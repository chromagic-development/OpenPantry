<?php
// Upload + AI-read flow for completed delivery menus.
//   GET  -> show upload form (PDF only)
//   POST -> rasterize PDF pages with Ghostscript at 220 DPI, send each
//           page image to the OpenAI Chat Completions API (gpt-4o by
//           default) TWICE with deliberately different framings:
//             Pass A: flat enumeration in printed order, temperature 0.0
//             Pass B: category-by-category with explicit per-category
//                     counts, temperature 0.3
//           Each pass returns JSON of the shape
//           { client_id, items: [{form_name, status}, …] }. The server
//           OR-s the per-item verdicts and persists every item either
//           pass saw as "checked" or "unsure" via persistDeliveryOrder()
//           — same path the kiosk uses, so orders show up labeled
//           "DELIVERY" in orders_listing.php and the items deduct from
//           inventory.
//
// Independence is the whole point of the two-pass strategy. Two
// identical-prompt + temperature-0 passes return near-identical output
// and barely help. Varying both the prompt structure and the temperature
// forces the model to attend to the image differently, so single-pass
// misses (especially mid-list categories like Frozen Items on long
// menus) get caught by the other pass. Disagreements are surfaced as
// "verify against the paper" warnings in the summary table so staff can
// spot-check before items leave the pantry.
//
// Requires Ghostscript on the server. Path resolution:
//   1. settings.ghostscript_bin (override; explicit path)
//   2. PATH lookup of gs / gswin64c / gswin32c
//   3. Common Windows install locations under C:\Program Files\gs\
$GLOBALS['FS_PREFIX'] = '../';
require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/db.php';
requireLogin();

$db  = getDB();
$pdb = picklistDB();

// ── Helpers ────────────────────────────────────────────────────────────────

function findGhostscript(): ?string {
    $override = trim((string)setting('ghostscript_bin', ''));
    if ($override !== '') {
        // Trust user-set absolute path or PATH-resolvable name.
        return $override;
    }
    $isWin = stripos(PHP_OS, 'WIN') === 0;
    $names = $isWin ? ['gswin64c', 'gswin32c', 'gs'] : ['gs'];
    foreach ($names as $n) {
        $cmd = $isWin ? "where $n 2>nul" : "command -v $n 2>/dev/null";
        $found = trim((string)@shell_exec($cmd));
        if ($found !== '') {
            // `where` can return multiple lines on Windows; take the first.
            $first = strtok($found, "\r\n");
            return $first ?: $n;
        }
    }
    if ($isWin) {
        foreach (glob('C:\Program Files\gs\gs*\bin\gswin64c.exe') ?: [] as $p) return $p;
        foreach (glob('C:\Program Files (x86)\gs\gs*\bin\gswin32c.exe') ?: [] as $p) return $p;
    }
    return null;
}

function rasterizePdf(string $gsBin, string $pdfPath, string $outDir, int $dpi = 220): array {
    if (!is_dir($outDir) && !@mkdir($outDir, 0700, true)) {
        return ['ok' => false, 'error' => "Could not create temp dir $outDir"];
    }
    $tplt = $outDir . DIRECTORY_SEPARATOR . 'page-%03d.png';
    // -dDownScaleFactor=1 keeps full res; 150 dpi is enough for vision to
    // pick up a 14px checkbox at letter-page scale without blowing up tokens.
    $cmd = escapeshellarg($gsBin)
         . ' -dQUIET -dNOPAUSE -dBATCH -dSAFER'
         . ' -sDEVICE=png16m'
         . ' -r' . (int)$dpi
         . ' -sOutputFile=' . escapeshellarg($tplt)
         . ' ' . escapeshellarg($pdfPath)
         . ' 2>&1';
    $output = []; $rc = 0;
    exec($cmd, $output, $rc);
    if ($rc !== 0) {
        return ['ok' => false, 'error' => "Ghostscript exit $rc: " . implode("\n", $output)];
    }
    $pngs = glob($outDir . DIRECTORY_SEPARATOR . 'page-*.png') ?: [];
    sort($pngs);
    if (empty($pngs)) {
        return ['ok' => false, 'error' => 'Ghostscript produced no pages.'];
    }
    return ['ok' => true, 'pages' => $pngs];
}

function callOpenAIVision(string $apiKey, string $model, string $userPrompt, string $imagePath, float $temperature = 0.0): array {
    $bin = @file_get_contents($imagePath);
    if ($bin === false) return ['ok' => false, 'error' => "Could not read $imagePath"];
    $dataUri = 'data:image/png;base64,' . base64_encode($bin);

    $payload = json_encode([
        'model' => $model,
        'response_format' => ['type' => 'json_object'],
        'temperature' => $temperature,
        'messages' => [
            [
                'role' => 'system',
                'content' => 'You read scanned delivery order forms. Each form has a '
                          .  '"CLIENT #N" badge in the top-right (N is a positive integer). '
                          .  'Below the client info is a category-organized list of items, '
                          .  'each with a small square checkbox to its LEFT. '
                          .  ''
                          .  'A checkbox is MARKED if the printed square contains any '
                          .  'deliberate ink — including a check (✓), an X, a slash, a dot, '
                          .  'a scribble, or a solid fill. A fully blacked-out / filled-in '
                          .  'box IS marked, not unmarked. Faint printer artifacts or stray '
                          .  'pen lines that cross the page but do not land inside the box '
                          .  'are NOT marked. '
                          .  ''
                          .  'You MUST enumerate EVERY item on the form, in the exact order '
                          .  'and with the exact form_name strings provided in the user '
                          .  'message — do not skip any item, do not invent names, do not '
                          .  'merge or rename. Pair each item with a status: "checked", '
                          .  '"unchecked", or "unsure" (use "unsure" only when the mark is '
                          .  'genuinely ambiguous). Return ONLY valid JSON.',
            ],
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $userPrompt],
                    ['type' => 'image_url', 'image_url' => ['url' => $dataUri, 'detail' => 'high']],
                ],
            ],
        ],
    ]);

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 120,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false)  return ['ok' => false, 'error' => 'cURL: ' . $err];
    if ($code !== 200)    return ['ok' => false, 'error' => "OpenAI HTTP $code: " . substr((string)$resp, 0, 400)];
    $body = json_decode($resp, true);
    $text = $body['choices'][0]['message']['content'] ?? '';
    $json = json_decode($text, true);
    if (!is_array($json)) return ['ok' => false, 'error' => 'Bad JSON from model: ' . substr($text, 0, 200)];
    return ['ok' => true, 'data' => $json];
}

function rmrf(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . DIRECTORY_SEPARATOR . $f;
        is_dir($p) ? rmrf($p) : @unlink($p);
    }
    @rmdir($dir);
}

// ── POST: process the uploaded PDF ─────────────────────────────────────────

$results = null; // per-page outcome rows for the summary
$fatal   = null; // hard pre-flight error
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $apiKey = trim((string)setting('openai_api_key', ''));
    $model  = trim((string)setting('openai_model', 'gpt-4o'));
    if ($model === '' || stripos($model, 'mini') !== false) {
        // gpt-4o-mini's vision is noticeably worse at reading hand checkboxes;
        // override the default chat model with full gpt-4o for this flow.
        $model = 'gpt-4o';
    }
    if ($apiKey === '') {
        $fatal = 'OpenAI API key is not configured. Set it on the Settings page first.';
    }

    $gs = $fatal === null ? findGhostscript() : null;
    if ($fatal === null && $gs === null) {
        $fatal = 'Ghostscript was not found. Install it or set the ghostscript_bin setting to its absolute path.';
    }

    if ($fatal === null) {
        if (!isset($_FILES['pdf']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
            $fatal = 'No PDF uploaded, or upload failed.';
        } else {
            $tmp  = $_FILES['pdf']['tmp_name'];
            $name = (string)$_FILES['pdf']['name'];
            if (strtolower((string)pathinfo($name, PATHINFO_EXTENSION)) !== 'pdf') {
                $fatal = 'Only .pdf files are accepted.';
            } else {
                // Sniff first bytes — a PDF starts with "%PDF-".
                $head = (string)@file_get_contents($tmp, false, null, 0, 5);
                if ($head !== '%PDF-') $fatal = 'File does not look like a PDF.';
            }
        }
    }

    if ($fatal === null) {
        $work = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fs_dlmenus_' . bin2hex(random_bytes(6));
        @mkdir($work, 0700, true);
        $pdfStored = $work . DIRECTORY_SEPARATOR . 'input.pdf';
        if (!@move_uploaded_file($_FILES['pdf']['tmp_name'], $pdfStored)) {
            $fatal = 'Could not stage the uploaded PDF.';
        }

        $pages = [];
        if ($fatal === null) {
            $r = rasterizePdf($gs, $pdfStored, $work . DIRECTORY_SEPARATOR . 'pages');
            if (!$r['ok']) $fatal = $r['error']; else $pages = $r['pages'];
        }

        // Today's flat menu — same set print_menus.php put on the forms.
        $flatMenu = $fatal === null ? deliveryMenuFlat($db, $pdb) : [];
        $byFormName = [];
        $menuByCat  = [];
        foreach ($flatMenu as $row) {
            $byFormName[strtolower($row['form_name'])] = $row;
            $menuByCat[$row['category']][] = $row['form_name'];
        }
        $menuPrompt = '';
        foreach ($menuByCat as $cat => $names) {
            $menuPrompt .= $cat . ":\n";
            foreach ($names as $n) $menuPrompt .= "  - " . $n . "\n";
        }
        // Pass A: flat enumeration in printed order. Good baseline; the
        // weak spot is items in the middle of a long list, where attention
        // tends to thin out (~30+ items per page is enough to trigger this
        // on gpt-4o).
        $userPromptA =
            "Today's menu — these are the EXACT strings printed on the form, in printed "
          . "order. You must return one entry per item, using these strings verbatim:\n\n"
          . $menuPrompt . "\n"
          . "Return JSON of the form:\n"
          . '{"client_id": <integer N from the CLIENT #N badge>,'
          . ' "items": [{"form_name": "<exact string from above>", "status": "checked|unchecked|unsure"}, …]}.'
          . "\n\n"
          . "Rules:\n"
          . "  - Include EVERY menu item from the list above, in order, even if unchecked.\n"
          . "  - status is \"checked\" if the printed square has ANY deliberate ink in it\n"
          . "    (check, X, slash, dot, scribble, solid fill — all count).\n"
          . "  - status is \"unchecked\" if the printed square is empty.\n"
          . "  - status is \"unsure\" ONLY if the mark is genuinely ambiguous (e.g. a stray\n"
          . "    pen line that may or may not land inside the box).\n"
          . "  - On the form, each item name is followed by a parenthesized Qty/Weight,\n"
          . "    e.g. \"(2 each)\" or \"(1.5 lb)\", possibly hand-corrected. That suffix is\n"
          . "    NOT part of the item name — ignore it and use the strings above verbatim.\n"
          . "  - Do not invent items, rename items, or skip items.";

        // Pass B: category-by-category with an explicit per-category item
        // count. Forces the model to attend to each category as a discrete
        // unit instead of one long list — fixes the "missed Frozen Items"
        // failure mode where mid-list categories get glossed over. Combined
        // with a non-zero temperature, this gives the second pass a
        // genuinely different perspective than pass A so failures decorrelate.
        $menuPromptCounted = '';
        foreach ($menuByCat as $cat => $names) {
            $n = count($names);
            $menuPromptCounted .= sprintf("%s (%d item%s):\n", $cat, $n, $n === 1 ? '' : 's');
            foreach ($names as $name) $menuPromptCounted .= "  - " . $name . "\n";
        }
        $userPromptB =
            "You are inspecting a scanned delivery order form, category by category. "
          . "For EACH category below, examine EVERY listed item — do not skip any "
          . "category, and do not skip any item within a category. The category "
          . "headers (DAIRY, DRY GOODS, FROZEN ITEMS, PRODUCE, OTHER ITEMS, etc.) "
          . "appear as bold labels on the form. Each item has a small printed "
          . "checkbox to its LEFT.\n\n"
          . "Categories and items (exact strings, exact counts):\n\n"
          . $menuPromptCounted . "\n"
          . "Return JSON of the form:\n"
          . '{"client_id": <integer N from the CLIENT #N badge>,'
          . ' "items": [{"form_name": "<exact string>", "status": "checked|unchecked|unsure"}, …]}.'
          . "\n\n"
          . "Rules:\n"
          . "  - Your items array MUST contain exactly the listed item count for each\n"
          . "    category (totals shown above). Count your output before returning.\n"
          . "  - status is \"checked\" if the box contains ANY deliberate ink (check, X,\n"
          . "    slash, dot, scribble, fully blacked-out fill).\n"
          . "  - status is \"unchecked\" only when the box is clearly empty.\n"
          . "  - status is \"unsure\" when the mark is genuinely ambiguous.\n"
          . "  - Each printed item name ends with a parenthesized Qty/Weight, e.g.\n"
          . "    \"(2 each)\" or \"(1.5 lb)\", possibly hand-corrected. Ignore that suffix —\n"
          . "    it is not part of the item name.\n"
          . "  - Use the form_name strings verbatim. Do not invent or rename.";

        $passConfigs = [
            ['prompt' => $userPromptA, 'temperature' => 0.0],
            ['prompt' => $userPromptB, 'temperature' => 0.3],
        ];

        $results = [];
        if ($fatal === null) {
            // Local helper: parse one AI response into a [form_name => status]
            // map. Tolerates the legacy bare-string response shape (treats
            // every entry as "checked") so an out-of-sync prompt still works.
            $parseAiItems = function ($aiItems) {
                if (!is_array($aiItems)) return [];
                $map = [];
                foreach ($aiItems as $entry) {
                    if (is_string($entry)) {
                        $map[strtolower(trim($entry))] = 'checked';
                    } elseif (is_array($entry) && isset($entry['form_name'])) {
                        $name = strtolower(trim((string)$entry['form_name']));
                        $st   = strtolower(trim((string)($entry['status'] ?? 'checked')));
                        if (!in_array($st, ['checked', 'unchecked', 'unsure'], true)) {
                            $st = 'unchecked';
                        }
                        $map[$name] = $st;
                    }
                }
                return $map;
            };

            foreach ($pages as $idx => $imagePath) {
                $pageNum = $idx + 1;
                $row = ['page' => $pageNum, 'status' => '', 'detail' => '', 'client_id' => null, 'items' => []];

                // Two-pass union strategy with INDEPENDENT passes. Vision
                // models miss roughly 10–15% of hand-marked checkboxes per
                // pass, but if both passes use the same prompt + image +
                // temperature 0 the misses are highly correlated and the
                // union barely helps. We instead vary BOTH the framing
                // (flat list vs. category-by-category with explicit
                // counts) AND the temperature (0.0 vs. 0.3), so the two
                // passes attend to the image differently. Disagreements
                // are treated as "unsure" so staff can verify against the
                // paper before items leave the pantry.
                $passes = [];
                $clientId = 0;
                $firstError = null;
                foreach ($passConfigs as $cfg) {
                    $r = callOpenAIVision($apiKey, $model, $cfg['prompt'], $imagePath, $cfg['temperature']);
                    if (!$r['ok']) {
                        // Tolerate one transient failure; require at least one good pass.
                        if ($firstError === null) $firstError = $r['error'];
                        continue;
                    }
                    $data = $r['data'];
                    if ($clientId === 0) $clientId = (int)($data['client_id'] ?? 0);
                    $passes[] = $parseAiItems($data['items'] ?? []);
                }
                if (empty($passes)) {
                    $row['status'] = 'error';
                    $row['detail'] = 'OpenAI: ' . ($firstError ?? 'unknown error');
                    $results[] = $row;
                    continue;
                }
                $row['client_id'] = $clientId;

                if ($clientId <= 0) { $row['status'] = 'error'; $row['detail'] = 'No CLIENT #N detected.'; $results[] = $row; continue; }

                $cStmt = $db->prepare("SELECT id, grp, adults, children, delivered_at FROM delivery_clients WHERE id = ?");
                $cStmt->execute([$clientId]);
                $client = $cStmt->fetch();
                if (!$client) { $row['status'] = 'error'; $row['detail'] = "Client #$clientId not found."; $results[] = $row; continue; }
                if ($client['delivered_at']) {
                    $row['status'] = 'skipped';
                    $row['detail'] = "Client #$clientId already has a delivery this round — reset them first to re-process.";
                    $results[] = $row;
                    continue;
                }

                // Combine the two pass maps. For each menu item:
                //   - any "checked" vote     -> include
                //   - any "unsure" vote      -> include (flagged for verification)
                //   - passes disagree        -> include, flag for verification
                //                              (a missed-checkbox pass shouldn't
                //                              negate a positive sighting)
                //   - all passes "unchecked" -> skip
                $itemKeys = []; $sizesByKey = []; $matched = []; $unsure = [];
                foreach ($flatMenu as $menuRow) {
                    $key = strtolower($menuRow['form_name']);
                    $votes = [];
                    foreach ($passes as $p) {
                        if (isset($p[$key])) $votes[] = $p[$key];
                    }
                    if (empty($votes)) continue;

                    $anyChecked   = in_array('checked',   $votes, true);
                    $anyUnsure    = in_array('unsure',    $votes, true);
                    $anyUnchecked = in_array('unchecked', $votes, true);

                    $include = $anyChecked || $anyUnsure;
                    if (!$include) continue;
                    // Flag when the two passes disagreed (or any pass said unsure)
                    // so staff can spot-check before the items leave the pantry.
                    $needsReview = $anyUnsure || ($anyChecked && $anyUnchecked);

                    $itemKeys[] = $menuRow['key'];
                    if ($menuRow['size'] !== null) $sizesByKey[$menuRow['key']] = $menuRow['size'];
                    $matched[] = $menuRow['form_name'];
                    if ($needsReview) $unsure[] = $menuRow['form_name'];
                }
                $row['items']  = $matched;
                $row['unsure'] = $unsure;

                if (empty($itemKeys)) {
                    $row['status'] = 'error';
                    $row['detail'] = 'No items checked on this page (per AI read).';
                    $results[] = $row;
                    continue;
                }

                $save = persistDeliveryOrder(
                    $db, $pdb,
                    (int)$client['id'], (string)$client['grp'],
                    (int)$client['adults'], (int)$client['children'],
                    $itemKeys, $sizesByKey
                );
                if (!$save['ok']) {
                    $row['status'] = 'error';
                    $row['detail'] = $save['error'];
                    $results[] = $row;
                    continue;
                }
                $row['status']   = 'ok';
                $row['detail']   = sprintf('Order #%d · %d items written', $save['order_id'], count($save['lines']));
                $row['matched']  = $matched;
                $row['order_id'] = $save['order_id'];
                $results[] = $row;
            }
        }
        rmrf($work);
    }
}

renderHead('Upload Completed Delivery Menus');
renderNav('delivery');
?>
<div class="container">

  <div style="display:flex; align-items:center; gap:12px; margin-bottom:14px;">
    <a href="client/" class="btn btn-secondary" style="text-decoration:none;">← Back to Clients</a>
  </div>

  <?php if ($fatal !== null): ?>
    <div class="banner error" style="margin-bottom:16px;">⚠ <?= htmlspecialchars($fatal) ?></div>
  <?php endif; ?>

  <?php if ($results !== null && $fatal === null): ?>
    <div class="card">
      <h2>Processed <?= count($results) ?> page<?= count($results) === 1 ? '' : 's' ?></h2>
      <table class="data">
        <thead>
          <tr><th>Page</th><th>Client</th><th>Status</th><th>Detail</th></tr>
        </thead>
        <tbody>
          <?php foreach ($results as $r): ?>
            <tr>
              <td><?= (int)$r['page'] ?></td>
              <td><?= $r['client_id'] ? '#' . (int)$r['client_id'] : '—' ?></td>
              <td>
                <?php
                  $s = $r['status'];
                  $color = ['ok' => '#276437', 'skipped' => '#806000', 'error' => '#a02020'][$s] ?? '#555';
                ?>
                <strong style="color:<?= $color ?>;"><?= htmlspecialchars(strtoupper($s)) ?></strong>
              </td>
              <td>
                <?= htmlspecialchars($r['detail']) ?>
                <?php if (!empty($r['items'])): ?>
                  <div style="font-size:.8rem; color:#555; margin-top:4px;">
                    <?= htmlspecialchars(implode(', ', $r['items'])) ?>
                  </div>
                <?php endif; ?>
                <?php if (!empty($r['unsure'])): ?>
                  <div style="font-size:.78rem; color:#806000; margin-top:4px;">
                    ⚠ Ambiguous marks — verify against the paper form:
                    <strong><?= htmlspecialchars(implode(', ', $r['unsure'])) ?></strong>
                  </div>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <p style="margin-top:14px; font-size:.85rem; color:#666;">
        Successful pages were written as delivery orders and deducted from inventory.
        They appear in the orders report tagged <strong>Delivery</strong>.
      </p>
    </div>
  <?php endif; ?>

  <div class="card">
    <h2>Upload a scanned PDF</h2>
    <p style="color:#555; font-size:.9rem;">
      The PDF should contain one printed delivery-menu page per client, with
      checkboxes filled by hand in black ink. Each page must still show its
      "CLIENT #N" badge — that's how the AI identifies whose order it is.
    </p>
    <form method="post" enctype="multipart/form-data" action="process_upload.php">
      <div class="row" style="align-items:end;">
        <div style="flex:2 1 300px;">
          <label for="pdf">PDF file</label>
          <input type="file" id="pdf" name="pdf" accept="application/pdf,.pdf" required>
        </div>
        <div>
          <button type="submit" class="btn btn-primary">📤 Upload &amp; Process</button>
        </div>
      </div>
    </form>
  </div>
</div>
<?php renderFoot(); ?>
