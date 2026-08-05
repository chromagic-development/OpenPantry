<?php
// Manual inventory count entry. Lists every generic_name we've ever seen
// (from upc_lookup, produce_lookup, and prior scans) and lets the user
// enter a current count. Save writes to the `inventory` table.
$GLOBALS['FS_PREFIX'] = '../';
require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../auth.php';
requireLogin();
$db = getDB();

// Build the master list of generic names FIRST so the POST handler can
// upsert the Deliverable flag for every row on the form, including rows
// that don't have an inventory record yet (no current count entered).
$names = [];
$kinds = [];
foreach ($db->query("SELECT DISTINCT generic_name FROM upc_lookup") as $r) {
    $names[$r['generic_name']] = 'each';
    $kinds[$r['generic_name']] = 'packaged';
}
foreach ($db->query("SELECT DISTINCT generic_name, unit FROM produce_lookup") as $r) {
    $names[$r['generic_name']] = $r['unit'];
    $kinds[$r['generic_name']] = 'produce';
}
foreach ($db->query("SELECT DISTINCT generic_name, kind FROM scans") as $r) {
    if (!isset($names[$r['generic_name']])) {
        $names[$r['generic_name']] = ($r['kind'] === 'produce') ? 'lb' : 'each';
    }
    if (!isset($kinds[$r['generic_name']])) {
        $kinds[$r['generic_name']] = $r['kind'];
    }
}
ksort($names, SORT_NATURAL | SORT_FLAG_CASE);

$saved = false;
$produceCleared = false;
// Rows saved with an Order Unit but no Avg Wt to convert by (see the save loop).
$needsWeight = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['action'] ?? '') === 'remove_produce') {
        // Zero out the current count for every produce item (any generic_name
        // present in produce_lookup). Only touches inventory rows that already
        // exist; never-counted produce is already effectively zero.
        $clr = $db->prepare(
            "UPDATE inventory SET count = 0, updated_at = ?
             WHERE generic_name IN (SELECT generic_name FROM produce_lookup)"
        );
        $clr->execute([now()]);
        $produceCleared = true;
    } else {
        // Normalize the submission into one $rows list. Primary path: a single
        // JSON blob serialized by JS at submit time. This exists because PHP's
        // max_input_vars (1000 on typical shared hosting) silently DROPS every
        // POST variable past the first 1000 — with 350+ items × 3-4 fields per
        // row, every item alphabetically past the cutoff was never saved at
        // all. One JSON field sidesteps the limit entirely.
        $rows = [];
        $json = (string)($_POST['rows_json'] ?? '');
        if ($json !== '') {
            foreach ((array)json_decode($json, true) as $r) {
                if (!is_array($r) || !isset($r['name'])) continue;
                $rows[] = [
                    'name'  => (string)$r['name'],
                    'count' => (string)($r['count'] ?? ''),
                    'unit'  => (string)($r['unit'] ?? ''),
                    // null = field absent (never true on the JS path, which
                    // always sends the case value, possibly '').
                    'case'  => array_key_exists('case', $r) ? (string)$r['case'] : null,
                    'ounit' => array_key_exists('ounit', $r) ? (string)$r['ounit'] : null,
                    'lbea'  => array_key_exists('lbea', $r)  ? (string)$r['lbea']  : null,
                    'alt'   => array_key_exists('alt', $r)   ? (string)$r['alt']   : null,
                    'del'   => !empty($r['del']),
                ];
            }
        } else {
            // No-JS fallback: per-field arrays keyed by numeric row index with
            // the generic name as a hidden field *value* (values round-trip
            // intact even for names with brackets or odd characters). Still
            // subject to max_input_vars truncation on very large item lists.
            $postNames  = $_POST['name']       ?? [];
            $counts     = $_POST['count']      ?? [];
            $units      = $_POST['unit']       ?? [];
            $caseCounts = $_POST['case_count'] ?? [];
            $orderUnits = $_POST['order_unit']  ?? [];
            $lbPerEachs = $_POST['lb_per_each'] ?? [];
            $altCases   = $_POST['alt_case']    ?? [];
            // Deliverable: unchecked boxes don't submit at all, so default
            // every submitted row to 0 and flip the ones the browser DID send.
            $deliverablePost = $_POST['deliverable'] ?? [];
            foreach ($postNames as $i => $name) {
                $rows[] = [
                    'name'  => (string)$name,
                    'count' => (string)($counts[$i] ?? ''),
                    'unit'  => (string)($units[$i] ?? ''),
                    'case'  => array_key_exists($i, $caseCounts) ? (string)$caseCounts[$i] : null,
                    'ounit' => array_key_exists($i, $orderUnits) ? (string)$orderUnits[$i] : null,
                    'lbea'  => array_key_exists($i, $lbPerEachs) ? (string)$lbPerEachs[$i] : null,
                    'alt'   => array_key_exists($i, $altCases)   ? (string)$altCases[$i]   : null,
                    'del'   => isset($deliverablePost[$i]),
                ];
            }
        }
        $upd = $db->prepare(
            "INSERT INTO inventory (generic_name, count, unit, updated_at)
             VALUES (?, ?, ?, ?)
             ON CONFLICT(generic_name) DO UPDATE SET
               count = excluded.count, unit = excluded.unit, updated_at = excluded.updated_at"
        );
        // Separate upsert for deliverable so it can be persisted independently
        // of count (rows with no current count still need the flag stored).
        $updDel = $db->prepare(
            "INSERT INTO inventory (generic_name, count, unit, updated_at, deliverable)
             VALUES (?, 0, ?, ?, ?)
             ON CONFLICT(generic_name) DO UPDATE SET deliverable = excluded.deliverable"
        );
        // The four ordering fields, also independent of count and written
        // together because they only mean anything as a set: Count/Case is a
        // number of Order Units, Order Unit is only honoured when Avg Wt
        // supplies the conversion, and Alt Case renames the case the other
        // three add up to. 0 / '' = "not set", which the Order Report renders
        // as "—" in its Case Request column, treats as "order in the stock
        // unit", and reads as the plain word "case(s)" respectively.
        $updOrder = $db->prepare(
            "INSERT INTO inventory (generic_name, count, unit, updated_at, count_per_case, order_unit, lb_per_each, alt_case)
             VALUES (?, 0, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(generic_name) DO UPDATE SET
               count_per_case = excluded.count_per_case,
               order_unit     = excluded.order_unit,
               lb_per_each    = excluded.lb_per_each,
               alt_case       = excluded.alt_case"
        );
        // Current ordering fields, so a submission that omits one of the four
        // (a truncated or hand-rolled POST) preserves it instead of zeroing it.
        $curOrder = [];
        foreach ($db->query("SELECT generic_name, count_per_case, order_unit, lb_per_each, alt_case FROM inventory") as $c) {
            $curOrder[$c['generic_name']] = $c;
        }
        // The unit also lives in produce_lookup, so keep it in sync — otherwise
        // changing a produce item's unit here (e.g. lb -> each) leaves the
        // lookup table stale. The UPDATE is a harmless no-op for non-produce names.
        $updProduce = $db->prepare("UPDATE produce_lookup SET unit = ? WHERE generic_name = ?");

        foreach ($rows as $r) {
            $name = $r['name'];
            // Only accept names that are in the server-built master list, so a
            // crafted POST can't write arbitrary inventory rows.
            if (!isset($names[$name])) continue;
            $u   = ($r['unit'] === 'lb' || $r['unit'] === 'each')
                 ? $r['unit']
                 : (($names[$name] === 'lb') ? 'lb' : 'each');
            $del = $r['del'] ? 1 : 0;
            $updProduce->execute([$u, $name]);
            // Always persist deliverable (independent of count).
            $updDel->execute([$name, $u, now(), $del]);
            // Persist the ordering fields whenever any of them was submitted.
            // An emptied field maps back to 0 / '' ("not set") so a stale case
            // size or order unit can be cleared; a field the POST left out
            // entirely keeps its stored value.
            if ($r['case'] !== null || $r['ounit'] !== null || $r['lbea'] !== null
                || $r['alt'] !== null) {
                $cur = $curOrder[$name] ?? [];
                $cpc = $r['case'] !== null
                     ? ((is_numeric($r['case']) && (float)$r['case'] > 0) ? (float)$r['case'] : 0.0)
                     : (float)($cur['count_per_case'] ?? 0);
                $ou  = $r['ounit'] !== null
                     ? (($r['ounit'] === 'lb' || $r['ounit'] === 'each') ? $r['ounit'] : '')
                     : (string)($cur['order_unit'] ?? '');
                $lbe = $r['lbea'] !== null
                     ? ((is_numeric($r['lbea']) && (float)$r['lbea'] > 0) ? (float)$r['lbea'] : 0.0)
                     : (float)($cur['lb_per_each'] ?? 0);
                // Free text, so only collapse the whitespace — the wording is
                // the vendor's and goes into the order email verbatim. Newlines
                // would break the one-line-per-item email format.
                // (?? falls back when the subject isn't valid UTF-8, which
                // makes preg_replace return null rather than a string.)
                $alt = $r['alt'] !== null
                     ? trim(preg_replace('/\s+/u', ' ', $r['alt']) ?? $r['alt'])
                     : (string)($cur['alt_case'] ?? '');
                if (mb_strlen($alt) > 200) $alt = mb_substr($alt, 0, 200);
                // Picking the stock unit as the order unit is just "no
                // override" — store it as such so the report never has to
                // special-case the two being equal.
                if ($ou === $u) $ou = '';
                // Set to order in the other unit with no average weight to
                // convert by: the Order Report falls back to the stock unit, so
                // flag it rather than let the row look configured.
                if ($ou !== '' && $lbe <= 0) $needsWeight[] = $name;
                $updOrder->execute([$name, $u, now(), $cpc, $ou, $lbe, $alt]);
            }
            // Persist count + unit only if the user actually entered a value.
            if ($r['count'] === '') continue;
            $upd->execute([$name, (float)$r['count'], $u, now()]);
        }
        $saved = true;
    }
}

$inv = [];
foreach ($db->query(
    "SELECT generic_name, count, unit, updated_at, deliverable,
            restocked_purchased, restocked_donated, count_per_case,
            order_unit, lb_per_each, alt_case
       FROM inventory") as $r) {
    $inv[$r['generic_name']] = $r;
}

// Per-row Purchased % display. Lifetime ratio of items added via the
// Restock page (purchased / (purchased + donated)). Rows that have never
// been touched by Restock have both counters at 0 → no ratio defined →
// rendered as "—" so the column doesn't mislead with a fake 0%.
function purchasedPctDisplay(?array $row): string {
    if (!$row) return '—';
    $p = (float)($row['restocked_purchased'] ?? 0);
    $d = (float)($row['restocked_donated']   ?? 0);
    $t = $p + $d;
    if ($t <= 0) return '—';
    return (string)((int)round(($p / $t) * 100)) . '%';
}

renderHead('Inventory');
renderNav('inventory');
?>
<div class="container">
  <?php if ($saved): ?>
    <div class="banner success">✅ Inventory updated.</div>
  <?php endif; ?>
  <?php if ($needsWeight): ?>
    <div class="banner warn">
      ⚠ Set an <strong>Avg Wt</strong> for
      <?= htmlspecialchars(implode(', ', $needsWeight)) ?> —
      without a weight per piece there's nothing to convert by, so the Order
      Report will keep ordering <?= count($needsWeight) === 1 ? 'it' : 'them' ?>
      in the pantry's own unit and ignore the Order Unit.
    </div>
  <?php endif; ?>
  <?php if ($produceCleared): ?>
    <div class="banner success">✅ Produce stock removed (all produce counts set to zero).</div>
  <?php endif; ?>

  <div class="card">
    <h2>Latest Inventory Count</h2>
    <p style="color:#777; font-size:.85rem; margin-bottom:14px;">
      Enter your current estimated count for each generic item.
      The Order Report uses these to compute <em>Total Order Request = Par Level − Latest Inventory Count</em>.
    </p>

    <?php if (!$names): ?>
      <p style="color:#777;">No items in the lookup tables yet. Scan a few barcodes first or add entries under Lookup Tables.</p>
    <?php else: ?>
    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-bottom:12px;">
      <select id="invKind" onchange="applyInvFilter()" style="width:160px;">
        <option value="">All Types</option>
        <option value="packaged">Packaged Only</option>
        <option value="produce">Produce Only</option>
      </select>
      <input type="search" id="invSearch" placeholder="🔍 Filter by generic name…"
             oninput="applyInvFilter()"
             style="max-width:340px; flex:1 1 200px;">
      <span id="invCount" style="font-size:.8rem; color:#777;"><?= count($names) ?> items</span>
      <button type="button" class="btn btn-secondary" style="margin-left:auto;"
              onclick="printInventory()">🖨 Print Inventory Report</button>
      <form method="post" style="display:inline; margin:0;"
            onsubmit="return confirm('Set every produce item\'s current count to zero? This cannot be undone.');">
        <input type="hidden" name="action" value="remove_produce">
        <button type="submit" class="btn btn-secondary">Remove Produce Stock</button>
      </form>
      <button type="submit" form="invForm" class="btn btn-primary">Save All</button>
    </div>
    <form method="post" id="invForm">
      <!-- Filled by JS on submit with the whole table as one JSON blob so the
           POST stays at ~1 variable instead of 4 per row (max_input_vars). -->
      <input type="hidden" name="rows_json" id="rowsJson" value="">
      <div class="inv-table-wrap">
      <table class="data" id="invTable">
        <!-- Percentage widths + table-layout:fixed keep all ten columns inside
             the card no matter how long a generic name runs, so the table never
             needs to scroll sideways on a desktop screen. -->
        <colgroup>
          <col class="c-name"><col class="c-del"><col class="c-count">
          <col class="c-unit"><col class="c-ounit"><col class="c-lbea">
          <col class="c-cpc"><col class="c-alt"><col class="c-pct"><col class="c-upd">
        </colgroup>
        <thead><tr>
          <th>Generic<br>Name</th>
          <th title="Show this item on the client delivery menu (does not affect the PantryPrep counter form)">Deliver&shy;able</th>
          <th class="num">Current<br>Count</th>
          <th>Unit</th>
          <th title="The unit the wholesale vendor quotes this item's case in, when it differs from the unit the pantry stocks it in — loose avocados weighed in lb but bought by the 48-count case. Needs an Avg Wt to convert by.">Order<br>Unit</th>
          <th class="num" title="Average weight of one piece, in pounds. This is what converts between the two units: the Order Report divides by it to place the order in pieces and multiplies by it to book the delivery back into pounds.">Avg Wt<br>(lb ea)</th>
          <th class="num" title="How many Order Units one supplier case holds — used for the Case Request column on the Order Report">Count/<br>Case</th>
          <th title="The vendor's own wording for this item's pack. When filled in, the Order Report's Email Order and Print Order lines read &quot;4 89-100 ct case(s), least expensive variety - Apples&quot; in place of the default &quot;4 cases - Apples (40 lb)&quot;: it replaces the word case(s) and the pack-size note in parentheses. Only the first few characters fit the column; the whole entry is stored and used.">Alt<br>Case</th>
          <th class="num" title="Lifetime % of this item's restocked amount that was purchased rather than donated">Bought</th>
          <th>Last<br>Updated</th>
        </tr></thead>
        <tbody>
        <?php $i = 0; foreach ($names as $name => $defaultUnit): $i++;
            $row = $inv[$name] ?? null;
            $count = $row['count'] ?? '';
            $unit  = $row['unit']  ?? $defaultUnit;
            // 'each' items can only be counted in whole units; show 21 not 21.0.
            if ($count !== '' && $unit === 'each') {
                $count = (string)(int)round((float)$count);
            } elseif ($count !== '') {
                // 'lb' items: trim pointless trailing zeros (21.50 -> 21.5, 21.0 -> 21).
                $count = rtrim(rtrim(number_format((float)$count, 2, '.', ''), '0'), '.');
            }
            $isEach = ($unit === 'each');
            // Default new rows to deliverable=1 (show on the delivery menu).
            // Only persisted 0 values flip the checkbox off.
            $deliverable = ($row === null) ? 1 : (int)($row['deliverable'] ?? 1);
            // Count/Case: 0 = unset → blank field. Trim trailing zeros for display.
            $cpc = (float)($row['count_per_case'] ?? 0);
            $cpcDisplay = $cpc > 0
                ? rtrim(rtrim(number_format($cpc, 2, '.', ''), '0'), '.')
                : '';
            // Order unit: '' = same as the stock unit (the common case).
            $orderUnit = (string)($row['order_unit'] ?? '');
            // Avg weight per piece: 0 = unset → blank. Three decimals, since a
            // small piece of produce can weigh well under a tenth of a pound.
            $lbEach = (float)($row['lb_per_each'] ?? 0);
            $lbEachDisplay = $lbEach > 0
                ? rtrim(rtrim(number_format($lbEach, 3, '.', ''), '0'), '.')
                : '';
            // Alt Case: free text, '' = unset. The field is only ~10 characters
            // wide, so the full entry lives in the title tooltip too.
            $altCase = (string)($row['alt_case'] ?? '');
        ?>
          <tr data-name="<?= htmlspecialchars(strtolower($name)) ?>" data-kind="<?= htmlspecialchars($kinds[$name] ?? 'packaged') ?>">
            <td class="inv-name">
              <input type="hidden" name="name[<?= $i ?>]" value="<?= htmlspecialchars($name) ?>">
              <?= htmlspecialchars($name) ?></td>
            <td style="text-align:center;">
              <input type="checkbox"
                     name="deliverable[<?= $i ?>]"
                     value="1"<?= $deliverable === 1 ? ' checked' : '' ?>
                     class="inv-check">
            </td>
            <td class="num">
              <div class="inv-counter">
                <button type="button" class="inv-btn inv-btn-dec"
                        aria-label="Decrement" onclick="bumpCount(this, -1)">−</button>
                <input type="number"
                       step="<?= $isEach ? '1' : '0.01' ?>"
                       min="0"
                       class="inv-count-input"
                       name="count[<?= $i ?>]"
                       value="<?= htmlspecialchars((string)$count) ?>">
                <button type="button" class="inv-btn inv-btn-inc"
                        aria-label="Increment" onclick="bumpCount(this, 1)">+</button>
              </div>
            </td>
            <td>
              <select name="unit[<?= $i ?>]" onchange="syncStep(this)">
                <option value="each" <?= $unit==='each'?'selected':'' ?>>each</option>
                <option value="lb"   <?= $unit==='lb'  ?'selected':'' ?>>lb</option>
              </select>
            </td>
            <td>
              <select name="order_unit[<?= $i ?>]" title="The unit this item is ordered in. &quot;same&quot; means the vendor quotes it in the pantry's own stock unit.">
                <option value="" <?= $orderUnit===''    ?'selected':'' ?>>same</option>
                <option value="each" <?= $orderUnit==='each'?'selected':'' ?>>each</option>
                <option value="lb"   <?= $orderUnit==='lb'  ?'selected':'' ?>>lb</option>
              </select>
            </td>
            <td class="num">
              <input type="number" step="any" min="0" placeholder="—"
                     name="lb_per_each[<?= $i ?>]"
                     value="<?= htmlspecialchars($lbEachDisplay) ?>">
            </td>
            <td class="num">
              <input type="number" step="any" min="0" placeholder="—"
                     name="case_count[<?= $i ?>]"
                     value="<?= htmlspecialchars($cpcDisplay) ?>">
            </td>
            <td>
              <input type="text" class="inv-alt" maxlength="200" placeholder="—"
                     name="alt_case[<?= $i ?>]"
                     value="<?= htmlspecialchars($altCase) ?>"
                     title="<?= $altCase === ''
                         ? 'Vendor wording for this pack, used in place of &quot;case(s)&quot; on the order email and print sheet'
                         : htmlspecialchars($altCase) ?>">
            </td>
            <td class="num" style="font-variant-numeric: tabular-nums;"><?= htmlspecialchars(purchasedPctDisplay($row)) ?></td>
            <td class="inv-updated"><?= htmlspecialchars($row['updated_at'] ?? '—') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <div style="margin-top:16px;">
        <button type="submit" class="btn btn-primary">Save All</button>
      </div>
    </form>
    <?php endif; ?>
  </div>
</div>
<style>
  /* All nine columns are sized as a percentage of the card so the table fits
     the page width and never scrolls sideways on a desktop screen. The wrapper
     keeps overflow-x only as the phone fallback, where the controls would
     otherwise be squeezed below a usable size (see min-width on the table). */
  .inv-table-wrap { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
  /* min-width is the phone floor: below it the wrapper scrolls rather than
     squeezing the selects and the count field down to unusable slivers. Any
     card wider than this (every desktop and tablet width) fits with no scroll. */
  #invTable { table-layout: fixed; min-width: 780px; }
  /* Percentages total 100 — the widths below are the whole budget. */
  #invTable .c-name  { width: 14%; }
  #invTable .c-del   { width:  9%; }
  #invTable .c-count { width: 17%; }
  #invTable .c-unit  { width:  8%; }
  #invTable .c-ounit { width:  9%; }
  #invTable .c-lbea  { width:  8%; }
  #invTable .c-cpc   { width:  8%; }
  #invTable .c-alt   { width: 10%; }
  #invTable .c-pct   { width:  8%; }
  #invTable .c-upd   { width:  9%; }
  /* Tighter than the shared table.data rhythm: at 18px root the default
     8px/10px padding alone costs ~200px across ten columns. */
  #invTable th, #invTable td { padding: 6px 5px; font-size: .8rem; }
  #invTable th { font-size: .62rem; line-height: 1.25; hyphens: auto; }
  #invTable .inv-name { overflow-wrap: anywhere; }
  #invTable .inv-updated { color: #777; font-size: .68rem; line-height: 1.3; }
  #invTable .inv-check { width: 20px; height: 20px; cursor: pointer; }
  /* Controls fill their column instead of carrying fixed pixel widths. */
  #invTable select,
  #invTable input[type=number],
  #invTable input[type=text] {
    width: 100%; padding: 6px 4px;
    font-size: .85rem; border-width: 1px;
  }
  /* Alt Case holds a sentence in a ~10-character slot: smaller type, and the
     caret scrolls through the rest of the entry (the full text is in the
     field's tooltip). */
  #invTable .inv-alt { font-size: .7rem; text-overflow: ellipsis; }
  #invTable input[type=number] { text-align: right; }
  /* Spinner arrows cost ~15px per number field and the count already has its
     own +/- buttons, so drop them. */
  #invTable input[type=number]::-webkit-outer-spin-button,
  #invTable input[type=number]::-webkit-inner-spin-button {
    -webkit-appearance: none; margin: 0;
  }
  #invTable input[type=number] { -moz-appearance: textfield; }
  /* The count input flexes into whatever the buttons leave, so the counter
     always fits its column. */
  .inv-counter { display: flex; align-items: center; justify-content: flex-end; gap: 4px; }
  /* Explicit flex-basis, not auto: a number input's intrinsic width is far
     wider than the column and would otherwise shrink the buttons instead. */
  .inv-count-input { flex: 1 1 50px; min-width: 50px; text-align: right; }
  #invTable .inv-count-input { font-size: .9rem; padding: 6px 4px; }
  /* The buttons hold 44px wherever there's room and give ground before the
     count field does, so the counter never spills out of its column. */
  .inv-btn {
    flex: 0 1 44px; width: 44px; min-width: 30px; height: 44px;
    font-size: 1.5rem; font-weight: 800; line-height: 1;
    padding: 0; cursor: pointer;
    border: 1px solid var(--border); border-radius: 10px;
    background: #fafaf5; color: var(--brown);
    touch-action: manipulation; user-select: none;
  }
  .inv-btn:hover  { filter: brightness(.97); }
  .inv-btn:active { transform: scale(.94); }
  .inv-btn-dec   { color: var(--red); }
  .inv-btn-inc   { color: var(--green); }
</style>
<script>
// Tap-friendly increment/decrement. Increments by 1 regardless of unit so the
// button feels useful on a phone; users can still type fractional lb directly.
function bumpCount(btn, delta) {
  var wrap  = btn.closest('.inv-counter');
  var input = wrap.querySelector('input[type=number]');
  if (!input) return;
  var cur = parseFloat(input.value);
  if (isNaN(cur)) cur = 0;
  var next = cur + delta;
  if (next < 0) next = 0;
  var isEach = parseFloat(input.step) >= 1;
  if (isEach) {
    input.value = String(Math.round(next));
  } else {
    // Mirror server-side display: up to 2 decimals, trim trailing zeros.
    input.value = (next.toFixed(2).replace(/\.?0+$/, '') || '0');
  }
}

// When the unit dropdown flips between each/lb, retune the count input's
// step so the spinner moves in whole units for 'each' and 0.01 for 'lb',
// and round any visible value to match.
function syncStep(sel) {
  // Target the count field by class — the row also holds Avg Wt and Count/Case
  // number inputs, and neither should be re-stepped by the unit dropdown.
  var input = sel.closest('tr').querySelector('.inv-count-input');
  if (!input) return;
  if (sel.value === 'each') {
    input.step = '1';
    if (input.value !== '') input.value = String(Math.round(parseFloat(input.value) || 0));
  } else {
    input.step = '0.01';
  }
}

// Serialize every row into ONE hidden JSON field at submit time, then disable
// the per-field inputs so they are excluded from the POST. Without this the
// submission has 3-4 variables per item and PHP's max_input_vars (1000 on
// most shared hosts) silently drops every row past the first ~250-330 items —
// items late in the alphabet were never saved.
var invForm = document.getElementById('invForm');
if (invForm) invForm.addEventListener('submit', function() {
  var rows = document.querySelectorAll('#invTable tbody tr');
  var data = [];
  rows.forEach(function(tr) {
    var nameInput = tr.querySelector('input[type=hidden][name^="name["]');
    if (!nameInput) return;
    var cnt     = tr.querySelector('.inv-count-input');
    var unitSel = tr.querySelector('select[name^="unit["]');
    var caseIn  = tr.querySelector('input[name^="case_count["]');
    var ounitSel= tr.querySelector('select[name^="order_unit["]');
    var lbeaIn  = tr.querySelector('input[name^="lb_per_each["]');
    var altIn   = tr.querySelector('input[name^="alt_case["]');
    var delCb   = tr.querySelector('input[type=checkbox][name^="deliverable["]');
    data.push({
      name:   nameInput.value,
      count:  cnt ? cnt.value : '',
      unit:   unitSel ? unitSel.value : '',
      'case': caseIn ? caseIn.value : '',
      ounit:  ounitSel ? ounitSel.value : '',
      lbea:   lbeaIn ? lbeaIn.value : '',
      alt:    altIn ? altIn.value : '',
      del:    !!(delCb && delCb.checked)
    });
  });
  document.getElementById('rowsJson').value = JSON.stringify(data);
  // Disabled controls don't submit; keeps the POST tiny. The page reloads on
  // response, so leaving them disabled for the request's duration is fine.
  this.querySelectorAll('#invTable [name]').forEach(function(el) { el.disabled = true; });
});

function applyInvFilter() {
  var q    = (document.getElementById('invSearch').value || '').toLowerCase().trim();
  var kind = document.getElementById('invKind').value;
  var rows = document.querySelectorAll('#invTable tbody tr');
  var shown = 0;
  rows.forEach(function(tr) {
    var name    = tr.getAttribute('data-name') || '';
    var rowKind = tr.getAttribute('data-kind') || '';
    var matchesName = !q    || name.includes(q);
    var matchesKind = !kind || rowKind === kind;
    var visible = matchesName && matchesKind;
    tr.style.display = visible ? '' : 'none';
    if (visible) shown++;
  });
  var filtered = (q || kind);
  document.getElementById('invCount').textContent =
    shown + (filtered ? ' of ' + rows.length : ' items');
}

function escHtml(s) {
  return String(s).replace(/[&<>"']/g, function(ch) {
    return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[ch];
  });
}

// Build a compact, paper-saving printout of the current inventory. Reads the
// on-screen values (so any unsaved edits are reflected), lays the items out in
// multiple narrow columns, and opens the browser print dialog. The whole
// inventory is included regardless of the on-screen filter.
function printInventory() {
  var rows  = document.querySelectorAll('#invTable tbody tr');
  var items = [];
  rows.forEach(function(tr) {
    var nameCell = tr.querySelector('td');
    var input    = tr.querySelector('.inv-count-input');
    var unitSel  = tr.querySelector('select[name^="unit"]');
    if (!nameCell || !input) return;
    items.push({
      name:  (nameCell.textContent || '').trim(),
      count: (input.value || '').trim(),
      unit:  unitSel ? unitSel.value : ''
    });
  });
  if (!items.length) { alert('No inventory items to print.'); return; }

  var now = new Date();
  var dateStr = now.toLocaleDateString() + ' ' +
    now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

  var listHtml = items.map(function(it) {
    var c = (it.count === '') ? '—' : it.count;
    return '<div class="ir"><span class="n">' + escHtml(it.name) +
           '</span><span class="c">' + escHtml(c) +
           (it.unit ? ' ' + escHtml(it.unit) : '') + '</span></div>';
  }).join('');

  var doc =
    '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Inventory Report</title><style>' +
    '@page { margin: 10mm; }' +
    '* { box-sizing: border-box; }' +
    'body { font-family: Arial, Helvetica, sans-serif; color:#000; margin:0; }' +
    'h1 { font-size:14px; margin:0 0 2px; }' +
    '.meta { font-size:10px; color:#444; margin:0 0 8px; }' +
    '.list { column-count: 3; column-gap: 16px; }' +
    '@media (max-width: 640px) { .list { column-count: 2; } }' +
    '.ir { display:flex; justify-content:space-between; gap:8px; font-size:10px;' +
    ' line-height:1.5; padding:1px 0; border-bottom:1px dotted #bbb;' +
    ' break-inside:avoid; -webkit-column-break-inside:avoid; }' +
    '.ir .n { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }' +
    '.ir .c { font-weight:700; white-space:nowrap; }' +
    '</style></head><body>' +
    '<h1>Inventory Report</h1>' +
    '<div class="meta">' + items.length + ' items &middot; ' + escHtml(dateStr) + '</div>' +
    '<div class="list">' + listHtml + '</div>' +
    '</body></html>';

  var win = window.open('', '_blank');
  if (!win) { alert('Pop-up blocked. Allow pop-ups for this site to print the report.'); return; }
  win.document.open();
  win.document.write(doc);
  win.document.close();
  win.focus();
  // Let the column layout render before invoking print.
  setTimeout(function() { try { win.print(); } catch (e) {} }, 250);
}
</script>
<?php renderFoot(); ?>
