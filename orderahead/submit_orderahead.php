<?php
// AJAX endpoint for the OrderAhead Distribution Report upload. Parses
// the uploaded CSV, finds the ItemName / Quantity / TotalPounds columns
// by header name (case-insensitive), aggregates per-item totals so
// duplicate rows for the same item sum, then in a single transaction:
//   1. Decrements inventory per matched row:
//        unit == 'each' → subtract aggregated Quantity
//        unit == 'lb'   → subtract aggregated TotalPounds
//   2. Records the import as a closed `orders` row tagged
//        "ORDER AHEAD · <filename>"
//      with one `scans` row per matched deduction. orders_listing.php
//      detects the ORDER AHEAD prefix and renders an "OrderAhead
//      delivery" pill; volume_report.php picks the scans up
//      automatically because it aggregates the scans table by day.
//
// Returns JSON:
//   { ok: true, rows, order_id, applied: [{name, unit, deducted, new_count}, ...],
//     skipped: [{name, qty, lbs}, ...] }
// Rows whose ItemName isn't in inventory are reported under `skipped`
// so staff can fix the upstream naming and re-import if needed.
$GLOBALS['FS_PREFIX'] = '../';
require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../auth.php';
requireLoginAPI();

header('Content-Type: application/json');

if (empty($_FILES['csv']) || !is_array($_FILES['csv'])) {
    echo json_encode(['ok' => false, 'error' => 'No file was uploaded.']);
    exit;
}
$f = $_FILES['csv'];
if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    // Map the common PHP upload errors to something staff-readable.
    $errs = [
        UPLOAD_ERR_INI_SIZE   => 'The file is larger than the server upload limit.',
        UPLOAD_ERR_FORM_SIZE  => 'The file is larger than the form upload limit.',
        UPLOAD_ERR_PARTIAL    => 'The upload was interrupted — try again.',
        UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server is missing a temp directory.',
        UPLOAD_ERR_CANT_WRITE => 'Server could not save the upload.',
        UPLOAD_ERR_EXTENSION  => 'A PHP extension blocked the upload.',
    ];
    $msg = $errs[$f['error']] ?? 'Upload failed.';
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}
if (!is_uploaded_file($f['tmp_name'])) {
    echo json_encode(['ok' => false, 'error' => 'Upload rejected.']);
    exit;
}

$fh = @fopen($f['tmp_name'], 'r');
if (!$fh) {
    echo json_encode(['ok' => false, 'error' => 'Could not read the uploaded file.']);
    exit;
}

// First record is the header row. Locate the three columns we care about
// by name (case-insensitive, whitespace-trimmed) so the importer keeps
// working if the export adds or reorders other columns.
$header = fgetcsv($fh);
if (!$header || !is_array($header)) {
    fclose($fh);
    echo json_encode(['ok' => false, 'error' => 'CSV is empty or unreadable.']);
    exit;
}
$norm = array_map(function ($h) { return strtolower(trim((string)$h)); }, $header);
$idxName = array_search('itemname',    $norm, true);
$idxQty  = array_search('quantity',    $norm, true);
$idxLbs  = array_search('totalpounds', $norm, true);
if ($idxName === false || $idxQty === false || $idxLbs === false) {
    fclose($fh);
    echo json_encode(['ok' => false,
        'error' => 'CSV must include ItemName, Quantity, and TotalPounds columns.']);
    exit;
}

// Aggregate per-item totals. Duplicate rows for the same ItemName (case
// differences, multiple shipments in one report) collapse to one entry
// so the inventory decrement is a single statement per item.
$totals = []; // lcName => ['name' => display, 'qty' => float, 'lbs' => float]
$rowCount = 0;
while (($row = fgetcsv($fh)) !== false) {
    if ($row === [null] || $row === false) continue; // blank line
    // Skip rows that are short — the CSV might have trailing blank lines.
    if (count($row) <= max($idxName, $idxQty, $idxLbs)) continue;
    $name = trim((string)($row[$idxName] ?? ''));
    if ($name === '') continue;
    $rowCount++;
    $qty = (float)str_replace(',', '', (string)$row[$idxQty]);
    $lbs = (float)str_replace(',', '', (string)$row[$idxLbs]);
    $lc  = strtolower($name);
    if (!isset($totals[$lc])) {
        $totals[$lc] = ['name' => $name, 'qty' => 0.0, 'lbs' => 0.0];
    }
    $totals[$lc]['qty'] += $qty;
    $totals[$lc]['lbs'] += $lbs;
}
fclose($fh);

if (!$totals) {
    echo json_encode([
        'ok'      => true,
        'rows'    => 0,
        'applied' => [],
        'skipped' => [],
    ]);
    exit;
}

$db = getDB();

// Pull inventory keyed by lowercased name. We treat inventory as the
// authoritative spelling/unit; the CSV name is only used as a key.
$invByLc = [];
foreach ($db->query("SELECT generic_name, count, unit FROM inventory") as $r) {
    $invByLc[strtolower($r['generic_name'])] = $r;
}

$applied = [];
$skipped = [];
$orderId = null;

// Filename of the upload — used as the second segment of the orders.note
// so multiple imports per day stay distinguishable. Strip anything that
// could break the "PREFIX · …" parsing convention.
$origName = isset($f['name']) ? basename((string)$f['name']) : 'upload.csv';
$origName = str_replace(['·', "\r", "\n"], ['-', '', ''], $origName);
if (strlen($origName) > 80) $origName = substr($origName, 0, 80);

$db->beginTransaction();
try {
    $upd = $db->prepare(
        "UPDATE inventory SET count = MAX(0, count - ?), updated_at = ? WHERE generic_name = ?"
    );
    $ts = now();
    // Collect per-line metadata we'll need for the scans rows below — we
    // can't insert scans yet because we don't have the order_id until we
    // know there's at least one applied line worth saving.
    $scanLines = [];
    foreach ($totals as $lc => $t) {
        if (!isset($invByLc[$lc])) {
            $skipped[] = [
                'name' => $t['name'],
                'qty'  => $t['qty'],
                'lbs'  => $t['lbs'],
            ];
            continue;
        }
        $inv  = $invByLc[$lc];
        $unit = $inv['unit'];
        // Pick which CSV column to apply, per the spec.
        $deduct = ($unit === 'lb')
            ? round((float)$t['lbs'], 2)
            : (int)round((float)$t['qty']);
        if ($deduct <= 0) {
            // Nothing to do — treat as skipped so staff see why this item
            // didn't change (zero quantity in the report).
            $skipped[] = [
                'name' => $t['name'],
                'qty'  => $t['qty'],
                'lbs'  => $t['lbs'],
            ];
            continue;
        }
        $upd->execute([$deduct, $ts, $inv['generic_name']]);
        // New on-hand: SQLite's MAX(0, …) clamps to 0, so mirror that here
        // for the response rather than reading the row back.
        $newCount = max(0.0, (float)$inv['count'] - (float)$deduct);
        $applied[] = [
            'name'      => $inv['generic_name'],
            'unit'      => $unit,
            'deducted'  => $deduct,
            'new_count' => $newCount,
        ];
        // Stage the matching scans row data. lb items go in as
        // kind=produce with weight_lbs set; each items as kind=packaged
        // with quantity set — mirrors persistDeliveryOrder().
        $scanLines[] = [
            'name'    => $inv['generic_name'],
            'unit'    => $unit,
            'deduct'  => $deduct,
        ];
    }

    // Persist the import as a closed order + per-item scans, but only if
    // at least one deduction actually applied. An all-skipped import is
    // a no-op and doesn't deserve an orders row.
    if (!empty($scanLines)) {
        $note = "ORDER AHEAD · {$origName}";
        $db->prepare(
            "INSERT INTO orders (started_at, ended_at, status, note) VALUES (?, ?, 'closed', ?)"
        )->execute([$ts, $ts, $note]);
        $orderId = (int)$db->lastInsertId();

        $insScan = $db->prepare(
            "INSERT INTO scans (order_id, barcode, generic_name, kind, quantity, weight_lbs, scanned_at)
             VALUES (?, '', ?, ?, ?, ?, ?)"
        );
        foreach ($scanLines as $ln) {
            $kind = ($ln['unit'] === 'lb') ? 'produce' : 'packaged';
            if ($ln['unit'] === 'lb') {
                $insScan->execute([$orderId, $ln['name'], $kind, 1, (float)$ln['deduct'], $ts]);
            } else {
                $insScan->execute([$orderId, $ln['name'], $kind, (int)$ln['deduct'], null, $ts]);
            }
        }
    }

    $db->commit();
} catch (\Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    echo json_encode(['ok' => false, 'error' => 'Import failed: ' . $e->getMessage()]);
    exit;
}

echo json_encode([
    'ok'       => true,
    'rows'     => $rowCount,
    'order_id' => $orderId,
    'applied'  => $applied,
    'skipped'  => $skipped,
]);
