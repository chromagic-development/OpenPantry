<?php
// Progressive rate limiting for the admin login, shared by FoodScan
// (auth.php requireLogin) and the PantryPrep admin panel (menucounter/admin).
//
// Failures are tracked per client IP (REMOTE_ADDR — never X-Forwarded-For,
// which the client controls) in a `login_throttle` table in openpantry.db:
//
//   failures 1–2   free retries (typos happen)
//   failure  3     10-second wait               ── the IP is now "flagged"
//   failure  4     30-second wait
//   failure  5+    SOFT-LOCK: a single-use 6-digit code is emailed to the
//                  administrator (Settings → admin_email) and must be entered
//                  alongside the password. No waiting when the code is used.
//                  When no code can be issued (no admin email configured,
//                  mail failure, resend pacing), an escalating hard timeout
//                  is the safety net: 1 → 2 → 4 → 8 minutes, capped at 10.
//
// A successful login clears the IP's record; 30 quiet minutes decay it. The
// emailed code lives 15 minutes, dies after 5 wrong tries, and re-sends are
// paced per IP (2 min) and globally (1 min) so failed logins can't be used
// to flood the administrator's inbox.
//
// Everything fails OPEN: if openpantry.db doesn't exist yet (fresh install,
// schema not seeded) or a query throws, login behaves exactly as before —
// the throttle is a hardening layer, never the reason the pantry is locked
// out of its own app.

require_once __DIR__ . '/paths.php';
require_once __DIR__ . '/crypto.php';

const FP_THROTTLE_SOFTLOCK_AT = 5;    // failures before the soft-lock engages
const FP_THROTTLE_MAX_WAIT    = 600;  // timeout cap: 10 minutes
const FP_THROTTLE_DECAY       = 1800; // forget failures after 30 quiet minutes
const FP_OTP_TTL              = 900;  // emailed code lives 15 minutes
const FP_OTP_MAX_TRIES        = 5;    // wrong entries before the code dies
const FP_OTP_RESEND_IP        = 120;  // per-IP minimum between code emails
const FP_OTP_RESEND_GLOBAL    = 60;   // any-IP minimum between code emails

// The PantryPrep side loads menucounter/db.php, which has foodscanSetting()
// but not setting() — and op_send_mail() (mailer.php) reads its SMTP config
// through setting(). Provide a read-only clone backed by the throttle
// connection so the soft-lock email works from both apps.
if (!function_exists('setting')) {
    function setting(string $key, ?string $default = null): ?string {
        $db = fpThrottleDB();
        if (!$db) return $default;
        try {
            $s = $db->prepare('SELECT value FROM settings WHERE key = ?');
            $s->execute([$key]);
            $v = $s->fetchColumn();
        } catch (\PDOException $e) {
            return $default;
        }
        if ($v === false) return $default;
        return fsSettingIsEncrypted($key) ? fsDecrypt((string)$v) : (string)$v;
    }
}

// Private PDO handle to openpantry.db (both apps can be in play, each with
// its own getDB(), so the throttle keeps its own connection). Returns null —
// disabling the throttle — when the database file doesn't exist yet: opening
// it here would create an empty file and make db.php's first-run seeding
// think the install is already initialized.
function fpThrottleDB(): ?PDO {
    static $db = null;
    if ($db instanceof PDO) return $db;
    if ($db === false) return null;
    $path = fsDbPath('openpantry.db');
    if (!is_file($path)) { $db = false; return null; }
    try {
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec("CREATE TABLE IF NOT EXISTS login_throttle (
            ip           TEXT PRIMARY KEY,
            fails        INTEGER NOT NULL DEFAULT 0,
            last_fail_at INTEGER NOT NULL DEFAULT 0,
            otp_hash     TEXT    NOT NULL DEFAULT '',
            otp_expires  INTEGER NOT NULL DEFAULT 0,
            otp_tries    INTEGER NOT NULL DEFAULT 0,
            otp_sent_at  INTEGER NOT NULL DEFAULT 0
        )");
        $db = $pdo;
        return $db;
    } catch (\Throwable $e) {
        $db = false;
        return null;
    }
}

function fpThrottleRow(PDO $db, string $ip): ?array {
    try {
        $s = $db->prepare('SELECT * FROM login_throttle WHERE ip = ?');
        $s->execute([$ip]);
        $row = $s->fetch();
        return $row ?: null;
    } catch (\PDOException $e) {
        return null;
    }
}

// Where does this IP stand right now?
//   ['mode' => 'open',  ...]            attempt allowed, nothing special
//   ['mode' => 'wait',  'wait' => sec]  throttled — reject without verifying
//   ['mode' => 'otp',   'note' => msg]  soft-locked — password AND emailed
//                                       code are both required
// $allowSend: true only when rendering the login wall — that's the moment a
// missing code may be (re)issued and emailed. POST checks pass false so a
// brute-force loop can't drive email sends.
function fpLoginGate(string $ip, bool $allowSend): array {
    $open = ['mode' => 'open', 'wait' => 0, 'note' => ''];
    $db = fpThrottleDB();
    if (!$db) return $open;
    $row = fpThrottleRow($db, $ip);
    if (!$row) return $open;
    $now   = time();
    $fails = (int)$row['fails'];

    if ($fails > 0 && $now - (int)$row['last_fail_at'] > FP_THROTTLE_DECAY) {
        try { $db->prepare('DELETE FROM login_throttle WHERE ip = ?')->execute([$ip]); }
        catch (\PDOException $e) {}
        return $open;
    }
    if ($fails < 3) return $open;

    if ($fails < FP_THROTTLE_SOFTLOCK_AT) {           // flagged: brief pauses
        $left = (int)$row['last_fail_at'] + ($fails === 3 ? 10 : 30) - $now;
        return $left > 0 ? ['mode' => 'wait', 'wait' => $left, 'note' => ''] : $open;
    }

    // Soft-lock stage.
    $codeLive = static function (array $r) use ($now): bool {
        return $r['otp_hash'] !== '' && (int)$r['otp_expires'] > $now
            && (int)$r['otp_tries'] < FP_OTP_MAX_TRIES;
    };
    $note = '';
    if (!$codeLive($row) && $allowSend) {
        $note = fpLoginOtpSend($db, $ip, $row);
        $row  = fpThrottleRow($db, $ip) ?: $row;
    }
    if ($codeLive($row)) {
        if ($note === '') {
            $note = 'Enter the 6-digit security code that was emailed to the administrator, along with the password.';
        }
        return ['mode' => 'otp', 'wait' => 0, 'note' => $note];
    }

    // No live code — the capped escalating timeout is the safety net.
    $wait = min(FP_THROTTLE_MAX_WAIT,
                60 * (2 ** min(10, $fails - FP_THROTTLE_SOFTLOCK_AT)));
    $left = (int)$row['last_fail_at'] + $wait - $now;
    if ($left > 0) {
        return ['mode' => 'wait', 'wait' => $left, 'note' => $note];
    }
    return $open;
}

// Email a fresh single-use code to the administrator, honoring the resend
// pacing. Returns a user-facing note ('' means "nothing to say" — e.g. no
// admin email is configured, so the timeout fallback governs silently).
function fpLoginOtpSend(PDO $db, string $ip, array $row): string {
    $now = time();
    $adminEmail = trim((string)(setting('admin_email', '') ?? ''));
    if ($adminEmail === '') return '';
    if ($now - (int)$row['otp_sent_at'] < FP_OTP_RESEND_IP) {
        return 'A security code was emailed to the administrator recently — wait a couple of minutes, then reload this page for a new one.';
    }
    $g = fpThrottleRow($db, '@');
    if ($g && $now - (int)$g['otp_sent_at'] < FP_OTP_RESEND_GLOBAL) {
        return 'Please wait a minute, then reload this page to receive a security code.';
    }

    require_once __DIR__ . '/mailer.php';
    try { $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT); }
    catch (\Throwable $e) { return ''; }
    $res = op_send_mail(
        $adminEmail,
        'OpenPantry admin login security code',
        "Five or more failed admin login attempts were made from IP {$ip}.\n\n"
        . "To finish logging in, enter this single-use security code on the login page\n"
        . "along with the admin password:\n\n"
        . "    {$code}\n\n"
        . "The code expires in " . (int)(FP_OTP_TTL / 60) . " minutes and dies after "
        . FP_OTP_MAX_TRIES . " wrong entries.\n\n"
        . "If nobody on your team is trying to log in, an attacker may be guessing the\n"
        . "password — do not share this code, and consider changing the admin password\n"
        . "in Settings."
    );
    if (empty($res['ok'])) {
        error_log('OpenPantry: could not email login security code: '
                  . (string)($res['error'] ?? 'unknown error'));
        return 'The security-code email could not be sent. Please try again later.';
    }
    try {
        $db->prepare('UPDATE login_throttle
                         SET otp_hash = ?, otp_expires = ?, otp_tries = 0, otp_sent_at = ?
                       WHERE ip = ?')
           ->execute([password_hash($code, PASSWORD_DEFAULT), $now + FP_OTP_TTL, $now, $ip]);
        $db->prepare("INSERT INTO login_throttle (ip, otp_sent_at) VALUES ('@', ?)
                      ON CONFLICT(ip) DO UPDATE SET otp_sent_at = excluded.otp_sent_at")
           ->execute([$now]);
    } catch (\PDOException $e) {
        return '';
    }
    return 'A 6-digit security code has been emailed to the administrator. Enter it below with the password.';
}

// Check a submitted code against the live one for this IP. A wrong non-empty
// code burns one of the FP_OTP_MAX_TRIES entries; an empty field doesn't
// (the overall failed attempt still counts via fpLoginRecordFailure).
function fpLoginOtpCheck(string $ip, string $code): bool {
    $db = fpThrottleDB();
    if (!$db) return true;             // throttle unavailable → gate was 'open' anyway
    $row = fpThrottleRow($db, $ip);
    $now = time();
    if (!$row || $row['otp_hash'] === '' || (int)$row['otp_expires'] <= $now
        || (int)$row['otp_tries'] >= FP_OTP_MAX_TRIES) {
        return false;
    }
    if ($code === '') return false;
    if (password_verify($code, $row['otp_hash'])) return true;
    try {
        $db->prepare('UPDATE login_throttle SET otp_tries = otp_tries + 1 WHERE ip = ?')
           ->execute([$ip]);
    } catch (\PDOException $e) {}
    return false;
}

function fpLoginRecordFailure(string $ip): void {
    $db = fpThrottleDB();
    if (!$db) return;
    $now = time();
    try {
        // Housekeeping: drop day-old records (keep the '@' send-pacing row).
        $db->prepare("DELETE FROM login_throttle
                       WHERE ip <> '@' AND last_fail_at > 0 AND last_fail_at < ?")
           ->execute([$now - 86400]);
        $row   = fpThrottleRow($db, $ip);
        $fails = ($row && $now - (int)$row['last_fail_at'] <= FP_THROTTLE_DECAY)
               ? (int)$row['fails'] : 0;
        $db->prepare('INSERT INTO login_throttle (ip, fails, last_fail_at) VALUES (?, ?, ?)
                      ON CONFLICT(ip) DO UPDATE
                        SET fails = excluded.fails, last_fail_at = excluded.last_fail_at')
           ->execute([$ip, $fails + 1, $now]);
    } catch (\PDOException $e) {}
}

function fpLoginRecordSuccess(string $ip): void {
    $db = fpThrottleDB();
    if (!$db) return;
    try { $db->prepare('DELETE FROM login_throttle WHERE ip = ?')->execute([$ip]); }
    catch (\PDOException $e) {}
}

// Human wording for a wait-mode gate.
function fpThrottleWaitText(int $sec): string {
    if ($sec >= 90) {
        return 'Please wait about ' . (int)ceil($sec / 60) . ' minutes and try again.';
    }
    return 'Please wait ' . max(1, $sec) . ' seconds and try again.';
}
