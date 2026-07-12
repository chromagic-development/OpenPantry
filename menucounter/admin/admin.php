<?php
require_once '../db.php';
require_once __DIR__ . '/../../ratelimit.php'; // progressive login throttle + soft-lock
$db = getDB();

// admin_password lives in openpantry.db now — see foodscanSetting() in db.php.
$adminPassword = foodscanSetting('admin_password', 'admin');
$clientIp      = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

// ── Persistent auth cookie (2 months) ────────────────────────────────────────
// Uses header() directly for maximum PHP/server compatibility.
// Token is a SHA-256 hash of the password — auto-invalidates on password change.
define('AUTH_COOKIE', 'fp_admin_auth');
$twoMonths = 60 * 60 * 24 * 60;

function makeAuthToken($password) {
    return hash('sha256', 'fp_admin_' . $password);
}

function setAuthCookie($password, $duration) {
    $token   = makeAuthToken($password);
    $expires = gmdate('D, d M Y H:i:s T', time() + $duration);
    header('Set-Cookie: ' . AUTH_COOKIE . '=' . $token
           . '; Expires=' . $expires
           . '; Max-Age=' . $duration
           . '; Path=/'
           . '; HttpOnly'
           . '; SameSite=Lax', false);
    $_COOKIE[AUTH_COOKIE] = $token;
}

function clearAuthCookie() {
    header('Set-Cookie: ' . AUTH_COOKIE . '='
           . '; Expires=Thu, 01 Jan 1970 00:00:00 GMT'
           . '; Max-Age=0'
           . '; Path=/'
           . '; HttpOnly'
           . '; SameSite=Lax', false);
    unset($_COOKIE[AUTH_COOKIE]);
}

function isAuthenticated($password) {
    $cookie = $_COOKIE[AUTH_COOKIE] ?? '';
    return $cookie !== '' && hash_equals(makeAuthToken($password), $cookie);
}

// Handle login form submission. admin_password is a one-way hash in
// openpantry.db; fpVerifyAdminPassword (crypto.php, included via ../db.php)
// checks the submitted password against it. The cookie token still derives
// from the stored hash, so setAuthCookie keeps using $adminPassword.
// Attempts run through the shared progressive throttle (ratelimit.php):
// flagged IPs wait, soft-locked IPs must also supply the emailed code.
if (isset($_POST['action']) && $_POST['action'] === 'login') {
    $gate = fpLoginGate($clientIp, false);
    if ($gate['mode'] === 'wait') {
        $loginError = 'Too many failed attempts. ' . fpThrottleWaitText($gate['wait']);
    } else {
        $passOk = fpVerifyAdminPassword($_POST['password'] ?? '', $adminPassword);
        $otpOk  = ($gate['mode'] !== 'otp')
               || fpLoginOtpCheck($clientIp, trim((string)($_POST['otp'] ?? '')));
        if ($passOk && $otpOk) {
            fpLoginRecordSuccess($clientIp);
            setAuthCookie($adminPassword, $twoMonths);
        } else {
            fpLoginRecordFailure($clientIp);
            $loginError = ($gate['mode'] === 'otp')
                ? 'Incorrect password or security code.'
                : 'Incorrect password.';
        }
    }
}

// AJAX password check for the confirm-change modal. admin_password is a
// one-way hash, so the check must happen server-side — the client can't
// compare plaintext input against the stored value. Failures feed the same
// per-IP throttle as the login form; the emailed-code stage doesn't apply
// here because reaching this endpoint already requires a valid auth cookie
// (isAuthenticated), which serves as the second factor.
if (isset($_POST['action']) && $_POST['action'] === 'verify_password') {
    header('Content-Type: application/json');
    $gate = fpLoginGate($clientIp, false);
    $ok   = false;
    if ($gate['mode'] !== 'wait' && isAuthenticated($adminPassword)) {
        $ok = fpVerifyAdminPassword($_POST['password'] ?? '', $adminPassword);
        if ($ok) fpLoginRecordSuccess($clientIp);
        else     fpLoginRecordFailure($clientIp);
    }
    echo json_encode(['success' => $ok]);
    exit;
}

// Handle logout
if (isset($_GET['logout'])) {
    clearAuthCookie();
    header('Location: admin.php');
    exit;
}

// Refresh cookie expiry on each authenticated visit
if (isAuthenticated($adminPassword)) {
    setAuthCookie($adminPassword, $twoMonths);
}

// Show login wall if not authenticated
if (!isAuthenticated($adminPassword)) {
    // Rendering the wall is the one moment a soft-locked IP may trigger the
    // (paced) security-code email — see fpLoginGate's $allowSend.
    $gate   = fpLoginGate($clientIp, true);
    $notice = $gate['note'];
    if ($gate['mode'] === 'wait' && $notice === '') {
        $notice = 'Too many failed attempts. ' . fpThrottleWaitText($gate['wait']);
    }
?><!DOCTYPE html>
<html lang="en">
<head>
<link rel="icon" type="image/x-icon" href="../favicon.ico">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Footprints – Admin Login</title>
<style>
  :root { --brown:#6B4C11; --green:#8BAF3A; --light:#F5F0E8; --border:#D4C9A8; }
  * { box-sizing:border-box; margin:0; padding:0; }
  body { font-family:Arial,sans-serif; background:var(--light); display:flex; align-items:center; justify-content:center; min-height:100vh; }
  .login-card { background:#fff; border:1px solid var(--border); border-radius:12px; padding:36px 40px; width:100%; max-width:360px; box-shadow:0 4px 16px rgba(0,0,0,.1); }
  .login-card h1 { font-size:1.1rem; color:var(--brown); margin-bottom:6px; }
  .login-card p  { font-size:.82rem; color:#888; margin-bottom:24px; }
  label { display:block; font-size:.78rem; font-weight:700; text-transform:uppercase; letter-spacing:.4px; color:var(--brown); margin-bottom:6px; }
  input[type="password"], input[type="text"] { width:100%; border:1px solid var(--border); border-radius:6px; padding:9px 12px; font-size:.95rem; margin-bottom:16px; background:#fafaf5; }
  input[type="password"]:focus, input[type="text"]:focus { outline:none; border-color:var(--green); }
  .btn-login { width:100%; background:var(--brown); color:#fff; border:none; border-radius:7px; padding:11px; font-size:1rem; font-weight:700; cursor:pointer; }
  .btn-login:hover { background:#8B6420; }
  .error { background:#F8D7DA; border:1px solid #F1AEB5; color:#8B1A1A; border-radius:6px; padding:10px 14px; font-size:.85rem; margin-bottom:16px; }
  .notice { background:#FFF3CD; border:1px solid #E6D9A8; color:#6B5B11; border-radius:6px; padding:10px 14px; font-size:.85rem; margin-bottom:16px; }
</style>
</head>
<body>
<div class="login-card">
  <h1>⚙ Admin Login</h1>
  <p>Enter the administrator password to continue.</p>
  <?php if (!empty($loginError)): ?>
    <div class="error">⚠ <?= htmlspecialchars($loginError) ?></div>
  <?php endif; ?>
  <?php if ($notice !== ''): ?>
    <div class="notice">🔐 <?= htmlspecialchars($notice) ?></div>
  <?php endif; ?>
  <form method="POST">
    <input type="hidden" name="action" value="login">
    <label for="password">Password</label>
    <input type="password" id="password" name="password" autofocus placeholder="Enter password">
    <?php if ($gate['mode'] === 'otp'): ?>
      <label for="otp">Security Code</label>
      <input type="text" id="otp" name="otp" inputmode="numeric"
             autocomplete="one-time-code" maxlength="6" placeholder="6-digit emailed code">
    <?php endif; ?>
    <button type="submit" class="btn-login">Log In</button>
  </form>
</div>
</body>
</html>
<?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<link rel="icon" type="image/x-icon" href="../favicon.ico">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Footprints – Admin: Manage Items</title>
<style>
  :root { --brown:#6B4C11; --green:#8BAF3A; --light:#F5F0E8; --border:#D4C9A8; }
  * { box-sizing:border-box; margin:0; padding:0; }
  body { font-family:Arial,sans-serif; background:var(--light); color:#333; }
  /* Shared OpenPantry header (matches foodscan/index.php). Page actions live
     on the right, restyled as light secondary buttons. No subnav. */
  .site-header {
    background:#fff; border-bottom:3px solid var(--green);
    padding:14px 24px; display:flex; align-items:center; gap:16px;
    box-shadow:0 2px 6px rgba(0,0,0,.08);
  }
  .site-header img { height:56px; display:block; }
  .site-header .header-text h1 { font-size:1.1rem; color:var(--brown); font-weight:700; text-transform:uppercase; margin:0; }
  .site-header .header-text p { font-size:.8rem; color:#777; margin:0; }
  .site-header .header-actions { margin-left:auto; display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
  .site-header .header-actions a, .site-header .header-actions button {
    color:var(--brown); text-decoration:none; background:#fff;
    border:2px solid var(--border); border-radius:7px;
    padding:8px 14px; font-size:.84rem; font-weight:700; cursor:pointer; font-family:inherit;
  }
  .site-header .header-actions a:hover, .site-header .header-actions button:hover { background:#EEE8D5; }

  .container { max-width:1500px; margin:30px auto 60px; padding:0 16px; }
  h1 { font-size:1.3rem; color:var(--brown); margin-bottom:6px; }
  .subtitle { font-size:.84rem; color:#777; margin-bottom:20px; }

  .card { background:#fff; border:1px solid var(--border); border-radius:10px; overflow:visible; box-shadow:0 2px 8px rgba(0,0,0,.06); }
  .table-scroll { overflow-x:auto; -webkit-overflow-scrolling:touch; }
  .card-header { padding:14px 20px; background:#F0EBD8; border-bottom:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; }
  .card-header h2 { font-size:.9rem; font-weight:800; text-transform:uppercase; letter-spacing:.5px; color:var(--brown); }
  .item-search {
    border: 1px solid var(--border); border-radius: 6px;
    padding: 6px 12px; font-size: .85rem; font-family: inherit;
    background: #fff; color: #333; outline: none;
    width: 220px;
  }
  .item-search:focus { border-color: var(--green); }
  .item-search::placeholder { color: #bbb; }

  #itemsTable { width:100%; border-collapse:collapse; }
  #itemsTable th { text-align:left; padding:9px 12px; font-size:.75rem; text-transform:uppercase; letter-spacing:.5px; background:#F5F0E8; color:var(--brown); border-bottom:1px solid var(--border); }
  #itemsTable td { padding:8px 12px; border-bottom:1px solid #F0EBD8; vertical-align:middle; font-size:.88rem; }
  #itemsTable tr:hover td { background:#FAFAF5; }
  #itemsTable input[type="text"], #itemsTable select {
    border:1px solid var(--border); border-radius:4px; padding:5px 8px;
    font-size:.85rem; width:100%; background:#fafaf5;
  }
  #itemsTable input[type="text"]:focus, #itemsTable select:focus {
    outline:none; border-color:var(--green);
  }
  .toggle-active { cursor:pointer; font-size:1.1rem; }
  .drag-handle { cursor:grab; color:#bbb; font-size:1.1rem; padding:0 4px; }
  .drag-handle:active { cursor:grabbing; }
  .row-dragging { opacity:.4; background:#EEE0C0 !important; }

  .btn { border:none; border-radius:6px; padding:8px 18px; font-size:.85rem; font-weight:700; cursor:pointer; transition:background .2s; }
  .btn-green  { background:var(--green); color:#fff; }
  .btn-green:hover  { background:#6F9430; }
  .btn-brown  { background:var(--brown); color:#fff; }
  .btn-brown:hover  { background:#8B6420; }
  .btn-red    { background:transparent; color:#C62828; border:1px solid #C62828; }
  .btn-red:hover    { background:#C62828; color:#fff; }

  .btn-row { padding:16px 20px; display:flex; justify-content:space-between; flex-wrap:wrap; gap:10px; border-top:1px solid var(--border); background:#F5F0E8; }

  .toast { position:fixed; bottom:24px; right:24px; background:#222; color:#fff; padding:12px 20px; border-radius:8px; font-size:.88rem; font-weight:600; transform:translateY(80px); opacity:0; transition:all .3s; pointer-events:none; z-index:999; }
  .toast.show { transform:translateY(0); opacity:1; }

  /* ── Password confirm modal ─────────────── */
  .pw-overlay {
    display:none; position:fixed; inset:0;
    background:rgba(0,0,0,.45); z-index:1000;
    align-items:center; justify-content:center;
  }
  .pw-overlay.open { display:flex; }
  .pw-modal {
    background:#fff; border-radius:10px; padding:28px 32px;
    box-shadow:0 8px 32px rgba(0,0,0,.2); width:100%; max-width:340px;
  }
  .pw-modal h3 { font-size:1rem; color:var(--brown); margin-bottom:6px; }
  .pw-modal p  { font-size:.82rem; color:#666; margin-bottom:16px; }
  .pw-modal input[type="password"] {
    width:100%; border:1px solid var(--border); border-radius:6px;
    padding:9px 12px; font-size:.95rem; margin-bottom:6px; background:#fafaf5;
  }
  .pw-modal input[type="password"]:focus { outline:none; border-color:var(--green); }
  .pw-modal .pw-error { color:#C62828; font-size:.78rem; min-height:18px; margin-bottom:10px; }
  .pw-modal .pw-btns { display:flex; gap:10px; justify-content:flex-end; }
</style>
</head>
<body>

<header class="site-header">
  <img src="../../logo.jpg" alt="Logo">
  <div class="header-text">
    <h1><span style="color:var(--green);">Open</span>Pantry</h1>
    <p>Inventory tracking management</p>
  </div>
  <div class="header-actions">
    <a href="../orders">← Orders</a>
    <a href="../" target="_blank" rel="noopener noreferrer">📋 Order Form</a>
    <a href="../report/">📊 Report</a>
    <a href="../admin?logout=1">🔒 Log Out</a>
  </div>
</header>

<div class="container">
  <div class="card" style="margin-bottom:20px;">
    <div style="padding:16px 20px; display:flex; align-items:flex-end; gap:12px; flex-wrap:wrap;">
      <div style="flex:1 1 320px;">
        <label for="clientNotes" style="display:block; font-size:.78rem; font-weight:700; text-transform:uppercase; letter-spacing:.4px; color:var(--brown); margin-bottom:6px;">Announcement</label>
        <input type="text" id="clientNotes"
               placeholder="Optional note shown at the top of the order form — leave blank to show nothing"
               style="width:100%; border:1px solid var(--border); border-radius:6px; padding:8px 12px; font-size:.9rem; background:#fafaf5;">
      </div>
      <button class="btn btn-brown" onclick="saveClientNotes()">💾 Save Note</button>
    </div>
  </div>

  <h1>Configure Order Form Items</h1>
  <p class="subtitle">Add, remove, reorder, or toggle items that appear on the customer order form. Drag rows to reorder. Changes take effect immediately for new orders.</p>

  <div class="card">
    <div class="card-header">
      <h2>Active Items</h2>
      <input type="text" class="item-search" id="itemSearch"
             placeholder="🔍 Filter by item name…"
             oninput="filterItems(this.value)">
      <div style="display:flex;gap:10px;margin-left:auto;">
        <button class="btn btn-green" onclick="addRow()">+ Add Item</button>
        <button class="btn btn-brown" onclick="saveItems()">💾 Save All Changes</button>
      </div>
    </div>
    <div class="table-scroll">
    <table id="itemsTable">
      <thead>
        <tr>
          <th style="width:30px;"></th>
          <th style="width:40px;">On</th>
          <th style="width:90px;">Unavailable?</th>
          <th style="width:120px;">Category</th>
          <th style="min-width:160px;">Item Name</th>
          <th style="width:80px;">Subtype?</th>
          <th style="width:100px;">Subtype Label</th>
          <th style="min-width:320px;">Subtype Selections</th>
          <th style="width:90px;">Family Factor</th>
          <th style="width:60px;">Adults</th>
          <th style="width:70px;">Children</th>
          <th style="width:60px;">Remove</th>
        </tr>
      </thead>
      <tbody id="itemsTbody"></tbody>
    </table>
    </div>
    <div class="btn-row">
      <div style="display:flex;gap:10px;">
        <button class="btn btn-green" onclick="addRow()">+ Add Item</button>
        <button class="btn btn-brown" onclick="saveItems()">💾 Save All Changes</button>
      </div>
      <span style="font-size:.78rem;color:#999;">Changes are saved to the database immediately</span>
    </div>
  </div>
</div>

<!-- ── Password Confirmation Modal ── -->
<div class="pw-overlay" id="pwOverlay">
  <div class="pw-modal">
    <h3 id="pwModalTitle">Confirm Action</h3>
    <p id="pwModalDesc">Enter the admin password to proceed.</p>
    <input type="password" id="pwModalInput"
           autocomplete="new-password"
           placeholder="Admin password"
           onkeydown="if(event.key==='Enter')pwModalConfirm()">
    <div class="pw-error" id="pwModalError"></div>
    <div class="pw-btns">
      <button class="btn btn-outline" onclick="pwModalCancel()">Cancel</button>
      <button class="btn btn-brown" onclick="pwModalConfirm()">Confirm</button>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
const CATS = ['DAIRY','DRY GOODS','FROZEN ITEMS','SPECIALS','PRODUCE','OTHER ITEMS'];
let items = [];
let dragSrc = null;

// ── Password modal ──────────────────────────────────────────────────
// The admin password is stored as a one-way hash, so entered passwords are
// verified server-side (POST action=verify_password) rather than compared here.
let pwModalCallback = null;
let pwModalRevertCallback = null;
let pwModalChecking = false;

function requirePassword(title, desc, onConfirm, onCancel) {
  document.getElementById('pwModalTitle').textContent = title;
  document.getElementById('pwModalDesc').textContent  = desc;
  document.getElementById('pwModalError').textContent = '';
  document.getElementById('pwModalInput').value       = '';
  pwModalCallback       = onConfirm;
  pwModalRevertCallback = onCancel || null;
  document.getElementById('pwOverlay').classList.add('open');
  setTimeout(function() { document.getElementById('pwModalInput').focus(); }, 50);
}

async function pwModalConfirm() {
  if (pwModalChecking) return;
  pwModalChecking = true;
  var entered = document.getElementById('pwModalInput').value;
  var ok = false;
  try {
    const res  = await fetch('admin.php', {
      method: 'POST',
      body: new URLSearchParams({ action: 'verify_password', password: entered })
    });
    const data = await res.json();
    ok = !!data.success;
  } catch (e) {
    ok = false;
  }
  pwModalChecking = false;
  if (!ok) {
    document.getElementById('pwModalError').textContent = '✗ Incorrect password.';
    document.getElementById('pwModalInput').value = '';
    document.getElementById('pwModalInput').focus();
    return;
  }
  document.getElementById('pwOverlay').classList.remove('open');
  document.getElementById('pwModalInput').value = '';
  if (pwModalCallback) { pwModalCallback(); pwModalCallback = null; }
}

function pwModalCancel() {
  document.getElementById('pwOverlay').classList.remove('open');
  document.getElementById('pwModalInput').value = '';
  if (pwModalRevertCallback) { pwModalRevertCallback(); pwModalRevertCallback = null; }
  pwModalCallback = null;
}

// Close on overlay background click
document.getElementById('pwOverlay').addEventListener('click', function(e) {
  if (e.target === this) pwModalCancel();
});

async function loadItems() {
  const res  = await fetch('../api.php?action=get_config');
  const data = await res.json();
  items = data.items || [];
  document.getElementById('clientNotes').value = data.client_notes || '';
  renderTable();
}

async function saveClientNotes() {
  try {
    const res  = await fetch('../api.php?action=save_notes', {
      method: 'POST',
      body: new URLSearchParams({ notes: document.getElementById('clientNotes').value })
    });
    const data = await res.json();
    if (!data.success) throw new Error(data.error);
    showToast('✅ Client note saved!');
  } catch(e) {
    showToast('⚠ Save failed: ' + e.message);
  }
}

function renderTable() {
  const tbody = document.getElementById('itemsTbody');
  tbody.innerHTML = items.map((item, i) => `
    <tr id="row_${i}" draggable="true"
        data-item-name="${escHtml((item.item_name||'').toLowerCase())}"
        ondragstart="dragStart(event,${i})" ondragover="dragOver(event,${i})"
        ondrop="dragDrop(event,${i})" ondragend="dragEnd(event)">
      <td><span class="drag-handle" title="Drag to reorder">⠿</span></td>
      <td>
        <span class="toggle-active" onclick="toggleActive(${i})" title="Toggle active">
          ${item.active=='1'||item.active===1 ? '✅' : '⬜'}
        </span>
      </td>
      <td style="text-align:center;">
        <input type="checkbox" title="Check if this item is currently unavailable"
               ${item.unavailable==1?'checked':''}
               onchange="items[${i}].unavailable=this.checked?1:0"
               style="accent-color:#C62828;width:16px;height:16px;">
      </td>
      <td>
        <select data-row="${i}" data-field="category"
                onchange="confirmFieldChange(this, ${i}, 'category')"
                onfocus="this.dataset.prev=this.value">
          ${CATS.map(c => `<option value="${c}" ${c===item.category?'selected':''}>${c}</option>`).join('')}
        </select>
      </td>
      <td><input type="text" value="${escHtml(item.item_name)}"
                 onfocus="this.dataset.prev=this.value"
                 onchange="confirmFieldChange(this, ${i}, 'item_name')"
                 placeholder="Item name"></td>
      <td style="text-align:center;">
        <input type="checkbox" ${item.has_detail==1?'checked':''}
               onchange="confirmCheckboxChange(this, ${i}, 'has_detail')">
      </td>
      <td>
        ${item.has_detail==1 ? `<input type="text" value="${escHtml(item.detail_label||'Size')}" onfocus="this.dataset.prev=this.value" onchange="confirmFieldChange(this, ${i}, 'detail_label')" placeholder="Label">` : '<span style="color:#ccc">—</span>'}
      </td>
      <td>
        ${item.has_detail==1 ? `<input type="text" value="${escHtml(item.size_options||'')}" onfocus="this.dataset.prev=this.value" onchange="confirmFieldChange(this, ${i}, 'size_options')" placeholder="e.g. Small,Medium,Large" title="Comma-separated list of size options">` : '<span style="color:#ccc">—</span>'}
      </td>
      <td>
        <input type="number" value="${parseFloat(item.family_factor||1).toFixed(2)}"
               min="0.01" step="0.01"
               title="Multiply family size (max 5) by this factor then round up to get item quantity"
               onfocus="this.dataset.prev=this.value"
               onchange="confirmFieldChange(this, ${i}, 'family_factor')"
               style="width:70px;text-align:center;">
      </td>
      <td style="text-align:center;">
        <input type="checkbox" title="Use only Adults count in calculation"
               ${item.use_adults==1?'checked':''}
               onchange="confirmCheckboxChange(this, ${i}, 'use_adults')"
               style="accent-color:#6B4C11;width:16px;height:16px;">
      </td>
      <td style="text-align:center;">
        <input type="checkbox" title="Use only Children count in calculation"
               ${item.use_children==1?'checked':''}
               onchange="confirmCheckboxChange(this, ${i}, 'use_children')"
               style="accent-color:#4A90D9;width:16px;height:16px;">
      </td>
      <td>
        <button class="btn btn-red" style="padding:5px 10px;" onclick="removeRow(${i})">✕</button>
      </td>
    </tr>
  `).join('');

  // Re-apply active search filter after render (only if user has typed something)
  var searchEl = document.getElementById('itemSearch');
  if (searchEl && searchEl.value.trim()) filterItems(searchEl.value);
}

function filterItems(term) {
  var q = term.trim().toLowerCase();
  document.querySelectorAll('#itemsTbody tr').forEach(function(row) {
    if (!q) {
      row.style.display = '';
    } else {
      var name = row.getAttribute('data-item-name') || '';
      row.style.display = name.includes(q) ? '' : 'none';
    }
  });
}

function addRow() {
  requirePassword(
    'Confirm Change',
    'Enter the admin password to add a new item.',
    function() {
      items.push({ category:'DAIRY', item_name:'', has_detail:0, detail_label:'', size_options:'', family_factor:0.10, active:1, unavailable:0, use_adults:0, use_children:0, sort_order:items.length, isNew:true });
      renderTable();
      // Focus the new row name input
      setTimeout(() => {
        const rows = document.querySelectorAll('#itemsTbody tr');
        const last = rows[rows.length-1];
        if (last) { const inp = last.querySelector('input[type="text"]'); if (inp) inp.focus(); }
      }, 50);
    }
  );
}

var FIELD_LABELS = {
  category:      'Category',
  item_name:     'Item Name',
  detail_label:  'Subtype Label',
  size_options:  'Subtype Selections',
  family_factor: 'Family Factor',
  has_detail:    'Subtype?',
  use_adults:    'Adults',
  use_children:  'Children'
};

function applyFieldValue(i, field, newVal) {
  items[i][field] = field === 'family_factor' ? (parseFloat(newVal) || 1) : newVal;
}

function confirmFieldChange(el, i, field) {
  var newVal  = el.value;
  var prevVal = el.dataset.prev !== undefined ? el.dataset.prev : el.defaultValue;
  if (newVal === prevVal) return;

  // New rows (not yet saved) don't require password approval
  if (items[i] && items[i].isNew) {
    applyFieldValue(i, field, newVal);
    el.dataset.prev = newVal;
    return;
  }

  requirePassword(
    'Confirm Change',
    'Enter the admin password to change the ' + FIELD_LABELS[field] + ' field.',
    function() {
      applyFieldValue(i, field, newVal);
      el.dataset.prev = newVal;
    },
    function() {
      el.value = prevVal;
    }
  );
}

function confirmCheckboxChange(el, i, field) {
  var newVal = el.checked ? 1 : 0;

  function apply() {
    items[i][field] = newVal;
    // Subtype? shows/hides the label and selections inputs, so re-render
    if (field === 'has_detail') renderTable();
  }

  // New rows (not yet saved) don't require password approval
  if (items[i] && items[i].isNew) { apply(); return; }

  requirePassword(
    'Confirm Change',
    'Enter the admin password to change the ' + FIELD_LABELS[field] + ' field.',
    apply,
    function() { el.checked = (newVal !== 1); }
  );
}

function removeRow(i) {
  requirePassword(
    'Confirm Remove',
    'Enter the admin password to remove "' + (items[i].item_name || 'this item') + '".',
    function() {
      items.splice(i, 1);
      renderTable();
    }
  );
}

function toggleActive(i) {
  items[i].active = (items[i].active==1||items[i].active===true) ? 0 : 1;
  renderTable();
}

// Drag-to-reorder
function dragStart(e, i) { dragSrc=i; e.currentTarget.classList.add('row-dragging'); }
function dragOver(e, i)  { e.preventDefault(); }
function dragDrop(e, i)  {
  e.preventDefault();
  if (dragSrc === null || dragSrc === i) return;
  const moved = items.splice(dragSrc, 1)[0];
  items.splice(i, 0, moved);
  renderTable();
}
function dragEnd(e) { e.currentTarget.classList.remove('row-dragging'); dragSrc = null; }

async function saveItems() {
  // Validate
  for (let i=0; i<items.length; i++) {
    if (!items[i].item_name.trim()) {
      alert('Item name cannot be empty (row '+(i+1)+')');
      return;
    }
  }
  try {
    const res  = await fetch('../api.php?action=save_config', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ items })
    });
    const data = await res.json();
    if (!data.success) throw new Error(data.error);
    showToast('✅ Items saved successfully!');
    await loadItems();
  } catch(e) {
    showToast('⚠ Save failed: ' + e.message);
  }
}

function escHtml(str) {
  return String(str||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

let toastTimer;
function showToast(msg) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => t.classList.remove('show'), 3500);
}

loadItems();
</script>

<footer style="text-align:center; padding:24px 16px; font-size:.78rem; color:#999; border-top:1px solid var(--border); margin-top:40px;">
  &copy; 2026 <strong>Chromagic Development</strong> &mdash; OpenPantry, by
  <a href="mailto:chromagic@gmail.com" style="color:var(--brown); text-decoration:none; font-weight:600;">Bruce Alexander</a>.
  Released under the
  <a href="../../LICENSE" style="color:var(--brown); text-decoration:none;">MIT License</a>.
</footer>

</body>
</html>
