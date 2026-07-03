<?php
// Daily Order & Item Volume Report.
// Per day in the selected range: number of distinct orders touched by a scan
// and the scan count. Missing days are zero-filled.
$GLOBALS['FS_PREFIX'] = '../../';
require_once __DIR__ . '/../../common.php';
require_once __DIR__ . '/../../auth.php';
requireLogin();
$db = getDB();

date_default_timezone_set('America/New_York');

$defaultEnd   = date('Y-m-d');
$defaultStart = date('Y-m-d', strtotime('-30 days'));
$dateStart = $_GET['date_start'] ?? $defaultStart;
$dateEnd   = $_GET['date_end']   ?? $defaultEnd;

// End-date is inclusive of the entire selected day, so a Start=End=04/16
// filter returns every scan from 00:00:00 through 23:59:59 on 04/16.
$rangeStart = $dateStart . ' 00:00:00';
$rangeEnd   = $dateEnd   . ' 23:59:59';

$sql = "
    SELECT
        DATE(s.scanned_at)              AS day,
        COUNT(DISTINCT s.order_id)      AS order_count,
        COUNT(s.id)                     AS scan_count,
        COALESCE(SUM(s.quantity), 0)    AS qty_total,
        COALESCE(SUM(s.weight_lbs), 0)  AS weight_total
    FROM scans s
    WHERE s.scanned_at >= :rs AND s.scanned_at <= :re
    GROUP BY DATE(s.scanned_at)
    ORDER BY DATE(s.scanned_at) ASC
";
$stmt = $db->prepare($sql);
$stmt->execute([':rs' => $rangeStart, ':re' => $rangeEnd]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$byDay = [];
foreach ($rows as $r) $byDay[$r['day']] = $r;

$allDays = $orderCounts = $scanCounts = $qtyTotals = $weightTotals = [];
$cur = strtotime($dateStart);
$end = strtotime($dateEnd);
while ($cur <= $end) {
    $d = date('Y-m-d', $cur);
    $allDays[]      = $d;
    $orderCounts[]  = isset($byDay[$d]) ? (int)$byDay[$d]['order_count']   : 0;
    $scanCounts[]   = isset($byDay[$d]) ? (int)$byDay[$d]['scan_count']    : 0;
    $qtyTotals[]    = isset($byDay[$d]) ? (int)$byDay[$d]['qty_total']     : 0;
    $weightTotals[] = isset($byDay[$d]) ? (float)$byDay[$d]['weight_total']: 0.0;
    $cur = strtotime('+1 day', $cur);
}

$totalOrders = array_sum($orderCounts);
$totalScans  = array_sum($scanCounts);
$totalQty    = array_sum($qtyTotals);
$totalWeight = array_sum($weightTotals);
$numDays     = count($allDays);
$avgOrders   = $numDays > 0 ? round($totalOrders / $numDays, 1) : 0;
$avgScans    = $numDays > 0 ? round($totalScans  / $numDays, 1) : 0;
$peakOrders  = $totalOrders > 0 ? max($orderCounts) : 0;
$peakScans   = $totalScans  > 0 ? max($scanCounts)  : 0;
$hasData = ($totalOrders > 0);

function fmtWeight(float $w): string {
    return rtrim(rtrim(number_format($w, 2, '.', ''), '0'), '.');
}

renderHead('Daily Volume Report');
renderNav('volume');
?>
<style>
  .bar-cell { display:flex; align-items:center; gap:8px; justify-content:flex-end; }
  .mini-bar { height:10px; border-radius:5px; min-width:4px; }
  .no-data { padding:40px; text-align:center; color:#999; font-size:.9rem; }
  .chart-wrap { padding:12px; }
  canvas { max-width:100%; }
  @media print {
    .site-header, nav.subnav, .filter-card, .btn, form { display:none; }
  }
</style>

<div class="container">
  <form method="GET" id="reportForm">
    <div class="card filter-card">
      <h2>🔍 Date Range</h2>
      <div class="row">
        <div>
          <label for="date_start">Start Date</label>
          <input type="date" id="date_start" name="date_start" value="<?= htmlspecialchars($dateStart) ?>">
        </div>
        <div>
          <label for="date_end">End Date</label>
          <input type="date" id="date_end" name="date_end" value="<?= htmlspecialchars($dateEnd) ?>">
        </div>
      </div>
      <div class="row" style="margin-top:14px;">
        <button type="submit" class="btn btn-primary" style="flex:0 0 160px;">📅 Run Report</button>
        <a href="" class="btn btn-secondary" style="flex:0 0 100px; text-align:center; text-decoration:none;">↺ Reset</a>
        <?php if ($hasData): ?>
          <button type="button" class="btn btn-secondary" style="flex:0 0 100px;" onclick="window.print()">🖨 Print</button>
        <?php endif; ?>
      </div>
    </div>
  </form>

  <?php if ($hasData): ?>

  <div class="card">
    <h2>At a Glance</h2>
    <div class="stat-grid">
      <div class="stat"><div class="v"><?= $totalOrders ?></div><div class="k">Total Orders</div></div>
      <div class="stat"><div class="v"><?= $totalScans ?></div><div class="k">Total Scans</div></div>
      <div class="stat"><div class="v"><?= $avgOrders ?></div><div class="k">Avg Orders/Day</div></div>
      <div class="stat"><div class="v"><?= $avgScans ?></div><div class="k">Avg Scans/Day</div></div>
      <div class="stat"><div class="v"><?= $peakOrders ?></div><div class="k">Peak Orders</div></div>
      <div class="stat"><div class="v"><?= $peakScans ?></div><div class="k">Peak Scans</div></div>
      <div class="stat"><div class="v"><?= $numDays ?></div><div class="k">Days in Range</div></div>
    </div>
  </div>

  <div class="card">
    <h2>📈 Daily Volume Chart</h2>
    <div class="chart-wrap"><canvas id="volumeChart" style="max-height:400px;"></canvas></div>
  </div>

  <div class="card">
    <h2>📋 Daily Breakdown</h2>
    <table class="data">
      <thead>
        <tr>
          <th>Date</th>
          <th>Day</th>
          <th class="num">Orders</th>
          <th class="num">Scans</th>
          <th class="num">Packaged (each)</th>
          <th class="num">Produce (lb)</th>
          <th class="num">Avg Scans/Order</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $maxOrd  = $peakOrders > 0 ? $peakOrders : 1;
        $maxScan = $peakScans  > 0 ? $peakScans  : 1;
        foreach ($allDays as $idx => $d):
            $ord = $orderCounts[$idx];
            $scn = $scanCounts[$idx];
            $q   = $qtyTotals[$idx];
            $w   = $weightTotals[$idx];
            $avg = $ord > 0 ? round($scn / $ord, 1) : '—';
            $ordPct  = round(($ord / $maxOrd)  * 80);
            $scanPct = round(($scn / $maxScan) * 80);
        ?>
        <tr<?= $ord === 0 ? ' style="opacity:.4"' : '' ?>>
          <td><?= date('M j, Y', strtotime($d)) ?></td>
          <td><?= date('D', strtotime($d)) ?></td>
          <td class="num">
            <div class="bar-cell">
              <div class="mini-bar" style="width:<?= $ordPct ?>px;background:var(--brown);"></div>
              <strong><?= $ord ?></strong>
            </div>
          </td>
          <td class="num">
            <div class="bar-cell">
              <div class="mini-bar" style="width:<?= $scanPct ?>px;background:var(--green);"></div>
              <strong><?= $scn ?></strong>
            </div>
          </td>
          <td class="num"><?= $q ?></td>
          <td class="num"><?= fmtWeight($w) ?></td>
          <td class="num"><?= $avg ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
  <script>
  (function() {
    var labels = <?= json_encode(array_map(function($d){ return date('M j', strtotime($d)); }, $allDays)) ?>;
    var orders = <?= json_encode($orderCounts) ?>;
    var scans  = <?= json_encode($scanCounts) ?>;

    new Chart(document.getElementById('volumeChart'), {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [
          { label:'Orders', data: orders, backgroundColor:'#6B4C11', borderRadius:4, yAxisID:'yOrders', order:2 },
          { label:'Scans', data: scans, type:'line', borderColor:'#8BAF3A',
            backgroundColor:'rgba(139,175,58,.15)', borderWidth:2.5, pointRadius:3, pointHoverRadius:5,
            fill:true, tension:0.3, yAxisID:'yScans', order:1 }
        ]
      },
      options: {
        responsive: true,
        interaction: { mode:'index', intersect:false },
        plugins: {
          legend: { position:'top' },
          tooltip: { callbacks: { label: function(ctx) { return ctx.dataset.label + ': ' + ctx.raw; } } }
        },
        scales: {
          x: { grid: { color:'#F0EBD8' }, ticks: { font:{size:11}, maxRotation:45 } },
          yOrders: {
            position:'left', grid: { color:'#F0EBD8' },
            title: { display:true, text:'Orders', color:'#6B4C11', font:{size:11, weight:'bold'} },
            ticks: { font:{size:11}, precision:0, callback: function(v){ return Number.isInteger(v) ? v : null; } },
            beginAtZero:true
          },
          yScans: {
            position:'right', grid: { drawOnChartArea:false },
            title: { display:true, text:'Scans', color:'#8BAF3A', font:{size:11, weight:'bold'} },
            ticks: { font:{size:11}, precision:0, callback: function(v){ return Number.isInteger(v) ? v : null; } },
            beginAtZero:true
          }
        }
      }
    });
  })();
  </script>

  <?php else: ?>
    <div class="card"><div class="no-data">No scans found in the selected date range.</div></div>
  <?php endif; ?>

</div>
<?php renderFoot(); ?>
