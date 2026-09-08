<?php
// Start / end / cancel orders.
//   POST { action: 'start' }   -> open new order
//   POST { action: 'end' }     -> close + deduct inventory
//   POST { action: 'cancel' }  -> delete order + its scans (never happened)
//   POST { action: 'current' } -> return current open order, if any
//   POST { action: 'assist_join', order_id } -> help another station's order
//   POST { action: 'assist_auto' } -> help the one other open order (command barcode)
//   POST { action: 'assist_leave' } -> stop helping (and leave assist mode)
//   POST { action: 'scan_beep', on } -> beep on this station's own scans?
//   POST { action: 'sync' }    -> poll: current state + this order's scans
//   POST { action: 'remote_end', order_id }    -> admin: end another station's order
//   POST { action: 'remote_cancel', order_id } -> admin: cancel another station's order
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
    // Sticky assist, resolved before anything reads the station's state: a
    // station left in assist mode joins the next open order here, so the poll
    // that finds the previous order gone is the same one that reports the new
    // one. Nothing happens unless the mode is on and the station is free.
    autoJoinNextAssist();
    $assistMode = assistModeOn();
    // An administrator or supervisor signed in on this device (the scan page
    // itself is gated by IP, not by password, so most stations are neither).
    $canClose   = fpAuthRole() !== '';
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
        // growing item count) redraws the idle station's Assist card. The mode
        // is in it as well, so switching it off repaints the order bar.
        // ':admin' so signing in (or out) in another tab repaints the card
        // with — or without — its End / Cancel buttons on the next poll.
        $fp = 'idle' . ($assistMode ? ':assisting' : '') . ($canClose ? ':admin' : '');
        foreach ($assistable as $a) $fp .= ':' . $a['id'] . '.' . $a['scan_count'];
        return [
            'ok'          => true,
            'order'       => null,
            'role'        => 'idle',
            'scans'       => [],
            'scan_count'  => 0,
            'assist_count'=> 0,
            // On while idle = between orders: the station is waiting for the
            // next one, not free to start an order of its own.
            'assist_mode' => $assistMode,
            // The station's own switch, so the assist controls come back from a
            // poll (or a reload) in the position the operator left them.
            'scan_beep'   => stationScanBeep(),
            'assistable'  => $assistable,
            // May this station close an order it doesn't own? The Assist card
            // draws its End / Cancel buttons from this, and the endpoint checks
            // the cookie again — the flag decides what is offered, not what is
            // allowed.
            'can_close'   => $canClose,
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
        'assist_mode'  => $assistMode,
        'scan_beep'    => stationScanBeep(),
        'station'      => currentStationId(),
        'can_close'    => $canClose,
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
    // In assist mode but between orders. Opening an order of its own here is
    // exactly what sticky assist exists to prevent: the helper would take the
    // next household onto a second order instead of the one the primary
    // station is about to start. Wait for that order, or leave assist.
    if (assistModeOn()) {
        jsonOut([
            'ok'    => false,
            'error' => 'This station is in assist mode, waiting for the next order. '
                     . 'Leave assist to start orders of its own.',
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
    $endedAt = endOrderById((int)$open['id']);
    jsonOut(['ok' => true, 'order_id' => (int)$open['id'], 'ended_at' => $endedAt]);
}

if ($action === 'cancel') {
    // Owner-scoped for the same reason as 'end', and more so: cancelling
    // discards every scan, including the helper's.
    $open = currentOpenOrder();
    if (!$open) jsonOut(['ok' => false, 'error' => assistOnlyError('cancel')], 400);
    $at = cancelOrderById((int)$open['id']);
    jsonOut(['ok' => true, 'order_id' => (int)$open['id'], 'cancelled_at' => $at]);
}

// ── Closing someone else's order ────────────────────────────────────────────
// A station that goes away mid-order — laptop closed, tablet carried off,
// browser gone — leaves its order open forever. Every other station keeps
// offering to assist it, and a station in sticky assist mode will keep
// re-joining it, because as far as the database is concerned it is a live
// order waiting for its next item. The owner station is the only one that can
// End or Cancel it, and the owner station is exactly what is missing.
//
// So these two actions relax the ownership rule for an administrator or
// supervisor: the same End and Cancel, on an order this station does not own.
// They are not part of the volunteer flow — an un-logged-in station gets the
// buttons neither in the UI nor here.
if ($action === 'remote_end' || $action === 'remote_cancel') {
    if (fpAuthRole() === '') {
        jsonOut(['ok' => false,
                 'error' => 'Log in as an administrator or supervisor on this station '
                          . 'to close another station\'s order.'], 403);
    }
    $orderId = (int)($in['order_id'] ?? 0);
    if ($orderId <= 0) jsonOut(['ok' => false, 'error' => 'Missing order_id'], 400);
    $stmt = $db->prepare("SELECT * FROM orders WHERE id=?");
    $stmt->execute([$orderId]);
    $target = $stmt->fetch();
    // The Assist card was drawn from a poll up to 2.5s ago, and the owner may
    // have come back and closed the order in between.
    if (!$target) {
        jsonOut(['ok' => false, 'error' => 'That order no longer exists.'], 409);
    }
    if ($target['status'] !== 'open') {
        jsonOut(['ok' => false, 'error' => 'Order #' . $orderId . ' is already closed.'], 409);
    }
    $ended = ($action === 'remote_end');
    $at = $ended ? endOrderById($orderId) : cancelOrderById($orderId);
    // The station's own state, so the page repaints from one response: the
    // closed order drops out of the Assist card, and a station left in assist
    // mode picks up whatever real order is open now.
    $payload = stationSyncPayload();
    $payload['closed_order']  = $orderId;
    $payload['closed_action'] = $ended ? 'ended' : 'cancelled';
    $payload['closed_at']     = $at;
    jsonOut($payload);
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
    // Joining is also opting into assist mode: this station keeps helping,
    // order after order, until Leave Assist.
    setAssistMode(true);
    jsonOut(stationSyncPayload());
}

if ($action === 'assist_auto') {
    // The scanner-only form of the "+ Assist" button: command barcode 990002
    // carries no order number, so the order is chosen here, against live state,
    // rather than from a picker the station polled up to 2.5s ago.
    if (assistedOrder()) {
        // Already helping. Scanning the code again is a no-op, not a fault —
        // an operator who missed the banner will naturally scan it twice.
        setAssistMode(true);
        $payload = stationSyncPayload();
        $payload['already'] = true;
        jsonOut($payload);
    }
    if (currentOpenOrder()) {
        jsonOut(['ok' => false,
                 'error' => "End this station's own order before assisting another."], 409);
    }
    $open = assistableOrders();
    if (!$open) {
        jsonOut(['ok' => false,
                 'error' => 'No other station has an open order to assist.'], 409);
    }
    if (count($open) > 1) {
        // Ambiguous, and guessing would put the operator on the wrong
        // household. Send them to the card, which lists every candidate.
        jsonOut(['ok' => false,
                 'error' => count($open) . ' orders are open — tap + Assist on the one you want.'], 409);
    }
    $err = joinOrderAssist((int)$open[0]['id']);
    if ($err !== null) jsonOut(['ok' => false, 'error' => $err], 409);
    setAssistMode(true);
    jsonOut(stationSyncPayload());
}

if ($action === 'assist_leave') {
    // The one way out of assist mode, so drop the mode before the row —
    // otherwise the sync below would helpfully re-join the order just left.
    setAssistMode(false);
    leaveOrderAssist();
    jsonOut(stationSyncPayload());
}

if ($action === 'scan_beep') {
    // The sliding switch on the assisting station: does it sound the scan tone
    // as it records items? Stored per station rather than per order, so the
    // operator sets it once and the next order — and the next shift — starts
    // the way they left it.
    setStationScanBeep(!empty($in['on']));
    jsonOut(stationSyncPayload());
}

if ($action === 'sync') {
    jsonOut(stationSyncPayload());
}

jsonOut(['ok' => false, 'error' => 'Unknown action'], 400);
