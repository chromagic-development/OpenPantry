<?php
// Manage produce codes and UPC -> generic mappings.
$GLOBALS['FS_PREFIX'] = '../';
require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../auth.php';
requireLogin();
$db = getDB();

$msg = null;
$msgKind = 'success';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'produce_add') {
        $code = trim((string)$_POST['code']);
        $name = trim((string)$_POST['name']);
        $unit = ($_POST['unit'] ?? 'each') === 'lb' ? 'lb' : 'each';
        if ($code !== '' && $name !== '') {
            $db->prepare(
                "INSERT INTO produce_lookup (code, generic_name, unit) VALUES (?, ?, ?)
                 ON CONFLICT(code) DO UPDATE SET generic_name=excluded.generic_name, unit=excluded.unit"
            )->execute([$code, $name, $unit]);
            $msg = "Saved produce code $code → $name";
        }
    } elseif ($action === 'produce_delete') {
        $db->prepare("DELETE FROM produce_lookup WHERE code=?")->execute([$_POST['code'] ?? '']);
        $msg = 'Produce code removed.';
    } elseif ($action === 'upc_edit') {
        $upc  = trim((string)$_POST['upc']);
        $gen  = trim((string)$_POST['generic_name']);
        if ($upc !== '' && $gen !== '') {
            $oldName = $db->prepare("SELECT generic_name FROM upc_lookup WHERE upc=?");
            $oldName->execute([$upc]);
            $prev = $oldName->fetchColumn();

            $db->prepare(
                "INSERT INTO upc_lookup (upc, brand_name, generic_name, source, created_at, updated_at)
                 VALUES (?, '', ?, 'manual', ?, ?)
                 ON CONFLICT(upc) DO UPDATE SET generic_name=excluded.generic_name,
                   source = CASE WHEN upc_lookup.source='off+ai' THEN 'manual' ELSE upc_lookup.source END,
                   updated_at = excluded.updated_at"
            )->execute([$upc, $gen, now(), now()]);

            // Rename propagation: when an existing UPC mapping is renamed,
            // also rename historical scans of *this* UPC. If no other UPC or
            // produce code still uses the old generic name, fold its inventory
            // row into the new name (or rename it outright).
            if ($prev && $prev !== $gen) {
                $db->prepare("UPDATE scans SET generic_name=? WHERE barcode=?")
                   ->execute([$gen, $upc]);

                $stillUsed = $db->prepare(
                    "SELECT 1 FROM upc_lookup WHERE generic_name=? AND upc!=?
                     UNION SELECT 1 FROM produce_lookup WHERE generic_name=?"
                );
                $stillUsed->execute([$prev, $upc, $prev]);
                if (!$stillUsed->fetchColumn()) {
                    $db->prepare(
                        "INSERT INTO inventory (generic_name, count, unit, updated_at)
                         SELECT ?, count, unit, ? FROM inventory WHERE generic_name=?
                         ON CONFLICT(generic_name) DO UPDATE SET
                           count = inventory.count + excluded.count,
                           updated_at = excluded.updated_at"
                    )->execute([$gen, now(), $prev]);
                    $db->prepare("DELETE FROM inventory WHERE generic_name=?")
                       ->execute([$prev]);
                }
            }
            $msg = "Saved UPC $upc → $gen";
        }
    } elseif ($action === 'upc_delete') {
        $db->prepare("DELETE FROM upc_lookup WHERE upc=?")->execute([$_POST['upc'] ?? '']);
        $msg = 'UPC mapping removed.';
    } elseif ($action === 'upc_add') {
        $upc   = trim((string)($_POST['upc']          ?? ''));
        $brand = trim((string)($_POST['brand_name']   ?? ''));
        $gen   = trim((string)($_POST['generic_name'] ?? ''));
        if ($upc === '' || $gen === '') {
            $msg = 'UPC and generic name are both required.';
            $msgKind = 'error';
        } else {
            $exists = $db->prepare('SELECT 1 FROM upc_lookup WHERE upc = ?');
            $exists->execute([$upc]);
            if ($exists->fetchColumn()) {
                $msg = "UPC $upc is already cached — edit it in the table above.";
                $msgKind = 'warn';
            } else {
                $db->prepare(
                    "INSERT INTO upc_lookup (upc, brand_name, generic_name, source, created_at)
                     VALUES (?, ?, ?, 'manual', ?)"
                )->execute([$upc, $brand, $gen, now()]);
                $msg = "Added UPC $upc → $gen";
            }
        }
    }
}

$produce = $db->query("SELECT code, generic_name, unit FROM produce_lookup ORDER BY generic_name COLLATE NOCASE")->fetchAll();
// Show the 500 most recently touched UPCs, sorted alphabetically by generic name.
$upcs    = $db->query(
    "SELECT * FROM (
       SELECT upc, brand_name, generic_name, source, created_at FROM upc_lookup
       ORDER BY updated_at DESC, created_at DESC LIMIT 500
     ) ORDER BY generic_name COLLATE NOCASE"
)->fetchAll();

renderHead('Lookup Tables');
renderNav('lookup');
?>
<div class="container">
  <?php if ($msg): ?>
    <div class="banner <?= htmlspecialchars($msgKind) ?>">
      <?= $msgKind === 'success' ? '✅' : '⚠' ?> <?= htmlspecialchars($msg) ?>
    </div>
  <?php endif; ?>

  <div class="card">
    <h2>Produce Codes</h2>
    <p style="color:#777; font-size:.85rem; margin-bottom:12px;">
      4–5 digit PLU codes, or 12-digit pantry labels starting with 4.
    </p>
    <form method="post" class="row" style="margin-bottom:14px;">
      <input type="hidden" name="action" value="produce_add">
      <div><label>Code</label><input type="text" name="code" required placeholder="e.g. 4087"></div>
      <div style="flex:2;"><label>Generic Name</label><input type="text" name="name" required placeholder="e.g. Tomatoes"></div>
      <div>
        <label>Unit</label>
        <select name="unit"><option value="lb">lb (prompts for weight)</option><option value="each">each (count of 1)</option></select>
      </div>
      <div style="flex:0 0 100px;"><label>&nbsp;</label><button class="btn btn-primary btn-block">Add</button></div>
    </form>
    <div style="margin-bottom:10px;">
      <input type="search" id="prodSearch" placeholder="🔍 Filter by code or generic name…"
             oninput="filterLookup('prodSearch', 'prodTable', 'prodCount')"
             style="max-width:340px;">
      <span id="prodCount" style="font-size:.8rem; color:#777; margin-left:10px;"><?= count($produce) ?> codes</span>
    </div>
    <table class="data" id="prodTable">
      <thead><tr><th>Code</th><th>Generic Name</th><th>Unit</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($produce as $p): ?>
        <tr data-search="<?= htmlspecialchars(strtolower($p['code'] . ' ' . $p['generic_name'])) ?>">
          <td><?= htmlspecialchars($p['code']) ?></td>
          <td><?= htmlspecialchars($p['generic_name']) ?></td>
          <td><?= htmlspecialchars($p['unit']) ?></td>
          <td>
            <form method="post" style="display:inline;">
              <input type="hidden" name="action" value="produce_delete">
              <input type="hidden" name="code" value="<?= htmlspecialchars($p['code']) ?>">
              <button class="btn btn-secondary" style="padding:4px 10px; font-size:.8rem;"
                onclick="return confirm('Delete <?= htmlspecialchars($p['code']) ?>?')">Remove</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="card">
    <h2>Add UPC Manually</h2>
    <p style="color:#777; font-size:.85rem; margin-bottom:12px;">
      Use this for UPCs that Open Food Facts can't resolve. The mapping is
      written to the cache below with source <code>manual</code> and used
      for all future scans of this code.
    </p>
    <form method="post" class="row" style="margin-bottom:0;">
      <input type="hidden" name="action" value="upc_add">
      <div style="flex:1 1 160px;">
        <label>UPC</label>
        <input type="text" name="upc" required inputmode="numeric"
               autocomplete="off" placeholder="e.g. 012345678905">
      </div>
      <div style="flex:2 1 240px;">
        <label>Branded Name <span style="font-weight:400; color:#999;">(optional)</span></label>
        <input type="text" name="brand_name" autocomplete="off"
               placeholder="e.g. Bumble Bee Solid White Tuna">
      </div>
      <div style="flex:2 1 200px;">
        <label>Generic Name</label>
        <input type="text" name="generic_name" required autocomplete="off"
               placeholder="e.g. Canned Tuna">
      </div>
      <div style="flex:0 0 100px;"><label>&nbsp;</label><button class="btn btn-primary btn-block">Add</button></div>
    </form>
  </div>

  <div class="card">
    <h2>UPC → Generic Cache</h2>
    <p style="color:#777; font-size:.85rem; margin-bottom:12px;">
      Populated automatically when a new UPC is scanned (Open Food Facts +
      OpenAI mapping). Edit a generic name here if the AI mislabeled it.
    </p>
    <div style="margin-bottom:10px;">
      <input type="search" id="upcSearch" placeholder="🔍 Filter by UPC, brand, or generic name…"
             oninput="filterLookup('upcSearch', 'upcTable', 'upcCount')"
             style="max-width:340px;">
      <span id="upcCount" style="font-size:.8rem; color:#777; margin-left:10px;"><?= count($upcs) ?> UPCs</span>
    </div>
    <!-- Confine the wide cache table to the card; scroll horizontally instead
         of letting the First Seen column bleed past the card's right edge. -->
    <div style="width:100%; overflow-x:auto; -webkit-overflow-scrolling:touch;">
    <table class="data" id="upcTable">
      <thead><tr>
        <th>UPC</th><th>Branded Name (from OFF)</th>
        <th>Generic</th><th>Source</th><th>First Seen</th><th></th>
      </tr></thead>
      <tbody>
        <?php foreach ($upcs as $u): ?>
        <tr data-search="<?= htmlspecialchars(strtolower($u['upc'] . ' ' . ($u['brand_name'] ?? '') . ' ' . $u['generic_name'])) ?>">
          <td><?= htmlspecialchars($u['upc']) ?></td>
          <td style="font-size:.8rem; color:#666;"><?= htmlspecialchars($u['brand_name'] ?? '') ?></td>
          <td>
            <form method="post" style="display:flex; gap:4px;">
              <input type="hidden" name="action" value="upc_edit">
              <input type="hidden" name="upc" value="<?= htmlspecialchars($u['upc']) ?>">
              <input type="text" name="generic_name" value="<?= htmlspecialchars($u['generic_name']) ?>" style="min-width:160px;">
              <button class="btn btn-primary" style="padding:6px 10px; font-size:.8rem; flex:0 0 auto;">Save</button>
            </form>
          </td>
          <td><span style="font-size:.75rem; padding:2px 6px; background:var(--cat-bg); border-radius:4px;"><?= htmlspecialchars($u['source']) ?></span></td>
          <td style="font-size:.75rem; color:#777;"><?= htmlspecialchars($u['created_at']) ?></td>
          <td>
            <form method="post" style="display:inline;">
              <input type="hidden" name="action" value="upc_delete">
              <input type="hidden" name="upc" value="<?= htmlspecialchars($u['upc']) ?>">
              <button class="btn btn-secondary" style="padding:4px 10px; font-size:.8rem;"
                onclick="return confirm('Delete UPC <?= htmlspecialchars($u['upc']) ?>?')">×</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$upcs): ?>
          <tr><td colspan="6" style="color:#777;">No UPCs cached yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
    </div>
  </div>

</div>
<script>
function filterLookup(inputId, tableId, countId) {
  var q = (document.getElementById(inputId).value || '').toLowerCase().trim();
  var rows = document.querySelectorAll('#' + tableId + ' tbody tr');
  var shown = 0;
  rows.forEach(function(tr) {
    var hay = tr.getAttribute('data-search') || '';
    var visible = !q || hay.includes(q);
    tr.style.display = visible ? '' : 'none';
    if (visible) shown++;
  });
  var c = document.getElementById(countId);
  if (c) c.textContent = shown + (q ? ' of ' + rows.length : ' shown');
}
</script>
<?php renderFoot(); ?>
