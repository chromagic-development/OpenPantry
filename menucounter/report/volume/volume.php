<?php
require_once '../../db.php';
$db = getDB();

// ── Auth gate ─────────────────────────────────────────────────────────────────
function makeAuthToken($password) {
    return hash('sha256', 'fp_admin_' . $password);
}
function isAuthenticated($db) {
    // admin_password lives in openpantry.db (see foodscanSetting() in ../../db.php).
    $pw     = foodscanSetting('admin_password', 'admin');
    $cookie = $_COOKIE['fp_admin_auth'] ?? '';
    return $cookie !== '' && hash_equals(makeAuthToken($pw), $cookie);
}
if (!isAuthenticated($db)) {
    header('Location: ../admin/admin.php');
    exit;
}

// ── Date range defaults (last 30 days) ───────────────────────────────────────
$defaultEnd   = date('Y-m-d');
$defaultStart = date('Y-m-d', strtotime('-30 days'));

$dateStart = $_GET['date_start'] ?? $defaultStart;
$dateEnd   = $_GET['date_end']   ?? $defaultEnd;

// ── Query: daily order count and item count ───────────────────────────────────
$sql = "
    SELECT
        DATE(o.created_at)         AS day,
        COUNT(DISTINCT o.id)       AS order_count,
        COUNT(oi.id)               AS item_count
    FROM orders o
    LEFT JOIN order_items oi ON oi.order_id = o.id
    WHERE DATE(o.created_at) BETWEEN :ds AND :de
    GROUP BY DATE(o.created_at)
    ORDER BY DATE(o.created_at) ASC
";
$stmt = $db->prepare($sql);
$stmt->execute([':ds' => $dateStart, ':de' => $dateEnd]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Fill in missing days with zero ───────────────────────────────────────────
$dataByDay = [];
foreach ($rows as $r) {
    $dataByDay[$r['day']] = $r;
}

$allDays      = [];
$orderCounts  = [];
$itemCounts   = [];

$cur = strtotime($dateStart);
$end = strtotime($dateEnd);
while ($cur <= $end) {
    $d = date('Y-m-d', $cur);
    $allDays[]     = $d;
    $orderCounts[] = isset($dataByDay[$d]) ? (int)$dataByDay[$d]['order_count'] : 0;
    $itemCounts[]  = isset($dataByDay[$d]) ? (int)$dataByDay[$d]['item_count']  : 0;
    $cur = strtotime('+1 day', $cur);
}

// ── Totals ────────────────────────────────────────────────────────────────────
$totalOrders = array_sum($orderCounts);
$totalItems  = array_sum($itemCounts);
$numDays     = count($allDays);
$avgOrders   = $numDays > 0 ? round($totalOrders / $numDays, 1) : 0;
$avgItems    = $numDays > 0 ? round($totalItems  / $numDays, 1) : 0;
$peakOrders  = $totalOrders > 0 ? max($orderCounts) : 0;
$peakItems   = $totalItems  > 0 ? max($itemCounts)  : 0;

$hasData = ($totalOrders > 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<link rel="icon" type="image/x-icon" href="../../favicon.ico">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Footprints – Daily Volume Report</title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<style>
  :root { --brown:#6B4C11; --green:#8BAF3A; --light:#F5F0E8; --border:#D4C9A8; }
  * { box-sizing:border-box; margin:0; padding:0; }
  body { font-family:Arial,sans-serif; background:var(--light); color:#333; }

  /* ── Header (matches foodscan/index.php) ── */
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

  .page { max-width:1500px; margin:30px auto 60px; padding:0 16px; }
  h1 { font-size:1.3rem; color:var(--brown); margin-bottom:4px; }
  .subtitle { font-size:.84rem; color:#777; margin-bottom:22px; }

  /* ── Filter card ── */
  .filter-card { background:#fff; border:1px solid var(--border); border-radius:10px; overflow:hidden; box-shadow:0 2px 8px rgba(0,0,0,.06); margin-bottom:24px; }
  .card-header  { padding:12px 20px; background:#F0EBD8; border-bottom:1px solid var(--border); font-size:.9rem; font-weight:800; text-transform:uppercase; letter-spacing:.5px; color:var(--brown); }
  .filter-body  { padding:20px; display:grid; grid-template-columns:1fr 1fr; gap:16px; align-items:start; max-width:600px; }
  .filter-group label { display:block; font-size:.75rem; font-weight:700; text-transform:uppercase; letter-spacing:.4px; color:var(--brown); margin-bottom:6px; }
  .filter-group input[type="date"] { width:100%; border:1px solid var(--border); border-radius:6px; padding:7px 10px; font-size:.88rem; background:#fafaf5; }
  .filter-group input[type="date"]:focus { outline:none; border-color:var(--green); }
  .filter-footer { padding:14px 20px; background:#F5F0E8; border-top:1px solid var(--border); display:flex; gap:10px; align-items:center; }
  .btn { border:none; border-radius:6px; padding:9px 22px; font-size:.88rem; font-weight:700; cursor:pointer; transition:background .2s; }
  .btn-brown { background:var(--brown); color:#fff; }
  .btn-brown:hover { background:#8B6420; }
  .btn-outline { background:transparent; color:var(--brown); border:1px solid var(--brown); }
  .btn-outline:hover { background:var(--brown); color:#fff; }

  /* ── Stat boxes ── */
  .stats { display:flex; gap:16px; margin-bottom:22px; flex-wrap:wrap; }
  .stat-box { background:#fff; border:1px solid var(--border); border-radius:8px; padding:14px 22px; text-align:center; min-width:130px; box-shadow:0 1px 4px rgba(0,0,0,.05); }
  .stat-box .num { font-size:1.8rem; font-weight:800; color:var(--brown); }
  .stat-box .lbl { font-size:.73rem; text-transform:uppercase; letter-spacing:.4px; color:#888; margin-top:2px; }

  /* ── Chart card ── */
  .chart-card { background:#fff; border:1px solid var(--border); border-radius:10px; overflow:hidden; box-shadow:0 2px 8px rgba(0,0,0,.06); margin-bottom:22px; }
  .chart-wrap { padding:20px; }

  /* ── Data table ── */
  .data-table { width:100%; border-collapse:collapse; font-size:.85rem; }
  .data-table th { text-align:left; padding:9px 14px; font-size:.74rem; text-transform:uppercase; letter-spacing:.5px; background:#F5F0E8; color:var(--brown); border-bottom:1px solid var(--border); }
  .data-table td { padding:8px 14px; border-bottom:1px solid #F0EBD8; }
  .data-table tr:last-child td { border-bottom:none; }
  .data-table tr:hover td { background:#FAFAF5; }
  .data-table td:first-child { font-weight:600; }
  .bar-cell { display:flex; align-items:center; gap:8px; }
  .mini-bar { height:10px; border-radius:5px; min-width:4px; }
  .no-data { padding:40px; text-align:center; color:#999; font-size:.9rem; }

  @media print {
    .site-header, .filter-card, .btn { display:none; }
    .page { margin:0; }
  }
</style>
</head>
<body>

<header class="site-header">
  <img src="../../../logo.jpg" alt="Logo">
  <div class="header-text">
    <h1><span style="color:var(--green);">Open</span>Pantry</h1>
    <p>Inventory tracking management</p>
  </div>
  <div class="header-actions">
    <a href="../../orders/">← Orders</a>
    <a href="../../admin">⚙ Manage Items</a>
    <a href="../">📊 Item Report</a>
    <a href="../../admin/?logout=1">🔒 Log Out</a>
  </div>
</header>

<div class="page">
  <h1>Daily Order &amp; Item Volume</h1>
  <p class="subtitle">View the number of orders submitted and items picked per day over a date range.</p>

  <!-- ── Filter ── -->
  <form method="GET" id="reportForm">
  <div class="filter-card">
    <div class="card-header">🔍 Date Range</div>
    <div class="filter-body">
      <div class="filter-group">
        <label for="date_start">Start Date</label>
        <input type="date" id="date_start" name="date_start" value="<?= htmlspecialchars($dateStart) ?>">
      </div>
      <div class="filter-group">
        <label for="date_end">End Date</label>
        <input type="date" id="date_end" name="date_end" value="<?= htmlspecialchars($dateEnd) ?>">
      </div>
    </div>
    <div class="filter-footer">
      <button type="submit" class="btn btn-brown">📅 Run Report</button>
      <a href="" class="btn btn-outline">↺ Reset</a>
      <?php if ($hasData): ?>
        <button type="button" class="btn btn-outline" onclick="window.print()" style="margin-left:auto;">🖨 Print</button>
      <?php endif; ?>
    </div>
  </div>
  </form>

  <?php if ($hasData): ?>

  <!-- ── Stat boxes ── -->
  <div class="stats">
    <div class="stat-box"><div class="num"><?= $totalOrders ?></div><div class="lbl">Total Orders</div></div>
    <div class="stat-box"><div class="num"><?= $totalItems ?></div><div class="lbl">Total Items</div></div>
    <div class="stat-box"><div class="num"><?= $avgOrders ?></div><div class="lbl">Avg Orders/Day</div></div>
    <div class="stat-box"><div class="num"><?= $avgItems ?></div><div class="lbl">Avg Items/Day</div></div>
    <div class="stat-box"><div class="num"><?= $peakOrders ?></div><div class="lbl">Peak Orders</div></div>
    <div class="stat-box"><div class="num"><?= $peakItems ?></div><div class="lbl">Peak Items</div></div>
    <div class="stat-box"><div class="num"><?= $numDays ?></div><div class="lbl">Days in Range</div></div>
  </div>

  <!-- ── Chart ── -->
  <div class="chart-card">
    <div class="card-header">📈 Daily Volume Chart</div>
    <div class="chart-wrap">
      <canvas id="volumeChart" style="max-height:400px;"></canvas>
    </div>
  </div>

  <!-- ── Data table ── -->
  <div class="chart-card">
    <div class="card-header">📋 Daily Breakdown</div>
    <table class="data-table">
      <thead>
        <tr>
          <th>Date</th>
          <th>Day</th>
          <th>Orders</th>
          <th>Items Picked</th>
          <th>Avg Items/Order</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $maxOrd = $peakOrders > 0 ? $peakOrders : 1;
        $maxItm = $peakItems  > 0 ? $peakItems  : 1;
        foreach ($allDays as $idx => $d):
            $ord = $orderCounts[$idx];
            $itm = $itemCounts[$idx];
            $avg = $ord > 0 ? round($itm / $ord, 1) : '—';
            $ordPct = round(($ord / $maxOrd) * 80);
            $itmPct = round(($itm / $maxItm) * 80);
        ?>
        <tr<?= $ord === 0 ? ' style="opacity:.4"' : '' ?>>
          <td><?= date('M j, Y', strtotime($d)) ?></td>
          <td><?= date('D', strtotime($d)) ?></td>
          <td>
            <div class="bar-cell">
              <div class="mini-bar" style="width:<?= $ordPct ?>px;background:var(--brown);"></div>
              <strong><?= $ord ?></strong>
            </div>
          </td>
          <td>
            <div class="bar-cell">
              <div class="mini-bar" style="width:<?= $itmPct ?>px;background:var(--green);"></div>
              <strong><?= $itm ?></strong>
            </div>
          </td>
          <td><?= $avg ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <script>
  (function() {
    var labels  = <?= json_encode(array_map(function($d) { return date('M j', strtotime($d)); }, $allDays)) ?>;
    var orders  = <?= json_encode($orderCounts) ?>;
    var items   = <?= json_encode($itemCounts) ?>;

    new Chart(document.getElementById('volumeChart'), {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [
          {
            label: 'Orders',
            data: orders,
            backgroundColor: '#6B4C11',
            borderRadius: 4,
            yAxisID: 'yOrders',
            order: 2
          },
          {
            label: 'Items Picked',
            data: items,
            type: 'line',
            borderColor: '#8BAF3A',
            backgroundColor: 'rgba(139,175,58,.15)',
            borderWidth: 2.5,
            pointRadius: 3,
            pointHoverRadius: 5,
            fill: true,
            tension: 0.3,
            yAxisID: 'yItems',
            order: 1
          }
        ]
      },
      options: {
        responsive: true,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { position: 'top' },
          tooltip: {
            callbacks: {
              label: function(ctx) {
                return ctx.dataset.label + ': ' + ctx.raw;
              }
            }
          }
        },
        scales: {
          x: {
            grid: { color: '#F0EBD8' },
            ticks: { font: { size: 11 }, maxRotation: 45 }
          },
          yOrders: {
            position: 'left',
            grid: { color: '#F0EBD8' },
            title: { display: true, text: 'Orders', color: '#6B4C11', font: { size: 11, weight: 'bold' } },
            ticks: {
              font: { size: 11 }, precision: 0,
              callback: function(v) { return Number.isInteger(v) ? v : null; }
            },
            beginAtZero: true
          },
          yItems: {
            position: 'right',
            grid: { drawOnChartArea: false },
            title: { display: true, text: 'Items Picked', color: '#8BAF3A', font: { size: 11, weight: 'bold' } },
            ticks: {
              font: { size: 11 }, precision: 0,
              callback: function(v) { return Number.isInteger(v) ? v : null; }
            },
            beginAtZero: true
          }
        }
      }
    });
  })();
  </script>

  <?php else: ?>
    <div class="chart-card"><div class="no-data">No orders found in the selected date range.</div></div>
  <?php endif; ?>

</div><!-- .page -->

<footer style="text-align:center; padding:24px 16px; font-size:.78rem; color:#999; border-top:1px solid var(--border); margin-top:40px;">
  &copy; 2026 <strong>Chromagic Development</strong> &mdash; OpenPantry, by
  <a href="mailto:chromagic@gmail.com" style="color:var(--brown); text-decoration:none; font-weight:600;">Bruce Alexander</a>.
  Released under the
  <a href="../../../LICENSE" style="color:var(--brown); text-decoration:none;">MIT License</a>.
</footer>

</body>
</html>
