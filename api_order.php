<?php
// Start / end / cancel orders.
//   POST { action: 'start' }   -> open new order
//   POST { action: 'end' }     -> close + deduct inventory
//   POST { action: 'cancel' }  -> delete order + its scans (never happened)
//   POST { action: 'current' } -> return current open order, if any
//   POST { action: 'assist_join', order_id } -> help another station's order
//   POST { action: 'assist_leave' } -> stop helping
//   POST { action: 'sync' }    -> poll: current state + this order's scans
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/auth.php';
requireAllowedIPAPI();

$in = jsonIn();
$action = $in['action'] ?? '';
$db = getDB();

// 'end' and 'cancel' are owner-only, so they resolve nothing on a helper
// station. Say why rather than the bare "No open order" a truly idle station
// would get — the two cases are indistinguishable from the lookup alone.
function assistOnlyError(string $what): string {
    $assisting = assistedOrder();
    if (!$assisting) return 'No open order';
    return 'Only the station that started order #' . (int)$assisting['id']
         . ' can ' . $what . ' it. Leave assist instead.';
}

// The full state of this station, shared by 'sync' and 'assist_join' so the
// page renders identically whether it polled into a state or acted into it.
function stationSyncPayload(): array {
    $order = activeScanOrder();
    if (!$order) {
        $assistable = [];
        foreach (assistableOrders() as $o) {
            $assistable[] = [
                'id'           => (int)$o['id'],
                'started_at'   => $o['started_at'],
                'time_label'   => date('g:i A', strtotime($o['started_at'])),
                'scan_count'   => (int)$o['scan_count'],
                'assist_count' => (int)$o['assist_count'],
            ];
        }
        // Fingerprint covers the picker too, so a new order elsewhere (or its
        // growing item count) redraws the idle station's Assist card.
        $fp = 'idle';
        foreach ($assistable as $a) $fp .= ':' . $a['id'] . '.' . $a['scan_count'];
        return [
            'ok'          => true,
            'order'       => null,
            'role'        => 'idle',
            'scans'       => [],
            'scan_count'  => 0,
            'assist_count'=> 0,
            'assistable'  => $assistable,
            'fingerprint' => $fp,
        ];
    }

    $orderId = (int)$order['id'];
    $scans   = orderScanRows($orderId);
    $assists = orderAssistCount($orderId);
    $maxId   = 0;
    foreach ($scans as $s) { if ((int)$s['id'] > $maxId) $maxId = (int)$s['id']; }

    return [
        'ok'    => true,
        'order' => [
            'id'         => $orderId,
            'started_at' => $order['started_at'],
            'is_assist'  => (bool)$order['is_assist'],
        ],
        'role'         => $order['is_assist'] ? 'assist' : 'owner',
        'scans'        => $scans,
        'scan_count'   => count($scans),
        'assist_count' => $assists,
        'station'      => currentStationId(),
        // Max scan id moves on insert, count moves on insert *and* delete, so
        // together they catch the other station's removals as well as its adds.
        'fingerprint'  => $orderId . ':' . count($scans) . ':' . $maxId . ':' . $assists,
    ];
}

if ($action === 'start') {
    // A helper station has no order of its own to start — it would silently
    // abandon the order it is assisting. The UI never gets here (the assisted
    // order is already in state.orderId, so startOrderIfNeeded short-circuits),
    // but the endpoint shouldn't depend on that.
    $assisting = assistedOrder();
    if ($assisting) {
        jsonOut([
            'ok'    => false,
            'error' => 'This station is assisting order #' . (int)$assisting['id']
                     . '. Leave assist first.',
        ], 409);
    }
    // Auto-close only THIS station's stale open orders, so starting an order on
    // one scanner never closes another scanner's in-progress order. Each station
    // keeps at most one open order; other stations are untouched.
    $station = currentStationId();
    $close = $db->prepare("UPDATE orders SET status='closed', ended_at=? WHERE status='open' AND station=?");
    $close->execute([now(), $station]);
    $ins = $db->prepare("INSERT INTO orders (started_at, status, station) VALUES (?, 'open', ?)");
    $ins->execute([now(), $station]);
    $id = (int)$db->lastInsertId();
    jsonOut(['ok' => true, 'order_id' => $id, 'started_at' => now()]);
}

if ($action === 'end') {
    // Owner-scoped on purpose: currentOpenOrder() (not activeScanOrder()) means
    // a helper station can never close the order it is only assisting.
    $open = currentOpenOrder();
    if (!$open) jsonOut(['ok' => false, 'error' => assistOnlyError('end')], 400);
    $u = $db->prepare("UPDATE orders SET status='closed', ended_at=? WHERE id=?");
    $u->execute([now(), $open['id']]);
    // Release the helpers: their next sync sees no order and drops to idle.
    clearOrderAssists((int)$open['id']);

    // Decrement inventory by what was scanned in this order so the inventory
    // count reflects what just left the building.
    $items = $db->prepare(
        "SELECT generic_name, SUM(quantity) qty, SUM(COALESCE(weight_lbs,0)) wt, kind
         FROM scans WHERE order_id=? GROUP BY generic_name"
    );
    $items->execute([$open['id']]);
    $exists = $db->prepare('SELECT 1 FROM inventory WHERE generic_name=?');
    $update = $db->prepare(
        "UPDATE inventory SET count = MAX(0, count - ?), updated_at=? WHERE generic_name=?"
    );
    foreach ($items->fetchAll() as $row) {
        $delta = ($row['kind'] === 'produce') ? (float)$row['wt'] : (float)$row['qty'];
        // Only decrement existing inventory rows. Closing an order shouldn't
        // imply "the prior count was zero" for an item never counted.
        $exists->execute([$row['generic_name']]);
        if ($exists->fetchColumn()) {
            $update->execute([$delta, now(), $row['generic_name']]);
        }
    }

    jsonOut(['ok' => true, 'order_id' => (int)$open['id'], 'ended_at' => now()]);
}

if ($action === 'cancel') {
    // Owner-scoped for the same reason as 'end', and more so: cancelling
    // discards every scan, including the helper's.
    $open = currentOpenOrder();
    if (!$open) jsonOut(['ok' => false, 'error' => assistOnlyError('cancel')], 400);
    // Cancellation = "this order didn't happen". Wipe its scans so they
    // don't pollute the demand history, drop the order row, and reset the
    // AUTOINCREMENT high-water mark to the surviving MAX(id). When the
    // cancelled order was the newest, this reuses its number instead of
    // skipping it; with another station's order still open, MAX(id) is that
    // order so seq is left effectively unchanged. Either way the next insert
    // is MAX(id)+1, so no live id is ever collided with.
    $db->beginTransaction();
    $db->prepare("DELETE FROM scans WHERE order_id=?")->execute([$open['id']]);
    // Before the order row, so the order_assists foreign key still resolves.
    $db->prepare("DELETE FROM order_assists WHERE order_id=?")->execute([$open['id']]);
    $db->prepare("DELETE FROM orders WHERE id=?")->execute([$open['id']]);
    $db->exec(
        "UPDATE sqlite_sequence SET seq = (SELECT IFNULL(MAX(id), 0) FROM orders) WHERE name='orders'"
    );
    $db->commit();
    jsonOut(['ok' => true, 'order_id' => (int)$open['id'], 'cancelled_at' => now()]);
}

if ($action === 'current') {
    $open = currentOpenOrder();
    if (!$open) jsonOut(['ok' => true, 'order' => null]);
    $c = $db->prepare("SELECT COUNT(*) FROM scans WHERE order_id=?");
    $c->execute([$open['id']]);
    $open['scan_count'] = (int)$c->fetchColumn();
    jsonOut(['ok' => true, 'order' => $open]);
}

if ($action === 'assist_join') {
    $orderId = (int)($in['order_id'] ?? 0);
    if ($orderId <= 0) jsonOut(['ok' => false, 'error' => 'Missing order_id'], 400);
    // The picker was rendered from a poll, so the order may have closed between
    // the render and the tap. joinOrderAssist() reports that (and the other
    // refusals) as a message the page can show verbatim.
    $err = joinOrderAssist($orderId);
    if ($err !== null) jsonOut(['ok' => false, 'error' => $err], 409);
    jsonOut(stationSyncPayload());
}

if ($action === 'assist_leave') {
    leaveOrderAssist();
    jsonOut(stationSyncPayload());
}

if ($action === 'sync') {
    jsonOut(stationSyncPayload());
}

jsonOut(['ok' => false, 'error' => 'Unknown action'], 400);
