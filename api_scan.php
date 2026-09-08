<?php
// Record a single scan, look up a barcode, or remove a scan from the open order.
// POST { action: 'lookup', barcode }                          -> resolve only, do not insert.
// POST { action: 'record', barcode, weight_lbs?, quantity? }  -> resolve + insert; returns the new scan_id.
//        A store-printed item label (prefix 2) records as one package, like any other packaged item.
// POST { action: 'delete', scan_id }                          -> remove a scan, only if it belongs to the current open order.
// POST { action: 'search', q, scope? }                        -> partial name match against the lookup tables,
//        best matches first: exact name, then names starting with the query, then word-boundary hits.
//        scope='weighed' restricts to produce codes sold by the pound.

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/auth.php';
requireAllowedIPAPI();
require_once __DIR__ . '/lookup.php';

$in = jsonIn();
$action = $in['action'] ?? '';

if ($action === 'search') {
    // Type-ahead for the scan page: partial name match so an operator can add
    // an item by typing its name instead of scanning. Matches generic names in
    // both lookup tables (plus UPC brand names) and dedupes by generic name —
    // when several codes share a name, the produce PLU wins over a cached UPC
    // so the recorded barcode is the pantry's own code where one exists.
    // scope='weighed' narrows this to produce_lookup rows priced by the pound.
    $q = trim((string)($in['q'] ?? ''));
    if (strlen($q) < 2) jsonOut(['ok' => true, 'matches' => [], 'total' => 0]);
    $like = '%' . strtr($q, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
    if (($in['scope'] ?? '') === 'weighed') {
        // The scan page's PLU window: a scale reading is already held, so the
        // only codes that can identify the item are produce PLUs sold by the
        // pound. Cached UPCs and count-based produce are excluded outright —
        // picking one would throw the weight away.
        $st = getDB()->prepare(
            "SELECT code, generic_name, '' AS brand_name, 'produce' AS src
               FROM produce_lookup
              WHERE unit = 'lb' AND generic_name LIKE ? ESCAPE '\\'"
        );
        $st->execute([$like]);
    } else {
        $st = getDB()->prepare(
            "SELECT code, generic_name, '' AS brand_name, 'produce' AS src
               FROM produce_lookup
              WHERE generic_name LIKE ? ESCAPE '\\'
             UNION ALL
             SELECT upc AS code, generic_name, COALESCE(brand_name, '') AS brand_name, 'upc' AS src
               FROM upc_lookup
              WHERE generic_name LIKE ? ESCAPE '\\' OR brand_name LIKE ? ESCAPE '\\'"
        );
        $st->execute([$like, $like, $like]);
    }
    // Rank tier for the type-ahead: a short, common query like "chicken" turns
    // up dozens of names that merely contain it, and a plain alphabetical cut
    // to eight ("Bouillon Powder" … "Canned Dog Food") drops the item actually
    // named Chicken off the bottom. Score first, slice after.
    $ql = mb_strtolower($q);
    $qLen = strlen($ql);
    $matches = [];
    foreach ($st->fetchAll() as $row) {
        $key = mb_strtolower($row['generic_name']);
        if (isset($matches[$key])) continue;  // produce rows come first, so they win
        if ($key === $ql) {
            $rank = 0;                                    // "Chicken"
        } elseif (strncmp($key, $ql, $qLen) === 0) {
            $rank = 1;                                    // "Chicken Noodle Soup"
        } elseif (preg_match('/\b' . preg_quote($ql, '/') . '/u', $key)) {
            $rank = 2;                                    // "Canned Chicken", "Butter Chicken"
        } elseif (mb_strpos($key, $ql) !== false) {
            $rank = 3;                                    // mid-word hit
        } else {
            $rank = 4;                                    // matched on brand name only
        }
        $matches[$key] = [
            'code'  => $row['code'],
            'name'  => $row['generic_name'],
            'brand' => $row['brand_name'],
            'src'   => $row['src'],
            'rank'  => $rank,
            'key'   => $key,
        ];
    }
    // Best tier first, alphabetical inside a tier. The key is compared
    // explicitly rather than leaning on sort stability, which the host's PHP
    // is too old to guarantee.
    usort($matches, function ($a, $b) {
        if ($a['rank'] !== $b['rank']) return $a['rank'] - $b['rank'];
        return strcmp($a['key'], $b['key']);
    });
    $top = [];
    foreach (array_slice($matches, 0, 8) as $m) {
        unset($m['rank'], $m['key']);   // ranking is internal; the page reads name/brand/code
        $top[] = $m;
    }
    jsonOut([
        'ok'      => true,
        'matches' => $top,
        'total'   => count($matches),
    ]);
}

if ($action === 'delete') {
    $scanId = (int)($in['scan_id'] ?? 0);
    if ($scanId <= 0) jsonOut(['ok' => false, 'error' => 'Missing scan_id'], 400);

    // activeScanOrder(), so a station assisting a team-scanned order can pull a
    // mis-scan back out of it — including one its teammate entered.
    $open = activeScanOrder();
    if (!$open) jsonOut(['ok' => false, 'error' => 'No open order'], 409);

    $db = getDB();
    // Scope the delete to the current open order so a stray scan_id can't
    // mutate historical, already-closed orders.
    $del = $db->prepare("DELETE FROM scans WHERE id = ? AND order_id = ?");
    $del->execute([$scanId, $open['id']]);
    if ($del->rowCount() === 0) {
        jsonOut(['ok' => false, 'error' => 'Scan not found in current order'], 404);
    }

    $c = $db->prepare("SELECT COUNT(*) FROM scans WHERE order_id = ?");
    $c->execute([$open['id']]);
    jsonOut(['ok' => true, 'scan_count' => (int)$c->fetchColumn()]);
}

$barcode = trim((string)($in['barcode'] ?? ''));
if ($barcode === '') jsonOut(['ok' => false, 'error' => 'Missing barcode'], 400);

// Manual-mapping rescue: when the scanner page couldn't resolve a UPC via
// OFF and the operator filled in the "Unknown UPC" modal with a generic
// name, write the mapping to upc_lookup BEFORE resolving so the resolver
// hits the cache instead of re-running OFF + OpenAI.
//
// Two shapes arrive here. A UPC nobody has ever named is inserted. A UPC the
// station recorded under the placeholder while Ignore Unknown Items was on is
// overwritten instead, and its scans come with it -- see below.
$manualGeneric = trim((string)($in['generic_name'] ?? ''));
if ($action === 'record' && $manualGeneric !== '' && classifyBarcode($barcode) === 'packaged') {
    $manualBrand = trim((string)($in['brand_name'] ?? ''));
    // A store-printed item label is cached under its six-digit item
    // key, never the whole label: the trailing digits are this one package's
    // price, so keying the name to them would name a single package and leave
    // the next one unknown.
    $cacheKey = lookupCacheKey($barcode);
    $db = getDB();

    $cur = $db->prepare('SELECT generic_name FROM upc_lookup WHERE upc = ?');
    $cur->execute([$cacheKey]);
    $prev = $cur->fetchColumn();

    if ($prev === UNIDENTIFIED_NAME) {
        // The placeholder is the one existing name the station is allowed to
        // overwrite. It was never a decision -- only a marker left where a
        // decision hadn't been made yet -- whereas any other name in this
        // column WAS one, and changing that belongs on the Lookup Tables page,
        // not on a counter mid-order. The WHERE clause repeats the check so a
        // teammate who named the same UPC a second earlier still wins.
        $db->prepare(
            "UPDATE upc_lookup
                SET generic_name = ?, brand_name = ?, source = 'manual', updated_at = ?
              WHERE upc = ? AND generic_name = ?"
        )->execute([$manualGeneric, $manualBrand, now(), $cacheKey, UNIDENTIFIED_NAME]);

        // Carry this UPC's own history onto the name it just got, so the demand
        // the pantry already recorded lands on the real item instead of being
        // stranded under the placeholder. Scoped to the barcode, never to the
        // name: every other UPC still waiting to be named is sitting under the
        // same placeholder, and a rename by name would sweep all of them onto
        // whatever was typed here. That is also why the Consolidate Names tool
        // cannot do this job -- it merges by name and nothing else.
        //
        // Bounded work: one indexed UPDATE over the scan rows of a single item.
        renameUPCScans($cacheKey, $manualGeneric, UNIDENTIFIED_NAME);

        // The `Unidentified` inventory row is deliberately left alone. Its
        // count is a hand-entered figure standing for a shelf of mixed unnamed
        // goods, and no share of it can honestly be attributed to this one UPC.
        // It empties out on the Inventory page as the names get assigned.
    } elseif ($prev === false) {
        $db->prepare(
            "INSERT INTO upc_lookup (upc, brand_name, generic_name, source, created_at)
             VALUES (?, ?, ?, 'manual', ?)
             ON CONFLICT(upc) DO NOTHING"
        )->execute([$cacheKey, $manualBrand, $manualGeneric, now()]);
    }
    // It has a name now, so it is no longer an unidentified UPC. Matters when
    // Ignore Unknown Items gets switched back on later: without this the stale
    // miss would keep the station skipping an item it can actually name.
    forgetUnidentifiedUPC($cacheKey);
}

$res = lookupBarcode($barcode);
if ($action === 'lookup') jsonOut($res);

if ($action !== 'record') jsonOut(['ok' => false, 'error' => 'Unknown action'], 400);

if (!$res['ok']) jsonOut($res, 422);

// activeScanOrder(), so a helper station records into the order it is
// assisting instead of being told to start one of its own.
$open = activeScanOrder();
if (!$open) jsonOut(['ok' => false, 'error' => 'No order is open. Tap START ORDER first.'], 409);

$db = getDB();
$weight = null;
$qty    = 1;
if ($res['kind'] === 'produce' && !empty($res['needs_weight'])) {
    $weight = isset($in['weight_lbs']) ? (float)$in['weight_lbs'] : 0;
    if ($weight <= 0) jsonOut(['ok' => false, 'error' => 'Weight required for ' . $res['generic_name']], 400);
} else {
    $qty = isset($in['quantity']) ? max(1, (int)$in['quantity']) : 1;
}

$station = currentStationId();
$ins = $db->prepare(
    "INSERT INTO scans (order_id, barcode, generic_name, kind, quantity, weight_lbs, scanned_at, station)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
);
$ins->execute([
    $open['id'], $barcode, $res['generic_name'], $res['kind'],
    $qty, $weight, now(), $station
]);
$scanId = (int)$db->lastInsertId();

// Echo the running totals back so the scanner UI can update its strip.
$c = $db->prepare("SELECT COUNT(*) FROM scans WHERE order_id=?");
$c->execute([$open['id']]);
$scanCount = (int)$c->fetchColumn();

jsonOut([
    'ok' => true,
    'order_id' => (int)$open['id'],
    'scan_count' => $scanCount,
    'warning' => $res['ai_error'] ?? null,
    'item' => [
        'id' => $scanId,
        'generic_name' => $res['generic_name'],
        'kind' => $res['kind'],
        'quantity' => $qty,
        'weight_lbs' => $weight,
        'barcode' => $barcode,
        // So the row renders with its station badge right away, without
        // waiting for the next sync poll to redraw the table.
        'station' => $station,
        'station_label' => orderStations((int)$open['id'])[$station] ?? '',
        'brand_name' => $res['brand_name'] ?? null,
        'source' => $res['source'] ?? null,
        // Present only for a store-printed label: the six-digit item key the
        // name is cached under, so the station can say what it actually saved.
        'store_key' => $res['store_key'] ?? null,
        // True when this recorded under the placeholder name because Ignore
        // Unknown Items is on, so the station can say so in the banner.
        'unidentified' => !empty($res['unidentified']),
    ],
]);
