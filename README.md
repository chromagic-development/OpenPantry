# OpenPantry

A self-contained **PHP + SQLite** application for assisting a food pantry:
check items out the door, keep a live inventory, and forecast what to
reorder with granular reporting for critical insights — no database server,
no build step, no external dependencies to install. Drop the folder on any
PHP 8 host and it initializes itself on the first request.

Food banks and pantries serve approximately 25% to 30% of food-insecure
households in the United States. This application aids in the underserved
space of IT tools for these non-profit organizations. Well designed,
open source solutions can produce key metric insights to encourage donations
as well as providing efficiency through automation that frees administrators
and volunteers to engage more with the public on a personal and empathetic
level. The OpenPantry app is a hybrid Just-In-Time (JIT) inventory management
system that achieves those goals for affiliated smaller food pantries by
optimizing the use and availability of their limited storage capacity and
high demand, essential foods. It offers a suite of capabilities ranging from
grocery store-style checkout with barcode scanning, event orders, deliveries
with picklists, menu counter kiosk based portioning per household size, and
robust reporting with significant BI granularity for informed planning and
governance for the board and impact reporting for donors. A smattering of AI
even maps the many brand names to generic name labeling for management of
goods. PII security and privacy is paramount. There is even the potential to
track that nutritional needs are being met for orders because the scanning
API has access to that information.

Built around handheld laser scanners on Android tablets (with a phone-camera
fallback), OpenPantry also bundles a nested customer-ordering app,
**Menu Counter** for high demand/high cost items, plus channels for
deliveries, events, and order-ahead imports. Every outbound channel funnels
into one inventory and one demand model, so the reorder report sees the
whole picture.

---

## What it does

### 1. Checkout & inventory
- **Scan out orders.** An operator taps **Start Order**, scans each item going
  out the door, then taps **End Order**. Each order gets an auto-incrementing
  number and start/end timestamps; closing it deducts the scanned quantities
  from inventory. Multiple scanning **stations** can each hold their own open
  order at once (tracked by a per-device cookie).
- **Generic-name normalization.** Scanned UPCs are stored only as their
  *generic* food name (e.g. `Black Beans`), never the brand, so demand
  aggregates cleanly. The first time a UPC is seen, OpenPantry queries
  [Open Food Facts](https://world.openfoodfacts.org/) for the branded name,
  asks OpenAI to reduce it to a 2–4 word generic, and caches the mapping in
  `upc_lookup` so every later scan is instant and offline.
- **Produce.** 4–5 digit PLU codes (and 12-digit pantry labels beginning with
  `4`) are recognized as produce and prompt for a weight. The produce table
  ships pre-seeded with the most common items.

### 2. Other outbound channels
All of these draw from the same inventory pool:

- **Deliveries** — a kiosk order form that cycles through saved delivery
  clients, logs a closed order per delivery, and prints packing / call sheets
  in bulk. Client contact + household details are stored encrypted.
- **Events** — logs internal consumption (Breakfast Cafe, Community Supper,
  etc.) as an order tagged `EVENT · <type> · <initials>`.
- **OrderAhead** — imports a Distribution Report CSV and decrements inventory
  by matching each row's item name against your generic names.
- **Restock** — staff stage counts per item and submit a batch that *adds* to
  inventory (split by purchased vs. donated for the Purchased-% column). This
  is a pure inventory mutation — no order/scan rows, so it stays out of the
  usage reports.
- **Menu Counter** — the nested customer-facing ordering app (see below).

### 3. Reports
- **Order Now** — the reorder report. For each item it computes a Par Level
  and how much to order (details below).
- **Orders Listing** — every order and its items over a date range, with pills
  marking delivery/event orders.
- **Item Usage** — per-item totals over a date range.
- **Daily Volume** — orders and scans per day.
- **Basket Size** — distribution of items-per-order for in-pantry trips
  (evidence on whether unrationed access leads to larger baskets over time).

### 4. The demand model (Order Now report)

For each generic item:

```
Par Level     = Forecast(LeadTime) + SafetyStock
SafetyStock   = Z × √Variance(LeadTime)
TotalOrderReq = max(0, ParLevel − LatestInventoryCount)
```

- **Forecast / Variance** come from a quasi-Poisson GLM (log link) fitted to
  the item's full weekly scan history, with a linear time **trend** and
  **Fourier seasonality** on the annual cycle. The dispersion φ inflates the
  predictive variance, so safety stock reflects each item's real volatility.
- **S** (seasonality) and **G** (growth) are read back out of the same fit:
  S = forecast ÷ deseasonalized baseline; G = exp(trend), the annual growth
  multiplier.
- **Z** = confidence multiplier (default 1.65 ≈ 95%).
- Each fit is memoized in `forecast_cache` (keyed by item + date + a hash of
  its scan history), so refits happen only when the data changes or the day
  rolls over. The table is *just* a cache — safe to delete; it rebuilds.
- Items with too little history (< ~2 months) fall back to a trailing-average
  method and are flagged with a `°`.

### 5. Reorder alerts & email
Per-item lead-time alerts show as banners on the Order Now report when an
item's projected days-of-stock drops below its threshold. Tick an alert's
**Email** box and a scheduled job (`cron_reorder_alerts.php`) mails a digest of
triggered items to the administrator, throttled to at most once per ~20 hours.
Email goes out via authenticated SMTP when configured, otherwise PHP `mail()`.
The SMTP client in `mailer.php` is self-contained — no libraries.
The "Email Order" features uses Gmail or you can alternatively "Print Order".

---

## Security model

- **Admin login** is shared across the whole app (including Menu Counter) via a
  single cookie. The password is stored **one-way hashed** (`password_hash`) —
  even the running app can't recover it. Default password is `admin`; change it
  under **Settings** immediately.
- **Network gate.** Pages can be restricted to a single allowed IP (your
  pantry's public WiFi address) and to configurable weekly **allowed hours**.
  Both live in `auth.php`; leave the IP blank to allow all.
- **Field-level encryption.** PII security and privacy is paramount.
  Sensitive columns are encrypted at rest with libsodium (`crypto.php`):
  **every `settings` value** (the hashed `admin_password` and a migration
  flag excepted) and delivery clients' `address` / `city` / `phone`. The
  32-byte key lives in `encryption_key.php`, generated on first use.

  > ⚠️ **Back up `encryption_key.php` and keep it out of version control.**
  > Losing it makes all encrypted data permanently unrecoverable. It's
  > recommended to relocate the key above the web root — point the app at it
  > with `OPENPANTRY_KEY_PATH` (env var) or a `FS_ENC_KEY_PATH` constant. On a
  > host without libsodium (PHP < 7.2), encryption degrades to a no-op and
  > values are stored in clear text until a sodium-capable PHP runs.

---

## Layout

User-facing pages live in their own subfolders so URLs stay clean
(`/openpantry/scan/`, `/openpantry/inventory/`, …). Each has a `.htaccess`
with `DirectoryIndex <name>.php`. Libraries and JSON endpoints stay flat at the
root.

```
openpantry/
├── index.php          ← dashboard (served at /openpantry/)
├── schema.sql         library: SQLite schema
├── paths.php          library: database locations (OPENPANTRY_DB_DIR)
├── db.php             library: PDO + first-run seed + idempotent migrations
├── auth.php           library: shared login + IP/time access gate
├── crypto.php         library: field-level encryption + password hashing
├── common.php         library: header/nav/styles + station cookie
├── lookup.php         library: barcode → generic name (OFF + OpenAI)
├── mailer.php         library: dependency-free SMTP / mail() sender
├── api_order.php      JSON: start/end/cancel orders
├── api_scan.php       JSON: lookup / record / delete a scan
├── api_alert.php      JSON: reorder-alert CRUD + email toggle
├── api_openai_test.php       JSON: smoke-test the OpenAI key
├── api_send_test_email.php   JSON: send a test reorder reminder
├── cron_reorder_alerts.php   cron: email triggered reorder reminders
├── scan/              page: laser scanner
├── scan_camera/       page: html5-qrcode camera scanner
├── inventory/         page: manual current-count entry
├── restock/           page: batch add-to-inventory
├── delivery/          app:  delivery kiosk + client manager + printing
├── event/             page: internal event consumption
├── orderahead/        page: OrderAhead CSV import
├── reports/
│   ├── order_report/            page: par-level "Order Now" report
│   ├── orders_listing_report/   page: orders & items by date range
│   ├── usage_report/            page: per-item totals by date range
│   ├── volume_report/           page: orders & scans per day
│   └── basket_report/           page: basket-size distribution
├── lookup_admin/      page: manage produce + UPC mappings
├── settings/          page: OpenAI key, par defaults, network access, admin email/password, SMTP
├── logout/            page: clears auth cookie
└── menucounter/       nested app: customer order form, pick queue, item admin
    ├── index.php         customer-facing order form
    ├── submit_order.php  POST handler for the order form
    ├── api.php           JSON API for pick queue + item admin
    ├── db.php            PDO + first-run schema/seed for picklist.db
    ├── admin/            page: configure order-form items (password protected)
    ├── orders/           page: live employee pick-queue dashboard
    ├── report/           page: item-usage reports + chart
    └── deduplicate/      page: merge duplicate item rows
```

Two SQLite files are created automatically: `openpantry.db` at the root and
`menucounter/picklist.db` for the ordering app. On a real deployment, move
them out of the web root — see **Keeping the data files out of the web root**
below.

---

## Setup

1. **Requirements:** any web host with **PHP 8+** and the `pdo_sqlite`
   extension (standard on every default install). For encryption, the `sodium`
   extension — built in on PHP 7.2+.
2. **Deploy.** Copy the whole folder to your host. The nested `menucounter/`
   app ships in the same folder — no separate deploy.
3. **Make it writable.** Ensure the app folder (and `menucounter/`) is writable
   by the web server so `openpantry.db`, `menucounter/picklist.db`, and
   `encryption_key.php` can be created on first hit.
4. **Browse to the app root.** The schema initializes itself, the produce table
   seeds with common PLU codes, and the databases are created on first load.
5. **Open Settings.** Paste your OpenAI API key. Set the **Network Access** IP
   (your pantry's public WiFi address) and **change the default admin password
   (`admin`)**. Optionally set the administrator email and SMTP details.
6. **Scan.** Open `/openpantry/scan/` on a tablet wired to the handheld
   scanner. Tap anywhere on the page to keep focus in the barcode field — the
   scanner types digits + Enter and the page does the rest. For phones without
   a wired scanner, use `/openpantry/scan_camera/` (needs camera permission and
   HTTPS or localhost).
7. **Customer ordering** lives at `/openpantry/menucounter/`; the employee pick
   queue is at `/openpantry/menucounter/orders/`.
8. **(Optional) Reorder-reminder cron.** Add a cron job that runs the mailer on
   your cadence, e.g. daily at 7am:

   ```
   0 7 * * *  /usr/local/bin/php /home/you/public_html/openpantry/cron_reorder_alerts.php
   ```

   > Call the PHP binary by its **absolute path** (`/usr/local/bin/php` on
   > Namecheap-style cPanel hosts) — a bare `.php` path can silently fail with
   > "Permission denied." Use **Send Test Email** in Settings to confirm
   > delivery first.

---

## Hardware notes — laser scanners and scales

Most USB / Bluetooth handheld scanners (Honeywell, Symbol/Zebra, Inateck,
NetumScan, etc.) ship as HID keyboard-wedge devices: they type the barcode
digits, then send a CR/Enter terminator. The scan page assumes that default —
no driver or pairing beyond the OS keyboard pairing. If your scanner doesn't
send Enter, reconfigure it via its programming sheet to add a CR (or CR+LF)
suffix. The recommended hardware setup consists of a Chromebook with a USB
laser scanner and VEVOR Industrial Scale that includes a RS-232 to USB HID
interface to automatically enter produce weight in pounds (e.g. 1.120lb).
Alternatively, all PLU/UPC codes and weights can be entered manually and there
is a camera option available instead of requiring a laser scanner.

## Open Food Facts notes

`lookup.php` calls `https://world.openfoodfacts.org/api/v2/product/{upc}.json`
with a 6-second timeout. The full OFF export is ~50 GB, so shipping it isn't
practical; the live API gives identical coverage on cache miss, and after the
first scan the mapping is local-only.

## Editing AI-derived generics

If OpenAI picks a generic name you don't like (e.g. `Beans` when you wanted
`Black Beans`), open **Lookup Tables → UPC → Generic Cache** and edit it
in place. Future scans use your edit.

## Keeping the data files out of the web root

Both SQLite databases and the field-encryption key default to living inside
the app folder so a first run needs zero configuration — but anything under
`public_html` can be **downloaded by anyone who guesses the URL** (client
names and order history included). On a real deployment, move all three
above the web root:

1. Create two folders **next to** `public_html` (not inside it), e.g.
   `openpantry_secret/` for the key and `openpantry_data/` for the databases.
   (The app creates `openpantry_data/` itself if it can.)
2. Add two `SetEnv` lines to `openpantry/.htaccess` (absolute paths, no
   spaces):

   ```apache
   SetEnv OPENPANTRY_KEY_PATH /home/you/domains/example.org/openpantry_secret/encryption_key.php
   SetEnv OPENPANTRY_DB_DIR /home/you/domains/example.org/openpantry_data
   ```

3. Load any page. The key file is copied to the new location and both
   databases are moved there automatically (their WAL journals are
   checkpointed into the main files first, so nothing is lost). After
   confirming the app still works, delete any old `encryption_key.php` left
   inside the web root.
4. Defense in depth — also refuse to serve database files over HTTP, in case
   one ever lands in the web tree again. In the same `openpantry/.htaccess`:

   ```apache
   <FilesMatch "\.(db|db-wal|db-shm|sqlite)$">
     Require all denied
   </FilesMatch>
   ```

5. Verify: `https://your-site/openpantry/openpantry.db` and
   `.../openpantry/menucounter/picklist.db` should now return 403/404 instead
   of downloading, and the app pages should all still work.

The cron mailer reads the same `SetEnv` lines straight out of `.htaccess`
when run from the command line, so the cron job needs no changes.

## Files for scheduled backups

- `openpantry.db` and `picklist.db`, both in `openpantry_data/` once
  `OPENPANTRY_DB_DIR` is set (see above) — or, without it, in `openpantry/`
  and `openpantry/menucounter/` respectively. **Re-point existing backup jobs
  after moving the databases.**
- `openpantry_secret/encryption_key.php` — needs to be backed up only once,
  after the app creates it.
