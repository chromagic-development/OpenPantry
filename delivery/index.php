<?php
// Delivery menu form. Staff fills in customer details, picks items from
// what's currently in stock (config_items ∪ inventory), and clicks "DONE".
// That logs a closed order in openpantry.db (order number + one scans row
// per item, inventory decremented) and reloads the page so the rotation
// advances to the next pending client. Customer name/address/city are NOT
// persisted — only the order number, items, and counts.
//
// The DONE button does NOT print anything. Per-client Packing & Delivery
// Lists are printed in bulk later from client/client.php via the
// "🧾 Print Packing & Delivery Lists" button, which pulls the persisted
// scan rows for each delivered client.
$GLOBALS['FS_PREFIX'] = '../';
require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/db.php';
requireAllowedIP();

$db   = getDB();           // openpantry.db
$pdb  = picklistDB();      // picklist.db (read-only; may be null)

$items     = buildDeliveryItems($db, $pdb);
$catOrder  = deliveryCategoryOrder($items);
$totalCnt  = array_sum(array_map('count', $items));

$cities = deliveryCities();

// Saved clients still awaiting a delivery this round: enabled, not yet printed.
// The form cycles through these to pre-fill the "New Delivery" fields.
$clients = array_map('fsDecryptClientFields', $db->query(
    "SELECT id, name, adults, children, grp, address, city, phone, volunteer
       FROM delivery_clients
      WHERE enabled = 1 AND delivered_at IS NULL
      ORDER BY sort_order, id"
)->fetchAll());

// Group options for the kiosk picker: the admin-managed list, plus any group
// a pending client carries that was later removed from the list — otherwise
// loading that client couldn't pre-select their group and the delivery would
// submit the wrong one.
$kioskGroups = deliveryGroups();
foreach ($clients as $c) {
    if ($c['grp'] !== '' && !in_array($c['grp'], $kioskGroups, true)) {
        $kioskGroups[] = $c['grp'];
    }
}

// Same idea for cities: include any pending client's city that was later
// removed from the list, so loading that client can pre-select it.
$kioskCities = deliveryCities();
foreach ($clients as $c) {
    $cc = (string)($c['city'] ?? '');
    if ($cc !== '' && !in_array($cc, $kioskCities, true)) {
        $kioskCities[] = $cc;
    }
}

renderHead('Deliveries');
// Menu/subnav intentionally omitted — the delivery menu is a focused kiosk flow.
?>
<div class="container">

<div id="formError" class="banner error" style="display:none;"></div>

<form method="POST" action="submit_delivery.php" id="deliveryForm" novalidate>
  <input type="hidden" id="client_id" name="client_id" value="">

  <!-- Customer + group ------------------------------------------------ -->
  <div class="card">
    <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:6px;">
      <h2 style="margin:0; border:0; padding:0;">New Delivery</h2>
    </div>

    <!-- Client rotation navigator: steps through enabled, not-yet-printed
         saved clients and pre-fills the fields below. Hidden when none remain. -->
    <div id="clientNav" class="dp-client-nav" style="display:none;">
      <button type="button" id="clientPrev" class="btn btn-secondary dp-nav-btn" aria-label="Previous client">◀</button>
      <div class="dp-nav-info">
        <div class="dp-nav-name" id="clientNavName">—</div>
        <div class="dp-nav-pos" id="clientNavPos"></div>
      </div>
      <button type="button" id="clientNext" class="btn btn-secondary dp-nav-btn" aria-label="Next client">▶</button>
    </div>

    <div class="row" style="align-items:end;">
      <div style="flex:2 1 200px;">
        <label for="cust_name">First Name</label>
        <input type="text" id="cust_name" name="name" required autocomplete="off" placeholder="First name only">
      </div>
      <div style="flex:1 1 110px;">
        <label for="adults">Adults</label>
        <select id="adults" name="adults">
          <?php for ($i = 1; $i <= 6; $i++): ?>
            <option value="<?= $i ?>"<?= $i === 1 ? ' selected' : '' ?>><?= $i ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div style="flex:1 1 110px;">
        <label for="children">Children</label>
        <select id="children" name="children" onchange="updateChildrenOnly()">
          <?php for ($i = 0; $i <= 6; $i++): ?>
            <option value="<?= $i ?>"<?= $i === 0 ? ' selected' : '' ?>><?= $i ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="dp-group-col">
        <label for="groupSelect">Group</label>
        <select id="groupSelect" name="group" class="dp-group-select">
          <?php foreach ($kioskGroups as $g): ?>
            <option value="<?= htmlspecialchars($g) ?>"><?= htmlspecialchars($g) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="row" style="margin-top:14px;">
      <div style="flex:3 1 280px;">
        <label for="address">Address</label>
        <input type="text" id="address" name="address" required autocomplete="off" placeholder="Street">
      </div>
      <div style="flex:1 1 200px;">
        <label for="city">City</label>
        <select id="city" name="city" required>
          <option value="" selected disabled>Select city…</option>
          <?php foreach ($kioskCities as $c): ?>
            <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="flex:1 1 160px;">
        <label for="phone">Phone</label>
        <input type="text" id="phone" name="phone" required autocomplete="off" placeholder="(555) 123-4567">
      </div>
    </div>

    <div class="row" style="margin-top:14px;">
      <div style="flex:1 1 280px;">
        <label for="volunteer">Volunteer
          <span style="font-weight:400; color:#777; text-transform:none; font-size:.75rem;">
            (optional)
          </span>
        </label>
        <input type="text" id="volunteer" name="volunteer" autocomplete="off"
               placeholder="Name of the volunteer assigned to this client">
      </div>
    </div>
  </div>

  <!-- Items ----------------------------------------------------------- -->
  <div class="card">
    <div class="dp-items-header">
      <h2 style="margin:0;">Available Items</h2>
      <input type="search" id="itemSearch" class="dp-search"
             placeholder="🔍 Filter items by name…" oninput="applyVisibility()" autocomplete="off">
      <span id="itemCount" style="font-size:.8rem; color:#777;"><?= $totalCnt ?> in stock</span>
    </div>

    <?php if ($totalCnt === 0): ?>
      <p style="color:#777; padding:14px 0;">No items currently in stock. Add inventory before taking deliveries.</p>
    <?php endif; ?>

    <?php foreach ($catOrder as $cat): ?>
      <div class="dp-cat" data-category="<?= htmlspecialchars($cat) ?>">
        <h3 class="dp-cat-h"><?= htmlspecialchars($cat) ?></h3>
        <div class="dp-grid">
          <?php foreach ($items[$cat] as $idx => $it):
            $rid = 'opt_' . preg_replace('/[^A-Za-z0-9]/', '_', $it['key']) . '_' . $idx;
            $kidsOnly = !empty($it['has_factor']) && $it['use_children'] && !$it['use_adults'];
            // Include the size options in the search text so e.g. "salted"
            // still finds the "Butter" item.
            $searchText = strtolower($it['name'] . (empty($it['sizes']) ? '' : ' ' . implode(' ', $it['sizes'])));
          ?>
            <div class="dp-item"
                 data-name="<?= htmlspecialchars($searchText) ?>"
                 <?= $kidsOnly ? 'data-kids-only="1" style="display:none;"' : '' ?>
                 <?= !empty($it['has_detail']) ? 'data-has-size="1"' : '' ?>>
              <input type="checkbox" id="<?= $rid ?>" name="item[]"
                     value="<?= htmlspecialchars($it['key']) ?>">
              <label for="<?= $rid ?>"><?= htmlspecialchars($it['name']) ?></label>
              <?php if (!empty($it['has_detail'])): ?>
                <select class="dp-size" name="size[<?= htmlspecialchars($it['key']) ?>]"
                        aria-label="<?= htmlspecialchars($it['detail_label']) ?>">
                  <?php foreach ($it['sizes'] as $sz): ?>
                    <option value="<?= htmlspecialchars($sz) ?>"><?= htmlspecialchars($it['detail_label'] . ': ' . $sz) ?></option>
                  <?php endforeach; ?>
                </select>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>

    <div class="dp-submit-row">
      <!-- Mirrors the PantryPrep counter form's "DONE" button: the click
           POSTs the order silently (no packing-list overlay, no print
           dialog) and reloads so the rotation advances to the next
           pending client. Each client's packing list is printed later
           in bulk from client/client.php via "Print Packing & Delivery
           Lists" — so this button is just a fast handoff between
           deliveries. -->
      <button type="submit" id="doneBtn" class="dp-done-btn">📋 DONE</button>
    </div>
  </div>
</form>
</div>

<style>
  /* Client rotation navigator */
  .dp-client-nav {
    display: flex; align-items: center; gap: 12px;
    background: var(--cat-bg); border: 1px solid var(--border);
    border-radius: 10px; padding: 10px 14px; margin: 4px 0 16px;
  }
  .dp-nav-btn {
    flex: 0 0 auto; min-width: 56px; font-size: 1.3rem; padding: 10px 14px;
  }
  .dp-nav-info { flex: 1 1 auto; text-align: center; line-height: 1.2; }
  .dp-nav-name { font-size: 1.15rem; font-weight: 800; color: var(--brown); }
  .dp-nav-pos { font-size: .75rem; color: #777; text-transform: uppercase; letter-spacing: .5px; }

  /* Locked client fields (populated from the saved roster, edited via the
     client manager). Read-only inputs and disabled selects share one look. */
  #deliveryForm input[readonly],
  #deliveryForm select:disabled {
    background: #efe9dd; color: var(--text); cursor: not-allowed;
    opacity: 1; -webkit-text-fill-color: var(--text);
  }

  .dp-group-col { flex: 0 0 200px; }
  .dp-group-select {
    width: 100%;
    font-size: 2.4rem !important;
    font-weight: 800 !important;
    text-align: center;
    text-align-last: center;
    color: var(--brown);
    height: auto;
    padding: 6px 14px !important;
    background: #fff;
  }

  .dp-items-header {
    display:flex; align-items:center; gap:14px; flex-wrap: wrap;
    padding-bottom: 10px; border-bottom: 2px solid var(--cat-bg); margin-bottom: 12px;
  }
  .dp-search { flex: 1 1 240px; max-width: 360px; }

  .dp-cat { margin-top: 16px; }
  .dp-cat-h {
    font-size: .85rem; font-weight: 800; text-transform: uppercase;
    color: var(--brown); border-bottom: 2px solid var(--cat-bg);
    padding-bottom: 4px; margin-bottom: 10px;
  }

  .dp-grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 10px; align-items: start;
  }
  .dp-item { position: relative; display: flex; flex-direction: column; gap: 6px; }
  .dp-item input[type="checkbox"] { position: absolute; opacity: 0; }
  .dp-item label {
    display: flex; align-items: center; justify-content: center;
    padding: 12px 14px; background: #fff;
    border: 2px solid var(--border); border-radius: 8px;
    cursor: pointer; font-size: .9rem; font-weight: 700; color: var(--text);
    transition: all .15s; min-height: 50px; text-align: center;
    box-shadow: 0 1px 3px rgba(0,0,0,.05);
  }
  .dp-item input[type="checkbox"]:checked + label {
    background: var(--green); border-color: var(--green); color: #fff;
  }
  .dp-size { display: none; font-size: .85rem; padding: 8px; }

  .dp-submit-row {
    padding: 18px 20px 0; margin-top: 14px;
    border-top: 2px solid var(--border);
  }
  /* PantryPrep-style DONE button: full-width green pill with a clipboard
     icon. Matches `.btn-submit` over in foodscan/menucounter/index.php so
     both order forms feel like the same control. */
  .dp-done-btn {
    background: var(--green); color: #fff;
    border: none; border-radius: 7px;
    padding: 16px; font-size: 1.15rem; font-weight: 700;
    width: 100%; cursor: pointer;
  }
  .dp-done-btn:disabled { opacity: .6; cursor: progress; }
</style>

<script>
function esc(s) {
  return String(s == null ? '' : s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

// ── Client rotation ───────────────────────────────────────────────────────
// Enabled clients that haven't yet been delivered this round. The navigator
// steps through them, pre-filling the form; the hidden client_id is posted
// so submit_delivery.php can mark that client delivered. Clicking DONE
// reloads the page, which then auto-loads the next pending client (the
// just-delivered one is no longer in the list).
var DP_CLIENTS = <?= json_encode($clients, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
var dpClientIdx = 0;

function setSelectValue(id, value) {
  var sel = document.getElementById(id);
  if (!sel) return;
  var v = String(value);
  for (var i = 0; i < sel.options.length; i++) {
    if (sel.options[i].value === v) { sel.value = v; return; }
  }
}

// Customer fields that come from the saved client roster. When a client is
// loaded these are locked: text inputs become readonly (still submit) and
// selects become disabled (re-enabled just before submit so they POST too).
// Editing a client's details is done in the client manager, not here.
var DP_LOCK_INPUTS  = ['cust_name', 'address', 'phone', 'volunteer'];
var DP_LOCK_SELECTS = ['adults', 'children', 'groupSelect', 'city'];

function lockClientFields(locked) {
  DP_LOCK_INPUTS.forEach(function (id) {
    var el = document.getElementById(id);
    if (el) el.readOnly = locked;
  });
  DP_LOCK_SELECTS.forEach(function (id) {
    var el = document.getElementById(id);
    if (el) el.disabled = locked;
  });
}

function populateClient(idx) {
  if (!DP_CLIENTS.length) return;
  if (idx < 0) idx = 0;
  if (idx > DP_CLIENTS.length - 1) idx = DP_CLIENTS.length - 1;
  dpClientIdx = idx;
  var c = DP_CLIENTS[idx];

  document.getElementById('client_id').value = c.id;
  document.getElementById('cust_name').value = c.name || '';
  document.getElementById('address').value   = c.address || '';
  document.getElementById('phone').value     = c.phone || '';
  document.getElementById('volunteer').value = c.volunteer || '';
  setSelectValue('adults',   c.adults);
  setSelectValue('children', c.children);
  setSelectValue('groupSelect', c.grp);
  setSelectValue('city', c.city || '');

  document.getElementById('clientNavName').textContent = c.name || '(unnamed)';
  document.getElementById('clientNavPos').textContent  = 'Client ' + (idx + 1) + ' of ' + DP_CLIENTS.length;
  document.getElementById('clientPrev').disabled = (idx === 0);
  document.getElementById('clientNext').disabled = (idx === DP_CLIENTS.length - 1);

  // Children count drives which kids-only items are visible.
  if (typeof updateChildrenOnly === 'function') updateChildrenOnly();

  // Lock the populated client fields — they're edited in the client manager.
  lockClientFields(true);
}

function initClientNav() {
  if (!DP_CLIENTS.length) return;          // no saved clients pending — manual entry
  document.getElementById('clientNav').style.display = 'flex';
  document.getElementById('clientPrev').addEventListener('click', function () { populateClient(dpClientIdx - 1); });
  document.getElementById('clientNext').addEventListener('click', function () { populateClient(dpClientIdx + 1); });
  populateClient(0);
}

// Single source of truth for item visibility: an item shows when it matches
// the search box AND (it isn't a children-only item, or Children > 0).
function applyVisibility() {
  var q = (document.getElementById('itemSearch').value || '').trim().toLowerCase();
  var childrenN = parseInt(document.getElementById('children').value, 10) || 0;
  var perCat = {};
  document.querySelectorAll('.dp-item').forEach(function (it) {
    var kidsOnly = it.hasAttribute('data-kids-only');
    var hideForKids = kidsOnly && childrenN === 0;
    var name = it.getAttribute('data-name') || '';
    var matchesSearch = !q || name.indexOf(q) !== -1;
    var visible = !hideForKids && matchesSearch;
    it.style.display = visible ? '' : 'none';
    if (hideForKids) {
      var cb = it.querySelector('input[type="checkbox"]');
      if (cb) cb.checked = false;
      var sel = it.querySelector('.dp-size');
      if (sel) sel.style.display = 'none';
    }
    if (visible) {
      var cat = it.closest('.dp-cat').getAttribute('data-category');
      perCat[cat] = (perCat[cat] || 0) + 1;
    }
  });
  document.querySelectorAll('.dp-cat').forEach(function (c) {
    var cat = c.getAttribute('data-category');
    c.style.display = perCat[cat] ? '' : 'none';
  });
  var total = Object.keys(perCat).reduce(function (s, k) { return s + perCat[k]; }, 0);
  document.getElementById('itemCount').textContent = q ? (total + ' matching') : (total + ' in stock');
}

// Children select change re-evaluates which kids-only items are shown.
function updateChildrenOnly() { applyVisibility(); }

// Reveal the size dropdown when an item with sizes is selected.
document.querySelectorAll('.dp-item[data-has-size] input[type="checkbox"]').forEach(function (cb) {
  cb.addEventListener('change', function () {
    var sel = this.closest('.dp-item').querySelector('.dp-size');
    if (sel) sel.style.display = this.checked ? 'block' : 'none';
  });
});

// Inline validation messaging — shown at the top of the form, no popups.
function showFormError(msg) {
  var b = document.getElementById('formError');
  b.textContent = '⚠ ' + msg;
  b.style.display = 'flex';
  window.scrollTo({ top: 0, behavior: 'smooth' });
}
function clearFormError() {
  var b = document.getElementById('formError');
  b.style.display = 'none';
  b.textContent = '';
}

function validateForm() {
  clearFormError();
  var required = [
    ['cust_name', 'first name'],
    ['address',   'address'],
    ['city',      'city'],
    ['phone',     'phone number'],
  ];
  for (var i = 0; i < required.length; i++) {
    var el = document.getElementById(required[i][0]);
    if (!el.value.trim()) {
      showFormError('Please enter the ' + required[i][1] + '.');
      el.focus();
      return false;
    }
  }
  if (document.querySelectorAll('input[name="item[]"]:checked').length === 0) {
    showFormError('Select at least one item before submitting.');
    return false;
  }
  return true;
}

// DONE submit: POST silently to submit_delivery.php (which writes the order,
// scans rows, decrements inventory, and stamps delivered_at on the saved
// client), then reload the page. The just-delivered client drops out of
// the rotation server-side, so the reload naturally lands on the next
// pending client. No packing-list overlay and no print dialog — those
// lists are printed in bulk later via client/client.php → "Print Packing
// & Delivery Lists".
document.getElementById('deliveryForm').addEventListener('submit', function (e) {
  e.preventDefault();
  if (!validateForm()) return;
  var btn = document.getElementById('doneBtn');
  btn.disabled = true;
  btn.textContent = 'Saving…';
  // Disabled <select>s don't POST — re-enable the locked client selects so the
  // group/adults/children/city values are included in the submission.
  DP_LOCK_SELECTS.forEach(function (id) {
    var el = document.getElementById(id);
    if (el) el.disabled = false;
  });
  var fd = new FormData(this);
  fetch('submit_delivery.php', { method: 'POST', body: fd })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (!data.ok) {
        btn.disabled = false;
        btn.textContent = '📋 DONE';
        showFormError(data.error || 'Could not save the delivery.');
        return;
      }
      clearFormError();
      // Server-side rotation has advanced — reload to pick up the new pending list.
      window.location.href = 'index.php';
    })
    .catch(function (err) {
      btn.disabled = false;
      btn.textContent = '📋 DONE';
      showFormError('Network error: ' + err.message);
    });
});

// Initial state: children defaults to 0, so kids-only items start hidden
// (also rendered with inline display:none server-side to avoid a flash).
applyVisibility();

// Load the first pending saved client (if any) into the form.
initClientNav();
</script>
<?php renderFoot(); ?>
