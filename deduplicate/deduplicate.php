<?php
// Merge duplicate generic_name entries in openpantry.db.
//
// The pantry's canonical item key is the generic name string itself — there is
// no id table behind it — so the same food can end up stored under several
// spellings: the OpenAI mapper returns "Green beans" one week and "Green
// Beans" the next, a produce PLU is seeded "Squash Zucchini" while a UPC maps
// to "Zucchini", or an admin retypes a name on the Lookup Tables page. Each
// variant then carries its own scan history, its own inventory row and its own
// reorder alert, which splits demand across two items and biases every par
// level built on it low.
//
// This page merges one name into another everywhere it appears:
//   scans          — historical scan rows are renamed (demand history merges)
//   upc_lookup     — future scans of those UPCs resolve to the kept name
//   produce_lookup — same for PLU / scale-label codes
//   inventory      — folded into the kept row (counts and lifetime restock
//                    totals summed), or renamed outright when the kept name
//                    has no row yet, so its unit / case configuration survives
//   alerts         — re-pointed, or dropped when the kept name already has one
//
// forecast_cache needs no cleanup: its keys hash the item name *and* its
// training series, so a merged item simply misses the cache and refits.
//
// The page also deletes a name outright, but only when nothing in the database
// can produce it any more — no UPC, no produce code and no scan history. That
// is a narrower door than it looks, and deliberately so: inventory.php builds
// its item list from upc_lookup + produce_lookup + scans and never from
// `inventory` itself, so deleting the inventory row of a name that still has
// scan history removes nothing visible — the name comes straight back on the
// next page load with an empty count, which op_report_rows() reads as zero
// stock and orders a full par level against. Anything still referenced has to
// be merged. What delete is actually for is the reverse orphan: an inventory
// row (or a reorder alert) whose last UPC or produce code was deleted, which
// no page can reach at all, because a name with no reference never makes it
// into inventory.php's list in the first place.
//
// The companion tool for the ordering app's own database (picklist.db) is
// menucounter/deduplicate/deduplicate.php, which remaps order_items rows onto
// a canonical config_items entry.
$GLOBALS['FS_PREFIX'] = '../';
require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../auth.php';
requireLogin();
$db = getDB();

// ── Helpers ──────────────────────────────────────────────────────────────────

// Every generic name known to the database, with what each table holds for it.
// Names are compared byte-for-byte (SQLite's default BINARY collation), which
// is the point: "Green beans" and "Green Beans" are two different items here,
// and telling them apart is exactly what this page is for.
function opNameStats(PDO $db): array {
    $rows  = [];
    $touch = function (string $n) use (&$rows) {
        if (!isset($rows[$n])) {
            $rows[$n] = ['name' => $n, 'scans' => 0, 'upcs' => 0, 'plus' => 0,
                         'alerts' => 0, 'inv' => null, 'unit' => ''];
        }
    };
    foreach ($db->query("SELECT generic_name AS n, COUNT(*) AS c FROM scans GROUP BY generic_name") as $r) {
        $touch($r['n']); $rows[$r['n']]['scans'] = (int)$r['c'];
    }
    foreach ($db->query("SELECT generic_name AS n, COUNT(*) AS c FROM upc_lookup GROUP BY generic_name") as $r) {
        $touch($r['n']); $rows[$r['n']]['upcs'] = (int)$r['c'];
    }
    foreach ($db->query("SELECT generic_name AS n, COUNT(*) AS c FROM produce_lookup GROUP BY generic_name") as $r) {
        $touch($r['n']); $rows[$r['n']]['plus'] = (int)$r['c'];
    }
    foreach ($db->query("SELECT generic_name AS n, COUNT(*) AS c FROM alerts GROUP BY generic_name") as $r) {
        $touch($r['n']); $rows[$r['n']]['alerts'] = (int)$r['c'];
    }
    foreach ($db->query("SELECT generic_name AS n, count, unit FROM inventory") as $r) {
        $touch($r['n']);
        $rows[$r['n']]['inv']  = (float)$r['count'];
        $rows[$r['n']]['unit'] = (string)$r['unit'];
    }
    ksort($rows, SORT_NATURAL | SORT_FLAG_CASE);
    return $rows;
}

// Normalized grouping key used to *suggest* duplicates. Case, punctuation,
// word order and simple plurals are folded, so "Green Beans", "green bean"
// and "Beans, Green" land in one group. Only ever a hint — nothing merges
// without the operator picking both sides.
function opDedupeKey(string $name): string {
    $s = strtolower(trim($name));
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
    $words = [];
    foreach (explode(' ', trim($s)) as $w) {
        if ($w === '') continue;
        $len = strlen($w);
        if ($len > 4 && substr($w, -3) === 'ies') {
            $w = substr($w, 0, -3) . 'y';                       // berries -> berry
        } elseif ($len > 4 && preg_match('/(oes|ses|xes|zes|ches|shes)$/', $w)) {
            $w = substr($w, 0, -2);                             // tomatoes -> tomato
        } elseif ($len > 3 && substr($w, -1) === 's' && substr($w, -2) !== 'ss') {
            $w = substr($w, 0, -1);                             // beans -> bean
        }
        $words[] = $w;
    }
    sort($words);
    return implode(' ', $words);
}

// Trim trailing zeros off a count so "12.00" prints as "12" and "1.50" as "1.5".
function opNum(float $v): string {
    return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
}

// Move every reference to $src onto $dst, in one transaction. Returns a
// per-table tally for the result banner.
function opMergeName(PDO $db, string $src, string $dst): array {
    $n = ['scans' => 0, 'upcs' => 0, 'plus' => 0,
          'inventory' => 'none', 'alerts' => 0, 'alerts_dropped' => 0];

    $db->beginTransaction();
    try {
        $st = $db->prepare("UPDATE scans SET generic_name = ? WHERE generic_name = ?");
        $st->execute([$dst, $src]);
        $n['scans'] = $st->rowCount();

        $st = $db->prepare("UPDATE upc_lookup SET generic_name = ?, updated_at = ? WHERE generic_name = ?");
        $st->execute([$dst, now(), $src]);
        $n['upcs'] = $st->rowCount();

        $st = $db->prepare("UPDATE produce_lookup SET generic_name = ? WHERE generic_name = ?");
        $st->execute([$dst, $src]);
        $n['plus'] = $st->rowCount();

        // Inventory: generic_name is the primary key, so a straight rename only
        // works when the kept name has no row. When it does, sum the current
        // count and both lifetime restock counters into it (the kept row's
        // unit, case size and other order settings win) and drop the source.
        $sel = $db->prepare("SELECT * FROM inventory WHERE generic_name = ?");
        $sel->execute([$src]);
        $srcInv = $sel->fetch();
        if ($srcInv) {
            $sel->execute([$dst]);
            $dstInv = $sel->fetch();
            if (!$dstInv) {
                $db->prepare("UPDATE inventory SET generic_name = ?, updated_at = ? WHERE generic_name = ?")
                   ->execute([$dst, now(), $src]);
                $n['inventory'] = 'renamed';
            } else {
                $db->prepare(
                    "UPDATE inventory
                        SET count               = count + :c,
                            restocked_purchased = restocked_purchased + :rp,
                            restocked_donated   = restocked_donated + :rd,
                            updated_at          = :now
                      WHERE generic_name = :dst"
                )->execute([
                    ':c'   => (float)$srcInv['count'],
                    ':rp'  => (float)$srcInv['restocked_purchased'],
                    ':rd'  => (float)$srcInv['restocked_donated'],
                    ':now' => now(),
                    ':dst' => $dst,
                ]);
                $db->prepare("DELETE FROM inventory WHERE generic_name = ?")->execute([$src]);
                $n['inventory'] = 'folded';
            }
        }

        // Alerts: re-point the source's alerts unless the kept name already has
        // one, in which case they'd only duplicate the same reminder email.
        $has = $db->prepare("SELECT COUNT(*) FROM alerts WHERE generic_name = ?");
        $has->execute([$dst]);
        if ((int)$has->fetchColumn() > 0) {
            $st = $db->prepare("DELETE FROM alerts WHERE generic_name = ?");
            $st->execute([$src]);
            $n['alerts_dropped'] = $st->rowCount();
        } else {
            $st = $db->prepare("UPDATE alerts SET generic_name = ? WHERE generic_name = ?");
            $st->execute([$dst, $src]);
            $n['alerts'] = $st->rowCount();
        }

        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    return $n;
}

// True when nothing in the database can produce this name any more, which is
// the only condition under which deleting it is honest. A name that still has
// scans has to be MERGED instead: the scan rows would keep it on the Inventory
// page with no row behind it, and a missing inventory row is not "no stock
// tracked" anywhere downstream — op_report_rows() in
// reports/order_report/report_lib.php reads it as stock zero.
function opCanDelete(array $r): bool {
    return $r['scans'] === 0 && $r['upcs'] === 0 && $r['plus'] === 0;
}

// Drop what an unreferenced name still owns. Only `inventory` and `alerts` can
// hold anything at this point — the other three tables are what opCanDelete()
// just checked were empty — so this is the whole cleanup. One transaction, so
// a name never ends up with its alert still firing against a row that is gone.
function opDeleteName(PDO $db, string $name): array {
    $n = ['inventory' => 0, 'alerts' => 0];

    $db->beginTransaction();
    try {
        $st = $db->prepare("DELETE FROM inventory WHERE generic_name = ?");
        $st->execute([$name]);
        $n['inventory'] = $st->rowCount();

        $st = $db->prepare("DELETE FROM alerts WHERE generic_name = ?");
        $st->execute([$name]);
        $n['alerts'] = $st->rowCount();

        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    return $n;
}

// ── Handle the merge ─────────────────────────────────────────────────────────
$msg     = null;
$msgKind = 'success';

$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string)($_POST['action'] ?? '') : '';

if ($action === 'merge') {
    $src = trim((string)($_POST['source_name'] ?? ''));
    // The typed name wins over the picker, which is how a whole item gets
    // renamed to a spelling that doesn't exist in the database yet.
    $dst = trim((string)($_POST['target_new'] ?? ''));
    if ($dst === '') $dst = trim((string)($_POST['target_name'] ?? ''));

    $before = opNameStats($db);
    if ($src === '' || $dst === '') {
        $msg = 'Pick the duplicate to merge and the name to keep.';
        $msgKind = 'error';
    } elseif ($src === $dst) {
        $msg = 'Those are the same name — nothing to merge.';
        $msgKind = 'error';
    } elseif (!isset($before[$src])) {
        $msg = 'That duplicate is no longer in the database — it may already have been merged.';
        $msgKind = 'error';
    } else {
        try {
            $n = opMergeName($db, $src, $dst);
            $bits = [];
            if ($n['scans']) $bits[] = $n['scans'] . ' scan' . ($n['scans'] === 1 ? '' : 's');
            if ($n['upcs'])  $bits[] = $n['upcs']  . ' UPC mapping' . ($n['upcs'] === 1 ? '' : 's');
            if ($n['plus'])  $bits[] = $n['plus']  . ' produce code' . ($n['plus'] === 1 ? '' : 's');
            if ($n['inventory'] === 'folded')  $bits[] = 'inventory count folded in';
            if ($n['inventory'] === 'renamed') $bits[] = 'inventory row renamed';
            if ($n['alerts'])         $bits[] = $n['alerts'] . ' alert' . ($n['alerts'] === 1 ? '' : 's') . ' moved';
            if ($n['alerts_dropped']) $bits[] = $n['alerts_dropped'] . ' duplicate alert'
                                              . ($n['alerts_dropped'] === 1 ? '' : 's') . ' removed';
            $msg = '"' . $src . '" merged into "' . $dst . '" — '
                 . ($bits ? implode(', ', $bits) : 'nothing referenced it') . '.';
            // A unit mismatch means the two rows were counted differently (each
            // vs lb); the sum is still stored, but the operator should check it.
            $su = $before[$src]['unit'] ?? '';
            $du = $before[$dst]['unit'] ?? '';
            if ($n['inventory'] === 'folded' && $su !== '' && $du !== '' && $su !== $du) {
                $msg .= ' Note: the two inventory rows used different units ('
                      . $su . ' vs ' . $du . ') — check the merged count on the Inventory page.';
                $msgKind = 'warn';
            }
        } catch (\Throwable $e) {
            $msg = 'Merge failed and was rolled back: ' . $e->getMessage();
            $msgKind = 'error';
        }
    }
}

// ── Handle the delete ───────────────────────────────────────────────────────
if ($action === 'delete') {
    $name   = trim((string)($_POST['name'] ?? ''));
    $before = opNameStats($db);
    if ($name === '') {
        $msg = 'Pick the name to remove.';
        $msgKind = 'error';
    } elseif (!isset($before[$name])) {
        $msg = 'That name is no longer in the database — it may already have been removed.';
        $msgKind = 'error';
    } elseif (!opCanDelete($before[$name])) {
        // Re-checked here and not only in the UI: the button is rendered only
        // for names that pass, but a scan landing between the page render and
        // the click is exactly how a name stops passing.
        $r   = $before[$name];
        $why = [];
        if ($r['scans']) $why[] = $r['scans'] . ' scan' . ($r['scans'] === 1 ? '' : 's') . ' of history';
        if ($r['upcs'])  $why[] = $r['upcs']  . ' UPC mapping' . ($r['upcs'] === 1 ? '' : 's');
        if ($r['plus'])  $why[] = $r['plus']  . ' produce code' . ($r['plus'] === 1 ? '' : 's');
        $msg = '"' . $name . '" still has ' . implode(' and ', $why) . '. Deleting its inventory '
             . 'row would not remove the item — the Inventory page rebuilds its list from those '
             . 'tables, so the name would come back with an empty count, and the Order Report '
             . 'reads a missing count as zero stock and asks for a full par level. Merge it into '
             . 'the name you want to keep instead.';
        $msgKind = 'error';
    } elseif ($before[$name]['inv'] === null && $before[$name]['alerts'] === 0) {
        $msg = '"' . $name . '" has no inventory row and no alerts — there is nothing left to remove.';
        $msgKind = 'warn';
    } else {
        try {
            $n    = opDeleteName($db, $name);
            $bits = [];
            if ($n['inventory']) $bits[] = 'inventory row';
            if ($n['alerts'])    $bits[] = $n['alerts'] . ' reorder alert'
                                         . ($n['alerts'] === 1 ? '' : 's');
            $msg = '"' . $name . '" removed — ' . implode(' and ', $bits) . ' deleted.';
        } catch (\Throwable $e) {
            $msg = 'Delete failed and was rolled back: ' . $e->getMessage();
            $msgKind = 'error';
        }
    }
}

// ── Load current state ───────────────────────────────────────────────────────
$stats = opNameStats($db);

// Deep link from the Inventory page (?merge=<name>), which is where a split
// item is usually noticed: preselect it as the duplicate to merge away.
$preSrc = trim((string)($_GET['merge'] ?? ''));
if ($preSrc !== '' && !isset($stats[$preSrc]) && $msg === null) {
    $msg = '"' . $preSrc . '" is no longer in the database — it may already have been merged.';
    $msgKind = 'warn';
    $preSrc  = '';
}

// Names that normalize to the same key — the suggested merges.
$groups = [];
foreach ($stats as $name => $r) {
    $groups[opDedupeKey($name)][] = $name;
}
$dupGroups = [];
foreach ($groups as $key => $members) {
    if (count($members) > 1) $dupGroups[$key] = $members;
}
ksort($dupGroups, SORT_NATURAL);

// Flag for the table below.
$inDupGroup = [];
foreach ($dupGroups as $members) {
    foreach ($members as $m) $inDupGroup[$m] = true;
}

renderHead('De-duplicate Items');
renderNav('dedupe');
?>
<div class="container">

  <?php if ($msg): ?>
    <div class="banner <?= htmlspecialchars($msgKind) ?>">
      <span><?= $msgKind === 'success' ? '✅' : ($msgKind === 'warn' ? '⚠' : '⛔') ?></span>
      <span><?= htmlspecialchars($msg) ?></span>
    </div>
  <?php endif; ?>

  <div class="card">
    <h2>🔀 Merge Duplicate Items</h2>
    <p style="font-size:.85rem; color:#777; margin-bottom:16px;">
      The same food stored under two spellings splits its scan history, so the
      Order Report under-counts demand for both. Merging moves every scan, UPC
      mapping, produce code, inventory count and reorder alert from the
      duplicate onto the name you keep, then removes the duplicate.
      <strong>This rewrites history and cannot be undone</strong> — back up
      <code>openpantry.db</code> first if you're unsure.
    </p>

    <form method="POST" id="mergeForm">
      <input type="hidden" name="action" value="merge">
      <div class="row" style="align-items:flex-start;">
        <div style="flex:1 1 260px;">
          <label for="source_name">Duplicate — merge this away</label>
          <select id="source_name" name="source_name" required onchange="opPreview()">
            <option value="">— Select the duplicate —</option>
            <?php foreach ($stats as $name => $r): ?>
              <option value="<?= htmlspecialchars($name) ?>"<?= $name === $preSrc ? ' selected' : '' ?>
                      data-scans="<?= (int)$r['scans'] ?>"
                      data-upcs="<?= (int)$r['upcs'] ?>"
                      data-plus="<?= (int)$r['plus'] ?>"
                      data-alerts="<?= (int)$r['alerts'] ?>"
                      data-inv="<?= $r['inv'] === null ? '' : htmlspecialchars(opNum($r['inv']) . ' ' . $r['unit']) ?>">
                <?= htmlspecialchars($name) ?>
                (<?= (int)$r['scans'] ?> scan<?= $r['scans'] === 1 ? '' : 's' ?><?php
                  if ($r['upcs'])  echo ', ' . (int)$r['upcs'] . ' UPC';
                  if ($r['plus'])  echo ', ' . (int)$r['plus'] . ' PLU';
                  if ($r['inv'] !== null) echo ', inv ' . opNum($r['inv']);
                ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div style="flex:0 0 auto; align-self:center; font-size:1.4rem;
                    color:var(--brown); opacity:.5; padding:0 4px;">→</div>

        <div style="flex:1 1 260px;">
          <label for="target_name">Keep — everything moves onto this name</label>
          <select id="target_name" name="target_name" onchange="opPreview()">
            <option value="">— Select the name to keep —</option>
            <?php foreach ($stats as $name => $r): ?>
              <option value="<?= htmlspecialchars($name) ?>">
                <?= htmlspecialchars($name) ?>
                (<?= (int)$r['scans'] ?> scan<?= $r['scans'] === 1 ? '' : 's' ?>)
              </option>
            <?php endforeach; ?>
          </select>
          <label for="target_new" style="margin-top:10px;">…or type a new name</label>
          <input type="text" id="target_new" name="target_new" oninput="opPreview()"
                 placeholder="Leave blank to use the list above" autocomplete="off">
        </div>
      </div>

      <div id="opPreviewBox" class="banner info" style="display:none; margin-top:16px;"></div>

      <div style="margin-top:16px; display:flex; gap:10px; flex-wrap:wrap;">
        <button type="submit" class="btn btn-primary" onclick="return opConfirm();">🔀 Merge Items</button>
        <button type="button" class="btn btn-secondary" onclick="opClear()">↺ Clear</button>
      </div>
    </form>
  </div>

  <?php if ($dupGroups): ?>
  <div class="card">
    <h2>🔎 Possible Duplicates (<?= count($dupGroups) ?>)</h2>
    <p style="font-size:.85rem; color:#777; margin-bottom:14px;">
      Names that match once case, punctuation, word order and plurals are
      ignored. Use <strong>keep</strong> on the spelling you want and
      <strong>merge</strong> on the one to fold into it.
    </p>
    <table class="data">
      <thead>
        <tr><th>Names that look like the same item</th></tr>
      </thead>
      <tbody>
        <?php foreach ($dupGroups as $members): ?>
        <tr><td>
          <?php foreach ($members as $m): $r = $stats[$m]; ?>
          <span style="display:inline-flex; align-items:center; gap:6px; margin:3px 10px 3px 0;
                       background:var(--cat-bg); border-radius:14px; padding:3px 10px; font-size:.85rem;">
            <strong><?= htmlspecialchars($m) ?></strong>
            <span style="color:#777; font-size:.78rem;">
              <?= (int)$r['scans'] ?> scan<?= $r['scans'] === 1 ? '' : 's' ?>
            </span>
            <button type="button" class="op-pick" data-role="target" data-name="<?= htmlspecialchars($m) ?>"
                    style="border:none; background:#fff; color:var(--brown); border-radius:10px;
                           padding:2px 8px; font-size:.72rem; font-weight:700; cursor:pointer;">keep</button>
            <button type="button" class="op-pick" data-role="source" data-name="<?= htmlspecialchars($m) ?>"
                    style="border:none; background:#fff; color:var(--red); border-radius:10px;
                           padding:2px 8px; font-size:.72rem; font-weight:700; cursor:pointer;">merge</button>
          </span>
          <?php endforeach; ?>
        </td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <div class="card">
    <h2>📋 All Item Names (<?= count($stats) ?>)</h2>
    <div style="margin-bottom:12px;">
      <input type="search" id="opFilter" placeholder="Filter names…" oninput="opFilterRows()"
             autocomplete="off" style="max-width:280px;">
    </div>
    <table class="data" id="opNameTable">
      <thead>
        <tr>
          <th>Generic name</th>
          <th class="num">Scans</th>
          <th class="num">UPCs</th>
          <th class="num">PLUs</th>
          <th class="num">Inventory</th>
          <th class="num">Alerts</th>
          <th>Status</th>
          <th>Remove</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($stats as $name => $r):
          $unmapped = ($r['upcs'] === 0 && $r['plus'] === 0);
          // Nothing can scan into it and nothing ever did, so whatever is left
          // is an inventory row or alert that no other page can even list.
          $orphan    = opCanDelete($r);
          $deletable = $orphan && ($r['inv'] !== null || $r['alerts'] > 0);
        ?>
        <tr>
          <td><?= htmlspecialchars($name) ?></td>
          <td class="num"><?= (int)$r['scans'] ?></td>
          <td class="num"><?= (int)$r['upcs'] ?></td>
          <td class="num"><?= (int)$r['plus'] ?></td>
          <td class="num"><?= $r['inv'] === null
                ? '<span style="color:#bbb">—</span>'
                : htmlspecialchars(opNum($r['inv']) . ' ' . $r['unit']) ?></td>
          <td class="num"><?= (int)$r['alerts'] ?></td>
          <td>
            <?php if (isset($inDupGroup[$name])): ?>
              <span style="background:var(--red); color:#fff; font-size:.7rem; font-weight:700;
                           border-radius:4px; padding:2px 7px;">⚠ Possible duplicate</span>
            <?php elseif ($orphan): ?>
              <span style="background:#777; color:#fff; font-size:.7rem; font-weight:700;
                           border-radius:4px; padding:2px 7px;">Orphan row</span>
            <?php elseif ($unmapped): ?>
              <span style="background:#E07B39; color:#fff; font-size:.7rem; font-weight:700;
                           border-radius:4px; padding:2px 7px;">No barcode</span>
            <?php else: ?>
              <span style="background:var(--green); color:#fff; font-size:.7rem; font-weight:700;
                           border-radius:4px; padding:2px 7px;">✓ Mapped</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if (!$deletable): ?>
              <span style="color:#ccc;">—</span>
            <?php else: ?>
              <form method="POST" style="margin:0;" onsubmit="return opConfirmDelete(this);">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="name" value="<?= htmlspecialchars($name) ?>">
                <button type="submit"
                        style="border:none; background:#fff; color:var(--red);
                               border:1px solid var(--red); border-radius:10px;
                               padding:2px 9px; font-size:.72rem; font-weight:700;
                               cursor:pointer; white-space:nowrap;">🗑 Remove</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p style="font-size:.78rem; color:#999; margin-top:12px;">
      <strong>No barcode</strong> means no UPC or produce code still resolves to
      that name, so nothing new can be scanned into it — usually a leftover from
      a rename, and a good merge candidate.
      <br><br>
      <strong>Orphan row</strong> goes further: no barcode <em>and</em> no scan
      history, so the name exists only as an inventory row or a reorder alert
      that no page can reach — the Inventory page builds its list from the
      lookup tables and past scans, never from the inventory table itself.
      Those are the only names <strong>Remove</strong> is offered for. A name
      that still has scans has to be merged instead: deleting its inventory row
      would leave the name on the Inventory page with no count behind it, and
      the Order Report reads a missing count as zero stock and orders a full par
      level against it.
    </p>
  </div>

</div>

<script>
function opEsc(s) {
  return String(s == null ? '' : s)
    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function opTargetName() {
  var typed = document.getElementById('target_new').value.trim();
  return typed !== '' ? typed : document.getElementById('target_name').value;
}

function opPreview() {
  var srcSel = document.getElementById('source_name');
  var box    = document.getElementById('opPreviewBox');
  var src    = srcSel.value;
  var dst    = opTargetName();

  if (!src || !dst) { box.style.display = 'none'; return; }

  if (src === dst) {
    box.className = 'banner error';
    box.style.display = 'flex';
    box.innerHTML = '<span>⛔</span><span>Those are the same name — pick a different one to keep.</span>';
    return;
  }

  var opt    = srcSel.options[srcSel.selectedIndex];
  var scans  = parseInt(opt.getAttribute('data-scans')  || '0', 10);
  var upcs   = parseInt(opt.getAttribute('data-upcs')   || '0', 10);
  var plus   = parseInt(opt.getAttribute('data-plus')   || '0', 10);
  var alerts = parseInt(opt.getAttribute('data-alerts') || '0', 10);
  var inv    = opt.getAttribute('data-inv') || '';

  var moves = [scans + ' scan' + (scans === 1 ? '' : 's') + ' renamed'];
  if (upcs)   moves.push(upcs + ' UPC mapping' + (upcs === 1 ? '' : 's') + ' re-pointed');
  if (plus)   moves.push(plus + ' produce code' + (plus === 1 ? '' : 's') + ' re-pointed');
  if (inv)    moves.push('inventory count of ' + inv + ' folded in');
  if (alerts) moves.push(alerts + ' reorder alert' + (alerts === 1 ? '' : 's') + ' merged');

  box.className = 'banner info';
  box.style.display = 'flex';
  box.innerHTML = '<span>🔀</span><span><strong>' + opEsc(src) + '</strong> → <strong>'
                + opEsc(dst) + '</strong><br>' + opEsc(moves.join(', '))
                + '.<br>"' + opEsc(src) + '" then no longer exists.</span>';
}

function opConfirm() {
  var src = document.getElementById('source_name').value;
  var dst = opTargetName();
  if (!src || !dst) { alert('Pick the duplicate to merge and the name to keep.'); return false; }
  if (src === dst)  { alert('Those are the same name — nothing to merge.'); return false; }
  return confirm('Merge "' + src + '" into "' + dst + '"?\n\n'
               + 'Every scan, mapping, inventory count and alert moves onto "'
               + dst + '", and "' + src + '" is removed. This cannot be undone.');
}

function opConfirmDelete(form) {
  var name = form.querySelector('input[name="name"]').value;
  return confirm('Remove "' + name + '"?\n\n'
               + 'Nothing can scan into this name and it has no scan history, so only its '
               + 'inventory row and reorder alerts are left. Both are deleted, including the '
               + 'unit, case sizes and lifetime restock totals on that row. This cannot be undone.');
}

function opClear() {
  document.getElementById('mergeForm').reset();
  document.getElementById('opPreviewBox').style.display = 'none';
}

// "keep" / "merge" buttons in the Possible Duplicates card fill the form above.
document.querySelectorAll('.op-pick').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var name = btn.getAttribute('data-name');
    if (btn.getAttribute('data-role') === 'source') {
      document.getElementById('source_name').value = name;
    } else {
      document.getElementById('target_name').value = name;
      document.getElementById('target_new').value  = '';
    }
    opPreview();
    document.getElementById('mergeForm').scrollIntoView({ behavior: 'smooth', block: 'center' });
  });
});

// Arrived from the Inventory page's 🔀 link: the option is already selected
// server-side, so just draw the preview of what the merge would move.
if (document.getElementById('source_name').value !== '') opPreview();

function opFilterRows() {
  var q = document.getElementById('opFilter').value.toLowerCase();
  var rows = document.querySelectorAll('#opNameTable tbody tr');
  for (var i = 0; i < rows.length; i++) {
    var name = rows[i].cells[0].textContent.toLowerCase();
    rows[i].style.display = (q === '' || name.indexOf(q) !== -1) ? '' : 'none';
  }
}
</script>
<?php renderFoot(); ?>
