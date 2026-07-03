<?php
// Delivery client manager. Lets staff build and maintain a reusable list of
// delivery clients — each carrying the same fields as the "New Delivery" form
// (first name, adults, children, group, address, city, phone) plus an
// "enabled" flag. The delivery menu (../index.php) cycles through the enabled
// clients that haven't yet had a packing list printed.
//
// Administrator-password protected (this manager edits the client roster, so it
// sits behind the shared admin login). CRUD happens via plain POST + the
// Post/Redirect/Get pattern.
$GLOBALS['FS_PREFIX'] = '../../';
require_once __DIR__ . '/../../common.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../db.php';
requireLogin();

$db     = getDB();           // openpantry.db
$cities = deliveryCities();

// ── Weekly auto-reset ───────────────────────────────────────────────────────
// The delivery rotation runs a week at a time (Monday–Sunday). The first time
// this page is loaded in a new week — i.e. once a week, after the previous
// Sunday — every client's delivered_at is cleared so the whole roster is
// pending again for the new week. A stored ISO week key (weeks start Monday)
// guards it so it fires exactly once per week regardless of how many loads.
$thisWeek = date('o-W'); // ISO-8601 year-week; rolls over each Monday (after Sunday)
if (setting('delivery_clients_reset_week', '') !== $thisWeek) {
    $db->exec("UPDATE delivery_clients SET delivered_at = NULL");
    setSetting('delivery_clients_reset_week', $thisWeek);
}

// ── Mutations (Post/Redirect/Get so refresh doesn't re-submit) ──────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save') {
        $id       = (int)($_POST['id'] ?? 0);
        $name     = trim((string)($_POST['name'] ?? ''));
        $adults   = max(1, min(6, (int)($_POST['adults']   ?? 1)));
        $children = max(0, min(6, (int)($_POST['children'] ?? 0)));
        $grp      = trim((string)($_POST['grp'] ?? ''));
        // When editing, fetch the client's current group + (decrypted) city so
        // we can keep them valid even if those values were later removed from
        // the pickers — removing an option must never silently change a saved
        // client's group/city.
        $curGrp = $curCity = '';
        if ($id > 0) {
            $curStmt = $db->prepare("SELECT grp, city FROM delivery_clients WHERE id=?");
            $curStmt->execute([$id]);
            $curRow  = $curStmt->fetch(PDO::FETCH_ASSOC) ?: ['grp' => '', 'city' => ''];
            $curGrp  = (string)$curRow['grp'];
            $curCity = fsDecrypt((string)$curRow['city']);
        }
        // Group: must be a current group, or the client's existing one.
        $allowedGroups = deliveryGroups();
        if ($curGrp !== '' && !in_array($curGrp, $allowedGroups, true)) $allowedGroups[] = $curGrp;
        if (!in_array($grp, $allowedGroups, true)) {
            $grp = $allowedGroups[0] ?? 'E-1';
        }
        $address  = trim((string)($_POST['address'] ?? ''));
        // City: must be a current city, or the client's existing one. Anything
        // else is cleared (treated as "no city").
        $city = (string)($_POST['city'] ?? '');
        $allowedCities = $cities;
        if ($curCity !== '' && !in_array($curCity, $allowedCities, true)) $allowedCities[] = $curCity;
        if ($city !== '' && !in_array($city, $allowedCities, true)) $city = '';
        $phone    = trim((string)($_POST['phone'] ?? ''));
        $volunteer = trim((string)($_POST['volunteer'] ?? ''));
        $enabled  = isset($_POST['enabled']) ? 1 : 0;

        if ($name === '') {
            header('Location: client.php?err=name' . ($id ? '&edit=' . $id : ''));
            exit;
        }

        // Encrypt PII at rest. Validation above (e.g. city against the allowed
        // list) ran on the plaintext; these vars aren't read again after the
        // write, so reassigning them in place is safe.
        $address = fsMaybeEncrypt($address);
        $city    = fsMaybeEncrypt($city);
        $phone   = fsMaybeEncrypt($phone);

        if ($id > 0) {
            $db->prepare(
                "UPDATE delivery_clients
                    SET name=?, adults=?, children=?, grp=?, address=?, city=?, phone=?, volunteer=?, enabled=?
                  WHERE id=?"
            )->execute([$name, $adults, $children, $grp, $address, $city, $phone, $volunteer, $enabled, $id]);
        } else {
            $nextSort = (int)$db->query("SELECT COALESCE(MAX(sort_order),0)+1 FROM delivery_clients")->fetchColumn();
            $db->prepare(
                "INSERT INTO delivery_clients
                    (name, adults, children, grp, address, city, phone, volunteer, enabled, sort_order, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute([$name, $adults, $children, $grp, $address, $city, $phone, $volunteer, $enabled, $nextSort, now()]);
        }
        header('Location: client.php?saved=1');
        exit;
    }

    if ($action === 'toggle') {
        $id      = (int)($_POST['id'] ?? 0);
        $enabled = isset($_POST['enabled']) ? 1 : 0;
        $db->prepare("UPDATE delivery_clients SET enabled=? WHERE id=?")->execute([$enabled, $id]);
        header('Location: client.php');
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("DELETE FROM delivery_clients WHERE id=?")->execute([$id]);
        header('Location: client.php?deleted=1');
        exit;
    }

    if ($action === 'reset') {
        // Put one client back into the delivery rotation.
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("UPDATE delivery_clients SET delivered_at=NULL WHERE id=?")->execute([$id]);
        header('Location: client.php?reset=1');
        exit;
    }

    if ($action === 'reset_all') {
        // Start a fresh delivery round — everyone pending again.
        $db->exec("UPDATE delivery_clients SET delivered_at=NULL");
        header('Location: client.php?reset_all=1');
        exit;
    }

    if ($action === 'reorder') {
        // Drag-and-drop reorder of the client list. The JS posts the new
        // ordering as order[]=<id>&order[]=<id>… and we rewrite each row's
        // sort_order to its 1-based position. Wrapped in a transaction so a
        // partial failure doesn't leave the list half-renumbered.
        $ids = $_POST['order'] ?? [];
        if (is_array($ids) && $ids) {
            $db->beginTransaction();
            $stmt = $db->prepare("UPDATE delivery_clients SET sort_order=? WHERE id=?");
            foreach ($ids as $i => $id) {
                $iid = (int)$id;
                if ($iid > 0) $stmt->execute([(int)$i + 1, $iid]);
            }
            $db->commit();
        }
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }
}

// ── Load ────────────────────────────────────────────────────────────────────
// Decrypt the PII columns for display / the edit-form prefill ($edit is taken
// from this list below).
$clients = array_map('fsDecryptClientFields', $db->query(
    "SELECT id, name, adults, children, grp, address, city, phone, volunteer, enabled, delivered_at
       FROM delivery_clients
      ORDER BY sort_order, id"
)->fetchAll());

$pendingCount   = 0;
$deliveredCount = 0;
foreach ($clients as $c) {
    if ($c['delivered_at']) $deliveredCount++;
    elseif ((int)$c['enabled'] === 1) $pendingCount++;
}

// If editing, pull the row to pre-fill the form.
$editId = (int)($_GET['edit'] ?? 0);
$edit   = null;
if ($editId > 0) {
    foreach ($clients as $c) {
        if ((int)$c['id'] === $editId) { $edit = $c; break; }
    }
}

// Form field defaults (edit row or blank new client).
$fName     = $edit['name']     ?? '';
$fAdults   = (int)($edit['adults']   ?? 1);
$fChildren = (int)($edit['children'] ?? 0);
$fGrp      = $edit['grp']      ?? '';
$fAddress  = $edit['address']  ?? '';
$fCity     = $edit['city']     ?? '';
$fPhone     = $edit['phone']     ?? '';
$fVolunteer = $edit['volunteer'] ?? '';
$fEnabled   = $edit ? (int)$edit['enabled'] === 1 : true;

// Selectable groups for the dropdowns. When editing a client whose group was
// removed from the picker, keep it as an option so saving doesn't lose it.
$groupList   = deliveryGroups();
$groupCities = deliveryGroupCities(); // group => [cities] for the City filter
$formGroups  = $groupList;
if ($fGrp !== '' && !in_array($fGrp, $formGroups, true)) {
    $formGroups[] = $fGrp;
}
// City options for the form. Keep an editing client's city even if it was
// removed from the picker so it isn't lost on save.
$formCities = $cities;
if ($fCity !== '' && !in_array($fCity, $formCities, true)) {
    $formCities[] = $fCity;
}

renderHead('Delivery Clients');
renderNav('delivery'); // full app menu + Log out, with Deliveries highlighted
?>
<div class="container">

  <!-- Row 1: kiosk link + the pending/delivered tally. -->
  <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:10px;">
    <a href="../" class="btn btn-secondary" style="text-decoration:none;"
       target="_blank" rel="noopener">Client Delivery Menu</a>

    <div style="margin-left:auto; font-size:.85rem; color:#777;">
      <strong style="color:var(--green);"><?= $pendingCount ?></strong> pending ·
      <strong style="color:var(--brown);"><?= $deliveredCount ?></strong> delivered
    </div>
  </div>

  <!-- Row 2: Print Delivery Menus, Print Call Sheet, Upload Completed
       Delivery Menus (PDF -> AI -> orders), and Print Packing & Delivery
       Lists. Ordered to match the workflow: print the anonymous
       worksheets, print the PII call sheet (stays at the pantry, never
       scanned), make calls + collect completed menus, upload to AI,
       print packing lists for delivery. Each Print button toggles its
       own group-picker that drops in underneath; only one picker is
       visible at a time. -->
  <div style="display:flex; align-items:center; gap:8px; flex-wrap:nowrap;
              margin-bottom:10px;">
    <button type="button" class="btn btn-secondary row2-btn"
            onclick="togglePrintMenus()" aria-controls="printMenusForm">
      🖨 Print Delivery Menus
    </button>

    <button type="button" class="btn btn-secondary row2-btn"
            onclick="togglePrintCallSheet()" aria-controls="printCallSheetForm">
      📞 Print Call Sheet
    </button>

    <a href="../process_upload.php" class="btn btn-secondary row2-btn"
       style="text-decoration:none;">
      📤 Upload Completed Delivery Menus
    </a>

    <button type="button" class="btn btn-secondary row2-btn"
            onclick="togglePrintPacking()" aria-controls="printPackingForm">
      🧾 Print Packing &amp; Delivery Lists
    </button>
  </div>

  <!-- Row 3: group-picker forms. Each lives in its own row and is hidden
       until the matching Print button is clicked. Clicking the other
       Print button swaps which one is shown so the user never sees both
       at once. -->
  <form id="printMenusForm" method="get" action="../print_menus.php"
        target="_blank" rel="noopener"
        style="display:none; align-items:center; gap:8px;
               margin:0 0 16px 0; padding:10px 14px;
               background:var(--cat-bg); border:1px solid var(--border);
               border-radius:8px;">
    <strong style="font-size:.85rem; color:var(--brown);">Print Delivery Menus —</strong>
    <label for="pm_group"
           style="margin:0; text-transform:none; font-size:.9rem; font-weight:600;">
      Group:
    </label>
    <select id="pm_group" name="group" style="width:auto;">
      <option value="all">All groups</option>
      <?php foreach ($groupList as $g): ?>
        <option value="<?= htmlspecialchars($g) ?>"><?= htmlspecialchars($g) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary"
            style="padding:6px 14px; font-size:.85rem;">Print</button>
  </form>

  <form id="printCallSheetForm" method="get" action="../print_call_sheet.php"
        target="_blank" rel="noopener"
        style="display:none; align-items:center; gap:8px;
               margin:0 0 16px 0; padding:10px 14px;
               background:var(--cat-bg); border:1px solid var(--border);
               border-radius:8px;">
    <strong style="font-size:.85rem; color:var(--brown);">Print Call Sheet —</strong>
    <label for="cs_group"
           style="margin:0; text-transform:none; font-size:.9rem; font-weight:600;">
      Group:
    </label>
    <select id="cs_group" name="group" style="width:auto;">
      <option value="all">All groups</option>
      <?php foreach ($groupList as $g): ?>
        <option value="<?= htmlspecialchars($g) ?>"><?= htmlspecialchars($g) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary"
            style="padding:6px 14px; font-size:.85rem;">Print</button>
  </form>

  <form id="printPackingForm" method="get" action="../print_packing_lists.php"
        target="_blank" rel="noopener"
        style="display:none; align-items:center; gap:8px;
               margin:0 0 16px 0; padding:10px 14px;
               background:var(--cat-bg); border:1px solid var(--border);
               border-radius:8px;">
    <strong style="font-size:.85rem; color:var(--brown);">Print Packing &amp; Delivery Lists —</strong>
    <label for="pp_group"
           style="margin:0; text-transform:none; font-size:.9rem; font-weight:600;">
      Group:
    </label>
    <select id="pp_group" name="group" style="width:auto;">
      <option value="all">All groups</option>
      <?php foreach ($groupList as $g): ?>
        <option value="<?= htmlspecialchars($g) ?>"><?= htmlspecialchars($g) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary"
            style="padding:6px 14px; font-size:.85rem;">Print</button>
  </form>

  <?php if (isset($_GET['saved'])): ?>
    <div class="banner success">✅ Client saved.</div>
  <?php elseif (isset($_GET['deleted'])): ?>
    <div class="banner success">🗑 Client deleted.</div>
  <?php elseif (isset($_GET['reset'])): ?>
    <div class="banner success">↺ Client returned to the delivery rotation.</div>
  <?php elseif (isset($_GET['reset_all'])): ?>
    <div class="banner success">↺ All clients returned to the delivery rotation.</div>
  <?php elseif (isset($_GET['err']) && $_GET['err'] === 'name'): ?>
    <div class="banner error">⚠ A first name is required.</div>
  <?php endif; ?>

  <!-- Add / edit form ------------------------------------------------------ -->
  <div class="card">
    <h2><?= $edit ? 'Edit Client' : 'Add Client' ?></h2>
    <form method="post" action="client.php">
      <input type="hidden" name="action" value="save">
      <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>

      <div class="row" style="align-items:end;">
        <div style="flex:2 1 200px;">
          <label for="name">First Name</label>
          <input type="text" id="name" name="name" required autocomplete="off"
                 placeholder="First name only" value="<?= htmlspecialchars($fName) ?>">
        </div>
        <div style="flex:1 1 110px;">
          <label for="adults">Adults</label>
          <select id="adults" name="adults">
            <?php for ($i = 1; $i <= 6; $i++): ?>
              <option value="<?= $i ?>"<?= $i === $fAdults ? ' selected' : '' ?>><?= $i ?></option>
            <?php endfor; ?>
          </select>
        </div>
        <div style="flex:1 1 110px;">
          <label for="children">Children</label>
          <select id="children" name="children">
            <?php for ($i = 0; $i <= 6; $i++): ?>
              <option value="<?= $i ?>"<?= $i === $fChildren ? ' selected' : '' ?>><?= $i ?></option>
            <?php endfor; ?>
          </select>
        </div>
        <div style="flex:1 1 120px;">
          <label for="grp">Group</label>
          <select id="grp" name="grp" required>
            <option value="" disabled<?= $fGrp === '' ? ' selected' : '' ?>>Select group…</option>
            <?php foreach ($formGroups as $g): ?>
              <option value="<?= htmlspecialchars($g) ?>"<?= $g === $fGrp ? ' selected' : '' ?>><?= htmlspecialchars($g) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="row" style="margin-top:14px; align-items:end;">
        <div style="flex:3 1 280px;">
          <label for="address">Address</label>
          <input type="text" id="address" name="address" autocomplete="off"
                 placeholder="Street" value="<?= htmlspecialchars($fAddress) ?>">
        </div>
        <div style="flex:1 1 200px;">
          <label for="city">City</label>
          <select id="city" name="city">
            <option value=""<?= $fCity === '' ? ' selected' : '' ?>>Select city…</option>
            <?php foreach ($formCities as $c): ?>
              <option value="<?= htmlspecialchars($c) ?>"<?= $c === $fCity ? ' selected' : '' ?>><?= htmlspecialchars($c) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="flex:1 1 160px;">
          <label for="phone">Phone</label>
          <input type="text" id="phone" name="phone" autocomplete="off"
                 placeholder="(555) 123-4567" value="<?= htmlspecialchars($fPhone) ?>">
        </div>
      </div>

      <div class="row" style="margin-top:14px; align-items:end;">
        <div style="flex:1 1 280px;">
          <label for="volunteer">Volunteer
            <span style="font-weight:400; color:#777; text-transform:none; font-size:.75rem;">
              (optional)
            </span>
          </label>
          <input type="text" id="volunteer" name="volunteer" autocomplete="off"
                 placeholder="Name of the volunteer assigned to this client"
                 value="<?= htmlspecialchars($fVolunteer) ?>">
        </div>
      </div>

      <div style="display:flex; align-items:center; gap:18px; margin-top:16px; flex-wrap:wrap;">
        <label style="display:flex; align-items:center; gap:8px; text-transform:none; font-size:.95rem; margin:0; cursor:pointer;">
          <input type="checkbox" name="enabled" value="1" style="width:auto;"<?= $fEnabled ? ' checked' : '' ?>>
          Enabled (include in delivery rotation)
        </label>
        <div style="margin-left:auto; display:flex; gap:10px;">
          <?php if ($edit): ?>
            <a href="client.php" class="btn btn-secondary" style="text-decoration:none;">Cancel</a>
          <?php endif; ?>
          <button type="submit" class="btn btn-primary"><?= $edit ? '💾 Save Changes' : '＋ Add Client' ?></button>
        </div>
      </div>
    </form>
  </div>

  <!-- Client list ---------------------------------------------------------- -->
  <div class="card">
    <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:6px;">
      <h2 style="margin:0; border:0; padding:0;">Clients (<?= count($clients) ?>)</h2>
      <?php if ($deliveredCount > 0): ?>
        <form method="post" action="client.php" style="margin-left:auto;"
              onsubmit="return confirm('Return ALL clients to the pending delivery rotation?');">
          <input type="hidden" name="action" value="reset_all">
          <button type="submit" class="btn btn-secondary" style="padding:6px 12px; font-size:.8rem;">↺ Reset all delivered</button>
        </form>
      <?php endif; ?>
    </div>

    <?php if (!$clients): ?>
      <p style="color:#777; padding:10px 0;">No clients yet. Add one above to start a delivery rotation.</p>
    <?php else: ?>
    <div class="dc-table-wrap">
    <table class="data dc-table">
      <thead><tr>
        <th class="dc-drag-th" aria-label="Drag handle"></th>
        <th>Name</th>
        <th class="num">Adults</th>
        <th class="num">Children</th>
        <th>Group</th>
        <th>Address</th>
        <th>City</th>
        <th>Phone</th>
        <th>Volunteer</th>
        <th>Enabled</th>
        <th>Status</th>
        <th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($clients as $c): ?>
        <tr data-client-id="<?= (int)$c['id'] ?>"<?= (int)$c['enabled'] === 1 ? '' : ' style="opacity:.55;"' ?>>
          <td class="dc-drag-cell" draggable="true" title="Drag to reorder" aria-label="Drag to reorder">⋮⋮</td>
          <td><strong><?= htmlspecialchars($c['name']) ?></strong></td>
          <td class="num"><?= (int)$c['adults'] ?></td>
          <td class="num"><?= (int)$c['children'] ?></td>
          <td><?= htmlspecialchars($c['grp']) ?></td>
          <td><?= htmlspecialchars($c['address']) ?></td>
          <td><?= htmlspecialchars($c['city']) ?></td>
          <td><?= htmlspecialchars($c['phone']) ?></td>
          <td><?= htmlspecialchars($c['volunteer'] ?? '') ?></td>
          <td>
            <form method="post" action="client.php" style="margin:0;">
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
              <input type="checkbox" name="enabled" value="1" style="width:auto;"
                     onchange="this.form.submit()"<?= (int)$c['enabled'] === 1 ? ' checked' : '' ?>>
            </form>
          </td>
          <td>
            <?php if ($c['delivered_at']): ?>
              <span style="color:var(--brown); font-size:.78rem;">✓ Delivered</span>
            <?php else: ?>
              <span style="color:var(--green); font-size:.78rem;">Pending</span>
            <?php endif; ?>
          </td>
          <td>
            <div class="dc-actions">
              <a href="client.php?edit=<?= (int)$c['id'] ?>" class="btn btn-secondary">Edit</a>
              <?php if ($c['delivered_at']): ?>
                <form method="post" action="client.php">
                  <input type="hidden" name="action" value="reset">
                  <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                  <button class="btn btn-secondary">↺ Reset</button>
                </form>
              <?php endif; ?>
              <form method="post" action="client.php"
                    onsubmit="return confirm('Delete <?= htmlspecialchars(addslashes($c['name'])) ?>?');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <button class="btn btn-danger">Delete</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>

</div>
<style>
  /* Make the 11-column client table fit the card without horizontal scroll
     by shrinking cell padding + font compared to the base `.data` styles
     (8px 10px / .9rem). Selectors are written as `table.dc-table` so they
     beat the base `table.data th, table.data td` specificity. Long phone
     digit strings get broken with `overflow-wrap` so they can't anchor a
     wide column. */
  .dc-table-wrap { width: 100%; }
  table.dc-table { width: 100%; table-layout: auto; }
  table.dc-table th, table.dc-table td {
    padding: 5px 5px; font-size: .78rem; overflow-wrap: anywhere;
  }
  table.dc-table th { font-size: .65rem; }
  table.dc-table td:last-child { width: 1%; white-space: nowrap; }
  /* Drag-and-drop reorder handle (first column). The handle is narrow and
     uses `cursor: grab`; making only the handle cell `draggable` keeps the
     row's inline checkbox + action buttons fully clickable. The dragged
     row dims via `.dc-dragging` so the user can see where it'll land. */
  table.dc-table th.dc-drag-th, table.dc-table td.dc-drag-cell {
    width: 18px; text-align: center; padding: 5px 2px;
  }
  table.dc-table td.dc-drag-cell {
    cursor: grab; color: #aaa; font-weight: 700;
    user-select: none; -webkit-user-select: none;
    letter-spacing: -1px; line-height: 1;
  }
  table.dc-table td.dc-drag-cell:active { cursor: grabbing; color: var(--brown); }
  table.dc-table tr.dc-dragging { opacity: .35; }
  table.dc-table tr.dc-dragging td { background: var(--cat-bg); }
  /* Row 2 action buttons: compact enough that the trio (Print Delivery
     Menus + Upload Completed Delivery Menus + Print Packing & Delivery
     Lists) fits the container on a typical desktop without overflow.
     `flex:1 1 auto` lets them share leftover width if any. */
  .row2-btn {
    flex: 1 1 auto;
    padding: 7px 10px;
    font-size: .82rem;
    white-space: nowrap;
    text-align: center;
  }
  /* Stack the per-row action buttons vertically so a third (Reset) button
     drops in cleanly under the others without pushing Delete past the card
     edge. Buttons keep their natural compact width (no stretch) so the
     action column stays as narrow as the widest button. */
  .dc-actions { display: flex; flex-direction: column; gap: 6px; align-items: flex-end; }
  .dc-actions form { margin: 0; }
  .dc-actions .btn { padding: 4px 10px; font-size: .78rem; text-decoration: none; white-space: nowrap; }
  .dc-table td:last-child { width: 1%; white-space: nowrap; }
</style>
<script>
// Reveal the group-picker row underneath the button row. Only one picker
// is shown at a time — opening any of them auto-closes the others, and
// clicking the same Print button again closes its picker.
function _showOnly(meId, otherIds) {
  var me = document.getElementById(meId);
  if (!me) return;
  otherIds.forEach(function (id) {
    var el = document.getElementById(id);
    if (el) el.style.display = 'none';
  });
  me.style.display = (me.style.display === 'flex') ? 'none' : 'flex';
}
function togglePrintMenus()     { _showOnly('printMenusForm',     ['printCallSheetForm', 'printPackingForm']); }
function togglePrintCallSheet() { _showOnly('printCallSheetForm', ['printMenusForm',     'printPackingForm']); }
function togglePrintPacking()   { _showOnly('printPackingForm',   ['printMenusForm',     'printCallSheetForm']); }

// Drag-and-drop reorder of the clients table.
// Only the leading drag-handle cell is `draggable`, so dragging never
// starts when the user clicks the Enabled checkbox or an action button.
// The dragstart sets the drag image to the parent row (so the user sees
// the whole row floating), and dragover live-reorders the DOM. The new
// ordering is POSTed once on drop via fetch — no full-page reload, so the
// reorder feels instant and the rest of the UI state is preserved.
(function () {
  var tbody = document.querySelector('.dc-table tbody');
  if (!tbody) return;
  var dragRow = null;

  tbody.querySelectorAll('.dc-drag-cell').forEach(function (handle) {
    handle.addEventListener('dragstart', function (e) {
      dragRow = handle.closest('tr');
      if (!dragRow) return;
      e.dataTransfer.effectAllowed = 'move';
      // Some browsers (notably Firefox) won't start a drag without data.
      try { e.dataTransfer.setData('text/plain', dragRow.dataset.clientId || ''); } catch (_) {}
      // Use the full row as the drag image rather than just the handle cell.
      try { e.dataTransfer.setDragImage(dragRow, 20, 12); } catch (_) {}
      // Apply the dim class on the next tick so the drag image snapshot
      // captures the row at full opacity before we dim it.
      setTimeout(function () { if (dragRow) dragRow.classList.add('dc-dragging'); }, 0);
    });
    handle.addEventListener('dragend', function () {
      if (!dragRow) return;
      dragRow.classList.remove('dc-dragging');
      saveOrder();
      dragRow = null;
    });
  });

  // Find the row whose vertical midpoint is just below the cursor — the
  // dragged row gets inserted before it. Returns null when the cursor is
  // past the last row, meaning "append to end".
  function getDragAfterElement(y) {
    var rows = Array.prototype.slice.call(
      tbody.querySelectorAll('tr:not(.dc-dragging)')
    );
    var closest = { offset: Number.NEGATIVE_INFINITY, element: null };
    rows.forEach(function (row) {
      var box = row.getBoundingClientRect();
      var offset = y - box.top - box.height / 2;
      if (offset < 0 && offset > closest.offset) {
        closest = { offset: offset, element: row };
      }
    });
    return closest.element;
  }

  tbody.addEventListener('dragover', function (e) {
    if (!dragRow) return;
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    var afterEl = getDragAfterElement(e.clientY);
    if (afterEl == null) {
      if (tbody.lastElementChild !== dragRow) tbody.appendChild(dragRow);
    } else if (afterEl !== dragRow) {
      tbody.insertBefore(dragRow, afterEl);
    }
  });

  function saveOrder() {
    var ids = Array.prototype.slice
      .call(tbody.querySelectorAll('tr[data-client-id]'))
      .map(function (tr) { return tr.dataset.clientId; });
    if (!ids.length) return;
    var fd = new FormData();
    fd.append('action', 'reorder');
    ids.forEach(function (id) { fd.append('order[]', id); });
    fetch('client.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .catch(function () { /* user can drag again if it fails */ });
  }
})();

// When the Group changes, narrow the City dropdown to the cities that group
// serves (the admin-managed Group → Cities mapping). A group with no mapping
// (or an empty one) offers all cities. Fires only on user change, so an
// existing client's saved city isn't disturbed when the edit form first loads.
var GROUP_CITIES = <?= json_encode($groupCities, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
var ALL_CITIES   = <?= json_encode(array_values($cities), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
(function () {
  var grp  = document.getElementById('grp');
  var city = document.getElementById('city');
  if (!grp || !city) return;

  function citiesForGroup(g) {
    var mapped = GROUP_CITIES[g];
    return (mapped && mapped.length) ? mapped : ALL_CITIES;
  }

  grp.addEventListener('change', function () {
    var current = city.value;
    var list = citiesForGroup(grp.value).slice();
    // Preserve a previously-chosen city that isn't in the group's list (e.g.
    // editing a client whose city the group doesn't serve) so it isn't lost.
    if (current && list.indexOf(current) === -1) list.unshift(current);

    city.innerHTML = '';
    var ph = document.createElement('option');
    ph.value = ''; ph.textContent = 'Select city…';
    city.appendChild(ph);
    list.forEach(function (c) {
      var o = document.createElement('option');
      o.value = c; o.textContent = c;
      if (c === current) o.selected = true;
      city.appendChild(o);
    });
    // If the group serves exactly one city and nothing was chosen yet,
    // auto-select it (mirrors the old single-city auto-fill).
    if (!current && list.length === 1) city.value = list[0];
  });
})();
</script>
<?php renderFoot(); ?>
