<?php
// Record a single scan, look up a barcode, or remove a scan from the open order.
// POST { action: 'lookup', barcode }                          -> resolve only, do not insert.
// POST { action: 'record', barcode, weight_lbs?, quantity? }  -> resolve + insert; returns the new scan_id.
// POST { action: 'delete', scan_id }                          -> remove a scan, only if it belongs to the current open order.

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/auth.php';
requireAllowedIPAPI();
require_once __DIR__ . '/lookup.php';

$in = jsonIn();
$action = $in['action'] ?? '';

if ($action === 'delete') {
    $scanId = (int)($in['scan_id'] ?? 0);
    if ($scanId <= 0) jsonOut(['ok' => false, 'error' => 'Missing scan_id'], 400);

    $open = currentOpenOrder();
    if (!$open) jsonOut(['ok' => false, 'error' => 'No open order'], 409);

    $db = getDB();
    // Scope the delete to the current open order so a stray scan_id can't
    // mutate historical, already-closed orders.
    $del = $db->prepare("DELETE FROM scans WHERE id = ? AND order_id = ?");
    $del->execute([$scanId, $open['id']]);
    if ($del->rowCount() === 0) {
        jsonOut(['ok' => false, 'error' => 'Scan not found in current order'], 404);
    }

    $c = $db->prepare("SELECT COUNT(*) FROM scans WHERE order_id = ?");
    $c->execute([$open['id']]);
    jsonOut(['ok' => true, 'scan_count' => (int)$c->fetchColumn()]);
}

$barcode = trim((string)($in['barcode'] ?? ''));
if ($barcode === '') jsonOut(['ok' => false, 'error' => 'Missing barcode'], 400);

// Manual-mapping rescue: when the scanner page couldn't resolve a UPC via
// OFF and the operator filled in the "Unknown UPC" modal with a generic
// name, write the mapping to upc_lookup BEFORE resolving so the resolver
// hits the cache instead of re-running OFF + OpenAI.
$manualGeneric = trim((string)($in['generic_name'] ?? ''));
if ($action === 'record' && $manualGeneric !== '' && classifyBarcode($barcode) === 'packaged') {
    $manualBrand = trim((string)($in['brand_name'] ?? ''));
    getDB()->prepare(
        "INSERT INTO upc_lookup (upc, brand_name, generic_name, source, created_at)
         VALUES (?, ?, ?, 'manual', ?)
         ON CONFLICT(upc) DO NOTHING"
    )->execute([$barcode, $manualBrand, $manualGeneric, now()]);
}

$res = lookupBarcode($barcode);
if ($action === 'lookup') jsonOut($res);

if ($action !== 'record') jsonOut(['ok' => false, 'error' => 'Unknown action'], 400);

if (!$res['ok']) jsonOut($res, 422);

$open = currentOpenOrder();
if (!$open) jsonOut(['ok' => false, 'error' => 'No order is open. Tap START ORDER first.'], 409);

$db = getDB();
$weight = null;
$qty    = 1;
if ($res['kind'] === 'produce' && !empty($res['needs_weight'])) {
    $weight = isset($in['weight_lbs']) ? (float)$in['weight_lbs'] : 0;
    if ($weight <= 0) jsonOut(['ok' => false, 'error' => 'Weight required for ' . $res['generic_name']], 400);
} else {
    $qty = isset($in['quantity']) ? max(1, (int)$in['quantity']) : 1;
}

$ins = $db->prepare(
    "INSERT INTO scans (order_id, barcode, generic_name, kind, quantity, weight_lbs, scanned_at)
     VALUES (?, ?, ?, ?, ?, ?, ?)"
);
$ins->execute([
    $open['id'], $barcode, $res['generic_name'], $res['kind'],
    $qty, $weight, now()
]);
$scanId = (int)$db->lastInsertId();

// Echo the running totals back so the scanner UI can update its strip.
$c = $db->prepare("SELECT COUNT(*) FROM scans WHERE order_id=?");
$c->execute([$open['id']]);
$scanCount = (int)$c->fetchColumn();

jsonOut([
    'ok' => true,
    'order_id' => (int)$open['id'],
    'scan_count' => $scanCount,
    'warning' => $res['ai_error'] ?? null,
    'item' => [
        'id' => $scanId,
        'generic_name' => $res['generic_name'],
        'kind' => $res['kind'],
        'quantity' => $qty,
        'weight_lbs' => $weight,
        'barcode' => $barcode,
        'brand_name' => $res['brand_name'] ?? null,
        'source' => $res['source'] ?? null,
    ],
]);
