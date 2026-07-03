<?php
// OrderAhead import. Staff upload a Distribution Report CSV exported from
// the OrderAhead system. The server matches each row's ItemName against
// the inventory list (case-insensitive on generic_name) and decrements
// the matching inventory row:
//   * unit == 'each' → subtract the row's Quantity
//   * unit == 'lb'   → subtract the row's TotalPounds
// Rows whose ItemName doesn't match any inventory row are reported back
// as "skipped" so staff can reconcile the naming differences upstream.
//
// No `orders` / `scans` rows are written — the spec describes inventory
// effects only, so this mirrors the Restock page's mutation-only model
// rather than the Delivery/Event "creates an order" model.
$GLOBALS['FS_PREFIX'] = '../';
require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../auth.php';
requireLogin();

renderHead('OrderAhead');
renderNav('order_ahead');
?>
<div class="container">

<div id="formError" class="banner error" style="display:none;"></div>

<div class="card">
  <h2>Upload OrderAhead Distribution Report</h2>
  <p style="color:#555; font-size:.9rem; margin-bottom:14px;">
    Pick a Distribution Report CSV exported from OrderAhead. The importer
    reads three columns from the file — <strong>ItemName</strong>,
    <strong>Quantity</strong>, and <strong>TotalPounds</strong> — and
    deducts from inventory as follows:
  </p>
  <ul style="color:#555; font-size:.9rem; margin: 0 0 14px 20px; line-height: 1.6;">
    <li>If the matching inventory item's unit is <strong>each</strong>,
        the row's <strong>Quantity</strong> is subtracted.</li>
    <li>If the matching inventory item's unit is <strong>lb</strong>,
        the row's <strong>TotalPounds</strong> is subtracted.</li>
    <li>Rows whose ItemName isn't in inventory are listed as skipped
        — nothing is deducted for those.</li>
  </ul>

  <form id="oaForm" enctype="multipart/form-data">
    <div class="row" style="align-items:end;">
      <div style="flex:3 1 320px;">
        <label for="csv">Distribution Report (CSV)</label>
        <input type="file" id="csv" name="csv" accept=".csv,text/csv" required>
      </div>
      <div style="flex:0 0 200px;">
        <button type="submit" id="uploadBtn" class="btn btn-primary"
                style="width:100%;">📤 Remove from Inventory</button>
      </div>
    </div>
  </form>
</div>

<!-- Results card. Hidden until an upload finishes. -->
<div id="resultsCard" class="card" style="display:none;">
  <h2>Import Result</h2>

  <div class="stat-grid" style="margin-bottom:14px;">
    <div class="stat"><div class="v" id="statRows">0</div><div class="k">Rows in CSV</div></div>
    <div class="stat"><div class="v" id="statApplied">0</div><div class="k">Items Deducted</div></div>
    <div class="stat"><div class="v" id="statSkipped">0</div><div class="k">Items Skipped</div></div>
  </div>

  <div id="appliedBox" style="display:none;">
    <h3 style="font-size:.85rem; font-weight:800; text-transform:uppercase;
               color: var(--brown); border-bottom: 2px solid var(--cat-bg);
               padding-bottom: 4px; margin: 14px 0 8px;">Deductions applied</h3>
    <table class="data">
      <thead>
        <tr>
          <th>Item</th>
          <th class="num">Deducted</th>
          <th>Unit</th>
          <th class="num">New on-hand</th>
        </tr>
      </thead>
      <tbody id="appliedRows"></tbody>
    </table>
  </div>

  <div id="skippedBox" style="display:none;">
    <h3 style="font-size:.85rem; font-weight:800; text-transform:uppercase;
               color: var(--brown); border-bottom: 2px solid var(--cat-bg);
               padding-bottom: 4px; margin: 18px 0 8px;">Skipped (no matching inventory item)</h3>
    <table class="data">
      <thead>
        <tr>
          <th>Item Name (from CSV)</th>
          <th class="num">Quantity</th>
          <th class="num">TotalPounds</th>
        </tr>
      </thead>
      <tbody id="skippedRows"></tbody>
    </table>
  </div>
</div>

</div>

<script>
function esc(s) {
  return String(s == null ? '' : s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
function fmtCount(n) {
  return parseFloat(Number(n).toFixed(2)).toString();
}
function showFormError(msg) {
  var b = document.getElementById('formError');
  b.className = 'banner error';
  b.textContent = '⚠ ' + msg;
  b.style.display = 'flex';
  window.scrollTo({ top: 0, behavior: 'smooth' });
}
function showFormSuccess(msg) {
  var b = document.getElementById('formError');
  b.className = 'banner success';
  b.textContent = '✅ ' + msg;
  b.style.display = 'flex';
}
function clearFormError() {
  var b = document.getElementById('formError');
  b.style.display = 'none';
  b.textContent = '';
}

function renderResults(data) {
  document.getElementById('statRows').textContent    = data.rows;
  document.getElementById('statApplied').textContent = data.applied.length;
  document.getElementById('statSkipped').textContent = data.skipped.length;

  var appBox  = document.getElementById('appliedBox');
  var appBody = document.getElementById('appliedRows');
  appBody.innerHTML = '';
  if (data.applied.length) {
    appBox.style.display = '';
    data.applied.forEach(function (r) {
      var tr = document.createElement('tr');
      tr.innerHTML =
        '<td>' + esc(r.name) + '</td>' +
        '<td class="num">−' + fmtCount(r.deducted) + '</td>' +
        '<td>' + esc(r.unit) + '</td>' +
        '<td class="num">' + fmtCount(r.new_count) + '</td>';
      appBody.appendChild(tr);
    });
  } else {
    appBox.style.display = 'none';
  }

  var skBox  = document.getElementById('skippedBox');
  var skBody = document.getElementById('skippedRows');
  skBody.innerHTML = '';
  if (data.skipped.length) {
    skBox.style.display = '';
    data.skipped.forEach(function (r) {
      var tr = document.createElement('tr');
      tr.innerHTML =
        '<td>' + esc(r.name) + '</td>' +
        '<td class="num">' + fmtCount(r.qty) + '</td>' +
        '<td class="num">' + fmtCount(r.lbs) + '</td>';
      skBody.appendChild(tr);
    });
  } else {
    skBox.style.display = 'none';
  }

  document.getElementById('resultsCard').style.display = '';
}

document.getElementById('oaForm').addEventListener('submit', function (e) {
  e.preventDefault();
  clearFormError();
  var fileEl = document.getElementById('csv');
  if (!fileEl.files || !fileEl.files[0]) {
    showFormError('Choose a Distribution Report CSV to upload.');
    return;
  }
  var btn = document.getElementById('uploadBtn');
  btn.disabled = true;
  btn.textContent = 'Uploading…';

  var fd = new FormData();
  fd.append('csv', fileEl.files[0]);

  fetch('submit_orderahead.php', { method: 'POST', body: fd, credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      btn.disabled = false;
      btn.textContent = '📤 Upload & Apply';
      if (!data.ok) {
        showFormError(data.error || 'Could not import the CSV.');
        return;
      }
      var msg = 'Imported ' + data.rows + ' row(s): ' +
                data.applied.length + ' deduction(s) applied, ' +
                data.skipped.length + ' skipped.';
      showFormSuccess(msg);
      renderResults(data);
      // Let the user upload another file without clearing the previous result.
      fileEl.value = '';
    })
    .catch(function (err) {
      btn.disabled = false;
      btn.textContent = '📤 Upload & Apply';
      showFormError('Network error: ' + err.message);
    });
});
</script>
<?php renderFoot(); ?>
