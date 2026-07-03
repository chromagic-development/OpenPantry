<?php
// App-level settings: OpenAI key, default lead time, safety Z, velocity window.
$GLOBALS['FS_PREFIX'] = '../';
require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../event/event_types.php'; // event-type helpers
require_once __DIR__ . '/../delivery/db.php';       // delivery group/city helpers
require_once __DIR__ . '/../reports/order_report/report_lib.php'; // OP_ALERT_EMAIL_MIN_HOURS
require_once __DIR__ . '/../mailer.php';                          // op_split_recipients()
requireLogin();
$db = getDB();

$msg = null;
$sysSaved = null;
$sysError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['fs_action'] ?? '';
    if ($action === 'save_pantry_info') {
        // Food Pantry Name + optional logo upload. The name is saved
        // regardless; the logo is only replaced when a file is supplied.
        $name = trim((string)($_POST['food_pantry_name'] ?? ''));
        if (strlen($name) > 120) $name = substr($name, 0, 120);
        setSetting('food_pantry_name', $name);

        $logoMsg = '';
        if (!empty($_FILES['logo']['name'])) {
            $f = $_FILES['logo'];
            if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $sysError = 'Logo upload failed (code ' . (int)($f['error'] ?? -1) . ').';
            } elseif (!is_uploaded_file($f['tmp_name'])) {
                $sysError = 'Logo upload rejected.';
            } elseif (($f['size'] ?? 0) > 5 * 1024 * 1024) {
                $sysError = 'Logo is larger than 5 MB.';
            } else {
                // Confirm it's actually an image before overwriting logo.jpg.
                $info = @getimagesize($f['tmp_name']);
                if ($info === false) {
                    $sysError = 'That file is not a recognizable image.';
                } elseif (!move_uploaded_file($f['tmp_name'], __DIR__ . '/../logo.jpg')) {
                    $sysError = 'Could not save the new logo (check folder permissions).';
                } else {
                    $logoMsg = ' New logo uploaded.';
                }
            }
        }
        if ($sysError === null) {
            $sysSaved = 'Food pantry information saved.' . $logoMsg;
        }
    } elseif ($action === 'save_ip') {
        $ip = trim($_POST['allowed_ip'] ?? '');
        setSetting('allowed_ip', $ip);
        $sysSaved = 'IP address updated.';
    } elseif ($action === 'save_access_schedule') {
        // Weekly allowed-hours window. Persisted as one JSON blob; the gate
        // helpers in auth.php (fpAccessSchedule / fpAccessTimeAllowed) read
        // it back. Times are normalized to HH:MM; an unparseable value
        // becomes '' which the gate treats as "no window that day".
        $enabled  = isset($_POST['schedule_enabled']) ? 1 : 0;
        $startArr = $_POST['day_start'] ?? [];
        $endArr   = $_POST['day_end']   ?? [];
        $onArr    = $_POST['day_on']    ?? [];
        $days = [];
        for ($w = 0; $w <= 6; $w++) {
            $days[(string)$w] = [
                'on'    => isset($onArr[$w]) ? 1 : 0,
                'start' => fpNormalizeTime((string)($startArr[$w] ?? '')),
                'end'   => fpNormalizeTime((string)($endArr[$w] ?? '')),
            ];
        }
        setSetting('access_schedule', json_encode(['enabled' => $enabled, 'days' => $days]));
        $sysSaved = 'Allowed-hours schedule updated.';
    } elseif ($action === 'save_admin_password') {
        // The email always saves. The password only changes when both fields
        // are filled (leave them blank to update just the email). The email
        // field accepts several addresses separated by commas or semicolons;
        // reminders go to all of them. Stored normalized as a comma list.
        $email = trim($_POST['admin_email'] ?? '');
        $parts = op_split_recipients($email);
        if ($email !== '' && (!empty($parts['invalid']) || empty($parts['valid']))) {
            $bad = !empty($parts['invalid']) ? $parts['invalid'] : [$email];
            $sysError = 'These email addresses are not valid: ' . implode(', ', $bad);
        } else {
            setSetting('admin_email', implode(', ', $parts['valid']));
            $newPw     = trim($_POST['new_password']     ?? '');
            $confirmPw = trim($_POST['confirm_password'] ?? '');
            if ($newPw === '' && $confirmPw === '') {
                $sysSaved = 'Administrator email saved.';
            } elseif ($newPw !== $confirmPw) {
                $sysError = 'Passwords do not match.';
            } else {
                // Store a one-way hash, never the plaintext. The session cookie
                // token derives from the stored value (the hash), so set it from
                // the hash to keep the current session valid after rotation.
                $pwHash = fpHashAdminPassword($newPw);
                setSetting('admin_password', $pwHash);
                fpSetAuthCookie($pwHash, FP_COOKIE_TTL);
                $sysSaved = 'Administrator email address and password updated.';
            }
        }
    } elseif ($action === 'save_smtp') {
        // Outbound-email transport for reorder reminders. Leaving SMTP Host
        // blank makes op_send_mail() fall back to PHP mail(). The recipient
        // address lives in the Administrator Email Address & Password card (admin_email)
        // and is intentionally NOT touched here.
        setSetting('smtp_host',      trim($_POST['smtp_host'] ?? ''));
        setSetting('smtp_port',      trim($_POST['smtp_port'] ?? ''));
        $sec = $_POST['smtp_security'] ?? 'tls';
        setSetting('smtp_security',  in_array($sec, ['', 'ssl', 'tls'], true) ? $sec : 'tls');
        setSetting('smtp_user',      trim($_POST['smtp_user'] ?? ''));
        // Only overwrite the stored password when a new one is typed, so
        // re-saving the form doesn't wipe it (the field renders blank).
        $pw = (string)($_POST['smtp_pass'] ?? '');
        if ($pw !== '') setSetting('smtp_pass', $pw);
        setSetting('smtp_from',      trim($_POST['smtp_from'] ?? ''));
        setSetting('smtp_from_name', trim($_POST['smtp_from_name'] ?? ''));
        setSetting('smtp_insecure',  isset($_POST['smtp_insecure']) ? '1' : '0');
        $sysSaved = 'Email notification settings saved.';
    } elseif ($action === 'add_event_type') {
        $t = trim((string)($_POST['event_name'] ?? ''));
        if ($t !== '' && mb_strlen($t) > 40) $t = mb_substr($t, 0, 40);
        if ($t === '') {
            $sysError = 'Enter an event name.';
        } elseif (in_array($t, eventTypes(), true)) {
            $sysError = 'That event already exists.';
        } else {
            $types = eventTypes(); $types[] = $t; setEventTypes($types);
            $sysSaved = 'Event added.';
        }
    } elseif ($action === 'remove_event_type') {
        $t = (string)($_POST['event_name'] ?? '');
        $types = array_values(array_filter(eventTypes(), function ($x) use ($t) { return $x !== $t; }));
        if ($types === []) { $sysError = 'At least one event is required.'; }
        else { setEventTypes($types); $sysSaved = 'Event removed.'; }
    } elseif ($action === 'add_group') {
        $g = trim((string)($_POST['group_name'] ?? ''));
        if ($g !== '' && mb_strlen($g) > 20) $g = mb_substr($g, 0, 20);
        if ($g === '') {
            $sysError = 'Enter a group name.';
        } elseif (in_array($g, deliveryGroups(), true)) {
            $sysError = 'That group already exists.';
        } else {
            $groups = deliveryGroups(); $groups[] = $g; setDeliveryGroups($groups);
            $sysSaved = 'Group added.';
        }
    } elseif ($action === 'remove_group') {
        $g = (string)($_POST['group_name'] ?? '');
        $groups = array_values(array_filter(deliveryGroups(), function ($x) use ($g) { return $x !== $g; }));
        if ($groups === []) {
            $sysError = 'At least one group is required.';
        } else {
            setDeliveryGroups($groups);
            $map = deliveryGroupCities();
            if (isset($map[$g])) { unset($map[$g]); setDeliveryGroupCities($map); }
            $sysSaved = 'Group removed. Existing clients keep their group.';
        }
    } elseif ($action === 'add_city') {
        $c = trim((string)($_POST['city_name'] ?? ''));
        if ($c !== '' && mb_strlen($c) > 40) $c = mb_substr($c, 0, 40);
        if ($c === '') {
            $sysError = 'Enter a city name.';
        } elseif (in_array($c, deliveryCities(), true)) {
            $sysError = 'That city already exists.';
        } else {
            $cs = deliveryCities(); $cs[] = $c; setDeliveryCities($cs);
            $sysSaved = 'City added.';
        }
    } elseif ($action === 'remove_city') {
        $c = (string)($_POST['city_name'] ?? '');
        $cs = array_values(array_filter(deliveryCities(), function ($x) use ($c) { return $x !== $c; }));
        if ($cs === []) {
            $sysError = 'At least one city is required.';
        } else {
            setDeliveryCities($cs);
            $map = deliveryGroupCities();
            $changed = false;
            foreach ($map as $g => $list) {
                $new = array_values(array_filter($list, function ($x) use ($c) { return $x !== $c; }));
                if ($new !== $list) { $map[$g] = $new; $changed = true; }
            }
            if ($changed) setDeliveryGroupCities($map);
            $sysSaved = 'City removed. Existing clients keep their city.';
        }
    } elseif ($action === 'save_group_cities') {
        $posted = $_POST['map'] ?? [];
        $cs = deliveryCities();
        $map = [];
        foreach (deliveryGroups() as $g) {
            $sel = (is_array($posted) && isset($posted[$g]) && is_array($posted[$g])) ? $posted[$g] : [];
            $map[$g] = array_values(array_intersect($cs, $sel));
        }
        setDeliveryGroupCities($map);
        $sysSaved = 'Group–city mapping saved.';
    } elseif ($action === 'save_tare') {
        // Tare (in ounces) subtracted from each produce weight entered on the
        // scan pages. Stored as a non-negative number; blank/invalid → 0.
        $t = trim((string)($_POST['tare_oz'] ?? ''));
        $tareOz = (is_numeric($t) && (float)$t > 0) ? (float)$t : 0.0;
        setSetting('tare_oz', (string)$tareOz);
        $sysSaved = 'Tare weight saved.';
    } else {
        foreach (['openai_api_key', 'openai_model', 'default_lead_time', 'safety_z', 'velocity_window'] as $k) {
            if (isset($_POST[$k])) setSetting($k, trim((string)$_POST[$k]));
        }
        $msg = 'Settings saved.';
    }
}

$pantryName = setting('food_pantry_name', '') ?? '';
$logoFile   = __DIR__ . '/../logo.jpg';
$logoVer    = is_file($logoFile) ? ('?v=' . filemtime($logoFile)) : '';

$allowedIp = setting('allowed_ip', '') ?? '';
$schedule  = fpAccessSchedule();
$dayNames  = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
$nowDow    = (int)date('w');
$nowHm     = date('H:i');

$key      = setting('openai_api_key', '');
$model    = setting('openai_model', 'gpt-4o-mini');
$lt       = setting('default_lead_time', '14');
$z        = setting('safety_z', '1.65');
$vw       = setting('velocity_window', '30');
$lastErr  = setting('last_openai_error', '');
$hasCurl  = function_exists('curl_init');
$tareOz   = setting('tare_oz', '0');

// Administrator email + outbound-email (SMTP) settings.
$adminEmail   = setting('admin_email', '') ?? '';
$smtpHost     = setting('smtp_host', '') ?? '';
$smtpPort     = setting('smtp_port', '') ?? '';
$smtpSecurity = setting('smtp_security', 'tls') ?? 'tls';
$smtpUser     = setting('smtp_user', '') ?? '';
$smtpHasPass  = (setting('smtp_pass', '') ?? '') !== '';
$smtpFrom     = setting('smtp_from', '') ?? '';
$smtpFromName = setting('smtp_from_name', '') ?? '';
$smtpInsecure = (setting('smtp_insecure', '0') ?? '0') === '1';

// Management lists for the Manage Events / Groups / Cities sections.
$eventTypeList = eventTypes();
$groupList     = deliveryGroups();
$cityList      = deliveryCities();
$groupCities   = deliveryGroupCities();

renderHead('Settings');
renderNav('settings');
?>
<div class="container">
  <?php if ($msg): ?><div class="banner success">✅ <?= htmlspecialchars($msg) ?></div><?php endif; ?>

  <!-- Security status. The admin password is always one-way hashed. The OpenAI
       key, allowed IP, and client address/city/phone are encrypted at rest with
       libsodium — but only when the PHP `sodium` extension is present (7.2+). -->
  <?php if (!fsCryptoAvailable()): ?>
    <div class="banner warn">
      <div style="font-size:1.2rem;">🔓</div>
      <div>
        <strong>Field encryption is OFF.</strong> The PHP <code>sodium</code>
        extension isn't available, so the OpenAI key, allowed IP, and client
        address/city/phone are stored as <em>plain text</em>. (The admin password
        is one-way hashed regardless, so it's protected either way.) Switch this
        site to <strong>PHP 7.2 or newer</strong> (cPanel → “Select PHP Version”)
        to turn on field encryption — existing values are encrypted automatically
        on the next page load.
      </div>
    </div>
  <?php endif; ?>

  <!-- ── Food Pantry Information ───────────────────────────────── -->
  <?php if ($sysSaved): ?>
    <div class="banner success">✅ <?= htmlspecialchars($sysSaved) ?></div>
  <?php elseif ($sysError): ?>
    <div class="banner error">⚠ <?= htmlspecialchars($sysError) ?></div>
  <?php endif; ?>

  <div class="card">
    <h2>Food Pantry Information</h2>
    <p style="color:#777; font-size:.85rem; margin-bottom:14px;">
      Your pantry's name appears in places like the order email subject. The
      logo shows in the header on every page.
    </p>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="fs_action" value="save_pantry_info">
      <div style="margin-bottom:14px;">
        <label for="food_pantry_name">Food Pantry Name</label>
        <input type="text" id="food_pantry_name" name="food_pantry_name"
               value="<?= htmlspecialchars($pantryName) ?>"
               placeholder="e.g. Footprints Food Pantry">
      </div>
      <div style="margin-bottom:14px;">
        <label for="logo">Logo</label>
        <div style="display:flex; align-items:center; gap:16px; flex-wrap:wrap;">
          <img src="../logo.jpg<?= $logoVer ?>" alt="Current logo"
               style="height:56px; border:1px solid var(--border); border-radius:6px; background:#fff; padding:4px;">
          <input type="file" id="logo" name="logo" accept="image/*" style="flex:1 1 240px;">
        </div>
        <p style="font-size:.75rem; color:#777; margin-top:6px;">
          JPG recommended, up to 5 MB. Leave empty to keep the current logo.
        </p>
      </div>
      <button type="submit" class="btn btn-primary">Save Food Pantry Info</button>
    </form>
  </div>

  <div class="card">
    <h2>Generic-Name Mapping (OpenAI)</h2>
    <p style="color:#777; font-size:.85rem; margin-bottom:14px;">
      Used the first time a UPC is scanned, after Open Food Facts returns a
      branded product name. The result is cached forever in the UPC lookup table,
      so this only runs once per new product. If the key is blank, the raw branded
      name is used as the generic and you can clean it up under Lookup Tables.
    </p>
    <?php if ($lastErr): ?>
      <div class="banner error">
        <div style="font-size:1.2rem;">⚠️</div>
        <div><strong>Last OpenAI error:</strong> <?= htmlspecialchars($lastErr) ?></div>
      </div>
    <?php endif; ?>
    <?php if (!$hasCurl): ?>
      <div class="banner warn">
        PHP cURL extension is not loaded — falling back to <code>file_get_contents</code>,
        which often fails on Windows due to missing CA bundle. Enable
        <code>extension=curl</code> in php.ini for reliable OpenAI calls.
      </div>
    <?php endif; ?>

    <form method="post">
      <div style="margin-bottom:12px;">
        <label for="key">OpenAI API Key</label>
        <input type="password" id="key" name="openai_api_key" value="<?= htmlspecialchars($key) ?>" placeholder="sk-...">
      </div>
      <div style="margin-bottom:12px;">
        <label for="model">Model</label>
        <input type="text" id="model" name="openai_model" value="<?= htmlspecialchars($model) ?>">
        <p style="font-size:.75rem; color:#777; margin-top:4px;">
          Known good: <code>gpt-4o-mini</code>, <code>gpt-4o</code>, <code>gpt-4.1-mini</code>.
        </p>
      </div>
      <div style="margin-bottom:16px;">
        <button type="button" id="btnTest" class="btn btn-secondary">Test API Key</button>
        <span id="testResult" style="margin-left:10px; font-size:.9rem;"></span>
      </div>

      <h2 style="margin-top:24px;">Par Level Defaults</h2>
      <div class="row">
        <div>
          <label for="lt">Default Lead Time (days)</label>
          <input type="number" id="lt" name="default_lead_time" min="1" max="365" value="<?= htmlspecialchars($lt) ?>">
        </div>
        <div>
          <label for="z">Safety Stock Z</label>
          <input type="number" id="z" name="safety_z" step="0.01" min="0" value="<?= htmlspecialchars($z) ?>">
        </div>
        <div>
          <label for="vw">Velocity Window (days)</label>
          <input type="number" id="vw" name="velocity_window" min="7" max="365" value="<?= htmlspecialchars($vw) ?>">
        </div>
      </div>
      <p style="color:#777; font-size:.8rem; margin-top:8px;">
        Common Z values: 1.28 (90%), 1.65 (95%), 2.33 (99%).
      </p>

      <div style="margin-top:18px;">
        <input type="hidden" name="fs_action" value="save_app_settings">
        <button class="btn btn-primary">Save Settings</button>
      </div>
    </form>
  </div>

  <!-- ── PantryPrep System Configuration ─────────────────────────
       (system save/error banner is rendered once at the top of the page) -->

  <div class="card">
    <h2>🌐 Secure Network Access</h2>
    <p style="color:#777; font-size:.85rem; margin-bottom:14px;">
      Restricts the PantryPrep order form and FoodScan scanning stations to a single
      public IPv4 address (your pantry's WiFi). Leave blank to disable the check.
      Your current detected IP is <strong><?= htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? '') ?></strong>.
    </p>
    <form method="post">
      <input type="hidden" name="fs_action" value="save_ip">
      <label for="allowed_ip">Public IPv4 Address</label>
      <div class="row">
        <input type="text" id="allowed_ip" name="allowed_ip"
               value="<?= htmlspecialchars($allowedIp) ?>"
               placeholder="<?= htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? '') ?>"
               style="font-family:monospace;">
        <button type="submit" class="btn btn-primary">Set IP Address</button>
        <button type="button" class="btn btn-secondary"
                onclick="document.getElementById('allowed_ip').value='<?= htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? '') ?>'">
          Use My Current IP
        </button>
      </div>
    </form>

    <!-- ── Allowed Hours ──────────────────────────────────────────
         An optional time-of-day gate layered on top of the IP check.
         When enabled, the same pages that honor the IP restriction are
         only reachable during each day's start–end window (server time).
         The Settings page itself is NOT gated, so an admin can always
         get back in here to adjust or disable the schedule. -->
    <hr style="border:none; border-top:1px solid var(--border); margin:24px 0 18px;">
    <h3 style="font-size:.95rem; color:var(--brown); margin-bottom:8px;">⏰ Allowed Hours</h3>
    <p style="color:#777; font-size:.85rem; margin-bottom:14px;">
      Optionally limit the gated pages to set hours on each day of the week.
      Times use the server clock (currently
      <strong><?= htmlspecialchars($dayNames[$nowDow]) ?> <?= htmlspecialchars($nowHm) ?></strong>).
      Uncheck a day to block it entirely; an overnight window (end earlier than
      start, e.g. 22:00–06:00) is allowed.
    </p>
    <form method="post" id="scheduleForm">
      <input type="hidden" name="fs_action" value="save_access_schedule">
      <label style="display:flex; align-items:center; gap:8px; cursor:pointer; text-transform:none; font-size:.95rem; margin-bottom:14px;">
        <input type="checkbox" name="schedule_enabled" value="1" id="schedEnabled"
               style="width:auto;"<?= $schedule['enabled'] ? ' checked' : '' ?>>
        Limit access to the hours below
      </label>
      <table class="data" id="schedTable" style="max-width:520px;">
        <thead><tr>
          <th>Day</th>
          <th style="text-align:center;">Allowed</th>
          <th>Start</th>
          <th>End</th>
        </tr></thead>
        <tbody>
          <?php for ($w = 0; $w <= 6; $w++):
            $d = $schedule['days'][$w];
          ?>
          <tr<?= $w === $nowDow ? ' style="background:#fafaf5;"' : '' ?>>
            <td><strong><?= htmlspecialchars($dayNames[$w]) ?></strong><?= $w === $nowDow ? ' <span style="color:#777; font-size:.75rem;">(today)</span>' : '' ?></td>
            <td style="text-align:center;">
              <input type="checkbox" name="day_on[<?= $w ?>]" value="1"
                     class="sched-field" style="width:auto;"<?= $d['on'] ? ' checked' : '' ?>>
            </td>
            <td>
              <input type="time" name="day_start[<?= $w ?>]" class="sched-field"
                     value="<?= htmlspecialchars($d['start']) ?>" style="width:130px;">
            </td>
            <td>
              <input type="time" name="day_end[<?= $w ?>]" class="sched-field"
                     value="<?= htmlspecialchars($d['end']) ?>" style="width:130px;">
            </td>
          </tr>
          <?php endfor; ?>
        </tbody>
      </table>
      <div style="margin-top:16px;">
        <button type="submit" class="btn btn-primary">Save Allowed Hours</button>
      </div>
    </form>
  </div>

  <div class="card">
    <h2>🔑 Administrator Email Address &amp; Password</h2>
    <p style="color:#777; font-size:.85rem; margin-bottom:14px;">
      The email address is where reorder reminders are sent when an alert's
      Email box is ticked on the Order Report. The password is the shared login
      for PantryPrep admin and FoodScan; default is
      <code style="background:#EEE8D5;padding:2px 6px;border-radius:4px;">admin</code>
      — change it after first setup. Leave both password boxes blank to save
      only the email.
    </p>
    <form method="post">
      <input type="hidden" name="fs_action" value="save_admin_password">
      <div style="margin-bottom:12px;">
        <label for="admin_email">Administrator Email Address</label>
        <input type="text" id="admin_email" name="admin_email"
               value="<?= htmlspecialchars($adminEmail) ?>"
               placeholder="e.g. manager@yourpantry.org" oninput="checkPwMatch()">
        <p style="font-size:.75rem; color:#777; margin-top:4px;">
          Separate multiple recipients with a comma or semicolon — reminders go to all of them.
        </p>
      </div>
      <div style="margin-bottom:12px;">
        <label for="new_password">New Password</label>
        <input type="password" id="new_password" name="new_password"
               placeholder="Leave blank to keep current" oninput="checkPwMatch()">
      </div>
      <div style="margin-bottom:12px;">
        <label for="confirm_password">Retype Password</label>
        <input type="password" id="confirm_password" name="confirm_password"
               placeholder="Retype new password" oninput="checkPwMatch()">
      </div>
      <div class="row" style="align-items:center;">
        <button type="submit" id="pwSubmitBtn" class="btn btn-primary">Save</button>
        <span id="pwMatchMsg" style="font-size:.85rem;"></span>
      </div>
    </form>
  </div>

  <!-- ── Email Notifications (SMTP) ─────────────────────────────────
       Outbound email for reorder reminders. Leave SMTP Host blank to use
       PHP's built-in mail(); fill it in to send authenticated SMTP from a
       real mailbox (more deliverable on shared cPanel hosting). The actual
       sending happens in the scheduled cron job (cron_reorder_alerts.php). -->
  <div class="card">
    <h2>📧 Email Notifications</h2>
    <p style="color:#777; font-size:.85rem; margin-bottom:14px;">
      How reorder-reminder emails are sent. Leave <strong>SMTP Host</strong>
      blank to use the server's built-in mail (simplest, but more likely to be
      filtered as spam). Fill it in to send authenticated SMTP from a real
      mailbox — recommended on shared/cPanel hosting.
    </p>
    <form method="post">
      <input type="hidden" name="fs_action" value="save_smtp">
      <p style="font-size:.8rem; color:#777; margin-bottom:14px;">
        Reminders are sent to
        <strong><?= htmlspecialchars($adminEmail !== '' ? $adminEmail : '— no address set —') ?></strong>.
        Change the recipient in <em>Administrator Email &amp; Password</em> above.
      </p>
      <div class="row">
        <div style="flex:2 1 240px;">
          <label for="smtp_host">SMTP Host</label>
          <input type="text" id="smtp_host" name="smtp_host"
                 value="<?= htmlspecialchars($smtpHost) ?>"
                 placeholder="e.g. mail.yourpantry.org (blank = use PHP mail())">
        </div>
        <div style="flex:0 0 110px;">
          <label for="smtp_port">Port</label>
          <input type="number" id="smtp_port" name="smtp_port" min="1" max="65535"
                 value="<?= htmlspecialchars($smtpPort) ?>" placeholder="auto">
        </div>
        <div style="flex:0 0 160px;">
          <label for="smtp_security">Security</label>
          <select id="smtp_security" name="smtp_security">
            <option value="tls"<?= $smtpSecurity === 'tls' ? ' selected' : '' ?>>STARTTLS (587)</option>
            <option value="ssl"<?= $smtpSecurity === 'ssl' ? ' selected' : '' ?>>SSL/TLS (465)</option>
            <option value=""<?= $smtpSecurity === '' ? ' selected' : '' ?>>None</option>
          </select>
        </div>
      </div>
      <div class="row" style="margin-top:12px;">
        <div>
          <label for="smtp_user">SMTP Username</label>
          <input type="text" id="smtp_user" name="smtp_user"
                 value="<?= htmlspecialchars($smtpUser) ?>"
                 placeholder="usually the full mailbox address" autocomplete="off">
        </div>
        <div>
          <label for="smtp_pass">SMTP Password</label>
          <input type="password" id="smtp_pass" name="smtp_pass"
                 placeholder="<?= $smtpHasPass ? '•••••• (saved — leave blank to keep)' : 'mailbox password' ?>"
                 autocomplete="new-password">
        </div>
      </div>
      <div class="row" style="margin-top:12px;">
        <div>
          <label for="smtp_from">From Address</label>
          <input type="text" id="smtp_from" name="smtp_from"
                 value="<?= htmlspecialchars($smtpFrom) ?>"
                 placeholder="blank = SMTP username">
        </div>
        <div>
          <label for="smtp_from_name">From Name</label>
          <input type="text" id="smtp_from_name" name="smtp_from_name"
                 value="<?= htmlspecialchars($smtpFromName) ?>"
                 placeholder="blank = food pantry name">
        </div>
      </div>
      <label style="display:flex; align-items:flex-start; gap:8px; cursor:pointer; text-transform:none; font-size:.85rem; color:#555; margin-top:14px;">
        <input type="checkbox" name="smtp_insecure" value="1" style="width:auto; margin-top:3px;"<?= $smtpInsecure ? ' checked' : '' ?>>
        <span>Allow mismatched / self-signed certificate. Tick this if the test
          fails to connect on shared/cPanel hosting — the mail server's
          certificate is usually issued for the server's own hostname, not for
          <code><?= htmlspecialchars($smtpHost !== '' ? $smtpHost : 'mail.yourdomain') ?></code>.
          The connection stays encrypted; only the certificate <em>name</em> isn't verified.</span>
      </label>
      <div style="margin-top:16px; display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
        <button type="submit" class="btn btn-primary">Save Email Settings</button>
        <button type="button" id="btnTestEmail" class="btn btn-secondary">Send Test Email</button>
        <span id="testEmailResult" style="font-size:.9rem;"></span>
      </div>
      <p style="font-size:.75rem; color:#777; margin-top:10px;">
        Save first, then test — the test uses the saved settings.
      </p>
    </form>

    <hr style="border:none; border-top:1px solid var(--border); margin:20px 0 16px;">
    <h3 style="font-size:.95rem; color:var(--brown); margin-bottom:8px;">⏱ Scheduling the reminder email (cPanel Cron)</h3>
    <p style="color:#777; font-size:.85rem; margin-bottom:8px;">
      Reminders are emailed by <code>cron_reorder_alerts.php</code>. In cPanel →
      <strong>Cron Jobs</strong>, add a job that runs it on whatever cadence you
      like (e.g. once each morning). Use the PHP CLI binary and the full path to
      this script:
    </p>
    <pre style="background:#2d2d2d; color:#f0f0f0; padding:12px 14px; border-radius:6px; overflow-x:auto; font-size:.8rem;">0 7 * * *  /usr/local/bin/php /home/USERNAME/public_html/openpantry/cron_reorder_alerts.php</pre>
    <p style="color:#777; font-size:.78rem; margin-top:6px;">
      Replace <code>USERNAME</code> (and adjust the path) to match your account.
      In cPanel, the absolute path is shown in the File Manager address bar, or
      run <code>pwd</code> in Terminal from the app folder.
    </p>
    <p style="color:#777; font-size:.78rem; margin-top:8px;">
      It emails one digest of every <em>currently triggered</em> alert that has
      its Email box ticked, and won't re-send the same alert more than once every
      <?= OP_ALERT_EMAIL_MIN_HOURS ?> hours. Path/PHP binary may differ on your
      host — check cPanel's “Cron Jobs” help for the exact PHP command.
    </p>
  </div>

  <style>
    .mgmt-section { margin-top: 18px; padding-top: 14px; border-top: 1px solid var(--border); }
    .mgmt-section.first { margin-top: 0; padding-top: 0; border-top: none; }
    .mgmt-label { display: block; font-size: .8rem; font-weight: 700; text-transform: uppercase; color: var(--brown); margin: 0 0 8px; }
    .mgmt-chips { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
    .mgmt-chips:empty { display: none; }
    .mgmt-chip { display: inline-flex; align-items: center; gap: 4px; background: var(--cat-bg); border: 1px solid var(--border); border-radius: 14px; padding: 4px 6px 4px 12px; font-size: .85rem; font-weight: 700; color: var(--brown); }
    .mgmt-chip-x { border: none; background: none; cursor: pointer; color: #8B1A1A; font-size: 1.05rem; line-height: 1; padding: 0 4px; border-radius: 50%; }
    .mgmt-chip-x:hover { background: rgba(139,26,26,.12); }
    .mgmt-add { display: flex; gap: 8px; max-width: 380px; }
    .mgmt-add input { flex: 1; }
    .gc-map-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    table.gc-map { border-collapse: collapse; font-size: .85rem; }
    table.gc-map th, table.gc-map td { border: 1px solid var(--border); padding: 6px 12px; white-space: nowrap; }
    table.gc-map th { background: var(--cat-bg); color: var(--brown); font-weight: 700; }
    table.gc-map td:first-child { text-align: left; }
    table.gc-map input[type="checkbox"] { width: auto; cursor: pointer; }
  </style>

  <!-- ── Manage Events ─────────────────────────────────────────── -->
  <div class="card">
    <h2>Manage Events</h2>
    <p style="color:#777; font-size:.85rem; margin-bottom:14px;">
      Add or remove selectable event types on the Events page and reports.
    </p>
    <div class="mgmt-chips">
      <?php foreach ($eventTypeList as $t): ?>
        <span class="mgmt-chip">
          <span><?= htmlspecialchars($t) ?></span>
          <form method="post" style="display:inline; margin:0;"
                onsubmit="return confirm('Remove this event from the picker? Past event orders keep their type.');">
            <input type="hidden" name="fs_action" value="remove_event_type">
            <input type="hidden" name="event_name" value="<?= htmlspecialchars($t) ?>">
            <button type="submit" class="mgmt-chip-x" title="Remove event" aria-label="Remove event">×</button>
          </form>
        </span>
      <?php endforeach; ?>
    </div>
    <form method="post" class="mgmt-add">
      <input type="hidden" name="fs_action" value="add_event_type">
      <input type="text" name="event_name" maxlength="40" required
             placeholder="New event (e.g. Holiday Meal)" autocomplete="off">
      <button type="submit" class="btn btn-secondary">＋ Add Event</button>
    </form>
  </div>

  <!-- ── Delivery Groups & Cities ──────────────────────────────── -->
  <div class="card">
    <h2>Delivery Groups &amp; Cities</h2>

    <div class="mgmt-section first">
      <label class="mgmt-label">Manage Groups</label>
      <div class="mgmt-chips">
        <?php foreach ($groupList as $g): ?>
          <span class="mgmt-chip">
            <span><?= htmlspecialchars($g) ?></span>
            <form method="post" style="display:inline; margin:0;"
                  onsubmit="return confirm('Remove this group? Existing clients keep their group.');">
              <input type="hidden" name="fs_action" value="remove_group">
              <input type="hidden" name="group_name" value="<?= htmlspecialchars($g) ?>">
              <button type="submit" class="mgmt-chip-x" title="Remove group" aria-label="Remove group">×</button>
            </form>
          </span>
        <?php endforeach; ?>
      </div>
      <form method="post" class="mgmt-add">
        <input type="hidden" name="fs_action" value="add_group">
        <input type="text" name="group_name" maxlength="20" required
               placeholder="New group (e.g. K-3)" autocomplete="off">
        <button type="submit" class="btn btn-secondary">＋ Add Group</button>
      </form>
    </div>

    <div class="mgmt-section">
      <label class="mgmt-label">Manage Cities</label>
      <div class="mgmt-chips">
        <?php foreach ($cityList as $c): ?>
          <span class="mgmt-chip">
            <span><?= htmlspecialchars($c) ?></span>
            <form method="post" style="display:inline; margin:0;"
                  onsubmit="return confirm('Remove this city? Existing clients keep their city.');">
              <input type="hidden" name="fs_action" value="remove_city">
              <input type="hidden" name="city_name" value="<?= htmlspecialchars($c) ?>">
              <button type="submit" class="mgmt-chip-x" title="Remove city" aria-label="Remove city">×</button>
            </form>
          </span>
        <?php endforeach; ?>
      </div>
      <form method="post" class="mgmt-add">
        <input type="hidden" name="fs_action" value="add_city">
        <input type="text" name="city_name" maxlength="40" required
               placeholder="New city (e.g. York, ME)" autocomplete="off">
        <button type="submit" class="btn btn-secondary">＋ Add City</button>
      </form>
    </div>

    <div class="mgmt-section">
      <label class="mgmt-label">Group → Cities</label>
      <p style="font-size:.8rem; color:#777; margin:-4px 0 10px;">
        Choose which cities each group serves. On the Add Client form, picking a
        group narrows the City list to its cities (a group with none checked
        offers all cities).
      </p>
      <form method="post">
        <input type="hidden" name="fs_action" value="save_group_cities">
        <div class="gc-map-wrap">
          <table class="gc-map">
            <thead>
              <tr>
                <th>Group</th>
                <?php foreach ($cityList as $c): ?><th><?= htmlspecialchars($c) ?></th><?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($groupList as $g): $mapped = $groupCities[$g] ?? []; ?>
              <tr>
                <td><strong><?= htmlspecialchars($g) ?></strong></td>
                <?php foreach ($cityList as $c): ?>
                  <td style="text-align:center;">
                    <input type="checkbox" name="map[<?= htmlspecialchars($g) ?>][]"
                           value="<?= htmlspecialchars($c) ?>"<?= in_array($c, $mapped, true) ? ' checked' : '' ?>>
                  </td>
                <?php endforeach; ?>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <button type="submit" class="btn btn-secondary" style="margin-top:10px;">💾 Save Group–City Mapping</button>
      </form>
    </div>
  </div>

  <!-- ── Tare ──────────────────────────────────────────────────── -->
  <div class="card">
    <h2>Tare</h2>
    <p style="color:#777; font-size:.85rem; margin-bottom:14px;">
      Container weight, in ounces, subtracted from the value entered in the
      “Weight required” window when adding an item to an order. Set to 0 for
      no tare.
    </p>
    <form method="post">
      <input type="hidden" name="fs_action" value="save_tare">
      <div class="row" style="align-items:flex-end;">
        <div>
          <label for="tare_oz">Tare (ounces)</label>
          <input type="number" id="tare_oz" name="tare_oz" min="0" step="0.01"
                 value="<?= htmlspecialchars($tareOz) ?>">
        </div>
        <div style="flex:0 0 140px;">
          <label>&nbsp;</label>
          <button type="submit" class="btn btn-primary btn-block">Save Tare</button>
        </div>
      </div>
    </form>
  </div>
</div>
<script>
function checkPwMatch() {
  var pw1 = document.getElementById('new_password').value;
  var pw2 = document.getElementById('confirm_password').value;
  var btn = document.getElementById('pwSubmitBtn');
  var msg = document.getElementById('pwMatchMsg');
  // Both blank → saving the email only (password unchanged): allow, no message.
  if (!pw1 && !pw2) { msg.textContent = ''; btn.disabled = false; return; }
  if (pw1 === pw2) {
    msg.textContent = '✅ Passwords match'; msg.style.color = '#276437'; btn.disabled = false;
  } else {
    msg.textContent = '✗ Passwords do not match'; msg.style.color = '#8B1A1A'; btn.disabled = true;
  }
}

// Send Test Email: posts to api_send_test_email.php, which uses the saved
// SMTP / admin-email settings. Save the form first so a just-typed address is
// what gets used.
document.getElementById('btnTestEmail').addEventListener('click', async () => {
  var res = document.getElementById('testEmailResult');
  res.textContent = 'Sending…'; res.style.color = '#777';
  try {
    var r = await fetch('../api_send_test_email.php', { method: 'POST', credentials: 'same-origin' })
              .then(function (x) { return x.json(); });
    if (r.ok) {
      res.textContent = '✅ Sent to ' + r.to + ' (' + r.via + ').';
      res.style.color = '#276437';
    } else {
      res.textContent = '❌ ' + r.error;
      res.style.color = '#8B1A1A';
    }
  } catch (e) {
    res.textContent = '❌ ' + e.message;
    res.style.color = '#8B1A1A';
  }
});
// Dim the per-day schedule rows when the master toggle is off. The fields
// stay enabled (disabled inputs wouldn't POST, which would wipe the saved
// times on the next save) — this is purely a visual cue.
(function () {
  var master = document.getElementById('schedEnabled');
  var table  = document.getElementById('schedTable');
  if (!master || !table) return;
  function sync() { table.style.opacity = master.checked ? '1' : '.5'; }
  master.addEventListener('change', sync);
  sync();
})();

document.getElementById('btnTest').addEventListener('click', async () => {
  const res = document.getElementById('testResult');
  res.textContent = 'Testing…'; res.style.color = '#777';
  // Save the form first so any pending key/model change is what we test.
  const fd = new FormData(document.querySelector('form'));
  await fetch('', { method: 'POST', body: fd });
  const r = await fetch('../api_openai_test.php', { method: 'POST' }).then(r => r.json());
  if (r.ok) {
    res.textContent = '✅ OK — "' + r.sample + '" → "' + r.generic + '" (model: ' + r.model + ')';
    res.style.color = '#276437';
  } else {
    res.textContent = '❌ ' + r.error;
    res.style.color = '#8B1A1A';
  }
});
</script>
<?php renderFoot(); ?>
