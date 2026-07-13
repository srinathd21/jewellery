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
        :root{
            --primary:<?php echo h($theme['primary_color']); ?>;
            --primary-dark:<?php echo h($theme['primary_dark_color']); ?>;
            --primary-soft:<?php echo h($theme['primary_soft_color']); ?>;
            --sidebar-gradient-1:<?php echo h($theme['sidebar_gradient_1']); ?>;
            --sidebar-gradient-2:<?php echo h($theme['sidebar_gradient_2']); ?>;
            --sidebar-gradient-3:<?php echo h($theme['sidebar_gradient_3']); ?>;
            --page-bg:<?php echo h($theme['page_background']); ?>;
            --card-bg:<?php echo h($theme['card_background']); ?>;
            --text-color:<?php echo h($theme['text_color']); ?>;
            --muted-color:<?php echo h($theme['muted_text_color']); ?>;
            --border-color:<?php echo h($theme['border_color']); ?>;
            --radius:<?php echo (int)$theme['border_radius_px']; ?>px;
        }
        body{background:var(--page-bg);color:var(--text-color);font-family:<?php echo json_encode((string)$theme['font_family']); ?>,sans-serif;}
        .sidebar{background:linear-gradient(180deg,var(--sidebar-gradient-1),var(--sidebar-gradient-2),var(--sidebar-gradient-3))!important;}
        .page-heading{display:flex;align-items:flex-end;justify-content:space-between;gap:12px;margin-bottom:10px;}
        .page-title{margin:0;font-family:<?php echo json_encode((string)$theme['heading_font_family']); ?>,serif;font-size:20px;font-weight:800;}
        .page-subtitle{margin-top:3px;color:var(--muted-color);font-size:10px;}
        .stat-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:8px;margin-bottom:10px;}
        .stat-card{background:var(--card-bg);border:1px solid var(--border-color);border-radius:var(--radius);padding:12px;min-height:78px;display:flex;align-items:center;gap:10px;}
        .stat-icon{width:40px;height:40px;flex:0 0 40px;display:flex;align-items:center;justify-content:center;border-radius:calc(var(--radius)*.72);background:var(--primary-soft);color:var(--primary-dark);font-size:15px;}
        .stat-label{font-size:9px;color:var(--muted-color);}
        .stat-value{font-size:18px;line-height:1.1;font-weight:800;margin-top:4px;white-space:nowrap;}
        .stock-toolbar{display:flex;align-items:end;gap:8px;flex-wrap:wrap;background:var(--card-bg);border:1px solid var(--border-color);border-radius:var(--radius);padding:10px 12px;margin-bottom:10px;}
        .toolbar-field{min-width:150px;flex:1 1 150px;}
        .toolbar-search{min-width:240px;flex:2 1 240px;position:relative;}
        .toolbar-search i{position:absolute;left:11px;bottom:11px;color:var(--muted-color);font-size:11px;}
        .toolbar-search input{padding-left:32px;}
        .field-label{display:block;font-size:9px;font-weight:700;margin-bottom:5px;color:var(--muted-color);}
        .form-control,.form-select{font-size:10px;min-height:36px;border-radius:9px;border-color:var(--border-color);background:var(--card-bg);color:var(--text-color);}
        .btn-theme{background:linear-gradient(135deg,var(--primary),var(--primary-dark));border:0;color:#fff;border-radius:calc(var(--radius)*.65);font-size:10px;font-weight:700;padding:9px 13px;}
        .btn-soft{background:var(--card-bg);border:1px solid var(--border-color);color:var(--text-color);border-radius:calc(var(--radius)*.65);font-size:10px;font-weight:700;padding:8px 12px;}
        .stock-card{background:var(--card-bg);border:1px solid var(--border-color);border-radius:var(--radius);overflow:hidden;}
        .stock-table{margin:0;font-size:10px;}
        .stock-table th{font-size:9px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted-color);background:color-mix(in srgb,var(--muted-color) 6%,transparent);white-space:nowrap;padding:10px;border-color:var(--border-color);}
        .stock-table td{padding:10px;vertical-align:middle;color:var(--text-color);background:var(--card-bg)!important;border-color:var(--border-color);white-space:nowrap;}
        .product-cell{min-width:220px;white-space:normal!important;}
        .product-wrap{display:flex;align-items:center;gap:9px;}
        .product-image{width:38px;height:38px;object-fit:cover;border-radius:9px;border:1px solid var(--border-color);}
        .product-placeholder{width:38px;height:38px;flex:0 0 38px;display:flex;align-items:center;justify-content:center;border-radius:9px;background:var(--primary-soft);color:var(--primary-dark);}
        .product-name{font-size:10px;font-weight:800;}
        .product-sub{font-size:8px;color:var(--muted-color);margin-top:2px;}
        .status-badge{display:inline-flex;align-items:center;border-radius:999px;padding:4px 8px;font-size:8px;font-weight:800;}
        .status-in{background:#eaf8f0;color:#168449;}.status-low{background:#fff4db;color:#a76500;}.status-out{background:#fdecec;color:#bd2d2d;}
        .number-in{color:#168449;font-weight:700;}.number-out{color:#bd2d2d;font-weight:700;}.number-main{font-weight:800;}
        .empty-state{padding:48px 20px;text-align:center;color:var(--muted-color);}.empty-state i{font-size:32px;margin-bottom:10px;}
        .notice{margin-top:10px;padding:10px 12px;border-radius:10px;background:#fff8e8;color:#8a5a00;font-size:10px;border:1px solid #f5dfaa;}
        body.dark-mode,body[data-theme="dark"],html.dark-mode body,html[data-theme="dark"] body{--page-bg:#0f151b;--card-bg:#182129;--text-color:#f3f6f8;--muted-color:#9aa7b3;--border-color:#2c3944;}
        @media(max-width:1199.98px){.stat-grid{grid-template-columns:repeat(3,minmax(0,1fr));}}
        @media(max-width:991.98px){
            .stat-grid{grid-template-columns:repeat(2,minmax(0,1fr));}
            .stock-toolbar{align-items:stretch;}
            .toolbar-field,.toolbar-search{min-width:calc(50% - 8px);}
            .stock-card{background:transparent;border:0;overflow:visible;}
            .table-responsive{overflow:visible;}
            .stock-table{display:block;background:transparent;}
            .stock-table thead{display:none;}
            .stock-table tbody{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;}
            .stock-table tbody tr{display:grid;grid-template-columns:1fr 1fr;background:var(--card-bg);border:1px solid var(--border-color);border-radius:var(--radius);padding:12px;}
            .stock-table tbody td{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;min-width:0;padding:7px 0;border:0;border-bottom:1px dashed var(--border-color);text-align:right!important;white-space:normal;}
            .stock-table tbody td::before{content:attr(data-label);font-size:8px;font-weight:700;text-transform:uppercase;color:var(--muted-color);text-align:left;}
            .stock-table tbody td.product-cell{grid-column:1/-1;display:block;padding:0 0 10px;}
            .stock-table tbody td.product-cell::before{display:none;}
            .stock-table tbody td:last-child{border-bottom:0;}
        }
        @media(max-width:767.98px){
            .content-wrap{padding-left:10px;padding-right:10px;}
            .page-heading{align-items:flex-start;flex-direction:column;}
            .stat-grid{grid-template-columns:1fr 1fr;}
            .toolbar-field,.toolbar-search{min-width:100%;flex-basis:100%;}
            .stock-table tbody{grid-template-columns:1fr;}
            .stock-table tbody tr{grid-template-columns:1fr;}
            .stock-table tbody td{grid-column:1/-1;}
        }
        @media print{.sidebar,.app-nav,.footer,.no-print{display:none!important}.app-main{margin-left:0!important}.content-wrap{padding:0!important}.stock-card{border:0}.table-responsive{overflow:visible!important}.stock-table{display:table!important}.stock-table thead{display:table-header-group!important}.stock-table tbody{display:table-row-group!important}.stock-table tbody tr{display:table-row!important}.stock-table tbody td{display:table-cell!important}.stock-table tbody td::before{display:none!important}}
    </style>
</head>
<body>
<?php include('includes/sidebar.php'); ?>
<main class="app-main">
    <?php include('includes/nav.php'); ?>
    <div class="content-wrap">
        <div class="page-heading">
            <div>
                <h1 class="page-title">Stock Overview</h1>
                <div class="page-subtitle"><?php echo h($businessName); ?> · Inventory stock position and movement summary</div>
            </div>
            <button type="button" class="btn btn-soft no-print" onclick="window.print()"><i class="fa-solid fa-print me-2"></i>Print</button>
        </div>

        <div class="stat-grid">
            <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-boxes-stacked"></i></div><div><div class="stat-label">Total Products</div><div class="stat-value"><?php echo (int)$totalProducts; ?></div></div></div>
            <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-circle-check"></i></div><div><div class="stat-label">In Stock</div><div class="stat-value"><?php echo (int)$inStockCount; ?></div></div></div>
            <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-triangle-exclamation"></i></div><div><div class="stat-label">Low Stock</div><div class="stat-value"><?php echo (int)$lowStockCount; ?></div></div></div>
            <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-circle-xmark"></i></div><div><div class="stat-label">Out of Stock</div><div class="stat-value"><?php echo (int)$outOfStockCount; ?></div></div></div>
            <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-cubes"></i></div><div><div class="stat-label">Total Stock Qty</div><div class="stat-value"><?php echo number_format($totalStockQty, 3); ?></div></div></div>
            <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-weight-hanging"></i></div><div><div class="stat-label">Total Weight</div><div class="stat-value"><?php echo number_format($totalStockWeight, 3); ?></div></div></div>
        </div>

        <form method="get" class="stock-toolbar no-print">
            <div class="toolbar-search"><label class="field-label">Search</label><i class="fa-solid fa-magnifying-glass"></i><input type="search" name="search" class="form-control" placeholder="Product, code, barcode or category" value="<?php echo h($search); ?>"></div>
            <div class="toolbar-field"><label class="field-label">Category</label><select name="category_id" class="form-select"><option value="0">All Categories</option><?php foreach ($categories as $cat): ?><option value="<?php echo (int)$cat['id']; ?>" <?php echo $categoryFilter === (int)$cat['id'] ? 'selected' : ''; ?>><?php echo h($cat['category_name']); ?></option><?php endforeach; ?></select></div>
            <div class="toolbar-field"><label class="field-label">Stock Status</label><select name="stock_status" class="form-select"><option value="all" <?php echo $stockStatus === 'all' ? 'selected' : ''; ?>>All Stock</option><option value="in_stock" <?php echo $stockStatus === 'in_stock' ? 'selected' : ''; ?>>In Stock</option><option value="low_stock" <?php echo $stockStatus === 'low_stock' ? 'selected' : ''; ?>>Low Stock</option><option value="out_of_stock" <?php echo $stockStatus === 'out_of_stock' ? 'selected' : ''; ?>>Out of Stock</option></select></div>
            <div class="toolbar-field"><label class="field-label">Product Status</label><select name="status" class="form-select"><option value="all" <?php echo $productStatus === 'all' ? 'selected' : ''; ?>>All Status</option><option value="active" <?php echo $productStatus === 'active' ? 'selected' : ''; ?>>Active</option><option value="inactive" <?php echo $productStatus === 'inactive' ? 'selected' : ''; ?>>Inactive</option></select></div>
            <div class="d-flex gap-2"><button type="submit" class="btn btn-theme"><i class="fa-solid fa-filter me-2"></i>Apply</button><a href="stock-overview.php" class="btn btn-soft">Reset</a></div>
        </form>

        <div class="stock-card">
            <?php if (!empty($products)): ?>
            <div class="table-responsive">
                <table class="table stock-table align-middle">
                    <thead><tr><th>#</th><th>Product</th><th>Category</th><?php if ($prodHasBarcode): ?><th>Barcode</th><?php endif; ?><?php if ($prodHasPurity): ?><th>Purity</th><?php endif; ?><th>Opening</th><th>In</th><th>Out</th><th>Closing</th><th>Minimum</th><th>Weight</th><?php if ($prodHasSaleRate): ?><th>Sale Rate</th><?php endif; ?><th>Status</th><th>Last Movement</th><th>Updated</th></tr></thead>
                    <tbody>
                    <?php foreach ($products as $index => $product): ?>
                        <?php
                            $stockQty = (float)($product['stock_qty'] ?? 0);
                            $minQty = (float)($product['min_stock_qty'] ?? 0);
                            $stockWeight = (float)($product['stock_weight'] ?? 0);
                            $statusText = 'In Stock'; $statusClass = 'status-in';
                            if ($stockQty <= 0) { $statusText = 'Out of Stock'; $statusClass = 'status-out'; }
                            elseif ($minQty > 0 && $stockQty <= $minQty) { $statusText = 'Low Stock'; $statusClass = 'status-low'; }
                        ?>
                        <tr>
                            <td data-label="#"><?php echo $index + 1; ?></td>
                            <td class="product-cell" data-label="Product"><div class="product-wrap"><?php if (!empty($product['image_path'])): ?><img src="<?php echo h($product['image_path']); ?>" alt="" class="product-image"><?php else: ?><div class="product-placeholder"><i class="fa-solid fa-gem"></i></div><?php endif; ?><div><div class="product-name"><?php echo h($product['product_name']); ?></div><div class="product-sub"><?php echo h($product['product_code']); ?><?php if (!empty($product['design_name'])): ?> · <?php echo h($product['design_name']); ?><?php endif; ?></div></div></div></td>
                            <td data-label="Category"><?php echo h($product['category_name'] ?: '—'); ?></td>
                            <?php if ($prodHasBarcode): ?><td data-label="Barcode"><?php echo h($product['barcode'] ?: '—'); ?></td><?php endif; ?>
                            <?php if ($prodHasPurity): ?><td data-label="Purity"><?php echo h($product['purity'] ?: '—'); ?></td><?php endif; ?>
                            <td data-label="Opening"><?php echo number_format((float)($product['opening_qty'] ?? 0), 3); ?></td>
                            <td data-label="In" class="number-in"><?php echo number_format((float)($product['in_qty'] ?? 0), 3); ?></td>
                            <td data-label="Out" class="number-out"><?php echo number_format((float)($product['out_qty'] ?? 0), 3); ?></td>
                            <td data-label="Closing" class="number-main"><?php echo number_format($stockQty, 3); ?> <?php echo h($product['unit'] ?? ''); ?></td>
                            <td data-label="Minimum"><?php echo number_format($minQty, 3); ?></td>
                            <td data-label="Weight"><?php echo number_format($stockWeight, 3); ?></td>
                            <?php if ($prodHasSaleRate): ?><td data-label="Sale Rate">₹<?php echo number_format((float)($product['sale_rate'] ?? 0), 2); ?></td><?php endif; ?>
                            <td data-label="Status"><span class="status-badge <?php echo $statusClass; ?>"><?php echo $statusText; ?></span></td>
                            <td data-label="Last Movement"><?php echo !empty($product['last_movement_date']) ? date('d-m-Y h:i A', strtotime($product['last_movement_date'])) : '—'; ?></td>
                            <td data-label="Updated"><?php echo !empty($product['stock_updated_at']) ? date('d-m-Y h:i A', strtotime($product['stock_updated_at'])) : '—'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <div class="empty-state"><i class="fa-regular fa-folder-open"></i><div>No stock records found.</div></div>
            <?php endif; ?>
        </div>

        <?php if (!$hasProductStockTable): ?><div class="notice"><strong>Note:</strong> The <code>product_stock</code> table was not found. Closing quantity is currently taken from <code>products.current_stock_qty</code>.</div><?php endif; ?>
        <?php include('includes/footer.php'); ?>
    </div>
</main>
<?php include('includes/script.php'); ?>
<script src="assets/js/script.js"></script>
</body>
</html>
