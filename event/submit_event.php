<?php
// AJAX endpoint for the Events form. Writes a single closed order tagged
//   "EVENT · <event type> · <initials>"
// one `scans` row per staged item, and decrements `inventory` by each
// staged count. Wrapped in a transaction so a mid-batch failure can't
// leave inventory half-decremented. The orders_listing uses the EVENT
// note prefix to render an event-type pill, and the volume_report picks
// the scans up automatically because it just aggregates the `scans`
// table by date.
$GLOBALS['FS_PREFIX'] = '../';
require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/event_types.php';
requireLoginAPI();

header('Content-Type: application/json');

$db = getDB();

$eventType = trim((string)($_POST['event_type'] ?? ''));
$initials  = strtoupper(trim((string)($_POST['initials'] ?? '')));
$names     = $_POST['item_name']  ?? [];
$counts    = $_POST['item_count'] ?? [];
$units     = $_POST['item_unit']  ?? [];

if (!isEventType($eventType)) {
    echo json_encode(['ok' => false, 'error' => 'Pick a supported event type.']);
    exit;
}
if ($initials === '') {
    echo json_encode(['ok' => false, 'error' => 'Enter your initials.']);
    exit;
}
// Keep initials short — they end up in the order's note field.
if (strlen($initials) > 8) $initials = substr($initials, 0, 8);

if (!is_array($names) || !is_array($counts) || empty($names)
    || count($names) !== count($counts)) {
    echo json_encode(['ok' => false, 'error' => 'Stage at least one item with “Use for event”.']);
    exit;
}
if (!is_array($units)) $units = [];

// Re-pull current inventory by lowercased name. This is the only source
// of truth for what we'll decrement — never trust the client-side unit
// or available count; both can have changed since the page loaded.
$invByLc = [];
foreach ($db->query("SELECT generic_name, count, unit FROM inventory WHERE count > 0") as $r) {
    $invByLc[strtolower($r['generic_name'])] = $r;
}

$lines = [];
foreach ($names as $i => $rawName) {
    $name = trim((string)$rawName);
    if ($name === '') continue;
    $lc = strtolower($name);
    if (!isset($invByLc[$lc])) {
        echo json_encode(['ok' => false,
            'error' => '“' . $name . '” is no longer in stock — refresh the page and try again.']);
        exit;
    }
    $row     = $invByLc[$lc];
    $onHand  = (float)$row['count'];
    $unit    = $row['unit'];                 // server-authoritative unit
    $reqRaw  = (string)$counts[$i];
    $req     = (float)$reqRaw;
    if ($req <= 0 || $reqRaw === '' || !is_numeric($reqRaw)) {
        echo json_encode(['ok' => false,
            'error' => 'Count for ' . $name . ' must be greater than 0.']);
        exit;
    }
    if ($req > $onHand) {
        echo json_encode(['ok' => false,
            'error' => 'Only ' . rtrim(rtrim(number_format($onHand, 2, '.', ''), '0'), '.') .
                       ' ' . $unit . ' of ' . $name . ' is in stock.']);
        exit;
    }
    // 'lb' inventory rows are tracked as weight; everything else as integer count.
    $byWeight = ($unit === 'lb');
    $lines[] = [
        'generic_name' => $row['generic_name'],   // preserve original casing
        'unit'         => $unit,
        'by_weight'    => $byWeight,
        // For 'each' items we still allow fractional client input but
        // round to an integer at the scans/decrement layer — the
        // existing scans.quantity column is INTEGER.
        'quantity'     => $byWeight ? 1 : (int)round($req),
        'weight_lbs'   => $byWeight ? round($req, 2) : null,
    ];
}

if (empty($lines)) {
    echo json_encode(['ok' => false, 'error' => 'No valid items to log.']);
    exit;
}

// Persist: one orders row + one scans row per line + one inventory
// decrement per line, all in a single transaction. Note format matches
// the "PREFIX · …" convention used by delivery so orders_listing can
// detect it with a LIKE 'EVENT %' check.
$db->beginTransaction();
try {
    $ts   = now();
    $note = "EVENT · {$eventType} · {$initials}";

    $db->prepare(
        "INSERT INTO orders (started_at, ended_at, status, note) VALUES (?, ?, 'closed', ?)"
    )->execute([$ts, $ts, $note]);
    $orderId = (int)$db->lastInsertId();

    $insScan = $db->prepare(
        "INSERT INTO scans (order_id, barcode, generic_name, kind, quantity, weight_lbs, scanned_at)
         VALUES (?, '', ?, ?, ?, ?, ?)"
    );
    $dec = $db->prepare(
        "UPDATE inventory SET count = MAX(0, count - ?), updated_at = ? WHERE generic_name = ?"
    );

    foreach ($lines as $ln) {
        $kind = ($ln['unit'] === 'lb') ? 'produce' : 'packaged';
        if ($ln['by_weight']) {
            $insScan->execute([$orderId, $ln['generic_name'], $kind, 1, $ln['weight_lbs'], $ts]);
            $dec->execute([$ln['weight_lbs'], $ts, $ln['generic_name']]);
        } else {
            $insScan->execute([$orderId, $ln['generic_name'], $kind, $ln['quantity'], null, $ts]);
            $dec->execute([$ln['quantity'], $ts, $ln['generic_name']]);
        }
    }

    $db->commit();
    echo json_encode(['ok' => true, 'order_id' => $orderId, 'lines' => count($lines)]);
    exit;
} catch (\Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    echo json_encode(['ok' => false, 'error' => 'Save failed: ' . $e->getMessage()]);
    exit;
}
