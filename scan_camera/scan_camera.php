<?php
// Camera-based scanning via html5-qrcode. Same backend endpoints as scan.php.
$GLOBALS['FS_PREFIX'] = '../';
require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../auth.php';
requireAllowedIP();
$order = currentOpenOrder();
// Tare (ounces) subtracted from each entered produce weight; converted to lb
// for the client-side weight math.
$tareLbs = (float)(setting('tare_oz', '0') ?? 0) / 16.0;
renderHead('Scan (Camera)');
renderNav('scan_camera');
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

  <div class="card">
    <h2>Camera Scanner</h2>
    <div class="banner info">
      <div style="font-size:1.4rem;">📱</div>
      <div>Tap <strong>Start Camera</strong>, allow permission, then point at a barcode.
        After each scan the camera pauses for 1.5 seconds to avoid duplicates.</div>
    </div>
    <div style="display:flex; gap:8px; margin-bottom:10px;">
      <button id="camStart" class="btn btn-primary">Start Camera</button>
      <button id="camStop"  class="btn btn-secondary" disabled>Stop Camera</button>
      <button id="manualBtn" class="btn btn-secondary">Enter manually</button>
    </div>
    <div id="reader" style="width:100%; max-width:480px; margin:0 auto;"></div>

    <div id="manualWrap" style="display:none; margin-top:12px;">
      <label for="manualInput">Barcode</label>
      <div class="row">
        <input type="text" id="manualInput" inputmode="numeric" autocomplete="off">
        <button id="manualSubmit" class="btn btn-primary" style="flex:0 0 140px;">Submit</button>
      </div>
    </div>

    <div id="lastScanWrap" style="display:none; margin-top:12px;">
      <div style="font-size:.75rem; text-transform:uppercase; color:#777;">Last scan</div>
      <div id="lastScan" style="font-size:1.4rem; font-weight:800; color:var(--brown);"></div>
      <div id="lastScanMeta" style="font-size:.8rem; color:#777;"></div>
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
      <thead><tr><th>Time</th><th>Generic</th><th>Kind</th><th class="num">Qty</th><th class="num">Lbs</th><th>Barcode</th></tr></thead>
      <tbody></tbody>
    </table>
  </div>
</div>

<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"
        onerror="this.onerror=null;this.src='https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js'"></script>
<script>
const state = {
  orderId: <?= $order ? (int)$order['id'] : 'null' ?>,
  pendingProduce: null,
  weightDigits: '',
  weightDecimal: null,
  scanner: null,
  paused: false,
  lastCode: null,
  lastAt: 0,
};

// Tare (in pounds) subtracted from each entered produce weight; set on the
// Settings page in ounces.
const TARE_LBS = <?= json_encode($tareLbs) ?>;

// Audible feedback, rendered with Web Audio so no asset file is needed. The
// AudioContext is unlocked by the Start Camera click (a user gesture), so
// resume() succeeds here. Three distinct sounds, matching scan.php:
//   scanBeep()  — single short tone: camera registered a barcode.
//   alertBeep() — rising two-tone chirp: produce weight entry.
//   errorBeep() — harsh descending buzz: barcode the lookup can't resolve.
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
function scanBeep() {
  playTones([[0, 1046, 0.12]]); // ~C6, crisp and clearly audible
}
function alertBeep() {
  playTones([[0, 880, 0.18], [0.22, 1175, 0.22]]);
}
function errorBeep() {
  playTones([[0, 330, 0.16, 'sawtooth'], [0.20, 220, 0.34, 'sawtooth']]);
}

const $ = (id) => document.getElementById(id);

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
  $('btnEnd').disabled = true; $('btnCancel').disabled = true;
});
$('btnCancel').addEventListener('click', async () => {
  const r = await postJson('../api_order.php', {action:'cancel'});
  if (!r.ok) { alert(r.error || 'Could not cancel order'); return; }
  state.orderId = null;
  $('orderNumLabel').textContent = '— not started —';
  $('orderStartLabel').textContent = 'Order #' + r.order_id + ' cancelled — scans discarded';
  $('btnEnd').disabled = true; $('btnCancel').disabled = true;
  resetTable();
});

$('camStart').addEventListener('click', async () => {
  try {
    state.scanner = new Html5Qrcode('reader');
    const config = {
      fps: 10, qrbox: { width: 280, height: 160 },
      formatsToSupport: [
        Html5QrcodeSupportedFormats.UPC_A,
        Html5QrcodeSupportedFormats.UPC_E,
        Html5QrcodeSupportedFormats.EAN_13,
        Html5QrcodeSupportedFormats.EAN_8,
        Html5QrcodeSupportedFormats.CODE_128,
      ],
    };
    await state.scanner.start({ facingMode: 'environment' }, config, onDecoded);
    $('camStart').disabled = true;
    $('camStop').disabled = false;
  } catch (e) {
    alert('Camera error: ' + e);
  }
});
$('camStop').addEventListener('click', async () => {
  if (state.scanner) {
    try { await state.scanner.stop(); } catch (_) {}
    try { state.scanner.clear(); } catch (_) {}
    state.scanner = null;
  }
  $('camStart').disabled = false; $('camStop').disabled = true;
});

function onDecoded(text) {
  if (state.paused) return;
  // Debounce identical reads within 1.5s.
  const now = Date.now();
  if (state.lastCode === text && (now - state.lastAt) < 1500) return;
  state.lastCode = text; state.lastAt = now;
  state.paused = true;
  setTimeout(() => { state.paused = false; }, 1500);
  scanBeep(); // audible confirmation the camera registered the barcode
  handleScan(text);
}

$('manualBtn').addEventListener('click', () => {
  const w = $('manualWrap');
  w.style.display = w.style.display === 'none' ? 'block' : 'none';
  if (w.style.display === 'block') $('manualInput').focus();
});
$('manualSubmit').addEventListener('click', () => {
  const c = $('manualInput').value.trim();
  if (c) { $('manualInput').value=''; handleScan(c); }
});
$('manualInput').addEventListener('keydown', e => {
  if (e.key === 'Enter') { e.preventDefault(); $('manualSubmit').click(); }
});

async function handleScan(code) {
  if (!(await startOrderIfNeeded())) return;
  const lk = await postJson('../api_scan.php', {action:'lookup', barcode: code});
  if (!lk.ok) {
    // Unknown barcode: this page has no name-entry modal, so the scan is
    // dropped. Distinct error buzz (same sound as scan.php's unknown-UPC
    // prompt) so the operator knows to look at the screen.
    errorBeep();
    flash(lk.error || 'Unknown barcode', 'error');
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
  const rec = await postJson('../api_scan.php', {action:'record', barcode: code});
  afterRecord(rec);
}

function openWeightModal() {
  const m = $('weightPrompt');
  m.style.display = 'flex';
  m.setAttribute('aria-hidden', 'false');
  weightError('');
  renderWeight();
  alertBeep();
  setTimeout(() => $('weightInput').focus(), 0);
}
function closeWeightModal() {
  const m = $('weightPrompt');
  m.style.display = 'none';
  m.setAttribute('aria-hidden', 'true');
}

// ── Weight entry: adding-machine (manual) OR decimal pounds (scale) ──────
// Manual: 1st digit = whole pounds, 2nd–3rd = ounces (buffer may grow past 3
// so a >3-digit Enter is detected and rejected, clearing for re-entry).
// Scale: an HID scale auto-types decimal pounds ending in "lb" + Enter, e.g.
// " 0.054lb" / " 1.120lb". The '.' switches to decimal mode; the leading space
// and trailing "lb" letters are ignored keystrokes.
const WEIGHT_VALID_DIGITS = 3;
const WEIGHT_BUFFER_LIMIT = 10;

function renderWeight() {
  const el = $('weightInput');
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
  if (state.weightDigits === '' && ch === '0') return;
  state.weightDigits += ch;
  renderWeight();
}
// A '.' starts decimal-pounds mode; digits already typed become whole pounds.
function startWeightDecimal() {
  weightError('');
  if (state.weightDecimal !== null) return;
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

$('weightSubmit').addEventListener('click', submitWeight);
$('weightInput').addEventListener('keydown', (e) => {
  if (e.key === 'Enter') { e.preventDefault(); submitWeight(); return; }
  if (e.key === 'Backspace' || e.key === 'Delete') { e.preventDefault(); popWeightDigit(); return; }
  if (e.key === '.') { e.preventDefault(); startWeightDecimal(); return; }
  if (e.key >= '0' && e.key <= '9') { e.preventDefault(); appendWeightDigit(e.key); return; }
  if (e.key.length === 1 && !e.ctrlKey && !e.metaKey) e.preventDefault();
});
// Mobile soft-keyboards fire `input` without keydown — sync the buffer.
$('weightInput').addEventListener('input', () => {
  const val = $('weightInput').value;
  if (val.includes('.')) {
    state.weightDecimal = (val.match(/[\d.]/g) || []).join('').slice(0, WEIGHT_BUFFER_LIMIT);
    renderWeight();
    return;
  }
  state.weightDecimal = null;
  const digits = (val.match(/\d/g) || []).join('').replace(/^0+/, '');
  state.weightDigits = digits.slice(0, WEIGHT_BUFFER_LIMIT);
  renderWeight();
});
$('weightCancel').addEventListener('click', () => {
  state.pendingProduce = null;
  state.weightDigits = '';
  state.weightDecimal = null;
  closeWeightModal();
});
// Backdrop clicks do not dismiss — operator must explicitly Save or Cancel.
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
    // Empty OR >3 digits → reject and reset; the backend never sees the bad value.
    if (!state.weightDigits || state.weightDigits.length > WEIGHT_VALID_DIGITS) {
      weightError(state.weightDigits ? 'Too many digits — enter 1 lb digit + 2 oz digits (e.g. 514).'
                                     : 'Enter a weight first.');
      state.weightDigits = '';
      renderWeight();
      $('weightInput').focus();
      return;
    }
    // Convert buffer "514" → 5 lb 14 oz → 5 + 14/16 = 5.875 lb. Inventory keeps
    // storing pure lb so totals stay in a single unit.
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
}
function afterRecord(rec) {
  if (!rec.ok) { flash(rec.error || 'Save failed', 'error'); return; }
  const it = rec.item;
  showLast(it.generic_name, it.barcode,
    it.kind === 'produce' ? it.weight_lbs + ' lb' : 'qty ' + it.quantity);
  appendRow(it); bumpStats(it, rec.scan_count);
  if (rec.warning) flash('AI mapping skipped: ' + rec.warning + '. Saved as raw name — edit under Lookup Tables.', 'warn');
}
function showLast(name, code, qtyText) {
  $('lastScanWrap').style.display = 'block';
  $('lastScan').textContent = name;
  $('lastScanMeta').textContent = code + ' · ' + qtyText;
}
const tableState = { unique: new Set(), totalWeight: 0 };
function resetTable() {
  tableState.unique = new Set(); tableState.totalWeight = 0;
  $('scanTable').querySelector('tbody').innerHTML = '';
  $('statCount').textContent='0'; $('statUnique').textContent='0'; $('statWeight').textContent='0.0';
}
function appendRow(it) {
  const tb = $('scanTable').querySelector('tbody');
  const tr = document.createElement('tr');
  tr.innerHTML = `<td>${new Date().toLocaleTimeString()}</td><td>${escape(it.generic_name)}</td>
    <td>${it.kind}</td><td class="num">${it.quantity||''}</td>
    <td class="num">${it.weight_lbs?Number(it.weight_lbs).toFixed(2):''}</td>
    <td>${escape(it.barcode)}</td>`;
  tb.prepend(tr);
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
  $('reader').parentElement.prepend(b);
  setTimeout(() => b.remove(), 4000);
}
</script>
<?php renderFoot(); ?>
