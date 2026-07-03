<?php
// Smoke-test the OpenAI key + model. Returns the generic name OpenAI assigns
// to a fixed sample product. Used by the "Test" button in settings.php.
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/auth.php';
requireLoginAPI();
require_once __DIR__ . '/lookup.php';

$key   = setting('openai_api_key', '');
$model = setting('openai_model', 'gpt-4o-mini');

if ($key === '') {
    jsonOut(['ok' => false, 'error' => 'No API key saved in settings.']);
}

$sample = "Alexander's Premium Black Beans with Mesquite";
$r = mapGenericViaOpenAI($sample, $key, $model);
if ($r['name'] === null) {
    setSetting('last_openai_error', $r['error'] . ' (' . now() . ')');
    jsonOut(['ok' => false, 'error' => $r['error'], 'model' => $model]);
}
setSetting('last_openai_error', '');
jsonOut([
    'ok'      => true,
    'model'   => $model,
    'sample'  => $sample,
    'generic' => $r['name'],
]);
