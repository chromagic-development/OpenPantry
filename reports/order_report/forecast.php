<?php
// Demand forecasting for the Order Report.
//
// Replaces the old ad-hoc S (seasonality) and G (growth) ratio multipliers
// with a single coherent statistical model fitted per generic_name:
//
//   a quasi-Poisson GLM with a log link, a linear time trend, and Fourier
//   seasonal terms on the annual cycle.
//
// log λ(t) = β0 + β_trend·t_years + Σ_k [ a_k·sin(2πk·doy/365.25)
//                                       + b_k·cos(2πk·doy/365.25) ]
//
// where λ(t) is the expected *daily* demand (count for packaged items, lbs
// for produce) on calendar day t. The model is fit on weekly buckets of the
// item's full scan history (zero-filled, so weeks with no demand count), with
// an offset of log(7) so exp(Xβ) reads directly as a per-day rate.
//
// From one fit we derive everything the report needs:
//   * Forecast over the lead time   = Σ_{d=1..LT} λ(today+d)
//   * Predictive variance over LT    = φ · Forecast        (φ = dispersion)
//   * Safety Stock                   = Z · √variance
//   * G (annual growth)              = exp(β_trend)         — interpretable factor
//   * S (seasonality over the window)= Forecast / (deseasonalized baseline)
//
// So trend, seasonality, and the uncertainty that drives safety stock all
// come from the same model instead of three disconnected ratios. Count data
// with many zero weeks is exactly what a (quasi-)Poisson GLM is for; the
// dispersion φ absorbs the over-dispersion typical of pantry demand and
// honestly inflates safety stock.
//
// Pure PHP, no extensions: a 6×6 (at most) weighted least-squares system is
// solved by Gaussian elimination inside an IRLS loop. The caller wraps the
// entry point in try/catch and falls back to the trailing-average method, so
// a thin- or pathological-history item never breaks the page.

// --- Tunables ------------------------------------------------------------
const OP_GLM_MIN_BUCKETS   = 10;     // weekly buckets required to attempt a fit
const OP_GLM_MIN_SPAN_DAYS = 60;     // history span required to attempt a fit
const OP_GLM_SEASON_1H_DAYS = 330;   // ≥ this span → 1 seasonal harmonic
const OP_GLM_SEASON_2H_DAYS = 540;   // ≥ this span → 2 seasonal harmonics
const OP_GLM_MAX_ITER      = 50;
const OP_GLM_RIDGE         = 1e-8;   // tiny diagonal load for numerical stability
const OP_GLM_G_MIN         = 0.33;   // clamp displayed/applied growth factor
const OP_GLM_G_MAX         = 3.0;
const OP_GLM_S_MIN         = 0.25;   // clamp displayed/applied seasonal factor
const OP_GLM_S_MAX         = 4.0;
const OP_GLM_LAMBDA_CAP    = 12.0;   // cap a day's rate at this × deseasonalized base

// Solve A·x = b for a small dense system by Gaussian elimination with partial
// pivoting. Returns null if the matrix is effectively singular.
function op_glm_solve(array $A, array $b): ?array {
    $n = count($A);
    // Augmented matrix [A | b].
    $M = [];
    for ($i = 0; $i < $n; $i++) {
        $M[$i] = array_merge($A[$i], [$b[$i]]);
    }
    for ($col = 0; $col < $n; $col++) {
        // Partial pivot: largest magnitude in this column at/below the diagonal.
        $piv = $col;
        $best = abs($M[$col][$col]);
        for ($r = $col + 1; $r < $n; $r++) {
            if (abs($M[$r][$col]) > $best) { $best = abs($M[$r][$col]); $piv = $r; }
        }
        if ($best < 1e-12) return null; // singular
        if ($piv !== $col) { $tmp = $M[$col]; $M[$col] = $M[$piv]; $M[$piv] = $tmp; }
        $pivval = $M[$col][$col];
        for ($r = 0; $r < $n; $r++) {
            if ($r === $col) continue;
            $f = $M[$r][$col] / $pivval;
            if ($f == 0.0) continue;
            for ($c = $col; $c <= $n; $c++) {
                $M[$r][$c] -= $f * $M[$col][$c];
            }
        }
    }
    $x = [];
    for ($i = 0; $i < $n; $i++) $x[$i] = $M[$i][$n] / $M[$i][$i];
    return $x;
}

// Design row for a sample: [intercept, trend(years), sin1, cos1, sin2, cos2, …].
function op_glm_design_row(float $tYears, int $doy, int $harmonics): array {
    $row = [1.0, $tYears];
    for ($k = 1; $k <= $harmonics; $k++) {
        $ang = 2.0 * M_PI * $k * $doy / 365.25;
        $row[] = sin($ang);
        $row[] = cos($ang);
    }
    return $row;
}

// Iteratively Reweighted Least Squares fit of a Poisson GLM (log link).
// $offset[i] = log(exposure days) so exp(Xβ) is a per-day rate.
// Returns ['beta'=>[], 'disp'=>float, 'ok'=>bool].
function op_glm_fit(array $X, array $y, array $offset): array {
    $n = count($X);
    $p = count($X[0]);
    $beta = array_fill(0, $p, 0.0);

    // Initialise the intercept at log(overall mean rate) for fast convergence.
    $totY = array_sum($y);
    $totExp = 0.0;
    foreach ($offset as $o) $totExp += exp($o);
    $beta[0] = log(max($totY, 0.5) / max($totExp, 1e-9));

    for ($it = 0; $it < OP_GLM_MAX_ITER; $it++) {
        // Accumulate XᵀWX and XᵀWz for the weighted least-squares step.
        $A = [];
        for ($a = 0; $a < $p; $a++) $A[$a] = array_fill(0, $p, 0.0);
        $rhs = array_fill(0, $p, 0.0);

        for ($i = 0; $i < $n; $i++) {
            $eta = $offset[$i];
            for ($j = 0; $j < $p; $j++) $eta += $X[$i][$j] * $beta[$j];
            if ($eta > 30) $eta = 30; elseif ($eta < -30) $eta = -30;
            $mu = exp($eta);
            $w  = $mu;                                   // Poisson IRLS weight
            $z  = ($eta - $offset[$i]) + ($y[$i] - $mu) / $mu; // working response (sans offset)
            for ($a = 0; $a < $p; $a++) {
                $xwa = $X[$i][$a] * $w;
                $rhs[$a] += $xwa * $z;
                for ($b = 0; $b < $p; $b++) {
                    $A[$a][$b] += $xwa * $X[$i][$b];
                }
            }
        }
        for ($d = 0; $d < $p; $d++) $A[$d][$d] += OP_GLM_RIDGE;

        $newBeta = op_glm_solve($A, $rhs);
        if ($newBeta === null) return ['beta' => $beta, 'disp' => 1.0, 'ok' => false];

        $delta = 0.0;
        for ($j = 0; $j < $p; $j++) $delta = max($delta, abs($newBeta[$j] - $beta[$j]));
        $beta = $newBeta;
        if ($delta < 1e-8) break;
    }

    // Quasi-Poisson dispersion from Pearson residuals: φ = Σ (y-μ)²/μ / (n-p).
    $chisq = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $eta = $offset[$i];
        for ($j = 0; $j < $p; $j++) $eta += $X[$i][$j] * $beta[$j];
        if ($eta > 30) $eta = 30; elseif ($eta < -30) $eta = -30;
        $mu = exp($eta);
        $chisq += ($y[$i] - $mu) * ($y[$i] - $mu) / max($mu, 1e-9);
    }
    $disp = $chisq / max($n - $p, 1);

    return ['beta' => $beta, 'disp' => max($disp, 1e-6), 'ok' => true];
}

// Fit the per-item demand GLM from its daily-demand history. This is the
// expensive, cacheable step: the returned coefficients depend only on the
// training data and the anchor date — not on lead time or Z.
//
//   $dailyMap : ['Y-m-d' => amount, …] over the training window (sparse OK;
//               missing days are treated as zero demand).
//   $today    : DateTimeImmutable anchor (midnight).
//
// Returns null when there isn't enough history to fit, otherwise
// ['beta'=>[], 'disp'=>float, 'harmonics'=>int, 'buckets'=>int].
function op_glm_fit_series(array $dailyMap, DateTimeImmutable $today): ?array {
    if (!$dailyMap) return null;

    // History span from the earliest scan day to today.
    $earliest = null;
    foreach ($dailyMap as $d => $_) {
        if ($earliest === null || $d < $earliest) $earliest = $d;
    }
    $earliestDt = DateTimeImmutable::createFromFormat('Y-m-d', $earliest)->setTime(0, 0);
    $spanDays = (int)$earliestDt->diff($today)->days;
    if ($spanDays < OP_GLM_MIN_SPAN_DAYS) return null;

    // Seasonal richness scales with how much history we have.
    $harmonics = 0;
    if ($spanDays >= OP_GLM_SEASON_2H_DAYS)      $harmonics = 2;
    elseif ($spanDays >= OP_GLM_SEASON_1H_DAYS)  $harmonics = 1;

    // Weekly buckets, zero-filled across the whole span. Week 0 starts at the
    // earliest scan day; we step in 7-day blocks and stop before the current,
    // still-incomplete week so the last point isn't biased low. Each bucket's
    // representative day (for trend/doy) is its start date.
    $X = []; $y = []; $offset = [];
    $logExposure = log(7.0);
    $wkStart = $earliestDt;
    $cutoff  = $today->modify('-7 days'); // last fully-elapsed week start
    while ($wkStart <= $cutoff) {
        $sum = 0.0;
        for ($i = 0; $i < 7; $i++) {
            $key = $wkStart->modify("+{$i} days")->format('Y-m-d');
            if (isset($dailyMap[$key])) $sum += (float)$dailyMap[$key];
        }
        $tYears = -((int)$wkStart->diff($today)->days) / 365.25; // past = negative
        $doy = (int)$wkStart->format('z') + 1;                    // 1..366
        $X[] = op_glm_design_row($tYears, $doy, $harmonics);
        $y[] = $sum;
        $offset[] = $logExposure;
        $wkStart = $wkStart->modify('+7 days');
    }

    if (count($X) < OP_GLM_MIN_BUCKETS) return null;
    if (array_sum($y) <= 0)            return null; // never any demand

    $fit = op_glm_fit($X, $y, $offset);
    if (!$fit['ok']) return null;

    return [
        'beta'      => $fit['beta'],
        'disp'      => $fit['disp'],
        'harmonics' => $harmonics,
        'buckets'   => count($X),
    ];
}

// Project a fitted model into a lead-time forecast. Cheap — pure arithmetic
// over $leadTime days — so it's recomputed live even when the fit is cached,
// letting Lead Time / Z change without refitting.
//
//   $fit : output of op_glm_fit_series (beta, disp, harmonics, buckets).
//
// Returns an assoc array with: method, forecast, variance, avg_daily, sigma,
// safety, S, G, dispersion, harmonics, buckets.
function op_project_forecast(array $fit, DateTimeImmutable $today, int $leadTime, float $z): array {
    $beta      = $fit['beta'];
    $disp      = $fit['disp'];
    $harmonics = $fit['harmonics'];

    // Deseasonalized daily base at "today" (intercept + trend only), used as
    // the reference level for the seasonal factor and to cap extrapolation.
    $base0 = exp($beta[0]); // trend term is t_years=0 at today
    $lamCap = OP_GLM_LAMBDA_CAP * max($base0, 1e-9);

    // Sum the daily rate over the lead-time horizon for the forecast, and the
    // deseasonalized rate over the same horizon for the seasonal-factor base.
    $forecast = 0.0;
    $baseSum  = 0.0;
    for ($d = 1; $d <= $leadTime; $d++) {
        $fd = $today->modify("+{$d} days");
        $tYears = $d / 365.25;
        $doy = (int)$fd->format('z') + 1;
        $row = op_glm_design_row($tYears, $doy, $harmonics);
        $eta = 0.0;
        for ($j = 0; $j < count($beta); $j++) $eta += $row[$j] * $beta[$j];
        if ($eta > 30) $eta = 30; elseif ($eta < -30) $eta = -30;
        $lam = min(exp($eta), $lamCap);
        $forecast += $lam;
        $baseSum  += min(exp($beta[0] + $beta[1] * $tYears), $lamCap);
    }

    // Interpretable factors. G is the model's annual growth multiplier; S is
    // how the forecast window compares to its deseasonalized baseline. Both
    // clamped so a wild fit can't produce an absurd par level.
    $G = exp($beta[1]);
    $G = max(OP_GLM_G_MIN, min(OP_GLM_G_MAX, $G));
    $S = $baseSum > 0 ? $forecast / $baseSum : 1.0;
    $S = max(OP_GLM_S_MIN, min(OP_GLM_S_MAX, $S));

    // Predictive variance of a sum of (over-dispersed) Poisson days ≈ φ·mean.
    $variance = $disp * $forecast;
    $safety   = $z * sqrt(max($variance, 0.0));
    $avgDaily = $leadTime > 0 ? $forecast / $leadTime : $forecast;
    $sigma    = sqrt($disp * max($avgDaily, 0.0)); // per-day inflated stdev

    return [
        'method'     => 'glm',
        'forecast'   => $forecast,
        'variance'   => $variance,
        'avg_daily'  => $avgDaily,
        'sigma'      => $sigma,
        'safety'     => $safety,
        'S'          => $S,
        'G'          => $G,
        'dispersion' => $disp,
        'harmonics'  => $harmonics,
        'buckets'    => $fit['buckets'] ?? 0,
    ];
}

// Uncached convenience entry point: fit then project. Used by the CLI
// self-test and any caller that doesn't want the DB cache.
function op_forecast_item(array $dailyMap, DateTimeImmutable $today, int $leadTime, float $z): ?array {
    $fit = op_glm_fit_series($dailyMap, $today);
    if ($fit === null) return null;
    return op_project_forecast($fit, $today, $leadTime, $z);
}

// Cached entry point used by the report. Memoizes the expensive fit in the
// forecast_cache table, keyed by item + anchor date + a hash of the training
// series (and the event-filter flag, for clarity). Because the key includes
// the series hash and the date, the fit is reused for repeat page loads and
// Lead-Time / Z changes on the same day, and invalidates automatically when
// scans change or the day rolls over. Only the cheap projection runs on a hit.
//
// Any database error degrades silently to the uncached path, so caching can
// never break the report.
function op_forecast_item_cached(
    PDO $db, string $name, array $dailyMap, DateTimeImmutable $today,
    int $leadTime, float $z, bool $ignoreEvents
): ?array {
    // Stable hash of the training series (sort so PDO row order can't matter).
    $series = $dailyMap;
    ksort($series);
    $key = sha1($name . '|' . ($ignoreEvents ? 'E1' : 'E0') . '|'
              . $today->format('Y-m-d') . '|' . md5(json_encode($series)));

    try {
        $sel = $db->prepare('SELECT payload FROM forecast_cache WHERE cache_key = ?');
        $sel->execute([$key]);
        $payload = $sel->fetchColumn();
        if ($payload !== false) {
            $fit = json_decode((string)$payload, true);
            if (is_array($fit) && isset($fit['beta'][0])) {
                return op_project_forecast($fit, $today, $leadTime, $z);
            }
            // Malformed row — fall through and refit.
        }

        // Cache miss: fit, store, project. Insufficient-history items return
        // null and aren't cached (their early-out is already cheap).
        $fit = op_glm_fit_series($dailyMap, $today);
        if ($fit === null) return null;

        $ins = $db->prepare(
            'INSERT INTO forecast_cache (cache_key, payload, created_at) VALUES (?, ?, ?)
             ON CONFLICT(cache_key) DO UPDATE SET payload = excluded.payload,
                                                  created_at = excluded.created_at'
        );
        $ins->execute([$key, json_encode($fit), now()]);

        // Opportunistically prune fits older than a week (anchor dates roll
        // over daily, so stale keys never get re-read). Gated so it runs on a
        // small fraction of misses rather than every write.
        if (mt_rand(1, 10) === 1) {
            $cut = $today->modify('-7 days')->format('Y-m-d 00:00:00');
            $db->prepare('DELETE FROM forecast_cache WHERE created_at < ?')->execute([$cut]);
        }

        return op_project_forecast($fit, $today, $leadTime, $z);
    } catch (\Throwable $e) {
        // DB unavailable / locked / schema missing — just compute uncached.
        return op_forecast_item($dailyMap, $today, $leadTime, $z);
    }
}

// --- CLI self-test -------------------------------------------------------
// Run `php forecast.php --selftest` to sanity-check the numerics against a
// synthetic series with known trend/seasonality. No effect when included by
// a web page.
if (PHP_SAPI === 'cli' && in_array('--selftest', $argv ?? [], true)) {
    mt_srand(42);
    $today = new DateTimeImmutable('today');
    $b0 = log(3.0); $btrend = 0.18; $a1 = 0.6; $c1 = -0.3; // ~3/day, +20%/yr
    $daily = [];
    for ($d = 0; $d < 730; $d++) {                 // two years of daily history
        $day = $today->modify('-' . (730 - $d) . ' days');
        $tYears = -((730 - $d) / 365.25);
        $doy = (int)$day->format('z') + 1;
        $lam = exp($b0 + $btrend * $tYears
                   + $a1 * sin(2 * M_PI * $doy / 365.25)
                   + $c1 * cos(2 * M_PI * $doy / 365.25));
        // crude Poisson draw
        $L = exp(-$lam); $k = 0; $pr = 1.0;
        do { $k++; $pr *= mt_rand() / mt_getrandmax(); } while ($pr > $L);
        $cnt = $k - 1;
        if ($cnt > 0) $daily[$day->format('Y-m-d')] = $cnt;
    }
    $r = op_forecast_item($daily, $today, 14, 1.65);
    echo "method     : {$r['method']}\n";
    echo "harmonics  : {$r['harmonics']} buckets {$r['buckets']}\n";
    printf("G (true ~%.3f) : %.3f\n", exp($btrend), $r['G']);
    printf("S          : %.3f\n", $r['S']);
    printf("avg_daily  : %.3f\n", $r['avg_daily']);
    printf("14d forecast: %.2f  safety %.2f  disp %.3f\n",
           $r['forecast'], $r['safety'], $r['dispersion']);
}
