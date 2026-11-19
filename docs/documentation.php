<?php
// Documentation library. Lists the PDF handbooks, quick-setup sheets and
// barcode sheets in this folder and opens them for reading.
//
// Login only — administrator or supervisor, the same gate every other page
// uses (requireLogin accepts either password). The PDFs themselves are NOT
// reachable by URL: docs/.htaccess denies every file in this folder except
// this page, so the only way to read one is through ?file= below, which runs
// the login check first. Nothing here writes, uploads, renames or deletes —
// the documents stay exactly as they were committed.
//
// GET documentation.php            → the list (login wall when signed out)
// GET documentation.php?file=X.pdf → streams that PDF inline for the browser's
//                                    own viewer; 404 for anything that is not
//                                    a PDF sitting directly in this folder
$GLOBALS['FS_PREFIX'] = '../';
require_once __DIR__ . '/../common.php';
requireLogin();

// Every PDF directly in this folder, keyed by file name. Built from the
// directory listing rather than trusting the request, so ?file= can only ever
// name one of these — no paths, no other extensions, no dot-dot.
function docsPdfList(): array {
    $out = [];
    foreach (glob(__DIR__ . '/*.pdf') ?: [] as $path) {
        if (is_file($path)) $out[basename($path)] = $path;
    }
    ksort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

$pdfs = docsPdfList();

if (isset($_GET['file'])) {
    $name = (string)$_GET['file'];
    if (!isset($pdfs[$name])) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Document not found.';
        exit;
    }
    $path = $pdfs[$name];
    header('Content-Type: application/pdf');
    header('Content-Length: ' . filesize($path));
    // inline = open in the browser's PDF viewer; the browser still offers its
    // own Download/Print buttons, which is fine — these are reading copies.
    header('Content-Disposition: inline; filename="' . str_replace('"', '', $name) . '"');
    header('X-Content-Type-Options: nosniff');
    // Behind a login, so keep it out of shared caches.
    header('Cache-Control: private, max-age=300');
    readfile($path);
    exit;
}

// One-line descriptions for the documents we know about. A PDF added later
// still lists — it just shows its file name tidied up and no description.
$known = [
    'OpenPantry-Administrator-Handbook.pdf'  => ['Administrator Handbook', 'Setting up and running OpenPantry: settings, inventory, reports, ordering and maintenance.'],
    'OpenPantry-Volunteer-Handbook.pdf'      => ['Volunteer Handbook', 'Day-to-day guide for volunteers working the scan station and menu counter.'],
    'OpenPantry-ScanStation-QuickSetup.pdf'  => ['Scan Station Quick Setup', 'Getting a scan station connected, signed in and ready to scan.'],
    'OpenPantry-ScanStation-Workflow.pdf'    => ['Scan Station Workflow', 'The checkout flow at the scan station, step by step.'],
    'OpenPantry-MenuStation-QuickSetup.pdf'  => ['Menu Station Quick Setup', 'Getting the menu counter station connected and ready to take orders.'],
    'OpenPantry-command-barcodes.pdf'        => ['Command Barcodes', 'Printable barcodes that control the scan station without a keyboard.'],
    'OpenPantry-Poisson-GLM-Reference.pdf'   => ['Forecasting Reference (Poisson GLM)', 'How the reorder forecast is calculated.'],
    'footprints-upc-barcodes.pdf'            => ['Footprints UPC Barcode Sheet', 'Printable barcodes for in-store items without a usable UPC.'],
    'footprints-plu-barcodes.pdf'            => ['Footprints PLU Barcode Sheet', 'Printable barcodes for produce and other PLU items.'],
];

function docsTitleFromName(string $name): string {
    $t = preg_replace('/\.pdf$/i', '', $name);
    $t = preg_replace('/^OpenPantry-/i', '', $t);
    return ucwords(str_replace(['-', '_'], ' ', $t));
}

function docsSize(int $bytes): string {
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 1) . ' MB';
    return max(1, (int)round($bytes / 1024)) . ' KB';
}

renderHead('Documentation');
renderNav('settings');
?>
<style>
  .doc-bar { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
  .doc-bar h1 { font-size: 1.3rem; color: var(--brown); margin-right: auto; }
  .doc-bar .btn { padding: 9px 14px; font-size: .9rem; text-decoration: none; }
  .doc-list { list-style: none; margin: 0; padding: 0; }
  .doc-list li { display: flex; align-items: baseline; gap: 14px; padding: 12px 4px;
                 border-bottom: 1px solid var(--border); flex-wrap: wrap; }
  .doc-list li:last-child { border-bottom: 0; }
  .doc-list a.doc-title { font-weight: 700; font-size: 1rem; color: var(--blue); text-decoration: none; }
  .doc-list a.doc-title:hover { text-decoration: underline; }
  .doc-desc { flex: 1 1 320px; font-size: .88rem; color: #555; }
  .doc-meta { font-size: .78rem; color: #888; white-space: nowrap; font-variant-numeric: tabular-nums; }
</style>
<div class="container">
  <div class="card doc-bar">
    <h1>📚 Documentation</h1>
    <a class="btn btn-secondary" href="../settings/" title="Back to Settings">⚙ Settings</a>
  </div>

  <div class="card">
    <?php if (!$pdfs): ?>
      <p>No documents are available.</p>
    <?php else: ?>
      <p style="font-size:.85rem; color:#555; margin-bottom:8px;">
        Click a document to open it in a new tab.
      </p>
      <ul class="doc-list">
        <?php foreach ($pdfs as $name => $path):
          [$title, $desc] = $known[$name] ?? [docsTitleFromName($name), ''];
        ?>
        <li>
          <a class="doc-title" href="documentation.php?file=<?= rawurlencode($name) ?>"
             target="_blank" rel="noopener">📄 <?= htmlspecialchars($title) ?></a>
          <span class="doc-desc"><?= htmlspecialchars($desc) ?></span>
          <span class="doc-meta"><?= htmlspecialchars(docsSize((int)filesize($path))) ?>
            · updated <?= htmlspecialchars(date('M j, Y', (int)filemtime($path))) ?></span>
        </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>
<?php renderFoot(); ?>
