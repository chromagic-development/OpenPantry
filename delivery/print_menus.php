<?php
// Printable per-client delivery menus, one page per pending client in the
// selected group. Each available item appears with a hand-markable checkbox
// and a parenthesized Qty / Weight — the amount this client's household
// would receive, computed with the same rules the packing list uses;
// completed sheets are later scanned and read back by vision AI (the
// "CLIENT #N" badge in the top-right is the OCR-friendly identifier, and
// process_upload.php's prompts tell the model to ignore the qty suffix).
//
// Filter: ?group=K-1|K-2|E-1|E-2|all  (default: all)
// Scope:  enabled clients with delivered_at IS NULL — i.e. clients that
//         still need a delivery this week. Already-delivered ("would need a
//         reset") clients are skipped.
//
// NOTE: address / city are intentionally NOT pulled or rendered — these
// sheets travel with each delivery and we don't want unnecessary PII
// circulating on paper. Phone is included so the volunteer can call the
// client about the order; client id (CLIENT #N badge), first name, group,
// and household size also make it onto the page.
$GLOBALS['FS_PREFIX'] = '../';
require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/db.php';
requireLogin();

$db  = getDB();
$pdb = picklistDB();

$validGroups = deliveryGroups();
$group       = (string)($_GET['group'] ?? 'all');
$groupFilter = in_array($group, $validGroups, true) ? $group : 'all';

// Pending clients, optionally narrowed to one group.
// Address / city deliberately NOT selected — the printed form is meant to
// expose only the client identifier + household + group + phone, so staff
// at the pantry can't accidentally circulate personal details on a
// worksheet that travels with each delivery.
$sql = "SELECT id, name, phone, adults, children, grp, volunteer
          FROM delivery_clients
         WHERE enabled = 1 AND delivered_at IS NULL";
$params = [];
if ($groupFilter !== 'all') {
    $sql .= " AND grp = ?";
    $params[] = $groupFilter;
}
// Match the order of the Clients section on client.php (the drag-and-drop
// order), rather than clustering by group, so the printed menus come out in
// the same sequence staff see in the list.
$sql .= " ORDER BY sort_order, id";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$clients = array_map('fsDecryptClientFields', $stmt->fetchAll());

// Today's menu — same in-stock list the delivery kiosk uses. Flatten
// has_detail items into one checkbox row per in-stock size variant
// ("Butter Salted", "Butter Unsalted") so each row is a single tickbox.
// Carry the kids-only flag through so per-client rendering can hide those
// rows when the client has 0 children (mirrors the index.php JS that hides
// data-kids-only items when the children selector is 0).
$menu     = buildDeliveryItems($db, $pdb);
$catOrder = deliveryCategoryOrder($menu);
$rows = [];
foreach ($catOrder as $cat) {
    if (empty($menu[$cat])) continue;
    foreach ($menu[$cat] as $it) {
        $kidsOnly = !empty($it['has_factor'])
                 && !empty($it['use_children'])
                 && empty($it['use_adults']);
        if (!empty($it['has_detail'])) {
            foreach ($it['sizes'] as $sz) {
                $rows[$cat][] = ['name' => $it['name'] . ' ' . $sz, 'kids_only' => $kidsOnly, 'item' => $it];
            }
        } else {
            $rows[$cat][] = ['name' => $it['name'], 'kids_only' => $kidsOnly, 'item' => $it];
        }
    }
}

// The amount this client would receive for an item, printed in parentheses
// after the item name. Mirrors the household clamps + familySize formula in
// persistDeliveryOrder() and the Qty / Weight formatting in
// print_packing_lists.php (fmtAmount), so the order form and the packing
// list always show the same numbers.
function fmtPlannedAmount(array $item, int $adults, int $children): string {
    $adults   = max(1, $adults);
    $children = max(0, $children);
    $familySize = min($adults + ($children / 2), 5);
    if ($familySize < 1) $familySize = 1;
    $qd = deliveryItemQuantity($item, $adults, $children, $familySize);
    if (!empty($qd['by_weight'])) {
        $w = rtrim(rtrim(number_format((float)$qd['weight_lbs'], 2, '.', ''), '0'), '.');
        return $w . ' lb';
    }
    return (int)$qd['quantity'] . ' each';
}

$today = date('M j, Y');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<link rel="icon" type="image/x-icon" href="../menucounter/favicon.ico">
<title>Delivery Menus — <?= htmlspecialchars($groupFilter) ?> — <?= htmlspecialchars($today) ?></title>
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
  .id-badge {
    float: right; border: 2px solid #000; padding: 6px 12px;
    font-size: 14pt; font-weight: 800; font-family: 'Courier New', monospace;
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
  .cat { margin-top: 12px; }
  .cat h2 {
    font-size: 11pt; border-bottom: 1px solid #000; padding-bottom: 2px;
    margin: 8px 0 6px;
  }
  .items {
    display: grid; grid-template-columns: repeat(3, 1fr);
    gap: 4px 14px; font-size: 10.5pt;
  }
  .item { display: flex; align-items: center; gap: 8px; line-height: 1.6; }
  .item .qty { font-weight: 700; white-space: nowrap; }
  .qty-note { margin-top: 16px; font-weight: 700; font-size: 10.5pt; }
  .cb {
    display: inline-block; width: 14px; height: 14px; flex: 0 0 14px;
    border: 1.5px solid #000; border-radius: 2px; background: #fff;
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
  <span>&middot; <?= count($clients) ?> client(s)</span>
  <span style="margin-left:auto;"></span>
  <button type="button" onclick="window.print()">🖨 Print</button>
  <button type="button" onclick="window.close()">Close</button>
</div>

<?php if (!$clients): ?>
  <div class="empty" style="margin-top:40px;">
    No pending clients to print in group <strong><?= htmlspecialchars($groupFilter) ?></strong>.
    (Clients already marked delivered this week are skipped.)
  </div>
<?php else: ?>
  <?php foreach ($clients as $c): ?>
    <div class="page">
      <div class="id-badge">CLIENT #<?= (int)$c['id'] ?></div>
      <h1>Delivery Order Form</h1>
      <div class="subhead">
        <?= htmlspecialchars($today) ?> · Please mark each item you would like.
      </div>

      <div class="client-info">
        <div><span class="lbl">Name:</span><?= htmlspecialchars($c['name']) ?></div>
        <div><span class="lbl">Group:</span><?= htmlspecialchars($c['grp']) ?></div>
        <div><span class="lbl">Phone:</span><?= htmlspecialchars($c['phone']) ?></div>
        <div><span class="lbl">Volunteer:</span><?= htmlspecialchars($c['volunteer'] ?? '') ?></div>
        <div><span class="lbl">Household:</span>
          <?= (int)$c['adults'] ?> adult<?= (int)$c['adults'] === 1 ? '' : 's' ?>,
          <?= (int)$c['children'] ?> child<?= (int)$c['children'] === 1 ? '' : 'ren' ?>
        </div>
      </div>

      <?php
        // Per-client visibility pass: a client with no children doesn't see
        // kids-only items (e.g. kids snacks, diapers). Build a filtered view
        // here so empty categories disappear cleanly too.
        $hasKids  = (int)$c['children'] > 0;
        $adults   = (int)$c['adults'];
        $children = (int)$c['children'];
        $visibleRows = [];
        foreach ($rows as $cat => $items) {
            $kept = [];
            foreach ($items as $r) {
                if (!$hasKids && !empty($r['kids_only'])) continue;
                $kept[] = $r;
            }
            if ($kept) $visibleRows[$cat] = $kept;
        }
      ?>
      <?php if (empty($visibleRows)): ?>
        <div class="empty">No items currently in stock to offer.</div>
      <?php else: ?>
        <?php foreach ($visibleRows as $cat => $items): ?>
          <div class="cat">
            <h2><?= htmlspecialchars($cat) ?></h2>
            <div class="items">
              <?php foreach ($items as $r): ?>
                <div class="item">
                  <span class="cb" aria-hidden="true"></span>
                  <span><?= htmlspecialchars($r['name']) ?>
                    <span class="qty">(<?= htmlspecialchars(fmtPlannedAmount($r['item'], $adults, $children)) ?>)</span></span>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
        <div class="qty-note">
          Please write any specific Qty / Weight requests by a client next to the item defaults.
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

</body>
</html>
