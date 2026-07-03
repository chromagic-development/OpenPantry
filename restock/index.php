<?php
// Restock form. Staff filter the known inventory by partial name, enter a
// count per item, and click "Add to inventory" to stage that line. Repeated
// clicks accumulate a list; clicking DONE submits the batch and each
// staged count is ADDED to the matching inventory row (the mirror of the
// event flow, which decrements).
//
// Unlike Events, restocks aren't customer-facing orders — they're an
// inventory mutation, so we don't write any `orders` or `scans` rows.
// That matches the manual-edit model the existing Inventory page uses,
// and keeps Restock from polluting the orders / volume reports.
$GLOBALS['FS_PREFIX'] = '../';
require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../auth.php';
requireLogin();

$db = getDB();

// Every known inventory row, in stock or not — restocking has to work
// even when an item has sold out (count = 0). The Inventory page is the
// canonical source for unit + new-item creation; this page just nudges
// counts up for already-known items.
$items = $db->query(
    "SELECT generic_name, count, unit FROM inventory
      ORDER BY generic_name COLLATE NOCASE"
)->fetchAll();

renderHead('Restock');
renderNav('restock');
?>
<div class="container">

<div id="formError" class="banner error" style="display:none;"></div>

<form method="POST" action="submit_restock.php" id="restockForm" novalidate>

  <!-- Items picker + staged-lines list ------------------------------ -->
  <div class="card">
    <div class="rs-items-header">
      <h2 style="margin:0;">Restock Items</h2>
      <!-- Purchased vs. donated source toggle. One checkbox per batch —
           all staged lines submitted together share the same source. The
           server uses this to bump either inventory.restocked_purchased
           or inventory.restocked_donated alongside the count, and the
           Inventory page surfaces the lifetime ratio as "Purchased %". -->
      <label class="rs-purchased-toggle" for="purchased">
        <input type="checkbox" id="purchased" name="purchased" value="1" checked>
        Purchased <span class="rs-toggle-hint">(uncheck for donated)</span>
      </label>
      <input type="search" id="itemSearch" class="rs-search"
             placeholder="🔍 Type to filter items by name…" oninput="renderMatches()" autocomplete="off" autofocus>
      <span id="itemCount" style="font-size:.8rem; color:#777;"></span>
    </div>

    <?php if (!$items): ?>
      <p style="color:#777; padding:14px 0;">
        No inventory rows yet — add at least one item on the
        <a href="../inventory/">Inventory</a> page first.
      </p>
    <?php endif; ?>

    <!-- Matched items appear here as filter text is typed. -->
    <div id="matchList" class="rs-match-list"></div>

    <!-- Running list of staged lines. Each click of "Add to inventory"
         adds a row here; the × button removes a line from the batch. -->
    <div id="stagedBox" style="display:none;">
      <h3 class="rs-staged-h">Staged for restock</h3>
      <table class="data rs-staged-table">
        <thead>
          <tr>
            <th>Item</th>
            <th class="num">Add</th>
            <th>Unit</th>
            <th></th>
          </tr>
        </thead>
        <tbody id="stagedRows"></tbody>
      </table>
    </div>

    <div class="rs-submit-row">
      <!-- DONE: green pill matching delivery/event. POSTs every
           staged line to submit_restock.php which increments inventory
           in a single transaction. -->
      <button type="submit" id="doneBtn" class="rs-done-btn">📋 DONE</button>
    </div>
  </div>
</form>
</div>

<style>
  .rs-items-header {
    display:flex; align-items:center; gap:14px; flex-wrap: wrap;
    padding-bottom: 10px; border-bottom: 2px solid var(--cat-bg); margin-bottom: 12px;
  }
  .rs-search { flex: 1 1 240px; max-width: 420px; }
  /* "Purchased" toggle — applies to every staged line in the batch. Lives
     in the header alongside the search so it's set before staging starts. */
  .rs-purchased-toggle {
    display: inline-flex; align-items: center; gap: 8px; margin: 0;
    padding: 6px 10px; background: #fff; border: 1px solid var(--border);
    border-radius: 6px; cursor: pointer; font-weight: 700; color: var(--brown);
    font-size: .85rem; text-transform: none;
  }
  .rs-purchased-toggle input { width: auto; margin: 0; }
  .rs-purchased-toggle .rs-toggle-hint {
    font-weight: 400; color: #777; font-size: .75rem;
  }

  /* One row per filter-matched item: name + current count, then the
     restock-count input, the read-only unit chip, and the
     "Add to inventory" button. */
  .rs-match-list { display:flex; flex-direction:column; gap: 8px; }
  .rs-match-row {
    display:flex; align-items:center; gap:10px; flex-wrap:wrap;
    padding: 10px 12px; border: 1px solid var(--border); border-radius: 8px;
    background: #fff;
  }
  .rs-match-name { flex: 1 1 200px; font-weight: 700; color: var(--text); }
  .rs-match-name .in-stock {
    font-weight: 400; color: #777; font-size: .8rem; margin-left: 6px;
  }
  .rs-match-row input.rs-count { flex: 0 0 90px; text-align: right; }
  .rs-match-row .rs-unit {
    flex: 0 0 56px; padding: 6px 10px;
    background: #efe9dd; border: 1px solid var(--border); border-radius: 6px;
    text-align: center; font-size: .85rem; color: var(--text);
    text-transform: lowercase;
  }
  .rs-match-row .rs-add-btn {
    flex: 0 0 auto;
    padding: 8px 14px; font-size: .9rem; font-weight: 700;
    /* Green to signal "add to inventory" (delivery/event use brown for
       decrement actions); matches the DONE button family. */
    background: var(--green); color: #fff;
    border: none; border-radius: 6px; cursor: pointer;
  }
  .rs-match-row .rs-add-btn:disabled { opacity: .5; cursor: not-allowed; }

  .rs-empty-hint { padding: 18px 4px; color: #999; font-style: italic; }

  .rs-staged-h {
    font-size: .85rem; font-weight: 800; text-transform: uppercase;
    color: var(--brown); border-bottom: 2px solid var(--cat-bg);
    padding-bottom: 4px; margin: 20px 0 10px;
  }
  .rs-staged-table .rs-remove {
    background: none; border: none; color: #8B1A1A; cursor: pointer;
    font-size: 1.1rem; padding: 2px 8px;
  }

  .rs-submit-row {
    padding: 18px 20px 0; margin-top: 14px;
    border-top: 2px solid var(--border);
  }
  .rs-done-btn {
    background: var(--green); color: #fff;
    border: none; border-radius: 7px;
    padding: 16px; font-size: 1.15rem; font-weight: 700;
    width: 100%; cursor: pointer;
  }
  .rs-done-btn:disabled { opacity: .6; cursor: progress; }
</style>

<script>
// Inventory snapshot — name, current count, unit. The server re-pulls
// inventory at submit time so a stale snapshot can't desync the increment.
var RS_ITEMS = <?= json_encode(array_map(function ($r) {
    return [
        'name'  => $r['generic_name'],
        'unit'  => $r['unit'],
        'count' => (float)$r['count'],
    ];
}, $items), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

// Lines the user has clicked "Add to inventory" on. Each: { name, unit, count }.
// On DONE they're POSTed as item_name[]/item_count[]/item_unit[] arrays.
var staged = [];

function esc(s) {
  return String(s == null ? '' : s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
function fmtCount(n) {
  return parseFloat(Number(n).toFixed(2)).toString();
}

// Render the filter-matched item rows. Empty filter shows a hint instead
// of dumping the entire inventory — typing narrows the list.
function renderMatches() {
  var q = (document.getElementById('itemSearch').value || '').trim().toLowerCase();
  var list = document.getElementById('matchList');
  var counter = document.getElementById('itemCount');
  list.innerHTML = '';
  if (!RS_ITEMS.length) { counter.textContent = ''; return; }
  if (!q) {
    list.innerHTML = '<div class="rs-empty-hint">Type to search the ' +
                     RS_ITEMS.length + ' known item(s)…</div>';
    counter.textContent = RS_ITEMS.length + ' known';
    return;
  }
  var matches = RS_ITEMS.filter(function (it) {
    return it.name.toLowerCase().indexOf(q) !== -1;
  });
  counter.textContent = matches.length + ' matching';
  if (!matches.length) {
    list.innerHTML = '<div class="rs-empty-hint">No items match “' + esc(q) + '”.</div>';
    return;
  }
  matches.forEach(function (it) {
    var row = document.createElement('div');
    row.className = 'rs-match-row';
    row.innerHTML =
      '<div class="rs-match-name">' + esc(it.name) +
        '<span class="in-stock">(' + fmtCount(it.count) + ' ' + esc(it.unit) + ' on hand)</span>' +
      '</div>' +
      '<input type="number" class="rs-count" min="0" step="' +
        (it.unit === 'lb' ? 'any' : '1') + '" placeholder="0">' +
      '<div class="rs-unit">' + esc(it.unit) + '</div>' +
      '<button type="button" class="rs-add-btn">Add to inventory</button>';
    var input = row.querySelector('.rs-count');
    row.querySelector('.rs-add-btn').addEventListener('click', function () {
      stageLine(it, input);
    });
    list.appendChild(row);
  });
}

// "Add to inventory" handler. Validates the count, then merges into any
// existing staged line for the same item rather than duplicating rows.
function stageLine(it, input) {
  clearFormError();
  var raw = (input.value || '').trim();
  var n = parseFloat(raw);
  if (!raw || isNaN(n) || n <= 0) {
    showFormError('Enter a count greater than 0 for ' + it.name + '.');
    input.focus();
    return;
  }
  var existing = null;
  for (var i = 0; i < staged.length; i++) {
    if (staged[i].name === it.name) { existing = staged[i]; break; }
  }
  if (existing) {
    existing.count = existing.count + n;
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
      '<td class="num">+' + fmtCount(line.count) + '</td>' +
      '<td>' + esc(line.unit) + '</td>' +
      '<td class="num"><button type="button" class="rs-remove" title="Remove">×</button></td>';
    tr.querySelector('.rs-remove').addEventListener('click', function () {
      staged.splice(idx, 1);
      renderStaged();
    });
    body.appendChild(tr);
  });
}

// Inline error banner (mirrors delivery/event).
function showFormError(msg) {
  var b = document.getElementById('formError');
  b.className = 'banner error';
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
  if (!staged.length) {
    showFormError('Stage at least one item with “Add to inventory” before submitting.');
    return false;
  }
  return true;
}

// DONE submit: POST staged lines to submit_restock.php, then reload so
// the on-hand counts shown in the filter reflect the new inventory.
document.getElementById('restockForm').addEventListener('submit', function (e) {
  e.preventDefault();
  if (!validateForm()) return;
  var btn = document.getElementById('doneBtn');
  btn.disabled = true;
  btn.textContent = 'Saving…';

  var fd = new FormData();
  // Purchased flag applies to the whole batch. We send "1" when checked
  // and don't send the field when unchecked — the server treats absence
  // as "donated", matching standard form-checkbox semantics.
  if (document.getElementById('purchased').checked) {
    fd.append('purchased', '1');
  }
  staged.forEach(function (line) {
    fd.append('item_name[]',  line.name);
    fd.append('item_count[]', line.count);
    fd.append('item_unit[]',  line.unit);
  });

  fetch('submit_restock.php', { method: 'POST', body: fd, credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (!data.ok) {
        btn.disabled = false;
        btn.textContent = '📋 DONE';
        showFormError(data.error || 'Could not save the restock.');
        return;
      }
      clearFormError();
      window.location.href = 'index.php?saved=1';
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
  b.textContent = '✅ Restock saved.';
  b.style.display = 'flex';
}

// Initial paint: empty matchList + hint.
renderMatches();
</script>
<?php renderFoot(); ?>
