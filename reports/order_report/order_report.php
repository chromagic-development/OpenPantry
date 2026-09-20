<?php
// Order Report.
//
// For each generic_name we've ever scanned, the order recommendation is built
// from a per-item demand forecast over the lead time:
//
//   Par Level         = Forecast(LT) + SafetyStock
//   Safety Stock      = Z * √Variance(LT)
//   Total Order Req.  = max(0, Par Level - Latest Inventory Count)
//
// Forecast(LT) and Variance(LT) come from a quasi-Poisson GLM fitted to the
// item's full weekly scan history (trend + Fourier seasonality), implemented
// in forecast.php. That single model replaces the old hand-rolled seasonality
// (S) and growth (G) ratio multipliers — they're now read back out of the fit
// as interpretable factors for the table:
//   S = Forecast(LT) / deseasonalized baseline over the same window
//   G = exp(trend) — the model's annual growth multiplier
// and the safety stock is driven by the model's own predictive variance
// (dispersion φ), so trend, seasonality, and uncertainty are all consistent.
//
// Items without enough history to fit (new installs, brand-new items) fall
// back to the original trailing-average method:
//   Par Level = ADV * LT + Z * σ * √LT,  with S = G = 1
// where ADV is the mean daily demand over the last `velocity_window` days
// (0-filled, population stats), denominated per calendar day so it lines up
// with LT and days_left. σ is the stdev over the pantry's *distribution days
// only* — day-of-week schedule inferred from the scan history by op_open_dow()
// — then rescaled by √(openDays(LT)/LT) so the identity above still holds. A
// closed Saturday is schedule rather than demand noise, and counting its zero
// as volatility inflated safety stock badly (>5x on steady produce). A pantry
// that distributes 7 days a week is unaffected. LT = user-selectable Lead Time;
// Z = `safety_z` (1.65 ≈ 95% confidence).
//
// All of the above is denominated in the unit the pantry stocks and scans the
// item in. The vendor may quote its case in the other one — loose avocados are
// weighed in lb but bought as a 48-count case — so the Order Request crosses
// over to inventory.order_unit for the case count and the order sheet, then
// back again for Restock Now, using inventory.lb_per_each. Those conversions
// all happen in report_lib.php; the table below stays in pantry units.
//
// The Order Request column is an editable numeric field on every row, holding
// the recommendation rounded exactly as the column used to print it (blank when
// there is nothing to order, which includes every item the pantry has never
// scanned — those have no forecast at all). A recommendation is a starting
// point: the operator can raise it, cut it, or type one in from nothing, and
// whatever the field holds is what the email, the print sheet and Restock Now
// carry. That makes the field the single source of truth for the order, so the
// JS at the foot of this file — not the server — runs report_lib.php's
// conversion chain (stock unit → order unit → whole cases → cu ft, and back to
// the stock unit for the restock) over the current value, and repaints the ≈
// line and Case Request as it changes. The consequence worth knowing: the
// figure on screen is the figure ordered, with no full-precision value working
// invisibly behind it.

$GLOBALS['FS_PREFIX'] = '../../';
require_once __DIR__ . '/../../common.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/report_lib.php'; // shared row + alert computation
requireLogin();
$db = getDB();

// AJAX ping fired when the Generate Email button is clicked, so the "last
// ordered" stamp under the order buttons survives reloads. Handled before
// any output.
if (($_POST['action'] ?? '') === 'record_email_order') {
    setSetting('report_last_email_order', now());
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'at' => now()]);
    exit;
}
$lastEmailOrder = setting('report_last_email_order', '') ?? '';
$lastEmailOrderTxt = $lastEmailOrder !== ''
    ? date('M j, Y g:i A', strtotime($lastEmailOrder))
    : '';

// Food pantry name (Settings → Food Pantry Information) is appended to the
// order-email subject as "New Order from <name>".
$pantryName    = setting('food_pantry_name', '') ?? '';
$orderSubject  = $pantryName !== '' ? 'New Order from ' . $pantryName : 'New Order';

$leadTime  = max(1, (int)($_GET['lead_time'] ?? (int)setting('default_lead_time', '14')));
$velWindow = max(7, (int)setting('velocity_window', '30'));
$z         = (float)setting('safety_z', '1.65');

// Checkbox parameters, persisted via settings so the choices survive
// reloads / navigation. A hidden "submitted" field marks a real form
// submission, since an unchecked checkbox sends nothing over GET.
//   * Ignore In Stock (default off): order recommendations ignore the
//     current inventory count (Par Level becomes the Order Request).
//   * Ignore Events (default ON): scans belonging to EVENT-tagged orders
//     are excluded from the velocity window, so food consumed by events
//     doesn't inflate Avg Daily / par levels for regular pantry demand.
//   * Produce Only (default off): recommendations list only produce-kind
//     items, and the Generate Email button (supplier produce order)
//     becomes available.
//   * Purchased Only (default off): recommendations list only items that
//     have a non-zero Purchased value on the Inventory page (i.e. some of
//     their restocked amount was purchased rather than donated).
// Independently of the filters, a row starts with its Restock box checked when
// the item is one of the Reorder reminders and has a non-zero Order Request —
// the alerts are what the page is asking be ordered, so they arrive ticked.
if (isset($_GET['submitted'])) {
    $ignoreStock   = isset($_GET['ignore_stock']);
    $ignoreEvents  = isset($_GET['ignore_events']);
    $produceOnly   = isset($_GET['produce_only']);
    $purchasedOnly = isset($_GET['purchased_only']);
    setSetting('report_ignore_stock',   $ignoreStock   ? '1' : '0');
    setSetting('report_ignore_events',  $ignoreEvents  ? '1' : '0');
    setSetting('report_produce_only',   $produceOnly   ? '1' : '0');
    setSetting('report_purchased_only', $purchasedOnly ? '1' : '0');
} else {
    $ignoreStock   = (setting('report_ignore_stock', '0') === '1');
    $ignoreEvents  = (setting('report_ignore_events', '1') === '1');
    $produceOnly   = (setting('report_produce_only', '0') === '1');
    $purchasedOnly = (setting('report_purchased_only', '0') === '1');
}

// Build the report rows (per-item demand model, par levels, order requests)
// and evaluate the reorder alerts. Both live in report_lib.php so this page
// and the cron mailer (cron_reorder_alerts.php) compute identical results.
// The "Generate Email" / "Restock Now" payloads are built client-side from
// whichever per-row "Restock" checkboxes are ticked.
$rows = op_report_rows($db, [
    'lead_time'       => $leadTime,
    'velocity_window' => $velWindow,
    'z'               => $z,
    'ignore_stock'    => $ignoreStock,
    'ignore_events'   => $ignoreEvents,
    'produce_only'    => $produceOnly,
    'purchased_only'  => $purchasedOnly,
    // Always on for the page. The report is built from scan history, so an item
    // the pantry has never scanned would otherwise have no row at all and no way
    // to be ordered from here. It carries no forecast either way — it simply
    // appears with an empty Order Request to type into. The cron mailer doesn't
    // pass this and keeps the pure forecast: an item with no history can never
    // trigger a reorder alert, so listing it there would be noise.
    'include_unscanned' => true,
]);

// Storage capacity. The cubic feet an order needs are summed client-side from
// the ticked rows (they change with every click), but what's already accounted
// for doesn't depend on the checkboxes, so it's totalled here — over every
// listed row with a Cu Ft/Case, not just the ticked ones, because stock of an item
// you're *not* reordering still occupies the same floor.
//
// "Already accounted for" is deliberately not "already on the shelf": Restock
// Now is clicked when an order is placed rather than when it arrives, so an
// Inventory count is an inventory *position* — shelf plus everything in
// transit. That is the right basis here, since all of it has to fit in the same
// room by the time this order lands. It also means the total never subtracts
// the demand served between now and delivery, so it reads slightly high, which
// is the safe direction for a capacity warning.
$maxCrates   = max(0, (int)setting('max_storage_crates', '0'));
$stockCrates = 0.0;
$anyCrates   = false;
foreach ($rows as $r) {
    if (empty($r['has_crates'])) continue;
    $anyCrates    = true;
    $stockCrates += (float)$r['stock_crates'];
}

// Reorder reminders: one HTML line per triggered alert, same wording the
// cron mailer uses (built from the structured entries report_lib returns).
$alertEntries = op_report_alerts($db, $rows);
// Grouped by unit, biggest order first within each group. The list is read as a
// shopping priority, so the line that needs the most food should lead — but 176
// each and 219.6 lb are not comparable quantities, and interleaving them reads
// as a ranking that isn't one. So the units are kept apart, and each group is
// ordered by its own largest request, which puts the single biggest line at the
// top of the list as before and keeps its unit-mates under it. Sorted on the raw
// request rather than the rounded text so two similar figures still order
// correctly.
$unitRank = [];
foreach ($alertEntries as $a) {
    $u = $a['unit'];
    $unitRank[$u] = max($unitRank[$u] ?? 0.0, (float)$a['order']);
}
usort($alertEntries, function ($x, $y) use ($unitRank) {
    if ($x['unit'] !== $y['unit']) {
        // Tie between two units (identical largest requests) falls back to the
        // unit name, so the order is stable rather than whatever usort picks.
        return ($unitRank[$y['unit']] <=> $unitRank[$x['unit']])
            ?: strcmp($x['unit'], $y['unit']);
    }
    return ((float)$y['order']) <=> ((float)$x['order']);
});

$alerts = [];
// Names of the alerted items that actually have something to order. The Restock
// column starts ticked on exactly these rows (see the checkbox below).
$alertOrderNames = [];
foreach ($alertEntries as $a) {
    $alerts[] = "<strong>" . htmlspecialchars($a['name']) . "</strong>: only "
        . $a['days_text'] . " days of stock — order at least "
        . $a['order_text'] . " " . htmlspecialchars($a['unit']);
    if ((float)$a['order'] > 0) $alertOrderNames[$a['name']] = true;
}

renderHead('Order Now Report');
renderNav('report');
?>
<style>
  /* The 13-column recommendations table is wider than the card. Keep it inside
     the card by scrolling horizontally on narrow screens, and shrink the cell
     padding + font (vs the base `.data` styles) so it fits without scroll on a
     typical desktop. Selectors use `table#repTable` to beat `.data`. */
  .rep-table-wrap { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
  table#repTable th, table#repTable td { padding: 6px 7px; font-size: .8rem; }
  table#repTable th { font-size: .68rem; }
  /* A single bordered box around all four parameter checkboxes (styled like
     the "Purchased" toggle on the Restock page), with the toggles laid out in
     two columns inside it. */
  .rep-toggle-group {
    display: flex; gap: 6px 18px; flex-wrap: wrap;
    padding: 8px 12px; background: #fff; border: 1px solid var(--border);
    border-radius: 8px;
  }
  .rep-toggle-col { display: flex; flex-direction: column; gap: 6px; }
  .rep-toggle {
    display: flex; align-items: center; gap: 8px; margin: 0;
    cursor: pointer; font-weight: 700; color: var(--brown);
    font-size: .85rem; text-transform: none; white-space: nowrap;
  }
  .rep-toggle input { width: auto; margin: 0; }
  /* Editable Order Request. The base rules make every number input full-width
     at 1rem, which would blow the column open, so the in-table field is sized
     to the digits it holds and the spinner arrows (~15px of nothing) are
     dropped, matching the Inventory table's number fields. */
  table#repTable input.order-qty {
    width: 4.4em; padding: 4px 5px; font-size: .8rem;
    text-align: right; border-width: 1px; font-weight: 800;
    /* Number inputs don't inherit the cell colour, so the red the column used
       to print a request in is set here — a quantity to order still reads as
       one whether the forecast asked for it or the operator did. */
    color: var(--red);
  }
  /* Empty box = nothing to order, and greys back to how a 0 used to look. */
  table#repTable input.order-qty:placeholder-shown { color: #999; font-weight: 700; }
  /* Overridden rows: the figure is still the request, but it is no longer the
     forecast's, and that's worth seeing before the order goes out. */
  table#repTable input.order-qty.edited {
    border-color: var(--blue); background: #f2f8ff;
  }
  table#repTable input.order-qty::-webkit-outer-spin-button,
  table#repTable input.order-qty::-webkit-inner-spin-button {
    -webkit-appearance: none; margin: 0;
  }
  table#repTable input.order-qty { -moz-appearance: textfield; }
  /* Live "≈ 6 count" line under the field — same look as the computed rows'
     order-unit note it sits in place of. */
  table#repTable .order-conv { font-weight: 400; font-size: .75rem; color: #777; }
  @media print {
    /* The order sheet comes from Print Order; a printed copy of the report
       itself should show what was typed, not an empty box. */
    table#repTable input.order-qty { border: none; padding: 0; background: none; }
    .site-header, nav.subnav, .btn, form, .no-print { display:none; }
    .rep-table-wrap { overflow-x: visible; }
  }
</style>
<div class="container">

  <?php if ($alerts): ?>
    <div class="banner warn">
      <div style="font-size:1.4rem;">⏰</div>
      <div>
        <strong>Reorder reminders:</strong>
        <ul style="margin:6px 0 0 18px;">
          <?php foreach ($alerts as $a) echo "<li>$a</li>"; ?>
        </ul>
      </div>
    </div>
  <?php endif; ?>

  <div class="card no-print">
    <h2>Parameters</h2>
    <form method="get" class="row">
      <input type="hidden" name="submitted" value="1">
      <div>
        <label for="lt">Lead Time (days)</label>
        <input type="number" id="lt" name="lead_time" min="1" max="365" value="<?= $leadTime ?>">
      </div>
      <div>
        <label>Velocity Window</label>
        <input type="text" value="<?= $velWindow ?> days" disabled>
      </div>
      <div>
        <label>Confidence Z</label>
        <input type="text" value="<?= htmlspecialchars((string)$z) ?>" disabled>
      </div>
      <!-- All four parameter checkboxes share one bordered box, laid out in
           two columns (Ignore In Stock / Ignore Events, then Purchased Only /
           Produce Only). -->
      <div class="rep-toggle-group" style="flex:0 0 auto;">
        <div class="rep-toggle-col">
          <label class="rep-toggle">
            <input type="checkbox" name="ignore_stock" value="1" <?= $ignoreStock ? 'checked' : '' ?>>
            Ignore In Stock
          </label>
          <label class="rep-toggle"
                 title="Exclude items consumed by Events from the Avg Daily calculation">
            <input type="checkbox" name="ignore_events" value="1" <?= $ignoreEvents ? 'checked' : '' ?>>
            Ignore Events
          </label>
        </div>
        <div class="rep-toggle-col">
          <label class="rep-toggle"
                 title="List only items with a non-zero Purchased value on the Inventory page">
            <input type="checkbox" name="purchased_only" value="1" <?= $purchasedOnly ? 'checked' : '' ?>>
            Purchased Only
          </label>
          <label class="rep-toggle"
                 title="List only produce items">
            <input type="checkbox" name="produce_only" value="1" <?= $produceOnly ? 'checked' : '' ?>>
            Produce Only
          </label>
        </div>
      </div>
      <!-- Always-visible order actions plus Recalculate, all on one row. -->
      <div style="flex:0 0 auto; display:flex; align-items:center; gap:8px;">
        <div style="display:flex; flex-direction:column;">
          <label>&nbsp;</label>
          <div style="display:flex; gap:4px;">
            <button type="submit" class="btn btn-primary" style="white-space:nowrap;">Recalculate</button>
            <button type="button" class="btn btn-secondary" style="white-space:nowrap;"
                    onclick="generateOrderEmail()"
                    title="Open a Gmail draft listing the checked items">✉ Email Order</button>
            <button type="button" class="btn btn-secondary" style="white-space:nowrap;"
                    onclick="printOrder()"
                    title="Print an order sheet listing the checked items">🖨 Print Order</button>
            <button type="button" class="btn btn-secondary" style="white-space:nowrap;"
                    onclick="restockNow()"
                    title="Add the checked items' ordered quantities to inventory">📦 Restock Now</button>
            <?php if ($rows): ?>
            <button type="button" class="btn btn-secondary" style="white-space:nowrap;"
                    onclick="window.print()">🖨 Print</button>
            <?php endif; ?>
          </div>
        </div>
        <!-- Timestamp of the most recent Email Order click (persisted).
             Label sits above the date so the stamp stays narrow and the
             order buttons keep to a single row. -->
        <div id="lastOrderStamp" style="font-size:.72rem; color:#777; line-height:1.3;">
          <?php if ($lastEmailOrderTxt !== ''): ?>
            <div>Last email order:</div>
            <div id="lastOrderDate" style="white-space:nowrap;"><?= htmlspecialchars($lastEmailOrderTxt) ?></div>
          <?php else: ?>
            <div id="lastOrderDate">No email order yet</div>
          <?php endif; ?>
        </div>
      </div>
    </form>
    <p style="color:#777; font-size:.8rem; margin-top:10px;">
      Par levels come from a per-item demand model (trend + seasonality) fitted
      to each item's scan history; the S and G columns are its seasonal and
      annual-growth factors. Items with too little history (&lt; ~2 months) use
      a trailing <?= $velWindow ?>-day average instead (S = G = 1, shown with a
      <span title="trailing-average fallback">°</span> after the name). Items
      the pantry has never scanned have no demand history to forecast from at
      all; they're listed too, marked
      <span title="never scanned">&empty;</span> with empty model columns, so
      they can be ordered by hand. Adjust velocity window and Z under
      <a href="../../settings/">Settings</a>.
    </p>
    <p id="orderMsg" style="font-size:.85rem; font-weight:700; margin-top:8px;"></p>
  </div>

  <div class="card no-print">
    <h2>Reorder Alerts</h2>
    <p style="color:#777; font-size:.85rem; margin-bottom:12px;">
      A reminder shows on this page whenever an item's projected days-of-stock
      falls below its alert lead time. Lead time here is the fulfillment window
      you want to leave for the supplier. Tick <strong>Email</strong> on an alert
      to also have the reorder reminder emailed to the administrator
      <?php $adminEmail = trim((string)(setting('admin_email', '') ?? '')); ?>
      <?php if ($adminEmail !== ''): ?>
        (<strong><?= htmlspecialchars($adminEmail) ?></strong>)
      <?php endif; ?>
      by the scheduled reorder-alert job (see Settings → Email Notifications).
    </p>
    <?php if ($adminEmail === ''): ?>
      <div class="banner warn" style="margin-bottom:12px;">
        <div>
          No administrator email is set, so reorder reminders can't be emailed.
          Add one under <a href="../../settings/">Settings → Administrator Email &amp; Password</a>.
        </div>
      </div>
    <?php endif; ?>
    <form method="post" action="../../api_alert.php" class="row" style="margin-bottom:14px;">
      <input type="hidden" name="action" value="add">
      <div style="flex:2 1 220px;">
        <label>Item</label>
        <select name="generic_name" required>
          <option value="">— select —</option>
          <?php
          // $rows is ordered by order request (largest first) for the
          // recommendations table; the dropdown lists the same items a→z.
          $alertItems = array_column($rows, 'name');
          usort($alertItems, 'strcasecmp');
          ?>
          <?php foreach ($alertItems as $n): ?>
            <option value="<?= htmlspecialchars($n) ?>"><?= htmlspecialchars($n) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Lead Time (days)</label>
        <input type="number" name="lead_time_days" min="1" value="<?= $leadTime ?>" required>
      </div>
      <div style="flex:0 0 120px;">
        <label>&nbsp;</label>
        <button type="submit" class="btn btn-primary btn-block">Add</button>
      </div>
    </form>
    <?php
    $a = $db->query("SELECT id, generic_name, lead_time_days, enabled, email_enabled FROM alerts ORDER BY generic_name");
    $aRows = $a->fetchAll();
    ?>
    <?php if ($aRows): ?>
    <table class="data">
      <thead><tr>
        <th>Item</th>
        <th class="num">Lead Time</th>
        <th>Status</th>
        <th title="Email this reorder reminder to the administrator">Email</th>
        <th></th>
      </tr></thead>
      <tbody>
        <?php foreach ($aRows as $row): ?>
          <tr>
            <td><?= htmlspecialchars($row['generic_name']) ?></td>
            <td class="num"><?= (int)$row['lead_time_days'] ?> days</td>
            <td><?= $row['enabled'] ? 'On' : 'Off' ?></td>
            <td>
              <!-- Auto-submits on toggle. The hidden 0 precedes the checkbox so
                   an unchecked box still posts email=0 (last value wins). -->
              <form method="post" action="../../api_alert.php" style="display:inline; margin:0;">
                <input type="hidden" name="action" value="set_email">
                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                <input type="hidden" name="email" value="0">
                <input type="checkbox" name="email" value="1" style="width:auto; cursor:pointer;"
                       onchange="this.form.submit()"
                       <?= $row['email_enabled'] ? 'checked' : '' ?>>
              </form>
            </td>
            <td>
              <form method="post" action="../../api_alert.php" style="display:inline;">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                <button class="btn btn-secondary" style="padding:4px 10px; font-size:.8rem;">Remove</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
      <p style="color:#777;">No alerts configured.</p>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Order Recommendations</h2>
    <?php if (!$rows): ?>
      <p style="color:#777;">No scan history yet. Record some orders first.</p>
    <?php else: ?>
    <div class="no-print" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-bottom:12px;">
      <input type="search" id="repSearch" placeholder="🔍 Filter by generic name…"
             oninput="applyRepFilter()"
             style="max-width:340px; flex:1 1 200px;">
      <span id="repCount" style="font-size:.8rem; color:#777;"><?= count($rows) ?> items</span>
      <?php if ($anyCrates): ?>
        <!-- Live storage total, next to the checkboxes it reacts to rather than
             up beside the order buttons, so it's on screen while ticking. -->
        <span id="crateTotal" style="font-size:.8rem; margin-left:auto;"
              title="Storage this order needs, in cubic feet, from the figure set per item on the Inventory page (Cu Ft/Case).&#10;&#10;&quot;On hand/on order&quot; is the current Inventory count, which already includes any delivery booked in with Restock Now at the time it was ordered — so it covers what is on the shelf AND what is still in transit. That is everything that will be in the room when this order lands.&#10;&#10;Demand between now and delivery is not subtracted, so the figure runs a little high. Items with no Cu Ft/Case are left out of it entirely."></span>
      <?php endif; ?>
    </div>
    <p class="no-print" style="color:#777; font-size:.8rem; margin:-4px 0 12px;">
      <strong>Order Request</strong> is editable on every row. The forecast fills
      it in where it has one; change it, or type one into a blank box, and the
      order unit, case count and Restock Now all follow — quantities are always
      in the unit the pantry stocks the item in. Edited boxes are outlined in
      blue, and their tooltip shows what the forecast had asked for. Clear a box
      to leave that item out of the order.
    </p>
    <div class="rep-table-wrap">
    <table class="data" id="repTable">
      <thead><tr>
        <th title="Include this item in the order email / Restock Now">
          <?php /* Master toggle, in the header so it sits at the top of the
                   column it governs. It acts on the rows the filter is showing
                   (every row when nothing is typed in the search box). */ ?>
          <label style="display:inline-flex; align-items:center; gap:4px; cursor:pointer;"
                 title="Check or uncheck the Restock box on every listed row">
            <input type="checkbox" id="restockAll" onclick="toggleAllRestock(this)">
            Restock
          </label>
        </th>
        <th>Generic Name</th>
        <th>Type</th>
        <th class="num" title="Expected daily demand over the lead time. Model-fitted items derive it from their full scan history; fallback items (marked &deg; after the name) use a trailing <?= $velWindow ?>-day average.">Avg Daily</th>
        <th class="num" style="text-transform:none" title="Per-day demand standard deviation; drives Safety Stock">σ</th>
        <th class="num" title="Z × √(predictive variance) — buffer for demand uncertainty over the lead time">Safety<br>Stock</th>
        <th class="num" title="Seasonality factor: this window's forecast vs. its deseasonalized baseline (1.0 = average season)">S</th>
        <th class="num" title="Growth factor: the model's annual demand multiplier, exp(trend) (1.0 = flat)">G</th>
        <th class="num">Par<br>Level</th>
        <th class="num">In Stock</th>
        <th class="num" title="How much to order, in the unit the pantry stocks the item in — editable on every row. The forecast fills it in; change it and the order unit, cases and Restock Now all follow. Clear it to leave the item out.">Order<br>Request</th>
        <th>Unit</th>
        <th class="num" title="Whole cases to cover the Order Request, from the Count/Case on the Inventory page — counted in the item's Order Unit, which may differ from the unit the pantry stocks it in">Case<br>Request</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <?php
          // The Order Request field is the single source of truth for what gets
          // ordered, so it is pre-filled with the request rounded exactly as the
          // column used to print it — whole units for 'each', one decimal for
          // 'lb'. Everything downstream (order unit, cases, cu ft, restock) is
          // derived in the browser from this number, which means the figure the
          // operator reads is the figure the order carries; there is no
          // full-precision value working invisibly behind it.
          $reqRounded = $r['unit'] === 'each'
                      ? ceil((float)$r['order'])
                      : round((float)$r['order'], 1);
          // Blank rather than 0 when there is nothing to order: an empty box
          // invites a quantity, where a 0 reads as an answer.
          $reqValue = $reqRounded > 0
                    ? rtrim(rtrim(number_format($reqRounded, 1, '.', ''), '0'), '.')
                    : '';
          // Never scanned, so nothing in the demand columns was computed from
          // anything. They print blank rather than as 0.00 / 1.00, which would
          // read as a model that found no demand instead of no model at all.
          $noHist = ($r['method'] === 'unscanned');
        ?>
        <tr data-name="<?= htmlspecialchars(strtolower($r['name'])) ?>">
          <td style="text-align:center;">
            <?php /* Only conversion inputs travel with the row now: the cases,
                     order quantity, restock quantity and cubic-foot footprint all
                     depend on the editable field, so they are computed in the
                     browser rather than shipped as stale attributes. */ ?>
            <input type="checkbox" class="restock-cb"
                   data-name="<?= htmlspecialchars($r['name']) ?>"
                   data-cpc="<?= htmlspecialchars((string)(float)$r['cpc']) ?>"
                   data-unit="<?= htmlspecialchars($r['unit']) ?>"
                   data-order-unit="<?= htmlspecialchars($r['order_unit']) ?>"
                   data-alt-case="<?= htmlspecialchars((string)($r['alt_case'] ?? '')) ?>"
                   data-has-crates="<?= !empty($r['has_crates']) ? '1' : '0' ?>"
                   data-lb-per-each="<?= htmlspecialchars((string)(float)$r['lb_per_each']) ?>"
                   data-crates-per-case="<?= htmlspecialchars((string)(float)$r['crates_per_case']) ?>"
                   <?php /* Rows named in the Reorder reminders banner start
                            ticked, so the order the alerts are asking for can
                            be sent without hunting for those lines in the
                            table. Only where there is something to order: a
                            row whose Order Request is 0 (blank) contributes
                            nothing to the email or Restock Now, so a tick
                            there is just one more box to clear. */ ?>
                   <?= (isset($alertOrderNames[$r['name']]) && $reqValue !== '') ? 'checked' : '' ?>>
          </td>
          <td><strong><?= htmlspecialchars($r['name']) ?></strong><?php
              // ° marks rows using the trailing-average fallback (too little
              // history for the demand model); ∅ marks rows with no scan
              // history at all, listed only because Include Unscanned is on.
              // GLM-fitted rows get no marker.
              if ($noHist)                    echo '<span title="Never scanned — no demand history to forecast from, so there is no recommendation. Type a quantity to order it." style="color:#999; cursor:help;">&nbsp;&empty;</span>';
              elseif ($r['method'] !== 'glm') echo '<span title="Trailing-average fallback — not enough history to fit the demand model" style="color:#999; cursor:help;">&nbsp;°</span>';
          ?></td>
          <td><?= htmlspecialchars($r['kind']) ?></td>
          <td class="num"><?= $noHist ? '—' : number_format($r['adv'], 2) ?></td>
          <td class="num"><?= $noHist ? '—' : number_format($r['sigma'], 2) ?></td>
          <td class="num"><?= $noHist ? '—' : number_format($r['safety'], 1) ?></td>
          <td class="num"><?= $noHist ? '—' : number_format($r['S'], 2) ?></td>
          <td class="num"><?= $noHist ? '—' : number_format($r['G'], 2) ?></td>
          <td class="num"><?= $noHist ? '—' : number_format($r['par'], 1) ?></td>
          <td class="num"><?php
              // "Ignore In Stock" recommends as if nothing is on hand, so show
              // every In Stock value as 0 to match what the calculation used —
              // the item still stays in the report.
              $dispStock = $ignoreStock ? 0.0 : (float)$r['stock'];
              echo $r['unit'] === 'each'
                  ? (string)(int)round($dispStock)   // 'each' items are whole units
                  : number_format($dispStock, 1);
          ?></td>
          <td class="num">
            <?php
              // Every row's request is editable, whether the forecast produced
              // one or not. A recommendation is a starting point — the operator
              // knows about the truckload arriving Thursday, the event next
              // week, and what the vendor will actually sell today — and what
              // ends up in this box is what the email, the print sheet and
              // Restock Now all carry. data-calc keeps the figure the forecast
              // asked for so an override can be shown as such and put back.
              $hint = 'Order Request in ' . $r['unit'] . ', editable';
              if ($noHist) {
                  $hint = 'Never scanned, so there is no forecast for this item. '
                        . 'Type a quantity in ' . $r['unit'] . ' to order it';
              }
              if ($r['order_unit'] !== $r['unit']) {
                  $hint .= ' — converts to ' . $r['order_unit'] . ' for the order at '
                        . rtrim(rtrim(number_format($r['lb_per_each'], 3, '.', ''), '0'), '.')
                        . ' lb each';
              }
              $hint .= '.';
            ?>
            <input type="number" class="order-qty" min="0" placeholder="0"
                   step="<?= $r['unit'] === 'each' ? '1' : '0.1' ?>"
                   value="<?= htmlspecialchars($reqValue) ?>"
                   data-calc="<?= htmlspecialchars($reqValue) ?>"
                   aria-label="Order Request for <?= htmlspecialchars($r['name']) ?>, in <?= htmlspecialchars($r['unit']) ?>"
                   title="<?= htmlspecialchars($hint) ?>">
            <?php // Filled by the JS below: the request in the vendor's unit,
                  // whenever that differs from the unit the pantry stocks in. ?>
            <div class="order-conv"></div>
          </td>
          <td><?= htmlspecialchars($r['unit']) ?></td>
          <td class="num" style="font-weight:800;">
            <?php
              // "—" when Count/Case isn't set on the Inventory page. Otherwise a
              // span the JS rewrites from the Order Request field as it changes:
              // whole cases are what the vendor is actually asked for, so an
              // edit has to show its round-up before the order goes out.
              if ($r['cpc'] <= 0) echo '—';
              else                echo '<span class="case-req">' . (int)$r['cases'] . '</span>';
            ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>

  <div class="card no-print">
    <h2>Days Not Scanned</h2>
    <p style="color:#777; font-size:.85rem; margin-bottom:12px;">
      List days the pantry <strong>operated but nothing was scanned</strong> —
      volunteer absent, station down, simply missed. Food left the building with
      no record of it, so the demand model treats these days as
      <em>unobserved</em> instead of as days with zero demand, which would drag
      average daily demand down and quietly suppress reorder alerts.
    </p>
    <p style="color:#777; font-size:.85rem; margin-bottom:12px;">
      Do <strong>not</strong> list days the pantry was genuinely closed
      (holidays, snow days). Nothing moved on those, and that is honest
      information about demand.
    </p>
    <form method="post" action="../../api_unscanned.php" class="row" style="margin-bottom:14px;">
      <input type="hidden" name="action" value="add">
      <div>
        <label for="usDay">Date</label>
        <input type="date" id="usDay" name="day" required max="<?= date('Y-m-d') ?>">
      </div>
      <div style="flex:2 1 220px;">
        <label for="usNote">Note (optional)</label>
        <input type="text" id="usNote" name="note" maxlength="200"
               placeholder="e.g. no volunteer available">
      </div>
      <div style="flex:0 0 120px;">
        <label>&nbsp;</label>
        <button type="submit" class="btn btn-primary btn-block">Add</button>
      </div>
    </form>
    <?php
    // Read defensively: on a partial deploy (PHP files uploaded ahead of
    // schema.sql) the table won't exist yet, and the panel should degrade to
    // empty rather than fataling the whole report.
    $uRows = [];
    try {
        $uRows = $db->query("SELECT day, note FROM unscanned_days ORDER BY day DESC")->fetchAll();
    } catch (\Throwable $e) {
        $uRows = [];
    }
    ?>
    <?php if ($uRows): ?>
    <table class="data">
      <thead><tr>
        <th>Date</th>
        <th>Note</th>
        <th></th>
      </tr></thead>
      <tbody>
        <?php foreach ($uRows as $u): ?>
          <tr>
            <td><?= htmlspecialchars(date('D, M j, Y', strtotime($u['day']))) ?></td>
            <td><?= htmlspecialchars((string)$u['note']) ?></td>
            <td>
              <form method="post" action="../../api_unscanned.php" style="display:inline;">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="day" value="<?= htmlspecialchars($u['day']) ?>">
                <button class="btn btn-secondary" style="padding:4px 10px; font-size:.8rem;">Remove</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
      <p style="color:#777;">No missed scanning days recorded.</p>
    <?php endif; ?>
  </div>

</div>
<script>
// Generic-name filter for the recommendations table (mirrors the
// Inventory page's filter). Rows carry data-name in lowercase.
function applyRepFilter() {
  var q = (document.getElementById('repSearch').value || '').trim().toLowerCase();
  var rows = document.querySelectorAll('#repTable tbody tr');
  var shown = 0;
  rows.forEach(function (tr) {
    var match = !q || (tr.dataset.name || '').indexOf(q) !== -1;
    tr.style.display = match ? '' : 'none';
    if (match) shown++;
  });
  document.getElementById('repCount').textContent =
    q ? (shown + ' matching') : (shown + ' items');
  syncRestockMaster();
}

// The header's master Restock box works on the rows the filter is currently
// showing, not the whole table: with a filter typed, the visible list is the one
// the operator is working, and ticking items they can't see would put them in
// the order unseen. With the search box empty — the usual case — that is every
// row. Note the reverse of the same rule: clearing the box under a filter leaves
// any checked row that is filtered out still checked, and still in the order.
function visibleRestockBoxes() {
  var out = [];
  document.querySelectorAll('#repTable tbody tr').forEach(function (tr) {
    if (tr.style.display === 'none') return;
    var cb = tr.querySelector('.restock-cb');
    if (cb) out.push(cb);
  });
  return out;
}

function toggleAllRestock(master) {
  visibleRestockBoxes().forEach(function (cb) { cb.checked = master.checked; });
  master.indeterminate = false;
  updateCrateTotal();
}

// Keep the header box honest about the column under it: checked when every
// visible row is, indeterminate on a mixed set, clear when none are. Called
// after anything that ticks a box or changes which rows are visible — including
// the page-load pass, since alerted rows ship pre-checked.
function syncRestockMaster() {
  var master = document.getElementById('restockAll');
  if (!master) return;
  var boxes = visibleRestockBoxes();
  var checked = boxes.filter(function (cb) { return cb.checked; }).length;
  master.checked       = (boxes.length > 0 && checked === boxes.length);
  master.indeterminate = (checked > 0 && checked < boxes.length);
  master.disabled      = (boxes.length === 0);
}

// Email subject, with the pantry name appended server-side when set.
var ORDER_SUBJECT = <?= json_encode($orderSubject, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

// Order actions read the per-row "Restock" checkboxes live. Each ticked
// row carries its cases / count-per-case / unit / order request in
// data-* attributes, so both the email and the inventory restock reflect
// exactly what's checked at click time.
//
// Every row's Order Request is editable, so the quantity that matters only ever
// exists in the browser. The server sends the conversion inputs — the item's
// stock unit, order unit, weight per piece, case size, cubic feet per case — and
// deriveOrder() below runs report_lib.php's own conversion chain over whatever
// the field currently holds. Nothing downstream of gatherCheckedItems (email
// lines, print sheet, Restock Now, the cu ft tally) knows or cares whether that
// number came from the forecast or from the operator.
function orderInputFor(cb) {
  var tr = cb.closest('tr');
  return tr ? tr.querySelector('input.order-qty') : null;
}

// Port of op_convert_qty() in report_lib.php: cross a quantity between the only
// two units the app knows, using the item's average weight per piece. null when
// the units differ and no factor is set — genuinely unknown, so callers fall
// back to the stock unit rather than invent a number.
function opConvert(qty, from, to, lbPerEach) {
  if (from === to)      return qty;
  if (!(lbPerEach > 0)) return null;
  if (from === 'each' && to === 'lb')   return qty * lbPerEach;
  if (from === 'lb'   && to === 'each') return qty / lbPerEach;
  return null;
}

// Fill in an item's vendor-facing figures from its Order Request, following
// report_lib.php step for step: stock unit → order unit → whole cases (ceil, so
// 5.25 cases of avocados becomes 6) → cu ft, then back to the stock unit for
// what those cases actually put on the shelf.
function deriveOrder(it, qty) {
  if (!(qty > 0)) qty = 0;
  it.order = qty;
  var oq = opConvert(qty, it.unit, it.orderUnit, it.lbPerEach);
  if (oq === null) {          // half-configured item — stay in the stock unit
    it.orderUnit = it.unit;
    oq = qty;
  }
  it.orderQty = oq;
  it.cases    = (it.cpc > 0 && oq > 0) ? Math.ceil(oq / it.cpc) : 0;
  if (it.cpc > 0) {
    var back = opConvert(it.cases * it.cpc, it.orderUnit, it.unit, it.lbPerEach);
    it.restockQty = (back === null) ? it.cases * it.cpc : back;
  } else {
    it.restockQty = qty;
  }
  it.orderCrates = it.hasCrates ? it.cases * it.cratesPer : 0;
  return it;
}

// One row's order figures: the item's fixed conversion data from the checkbox,
// everything else derived from whatever its Order Request field holds right now.
function itemFromCb(cb) {
  var it = {
    name:       cb.dataset.name,
    cpc:        parseFloat(cb.dataset.cpc) || 0,
    unit:       cb.dataset.unit,
    orderUnit:  cb.dataset.orderUnit || cb.dataset.unit,
    altCase:    (cb.dataset.altCase || '').trim(),
    hasCrates:  cb.dataset.hasCrates === '1',
    lbPerEach:  parseFloat(cb.dataset.lbPerEach) || 0,
    cratesPer:  parseFloat(cb.dataset.cratesPerCase) || 0
  };
  var input = orderInputFor(cb);
  return deriveOrder(it, input ? parseFloat(input.value) : 0);
}

// Sorted by name, not left in table order. The table sorts by order request so
// the biggest needs are on top, but an order sheet is read against a shelf or a
// vendor's catalogue, where alphabetical is what makes an item findable — and a
// list whose order shifts with every recalculation is hard to check twice.
// Sorting here rather than in the email keeps the email, the print sheet, and
// Restock Now on one order, which is the point of them sharing this function.
// Case-insensitive, and numeric so "Bag 2" precedes "Bag 10".
function gatherCheckedItems() {
  var out = [];
  document.querySelectorAll('.restock-cb:checked').forEach(function (cb) {
    out.push(itemFromCb(cb));
  });
  out.sort(function (a, b) {
    return String(a.name).localeCompare(String(b.name), undefined,
                                        { sensitivity: 'base', numeric: true });
  });
  return out;
}

// Nothing worth ordering — used to drop rows whose request rounds away, so
// the email, the print sheet, and the restock all act on the same set.
function hasOrder(it) {
  var req = it.orderUnit === 'each'
          ? Math.ceil(it.orderQty)
          : Math.round(it.orderQty * 10) / 10;
  return req > 0;
}

function fmtNum(n) { return parseFloat(Number(n).toFixed(2)).toString(); }

// Shown when nothing ticked has a quantity behind it.
var NO_ORDER_MSG = 'No checked items have an order request greater than 0. '
  + 'Type a quantity into an item’s Order Request box to order it.';

// Order Request as the vendor reads it, with the same rounding the order lines
// use so a row's ≈ line and its email line can't disagree.
function orderQtyText(it) {
  return it.orderUnit === 'each'
    ? Math.ceil(it.orderQty) + ' count'
    : fmtNum(Math.round(it.orderQty * 10) / 10) + ' ' + it.orderUnit;
}

// Repaint one row's derived figures from its Order Request field: the ≈
// order-unit line under the field and the Case Request beside it, so the row on
// screen always shows the vendor figures the order will carry — including the
// round up to a whole case. Also flags the field when it no longer holds the
// forecast's own number, since an override is worth seeing before the order
// goes out; the tooltip carries the original so it can be put back.
function repaintRow(tr) {
  var cb = tr.querySelector('.restock-cb');
  var input = tr.querySelector('input.order-qty');
  if (!cb || !input) return null;
  var it = itemFromCb(cb);

  var conv = tr.querySelector('.order-conv');
  if (conv) {
    var crossesOver = (it.orderUnit !== it.unit && it.order > 0);
    conv.textContent = crossesOver ? '≈ ' + orderQtyText(it) : '';
    conv.title = crossesOver
      ? 'Order Request converted to this item’s Order Unit'
      : '';
  }
  // Only present when a Count/Case is set; without one the cell stays "—".
  var caseCell = tr.querySelector('.case-req');
  if (caseCell) caseCell.textContent = String(it.cases);

  // Stash the server-rendered tooltip on first paint so it can be restored when
  // an override is undone (cheaper than shipping it twice per row).
  if (input.dataset.hint === undefined) input.dataset.hint = input.title;
  // dataset.calc is '' for rows the forecast asked nothing for, so typing into
  // one of those counts as an override too — which is exactly what it is.
  var calc = input.dataset.calc || '';
  var edited = (input.value.trim() !== calc);
  input.classList.toggle('edited', edited);
  input.title = edited
    ? 'Edited — the forecast asked for '
      + (calc === '' ? 'nothing' : calc + ' ' + it.unit)
      + '. Clear the box to drop this item from the order.'
    : input.dataset.hint;
  return it;
}

// Typing a quantity also ticks the row's Restock box: entering a number is the
// intent to order the item, and leaving the box clear would silently drop it
// from the very order it was typed for. Emptying the field doesn't untick — an
// empty request contributes nothing anyway, and un-ticking under the operator
// would fight whatever they set deliberately. Only ever called from the input
// event, never on load: pre-filled recommendations must not tick themselves.
function onOrderInput(input) {
  var tr = input.closest('tr');
  if (!tr) return;
  var it = repaintRow(tr);
  var cb = tr.querySelector('.restock-cb');
  if (it && cb && it.order > 0 && !cb.checked) cb.checked = true;
  updateCrateTotal();
  syncRestockMaster();
}

// One email line per checked item, written entirely in the vendor's unit so
// it can be read straight off the sheet onto an order form. With a
// Count/Case set: the case request plus a "(count/case count|lb)"
// description, where "count" stands in for the 'each' unit. With Count/Case
// 0 or unavailable: only the Order Request quantity.
function emailLineFor(it) {
  var unitTxt = it.orderUnit === 'each' ? 'count' : it.orderUnit;
  if (it.cpc > 0) {
    // An Alt Case entry on the Inventory page is the vendor's own wording for
    // the pack ("89-100 ct case(s), least expensive variety"). It stands in
    // for the word case(s) and takes the "(48 count)" size note with it — the
    // alt text already describes the pack, so repeating the size reads wrong.
    if (it.altCase) {
      return it.cases + ' ' + it.altCase + ' - ' + it.name;
    }
    return it.cases + ' case' + (it.cases === 1 ? '' : 's') +
           ' - ' + it.name + ' (' + fmtNum(it.cpc) + ' ' + unitTxt + ')';
  }
  var oq = it.orderUnit === 'each' ? Math.ceil(it.orderQty)
                                   : Math.round(it.orderQty * 10) / 10;
  return fmtNum(oq) + ' ' + unitTxt + ' - ' + it.name;
}

// Quantity the order brings in, in the unit the pantry stocks the item in —
// cases × count/case converted back, or the Order Request when no
// Count/Case is set. Computed server-side; restock/submit_restock.php
// expects the inventory unit and is authoritative about which one that is.
function restockQtyFor(it) {
  if (it.cpc > 0) return it.restockQty;
  return it.unit === 'each' ? Math.ceil(it.order) : Math.round(it.order * 100) / 100;
}

// ── Storage capacity ────────────────────────────────────────────────────────
// Max Storage (cu ft) from Settings (0 = no limit set) and the cubic feet already
// on the floor, totalled server-side across every listed item with a Cu Ft/Case.
var MAX_CRATES   = <?= (int)$maxCrates ?>;
var STOCK_CRATES = <?= json_encode(round($stockCrates, 2)) ?>;

function fmtCrates(n) {
  return (Math.round(n * 10) / 10).toFixed(1);
}

// Cubic feet the ticked order needs, plus how many ticked items can't be counted
// because they have no Cu Ft/Case (or no Count/Case to divide by). Restricted
// to rows that would actually be ordered, so the total matches the email.
function crateTally() {
  var items = gatherCheckedItems().filter(hasOrder);
  var incoming = 0, unconfigured = 0;
  items.forEach(function (it) {
    if (it.hasCrates) incoming += it.orderCrates;
    else              unconfigured++;
  });
  return {
    incoming:     incoming,
    unconfigured: unconfigured,
    total:        STOCK_CRATES + incoming,
    over:         MAX_CRATES > 0 && (STOCK_CRATES + incoming) > MAX_CRATES
  };
}

// Repaint the readout beside the table. Called on load and on every tick.
function updateCrateTotal() {
  var el = document.getElementById('crateTotal');
  if (!el) return;
  var t = crateTally();
  // "on hand/on order" rather than "on hand": Restock Now is clicked when an
  // order is placed, not when it lands, so the Inventory count this comes from
  // is an inventory position — shelf plus whatever is still in transit. Saying
  // "on hand" would read as the physical shelf and understate what has to fit.
  var txt = 'Cu ft: ' + fmtCrates(STOCK_CRATES) + ' on hand/on order + '
          + fmtCrates(t.incoming) + ' this order = ' + fmtCrates(t.total);
  if (MAX_CRATES > 0) txt += ' of ' + MAX_CRATES;
  if (t.over) {
    txt = '⚠ ' + txt + ' — over by '
        + fmtCrates(t.total - MAX_CRATES);
  }
  // An unconfigured item takes real floor space the total can't see, so say so
  // rather than let the figure read as complete.
  if (t.unconfigured > 0) {
    txt += ' · ' + t.unconfigured + ' item'
        + (t.unconfigured === 1 ? '' : 's') + ' with no Cu Ft/Case';
  }
  el.textContent = txt;
  el.style.color      = t.over ? 'var(--red)' : '#777';
  el.style.fontWeight = t.over ? '700' : '400';
}

function setOrderMsg(text, ok) {
  var el = document.getElementById('orderMsg');
  if (!el) return;
  el.textContent = text;
  el.style.color = ok === false ? '#8B1A1A' : '#276437';
}

// Generate Email: open a Gmail draft (subject "New Order", no signature)
// listing one line per checked item.
function generateOrderEmail() {
  // Exclude anything with an Order Request of 0 (nothing actually needed),
  // matching the value shown in the table's Order Request column.
  var items = gatherCheckedItems().filter(hasOrder);
  if (!items.length) {
    alert(NO_ORDER_MSG);
    return;
  }
  // Last look at storage before the order leaves. A warning, never a block —
  // going over may well be the right call, and only the person ordering knows.
  // confirm() is synchronous and stays inside this click, so the window.open
  // below is still treated as user-initiated and isn't popup-blocked.
  var t = crateTally();
  if (t.over && !confirm(
        'This order needs ' + fmtCrates(t.incoming) + ' cu ft on top of the '
        + fmtCrates(STOCK_CRATES) + ' already on hand or on order — '
        + fmtCrates(t.total) + ' against a limit of ' + MAX_CRATES + ', over by '
        + fmtCrates(t.total - MAX_CRATES) + '.\n\n'
        + (t.unconfigured > 0
            ? t.unconfigured + ' checked item'
              + (t.unconfigured === 1 ? ' has' : 's have')
              + ' no Cu Ft/Case set, so the real total is higher.\n\n'
            : '')
        + 'Send it anyway?')) {
    return;
  }
  var body = items.map(emailLineFor).join('\n');
  var url = 'https://mail.google.com/mail/?view=cm&fs=1'
          + '&su=' + encodeURIComponent(ORDER_SUBJECT)
          + '&body=' + encodeURIComponent(body);
  // Open Gmail inside the click handler so it isn't popup-blocked.
  window.open(url, '_blank', 'noopener');
  recordEmailOrderTime();
  setOrderMsg('✅ Order email opened for ' + items.length + ' item(s).', true);
}

// Print Order: open a printer-friendly order sheet listing the checked
// items — the same lines the email uses — instead of mailing them.
function printOrder() {
  // Match Email Order: drop anything whose Order Request rounds to 0.
  var items = gatherCheckedItems().filter(hasOrder);
  if (!items.length) {
    alert(NO_ORDER_MSG);
    return;
  }
  var esc = function (s) {
    return String(s).replace(/[&<>]/g, function (c) {
      return c === '&' ? '&amp;' : c === '<' ? '&lt;' : '&gt;';
    });
  };
  var lines = items.map(function (it) {
    return '<li>' + esc(emailLineFor(it)) + '</li>';
  }).join('');
  // The popup is its own about:blank document, so relative asset paths
  // won't resolve. Reuse the parent page's favicon via its already-
  // absolute .href so the print tab shows the app icon.
  var iconLink = document.querySelector('link[rel~="icon"]');
  var faviconTag = iconLink
    ? '<link rel="icon" type="image/x-icon" href="' + esc(iconLink.href) + '">'
    : '';
  var html = '<!DOCTYPE html><html><head><meta charset="utf-8">'
           + '<title>' + esc(ORDER_SUBJECT) + '</title>'
           + faviconTag
           + '<style>body{font-family:Arial,Helvetica,sans-serif;margin:40px;}'
           + 'h1{font-size:1.3rem;} ul{font-size:1rem;line-height:1.7;} li{margin-bottom:2px;}'
           + '</style></head><body>'
           + '<h1>' + esc(ORDER_SUBJECT) + '</h1>'
           + '<ul>' + lines + '</ul>'
           // Print once loaded, then close the helper tab when the print
           // dialog is dismissed (printed or cancelled) so it isn't left behind.
           + '<script>window.onafterprint=function(){window.close();};'
           + 'window.onload=function(){window.print();};<\/script>'
           + '</body></html>';
  // Open the sheet inside the click handler so it isn't popup-blocked.
  var w = window.open('', '_blank');
  if (!w) {
    setOrderMsg('⚠ Print Order was blocked — allow pop-ups and try again.', false);
    return;
  }
  w.document.open();
  w.document.write(html);
  w.document.close();
  setOrderMsg('✅ Order sheet opened for printing (' + items.length + ' item(s)).', true);
}

// Restock Now: add the ordered quantities for the checked items to
// inventory (a purchased restock, so it also feeds the Purchased % metric).
function restockNow() {
  var items = gatherCheckedItems();
  if (!items.length) {
    alert('Check at least one item’s Restock box to add it to inventory.');
    return;
  }
  // Same zero-filter as Email/Print. Without it a row with a Count/Case and a
  // negligible request is left off the order sheet yet still books a whole
  // case into inventory.
  items = items.filter(hasOrder);
  if (!items.length) {
    setOrderMsg(NO_ORDER_MSG, false);
    return;
  }
  var fd = new FormData();
  fd.append('purchased', '1'); // ordered from a supplier = purchased
  var n = 0;
  items.forEach(function (it) {
    var qty = restockQtyFor(it);
    if (qty > 0) {
      fd.append('item_name[]',  it.name);
      fd.append('item_count[]', qty);
      fd.append('item_unit[]',  it.unit);
      n++;
    }
  });
  if (!n) {
    setOrderMsg('Nothing to restock — the checked items have no quantity to add.', false);
    return;
  }
  fetch('../../restock/submit_restock.php', { method: 'POST', body: fd, credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (data && data.ok) {
        setOrderMsg('✅ Restocked ' + data.lines + ' item(s) into inventory.', true);
      } else {
        setOrderMsg('⚠ Restock failed: ' + ((data && data.error) || 'unknown error') + '.', false);
      }
    })
    .catch(function (err) {
      setOrderMsg('⚠ Restock request failed: ' + err.message + '.', false);
    });
}

// Persist + live-update the "Last ordered" stamp under the order buttons.
// Fires on every Generate Email click so the stamp reflects the latest send.
function recordEmailOrderTime() {
  var stamp = document.getElementById('lastOrderStamp');
  var fd = new FormData();
  fd.append('action', 'record_email_order');
  fetch('', { method: 'POST', body: fd, credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (data && data.ok && stamp) {
        var d = new Date(data.at.replace(' ', 'T'));
        var txt = isNaN(d.getTime())
          ? data.at
          : d.toLocaleString([], { month: 'short', day: 'numeric', year: 'numeric',
                                   hour: 'numeric', minute: '2-digit' });
        // Keep the label above the date so the stamp stays narrow.
        stamp.innerHTML = '<div>Last email order:</div>'
                        + '<div id="lastOrderDate" style="white-space:nowrap;"></div>';
        stamp.querySelector('#lastOrderDate').textContent = txt;
      }
    })
    .catch(function () { /* stamp just won't refresh until next reload */ });
}

// Keep the cu ft readout in step with the Restock boxes, and each row's derived
// figures in step with its Order Request field. Both delegated from the table so
// they cover every row without one listener each.
//
// The initial pass paints every row rather than trusting the server's figures:
// the field is rounded to what the column displays, so deriving from it is what
// makes the ≈ line and Case Request agree with the number actually on screen.
// It also picks up values a browser refilled on reload or a back-button return.
// It deliberately does NOT go through onOrderInput — that ticks Restock boxes,
// and a page-load tick of every recommended row is not something the operator
// asked for.
(function () {
  var tbl = document.getElementById('repTable');
  if (tbl) {
    tbl.addEventListener('change', function (e) {
      if (e.target && e.target.classList.contains('restock-cb')) {
        updateCrateTotal();
        syncRestockMaster();
      }
    });
    tbl.addEventListener('input', function (e) {
      if (e.target && e.target.classList.contains('order-qty')) onOrderInput(e.target);
    });
    tbl.querySelectorAll('tbody tr').forEach(function (tr) { repaintRow(tr); });
  }
  updateCrateTotal();
  syncRestockMaster();
})();
</script>
<?php renderFoot(); ?>
