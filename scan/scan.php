<?php
// Laser-scanner page. The hardware acts as a keyboard wedge: it types the
// barcode digits then sends Enter. The first scan auto-creates a new order;
// the End / Cancel buttons close or discard it respectively.
$GLOBALS['FS_PREFIX'] = '../';
require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../auth.php';
requireAllowedIP();
$order = currentOpenOrder();
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
<div class="container">
  <div id="orderBar" class="card" style="display:flex; align-items:center; gap:14px; flex-wrap:wrap;">
    <div style="flex:1 1 200px;">
      <div style="font-size:.75rem; text-transform:uppercase; color:#777;">Current Order</div>
      <div id="orderNumLabel" style="font-size:1.8rem; font-weight:800; color:var(--brown);">
        <?= $order ? '#' . (int)$order['id'] : '— not started —' ?>
      </div>
      <div id="orderStartLabel" style="font-size:.8rem; color:#777;">
        <?= $order ? 'Started ' . htmlspecialchars($order['started_at'])
                   : ($closedMsg ?: 'Scan an item to begin a new order') ?>
      </div>
    </div>
    <div style="display:flex; gap:8px;">
      <button id="btnEnd"    class="btn btn-primary" <?= $order ? '' : 'disabled' ?>>■ End Order</button>
      <button id="btnCancel" class="btn btn-danger"  <?= $order ? '' : 'disabled' ?>>✕ Cancel Order</button>
    </div>
  </div>

  <div class="card" id="scanCard">
    <h2>Scan Barcode</h2>
    <div class="banner info" id="scannerHint">
      <div style="font-size:1.4rem;">📷</div>
      <div>
        <strong>Scanner ready:</strong> point at a barcode and pull the trigger.
        The field below shows what the scanner is typing. Tap the field if the
        cursor leaves it. No barcode? Type the item's <em>name</em> instead —
        matches from the lookup tables appear as you type.
      </div>
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

  <style>
    .wt-overlay {
      position: fixed; inset: 0;
      background: rgba(20, 14, 0, .62);
      align-items: center; justify-content: center;
      z-index: 9999; padding: 20px;
    }
    .wt-modal {
      background: #fff; border: 1px solid var(--border);
      border-radius: 14px; width: 100%; max-width: 560px;
      box-shadow: 0 20px 60px rgba(0,0,0,.35); overflow: hidden;
      animation: wtPop .18s ease;
    }
    @keyframes wtPop { from { transform: scale(.94); opacity:0 } to { transform: scale(1); opacity:1 } }
    .wt-header { padding: 22px 28px 16px; background: var(--cat-bg); border-bottom: 1px solid var(--border); }
    .wt-eyebrow { font-size:.78rem; text-transform:uppercase; letter-spacing:.5px;
                  color: var(--brown); font-weight:700; }
    .wt-title { font-size: 2rem; color: var(--brown); margin-top: 6px;
                line-height: 1.1; word-break: break-word; }
    .wt-body { padding: 22px 28px 8px; }
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
  </style>

  <div class="card">
    <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; margin-bottom:16px;">
      <h2 style="margin:0;">This Order</h2>
      <button id="btnRecipe" class="btn btn-secondary" <?= $order ? '' : 'disabled' ?>>🍲 Suggest Recipe</button>
    </div>
    <div class="stat-grid" style="margin-bottom:12px;">
      <div class="stat"><div class="v" id="statCount">0</div><div class="k">Items Scanned</div></div>
      <div class="stat"><div class="v" id="statUnique">0</div><div class="k">Unique Generics</div></div>
      <div class="stat"><div class="v" id="statWeight">0.0</div><div class="k">Produce lbs</div></div>
    </div>
    <table class="data" id="scanTable">
      <thead><tr>
        <th>Time</th><th>Generic</th><th>Kind</th><th class="num">Qty</th><th class="num">Lbs</th><th>Barcode</th><th style="width:54px;"></th>
      </tr></thead>
      <tbody></tbody>
    </table>
    <style>
      /* Generic-name links open AI prep tips; dotted underline + help cursor
         so they read as "more info" rather than navigation. */
      .prep-link { color: var(--brown); font-weight: 600;
                   text-decoration: underline dotted; text-underline-offset: 3px;
                   cursor: help; }
      .prep-link:hover { color: var(--green); }
      .btn-x { background: var(--red); color: #fff; border: none;
               border-radius: 8px; width: 44px; height: 44px;
               font-size: 1.3rem; font-weight: 800; cursor: pointer;
               line-height: 1; padding: 0; }
      .btn-x:hover { filter: brightness(1.1); }
      .btn-x:active { transform: scale(.96); }
      .btn-x:disabled { opacity: .4; cursor: not-allowed; }
    </style>
  </div>
</div>

<script>
const state = {
  orderId: <?= $order ? (int)$order['id'] : 'null' ?>,
  pendingProduce:    null,  // { barcode, generic_name } awaiting weight entry
  pendingUnknownUPC: null,  // { barcode } awaiting manual generic name
  weightDigits:      '',    // adding-machine buffer (manual lb+oz entry)
  weightDecimal:     null,  // decimal-pounds string when in scale/decimal mode
};

// Tare (in pounds) subtracted from each entered produce weight; set on the
// Settings page in ounces.
const TARE_LBS = <?= json_encode($tareLbs) ?>;

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

const $ = (id) => document.getElementById(id);
const barcodeInput = $('barcodeInput');

function modalOpen() {
  return $('weightPrompt').style.display !== 'none'
      || $('namePrompt').style.display   !== 'none';
}
function refocus() {
  if (!modalOpen()) {
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
            || e.target.closest('#orderBar')
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

async function postJson(url, body) {
  const r = await fetch(url, {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify(body),
  });
  return r.json();
}

async function startOrderIfNeeded() {
  if (state.orderId) return true;
  const r = await postJson('../api_order.php', {action:'start'});
  if (!r.ok) { flash('Could not start order: ' + (r.error || 'unknown'), 'error'); return false; }
  state.orderId = r.order_id;
  $('orderNumLabel').textContent = '#' + r.order_id;
  $('orderStartLabel').textContent = 'Started ' + r.started_at;
  $('btnEnd').disabled = false;
  $('btnCancel').disabled = false;
  $('btnRecipe').disabled = false;
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

async function handleScan(code) {
  // Reserved command barcode: 990001 acts as the End Order trigger so the
  // operator can finish an order without touching the screen.
  if (code === '990001') {
    if (state.orderId) {
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
    // Packaged UPC that OFF couldn't resolve — prompt the operator for a
    // generic (and optional brand) so the scan can still be recorded and
    // future scans of this UPC hit the cache.
    if (lk.kind === 'packaged') {
      openNameModal(code);
      return;
    }
    flash(lk.error || 'Unknown barcode: ' + code, 'error');
    showLast('Unknown', code, '');
    return;
  }
  if (lk.kind === 'produce' && lk.needs_weight) {
    state.pendingProduce = { barcode: code, generic_name: lk.generic_name };
    state.weightDigits = '';
    state.weightDecimal = null;
    $('weightItem').textContent = lk.generic_name;
    openWeightModal();
    return;
  }
  // Packaged or count-based produce: record immediately.
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
// Enter is ignored on both fields so a stray scanner burst (digits + Enter)
// can't auto-submit a wrong generic name.
['nameBrand', 'nameGeneric'].forEach((id) => {
  $(id).addEventListener('keydown', (e) => {
    if (e.key === 'Enter') e.preventDefault();
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
function appendRow(it) {
  const tb = $('scanTable').querySelector('tbody');
  const tr = document.createElement('tr');
  const t = new Date().toLocaleTimeString();
  tr.dataset.scanId = it.id || '';
  tr.innerHTML = `<td>${t}</td>
    <td><a href="#" class="prep-link" title="How do I prepare this item?"
           data-name="${escape(it.generic_name)}">${escape(it.generic_name)}</a></td>
    <td>${it.kind}</td><td class="num">${it.quantity || ''}</td>
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
  rows.forEach(tr => {
    const cells = tr.children;
    unique.add(cells[1].textContent);
    if (cells[2].textContent === 'produce') {
      const lbs = parseFloat(cells[4].textContent);
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
</script>
<?php renderFoot(); ?>
