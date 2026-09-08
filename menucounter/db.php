<?php
// Read a setting out of openpantry.db (one folder up). Lives here so PantryPrep
// pages can fetch `admin_password` / `allowed_ip` from FoodScan's settings
// table without including foodscan/db.php (which would collide on getDB()).
require_once __DIR__ . '/../paths.php';  // database locations (OPENPANTRY_DB_DIR)
require_once __DIR__ . '/../crypto.php'; // shared field-level decryption + access-schedule eval

// Match the FoodScan side (common.php) so date()-based logic — notably the
// allowed-hours access gate — reads Eastern wall-clock time on every page.
date_default_timezone_set('America/New_York');

function foodscanSetting(string $key, ?string $default = null): ?string {
    static $fdb = null;
    static $missing = false;
    if ($missing) return $default;
    if ($fdb === null) {
        $path = fsDbPath('openpantry.db');
        if (!file_exists($path)) { $missing = true; return $default; }
        try {
            $fdb = new PDO('sqlite:' . $path);
            $fdb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (\PDOException $e) {
            $missing = true;
            return $default;
        }
    }
    try {
        $s = $fdb->prepare('SELECT value FROM settings WHERE key = ?');
        $s->execute([$key]);
        $v = $s->fetchColumn();
        if ($v === false) return $default;
        // Settings are encrypted at rest (admin_password stays a plain
        // password_hash). fsDecrypt passes legacy plaintext through untouched.
        if (fsSettingIsEncrypted($key)) {
            return fsDecrypt((string)$v);
        }
        return (string)$v;
    } catch (\PDOException $e) {
        return $default;
    }
}

// The public IPv4 addresses the network gate accepts — the primary one plus
// the two optional extras (Settings -> Secure Network Access). Mirrors
// fpAllowedIPs() in auth.php, read through this app's own settings reader.
function foodscanAllowedIPs(): array {
    $ips = [];
    foreach (['allowed_ip', 'allowed_ip2', 'allowed_ip3'] as $key) {
        $ip = trim((string)(foodscanSetting($key, '') ?? ''));
        if ($ip !== '') $ips[] = $ip;
    }
    return $ips;
}

// ── Shared admin/supervisor auth cookie ──────────────────────────────────────
// The PantryPrep pages here trust the same `fp_admin_auth` cookie FoodScan
// issues (auth.php). Two passwords can mint it: `admin_password` and the
// optional `supervisor_password` (Settings -> Supervisor Password), which
// opens the same pages. The token is a SHA-256 of the STORED value — the
// password_hash, not the typed password — so rotating either one invalidates
// the sessions it issued.
function fpMcAuthToken(string $stored): string {
    return hash('sha256', 'fp_admin_' . $stored);
}

function foodscanSupervisorStored(): string {
    return (string)foodscanSetting('supervisor_password', '');
}

function foodscanVerifySupervisorPassword(string $submitted): bool {
    $stored = foodscanSupervisorStored();
    return $stored !== '' && fpVerifyAdminPassword($submitted, $stored);
}

// The stored value the current cookie was issued for, or null when the cookie
// is missing or stale. Callers re-issue the cookie from this so a sliding
// renewal keeps a supervisor session a supervisor session. Null rather than ''
// because an empty stored password is itself a (legacy) valid seed.
function foodscanAuthSeed(): ?string {
    $cookie = $_COOKIE['fp_admin_auth'] ?? '';
    if ($cookie === '') return null;
    $admin = (string)foodscanSetting('admin_password', 'admin');
    if (hash_equals(fpMcAuthToken($admin), $cookie)) return $admin;
    $sup = foodscanSupervisorStored();
    if ($sup !== '' && hash_equals(fpMcAuthToken($sup), $cookie)) return $sup;
    return null;
}

function foodscanAuthCookieValid(): bool {
    return foodscanAuthSeed() !== null;
}

// 'admin', 'supervisor', or '' when the cookie is missing or stale. Mirrors
// fpAuthRole() in ../../auth.php. Admin is tested first, so a supervisor
// password set equal to the administrator's reads as an admin session.
function foodscanAuthRole(): string {
    $seed = foodscanAuthSeed();
    if ($seed === null) return '';
    $admin = (string)foodscanSetting('admin_password', 'admin');
    if (hash_equals($admin, $seed)) return 'admin';
    return 'supervisor';
}

// True when the visitor holds the cookie the SUPERVISOR password minted. Pages
// here use it the way settings.php does: show the tool, withhold the writes.
function foodscanIsSupervisor(): bool {
    return foodscanAuthRole() === 'supervisor';
}

// One connection per request, opened on first use. Every page here calls
// getDB() once, but the order-form kiosks poll api.php every few seconds, so
// each avoided reconnect is one less connection contending for the file.
function getDB() {
    static $db = null;
    if ($db !== null) return $db;
    try {
        return $db = openPicklistDb();
    } catch (\PDOException $e) {
        // A locked or unreadable picklist.db used to surface as a bare 500 with
        // nothing written anywhere. Log the real SQLite message, then rethrow
        // something a caller can show a human — api.php catches Exception and
        // returns it as JSON.
        error_log('OpenPantry: picklist.db unavailable (' . fsDbPath('picklist.db') . ') — ' . $e->getMessage());
        throw new \RuntimeException(
            'The pantry database is busy or unavailable. Please try again in a moment.',
            0,
            $e
        );
    }
}

function openPicklistDb(): PDO {
    $dbPath = fsDbPath('picklist.db');
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Set the busy timeout before any pragma that can itself need a lock: until
    // it is set, a contended statement fails with SQLITE_BUSY instantly and
    // gets no retry window at all.
    $db->exec("PRAGMA busy_timeout = 5000"); // wait up to 5 seconds before failing on lock
    // Only *assign* journal_mode when it is actually wrong. Reading it takes no
    // lock; converting the file to WAL needs an exclusive one, which is
    // unobtainable while a kiosk is polling. WAL is persistent in the database
    // header, so on a healthy install this branch never runs.
    if (strtolower((string)$db->query("PRAGMA journal_mode")->fetchColumn()) !== 'wal') {
        $db->exec("PRAGMA journal_mode = WAL");
    }
    $db->exec("PRAGMA foreign_keys = ON");

    // Migrate: add unavailable column if it doesn't exist yet
    try { $db->exec("ALTER TABLE config_items ADD COLUMN unavailable INTEGER DEFAULT 0"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE config_items ADD COLUMN size_options TEXT DEFAULT ''"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE config_items ADD COLUMN family_factor REAL DEFAULT 1.0"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE order_items ADD COLUMN config_item_id INTEGER DEFAULT NULL"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE config_items ADD COLUMN use_adults INTEGER DEFAULT 0"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE config_items ADD COLUMN use_children INTEGER DEFAULT 0"); } catch (Exception $e) {}

    $db->exec("CREATE TABLE IF NOT EXISTS orders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        adults INTEGER DEFAULT 0,
        children INTEGER DEFAULT 0,
        week_date TEXT,
        notes TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        status TEXT DEFAULT 'pending'
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS order_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL,
        category TEXT NOT NULL,
        item_name TEXT NOT NULL,
        item_detail TEXT DEFAULT '',
        completed INTEGER DEFAULT 0,
        FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS config_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        category TEXT NOT NULL,
        item_name TEXT NOT NULL,
        has_detail INTEGER DEFAULT 0,
        detail_label TEXT DEFAULT '',
        active INTEGER DEFAULT 1,
        sort_order INTEGER DEFAULT 0
    )");

    // Menucounter-local settings (key/value). Currently just `client_notes`,
    // the optional "Special Notes to Clients" line shown on the order form.
    $db->exec("CREATE TABLE IF NOT EXISTS menu_settings (
        key TEXT PRIMARY KEY,
        value TEXT DEFAULT ''
    )");

    // (admin_password / allowed_ip moved to openpantry.db — see foodscanSetting().)

    // Seed default items if table is empty
    $count = $db->query("SELECT COUNT(*) FROM config_items")->fetchColumn();
    if ($count == 0) {
        $items = [
            ['DAIRY',        'Salted Butter',               0, '',     1, 1],
            ['DAIRY',        'Unsalted Butter',              0, '',     1, 2],
            ['DAIRY',        'Eggs',                        0, '',     1, 3],
            ['DRY GOODS',    'Canned Tuna',                 0, '',     1, 1],
            ['DRY GOODS',    'Canned Chicken',              0, '',     1, 2],
            ['DRY GOODS',    'Almond Milk',                 0, '',     1, 3],
            ['DRY GOODS',    "Kid's Snacks (16 and Under)", 0, '',     1, 4],
            ['FROZEN ITEMS', 'Ground Beef',                 0, '',     1, 1],
            ['FROZEN ITEMS', 'Fish Nuggets',                0, '',     1, 2],
            ['FROZEN ITEMS', 'Whole Turkey',                0, '',     1, 3],
            ['SPECIALS',     'Coffee',                      0, '',     1, 1],
            ['SPECIALS',     'Tea',                         0, '',     1, 2],
            ['OTHER ITEMS',  'Diapers (Child)',             1, 'Size', 1, 1],
            ['OTHER ITEMS',  'Diapers (Adult) Male/Female', 1, 'Size', 1, 2],
        ];
        $stmt = $db->prepare(
            "INSERT INTO config_items (category, item_name, has_detail, detail_label, active, sort_order)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        foreach ($items as $row) {
            $stmt->execute($row);
        }
    }

    return $db;
}

// Read a menucounter-local setting from picklist.db (menu_settings table).
function menuSetting(PDO $db, string $key, string $default = ''): string {
    try {
        $s = $db->prepare('SELECT value FROM menu_settings WHERE key = ?');
        $s->execute([$key]);
        $v = $s->fetchColumn();
        return $v === false ? $default : (string)$v;
    } catch (\PDOException $e) {
        return $default;
    }
}

function setMenuSetting(PDO $db, string $key, string $value): void {
    $db->prepare('INSERT OR REPLACE INTO menu_settings (key, value) VALUES (?, ?)')
       ->execute([$key, $value]);
}
