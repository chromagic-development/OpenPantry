<?php
// Impact Report — the "tell the story to a funder" view.
//
// Every other report in this folder answers an operational question (what do we
// order, who took what, how big is a basket). This one answers the question a
// board member, a grant officer, or a town-meeting audience asks: *what did
// this pantry actually do for people this year?* It is deliberately the only
// report that reads BOTH databases:
//
//   openpantry.db  — orders + scans: food that actually left the building,
//                    measured (produce lb) or counted (packaged each).
//   picklist.db    — PantryPrep counter requests: what households asked for,
//                    their size (adults/children), and how much of the request
//                    was actually filled.
//
// The two are separate flows, not two views of one flow — the counter order
// form is filled in by a household, the scans are recorded at checkout — so
// they are reported in separate sections and never summed together.
//
// Estimates are labelled as estimates. Packaged goods are counted, not weighed,
// so any pound figure that includes them depends on an assumed average item
// weight; meals-equivalent depends on an assumed pounds-per-meal. Both are
// operator-editable inputs at the top of the page and are restated in the
// Methodology card, so nothing in the headline numbers is a black box.
$GLOBALS['FS_PREFIX'] = '../../';
require_once __DIR__ . '/../../common.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../event/event_types.php';
require_once __DIR__ . '/../../lookup.php';      // storeLabelSql()
require_once __DIR__ . '/../../delivery/db.php'; // picklistDB(): the shared picklist.db handle
requireLogin();
$db = getDB();

date_default_timezone_set('America/New_York');

// ── Inputs ────────────────────────────────────────────────────────────────
// A year is the natural reporting window: it covers a full seasonal cycle and
// matches how grant periods are written.
$defaultEnd   = date('Y-m-d');
$defaultStart = date('Y-m-01', strtotime('-11 months'));

// Anything that isn't a real Y-m-d falls back to the default rather than
// reaching SQL or strtotime() as garbage.
function opImpactDate(?string $v, string $fallback): string {
    if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return $fallback;
    [$y, $m, $d] = array_map('intval', explode('-', $v));
    return checkdate($m, $d, $y) ? $v : $fallback;
}
// Assumption inputs are clamped to a sane band so a stray keystroke can't
// produce a headline number off by three orders of magnitude.
function opImpactNumParam(?string $v, float $fallback, float $min, float $max): float {
    if (!is_string($v) || $v === '' || !is_numeric($v)) return $fallback;
    return max($min, min($max, (float)$v));
}

$dateStart = opImpactDate($_GET['date_start'] ?? null, $defaultStart);
$dateEnd   = opImpactDate($_GET['date_end']   ?? null, $defaultEnd);
if ($dateStart > $dateEnd) { $t = $dateStart; $dateStart = $dateEnd; $dateEnd = $t; }

// Average weight of one packaged/each item. Used only where packaged goods have
// to share an axis with weighed produce (total pounds, meals, sourcing mix).
$lbPerItem = opImpactNumParam($_GET['lb_per_item'] ?? null, 1.0, 0.1, 10.0);
// Pounds per meal. 1.2 lb is the Feeding America convention; editable because
// some funders specify their own.
$lbPerMeal = opImpactNumParam($_GET['lb_per_meal'] ?? null, 1.2, 0.25, 10.0);

$rangeStart = $dateStart . ' 00:00:00';
$rangeEnd   = $dateEnd   . ' 23:59:59';

// Month buckets, pre-seeded so a month with no service charts as a real zero
// instead of vanishing from the axis. Capped so a typo'd 3020 end date can't
// build a 12,000-column chart.
$months = [];
$cur  = new DateTime(substr($dateStart, 0, 7) . '-01');
$stop = new DateTime(substr($dateEnd,   0, 7) . '-01');
while ($cur <= $stop && count($months) < 120) {
    $months[] = $cur->format('Y-m');
    $cur->modify('+1 month');
}
$monthLabels = array_map(function ($m) { return date('M Y', strtotime($m . '-01')); }, $months);
$monthIndex  = array_flip($months);

// ── Channel classification ────────────────────────────────────────────────
// Same note-prefix convention the other reports use (orders_listing.php is the
// canonical description). Specific event types roll up into one "Event" bucket
// here: an impact audience wants four channels, not eleven.
$channelCase = "CASE"
    . " WHEN o.note LIKE 'DELIVERY %'    THEN 'Delivery'"
    . " WHEN o.note LIKE 'ORDER AHEAD %' THEN 'OrderAhead'"
    . " WHEN o.note LIKE 'EVENT %'       THEN 'Event'"
    . " ELSE 'Pantry' END";
$channels = ['Pantry', 'Delivery', 'Event', 'OrderAhead'];
$channelLabel = [
    'Pantry'     => 'In-Pantry Shopping',
    'Delivery'   => 'Home Delivery',
    'Event'      => 'Community Events',
    'OrderAhead' => 'Order Ahead',
];
$channelColor = [
    'Pantry'     => '#8BAF3A',
    'Delivery'   => '#2F6FA1',
    'Event'      => '#8B5A2B',
    'OrderAhead' => '#C7902B',
];

// ── Fresh / frozen protein items ──────────────────────────────────────────
// The pantry hands out meat and fish that is scanned by the piece but is
// nowhere near the ~1 lb an average packaged item weighs, so rolling it into
// the generic "counted items" bucket understates the year badly. An item is
// treated as fresh/frozen protein when all three hold:
//
//   1. its generic name names a meat or a fish — by the animal (turkey, lamb),
//      the cut (ham, bacon) or the species (haddock, pollack), since a bag of
//      Haddock says nothing about "fish" and a Ham says nothing about "pork",
//   2. it carries an Avg Wt (inventory.lb_per_each) — without a real per-piece
//      weight there is nothing to convert with, and
//   3. its name does not contain "can" — canned chicken, canned tuna and
//      chicken-noodle soup are shelf-stable groceries, not fresh protein.
//
// Matching rows are weighed at their own Avg Wt instead of $lbPerItem and are
// carried as a third category everywhere pounds are split, so no pound is
// counted twice. On the chart they collapse to the meat itself: "Pork Loin
// Fillet" and "Pork Chops" are both simply Pork, Ham and Bacon are Pork too,
// and every fish species is simply Fish.

// Keyword => chart label, in priority order: the FIRST hit wins, so Turkey
// Bacon is Turkey rather than Pork and a Beef Hot Dog is Beef rather than a
// Hot Dog. Matched on whole words (with an optional plural) so a short keyword
// cannot fire inside an unrelated grocery — the ham pattern leaves Hamburger
// Buns and Graham Crackers alone, and veal does not match "reveal".
$proteinMap = [
    'chicken'     => 'Chicken',
    'cornish hen' => 'Chicken',
    'turkey'      => 'Turkey',
    'beef'        => 'Beef',
    'veal'        => 'Veal',
    'pork'        => 'Pork',
    'ham'         => 'Pork',
    'bacon'       => 'Pork',
    'lamb'        => 'Lamb',
    'mutton'      => 'Lamb',
    'duck'        => 'Duck',
    'goose'       => 'Goose',
    'quail'       => 'Quail',
    'rabbit'      => 'Rabbit',
    'venison'     => 'Venison',
    'elk'         => 'Elk',
    'bison'       => 'Bison',
];
// Fish species, all labelled Fish. Same whole-word rule, and for the same
// reason: "cod", "sole", "hake", "bass" and "perch" are short enough to turn
// up inside other words, and a Protein Shake is not hake. Species ending in
// -fish are caught by the loose 'fish' test below as well, but are listed here
// so the rule reads as one list. Goat and buffalo are deliberately absent:
// Goat Cheese is dairy and Buffalo Sauce is a condiment.
$fishSpecies = [
    'pollack', 'pollock', 'catfish', 'cod', 'haddock', 'hake', 'whiting',
    'tilapia', 'swai', 'basa', 'salmon', 'tuna', 'halibut', 'trout',
    'flounder', 'sole', 'mackerel', 'sardine', 'anchovy', 'anchovies',
    'herring', 'smelt', 'perch', 'bass', 'snapper', 'grouper', 'mahi',
    'walleye', 'tilefish', 'swordfish', 'monkfish',
];

// Compiled once rather than per inventory row. The optional plural lets "Cod"
// and "Cods" both land without letting the stem run on into another word.
$proteinRe = [];
foreach ($proteinMap as $w => $l) {
    $proteinRe[] = ['/\\b' . preg_quote($w, '/') . '(?:e?s)?\\b/', $l];
}
$fishRe = '/\\b(?:' . implode('|', $fishSpecies) . ')(?:e?s)?\\b/';

// Every item the Inventory page has weighed. An Avg Wt is a measured fact
// about the item, so wherever one exists it wins over the $lbPerItem guess
// — for meat, for produce and for packaged goods alike. $lbPerItem survives
// only as the fallback for items with no weight on record.
$lbEach = [];
foreach ($db->query("SELECT generic_name, lb_per_each FROM inventory WHERE lb_per_each > 0") as $r) {
    $lbEach[(string)$r['generic_name']] = (float)$r['lb_per_each'];
}

$proteinLbEach = [];   // generic_name => avg lb per piece
$proteinGroup  = [];   // generic_name => 'Chicken' | 'Turkey' | 'Fish' | ...
foreach ($lbEach as $name => $lbPerEach) {
    $low  = mb_strtolower($name);
    if (strpos($low, 'can') !== false) continue;          // canned / cannellini: not fresh
    // Label by the meat, not the recipe.
    $label = null;
    foreach ($proteinRe as $t) {
        if (preg_match($t[0], $low)) { $label = $t[1]; break; }
    }
    // 'fish' stays a loose substring test so unlisted -fish species (bluefish,
    // rockfish) still land, which a whole-word test would miss.
    if ($label === null && (strpos($low, 'fish') !== false || preg_match($fishRe, $low))) {
        $label = 'Fish';
    }
    // A plain "Hot Dogs" or "Sausage" names no animal at all, so it keeps its
    // own label rather than being guessed into one of the meats above or
    // dropped from the chart.
    if ($label === null && strpos($low, 'hot dog') !== false) $label = 'Hot Dog';
    if ($label === null && strpos($low, 'sausage') !== false) $label = 'Sausage';
    if ($label === null) continue;
    $proteinLbEach[$name] = $lbPerEach;
    $proteinGroup[$name]  = $label;
}

// ── Produce sold by the piece ─────────────────────────────────────
// Most produce crosses a scale and carries its real pounds. The rest is sold
// by the piece — a head of lettuce, an avocado, a melon — and scans as a count,
// which the generic $lbPerItem would book at 1 lb whether it is a lime or a
// watermelon. Where the Inventory page has an Avg Wt for one of these, that
// figure is used instead, exactly as it is for protein.
//
// Membership in produce_lookup is what makes an item produce here; the scan
// itself says whether that particular pickup was weighed or counted, so no
// assumption about how the item is "usually" sold is needed.
//
// The three maps below partition $lbEach: protein first, then produce, then
// everything else. They are disjoint by construction, so an item can never be
// counted under two of them and the SQL needs no cross-checks.
$produceNames = [];
foreach ($db->query("SELECT DISTINCT generic_name FROM produce_lookup") as $r) {
    $produceNames[(string)$r['generic_name']] = true;
}
$produceLbEach  = [];   // produce sold by the piece  —  green bar
$packagedLbEach = [];   // packaged goods with a weight  —  brown bar
foreach ($lbEach as $n => $w) {
    if (isset($proteinLbEach[$n]))  continue;
    if (isset($produceNames[$n])) { $produceLbEach[$n]  = $w; }
    else                          { $packagedLbEach[$n] = $w; }
}

// SQL fragments so the splits can happen inside the existing rollup query
// rather than in a second pass that would have to be re-grouped by month and
// by channel. With no qualifying items each collapses to a constant false/zero.
$opImpactWeightSql = function (array $map) use ($db) {
    if (!$map) return ['0', '0'];
    $quoted = [];
    $cases  = '';
    foreach ($map as $n => $w) {
        $q        = $db->quote($n);
        $quoted[] = $q;
        $cases   .= ' WHEN ' . $q . ' THEN ' . (float)$w;
    }
    return [
        's.generic_name IN (' . implode(',', $quoted) . ')',
        'CASE s.generic_name' . $cases . ' ELSE 0 END',
    ];
};
[$protIsSql, $protLbEachSql] = $opImpactWeightSql($proteinLbEach);
[$prodIsSql, $prodLbEachSql] = $opImpactWeightSql($produceLbEach);
[$pkgIsSql,  $pkgLbEachSql]  = $opImpactWeightSql($packagedLbEach);

// ── openpantry.db: one row per order that moved food ──────────────────────
// Weighed produce carries its pounds in weight_lbs and a filler quantity of 1,
// so quantity is only meaningful for the non-produce rows — hence the split
// conditional sums rather than a single SUM(quantity). Produce sold by the
// piece is stored with kind='packaged'; where it has an Avg Wt it is converted
// at that weight in prod_lbs, packaged goods with an Avg Wt convert the same way
// in pkg_lbs, and only items with no weight on record land in each_qty.
//
// Every order with at least one scan counts, including one still open at the
// moment the report runs: the food is already out the door, and excluding it
// would silently under-report the current day.
$orderSql = "
    SELECT
        o.id                                                            AS oid,
        strftime('%Y-%m', o.started_at)                                 AS month,
        CAST(strftime('%w', o.started_at) AS INTEGER)                   AS dow,
        o.note                                                          AS note,
        ($channelCase)                                                  AS channel,
        SUM(CASE WHEN $protIsSql OR $prodIsSql OR $pkgIsSql THEN 0
                 WHEN s.kind = 'produce' THEN 0 ELSE s.quantity END)    AS each_qty,
        SUM(CASE WHEN $protIsSql THEN 0
                 WHEN s.kind = 'produce' THEN COALESCE(s.weight_lbs, 0)
                 ELSE 0 END)                                            AS lbs,
        SUM(CASE WHEN $prodIsSql AND s.kind <> 'produce'
                 THEN s.quantity * ($prodLbEachSql) ELSE 0 END)         AS prod_lbs,
        SUM(CASE WHEN $prodIsSql AND s.kind <> 'produce'
                 THEN s.quantity ELSE 0 END)                            AS prod_qty,
        SUM(CASE WHEN $pkgIsSql AND s.kind <> 'produce'
                 THEN s.quantity * ($pkgLbEachSql) ELSE 0 END)          AS pkg_lbs,
        SUM(CASE WHEN $pkgIsSql AND s.kind <> 'produce'
                 THEN s.quantity ELSE 0 END)                            AS pkg_qty,
        SUM(CASE WHEN $protIsSql THEN
                      CASE WHEN s.kind = 'produce' THEN COALESCE(s.weight_lbs, 0)
                           ELSE s.quantity * ($protLbEachSql) END
                 ELSE 0 END)                                            AS prot_lbs,
        SUM(CASE WHEN $protIsSql AND s.kind <> 'produce'
                 THEN s.quantity ELSE 0 END)                            AS prot_qty,
        COUNT(s.id)                                                     AS scans
    FROM orders o
    JOIN scans s ON s.order_id = o.id
    WHERE o.started_at >= :rs AND o.started_at <= :re
    GROUP BY o.id
";
$stmt = $db->prepare($orderSql);
$stmt->execute([':rs' => $rangeStart, ':re' => $rangeEnd]);
$orderRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totOrders   = count($orderRows);
$totEach     = 0;
$totLbs      = 0.0;
$totProtLbs  = 0.0;   // fresh/frozen protein, at each item's own Avg Wt
$totProtQty  = 0;
$totWeighed  = 0.0;   // produce that actually crossed a scale
$totProdLbs  = 0.0;   // produce sold by the piece, at each item's own Avg Wt
$totProdQty  = 0;
$totPkgLbs   = 0.0;   // packaged goods with an Avg Wt, at that weight
$totPkgQty   = 0;
$totScans    = 0;

// Per-month and per-channel rollups, all zero-filled up front so the chart
// arrays line up with $months / $channels without any isset() dancing.
$mLbs    = array_fill_keys($months, 0.0);  // produce: weighed + by the piece
$mEach   = array_fill_keys($months, 0);    // counted items with no Avg Wt
$mPkg    = array_fill_keys($months, 0.0);  // packaged goods at their own Avg Wt
$mProt   = array_fill_keys($months, 0.0);  // fresh/frozen protein pounds
$mOrders = array_fill_keys($months, 0);
$mAdults = array_fill_keys($months, 0);    // from delivery notes (see below)
$mKids   = array_fill_keys($months, 0);
$mChan   = [];
foreach ($channels as $c) $mChan[$c] = array_fill_keys($months, 0);

$chanOrders = array_fill_keys($channels, 0);
$chanEach   = array_fill_keys($channels, 0);
$chanLbs    = array_fill_keys($channels, 0.0);
$chanProt   = array_fill_keys($channels, 0.0);
$chanPkg    = array_fill_keys($channels, 0.0);

$dowOrders  = array_fill(0, 7, 0);         // 0 = Sunday

// Home deliveries are the one openpantry channel that records household size:
// persistDeliveryOrder() ends the note with "· 2A/1C" (delivery/db.php). No
// name, address, or phone is ever written to the order row, so parsing this is
// the whole of the household detail available here — and it is not PII.
$delOrders = 0; $delAdults = 0; $delKids = 0;

foreach ($orderRows as $r) {
    $m   = (string)$r['month'];
    $ch  = (string)$r['channel'];
    $ea  = (int)$r['each_qty'];
    // Produce reports as one figure everywhere on the page: pounds off the
    // scale plus by-the-piece produce at its own Avg Wt. The two halves are
    // kept apart only for the Methodology card, which states each separately.
    $wl  = (float)$r['lbs'];
    $pd  = (float)$r['prod_lbs'];
    $lb  = $wl + $pd;
    $pl  = (float)$r['prot_lbs'];
    $pk  = (float)$r['pkg_lbs'];

    $totEach    += $ea;
    $totLbs     += $lb;
    $totWeighed += $wl;
    $totProdLbs += $pd;
    $totProdQty += (int)$r['prod_qty'];
    $totPkgLbs  += $pk;
    $totPkgQty  += (int)$r['pkg_qty'];
    $totProtLbs += $pl;
    $totProtQty += (int)$r['prot_qty'];
    $totScans   += (int)$r['scans'];

    if (isset($monthIndex[$m])) {
        $mLbs[$m]    += $lb;
        $mEach[$m]   += $ea;
        $mPkg[$m]    += $pk;
        $mProt[$m]   += $pl;
        $mOrders[$m] += 1;
        if (isset($mChan[$ch][$m])) $mChan[$ch][$m] += 1;
    }
    if (isset($chanOrders[$ch])) {
        $chanOrders[$ch] += 1;
        $chanEach[$ch]   += $ea;
        $chanLbs[$ch]    += $lb;
        $chanPkg[$ch]    += $pk;
        $chanProt[$ch]   += $pl;
    }
    $d = (int)$r['dow'];
    if ($d >= 0 && $d <= 6) $dowOrders[$d]++;

    if ($ch === 'Delivery' && preg_match('/(\d+)A\/(\d+)C/', (string)$r['note'], $hm)) {
        $delOrders++;
        $a = (int)$hm[1]; $k = (int)$hm[2];
        $delAdults += $a; $delKids += $k;
        if (isset($monthIndex[$m])) { $mAdults[$m] += $a; $mKids[$m] += $k; }
    }
}

// Distinct service days come from the scans themselves rather than the order
// rows above, so a day is counted once no matter how many stations were open.
$dayStmt = $db->prepare(
    "SELECT COUNT(DISTINCT date(s.scanned_at)) AS days,
            COUNT(DISTINCT s.generic_name)     AS items
     FROM scans s WHERE s.scanned_at >= :rs AND s.scanned_at <= :re"
);
$dayStmt->execute([':rs' => $rangeStart, ':re' => $rangeEnd]);
$dayRow      = $dayStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$serviceDays = (int)($dayRow['days']  ?? 0);
$distinctItems = (int)($dayRow['items'] ?? 0);

// Days the pantry operated with the scanner down or forgotten. Reported openly:
// they are the honest error bar around every count on this page.
$unscannedStmt = $db->prepare(
    "SELECT COUNT(*) FROM unscanned_days WHERE day >= :ds AND day <= :de"
);
$unscannedStmt->execute([':ds' => $dateStart, ':de' => $dateEnd]);
$unscannedDays = (int)$unscannedStmt->fetchColumn();

// Estimated total pounds. Every item with an Avg Wt contributes at that
// weight; only what has no weight on record falls back to $lbPerItem. The
// streams are disjoint by construction (see the three maps above), so nothing
// is double counted and the stacked month chart sums back to this figure.
$estLbs   = $totLbs + $totProtLbs + $totPkgLbs + $totEach * $lbPerItem;
$estMeals = $lbPerMeal > 0 ? (int)round($estLbs / $lbPerMeal) : 0;

// ── Top items ─────────────────────────────────────────────────────────────
// One row per generic name. An item is called produce when most of its scans
// were produce scans — either weighed (kind='produce') or a produce PLU sold by
// the piece (in produce_lookup), matching usage_report.php's category rule —
// and never when the barcode is a store-printed item label. Those
// record as packaged now; the test still earns its keep on rows written while
// the station read the label's embedded weight and filed them as kind='produce'
// without their being produce at all.
$topSql = "
    SELECT
        s.generic_name,
        COUNT(*)                                                          AS scans,
        SUM(CASE WHEN " . storeLabelSql('s.barcode') . " THEN 0
                 WHEN pl.code IS NOT NULL OR s.kind = 'produce'
                 THEN 1 ELSE 0 END)                                       AS produce_scans,
        SUM(CASE WHEN s.kind = 'produce' THEN 0 ELSE s.quantity END)      AS each_qty,
        SUM(CASE WHEN s.kind = 'produce' THEN COALESCE(s.weight_lbs, 0)
                 ELSE 0 END)                                              AS lbs
    FROM scans s
    LEFT JOIN produce_lookup pl ON pl.code = s.barcode
    WHERE s.scanned_at >= :rs AND s.scanned_at <= :re
    GROUP BY s.generic_name
";
$topStmt = $db->prepare($topSql);
$topStmt->execute([':rs' => $rangeStart, ':re' => $rangeEnd]);
$topRows = [];
foreach ($topStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $name = (string)$r['generic_name'];
    $each = (int)$r['each_qty'];
    $lbs  = (float)$r['lbs'];
    // Any item with an Avg Wt converts at it, exactly as in the rollups above;
    // $lbPerItem is the fallback for everything with no weight on record.
    $isProt  = isset($proteinLbEach[$name]);
    $perEach = $lbEach[$name] ?? $lbPerItem;
    $topRows[] = [
        'name'     => $name,
        'category' => $isProt
            ? 'protein'
            : ((((int)$r['produce_scans'] * 2 > (int)$r['scans'])) ? 'produce' : 'packaged'),
        'each'     => $each,
        'lbs'      => $lbs,
        // Ranked on estimated pounds so a case of apples and a case of soup are
        // comparable; the table still shows each measure in its own unit.
        'est_lbs'  => $lbs + $each * $perEach,
    ];
}
usort($topRows, function ($a, $b) { return $b['est_lbs'] <=> $a['est_lbs']; });
$topN = array_slice($topRows, 0, 15);

// Produce only, for the fresh-produce chart. The Top Items Detail table below
// still reports the overall top 15 across all three categories.
$topProduce = array_slice(array_values(array_filter($topRows, function ($r) {
    return $r['category'] === 'produce';
})), 0, 15);

// Protein rolled up to the meat. Several generic names collapse onto one bar —
// Pork Loin Fillet and Pork Chops are both Pork — so this is a handful of bars,
// not fifteen, whenever the pantry stocks fewer than fifteen kinds of meat.
$protGroups = [];
foreach ($topRows as $r) {
    if ($r['category'] !== 'protein') continue;
    $g = $proteinGroup[$r['name']] ?? 'Other';
    if (!isset($protGroups[$g])) {
        $protGroups[$g] = ['name' => $g, 'lbs' => 0.0, 'each' => 0, 'est_lbs' => 0.0, 'items' => 0];
    }
    $protGroups[$g]['lbs']     += $r['lbs'];
    $protGroups[$g]['each']    += $r['each'];
    $protGroups[$g]['est_lbs'] += $r['est_lbs'];
    $protGroups[$g]['items']   += 1;
}
$protN = array_values($protGroups);
usort($protN, function ($a, $b) { return $b['est_lbs'] <=> $a['est_lbs']; });
$protN = array_slice($protN, 0, 15);

// Canned and packaged goods, ranked by count. Everything that is neither
// produce nor fresh protein lands here — the shelf-stable middle of the pantry
// — and it is ranked by the number of items handed out rather than by pounds:
// those are the least certain figures on the page, and a household carries
// home cans and boxes, not pounds of can. Rows with no counted scans are
// skipped so a stray weighed row cannot draw a zero-length bar.
$topPackaged = array_values(array_filter($topRows, function ($r) {
    return $r['category'] === 'packaged' && $r['each'] > 0;
}));
usort($topPackaged, function ($a, $b) { return $b['each'] <=> $a['each']; });
$topPackaged = array_slice($topPackaged, 0, 15);

// ── Sourcing: donated vs purchased ────────────────────────────────────────
// This card reports the food that actually went out the door in the selected
// period — the same scanned pounds every other section counts — split by how
// the pantry came by it. There is no per-scan record of where a particular can
// came from, so each item's distributed pounds are apportioned by that item's
// own lifetime Bought share from the Restock page (the "Bought" column on the
// Inventory page):
//
//   purchased lb = est. lb distributed × restocked_purchased / (purchased + donated)
//   donated   lb = the remainder
//
// An item the Restock page has never recorded as purchased — both counters at
// zero, or purchased at zero — shows "—" or 0% on Inventory and is taken as
// wholly donated, which is how food reaches this pantry by default.
//
// That default is only defensible where *something* has been restocked. On an
// install where the Restock page has never been used at all, every item lands
// in the no-ratio bucket and the arithmetic below would report a confident
// "100% donated" built on no evidence whatsoever — to a page written for
// donors and boards. So the pounds carrying a real ratio are tracked
// separately, and with none of them the card and the hero stat say there is
// nothing to report rather than inventing a figure.
//
// The ratio itself is lifetime (Restock keeps running totals, not a dated log),
// but the pounds it is applied to are the filtered period's, so unlike the old
// all-time restock mix this figure does follow the date filter.
$boughtShare = [];   // generic_name => 0..1, absent when no restock history
foreach ($db->query("SELECT generic_name, restocked_purchased, restocked_donated
                       FROM inventory") as $r) {
    $p = (float)$r['restocked_purchased'];
    $d = (float)$r['restocked_donated'];
    $t = $p + $d;
    if ($t > 0) $boughtShare[(string)$r['generic_name']] = max(0.0, min(1.0, $p / $t));
}

$purchasedLbs = 0.0;
$donatedLbs   = 0.0;
$noRatioLbs   = 0.0;   // pounds with no Bought % on record → booked as donated
$ratioLbs     = 0.0;   // pounds an actual restock ratio was applied to
foreach ($topRows as $r) {
    $lbs = (float)$r['est_lbs'];
    if ($lbs <= 0) continue;
    if (isset($boughtShare[$r['name']])) {
        $share         = $boughtShare[$r['name']];
        $purchasedLbs += $lbs * $share;
        $donatedLbs   += $lbs * (1 - $share);
        $ratioLbs     += $lbs;
    } else {
        $donatedLbs   += $lbs;
        $noRatioLbs   += $lbs;
    }
}
$sourcedLbs   = $purchasedLbs + $donatedLbs;
// Not "did anything go out the door" but "is any of it backed by restock
// history" — the whole split is guesswork without at least one item's ratio.
$hasSourcing  = $ratioLbs > 0;
$donatedPct   = $hasSourcing ? round(100 * $donatedLbs / $sourcedLbs) : 0;
$noRatioPct   = $sourcedLbs > 0 ? round(100 * $noRatioLbs / $sourcedLbs) : 0;

// ── Delivery roster (no PII) ──────────────────────────────────────────────
// Counts and household sizes only. Name/address/phone are never read here —
// address, city and phone are encrypted at rest anyway (crypto.php).
$clientRow = $db->query("
    SELECT COUNT(*) AS total,
           COALESCE(SUM(CASE WHEN enabled = 1 THEN 1 ELSE 0 END), 0) AS active,
           COALESCE(SUM(adults), 0)   AS adults,
           COALESCE(SUM(children), 0) AS children
    FROM delivery_clients
")->fetch(PDO::FETCH_ASSOC) ?: [];
$clientTotal  = (int)($clientRow['total'] ?? 0);
$clientActive = (int)($clientRow['active'] ?? 0);
$clientPeople = (int)($clientRow['adults'] ?? 0) + (int)($clientRow['children'] ?? 0);

// ── picklist.db: counter requests and how much of them got filled ─────────
// Optional section: a site running FoodScan without PantryPrep has no
// picklist.db, and picklistDB() returns null rather than fataling.
$pdb = picklistDB();
$hasPicklist = false;
$reqOrders = 0; $reqHouseholds = 0; $reqAdults = 0; $reqKids = 0;
$reqUnits = 0; $reqFilled = 0;
$mReqOrders = array_fill_keys($months, 0);
$mReqAdults = array_fill_keys($months, 0);
$mReqKids   = array_fill_keys($months, 0);
$catRows = [];

if ($pdb !== null) {
    try {
        // NOTE ON TIME: picklist orders.created_at is written by SQLite's
        // CURRENT_TIMESTAMP, which is UTC, while openpantry timestamps are
        // Eastern (PHP writes them). At month granularity the difference only
        // moves late-evening orders across a month boundary, so the existing
        // convention from menucounter/reports/orders_listing.php — compare the stored
        // date directly — is kept rather than applying a DST-fragile offset.
        $q = $pdb->prepare("
            SELECT o.id                              AS oid,
                   LOWER(TRIM(o.name))               AS household,
                   COALESCE(o.adults, 0)             AS adults,
                   COALESCE(o.children, 0)           AS children,
                   strftime('%Y-%m', o.created_at)   AS month
            FROM orders o
            WHERE DATE(o.created_at) BETWEEN :ds AND :de
        ");
        $q->execute([':ds' => $dateStart, ':de' => $dateEnd]);
        $seen = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $reqOrders++;
            $a = (int)$r['adults']; $k = (int)$r['children'];
            $reqAdults += $a; $reqKids += $k;
            // Names are read only to count distinct households and are never
            // displayed or stored anywhere on this page.
            $h = (string)$r['household'];
            if ($h !== '' && !isset($seen[$h])) { $seen[$h] = true; $reqHouseholds++; }
            $m = (string)$r['month'];
            if (isset($monthIndex[$m])) {
                $mReqOrders[$m]++;
                $mReqAdults[$m] += $a;
                $mReqKids[$m]   += $k;
            }
        }

        // order_items holds one row per unit requested (submit_order.php inserts
        // family_factor × household size rows per item), so COUNT(*) is units
        // requested and SUM(completed) is units actually handed over. Item and
        // category names come from the live config row when the request is
        // still linked to one, so a renamed item reports under its current name.
        $cq = $pdb->prepare("
            SELECT COALESCE(ci.category,  oi.category)  AS category,
                   COALESCE(ci.item_name, oi.item_name) AS item_name,
                   COUNT(*)                             AS requested,
                   COALESCE(SUM(oi.completed), 0)       AS filled
            FROM order_items oi
            JOIN orders o ON o.id = oi.order_id
            LEFT JOIN config_items ci ON ci.id = oi.config_item_id
            WHERE DATE(o.created_at) BETWEEN :ds AND :de
            GROUP BY category, item_name
            ORDER BY requested DESC
        ");
        $cq->execute([':ds' => $dateStart, ':de' => $dateEnd]);
        $byCat = [];
        foreach ($cq->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $c = (string)$r['category'];
            if (!isset($byCat[$c])) $byCat[$c] = ['category' => $c, 'requested' => 0, 'filled' => 0, 'items' => 0];
            $byCat[$c]['requested'] += (int)$r['requested'];
            $byCat[$c]['filled']    += (int)$r['filled'];
            $byCat[$c]['items']     += 1;
            $reqUnits  += (int)$r['requested'];
            $reqFilled += (int)$r['filled'];
        }
        uasort($byCat, function ($a, $b) { return $b['requested'] <=> $a['requested']; });
        $catRows = array_values($byCat);
        $hasPicklist = true;
    } catch (\PDOException $e) {
        // A picklist.db that exists but predates these tables shouldn't take the
        // whole report down — the openpantry sections stand on their own.
        $hasPicklist = false;
    }
}
$fillPct = $reqUnits > 0 ? round(100 * $reqFilled / $reqUnits) : 0;

// People reached, person-visits: every service event counted at its household
// size. Only the two channels that record household size contribute (home
// delivery notes and counter requests), so this understates total reach.
$peopleVisits = $delAdults + $delKids + $reqAdults + $reqKids;

$hasData = $totOrders > 0 || $reqOrders > 0;

// ── Formatting helpers ────────────────────────────────────────────────────
function opN($v, int $dec = 0): string { return number_format((float)$v, $dec); }
function opPct(float $part, float $whole): string {
    return $whole > 0 ? round(100 * $part / $whole) . '%' : '—';
}

renderHead('Impact Report');
renderNav('impact');
?>
<style>
  /* The hero band is the one place this report departs from the shared card
     styling: an impact summary is meant to be read across the room / printed
     onto the first page of a grant packet. Brand tokens only, no new palette. */
  .impact-hero {
    background: linear-gradient(135deg, #6B4C11 0%, #8B6A22 55%, #8BAF3A 100%);
    color: #fff; border-radius: 12px; padding: 26px 24px; margin-bottom: 20px;
    box-shadow: 0 3px 14px rgba(0,0,0,.14);
  }
  .impact-hero h2 { color:#fff; border:none; margin:0 0 4px; font-size:1.05rem; letter-spacing:.6px; }
  .impact-hero .period { font-size:.8rem; opacity:.85; margin-bottom:18px; }
  .hero-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(150px,1fr)); gap:16px; }
  .hero-stat { background:rgba(255,255,255,.13); border:1px solid rgba(255,255,255,.22);
               border-radius:10px; padding:14px 12px; text-align:center; }
  .hero-stat .v { font-size:1.9rem; font-weight:800; line-height:1.1; }
  .hero-stat .k { font-size:.7rem; text-transform:uppercase; letter-spacing:.6px;
                  opacity:.9; margin-top:5px; }
  .hero-stat .sub { font-size:.66rem; opacity:.75; margin-top:3px; }

  .lede { font-size:.9rem; color:#555; line-height:1.55; }
  .lede strong { color:var(--brown); }
  .chart-wrap { padding:10px 4px; position:relative; }
  canvas { max-width:100%; }
  .no-data { padding:40px; text-align:center; color:#999; font-size:.9rem; }
  .two-up { display:grid; grid-template-columns:repeat(auto-fit, minmax(300px,1fr)); gap:20px; }
  .two-up .card { margin-bottom:0; }
  .pill { display:inline-block; font-size:.68rem; font-weight:700; text-transform:uppercase;
          letter-spacing:.5px; color:#fff; border-radius:4px; padding:2px 7px; }
  .pill.produce  { background: var(--green); }
  .pill.packaged { background: var(--brown); }
  .pill.protein  { background: #A2453C; }
  .total-row td { font-weight:700; background:var(--cat-bg); border-top:2px solid var(--border); }
  .meter { height:9px; border-radius:5px; background:var(--cat-bg); overflow:hidden; min-width:70px; }
  .meter > span { display:block; height:100%; background:var(--green); border-radius:5px; }
  .note-list { font-size:.82rem; color:#666; line-height:1.6; padding-left:18px; }
  .note-list li { margin-bottom:6px; }
  @media print {
    .site-header, nav.subnav, .filter-card, .btn, form { display:none; }
    .impact-hero { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    .card { break-inside:avoid; page-break-inside:avoid; }
  }
</style>

<div class="container">
  <form method="GET" id="reportForm">
    <div class="card filter-card">
      <h2>🔍 Reporting Period &amp; Assumptions</h2>
      <div class="row">
        <div>
          <label for="date_start">Start Date</label>
          <input type="date" id="date_start" name="date_start" value="<?= htmlspecialchars($dateStart) ?>">
        </div>
        <div>
          <label for="date_end">End Date</label>
          <input type="date" id="date_end" name="date_end" value="<?= htmlspecialchars($dateEnd) ?>">
        </div>
        <div>
          <label for="lb_per_item" title="Used only where counted items must share an axis with weighed produce.">Avg lb per Packaged Item</label>
          <input type="number" step="0.1" min="0.1" max="10" id="lb_per_item" name="lb_per_item" value="<?= htmlspecialchars((string)$lbPerItem) ?>">
        </div>
        <div>
          <label for="lb_per_meal" title="Feeding America uses 1.2 lb per meal.">lb per Meal</label>
          <input type="number" step="0.05" min="0.25" max="10" id="lb_per_meal" name="lb_per_meal" value="<?= htmlspecialchars((string)$lbPerMeal) ?>">
        </div>
      </div>
      <div class="row" style="margin-top:14px;">
        <button type="submit" class="btn btn-primary" style="flex:0 0 170px;">📊 Run Report</button>
        <a href="" class="btn btn-secondary" style="flex:0 0 100px; text-align:center; text-decoration:none;">↺ Reset</a>
        <?php if ($hasData): ?>
          <button type="button" class="btn btn-secondary" style="flex:0 0 100px;" onclick="window.print()">🖨 Print</button>
        <?php endif; ?>
      </div>
    </div>
  </form>

  <?php if (!$hasData): ?>
    <div class="card"><div class="no-data">No food distribution or counter requests recorded between
      <?= htmlspecialchars(date('M j, Y', strtotime($dateStart))) ?> and
      <?= htmlspecialchars(date('M j, Y', strtotime($dateEnd))) ?>.</div></div>
  <?php else: ?>

  <div class="impact-hero">
    <h2>OUR IMPACT</h2>
    <div class="period">
      <?= htmlspecialchars(date('F j, Y', strtotime($dateStart))) ?> &ndash;
      <?= htmlspecialchars(date('F j, Y', strtotime($dateEnd))) ?>
      &nbsp;·&nbsp; <?= opN($serviceDays) ?> days of service
    </div>
    <div class="hero-grid">
      <div class="hero-stat">
        <div class="v"><?= opN($estLbs) ?></div>
        <div class="k">Pounds of Food</div>
        <div class="sub">estimated total</div>
      </div>
      <div class="hero-stat">
        <div class="v"><?= opN($estMeals) ?></div>
        <div class="k">Meals Provided</div>
        <div class="sub"><?= htmlspecialchars((string)$lbPerMeal) ?> lb per meal</div>
      </div>
      <div class="hero-stat">
        <div class="v"><?= opN($totOrders) ?></div>
        <div class="k">Households Served</div>
        <div class="sub">visits &amp; deliveries</div>
      </div>
      <div class="hero-stat">
        <div class="v"><?= opN($peopleVisits) ?></div>
        <div class="k">People Reached</div>
        <div class="sub">person-visits, where recorded</div>
      </div>
      <div class="hero-stat">
        <div class="v"><?= $hasSourcing ? $donatedPct . '%' : '&mdash;' ?></div>
        <div class="k">Food Donated</div>
        <div class="sub"><?= $hasSourcing
          ? 'of pounds distributed' : 'no restock history yet' ?></div>
      </div>
      <div class="hero-stat">
        <div class="v"><?= opN($distinctItems) ?></div>
        <div class="k">Distinct Items</div>
        <div class="sub">variety offered</div>
      </div>
    </div>
  </div>

  <div class="card">
    <h2>By the Numbers</h2>
    <div class="stat-grid">
      <div class="stat"><div class="v"><?= opN($totLbs) ?></div><div class="k">Produce lb</div></div>
      <div class="stat"><div class="v"><?= opN($totProtLbs) ?></div><div class="k">Protein lb (est.)</div></div>
      <div class="stat"><div class="v"><?= opN($totEach) ?></div><div class="k">Items (counted)</div></div>
      <div class="stat"><div class="v"><?= opN($totScans) ?></div><div class="k">Scans Recorded</div></div>
      <div class="stat"><div class="v"><?= $totOrders > 0 ? opN($estLbs / $totOrders, 1) : '—' ?></div><div class="k">Est. lb per Household</div></div>
      <div class="stat"><div class="v"><?= $serviceDays > 0 ? opN($totOrders / $serviceDays, 1) : '—' ?></div><div class="k">Households per Day</div></div>
      <div class="stat"><div class="v"><?= opN($delOrders) ?></div><div class="k">Home Deliveries</div></div>
      <div class="stat"><div class="v"><?= opN($clientActive) ?></div><div class="k">Active Delivery Clients</div></div>
      <div class="stat"><div class="v"><?= opN($clientPeople) ?></div><div class="k">People on Delivery Roster</div></div>
    </div>
  </div>

  <div class="card">
    <h2>📈 Food Distributed by Month</h2>
    <p class="lede" style="margin-bottom:10px;">
      Bars split the estimated total three ways: <strong>produce</strong>,
      <strong>fresh &amp; frozen protein</strong>, and <strong>packaged
      goods</strong>. Every item with an <strong>Avg Wt (lb ea)</strong> on the
      Inventory page is converted at that weight; only items with no weight on
      record fall back to <?= htmlspecialchars((string)$lbPerItem) ?> lb each.
      The three are mutually exclusive, so nothing is counted twice. The line is households
      served, on its own axis &mdash; when it tracks the bars, each household is
      getting a steady amount rather than a shrinking share.
    </p>
    <div class="chart-wrap" style="height:360px;"><canvas id="monthChart"></canvas></div>
  </div>

  <div class="two-up" style="margin-bottom:20px;">
    <div class="card">
      <h2>🚪 How People Reach Us</h2>
      <p class="lede" style="margin-bottom:10px;">
        Households served each month by channel. Growth in delivery and events is
        reach the pantry doorway alone would not have.
      </p>
      <div class="chart-wrap" style="height:320px;"><canvas id="channelChart"></canvas></div>
    </div>
    <div class="card">
      <h2>📦 Cumulative Pounds</h2>
      <p class="lede" style="margin-bottom:10px;">
        Running total of estimated pounds across the period &mdash; the single
        line most funders want as the summary of the year.
      </p>
      <div class="chart-wrap" style="height:320px;"><canvas id="cumChart"></canvas></div>
    </div>
  </div>

  <div class="card">
    <h2>🥕 Top Fresh Produce Items Distributed</h2>
    <p class="lede" style="margin-bottom:10px;">
      Fresh produce only, ranked by pounds.
    </p>
    <?php if ($topProduce): ?>
      <div class="chart-wrap" style="height:<?= max(280, count($topProduce) * 26 + 70) ?>px;"><canvas id="topChart"></canvas></div>
    <?php else: ?>
      <div class="no-data">No produce scanned in this period.</div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>🥩 Top Fresh or Frozen Protein Items Distributed</h2>
    <p class="lede" style="margin-bottom:10px;">
      Meat and fish, ranked by pounds by using the average weight per item.
    </p>
    <?php if ($protN): ?>
      <div class="chart-wrap" style="height:<?= max(240, count($protN) * 32 + 70) ?>px;"><canvas id="proteinChart"></canvas></div>
    <?php else: ?>
      <div class="no-data">
        No fresh or frozen protein recorded in this period. Set an
        <strong>Avg Wt (lb ea)</strong> on the Inventory page for the meat and
        fish the pantry hands out, and it will appear here.
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>🥫 Top Canned or Packaged Items Distributed</h2>
    <p class="lede" style="margin-bottom:10px;">
      Canned and packaged goods only, ranked by count.
    </p>
    <?php if ($topPackaged): ?>
      <div class="chart-wrap" style="height:<?= max(280, count($topPackaged) * 26 + 70) ?>px;"><canvas id="packagedChart"></canvas></div>
    <?php else: ?>
      <div class="no-data">No canned or packaged goods scanned in this period.</div>
    <?php endif; ?>
  </div>

  <div class="two-up" style="margin-bottom:20px;">
    <div class="card">
      <h2>📅 Weekly Rhythm</h2>
      <p class="lede" style="margin-bottom:10px;">
        Households served by day of week &mdash; the operating pattern behind the
        volunteer schedule.
      </p>
      <div class="chart-wrap" style="height:290px;"><canvas id="dowChart"></canvas></div>
    </div>
    <div class="card">
      <h2>🤝 Where the Food Comes From</h2>
      <p class="lede" style="margin-bottom:10px;">
        The pounds handed out in this period, split by how the pantry came by
        them: each item's estimated pounds are apportioned at its
        <strong>Bought&nbsp;%</strong> on the Inventory page, and anything with no
        purchase on record counts as donated.
      </p>
      <?php if ($hasSourcing): ?>
        <div class="chart-wrap" style="height:290px;"><canvas id="srcChart"></canvas></div>
      <?php elseif ($sourcedLbs > 0): ?>
        <div class="no-data">No restock history recorded yet, so the pounds handed
          out in this period can't be split by source.</div>
      <?php else: ?>
        <div class="no-data">No food scanned out in this period.</div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($hasPicklist && ($reqOrders > 0 || $reqUnits > 0)): ?>
  <div class="card">
    <h2>📝 Counter Requests &amp; Fill Rate <span style="font-weight:400; text-transform:none; color:#999; font-size:.8rem;">— from picklist.db</span></h2>
    <p class="lede" style="margin-bottom:14px;">
      What households asked for at the order counter, and how much of it the
      pantry was able to hand over. Each requested <em>unit</em> is one row, so the
      fill rate is measured in actual goods, not in checkboxes. This is a separate
      flow from the scanned distribution above and is never added to it.
    </p>
    <div class="stat-grid">
      <div class="stat"><div class="v"><?= opN($reqOrders) ?></div><div class="k">Requests</div></div>
      <div class="stat"><div class="v"><?= opN($reqHouseholds) ?></div><div class="k">Unique Households</div></div>
      <div class="stat"><div class="v"><?= opN($reqAdults) ?></div><div class="k">Adults (person-visits)</div></div>
      <div class="stat"><div class="v"><?= opN($reqKids) ?></div><div class="k">Children (person-visits)</div></div>
      <div class="stat"><div class="v"><?= opN($reqUnits) ?></div><div class="k">Units Requested</div></div>
      <div class="stat"><div class="v"><?= $fillPct ?>%</div><div class="k">Fill Rate</div></div>
    </div>
  </div>

  <div class="two-up" style="margin-bottom:20px;">
    <div class="card">
      <h2>🧺 What Households Ask For</h2>
      <div class="chart-wrap" style="height:300px;"><canvas id="catChart"></canvas></div>
    </div>
    <div class="card">
      <h2>✅ Fill Rate by Category</h2>
      <p class="lede" style="margin-bottom:10px;">
        Share of requested units actually filled. A persistently low bar is the
        category where more supply would do the most good.
      </p>
      <div class="chart-wrap" style="height:300px;"><canvas id="fillChart"></canvas></div>
    </div>
  </div>

  <div class="card">
    <h2>👪 People Reached Each Month</h2>
    <p class="lede" style="margin-bottom:10px;">
      Adults and children in the households served, counted once per service
      event (person-visits). Only home deliveries and counter requests record
      household size, so this is a floor on the pantry's real reach, not a ceiling.
    </p>
    <div class="chart-wrap" style="height:320px;"><canvas id="peopleChart"></canvas></div>
  </div>
  <?php endif; ?>

  <div class="card">
    <h2>📋 Monthly Detail</h2>
    <table class="data">
      <thead>
        <tr>
          <th>Month</th>
          <th class="num">Households</th>
          <th class="num">Produce lb</th>
          <th class="num">Protein lb</th>
          <th class="num">Items</th>
          <th class="num">Est. lb</th>
          <th class="num">Est. Meals</th>
          <?php if ($hasPicklist): ?><th class="num">Requests</th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($months as $i => $m):
          $eLbs = $mLbs[$m] + $mProt[$m] + $mPkg[$m] + $mEach[$m] * $lbPerItem; ?>
        <tr>
          <td><?= htmlspecialchars($monthLabels[$i]) ?></td>
          <td class="num"><?= opN($mOrders[$m]) ?></td>
          <td class="num"><?= opN($mLbs[$m], 1) ?></td>
          <td class="num"><?= opN($mProt[$m], 1) ?></td>
          <td class="num"><?= opN($mEach[$m]) ?></td>
          <td class="num"><?= opN($eLbs) ?></td>
          <td class="num"><?= $lbPerMeal > 0 ? opN($eLbs / $lbPerMeal) : '—' ?></td>
          <?php if ($hasPicklist): ?><td class="num"><?= opN($mReqOrders[$m]) ?></td><?php endif; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr class="total-row">
          <td>Total</td>
          <td class="num"><?= opN($totOrders) ?></td>
          <td class="num"><?= opN($totLbs, 1) ?></td>
          <td class="num"><?= opN($totProtLbs, 1) ?></td>
          <td class="num"><?= opN($totEach) ?></td>
          <td class="num"><?= opN($estLbs) ?></td>
          <td class="num"><?= opN($estMeals) ?></td>
          <?php if ($hasPicklist): ?><td class="num"><?= opN($reqOrders) ?></td><?php endif; ?>
        </tr>
      </tfoot>
    </table>
  </div>

  <div class="card">
    <h2>🚚 Service Channels</h2>
    <table class="data">
      <thead>
        <tr>
          <th>Channel</th>
          <th class="num">Households</th>
          <th class="num">Share</th>
          <th class="num">Produce lb</th>
          <th class="num">Protein lb</th>
          <th class="num">Items</th>
          <th class="num">Est. lb</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($channels as $c):
          if ($chanOrders[$c] === 0) continue;
          $cEst = $chanLbs[$c] + $chanProt[$c] + $chanPkg[$c] + $chanEach[$c] * $lbPerItem; ?>
        <tr>
          <td>
            <span class="pill" style="background:<?= htmlspecialchars($channelColor[$c]) ?>;"><?= htmlspecialchars($c) ?></span>
            &nbsp;<?= htmlspecialchars($channelLabel[$c]) ?>
          </td>
          <td class="num"><?= opN($chanOrders[$c]) ?></td>
          <td class="num"><?= opPct((float)$chanOrders[$c], (float)$totOrders) ?></td>
          <td class="num"><?= opN($chanLbs[$c], 1) ?></td>
          <td class="num"><?= opN($chanProt[$c], 1) ?></td>
          <td class="num"><?= opN($chanEach[$c]) ?></td>
          <td class="num"><?= opN($cEst) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="card">
    <h2>🏆 Top Items Detail</h2>
    <table class="data">
      <thead>
        <tr>
          <th>Item</th>
          <th>Type</th>
          <th class="num">Weighed lb</th>
          <th class="num">Counted</th>
          <th class="num">Est. lb</th>
          <th class="num">Share</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($topN as $r): ?>
        <tr>
          <td><?= htmlspecialchars($r['name']) ?></td>
          <td><span class="pill <?= htmlspecialchars($r['category']) ?>"><?= htmlspecialchars($r['category']) ?></span></td>
          <td class="num"><?= $r['lbs']  > 0 ? opN($r['lbs'], 1) : '—' ?></td>
          <td class="num"><?= $r['each'] > 0 ? opN($r['each'])   : '—' ?></td>
          <td class="num"><?= opN($r['est_lbs']) ?></td>
          <td class="num"><?= opPct($r['est_lbs'], $estLbs) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($hasPicklist && !empty($catRows)): ?>
  <div class="card">
    <h2>📊 Requests by Category</h2>
    <table class="data">
      <thead>
        <tr>
          <th>Category</th>
          <th class="num">Distinct Items</th>
          <th class="num">Units Requested</th>
          <th class="num">Units Filled</th>
          <th>Fill Rate</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($catRows as $r):
          $pct = $r['requested'] > 0 ? round(100 * $r['filled'] / $r['requested']) : 0; ?>
        <tr>
          <td><?= htmlspecialchars($r['category']) ?></td>
          <td class="num"><?= opN($r['items']) ?></td>
          <td class="num"><?= opN($r['requested']) ?></td>
          <td class="num"><?= opN($r['filled']) ?></td>
          <td>
            <div style="display:flex; align-items:center; gap:8px;">
              <div class="meter" style="flex:1;"><span style="width:<?= $pct ?>%;"></span></div>
              <strong style="color:var(--brown); font-size:.85rem;"><?= $pct ?>%</strong>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr class="total-row">
          <td colspan="2">All Categories</td>
          <td class="num"><?= opN($reqUnits) ?></td>
          <td class="num"><?= opN($reqFilled) ?></td>
          <td><?= $fillPct ?>%</td>
        </tr>
      </tfoot>
    </table>
  </div>
  <?php endif; ?>

  <div class="card">
    <h2>📐 Methodology &amp; Caveats</h2>
    <ul class="note-list">
      <li><strong>Two databases, kept apart.</strong> Distribution figures come from
        <code>openpantry.db</code> (orders + scans &mdash; food that left the building).
        Request and fill-rate figures come from <code>picklist.db</code> (the PantryPrep
        counter order form). They are different flows and are never summed together.</li>
      <li><strong>Pounds are part measured, part estimated.</strong>
        <?= opN($totWeighed) ?> lb of produce actually crossed a scale. A further
        <?= opN($totProdQty) ?> pieces of produce sold by the piece
        (<?= opN($totProdLbs) ?> lb) were counted and converted at each item's own
        Avg Wt from the Inventory page; the two together are the produce figure
        shown everywhere on this page. Another <?= opN($totPkgQty) ?> packaged
        items (<?= opN($totPkgLbs) ?> lb) likewise carry an Avg Wt and convert at
        it. Only the remaining
        <?= opN($totEach) ?> items have no per-piece weight on record; those are
        converted at
        <strong><?= htmlspecialchars((string)$lbPerItem) ?> lb each</strong>. Change that
        figure at the top of the page and every estimate that still depends on it
        follows &mdash; setting an Avg Wt on the Inventory page is what takes an
        item off that assumption for good.</li>
      <li><strong>Fresh and frozen protein is weighed at its own Avg Wt.</strong>
        <?= opN($totProtQty) ?> pieces of meat and fish
        (<?= opN($totProtLbs) ?> lb, <?= opPct($totProtLbs, $estLbs) ?> of the
        estimated total) carry a per-piece weight set on the Inventory page and
        are converted at that figure instead of the generic
        <?= htmlspecialchars((string)$lbPerItem) ?> lb. An item qualifies when its
        name says what animal it is &mdash; chicken, turkey, beef, veal, pork,
        ham, bacon, lamb, duck and the other common meats, a fish species such
        as cod, haddock, pollack or salmon, or a bare hot dog or sausage &mdash;
        and it has an Avg Wt, and its name does not contain <em>can</em>, so
        canned chicken, canned tuna and chicken-noodle soup stay in the
        counted-items bucket. These pounds are subtracted from that bucket,
        never added on top of it.</li>
      <li><strong>Meals are a conversion, not a count.</strong> Estimated pounds ÷
        <strong><?= htmlspecialchars((string)$lbPerMeal) ?> lb per meal</strong>
        (1.2 lb is the Feeding America convention). No one counts meals directly.</li>
      <li><strong>"Households served" means service events, not distinct families.</strong>
        A household that visits weekly is counted each visit. Only the counter-request
        section reports distinct households, and only for the requests it covers.</li>
      <li><strong>People reached is a floor.</strong> Household size is recorded only for
        home deliveries and counter requests; in-pantry shopping and event orders carry no
        household count, so real reach is higher than the figure shown.</li>
      <li><strong>Sourcing is apportioned, not logged per scan.</strong> Nothing
        records where a particular can came from, so the pounds distributed in this period
        are split at each item's <strong>Bought&nbsp;%</strong> &mdash; its lifetime
        <em>purchased &divide; (purchased + donated)</em> from the Restock page, the same
        figure the Inventory page shows. An item with no purchase on record (Bought shows
        &ldquo;&mdash;&rdquo; or 0%) is taken as wholly donated<?= $noRatioLbs > 0
          ? ', which covers ' . $noRatioPct . '% of the pounds here' : '' ?>.
        <?= $hasSourcing ? '' : 'No item has any restock history at all, so no split is'
          . ' shown above rather than reporting everything as donated on no evidence. ' ?>The
        pounds follow the date filter; the ratio applied to them is lifetime, because
        Restock keeps running totals rather than a dated log.</li>
      <?php if ($unscannedDays > 0): ?>
      <li><strong><?= opN($unscannedDays) ?> operating day<?= $unscannedDays === 1 ? '' : 's' ?>
        in this period went unscanned</strong> (recorded in <code>unscanned_days</code>).
        Food moved on those days with no record of it, so every distribution count on this
        page is an undercount by roughly that share of the period.</li>
      <?php else: ?>
      <li><strong>No unscanned operating days</strong> are recorded in this period, so scan
        coverage is complete as far as the pantry logged it.</li>
      <?php endif; ?>
      <li><strong>No personal information appears in this report.</strong> Order rows never
        store client names or addresses; delivery notes carry only a client number and a
        household size. Household names in <code>picklist.db</code> are read solely to count
        distinct households and are never displayed.</li>
    </ul>
  </div>

  <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
  <script>
  (function () {
    if (typeof Chart === 'undefined') return; // offline / CDN blocked: tables still stand

    var BROWN = '#6B4C11', GREEN = '#8BAF3A', GRID = '#F0EBD8', MEAT = '#A2453C';
    Chart.defaults.font.size = 11;
    Chart.defaults.color = '#555';

    var monthLabels = <?= json_encode($monthLabels) ?>;
    var produceLbs  = <?= json_encode(array_map(function ($v) { return round($v, 1); }, array_values($mLbs))) ?>;
    var packagedLbs = <?= json_encode(array_map(function ($m) use ($mEach, $mPkg, $lbPerItem) {
        return round($mPkg[$m] + $mEach[$m] * $lbPerItem, 1);
    }, $months)) ?>;
    var proteinLbs  = <?= json_encode(array_map(function ($v) { return round($v, 1); }, array_values($mProt))) ?>;
    var monthOrders = <?= json_encode(array_values($mOrders)) ?>;

    new Chart(document.getElementById('monthChart'), {
      data: {
        labels: monthLabels,
        datasets: [
          { type:'bar', label:'Produce (lb)', data:produceLbs, backgroundColor:GREEN,
            stack:'lb', borderRadius:3, order:2 },
          { type:'bar', label:'Fresh/frozen protein (est. lb)', data:proteinLbs, backgroundColor:MEAT,
            stack:'lb', borderRadius:3, order:2 },
          { type:'bar', label:'Packaged (est. lb)', data:packagedLbs, backgroundColor:BROWN,
            stack:'lb', borderRadius:3, order:2 },
          { type:'line', label:'Households served', data:monthOrders, yAxisID:'y1',
            borderColor:'#2F6FA1', backgroundColor:'#2F6FA1', borderWidth:2.5,
            pointRadius:3, tension:0.3, fill:false, order:1 }
        ]
      },
      options: {
        responsive:true, maintainAspectRatio:false,
        interaction:{ mode:'index', intersect:false },
        plugins:{ legend:{ position:'top' } },
        scales:{
          x:{ stacked:true, grid:{ color:GRID }, ticks:{ maxRotation:45 } },
          y:{ stacked:true, beginAtZero:true, grid:{ color:GRID },
              title:{ display:true, text:'Pounds', color:BROWN, font:{ weight:'bold' } } },
          y1:{ position:'right', beginAtZero:true, grid:{ display:false },
               title:{ display:true, text:'Households', color:'#2F6FA1', font:{ weight:'bold' } } }
        }
      }
    });

    new Chart(document.getElementById('channelChart'), {
      type:'bar',
      data: {
        labels: monthLabels,
        datasets: <?= json_encode(array_values(array_filter(array_map(function ($c) use ($mChan, $channelColor, $channelLabel, $chanOrders) {
            if ($chanOrders[$c] === 0) return null;
            return [
                'label'           => $channelLabel[$c],
                'data'            => array_values($mChan[$c]),
                'backgroundColor' => $channelColor[$c],
                'borderRadius'    => 3,
            ];
        }, $channels)))) ?>
      },
      options: {
        responsive:true, maintainAspectRatio:false,
        interaction:{ mode:'index', intersect:false },
        plugins:{ legend:{ position:'top' } },
        scales:{
          x:{ stacked:true, grid:{ color:GRID }, ticks:{ maxRotation:45 } },
          y:{ stacked:true, beginAtZero:true, grid:{ color:GRID }, ticks:{ precision:0 },
              title:{ display:true, text:'Households served', color:BROWN, font:{ weight:'bold' } } }
        }
      }
    });

    var running = 0;
    var cumulative = produceLbs.map(function (v, i) {
      running += v + proteinLbs[i] + packagedLbs[i];
      return Math.round(running);
    });
    new Chart(document.getElementById('cumChart'), {
      type:'line',
      data:{ labels: monthLabels, datasets:[
        { label:'Cumulative est. lb', data:cumulative, borderColor:BROWN,
          backgroundColor:'rgba(139,175,58,.28)', borderWidth:2.5,
          pointRadius:2.5, tension:0.25, fill:true }
      ]},
      options:{
        responsive:true, maintainAspectRatio:false,
        plugins:{ legend:{ display:false } },
        scales:{
          x:{ grid:{ color:GRID }, ticks:{ maxRotation:45 } },
          y:{ beginAtZero:true, grid:{ color:GRID },
              title:{ display:true, text:'Pounds (cumulative)', color:BROWN, font:{ weight:'bold' } } }
        }
      }
    });

    var top = <?= json_encode(array_map(function ($r) {
        return [
            'name'  => $r['name'],
            'value' => round($r['est_lbs'], 1),
            'cat'   => $r['category'],
            'lbs'   => round($r['lbs'], 1),
            'each'  => $r['each'],
        ];
    }, $topProduce)) ?>;
    if (top.length) new Chart(document.getElementById('topChart'), {
      type:'bar',
      data:{
        labels: top.map(function (r) { return r.name; }),
        datasets:[{
          label:'Est. lb',
          data: top.map(function (r) { return r.value; }),
          backgroundColor: GREEN,
          borderRadius:4
        }]
      },
      options:{
        indexAxis:'y', responsive:true, maintainAspectRatio:false,
        plugins:{
          legend:{ display:false },
          tooltip:{ callbacks:{ label: function (c) {
            var r = top[c.dataIndex], parts = [];
            if (r.lbs  > 0) parts.push(r.lbs + ' lb weighed');
            if (r.each > 0) parts.push(r.each + ' counted');
            return r.value + ' est. lb (' + parts.join(' + ') + ')';
          } } }
        },
        scales:{
          x:{ beginAtZero:true, grid:{ color:GRID },
              title:{ display:true, text:'Estimated pounds', color:BROWN, font:{ weight:'bold' } } },
          y:{ grid:{ display:false } }
        }
      }
    });

    // Fresh/frozen protein, rolled up to the meat. Same axis and tooltip shape
    // as the produce chart above so the two read as a pair.
    var prot = <?= json_encode(array_map(function ($r) {
        return [
            'name'  => $r['name'],
            'value' => round($r['est_lbs'], 1),
            'lbs'   => round($r['lbs'], 1),
            'each'  => $r['each'],
            'items' => $r['items'],
        ];
    }, $protN)) ?>;
    if (prot.length) new Chart(document.getElementById('proteinChart'), {
      type:'bar',
      data:{
        labels: prot.map(function (r) { return r.name; }),
        datasets:[{
          label:'Est. lb',
          data: prot.map(function (r) { return r.value; }),
          backgroundColor: MEAT,
          borderRadius:4
        }]
      },
      options:{
        indexAxis:'y', responsive:true, maintainAspectRatio:false,
        plugins:{
          legend:{ display:false },
          tooltip:{ callbacks:{ label: function (c) {
            var r = prot[c.dataIndex], parts = [];
            if (r.each > 0) parts.push(r.each + ' pieces');
            if (r.lbs  > 0) parts.push(r.lbs + ' lb weighed');
            parts.push(r.items + (r.items === 1 ? ' item' : ' items'));
            return r.value + ' est. lb (' + parts.join(', ') + ')';
          } } }
        },
        scales:{
          x:{ beginAtZero:true, grid:{ color:GRID },
              title:{ display:true, text:'Estimated pounds', color:BROWN, font:{ weight:'bold' } } },
          y:{ grid:{ display:false } }
        }
      }
    });

    // Canned and packaged goods. Same axis and tooltip shape as the two charts
    // above, but ranked and drawn by the item count: the estimated weight of a
    // shelf-stable case is the softest number on the page, while the number of
    // groceries handed out is scanned fact.
    var pkg = <?= json_encode(array_map(function ($r) {
        return [
            'name'  => $r['name'],
            'value' => $r['each'],
            'lbs'   => round($r['est_lbs'], 1),
        ];
    }, $topPackaged)) ?>;
    if (pkg.length) new Chart(document.getElementById('packagedChart'), {
      type:'bar',
      data:{
        labels: pkg.map(function (r) { return r.name; }),
        datasets:[{
          label:'Items',
          data: pkg.map(function (r) { return r.value; }),
          backgroundColor: BROWN,
          borderRadius:4
        }]
      },
      options:{
        indexAxis:'y', responsive:true, maintainAspectRatio:false,
        plugins:{
          legend:{ display:false },
          tooltip:{ callbacks:{ label: function (c) {
            var r = pkg[c.dataIndex];
            return r.value + (r.value === 1 ? ' item' : ' items') + ' (' + r.lbs + ' est. lb)';
          } } }
        },
        scales:{
          x:{ beginAtZero:true, grid:{ color:GRID }, ticks:{ precision:0 },
              title:{ display:true, text:'Items distributed', color:BROWN, font:{ weight:'bold' } } },
          y:{ grid:{ display:false } }
        }
      }
    });

    new Chart(document.getElementById('dowChart'), {
      type:'bar',
      data:{
        labels:['Sun','Mon','Tue','Wed','Thu','Fri','Sat'],
        datasets:[{ label:'Households', data:<?= json_encode($dowOrders) ?>,
                    backgroundColor:GREEN, borderRadius:4 }]
      },
      options:{
        responsive:true, maintainAspectRatio:false,
        plugins:{ legend:{ display:false } },
        scales:{
          x:{ grid:{ display:false } },
          y:{ beginAtZero:true, grid:{ color:GRID }, ticks:{ precision:0 },
              title:{ display:true, text:'Households served', color:BROWN, font:{ weight:'bold' } } }
        }
      }
    });

    <?php if ($hasSourcing): ?>
    new Chart(document.getElementById('srcChart'), {
      type:'doughnut',
      data:{
        labels:['Donated', 'Purchased'],
        datasets:[{ data:[<?= round($donatedLbs, 1) ?>, <?= round($purchasedLbs, 1) ?>],
                    backgroundColor:[GREEN, BROWN], borderColor:'#fff', borderWidth:2 }]
      },
      options:{
        responsive:true, maintainAspectRatio:false, cutout:'58%',
        plugins:{
          legend:{ position:'bottom' },
          tooltip:{ callbacks:{ label: function (c) {
            var total = c.dataset.data.reduce(function (a, b) { return a + b; }, 0);
            var pct = total > 0 ? Math.round(100 * c.raw / total) : 0;
            return c.label + ': ' + c.raw.toLocaleString() + ' est. lb (' + pct + '%)';
          } } }
        }
      }
    });
    <?php endif; ?>

    <?php if ($hasPicklist && ($reqOrders > 0 || $reqUnits > 0)): ?>
    var cats = <?= json_encode(array_map(function ($r) {
        return [
            'category'  => $r['category'],
            'requested' => (int)$r['requested'],
            'filled'    => (int)$r['filled'],
            'pct'       => $r['requested'] > 0 ? round(100 * $r['filled'] / $r['requested']) : 0,
        ];
    }, $catRows)) ?>;
    // Categories are pantry-defined and open-ended, so the wheel is generated
    // from the brand hues rather than hard-coded per category name.
    var WHEEL = ['#8BAF3A', '#6B4C11', '#2F6FA1', '#C7902B', '#8B5A2B', '#5D7E2A', '#A8763E', '#4E8C8A'];

    new Chart(document.getElementById('catChart'), {
      type:'doughnut',
      data:{
        labels: cats.map(function (c) { return c.category; }),
        datasets:[{ data: cats.map(function (c) { return c.requested; }),
                    backgroundColor: cats.map(function (c, i) { return WHEEL[i % WHEEL.length]; }),
                    borderColor:'#fff', borderWidth:2 }]
      },
      options:{
        responsive:true, maintainAspectRatio:false, cutout:'52%',
        plugins:{
          legend:{ position:'bottom', labels:{ boxWidth:12 } },
          tooltip:{ callbacks:{ label: function (c) { return c.label + ': ' + c.raw.toLocaleString() + ' units requested'; } } }
        }
      }
    });

    new Chart(document.getElementById('fillChart'), {
      type:'bar',
      data:{
        labels: cats.map(function (c) { return c.category; }),
        datasets:[{ label:'Fill rate %', data: cats.map(function (c) { return c.pct; }),
                    backgroundColor: cats.map(function (c) {
                      return c.pct >= 90 ? GREEN : (c.pct >= 70 ? '#C7902B' : '#b1452a');
                    }), borderRadius:4 }]
      },
      options:{
        indexAxis:'y', responsive:true, maintainAspectRatio:false,
        plugins:{
          legend:{ display:false },
          tooltip:{ callbacks:{ label: function (c) {
            var r = cats[c.dataIndex];
            return r.pct + '% — ' + r.filled.toLocaleString() + ' of ' + r.requested.toLocaleString() + ' units';
          } } }
        },
        scales:{
          x:{ beginAtZero:true, max:100, grid:{ color:GRID },
              ticks:{ callback: function (v) { return v + '%'; } } },
          y:{ grid:{ display:false } }
        }
      }
    });

    new Chart(document.getElementById('peopleChart'), {
      type:'bar',
      data:{
        labels: monthLabels,
        datasets:[
          { label:'Adults',   data:<?= json_encode(array_map(function ($m) use ($mAdults, $mReqAdults) { return $mAdults[$m] + $mReqAdults[$m]; }, $months)) ?>,
            backgroundColor:BROWN, borderRadius:3 },
          { label:'Children', data:<?= json_encode(array_map(function ($m) use ($mKids, $mReqKids) { return $mKids[$m] + $mReqKids[$m]; }, $months)) ?>,
            backgroundColor:GREEN, borderRadius:3 }
        ]
      },
      options:{
        responsive:true, maintainAspectRatio:false,
        interaction:{ mode:'index', intersect:false },
        plugins:{ legend:{ position:'top' } },
        scales:{
          x:{ stacked:true, grid:{ color:GRID }, ticks:{ maxRotation:45 } },
          y:{ stacked:true, beginAtZero:true, grid:{ color:GRID }, ticks:{ precision:0 },
              title:{ display:true, text:'People (person-visits)', color:BROWN, font:{ weight:'bold' } } }
        }
      }
    });
    <?php endif; ?>
  })();
  </script>

  <?php endif; ?>
</div>
<?php renderFoot(); ?>
