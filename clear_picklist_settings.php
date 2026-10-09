<?php
// One-off cleanup: blank admin_password and allowed_ip in picklist.db's legacy
// `settings` table. migrateAdminAccessSettings() (db.php) copied both into
// openpantry.db long ago and nothing reads them from picklist.db any more, but
// the admin password was still sitting there in plain text.
//
// Load this page once, check the output, then DELETE THIS FILE from the server.
require_once __DIR__ . '/paths.php'; // fsDbPath(): the live database locations

header('Content-Type: text/plain; charset=utf-8');

$keys = ['admin_password', 'allowed_ip'];

try {
    $appPath  = fsDbPath('openpantry.db');
    $pickPath = fsDbPath('picklist.db');
    if (!is_file($appPath))  throw new RuntimeException('openpantry.db was not found.');
    if (!is_file($pickPath)) throw new RuntimeException('picklist.db was not found.');

    // Guard: only blank picklist.db's copies once openpantry.db holds both
    // keys. If one were missing, the migration would copy the blank across.
    $app = new PDO('sqlite:' . $appPath);
    $app->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $have = $app->query("SELECT key FROM settings WHERE key IN ('admin_password','allowed_ip')")
                ->fetchAll(PDO::FETCH_COLUMN);
    $app = null;
    $missing = array_diff($keys, $have);
    if ($missing) {
        throw new RuntimeException('openpantry.db has no ' . implode(' / ', $missing)
            . ' row yet. Nothing was changed.');
    }

    $pick = new PDO('sqlite:' . $pickPath);
    $pick->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pick->exec('PRAGMA busy_timeout = 5000'); // the menu counter may be mid-write

    $hasTable = $pick->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='settings'")
                     ->fetchColumn();
    if (!$hasTable) {
        echo "picklist.db has no settings table. Nothing to do.\n";
        exit;
    }

    // Zero the old bytes instead of just marking them free, so the plaintext
    // password doesn't linger in the file's unused space.
    $pick->exec('PRAGMA secure_delete = ON');

    $sel = $pick->prepare('SELECT value FROM settings WHERE key = ?');
    $upd = $pick->prepare("UPDATE settings SET value = '' WHERE key = ?");
    foreach ($keys as $k) {
        $sel->execute([$k]);
        $v = $sel->fetchColumn();
        $sel->closeCursor();
        if ($v === false) {
            $msg = 'no row, nothing to clear';
        } elseif ((string)$v === '') {
            $msg = 'already empty';
        } else {
            $upd->execute([$k]);
            $msg = 'cleared';
        }
        echo str_pad($k, 16) . $msg . "\n";
    }

    // picklist.db runs in WAL mode; fold the change into the main file now.
    $row = $pick->query('PRAGMA wal_checkpoint(TRUNCATE)')->fetch(PDO::FETCH_NUM);
    echo (is_array($row) && (int)$row[0] === 1)
        ? "\nSaved. The menu counter was busy, so SQLite will finish folding it in on its own.\n"
        : "\nDone. Now delete this file from the server.\n";
} catch (\Throwable $e) {
    http_response_code(500);
    echo 'Error: ' . $e->getMessage() . "\n";
}
