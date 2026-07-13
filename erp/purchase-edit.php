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
    function h($value): string {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('tableExists')) {
    function tableExists(mysqli $conn, string $tableName): bool {
        $safe = $conn->real_escape_string($tableName);
        $res = $conn->query("SHOW TABLES LIKE '{$safe}'");
        return $res && $res->num_rows > 0;
    }
}

if (!function_exists('hasColumn')) {
    function hasColumn(mysqli $conn, string $table, string $column): bool {
        $table = $conn->real_escape_string($table);
        $column = $conn->real_escape_string($column);
        $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
        return $res && $res->num_rows > 0;
    }
}

if (!function_exists('addAuditLog')) {
    function addAuditLog(mysqli $conn, ?int $businessId, ?int $userId, string $module, string $action, ?int $referenceId, string $description): void
    {
        if (!tableExists($conn, 'audit_logs')) {
            return;
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $stmt = $conn->prepare("
            INSERT INTO audit_logs (business_id, user_id, module_name, action_type, reference_id, description, ip_address, user_agent, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        if ($stmt) {
            $stmt->bind_param(
                'iississs',
                $businessId,
                $userId,
                $module,
                $action,
                $referenceId,
                $description,
                $ip,
                $ua
            );
            $stmt->execute();
            $stmt->close();
        }
    }
}

function moneyf($amount): string {
    return number_format((float)$amount, 2, '.', '');
}

function qtyf($qty): string {
    return number_format((float)$qty, 3, '.', '');
}

function getProductRow(mysqli $conn, int $productId, int $businessId, bool $prdHasBusinessId): ?array
{
    $sql = "SELECT p.* FROM products p WHERE p.id = ?";

    if ($prdHasBusinessId) {
        $sql .= " AND p.business_id = ?";
    }

    $sql .= " LIMIT 1";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return null;
    }

    if ($prdHasBusinessId) {
        $stmt->bind_param('ii', $productId, $businessId);
    } else {
        $stmt->bind_param('i', $productId);
    }

    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    return $row ?: null;
}

function updateProductStockQty(mysqli $conn, int $productId, int $businessId, bool $prdHasBusinessId, bool $prdHasCurrentStockQty, float $qtyDelta): void
{
    if (!$prdHasCurrentStockQty || $productId <= 0 || $qtyDelta == 0.0) {
        return;
    }

    if ($prdHasBusinessId) {
        $stmt = $conn->prepare("UPDATE products SET current_stock_qty = COALESCE(current_stock_qty, 0) + ? WHERE id = ? AND business_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('dii', $qtyDelta, $productId, $businessId);
            $stmt->execute();
            $stmt->close();
        }
    } else {
        $stmt = $conn->prepare("UPDATE products SET current_stock_qty = COALESCE(current_stock_qty, 0) + ? WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('di', $qtyDelta, $productId);
            $stmt->execute();
            $stmt->close();
        }
    }
}

function updateProductStockTable(
    mysqli $conn,
    int $productId,
    int $businessId,
    bool $productStockExists,
    bool $productStockHasBiz,
    bool $productStockHasProduct,
    bool $productStockHasInQty,
    bool $productStockHasInWt,
    bool $productStockHasCloseQty,
    bool $productStockHasCloseWt,
    float $qtyDelta,
    float $weightDelta
): void {
    if (!$productStockExists || !$productStockHasProduct || $productId <= 0 || ($qtyDelta == 0.0 && $weightDelta == 0.0)) {
        return;
    }

    if ($productStockHasBiz) {
        $stmt = $conn->prepare("SELECT id FROM product_stock WHERE product_id = ? AND business_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('ii', $productId, $businessId);
        }
    } else {
        $stmt = $conn->prepare("SELECT id FROM product_stock WHERE product_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $productId);
        }
    }

    $stockRow = null;

    if ($stmt) {
        $stmt->execute();
        $res = $stmt->get_result();
        $stockRow = $res ? $res->fetch_assoc() : null;
        $stmt->close();
    }

    if ($stockRow) {
        $updates = [];
        $types = '';
        $values = [];

        if ($productStockHasInQty) {
            $updates[] = "in_qty = GREATEST(COALESCE(in_qty, 0) + ?, 0)";
            $types .= 'd';
            $values[] = $qtyDelta;
        }

        if ($productStockHasInWt) {
            $updates[] = "in_weight = GREATEST(COALESCE(in_weight, 0) + ?, 0)";
            $types .= 'd';
            $values[] = $weightDelta;
        }

        if ($productStockHasCloseQty) {
            $updates[] = "closing_qty = GREATEST(COALESCE(closing_qty, 0) + ?, 0)";
            $types .= 'd';
            $values[] = $qtyDelta;
        }

        if ($productStockHasCloseWt) {
            $updates[] = "closing_weight = GREATEST(COALESCE(closing_weight, 0) + ?, 0)";
            $types .= 'd';
            $values[] = $weightDelta;
        }

        if (!empty($updates)) {
            $sql = "UPDATE product_stock SET " . implode(', ', $updates) . " WHERE id = ?";
            $types .= 'i';
            $values[] = (int)$stockRow['id'];

            $stmt = $conn->prepare($sql);

            if ($stmt) {
                $bindValues = [];
                $bindValues[] = $types;

                for ($i = 0; $i < count($values); $i++) {
                    $bindValues[] = &$values[$i];
                }

                call_user_func_array([$stmt, 'bind_param'], $bindValues);
                $stmt->execute();
                $stmt->close();
            }
        }
    } else {
        $fields = [];
        $placeholders = [];
        $types = '';
        $values = [];

        if ($productStockHasBiz) {
            $fields[] = 'business_id';
            $placeholders[] = '?';
            $types .= 'i';
            $values[] = $businessId;
        }

        if ($productStockHasProduct) {
            $fields[] = 'product_id';
            $placeholders[] = '?';
            $types .= 'i';
            $values[] = $productId;
        }

        if ($productStockHasInQty) {
            $fields[] = 'in_qty';
            $placeholders[] = '?';
            $types .= 'd';
            $values[] = max($qtyDelta, 0);
        }

        if ($productStockHasInWt) {
            $fields[] = 'in_weight';
            $placeholders[] = '?';
            $types .= 'd';
            $values[] = max($weightDelta, 0);
        }

        if ($productStockHasCloseQty) {
            $fields[] = 'closing_qty';
            $placeholders[] = '?';
            $types .= 'd';
            $values[] = max($qtyDelta, 0);
        }

        if ($productStockHasCloseWt) {
            $fields[] = 'closing_weight';
            $placeholders[] = '?';
            $types .= 'd';
            $values[] = max($weightDelta, 0);
        }

        if (!empty($fields)) {
            $sql = "INSERT INTO product_stock (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
            $stmt = $conn->prepare($sql);

            if ($stmt) {
                $bindValues = [];
                $bindValues[] = $types;

                for ($i = 0; $i < count($values); $i++) {
                    $bindValues[] = &$values[$i];
                }

                call_user_func_array([$stmt, 'bind_param'], $bindValues);
                $stmt->execute();
                $stmt->close();
            }
        }
    }
}


function updateCurrentProductStock(
    mysqli $conn,
    int $businessId,
    int $branchId,
    int $productId,
    float $quantityDelta,
    float $grossWeightDelta,
    float $netWeightDelta,
    float $valueDelta
): void {
    if ($productId <= 0 || !tableExists($conn, 'product_stock')) {
        return;
    }

    $stmt = $conn->prepare("
        SELECT id, quantity, gross_weight, net_weight, stock_value
        FROM product_stock
        WHERE business_id = ?
          AND branch_id = ?
          AND product_id = ?
        LIMIT 1
        FOR UPDATE
    ");

    if (!$stmt) {
        throw new Exception('Failed to prepare product stock lookup: ' . $conn->error);
    }

    $stmt->bind_param('iii', $businessId, $branchId, $productId);
    $stmt->execute();
    $stock = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($stock) {
        $newQuantity = max(0, (float)$stock['quantity'] + $quantityDelta);
        $newGrossWeight = max(0, (float)$stock['gross_weight'] + $grossWeightDelta);
        $newNetWeight = max(0, (float)$stock['net_weight'] + $netWeightDelta);
        $newStockValue = max(0, (float)$stock['stock_value'] + $valueDelta);
        $newAverageCost = $newQuantity > 0 ? $newStockValue / $newQuantity : 0.0;
        $stockId = (int)$stock['id'];

        $stmt = $conn->prepare("
            UPDATE product_stock
            SET quantity = ?,
                gross_weight = ?,
                net_weight = ?,
                average_cost = ?,
                stock_value = ?
            WHERE id = ?
        ");

        if (!$stmt) {
            throw new Exception('Failed to prepare product stock update: ' . $conn->error);
        }

        $stmt->bind_param(
            'dddddi',
            $newQuantity,
            $newGrossWeight,
            $newNetWeight,
            $newAverageCost,
            $newStockValue,
            $stockId
        );

        if (!$stmt->execute()) {
            throw new Exception('Failed to update product stock: ' . $stmt->error);
        }

        $stmt->close();
        return;
    }

    if ($quantityDelta <= 0 && $grossWeightDelta <= 0 && $netWeightDelta <= 0 && $valueDelta <= 0) {
        return;
    }

    $newQuantity = max(0, $quantityDelta);
    $newGrossWeight = max(0, $grossWeightDelta);
    $newNetWeight = max(0, $netWeightDelta);
    $newStockValue = max(0, $valueDelta);
    $newAverageCost = $newQuantity > 0 ? $newStockValue / $newQuantity : 0.0;

    $stmt = $conn->prepare("
        INSERT INTO product_stock
        (
            business_id,
            branch_id,
            product_id,
            quantity,
            gross_weight,
            net_weight,
            average_cost,
            stock_value
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        throw new Exception('Failed to prepare product stock insert: ' . $conn->error);
    }

    $stmt->bind_param(
        'iiiddddd',
        $businessId,
        $branchId,
        $productId,
        $newQuantity,
        $newGrossWeight,
        $newNetWeight,
        $newAverageCost,
        $newStockValue
    );

    if (!$stmt->execute()) {
        throw new Exception('Failed to insert product stock: ' . $stmt->error);
    }

    $stmt->close();
}

function insertPurchaseStockMovement(
    mysqli $conn,
    int $businessId,
    int $branchId,
    int $productId,
    int $purchaseId,
    float $quantity,
    float $netWeight,
    float $rate,
    float $valueAmount,
    int $userId,
    string $remarks
): void {
    if ($productId <= 0 || !tableExists($conn, 'stock_movements')) {
        return;
    }

    $stmt = $conn->prepare("
        INSERT INTO stock_movements
        (
            business_id,
            branch_id,
            product_id,
            movement_date,
            movement_type,
            reference_table,
            reference_id,
            quantity_in,
            quantity_out,
            weight_in,
            weight_out,
            rate,
            value_amount,
            remarks,
            created_by
        )
        VALUES
        (
            ?, ?, ?, NOW(), 'Purchase', 'purchases', ?,
            ?, 0, ?, 0, ?, ?, ?, ?
        )
    ");

    if (!$stmt) {
        throw new Exception('Failed to prepare stock movement insert: ' . $conn->error);
    }

    $stmt->bind_param(
        'iiiiddddsi',
        $businessId,
        $branchId,
        $productId,
        $purchaseId,
        $quantity,
        $netWeight,
        $rate,
        $valueAmount,
        $remarks,
        $userId
    );

    if (!$stmt->execute()) {
        throw new Exception('Failed to insert stock movement: ' . $stmt->error);
    }

    $stmt->close();
}

$pageTitle = 'Edit Purchase';
$page_title = 'Edit Purchase';
$currentPage = 'purchases';

/* -------------------------------------------------------
   AUTH CHECK
------------------------------------------------------- */
if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    header('Location: ../login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? 0);

if ($businessId <= 0) {
    die('Business session not found. Please login again.');
}

/* -------------------------------------------------------
   ROLE / PERMISSION CHECK
------------------------------------------------------- */
$roleName = strtolower(trim((string)($_SESSION['role_name'] ?? '')));
$roleCode = strtolower(trim((string)($_SESSION['role_code'] ?? '')));
$userType = (string)($_SESSION['user_type'] ?? '');

$allowedByRole = (
    $userType === 'Platform Admin'
    || in_array($roleName, ['admin', 'business admin', 'manager', 'stock'], true)
    || in_array($roleCode, ['admin', 'business_admin', 'manager', 'stock'], true)
);

$allowedByPermission = false;
$sessionPermissions = $_SESSION['permissions'] ?? [];
foreach (['perm.purchases', 'perm.purchase'] as $permissionCode) {
    if (
        isset($sessionPermissions[$permissionCode])
        && (
            (int)($sessionPermissions[$permissionCode]['can_open'] ?? 0) === 1
            || (int)($sessionPermissions[$permissionCode]['can_update'] ?? 0) === 1
        )
    ) {
        $allowedByPermission = true;
        break;
    }
}

if (!$allowedByRole && !$allowedByPermission) {
    http_response_code(403);
    die('Access denied. You do not have permission to edit purchases.');
}

/* -------------------------------------------------------
   REQUIRED TABLES
------------------------------------------------------- */
if (!tableExists($conn, 'purchases') || !tableExists($conn, 'purchase_items') || !tableExists($conn, 'suppliers')) {
    die('Required tables not found. Please import the SQL first.');
}

/* -------------------------------------------------------
   COLUMN CHECKS
------------------------------------------------------- */
$purHasBusinessId      = hasColumn($conn, 'purchases', 'business_id');
$pitHasBusinessId      = hasColumn($conn, 'purchase_items', 'business_id');
$supHasBusinessId      = hasColumn($conn, 'suppliers', 'business_id');
$prdHasBusinessId      = tableExists($conn, 'products') && hasColumn($conn, 'products', 'business_id');
$prdHasCurrentStockQty = tableExists($conn, 'products') && hasColumn($conn, 'products', 'current_stock_qty');

$productStockExists      = tableExists($conn, 'product_stock');
$stockMovementExists     = tableExists($conn, 'stock_movements');

$productStockHasBiz      = $productStockExists && hasColumn($conn, 'product_stock', 'business_id');
$productStockHasProduct  = $productStockExists && hasColumn($conn, 'product_stock', 'product_id');
$productStockHasInQty    = $productStockExists && hasColumn($conn, 'product_stock', 'in_qty');
$productStockHasInWt     = $productStockExists && hasColumn($conn, 'product_stock', 'in_weight');
$productStockHasCloseQty = $productStockExists && hasColumn($conn, 'product_stock', 'closing_qty');
$productStockHasCloseWt  = $productStockExists && hasColumn($conn, 'product_stock', 'closing_weight');

$stockMoveHasBiz       = $stockMovementExists && hasColumn($conn, 'stock_movements', 'business_id');
$stockMoveHasDate      = $stockMovementExists && hasColumn($conn, 'stock_movements', 'movement_date');
$stockMoveHasProductId = $stockMovementExists && hasColumn($conn, 'stock_movements', 'product_id');
$stockMoveHasType      = $stockMovementExists && hasColumn($conn, 'stock_movements', 'movement_type');
$stockMoveHasRefTable  = $stockMovementExists && hasColumn($conn, 'stock_movements', 'ref_table');
$stockMoveHasRefId     = $stockMovementExists && hasColumn($conn, 'stock_movements', 'ref_id');
$stockMoveHasQtyIn     = $stockMovementExists && hasColumn($conn, 'stock_movements', 'qty_in');
$stockMoveHasWeightIn  = $stockMovementExists && hasColumn($conn, 'stock_movements', 'weight_in');
$stockMoveHasRemarks   = $stockMovementExists && hasColumn($conn, 'stock_movements', 'remarks');
$stockMoveHasCreatedBy = $stockMovementExists && hasColumn($conn, 'stock_movements', 'created_by');

$paymentMethodExists = tableExists($conn, 'payment_methods');

/* -------------------------------------------------------
   PURCHASE ID
------------------------------------------------------- */
$purchaseId = 0;

if (isset($_GET['id']) && (int)$_GET['id'] > 0) {
    $purchaseId = (int)$_GET['id'];
} elseif (isset($_GET['purchase_id']) && (int)$_GET['purchase_id'] > 0) {
    $purchaseId = (int)$_GET['purchase_id'];
} elseif (isset($_POST['purchase_id']) && (int)$_POST['purchase_id'] > 0) {
    $purchaseId = (int)$_POST['purchase_id'];
}

if ($purchaseId <= 0) {
    header('Location: purchases.php');
    exit;
}

/* -------------------------------------------------------
   LOAD PURCHASE
------------------------------------------------------- */
$sql = "SELECT * FROM purchases WHERE id = ?";

if ($purHasBusinessId) {
    $sql .= " AND business_id = ?";
}

$sql .= " LIMIT 1";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die('Failed to prepare purchase query: ' . $conn->error);
}

if ($purHasBusinessId) {
    $stmt->bind_param('ii', $purchaseId, $businessId);
} else {
    $stmt->bind_param('i', $purchaseId);
}

$stmt->execute();
$res = $stmt->get_result();
$purchase = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$purchase) {
    die('Purchase not found.');
}

/* -------------------------------------------------------
   LOAD SUPPLIERS
------------------------------------------------------- */
$suppliers = [];

$sql = "SELECT id, supplier_name";

if (hasColumn($conn, 'suppliers', 'supplier_code')) {
    $sql .= ", supplier_code";
}

if (hasColumn($conn, 'suppliers', 'mobile')) {
    $sql .= ", mobile";
}

$sql .= " FROM suppliers WHERE 1=1";

if ($supHasBusinessId) {
    $sql .= " AND business_id = ?";
}

if (hasColumn($conn, 'suppliers', 'is_active')) {
    $sql .= " AND is_active = 1";
}

$sql .= " ORDER BY supplier_name ASC";

$stmt = $conn->prepare($sql);

if ($stmt) {
    if ($supHasBusinessId) {
        $stmt->bind_param('i', $businessId);
    }

    $stmt->execute();
    $res = $stmt->get_result();

    while ($res && ($row = $res->fetch_assoc())) {
        $suppliers[] = $row;
    }

    $stmt->close();
}

/* -------------------------------------------------------
   LOAD PRODUCTS
------------------------------------------------------- */
$products = [];

if (tableExists($conn, 'products')) {
    $sql = "SELECT id, product_name, product_code";

    if (hasColumn($conn, 'products', 'barcode')) {
        $sql .= ", barcode";
    }

    if (hasColumn($conn, 'products', 'purity')) {
        $sql .= ", purity";
    }

    if (hasColumn($conn, 'products', 'purchase_rate')) {
        $sql .= ", purchase_rate";
    }

    if (hasColumn($conn, 'products', 'net_weight')) {
        $sql .= ", net_weight";
    }

    $sql .= " FROM products WHERE 1=1";

    if ($prdHasBusinessId) {
        $sql .= " AND business_id = ?";
    }

    if (hasColumn($conn, 'products', 'is_active')) {
        $sql .= " AND is_active = 1";
    }

    $sql .= " ORDER BY product_name ASC";

    $stmt = $conn->prepare($sql);

    if ($stmt) {
        if ($prdHasBusinessId) {
            $stmt->bind_param('i', $businessId);
        }

        $stmt->execute();
        $res = $stmt->get_result();

        while ($res && ($row = $res->fetch_assoc())) {
            $products[] = $row;
        }

        $stmt->close();
    }
}

/* -------------------------------------------------------
   LOAD PAYMENT METHODS
------------------------------------------------------- */
$paymentMethods = [];

if ($paymentMethodExists) {
    $sql = "SELECT id, method_name FROM payment_methods WHERE 1=1";

    if (hasColumn($conn, 'payment_methods', 'is_active')) {
        $sql .= " AND is_active = 1";
    }

    $sql .= " ORDER BY id ASC";
    $res = $conn->query($sql);

    while ($res && ($row = $res->fetch_assoc())) {
        $paymentMethods[] = $row;
    }
}

/* -------------------------------------------------------
   LOAD EXISTING ITEMS
------------------------------------------------------- */
$formItems = [];

$sql = "SELECT * FROM purchase_items WHERE purchase_id = ?";

if ($pitHasBusinessId) {
    $sql .= " AND business_id = ?";
}

$sql .= " ORDER BY id ASC";

$stmt = $conn->prepare($sql);

if ($stmt) {
    if ($pitHasBusinessId) {
        $stmt->bind_param('ii', $purchaseId, $businessId);
    } else {
        $stmt->bind_param('i', $purchaseId);
    }

    $stmt->execute();
    $res = $stmt->get_result();

    while ($res && ($row = $res->fetch_assoc())) {
        $formItems[] = [
            'product_id'       => (string)($row['product_id'] ?? ''),
            'item_name'        => (string)($row['item_name'] ?? ''),
            'purity'           => (string)($row['purity'] ?? '925'),
            'hsn_code'         => (string)($row['hsn_code'] ?? ''),
            'qty'              => qtyf($row['quantity'] ?? $row['qty'] ?? 0),
            'gross_weight'     => qtyf($row['gross_weight'] ?? 0),
            'less_weight'      => qtyf(max(
                (float)($row['gross_weight'] ?? 0) - (float)($row['net_weight'] ?? 0),
                0
            )),
            'net_weight'       => qtyf($row['net_weight'] ?? 0),
            'rate_per_gram'    => moneyf($row['rate'] ?? $row['rate_per_gram'] ?? 0),
            'making_charge'    => moneyf($row['making_charge'] ?? 0),
            'stone_charge'     => moneyf($row['stone_charge'] ?? 0),
            'item_amount'      => moneyf(
                ((float)($row['line_total'] ?? $row['total_amount'] ?? 0))
                - ((float)($row['tax_amount'] ?? $row['gst_amount'] ?? 0))
            ),
            'discount_amount'  => moneyf($row['discount_amount'] ?? 0),
            'taxable_amount'   => moneyf(
                ((float)($row['line_total'] ?? $row['total_amount'] ?? 0))
                - ((float)($row['tax_amount'] ?? $row['gst_amount'] ?? 0))
            ),
            'gst_percent'      => moneyf($row['tax_percent'] ?? $row['gst_percent'] ?? 3),
            'gst_amount'       => moneyf($row['tax_amount'] ?? $row['gst_amount'] ?? 0),
            'total_amount'     => moneyf($row['line_total'] ?? $row['total_amount'] ?? 0),
        ];
    }

    $stmt->close();
}

if (empty($formItems)) {
    $formItems[] = [
        'product_id'       => '',
        'item_name'        => '',
        'purity'           => '925',
        'hsn_code'         => '',
        'qty'              => '1.000',
        'gross_weight'     => '0.000',
        'less_weight'      => '0.000',
        'net_weight'       => '0.000',
        'rate_per_gram'    => '0.00',
        'making_charge'    => '0.00',
        'stone_charge'     => '0.00',
        'item_amount'      => '0.00',
        'discount_amount'  => '0.00',
        'taxable_amount'   => '0.00',
        'gst_percent'      => '3.00',
        'gst_amount'       => '0.00',
        'total_amount'     => '0.00',
    ];
}

/* -------------------------------------------------------
   DEFAULT VALUES
------------------------------------------------------- */
$success = '';
$error = '';

$purchaseNo = (string)($purchase['purchase_no'] ?? '');
$purchaseDate = (string)($purchase['purchase_date'] ?? date('Y-m-d'));
$supplierId = (int)($purchase['supplier_id'] ?? 0);
$invoiceNo = (string)($purchase['supplier_invoice_no'] ?? $purchase['invoice_no'] ?? '');
$paymentMethodId = (int)($purchase['payment_method_id'] ?? 0);
$notes = (string)($purchase['notes'] ?? '');
$discountAmount = moneyf($purchase['discount_amount'] ?? 0);
$roundOff = moneyf($purchase['round_off'] ?? 0);
$paidAmount = moneyf($purchase['paid_amount'] ?? 0);

/* -------------------------------------------------------
   UPDATE PURCHASE
------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $purchaseNo = trim((string)($_POST['purchase_no'] ?? ''));
    $purchaseDate = trim((string)($_POST['purchase_date'] ?? ''));
    $supplierId = (int)($_POST['supplier_id'] ?? 0);
    $invoiceNo = trim((string)($_POST['invoice_no'] ?? ''));
    $paymentMethodId = (int)($_POST['payment_method_id'] ?? 0);
    $notes = trim((string)($_POST['notes'] ?? ''));
    $discountAmount = trim((string)($_POST['discount_amount'] ?? '0'));
    $roundOff = trim((string)($_POST['round_off'] ?? '0'));
    $paidAmount = trim((string)($_POST['paid_amount'] ?? '0'));

    $postedItems = $_POST['items'] ?? [];

    if (
        (!is_array($postedItems) || empty($postedItems))
        && isset($_POST['items_json'])
        && trim((string)$_POST['items_json']) !== ''
    ) {
        $decodedItems = json_decode((string)$_POST['items_json'], true);
        if (is_array($decodedItems)) {
            $postedItems = $decodedItems;
        }
    }
    $cleanItems = [];
    $formItems = [];

    if ($purchaseNo === '') {
        $error = 'Purchase number is required.';
    } elseif ($purchaseDate === '') {
        $error = 'Purchase date is required.';
    } elseif ($supplierId <= 0) {
        $error = 'Please select supplier.';
    } elseif (!is_array($postedItems) || empty($postedItems)) {
        $error = 'Please add at least one item.';
    } elseif (!is_numeric($discountAmount) || !is_numeric($roundOff) || !is_numeric($paidAmount)) {
        $error = 'Invalid totals.';
    } else {
        foreach ($postedItems as $item) {
            $row = [
                'product_id'       => (int)($item['product_id'] ?? 0),
                'item_name'        => trim((string)($item['item_name'] ?? '')),
                'purity'           => trim((string)($item['purity'] ?? '925')),
                'hsn_code'         => trim((string)($item['hsn_code'] ?? '')),
                'qty'              => trim((string)($item['qty'] ?? '0')),
                'gross_weight'     => trim((string)($item['gross_weight'] ?? '0')),
                'less_weight'      => trim((string)($item['less_weight'] ?? '0')),
                'net_weight'       => trim((string)($item['net_weight'] ?? '0')),
                'rate_per_gram'    => trim((string)($item['rate_per_gram'] ?? '0')),
                'making_charge'    => trim((string)($item['making_charge'] ?? '0')),
                'stone_charge'     => trim((string)($item['stone_charge'] ?? '0')),
                'item_amount'      => '0.00',
                'discount_amount'  => trim((string)($item['discount_amount'] ?? '0')),
                'taxable_amount'   => '0.00',
                'gst_percent'      => trim((string)($item['gst_percent'] ?? '3')),
                'gst_amount'       => '0.00',
                'total_amount'     => '0.00',
            ];

            $blankRow = (
                $row['product_id'] <= 0 &&
                $row['item_name'] === '' &&
                (float)$row['qty'] <= 0 &&
                (float)$row['net_weight'] <= 0
            );

            if ($blankRow) {
                continue;
            }

            if ($row['item_name'] === '' && $row['product_id'] > 0) {
                $prd = getProductRow($conn, $row['product_id'], $businessId, $prdHasBusinessId);
                if ($prd) {
                    $row['item_name'] = (string)($prd['product_name'] ?? '');
                    if ($row['purity'] === '' && isset($prd['purity'])) {
                        $row['purity'] = (string)$prd['purity'];
                    }
                }
            }

            if (
                $row['item_name'] === '' ||
                !is_numeric($row['qty']) ||
                !is_numeric($row['gross_weight']) ||
                !is_numeric($row['less_weight']) ||
                !is_numeric($row['net_weight']) ||
                !is_numeric($row['rate_per_gram']) ||
                !is_numeric($row['making_charge']) ||
                !is_numeric($row['stone_charge']) ||
                !is_numeric($row['discount_amount']) ||
                !is_numeric($row['gst_percent'])
            ) {
                $error = 'Invalid item values found.';
                break;
            }

            $qty = (float)$row['qty'];
            $grossWeight = (float)$row['gross_weight'];
            $lessWeight = (float)$row['less_weight'];
            $netWeight = (float)$row['net_weight'];
            $ratePerGram = (float)$row['rate_per_gram'];
            $makingCharge = (float)$row['making_charge'];
            $stoneCharge = (float)$row['stone_charge'];
            $itemDiscount = (float)$row['discount_amount'];
            $gstPercent = (float)$row['gst_percent'];

            if ($qty <= 0) {
                $error = 'Item quantity must be greater than zero.';
                break;
            }

            if ($grossWeight < 0 || $lessWeight < 0 || $netWeight < 0) {
                $error = 'Weight values cannot be negative.';
                break;
            }

            $itemAmount = ($netWeight * $ratePerGram) + $makingCharge + $stoneCharge;
            $taxableAmount = $itemAmount - $itemDiscount;

            if ($taxableAmount < 0) {
                $taxableAmount = 0;
            }

            $gstAmount = ($taxableAmount * $gstPercent) / 100;
            $totalAmount = $taxableAmount + $gstAmount;

            $row['item_amount'] = moneyf($itemAmount);
            $row['taxable_amount'] = moneyf($taxableAmount);
            $row['gst_amount'] = moneyf($gstAmount);
            $row['total_amount'] = moneyf($totalAmount);

            $cleanItems[] = $row;
        }

        $formItems = !empty($cleanItems) ? $cleanItems : $formItems;

        if ($error === '' && empty($cleanItems)) {
            $error = 'Please add at least one valid item.';
        }

        if ($error === '') {
            $subtotal = 0.00;
            $taxableTotal = 0.00;
            $gstTotal = 0.00;

            foreach ($cleanItems as $row) {
                $subtotal += (float)$row['item_amount'];
                $taxableTotal += (float)$row['taxable_amount'];
                $gstTotal += (float)$row['gst_amount'];
            }

            $discountAmountF = (float)$discountAmount;
            $roundOffF = (float)$roundOff;
            $paidAmountF = (float)$paidAmount;

            $cgstAmount = round($gstTotal / 2, 2);
            $sgstAmount = round($gstTotal / 2, 2);
            $igstAmount = 0.00;
            $grandTotal = $taxableTotal + $gstTotal + $roundOffF;
            $balanceAmount = $grandTotal - $paidAmountF;

            if ($balanceAmount < 0) {
                $balanceAmount = 0;
            }

            $paymentStatus = 'Unpaid';

            if ($paidAmountF > 0 && $paidAmountF < $grandTotal) {
                $paymentStatus = 'Partial';
            } elseif ($paidAmountF >= $grandTotal && $grandTotal > 0) {
                $paymentStatus = 'Paid';
            }

            $conn->begin_transaction();

            try {
                /* ---------------------------------------------
                   REVERSE OLD PURCHASE STOCK
                --------------------------------------------- */
                $oldItems = [];

                $stmt = $conn->prepare("
                    SELECT product_id, quantity, gross_weight, net_weight, line_total
                    FROM purchase_items
                    WHERE purchase_id = ?
                      AND business_id = ?
                      AND branch_id = ?
                ");

                if (!$stmt) {
                    throw new Exception('Failed to prepare old purchase item lookup: ' . $conn->error);
                }

                $oldBranchId = (int)($purchase['branch_id'] ?? $branchId);
                $stmt->bind_param('iii', $purchaseId, $businessId, $oldBranchId);

                if (!$stmt->execute()) {
                    throw new Exception('Failed to load old purchase items: ' . $stmt->error);
                }

                $res = $stmt->get_result();
                while ($res && ($oldRow = $res->fetch_assoc())) {
                    $oldItems[] = $oldRow;
                }
                $stmt->close();

                foreach ($oldItems as $old) {
                    $oldProductId = (int)($old['product_id'] ?? 0);

                    if ($oldProductId <= 0) {
                        continue;
                    }

                    updateCurrentProductStock(
                        $conn,
                        $businessId,
                        $oldBranchId,
                        $oldProductId,
                        -1 * (float)($old['quantity'] ?? 0),
                        -1 * (float)($old['gross_weight'] ?? 0),
                        -1 * (float)($old['net_weight'] ?? 0),
                        -1 * (float)($old['line_total'] ?? 0)
                    );
                }

                /* ---------------------------------------------
                   DELETE OLD ITEMS
                --------------------------------------------- */
                $stmt = $conn->prepare("
                    DELETE FROM purchase_items
                    WHERE purchase_id = ?
                      AND business_id = ?
                      AND branch_id = ?
                ");

                if (!$stmt) {
                    throw new Exception('Failed to prepare old item delete: ' . $conn->error);
                }

                $stmt->bind_param('iii', $purchaseId, $businessId, $oldBranchId);

                if (!$stmt->execute()) {
                    throw new Exception('Failed to delete old purchase items: ' . $stmt->error);
                }

                $stmt->close();

                /* ---------------------------------------------
                   DELETE OLD STOCK MOVEMENTS
                --------------------------------------------- */
                if (tableExists($conn, 'stock_movements')) {
                    $stmt = $conn->prepare("
                        DELETE FROM stock_movements
                        WHERE reference_table = 'purchases'
                          AND reference_id = ?
                          AND business_id = ?
                          AND branch_id = ?
                    ");

                    if (!$stmt) {
                        throw new Exception('Failed to prepare old stock movement delete: ' . $conn->error);
                    }

                    $stmt->bind_param('iii', $purchaseId, $businessId, $oldBranchId);

                    if (!$stmt->execute()) {
                        throw new Exception('Failed to delete old stock movements: ' . $stmt->error);
                    }

                    $stmt->close();
                }

                /* ---------------------------------------------
                   UPDATE PURCHASE HEADER
                --------------------------------------------- */
                $purParts = [];
                $purTypes = '';
                $purValues = [];

                $purchaseColumns = [
                    'branch_id'         => [(int)($purchase['branch_id'] ?? $branchId), 'i'],
                    'purchase_no'       => [$purchaseNo, 's'],
                    'purchase_date'     => [$purchaseDate, 's'],
                    'supplier_id'       => [$supplierId, 'i'],
                    'invoice_no'        => [$invoiceNo, 's'],
                    'supplier_invoice_no' => [$invoiceNo, 's'],
                    'payment_method_id' => [$paymentMethodId > 0 ? $paymentMethodId : null, 'i'],
                    'subtotal'          => [$subtotal, 'd'],
                    'discount_amount'   => [$discountAmountF, 'd'],
                    'taxable_amount'    => [$taxableTotal, 'd'],
                    'cgst_amount'       => [$cgstAmount, 'd'],
                    'sgst_amount'       => [$sgstAmount, 'd'],
                    'igst_amount'       => [$igstAmount, 'd'],
                    'round_off'         => [$roundOffF, 'd'],
                    'grand_total'       => [$grandTotal, 'd'],
                    'paid_amount'       => [$paidAmountF, 'd'],
                    'balance_amount'    => [$balanceAmount, 'd'],
                    'payment_status'    => [$paymentStatus, 's'],
                    'notes'             => [$notes, 's'],
                ];

                foreach ($purchaseColumns as $col => $cfg) {
                    if (hasColumn($conn, 'purchases', $col)) {
                        $purParts[] = "`$col` = ?";
                        $purTypes .= $cfg[1];
                        $purValues[] = $cfg[0];
                    }
                }

                if (hasColumn($conn, 'purchases', 'updated_at')) {
                    $purParts[] = "updated_at = NOW()";
                }

                if (empty($purParts)) {
                    throw new Exception('No purchase columns found for update.');
                }

                $sql = "UPDATE purchases SET " . implode(', ', $purParts) . " WHERE id = ?";
                $purTypes .= 'i';
                $purValues[] = $purchaseId;

                if ($purHasBusinessId) {
                    $sql .= " AND business_id = ?";
                    $purTypes .= 'i';
                    $purValues[] = $businessId;
                }

                $sql .= " LIMIT 1";

                $stmt = $conn->prepare($sql);

                if (!$stmt) {
                    throw new Exception('Failed to prepare purchase update: ' . $conn->error);
                }

                $bindValues = [];
                $bindValues[] = $purTypes;

                for ($i = 0; $i < count($purValues); $i++) {
                    $bindValues[] = &$purValues[$i];
                }

                call_user_func_array([$stmt, 'bind_param'], $bindValues);

                if (!$stmt->execute()) {
                    throw new Exception('Failed to update purchase: ' . $stmt->error);
                }

                $stmt->close();

                /* ---------------------------------------------
                   INSERT NEW ITEMS + STOCK
                --------------------------------------------- */
                foreach ($cleanItems as $row) {
                    $pitFields = [];
                    $pitPlaceholders = [];
                    $pitTypes = '';
                    $pitValues = [];

                    if ($pitHasBusinessId) {
                        $pitFields[] = 'business_id';
                        $pitPlaceholders[] = '?';
                        $pitTypes .= 'i';
                        $pitValues[] = $businessId;
                    }

                    if (hasColumn($conn, 'purchase_items', 'branch_id')) {
                        $pitFields[] = 'branch_id';
                        $pitPlaceholders[] = '?';
                        $pitTypes .= 'i';
                        $pitValues[] = (int)($_SESSION['branch_id'] ?? $purchase['branch_id'] ?? 0);
                    }

                    $productIdValue = $row['product_id'] > 0 ? $row['product_id'] : null;

                    $itemColumns = [
                        'purchase_id'     => [$purchaseId, 'i'],
                        'product_id'      => [$productIdValue, 'i'],
                        'item_name'       => [$row['item_name'], 's'],
                        'purity'          => [$row['purity'], 's'],
                        'hsn_code'        => [$row['hsn_code'], 's'],
                        'qty'             => [(float)$row['qty'], 'd'],
                        'quantity'        => [(float)$row['qty'], 'd'],
                        'gross_weight'    => [(float)$row['gross_weight'], 'd'],
                        'less_weight'     => [(float)$row['less_weight'], 'd'],
                        'net_weight'      => [(float)$row['net_weight'], 'd'],
                        'rate_per_gram'   => [(float)$row['rate_per_gram'], 'd'],
                        'rate'            => [(float)$row['rate_per_gram'], 'd'],
                        'making_charge'   => [(float)$row['making_charge'], 'd'],
                        'stone_charge'    => [(float)$row['stone_charge'], 'd'],
                        'item_amount'     => [(float)$row['item_amount'], 'd'],
                        'discount_amount' => [(float)$row['discount_amount'], 'd'],
                        'taxable_amount'  => [(float)$row['taxable_amount'], 'd'],
                        'gst_percent'     => [(float)$row['gst_percent'], 'd'],
                        'tax_percent'     => [(float)$row['gst_percent'], 'd'],
                        'gst_amount'      => [(float)$row['gst_amount'], 'd'],
                        'tax_amount'      => [(float)$row['gst_amount'], 'd'],
                        'total_amount'    => [(float)$row['total_amount'], 'd'],
                        'line_total'      => [(float)$row['total_amount'], 'd'],
                    ];

                    foreach ($itemColumns as $col => $cfg) {
                        if (hasColumn($conn, 'purchase_items', $col)) {
                            $pitFields[] = "`$col`";
                            $pitPlaceholders[] = '?';
                            $pitTypes .= $cfg[1];
                            $pitValues[] = $cfg[0];
                        }
                    }

                    $sql = "INSERT INTO purchase_items (" . implode(', ', $pitFields) . ") VALUES (" . implode(', ', $pitPlaceholders) . ")";
                    $stmt = $conn->prepare($sql);

                    if (!$stmt) {
                        throw new Exception('Failed to prepare purchase item insert: ' . $conn->error);
                    }

                    $bindValues = [];
                    $bindValues[] = $pitTypes;

                    for ($i = 0; $i < count($pitValues); $i++) {
                        $bindValues[] = &$pitValues[$i];
                    }

                    call_user_func_array([$stmt, 'bind_param'], $bindValues);

                    if (!$stmt->execute()) {
                        throw new Exception('Failed to save purchase item: ' . $stmt->error);
                    }

                    $stmt->close();

                    $productId = (int)$row['product_id'];
                    $qty = (float)$row['qty'];
                    $netWeight = (float)$row['net_weight'];

                    if ($productId > 0) {
                        $currentBranchId = (int)($purchase['branch_id'] ?? $branchId);
                        $lineValue = (float)$row['total_amount'];

                        updateCurrentProductStock(
                            $conn,
                            $businessId,
                            $currentBranchId,
                            $productId,
                            $qty,
                            (float)$row['gross_weight'],
                            $netWeight,
                            $lineValue
                        );

                        insertPurchaseStockMovement(
                            $conn,
                            $businessId,
                            $currentBranchId,
                            $productId,
                            $purchaseId,
                            $qty,
                            $netWeight,
                            (float)$row['rate_per_gram'],
                            $lineValue,
                            $userId,
                            'Purchase updated: ' . $purchaseNo
                        );
                    }
                }

                addAuditLog(
                    $conn,
                    $businessId,
                    $userId,
                    'Purchases',
                    'Update',
                    $purchaseId,
                    'Updated purchase ' . $purchaseNo
                );

                $conn->commit();

                header('Location: purchase-view.php?id=' . $purchaseId . '&msg=updated');
                exit;
            } catch (Throwable $e) {
                $conn->rollback();
                $error = $e->getMessage();
            }
        }
    }
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
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo h($businessName); ?> - Edit Purchase</title>
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
    --sidebar-width:<?php echo (int)$theme['sidebar_width_px']; ?>px;
    --radius:<?php echo (int)$theme['border_radius_px']; ?>px;
}
body{background:var(--page-bg);color:var(--text-color);font-family:<?php echo json_encode((string)$theme['font_family']); ?>,sans-serif;}
.sidebar{background:linear-gradient(180deg,var(--sidebar-gradient-1),var(--sidebar-gradient-2),var(--sidebar-gradient-3))!important;}
.card{background:var(--card-bg);border:1px solid var(--border-color);border-radius:var(--radius);box-shadow:none;margin-bottom:12px;}
.card-body{padding:14px;}
.card-title,h4,h5{font-family:<?php echo json_encode((string)$theme['heading_font_family']); ?>,serif;font-weight:800;}
.form-label{font-size:10px;font-weight:700;margin-bottom:5px;}
.form-control,.form-select{min-height:38px;border-radius:9px;border-color:var(--border-color);background:var(--card-bg);color:var(--text-color);font-size:11px;box-shadow:none;}
.form-control:focus,.form-select:focus{border-color:var(--primary);box-shadow:0 0 0 .2rem color-mix(in srgb,var(--primary) 13%,transparent);}
.btn{border-radius:9px;font-size:11px;font-weight:700;}
.btn-primary{background:linear-gradient(135deg,var(--primary),var(--primary-dark));border-color:transparent;}
.btn-primary:hover{border-color:transparent;filter:brightness(1.03);}
.btn-info{background:var(--primary-soft);border-color:color-mix(in srgb,var(--primary) 25%,var(--border-color));color:var(--primary-dark);}
.table{font-size:10px;color:var(--text-color);}
.table th{padding:9px 8px;background:color-mix(in srgb,var(--muted-color) 6%,var(--card-bg));color:var(--muted-color);border-color:var(--border-color);font-size:9px;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap;}
.table td{padding:9px 8px;background:var(--card-bg)!important;color:var(--text-color);border-color:var(--border-color);vertical-align:middle;}
.table-responsive{border-radius:var(--radius);}
.alert{border:0;border-radius:10px;font-size:11px;}
.badge{font-size:9px;border-radius:999px;padding:5px 8px;}
.purchase-page-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:10px;background:var(--card-bg);border:1px solid var(--border-color);border-radius:var(--radius);padding:12px 14px;}
.purchase-page-title{font-family:<?php echo json_encode((string)$theme['heading_font_family']); ?>,serif;font-size:18px;font-weight:800;margin:0;}
.purchase-page-subtitle{font-size:10px;color:var(--muted-color);margin-top:2px;}
#itemsTable{min-width:1600px;}
#itemsTable input,#itemsTable select{min-width:78px;height:34px;font-size:10px;padding:5px 7px;}
.info-table th,.purchase-summary-table th{width:180px;background:color-mix(in srgb,var(--muted-color) 6%,var(--card-bg));}
body.dark-mode,body[data-theme="dark"],html.dark-mode body,html[data-theme="dark"] body{--page-bg:#0f151b;--card-bg:#182129;--text-color:#f3f6f8;--muted-color:#9aa7b3;--border-color:#2c3944;}
@media(max-width:767.98px){.content-wrap{padding-left:10px;padding-right:10px}.purchase-page-head{align-items:flex-start;flex-direction:column}.purchase-page-head .d-flex{width:100%;flex-wrap:wrap}.purchase-page-head .btn{flex:1}}
@media print{
    .sidebar,.app-nav,.no-print,.footer{display:none!important}
    .app-main{margin-left:0!important}
    .content-wrap{padding:0!important}
    .card{border:1px solid #ddd!important}
}
</style>

<style>
    #itemsTable {
        min-width: 2100px;
    }

    #itemsTable th,
    #itemsTable td {
        vertical-align: middle;
        white-space: nowrap;
    }

    #itemsTable input,
    #itemsTable select {
        min-width: 75px;
        height: 40px;
        font-size: 14px;
    }

    .purchase-summary-table th {
        width: 45%;
        background: #f8f9fa;
    }

    .purchase-summary-table input {
        min-width: 160px;
    }
</style>
</head>
<body>
<?php include('includes/sidebar.php'); ?>
<main class="app-main">
<?php include('includes/nav.php'); ?>
<div class="content-wrap">


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

                <form method="post" id="purchaseForm">
                    <input type="hidden" name="purchase_id" value="<?php echo (int)$purchaseId; ?>">
                    <input type="hidden" name="items_json" id="itemsJson" value="">

                    <div class="row">
                        <div class="col-xl-12">
                            <div class="card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                                        <div>
                                            <h4 class="card-title mb-1">Edit Purchase</h4>
                                            <p class="text-muted mb-0">Update purchase entry and item details</p>
                                        </div>
                                        <div class="d-flex gap-2">
                                            <a href="purchase-view.php?id=<?php echo (int)$purchaseId; ?>" class="btn btn-info">
                                                View
                                            </a>
                                            <a href="purchases.php" class="btn btn-secondary">
                                                Back to Purchases
                                            </a>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-3 mb-3">
                                            <label class="form-label">Purchase No</label>
                                            <input type="text" name="purchase_no" class="form-control" value="<?php echo h($purchaseNo); ?>" required>
                                        </div>

                                        <div class="col-md-3 mb-3">
                                            <label class="form-label">Purchase Date</label>
                                            <input type="date" name="purchase_date" class="form-control" value="<?php echo h($purchaseDate); ?>" required>
                                        </div>

                                        <div class="col-md-3 mb-3">
                                            <label class="form-label">Supplier <span class="text-danger">*</span></label>
                                            <select name="supplier_id" class="form-select" required>
                                                <option value="">Select Supplier</option>
                                                <?php foreach ($suppliers as $sup): ?>
                                                    <option value="<?php echo (int)$sup['id']; ?>" <?php echo $supplierId === (int)$sup['id'] ? 'selected' : ''; ?>>
                                                        <?php
                                                        echo h($sup['supplier_name']);
                                                        if (!empty($sup['supplier_code'])) {
                                                            echo ' (' . h($sup['supplier_code']) . ')';
                                                        }
                                                        ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="col-md-3 mb-3">
                                            <label class="form-label">Invoice No</label>
                                            <input type="text" name="invoice_no" class="form-control" value="<?php echo h($invoiceNo); ?>">
                                        </div>
                                    </div>

                                    <div class="table-responsive" style="overflow-x:auto;">
                                        <table class="table table-bordered align-middle mb-0" id="itemsTable">
                                            <thead class="table-light">
                                                <tr>
                                                    <th style="min-width:220px;">Product</th>
                                                    <th style="min-width:220px;">Item Name</th>
                                                    <th style="min-width:100px;">Purity</th>
                                                    <th style="min-width:100px;">HSN</th>
                                                    <th style="min-width:90px;">Qty</th>
                                                    <th style="min-width:110px;">Gross Wt</th>
                                                    <th style="min-width:110px;">Less Wt</th>
                                                    <th style="min-width:110px;">Net Wt</th>
                                                    <th style="min-width:120px;">Rate/Gm</th>
                                                    <th style="min-width:120px;">Making</th>
                                                    <th style="min-width:120px;">Stone</th>
                                                    <th style="min-width:120px;">Discount</th>
                                                    <th style="min-width:100px;">GST %</th>
                                                    <th style="min-width:130px;">Taxable</th>
                                                    <th style="min-width:130px;">GST Amt</th>
                                                    <th style="min-width:130px;">Total</th>
                                                    <th style="min-width:90px;">Action</th>
                                                </tr>
                                            </thead>

                                            <tbody id="itemRows">
                                                <?php foreach ($formItems as $index => $item): ?>
                                                    <tr>
                                                        <td>
                                                            <select name="items[<?php echo $index; ?>][product_id]" class="form-select product-select">
                                                                <option value="">Select</option>
                                                                <?php foreach ($products as $prd): ?>
                                                                    <option
                                                                        value="<?php echo (int)$prd['id']; ?>"
                                                                        data-name="<?php echo h($prd['product_name']); ?>"
                                                                        data-purity="<?php echo h($prd['purity'] ?? '925'); ?>"
                                                                        data-rate="<?php echo h($prd['purchase_rate'] ?? '0'); ?>"
                                                                        data-weight="<?php echo h($prd['net_weight'] ?? '0'); ?>"
                                                                        <?php echo (int)($item['product_id'] ?? 0) === (int)$prd['id'] ? 'selected' : ''; ?>
                                                                    >
                                                                        <?php echo h($prd['product_name'] . ' (' . $prd['product_code'] . ')'); ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </td>

                                                        <td><input type="text" name="items[<?php echo $index; ?>][item_name]" class="form-control item-name" value="<?php echo h($item['item_name']); ?>"></td>
                                                        <td><input type="text" name="items[<?php echo $index; ?>][purity]" class="form-control purity" value="<?php echo h($item['purity']); ?>"></td>
                                                        <td><input type="text" name="items[<?php echo $index; ?>][hsn_code]" class="form-control hsn-code" value="<?php echo h($item['hsn_code']); ?>"></td>
                                                        <td><input type="number" step="0.001" min="0" name="items[<?php echo $index; ?>][qty]" class="form-control qty" value="<?php echo h($item['qty']); ?>"></td>
                                                        <td><input type="number" step="0.001" min="0" name="items[<?php echo $index; ?>][gross_weight]" class="form-control gross-weight" value="<?php echo h($item['gross_weight']); ?>"></td>
                                                        <td><input type="number" step="0.001" min="0" name="items[<?php echo $index; ?>][less_weight]" class="form-control less-weight" value="<?php echo h($item['less_weight']); ?>"></td>
                                                        <td><input type="number" step="0.001" min="0" name="items[<?php echo $index; ?>][net_weight]" class="form-control net-weight" value="<?php echo h($item['net_weight']); ?>"></td>
                                                        <td><input type="number" step="0.01" min="0" name="items[<?php echo $index; ?>][rate_per_gram]" class="form-control rate-per-gram" value="<?php echo h($item['rate_per_gram']); ?>"></td>
                                                        <td><input type="number" step="0.01" min="0" name="items[<?php echo $index; ?>][making_charge]" class="form-control making-charge" value="<?php echo h($item['making_charge']); ?>"></td>
                                                        <td><input type="number" step="0.01" min="0" name="items[<?php echo $index; ?>][stone_charge]" class="form-control stone-charge" value="<?php echo h($item['stone_charge']); ?>"></td>
                                                        <td><input type="number" step="0.01" min="0" name="items[<?php echo $index; ?>][discount_amount]" class="form-control item-discount" value="<?php echo h($item['discount_amount']); ?>"></td>
                                                        <td><input type="number" step="0.01" min="0" name="items[<?php echo $index; ?>][gst_percent]" class="form-control gst-percent" value="<?php echo h($item['gst_percent']); ?>"></td>

                                                        <td>
                                                            <input type="number" step="0.01" min="0" name="items[<?php echo $index; ?>][taxable_amount]" class="form-control taxable-amount" value="<?php echo h($item['taxable_amount']); ?>" readonly>
                                                            <input type="hidden" name="items[<?php echo $index; ?>][item_amount]" class="item-amount" value="<?php echo h($item['item_amount']); ?>">
                                                        </td>

                                                        <td><input type="number" step="0.01" min="0" name="items[<?php echo $index; ?>][gst_amount]" class="form-control gst-amount" value="<?php echo h($item['gst_amount']); ?>" readonly></td>
                                                        <td><input type="number" step="0.01" min="0" name="items[<?php echo $index; ?>][total_amount]" class="form-control total-amount" value="<?php echo h($item['total_amount']); ?>" readonly></td>
                                                        <td><button type="button" class="btn btn-danger btn-sm remove-row">X</button></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>

                                    <button type="button" class="btn btn-info btn-sm mt-3 mb-4" id="addRowBtn">Add Item</button>

                                    <div class="row">
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Payment Method</label>
                                            <select name="payment_method_id" class="form-select">
                                                <option value="">Select</option>
                                                <?php foreach ($paymentMethods as $pm): ?>
                                                    <option value="<?php echo (int)$pm['id']; ?>" <?php echo $paymentMethodId === (int)$pm['id'] ? 'selected' : ''; ?>>
                                                        <?php echo h($pm['method_name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="col-md-8 mb-3">
                                            <label class="form-label">Notes</label>
                                            <input type="text" name="notes" class="form-control" value="<?php echo h($notes); ?>">
                                        </div>
                                    </div>

                                    <div class="row justify-content-end">
                                        <div class="col-md-4">
                                            <table class="table table-bordered purchase-summary-table">
                                                <tr>
                                                    <th>Subtotal</th>
                                                    <td><input type="number" step="0.01" class="form-control" id="subtotal" readonly></td>
                                                </tr>
                                                <tr>
                                                    <th>Discount</th>
                                                    <td><input type="number" step="0.01" min="0" name="discount_amount" id="discount_amount" class="form-control" value="<?php echo h($discountAmount); ?>"></td>
                                                </tr>
                                                <tr>
                                                    <th>Taxable</th>
                                                    <td><input type="number" step="0.01" class="form-control" id="taxable_total" readonly></td>
                                                </tr>
                                                <tr>
                                                    <th>GST Total</th>
                                                    <td><input type="number" step="0.01" class="form-control" id="gst_total" readonly></td>
                                                </tr>
                                                <tr>
                                                    <th>Round Off</th>
                                                    <td><input type="number" step="0.01" name="round_off" id="round_off" class="form-control" value="<?php echo h($roundOff); ?>"></td>
                                                </tr>
                                                <tr>
                                                    <th>Grand Total</th>
                                                    <td><input type="number" step="0.01" class="form-control" id="grand_total" readonly></td>
                                                </tr>
                                                <tr>
                                                    <th>Paid Amount</th>
                                                    <td><input type="number" step="0.01" min="0" name="paid_amount" id="paid_amount" class="form-control" value="<?php echo h($paidAmount); ?>"></td>
                                                </tr>
                                                <tr>
                                                    <th>Balance</th>
                                                    <td><input type="number" step="0.01" class="form-control" id="balance_amount" readonly></td>
                                                </tr>
                                            </table>
                                        </div>
                                    </div>

                                    <div class="d-flex gap-2">
                                        <button type="submit" class="btn btn-primary">Update Purchase</button>
                                        <a href="purchase-view.php?id=<?php echo (int)$purchaseId; ?>" class="btn btn-info">View Purchase</a>
                                        <a href="purchases.php" class="btn btn-secondary">Back to Purchases</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
<?php include('includes/footer.php'); ?>
</div>
</main>

<script>
(function () {
    'use strict';

    const products = <?php
        $productJs = [];
        foreach ($products as $p) {
            $productJs[] = [
                'id' => (int)$p['id'],
                'product_name' => (string)$p['product_name'],
                'product_code' => (string)$p['product_code'],
                'purity' => (string)($p['purity'] ?? '925'),
                'purchase_rate' => (float)($p['purchase_rate'] ?? 0),
                'net_weight' => (float)($p['net_weight'] ?? 0)
            ];
        }
        echo json_encode($productJs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    ?>;

    const form = document.getElementById('purchaseForm');
    const tbody = document.getElementById('itemRows');
    const itemsJson = document.getElementById('itemsJson');
    let rowIndex = tbody ? tbody.querySelectorAll('tr').length : 0;

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
    }

    function num(value) {
        const parsed = parseFloat(value);
        return Number.isFinite(parsed) ? parsed : 0;
    }

    function productOptions(selectedId = '') {
        let html = '<option value="">Select</option>';
        products.forEach(function (product) {
            const selected = String(selectedId) === String(product.id) ? ' selected' : '';
            html += '<option value="' + product.id + '"' +
                ' data-name="' + escapeHtml(product.product_name) + '"' +
                ' data-purity="' + escapeHtml(product.purity) + '"' +
                ' data-rate="' + product.purchase_rate + '"' +
                ' data-weight="' + product.net_weight + '"' +
                selected + '>' +
                escapeHtml(product.product_name + ' (' + product.product_code + ')') +
                '</option>';
        });
        return html;
    }

    function calculateRow(row) {
        const gross = num(row.querySelector('.gross-weight')?.value);
        const less = num(row.querySelector('.less-weight')?.value);
        const net = Math.max(0, gross - less);
        const rate = num(row.querySelector('.rate-per-gram')?.value);
        const making = num(row.querySelector('.making-charge')?.value);
        const stone = num(row.querySelector('.stone-charge')?.value);
        const discount = num(row.querySelector('.item-discount')?.value);
        const gstPercent = num(row.querySelector('.gst-percent')?.value);

        const netInput = row.querySelector('.net-weight');
        if (netInput) netInput.value = net.toFixed(3);

        const itemAmount = (net * rate) + making + stone;
        const taxable = Math.max(0, itemAmount - discount);
        const gst = taxable * gstPercent / 100;
        const total = taxable + gst;

        row.querySelector('.item-amount').value = itemAmount.toFixed(2);
        row.querySelector('.taxable-amount').value = taxable.toFixed(2);
        row.querySelector('.gst-amount').value = gst.toFixed(2);
        row.querySelector('.total-amount').value = total.toFixed(2);

        calculateTotals();
    }

    function calculateTotals() {
        let subtotal = 0;
        let taxable = 0;
        let gst = 0;

        tbody.querySelectorAll('tr').forEach(function (row) {
            subtotal += num(row.querySelector('.item-amount')?.value);
            taxable += num(row.querySelector('.taxable-amount')?.value);
            gst += num(row.querySelector('.gst-amount')?.value);
        });

        const billDiscount = num(document.getElementById('discount_amount')?.value);
        const roundOff = num(document.getElementById('round_off')?.value);
        const paid = num(document.getElementById('paid_amount')?.value);
        const finalTaxable = Math.max(0, taxable - billDiscount);
        const grandTotal = finalTaxable + gst + roundOff;
        const balance = Math.max(0, grandTotal - paid);

        const values = {
            subtotal: subtotal,
            taxable_total: finalTaxable,
            gst_total: gst,
            grand_total: grandTotal,
            balance_amount: balance
        };

        Object.keys(values).forEach(function (id) {
            const element = document.getElementById(id);
            if (element) element.value = values[id].toFixed(2);
        });
    }

    function bindRow(row) {
        const select = row.querySelector('.product-select');
        if (select) {
            select.addEventListener('change', function () {
                const option = this.options[this.selectedIndex];
                if (!option || !option.value) return;

                row.querySelector('.item-name').value = option.dataset.name || '';
                row.querySelector('.purity').value = option.dataset.purity || '925';
                row.querySelector('.rate-per-gram').value = num(option.dataset.rate).toFixed(2);

                const weight = num(option.dataset.weight);
                if (num(row.querySelector('.gross-weight').value) <= 0) {
                    row.querySelector('.gross-weight').value = weight.toFixed(3);
                }

                calculateRow(row);
            });
        }

        row.querySelectorAll('input').forEach(function (input) {
            input.addEventListener('input', function () {
                calculateRow(row);
            });
        });

        const remove = row.querySelector('.remove-row');
        if (remove) {
            remove.addEventListener('click', function () {
                if (tbody.querySelectorAll('tr').length <= 1) {
                    return;
                }
                row.remove();
                calculateTotals();
            });
        }
    }

    function addRow() {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td><select name="items[${rowIndex}][product_id]" class="form-select product-select">${productOptions()}</select></td>
            <td><input type="text" name="items[${rowIndex}][item_name]" class="form-control item-name"></td>
            <td><input type="text" name="items[${rowIndex}][purity]" class="form-control purity" value="925"></td>
            <td><input type="text" name="items[${rowIndex}][hsn_code]" class="form-control hsn-code"></td>
            <td><input type="number" step="0.001" min="0" name="items[${rowIndex}][qty]" class="form-control qty" value="1.000"></td>
            <td><input type="number" step="0.001" min="0" name="items[${rowIndex}][gross_weight]" class="form-control gross-weight" value="0.000"></td>
            <td><input type="number" step="0.001" min="0" name="items[${rowIndex}][less_weight]" class="form-control less-weight" value="0.000"></td>
            <td><input type="number" step="0.001" min="0" name="items[${rowIndex}][net_weight]" class="form-control net-weight" value="0.000"></td>
            <td><input type="number" step="0.01" min="0" name="items[${rowIndex}][rate_per_gram]" class="form-control rate-per-gram" value="0.00"></td>
            <td><input type="number" step="0.01" min="0" name="items[${rowIndex}][making_charge]" class="form-control making-charge" value="0.00"></td>
            <td><input type="number" step="0.01" min="0" name="items[${rowIndex}][stone_charge]" class="form-control stone-charge" value="0.00"></td>
            <td><input type="number" step="0.01" min="0" name="items[${rowIndex}][discount_amount]" class="form-control item-discount" value="0.00"></td>
            <td><input type="number" step="0.01" min="0" name="items[${rowIndex}][gst_percent]" class="form-control gst-percent" value="3.00"></td>
            <td>
                <input type="number" step="0.01" name="items[${rowIndex}][taxable_amount]" class="form-control taxable-amount" value="0.00" readonly>
                <input type="hidden" name="items[${rowIndex}][item_amount]" class="item-amount" value="0.00">
            </td>
            <td><input type="number" step="0.01" name="items[${rowIndex}][gst_amount]" class="form-control gst-amount" value="0.00" readonly></td>
            <td><input type="number" step="0.01" name="items[${rowIndex}][total_amount]" class="form-control total-amount" value="0.00" readonly></td>
            <td><button type="button" class="btn btn-danger btn-sm remove-row">X</button></td>
        `;
        tbody.appendChild(row);
        rowIndex++;
        bindRow(row);
        calculateRow(row);
    }

    function serializeItems() {
        const items = [];

        tbody.querySelectorAll('tr').forEach(function (row) {
            items.push({
                product_id: row.querySelector('.product-select')?.value || '',
                item_name: row.querySelector('.item-name')?.value || '',
                purity: row.querySelector('.purity')?.value || '',
                hsn_code: row.querySelector('.hsn-code')?.value || '',
                qty: row.querySelector('.qty')?.value || '0',
                gross_weight: row.querySelector('.gross-weight')?.value || '0',
                less_weight: row.querySelector('.less-weight')?.value || '0',
                net_weight: row.querySelector('.net-weight')?.value || '0',
                rate_per_gram: row.querySelector('.rate-per-gram')?.value || '0',
                making_charge: row.querySelector('.making-charge')?.value || '0',
                stone_charge: row.querySelector('.stone-charge')?.value || '0',
                discount_amount: row.querySelector('.item-discount')?.value || '0',
                gst_percent: row.querySelector('.gst-percent')?.value || '0',
                taxable_amount: row.querySelector('.taxable-amount')?.value || '0',
                gst_amount: row.querySelector('.gst-amount')?.value || '0',
                total_amount: row.querySelector('.total-amount')?.value || '0'
            });
        });

        itemsJson.value = JSON.stringify(items);
    }

    tbody.querySelectorAll('tr').forEach(function (row) {
        bindRow(row);
        calculateRow(row);
    });

    document.getElementById('addRowBtn')?.addEventListener('click', addRow);

    ['discount_amount', 'round_off', 'paid_amount'].forEach(function (id) {
        document.getElementById(id)?.addEventListener('input', calculateTotals);
    });

    form?.addEventListener('submit', function () {
        serializeItems();
    });

    calculateTotals();
})();
</script>

<?php include('includes/script.php'); ?>
<script src="assets/js/script.js"></script>
</body>
</html>
