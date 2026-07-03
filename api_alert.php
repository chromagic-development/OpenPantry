<?php
// Add or delete reorder alerts. Form-encoded POST; redirects back to the
// Order Report (reports/order_report/).
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/auth.php';
requireLogin();
$db = getDB();
$action = $_POST['action'] ?? '';

if ($action === 'add') {
    $name  = trim((string)($_POST['generic_name'] ?? ''));
    $lt    = max(1, (int)($_POST['lead_time_days'] ?? 0));
    $email = !empty($_POST['email']) ? 1 : 0;
    if ($name !== '' && $lt > 0) {
        $ins = $db->prepare(
            "INSERT INTO alerts (generic_name, lead_time_days, enabled, email_enabled) VALUES (?, ?, 1, ?)"
        );
        $ins->execute([$name, $lt, $email]);
    }
}
if ($action === 'set_email') {
    // Toggle whether this alert's reorder reminder is emailed by the cron job.
    $id    = (int)($_POST['id'] ?? 0);
    $email = !empty($_POST['email']) ? 1 : 0;
    if ($id) {
        $u = $db->prepare("UPDATE alerts SET email_enabled = ? WHERE id = ?");
        $u->execute([$email, $id]);
    }
}
if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $d = $db->prepare("DELETE FROM alerts WHERE id = ?");
        $d->execute([$id]);
    }
}
header('Location: reports/order_report/');
