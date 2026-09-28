<?php
// Uptime monitor. A dashboard for whoever looks after the pantry's systems: it
// checks the server every few seconds whether or not anyone is scanning or
// picking, raises the same connection-lost window and sounds the scan station
// and the menu counter's pick queue use, and keeps a timestamped log of every
// problem and its recovery for as long as the page stays open.
//
// Login only — no network gate. Unlike the scan and pick pages this is meant to
// be opened from anywhere (IT at home, a manager's office), so any address may
// load it as long as it is signed in with the administrator or supervisor
// password.
//
// GET monitor.php                → the dashboard (login wall when signed out)
// GET monitor.php?action=status  → the heartbeat the dashboard polls: JSON with
//                                  today's order counts from both databases,
//                                  or 401 JSON when the login is gone
require_once __DIR__ . '/common.php';   // db.php + auth.php; America/New_York

// Scan-station orders started today, from openpantry.db. started_at is local
// time (now() in db.php), so today's bounds are local too. A cancelled order is
// deleted outright, so everything left is either still open or closed.
function monitorScanOrders(): array {
    try {
        $st = getDB()->prepare(
            "SELECT COUNT(*) AS total, COALESCE(SUM(status = 'open'), 0) AS open
               FROM orders WHERE started_at >= ? AND started_at < ?");
        $st->execute([date('Y-m-d 00:00:00'), date('Y-m-d 00:00:00', strtotime('tomorrow'))]);
        $r = $st->fetch();
        return ['ok' => true, 'total' => (int)$r['total'], 'open' => (int)$r['open']];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

// Menu-counter orders placed today, from picklist.db. Opened here with a plain
// read connection rather than menucounter/db.php, whose getDB() would collide
// with this app's own and whose opener re-runs that app's migrations on every
// call. created_at is SQLite's CURRENT_TIMESTAMP, i.e. UTC, so today's local
// bounds are converted to UTC before comparing.
function monitorMenuOrders(): array {
    $path = fsDbPath('picklist.db');
    if (!is_file($path)) return ['ok' => false, 'error' => 'picklist.db was not found'];
    try {
        $db = new PDO('sqlite:' . $path);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        // Short on purpose: the dashboard gives up on a check after 5 seconds,
        // and a wait that long would read as the whole server being down.
        $db->exec('PRAGMA busy_timeout = 2000');
        $utc  = new DateTimeZone('UTC');
        $from = (new DateTime('today'))->setTimezone($utc)->format('Y-m-d H:i:s');
        $to   = (new DateTime('tomorrow'))->setTimezone($utc)->format('Y-m-d H:i:s');
        $st = $db->prepare(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(status = 'pending'), 0)  AS pending,
                    COALESCE(SUM(status = 'complete'), 0) AS complete
               FROM orders WHERE created_at >= ? AND created_at < ?");
        $st->execute([$from, $to]);
        $r = $st->fetch();
        return ['ok' => true, 'total' => (int)$r['total'],
                'pending' => (int)$r['pending'], 'complete' => (int)$r['complete']];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

// Settings → Allowed Hours, as the dashboard needs it: whether the schedule is
// switched on at all, and whether now is inside it. The dashboard checks every
// 3 seconds while the pantry is open and once a minute otherwise, so a monitor
// left running overnight costs next to nothing. Evaluated by the same function
// the network wall uses, so "open" means exactly what it means to the stations.
function monitorHours(): array {
    $raw  = setting('access_schedule', '') ?? '';
    $data = json_decode((string)$raw, true);
    return ['enabled' => is_array($data) && !empty($data['enabled']),
            'open'    => fsScheduleAllowsNow($raw)];
}

if (($_GET['action'] ?? '') === 'status') {
    header('Cache-Control: no-store');
    // A lapsed login is not an outage — the server answered — so it gets its
    // own flag, and the dashboard says "signed out" instead of "server down".
    if (fpAuthRole() === '') {
        jsonOut(['ok' => false, 'login_required' => true, 'error' => 'Login required'], 401);
    }
    jsonOut([
        'ok'    => true,
        'scan'  => monitorScanOrders(),
        'menu'  => monitorMenuOrders(),
        'hours' => monitorHours(),
    ]);
}

requireLogin();
renderHead('Uptime Monitor');
// No nav bar, like the scan station: this page is meant to be left running,
// and a click away from it ends the monitoring and its log.
?>
<style>
  .container.monitor { max-width: 1100px; }
  .mon-bar { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
  .mon-bar h1 { font-size: 1.3rem; color: var(--brown); margin-right: auto; }
  .mon-bar .since { font-size: .8rem; color: #777; font-weight: 400; display: block; margin-top: 2px; }
  .mon-bar .btn { padding: 9px 14px; font-size: .9rem; text-decoration: none; }
  .btn-silence.on { background: #8B1A1A; border-color: #8B1A1A; color: #fff; }

  /* The one thing to read from across the room. */
  .status-card { display: flex; align-items: center; gap: 22px; padding: 22px 26px;
                 border-width: 3px; }
  .status-dot { width: 46px; height: 46px; border-radius: 50%; flex: 0 0 auto; }
  .status-card .st-title { font-size: 1.7rem; font-weight: 800; line-height: 1.15; }
  .status-card .st-detail { font-size: .9rem; color: #555; margin-top: 4px; }
  .status-card.ok        { border-color: var(--green); }
  .status-card.ok .status-dot { background: var(--green); animation: monPulse 2s infinite; }
  .status-card.ok .st-title   { color: #276437; }
  .status-card.warn      { border-color: #E0B400; background: #FFFBEA; }
  .status-card.warn .status-dot { background: #E0B400; }
  .status-card.warn .st-title   { color: #806000; }
  .status-card.down      { border-color: var(--red); background: #FDF0EE; }
  .status-card.down .status-dot { background: #C62828; animation: monAlarm 1s infinite; }
  .status-card.down .st-title   { color: #8B1A1A; }
  .status-card.blind     { border-color: #999; background: #F4F4F4; }
  .status-card.blind .status-dot { background: #999; }
  .status-card.blind .st-title   { color: #555; }
  @keyframes monPulse { 0%,100% { opacity: 1 } 50% { opacity: .35 } }
  @keyframes monAlarm { 0%,100% { box-shadow: 0 0 0 0 rgba(198,40,40,.6) }
                        50%     { box-shadow: 0 0 0 12px rgba(198,40,40,0) } }
  .btn-show-window { margin-left: auto; padding: 9px 14px; font-size: .85rem; }

  .stat .v.small { font-size: 1.15rem; }
  .stat .sub { font-size: .75rem; color: #777; margin-top: 4px; }
  .orders-asof { font-size: .8rem; color: #777; margin-top: 10px; }
  .orders-asof.stale { color: #8B1A1A; font-weight: 700; }
  .db-note { font-size: .85rem; margin-top: 10px; color: #8B1A1A; font-weight: 700; }

  .log-wrap { max-height: 440px; overflow-y: auto; border: 1px solid var(--border); border-radius: 8px; }
  .log-wrap table.data th { position: sticky; top: 0; }
  .log-time { white-space: nowrap; font-variant-numeric: tabular-nums; color: #555; }
  .log-badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: .7rem;
               font-weight: 800; letter-spacing: .3px; white-space: nowrap; color: #fff; }
  .log-badge.start    { background: var(--blue); }
  .log-badge.outage   { background: #C62828; }
  .log-badge.resolved { background: #2E7D32; }
  .log-badge.blip     { background: #B8860B; }
  .log-badge.database { background: #B8860B; }
  .log-badge.signedout{ background: #666; }
  .log-badge.note     { background: #999; }

  .mon-help { font-size: .85rem; color: #555; line-height: 1.5; }
  .mon-help li { margin: 0 0 6px 18px; }

  .mon-toast { position: fixed; bottom: 24px; right: 24px; background: #222; color: #fff;
               padding: 12px 18px; border-radius: 8px; font-size: .9rem; font-weight: 600;
               opacity: 0; transform: translateY(60px); transition: all .3s;
               pointer-events: none; z-index: 1001; }
  .mon-toast.show { opacity: 1; transform: translateY(0); }

  /* ── Connection-lost window (same design as the scan station's) ── */
  .net-overlay { position: fixed; inset: 0; z-index: 1000; padding: 20px;
                 background: rgba(40, 8, 0, .72); display: none;
                 align-items: center; justify-content: center; }
  .net-overlay.show { display: flex; }
  .net-modal { background: #fff; border: 3px solid #C62828; border-radius: 14px;
               width: 100%; max-width: 580px; max-height: calc(100vh - 40px);
               display: flex; flex-direction: column; overflow: hidden;
               box-shadow: 0 20px 60px rgba(0,0,0,.35); }
  .net-header { padding: 20px 26px 14px; background: #F8D7DA; border-bottom: 1px solid #F1AEB5; }
  .net-eyebrow { font-size: .78rem; text-transform: uppercase; letter-spacing: .5px;
                 font-weight: 700; color: #8B1A1A; }
  .net-title { font-size: 1.6rem; color: #8B1A1A; margin-top: 6px; line-height: 1.15; }
  .net-body { padding: 18px 26px 6px; overflow-y: auto; }
  .net-body p { font-size: 1rem; line-height: 1.45; }
  .net-body a { color: var(--blue); font-weight: 700; }
  .net-hint { font-size: .8rem !important; color: #777; margin-top: 12px; }
  .net-status { margin: 12px 0 8px; font-size: .8rem; color: #777; font-family: monospace; }
  .net-actions { padding: 14px 22px 18px; display: flex; gap: 10px; justify-content: flex-end;
                 flex-wrap: wrap; background: #fafaf5; border-top: 1px solid var(--border); }
  .net-actions .btn { padding: 10px 16px; font-size: .9rem; }
</style>

<div class="container monitor">
  <div class="card mon-bar">
    <h1>📡 Uptime Monitor
      <span class="since" id="monSince"></span>
    </h1>
    <button type="button" class="btn btn-secondary btn-silence" id="btnSilence"
            aria-pressed="false" title="Turn the alert sounds off or back on">🔔 Sound on</button>
    <button type="button" class="btn btn-secondary" id="btnTestSound"
            title="Play the outage and recovery sounds once, so you know what to listen for">🔊 Test sounds</button>
    <button type="button" class="btn btn-secondary" id="btnCopyLog"
            title="Copy the event log as text, to paste into an email or ticket">📋 Copy log</button>
    <a class="btn btn-secondary" href="settings/" title="Back to Settings — this stops monitoring">⚙ Settings</a>
  </div>

  <!-- Chrome keeps a page silent until someone has clicked it. A monitor is
       often opened and then left alone, so say so rather than let the first
       alarm go unheard. Hidden once sound is unlocked. -->
  <div class="banner warn" id="audioHint" style="display:none; cursor:pointer;">
    🔈 <div><strong>Click anywhere on this page once</strong> so Chrome will let it
    play alert sounds. Until then, alerts are shown but not heard.</div>
  </div>

  <div class="card status-card ok" id="statusCard">
    <div class="status-dot"></div>
    <div>
      <div class="st-title" id="stTitle">Checking…</div>
      <div class="st-detail" id="stDetail">Contacting the OpenPantry server.</div>
    </div>
    <button type="button" class="btn btn-secondary btn-show-window" id="btnShowWindow"
            style="display:none;">Show alert window</button>
  </div>

  <div class="stat-grid" style="margin-bottom:20px;">
    <div class="stat"><div class="v" id="stUptime">—</div><div class="k">Uptime since opened</div></div>
    <div class="stat"><div class="v" id="stOutages">0</div><div class="k">Outages</div>
      <div class="sub" id="stDowntime">no downtime</div></div>
    <div class="stat"><div class="v small" id="stLastCheck">—</div><div class="k">Last good check</div>
      <div class="sub" id="stResponse"></div></div>
    <div class="stat"><div class="v small" id="stChecks">0</div><div class="k">Checks made</div>
      <div class="sub" id="stFailed">none failed</div></div>
  </div>

  <div class="card">
    <h2>Orders Today</h2>
    <div class="stat-grid">
      <div class="stat"><div class="v" id="ordScan">—</div><div class="k">Scan station</div>
        <div class="sub" id="ordScanSub"></div></div>
      <div class="stat"><div class="v" id="ordMenu">—</div><div class="k">Menu counter</div>
        <div class="sub" id="ordMenuSub"></div></div>
      <div class="stat"><div class="v" id="ordTotal">—</div><div class="k">Total</div></div>
    </div>
    <div class="orders-asof" id="ordAsOf"></div>
    <div class="db-note" id="dbNote" style="display:none;"></div>
  </div>

  <div class="card">
    <h2>Event Log</h2>
    <div class="log-wrap" id="logWrap">
      <table class="data">
        <thead><tr><th style="width:190px;">Time</th><th style="width:110px;">Event</th><th>Details</th></tr></thead>
        <tbody id="logBody"></tbody>
      </table>
    </div>
  </div>

  <div class="card mon-help">
    <h2>About This Monitor</h2>
    <ul>
      <li>Checks the OpenPantry server every 3 seconds during the Allowed Hours
          set in Settings, and once a minute outside them, to keep the load on the
          server down while the pantry is closed. Any failed check switches it
          straight back to every 3 seconds, whatever the hour. If Allowed Hours
          are turned off in Settings, it checks every 3 seconds all the time.</li>
      <li>Each check also reads today's order counts from both the scan-station
          and menu-counter databases, so a database fault shows up here too. One
          missed check is logged as a blip; two in a row is an outage.</li>
      <li>It tests the connection <strong>from this computer</strong>. Run at the
          pantry, it also catches the pantry's own internet going down. Run from
          somewhere else, it watches the server only — it can't tell whether the
          pantry itself is online.</li>
      <li>The log lives in this page only: refreshing or closing it starts a new
          one. Use <em>Copy log</em> to keep it.</li>
      <li>Leave this tab open in its own window. Chrome's Memory Saver can put a
          long-idle background tab to sleep, which stops the checks; to prevent
          that, add this site under Chrome Settings → Performance →
          “Always keep these sites active”.</li>
    </ul>
  </div>
</div>

<div class="mon-toast" id="monToast"></div>

<!-- Connection-lost window. Lifted by the next successful check. Unlike the
     station pages it can be hidden: nothing here can be saved or lost, and the
     person watching may want the log behind it. The status card stays red. -->
<div class="net-overlay" id="netPrompt" aria-hidden="true">
  <div class="net-modal" role="alertdialog" aria-modal="true"
       aria-labelledby="netTitle" aria-describedby="netBody">
    <div class="net-header">
      <div class="net-eyebrow" id="netEyebrow">⚠ Connection problem</div>
      <h2 class="net-title" id="netTitle">Connection lost</h2>
    </div>
    <div class="net-body">
      <p id="netBody"></p>
      <p class="net-hint">
        Checking every 3 seconds. This window lifts by itself as soon as the
        server answers, with a rising chime unless sounds are silenced.
      </p>
      <div class="net-status" id="netStatus"></div>
    </div>
    <div class="net-actions">
      <button type="button" class="btn btn-secondary btn-silence" id="netSilence"
              aria-pressed="false">🔔 Sound on</button>
      <button type="button" class="btn btn-secondary" id="netHide">Hide window</button>
    </div>
  </div>
</div>

<script>
const $ = (id) => document.getElementById(id);

const STATUS_URL       = 'monitor.php?action=status';
const CHECK_EVERY_MS   = 3000;   // heartbeat cadence during Allowed Hours, and while anything is wrong
const IDLE_EVERY_MS    = 60000;  // …and outside Allowed Hours, while all is well
const CHECK_TIMEOUT_MS = 5000;   // a check with no reply by then has failed
const FAILS_TO_ALERT   = 2;      // consecutive failures before it's an outage
const NAG_EVERY_MS     = 15000;  // repeat the lost sound while it lasts
const SILENCE_KEY      = 'op_monitor_silenced';

// ── Sounds ───────────────────────────────────────────────────────────
// The scan station's pair, so an alarm means the same thing in every room.
//   lostBeep()     — three long falling notes: the server can't be reached.
//   restoredBeep() — the same three notes rising: it's back.
let audioCtx = null;

function unlockAudio() {
  try {
    if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    if (audioCtx.state === 'suspended') {
      audioCtx.resume().then(syncAudioHint, syncAudioHint);
    }
  } catch (e) { /* audio unavailable — fail silently */ }
  syncAudioHint();
}
function audioReady() { return !!audioCtx && audioCtx.state === 'running'; }
function syncAudioHint() { $('audioHint').style.display = audioReady() ? 'none' : 'flex'; }
['pointerdown', 'keydown', 'touchstart'].forEach((ev) =>
  window.addEventListener(ev, unlockAudio, { capture: true }));

function playTones(tones, level) {
  try {
    unlockAudio();
    const t0 = audioCtx.currentTime;
    for (const [offset, freq, dur, type] of tones) {
      const osc  = audioCtx.createOscillator();
      const gain = audioCtx.createGain();
      osc.type = type || 'square';
      osc.frequency.value = freq;
      const start = t0 + offset;
      gain.gain.setValueAtTime(0, start);
      gain.gain.linearRampToValueAtTime(level, start + 0.012);
      gain.gain.setValueAtTime(level, start + dur - 0.02);
      gain.gain.linearRampToValueAtTime(0, start + dur);
      osc.connect(gain); gain.connect(audioCtx.destination);
      osc.start(start); osc.stop(start + dur + 0.01);
    }
  } catch (e) { /* audio unavailable — fail silently */ }
}
function lostBeep() {
  playTones([[0, 784, 0.30], [0.36, 587, 0.30], [0.72, 392, 0.55]], 0.40);   // G5 D5 G4
}
function restoredBeep() {
  playTones([[0, 523, 0.16, 'triangle'], [0.18, 659, 0.16, 'triangle'],
             [0.36, 784, 0.34, 'triangle']], 0.55);                          // C5 E5 G5
}

// ── State ────────────────────────────────────────────────────────────
const mon = {
  startedAt: Date.now(),
  // Current incident. kind: 'unreachable' (the server can't be reached) or
  // 'signedout' (it answers, but no longer accepts this page's login).
  down: false, kind: null, where: null, reason: '', since: 0, entry: null,
  windowHidden: false,
  consecutiveFails: 0, firstFailAt: 0, firstFailReason: '',
  checks: 0, failedChecks: 0,
  outages: 0, downtimeMs: 0,   // server outages only
  blindMs: 0,                  // signed-out time: neither up nor down, just unknown
  lastOkAt: 0, lastMs: null,
  busy: false, lastCheckAt: 0, lastNagAt: 0,
  silenced: false,
  orders: null, ordersAt: 0,
  // Settings → Allowed Hours, from the last good check. Assumed open until the
  // server says otherwise, so the first minute is never the slow cadence.
  hoursEnabled: false, inHours: true, hoursKnown: false,
  // Per-database health, from the heartbeat's own counts.
  db: {
    scan: { label: 'Scan-station database (openpantry.db)', bad: 0, since: 0, error: '', alerted: false },
    menu: { label: 'Menu-counter database (picklist.db)',   bad: 0, since: 0, error: '', alerted: false },
  },
};

// ── Formatting ───────────────────────────────────────────────────────
function fmtStamp(ms) {
  return new Date(ms).toLocaleString([], { month: 'short', day: 'numeric',
    hour: 'numeric', minute: '2-digit', second: '2-digit' });
}
function fmtClock(ms) { return new Date(ms).toLocaleTimeString(); }
function fmtDur(ms) {
  const s = Math.max(0, Math.round(ms / 1000));
  if (s < 60) return s + ' s';
  const m = Math.floor(s / 60), h = Math.floor(m / 60), d = Math.floor(h / 24);
  if (d) return d + ' d ' + (h % 24) + ' h';
  if (h) return h + ' h ' + (m % 60) + ' min';
  return m + ' min ' + (s % 60) + ' s';
}
function escHtml(s) {
  return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
let toastTimer = null;
function toast(msg) {
  const t = $('monToast');
  t.textContent = msg;
  t.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => t.classList.remove('show'), 3000);
}

// ── Event log ────────────────────────────────────────────────────────
// Oldest first, so the first line is always the moment monitoring began.
const LOG_LABELS = { start: 'STARTED', outage: 'OUTAGE', resolved: 'RESOLVED', blip: 'BLIP',
                     database: 'DATABASE', signedout: 'SIGNED OUT', note: 'NOTE' };
const logEntries = [];

function log(type, text, at) {
  const entry = { at: at || Date.now(), type, text, row: document.createElement('tr') };
  logEntries.push(entry);
  const wrap = $('logWrap');
  const atBottom = wrap.scrollTop + wrap.clientHeight >= wrap.scrollHeight - 30;
  $('logBody').appendChild(entry.row);
  drawLogRow(entry);
  if (atBottom) wrap.scrollTop = wrap.scrollHeight;
  return entry;
}
// An outage's line is written the moment it starts and filled in once the
// diagnosis is known, rather than logging a second line a few seconds later.
function updateLog(entry, text) { entry.text = text; drawLogRow(entry); }
function drawLogRow(e) {
  e.row.innerHTML = '<td class="log-time">' + escHtml(fmtStamp(e.at)) + '</td>'
    + '<td><span class="log-badge ' + e.type + '">' + LOG_LABELS[e.type] + '</span></td>'
    + '<td>' + escHtml(e.text) + '</td>';
}

$('btnCopyLog').addEventListener('click', async () => {
  const text = 'OpenPantry uptime log — copied ' + fmtStamp(Date.now()) + '\n'
    + logEntries.map(e => fmtStamp(e.at) + '\t' + LOG_LABELS[e.type] + '\t' + e.text).join('\n');
  try {
    await navigator.clipboard.writeText(text);
  } catch (e) {
    // Clipboard API needs https; fall back to a throwaway textarea.
    const ta = document.createElement('textarea');
    ta.value = text;
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); } catch (_) {}
    ta.remove();
  }
  toast('Log copied — ' + logEntries.length + (logEntries.length === 1 ? ' entry' : ' entries'));
});

// ── Silence toggle ───────────────────────────────────────────────────
// Remembered in this browser, so a refresh doesn't flip it back on (or off)
// behind the watcher's back. Both copies of the button — toolbar and window —
// always show the same state.
function setSilenced(on, quiet) {
  mon.silenced = on;
  try { localStorage.setItem(SILENCE_KEY, on ? '1' : '0'); } catch (e) {}
  document.querySelectorAll('.btn-silence').forEach((b) => {
    b.classList.toggle('on', on);
    b.setAttribute('aria-pressed', on ? 'true' : 'false');
    b.textContent = on ? '🔕 Silenced' : '🔔 Sound on';
  });
  if (!quiet) log('note', on ? 'Sounds silenced.' : 'Sounds turned back on.');
}
document.querySelectorAll('.btn-silence').forEach((b) =>
  b.addEventListener('click', () => setSilenced(!mon.silenced)));

$('btnTestSound').addEventListener('click', () => {
  unlockAudio();
  lostBeep();
  setTimeout(restoredBeep, 1500);
  if (mon.silenced) toast('Test played — alerts are still silenced');
});
$('audioHint').addEventListener('click', unlockAudio);

// ── The check ────────────────────────────────────────────────────────
class CheckError extends Error {
  constructor(kind, message) { super(message); this.kind = kind; }
}

// One heartbeat. Anything that isn't a JSON reply from the server — a failed
// connection, no reply in time, a host error page — is a failed check. A 401
// is the server answering, so it's reported as a lapsed login, not an outage.
async function fetchStatus() {
  let r, text;
  try {
    r = await fetch(STATUS_URL, { cache: 'no-store', signal: AbortSignal.timeout(CHECK_TIMEOUT_MS) });
    text = await r.text();
  } catch (e) {
    throw new CheckError('unreachable', e && e.name === 'TimeoutError'
      ? 'no reply within ' + (CHECK_TIMEOUT_MS / 1000) + ' seconds'
      : 'the connection failed');
  }
  let d;
  try { d = JSON.parse(text); } catch (e) {
    throw new CheckError('unreachable', 'it returned an error page, HTTP ' + r.status);
  }
  if (d && d.login_required) {
    throw new CheckError('signedout', 'the server no longer accepts this page\'s login');
  }
  return d;
}

// Is the wider internet up? Asked only while the server is failing, to say
// whose problem it is. Google's connectivity-check URL (the one Chrome itself
// uses) raced against a public CDN; no-cors, so the reply is opaque, but that
// one arrived at all is the answer.
async function internetReachable() {
  if (navigator.onLine === false) return false;
  const ping = (u) => fetch(u, { mode: 'no-cors', cache: 'no-store',
                                 signal: AbortSignal.timeout(4000) });
  try {
    await Promise.any([
      ping('https://www.gstatic.com/generate_204'),
      ping('https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/package.json'),
    ]);
    return true;
  } catch (e) {
    return false;
  }
}

async function check() {
  if (mon.busy) return;
  mon.busy = true;
  mon.lastCheckAt = Date.now();
  // During an outage the internet check runs alongside, so each round can
  // say which side is at fault without waiting another round.
  const internetP = (mon.down && mon.kind === 'unreachable') ? internetReachable() : null;
  const t0 = performance.now();
  let d = null, err = null;
  try { d = await fetchStatus(); } catch (e) { err = e; }
  const ms = Math.round(performance.now() - t0);
  mon.checks++;
  try {
    if (d) onCheckOk(d, ms);
    else   await onCheckFailed(err, internetP);
  } finally {
    mon.busy = false;
    render();
  }
}

function onCheckOk(d, ms) {
  mon.lastOkAt = Date.now();
  mon.lastMs = ms;
  if (mon.down) {
    endIncident();
  } else if (mon.consecutiveFails > 0) {
    log('blip', 'One check failed (' + mon.firstFailReason + '); the next one succeeded. No alert raised.',
        mon.firstFailAt);
  }
  mon.consecutiveFails = 0;
  watchHours(d.hours);
  mon.orders = d;
  mon.ordersAt = Date.now();
  watchDatabase('scan', d.scan);
  watchDatabase('menu', d.menu);
}

async function onCheckFailed(err, internetP) {
  mon.failedChecks++;
  const kind   = (err && err.kind) || 'unreachable';
  const reason = (err && err.message) || 'unknown error';
  if (!mon.down) {
    if (++mon.consecutiveFails === 1) {
      mon.firstFailAt = mon.lastCheckAt;
      mon.firstFailReason = reason;
    }
    // A lapsed login won't fix itself on the next try, so it isn't given one.
    if (kind === 'signedout' || mon.consecutiveFails >= FAILS_TO_ALERT) {
      startIncident(kind, reason, null, mon.firstFailAt);
    }
    return;
  }
  // Already down: keep what the window says current.
  const wasTitle = incidentTitle();
  if (kind !== mon.kind) {
    // Switching between "can't reach it" and "signed out" is a new incident.
    endIncident(true);
    startIncident(kind, reason, null, Date.now());
    return;
  }
  mon.reason = reason;
  if (kind === 'unreachable') {
    mon.where = (await (internetP || internetReachable())) ? 'server' : 'internet';
    const nowTitle = incidentTitle();
    if (mon.entry && mon.entry.pending) {
      finishOutageEntry();
    } else if (nowTitle !== wasTitle) {
      log('outage', 'Now: ' + nowTitle + ' (' + reason + ').');
    }
  }
}

// ── Incidents ────────────────────────────────────────────────────────
function startIncident(kind, reason, where, at) {
  if (mon.down) return;
  Object.assign(mon, { down: true, kind, reason, where, since: at || Date.now(),
                       windowHidden: false, lastNagAt: Date.now() });
  if (kind === 'signedout') {
    mon.entry = log('signedout', 'Signed out: ' + reason
      + '. Monitoring is paused until someone logs in again.', mon.since);
  } else {
    mon.outages++;
    mon.entry = log('outage', incidentTitle() + ' (' + reason + ')'
      + (where ? '.' : '. Checking whether it is the internet or the server…'), mon.since);
    mon.entry.pending = !where;
    // Diagnose straight away rather than leave "checking…" up for a round.
    if (!where) {
      internetReachable().then((ok) => {
        if (mon.down && mon.kind === 'unreachable' && mon.where === null) {
          mon.where = ok ? 'server' : 'internet';
          finishOutageEntry();
          render();
        }
      });
    }
  }
  if (!mon.silenced) lostBeep();
  render();
}

function finishOutageEntry() {
  if (!mon.entry || !mon.entry.pending) return;
  mon.entry.pending = false;
  updateLog(mon.entry, incidentTitle() + ' (' + mon.reason + ').');
}

// `quiet`: closing one incident only to open another of the other kind, so
// no chime — and for a sign-out, no claim that anyone signed back in.
function endIncident(quiet) {
  if (!mon.down) return;
  const lasted = Date.now() - mon.since;
  if (mon.kind === 'signedout') {
    mon.blindMs += lasted;
    log(quiet ? 'note' : 'resolved', quiet
      ? 'Signed-out period ended after ' + fmtDur(lasted) + ': the server has stopped answering.'
      : 'Signed in again — monitoring resumed after ' + fmtDur(lasted) + '.');
  } else {
    finishOutageEntry();
    mon.downtimeMs += lasted;
    log('resolved', 'Connection restored — the server is answering again. Outage lasted '
      + fmtDur(lasted) + '.');
  }
  Object.assign(mon, { down: false, kind: null, where: null, reason: '', entry: null });
  if (!quiet && !mon.silenced) restoredBeep();
}

function incidentTitle() {
  if (mon.kind === 'signedout') return 'Signed out — monitoring paused';
  if (mon.where === 'internet') return 'No internet connection';
  if (mon.where === 'server')   return 'The pantry server isn\'t responding';
  return 'Connection lost';
}

function incidentBody() {
  if (mon.kind === 'signedout') {
    return 'The server is answering, but it no longer accepts this page\'s login — '
         + 'the password may have been changed, or someone logged out in another tab. '
         + '<a href="settings/" target="_blank" rel="noopener">Log in again in a new tab</a> '
         + 'and this page picks up where it left off.';
  }
  if (mon.where === 'internet') {
    return escHtml((navigator.onLine === false
             ? 'This computer is not connected to any network, '
             : 'This computer can\'t reach the internet, ')
         + 'so it can\'t reach the OpenPantry server either. If this monitor is at the '
         + 'pantry, the scan stations and the menu counter are almost certainly cut off '
         + 'too. Check the Wi-Fi or network cable, and the pantry\'s router and modem.');
  }
  if (mon.where === 'server') {
    return escHtml('This computer\'s internet connection is working, but the OpenPantry '
         + 'server is not working (' + mon.reason + '). No station can save anything '
         + 'until it recovers. This is a problem with the hosting server, not the '
         + 'pantry\'s network — restarting the router won\'t help. If it lasts more than '
         + 'a few minutes, contact the OpenPantry hosting provider.');
  }
  return escHtml('This computer can\'t reach the OpenPantry server (' + mon.reason
       + '). Checking whether it\'s the internet or the server…');
}

// ── Allowed Hours ────────────────────────────────────────────────────
// How often to check right now. Fast whenever the answer matters: during
// Allowed Hours, and at any hour once a check has failed, an incident is open
// or a database is misbehaving — the two-strike rule and the recovery probe
// both need the next check to come quickly. Otherwise once a minute.
function checkEvery() {
  const troubled = mon.down || mon.consecutiveFails > 0
                || Object.values(mon.db).some(s => s.bad > 0);
  return (troubled || mon.inHours) ? CHECK_EVERY_MS : IDLE_EVERY_MS;
}

// The switch between cadences is noted in the log, so a gap between entries
// overnight reads as intended rather than as the page having stalled.
function watchHours(h) {
  if (!h) return;   // an older server that doesn't report hours: stay fast
  const was = mon.hoursKnown ? mon.inHours : null;
  mon.hoursEnabled = !!h.enabled;
  mon.inHours = !!h.open;
  mon.hoursKnown = true;
  if (was === mon.inHours) return;
  if (!mon.inHours) {
    log('note', 'Outside Allowed Hours — checking every ' + (IDLE_EVERY_MS / 1000)
      + ' seconds until they begin.');
  } else if (was === false) {
    log('note', 'Allowed Hours began — checking every ' + (CHECK_EVERY_MS / 1000) + ' seconds.');
  }
}

// ── Database health ──────────────────────────────────────────────────
// The heartbeat reads both databases, so a fault in either shows up even while
// the server itself answers. Same two-strikes rule as the connection: SQLite
// can be briefly busy, and one slow read isn't worth an alarm.
function watchDatabase(key, res) {
  const s = mon.db[key];
  if (res && res.ok) {
    if (s.alerted) {
      log('resolved', s.label + ' is readable again after ' + fmtDur(Date.now() - s.since) + '.');
    } else if (s.bad === 1) {
      log('blip', s.label + ' failed one read (' + s.error + '); the next one succeeded.', s.since);
    }
    s.bad = 0; s.alerted = false; s.error = '';
    return;
  }
  s.error = (res && res.error) || 'no answer';
  if (++s.bad === 1) s.since = Date.now();
  if (s.bad === FAILS_TO_ALERT && !s.alerted) {
    s.alerted = true;
    log('database', s.label + ' can\'t be read: ' + s.error
      + '. Stations using it will fail to save until this clears.', s.since);
    if (!mon.silenced) lostBeep();
  }
}

// ── Drawing ──────────────────────────────────────────────────────────
function render() {
  const now = Date.now();
  const elapsed = now - mon.startedAt;
  const current = mon.down ? now - mon.since : 0;
  const down = mon.downtimeMs + (mon.down && mon.kind === 'unreachable' ? current : 0);
  const blind = mon.blindMs + (mon.down && mon.kind === 'signedout' ? current : 0);
  const watched = Math.max(1, elapsed - blind);

  $('monSince').textContent = 'Monitoring since ' + fmtStamp(mon.startedAt)
    + ' · ' + fmtDur(elapsed) + ' · checking every ' + (checkEvery() / 1000) + ' s'
    + (!mon.inHours && checkEvery() === IDLE_EVERY_MS ? ' (outside Allowed Hours)' : '');

  // Status card
  const dbBad = Object.values(mon.db).filter(s => s.alerted);
  const card = $('statusCard');
  let cls, title, detail;
  if (mon.down) {
    cls = mon.kind === 'signedout' ? 'blind' : 'down';
    title = incidentTitle();
    detail = (mon.kind === 'signedout' ? 'Paused ' : 'Down for ') + fmtDur(current)
      + ' (since ' + fmtClock(mon.since) + ') · ' + mon.reason;
  } else if (!mon.lastOkAt) {
    cls = 'ok'; title = 'Checking…'; detail = 'Contacting the OpenPantry server.';
  } else if (dbBad.length) {
    cls = 'warn'; title = 'Server up — database problem';
    detail = dbBad.map(s => s.label + ': ' + s.error).join(' · ');
  } else {
    cls = 'ok'; title = 'Online — all systems normal';
    detail = 'The server is answering and both databases are readable.';
  }
  card.className = 'card status-card ' + cls;
  $('stTitle').textContent = title;
  $('stDetail').textContent = detail;
  $('btnShowWindow').style.display = (mon.down && mon.windowHidden) ? '' : 'none';
  document.title = (mon.down ? (mon.kind === 'signedout' ? '⏸ Signed out' : '🔴 OUTAGE')
                             : (dbBad.length ? '🟡 Database problem' : '🟢 Online'))
                 + ' – Uptime Monitor';

  // Stats
  $('stUptime').textContent = mon.checks ? (100 * (1 - down / watched)).toFixed(2) + '%' : '—';
  $('stOutages').textContent = mon.outages;
  $('stDowntime').textContent = down ? fmtDur(down) + ' total downtime' : 'no downtime';
  $('stLastCheck').textContent = mon.lastOkAt ? fmtClock(mon.lastOkAt) : '—';
  $('stResponse').textContent = mon.lastMs !== null ? 'answered in ' + mon.lastMs + ' ms' : '';
  $('stChecks').textContent = mon.checks.toLocaleString();
  $('stFailed').textContent = mon.failedChecks ? mon.failedChecks + ' failed' : 'none failed';

  // Orders today
  const o = mon.orders;
  if (o) {
    const sc = o.scan || {}, mc = o.menu || {};
    $('ordScan').textContent = sc.ok ? sc.total : '⚠';
    $('ordScanSub').textContent = sc.ok ? (sc.open ? sc.open + ' in progress' : 'none in progress')
                                        : 'database unreadable';
    $('ordMenu').textContent = mc.ok ? mc.total : '⚠';
    $('ordMenuSub').textContent = mc.ok ? mc.pending + ' pending · ' + mc.complete + ' complete'
                                        : 'database unreadable';
    $('ordTotal').textContent = (sc.ok ? sc.total : 0) + (mc.ok ? mc.total : 0)
                              + ((sc.ok && mc.ok) ? '' : '+');
    const stale = mon.down;
    $('ordAsOf').textContent = (stale ? '⚠ Last known counts, as of ' : 'Updated ')
      + fmtClock(mon.ordersAt) + (stale ? ' — the server can\'t be reached right now.' : '');
    $('ordAsOf').classList.toggle('stale', stale);
  }
  $('dbNote').style.display = dbBad.length ? 'block' : 'none';
  $('dbNote').textContent = dbBad.map(s => '⚠ ' + s.label + ' can\'t be read: ' + s.error).join('\n');

  // Window
  const showWin = mon.down && !mon.windowHidden;
  $('netPrompt').classList.toggle('show', showWin);
  $('netPrompt').setAttribute('aria-hidden', showWin ? 'false' : 'true');
  if (mon.down) {
    $('netEyebrow').textContent = mon.kind === 'signedout' ? '⏸ Monitoring paused' : '⚠ Connection problem';
    $('netTitle').textContent = incidentTitle();
    // Only when it changes: this runs every second, and rebuilding the
    // paragraph under the pointer would swallow a click on its link.
    const body = incidentBody();
    if ($('netBody').dataset.html !== body) {
      $('netBody').innerHTML = body;
      $('netBody').dataset.html = body;
    }
    $('netStatus').textContent = 'Started ' + fmtClock(mon.since) + ' · ' + fmtDur(current)
      + ' so far · last check ' + fmtClock(mon.lastCheckAt);
  }
}

$('netHide').addEventListener('click', () => { mon.windowHidden = true; render(); });
$('btnShowWindow').addEventListener('click', () => { mon.windowHidden = false; render(); });

// ── Scheduling ───────────────────────────────────────────────────────
// Driven from a Web Worker's timer rather than the page's own: Chrome slows a
// background tab's timers to once a minute after a few minutes hidden, which
// would turn a 3-second heartbeat into a 60-second one exactly when nobody is
// looking. A worker's timer isn't held back that way, and its message wakes
// the page on schedule.
function tick() {
  const now = Date.now();
  if (!mon.busy && now - mon.lastCheckAt >= checkEvery()) check();
  if (mon.down && !mon.silenced && now - mon.lastNagAt >= NAG_EVERY_MS) {
    mon.lastNagAt = now;
    lostBeep();
  }
  render();
}
function startTicker() {
  try {
    const src = 'setInterval(function () { postMessage(0); }, 1000);';
    const w = new Worker(URL.createObjectURL(new Blob([src], { type: 'text/javascript' })));
    w.onmessage = tick;
    return;
  } catch (e) { /* no workers — fall back to the page's own timer */ }
  setInterval(tick, 1000);
}

// Chrome reports the network adapter going away at once — no need to wait two
// checks. 'online' only brings the next check forward; the check decides.
window.addEventListener('offline', () => {
  if (!mon.down) startIncident('unreachable', 'this computer has no network connection', 'internet');
});
window.addEventListener('online', () => { if (mon.down) mon.lastCheckAt = 0; });

// ── Start ────────────────────────────────────────────────────────────
(function start() {
  let silenced = false;
  try { silenced = localStorage.getItem(SILENCE_KEY) === '1'; } catch (e) {}
  setSilenced(silenced, true);
  const nav = (performance.getEntriesByType && performance.getEntriesByType('navigation')[0]) || null;
  log('start', 'Monitoring started (page ' + (nav && nav.type === 'reload' ? 'refreshed' : 'opened')
    + '). Checking the server every ' + (CHECK_EVERY_MS / 1000) + ' seconds during Allowed Hours'
    + ' and every ' + (IDLE_EVERY_MS / 1000) + ' seconds outside them'
    + (silenced ? '; sounds are silenced.' : '.'));
  unlockAudio();   // succeeds only if Chrome already allows sound here
  check();
  startTicker();
})();
</script>
<?php renderFoot(); ?>
