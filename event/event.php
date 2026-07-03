<?php
// Event consumption form. Staff picks an event type (Breakfast Cafe /
// Community Supper / Meal Prep / Mainspring Cooks) and their initials,
// then filters in-stock inventory by partial name, enters a count per
// item, and clicks "Use for event" to stage that line. Repeating builds
// a list of staged lines; clicking DONE submits the batch, which writes
// one `orders` row tagged "EVENT · <type> · <initials>" plus one `scans`
// row per line and decrements inventory accordingly. Mirrors the
// delivery/ flow: the orders/scans rows are what makes events appear
// in orders_listing (with an event pill) and volume_report.
$GLOBALS['FS_PREFIX'] = '../';
require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/event_types.php';
requireLogin();

$db = getDB();

// All in-stock inventory. Events draw from the same pool the delivery
// kiosk uses (count > 0), but unlike deliveries we ignore the
// `deliverable` flag — events are an internal-use channel, not a
// customer order form, so an item being hidden from the PantryPrep
// counter shouldn't hide it from event consumption tracking.
$items = $db->query(
    "SELECT generic_name, count, unit FROM inventory
      WHERE count > 0
      ORDER BY generic_name COLLATE NOCASE"
)->fetchAll();

renderHead('Events');
renderNav('event');
?>
<div class="container">

<div id="formError" class="banner error" style="display:none;"></div>

<form method="POST" action="submit_event.php" id="eventForm" novalidate>

  <!-- Event header: type + initials --------------------------------- -->
  <div class="card">
    <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:6px;">
      <h2 style="margin:0; border:0; padding:0;">New Event</h2>
    </div>

    <div class="row" style="align-items:end;">
      <div style="flex:3 1 320px;">
        <label for="event_type">Event</label>
        <select id="event_type" name="event_type" class="ev-type-select" required>
          <option value="" selected disabled>Select event…</option>
          <?php foreach (eventTypes() as $t): ?>
            <option value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="flex:1 1 160px;">
        <label for="initials">Initials</label>
        <input type="text" id="initials" name="initials" required autocomplete="off"
               maxlength="8" placeholder="e.g. JD" style="text-transform:uppercase;">
      </div>
    </div>
  </div>

  <!-- Items picker + staged-lines list ------------------------------ -->
  <div class="card">
    <div class="ev-items-header">
      <h2 style="margin:0;">Items In Stock</h2>
      <input type="search" id="itemSearch" class="ev-search"
             placeholder="🔍 Type to filter items by name…" oninput="renderMatches()" autocomplete="off" autofocus>
      <span id="itemCount" style="font-size:.8rem; color:#777;"></span>
    </div>

    <?php if (!$items): ?>
      <p style="color:#777; padding:14px 0;">No items currently in stock.</p>
    <?php endif; ?>

    <!-- Matched items appear here as filter text is typed. -->
    <div id="matchList" class="ev-match-list"></div>

    <!-- Running list of staged lines. Each click of "Use for event"
         adds a row here; clicking the × removes it from the batch. -->
    <div id="stagedBox" style="display:none;">
      <h3 class="ev-staged-h">Staged for this event</h3>
      <table class="data ev-staged-table">
        <thead>
          <tr>
            <th>Item</th>
            <th class="num">Count</th>
            <th>Unit</th>
            <th></th>
          </tr>
        </thead>
        <tbody id="stagedRows"></tbody>
      </table>
    </div>

    <div class="ev-submit-row">
      <!-- DONE: mirrors the delivery/index.php style. POSTs all staged
           lines to submit_event.php, which writes the order + scans +
           inventory decrement in a single transaction. -->
      <button type="submit" id="doneBtn" class="ev-done-btn">📋 DONE</button>
    </div>
  </div>
</form>
</div>

<style>
  /* Big event-type picker, matching the spirit of the delivery group
     selector — staff should see at a glance which event they're logging. */
  .ev-type-select {
    width: 100%;
    font-size: 1.6rem !important;
    font-weight: 800 !important;
    text-align: center;
    text-align-last: center;
    color: var(--brown);
    padding: 8px 14px !important;
    background: #fff;
  }

  .ev-items-header {
    display:flex; align-items:center; gap:14px; flex-wrap: wrap;
    padding-bottom: 10px; border-bottom: 2px solid var(--cat-bg); margin-bottom: 12px;
  }
  .ev-search { flex: 1 1 240px; max-width: 420px; }

  /* One row per filter-matched item: name on the left, count input,
     read-only unit chip, and "Use for event" button on the right. */
  .ev-match-list { display:flex; flex-direction:column; gap: 8px; }
  .ev-match-row {
    display:flex; align-items:center; gap:10px; flex-wrap:wrap;
    padding: 10px 12px; border: 1px solid var(--border); border-radius: 8px;
    background: #fff;
  }
  .ev-match-name { flex: 1 1 200px; font-weight: 700; color: var(--text); }
  .ev-match-name .in-stock {
    font-weight: 400; color: #777; font-size: .8rem; margin-left: 6px;
  }
  .ev-match-row input.ev-count {
    flex: 0 0 90px; text-align: right;
  }
  .ev-match-row .ev-unit {
    flex: 0 0 56px; padding: 6px 10px;
    background: #efe9dd; border: 1px solid var(--border); border-radius: 6px;
    text-align: center; font-size: .85rem; color: var(--text);
    text-transform: lowercase;
  }
  .ev-match-row .ev-use-btn {
    flex: 0 0 auto;
    padding: 8px 14px; font-size: .9rem; font-weight: 700;
    background: var(--brown); color: #fff;
    border: none; border-radius: 6px; cursor: pointer;
  }
  .ev-match-row .ev-use-btn:disabled { opacity: .5; cursor: not-allowed; }

  .ev-empty-hint { padding: 18px 4px; color: #999; font-style: italic; }

  .ev-staged-h {
    font-size: .85rem; font-weight: 800; text-transform: uppercase;
    color: var(--brown); border-bottom: 2px solid var(--cat-bg);
    padding-bottom: 4px; margin: 20px 0 10px;
  }
  .ev-staged-table .ev-remove {
    background: none; border: none; color: #8B1A1A; cursor: pointer;
    font-size: 1.1rem; padding: 2px 8px;
  }

  .ev-submit-row {
    padding: 18px 20px 0; margin-top: 14px;
    border-top: 2px solid var(--border);
  }
  /* PantryPrep-style DONE button — same green pill as delivery so both
     order forms feel like the same control. */
  .ev-done-btn {
    background: var(--green); color: #fff;
    border: none; border-radius: 7px;
    padding: 16px; font-size: 1.15rem; font-weight: 700;
    width: 100%; cursor: pointer;
  }
  .ev-done-btn:disabled { opacity: .6; cursor: progress; }
</style>

<script>
// In-stock inventory snapshot. Source of truth for the filter list; the
// server will re-validate against the current inventory at submit time
// so a stale snapshot can't oversell stock.
var EV_ITEMS = <?= json_encode(array_map(function ($r) {
    return [
        'name'  => $r['generic_name'],
        'unit'  => $r['unit'],
        'count' => (float)$r['count'],
    ];
}, $items), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

// Lines the user has clicked "Use for event" on. Each entry:
//   { name, unit, count }
// On DONE these are POSTed as item_name[]=...&item_count[]=...&item_unit[]=...
var staged = [];

function esc(s) {
  return String(s == null ? '' : s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

// Render the filter-matched item rows. Empty filter shows a hint instead
// of dumping the entire inventory — typing narrows the list.
function renderMatches() {
  var q = (document.getElementById('itemSearch').value || '').trim().toLowerCase();
  var list = document.getElementById('matchList');
  var counter = document.getElementById('itemCount');
  list.innerHTML = '';
  if (!EV_ITEMS.length) { counter.textContent = ''; return; }
  if (!q) {
    list.innerHTML = '<div class="ev-empty-hint">Type to search the ' +
                     EV_ITEMS.length + ' in-stock item(s)…</div>';
    counter.textContent = EV_ITEMS.length + ' in stock';
    return;
  }
  var matches = EV_ITEMS.filter(function (it) {
    return it.name.toLowerCase().indexOf(q) !== -1;
  });
  counter.textContent = matches.length + ' matching';
  if (!matches.length) {
    list.innerHTML = '<div class="ev-empty-hint">No items match “' + esc(q) + '”.</div>';
    return;
  }
  matches.forEach(function (it) {
    var row = document.createElement('div');
    row.className = 'ev-match-row';
    row.innerHTML =
      '<div class="ev-match-name">' + esc(it.name) +
        '<span class="in-stock">(' + fmtCount(it.count) + ' ' + esc(it.unit) + ' in stock)</span>' +
      '</div>' +
      '<input type="number" class="ev-count" min="0" step="' +
        (it.unit === 'lb' ? 'any' : '1') + '" placeholder="0">' +
      '<div class="ev-unit">' + esc(it.unit) + '</div>' +
      '<button type="button" class="ev-use-btn">Use for event</button>';
    var input = row.querySelector('.ev-count');
    input.max = it.count;
    row.querySelector('.ev-use-btn').addEventListener('click', function () {
      stageLine(it, input);
    });
    list.appendChild(row);
  });
}

function fmtCount(n) {
  // Trim trailing zeros; keep up to 2 decimals.
  return parseFloat(Number(n).toFixed(2)).toString();
}

// "Use for event" handler. Validates the count, then either appends a new
// staged line or sums into an existing line for the same item.
function stageLine(it, input) {
  clearFormError();
  var raw = (input.value || '').trim();
  var n = parseFloat(raw);
  if (!raw || isNaN(n) || n <= 0) {
    showFormError('Enter a count greater than 0 for ' + it.name + '.');
    input.focus();
    return;
  }
  if (n > it.count) {
    showFormError('Only ' + fmtCount(it.count) + ' ' + it.unit + ' of ' +
                  it.name + ' is in stock.');
    input.focus();
    return;
  }
  // Merge into an existing staged line for the same item rather than
  // creating duplicates — the cap is the original on-hand count.
  var existing = null;
  for (var i = 0; i < staged.length; i++) {
    if (staged[i].name === it.name) { existing = staged[i]; break; }
  }
  if (existing) {
    var combined = existing.count + n;
    if (combined > it.count) {
      showFormError('Combined event count for ' + it.name + ' would exceed the ' +
                    fmtCount(it.count) + ' ' + it.unit + ' in stock.');
      input.focus();
      return;
    }
    existing.count = combined;
  } else {
    staged.push({ name: it.name, unit: it.unit, count: n });
  }
  input.value = '';
  renderStaged();
}

function renderStaged() {
  var box  = document.getElementById('stagedBox');
  var body = document.getElementById('stagedRows');
  body.innerHTML = '';
  if (!staged.length) { box.style.display = 'none'; return; }
  box.style.display = '';
  staged.forEach(function (line, idx) {
    var tr = document.createElement('tr');
    tr.innerHTML =
      '<td>' + esc(line.name) + '</td>' +
      '<td class="num">' + fmtCount(line.count) + '</td>' +
      '<td>' + esc(line.unit) + '</td>' +
      '<td class="num"><button type="button" class="ev-remove" title="Remove">×</button></td>';
    tr.querySelector('.ev-remove').addEventListener('click', function () {
      staged.splice(idx, 1);
      renderStaged();
    });
    body.appendChild(tr);
  });
}

// Inline error banner (mirrors delivery/index.php).
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
  var type = document.getElementById('event_type').value;
  if (!type) { showFormError('Pick an event type.'); return false; }
  var initials = document.getElementById('initials').value.trim();
  if (!initials) { showFormError('Enter your initials.'); return false; }
  if (!staged.length) {
    showFormError('Stage at least one item with “Use for event” before submitting.');
    return false;
  }
  return true;
}

// DONE submit: POST staged lines to submit_event.php, then reload the
// page so the in-stock list reflects the new inventory counts.
document.getElementById('eventForm').addEventListener('submit', function (e) {
  e.preventDefault();
  if (!validateForm()) return;
  var btn = document.getElementById('doneBtn');
  btn.disabled = true;
  btn.textContent = 'Saving…';

  var fd = new FormData();
  fd.append('event_type', document.getElementById('event_type').value);
  fd.append('initials',   document.getElementById('initials').value.trim());
  staged.forEach(function (line) {
    fd.append('item_name[]',  line.name);
    fd.append('item_count[]', line.count);
    fd.append('item_unit[]',  line.unit);
  });

  fetch('submit_event.php', { method: 'POST', body: fd, credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (!data.ok) {
        btn.disabled = false;
        btn.textContent = '📋 DONE';
        showFormError(data.error || 'Could not save the event.');
        return;
      }
      clearFormError();
      // Reload so the in-stock snapshot reflects the new inventory.
      window.location.href = 'event.php?saved=1';
    })
    .catch(function (err) {
      btn.disabled = false;
      btn.textContent = '📋 DONE';
      showFormError('Network error: ' + err.message);
    });
});

// Show the "saved" banner once on reload.
if (window.location.search.indexOf('saved=1') !== -1) {
  var b = document.getElementById('formError');
  b.className = 'banner success';
  b.textContent = '✅ Event saved.';
  b.style.display = 'flex';
}

// Initial paint: empty matchList + hint.
renderMatches();
</script>
<?php renderFoot(); ?>
