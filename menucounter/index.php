<?php
require_once 'db.php';
require_once '../auth.php'; // fpRenderAccessDenied() — shared styled Access Denied wall
$db = getDB();

$allowedIp = foodscanSetting('allowed_ip', '') ?: $_SERVER['REMOTE_ADDR'];

$visitor_ip = $_SERVER['REMOTE_ADDR'];

// Render the same styled "🔒 Access Denied" wall the delivery and scan kiosks
// show, mirroring auth.php's requireAllowedIP() reasons (network first).
if ($visitor_ip !== $allowedIp) {
    fpRenderAccessDenied('');
}

// Allowed-hours gate (Settings → Secure Network Access). Same schedule the
// FoodScan pages honor, evaluated identically via fsScheduleAllowsNow().
if (!fsScheduleAllowsNow(foodscanSetting('access_schedule', ''))) {
    fpRenderAccessDenied('Access is closed right now. Please try again during the permitted hours.');
}

$stmt = $db->query("SELECT * FROM config_items WHERE active = 1 ORDER BY category, sort_order, id");
$allItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

// PantryPrep visibility gate: any inventory row in openpantry.db that's been
// marked NOT deliverable (checkbox unchecked on the Inventory page) is
// suppressed here. Case-insensitive match on item_name <-> generic_name.
// If openpantry.db isn't reachable or the column is missing, fail open and
// show every item (current behavior).
$nondeliverable = [];
try {
    $fsDb = new PDO('sqlite:' . fsDbPath('openpantry.db'));
    $fsDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    foreach ($fsDb->query("SELECT generic_name FROM inventory WHERE deliverable = 0") as $r) {
        $nondeliverable[strtolower($r['generic_name'])] = true;
    }
} catch (\Throwable $e) {
    // Leave $nondeliverable empty -> nothing gets filtered.
}
if (!empty($nondeliverable)) {
    $allItems = array_values(array_filter($allItems, function ($it) use ($nondeliverable) {
        return !isset($nondeliverable[strtolower($it['item_name'])]);
    }));
}

$categories = [];
foreach ($allItems as $item) {
    $categories[$item['category']][] = $item;
}

$success  = isset($_GET['success']);
$orderId  = (int)($_GET['order_id'] ?? 0);
$errName  = isset($_GET['error']) && $_GET['error'] === 'name';

// Optional "Special Notes to Clients" line (Admin → Manage Items). Blank = hidden.
$clientNotes = trim(menuSetting($db, 'client_notes'));

$weekStart = date('M j', strtotime('monday this week'));
$weekEnd   = date('j',   strtotime('friday this week'));
$weekLabel = $weekStart . ' - ' . $weekEnd;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<link rel="icon" type="image/x-icon" href="favicon.ico">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Footprints – Order Form</title>
<style>
  :root {
    --brown:  #6B4C11;
    --green:  #8BAF3A;
    --light:  #F5F0E8;
    --border: #D4C9A8;
    --text:   #333;
    --cat-bg: #EEE8D5;
    --blue:   #0056b3;
  }
  * { box-sizing: border-box; margin: 0; padding: 0; }
  html { font-size: 162%; } 

  body { font-family: Arial, sans-serif; background: var(--light); color: var(--text); min-height: 100vh; }

  /* ── Header ─────────────────────────────── */
  .site-header {
    background: #fff; border-bottom: 3px solid var(--green);
    padding: 14px 24px; display: flex; align-items: center; gap: 16px;
    box-shadow: 0 2px 6px rgba(0,0,0,.08);
  }
  .site-header img { height: 64px; }
  .header-text h1 { font-size: 1.05rem; color: var(--brown); font-weight: 700; text-transform: uppercase; }
  .header-text p  { font-size: .8rem; color: #777; }

  /* ── Banners ────────────────────────────── */
  .banner {
    max-width: 900px; margin: 20px auto 0; padding: 16px 22px;
    border-radius: 8px; display: flex; align-items: center; gap: 14px;
    opacity: 1; transition: opacity 1s ease;
  }
  .banner.success { background:#D4EDDA; border:1px solid #A8D8B9; color:#276437; }
  .banner.error   { background:#F8D7DA; border:1px solid #F1AEB5; color:#8B1A1A; }
  .banner.validation { background:#E7F3FF; border:1px solid #BADCFF; color:var(--blue); display:none; }
  
  .banner h3      { font-size:.95rem; font-weight:700; margin-bottom:2px; }
  .banner p       { font-size:.85rem; }

  /* ── Translation Widget ─────────────────── */
  .translate-wrap { margin-left: auto; display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
  .translate-wrap label { font-size: .75rem; color: #999; }
  #google_translate_element { display: none !important; }
  #custom_lang_select {
    border: 1px solid var(--border); border-radius: 5px;
    padding: 5px 8px; font-size: .82rem; background: #fff;
    color: var(--brown); cursor: pointer;
    width: 110px; /* Snug fit for 'Español' */
  }

  /* ── Main Layout ────────────────────────── */
  .container { max-width: 900px; margin: 24px auto 40px; padding: 0 16px; }
  .card { background: #fff; border: 1px solid var(--border); border-radius: 10px; overflow: visible; box-shadow: 0 2px 10px rgba(0,0,0,.07); }

  /* ── Special Notes to Clients (set in Admin) ── */
  .client-notes {
    padding: 12px 20px; text-align: center;
    font-family: Georgia, 'Palatino Linotype', 'Book Antiqua', serif;
    font-style: italic; font-size: 1.02rem; font-weight: 600;
    letter-spacing: .2px; color: var(--blue);
    background: #F7FAFF; border-bottom: 1px solid var(--border);
  }

  .order-header { display: flex; flex-direction: column; border-bottom: 2px solid var(--border); }
  .name-cell { padding: 12px 16px; border-bottom: 1px solid var(--border); display: flex; flex-direction: column; justify-content: center; gap: 6px; }
  .name-cell label { font-weight: 700; font-size: .8rem; text-transform: uppercase; color: var(--brown); }
  .name-cell input { border: none; border-bottom: 2px solid var(--border); font-size: 1rem; padding: 2px 4px; outline: none; background: transparent; }
  .name-cell input.error { border-bottom-color: #f44336; background: #fff5f5; }
  
  .stats-row { display: grid; grid-template-columns: 1fr 1fr 1fr; }
  .count-cell { padding: 10px 18px; display: flex; flex-direction: column; align-items: center; justify-content: center; border-right: 1px solid var(--border); }
  .count-cell label { font-size: .72rem; font-weight: 700; text-transform: uppercase; color: var(--brown); margin-bottom: 4px; }
  .week-cell { padding: 10px 18px; display: flex; flex-direction: column; align-items: center; justify-content: center; }

  /* ── Modal & Trigger Styles ──────────────── */
  .subtype-trigger {
    display: flex; align-items: center; justify-content: space-between;
    gap: 8px; width: 100%; margin-top: 6px;
    padding: 12px 14px; border: 2px solid var(--blue);
    border-radius: 8px; background: #fff;
    font-size: 0.9rem; font-weight: 700; font-family: inherit;
    color: var(--blue); cursor: pointer; transition: all .15s;
  }
  .subtype-trigger.has-value { border-color: var(--green); color: var(--brown); }
  .subtype-trigger .st-arrow { font-size: 0.65rem; opacity: .6; }

  .modal-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,0.55); z-index: 10000;
    align-items: center; justify-content: center;
  }
  .modal-overlay.open { display: flex; }
  .modal-box {
    background: #fff; border-radius: 12px;
    box-shadow: 0 8px 32px rgba(0,0,0,.3);
    width: 90%; max-width: 420px;
    overflow: hidden; animation: modalIn .18s ease;
  }
  @keyframes modalIn { from { transform: scale(.92); opacity:0; } to { transform: scale(1); opacity:1; } }
  .modal-header {
    padding: 16px 20px; background: var(--brown); color: #fff;
    font-size: 0.9rem; font-weight: 700; display: flex;
    align-items: center; justify-content: space-between;
  }
  .modal-close { background: none; border: none; color: #fff; font-size: 1.4rem; cursor: pointer; line-height: 1; padding: 0 4px; }
  .modal-options { padding: 10px 0; max-height: 60vh; overflow-y: auto; }
  .modal-option {
    padding: 16px 22px; font-size: 0.9rem; font-weight: 700;
    cursor: pointer; border-bottom: 1px solid #F0EBD8; color: var(--text);
    display: flex; align-items: center; gap: 12px; transition: background .12s;
  }
  .modal-option:last-child { border-bottom: none; }
  .modal-option:hover, .modal-option:active { background: #F5FAE8; }
  .modal-option.selected { background: var(--green); color: #fff; }

  /* ── Item Grid & Rows ──────────────────── */
  .items-grid { display: grid; grid-template-columns: 1fr; }
  .cat-block { padding: 16px 20px; border-bottom: 1px solid var(--border); }
  .cat-block h3 { font-size: .85rem; font-weight: 800; text-transform: uppercase; color: var(--brown); border-bottom: 2px solid var(--cat-bg); margin-bottom: 10px; }
  
  .item-row { margin-bottom: 10px; position: relative; }
  .item-row input[type="checkbox"] { position: absolute; opacity: 0; }
  .item-row label {
    display: flex; align-items: center; justify-content: center;
    padding: 12px 14px; background-color: #fff;
    border: 2px solid var(--border); border-radius: 8px;
    cursor: pointer; font-size: 0.9rem; font-weight: 700; color: var(--text);
    transition: all 0.2s ease; min-height: 50px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    white-space: nowrap; 
  }
  .item-row input[type="checkbox"]:checked + label { background: var(--green); border-color: var(--green); color: #fff; }
  
  .unavail-badge {
    display: inline-block; font-size: .65rem; font-weight: 700; color: #fff;
    background: #6B4C11; border-radius: 4px; padding: 2px 6px; margin-left: 8px;
    vertical-align: middle;
  }

  .item-row.unavailable label { background: #f0f0f0; border-color: #e0e0e0; color: #aaa; cursor: not-allowed; flex-direction: row !important; }
  .item-row.children-only { display: none; }
  .detail-row { margin: 6px 0 12px 10px; display: none; align-items: center; gap: 8px; }
  .detail-row.visible { display: flex; }

  .submit-row { padding: 18px 20px; background: var(--cat-bg); border-top: 2px solid var(--border); }
  .btn-submit { background: #8BAF3A; color: #fff; border: none; border-radius: 7px; padding: 16px; font-size: 1.15rem; font-weight: 700; width: 100%; cursor: pointer; }

  @media (max-width: 600px) {
    .stats-row { grid-template-columns: 1fr; }
    .count-cell { border-right: none; border-bottom: 1px solid var(--border); }
  }
</style>
</head>
<body>

<header class="site-header">
  <img src="../logo.jpg" alt="Logo">
  <div class="header-text">
    <h1>~Menu Counter~</h1>
    <p>Select items and tap DONE</p>
  </div>
  <div class="translate-wrap">
    <label for="custom_lang_select">🌐 Translate:</label>
    <select id="custom_lang_select" onchange="triggerGoogleTranslate(this.value)" translate="no">
      <option value="en">English</option>
      <option value="es">Español</option>
      <option value="pt">Português</option>
      <option value="ar">العربية</option>
      <option value="zh-TW">廣東話</option>
      <option value="fr">Français</option>
      <option value="ht">Kreyòl ayisyen</option>
      <option value="so">Soomaali</option>
      <option value="vi">Tiếng Việt</option>
      <option value="km">ភាសាខ្មែរ</option>
      <option value="ru">Русский</option>
    </select>
    <div id="google_translate_element"></div>
  </div>
</header>

<div class="container">

<?php if ($success): ?>
<div class="banner success" id="successBanner">
  <div style="font-size:1.6rem;">✅</div>
  <div>
    <h3>Order Submitted!</h3>
    <p>Your order #<strong><?= $orderId ?></strong> has been received. Thank you!</p>
  </div>
</div>
<?php endif; ?>

<?php if ($errName): ?>
<div class="banner error">
  <div style="font-size:1.6rem;">⚠️</div>
  <div>
    <h3>Missing Name</h3>
    <p>Please enter your first name before submitting.</p>
  </div>
</div>
<?php endif; ?>

<div class="banner validation" id="validationBanner">
  <div style="font-size:1.6rem;">ℹ️</div>
  <div>
    <h3 id="valTitle">Action Required</h3>
    <p id="valMessage">Tap all — Select — fields marked in blue below and make your selections</p>
  </div>
</div>

<form method="POST" action="submit_order.php" id="orderForm" novalidate>
<input type="hidden" name="week_date" value="<?= htmlspecialchars($weekLabel) ?>">

<div class="card" style="margin-top:20px;">
  <div style="padding: 18px 20px; background: var(--cat-bg); border-bottom: 2px solid var(--border);">
    <button type="submit" class="btn-submit">📋 DONE</button>
  </div>

  <?php if ($clientNotes !== ''): ?>
  <?php // Rendered verbatim — the note is admin-authored and may contain HTML
        // (formatting tags, emoji entities). Do not echo untrusted input here. ?>
  <div class="client-notes"><?= $clientNotes ?></div>
  <?php endif; ?>

  <div class="order-header">
    <div class="name-cell">
      <label for="cust_name">First Name</label>
      <input type="text" id="cust_name" name="name" placeholder="First Name Only" required 
             onfocus="this.placeholder='Use Keyboard Below'" onblur="this.placeholder='First Name Only'">
    </div>
    
    <div class="stats-row">
      <div class="count-cell">
        <label>Adults</label>
        <input type="hidden" id="adults" name="adults" value="1" data-label="Adults" data-opts="1|2|3|4|5|6">
        <button type="button" class="subtype-trigger has-value" id="strig_adults" onclick="openCountModal('adults')">
          <span id="strig_label_adults">1</span>
          <span class="st-arrow">▼</span>
        </button>
      </div>

      <div class="count-cell">
        <label>Children</label>
        <input type="hidden" id="children" name="children" value="0" data-label="Children" data-opts="0|1|2|3|4|5|6">
        <button type="button" class="subtype-trigger has-value" id="strig_children" onclick="openCountModal('children')">
          <span id="strig_label_children">0</span>
          <span class="st-arrow">▼</span>
        </button>
      </div>

      <div class="week-cell">
        <div style="font-size:.65rem; color:#888;">Week</div>
        <div style="font-size:.85rem; font-weight:700; color:var(--brown);"><?= htmlspecialchars($weekLabel) ?></div>
      </div>
    </div>
  </div>

  <div class="items-grid">
  <?php
    $orderedCats = ['DAIRY', 'DRY GOODS', 'FROZEN ITEMS', 'SPECIALS', 'PRODUCE', 'OTHER ITEMS'];
    foreach ($orderedCats as $catName):
        if (!isset($categories[$catName])) continue;
  ?>
    <div class="cat-block">
      <h3><?= htmlspecialchars($catName) ?></h3>
      <?php foreach ($categories[$catName] as $item): ?>
      <div class="item-row<?= !empty($item['unavailable']) ? ' unavailable' : '' ?><?= !empty($item['use_children']) && empty($item['use_adults']) ? ' children-only' : '' ?>" 
           data-item-row="<?= $item['id'] ?>" <?= !empty($item['use_children']) && empty($item['use_adults']) ? 'data-children-only="1"' : '' ?>>
        <input type="checkbox" id="item_<?= $item['id'] ?>" name="item_<?= $item['id'] ?>" value="1" 
               data-item-id="<?= $item['id'] ?>" <?= $item['has_detail'] ? 'data-has-detail="1"' : '' ?>
               <?= !empty($item['unavailable']) ? 'disabled' : '' ?>>
        <label for="item_<?= $item['id'] ?>">
            <?= htmlspecialchars($item['item_name']) ?>
            <?php if (!empty($item['unavailable'])): ?>
                <span class="unavail-badge">Unavailable</span>
            <?php endif; ?>
        </label>
      </div>

      <?php if ($item['has_detail']): ?>
      <div class="detail-row" id="detail_row_<?= $item['id'] ?>">
        <input type="hidden" id="detail_<?= $item['id'] ?>" name="detail_<?= $item['id'] ?>" value=""
               data-label="<?= htmlspecialchars($item['detail_label']) ?>"
               data-item-name="<?= htmlspecialchars($item['item_name']) ?>"
               data-opts="<?= htmlspecialchars(implode('|', array_filter(array_map('trim', explode(',', $item['size_options'] ?? ''))))) ?>">
        <button type="button" class="subtype-trigger" id="strig_<?= $item['id'] ?>" onclick="openSubtypeModal('<?= $item['id'] ?>')">
          <?php
            $opts = array_filter(array_map('trim', explode(',', $item['size_options'] ?? '')));
            $first = !empty($opts) ? reset($opts) : '';
            $displayText = $item['detail_label'] . ($first ? ': ' . $first : '');
          ?>
          <span id="strig_label_<?= $item['id'] ?>"><?= htmlspecialchars($displayText) ?></span>
          <span class="st-arrow">▼</span>
        </button>
      </div>
      <?php endif; ?>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
  </div>

  <div class="submit-row">
    <button type="submit" class="btn-submit">📋 DONE</button>
  </div>
</div>
</form>
</div>

<div class="modal-overlay" id="subtypeModal">
  <div class="modal-box">
    <div class="modal-header">
      <span id="modalTitle">Select</span>
      <button class="modal-close" onclick="closeSubtypeModal()">✕</button>
    </div>
    <div class="modal-options" id="modalOptions"></div>
  </div>
</div>

<script>
// 1. Translation & Initialization
function googleTranslateElementInit() {
  new google.translate.TranslateElement({pageLanguage: 'en'}, 'google_translate_element');
  var timer = setInterval(function() {
    var gtSel = document.querySelector('#google_translate_element select');
    if (!gtSel) return;
    clearInterval(timer);
    var saved = localStorage.getItem('fp_lang');
    if (saved && saved !== 'en') {
      document.getElementById('custom_lang_select').value = saved;
      gtSel.value = saved;
      gtSel.dispatchEvent(new Event('change'));
    }
  }, 500);
}

function triggerGoogleTranslate(lang) {
  localStorage.setItem('fp_lang', lang);
  // Nudging the hidden widget select with a change event is unreliable once a
  // translation is already active, so instead persist the choice in the
  // `googtrans` cookie Google Translate reads on page load, then reload.
  // Clear every variant the widget may have set (with/without domain).
  var kill = 'googtrans=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT';
  document.cookie = kill;
  document.cookie = kill + '; domain=' + location.hostname;
  document.cookie = kill + '; domain=.' + location.hostname;
  if (lang !== 'en') {
    document.cookie = 'googtrans=/en/' + lang + '; path=/';
  }
  location.reload();
}

// 2. Banner Logic
document.addEventListener('DOMContentLoaded', function() {
  var sBanner = document.getElementById('successBanner');
  if (sBanner) {
    setTimeout(function() {
      sBanner.style.opacity = '0'; 
      setTimeout(function(){ sBanner.style.display='none'; }, 1000);
    }, 5000);
  }
});

// 3. Form Logic
function updateChildrenOnly() {
  var count = parseInt(document.getElementById('children').value) || 0;
  document.querySelectorAll('[data-children-only]').forEach(row => {
    row.style.display = (count > 0) ? 'block' : 'none';
    if (count === 0) {
       var cb = row.querySelector('input');
       if(cb) cb.checked = false;
    }
  });
}

document.querySelectorAll('[data-has-detail]').forEach(cb => {
  cb.addEventListener('change', function() {
    var itemId = this.dataset.itemId;
    var row = document.getElementById('detail_row_' + itemId);
    var hidden = document.getElementById('detail_' + itemId);
    var trigger = document.getElementById('strig_' + itemId);
    var lbl = document.getElementById('strig_label_' + itemId);

    row.classList.toggle('visible', this.checked);

    if (this.checked) {
      var opts = hidden ? hidden.getAttribute('data-opts').split('|').filter(Boolean) : [];
      if (opts.length > 0) {
        hidden.value = opts[0];
        lbl.textContent = hidden.getAttribute('data-label') + ': ' + opts[0];
        trigger.classList.add('has-value');
      }
      openSubtypeModal(itemId);
    }
  });
});

document.getElementById('cust_name').addEventListener('input', function() {
  if (this.value.trim().length > 0) {
    document.getElementById('validationBanner').style.display = 'none';
    this.classList.remove('error');
  }
});

document.getElementById('orderForm').addEventListener('submit', function(e) {
  var nameField = document.getElementById('cust_name');
  var checked = document.querySelectorAll('.items-grid input[type="checkbox"]:checked');
  var valBanner = document.getElementById('validationBanner');
  var valMsg = document.getElementById('valMessage');
  
  if (!nameField.value.trim()) {
    e.preventDefault();
    valMsg.textContent = "Please enter your First Name to continue";
    valBanner.style.display = 'flex';
    nameField.classList.add('error'); 
    window.scrollTo({ top: 0, behavior: 'smooth' });
    return;
  }

  if (checked.length === 0) { 
    e.preventDefault();
    valMsg.textContent = "Please select at least one item for your order";
    valBanner.style.display = 'flex';
    window.scrollTo({ top: 0, behavior: 'smooth' });
    return; 
  }
  
  var missingDropdown = false;
  checked.forEach(cb => {
    var detail = document.getElementById('detail_' + cb.dataset.itemId);
    if (detail && !detail.value) missingDropdown = true;
  });

  if (missingDropdown) { 
    e.preventDefault(); 
    valMsg.textContent = "Tap — Select — fields marked in blue to complete selections";
    valBanner.style.display = 'flex';
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }
});

// 4. Modal Logic
function openSubtypeModal(itemId) {
  var hidden = document.getElementById('detail_' + itemId);
  var label = hidden ? hidden.getAttribute('data-label') : 'Select';
  var itemName = hidden ? hidden.getAttribute('data-item-name') : '';
  var opts = hidden ? hidden.getAttribute('data-opts').split('|').filter(Boolean) : [];
  var current = hidden ? hidden.value : (opts.length ? opts[0] : '');

  document.getElementById('modalTitle').textContent = (itemName ? itemName + ' ' : '') + label;
  var container = document.getElementById('modalOptions');
  container.innerHTML = '';

  opts.forEach(function(opt) {
    var div = document.createElement('div');
    div.className = 'modal-option' + (opt === current ? ' selected' : '');
    div.innerHTML = '<span class="mo-icon">' + (opt === current ? '✓' : '○') + '</span>' + opt;
    div.onclick = function() { pickSubtypeOption(itemId, opt); };
    container.appendChild(div);
  });
  document.getElementById('subtypeModal').classList.add('open');
}

function openCountModal(field) {
  var hidden = document.getElementById(field);
  var opts = hidden ? hidden.getAttribute('data-opts').split('|') : [];
  var current = hidden ? hidden.value : opts[0];
  document.getElementById('modalTitle').textContent = (field === 'adults' ? 'Adults' : 'Children');
  var container = document.getElementById('modalOptions');
  container.innerHTML = '';
  opts.forEach(function(opt) {
    var div = document.createElement('div');
    div.className = 'modal-option' + (opt === current ? ' selected' : '');
    div.innerHTML = '<span class="mo-icon">' + (opt === current ? '✓' : '○') + '</span>' + opt;
    div.onclick = function() { pickSubtypeOption(field, opt); };
    container.appendChild(div);
  });
  document.getElementById('subtypeModal').classList.add('open');
}

function pickSubtypeOption(itemId, value) {
  var isCount = (itemId === 'adults' || itemId === 'children');
  var hidden = document.getElementById(isCount ? itemId : 'detail_' + itemId);
  var trigger = document.getElementById('strig_' + itemId);
  var lbl = document.getElementById('strig_label_' + itemId);
  
  if (hidden) hidden.value = value;
  if (lbl) {
    if (isCount) lbl.textContent = value;
    else lbl.textContent = hidden.getAttribute('data-label') + ': ' + value;
  }
  if (trigger) trigger.classList.add('has-value');
  if (itemId === 'children') updateChildrenOnly();
  closeSubtypeModal();
}

function closeSubtypeModal() {
  document.getElementById('subtypeModal').classList.remove('open');
}
</script>
<script src="//translate.google.com/translate_a/element.js?cb=googleTranslateElementInit"></script>
</body>
</html>