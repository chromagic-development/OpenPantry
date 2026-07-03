<?php
// Start / end / cancel orders.
//   POST { action: 'start' }   -> open new order
//   POST { action: 'end' }     -> close + deduct inventory
//   POST { action: 'cancel' }  -> delete order + its scans (never happened)
//   POST { action: 'current' } -> return current open order, if any
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/auth.php';
requireAllowedIPAPI();

$in = jsonIn();
$action = $in['action'] ?? '';
$db = getDB();

if ($action === 'start') {
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
    $open = currentOpenOrder();
    if (!$open) jsonOut(['ok' => false, 'error' => 'No open order'], 400);
    $u = $db->prepare("UPDATE orders SET status='closed', ended_at=? WHERE id=?");
    $u->execute([now(), $open['id']]);

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
    $open = currentOpenOrder();
    if (!$open) jsonOut(['ok' => false, 'error' => 'No open order'], 400);
    // Cancellation = "this order didn't happen". Wipe its scans so they
    // don't pollute the demand history, drop the order row, and reset the
    // AUTOINCREMENT high-water mark to the surviving MAX(id). When the
    // cancelled order was the newest, this reuses its number instead of
    // skipping it; with another station's order still open, MAX(id) is that
    // order so seq is left effectively unchanged. Either way the next insert
    // is MAX(id)+1, so no live id is ever collided with.
    $db->beginTransaction();
    $db->prepare("DELETE FROM scans WHERE order_id=?")->execute([$open['id']]);
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

jsonOut(['ok' => false, 'error' => 'Unknown action'], 400);
