<?php
require_once '../db.php';
$db = getDB();

// ── Auth gate (same persistent cookie as admin.php) ───────────────────────────
function makeAuthToken($password) {
    return hash('sha256', 'fp_admin_' . $password);
}
function isAuthenticated($db) {
    // admin_password lives in openpantry.db (see foodscanSetting() in ../db.php).
    $pw     = foodscanSetting('admin_password', 'admin');
    $cookie = $_COOKIE['fp_admin_auth'] ?? '';
    return $cookie !== '' && hash_equals(makeAuthToken($pw), $cookie);
}
if (!isAuthenticated($db)) {
    header('Location: ../admin/admin.php');
    exit;
}

// ── Handle form submission ────────────────────────────────────────────────────
$message = '';
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'remap') {
    $sourceItemName    = trim($_POST['source_item_name']    ?? '');
    $sourceConfigId    = $_POST['source_config_id']  !== '' ? (int)$_POST['source_config_id']  : null;
    $targetConfigId    = (int)($_POST['target_config_id']   ?? 0);

    if (!$sourceItemName || !$targetConfigId) {
        $message = 'Please select both a source item and a target item.';
        $msgType = 'error';
    } else {
        // Fetch target item details
        $tgt = $db->prepare("SELECT item_name, category FROM config_items WHERE id = ?");
        $tgt->execute([$targetConfigId]);
        $targetItem = $tgt->fetch(PDO::FETCH_ASSOC);

        if (!$targetItem) {
            $message = 'Target item not found in config_items.';
            $msgType = 'error';
        } else {
            // Build WHERE clause: match by config_item_id if set, otherwise by item_name
            if ($sourceConfigId !== null) {
                $stmt = $db->prepare(
                    "UPDATE order_items
                     SET item_name = :new_name, category = :new_cat, config_item_id = :new_cid
                     WHERE config_item_id = :src_cid"
                );
                $stmt->execute([
                    ':new_name' => $targetItem['item_name'],
                    ':new_cat'  => $targetItem['category'],
                    ':new_cid'  => $targetConfigId,
                    ':src_cid'  => $sourceConfigId,
                ]);
            } else {
                // Legacy rows with no config_item_id — match by item_name
                $stmt = $db->prepare(
                    "UPDATE order_items
                     SET item_name = :new_name, category = :new_cat, config_item_id = :new_cid
                     WHERE item_name = :src_name AND (config_item_id IS NULL OR config_item_id = 0)"
                );
                $stmt->execute([
                    ':new_name' => $targetItem['item_name'],
                    ':new_cat'  => $targetItem['category'],
                    ':new_cid'  => $targetConfigId,
                    ':src_name' => $sourceItemName,
                ]);
            }

            $affected = $stmt->rowCount();
            $message  = "✅ Updated {$affected} order item row" . ($affected !== 1 ? 's' : '') . ": "
                      . htmlspecialchars($sourceItemName)
                      . " → " . htmlspecialchars($targetItem['item_name'])
                      . " (config id " . $targetConfigId . ")";
            $msgType  = 'success';
        }
    }
}

// ── Load data for selects ─────────────────────────────────────────────────────

// Distinct items currently in order_items (with their config_item_id if set)
$orderItemsStmt = $db->query(
    "SELECT
        COALESCE(oi.item_name, '') AS item_name,
        oi.config_item_id,
        ci.item_name               AS config_name,
        ci.category                AS config_category,
        COUNT(*)                   AS row_count
     FROM order_items oi
     LEFT JOIN config_items ci ON ci.id = oi.config_item_id
     GROUP BY oi.item_name, oi.config_item_id
     ORDER BY oi.item_name, oi.config_item_id"
);
$orderItems = $orderItemsStmt->fetchAll(PDO::FETCH_ASSOC);

// All current config items
$configItemsStmt = $db->query(
    "SELECT id, item_name, category FROM config_items ORDER BY category, item_name"
);
$configItems = $configItemsStmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<link rel="icon" type="image/x-icon" href="../favicon.ico">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Footprints – De-duplicate Items</title>
<style>
  :root { --brown:#6B4C11; --green:#8BAF3A; --light:#F5F0E8; --border:#D4C9A8; }
  * { box-sizing:border-box; margin:0; padding:0; }
  body { font-family:Arial,sans-serif; background:var(--light); color:#333; }

  /* ── Header (matches foodscan/index.php) ── */
  .site-header {
    background:#fff; border-bottom:3px solid var(--green);
    padding:14px 24px; display:flex; align-items:center; gap:16px;
    box-shadow:0 2px 6px rgba(0,0,0,.08);
  }
  .site-header img { height:56px; display:block; }
  .site-header .header-text h1 { font-size:1.1rem; color:var(--brown); font-weight:700; text-transform:uppercase; margin:0; }
  .site-header .header-text p { font-size:.8rem; color:#777; margin:0; }
  .site-header .header-actions { margin-left:auto; display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
  .site-header .header-actions a, .site-header .header-actions button {
    color:var(--brown); text-decoration:none; background:#fff;
    border:2px solid var(--border); border-radius:7px;
    padding:8px 14px; font-size:.84rem; font-weight:700; cursor:pointer; font-family:inherit;
  }
  .site-header .header-actions a:hover, .site-header .header-actions button:hover { background:#EEE8D5; }

  .page { max-width:900px; margin:30px auto 60px; padding:0 16px; }
  h1 { font-size:1.3rem; color:var(--brown); margin-bottom:4px; }
  .subtitle { font-size:.84rem; color:#777; margin-bottom:24px; }

  .msg { border-radius:8px; padding:14px 18px; margin-bottom:20px; font-size:.9rem; }
  .msg.success { background:#D4EDDA; border:1px solid #A8D8B9; color:#276437; }
  .msg.error   { background:#F8D7DA; border:1px solid #F1AEB5; color:#8B1A1A; }

  /* ── Main card ── */
  .card {
    background:#fff; border:1px solid var(--border); border-radius:10px;
    overflow:hidden; box-shadow:0 2px 8px rgba(0,0,0,.06); margin-bottom:24px;
  }
  .card-header {
    padding:13px 20px; background:#F0EBD8; border-bottom:1px solid var(--border);
    font-size:.9rem; font-weight:800; text-transform:uppercase; letter-spacing:.5px; color:var(--brown);
  }
  .card-body { padding:22px 24px; }

  .remap-grid {
    display:grid; grid-template-columns:1fr auto 1fr; gap:16px; align-items:start;
  }
  @media(max-width:600px) { .remap-grid { grid-template-columns:1fr; } }

  .arrow-col {
    display:flex; align-items:center; justify-content:center;
    padding-top:28px; font-size:1.6rem; color:var(--brown); opacity:.5;
  }

  .field-group { display:flex; flex-direction:column; gap:8px; }
  .field-group label {
    font-size:.75rem; font-weight:700; text-transform:uppercase;
    letter-spacing:.4px; color:var(--brown);
  }
  .field-group select {
    border:1px solid var(--border); border-radius:6px;
    padding:8px 10px; font-size:.88rem; background:#fafaf5;
    width:100%; cursor:pointer;
  }
  .field-group select:focus { outline:none; border-color:var(--green); }
  .field-group .hint { font-size:.75rem; color:#999; }

  .preview-box {
    margin-top:16px; padding:14px 16px;
    background:#F5F0E8; border:1px solid var(--border); border-radius:8px;
    font-size:.85rem; color:#555; display:none;
  }
  .preview-box.visible { display:block; }
  .preview-box strong { color:var(--brown); }
  .preview-badge {
    display:inline-block; background:var(--green); color:#fff;
    font-size:.7rem; font-weight:700; border-radius:4px; padding:1px 7px;
    margin-left:6px; vertical-align:middle;
  }
  .preview-badge.legacy { background:#E07B39; }

  .btn-row { margin-top:20px; display:flex; gap:12px; align-items:center; flex-wrap:wrap; }
  .btn { border:none; border-radius:7px; padding:11px 28px; font-size:.95rem; font-weight:700; cursor:pointer; transition:background .2s; }
  .btn-brown { background:var(--brown); color:#fff; }
  .btn-brown:hover { background:#8B6420; }
  .btn-outline { background:transparent; color:var(--brown); border:2px solid var(--brown); }
  .btn-outline:hover { background:var(--brown); color:#fff; }

  /* ── Inventory table ── */
  .inv-table { width:100%; border-collapse:collapse; font-size:.85rem; }
  .inv-table th {
    text-align:left; padding:9px 14px; font-size:.74rem; text-transform:uppercase;
    letter-spacing:.5px; background:#F5F0E8; color:var(--brown); border-bottom:1px solid var(--border);
  }
  .inv-table td { padding:8px 14px; border-bottom:1px solid #F0EBD8; vertical-align:middle; }
  .inv-table tr:last-child td { border-bottom:none; }
  .inv-table tr:hover td { background:#FAFAF5; }
  .badge { display:inline-block; font-size:.7rem; font-weight:700; border-radius:4px; padding:2px 7px; color:#fff; }
  .badge-linked  { background:var(--green); }
  .badge-legacy  { background:#E07B39; }
  .badge-mismatch { background:#C62828; }
  .count-pill {
    display:inline-block; background:#EEE8D5; color:var(--brown);
    font-size:.75rem; font-weight:700; border-radius:10px; padding:2px 9px;
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
    <a href="../admin/admin.php">⚙ Admin</a>
    <a href="../report/">📊 Report</a>
    <a href="../admin/admin.php?logout=1">🔒 Log Out</a>
  </div>
</header>

<div class="page">
  <h1>Order Items De-duplicate Tool</h1>
  <p class="subtitle">
    Reassign <code>item_name</code> and <code>config_item_id</code> on historical order rows
    to match a canonical item from the current config. Use this to merge renamed or duplicate entries.
  </p>

  <?php if ($message): ?>
  <div class="msg <?= $msgType ?>"><?= $message ?></div>
  <?php endif; ?>

  <!-- ── Remap form ── -->
  <div class="card">
    <div class="card-header">🔁 Remap Item</div>
    <div class="card-body">
      <form method="POST" id="remapForm">
        <input type="hidden" name="action" value="remap">

        <div class="remap-grid">

          <!-- Source -->
          <div class="field-group">
            <label for="source_sel">Source — order_items entry to change</label>
            <select id="source_sel" name="source_item_name" onchange="updatePreview()" required>
              <option value="">— Select source item —</option>
              <?php foreach ($orderItems as $oi): ?>
              <option value="<?= htmlspecialchars($oi['item_name']) ?>"
                      data-config-id="<?= $oi['config_item_id'] !== null ? (int)$oi['config_item_id'] : '' ?>"
                      data-config-name="<?= htmlspecialchars($oi['config_name'] ?? '') ?>"
                      data-count="<?= (int)$oi['row_count'] ?>">
                <?= htmlspecialchars($oi['item_name']) ?>
                <?= $oi['config_item_id'] ? '(id:'.(int)$oi['config_item_id'].')' : '(no id)' ?>
                — <?= (int)$oi['row_count'] ?> row<?= $oi['row_count'] != 1 ? 's' : '' ?>
              </option>
              <?php endforeach; ?>
            </select>
            <input type="hidden" name="source_config_id" id="source_config_id_input" value="">
            <div class="hint">Rows currently in order_items, grouped by name + config id.</div>
          </div>

          <div class="arrow-col">→</div>

          <!-- Target -->
          <div class="field-group">
            <label for="target_sel">Target — config_items canonical item</label>
            <select id="target_sel" name="target_config_id" onchange="updatePreview()" required>
              <option value="">— Select target item —</option>
              <?php $prevCat = ''; foreach ($configItems as $ci): ?>
              <?php if ($ci['category'] !== $prevCat): $prevCat = $ci['category']; ?>
              <optgroup label="<?= htmlspecialchars($ci['category']) ?>">
              <?php endif; ?>
                <option value="<?= (int)$ci['id'] ?>"
                        data-name="<?= htmlspecialchars($ci['item_name']) ?>"
                        data-cat="<?= htmlspecialchars($ci['category']) ?>">
                  <?= htmlspecialchars($ci['item_name']) ?> (id:<?= (int)$ci['id'] ?>)
                </option>
              <?php endforeach; ?>
            </select>
            <div class="hint">Items currently in config_items (what you want the rows to become).</div>
          </div>

        </div><!-- .remap-grid -->

        <div class="preview-box" id="previewBox"></div>

        <div class="btn-row">
          <button type="submit" class="btn btn-brown">🔁 Apply Remap</button>
          <button type="button" class="btn btn-outline" onclick="document.getElementById('remapForm').reset(); document.getElementById('previewBox').classList.remove('visible');">↺ Clear</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ── Current inventory ── -->
  <div class="card">
    <div class="card-header">📋 Current order_items Inventory</div>
    <table class="inv-table">
      <thead>
        <tr>
          <th>item_name (in order_items)</th>
          <th>config_item_id</th>
          <th>Linked config name</th>
          <th>Rows</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($orderItems as $oi):
          $linked   = $oi['config_item_id'] !== null;
          $matches  = $linked && $oi['config_name'] === $oi['item_name'];
          $mismatch = $linked && $oi['config_name'] !== null && $oi['config_name'] !== $oi['item_name'];
          $orphan   = $linked && $oi['config_name'] === null;
        ?>
        <tr>
          <td><?= htmlspecialchars($oi['item_name']) ?></td>
          <td><?= $oi['config_item_id'] !== null ? (int)$oi['config_item_id'] : '<span style="color:#bbb">NULL</span>' ?></td>
          <td><?= $oi['config_name'] ? htmlspecialchars($oi['config_name']) : '<span style="color:#bbb">—</span>' ?></td>
          <td><span class="count-pill"><?= (int)$oi['row_count'] ?></span></td>
          <td>
            <?php if ($mismatch || $orphan): ?>
              <span class="badge badge-mismatch">⚠ Needs remap</span>
            <?php elseif (!$linked): ?>
              <span class="badge badge-legacy">Legacy</span>
            <?php else: ?>
              <span class="badge badge-linked">✓ Linked</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

</div><!-- .page -->

<footer style="text-align:center; padding:24px 16px; font-size:.78rem; color:#999; border-top:1px solid var(--border); margin-top:40px;">
  &copy; 2026 <strong>Chromagic Development</strong> &mdash; OpenPantry, by
  <strong>Bruce Alexander</strong>.
  Released under the
  <a href="../../LICENSE" style="color:var(--brown); text-decoration:none;">MIT License</a>.
</footer>

<script>
function updatePreview() {
  var srcSel  = document.getElementById('source_sel');
  var tgtSel  = document.getElementById('target_sel');
  var box     = document.getElementById('previewBox');
  var hiddenId = document.getElementById('source_config_id_input');

  var srcOpt  = srcSel.options[srcSel.selectedIndex];
  var tgtOpt  = tgtSel.options[tgtSel.selectedIndex];

  if (!srcSel.value || !tgtSel.value) {
    box.classList.remove('visible');
    return;
  }

  var srcName   = srcSel.value;
  var srcCid    = srcOpt ? srcOpt.getAttribute('data-config-id') : '';
  var srcCount  = srcOpt ? srcOpt.getAttribute('data-count') : '?';
  var tgtName   = tgtOpt ? tgtOpt.getAttribute('data-name') : '';
  var tgtCat    = tgtOpt ? tgtOpt.getAttribute('data-cat') : '';
  var tgtId     = tgtSel.value;

  // Pass source config_item_id to form
  hiddenId.value = srcCid || '';

  var isLegacy  = !srcCid;
  var badge     = isLegacy
    ? '<span class="preview-badge legacy">Legacy — match by name</span>'
    : '<span class="preview-badge">Match by config id ' + srcCid + '</span>';

  box.innerHTML =
    '<strong>Will update ' + srcCount + ' row' + (srcCount != 1 ? 's' : '') + ':</strong>' + badge + '<br><br>' +
    '&nbsp;&nbsp;item_name: <strong>' + escHtml(srcName) + '</strong> → <strong>' + escHtml(tgtName) + '</strong><br>' +
    '&nbsp;&nbsp;category: updated to <strong>' + escHtml(tgtCat) + '</strong><br>' +
    '&nbsp;&nbsp;config_item_id: <strong>' + (srcCid || 'NULL') + '</strong> → <strong>' + tgtId + '</strong>';

  box.classList.add('visible');
}

function escHtml(str) {
  return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
</body>
</html>
