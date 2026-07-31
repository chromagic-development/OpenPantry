<?php
// One-off diagnostic: dump the fitted quasi-Poisson dispersion (φ) for every
// generic_name the Order Report would forecast.
//
//   /usr/local/bin/php /home/you/public_html/cli_dispersion.php
//
// Why: safety stock is Z·√(φ·Forecast) (see forecast.php), so φ scales the
// buffer directly. φ < 1 is normal and correct — it means the item's demand is
// genuinely smoother than Poisson, which is the common case for produce weighed
// in lbs. What is NOT healthy is φ collapsing toward zero: that drives safety
// stock to ~0 and leaves the item with no buffer at all. This script exists to
// answer "does any item actually sit near that degenerate corner", which cannot
// be answered from synthetic data.
//
// Read the output as: anything flagged DEGENERATE deserves a look. A long tail
// of items at φ = 0.05–0.5 is expected and needs no action.
//
// Auth mirrors cron_reorder_alerts.php: CLI runs freely, HTTP needs an admin.

require_once __DIR__ . '/common.php';

if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/auth.php';
    requireLogin();
    header('Content-Type: text/plain; charset=utf-8');
}

require_once __DIR__ . '/reports/order_report/report_lib.php';

const OP_PHI_DEGENERATE = 0.01;   // below this, the buffer is effectively gone

$db    = getDB();
$today = new DateTimeImmutable('today');

// Same knobs the report uses, so the safety numbers below match the page.
$leadTime = max(1, (int)setting('default_lead_time', '14'));
$z        = max(0.0, min(4.0, (float)setting('safety_z', '1.65')));

$unscanned = op_unscanned_days($db);

// Per-item daily demand history — identical to op_report_rows() in
// report_lib.php, including the default "ignore EVENT orders" filter, so the
// series fitted here is the series the report fits.
$histStart = $today->modify('-' . OP_HIST_DAYS . ' days')->format('Y-m-d 00:00:00');
$stmt = $db->prepare(
    "SELECT s.generic_name, s.kind,
            DATE(s.scanned_at) day,
            SUM(s.quantity) qty, SUM(COALESCE(s.weight_lbs,0)) wt
     FROM scans s
     LEFT JOIN orders o ON o.id = s.order_id
     WHERE s.scanned_at >= ?
       AND (o.note IS NULL OR o.note NOT LIKE 'EVENT %')
     GROUP BY s.generic_name, day"
);
$stmt->execute([$histStart]);

$hist = [];
$kinds = [];
foreach ($stmt as $r) {
    $kinds[$r['generic_name']] = $r['kind'];
    $amt = ($r['kind'] === 'produce') ? (float)$r['wt'] : (float)$r['qty'];
    $hist[$r['generic_name']][$r['day']] = $amt;
}

// Mirror of op_glm_fit_series()'s early-outs, so a null fit can be attributed
// to a specific gate instead of just "no fit". Pure diagnosis — it computes the
// same span/bucket/total numbers the real fit does and reports which one bit.
function op_diag_reason(array $daily, DateTimeImmutable $today, array $unscanned) {
    if (!$daily) return ['reason' => 'empty series', 'span' => 0, 'buckets' => 0, 'total' => 0.0];

    $earliest = null;
    foreach ($daily as $d => $_) {
        if ($earliest === null || $d < $earliest) $earliest = $d;
    }
    $earliestDt = DateTimeImmutable::createFromFormat('Y-m-d', (string)$earliest);
    if ($earliestDt === false) {
        return ['reason' => "unparseable day key '" . $earliest . "'",
                'span' => 0, 'buckets' => 0, 'total' => 0.0];
    }
    $earliestDt = $earliestDt->setTime(0, 0);
    $span = (int)$earliestDt->diff($today)->days;

    $buckets = 0; $total = 0.0;
    $wk = $earliestDt;
    $cutoff = $today->modify('-7 days');
    while ($wk <= $cutoff) {
        $sum = 0.0; $observed = 0;
        for ($i = 0; $i < 7; $i++) {
            $key = $wk->modify("+{$i} days")->format('Y-m-d');
            if (isset($unscanned[$key])) continue;
            $observed++;
            if (isset($daily[$key])) $sum += (float)$daily[$key];
        }
        if ($observed > 0) { $buckets++; $total += $sum; }
        $wk = $wk->modify('+7 days');
    }

    $reason = 'fit should have succeeded';
    if ($span < OP_GLM_MIN_SPAN_DAYS)        $reason = 'span < ' . OP_GLM_MIN_SPAN_DAYS . ' days';
    elseif ($buckets < OP_GLM_MIN_BUCKETS)   $reason = 'buckets < ' . OP_GLM_MIN_BUCKETS;
    elseif ($total <= 0)                     $reason = 'total demand <= 0';
    return ['reason' => $reason, 'span' => $span, 'buckets' => $buckets, 'total' => $total];
}

$fitted = [];
$fellBack = [];
$reasons  = [];
$examples = [];
foreach ($hist as $name => $days) {
    $err = null;
    try {
        $fit = op_glm_fit_series($days, $today, $unscanned);
    } catch (\Throwable $e) {
        $fit = null;
        $err = get_class($e) . ': ' . $e->getMessage();
    }
    if ($fit === null) {
        // Not enough history — the report uses the trailing-average method for
        // these, so they have no φ at all. Record why.
        $fellBack[] = $name;
        $d = op_diag_reason($days, $today, $unscanned);
        $r = $err !== null ? ('threw ' . $err) : $d['reason'];
        if (!isset($reasons[$r])) { $reasons[$r] = 0; $examples[$r] = []; }
        $reasons[$r]++;
        if (count($examples[$r]) < 3) {
            $examples[$r][] = sprintf('%s (days=%d, span=%d, buckets=%d, total=%.1f)',
                $name, count($days), $d['span'], $d['buckets'], $d['total']);
        }
        continue;
    }
    $proj = op_project_forecast($fit, $today, $leadTime, $z);
    $fitted[] = [
        'name'      => $name,
        'kind'      => $kinds[$name] ?? '',
        'phi'       => $fit['disp'],
        'buckets'   => $fit['buckets'],
        'harmonics' => $fit['harmonics'],
        'forecast'  => $proj['forecast'],
        'safety'    => $proj['safety'],
    ];
}

// Classic closure, not an arrow function: the host runs PHP < 7.4 and `fn()`
// is a parse error there (the rest of the codebase avoids it too).
usort($fitted, function ($a, $b) { return $a['phi'] < $b['phi'] ? -1 : ($a['phi'] > $b['phi'] ? 1 : 0); });

printf("OpenPantry dispersion probe — %s\n", $today->format('Y-m-d'));
printf("Lead time %d days, Z = %.2f\n", $leadTime, $z);
printf("%d items fitted, %d on trailing-average fallback\n\n",
       count($fitted), count($fellBack));

printf("%-34s %-9s %8s %8s %10s %10s %8s  %s\n",
       'generic_name', 'kind', 'phi', 'sqrt', 'forecast', 'safety', 'SS/fc', '');
echo str_repeat('-', 108), "\n";

$degenerate = 0;
foreach ($fitted as $f) {
    $flag = '';
    if ($f['phi'] < OP_PHI_DEGENERATE) { $flag = 'DEGENERATE'; $degenerate++; }
    $pct = $f['forecast'] > 0 ? ($f['safety'] / $f['forecast'] * 100) : 0.0;
    printf("%-34s %-9s %8.4f %8.3f %10.2f %10.2f %7.1f%%  %s\n",
        substr($f['name'], 0, 34), $f['kind'], $f['phi'], sqrt($f['phi']),
        $f['forecast'], $f['safety'], $pct, $flag);
}

if ($fitted) {
    $phis = array_column($fitted, 'phi');
    $med  = $phis[intdiv(count($phis), 2)];
    printf("\nφ  min %.4f | median %.4f | max %.4f\n", $phis[0], $med, end($phis));
    printf("%d item(s) below φ = %.2f\n", $degenerate, OP_PHI_DEGENERATE);
    echo $degenerate === 0
        ? "No degenerate buffers — the 1e-6 floor in forecast.php is not being leaned on.\n"
        : "Flagged items have effectively no safety stock; check whether their history is near-deterministic.\n";
}

if ($fellBack) {
    printf("\n%d item(s) on the trailing-average fallback. Why:\n\n", count($fellBack));
    arsort($reasons);
    foreach ($reasons as $r => $n) {
        printf("  %5d  %s\n", $n, $r);
        foreach ($examples[$r] as $ex) printf("           e.g. %s\n", $ex);
    }
}

// Global inputs worth ruling out when *everything* falls back: a saturated
// unscanned-day log zeroes every bucket's exposure, and a day key SQLite's
// DATE() couldn't parse makes every series unfittable.
echo "\n--- global inputs ---\n";
printf("unscanned_days rows: %d\n", count($unscanned));
$sampleName = key($hist);
if ($sampleName !== null) {
    $sampleDays = array_keys($hist[$sampleName]);
    sort($sampleDays);
    printf("sample item '%s': %d day keys, first=%s last=%s\n",
        $sampleName, count($sampleDays),
        $sampleDays[0] ?? 'n/a', end($sampleDays) ?: 'n/a');
}
printf("scans table: %d rows total, min(scanned_at)=%s max(scanned_at)=%s\n",
    (int)$db->query("SELECT COUNT(*) FROM scans")->fetchColumn(),
    (string)$db->query("SELECT MIN(scanned_at) FROM scans")->fetchColumn(),
    (string)$db->query("SELECT MAX(scanned_at) FROM scans")->fetchColumn());
printf("history window starts: %s (OP_HIST_DAYS=%d)\n", $histStart, OP_HIST_DAYS);
