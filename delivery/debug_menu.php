<?php
// Diagnostic view of the delivery menu pipeline. Renders four tables:
//
//   1. Environment      — picklist.db status, openpantry.db status
//   2. config_items     — raw rows from picklist.db with bracketed names
//                         and lengths so trailing whitespace is visible
//   3. inventory (in stock) — raw rows from openpantry.db, same treatment
//   4. buildDeliveryItems output — every menu item with its derived
//                         flags (has_factor, unit, family_factor, etc.)
//                         and a Match column showing whether it came from
//                         a config_item ('config') or inventory-only ('inv').
//
// The point: when an item is rendering as qty=1 / weight=NULL on a
// packing list, this page tells you whether it has has_factor=true (so
// the calculation ran but inventory.unit is wrong) or has_factor=false
// (so the config_item didn't match — and the next two tables show why).
//
// Admin-gated; no DB writes.
$GLOBALS['FS_PREFIX'] = '../';
require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/db.php';
requireLogin();

$db  = getDB();
$pdb = picklistDB();

$filter = trim((string)($_GET['q'] ?? ''));
$filterLc = $filter === '' ? null : strtolower($filter);
function keep(?string $needle, string $hay): bool {
    return $needle === null || stripos($hay, $needle) !== false;
}

// ── 2. config_items raw ──────────────────────────────────────────────────
$configRows = [];
$configErr  = null;
if ($pdb) {
    try {
        $configRows = $pdb->query(
            "SELECT id, category, item_name,
                    COALESCE(family_factor, 1.0) AS family_factor,
                    COALESCE(use_adults, 0)      AS use_adults,
                    COALESCE(use_children, 0)    AS use_children,
                    COALESCE(has_detail, 0)      AS has_detail,
                    COALESCE(size_options, '')   AS size_options,
                    COALESCE(unavailable, 0)     AS unavailable,
                    COALESCE(active, 1)          AS active
             FROM config_items
             ORDER BY item_name"
        )->fetchAll();
    } catch (\PDOException $e) {
        $configErr = $e->getMessage();
    }
}

// ── 3. inventory raw (only count>0 rows are visible to the kiosk) ────────
$inventoryRows = $db->query(
    "SELECT generic_name, count, unit FROM inventory WHERE count > 0 ORDER BY generic_name"
)->fetchAll();

// Indexes for cross-checking which side a name is missing from.
$invByLc = [];
foreach ($inventoryRows as $r) $invByLc[strtolower($r['generic_name'])] = $r;
$cfgByLc = [];
foreach ($configRows as $r) $cfgByLc[strtolower($r['item_name'])] = $r;

// ── 4. buildDeliveryItems output ─────────────────────────────────────────
$menu     = buildDeliveryItems($db, $pdb);
$catOrder = deliveryCategoryOrder($menu);
$menuRows = [];
foreach ($catOrder as $cat) {
    if (empty($menu[$cat])) continue;
    foreach ($menu[$cat] as $it) {
        // Match origin: config_item keys start with 'c:', inventory-only with 'i:'.
        $isConfig = strncmp($it['key'], 'c:', 2) === 0;
        $menuRows[] = [
            'category'      => $cat,
            'name'          => $it['name'],
            'key'           => $it['key'],
            'match'         => $isConfig ? 'config' : 'inv',
            'has_factor'    => !empty($it['has_factor']),
            'unit'          => $it['unit'] ?? '',
            'family_factor' => $it['family_factor'] ?? null,
            'use_adults'    => $it['use_adults'] ?? null,
            'use_children'  => $it['use_children'] ?? null,
            'has_detail'    => !empty($it['has_detail']),
            'sizes'         => empty($it['sizes']) ? '' : implode(', ', $it['sizes']),
        ];
    }
}

// Mismatch helpers.
$cfgInactiveButLinked = []; // config rows w/ inventory match but active=0 or unavailable=1
foreach ($configRows as $r) {
    $isActive    = (int)$r['active'] === 1;
    $isAvailable = (int)$r['unavailable'] === 0;
    if ($isActive && $isAvailable) continue;
    $lc = strtolower($r['item_name']);
    $linked = isset($invByLc[$lc]);
    if (!$linked && (int)$r['has_detail'] === 1) {
        $sizes = array_filter(array_map('trim', explode(',', (string)$r['size_options'])));
        foreach ($sizes as $sz) {
            if (isset($invByLc[strtolower($r['item_name'] . ' ' . $sz)])) { $linked = true; break; }
        }
    }
    if ($linked) $cfgInactiveButLinked[] = $r;
}

$cfgNotInInv = []; // config rows whose item_name has no in-stock inventory row
foreach ($configRows as $r) {
    $lc = strtolower($r['item_name']);
    // For has_detail rows, the inventory rows are the variants ("<name> <sz>");
    // a bare match miss is normal — flag only if no variants are present either.
    if ((int)$r['has_detail'] === 1) {
        $sizes = array_filter(array_map('trim', explode(',', (string)$r['size_options'])));
        $variantHit = false;
        foreach ($sizes as $sz) {
            if (isset($invByLc[strtolower($r['item_name'] . ' ' . $sz)])) { $variantHit = true; break; }
        }
        if (!$variantHit) $cfgNotInInv[] = $r;
    } else {
        if (!isset($invByLc[$lc])) $cfgNotInInv[] = $r;
    }
}
$invNotInCfg = []; // inventory rows with no matching config_item (these fall through to PRODUCE/DRY GOODS)
foreach ($inventoryRows as $r) {
    $lc = strtolower($r['generic_name']);
    if (!isset($cfgByLc[$lc])) {
        // Could still be a has_detail variant; check if any config has this as "<name> <size>".
        $variantOf = null;
        foreach ($configRows as $c) {
            if ((int)$c['has_detail'] !== 1) continue;
            $sizes = array_filter(array_map('trim', explode(',', (string)$c['size_options'])));
            foreach ($sizes as $sz) {
                if (strtolower($c['item_name'] . ' ' . $sz) === $lc) { $variantOf = $c['item_name'] . ' / ' . $sz; break 2; }
            }
        }
        $r['_variant_of'] = $variantOf;
        $invNotInCfg[] = $r;
    }
}

renderHead('Delivery Menu — Debug');
renderNav('delivery');
?>
<style>
  .dbg-table { width: 100%; border-collapse: collapse; font-size: .85rem; margin-top: 8px; }
  .dbg-table th, .dbg-table td { padding: 5px 8px; border-bottom: 1px solid #eee; text-align: left; vertical-align: top; }
  .dbg-table th { background: var(--cat-bg); font-size: .75rem; text-transform: uppercase; color: #555; }
  .dbg-table td.num { text-align: right; font-variant-numeric: tabular-nums; }
  .pill { display:inline-block; padding:1px 7px; border-radius:3px; font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:#fff; }
  .pill.config { background: var(--green); }
  .pill.inv    { background: #a02020; }
  .pill.true   { background: var(--brown); }
  .pill.false  { background: #888; }
  .pill.flag   { background: #806000; }
  .mono { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: .78rem; }
  .warn { background: #FFF3CD; padding: 8px 12px; border-left: 4px solid #806000; margin: 8px 0; font-size: .85rem; }
  .ok   { background: #D4EDDA; padding: 8px 12px; border-left: 4px solid #276437; margin: 8px 0; font-size: .85rem; }
  details.section { margin-bottom: 18px; }
  details.section > summary { cursor: pointer; font-weight: 700; font-size: 1rem; padding: 6px 0; color: var(--brown); }
</style>

<div class="container">
  <h1 style="margin-top:0;">Delivery Menu — Debug</h1>
  <p style="color:#555; font-size:.9rem;">
    Diagnostic view of what <code>buildDeliveryItems()</code> sees. Every row stored to
    <code>scans</code> with <code>quantity=1, weight_lbs=NULL</code> for an in-stock item
    has <strong>Match = inv</strong> in section 4 below.
  </p>

  <form method="get" style="margin: 6px 0 14px;">
    <label for="q" style="font-size:.85rem; margin-right:6px;">Filter (matches all sections):</label>
    <input type="search" id="q" name="q" value="<?= htmlspecialchars($filter) ?>"
           placeholder="e.g. apple" autocomplete="off" style="width:auto; padding:4px 8px;">
    <button type="submit" class="btn btn-secondary" style="padding:4px 12px; font-size:.85rem;">Apply</button>
    <?php if ($filter !== ''): ?>
      <a href="debug_menu.php" class="btn btn-secondary" style="padding:4px 12px; font-size:.85rem; text-decoration:none;">Clear</a>
    <?php endif; ?>
  </form>

  <!-- 1. Environment -->
  <details class="section" open>
    <summary>1. Environment</summary>
    <?php if ($pdb): ?>
      <div class="ok">✅ <code>menucounter/picklist.db</code> is loaded
        (<?= count($configRows) ?> rows in <code>config_items</code><?= $configErr ? ', error: ' . htmlspecialchars($configErr) : '' ?>).</div>
    <?php else: ?>
      <div class="warn">⚠ <code>menucounter/picklist.db</code> could not be opened — every menu item will fall through to the inventory-only branch (qty=1).</div>
    <?php endif; ?>
    <div class="ok"><code>openpantry.db.inventory</code>: <?= count($inventoryRows) ?> in-stock row(s).</div>
  </details>

  <!-- 2. config_items raw -->
  <details class="section" open>
    <summary>2. <code>config_items</code> (picklist.db, raw)</summary>
    <?php
      $visible = array_values(array_filter($configRows, function ($r) use ($filterLc) {
          return keep($filterLc, $r['item_name']);
      }));
    ?>
    <p style="font-size:.8rem; color:#777;">Showing <?= count($visible) ?> of <?= count($configRows) ?> row(s).</p>
    <?php if (!empty($visible)): ?>
      <table class="dbg-table">
        <thead>
          <tr>
            <th>id</th><th>category</th>
            <th>item_name (bracketed)</th><th class="num">len</th>
            <th class="num">family_factor</th><th>use_a/c</th>
            <th>has_detail</th><th>size_options</th>
            <th>active</th><th>unavailable</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($visible as $r): ?>
            <tr>
              <td><?= (int)$r['id'] ?></td>
              <td><?= htmlspecialchars($r['category']) ?></td>
              <td class="mono">[<?= htmlspecialchars($r['item_name']) ?>]</td>
              <td class="num"><?= strlen($r['item_name']) ?></td>
              <td class="num"><?= htmlspecialchars((string)$r['family_factor']) ?></td>
              <td><?= (int)$r['use_adults'] ?>/<?= (int)$r['use_children'] ?></td>
              <td><?= (int)$r['has_detail'] === 1 ? '<span class="pill true">yes</span>' : '<span class="pill false">no</span>' ?></td>
              <td class="mono"><?= htmlspecialchars($r['size_options']) ?></td>
              <td><?= (int)$r['active']      === 1 ? '<span class="pill true">on</span>' : '<span class="pill flag">OFF</span>' ?></td>
              <td><?= (int)$r['unavailable'] === 1 ? '<span class="pill flag">YES</span>' : '<span class="pill false">no</span>' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </details>

  <!-- 3. inventory raw -->
  <details class="section" open>
    <summary>3. <code>inventory</code> in stock (openpantry.db, raw)</summary>
    <?php
      $visible = array_values(array_filter($inventoryRows, function ($r) use ($filterLc) {
          return keep($filterLc, $r['generic_name']);
      }));
    ?>
    <p style="font-size:.8rem; color:#777;">Showing <?= count($visible) ?> of <?= count($inventoryRows) ?> row(s).</p>
    <?php if (!empty($visible)): ?>
      <table class="dbg-table">
        <thead>
          <tr><th>generic_name (bracketed)</th><th class="num">len</th><th>unit</th><th class="num">count</th></tr>
        </thead>
        <tbody>
          <?php foreach ($visible as $r): ?>
            <tr>
              <td class="mono">[<?= htmlspecialchars($r['generic_name']) ?>]</td>
              <td class="num"><?= strlen($r['generic_name']) ?></td>
              <td><?= htmlspecialchars($r['unit']) ?></td>
              <td class="num"><?= htmlspecialchars((string)$r['count']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </details>

  <!-- 4. buildDeliveryItems output -->
  <details class="section" open>
    <summary>4. <code>buildDeliveryItems()</code> output (what the kiosk + upload actually use)</summary>
    <?php
      $visible = array_values(array_filter($menuRows, function ($r) use ($filterLc) {
          return keep($filterLc, $r['name']);
      }));
    ?>
    <p style="font-size:.8rem; color:#777;">
      Showing <?= count($visible) ?> of <?= count($menuRows) ?> menu row(s).
      <span class="pill config">config</span> = matched to a <code>config_items</code> row (factor applied).
      <span class="pill inv">inv</span> = inventory-only fallback (factor NOT applied; quantity always 1).
    </p>
    <?php if (!empty($visible)): ?>
      <table class="dbg-table">
        <thead>
          <tr>
            <th>match</th><th>category</th><th>name</th><th>key</th>
            <th>has_factor</th><th>unit</th>
            <th class="num">family_factor</th><th>use_a/c</th>
            <th>has_detail</th><th>sizes</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($visible as $r): ?>
            <tr>
              <td><span class="pill <?= $r['match'] ?>"><?= $r['match'] ?></span></td>
              <td><?= htmlspecialchars($r['category']) ?></td>
              <td><strong><?= htmlspecialchars($r['name']) ?></strong></td>
              <td class="mono"><?= htmlspecialchars($r['key']) ?></td>
              <td><?= $r['has_factor'] ? '<span class="pill true">yes</span>' : '<span class="pill false">no</span>' ?></td>
              <td><?= htmlspecialchars($r['unit']) ?></td>
              <td class="num"><?= $r['family_factor'] === null ? '—' : htmlspecialchars((string)$r['family_factor']) ?></td>
              <td><?= $r['use_adults'] === null ? '—' : ((int)$r['use_adults'] . '/' . (int)$r['use_children']) ?></td>
              <td><?= $r['has_detail'] ? '<span class="pill true">yes</span>' : '<span class="pill false">no</span>' ?></td>
              <td class="mono"><?= htmlspecialchars($r['sizes']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </details>

  <!-- 5. mismatches -->
  <details class="section" open>
    <summary>5. Mismatches</summary>
    <h3 style="font-size:.9rem; margin: 10px 0 4px;">5a. <code>config_items</code> matched to inventory but flagged <strong>inactive</strong> or <strong>unavailable</strong></h3>
    <p style="font-size:.8rem; color:#777;">These rows look right at first glance — the name lines up with an in-stock inventory row — but the kiosk + upload won't see them because <code>buildDeliveryItems()</code> selects <code>WHERE active = 1</code> and skips <code>unavailable = 1</code>. As a result the matching inventory row falls through as inv-only and stores qty=1, weight=NULL.</p>
    <?php
      $visible = array_values(array_filter($cfgInactiveButLinked, function ($r) use ($filterLc) {
          return keep($filterLc, $r['item_name']);
      }));
    ?>
    <?php if (empty($visible)): ?>
      <p style="font-size:.85rem; color:#555;"><em>None.</em></p>
    <?php else: ?>
      <table class="dbg-table">
        <thead><tr><th>id</th><th>item_name (bracketed)</th><th class="num">family_factor</th><th>active</th><th>unavailable</th></tr></thead>
        <tbody>
          <?php foreach ($visible as $r): ?>
            <tr>
              <td><?= (int)$r['id'] ?></td>
              <td class="mono">[<?= htmlspecialchars($r['item_name']) ?>]</td>
              <td class="num"><?= htmlspecialchars((string)$r['family_factor']) ?></td>
              <td><?= (int)$r['active']      === 1 ? '<span class="pill true">on</span>' : '<span class="pill flag">OFF</span>' ?></td>
              <td><?= (int)$r['unavailable'] === 1 ? '<span class="pill flag">YES</span>' : '<span class="pill false">no</span>' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <h3 style="font-size:.9rem; margin: 14px 0 4px;">5b. <code>config_items</code> with NO matching in-stock inventory row</h3>
    <p style="font-size:.8rem; color:#777;">These config rows are invisible to the kiosk + upload because there's no in-stock inventory to attach them to. has_detail rows are only listed here if none of their size variants are in stock.</p>
    <?php
      $visible = array_values(array_filter($cfgNotInInv, function ($r) use ($filterLc) {
          return keep($filterLc, $r['item_name']);
      }));
    ?>
    <?php if (empty($visible)): ?>
      <p style="font-size:.85rem; color:#555;"><em>None.</em></p>
    <?php else: ?>
      <table class="dbg-table">
        <thead><tr><th>id</th><th>item_name (bracketed)</th><th class="num">len</th><th>has_detail</th><th>size_options</th></tr></thead>
        <tbody>
          <?php foreach ($visible as $r): ?>
            <tr>
              <td><?= (int)$r['id'] ?></td>
              <td class="mono">[<?= htmlspecialchars($r['item_name']) ?>]</td>
              <td class="num"><?= strlen($r['item_name']) ?></td>
              <td><?= (int)$r['has_detail'] === 1 ? '<span class="pill true">yes</span>' : '<span class="pill false">no</span>' ?></td>
              <td class="mono"><?= htmlspecialchars($r['size_options']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <h3 style="font-size:.9rem; margin: 14px 0 4px;">5c. In-stock <code>inventory</code> rows with NO matching <code>config_items</code> row</h3>
    <p style="font-size:.8rem; color:#777;">These show up on the menu, but as inventory-only items (Match = inv) — factor not applied. Variants of a has_detail config row are noted in the right column.</p>
    <?php
      $visible = array_values(array_filter($invNotInCfg, function ($r) use ($filterLc) {
          return keep($filterLc, $r['generic_name']);
      }));
    ?>
    <?php if (empty($visible)): ?>
      <p style="font-size:.85rem; color:#555;"><em>None.</em></p>
    <?php else: ?>
      <table class="dbg-table">
        <thead><tr><th>generic_name (bracketed)</th><th class="num">len</th><th>unit</th><th class="num">count</th><th>note</th></tr></thead>
        <tbody>
          <?php foreach ($visible as $r): ?>
            <tr>
              <td class="mono">[<?= htmlspecialchars($r['generic_name']) ?>]</td>
              <td class="num"><?= strlen($r['generic_name']) ?></td>
              <td><?= htmlspecialchars($r['unit']) ?></td>
              <td class="num"><?= htmlspecialchars((string)$r['count']) ?></td>
              <td><?= $r['_variant_of'] ? 'variant of: <strong>' . htmlspecialchars($r['_variant_of']) . '</strong>' : '<em>no config match</em>' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </details>
</div>
<?php renderFoot(); ?>
