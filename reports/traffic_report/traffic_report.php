<?php
// Client Traffic Report — when do clients actually arrive?
//
// Reads PantryPrep's picklist.db (one orders row per client at the menu
// counter) and buckets every order into the half hour it was placed, per day
// of the week. Each bar is the AVERAGE number of clients in that half hour on
// a typical operating day of that weekday, so a range covering 12 Tuesdays and
// 3 Mondays still compares like with like. Staffing is the intended use:
// which half hours need the most volunteers on the floor.
//
// The chart covers exactly the pantry's posted hours of operation (TR_HOURS
// below): every scheduled half hour gets a bar, even one nobody came in. An
// operating day is a scheduled weekday with at least TR_MIN_DAY_ORDERS orders
// inside its hours, so a holiday closure with a stray test order doesn't count
// as a near-empty pantry day and drag that weekday's averages down. Orders
// outside the hours (stragglers after closing, admin tests) or on a closed day
// are counted and reported, not charted.
$GLOBALS['FS_PREFIX'] = '../../';
require_once __DIR__ . '/../../common.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../delivery/db.php'; // picklistDB(): the shared picklist.db handle
requireLogin();

date_default_timezone_set('America/New_York');

define('TR_MIN_DAY_ORDERS', 5);

// Posted hours of operation, Eastern, by ISO weekday (1 = Monday). Each entry
// is one open session as [opens, closes]; times must fall on the half hour.
// Edit here when the schedule changes.
const TR_HOURS = [
    1 => [['09:00', '11:00']],
    2 => [['10:00', '13:00'], ['17:00', '19:00']],
    3 => [['14:00', '18:00']],
    4 => [['10:30', '13:30']],
    5 => [['10:00', '13:00']],
];

// Half-hour slot numbers (hour*2 + 1 for :30, so 0..47) covered by one
// weekday's sessions, in time order. The closing time's slot is excluded:
// 9–11 AM is 9:00, 9:30, 10:00 and 10:30.
function trOpenSlots(int $dow): array {
    $slots = [];
    foreach (TR_HOURS[$dow] ?? [] as [$open, $close]) {
        [$oh, $om] = array_map('intval', explode(':', $open));
        [$ch, $cm] = array_map('intval', explode(':', $close));
        for ($s = $oh * 2 + intdiv($om, 30); $s < $ch * 2 + intdiv($cm, 30); $s++) $slots[] = $s;
    }
    return $slots;
}

// Query-string dates feed strtotime()/DateTime, so reject anything that isn't
// a real Y-m-d before it gets that far.
function trValidDate($v): bool {
    if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return false;
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return $d !== false && $d->format('Y-m-d') === $v;
}

// Default to the trailing 90 days: about 13 of each weekday, enough for steady
// averages while still reflecting the current schedule.
$dateStart = isset($_GET['date_start']) && trValidDate($_GET['date_start'])
    ? $_GET['date_start'] : date('Y-m-d', strtotime('-90 days'));
$dateEnd   = isset($_GET['date_end']) && trValidDate($_GET['date_end'])
    ? $_GET['date_end'] : date('Y-m-d');

// picklist orders.created_at is SQLite CURRENT_TIMESTAMP — UTC. Half-hour
// buckets can't tolerate the 4–5 hour shift the month-level reports shrug off,
// so the Eastern date range is converted to UTC for the query and each row is
// converted back to Eastern (DST-aware) before bucketing.
$utc = new DateTimeZone('UTC');
$local = new DateTimeZone('America/New_York');
$rangeStart = (new DateTime($dateStart . ' 00:00:00', $local))->setTimezone($utc)->format('Y-m-d H:i:s');
$rangeEnd   = (new DateTime($dateEnd   . ' 23:59:59', $local))->setTimezone($utc)->format('Y-m-d H:i:s');

$pdb = picklistDB();
$stamps = [];
$dbError = '';
if ($pdb !== null) {
    try {
        $q = $pdb->prepare("SELECT created_at FROM orders
                            WHERE created_at >= :rs AND created_at <= :re");
        $q->execute([':rs' => $rangeStart, ':re' => $rangeEnd]);
        $stamps = $q->fetchAll(PDO::FETCH_COLUMN);
    } catch (\Throwable $e) {
        error_log('OpenPantry traffic report: ' . $e->getMessage());
        $dbError = 'The menu counter database could not be read.';
    }
} else {
    $dbError = 'No menu counter database (picklist.db) was found.';
}

// ---- bucket every order by local date and half hour ---------------------
// $byDate[Y-m-d] = ['dow' => 1..7 (ISO, Mon=1), 'slots' => [slot => count]]
// slot = hour*2 + (minute >= 30), so 0..47.
$byDate = [];
foreach ($stamps as $ts) {
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', (string)$ts, $utc);
    if ($dt === false) continue;
    $dt->setTimezone($local);
    $d = $dt->format('Y-m-d');
    $slot = (int)$dt->format('G') * 2 + ((int)$dt->format('i') >= 30 ? 1 : 0);
    if (!isset($byDate[$d])) $byDate[$d] = ['dow' => (int)$dt->format('N'), 'slots' => []];
    $byDate[$d]['slots'][$slot] = ($byDate[$d]['slots'][$slot] ?? 0) + 1;
}

// Per weekday: operating days and total in-hours orders per slot. Everything
// else (outside the posted hours, or on a day that wasn't really open) lands
// in $offHours.
$dayNames  = [1 => 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
$openSlots = [];
foreach (array_keys(TR_HOURS) as $dow) $openSlots[$dow] = array_flip(trOpenSlots($dow));
$opDays    = array_fill_keys(array_keys($dayNames), 0);
$slotTotal = [];   // [dow][slot] => orders
$totalOrders = count($stamps);
$offHours = 0;
foreach ($byDate as $info) {
    $dow = $info['dow'];
    $inHours = array_intersect_key($info['slots'], $openSlots[$dow] ?? []);
    $n = array_sum($inHours);
    $offHours += array_sum($info['slots']) - $n;
    if ($n < TR_MIN_DAY_ORDERS) { $offHours += $n; continue; }
    $opDays[$dow]++;
    foreach ($inHours as $s => $c) $slotTotal[$dow][$s] = ($slotTotal[$dow][$s] ?? 0) + $c;
}

// Label a half-hour slot compactly for the axis ("10:30a") and fully for the
// tooltip / table ("10:30–11:00 AM").
function trSlotShort(int $s): string {
    $h = intdiv($s, 2); $m = ($s % 2) ? '30' : '00';
    return ((($h + 11) % 12) + 1) . ':' . $m . ($h < 12 ? 'a' : 'p');
}
function trSlotLong(int $s): string {
    $fmt = function (int $s): array {
        $h = intdiv($s % 48, 2);
        return [((($h + 11) % 12) + 1) . ':' . (($s % 2) ? '30' : '00'), $h < 12 ? 'AM' : 'PM'];
    };
    [$a, $ap] = $fmt($s);
    [$b, $bp] = $fmt($s + 1);
    return $ap === $bp ? "{$a}–{$b} {$bp}" : "{$a} {$ap}–{$b} {$bp}";
}

// Posted hours as text for the page, e.g. "Tue 10:00 AM–1:00 PM, 5:00–7:00 PM".
function trTime(string $hm): array {
    [$h, $m] = array_map('intval', explode(':', $hm));
    return [((($h + 11) % 12) + 1) . ':' . sprintf('%02d', $m), $h < 12 ? 'AM' : 'PM'];
}
$hoursText = [];
foreach (TR_HOURS as $dow => $sessions) {
    $parts = [];
    foreach ($sessions as [$open, $close]) {
        [$a, $ap] = trTime($open);
        [$b, $bp] = trTime($close);
        $parts[] = $ap === $bp ? "{$a}–{$b} {$bp}" : "{$a} {$ap}–{$b} {$bp}";
    }
    $hoursText[] = substr($dayNames[$dow], 0, 3) . ' ' . implode(', ', $parts);
}

// ---- build the bars: every posted half hour, weekday by weekday ---------
// A weekday with no operating days in the range is skipped — there is
// nothing to average over.
$bars = [];   // each: dow, slot, avg, total, days
$closedWeekdays = [];
foreach (array_keys(TR_HOURS) as $dow) {
    if ($opDays[$dow] === 0) { $closedWeekdays[] = $dayNames[$dow]; continue; }
    foreach (trOpenSlots($dow) as $s) {
        $tot = $slotTotal[$dow][$s] ?? 0;
        $bars[] = [
            'dow'   => $dow,
            'slot'  => $s,
            'avg'   => round($tot / $opDays[$dow], 1),
            'total' => $tot,
            'days'  => $opDays[$dow],
        ];
    }
}

$hasData = $bars !== [];
$totalOpDays = array_sum($opDays);
$peak = null;
foreach ($bars as $b) if ($peak === null || $b['avg'] > $peak['avg']) $peak = $b;

// Chart payload. Each weekday's bars share a color, get a divider before them,
// and a day-name header drawn above the group (see dayHeaders below).
$chartLabels = $chartData = $chartTips = $chartColors = $dayStarts = $dayShort = [];
$breaks = [];   // bars that open a second session the same day (Tuesday evening)
$palette = ['#8BAF3A', '#6B4C11'];
$prevDow = null; $prevSlot = null; $dayIdx = -1;
foreach ($bars as $i => $b) {
    $first = $b['dow'] !== $prevDow;
    if ($first) { $dayIdx++; $dayStarts[] = $i; $dayShort[] = substr($dayNames[$b['dow']], 0, 3); }
    elseif ($b['slot'] !== $prevSlot + 1) $breaks[] = $i;
    $chartLabels[] = trSlotShort($b['slot']);
    $chartData[]   = $b['avg'];
    $chartTips[]   = [
        'title' => $dayNames[$b['dow']] . ' ' . trSlotLong($b['slot']),
        'total' => $b['total'],
        'days'  => $b['days'],
        'day'   => $dayNames[$b['dow']],
    ];
    $chartColors[] = $palette[$dayIdx % 2];
    $prevDow = $b['dow'];
    $prevSlot = $b['slot'];
}

renderHead('Client Traffic Report');
renderNav('traffic');
?>
<style>
  .no-data { padding:40px; text-align:center; color:#999; font-size:.9rem; }
  .chart-wrap { padding:12px; position:relative; height:420px; }
  .lede { font-size:.9rem; color:#555; line-height:1.5; }
  .lede strong { color:var(--brown); }
  table.data td.day { font-weight:700; color:var(--brown); }
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
        <a href="?" class="btn btn-secondary" style="flex:0 0 100px; text-align:center; text-decoration:none;">↺ Reset</a>
        <?php if ($hasData): ?>
          <button type="button" class="btn btn-secondary" style="flex:0 0 100px;" onclick="window.print()">🖨 Print</button>
        <?php endif; ?>
      </div>
    </div>
  </form>

  <?php if ($dbError !== ''): ?>
    <div class="card"><div class="no-data"><?= htmlspecialchars($dbError) ?></div></div>
  <?php elseif ($hasData): ?>

  <div class="card">
    <h2>At a Glance</h2>
    <div class="stat-grid">
      <div class="stat"><div class="v"><?= $totalOrders ?></div><div class="k">Client Orders</div></div>
      <div class="stat"><div class="v"><?= $totalOpDays ?></div><div class="k">Operating Days</div></div>
      <div class="stat"><div class="v"><?= $peak['avg'] ?></div><div class="k">Busiest Half Hour (avg)</div></div>
      <div class="stat"><div class="v" style="font-size:1.05rem; line-height:1.9rem;"><?= htmlspecialchars(substr($dayNames[$peak['dow']], 0, 3) . ' ' . trSlotLong($peak['slot'])) ?></div><div class="k">Busiest Half Hour</div></div>
      <div class="stat"><div class="v"><?= $offHours ?></div><div class="k">Outside Hours (not charted)</div></div>
    </div>
  </div>

  <div class="card">
    <h2>📊 Client Traffic</h2>
    <p class="lede" style="margin-bottom:10px;">
      Each bar is the <strong>average number of client orders</strong> placed at the
      menu counter and closely aligned with scan station order volume in that half hour on a typical operating day of that weekday.
      Hover a bar for the total and how many of that weekday were averaged — every weekday is averaged over its own open days.
      Therefore, the results reflect the typical traffic flow .
    </p>
    <p class="lede" style="margin-bottom:10px;">
      <strong>Hours of operation:</strong> <?= htmlspecialchars(implode(' · ', $hoursText)) ?>
    </p>
    <div class="chart-wrap"><canvas id="trafficChart"></canvas></div>
  </div>

  <div class="card">
    <h2>📋 Half-Hour Detail</h2>
    <table class="data">
      <thead>
        <tr>
          <th>Day</th>
          <th>Interval</th>
          <th class="num">Avg Clients</th>
          <th class="num">Total Clients</th>
          <th class="num">Days Averaged</th>
        </tr>
      </thead>
      <tbody>
        <?php $prev = null; foreach ($bars as $b): ?>
        <tr>
          <td class="day"><?= $b['dow'] !== $prev ? htmlspecialchars($dayNames[$b['dow']]) : '' ?></td>
          <td><?= htmlspecialchars(trSlotLong($b['slot'])) ?></td>
          <td class="num"><?= $b['avg'] ?></td>
          <td class="num"><?= $b['total'] ?></td>
          <td class="num"><?= $b['days'] ?></td>
        </tr>
        <?php $prev = $b['dow']; endforeach; ?>
      </tbody>
    </table>
    <p class="lede" style="margin-top:10px; font-size:.8rem;">
      Times are Eastern. An operating day is a scheduled day with at least
      <?= TR_MIN_DAY_ORDERS ?> orders during its posted hours.
      <?= $offHours ?> order<?= $offHours === 1 ? '' : 's' ?> fell outside the posted hours
      (mostly stragglers just after closing) or on a day the pantry wasn't open, and
      <?= $offHours === 1 ? 'is' : 'are' ?> left out of the chart.
      <?php if ($closedWeekdays): ?>
        No operating <?= htmlspecialchars(implode(', ', $closedWeekdays)) ?> fell in this range.
      <?php endif; ?>
    </p>
  </div>

  <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
  <script>
  (function() {
    var labels    = <?= json_encode($chartLabels) ?>;
    var data      = <?= json_encode($chartData) ?>;
    var tips      = <?= json_encode($chartTips) ?>;
    var colors    = <?= json_encode($chartColors) ?>;
    var dayStarts = <?= json_encode($dayStarts) ?>;
    var breaks    = <?= json_encode($breaks) ?>;
    var dayShort  = <?= json_encode($dayShort) ?>;

    // Weekday names centered above each day's group of bars.
    var dayHeaders = {
      id: 'dayHeaders',
      afterDraw: function(chart) {
        var x = chart.scales.x, ctx = chart.ctx, top = chart.chartArea.top;
        ctx.save();
        ctx.font = 'bold 12px Arial, sans-serif';
        ctx.fillStyle = '#6B4C11';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'bottom';
        dayStarts.forEach(function(start, d) {
          var end = d + 1 < dayStarts.length ? dayStarts[d + 1] - 1 : data.length - 1;
          ctx.fillText(dayShort[d], (x.getPixelForValue(start) + x.getPixelForValue(end)) / 2, top - 6);
        });
        ctx.restore();
      }
    };

    new Chart(document.getElementById('trafficChart'), {
      type: 'bar',
      data: { labels: labels, datasets: [
        { label:'Client count', data: data, backgroundColor: colors, borderRadius:3,
          barPercentage:0.92, categoryPercentage:0.92 }
      ]},
      plugins: [dayHeaders],
      options: {
        responsive: true,
        maintainAspectRatio: false,
        layout: { padding: { top: 22 } },
        plugins: {
          legend: { display:false },
          tooltip: { callbacks: {
            title: function(c){ return tips[c[0].dataIndex].title; },
            label: function(c){
              var t = tips[c.dataIndex];
              return [c.raw + ' clients on average',
                      t.total + ' total over ' + t.days + ' ' + t.day + (t.days === 1 ? '' : 's')];
            }
          } }
        },
        scales: {
          x: { title:{ display:true, text:'Hours of operation', color:'#6B4C11', font:{size:11,weight:'bold'} },
               // A dark rule before each weekday's first bar separates the days;
               // a light one marks a closed break between two sessions.
               grid:{ color: function(ctx){ return dayStarts.indexOf(ctx.index) > 0 ? '#6B4C11'
                                                 : breaks.indexOf(ctx.index) >= 0 ? '#D4C9A8' : 'transparent'; },
                      lineWidth: function(ctx){ return dayStarts.indexOf(ctx.index) > 0 ? 1.5
                                                 : breaks.indexOf(ctx.index) >= 0 ? 1 : 0; } },
               ticks:{ font:{size:10}, autoSkip:false, maxRotation:90, minRotation:0 } },
          y: { beginAtZero:true, title:{ display:true, text:'Client count', color:'#6B4C11', font:{size:11,weight:'bold'} },
               grid:{ color:'#F0EBD8' }, ticks:{ font:{size:11} } }
        }
      }
    });
  })();
  </script>

  <?php else: ?>
    <div class="card"><div class="no-data">No client orders found in the selected date range.</div></div>
  <?php endif; ?>

</div>
<?php renderFoot(); ?>
