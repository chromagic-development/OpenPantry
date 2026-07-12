<?php
// Shared layout for every FoodScan page. Reuses PantryPrep color tokens.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php'; // defines AUTH_COOKIE; no auth check fires until a page calls requireLogin/requireAllowedIP

// Force a consistent timezone for every page so the timestamp `now()` writes
// to the scans table matches the date used by report filters. Without this,
// a scan at 8 pm ET on a UTC-default server lands as the next calendar day.
date_default_timezone_set('America/New_York');

getDB(); // ensure schema exists on first hit

function fsPrefix(): string {
    // Pages in subfolders (e.g. /foodscan/scan/) set $GLOBALS['FS_PREFIX']='../'
    // before requiring common.php so the shared header/nav resolves links and
    // assets correctly regardless of how deep the page sits.
    return $GLOBALS['FS_PREFIX'] ?? '';
}

function renderHead(string $title): void {
    $p = fsPrefix();
    ?><!DOCTYPE html>
<html lang="en">
<head>
<link rel="icon" type="image/x-icon" href="<?= $p ?>menucounter/favicon.ico">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($title) ?> – OpenPantry</title>
<style>
  :root {
    --brown:  #6B4C11;
    --green:  #8BAF3A;
    --light:  #F5F0E8;
    --border: #D4C9A8;
    --text:   #333;
    --cat-bg: #EEE8D5;
    --blue:   #0056b3;
    --red:    #b1452a;
  }
  * { box-sizing: border-box; margin: 0; padding: 0; }
  html { font-size: 18px; }
  body { font-family: Arial, sans-serif; background: var(--light); color: var(--text); min-height: 100vh; }

  .site-header {
    background: #fff; border-bottom: 3px solid var(--green);
    padding: 14px 24px; display: flex; align-items: center; gap: 16px;
    box-shadow: 0 2px 6px rgba(0,0,0,.08);
  }
  .site-header img { height: 56px; }
  .header-text h1 { font-size: 1.1rem; color: var(--brown); font-weight: 700; text-transform: uppercase; }
  .header-text p  { font-size: .8rem; color: #777; }

  nav.subnav {
    background: #fff; border-bottom: 1px solid var(--border);
    padding: 8px 24px; display: flex; gap: 6px; flex-wrap: wrap;
  }
  nav.subnav a {
    text-decoration: none; color: var(--brown); padding: 6px 12px;
    border-radius: 6px; font-size: .85rem; font-weight: 700;
  }
  nav.subnav a.active, nav.subnav a:hover { background: var(--cat-bg); }
  nav.subnav .nav-logout { margin-left: auto; color: #8B1A1A; }

  /* Dropdown groups: the trigger looks like the flat nav links so the bar
     reads as one consistent row, and the panel anchors under it. The
     panel opens on click (handled in renderNav's inline JS) and on hover
     for desktop convenience. */
  nav.subnav .nav-group { position: relative; display: inline-flex; }
  nav.subnav .nav-trigger {
    background: transparent; border: none; cursor: pointer;
    color: var(--brown); padding: 6px 12px; border-radius: 6px;
    font-size: .85rem; font-weight: 700; font-family: inherit;
    display: inline-flex; align-items: center; gap: 4px;
  }
  nav.subnav .nav-trigger.active, nav.subnav .nav-trigger:hover,
  nav.subnav .nav-group:hover         > .nav-trigger,
  nav.subnav .nav-group:focus-within  > .nav-trigger { background: var(--cat-bg); }
  nav.subnav .nav-caret { font-size: .7rem; line-height: 1; }
  nav.subnav .nav-dropdown {
    display: none; position: absolute; top: 100%; left: 0; z-index: 100;
    flex-direction: column; min-width: 180px; padding: 6px;
    background: #fff; border: 1px solid var(--border); border-radius: 6px;
    box-shadow: 0 4px 12px rgba(0,0,0,.08); margin-top: 6px;
  }
  /* Invisible "bridge" filling the gap between the trigger and the panel so the
     cursor can travel from one to the other without crossing a non-hover zone
     (which would close the menu). Its negative top overlaps the trigger a touch
     so the hover area is continuous. */
  nav.subnav .nav-dropdown::before {
    content: ''; position: absolute; left: 0; right: 0; top: -8px; height: 8px;
  }
  nav.subnav .nav-group:hover        > .nav-dropdown,
  nav.subnav .nav-group:focus-within > .nav-dropdown { display: flex; }
  nav.subnav .nav-dropdown a {
    padding: 8px 12px; border-radius: 4px; white-space: nowrap;
  }
  nav.subnav .nav-dropdown a:hover,
  nav.subnav .nav-dropdown a.active { background: var(--cat-bg); }

  .container { max-width: 980px; margin: 24px auto 40px; padding: 0 16px; }
  .card { background: #fff; border: 1px solid var(--border); border-radius: 10px;
          box-shadow: 0 2px 10px rgba(0,0,0,.07); padding: 20px; margin-bottom: 20px; }
  .card h2 { font-size: 1rem; color: var(--brown); text-transform: uppercase;
             border-bottom: 2px solid var(--cat-bg); padding-bottom: 8px; margin-bottom: 14px; }

  .btn {
    border: none; border-radius: 7px; padding: 12px 18px;
    font-size: 1rem; font-weight: 700; cursor: pointer; font-family: inherit;
  }
  .btn-primary { background: var(--green); color: #fff; }
  .btn-secondary { background: #fff; color: var(--brown); border: 2px solid var(--border); }
  .btn-danger { background: var(--red); color: #fff; }
  .btn-block { display: block; width: 100%; }
  .btn:disabled { opacity: .5; cursor: not-allowed; }

  input[type=text], input[type=number], input[type=password], input[type=email],
  input[type=search], input[type=date], select, textarea {
    border: 2px solid var(--border); border-radius: 6px; padding: 10px;
    font-size: 1rem; font-family: inherit; background: #fff; width: 100%;
  }
  input:focus, select:focus, textarea:focus { outline: none; border-color: var(--blue); }
  label { font-size: .8rem; font-weight: 700; text-transform: uppercase;
          color: var(--brown); display: block; margin-bottom: 4px; }

  table.data { width: 100%; border-collapse: collapse; }
  table.data th, table.data td { padding: 8px 10px; text-align: left; border-bottom: 1px solid var(--border); font-size: .9rem; }
  table.data th { background: var(--cat-bg); color: var(--brown); text-transform: uppercase; font-size: .75rem; }
  table.data tr:hover td { background: #fafaf5; }
  table.data td.num, table.data th.num { text-align: right; font-variant-numeric: tabular-nums; }

  .banner { padding: 14px 18px; border-radius: 8px; margin-bottom: 16px;
            display: flex; gap: 12px; align-items: center; }
  .banner.success  { background: #D4EDDA; border: 1px solid #A8D8B9; color: #276437; }
  .banner.error    { background: #F8D7DA; border: 1px solid #F1AEB5; color: #8B1A1A; }
  .banner.warn     { background: #FFF3CD; border: 1px solid #FFE69C; color: #806000; }
  .banner.info     { background: #E7F3FF; border: 1px solid #BADCFF; color: var(--blue); }

  .stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; }
  .stat { background: var(--cat-bg); border-radius: 8px; padding: 14px; text-align: center; }
  .stat .v { font-size: 1.6rem; font-weight: 800; color: var(--brown); }
  .stat .k { font-size: .75rem; text-transform: uppercase; color: #777; margin-top: 2px; }

  .row { display: flex; gap: 10px; align-items: end; flex-wrap: wrap; }
  .row > * { flex: 1 1 160px; }
</style>
</head>
<body>
<header class="site-header">
  <?php
    // Logo lives at foodscan/logo.jpg (admin-replaceable via Settings →
    // Food Pantry Information). Cache-bust with the file mtime so a freshly
    // uploaded logo shows immediately instead of a stale cached copy.
    $logoFile = __DIR__ . '/logo.jpg';
    $logoVer  = is_file($logoFile) ? ('?v=' . filemtime($logoFile)) : '';
    $pantryName = setting('food_pantry_name', '') ?? '';
  ?>
  <img src="<?= $p ?>logo.jpg<?= $logoVer ?>" alt="<?= htmlspecialchars($pantryName !== '' ? $pantryName : 'Logo') ?>">
  <div class="header-text">
    <h1><span style="color:#8baf3a;">Open</span>Pantry</h1>
    <p>Inventory tracking management</p>
  </div>
</header>
<?php
}

function renderNav(string $active = ''): void {
    $p = fsPrefix();
    // Dashboard lives at /foodscan/. From the dashboard itself, $p is '' so
    // the link is current-page; from subfolders, $p is '../' so it goes up
    // to /foodscan/ which serves index.php.
    $dash = $p === '' ? '' : $p;

    // Nav structure: each node is either a flat link or a "group" that
    // renders as a click-to-open dropdown trigger. Active highlighting
    // propagates from a child up to its parent trigger (so visiting any
    // Reports page lights up the Reports trigger).
    //
    // Scan (Laser) and Menu Counter (PantryPrep admin) open in a new tab —
    // the rest open in the current tab.
    $newTabKeys = ['scan', 'menu_counter'];
    $nav = [
        ['type' => 'link', 'key' => 'index', 'label' => 'Dashboard', 'href' => $dash],
        ['type' => 'group', 'label' => 'Checkout', 'children' => [
            ['key' => 'scan',         'label' => 'Scan (Laser)',  'href' => $p . 'scan/'],
            ['key' => 'scan_camera',  'label' => 'Scan (Camera)', 'href' => $p . 'scan_camera/'],
            ['key' => 'menu_counter', 'label' => 'Menu Counter',  'href' => $p . 'menucounter/admin/'],
            ['key' => 'delivery',     'label' => 'Deliveries',    'href' => $p . 'delivery/client/'],
            ['key' => 'order_ahead',  'label' => 'OrderAhead',    'href' => $p . 'orderahead/'],
            ['key' => 'event',        'label' => 'Events',        'href' => $p . 'event/'],
        ]],
        ['type' => 'link', 'key' => 'restock',   'label' => 'Restock',       'href' => $p . 'restock/'],
        ['type' => 'link', 'key' => 'inventory', 'label' => 'Inventory',     'href' => $p . 'inventory/'],
        ['type' => 'link', 'key' => 'lookup',    'label' => 'Lookup Tables', 'href' => $p . 'lookup_admin/'],
        ['type' => 'group', 'label' => 'Reports', 'children' => [
            ['key' => 'report',  'label' => 'Order Now',      'href' => $p . 'reports/order_report/'],
            ['key' => 'orders',  'label' => 'Orders Listing', 'href' => $p . 'reports/orders_listing_report/'],
            ['key' => 'usage',   'label' => 'Item Usage',     'href' => $p . 'reports/usage_report/'],
            ['key' => 'volume',  'label' => 'Daily Volume',   'href' => $p . 'reports/volume_report/'],
            ['key' => 'basket',  'label' => 'Basket Size',    'href' => $p . 'reports/basket_report/'],
        ]],
        ['type' => 'link', 'key' => 'settings', 'label' => 'Settings', 'href' => $p . 'settings/'],
    ];

    echo '<nav class="subnav">';
    foreach ($nav as $node) {
        if ($node['type'] === 'link') {
            $cls = ($node['key'] === $active) ? ' class="active"' : '';
            $tgt = in_array($node['key'], $newTabKeys, true) ? ' target="_blank" rel="noopener"' : '';
            echo '<a href="' . htmlspecialchars($node['href']) . '"' . $cls . $tgt . '>'
               . htmlspecialchars($node['label']) . '</a>';
            continue;
        }
        // group: a button-style trigger plus a dropdown panel of child links.
        // The trigger gets the active style when any of its children is active.
        $groupActive = false;
        foreach ($node['children'] as $child) {
            if ($child['key'] === $active) { $groupActive = true; break; }
        }
        $groupCls = $groupActive ? 'nav-group active' : 'nav-group';
        $triggerCls = $groupActive ? 'nav-trigger active' : 'nav-trigger';
        echo '<div class="' . $groupCls . '">';
        echo   '<button type="button" class="' . $triggerCls . '" aria-haspopup="true" tabindex="0">'
             . htmlspecialchars($node['label']) . ' <span class="nav-caret">▾</span>'
             . '</button>';
        echo   '<div class="nav-dropdown" role="menu">';
        foreach ($node['children'] as $child) {
            $childCls = ($child['key'] === $active) ? ' class="active"' : '';
            $childTgt = in_array($child['key'], $newTabKeys, true) ? ' target="_blank" rel="noopener"' : '';
            echo '<a href="' . htmlspecialchars($child['href']) . '"' . $childCls . $childTgt
               . ' role="menuitem">' . htmlspecialchars($child['label']) . '</a>';
        }
        echo   '</div>';
        echo '</div>';
    }
    // Show logout only if the admin cookie is currently valid.
    if (isset($_COOKIE[AUTH_COOKIE]) && $_COOKIE[AUTH_COOKIE] !== '') {
        echo '<a href="' . $p . 'logout/" class="nav-logout">🔒 Log out</a>';
    }
    echo '</nav>';
    // Dropdown behavior: hover only. The CSS opens the panel on
    // `.nav-group:hover` and `:focus-within` (the latter keeps keyboard
    // tabbing usable without JS), so no script is needed.
}

function renderFoot(): void {
    $p = fsPrefix();
    ?>
<footer class="site-footer" style="text-align:center; padding:24px 16px; font-size:.78rem;
        color:#999; border-top:1px solid var(--border); margin-top:40px;">
  &copy; 2026 <strong>Chromagic Development</strong> &mdash; OpenPantry, by
  <strong>Bruce Alexander</strong>.
  Released under the
  <a href="<?= $p ?>LICENSE" style="color:var(--brown); text-decoration:none;">MIT License</a>.
</footer>
</body></html><?php
}

// Opaque per-device station id, used so two scanning stations can each keep
// their own open order at the same time. Stored in the `fs_station` cookie
// (random 128-bit token); generated and set on first use. MUST be called
// before any output is sent — every current caller (the scan pages before
// renderHead, and the JSON APIs before jsonOut) satisfies that.
function currentStationId(): string {
    static $sid = null;
    if ($sid !== null) return $sid;

    $cookie = $_COOKIE['fs_station'] ?? '';
    if (is_string($cookie) && preg_match('/^[a-f0-9]{32}$/', $cookie)) {
        return $sid = $cookie;
    }

    $sid = bin2hex(random_bytes(16));
    // 1-year cookie, path '/' so the scan pages (/scan/, /scan_camera/) and the
    // API endpoints at the app root share the same token. Legacy setcookie
    // signature for PHP 7.2 compatibility (matches the sodium note in db.php).
    @setcookie('fs_station', $sid, time() + 365 * 24 * 3600, '/');
    $_COOKIE['fs_station'] = $sid; // visible to the rest of this same request
    return $sid;
}

// The open order for a given station (defaults to this device's station). Each
// station has at most one open order, so this is the row the scan flow appends
// to and the End/Cancel actions operate on. Pass '' to match legacy orders.
function currentOpenOrder(?string $station = null): ?array {
    if ($station === null) $station = currentStationId();
    $stmt = getDB()->prepare(
        "SELECT * FROM orders WHERE status='open' AND station=? ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$station]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// Every currently-open order, regardless of station — for admin overviews
// (e.g. the dashboard) that want to surface all in-progress orders rather than
// just the viewing device's own.
function allOpenOrders(): array {
    return getDB()
        ->query("SELECT * FROM orders WHERE status='open' ORDER BY id DESC")
        ->fetchAll();
}

function jsonOut($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function jsonIn(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return $_POST;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}
