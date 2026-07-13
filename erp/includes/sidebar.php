<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    $configCandidates = [
        dirname(__DIR__) . '/config/config.php',
        dirname(__DIR__) . '/config.php',
        __DIR__ . '/../config/config.php',
        __DIR__ . '/../config.php',
    ];

    foreach ($configCandidates as $configFile) {
        if (is_file($configFile)) {
            require_once $configFile;
            break;
        }
    }
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Sidebar database connection is not available.');
}

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if (!function_exists('sidebar_e')) {
    function sidebar_e($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('sidebar_initials')) {
    function sidebar_initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $initials = '';
        foreach (array_slice($parts, 0, 2) as $part) {
            $initials .= strtoupper(substr($part, 0, 1));
        }
        return $initials !== '' ? $initials : 'JE';
    }
}

$currentFile = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: 'index.php');
$currentFile = $currentFile === '' ? 'index.php' : $currentFile;

$userType  = (string)($_SESSION['user_type'] ?? 'Business User');
$businessId = (int)($_SESSION['business_id'] ?? 0);
$roleId     = (int)($_SESSION['role_id'] ?? 0);
$isPlatformAdmin = $userType === 'Platform Admin';

$businessName = (string)($_SESSION['business_name'] ?? 'Jewellery ERP');
$branchName   = (string)($_SESSION['branch_name'] ?? '');
$themeLogo    = '';
$primaryColor = '#d89416';
$primaryDark  = '#b86a0b';
$sidebar1     = '#171c21';
$sidebar2     = '#20272d';
$sidebar3     = '#101419';
$radius       = 12;

if ($businessId > 0) {
    $themeStmt = $conn->prepare(
        'SELECT b.business_name, t.logo_path, t.primary_color, t.primary_dark_color,\n'
        . 't.sidebar_gradient_1, t.sidebar_gradient_2, t.sidebar_gradient_3, t.border_radius_px\n'
        . 'FROM businesses b\n'
        . 'LEFT JOIN business_theme_settings t ON t.business_id = b.id\n'
        . 'WHERE b.id = ? LIMIT 1'
    );

    if ($themeStmt) {
        $themeStmt->bind_param('i', $businessId);
        $themeStmt->execute();
        $themeResult = $themeStmt->get_result();
        $themeRow = $themeResult ? $themeResult->fetch_assoc() : null;
        $themeStmt->close();

        if ($themeRow) {
            $businessName = (string)($themeRow['business_name'] ?: $businessName);
            $themeLogo = (string)($themeRow['logo_path'] ?? '');
            $primaryColor = (string)($themeRow['primary_color'] ?: $primaryColor);
            $primaryDark = (string)($themeRow['primary_dark_color'] ?: $primaryDark);
            $sidebar1 = (string)($themeRow['sidebar_gradient_1'] ?: $sidebar1);
            $sidebar2 = (string)($themeRow['sidebar_gradient_2'] ?: $sidebar2);
            $sidebar3 = (string)($themeRow['sidebar_gradient_3'] ?: $sidebar3);
            $radius = (int)($themeRow['border_radius_px'] ?: $radius);
        }
    }
}

$menuRows = [];

if ($isPlatformAdmin) {
    $menuSql = "SELECT mi.*, 1 AS can_open
                FROM menu_items mi
                WHERE mi.is_active = 1
                  AND mi.is_visible = 1
                  AND (mi.business_id IS NULL OR mi.business_id = ?)
                ORDER BY mi.sort_order ASC, mi.id ASC";
    $menuStmt = $conn->prepare($menuSql);
    if ($menuStmt) {
        $menuStmt->bind_param('i', $businessId);
        $menuStmt->execute();
        $menuResult = $menuStmt->get_result();
        while ($row = $menuResult->fetch_assoc()) {
            $menuRows[] = $row;
        }
        $menuStmt->close();
    }
} else {
    $menuSql = "SELECT DISTINCT
                    mi.*,
                    rp.can_open
                FROM menu_items mi
                INNER JOIN permissions p
                    ON p.menu_item_id = mi.id
                   AND p.is_active = 1
                   AND (p.business_id IS NULL OR p.business_id = ?)
                INNER JOIN role_permissions rp
                    ON rp.permission_id = p.id
                   AND rp.business_id = ?
                   AND rp.role_id = ?
                WHERE mi.is_active = 1
                  AND mi.is_visible = 1
                  AND rp.can_open = 1
                  AND (mi.business_id IS NULL OR mi.business_id = ?)
                ORDER BY mi.sort_order ASC, mi.id ASC";

    $menuStmt = $conn->prepare($menuSql);
    if ($menuStmt) {
        $menuStmt->bind_param('iiii', $businessId, $businessId, $roleId, $businessId);
        $menuStmt->execute();
        $menuResult = $menuStmt->get_result();
        while ($row = $menuResult->fetch_assoc()) {
            $menuRows[] = $row;
        }
        $menuStmt->close();
    }
}

$menuById = [];
$childrenByParent = [];

foreach ($menuRows as $row) {
    $id = (int)$row['id'];
    $parentId = $row['parent_id'] !== null ? (int)$row['parent_id'] : 0;
    $menuById[$id] = $row;
    $childrenByParent[$parentId][] = $row;
}

// Keep a parent visible when an allowed child exists, even if the parent itself has no direct permission row.
foreach ($menuRows as $row) {
    $parentId = $row['parent_id'] !== null ? (int)$row['parent_id'] : 0;
    if ($parentId > 0 && !isset($menuById[$parentId])) {
        $parentStmt = $conn->prepare(
            'SELECT * FROM menu_items WHERE id = ? AND is_active = 1 AND is_visible = 1 LIMIT 1'
        );
        if ($parentStmt) {
            $parentStmt->bind_param('i', $parentId);
            $parentStmt->execute();
            $parentResult = $parentStmt->get_result();
            $parent = $parentResult ? $parentResult->fetch_assoc() : null;
            $parentStmt->close();
            if ($parent) {
                $menuById[$parentId] = $parent;
                $childrenByParent[0][] = $parent;
            }
        }
    }
}

// Sort every menu level independently by the assigned sort order.
foreach ($childrenByParent as $parentKey => &$childRows) {
    usort($childRows, static function (array $a, array $b): int {
        $orderCompare = (int)($a['sort_order'] ?? 0) <=> (int)($b['sort_order'] ?? 0);
        return $orderCompare !== 0 ? $orderCompare : ((int)$a['id'] <=> (int)$b['id']);
    });
}
unset($childRows);

$rootMenus = $childrenByParent[0] ?? [];
$businessShort = sidebar_initials($businessName);
?>

<style>
    .app-sidebar {
        --sidebar-primary: <?php echo sidebar_e($primaryColor); ?>;
        --sidebar-primary-dark: <?php echo sidebar_e($primaryDark); ?>;
        --sidebar-radius: <?php echo max(0, $radius); ?>px;
        height: 100vh;
        max-height: 100vh;
        overflow: hidden !important;
        display: flex;
        flex-direction: column;
        background: linear-gradient(180deg,
            <?php echo sidebar_e($sidebar1); ?> 0%,
            <?php echo sidebar_e($sidebar2); ?> 52%,
            <?php echo sidebar_e($sidebar3); ?> 100%) !important;
    }

    .app-sidebar .brand-box {
        min-height: 76px;
        flex: 0 0 auto;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 12px 16px;
        border-bottom: 1px solid rgba(255,255,255,.08);
    }

    .app-sidebar .brand-logo-img {
        max-width: 150px;
        max-height: 52px;
        object-fit: contain;
    }

    .app-sidebar .brand-title {
        color: #efc35a;
        font-weight: 800;
        letter-spacing: .16em;
        font-size: 20px;
        line-height: 1;
        text-align: center;
    }

    .app-sidebar .brand-subtitle {
        color: #fff2bf;
        font-size: 8px;
        font-weight: 700;
        letter-spacing: .34em;
        margin-top: 6px;
        text-align: center;
    }

    .app-sidebar .brand-short {
        width: 40px;
        height: 40px;
        display: none;
        place-items: center;
        border-radius: calc(var(--sidebar-radius) * .75);
        background: linear-gradient(135deg, var(--sidebar-primary), var(--sidebar-primary-dark));
        color: #fff;
        font-weight: 800;
    }

    .app-sidebar .sidebar-business-context {
        margin: 10px 12px 2px;
        padding: 10px 12px;
        border: 1px solid rgba(255,255,255,.08);
        border-radius: calc(var(--sidebar-radius) * .75);
        background: rgba(255,255,255,.04);
        color: rgba(255,255,255,.78);
        font-size: 10px;
        line-height: 1.45;
    }

    .app-sidebar .sidebar-business-context strong {
        display: block;
        color: #fff;
        font-size: 11px;
    }

    .app-sidebar .sidebar-nav {
        flex: 1 1 auto;
        min-height: 0;
        max-height: none !important;
        padding: 10px 8px 18px;
        overflow-y: auto !important;
        overflow-x: hidden !important;
        overscroll-behavior: contain;
        scrollbar-width: thin;
        scrollbar-color: rgba(255,255,255,.24) transparent;
    }

    .app-sidebar .sidebar-nav::-webkit-scrollbar {
        width: 5px;
    }

    .app-sidebar .sidebar-nav::-webkit-scrollbar-track {
        background: transparent;
    }

    .app-sidebar .sidebar-nav::-webkit-scrollbar-thumb {
        background: rgba(255,255,255,.24);
        border-radius: 10px;
    }

    .app-sidebar .sidebar-nav::-webkit-scrollbar-thumb:hover {
        background: rgba(255,255,255,.36);
    }

    .app-sidebar .nav-link,
    .app-sidebar .submenu-toggle {
        width: 100%;
        display: flex;
        align-items: center;
        gap: 10px;
        min-height: 42px;
        padding: 9px 10px;
        margin-bottom: 3px;
        border: 0;
        border-radius: calc(var(--sidebar-radius) * .72);
        background: transparent;
        color: rgba(255,255,255,.82);
        text-decoration: none;
        font-size: 13px;
        font-weight: 600;
        text-align: left;
        transition: background .2s ease, color .2s ease, transform .2s ease;
    }

    .app-sidebar .nav-link:hover,
    .app-sidebar .submenu-toggle:hover {
        color: #fff;
        background: rgba(255,255,255,.07);
    }

    .app-sidebar .nav-link.active,
    .app-sidebar .submenu-toggle.active {
        color: #fff;
        background: linear-gradient(135deg, var(--sidebar-primary), var(--sidebar-primary-dark));
        box-shadow: 0 12px 28px color-mix(in srgb, var(--sidebar-primary) 24%, transparent);
    }

    .app-sidebar .nav-link i,
    .app-sidebar .submenu-toggle i:first-child {
        width: 18px;
        min-width: 18px;
        text-align: center;
    }


    .app-sidebar .sidebar-label {
        min-width: 0;
        flex: 1 1 auto;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .app-sidebar .sidebar-submenu .sidebar-label {
        font-size: 11px;
    }

    .app-sidebar .submenu-toggle .submenu-arrow {
        margin-left: auto;
        font-size: 10px;
        transition: transform .2s ease;
    }

    .app-sidebar .submenu-toggle[aria-expanded="true"] .submenu-arrow {
        transform: rotate(180deg);
    }

    .app-sidebar .sidebar-submenu {
        margin: 2px 0 6px 18px;
        padding-left: 12px;
        border-left: 1px solid rgba(255,255,255,.10);
    }

    .app-sidebar .sidebar-submenu .nav-link {
        min-height: 36px;
        padding: 7px 8px;
        font-size: 11px;
        color: rgba(255,255,255,.68);
    }

    .app-sidebar .sidebar-submenu .nav-link.active {
        color: #fff;
        background: rgba(255,255,255,.11);
        box-shadow: none;
    }

    .app-sidebar .sidebar-divider {
        height: 1px;
        margin: 10px 8px;
        background: rgba(255,255,255,.08);
    }

    .app-sidebar .sidebar-group-label {
        padding: 10px 12px 5px;
        color: rgba(255,255,255,.38);
        font-size: 9px;
        font-weight: 800;
        letter-spacing: .12em;
        text-transform: uppercase;
    }

    body.sidebar-collapsed .app-sidebar .brand-full,
    body.sidebar-collapsed .app-sidebar .sidebar-label,
    body.sidebar-collapsed .app-sidebar .submenu-arrow,
    body.sidebar-collapsed .app-sidebar .sidebar-business-context {
        display: none !important;
    }

    body.sidebar-collapsed .app-sidebar .brand-short {
        display: grid;
    }

    body.sidebar-collapsed .app-sidebar .nav-link,
    body.sidebar-collapsed .app-sidebar .submenu-toggle {
        justify-content: center;
        padding-left: 10px;
        padding-right: 10px;
    }

    body.sidebar-collapsed .app-sidebar .sidebar-submenu {
        margin-left: 0;
        padding-left: 0;
        border-left: 0;
    }

    /* Submenu visibility is controlled by script.js. */
    .app-sidebar .sidebar-submenu {
        display: none !important;
        margin: 0 0 0 18px;
        padding-left: 12px;
        border-left: 1px solid rgba(255,255,255,.10);
        overflow: hidden !important;
    }

    .app-sidebar .sidebar-submenu.is-open {
        display: block !important;
        margin-bottom: 6px;
    }

    .app-sidebar .sidebar-submenu.submenu-animating {
        display: block !important;
    }

    .app-sidebar .submenu-toggle {
        cursor: pointer;
        -webkit-tap-highlight-color: transparent;
    }

    .app-sidebar .submenu-toggle:focus {
        outline: none;
        box-shadow: 0 0 0 2px color-mix(in srgb, var(--sidebar-primary) 35%, transparent);
    }

    .sidebar-hover-card {
        position: fixed;
        z-index: 30000;
        display: none;
        max-width: 260px;
        padding: 8px 11px;
        border: 1px solid rgba(255,255,255,.12);
        border-radius: 8px;
        background: #20272d;
        color: #fff;
        font-size: 11px;
        font-weight: 600;
        line-height: 1.35;
        white-space: normal;
        overflow-wrap: anywhere;
        box-shadow: 0 10px 28px rgba(0,0,0,.28);
        pointer-events: none;
    }
    .sidebar-hover-card.show { display: block; }

    @media (prefers-reduced-motion: reduce) {
        .app-sidebar .sidebar-submenu,
    
    .app-sidebar .sidebar-label {
        min-width: 0;
        flex: 1 1 auto;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .app-sidebar .sidebar-submenu .sidebar-label {
        font-size: 11px;
    }

    .app-sidebar .submenu-toggle .submenu-arrow {
            transition: none !important;
        }
    }

</style>

<aside class="app-sidebar" id="sidebar">
    <div class="brand-box">
        <div class="brand-full">
            <?php if ($themeLogo !== ''): ?>
                <img class="brand-logo-img" src="<?php echo sidebar_e($themeLogo); ?>" alt="<?php echo sidebar_e($businessName); ?>">
            <?php else: ?>
                <div class="brand-title"><?php echo sidebar_e(strtoupper($businessName)); ?></div>
                <?php if ($branchName !== ''): ?>
                    <div class="brand-subtitle"><?php echo sidebar_e(strtoupper($branchName)); ?></div>
                <?php else: ?>
                    <div class="brand-subtitle">JEWELLERS</div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <div class="brand-short"><?php echo sidebar_e(substr($businessShort, 0, 1)); ?></div>
    </div>

   
    <nav class="sidebar-nav nav flex-column" aria-label="Main navigation">
        <?php foreach ($rootMenus as $menu): ?>
            <?php
            $menuId = (int)$menu['id'];
            $menuType = (string)($menu['menu_type'] ?? 'Menu');
            $menuTitle = (string)($menu['menu_title'] ?? 'Menu');
            $routeUrl = trim((string)($menu['route_url'] ?? ''));
            $iconClass = trim((string)($menu['icon_class'] ?? 'fa-regular fa-circle'));
            $children = $childrenByParent[$menuId] ?? [];

            /*
             * Add rate-management pages inside the Products module.
             * The sidebar is database-driven, so these virtual children are added only
             * when the current root menu is the Products module. Existing database menu
             * rows with the same route are respected and will not be duplicated.
             */
            $menuCode = strtolower(trim((string)($menu['menu_code'] ?? '')));
            $menuTitleKey = strtolower(trim($menuTitle));
            $menuRouteFile = strtolower(basename(parse_url($routeUrl, PHP_URL_PATH) ?: ''));
            $isProductsModule = (
                $menuCode === 'products' ||
                $menuCode === 'product' ||
                strpos($menuCode, 'products') !== false ||
                $menuTitleKey === 'products' ||
                $menuTitleKey === 'product management' ||
                strpos($menuTitleKey, 'product') !== false ||
                in_array($menuRouteFile, ['products.php', 'manage-product.php', 'product1.php'], true)
            );


            $isPawnModule = (
                $menuCode === 'pawn' ||
                $menuCode === 'pawn.broking' ||
                strpos($menuCode, 'pawn') !== false ||
                $menuTitleKey === 'pawn broking' ||
                $menuTitleKey === 'pawn' ||
                strpos($menuTitleKey, 'pawn') !== false ||
                in_array($menuRouteFile, ['pawn-list.php', 'pawn-entry.php', 'pawn-customers.php'], true)
            );

            if ($isProductsModule) {
                $existingChildRoutes = [];
                foreach ($children as $existingChild) {
                    $existingRoute = strtolower(basename(parse_url((string)($existingChild['route_url'] ?? ''), PHP_URL_PATH) ?: ''));
                    if ($existingRoute !== '') {
                        $existingChildRoutes[$existingRoute] = true;
                    }
                }

                $virtualProductChildren = [
                    [
                        'id' => -900001,
                        'parent_id' => $menuId,
                        'menu_type' => 'Menu',
                        'menu_title' => 'Silver Rates',
                        'route_url' => 'silver-rates.php',
                        'icon_class' => 'fa-solid fa-coins',
                        'sort_order' => 9001,
                        'open_in_new_tab' => 0,
                        'is_active' => 1,
                        'is_visible' => 1,
                    ],
                    [
                        'id' => -900002,
                        'parent_id' => $menuId,
                        'menu_type' => 'Menu',
                        'menu_title' => 'Metal Rates',
                        'route_url' => 'metal-rates.php',
                        'icon_class' => 'fa-solid fa-scale-balanced',
                        'sort_order' => 9002,
                        'open_in_new_tab' => 0,
                        'is_active' => 1,
                        'is_visible' => 1,
                    ],
                ];

                foreach ($virtualProductChildren as $virtualChild) {
                    $virtualRoute = strtolower(basename((string)$virtualChild['route_url']));
                    if (!isset($existingChildRoutes[$virtualRoute])) {
                        $children[] = $virtualChild;
                        $existingChildRoutes[$virtualRoute] = true;
                    }
                }
            }


            if ($isPawnModule) {
                $existingChildRoutes = [];
                foreach ($children as $existingChild) {
                    $existingRoute = strtolower(basename(parse_url((string)($existingChild['route_url'] ?? ''), PHP_URL_PATH) ?: ''));
                    if ($existingRoute !== '') {
                        $existingChildRoutes[$existingRoute] = true;
                    }
                }

                $virtualPawnChildren = [
                    [
                        'id' => -910001,
                        'parent_id' => $menuId,
                        'menu_type' => 'Menu',
                        'menu_title' => 'Pawn Entries',
                        'route_url' => 'pawn-list.php',
                        'icon_class' => 'fa-solid fa-list',
                        'sort_order' => 1,
                        'open_in_new_tab' => 0,
                        'is_active' => 1,
                        'is_visible' => 1,
                    ],
                    [
                        'id' => -910002,
                        'parent_id' => $menuId,
                        'menu_type' => 'Menu',
                        'menu_title' => 'New Pawn Entry',
                        'route_url' => 'pawn-entry.php',
                        'icon_class' => 'fa-solid fa-plus-circle',
                        'sort_order' => 2,
                        'open_in_new_tab' => 0,
                        'is_active' => 1,
                        'is_visible' => 1,
                    ],
                    [
                        'id' => -910003,
                        'parent_id' => $menuId,
                        'menu_type' => 'Menu',
                        'menu_title' => 'Pawn Customers',
                        'route_url' => 'pawn-customers.php',
                        'icon_class' => 'fa-solid fa-users',
                        'sort_order' => 3,
                        'open_in_new_tab' => 0,
                        'is_active' => 1,
                        'is_visible' => 1,
                    ],
                    [
                        'id' => -910004,
                        'parent_id' => $menuId,
                        'menu_type' => 'Menu',
                        'menu_title' => 'Add Pawn Customer',
                        'route_url' => 'pawn-customer-add.php',
                        'icon_class' => 'fa-solid fa-user-plus',
                        'sort_order' => 4,
                        'open_in_new_tab' => 0,
                        'is_active' => 1,
                        'is_visible' => 1,
                    ],
                    [
                        'id' => -910005,
                        'parent_id' => $menuId,
                        'menu_type' => 'Menu',
                        'menu_title' => 'Pawn Categories',
                        'route_url' => 'pawn-categories.php',
                        'icon_class' => 'fa-solid fa-tags',
                        'sort_order' => 5,
                        'open_in_new_tab' => 0,
                        'is_active' => 1,
                        'is_visible' => 1,
                    ],
                    [
                        'id' => -910006,
                        'parent_id' => $menuId,
                        'menu_type' => 'Menu',
                        'menu_title' => 'Add Pawn Category',
                        'route_url' => 'pawn-category-add.php',
                        'icon_class' => 'fa-solid fa-tag',
                        'sort_order' => 6,
                        'open_in_new_tab' => 0,
                        'is_active' => 1,
                        'is_visible' => 1,
                    ],
                    [
                        'id' => -910007,
                        'parent_id' => $menuId,
                        'menu_type' => 'Menu',
                        'menu_title' => 'Pawn Payments',
                        'route_url' => 'pawn-payment.php',
                        'icon_class' => 'fa-solid fa-money-bill-transfer',
                        'sort_order' => 7,
                        'open_in_new_tab' => 0,
                        'is_active' => 1,
                        'is_visible' => 1,
                    ],
                    [
                        'id' => -910008,
                        'parent_id' => $menuId,
                        'menu_type' => 'Menu',
                        'menu_title' => 'Interest Collection',
                        'route_url' => 'pawn-interest.php',
                        'icon_class' => 'fa-solid fa-percent',
                        'sort_order' => 8,
                        'open_in_new_tab' => 0,
                        'is_active' => 1,
                        'is_visible' => 1,
                    ],
                    [
                        'id' => -910009,
                        'parent_id' => $menuId,
                        'menu_type' => 'Menu',
                        'menu_title' => 'Pawn Release',
                        'route_url' => 'pawn-release.php',
                        'icon_class' => 'fa-solid fa-box-open',
                        'sort_order' => 9,
                        'open_in_new_tab' => 0,
                        'is_active' => 1,
                        'is_visible' => 1,
                    ],
                ];

                foreach ($virtualPawnChildren as $virtualChild) {
                    $virtualRoute = strtolower(basename((string)$virtualChild['route_url']));
                    if (!isset($existingChildRoutes[$virtualRoute])) {
                        $children[] = $virtualChild;
                        $existingChildRoutes[$virtualRoute] = true;
                    }
                }
            }

            usort($children, static function (array $a, array $b): int {
                return [(int)$a['sort_order'], (int)$a['id']] <=> [(int)$b['sort_order'], (int)$b['id']];
            });

            $directActive = $routeUrl !== '' && basename(parse_url($routeUrl, PHP_URL_PATH) ?: '') === $currentFile;
            $childActive = false;
            foreach ($children as $child) {
                $childRoute = trim((string)($child['route_url'] ?? ''));
                if ($childRoute !== '' && basename(parse_url($childRoute, PHP_URL_PATH) ?: '') === $currentFile) {
                    $childActive = true;
                    break;
                }
            }
            $isActive = $directActive || $childActive;
            ?>

            <?php if ($menuType === 'Divider'): ?>
                <div class="sidebar-divider"></div>
                <?php continue; ?>
            <?php endif; ?>

            <?php if ($menuType === 'Group' && !$children): ?>
                <div class="sidebar-group-label"><?php echo sidebar_e($menuTitle); ?></div>
                <?php continue; ?>
            <?php endif; ?>

            <?php if ($children): ?>
                <?php $collapseId = 'sidebarMenu' . $menuId; ?>
                <button
                    class="submenu-toggle <?php echo $isActive ? 'active' : ''; ?>"
                    type="button"
                    data-submenu-target="<?php echo sidebar_e($collapseId); ?>"
                    aria-expanded="<?php echo $isActive ? 'true' : 'false'; ?>"
                    aria-controls="<?php echo sidebar_e($collapseId); ?>"
                    data-sidebar-tooltip="<?php echo sidebar_e($menuTitle); ?>">
                    <i class="<?php echo sidebar_e($iconClass); ?>"></i>
                    <span class="sidebar-label"><?php echo sidebar_e($menuTitle); ?></span>
                    <i class="fa-solid fa-chevron-down submenu-arrow"></i>
                </button>

                <div class="sidebar-submenu <?php echo $isActive ? 'is-open' : ''; ?>" id="<?php echo sidebar_e($collapseId); ?>">
                    <div class="submenu-inner">
                    <?php foreach ($children as $child): ?>
                        <?php
                        $childRoute = trim((string)($child['route_url'] ?? '#'));
                        $childCurrent = $childRoute !== '' && basename(parse_url($childRoute, PHP_URL_PATH) ?: '') === $currentFile;
                        $childIcon = trim((string)($child['icon_class'] ?? 'fa-regular fa-circle'));
                        ?>
                        <a class="nav-link <?php echo $childCurrent ? 'active' : ''; ?>"
                           href="<?php echo sidebar_e($childRoute !== '' ? $childRoute : '#'); ?>"
                           data-sidebar-tooltip="<?php echo sidebar_e($child['menu_title'] ?? 'Menu'); ?>"
                           <?php echo !empty($child['open_in_new_tab']) ? 'target="_blank" rel="noopener"' : ''; ?>>
                            <i class="<?php echo sidebar_e($childIcon); ?>"></i>
                            <span class="sidebar-label"><?php echo sidebar_e($child['menu_title'] ?? 'Menu'); ?></span>
                        </a>
                    <?php endforeach; ?>
                    </div>
                </div>
            <?php else: ?>
                <a class="nav-link <?php echo $directActive ? 'active' : ''; ?>"
                   href="<?php echo sidebar_e($routeUrl !== '' ? $routeUrl : '#'); ?>"
                   data-sidebar-tooltip="<?php echo sidebar_e($menuTitle); ?>"
                   <?php echo !empty($menu['open_in_new_tab']) ? 'target="_blank" rel="noopener"' : ''; ?>>
                    <i class="<?php echo sidebar_e($iconClass); ?>"></i>
                    <span class="sidebar-label"><?php echo sidebar_e($menuTitle); ?></span>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>

        <?php if (!$rootMenus): ?>
            <div class="sidebar-business-context">No menu permissions are assigned to this role.</div>
        <?php endif; ?>
    </nav>
</aside>
<div class="sidebar-hover-card" id="sidebarHoverCard" role="tooltip"></div>
<script>
(function () {
    'use strict';
    const card = document.getElementById('sidebarHoverCard');
    if (!card) return;
    let activeTarget = null;
    function showCard(target) {
        const text = (target.getAttribute('data-sidebar-tooltip') || '').trim();
        const label = target.querySelector('.sidebar-label');
        if (!text || !label) return;
        const isTruncated = label.scrollWidth > label.clientWidth + 1;
        const isCollapsed = document.body.classList.contains('sidebar-collapsed');
        if (!isTruncated && !isCollapsed) return;
        activeTarget = target;
        card.textContent = text;
        card.classList.add('show');
        const rect = target.getBoundingClientRect();
        const cardRect = card.getBoundingClientRect();
        let top = rect.top + (rect.height - cardRect.height) / 2;
        top = Math.max(8, Math.min(top, window.innerHeight - cardRect.height - 8));
        let left = rect.right + 8;
        if (left + cardRect.width > window.innerWidth - 8) left = Math.max(8, rect.left - cardRect.width - 8);
        card.style.top = top + 'px';
        card.style.left = left + 'px';
    }
    function hideCard() { activeTarget = null; card.classList.remove('show'); }
    document.querySelectorAll('.app-sidebar [data-sidebar-tooltip]').forEach(function (target) {
        target.addEventListener('mouseenter', function () { showCard(target); });
        target.addEventListener('mouseleave', hideCard);
        target.addEventListener('focus', function () { showCard(target); });
        target.addEventListener('blur', hideCard);
    });
    window.addEventListener('scroll', function () { if (activeTarget) showCard(activeTarget); }, true);
    window.addEventListener('resize', hideCard);
})();
</script>