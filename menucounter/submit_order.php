<?php
require_once 'db.php';
$db = getDB();

// ── Validate name ─────────────────────────────────────────────────────────────
$name = trim($_POST['name'] ?? '');
if (!$name) {
    header('Location: index.php?error=name');
    exit;
}

// ── Family size (capped at 5) ─────────────────────────────────────────────────
$adults     = max(0, (int)($_POST['adults']   ?? 1));
$children   = max(0, (int)($_POST['children'] ?? 0));
$familySize = min($adults + ($children / 2), 5);
if ($familySize < 1) $familySize = 1; // at least 1

$weekDate = trim($_POST['week_date'] ?? '');
$notes    = trim($_POST['notes']     ?? '');

// ── Insert order row ──────────────────────────────────────────────────────────
$stmt = $db->prepare(
    "INSERT INTO orders (name, adults, children, week_date, notes, status)
     VALUES (:name, :adults, :children, :week_date, :notes, 'pending')"
);
$stmt->execute([
    ':name'      => $name,
    ':adults'    => $adults,
    ':children'  => $children,
    ':week_date' => $weekDate,
    ':notes'     => $notes,
]);
$orderId = (int)$db->lastInsertId();

// ── Load active config items with family_factor ───────────────────────────────
$configStmt = $db->query(
    "SELECT * FROM config_items WHERE active = 1 ORDER BY category, sort_order, id"
);
$configItems = $configStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Insert order items using family_factor ────────────────────────────────────
$insertStmt = $db->prepare(
    "INSERT INTO order_items (order_id, category, item_name, item_detail, completed, config_item_id)
     VALUES (:order_id, :category, :item_name, :item_detail, 0, :config_item_id)"
);

foreach ($configItems as $item) {
    $fieldName = 'item_' . $item['id'];
    if (empty($_POST[$fieldName])) continue;

    $detail = trim($_POST['detail_' . $item['id']] ?? '');

    // Determine which count to use for this item
    $useAdults   = (int)($item['use_adults']   ?? 0);
    $useChildren = (int)($item['use_children'] ?? 0);
    if ($useChildren && !$useAdults) {
        $count = $children;           // children only
    } elseif ($useAdults && !$useChildren) {
        $count = $adults;             // adults only
    } else {
        $count = $familySize;         // combined (default)
    }
    if ($count < 1) $count = 1;

    $factor   = (float)($item['family_factor'] ?? 1.0);
    if ($factor <= 0) $factor = 1.0;
    $quantity = (int)ceil($count * $factor);
    if ($quantity < 1) $quantity = 1;

    for ($q = 0; $q < $quantity; $q++) {
        $insertStmt->execute([
            ':order_id'      => $orderId,
            ':category'      => $item['category'],
            ':item_name'     => $item['item_name'],
            ':item_detail'   => $detail,
            ':config_item_id'=> (int)$item['id'],
        ]);
    }
}

header('Location: index.php?success=1&order_id=' . $orderId);
exit;
