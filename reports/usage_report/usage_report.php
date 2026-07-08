<?php
// Item Usage Report — totals scans, quantity (packaged), and weight (produce)
// per generic name across a date range. Mirrors PantryPrep's item report but
// drops the category column (FoodScan has no categories).
$GLOBALS['FS_PREFIX'] = '../../';
require_once __DIR__ . '/../../common.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../event/event_types.php';
requireLogin();
$db = getDB();

date_default_timezone_set('America/New_York');

$defaultStart = date('Y-m-d');
$defaultEnd   = date('Y-m-d');
$dateStart = $_GET['date_start'] ?? $defaultStart;
$dateEnd   = $_GET['date_end']   ?? $defaultEnd;

// End-date is inclusive of the entire selected day, so a Start=End=04/16
// filter returns every scan from 00:00:00 through 23:59:59 on 04/16.
$rangeStart = $dateStart . ' 00:00:00';
$rangeEnd   = $dateEnd   . ' 23:59:59';

// Filter options
$allItems = $db->query("SELECT DISTINCT generic_name FROM scans ORDER BY generic_name")->fetchAll(PDO::FETCH_COLUMN);
$allKinds = ['packaged', 'produce'];

$selItems = $_GET['items'] ?? [];
$selKinds = $_GET['kinds'] ?? [];

// Order-type filter (multi-select), mirroring orders_listing.php. Options:
//   * "Pantry"     — unlabeled orders, i.e. the scanner-based in-pantry orders
//                    whose note doesn't carry a DELIVERY / EVENT / ORDER AHEAD prefix
//   * "Delivery"   — orders written by delivery/submit_delivery.php
//   * "OrderAhead" — orders written by orderahead/submit_orderahead.php
//   * each event type — orders written by event/submit_event.php for that type
// When nothing is selected, no type filter is applied and the table rolls all
// order types together into one "All" row per item.
// Event types follow the current picker list (Settings → Manage Events).
// Removing an event drops it from the filter and the per-type breakdown;
// orders of a removed type roll up under the generic "Event" label.
$eventTypesAll = eventTypes();
$allOrderTypes = array_merge(['Pantry', 'Delivery', 'OrderAhead'], $eventTypesAll);
$rawSelected   = $_GET['order_types'] ?? [];
if (!is_array($rawSelected)) $rawSelected = [];
$selectedTypes = array_values(array_intersect($allOrderTypes, $rawSelected));

// Delivery orders are persisted by delivery/submit_delivery.php with a
// note that starts with "DELIVERY · " — no PII is ever stored on the row, so
// the LIKE is the only signal we need to split delivery vs pickup.
$deliveryNoteSql = "o.note LIKE 'DELIVERY %'";

// Classify each order into one of the whitelisted order types from its note
// prefix. Event types come from the eventTypes() whitelist (no user input),
// quoted-escaped anyway; an EVENT-tagged note with an unknown type falls back
// to the generic "Event" label rather than leaking into Pantry.
$orderTypeCase = "CASE"
    . " WHEN $deliveryNoteSql THEN 'Delivery'"
    . " WHEN o.note LIKE 'ORDER AHEAD %' THEN 'OrderAhead'";
foreach ($eventTypesAll as $t) {
    $safe = str_replace("'", "''", $t);
    $orderTypeCase .= " WHEN o.note LIKE 'EVENT · {$safe} · %' THEN '{$safe}'";
}
$orderTypeCase .= " WHEN o.note LIKE 'EVENT %' THEN 'Event' ELSE 'Pantry' END";

$conditions = ["s.scanned_at >= :rs", "s.scanned_at <= :re"];
$params     = [':rs' => $rangeStart, ':re' => $rangeEnd];

if (!empty($selItems)) {
    $ph = [];
    foreach ($selItems as $i => $v) { $ph[] = ":it$i"; $params[":it$i"] = $v; }
    $conditions[] = "s.generic_name IN (" . implode(',', $ph) . ")";
}
if (!empty($selKinds)) {
    $ph = [];
    foreach ($selKinds as $i => $v) { $ph[] = ":k$i"; $params[":k$i"] = $v; }
    $conditions[] = "s.kind IN (" . implode(',', $ph) . ")";
}
if (!empty($selectedTypes)) {
    $ph = [];
    foreach ($selectedTypes as $i => $v) { $ph[] = ":ot$i"; $params[":ot$i"] = $v; }
    $conditions[] = "($orderTypeCase) IN (" . implode(',', $ph) . ")";
}
$where = implode(' AND ', $conditions);

// With order types selected, break each item out per type; with none selected,
// roll every order type together into a single "All" row per item.
$orderTypeSelect = !empty($selectedTypes) ? "($orderTypeCase)" : "'All'";

$sql = "
    SELECT
        s.generic_name,
        s.kind,
        $orderTypeSelect                      AS order_type,
        COUNT(*)                              AS scan_count,
        COALESCE(SUM(s.quantity), 0)          AS total_qty,
        COALESCE(SUM(s.weight_lbs), 0)        AS total_weight
    FROM scans s
    JOIN orders o ON o.id = s.order_id
    WHERE $where
    GROUP BY s.generic_name, s.kind, order_type
    ORDER BY s.generic_name, order_type
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

$oSql = "
    SELECT
        COUNT(DISTINCT s.order_id)                                                AS total_orders,
        COUNT(DISTINCT CASE WHEN $deliveryNoteSql THEN s.order_id END)            AS delivery_orders
    FROM scans s
    JOIN orders o ON o.id = s.order_id
    WHERE $where
";
$oStmt = $db->prepare($oSql);
$oStmt->execute($params);
$oRow = $oStmt->fetch(PDO::FETCH_ASSOC) ?: ['total_orders' => 0, 'delivery_orders' => 0];
$orderCount         = (int)$oRow['total_orders'];
$deliveryOrderCount = (int)$oRow['delivery_orders'];

$grandScans  = 0;
$grandQty    = 0;
$grandWeight = 0.0;
$maxBar      = 0; // for bar scaling — use the "natural" metric per type
// Chart bars roll all order types back together so each item is one bar,
// even when the table breaks them out as separate rows.
$chartAgg = [];
foreach ($results as $r) {
    $grandScans  += (int)$r['scan_count'];
    $grandQty    += (int)$r['total_qty'];
    $grandWeight += (float)$r['total_weight'];
    $nat = ($r['kind'] === 'produce') ? (float)$r['total_weight'] : (float)$r['total_qty'];
    if ($nat > $maxBar) $maxBar = $nat;

    $k = $r['generic_name'];
    if (!isset($chartAgg[$k])) {
        $chartAgg[$k] = [
            'generic_name' => $r['generic_name'],
            'kind'         => $r['kind'],
            'total_qty'    => 0,
            'total_weight' => 0.0,
        ];
    }
    $chartAgg[$k]['total_qty']    += (int)$r['total_qty'];
    $chartAgg[$k]['total_weight'] += (float)$r['total_weight'];
}
$chartRows = array_values($chartAgg);

function fmtAmount(array $r): string {
    if ($r['kind'] === 'produce') {
        return rtrim(rtrim(number_format((float)$r['total_weight'], 2, '.', ''), '0'), '.') . ' lb';
    }
    return (string)(int)$r['total_qty'] . ' each';
}
function fmtWeight(float $w): string {
    return rtrim(rtrim(number_format($w, 2, '.', ''), '0'), '.');
}
function orderTypePillClass(string $t): string {
    switch ($t) {
        case 'All':        return 'all';
        case 'Pantry':     return 'pantry';
        case 'Delivery':   return 'delivery';
        case 'OrderAhead': return 'order_ahead';
        default:           return 'event';
    }
}

renderHead('Item Usage Report');
renderNav('usage');
?>
<style>
  /* Report-specific extras layered on top of common.php */
  .type-pill { display:inline-block; font-size:.7rem; font-weight:700;
               text-transform:uppercase; letter-spacing:.5px; color:#fff;
               border-radius:4px; padding:2px 7px; }
  .type-pill.packaged { background: var(--brown); }
  .type-pill.produce  { background: var(--green); }
  .type-pill.delivery    { background: #2F6FA1; }
  .type-pill.pantry      { background: #888; }
  .type-pill.order_ahead { background: #5D7E2A; }
  .type-pill.event       { background: #8B5A2B; }
  .type-pill.all         { background: #555; }
  /* Order-type multi-select: a list of checkboxes inside a bordered box,
     same pattern as orders_listing.php. */
  .order-type-box {
    flex: 1 1 100%; display: flex; flex-wrap: wrap; gap: 6px 14px;
    align-items: center; padding: 8px 12px; background: #fff;
    border: 1px solid var(--border); border-radius: 6px;
  }
  .order-type-box > .lbl {
    font-size: .75rem; font-weight: 700; color: var(--brown);
    text-transform: uppercase; letter-spacing: .5px; margin-right: 6px;
  }
  .order-type-box label {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: .9rem; text-transform: none; cursor: pointer;
    margin: 0; font-weight: 600;
  }
  .order-type-box input { width: auto; margin: 0; }
  .qty-bar-wrap { display:flex; align-items:center; gap:8px; }
  .qty-bar { height:10px; border-radius:5px; min-width:4px; }
  .qty-bar.packaged { background: var(--brown); }
  .qty-bar.produce  { background: var(--green); }
  .qty-num { font-weight:700; color: var(--brown); white-space:nowrap; }
  .total-row td { font-weight:700; background: var(--cat-bg); border-top:2px solid var(--border); font-size:.88rem; }
  select[multiple] { height: 130px; padding: 4px; }
  .chart-wrap { padding: 12px; position: relative; }
  canvas { max-width: 100%; }
  .no-data { padding:30px; text-align:center; color:#999; font-size:.9rem; }
  @media print {
    .site-header, nav.subnav, .filter-card, .btn, form { display:none; }
  }
</style>

<div class="container">
  <form method="GET" id="reportForm">
    <div class="card filter-card">
      <h2>🔍 Filter Options</h2>
      <div class="row" style="align-items: stretch;">
        <div>
          <label for="date_start">Start Date</label>
          <input type="date" id="date_start" name="date_start" value="<?= htmlspecialchars($dateStart) ?>">
        </div>
        <div>
          <label for="date_end">End Date</label>
          <input type="date" id="date_end" name="date_end" value="<?= htmlspecialchars($dateEnd) ?>">
        </div>
        <div>
          <label>Type</label>
          <select name="kinds[]" multiple size="2" style="height:64px;">
            <?php foreach ($allKinds as $k): ?>
              <option value="<?= htmlspecialchars($k) ?>" <?= in_array($k, $selKinds, true) ? 'selected' : '' ?>><?= htmlspecialchars($k) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="flex: 2 1 240px;">
          <label>Generic Item</label>
          <select name="items[]" multiple id="sel_items">
            <?php foreach ($allItems as $it): ?>
              <option value="<?= htmlspecialchars($it) ?>" <?= in_array($it, $selItems, true) ? 'selected' : '' ?>><?= htmlspecialchars($it) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="row" style="margin-top: 14px;">
        <div class="order-type-box" role="group" aria-label="Order Type">
          <span class="lbl">Order Type:</span>
          <?php foreach ($allOrderTypes as $t):
            $checked = in_array($t, $selectedTypes, true);
          ?>
            <label>
              <input type="checkbox" name="order_types[]"
                     value="<?= htmlspecialchars($t) ?>"<?= $checked ? ' checked' : '' ?>>
              <?= htmlspecialchars($t) ?>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="row" style="margin-top: 14px; align-items:center;">
        <button type="submit" class="btn btn-primary" style="flex:0 0 160px;">📊 Run Report</button>
        <a href="" class="btn btn-secondary" style="flex:0 0 100px; text-align:center; text-decoration:none;">↺ Reset</a>
        <?php if (!empty($results)): ?>
          <button type="button" class="btn btn-secondary" style="flex:0 0 100px;" onclick="window.print()">🖨 Print</button>
        <?php endif; ?>
      </div>
    </div>
  </form>

  <?php if (isset($_GET['date_start'])): ?>

  <div class="card">
    <h2>At a Glance</h2>
    <div class="stat-grid">
      <div class="stat"><div class="v"><?= $orderCount ?></div><div class="k">Orders</div></div>
      <div class="stat"><div class="v"><?= $deliveryOrderCount ?></div><div class="k">Delivery Orders</div></div>
      <div class="stat"><div class="v"><?= $grandScans ?></div><div class="k">Scans</div></div>
      <div class="stat"><div class="v"><?= count($results) ?></div><div class="k">Item Types</div></div>
      <div class="stat"><div class="v"><?= $grandQty ?></div><div class="k">Packaged (each)</div></div>
      <div class="stat"><div class="v"><?= fmtWeight($grandWeight) ?></div><div class="k">Produce (lb)</div></div>
      <div class="stat"><div class="v" style="font-size:1rem;"><?= htmlspecialchars(date('M j', strtotime($dateStart))) ?> – <?= htmlspecialchars(date('M j', strtotime($dateEnd))) ?></div><div class="k">Date Range</div></div>
    </div>
  </div>

  <?php if (empty($results)): ?>
    <div class="card"><div class="no-data">⚠ No scans found for the selected filters and date range.</div></div>
  <?php else: ?>

  <?php
    // Full-width chart: horizontal bars need ~26px of height per item to stay
    // legible, vertical charts get a comfortable fixed height.
    $chartHeight = count($chartRows) > 8 ? max(340, count($chartRows) * 26 + 80) : 340;
  ?>
  <div class="card">
    <h2>📈 Quantity Chart</h2>
    <div class="chart-wrap" style="height:<?= $chartHeight ?>px;"><canvas id="reportChart"></canvas></div>
  </div>

  <div class="card">
    <h2>📋 Item Quantities</h2>
      <table class="data">
        <thead>
          <tr>
            <th>Generic Item</th>
            <th>Type</th>
            <th>Order Type</th>
            <th class="num">Scans</th>
            <th>Amount</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($results as $row):
            $natural = $row['kind'] === 'produce' ? (float)$row['total_weight'] : (float)$row['total_qty'];
            $pct = $maxBar > 0 ? min(80, round(($natural / $maxBar) * 80)) : 0;
          ?>
          <tr>
            <td><?= htmlspecialchars($row['generic_name']) ?></td>
            <td><span class="type-pill <?= htmlspecialchars($row['kind']) ?>"><?= htmlspecialchars($row['kind']) ?></span></td>
            <td>
              <span class="type-pill <?= orderTypePillClass($row['order_type']) ?>">
                <?= htmlspecialchars($row['order_type']) ?>
              </span>
            </td>
            <td class="num"><?= (int)$row['scan_count'] ?></td>
            <td>
              <div class="qty-bar-wrap">
                <div class="qty-bar <?= htmlspecialchars($row['kind']) ?>" style="width:<?= $pct ?>px;"></div>
                <span class="qty-num"><?= htmlspecialchars(fmtAmount($row)) ?></span>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr class="total-row">
            <td colspan="3">Grand Total</td>
            <td class="num"><?= $grandScans ?> scans</td>
            <td><?= $grandQty ?> each &nbsp;·&nbsp; <?= fmtWeight($grandWeight) ?> lb</td>
          </tr>
        </tfoot>
      </table>
  </div>

  <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
  <script>
  (function() {
    var rows = <?= json_encode(array_map(function($r) {
        $natural = $r['kind'] === 'produce' ? (float)$r['total_weight'] : (int)$r['total_qty'];
        return [
            'label'   => $r['generic_name'],
            'kind'    => $r['kind'],
            'value'   => $natural,
            'display' => fmtAmount($r),
        ];
    }, $chartRows)) ?>;
    var labels = rows.map(function(r){ return r.label; });
    var values = rows.map(function(r){ return r.value; });
    var colors = rows.map(function(r){ return r.kind === 'produce' ? '#8BAF3A' : '#6B4C11'; });

    new Chart(document.getElementById('reportChart'), {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [{
          label: 'Amount',
          data: values,
          backgroundColor: colors,
          borderRadius: 5,
          borderSkipped: false,
        }]
      },
      options: {
        indexAxis: <?= count($chartRows) > 8 ? "'y'" : "'x'" ?>,
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              title: function(ctx) { return ctx[0].label; },
              label: function(ctx) {
                var r = rows[ctx.dataIndex];
                return r.kind + ': ' + r.display;
              }
            }
          }
        },
        scales: {
          x: { grid: { color:'#F0EBD8' }, ticks: { font: { size: 11 } } },
          y: { grid: { color:'#F0EBD8' }, ticks: { font: { size: 11 } }, beginAtZero: true }
        }
      }
    });
  })();
  </script>

  <?php endif; ?>
  <?php endif; ?>

</div>
<?php renderFoot(); ?>
