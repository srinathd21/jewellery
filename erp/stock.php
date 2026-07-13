<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));

$configCandidates = [
    __DIR__ . '/config/config.php',
    __DIR__ . '/config.php',
    __DIR__ . '/includes/config.php',
    __DIR__ . '/super-admin/includes/config.php',
];

$configLoaded = false;

foreach ($configCandidates as $configFile) {
    if (is_file($configFile)) {
        require_once $configFile;
        $configLoaded = true;
        break;
    }
}

if (!$configLoaded || !isset($conn) || !($conn instanceof mysqli)) {
    die('Database configuration is not available.');
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn->set_charset('utf8mb4');

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('tableExists')) {
    function tableExists(mysqli $conn, string $table): bool
    {
        $safe = $conn->real_escape_string($table);
        $result = $conn->query("SHOW TABLES LIKE '{$safe}'");
        return $result && $result->num_rows > 0;
    }
}

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$businessId = (int)($_SESSION['business_id'] ?? 0);

if ($businessId <= 0) {
    die('Business session not found. Please login again.');
}

$roleName = strtolower(trim((string)($_SESSION['role_name'] ?? '')));
$roleCode = strtolower(trim((string)($_SESSION['role_code'] ?? '')));
$userType = (string)($_SESSION['user_type'] ?? '');
$permissions = $_SESSION['permissions'] ?? [];

$allowed = (
    $userType === 'Platform Admin'
    || in_array($roleName, ['admin', 'business admin', 'manager', 'stock'], true)
    || in_array($roleCode, ['admin', 'business_admin', 'manager', 'stock'], true)
);

foreach (['perm.stock', 'perm.inventory'] as $permissionCode) {
    if (
        isset($permissions[$permissionCode]) &&
        (
            (int)($permissions[$permissionCode]['can_open'] ?? 0) === 1 ||
            (int)($permissions[$permissionCode]['can_view'] ?? 0) === 1
        )
    ) {
        $allowed = true;
        break;
    }
}

if (!$allowed) {
    http_response_code(403);
    die('Access denied.');
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

if (tableExists($conn, 'business_theme_settings')) {
    $stmt = $conn->prepare(
        'SELECT * FROM business_theme_settings WHERE business_id = ? LIMIT 1'
    );

    if ($stmt) {
        $stmt->bind_param('i', $businessId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();

        foreach ($theme as $key => $defaultValue) {
            if (isset($row[$key]) && $row[$key] !== '') {
                $theme[$key] = $row[$key];
            }
        }
    }
}

$pageTitle = 'Stock Overview';
$businessName = (string)($_SESSION['business_name'] ?? 'Jewellery ERP');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo h($businessName); ?> - Stock Overview</title>

    <?php include('includes/links.php'); ?>

    <style>
        :root {
            --primary: <?php echo h($theme['primary_color']); ?>;
            --primary-dark: <?php echo h($theme['primary_dark_color']); ?>;
            --primary-soft: <?php echo h($theme['primary_soft_color']); ?>;
            --sidebar-gradient-1: <?php echo h($theme['sidebar_gradient_1']); ?>;
            --sidebar-gradient-2: <?php echo h($theme['sidebar_gradient_2']); ?>;
            --sidebar-gradient-3: <?php echo h($theme['sidebar_gradient_3']); ?>;
            --page-bg: <?php echo h($theme['page_background']); ?>;
            --card-bg: <?php echo h($theme['card_background']); ?>;
            --text-color: <?php echo h($theme['text_color']); ?>;
            --muted-color: <?php echo h($theme['muted_text_color']); ?>;
            --border-color: <?php echo h($theme['border_color']); ?>;
            --radius: <?php echo (int)$theme['border_radius_px']; ?>px;
        }

        body {
            background: var(--page-bg);
            color: var(--text-color);
            font-family: <?php echo json_encode((string)$theme['font_family']); ?>, sans-serif;
        }

        .sidebar {
            background: linear-gradient(
                180deg,
                var(--sidebar-gradient-1),
                var(--sidebar-gradient-2),
                var(--sidebar-gradient-3)
            ) !important;
        }

        .page-head,
        .panel,
        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
        }

        .page-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 13px 15px;
            margin-bottom: 12px;
        }

        .page-title {
            margin: 0;
            font-family: <?php echo json_encode((string)$theme['heading_font_family']); ?>, serif;
            font-size: 19px;
            font-weight: 800;
        }

        .page-subtitle {
            margin-top: 2px;
            color: var(--muted-color);
            font-size: 10px;
        }

        .btn {
            border-radius: 9px;
            font-size: 11px;
            font-weight: 700;
        }

        .btn-primary {
            border-color: transparent;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
        }

        .btn-soft {
            border: 1px solid color-mix(in srgb, var(--primary) 25%, var(--border-color));
            background: var(--primary-soft);
            color: var(--primary-dark);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 10px;
            margin-bottom: 12px;
        }

        .stat-card {
            padding: 13px;
        }

        .stat-label {
            color: var(--muted-color);
            font-size: 9px;
            font-weight: 700;
            letter-spacing: .04em;
            text-transform: uppercase;
        }

        .stat-value {
            margin-top: 6px;
            font-family: <?php echo json_encode((string)$theme['heading_font_family']); ?>, serif;
            font-size: 22px;
            font-weight: 800;
        }

        .stat-note {
            margin-top: 3px;
            color: var(--muted-color);
            font-size: 9px;
        }

        .panel {
            margin-bottom: 12px;
            overflow: hidden;
        }

        .panel-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 12px 14px;
            border-bottom: 1px solid var(--border-color);
        }

        .panel-title {
            margin: 0;
            font-size: 14px;
            font-weight: 800;
        }

        .panel-body {
            padding: 14px;
        }

        .form-label {
            margin-bottom: 5px;
            font-size: 10px;
            font-weight: 700;
        }

        .form-control,
        .form-select {
            min-height: 38px;
            border-color: var(--border-color);
            border-radius: 9px;
            background: var(--card-bg);
            color: var(--text-color);
            font-size: 11px;
            box-shadow: none;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 .2rem color-mix(in srgb, var(--primary) 14%, transparent);
        }

        .table-wrap {
            overflow-x: auto;
        }

        .stock-table {
            min-width: 1350px;
            margin: 0;
            color: var(--text-color);
            font-size: 10px;
        }

        .stock-table th {
            padding: 9px 8px;
            border-color: var(--border-color);
            background: color-mix(in srgb, var(--muted-color) 6%, var(--card-bg));
            color: var(--muted-color);
            font-size: 9px;
            letter-spacing: .04em;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .stock-table td {
            padding: 9px 8px;
            border-color: var(--border-color);
            background: var(--card-bg) !important;
            vertical-align: middle;
        }

        .product-cell {
            display: flex;
            align-items: center;
            gap: 9px;
            min-width: 220px;
        }

        .product-image {
            width: 42px;
            height: 42px;
            border-radius: 9px;
            object-fit: cover;
            border: 1px solid var(--border-color);
            background: var(--page-bg);
        }

        .product-name {
            font-weight: 800;
        }

        .product-code {
            color: var(--muted-color);
            font-size: 9px;
        }

        .status-pill {
            display: inline-flex;
            padding: 5px 8px;
            border-radius: 999px;
            font-size: 9px;
            font-weight: 800;
        }

        .status-in { background: #eaf8f0; color: #168449; }
        .status-low { background: #fff4dc; color: #9a6200; }
        .status-out { background: #fdecec; color: #bd2d2d; }

        .pagination-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 12px 14px;
            border-top: 1px solid var(--border-color);
        }

        .pagination-info {
            color: var(--muted-color);
            font-size: 10px;
        }

        .loading-row {
            padding: 35px !important;
            color: var(--muted-color);
            text-align: center;
        }

        .toast-box {
            position: fixed;
            top: 75px;
            right: 18px;
            z-index: 20000;
            width: min(360px, calc(100vw - 24px));
        }

        .app-toast {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            margin-bottom: 8px;
            padding: 11px 13px;
            border-radius: 10px;
            color: #fff;
            font-size: 11px;
            font-weight: 700;
            box-shadow: 0 14px 35px rgba(0,0,0,.22);
        }

        .toast-success { background: #168449; }
        .toast-error { background: #c0392b; }
        .toast-info { background: #285a8e; }

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

        @media (max-width: 991.98px) {
            .stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 575.98px) {
            .content-wrap {
                padding-left: 10px;
                padding-right: 10px;
            }

            .page-head {
                align-items: flex-start;
                flex-direction: column;
            }

            .page-actions {
                display: grid !important;
                grid-template-columns: repeat(3, 1fr);
                width: 100%;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .pagination-bar {
                align-items: stretch;
                flex-direction: column;
            }
        }

        @media print {
            .sidebar,
            .app-nav,
            .page-actions,
            .filter-panel,
            .footer,
            .pagination-bar {
                display: none !important;
            }

            .app-main {
                margin-left: 0 !important;
            }

            .content-wrap {
                padding: 0 !important;
            }

            .page-head,
            .panel,
            .stat-card {
                border: 1px solid #ddd !important;
            }

            .table-wrap {
                overflow: visible !important;
            }

            .stock-table {
                min-width: 0 !important;
                font-size: 8px;
            }
        }
    </style>
</head>

<body>
<?php include('includes/sidebar.php'); ?>

<main class="app-main">
    <?php include('includes/nav.php'); ?>

    <div class="content-wrap">
        <div class="page-head">
            <div>
                <h1 class="page-title">Stock Overview</h1>
                <div class="page-subtitle">
                    Product-wise stock quantity, weight, value and availability.
                </div>
            </div>

            <div class="page-actions d-flex gap-2">
                <button type="button" class="btn btn-soft" onclick="window.print()">
                    <i class="fa-solid fa-print me-1"></i>Print
                </button>

                <a href="products.php" class="btn btn-light">
                    <i class="fa-solid fa-boxes-stacked me-1"></i>Products
                </a>

                <a href="product-add.php" class="btn btn-primary">
                    <i class="fa-solid fa-plus me-1"></i>Add Product
                </a>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Products</div>
                <div class="stat-value" id="totalProducts">0</div>
                <div class="stat-note">Matching current filters</div>
            </div>

            <div class="stat-card">
                <div class="stat-label">In Stock</div>
                <div class="stat-value" id="inStockCount">0</div>
                <div class="stat-note">Products with available quantity</div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Low Stock</div>
                <div class="stat-value" id="lowStockCount">0</div>
                <div class="stat-note">At or below minimum level</div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Out of Stock</div>
                <div class="stat-value" id="outOfStockCount">0</div>
                <div class="stat-note">No available quantity</div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Total Quantity</div>
                <div class="stat-value" id="totalStockQty">0.000</div>
                <div class="stat-note">Current stock quantity</div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Total Weight</div>
                <div class="stat-value" id="totalStockWeight">0.000</div>
                <div class="stat-note">Current net stock weight</div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Total Stock Value</div>
                <div class="stat-value" id="totalStockValue">₹0.00</div>
                <div class="stat-note">Current inventory valuation</div>
            </div>
        </div>

        <section class="panel filter-panel">
            <div class="panel-head">
                <h2 class="panel-title">Filters</h2>
            </div>

            <div class="panel-body">
                <form id="filterForm" class="row g-2 align-items-end">
                    <div class="col-lg-3 col-md-6">
                        <label class="form-label">Search</label>
                        <input
                            type="text"
                            class="form-control"
                            id="searchInput"
                            placeholder="Name, code, barcode or category"
                        >
                    </div>

                    <div class="col-lg-2 col-md-6">
                        <label class="form-label">Category</label>
                        <select class="form-select" id="categoryFilter">
                            <option value="">All Categories</option>
                        </select>
                    </div>

                    <div class="col-lg-2 col-md-6">
                        <label class="form-label">Stock Status</label>
                        <select class="form-select" id="stockStatus">
                            <option value="all">All</option>
                            <option value="in_stock">In Stock</option>
                            <option value="low_stock">Low Stock</option>
                            <option value="out_of_stock">Out of Stock</option>
                        </select>
                    </div>

                    <div class="col-lg-2 col-md-6">
                        <label class="form-label">Product Status</label>
                        <select class="form-select" id="productStatus">
                            <option value="all">All</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>

                    <div class="col-lg-3">
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary flex-fill">
                                <i class="fa-solid fa-magnifying-glass me-1"></i>Search
                            </button>

                            <button type="button" class="btn btn-light flex-fill" id="resetBtn">
                                Reset
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <h2 class="panel-title">Product Stock</h2>
                <select class="form-select" id="limitSelect" style="width:100px;min-height:34px">
                    <option value="25">25 rows</option>
                    <option value="50" selected>50 rows</option>
                    <option value="100">100 rows</option>
                    <option value="200">200 rows</option>
                </select>
            </div>

            <div class="table-wrap">
                <table class="table stock-table align-middle">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Product</th>
                            <th>Category</th>
                            <th>Barcode</th>
                            <th>Purity</th>
                            <th>Quantity</th>
                            <th>Gross Weight</th>
                            <th>Net Weight</th>
                            <th>Min Qty</th>
                            <th>Average Cost</th>
                            <th>Stock Value</th>
                            <th>Sale Rate</th>
                            <th>Status</th>
                            <th>Last Movement</th>
                            <th>Updated</th>
                        </tr>
                    </thead>

                    <tbody id="stockRows">
                        <tr>
                            <td colspan="15" class="loading-row">Loading stock overview...</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="pagination-bar">
                <div class="pagination-info" id="paginationInfo">
                    Showing 0 records
                </div>

                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-light" id="prevBtn">
                        Previous
                    </button>
                    <button type="button" class="btn btn-light" id="nextBtn">
                        Next
                    </button>
                </div>
            </div>
        </section>

        <?php include('includes/footer.php'); ?>
    </div>
</main>

<div class="toast-box" id="toastBox"></div>

<?php include('includes/script.php'); ?>
<script src="assets/js/script.js"></script>

<script>
(function () {
    'use strict';

    const apiUrl = 'api/stock-overview-list.php';
    const state = {
        page: 1,
        limit: 50,
        categoriesLoaded: false
    };

    const currency = <?php echo json_encode((string)($_SESSION['currency_symbol'] ?? '₹')); ?>;

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
    }

    function number(value, decimals = 2) {
        const parsed = parseFloat(value);
        return Number.isFinite(parsed) ? parsed.toFixed(decimals) : (0).toFixed(decimals);
    }

    function formatDate(value) {
        if (!value) {
            return '-';
        }

        const date = new Date(String(value).replace(' ', 'T'));

        if (Number.isNaN(date.getTime())) {
            return escapeHtml(value);
        }

        return date.toLocaleString('en-IN', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    function showToast(message, type = 'info') {
        const box = document.getElementById('toastBox');
        const toast = document.createElement('div');
        toast.className = 'app-toast toast-' + type;
        toast.innerHTML =
            '<i class="fa-solid fa-circle-info mt-1"></i>' +
            '<span>' + escapeHtml(message) + '</span>';

        box.appendChild(toast);

        setTimeout(function () {
            toast.remove();
        }, 3500);
    }

    function getFilters() {
        return {
            search: document.getElementById('searchInput').value.trim(),
            category_id: document.getElementById('categoryFilter').value,
            stock_status: document.getElementById('stockStatus').value,
            status: document.getElementById('productStatus').value,
            page: state.page,
            limit: state.limit
        };
    }

    function buildQuery(params) {
        const query = new URLSearchParams();

        Object.entries(params).forEach(function ([key, value]) {
            if (value !== '' && value !== null && value !== undefined) {
                query.set(key, value);
            }
        });

        return query.toString();
    }

    function setLoading() {
        document.getElementById('stockRows').innerHTML =
            '<tr><td colspan="15" class="loading-row">' +
            '<i class="fa-solid fa-spinner fa-spin me-2"></i>Loading stock overview...' +
            '</td></tr>';
    }

    function renderCategories(categories) {
        if (state.categoriesLoaded) {
            return;
        }

        const select = document.getElementById('categoryFilter');

        categories.forEach(function (category) {
            const option = document.createElement('option');
            option.value = category.id;
            option.textContent = category.category_name +
                (category.category_code ? ' (' + category.category_code + ')' : '');
            select.appendChild(option);
        });

        state.categoriesLoaded = true;
    }

    function renderSummary(summary) {
        document.getElementById('totalProducts').textContent = summary.total_products || 0;
        document.getElementById('inStockCount').textContent = summary.in_stock_count || 0;
        document.getElementById('lowStockCount').textContent = summary.low_stock_count || 0;
        document.getElementById('outOfStockCount').textContent = summary.out_of_stock_count || 0;
        document.getElementById('totalStockQty').textContent = number(summary.total_stock_qty, 3);
        document.getElementById('totalStockWeight').textContent = number(summary.total_stock_weight, 3);
        document.getElementById('totalStockValue').textContent =
            currency + number(summary.total_stock_value, 2);
    }

    function stockBadge(stockQty, minQty) {
        const qty = parseFloat(stockQty) || 0;
        const min = parseFloat(minQty) || 0;

        if (qty <= 0) {
            return '<span class="status-pill status-out">Out of Stock</span>';
        }

        if (min > 0 && qty <= min) {
            return '<span class="status-pill status-low">Low Stock</span>';
        }

        return '<span class="status-pill status-in">In Stock</span>';
    }

    function renderRows(rows, pagination) {
        const tbody = document.getElementById('stockRows');

        if (!rows.length) {
            tbody.innerHTML =
                '<tr><td colspan="15" class="loading-row">No stock records found.</td></tr>';
            return;
        }

        tbody.innerHTML = rows.map(function (row, index) {
            const serial = ((pagination.page - 1) * pagination.limit) + index + 1;
            const image = row.image_path
                ? '<img src="' + escapeHtml(row.image_path) + '" class="product-image" alt="Product">'
                : '<div class="product-image d-flex align-items-center justify-content-center">' +
                  '<i class="fa-solid fa-gem"></i></div>';

            return `
                <tr>
                    <td>${serial}</td>
                    <td>
                        <div class="product-cell">
                            ${image}
                            <div>
                                <div class="product-name">${escapeHtml(row.product_name)}</div>
                                <div class="product-code">${escapeHtml(row.product_code || '')}</div>
                                ${row.design_name
                                    ? '<div class="product-code">' + escapeHtml(row.design_name) + '</div>'
                                    : ''}
                            </div>
                        </div>
                    </td>
                    <td>${escapeHtml(row.category_name || '-')}</td>
                    <td>${escapeHtml(row.barcode || '-')}</td>
                    <td>${escapeHtml(row.purity || '-')}</td>
                    <td><strong>${number(row.stock_qty, 3)}</strong> ${escapeHtml(row.unit || '')}</td>
                    <td>${number(row.gross_weight, 3)}</td>
                    <td>${number(row.stock_weight, 3)}</td>
                    <td>${number(row.min_stock_qty, 3)}</td>
                    <td>${currency}${number(row.average_cost, 2)}</td>
                    <td><strong>${currency}${number(row.stock_value, 2)}</strong></td>
                    <td>${currency}${number(row.sale_rate, 2)}</td>
                    <td>${stockBadge(row.stock_qty, row.min_stock_qty)}</td>
                    <td>${formatDate(row.last_movement_date)}</td>
                    <td>${formatDate(row.stock_updated_at)}</td>
                </tr>
            `;
        }).join('');
    }

    function renderPagination(pagination) {
        const start = pagination.total === 0
            ? 0
            : ((pagination.page - 1) * pagination.limit) + 1;
        const end = Math.min(pagination.page * pagination.limit, pagination.total);

        document.getElementById('paginationInfo').textContent =
            'Showing ' + start + '–' + end + ' of ' + pagination.total + ' records';

        document.getElementById('prevBtn').disabled = pagination.page <= 1;
        document.getElementById('nextBtn').disabled = pagination.page >= pagination.pages;
    }

    async function loadStock() {
        setLoading();

        try {
            const response = await fetch(apiUrl + '?' + buildQuery(getFilters()), {
                headers: {
                    'Accept': 'application/json'
                }
            });

            const payload = await response.json();

            if (!response.ok || !payload.success) {
                throw new Error(payload.message || 'Unable to load stock overview.');
            }

            renderCategories(payload.data.categories || []);
            renderSummary(payload.data.summary || {});
            renderRows(payload.data.rows || [], payload.data.pagination);
            renderPagination(payload.data.pagination);
        } catch (error) {
            document.getElementById('stockRows').innerHTML =
                '<tr><td colspan="15" class="loading-row text-danger">' +
                escapeHtml(error.message) +
                '</td></tr>';

            showToast(error.message, 'error');
        }
    }

    document.getElementById('filterForm').addEventListener('submit', function (event) {
        event.preventDefault();
        state.page = 1;
        loadStock();
    });

    document.getElementById('resetBtn').addEventListener('click', function () {
        document.getElementById('filterForm').reset();
        state.page = 1;
        loadStock();
    });

    document.getElementById('limitSelect').addEventListener('change', function () {
        state.limit = parseInt(this.value, 10) || 50;
        state.page = 1;
        loadStock();
    });

    document.getElementById('prevBtn').addEventListener('click', function () {
        if (state.page > 1) {
            state.page--;
            loadStock();
        }
    });

    document.getElementById('nextBtn').addEventListener('click', function () {
        state.page++;
        loadStock();
    });

    loadStock();
})();
</script>
</body>
</html>
