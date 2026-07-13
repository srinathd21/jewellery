<?php
header('Content-Type: application/json; charset=utf-8');
function respond(bool $success,string $message,array $extra=[],int $status=200):void{http_response_code($status);echo json_encode(array_merge(['success'=>$success,'message'=>$message],$extra));exit;}

if (session_status() === PHP_SESSION_NONE) { session_start(); }
date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));

$configCandidates=[dirname(__DIR__).'/config/config.php',dirname(__DIR__).'/config.php',dirname(__DIR__).'/includes/config.php',dirname(__DIR__).'/super-admin/includes/config.php']; foreach($configCandidates as $configFile){if(is_file($configFile)){require_once $configFile;break;}}

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
    respond(false,'Your session has expired. Please log in again.',[],401);
}

$userId = (int)$_SESSION['user_id'];
$businessId = (int)($_SESSION['business_id'] ?? 0);

if ($businessId <= 0) {
    die('Business session not found. Please login again.');
}

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
if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(false,'Invalid request method.',[],405);
if (!hash_equals((string)($_SESSION['stock_adjustment_csrf']??''),(string)($_POST['csrf_token']??''))) respond(false,'Invalid or expired request token. Refresh the page and try again.',[],419);

function stockAdjustmentPermission(mysqli $conn,string $action):bool{if(($_SESSION['user_type']??'')==='Platform Admin')return true;$map=['create'=>'can_create','update'=>'can_update'];$field=$map[$action]??'';foreach(['perm.inventory.stock_adjustment','perm.inventory.stock','perm.inventory'] as $key){if(isset($_SESSION['permissions'][$key][$field]))return (int)$_SESSION['permissions'][$key][$field]===1;}$businessId=(int)($_SESSION['business_id']??0);$roleId=(int)($_SESSION['role_id']??0);if($businessId<=0||$roleId<=0)return false;$sql="SELECT rp.`{$field}` FROM role_permissions rp INNER JOIN permissions p ON p.id=rp.permission_id WHERE rp.business_id=? AND rp.role_id=? AND p.is_active=1 AND p.permission_code IN ('perm.inventory.stock_adjustment','perm.inventory.stock','perm.inventory') ORDER BY FIELD(p.permission_code,'perm.inventory.stock_adjustment','perm.inventory.stock','perm.inventory') LIMIT 1";$stmt=$conn->prepare($sql);if(!$stmt)return false;$stmt->bind_param('ii',$businessId,$roleId);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();return (int)($row[$field]??0)===1;}
if(!stockAdjustmentPermission($conn,'create')&&!stockAdjustmentPermission($conn,'update'))respond(false,'You do not have permission to create stock adjustments.',[],403);

if (($_POST['action'] ?? '') === 'save') {
    $selectedProductId = (int)($_POST['product_id'] ?? 0);
    $adjustmentMode = trim((string)($_POST['adjustment_mode'] ?? 'add'));
    $adjustmentQty = trim((string)($_POST['adjustment_qty'] ?? '0'));
    $adjustmentWeight = trim((string)($_POST['adjustment_weight'] ?? '0'));
    $remarks = trim((string)($_POST['remarks'] ?? ''));
    $movementDate = trim((string)($_POST['movement_date'] ?? date('Y-m-d\TH:i')));

    $qtyValue = is_numeric($adjustmentQty) ? (float)$adjustmentQty : 0;
    $weightValue = is_numeric($adjustmentWeight) ? (float)$adjustmentWeight : 0;

    $redirectProduct = $selectedProductId > 0 ? '&product_id=' . $selectedProductId : '';

    if ($selectedProductId <= 0) {
        respond(false, 'Please select a product.', [], 400);
    } elseif (!in_array($adjustmentMode, ['add', 'subtract', 'set'], true)) {
        respond(false, 'Invalid adjustment mode.', [], 400);
    } elseif ($qtyValue < 0 || $weightValue < 0) {
        respond(false, 'Quantity and weight cannot be negative.', [], 400);
    } elseif ($qtyValue == 0 && $weightValue == 0) {
        respond(false, 'Enter quantity or weight for adjustment.', [], 400);
    }

    $stmt = $conn->prepare("
        SELECT
            p.id,
            p.product_name,
            " . ($prodHasProductCode ? "p.product_code," : "'' AS product_code,") . "
            " . ($prodHasUnit ? "p.unit," : "'pcs' AS unit,") . "
            " . ($prodHasCurrentStockQty ? "IFNULL(p.current_stock_qty, 0) AS current_stock_qty," : "0 AS current_stock_qty,") . "
            IFNULL(ps.closing_qty, 0) AS ps_closing_qty,
            IFNULL(ps.closing_weight, 0) AS ps_closing_weight
        FROM products p
        LEFT JOIN product_stock ps ON ps.product_id = p.id
            " . ($psHasBusinessId && $prodHasBusinessId ? "AND ps.business_id = p.business_id" : "") . "
        WHERE p.id = ?
        " . ($prodHasBusinessId ? "AND p.business_id = ?" : "") . "
        LIMIT 1
    ");

    $productRow = null;

    if ($stmt) {
        if ($prodHasBusinessId) {
            $stmt->bind_param('ii', $selectedProductId, $businessId);
        } else {
            $stmt->bind_param('i', $selectedProductId);
        }

        $stmt->execute();
        $res = $stmt->get_result();
        $productRow = $res ? $res->fetch_assoc() : null;
        $stmt->close();
    }

    if (!$productRow) {
        respond(false, 'Selected product not found.', [], 404);
    }

    $currentQty = (float)($productRow['ps_closing_qty'] ?? 0);
    $currentWeight = (float)($productRow['ps_closing_weight'] ?? 0);

    if ($currentQty == 0 && $prodHasCurrentStockQty) {
        $currentQty = (float)($productRow['current_stock_qty'] ?? 0);
    }

    $newQty = $currentQty;
    $newWeight = $currentWeight;

    $qtyIn = 0.0;
    $qtyOut = 0.0;
    $weightIn = 0.0;
    $weightOut = 0.0;

    if ($adjustmentMode === 'add') {
        $newQty = $currentQty + $qtyValue;
        $newWeight = $currentWeight + $weightValue;
        $qtyIn = $qtyValue;
        $weightIn = $weightValue;
    } elseif ($adjustmentMode === 'subtract') {
        if ($qtyValue > $currentQty) {
            respond(false, 'Adjustment quantity cannot be greater than current stock quantity.', [], 409);
        }

        if ($weightValue > $currentWeight) {
            respond(false, 'Adjustment weight cannot be greater than current stock weight.', [], 409);
        }

        $newQty = $currentQty - $qtyValue;
        $newWeight = $currentWeight - $weightValue;
        $qtyOut = $qtyValue;
        $weightOut = $weightValue;
    } elseif ($adjustmentMode === 'set') {
        $newQty = $qtyValue;
        $newWeight = $weightValue;

        if ($newQty >= $currentQty) {
            $qtyIn = $newQty - $currentQty;
        } else {
            $qtyOut = $currentQty - $newQty;
        }

        if ($newWeight >= $currentWeight) {
            $weightIn = $newWeight - $currentWeight;
        } else {
            $weightOut = $currentWeight - $newWeight;
        }
    }

    $conn->begin_transaction();

    try {
        if (!ensureProductStockRow($conn, $businessId, $selectedProductId, $psHasBusinessId)) {
            throw new Exception('Unable to create stock row.');
        }

        $updateParts = [];
        $types = '';
        $values = [];

        if ($psHasInQty) {
            $updateParts[] = "in_qty = IFNULL(in_qty, 0) + ?";
            $types .= 'd';
            $values[] = $qtyIn;
        }

        if ($psHasInWeight) {
            $updateParts[] = "in_weight = IFNULL(in_weight, 0) + ?";
            $types .= 'd';
            $values[] = $weightIn;
        }

        if ($psHasOutQty) {
            $updateParts[] = "out_qty = IFNULL(out_qty, 0) + ?";
            $types .= 'd';
            $values[] = $qtyOut;
        }

        if ($psHasOutWeight) {
            $updateParts[] = "out_weight = IFNULL(out_weight, 0) + ?";
            $types .= 'd';
            $values[] = $weightOut;
        }

        if ($psHasClosingQty) {
            $updateParts[] = "closing_qty = ?";
            $types .= 'd';
            $values[] = $newQty;
        }

        if ($psHasClosingWeight) {
            $updateParts[] = "closing_weight = ?";
            $types .= 'd';
            $values[] = $newWeight;
        }

        if ($psHasUpdatedAt) {
            $updateParts[] = "updated_at = NOW()";
        }

        if (empty($updateParts)) {
            throw new Exception('No editable columns found in product_stock.');
        }

        $sql = "UPDATE product_stock SET " . implode(', ', $updateParts) . " WHERE product_id = ?";
        $types .= 'i';
        $values[] = $selectedProductId;

        if ($psHasBusinessId) {
            $sql .= " AND business_id = ?";
            $types .= 'i';
            $values[] = $businessId;
        }

        $sql .= " LIMIT 1";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            throw new Exception('Failed to prepare product_stock update: ' . $conn->error);
        }

        $bindValues = [];
        $bindValues[] = $types;

        foreach ($values as $k => $v) {
            $bindValues[] = &$values[$k];
        }

        call_user_func_array([$stmt, 'bind_param'], $bindValues);

        if (!$stmt->execute()) {
            $stmt->close();
            throw new Exception('Failed to update product_stock.');
        }

        $stmt->close();

        if ($prodHasCurrentStockQty) {
            $sql = "UPDATE products SET current_stock_qty = ?";

            if ($prodHasUpdatedAt) {
                $sql .= ", updated_at = NOW()";
            }

            $sql .= " WHERE id = ?";

            if ($prodHasBusinessId) {
                $sql .= " AND business_id = ?";
            }

            $sql .= " LIMIT 1";

            $stmt = $conn->prepare($sql);

            if (!$stmt) {
                throw new Exception('Failed to prepare products update.');
            }

            if ($prodHasBusinessId) {
                $stmt->bind_param('dii', $newQty, $selectedProductId, $businessId);
            } else {
                $stmt->bind_param('di', $newQty, $selectedProductId);
            }

            if (!$stmt->execute()) {
                $stmt->close();
                throw new Exception('Failed to update products stock.');
            }

            $stmt->close();
        }

        $movementType = 'Adjustment';
        $refTable = 'stock_adjustment';
        $refId = 0;
        $movementDateSql = date('Y-m-d H:i:s', strtotime($movementDate));

        $remarksText = trim($remarks);
        if ($remarksText === '') {
            $remarksText = 'Manual stock adjustment';
        }

        $columns = [];
        $placeholders = [];
        $types = '';
        $values = [];

        if ($smHasBusinessId) {
            $columns[] = 'business_id';
            $placeholders[] = '?';
            $types .= 'i';
            $values[] = $businessId;
        }

        if ($smHasMovementDate) {
            $columns[] = 'movement_date';
            $placeholders[] = '?';
            $types .= 's';
            $values[] = $movementDateSql;
        }

        if ($smHasProductId) {
            $columns[] = 'product_id';
            $placeholders[] = '?';
            $types .= 'i';
            $values[] = $selectedProductId;
        }

        if ($smHasMovementType) {
            $columns[] = 'movement_type';
            $placeholders[] = '?';
            $types .= 's';
            $values[] = $movementType;
        }

        if ($smHasRefTable) {
            $columns[] = 'ref_table';
            $placeholders[] = '?';
            $types .= 's';
            $values[] = $refTable;
        }

        if ($smHasRefId) {
            $columns[] = 'ref_id';
            $placeholders[] = '?';
            $types .= 'i';
            $values[] = $refId;
        }

        if ($smHasQtyIn) {
            $columns[] = 'qty_in';
            $placeholders[] = '?';
            $types .= 'd';
            $values[] = $qtyIn;
        }

        if ($smHasQtyOut) {
            $columns[] = 'qty_out';
            $placeholders[] = '?';
            $types .= 'd';
            $values[] = $qtyOut;
        }

        if ($smHasWeightIn) {
            $columns[] = 'weight_in';
            $placeholders[] = '?';
            $types .= 'd';
            $values[] = $weightIn;
        }

        if ($smHasWeightOut) {
            $columns[] = 'weight_out';
            $placeholders[] = '?';
            $types .= 'd';
            $values[] = $weightOut;
        }

        if ($smHasRemarks) {
            $columns[] = 'remarks';
            $placeholders[] = '?';
            $types .= 's';
            $values[] = $remarksText;
        }

        if ($smHasCreatedBy) {
            $columns[] = 'created_by';
            $placeholders[] = '?';
            $types .= 'i';
            $values[] = $userId;
        }

        if ($smHasCreatedAt) {
            $columns[] = 'created_at';
            $placeholders[] = 'NOW()';
        }

        if (empty($columns)) {
            throw new Exception('No columns found for stock movement insert.');
        }

        $sql = "INSERT INTO stock_movements (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            throw new Exception('Failed to prepare stock_movements insert: ' . $conn->error);
        }

        if ($types !== '') {
            $bindValues = [];
            $bindValues[] = $types;

            foreach ($values as $k => $v) {
                $bindValues[] = &$values[$k];
            }

            call_user_func_array([$stmt, 'bind_param'], $bindValues);
        }

        if (!$stmt->execute()) {
            $stmt->close();
            throw new Exception('Failed to insert stock movement.');
        }

        $movementInsertId = (int)$stmt->insert_id;
        $stmt->close();

        addAuditLogSafe(
            $conn,
            $businessId,
            $userId,
            'Stock Adjustment',
            'Create',
            $movementInsertId,
            'Stock adjusted for product ID ' . $selectedProductId
        );

        $conn->commit();

        respond(true, 'Stock adjustment saved successfully.', ['product_id' => $selectedProductId, 'movement_id' => $movementInsertId]);
    } catch (Throwable $e) {
        $conn->rollback();

        respond(false, 'Failed to save stock adjustment: ' . $e->getMessage(), [], 500);
    }
}

respond(false,'Invalid action.',[],400);
