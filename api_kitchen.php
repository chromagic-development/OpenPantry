<?php
// AI kitchen help for the scan station. Both actions return plain text meant
// for a print-friendly window on the client.
// POST { action: 'recipe' }                 -> nutritious recipe built from the open order's scanned items.
// POST { action: 'prepare', generic_name }  -> how to prepare and serve a single item.

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/auth.php';
requireAllowedIPAPI();
require_once __DIR__ . '/lookup.php';

$in = jsonIn();
$action = $in['action'] ?? '';

$key   = setting('openai_api_key', '');
$model = setting('openai_model', 'gpt-4o-mini');
if ($key === '') {
    jsonOut(['ok' => false, 'error' => 'OpenAI API key not set — add it in Settings.'], 400);
}

if ($action === 'recipe') {
    $open = currentOpenOrder();
    if (!$open) jsonOut(['ok' => false, 'error' => 'No open order — scan items first.'], 409);

    $st = getDB()->prepare(
        "SELECT generic_name,
                SUM(CASE WHEN kind = 'produce' THEN COALESCE(weight_lbs, 0) ELSE 0 END) AS lbs,
                SUM(CASE WHEN kind = 'produce' THEN 0 ELSE quantity END)                AS qty
           FROM scans
          WHERE order_id = ?
          GROUP BY generic_name
          ORDER BY generic_name COLLATE NOCASE"
    );
    $st->execute([$open['id']]);
    $rows = $st->fetchAll();
    if (!$rows) jsonOut(['ok' => false, 'error' => 'No items scanned on this order yet.'], 409);

    $lines = [];
    foreach ($rows as $r) {
        $amount  = (float)$r['lbs'] > 0 ? round((float)$r['lbs'], 2) . ' lb' : 'x' . (int)$r['qty'];
        $lines[] = '- ' . $r['generic_name'] . ' (' . $amount . ')';
    }

    $sys = "You are a friendly nutrition assistant for a food pantry. Clients receive an order "
         . "of grocery items and need simple, nutritious meal ideas. Write in PLAIN TEXT only — "
         . "no markdown symbols like # or *. Assume basic kitchen equipment and common staples "
         . "(salt, pepper, cooking oil, water) are available.";
    $user = "Here are the items in this pantry order:\n" . implode("\n", $lines) . "\n\n"
          . "Suggest one traditional, well-known recipe (a familiar home-cooked dish, not an "
          . "invented combination) made from these items. Among traditional dishes, prefer the "
          . "one that uses as many of the order items as possible — but staying a genuine "
          . "classic recipe always comes first: never force an item in where it doesn't "
          . "belong, and it is fine to leave items out when they don't fit. Include: the "
          . "recipe title, an ingredient list with approximate quantities (noting any common "
          . "staples needed beyond the order items), numbered step-by-step instructions, and "
          . "one short note on why the meal is nutritious.";

    $r = openAIChatText(
        [['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => $user]],
        $key, $model, 900, 0.5
    );
    if ($r['text'] === null) {
        setSetting('last_openai_error', $r['error'] . ' (' . now() . ')');
        jsonOut(['ok' => false, 'error' => $r['error']], 502);
    }
    jsonOut(['ok' => true, 'order_id' => (int)$open['id'], 'text' => $r['text']]);
}

if ($action === 'prepare') {
    $name = trim((string)($in['generic_name'] ?? ''));
    if ($name === '') jsonOut(['ok' => false, 'error' => 'Missing generic_name'], 400);
    if (mb_strlen($name) > 80) $name = mb_substr($name, 0, 80);

    $sys = "You are a friendly assistant for a food pantry, helping clients cook the groceries "
         . "they receive. Write in PLAIN TEXT only — no markdown symbols like # or *. Use short "
         . "numbered steps covering basic preparation, cooking, and one or two serving "
         . "suggestions. Keep it under 250 words and assume basic kitchen equipment.";
    $user = "How do I prepare and serve " . $name . "?";

    $r = openAIChatText(
        [['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => $user]],
        $key, $model, 500, 0.4
    );
    if ($r['text'] === null) {
        setSetting('last_openai_error', $r['error'] . ' (' . now() . ')');
        jsonOut(['ok' => false, 'error' => $r['error']], 502);
    }
    jsonOut(['ok' => true, 'name' => $name, 'text' => $r['text']]);
}

jsonOut(['ok' => false, 'error' => 'Unknown action'], 400);
