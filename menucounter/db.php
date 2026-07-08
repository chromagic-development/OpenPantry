<?php
// Read a setting out of openpantry.db (one folder up). Lives here so PantryPrep
// pages can fetch `admin_password` / `allowed_ip` from FoodScan's settings
// table without including foodscan/db.php (which would collide on getDB()).
require_once __DIR__ . '/../crypto.php'; // shared field-level decryption + access-schedule eval

// Match the FoodScan side (common.php) so date()-based logic — notably the
// allowed-hours access gate — reads Eastern wall-clock time on every page.
date_default_timezone_set('America/New_York');

function foodscanSetting(string $key, ?string $default = null): ?string {
    static $fdb = null;
    static $missing = false;
    if ($missing) return $default;
    if ($fdb === null) {
        $path = __DIR__ . '/../openpantry.db';
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
        // Sensitive keys (admin_password / allowed_ip) are encrypted at rest.
        if (in_array($key, FS_ENCRYPTED_SETTINGS, true)) {
            return fsDecrypt((string)$v);
        }
        return (string)$v;
    } catch (\PDOException $e) {
        return $default;
    }
}

function getDB() {
    $dbPath = __DIR__ . '/picklist.db';
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$db->exec("PRAGMA journal_mode = WAL");
    $db->exec("PRAGMA busy_timeout = 5000"); // wait up to 5 seconds before failing on lock
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
