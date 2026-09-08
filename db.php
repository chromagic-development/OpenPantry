<?php
// SQLite connection + first-run schema/seed. Idempotent.

require_once __DIR__ . '/paths.php';  // database locations (OPENPANTRY_DB_DIR)
require_once __DIR__ . '/crypto.php'; // field-level encryption helpers

function getDB(): PDO {
    static $db = null;
    if ($db !== null) return $db;

    $path = fsDbPath('openpantry.db');
    $isNew = !file_exists($path);

    $db = new PDO('sqlite:' . $path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    // With multiple scanning stations writing concurrently, two single-row
    // inserts can momentarily contend for SQLite's write lock. Wait up to 5s
    // for the lock instead of failing immediately with SQLITE_BUSY. This has to
    // be set before any pragma that can itself need a lock — until it is, SQLite
    // returns SQLITE_BUSY instantly with no retry at all.
    $db->exec('PRAGMA busy_timeout = 5000');
    $db->exec('PRAGMA foreign_keys = ON');
    // Only *assign* journal_mode when it is actually wrong. Reading it takes no
    // lock, but converting a file to WAL needs an exclusive one — unobtainable
    // while any other connection is open. Once set, WAL is persistent in the
    // database header, so on a healthy install this branch never runs.
    if (strtolower((string)$db->query('PRAGMA journal_mode')->fetchColumn()) !== 'wal') {
        $db->exec('PRAGMA journal_mode = WAL');
    }

    $schema = file_get_contents(__DIR__ . '/schema.sql');
    $db->exec($schema);

    if ($isNew) {
        seedProduce($db);
        seedSettings($db);
    }
    // One-time copy of admin_password / allowed_ip from the old picklist.db
    // home. Idempotent — short-circuits once both keys exist here.
    migrateAdminAccessSettings($db);
    // Idempotent schema migrations for existing installs. Each block adds a
    // column only if it's missing, so this is safe to run on every getDB().
    migrateAddInventoryDeliverable($db);
    migrateAddDeliveryClientVolunteer($db);
    migrateAddInventoryRestockSource($db);
    migrateAddInventoryCountPerCase($db);
    migrateAddInventoryOrderUnit($db);
    migrateAddInventoryAltCase($db);
    migrateAddInventoryCratesPerCase($db);
    migrateAddOrderStation($db);
    migrateAddScanStation($db);
    migrateAddAlertEmailEnabled($db);
    migrateAddUpcRecalled($db);
    // Convert a stored plaintext (or previously-encrypted) admin_password into
    // a one-way hash. Runs before the field-encryption migration so the latter
    // never re-encrypts the password.
    migrateHashAdminPassword($db);
    // Encrypt existing plaintext in the delivery_clients PII fields. Runs once
    // (guarded by a settings flag); a no-op until a sodium-capable PHP is
    // running.
    migrateEncryptSensitiveFields($db);
    // Encrypt every remaining plaintext settings value (all keys except the
    // FS_UNENCRYPTED_SETTINGS exemptions). Flagless and self-healing — see the
    // function comment. Runs after the password hashing above so a legacy
    // plaintext admin_password becomes a hash, never ciphertext.
    migrateEncryptSettings($db);
    return $db;
}

// Hash the stored admin_password if it isn't already a password_hash. Handles
// legacy plaintext (works on any PHP) and a value that the earlier feature may
// have encrypted (decrypted first, which needs libsodium). Idempotent — once
// the value is a hash, password_get_info() detects it and we return.
function migrateHashAdminPassword(PDO $db): void {
    $v = $db->query("SELECT value FROM settings WHERE key='admin_password'")->fetchColumn();
    if ($v === false || $v === '') return;
    $v = (string)$v;
    if (!empty(password_get_info($v)['algo'])) return; // already hashed

    if (fsIsEncrypted($v)) {
        if (!fsCryptoAvailable()) return;     // can't decrypt yet — retry after PHP upgrade
        $plain = fsDecrypt($v);
        if ($plain === '') return;            // decryption failed — don't lock the admin out
    } else {
        $plain = $v;                          // legacy plaintext / 'admin' default
    }
    $db->prepare("UPDATE settings SET value=? WHERE key='admin_password'")
       ->execute([fpHashAdminPassword($plain)]);
}

// One-time encryption of any still-plaintext delivery_clients address / city /
// phone values. Idempotent — already-encrypted (sb1:-marked) and empty values
// are skipped, and the `enc_fields_v1` flag short-circuits subsequent loads.
// Deferred while sodium is unavailable so it runs after a PHP upgrade rather
// than marking itself done without actually encrypting anything. (The settings
// table is handled by the flagless migrateEncryptSettings() sweep instead.)
function migrateEncryptSensitiveFields(PDO $db): void {
    if (!fsCryptoAvailable()) return;
    // Don't mark the migration done unless a real key is in hand (e.g. the dir
    // must be writable to create encryption_key.php). Otherwise we'd flag it
    // complete while fsEncrypt() silently no-ops, leaving data in clear text.
    if (strlen(fsEncKey()) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) return;
    $done = $db->query("SELECT value FROM settings WHERE key='enc_fields_v1'")->fetchColumn();
    if ($done === '1') return;

    $rows = $db->query('SELECT id, address, city, phone FROM delivery_clients')
               ->fetchAll(PDO::FETCH_ASSOC);
    $cu = $db->prepare('UPDATE delivery_clients SET address=?, city=?, phone=? WHERE id=?');
    foreach ($rows as $r) {
        $a = fsMaybeEncrypt((string)$r['address']);
        $c = fsMaybeEncrypt((string)$r['city']);
        $p = fsMaybeEncrypt((string)$r['phone']);
        if ($a !== $r['address'] || $c !== $r['city'] || $p !== $r['phone']) {
            $cu->execute([$a, $c, $p, $r['id']]);
        }
    }

    $db->prepare("INSERT INTO settings (key, value) VALUES ('enc_fields_v1', '1')
                  ON CONFLICT(key) DO UPDATE SET value='1'")->execute();
}

// Encrypt-at-rest sweep for the settings table: every row except the
// FS_UNENCRYPTED_SETTINGS exemptions is stored as sb1: ciphertext. Runs on
// every getDB() — the table is a couple dozen tiny rows and at steady state
// this writes nothing — so plaintext defaults inserted later by INSERT OR
// IGNORE migrations self-heal on the next load instead of needing a new
// one-time flag each time a setting is added. Skipped until a sodium-capable
// PHP and the real key are in hand, so values are never encrypted under a
// missing or wrong key.
function migrateEncryptSettings(PDO $db): void {
    if (!fsCryptoAvailable()) return;
    if (strlen(fsEncKey()) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) return;
    $upd = $db->prepare('UPDATE settings SET value = ? WHERE key = ?');
    foreach ($db->query('SELECT key, value FROM settings')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $k = (string)$r['key'];
        $v = (string)$r['value'];
        if (!fsSettingIsEncrypted($k) || $v === '' || fsIsEncrypted($v)) continue;
        $upd->execute([fsEncrypt($v), $k]);
    }
}

// Adds inventory.deliverable (default 1) on installs that pre-date the
// PantryPrep visibility flag. Existing rows become deliverable by default so
// behavior is unchanged until the admin starts unchecking specific rows.
function migrateAddInventoryDeliverable(PDO $db): void {
    $cols = $db->query("PRAGMA table_info(inventory)")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        if (($c['name'] ?? '') === 'deliverable') return; // already present
    }
    $db->exec("ALTER TABLE inventory ADD COLUMN deliverable INTEGER NOT NULL DEFAULT 1");
}

// Adds the two lifetime-source counters (restocked_purchased,
// restocked_donated) that drive the Purchased % column on inventory.php.
// Each Restock batch increments one or the other by the staged amount,
// in addition to the usual `count` increment. Existing rows start at 0
// for both, so the Purchased % column renders as "—" until a restock
// records a source.
function migrateAddInventoryRestockSource(PDO $db): void {
    $cols = $db->query("PRAGMA table_info(inventory)")->fetchAll(PDO::FETCH_ASSOC);
    $have = [];
    foreach ($cols as $c) $have[$c['name'] ?? ''] = true;
    if (!isset($have['restocked_purchased'])) {
        $db->exec("ALTER TABLE inventory ADD COLUMN restocked_purchased REAL NOT NULL DEFAULT 0");
    }
    if (!isset($have['restocked_donated'])) {
        $db->exec("ALTER TABLE inventory ADD COLUMN restocked_donated REAL NOT NULL DEFAULT 0");
    }
}

// Adds inventory.count_per_case (default 0 = "not set"), entered on the
// Inventory page and used by the Order Report's Case Request column:
// cases = ceil(order request / count_per_case).
function migrateAddInventoryCountPerCase(PDO $db): void {
    $cols = $db->query("PRAGMA table_info(inventory)")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        if (($c['name'] ?? '') === 'count_per_case') return;
    }
    $db->exec("ALTER TABLE inventory ADD COLUMN count_per_case REAL NOT NULL DEFAULT 0");
}

// Adds inventory.order_unit ('' = same as `unit`) and inventory.lb_per_each
// (0 = not set) so an item can be stocked in one unit and ordered in another —
// avocados weighed on the scale in lb but bought as a 48-count case. Both
// defaults are inert: until an admin fills them in on the Inventory page, every
// item keeps ordering in its stock unit exactly as before, so count_per_case
// values entered under the old "always in `unit`" meaning stay correct.
function migrateAddInventoryOrderUnit(PDO $db): void {
    $cols = $db->query("PRAGMA table_info(inventory)")->fetchAll(PDO::FETCH_ASSOC);
    $have = [];
    foreach ($cols as $c) $have[$c['name'] ?? ''] = true;
    if (!isset($have['order_unit'])) {
        $db->exec("ALTER TABLE inventory ADD COLUMN order_unit TEXT NOT NULL DEFAULT ''");
    }
    if (!isset($have['lb_per_each'])) {
        $db->exec("ALTER TABLE inventory ADD COLUMN lb_per_each REAL NOT NULL DEFAULT 0");
    }
}

// Adds inventory.alt_case ('' = not set), the vendor's own wording for an
// item's pack ("89-100 ct case(s), least expensive variety"). Empty everywhere
// until an admin fills it in on the Inventory page, so order emails keep
// reading "4 cases - Apples (40 lb)" exactly as before.
function migrateAddInventoryAltCase(PDO $db): void {
    $cols = $db->query("PRAGMA table_info(inventory)")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        if (($c['name'] ?? '') === 'alt_case') return;
    }
    $db->exec("ALTER TABLE inventory ADD COLUMN alt_case TEXT NOT NULL DEFAULT ''");
}

// Adds inventory.crates_per_case (0 = not set), how many cubic feet one
// supplier case takes up on the floor. Entered on the Inventory page and summed
// by the Order Report against the max_storage_crates setting. Default 0 leaves
// every existing row unconfigured, so the capacity total reads 0 and warns
// about nothing until an admin fills the column in.
function migrateAddInventoryCratesPerCase(PDO $db): void {
    $cols = $db->query("PRAGMA table_info(inventory)")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        if (($c['name'] ?? '') === 'crates_per_case') return;
    }
    $db->exec("ALTER TABLE inventory ADD COLUMN crates_per_case REAL NOT NULL DEFAULT 0");
}

// Adds delivery_clients.volunteer (default '') for the optional "volunteer
// assigned to the client" field on client.php. Existing rows get an empty
// string, which renders as blank everywhere it's surfaced.
function migrateAddDeliveryClientVolunteer(PDO $db): void {
    $cols = $db->query("PRAGMA table_info(delivery_clients)")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        if (($c['name'] ?? '') === 'volunteer') return;
    }
    $db->exec("ALTER TABLE delivery_clients ADD COLUMN volunteer TEXT NOT NULL DEFAULT ''");
}

// Adds orders.station (default '') so concurrent scanning stations can each
// own a distinct open order. Existing/legacy orders keep '' — they're already
// resolved by id, and currentOpenOrder() only scopes the *open* lookup, so the
// blank station is harmless for closed history.
function migrateAddOrderStation(PDO $db): void {
    $cols = $db->query("PRAGMA table_info(orders)")->fetchAll(PDO::FETCH_ASSOC);
    $have = false;
    foreach ($cols as $c) {
        if (($c['name'] ?? '') === 'station') { $have = true; break; }
    }
    if (!$have) {
        $db->exec("ALTER TABLE orders ADD COLUMN station TEXT NOT NULL DEFAULT ''");
    }
    // Ensure the index exists in both cases: fresh installs already have the
    // column from schema.sql (so the ALTER is skipped), and existing installs
    // just gained it above. CREATE INDEX is omitted from schema.sql because it
    // runs before this migration, when the column may not exist yet.
    $db->exec("CREATE INDEX IF NOT EXISTS idx_orders_station ON orders(status, station)");
}

// Adds scans.station (default '') so a team-scanned order can show which
// station entered each row. Existing scans keep '' — they predate team
// scanning, and so do the delivery / event / orderahead channels, which insert
// scans directly with no station. The UI treats '' as "unattributed".
function migrateAddScanStation(PDO $db): void {
    $cols = $db->query("PRAGMA table_info(scans)")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        if (($c['name'] ?? '') === 'station') return;
    }
    $db->exec("ALTER TABLE scans ADD COLUMN station TEXT NOT NULL DEFAULT ''");
}

// Adds alerts.email_enabled (default 0) on installs created before reorder
// reminders could be emailed. Existing alerts stay banner-only until the admin
// ticks their Email box on the Order Report.
function migrateAddAlertEmailEnabled(PDO $db): void {
    $cols = $db->query("PRAGMA table_info(alerts)")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        if (($c['name'] ?? '') === 'email_enabled') return;
    }
    $db->exec("ALTER TABLE alerts ADD COLUMN email_enabled INTEGER NOT NULL DEFAULT 0");
}

// Adds upc_lookup.recalled (default 0) on installs created before product
// recalls could be flagged. Every existing mapping stays scannable; an admin
// ticks the Recalled box on the Lookup Tables page to pull one.
function migrateAddUpcRecalled(PDO $db): void {
    $cols = $db->query("PRAGMA table_info(upc_lookup)")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        if (($c['name'] ?? '') === 'recalled') return;
    }
    $db->exec("ALTER TABLE upc_lookup ADD COLUMN recalled INTEGER NOT NULL DEFAULT 0");
}

function seedProduce(PDO $db): void {
    // Standard 4-digit PLU codes for conventionally-grown common produce.
    // Add 12-digit "starts with 4" entries via the admin page if your pantry
    // prints its own UPC-A labels at the scale.
    $rows = [
        ['4087', 'Tomatoes',      'lb'],
        ['4078', 'Corn',          'each'],
        ['4072', 'Potatoes',      'lb'],
        ['4066', 'Green Beans',   'lb'],
        ['4562', 'Carrots',       'lb'],
        ['4011', 'Bananas',       'lb'],
        ['4133', 'Apples',        'lb'],
        ['4046', 'Avocados',      'each'],
        ['4082', 'Lemons',        'each'],
        ['4093', 'Onions Yellow', 'lb'],
        ['4079', 'Cucumbers',     'each'],
        ['4664', 'Lettuce Iceberg','each'],
        ['4640', 'Lettuce Romaine','each'],
        ['4068', 'Green Bell Pepper','each'],
        ['4081', 'Garlic',        'each'],
        ['4225', 'Sweet Potatoes','lb'],
        ['4080', 'Asparagus',     'lb'],
        ['4069', 'Cabbage Green', 'lb'],
        ['4060', 'Broccoli',      'lb'],
        ['4555', 'Squash Zucchini','lb'],
    ];
    $ins = $db->prepare(
        'INSERT OR IGNORE INTO produce_lookup (code, generic_name, unit) VALUES (?, ?, ?)'
    );
    foreach ($rows as $r) $ins->execute($r);
}

function seedSettings(PDO $db): void {
    $defaults = [
        'openai_api_key'   => '',
        'openai_model'     => 'gpt-4o-mini',
        'default_lead_time'=> '14',     // days
        'safety_z'         => '1.65',   // 95% confidence
        'velocity_window'  => '30',     // trailing days for avg daily velocity
        // Cubic feet of storage the pantry can hold. 0 = no limit set, which is
        // what existing installs read via setting()'s default — the Order Report
        // then shows the cu ft total without comparing it to anything.
        'max_storage_crates' => '0',
        'admin_password'   => 'admin',  // shared login (FoodScan + PantryPrep)
        'allowed_ip'       => '',       // primary allowed IPv4; all three empty = no gate
        'allowed_ip2'      => '',       // optional second allowed IPv4
        'allowed_ip3'      => '',       // optional third allowed IPv4
        'admin_email'      => '',       // where reorder-reminder emails are sent
        // Outbound email (reorder reminders). Empty smtp_host = use PHP mail().
        'smtp_host'        => '',
        'smtp_port'        => '',       // blank → 465 (ssl) / 587 (tls|none)
        'smtp_security'    => 'tls',    // '' (none) | 'ssl' | 'tls' (STARTTLS)
        'smtp_user'        => '',
        'smtp_pass'        => '',       // encrypted at rest (see crypto.php)
        'smtp_from'        => '',       // From address; blank → smtp_user
        'smtp_from_name'   => '',       // From display name; blank → pantry name
        'smtp_insecure'    => '0',      // 1 = don't verify the TLS cert name (shared hosting)
    ];
    $ins = $db->prepare('INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)');
    foreach ($defaults as $k => $v) $ins->execute([$k, $v]);
}

// Migrate admin_password / allowed_ip out of the legacy picklist.db settings
// table. Runs on every getDB() but is a no-op once both keys are present in
// openpantry.db. Picklist.db itself is opened read-only and never modified.
function migrateAdminAccessSettings(PDO $db): void {
    $stmt = $db->prepare(
        "SELECT key FROM settings WHERE key IN ('admin_password','allowed_ip')"
    );
    $stmt->execute();
    $present = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
    if (isset($present['admin_password']) && isset($present['allowed_ip'])) {
        return; // already migrated (or seeded)
    }

    $picklistPath = fsDbPath('picklist.db');
    if (!file_exists($picklistPath)) {
        // No legacy DB to migrate from — fall back to defaults.
        $db->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES ('admin_password', 'admin')")->execute();
        $db->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES ('allowed_ip', '')")->execute();
        return;
    }
    try {
        $old = new PDO('sqlite:' . $picklistPath);
        $old->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pick = $old->prepare('SELECT value FROM settings WHERE key = ?');

        if (!isset($present['admin_password'])) {
            $pick->execute(['admin_password']);
            $v = $pick->fetchColumn();
            $db->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES ('admin_password', ?)")
               ->execute([$v === false ? 'admin' : (string)$v]);
        }
        if (!isset($present['allowed_ip'])) {
            $pick->execute(['allowed_ip']);
            $v = $pick->fetchColumn();
            $db->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES ('allowed_ip', ?)")
               ->execute([$v === false ? '' : (string)$v]);
        }
    } catch (\PDOException $e) {
        // Picklist.db unreadable or settings table missing — seed defaults.
        $db->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES ('admin_password', 'admin')")->execute();
        $db->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES ('allowed_ip', '')")->execute();
    }
}

function setting(string $key, ?string $default = null): ?string {
    $db = getDB();
    $stmt = $db->prepare('SELECT value FROM settings WHERE key = ?');
    $stmt->execute([$key]);
    $v = $stmt->fetchColumn();
    if ($v === false) return $default;
    // Settings are encrypted at rest — decrypt on read. fsDecrypt passes
    // legacy plaintext values through untouched.
    if (fsSettingIsEncrypted($key)) {
        return fsDecrypt((string)$v);
    }
    return $v;
}

function setSetting(string $key, string $value): void {
    // Encrypt at rest (every key except the FS_UNENCRYPTED_SETTINGS
    // exemptions). fsMaybeEncrypt is idempotent and leaves empty strings as ''.
    if (fsSettingIsEncrypted($key)) {
        $value = fsMaybeEncrypt($value);
    }
    $db = getDB();
    $stmt = $db->prepare(
        'INSERT INTO settings (key, value) VALUES (?, ?)
         ON CONFLICT(key) DO UPDATE SET value = excluded.value'
    );
    $stmt->execute([$key, $value]);
}

function now(): string { return date('Y-m-d H:i:s'); }

// ── The placeholder name ───────────────────────────────────────────
// What a packaged UPC is called while Settings -> Ignore Unknown Items is on
// and nothing — cache, store-label rules, Open Food Facts — can name it.
//
// The switch used to mean "wave the item past and record nothing", which kept
// the counter moving but spent the pantry's own data to do it: a case of
// donated goods crossed the scanner and left no trace, so it counted toward no
// household's order and no report. lookup.php records the item under this name
// instead and caches the UPC against it, so the volume is captured now and the
// naming can happen later.
//
// It is an ordinary upc_lookup row, not a flag, which is what lets most of the
// app carry on unchanged: it appears on the Inventory page and in the usage and
// impact reports, and every UPC still waiting for a name shares the one row.
// Two places do have to know it by name:
//
//   * lookupBarcode() refuses to answer with it once the switch is off — the
//     station opens the Identify window instead, and the name typed there
//     replaces the placeholder for that one UPC, taking that UPC's scan history
//     with it (renameUPCScans).
//   * op_report_rows() drops it, which also silences any reorder alert on it.
//     "Order 4 cases of Unidentified" is not an instruction anyone can act on:
//     the row is an unknowable mix of items, so its demand history models
//     nothing and its par level buys nothing. It stays visible where it is
//     honest — how much unlisted stock moved — and out of what the pantry buys.
//
// Defined here rather than in lookup.php because the report code needs it and
// has no reason to load the OFF/OpenAI resolver.
const UNIDENTIFIED_NAME = 'Unidentified';

// ── Unidentified-UPC cache (legacy, drain-only) ────────────────────
// Companion to upc_lookup holding UPCs Open Food Facts has no product for, so
// a pantry running with Ignore Unknown Items on doesn't re-ask OFF about the
// same case every time it crosses the scanner.
//
// Superseded: those UPCs now get a real upc_lookup row under the
// 'Unidentified' placeholder (lookup.php's nameUPCUnidentified), which answers
// the next scan from the cache read that already runs first AND lets the item
// be recorded instead of dropped. So nothing writes this table any more, and
// the function that did is gone; upcIsUnidentified() is still read so rows the
// older build left behind convert to placeholders on their next scan, and
// forgetUnidentifiedUPC() clears each one as it does. They live here rather
// than in lookup.php because the admin pages that name a UPC by hand have no
// reason to pull in the OFF/OpenAI code.

function upcIsUnidentified(string $upc): bool {
    $stmt = getDB()->prepare('SELECT 1 FROM upc_unidentified WHERE upc = ?');
    $stmt->execute([$upc]);
    return (bool)$stmt->fetchColumn();
}

// Called wherever a upc_lookup row is created, regardless of the switch: this
// is cleanup, not a lookup, and a named UPC is no longer an unidentified one.
function forgetUnidentifiedUPC(string $upc): void {
    getDB()->prepare('DELETE FROM upc_unidentified WHERE upc = ?')->execute([$upc]);
}
