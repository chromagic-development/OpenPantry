<?php
// Shared auth with the PantryPrep admin panel.
//
//   requireLogin()     — shows a login wall; reads admin_password from
//                        openpantry.db's settings table (via setting() in
//                        db.php). Cookie name and token format match
//                        PantryPrep so a single login works across both apps.
//   requireAllowedIP() — 403s if REMOTE_ADDR doesn't match `allowed_ip`
//                        in the same settings store. Empty value = no
//                        restriction (lets you set it up first).

require_once __DIR__ . '/crypto.php';    // fpVerifyAdminPassword, fsScheduleAllowsNow
require_once __DIR__ . '/ratelimit.php'; // progressive login throttle + soft-lock

if (!defined('AUTH_COOKIE')) define('AUTH_COOKIE', 'fp_admin_auth');
const FP_COOKIE_TTL = 5184000; // 60 days, matches admin/admin.php

function fpMakeAuthToken(string $password): string {
    return hash('sha256', 'fp_admin_' . $password);
}

function fpIsAuthenticated(string $password): bool {
    $cookie = $_COOKIE[AUTH_COOKIE] ?? '';
    return $cookie !== '' && hash_equals(fpMakeAuthToken($password), $cookie);
}

function fpSetAuthCookie(string $password, int $duration): void {
    $token = fpMakeAuthToken($password);
    $expires = gmdate('D, d M Y H:i:s T', time() + $duration);
    header('Set-Cookie: ' . AUTH_COOKIE . '=' . $token
        . '; Expires=' . $expires . '; Max-Age=' . $duration
        . '; Path=/; HttpOnly; SameSite=Lax', false);
    $_COOKIE[AUTH_COOKIE] = $token;
}

function fpClearAuthCookie(): void {
    header('Set-Cookie: ' . AUTH_COOKIE . '='
        . '; Expires=Thu, 01 Jan 1970 00:00:00 GMT; Max-Age=0'
        . '; Path=/; HttpOnly; SameSite=Lax', false);
    unset($_COOKIE[AUTH_COOKIE]);
}

function requireLogin(): void {
    $password = setting('admin_password', 'admin') ?? 'admin';

    if (isset($_GET['logout'])) {
        fpClearAuthCookie();
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $loginError = null;
    if (($_POST['fp_login_action'] ?? '') === 'login') {
        // Progressive throttle (ratelimit.php): flagged IPs wait; soft-locked
        // IPs must also present the security code emailed to the admin.
        $gate = fpLoginGate($ip, false);
        if ($gate['mode'] === 'wait') {
            $loginError = 'Too many failed attempts. ' . fpThrottleWaitText($gate['wait']);
        } else {
            $passOk = fpVerifyAdminPassword($_POST['fp_login_password'] ?? '', $password);
            $otpOk  = ($gate['mode'] !== 'otp')
                   || fpLoginOtpCheck($ip, trim((string)($_POST['fp_login_otp'] ?? '')));
            if ($passOk && $otpOk) {
                fpLoginRecordSuccess($ip);
                // Cookie token is derived from the stored value ($password =
                // the hash), so it persists across requests and
                // auto-invalidates when the password (hash) changes.
                fpSetAuthCookie($password, FP_COOKIE_TTL);
                header('Location: ' . $_SERVER['REQUEST_URI']);
                exit;
            }
            fpLoginRecordFailure($ip);
            $loginError = ($gate['mode'] === 'otp')
                ? 'Incorrect password or security code.'
                : 'Incorrect password.';
        }
    }

    if (fpIsAuthenticated($password)) {
        fpSetAuthCookie($password, FP_COOKIE_TTL); // sliding renewal
        return;
    }
    // Rendering the wall is the one moment a soft-locked IP may trigger the
    // (paced) security-code email — see fpLoginGate's $allowSend.
    renderLoginWall($loginError, fpLoginGate($ip, true));
    exit;
}

function requireLoginAPI(): void {
    // JSON variant for AJAX endpoints — returns 401 instead of redirect.
    $password = setting('admin_password', 'admin') ?? 'admin';
    if (!fpIsAuthenticated($password)) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Login required']);
        exit;
    }
}

// Normalize an "HH:MM" 24-hour time string. Returns '' when invalid so
// callers can treat a missing/garbled time as "no window".
function fpNormalizeTime(string $v): string {
    $v = trim($v);
    if (preg_match('/^(\d{1,2}):(\d{2})$/', $v, $m)) {
        $h = (int)$m[1]; $min = (int)$m[2];
        if ($h >= 0 && $h <= 23 && $min >= 0 && $min <= 59) {
            return sprintf('%02d:%02d', $h, $min);
        }
    }
    return '';
}

// Read the weekly access schedule (the "Allowed Hours" controls in the
// Secure Network Access settings card) into a normalized structure:
//   ['enabled' => 0|1, 'days' => [0..6 => ['on'=>0|1,'start'=>'HH:MM','end'=>'HH:MM']]]
// Day index follows date('w'): 0 = Sunday … 6 = Saturday. Stored as a JSON
// blob in the `access_schedule` setting. Unconfigured installs return a
// disabled schedule, so the time gate is a no-op until an admin turns it on.
function fpAccessSchedule(): array {
    $raw  = setting('access_schedule', '');
    $data = json_decode((string)$raw, true);
    $out  = ['enabled' => 0, 'days' => []];
    $isArr = is_array($data);
    $out['enabled'] = ($isArr && !empty($data['enabled'])) ? 1 : 0;
    $days = ($isArr && isset($data['days']) && is_array($data['days'])) ? $data['days'] : [];
    for ($w = 0; $w <= 6; $w++) {
        $d = $days[(string)$w] ?? $days[$w] ?? null;
        if (is_array($d)) {
            $out['days'][$w] = [
                'on'    => !empty($d['on']) ? 1 : 0,
                'start' => fpNormalizeTime((string)($d['start'] ?? '')),
                'end'   => fpNormalizeTime((string)($d['end'] ?? '')),
            ];
        } else {
            // Sensible first-run default: all-day allowed, so flipping the
            // master toggle on without editing doesn't lock anyone out.
            $out['days'][$w] = ['on' => 1, 'start' => '00:00', 'end' => '23:59'];
        }
    }
    return $out;
}

// True when the current time falls inside today's allowed window, OR when the
// schedule is disabled. Delegates to the shared evaluator (crypto.php) so the
// foodscan gate and the menucounter kiosk pages block identically. Server time
// uses the app's America/New_York default set in common.php.
function fpAccessTimeAllowed(): bool {
    return fsScheduleAllowsNow(setting('access_schedule', ''));
}

// Render the full-page "Access Denied" wall with a reason line.
function fpRenderAccessDenied(string $reason): void {
    http_response_code(403);
    ?><!DOCTYPE html><html lang="en"><head>
    <meta charset="UTF-8"><title>Access Denied</title>
    <style>
      body{font-family:Arial,sans-serif;background:#F5F0E8;color:#333;
           display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;}
      .box{background:#fff;border:1px solid #D4C9A8;border-radius:10px;
           padding:32px 40px;max-width:440px;text-align:center;
           box-shadow:0 2px 10px rgba(0,0,0,.07);}
      h2{color:#8B1A1A;font-size:1.1rem;margin:0 0 8px;}
      p{color:#555;font-size:.9rem;margin:0;}
      footer.host-credit{position:fixed;bottom:0;left:0;right:0;text-align:center;
           padding:14px 16px;font-size:.75rem;color:#999;}
      footer.host-credit a{color:#6B4C11;text-decoration:none;font-weight:600;}
    </style></head><body>
    <div class="box">
      <h2>🔒 Access Denied</h2>
      <p><?= htmlspecialchars($reason) ?></p>
    </div>
    <footer class="host-credit">
      Hosting by <a href="https://interserver.net" target="_blank" rel="noopener">InterServer</a>
    </footer></body></html><?php
    exit;
}

function requireAllowedIP(): void {
    $allowed = trim(setting('allowed_ip', '') ?? '');
    $ipOk    = ($allowed === '') || (($_SERVER['REMOTE_ADDR'] ?? '') === $allowed);
    $timeOk  = fpAccessTimeAllowed();
    if ($ipOk && $timeOk) return;
    // Network mismatch is the more fundamental block, so report it first.
    // Network denials show just the styled "🔒 Access Denied" heading (no
    // subtext); the hours gate still explains when to come back.
    $reason = !$ipOk
        ? ''
        : 'Access is closed right now. Please try again during the permitted hours.';
    fpRenderAccessDenied($reason);
}

function requireAllowedIPAPI(): void {
    $allowed = trim(setting('allowed_ip', '') ?? '');
    $ipOk    = ($allowed === '') || (($_SERVER['REMOTE_ADDR'] ?? '') === $allowed);
    $timeOk  = fpAccessTimeAllowed();
    if ($ipOk && $timeOk) return;
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode([
        'ok'    => false,
        'error' => !$ipOk ? 'Network not allowed' : 'Outside permitted access hours',
    ]);
    exit;
}

function renderLoginWall(?string $error, array $gate = ['mode' => 'open', 'wait' => 0, 'note' => '']): void {
    $p = $GLOBALS['FS_PREFIX'] ?? '';
    $notice = $gate['note'];
    if ($gate['mode'] === 'wait' && $notice === '') {
        $notice = 'Too many failed attempts. ' . fpThrottleWaitText($gate['wait']);
    }
    ?><!DOCTYPE html><html lang="en"><head>
    <link rel="icon" type="image/x-icon" href="<?= $p ?>menucounter/favicon.ico">
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OpenPantry – Admin Login</title>
    <style>
      :root{--brown:#6B4C11;--green:#8BAF3A;--light:#F5F0E8;--border:#D4C9A8;}
      *{box-sizing:border-box;margin:0;padding:0;}
      body{font-family:Arial,sans-serif;background:var(--light);
           display:flex;align-items:center;justify-content:center;min-height:100vh;}
      .login-card{background:#fff;border:1px solid var(--border);border-radius:12px;
                  padding:36px 40px;width:100%;max-width:360px;
                  box-shadow:0 4px 16px rgba(0,0,0,.1);}
      .login-card h1{font-size:1.1rem;color:var(--brown);margin-bottom:6px;}
      .login-card p{font-size:.82rem;color:#888;margin-bottom:24px;}
      label{display:block;font-size:.78rem;font-weight:700;text-transform:uppercase;
            letter-spacing:.4px;color:var(--brown);margin-bottom:6px;}
      input[type="password"]{width:100%;border:1px solid var(--border);border-radius:6px;
                             padding:9px 12px;font-size:.95rem;margin-bottom:16px;background:#fafaf5;}
      input[type="password"]:focus{outline:none;border-color:var(--green);}
      .btn-login{width:100%;background:var(--brown);color:#fff;border:none;border-radius:7px;
                 padding:11px;font-size:1rem;font-weight:700;cursor:pointer;}
      .btn-login:hover{background:#8B6420;}
      input[type="text"]{width:100%;border:1px solid var(--border);border-radius:6px;
                         padding:9px 12px;font-size:.95rem;margin-bottom:16px;background:#fafaf5;}
      input[type="text"]:focus{outline:none;border-color:var(--green);}
      .error{background:#F8D7DA;border:1px solid #F1AEB5;color:#8B1A1A;border-radius:6px;
             padding:10px 14px;font-size:.85rem;margin-bottom:16px;}
      .notice{background:#FFF3CD;border:1px solid #E6D9A8;color:#6B5B11;border-radius:6px;
              padding:10px 14px;font-size:.85rem;margin-bottom:16px;}
      footer.host-credit{position:fixed;bottom:0;left:0;right:0;text-align:center;
             padding:14px 16px;font-size:.75rem;color:#999;}
      footer.host-credit a{color:var(--brown);text-decoration:none;font-weight:600;}
    </style></head><body>
    <div class="login-card">
      <h1>⚙ OpenPantry Admin</h1>
      <p>Enter the administrator password to continue.</p>
      <?php if ($error): ?>
        <div class="error">⚠ <?= htmlspecialchars($error) ?></div>
      <?php endif; ?>
      <?php if ($notice !== ''): ?>
        <div class="notice">🔐 <?= htmlspecialchars($notice) ?></div>
      <?php endif; ?>
      <form method="POST">
        <input type="hidden" name="fp_login_action" value="login">
        <label for="fp_login_password">Password</label>
        <input type="password" id="fp_login_password" name="fp_login_password" autofocus placeholder="Enter password">
        <?php if ($gate['mode'] === 'otp'): ?>
          <label for="fp_login_otp">Security Code</label>
          <input type="text" id="fp_login_otp" name="fp_login_otp" inputmode="numeric"
                 autocomplete="one-time-code" maxlength="6" placeholder="6-digit emailed code">
        <?php endif; ?>
        <button type="submit" class="btn-login">Log In</button>
      </form>
    </div>
    <footer class="host-credit">
      Hosting by <a href="https://interserver.net" target="_blank" rel="noopener">InterServer</a>
    </footer></body></html><?php
}
