<?php
// Deliveryprep helpers. The delivery menu lives inside FoodScan but needs
// read-only access to PantryPrep's picklist.db (for config_items metadata —
// category, family_factor, use_adults / use_children). We can't include
// menucounter/db.php because its getDB() collides with FoodScan's, so this
// file opens picklist.db with its own PDO handle.

require_once __DIR__ . '/../paths.php'; // database locations (OPENPANTRY_DB_DIR)

// Default cities, used until an admin customizes the list.
function deliveryCitiesDefault(): array {
    return ['Eliot, ME', 'Kittery, ME'];
}

// Admin-editable list of City options. Stored as a JSON array in the
// `delivery_cities` setting; falls back to the defaults when unset/empty.
// Single source of truth so the delivery kiosk (index.php) and the client
// manager (client/client.php) always offer the same City options.
function deliveryCities(): array {
    $raw = setting('delivery_cities', '');
    $arr = json_decode((string)$raw, true);
    if (!is_array($arr)) return deliveryCitiesDefault();
    $out = [];
    foreach ($arr as $c) {
        $c = trim((string)$c);
        if ($c !== '' && !in_array($c, $out, true)) $out[] = $c;
    }
    return $out !== [] ? $out : deliveryCitiesDefault();
}

function setDeliveryCities(array $cities): void {
    $clean = [];
    foreach ($cities as $c) {
        $c = trim((string)$c);
        if ($c !== '' && !in_array($c, $clean, true)) $clean[] = $c;
    }
    if ($clean === []) return;
    setSetting('delivery_cities', json_encode(array_values($clean)));
}

// Default delivery groups, used until an admin customizes the list on the
// client manager (client/client.php → Manage Groups).
function deliveryGroupsDefault(): array {
    return ['K-1', 'K-2', 'E-1', 'E-2'];
}

// The admin-editable list of selectable delivery groups. Stored as a JSON
// array in the `delivery_groups` setting; falls back to the defaults when
// unset/empty. Sanitized to non-empty, trimmed, unique strings.
//
// Removing a group from this list only removes it from the pickers — it does
// NOT touch existing delivery_clients rows, so clients keep whatever group
// they were saved with. The forms that need it surface a removed-but-in-use
// group as an extra option so it isn't silently changed.
function deliveryGroups(): array {
    $raw = setting('delivery_groups', '');
    $arr = json_decode((string)$raw, true);
    if (!is_array($arr)) return deliveryGroupsDefault();
    $out = [];
    foreach ($arr as $g) {
        $g = trim((string)$g);
        if ($g !== '' && !in_array($g, $out, true)) $out[] = $g;
    }
    return $out !== [] ? $out : deliveryGroupsDefault();
}

// Persist a new group list (sanitized). Empty input is ignored so the picker
// can never end up with zero groups.
function setDeliveryGroups(array $groups): void {
    $clean = [];
    foreach ($groups as $g) {
        $g = trim((string)$g);
        if ($g !== '' && !in_array($g, $clean, true)) $clean[] = $g;
    }
    if ($clean === []) return;
    setSetting('delivery_groups', json_encode(array_values($clean)));
}

// Default group → cities mapping (mirrors the original auto-fill: K-* serve
// Kittery, E-* serve Eliot).
function deliveryGroupCitiesDefault(): array {
    return [
        'K-1' => ['Kittery, ME'], 'K-2' => ['Kittery, ME'],
        'E-1' => ['Eliot, ME'],   'E-2' => ['Eliot, ME'],
    ];
}

// Map of group name => array of cities it serves. Stored as a JSON object in
// the `delivery_group_cities` setting. Drives the City dropdown filtering on
// the Add Client form: picking a group narrows the City choices to its
// mapped cities. A group with no mapping (or an empty list) is treated as
// "no constraint" — all cities are offered.
function deliveryGroupCities(): array {
    $raw  = setting('delivery_group_cities', '');
    $data = json_decode((string)$raw, true);
    if (!is_array($data)) return deliveryGroupCitiesDefault();
    $out = [];
    foreach ($data as $g => $cs) {
        $g = trim((string)$g);
        if ($g === '' || !is_array($cs)) continue;
        $list = [];
        foreach ($cs as $c) {
            $c = trim((string)$c);
            if ($c !== '' && !in_array($c, $list, true)) $list[] = $c;
        }
        $out[$g] = $list;
    }
    return $out;
}

function setDeliveryGroupCities(array $map): void {
    $clean = [];
    foreach ($map as $g => $cs) {
        $g = trim((string)$g);
        if ($g === '') continue;
        $list = [];
        if (is_array($cs)) {
            foreach ($cs as $c) {
                $c = trim((string)$c);
                if ($c !== '' && !in_array($c, $list, true)) $list[] = $c;
            }
        }
        $clean[$g] = array_values($list);
    }
    setSetting('delivery_group_cities', json_encode($clean));
}

function picklistDB(): ?PDO {
    static $db = null;
    if ($db !== null) return $db;
    $path = fsDbPath('picklist.db');
    if (!file_exists($path)) return null;
    try {
        $db = new PDO('sqlite:' . $path);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $db;
    } catch (\PDOException $e) {
        return null;
    }
}

// Build the picklist shown on the delivery menu.
//   1. Inventory rows (count>0) that match an active+available config_item
//      (by case-insensitive item_name == generic_name) are listed under
//      the config_item's category, carrying its family_factor + size metadata.
//   2. Remaining inventory rows (count>0) that aren't in picklist.db are
//      listed under "PRODUCE" if the name is in produce_lookup, otherwise
//      under "DRY GOODS".
// Returns: [category => [item, ...], ...] with each list pre-sorted by name.
//
// Matching, by inventory generic_name (case-insensitive):
//   * A config_item with has_detail=1 and size_options "A,B,C" lists as ONE
//     item (its item_name) with a size dropdown, offering only the sizes whose
//     "<item_name> <size>" inventory row is in stock (e.g. "Butter Salted").
//     Those variant rows are NOT listed separately.
//   * A config_item without sizes matches its exact item_name.
//   * Inventory rows not represented by any config_item list under PRODUCE (if
//     in produce_lookup) or DRY GOODS otherwise.
//   * A config_item with unavailable=1 is NOT offered for selection, but it
//     still claims (hides) its matching inventory row(s) so they don't fall
//     through to PRODUCE/DRY GOODS as raw rows.
function buildDeliveryItems(PDO $foodscanDb, ?PDO $picklistDb): array {
    $configItems = [];
    if ($picklistDb) {
        try {
            // NOTE: we deliberately do NOT filter by `active = 1` here. The
            // PantryPrep counter form already filters by active itself, so
            // admins can use it to hide items from the in-pantry order page
            // (alongside the foodscan inventory.deliverable flag), but the
            // delivery side still needs the config row's `has_factor` /
            // family_factor metadata so persistDeliveryOrder() can compute
            // the correct each/lb quantities for packing lists — even when
            // an item is currently flagged active = 0 in menucounter admin.
            // `unavailable = 1` still hides items from delivery menus (see
            // the `if ($unavailable) continue` branches below).
            $configItems = $picklistDb->query(
                "SELECT id, category, item_name,
                        COALESCE(family_factor, 1.0) AS family_factor,
                        COALESCE(use_adults, 0)      AS use_adults,
                        COALESCE(use_children, 0)    AS use_children,
                        COALESCE(has_detail, 0)      AS has_detail,
                        COALESCE(detail_label, '')   AS detail_label,
                        COALESCE(size_options, '')   AS size_options,
                        COALESCE(unavailable, 0)     AS unavailable
                 FROM config_items"
            )->fetchAll();
        } catch (\PDOException $e) { $configItems = []; }
    }

    // In-stock inventory, indexed by lowercased name. Items marked
    // `deliverable = 0` on the Inventory page are suppressed everywhere
    // that goes through this builder: the delivery kiosk, the printed
    // worksheets, and the AI upload processor. Because each config_item
    // only joins the menu when an inventory row carries its name, the
    // filter also cascades to the matching config row.
    $inventory = $foodscanDb->query(
        "SELECT generic_name, count, unit FROM inventory
          WHERE count > 0 AND deliverable = 1"
    )->fetchAll();
    $invByLc = [];
    foreach ($inventory as $inv) $invByLc[strtolower($inv['generic_name'])] = $inv;

    // Names that are produce (lowercased) so unmatched rows can be bucketed
    // into PRODUCE; anything else not in picklist.db -> DRY GOODS.
    $produceNames = [];
    try {
        foreach ($foodscanDb->query("SELECT generic_name FROM produce_lookup")->fetchAll() as $r) {
            $produceNames[strtolower($r['generic_name'])] = true;
        }
    } catch (\PDOException $e) { /* ignore */ }

    $items = [];
    $consumed = []; // lowercased inventory names represented by a config item

    foreach ($configItems as $cfg) {
        $sizes = array_values(array_filter(array_map('trim',
                    explode(',', (string)$cfg['size_options']))));
        $hasDetail = ((int)$cfg['has_detail'] === 1 && !empty($sizes));
        $nameLc = strtolower($cfg['item_name']);
        // Unavailable items still "claim" their matching inventory row(s) below
        // (so those rows don't reappear under PRODUCE/DRY GOODS), but are never
        // listed for selection themselves.
        $unavailable = ((int)$cfg['unavailable'] === 1);

        if ($hasDetail) {
            // Hide a plain (unsized) inventory row of the same name — a sized
            // item is ordered by its size variant, not as a bare row.
            $consumed[$nameLc] = true;
            // Offer only sizes whose "<name> <size>" inventory row is in stock.
            $inStock = [];
            foreach ($sizes as $sz) {
                $vlc = strtolower($cfg['item_name'] . ' ' . $sz);
                if (isset($invByLc[$vlc])) {
                    $inStock[] = $sz;
                    $consumed[$vlc] = true; // hide the variant as a separate item
                }
            }
            if ($unavailable) continue;    // claimed rows hidden, item not selectable
            if (empty($inStock)) continue; // no variants in stock
            $category = $cfg['category'];
            $items[$category][] = [
                'key'           => 'c:' . (int)$cfg['id'],
                'name'          => $cfg['item_name'],
                'category'      => $category,
                'has_factor'    => true,
                'use_adults'    => (int)$cfg['use_adults'],
                'use_children'  => (int)$cfg['use_children'],
                'family_factor' => (float)$cfg['family_factor'],
                'unit'          => 'each',
                'has_detail'    => true,
                'detail_label'  => $cfg['detail_label'] !== '' ? $cfg['detail_label'] : 'Size',
                'sizes'         => $inStock,
            ];
        } else {
            if (isset($invByLc[$nameLc])) {
                $inv = $invByLc[$nameLc];
                $consumed[$nameLc] = true;
                if ($unavailable) continue; // claimed row hidden, item not selectable
                $isProduce = isset($produceNames[$nameLc]);
                $category  = $isProduce ? 'PRODUCE' : $cfg['category'];
                $items[$category][] = [
                    'key'           => 'c:' . (int)$cfg['id'],
                    'name'          => $cfg['item_name'],
                    'category'      => $category,
                    'has_factor'    => true,
                    'use_adults'    => (int)$cfg['use_adults'],
                    'use_children'  => (int)$cfg['use_children'],
                    'family_factor' => (float)$cfg['family_factor'],
                    'unit'          => $inv['unit'],
                    'has_detail'    => false,
                    'detail_label'  => 'Size',
                    'sizes'         => [],
                ];
            }
        }
    }

    // Inventory rows not represented by any config item.
    foreach ($inventory as $inv) {
        $lc = strtolower($inv['generic_name']);
        if (isset($consumed[$lc])) continue;
        $isProduce = isset($produceNames[$lc]);
        $category  = $isProduce ? 'PRODUCE' : 'DRY GOODS';
        $items[$category][] = [
            'key'          => 'i:' . $inv['generic_name'],
            'name'         => $inv['generic_name'],
            'category'     => $category,
            'has_factor'   => false,
            'unit'         => $inv['unit'],
            'has_detail'   => false,
            'detail_label' => 'Size',
            'sizes'        => [],
        ];
    }

    foreach ($items as &$rows) {
        usort($rows, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
    }
    unset($rows);
    return $items;
}

// Canonical category ordering for the menu. Any categories present in the
// data but not in this list get appended in encounter order.
function deliveryCategoryOrder(array $items): array {
    $canonical = ['DAIRY', 'DRY GOODS', 'FROZEN ITEMS', 'SPECIALS', 'PRODUCE', 'OTHER ITEMS'];
    $out = [];
    foreach ($canonical as $c) {
        if (isset($items[$c])) $out[] = $c;
    }
    foreach (array_keys($items) as $c) {
        if (!in_array($c, $out, true)) $out[] = $c;
    }
    return $out;
}

// Decide how to translate a selected item into a scan row + inventory delta.
// Mirrors the family_factor calculation in menucounter/submit_order.php for
// config-backed items; falls back to "1 per each-unit inventory item, no
// decrement for lb-unit inventory" for inventory-only selections.
function deliveryItemQuantity(array $item, int $adults, int $children, float $familySize): array {
    // lb-unit config items are delivered BY WEIGHT, not by count. The weight in
    // pounds = family_factor × (adults + children); inventory (also kept in lb)
    // is drawn down by that same weight, and the packing list shows it as lbs.
    if (!empty($item['has_factor']) && ($item['unit'] ?? 'each') === 'lb') {
        $factor = (float)($item['family_factor'] ?? 1.0);
        if ($factor <= 0) $factor = 1.0;
        $people = max(1, $adults + $children);
        $weight = round($factor * $people, 2);
        if ($weight <= 0) $weight = $factor;
        return ['by_weight' => true, 'weight_lbs' => $weight, 'decrement' => true];
    }
    if (!empty($item['has_factor'])) {
        $useAdults   = (int)($item['use_adults']   ?? 0);
        $useChildren = (int)($item['use_children'] ?? 0);
        if ($useChildren && !$useAdults)      $count = $children;
        elseif ($useAdults && !$useChildren)  $count = $adults;
        else                                  $count = $familySize;
        if ($count < 1) $count = 1;
        $factor = (float)($item['family_factor'] ?? 1.0);
        if ($factor <= 0) $factor = 1.0;
        $qty = (int)ceil($count * $factor);
        if ($qty < 1) $qty = 1;
        return ['by_weight' => false, 'quantity' => $qty, 'decrement' => true];
    }
    // Inventory-only: 1 unit if 'each'; for 'lb' we have no weight, so we
    // still log the line but don't touch inventory.
    if (($item['unit'] ?? 'each') === 'lb') {
        return ['by_weight' => false, 'quantity' => 1, 'decrement' => false];
    }
    return ['by_weight' => false, 'quantity' => 1, 'decrement' => true];
}

// Shared writer for delivery orders. Used by the kiosk (submit_delivery.php)
// AND by the AI upload processor (process_upload.php) so both paths produce
// identical rows in orders/scans/inventory.
//
// Inputs:
//   $clientId      saved-client id (0 = ad-hoc; no delivered_at stamp)
//   $group         'K-1'|'K-2'|'E-1'|'E-2'
//   $adults/$children  household size
//   $itemKeys      array of menu keys (e.g. 'c:7', 'i:Apples') to deliver
//   $sizesByKey    optional map of key -> chosen size for has_detail items
//
// Returns: ['ok'=>true, 'order_id'=>N, 'lines'=>[...]]  or
//          ['ok'=>false, 'error'=>'...']
//
// The order's note encodes the client id when present:
//   "DELIVERY · Client #5 · Group K-1 · 2A/1C"
// which still matches the existing `note LIKE 'DELIVERY %'` detector in the
// reports, and gives print_packing_lists.php a way to look up each pending
// client's most recent delivery order.
function persistDeliveryOrder(
    PDO $db, ?PDO $pdb,
    int $clientId, string $group,
    int $adults, int $children,
    array $itemKeys, array $sizesByKey = []
): array {
    // Groups are now admin-editable (and a client may carry a group that was
    // later removed from the picker), so we only require a non-empty label
    // rather than matching a fixed list. The group is just a tag in the order
    // note; nothing downstream keys off specific values.
    $group = trim($group);
    if ($group === '' || mb_strlen($group) > 20) {
        return ['ok' => false, 'error' => 'A delivery group is required.'];
    }
    $adults   = max(1, $adults);
    $children = max(0, $children);
    $familySize = min($adults + ($children / 2), 5);
    if ($familySize < 1) $familySize = 1;

    // Re-derive the menu so only currently in-stock keys are honored — this
    // blocks stale/tampered keys and naturally drops items that sold out
    // between print and processing.
    $menu  = buildDeliveryItems($db, $pdb);
    $byKey = [];
    foreach ($menu as $rows) {
        foreach ($rows as $r) $byKey[$r['key']] = $r;
    }

    $unitOf = [];
    foreach ($db->query("SELECT generic_name, unit FROM inventory WHERE count > 0")->fetchAll() as $r) {
        $unitOf[strtolower($r['generic_name'])] = $r['unit'];
    }

    $lines = [];
    foreach ($itemKeys as $key) {
        if (!is_string($key) || !isset($byKey[$key])) continue;
        $item = $byKey[$key];
        $qd   = deliveryItemQuantity($item, $adults, $children, $familySize);

        if (!empty($item['has_detail'])) {
            $size = isset($sizesByKey[$key]) ? trim((string)$sizesByKey[$key]) : '';
            if ($size === '' || !in_array($size, $item['sizes'], true)) {
                $size = $item['sizes'][0] ?? '';
            }
            if ($size === '') continue;
            $invName = $item['name'] . ' ' . $size;
        } else {
            $invName = $item['name'];
        }

        $lc = strtolower($invName);
        if (!isset($unitOf[$lc])) continue;

        if (!empty($qd['by_weight'])) {
            $lines[] = [
                'generic_name' => $invName,
                'unit'         => $unitOf[$lc],
                'by_weight'    => true,
                'weight_lbs'   => (float)$qd['weight_lbs'],
                'quantity'     => 1,
                'decrement'    => $qd['decrement'],
            ];
        } else {
            $lines[] = [
                'generic_name' => $invName,
                'unit'         => $unitOf[$lc],
                'by_weight'    => false,
                'weight_lbs'   => null,
                'quantity'     => $qd['quantity'],
                'decrement'    => $qd['decrement'],
            ];
        }
    }

    if (empty($lines)) {
        return ['ok' => false, 'error' => 'No valid (in-stock) items selected.'];
    }

    $db->beginTransaction();
    try {
        $ts         = now();
        $clientFrag = $clientId > 0 ? " · Client #{$clientId}" : '';
        $note       = "DELIVERY{$clientFrag} · Group {$group} · {$adults}A/{$children}C";

        $db->prepare(
            "INSERT INTO orders (started_at, ended_at, status, note) VALUES (?, ?, 'closed', ?)"
        )->execute([$ts, $ts, $note]);
        $orderId = (int)$db->lastInsertId();

        $insScan = $db->prepare(
            "INSERT INTO scans (order_id, barcode, generic_name, kind, quantity, weight_lbs, scanned_at)
             VALUES (?, '', ?, ?, ?, ?, ?)"
        );
        $decStmt = $db->prepare(
            "UPDATE inventory SET count = MAX(0, count - ?), updated_at = ? WHERE generic_name = ?"
        );
        foreach ($lines as $ln) {
            $kind = ($ln['unit'] === 'lb') ? 'produce' : 'packaged';
            if (!empty($ln['by_weight'])) {
                $insScan->execute([$orderId, $ln['generic_name'], $kind, 1, $ln['weight_lbs'], $ts]);
                if ($ln['decrement']) {
                    $decStmt->execute([$ln['weight_lbs'], $ts, $ln['generic_name']]);
                }
            } else {
                $insScan->execute([$orderId, $ln['generic_name'], $kind, $ln['quantity'], null, $ts]);
                if ($ln['decrement']) {
                    $decStmt->execute([$ln['quantity'], $ts, $ln['generic_name']]);
                }
            }
        }
        if ($clientId > 0) {
            $db->prepare("UPDATE delivery_clients SET delivered_at = ? WHERE id = ?")
               ->execute([$ts, $clientId]);
        }
        $db->commit();
        return ['ok' => true, 'order_id' => $orderId, 'note' => $note, 'lines' => $lines];
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['ok' => false, 'error' => 'Save failed: ' . $e->getMessage()];
    }
}

// Flatten the kiosk menu into the same name list that print_menus.php prints
// on each form. Returns [['form_name' => 'Butter Salted', 'key' => 'c:7',
// 'size' => 'Salted', 'category' => 'DAIRY'], ...]. Used by process_upload.php
// to (a) hand the model the exact menu strings to match against, and (b)
// reverse the model's selections back into the menu keys persistDeliveryOrder
// expects.
function deliveryMenuFlat(PDO $db, ?PDO $pdb): array {
    $menu     = buildDeliveryItems($db, $pdb);
    $catOrder = deliveryCategoryOrder($menu);
    $out = [];
    foreach ($catOrder as $cat) {
        if (empty($menu[$cat])) continue;
        foreach ($menu[$cat] as $it) {
            if (!empty($it['has_detail'])) {
                foreach ($it['sizes'] as $sz) {
                    $out[] = [
                        'form_name' => $it['name'] . ' ' . $sz,
                        'key'       => $it['key'],
                        'size'      => $sz,
                        'category'  => $cat,
                    ];
                }
            } else {
                $out[] = [
                    'form_name' => $it['name'],
                    'key'       => $it['key'],
                    'size'      => null,
                    'category'  => $cat,
                ];
            }
        }
    }
    return $out;
}
