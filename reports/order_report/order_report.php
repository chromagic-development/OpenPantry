<?php
// Order Report.
//
// For each generic_name we've ever scanned, the order recommendation is built
// from a per-item demand forecast over the lead time:
//
//   Par Level         = Forecast(LT) + SafetyStock
//   Safety Stock      = Z * √Variance(LT)
//   Total Order Req.  = max(0, Par Level - Latest Inventory Count)
//
// Forecast(LT) and Variance(LT) come from a quasi-Poisson GLM fitted to the
// item's full weekly scan history (trend + Fourier seasonality), implemented
// in forecast.php. That single model replaces the old hand-rolled seasonality
// (S) and growth (G) ratio multipliers — they're now read back out of the fit
// as interpretable factors for the table:
//   S = Forecast(LT) / deseasonalized baseline over the same window
//   G = exp(trend) — the model's annual growth multiplier
// and the safety stock is driven by the model's own predictive variance
// (dispersion φ), so trend, seasonality, and uncertainty are all consistent.
//
// Items without enough history to fit (new installs, brand-new items) fall
// back to the original trailing-average method:
//   Par Level = ADV * LT + Z * σ * √LT,  with S = G = 1
// where ADV/σ are the mean/stdev of daily demand over the last
// `velocity_window` days (0-filled, population stats). LT = user-selectable
// Lead Time; Z = `safety_z` (1.65 ≈ 95% confidence).

$GLOBALS['FS_PREFIX'] = '../../';
require_once __DIR__ . '/../../common.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/report_lib.php'; // shared row + alert computation
requireLogin();
$db = getDB();

// AJAX ping fired when the Generate Email button is clicked, so the "last
// ordered" stamp under the order buttons survives reloads. Handled before
// any output.
if (($_POST['action'] ?? '') === 'record_email_order') {
    setSetting('report_last_email_order', now());
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'at' => now()]);
    exit;
}
$lastEmailOrder = setting('report_last_email_order', '') ?? '';
$lastEmailOrderTxt = $lastEmailOrder !== ''
    ? date('M j, Y g:i A', strtotime($lastEmailOrder))
    : '';

// Food pantry name (Settings → Food Pantry Information) is appended to the
// order-email subject as "New Order from <name>".
$pantryName    = setting('food_pantry_name', '') ?? '';
$orderSubject  = $pantryName !== '' ? 'New Order from ' . $pantryName : 'New Order';

$leadTime  = max(1, (int)($_GET['lead_time'] ?? (int)setting('default_lead_time', '14')));
$velWindow = max(7, (int)setting('velocity_window', '30'));
$z         = (float)setting('safety_z', '1.65');

// Checkbox parameters, persisted via settings so the choices survive
// reloads / navigation. A hidden "submitted" field marks a real form
// submission, since an unchecked checkbox sends nothing over GET.
//   * Ignore In Stock (default off): order recommendations ignore the
//     current inventory count (Par Level becomes the Order Request).
//   * Ignore Events (default ON): scans belonging to EVENT-tagged orders
//     are excluded from the velocity window, so food consumed by events
//     doesn't inflate Avg Daily / par levels for regular pantry demand.
//   * Produce Only (default off): recommendations list only produce-kind
//     items, and the Generate Email button (supplier produce order)
//     becomes available.
//   * Purchased Only (default off): recommendations list only items that
//     have a non-zero Purchased value on the Inventory page (i.e. some of
//     their restocked amount was purchased rather than donated).
if (isset($_GET['submitted'])) {
    $ignoreStock   = isset($_GET['ignore_stock']);
    $ignoreEvents  = isset($_GET['ignore_events']);
    $produceOnly   = isset($_GET['produce_only']);
    $purchasedOnly = isset($_GET['purchased_only']);
    setSetting('report_ignore_stock',   $ignoreStock   ? '1' : '0');
    setSetting('report_ignore_events',  $ignoreEvents  ? '1' : '0');
    setSetting('report_produce_only',   $produceOnly   ? '1' : '0');
    setSetting('report_purchased_only', $purchasedOnly ? '1' : '0');
} else {
    $ignoreStock   = (setting('report_ignore_stock', '0') === '1');
    $ignoreEvents  = (setting('report_ignore_events', '1') === '1');
    $produceOnly   = (setting('report_produce_only', '0') === '1');
    $purchasedOnly = (setting('report_purchased_only', '0') === '1');
}

// Build the report rows (per-item demand model, par levels, order requests)
// and evaluate the reorder alerts. Both live in report_lib.php so this page
// and the cron mailer (cron_reorder_alerts.php) compute identical results.
// The "Generate Email" / "Restock Now" payloads are built client-side from
// whichever per-row "Restock" checkboxes are ticked.
$rows = op_report_rows($db, [
    'lead_time'       => $leadTime,
    'velocity_window' => $velWindow,
    'z'               => $z,
    'ignore_stock'    => $ignoreStock,
    'ignore_events'   => $ignoreEvents,
    'produce_only'    => $produceOnly,
    'purchased_only'  => $purchasedOnly,
]);

// Reorder reminders: one HTML line per triggered alert, same wording the
// cron mailer uses (built from the structured entries report_lib returns).
$alerts = [];
foreach (op_report_alerts($db, $rows) as $a) {
    $alerts[] = "<strong>" . htmlspecialchars($a['name']) . "</strong>: only "
        . $a['days_text'] . " days of stock — order at least "
        . $a['order_text'] . " " . htmlspecialchars($a['unit']);
}

renderHead('Order Now Report');
renderNav('report');
?>
<style>
  /* The 13-column recommendations table is wider than the card. Keep it inside
     the card by scrolling horizontally on narrow screens, and shrink the cell
     padding + font (vs the base `.data` styles) so it fits without scroll on a
     typical desktop. Selectors use `table#repTable` to beat `.data`. */
  .rep-table-wrap { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
  table#repTable th, table#repTable td { padding: 6px 7px; font-size: .8rem; }
  table#repTable th { font-size: .68rem; }
  /* A single bordered box around all four parameter checkboxes (styled like
     the "Purchased" toggle on the Restock page), with the toggles laid out in
     two columns inside it. */
  .rep-toggle-group {
    display: flex; gap: 6px 18px; flex-wrap: wrap;
    padding: 8px 12px; background: #fff; border: 1px solid var(--border);
    border-radius: 8px;
  }
  .rep-toggle-col { display: flex; flex-direction: column; gap: 6px; }
  .rep-toggle {
    display: flex; align-items: center; gap: 8px; margin: 0;
    cursor: pointer; font-weight: 700; color: var(--brown);
    font-size: .85rem; text-transform: none; white-space: nowrap;
  }
  .rep-toggle input { width: auto; margin: 0; }
  @media print {
    .site-header, nav.subnav, .btn, form, .no-print { display:none; }
    .rep-table-wrap { overflow-x: visible; }
  }
</style>
<div class="container">

  <?php if ($alerts): ?>
    <div class="banner warn">
      <div style="font-size:1.4rem;">⏰</div>
      <div>
        <strong>Reorder reminders:</strong>
        <ul style="margin:6px 0 0 18px;">
          <?php foreach ($alerts as $a) echo "<li>$a</li>"; ?>
        </ul>
      </div>
    </div>
  <?php endif; ?>

  <div class="card no-print">
    <h2>Parameters</h2>
    <form method="get" class="row">
      <input type="hidden" name="submitted" value="1">
      <div>
        <label for="lt">Lead Time (days)</label>
        <input type="number" id="lt" name="lead_time" min="1" max="365" value="<?= $leadTime ?>">
      </div>
      <div>
        <label>Velocity Window</label>
        <input type="text" value="<?= $velWindow ?> days" disabled>
      </div>
      <div>
        <label>Confidence Z</label>
        <input type="text" value="<?= htmlspecialchars((string)$z) ?>" disabled>
      </div>
      <!-- All four parameter checkboxes share one bordered box, laid out in
           two columns (Ignore In Stock / Ignore Events, then Purchased Only /
           Produce Only). -->
      <div class="rep-toggle-group" style="flex:0 0 auto;">
        <div class="rep-toggle-col">
          <label class="rep-toggle">
            <input type="checkbox" name="ignore_stock" value="1" <?= $ignoreStock ? 'checked' : '' ?>>
            Ignore In Stock
          </label>
          <label class="rep-toggle"
                 title="Exclude items consumed by Events from the Avg Daily calculation">
            <input type="checkbox" name="ignore_events" value="1" <?= $ignoreEvents ? 'checked' : '' ?>>
            Ignore Events
          </label>
        </div>
        <div class="rep-toggle-col">
          <label class="rep-toggle"
                 title="List only items with a non-zero Purchased value on the Inventory page">
            <input type="checkbox" name="purchased_only" value="1" <?= $purchasedOnly ? 'checked' : '' ?>>
            Purchased Only
          </label>
          <label class="rep-toggle"
                 title="List only produce items; their Restock boxes start checked">
            <input type="checkbox" name="produce_only" value="1" <?= $produceOnly ? 'checked' : '' ?>>
            Produce Only
          </label>
        </div>
      </div>
      <!-- Always-visible order actions plus Recalculate, all on one row. -->
      <div style="flex:0 0 auto; display:flex; align-items:center; gap:8px;">
        <div style="display:flex; flex-direction:column;">
          <label>&nbsp;</label>
          <div style="display:flex; gap:4px;">
            <button type="submit" class="btn btn-primary" style="white-space:nowrap;">Recalculate</button>
            <button type="button" class="btn btn-secondary" style="white-space:nowrap;"
                    onclick="generateOrderEmail()"
                    title="Open a Gmail draft listing the checked items">✉ Email Order</button>
            <button type="button" class="btn btn-secondary" style="white-space:nowrap;"
                    onclick="printOrder()"
                    title="Print an order sheet listing the checked items">🖨 Print Order</button>
            <button type="button" class="btn btn-secondary" style="white-space:nowrap;"
                    onclick="restockNow()"
                    title="Add the checked items' ordered quantities to inventory">📦 Restock Now</button>
            <?php if ($rows): ?>
            <button type="button" class="btn btn-secondary" style="white-space:nowrap;"
                    onclick="window.print()">🖨 Print</button>
            <?php endif; ?>
          </div>
        </div>
        <!-- Timestamp of the most recent Email Order click (persisted).
             Label sits above the date so the stamp stays narrow and the
             order buttons keep to a single row. -->
        <div id="lastOrderStamp" style="font-size:.72rem; color:#777; line-height:1.3;">
          <?php if ($lastEmailOrderTxt !== ''): ?>
            <div>Last email order:</div>
            <div id="lastOrderDate" style="white-space:nowrap;"><?= htmlspecialchars($lastEmailOrderTxt) ?></div>
          <?php else: ?>
            <div id="lastOrderDate">No email order yet</div>
          <?php endif; ?>
        </div>
      </div>
    </form>
    <p style="color:#777; font-size:.8rem; margin-top:10px;">
      Par levels come from a per-item demand model (trend + seasonality) fitted
      to each item's scan history; the S and G columns are its seasonal and
      annual-growth factors. Items with too little history (&lt; ~2 months) use
      a trailing <?= $velWindow ?>-day average instead (S = G = 1, shown with a
      <span title="trailing-average fallback">°</span> after the name). Adjust
      velocity window and Z under <a href="../../settings/">Settings</a>.
    </p>
    <p id="orderMsg" style="font-size:.85rem; font-weight:700; margin-top:8px;"></p>
  </div>

  <div class="card no-print">
    <h2>Reorder Alerts</h2>
    <p style="color:#777; font-size:.85rem; margin-bottom:12px;">
      A reminder shows on this page whenever an item's projected days-of-stock
      falls below its alert lead time. Lead time here is the fulfillment window
      you want to leave for the supplier. Tick <strong>Email</strong> on an alert
      to also have the reorder reminder emailed to the administrator
      <?php $adminEmail = trim((string)(setting('admin_email', '') ?? '')); ?>
      <?php if ($adminEmail !== ''): ?>
        (<strong><?= htmlspecialchars($adminEmail) ?></strong>)
      <?php endif; ?>
      by the scheduled reorder-alert job (see Settings → Email Notifications).
    </p>
    <?php if ($adminEmail === ''): ?>
      <div class="banner warn" style="margin-bottom:12px;">
        <div>
          No administrator email is set, so reorder reminders can't be emailed.
          Add one under <a href="../../settings/">Settings → Administrator Email &amp; Password</a>.
        </div>
      </div>
    <?php endif; ?>
    <form method="post" action="../../api_alert.php" class="row" style="margin-bottom:14px;">
      <input type="hidden" name="action" value="add">
      <div style="flex:2 1 220px;">
        <label>Item</label>
        <select name="generic_name" required>
          <option value="">— select —</option>
          <?php
          // $rows is ordered by order request (largest first) for the
          // recommendations table; the dropdown lists the same items a→z.
          $alertItems = array_column($rows, 'name');
          usort($alertItems, 'strcasecmp');
          ?>
          <?php foreach ($alertItems as $n): ?>
            <option value="<?= htmlspecialchars($n) ?>"><?= htmlspecialchars($n) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Lead Time (days)</label>
        <input type="number" name="lead_time_days" min="1" value="<?= $leadTime ?>" required>
      </div>
      <div style="flex:0 0 120px;">
        <label>&nbsp;</label>
        <button type="submit" class="btn btn-primary btn-block">Add</button>
      </div>
    </form>
    <?php
    $a = $db->query("SELECT id, generic_name, lead_time_days, enabled, email_enabled FROM alerts ORDER BY generic_name");
    $aRows = $a->fetchAll();
    ?>
    <?php if ($aRows): ?>
    <table class="data">
      <thead><tr>
        <th>Item</th>
        <th class="num">Lead Time</th>
        <th>Status</th>
        <th title="Email this reorder reminder to the administrator">Email</th>
        <th></th>
      </tr></thead>
      <tbody>
        <?php foreach ($aRows as $row): ?>
          <tr>
            <td><?= htmlspecialchars($row['generic_name']) ?></td>
            <td class="num"><?= (int)$row['lead_time_days'] ?> days</td>
            <td><?= $row['enabled'] ? 'On' : 'Off' ?></td>
            <td>
              <!-- Auto-submits on toggle. The hidden 0 precedes the checkbox so
                   an unchecked box still posts email=0 (last value wins). -->
              <form method="post" action="../../api_alert.php" style="display:inline; margin:0;">
                <input type="hidden" name="action" value="set_email">
                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                <input type="hidden" name="email" value="0">
                <input type="checkbox" name="email" value="1" style="width:auto; cursor:pointer;"
                       onchange="this.form.submit()"
                       <?= $row['email_enabled'] ? 'checked' : '' ?>>
              </form>
            </td>
            <td>
              <form method="post" action="../../api_alert.php" style="display:inline;">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                <button class="btn btn-secondary" style="padding:4px 10px; font-size:.8rem;">Remove</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
      <p style="color:#777;">No alerts configured.</p>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Order Recommendations</h2>
    <?php if (!$rows): ?>
      <p style="color:#777;">No scan history yet. Record some orders first.</p>
    <?php else: ?>
    <div class="no-print" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-bottom:12px;">
      <input type="search" id="repSearch" placeholder="🔍 Filter by generic name…"
             oninput="applyRepFilter()"
             style="max-width:340px; flex:1 1 200px;">
      <span id="repCount" style="font-size:.8rem; color:#777;"><?= count($rows) ?> items</span>
    </div>
    <div class="rep-table-wrap">
    <table class="data" id="repTable">
      <thead><tr>
        <th title="Include this item in the order email / Restock Now">Restock</th>
        <th>Generic Name</th>
        <th>Type</th>
        <th class="num" title="Expected daily demand over the lead time from the item's demand model (or the trailing-window average for fallback items)">Avg Daily<br>(<?= $velWindow ?>d)</th>
        <th class="num" title="Per-day demand standard deviation; drives Safety Stock">σ</th>
        <th class="num" title="Z × √(predictive variance) — buffer for demand uncertainty over the lead time">Safety<br>Stock</th>
        <th class="num" title="Seasonality factor: this window's forecast vs. its deseasonalized baseline (1.0 = average season)">S</th>
        <th class="num" title="Growth factor: the model's annual demand multiplier, exp(trend) (1.0 = flat)">G</th>
        <th class="num">Par<br>Level</th>
        <th class="num">In Stock</th>
        <th class="num">Order<br>Request</th>
        <th>Unit</th>
        <th class="num" title="Whole cases to cover the Order Request, from the Count/Case on the Inventory page">Case<br>Request</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr data-name="<?= htmlspecialchars(strtolower($r['name'])) ?>">
          <td style="text-align:center;">
            <input type="checkbox" class="restock-cb"
                   data-name="<?= htmlspecialchars($r['name']) ?>"
                   data-cases="<?= (int)$r['cases'] ?>"
                   data-cpc="<?= htmlspecialchars((string)(float)$r['cpc']) ?>"
                   data-unit="<?= htmlspecialchars($r['unit']) ?>"
                   data-order="<?= htmlspecialchars((string)(float)$r['order']) ?>"
                   <?= $produceOnly ? 'checked' : '' ?>>
          </td>
          <td><strong><?= htmlspecialchars($r['name']) ?></strong><?php
              // ° marks rows using the trailing-average fallback (too little
              // history for the demand model). GLM-fitted rows get no marker.
              if ($r['method'] !== 'glm') echo '<span title="Trailing-average fallback — not enough history to fit the demand model" style="color:#999; cursor:help;">&nbsp;°</span>';
          ?></td>
          <td><?= htmlspecialchars($r['kind']) ?></td>
          <td class="num"><?= number_format($r['adv'], 2) ?></td>
          <td class="num"><?= number_format($r['sigma'], 2) ?></td>
          <td class="num"><?= number_format($r['safety'], 1) ?></td>
          <td class="num"><?= number_format($r['S'], 2) ?></td>
          <td class="num"><?= number_format($r['G'], 2) ?></td>
          <td class="num"><?= number_format($r['par'], 1) ?></td>
          <td class="num"><?php
              // "Ignore In Stock" recommends as if nothing is on hand, so show
              // every In Stock value as 0 to match what the calculation used —
              // the item still stays in the report.
              $dispStock = $ignoreStock ? 0.0 : (float)$r['stock'];
              echo $r['unit'] === 'each'
                  ? (string)(int)round($dispStock)   // 'each' items are whole units
                  : number_format($dispStock, 1);
          ?></td>
          <td class="num" style="font-weight:800; color:<?= $r['order']>0?'var(--red)':'#999' ?>;">
            <?= $r['unit'] === 'each'
                ? (int)ceil($r['order'])      // whole units only — round up so demand is fully covered
                : number_format($r['order'], 1) ?>
          </td>
          <td><?= htmlspecialchars($r['unit']) ?></td>
          <td class="num" style="font-weight:800;">
            <?php
              // "—" when Count/Case isn't set on the Inventory page; 0 when
              // it is set but nothing needs ordering.
              if ($r['cpc'] <= 0)      echo '—';
              else                     echo (int)$r['cases'];
            ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>

</div>
<script>
// Generic-name filter for the recommendations table (mirrors the
// Inventory page's filter). Rows carry data-name in lowercase.
function applyRepFilter() {
  var q = (document.getElementById('repSearch').value || '').trim().toLowerCase();
  var rows = document.querySelectorAll('#repTable tbody tr');
  var shown = 0;
  rows.forEach(function (tr) {
    var match = !q || (tr.dataset.name || '').indexOf(q) !== -1;
    tr.style.display = match ? '' : 'none';
    if (match) shown++;
  });
  document.getElementById('repCount').textContent =
    q ? (shown + ' matching') : (shown + ' items');
}

// Email subject, with the pantry name appended server-side when set.
var ORDER_SUBJECT = <?= json_encode($orderSubject, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

// Order actions read the per-row "Restock" checkboxes live. Each ticked
// row carries its cases / count-per-case / unit / order request in
// data-* attributes, so both the email and the inventory restock reflect
// exactly what's checked at click time.
function gatherCheckedItems() {
  var out = [];
  document.querySelectorAll('.restock-cb:checked').forEach(function (cb) {
    out.push({
      name:  cb.dataset.name,
      cases: parseInt(cb.dataset.cases, 10) || 0,
      cpc:   parseFloat(cb.dataset.cpc) || 0,
      unit:  cb.dataset.unit,
      order: parseFloat(cb.dataset.order) || 0
    });
  });
  return out;
}

function fmtNum(n) { return parseFloat(Number(n).toFixed(2)).toString(); }

// One email line per checked item. With a Count/Case set: the case request
// plus a "(count/case count|lb)" description, where "count" stands in for
// the 'each' unit. With Count/Case 0 or unavailable: only the Order
// Request quantity.
function emailLineFor(it) {
  if (it.cpc > 0) {
    var unitTxt = it.unit === 'each' ? 'count' : it.unit;
    return it.cases + ' case' + (it.cases === 1 ? '' : 's') +
           ' - ' + it.name + ' (' + fmtNum(it.cpc) + ' ' + unitTxt + ')';
  }
  var oq = it.unit === 'each' ? Math.ceil(it.order) : Math.round(it.order * 10) / 10;
  var oqUnit = it.unit === 'each' ? 'count' : it.unit;
  return fmtNum(oq) + ' ' + oqUnit + ' - ' + it.name;
}

// Quantity (in the item's unit) the order brings in: cases × count/case,
// or the Order Request when no Count/Case is set.
function restockQtyFor(it) {
  if (it.cpc > 0) return it.cases * it.cpc;
  return it.unit === 'each' ? Math.ceil(it.order) : Math.round(it.order * 100) / 100;
}

function setOrderMsg(text, ok) {
  var el = document.getElementById('orderMsg');
  if (!el) return;
  el.textContent = text;
  el.style.color = ok === false ? '#8B1A1A' : '#276437';
}

// Generate Email: open a Gmail draft (subject "New Order", no signature)
// listing one line per checked item.
function generateOrderEmail() {
  // Exclude anything with an Order Request of 0 (nothing actually needed),
  // matching the value shown in the table's Order Request column.
  var items = gatherCheckedItems().filter(function (it) {
    var req = it.unit === 'each' ? Math.ceil(it.order) : Math.round(it.order * 10) / 10;
    return req > 0;
  });
  if (!items.length) {
    alert('No checked items have an order request greater than 0.');
    return;
  }
  var body = items.map(emailLineFor).join('\n');
  var url = 'https://mail.google.com/mail/?view=cm&fs=1'
          + '&su=' + encodeURIComponent(ORDER_SUBJECT)
          + '&body=' + encodeURIComponent(body);
  // Open Gmail inside the click handler so it isn't popup-blocked.
  window.open(url, '_blank', 'noopener');
  recordEmailOrderTime();
  setOrderMsg('✅ Order email opened for ' + items.length + ' item(s).', true);
}

// Print Order: open a printer-friendly order sheet listing the checked
// items — the same lines the email uses — instead of mailing them.
function printOrder() {
  // Match Email Order: drop anything whose Order Request rounds to 0.
  var items = gatherCheckedItems().filter(function (it) {
    var req = it.unit === 'each' ? Math.ceil(it.order) : Math.round(it.order * 10) / 10;
    return req > 0;
  });
  if (!items.length) {
    alert('No checked items have an order request greater than 0.');
    return;
  }
  var esc = function (s) {
    return String(s).replace(/[&<>]/g, function (c) {
      return c === '&' ? '&amp;' : c === '<' ? '&lt;' : '&gt;';
    });
  };
  var lines = items.map(function (it) {
    return '<li>' + esc(emailLineFor(it)) + '</li>';
  }).join('');
  // The popup is its own about:blank document, so relative asset paths
  // won't resolve. Reuse the parent page's favicon via its already-
  // absolute .href so the print tab shows the app icon.
  var iconLink = document.querySelector('link[rel~="icon"]');
  var faviconTag = iconLink
    ? '<link rel="icon" type="image/x-icon" href="' + esc(iconLink.href) + '">'
    : '';
  var html = '<!DOCTYPE html><html><head><meta charset="utf-8">'
           + '<title>' + esc(ORDER_SUBJECT) + '</title>'
           + faviconTag
           + '<style>body{font-family:Arial,Helvetica,sans-serif;margin:40px;}'
           + 'h1{font-size:1.3rem;} ul{font-size:1rem;line-height:1.7;} li{margin-bottom:2px;}'
           + '</style></head><body>'
           + '<h1>' + esc(ORDER_SUBJECT) + '</h1>'
           + '<ul>' + lines + '</ul>'
           // Print once loaded, then close the helper tab when the print
           // dialog is dismissed (printed or cancelled) so it isn't left behind.
           + '<script>window.onafterprint=function(){window.close();};'
           + 'window.onload=function(){window.print();};<\/script>'
           + '</body></html>';
  // Open the sheet inside the click handler so it isn't popup-blocked.
  var w = window.open('', '_blank');
  if (!w) {
    setOrderMsg('⚠ Print Order was blocked — allow pop-ups and try again.', false);
    return;
  }
  w.document.open();
  w.document.write(html);
  w.document.close();
  setOrderMsg('✅ Order sheet opened for printing (' + items.length + ' item(s)).', true);
}

// Restock Now: add the ordered quantities for the checked items to
// inventory (a purchased restock, so it also feeds the Purchased % metric).
function restockNow() {
  var items = gatherCheckedItems();
  if (!items.length) {
    alert('Check at least one item’s Restock box to add it to inventory.');
    return;
  }
  var fd = new FormData();
  fd.append('purchased', '1'); // ordered from a supplier = purchased
  var n = 0;
  items.forEach(function (it) {
    var qty = restockQtyFor(it);
    if (qty > 0) {
      fd.append('item_name[]',  it.name);
      fd.append('item_count[]', qty);
      fd.append('item_unit[]',  it.unit);
      n++;
    }
  });
  if (!n) {
    setOrderMsg('Nothing to restock — the checked items have no quantity to add.', false);
    return;
  }
  fetch('../../restock/submit_restock.php', { method: 'POST', body: fd, credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (data && data.ok) {
        setOrderMsg('✅ Restocked ' + data.lines + ' item(s) into inventory.', true);
      } else {
        setOrderMsg('⚠ Restock failed: ' + ((data && data.error) || 'unknown error') + '.', false);
      }
    })
    .catch(function (err) {
      setOrderMsg('⚠ Restock request failed: ' + err.message + '.', false);
    });
}

// Persist + live-update the "Last ordered" stamp under the order buttons.
// Fires on every Generate Email click so the stamp reflects the latest send.
function recordEmailOrderTime() {
  var stamp = document.getElementById('lastOrderStamp');
  var fd = new FormData();
  fd.append('action', 'record_email_order');
  fetch('', { method: 'POST', body: fd, credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (data && data.ok && stamp) {
        var d = new Date(data.at.replace(' ', 'T'));
        var txt = isNaN(d.getTime())
          ? data.at
          : d.toLocaleString([], { month: 'short', day: 'numeric', year: 'numeric',
                                   hour: 'numeric', minute: '2-digit' });
        // Keep the label above the date so the stamp stays narrow.
        stamp.innerHTML = '<div>Last email order:</div>'
                        + '<div id="lastOrderDate" style="white-space:nowrap;"></div>';
        stamp.querySelector('#lastOrderDate').textContent = txt;
      }
    })
    .catch(function () { /* stamp just won't refresh until next reload */ });
}
</script>
<?php renderFoot(); ?>
