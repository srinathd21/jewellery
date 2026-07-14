<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));

$configCandidates = [__DIR__ . '/config/config.php', __DIR__ . '/config.php', __DIR__ . '/includes/config.php', __DIR__ . '/super-admin/includes/config.php'];
foreach ($configCandidates as $configFile) { if (is_file($configFile)) { require_once $configFile; break; } }

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not available. Check includes/config.php');
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

$pageTitle = 'Stock Adjustment';
$page_title = 'Stock Adjustment';
$currentPage = 'stock-adjustment';

/* -------------------------------------------------------
   AUTH CHECK
------------------------------------------------------- */
if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    header('Location: login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$businessId = (int)($_SESSION['business_id'] ?? 0);

if ($businessId <= 0) {
    die('Business session not found. Please login again.');
}

function stockAdjustmentPermission(mysqli $conn, string $action): bool
{
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin') return true;
    $map=['open'=>'can_open','view'=>'can_view','create'=>'can_create','update'=>'can_update'];
    $field=$map[$action]??''; if($field==='') return false;
    foreach (['perm.inventory.stock_adjustment','perm.inventory.stock','perm.inventory'] as $key) {
        if (isset($_SESSION['permissions'][$key][$field])) return (int)$_SESSION['permissions'][$key][$field]===1;
    }
    $businessId=(int)($_SESSION['business_id']??0); $roleId=(int)($_SESSION['role_id']??0);
    if($businessId<=0||$roleId<=0) return false;
    $sql="SELECT rp.`{$field}` FROM role_permissions rp INNER JOIN permissions p ON p.id=rp.permission_id WHERE rp.business_id=? AND rp.role_id=? AND p.is_active=1 AND p.permission_code IN ('perm.inventory.stock_adjustment','perm.inventory.stock','perm.inventory') ORDER BY FIELD(p.permission_code,'perm.inventory.stock_adjustment','perm.inventory.stock','perm.inventory') LIMIT 1";
    $stmt=$conn->prepare($sql); if(!$stmt) return false; $stmt->bind_param('ii',$businessId,$roleId); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc(); $stmt->close(); return (int)($row[$field]??0)===1;
}
if (!stockAdjustmentPermission($conn,'open')) { http_response_code(403); die('Access denied.'); }
$canView=stockAdjustmentPermission($conn,'view')||stockAdjustmentPermission($conn,'open');
$canCreate=stockAdjustmentPermission($conn,'create')||stockAdjustmentPermission($conn,'update');

/* -------------------------------------------------------
   REQUIRED TABLES
------------------------------------------------------- */
if (!tableExists($conn, 'products')) {
    die('Required table `products` not found. Please import the SQL first.');
}

if (!tableExists($conn, 'stock_movements')) {
    die('Required table `stock_movements` not found. Please import the SQL first.');
}

if (!tableExists($conn, 'product_stock')) {
    die('Required table `product_stock` not found. Please import the SQL first.');
}

/* -------------------------------------------------------
   COLUMN CHECKS
------------------------------------------------------- */
$prodHasBusinessId      = hasColumn($conn, 'products', 'business_id');
$prodHasProductCode     = hasColumn($conn, 'products', 'product_code');
$prodHasBarcode         = hasColumn($conn, 'products', 'barcode');
$prodHasPurity          = hasColumn($conn, 'products', 'purity');
$prodHasUnit            = hasColumn($conn, 'products', 'unit');
$prodHasCurrentStockQty = hasColumn($conn, 'products', 'current_stock_qty');
$prodHasIsActive        = hasColumn($conn, 'products', 'is_active');
$prodHasUpdatedAt       = hasColumn($conn, 'products', 'updated_at');

$psHasBusinessId        = hasColumn($conn, 'product_stock', 'business_id');
$psHasProductId         = hasColumn($conn, 'product_stock', 'product_id');
$psHasOpeningQty        = hasColumn($conn, 'product_stock', 'opening_qty');
$psHasOpeningWeight     = hasColumn($conn, 'product_stock', 'opening_weight');
$psHasInQty             = hasColumn($conn, 'product_stock', 'in_qty');
$psHasInWeight          = hasColumn($conn, 'product_stock', 'in_weight');
$psHasOutQty            = hasColumn($conn, 'product_stock', 'out_qty');
$psHasOutWeight         = hasColumn($conn, 'product_stock', 'out_weight');
$psHasClosingQty        = hasColumn($conn, 'product_stock', 'closing_qty');
$psHasClosingWeight     = hasColumn($conn, 'product_stock', 'closing_weight');
$psHasUpdatedAt         = hasColumn($conn, 'product_stock', 'updated_at');

$smHasBusinessId        = hasColumn($conn, 'stock_movements', 'business_id');
$smHasMovementDate      = hasColumn($conn, 'stock_movements', 'movement_date');
$smHasProductId         = hasColumn($conn, 'stock_movements', 'product_id');
$smHasMovementType      = hasColumn($conn, 'stock_movements', 'movement_type');
$smHasRefTable          = hasColumn($conn, 'stock_movements', 'ref_table');
$smHasRefId             = hasColumn($conn, 'stock_movements', 'ref_id');
$smHasQtyIn             = hasColumn($conn, 'stock_movements', 'qty_in');
$smHasQtyOut            = hasColumn($conn, 'stock_movements', 'qty_out');
$smHasWeightIn          = hasColumn($conn, 'stock_movements', 'weight_in');
$smHasWeightOut         = hasColumn($conn, 'stock_movements', 'weight_out');
$smHasRemarks           = hasColumn($conn, 'stock_movements', 'remarks');
$smHasCreatedBy         = hasColumn($conn, 'stock_movements', 'created_by');
$smHasCreatedAt         = hasColumn($conn, 'stock_movements', 'created_at');

/* -------------------------------------------------------
   HELPERS
------------------------------------------------------- */
function addAuditLogSafe(mysqli $conn, int $businessId, int $userId, string $module, string $action, int $refId, string $desc): void
{
    if (function_exists('addAuditLog')) {
        addAuditLog($conn, $businessId, $userId, $module, $action, $refId, $desc);
        return;
    }

    if (!tableExists($conn, 'audit_logs')) {
        return;
    }

    $columns = [];
    $placeholders = [];
    $types = '';
    $values = [];

    if (hasColumn($conn, 'audit_logs', 'business_id')) {
        $columns[] = 'business_id';
        $placeholders[] = '?';
        $types .= 'i';
        $values[] = $businessId;
    }

    if (hasColumn($conn, 'audit_logs', 'user_id')) {
        $columns[] = 'user_id';
        $placeholders[] = '?';
        $types .= 'i';
        $values[] = $userId;
    }

    if (hasColumn($conn, 'audit_logs', 'module_name')) {
        $columns[] = 'module_name';
        $placeholders[] = '?';
        $types .= 's';
        $values[] = $module;
    }

    if (hasColumn($conn, 'audit_logs', 'action_type')) {
        $columns[] = 'action_type';
        $placeholders[] = '?';
        $types .= 's';
        $values[] = $action;
    }

    if (hasColumn($conn, 'audit_logs', 'reference_id')) {
        $columns[] = 'reference_id';
        $placeholders[] = '?';
        $types .= 'i';
        $values[] = $refId;
    }

    if (hasColumn($conn, 'audit_logs', 'description')) {
        $columns[] = 'description';
        $placeholders[] = '?';
        $types .= 's';
        $values[] = $desc;
    }

    if (hasColumn($conn, 'audit_logs', 'ip_address')) {
        $columns[] = 'ip_address';
        $placeholders[] = '?';
        $types .= 's';
        $values[] = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    }

    if (hasColumn($conn, 'audit_logs', 'user_agent')) {
        $columns[] = 'user_agent';
        $placeholders[] = '?';
        $types .= 's';
        $values[] = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    }

    if (empty($columns)) {
        return;
    }

    $sql = "INSERT INTO audit_logs (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return;
    }

    if ($types !== '') {
        $bindParams = [];
        $bindParams[] = $types;

        foreach ($values as $k => $v) {
            $bindParams[] = &$values[$k];
        }

        call_user_func_array([$stmt, 'bind_param'], $bindParams);
    }

    $stmt->execute();
    $stmt->close();
}

function ensureProductStockRow(mysqli $conn, int $businessId, int $productId, bool $psHasBusinessId): bool
{
    if ($psHasBusinessId) {
        $stmt = $conn->prepare("SELECT id FROM product_stock WHERE product_id = ? AND business_id = ? LIMIT 1");
    } else {
        $stmt = $conn->prepare("SELECT id FROM product_stock WHERE product_id = ? LIMIT 1");
    }

    if (!$stmt) {
        return false;
    }

    if ($psHasBusinessId) {
        $stmt->bind_param('ii', $productId, $businessId);
    } else {
        $stmt->bind_param('i', $productId);
    }

    $stmt->execute();
    $res = $stmt->get_result();
    $exists = $res && $res->fetch_assoc();
    $stmt->close();

    if ($exists) {
        return true;
    }

    $columns = [];
    $placeholders = [];
    $types = '';
    $values = [];

    if ($psHasBusinessId && hasColumn($conn, 'product_stock', 'business_id')) {
        $columns[] = 'business_id';
        $placeholders[] = '?';
        $types .= 'i';
        $values[] = $businessId;
    }

    if (hasColumn($conn, 'product_stock', 'product_id')) {
        $columns[] = 'product_id';
        $placeholders[] = '?';
        $types .= 'i';
        $values[] = $productId;
    }

    $zeroColumns = [
        'opening_qty',
        'opening_weight',
        'in_qty',
        'in_weight',
        'out_qty',
        'out_weight',
        'closing_qty',
        'closing_weight'
    ];

    foreach ($zeroColumns as $col) {
        if (hasColumn($conn, 'product_stock', $col)) {
            $columns[] = $col;
            $placeholders[] = '0';
        }
    }

    if (empty($columns)) {
        return false;
    }

    $sql = "INSERT INTO product_stock (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return false;
    }

    if ($types !== '') {
        $bindParams = [];
        $bindParams[] = $types;

        foreach ($values as $k => $v) {
            $bindParams[] = &$values[$k];
        }

        call_user_func_array([$stmt, 'bind_param'], $bindParams);
    }

    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

/* -------------------------------------------------------
   HANDLE SUBMIT - POST REDIRECT GET
------------------------------------------------------- */
$theme=['primary_color'=>'#d89416','primary_dark_color'=>'#b86a0b','primary_soft_color'=>'#fff6e5','sidebar_gradient_1'=>'#171c21','sidebar_gradient_2'=>'#20272d','sidebar_gradient_3'=>'#101419','page_background'=>'#f4f3f0','card_background'=>'#ffffff','text_color'=>'#171717','muted_text_color'=>'#7d8794','border_color'=>'#e8e8e8','font_family'=>'Inter','heading_font_family'=>'Playfair Display','border_radius_px'=>12,'sidebar_width_px'=>230];
$themeStmt=$conn->prepare('SELECT * FROM business_theme_settings WHERE business_id=? LIMIT 1');
if($themeStmt){$themeStmt->bind_param('i',$businessId);$themeStmt->execute();$themeRow=$themeStmt->get_result()->fetch_assoc()?:[];$themeStmt->close();foreach($theme as $k=>$v){if(isset($themeRow[$k])&&$themeRow[$k]!=='')$theme[$k]=$themeRow[$k];}}
if(empty($_SESSION['stock_adjustment_csrf'])) $_SESSION['stock_adjustment_csrf']=bin2hex(random_bytes(32));
$csrfToken=$_SESSION['stock_adjustment_csrf'];
$businessName=(string)($_SESSION['business_name']??'Jewellery ERP');

/* -------------------------------------------------------
   PRODUCT OPTIONS
------------------------------------------------------- */
$productSql = "SELECT id, product_name";

if ($prodHasProductCode) {
    $productSql .= ", product_code";
}

if ($prodHasBarcode) {
    $productSql .= ", barcode";
}

if ($prodHasUnit) {
    $productSql .= ", unit";
}

if ($prodHasCurrentStockQty) {
    $productSql .= ", current_stock_qty";
}

$productSql .= " FROM products WHERE 1=1";

$productParams = [];
$productTypes = '';

if ($prodHasBusinessId) {
    $productSql .= " AND business_id = ?";
    $productParams[] = $businessId;
    $productTypes .= 'i';
}

if ($prodHasIsActive) {
    $productSql .= " AND is_active = 1";
}

$productSql .= " ORDER BY product_name ASC";

$products = [];

$stmt = $conn->prepare($productSql);

if ($stmt) {
    if (!empty($productParams)) {
        $bindValues = [];
        $bindValues[] = $productTypes;

        for ($i = 0; $i < count($productParams); $i++) {
            $bindValues[] = &$productParams[$i];
        }

        call_user_func_array([$stmt, 'bind_param'], $bindValues);
    }

    $stmt->execute();
    $res = $stmt->get_result();

    while ($res && $row = $res->fetch_assoc()) {
        $products[] = $row;
    }

    $stmt->close();
}

/* -------------------------------------------------------
   FORM DEFAULTS - GET ONLY AFTER REDIRECT
------------------------------------------------------- */
$selectedProductId = (int)($_GET['product_id'] ?? 0);
$adjustmentMode = 'add';
$adjustmentQty = '';
$adjustmentWeight = '';
$remarks = '';
$movementDate = date('Y-m-d\TH:i');

/* -------------------------------------------------------
   SELECTED PRODUCT DETAILS
------------------------------------------------------- */
$selectedProduct = null;

if ($selectedProductId > 0) {
    $stmt = $conn->prepare("
        SELECT
            p.id,
            p.product_name,
            " . ($prodHasProductCode ? "p.product_code," : "'' AS product_code,") . "
            " . ($prodHasBarcode ? "p.barcode," : "'' AS barcode,") . "
            " . ($prodHasPurity ? "p.purity," : "'' AS purity,") . "
            " . ($prodHasUnit ? "p.unit," : "'pcs' AS unit,") . "
            " . ($prodHasCurrentStockQty ? "IFNULL(p.current_stock_qty, 0) AS current_stock_qty," : "0 AS current_stock_qty,") . "
            IFNULL(ps.opening_qty, 0) AS opening_qty,
            IFNULL(ps.in_qty, 0) AS in_qty,
            IFNULL(ps.out_qty, 0) AS out_qty,
            IFNULL(ps.closing_qty, 0) AS closing_qty,
            IFNULL(ps.closing_weight, 0) AS closing_weight
        FROM products p
        LEFT JOIN product_stock ps ON ps.product_id = p.id
            " . ($psHasBusinessId && $prodHasBusinessId ? "AND ps.business_id = p.business_id" : "") . "
        WHERE p.id = ?
        " . ($prodHasBusinessId ? "AND p.business_id = ?" : "") . "
        LIMIT 1
    ");

    if ($stmt) {
        if ($prodHasBusinessId) {
            $stmt->bind_param('ii', $selectedProductId, $businessId);
        } else {
            $stmt->bind_param('i', $selectedProductId);
        }

        $stmt->execute();
        $res = $stmt->get_result();
        $selectedProduct = $res ? $res->fetch_assoc() : null;
        $stmt->close();
    }
}

/* -------------------------------------------------------
   RECENT ADJUSTMENTS
------------------------------------------------------- */
$recentAdjustments = [];

$whereRecent = " WHERE 1=1 ";
$recentParams = [];
$recentTypes = '';

if ($smHasBusinessId) {
    $whereRecent .= " AND sm.business_id = ? ";
    $recentParams[] = $businessId;
    $recentTypes .= 'i';
}

$adjustmentFilters = [];

if ($smHasMovementType) {
    $adjustmentFilters[] = "sm.movement_type = 'Adjustment'";
    $adjustmentFilters[] = "sm.movement_type = 'Manual'";
}

if ($smHasRefTable) {
    $adjustmentFilters[] = "sm.ref_table = 'stock_adjustment'";
}

if ($smHasRemarks) {
    $adjustmentFilters[] = "sm.remarks LIKE '%stock adjustment%'";
    $adjustmentFilters[] = "sm.remarks LIKE '%Manual stock adjustment%'";
}

if (!empty($adjustmentFilters)) {
    $whereRecent .= " AND (" . implode(" OR ", $adjustmentFilters) . ") ";
}

$sql = "
    SELECT
        sm.id,
        " . ($smHasMovementDate ? "sm.movement_date," : "sm.created_at AS movement_date,") . "
        " . ($smHasMovementType ? "sm.movement_type," : "'' AS movement_type,") . "
        " . ($smHasQtyIn ? "sm.qty_in," : "0 AS qty_in,") . "
        " . ($smHasQtyOut ? "sm.qty_out," : "0 AS qty_out,") . "
        " . ($smHasWeightIn ? "sm.weight_in," : "0 AS weight_in,") . "
        " . ($smHasWeightOut ? "sm.weight_out," : "0 AS weight_out,") . "
        " . ($smHasRemarks ? "sm.remarks," : "'' AS remarks,") . "
        p.product_name,
        " . ($prodHasProductCode ? "p.product_code," : "'' AS product_code,") . "
        " . ($prodHasUnit ? "p.unit" : "'pcs' AS unit") . "
    FROM stock_movements sm
    LEFT JOIN products p ON p.id = sm.product_id
    $whereRecent
    ORDER BY sm.id DESC
    LIMIT 10
";

$stmt = $conn->prepare($sql);

if ($stmt) {
    if (!empty($recentParams)) {
        $bindValues = [];
        $bindValues[] = $recentTypes;

        foreach ($recentParams as $k => $v) {
            $bindValues[] = &$recentParams[$k];
        }

        call_user_func_array([$stmt, 'bind_param'], $bindValues);
    }

    $stmt->execute();
    $res = $stmt->get_result();

    while ($res && $row = $res->fetch_assoc()) {
        $recentAdjustments[] = $row;
    }

    $stmt->close();
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo h($businessName); ?> - Stock Adjustment</title><?php include('includes/links.php'); ?><style>
:root{--primary:<?php echo h($theme['primary_color']); ?>;--primary-dark:<?php echo h($theme['primary_dark_color']); ?>;--primary-soft:<?php echo h($theme['primary_soft_color']); ?>;--page-bg:<?php echo h($theme['page_background']); ?>;--card-bg:<?php echo h($theme['card_background']); ?>;--text-color:<?php echo h($theme['text_color']); ?>;--muted-color:<?php echo h($theme['muted_text_color']); ?>;--border-color:<?php echo h($theme['border_color']); ?>;--radius:<?php echo (int)$theme['border_radius_px']; ?>px;--sidebar-width:<?php echo (int)$theme['sidebar_width_px']; ?>px}body{background:var(--page-bg);color:var(--text-color);font-family:<?php echo json_encode($theme['font_family']); ?>,sans-serif}.sidebar{background:linear-gradient(180deg,<?php echo h($theme['sidebar_gradient_1']); ?>,<?php echo h($theme['sidebar_gradient_2']); ?>,<?php echo h($theme['sidebar_gradient_3']); ?>)!important}.panel{background:var(--card-bg);border:1px solid var(--border-color);border-radius:var(--radius)}.muted{font-size:10px;color:var(--muted-color)}.panel{padding:14px;margin-bottom:10px}.panel-title{font-size:13px;font-weight:800;margin-bottom:12px}.form-control,.form-select{font-size:11px;min-height:36px;border-radius:9px;border-color:var(--border-color)}.btn-theme{background:linear-gradient(135deg,var(--primary),var(--primary-dark));color:#fff;border:0;border-radius:9px;font-size:11px;font-weight:700;padding:9px 14px}.table{font-size:10px;margin:0}.table th{color:var(--muted-color);text-transform:uppercase;font-size:9px}.stock-detail{display:grid;grid-template-columns:1fr 1fr;gap:8px}.detail{padding:10px;border:1px solid var(--border-color);border-radius:9px}.detail b{display:block;font-size:14px;margin-top:3px}.theme-toast{position:fixed;right:18px;top:78px;z-index:20000;padding:11px 14px;border-radius:10px;color:#fff;font-size:11px;font-weight:600;opacity:0;transform:translateY(-10px);transition:.2s}.theme-toast.show{opacity:1;transform:none}.theme-toast-success{background:#168449}.theme-toast-error{background:#c0392b}/* Keep the application top bar flush with the viewport. */
html,body{margin:0!important;padding:0!important}
body{min-height:100vh}
.app-main{margin-top:0!important;padding-top:0!important}
.app-main>.topbar,.app-main>header,.app-main>.navbar,.app-main>.nav-wrapper{margin-top:0!important;top:0!important}
.content-wrap{margin-top:0!important}
@media(max-width:767px){.stock-detail{grid-template-columns:1fr}.content-wrap{padding-left:10px;padding-right:10px}}
</style></head><body><?php include('includes/sidebar.php'); ?><main class="app-main"><?php include('includes/nav.php'); ?><div class="content-wrap">
<?php if(!$canView): ?><div class="panel text-center muted">You do not have permission to view stock adjustment.</div><?php else: ?>
<div class="row g-2"><div class="col-lg-7"><div class="panel"><div class="panel-title">Adjustment Entry</div><form id="adjustmentForm"><input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>"><input type="hidden" name="action" value="save"><div class="row g-3"><div class="col-12"><label class="form-label">Product *</label><select name="product_id" id="product_id" class="form-select" required><option value="0">Select Product</option><?php foreach($products as $product): ?><option value="<?php echo (int)$product['id']; ?>" <?php echo $selectedProductId===(int)$product['id']?'selected':''; ?>><?php echo h($product['product_name'].(!empty($product['product_code'])?' - '.$product['product_code']:'')); ?></option><?php endforeach; ?></select></div><div class="col-md-4"><label class="form-label">Mode *</label><select name="adjustment_mode" class="form-select"><option value="add">Add Stock</option><option value="subtract">Subtract Stock</option><option value="set">Set Exact Stock</option></select></div><div class="col-md-4"><label class="form-label">Quantity</label><input type="number" step="0.001" min="0" name="adjustment_qty" class="form-control"></div><div class="col-md-4"><label class="form-label">Weight</label><input type="number" step="0.001" min="0" name="adjustment_weight" class="form-control"></div><div class="col-md-6"><label class="form-label">Movement Date & Time</label><input type="datetime-local" name="movement_date" class="form-control" value="<?php echo h($movementDate); ?>"></div><div class="col-md-6"><label class="form-label">Remarks</label><input type="text" name="remarks" maxlength="500" class="form-control" placeholder="Reason for adjustment"></div><div class="col-12"><button class="btn btn-theme" id="saveButton" <?php echo !$canCreate?'disabled':''; ?>><i class="fa-solid fa-floppy-disk me-2"></i>Save Adjustment</button></div></div></form></div></div>
<div class="col-lg-5"><div class="panel"><div class="panel-title">Selected Product Stock</div><?php if($selectedProduct): ?><div class="mb-3"><b><?php echo h($selectedProduct['product_name']); ?></b><div class="muted"><?php echo h($selectedProduct['product_code']??''); ?> · <?php echo h($selectedProduct['purity']??''); ?></div></div><div class="stock-detail"><div class="detail"><span class="muted">Opening Qty</span><b><?php echo number_format((float)($selectedProduct['opening_qty']??0),3); ?></b></div><div class="detail"><span class="muted">Total In</span><b><?php echo number_format((float)($selectedProduct['in_qty']??0),3); ?></b></div><div class="detail"><span class="muted">Total Out</span><b><?php echo number_format((float)($selectedProduct['out_qty']??0),3); ?></b></div><div class="detail"><span class="muted">Closing Qty</span><b><?php echo number_format((float)($selectedProduct['closing_qty']??$selectedProduct['current_stock_qty']??0),3); ?></b></div><div class="detail"><span class="muted">Closing Weight</span><b><?php echo number_format((float)($selectedProduct['closing_weight']??0),3); ?></b></div><div class="detail"><span class="muted">Unit</span><b><?php echo h($selectedProduct['unit']??'pcs'); ?></b></div></div><?php else: ?><div class="muted">Select a product to see current stock details.</div><?php endif; ?></div></div></div>
<div class="panel"><div class="panel-title">Recent Adjustments</div><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Date</th><th>Product</th><th>Qty In</th><th>Qty Out</th><th>Weight In</th><th>Weight Out</th><th>Remarks</th></tr></thead><tbody><?php if($recentAdjustments): foreach($recentAdjustments as $row): ?><tr><td><?php echo !empty($row['movement_date'])?date('d-m-Y h:i A',strtotime($row['movement_date'])):'-'; ?></td><td><b><?php echo h($row['product_name']??''); ?></b><div class="muted"><?php echo h($row['product_code']??''); ?></div></td><td class="text-success"><?php echo number_format((float)($row['qty_in']??0),3); ?></td><td class="text-danger"><?php echo number_format((float)($row['qty_out']??0),3); ?></td><td class="text-success"><?php echo number_format((float)($row['weight_in']??0),3); ?></td><td class="text-danger"><?php echo number_format((float)($row['weight_out']??0),3); ?></td><td><?php echo h($row['remarks']??''); ?></td></tr><?php endforeach; else: ?><tr><td colspan="7" class="text-center muted py-4">No stock adjustments found.</td></tr><?php endif; ?></tbody></table></div></div><?php endif; ?><?php include('includes/footer.php'); ?></div></main><?php include('includes/script.php'); ?><script src="assets/js/script.js"></script><script>
(function(){'use strict';const form=document.getElementById('adjustmentForm');const product=document.getElementById('product_id');function toast(type,msg){const t=document.createElement('div');t.className='theme-toast theme-toast-'+type;t.textContent=msg;document.body.appendChild(t);requestAnimationFrame(()=>t.classList.add('show'));setTimeout(()=>{t.classList.remove('show');setTimeout(()=>t.remove(),250)},3000)}if(product)product.addEventListener('change',()=>{location.href='stock-adjustment.php'+(product.value!=='0'?'?product_id='+encodeURIComponent(product.value):'')});if(form)form.addEventListener('submit',async e=>{e.preventDefault();const b=document.getElementById('saveButton'),old=b.innerHTML;b.disabled=true;b.innerHTML='<i class="fa-solid fa-spinner fa-spin me-2"></i>Saving...';try{const r=await fetch('api/stock-adjustment-save.php',{method:'POST',body:new FormData(form),credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}});const j=await r.json().catch(()=>({success:false,message:'Invalid server response.'}));if(!r.ok||!j.success)throw new Error(j.message||'Unable to save adjustment.');toast('success',j.message);setTimeout(()=>location.href='stock-adjustment.php?product_id='+encodeURIComponent(j.product_id||product.value),500)}catch(err){toast('error',err.message)}finally{b.disabled=false;b.innerHTML=old}})})();
</script></body></html>
