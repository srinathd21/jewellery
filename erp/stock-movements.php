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

if (!function_exists('hasColumn')) {
    function hasColumn(mysqli $conn, string $table, string $column): bool
    {
        $table = $conn->real_escape_string($table);
        $column = $conn->real_escape_string($column);
        $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
        return $res && $res->num_rows > 0;
    }
}

$pageTitle = 'Stock Movements';
$page_title = 'Stock Movements';
$currentPage = 'stock-movements';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$businessId = (int)($_SESSION['business_id'] ?? 0);

if ($businessId <= 0) {
    http_response_code(403);
    die('A valid business must be selected before viewing stock movements.');
}

function stockMovementPermission(mysqli $conn, string $action): bool
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

    $sessionPermissions = $_SESSION['permissions'] ?? [];
    foreach (['perm.stock.movements', 'perm.stock', 'perm.inventory'] as $key) {
        if (isset($sessionPermissions[$key][$field])) {
            return (int)$sessionPermissions[$key][$field] === 1;
        }
    }

    $businessId = (int)($_SESSION['business_id'] ?? 0);
    $roleId = (int)($_SESSION['role_id'] ?? 0);
    if ($businessId <= 0 || $roleId <= 0) {
        $roleName = strtolower(trim((string)($_SESSION['role_name'] ?? '')));
        $roleCode = strtolower(trim((string)($_SESSION['role_code'] ?? '')));
        return in_array($roleName, ['admin', 'manager', 'stock'], true)
            || in_array($roleCode, ['admin', 'manager', 'stock'], true);
    }

    $sql = "SELECT rp.`{$field}`
            FROM role_permissions rp
            INNER JOIN permissions p ON p.id = rp.permission_id
            WHERE rp.business_id = ?
              AND rp.role_id = ?
              AND p.is_active = 1
              AND p.permission_code IN ('perm.stock.movements','perm.stock','perm.inventory')
            ORDER BY FIELD(p.permission_code,'perm.stock.movements','perm.stock','perm.inventory')
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

if (!stockMovementPermission($conn, 'open')) {
    http_response_code(403);
    die('Access denied. You do not have permission to open stock movements.');
}

$canView = stockMovementPermission($conn, 'view') || stockMovementPermission($conn, 'open');
$canViewValue = stockMovementPermission($conn, 'value') || $canView;

if (!tableExists($conn, 'stock_movements')) {
    die('Required table `stock_movements` not found.');
}
if (!tableExists($conn, 'products')) {
    die('Required table `products` not found.');
}

$smHasBusinessId   = hasColumn($conn, 'stock_movements', 'business_id');
$smHasMovementDate = hasColumn($conn, 'stock_movements', 'movement_date');
$smHasProductId    = hasColumn($conn, 'stock_movements', 'product_id');
$smHasMovementType = hasColumn($conn, 'stock_movements', 'movement_type');
$smHasRefTable     = hasColumn($conn, 'stock_movements', 'ref_table');
$smHasRefId        = hasColumn($conn, 'stock_movements', 'ref_id');
$smHasQtyIn        = hasColumn($conn, 'stock_movements', 'qty_in');
$smHasQtyOut       = hasColumn($conn, 'stock_movements', 'qty_out');
$smHasWeightIn     = hasColumn($conn, 'stock_movements', 'weight_in');
$smHasWeightOut    = hasColumn($conn, 'stock_movements', 'weight_out');
$smHasRemarks      = hasColumn($conn, 'stock_movements', 'remarks');
$smHasCreatedBy    = hasColumn($conn, 'stock_movements', 'created_by');
$smHasCreatedAt    = hasColumn($conn, 'stock_movements', 'created_at');

$prodHasBusinessId = hasColumn($conn, 'products', 'business_id');
$prodHasCode       = hasColumn($conn, 'products', 'product_code');
$prodHasBarcode    = hasColumn($conn, 'products', 'barcode');
$prodHasUnit       = hasColumn($conn, 'products', 'unit');
$userTableExists   = tableExists($conn, 'users');

$search       = trim((string)($_GET['search'] ?? ''));
$movementType = trim((string)($_GET['movement_type'] ?? ''));
$dateFrom     = trim((string)($_GET['date_from'] ?? ''));
$dateTo       = trim((string)($_GET['date_to'] ?? ''));
$productId    = (int)($_GET['product_id'] ?? 0);

$productSql = "SELECT id, product_name" . ($prodHasCode ? ", product_code" : "") . " FROM products WHERE 1=1";
$productParams = [];
$productTypes = '';

if ($prodHasBusinessId) {
    $productSql .= " AND business_id = ?";
    $productParams[] = $businessId;
    $productTypes .= 'i';
}
if (hasColumn($conn, 'products', 'is_active')) {
    $productSql .= " AND is_active = 1";
}
$productSql .= " ORDER BY product_name ASC";

$productOptions = [];
$stmt = $conn->prepare($productSql);
if ($stmt) {
    if ($productParams) {
        $bindValues = [$productTypes];
        foreach ($productParams as $key => $value) {
            $bindValues[] = &$productParams[$key];
        }
        call_user_func_array([$stmt, 'bind_param'], $bindValues);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && $row = $res->fetch_assoc()) {
        $productOptions[] = $row;
    }
    $stmt->close();
}

$summaryWhere = " WHERE 1=1 ";
$summaryParams = [];
$summaryTypes = '';
if ($smHasBusinessId) {
    $summaryWhere .= " AND sm.business_id = ? ";
    $summaryParams[] = $businessId;
    $summaryTypes .= 'i';
}

$totalMovements = 0;
$totalQtyIn = 0.0;
$totalQtyOut = 0.0;
$totalWeightIn = 0.0;
$totalWeightOut = 0.0;

$summarySql = "
    SELECT
        COUNT(*) AS total_movements,
        SUM(" . ($smHasQtyIn ? "IFNULL(sm.qty_in,0)" : "0") . ") AS total_qty_in,
        SUM(" . ($smHasQtyOut ? "IFNULL(sm.qty_out,0)" : "0") . ") AS total_qty_out,
        SUM(" . ($smHasWeightIn ? "IFNULL(sm.weight_in,0)" : "0") . ") AS total_weight_in,
        SUM(" . ($smHasWeightOut ? "IFNULL(sm.weight_out,0)" : "0") . ") AS total_weight_out
    FROM stock_movements sm
    {$summaryWhere}";

$stmt = $conn->prepare($summarySql);
if ($stmt) {
    if ($summaryParams) {
        $bindValues = [$summaryTypes];
        foreach ($summaryParams as $key => $value) {
            $bindValues[] = &$summaryParams[$key];
        }
        call_user_func_array([$stmt, 'bind_param'], $bindValues);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    $totalMovements = (int)($row['total_movements'] ?? 0);
    $totalQtyIn = (float)($row['total_qty_in'] ?? 0);
    $totalQtyOut = (float)($row['total_qty_out'] ?? 0);
    $totalWeightIn = (float)($row['total_weight_in'] ?? 0);
    $totalWeightOut = (float)($row['total_weight_out'] ?? 0);
}

$movementTypes = [
    'Opening', 'Purchase', 'Sale', 'Sale Return', 'Purchase Return',
    'Adjustment', 'Old Silver Inward', 'Damage', 'Manual'
];

$where = " WHERE 1=1 ";
$params = [];
$types = '';

if ($smHasBusinessId) {
    $where .= " AND sm.business_id = ? ";
    $params[] = $businessId;
    $types .= 'i';
}

if ($search !== '') {
    $where .= " AND (p.product_name LIKE ?";
    $like = '%' . $search . '%';
    $params[] = $like;
    $types .= 's';

    if ($prodHasCode) {
        $where .= " OR p.product_code LIKE ?";
        $params[] = $like;
        $types .= 's';
    }
    if ($prodHasBarcode) {
        $where .= " OR p.barcode LIKE ?";
        $params[] = $like;
        $types .= 's';
    }
    if ($smHasRemarks) {
        $where .= " OR sm.remarks LIKE ?";
        $params[] = $like;
        $types .= 's';
    }
    if ($smHasRefTable) {
        $where .= " OR sm.ref_table LIKE ?";
        $params[] = $like;
        $types .= 's';
    }
    $where .= ")";
}

if ($movementType !== '' && $smHasMovementType) {
    $where .= " AND sm.movement_type = ? ";
    $params[] = $movementType;
    $types .= 's';
}
if ($productId > 0 && $smHasProductId) {
    $where .= " AND sm.product_id = ? ";
    $params[] = $productId;
    $types .= 'i';
}
if ($dateFrom !== '' && $smHasMovementDate) {
    $where .= " AND DATE(sm.movement_date) >= ? ";
    $params[] = $dateFrom;
    $types .= 's';
}
if ($dateTo !== '' && $smHasMovementDate) {
    $where .= " AND DATE(sm.movement_date) <= ? ";
    $params[] = $dateTo;
    $types .= 's';
}

$sql = "
    SELECT
        sm.id,
        " . ($smHasMovementDate ? "sm.movement_date," : "sm.created_at AS movement_date,") . "
        " . ($smHasMovementType ? "sm.movement_type," : "'' AS movement_type,") . "
        " . ($smHasRefTable ? "sm.ref_table," : "'' AS ref_table,") . "
        " . ($smHasRefId ? "sm.ref_id," : "NULL AS ref_id,") . "
        " . ($smHasQtyIn ? "sm.qty_in," : "0 AS qty_in,") . "
        " . ($smHasQtyOut ? "sm.qty_out," : "0 AS qty_out,") . "
        " . ($smHasWeightIn ? "sm.weight_in," : "0 AS weight_in,") . "
        " . ($smHasWeightOut ? "sm.weight_out," : "0 AS weight_out,") . "
        " . ($smHasRemarks ? "sm.remarks," : "'' AS remarks,") . "
        " . ($smHasCreatedAt ? "sm.created_at," : "NULL AS created_at,") . "
        p.product_name,
        " . ($prodHasCode ? "p.product_code," : "'' AS product_code,") . "
        " . ($prodHasBarcode ? "p.barcode," : "'' AS barcode,") . "
        " . ($prodHasUnit ? "p.unit," : "'pcs' AS unit,") . "
        " . ($userTableExists && $smHasCreatedBy ? "u.full_name AS created_by_name" : "'' AS created_by_name") . "
    FROM stock_movements sm
    LEFT JOIN products p ON p.id = sm.product_id
    " . ($userTableExists && $smHasCreatedBy ? "LEFT JOIN users u ON u.id = sm.created_by" : "") . "
    {$where}
    ORDER BY " . ($smHasMovementDate ? "sm.movement_date" : "sm.id") . " DESC, sm.id DESC";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die('Failed to prepare stock movements query.');
}
if ($params) {
    $bindValues = [$types];
    foreach ($params as $key => $value) {
        $bindValues[] = &$params[$key];
    }
    call_user_func_array([$stmt, 'bind_param'], $bindValues);
}
$stmt->execute();
$res = $stmt->get_result();
$movements = [];
while ($res && $row = $res->fetch_assoc()) {
    $movements[] = $row;
}
$stmt->close();

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
}

$businessName = (string)($_SESSION['business_name'] ?? 'Jewellery ERP');
$netWeightChange = $totalWeightIn - $totalWeightOut;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo h($businessName); ?> - Stock Movements</title>
<?php include('includes/links.php'); ?>
<style>
:root{
    --primary:<?php echo h($theme['primary_color']); ?>;
    --primary-dark:<?php echo h($theme['primary_dark_color']); ?>;
    --primary-soft:<?php echo h($theme['primary_soft_color']); ?>;
    --page-bg:<?php echo h($theme['page_background']); ?>;
    --card-bg:<?php echo h($theme['card_background']); ?>;
    --text-color:<?php echo h($theme['text_color']); ?>;
    --muted-color:<?php echo h($theme['muted_text_color']); ?>;
    --border-color:<?php echo h($theme['border_color']); ?>;
    --sidebar-width:<?php echo (int)$theme['sidebar_width_px']; ?>px;
    --radius:<?php echo (int)$theme['border_radius_px']; ?>px;
}
body{background:var(--page-bg);color:var(--text-color);font-family:<?php echo json_encode((string)$theme['font_family']); ?>,sans-serif;}
.sidebar{background:linear-gradient(180deg,<?php echo h($theme['sidebar_gradient_1']); ?>,<?php echo h($theme['sidebar_gradient_2']); ?>,<?php echo h($theme['sidebar_gradient_3']); ?>)!important;}
.page-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:10px;}
.page-title{font-family:<?php echo json_encode((string)$theme['heading_font_family']); ?>,serif;font-size:18px;font-weight:800;margin:0;}
.page-subtitle{font-size:10px;color:var(--muted-color);margin-top:2px;}
.stat-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin-bottom:10px;}
.stat-card{background:var(--card-bg);border:1px solid var(--border-color);border-radius:var(--radius);padding:12px 14px;min-height:82px;display:flex;align-items:center;gap:12px;}
.stat-icon{width:42px;height:42px;flex:0 0 42px;display:flex;align-items:center;justify-content:center;border-radius:calc(var(--radius)*.75);background:var(--primary-soft);color:var(--primary-dark);font-size:16px;}
.stat-label{font-size:10px;color:var(--muted-color);}
.stat-value{font-size:21px;line-height:1.1;font-weight:800;margin-top:4px;}
.filter-card,.movements-card{background:var(--card-bg);border:1px solid var(--border-color);border-radius:var(--radius);}
.filter-card{padding:10px 12px;margin-bottom:10px;}
.filter-grid{display:grid;grid-template-columns:minmax(190px,1.4fr) minmax(180px,1.2fr) minmax(145px,1fr) 135px 135px auto;gap:8px;align-items:end;}
.field-label{display:block;font-size:9px;font-weight:700;margin-bottom:4px;color:var(--muted-color);text-transform:uppercase;letter-spacing:.035em;}
.form-control,.form-select{font-size:10px;min-height:36px;border-radius:9px;border-color:var(--border-color);background:var(--card-bg);color:var(--text-color);}
.btn-theme{background:linear-gradient(135deg,var(--primary),var(--primary-dark));border:0;color:#fff;border-radius:9px;font-size:10px;font-weight:700;min-height:36px;padding:8px 13px;}
.btn-reset{border:1px solid var(--border-color);background:var(--card-bg);color:var(--text-color);border-radius:9px;font-size:10px;font-weight:700;min-height:36px;padding:8px 12px;}
.movements-card{overflow:hidden;}
.movements-table{margin:0;font-size:10px;}
.movements-table th{font-size:9px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted-color);background:color-mix(in srgb,var(--muted-color) 6%,transparent);white-space:nowrap;padding:10px 11px;border-color:var(--border-color);}
.movements-table td{padding:10px 11px;vertical-align:middle;background:var(--card-bg)!important;color:var(--text-color);border-color:var(--border-color);}
.product-name{font-size:10px;font-weight:800;}
.product-sub{font-size:8px;color:var(--muted-color);margin-top:2px;}
.type-badge,.reference-badge{display:inline-flex;align-items:center;border-radius:999px;padding:4px 8px;font-size:8px;font-weight:800;}
.type-in{background:#eaf8f0;color:#168449;}
.type-out{background:#fdecec;color:#bd2d2d;}
.type-adjustment{background:#fff6df;color:#a66800;}
.type-neutral{background:#edf1f5;color:#536170;}
.reference-badge{background:var(--primary-soft);color:var(--primary-dark);}
.number-in{color:#168449;font-weight:800;}
.number-out{color:#bd2d2d;font-weight:800;}
.empty-state{padding:50px 20px;text-align:center;color:var(--muted-color);}
.empty-state i{font-size:34px;margin-bottom:10px;}
body.dark-mode,body[data-theme="dark"],html.dark-mode body,html[data-theme="dark"] body{--page-bg:#0f151b;--card-bg:#182129;--text-color:#f3f6f8;--muted-color:#9aa7b3;--border-color:#2c3944;}
@media(max-width:1199.98px){.filter-grid{grid-template-columns:repeat(3,minmax(0,1fr));}.filter-actions{grid-column:1/-1;}}
@media(max-width:991.98px){
    .stat-grid{grid-template-columns:repeat(2,minmax(0,1fr));}
    .movements-card{background:transparent;border:0;overflow:visible;}
    .table-responsive{overflow:visible;}
    .movements-table{display:block;background:transparent;}
    .movements-table thead{display:none;}
    .movements-table tbody{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;}
    .movements-table tbody tr{display:grid;grid-template-columns:1fr 1fr;background:var(--card-bg);border:1px solid var(--border-color);border-radius:var(--radius);padding:13px;}
    .movements-table tbody td{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;min-width:0;padding:8px 0;border:0;border-bottom:1px dashed var(--border-color);text-align:right!important;}
    .movements-table tbody td::before{content:attr(data-label);font-size:8px;font-weight:700;text-transform:uppercase;color:var(--muted-color);text-align:left;}
    .movements-table tbody td.product-column{grid-column:1/-1;display:block;padding:0 0 10px;text-align:left!important;}
    .movements-table tbody td.product-column::before{display:none;}
}
@media(max-width:767.98px){
    .content-wrap{padding-left:10px;padding-right:10px;}
    .filter-grid{grid-template-columns:1fr;}
    .filter-actions{grid-column:auto;}
    .movements-table tbody{grid-template-columns:1fr;}
    .movements-table tbody tr{grid-template-columns:1fr;}
    .movements-table tbody td{grid-column:1/-1;}
}
@media print{.sidebar,.app-nav,.footer,.no-print{display:none!important}.app-main{margin-left:0!important}.content-wrap{padding:0!important}.movements-card{border:0}.table-responsive{overflow:visible!important}}
</style>
</head>
<body>
<?php include('includes/sidebar.php'); ?>
<main class="app-main">
<?php include('includes/nav.php'); ?>
<div class="content-wrap">
    <?php if (!$canView): ?>
        <div class="movements-card"><div class="empty-state"><i class="fa-solid fa-lock"></i><div>You do not have permission to view stock movements.</div></div></div>
    <?php else: ?>
        <div class="stat-grid">
            <div class="stat-card">
                <div class="stat-icon"><i class="fa-solid fa-arrow-right-arrow-left"></i></div>
                <div>
                    <div class="stat-label">Total Movements</div>
                    <div class="stat-value"><?php echo number_format($totalMovements); ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fa-solid fa-arrow-down"></i></div>
                <div>
                    <div class="stat-label">Total Quantity In</div>
                    <div class="stat-value"><?php echo number_format($totalQtyIn, 3); ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fa-solid fa-arrow-up"></i></div>
                <div>
                    <div class="stat-label">Total Quantity Out</div>
                    <div class="stat-value"><?php echo number_format($totalQtyOut, 3); ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fa-solid fa-weight-scale"></i></div>
                <div>
                    <div class="stat-label">Net Weight Change</div>
                    <div class="stat-value"><?php echo number_format($netWeightChange, 3); ?></div>
                </div>
            </div>
        </div>

        <form method="get" class="filter-card no-print">
            <div class="filter-grid">
                <div><label class="field-label">Search</label><input type="search" name="search" class="form-control" placeholder="Product, code, barcode, remarks..." value="<?php echo h($search); ?>"></div>
                <div><label class="field-label">Product</label><select name="product_id" class="form-select"><option value="0">All Products</option><?php foreach ($productOptions as $product): ?><option value="<?php echo (int)$product['id']; ?>" <?php echo $productId === (int)$product['id'] ? 'selected' : ''; ?>><?php echo h($product['product_name'] . (!empty($product['product_code']) ? ' - ' . $product['product_code'] : '')); ?></option><?php endforeach; ?></select></div>
                <div><label class="field-label">Movement Type</label><select name="movement_type" class="form-select"><option value="">All Types</option><?php foreach ($movementTypes as $type): ?><option value="<?php echo h($type); ?>" <?php echo $movementType === $type ? 'selected' : ''; ?>><?php echo h($type); ?></option><?php endforeach; ?></select></div>
                <div><label class="field-label">Date From</label><input type="date" name="date_from" class="form-control" value="<?php echo h($dateFrom); ?>"></div>
                <div><label class="field-label">Date To</label><input type="date" name="date_to" class="form-control" value="<?php echo h($dateTo); ?>"></div>
                <div class="filter-actions d-flex gap-2"><button type="submit" class="btn btn-theme"><i class="fa-solid fa-filter me-1"></i>Apply</button><a href="stock-movements.php" class="btn btn-reset"><i class="fa-solid fa-rotate-left me-1"></i>Reset</a></div>
            </div>
        </form>

        <div class="movements-card">
            <?php if ($movements): ?>
                <div class="table-responsive">
                    <table class="table movements-table align-middle">
                        <thead><tr><th>#</th><th>Date & Time</th><th>Product</th><th>Type</th><th>Reference</th><th>Qty In</th><th>Qty Out</th><th>Weight In</th><th>Weight Out</th><th>Remarks</th><th>Created By</th></tr></thead>
                        <tbody>
                        <?php foreach ($movements as $index => $movement): ?>
                            <?php
                            $type = (string)($movement['movement_type'] ?? '');
                            $typeClass = 'type-neutral';
                            if (in_array($type, ['Opening','Purchase','Sale Return','Old Silver Inward','Manual'], true)) {
                                $typeClass = 'type-in';
                            } elseif (in_array($type, ['Sale','Purchase Return','Damage'], true)) {
                                $typeClass = 'type-out';
                            } elseif ($type === 'Adjustment') {
                                $typeClass = 'type-adjustment';
                            }
                            $referenceText = '—';
                            if (!empty($movement['ref_table']) || !empty($movement['ref_id'])) {
                                $referenceText = trim((string)($movement['ref_table'] ?? ''));
                                if (!empty($movement['ref_id'])) {
                                    $referenceText .= ' #' . (int)$movement['ref_id'];
                                }
                            }
                            ?>
                            <tr>
                                <td data-label="#"><?php echo $index + 1; ?></td>
                                <td data-label="Date"><?php echo !empty($movement['movement_date']) ? date('d M Y, h:i A', strtotime($movement['movement_date'])) : '—'; ?></td>
                                <td class="product-column" data-label="Product"><div class="product-name"><?php echo h($movement['product_name'] ?? 'Unknown Product'); ?></div><div class="product-sub"><?php echo h(trim(($movement['product_code'] ?? '') . (!empty($movement['barcode']) ? ' · ' . $movement['barcode'] : '')) ?: 'No code'); ?></div></td>
                                <td data-label="Type"><span class="type-badge <?php echo $typeClass; ?>"><?php echo h($type !== '' ? $type : 'N/A'); ?></span></td>
                                <td data-label="Reference"><span class="reference-badge"><?php echo h($referenceText); ?></span></td>
                                <td data-label="Qty In"><span class="number-in"><?php echo number_format((float)($movement['qty_in'] ?? 0), 3); ?></span> <?php echo h($movement['unit'] ?? ''); ?></td>
                                <td data-label="Qty Out"><span class="number-out"><?php echo number_format((float)($movement['qty_out'] ?? 0), 3); ?></span> <?php echo h($movement['unit'] ?? ''); ?></td>
                                <td data-label="Weight In"><span class="number-in"><?php echo number_format((float)($movement['weight_in'] ?? 0), 3); ?></span></td>
                                <td data-label="Weight Out"><span class="number-out"><?php echo number_format((float)($movement['weight_out'] ?? 0), 3); ?></span></td>
                                <td data-label="Remarks"><?php echo h($movement['remarks'] ?? '—'); ?></td>
                                <td data-label="Created By"><?php echo h($movement['created_by_name'] ?? '—'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state"><i class="fa-solid fa-arrow-right-arrow-left"></i><div>No stock movements found.</div><small>Try changing the search or filter values.</small></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php include('includes/footer.php'); ?>
</div>
</main>
<?php include('includes/script.php'); ?>
<script src="assets/js/script.js"></script>
</body>
</html>
