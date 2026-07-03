<?php
// AJAX endpoint for the Restock form. Increments inventory counts for
// each staged line in a single transaction. No orders/scans rows are
// written — restocks are inventory mutations, not customer orders, so
// they don't belong in orders_listing or volume_report (which mirrors
// the model the Inventory page already uses).
$GLOBALS['FS_PREFIX'] = '../';
require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../auth.php';
requireLoginAPI();

header('Content-Type: application/json');

$db = getDB();

$names     = $_POST['item_name']  ?? [];
$counts    = $_POST['item_count'] ?? [];
$units     = $_POST['item_unit']  ?? [];
// Source flag for the whole batch. Standard checkbox semantics: present
// = checked = purchased; absent = unchecked = donated. The matching
// column gets bumped by the same amount as `count` so the lifetime
// purchased/donated split stays in sync with what was actually added.
$purchased = isset($_POST['purchased']);
$sourceCol = $purchased ? 'restocked_purchased' : 'restocked_donated';

if (!is_array($names) || !is_array($counts) || empty($names)
    || count($names) !== count($counts)) {
    echo json_encode(['ok' => false, 'error' => 'Stage at least one item with “Add to inventory”.']);
    exit;
}
if (!is_array($units)) $units = [];

// Re-pull inventory by lowercased name. The server is authoritative on
// both the canonical generic_name spelling and the unit; never trust the
// client-side snapshot, which may be stale.
$invByLc = [];
foreach ($db->query("SELECT generic_name, count, unit FROM inventory") as $r) {
    $invByLc[strtolower($r['generic_name'])] = $r;
}

$lines = [];
foreach ($names as $i => $rawName) {
    $name = trim((string)$rawName);
    if ($name === '') continue;
    $lc = strtolower($name);
    if (!isset($invByLc[$lc])) {
        // The item must already exist in inventory — restocking presumes
        // a known unit. Brand-new items get created on the Inventory page.
        echo json_encode(['ok' => false,
            'error' => '“' . $name . '” is not in inventory yet — add it on the Inventory page first.']);
        exit;
    }
    $row    = $invByLc[$lc];
    $unit   = $row['unit'];                      // server-authoritative unit
    $reqRaw = (string)$counts[$i];
    $req    = (float)$reqRaw;
    if ($req <= 0 || $reqRaw === '' || !is_numeric($reqRaw)) {
        echo json_encode(['ok' => false,
            'error' => 'Count for ' . $name . ' must be greater than 0.']);
        exit;
    }
    // 'lb' inventory is tracked as a real number; everything else as
    // integer count. Match the precision the inventory column already uses.
    $lines[] = [
        'generic_name' => $row['generic_name'],   // preserve original casing
        'unit'         => $unit,
        'add'          => ($unit === 'lb') ? round($req, 2) : (int)round($req),
    ];
}

if (empty($lines)) {
    echo json_encode(['ok' => false, 'error' => 'No valid items to restock.']);
    exit;
}

// Persist: one inventory increment per line, all in a single transaction
// so a mid-batch failure can't leave inventory partially updated.
$db->beginTransaction();
try {
    $ts  = now();
    // The source column name is whitelisted to one of two fixed values
    // above, so interpolating it into the SQL is safe — PDO can't bind
    // identifiers, only values.
    $inc = $db->prepare(
        "UPDATE inventory
            SET count = count + ?,
                {$sourceCol} = {$sourceCol} + ?,
                updated_at = ?
          WHERE generic_name = ?"
    );
    foreach ($lines as $ln) {
        $inc->execute([$ln['add'], $ln['add'], $ts, $ln['generic_name']]);
    }
    $db->commit();
    echo json_encode(['ok' => true, 'lines' => count($lines)]);
    exit;
} catch (\Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    echo json_encode(['ok' => false, 'error' => 'Save failed: ' . $e->getMessage()]);
    exit;
}
