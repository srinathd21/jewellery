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

$pageTitle = 'Stock Movements';
$businessName = (string)($_SESSION['business_name'] ?? 'Jewellery ERP');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo h($businessName); ?> - Stock Movements</title>

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
            grid-template-columns: repeat(5, minmax(0, 1fr));
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
            font-size: 21px;
            font-weight: 800;
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

        .movement-table {
            min-width: 1450px;
            margin: 0;
            color: var(--text-color);
            font-size: 10px;
        }

        .movement-table th {
            padding: 9px 8px;
            border-color: var(--border-color);
            background: color-mix(in srgb, var(--muted-color) 6%, var(--card-bg));
            color: var(--muted-color);
            font-size: 9px;
            letter-spacing: .04em;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .movement-table td {
            padding: 9px 8px;
            border-color: var(--border-color);
            background: var(--card-bg) !important;
            vertical-align: middle;
        }

        .product-name {
            font-weight: 800;
        }

        .product-code,
        .small-muted {
            color: var(--muted-color);
            font-size: 9px;
        }

        .movement-badge {
            display: inline-flex;
            padding: 5px 8px;
            border-radius: 999px;
            font-size: 9px;
            font-weight: 800;
        }

        .badge-in { background: #eaf8f0; color: #168449; }
        .badge-out { background: #fdecec; color: #bd2d2d; }
        .badge-adjust { background: #fff4dc; color: #9a6200; }
        .badge-neutral { background: #edf1f4; color: #56616d; }

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
            margin-bottom: 8px;
            padding: 11px 13px;
            border-radius: 10px;
            color: #fff;
            font-size: 11px;
            font-weight: 700;
            box-shadow: 0 14px 35px rgba(0,0,0,.22);
        }

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

        @media (max-width: 1199.98px) {
            .stats-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }

        @media (max-width: 767.98px) {
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
                grid-template-columns: repeat(2, 1fr);
                width: 100%;
            }

            .stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 575.98px) {
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

            .table-wrap {
                overflow: visible !important;
            }

            .movement-table {
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
                <h1 class="page-title">Stock Movements</h1>
                <div class="page-subtitle">
                    Review product stock inward, outward, adjustments and references.
                </div>
            </div>

            <div class="page-actions d-flex gap-2">
                <button type="button" class="btn btn-soft" onclick="window.print()">
                    <i class="fa-solid fa-print me-1"></i>Print
                </button>

                <a href="stock-overview.php" class="btn btn-primary">
                    <i class="fa-solid fa-boxes-stacked me-1"></i>Stock Overview
                </a>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Movements</div>
                <div class="stat-value" id="totalMovements">0</div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Quantity In</div>
                <div class="stat-value" id="totalQtyIn">0.000</div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Quantity Out</div>
                <div class="stat-value" id="totalQtyOut">0.000</div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Weight In</div>
                <div class="stat-value" id="totalWeightIn">0.000</div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Net Weight Change</div>
                <div class="stat-value" id="netWeightChange">0.000</div>
            </div>
        </div>

        <section class="panel filter-panel">
            <div class="panel-head">
                <h2 class="panel-title">Filters</h2>
            </div>

            <div class="panel-body">
                <form id="filterForm" class="row g-2 align-items-end">
                    <div class="col-xl-3 col-md-6">
                        <label class="form-label">Search</label>
                        <input
                            type="text"
                            class="form-control"
                            id="searchInput"
                            placeholder="Product, code, barcode, remarks"
                        >
                    </div>

                    <div class="col-xl-2 col-md-6">
                        <label class="form-label">Product</label>
                        <select class="form-select" id="productFilter">
                            <option value="">All Products</option>
                        </select>
                    </div>

                    <div class="col-xl-2 col-md-6">
                        <label class="form-label">Movement Type</label>
                        <select class="form-select" id="movementType">
                            <option value="">All Types</option>
                        </select>
                    </div>

                    <div class="col-xl-2 col-md-6">
                        <label class="form-label">Date From</label>
                        <input type="date" class="form-control" id="dateFrom">
                    </div>

                    <div class="col-xl-2 col-md-6">
                        <label class="form-label">Date To</label>
                        <input type="date" class="form-control" id="dateTo">
                    </div>

                    <div class="col-xl-1 col-md-6">
                        <button type="submit" class="btn btn-primary w-100">
                            Go
                        </button>
                    </div>

                    <div class="col-12">
                        <button type="button" class="btn btn-light" id="resetBtn">
                            Reset
                        </button>
                    </div>
                </form>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <h2 class="panel-title">Movement History</h2>

                <select class="form-select" id="limitSelect" style="width:100px;min-height:34px">
                    <option value="25">25 rows</option>
                    <option value="50" selected>50 rows</option>
                    <option value="100">100 rows</option>
                    <option value="200">200 rows</option>
                </select>
            </div>

            <div class="table-wrap">
                <table class="table movement-table align-middle">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Date & Time</th>
                            <th>Product</th>
                            <th>Type</th>
                            <th>Reference</th>
                            <th>Qty In</th>
                            <th>Qty Out</th>
                            <th>Weight In</th>
                            <th>Weight Out</th>
                            <th>Rate</th>
                            <th>Value</th>
                            <th>Remarks</th>
                            <th>Created By</th>
                        </tr>
                    </thead>

                    <tbody id="movementRows">
                        <tr>
                            <td colspan="13" class="loading-row">Loading stock movements...</td>
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

    const apiUrl = 'api/stock-movements-list.php';
    const state = {
        page: 1,
        limit: 50,
        optionsLoaded: false
    };

    const currency = <?php echo json_encode((string)($_SESSION['currency_symbol'] ?? '₹')); ?>;

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
    }

    function number(value, decimals = 3) {
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
        toast.textContent = message;
        box.appendChild(toast);

        setTimeout(function () {
            toast.remove();
        }, 3500);
    }

    function movementBadge(type) {
        const inward = ['Opening', 'Purchase', 'Sale Return', 'Old Silver Inward', 'Manual'];
        const outward = ['Sale', 'Purchase Return', 'Damage'];

        if (inward.includes(type)) {
            return '<span class="movement-badge badge-in">' + escapeHtml(type) + '</span>';
        }

        if (outward.includes(type)) {
            return '<span class="movement-badge badge-out">' + escapeHtml(type) + '</span>';
        }

        if (type === 'Adjustment') {
            return '<span class="movement-badge badge-adjust">Adjustment</span>';
        }

        return '<span class="movement-badge badge-neutral">' + escapeHtml(type || 'N/A') + '</span>';
    }

    function getFilters() {
        return {
            search: document.getElementById('searchInput').value.trim(),
            product_id: document.getElementById('productFilter').value,
            movement_type: document.getElementById('movementType').value,
            date_from: document.getElementById('dateFrom').value,
            date_to: document.getElementById('dateTo').value,
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

    function renderOptions(products, movementTypes) {
        if (state.optionsLoaded) {
            return;
        }

        const productSelect = document.getElementById('productFilter');

        products.forEach(function (product) {
            const option = document.createElement('option');
            option.value = product.id;
            option.textContent = product.product_name +
                (product.product_code ? ' - ' + product.product_code : '');
            productSelect.appendChild(option);
        });

        const movementSelect = document.getElementById('movementType');

        movementTypes.forEach(function (type) {
            const option = document.createElement('option');
            option.value = type;
            option.textContent = type;
            movementSelect.appendChild(option);
        });

        state.optionsLoaded = true;
    }

    function renderSummary(summary) {
        document.getElementById('totalMovements').textContent = summary.total_movements || 0;
        document.getElementById('totalQtyIn').textContent = number(summary.total_qty_in);
        document.getElementById('totalQtyOut').textContent = number(summary.total_qty_out);
        document.getElementById('totalWeightIn').textContent = number(summary.total_weight_in);
        document.getElementById('netWeightChange').textContent = number(summary.net_weight_change);
    }

    function renderRows(rows, pagination) {
        const tbody = document.getElementById('movementRows');

        if (!rows.length) {
            tbody.innerHTML =
                '<tr><td colspan="13" class="loading-row">No stock movements found.</td></tr>';
            return;
        }

        tbody.innerHTML = rows.map(function (row, index) {
            const serial = ((pagination.page - 1) * pagination.limit) + index + 1;
            const reference = row.reference_table || row.reference_id
                ? escapeHtml(row.reference_table || '') +
                  (row.reference_id ? ' #' + escapeHtml(row.reference_id) : '')
                : '-';

            return `
                <tr>
                    <td>${serial}</td>
                    <td>${formatDate(row.movement_date)}</td>
                    <td>
                        <div class="product-name">${escapeHtml(row.product_name || 'Unknown Product')}</div>
                        <div class="product-code">${escapeHtml(row.product_code || '')}</div>
                        ${row.barcode
                            ? '<div class="product-code">' + escapeHtml(row.barcode) + '</div>'
                            : ''}
                    </td>
                    <td>${movementBadge(row.movement_type)}</td>
                    <td>${reference}</td>
                    <td class="text-success">${number(row.quantity_in)} ${escapeHtml(row.unit || '')}</td>
                    <td class="text-danger">${number(row.quantity_out)} ${escapeHtml(row.unit || '')}</td>
                    <td class="text-success">${number(row.weight_in)}</td>
                    <td class="text-danger">${number(row.weight_out)}</td>
                    <td>${currency}${number(row.rate, 2)}</td>
                    <td>${currency}${number(row.value_amount, 2)}</td>
                    <td>${escapeHtml(row.remarks || '-')}</td>
                    <td>${escapeHtml(row.created_by_name || '-')}</td>
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

    async function loadMovements() {
        document.getElementById('movementRows').innerHTML =
            '<tr><td colspan="13" class="loading-row">' +
            '<i class="fa-solid fa-spinner fa-spin me-2"></i>Loading stock movements...' +
            '</td></tr>';

        try {
            const response = await fetch(apiUrl + '?' + buildQuery(getFilters()), {
                headers: {
                    'Accept': 'application/json'
                }
            });

            const payload = await response.json();

            if (!response.ok || !payload.success) {
                throw new Error(payload.message || 'Unable to load stock movements.');
            }

            renderOptions(payload.data.products || [], payload.data.movement_types || []);
            renderSummary(payload.data.summary || {});
            renderRows(payload.data.rows || [], payload.data.pagination);
            renderPagination(payload.data.pagination);
        } catch (error) {
            document.getElementById('movementRows').innerHTML =
                '<tr><td colspan="13" class="loading-row text-danger">' +
                escapeHtml(error.message) +
                '</td></tr>';

            showToast(error.message, 'error');
        }
    }

    document.getElementById('filterForm').addEventListener('submit', function (event) {
        event.preventDefault();
        state.page = 1;
        loadMovements();
    });

    document.getElementById('resetBtn').addEventListener('click', function () {
        document.getElementById('filterForm').reset();
        state.page = 1;
        loadMovements();
    });

    document.getElementById('limitSelect').addEventListener('change', function () {
        state.limit = parseInt(this.value, 10) || 50;
        state.page = 1;
        loadMovements();
    });

    document.getElementById('prevBtn').addEventListener('click', function () {
        if (state.page > 1) {
            state.page--;
            loadMovements();
        }
    });

    document.getElementById('nextBtn').addEventListener('click', function () {
        state.page++;
        loadMovements();
    });

    loadMovements();
})();
</script>
</body>
</html>
