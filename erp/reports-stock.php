<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));

foreach ([
    __DIR__ . '/config/config.php',
    __DIR__ . '/config.php',
    __DIR__ . '/includes/config.php',
    __DIR__ . '/super-admin/includes/config.php'
] as $file) {
    if (is_file($file)) {
        require_once $file;
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

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function money($value, string $currency = '₹'): string
{
    return $currency . ' ' . number_format((float)$value, 2);
}

function tableExists(mysqli $conn, string $table): bool
{
    $safe = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $result && $result->num_rows > 0;
}

function columnExists(mysqli $conn, string $table, string $column): bool
{
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    return $result && $result->num_rows > 0;
}

function fetchRows(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($conn->error);
    }

    if ($types !== '') {
        $bind = [$types];
        foreach ($params as $index => $value) {
            $bind[] =& $params[$index];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind);
    }

    if (!$stmt->execute()) {
        throw new RuntimeException($stmt->error);
    }

    $result = $stmt->get_result();
    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    $stmt->close();
    return $rows;
}

function fetchRow(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $rows = fetchRows($conn, $sql, $types, $params);
    return $rows[0] ?? [];
}

function reportPermission(): bool
{
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin') {
        return true;
    }

    $permissions = $_SESSION['permissions'] ?? [];

    foreach (['perm.reports.stock', 'perm.inventory.stock', 'perm.reports'] as $code) {
        if (isset($permissions[$code])) {
            $row = $permissions[$code];

            if (
                (int)($row['can_open'] ?? 0) === 1 ||
                (int)($row['can_view'] ?? 0) === 1
            ) {
                return true;
            }
        }
    }

    return true;
}

if (!reportPermission()) {
    http_response_code(403);
    die('Access denied. You do not have permission to view stock reports.');
}

foreach (['products', 'product_stock'] as $table) {
    if (!tableExists($conn, $table)) {
        die("Required table '{$table}' is missing.");
    }
}

$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? ($_SESSION['default_branch_id'] ?? 0));
$currency = (string)($_SESSION['currency_symbol'] ?? '₹');

if ($businessId <= 0 || $branchId <= 0) {
    die('A valid business and branch must be selected.');
}

$categoryId = (int)($_GET['category_id'] ?? 0);
$metalId = (int)($_GET['metal_id'] ?? 0);
$stockStatus = trim((string)($_GET['stock_status'] ?? ''));
$search = trim((string)($_GET['search'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? date('Y-m-01')));
$dateTo = trim((string)($_GET['date_to'] ?? date('Y-m-d')));
$export = trim((string)($_GET['export'] ?? ''));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = date('Y-m-d');
}

$categories = tableExists($conn, 'product_categories')
    ? fetchRows(
        $conn,
        "SELECT id, category_name
         FROM product_categories
         WHERE business_id=? AND is_active=1
         ORDER BY category_name",
        'i',
        [$businessId]
    )
    : [];

$metals = tableExists($conn, 'metals')
    ? fetchRows(
        $conn,
        "SELECT id, metal_code, metal_name
         FROM metals
         WHERE business_id=? AND is_active=1
         ORDER BY metal_name",
        'i',
        [$businessId]
    )
    : [];

$where = " WHERE p.business_id=? AND p.is_active=1 ";
$types = 'i';
$params = [$businessId];

if ($categoryId > 0) {
    $where .= " AND p.category_id=? ";
    $types .= 'i';
    $params[] = $categoryId;
}

if ($metalId > 0) {
    $where .= " AND p.metal_id=? ";
    $types .= 'i';
    $params[] = $metalId;
}

if ($search !== '') {
    $like = '%' . $search . '%';
    $where .= " AND (
        p.product_name LIKE ?
        OR p.product_code LIKE ?
        OR p.barcode LIKE ?
        OR p.hsn_code LIKE ?
    ) ";
    $types .= 'ssss';
    array_push($params, $like, $like, $like, $like);
}

if ($stockStatus === 'available') {
    $where .= " AND COALESCE(ps.quantity,0) > 0 ";
} elseif ($stockStatus === 'zero') {
    $where .= " AND COALESCE(ps.quantity,0) <= 0 ";
} elseif ($stockStatus === 'low') {
    $where .= " AND COALESCE(ps.quantity,0) <= p.minimum_stock_qty ";
}

$stockRows = fetchRows(
    $conn,
    "
    SELECT
        p.id,
        p.product_code,
        p.barcode,
        p.product_name,
        p.hsn_code,
        p.purity,
        p.gross_weight AS product_gross_weight,
        p.net_weight AS product_net_weight,
        p.minimum_stock_qty,
        p.purchase_rate,
        p.sale_rate,
        p.tax_percent,
        p.track_stock,
        pc.category_name,
        m.metal_name,
        m.metal_code,
        COALESCE(ps.quantity,0) AS quantity,
        COALESCE(ps.gross_weight,0) AS gross_weight,
        COALESCE(ps.net_weight,0) AS net_weight,
        COALESCE(ps.average_cost,0) AS average_cost,
        COALESCE(ps.stock_value,0) AS stock_value,
        ps.updated_at
    FROM products p
    LEFT JOIN product_stock ps
      ON ps.product_id=p.id
     AND ps.business_id=p.business_id
     AND ps.branch_id=?
    LEFT JOIN product_categories pc
      ON pc.id=p.category_id
     AND pc.business_id=p.business_id
    LEFT JOIN metals m
      ON m.id=p.metal_id
     AND m.business_id=p.business_id
    {$where}
    ORDER BY
        CASE WHEN COALESCE(ps.quantity,0) <= p.minimum_stock_qty THEN 0 ELSE 1 END,
        p.product_name
    ",
    'i' . $types,
    array_merge([$branchId], $params)
);

$summary = [
    'total_products' => count($stockRows),
    'available_products' => 0,
    'low_stock_products' => 0,
    'zero_stock_products' => 0,
    'quantity' => 0,
    'gross_weight' => 0,
    'net_weight' => 0,
    'stock_value' => 0
];

foreach ($stockRows as $row) {
    $qty = (float)$row['quantity'];
    $minimum = (float)$row['minimum_stock_qty'];

    if ($qty > 0) {
        $summary['available_products']++;
    }
    if ($qty <= $minimum) {
        $summary['low_stock_products']++;
    }
    if ($qty <= 0) {
        $summary['zero_stock_products']++;
    }

    $summary['quantity'] += $qty;
    $summary['gross_weight'] += (float)$row['gross_weight'];
    $summary['net_weight'] += (float)$row['net_weight'];
    $summary['stock_value'] += (float)$row['stock_value'];
}

$metalSummary = fetchRows(
    $conn,
    "
    SELECT
        COALESCE(m.metal_name,'Unspecified') AS metal_name,
        COALESCE(m.metal_code,'OTHER') AS metal_code,
        COUNT(DISTINCT p.id) AS product_count,
        COALESCE(SUM(ps.quantity),0) AS quantity,
        COALESCE(SUM(ps.gross_weight),0) AS gross_weight,
        COALESCE(SUM(ps.net_weight),0) AS net_weight,
        COALESCE(SUM(ps.stock_value),0) AS stock_value
    FROM products p
    LEFT JOIN product_stock ps
      ON ps.product_id=p.id
     AND ps.business_id=p.business_id
     AND ps.branch_id=?
    LEFT JOIN metals m
      ON m.id=p.metal_id
     AND m.business_id=p.business_id
    WHERE p.business_id=? AND p.is_active=1
    GROUP BY m.id,m.metal_name,m.metal_code
    ORDER BY stock_value DESC, metal_name
    ",
    'ii',
    [$branchId, $businessId]
);

$movementRows = [];
if (tableExists($conn, 'stock_movements')) {
    $referenceTableColumn = columnExists($conn, 'stock_movements', 'reference_table')
        ? 'sm.reference_table'
        : (columnExists($conn, 'stock_movements', 'ref_table') ? 'sm.ref_table' : "''");

    $referenceIdColumn = columnExists($conn, 'stock_movements', 'reference_id')
        ? 'sm.reference_id'
        : (columnExists($conn, 'stock_movements', 'ref_id') ? 'sm.ref_id' : 'NULL');

    $quantityInColumn = columnExists($conn, 'stock_movements', 'quantity_in')
        ? 'sm.quantity_in'
        : (columnExists($conn, 'stock_movements', 'qty_in') ? 'sm.qty_in' : '0');

    $quantityOutColumn = columnExists($conn, 'stock_movements', 'quantity_out')
        ? 'sm.quantity_out'
        : (columnExists($conn, 'stock_movements', 'qty_out') ? 'sm.qty_out' : '0');

    $weightInColumn = columnExists($conn, 'stock_movements', 'weight_in')
        ? 'sm.weight_in'
        : '0';

    $weightOutColumn = columnExists($conn, 'stock_movements', 'weight_out')
        ? 'sm.weight_out'
        : '0';

    $valueColumn = columnExists($conn, 'stock_movements', 'value_amount')
        ? 'sm.value_amount'
        : '0';

    $movementWhere = "
        WHERE sm.business_id=?
          AND sm.branch_id=?
          AND DATE(sm.movement_date) BETWEEN ? AND ?
    ";

    $movementTypes = 'iiss';
    $movementParams = [$businessId, $branchId, $dateFrom, $dateTo];

    if ($search !== '') {
        $like = '%' . $search . '%';
        $movementWhere .= " AND (
            p.product_name LIKE ?
            OR p.product_code LIKE ?
            OR sm.movement_type LIKE ?
        ) ";
        $movementTypes .= 'sss';
        array_push($movementParams, $like, $like, $like);
    }

    $movementRows = fetchRows(
        $conn,
        "
        SELECT
            sm.id,
            sm.movement_date,
            sm.movement_type,
            {$referenceTableColumn} AS reference_table,
            {$referenceIdColumn} AS reference_id,
            {$quantityInColumn} AS quantity_in,
            {$quantityOutColumn} AS quantity_out,
            {$weightInColumn} AS weight_in,
            {$weightOutColumn} AS weight_out,
            sm.rate,
            {$valueColumn} AS value_amount,
            sm.remarks,
            p.product_code,
            p.product_name
        FROM stock_movements sm
        INNER JOIN products p
          ON p.id=sm.product_id
         AND p.business_id=sm.business_id
        {$movementWhere}
        ORDER BY sm.movement_date DESC, sm.id DESC
        LIMIT 500
        ",
        $movementTypes,
        $movementParams
    );
}

if ($export === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="stock-report-' . date('Ymd-His') . '.xls"');

    echo '<html><head><meta charset="utf-8"></head><body>';
    echo '<h2>Stock Report</h2>';
    echo '<table border="1">';
    echo '<tr><th>Product Code</th><th>Product</th><th>Category</th><th>Metal</th><th>Purity</th><th>Quantity</th><th>Gross Weight</th><th>Net Weight</th><th>Average Cost</th><th>Stock Value</th><th>Minimum Stock</th><th>Status</th></tr>';

    foreach ($stockRows as $row) {
        $qty = (float)$row['quantity'];
        $minimum = (float)$row['minimum_stock_qty'];

        if ($qty <= 0) {
            $status = 'Out of Stock';
        } elseif ($qty <= $minimum) {
            $status = 'Low Stock';
        } else {
            $status = 'Available';
        }

        echo '<tr>';
        echo '<td>' . e($row['product_code']) . '</td>';
        echo '<td>' . e($row['product_name']) . '</td>';
        echo '<td>' . e($row['category_name'] ?: '—') . '</td>';
        echo '<td>' . e($row['metal_name'] ?: '—') . '</td>';
        echo '<td>' . e($row['purity'] ?: '—') . '</td>';
        echo '<td>' . number_format($qty, 3, '.', '') . '</td>';
        echo '<td>' . number_format((float)$row['gross_weight'], 3, '.', '') . '</td>';
        echo '<td>' . number_format((float)$row['net_weight'], 3, '.', '') . '</td>';
        echo '<td>' . number_format((float)$row['average_cost'], 2, '.', '') . '</td>';
        echo '<td>' . number_format((float)$row['stock_value'], 2, '.', '') . '</td>';
        echo '<td>' . number_format($minimum, 3, '.', '') . '</td>';
        echo '<td>' . e($status) . '</td>';
        echo '</tr>';
    }

    echo '</table></body></html>';
    exit;
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
];

if (tableExists($conn, 'business_theme_settings')) {
    $row = fetchRow(
        $conn,
        'SELECT * FROM business_theme_settings WHERE business_id=? LIMIT 1',
        'i',
        [$businessId]
    );

    foreach ($theme as $key => $value) {
        if (isset($row[$key]) && $row[$key] !== '') {
            $theme[$key] = $row[$key];
        }
    }
}

$businessName = (string)($_SESSION['business_name'] ?? 'Jewellery ERP');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($businessName) ?> - Stock Report</title>
    <?php include('includes/links.php'); ?>

    <style>
        :root{
            --primary:<?= e($theme['primary_color']) ?>;
            --primary-dark:<?= e($theme['primary_dark_color']) ?>;
            --primary-soft:<?= e($theme['primary_soft_color']) ?>;
            --page-bg:<?= e($theme['page_background']) ?>;
            --card-bg:<?= e($theme['card_background']) ?>;
            --text:<?= e($theme['text_color']) ?>;
            --muted:<?= e($theme['muted_text_color']) ?>;
            --line:<?= e($theme['border_color']) ?>;
            --radius:<?= (int)$theme['border_radius_px'] ?>px;
        }

        body{
            background:var(--page-bg);
            color:var(--text);
            font-family:<?= json_encode($theme['font_family']) ?>,sans-serif;
        }

        .sidebar{
            background:linear-gradient(
                180deg,
                <?= e($theme['sidebar_gradient_1']) ?>,
                <?= e($theme['sidebar_gradient_2']) ?>,
                <?= e($theme['sidebar_gradient_3']) ?>
            )!important;
        }

        .report-card,.summary-card{
            background:var(--card-bg);
            border:1px solid var(--line);
            border-radius:var(--radius);
        }

        .report-head{
            padding:14px 16px;
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
        }

        .report-title{
            margin:0;
            font:700 21px <?= json_encode($theme['heading_font_family']) ?>,serif;
        }

        .report-subtitle{
            margin-top:3px;
            color:var(--muted);
            font-size:10px;
        }

        .action-group{
            display:flex;
            flex-wrap:wrap;
            gap:7px;
        }

        .btn-theme,.btn-soft{
            min-height:37px;
            border-radius:9px;
            padding:8px 12px;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:6px;
            font-size:10px;
            font-weight:800;
            text-decoration:none;
        }

        .btn-theme{
            border:0;
            color:#fff;
            background:linear-gradient(135deg,var(--primary),var(--primary-dark));
        }

        .btn-soft{
            border:1px solid var(--line);
            background:var(--card-bg);
            color:var(--text);
        }

        .filter-card{
            padding:12px;
            margin-bottom:12px;
        }

        .filter-grid{
            display:grid;
            grid-template-columns:repeat(6,minmax(0,1fr));
            gap:8px;
        }

        .form-control,.form-select{
            min-height:38px;
            border:1px solid var(--line);
            border-radius:9px;
            background:var(--card-bg);
            color:var(--text);
            font-size:10px;
        }

        .summary-grid{
            display:grid;
            grid-template-columns:repeat(6,minmax(0,1fr));
            gap:10px;
            margin-bottom:12px;
        }

        .summary-card{
            padding:13px;
            display:flex;
            align-items:center;
            gap:10px;
        }

        .summary-icon{
            width:40px;
            height:40px;
            border-radius:10px;
            display:grid;
            place-items:center;
            background:var(--primary-soft);
            color:var(--primary-dark);
            flex:0 0 auto;
        }

        .summary-label{
            color:var(--muted);
            font-size:8px;
            text-transform:uppercase;
        }

        .summary-value{
            margin-top:2px;
            font-size:17px;
            font-weight:900;
        }

        .nav-tabs{
            border-bottom:1px solid var(--line);
            padding:0 12px;
        }

        .nav-tabs .nav-link{
            border:0;
            color:var(--muted);
            font-size:10px;
            font-weight:800;
            padding:11px 13px;
        }

        .nav-tabs .nav-link.active{
            color:var(--primary-dark);
            border-bottom:2px solid var(--primary);
            background:transparent;
        }

        .table{
            margin:0;
            font-size:9px;
        }

        .table th{
            color:var(--muted);
            background:color-mix(in srgb,var(--muted) 6%,transparent);
            font-size:8px;
            text-transform:uppercase;
            white-space:nowrap;
        }

        .table th,.table td{
            padding:9px 10px;
            border-color:var(--line);
            vertical-align:middle;
        }

        .product-name{
            font-weight:900;
            font-size:10px;
        }

        .subtext{
            margin-top:2px;
            color:var(--muted);
            font-size:8px;
        }

        .text-number{
            text-align:right;
            white-space:nowrap;
            font-weight:800;
        }

        .badge-soft{
            display:inline-flex;
            border-radius:999px;
            padding:4px 7px;
            font-size:8px;
            font-weight:800;
        }

        .badge-available{background:#eaf8f0;color:#168449}
        .badge-low{background:#fff4dc;color:#946000}
        .badge-zero{background:#fdecec;color:#bd2d2d}
        .badge-in{background:#eaf8f0;color:#168449}
        .badge-out{background:#fdecec;color:#bd2d2d}
        .badge-neutral{background:#eef2f7;color:#536170}

        .metal-grid{
            display:grid;
            grid-template-columns:repeat(4,minmax(0,1fr));
            gap:10px;
            padding:12px;
        }

        .metal-card{
            border:1px solid var(--line);
            border-radius:10px;
            padding:12px;
        }

        .metal-name{
            font-size:11px;
            font-weight:900;
        }

        .metal-code{
            color:var(--muted);
            font-size:8px;
        }

        .metal-value{
            margin-top:8px;
            font-size:15px;
            font-weight:900;
            color:var(--primary-dark);
        }

        .metal-detail{
            display:flex;
            justify-content:space-between;
            gap:8px;
            padding-top:6px;
            margin-top:6px;
            border-top:1px dashed var(--line);
            color:var(--muted);
            font-size:8px;
        }

        .empty-state{
            padding:34px 15px;
            text-align:center;
            color:var(--muted);
        }

        .print-header{
            display:none;
        }

        body.dark-mode,body[data-theme=dark]{
            --page-bg:#0f151b;
            --card-bg:#182129;
            --text:#f3f6f8;
            --muted:#9aa7b3;
            --line:#2c3944;
        }

        @media(max-width:1199px){
            .summary-grid{grid-template-columns:repeat(3,minmax(0,1fr))}
            .filter-grid{grid-template-columns:repeat(3,minmax(0,1fr))}
            .metal-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
        }

        @media(max-width:767px){
            .report-head{align-items:flex-start;flex-direction:column}
            .summary-grid,.filter-grid,.metal-grid{grid-template-columns:1fr}
        }

        @media print{
            .sidebar,.app-nav,.filter-card,.action-group,.nav-tabs,.footer,
            .content-wrap > .report-card:first-of-type{
                display:none!important;
            }

            .app-main{margin:0!important}
            .content-wrap{padding:0!important}
            body{background:#fff!important;color:#000!important}
            .print-header{display:block;text-align:center;margin-bottom:15px}
            .report-card,.summary-card,.metal-card{
                box-shadow:none!important;
                border:1px solid #bbb!important;
            }
            .tab-pane{display:block!important;opacity:1!important}
            .tab-pane:not(:first-child){page-break-before:always}
            .table{font-size:8px}
        }
    </style>
</head>

<body>
<?php include('includes/sidebar.php'); ?>

<main class="app-main">
    <?php include('includes/nav.php'); ?>

    <div class="content-wrap">
        <div class="print-header">
            <h2><?= e($businessName) ?></h2>
            <h3>Stock Report</h3>
            <div>Printed on <?= e(date('d-m-Y h:i A')) ?></div>
        </div>

        <section class="report-card mb-3">
            <div class="report-head">
                <div>
                    <h1 class="report-title">Stock Report</h1>
                    <div class="report-subtitle">
                        Current quantity, weight, valuation, low stock and movement report.
                    </div>
                </div>

                <div class="action-group">
                    <a href="products.php" class="btn-soft">
                        <i class="fa-solid fa-box"></i>Products
                    </a>
                    <a href="stock-overview.php" class="btn-soft">
                        <i class="fa-solid fa-warehouse"></i>Stock Overview
                    </a>
                    <a href="stock-movements.php" class="btn-soft">
                        <i class="fa-solid fa-right-left"></i>Movements
                    </a>
                    <button type="button" class="btn-soft" onclick="window.print()">
                        <i class="fa-solid fa-print"></i>Print
                    </button>
                    <a href="?<?= e(http_build_query(array_merge($_GET, ['export'=>'excel']))) ?>"
                       class="btn-theme">
                        <i class="fa-solid fa-file-excel"></i>Excel
                    </a>
                </div>
            </div>
        </section>

        <form method="get" class="report-card filter-card">
            <div class="filter-grid">
                <select name="category_id" class="form-select">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= (int)$category['id'] ?>"
                            <?= $categoryId === (int)$category['id'] ? 'selected' : '' ?>>
                            <?= e($category['category_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="metal_id" class="form-select">
                    <option value="">All Metals</option>
                    <?php foreach ($metals as $metal): ?>
                        <option value="<?= (int)$metal['id'] ?>"
                            <?= $metalId === (int)$metal['id'] ? 'selected' : '' ?>>
                            <?= e($metal['metal_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="stock_status" class="form-select">
                    <option value="">All Stock Status</option>
                    <option value="available" <?= $stockStatus === 'available' ? 'selected' : '' ?>>Available</option>
                    <option value="low" <?= $stockStatus === 'low' ? 'selected' : '' ?>>Low Stock</option>
                    <option value="zero" <?= $stockStatus === 'zero' ? 'selected' : '' ?>>Out of Stock</option>
                </select>

                <input type="search" name="search" class="form-control"
                       value="<?= e($search) ?>"
                       placeholder="Product, code, barcode, HSN...">

                <button type="submit" class="btn-theme">
                    <i class="fa-solid fa-filter"></i>Apply Filter
                </button>

                <a href="reports-stock.php" class="btn-soft">
                    <i class="fa-solid fa-rotate-left"></i>Reset
                </a>
            </div>
        </form>

        <section class="summary-grid">
            <div class="summary-card">
                <div class="summary-icon"><i class="fa-solid fa-boxes-stacked"></i></div>
                <div>
                    <div class="summary-label">Products</div>
                    <div class="summary-value"><?= (int)$summary['total_products'] ?></div>
                </div>
            </div>

            <div class="summary-card">
                <div class="summary-icon"><i class="fa-solid fa-cubes"></i></div>
                <div>
                    <div class="summary-label">Total Quantity</div>
                    <div class="summary-value"><?= number_format((float)$summary['quantity'], 3) ?></div>
                </div>
            </div>

            <div class="summary-card">
                <div class="summary-icon"><i class="fa-solid fa-weight-scale"></i></div>
                <div>
                    <div class="summary-label">Gross Weight</div>
                    <div class="summary-value"><?= number_format((float)$summary['gross_weight'], 3) ?> g</div>
                </div>
            </div>

            <div class="summary-card">
                <div class="summary-icon"><i class="fa-solid fa-scale-balanced"></i></div>
                <div>
                    <div class="summary-label">Net Weight</div>
                    <div class="summary-value"><?= number_format((float)$summary['net_weight'], 3) ?> g</div>
                </div>
            </div>

            <div class="summary-card">
                <div class="summary-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
                <div>
                    <div class="summary-label">Low Stock</div>
                    <div class="summary-value"><?= (int)$summary['low_stock_products'] ?></div>
                </div>
            </div>

            <div class="summary-card">
                <div class="summary-icon"><i class="fa-solid fa-indian-rupee-sign"></i></div>
                <div>
                    <div class="summary-label">Stock Value</div>
                    <div class="summary-value"><?= e(money($summary['stock_value'], $currency)) ?></div>
                </div>
            </div>
        </section>

        <section class="report-card">
            <ul class="nav nav-tabs" role="tablist">
                <li class="nav-item">
                    <button class="nav-link active" data-bs-toggle="tab"
                            data-bs-target="#currentStock" type="button">
                        Current Stock
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" data-bs-toggle="tab"
                            data-bs-target="#metalSummary" type="button">
                        Metal Summary
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" data-bs-toggle="tab"
                            data-bs-target="#movements" type="button">
                        Movement History
                    </button>
                </li>
            </ul>

            <div class="tab-content">
                <div class="tab-pane fade show active" id="currentStock">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                            <tr>
                                <th>Product</th>
                                <th>Category</th>
                                <th>Metal</th>
                                <th>Purity</th>
                                <th class="text-end">Qty</th>
                                <th class="text-end">Gross Wt</th>
                                <th class="text-end">Net Wt</th>
                                <th class="text-end">Avg Cost</th>
                                <th class="text-end">Stock Value</th>
                                <th class="text-end">Minimum</th>
                                <th>Status</th>
                                <th>Updated</th>
                            </tr>
                            </thead>

                            <tbody>
                            <?php if (!$stockRows): ?>
                                <tr>
                                    <td colspan="12">
                                        <div class="empty-state">No stock records found for the selected filters.</div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($stockRows as $row):
                                    $qty = (float)$row['quantity'];
                                    $minimum = (float)$row['minimum_stock_qty'];

                                    if ($qty <= 0) {
                                        $statusText = 'Out of Stock';
                                        $statusClass = 'badge-zero';
                                    } elseif ($qty <= $minimum) {
                                        $statusText = 'Low Stock';
                                        $statusClass = 'badge-low';
                                    } else {
                                        $statusText = 'Available';
                                        $statusClass = 'badge-available';
                                    }
                                ?>
                                    <tr>
                                        <td>
                                            <div class="product-name"><?= e($row['product_name']) ?></div>
                                            <div class="subtext">
                                                <?= e($row['product_code']) ?>
                                                <?= $row['barcode'] ? ' · ' . e($row['barcode']) : '' ?>
                                            </div>
                                        </td>
                                        <td><?= e($row['category_name'] ?: '—') ?></td>
                                        <td>
                                            <?= e($row['metal_name'] ?: '—') ?>
                                            <div class="subtext"><?= e($row['metal_code'] ?: '') ?></div>
                                        </td>
                                        <td><?= $row['purity'] !== null ? e(number_format((float)$row['purity'], 4)) : '—' ?></td>
                                        <td class="text-number"><?= number_format($qty, 3) ?></td>
                                        <td class="text-number"><?= number_format((float)$row['gross_weight'], 3) ?> g</td>
                                        <td class="text-number"><?= number_format((float)$row['net_weight'], 3) ?> g</td>
                                        <td class="text-number"><?= e(money($row['average_cost'], $currency)) ?></td>
                                        <td class="text-number"><?= e(money($row['stock_value'], $currency)) ?></td>
                                        <td class="text-number"><?= number_format($minimum, 3) ?></td>
                                        <td>
                                            <span class="badge-soft <?= $statusClass ?>">
                                                <?= e($statusText) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?= $row['updated_at'] ? e(date('d-m-Y h:i A', strtotime($row['updated_at']))) : '—' ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="tab-pane fade" id="metalSummary">
                    <?php if (!$metalSummary): ?>
                        <div class="empty-state">No metal stock summary found.</div>
                    <?php else: ?>
                        <div class="metal-grid">
                            <?php foreach ($metalSummary as $row): ?>
                                <div class="metal-card">
                                    <div class="metal-name"><?= e($row['metal_name']) ?></div>
                                    <div class="metal-code"><?= e($row['metal_code']) ?></div>
                                    <div class="metal-value"><?= e(money($row['stock_value'], $currency)) ?></div>

                                    <div class="metal-detail">
                                        <span>Products</span>
                                        <strong><?= (int)$row['product_count'] ?></strong>
                                    </div>
                                    <div class="metal-detail">
                                        <span>Quantity</span>
                                        <strong><?= number_format((float)$row['quantity'], 3) ?></strong>
                                    </div>
                                    <div class="metal-detail">
                                        <span>Gross Weight</span>
                                        <strong><?= number_format((float)$row['gross_weight'], 3) ?> g</strong>
                                    </div>
                                    <div class="metal-detail">
                                        <span>Net Weight</span>
                                        <strong><?= number_format((float)$row['net_weight'], 3) ?> g</strong>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="tab-pane fade" id="movements">
                    <div class="p-2 border-bottom" style="border-color:var(--line)!important">
                        <form method="get" class="d-flex flex-wrap gap-2">
                            <input type="hidden" name="category_id" value="<?= $categoryId ?>">
                            <input type="hidden" name="metal_id" value="<?= $metalId ?>">
                            <input type="hidden" name="stock_status" value="<?= e($stockStatus) ?>">
                            <input type="hidden" name="search" value="<?= e($search) ?>">

                            <input type="date" name="date_from" class="form-control"
                                   style="max-width:170px" value="<?= e($dateFrom) ?>">
                            <input type="date" name="date_to" class="form-control"
                                   style="max-width:170px" value="<?= e($dateTo) ?>">

                            <button type="submit" class="btn-theme">
                                <i class="fa-solid fa-calendar-days"></i>Load Movements
                            </button>
                        </form>
                    </div>

                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                            <tr>
                                <th>Date</th>
                                <th>Product</th>
                                <th>Movement</th>
                                <th>Reference</th>
                                <th class="text-end">Qty In</th>
                                <th class="text-end">Qty Out</th>
                                <th class="text-end">Weight In</th>
                                <th class="text-end">Weight Out</th>
                                <th class="text-end">Rate</th>
                                <th class="text-end">Value</th>
                                <th>Remarks</th>
                            </tr>
                            </thead>

                            <tbody>
                            <?php if (!$movementRows): ?>
                                <tr>
                                    <td colspan="11">
                                        <div class="empty-state">No stock movements found for the selected period.</div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($movementRows as $row):
                                    $movementType = strtolower((string)$row['movement_type']);
                                    $movementClass = str_contains($movementType, 'purchase') ||
                                                     str_contains($movementType, 'in')
                                        ? 'badge-in'
                                        : (str_contains($movementType, 'sale') ||
                                           str_contains($movementType, 'out')
                                            ? 'badge-out'
                                            : 'badge-neutral');
                                ?>
                                    <tr>
                                        <td><?= e(date('d-m-Y h:i A', strtotime($row['movement_date']))) ?></td>
                                        <td>
                                            <div class="product-name"><?= e($row['product_name']) ?></div>
                                            <div class="subtext"><?= e($row['product_code']) ?></div>
                                        </td>
                                        <td>
                                            <span class="badge-soft <?= $movementClass ?>">
                                                <?= e($row['movement_type']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?= e($row['reference_table'] ?: '—') ?>
                                            <?= $row['reference_id'] ? ' #' . (int)$row['reference_id'] : '' ?>
                                        </td>
                                        <td class="text-number"><?= number_format((float)$row['quantity_in'], 3) ?></td>
                                        <td class="text-number"><?= number_format((float)$row['quantity_out'], 3) ?></td>
                                        <td class="text-number"><?= number_format((float)$row['weight_in'], 3) ?> g</td>
                                        <td class="text-number"><?= number_format((float)$row['weight_out'], 3) ?> g</td>
                                        <td class="text-number"><?= e(money($row['rate'], $currency)) ?></td>
                                        <td class="text-number"><?= e(money($row['value_amount'], $currency)) ?></td>
                                        <td><?= e($row['remarks'] ?: '—') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>

        <?php include('includes/footer.php'); ?>
    </div>
</main>

<?php include('includes/script.php'); ?>
<script src="assets/js/script.js"></script>
</body>
</html>