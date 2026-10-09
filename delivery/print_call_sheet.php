<?php
// Printable "call sheet" — a PII cross-reference for the volunteers who
// phone clients at home to fill out delivery menus on their behalf. One
// row per pending client; columns: Called checkbox · Notes · Client #N ·
// Name · Phone · Address, City · Household · Group.
//
// This is the ONLY printed document in the delivery flow that carries the
// phone number. The marked-up worksheet (print_menus.php) and the AI
// upload pipeline (process_upload.php) intentionally never see phone,
// address, or city — the call sheet stays at the pantry desk so the same
// PII doesn't ride along with scanned forms uploaded to a third party.
// Shred or file securely at end of day.
//
// Filter: ?group=K-1|K-2|E-1|E-2|all (default: all)
// Scope:  enabled clients with delivered_at IS NULL — same "still pending
//         this rotation" scope as print_menus.php so the two prints line
//         up row-for-row when staff produce them at the same time.
$GLOBALS['FS_PREFIX'] = '../';
require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/db.php';
requireLogin();

$db = getDB();

$validGroups = deliveryGroups();
$group       = (string)($_GET['group'] ?? 'all');
$groupFilter = in_array($group, $validGroups, true) ? $group : 'all';

$sql = "SELECT id, name, adults, children, grp, address, city, phone, volunteer
          FROM delivery_clients
         WHERE enabled = 1 AND delivered_at IS NULL";
$params = [];
if ($groupFilter !== 'all') { $sql .= " AND grp = ?"; $params[] = $groupFilter; }
// Order: group → volunteer (unassigned last) → printed-roster order. Each
// volunteer's clients cluster together on the sheet so a single volunteer
// can tear off / circle their block.
$sql .= " ORDER BY grp,
                   CASE WHEN volunteer = '' THEN 1 ELSE 0 END,
                   volunteer COLLATE NOCASE,
                   sort_order, id";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$clients = array_map('fsDecryptClientFields', $stmt->fetchAll());

$today = date('M j, Y');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<link rel="icon" type="image/x-icon" href="../menucounter/favicon.ico">
<title>Call Sheet — <?= htmlspecialchars($groupFilter) ?> — <?= htmlspecialchars($today) ?></title>
<style>
  body { font-family: Arial, Helvetica, sans-serif; margin: 0; color: #000; background:#fff; }
  .controls {
    padding: 10px 14px; background: #f4f1e6; border-bottom: 1px solid #ccc;
    display: flex; align-items: center; gap: 10px; font-size: .9rem;
  }
  .controls button {
    font: inherit; padding: 6px 12px; cursor: pointer;
    border: 1px solid #888; background: #fff; border-radius: 4px;
  }
  /* One sheet of Letter, portrait. The sheet is laid out at the printable
     width (8.5in less the margins) on screen as well, so the preview looks
     like the paper and fitSheet() measures exactly what will print. --z is
     the zoom fitSheet() picks; the width grows by 1/zoom so a shrunken sheet
     still spans the page, and the white "margin" drawn around it on screen
     keeps its printed size. */
  @page { size: letter portrait; margin: 0.4in; }
  .page {
    --z: 1; zoom: var(--z);
    width: calc(7.7in / var(--z)); box-sizing: border-box;
    margin: 0.6in auto; background: #fff; font-size: 9.5pt;
    box-shadow: 0 0 0 calc(0.4in / var(--z)) #fff,
                0 0 0 calc(0.4in / var(--z) + 1px) #bbb;
  }
  @media screen { body { background: #e6e6e6; } }
  .header-row { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 8px; }
  h1 { margin: 0; font-size: 16pt; }
  .subhead { font-size: 9.5pt; color: #555; margin-top: 2px; }
  .confidential {
    border: 2px solid #8B1A1A; color: #8B1A1A; padding: 4px 10px;
    font-size: 9pt; font-weight: 800; text-transform: uppercase;
    letter-spacing: 1px; border-radius: 4px;
  }

  table.sheet { width: 100%; border-collapse: collapse; margin-top: 6px; font-size: 9.5pt; }
  table.sheet th {
    text-align: left; font-size: 8pt; text-transform: uppercase;
    color: #555; background: #f4f1e6;
    border: 1px solid #888; padding: 5px 4px;
  }
  table.sheet td {
    border: 1px solid #aaa; padding: 4px; vertical-align: top;
    page-break-inside: avoid;
  }
  /* Column sizing: keep Notes wide enough to actually write in, but not so
     wide that Phone/Address get cramped. The short columns are sized to
     their content (cell padding adds to these widths) so Address gets the
     rest of the 7.7in sheet and doesn't wrap into tall rows. */
  th.col-called,    td.col-called    { width: 38px; text-align: center; }
  th.col-notes,     td.col-notes     { width: 15%; }
  th.col-volunteer, td.col-volunteer { width: 76px; font-weight: 700; color: var(--brown, #6B4C11); }
  th.col-client,    td.col-client    { width: 44px; font-family: 'Courier New', monospace; font-weight: 700; }
  th.col-name,      td.col-name      { width: 14%; font-weight: 700; }
  th.col-phone,     td.col-phone     { width: 88px; font-variant-numeric: tabular-nums; }
  th.col-addr,      td.col-addr      { width: auto; }
  th.col-hh,        td.col-hh        { width: 34px; text-align: center; }
  th.col-grp,       td.col-grp       { width: 34px; text-align: center; font-weight: 700; }

  /* The tallest thing in a row, so it sets how many clients fit per sheet. */
  .cb-big {
    display: inline-block; width: 16px; height: 16px;
    border: 2px solid #000; border-radius: 3px; background: #fff;
  }
  /* Notes cell: visible writing baseline so the volunteer has a clear
     target to write on, instead of an empty box. */
  td.col-notes { background-image: linear-gradient(#ddd 1px, transparent 1px);
                 background-size: 100% 18px; background-position: 0 17px; }

  tr { page-break-inside: avoid; }
  tbody tr:nth-child(even) td { background-color: #fafafa; }
  tbody tr:nth-child(even) td.col-notes {
    background-color: #fafafa;
    background-image: linear-gradient(#d4d4d4 1px, transparent 1px);
    background-size: 100% 18px; background-position: 0 17px;
  }

  .empty { padding: 30px; text-align: center; color: #555; font-style: italic; }
  .footer { margin-top: 14px; font-size: 8pt; color: #555; font-style: italic; }

  @media print {
    .controls { display: none; }
    .page { margin: 0; box-shadow: none; }
  }
</style>
</head>
<body>

<div class="controls">
  <strong>Print Preview</strong>
  <span>&middot; Group: <em><?= htmlspecialchars($groupFilter) ?></em></span>
  <span>&middot; <?= count($clients) ?> client(s)</span>
  <span style="margin-left:auto;"></span>
  <button type="button" onclick="window.print()">🖨 Print</button>
  <button type="button" onclick="window.close()">Close</button>
</div>

<div class="page">
  <div class="header-row">
    <div>
      <h1>📞 Delivery Call Sheet</h1>
      <div class="subhead">
        <?= htmlspecialchars($today) ?>
        &nbsp;·&nbsp; Group: <strong><?= htmlspecialchars($groupFilter) ?></strong>
        &nbsp;·&nbsp; <?= count($clients) ?> pending client<?= count($clients) === 1 ? '' : 's' ?>
      </div>
    </div>
    <div class="confidential">Confidential · Do not scan or upload</div>
  </div>

  <?php if (!$clients): ?>
    <div class="empty">
      No pending clients to call in group <strong><?= htmlspecialchars($groupFilter) ?></strong>.
    </div>
  <?php else: ?>
    <table class="sheet">
      <thead>
        <tr>
          <th class="col-called">Called</th>
          <th class="col-volunteer">Volunteer</th>
          <th class="col-notes">Notes</th>
          <th class="col-client">Client</th>
          <th class="col-name">Name</th>
          <th class="col-phone">Phone</th>
          <th class="col-addr">Address</th>
          <th class="col-hh">A / C</th>
          <th class="col-grp">Grp</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($clients as $c):
          $cityPart = trim((string)$c['city']) !== '' ? ', ' . $c['city'] : '';
        ?>
          <tr>
            <td class="col-called"><span class="cb-big" aria-hidden="true"></span></td>
            <td class="col-volunteer"><?= htmlspecialchars($c['volunteer'] ?? '') ?></td>
            <td class="col-notes"></td>
            <td class="col-client">#<?= (int)$c['id'] ?></td>
            <td class="col-name"><?= htmlspecialchars($c['name']) ?></td>
            <td class="col-phone"><?= htmlspecialchars($c['phone']) ?></td>
            <td class="col-addr"><?= htmlspecialchars($c['address'] . $cityPart) ?></td>
            <td class="col-hh"><?= (int)$c['adults'] ?> / <?= (int)$c['children'] ?></td>
            <td class="col-grp"><?= htmlspecialchars($c['grp']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div class="footer">
      Use the Client # to match each row to the corresponding worksheet.
      Shred or file securely at end of day.
    </div>
  <?php endif; ?>
</div>

<script>
  // Zoom the sheet down just enough to fit one Letter page (10.2in tall inside
  // the 0.4in margins, less a little for rounding). Zoom shrinks type, boxes and
  // padding together. Below 60% the print gets too small to write on, so a very
  // long list stops there and continues on a second sheet under a repeated header.
  var SHEET_H = 10.15 * 96, MIN_Z = 0.6;
  function fitSheet(p) {
    function height(z) {
      p.style.setProperty('--z', z);
      return p.getBoundingClientRect().height;
    }
    if (height(1) <= SHEET_H) return;
    // Binary-search the largest zoom that fits. A straight SHEET_H / height
    // ratio isn't enough: Chrome rounds zoomed sizes up a little, so a sheet
    // shrinks less than its zoom.
    var lo = MIN_Z, hi = 1;
    for (var i = 0; i < 8; i++) {
      var mid = (lo + hi) / 2;
      if (height(mid) <= SHEET_H) lo = mid; else hi = mid;
    }
    height(lo);
  }
  function fitSheets() { document.querySelectorAll('.page').forEach(fitSheet); }
  fitSheets();
  window.addEventListener('beforeprint', fitSheets);
</script>

</body>
</html>
