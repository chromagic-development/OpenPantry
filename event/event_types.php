<?php
// Supported event types. The picker (index.php) and submit endpoint
// (submit_event.php) read eventTypes() so they stay in lock-step, and the
// reports (orders_listing.php, usage_report.php) use these helpers to detect
// and label EVENT-tagged orders.
//
// The list is admin-editable (Manage Events in Settings) and stored as a JSON
// array in the `event_types` setting. The reports follow this current list:
// removing a type drops it from the pickers AND from the reports' Order Type
// filter / breakdown. Historical event orders are not modified — they remain
// identified as generic "Event" once their specific type is removed.

function eventTypesDefault(): array {
    return ['Breakfast Cafe', 'Community Supper', 'Meal Prep', 'Mainspring Cooks'];
}

function eventTypes(): array {
    $raw = setting('event_types', '');
    $arr = json_decode((string)$raw, true);
    if (!is_array($arr)) return eventTypesDefault();
    $out = [];
    foreach ($arr as $t) {
        $t = trim((string)$t);
        if ($t !== '' && !in_array($t, $out, true)) $out[] = $t;
    }
    return $out !== [] ? $out : eventTypesDefault();
}

function setEventTypes(array $types): void {
    $clean = [];
    foreach ($types as $t) {
        $t = trim((string)$t);
        if ($t !== '' && !in_array($t, $clean, true)) $clean[] = $t;
    }
    if ($clean === []) return; // never persist an empty list
    setSetting('event_types', json_encode(array_values($clean)));
}

function isEventType(string $t): bool {
    return in_array($t, eventTypes(), true);
}

// Parse "<type>" out of an order note formatted "EVENT · <type> · <initials>".
// Returns '' for non-event notes. Used by orders_listing.php for the per-row
// pill (which only names the type when it's still a current event type).
function eventTypeFromNote(?string $note): string {
    if ($note === null) return '';
    $prefix = 'EVENT · ';
    if (strncmp($note, $prefix, strlen($prefix)) !== 0) return '';
    $rest = substr($note, strlen($prefix));
    $sep  = strpos($rest, ' · ');
    return $sep === false ? $rest : substr($rest, 0, $sep);
}
