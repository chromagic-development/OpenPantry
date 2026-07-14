<?php
// Printable per-client Packing & Delivery Lists for the current rotation.
// One page per delivered client in the selected group; each page shows the
// client's contact info, group, household, and the items + quantities
// recorded against their most recent delivery order.
//
// Filter: ?group=K-1|K-2|E-1|E-2|all (default: all)
// Scope:  enabled clients with delivered_at IS NOT NULL (i.e. processed
//         this round — either through the kiosk or via process_upload.php).
//
// Per-client order lookup keys off the encoded note format set by
// persistDeliveryOrder(): "DELIVERY · Client #N · …". The newest matching
// order wins, so re-processing a client (after a reset + new upload)
// naturally reprints with the latest items.
$GLOBALS['FS_PREFIX'] = '../';
require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/db.php';
requireLogin();

$db = getDB();

$validGroups = deliveryGroups();
$group       = (string)($_GET['group'] ?? 'all');
$groupFilter = in_array($group, $validGroups, true) ? $group : 'all';

$sql = "SELECT id, name, adults, children, grp, address, city, phone
          FROM delivery_clients
         WHERE enabled = 1 AND delivered_at IS NOT NULL";
$params = [];
if ($groupFilter !== 'all') { $sql .= " AND grp = ?"; $params[] = $groupFilter; }
$sql .= " ORDER BY grp, sort_order, id";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$clients = array_map('fsDecryptClientFields', $stmt->fetchAll());

// For each client, find their most recent delivery order (note encodes the
// client id) and pull its scans. Skip clients with no detectable order.
$orderStmt = $db->prepare(
    "SELECT id, started_at FROM orders WHERE note LIKE ? ORDER BY id DESC LIMIT 1"
);
$scanStmt = $db->prepare(
    "SELECT generic_name, kind, quantity, weight_lbs FROM scans
      WHERE order_id = ? ORDER BY kind DESC, generic_name"
);

$bundles = []; // each: ['client'=>..., 'order'=>..., 'scans'=>[...]]
foreach ($clients as $c) {
    $orderStmt->execute(['DELIVERY · Client #' . (int)$c['id'] . ' · %']);
    $order = $orderStmt->fetch();
    if (!$order) continue;
    $scanStmt->execute([$order['id']]);
    $bundles[] = [
        'client' => $c,
        'order'  => $order,
        'scans'  => $scanStmt->fetchAll(),
    ];
}

function fmtAmount(array $s): string {
    if (($s['kind'] ?? '') === 'produce' && $s['weight_lbs'] !== null) {
        $w = rtrim(rtrim(number_format((float)$s['weight_lbs'], 2, '.', ''), '0'), '.');
        return $w . ' lb';
    }
    return (int)$s['quantity'] . ' each';
}

$today = date('M j, Y');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<link rel="icon" type="image/x-icon" href="../menucounter/favicon.ico">
<title>Packing &amp; Delivery Lists — <?= htmlspecialchars($groupFilter) ?> — <?= htmlspecialchars($today) ?></title>
<style>
  body { font-family: Arial, Helvetica, sans-serif; margin: 0; color: #000; background:#fff; }
  .controls {
    padding: 10px 14px; background: #f4f1e6; border-bottom: 1px solid #ccc;
    display: flex; align-items: center; gap: 10px; font-size: .9rem;
  }
  .controls button {
    font: inherit; padding: 6px 12px; cursor: pointer;
    border: 1px solid #888; background: #fff; border-radius: 4px;
  }
  .page { padding: 0.5in; page-break-after: always; }
  .page:last-child { page-break-after: auto; }
  .order-badge {
    float: right; border: 2px solid #000; padding: 6px 12px;
    font-size: 12pt; font-weight: 800; font-family: 'Courier New', monospace;
    letter-spacing: 1px;
  }
  h1 { margin: 0 0 4px 0; font-size: 18pt; }
  .subhead { font-size: 10pt; color: #555; margin-bottom: 10px; }
  .client-info {
    border: 1px solid #444; border-radius: 6px; padding: 8px 12px;
    margin: 10px 0 14px; font-size: 11pt;
    display: grid; grid-template-columns: repeat(2, 1fr); gap: 4px 18px;
    clear: both;
  }
  .client-info .lbl {
    font-size: 9pt; color: #555; text-transform: uppercase;
    letter-spacing: .5px; margin-right: 4px;
  }
  table.items { width: 100%; border-collapse: collapse; margin-top: 8px; }
  table.items th {
    text-align: left; font-size: 9pt; text-transform: uppercase;
    color: #555; border-bottom: 1px solid #000; padding: 4px 6px;
  }
  table.items td {
    padding: 6px; border-bottom: 1px solid #ddd; font-size: 11pt; vertical-align: top;
  }
  table.items td.cb { width: 22px; }
  table.items td.amt { width: 90px; font-weight: 700; }
  .cb-box {
    display:inline-block; width:14px; height:14px;
    border:1.5px solid #000; border-radius:2px; background:#fff;
  }
  .empty { padding: 30px; text-align: center; color: #555; font-style: italic; }
  @media print {
    .controls { display: none; }
  }
</style>
</head>
<body>

<div class="controls">
  <strong>Print Preview</strong>
  <span>&middot; Group: <em><?= htmlspecialchars($groupFilter) ?></em></span>
  <span>&middot; <?= count($bundles) ?> list(s)</span>
  <span style="margin-left:auto;"></span>
  <button type="button" onclick="window.print()">🖨 Print</button>
  <button type="button" onclick="window.close()">Close</button>
</div>

<?php if (!$bundles): ?>
  <div class="empty" style="margin-top:40px;">
    No packing lists to print. A delivery order must be recorded for each
    client first (via the kiosk or the AI upload) — clients that haven't yet
    been processed this round are skipped.
  </div>
<?php else: ?>
  <?php foreach ($bundles as $b):
    $c     = $b['client'];
    $order = $b['order'];
    $scans = $b['scans'];
  ?>
    <div class="page">
      <div class="order-badge">ORDER #<?= (int)$order['id'] ?></div>
      <h1>Packing &amp; Delivery List</h1>
      <div class="subhead">
        <?= htmlspecialchars($today) ?> · Pack the items below for this client.
      </div>

      <div class="client-info">
        <div><span class="lbl">Name:</span><?= htmlspecialchars($c['name']) ?></div>
        <div><span class="lbl">Group:</span><?= htmlspecialchars($c['grp']) ?></div>
        <div><span class="lbl">Address:</span><?= htmlspecialchars($c['address']) ?></div>
        <div><span class="lbl">City:</span><?= htmlspecialchars($c['city']) ?></div>
        <div><span class="lbl">Phone:</span><?= htmlspecialchars($c['phone']) ?></div>
        <div><span class="lbl">Household:</span>
          <?= (int)$c['adults'] ?> adult<?= (int)$c['adults'] === 1 ? '' : 's' ?>,
          <?= (int)$c['children'] ?> child<?= (int)$c['children'] === 1 ? '' : 'ren' ?>
        </div>
      </div>

      <?php if (empty($scans)): ?>
        <div class="empty">This order has no items recorded.</div>
      <?php else: ?>
        <table class="items">
          <thead>
            <tr><th></th><th>Item</th><th>Qty / Weight</th></tr>
          </thead>
          <tbody>
            <?php foreach ($scans as $s): ?>
              <tr>
                <td class="cb"><span class="cb-box" aria-hidden="true"></span></td>
                <td><?= htmlspecialchars($s['generic_name']) ?></td>
                <td class="amt"><?= htmlspecialchars(fmtAmount($s)) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

</body>
</html>
