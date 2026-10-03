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

// Accept only a real calendar date in Y-m-d, and never a future one — you
// can't have missed scanning on a day that hasn't happened yet. The round-trip
// comparison rejects junk like '2026-02-31', which createFromFormat would
// silently roll forward to March 3rd.
function parseUnscannedDay(string $day): ?DateTimeImmutable {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $day);
    if ($d === false || $d->format('Y-m-d') !== $day) return null;
    if ($day > (new DateTimeImmutable('today'))->format('Y-m-d')) return null;
    return $d;
}

// A whole range in one submit is bounded so a mistyped year can't flood the
// table with a decade of "missed" days and gut every demand estimate.
const UNSCANNED_MAX_RANGE_DAYS = 366;

try {
    if ($action === 'add') {
        // `day` is the single-date field the form used before it took a range;
        // still honored so a page cached from before this deploy keeps working.
        $startIn = trim((string)($_POST['start'] ?? $_POST['day'] ?? ''));
        $endIn   = trim((string)($_POST['end'] ?? $startIn));
        $note    = substr(trim((string)($_POST['note'] ?? '')), 0, 200);

        $start = parseUnscannedDay($startIn);
        $end   = parseUnscannedDay($endIn);
        if ($start && $end) {
            // Entered backwards: take the range they obviously meant.
            if ($start > $end) [$start, $end] = [$end, $start];

            if ($start->diff($end)->days < UNSCANNED_MAX_RANGE_DAYS) {
                // Re-adding a day already on the list just updates its note, so
                // the form is safe to resubmit and created_at keeps its first
                // value. One transaction: a range lands whole or not at all.
                $ins = $db->prepare(
                    "INSERT INTO unscanned_days (day, note, created_at) VALUES (?, ?, ?)
                     ON CONFLICT(day) DO UPDATE SET note = excluded.note"
                );
                $createdAt = now();
                $db->beginTransaction();
                try {
                    for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
                        $ins->execute([$d->format('Y-m-d'), $note, $createdAt]);
                    }
                    $db->commit();
                } catch (\Throwable $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    throw $e;
                }
            }
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

// Return to the report as it was: its filters and the Days Not Scanned page
// ride along in `return`. Round-tripping through parse_str/http_build_query
// means only well-formed query parameters reach the Location header. An add
// goes back to the first page, where the most recent days are; a Remove stays
// on the page it was pressed from (the report clamps it if that page emptied).
parse_str((string)($_POST['return'] ?? ''), $returnQuery);
if ($action === 'add') unset($returnQuery['us_page']);
$qs = http_build_query($returnQuery);
header('Location: reports/order_report/' . ($qs !== '' ? '?' . $qs : '') . '#unscanned');
