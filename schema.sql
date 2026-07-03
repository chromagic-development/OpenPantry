-- FoodScan: SQLite schema
-- Tracks outgoing food orders from a pantry. Items are reduced to "generic"
-- names (e.g. "Black Beans") regardless of brand, so demand can be aggregated.

CREATE TABLE IF NOT EXISTS orders (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    started_at  TEXT    NOT NULL,
    ended_at    TEXT,
    status      TEXT    NOT NULL DEFAULT 'open',  -- open | closed
    note        TEXT,
    -- Opaque per-device id (random token in the `fs_station` cookie) so two
    -- scanning stations can each have their own open order at the same time.
    -- '' for orders created before stations existed / from un-cookied clients.
    station     TEXT    NOT NULL DEFAULT ''
);

CREATE INDEX IF NOT EXISTS idx_orders_status ON orders(status);
-- The (status, station) index is created by migrateAddOrderStation() in db.php
-- rather than here: schema.sql runs before the migrations, so on an existing
-- install the `station` column doesn't exist yet at this point.
CREATE INDEX IF NOT EXISTS idx_orders_started ON orders(started_at);

CREATE TABLE IF NOT EXISTS scans (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id      INTEGER NOT NULL,
    barcode       TEXT    NOT NULL,
    generic_name  TEXT    NOT NULL,
    kind          TEXT    NOT NULL,                -- 'packaged' | 'produce'
    quantity      INTEGER NOT NULL DEFAULT 1,
    weight_lbs    REAL,
    scanned_at    TEXT    NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id)
);

CREATE INDEX IF NOT EXISTS idx_scans_order   ON scans(order_id);
CREATE INDEX IF NOT EXISTS idx_scans_generic ON scans(generic_name);
CREATE INDEX IF NOT EXISTS idx_scans_when    ON scans(scanned_at);

-- Packaged / canned items: UPC -> generic name.
-- Populated lazily: first time a UPC is seen, OFF API is queried for the
-- branded product name, then OpenAI maps that name to a generic. The mapping
-- is cached here so future scans skip both API calls.
CREATE TABLE IF NOT EXISTS upc_lookup (
    upc           TEXT PRIMARY KEY,
    brand_name    TEXT,           -- raw name from OFF (for review)
    generic_name  TEXT NOT NULL,
    source        TEXT NOT NULL,  -- 'off+ai' | 'manual' | 'off-only'
    created_at    TEXT NOT NULL,
    updated_at    TEXT
);

-- Produce: PLU code (or pantry-printed 12-digit label starting with 4) -> name.
-- Seeded with common produce; admin page can add more.
CREATE TABLE IF NOT EXISTS produce_lookup (
    code          TEXT PRIMARY KEY,
    generic_name  TEXT NOT NULL,
    unit          TEXT NOT NULL DEFAULT 'lb'
);

-- Manual inventory snapshot. One row per generic_name; updated on the
-- inventory page. Used as "Latest Inventory Count" in the report formula.
-- `deliverable` (default 1) controls visibility on the PantryPrep counter
-- order form (foodscan/pantryprep/index.php). Uncheck to keep an item in
-- the foodscan inventory + delivery menu but hide it from in-pantry orders.
CREATE TABLE IF NOT EXISTS inventory (
    generic_name        TEXT PRIMARY KEY,
    count               REAL NOT NULL DEFAULT 0,
    unit                TEXT NOT NULL DEFAULT 'each',   -- 'each' | 'lb'
    updated_at          TEXT NOT NULL,
    deliverable         INTEGER NOT NULL DEFAULT 1,    -- 1 = show on pantryprep order form
    -- Lifetime amounts (in `unit`) added to this item through the Restock
    -- page, split by source. Drives the Purchased % column on inventory.php
    -- (purchased / (purchased + donated)). The Restock page increments one
    -- of these per batch based on the "Purchased" checkbox.
    restocked_purchased REAL NOT NULL DEFAULT 0,
    restocked_donated   REAL NOT NULL DEFAULT 0,
    -- How many units (in `unit`) one supplier case holds. 0 = not set.
    -- Drives the Case Request column on the Order Report:
    -- cases = ceil(order request / count_per_case).
    count_per_case      REAL NOT NULL DEFAULT 0
);

-- Key/value settings: OpenAI key, default lead time, safety-stock Z, etc.
CREATE TABLE IF NOT EXISTS settings (
    key    TEXT PRIMARY KEY,
    value  TEXT
);

-- Per-item lead-time alerts. When the report is opened and an item's
-- projected days-of-stock falls below its lead_time_days, a banner shows.
CREATE TABLE IF NOT EXISTS alerts (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    generic_name    TEXT NOT NULL,
    lead_time_days  INTEGER NOT NULL,
    enabled         INTEGER NOT NULL DEFAULT 1,
    -- 1 = include this alert in the reorder-reminder email sent by the cron
    -- script (cron_reorder_alerts.php). Toggled from the Order Report's
    -- Reorder Alerts table. 0 = on-page banner only, no email.
    email_enabled   INTEGER NOT NULL DEFAULT 0,
    -- Last time this alert was emailed; used by the cron script to avoid
    -- re-sending the same reminder more than once per OP_ALERT_EMAIL_MIN_HOURS.
    last_triggered  TEXT
);

CREATE INDEX IF NOT EXISTS idx_alerts_name ON alerts(generic_name);

-- Memoized demand-model fits for the Order Report. The expensive per-item GLM
-- (reports/order_report/forecast.php) is keyed by item + anchor date + a hash
-- of the item's training series, so it's refit only when the underlying scans
-- change (or the day rolls over). `payload` is the JSON-encoded fit (β coeffs,
-- dispersion, harmonics); the cheap forecast projection is recomputed live, so
-- changing Lead Time / Z still reuses the cached fit. Stale rows are pruned by
-- created_at, so this table is safe to delete at any time — it just rebuilds.
CREATE TABLE IF NOT EXISTS forecast_cache (
    cache_key   TEXT PRIMARY KEY,
    payload     TEXT NOT NULL,
    created_at  TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_forecast_cache_created ON forecast_cache(created_at);

-- Saved delivery clients. The delivery menu (deliveryprep/) cycles through the
-- enabled clients that have not yet had a packing list printed, pre-filling the
-- "New Delivery" fields. `delivered_at` is stamped when a packing list is
-- printed for that client; clearing it (via the client manager) puts the client
-- back into rotation. No counts/items live here — only the reusable contact +
-- household profile. ("group" is a SQL keyword, so the column is `grp`.)
CREATE TABLE IF NOT EXISTS delivery_clients (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    name          TEXT    NOT NULL,
    adults        INTEGER NOT NULL DEFAULT 1,
    children      INTEGER NOT NULL DEFAULT 0,
    grp           TEXT    NOT NULL DEFAULT 'E-1',
    address       TEXT    NOT NULL DEFAULT '',
    city          TEXT    NOT NULL DEFAULT '',
    phone         TEXT    NOT NULL DEFAULT '',
    volunteer     TEXT    NOT NULL DEFAULT '',   -- volunteer assigned to call this client
    enabled       INTEGER NOT NULL DEFAULT 1,   -- 0 = skip in delivery rotation
    delivered_at  TEXT,                          -- set when a list is printed; NULL = pending
    sort_order    INTEGER NOT NULL DEFAULT 0,
    created_at    TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_delivery_clients_rotation
    ON delivery_clients(enabled, delivered_at, sort_order);
