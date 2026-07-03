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
        <?= $order ? 'Started ' . htmlspecialchars($order['started_at']) : 'Scan an item to begin a new order' ?>
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
        cursor leaves it.
      </div>
    </div>
    <label for="barcodeInput">Barcode</label>
    <input type="text" id="barcodeInput" autocomplete="off" autocapitalize="off"
           autocorrect="off" spellcheck="false" inputmode="numeric"
           placeholder="Waiting for scan or keypad input…"
           style="font-size:1.4rem; font-family:monospace; letter-spacing:2px;"
           autofocus>

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
  </style>

  <div class="card">
    <h2>This Order</h2>
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

// Audible alert for operator-action-required events: produce weight entry,
// or an unknown UPC that needs a manual name. Web Audio so no asset file is
// needed; double tone so it's distinct from incidental beeps.
let audioCtx = null;
function alertBeep() {
  try {
    if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    if (audioCtx.state === 'suspended') audioCtx.resume();
    const playTone = (start, freq, dur) => {
      const osc  = audioCtx.createOscillator();
      const gain = audioCtx.createGain();
      osc.type = 'square';
      osc.frequency.value = freq;
      gain.gain.setValueAtTime(0, start);
      gain.gain.linearRampToValueAtTime(0.30, start + 0.012);
      gain.gain.setValueAtTime(0.30, start + dur - 0.02);
      gain.gain.linearRampToValueAtTime(0, start + dur);
      osc.connect(gain); gain.connect(audioCtx.destination);
      osc.start(start); osc.stop(start + dur + 0.01);
    };
    const t = audioCtx.currentTime;
    playTone(t,        880,  0.18);
    playTone(t + 0.22, 1175, 0.22);
  } catch (e) { /* audio unavailable — fail silently */ }
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
  resetTable();
  return true;
}

$('btnEnd').addEventListener('click', async () => {
  const r = await postJson('../api_order.php', {action:'end'});
  if (!r.ok) { alert(r.error || 'Could not end order'); return; }
  state.orderId = null;
  $('orderNumLabel').textContent = '— not started —';
  $('orderStartLabel').textContent = 'Order #' + r.order_id + ' closed at ' + r.ended_at;
  $('btnEnd').disabled = true;
  $('btnCancel').disabled = true;
  refocus();
});

$('btnCancel').addEventListener('click', async () => {
  const r = await postJson('../api_order.php', {action:'cancel'});
  if (!r.ok) { alert(r.error || 'Could not cancel order'); return; }
  state.orderId = null;
  $('orderNumLabel').textContent = '— not started —';
  $('orderStartLabel').textContent = 'Order #' + r.order_id + ' cancelled — scans discarded';
  $('btnEnd').disabled = true;
  $('btnCancel').disabled = true;
  resetTable();
  refocus();
});

barcodeInput.addEventListener('keydown', async (e) => {
  // Accept either Enter or Tab as the scanner's terminator.
  if (e.key !== 'Enter' && e.key !== 'Tab') return;
  e.preventDefault();
  const code = barcodeInput.value.trim();
  barcodeInput.value = '';
  if (!code) return;
  await handleScan(code);
});

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
  alertBeep();
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
  tr.innerHTML = `<td>${t}</td><td>${escape(it.generic_name)}</td>
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
  const b = document.createElement('div');
  b.className = 'banner ' + (kind || 'info');
  b.textContent = msg;
  $('scanCard').prepend(b);
  setTimeout(() => b.remove(), 4000);
}
</script>
<?php renderFoot(); ?>
