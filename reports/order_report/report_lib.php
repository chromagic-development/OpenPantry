<?php
// Shared Order-Report computation.
//
// The per-item demand model, par levels, order requests, and the reorder-alert
// evaluation all live here so two callers stay in lockstep:
//   * reports/order_report/order_report.php — renders the page.
//   * cron_reorder_alerts.php               — emails triggered reminders.
//
// Keeping one implementation means an alert that fires in the emailed digest is
// exactly the alert the report shows on the page. See order_report.php's header
// for the math (Par Level = Forecast(LT) + Safety Stock, etc.).

require_once __DIR__ . '/forecast.php';

// Training-window cap, ~3 years: two full annual cycles for seasonality while
// bounding per-page compute. (Was the HIST_DAYS const inside order_report.php.)
const OP_HIST_DAYS = 1100;

// Minimum hours between repeat reorder-reminder emails for the same alert.
// The cron script stamps alerts.last_triggered when it sends, then skips any
// alert re-triggered within this window so a frequent cron doesn't spam.
const OP_ALERT_EMAIL_MIN_HOURS = 20;

// Days the pantry operated but nothing was scanned, as a ['Y-m-d' => true] set
// (see the unscanned_days table in schema.sql). The demand model treats these
// as unobserved rather than as zero demand, so a missed scanning day no longer
// biases par levels and reorder alerts low.
//
// Cached per request — both the report page and the cron mailer call this once
// per run. Defensive for the same reason op_ensure_alert_email_column() is: on
// a partially-deployed install schema.sql may predate this file and the table
// won't exist yet. Missing table = no unscanned days = the original behaviour,
// which is the right way to fail here.
function op_unscanned_days(PDO $db): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    try {
        foreach ($db->query("SELECT day FROM unscanned_days") as $r) {
            $cache[(string)$r['day']] = true;
        }
    } catch (\Throwable $e) {
        // Table missing / DB unavailable — every day counts as observed.
    }
    return $cache;
}

// Which days of the week the pantry actually distributes on, as a [0..6 => true]
// set (0 = Sunday, matching date('w')). Derived from the scan history itself
// rather than configured: a day-of-week that has never once carried a scan is a
// day the pantry is closed.
//
// Why this matters for the trailing-average method: its sigma is the stdev of
// *daily* demand, and a closed Saturday contributes a zero that looks exactly
// like a quiet Saturday. Those structural zeros are schedule, not demand
// volatility, and they inflate sigma — and therefore safety stock — badly. On a
// Mon-Fri schedule the inflation runs ~1.4x on thin items and over 5x on steady
// high-volume produce, which is where the padding costs the most.
//
// Deliberately evidence-gated: with less than two weeks of history a day-of-week
// simply may not have come around yet, and calling it closed would be guessing.
// In that case (and for a genuinely 7-day pantry) every day is returned open,
// which reproduces the original behaviour exactly.
//
//   $hist : ['generic_name' => ['Y-m-d' => amount, …], …] as loaded below.
function op_open_dow(array $hist): array {
    $allOpen = [];
    for ($w = 0; $w < 7; $w++) $allOpen[$w] = true;

    // Union of every day any item was scanned on.
    $days = [];
    foreach ($hist as $byDay) {
        foreach ($byDay as $d => $_) $days[$d] = true;
    }
    if (count($days) < 10) return $allOpen;

    $keys = array_keys($days);
    sort($keys);
    $first = DateTimeImmutable::createFromFormat('Y-m-d', (string)$keys[0]);
    $last  = DateTimeImmutable::createFromFormat('Y-m-d', (string)end($keys));
    // Under 14 days of span, some weekday has had at most one chance to appear.
    if ($first === false || $last === false || (int)$first->diff($last)->days < 14) {
        return $allOpen;
    }

    $open = [];
    foreach ($keys as $d) {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', (string)$d);
        if ($dt !== false) $open[(int)$dt->format('w')] = true;
    }
    // A single open day-of-week is more likely a data artefact than a schedule.
    return count($open) >= 2 ? $open : $allOpen;
}

// Build the sorted, filtered Order-Report rows.
//
// $opts keys (all optional, sensible defaults from Settings):
//   lead_time, velocity_window, z, ignore_stock, ignore_events,
//   produce_only, purchased_only
//
// Each returned row has the same shape order_report.php's table expects, incl.
// 'days_left' (projected days of stock) used for alert evaluation.
function op_report_rows(PDO $db, array $opts = []): array {
    $leadTime      = max(1, (int)($opts['lead_time']       ?? (int)setting('default_lead_time', '14')));
    $velWindow     = max(7, (int)($opts['velocity_window']  ?? (int)setting('velocity_window', '30')));
    // Clamped like the two above. A stored non-numeric safety_z casts to 0.0 and
    // silently removes all safety stock; a negative one would put par levels
    // *below* the bare forecast, under-ordering exactly where the buffer matters.
    // 4.0 (~99.997% one-sided) is already well past any useful service level.
    $z             = max(0.0, min(4.0, (float)($opts['z']    ?? (float)setting('safety_z', '1.65'))));
    $ignoreStock   = (bool)($opts['ignore_stock']           ?? false);
    $ignoreEvents  = (bool)($opts['ignore_events']          ?? true);
    $produceOnly   = (bool)($opts['produce_only']           ?? false);
    $purchasedOnly = (bool)($opts['purchased_only']         ?? false);

    $today = new DateTimeImmutable('today');

    // Days with no scanning coverage. Excluded from the demand estimate's
    // exposure below (and inside the GLM fit) instead of being read as days of
    // genuine zero demand.
    $unscanned = op_unscanned_days($db);

    // Per-item daily demand history (full window feeds the GLM; its last
    // velocity_window days feed the trailing-average fallback).
    $histStart   = $today->modify('-' . OP_HIST_DAYS . ' days')->format('Y-m-d 00:00:00');
    $eventFilter = $ignoreEvents
        ? " AND (o.note IS NULL OR o.note NOT LIKE 'EVENT %')"
        : "";
    $stmt = $db->prepare(
        "SELECT s.generic_name, s.kind,
                DATE(s.scanned_at) day,
                SUM(s.quantity) qty, SUM(COALESCE(s.weight_lbs,0)) wt
         FROM scans s
         LEFT JOIN orders o ON o.id = s.order_id
         WHERE s.scanned_at >= ?{$eventFilter}
         GROUP BY s.generic_name, day"
    );
    $stmt->execute([$histStart]);
    $hist  = [];
    $kinds = [];
    foreach ($stmt as $r) {
        $kinds[$r['generic_name']] = $r['kind'];
        $amt = ($r['kind'] === 'produce') ? (float)$r['wt'] : (float)$r['qty'];
        $hist[$r['generic_name']][$r['day']] = $amt;
    }

    $inv = [];
    foreach ($db->query("SELECT generic_name, count, unit, count_per_case, restocked_purchased FROM inventory") as $r) {
        $inv[$r['generic_name']] = $r;
    }

    $produceNamesLc = [];
    foreach ($db->query("SELECT generic_name FROM produce_lookup") as $r) {
        $produceNamesLc[strtolower($r['generic_name'])] = true;
    }

    // Distribution schedule, inferred once for the whole report (see
    // op_open_dow). Open days in the lead-time horizon are what demand actually
    // varies over; closed ones contribute neither demand nor uncertainty.
    $openDow = op_open_dow($hist);
    $openInLT = 0;
    for ($i = 1; $i <= $leadTime; $i++) {
        if (isset($openDow[(int)$today->modify("+{$i} days")->format('w')])) $openInLT++;
    }

    $rows = [];
    foreach ($hist as $name => $days) {
        // Trailing window, 0-filled. An unscanned day is left out of the sample
        // entirely so the denominator shrinks with it — a 6-of-7 average rather
        // than a 7-day one diluted by a zero we never actually observed.
        //
        // Two samples are accumulated from the same window. $vals spans every
        // observed calendar day and yields ADV, a per-calendar-day rate: that is
        // what the lead-time forecast and days_left are denominated in, so it
        // must keep counting closed days as the genuine zeros they are.
        // $openVals keeps only days the pantry distributes on, and drives the
        // spread — a closed Sunday is schedule, not demand noise, and letting it
        // widen sigma is what inflates safety stock.
        $vals = [];
        $openVals = [];
        for ($i = 0; $i < $velWindow; $i++) {
            $day = $today->modify("-{$i} days");
            $d = $day->format('Y-m-d');
            if (isset($unscanned[$d])) continue;
            $v = $days[$d] ?? 0.0;
            $vals[] = $v;
            if (isset($openDow[(int)$day->format('w')])) $openVals[] = $v;
        }
        $n = count($vals);
        if ($n > 0) {
            $adv = array_sum($vals) / $n;
        } else {
            // Every day in the window was unscanned — no demand signal at all,
            // so don't invent one (and don't divide by zero).
            $adv = 0.0;
        }

        // Sigma over open days only, then re-expressed as the per-calendar-day
        // figure that reproduces the same buffer under the report's documented
        // Safety = Z·σ·√LT identity, so the ADV and σ columns stay in the same
        // units and order_report.php's header maths still reads true:
        //     Z·σ_open·√openInLT  ==  Z·(σ_open·√(openInLT/LT))·√LT
        $nOpen = count($openVals);
        if ($nOpen > 0 && $openInLT > 0) {
            $advOpen = array_sum($openVals) / $nOpen;
            $varOpen = 0.0;
            foreach ($openVals as $v) $varOpen += ($v - $advOpen) * ($v - $advOpen);
            $sigmaOpen = sqrt($varOpen / $nOpen);
            $sigma = $sigmaOpen * sqrt($openInLT / $leadTime);
        } else {
            $sigma = 0.0;
        }

        $fc = null;
        try {
            $fc = op_forecast_item_cached($db, $name, $days, $today, $leadTime, $z,
                                          $ignoreEvents, $unscanned);
        } catch (\Throwable $e) {
            $fc = null;
        }
        if ($fc !== null) {
            $method = 'glm';
            $adv    = $fc['avg_daily'];
            $sigma  = $fc['sigma'];
            $safety = $fc['safety'];
            $S      = $fc['S'];
            $G      = $fc['G'];
            $par    = $fc['forecast'] + $fc['safety'];
        } else {
            $method = 'recent';
            $S = 1.0;
            $G = 1.0;
            $safety = $z * $sigma * sqrt($leadTime);
            $par    = ($adv * $leadTime) + $safety;
        }
        $stock = (float)($inv[$name]['count'] ?? 0);
        $effStock = $ignoreStock ? 0.0 : $stock;
        $orderReq = max(0.0, $par - $effStock);

        $cpc   = (float)($inv[$name]['count_per_case'] ?? 0);
        $cases = ($cpc > 0 && $orderReq > 0) ? (int)ceil($orderReq / $cpc) : 0;

        $catKind = isset($produceNamesLc[strtolower($name)]) ? 'produce' : $kinds[$name];

        $rows[] = [
            'name'    => $name,
            'kind'    => $catKind,
            'unit'    => $inv[$name]['unit'] ?? ($kinds[$name] === 'produce' ? 'lb' : 'each'),
            'adv'     => $adv,
            'sigma'   => $sigma,
            'safety'  => $safety,
            'S'       => $S, 'G' => $G,
            'method'  => $method,
            'par'     => $par,
            'stock'   => $stock,
            'order'   => $orderReq,
            'cpc'     => $cpc,
            'cases'   => $cases,
            'purchased' => (float)($inv[$name]['restocked_purchased'] ?? 0),
            'days_left' => $adv > 0 ? ($stock / $adv) : INF,
        ];
    }

    usort($rows, function($a, $b) {
        return ($b['order'] <=> $a['order']) ?: strcasecmp($a['name'], $b['name']);
    });

    if ($produceOnly) {
        $rows = array_values(array_filter($rows, function ($r) {
            return $r['kind'] === 'produce';
        }));
    }
    if ($purchasedOnly) {
        $rows = array_values(array_filter($rows, function ($r) {
            return $r['purchased'] > 0;
        }));
    }
    return $rows;
}

// Ensure alerts.email_enabled exists, adding it if a stale db.php skipped the
// migration. Cached per request so the PRAGMA check runs at most once.
function op_ensure_alert_email_column(PDO $db): void {
    static $ensured = false;
    if ($ensured) return;
    $ensured = true;
    try {
        foreach ($db->query("PRAGMA table_info(alerts)") as $c) {
            if (($c['name'] ?? '') === 'email_enabled') return; // already present
        }
        $db->exec("ALTER TABLE alerts ADD COLUMN email_enabled INTEGER NOT NULL DEFAULT 0");
    } catch (\Throwable $e) {
        // Concurrent add, or a read-only DB — leave it; the SELECT will surface
        // any real problem. Nothing else we can safely do here.
    }
}

// Evaluate the reorder alerts against a set of report rows.
//
// Returns one entry per triggered alert (projected days-of-stock below the
// alert's lead time):
//   ['id', 'name', 'unit', 'days_left', 'order', 'lead_time_days',
//    'email_enabled', 'days_text', 'order_text', 'text']
// 'text' is a plain-text one-liner; callers render HTML from the parts.
//
// $emailEnabledOnly limits the result to alerts with the Email box ticked
// (used by the cron mailer).
function op_report_alerts(PDO $db, array $rows, bool $emailEnabledOnly = false): array {
    // Safety net: the email_enabled column is normally added by db.php's
    // migrateAddAlertEmailEnabled(). If an older db.php is still deployed, that
    // migration won't have run and the query below would fatal with
    // "no such column: email_enabled". Add it on demand so the report keeps
    // working regardless. Idempotent and effectively free after the first call.
    op_ensure_alert_email_column($db);

    // Index rows by name for an O(1) lookup per alert.
    $byName = [];
    foreach ($rows as $r) $byName[$r['name']] = $r;

    $sql = "SELECT id, generic_name, lead_time_days, email_enabled, last_triggered
            FROM alerts WHERE enabled=1";
    if ($emailEnabledOnly) $sql .= " AND email_enabled=1";

    $out = [];
    foreach ($db->query($sql) as $a) {
        $r = $byName[$a['generic_name']] ?? null;
        if ($r === null || !($r['days_left'] < $a['lead_time_days'])) continue;

        // 'each' items round up (whole units, fully cover demand); days-left is
        // always a whole number per operator request.
        $orderText = ($r['unit'] === 'each')
            ? (string)(int)ceil($r['order'])
            : number_format($r['order'], 1);
        $daysText = number_format($r['days_left'], 0);

        $out[] = [
            'id'             => (int)$a['id'],
            'name'           => $r['name'],
            'unit'           => $r['unit'],
            'days_left'      => $r['days_left'],
            'order'          => $r['order'],
            'lead_time_days' => (int)$a['lead_time_days'],
            'email_enabled'  => (int)$a['email_enabled'],
            'last_triggered' => $a['last_triggered'],
            'days_text'      => $daysText,
            'order_text'     => $orderText,
            'text'           => $r['name'] . ': only ' . $daysText
                              . ' days of stock — order at least ' . $orderText . ' ' . $r['unit'],
        ];
    }
    return $out;
}
