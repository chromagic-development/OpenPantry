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
// Start Date is sticky, the same way as the Volume report: the last one the
// report was run with is kept in a cookie and becomes the default next visit.
// Only an explicit submission writes it, and Reset clears it. With nothing
// saved, the window opens on the first of the prior month.
define('IR_START_COOKIE', 'fp_impact_start');

// Anything that isn't a real Y-m-d falls back to the default rather than
// reaching SQL or strtotime() as garbage. The cookie is user-controlled too.
function opImpactDate(?string $v, string $fallback): string {
    if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return $fallback;
    [$y, $m, $d] = array_map('intval', explode('-', $v));
    return checkdate($m, $d, $y) ? $v : $fallback;
}

// Must run before any output — this both clears a cookie and redirects.
if (isset($_GET['reset'])) {
    @setcookie(IR_START_COOKIE, '', time() - 3600, '/');
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

$defaultEnd   = date('Y-m-d');
$defaultStart = opImpactDate($_COOKIE[IR_START_COOKIE] ?? null,
                             date('Y-m-d', strtotime('first day of last month')));

$userStart = opImpactDate($_GET['date_start'] ?? null, '');
if ($userStart !== '') {
    @setcookie(IR_START_COOKIE, $userStart, time() + 365 * 24 * 3600, '/');
    $_COOKIE[IR_START_COOKIE] = $userStart;
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
// Activity level the Dietary Guidelines' calorie table is read at, for the
// days-of-food card. Moderately active is the table's middle column.
$activityLevels = ['sedentary' => 'Sedentary', 'moderate' => 'Moderately active', 'active' => 'Active'];
$activity = (string)($_GET['activity'] ?? 'moderate');
if (!isset($activityLevels[$activity])) $activity = 'moderate';

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

// Produce only, for the fresh-produce chart, rolled up to the kind of produce:
// Lettuce Romaine and Lettuce Iceberg are both Lettuce, Sweet Potatoes are
// Potatoes. Generic names put the kind first or last with no fixed order
// ("Onions Yellow" but "Green Beans"), so the kind is found by vocabulary, not
// by position. A plural or mass-noun match beats a singular one, because a
// variety tends to be named by a singular noun ("Grape Tomatoes"); among
// equals the last word wins. A name with no known kind stays its own bar. The
// Top Items Detail table below still reports the overall top 15 across all
// three categories, by generic name.
$produceKinds = [   // display name => singular form ('' for a mass noun)
    'Apples' => 'apple', 'Apricots' => 'apricot', 'Artichokes' => 'artichoke',
    'Asparagus' => '', 'Avocados' => 'avocado', 'Bananas' => 'banana',
    'Beans' => 'bean', 'Beets' => 'beet', 'Broccoli' => '', 'Cabbage' => '',
    'Carrots' => 'carrot', 'Cauliflower' => '', 'Celery' => '',
    'Cherries' => 'cherry', 'Corn' => '', 'Cucumbers' => 'cucumber',
    'Eggplant' => '', 'Garlic' => '', 'Grapes' => 'grape',
    'Grapefruit' => '', 'Greens' => '', 'Kale' => '', 'Leeks' => 'leek',
    'Lemons' => 'lemon', 'Lettuce' => '', 'Limes' => 'lime',
    'Mangoes' => 'mango', 'Melons' => 'melon', 'Mushrooms' => 'mushroom',
    'Nectarines' => 'nectarine', 'Okra' => '', 'Onions' => 'onion',
    'Oranges' => 'orange', 'Peaches' => 'peach', 'Pears' => 'pear',
    'Peas' => '', 'Peppers' => 'pepper', 'Pineapple' => '', 'Plums' => 'plum',
    'Potatoes' => 'potato', 'Pumpkins' => 'pumpkin', 'Radishes' => 'radish',
    'Spinach' => '', 'Squash' => '', 'Tomatoes' => 'tomato', 'Turnips' => 'turnip',
];
$kindStrong = [];   // plural or mass noun → display name
$kindWeak   = [];   // singular            → display name
foreach ($produceKinds as $display => $singular) {
    $kindStrong[strtolower($display)] = $display;
    if ($singular !== '') $kindWeak[$singular] = $display;
}
$produceKindOf = function (string $name) use ($kindStrong, $kindWeak): string {
    $strong = $weak = null;
    foreach (preg_split('/[^a-z]+/', strtolower($name), -1, PREG_SPLIT_NO_EMPTY) as $w) {
        if (isset($kindStrong[$w])) $strong = $kindStrong[$w];
        elseif (isset($kindWeak[$w])) $weak = $kindWeak[$w];
    }
    return $strong ?? $weak ?? $name;
};
$produceGroups = [];
foreach ($topRows as $r) {
    if ($r['category'] !== 'produce') continue;
    $g = $produceKindOf($r['name']);
    if (!isset($produceGroups[$g])) {
        $produceGroups[$g] = ['name' => $g, 'category' => 'produce', 'lbs' => 0.0,
                              'each' => 0, 'est_lbs' => 0.0, 'items' => []];
    }
    $produceGroups[$g]['lbs']     += $r['lbs'];
    $produceGroups[$g]['each']    += $r['each'];
    $produceGroups[$g]['est_lbs'] += $r['est_lbs'];
    $produceGroups[$g]['items'][]  = $r['name'];
}
$topProduce = array_values($produceGroups);
usort($topProduce, function ($a, $b) { return $b['est_lbs'] <=> $a['est_lbs']; });
$topProduce = array_slice($topProduce, 0, 15);

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
        // Grouped by position: both tables have category and item_name
        // columns, so grouping by those names is ambiguous and SQLite rejects
        // the whole query.
        $cq = $pdb->prepare("
            SELECT COALESCE(ci.category,  oi.category)  AS category,
                   COALESCE(ci.item_name, oi.item_name) AS item_name,
                   COUNT(*)                             AS requested,
                   COALESCE(SUM(oi.completed), 0)       AS filled
            FROM order_items oi
            JOIN orders o ON o.id = oi.order_id
            LEFT JOIN config_items ci ON ci.id = oi.config_item_id
            WHERE DATE(o.created_at) BETWEEN :ds AND :de
            GROUP BY 1, 2
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

// ── The plate: the average order against accepted guidelines ─────────────
// The one card on the page about the *shape* of an order rather than its
// size: what share of a household's food was fruit, vegetables, grains,
// protein and dairy, drawn as a recommended plate (in the style of the
// familiar USDA plate icon) beside a copy of it resized to the average order.
//
// Guideline portions: the plate model the accepted healthy-eating guides share
// — half the plate fruits and vegetables together, a quarter grains, a quarter
// protein. The guideline names no split between fruit and vegetables, so they
// are one section on the recommended plate and compared as one figure; the
// average order still shows them apart. Dairy is the cup beside the plate
// rather than a share of it, so it is measured against the plate: the Dietary
// Guidelines' 2,000-calorie pattern pairs 3 cups of dairy with the 4½ cups of
// fruit and vegetables (2 + 2½) that fill half the plate, which puts the cup
// at 3 ÷ 4½ of half a plate.
//
// Snacks — chips, crackers, cookies, cakes, pastries, candy and other sweets —
// sit on a dessert plate beside the main one and count in its percentages, so
// the five plate groups together make 100% and a pound of cookies shrinks the
// share left for everything else. The guidelines give these foods no portion
// at all, only advice to limit them, so the recommended dessert plate is
// empty: 0%.
$mpPlate = ['fruits' => 'Fruits', 'vegetables' => 'Vegetables', 'grains' => 'Grains',
            'protein' => 'Protein', 'snacks' => 'Snacks'];
$mpGuide = ['produce' => 50, 'grains' => 25, 'protein' => 25, 'snacks' => 0];
$mpGuideDairy = 100 * 3 / 4.5 / 2;
// Food that is on no part of the plate, reported beside it so the pounds the
// percentages leave out are still accounted for. Non-food (pet food, diapers,
// household goods) is dropped outright and never shown.
$mpOff = [
    'extras' => 'Fats, sugars, drinks &amp; condiments',
    'mixed'  => 'Soups &amp; mixed dishes',
    'other'  => 'Not yet sorted',
];

// Food groups by generic name, first match wins. Names put the food anywhere
// ("Canned Corn", "Corn Flakes Cereal", "Popcorn"), so order carries most of
// the logic: snack foods and sweets come first, so Butter Crackers, Peanut
// Butter Cookies and Chocolate Chips are snacks whatever else they name;
// breads come before the sugars and meats that name a bun's use (Honey Wheat
// Bread, Hot Dog Buns), fats/sugars/drinks before the foods they are made from
// (Strawberry Jam, Chicken Broth), mixed dishes
// before their ingredients (Chicken Noodle Soup), and grains before the fruit
// that flavours a cereal (Raisin Bran). The short list of overrides up top is
// the handful of names a later, broader rule would misfile. The standard
// food-group rules (USDA's, which the plate guides follow) decide the edge cases: butter, cream cheese and sour cream are
// fats, not dairy; almond, oat and rice milk are not dairy; eggs, beans, nuts
// and seeds are protein; 100% juice is fruit.
$mpMeatRe = '/\b(?:' . implode('|', array_map(function ($w) { return preg_quote($w, '/'); },
            array_keys($proteinMap))) . ')(?:e?s)?\b|fish|\b(?:' . implode('|', $fishSpecies) . ')(?:e?s)?\b/';
$mpRules = [
    ['/\b(?:cat|pet|bird)s?\b|(?<!hot )\bdogs?\b|rabbit food|litter|diapers?|wipes|tampons?|\bpads\b|detergent|soap|tissues?|towels?|toilet|tooth|mouth ?wash|shaving|lotion|shampoo|deodorant|razors?|jeans|socks/', 'nonfood'],
    // Snack foods and highly processed sweets, by the words their names use.
    // The look-behinds keep out the few that only share a word: English
    // muffins are bread, apple chips dried fruit, fish cakes protein.
    ['/snacks?\b|(?<!apple |banana )chips|popcorn(?! seasoning)|pretzels?|crisps|puffs|crackers?|'
     . 'cookies?|brownies?|(?<!fish |crab )\bcakes?\b|cupcakes?|cheesecake|pastr(?:y|ies)|croissants?|danish|donuts?|doughnuts?|'
     . 'cinnamon rolls?|turnovers?|\bpies?\b|strudel|eclairs?|(?<!english )muffins?|toaster|'
     . 'candy|candies|chocolate(?! milk)|marshmallows?|jelly beans|gumm(?:y|ies)|licorice|lollipops?|fudge|frosting|icing|'
     . 'pudding|gelatin|dessert|wafers|ginger snaps|\bbars?\b|\bsweets\b/', 'snacks'],
    ['/peanut butter|butter beans/', 'protein'],
    ['/\b(?:mac|macaroni|shells) (?:and|&) cheese\b|egg noodles/', 'grains'],
    ['/100% juice|apple ?sauce|\b(?:apple|banana) chips\b/', 'fruits'],
    ['/\b(?:vegetable|tomato) juice\b|\b(?:pasta|tomato|marinara|spaghetti|pizza) sauce\b|salad greens|spring mix|mustard greens|sugar snap|snap peas?|bean sprouts|chili peppers?/', 'vegetables'],
    ['/\b(?:almond|oat|rice|coconut|cashew) milk\b|\b(?:lemon|lime) juice\b/', 'extras'],
    ['/\b(?:breads?|buns?|rolls?|bagels?|biscuits?|muffins?|tortillas?|pitas?|waffles?)\b/', 'grains'],
    ['/\bbutter\b|\boils?\b|spreads?\b|cooking spray|margarine|shortening|\blard\b|mayo|cream cheese|sour cream|creamer|whipped|ice cream|'
     . 'sugar|syrup|\bhoney\b|\bjams?\b|jelly|preserves|\bdip\b|'
     . 'coffee|\btea\b|(?:sparkling|spring|bottled|mineral|seltzer|drinking) water|soda|lemonade|drinks?|punch|beverages?|bloody mary|'
     . 'ketchup|mustard|relish|sauce|dressing|vinaigrette|vinegar|\bsalt\b|seasoning|spices?|black pepper|grinder|pickle[ds]?|olives?|broth|bouillon|gravy|miso|powder|recipe mix|soup mix|extract|yeast/', 'extras'],
    ['/soups?\b|chowder|\bstew\b|\bchili\b|sandwich|\bkits?\b|curry|\bbowls?\b|salad|dumplings?|pizza|burritos?|entrees?|\bmeals?\b/', 'mixed'],
    ['/\bmilk\b|buttermilk|cheese\b|yogurt|kefir/', 'dairy'],
    [$mpMeatRe, 'protein'],
    ['/\beggs?\b|hot dogs?|sausages?|bratwurst|kielbasa|bologna|liverwurst|salami|pepperoni|jerky|steaks?\b|hamburgers?|burgers?|patties|meatballs?|'
     . '(?<!green |string |wax )\bbeans?\b|lentils?|chick ?peas?|garbanzo|black[- ]eyed peas|split peas|hummus|tofu|tempeh|'
     . '\bnuts?\b|almonds?|pistachios?|cashews?|peanuts?|walnuts?|pecans?|seeds\b|clams?|shrimp|\bcrab\b|oysters?|mussels?|scallops?|lobster/', 'protein'],
    ['/cereal|\boats?\b|oatmeal|granola|flour|masa|pasta|macaroni|noodles?|spaghetti|rotini|penne|fettuccine|linguine|lasagna|ramen|\brice\b|barley|quinoa|couscous|bulgur|grits|polenta|matzos?|taco shells|stuffing|croutons|pancake|baking mix/', 'grains'],
    ['/fruits?\b|juice|berr(?:y|ies)\b|apples?\b|apricots?|bananas?|cherr(?:y|ies)|clementines?|grapes?\b|grapefruit|kiwi|\blemons?\b|\blimes?\b|mangos?|mangoes|\bmelons?|watermelons?|cant[ae]loupe|honeydew|nectarines?|oranges?|mandarins?|tangerines?|peach(?:es)?\b|pears?\b|pineapples?|plums?\b|prunes?|raisins?|\bdates\b|\bfigs?\b|papaya|pomegranates?/', 'fruits'],
    ['/vegetables?|veggies?|artichokes?|asparagus|avocados?|\bbeets?\b|peppers?\b|jalap|jalep|bok choy|broccoli|brussels|cabbage|carrots?|cauliflower|celery|chard|cilantro|collards?|\bcorn\b|cucumbers?|eggplant|garlic|ginger|greens\b|green beans?|\bkale\b|leeks?|lettuce|mushrooms?|okra|onions?|parsley|\bpeas\b|potato(?:es)?|hash browns?|pumpkins?|radish(?:es)?|spinach|squash|zucchini|tomato(?:es)?|turnips?|bamboo|chestnuts|sprouts|\blimas?\b|\byams?\b|sauerkraut|\bherbs?\b|\bsage\b|basil/', 'vegetables'],
];
$mpGroupOf = function (string $name) use ($mpRules, $produceNames): string {
    $low = mb_strtolower($name);
    foreach ($mpRules as $rule) {
        if (preg_match($rule[0], $low)) return $rule[1];
    }
    // Every PLU produce item is a fruit or a vegetable, and the fruit list is
    // the shorter one to keep complete, so produce that names no fruit is a
    // vegetable (Bok Choy, Chard Swiss, Cilantro).
    return isset($produceNames[$name]) ? 'vegetables' : 'other';
};

// Milk is sold by volume and its generic names say how much ("Milk Whole -
// 1/2 Gallon", "Whole Milk - 1 Quart"), so a dairy item with a size in its
// name is weighed from it at 8.6 lb a gallon rather than booked at the
// packaged-item guess. 0 when the name carries no size.
function opMpVolumeLb(string $name): float {
    if (!preg_match('/(\d+(?:\.\d+)?(?:\s*\/\s*\d+)?|half)\s*-?\s*(gal(?:lon)?s?|quarts?|qts?|pints?|pts?)\b/i', $name, $m)) return 0.0;
    $n = strtolower($m[1]);
    if ($n === 'half') {
        $qty = 0.5;
    } elseif (strpos($n, '/') !== false) {
        [$a, $b] = array_map('floatval', explode('/', $n));
        $qty = $b > 0 ? $a / $b : 0.0;
    } else {
        $qty = (float)$n;
    }
    $u = strtolower($m[2])[0];
    return $qty * ($u === 'g' ? 1.0 : ($u === 'q' ? 0.25 : 0.125)) * 8.6;
}
// Pounds for one counted unit, by the same rules as the rest of the page: an
// Avg Wt from Inventory wins, then (for dairy) the size in the name, and only
// then the operator's packaged-item assumption.
$mpLbEach = function (string $name, string $group) use ($lbEach, $lbPerItem): float {
    if (isset($lbEach[$name])) return $lbEach[$name];
    if ($group === 'dairy') {
        $v = opMpVolumeLb($name);
        if ($v > 0) return $v;
    }
    return $lbPerItem;
};
// Matching key between a counter item and a generic name: "Milk (Lactose-
// Free)" on the counter form is "Milk Lactose-Free" at the scanner.
function opMpKey(string $s): string {
    return trim((string)preg_replace('/[^a-z0-9]+/', ' ', strtolower($s)));
}

// ── The counter's half of the order ──
// Items on the PantryPrep counter form (eggs, cheese, butter, frozen meat,
// canned tuna, kids' snacks…) are recorded twice: once as a request at the
// counter and again when the bag is scanned at checkout. The counter record is
// the one kept. It is the complete one — the counter logs every visit, while
// the scanner runs only on some days and at some stations — so for this card
// the two records are not merged order by order (nothing links them) but
// averaged separately and added: the average scanned basket with counter items
// set aside, plus the average counter request, filled units only.
//
// A scanned name is a counter item when it matches a counter item requested in
// the period, by the same rule the delivery menu uses (delivery/db.php): the
// item's name, or "<name> <size>" for a sized item (Butter Salted). An item the
// counter did not hand out in the period keeps its scans.
//
// Both halves are read one order at a time, so each order's pounds per group
// are on hand for the spread around the average as well as the average.
$mpUseCounter  = false;
$mpCounterRows = [];
if ($hasPicklist && $reqOrders > 0) {
    try {
        $q = $pdb->prepare("
            SELECT oi.order_id                          AS oid,
                   COALESCE(ci.item_name, oi.item_name) AS item_name,
                   COALESCE(oi.item_detail, '')         AS detail,
                   COALESCE(SUM(oi.completed), 0)       AS filled
            FROM order_items oi
            JOIN orders o ON o.id = oi.order_id
            LEFT JOIN config_items ci ON ci.id = oi.config_item_id
            WHERE DATE(o.created_at) BETWEEN :ds AND :de
            GROUP BY 1, 2, 3
        ");
        $q->execute([':ds' => $dateStart, ':de' => $dateEnd]);
        $mpCounterRows = $q->fetchAll(PDO::FETCH_ASSOC);
        $mpUseCounter  = true;
    } catch (\PDOException $e) {
        $mpCounterRows = [];
    }
}
// The same orders the rest of the page counts in $totOrders: started in the
// period, with at least one scan.
$q = $db->prepare("
    SELECT s.order_id                                                        AS oid,
           s.generic_name                                                    AS name,
           SUM(CASE WHEN s.kind = 'produce' THEN COALESCE(s.weight_lbs, 0)
                    ELSE 0 END)                                              AS lbs,
           SUM(CASE WHEN s.kind = 'produce' THEN 0 ELSE s.quantity END)      AS each_qty
    FROM orders o
    JOIN scans s ON s.order_id = o.id
    WHERE o.started_at >= :rs AND o.started_at <= :re
    GROUP BY s.order_id, s.generic_name
");
$q->execute([':rs' => $rangeStart, ':re' => $rangeEnd]);
$mpScanRows = $q->fetchAll(PDO::FETCH_ASSOC);

// Generic names by matching key, so a counter row picks up the scanned item's
// own Avg Wt and food group.
$mpNameByKey = [];
foreach (array_merge(array_keys($lbEach), array_column($mpScanRows, 'name')) as $n) {
    $mpNameByKey[opMpKey((string)$n)] = (string)$n;
}
$mpClaimed = [];   // matching keys the counter speaks for
foreach ($mpCounterRows as $r) {
    $item = trim((string)$r['item_name']);
    $mpClaimed[opMpKey($item)] = true;
    if (trim((string)$r['detail']) !== '') $mpClaimed[opMpKey($item . ' ' . trim((string)$r['detail']))] = true;
}
$mpLb    = array_fill_keys(array_merge(array_keys($mpPlate), ['dairy'], array_keys($mpOff)), 0.0);
$mpItems = [];      // group => [label => ['lb' => per order, 'counter' => bool]]
$mpOrd   = ['scan' => [], 'counter' => []];   // source => order id => group => lb
// Adds one item's pounds on one order: to that order's group total, and — as
// its share of the source's average — to the card's average and item list.
// Fruit and vegetables also total as 'produce', the guideline's single figure,
// and everything on the plate or in the cup as 'total'.
$mpAdd = function (string $src, $oid, string $group, string $label, float $lb, int $n)
         use (&$mpLb, &$mpItems, &$mpOrd, $mpPlate) {
    if ($lb <= 0) return;
    $mpLb[$group] += $lb / $n;
    if (!isset($mpItems[$group][$label])) $mpItems[$group][$label] = ['lb' => 0.0, 'counter' => $src === 'counter'];
    $mpItems[$group][$label]['lb'] += $lb / $n;
    $keys = [$group];
    if ($group === 'fruits' || $group === 'vegetables') $keys[] = 'produce';
    if (isset($mpPlate[$group]) || $group === 'dairy') $keys[] = 'total';
    foreach ($keys as $k) $mpOrd[$src][$oid][$k] = ($mpOrd[$src][$oid][$k] ?? 0.0) + $lb;
};
// Classification is by name, so each name is sorted once however many orders
// carry it.
$mpGroupCache = [];
$mpGroupOfC = function (string $name) use (&$mpGroupCache, $mpGroupOf): string {
    return $mpGroupCache[$name] ?? ($mpGroupCache[$name] = $mpGroupOf($name));
};
if ($mpUseCounter) {
    foreach ($mpCounterRows as $r) {
        $filled = (int)$r['filled'];
        if ($filled <= 0) continue;
        $item   = trim((string)$r['item_name']);
        $detail = trim((string)$r['detail']);
        $full   = $detail !== '' ? $item . ' ' . $detail : $item;
        $name   = $mpNameByKey[opMpKey($full)] ?? $mpNameByKey[opMpKey($item)] ?? $full;
        $group  = $mpGroupOfC($name);
        if ($group === 'nonfood') continue;
        $mpAdd('counter', $r['oid'], $group, $name, $filled * $mpLbEach($name, $group), $reqOrders);
    }
}
$mpDupScans   = 0;   // checkout scans of counter items, set aside
$mpNoWtPieces = 0;   // produce sold by the piece with no Avg Wt, at $lbPerItem
if ($totOrders > 0) {
    foreach ($mpScanRows as $r) {
        $name = (string)$r['name'];
        $each = (int)$r['each_qty'];
        if ($mpUseCounter && isset($mpClaimed[opMpKey($name)])) {
            $mpDupScans += $each;
            continue;
        }
        $group = $mpGroupOfC($name);
        if ($group === 'nonfood') continue;
        if (isset($produceNames[$name]) && !isset($lbEach[$name])) $mpNoWtPieces += $each;
        $mpAdd('scan', $r['oid'], $group, $name, (float)$r['lbs'] + $each * $mpLbEach($name, $group), $totOrders);
    }
}

// Spread around the average, in pounds per order. The sample variance of each
// source is taken over ALL its orders — one that carried none of a group is a
// real zero, not a missing value — and the average order's variance is the
// two added, since its two halves come from different orders that cannot be
// paired. That treats them as independent; larger households take more at
// both, so for protein and dairy (the groups the counter carries) the true
// spread is, if anything, somewhat wider.
function opMpVariance(array $perOrder, int $n, string $g): float {
    if ($n < 2) return 0.0;
    $s = $s2 = 0.0;
    foreach ($perOrder as $row) {
        $x   = $row[$g] ?? 0.0;
        $s  += $x;
        $s2 += $x * $x;
    }
    return max(0.0, ($s2 - $s * $s / $n) / ($n - 1));
}
$mpSd = [];
foreach (['fruits', 'vegetables', 'produce', 'grains', 'protein', 'snacks', 'dairy', 'total'] as $g) {
    $v = opMpVariance($mpOrd['scan'], $totOrders, $g);
    if ($mpUseCounter) $v += opMpVariance($mpOrd['counter'], $reqOrders, $g);
    $mpSd[$g] = sqrt($v);
}
$mpOrd = null;   // per-order detail is only needed for the spread
$mpPlateLb = 0.0;
foreach ($mpPlate as $g => $label) $mpPlateLb += $mpLb[$g];
// A plate needs the scanned half of the order: with counter requests alone it
// would be nothing but eggs, cheese and frozen meat.
$hasMyPlate = $totOrders > 0 && $mpPlateLb > 0;

// Whole-number shares that still add to 100, by largest remainder, so the
// table never shows a plate of 99% or 101%.
$mpShare = array_fill_keys(array_keys($mpPlate), 0);
if ($hasMyPlate) {
    $raw = [];
    foreach ($mpPlate as $g => $label) $raw[$g] = 100 * $mpLb[$g] / $mpPlateLb;
    foreach ($raw as $g => $v) $mpShare[$g] = (int)floor($v);
    $left = 100 - array_sum($mpShare);
    $rem  = [];
    foreach ($raw as $g => $v) $rem[$g] = $v - floor($v);
    arsort($rem);
    foreach (array_keys($rem) as $g) {
        if ($left-- <= 0) break;
        $mpShare[$g]++;
    }
}
$mpDairyPct = $hasMyPlate ? 100 * $mpLb['dairy'] / $mpPlateLb : 0.0;

// ── Days of food: the average order against a household's calorie needs ──
// The plate card says what the average order is made of; this one says how
// long it lasts. Its calories are set against what the average household
// needs in a day, by the Dietary Guidelines' own estimates.
//
// Daily calorie needs: Table A2-2 of the Dietary Guidelines for Americans,
// 2020–2025 (dietaryguidelines.gov, previous editions) — the most recent
// edition with a by-age table; the 2025–2030 edition gives none and works from
// a 2,000-calorie pattern. [from age, to age, male, female], each
// [sedentary, moderately active, active]. "76 and up" is taken as 76–80.
$dgaCalories = [
    [2, 2, [1000, 1000, 1000], [1000, 1000, 1000]],
    [3, 3, [1000, 1400, 1400], [1000, 1200, 1400]],
    [4, 4, [1200, 1400, 1600], [1200, 1400, 1400]],
    [5, 5, [1200, 1400, 1600], [1200, 1400, 1600]],
    [6, 6, [1400, 1600, 1800], [1200, 1400, 1600]],
    [7, 7, [1400, 1600, 1800], [1200, 1600, 1800]],
    [8, 8, [1400, 1600, 2000], [1400, 1600, 1800]],
    [9, 9, [1600, 1800, 2000], [1400, 1600, 1800]],
    [10, 10, [1600, 1800, 2200], [1400, 1800, 2000]],
    [11, 11, [1800, 2000, 2200], [1600, 1800, 2000]],
    [12, 12, [1800, 2200, 2400], [1600, 2000, 2200]],
    [13, 13, [2000, 2200, 2600], [1600, 2000, 2200]],
    [14, 14, [2000, 2400, 2800], [1800, 2000, 2400]],
    [15, 15, [2200, 2600, 3000], [1800, 2000, 2400]],
    [16, 16, [2400, 2800, 3200], [1800, 2000, 2400]],
    [17, 17, [2400, 2800, 3200], [1800, 2000, 2400]],
    [18, 18, [2400, 2800, 3200], [1800, 2000, 2400]],
    [19, 20, [2600, 2800, 3000], [2000, 2200, 2400]],
    [21, 25, [2400, 2800, 3000], [2000, 2200, 2400]],
    [26, 30, [2400, 2600, 3000], [1800, 2000, 2400]],
    [31, 35, [2400, 2600, 3000], [1800, 2000, 2200]],
    [36, 40, [2400, 2600, 2800], [1800, 2000, 2200]],
    [41, 45, [2200, 2600, 2800], [1800, 2000, 2200]],
    [46, 50, [2200, 2400, 2800], [1800, 2000, 2200]],
    [51, 55, [2200, 2400, 2800], [1600, 1800, 2200]],
    [56, 60, [2200, 2400, 2600], [1600, 1800, 2200]],
    [61, 65, [2000, 2400, 2600], [1600, 1800, 2000]],
    [66, 70, [2000, 2200, 2600], [1600, 1800, 2000]],
    [71, 75, [2000, 2200, 2600], [1600, 1800, 2000]],
    [76, 80, [2000, 2200, 2400], [1600, 1800, 2000]],
];
// The counter form records how many adults and children, not their ages or
// sexes, so each is the table's average over every year of age it covers —
// children 2–18, adults 19 on — with men and women (boys and girls) weighted
// equally.
function opDgaAverage(array $table, int $from, int $to, int $level): float {
    $sum = 0.0; $years = 0;
    foreach ($table as [$a, $b, $m, $f]) {
        if ($b < $from || $a > $to) continue;
        $n = min($b, $to) - max($a, $from) + 1;
        $sum += $n * ($m[$level] + $f[$level]) / 2;
        $years += $n;
    }
    return $years > 0 ? $sum / $years : 0.0;
}
$activityCol = ['sedentary' => 0, 'moderate' => 1, 'active' => 2][$activity];
$kcalAdult = opDgaAverage($dgaCalories, 19, 80, $activityCol);
$kcalChild = opDgaAverage($dgaCalories, 2, 18, $activityCol);

// The average household: counter requests in the period that say how many
// adults and children they were for. Requests with neither are left out
// rather than counted as households of nobody.
$hhOrders = 0; $hhAdults = 0.0; $hhKids = 0.0;
if ($hasPicklist) {
    try {
        $q = $pdb->prepare("
            SELECT COUNT(*) AS n, SUM(COALESCE(adults, 0)) AS a, SUM(COALESCE(children, 0)) AS c
            FROM orders
            WHERE DATE(created_at) BETWEEN :ds AND :de
              AND COALESCE(adults, 0) + COALESCE(children, 0) > 0
        ");
        $q->execute([':ds' => $dateStart, ':de' => $dateEnd]);
        $r = $q->fetch(PDO::FETCH_ASSOC) ?: [];
        $hhOrders = (int)($r['n'] ?? 0);
        if ($hhOrders > 0) {
            $hhAdults = (float)$r['a'] / $hhOrders;
            $hhKids   = (float)$r['c'] / $hhOrders;
        }
    } catch (\PDOException $e) {
        $hhOrders = 0;
    }
}
$hhKcalDay = $hhAdults * $kcalAdult + $hhKids * $kcalChild;

// Calories per pound, as handed out: rounded typical values from USDA
// FoodData Central (SR Legacy) for each kind of food, net of peel, cores and
// rinds for fresh produce (a pound of oranges is not a pound of orange) and as
// the whole can for canned goods. Matched on the item's generic name within
// its food group, first match wins, the null row being the group's default.
// The spread is wide on purpose — a pound of butter is 3,250 kcal and a pound
// of lettuce under 100 — which is why a single figure per group would not do.
$mpFishRe = '/fish|\b(?:' . implode('|', $fishSpecies) . ')(?:e?s)?\b|shrimp|\bcrab\b|lobster|scallops?|oysters?|mussels?/';
$kcalRules = [
    'fruits' => [
        ['/dried|raisins?|prunes?|\bdates\b|chips/', 1360],
        ['/\blemons?\b|\blimes?\b|melons?|cant[ae]loupe|honeydew|grapefruit/', 80],
        ['/juice|canned|apple ?sauce|cocktail|mixed fruit|fruit mix|slices|\bcups?\b/', 240],
        [null, 200],
    ],
    'vegetables' => [
        ['/instant/', 1600],
        ['/avocados?/', 540],
        ['/potato|hash browns?|\byams?\b/', 320],
        ['/(?:canned|frozen|sweet) (?:corn|peas)|canned .*(?:corn|peas)|peas and carrots/', 300],
        ['/sauce|paste/', 250],
        ['/onions?|carrots?|\bbeets?\b|garlic|parsnips?|leeks?/', 160],
        [null, 100],    // leafy and watery: lettuce, tomatoes, squash, peppers…
    ],
    'grains' => [
        ['/canned/', 360],
        ['/ramen|granola|matzos?|taco shells|croutons/', 2000],
        ['/stuffing|crumbs/', 1750],
        ['/\b(?:breads?|buns?|rolls?|bagels?|biscuits?|tortillas?|pitas?|waffles?)\b|muffins?(?! mix)/', 1200],
        [null, 1650],   // dry: pasta, rice, oats, flour, cereal, boxed mixes
    ],
    'snacks' => [
        ['/pudding|gelatin|dessert/', 600],
        ['/marshmallows?/', 1450],
        ['/\bpies?\b|turnovers?|strudel/', 1200],
        ['/muffins?(?! mix)|croissants?|danish|donuts?|doughnuts?|cinnamon rolls?|pastr|cupcakes?|cheesecake|eclairs?/', 1800],
        ['/snacks?|chips|popcorn|pretzels?|crisps|puffs/', 2200],
        ['/crackers?/', 2000],
        [null, 2000],   // cookies, cake and brownie mixes, candy, chocolate, frosting
    ],
    'protein' => [
        ['/peanut butter/', 2650],
        ['/\bnuts?\b|almonds?|pistachios?|cashews?|peanuts?|walnuts?|pecans?|seeds/', 2500],
        ['/\beggs?\b/', 570],
        ['/bacon|sausages?|hot dogs?|bratwurst|kielbasa|bologna|liverwurst|salami|pepperoni|jerky/', 1400],
        ['/canned|tuna|clams?/', 480],
        ['/lentils?|split peas|\bdry\b|dried/', 1600],
        ['/beans?|chick ?peas?|garbanzo|black[- ]eyed|hummus/', 450],
        ['/tofu|tempeh/', 350],
        ['/sticks|nuggets|breaded|burgers?|patties/', 1000],
        [$mpFishRe, 450],
        [null, 900],    // fresh and frozen meat and poultry, as sold
    ],
    'dairy' => [
        ['/cottage/', 450],
        ['/cheese/', 1800],
        ['/yogurt|kefir/', 360],
        ['/\bdry\b|powder/', 1640],
        ['/canned|evaporated|condensed/', 610],
        ['/chocolate/', 380],
        ['/fat free|skim|nonfat/', 155],
        ['/1%/', 190],
        ['/2%/', 225],
        ['/whole/', 275],
        [null, 230],    // milk with no fat level in its name
    ],
    'extras' => [
        ['/(?:almond|oat|rice|coconut|cashew|soy) milk/', 80],
        ['/creamer|sour cream|ice cream|whipped/', 850],
        ['/cream cheese/', 1550],
        ['/coffee|\btea\b|water|seasoning|spices?|\bsalt\b|black pepper|grinder|extract|yeast|vinegar|broth|bouillon|pickle|mustard|hot sauce|baking (?:soda|powder)/', 0],
        ['/fruit spread|\bjams?\b|jelly|preserves|syrup|\bhoney\b/', 1250],
        ['/sugar/', 1750],
        ['/butter|margarine|spreads?|shortening|\blard\b/', 3250],
        ['/\boils?\b|cooking spray/', 4000],
        ['/dressing|vinaigrette|mayo/', 1700],
        ['/juice|drinks?|punch|lemonade|soda|beverages?|bloody mary/', 220],
        ['/powder/', 1700],
        ['/ketchup|sauce|relish|gravy|olives?|miso|\bdip\b|salsa/', 500],
        ['/\bmix(?:es)?\b/', 1300],
        [null, 1500],
    ],
    'mixed' => [
        ['/condensed|cream of/', 450],
        ['/soups?|chowder/', 220],
        ['/sandwich/', 1100],
        ['/\bkits?\b|dinner/', 1600],
        [null, 600],
    ],
    'other' => [
        [null, 1000],
    ],
];
$kcalPerLb = function (string $name, string $group) use ($kcalRules): float {
    $low = mb_strtolower($name);
    foreach ($kcalRules[$group] ?? [[null, 1000]] as [$re, $kcal]) {
        if ($re === null || preg_match($re, $low)) return (float)$kcal;
    }
    return 1000.0;
};

// Calories in the average order, by group. Every food counts, on the plate or
// off it: the butter and the cooking oil are eaten too. Non-food never reached
// $mpItems.
$kcalGroups = ['fruits' => 'Fruits', 'vegetables' => 'Vegetables', 'grains' => 'Grains',
               'protein' => 'Protein', 'snacks' => 'Snacks', 'dairy' => 'Dairy'] + $mpOff;
$kcalByGroup = array_fill_keys(array_keys($kcalGroups), 0.0);
foreach ($mpItems as $g => $items) {
    if (!isset($kcalByGroup[$g])) continue;
    foreach ($items as $name => $it) $kcalByGroup[$g] += $it['lb'] * $kcalPerLb((string)$name, $g);
}
$kcalOrder = array_sum($kcalByGroup);
$hasDays   = $hasMyPlate && $hhOrders > 0 && $hhKcalDay > 0 && $kcalOrder > 0;
$daysFood  = $hasDays ? $kcalOrder / $hhKcalDay : 0.0;

// The cart's contents: each food group's share of the average order's pounds
// — the plates and the cup together, as the plate card weighs them — as a
// fixed number of grocery items, so that each item is the same slice of the
// order and the count of each kind can be read as its share.
define('CART_UNITS', 30);
$cartGroups = ['dairy', 'protein', 'grains', 'snacks', 'vegetables', 'fruits'];   // packed bottom up
$cartUnits  = array_fill_keys($cartGroups, 0);
$cartLb     = 0.0;
foreach ($cartGroups as $g) $cartLb += $mpLb[$g];
if ($cartLb > 0) {
    $raw = [];
    foreach ($cartGroups as $g) $raw[$g] = CART_UNITS * $mpLb[$g] / $cartLb;
    foreach ($raw as $g => $v) $cartUnits[$g] = (int)floor($v);
    $left = CART_UNITS - array_sum($cartUnits);
    $rem  = [];
    foreach ($raw as $g => $v) $rem[$g] = $v - floor($v);
    arsort($rem);
    foreach (array_keys($rem) as $g) {
        if ($left-- <= 0) break;
        $cartUnits[$g]++;
    }
}

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

// ── Plate drawing ─────────────────────────────────────────────────────────
// Drawn in the style of the USDA plate icon: a circle cut by three straight
// dividers — one vertical, then one horizontal on each side. Building it that
// way keeps every section a shape people recognise and makes its area exactly
// its share: each divider is placed by solving for the area on one side of it.
// Both plates come from the same code, so the right-hand plate is the
// recommended one with its dividers moved.

// Area, centroid and vertical extent of the part of a circle inside a
// rectangle, summed over thin horizontal strips — simpler than the closed form,
// which needs a branch for every way a rectangle can cut a circle.
function opMpRegion(float $cx, float $cy, float $r, float $x0, float $x1, float $y0, float $y1, int $n = 160): array {
    $out = ['a' => 0.0, 'x' => $cx, 'y' => $cy, 'top' => $cy, 'bottom' => $cy];
    $y0 = max($y0, $cy - $r);
    $y1 = min($y1, $cy + $r);
    if ($y1 <= $y0 || $x1 <= $x0) return $out;
    $dy = ($y1 - $y0) / $n;
    $a = $mx = $my = 0.0;
    $top = $bottom = null;
    for ($i = 0; $i < $n; $i++) {
        $y  = $y0 + ($i + 0.5) * $dy;
        $hw = sqrt(max(0.0, $r * $r - ($y - $cy) ** 2));
        $lo = max($x0, $cx - $hw);
        $hi = min($x1, $cx + $hw);
        if ($hi <= $lo) continue;
        $s   = ($hi - $lo) * $dy;
        $a  += $s;
        $mx += $s * ($lo + $hi) / 2;
        $my += $s * $y;
        if ($top === null) $top = $y;
        $bottom = $y;
    }
    if ($a <= 0) return $out;
    return ['a' => $a, 'x' => $mx / $a, 'y' => $my / $a, 'top' => $top, 'bottom' => $bottom];
}

// Bisection for the x (or y) where an increasing area reaches $target.
function opMpSolve(callable $f, float $lo, float $hi, float $target): float {
    for ($i = 0; $i < 32; $i++) {
        $mid = ($lo + $hi) / 2;
        if ($f($mid) < $target) $lo = $mid; else $hi = $mid;
    }
    return ($lo + $hi) / 2;
}

// One plate graphic as inline SVG. $share is the plate groups in whole percent,
// summing to 100: the main plate's four sections, plus 'snacks' for a dessert
// plate when the key is present. A group at 0 gives its room to its neighbour, so
// the recommended plate's single fruit-and-vegetable half is drawn as
// vegetables at 50 with fruits at 0. $cupScale is the dairy cup's area as a
// multiple of the recommended cup, and $dairyPct the figure printed on it.
// $names relabels a section — an array of lines for a long name. $id keeps the
// gradient and clip ids of the two plates on the page apart.
function opMyPlateSvg(array $share, float $cupScale, float $dairyPct, string $id, string $title, array $names = []): string {
    $R  = 140.0;                      // food area radius; everything scales off it
    $cx = 245.0; $cy = 230.0;
    $gap = 0.05 * $R; $bw = 0.028 * $R; $band = 0.04 * $R;
    $f = function (float $v): string { return sprintf('%.1f', $v); };
    $e = function (string $s): string { return htmlspecialchars($s, ENT_QUOTES); };

    // [outer fill, fill toward the divider, border, inner band] — sampled from
    // the USDA icon, which shades each section lighter toward the centre line.
    $col = [
        'fruits'     => ['#D01A21', '#ED416B', '#A9151A', '#F35B9D'],
        'vegetables' => ['#2CAC42', '#75C62B', '#168235', '#B3DB18'],
        'grains'     => ['#D45914', '#D97438', '#A9460F', '#E1A374'],
        'protein'    => ['#503685', '#7C5CA8', '#3F2A66', '#9D79C3'],
    ];
    $names += ['fruits' => 'Fruits', 'vegetables' => 'Vegetables', 'grains' => 'Grains', 'protein' => 'Protein'];

    $L = $cx - $R - 2; $Rt = $cx + $R + 2; $T = $cy - $R - 2; $B = $cy + $R + 2;
    $A = M_PI * $R * $R;
    $area = function ($x0, $x1, $y0, $y1) use ($cx, $cy, $R) {
        return opMpRegion($cx, $cy, $R, $x0, $x1, $y0, $y1, 120)['a'];
    };
    // Section areas split the main plate among its four groups; the labels
    // keep their share of everything on the plates, the dessert plate's snacks
    // included, so the five printed figures add to 100.
    $main = 0.0;
    foreach (['fruits', 'vegetables', 'grains', 'protein'] as $g) $main += max(0.0, (float)($share[$g] ?? 0));
    $p  = function (string $g) use ($share, $main) {
        return $main > 0 ? max(0.0, (float)($share[$g] ?? 0)) / $main : 0.0;
    };
    $xv = opMpSolve(function ($x) use ($area, $L, $T, $B) { return $area($L, $x, $T, $B); },
                    $L, $Rt, ($p('fruits') + $p('vegetables')) * $A);
    $yl = opMpSolve(function ($y) use ($area, $L, $xv, $T) { return $area($L, $xv, $T, $y); },
                    $T, $B, $p('fruits') * $A);
    $yr = opMpSolve(function ($y) use ($area, $xv, $Rt, $T) { return $area($xv, $Rt, $T, $y); },
                    $T, $B, $p('grains') * $A);

    // Section rectangles, inset half a gap from each divider. Their outer
    // sides run well past the rim so only the dividers draw an edge. A divider
    // with nothing on its far side is no divider at all: the section runs on
    // past it, or a sliver of rim would be cut off flat with a border drawn
    // across it.
    $far   = 40;
    $hasL  = $p('fruits') + $p('vegetables') > 0;
    $hasR  = $p('grains') + $p('protein') > 0;
    $vL    = $hasR ? $xv - $gap / 2 : $Rt + $far;   // right edge of the left half
    $vR    = $hasL ? $xv + $gap / 2 : $L - $far;    // left edge of the right half
    $rect = [
        'fruits'     => [$L - $far, $vL,        $T - $far,
                         $p('vegetables') > 0 ? $yl - $gap / 2 : $B + $far],
        'vegetables' => [$L - $far, $vL,        $p('fruits') > 0 ? $yl + $gap / 2 : $T - $far,
                         $B + $far],
        'grains'     => [$vR,       $Rt + $far, $T - $far,
                         $p('protein') > 0 ? $yr - $gap / 2 : $B + $far],
        'protein'    => [$vR,       $Rt + $far, $p('grains') > 0 ? $yr + $gap / 2 : $T - $far,
                         $B + $far],
    ];
    $left = ['fruits' => true, 'vegetables' => true];

    $defs  = '<clipPath id="' . $id . '-food"><circle cx="' . $f($cx) . '" cy="' . $f($cy) . '" r="' . $f($R) . '"/></clipPath>';
    $defs .= '<radialGradient id="' . $id . '-plate" cx="45%" cy="45%" r="60%"><stop offset="0" stop-color="#FFFFFF"/><stop offset="1" stop-color="#E9E8E8"/></radialGradient>';
    $body  = '';
    $labels = '';
    foreach ($rect as $g => [$x0, $x1, $y0, $y1]) {
        if ($p($g) <= 0 || $x1 <= $x0 || $y1 <= $y0) continue;
        [$outer, $inner, $border, $bandCol] = $col[$g];
        // Lighter toward the vertical divider, as in the icon.
        [$gx1, $gx2] = isset($left[$g]) ? [$cx - $R, $xv] : [$cx + $R, $xv];
        $defs .= '<linearGradient id="' . $id . '-g-' . $g . '" gradientUnits="userSpaceOnUse" x1="' . $f($gx1) . '" y1="0" x2="' . $f($gx2) . '" y2="0">'
               . '<stop offset="0" stop-color="' . $outer . '"/><stop offset="1" stop-color="' . $inner . '"/></linearGradient>';
        $defs .= '<clipPath id="' . $id . '-c-' . $g . '"><rect x="' . $f($x0) . '" y="' . $f($y0) . '" width="' . $f($x1 - $x0) . '" height="' . $f($y1 - $y0) . '"/></clipPath>';
        $rc = 'x="' . $f($x0) . '" y="' . $f($y0) . '" width="' . $f($x1 - $x0) . '" height="' . $f($y1 - $y0) . '"';
        $cc = 'cx="' . $f($cx) . '" cy="' . $f($cy) . '" r="' . $f($R) . '"';
        // Strokes are centred on the edges and clipped to the inside, so the
        // band shows at (border + band) wide and the border on top of it.
        $body .= '<g clip-path="url(#' . $id . '-c-' . $g . ')"><g clip-path="url(#' . $id . '-food)">'
               . '<rect ' . $rc . ' fill="url(#' . $id . '-g-' . $g . ')"/>'
               . '<circle ' . $cc . ' fill="none" stroke="' . $bandCol . '" stroke-width="' . $f(2 * ($bw + $band)) . '"/>'
               . '<rect ' . $rc . ' fill="none" stroke="' . $bandCol . '" stroke-width="' . $f(2 * ($bw + $band)) . '"/>'
               . '<circle ' . $cc . ' fill="none" stroke="' . $border . '" stroke-width="' . $f(2 * $bw) . '"/>'
               . '<rect ' . $rc . ' fill="none" stroke="' . $border . '" stroke-width="' . $f(2 * $bw) . '"/>'
               . '</g></g>';

        // Label at the section's centroid, sized to the room there; a section
        // too thin for white text inside it gets a one-line dark label instead.
        $reg   = opMpRegion($cx, $cy, $R, $x0, $x1, $y0, $y1);
        $hw    = sqrt(max(0.0, $R * $R - ($reg['y'] - $cy) ** 2));
        $w     = min($x1, $cx + $hw) - max($x0, $cx - $hw) - 2 * ($bw + $band);
        $h     = $reg['bottom'] - $reg['top'] - 2 * ($bw + $band);
        $lines = (array)$names[$g];
        $pct   = (int)round($share[$g]) . '%';
        $long  = max(array_map('strlen', $lines));
        $fs    = min(19.0, 0.92 * $w / ($long * 0.6), $h / (1.25 * (count($lines) + 1)));
        if ($fs >= 10.5) {
            // Name lines then the percentage, centred as a block on the centroid.
            $lines[] = $pct;
            $k = count($lines);
            foreach ($lines as $i => $line) {
                $ly = $reg['y'] + ($i - ($k - 1) / 2) * 1.12 * $fs + 0.35 * $fs;
                $labels .= '<text x="' . $f($reg['x']) . '" y="' . $f($ly) . '" text-anchor="middle" class="mp-lbl" font-size="'
                         . $f($i === $k - 1 ? $fs * 0.92 : $fs) . '">' . $e($line) . '</text>';
            }
        } else {
            $labels .= '<text x="' . $f($reg['x']) . '" y="' . $f($reg['y'] + 4) . '" text-anchor="middle" class="mp-lbl-sm" font-size="11">'
                     . $e(implode(' ', $lines) . ' ' . $pct) . '</text>';
        }
    }

    // Fork, left of the plate.
    $fx = $cx - 1.41 * $R;
    $tw = 0.042 * $R; $tg = (0.27 * $R - 4 * $tw) / 3;
    $fork = '';
    for ($i = 0; $i < 4; $i++) {
        $tx = $fx - 0.135 * $R + $i * ($tw + $tg);
        $fork .= '<rect x="' . $f($tx) . '" y="' . $f($cy - 0.8 * $R) . '" width="' . $f($tw) . '" height="' . $f(0.4 * $R) . '" rx="' . $f($tw / 2) . '"/>';
    }
    $fork .= '<path d="M' . $f($fx - 0.135 * $R) . ' ' . $f($cy - 0.44 * $R)
           . ' L' . $f($fx + 0.135 * $R) . ' ' . $f($cy - 0.44 * $R)
           . ' C' . $f($fx + 0.135 * $R) . ' ' . $f($cy - 0.26 * $R) . ' ' . $f($fx + 0.04 * $R) . ' ' . $f($cy - 0.24 * $R) . ' ' . $f($fx + 0.04 * $R) . ' ' . $f($cy - 0.1 * $R)
           . ' L' . $f($fx + 0.062 * $R) . ' ' . $f($cy + 0.74 * $R)
           . ' Q' . $f($fx) . ' ' . $f($cy + 0.86 * $R) . ' ' . $f($fx - 0.062 * $R) . ' ' . $f($cy + 0.74 * $R)
           . ' L' . $f($fx - 0.04 * $R) . ' ' . $f($cy - 0.1 * $R)
           . ' C' . $f($fx - 0.04 * $R) . ' ' . $f($cy - 0.24 * $R) . ' ' . $f($fx - 0.135 * $R) . ' ' . $f($cy - 0.26 * $R) . ' ' . $f($fx - 0.135 * $R) . ' ' . $f($cy - 0.44 * $R)
           . ' Z"/>';

    // Dairy cup, up and to the right. The icon draws it at 0.368 R; a larger
    // cup slides outward along the same line so it never covers the food.
    $r0  = 0.368 * $R;
    $cr  = min(0.54 * $R, $r0 * sqrt(max(0.0, $cupScale)));
    $rs  = max($cr, 0.12 * $R) + 0.085 * $R;
    $d   = max(1.52 * $R, 1.07 * $R + $rs);
    $dx  = $cx + 0.903 * $d; $dy = $cy - 0.429 * $d;
    $defs .= '<linearGradient id="' . $id . '-dairy" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#6CA3DC"/><stop offset="1" stop-color="#4F86CD"/></linearGradient>';
    $cup  = '<circle cx="' . $f($dx + 2) . '" cy="' . $f($dy + 3) . '" r="' . $f($rs) . '" fill="#000" opacity=".08"/>'
          . '<circle cx="' . $f($dx) . '" cy="' . $f($dy) . '" r="' . $f($rs) . '" fill="url(#' . $id . '-plate)" stroke="#D8D7D7" stroke-width="1.5"/>';
    $dairyLbl = (int)round($dairyPct) . '%';
    if ($cr > 0.5) {
        $cup .= '<circle cx="' . $f($dx) . '" cy="' . $f($dy) . '" r="' . $f($cr) . '" fill="url(#' . $id . '-dairy)"/>'
              . '<circle cx="' . $f($dx) . '" cy="' . $f($dy) . '" r="' . $f(max(0.0, $cr - $bw - $band / 2)) . '" fill="none" stroke="#82BAE7" stroke-width="' . $f($band) . '"/>'
              . '<circle cx="' . $f($dx) . '" cy="' . $f($dy) . '" r="' . $f(max(0.0, $cr - $bw / 2)) . '" fill="none" stroke="#3E6BA7" stroke-width="' . $f($bw) . '"/>';
    }
    $dfs = min(19.0, $cr * 0.34);
    if ($dfs >= 10.5) {
        $labels .= '<text x="' . $f($dx) . '" y="' . $f($dy - $dfs * 0.15) . '" text-anchor="middle" class="mp-lbl" font-size="' . $f($dfs) . '">Dairy</text>'
                 . '<text x="' . $f($dx) . '" y="' . $f($dy + $dfs * 0.95) . '" text-anchor="middle" class="mp-lbl" font-size="' . $f($dfs * 0.92) . '">' . $dairyLbl . '</text>';
    } else {
        $labels .= '<text x="' . $f($dx) . '" y="' . $f($dy + $rs + 14) . '" text-anchor="middle" class="mp-lbl-sm" font-size="11">Dairy ' . $dairyLbl . '</text>';
    }

    // Dessert plate, down and to the right, below the cup: a chocolate-chip
    // cookie whose area is the snacks' share on the same scale as the main
    // plate's sections — the main plate's food holds the other four groups'
    // share, so the cookie is that area times snacks ÷ the rest. An empty
    // dessert plate (the recommended one) still shows, labelled 0%. Like the
    // cup it slides outward as it grows, clear of the food and of the cup.
    $dessert = '';
    if (array_key_exists('snacks', $share)) {
        $sp  = max(0.0, (float)$share['snacks']);
        $sr  = $main > 0 ? min(0.54 * $R, $R * sqrt($sp / $main)) : 0.0;
        $ps  = max($sr + 0.1 * $R, 0.3 * $R);
        $d2  = max(1.55 * $R, 1.07 * $R + $ps);
        $sx  = $cx + 0.88 * $d2; $sy = $cy + 0.475 * $d2;
        $defs .= '<linearGradient id="' . $id . '-snack" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#B98049"/><stop offset="1" stop-color="#965F2E"/></linearGradient>';
        $dessert = '<circle cx="' . $f($sx + 2) . '" cy="' . $f($sy + 3) . '" r="' . $f($ps) . '" fill="#000" opacity=".08"/>'
                 . '<circle cx="' . $f($sx) . '" cy="' . $f($sy) . '" r="' . $f($ps) . '" fill="url(#' . $id . '-plate)" stroke="#D8D7D7" stroke-width="1.5"/>'
                 . '<circle cx="' . $f($sx) . '" cy="' . $f($sy) . '" r="' . $f($ps * 0.8) . '" fill="#F6F6F6" stroke="#E6E5E5" stroke-width="1.5"/>';
        if ($sr > 0.5) {
            $dessert .= '<circle cx="' . $f($sx) . '" cy="' . $f($sy) . '" r="' . $f($sr) . '" fill="url(#' . $id . '-snack)"/>'
                      . '<circle cx="' . $f($sx) . '" cy="' . $f($sy) . '" r="' . $f(max(0.0, $sr - $bw - $band / 2)) . '" fill="none" stroke="#E3B784" stroke-width="' . $f($band) . '"/>'
                      . '<circle cx="' . $f($sx) . '" cy="' . $f($sy) . '" r="' . $f(max(0.0, $sr - $bw / 2)) . '" fill="none" stroke="#6E431D" stroke-width="' . $f($bw) . '"/>';
            // Chocolate chips in a ring, leaving the middle to the label.
            if ($sr > 8) {
                foreach ([20, 80, 140, 205, 260, 320] as $deg) {
                    $a = deg2rad($deg);
                    $dessert .= '<ellipse cx="' . $f($sx + 0.62 * $sr * cos($a)) . '" cy="' . $f($sy + 0.62 * $sr * sin($a)) . '" rx="' . $f(0.1 * $sr) . '" ry="' . $f(0.075 * $sr)
                              . '" transform="rotate(' . $deg . ' ' . $f($sx + 0.62 * $sr * cos($a)) . ' ' . $f($sy + 0.62 * $sr * sin($a)) . ')" fill="#4A2A14"/>';
                }
            }
        }
        $snackLbl = (int)round($sp) . '%';
        $sfs = min(19.0, $sr * 0.34);
        if ($sfs >= 10.5) {
            $labels .= '<text x="' . $f($sx) . '" y="' . $f($sy - $sfs * 0.15) . '" text-anchor="middle" class="mp-lbl" font-size="' . $f($sfs) . '">Snacks</text>'
                     . '<text x="' . $f($sx) . '" y="' . $f($sy + $sfs * 0.95) . '" text-anchor="middle" class="mp-lbl" font-size="' . $f($sfs * 0.92) . '">' . $snackLbl . '</text>';
        } else {
            $labels .= '<text x="' . $f($sx) . '" y="' . $f($sy + $ps + 14) . '" text-anchor="middle" class="mp-lbl-sm" font-size="11">Snacks ' . $snackLbl . '</text>';
        }
    }

    return '<svg viewBox="0 0 563 475" role="img" aria-labelledby="' . $id . '-t" xmlns="http://www.w3.org/2000/svg">'
         . '<title id="' . $id . '-t">' . $e($title) . '</title><defs>' . $defs . '</defs>'
         . '<rect x="10" y="10" width="543" height="455" rx="60" fill="#B3DB18"/>'
         . '<g fill="#FFFFFF">' . $fork . '</g>'
         . '<circle cx="' . $f($cx - 0.04 * $R) . '" cy="' . $f($cy + 0.03 * $R) . '" r="' . $f(1.24 * $R) . '" fill="#DCDBDB"/>'
         . '<circle cx="' . $f($cx) . '" cy="' . $f($cy) . '" r="' . $f(1.2 * $R) . '" fill="url(#' . $id . '-plate)" stroke="#D8D7D7" stroke-width="1.5"/>'
         . '<circle cx="' . $f($cx) . '" cy="' . $f($cy) . '" r="' . $f(1.07 * $R) . '" fill="#F6F6F6" stroke="#E6E5E5" stroke-width="2"/>'
         . $body . $cup . $dessert . $labels . '</svg>';
}

// How tightly orders cluster around the average, judged by the standard
// deviation against the average itself (the coefficient of variation): under
// half the average and most orders land near it; past the average itself the
// group swings from little or none on many orders to a lot on a few. Returns
// [label, detail].
function opMpSpread(float $sd, float $mean): array {
    if ($mean <= 0) return ['&mdash;', ''];
    // Judged on the figure as printed, so an SD the table shows equal to the
    // average is never called wider than it.
    $cv    = round($sd / $mean, 2);
    $label = $cv < 0.5 ? 'Tightly clustered' : ($cv <= 1.0 ? 'Moderately spread' : 'Widely spread');
    if ($cv == 1.0)   $rel = 'about equal to the average';
    elseif ($cv < 1)  $rel = round(100 * $cv) . '% of the average';
    else              $rel = number_format($cv, 1) . '&times; the average';
    return [$label, 'SD is ' . $rel];
}

// One grocery item, centred on ($x, $y) in a cell about 30 px across, in its
// food group's plate colour: a milk carton, a drumstick, a loaf, a cookie, a
// head of broccoli or an apple. All five fill roughly the same area, so a cart of them
// reads as a count rather than as a contest of sizes.
function opCartItem(string $g, float $x, float $y): string {
    $p = function (float $dx, float $dy) use ($x, $y): string { return sprintf('%.1f,%.1f', $x + $dx, $y + $dy); };
    switch ($g) {
        case 'dairy':
            return '<polygon points="' . $p(-9, -4) . ' ' . $p(-5, -12) . ' ' . $p(5, -12) . ' ' . $p(9, -4) . '" fill="#E3EEFA" stroke="#3E6BA7" stroke-width="1.2"/>'
                 . '<rect x="' . sprintf('%.1f', $x - 5) . '" y="' . sprintf('%.1f', $y - 15) . '" width="10" height="3.5" fill="#FFFFFF" stroke="#3E6BA7" stroke-width="1"/>'
                 . '<rect x="' . sprintf('%.1f', $x - 9) . '" y="' . sprintf('%.1f', $y - 4) . '" width="18" height="18" fill="#FFFFFF" stroke="#3E6BA7" stroke-width="1.2"/>'
                 . '<rect x="' . sprintf('%.1f', $x - 9) . '" y="' . sprintf('%.1f', $y + 2) . '" width="18" height="9" fill="#4F86CD"/>';
        case 'protein':
            // A drumstick: an outlined bone with its two knuckles, under a
            // tear-drop of meat that narrows onto it.
            return '<path d="M' . $p(2, 2) . ' L' . $p(10, 10) . '" stroke="#8C8577" stroke-width="7" stroke-linecap="round"/>'
                 . '<circle cx="' . sprintf('%.1f', $x + 12.5) . '" cy="' . sprintf('%.1f', $y + 9) . '" r="3.6" fill="#F4EFE4" stroke="#8C8577" stroke-width="1.3"/>'
                 . '<circle cx="' . sprintf('%.1f', $x + 9) . '" cy="' . sprintf('%.1f', $y + 12.5) . '" r="3.6" fill="#F4EFE4" stroke="#8C8577" stroke-width="1.3"/>'
                 . '<path d="M' . $p(2, 2) . ' L' . $p(10, 10) . '" stroke="#F4EFE4" stroke-width="4.4" stroke-linecap="round"/>'
                 . '<path d="M' . $p(5, 5) . ' C' . $p(1, 9) . ' ' . $p(-10, 7) . ' ' . $p(-13, -1) . ' C' . $p(-16, -10) . ' ' . $p(-10, -16) . ' ' . $p(-1, -13) . ' C' . $p(7, -10) . ' ' . $p(9, 1) . ' ' . $p(5, 5) . ' Z" fill="#5B3F8E" stroke="#3F2A66" stroke-width="1.2"/>'
                 . '<path d="M' . $p(-9, -6) . ' Q' . $p(-8, -11) . ' ' . $p(-3, -11) . '" stroke="#9D79C3" stroke-width="2.2" fill="none" stroke-linecap="round"/>';
        case 'grains':
            return '<path d="M' . $p(-14, 12) . ' L' . $p(-14, -2) . ' C' . $p(-14, -13) . ' ' . $p(14, -13) . ' ' . $p(14, -2) . ' L' . $p(14, 12) . ' Z" fill="#D45914" stroke="#A9460F" stroke-width="1.2" stroke-linejoin="round"/>'
                 . '<path d="M' . $p(-7, -8) . ' L' . $p(-3, -3) . ' M' . $p(-1, -9) . ' L' . $p(3, -4) . ' M' . $p(5, -8) . ' L' . $p(9, -3) . '" stroke="#F0C9A0" stroke-width="1.8" stroke-linecap="round"/>';
        case 'vegetables':
            return '<path d="M' . $p(-4, 2) . ' L' . $p(-5, 14) . ' Q' . $p(0, 16) . ' ' . $p(5, 14) . ' L' . $p(4, 2) . ' Z" fill="#9BCB55" stroke="#5D8A2A" stroke-width="1"/>'
                 . '<circle cx="' . sprintf('%.1f', $x - 7) . '" cy="' . sprintf('%.1f', $y - 2) . '" r="7" fill="#2CAC42" stroke="#168235" stroke-width="1.2"/>'
                 . '<circle cx="' . sprintf('%.1f', $x + 7) . '" cy="' . sprintf('%.1f', $y - 2) . '" r="7" fill="#2CAC42" stroke="#168235" stroke-width="1.2"/>'
                 . '<circle cx="' . sprintf('%.1f', $x) . '" cy="' . sprintf('%.1f', $y - 8) . '" r="8" fill="#3DB64F" stroke="#168235" stroke-width="1.2"/>';
        case 'snacks':
            // A chocolate-chip cookie, as on the dessert plate.
            $chips = '';
            foreach ([[-6, -5], [5, -7], [7, 4], [-3, 6], [-8, 3], [1, -1]] as [$cx, $cy]) {
                $chips .= '<ellipse cx="' . sprintf('%.1f', $x + $cx) . '" cy="' . sprintf('%.1f', $y + $cy) . '" rx="2" ry="1.5" fill="#4A2A14"/>';
            }
            return '<circle cx="' . sprintf('%.1f', $x) . '" cy="' . sprintf('%.1f', $y) . '" r="13.5" fill="#B57D44" stroke="#6E431D" stroke-width="1.3"/>'
                 . '<circle cx="' . sprintf('%.1f', $x) . '" cy="' . sprintf('%.1f', $y) . '" r="10.5" fill="none" stroke="#E3B784" stroke-width="1.2" opacity=".7"/>'
                 . $chips;
        case 'fruits':
        default:
            return '<path d="M' . $p(0, -8) . ' C' . $p(-4, -13) . ' ' . $p(-14, -12) . ' ' . $p(-14, -1) . ' C' . $p(-14, 9) . ' ' . $p(-7, 14) . ' ' . $p(-3, 13) . ' C' . $p(-1, 12.5) . ' ' . $p(1, 12.5) . ' ' . $p(3, 13) . ' C' . $p(7, 14) . ' ' . $p(14, 9) . ' ' . $p(14, -1) . ' C' . $p(14, -12) . ' ' . $p(4, -13) . ' ' . $p(0, -8) . ' Z" fill="#D01A21" stroke="#A9151A" stroke-width="1.2"/>'
                 . '<path d="M' . $p(0, -8) . ' Q' . $p(1, -13) . ' ' . $p(3, -15) . '" stroke="#6B4C11" stroke-width="2" fill="none" stroke-linecap="round"/>'
                 . '<ellipse cx="' . sprintf('%.1f', $x + 6.5) . '" cy="' . sprintf('%.1f', $y - 13) . '" rx="4.5" ry="2.2" transform="rotate(-30 ' . $p(6.5, -13) . ')" fill="#2CAC42"/>'
                 . '<ellipse cx="' . sprintf('%.1f', $x - 6) . '" cy="' . sprintf('%.1f', $y - 3) . '" rx="2.5" ry="4" fill="#FFFFFF" opacity=".35"/>';
    }
}

// A shopping cart filled with $units grocery items per food group, packed
// bottom up in the order given (heavy cartons and meat at the bottom, fruit
// on top). The basket's wire mesh is drawn over the load, and the top row
// rides above the rim, so it reads as a full cart rather than a chart.
function opCartSvg(array $units, string $title): string {
    $f = function (float $v): string { return sprintf('%.1f', $v); };
    // Basket: a trapezoid, wider at the top.
    $tl = [78, 96]; $tr = [412, 96]; $br = [382, 222]; $bl = [108, 222];
    $edge = function (float $y) use ($tl, $bl, $tr, $br): array {
        $t = ($bl[1] - $y) / ($bl[1] - $tl[1]);
        return [$bl[0] + ($tl[0] - $bl[0]) * $t, $br[0] + ($tr[0] - $br[0]) * $t];
    };
    $rows  = [[203, 7], [169, 7], [135, 8], [101, 8]];   // [centre y, items], bottom up
    $queue = [];
    foreach ($units as $g => $n) for ($i = 0; $i < $n; $i++) $queue[] = $g;
    $items = '';
    $k = 0;
    foreach ($rows as [$y, $n]) {
        [$lx, $rx] = $edge($y);
        $step = min(40.0, ($rx - $lx - 12) / $n);
        $x0   = ($lx + $rx) / 2 - $step * ($n - 1) / 2;
        for ($i = 0; $i < $n && $k < count($queue); $i++, $k++) {
            // Drawn a fifth larger than the icon's own 30 px, about its centre,
            // so neighbours just touch the way groceries pack.
            $cx = $x0 + $i * $step;
            $items .= '<g transform="matrix(1.2 0 0 1.2 ' . $f(-0.2 * $cx) . ' ' . $f(-0.2 * $y) . ')">'
                    . opCartItem($queue[$k], $cx, $y) . '</g>';
        }
    }

    // Wire mesh: verticals between the rims, horizontals across the basket.
    $mesh = '';
    for ($i = 1; $i < 10; $i++) {
        $t = $i / 10;
        $mesh .= '<line x1="' . $f($tl[0] + ($tr[0] - $tl[0]) * $t) . '" y1="' . $tl[1] . '" x2="' . $f($bl[0] + ($br[0] - $bl[0]) * $t) . '" y2="' . $bl[1] . '"/>';
    }
    foreach ([138, 180] as $y) {
        [$lx, $rx] = $edge($y);
        $mesh .= '<line x1="' . $f($lx) . '" y1="' . $y . '" x2="' . $f($rx) . '" y2="' . $y . '"/>';
    }
    $basket = $tl[0] . ',' . $tl[1] . ' ' . $tr[0] . ',' . $tr[1] . ' ' . $br[0] . ',' . $br[1] . ' ' . $bl[0] . ',' . $bl[1];

    return '<svg viewBox="0 20 440 290" role="img" aria-label="' . htmlspecialchars($title, ENT_QUOTES) . '" xmlns="http://www.w3.org/2000/svg">'
         . '<title>' . htmlspecialchars($title, ENT_QUOTES) . '</title>'
         . '<polygon points="' . $basket . '" fill="#F3F3F1"/>'
         . $items
         . '<g stroke="#7D7D7D" stroke-width="1.5" opacity=".45">' . $mesh . '</g>'
         . '<g fill="none" stroke="#555" stroke-width="4.5" stroke-linecap="round" stroke-linejoin="round">'
         .   '<polygon points="' . $basket . '"/>'
         .   '<polyline points="24,58 58,58 78,96"/>'
         .   '<polyline points="108,222 118,252 372,252 382,222"/>'
         .   '<line x1="128" y1="252" x2="138" y2="268"/><line x1="362" y1="252" x2="352" y2="268"/>'
         . '</g>'
         . '<rect x="18" y="52" width="26" height="12" rx="6" fill="#2F6FA1"/>'
         . '<g fill="#FFFFFF" stroke="#444" stroke-width="5"><circle cx="140" cy="282" r="14"/><circle cx="350" cy="282" r="14"/></g>'
         . '<g fill="#444"><circle cx="140" cy="282" r="4"/><circle cx="350" cy="282" r="4"/></g>'
         . '</svg>';
}

// Signed gap from the guideline, in percentage points.
function opMpDiff(int $pts): string {
    if ($pts === 0) return 'on target';
    return ($pts > 0 ? '▲ ' : '▼ ') . abs($pts) . (abs($pts) === 1 ? ' pt' : ' pts');
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
  /* Plate pair: the two plates share one viewBox, so equal widths mean
     equal scale and the sections compare directly. */
  .plate-pair { display:grid; grid-template-columns:repeat(auto-fit, minmax(260px,1fr)); gap:18px; margin:6px 0 16px; }
  .plate-pair figure { margin:0; text-align:center; }
  .plate-pair svg { width:100%; height:auto; display:block; }
  .plate-pair figcaption { margin-top:8px; font-size:.82rem; color:#666; }
  .plate-pair figcaption strong { display:block; color:var(--brown); font-size:.9rem;
                                  text-transform:uppercase; letter-spacing:.5px; }
  .mp-lbl    { fill:#fff; font-weight:700; paint-order:stroke; stroke:rgba(0,0,0,.18); stroke-width:2.5px; }
  .mp-lbl-sm { fill:#333; font-weight:700; paint-order:stroke; stroke:#fff; stroke-width:3px; }
  .mp-swatch { display:inline-block; width:11px; height:11px; border-radius:3px; vertical-align:-1px; margin-right:7px; }
  .mp-sub { display:block; font-size:.72rem; color:#888; margin-left:18px; }
  .mp-scroll td:first-child { white-space:nowrap; }
  .mp-scroll td:first-child .mp-sub { white-space:normal; max-width:150px; }
  .mp-spread { font-weight:700; color:var(--brown); white-space:nowrap; }
  .mp-note { display:block; font-size:.72rem; color:#888; }
  /* Fruits and vegetables listed under their combined guideline row. */
  table.data tr.mp-part td { color:#777; font-size:.82rem; padding-top:5px; padding-bottom:5px; }
  table.data tr.mp-part td:first-child { padding-left:30px; }
  .mp-off { font-size:.82rem; color:#666; line-height:1.6; margin-top:10px; }
  .mp-scroll { overflow-x:auto; }
  @media (max-width:860px) {
    .mp-scroll table.data th, .mp-scroll table.data td { padding-left:6px; padding-right:6px; }
  }
  @media (max-width:520px) {
    .mp-scroll table.data th, .mp-scroll table.data td { padding:7px 5px; font-size:.78rem; }
    .mp-scroll table.data th { font-size:.66rem; }
  }
  details.mp-items { margin-top:12px; font-size:.8rem; color:#555; }
  details.mp-items summary { cursor:pointer; color:var(--brown); font-weight:700; }
  .mp-groups { display:grid; grid-template-columns:repeat(auto-fit, minmax(210px,1fr)); gap:14px 22px; margin-top:12px; }
  .mp-groups h4 { font-size:.74rem; text-transform:uppercase; letter-spacing:.4px; color:var(--brown);
                  display:flex; justify-content:space-between; margin-bottom:4px; }
  .mp-groups ul { list-style:none; padding:0; margin:0; }
  .mp-groups li { display:flex; justify-content:space-between; gap:8px; padding:2px 0;
                  border-bottom:1px dotted var(--border); }
  .mp-groups li span:last-child { font-variant-numeric:tabular-nums; white-space:nowrap; }
  .cart-pair { display:grid; grid-template-columns:repeat(auto-fit, minmax(280px,1fr)); gap:22px; align-items:start; }
  .cart-fig { margin:0; text-align:center; }
  .cart-fig svg { width:100%; max-width:440px; height:auto; display:block; margin:0 auto; }
  .cart-legend { display:flex; flex-wrap:wrap; justify-content:center; gap:6px 14px; font-size:.8rem; color:#555; margin-top:4px; }
  .cart-legend strong { color:var(--brown); }
  .cart-days { margin-top:16px; padding:14px 10px; background:var(--cat-bg); border-radius:10px; }
  .cart-days .v { font-size:3rem; font-weight:800; color:var(--brown); line-height:1; }
  .cart-days .k { font-size:.8rem; font-weight:700; text-transform:uppercase; letter-spacing:.6px; color:var(--brown); margin-top:4px; }
  .cart-days .sub { font-size:.8rem; color:#666; margin-top:4px; }
  table.kcal-table th, table.kcal-table td { padding:6px 8px; font-size:.84rem; }
  table.kcal-table th { font-size:.7rem; }
  .mp-tag { font-size:.62rem; font-weight:700; text-transform:uppercase; color:#fff; background:#2F6FA1;
            border-radius:3px; padding:0 4px; margin-left:4px; vertical-align:1px; }
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
        <div>
          <label for="activity" title="Activity level the Dietary Guidelines' daily calorie needs are read at, for Days of Food.">Activity Level</label>
          <select id="activity" name="activity">
            <?php foreach ($activityLevels as $k => $label): ?>
              <option value="<?= $k ?>"<?= $k === $activity ? ' selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="row" style="margin-top:14px;">
        <button type="submit" class="btn btn-primary" style="flex:0 0 170px;">📊 Run Report</button>
        <a href="?reset=1" class="btn btn-secondary" style="flex:0 0 100px; text-align:center; text-decoration:none;">↺ Reset</a>
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

  <div class="card">
    <h2>🍽 The Average Order on the Plate</h2>
    <p class="lede" style="margin-bottom:10px;">
      How a household's food measures up against accepted dietary guidelines for a
      balanced plate: <strong>half fruits and vegetables, a quarter grains, a quarter
      protein</strong>, with dairy on the side. On the left is the recommended plate; on
      the right, the same plate with each section resized to that food group's share of
      the average order's pounds, so a larger section means more of the order. The dairy
      cup grows or shrinks against the recommended cup the same way. Snack foods and
      sweets go on the <strong>dessert plate</strong> below the cup and count toward the
      plate's 100%; the guidelines give them no portion, only advice to limit them, so
      the recommended dessert plate is empty.
    </p>
    <?php if ($hasMyPlate):
      $mpSwatch = ['fruits' => '#D01A21', 'vegetables' => '#2CAC42', 'grains' => '#D45914',
                   'protein' => '#5B3F8E', 'snacks' => '#A86F38', 'dairy' => '#4F86CD'];
      $mpTitle  = 'Our average order: ';
      foreach ($mpPlate as $g => $label) $mpTitle .= strtolower($label) . ' ' . $mpShare[$g] . '%, ';
      $mpTitle .= 'and dairy ' . round($mpDairyPct) . '% of the plate';
      // Fruits and vegetables are one guideline figure, compared together.
      $mpProduce = $mpShare['fruits'] + $mpShare['vegetables'];
      $mpMean    = $mpLb + ['produce' => $mpLb['fruits'] + $mpLb['vegetables'],
                            'total'   => $mpPlateLb + $mpLb['dairy']];
      // The Std Dev and Spread cells for one row.
      $mpSdCells = function (string $g) use ($mpSd, $mpMean): string {
          [$label, $detail] = opMpSpread($mpSd[$g], $mpMean[$g]);
          return '<td class="num">' . ($mpMean[$g] > 0 ? '&plusmn;&nbsp;' . opN($mpSd[$g], 1) : '&mdash;') . '</td>'
               . '<td><span class="mp-spread">' . $label . '</span>'
               . ($detail !== '' ? '<span class="mp-note">' . $detail . '</span>' : '') . '</td>';
      }; ?>
      <div class="plate-pair">
        <figure>
          <?= opMyPlateSvg(['fruits' => 0, 'vegetables' => $mpGuide['produce'],
                            'grains' => $mpGuide['grains'], 'protein' => $mpGuide['protein'],
                            'snacks' => $mpGuide['snacks']],
                1.0, $mpGuideDairy, 'mpg',
                'Accepted guidelines: fruits and vegetables 50%, grains 25%, protein 25%, an empty dessert plate for snacks, and a dairy cup',
                ['vegetables' => ['Fruits &', 'Vegetables']]) ?>
          <figcaption><strong>Accepted Guidelines</strong>the recommended plate</figcaption>
        </figure>
        <figure>
          <?= opMyPlateSvg($mpShare, $mpDairyPct / $mpGuideDairy, $mpDairyPct, 'mpa', $mpTitle) ?>
          <figcaption><strong>Our Average Order</strong>
            <?= opN($mpPlateLb, 1) ?> lb on the plates &middot; <?= opN($mpLb['dairy'], 1) ?> lb of dairy</figcaption>
        </figure>
      </div>

      <div class="mp-scroll">
      <table class="data">
        <thead>
          <tr>
            <th>Food Group</th>
            <th class="num">Guideline</th>
            <th class="num">Our Average Order</th>
            <th class="num">Difference</th>
            <th class="num">Avg lb per Order</th>
            <th class="num" title="Standard deviation across orders: how far a single order's pounds of the group typically sit from the average.">Std Dev (lb)</th>
            <th title="The standard deviation read against the average: under half of it is tightly clustered, over the average itself is widely spread.">Spread</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td><span class="mp-swatch" style="background:<?= $mpSwatch['vegetables'] ?>;"></span>Fruits &amp; Vegetables</td>
            <td class="num"><?= $mpGuide['produce'] ?>%</td>
            <td class="num"><?= $mpProduce ?>%</td>
            <td class="num"><?= opMpDiff($mpProduce - $mpGuide['produce']) ?></td>
            <td class="num"><?= opN($mpMean['produce'], 1) ?></td>
            <?= $mpSdCells('produce') ?>
          </tr>
          <?php foreach (['fruits', 'vegetables'] as $g): ?>
          <tr class="mp-part">
            <td><span class="mp-swatch" style="background:<?= $mpSwatch[$g] ?>;"></span><?= $mpPlate[$g] ?></td>
            <td class="num"></td>
            <td class="num"><?= $mpShare[$g] ?>%</td>
            <td class="num"></td>
            <td class="num"><?= opN($mpLb[$g], 1) ?></td>
            <?= $mpSdCells($g) ?>
          </tr>
          <?php endforeach; ?>
          <?php foreach (['grains', 'protein'] as $g): ?>
          <tr>
            <td><span class="mp-swatch" style="background:<?= $mpSwatch[$g] ?>;"></span><?= $mpPlate[$g] ?></td>
            <td class="num"><?= $mpGuide[$g] ?>%</td>
            <td class="num"><?= $mpShare[$g] ?>%</td>
            <td class="num"><?= opMpDiff($mpShare[$g] - $mpGuide[$g]) ?></td>
            <td class="num"><?= opN($mpLb[$g], 1) ?></td>
            <?= $mpSdCells($g) ?>
          </tr>
          <?php endforeach; ?>
          <tr>
            <td><span class="mp-swatch" style="background:<?= $mpSwatch['snacks'] ?>;"></span>Snacks
              <span class="mp-sub">the dessert plate: snack foods &amp; sweets</span></td>
            <td class="num"><?= $mpGuide['snacks'] ?>%</td>
            <td class="num"><?= $mpShare['snacks'] ?>%</td>
            <td class="num"><?= opMpDiff($mpShare['snacks'] - $mpGuide['snacks']) ?></td>
            <td class="num"><?= opN($mpLb['snacks'], 1) ?></td>
            <?= $mpSdCells('snacks') ?>
          </tr>
          <tr>
            <td><span class="mp-swatch" style="background:<?= $mpSwatch['dairy'] ?>;"></span>Dairy
              <span class="mp-sub">the cup, as a share of the plates</span></td>
            <td class="num"><?= round($mpGuideDairy) ?>%</td>
            <td class="num"><?= round($mpDairyPct) ?>%</td>
            <td class="num"><?= opMpDiff((int)round($mpDairyPct) - (int)round($mpGuideDairy)) ?></td>
            <td class="num"><?= opN($mpLb['dairy'], 1) ?></td>
            <?= $mpSdCells('dairy') ?>
          </tr>
        </tbody>
        <tfoot>
          <tr class="total-row">
            <td colspan="4">Plates and cup together</td>
            <td class="num"><?= opN($mpMean['total'], 1) ?></td>
            <?= $mpSdCells('total') ?>
          </tr>
        </tfoot>
      </table>
      </div>

      <?php
        $mpOffParts = [];
        foreach ($mpOff as $g => $label) {
            if ($mpLb[$g] >= 0.05) $mpOffParts[] = '<strong>' . opN($mpLb[$g], 1) . ' lb</strong> of ' . strtolower($label);
        }
        if (count($mpOffParts) > 1) $mpOffParts[] = 'and ' . array_pop($mpOffParts);
      ?>
      <p class="mp-off">
        <strong>Std Dev</strong> is how far a single order's pounds of a group typically sit
        from the average; <strong>Spread</strong> reads it against the average &mdash; under
        half of it, orders are tightly clustered; beyond the average itself, they range from
        little or none to a lot.
        <?php if ($mpOffParts): ?>
          Also in the average order, but on no part of the plate:
          <?= implode(count($mpOffParts) > 2 ? ', ' : ' ', $mpOffParts) ?>.
        <?php endif; ?>
        Non-food items &mdash; pet food, diapers, household goods &mdash; are left out entirely.
        <?php if ($mpUseCounter): ?>
          Counter items come from the PantryPrep counter's own record of
          <?= opN($reqOrders) ?> requests, so their <?= opN($mpDupScans) ?> checkout
          scans are set aside rather than counted twice.
        <?php endif; ?>
        <?php if ($mpNoWtPieces > 0): ?>
          <?= opN($mpNoWtPieces) ?> pieces of produce sold by the piece have no
          <strong>Avg Wt (lb ea)</strong> on the Inventory page yet and count at
          <?= htmlspecialchars((string)$lbPerItem) ?> lb each &mdash; setting one (a lemon
          is nearer &frac14; lb) sharpens the fruit and vegetable shares.
        <?php endif; ?>
      </p>

      <details class="mp-items">
        <summary>Which items count toward each group</summary>
        <div class="mp-groups">
          <?php foreach (['fruits' => 'Fruits', 'vegetables' => 'Vegetables', 'grains' => 'Grains',
                          'protein' => 'Protein', 'snacks' => 'Snacks', 'dairy' => 'Dairy'] + $mpOff as $g => $label):
            if (empty($mpItems[$g])) continue;
            $list = $mpItems[$g];
            uasort($list, function ($a, $b) { return $b['lb'] <=> $a['lb']; });
            $shown = array_slice($list, 0, 12, true); ?>
          <div>
            <h4><span><?= $label ?></span><span><?= opN($mpLb[$g], 1) ?> lb</span></h4>
            <ul>
              <?php foreach ($shown as $n => $it): ?>
              <li><span><?= htmlspecialchars($n) ?><?= $it['counter'] ? '<span class="mp-tag">counter</span>' : '' ?></span>
                  <span><?= opN($it['lb'], $it['lb'] < 1 ? 2 : 1) ?></span></li>
              <?php endforeach; ?>
              <?php if (count($list) > count($shown)): ?>
              <li><span style="color:#999;">and <?= opN(count($list) - count($shown)) ?> more</span><span></span></li>
              <?php endif; ?>
            </ul>
          </div>
          <?php endforeach; ?>
        </div>
        <p class="mp-off">Pounds per average order. Items are sorted by name &mdash; see
          the Methodology card for the rules.</p>
      </details>
    <?php elseif ($totOrders === 0): ?>
      <div class="no-data">No scanned orders in this period. The plate needs the scanned
        half of the order &mdash; counter requests alone are only the counter's items.</div>
    <?php else: ?>
      <div class="no-data">None of the food scanned in this period falls into one of the plate's food groups.</div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>🛒 How Long the Average Order Lasts</h2>
    <p class="lede" style="margin-bottom:12px;">
      The average order's calories set against what the average household needs each
      day, by the Dietary Guidelines' estimates of daily calorie needs. The cart holds
      the average order's food groups in proportion &mdash; each item is the same share
      of its pounds &mdash; and the figure under it is how many days that order would
      feed the household.
    </p>
    <?php if ($hasMyPlate):
      $cartSwatch = ['fruits' => '#D01A21', 'vegetables' => '#2CAC42', 'grains' => '#D45914',
                     'protein' => '#5B3F8E', 'snacks' => '#A86F38', 'dairy' => '#4F86CD'];
      $cartTitle  = 'Shopping cart of the average order: ';
      foreach (array_keys($cartSwatch) as $g) {
          $cartTitle .= $cartUnits[$g] . ' ' . strtolower($kcalGroups[$g]) . ', ';
      }
      $cartTitle = rtrim($cartTitle, ', ') . ' of ' . CART_UNITS . ' items'; ?>
      <div class="cart-pair">
        <figure class="cart-fig">
          <?= opCartSvg($cartUnits, $cartTitle) ?>
          <div class="cart-legend">
            <?php foreach (array_keys($cartSwatch) as $g): ?>
              <span><span class="mp-swatch" style="background:<?= $cartSwatch[$g] ?>;"></span><?= $kcalGroups[$g] ?>
                <strong><?= $cartLb > 0 ? round(100 * $mpLb[$g] / $cartLb) : 0 ?>%</strong></span>
            <?php endforeach; ?>
          </div>
          <div class="cart-days">
            <?php if ($hasDays): ?>
              <div class="v"><?= opN($daysFood, 1) ?></div>
              <div class="k">days of food</div>
              <div class="sub">for an average household of <?= opN($hhAdults, 1) ?> adults and
                <?= opN($hhKids, 1) ?> children</div>
            <?php else: ?>
              <div class="v">&mdash;</div>
              <div class="k">days of food</div>
              <div class="sub">No counter requests in this period record a household size,
                so there is no average household to measure against.</div>
            <?php endif; ?>
          </div>
        </figure>

        <div>
          <table class="data kcal-table">
            <thead>
              <tr>
                <th>Calories in the Average Order</th>
                <th class="num">lb</th>
                <th class="num">kcal per lb</th>
                <th class="num">kcal</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($kcalGroups as $g => $label):
                if (!isset($cartSwatch[$g]) && $mpLb[$g] < 0.05) continue; ?>
              <tr>
                <td><?php if (isset($cartSwatch[$g])): ?><span class="mp-swatch" style="background:<?= $cartSwatch[$g] ?>;"></span><?php endif; ?><?= $label ?></td>
                <td class="num"><?= opN($mpLb[$g], 1) ?></td>
                <td class="num"><?= $mpLb[$g] > 0 ? opN($kcalByGroup[$g] / $mpLb[$g]) : '&mdash;' ?></td>
                <td class="num"><?= opN($kcalByGroup[$g]) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr class="total-row">
                <td>Average order</td>
                <td class="num"><?= opN(array_sum(array_intersect_key($mpLb, $kcalGroups)), 1) ?></td>
                <td></td>
                <td class="num"><?= opN($kcalOrder) ?></td>
              </tr>
            </tfoot>
          </table>

          <table class="data kcal-table" style="margin-top:14px;">
            <thead>
              <tr>
                <th>Household Needs per Day</th>
                <th class="num">People</th>
                <th class="num">kcal each</th>
                <th class="num">kcal</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td>Adults</td>
                <td class="num"><?= $hhOrders > 0 ? opN($hhAdults, 2) : '&mdash;' ?></td>
                <td class="num"><?= opN($kcalAdult) ?></td>
                <td class="num"><?= $hhOrders > 0 ? opN($hhAdults * $kcalAdult) : '&mdash;' ?></td>
              </tr>
              <tr>
                <td>Children</td>
                <td class="num"><?= $hhOrders > 0 ? opN($hhKids, 2) : '&mdash;' ?></td>
                <td class="num"><?= opN($kcalChild) ?></td>
                <td class="num"><?= $hhOrders > 0 ? opN($hhKids * $kcalChild) : '&mdash;' ?></td>
              </tr>
            </tbody>
            <tfoot>
              <tr class="total-row">
                <td colspan="3">Average household, per day</td>
                <td class="num"><?= $hhOrders > 0 ? opN($hhKcalDay) : '&mdash;' ?></td>
              </tr>
            </tfoot>
          </table>

          <p class="mp-off">
            <?php if ($hasDays): ?>
              <strong><?= opN($kcalOrder) ?> kcal &divide; <?= opN($hhKcalDay) ?> kcal a day
              = <?= opN($daysFood, 1) ?> days.</strong>
            <?php endif; ?>
            Daily needs are the Dietary Guidelines' estimates for a
            <strong><?= strtolower($activityLevels[$activity]) ?></strong> person (change it under
            Activity Level above), averaged over every age from 19 up for adults and 2&ndash;18 for
            children<?= $hhOrders > 0 ? ', and the household is the average of ' . opN($hhOrders)
              . ' counter requests that record their size' : '' ?>. Every food counts toward
            the calories, on the plate or off it; non-food is left out.
          </p>
        </div>
      </div>
    <?php else: ?>
      <div class="no-data">No scanned orders with food in this period, so there is no average
        order to measure.</div>
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
      <li><strong>The plate is a comparison by weight.</strong> The recommended plate
        follows the split accepted healthy-eating guidelines share: half the plate fruits
        and vegetables, a quarter grains and a quarter protein. The guidelines set no
        split between fruit and vegetables, so the two are compared as one figure, with
        each still shown on its own for the average order. Snack foods and sweets sit on
        a dessert plate that counts toward the same 100%; the guidelines give them no
        portion, only advice to limit them, so their recommended share is 0% and every
        pound of them is a pound the other groups don't get. Dairy is the cup beside the
        plates, so it is measured against them: the Dietary Guidelines' 2,000-calorie
        pattern pairs 3 cups of dairy with the 4&frac12; cups of fruit and vegetables that
        fill half the plate, which puts the cup at <?= round($mpGuideDairy) ?>% of the
        plate. The average order is weighed
        the same way as the rest of this page &mdash; produce at its scale weight, or its
        Avg Wt when sold by the piece; milk from the size in its generic name at 8.6 lb a
        gallon; everything else at its Avg Wt, or
        <?= htmlspecialchars((string)$lbPerItem) ?> lb each with none on record &mdash;
        and is the average scanned basket (<?= opN($totOrders) ?> orders, counter items
        set aside) plus the average counter request<?= $mpUseCounter
          ? ' (' . opN($reqOrders) . ' requests, filled units only)' : '' ?>. The two are
        averaged separately because the counter records every visit and the scanner only
        some. Items are sorted into groups by name. Anything named as a snack food or a
        sweet &mdash; snacks, chips, crackers, pretzels, popcorn, cookies, cakes, brownies,
        pastries, donuts, muffins, pies, candy, chocolate, pudding and the like &mdash; goes on
        the dessert plate first, whatever else its name says. The rest follow the standard
        food-group rules: butter, oils, sugars, drinks and condiments belong to no group,
        almond and oat milk are not dairy, and eggs, beans and nuts are protein; soups and
        other mixed dishes are left off the plate because their pounds can't be split
        between groups. The standard deviation is taken across orders, counting an order
        with none of a group as zero; since a scanned basket can't be paired with its
        counter request, the spread of each is measured separately and the two combined
        as though independent. Larger households take more at both, so for protein and
        dairy, the groups the counter carries, the true spread is if anything a little
        wider. A spread is called tightly clustered when the standard deviation is under
        half the average, moderately spread up to the average itself, and widely spread
        beyond it.</li>
      <li><strong>Days of food is a calorie estimate.</strong> Daily calorie needs come from
        Table A2-2 of the <em>Dietary Guidelines for Americans, 2020&ndash;2025</em>
        (dietaryguidelines.gov), the most recent edition that gives them by age, sex and
        activity level; the 2025&ndash;2030 edition works from a 2,000-calorie pattern
        instead. The counter form records how many adults and children a household has but
        not their ages, so an adult is the table's average over every age from 19 up
        (<?= opN($kcalAdult) ?> kcal a day at the chosen activity level) and a child its
        average over ages 2&ndash;18 (<?= opN($kcalChild) ?> kcal), men and women equally.
        The average order's calories come from its pounds of each kind of food at rounded
        typical values from USDA FoodData Central &mdash; about 100 kcal a pound for fresh
        vegetables, 200 for fresh fruit net of peel, 1,200 for bread, 1,650 for dry pasta and
        rice, 900 for meat, 230 for milk and 3,250 for butter &mdash; so the figure inherits
        every weight assumption above, the
        <?= htmlspecialchars((string)$lbPerItem) ?> lb packaged-item guess included. It
        assumes the household eats nothing but this order, and counts every food, butter and
        snacks too.</li>
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
            'items' => $r['items'],
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
          }, afterLabel: function (c) {
            var items = top[c.dataIndex].items;
            return (items.length > 1 || items[0] !== top[c.dataIndex].name)
              ? 'Includes: ' + items.join(', ') : '';
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
