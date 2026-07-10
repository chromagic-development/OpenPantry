<?php
// Barcode -> generic-name resolution.
//
// Flow:
//   1. Produce code (4-5 digits, or 12-digit starting with 4):
//        look up in produce_lookup; return generic_name + needs_weight=true
//   2. Packaged UPC (any other 8-14 digit code):
//        a. Try upc_lookup cache.
//        b. Cache miss -> fetch OFF API. If no product, mark unknown.
//        c. With a branded name in hand, ask OpenAI for a generic mapping.
//        d. Insert into upc_lookup and return.

require_once __DIR__ . '/db.php';

function classifyBarcode(string $code): string {
    $code = trim($code);
    if ($code === '' || !ctype_digit($code)) return 'invalid';
    $len = strlen($code);
    // Produce PLU: 4 or 5 digits.
    if ($len === 4 || $len === 5) return 'produce';
    // Pantry-printed 12-digit produce label starting with 4.
    if ($len === 12 && $code[0] === '4') return 'produce';
    if ($len >= 8 && $len <= 14) return 'packaged';
    return 'invalid';
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
    $stmt = $db->prepare('SELECT brand_name, generic_name FROM upc_lookup WHERE upc = ?');
    $stmt->execute([$code]);
    $row = $stmt->fetch();
    if ($row) {
        return [
            'ok' => true, 'kind' => 'packaged', 'barcode' => $code,
            'generic_name' => $row['generic_name'], 'brand_name' => $row['brand_name'],
            'source' => 'cache', 'needs_weight' => false,
        ];
    }

    // Cache miss: ask OFF for a product name.
    $off = fetchFromOFF($code);
    $brandName = $off['name'] ?? null;
    if (!$brandName) {
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

    $ins = $db->prepare(
        'INSERT INTO upc_lookup (upc, brand_name, generic_name, source, created_at)
         VALUES (?, ?, ?, ?, ?)'
    );
    $ins->execute([$code, $brandName, $generic, $source, now()]);

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
