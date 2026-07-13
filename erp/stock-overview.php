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
        $table = $conn->real_escape_string($table);
        $res = $conn->query("SHOW TABLES LIKE '{$table}'");
        return $res && $res->num_rows > 0;
    }
}

function hasColumn(mysqli $conn, string $table, string $column): bool
{
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    return $res && $res->num_rows > 0;
}

$pageTitle = 'Stock Overview';

/* -------------------------------------------------------
   AUTH CHECK
------------------------------------------------------- */
if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    header('Location: ../login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$businessId = (int)($_SESSION['business_id'] ?? 0);

if ($businessId <= 0) {
    die('Business session not found. Please login again.');
}

/* -------------------------------------------------------
   ROLE / PERMISSION CHECK
------------------------------------------------------- */
$roleName = strtolower(trim((string)($_SESSION['role_name'] ?? '')));
$roleCode = strtolower(trim((string)($_SESSION['role_code'] ?? '')));
$userType = (string)($_SESSION['user_type'] ?? '');
$sessionPermissions = $_SESSION['permissions'] ?? [];

$allowedRoles = ['admin', 'manager', 'stock'];

$accessAllowed = (
    $userType === 'Platform Admin'
    || in_array($roleName, $allowedRoles, true)
    || in_array($roleCode, $allowedRoles, true)
);

foreach (['perm.stock', 'perm.inventory', 'perm.old_silver'] as $permissionCode) {
    if (
        isset($sessionPermissions[$permissionCode])
        && (
            (int)($sessionPermissions[$permissionCode]['can_open'] ?? 0) === 1
            || (int)($sessionPermissions[$permissionCode]['can_view'] ?? 0) === 1
            || (int)($sessionPermissions[$permissionCode]['can_create'] ?? 0) === 1
            || (int)($sessionPermissions[$permissionCode]['can_edit'] ?? 0) === 1
        )
    ) {
        $accessAllowed = true;
        break;
    }
}

if (!$accessAllowed) {
    http_response_code(403);
    die('Access denied.');
}

/* -------------------------------------------------------
   REQUIRED TABLES
------------------------------------------------------- */
if (!tableExists($conn, 'products') || !tableExists($conn, 'product_categories')) {
    die('Required tables not found. Please import the SQL first.');
}

/* -------------------------------------------------------
   TABLE / COLUMN CHECKS
------------------------------------------------------- */
$hasProductStockTable    = tableExists($conn, 'product_stock');
$hasStockMovementTable   = tableExists($conn, 'stock_movements');

$prodHasBusinessId       = hasColumn($conn, 'products', 'business_id');
$prodHasBarcode          = hasColumn($conn, 'products', 'barcode');
$prodHasDesignName       = hasColumn($conn, 'products', 'design_name');
$prodHasPurity           = hasColumn($conn, 'products', 'purity');
$prodHasUnit             = hasColumn($conn, 'products', 'unit');
$prodHasGrossWeight      = hasColumn($conn, 'products', 'gross_weight');
$prodHasLessWeight       = hasColumn($conn, 'products', 'less_weight');
$prodHasNetWeight        = hasColumn($conn, 'products', 'net_weight');
$prodHasPurchaseRate     = hasColumn($conn, 'products', 'purchase_rate');
$prodHasSaleRate         = hasColumn($conn, 'products', 'sale_rate');
$prodHasMinStockQty      = hasColumn($conn, 'products', 'min_stock_qty');
$prodHasCurrentStockQty  = hasColumn($conn, 'products', 'current_stock_qty');
$prodHasImagePath        = hasColumn($conn, 'products', 'image_path');
$prodHasDescription      = hasColumn($conn, 'products', 'description');
$prodHasIsActive         = hasColumn($conn, 'products', 'is_active');
$prodHasCreatedAt        = hasColumn($conn, 'products', 'created_at');

$catHasBusinessId        = hasColumn($conn, 'product_categories', 'business_id');
$catHasIsActive          = hasColumn($conn, 'product_categories', 'is_active');

$psHasProductId          = $hasProductStockTable && hasColumn($conn, 'product_stock', 'product_id');
$psHasBusinessId         = $hasProductStockTable && hasColumn($conn, 'product_stock', 'business_id');
$psHasOpeningQty         = $hasProductStockTable && hasColumn($conn, 'product_stock', 'opening_qty');
$psHasOpeningWeight      = $hasProductStockTable && hasColumn($conn, 'product_stock', 'opening_weight');
$psHasInQty              = $hasProductStockTable && hasColumn($conn, 'product_stock', 'in_qty');
$psHasInWeight           = $hasProductStockTable && hasColumn($conn, 'product_stock', 'in_weight');
$psHasOutQty             = $hasProductStockTable && hasColumn($conn, 'product_stock', 'out_qty');
$psHasOutWeight          = $hasProductStockTable && hasColumn($conn, 'product_stock', 'out_weight');
$psHasClosingQty         = $hasProductStockTable && hasColumn($conn, 'product_stock', 'closing_qty');
$psHasClosingWeight      = $hasProductStockTable && hasColumn($conn, 'product_stock', 'closing_weight');
$psHasUpdatedAt          = $hasProductStockTable && hasColumn($conn, 'product_stock', 'updated_at');

$smHasBusinessId         = $hasStockMovementTable && hasColumn($conn, 'stock_movements', 'business_id');
$smHasProductId          = $hasStockMovementTable && hasColumn($conn, 'stock_movements', 'product_id');
$smHasMovementDate       = $hasStockMovementTable && hasColumn($conn, 'stock_movements', 'movement_date');
$smHasMovementType       = $hasStockMovementTable && hasColumn($conn, 'stock_movements', 'movement_type');

/* -------------------------------------------------------
   FLASH MESSAGES
------------------------------------------------------- */
$success = '';
$error = '';

$msg = trim((string)($_GET['msg'] ?? ''));
if ($msg === 'updated') {
    $success = 'Stock updated successfully.';
}

/* -------------------------------------------------------
   CATEGORY OPTIONS
------------------------------------------------------- */
$categorySql = "SELECT id, category_name FROM product_categories WHERE 1=1";
$categoryParams = [];
$categoryTypes = '';

if ($catHasBusinessId) {
    $categorySql .= " AND business_id = ?";
    $categoryParams[] = $businessId;
    $categoryTypes .= 'i';
}
if ($catHasIsActive) {
    $categorySql .= " AND is_active = 1";
}
$categorySql .= " ORDER BY category_name ASC";

$categories = [];
$stmt = $conn->prepare($categorySql);
if ($stmt) {
    if (!empty($categoryParams)) {
        $bindValues = [];
        $bindValues[] = $categoryTypes;
        for ($i = 0; $i < count($categoryParams); $i++) {
            $bindValues[] = &$categoryParams[$i];
        }
        call_user_func_array([$stmt, 'bind_param'], $bindValues);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && $row = $res->fetch_assoc()) {
        $categories[] = $row;
    }
    $stmt->close();
}

/* -------------------------------------------------------
   FILTERS
------------------------------------------------------- */
$search         = trim((string)($_GET['search'] ?? ''));
$categoryFilter = (int)($_GET['category_id'] ?? 0);
$stockStatus    = trim((string)($_GET['stock_status'] ?? 'all'));
$productStatus  = trim((string)($_GET['status'] ?? 'all'));

/* -------------------------------------------------------
   STOCK EXPRESSIONS
------------------------------------------------------- */
$stockQtyExpr = $prodHasCurrentStockQty ? "IFNULL(p.current_stock_qty, 0)" : "0";
$stockWeightExpr = $prodHasNetWeight ? "IFNULL(p.net_weight, 0)" : "0";
$openingQtyExpr = "0";
$openingWeightExpr = "0";
$inQtyExpr = "0";
$inWeightExpr = "0";
$outQtyExpr = "0";
$outWeightExpr = "0";
$lastUpdatedExpr = "p.updated_at";

$joinProductStock = "";
if ($hasProductStockTable && $psHasProductId) {
    $joinProductStock = " LEFT JOIN product_stock ps ON ps.product_id = p.id ";
    if ($psHasBusinessId && $prodHasBusinessId) {
        $joinProductStock = " LEFT JOIN product_stock ps ON ps.product_id = p.id AND ps.business_id = p.business_id ";
    }

    if ($psHasClosingQty) {
        $stockQtyExpr = "IFNULL(ps.closing_qty, " . ($prodHasCurrentStockQty ? "IFNULL(p.current_stock_qty, 0)" : "0") . ")";
    }
    if ($psHasClosingWeight) {
        $stockWeightExpr = "IFNULL(ps.closing_weight, " . ($prodHasNetWeight ? "IFNULL(p.net_weight, 0)" : "0") . ")";
    }
    if ($psHasOpeningQty) {
        $openingQtyExpr = "IFNULL(ps.opening_qty, 0)";
    }
    if ($psHasOpeningWeight) {
        $openingWeightExpr = "IFNULL(ps.opening_weight, 0)";
    }
    if ($psHasInQty) {
        $inQtyExpr = "IFNULL(ps.in_qty, 0)";
    }
    if ($psHasInWeight) {
        $inWeightExpr = "IFNULL(ps.in_weight, 0)";
    }
    if ($psHasOutQty) {
        $outQtyExpr = "IFNULL(ps.out_qty, 0)";
    }
    if ($psHasOutWeight) {
        $outWeightExpr = "IFNULL(ps.out_weight, 0)";
    }
    if ($psHasUpdatedAt) {
        $lastUpdatedExpr = "COALESCE(ps.updated_at, p.updated_at)";
    }
}

$lastMovementExpr = "NULL";
$joinLastMovement = "";
if ($hasStockMovementTable && $smHasProductId && $smHasMovementDate) {
    $lastMovementSubWhere = " WHERE 1=1 ";
    if ($smHasBusinessId) {
        $lastMovementSubWhere .= " AND business_id = " . (int)$businessId . " ";
    }

    $joinLastMovement = "
        LEFT JOIN (
            SELECT product_id, MAX(movement_date) AS last_movement_date
            FROM stock_movements
            {$lastMovementSubWhere}
            GROUP BY product_id
        ) smx ON smx.product_id = p.id
    ";
    $lastMovementExpr = "smx.last_movement_date";
}

/* -------------------------------------------------------
   SUMMARY COUNTS
------------------------------------------------------- */
$baseWhere = " WHERE 1=1 ";
$baseParams = [];
$baseTypes = '';

if ($prodHasBusinessId) {
    $baseWhere .= " AND p.business_id = ? ";
    $baseParams[] = $businessId;
    $baseTypes .= 'i';
}

if ($prodHasIsActive) {
    if ($productStatus === 'active') {
        $baseWhere .= " AND p.is_active = 1 ";
    } elseif ($productStatus === 'inactive') {
        $baseWhere .= " AND p.is_active = 0 ";
    }
}

$totalProducts = 0;
$inStockCount = 0;
$lowStockCount = 0;
$outOfStockCount = 0;
$totalStockQty = 0;
$totalStockWeight = 0;

$summarySql = "
    SELECT
        COUNT(*) AS total_products,
        SUM(CASE WHEN {$stockQtyExpr} > 0 THEN 1 ELSE 0 END) AS in_stock_count,
        SUM(CASE WHEN {$prodHasMinStockQty} " . ($prodHasMinStockQty ? "AND {$stockQtyExpr} > 0 AND {$stockQtyExpr} <= IFNULL(p.min_stock_qty, 0) AND IFNULL(p.min_stock_qty, 0) > 0" : "IS NULL") . " THEN 1 ELSE 0 END) AS low_stock_count,
        SUM(CASE WHEN {$stockQtyExpr} <= 0 THEN 1 ELSE 0 END) AS out_of_stock_count,
        SUM({$stockQtyExpr}) AS total_stock_qty,
        SUM({$stockWeightExpr}) AS total_stock_weight
    FROM products p
    {$joinProductStock}
    {$baseWhere}
";

$stmt = $conn->prepare($summarySql);
if ($stmt) {
    if (!empty($baseParams)) {
        $bindValues = [];
        $bindValues[] = $baseTypes;
        for ($i = 0; $i < count($baseParams); $i++) {
            $bindValues[] = &$baseParams[$i];
        }
        call_user_func_array([$stmt, 'bind_param'], $bindValues);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if ($row) {
        $totalProducts    = (int)($row['total_products'] ?? 0);
        $inStockCount     = (int)($row['in_stock_count'] ?? 0);
        $lowStockCount    = (int)($row['low_stock_count'] ?? 0);
        $outOfStockCount  = (int)($row['out_of_stock_count'] ?? 0);
        $totalStockQty    = (float)($row['total_stock_qty'] ?? 0);
        $totalStockWeight = (float)($row['total_stock_weight'] ?? 0);
    }
}

/* -------------------------------------------------------
   PRODUCT LIST WHERE
------------------------------------------------------- */
$where = " WHERE 1=1 ";
$params = [];
$types = '';

if ($prodHasBusinessId) {
    $where .= " AND p.business_id = ? ";
    $params[] = $businessId;
    $types .= 'i';
}

if ($search !== '') {
    $where .= " AND (p.product_name LIKE ? OR p.product_code LIKE ?";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $types .= 'ss';

    if ($prodHasBarcode) {
        $where .= " OR p.barcode LIKE ?";
        $params[] = $like;
        $types .= 's';
    }

    if ($prodHasDesignName) {
        $where .= " OR p.design_name LIKE ?";
        $params[] = $like;
        $types .= 's';
    }

    $where .= " OR c.category_name LIKE ?)";
    $params[] = $like;
    $types .= 's';
}

if ($categoryFilter > 0) {
    $where .= " AND p.category_id = ? ";
    $params[] = $categoryFilter;
    $types .= 'i';
}

if ($prodHasIsActive) {
    if ($productStatus === 'active') {
        $where .= " AND p.is_active = 1 ";
    } elseif ($productStatus === 'inactive') {
        $where .= " AND p.is_active = 0 ";
    }
}

if ($stockStatus === 'in_stock') {
    $where .= " AND {$stockQtyExpr} > 0 ";
} elseif ($stockStatus === 'out_of_stock') {
    $where .= " AND {$stockQtyExpr} <= 0 ";
} elseif ($stockStatus === 'low_stock' && $prodHasMinStockQty) {
    $where .= " AND {$stockQtyExpr} > 0 AND {$stockQtyExpr} <= IFNULL(p.min_stock_qty, 0) AND IFNULL(p.min_stock_qty, 0) > 0 ";
}

/* -------------------------------------------------------
   PRODUCT STOCK LIST
------------------------------------------------------- */
$sql = "
    SELECT
        p.id,
        p.product_code,
        p.product_name,
        c.category_name,
        " . ($prodHasBarcode ? "p.barcode," : "'' AS barcode,") . "
        " . ($prodHasDesignName ? "p.design_name," : "'' AS design_name,") . "
        " . ($prodHasPurity ? "p.purity," : "'' AS purity,") . "
        " . ($prodHasUnit ? "p.unit," : "'pcs' AS unit,") . "
        " . ($prodHasGrossWeight ? "p.gross_weight," : "0 AS gross_weight,") . "
        " . ($prodHasLessWeight ? "p.less_weight," : "0 AS less_weight,") . "
        " . ($prodHasNetWeight ? "p.net_weight," : "0 AS net_weight,") . "
        " . ($prodHasPurchaseRate ? "p.purchase_rate," : "0 AS purchase_rate,") . "
        " . ($prodHasSaleRate ? "p.sale_rate," : "0 AS sale_rate,") . "
        " . ($prodHasMinStockQty ? "p.min_stock_qty," : "0 AS min_stock_qty,") . "
        " . ($prodHasImagePath ? "p.image_path," : "'' AS image_path,") . "
        " . ($prodHasIsActive ? "p.is_active," : "1 AS is_active,") . "
        {$openingQtyExpr} AS opening_qty,
        {$openingWeightExpr} AS opening_weight,
        {$inQtyExpr} AS in_qty,
        {$inWeightExpr} AS in_weight,
        {$outQtyExpr} AS out_qty,
        {$outWeightExpr} AS out_weight,
        {$stockQtyExpr} AS stock_qty,
        {$stockWeightExpr} AS stock_weight,
        {$lastUpdatedExpr} AS stock_updated_at,
        {$lastMovementExpr} AS last_movement_date
    FROM products p
    LEFT JOIN product_categories c ON c.id = p.category_id
    {$joinProductStock}
    {$joinLastMovement}
    {$where}
    ORDER BY p.id DESC
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die('Failed to prepare stock overview query.');
}

if (!empty($params)) {
    $bindValues = [];
    $bindValues[] = $types;
    for ($i = 0; $i < count($params); $i++) {
        $bindValues[] = &$params[$i];
    }
    call_user_func_array([$stmt, 'bind_param'], $bindValues);
}

$stmt->execute();
$res = $stmt->get_result();
$products = [];
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $products[] = $row;
    }
}
$stmt->close();



$theme = [
    'primary_color' => '#d89416',
    'primary_dark_color' => '#b86a0b',
    'primary_soft_color' => '#fff6e5',
    'sidebar_gradient_1' => '#171c21',
    'sidebar_gradient_2' => '#20272d',
    'sidebar_gradient_3' => '#101419',
    'page_background' => '#f5f5f3',
    'card_background' => '#ffffff',
    'text_color' => '#111827',
    'muted_text_color' => '#7b8497',
    'border_color' => '#e5e7eb',
    'font_family' => 'Inter',
    'heading_font_family' => 'Playfair Display',
    'border_radius_px' => 14,
];

if (function_exists('tableExists') && tableExists($conn, 'business_theme_settings')) {
    $themeStmt = $conn->prepare(
        'SELECT * FROM business_theme_settings WHERE business_id = ? LIMIT 1'
    );

    if ($themeStmt) {
        $themeStmt->bind_param('i', $businessId);
        $themeStmt->execute();
        $themeRow = $themeStmt->get_result()->fetch_assoc() ?: [];
        $themeStmt->close();

        foreach ($theme as $themeKey => $themeDefault) {
            if (isset($themeRow[$themeKey]) && $themeRow[$themeKey] !== '') {
                $theme[$themeKey] = $themeRow[$themeKey];
            }
        }
    }
}

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
    --brand: <?php echo h($theme['primary_color']); ?>;
    --brand-dark: <?php echo h($theme['primary_dark_color']); ?>;
    --brand-soft: <?php echo h($theme['primary_soft_color']); ?>;
    --page-bg: <?php echo h($theme['page_background']); ?>;
    --card-bg: <?php echo h($theme['card_background']); ?>;
    --text: <?php echo h($theme['text_color']); ?>;
    --muted: <?php echo h($theme['muted_text_color']); ?>;
    --line: <?php echo h($theme['border_color']); ?>;
    --radius: <?php echo (int)$theme['border_radius_px']; ?>px;
}

body {
    background: var(--page-bg);
    color: var(--text);
    font-family: <?php echo json_encode((string)$theme['font_family']); ?>, sans-serif;
}

.sidebar {
    background: linear-gradient(
        180deg,
        <?php echo h($theme['sidebar_gradient_1']); ?>,
        <?php echo h($theme['sidebar_gradient_2']); ?>,
        <?php echo h($theme['sidebar_gradient_3']); ?>
    ) !important;
}

.content-wrap {
    padding-top: 16px;
}

.page-new-header {
    margin-bottom: 14px;
}

.page-new-title {
    margin: 0;
    font-family: <?php echo json_encode((string)$theme['heading_font_family']); ?>, serif;
    font-size: 24px;
    line-height: 1.1;
    font-weight: 800;
}

.page-new-subtitle {
    margin-top: 4px;
    color: var(--muted);
    font-size: 11px;
}

.card,
.report-card,
.invoice-header-box {
    background: var(--card-bg) !important;
    border: 1px solid var(--line) !important;
    border-radius: var(--radius) !important;
    box-shadow: none !important;
}

.card-header,
.report-card .card-header {
    background: #f7f7f8 !important;
    border-bottom: 1px solid var(--line) !important;
    color: var(--text);
    border-radius: var(--radius) var(--radius) 0 0 !important;
}

.card-body,
.report-card .card-body {
    padding: 14px !important;
}

h1, h2, h3, h4, h5, h6,
.card-title {
    color: var(--text);
}

.form-label {
    margin-bottom: 5px;
    color: var(--text);
    font-size: 10px;
    font-weight: 700;
}

.form-control,
.form-select {
    min-height: 40px;
    border: 1px solid var(--line);
    border-radius: 10px;
    background: var(--card-bg);
    color: var(--text);
    font-size: 11px;
    box-shadow: none;
}

.form-control:focus,
.form-select:focus {
    border-color: var(--brand);
    box-shadow: 0 0 0 .18rem rgba(216, 148, 22, .12);
}

.btn {
    min-height: 38px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 700;
}

.btn-primary,
.btn-info {
    border-color: transparent !important;
    background: linear-gradient(135deg, var(--brand), var(--brand-dark)) !important;
    color: #fff !important;
}

.btn-secondary,
.btn-light {
    border: 1px solid var(--line) !important;
    background: #fff !important;
    color: var(--text) !important;
}

.table-responsive {
    border-radius: 12px;
}

.table {
    margin-bottom: 0;
    color: var(--text);
    font-size: 10px;
}

.table thead th {
    padding: 12px 13px;
    border-color: var(--line);
    background: #f7f7f8;
    color: #738096;
    font-size: 9px;
    font-weight: 800;
    letter-spacing: .035em;
    text-transform: uppercase;
    white-space: nowrap;
}

.table tbody td {
    padding: 11px 13px;
    border-color: var(--line);
    background: var(--card-bg) !important;
    color: var(--text);
    vertical-align: middle;
}

.badge {
    border-radius: 999px;
    padding: 5px 9px;
    font-size: 9px;
    font-weight: 800;
}

.alert {
    border: 0;
    border-radius: 10px;
    font-size: 11px;
}

.text-muted {
    color: var(--muted) !important;
}

.row > [class*="col-"] > .card {
    height: calc(100% - 12px);
    margin-bottom: 12px;
}

body.dark-mode,
body[data-theme="dark"],
html.dark-mode body,
html[data-theme="dark"] body {
    --page-bg: #0f151b;
    --card-bg: #182129;
    --text: #f3f6f8;
    --muted: #9aa7b3;
    --line: #2c3944;
}

@media (max-width: 767px) {
    .content-wrap {
        padding-left: 10px;
        padding-right: 10px;
    }
}

@media print {
    .sidebar,
    .app-nav,
    .footer,
    .no-print {
        display: none !important;
    }

    .app-main {
        margin-left: 0 !important;
    }

    .content-wrap {
        padding: 0 !important;
    }

    .table-responsive {
        overflow: visible !important;
    }
}
</style>

    
</head>

<body>
<?php include('includes/sidebar.php'); ?>

<main class="app-main">
    <?php include('includes/nav.php'); ?>

    <div class="content-wrap">
        <div class="page-new-header">
            <h1 class="page-new-title">Stock Overview</h1>
            <div class="page-new-subtitle">
                <?php echo h($businessName); ?> &nbsp;•&nbsp; Inventory
            </div>
        </div>

        
                </div>

                <?php if ($success !== ''): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo h($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo h($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-md-3 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-primary mt-2"><?php echo $totalProducts; ?></h3>
                                <p class="text-muted mb-0">Total Products</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-success mt-2"><?php echo $inStockCount; ?></h3>
                                <p class="text-muted mb-0">In Stock</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-warning mt-2"><?php echo $lowStockCount; ?></h3>
                                <p class="text-muted mb-0">Low Stock</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-danger mt-2"><?php echo $outOfStockCount; ?></h3>
                                <p class="text-muted mb-0">Out of Stock</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-6">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-info mt-2"><?php echo number_format($totalStockQty, 3); ?></h3>
                                <p class="text-muted mb-0">Total Stock Qty</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-6">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-dark mt-2"><?php echo number_format($totalStockWeight, 3); ?></h3>
                                <p class="text-muted mb-0">Total Stock Weight</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <form method="get" class="row g-2 align-items-end">
                            <div class="col-md-3">
                                <label class="form-label">Search</label>
                                <input
                                    type="text"
                                    name="search"
                                    class="form-control"
                                    placeholder="Product name, code, barcode..."
                                    value="<?php echo h($search); ?>"
                                >
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Category</label>
                                <select name="category_id" class="form-select">
                                    <option value="0">All Categories</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo (int)$cat['id']; ?>" <?php echo $categoryFilter === (int)$cat['id'] ? 'selected' : ''; ?>>
                                            <?php echo h($cat['category_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Stock Status</label>
                                <select name="stock_status" class="form-select">
                                    <option value="all" <?php echo $stockStatus === 'all' ? 'selected' : ''; ?>>All</option>
                                    <option value="in_stock" <?php echo $stockStatus === 'in_stock' ? 'selected' : ''; ?>>In Stock</option>
                                    <option value="low_stock" <?php echo $stockStatus === 'low_stock' ? 'selected' : ''; ?>>Low Stock</option>
                                    <option value="out_of_stock" <?php echo $stockStatus === 'out_of_stock' ? 'selected' : ''; ?>>Out of Stock</option>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Product Status</label>
                                <select name="status" class="form-select">
                                    <option value="all" <?php echo $productStatus === 'all' ? 'selected' : ''; ?>>All</option>
                                    <option value="active" <?php echo $productStatus === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo $productStatus === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <div class="d-flex gap-2">
                                    <button type="submit" class="btn btn-primary w-100">Search</button>
                                    <a href="stock-overview.php" class="btn btn-secondary w-100">Reset</a>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Product</th>
                                        <th>Category</th>
                                        <?php if ($prodHasBarcode): ?><th>Barcode</th><?php endif; ?>
                                        <?php if ($prodHasPurity): ?><th>Purity</th><?php endif; ?>
                                        <th>Opening Qty</th>
                                        <th>In Qty</th>
                                        <th>Out Qty</th>
                                        <th>Closing Qty</th>
                                        <th>Min Qty</th>
                                        <th>Stock Weight</th>
                                        <?php if ($prodHasSaleRate): ?><th>Sale Rate</th><?php endif; ?>
                                        <th>Stock Status</th>
                                        <th>Last Movement</th>
                                        <th>Last Updated</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($products)): ?>
                                        <?php foreach ($products as $index => $product): ?>
                                            <?php
                                                $stockQty = (float)($product['stock_qty'] ?? 0);
                                                $minQty   = (float)($product['min_stock_qty'] ?? 0);
                                                $stockWeight = (float)($product['stock_weight'] ?? 0);

                                                $stockBadge = '<span class="badge bg-success">In Stock</span>';
                                                if ($stockQty <= 0) {
                                                    $stockBadge = '<span class="badge bg-danger">Out of Stock</span>';
                                                } elseif ($minQty > 0 && $stockQty <= $minQty) {
                                                    $stockBadge = '<span class="badge bg-warning text-dark">Low Stock</span>';
                                                }
                                            ?>
                                            <tr>
                                                <td><?php echo $index + 1; ?></td>

                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <?php if (!empty($product['image_path'])): ?>
                                                            <img
                                                                src="<?php echo h($product['image_path']); ?>"
                                                                alt="Product"
                                                                class="rounded me-2"
                                                                style="width:50px;height:50px;object-fit:cover;"
                                                            >
                                                        <?php endif; ?>

                                                        <div>
                                                            <strong><?php echo h($product['product_name']); ?></strong><br>
                                                            <small class="text-muted"><?php echo h($product['product_code']); ?></small>
                                                            <?php if (!empty($product['design_name'])): ?>
                                                                <br><small class="text-muted"><?php echo h($product['design_name']); ?></small>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </td>

                                                <td><?php echo h($product['category_name'] ?? ''); ?></td>

                                                <?php if ($prodHasBarcode): ?>
                                                    <td><?php echo h($product['barcode'] ?? ''); ?></td>
                                                <?php endif; ?>

                                                <?php if ($prodHasPurity): ?>
                                                    <td><?php echo h($product['purity'] ?? ''); ?></td>
                                                <?php endif; ?>

                                                <td><?php echo number_format((float)($product['opening_qty'] ?? 0), 3); ?></td>
                                                <td class="text-success"><?php echo number_format((float)($product['in_qty'] ?? 0), 3); ?></td>
                                                <td class="text-danger"><?php echo number_format((float)($product['out_qty'] ?? 0), 3); ?></td>
                                                <td><strong><?php echo number_format($stockQty, 3); ?></strong> <?php echo h($product['unit'] ?? ''); ?></td>
                                                <td><?php echo number_format($minQty, 3); ?></td>
                                                <td><?php echo number_format($stockWeight, 3); ?></td>

                                                <?php if ($prodHasSaleRate): ?>
                                                    <td>₹<?php echo number_format((float)($product['sale_rate'] ?? 0), 2); ?></td>
                                                <?php endif; ?>

                                                <td><?php echo $stockBadge; ?></td>

                                                <td>
                                                    <?php
                                                    if (!empty($product['last_movement_date'])) {
                                                        echo date('d-m-Y h:i A', strtotime($product['last_movement_date']));
                                                    } else {
                                                        echo '-';
                                                    }
                                                    ?>
                                                </td>

                                                <td>
                                                    <?php
                                                    if (!empty($product['stock_updated_at'])) {
                                                        echo date('d-m-Y h:i A', strtotime($product['stock_updated_at']));
                                                    } else {
                                                        echo '-';
                                                    }
                                                    ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <?php
                                            $colspan = 12;
                                            if ($prodHasBarcode) $colspan++;
                                            if ($prodHasPurity) $colspan++;
                                            if ($prodHasSaleRate) $colspan++;
                                        ?>
                                        <tr>
                                            <td colspan="<?php echo $colspan; ?>" class="text-center text-muted">
                                                No stock records found.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if (!$hasProductStockTable): ?>
                            <div class="alert alert-warning mt-3 mb-0">
                                <strong>Note:</strong> `product_stock` table not found. Closing quantity is currently shown from `products.current_stock_qty`.
                            </div>
                        <?php endif; ?>

                    </div>
                </div>

        <?php include('includes/footer.php'); ?>
    </div>
</main>

<?php include('includes/script.php'); ?>
<script src="assets/js/script.js"></script>

</body>
</html>
