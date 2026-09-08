<?php
// Barcode -> generic-name resolution.
//
// Flow:
//   1. Produce code (4-5 digits, or 12-digit starting with 4):
//        look up in produce_lookup; return generic_name + needs_weight=true
//   2. Packaged UPC (any other 8-14 digit code):
//        a. Try upc_lookup cache — under the six-digit item key when the code
//           is a store-printed item label (prefix 2), so all of an
//           item's packages share one row. A miss there stops at (a): no
//           retailer's private item number is in OFF.
//        b. With Ignore Unknown Items on, try the upc_unidentified cache — a
//           UPC OFF has already disowned answers without a network call.
//        c. Cache miss -> fetch OFF API. If no product, mark unknown (and
//           remember the miss, when that switch is on).
//        d. With a branded name in hand, ask OpenAI for a generic mapping.
//        e. Insert into upc_lookup and return. The insert tolerates a
//           concurrent writer (ON CONFLICT DO NOTHING) — two stations can
//           scan the same new UPC at once.

require_once __DIR__ . '/db.php';

// ── Store-printed item labels (prefix 2) ────────────────────────
// A retail scale prints its own UPC-A label for anything sold by the pound or
// at a per-package price — deli meat, cheese, bakery, cut fruit, meat trays.
// Prefix 2 is reserved for exactly that:
//
//     2 IIIII VVVVV          UPC-A, 12 digits
//     │ │     └──── this package's price (or weight), plus a check digit
//     │ └────────── item code, assigned by the store
//     └──────────── prefix 2, in-store variable measure
//
// The same item also turns up as a 13-digit EAN-13 in the restricted 22 range,
// which carries the identical six-digit item field one position further along:
//
//     2 2IIIII VVVVV         EAN-13, 13 digits — item field starts at digit 2
//
// Both spellings must land on one key, or the same tray of beef is named twice
// — which is exactly what upc_lookup held before this: 2234939002004 beside
// 234939, 2271400002002 beside 271400, both pairs already agreeing on a name.
//
// Only the prefix and item code identify the *product*. The trailing digits
// hold this one package's measurement, and which of price or weight they hold
// is a per-retailer, per-scale question with no reliable flag in the barcode
// to answer it — US grocers overwhelmingly embed the price. OpenPantry does not
// track dollars, so those digits are read past entirely: a prefix-2 label
// resolves to a generic name and records as one package, exactly like every
// other packaged item. Anything that needs pounds gets weighed on the pantry
// scale, the same as produce.
//
// The lookup key is the leading six digits, which cannot collide with a real
// barcode (those are 8, 12, 13 or 14 digits long). Every package of an item
// shares that one row, so it is named once — caching whole labels would fill
// upc_lookup with single-use rows that never hit again, and would send every
// unit of the same item back through the Identify window.
const STORE_KEY_LEN = 6;

// UNIDENTIFIED_NAME — the placeholder generic name this file writes while
// Settings -> Ignore Unknown Items is on. Defined in db.php, beside the
// unidentified-UPC helpers and for the same reason: the Order Report and the
// reorder mailer have to know the name in order to leave it out of an order,
// and neither has any business pulling in the OFF/OpenAI code that lives here.

// What a volunteer is told when a recalled item reaches the scanner. Defined
// here, beside the check that raises it, so the station banner, the API error
// and anything else that surfaces a refused recall all say the same sentence.
const RECALL_MESSAGE = 'Remove this item from the cart immediately - it has been recalled!';

// The six-digit item key for a store-printed label, or null when the code is
// not one.
function storeItemKey(string $code): ?string {
    $code = trim($code);
    if ($code === '' || !ctype_digit($code)) return null;

    // Six digits is the key itself, not a label — what the name-search
    // type-ahead and the Lookup Tables page hand back for one of these items.
    if (strlen($code) === STORE_KEY_LEN) return $code[0] === '2' ? $code : null;

    if (strlen($code) === 13) {
        // Scanners hand UPC-A back as 12 digits, but some report it as EAN-13
        // with a leading zero. Strip that so both spellings of one label land
        // on the same key instead of naming the item twice.
        if ($code[0] === '0') {
            $code = substr($code, 1);
        } elseif ($code[0] === '2' && $code[1] === '2') {
            // Native in-store EAN-13. The item field sits one position in, and
            // is the same six digits the UPC-A form leads with — so the key is
            // read from digit 2 rather than digit 1. Requiring a '2' there is
            // what keeps every key starting with '2', the invariant that
            // isStoreItemKey() and storeLabelSql() below both test on. A 13-digit
            // code in the rest of the restricted range (23-29) has its item
            // field somewhere this can't know, so it caches at full length.
            return substr($code, 1, STORE_KEY_LEN);
        }
    }
    if (strlen($code) !== 12 || $code[0] !== '2') return null;

    return substr($code, 0, STORE_KEY_LEN);
}

// The upc_lookup key a code should be stored and read under: the six-digit
// item key for a store-printed label, the code itself for everything else.
function lookupCacheKey(string $code): string {
    return storeItemKey($code) ?? trim($code);
}

// True when this string is a bare store item key rather than a full label.
function isStoreItemKey(string $code): bool {
    $code = trim($code);
    return strlen($code) === STORE_KEY_LEN && $code[0] === '2' && ctype_digit($code);
}

// SQL fragment: true when the named scans.barcode column holds a store-printed
// item label (or the six-digit key one was recorded under).
//
// Reports use it to keep deli meat out of the *produce* category. Scans
// recorded now are always kind='packaged', so the test only bites on rows
// written while the station read the embedded weight and filed them as
// kind='produce' — the schema's word for "measured in pounds", which is a
// different question from whether the item came out of the ground. Those rows
// are left as they were recorded; this keeps them counted as packaged.
function storeLabelSql(string $col): string {
    return "(($col LIKE '2%' AND length($col) IN (" . STORE_KEY_LEN . ", 12))"
         . " OR ($col LIKE '22%' AND length($col) = 13)"
         . " OR ($col LIKE '02%' AND length($col) = 13))";
}

function classifyBarcode(string $code): string {
    $code = trim($code);
    if ($code === '' || !ctype_digit($code)) return 'invalid';
    $len = strlen($code);
    // Produce PLU: 4 or 5 digits.
    if ($len === 4 || $len === 5) return 'produce';
    // Pantry-printed 12-digit produce label starting with 4.
    if ($len === 12 && $code[0] === '4') return 'produce';
    if ($len >= 8 && $len <= 14) return 'packaged';
    // Store item key. No scanner emits this — it comes from the name
    // search or Lookup Tables — but it resolves through the packaged path like
    // the labels it stands for. Checked last so it can never shadow a real
    // barcode length.
    if ($len === STORE_KEY_LEN && $code[0] === '2') return 'packaged';
    return 'invalid';
}

// Cache a UPC under the placeholder so the scan in front of the operator can
// be recorded. Only ever called with Ignore Unknown Items on. DO NOTHING on
// conflict for the same reason the other inserts here use it: two stations can
// reach this line for the same new UPC at once -- and, more importantly, a real
// name written in between must never be overwritten by a placeholder.
function nameUPCUnidentified(string $cacheKey, ?string $brand = null): void {
    getDB()->prepare(
        "INSERT INTO upc_lookup (upc, brand_name, generic_name, source, created_at)
         VALUES (?, ?, ?, 'unidentified', ?)
         ON CONFLICT(upc) DO NOTHING"
    )->execute([$cacheKey, $brand ?? '', UNIDENTIFIED_NAME, now()]);
    // It has a upc_lookup row now, so there is no miss left to remember.
    forgetUnidentifiedUPC($cacheKey);
}

// The answer a station gets for a UPC carrying the placeholder, switch on:
// ok=true, so it records exactly like any other packaged item. `unidentified`
// is what tells the page to say so in the banner rather than beeping.
function unidentifiedHit(string $code, ?string $matchedKey): array {
    $res = [
        'ok' => true, 'kind' => 'packaged', 'barcode' => $code,
        'generic_name' => UNIDENTIFIED_NAME, 'brand_name' => null,
        'source' => 'unidentified', 'needs_weight' => false,
        'unidentified' => true,
    ];
    if ($matchedKey !== null) $res['store_key'] = $matchedKey;
    return $res;
}

// Move one UPC's scan history onto a new name, and return how many rows moved.
//
// Scoped to the barcode on purpose. The placeholder name is shared by every
// UPC the pantry hasn't named yet, so renaming by *name* -- which is all the
// Merge Duplicate Items page can express -- would drag a shelf of unrelated
// donated goods onto whatever the operator just typed. Matching on the barcode
// is the only way to say "this item, not the pile it was sitting in", and
// $fromName pins it further so a rename can never touch scans that already
// carry a real name.
//
// A store-printed label is cached under its six-digit item key while the scans
// it named carry the whole label, so those are matched by prefix. All three
// spellings storeItemKey() folds into one key have to be listed, each pinned to
// its own length so a six-digit key can't claim a longer unrelated barcode that
// happens to start with the same digits:
//
//     234939          the key itself
//     234939 002004   12-digit UPC-A          -> LIKE '234939%',  length 12
//    0234939 002004   13-digit, leading zero  -> LIKE '0234939%', length 13
//    2 234939 002004  13-digit native EAN-13  -> LIKE '2234939%', length 13
//
// That last one is the form a retail scale actually prints, and it is the one
// an earlier copy of this rename (in lookup_admin.php's upc_edit) left out —
// which is why lookup_admin now calls this function instead of repeating it.
function renameUPCScans(string $cacheKey, string $newName, ?string $fromName = null): int {
    $isKey = isStoreItemKey($cacheKey);
    $where = $isKey
        ? "(barcode = :k
             OR (barcode LIKE :k12 AND length(barcode) = 12)
             OR (barcode LIKE :k13z AND length(barcode) = 13)
             OR (barcode LIKE :k13e AND length(barcode) = 13))"
        : "barcode = :k";
    $args = [':k' => $cacheKey, ':n' => $newName];
    if ($isKey) {
        $args[':k12']  = $cacheKey . '%';
        $args[':k13z'] = '0' . $cacheKey . '%';
        $args[':k13e'] = '2' . $cacheKey . '%';
    }
    if ($fromName !== null) {
        $where .= " AND generic_name = :from";
        $args[':from'] = $fromName;
    }
    $st = getDB()->prepare("UPDATE scans SET generic_name = :n WHERE $where");
    $st->execute($args);
    return $st->rowCount();
}

function lookupBarcode(string $code): array {
    $code = trim($code);
    $db = getDB();
    $kind = classifyBarcode($code);

    if ($kind === 'invalid') {
        return ['ok' => false, 'error' => 'Barcode is not a recognized format', 'barcode' => $code];
    }

    if ($kind === 'produce') {
        $stmt = $db->prepare('SELECT generic_name, unit FROM produce_lookup WHERE code = ?');
        $stmt->execute([$code]);
        $row = $stmt->fetch();
        if (!$row) {
            return [
                'ok' => false, 'kind' => 'produce', 'barcode' => $code,
                'error' => 'Unknown produce code. Add it under Lookup Tables.'
            ];
        }
        // The barcode is a PLU, but the inventory tracking style depends on
        // the unit, not the barcode shape. A produce code flipped to 'each'
        // in inventory (e.g. apples counted per-apple) must record as
        // kind='packaged' so the scans row carries a quantity, not a
        // weight. Otherwise the orders report renders the LBS column
        // (weight_lbs = NULL → "0 lb") and hides the QTY entirely.
        //
        // Mapping:
        //   produce_lookup.unit = 'lb'   → kind='produce'  (weighed)
        //   produce_lookup.unit = 'each' → kind='packaged' (counted)
        $isWeighed = ($row['unit'] === 'lb');
        return [
            'ok'           => true,
            'kind'         => $isWeighed ? 'produce' : 'packaged',
            'barcode'      => $code,
            'generic_name' => $row['generic_name'],
            'unit'         => $row['unit'],
            'needs_weight' => $isWeighed,
        ];
    }

    // packaged
    // Two reads, whole code first. Which half of a prefix-2 label identifies
    // the product is not actually universal: a retail scale varies the item
    // field and puts the price in the trailing digits, so its labels only ever
    // agree on the six-digit key — but a pantry printing its own sheet may have
    // done the reverse, pinning the item field and counting up in the trailing
    // digits, and those labels only agree in full — a whole sheet of unrelated
    // items then shares one key, and naming any of them names all of them.
    // Asking for the whole code first serves both layouts: a per-code row
    // wins wherever one exists, and everything without one falls through to the
    // shared item key, which is still what every real deli label needs.
    $storeKey = storeItemKey($code);

    // Read once, up here, because the switch now decides three separate things
    // below: whether a cached placeholder answers or re-opens the Identify
    // window, whether an unnameable store label records as unidentified, and
    // whether an Open Food Facts miss does.
    $ignoreUnknown = (setting('ignore_unknown_items', '0') ?? '0') === '1';

    $stmt = $db->prepare('SELECT brand_name, generic_name, recalled FROM upc_lookup WHERE upc = ?');
    $stmt->execute([$code]);
    $row = $stmt->fetch();
    // Set only when the name came from the item key, so the station reports the
    // key it actually resolved through rather than one it merely could have.
    $matchedKey = null;
    if (!$row && $storeKey !== null) {
        $stmt->execute([$storeKey]);
        $row = $stmt->fetch();
        if ($row) $matchedKey = $storeKey;
    }
    if ($row) {
        // Recalled: the mapping is known and correct, and that is exactly why
        // this item must not be handed out. Refused here rather than in the
        // scan page so a station left open since before the box was ticked
        // still can't write the row, and so both spellings of a store-printed
        // label are caught by the one check. ok=false keeps api_scan.php's
        // existing guard doing the work — nothing is inserted — while
        // `recalled` tells the station to sound the alarm instead of asking
        // anyone to name a UPC that already has a name.
        if ((int)$row['recalled'] === 1) {
            $res = [
                'ok' => false, 'kind' => 'packaged', 'barcode' => $code,
                'recalled' => true,
                'generic_name' => $row['generic_name'], 'brand_name' => $row['brand_name'],
                'source' => 'recall',
                'error' => RECALL_MESSAGE,
            ];
            if ($matchedKey !== null) $res['store_key'] = $matchedKey;
            return $res;
        }
        // Placeholder left by a scan taken while the switch was on. With the
        // switch now off, this is the moment the pantry asked for: the item is
        // on the counter in front of someone who can name it, so the cache is
        // refused and the station opens the Identify window. ok=false puts it
        // on the same path an unknown UPC already takes -- including the guard
        // that keeps a helper station out of naming -- while `unidentified`
        // says this one already has scan history waiting to move with the name.
        if ($row['generic_name'] === UNIDENTIFIED_NAME && !$ignoreUnknown) {
            $res = [
                'ok' => false, 'kind' => 'packaged', 'barcode' => $code,
                'unidentified' => true,
                'brand_name' => $row['brand_name'],
                'source' => 'unidentified',
                'error' => 'Scanned before as Unidentified. Name it now.',
            ];
            if ($matchedKey !== null) $res['store_key'] = $matchedKey;
            return $res;
        }
        $res = [
            'ok' => true, 'kind' => 'packaged', 'barcode' => $code,
            'generic_name' => $row['generic_name'], 'brand_name' => $row['brand_name'],
            'source' => 'cache', 'needs_weight' => false,
        ];
        if ($matchedKey !== null) $res['store_key'] = $matchedKey;
        // Switch on and the cached name IS the placeholder: flag it, so the
        // station's banner reads the same whether this is the first package of
        // an unlisted case or the fortieth.
        if ($row['generic_name'] === UNIDENTIFIED_NAME) $res['unidentified'] = true;
        return $res;
    }

    // Store-printed labels stop here. Open Food Facts cannot know this code —
    // the item number is private to the retailer that printed it — so the
    // six-second request and the OpenAI call after it are guaranteed waste.
    // Go straight to "operator, name this item".
    //
    // upc_unidentified is skipped for the same reason: it exists to save a
    // network round trip, and there isn't one to save. Nothing about the switch
    // changes at the station — an unnamed label still returns ok=false with
    // kind='packaged', which is what Ignore Unknown Items acts on.
    if ($storeKey !== null) {
        // Switch on: nothing here can name the label and nobody is going to be
        // asked to, so record it under the placeholder against the item key --
        // one row covering every package of it, the same key a typed name
        // would have been saved under.
        if ($ignoreUnknown) {
            nameUPCUnidentified($storeKey);
            return unidentifiedHit($code, $storeKey);
        }
        return [
            'ok' => false, 'kind' => 'packaged', 'barcode' => $code,
            'error' => 'Unknown store label. Name item ' . $storeKey
                     . ' to cover every package of it.',
            'source' => 'store-label', 'store_key' => $storeKey,
        ];
    }

    // A miss remembered by an older build, which recorded nothing for these and
    // kept them out of upc_lookup entirely. Promote it to a real placeholder
    // row: the item records from here on, and every later scan of it is answered
    // by the upc_lookup read above without ever reaching this line. Nothing
    // writes upc_unidentified any more, so the table drains as its UPCs come
    // back across the scanner.
    //
    // Deliberately after that read, never before it: a UPC parked in this cache
    // may have been given a real name since, and that mapping has to win.
    if ($ignoreUnknown && upcIsUnidentified($code)) {
        nameUPCUnidentified($code);
        return unidentifiedHit($code, null);
    }

    // Cache miss: ask OFF for a product name.
    $off = fetchFromOFF($code);
    $brandName = $off['name'] ?? null;
    if (!$brandName) {
        // Open Food Facts has never heard of it, and the pantry has said it
        // doesn't stop the line for that. Record it under the placeholder
        // rather than dropping the item on the floor: the case still crossed
        // the counter, and the UPC can be named later -- at the station with
        // the switch off, or on the Lookup Tables page.
        //
        // Only while the switch is on. Placeholder rows written with it off
        // would be built out of UPCs the operator was, at that moment, being
        // asked to name — and most of them get named seconds later.
        if ($ignoreUnknown) {
            nameUPCUnidentified($code);
            return unidentifiedHit($code, null);
        }
        return [
            'ok' => false, 'kind' => 'packaged', 'barcode' => $code,
            'error' => 'UPC not found in Open Food Facts. Add it manually under Lookup Tables.',
        ];
    }

    // Branded name in hand: ask OpenAI for a generic, or fall back to the brand.
    $apiKey  = setting('openai_api_key', '');
    $generic = null;
    $source  = 'off-only';
    $aiError = null;
    if ($apiKey) {
        $r = mapGenericViaOpenAI($brandName, $apiKey, setting('openai_model', 'gpt-4o-mini'));
        if ($r['name']) {
            $generic = $r['name'];
            $source  = 'off+ai';
        } else {
            $aiError = $r['error'] ?? 'unknown error';
            setSetting('last_openai_error', $aiError . ' (' . now() . ')');
        }
    } elseif ($apiKey === '') {
        $aiError = 'OpenAI API key not set';
    }
    if (!$generic) {
        // Best-effort fallback: strip leading brand words to ~3 words.
        $generic = ucwords(strtolower(trim(preg_replace('/\s+/', ' ', $brandName))));
    }

    // Two stations can miss the cache on the same new UPC and then both sit in
    // the OFF + OpenAI calls above for a few seconds, so by the time we write,
    // the row may already be there. A bare INSERT raised a UNIQUE violation and
    // 500'd the scan; first writer wins instead, the same DO NOTHING the manual
    // path in api_scan.php uses.
    $ins = $db->prepare(
        'INSERT INTO upc_lookup (upc, brand_name, generic_name, source, created_at)
         VALUES (?, ?, ?, ?, ?)
         ON CONFLICT(upc) DO NOTHING'
    );
    $ins->execute([$code, $brandName, $generic, $source, now()]);
    // OFF knows it after all, so drop any miss recorded on an earlier pass.
    forgetUnidentifiedUPC($code);
    if ($ins->rowCount() === 0) {
        // Someone beat us to it. Report what's actually stored rather than what
        // we just computed, so this answer can't disagree with the cache every
        // later scan of this UPC will read — the winner may have been a manual
        // entry with a curated name.
        $stmt = $db->prepare('SELECT brand_name, generic_name FROM upc_lookup WHERE upc = ?');
        $stmt->execute([$code]);
        if ($row = $stmt->fetch()) {
            $brandName = $row['brand_name'];
            $generic   = $row['generic_name'];
            $source    = 'cache';
        }
    }

    return [
        'ok' => true, 'kind' => 'packaged', 'barcode' => $code,
        'generic_name' => $generic, 'brand_name' => $brandName,
        'source' => $source, 'ai_error' => $aiError, 'needs_weight' => false,
    ];
}

function fetchFromOFF(string $upc): array {
    // v2 endpoint returns minimal payload when fields= is specified.
    $url = "https://world.openfoodfacts.org/api/v2/product/" . urlencode($upc) . ".json?fields=code,product_name,brands,generic_name,categories_tags";

    $ctx = stream_context_create(['http' => [
        'method'  => 'GET',
        'timeout' => 6,
        'header'  => "User-Agent: FootprintsFoodScan/1.0 (pantry use)\r\n",
        'ignore_errors' => true,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) return ['name' => null];

    $data = json_decode($body, true);
    if (!is_array($data) || ($data['status'] ?? 0) !== 1) return ['name' => null];

    $p = $data['product'] ?? [];
    $name = $p['product_name'] ?? '';
    $brand = $p['brands'] ?? '';
    if ($name === '' && !empty($p['generic_name'])) $name = $p['generic_name'];
    if ($name === '') return ['name' => null];

    $full = trim(($brand ? $brand . ' ' : '') . $name);
    return ['name' => $full, 'raw' => $p];
}

function mapGenericViaOpenAI(string $brandName, string $apiKey, string $model): array {
    $sys = "You convert branded grocery product names into short generic pantry-inventory names. "
         . "Reply with ONLY the generic name (2-4 words, Title Case, no brand). "
         . "Avoid flavor descriptors. Use plural when the item is typically counted in multiples (Beans, Tomatoes).\n\n"
         . "Baseline examples:\n"
         . "  'Alexander's Premium Black Beans with Mesquite' -> 'Black Beans'\n"
         . "  'Bumble Bee Solid White Albacore Tuna in Water' -> 'Canned Tuna'\n"
         . "  'Barilla Penne Rigate Pasta' -> 'Penne Pasta'\n"
         . "  'Kraft Macaroni & Cheese Dinner' -> 'Mac and Cheese'\n";

    // Ground the model in this pantry's own curated cache. When an operator
    // corrects a mislabeled generic through Lookup Tables, the row's source
    // flips to 'manual' (see lookup_admin.php upc_edit/upc_add), so those
    // brand -> generic pairs are the authoritative house convention. Showing
    // them as examples teaches the AI the intended level of generality — e.g.
    // that "Mercantile & Fancy Chunk Light Tuna in Water" should collapse to
    // the existing "Canned Tuna" rather than a fresh "Chunk Light Tuna" — so
    // it stays consistent with prior human decisions instead of re-inventing
    // names for near-identical products.
    $cacheExamples = cacheGenericExamples();
    if ($cacheExamples !== '') {
        $sys .= "\nThis pantry's established mappings (from operator-curated cache) — "
              . "reuse the same generic name whenever a product is similar:\n"
              . $cacheExamples . "\n";
    }

    $payload = [
        'model' => $model,
        'temperature' => 0,
        'max_tokens' => 20,
        'messages' => [
            ['role' => 'system', 'content' => $sys],
            ['role' => 'user',   'content' => "Map this product name to a generic: " . $brandName],
        ],
    ];
    return openAIRequest('https://api.openai.com/v1/chat/completions', $payload, $apiKey);
}

// Build few-shot example lines from the operator-curated UPC cache. Only
// 'manual' rows are used: those are mappings a human either typed in or
// corrected, so they represent the intended generic name (unlike raw 'off+ai'
// guesses or 'off-only' fallbacks, which we don't want to reinforce). Rows are
// ordered most-recently-touched first and capped so the prompt stays bounded.
function cacheGenericExamples(int $limit = 40): string {
    try {
        $rows = getDB()->query(
            "SELECT brand_name, generic_name
               FROM upc_lookup
              WHERE source = 'manual' AND TRIM(brand_name) <> ''
              ORDER BY COALESCE(updated_at, created_at) DESC
              LIMIT " . (int)$limit
        )->fetchAll();
    } catch (Throwable $e) {
        return '';
    }

    $lines = [];
    foreach ($rows as $r) {
        $brand = trim((string)($r['brand_name'] ?? ''));
        $gen   = trim((string)($r['generic_name'] ?? ''));
        if ($brand === '' || $gen === '') continue;
        // Keep each example short so a handful of verbose OFF names don't blow
        // the prompt budget; normalize the apostrophes we quote with.
        if (mb_strlen($brand) > 80) $brand = mb_substr($brand, 0, 77) . '...';
        $brand = str_replace("'", "\u{2019}", $brand);
        $gen   = str_replace("'", "\u{2019}", $gen);
        $lines[] = "  '" . $brand . "' -> '" . $gen . "'";
    }
    return implode("\n", $lines);
}

function openAIRequest(string $url, array $payload, string $apiKey): array {
    $raw = openAIRawRequest($url, $payload, $apiKey);
    if ($raw['body'] === null) return ['name' => null, 'error' => $raw['error']];
    return parseOpenAIResponse($raw['code'], $raw['body']);
}

// General chat completion returning the reply text untouched (no name-style
// trimming, which would eat trailing periods from prose). Used for the
// recipe / preparation-help features on the scan page.
function openAIChatText(array $messages, string $apiKey, string $model,
                        int $maxTokens = 700, float $temperature = 0.4): array {
    $payload = [
        'model'       => $model,
        'temperature' => $temperature,
        'max_tokens'  => $maxTokens,
        'messages'    => $messages,
    ];
    $raw = openAIRawRequest('https://api.openai.com/v1/chat/completions', $payload, $apiKey);
    if ($raw['body'] === null) return ['text' => null, 'error' => $raw['error']];
    $data = json_decode($raw['body'], true);
    if ($raw['code'] >= 400 || !is_array($data)) {
        $msg = is_array($data) ? ($data['error']['message'] ?? null) : null;
        return ['text' => null, 'error' => $msg ?? ('HTTP ' . $raw['code'] . ' from OpenAI')];
    }
    $txt = trim((string)($data['choices'][0]['message']['content'] ?? ''));
    if ($txt === '') return ['text' => null, 'error' => 'OpenAI returned empty content'];
    return ['text' => $txt, 'error' => null];
}

// Transport only: returns ['code' => int, 'body' => ?string, 'error' => ?string].
function openAIRawRequest(string $url, array $payload, string $apiKey): array {
    $body = json_encode($payload);
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ];

    // Prefer cURL — file_get_contents over HTTPS depends on openssl + a CA
    // bundle being configured in php.ini, which is hit-or-miss on Windows.
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $body,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            return ['code' => 0, 'body' => null, 'error' => 'cURL transport error: ' . $err];
        }
        return ['code' => $code, 'body' => $resp, 'error' => null];
    }

    // Fallback: file_get_contents.
    $ctx = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'timeout'       => 15,
            'header'        => implode("\r\n", $headers) . "\r\n",
            'content'       => $body,
            'ignore_errors' => true,
        ],
    ]);
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) {
        $err = error_get_last()['message'] ?? 'unknown';
        return ['code' => 0, 'body' => null, 'error' => 'file_get_contents failed: ' . $err
            . ' (consider enabling php_curl in php.ini)'];
    }
    $code = 200;
    if (isset($http_response_header[0])
        && preg_match('#HTTP/\S+\s+(\d+)#', $http_response_header[0], $m)) {
        $code = (int)$m[1];
    }
    return ['code' => $code, 'body' => $resp, 'error' => null];
}

function parseOpenAIResponse(int $httpCode, string $body): array {
    $data = json_decode($body, true);
    if ($httpCode >= 400 || !is_array($data)) {
        $msg = $data['error']['message'] ?? ('HTTP ' . $httpCode . ' from OpenAI');
        return ['name' => null, 'error' => $msg];
    }
    $txt = $data['choices'][0]['message']['content'] ?? '';
    $txt = trim($txt, " \t\n\r\"'.");
    if ($txt === '') {
        return ['name' => null, 'error' => 'OpenAI returned empty content'];
    }
    return ['name' => $txt, 'error' => null];
}
