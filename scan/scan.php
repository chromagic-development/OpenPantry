<?php
// Laser-scanner page. The hardware acts as a keyboard wedge: it types the
// barcode digits then sends Enter. The first scan auto-creates a new order;
// the End / Cancel buttons close or discard it respectively.
$GLOBALS['FS_PREFIX'] = '../';
require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../auth.php';
requireAllowedIP();
// activeScanOrder(), not currentOpenOrder(): this station may be assisting an
// order another station started (team scanning), in which case that shared
// order is the one this page scans into.
$myStation = currentStationId();
// Sticky assist: a station left in assist mode joins the next open order by
// itself. Runs before the order is resolved, so a reload between households
// paints the new order rather than an idle bar it would have to poll out of.
autoJoinNextAssist();
$order     = activeScanOrder();
$isAssist  = $order ? (bool)$order['is_assist'] : false;
// On until Leave Assist, whether or not there is an order to help right now.
$assistMode = assistModeOn();
// In assist mode between orders: no order, but not free either — the bar shows
// Leave Assist and the next scan joins the next order instead of starting one.
$waiting    = !$order && $assistMode;
// This station's own sliding switch: does it sound the scan tone when it
// records an item while assisting? Per station and persistent (station_prefs),
// so it is remembered the next time this device assists. Defaults to on.
$scanBeepOn = stationScanBeep();
// The scan table is rendered client-side, so a mid-order refresh would
// otherwise show an empty list with no ✕ buttons — leaving the operator no way
// to remove a mistake short of ending the order. Ship the open order's existing
// scans to the client and let appendRow() hydrate them on load. Same rows the
// sync poll returns, so hydration and live redraw can't drift apart.
$bootScans   = $order ? orderScanRows((int)$order['id']) : [];
$assistCount = $order ? orderAssistCount((int)$order['id']) : 0;
// Orders on other stations this one could offer to help with. Only computed
// when idle — a station already working an order isn't free to assist.
$assistable  = $order ? [] : assistableOrders();
// The scan page is gated by IP rather than by password, so most stations have
// nobody signed in. When someone is — administrator or supervisor — the Assist
// card also offers End and Cancel for each of those orders. That is the only
// way to clear an order whose station has gone away (laptop closed, tablet
// carried off): its owner is the only station allowed to close it, and the
// owner is exactly what is missing, so it stays open forever, keeps showing up
// as assistable, and keeps being re-joined by any station in sticky assist
// mode. See 'remote_end' / 'remote_cancel' in api_order.php.
$authRole       = fpAuthRole();
$canRemoteClose = $authRole !== '';
// Tare (ounces) subtracted from each entered produce weight; converted to lb
// for the client-side weight math.
$tareLbs = (float)(setting('tare_oz', '0') ?? 0) / 16.0;
// Settings → Ignore Unknown Items. On, a packaged UPC that can't be named is
// waved past instead of opening the "Identify this item" window.
$ignoreUnknown = (setting('ignore_unknown_items', '0') ?? '0') === '1';
// End/Cancel reload the page with these params so the confirmation survives
// the refresh. Only honored when no order is open (a new scan supersedes it).
$closedMsg = '';
if (!$order) {
  if (!empty($_GET['closed'])) {
    $closedMsg = 'Order #' . (int)$_GET['closed'] . ' closed'
               . (!empty($_GET['at']) ? ' at ' . htmlspecialchars($_GET['at']) : '');
  } elseif (!empty($_GET['cancelled'])) {
    $closedMsg = 'Order #' . (int)$_GET['cancelled'] . ' cancelled — scans discarded';
  }
}
renderHead('Scan');
// Menu/subnav intentionally omitted — the scan station is a focused kiosk flow.
?>
<div class="container scan-page">
 <div class="scan-layout">
  <div class="scan-col">
  <div id="orderBar" class="card" style="display:flex; align-items:center; gap:14px; flex-wrap:wrap;">
    <div style="flex:1 1 200px;">
      <div style="font-size:.75rem; text-transform:uppercase; color:#777;">
        <span id="orderBarEyebrow"><?= $isAssist ? 'Assisting Order' : ($waiting ? 'Assist Mode' : 'Current Order') ?></span>
        <!-- Owner-side team indicator. Hidden until a helper actually joins, so
             a single-station pantry never sees it. -->
        <span id="teamPill" class="team-pill"
              style="<?= (!$isAssist && $assistCount > 0) ? '' : 'display:none;' ?>">👥
          <span id="teamPillCount"><?= (int)$assistCount ?></span> assisting</span>
      </div>
      <div id="orderNumLabel" style="font-size:1.8rem; font-weight:800; color:var(--brown);">
        <?= $order ? '#' . (int)$order['id'] : ($waiting ? '— waiting —' : '— not started —') ?>
      </div>
      <div id="orderStartLabel" style="font-size:.8rem; color:#777;">
        <?php if ($order && $isAssist): ?>
          Started <?= htmlspecialchars($order['started_at']) ?> on another station
        <?php elseif ($order): ?>
          Started <?= htmlspecialchars($order['started_at']) ?>
        <?php elseif ($waiting): ?>
          Waiting for the next order to assist
        <?php else: ?>
          <?= $closedMsg ?: 'Scan an item to begin a new order' ?>
        <?php endif; ?>
      </div>
    </div>
    <!-- Owner controls. A helper never gets these: ending or cancelling the
         shared order stays with the station that started it. -->
    <div id="ownerControls" style="display:<?= ($isAssist || $waiting) ? 'none' : 'flex' ?>; gap:8px;">
      <button id="btnEnd"    class="btn btn-primary" <?= ($order && !$isAssist) ? '' : 'disabled' ?>>■ End Order</button>
      <button id="btnCancel" class="btn btn-danger"  <?= ($order && !$isAssist) ? '' : 'disabled' ?>>✕ Cancel Order</button>
    </div>
    <!-- Helper-side controls. The beep switch is here because the beep is
         here: an assisting station confirms each item out loud on its own
         speaker, and its operator is the one who decides whether they want
         that. -->
    <div id="assistControls" style="display:<?= ($isAssist || $waiting) ? 'flex' : 'none' ?>; gap:12px; align-items:center;">
      <label class="switch-row" for="beepSwitch"
             title="Sound a scan tone on this station each time you scan an item.">
        <span class="switch">
          <input type="checkbox" id="beepSwitch" <?= $scanBeepOn ? 'checked' : '' ?>>
          <span class="switch-track" aria-hidden="true"></span>
        </span>
        <span>🔔 Beep on each scan</span>
      </label>
      <button id="btnLeaveAssist" class="btn btn-secondary">⎋ Leave Assist</button>
    </div>
  </div>

  <!-- ── Team scanning: offer to help an order open on another station ──
       Rendered only when this station is idle and another one is mid-order, so
       a pantry running a single scanner never sees it. Kept in sync (shown,
       hidden, refreshed) by the sync poll. -->
  <div id="assistCard" class="card" style="<?= $assistable ? '' : 'display:none;' ?>">
    <div style="font-size:.75rem; text-transform:uppercase; color:#777; margin-bottom:8px;">
      Another station is scanning
    </div>
    <!-- Shown only to a signed-in administrator or supervisor, since only they
         get the End / Cancel buttons on the rows below. -->
    <div id="assistAdminNote" class="assist-note"
         style="<?= $canRemoteClose ? '' : 'display:none;' ?>">
      Signed in as <?= $authRole === 'supervisor' ? 'supervisor' : 'administrator' ?> —
      you can close one of these orders from here if the station that started it
      is no longer in use.
    </div>
    <div id="assistList"><?php foreach ($assistable as $a): ?>
      <div class="assist-row">
        <div>
          <div class="assist-order">Order #<?= (int)$a['id'] ?></div>
          <div class="assist-meta">
            started <?= htmlspecialchars(date('g:i A', strtotime($a['started_at']))) ?>
            · <?= (int)$a['scan_count'] ?> item<?= (int)$a['scan_count'] === 1 ? '' : 's' ?>
            <?= (int)$a['assist_count'] > 0 ? ' · ' . (int)$a['assist_count'] . ' assisting' : '' ?>
          </div>
        </div>
        <div class="assist-actions">
          <button type="button" class="btn btn-secondary btn-assist"
                  data-order-id="<?= (int)$a['id'] ?>">+ Assist</button>
          <?php if ($canRemoteClose): ?>
          <button type="button" class="btn btn-primary btn-sm btn-remote-end"
                  data-order-id="<?= (int)$a['id'] ?>"
                  data-scan-count="<?= (int)$a['scan_count'] ?>"
                  title="Close order #<?= (int)$a['id'] ?> from here and deduct its items from inventory.">■ End</button>
          <button type="button" class="btn btn-danger btn-sm btn-remote-cancel"
                  data-order-id="<?= (int)$a['id'] ?>"
                  data-scan-count="<?= (int)$a['scan_count'] ?>"
                  title="Discard order #<?= (int)$a['id'] ?> and everything scanned into it.">✕ Cancel</button>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?></div>
  </div>

  <div class="card" id="scanCard">
    <h2>Scan Barcode</h2>
    <!-- One line only: the longer explanation cost ~110px of the left column
         every load, and the placeholder below already says the field takes a
         typed name as well as a barcode. -->
    <div class="banner info" id="scannerHint">
      <span style="font-size:1.2rem;">📷</span>
      <div><strong>Scanner ready</strong> — scan a barcode, or weigh produce
           first and scan its PLU when asked.</div>
    </div>

    <!-- USB scale (HID POS). Hidden entirely on browsers without WebHID, so a
         station using the keyboard-wedge cable never sees a control it can't
         use. Shown by initScale() once support is confirmed. -->
    <div id="scaleBar" class="scale-bar" style="display:none;">
      <span id="scaleStatus" class="scale-status"></span>
      <button id="scaleConnect" type="button" class="btn btn-secondary scale-btn">⚖ Connect Scale</button>
    </div>

    <label for="barcodeInput">Barcode or Item Name</label>
    <input type="text" id="barcodeInput" autocomplete="off" autocapitalize="off"
           autocorrect="off" spellcheck="false"
           placeholder="Scan or type barcode/item name…"
           style="font-size:1.4rem; font-family:monospace; letter-spacing:2px;"
           autofocus>

    <!-- Type-ahead results when the operator types a name instead of a barcode -->
    <div id="nameMatches" style="display:none;"></div>

    <div id="lastScanWrap" style="display:none; margin-top:12px;">
      <div style="font-size:.75rem; text-transform:uppercase; color:#777;">Last scan</div>
      <div id="lastScan" style="font-size:1.4rem; font-weight:800; color:var(--brown);"></div>
      <div id="lastScanMeta" style="font-size:.8rem; color:#777;"></div>
    </div>

  </div>
  </div><!-- /.scan-col -->

  <!-- Right pane: the live order. Height-capped so the table scrolls inside
       itself and the newest row (with its ✕) is always on screen. -->
  <div class="card" id="thisOrderCard">
    <div class="order-head">
      <h2 style="margin:0;">This Order</h2>
      <div class="order-head-btns">
        <button id="camStart" class="btn btn-primary">Start Camera</button>
        <button id="camStop"  class="btn btn-secondary" disabled>Stop Camera</button>
        <button id="btnRecipe" class="btn btn-secondary" <?= $order ? '' : 'disabled' ?>>🍲 Suggest Recipe</button>
      </div>
    </div>
    <!-- Camera viewfinder. html5-qrcode injects a <video> here on start; the
         div stays empty and hidden until then, so the laser station's layout is
         unchanged. Sits directly under its buttons and above the scan list, so
         on a phone the item you just scanned appears right below the preview. -->
    <div id="reader" style="display:none;"></div>
    <div class="stat-grid" style="margin-bottom:12px;">
      <div class="stat"><div class="v" id="statCount">0</div><div class="k">Items Scanned</div></div>
      <div class="stat"><div class="v" id="statUnique">0</div><div class="k">Unique Generics</div></div>
      <div class="stat"><div class="v" id="statWeight">0.0</div><div class="k">Produce lbs</div></div>
    </div>
    <div id="scanTableWrap">
      <table class="data" id="scanTable">
        <!-- The "Who" column only earns its space once two stations share the
             order; it is hidden (via .team-on on the wrapper) until then. -->
        <thead><tr>
          <th>Time</th><th>Generic</th><th>Kind</th><th class="col-who">Who</th><th class="num">Qty</th><th class="num">Lbs</th><th>Barcode</th><th class="col-x"></th>
        </tr></thead>
        <tbody></tbody>
      </table>
    </div>
  </div>
 </div><!-- /.scan-layout -->

  <!-- ── Unknown-UPC name-entry modal (blocks until save or cancel) ── -->
  <div id="namePrompt" class="wt-overlay" style="display:none;" aria-hidden="true">
    <div class="wt-modal" role="dialog" aria-modal="true" aria-labelledby="nameTitle">
      <div class="wt-header">
        <div id="nameEyebrow" class="wt-eyebrow">⚠ Unknown UPC</div>
        <h2 id="nameTitle" class="wt-title">Identify this item</h2>
        <div style="font-family:monospace; font-size:1rem; color:var(--brown); margin-top:6px;">
          <span id="nameUpc">—</span>
        </div>
      </div>
      <div class="wt-body">
        <p id="nameStdNote" style="font-size:.85rem; color:#777; margin-bottom:14px;">
          Open Food Facts has no record of this UPC. Enter a generic name
          to add it to the cache so future scans recognize it automatically.
        </p>
        <!-- Shown instead of the note above for a store-printed item label,
             where what gets saved is deliberately not the barcode on the
             package. Filled in by openNameModal(). -->
        <p id="nameStoreNote" style="display:none; font-size:.85rem; color:#777; margin-bottom:14px;"></p>
        <div style="margin-bottom:14px;">
          <label for="nameBrand" class="wt-label">Branded Name <span style="font-weight:400; color:#999;">(optional)</span></label>
          <input type="text" id="nameBrand" autocomplete="off" autocapitalize="words"
                 placeholder="e.g. Bumble Bee Solid White Tuna">
        </div>
        <div>
          <label for="nameGeneric" class="wt-label">Generic Name</label>
          <input type="text" id="nameGeneric" autocomplete="off" autocapitalize="words"
                 placeholder="e.g. Canned Tuna">
        </div>
      </div>
      <div class="wt-actions">
        <button id="nameCancel" type="button" class="btn btn-secondary wt-btn">Cancel</button>
        <button id="nameSubmit" type="button" class="btn btn-primary wt-btn wt-btn-primary">💾 Save &amp; Record</button>
      </div>
    </div>
  </div>

  <!-- ── Product-recall modal ──────────────────────────────────────────
       A recalled item is the one case where the right outcome is for the
       volunteer to stop, so this blocks rather than flashing a banner: a
       4-second message at the top of the card is missed by an operator who is
       looking at the cart, and the item would go home with the household. It
       has to be dismissed, and it names the item so the right package comes
       back out. -->
  <div id="recallPrompt" class="wt-overlay recall-overlay" style="display:none;" aria-hidden="true">
    <div class="wt-modal recall-modal" role="alertdialog" aria-modal="true" aria-labelledby="recallTitle">
      <div class="wt-header recall-header">
        <div class="wt-eyebrow">🚨 Recalled Product</div>
        <h2 id="recallTitle" class="wt-title">Remove this item from the cart immediately - it has been recalled!</h2>
      </div>
      <div class="wt-body">
        <div class="recall-item" id="recallItem">—</div>
        <div class="recall-meta" id="recallMeta">—</div>
        <p class="wt-hint">
          Take this package out of the household's cart and set it aside for
          disposal. It has <strong>not</strong> been added to the order, and it
          cannot be scanned until the recall is cleared under Lookup Tables.
        </p>
      </div>
      <div class="wt-actions">
        <button id="recallAck" type="button" class="btn btn-danger wt-btn wt-btn-primary">✓ Item Removed</button>
      </div>
    </div>
  </div>

  <!-- ── Weight-entry modal (blocks the operator until they save or cancel) ── -->
  <div id="weightPrompt" class="wt-overlay" style="display:none;" aria-hidden="true">
    <div class="wt-modal" role="dialog" aria-modal="true" aria-labelledby="wtTitle">
      <div class="wt-header">
        <div class="wt-eyebrow">⚖ Weight required</div>
        <h2 id="wtTitle" class="wt-title"><span id="weightItem"></span></h2>
      </div>
      <div class="wt-body">
        <label for="weightInput" class="wt-label">Weight in pounds and ounces</label>
        <div class="wt-input-wrap">
          <!-- Two entry modes share this field:
               • Adding-machine (manual): 1st digit = whole pounds, next 2 =
                 ounces. Typing 514 shows "5 lb 14 oz". Backspace pops a digit.
               • Scale (HID): a connected scale auto-types decimal pounds like
                 " 1.120lb" + Enter; the '.' switches to decimal-pounds mode. -->
          <input type="text" id="weightInput" inputmode="numeric"
                 autocomplete="off" autocapitalize="off" autocorrect="off"
                 spellcheck="false" placeholder="0 lb 00 oz" class="wt-input"
                 style="padding-right:20px;">
        </div>
        <p class="wt-hint">
          Place the item on the scale and it records automatically. Or type by
          hand: 1 digit for pounds, then 2 digits for ounces —
          e.g. <code>514</code> = 5 lb 14 oz. Backspace to correct;
          Enter or <em>Save Weight</em> records it.
        </p>
        <p id="wtError" class="wt-error" style="display:none;"></p>
      </div>
      <div class="wt-actions">
        <button id="weightCancel" type="button" class="btn btn-secondary wt-btn">Cancel</button>
        <button id="weightSubmit" type="button" class="btn btn-primary wt-btn wt-btn-primary">💾 Save Weight</button>
      </div>
    </div>
  </div>

  <!-- ── PLU-entry modal (scale-first mode: weight is already captured) ── -->
  <div id="pluPrompt" class="wt-overlay" style="display:none;" aria-hidden="true">
    <div class="wt-modal" role="dialog" aria-modal="true" aria-labelledby="pluTitle">
      <div class="wt-header">
        <div class="wt-eyebrow">⚖ Weighed — PLU required</div>
        <h2 id="pluTitle" class="wt-title"><span id="pluWeight">—</span></h2>
      </div>
      <div class="wt-body">
        <label for="pluInput" class="wt-label">Scan or type the produce PLU — or its name</label>
        <div class="wt-input-wrap">
          <!-- Weight payloads are intercepted here too: a scale that sends its
               reading twice would otherwise have the repeat typed in as a PLU. -->
          <input type="text" id="pluInput" inputmode="numeric"
                 autocomplete="off" autocapitalize="off" autocorrect="off"
                 spellcheck="false" placeholder="e.g. 4011" class="wt-input">
        </div>
        <!-- Type-ahead over weighed produce names, so an operator who knows the
             item but not its code can pick it instead of hunting a PLU sheet. -->
        <div id="pluMatches" style="display:none;"></div>
        <!-- inputmode="numeric" gives a touch keypad with no letters on it, so
             a name can't be typed until this switches the field to text. -->
        <button id="pluNameMode" type="button" class="plu-name-mode">🔤 Type a name instead</button>
        <p class="wt-hint">
          The weight above is held until you identify the item. Scan the PLU (or
          type it and press Enter), then <strong>clear the platform</strong> —
          the scale only sends the next weight after it returns to zero.
          Typing letters searches produce sold by the pound — pick a match and
          its PLU is recorded for you.
        </p>
        <p id="pluError" class="wt-error" style="display:none;"></p>
      </div>
      <div class="wt-actions">
        <button id="pluCancel" type="button" class="btn btn-secondary wt-btn">Discard Weight</button>
        <button id="pluSubmit" type="button" class="btn btn-primary wt-btn wt-btn-primary">💾 Record</button>
      </div>
    </div>
  </div>

  <style>
    .wt-overlay {
      position: fixed; inset: 0;
      background: rgba(20, 14, 0, .62);
      align-items: center; justify-content: center;
      z-index: 9999; padding: 20px;
    }
    /* Capped and column-flexed so a tall body (the PLU window's match list) can
       never push Record/Discard off the bottom of a short screen — the header
       and the buttons stay put and the body scrolls between them. */
    .wt-modal {
      background: #fff; border: 1px solid var(--border);
      border-radius: 14px; width: 100%; max-width: 560px;
      max-height: calc(100vh - 40px);
      display: flex; flex-direction: column;
      box-shadow: 0 20px 60px rgba(0,0,0,.35); overflow: hidden;
      animation: wtPop .18s ease;
    }
    @keyframes wtPop { from { transform: scale(.94); opacity:0 } to { transform: scale(1); opacity:1 } }
    .wt-header { padding: 22px 28px 16px; background: var(--cat-bg); border-bottom: 1px solid var(--border); }
    .wt-eyebrow { font-size:.78rem; text-transform:uppercase; letter-spacing:.5px;
                  color: var(--brown); font-weight:700; }
    .wt-title { font-size: 2rem; color: var(--brown); margin-top: 6px;
                line-height: 1.1; word-break: break-word; }
    .wt-body { padding: 22px 28px 8px; overflow-y: auto; }
    .wt-label { font-size:.85rem; text-transform:uppercase; color: var(--brown);
                font-weight: 700; letter-spacing:.5px; margin-bottom: 10px; display:block; }
    .wt-input-wrap { position: relative; }
    /* `input.wt-input` (not bare `.wt-input`) so the type-attribute selector
       in common.php doesn't override our padding/font-size. */
    input.wt-input {
      width: 100%; font-size: 2.8rem; font-family: monospace;
      letter-spacing: 4px; text-align: right;
      padding: 16px 90px 16px 20px;
      border: 2px solid var(--border); border-radius: 10px;
      background: #fafaf5;
    }
    input.wt-input:focus { outline: none; border-color: var(--green);
                           box-shadow: 0 0 0 4px rgba(139,175,58,.22); }
    /* >3 digits in the buffer = invalid input. Outline red so the operator
       sees the overrun before pressing Enter (which then clears the field). */
    input.wt-input.wt-overflow,
    input.wt-input.wt-overflow:focus {
      border-color: var(--red); color: var(--red);
      box-shadow: 0 0 0 4px rgba(200, 60, 60, .20);
    }
    .wt-unit { position: absolute; right: 20px; top: 50%; transform: translateY(-50%);
               font-size: 1.15rem; color:#777; font-weight: 700; }
    .wt-hint { font-size:.78rem; color:#777; margin-top: 10px; }
    .wt-error { font-size:.95rem; color: var(--red); font-weight:700; margin-top: 10px; }
    .wt-actions { padding: 16px 24px 22px; display:flex; gap: 12px;
                  justify-content: flex-end; background: #fafaf5; border-top: 1px solid var(--border); }
    .wt-btn { font-size: 1.05rem; padding: 14px 26px; min-width: 130px; }
    .wt-btn-primary { min-width: 180px; }

    /* ── Recall window ── */
    /* Above the PLU window's z-index: a held weight does not get to sit in
       front of a recall, and acknowledging returns to the PLU window with the
       weight still held. */
    .recall-overlay { z-index: 10000; background: rgba(48, 4, 0, .86); }
    .recall-modal { border: 3px solid var(--red); }
    .recall-header { background: #F8D7DA; border-bottom: 1px solid #F1AEB5; }
    .recall-header .wt-eyebrow, .recall-header .wt-title { color: #8B1A1A; }
    .recall-header .wt-title { font-size: 1.6rem; }
    /* Pulses so the window reads as an alarm from across the counter, where
       the beep may be all the operator has noticed. */
    .recall-modal { animation: wtPop .18s ease, recallPulse 1s ease-in-out infinite; }
    @keyframes recallPulse {
      0%, 100% { box-shadow: 0 20px 60px rgba(0,0,0,.35), 0 0 0 0 rgba(177,69,42,.55); }
      50%      { box-shadow: 0 20px 60px rgba(0,0,0,.35), 0 0 0 14px rgba(177,69,42,0); }
    }
    .recall-item { font-size: 1.5rem; font-weight: 800; color: var(--brown); }
    .recall-meta { font-family: monospace; font-size: .85rem; color: #777; margin-top: 4px; }

    /* ── Name type-ahead suggestion list ── */
    #nameMatches { border: 1px solid var(--border); border-radius: 10px;
                   margin-top: 8px; overflow: hidden; background: #fff; }
    .nm-row { display: flex; align-items: center; gap: 12px;
              padding: 10px 14px; cursor: pointer;
              border-top: 1px solid var(--border); }
    .nm-row:first-child { border-top: none; }
    .nm-row:hover { background: var(--cat-bg); }
    /* Exactly one match left — highlight it so the operator knows Enter/Tab
       will accept it. */
    .nm-row.nm-unique { background: rgba(139,175,58,.14); }
    .nm-name { font-weight: 700; color: var(--brown); }
    .nm-brand { font-size: .75rem; color: #777; }
    .nm-code { font-family: monospace; color: #777; margin-left: auto; }
    .nm-hint { padding: 8px 14px; font-size: .75rem; color: #777;
               background: #fafaf5; border-top: 1px solid var(--border); }
    .nm-hint:first-child { border-top: none; }

    /* ── Same list inside the PLU window ── */
    /* No cap of its own: .wt-body scrolls, so the list is bounded by the modal
       and there is only ever one scrollbar to chase. */
    #pluMatches { border: 1px solid var(--border); border-radius: 10px;
                  margin-top: 10px; background: #fff; text-align: left; }
    /* Letters in the PLU field: the digit styling (right-aligned, 2.8rem,
       4px letter-spacing) makes a typed word unreadable, so relax it. */
    input.wt-input.wt-text {
      font-size: 1.5rem; letter-spacing: normal; text-align: left;
      font-family: inherit; padding-right: 20px;
    }
    .plu-name-mode { display: block; margin-top: 10px; background: none;
                     border: none; padding: 0; cursor: pointer;
                     font-size: .8rem; font-weight: 700; color: var(--brown);
                     text-decoration: underline; }

    /* ── Scan-table rows ── */
    /* Generic-name links open AI prep tips; dotted underline + help cursor
       so they read as "more info" rather than navigation. */
    /* ── USB scale strip ── */
    .scale-bar { display: flex; align-items: center; gap: 10px;
                 margin: 10px 0 4px; flex-wrap: wrap; }
    .scale-status { flex: 1 1 220px; font-size: .9rem; font-weight: 700;
                    padding: 8px 12px; border-radius: 8px;
                    border: 1px solid var(--border); background: var(--cat-bg);
                    color: var(--brown); }
    .scale-status.ok   { border-color: var(--green); }
    /* The idle/fault state has to survive a glance from across the counter —
       the operator is looking at the scale, not the screen. */
    .scale-status.warn { border-color: var(--red); color: var(--red);
                         background: #fdf3f3; }
    .scale-btn { padding: 8px 14px; font-size: .9rem; }

    .prep-link { color: var(--brown); font-weight: 600;
                 text-decoration: underline dotted; text-underline-offset: 3px;
                 cursor: help; }
    .prep-link:hover { color: var(--green); }
    /* Wide on purpose: removing a mis-scan is the one corrective action on this
       page, so it gets a target that's hard to miss in a hurry. The column
       width is set in CSS (.col-x) rather than inline so it tracks the
       narrower short-screen size below. */
    .btn-x { background: var(--red); color: #fff; border: none;
             border-radius: 8px; width: 132px; height: 44px;
             font-size: 1.3rem; font-weight: 800; cursor: pointer;
             line-height: 1; padding: 0; }
    #scanTable th.col-x { width: 148px; }
    .btn-x:hover { filter: brightness(1.1); }
    .btn-x:active { transform: scale(.96); }
    .btn-x:disabled { opacity: .4; cursor: not-allowed; }

    /* ── Team scanning ────────────────────────────────────────────────────
       The Who column is dead weight on a solo station, so it stays hidden
       until a second station joins the order (.team-on, set by the sync
       poll). Hiding the cells rather than not rendering them keeps
       appendRow() and the recomputeOrderStats() cell indices identical in
       both modes — one row shape, no branching. */
    #scanTable th.col-who, #scanTable td.col-who { display: none; }
    #thisOrderCard.team-on #scanTable th.col-who,
    #thisOrderCard.team-on #scanTable td.col-who { display: table-cell; }
    #scanTable th.col-who { width: 52px; }
    /* Filled = this station, outline = the teammate's. Distinguishable at a
       glance across the table without reading the number. */
    .who-badge { display: inline-block; min-width: 22px; padding: 1px 6px;
                 border-radius: 999px; font-size: .78rem; font-weight: 800;
                 text-align: center; border: 2px solid var(--green);
                 color: var(--green); background: transparent; }
    .who-badge.who-me { background: var(--green); color: #fff; }

    .team-pill { display: inline-block; margin-left: 6px; padding: 1px 8px;
                 border-radius: 999px; background: var(--green); color: #fff;
                 font-size: .68rem; font-weight: 800; letter-spacing: .3px;
                 text-transform: none; vertical-align: 1px; }

    /* Sliding switch — same construction as the one on the Settings page: a
       plain checkbox stretched invisibly over the track, so it keeps focus,
       the space bar and the whole switch as its hit area, with the visible
       parts drawn by the two spans behind it. Sized down a little: it shares
       the order bar with the Leave Assist button. */
    .switch-row { display: flex; align-items: center; gap: 8px; cursor: pointer;
                  font-size: .82rem; font-weight: 600; color: #555;
                  white-space: nowrap; }
    .switch { position: relative; flex: 0 0 auto; width: 44px; height: 24px; }
    .switch input[type="checkbox"] { position: absolute; top: 0; left: 0;
                  width: 100%; height: 100%; margin: 0; opacity: 0; cursor: pointer; }
    .switch-track { position: absolute; top: 0; left: 0; right: 0; bottom: 0;
                  background: #fff; border: 2px solid var(--border); border-radius: 999px;
                  pointer-events: none; transition: background .18s ease, border-color .18s ease; }
    .switch-track::before { content: ""; position: absolute; top: 2px; left: 2px;
                  width: 16px; height: 16px; border-radius: 50%; background: var(--border);
                  transition: transform .18s ease, background .18s ease; }
    .switch input[type="checkbox"]:checked + .switch-track { background: var(--green); border-color: var(--green); }
    .switch input[type="checkbox"]:checked + .switch-track::before { background: #fff; transform: translateX(20px); }
    .switch input[type="checkbox"]:focus-visible + .switch-track { outline: 2px solid var(--blue); outline-offset: 2px; }
    .switch input[type="checkbox"]:disabled { cursor: not-allowed; }
    .switch input[type="checkbox"]:disabled + .switch-track { opacity: .5; }
    /* Respect a reduced-motion preference: the knob jumps instead of sliding. */
    @media (prefers-reduced-motion: reduce) {
      .switch-track, .switch-track::before { transition: none; }
    }

    .assist-row { display: flex; align-items: center; justify-content: space-between;
                  gap: 12px; flex-wrap: wrap; padding: 8px 0;
                  border-top: 1px solid #eee; }
    .assist-row:first-child { border-top: none; padding-top: 0; }
    .assist-order { font-size: 1.15rem; font-weight: 800; color: var(--brown); }
    .assist-meta  { font-size: .8rem; color: #777; }
    .assist-note  { font-size: .8rem; color: #8a6d3b; background: #fcf8e3;
                    border: 1px solid #f0e3b8; border-radius: 6px;
                    padding: 6px 10px; margin-bottom: 10px; }
    .assist-actions { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
    /* End / Cancel sit next to + Assist in a row that already carries the order
       number and its counts, so they are sized down to keep the row on one
       line — and to read as the secondary action they are. */
    .btn-sm { padding: 8px 12px; font-size: .85rem; }
    .btn-assist:disabled, .btn-sm:disabled { opacity: .5; cursor: not-allowed; }

    /* ── Two-column kiosk layout ──────────────────────────────────────────
       The scan station is data-dense and runs on a wide laptop screen, so it
       overrides the shared 980px .container. Scan controls sit on the left,
       the live order list on the right in its own scroll pane — the operator
       never has to scroll the page to reach a row's ✕ button. */
    .container.scan-page { max-width: 1400px; margin: 14px auto 0; }

    /* 42% keeps the End/Cancel buttons on one line with the order number at
       1366px — at 38% they wrapped and cost the left column ~70px of height. */
    .scan-layout {
      display: grid;
      grid-template-columns: minmax(420px, 42%) 1fr;
      gap: 16px;
      align-items: start;
    }
    /* The modals are position:fixed and the <style> block is display:none, so
       neither becomes a grid item — only .scan-col and #thisOrderCard do. */
    .scan-col > .card:last-child { margin-bottom: 0; }

    /* Height-capped so the table scrolls inside itself instead of pushing the
       page down. 210 = site header (87) + container margin (14) + footer
       (40 margin + 63) + slack. Measured at 1920×955. */
    #thisOrderCard {
      display: flex; flex-direction: column;
      max-height: calc(100vh - 210px);
      margin-bottom: 0;
    }
    .order-head { display: flex; align-items: center; justify-content: space-between;
                  gap: 10px; flex-wrap: wrap; margin-bottom: 16px; }
    .order-head-btns { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

    /* ── Camera scanning ──────────────────────────────────────────────────
       Capped so the viewfinder can't push the scan list off a phone screen;
       html5-qrcode sizes the <video> it injects to the container width. */
    #reader { width: 100%; max-width: 420px; margin: 0 auto 14px;
              border-radius: 10px; overflow: hidden; }
    /* Capped so a tall portrait stream can't fill the screen on its own. The
       decoder reads the camera stream, not this element, so trimming the
       preview costs no scanning accuracy. */
    #reader video { display: block; width: 100%; max-height: 300px; object-fit: cover; }
    #scanTableWrap { flex: 1 1 auto; min-height: 0; overflow-y: auto; }
    /* Pinned header row — the table body scrolls under it. */
    #scanTable thead th { position: sticky; top: 0; z-index: 2; }

    /* 1366×768 and other short laptop screens: reclaim vertical space so more
       scan rows fit. On a 635px-tall viewport the site chrome alone eats ~24%
       of the screen, so the header and footer get trimmed too — this <style>
       only loads on scan.php, so no other page is affected. */
    @media (max-height: 850px) {
      html { font-size: 16px; }
      .site-header { padding: 8px 24px; }
      .site-header img { height: 40px; }
      /* renderFoot() sets these inline, so they need !important to trim. */
      .site-footer { margin-top: 12px !important; padding: 8px 16px !important; }
      .container.scan-page { margin-top: 10px; }
      .card { padding: 14px; margin-bottom: 12px; }
      .stat { padding: 5px 8px; }
      .stat .v { font-size: 1.15rem; }
      .stat .k { font-size: .68rem; }
      table.data th, table.data td { padding: 5px 8px; }
      /* The ✕ button's height, not the text, sets the row height — 30px here
         roughly doubles how many rows fit versus the 44px touch target. Width
         stays 3× the height, matching the full-size button's proportions. */
      .btn-x { width: 90px; height: 30px; font-size: 1rem; border-radius: 6px; }
      #scanTable th.col-x { width: 106px; }
      .order-head { margin-bottom: 8px; }
      #btnRecipe { padding: 8px 14px; font-size: .9rem; }
      /* 114 = trimmed header (59) + container margin (10) + trimmed footer
         (12 margin + 31) + 2px slack. Measured at 1366×635. */
      #thisOrderCard { max-height: calc(100vh - 114px); }
    }

    /* Below ~1100px there isn't room for two usable columns — fall back to the
       familiar single-column stack, table still internally scrolled. */
    @media (max-width: 1100px) {
      .scan-layout { grid-template-columns: 1fr; }
      .scan-col > .card:last-child { margin-bottom: 20px; }
      #thisOrderCard { max-height: 55vh; }
      /* Phone with the camera open: viewfinder + stats would leave the scan
         list a few pixels tall inside a 55vh box. Drop the cap and let the
         page scroll — the natural gesture on a phone anyway. */
      #thisOrderCard.cam-on { max-height: none; }
    }
  </style>

</div>

<!-- Camera barcode decoding. Loaded from a CDN with a second host as fallback;
     if both fail the Start Camera button reports it rather than throwing, and
     the rest of the page (laser scanner, manual entry) is unaffected. -->
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"
        onerror="this.onerror=null;this.src='https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js'"></script>
<script>
const state = {
  orderId: <?= $order ? (int)$order['id'] : 'null' ?>,
  // 'owner' (this station started the order), 'assist' (helping another
  // station's order), or 'idle'. Drives which controls the order bar shows.
  role: <?= json_encode($order ? ($isAssist ? 'assist' : 'owner') : 'idle') ?>,
  assistCount: <?= (int)$assistCount ?>,  // helper stations on this order
  // Sticky assist: on until Leave Assist. While it is on and role is 'idle',
  // this station is *between* orders — it joins the next one rather than
  // starting an order of its own.
  assistMode: <?= json_encode($assistMode) ?>,
  // Administrator or supervisor signed in here? Only then does the Assist card
  // draw End / Cancel for another station's order. The server checks the cookie
  // again on the action itself — this only decides what is offered.
  canClose: <?= json_encode($canRemoteClose) ?>,
  // The beep switch in the assist controls: should this station sound the scan
  // tone as it records items while assisting? Server-held per station, so it is
  // the same switch the next time this device assists.
  scanBeep: <?= json_encode($scanBeepOn) ?>,
  pendingProduce:    null,  // { barcode, generic_name } awaiting weight entry
  pendingUnknownUPC: null,  // { barcode } awaiting manual generic name
  weightDigits:      '',    // adding-machine buffer (manual lb+oz entry)
  weightDecimal:     null,  // decimal-pounds string when in scale/decimal mode
  pendingWeight:     null,  // lbs from the scale, awaiting a PLU (scale-first)
  lastScaleLbs:      null,  // last accepted scale reading, for repeat detection
  lastScaleAt:       0,     // ms timestamp of that reading
  scanner:           null,  // Html5Qrcode instance while the camera is running
  camPaused:         false, // post-decode cooldown, so one barcode reads once
  lastCamCode:       null,  // last decoded value, for repeat suppression
  lastCamAt:         0,     // ms timestamp of that decode
};

// Tare (in pounds) subtracted from each entered produce weight; set on the
// Settings page in ounces.
const TARE_LBS = <?= json_encode($tareLbs) ?>;

// Settings → Ignore Unknown Items. When true, a packaged UPC that neither the
// lookup cache nor Open Food Facts can name is recorded under the placeholder
// name "Unidentified" instead of opening the name-entry window: the item is
// counted, the barcode is cached against the placeholder, and the volunteer
// bags it and moves on. lookupBarcode() does that work and answers ok=true with
// unidentified=true, so the station only has to say what happened.
//
// The naming is not lost, only deferred: with the switch off, a scan of a UPC
// still carrying the placeholder opens the Identify window (see openNameModal),
// and the name typed there replaces it for that UPC and takes that UPC's scan
// history with it.
const IGNORE_UNKNOWN = <?= json_encode($ignoreUnknown) ?>;

// Scans already recorded against the open order, so a page refresh restores the
// list (and its ✕ buttons) instead of showing an empty table. Ascending id
// order — appendRow prepends, so replaying them yields newest-on-top.
const BOOT_SCANS = <?= json_encode($bootScans, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;

// This device's station token, so a scan row can be marked as mine vs. my
// teammate's without the server having to say which.
const MY_STATION = <?= json_encode($myStation) ?>;

// Audible alerts for operator-action-required events, rendered with Web
// Audio so no asset file is needed. Two distinct sounds so the operator can
// tell them apart without looking at the screen:
//   alertBeep()  — rising two-tone chirp: produce weight entry.
//   errorBeep()  — harsh descending buzz: unknown UPC needing a manual name.
//   ignoreBeep() — rising swoop: unknown UPC skipped, nothing to do.
//   alarmBeep()  — repeating two-tone siren: a recalled item was scanned.
let audioCtx = null;

// Chrome will not let an AudioContext start until the page has seen a real
// user gesture. A scan station that has only been *loaded* — auto-reconnected
// to its scale, nobody having clicked anything yet — therefore drops its first
// beeps silently, which is worst for the one alert that fires without anyone
// touching the page: "make sure scale is on". Unlock on the first gesture of
// any kind, including the barcode scanner's own keystrokes.
function unlockAudio() {
  try {
    if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    if (audioCtx.state === 'suspended') audioCtx.resume();
  } catch (e) { /* audio unavailable — fail silently */ }
}
['pointerdown', 'keydown', 'touchstart'].forEach((ev) =>
  window.addEventListener(ev, unlockAudio, { once: true, capture: true }));

function playTones(tones, level) {
  const peak = level || 0.30;
  try {
    if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    if (audioCtx.state === 'suspended') audioCtx.resume();
    const t0 = audioCtx.currentTime;
    for (const [offset, freq, dur, type] of tones) {
      const osc  = audioCtx.createOscillator();
      const gain = audioCtx.createGain();
      osc.type = type || 'square';
      osc.frequency.value = freq;
      const start = t0 + offset;
      gain.gain.setValueAtTime(0, start);
      gain.gain.linearRampToValueAtTime(peak, start + 0.012);
      gain.gain.setValueAtTime(peak, start + dur - 0.02);
      gain.gain.linearRampToValueAtTime(0, start + dur);
      osc.connect(gain); gain.connect(audioCtx.destination);
      osc.start(start); osc.stop(start + dur + 0.01);
    }
  } catch (e) { /* audio unavailable — fail silently */ }
}
function alertBeep() {
  playTones([[0, 880, 0.18], [0.22, 1175, 0.22]]);
}
function errorBeep() {
  playTones([[0, 330, 0.16, 'sawtooth'], [0.20, 220, 0.34, 'sawtooth']]);
}
// Falling pair — alertBeep() played backwards. Something came back off the
// order, which is not an error (nothing went wrong, the operator asked for it)
// and not a save, so it can't share a sound with either.
function removeBeep() {
  playTones([[0, 1175, 0.12], [0.14, 880, 0.18]]);
}
// Rising glide — the arcade "jump" swoop. Deliberately unlike errorBeep()'s
// descending buzz: nothing went wrong and nothing is owed, the operator can
// set the item aside and scan the next one. playTones() holds each note at a
// fixed pitch, so this glide needs its own oscillator with a frequency ramp.
function ignoreBeep() {
  try {
    if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    if (audioCtx.state === 'suspended') audioCtx.resume();
    const t0  = audioCtx.currentTime;
    const dur = 0.22;
    const osc  = audioCtx.createOscillator();
    const gain = audioCtx.createGain();
    osc.type = 'square';
    osc.frequency.setValueAtTime(330, t0);                        // ~E4
    osc.frequency.exponentialRampToValueAtTime(1320, t0 + dur);   // ~E6, two octaves up
    gain.gain.setValueAtTime(0, t0);
    gain.gain.linearRampToValueAtTime(0.30, t0 + 0.012);
    gain.gain.setValueAtTime(0.30, t0 + dur - 0.06);
    gain.gain.linearRampToValueAtTime(0, t0 + dur);
    osc.connect(gain); gain.connect(audioCtx.destination);
    osc.start(t0); osc.stop(t0 + dur + 0.01);
  } catch (e) { /* audio unavailable — fail silently */ }
}
// Single crisp tone: the camera registered a barcode. A laser scanner beeps on
// its own; the camera has no hardware feedback, so this stands in for it.
function scanBeep() {
  playTones([[0, 1046, 0.12]]);   // ~C6
}
// Emergency two-tone siren: a recalled item is on the counter. Six alternating
// notes over ~1.4s, far longer and more insistent than any other sound here, so
// it can't be mistaken for the buzz that means "unknown UPC" — that one asks
// for a name, this one means stop and take the package back. Louder too: the
// operator may be several feet from the screen with the modal behind them.
function alarmBeep() {
  const tones = [];
  for (let i = 0; i < 6; i++) {
    tones.push([i * 0.24, i % 2 ? 660 : 990, 0.22]);   // E5 / B5, alternating
  }
  playTones(tones, 0.45);
}

const $ = (id) => document.getElementById(id);
const barcodeInput = $('barcodeInput');

function modalOpen() {
  return $('weightPrompt').style.display !== 'none'
      || $('namePrompt').style.display   !== 'none'
      || $('pluPrompt').style.display    !== 'none'
      || $('recallPrompt').style.display !== 'none';
}
function refocus() {
  // Never while the camera is running: on a phone, focusing a text input opens
  // the soft keyboard, which covers the viewfinder the operator is aiming. The
  // camera is the input in that mode, so the field doesn't need focus at all.
  if (!modalOpen() && !state.scanner) {
    barcodeInput.focus();
    barcodeInput.select();
  }
}
// Re-grab focus aggressively so the scanner always lands in the right field,
// even after the user clicks Start Order, scrolls, or taps elsewhere.
document.addEventListener('click', refocus);
document.addEventListener('focusin', (e) => {
  const safe = e.target === barcodeInput
            || e.target.closest('#weightPrompt')
            || e.target.closest('#namePrompt')
            || e.target.closest('#pluPrompt')
            || e.target.closest('#recallPrompt')
            || e.target.closest('#orderBar')
            || e.target.closest('#assistCard')
            || e.target.tagName === 'A';
  if (!safe) setTimeout(refocus, 0);
});
window.addEventListener('load', refocus);

// Periodic safety net — some scanners send a fast burst right after page load
// or after Start Order while focus is still on the button.
setInterval(() => {
  if (document.activeElement !== barcodeInput
      && !modalOpen()
      && document.activeElement.tagName !== 'INPUT') {
    refocus();
  }
}, 500);

// Outstanding non-sync requests. The team-sync poll skips a tick while any are
// in flight, so a redraw can never land in the middle of a record, a delete, or
// a lookup the operator is waiting on. Counted here rather than at each call
// site so a new endpoint can't forget to opt in.
let inFlight = 0;

async function postJson(url, body) {
  const isSync = body && body.action === 'sync';
  if (!isSync) inFlight++;
  try {
    const r = await fetch(url, {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify(body),
    });
    return await r.json();
  } finally {
    if (!isSync) inFlight--;
  }
}

async function startOrderIfNeeded() {
  // Non-null while assisting too, so a helper's scans go to the shared order
  // instead of silently opening a competing one.
  if (state.orderId) return true;
  // In assist mode between orders: this item belongs to the next order, not to
  // one of this station's own. Join now — usually the poll has already done it
  // and this never runs, but an operator can beat the 2.5s tick. The server
  // refuses 'start' in this state anyway; this turns that refusal into the
  // join the operator meant.
  if (state.assistMode) return await joinNextOrderToAssist();
  const r = await postJson('../api_order.php', {action:'start'});
  if (!r.ok) { flash('Could not start order: ' + (r.error || 'unknown'), 'error'); return false; }
  state.orderId = r.order_id;
  $('assistCard').style.display = 'none';   // this station is no longer free
  applyRole('owner', r.order_id, r.started_at, 0);
  resetTable();
  return true;
}

// After ending or cancelling, reload the page so the "This Order" table and
// stats start from a clean slate. The query params carry the confirmation
// message across the refresh (rendered server-side into #orderStartLabel).
$('btnEnd').addEventListener('click', async () => {
  const r = await postJson('../api_order.php', {action:'end'});
  if (!r.ok) { alert(r.error || 'Could not end order'); return; }
  location.href = location.pathname
    + '?closed=' + encodeURIComponent(r.order_id)
    + '&at=' + encodeURIComponent(r.ended_at || '');
});

$('btnCancel').addEventListener('click', async () => {
  const r = await postJson('../api_order.php', {action:'cancel'});
  if (!r.ok) { alert(r.error || 'Could not cancel order'); return; }
  location.href = location.pathname + '?cancelled=' + encodeURIComponent(r.order_id);
});

// The confirmation is already rendered into the page, so drop the query
// params — a later manual refresh shouldn't re-show a stale message.
if (location.search) history.replaceState(null, '', location.pathname);

// ── Camera scanning ────────────────────────────────────────────────────────
// A phone pointed at a barcode, feeding the same handleScan() the laser
// scanner feeds — so the 990001/990002/990003 command codes, auto-start, the unknown-UPC
// prompt and the produce-weight flow all work identically from the camera.
// Mainly for team scanning: a volunteer with a phone can assist an order
// running on the wired station.

// The camera needs a secure context (https, or localhost). Say so up front
// rather than letting getUserMedia fail with a bare "NotAllowedError".
function cameraAvailable() {
  if (typeof Html5Qrcode === 'undefined') {
    flash('Camera library did not load — check the network connection, then reload.', 'error');
    return false;
  }
  if (!window.isSecureContext) {
    flash('The camera needs a secure (https) connection on this device.', 'error');
    return false;
  }
  return true;
}

$('camStart').addEventListener('click', async () => {
  if (state.scanner) return;
  if (!cameraAvailable()) return;
  $('camStart').disabled = true;   // pre-disable: starting takes a moment
  const reader = $('reader');
  reader.style.display = 'block';
  try {
    const scanner = new Html5Qrcode('reader');
    await scanner.start(
      { facingMode: 'environment' },   // rear camera on a phone
      {
        fps: 10,
        qrbox: { width: 280, height: 160 },
        // Retail barcodes only. Narrowing the formats measurably improves both
        // decode speed and the false-read rate versus scanning everything.
        formatsToSupport: [
          Html5QrcodeSupportedFormats.UPC_A,
          Html5QrcodeSupportedFormats.UPC_E,
          Html5QrcodeSupportedFormats.EAN_13,
          Html5QrcodeSupportedFormats.EAN_8,
          Html5QrcodeSupportedFormats.CODE_128,
        ],
      },
      onCameraDecode,
      () => {}   // per-frame "no barcode in view" — expected, so swallow it
    );
    // Only now is the camera actually live; setting state.scanner earlier would
    // suppress refocus() while the laser field was still the only input.
    state.scanner = scanner;
    $('thisOrderCard').classList.add('cam-on');
    $('camStop').disabled = false;
    // The soft keyboard would cover the viewfinder — let the camera be the input.
    barcodeInput.blur();
    flash('Camera on — point it at a barcode.', 'info');
  } catch (e) {
    reader.style.display = 'none';
    $('camStart').disabled = false;
    flash('Could not start the camera: ' + (e && e.message ? e.message : e), 'error');
  }
});

async function stopCamera() {
  const scanner = state.scanner;
  if (!scanner) return;
  // Cleared first so refocus() is free again even if stop() throws.
  state.scanner = null;
  try { await scanner.stop(); } catch (_) {}
  try { scanner.clear(); } catch (_) {}
  $('reader').style.display = 'none';
  $('thisOrderCard').classList.remove('cam-on');
  $('camStart').disabled = false;
  $('camStop').disabled  = true;
}

$('camStop').addEventListener('click', async () => {
  $('camStop').disabled = true;
  await stopCamera();
  refocus();   // hand the laser scanner its field back
});

// Release the camera on navigation (End / Cancel reload the page). Best effort:
// stop() is async and may not finish, but the browser tears the stream down
// with the document anyway — this just makes the indicator light go out sooner.
window.addEventListener('pagehide', () => { if (state.scanner) stopCamera(); });

function onCameraDecode(text) {
  if (state.camPaused) return;
  // A modal is a question the operator is answering — a weight, or the name of
  // an unknown UPC. The camera stays live behind it (stopping and restarting
  // the stream is slow and drops the preview), but its reads are ignored until
  // the answer is in, so a barcode still in frame can't record a second item.
  if (modalOpen()) return;
  const code = String(text).trim();
  if (!code) return;
  // A camera re-decodes the same barcode many times a second while it stays in
  // frame. Pause briefly after each accepted read, and additionally suppress an
  // identical code for 1.5s, so one item is recorded once.
  const now = Date.now();
  if (state.lastCamCode === code && (now - state.lastCamAt) < 1500) return;
  state.lastCamCode = code;
  state.lastCamAt   = now;
  state.camPaused   = true;
  setTimeout(() => { state.camPaused = false; }, 1500);
  scanBeep();
  handleScan(code);
}

// ── AI kitchen help: recipe from the order / prep tips per item ──────────
// Both features fetch text from api_kitchen.php and route it to the printer
// via a print-formatted popup window. The window must be opened synchronously
// in the click handler (popup blockers reject async window.open), so it shows
// a "working" note until the AI text arrives, then triggers the print dialog.
function openPrintWindow(title) {
  const w = window.open('', '_blank');
  if (!w) {
    flash('Popup blocked — allow popups for this site so the printout can open.', 'error');
    return null;
  }
  w.document.write('<!doctype html><title>' + escape(title) + '</title>'
    + '<body style="font:16px Georgia,serif; padding:40px; color:#333;">'
    + 'Asking the AI… this can take a few seconds.</body>');
  w.document.close();
  return w;
}

function printText(w, title, text) {
  if (w.closed) return;  // operator closed the tab while waiting
  w.document.open();
  w.document.write('<!doctype html><html><head><title>' + escape(title) + '</title><style>'
    + 'body { font: 15px/1.6 Georgia, serif; color: #222; margin: 40px auto; max-width: 680px; }'
    + 'h1 { font-size: 1.5rem; margin-bottom: 4px; }'
    + '.meta { font-size: .8rem; color: #777; margin-bottom: 20px; }'
    + 'pre { white-space: pre-wrap; font: inherit; }'
    + '.foot { font-size: .75rem; color: #777; border-top: 1px solid #ccc; margin-top: 24px; padding-top: 8px; }'
    + '</style></head><body>'
    + '<h1>' + escape(title) + '</h1>'
    + '<div class="meta">' + new Date().toLocaleString() + '</div>'
    + '<pre>' + escape(text) + '</pre>'
    + '<div class="foot">AI-generated suggestion — use your judgment on quantities and cooking times.</div>'
    + '</body></html>');
  w.document.close();
  w.focus();
  // Same-origin about:blank renders synchronously after close(); the short
  // delay just lets fonts/layout settle before the print dialog opens.
  setTimeout(() => { try { w.print(); } catch (e) { /* window closed */ } }, 300);
}

$('btnRecipe').addEventListener('click', async () => {
  if (!state.orderId) { flash('No open order — scan items first.', 'info'); return; }
  const w = openPrintWindow('Recipe Suggestion');
  if (!w) return;
  const btn = $('btnRecipe');
  btn.disabled = true;
  try {
    const r = await postJson('../api_kitchen.php', {action: 'recipe'});
    if (!r.ok) { w.close(); flash(r.error || 'Could not get a recipe', 'error'); return; }
    printText(w, 'Recipe Suggestion — Order #' + r.order_id, r.text);
  } catch (e) {
    w.close();
    flash('Recipe request failed: ' + e.message, 'error');
  } finally {
    btn.disabled = false;
    refocus();
  }
});

// Delegated: rows are re-rendered per scan, so listen on the table instead of
// binding each link.
$('scanTable').addEventListener('click', async (e) => {
  const a = e.target.closest('a.prep-link');
  if (!a) return;
  e.preventDefault();
  const name = a.dataset.name;
  const w = openPrintWindow('How to Prepare & Serve');
  if (!w) return;
  try {
    const r = await postJson('../api_kitchen.php', {action: 'prepare', generic_name: name});
    if (!r.ok) { w.close(); flash(r.error || 'Could not get preparation tips', 'error'); return; }
    printText(w, 'How to Prepare & Serve: ' + name, r.text);
  } catch (e2) {
    w.close();
    flash('Preparation-tips request failed: ' + e2.message, 'error');
  } finally {
    refocus();
  }
});

barcodeInput.addEventListener('keydown', async (e) => {
  if (e.key === 'Escape') {
    barcodeInput.value = '';
    hideNameMatches();
    return;
  }
  // Accept either Enter or Tab as the scanner's terminator.
  if (e.key !== 'Enter' && e.key !== 'Tab') return;
  e.preventDefault();
  const code = barcodeInput.value.trim();
  if (!code) return;
  // A scale transmission lands in this field whenever no modal has focus. It
  // always carries a unit suffix ("1.120lb"), and no barcode or lookup name
  // looks like that, so it can be split off before the name-query branch.
  if (isScaleTransmission(code)) {
    barcodeInput.value = '';
    hideNameMatches();
    await handleScaleWeight(code);
    return;
  }
  // Letters present → this is a name query, not a barcode. Accept it only
  // if it narrows the lookup tables to exactly one name.
  if (isNameQuery(code)) {
    await tryAcceptName(code);
    return;
  }
  barcodeInput.value = '';
  hideNameMatches();
  await handleScan(code);
});

// ── Add-by-name type-ahead ───────────────────────────────────────────────
// Typing letters into the barcode field searches the lookup-table names
// (produce codes + cached UPCs). Once the text matches exactly one name,
// Enter or Tab records that item via its PLU/UPC, same as a scan. A scanner
// burst is all digits, so it never triggers this path.
let nameMatches = [];      // matches currently rendered in the list
let nameTotal = 0;         // total distinct names matched (may exceed list)
let nameSearchTimer = null;
let nameSearchSeq = 0;     // discard out-of-order responses
const nameBox = $('nameMatches');

function isNameQuery(v) { return /[a-z]/i.test(v); }

function hideNameMatches() {
  nameBox.style.display = 'none';
  nameBox.innerHTML = '';
  nameMatches = [];
  nameTotal = 0;
}

barcodeInput.addEventListener('input', () => {
  const v = barcodeInput.value.trim();
  clearTimeout(nameSearchTimer);
  // "1.120lb" arrives one keystroke at a time; its last two characters are
  // letters, which would otherwise fire a name search for a half-typed weight.
  if (isScaleTyping(v)) { hideNameMatches(); return; }
  if (!isNameQuery(v) || v.length < 2) { hideNameMatches(); return; }
  nameSearchTimer = setTimeout(async () => {
    const seq = ++nameSearchSeq;
    const r = await postJson('../api_scan.php', {action:'search', q: v});
    if (seq !== nameSearchSeq) return;                       // superseded
    if (!isNameQuery(barcodeInput.value.trim())) return;     // cleared/scanned meanwhile
    if (!r.ok) {
      // Distinguish a server/API failure from a genuine zero-match result —
      // e.g. a stale api_scan.php without the search action answers
      // "Missing barcode", which must not read as "no such item".
      nameMatches = [];
      nameTotal = 0;
      nameBox.innerHTML = '<div class="nm-hint">⚠ Name search unavailable: '
        + escape(r.error || 'server error') + '</div>';
      nameBox.style.display = 'block';
      return;
    }
    nameMatches = r.matches || [];
    nameTotal   = r.total || nameMatches.length;
    renderNameMatches();
  }, 200);
});

function renderNameMatches() {
  if (!nameTotal) {
    nameBox.innerHTML = '<div class="nm-hint">No matching names in the lookup tables.</div>';
    nameBox.style.display = 'block';
    return;
  }
  const unique = nameTotal === 1;
  nameBox.innerHTML = nameMatches.map((m, i) =>
    `<div class="nm-row${unique ? ' nm-unique' : ''}" onclick="acceptNameMatch(${i})">
       <div>
         <div class="nm-name">${escape(m.name)}</div>
         ${m.brand ? '<div class="nm-brand">' + escape(m.brand) + '</div>' : ''}
       </div>
       <div class="nm-code">${escape(m.code)}</div>
     </div>`).join('')
    + `<div class="nm-hint">${unique
        ? '↵ Press Enter or Tab to add this item'
        : nameTotal + ' matches — keep typing to narrow to one, or tap the item.'}</div>`;
  nameBox.style.display = 'block';
}

// Enter/Tab on a name query: re-query so the decision is never based on a
// stale (still-debouncing) result, then accept only a unique match.
async function tryAcceptName(q) {
  clearTimeout(nameSearchTimer);
  const r = await postJson('../api_scan.php', {action:'search', q});
  if (!r.ok) { flash(r.error || 'Name search failed', 'error'); return; }
  nameMatches = r.matches || [];
  nameTotal   = r.total || nameMatches.length;
  if (nameTotal === 1) {
    await acceptNameMatch(0);
    return;
  }
  if (!nameTotal) {
    // Dead end — flash('error') buzzes and clears the field, so the next
    // scan starts clean instead of appending to the failed query.
    flash('No lookup names match "' + q + '".', 'error');
    return;
  }
  renderNameMatches();
  flash(nameTotal + ' names match — keep typing to narrow to one.', 'info');
}

async function acceptNameMatch(i) {
  const m = nameMatches[i];
  if (!m) return;
  barcodeInput.value = '';
  hideNameMatches();
  await handleScan(m.code);   // records via the item's PLU/UPC, same as a scan
  refocus();
}

// Visual indicator: highlight the input when it has focus so the operator
// instantly knows the scanner will land in the right place.
barcodeInput.addEventListener('focus', () => {
  barcodeInput.style.borderColor = 'var(--green)';
  barcodeInput.style.boxShadow = '0 0 0 3px rgba(139,175,58,.25)';
});
barcodeInput.addEventListener('blur', () => {
  barcodeInput.style.borderColor = '';
  barcodeInput.style.boxShadow = '';
});

// ── Weighed produce: either half may arrive first ────────────────────────
// A weighed produce entry is a PLU plus a weight, and the operator can supply
// them in either order — no setting picks one, because the two are told apart
// by what arrives. The scale types decimal pounds with an "lb" suffix; a PLU is
// bare digits. Nothing else on this page looks like either.
//
//   PLU first  → the "Weight required" window opens and the scale (or the
//                keypad) fills it in, exactly as it always has.
//   Scale first→ the reading is held here, the PLU window opens, and both are
//                recorded together once the operator scans the code.
//
// Either way the platform then has to be cleared: the scale only re-arms after
// it returns to zero, so one item can't be sent twice.
const SCALE_LB_RE    = /^-?\d*\.?\d+\s*(lb|lbs)\.?$/i;
const SCALE_OTHER_RE = /^-?\d*\.?\d+\s*(kg|g|oz)\.?$/i;
// Two readings this close together are the same settled weight sent twice, not
// two items — clearing the platform and settling a new item takes far longer.
const SCALE_ECHO_MS  = 3000;
const SCALE_ECHO_LBS = 0.0005;

function isScaleTransmission(v) { return SCALE_LB_RE.test(v) || SCALE_OTHER_RE.test(v); }
// Partial transmission still being typed: digits first, letters only at the end.
function isScaleTyping(v) { return /^-?[\d.]+\s*[a-z]*\.?$/i.test(v) && /\d/.test(v); }

function parseScaleWeight(v) {
  if (!SCALE_LB_RE.test(String(v).trim())) return null;
  const n = parseFloat(v);
  return isFinite(n) ? n : null;
}

// Pounds shown back to the operator both ways: decimal pounds is what gets
// stored, lb + oz is what they can check against the scale's own display.
function fmtLbs(lbs) {
  const whole = Math.floor(lbs);
  return lbs.toFixed(2) + ' lb  (' + whole + ' lb ' + ((lbs - whole) * 16).toFixed(1) + ' oz)';
}

function isScaleEcho(lbs) {
  return state.lastScaleLbs !== null
      && Math.abs(lbs - state.lastScaleLbs) < SCALE_ECHO_LBS
      && (Date.now() - state.lastScaleAt) < SCALE_ECHO_MS;
}

async function handleScaleWeight(raw) {
  // Wrong unit on the scale. Recording a kilogram reading as pounds would
  // understate produce by more than half, so refuse it outright.
  if (SCALE_OTHER_RE.test(raw)) {
    flash('Scale sent "' + raw + '" — switch the scale to pounds (lb). '
        + 'Weights are recorded in pounds.', 'error');
    return;
  }
  const lbs = parseScaleWeight(raw);
  if (lbs === null) return;
  if (lbs <= 0) {
    flash('Scale sent ' + raw + ' — place the item on the platform and let the '
        + 'reading settle.', 'error');
    return;
  }
  await acceptScaleWeight(lbs, { echoCheck: true });
}

// Everything a captured weight goes through, whichever way it arrived: typed
// by a keyboard-wedge cable, or decoded from a WebHID scale report. Keeping
// this transport-agnostic is what lets a USB scale reuse the whole scale-first
// flow — PLU window, mismatch handling, recording — without duplicating it.
//
// `echoCheck` guards the wedge path, where one settled reading is sometimes
// transmitted twice. The HID path passes false: it already refuses to capture
// again until the platform returns to zero, which is a stronger rule, and the
// time-based echo window would wrongly swallow a second item that happens to
// weigh the same.
async function acceptScaleWeight(lbs, opts) {
  const echoCheck = !opts || opts.echoCheck !== false;
  // The weight window is already open for a scanned item — the reading belongs
  // to that item, so fill it in rather than starting a second entry.
  if (state.pendingProduce && $('weightPrompt').style.display !== 'none') {
    state.weightDigits  = '';
    state.weightDecimal = String(lbs);
    renderWeight();
    await submitWeight();
    return;
  }
  if (echoCheck && isScaleEcho(lbs)) {
    state.lastScaleAt = Date.now();   // keep the echo window alive
    return;
  }
  // A weight is already held, waiting for its PLU. Replacing it would beep for
  // a second item, silently drop the first, and leave the operator typing a
  // code against a number that had changed behind the window. The held entry
  // has to be finished or discarded first — this is the last line of defence,
  // and the scale path refuses earlier so it can explain itself on the strip.
  if (state.pendingWeight !== null) return;
  // First item of a session can be a weighed one, so open the order here too.
  if (!(await startOrderIfNeeded())) return;
  state.pendingWeight = lbs;
  state.lastScaleLbs  = lbs;
  state.lastScaleAt   = Date.now();
  openPluModal(lbs);
}

// ── USB scale over WebHID (DYMO M25 and other HID POS scales) ─────────────
// Postal/shipping scales that work with USPS postage software implement the
// HID Point of Sale "Scale" usage page (0x8D). They are not keyboards and they
// type nothing: they stream input reports carrying a status byte, a unit, a
// power-of-ten exponent and a 16-bit raw weight. So there is no focus to
// manage, no text to parse, no terminator to worry about, and the scale itself
// reports when the reading has settled — no software settle detection needed.
//
// Because the report layout is a published standard rather than a vendor
// format, any HID-compliant scale works with this code, not just the M25.
const HID_SCALE_USAGE_PAGE = 0x8d;

// HID POS scale status codes.
const HID_ST_FAULT      = 1;
const HID_ST_ZERO       = 2;   // stable at centre of zero — platform is clear
const HID_ST_MOTION     = 3;
const HID_ST_STABLE     = 4;   // settled with weight on the platform
const HID_ST_UNDER_ZERO = 5;
const HID_ST_OVER_LIMIT = 6;
const HID_ST_NEEDS_CAL  = 7;
const HID_ST_NEEDS_ZERO = 8;

// HID POS weight-unit codes → multiplier into pounds. Whatever unit the
// operator leaves the scale in, we store pounds — so unlike the wedge path
// there is no "scale left in kilograms" failure to guard against.
//
// These codes are 1-based and easy to get wrong by one, which is not a
// harmless slip: mistaking ounce (11) for troy ounce inflates every weight by
// about 10% and nothing on screen looks broken. Anchor points from the DYMO
// implementations — 0x02 is gram and 0x0B is ounce.
// Deliberately partial: carats, taels and tons can't come off a pantry scale,
// and an unmapped code raises a visible error rather than a silent wrong
// number.
const HID_UNIT_TO_LBS = {
  1:  1 / 453592.37,    // milligram
  2:  1 / 453.59237,    // gram
  3:  2.20462262,       // kilogram
  6:  1 / 7000,         // grain
  10: 1 / 14.5833333,   // troy ounce
  11: 1 / 16,           // ounce
  12: 1,                // pound
};

// Below this the platform counts as empty: idle readings are ignored and the
// scale is rearmed for the next item.
const HID_MIN_CAPTURE_LBS = 0.02;

// A single "stable" report is not enough to trust. Scales in this class raise
// the stable flag while the reading is still creeping — produce settling in a
// bag, a platform rebounding after an item lands, a shopper's hand still
// resting on it — so capturing on the first stable report banks a weight that
// is still moving. Instead the reading has to hold near where it started, for
// a sustained window and across several reports, before it counts as final.
//
// There are two settle paths, because the evidence differs in strength:
//
//   • Every report reads *identically* — the value is parked, which is what a
//     genuinely settled item looks like. Capture quickly.
//   • The value jitters inside the tolerance — weaker evidence. Hold longer.
//
// A creeping weight changes on every report by definition, so it can never
// qualify for the fast path. That is what lets this be responsive without
// giving anything back on accuracy: speed is granted only to readings that
// have actually stopped.
//
// Raise these if weights still land low; the cost is only a later beep.
const HID_SETTLE_FAST_MS = 250;     // hold when consecutive reads are identical
const HID_SETTLE_MS      = 700;     // hold when the value is still jittering
// Minimum reports either path must see — and on a slow-reporting scale this,
// not the timers above, is what you actually wait for. Measured on a DYMO M25:
// it emits one report per second for a stationary item, so each report here
// costs a full second. Two means the reading must hold unchanged across a
// one-second gap before it counts, which is a real confirmation; three was two
// seconds of it and felt broken. One would be no confirmation at all — that is
// the setting that let a still-creeping weight get recorded.
const HID_SETTLE_REPORTS = 2;
// The jittery path needs one more, because its evidence is weaker: the value is
// changing, just not by much. At 1 Hz two samples cannot tell a settled reading
// from a slow creep — a weight climbing under the drift tolerance would sail
// through on the second report. Three forces the creep to accumulate past the
// tolerance and restart the window, which is what stops it.
const HID_SETTLE_REPORTS_JITTER = 3;
// Drift allowed from the anchor before the window restarts. At one division
// (0.00625 lb on an M25) a reading flickering a division either side of centre
// spans two divisions and restarts the window constantly, which is a stall
// dressed up as caution. Two-and-a-bit divisions absorbs that flicker while
// still catching creep: real creep runs far faster than 0.015 lb per window.
const HID_SETTLE_TOL_LBS = 0.015;
// Smallest change that counts as "the weight moved", for the idle watchdog.
// Comfortably above the M25's 0.1 oz division so a jittering last digit does
// not read as activity.
const HID_CHANGE_LBS = 0.005;
// The M25 powers itself down to save its batteries, and a scale that has shut
// off looks exactly like one nobody has touched — both simply stop changing.
// After three quiet minutes, say so out loud rather than letting a volunteer
// set produce on a dead scale and wonder why nothing happens.
const SCALE_IDLE_MS = 180000;
// Separately: a scale that has produced no usable reading at all since the page
// connected to it is almost always simply switched off. That deserves an answer
// in seconds — nobody opening the page should sit through the three-minute idle
// timeout to be told the scale isn't on.
const SCALE_STARTUP_MS = 12000;

const hid = {
  device:       null,
  lastLbs:      null,   // last reported weight, for change detection
  lastChangeAt: 0,      // ms timestamp of the last change
  idleWarned:   false,  // the "make sure scale is on" message is showing
  armed:        true,   // false after a capture, until the platform clears
  settleAnchor: null,   // weight the current settle window opened at
  settleSince:  0,      // when it opened
  settleCount:  0,      // stable reports seen inside it
  settleLast:   null,   // previous reading, for spotting identical repeats
  settleExact:  0,      // consecutive reports reading exactly the same
  settleSign:   0,      // direction of the last change: +1, -1, or 0
  settleMono:   0,      // consecutive changes in that same direction
  connectedAt:  0,      // when this device was opened
  everRead:     false,  // a usable weight has arrived since connecting
  motionStreak: 0,      // consecutive non-stable reports
};


// Any reading that isn't stable-and-holding drops the settle window, so a
// half-settled weight can never carry over into the next item's.
function resetSettle() {
  hid.settleAnchor = null;
  hid.settleSince  = 0;
  hid.settleCount  = 0;
  hid.settleLast   = null;
  hid.settleExact  = 0;
  hid.settleSign   = 0;
  hid.settleMono   = 0;
}

function scaleSupported() { return 'hid' in navigator; }

function setScaleStatus(text, kind) {
  const el = $('scaleStatus');
  if (!el) return;
  el.textContent = text || '';
  el.className = 'scale-status' + (kind ? ' ' + kind : '');
}

// Per-report status text. While "make sure scale is on" is showing, a sleeping
// scale's own frames must not paint over it — that message is the answer to
// what those frames mean, and it stays until a real weight change clears it.
function setScaleStatusLive(text, kind) {
  if (hid.idleWarned) return;
  setScaleStatus(text, kind);
}

function parseHidScaleReport(data) {
  // status, unit, exponent, weight LSB, weight MSB. The report ID is carried
  // separately by the event, so byte 0 here is already the status.
  if (!data || data.byteLength < 5) return null;
  const status = data.getUint8(0);
  const unit   = data.getUint8(1);
  const exp    = data.getInt8(2);
  const raw    = data.getUint16(3, true);
  const factor = HID_UNIT_TO_LBS[unit];
  // `raw` is carried through so the caller can tell an empty frame from a real
  // measurement in a unit we don't map — they need very different answers.
  if (factor === undefined) return { status: status, unit: unit, raw: raw, lbs: null };
  return { status: status, unit: unit, raw: raw,
           lbs: raw * Math.pow(10, exp) * factor };
}

// Only a *changed* weight proves someone is using the scale, and that is what
// resets the idle countdown — a scale sitting untouched is about to sleep.
//
// A report we cannot turn into a weight is not activity. A scale that has
// powered itself down keeps emitting empty frames, and counting those as use
// would hold the countdown open forever, so the "make sure scale is on" message
// could never appear — which is exactly the state it exists to report.
function scaleHeartbeat(lbs) {
  if (lbs === null) return;
  hid.everRead = true;   // the scale is on and talking sense
  const changed = (hid.lastLbs === null)
               || Math.abs(lbs - hid.lastLbs) >= HID_CHANGE_LBS;
  hid.lastLbs = lbs;
  if (!changed) return;
  hid.lastChangeAt = Date.now();
  if (hid.idleWarned) {
    hid.idleWarned = false;
    setScaleStatus('⚖ Scale ready', 'ok');
  }
}

async function onScaleReport(e) {
  const r = parseHidScaleReport(e.data);
  if (!r) return;
  scaleHeartbeat(r.lbs);

  // A scale that has powered itself down keeps the USB connection up and emits
  // empty frames — status 0, unit 0, weight 0. Those are neither a fault nor a
  // reading, so say nothing about them and let the idle watchdog be the thing
  // that speaks. Reading them as a unit problem is what used to put "set it to
  // lb or oz" on screen every time the scale went to sleep.
  if (!r.status || r.status > HID_ST_NEEDS_ZERO) return;

  if (r.status === HID_ST_OVER_LIMIT) {
    setScaleStatusLive('⚠ Over capacity — take the item off the scale.', 'warn');
    return;
  }
  if (r.status === HID_ST_FAULT || r.status === HID_ST_NEEDS_CAL) {
    setScaleStatusLive('⚠ Scale fault — switch it off and on again.', 'warn');
    return;
  }
  if (r.status === HID_ST_NEEDS_ZERO || r.status === HID_ST_UNDER_ZERO) {
    setScaleStatusLive('⚠ Scale needs re-zeroing — clear the platform and press its Tare/Zero key.', 'warn');
    return;
  }
  // An unconvertible reading of *zero* is an empty frame — a scale that is off
  // or asleep, which reports a unit of 0 and no weight. There is no unit for
  // the operator to change, so saying "set it to lb or oz" is both wrong and
  // the only thing they'd see on a freshly opened page. Stay quiet and let the
  // watchdog below report what is actually wrong: the scale isn't on.
  //
  // A non-zero value we cannot convert is a real measurement in a unit we
  // don't map, and that IS worth saying — the operator can fix it on the
  // scale's keypad.
  if (r.lbs === null) {
    if (r.raw > 0) {
      setScaleStatusLive('⚠ Scale is reporting an unrecognized unit ('
        + r.unit + ') — set it to lb or oz.', 'warn');
    }
    return;
  }
  // Platform clear → rearm for the next item. This is the HID equivalent of
  // "clear the platform", and it is why the wedge path's timed echo window is
  // not needed here.
  if (r.status === HID_ST_ZERO || r.lbs < HID_MIN_CAPTURE_LBS) {
    hid.armed = true;
    hid.motionStreak = 0;
    resetSettle();
    setScaleStatusLive('⚖ Scale ready', 'ok');
    return;
  }
  if (r.status !== HID_ST_STABLE) {
    // One stray non-stable report in the middle of settling is normal — these
    // scales flicker in and out of "stable" as an item beds in. Throwing the
    // whole window away on a single blip is how a one-second settle turns into
    // five: it restarts over and over and never finishes. Two in a row means
    // the item really is moving.
    hid.motionStreak++;
    if (hid.motionStreak >= 2) {
      resetSettle();
      setScaleStatusLive('⚖ Weighing…', '');
    }
    return;
  }
  hid.motionStreak = 0;
  if (!hid.armed) {
    // A reading was already taken for whatever is sitting here — or refused,
    // above. Once the pending entry is resolved, say what has to happen next
    // instead of leaving a stale message on screen with the platform loaded.
    if (state.pendingWeight === null) {
      setScaleStatusLive('⚖ Clear the platform to weigh the next item.', '');
    }
    return;
  }

  // Stable — but hold it. Comparing against the anchor rather than the previous
  // report is what stops a slow creep from settling: drifting a division per
  // report keeps restarting the window instead of quietly accumulating.
  if (hid.settleAnchor === null
      || Math.abs(r.lbs - hid.settleAnchor) > HID_SETTLE_TOL_LBS) {
    hid.settleAnchor = r.lbs;
    hid.settleSince  = Date.now();
    hid.settleCount  = 1;
    hid.settleLast   = r.lbs;
    hid.settleExact  = 1;
    hid.settleSign   = 0;
    hid.settleMono   = 0;
    setScaleStatusLive('⚖ Settling…', '');
    return;
  }
  hid.settleCount++;
  // Same raw reading twice running means the value is parked, not drifting.
  // (Identical raw counts decode to identical floats, so == is exact here.)
  // Otherwise track which way it moved: a reading that keeps stepping the same
  // direction is creeping, however small each step is, while a flickering last
  // digit reverses. That direction — not the size of the step — is what tells
  // the two apart when the scale only reports once a second and there are
  // barely any samples to judge from.
  if (r.lbs === hid.settleLast) {
    hid.settleExact++;
    hid.settleSign = 0;
    hid.settleMono = 0;
  } else {
    const sign = r.lbs > hid.settleLast ? 1 : -1;
    hid.settleExact = 1;
    hid.settleMono  = (sign === hid.settleSign) ? hid.settleMono + 1 : 1;
    hid.settleSign  = sign;
  }
  hid.settleLast  = r.lbs;

  const held    = Date.now() - hid.settleSince;
  const parked  = hid.settleExact >= HID_SETTLE_REPORTS
               && held >= HID_SETTLE_FAST_MS;
  // Two steps the same way is a trend, not noise — hold, whatever the timers say.
  const trending = hid.settleMono >= 2;
  const jittery = !trending
               && hid.settleCount >= HID_SETTLE_REPORTS_JITTER
               && held >= HID_SETTLE_MS;
  if (!parked && !jittery) {
    setScaleStatusLive('⚖ Settling…', '');
    return;
  }
  // Refuse to start a second weighing while one is still waiting for its PLU.
  // Removing the item and putting a new one on rearms the platform, so without
  // this the station would happily beep for item after item, each one quietly
  // replacing the weight the operator was in the middle of identifying.
  //
  // The reading is *discarded*, not queued, and the scale is disarmed with it:
  // this item went on by mistake, and springing a second PLU window on the
  // operator the instant they finish the first is how one mistake becomes two.
  // Weighing it for real means taking it off and putting it back on.
  if (state.pendingWeight !== null) {
    hid.armed = false;
    setScaleStatusLive('⚠ No PLU entered.', 'warn');
    return;
  }
  hid.armed = false;
  resetSettle();
  setScaleStatus('⚖ Total weight at ' + r.lbs.toFixed(2) + ' lb.', 'ok');
  await acceptScaleWeight(r.lbs, { echoCheck: false });
}

// Checked often enough to feel prompt, cheap enough to ignore.
setInterval(() => {
  if (!hid.device || hid.idleWarned) return;
  // Nothing usable since we connected — the scale is switched off, not merely
  // idle. Same thing to tell the operator, but answered in seconds: nobody
  // opening this page should wait out the three-minute idle timeout to be told
  // the scale isn't on.
  if (!hid.everRead) {
    if (Date.now() - hid.connectedAt < SCALE_STARTUP_MS) return;
    hid.idleWarned = true;
    errorBeep();
    setScaleStatus('⚠ Make sure scale is on.', 'warn');
    return;
  }
  if (!hid.lastChangeAt || Date.now() - hid.lastChangeAt < SCALE_IDLE_MS) return;
  hid.idleWarned = true;
  errorBeep();
  setScaleStatus('⚠ Make sure scale is on.', 'warn');
}, 5000);

async function openScale(device) {
  try {
    if (!device.opened) await device.open();
  } catch (err) {
    setScaleStatus('⚠ Could not open the scale: ' + err.message, 'warn');
    return false;
  }
  hid.device       = device;
  hid.lastLbs      = null;
  // Start the idle clock now, so a scale that is connected but already asleep
  // still raises the message instead of waiting forever for a first report.
  hid.lastChangeAt = Date.now();
  hid.idleWarned   = false;
  hid.armed        = true;
  hid.connectedAt  = Date.now();
  hid.everRead     = false;
  resetSettle();
  device.addEventListener('inputreport', onScaleReport);
  // Not "ready" yet — nothing has been heard from it. Claiming ready before a
  // single reading is what made a switched-off scale look fine on page load.
  setScaleStatus('⚖ Scale connected — waiting for a reading…', '');
  $('scaleConnect').style.display = 'none';
  return true;
}

async function connectScale() {
  let devices;
  try {
    devices = await navigator.hid.requestDevice({
      filters: [{ usagePage: HID_SCALE_USAGE_PAGE }],
    });
  } catch (err) {
    setScaleStatus('⚠ Scale not connected: ' + err.message, 'warn');
    return;
  }
  if (!devices || !devices.length) return;   // operator dismissed the picker
  await openScale(devices[0]);
}

function isScaleDevice(d) {
  return d.collections
      && d.collections.some(c => c.usagePage === HID_SCALE_USAGE_PAGE);
}

// A device the operator has already granted comes back through getDevices()
// with no prompt, so the station reconnects silently on every later page load.
// The Connect button is only ever needed once per browser profile.
async function initScale() {
  if (!scaleSupported()) return;   // keyboard-wedge stations never see the bar
  $('scaleBar').style.display = 'flex';
  setScaleStatus('⚖ Scale not connected', '');
  $('scaleConnect').addEventListener('click', connectScale);

  navigator.hid.addEventListener('disconnect', (e) => {
    if (!hid.device || e.device !== hid.device) return;
    hid.device = null;
    hid.idleWarned = false;
    setScaleStatus('⚠ Scale disconnected — check its USB cable and power.', 'warn');
    $('scaleConnect').style.display = '';
  });
  navigator.hid.addEventListener('connect', async (e) => {
    if (hid.device || !isScaleDevice(e.device)) return;
    await openScale(e.device);
  });

  try {
    const granted = await navigator.hid.getDevices();
    const scale = granted.find(isScaleDevice);
    if (scale) await openScale(scale);
  } catch (err) { /* no grant yet — the Connect button covers it */ }
}
initScale();

// Messages inside the PLU window. Every rejection buzzes: the operator is
// looking at the scale and the item, not at the screen, so a silent red line
// is a rejection they don't notice until the order comes up short. pluNote()
// is the exception — it confirms something rather than refusing it, so it
// stays quiet and doesn't wear the error styling.
function pluMessage(msg, kind) {
  const e = $('pluError');
  if (!msg) { e.style.display = 'none'; e.textContent = ''; e.style.color = ''; return; }
  e.textContent = msg;
  e.style.color = (kind === 'note') ? 'var(--brown)' : '';
  e.style.display = 'block';
  if (kind !== 'note') errorBeep();
}
function pluError(msg) { pluMessage(msg, 'error'); }
function pluNote(msg)  { pluMessage(msg, 'note'); }

// `beep` is false when re-opening after a failed save — pluError() sounds the
// buzz there instead, so a retry never plays both tones at once.
function openPluModal(lbs, beep) {
  $('pluWeight').textContent = fmtLbs(lbs);
  $('pluInput').value = '';
  resetPluEntry();
  pluError('');
  const m = $('pluPrompt');
  m.style.display = 'flex';
  m.setAttribute('aria-hidden', 'false');
  if (beep !== false) alertBeep();
  // Defer focus until after the layout settles so iOS Safari accepts it.
  setTimeout(() => $('pluInput').focus(), 0);
}

function closePluModal() {
  const m = $('pluPrompt');
  m.style.display = 'none';
  m.setAttribute('aria-hidden', 'true');
  $('pluInput').value = '';
  resetPluEntry();
  pluError('');
}

// Drop the held weight and put the station back in its resting state.
function clearPendingWeight() {
  state.pendingWeight = null;
  closePluModal();
}

async function submitPlu() {
  if (state.pendingWeight === null) { closePluModal(); refocus(); return; }
  const raw = $('pluInput').value.trim();
  if (!raw) {
    pluError('Scan or type the PLU for the item on the scale.');
    $('pluInput').focus();
    return;
  }
  // The scale sending its reading a second time would otherwise be typed in
  // here and treated as a PLU. Re-weighing the item takes the newer value.
  // Checked before the name branch: "1.120lb" has letters in it too.
  if (isScaleTransmission(raw)) {
    $('pluInput').value = '';
    resetPluEntry();
    const again = parseScaleWeight(raw);
    if (again !== null && again > 0 && !isScaleEcho(again)) {
      state.pendingWeight = again;
      state.lastScaleLbs  = again;
      state.lastScaleAt   = Date.now();
      $('pluWeight').textContent = fmtLbs(again);
      pluNote('Reading updated — this is the weight that will be recorded.');
    }
    $('pluInput').focus();
    return;
  }
  // A name, not a code — resolve it through the type-ahead. The text stays in
  // the field so a query that matched several items can be narrowed.
  if (isNameQuery(raw)) {
    await tryAcceptPluName(raw);
    return;
  }
  $('pluInput').value = '';
  hidePluMatches();
  await handleScan(raw);   // consumes state.pendingWeight
  refocus();
}

$('pluInput').addEventListener('keydown', (e) => {
  // Enter and Tab both terminate a scan, same as the main barcode field.
  if (e.key === 'Enter' || e.key === 'Tab') { e.preventDefault(); submitPlu(); return; }
  if (e.key === 'Escape') {
    e.preventDefault();
    $('pluInput').value = '';
    resetPluEntry();
    pluError('');
  }
});
$('pluSubmit').addEventListener('click', submitPlu);
$('pluCancel').addEventListener('click', () => {
  clearPendingWeight();
  flash('Weight discarded — clear the platform before weighing again.', 'info');
  refocus();
});
// Backdrop click does not dismiss: a held weight is only released by recording
// an item or by pressing Discard Weight.
$('pluPrompt').addEventListener('click', (e) => {
  if (e.target === $('pluPrompt')) {
    e.stopPropagation();
    $('pluInput').focus();
  }
});

// ── PLU window: add-by-name type-ahead ───────────────────────────────────
// The same affordance the scan box has, scoped to produce sold by the pound:
// a weight is already held here, so an item counted by the each can never be
// the right answer, and a cached UPC never carries a PLU. Picking a match puts
// its code in the field and records it exactly as a scanned code would.
let pluMatches = [];       // matches currently rendered in the list
let pluTotal = 0;          // total distinct names matched (may exceed list)
let pluSearchTimer = null;
let pluSearchSeq = 0;      // discard out-of-order responses
const pluBox   = $('pluMatches');
const pluInput = $('pluInput');

function hidePluMatches() {
  pluBox.style.display = 'none';
  pluBox.innerHTML = '';
  pluMatches = [];
  pluTotal = 0;
}

// Back to a bare numeric field: no list, digit styling, keypad keyboard.
function resetPluEntry() {
  hidePluMatches();
  pluInput.classList.remove('wt-text');
  pluInput.setAttribute('inputmode', 'numeric');
  $('pluNameMode').style.display = '';
}

// Touch stations only: the numeric keypad has no letters, so the field has to
// be switched before a name can be typed at all. A hardware keyboard ignores
// inputmode, so operators there can just start typing and never press this.
$('pluNameMode').addEventListener('click', () => {
  pluInput.setAttribute('inputmode', 'text');
  $('pluNameMode').style.display = 'none';
  pluInput.blur();          // force the on-screen keyboard to re-open as text
  setTimeout(() => pluInput.focus(), 0);
});

pluInput.addEventListener('input', () => {
  const v = pluInput.value.trim();
  clearTimeout(pluSearchTimer);
  // A scale reading is typed in one character at a time and ends in letters —
  // without this, "1.120lb" would look like a name to both the search and the
  // styling on its way past.
  if (isScaleTyping(v)) { pluInput.classList.remove('wt-text'); hidePluMatches(); return; }
  pluInput.classList.toggle('wt-text', isNameQuery(v));
  if (!isNameQuery(v) || v.length < 2) { hidePluMatches(); return; }
  pluSearchTimer = setTimeout(async () => {
    const seq = ++pluSearchSeq;
    const r = await postJson('../api_scan.php', {action:'search', q: v, scope:'weighed'});
    if (seq !== pluSearchSeq) return;                    // superseded
    if (!isNameQuery(pluInput.value.trim())) return;     // cleared/scanned meanwhile
    if (!r.ok) {
      // A server error must not read as "no such item" — say which it is.
      pluMatches = [];
      pluTotal = 0;
      pluBox.innerHTML = '<div class="nm-hint">⚠ Name search unavailable: '
        + escape(r.error || 'server error') + '</div>';
      pluBox.style.display = 'block';
      return;
    }
    pluMatches = r.matches || [];
    pluTotal   = r.total || pluMatches.length;
    renderPluMatches();
  }, 200);
});

function renderPluMatches() {
  if (!pluTotal) {
    pluBox.innerHTML = '<div class="nm-hint">No produce sold by the pound matches that name.</div>';
    pluBox.style.display = 'block';
    return;
  }
  const unique = pluTotal === 1;
  pluBox.innerHTML = pluMatches.map((m, i) =>
    `<div class="nm-row${unique ? ' nm-unique' : ''}" onclick="acceptPluMatch(${i})">
       <div class="nm-name">${escape(m.name)}</div>
       <div class="nm-code">${escape(m.code)}</div>
     </div>`).join('')
    + `<div class="nm-hint">${unique
        ? '↵ Press Enter to record this item with the weight above'
        : pluTotal + ' matches — keep typing to narrow to one, or tap the item.'}</div>`;
  pluBox.style.display = 'block';
}

async function acceptPluMatch(i) {
  const m = pluMatches[i];
  if (!m) return;
  hidePluMatches();
  pluInput.classList.remove('wt-text');
  pluInput.value = m.code;   // show the PLU that was chosen, then record it
  await submitPlu();
}

// Enter on a name: re-query so the decision is never made on a stale (still
// debouncing) result, then accept only a unique match.
async function tryAcceptPluName(q) {
  clearTimeout(pluSearchTimer);
  const r = await postJson('../api_scan.php', {action:'search', q, scope:'weighed'});
  if (!r.ok) {
    pluError(r.error || 'Name search failed — scan or type the PLU instead.');
    pluInput.focus();
    return;
  }
  pluMatches = r.matches || [];
  pluTotal   = r.total || pluMatches.length;
  if (pluTotal === 1) { await acceptPluMatch(0); return; }
  if (!pluTotal) {
    // Buzzes: the operator is looking at the scale, not the screen. The list is
    // dropped so the same "no matches" line isn't stacked twice.
    hidePluMatches();
    pluError('No produce sold by the pound matches "' + q + '" — scan or type '
           + 'the PLU instead.');
    pluInput.focus();
    return;
  }
  renderPluMatches();
  pluNote(pluTotal + ' items match — keep typing to narrow to one, or tap one.');
  pluInput.focus();
}

async function handleScan(code) {
  // Reserved command barcode: 990001 acts as the End Order trigger so the
  // operator can finish an order without touching the screen.
  if (code === '990001') {
    if (state.orderId) {
      clearPendingWeight();   // ending the order abandons any held weight
      $('btnEnd').click();
    } else {
      flash('No open order to end.', 'info');
    }
    return;
  }
  // Reserved command barcode: 990002 is the "+ Assist" button in barcode form,
  // so a helper station can join a teammate's order with the scanner alone.
  // Must stay above startOrderIfNeeded() — a station about to assist is idle,
  // and auto-starting an order of its own would block the join outright.
  if (code === '990002') {
    await joinNextOrderToAssist();
    return;
  }
  // Reserved command barcode: 990003 is the ✕ on the newest row of This Order,
  // in barcode form. A mis-scan is usually noticed with the next package
  // already in hand, and reaching across the counter to tap a small button is
  // the one thing in this flow that makes an operator put the scanner down.
  // Above startOrderIfNeeded() like its siblings: undoing a scan must never be
  // the thing that opens an order.
  if (code === '990003') {
    await removeNewestScan();
    return;
  }
  // First scan of a session auto-creates a new order; no Start button needed.
  if (!(await startOrderIfNeeded())) return;
  // Look up first so we know whether to ask for weight.
  const lk = await postJson('../api_scan.php', {action:'lookup', barcode: code});
  if (!lk.ok) {
    // Recall first, ahead of every other not-found handling. The code resolved
    // perfectly well — the refusal is deliberate — so none of the branches
    // below apply: there is nothing to re-scan, nothing to name, and nothing
    // to wave past, whichever station this is and whatever Ignore Unknown
    // Items is set to. A held weight stays held: the PLU window is still
    // behind this one, and the operator releases it with Discard Weight once
    // the recalled package is out of the cart.
    if (lk.recalled) {
      openRecallModal(code, lk);
      return;
    }
    // With a weight held, keep the PLU window up and the weight with it — the
    // operator only mis-scanned, and re-weighing the item would be busywork.
    // flash('error') routes into that window while it is open.
    if (state.pendingWeight !== null) {
      flash('Nothing found for ' + code + '. Scan the PLU again, or press '
          + 'Discard Weight to start this item over.', 'error');
      return;
    }
    // Packaged UPC that OFF couldn't resolve — prompt the operator for a
    // generic (and optional brand) so the scan can still be recorded and
    // future scans of this UPC hit the cache.
    if (lk.kind === 'packaged') {
      // Settings → Ignore Unknown Items, fallback path. With the switch on
      // the resolver normally answers ok=true and the item records under the
      // placeholder, so getting here means the placeholder row itself couldn't
      // be written — a locked database, a failed insert. Fall back to the old
      // behaviour rather than stopping the line: banner, Unknown last-scan
      // line, cleared field, no scan row — green and swooping up rather than
      // red and buzzing down, because there is still nothing for the operator
      // to fix. Checked ahead of the assist guard: with the item being skipped
      // outright, "must be scanned by primary station" would send a helper on
      // an errand that ends the same way at the other station.
      if (IGNORE_UNKNOWN) {
        flash('No scan required for this item.', 'error',
              { beep: ignoreBeep, style: 'success' });
        showLast('Unknown', code, '');
        return;
      }
      // Naming a UPC writes a pantry-wide lookup row, so it is the owner's
      // call, not a helper's. A helper gets the same treatment as a barcode
      // that doesn't parse: buzz, banner, and an Unknown last-scan line —
      // no modal, so the assisting station can move straight to the next item.
      if (state.role === 'assist') {
        flash('This item must be scanned by primary station', 'error');
        showLast('Unknown', code, '');
        return;
      }
      openNameModal(code, lk);
      return;
    }
    flash(lk.error || 'Unknown barcode: ' + code, 'error');
    showLast('Unknown', code, '');
    return;
  }
  if (lk.kind === 'produce' && lk.needs_weight) {
    // Scale-first: the weight is already in hand, so record both halves now.
    // The scale is zeroed on its own container, so the reading is already net
    // and the configured tare is not subtracted again.
    if (state.pendingWeight !== null) {
      const w = state.pendingWeight;
      closePluModal();   // shut first: no second scan can land mid-save
      const rec = await postJson('../api_scan.php', {
        action: 'record', barcode: code, weight_lbs: w,
      });
      if (!rec.ok) {
        // Keep the weight and put the window back, so a failed save costs a
        // retry rather than a trip back to the scale.
        openPluModal(w, false);
        pluError(rec.error || 'Save failed — scan the PLU again to retry.');
        return;
      }
      state.pendingWeight = null;
      afterRecord(rec);
      flash('Recorded ' + w.toFixed(2) + ' lb of ' + lk.generic_name
          + ' — clear the platform for the next item.', 'info');
      return;
    }
    // No weight yet (PLU-first station, or the scale is offline): ask for it.
    state.pendingProduce = { barcode: code, generic_name: lk.generic_name };
    state.weightDigits = '';
    state.weightDecimal = null;
    $('weightItem').textContent = lk.generic_name;
    openWeightModal();
    return;
  }
  // Packaged or count-based produce: record immediately.
  if (state.pendingWeight !== null) {
    // Identified as something sold by the each, not by the pound. Record it the
    // normal way, but say plainly that the reading was dropped so nobody
    // assumes the weight went in with it.
    const dropped = state.pendingWeight;
    clearPendingWeight();
    const rec = await postJson('../api_scan.php', {action:'record', barcode: code});
    afterRecord(rec);
    if (rec.ok) {
      // Buzz even though the item saved: a weight was thrown away, and the
      // operator is watching the scale rather than the banner.
      errorBeep();
      flash(lk.generic_name + ' is recorded by count, not weight — the '
          + dropped.toFixed(2) + ' lb reading was discarded. Clear the platform.', 'warn');
    }
    return;
  }
  const rec = await postJson('../api_scan.php', {action:'record', barcode: code});
  afterRecord(rec);
  // Recorded, but under the placeholder name: Ignore Unknown Items is on and
  // nothing could name this barcode. Say so plainly — the item IS on the order
  // and IS counted, which is the whole difference from the old "no scan
  // required" — and say it in green with no beep at all. Nothing is owed here:
  // the volunteer bags it and scans the next one, and the naming happens later
  // with the switch off.
  if (rec.ok && rec.item && rec.item.unidentified) {
    flash('Recorded as Unidentified — this barcode has no name yet.', 'info',
          { style: 'success' });
  }
}

// ── Weight entry: adding-machine (manual) OR decimal pounds (scale) ──────
// Two entry paths share this field:
//
//  1. Adding-machine (manual keypad): digits accumulate in state.weightDigits.
//       1st digit  → whole pounds
//       2nd-3rd    → ounces (00-99)
//     Backspace pops the rightmost digit. The buffer may grow past 3 so a
//     >3-digit Enter can be detected and rejected (clear, start over).
//
//  2. Decimal pounds (HID scale): a scale placed on the line auto-types the
//     weight as decimal pounds ending in "lb" + Enter, e.g. " 0.054lb" or
//     " 1.120lb". The '.' switches us into decimal mode: state.weightDecimal
//     holds the raw pounds string, parsed as a float on submit. The leading
//     space and the trailing "lb" letters are ignored keystrokes.
const WEIGHT_VALID_DIGITS = 3;  // 1 lb digit + 2 oz digits
const WEIGHT_BUFFER_LIMIT = 10; // hard cap to prevent runaway typing

function renderWeight() {
  const el = $('weightInput');
  // Decimal / scale mode: show the raw pounds value with an "lb" suffix.
  if (state.weightDecimal !== null) {
    el.classList.remove('wt-overflow');
    el.value = state.weightDecimal ? state.weightDecimal + ' lb' : '';
    return;
  }
  const d = state.weightDigits;
  if (!d) {
    el.value = '';
    el.classList.remove('wt-overflow');
    return;
  }
  if (d.length > WEIGHT_VALID_DIGITS) {
    // Show the raw run-on digits so the operator can see the overrun before
    // pressing Enter. Visual warning via the .wt-overflow style.
    el.value = d;
    el.classList.add('wt-overflow');
    return;
  }
  el.classList.remove('wt-overflow');
  const padded = d.padStart(3, '0');
  el.value = padded.slice(0, 1) + ' lb ' + padded.slice(1) + ' oz';
}

// Inline error shown inside the weight modal (a flash() banner would render
// behind the modal's dark overlay). Cleared as soon as fresh input arrives.
function weightError(msg) {
  const e = $('wtError');
  if (!msg) { e.style.display = 'none'; e.textContent = ''; return; }
  e.textContent = msg;
  e.style.display = 'block';
}

function appendWeightDigit(ch) {
  weightError('');
  if (state.weightDecimal !== null) {
    if (state.weightDecimal.length >= WEIGHT_BUFFER_LIMIT) return;
    state.weightDecimal += ch;
    renderWeight();
    return;
  }
  if (state.weightDigits.length >= WEIGHT_BUFFER_LIMIT) return;
  // Don't accumulate leading zeros (so "0" stays empty until a real digit).
  if (state.weightDigits === '' && ch === '0') return;
  state.weightDigits += ch;
  renderWeight();
}

// A '.' (from a scale, or typed manually) starts decimal-pounds mode. Any
// digits already in the adding-machine buffer become the whole-pounds part.
function startWeightDecimal() {
  weightError('');
  if (state.weightDecimal !== null) return;  // already decimal; ignore extra dots
  state.weightDecimal = (state.weightDigits || '0') + '.';
  state.weightDigits = '';
  renderWeight();
}

function popWeightDigit() {
  weightError('');
  if (state.weightDecimal !== null) {
    state.weightDecimal = state.weightDecimal.slice(0, -1);
    if (state.weightDecimal === '') state.weightDecimal = null;
    renderWeight();
    return;
  }
  if (!state.weightDigits.length) return;
  state.weightDigits = state.weightDigits.slice(0, -1);
  renderWeight();
}

$('weightInput').addEventListener('keydown', (e) => {
  // Enter accepts the input. submitWeight() itself clears the field if the
  // buffer is empty or invalid, so a stray scanner burst still can't
  // auto-submit a bad weight.
  if (e.key === 'Enter') { e.preventDefault(); submitWeight(); return; }
  if (e.key === 'Backspace' || e.key === 'Delete') { e.preventDefault(); popWeightDigit(); return; }
  if (e.key === '.') { e.preventDefault(); startWeightDecimal(); return; }
  if (e.key >= '0' && e.key <= '9') { e.preventDefault(); appendWeightDigit(e.key); return; }
  // Ignore everything else — including the scale's leading space and trailing
  // "lb" letters, and any paste shortcut that would bypass the buffer.
  if (e.key.length === 1 && !e.ctrlKey && !e.metaKey) e.preventDefault();
});
// Mobile soft-keyboards fire `input` events without keydown — sync from there
// by re-reading whatever the browser put in the field and rebuilding the buffer.
$('weightInput').addEventListener('input', () => {
  const val = $('weightInput').value;
  if (val.includes('.')) {
    // Decimal / scale-style value: keep the digits and the point.
    state.weightDecimal = (val.match(/[\d.]/g) || []).join('').slice(0, WEIGHT_BUFFER_LIMIT);
    renderWeight();
    return;
  }
  state.weightDecimal = null;
  const digits = (val.match(/\d/g) || []).join('').replace(/^0+/, '');
  state.weightDigits = digits.slice(0, WEIGHT_BUFFER_LIMIT);
  renderWeight();
});

// ── Product-recall modal ────────────────────────────────────────────────
// Raised whenever the resolver refuses a code because its upc_lookup row is
// flagged recalled. Nothing was written — the server declined before the
// insert — so there is no scan to undo here; the only outstanding action is
// physical, and it belongs to the volunteer standing at the cart.
function openRecallModal(code, lk) {
  const name = (lk && lk.generic_name) || 'Recalled item';
  const key  = (lk && lk.store_key) || null;
  $('recallItem').textContent = name;
  // The store key as well as the scanned label when they differ: the recall
  // covers every package of that item, not just the one in hand.
  $('recallMeta').textContent = key ? key + ' · scanned ' + code : code;
  const m = $('recallPrompt');
  m.style.display = 'flex';
  m.setAttribute('aria-hidden', 'false');
  alarmBeep();
  // The last-scan line normally reads as a receipt for what went onto the
  // order, so say plainly that this one did not.
  showLast('⚠ RECALLED — ' + name, code, 'not recorded');
  setTimeout(() => $('recallAck').focus(), 0);
}
function closeRecallModal() {
  const m = $('recallPrompt');
  m.style.display = 'none';
  m.setAttribute('aria-hidden', 'true');
}
$('recallAck').addEventListener('click', () => {
  closeRecallModal();
  // Straight back to the field the scanner types into — unless the PLU window
  // is still up behind this one, in which case refocus() leaves it alone and
  // the held weight is still waiting there.
  barcodeInput.value = '';
  hideNameMatches();
  if ($('pluPrompt').style.display !== 'none') { $('pluInput').focus(); return; }
  refocus();
});
// Backdrop click does not dismiss: acknowledging a recall is deliberate.
$('recallPrompt').addEventListener('click', (e) => {
  if (e.target === $('recallPrompt')) { e.stopPropagation(); $('recallAck').focus(); }
});

// ── Unknown-UPC name-entry modal ────────────────────────────────────────
function openNameModal(barcode, lk) {
  state.pendingUnknownUPC = { barcode };
  const storeKey = (lk && lk.store_key) || null;
  // Scanned before while Ignore Unknown Items was on, so it is already on the
  // shelf and already in the scan history under the placeholder name. The
  // window is the same one — the operator's job is identical, type a generic
  // name — but the eyebrow and the note say which situation this is, because
  // the consequence differs: saving here also renames this barcode's past
  // scans, and "Open Food Facts has no record of this UPC" would be a
  // misleading thing to read while doing it.
  const wasUnidentified = !!(lk && lk.unidentified);
  $('nameEyebrow').textContent = wasUnidentified
    ? '⚠ Unidentified Item'
    : (storeKey ? '⚠ Unknown Store Label' : '⚠ Unknown UPC');
  $('nameUpc').textContent = storeKey ? storeKey + ' · scanned ' + barcode : barcode;
  $('nameStdNote').style.display = (storeKey && !wasUnidentified) ? 'none' : 'block';
  $('nameStoreNote').style.display  = storeKey ? 'block' : 'none';
  if (wasUnidentified) {
    $('nameStdNote').textContent =
      'This barcode was scanned before while Ignore Unknown Items was on, so '
      + 'it was recorded as "Unidentified". Name it now and every past scan of '
      + 'this one barcode is renamed with it — other unidentified items are '
      + 'left alone.';
  } else {
    $('nameStdNote').textContent =
      'Open Food Facts has no record of this UPC. Enter a generic name to add '
      + 'it to the cache so future scans recognize it automatically.';
  }
  if (storeKey) {
    $('nameStoreNote').textContent =
      'This is a store-printed item label. The name is saved against '
      + 'item ' + storeKey + ' only — the last five digits hold the price or '
      + 'weight of this one package and change with every package, so one '
      + 'name covers all of them.';
  }
  $('nameBrand').value = '';
  $('nameGeneric').value = '';
  const m = $('namePrompt');
  m.style.display = 'flex';
  m.setAttribute('aria-hidden', 'false');
  errorBeep();
  setTimeout(() => $('nameGeneric').focus(), 0);
}
function closeNameModal() {
  state.pendingUnknownUPC = null;
  const m = $('namePrompt');
  m.style.display = 'none';
  m.setAttribute('aria-hidden', 'true');
}
$('nameCancel').addEventListener('click', () => {
  closeNameModal();
  refocus();
});
// Backdrop click does not dismiss — operator must use the buttons.
$('namePrompt').addEventListener('click', (e) => {
  if (e.target === $('namePrompt')) {
    e.stopPropagation();
    $('nameGeneric').focus();
  }
});
// Capitalize the start of every word typed into the two name fields, so
// "bumble bee tuna" is saved as "Bumble Bee Tuna" and matches the Title Case
// the rest of the item names already use. generic_name is a case-sensitive key
// (it is the inventory table's primary key), so a lower-case spelling typed at
// the station would otherwise sit beside the capitalized one as a second item
// with its own count and its own scan history.
//
// Only the FIRST letter of each word is touched; the rest is left exactly as
// typed, so DEL MONTE, V8 and 2% survive being re-cased. A word starts at the
// beginning of the field or after a space, hyphen, slash or opening bracket —
// not after an apostrophe, which would give "Bob'S".
function capitalizeWords(s) {
  return s.replace(/(^[\s\-/([]*|[\s\-/([]+)(\S)/g, (m, sep, ch) => sep + ch.toUpperCase());
}
['nameBrand', 'nameGeneric'].forEach((id) => {
  $(id).addEventListener('input', (e) => {
    const el = e.target;
    const next = capitalizeWords(el.value);
    if (next === el.value) return;
    // Re-casing a letter doesn't change the string's length, so the caret goes
    // back exactly where it was instead of jumping to the end mid-word. The
    // min() is only a guard for the rare character that lengthens when upper-
    // cased.
    const pos = Math.min(el.selectionStart, next.length);
    el.value = next;
    el.setSelectionRange(pos, pos);
  });
});

// Enter never submits on either field, so a stray scanner burst (digits +
// Enter) can't save a wrong generic name.
['nameBrand', 'nameGeneric'].forEach((id) => {
  $(id).addEventListener('keydown', (e) => {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    // Enter here is almost always the tail of a scanner burst: the operator
    // kept scanning while the modal was up, so the field now holds a UPC (or
    // several concatenated). Silently swallowing it left that junk in place
    // to be saved as the generic name. Buzz and empty the field instead, so
    // the burst leaves no trace and the operator hears that it was rejected.
    errorBeep();
    e.target.value = '';
  });
});
$('nameSubmit').addEventListener('click', async () => {
  if (!state.pendingUnknownUPC) return;
  const generic = capitalizeWords($('nameGeneric').value.trim());
  if (!generic) { $('nameGeneric').focus(); return; }
  const brand = capitalizeWords($('nameBrand').value.trim());
  const rec = await postJson('../api_scan.php', {
    action: 'record',
    barcode: state.pendingUnknownUPC.barcode,
    brand_name: brand,
    generic_name: generic,
  });
  closeNameModal();
  afterRecord(rec);
  refocus();
});

function openWeightModal() {
  const m = $('weightPrompt');
  m.style.display = 'flex';
  m.setAttribute('aria-hidden', 'false');
  weightError('');
  renderWeight();
  alertBeep();
  // Defer focus until after the layout settles so iOS Safari accepts it.
  setTimeout(() => $('weightInput').focus(), 0);
}
function closeWeightModal() {
  const m = $('weightPrompt');
  m.style.display = 'none';
  m.setAttribute('aria-hidden', 'true');
}

$('weightSubmit').addEventListener('click', submitWeight);
$('weightCancel').addEventListener('click', () => {
  state.pendingProduce = null;
  state.weightDigits = '';
  state.weightDecimal = null;
  closeWeightModal();
  refocus();
});
// Block clicks on the dark backdrop from doing anything — operator must
// explicitly Save or Cancel so the weight prompt cannot be dismissed by
// accident while a produce scan is pending.
$('weightPrompt').addEventListener('click', (e) => {
  if (e.target === $('weightPrompt')) {
    e.stopPropagation();
    $('weightInput').focus();
  }
});

async function submitWeight() {
  if (!state.pendingProduce) return;
  const fromScale = state.weightDecimal !== null;
  let gross;
  if (fromScale) {
    // Decimal pounds straight from the scale (e.g. "1.120" → 1.12 lb).
    gross = parseFloat(state.weightDecimal);
    if (!isFinite(gross) || gross <= 0) {
      weightError('Scale weight must be greater than 0. Re-weigh the item.');
      state.weightDecimal = null;
      renderWeight();
      $('weightInput').focus();
      return;
    }
  } else {
    // Empty buffer OR more than 3 digits → reject and reset so the operator
    // can re-enter. The backend never sees the bad value.
    if (!state.weightDigits || state.weightDigits.length > WEIGHT_VALID_DIGITS) {
      weightError(state.weightDigits ? 'Too many digits — enter 1 lb digit + 2 oz digits (e.g. 514).'
                                     : 'Enter a weight first.');
      state.weightDigits = '';
      renderWeight();
      $('weightInput').focus();
      return;
    }
    // Convert 1–3 buffer digits into decimal pounds:
    //   buffer "514" → 5 lb 14 oz → 5 + 14/16 = 5.875 lb
    // Inventory keeps storing pure lb so totals stay in a single unit.
    const padded = state.weightDigits.padStart(3, '0');
    const lbs = parseInt(padded.slice(0, 1), 10);
    const oz  = parseInt(padded.slice(1),    10);
    gross = lbs + (oz / 16);
  }
  // A HID scale is zeroed at the hardware, so its reading is already the net
  // produce weight. Only manual (keypad) entries have the configured container
  // tare removed here — subtracting it from a scale reading would double-count.
  const w = fromScale ? gross : gross - TARE_LBS;
  if (!w || w <= 0) {
    weightError('Weight after tare must be greater than 0. Check the Settings tare.');
    $('weightInput').focus();
    return;
  }
  const rec = await postJson('../api_scan.php', {
    action: 'record', barcode: state.pendingProduce.barcode, weight_lbs: w,
  });
  state.pendingProduce = null;
  state.weightDigits = '';
  state.weightDecimal = null;
  closeWeightModal();
  afterRecord(rec);
  refocus();
}

function afterRecord(rec) {
  // The item was flagged recalled between this page's lookup and its record —
  // an admin ticking the box mid-order, or a page open since before it was
  // ticked. The server refused the insert either way; raise the same alarm the
  // lookup path does rather than letting it read as an ordinary save failure.
  if (!rec.ok && rec.recalled) {
    openRecallModal(rec.barcode || '', rec);
    return;
  }
  if (!rec.ok) { flash(rec.error || 'Save failed', 'error'); return; }
  // Assist stations confirm each item out loud. A helper is often the one on
  // the far side of the cart with a phone, where the wired scanner's own beep
  // isn't audible and the screen isn't being watched — this says the item
  // landed on the shared order. Owner stations stay as they were: their
  // hardware scanner already beeps for them.
  //
  // Not while the camera is running: it beeps on decode (onCamDecode), so
  // beeping again on the record would double every item.
  if (state.role === 'assist' && state.scanBeep && !state.scanner) scanBeep();
  const it = rec.item;
  showLast(it.generic_name, it.barcode,
    it.kind === 'produce' ? it.weight_lbs + ' lb' : 'qty ' + it.quantity);
  appendRow(it);
  bumpStats(it, rec.scan_count);
  if (rec.warning) flash('AI mapping skipped: ' + rec.warning + '. Saved as raw name — edit under Lookup Tables.', 'warn');
}

function showLast(name, code, qtyText) {
  $('lastScanWrap').style.display = 'block';
  $('lastScan').textContent = name;
  $('lastScanMeta').textContent = code + ' · ' + qtyText;
}

const tableState = { rows: [], unique: new Set(), totalWeight: 0, count: 0 };
function resetTable() {
  tableState.rows = [];
  tableState.unique = new Set();
  tableState.totalWeight = 0;
  tableState.count = 0;
  $('scanTable').querySelector('tbody').innerHTML = '';
  $('statCount').textContent = '0';
  $('statUnique').textContent = '0';
  $('statWeight').textContent = '0.0';
}
// timeLabel is supplied only when replaying BOOT_SCANS, so a hydrated row keeps
// its original scan time instead of showing the page-load time.
function appendRow(it, timeLabel) {
  const tb = $('scanTable').querySelector('tbody');
  const tr = document.createElement('tr');
  const t = timeLabel || new Date().toLocaleTimeString();
  tr.dataset.scanId = it.id || '';
  // Who scanned it. The cell is always rendered (hidden by CSS on a solo
  // station) so the row shape never varies; `mine` fills the badge in.
  const mine  = it.station ? it.station === MY_STATION : true;
  const label = it.station_label || '';
  const who = label
    ? `<span class="who-badge${mine ? ' who-me' : ''}"
             title="${mine ? 'This station' : 'Other station'}">${escape(label)}</span>`
    : '';
  tr.innerHTML = `<td>${t}</td>
    <td><a href="#" class="prep-link" title="How do I prepare this item?"
           data-name="${escape(it.generic_name)}">${escape(it.generic_name)}</a></td>
    <td>${it.kind}</td>
    <td class="col-who">${who}</td>
    <td class="num">${it.quantity || ''}</td>
    <td class="num">${it.weight_lbs ? Number(it.weight_lbs).toFixed(2) : ''}</td>
    <td>${escape(it.barcode)}</td>
    <td><button type="button" class="btn-x" aria-label="Remove scan"
                onclick="removeScan(this, ${it.id || 0})">✕</button></td>`;
  tb.prepend(tr);
}

async function removeScan(btn, scanId) {
  if (!scanId) return false;
  btn.disabled = true;
  const r = await postJson('../api_scan.php', {action:'delete', scan_id: scanId});
  if (!r.ok) {
    btn.disabled = false;
    flash(r.error || 'Could not remove scan', 'error');
    return false;
  }
  const tr = btn.closest('tr');
  if (tr) tr.remove();
  recomputeOrderStats(r.scan_count);
  refocus();
  return true;
}

// The scanner-only form of that ✕, reached by command barcode 990003. Rows are
// prepended, so the first row in the table is the newest scan — the same row
// the top ✕ sits on, and on a team-scanned order it may be a teammate's, just
// as it is when the button is tapped. The server scopes either delete to the
// open order.
async function removeNewestScan() {
  if (!state.orderId) { flash('No open order — nothing to remove.', 'info'); return; }
  // A held weight is the outstanding action here, not a recorded scan: nothing
  // has been written yet, so the thing to take back is the weight. Same release
  // Discard Weight performs, and it leaves the previous item on the order,
  // which is what the operator standing at the scale means by "undo".
  if (state.pendingWeight !== null) {
    clearPendingWeight();
    flash('Weight discarded — clear the platform before weighing again.', 'info');
    refocus();
    return;
  }
  const row = $('scanTable').querySelector('tbody tr');
  if (!row) { flash('Nothing scanned yet on this order.', 'info'); return; }
  const scanId = Number(row.dataset.scanId || 0);
  // A row with no id was never persisted, so there is nothing for the server to
  // delete and the ✕ on it is dead too. Say so rather than failing silently.
  if (!scanId) { flash('That row cannot be removed — reload the page.', 'error'); return; }
  const name = row.children[1].textContent;
  const btn  = row.querySelector('.btn-x');
  if (!btn || !(await removeScan(btn, scanId))) return;
  // The operator is looking at the cart, not the screen: say what left the
  // order out loud, and correct the last-scan line, which still reads as a
  // receipt for the item just removed.
  removeBeep();
  flash('Removed ' + name + ' from this order.', 'info');
  showLast(name, row.children[6].textContent, 'removed');
}

// Recompute the visible "This Order" stats from whatever rows remain.
// Cheap because the table is per-order, not historical.
function recomputeOrderStats(scanCount) {
  const rows = document.querySelectorAll('#scanTable tbody tr');
  const unique = new Set();
  let totalWeight = 0;
  // Cell indices: 0 Time, 1 Generic, 2 Kind, 3 Who, 4 Qty, 5 Lbs, 6 Barcode.
  // The Who cell is always present in the DOM (CSS hides it on a solo
  // station), so these indices hold in both modes.
  rows.forEach(tr => {
    const cells = tr.children;
    unique.add(cells[1].textContent);
    if (cells[2].textContent === 'produce') {
      const lbs = parseFloat(cells[5].textContent);
      if (lbs) totalWeight += lbs;
    }
  });
  $('statCount').textContent  = scanCount;
  $('statUnique').textContent = unique.size;
  $('statWeight').textContent = totalWeight.toFixed(1);
  // Resync the running totals so subsequent scans append correctly.
  tableState.unique      = unique;
  tableState.totalWeight = totalWeight;
}
function bumpStats(it, scanCount) {
  tableState.unique.add(it.generic_name);
  if (it.kind === 'produce' && it.weight_lbs) tableState.totalWeight += Number(it.weight_lbs);
  $('statCount').textContent = scanCount;
  $('statUnique').textContent = tableState.unique.size;
  $('statWeight').textContent = tableState.totalWeight.toFixed(1);
}
function escape(s) { return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
// opts lets a caller keep the 'error' handling — the cleared barcode field and
// the routing into the PLU window — while replacing the parts that say
// something went wrong: opts.beep swaps the buzz, opts.style swaps the banner
// color. For a message that is informational rather than a fault.
function flash(msg, kind, opts) {
  opts = opts || {};
  // With the PLU window up, a banner would render behind its dark overlay.
  // Errors go inside that window instead, where the operator is looking, and
  // the held weight survives so a mis-scan doesn't cost a re-weigh.
  if (kind === 'error' && $('pluPrompt').style.display !== 'none') {
    pluError(msg);   // buzzes on its own
    $('pluInput').value = '';
    hidePluMatches();
    $('pluInput').focus();
    return;
  }
  // Every error banner also buzzes and empties the barcode field. An operator
  // who misses the banner otherwise keeps scanning into the leftover text —
  // each burst appends to it, so every following scan is rejected too.
  if (kind === 'error') {
    (opts.beep || errorBeep)();
    barcodeInput.value = '';
    hideNameMatches();
  }
  const b = document.createElement('div');
  b.className = 'banner ' + (opts.style || kind || 'info');
  b.textContent = msg;
  $('scanCard').prepend(b);
  setTimeout(() => b.remove(), 4000);
}

// ── Team scanning: assist another station, and stay in sync with it ─────────
// Every station polls api_order.php `sync`. Idle stations poll so the Assist
// card appears the moment a teammate starts an order; teamed stations poll to
// pick up each other's scans and removals. The payload is the whole scan list
// rather than a delta, because a delta can't express "the other station
// deleted row 41" — and an order is only ever a few dozen rows.

const SYNC_MS = 2500;
let lastFingerprint = null;

// Point the order bar at whatever role the server just reported.
function applyRole(role, orderId, startedAt, assistCount) {
  state.role        = role;
  state.assistCount = assistCount || 0;
  const owner  = role === 'owner';
  const assist = role === 'assist';
  // Idle *and* in assist mode: between orders. Keeps Leave Assist (the only
  // way out) and never offers End / Cancel, which would belong to an order
  // this station doesn't have.
  const waiting = !owner && !assist && state.assistMode;

  $('orderBarEyebrow').textContent =
    assist ? 'Assisting Order' : (waiting ? 'Assist Mode' : 'Current Order');
  $('ownerControls').style.display  = (assist || waiting) ? 'none' : 'flex';
  $('assistControls').style.display = (assist || waiting) ? 'flex' : 'none';
  $('btnEnd').disabled    = !owner;
  $('btnCancel').disabled = !owner;
  $('btnRecipe').disabled = !(owner || assist);

  $('orderNumLabel').textContent =
    orderId ? '#' + orderId : (waiting ? '— waiting —' : '— not started —');
  if (orderId) {
    $('orderStartLabel').textContent =
      'Started ' + startedAt + (assist ? ' on another station' : '');
  }

  // The team pill and the Who column both mean "more than one station is on
  // this order", so they light up together.
  const teamed = (owner && state.assistCount > 0) || assist;
  $('teamPill').style.display = (owner && state.assistCount > 0) ? '' : 'none';
  $('teamPillCount').textContent = state.assistCount;
  $('thisOrderCard').classList.toggle('team-on', teamed);
}

// Replace the scan table with the server's list. Full replacement (rather than
// diffing) keeps deletions on the other station correct for free; the table
// holds no editable state worth preserving. Scroll position is restored around
// it — the pane is height-capped and scrolls internally, so a long order would
// otherwise jump to the top every time the teammate scanned.
function redrawScans(scans, scanCount) {
  const wrap = $('scanTableWrap');
  const top  = wrap.scrollTop;
  resetTable();
  scans.forEach(s => appendRow(s, s.time_label));
  recomputeOrderStats(scanCount);
  wrap.scrollTop = Math.min(top, Math.max(0, wrap.scrollHeight - wrap.clientHeight));
}

function renderAssistable(list) {
  const card = $('assistCard');
  if (!list || !list.length) { card.style.display = 'none'; return; }
  $('assistAdminNote').style.display = state.canClose ? '' : 'none';
  $('assistList').innerHTML = list.map(a => `
    <div class="assist-row">
      <div>
        <div class="assist-order">Order #${a.id}</div>
        <div class="assist-meta">started ${escape(a.time_label)}
          · ${a.scan_count} item${a.scan_count === 1 ? '' : 's'}${
            a.assist_count > 0 ? ' · ' + a.assist_count + ' assisting' : ''}</div>
      </div>
      <div class="assist-actions">
        <button type="button" class="btn btn-secondary btn-assist"
                data-order-id="${a.id}">+ Assist</button>${state.canClose ? `
        <button type="button" class="btn btn-primary btn-sm btn-remote-end"
                data-order-id="${a.id}" data-scan-count="${a.scan_count}"
                title="Close order #${a.id} from here and deduct its items from inventory.">■ End</button>
        <button type="button" class="btn btn-danger btn-sm btn-remote-cancel"
                data-order-id="${a.id}" data-scan-count="${a.scan_count}"
                title="Discard order #${a.id} and everything scanned into it.">✕ Cancel</button>` : ''}
      </div>
    </div>`).join('');
  card.style.display = '';
}

// Apply a sync/assist_join/assist_leave payload to the whole page.
function applySync(d) {
  lastFingerprint = d.fingerprint;
  // The server is the authority on the mode, and applyRole() reads it, so it
  // has to land before anything repaints.
  state.assistMode = !!d.assist_mode;
  // Signing in (or out) in another tab changes what the card may offer, and the
  // idle fingerprint carries the flag so a poll actually reaches this line.
  if (typeof d.can_close === 'boolean') state.canClose = d.can_close;
  // Same for the switch: a reload, or the page in another tab, finds it where
  // the operator left it rather than where this copy of the page last drew it.
  if (typeof d.scan_beep === 'boolean') {
    state.scanBeep = d.scan_beep;
    $('beepSwitch').checked = d.scan_beep;
  }
  if (d.role === 'idle') {
    // Falling out of an order we were assisting means the owner ended or
    // cancelled it — say so, since nothing on this station caused it. In assist
    // mode the station isn't done, only between households: the server joins
    // the next order on a later poll (or the next scan does it).
    if (state.role === 'assist' && state.orderId) {
      flash(state.assistMode
        ? 'Order #' + state.orderId + ' closed — waiting for the next order to assist'
        : 'Order #' + state.orderId + ' was closed by the other station', 'info');
    }
    state.orderId = null;
    applyRole('idle', null, null, 0);
    $('orderStartLabel').textContent = state.assistMode
      ? 'Waiting for the next order to assist'
      : 'Scan an item to begin a new order';
    resetTable();
    renderAssistable(d.assistable);
    return;
  }
  $('assistCard').style.display = 'none';
  state.orderId = d.order.id;
  applyRole(d.role, d.order.id, d.order.started_at, d.assist_count);
  redrawScans(d.scans, d.scan_count);
}

async function syncTick() {
  // Never redraw under an open modal or a request that owns the table.
  if (inFlight > 0 || modalOpen()) return;
  // Mid-flow client state the operator would lose to a redraw: a held scale
  // weight, or a produce item waiting on its weight.
  if (state.pendingWeight !== null || state.pendingProduce) return;
  let d;
  try {
    d = await postJson('../api_order.php', {action:'sync'});
  } catch (e) { return; }  // transient network blip — the next tick retries
  if (!d || !d.ok) return;
  // Re-check the guards: they may have changed during the round trip.
  if (inFlight > 0 || modalOpen()) return;
  if (d.fingerprint === lastFingerprint) return;
  applySync(d);
}
setInterval(syncTick, SYNC_MS);

// Close an order this station doesn't own — the escape hatch for an order
// whose station has gone away. Confirmed rather than immediate: this reaches
// across the room into someone else's order, and Cancel throws away scans that
// are not this station's to throw away.
async function remoteClose(btn, cancel) {
  const id    = Number(btn.dataset.orderId);
  const items = Number(btn.dataset.scanCount || 0);
  const what  = items + ' item' + (items === 1 ? '' : 's');
  const msg = cancel
    ? 'Cancel order #' + id + ' on the other station? The ' + what
      + ' scanned into it will be discarded, and this cannot be undone.'
    : 'End order #' + id + ' on the other station? Its ' + what
      + ' will be deducted from inventory, exactly as if that station had'
      + ' pressed End Order itself.';
  if (!window.confirm(msg)) { refocus(); return; }
  btn.disabled = true;
  const r = await postJson('../api_order.php',
    {action: cancel ? 'remote_cancel' : 'remote_end', order_id: id});
  if (!r.ok) {
    btn.disabled = false;
    // Usually the owner came back and closed it during the 2.5s the card was
    // stale. Clear the fingerprint so the next tick redraws from fresh state.
    lastFingerprint = null;
    flash(r.error || 'Could not close that order', 'error');
    return;
  }
  applySync(r);
  flash(cancel
    ? 'Order #' + id + ' cancelled — its scans were discarded'
    : 'Order #' + id + ' ended — its items were deducted from inventory', 'info');
  refocus();
}

// Delegated so it survives renderAssistable() replacing the list.
$('assistCard').addEventListener('click', async (e) => {
  const endBtn = e.target.closest('.btn-remote-end');
  if (endBtn) return await remoteClose(endBtn, false);
  const cancelBtn = e.target.closest('.btn-remote-cancel');
  if (cancelBtn) return await remoteClose(cancelBtn, true);
  const btn = e.target.closest('.btn-assist');
  if (!btn) return;
  btn.disabled = true;
  const r = await postJson('../api_order.php',
    {action:'assist_join', order_id: Number(btn.dataset.orderId)});
  if (!r.ok) {
    btn.disabled = false;
    // Usually "already closed" — the picker was drawn up to 2.5s ago. Clear
    // the fingerprint so the next tick redraws the card from fresh state.
    lastFingerprint = null;
    flash(r.error || 'Could not join that order', 'error');
    return;
  }
  applySync(r);
  refocus();
});

// Join an order with the server picking which (see api_order.php
// 'assist_auto'), and report what it decided — the operator is looking at the
// scanner, not the Assist card. Used by command barcode 990002 and, in assist
// mode, by the first scan of the next order. Returns whether this station came
// out of it attached to an order.
async function joinNextOrderToAssist() {
  const r = await postJson('../api_order.php', {action: 'assist_auto'});
  if (!r.ok) {
    // Either several orders were open, or the one candidate closed between the
    // last poll and the scan. Clear the fingerprint so the next tick redraws
    // the card from fresh state either way.
    lastFingerprint = null;
    flash(r.error || 'Could not join an order', 'error');
    return false;
  }
  applySync(r);
  // The same rising chirp that asks for a weight: nothing is wrong, but the
  // order bar just changed under the operator and is worth a glance.
  alertBeep();
  flash((r.already ? 'Already assisting order #' : 'Now assisting order #') + r.order.id, 'info');
  refocus();
  return true;
}

// The beep switch. Saved per station, so it outlives this order, this assist
// and this browser session. The checkbox is already in its new position when
// this fires, so a refused save has to put it back — a switch that lies about
// what the station will do is worse than no switch.
$('beepSwitch').addEventListener('change', async (e) => {
  const on = e.target.checked;
  const r = await postJson('../api_order.php', {action:'scan_beep', on: on});
  if (!r || !r.ok) {
    e.target.checked = !on;
    flash('Could not save the beep setting', 'error');
    return;
  }
  state.scanBeep = on;
  flash(on ? 'This station will beep on each item you scan'
           : 'This station will stay quiet as you scan', 'info');
  refocus();
});

// The one way out of assist mode: it ends both the current assist (if any) and
// the standing offer to pick up the next order.
$('btnLeaveAssist').addEventListener('click', async () => {
  const r = await postJson('../api_order.php', {action:'assist_leave'});
  if (!r.ok) { flash(r.error || 'Could not leave assist', 'error'); return; }
  // Leaving is this station's own doing, so skip applySync's "closed by the
  // other station" notice by dropping the assist role first.
  state.role = 'idle';
  applySync(r);
  flash('Left assist — this station is idle', 'info');
  refocus();
});

// ── Hydrate the open order's existing scans (page refresh mid-order) ────────
// MUST stay at the very bottom. recomputeOrderStats() writes to `tableState`,
// which is a `const` declared above — running this any earlier hits it in the
// temporal dead zone and throws, which would silently abort every
// addEventListener call below the throw (End/Cancel/Recipe, the barcode field,
// the modal buttons). Only the ✕ button would still work, since it is the
// page's one inline onclick.
if (BOOT_SCANS.length) {
  // Ascending id order + prepend = newest on top, matching live scanning.
  BOOT_SCANS.forEach(s => appendRow(s, s.time_label));
  recomputeOrderStats(BOOT_SCANS.length);
}
// Show the Who column from the first paint when this order is already teamed,
// rather than waiting up to SYNC_MS for the first poll to switch it on.
if (state.role === 'assist' || (state.role === 'owner' && state.assistCount > 0)) {
  $('thisOrderCard').classList.add('team-on');
}
</script>
<?php renderFoot(); ?>
