<?php
// Manage produce codes and UPC -> generic mappings.
$GLOBALS['FS_PREFIX'] = '../';
require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../lookup.php';   // lookupCacheKey() / isStoreItemKey()
requireLogin();
$db = getDB();

// A supervisor session (Settings -> Supervisor Password) may read these tables
// but not rewrite them. Everything on this page reaches further than it looks:
// renaming a generic name rewrites historical scans and folds inventory rows
// together, and a recall stops every scanning station accepting the item. So
// the write controls are withheld from the page below, and this guard refuses
// every action outright so the lock doesn't depend on the browser honoring it.
//
// function_exists guards the call because this app is deployed a file at a
// time: fpIsSupervisor() arrived in auth.php with the supervisor login, and on
// an install whose auth.php predates it there are no supervisor sessions to
// withhold anything from, so 'not a supervisor' is the right answer there
// rather than a fatal that takes the whole page down.
$isSupervisor = function_exists('fpIsSupervisor') && fpIsSupervisor();

$msg = null;
$msgKind = 'success';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($isSupervisor) {
        $msg = 'Supervisor access: the lookup tables are read-only. Sign in with the administrator password to change them.';
        $msgKind = 'error';
    } elseif ($action === 'produce_add') {
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
        // Normalize so a full store label pasted into the box edits
        // the item row it actually resolves through, instead of creating a
        // one-package row beside it.
        $upc  = lookupCacheKey(trim((string)$_POST['upc']));
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
            forgetUnidentifiedUPC($upc);   // named by hand: no longer a miss

            // Rename propagation: when an existing UPC mapping is renamed,
            // also rename historical scans of *this* UPC. If no other UPC or
            // produce code still uses the old generic name, fold its inventory
            // row into the new name (or rename it outright).
            if ($prev && $prev !== $gen) {
                // Store-label rows are keyed on the six-digit item key, but the
                // scans they named carry the full store label, so an equality
                // match would rename nothing. renameUPCScans() knows every
                // spelling that folds into one key — including the native
                // EAN-13 form the copy that used to live here missed, which
                // left a renamed deli item's own history behind under the old
                // name.
                renameUPCScans($upc, $gen);

                // Never fold the placeholder's inventory row into the new
                // name, even when this happens to be the last UPC still
                // carrying it. That count is a hand-entered figure standing for
                // a shelf of assorted unnamed goods, so handing all of it to
                // the one item being named here would invent stock that isn't
                // there. The row is emptied on the Inventory page instead.
                $stillUsed = $db->prepare(
                    "SELECT 1 FROM upc_lookup WHERE generic_name=? AND upc!=?
                     UNION SELECT 1 FROM produce_lookup WHERE generic_name=?"
                );
                $stillUsed->execute([$prev, $upc, $prev]);
                if ($prev !== UNIDENTIFIED_NAME && !$stillUsed->fetchColumn()) {
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
    } elseif ($action === 'upc_recall') {
        // Product recall toggle. Only the flag moves — the mapping itself is
        // left alone, because the item still needs its name: reports covering
        // orders placed before the recall have to keep reading, and clearing
        // the box has to put the item straight back into service.
        //
        // Normalized like the edit above so a whole store label pasted into a
        // row (or arriving from an older page) recalls the six-digit item key
        // every package of it resolves through, rather than flagging one
        // package and leaving the rest scannable.
        $upc      = lookupCacheKey(trim((string)($_POST['upc'] ?? '')));
        $recalled = ($_POST['recalled'] ?? '0') === '1' ? 1 : 0;
        if ($upc !== '') {
            // updated_at is bumped so the recall surfaces at the top of the
            // unfiltered cache list, where the 500-row cap would otherwise
            // hide a rarely-scanned item the moment it mattered most.
            $st = $db->prepare("UPDATE upc_lookup SET recalled = ?, updated_at = ? WHERE upc = ?");
            $st->execute([$recalled, now(), $upc]);
            if ($st->rowCount() === 0) {
                $msg = "UPC $upc is not in the cache — nothing to recall.";
                $msgKind = 'error';
            } elseif ($recalled) {
                $msg = "UPC $upc marked RECALLED — scanning it now refuses the item.";
                $msgKind = 'warn';
            } else {
                $msg = "Recall cleared on UPC $upc — it scans normally again.";
            }
        }
    } elseif ($action === 'upc_delete') {
        $db->prepare("DELETE FROM upc_lookup WHERE upc=?")->execute([$_POST['upc'] ?? '']);
        $msg = 'UPC mapping removed.';
    } elseif ($action === 'upc_add') {
        // Same normalization as upc_edit: paste a whole deli label here and it
        // is stored as the item key, which is what a scan will look it up by.
        $upc   = lookupCacheKey(trim((string)($_POST['upc'] ?? '')));
        $brand = trim((string)($_POST['brand_name']   ?? ''));
        $gen   = trim((string)($_POST['generic_name'] ?? ''));
        if ($upc === '' || $gen === '') {
            $msg = 'UPC and generic name are both required.';
            $msgKind = 'error';
        } else {
            // Let the insert itself decide whether the UPC was new. Checking
            // with a SELECT first left a gap a scanning station could write
            // into (lookup.php caches a UPC the moment someone scans it), and
            // the follow-up INSERT then died on the primary key. rowCount()
            // tells the two outcomes apart without the extra query.
            $ins = $db->prepare(
                "INSERT INTO upc_lookup (upc, brand_name, generic_name, source, created_at)
                 VALUES (?, ?, ?, 'manual', ?)
                 ON CONFLICT(upc) DO NOTHING"
            );
            $ins->execute([$upc, $brand, $gen, now()]);
            forgetUnidentifiedUPC($upc);   // named by hand: no longer a miss
            if ($ins->rowCount() === 0) {
                $msg = "UPC $upc is already cached — edit it in the table above.";
                $msgKind = 'warn';
            } else {
                $msg = "Added UPC $upc → $gen";
            }
        }
    }
}

$produce = $db->query("SELECT code, generic_name, unit FROM produce_lookup ORDER BY generic_name COLLATE NOCASE")->fetchAll();
// The cache runs to thousands of rows, so the table is capped. The search that
// narrows it therefore has to run in SQL: filtering the rendered page in the
// browser would only ever search whatever the cap let through, and quietly
// report "1 of 500" while the row being looked for sat outside it — which is
// exactly how a merged store-label key stayed invisible while it was mis-naming
// scans. Unfiltered still shows the most recently touched rows; a search reaches
// the whole table.
const UPC_PAGE = 500;
$upcQ = trim((string)($_GET['q'] ?? ''));

if ($upcQ !== '') {
    // ! as the escape character, so a search term containing % or _ matches
    // those characters literally instead of acting as a wildcard.
    $like  = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $upcQ) . '%';
    $where = "WHERE upc LIKE :q ESCAPE '!' OR brand_name LIKE :q ESCAPE '!'
                 OR generic_name LIKE :q ESCAPE '!'";
    $cnt = $db->prepare("SELECT COUNT(*) FROM upc_lookup $where");
    $cnt->execute([':q' => $like]);
    $upcMatches = (int)$cnt->fetchColumn();

    $st = $db->prepare(
        "SELECT upc, brand_name, generic_name, source, created_at, recalled FROM upc_lookup
         $where ORDER BY generic_name COLLATE NOCASE LIMIT " . UPC_PAGE);
    $st->execute([':q' => $like]);
    $upcs = $st->fetchAll();
} else {
    $upcMatches = (int)$db->query("SELECT COUNT(*) FROM upc_lookup")->fetchColumn();
    $upcs = $db->query(
        "SELECT * FROM (
           SELECT upc, brand_name, generic_name, source, created_at, recalled FROM upc_lookup
           ORDER BY updated_at DESC, created_at DESC LIMIT " . UPC_PAGE . "
         ) ORDER BY generic_name COLLATE NOCASE"
    )->fetchAll();
}

renderHead('Lookup Tables');
renderNav('lookup');
?>
<div class="container">
  <?php if ($msg): ?>
    <div class="banner <?= htmlspecialchars($msgKind) ?>">
      <?= $msgKind === 'success' ? '✅' : '⚠' ?> <?= htmlspecialchars($msg) ?>
    </div>
  <?php endif; ?>

  <?php if ($isSupervisor): ?>
    <div class="banner warn">
      <div style="font-size:1.2rem;">🔑</div>
      <div>
        <strong>Supervisor sign-in — this page is read-only.</strong>
        The produce codes and the UPC cache are shown for reference, and the
        search still reaches the whole table. Adding, renaming, recalling, and
        removing entries here renames past scans and changes what the scanning
        stations accept, so it needs the administrator password.
      </div>
    </div>
  <?php endif; ?>

  <div class="card">
    <h2>Produce Codes</h2>
    <p style="color:#777; font-size:.85rem; margin-bottom:12px;">
      4–5 digit PLU codes, or 12-digit pantry labels starting with 4.
    </p>
    <?php if (!$isSupervisor): ?>
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
    <?php endif; ?>
    <div style="margin-bottom:10px;">
      <input type="search" id="prodSearch" placeholder="🔍 Filter by code or generic name…"
             oninput="filterLookup('prodSearch', 'prodTable', 'prodCount')"
             style="max-width:340px;">
      <span id="prodCount" style="font-size:.8rem; color:#777; margin-left:10px;"><?= count($produce) ?> codes</span>
    </div>
    <table class="data" id="prodTable">
      <thead><tr><th>Code</th><th>Generic Name</th><th>Unit</th><?php if (!$isSupervisor): ?><th></th><?php endif; ?></tr></thead>
      <tbody>
        <?php foreach ($produce as $p): ?>
        <tr data-search="<?= htmlspecialchars(strtolower($p['code'] . ' ' . $p['generic_name'])) ?>">
          <td><?= htmlspecialchars($p['code']) ?></td>
          <td><?= htmlspecialchars($p['generic_name']) ?></td>
          <td><?= htmlspecialchars($p['unit']) ?></td>
          <?php if (!$isSupervisor): ?>
          <td>
            <form method="post" style="display:inline;">
              <input type="hidden" name="action" value="produce_delete">
              <input type="hidden" name="code" value="<?= htmlspecialchars($p['code']) ?>">
              <button class="btn btn-secondary" style="padding:4px 10px; font-size:.8rem;"
                onclick="return confirm('Delete <?= htmlspecialchars($p['code']) ?>?')">Remove</button>
            </form>
          </td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if (!$isSupervisor): ?>
  <div class="card">
    <h2>Add UPC Manually</h2>
    <p style="color:#777; font-size:.85rem; margin-bottom:12px;">
      Use this for UPCs that Open Food Facts can't resolve. The mapping is
      written to the cache below with source <code>manual</code> and used
      for all future scans of this code.
    </p>
    <p style="color:#777; font-size:.85rem; margin-bottom:12px;">
      <strong>Store-printed item labels</strong> (starting with <code>2</code>)
      are stored as the leading six digits only — the prefix plus the store's
      item code. The last five digits are that one package's price or weight,
      so they are dropped and one name covers every package. Paste a whole
      label here and it is shortened for you.
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
  <?php endif; ?>

  <!-- The row forms below deliberately post with no action: a save, recall, or
       delete reloads to the top of the page, where the banner reporting what
       happened is drawn. Landing back on the table would hide that message. -->
  <div class="card" id="upc-cache">
    <h2>UPC → Generic Cache</h2>
    <p style="color:#777; font-size:.85rem; margin-bottom:12px;">
      Populated automatically when a new UPC is scanned (Open Food Facts +
      OpenAI mapping).<?php if (!$isSupervisor): ?> Edit a generic name here if
      the AI mislabeled it.<?php endif; ?>
      Rows marked <strong>🏷 store item</strong> are six-digit item keys
      for store-printed labels rather than barcodes you can scan.
    </p>
    <p style="color:#777; font-size:.85rem; margin-bottom:12px;">
      <?php if ($isSupervisor): ?>A UPC ticked <strong>Recalled</strong> has been
      pulled: it stops<?php else: ?>Tick <strong>Recalled</strong> to pull an
      item. A recalled UPC stops<?php endif; ?>
      recording at every station: scanning it refuses the item, sounds an
      alarm, and tells the volunteer to take it back out of the cart. The
      mapping and its history are untouched<?php if (!$isSupervisor): ?> — clear
      the box to put the item back into service<?php endif; ?>.
    </p>
    <!-- GET, so the search survives an edit posted from a row below: those
         forms carry no action and so post back to this URL, query string and
         all. The fragment here is on the search only — it has no banner to
         show, so it can land straight on the table it filtered. -->
    <form method="get" action="#upc-cache" style="margin-bottom:10px; display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
      <input type="search" name="q" value="<?= htmlspecialchars($upcQ) ?>"
             placeholder="🔍 Search all UPCs, brands, and generic names…"
             style="max-width:340px;">
      <button class="btn btn-primary" style="padding:6px 12px; font-size:.8rem;">Search</button>
      <?php if ($upcQ !== ''): ?>
        <a href="?#upc-cache" style="font-size:.8rem;">Clear</a>
      <?php endif; ?>
      <span style="font-size:.8rem; color:#777;">
        <?php if ($upcQ !== ''): ?>
          <?= number_format($upcMatches) ?> match<?= $upcMatches === 1 ? '' : 'es' ?>
          <?php if ($upcMatches > UPC_PAGE): ?>
            — showing the first <?= UPC_PAGE ?>, narrow the search to see the rest
          <?php endif; ?>
        <?php else: ?>
          <?= number_format($upcMatches) ?> cached
          <?php if ($upcMatches > UPC_PAGE): ?>
            — showing the <?= UPC_PAGE ?> most recently updated; search to reach the others
          <?php endif; ?>
        <?php endif; ?>
      </span>
    </form>
    <!-- Confine the wide cache table to the card; scroll horizontally instead
         of letting the First Seen column bleed past the card's right edge. -->
    <div style="width:100%; overflow-x:auto; -webkit-overflow-scrolling:touch;">
    <table class="data" id="upcTable">
      <thead><tr>
        <th>Recalled</th><th>UPC</th><th>Branded Name (from OFF)</th>
        <th>Generic</th><th>Source</th><th>First Seen</th>
        <?php if (!$isSupervisor): ?><th></th><?php endif; ?>
      </tr></thead>
      <tbody>
        <?php foreach ($upcs as $u): ?>
        <?php $isStore   = isStoreItemKey($u['upc']);
              $isRecalled = (int)($u['recalled'] ?? 0) === 1; ?>
        <tr<?= $isRecalled ? ' class="recalled-row"' : '' ?>>
          <td style="text-align:center;">
            <!-- The hidden field carries the "off" value: an unchecked box
                 sends nothing at all, so without it clearing a recall would
                 post no `recalled` key and the handler could not tell the two
                 apart. The checkbox is declared after it, and PHP takes the
                 later of two same-named values. -->
            <?php if ($isSupervisor): ?>
              <!-- Status only, and no form around it: with nothing a supervisor
                   may post, the hidden fields would just be an inert POST body
                   sitting in the page. -->
              <input type="checkbox" class="recall-box" disabled
                     <?= $isRecalled ? 'checked' : '' ?>
                     title="Recall status — administrator password required to change it"
                     aria-label="Recalled: <?= htmlspecialchars($u['generic_name']) ?>">
            <?php else: ?>
            <form method="post" style="display:inline;">
              <input type="hidden" name="action" value="upc_recall">
              <input type="hidden" name="upc" value="<?= htmlspecialchars($u['upc']) ?>">
              <input type="hidden" name="recalled" value="0">
              <input type="checkbox" name="recalled" value="1" class="recall-box"
                     <?= $isRecalled ? 'checked' : '' ?>
                     title="Recalled — refuse this item at every scanning station"
                     aria-label="Recalled: <?= htmlspecialchars($u['generic_name']) ?>"
                     onchange="toggleRecall(this, '<?= htmlspecialchars($u['upc'], ENT_QUOTES) ?>')">
            </form>
            <?php endif; ?>
          </td>
          <td>
            <?= htmlspecialchars($u['upc']) ?>
            <?php if ($isStore): ?>
              <!-- Six digits, not a scannable barcode: the item key every
                   package of this store-printed label resolves through. -->
              <div style="font-size:.7rem; color:#777; white-space:nowrap;" title="Store-printed item label: only the prefix and item code are stored.">🏷 store item</div>
            <?php endif; ?>
          </td>
          <td style="font-size:.8rem; color:#666;"><?= htmlspecialchars($u['brand_name'] ?? '') ?></td>
          <td>
            <?php if ($isSupervisor): ?>
              <?= htmlspecialchars($u['generic_name']) ?>
            <?php else: ?>
            <form method="post" style="display:flex; gap:4px;">
              <input type="hidden" name="action" value="upc_edit">
              <input type="hidden" name="upc" value="<?= htmlspecialchars($u['upc']) ?>">
              <input type="text" name="generic_name" value="<?= htmlspecialchars($u['generic_name']) ?>" style="min-width:160px;">
              <button class="btn btn-primary" style="padding:6px 10px; font-size:.8rem; flex:0 0 auto;">Save</button>
            </form>
            <?php endif; ?>
          </td>
          <td><span style="font-size:.75rem; padding:2px 6px; background:var(--cat-bg); border-radius:4px;"><?= htmlspecialchars($u['source']) ?></span></td>
          <td style="font-size:.75rem; color:#777;"><?= htmlspecialchars($u['created_at']) ?></td>
          <?php if (!$isSupervisor): ?>
          <td>
            <form method="post" style="display:inline;">
              <input type="hidden" name="action" value="upc_delete">
              <input type="hidden" name="upc" value="<?= htmlspecialchars($u['upc']) ?>">
              <button class="btn btn-secondary" style="padding:4px 10px; font-size:.8rem;"
                onclick="return confirm('Delete UPC <?= htmlspecialchars($u['upc']) ?>?')">×</button>
            </form>
          </td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        <?php if (!$upcs): ?>
          <tr><td colspan="<?= $isSupervisor ? 6 : 7 ?>" style="color:#777;">
            <?= $upcQ !== '' ? 'No UPCs match ' . htmlspecialchars($upcQ) . '.' : 'No UPCs cached yet.' ?>
          </td></tr>
        <?php endif; ?>
      </tbody>
    </table>
    </div>
  </div>

</div>
<style>
  /* Big enough to hit accurately in a dense table, and red so a page of
     mappings shows at a glance which items are pulled. */
  .recall-box { width: 20px; height: 20px; accent-color: var(--red); cursor: pointer; }
  .recall-box:disabled { cursor: not-allowed; opacity: .55; }
  .recalled-row { background: #FDF3F3; }
  .recalled-row td { color: #8B1A1A; }
</style>
<script>
// Ticking the box is the safe direction — the item stops being handed out —
// so it just saves. Clearing one puts a recalled product back into circulation,
// which is not something a stray click in a 500-row table should be able to do
// silently, so that direction asks first and puts the tick back if the answer
// is no.
function toggleRecall(cb, upc) {
  if (!cb.checked && !confirm('Clear the recall on UPC ' + upc + '?'
      + '\n\nScanning stations will start accepting this item again.')) {
    cb.checked = true;
    return;
  }
  cb.form.submit();
}

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
