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

$pageTitle = 'Stock Adjustment';
$page_title = 'Stock Adjustment';
$currentPage = 'stock-adjustment';

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
   FLASH MESSAGE
------------------------------------------------------- */
$success = '';
$error = '';

if (!empty($_SESSION['flash_success'])) {
    $success = (string)$_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

if (!empty($_SESSION['flash_error'])) {
    $error = (string)$_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_adjustment'])) {
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
        $_SESSION['flash_error'] = 'Please select a product.';
        header('Location: stock-adjustment.php' . ($redirectProduct ? '?product_id=' . $selectedProductId : ''));
        exit;
    } elseif (!in_array($adjustmentMode, ['add', 'subtract', 'set'], true)) {
        $_SESSION['flash_error'] = 'Invalid adjustment mode.';
        header('Location: stock-adjustment.php?product_id=' . $selectedProductId);
        exit;
    } elseif ($qtyValue < 0 || $weightValue < 0) {
        $_SESSION['flash_error'] = 'Quantity and weight cannot be negative.';
        header('Location: stock-adjustment.php?product_id=' . $selectedProductId);
        exit;
    } elseif ($qtyValue == 0 && $weightValue == 0) {
        $_SESSION['flash_error'] = 'Enter quantity or weight for adjustment.';
        header('Location: stock-adjustment.php?product_id=' . $selectedProductId);
        exit;
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
        $_SESSION['flash_error'] = 'Selected product not found.';
        header('Location: stock-adjustment.php');
        exit;
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
            $_SESSION['flash_error'] = 'Adjustment quantity cannot be greater than current stock quantity.';
            header('Location: stock-adjustment.php?product_id=' . $selectedProductId);
            exit;
        }

        if ($weightValue > $currentWeight) {
            $_SESSION['flash_error'] = 'Adjustment weight cannot be greater than current stock weight.';
            header('Location: stock-adjustment.php?product_id=' . $selectedProductId);
            exit;
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

        $_SESSION['flash_success'] = 'Stock adjustment saved successfully.';
        header('Location: stock-adjustment.php?msg=saved&product_id=' . $selectedProductId);
        exit;
    } catch (Throwable $e) {
        $conn->rollback();

        $_SESSION['flash_error'] = 'Failed to save stock adjustment: ' . $e->getMessage();
        header('Location: stock-adjustment.php?product_id=' . $selectedProductId);
        exit;
    }
}

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
    <title><?php echo h($businessName); ?> - Stock Adjustment</title>

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
            <h1 class="page-new-title">Stock Adjustment</h1>
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
                    <div class="col-lg-7">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Adjustment Entry</h5>
                            </div>
                            <div class="card-body">
                                <form method="post" action="stock-adjustment.php">
                                    <div class="row g-3">
                                        <div class="col-md-12">
                                            <label class="form-label">Product <span class="text-danger">*</span></label>
                                            <select
                                                name="product_id"
                                                class="form-select"
                                                required
                                                onchange="if(this.value && this.value !== '0'){ window.location.href='stock-adjustment.php?product_id=' + encodeURIComponent(this.value); }"
                                            >
                                                <option value="0">Select Product</option>
                                                <?php foreach ($products as $product): ?>
                                                    <option value="<?php echo (int)$product['id']; ?>" <?php echo $selectedProductId === (int)$product['id'] ? 'selected' : ''; ?>>
                                                        <?php
                                                        echo h(
                                                            $product['product_name']
                                                            . (!empty($product['product_code']) ? ' - ' . $product['product_code'] : '')
                                                            . (isset($product['current_stock_qty']) ? ' (Stock: ' . number_format((float)$product['current_stock_qty'], 3) . ')' : '')
                                                        );
                                                        ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label">Adjustment Mode <span class="text-danger">*</span></label>
                                            <select name="adjustment_mode" class="form-select" required>
                                                <option value="add">Add Stock</option>
                                                <option value="subtract">Subtract Stock</option>
                                                <option value="set">Set Exact Stock</option>
                                            </select>
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label">Quantity</label>
                                            <input
                                                type="number"
                                                step="0.001"
                                                min="0"
                                                name="adjustment_qty"
                                                class="form-control"
                                                value=""
                                                placeholder="Enter quantity"
                                            >
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label">Weight</label>
                                            <input
                                                type="number"
                                                step="0.001"
                                                min="0"
                                                name="adjustment_weight"
                                                class="form-control"
                                                value=""
                                                placeholder="Enter weight"
                                            >
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Movement Date & Time</label>
                                            <input
                                                type="datetime-local"
                                                name="movement_date"
                                                class="form-control"
                                                value="<?php echo h($movementDate); ?>"
                                            >
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Remarks</label>
                                            <input
                                                type="text"
                                                name="remarks"
                                                class="form-control"
                                                value=""
                                                placeholder="Reason for adjustment"
                                            >
                                        </div>

                                        <div class="col-12">
                                            <button type="submit" name="save_adjustment" value="1" class="btn btn-primary">
                                                Save Adjustment
                                            </button>
                                            <a href="stock-adjustment.php" class="btn btn-secondary">Reset</a>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-5">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Selected Product Stock</h5>
                            </div>
                            <div class="card-body">
                                <?php if ($selectedProduct): ?>
                                    <div class="table-responsive">
                                        <table class="table table-bordered mb-0">
                                            <tr>
                                                <th>Product</th>
                                                <td><?php echo h($selectedProduct['product_name'] ?? ''); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Code</th>
                                                <td><?php echo h($selectedProduct['product_code'] ?? ''); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Barcode</th>
                                                <td><?php echo h($selectedProduct['barcode'] ?? ''); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Purity</th>
                                                <td><?php echo h($selectedProduct['purity'] ?? ''); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Unit</th>
                                                <td><?php echo h($selectedProduct['unit'] ?? 'pcs'); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Opening Qty</th>
                                                <td><?php echo number_format((float)($selectedProduct['opening_qty'] ?? 0), 3); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Total In Qty</th>
                                                <td class="text-success"><?php echo number_format((float)($selectedProduct['in_qty'] ?? 0), 3); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Total Out Qty</th>
                                                <td class="text-danger"><?php echo number_format((float)($selectedProduct['out_qty'] ?? 0), 3); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Closing Qty</th>
                                                <td><strong><?php echo number_format((float)($selectedProduct['closing_qty'] ?? $selectedProduct['current_stock_qty'] ?? 0), 3); ?></strong></td>
                                            </tr>
                                            <tr>
                                                <th>Closing Weight</th>
                                                <td><strong><?php echo number_format((float)($selectedProduct['closing_weight'] ?? 0), 3); ?></strong></td>
                                            </tr>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="text-muted">Select a product to see current stock details.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Recent Adjustments</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Date & Time</th>
                                        <th>Product</th>
                                        <th>Qty In</th>
                                        <th>Qty Out</th>
                                        <th>Weight In</th>
                                        <th>Weight Out</th>
                                        <th>Remarks</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($recentAdjustments)): ?>
                                        <?php foreach ($recentAdjustments as $index => $row): ?>
                                            <tr>
                                                <td><?php echo $index + 1; ?></td>
                                                <td>
                                                    <?php
                                                    echo !empty($row['movement_date'])
                                                        ? date('d-m-Y h:i A', strtotime($row['movement_date']))
                                                        : '-';
                                                    ?>
                                                </td>
                                                <td>
                                                    <strong><?php echo h($row['product_name'] ?? ''); ?></strong>
                                                    <?php if (!empty($row['product_code'])): ?>
                                                        <br><small class="text-muted"><?php echo h($row['product_code']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-success">
                                                    <?php echo number_format((float)($row['qty_in'] ?? 0), 3); ?>
                                                    <small><?php echo h($row['unit'] ?? ''); ?></small>
                                                </td>
                                                <td class="text-danger">
                                                    <?php echo number_format((float)($row['qty_out'] ?? 0), 3); ?>
                                                    <small><?php echo h($row['unit'] ?? ''); ?></small>
                                                </td>
                                                <td class="text-success"><?php echo number_format((float)($row['weight_in'] ?? 0), 3); ?></td>
                                                <td class="text-danger"><?php echo number_format((float)($row['weight_out'] ?? 0), 3); ?></td>
                                                <td><?php echo h($row['remarks'] ?? ''); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="8" class="text-center text-muted">No stock adjustments found.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

        <?php include('includes/footer.php'); ?>
    </div>
</main>

<?php include('includes/script.php'); ?>
<script src="assets/js/script.js"></script>

</body>
</html>
