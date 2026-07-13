<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));

foreach ([
    __DIR__ . '/config/config.php',
    __DIR__ . '/config.php',
    __DIR__ . '/includes/config.php',
    __DIR__ . '/super-admin/includes/config.php',
] as $configFile) {
    if (is_file($configFile)) {
        require_once $configFile;
        break;
    }
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database configuration is not available.');
}
$conn->set_charset('utf8mb4');

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('productPermission')) {
    function productPermission(mysqli $conn, string $action): bool
    {
        if (($_SESSION['user_type'] ?? '') === 'Platform Admin') {
            return true;
        }

        $fieldMap = [
            'open' => 'can_open',
            'view' => 'can_view',
            'value' => 'can_view_value',
            'create' => 'can_create',
            'update' => 'can_update',
            'approve' => 'can_approve',
            'delete' => 'can_delete',
        ];

        $field = $fieldMap[$action] ?? '';
        if ($field === '') {
            return false;
        }

        $permissions = $_SESSION['permissions'] ?? [];
        foreach (['perm.products.list', 'perm.products'] as $permissionCode) {
            if (isset($permissions[$permissionCode][$field])) {
                return (int)$permissions[$permissionCode][$field] === 1;
            }
        }

        $businessId = (int)($_SESSION['business_id'] ?? 0);
        $roleId = (int)($_SESSION['role_id'] ?? 0);
        if ($businessId <= 0 || $roleId <= 0) {
            return false;
        }

        $sql = "SELECT rp.`{$field}`
                FROM role_permissions rp
                INNER JOIN permissions p ON p.id = rp.permission_id
                WHERE rp.business_id = ?
                  AND rp.role_id = ?
                  AND p.is_active = 1
                  AND p.permission_code IN ('perm.products.list', 'perm.products')
                ORDER BY FIELD(p.permission_code, 'perm.products.list', 'perm.products')
                LIMIT 1";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ii', $businessId, $roleId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int)($row[$field] ?? 0) === 1;
    }
}

if (!productPermission($conn, 'open')) {
    http_response_code(403);
    die('Access denied. You do not have permission to open products.');
}

$canView = productPermission($conn, 'view') || productPermission($conn, 'open');
$canValue = productPermission($conn, 'value');
$canCreate = productPermission($conn, 'create');
$canUpdate = productPermission($conn, 'update');
$canDelete = productPermission($conn, 'delete');

$businessId = (int)($_SESSION['business_id'] ?? 0);
if ($businessId <= 0) {
    http_response_code(403);
    die('A valid business must be selected.');
}

if (empty($_SESSION['products_csrf'])) {
    $_SESSION['products_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['products_csrf'];

$theme = [
    'primary_color' => '#d89416',
    'primary_dark_color' => '#b86a0b',
    'primary_soft_color' => '#fff6e5',
    'sidebar_gradient_1' => '#171c21',
    'sidebar_gradient_2' => '#20272d',
    'sidebar_gradient_3' => '#101419',
    'page_background' => '#f4f3f0',
    'card_background' => '#ffffff',
    'text_color' => '#171717',
    'muted_text_color' => '#7d8794',
    'border_color' => '#e8e8e8',
    'font_family' => 'Inter',
    'heading_font_family' => 'Playfair Display',
    'border_radius_px' => 12,
    'sidebar_width_px' => 230,
];

$themeStmt = $conn->prepare('SELECT * FROM business_theme_settings WHERE business_id = ? LIMIT 1');
if ($themeStmt) {
    $themeStmt->bind_param('i', $businessId);
    $themeStmt->execute();
    $themeRow = $themeStmt->get_result()->fetch_assoc() ?: [];
    $themeStmt->close();
    foreach ($theme as $key => $defaultValue) {
        if (isset($themeRow[$key]) && $themeRow[$key] !== '') {
            $theme[$key] = $themeRow[$key];
        }
    }
}

$categories = [];
$categoryStmt = $conn->prepare('SELECT id, category_name FROM product_categories WHERE business_id = ? AND is_active = 1 ORDER BY category_name ASC');
if ($categoryStmt) {
    $categoryStmt->bind_param('i', $businessId);
    $categoryStmt->execute();
    $result = $categoryStmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $categories[] = $row;
    }
    $categoryStmt->close();
}

$initialSearch = trim((string)($_GET['search'] ?? ''));
$initialCategory = max(0, (int)($_GET['category_id'] ?? 0));
$initialStatus = in_array((string)($_GET['status'] ?? ''), ['active', 'inactive'], true)
    ? (string)$_GET['status']
    : '';
$allowedPerPage = [10, 25, 50, 100];
$initialPerPage = (int)($_GET['per_page'] ?? 10);
if (!in_array($initialPerPage, $allowedPerPage, true)) {
    $initialPerPage = 10;
}
$initialPage = max(1, (int)($_GET['page'] ?? 1));

$pageTitle = 'Products';
$businessName = (string)($_SESSION['business_name'] ?? 'Jewellery ERP');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo e($businessName); ?> - Products</title>
    <?php include('includes/links.php'); ?>
    <style>
        :root {
            --primary: <?php echo e($theme['primary_color']); ?>;
            --primary-dark: <?php echo e($theme['primary_dark_color']); ?>;
            --primary-soft: <?php echo e($theme['primary_soft_color']); ?>;
            --sidebar-gradient-1: <?php echo e($theme['sidebar_gradient_1']); ?>;
            --sidebar-gradient-2: <?php echo e($theme['sidebar_gradient_2']); ?>;
            --sidebar-gradient-3: <?php echo e($theme['sidebar_gradient_3']); ?>;
            --page-bg: <?php echo e($theme['page_background']); ?>;
            --card-bg: <?php echo e($theme['card_background']); ?>;
            --text-color: <?php echo e($theme['text_color']); ?>;
            --muted-color: <?php echo e($theme['muted_text_color']); ?>;
            --border-color: <?php echo e($theme['border_color']); ?>;
            --sidebar-width: <?php echo (int)$theme['sidebar_width_px']; ?>px;
            --radius: <?php echo (int)$theme['border_radius_px']; ?>px;
        }

        body {
            background: var(--page-bg);
            color: var(--text-color);
            font-family: <?php echo json_encode((string)$theme['font_family']); ?>, sans-serif;
        }

        .sidebar {
            background: linear-gradient(180deg, var(--sidebar-gradient-1), var(--sidebar-gradient-2), var(--sidebar-gradient-3)) !important;
        }

        .stat-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 10px;
            margin-bottom: 12px;
        }

        .stat-card,
        .products-toolbar,
        .products-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
        }

        .stat-card {
            min-height: 84px;
            padding: 13px 15px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .stat-icon {
            width: 44px;
            height: 44px;
            flex: 0 0 44px;
            display: grid;
            place-items: center;
            border-radius: calc(var(--radius) * .8);
            background: var(--primary-soft);
            color: var(--primary-dark);
        }

        .stat-label {
            font-size: 10px;
            color: var(--muted-color);
        }

        .stat-value {
            margin-top: 2px;
            font-size: 23px;
            line-height: 1.1;
            font-weight: 800;
        }

        .products-toolbar {
            padding: 12px;
            margin-bottom: 12px;
        }

        .products-filter-grid {
            display: grid !important;
            grid-template-columns: minmax(220px, 1.8fr) minmax(150px, .95fr) minmax(130px, .78fr) 105px auto auto;
            gap: 8px;
            align-items: center;
            width: 100%;
            margin: 0;
        }

        .products-filter-grid > * {
            min-width: 0;
            margin: 0;
            align-self: center;
        }

        .products-filter-grid .filter-field,
        .products-filter-grid .form-control,
        .products-filter-grid .form-select {
            width: 100%;
        }

        .products-filter-grid .add-product-wrap {
            display: flex;
            justify-content: flex-end;
        }

        .products-filter-grid .add-product-wrap .btn-theme {
            width: auto;
            min-width: 130px;
            border: 0 !important;
            border-bottom: 0 !important;
            outline: 0;
            box-shadow: none;
            text-decoration: none !important;
            background-image: linear-gradient(135deg, var(--primary), var(--primary-dark));
            appearance: none;
            -webkit-appearance: none;
        }

        .products-filter-grid .add-product-wrap .btn-theme::before,
        .products-filter-grid .add-product-wrap .btn-theme::after {
            content: none !important;
            display: none !important;
            border: 0 !important;
        }

        .filter-field {
            position: relative;
            min-width: 0;
        }

        .filter-field > i {
            position: absolute;
            z-index: 3;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--muted-color);
            font-size: 11px;
            pointer-events: none;
        }

        .filter-field.has-icon .form-control,
        .filter-field.has-icon .form-select {
            padding-left: 34px;
        }

        .form-control,
        .form-select {
            min-height: 39px;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            background: var(--card-bg);
            color: var(--text-color);
            font-size: 11px;
            box-shadow: none;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 .2rem color-mix(in srgb, var(--primary) 12%, transparent);
        }

        .btn-theme,
        .btn-filter-reset {
            min-height: 39px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            padding: 9px 14px;
        }

        .btn-theme {
            border: 0;
            color: #fff;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            text-decoration: none !important;
        }

        .btn-theme:hover,
        .btn-theme:focus,
        .btn-theme:active,
        .btn-theme:visited {
            color: #fff;
            text-decoration: none !important;
        }

        .btn-theme:hover {
            filter: brightness(1.03);
        }

        .products-toolbar .btn-theme,
        .products-toolbar .btn-filter-reset,
        .products-toolbar .form-control,
        .products-toolbar .form-select {
            height: 39px;
            min-height: 39px;
        }

        .products-toolbar .btn-theme,
        .products-toolbar .btn-filter-reset {
            margin: 0;
            line-height: 1;
        }

        .btn-filter-reset {
            border: 1px solid var(--border-color);
            background: var(--card-bg);
            color: var(--text-color);
        }

        .products-card {
            position: relative;
            overflow: hidden;
        }

        .products-loading {
            position: absolute;
            inset: 0;
            z-index: 20;
            display: none;
            align-items: center;
            justify-content: center;
            background: color-mix(in srgb, var(--card-bg) 82%, transparent);
            backdrop-filter: blur(2px);
        }

        .products-loading.show {
            display: flex;
        }

        .products-loading-box {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            padding: 10px 14px;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            background: var(--card-bg);
            color: var(--muted-color);
            font-size: 11px;
            box-shadow: 0 12px 30px rgba(0, 0, 0, .1);
        }

        .product-table {
            margin: 0;
            font-size: 10px;
        }

        .product-table th {
            padding: 10px 12px;
            border-color: var(--border-color);
            background: color-mix(in srgb, var(--muted-color) 6%, transparent);
            color: var(--muted-color);
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: .04em;
            white-space: nowrap;
        }

        .product-table td {
            padding: 10px 12px;
            vertical-align: middle;
            border-color: var(--border-color);
            background: var(--card-bg) !important;
            color: var(--text-color);
        }

        .product-name {
            font-size: 11px;
            font-weight: 800;
        }

        .product-sub {
            margin-top: 2px;
            color: var(--muted-color);
            font-size: 9px;
        }

        .badge-soft,
        .status-badge {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 4px 8px;
            font-size: 9px;
            font-weight: 700;
        }

        .badge-soft {
            background: var(--primary-soft);
            color: var(--primary-dark);
        }

        .status-active {
            background: #eaf8f0;
            color: #168449;
        }

        .status-inactive {
            background: #fdecec;
            color: #bd2d2d;
        }

        .thumb,
        .thumb-empty {
            width: 40px;
            height: 40px;
            flex: 0 0 40px;
            border-radius: 9px;
            border: 1px solid var(--border-color);
        }

        .thumb {
            object-fit: cover;
        }

        .thumb-empty {
            display: grid;
            place-items: center;
            background: var(--primary-soft);
            color: var(--primary-dark);
        }

        .action-btn {
            width: 30px;
            height: 30px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--card-bg);
            color: var(--text-color);
            display: inline-grid;
            place-items: center;
            font-size: 10px;
        }

        .action-btn:hover {
            background: var(--primary-soft);
            color: var(--primary-dark);
        }

        .action-btn.danger:hover {
            background: #fdecec;
            color: #bd2d2d;
        }

        .empty-state {
            padding: 58px 20px;
            text-align: center;
            color: var(--muted-color);
        }

        .empty-state i {
            margin-bottom: 10px;
            font-size: 30px;
        }

        .pagination-wrap {
            min-height: 58px;
            padding: 10px 12px;
            border-top: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .pagination-summary {
            color: var(--muted-color);
            font-size: 10px;
        }

        .pagination {
            margin: 0;
            gap: 4px;
            flex-wrap: wrap;
        }

        .pagination .page-link {
            min-width: 32px;
            height: 32px;
            padding: 0 9px;
            border: 1px solid var(--border-color);
            border-radius: 8px !important;
            background: var(--card-bg);
            color: var(--text-color);
            font-size: 10px;
            display: grid;
            place-items: center;
            box-shadow: none;
        }

        .pagination .page-item.active .page-link {
            border-color: var(--primary);
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: #fff;
        }

        .pagination .page-item.disabled .page-link {
            opacity: .45;
            pointer-events: none;
        }

        .theme-toast {
            position: fixed;
            right: 18px;
            top: 78px;
            z-index: 20000;
            min-width: 260px;
            max-width: 420px;
            padding: 11px 14px;
            border-radius: 10px;
            color: #fff;
            font-size: 11px;
            font-weight: 600;
            box-shadow: 0 14px 35px rgba(0, 0, 0, .22);
            opacity: 0;
            transform: translateY(-10px);
            transition: .22s;
        }

        .theme-toast.show {
            opacity: 1;
            transform: translateY(0);
        }

        .theme-toast-success { background: #168449; }
        .theme-toast-error { background: #c0392b; }

        body.dark-mode,
        body[data-theme="dark"],
        html.dark-mode body,
        html[data-theme="dark"] body {
            --page-bg: #0f151b;
            --card-bg: #182129;
            --text-color: #f3f6f8;
            --muted-color: #9aa7b3;
            --border-color: #2c3944;
        }

        @media (max-width: 1200px) and (min-width: 992px) {
            .products-filter-grid {
                grid-template-columns: minmax(190px, 1.55fr) minmax(135px, .9fr) minmax(120px, .72fr) 96px auto auto;
                gap: 7px;
            }

            .products-filter-grid .add-product-wrap {
                grid-column: auto;
                justify-content: flex-end;
            }

            .products-filter-grid .add-product-wrap .btn-theme {
                width: auto;
                min-width: 116px;
                padding-left: 11px;
                padding-right: 11px;
            }

            .products-filter-grid .btn-filter-reset {
                padding-left: 10px;
                padding-right: 10px;
            }
        }

        @media (max-width: 991.98px) {
            .stat-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .products-filter-grid {
                grid-template-columns: 1fr 1fr;
            }

            .products-filter-grid .search-field {
                grid-column: 1 / -1;
            }

            .products-card {
                overflow: visible;
                border: 0;
                background: transparent;
            }

            .table-responsive {
                overflow: visible;
            }

            .product-table,
            .product-table tbody {
                display: block;
            }

            .product-table thead {
                display: none;
            }

            .product-table tbody {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 12px;
            }

            .product-table tbody tr {
                display: grid;
                grid-template-columns: 1fr 1fr;
                padding: 14px;
                border: 1px solid var(--border-color);
                border-radius: var(--radius);
                background: var(--card-bg);
            }

            .product-table tbody td {
                display: flex;
                justify-content: space-between;
                gap: 10px;
                min-width: 0;
                padding: 8px 0;
                border: 0;
                border-bottom: 1px dashed var(--border-color);
                text-align: right !important;
            }

            .product-table tbody td::before {
                content: attr(data-label);
                color: var(--muted-color);
                font-size: 9px;
                font-weight: 700;
                text-transform: uppercase;
                text-align: left;
            }

            .product-table tbody td.main-cell,
            .product-table tbody td.actions {
                grid-column: 1 / -1;
            }

            .product-table tbody td.main-cell {
                justify-content: flex-start;
                padding-top: 0;
            }

            .product-table tbody td.main-cell::before {
                display: none;
            }

            .product-table tbody td.actions {
                border-bottom: 0;
            }

            .pagination-wrap {
                border: 1px solid var(--border-color);
                border-radius: var(--radius);
                background: var(--card-bg);
                margin-top: 12px;
            }
        }

        @media (max-width: 767.98px) {
            .content-wrap {
                padding-left: 10px;
                padding-right: 10px;
            }

            .products-filter-grid {
                grid-template-columns: 1fr;
            }

            .products-filter-grid .search-field,
            .products-filter-grid .add-product-wrap {
                grid-column: auto;
            }

            .product-table tbody {
                grid-template-columns: 1fr;
            }

            .product-table tbody tr {
                grid-template-columns: 1fr;
            }

            .product-table tbody td {
                grid-column: 1 / -1;
            }

            .pagination-wrap {
                align-items: flex-start;
                flex-direction: column;
            }

            .theme-toast {
                left: 12px;
                right: 12px;
                min-width: 0;
                max-width: none;
            }
        }
    </style>
</head>
<body>
<?php include('includes/sidebar.php'); ?>
<main class="app-main">
    <?php include('includes/nav.php'); ?>
    <div class="content-wrap">
        <?php if (!$canView): ?>
            <div class="products-card">
                <div class="empty-state">
                    <i class="fa-solid fa-lock"></i>
                    <div>You do not have permission to view products.</div>
                </div>
            </div>
        <?php else: ?>
            <div class="stat-grid">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fa-solid fa-gem"></i></div>
                    <div><div class="stat-label">Total Products</div><div class="stat-value" id="statTotal">0</div></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fa-solid fa-circle-check"></i></div>
                    <div><div class="stat-label">Active Products</div><div class="stat-value" id="statActive">0</div></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fa-solid fa-circle-xmark"></i></div>
                    <div><div class="stat-label">Inactive Products</div><div class="stat-value" id="statInactive">0</div></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fa-solid fa-boxes-stacked"></i></div>
                    <div><div class="stat-label">Stock Tracked</div><div class="stat-value" id="statTracked">0</div></div>
                </div>
            </div>

            <div class="products-toolbar">
                <form id="productsFilterForm" class="products-filter-grid" autocomplete="off">
                    <div class="filter-field has-icon search-field">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input
                            type="search"
                            class="form-control"
                            id="productSearch"
                            name="search"
                            value="<?php echo e($initialSearch); ?>"
                            placeholder="Search product name, code, barcode or category..."
                        >
                    </div>

                    <div class="filter-field has-icon">
                        <i class="fa-solid fa-tags"></i>
                        <select class="form-select" id="categoryFilter" name="category_id">
                            <option value="0">All categories</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo (int)$category['id']; ?>" <?php echo $initialCategory === (int)$category['id'] ? 'selected' : ''; ?>>
                                    <?php echo e($category['category_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-field has-icon">
                        <i class="fa-solid fa-toggle-on"></i>
                        <select class="form-select" id="statusFilter" name="status">
                            <option value="">All status</option>
                            <option value="active" <?php echo $initialStatus === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $initialStatus === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>

                    <div class="filter-field">
                        <select class="form-select" id="perPageFilter" name="per_page" aria-label="Rows per page">
                            <?php foreach ($allowedPerPage as $size): ?>
                                <option value="<?php echo $size; ?>" <?php echo $initialPerPage === $size ? 'selected' : ''; ?>><?php echo $size; ?> rows</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <button type="button" class="btn-filter-reset" id="resetFilters">
                        <i class="fa-solid fa-rotate-left"></i> Reset
                    </button>

                    <?php if ($canCreate): ?>
                        <div class="add-product-wrap">
                            <button type="button" class="btn-theme" id="addProductButton">
                                <i class="fa-solid fa-plus"></i> Add Product
                            </button>
                        </div>
                    <?php endif; ?>
                </form>
            </div>

            <section class="products-card" id="productsListSection">
                <div class="products-loading" id="productsLoading" aria-hidden="true">
                    <div class="products-loading-box">
                        <i class="fa-solid fa-spinner fa-spin"></i>
                        <span>Loading products...</span>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table product-table align-middle" id="productsTable">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Category</th>
                                <th>Metal</th>
                                <th>Weights</th>
                                <?php if ($canValue): ?><th>Rates</th><?php endif; ?>
                                <th>Stock</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="productsTableBody"></tbody>
                    </table>
                </div>

                <div id="productsEmpty" class="empty-state d-none">
                    <i class="fa-regular fa-folder-open"></i>
                    <div>No products found.</div>
                </div>

                <div class="pagination-wrap" id="paginationWrap">
                    <div class="pagination-summary" id="paginationSummary">Showing 0 products</div>
                    <nav aria-label="Product pages">
                        <ul class="pagination pagination-sm" id="productsPagination"></ul>
                    </nav>
                </div>
            </section>
        <?php endif; ?>

        <?php include('includes/footer.php'); ?>
    </div>
</main>

<?php include('includes/script.php'); ?>
<script src="assets/js/script.js"></script>
<?php if ($canView): ?>
<script>
(function () {
    'use strict';

    const csrfToken = <?php echo json_encode($csrfToken); ?>;
    const currencySymbol = <?php echo json_encode((string)($_SESSION['currency_symbol'] ?? '₹')); ?>;
    const permissions = {
        canValue: <?php echo $canValue ? 'true' : 'false'; ?>,
        canUpdate: <?php echo $canUpdate ? 'true' : 'false'; ?>,
        canDelete: <?php echo $canDelete ? 'true' : 'false'; ?>
    };

    const form = document.getElementById('productsFilterForm');
    const searchInput = document.getElementById('productSearch');
    const categoryFilter = document.getElementById('categoryFilter');
    const statusFilter = document.getElementById('statusFilter');
    const perPageFilter = document.getElementById('perPageFilter');
    const resetFilters = document.getElementById('resetFilters');
    const tableBody = document.getElementById('productsTableBody');
    const emptyState = document.getElementById('productsEmpty');
    const loading = document.getElementById('productsLoading');
    const pagination = document.getElementById('productsPagination');
    const paginationSummary = document.getElementById('paginationSummary');
    const listSection = document.getElementById('productsListSection');
    const addProductButton = document.getElementById('addProductButton');

    let currentController = null;
    let searchTimer = null;
    let currentPage = <?php echo $initialPage; ?>;

    function escapeHtml(value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function number(value, digits = 2) {
        return Number(value || 0).toLocaleString(undefined, {
            minimumFractionDigits: digits,
            maximumFractionDigits: digits
        });
    }

    function showToast(type, message) {
        const toast = document.createElement('div');
        toast.className = 'theme-toast theme-toast-' + type;
        toast.innerHTML = '<i class="fa-solid ' + (type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation') + ' me-2"></i><span></span>';
        toast.querySelector('span').textContent = message;
        document.body.appendChild(toast);
        requestAnimationFrame(() => toast.classList.add('show'));
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 250);
        }, 3200);
    }

    function setLoading(state) {
        loading.classList.toggle('show', state);
        loading.setAttribute('aria-hidden', state ? 'false' : 'true');
    }

    function collectState(page = currentPage) {
        return {
            search: searchInput.value.trim(),
            category_id: categoryFilter.value,
            status: statusFilter.value,
            per_page: perPageFilter.value,
            page: Math.max(1, Number(page) || 1)
        };
    }

    function updateUrl(state, replace = false) {
        const url = new URL(window.location.href);
        ['search', 'category_id', 'status', 'per_page', 'page'].forEach(key => url.searchParams.delete(key));

        if (state.search) url.searchParams.set('search', state.search);
        if (Number(state.category_id) > 0) url.searchParams.set('category_id', state.category_id);
        if (state.status) url.searchParams.set('status', state.status);
        if (Number(state.per_page) !== 10) url.searchParams.set('per_page', state.per_page);
        if (Number(state.page) > 1) url.searchParams.set('page', state.page);

        const method = replace ? 'replaceState' : 'pushState';
        window.history[method](state, '', url);
    }

    function productRow(product) {
        const image = product.image_path
            ? '<img src="' + escapeHtml(product.image_path) + '" class="thumb" alt="">'
            : '<div class="thumb-empty"><i class="fa-solid fa-gem"></i></div>';

        const barcode = product.barcode ? ' · ' + escapeHtml(product.barcode) : '';
        const purity = product.purity !== null && product.purity !== ''
            ? '<div class="product-sub mt-1">' + escapeHtml(product.purity) + '%</div>'
            : '';

        const rates = permissions.canValue
            ? '<td data-label="Rates"><div>Sale: ' + escapeHtml(currencySymbol) + number(product.sale_rate, 2) + '</div><div class="product-sub">Purchase: ' + escapeHtml(currencySymbol) + number(product.purchase_rate, 2) + '</div></td>'
            : '';

        let actions = '';
        if (permissions.canUpdate) {
            actions += '<a class="action-btn" href="product-edit.php?id=' + Number(product.id) + '" title="Edit"><i class="fa-solid fa-pen"></i></a>';
            actions += '<button type="button" class="action-btn toggle-product" data-id="' + Number(product.id) + '" data-active="' + Number(product.is_active) + '" title="' + (Number(product.is_active) === 1 ? 'Deactivate' : 'Activate') + '"><i class="fa-solid ' + (Number(product.is_active) === 1 ? 'fa-ban' : 'fa-circle-check') + '"></i></button>';
        }
        if (permissions.canDelete) {
            actions += '<button type="button" class="action-btn danger delete-product" data-id="' + Number(product.id) + '" data-name="' + escapeHtml(product.product_name) + '" title="Delete"><i class="fa-solid fa-trash"></i></button>';
        }

        return '<tr>' +
            '<td class="main-cell" data-label="Product"><div class="d-flex align-items-center gap-2">' + image + '<div><div class="product-name">' + escapeHtml(product.product_name) + '</div><div class="product-sub">' + escapeHtml(product.product_code) + barcode + '</div></div></div></td>' +
            '<td data-label="Category">' + escapeHtml(product.category_name || '—') + '</td>' +
            '<td data-label="Metal"><span class="badge-soft">' + escapeHtml(product.metal_name || '—') + '</span>' + purity + '</td>' +
            '<td data-label="Weights"><div>G: ' + number(product.gross_weight, 3) + '</div><div class="product-sub">N: ' + number(product.net_weight, 3) + '</div></td>' +
            rates +
            '<td data-label="Stock">' + number(product.stock_qty, 3) + ' ' + escapeHtml(product.unit_name || '') + '</td>' +
            '<td data-label="Status"><span class="status-badge ' + (Number(product.is_active) === 1 ? 'status-active' : 'status-inactive') + '">' + (Number(product.is_active) === 1 ? 'Active' : 'Inactive') + '</span></td>' +
            '<td class="actions text-end" data-label="Actions"><div class="d-inline-flex gap-1">' + actions + '</div></td>' +
            '</tr>';
    }

    function pageItem(label, page, disabled = false, active = false, ariaLabel = '') {
        const item = document.createElement('li');
        item.className = 'page-item' + (disabled ? ' disabled' : '') + (active ? ' active' : '');
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'page-link';
        button.innerHTML = label;
        button.dataset.page = String(page);
        if (ariaLabel) button.setAttribute('aria-label', ariaLabel);
        item.appendChild(button);
        return item;
    }

    function renderPagination(meta) {
        pagination.innerHTML = '';
        const totalPages = Number(meta.total_pages || 1);
        const page = Number(meta.page || 1);

        pagination.appendChild(pageItem('<i class="fa-solid fa-angle-left"></i>', page - 1, page <= 1, false, 'Previous page'));

        let start = Math.max(1, page - 2);
        let end = Math.min(totalPages, page + 2);
        if (page <= 3) end = Math.min(totalPages, 5);
        if (page >= totalPages - 2) start = Math.max(1, totalPages - 4);

        if (start > 1) {
            pagination.appendChild(pageItem('1', 1, false, page === 1));
            if (start > 2) pagination.appendChild(pageItem('…', page, true));
        }

        for (let i = start; i <= end; i++) {
            pagination.appendChild(pageItem(String(i), i, false, i === page));
        }

        if (end < totalPages) {
            if (end < totalPages - 1) pagination.appendChild(pageItem('…', page, true));
            pagination.appendChild(pageItem(String(totalPages), totalPages, false, page === totalPages));
        }

        pagination.appendChild(pageItem('<i class="fa-solid fa-angle-right"></i>', page + 1, page >= totalPages, false, 'Next page'));

        paginationSummary.textContent = meta.total > 0
            ? 'Showing ' + meta.from + '–' + meta.to + ' of ' + meta.total + ' products'
            : 'Showing 0 products';
    }

    function updateStats(stats) {
        document.getElementById('statTotal').textContent = Number(stats.total || 0).toLocaleString();
        document.getElementById('statActive').textContent = Number(stats.active || 0).toLocaleString();
        document.getElementById('statInactive').textContent = Number(stats.inactive || 0).toLocaleString();
        document.getElementById('statTracked').textContent = Number(stats.tracked || 0).toLocaleString();
    }

    async function loadProducts(options = {}) {
        const requestedPage = Math.max(1, Number(options.page ?? currentPage) || 1);
        const state = collectState(requestedPage);

        if (currentController) currentController.abort();
        currentController = new AbortController();
        setLoading(true);

        const data = new FormData();
        data.append('action', 'list');
        data.append('csrf_token', csrfToken);
        Object.entries(state).forEach(([key, value]) => data.append(key, String(value)));

        try {
            const response = await fetch('api/products-save.php', {
                method: 'POST',
                body: data,
                credentials: 'same-origin',
                headers: {'X-Requested-With': 'XMLHttpRequest'},
                signal: currentController.signal
            });

            const result = await response.json().catch(() => ({success: false, message: 'Invalid server response.'}));
            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Unable to load products.');
            }

            currentPage = Number(result.meta.page || 1);
            tableBody.innerHTML = result.products.map(productRow).join('');
            emptyState.classList.toggle('d-none', result.products.length > 0);
            document.getElementById('productsTable').classList.toggle('d-none', result.products.length === 0);
            renderPagination(result.meta);
            updateStats(result.stats);

            const finalState = collectState(currentPage);
            if (options.updateHistory !== false) {
                updateUrl(finalState, options.replaceHistory === true);
            }

            if (options.scroll === true) {
                listSection.scrollIntoView({behavior: 'smooth', block: 'start'});
            }
        } catch (error) {
            if (error.name !== 'AbortError') {
                showToast('error', error.message);
            }
        } finally {
            setLoading(false);
        }
    }

    async function apiAction(formData) {
        const response = await fetch('api/products-save.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        });
        const result = await response.json().catch(() => ({success: false, message: 'Invalid server response.'}));
        if (!response.ok || !result.success) {
            throw new Error(result.message || 'Request failed.');
        }
        return result;
    }

    function resetToFirstPageAndLoad() {
        currentPage = 1;
        loadProducts({page: 1, scroll: false});
    }

    form.addEventListener('submit', event => event.preventDefault());

    if (addProductButton) {
        addProductButton.addEventListener('click', () => {
            window.location.href = 'product-add.php';
        });
    }

    searchInput.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(resetToFirstPageAndLoad, 350);
    });

    [categoryFilter, statusFilter, perPageFilter].forEach(element => {
        element.addEventListener('change', resetToFirstPageAndLoad);
    });

    resetFilters.addEventListener('click', () => {
        searchInput.value = '';
        categoryFilter.value = '0';
        statusFilter.value = '';
        perPageFilter.value = '10';
        currentPage = 1;
        loadProducts({page: 1, scroll: false});
        searchInput.focus();
    });

    pagination.addEventListener('click', event => {
        const button = event.target.closest('[data-page]');
        if (!button || button.closest('.disabled') || button.closest('.active')) return;
        const page = Number(button.dataset.page || 1);
        if (page < 1) return;
        currentPage = page;
        loadProducts({page, scroll: true});
    });

    document.addEventListener('click', async event => {
        const toggle = event.target.closest('.toggle-product');
        if (toggle) {
            const next = Number(toggle.dataset.active) === 1 ? 0 : 1;
            if (!window.confirm((next === 1 ? 'Activate' : 'Deactivate') + ' this product?')) return;

            const data = new FormData();
            data.append('action', 'toggle');
            data.append('csrf_token', csrfToken);
            data.append('product_id', toggle.dataset.id);
            data.append('is_active', String(next));

            try {
                const result = await apiAction(data);
                showToast('success', result.message);
                loadProducts({page: currentPage, updateHistory: false});
            } catch (error) {
                showToast('error', error.message);
            }
        }

        const remove = event.target.closest('.delete-product');
        if (remove) {
            if (!window.confirm('Delete ' + remove.dataset.name + '? This action cannot be undone.')) return;

            const data = new FormData();
            data.append('action', 'delete');
            data.append('csrf_token', csrfToken);
            data.append('product_id', remove.dataset.id);

            try {
                const result = await apiAction(data);
                showToast('success', result.message);
                loadProducts({page: currentPage, updateHistory: false});
            } catch (error) {
                showToast('error', error.message);
            }
        }
    });

    window.addEventListener('popstate', () => {
        const params = new URLSearchParams(window.location.search);
        searchInput.value = params.get('search') || '';
        categoryFilter.value = params.get('category_id') || '0';
        statusFilter.value = params.get('status') || '';
        perPageFilter.value = params.get('per_page') || '10';
        currentPage = Math.max(1, Number(params.get('page') || 1));
        loadProducts({page: currentPage, updateHistory: false, scroll: true});
    });

    loadProducts({page: currentPage, replaceHistory: true});
})();
</script>
<?php endif; ?>
</body>
</html>
