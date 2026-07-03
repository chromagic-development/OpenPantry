<?php
require_once __DIR__ . '/common.php';
requireLogin();
$db = getDB();

// Show every in-progress order across all scanning stations (not just this
// device's), so the dashboard is a true overview now that stations can each
// have their own open order.
$openOrders    = allOpenOrders();
$totalOrders   = (int)$db->query("SELECT COUNT(*) FROM orders")->fetchColumn();
$totalScans    = (int)$db->query("SELECT COUNT(*) FROM scans")->fetchColumn();
$cachedUpcs    = (int)$db->query("SELECT COUNT(*) FROM upc_lookup")->fetchColumn();
$produceCount  = (int)$db->query("SELECT COUNT(*) FROM produce_lookup")->fetchColumn();

$recent = $db->query(
    "SELECT o.id, o.started_at, o.ended_at, o.status,
            COUNT(s.id) scans, COUNT(DISTINCT s.generic_name) uniq
     FROM orders o
     LEFT JOIN scans s ON s.order_id = o.id
     GROUP BY o.id ORDER BY o.id DESC LIMIT 12"
)->fetchAll();

renderHead('Dashboard');
renderNav('index');
?>
<div class="container">

<?php if ($openOrders): ?>
  <div class="banner warn">
    <div style="font-size:1.4rem;">⏺</div>
    <div>
      <?php if (count($openOrders) === 1): $o = $openOrders[0]; ?>
        Order <strong>#<?= (int)$o['id'] ?></strong> is currently open
        (started <?= htmlspecialchars($o['started_at']) ?>).
        Continue scanning, or <a href="scan/" target="_blank" rel="noopener">end the order</a> when complete.
      <?php else: ?>
        <strong><?= count($openOrders) ?> orders</strong> are currently open across scanning stations:
        <?php
          $labels = array_map(function ($o) {
              return '#' . (int)$o['id'] . ' (started ' . htmlspecialchars($o['started_at']) . ')';
          }, $openOrders);
          echo implode(', ', $labels);
        ?>. Each station ends its own order from its <a href="scan/" target="_blank" rel="noopener">Scan page</a>.
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

  <div class="card">
    <h2>At a Glance</h2>
    <div class="stat-grid">
      <div class="stat"><div class="v"><?= $totalOrders ?></div><div class="k">Orders Tracked</div></div>
      <div class="stat"><div class="v"><?= $totalScans ?></div><div class="k">Total Scans</div></div>
      <div class="stat"><div class="v"><?= $cachedUpcs ?></div><div class="k">Cached UPCs</div></div>
      <div class="stat"><div class="v"><?= $produceCount ?></div><div class="k">Produce Codes</div></div>
    </div>
  </div>

  <div class="card">
    <h2>Quick Actions</h2>
    <div class="row">
      <a class="btn btn-primary" href="scan/" target="_blank" rel="noopener" style="text-decoration:none; text-align:center;">📷 Laser Scanner</a>
      <a class="btn btn-secondary" href="menucounter/admin/admin.php" target="_blank" rel="noopener" style="text-decoration:none; text-align:center;">🧮 Menu Counter</a>
      <a class="btn btn-secondary" href="restock/index.php" style="text-decoration:none; text-align:center;">📦 Restock</a>
      <a class="btn btn-secondary" href="reports/order_report/" style="text-decoration:none; text-align:center;">📊 Order Report</a>
    </div>
  </div>

  <div class="card">
    <h2>Recent Orders</h2>
    <?php if (!$recent): ?>
      <p style="color:#777;">No orders yet. Open the Scan page to start one.</p>
    <?php else: ?>
    <table class="data">
      <thead><tr>
        <th>#</th><th>Status</th><th>Started</th><th>Ended</th>
        <th class="num">Scans</th><th class="num">Unique</th>
      </tr></thead>
      <tbody>
      <?php foreach ($recent as $r): ?>
        <tr>
          <td><strong>#<?= (int)$r['id'] ?></strong></td>
          <td><?= htmlspecialchars($r['status']) ?></td>
          <td><?= htmlspecialchars($r['started_at']) ?></td>
          <td><?= htmlspecialchars($r['ended_at'] ?? '—') ?></td>
          <td class="num"><?= (int)$r['scans'] ?></td>
          <td class="num"><?= (int)$r['uniq'] ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

</div>
<?php renderFoot(); ?>
