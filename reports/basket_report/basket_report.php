<?php
// Basket Size Report — OpenPantry-only evidence against the "hoarding" claim.
//
// The abundance/trust thesis predicts that when people aren't rationed, they
// still take a modest, household-appropriate amount per trip. The scarcity /
// hoarding thesis predicts the opposite: a pile-up of maxed-out baskets and
// per-trip take that creeps upward over time.
//
// This report needs NO check-in data. It looks only at in-pantry ("Pantry")
// orders — the self-selected market trips — and characterizes the *per-order*
// basket: how big a typical basket is, the shape of the distribution (is there
// a spike at the top?), and whether basket size drifts upward month over month.
//
// "Size" is measured two ways, kept in their native units:
//   * items/order  = SUM(scans.quantity)   — packaged each + produce counts
//   * produce lbs  = SUM(scans.weight_lbs) — weighed produce only
// items/order is the headline metric (every order has it); produce lbs is shown
// alongside because heavy produce hauls are the most plausible "hoarding" path.
//
// Delivery / Event / OrderAhead orders are excluded: those baskets are packed
// by staff or pre-ordered, so they don't reflect a shopper's own restraint.
$GLOBALS['FS_PREFIX'] = '../../';
require_once __DIR__ . '/../../common.php';
require_once __DIR__ . '/../../auth.php';
requireLogin();
$db = getDB();

date_default_timezone_set('America/New_York');

// Default to a 12-month window: long enough for the distribution shape to be
// stable and for a year of monthly trend points to show consistency.
$defaultEnd   = date('Y-m-d');
$defaultStart = date('Y-m-d', strtotime('-365 days'));
$dateStart = $_GET['date_start'] ?? $defaultStart;
$dateEnd   = $_GET['date_end']   ?? $defaultEnd;

$rangeStart = $dateStart . ' 00:00:00';
$rangeEnd   = $dateEnd   . ' 23:59:59';

// Pantry = self-selected in-market trips. Mirrors the "Pantry" bucket in the
// Orders Listing report: everything whose note is unlabeled (NULL notes count
// as Pantry, hence the IS NULL branch).
$sql = "
    SELECT
        o.id                               AS oid,
        strftime('%Y-%m', o.started_at)    AS month,
        COALESCE(SUM(s.quantity), 0)       AS items,
        COALESCE(SUM(s.weight_lbs), 0)     AS lbs
    FROM orders o
    JOIN scans s ON s.order_id = o.id
    WHERE o.started_at >= :rs AND o.started_at <= :re
      AND o.status = 'closed'
      AND (o.note IS NULL OR (
            o.note NOT LIKE 'DELIVERY %' AND
            o.note NOT LIKE 'EVENT %'    AND
            o.note NOT LIKE 'ORDER AHEAD %'))
    GROUP BY o.id
    HAVING items > 0
";
$stmt = $db->prepare($sql);
$stmt->execute([':rs' => $rangeStart, ':re' => $rangeEnd]);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

$n = count($orders);
$hasData = $n > 0;

// ---- statistics helpers -------------------------------------------------
function pctile(array $sorted, float $p): float {
    // Linear-interpolation percentile on a pre-sorted ascending array.
    $c = count($sorted);
    if ($c === 0) return 0.0;
    if ($c === 1) return (float)$sorted[0];
    $rank = $p / 100 * ($c - 1);
    $lo = (int)floor($rank);
    $hi = (int)ceil($rank);
    if ($lo === $hi) return (float)$sorted[$lo];
    $frac = $rank - $lo;
    return $sorted[$lo] + ($sorted[$hi] - $sorted[$lo]) * $frac;
}

$items = array_map(function($r){ return (float)$r['items']; }, $orders);
$lbs   = array_map(function($r){ return (float)$r['lbs'];   }, $orders);
sort($items);
sort($lbs);

$mean   = $hasData ? array_sum($items) / $n : 0;
$median = $hasData ? pctile($items, 50) : 0;
$p90    = $hasData ? pctile($items, 90) : 0;
$p95    = $hasData ? pctile($items, 95) : 0;
$maxItm = $hasData ? end($items) : 0;
$lbsMed = $hasData ? pctile($lbs, 50) : 0;
$lbsP90 = $hasData ? pctile($lbs, 90) : 0;

// Skew indicator: a fat right tail of hoarders pulls the mean well above the
// median. ~1.0 means a symmetric, well-behaved distribution.
$skew = $median > 0 ? round($mean / $median, 2) : 0;

// Right-tail share: fraction of baskets more than 2x the median. Under
// rationing-driven hoarding this swells; under proportionate take it stays
// small (a few genuinely large households).
$tailCut = 2 * $median;
$tailShare = $hasData
    ? round(100 * count(array_filter($items, function($v) use ($tailCut){ return $v > $tailCut; })) / $n)
    : 0;

// ---- histogram ----------------------------------------------------------
// Pick a bucket width that yields ~18 bars across the range, rounded to a
// sensible integer, so the distribution shape reads clearly at any scale.
$binW = max(1, (int)ceil(($maxItm ?: 1) / 18));
$binCount = $hasData ? ((int)floor($maxItm / $binW) + 1) : 0;
$hist = array_fill(0, max($binCount, 1), 0);
foreach ($items as $v) {
    $b = (int)floor($v / $binW);
    if ($b >= count($hist)) $b = count($hist) - 1;
    $hist[$b]++;
}
$histLabels = [];
for ($b = 0; $b < count($hist); $b++) {
    $lo = $b * $binW;
    $hi = $lo + $binW - 1;
    $histLabels[] = $binW === 1 ? (string)$lo : ($lo . '–' . $hi);
}

// ---- monthly trend (median + p90 basket per month) ----------------------
$byMonth = [];
foreach ($orders as $r) $byMonth[$r['month']][] = (float)$r['items'];
ksort($byMonth);
$monthLabels = $monthMedian = $monthP90 = $monthCount = [];
foreach ($byMonth as $m => $vals) {
    sort($vals);
    $monthLabels[]  = date('M Y', strtotime($m . '-01'));
    $monthMedian[]  = round(pctile($vals, 50), 1);
    $monthP90[]     = round(pctile($vals, 90), 1);
    $monthCount[]   = count($vals);
}

renderHead('Basket Size Report');
renderNav('basket');
?>
<style>
  .no-data { padding:40px; text-align:center; color:#999; font-size:.9rem; }
  .chart-wrap { padding:12px; }
  canvas { max-width:100%; }
  .lede { font-size:.9rem; color:#555; line-height:1.5; }
  .lede strong { color:var(--brown); }
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
    <h2>What this shows</h2>
    <p class="lede">
      In-pantry baskets only (deliveries, events, and order-ahead excluded).
      The balanced share hypothesis predicts no <strong>spike of maxed-out baskets</strong>
      and per-trip take that <strong>climbs over time</strong>. The trust/abundance
      hypothesis predicts a <strong>single modest hump</strong> with a thin tail,
      <strong>flat across the months</strong>. Two quick tells below: the
      <strong>mean ÷ median</strong> ratio (near 1.0 = no fat tail of hoarders)
      and the <strong>big-basket share</strong> (baskets over 2× the median).
    </p>
  </div>

  <div class="card">
    <h2>At a Glance</h2>
    <div class="stat-grid">
      <div class="stat"><div class="v"><?= $n ?></div><div class="k">Pantry Baskets</div></div>
      <div class="stat"><div class="v"><?= round($median) ?></div><div class="k">Median Items/Basket</div></div>
      <div class="stat"><div class="v"><?= round($mean, 1) ?></div><div class="k">Mean Items/Basket</div></div>
      <div class="stat"><div class="v"><?= $skew ?></div><div class="k">Mean ÷ Median</div></div>
      <div class="stat"><div class="v"><?= round($p90) ?></div><div class="k">90th Pctile</div></div>
      <div class="stat"><div class="v"><?= round($maxItm) ?></div><div class="k">Largest Basket</div></div>
      <div class="stat"><div class="v"><?= $tailShare ?>%</div><div class="k">Baskets &gt; 2× Median</div></div>
      <div class="stat"><div class="v"><?= round($lbsMed, 1) ?></div><div class="k">Median Produce lb</div></div>
    </div>
  </div>

  <div class="card">
    <h2>📊 Basket Size Distribution</h2>
    <p class="lede" style="margin-bottom:10px;">
      Each bar = how many baskets held that many items. An unbalanced share would stack the
      bars up against the right edge; proportionate take makes one hump near the
      median with a thin right tail.
    </p>
    <div class="chart-wrap"><canvas id="histChart" style="max-height:380px;"></canvas></div>
  </div>

  <div class="card">
    <h2>📈 Basket Size Over Time</h2>
    <p class="lede" style="margin-bottom:10px;">
      Median and 90th-percentile basket per month. Flat lines mean people take
      the same modest amount today as they did months ago — no escalation.
    </p>
    <div class="chart-wrap"><canvas id="trendChart" style="max-height:360px;"></canvas></div>
  </div>

  <div class="card">
    <h2>📋 Monthly Detail</h2>
    <table class="data">
      <thead>
        <tr>
          <th>Month</th>
          <th class="num">Baskets</th>
          <th class="num">Median Items</th>
          <th class="num">90th Pctile Items</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($monthLabels as $i => $ml): ?>
        <tr>
          <td><?= htmlspecialchars($ml) ?></td>
          <td class="num"><?= $monthCount[$i] ?></td>
          <td class="num"><?= $monthMedian[$i] ?></td>
          <td class="num"><?= $monthP90[$i] ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
  <script>
  (function() {
    var histLabels = <?= json_encode($histLabels) ?>;
    var histData   = <?= json_encode(array_values($hist)) ?>;
    var medianVal  = <?= json_encode(round($median, 1)) ?>;

    new Chart(document.getElementById('histChart'), {
      type: 'bar',
      data: { labels: histLabels, datasets: [
        { label:'Baskets', data: histData, backgroundColor:'#8BAF3A', borderRadius:3 }
      ]},
      options: {
        responsive: true,
        plugins: {
          legend: { display:false },
          tooltip: { callbacks: { title: function(c){ return c[0].label + ' items'; },
                                   label: function(c){ return c.raw + ' baskets'; } } }
        },
        scales: {
          x: { title:{ display:true, text:'Items in basket', color:'#6B4C11', font:{size:11,weight:'bold'} },
               grid:{ color:'#F0EBD8' }, ticks:{ font:{size:11}, maxRotation:45 } },
          y: { beginAtZero:true, title:{ display:true, text:'Number of baskets', color:'#6B4C11', font:{size:11,weight:'bold'} },
               grid:{ color:'#F0EBD8' }, ticks:{ font:{size:11}, precision:0 } }
        }
      }
    });

    var mLabels = <?= json_encode($monthLabels) ?>;
    var mMedian = <?= json_encode($monthMedian) ?>;
    var mP90    = <?= json_encode($monthP90) ?>;

    new Chart(document.getElementById('trendChart'), {
      type: 'line',
      data: { labels: mLabels, datasets: [
        { label:'Median items/basket', data: mMedian, borderColor:'#6B4C11',
          backgroundColor:'rgba(107,76,17,.12)', borderWidth:2.5, pointRadius:3, tension:0.3, fill:false },
        { label:'90th pctile items/basket', data: mP90, borderColor:'#8BAF3A',
          backgroundColor:'rgba(139,175,58,.12)', borderWidth:2.5, pointRadius:3, tension:0.3,
          borderDash:[5,4], fill:false }
      ]},
      options: {
        responsive: true,
        interaction: { mode:'index', intersect:false },
        plugins: { legend: { position:'top' } },
        scales: {
          x: { grid:{ color:'#F0EBD8' }, ticks:{ font:{size:11}, maxRotation:45 } },
          y: { beginAtZero:true, grid:{ color:'#F0EBD8' },
               title:{ display:true, text:'Items per basket', color:'#6B4C11', font:{size:11,weight:'bold'} },
               ticks:{ font:{size:11} } }
        }
      }
    });
  })();
  </script>

  <?php else: ?>
    <div class="card"><div class="no-data">No pantry baskets found in the selected date range.</div></div>
  <?php endif; ?>

</div>
<?php renderFoot(); ?>
