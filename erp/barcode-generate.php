<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string) ($_SESSION['timezone'] ?? 'Asia/Kolkata'));

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
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
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
            'create' => 'can_create',
            'update' => 'can_update',
        ];

        $field = $fieldMap[$action] ?? '';
        if ($field === '') {
            return false;
        }

        $permissions = $_SESSION['permissions'] ?? [];

        foreach (['perm.products.list', 'perm.products'] as $permissionCode) {
            if (isset($permissions[$permissionCode][$field])) {
                return (int) $permissions[$permissionCode][$field] === 1;
            }
        }

        $businessId = (int) ($_SESSION['business_id'] ?? 0);
        $roleId = (int) ($_SESSION['role_id'] ?? 0);

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

        return (int) ($row[$field] ?? 0) === 1;
    }
}

if (!productPermission($conn, 'open')) {
    http_response_code(403);
    die('Access denied. You do not have permission to open products.');
}

$canView = productPermission($conn, 'view') || productPermission($conn, 'open');

$businessId = (int) ($_SESSION['business_id'] ?? 0);
if ($businessId <= 0) {
    http_response_code(403);
    die('A valid business must be selected.');
}

if (empty($_SESSION['barcode_print_csrf'])) {
    $_SESSION['barcode_print_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['barcode_print_csrf'];

function jsonResponse(bool $success, string $message = '', array $extra = [], int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        array_merge(
            ['success' => $success, 'message' => $message],
            $extra
        ),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

/*
|--------------------------------------------------------------------------
| AJAX PRODUCT LIST
|--------------------------------------------------------------------------
| This page no longer generates, edits or previews barcodes.
| It only loads product rows and sends the entered quantities to the
| local Windows Barcode Print Manager API.
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $postedToken = (string) ($_POST['csrf_token'] ?? '');

    if (!hash_equals($csrfToken, $postedToken)) {
        jsonResponse(
            false,
            'Session expired. Refresh the page and try again.',
            [],
            419
        );
    }

    if (!$canView) {
        jsonResponse(
            false,
            'You do not have permission to view products.',
            [],
            403
        );
    }

    $action = (string) $_POST['action'];

    if ($action === 'list') {
        $search = trim((string) ($_POST['search'] ?? ''));

        $where = ['p.business_id = ?'];
        $types = 'i';
        $params = [$businessId];

        if ($search !== '') {
            $where[] = '(
                p.product_name LIKE ?
                OR p.product_code LIKE ?
                OR p.barcode LIKE ?
                OR pc.category_name LIKE ?
                OR m.metal_name LIKE ?
            )';

            $like = '%' . $search . '%';
            $types .= 'sssss';

            array_push(
                $params,
                $like,
                $like,
                $like,
                $like,
                $like
            );
        }

        $sql = "SELECT
                    p.id,
                    p.product_code,
                    p.barcode,
                    p.product_name,
                    p.image_path,
                    p.gross_weight,
                    p.net_weight,
                    p.sale_rate,
                    p.is_active,
                    COALESCE(pc.category_name, '') AS category_name,
                    COALESCE(m.metal_name, '') AS metal_name
                FROM products p
                LEFT JOIN product_categories pc ON pc.id = p.category_id
                LEFT JOIN metals m ON m.id = p.metal_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY
                    p.is_active DESC,
                    p.product_name ASC,
                    p.id ASC
                LIMIT 1000";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            jsonResponse(false, 'Unable to load products.', [], 500);
        }

        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $products = [];
        $printable = 0;

        while ($row = $result->fetch_assoc()) {
            $barcode = trim((string) ($row['barcode'] ?? ''));

            if ($barcode !== '' && preg_match('/^[0-9]{1,14}$/', $barcode)) {
                $printable++;
            }

            $products[] = $row;
        }

        $stmt->close();

        jsonResponse(
            true,
            '',
            [
                'products' => $products,
                'total' => count($products),
                'printable' => $printable,
            ]
        );
    }

    jsonResponse(false, 'Invalid request.', [], 400);
}

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

$themeStmt = $conn->prepare(
    'SELECT * FROM business_theme_settings WHERE business_id = ? LIMIT 1'
);

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

$pageTitle = 'Barcode Printing';
$businessName = (string) ($_SESSION['business_name'] ?? 'Jewellery ERP');
$currencySymbol = (string) ($_SESSION['currency_symbol'] ?? '₹');
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo e($businessName); ?> - Barcode Printing</title>

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
            --sidebar-width: <?php echo (int) $theme['sidebar_width_px']; ?>px;
            --radius: <?php echo (int) $theme['border_radius_px']; ?>px;
        }

        body {
            background: var(--page-bg);
            color: var(--text-color);
            font-family: <?php echo json_encode((string) $theme['font_family']); ?>, sans-serif;
        }

        .sidebar {
            background: linear-gradient(
                180deg,
                var(--sidebar-gradient-1),
                var(--sidebar-gradient-2),
                var(--sidebar-gradient-3)
            ) !important;
        }

        .print-page-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            overflow: hidden;
            position: relative;
        }

        .print-toolbar {
            padding: 14px;
            border-bottom: 1px solid var(--border-color);
            display: grid;
            grid-template-columns: minmax(260px, 1fr) auto auto auto;
            gap: 10px;
            align-items: center;
        }

        .search-field {
            position: relative;
        }

        .search-field > i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--muted-color);
            pointer-events: none;
        }

        .search-field .form-control {
            padding-left: 36px;
        }

        .form-control {
            min-height: 40px;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            background: var(--card-bg);
            color: var(--text-color);
            box-shadow: none;
            font-size: 12px;
        }

        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 .2rem color-mix(in srgb, var(--primary) 12%, transparent);
        }

        .btn-theme,
        .btn-soft {
            min-height: 40px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 800;
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
            background: linear-gradient(
                135deg,
                var(--primary),
                var(--primary-dark)
            );
        }

        .btn-theme:disabled {
            opacity: .6;
            cursor: not-allowed;
        }

        .btn-soft {
            border: 1px solid var(--border-color);
            color: var(--text-color);
            background: var(--card-bg);
        }

        .api-status {
            min-height: 40px;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 8px 12px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            font-size: 10px;
            font-weight: 800;
            white-space: nowrap;
        }

        .api-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #9aa1a8;
        }

        .api-status.online {
            color: #168449;
            border-color: #bfe7cf;
            background: #f2fbf6;
        }

        .api-status.online .api-dot {
            background: #168449;
        }

        .api-status.offline {
            color: #c0392b;
            border-color: #f0c7c2;
            background: #fff5f4;
        }

        .api-status.offline .api-dot {
            background: #c0392b;
        }

        .table-wrap {
            position: relative;
            min-height: 220px;
        }

        .product-table {
            margin: 0;
            font-size: 11px;
        }

        .product-table th {
            padding: 11px 12px;
            border-color: var(--border-color);
            background: color-mix(in srgb, var(--muted-color) 6%, transparent);
            color: var(--muted-color);
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: .04em;
            white-space: nowrap;
        }

        .product-table td {
            padding: 9px 12px;
            vertical-align: middle;
            border-color: var(--border-color);
            background: var(--card-bg) !important;
            color: var(--text-color);
        }

        .product-table tbody tr.has-qty td {
            background: color-mix(in srgb, var(--primary-soft) 55%, var(--card-bg)) !important;
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

        .barcode-text {
            font-family: Consolas, monospace;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .04em;
        }

        .barcode-missing {
            color: #b76b00;
            font-size: 10px;
            font-weight: 700;
        }

        .price-text {
            font-weight: 700;
            white-space: nowrap;
        }

        .qty-input {
            width: 90px;
            min-height: 36px;
            height: 36px;
            text-align: center;
            font-weight: 800;
            font-size: 13px;
            margin-left: auto;
            margin-right: auto;
        }

        .qty-input:disabled {
            background: color-mix(in srgb, var(--muted-color) 8%, var(--card-bg));
            cursor: not-allowed;
        }

        .row-status {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 76px;
            padding: 5px 8px;
            border-radius: 999px;
            font-size: 9px;
            font-weight: 800;
        }

        .row-status.ready {
            background: #eaf8f0;
            color: #168449;
        }

        .row-status.waiting {
            background: #f1f3f5;
            color: #6f7882;
        }

        .row-status.printing {
            background: #eef5ff;
            color: #2c6db2;
        }

        .row-status.done {
            background: #eaf8f0;
            color: #168449;
        }

        .row-status.error {
            background: #fff0ee;
            color: #c0392b;
        }

        .summary-bar {
            padding: 12px 14px;
            border-top: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .summary-left {
            display: flex;
            align-items: center;
            gap: 18px;
            flex-wrap: wrap;
        }

        .summary-item {
            font-size: 10px;
            color: var(--muted-color);
        }

        .summary-item strong {
            color: var(--text-color);
            font-size: 13px;
        }

        .loading-overlay {
            position: absolute;
            inset: 0;
            z-index: 30;
            display: none;
            align-items: center;
            justify-content: center;
            background: color-mix(in srgb, var(--card-bg) 86%, transparent);
            backdrop-filter: blur(2px);
        }

        .loading-overlay.show {
            display: flex;
        }

        .loading-box {
            padding: 11px 15px;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            background: var(--card-bg);
            color: var(--muted-color);
            font-size: 11px;
            font-weight: 700;
        }

        .empty-state {
            padding: 60px 20px;
            text-align: center;
            color: var(--muted-color);
        }

        .theme-toast {
            position: fixed;
            right: 18px;
            top: 78px;
            z-index: 20000;
            min-width: 280px;
            max-width: 460px;
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

        .theme-toast-success {
            background: #168449;
        }

        .theme-toast-error {
            background: #c0392b;
        }

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

        @media (max-width: 1050px) {
            .print-toolbar {
                grid-template-columns: 1fr 1fr;
            }

            .search-field {
                grid-column: 1 / -1;
            }
        }

        @media (max-width: 767.98px) {
            .content-wrap {
                padding-left: 10px;
                padding-right: 10px;
            }

            .print-toolbar {
                grid-template-columns: 1fr;
            }

            .search-field {
                grid-column: auto;
            }

            .api-status,
            .btn-soft,
            .btn-theme {
                width: 100%;
            }

            .product-table thead {
                display: none;
            }

            .product-table,
            .product-table tbody {
                display: block;
            }

            .product-table tbody {
                display: grid;
                grid-template-columns: 1fr;
                gap: 10px;
                padding: 10px;
            }

            .product-table tbody tr {
                display: grid;
                grid-template-columns: 1fr;
                padding: 12px;
                border: 1px solid var(--border-color);
                border-radius: var(--radius);
                background: var(--card-bg);
            }

            .product-table tbody td {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 10px;
                padding: 7px 0;
                border: 0;
                border-bottom: 1px dashed var(--border-color);
            }

            .product-table tbody td:last-child {
                border-bottom: 0;
            }

            .product-table tbody td::before {
                content: attr(data-label);
                color: var(--muted-color);
                font-size: 9px;
                font-weight: 700;
                text-transform: uppercase;
            }

            .product-table tbody td.main-cell {
                justify-content: flex-start;
            }

            .product-table tbody td.main-cell::before {
                display: none;
            }

            .qty-input {
                margin: 0;
            }

            .summary-bar {
                align-items: stretch;
                flex-direction: column;
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

                <div class="print-page-card">
                    <div class="empty-state">
                        <i class="fa-solid fa-lock mb-2"></i>
                        <div>You do not have permission to view products.</div>
                    </div>
                </div>

            <?php else: ?>

                <div class="print-page-card">

                    <div class="print-toolbar">
                        <div class="search-field">
                            <i class="fa-solid fa-magnifying-glass"></i>
                            <input
                                type="search"
                                class="form-control"
                                id="productSearch"
                                placeholder="Search product name, code, barcode, category or metal..."
                                autocomplete="off"
                            >
                        </div>

                        <div class="api-status" id="apiStatus">
                            <span class="api-dot"></span>
                            <span id="apiStatusText">Checking Print Manager...</span>
                        </div>

                        <button
                            type="button"
                            class="btn-soft"
                            id="clearCounts"
                        >
                            <i class="fa-solid fa-eraser"></i>
                            Clear Counts
                        </button>

                        <button
                            type="button"
                            class="btn-theme"
                            id="printEntered"
                        >
                            <i class="fa-solid fa-print"></i>
                            Print Entered Qty
                        </button>
                    </div>

                    <div class="table-wrap">
                        <div class="loading-overlay" id="productLoading">
                            <div class="loading-box">
                                <i class="fa-solid fa-spinner fa-spin me-2"></i>
                                <span id="loadingText">Loading products...</span>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table
                                class="table product-table align-middle"
                                id="productTable"
                            >
                                <thead>
                                    <tr>
                                        <th style="width:60px">#</th>
                                        <th>Product</th>
                                        <th>Category / Metal</th>
                                        <th>Barcode</th>
                                        <th class="text-end">Sale Rate</th>
                                        <th class="text-center" style="width:115px">Count</th>
                                        <th class="text-center" style="width:105px">Status</th>
                                    </tr>
                                </thead>

                                <tbody id="productTableBody"></tbody>
                            </table>
                        </div>

                        <div
                            class="empty-state d-none"
                            id="emptyState"
                        >
                            <i class="fa-regular fa-folder-open fa-2x mb-2"></i>
                            <div>No matching products found.</div>
                        </div>
                    </div>

                    <div class="summary-bar">
                        <div class="summary-left">
                            <div class="summary-item">
                                Products:
                                <strong id="totalProducts">0</strong>
                            </div>

                            <div class="summary-item">
                                Printable:
                                <strong id="printableProducts">0</strong>
                            </div>

                            <div class="summary-item">
                                Products with Count:
                                <strong id="enteredProducts">0</strong>
                            </div>

                            <div class="summary-item">
                                Total Labels:
                                <strong id="totalLabels">0</strong>
                            </div>
                        </div>

                        <div class="summary-item">
                            Local API:
                            <strong>127.0.0.1:17991</strong>
                        </div>
                    </div>

                </div>

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
            const businessName = <?php echo json_encode($businessName); ?>;
            const currencySymbol = <?php echo json_encode($currencySymbol); ?>;

            /*
             * Your local Windows Barcode Print Manager.
             * The browser on each billing PC calls its own 127.0.0.1.
             */
            const LOCAL_API = 'http://127.0.0.1:17991';

            const body = document.getElementById('productTableBody');
            const table = document.getElementById('productTable');
            const emptyState = document.getElementById('emptyState');
            const loading = document.getElementById('productLoading');
            const loadingText = document.getElementById('loadingText');
            const search = document.getElementById('productSearch');
            const printButton = document.getElementById('printEntered');
            const clearButton = document.getElementById('clearCounts');

            const apiStatus = document.getElementById('apiStatus');
            const apiStatusText = document.getElementById('apiStatusText');

            const totalProducts = document.getElementById('totalProducts');
            const printableProducts = document.getElementById('printableProducts');
            const enteredProducts = document.getElementById('enteredProducts');
            const totalLabels = document.getElementById('totalLabels');

            let products = [];
            let searchTimer = null;
            let isPrinting = false;

            function escapeHtml(value) {
                return String(value ?? '')
                    .replaceAll('&', '&amp;')
                    .replaceAll('<', '&lt;')
                    .replaceAll('>', '&gt;')
                    .replaceAll('"', '&quot;')
                    .replaceAll("'", '&#039;');
            }

            function showToast(type, message, duration = 3500) {
                const toast = document.createElement('div');

                toast.className =
                    'theme-toast theme-toast-' + type;

                toast.innerHTML =
                    '<i class="fa-solid ' +
                    (type === 'success'
                        ? 'fa-circle-check'
                        : 'fa-circle-exclamation') +
                    ' me-2"></i><span></span>';

                toast.querySelector('span').textContent = message;

                document.body.appendChild(toast);

                requestAnimationFrame(
                    () => toast.classList.add('show')
                );

                setTimeout(() => {
                    toast.classList.remove('show');

                    setTimeout(
                        () => toast.remove(),
                        250
                    );
                }, duration);
            }

            function setLoading(state, message = 'Loading products...') {
                loadingText.textContent = message;
                loading.classList.toggle('show', state);
            }

            async function pageRequest(data) {
                data.append('csrf_token', csrfToken);

                const response = await fetch(
                    window.location.pathname,
                    {
                        method: 'POST',
                        body: data,
                        credentials: 'same-origin',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    }
                );

                const result = await response
                    .json()
                    .catch(() => ({
                        success: false,
                        message: 'Invalid server response.'
                    }));

                if (!response.ok || !result.success) {
                    throw new Error(
                        result.message || 'Request failed.'
                    );
                }

                return result;
            }

            function isValidBarcode(barcode) {
                return /^[0-9]{1,14}$/.test(
                    String(barcode || '').trim()
                );
            }

            function imageHtml(product) {
                if (product.image_path) {
                    return (
                        '<img src="' +
                        escapeHtml(product.image_path) +
                        '" class="thumb" alt="">'
                    );
                }

                return (
                    '<div class="thumb-empty">' +
                    '<i class="fa-solid fa-gem"></i>' +
                    '</div>'
                );
            }

            function rowHtml(product, index) {
                const barcode =
                    String(product.barcode || '').trim();

                const printable =
                    isValidBarcode(barcode);

                const price =
                    Number(product.sale_rate || 0);

                return (
                    '<tr data-id="' +
                    Number(product.id) +
                    '">' +

                    '<td data-label="#">' +
                    (index + 1) +
                    '</td>' +

                    '<td class="main-cell" data-label="Product">' +
                    '<div class="d-flex align-items-center gap-2">' +
                    imageHtml(product) +
                    '<div>' +
                    '<div class="product-name">' +
                    escapeHtml(product.product_name) +
                    '</div>' +
                    '<div class="product-sub">' +
                    escapeHtml(product.product_code || '—') +
                    (Number(product.is_active) === 1
                        ? ''
                        : ' · Inactive') +
                    '</div>' +
                    '</div>' +
                    '</div>' +
                    '</td>' +

                    '<td data-label="Category / Metal">' +
                    '<div>' +
                    escapeHtml(product.category_name || '—') +
                    '</div>' +
                    '<div class="product-sub">' +
                    escapeHtml(product.metal_name || '—') +
                    '</div>' +
                    '</td>' +

                    '<td data-label="Barcode">' +
                    (
                        printable
                            ? '<span class="barcode-text">' +
                              escapeHtml(barcode) +
                              '</span>'
                            : '<span class="barcode-missing">' +
                              '<i class="fa-solid fa-triangle-exclamation me-1"></i>' +
                              'No printable barcode' +
                              '</span>'
                    ) +
                    '</td>' +

                    '<td class="text-end" data-label="Sale Rate">' +
                    '<span class="price-text">' +
                    escapeHtml(currencySymbol) +
                    price.toFixed(2) +
                    '</span>' +
                    '</td>' +

                    '<td class="text-center" data-label="Count">' +
                    '<input ' +
                    'type="number" ' +
                    'class="form-control qty-input" ' +
                    'value="0" ' +
                    'min="0" ' +
                    'max="500" ' +
                    'step="1" ' +
                    'inputmode="numeric" ' +
                    (printable ? '' : 'disabled ') +
                    '>' +
                    '</td>' +

                    '<td class="text-center" data-label="Status">' +
                    '<span class="row-status ' +
                    (printable ? 'ready' : 'waiting') +
                    '">' +
                    (printable ? 'Ready' : 'No Barcode') +
                    '</span>' +
                    '</td>' +

                    '</tr>'
                );
            }

            function render() {
                body.innerHTML = products
                    .map(rowHtml)
                    .join('');

                const hasProducts =
                    products.length > 0;

                table.classList.toggle(
                    'd-none',
                    !hasProducts
                );

                emptyState.classList.toggle(
                    'd-none',
                    hasProducts
                );

                updateSummary();
            }

            function getRowQuantity(row) {
                const input =
                    row.querySelector('.qty-input');

                if (!input || input.disabled) {
                    return 0;
                }

                let quantity =
                    parseInt(input.value || '0', 10);

                if (!Number.isFinite(quantity)) {
                    quantity = 0;
                }

                quantity =
                    Math.max(
                        0,
                        Math.min(500, quantity)
                    );

                input.value = String(quantity);

                return quantity;
            }

            function updateSummary() {
                const rows =
                    [...body.querySelectorAll('tr[data-id]')];

                let productCount = 0;
                let labelCount = 0;

                rows.forEach(row => {
                    const quantity =
                        getRowQuantity(row);

                    row.classList.toggle(
                        'has-qty',
                        quantity > 0
                    );

                    if (quantity > 0) {
                        productCount++;
                        labelCount += quantity;
                    }
                });

                enteredProducts.textContent =
                    productCount.toLocaleString();

                totalLabels.textContent =
                    labelCount.toLocaleString();

                printButton.disabled =
                    isPrinting || labelCount === 0;
            }

            async function loadProducts() {
                setLoading(true);

                const data = new FormData();
                data.append('action', 'list');
                data.append(
                    'search',
                    search.value.trim()
                );

                try {
                    const result =
                        await pageRequest(data);

                    products =
                        result.products || [];

                    totalProducts.textContent =
                        Number(result.total || 0)
                            .toLocaleString();

                    printableProducts.textContent =
                        Number(result.printable || 0)
                            .toLocaleString();

                    render();
                } catch (error) {
                    products = [];
                    render();

                    showToast(
                        'error',
                        error.message
                    );
                } finally {
                    setLoading(false);
                }
            }

            async function fetchLocal(
                path,
                options = {},
                timeoutMs = 3500
            ) {
                const controller =
                    new AbortController();

                const timer = setTimeout(
                    () => controller.abort(),
                    timeoutMs
                );

                try {
                    return await fetch(
                        LOCAL_API + path,
                        {
                            ...options,
                            cache: 'no-store',
                            signal: controller.signal
                        }
                    );
                } finally {
                    clearTimeout(timer);
                }
            }

            async function checkPrintManager(showError = false) {
                apiStatus.classList.remove(
                    'online',
                    'offline'
                );

                apiStatusText.textContent =
                    'Checking Print Manager...';

                try {
                    const response =
                        await fetchLocal(
                            '/health',
                            {
                                method: 'GET',
                                headers: {
                                    'Accept': 'application/json'
                                }
                            },
                            2500
                        );

                    const result =
                        await response.json();

                    if (!response.ok || !result.success) {
                        throw new Error(
                            result.message ||
                            'Print Manager unavailable.'
                        );
                    }

                    apiStatus.classList.add('online');

                    apiStatusText.textContent =
                        result.defaultPrinter
                            ? 'API Ready · ' +
                              result.defaultPrinter
                            : 'API Ready';

                    return true;
                } catch (error) {
                    apiStatus.classList.add('offline');
                    apiStatusText.textContent =
                        'Print Manager Offline';

                    if (showError) {
                        showToast(
                            'error',
                            'Local Barcode Print Manager is not running on port 17991.'
                        );
                    }

                    return false;
                }
            }

            function collectPrintItems() {
                const rows =
                    [...body.querySelectorAll('tr[data-id]')];

                const items = [];

                rows.forEach(row => {
                    const quantity =
                        getRowQuantity(row);

                    if (quantity <= 0) {
                        return;
                    }

                    const id =
                        Number(row.dataset.id);

                    const product =
                        products.find(
                            item =>
                                Number(item.id) === id
                        );

                    if (!product) {
                        return;
                    }

                    items.push({
                        row,
                        product,
                        quantity
                    });
                });

                return items;
            }

            function setRowStatus(
                row,
                type,
                text
            ) {
                const status =
                    row.querySelector('.row-status');

                if (!status) {
                    return;
                }

                status.className =
                    'row-status ' + type;

                status.textContent = text;
            }

            async function sendPrintJob(
                product,
                quantity
            ) {
                const barcode =
                    String(product.barcode || '')
                        .trim();

                if (!isValidBarcode(barcode)) {
                    throw new Error(
                        'Product does not have a valid numeric barcode.'
                    );
                }

                const price =
                    Number(product.sale_rate || 0);

                if (!Number.isFinite(price) || price <= 0) {
                    throw new Error(
                        'Sale rate must be greater than 0.'
                    );
                }

                const payload = {
                    shopName: businessName,
                    productCode:
                        String(
                            product.product_code || ''
                        ).trim(),
                    productName:
                        String(
                            product.product_name || ''
                        ).trim(),
                    price: price,
                    barcode: barcode,
                    quantity: quantity
                };

                const response =
                    await fetchLocal(
                        '/print',
                        {
                            method: 'POST',
                            headers: {
                                'Content-Type':
                                    'application/json',
                                'Accept':
                                    'application/json'
                            },
                            body:
                                JSON.stringify(payload)
                        },
                        15000
                    );

                const result =
                    await response
                        .json()
                        .catch(() => ({
                            success: false,
                            message:
                                'Invalid response from local Print Manager.'
                        }));

                if (!response.ok || !result.success) {
                    throw new Error(
                        result.message ||
                        'Unable to print barcode.'
                    );
                }

                return result;
            }

            async function printEnteredQuantities() {
                if (isPrinting) {
                    return;
                }

                const items =
                    collectPrintItems();

                if (!items.length) {
                    showToast(
                        'error',
                        'Enter a count greater than 0 for at least one product.'
                    );
                    return;
                }

                const managerReady =
                    await checkPrintManager(true);

                if (!managerReady) {
                    return;
                }

                isPrinting = true;
                printButton.disabled = true;
                clearButton.disabled = true;
                search.disabled = true;

                let successCount = 0;
                let successLabels = 0;
                const errors = [];

                try {
                    for (
                        let index = 0;
                        index < items.length;
                        index++
                    ) {
                        const item =
                            items[index];

                        setLoading(
                            true,
                            'Printing ' +
                            (index + 1) +
                            ' of ' +
                            items.length +
                            ' product(s)...'
                        );

                        setRowStatus(
                            item.row,
                            'printing',
                            'Printing...'
                        );

                        try {
                            await sendPrintJob(
                                item.product,
                                item.quantity
                            );

                            successCount++;
                            successLabels +=
                                item.quantity;

                            setRowStatus(
                                item.row,
                                'done',
                                'Sent'
                            );

                            const input =
                                item.row.querySelector(
                                    '.qty-input'
                                );

                            if (input) {
                                /*
                                 * After a successful print request,
                                 * reset this product count to zero so
                                 * accidental duplicate printing is avoided.
                                 */
                                input.value = '0';
                            }
                        } catch (error) {
                            setRowStatus(
                                item.row,
                                'error',
                                'Failed'
                            );

                            errors.push(
                                (item.product.product_name ||
                                    item.product.product_code ||
                                    'Product') +
                                ': ' +
                                error.message
                            );
                        }

                        updateSummary();
                    }
                } finally {
                    setLoading(false);
                    isPrinting = false;
                    clearButton.disabled = false;
                    search.disabled = false;
                    updateSummary();
                }

                if (successCount > 0) {
                    showToast(
                        'success',
                        successLabels +
                        ' label(s) from ' +
                        successCount +
                        ' product(s) sent to the local printer.',
                        4500
                    );
                }

                if (errors.length > 0) {
                    showToast(
                        'error',
                        errors.length +
                        ' product(s) failed. ' +
                        errors[0],
                        6000
                    );
                }

                checkPrintManager(false);
            }

            body.addEventListener(
                'input',
                event => {
                    if (
                        !event.target.classList
                            .contains('qty-input')
                    ) {
                        return;
                    }

                    let value =
                        String(event.target.value || '')
                            .replace(/[^\d]/g, '');

                    if (value === '') {
                        value = '0';
                    }

                    let quantity =
                        parseInt(value, 10);

                    if (!Number.isFinite(quantity)) {
                        quantity = 0;
                    }

                    quantity =
                        Math.max(
                            0,
                            Math.min(500, quantity)
                        );

                    event.target.value =
                        String(quantity);

                    const row =
                        event.target.closest(
                            'tr[data-id]'
                        );

                    if (row) {
                        setRowStatus(
                            row,
                            'ready',
                            quantity > 0
                                ? 'Queued'
                                : 'Ready'
                        );
                    }

                    updateSummary();
                }
            );

            body.addEventListener(
                'change',
                event => {
                    if (
                        event.target.classList
                            .contains('qty-input')
                    ) {
                        updateSummary();
                    }
                }
            );

            clearButton.addEventListener(
                'click',
                () => {
                    if (isPrinting) {
                        return;
                    }

                    body.querySelectorAll(
                        '.qty-input'
                    ).forEach(input => {
                        if (!input.disabled) {
                            input.value = '0';

                            const row =
                                input.closest(
                                    'tr[data-id]'
                                );

                            if (row) {
                                setRowStatus(
                                    row,
                                    'ready',
                                    'Ready'
                                );
                            }
                        }
                    });

                    updateSummary();
                    showToast(
                        'success',
                        'All product counts cleared.'
                    );
                }
            );

            printButton.addEventListener(
                'click',
                printEnteredQuantities
            );

            search.addEventListener(
                'input',
                () => {
                    clearTimeout(searchTimer);

                    searchTimer =
                        setTimeout(
                            loadProducts,
                            350
                        );
                }
            );

            /*
             * Start:
             * - Product count fields are always 0.
             * - No preview.
             * - No browser print dialog.
             * - No barcode settings.
             * - Print goes directly to the local API.
             */
            loadProducts();
            checkPrintManager(false);

            /*
             * Refresh local API health periodically.
             */
            setInterval(
                () => {
                    if (!isPrinting) {
                        checkPrintManager(false);
                    }
                },
                15000
            );
        })();
    </script>
    <?php endif; ?>
</body>

</html>