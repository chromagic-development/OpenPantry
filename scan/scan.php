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
$order     = activeScanOrder();
$isAssist  = $order ? (bool)$order['is_assist'] : false;
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
// Tare (ounces) subtracted from each entered produce weight; converted to lb
// for the client-side weight math.
$tareLbs = (float)(setting('tare_oz', '0') ?? 0) / 16.0;
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
        <span id="orderBarEyebrow"><?= $isAssist ? 'Assisting Order' : 'Current Order' ?></span>
        <!-- Owner-side team indicator. Hidden until a helper actually joins, so
             a single-station pantry never sees it. -->
        <span id="teamPill" class="team-pill"
              style="<?= (!$isAssist && $assistCount > 0) ? '' : 'display:none;' ?>">👥
          <span id="teamPillCount"><?= (int)$assistCount ?></span> assisting</span>
      </div>
      <div id="orderNumLabel" style="font-size:1.8rem; font-weight:800; color:var(--brown);">
        <?= $order ? '#' . (int)$order['id'] : '— not started —' ?>
      </div>
      <div id="orderStartLabel" style="font-size:.8rem; color:#777;">
        <?php if ($order && $isAssist): ?>
          Started <?= htmlspecialchars($order['started_at']) ?> on another station
        <?php elseif ($order): ?>
          Started <?= htmlspecialchars($order['started_at']) ?>
        <?php else: ?>
          <?= $closedMsg ?: 'Scan an item to begin a new order' ?>
        <?php endif; ?>
      </div>
    </div>
    <!-- Owner controls. A helper never gets these: ending or cancelling the
         shared order stays with the station that started it. -->
    <div id="ownerControls" style="display:<?= $isAssist ? 'none' : 'flex' ?>; gap:8px;">
      <button id="btnEnd"    class="btn btn-primary" <?= ($order && !$isAssist) ? '' : 'disabled' ?>>■ End Order</button>
      <button id="btnCancel" class="btn btn-danger"  <?= ($order && !$isAssist) ? '' : 'disabled' ?>>✕ Cancel Order</button>
    </div>
    <div id="assistControls" style="display:<?= $isAssist ? 'flex' : 'none' ?>; gap:8px;">
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
        <button type="button" class="btn btn-secondary btn-assist"
                data-order-id="<?= (int)$a['id'] ?>">+ Assist</button>
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
    <label for="barcodeInput">Barcode or Item Name</label>
    <input type="text" id="barcodeInput" autocomplete="off" autocapitalize="off"
           autocorrect="off" spellcheck="false"
           placeholder="Scan, type a barcode, or type an item name…"
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
        <div class="wt-eyebrow">⚠ Unknown UPC</div>
        <h2 id="nameTitle" class="wt-title">Identify this item</h2>
        <div style="font-family:monospace; font-size:1rem; color:var(--brown); margin-top:6px;">
          <span id="nameUpc">—</span>
        </div>
      </div>
      <div class="wt-body">
        <p style="font-size:.85rem; color:#777; margin-bottom:14px;">
          Open Food Facts has no record of this UPC. Enter a generic name
          to add it to the cache so future scans recognize it automatically.
        </p>
        <div style="margin-bottom:14px;">
          <label for="nameBrand" class="wt-label">Branded Name <span style="font-weight:400; color:#999;">(optional)</span></label>
          <input type="text" id="nameBrand" autocomplete="off"
                 placeholder="e.g. Bumble Bee Solid White Tuna">
        </div>
        <div>
          <label for="nameGeneric" class="wt-label">Generic Name</label>
          <input type="text" id="nameGeneric" autocomplete="off"
                 placeholder="e.g. Canned Tuna">
        </div>
      </div>
      <div class="wt-actions">
        <button id="nameCancel" type="button" class="btn btn-secondary wt-btn">Cancel</button>
        <button id="nameSubmit" type="button" class="btn btn-primary wt-btn wt-btn-primary">💾 Save &amp; Record</button>
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

    .assist-row { display: flex; align-items: center; justify-content: space-between;
                  gap: 12px; flex-wrap: wrap; padding: 8px 0;
                  border-top: 1px solid #eee; }
    .assist-row:first-child { border-top: none; padding-top: 0; }
    .assist-order { font-size: 1.15rem; font-weight: 800; color: var(--brown); }
    .assist-meta  { font-size: .8rem; color: #777; }
    .btn-assist:disabled { opacity: .5; cursor: not-allowed; }

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
//   alertBeep() — rising two-tone chirp: produce weight entry.
//   errorBeep() — harsh descending buzz: unknown UPC needing a manual name.
let audioCtx = null;
function playTones(tones) {
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
      gain.gain.linearRampToValueAtTime(0.30, start + 0.012);
      gain.gain.setValueAtTime(0.30, start + dur - 0.02);
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
// Single crisp tone: the camera registered a barcode. A laser scanner beeps on
// its own; the camera has no hardware feedback, so this stands in for it.
function scanBeep() {
  playTones([[0, 1046, 0.12]]);   // ~C6
}

const $ = (id) => document.getElementById(id);
const barcodeInput = $('barcodeInput');

function modalOpen() {
  return $('weightPrompt').style.display !== 'none'
      || $('namePrompt').style.display   !== 'none'
      || $('pluPrompt').style.display    !== 'none';
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
// scanner feeds — so the 990001 end-order code, auto-start, the unknown-UPC
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
  // The weight window is already open for a scanned item, but the keystrokes
  // landed out here — the reading still belongs to that item. (Focus normally
  // keeps this from happening; it costs one branch to not lose a weight if it
  // does.)
  if (state.pendingProduce && $('weightPrompt').style.display !== 'none') {
    state.weightDigits  = '';
    state.weightDecimal = String(lbs);
    renderWeight();
    await submitWeight();
    return;
  }
  if (lbs <= 0) {
    flash('Scale sent ' + raw + ' — place the item on the platform and let the '
        + 'reading settle.', 'error');
    return;
  }
  if (isScaleEcho(lbs)) {
    state.lastScaleAt = Date.now();   // keep the echo window alive
    return;
  }
  // First item of a session can be a weighed one, so open the order here too.
  if (!(await startOrderIfNeeded())) return;
  state.pendingWeight = lbs;
  state.lastScaleLbs  = lbs;
  state.lastScaleAt   = Date.now();
  openPluModal(lbs);
}

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
  // First scan of a session auto-creates a new order; no Start button needed.
  if (!(await startOrderIfNeeded())) return;
  // Look up first so we know whether to ask for weight.
  const lk = await postJson('../api_scan.php', {action:'lookup', barcode: code});
  if (!lk.ok) {
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
      // Naming a UPC writes a pantry-wide lookup row, so it is the owner's
      // call, not a helper's. A helper gets the same treatment as a barcode
      // that doesn't parse: buzz, banner, and an Unknown last-scan line —
      // no modal, so the assisting station can move straight to the next item.
      if (state.role === 'assist') {
        flash('This item must be scanned by primary station', 'error');
        showLast('Unknown', code, '');
        return;
      }
      openNameModal(code);
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

// ── Unknown-UPC name-entry modal ────────────────────────────────────────
function openNameModal(barcode) {
  state.pendingUnknownUPC = { barcode };
  $('nameUpc').textContent = barcode;
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
  const generic = $('nameGeneric').value.trim();
  if (!generic) { $('nameGeneric').focus(); return; }
  const brand = $('nameBrand').value.trim();
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
  if (!rec.ok) { flash(rec.error || 'Save failed', 'error'); return; }
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
  if (!scanId) return;
  btn.disabled = true;
  const r = await postJson('../api_scan.php', {action:'delete', scan_id: scanId});
  if (!r.ok) {
    btn.disabled = false;
    flash(r.error || 'Could not remove scan', 'error');
    return;
  }
  const tr = btn.closest('tr');
  if (tr) tr.remove();
  recomputeOrderStats(r.scan_count);
  refocus();
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
function flash(msg, kind) {
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
    errorBeep();
    barcodeInput.value = '';
    hideNameMatches();
  }
  const b = document.createElement('div');
  b.className = 'banner ' + (kind || 'info');
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

  $('orderBarEyebrow').textContent = assist ? 'Assisting Order' : 'Current Order';
  $('ownerControls').style.display  = assist ? 'none' : 'flex';
  $('assistControls').style.display = assist ? 'flex' : 'none';
  $('btnEnd').disabled    = !owner;
  $('btnCancel').disabled = !owner;
  $('btnRecipe').disabled = !(owner || assist);

  $('orderNumLabel').textContent = orderId ? '#' + orderId : '— not started —';
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
  $('assistList').innerHTML = list.map(a => `
    <div class="assist-row">
      <div>
        <div class="assist-order">Order #${a.id}</div>
        <div class="assist-meta">started ${escape(a.time_label)}
          · ${a.scan_count} item${a.scan_count === 1 ? '' : 's'}${
            a.assist_count > 0 ? ' · ' + a.assist_count + ' assisting' : ''}</div>
      </div>
      <button type="button" class="btn btn-secondary btn-assist"
              data-order-id="${a.id}">+ Assist</button>
    </div>`).join('');
  card.style.display = '';
}

// Apply a sync/assist_join/assist_leave payload to the whole page.
function applySync(d) {
  lastFingerprint = d.fingerprint;
  if (d.role === 'idle') {
    // Falling out of an order we were assisting means the owner ended or
    // cancelled it — say so, since nothing on this station caused it.
    if (state.role === 'assist' && state.orderId) {
      flash('Order #' + state.orderId + ' was closed by the other station', 'info');
    }
    state.orderId = null;
    applyRole('idle', null, null, 0);
    $('orderStartLabel').textContent = 'Scan an item to begin a new order';
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

// Delegated so it survives renderAssistable() replacing the list.
$('assistCard').addEventListener('click', async (e) => {
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
