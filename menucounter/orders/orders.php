<?php
require_once '../db.php';
require_once '../../auth.php'; // fpRenderAccessDenied() — shared styled Access Denied wall
$db = getDB();

$allowedIp = foodscanSetting('allowed_ip', '') ?: $_SERVER['REMOTE_ADDR'];

$visitor_ip = $_SERVER['REMOTE_ADDR'];

// Render the same styled "🔒 Access Denied" wall the delivery and scan kiosks
// show, mirroring auth.php's requireAllowedIP() reasons (network first).
if ($visitor_ip !== $allowedIp) {
    // This appears if the device is NOT on the WiFi
    fpRenderAccessDenied('');
}

// Allowed-hours gate (Settings → Secure Network Access), same schedule and
// evaluation the FoodScan pages use.
if (!fsScheduleAllowsNow(foodscanSetting('access_schedule', ''))) {
    fpRenderAccessDenied('Access is closed right now. Please try again during the permitted hours.');
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<link rel="icon" type="image/x-icon" href="../favicon.ico">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Footprints – Orders</title>
<style>
  :root {
    --brown:  #6B4C11;
    --green:  #8BAF3A;
    --light:  #F5F0E8;
    --border: #D4C9A8;
    --text:   #333;
    --done:   #2E7D32;
    --warn:   #C62828;
  }
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: Arial, sans-serif; background: var(--light); color: var(--text); }

  /* ── Top bar ─────────────────────────── */
  /* OpenPantry header (matches foodscan/index.php). Fixed height so the
     full-viewport queue layout below can subtract it. Stats + actions sit
     on the right, restyled for the light bar. */
  .site-header {
    background: #fff; border-bottom: 3px solid var(--green);
    padding: 14px 24px; display: flex; align-items: center; gap: 16px;
    height: 88px; position: sticky; top: 0; z-index: 100;
    box-shadow: 0 2px 6px rgba(0,0,0,.08);
  }
  .site-header img { height: 56px; display: block; }
  .site-header .header-text h1 { font-size: 1.1rem; color: var(--brown); font-weight: 700; text-transform: uppercase; margin: 0; }
  .site-header .header-text p { font-size: .8rem; color: #777; margin: 0; }
  .site-header .header-actions { margin-left: auto; display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
  .site-header .stats { display: flex; gap: 12px; align-items: center; font-size: .85rem; }
  .site-header .stat-pill {
    background: var(--light); color: var(--brown); border: 1px solid var(--border);
    border-radius: 20px; padding: 4px 14px; font-weight: 700;
  }
  .site-header .stat-pill span { color: #777; font-weight: 400; }
  .btn-refresh {
    background: var(--green); color: #fff;
    border: none; border-radius: 7px; padding: 8px 18px;
    font-size: .85rem; font-weight: 700; cursor: pointer;
    transition: background .2s;
  }
  .btn-refresh:hover { background: #6F9430; }
  .btn-config {
    background: #fff; color: var(--brown);
    border: 2px solid var(--border); border-radius: 7px; padding: 8px 14px;
    font-size: .84rem; font-weight: 700; cursor: pointer; transition: background .2s; text-decoration: none;
  }
  .btn-config:hover { background: #EEE8D5; }

  /* ── Layout ──────────────────────────── */
  .layout { display: flex; height: calc(100vh - 88px); }

  /* ── Sidebar queue ───────────────────── */
  .sidebar {
    width: 280px;
    min-width: 240px;
    background: #fff;
    border-right: 2px solid var(--border);
    display: flex;
    flex-direction: column;
    overflow: hidden;
  }
  .sidebar-header {
    padding: 14px 16px;
    background: var(--light);
    border-bottom: 1px solid var(--border);
    font-weight: 700;
    font-size: .85rem;
    text-transform: uppercase;
    letter-spacing: .5px;
    color: var(--brown);
    display: flex;
    align-items: center;
    justify-content: space-between;
  }
  .queue-list {
    flex: 1;
    overflow-y: auto;
    padding: 8px;
    display: flex;
    flex-direction: column;
    gap: 6px;
  }
  .queue-card {
    background: #fafaf5;
    border: 2px solid var(--border);
    border-radius: 8px;
    padding: 10px 12px;
    cursor: pointer;
    transition: border-color .15s, background .15s, transform .1s;
    position: relative;
  }
  .queue-card:hover { background: #F0EBD8; border-color: var(--green); transform: translateX(2px); }
  .queue-card.active { border-color: var(--brown); background: #EEE0C0; }
  .queue-card.all-done { border-color: var(--done); background: #E8F5E9; }
  .qc-name { font-weight: 700; font-size: .92rem; color: var(--brown); margin-bottom: 3px; }
  .qc-meta { font-size: .75rem; color: #777; margin-bottom: 5px; }
  .qc-progress { font-size: .72rem; font-weight: 600; }
  .progress-bar {
    height: 5px; background: #ddd; border-radius: 3px; margin-top: 5px; overflow: hidden;
  }
  .progress-fill {
    height: 100%; background: var(--green); border-radius: 3px;
    transition: width .3s;
  }
  .progress-fill.complete { background: var(--done); }
  .qc-time { font-size: .68rem; color: #aaa; margin-top: 4px; }
  .badge-new {
    position: absolute; top: 8px; right: 8px;
    background: var(--green); color: #fff;
    font-size: .6rem; font-weight: 700; padding: 2px 6px;
    border-radius: 10px; text-transform: uppercase;
  }

  /* ── Main panel ──────────────────────── */
  .main-panel {
    flex: 1;
    overflow-y: auto;
    padding: 0;
    display: flex;
    flex-direction: column;
    min-height: 0;
  }

  /* ── Empty state ─────────────────────── */
  .empty-state {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 16px;
    color: #aaa;
    padding: 40px;
  }
  .empty-state .big-icon { font-size: 4rem; }
  .empty-state h2 { font-size: 1.2rem; color: #888; }
  .empty-state p  { font-size: .88rem; text-align: center; max-width: 320px; }

  /* ── Order detail panel ──────────────── */
  .order-detail { display: none; flex-direction: column; flex: 1; min-height: 0; }
  .order-detail.visible { display: flex; }

  .detail-header {
    padding: 16px 24px;
    background: #fff;
    border-bottom: 2px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px;
    position: sticky;
    top: 0;
    z-index: 10;
  }
  .detail-header .order-info h2 { font-size: 1.3rem; color: var(--brown); }
  .detail-header .order-info .meta { font-size: .82rem; color: #666; margin-top: 2px; }
  .detail-header .order-actions { display: flex; gap: 10px; flex-wrap: wrap; }

  .btn-complete {
    background: var(--done); color: #fff;
    border: none; border-radius: 7px; padding: 10px 22px;
    font-size: .9rem; font-weight: 700; cursor: pointer;
    transition: background .2s;
  }
  .btn-complete:hover { background: #1B5E20; }
  .btn-complete:disabled { background: #aaa; cursor: not-allowed; }
  .btn-delete {
    background: transparent; color: var(--warn);
    border: 2px solid var(--warn); border-radius: 7px; padding: 9px 18px;
    font-size: .88rem; font-weight: 700; cursor: pointer;
    transition: all .2s;
  }
  .btn-delete:hover { background: var(--warn); color: #fff; }

  /* ── Progress summary bar ────────────── */
  .progress-summary {
    padding: 10px 24px;
    background: var(--light);
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    gap: 14px;
  }
  .prog-label { font-size: .82rem; font-weight: 700; color: var(--brown); white-space: nowrap; }
  .prog-bar-wrap { flex: 1; }
  .prog-bar-bg  { height: 10px; background: #ddd; border-radius: 5px; overflow: hidden; }
  .prog-bar-fill { height: 100%; background: var(--green); border-radius: 5px; transition: width .3s; }
  .prog-bar-fill.done { background: var(--done); }
  .prog-count { font-size: .82rem; color: #555; white-space: nowrap; }

  /* ── Item list ───────────────────────── */
  .items-container { padding: 20px 24px 160px; }
  .cat-section { margin-bottom: 24px; }
  .cat-title {
    font-size: .8rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .8px;
    color: var(--brown);
    padding: 5px 0 8px;
    border-bottom: 2px solid var(--border);
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .cat-icon { font-size: 1rem; }

.pick-item {
    display: flex;
    align-items: center;
    /* Removed padding/cursor from here so the 'dead zone' doesn't click */
    border-radius: 8px;
    margin-bottom: 6px;
    background: #fff;
    border: 2px solid var(--border);
    transition: all .15s;
    user-select: none;
    padding-right: 14px; /* Keeps the 'x' button away from the right edge */
  }
  
  /* The new clickable zone for marking items complete */
  .pick-click-region {
    display: flex;
    align-items: center;
    gap: 12px;
    flex: 1;
    padding: 10px 14px;
    cursor: pointer;
    margin-right: 36px; /* The 36px 'dead zone' to the left of the + button */
  }
  .pick-click-region:hover { background: #F5FAE8; border-radius: 6px 0 0 6px; }

  .pick-item.picked {
    background: #E8F5E9;
    border-color: var(--done);
    opacity: .75;
  }
  
  /* ── Item Action Buttons ──────────────────── */
  .btn-remove-item {
    background: #f4f4f4; border: 1px solid #d4d4d4; color: #555;
    font-size: 1rem; cursor: pointer; padding: 6px 12px;
    border-radius: 6px; flex-shrink: 0; line-height: 1;
    transition: all .15s;
    box-shadow: 0 1px 2px rgba(0,0,0,0.05);
  }
  .btn-remove-item:hover { color: #fff; background: var(--warn); border-color: var(--warn); }
  
  .btn-duplicate-item {
    background: #f4f4f4; border: 1px solid #d4d4d4; color: #555;
    font-size: 1rem; cursor: pointer; padding: 6px 12px;
    border-radius: 6px; flex-shrink: 0; line-height: 1;
    transition: all .15s;
    margin-right: 12px; /* Doubles the default 12px flex gap to 24px */
    box-shadow: 0 1px 2px rgba(0,0,0,0.05);
  }
  .btn-duplicate-item:hover { color: #fff; background: var(--green); border-color: var(--green); }
  
  /* ── Notes box ───────────────────────── */
  .notes-box {
    margin: 16px 24px;
    padding: 12px 16px;
    background: #FFF9E6;
    border: 1px solid #F0DC82;
    border-radius: 8px;
  }
  .notes-box h4 { font-size: .78rem; font-weight: 700; text-transform: uppercase; letter-spacing: .4px; color: #7A6010; margin-bottom: 4px; }
  .notes-box p  { font-size: .85rem; color: #555; }

  /* ── Auto-refresh indicator ──────────── */
  .refresh-dot {
    width: 8px; height: 8px; border-radius: 50%;
    background: var(--green); display: inline-block; margin-right: 6px;
    animation: pulse 2s infinite;
  }
  @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.3} }

  /* ── Toast ───────────────────────────── */
  .toast {
    position: fixed; bottom: 24px; right: 24px;
    background: #222; color: #fff;
    padding: 12px 20px; border-radius: 8px;
    font-size: .88rem; font-weight: 600;
    transform: translateY(80px); opacity: 0;
    transition: all .3s; pointer-events: none; z-index: 999;
  }
  .toast.show { transform: translateY(0); opacity: 1; }

  /* ── Responsive ──────────────────────── */
  @media (max-width: 700px) {
    .sidebar { width: 220px; min-width: 180px; }
    .site-header .stats { display: none; }
  }
  @media (max-width: 500px) {
    .layout { flex-direction: column; }
    .sidebar { width: 100%; height: 180px; border-right: none; border-bottom: 2px solid var(--border); }
    .queue-list { flex-direction: row; overflow-x: auto; padding: 8px; flex-wrap: nowrap; }
    .queue-card { min-width: 180px; }
  }
</style>
</head>
<body>

<header class="site-header">
  <img src="../../logo.jpg" alt="Logo">
  <div class="header-text">
    <h1><span style="color:var(--green);">Open</span>Pantry</h1>
    <p>Inventory tracking management</p>
  </div>
  <div class="header-actions">
    <div class="stats" id="statsBar">
      <div class="stat-pill"><span>Pending: </span><span id="statPending">–</span></div>
      <div class="stat-pill"><span>Items Left: </span><span id="statItems">–</span></div>
    </div>
    <span class="refresh-dot" title="Auto-refreshing every 15s"></span>
    <button class="btn-refresh" onclick="loadOrders()">↺ Refresh</button>
    <a href="../admin" class="btn-config">⚙ Manage Items</a>
  </div>
</header>

<div class="layout">

  <div class="sidebar">
    <div class="sidebar-header">
      <span>📋 Order Queue</span>
      <span id="queueCount" style="background:var(--brown);color:#fff;border-radius:12px;padding:1px 8px;font-size:.75rem;">0</span>
    </div>
    <div class="queue-list" id="queueList">
      <div style="padding:20px;color:#aaa;font-size:.82rem;text-align:center;">Loading...</div>
    </div>
  </div>

  <div class="main-panel" id="mainPanel">
    <div class="empty-state" id="emptyState">
      <div class="big-icon">🛒</div>
      <h2>No Order Selected</h2>
      <p>Select an order from the queue to see its picklist, or wait for new orders to arrive.</p>
    </div>
    <div class="order-detail" id="orderDetail">
      </div>
  </div>

</div>

<div class="toast" id="toast"></div>

<footer style="text-align:center; padding:24px 16px; font-size:.78rem; color:#999; border-top:1px solid var(--border); margin-top:40px;">
  &copy; 2026 <strong>Chromagic Development</strong> &mdash; OpenPantry, by
  <strong>Bruce Alexander</strong>.
  Released under the
  <a href="../../LICENSE" style="color:var(--brown); text-decoration:none;">MIT License</a>.
</footer>

<script>
let orders = [];
let activeOrderId = null;
let previousOrdersJson = ''; // Cache the last known state to prevent redraw blinking
const CAT_ICONS = {
  'DAIRY': '🥛', 'FROZEN ITEMS': '🧊', 'SPECIALS': '☕',
  'DRY GOODS': '🥫', 'PRODUCE': '🥬', 'OTHER ITEMS': '📦'
};

// ── Load orders from API ────────────────────────────────────────────
async function loadOrders() {
  try {
    const res = await fetch('../api.php?action=get_orders');
    const data = await res.json();
    if (!data.success) throw new Error(data.error);
    
    // Check if the server data is actually different from what we are showing
    const currentOrdersJson = JSON.stringify(data.orders);
    if (currentOrdersJson === previousOrdersJson) {
      return; // No actual changes, don't trigger layout-disturbing renders!
    }

    // New data found. Update cache.
    previousOrdersJson = currentOrdersJson;
    orders = data.orders;

    // Save scroll positions before redrawing
    const queueListEl = document.getElementById('queueList');
    const queueScroll = queueListEl ? queueListEl.scrollTop : 0;

    let itemScroll = 0;
    let activeItemsEl = null;
    if (activeOrderId) {
      activeItemsEl = document.getElementById('itemsContainer_' + activeOrderId);
      if (activeItemsEl) {
        itemScroll = activeItemsEl.scrollTop;
      }
    }

    // Execute renders
    renderQueue();
    updateStats();
    
    // Re-render active order if still present
    if (activeOrderId) {
      const still = orders.find(o => o.id == activeOrderId);
      if (still) renderDetail(still);
      else showEmpty();
    }

    // Restore scroll positions after redrawing
    if (queueListEl) queueListEl.scrollTop = queueScroll;
    if (activeOrderId && activeItemsEl) {
      const newItemsEl = document.getElementById('itemsContainer_' + activeOrderId);
      if (newItemsEl) newItemsEl.scrollTop = itemScroll;
    }

  } catch(e) {
    showToast('⚠ Refresh failed: ' + e.message);
  }
}

// ── Render the sidebar queue ────────────────────────────────────────
function renderQueue() {
  const list = document.getElementById('queueList');
  document.getElementById('queueCount').textContent = orders.length;

  if (orders.length === 0) {
    list.innerHTML = `<div style="padding:30px 12px;text-align:center;color:#aaa;font-size:.82rem;">
      ✅ All orders complete!<br><br>Queue is empty.
    </div>`;
    showEmpty();
    return;
  }

  list.innerHTML = orders.map(o => {
    const total    = parseInt(o.total_items) || 0;
    const done     = parseInt(o.completed_items) || 0;
    const pct      = total > 0 ? Math.round((done/total)*100) : 0;
    const allDone  = total > 0 && done === total;
    const isNew    = (Date.now() - new Date(o.created_at.replace(' ', 'T')).getTime()) < 120000;
    const timeStr  = formatTime(o.created_at);
    return `<div class="queue-card ${allDone ? 'all-done' : ''} ${o.id == activeOrderId ? 'active' : ''}"
                 onclick="selectOrder(${o.id})" id="qc_${o.id}">
      ${isNew ? '<span class="badge-new">New</span>' : ''}
      <div class="qc-name">👤 ${escHtml(o.name)}</div>
      <div class="qc-meta">
        ${o.adults > 0 ? o.adults + ' adult' + (o.adults!=1?'s':'') : ''}
        ${o.children > 0 ? ' · ' + o.children + ' child' + (o.children!=1?'ren':'') : ''}
        &nbsp;| ${total} item${total!=1?'s':''}
      </div>
      <div class="qc-progress" style="color:${allDone?'var(--done)':'var(--brown)'}">
        ${done}/${total} picked ${allDone ? '✓' : ''}
      </div>
      <div class="progress-bar">
        <div class="progress-fill ${allDone?'complete':''}" style="width:${pct}%"></div>
      </div>
      <div class="qc-time">${timeStr}</div>
    </div>`;
  }).join('');
}

// ── Select an order ─────────────────────────────────────────────────
function selectOrder(id) {
  activeOrderId = id;
  const order = orders.find(o => o.id == id);
  if (!order) return;
  // Update active state in sidebar
  document.querySelectorAll('.queue-card').forEach(c => c.classList.remove('active'));
  const card = document.getElementById('qc_' + id);
  if (card) card.classList.add('active');
  renderDetail(order);
}

// ── Render the detail panel ─────────────────────────────────────────
function renderDetail(order) {
  const panel = document.getElementById('orderDetail');
  panel.classList.add('visible');
  document.getElementById('emptyState').style.display = 'none';

  const total   = parseInt(order.total_items) || 0;
  const done    = parseInt(order.completed_items) || 0;
  const pct     = total > 0 ? Math.round((done/total)*100) : 0;
  const allDone = total > 0 && done === total;

  // Group items by category (sorted to keep duplicates together)
  const cats = {};
  
  // 1. Create a copy and sort by Name, then ID
  const sortedItems = [...(order.items || [])].sort((a, b) => {
    const nameA = (a.item_name || "").toLowerCase();
    const nameB = (b.item_name || "").toLowerCase();
    if (nameA < nameB) return -1;
    if (nameA > nameB) return 1;
    return a.id - b.id; // Secondary sort by ID to keep them in creation order
  });

  // 2. Group the sorted items into categories
  sortedItems.forEach(item => {
    if (!cats[item.category]) cats[item.category] = [];
    cats[item.category].push(item);
  });

  const catOrder = ['DAIRY','FROZEN ITEMS','SPECIALS','DRY GOODS','PRODUCE','OTHER ITEMS'];
  const sortedCats = [...new Set([...catOrder, ...Object.keys(cats)])].filter(c => cats[c]);

  panel.innerHTML = `
    <div class="detail-header">
      <div class="order-info">
        <h2>Order #${order.id} — ${escHtml(order.name)}</h2>
        <div class="meta">
          ${order.adults > 0 ? order.adults + ' Adult' + (order.adults!=1?'s':'') : ''}
          ${order.children > 0 ? ' · ' + order.children + ' Child' + (order.children!=1?'ren':'') : ''}
          &nbsp;|&nbsp; Week: ${escHtml(order.week_date || '—')}
          &nbsp;|&nbsp; Received: ${formatTime(order.created_at)}
        </div>
      </div>
      <div class="order-actions">
        <button class="btn-delete" onclick="deleteOrder(${order.id})">🗑 Cancel</button>
        <button class="btn-complete" id="btnComplete_${order.id}"
                onclick="completeOrder(${order.id})"
                ${allDone ? '' : 'disabled'}>
          ✅ Mark Complete
        </button>
      </div>
    </div>

    <div class="progress-summary">
      <span class="prog-label">Progress</span>
      <div class="prog-bar-wrap">
        <div class="prog-bar-bg">
          <div class="prog-bar-fill ${allDone?'done':''}" id="progFill_${order.id}" style="width:${pct}%"></div>
        </div>
      </div>
      <span class="prog-count" id="progCount_${order.id}">${done} / ${total} items</span>
    </div>

    ${order.notes ? `<div class="notes-box"><h4>📝 Notes</h4><p>${escHtml(order.notes)}</p></div>` : ''}

    <div class="items-container" id="itemsContainer_${order.id}">
      ${sortedCats.map(cat => `
        <div class="cat-section" id="catSec_${order.id}_${cat.replace(/\s+/g,'_')}">
          <div class="cat-title">
            <span class="cat-icon">${CAT_ICONS[cat] || '📦'}</span>
            ${escHtml(cat)}
          </div>
          ${cats[cat].map(item => `
            <div class="pick-item ${item.completed=='1'?'picked':''}" id="pi_${item.id}">
              
              <div class="pick-click-region" onclick="toggleItem(${item.id}, ${order.id})">
                <div class="pick-checkbox">${item.completed=='1' ? '✓' : ''}</div>
                <div class="pick-item-text">
                  <div class="pick-item-name">${escHtml(item.item_name)}</div>
                  ${item.item_detail ? `<div class="pick-item-detail"><i>Size/Type:</i> <b>${escHtml(item.item_detail)}</b></div>` : ''}
                </div>
              </div>

              <button class="btn-duplicate-item" title="Duplicate this item in the order"
                      onclick="event.stopPropagation(); duplicateOrderItem(${item.id}, ${order.id}, '${escHtml(item.item_name)}', '${escHtml(item.item_detail||'')}', '${escHtml(item.category||'')}')">＋</button>
              <button class="btn-remove-item" title="Remove this item from the order"
                      onclick="event.stopPropagation(); removeOrderItem(${item.id}, ${order.id}, '${escHtml(item.item_name)}')">✕</button>
            </div>
          `).join('')}
        </div>
      `).join('')}
    </div>
  `;
}

function showEmpty() {
  activeOrderId = null;
  document.getElementById('emptyState').style.display = '';
  const panel = document.getElementById('orderDetail');
  panel.classList.remove('visible');
  panel.innerHTML = '';
}

// ── Toggle a single item ────────────────────────────────────────────
async function toggleItem(itemId, orderId) {
  const el = document.getElementById('pi_' + itemId);
  const isPicked = el.classList.contains('picked');
  const newVal = isPicked ? 0 : 1;

  // Optimistic UI
  el.classList.toggle('picked', !isPicked);
  el.querySelector('.pick-checkbox').textContent = newVal ? '✓' : '';

  try {
    const fd = new FormData();
    fd.append('action', 'toggle_item');
    fd.append('item_id', itemId);
    fd.append('completed', newVal);
    const res  = await fetch('../api.php', {method:'POST', body: fd});
    const data = await res.json();
    if (!data.success) throw new Error(data.error);

    // Update local order data
    const order = orders.find(o => o.id == orderId);
    if (order) {
      const item = (order.items||[]).find(i => i.id == itemId);
      if (item) {
        item.completed = newVal;
        order.completed_items = data.completed;
      }
      // Update progress bar
      const total  = parseInt(order.total_items) || 0;
      const done   = data.completed;
      const pct    = total > 0 ? Math.round((done/total)*100) : 0;
      const allDone = done === total && total > 0;

      const fill = document.getElementById('progFill_' + orderId);
      const cnt  = document.getElementById('progCount_' + orderId);
      const btn  = document.getElementById('btnComplete_' + orderId);
      if (fill) { fill.style.width = pct + '%'; fill.classList.toggle('done', allDone); }
      if (cnt)  cnt.textContent = done + ' / ' + total + ' items';
      if (btn)  btn.disabled = !allDone;

      // Update sidebar card
      updateSidebarCard(order);

      if (allDone) showToast('🎉 All items picked for ' + order.name + '! Ready to complete.');
    }
  } catch(e) {
    // Revert on error
    el.classList.toggle('picked', isPicked);
    el.querySelector('.pick-checkbox').textContent = isPicked ? '✓' : '';
    showToast('⚠ Error: ' + e.message);
  }
}

// ── Duplicate a single item in an order ─────────────────────────────
async function duplicateOrderItem(itemId, orderId, itemName, itemDetail, category) {
  try {
    const fd = new FormData();
    fd.append('action', 'duplicate_order_item');
    fd.append('item_id', itemId);
    fd.append('order_id', orderId);
    const res  = await fetch('../api.php', {method:'POST', body: fd});
    const data = await res.json();
    if (!data.success) throw new Error(data.error);

	// Update local order data
    const order = orders.find(o => o.id == orderId);
    if (order) {
      const newItem = { id: data.new_id, category: category, item_name: itemName, item_detail: itemDetail, completed: '0' };
      order.items = order.items || [];
      
      // Find the index of the original item
      const srcIdx = order.items.findIndex(i => i.id == itemId);
      if (srcIdx >= 0) {
        // Insert directly after the original item
        order.items.splice(srcIdx + 1, 0, newItem);
      } else {
        order.items.push(newItem);
      }

      order.total_items = data.total;
      order.completed_items = data.completed;

      renderDetail(order); // Re-render immediately
      // Re-mark active sidebar card
      document.querySelectorAll('.queue-card').forEach(c => c.classList.remove('active'));
      const card = document.getElementById('qc_' + orderId);
      if (card) card.classList.add('active');
      updateSidebarCard(order);
    }

    showToast('＋ "' + itemName + '" duplicated in order.');
  } catch(e) {
    showToast('⚠ Error: ' + e.message);
  }
}

// ── Remove a single item from an order ──────────────────────────────
async function removeOrderItem(itemId, orderId, itemName) {
  try {
    const fd = new FormData();
    fd.append('action', 'remove_order_item');
    fd.append('item_id', itemId);
    fd.append('order_id', orderId);
    const res  = await fetch('../api.php', {method:'POST', body: fd});
    const data = await res.json();
    if (!data.success) throw new Error(data.error);

    // Remove the element from the DOM
    const el = document.getElementById('pi_' + itemId);
    if (el) el.remove();

    // Update local order data
    const order = orders.find(o => o.id == orderId);
    if (order) {
      order.items = (order.items || []).filter(i => i.id != itemId);
      order.total_items     = data.total;
      order.completed_items = data.completed;

      // Update progress bar
      const total   = data.total;
      const done    = data.completed;
      const pct     = total > 0 ? Math.round((done/total)*100) : 0;
      const allDone = total > 0 && done === total;

      const fill = document.getElementById('progFill_' + orderId);
      const cnt  = document.getElementById('progCount_' + orderId);
      const btn  = document.getElementById('btnComplete_' + orderId);
      if (fill) { fill.style.width = pct + '%'; fill.classList.toggle('done', allDone); }
      if (cnt)  cnt.textContent = done + ' / ' + total + ' items';
      if (btn)  btn.disabled = !allDone;

      updateSidebarCard(order);

      // Remove empty category sections
      document.querySelectorAll('.cat-section').forEach(function(sec) {
        if (sec.querySelectorAll('.pick-item').length === 0) sec.remove();
      });
    }

    showToast('✕ "' + itemName + '" removed from order.');
  } catch(e) {
    showToast('⚠ Error: ' + e.message);
  }
}

// ── Complete an order ───────────────────────────────────────────────
async function completeOrder(orderId) {
  const order = orders.find(o => o.id == orderId);
  try {
    const fd = new FormData();
    fd.append('action', 'complete_order');
    fd.append('order_id', orderId);
    const res  = await fetch('../api.php', {method:'POST', body: fd});
    const data = await res.json();
    if (!data.success) throw new Error(data.error);

    showToast('✅ Order completed!');
    orders = orders.filter(o => o.id != orderId);
    renderQueue();
    updateStats();
    showEmpty();
  } catch(e) {
    showToast('⚠ Error: ' + e.message);
  }
}

// ── Delete / cancel an order ────────────────────────────────────────
async function deleteOrder(orderId) {
  const order = orders.find(o => o.id == orderId);
  try {
    const fd = new FormData();
    fd.append('action', 'delete_order');
    fd.append('order_id', orderId);
    const res  = await fetch('../api.php', {method:'POST', body: fd});
    const data = await res.json();
    if (!data.success) throw new Error(data.error);

    showToast('🗑 Order cancelled.');
    orders = orders.filter(o => o.id != orderId);
    renderQueue();
    updateStats();
    showEmpty();
  } catch(e) {
    showToast('⚠ Error: ' + e.message);
  }
}

// ── Helpers ─────────────────────────────────────────────────────────
function updateSidebarCard(order) {
  const total   = parseInt(order.total_items) || 0;
  const done    = parseInt(order.completed_items) || 0;
  const pct     = total > 0 ? Math.round((done/total)*100) : 0;
  const allDone = total > 0 && done === total;
  const card    = document.getElementById('qc_' + order.id);
  if (!card) return;
  card.classList.toggle('all-done', allDone);
  const progBar  = card.querySelector('.progress-fill');
  const progText = card.querySelector('.qc-progress');
  if (progBar)  { progBar.style.width = pct + '%'; progBar.classList.toggle('complete', allDone); }
  if (progText) progText.textContent = done + '/' + total + ' picked ' + (allDone ? '✓' : '');
}

function updateStats() {
  let pending = orders.length;
  let itemsLeft = orders.reduce((sum, o) => sum + (parseInt(o.total_items)||0) - (parseInt(o.completed_items)||0), 0);
  document.getElementById('statPending').textContent = pending;
  document.getElementById('statItems').textContent  = itemsLeft;
}

function formatTime(dtStr) {
  if (!dtStr) return '';
  const d = new Date(dtStr.replace(' ','T') + 'Z');
  return d.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'}) + ' ' + d.toLocaleDateString([], {month:'short',day:'numeric'});
}

function escHtml(str) {
  return String(str||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

let toastTimer;
function showToast(msg) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => t.classList.remove('show'), 3500);
}

// ── Init ─────────────────────────────────────────────────────────────
loadOrders();
// Auto-refresh every 5 seconds
setInterval(loadOrders, 5000);
</script>
</body>
</html>