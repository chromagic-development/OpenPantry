<?php
// Where the SQLite databases live. Shared by FoodScan (db.php), PantryPrep
// (menucounter/db.php), the delivery helpers, and the CLI cron, so every PDO
// open resolves the same file.
//
// By default openpantry.db sits in the app root and picklist.db in
// menucounter/ — zero-config for a fresh install, but inside the web root,
// which leaves the raw .db files one guessable URL away from being
// downloaded. Point OPENPANTRY_DB_DIR at a directory OUTSIDE the web root
// (same idea as OPENPANTRY_KEY_PATH in crypto.php) and the app stores both
// databases there instead:
//
//   openpantry/.htaccess:
//     SetEnv OPENPANTRY_DB_DIR /home/you/domains/example.org/openpantry_data
//
// On the first request after the setting appears, a database still sitting in
// the web tree is moved into that directory automatically. The move is
// fail-safe: the WAL is checkpointed into the main file first, and if
// anything goes wrong (directory missing and uncreatable, checkpoint blocked
// by a concurrent writer, rename refused) the app keeps using the in-web-root
// copy and logs why, so a half-finished migration can't take the pantry
// offline. Remember to re-point scheduled backups at the new directory.

// Apache's SetEnv never reaches CLI PHP, so cron_reorder_alerts.php would see
// neither OPENPANTRY_DB_DIR nor OPENPANTRY_KEY_PATH and silently fall back to
// the legacy in-web-root paths (worst case recreating an empty database or a
// fresh key there). When a var is absent, lift it from the .htaccess beside
// this file so web requests and cron agree. Values must not contain spaces.
(function () {
    $need = [];
    foreach (['OPENPANTRY_DB_DIR', 'OPENPANTRY_KEY_PATH'] as $k) {
        $v = getenv($k);
        if ((!is_string($v) || $v === '') && empty($_SERVER[$k])) $need[] = $k;
    }
    if (!$need) return;
    $txt = @file_get_contents(__DIR__ . '/.htaccess');
    if (!is_string($txt)) return;
    foreach ($need as $k) {
        if (preg_match('/^[ \t]*SetEnv[ \t]+' . $k . '[ \t]+(\S+)/mi', $txt, $m)) {
            putenv($k . '=' . $m[1]);
            $_SERVER[$k] = $m[1];
        }
    }
})();

// The configured database directory, created on first use so "add the SetEnv
// line" is the only manual deployment step. '' = unset or unusable, meaning
// the legacy in-web-root locations stay in effect.
function fsDbDir(): string {
    static $dir = null;
    if ($dir !== null) return $dir;
    $v = getenv('OPENPANTRY_DB_DIR');
    if (!is_string($v) || $v === '') $v = (string)($_SERVER['OPENPANTRY_DB_DIR'] ?? '');
    $v = rtrim($v, '/\\');
    if ($v === '') return $dir = '';
    if (!is_dir($v) && !@mkdir($v, 0700, true)) {
        error_log("OpenPantry: OPENPANTRY_DB_DIR '$v' is missing and could not be created — databases stay in the web tree.");
        return $dir = '';
    }
    return $dir = $v;
}

// Resolve a database filename ('openpantry.db' or 'picklist.db') to the path
// every PDO open must use. Relocates a legacy in-web-root file into
// OPENPANTRY_DB_DIR the first time it's asked for after the var is set.
function fsDbPath(string $file): string {
    static $resolved = [];
    if (isset($resolved[$file])) return $resolved[$file];

    $legacyDir = ($file === 'picklist.db') ? __DIR__ . '/menucounter' : __DIR__;
    $legacy    = $legacyDir . '/' . $file;

    $dir = fsDbDir();
    if ($dir === '') return $resolved[$file] = $legacy;

    $target = $dir . '/' . $file;
    if (!is_file($target) && is_file($legacy)) {
        fsRelocateDb($legacy, $target);
    }
    // A failed relocation keeps the app on the legacy copy (and retries next
    // request); a fresh install (neither file exists) creates the database at
    // the protected path from the start.
    return $resolved[$file] = (is_file($target) || !is_file($legacy)) ? $target : $legacy;
}

// Move one SQLite database into the protected directory. Both apps run
// journal_mode=WAL, so recent commits can live in <db>-wal rather than the
// main file — checkpoint them in before touching anything, and defer the move
// if a concurrent connection blocks the checkpoint.
function fsRelocateDb(string $from, string $to): void {
    try {
        $pdo = new PDO('sqlite:' . $from);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $row = $pdo->query('PRAGMA wal_checkpoint(TRUNCATE)')->fetch(PDO::FETCH_NUM);
        $pdo = null;
        if (is_array($row) && (int)$row[0] === 1) {
            error_log("OpenPantry: $from is busy; move to $to deferred to a later request.");
            return;
        }
    } catch (\Throwable $e) {
        error_log("OpenPantry: could not checkpoint $from before moving it: " . $e->getMessage());
        return;
    }
    if (!@rename($from, $to)) {
        if (is_file($to)) return; // a concurrent request won the race — fine
        // rename can fail across filesystems; fall back to copy + delete.
        if (!@copy($from, $to)) {
            error_log("OpenPantry: could not move $from to $to — still using the web-tree copy.");
            return;
        }
        @unlink($from);
    }
    @chmod($to, 0600);
    // The checkpoint emptied the sidecars; clear them out of the web tree.
    @unlink($from . '-wal');
    @unlink($from . '-shm');
}
