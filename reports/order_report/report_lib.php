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
    $z             = (float)($opts['z']                     ?? (float)setting('safety_z', '1.65'));
    $ignoreStock   = (bool)($opts['ignore_stock']           ?? false);
    $ignoreEvents  = (bool)($opts['ignore_events']          ?? true);
    $produceOnly   = (bool)($opts['produce_only']           ?? false);
    $purchasedOnly = (bool)($opts['purchased_only']         ?? false);

    $today = new DateTimeImmutable('today');

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

    $rows = [];
    foreach ($hist as $name => $days) {
        $vals = [];
        for ($i = 0; $i < $velWindow; $i++) {
            $d = $today->modify("-{$i} days")->format('Y-m-d');
            $vals[] = $days[$d] ?? 0.0;
        }
        $n   = count($vals);
        $adv = array_sum($vals) / $n;
        $var = 0.0;
        foreach ($vals as $v) $var += ($v - $adv) * ($v - $adv);
        $sigma = sqrt($var / $n);

        $fc = null;
        try {
            $fc = op_forecast_item_cached($db, $name, $days, $today, $leadTime, $z, $ignoreEvents);
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
