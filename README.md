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
- **Team scanning.** Two operators can check the same household out together.
  An idle station shows an **Another station is scanning** card listing the
  open orders elsewhere in the pantry; tapping **+ Assist** joins one, and from
  then on that station's scans land in the shared order. Ownership doesn't
  move: only the station that started the order can **End** or **Cancel** it,
  and the helper gets a **Leave Assist** button instead. Both screens stay in
  step through a 2.5-second poll, so a scan on one appears on the other without
  a refresh, and either operator can pull a mis-scan back out — including one
  their teammate entered. Once a second station joins, a **Who** column appears
  in the item list badging each row with the station that scanned it (filled
  badge = this station, outline = the teammate's); it stays hidden on a
  single-station pantry, as does the whole Assist card. Ending or cancelling
  the order releases the helpers back to idle. A common use is a volunteer's
  phone in camera mode assisting the wired laser station on a busy day.
- **Closing a stranded order.** A station that disappears mid-order — laptop
  shut, tablet carried off — leaves its order open with nobody able to close
  it, since only the owning station may End or Cancel. Every other station goes
  on offering to assist it, and a station in assist mode keeps being pulled
  back onto it instead of the live order. With an **administrator or supervisor
  signed in on that device**, each row of the Assist card also carries **End**
  and **Cancel** buttons that close someone else's order from where you are
  standing — End deducts its items from inventory exactly as the owning station
  would have, Cancel discards the order and its scans. Both confirm first, and
  both are invisible (and refused server-side) without that login.
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
- **Impact** — the funder/board view, and the only report that reads *both*
  databases. Pounds distributed and meals provided, households and people
  reached, service channels, top items, donated-vs-purchased sourcing, and
  (from `picklist.db`) counter requests with their fill rate. Weighed produce
  is real; packaged goods are counted, so any pound or meal figure uses the
  average-item-weight and pounds-per-meal assumptions set at the top of the
  page and restated in the report's Methodology card. No client PII appears.

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
- **Supervisor login** (optional, Settings → 🔑 Supervisor Password). A second
  shared password, hashed the same way, that opens every page and report the
  admin password does — with one exception: the **Settings** page is read-only
  for a supervisor apart from the **Public IPv4 Address** controls (*Use My
  Current IP* / *Set IP Address*). It's for whoever opens the pantry: they can
  re-point the network gate at today's WiFi address without holding the keys to
  the OpenAI key, the mail settings, or the passwords. Setting or removing it
  takes the current administrator password, and doing so signs out anyone using
  the old one (the cookie token derives from the stored hash). Unset = the role
  doesn't exist; there is no default supervisor password.
- **Login rate limiting** (`ratelimit.php`). Failed logins are throttled per
  IP with progressive delays (10s after the 3rd failure, 30s after the 4th).
  The 5th failure **soft-locks** the IP: a single-use 6-digit code is emailed
  to the administrator (Settings → Administrator Email) and must be entered
  alongside the password — no waiting once the code is in hand. If no admin
  email is configured (or mail fails), an escalating 1 → 10 minute timeout
  takes over instead. A successful login clears the record; 30 quiet minutes
  do too. Codes expire in 15 minutes, die after 5 wrong tries, and re-sends
  are paced so failed logins can't flood the administrator's inbox.
- **Network gate.** Pages can be restricted to a single allowed IP (your
  pantry's public WiFi address) and to configurable weekly **allowed hours**.
  Both live in `auth.php`; leave the IP blank to allow all.
- **Field-level encryption.** PII security and privacy is paramount.
  Sensitive columns are encrypted at rest with libsodium (`crypto.php`):
  **every `settings` value** (the hashed `admin_password` /
  `supervisor_password` and a migration flag excepted) and delivery clients' `address` / `city` / `phone`. The
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
├── common.php         library: header/nav/styles + station cookie + team scanning
├── lookup.php         library: barcode → generic name (OFF + OpenAI)
├── mailer.php         library: dependency-free SMTP / mail() sender
├── api_order.php      JSON: start/end/cancel orders + assist join/leave/sync
│                       + admin remote end/cancel of another station's order
├── api_scan.php       JSON: lookup / record / delete a scan
├── api_alert.php      JSON: reorder-alert CRUD + email toggle
├── api_openai_test.php       JSON: smoke-test the OpenAI key
├── api_send_test_email.php   JSON: send a test reorder reminder
├── cron_reorder_alerts.php   cron: email triggered reorder reminders
├── scan/              page: scanning station (laser scanner + phone camera)
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
│   ├── basket_report/           page: basket-size distribution
│   └── impact_report/           page: impact summary (both databases)
├── lookup_admin/      page: manage produce + UPC mappings
├── deduplicate/       page: merge duplicate generic names (scans, lookups, inventory, alerts)
├── settings/          page: OpenAI key, par defaults, network access, admin email/password, SMTP
├── logout/            page: clears auth cookie
└── menucounter/       nested app: customer order form, pick queue, item admin
    ├── index.php         customer-facing order form
    ├── submit_order.php  POST handler for the order form
    ├── api.php           JSON API for pick queue + item admin
    ├── db.php            PDO + first-run schema/seed for picklist.db
    ├── admin/            page: configure order-form items (password protected)
    ├── orders/           page: live employee pick-queue dashboard
    ├── reports/          pages: item-usage report + chart, daily volume
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
   scanner types digits + Enter and the page does the rest. On a device with no
   wired scanner, tap **Start Camera** on the same page to scan with the
   device's camera instead (needs camera permission and HTTPS or localhost).
   To put a second person on the same household, open the same page on their
   device and tap **+ Assist** on the order already running.
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
suffix. The recommended hardware setup is a Chromebook, a USB laser scanner, and
a USB scale — either a HID Point-of-Sale scale (see below) or a VEVOR Industrial
Scale with an RS-232-to-USB-HID cable that types produce weight in pounds (e.g.
1.120lb). Alternatively, all PLU/UPC codes and weights can be entered manually
and there is a camera option instead of a laser scanner.

### Two ways to connect a scale

The scan station supports two kinds of scale, and picks up whichever is present
with nothing to configure.

**USB scale (recommended).** Any scale that implements the USB HID Point-of-Sale
scale usage page (`0x8D`) — the standard USPS/ShipStation postage software
speaks, e.g. the DYMO M25 — plugs straight into the Chromebook with no adapter,
no serial cable, and no drivers. A scale strip above the barcode box shows the
connection state; the first time, click **Connect Scale** and pick the device.
That grant is remembered, so every later page load reconnects silently.

This path is not a keyboard: the page reads HID reports directly, so the weight
never depends on which field has focus and can't collide with the laser
scanner's keystrokes. The report carries its unit — g, kg, oz or lb all convert
to pounds automatically. Capture waits for the scale's own *stable* flag **and**
for the reading to hold near where it started; scales in this class raise that
flag while the value is still creeping, so a single stable report is not
trusted. Two hold windows keep that from feeling sluggish (both in
`scan/scan.php`): `HID_SETTLE_FAST_MS` (~350 ms) when consecutive reports read
*identically* — a parked value, which is what a genuinely settled item looks
like — and `HID_SETTLE_MS` (~700 ms) when it is still jittering inside the
tolerance. A creeping weight changes on every report by definition, so it can
never take the fast path. Needs a
Chromium browser (Chrome, Edge, ChromeOS); stations without WebHID simply don't
see the strip.

Because the report layout is a published standard rather than a vendor format,
any HID-compliant scale works — you are not tied to one model.

**Keyboard-wedge scale (the original path).** The VEVOR-style setup, where an
RS-232-to-USB-HID cable types decimal pounds with an `lb` suffix as if someone
had typed them. Still fully supported and unchanged.

### Either order, either scale

A weighed produce entry is two halves, a PLU and a weight, and **the scan
station accepts them in either order with nothing to configure**. On the wedge
path it tells them apart by what arrives: the scale types decimal pounds with an
`lb` suffix, a PLU is bare digits, and nothing else on the page looks like
either.

- **Scan the PLU first** — the “Weight required” window opens and the scale (or
  the keypad) fills it in.
- **Weigh first** — set the item on the scale and leave it alone. When it
  settles the scale transmits on its own; the station holds the reading, beeps,
  and asks for the PLU. Scan the code and both halves are recorded together.

Either way, **clear the platform afterwards**: the station will not capture a
second item until the scale returns to zero. On a wedge scale this is also a
hardware rule — it only arms its next transmission once cleared — and weighing
first needs it in “PC” mode (press `.` then `9`) rather than “print” mode.

**One weight at a time.** A captured weight is held until its PLU is entered or
it is discarded, and while it is held the station refuses to weigh anything
else — the strip says **“No PLU entered.”** and there is no beep. The refused
reading is *discarded*, not queued: an item put on by mistake while the window
is open is ignored outright rather than springing a second PLU prompt the moment
the first is finished. To weigh it for real, take it off and put it back on.
Without that rule, removing the item and setting down another rearms the scale,
and each new weighing silently replaces the one being identified.

Volunteers can switch between the two orders mid-order without telling the app,
and the manual keypad fallback always works, so an unplugged scale doesn't stop
the station.

Guardrails differ slightly by path. On the wedge path a weight sent twice for
one item is recognized as an echo and ignored, and a scale left in kilograms is
refused outright rather than recorded as pounds. On the USB path neither can
happen: the return-to-zero rule already prevents a double capture, and units are
converted rather than refused. The USB path adds messages the wedge can't
produce — over-capacity, scale fault, needs re-zeroing, and disconnection.

**“Make sure scale is on.”** Postal scales power themselves down to save their
batteries, and a switched-off scale looks exactly like one nobody has touched —
both simply stop changing. The station beeps and shows that message in two
situations, and any real change to the weight clears it:

- **No usable reading within ~12 seconds of connecting** (`SCALE_STARTUP_MS`).
  This is the page-load case: the scale was never switched on. Answered in
  seconds rather than making the operator wait out the idle timeout below.
- **The weight has not changed for three minutes** (`SCALE_IDLE_MS`) — the scale
  was on and has since gone to sleep.

Until a first reading arrives the strip reads **“Scale connected — waiting for a
reading…”** rather than claiming ready, since a switched-off scale would
otherwise look fine.

### Printing labels for items with no barcode

Butter tubs, halal meat, diapers and anything else that arrives unlabelled needs
a barcode of its own. `docs/make_barcode_sheets.py` prints two sheets: in-store
UPC-A labels for those items, and Code128 PLU labels for loose produce.

```bash
python docs/make_barcode_sheets.py --db /path/to/openpantry.db
```

In-store labels use the GS1 prefix-2 range, `2 IIIII VVVVV C`, where `IIIII` is
the item and `VVVVV` is a variable measure a retail scale rewrites for every
package. **The item number has to live in `IIIII`.** Lookups key these on the
leading six digits precisely because the trailing five are not stable, so a
sheet that instead counts up in `VVVVV` files every item it lists under one key
— and naming any one of them then names all of them. The generator pins `VVVVV`
to `00000`; nothing on these sheets is weighed.

Item numbers are read back from `upc_lookup` rather than reassigned, so a
reprint cannot repoint a label that is already on a shelf. `--add FILE` appends
new names at the next free number and prints the mappings to enter under Lookup
Tables; `--items FILE` works with no database at all, for a new install. Keys
outside the pantry's reserved block (`--block`, default `1-999`) are the
retailer's own deli and bakery labels and are listed as skipped rather than
reprinted.

## Open Food Facts notes

`lookup.php` calls `https://world.openfoodfacts.org/api/v2/product/{upc}.json`
with a 6-second timeout. The full OFF export is ~50 GB, so shipping it isn't
practical; the live API gives identical coverage on cache miss, and after the
first scan the mapping is local-only.

## Implementation of AI and machine learning

- The scan station has a "Create Recipe" feature where generative AI
  can print a recipe for a client based on their order's ingredients as well
  as a "How do I prepare this item?" option for each item in the client order.
- The app classifies and maps brand labelled products to their generic named 
  equivalents using a LLM to simplify tracking for which multiple brand names
  are irrelevant noise. When corrections are made to any mappings, it further
  "learns" what you expect by improving the multi-shot prompt. If OpenAI picks
  a generic name you don't like (e.g. Beans when you wanted Black Beans), open
  Lookup Tables → UPC → Generic Cache and edit it in place. Future scans use
  your edit.
- It also integrates paper checklists provided by delivery volunteers and
  homebound clients who do not have Internet access by scanning them with a
  multimodal LLM/CNN. This is a cheap and less fragile solution than traditional
  OMR.
- Furthermore, the prediction algorithm for restocking to optimize food
  availability could be considered a rudimentary form of ML by implementing a
  Poisson GLM where the linear model achieves better predictions as it refits
  with more data.

 Explore AI for Humanity at https://lnkd.in/gGWa93UM to learn more about how 
 AI initiatives can make positive contributions to non-profits.

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
