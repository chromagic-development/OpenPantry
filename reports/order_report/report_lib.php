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

// Convert a quantity between the only two units the app knows, using the item's
// average weight per piece (inventory.lb_per_each).
//
// This is what lets an item be stocked in one unit and ordered in another: loose
// produce is weighed on the scale in 'lb', but the vendor sells avocados by the
// 48-count case, so the order request has to cross over to 'each' on the way out
// and back to 'lb' when the delivery is booked in. One factor covers both
// directions, and the inverse case too (a counted item sold as a 40 lb case).
//
// Returns null when the units differ and the factor isn't set — the conversion
// is genuinely unknown, and callers must fall back to the stock unit rather than
// invent a number (or divide by zero).
function op_convert_qty(float $qty, string $from, string $to, float $lbPerEach): ?float {
    if ($from === $to)   return $qty;
    if ($lbPerEach <= 0) return null;
    if ($from === 'each' && $to === 'lb')   return $qty * $lbPerEach;
    if ($from === 'lb'   && $to === 'each') return $qty / $lbPerEach;
    return null;  // unit outside {each, lb} — nothing sensible to do
}

// Vendor-facing figures for one item: the crossover from the unit the pantry
// stocks it in to the unit the vendor quotes, the whole cases that covers, what
// those cases put back on the shelf, and the floor space both take up.
//
// Split out of op_report_rows() because two row builders need it — items with a
// demand history, and (when the report is asked for them) inventory items with
// none. The conversions are fiddly enough that a second copy would drift, and a
// drift here means an order email that disagrees with the inventory it books in.
//
//   $invRow       : the item's inventory row, or null if it has none.
//   $unitFallback : unit to assume when inventory doesn't name one.
//   $orderReq     : the request, in the pantry's own unit.
//   $effStock     : on-hand count the caller is working from (0 under
//                   "Ignore In Stock"), also in the pantry's own unit.
function op_order_figures(?array $invRow, string $unitFallback, float $orderReq, float $effStock): array {
    $unit      = (string)($invRow['unit'] ?? $unitFallback);
    $lbPerEach = (float)($invRow['lb_per_each'] ?? 0);
    $orderUnit = (string)($invRow['order_unit'] ?? '');
    // A half-configured item (order unit set, average weight missing or a junk
    // unit stored) falls back to the stock unit — the original behaviour —
    // rather than producing a case count nobody can trust. inventory.php warns
    // on save when that happens.
    $orderQty  = ($orderUnit === 'each' || $orderUnit === 'lb')
               ? op_convert_qty($orderReq, $unit, $orderUnit, $lbPerEach)
               : null;
    if ($orderQty === null) {
        $orderUnit = $unit;
        $orderQty  = $orderReq;
    }

    $cpc = (float)($invRow['count_per_case'] ?? 0);
    // count_per_case is in the order unit, so the ceil to whole cases lands on
    // vendor units — 5.25 cases of avocados becomes 6, never 5.
    $cases = ($cpc > 0 && $orderQty > 0) ? (int)ceil($orderQty / $cpc) : 0;
    // What those cases actually add to the shelf, back in the stock unit.
    // Restock Now posts this, so a 6 × 48-count case order books in as 144 lb
    // rather than 288 of anything.
    $restockQty = $cpc > 0
        ? (op_convert_qty($cases * $cpc, $orderUnit, $unit, $lbPerEach) ?? ($cases * $cpc))
        : $orderReq;

    // Floor space, in cubic feet (Inventory → Cu Ft/Case).
    // Both figures need a case size to divide by as well as the factor itself,
    // so a half-configured item contributes 0 and is counted as unconfigured by
    // the report instead of being read as taking no room.
    $cratesPer   = (float)($invRow['crates_per_case'] ?? 0);
    $hasCrates   = ($cratesPer > 0 && $cpc > 0);
    $orderCrates = $hasCrates ? $cases * $cratesPer : 0.0;
    // What the pantry already holds, in the same cubic feet — shelf plus
    // anything in transit, since a delivery is booked in with Restock Now when
    // it is ordered rather than when it lands. Stock crosses into the order unit
    // exactly as the request does above, then divides by the case size to reach
    // a case-equivalent. The caller passes $effStock rather than the raw count
    // so "Ignore In Stock" stays self-consistent: that mode asks what a full par
    // would need on an empty floor, and a capacity total still counting the real
    // shelf would answer a different question.
    $stockCrates = 0.0;
    if ($hasCrates && $effStock > 0) {
        $stockOrderQty = op_convert_qty($effStock, $unit, $orderUnit, $lbPerEach);
        if ($stockOrderQty !== null) {
            $stockCrates = ($stockOrderQty / $cpc) * $cratesPer;
        }
    }

    return [
        'unit'        => $unit,
        'cpc'         => $cpc,
        'cases'       => $cases,
        // The vendor's own wording for the pack, from the Inventory page's Alt
        // Case field. '' = use the plain word "case(s)".
        'alt_case'    => (string)($invRow['alt_case'] ?? ''),
        // 'order_unit' equals 'unit' and 'order_qty' equals the request whenever
        // no separate order unit is configured, so consumers can use these
        // unconditionally.
        'order_unit'  => $orderUnit,
        'lb_per_each' => $lbPerEach,
        'order_qty'   => $orderQty,
        'restock_qty' => $restockQty,
        // 'has_crates' is false when either half of the conversion is missing,
        // which is what the report counts to warn that its total is a lower
        // bound rather than the whole picture.
        'crates_per_case' => $cratesPer,
        'has_crates'      => $hasCrates,
        'order_crates'    => $orderCrates,
        'stock_crates'    => $stockCrates,
    ];
}

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
//   produce_only, purchased_only, include_unscanned
//
// Rows normally come from the scan history — an item the pantry has never
// scanned has no demand to model, so it has no row. include_unscanned adds those
// inventory items back as zero-demand rows so they can still be ordered by hand.
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
    // Default off keeps every existing caller — the page's saved settings and
    // the cron mailer both — on the pure forecast they get today.
    $includeUnscanned = (bool)($opts['include_unscanned']   ?? false);

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

    // Guard the SELECT below against a partial deploy that brought this file up
    // without db.php's matching migration.
    op_ensure_inventory_crates_column($db);

    $inv = [];
    foreach ($db->query("SELECT generic_name, count, unit, count_per_case, order_unit, lb_per_each, alt_case, crates_per_case, restocked_purchased FROM inventory") as $r) {
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

        // Everything above — demand, par, order request — is denominated in the
        // unit the pantry stocks and scans the item in. The vendor may quote its
        // case in the other one, so the case maths crosses over in
        // op_order_figures() and back again for the restock, and only there.
        $fig = op_order_figures($inv[$name] ?? null,
                                $kinds[$name] === 'produce' ? 'lb' : 'each',
                                $orderReq, $effStock);

        $catKind = isset($produceNamesLc[strtolower($name)]) ? 'produce' : $kinds[$name];

        $rows[] = $fig + [
            'name'    => $name,
            'kind'    => $catKind,
            'adv'     => $adv,
            'sigma'   => $sigma,
            'safety'  => $safety,
            'S'       => $S, 'G' => $G,
            'method'  => $method,
            'par'     => $par,
            'stock'   => $stock,
            'order'   => $orderReq,
            'purchased' => (float)($inv[$name]['restocked_purchased'] ?? 0),
            'days_left' => $adv > 0 ? ($stock / $adv) : INF,
        ];
    }

    // Inventory items that have never been scanned. There is no demand signal
    // behind them, so there is nothing to forecast and nothing to recommend —
    // they are listed purely so they can be ordered by hand, which the Order
    // Request column allows on any row with nothing to recommend.
    //
    // Off unless asked for, and deliberately so: this report is a forecast, and
    // on a real install these run to dozens of rows (stock the pantry holds but
    // has never distributed through the scanner) that would bury the items
    // actually needing attention. 'method' marks them so the page can show the
    // model columns as blank rather than as a fitted zero, and days_left stays
    // INF so a reorder alert on one can never fire on no evidence.
    if ($includeUnscanned) {
        foreach ($inv as $name => $invRow) {
            if (isset($hist[$name])) continue;
            // Scanned rows take their kind from the scan and are only promoted
            // to produce by the lookup; with no scan, the lookup is the only
            // evidence there is, so an item the pantry treats as produce but
            // has never added to Produce Lookup types as packaged and stays
            // hidden under Produce Only. Adding it to the lookup fixes it —
            // there is nothing else here to infer it from (inventory has no
            // kind column, and the unit doesn't tell you: rat food is 'lb').
            $catKind  = isset($produceNamesLc[strtolower($name)]) ? 'produce' : 'packaged';
            $stock    = (float)($invRow['count'] ?? 0);
            $effStock = $ignoreStock ? 0.0 : $stock;
            // No history means no par level, so max(0, 0 - stock) is 0 — the
            // request can only ever be the one typed into the page.
            $fig = op_order_figures($invRow, $catKind === 'produce' ? 'lb' : 'each',
                                    0.0, $effStock);
            $rows[] = $fig + [
                'name'    => $name,
                'kind'    => $catKind,
                'adv'     => 0.0,
                'sigma'   => 0.0,
                'safety'  => 0.0,
                'S'       => 1.0, 'G' => 1.0,
                'method'  => 'unscanned',
                'par'     => 0.0,
                'stock'   => $stock,
                'order'   => 0.0,
                'purchased' => (float)($invRow['restocked_purchased'] ?? 0),
                'days_left' => INF,
            ];
        }
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

// Ensure inventory.crates_per_case exists, adding it if a stale db.php skipped
// the migration. Same safety net, and the same reason, as the alerts column
// below: this file and db.php are deployed as separate uploads, so a run that
// copies up the new report but not the new db.php would otherwise fatal on the
// inventory SELECT ("no such column") and take the whole Order Report with it.
// Cached per request so the PRAGMA check runs at most once.
function op_ensure_inventory_crates_column(PDO $db): void {
    static $ensured = false;
    if ($ensured) return;
    $ensured = true;
    try {
        foreach ($db->query("PRAGMA table_info(inventory)") as $c) {
            if (($c['name'] ?? '') === 'crates_per_case') return;
        }
        $db->exec("ALTER TABLE inventory ADD COLUMN crates_per_case REAL NOT NULL DEFAULT 0");
    } catch (\Throwable $e) {
        // Concurrent add, or a read-only DB — leave it; the SELECT will surface
        // any real problem. Nothing else we can safely do here.
    }
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
