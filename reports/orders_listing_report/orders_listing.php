<?php
// Orders Report — lists every order in the date range and the items scanned
// against each. Defaults to today/today.
$GLOBALS['FS_PREFIX'] = '../../';
require_once __DIR__ . '/../../common.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../event/event_types.php';
requireLogin();
$db = getDB();

$defaultStart = date('Y-m-d');
$defaultEnd   = date('Y-m-d');
$dateStart = $_GET['date_start'] ?? $defaultStart;
$dateEnd   = $_GET['date_end']   ?? $defaultEnd;
// Optional generic-name filter: keep only scans whose name contains this text.
$itemFilter = trim($_GET['item'] ?? '');

// Order-type filter (multi-select). Options:
//   * "Pantry"     — unlabeled orders, i.e. the scanner-based in-pantry orders
//                    whose note doesn't carry a DELIVERY / EVENT / ORDER AHEAD prefix
//   * "Delivery"   — orders written by delivery/submit_delivery.php
//   * "OrderAhead" — orders written by orderahead/submit_orderahead.php
//   * each event type — orders written by event/submit_event.php for that type
// When nothing is selected, no type filter is applied (shows everything).
// Event types follow the current picker list (Settings → Manage Events).
// Removing an event drops it from this filter; orders of a removed type are
// still listed but show as a generic "Event" (see the pill below).
$eventTypeSet   = eventTypes();
$allOrderTypes  = array_merge(['Pantry', 'Delivery', 'OrderAhead'], $eventTypeSet);
$rawSelected    = $_GET['order_types'] ?? [];
if (!is_array($rawSelected)) $rawSelected = [];
$selectedTypes  = array_values(array_intersect($allOrderTypes, $rawSelected));

// Inclusive end-date: cover the entire selected day.
$rangeStart = $dateStart . ' 00:00:00';
$rangeEnd   = $dateEnd   . ' 23:59:59';

// Delivery orders are tagged by delivery/submit_delivery.php with a note
// starting with "DELIVERY · "; event orders by event/submit_event.php
// with "EVENT · <type> · <initials>"; OrderAhead imports by
// orderahead/submit_orderahead.php with "ORDER AHEAD · <filename>".
// Those prefixes are all the SQL needs; the event type is parsed in PHP
// further down so it can populate the per-row pill.
$deliveryNoteSql   = "o.note LIKE 'DELIVERY %'";
$eventNoteSql      = "o.note LIKE 'EVENT %'";
$orderAheadNoteSql = "o.note LIKE 'ORDER AHEAD %'";

// Translate the selected order types into an OR-ed SQL clause. Event types
// get a parameterized LIKE so the exact type label flows in as data, not
// concatenated SQL — even though every value here came from the whitelist
// above, the parameter form keeps it tidy.
$typeBinds = [':rs' => $rangeStart, ':re' => $rangeEnd];
$typeClause = '';
if ($selectedTypes) {
    $orParts = [];
    $i = 0;
    foreach ($selectedTypes as $t) {
        if ($t === 'Pantry') {
            // Pantry = "everything not labeled". Excludes every prefix
            // produced by Delivery / Event / OrderAhead so those don't
            // leak into the unlabeled bucket.
            $orParts[] = "(o.note IS NULL OR ("
                       . "o.note NOT LIKE 'DELIVERY %' AND "
                       . "o.note NOT LIKE 'EVENT %' AND "
                       . "o.note NOT LIKE 'ORDER AHEAD %'))";
        } elseif ($t === 'Delivery') {
            $orParts[] = $deliveryNoteSql;
        } elseif ($t === 'OrderAhead') {
            $orParts[] = $orderAheadNoteSql;
        } else {
            $ph = ':evt' . $i++;
            $orParts[] = "o.note LIKE $ph";
            $typeBinds[$ph] = "EVENT · {$t} · %";
        }
    }
    $typeClause = ' AND (' . implode(' OR ', $orParts) . ')';
}

// Pull orders whose start OR any scan falls inside the window so we don't miss
// long-running orders started just before the range.
$ordersStmt = $db->prepare(
    "SELECT DISTINCT o.id, o.started_at, o.ended_at, o.status, o.note,
            CASE WHEN $deliveryNoteSql   THEN 1 ELSE 0 END AS is_delivery,
            CASE WHEN $eventNoteSql      THEN 1 ELSE 0 END AS is_event,
            CASE WHEN $orderAheadNoteSql THEN 1 ELSE 0 END AS is_order_ahead
     FROM orders o
     LEFT JOIN scans s ON s.order_id = o.id
     WHERE ((o.started_at BETWEEN :rs AND :re)
         OR (s.scanned_at BETWEEN :rs AND :re))
         $typeClause
     ORDER BY o.id DESC"
);
$ordersStmt->execute($typeBinds);
$orders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);

// Pull all scans for those orders in one shot, then group in PHP.
$scansByOrder = [];
if ($orders) {
    $ids = array_column($orders, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sStmt = $db->prepare(
        "SELECT order_id, generic_name, kind, quantity, weight_lbs, barcode, scanned_at
         FROM scans WHERE order_id IN ($placeholders)
         ORDER BY scanned_at ASC"
    );
    $sStmt->execute($ids);
    foreach ($sStmt as $row) {
        if ($itemFilter !== '' && stripos($row['generic_name'], $itemFilter) === false) continue;
        $scansByOrder[$row['order_id']][] = $row;
    }
}

// With a name filter active, drop orders that have no matching scans so the
// report only shows orders containing the searched item.
if ($itemFilter !== '') {
    $orders = array_values(array_filter($orders, function ($o) use ($scansByOrder) {
        return !empty($scansByOrder[$o['id']]);
    }));
}

// Visual category for the per-scan type pill. The stored `kind` field is
// the *tracking* method (packaged = counted, produce = weighed), but a
// PLU-coded item flipped to unit='each' (e.g. apples sold per-apple) is
// stored as kind='packaged' so the scans row carries a quantity rather
// than a weight — without this helper that item would render as a brown
// PACKAGED pill in the report even though it's still produce visually.
//
// So: drive the pill from the barcode shape (the actual product
// category) when there is one. Scans written by the Event / Delivery /
// OrderAhead flows have an empty barcode, so for those fall back to a
// produce_lookup name check — "Lemons" is produce even when its unit is
// 'each' and the row was stored as kind='packaged'. Only when neither
// signal is available does the stored kind decide.
$produceNamesLc = [];
foreach ($db->query("SELECT generic_name FROM produce_lookup") as $pn) {
    $produceNamesLc[strtolower($pn['generic_name'])] = true;
}
function scanDisplayKind(array $scan, array $produceNamesLc): string {
    $code = trim((string)($scan['barcode'] ?? ''));
    if ($code !== '' && ctype_digit($code)) {
        $len = strlen($code);
        // Mirrors classifyBarcode() in lookup.php: a produce PLU is 4-5
        // digits, or the pantry-printed 12-digit label starting with 4.
        if ($len === 4 || $len === 5) return 'produce';
        if ($len === 12 && $code[0] === '4') return 'produce';
        return 'packaged';
    }
    // No barcode (event/delivery/orderahead scans): produce when the name
    // is a known produce item, regardless of how the row is unit-tracked.
    if (isset($produceNamesLc[strtolower((string)($scan['generic_name'] ?? ''))])) {
        return 'produce';
    }
    return (string)($scan['kind'] ?? 'packaged');
}

// (eventTypeFromNote() — which drives the per-order "<type> Event" pill — is
// shared from event/event_types.php.)

// Top-line totals across all listed orders.
$totalOrders            = count($orders);
$totalDeliveryOrders    = 0;
$totalEventOrders       = 0;
$totalOrderAheadOrders  = 0;
foreach ($orders as $o) {
    if ((int)$o['is_delivery']    === 1) $totalDeliveryOrders++;
    if ((int)$o['is_event']       === 1) $totalEventOrders++;
    if ((int)$o['is_order_ahead'] === 1) $totalOrderAheadOrders++;
}
$totalScans    = 0;
$totalQty      = 0;
$totalWeight   = 0.0;
$uniqueItems   = [];
foreach ($scansByOrder as $rows) {
    foreach ($rows as $r) {
        $totalScans++;
        if ($r['kind'] === 'produce') $totalWeight += (float)$r['weight_lbs'];
        else                          $totalQty    += (int)$r['quantity'];
        $uniqueItems[$r['generic_name']] = true;
    }
}

function fmtWeight(float $w): string {
    return rtrim(rtrim(number_format($w, 2, '.', ''), '0'), '.');
}

renderHead('Orders Listing Report');
renderNav('orders');
?>
<style>
  .type-pill { display:inline-block; font-size:.7rem; font-weight:700;
               text-transform:uppercase; letter-spacing:.5px; color:#fff;
               border-radius:4px; padding:2px 7px; }
  .type-pill.packaged { background: var(--brown); }
  .type-pill.produce  { background: var(--green); }
  .type-pill.delivery     { background: #2F6FA1; }
  .type-pill.event        { background: #8B5A2B; }
  .type-pill.order_ahead  { background: #5D7E2A; }
  /* Order-type multi-select: a list of checkboxes inside a bordered box
     gives a multi-select feel without the awkward native ctrl-click UX.
     Wraps responsively so the four event types + Pantry + Delivery fit
     on one row on a desktop and stack on narrow screens. */
  .order-type-box {
    flex: 1 1 100%; display: flex; flex-wrap: wrap; gap: 6px 14px;
    align-items: center; padding: 8px 12px; background: #fff;
    border: 1px solid var(--border); border-radius: 6px;
  }
  .order-type-box > .lbl {
    font-size: .75rem; font-weight: 700; color: var(--brown);
    text-transform: uppercase; letter-spacing: .5px; margin-right: 6px;
  }
  .order-type-box label {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: .9rem; text-transform: none; cursor: pointer;
    margin: 0; font-weight: 600;
  }
  .order-type-box input { width: auto; margin: 0; }
  .order-card { margin-bottom: 18px; }
  .order-head {
    display:flex; flex-wrap:wrap; gap:10px 18px; align-items:baseline;
    border-bottom:1px solid var(--border); padding-bottom:10px; margin-bottom:10px;
  }
  .order-head .num { font-size:1.4rem; font-weight:800; color:var(--brown); }
  .order-head .meta { font-size:.8rem; color:#777; }
  .order-head .stat-inline { font-size:.8rem; color:var(--brown); font-weight:700; }
  .status-pill {
    display:inline-block; font-size:.7rem; font-weight:700;
    text-transform:uppercase; letter-spacing:.5px;
    border-radius:4px; padding:2px 7px;
  }
  .status-pill.open   { background:#FFF3CD; color:#806000; border:1px solid #FFE69C; }
  .status-pill.closed { background:#D4EDDA; color:#276437; border:1px solid #A8D8B9; }
  .no-data { padding:30px; text-align:center; color:#999; font-size:.9rem; }
  @media print {
    .site-header, nav.subnav, .filter-card, .btn, form { display:none; }
    .order-card { break-inside: avoid; }
  }
</style>

<div class="container">
  <form method="GET">
    <div class="card filter-card">
      <h2>🔍 Date Range</h2>
      <div class="row">
        <div>
          <label for="date_start">Start Date</label>
          <input type="date" id="date_start" name="date_start" value="<?= htmlspecialchars($dateStart) ?>">
        </div>
        <div>
          <label for="date_end">End Date</label>
          <input type="date" id="date_end" name="date_end" value="<?= htmlspecialchars($dateEnd) ?>">
        </div>
        <div style="flex:1 1 220px;">
          <label for="item">Generic Name</label>
          <input type="search" id="item" name="item" value="<?= htmlspecialchars($itemFilter) ?>"
                 placeholder="🔍 Filter by item name…" autocomplete="off" style="width:100%;">
        </div>
      </div>
      <div class="row" style="margin-top:14px;">
        <div class="order-type-box" role="group" aria-label="Order Type">
          <span class="lbl">Order Type:</span>
          <?php foreach ($allOrderTypes as $t):
            $checked = in_array($t, $selectedTypes, true);
          ?>
            <label>
              <input type="checkbox" name="order_types[]"
                     value="<?= htmlspecialchars($t) ?>"<?= $checked ? ' checked' : '' ?>>
              <?= htmlspecialchars($t) ?>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="row" style="margin-top:14px; align-items:center;">
        <button type="submit" class="btn btn-primary" style="flex:0 0 160px;">📋 Run Report</button>
        <a href="" class="btn btn-secondary" style="flex:0 0 100px; text-align:center; text-decoration:none;">↺ Reset</a>
        <?php if ($orders): ?>
          <button type="button" class="btn btn-secondary" style="flex:0 0 100px;" onclick="window.print()">🖨 Print</button>
        <?php endif; ?>
      </div>
    </div>
  </form>

  <div class="card">
    <h2>At a Glance</h2>
    <div class="stat-grid">
      <div class="stat"><div class="v"><?= $totalOrders ?></div><div class="k">Orders</div></div>
      <div class="stat"><div class="v"><?= $totalDeliveryOrders ?></div><div class="k">Delivery Orders</div></div>
      <div class="stat"><div class="v"><?= $totalEventOrders ?></div><div class="k">Event Orders</div></div>
      <div class="stat"><div class="v"><?= $totalOrderAheadOrders ?></div><div class="k">OrderAhead Orders</div></div>
      <div class="stat"><div class="v"><?= $totalScans ?></div><div class="k">Scans</div></div>
      <div class="stat"><div class="v"><?= count($uniqueItems) ?></div><div class="k">Unique Items</div></div>
      <div class="stat"><div class="v"><?= $totalQty ?></div><div class="k">Packaged (each)</div></div>
      <div class="stat"><div class="v"><?= fmtWeight($totalWeight) ?></div><div class="k">Produce (lb)</div></div>
      <div class="stat"><div class="v" style="font-size:1rem;"><?= htmlspecialchars(date('M j', strtotime($dateStart))) ?> – <?= htmlspecialchars(date('M j', strtotime($dateEnd))) ?></div><div class="k">Date Range</div></div>
    </div>
  </div>

  <?php if (!$orders): ?>
    <div class="card"><div class="no-data">⚠ No orders or scans
      <?= $itemFilter !== '' ? 'match “' . htmlspecialchars($itemFilter) . '” in the selected date range.' : 'in the selected date range.' ?>
    </div></div>
  <?php else: ?>

    <?php foreach ($orders as $o):
      $rows  = $scansByOrder[$o['id']] ?? [];
      $oScans = count($rows);
      $oQty   = 0; $oWeight = 0.0; $oUnique = [];
      foreach ($rows as $r) {
        if ($r['kind'] === 'produce') $oWeight += (float)$r['weight_lbs'];
        else                          $oQty    += (int)$r['quantity'];
        $oUnique[$r['generic_name']] = true;
      }
    ?>
    <div class="card order-card">
      <div class="order-head">
        <div class="num">Order #<?= (int)$o['id'] ?></div>
        <span class="status-pill <?= htmlspecialchars($o['status']) ?>"><?= htmlspecialchars($o['status']) ?></span>
        <?php if ((int)$o['is_delivery'] === 1): ?>
          <span class="type-pill delivery">🚚 Delivery</span>
        <?php endif; ?>
        <?php if ((int)$o['is_event'] === 1):
          // Name the type only while it's still a current event type; a removed
          // type falls back to a generic "Event" label.
          $evType = eventTypeFromNote($o['note']);
          $evLabel = ($evType !== '' && in_array($evType, $eventTypeSet, true)) ? $evType . ' Event' : 'Event';
        ?>
          <span class="type-pill event">📋 <?= htmlspecialchars($evLabel) ?></span>
        <?php endif; ?>
        <?php if ((int)$o['is_order_ahead'] === 1): ?>
          <span class="type-pill order_ahead">📤 OrderAhead delivery</span>
        <?php endif; ?>
        <div class="meta">
          Started <?= htmlspecialchars($o['started_at']) ?>
          <?php if ($o['ended_at']): ?> · Ended <?= htmlspecialchars($o['ended_at']) ?><?php endif; ?>
        </div>
        <div class="stat-inline" style="margin-left:auto;">
          <?= $oScans ?> scans ·
          <?= count($oUnique) ?> unique ·
          <?= $oQty ?> each ·
          <?= fmtWeight($oWeight) ?> lb
        </div>
      </div>

      <?php if (!$rows): ?>
        <p style="color:#999; font-size:.85rem; padding:10px 0;">No scans recorded for this order.</p>
      <?php else: ?>
      <table class="data">
        <thead>
          <tr>
            <th>Time</th>
            <th>Generic Item</th>
            <th>Type</th>
            <th class="num">Qty</th>
            <th class="num">Lbs</th>
            <th>Barcode</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
          <tr>
            <td style="white-space:nowrap;"><?= htmlspecialchars(substr($r['scanned_at'], 11, 8)) ?></td>
            <td><?= htmlspecialchars($r['generic_name']) ?></td>
            <?php $dk = scanDisplayKind($r, $produceNamesLc); ?>
            <td><span class="type-pill <?= htmlspecialchars($dk) ?>"><?= htmlspecialchars($dk) ?></span></td>
            <td class="num"><?= $r['kind'] === 'packaged' ? (int)$r['quantity'] : '' ?></td>
            <td class="num"><?= $r['kind'] === 'produce' ? fmtWeight((float)$r['weight_lbs']) : '' ?></td>
            <td style="font-family:monospace; font-size:.8rem; color:#777;"><?= htmlspecialchars($r['barcode']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>

  <?php endif; ?>
</div>
<?php renderFoot(); ?>
