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

// Avg Wt (lb ea) of every lb-unit item that has one, by lowercased name: the
// scan rows carry the menu's spelling of the name, which only matches the
// inventory row case-insensitively. Items missing from this map print their
// weight alone.
$lbPerEach = [];
foreach ($db->query("SELECT generic_name, lb_per_each FROM inventory
                      WHERE unit = 'lb' AND lb_per_each > 0") as $r) {
    $lbPerEach[strtolower($r['generic_name'])] = (float)$r['lb_per_each'];
}

function fmtAmount(array $s, array $lbPerEach): string {
    if (($s['kind'] ?? '') === 'produce' && $s['weight_lbs'] !== null) {
        return deliveryWeightLabel((float)$s['weight_lbs'],
                                   $lbPerEach[strtolower($s['generic_name'])] ?? 0.0,
                                   true); // "9 each or 3 lb"
    }
    return (int)$s['quantity'] . ' each';
}

// The most item rows one column holds on a Letter sheet at full size. A longer
// order prints in two columns (down the left, then the right), which keeps a
// full menu's worth of items on one sheet without shrinking the type; past
// about 48 items fitSheet() zooms the sheet down as well.
const ONE_COLUMN_MAX = 24;

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
  /* Breaks go before each sheet rather than after, so a hidden label sheet
     following the last list can't leave a trailing blank page. */
  .page, .label-page { break-before: page; page-break-before: always; }
  .controls + .page { break-before: auto; page-break-before: auto; }
  /* Each list is one sheet of Letter, portrait. The sheet is laid out at the
     printable width (8.5in less the margins) on screen as well, so the preview
     looks like the paper and fitSheet() measures exactly what will print. --z
     is the zoom fitSheet() picks; the width grows by 1/zoom so a shrunken
     sheet still spans the page, and the white "margin" drawn around it on
     screen keeps its printed size. */
  @page { size: letter portrait; margin: 0.5in; }
  .page {
    --z: 1; zoom: var(--z);
    width: calc(7.5in / var(--z)); box-sizing: border-box;
    margin: 0.7in auto; background: #fff;
    box-shadow: 0 0 0 calc(0.5in / var(--z)) #fff,
                0 0 0 calc(0.5in / var(--z) + 1px) #bbb;
  }
  @media screen { body { background: #e6e6e6; } }
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
  /* A long order splits into two side-by-side tables (see ONE_COLUMN_MAX). */
  .items-cols { display: flex; align-items: flex-start; gap: 0.3in; }
  .items-cols table.items { flex: 1 1 0; min-width: 0; }
  table.items th {
    text-align: left; font-size: 9pt; text-transform: uppercase;
    color: #555; border-bottom: 1px solid #000; padding: 4px 6px;
  }
  table.items td {
    padding: 6px; border-bottom: 1px solid #ddd; font-size: 11pt; vertical-align: top;
  }
  table.items td.cb { width: 22px; }
  /* Wide enough for "38 each or 12.5 lb" on one line. */
  table.items td.amt { width: 150px; font-weight: 700; white-space: nowrap; }
  .cb-box {
    display:inline-block; width:14px; height:14px;
    border:1.5px solid #000; border-radius:2px; background:#fff;
  }
  .empty { padding: 30px; text-align: center; color: #555; font-style: italic; }
  /* Address label sheet: four identical labels in a 2x2 grid, cut apart after
     printing. Each label is the group, very large in a rectangle, over the
     client's name and address. Printed only by "Print with address labels" (body.with-labels).
     Its own page margins plus a fixed size in inches (fits Letter and A4) keep
     it on one sheet, and make the on-screen layout that fitLabels() measures
     the same as the printed one. */
  @page labels { margin: 0.4in; }
  .label-page {
    display: none; page: labels;
    width: 7.4in; height: 10in; margin: 0 auto; background: #fff;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    grid-template-rows: repeat(2, minmax(0, 1fr));
    gap: 0.4in;
  }
  body.with-labels .label-page { display: grid; }
  .label {
    display: flex; flex-direction: column; justify-content: center;
    gap: 0.15in; overflow: hidden;
    text-align: center; line-height: 1.2; overflow-wrap: break-word;
  }
  /* The rectangle hugs the group, about one font size tall. */
  .label .lbl-grp {
    align-self: center; max-width: 100%; box-sizing: border-box;
    overflow: hidden; padding: 0.1em 0.3em; line-height: 1;
    border: 3px solid #000; border-radius: 8px;
    font-size: 48pt; font-weight: 800; white-space: nowrap;
    /* fitLabels() shrinks the font for a group name too wide to fit */
  }
  .label .lbl-name { font-size: 16pt; font-weight: 700; }
  .label .lbl-addr { font-size: 16pt; }
  @media print {
    .controls { display: none; }
    .page { margin: 0; box-shadow: none; }
  }
</style>
</head>
<body>

<div class="controls">
  <strong>Print Preview</strong>
  <span>&middot; Group: <em><?= htmlspecialchars($groupFilter) ?></em></span>
  <span>&middot; <?= count($bundles) ?> list(s)</span>
  <span style="margin-left:auto;"></span>
  <button type="button" onclick="printWithLabels()">🏷 Print with address labels</button>
  <button type="button" onclick="window.print()">🖨 Print</button>
  <button type="button" onclick="window.close()">Close</button>
</div>
<script>
  // Each list is followed by a label sheet that only prints in this mode;
  // afterprint (fired on cancel too) puts the plain Print button back to
  // lists only.
  function printWithLabels() {
    document.body.classList.add('with-labels');
    fitLabels();
    window.print();
  }
  // Step each group's font down from its 48pt start (3x the name) until a
  // long group name fits the label width (16pt floor, the name size).
  function fitLabels() {
    document.querySelectorAll('.lbl-grp').forEach(function (box) {
      for (var pt = 48; pt > 16 && box.scrollWidth > box.clientWidth; pt -= 2) {
        box.style.fontSize = (pt - 2) + 'pt';
      }
    });
  }
  window.addEventListener('afterprint', function () {
    document.body.classList.remove('with-labels');
  });
</script>

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
      <?php else:
        $cols = count($scans) > ONE_COLUMN_MAX
              ? array_chunk($scans, (int)ceil(count($scans) / 2))
              : [$scans];
      ?>
        <div class="items-cols">
        <?php foreach ($cols as $col): ?>
          <table class="items">
            <thead>
              <tr><th></th><th>Item</th><th>Qty / Weight</th></tr>
            </thead>
            <tbody>
              <?php foreach ($col as $s): ?>
                <tr>
                  <td class="cb"><span class="cb-box" aria-hidden="true"></span></td>
                  <td><?= htmlspecialchars($s['generic_name']) ?></td>
                  <td class="amt"><?= htmlspecialchars(fmtAmount($s, $lbPerEach)) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="label-page">
      <?php for ($i = 0; $i < 4; $i++): ?>
        <div class="label">
          <div class="lbl-grp"><?= htmlspecialchars($c['grp']) ?></div>
          <div class="lbl-name"><?= htmlspecialchars($c['name']) ?></div>
          <div class="lbl-addr">
            <?= htmlspecialchars($c['address']) ?>
            <?php if ((string)$c['city'] !== ''): ?><br><?= htmlspecialchars($c['city']) ?><?php endif; ?>
          </div>
        </div>
      <?php endfor; ?>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<script>
  // Zoom each list down just enough to fit one Letter page (10in tall inside
  // the 0.5in margins, less a little for rounding). Zoom shrinks type, boxes and
  // padding together. Below 60% the items get too small to pack from, so a very
  // long order stops there and continues on a second sheet.
  var SHEET_H = 9.95 * 96, MIN_Z = 0.6;
  function fitSheet(p) {
    function height(z) {
      p.style.setProperty('--z', z);
      return p.getBoundingClientRect().height;
    }
    if (height(1) <= SHEET_H) return;
    // Binary-search the largest zoom that fits. A straight SHEET_H / height
    // ratio isn't enough: Chrome rounds zoomed sizes up a little, so a sheet
    // shrinks less than its zoom.
    var lo = MIN_Z, hi = 1;
    for (var i = 0; i < 8; i++) {
      var mid = (lo + hi) / 2;
      if (height(mid) <= SHEET_H) lo = mid; else hi = mid;
    }
    height(lo);
  }
  function fitSheets() { document.querySelectorAll('.page').forEach(fitSheet); }
  fitSheets();
  window.addEventListener('beforeprint', fitSheets);
</script>

</body>
</html>
