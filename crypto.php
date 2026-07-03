<?php
// Field-level encryption for sensitive columns (libsodium / sodium_crypto_secretbox).
//
// Encrypts at rest:
//   settings:         openai_api_key, allowed_ip
//   delivery_clients: address, city, phone
//
// admin_password is NOT encrypted — it is one-way HASHED (password_hash) so
// even the running app can't recover the plaintext. See fpHashAdminPassword()
// / fpVerifyAdminPassword() below. Hashing works on any PHP 5.5+, so the
// password is protected even where libsodium (PHP 7.2+) isn't available.
//
// Storage format:  sb1:<base64(nonce . ciphertext)>
//   The "sb1:" marker lets encrypted and legacy-plaintext values coexist
//   during migration — fsDecrypt() returns unmarked (plaintext) values
//   untouched. Empty strings are never encrypted (stay '').
//
// Requirements:
//   The PHP `sodium` extension. It is BUILT IN on PHP 7.2+. On older PHP
//   (e.g. 7.1) it is absent unless separately installed. When sodium is
//   unavailable these helpers degrade to a no-op so the app keeps running —
//   but values are then stored in CLEAR TEXT. fsCryptoAvailable() lets the
//   UI surface that state, and the encrypt migration is deferred until a
//   sodium-capable PHP runs.
//
// Key management:
//   A 32-byte key lives in encryption_key.php, generated on first use. By
//   default it sits beside this file (inside the web root). *** LOSING THAT
//   FILE MAKES ALL ENCRYPTED DATA PERMANENTLY UNRECOVERABLE. *** Back it up,
//   keep it out of version control.
//
// Relocating the key (recommended) — move it ABOVE the web root so it can
//   never be served over HTTP or read by other tenants. Point the app at the
//   new location with EITHER:
//     * an environment variable:  OPENPANTRY_KEY_PATH=/home/you/secret/encryption_key.php
//     * or define a constant before this file loads:  define('FS_ENC_KEY_PATH', '...');
//   The move is fail-safe: if the configured file doesn't exist yet but the
//   old in-web-root key does, fsEncKey() copies the existing key to the new
//   path (never generates a fresh one), so a half-finished migration can't
//   orphan your encrypted data. After confirming the app still decrypts,
//   delete the old web-root copy. See README for step-by-step.

const FS_ENC_MARKER         = 'sb1:';
const FS_ENCRYPTED_SETTINGS = ['openai_api_key', 'allowed_ip', 'smtp_pass'];

function fsCryptoAvailable(): bool {
    return function_exists('sodium_crypto_secretbox')
        && function_exists('sodium_crypto_secretbox_open')
        && defined('SODIUM_CRYPTO_SECRETBOX_KEYBYTES');
}

// Resolve the key file location. Priority: OPENPANTRY_KEY_PATH env var, then a
// FS_ENC_KEY_PATH constant, else the legacy in-web-root path beside this file.
function fsEncKeyFile(): string {
    // getenv() catches classic mod_php; $_SERVER catches PHP-FPM/CGI, where an
    // Apache `SetEnv` lands in $_SERVER rather than the process environment.
    $env = getenv('OPENPANTRY_KEY_PATH');
    if (!is_string($env) || $env === '') $env = $_SERVER['OPENPANTRY_KEY_PATH'] ?? '';
    if (is_string($env) && $env !== '') return $env;
    if (defined('FS_ENC_KEY_PATH') && FS_ENC_KEY_PATH !== '') return FS_ENC_KEY_PATH;
    return __DIR__ . '/encryption_key.php';
}

// Load (or first-time generate) the raw 32-byte secret key. Returns '' if it
// cannot be obtained, so callers fall back to no-op behavior rather than
// storing data under a bad key.
function fsEncKey(): string {
    static $key = null;
    if ($key !== null) return $key;

    $file   = fsEncKeyFile();
    $legacy = __DIR__ . '/encryption_key.php';

    // Migration safety: if the configured key file is missing but the legacy
    // in-web-root key exists, the admin has set a new path without moving the
    // file yet. Copy the existing key to the new location rather than
    // generating a new one (which would orphan all existing ciphertext). If
    // the copy can't happen (e.g. target dir absent), keep using the legacy
    // file so the app stays functional and nothing is lost.
    if (!is_file($file) && $file !== $legacy && is_file($legacy)) {
        if (@copy($legacy, $file)) { @chmod($file, 0600); }
        else { $file = $legacy; }
    }

    if (!is_file($file)) {
        try { $raw = random_bytes(32); } catch (\Throwable $e) { $key = ''; return $key; }
        $content = "<?php\n"
                 . "// AUTO-GENERATED ENCRYPTION KEY — do NOT edit, move, or delete.\n"
                 . "// Losing this file makes all encrypted settings and client\n"
                 . "// fields permanently unrecoverable. Keep a secure backup.\n"
                 . "return '" . base64_encode($raw) . "';\n";
        // 'x' = create exclusively. If a concurrent first request already made
        // the file, fopen fails and we fall through to read the existing one,
        // avoiding two different keys.
        $fp = @fopen($file, 'x');
        if ($fp !== false) {
            fwrite($fp, $content);
            fclose($fp);
            @chmod($file, 0600);
        }
    }

    $b64 = @include $file;            // the key file returns its base64 string
    $key = is_string($b64) ? (base64_decode($b64, true) ?: '') : '';
    return $key;
}

function fsIsEncrypted(string $v): bool {
    return strncmp($v, FS_ENC_MARKER, strlen(FS_ENC_MARKER)) === 0;
}

function fsEncrypt(string $plain): string {
    if ($plain === '') return '';
    if (!fsCryptoAvailable()) return $plain;        // no-op: no sodium → clear text
    $key = fsEncKey();
    if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) return $plain;
    $nonce  = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = sodium_crypto_secretbox($plain, $nonce, $key);
    return FS_ENC_MARKER . base64_encode($nonce . $cipher);
}

function fsDecrypt(string $stored): string {
    if ($stored === '' || !fsIsEncrypted($stored)) return $stored; // legacy plaintext
    if (!fsCryptoAvailable()) return '';
    $key = fsEncKey();
    if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) return '';
    $raw = base64_decode(substr($stored, strlen(FS_ENC_MARKER)), true);
    $min = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES;
    if ($raw === false || strlen($raw) < $min) return '';
    $nonce  = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plain  = sodium_crypto_secretbox_open($cipher, $nonce, $key);
    return ($plain === false) ? '' : $plain;
}

// Encrypt only if not empty and not already encrypted (idempotent — safe to
// call on values that may already be ciphertext).
function fsMaybeEncrypt(string $v): string {
    if ($v === '' || fsIsEncrypted($v)) return $v;
    return fsEncrypt($v);
}

// Decrypt the encrypted columns of a delivery_clients row. Only keys that are
// actually present in the row are touched, so it's safe for partial SELECTs
// (e.g. print_menus.php selects only `phone`).
function fsDecryptClientFields(array $row): array {
    foreach (['address', 'city', 'phone'] as $f) {
        if (isset($row[$f])) $row[$f] = fsDecrypt((string)$row[$f]);
    }
    return $row;
}

// ── Allowed-hours access schedule ───────────────────────────────────────────
// Pure evaluation of the `access_schedule` setting (a JSON blob) against the
// current time. Shared by foodscan/auth.php (requireAllowedIP) and the
// menucounter kiosk pages so both block identically. The caller passes the
// raw JSON string. Uses the ambient default timezone, which both bootstraps
// pin to America/New_York (common.php and menucounter/db.php), so the gate
// reads the same wall-clock time everywhere.
//
// Returns true when access is allowed: the schedule is disabled, or "now" is
// inside today's start–end window (an end earlier than start = overnight,
// wraps past midnight). Malformed/closed days fail closed (blocked).
function fsScheduleAllowsNow($rawJson): bool {
    $data = json_decode((string)$rawJson, true);
    if (!is_array($data) || empty($data['enabled'])) return true; // gate off → allow
    $days = (isset($data['days']) && is_array($data['days'])) ? $data['days'] : [];
    $w = (int)date('w'); // 0 = Sunday … 6 = Saturday
    $d = $days[(string)$w] ?? ($days[$w] ?? null);
    if (!is_array($d) || empty($d['on'])) return false;          // day closed
    $start = fsNormalizeHm((string)($d['start'] ?? ''));
    $end   = fsNormalizeHm((string)($d['end'] ?? ''));
    if ($start === '' || $end === '') return false;
    $cur = date('H:i');
    if ($start <= $end) {
        return ($cur >= $start && $cur <= $end);                 // same-day window
    }
    return ($cur >= $start || $cur <= $end);                     // overnight window
}

function fsNormalizeHm(string $v): string {
    $v = trim($v);
    if (preg_match('/^(\d{1,2}):(\d{2})$/', $v, $m)) {
        $h = (int)$m[1]; $min = (int)$m[2];
        if ($h >= 0 && $h <= 23 && $min >= 0 && $min <= 59) {
            return sprintf('%02d:%02d', $h, $min);
        }
    }
    return '';
}

// ── Admin password hashing ──────────────────────────────────────────────────
// admin_password is stored as a one-way password_hash (bcrypt/argon2) — never
// recoverable. Shared by foodscan/auth.php and the menucounter admin login so
// both verify identically. The session-cookie token is still derived from
// whatever admin_password holds (now the hash), so it keeps auto-invalidating
// when the password changes.

function fpHashAdminPassword(string $plain): string {
    return password_hash($plain, PASSWORD_DEFAULT);
}

// Verify a submitted password against the stored admin_password value, handling
// every state it can be in:
//   * a modern password_hash  → password_verify (the normal case)
//   * legacy plaintext / the 'admin' default → constant-time compare
//   * a value left encrypted by the earlier feature → decrypt then compare
// The hashing migration converts plaintext/encrypted values to a hash on the
// next foodscan page load, so the latter two are transitional fallbacks.
function fpVerifyAdminPassword(string $submitted, string $stored): bool {
    if ($stored === '') {
        return hash_equals('admin', $submitted); // unset → default password
    }
    $info = password_get_info($stored);
    if (!empty($info['algo'])) {
        return password_verify($submitted, $stored);
    }
    if (fsIsEncrypted($stored)) {
        $plain = fsDecrypt($stored);
        return $plain !== '' && hash_equals($plain, $submitted);
    }
    return hash_equals($stored, $submitted); // legacy plaintext
}
