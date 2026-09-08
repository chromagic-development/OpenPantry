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
    -- Which scanning station entered this row, for team scanning (two stations
    -- sharing one order). '' when recorded before stations were tracked, or by
    -- a non-scan channel — delivery / event / orderahead write their scans
    -- directly and have no station.
    station       TEXT    NOT NULL DEFAULT '',
    FOREIGN KEY (order_id) REFERENCES orders(id)
);

CREATE INDEX IF NOT EXISTS idx_scans_order   ON scans(order_id);
CREATE INDEX IF NOT EXISTS idx_scans_generic ON scans(generic_name);
CREATE INDEX IF NOT EXISTS idx_scans_when    ON scans(scanned_at);

-- Team scanning: a station that has joined *another* station's open order to
-- help check the same household out. One row per helper station per order;
-- deleting the row ends the assist. The order's owner stays orders.station —
-- only the owner can End or Cancel it. Rows are cleared when the order closes.
CREATE TABLE IF NOT EXISTS order_assists (
    order_id   INTEGER NOT NULL,
    station    TEXT    NOT NULL,   -- fs_station token of the helping device
    joined_at  TEXT    NOT NULL,
    PRIMARY KEY (order_id, station),
    FOREIGN KEY (order_id) REFERENCES orders(id)
);

-- Looked up by station on every scan (activeScanOrder), so index that side.
CREATE INDEX IF NOT EXISTS idx_order_assists_station ON order_assists(station);

-- Sticky assist mode. A station that joins an order stays in assist mode until
-- Leave Assist is pressed: when the order it is helping ends or is cancelled,
-- its order_assists row goes away but this one doesn't, and the station joins
-- the next order by itself (autoJoinNextAssist()). One row per station, present
-- only while the mode is on.
CREATE TABLE IF NOT EXISTS assist_mode (
    station  TEXT PRIMARY KEY,      -- fs_station token of the helping device
    since    TEXT NOT NULL          -- when the mode was switched on
);

-- Per-device preferences that outlive any one order or assist session, so a
-- station finds its own switches where it left them. A missing row means every
-- default, which is why nothing is written until a switch is actually moved.
--
-- scan_beep: whether this station sounds the scan tone on its own speaker as
-- it records items while assisting. Set from the sliding switch in the assist
-- controls, and read only by the station that owns it.
CREATE TABLE IF NOT EXISTS station_prefs (
    station    TEXT PRIMARY KEY,          -- fs_station token of the device
    scan_beep  INTEGER NOT NULL DEFAULT 1,
    updated_at TEXT NOT NULL
);

-- Days the pantry operated but nothing was scanned (volunteer absent, station
-- down, simply forgotten). These days are *unobserved*, not zero-demand: food
-- left the building with no record of it.
--
-- Without this list the Order Report reads a gap as a genuine zero, which
-- understates demand and therefore biases par levels — and the reorder alerts
-- that depend on them — low. Listing a day here removes it from the demand
-- model's exposure instead: see op_glm_fit_series() in
-- reports/order_report/forecast.php (the weekly Poisson offset becomes
-- log(observed days) rather than a flat log(7)) and op_report_rows() in
-- reports/order_report/report_lib.php (the trailing-average denominator).
--
-- Only list days the pantry actually operated. A real closure (holiday, snow
-- day) is a true zero and should NOT be listed — no food moved, and that is
-- honest information about throughput.
CREATE TABLE IF NOT EXISTS unscanned_days (
    day         TEXT PRIMARY KEY,           -- 'YYYY-MM-DD'
    note        TEXT NOT NULL DEFAULT '',   -- optional reason, for the operator
    created_at  TEXT NOT NULL
);

-- Packaged / canned items: UPC -> generic name.
-- Populated lazily: first time a UPC is seen, OFF API is queried for the
-- branded product name, then OpenAI maps that name to a generic. The mapping
-- is cached here so future scans skip both API calls.
CREATE TABLE IF NOT EXISTS upc_lookup (
    upc           TEXT PRIMARY KEY,
    brand_name    TEXT,           -- raw name from OFF (for review)
    -- 'Unidentified' is a reserved placeholder, written by the station while
    -- Settings -> Ignore Unknown Items is on and nothing can name the barcode.
    -- It is a real name in every other respect (it counts, it appears on the
    -- Inventory page), but lookupBarcode() refuses to answer with it once that
    -- switch is off: the station opens the Identify window instead, and the
    -- name typed there overwrites it for that UPC and renames that UPC's own
    -- scan rows. See UNIDENTIFIED_NAME in lookup.php.
    generic_name  TEXT NOT NULL,
    -- 'unidentified' is the placeholder above, and the one value a station is
    -- allowed to overwrite in place.
    source        TEXT NOT NULL,  -- 'off+ai' | 'manual' | 'off-only' | 'unidentified'
    created_at    TEXT NOT NULL,
    updated_at    TEXT,
    -- Product recall. Ticked from the Recalled column on the Lookup Tables
    -- page when a supplier or the FDA pulls an item. lookupBarcode() then
    -- refuses the code outright — ok=false with recalled=1 — so api_scan.php
    -- writes no scans row and the station sounds an alarm and tells the
    -- volunteer to pull the item back out of the cart. Enforced in the
    -- resolver rather than in the scan page so a station left open since
    -- before the box was ticked still can't record the item, and so both
    -- spellings of a store-printed label are refused by the one check.
    --
    -- Only barcode-resolved channels see this: delivery / event / orderahead
    -- pick items by generic name off the inventory menu and never touch a UPC,
    -- so a recall on one brand of an item can't speak for the rest of it.
    recalled      INTEGER NOT NULL DEFAULT 0
);

-- Legacy companion to upc_lookup: UPCs that Open Food Facts has no product
-- for. Written by an older build, in which Ignore Unknown Items waved an
-- unidentifiable UPC past and recorded nothing at all — so remembering the
-- miss was the only way to spare the next package of the same case another
-- six-second OFF request.
--
-- Nothing writes it any more. Those UPCs now get a real upc_lookup row under
-- the 'Unidentified' placeholder, which remembers the miss and records the
-- item, and which the always-first upc_lookup read answers from directly. The
-- table is still consulted so the rows already in it convert on their next
-- scan: a hit promotes the UPC to a placeholder row and deletes itself, so
-- this drains to empty as the pantry's unknown stock comes back across the
-- scanner.
CREATE TABLE IF NOT EXISTS upc_unidentified (
    upc         TEXT PRIMARY KEY,
    checked_at  TEXT NOT NULL   -- when OFF last answered "no such product"
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
    -- How many units (in `order_unit`) one supplier case holds. 0 = not set.
    -- Drives the Case Request column on the Order Report:
    -- cases = ceil(order request / count_per_case).
    count_per_case      REAL NOT NULL DEFAULT 0,
    -- The unit the wholesale vendor quotes this item's case in, when it differs
    -- from the unit the pantry stocks and scans it in. '' = same as `unit`.
    -- Loose produce is weighed on a scale (unit='lb') but some of it is sold by
    -- the piece — a 48-count case of avocados — so the order has to be placed in
    -- 'each' and the delivery booked back into inventory in 'lb'.
    order_unit          TEXT NOT NULL DEFAULT '',       -- '' | 'each' | 'lb'
    -- Average weight in pounds of one piece, which is what makes that round trip
    -- possible: each -> lb multiplies by it, lb -> each divides. 0 = not set,
    -- in which case order_unit is ignored and the item orders in `unit`.
    lb_per_each         REAL NOT NULL DEFAULT 0,
    -- The vendor's own wording for this item's pack, e.g. "89-100 ct case(s),
    -- least expensive variety". '' = not set. When set, the Order Report's
    -- Email/Print order lines say "4 <alt_case> - Apples" in place of the
    -- default "4 cases - Apples (40 lb)" — it replaces the word case(s) and
    -- the trailing pack-size note, which the alt text already spells out.
    alt_case            TEXT NOT NULL DEFAULT '',
    -- Cubic feet one supplier case occupies on the pantry floor. 0 = not
    -- set, which leaves the item out of the Order Report's capacity total
    -- rather than guessing a factor for it. The Order Report compares the sum
    -- against the max_storage_crates setting, so every item's figure has to be
    -- measured the same way.
    crates_per_case     REAL NOT NULL DEFAULT 0
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

-- Per-IP progressive throttle for the admin login (see ratelimit.php, which
-- also creates this on demand so the PantryPrep admin panel works before the
-- FoodScan schema has run). Row '@' is a sentinel holding the last time ANY
-- security-code email went out (global send pacing).
CREATE TABLE IF NOT EXISTS login_throttle (
    ip           TEXT PRIMARY KEY,             -- REMOTE_ADDR ('@' = global sentinel)
    fails        INTEGER NOT NULL DEFAULT 0,   -- consecutive failures (30-min decay)
    last_fail_at INTEGER NOT NULL DEFAULT 0,   -- unix seconds
    otp_hash     TEXT    NOT NULL DEFAULT '',  -- password_hash of the emailed 6-digit code
    otp_expires  INTEGER NOT NULL DEFAULT 0,   -- unix seconds
    otp_tries    INTEGER NOT NULL DEFAULT 0,   -- wrong entries against this code
    otp_sent_at  INTEGER NOT NULL DEFAULT 0    -- unix seconds (resend pacing)
);
