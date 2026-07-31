<?php
// Add or remove "days not scanned" entries — days the pantry operated but no
// scanning happened. The Order Report's demand model treats these as
// unobserved rather than as days of genuine zero demand; see the unscanned_days
// comment in schema.sql. Form-encoded POST; redirects back to the Order Report.
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/auth.php';
requireLogin();
$db = getDB();
$action = $_POST['action'] ?? '';

try {
    if ($action === 'add') {
        $day  = trim((string)($_POST['day'] ?? ''));
        $note = trim((string)($_POST['note'] ?? ''));

        // Accept only a real calendar date in Y-m-d, and never a future one —
        // you can't have missed scanning on a day that hasn't happened yet.
        // The round-trip comparison rejects junk like '2026-02-31', which
        // createFromFormat would silently roll forward to March 3rd.
        $d = DateTimeImmutable::createFromFormat('Y-m-d', $day);
        $valid = $d !== false
              && $d->format('Y-m-d') === $day
              && $day <= (new DateTimeImmutable('today'))->format('Y-m-d');

        if ($valid) {
            // Re-adding a day already on the list just updates its note, so the
            // form is safe to resubmit and created_at keeps its first value.
            $ins = $db->prepare(
                "INSERT INTO unscanned_days (day, note, created_at) VALUES (?, ?, ?)
                 ON CONFLICT(day) DO UPDATE SET note = excluded.note"
            );
            $ins->execute([$day, substr($note, 0, 200), now()]);
        }
    }

    if ($action === 'delete') {
        $day = trim((string)($_POST['day'] ?? ''));
        if ($day !== '') {
            $del = $db->prepare("DELETE FROM unscanned_days WHERE day = ?");
            $del->execute([$day]);
        }
    }
} catch (\Throwable $e) {
    // Table missing (PHP files deployed ahead of schema.sql) or the DB is
    // locked. Fall through to the redirect rather than dumping an error page:
    // the report reads the same list defensively and simply behaves as it did
    // before this feature existed.
}

header('Location: reports/order_report/');
