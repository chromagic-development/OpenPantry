<?php
// AJAX endpoint for the delivery kiosk form. Validates the POST, then defers
// to persistDeliveryOrder() (delivery/db.php) so the kiosk and the AI
// upload processor write deliveries identically (order + scans + inventory
// decrement + delivered_at stamp + "DELIVERY · Client #N · …" note).
// PII (name/address/city/phone) stays in the browser; only the order
// number, items, counts, and the client id live in the database.
$GLOBALS['FS_PREFIX'] = '../';
require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/db.php';
requireAllowedIPAPI();

header('Content-Type: application/json');

$db  = getDB();
$pdb = picklistDB();

$group    = (string)($_POST['group'] ?? '');
$adults   = (int)($_POST['adults']   ?? 1);
$children = (int)($_POST['children'] ?? 0);
$selected = $_POST['item'] ?? [];
$sizes    = $_POST['size'] ?? [];
$clientId = (int)($_POST['client_id'] ?? 0); // 0 = ad-hoc

if (!is_array($selected) || empty($selected)) {
    echo json_encode(['ok' => false, 'error' => 'Select at least one item.']);
    exit;
}
if (!is_array($sizes)) $sizes = [];

$result = persistDeliveryOrder($db, $pdb, $clientId, $group, $adults, $children, $selected, $sizes);
if (!$result['ok']) {
    echo json_encode(['ok' => false, 'error' => $result['error']]);
    exit;
}

// Packing-list payload. PII stays in the browser and is merged into the
// printout client-side; it is never written to the database.
$out = [];
foreach ($result['lines'] as $ln) {
    $out[] = [
        'name'       => $ln['generic_name'],
        'quantity'   => $ln['quantity'],
        'weight_lbs' => !empty($ln['by_weight']) ? $ln['weight_lbs'] : null,
    ];
}
echo json_encode([
    'ok'       => true,
    'order_id' => $result['order_id'],
    'group'    => $group,
    'adults'   => max(1, $adults),
    'children' => max(0, $children),
    'items'    => $out,
]);
exit;
