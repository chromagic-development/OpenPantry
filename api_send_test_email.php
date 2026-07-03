<?php
// Send a test reorder-reminder email using the saved Email Notification
// settings. Backs the "Send Test Email" button in settings.php.
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/auth.php';
requireLoginAPI();
require_once __DIR__ . '/mailer.php';

$to = trim((string)(setting('admin_email', '') ?? ''));
if ($to === '') {
    jsonOut(['ok' => false, 'error' => 'No administrator email is set. Save one first.']);
}

$pantry  = trim((string)(setting('food_pantry_name', '') ?? ''));
$via     = trim((string)(setting('smtp_host', '') ?? '')) !== '' ? 'SMTP' : 'PHP mail()';
$subject = ($pantry !== '' ? $pantry . ' — ' : '') . 'OpenPantry test email';
$body    = "This is a test email from OpenPantry.\n\n"
         . "If you received this, reorder-reminder notifications are configured "
         . "correctly and will be sent to this address.\n\n"
         . "Sent: " . now() . "\n";

$r = op_send_mail($to, $subject, $body);
if ($r['ok']) {
    jsonOut(['ok' => true, 'to' => $to, 'via' => $via]);
}
jsonOut(['ok' => false, 'error' => $r['error']]);
