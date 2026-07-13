<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));
header('Content-Type: application/json; charset=utf-8');

$configCandidates = [
    dirname(__DIR__) . '/config/config.php',
    dirname(__DIR__) . '/config.php',
    dirname(__DIR__) . '/includes/config.php',
    dirname(__DIR__) . '/super-admin/includes/config.php',
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
    apiResponse(false, 'Database configuration is not available.', [], 500);
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn->set_charset('utf8mb4');

function apiResponse(bool $success, string $message, array $data = [], int $status = 200): never
{
    http_response_code($status);
    echo json_encode(
        [
            'success' => $success,
            'message' => $message,
            'data' => $data,
        ],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function tableExists(mysqli $conn, string $table): bool
{
    $safe = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $result && $result->num_rows > 0;
}

function hasColumn(mysqli $conn, string $table, string $column): bool
{
    $safeTable = $conn->real_escape_string($table);
    $safeColumn = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    return $result && $result->num_rows > 0;
}

function inputData(): array
{
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));

    if (str_contains($contentType, 'application/json')) {
        $decoded = json_decode((string)file_get_contents('php://input'), true);
        return is_array($decoded) ? $decoded : [];
    }

    return $_POST;
}

function requireLogin(): array
{
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $businessId = (int)($_SESSION['business_id'] ?? 0);
    $branchId = (int)($_SESSION['branch_id'] ?? 0);

    if ($userId <= 0) {
        apiResponse(false, 'Login session expired.', [], 401);
    }

    if ($businessId <= 0) {
        apiResponse(false, 'Business session not found.', [], 401);
    }

    return [$userId, $businessId, $branchId];
}

function hasAnyPermission(array $permissionCodes, array $actions): bool
{
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin') {
        return true;
    }

    $roleName = strtolower(trim((string)($_SESSION['role_name'] ?? '')));
    $roleCode = strtolower(trim((string)($_SESSION['role_code'] ?? '')));

    if (
        in_array($roleName, ['admin', 'business admin', 'manager', 'stock', 'staff', 'sales'], true) ||
        in_array($roleCode, ['admin', 'business_admin', 'manager', 'stock', 'staff', 'sales'], true)
    ) {
        return true;
    }

    $permissions = $_SESSION['permissions'] ?? [];

    foreach ($permissionCodes as $code) {
        if (!isset($permissions[$code])) {
            continue;
        }

        foreach ($actions as $action) {
            if ((int)($permissions[$code][$action] ?? 0) === 1) {
                return true;
            }
        }
    }

    return false;
}

function requirePermission(array $permissionCodes, array $actions): void
{
    if (!hasAnyPermission($permissionCodes, $actions)) {
        apiResponse(false, 'Access denied.', [], 403);
    }
}

function bindDynamic(mysqli_stmt $stmt, string $types, array &$values): void
{
    if ($types === '' || empty($values)) {
        return;
    }

    $bind = [$types];

    foreach ($values as $index => $value) {
        $bind[] = &$values[$index];
    }

    call_user_func_array([$stmt, 'bind_param'], $bind);
}

function addAuditLogSafe(
    mysqli $conn,
    int $businessId,
    int $userId,
    string $module,
    string $action,
    int $referenceId,
    string $description
): void {
    if (!tableExists($conn, 'audit_logs')) {
        return;
    }

    $fields = [];
    $placeholders = [];
    $types = '';
    $values = [];

    $map = [
        'business_id' => [$businessId, 'i'],
        'user_id' => [$userId, 'i'],
        'module_name' => [$module, 's'],
        'action_type' => [$action, 's'],
        'reference_id' => [$referenceId, 'i'],
        'description' => [$description, 's'],
        'ip_address' => [(string)($_SERVER['REMOTE_ADDR'] ?? ''), 's'],
        'user_agent' => [(string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 's'],
    ];

    foreach ($map as $field => [$value, $type]) {
        if (!hasColumn($conn, 'audit_logs', $field)) {
            continue;
        }

        $fields[] = $field;
        $placeholders[] = '?';
        $types .= $type;
        $values[] = $value;
    }

    if (empty($fields)) {
        return;
    }

    $sql = 'INSERT INTO audit_logs (' . implode(', ', $fields) . ')
            VALUES (' . implode(', ', $placeholders) . ')';

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return;
    }

    bindDynamic($stmt, $types, $values);
    $stmt->execute();
    $stmt->close();
}

function generateReferenceNo(
    mysqli $conn,
    string $table,
    string $column,
    int $businessId,
    string $prefix
): string {
    $like = $prefix . '%';
    $sql = "SELECT `{$column}`
            FROM `{$table}`
            WHERE business_id = ?
              AND `{$column}` LIKE ?
            ORDER BY id DESC
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    $last = '';

    if ($stmt) {
        $stmt->bind_param('is', $businessId, $like);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $last = (string)($row[$column] ?? '');
        $stmt->close();
    }

    $running = 1;

    if ($last !== '' && preg_match('/(\d{4})$/', $last, $match)) {
        $running = ((int)$match[1]) + 1;
    }

    return $prefix . str_pad((string)$running, 4, '0', STR_PAD_LEFT);
}

[$userId, $businessId, $branchId] = requireLogin();
requirePermission(['perm.stock', 'perm.inventory'], ['can_open', 'can_create', 'can_edit']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiResponse(false, 'POST method required.', [], 405);
}

if (
    !tableExists($conn, 'products') ||
    !tableExists($conn, 'product_stock') ||
    !tableExists($conn, 'stock_movements')
) {
    apiResponse(false, 'Required stock tables are missing.', [], 500);
}

$data = inputData();

$productId = (int)($data['product_id'] ?? 0);
$mode = strtolower(trim((string)($data['adjustment_mode'] ?? 'add')));
$quantity = (float)($data['adjustment_qty'] ?? 0);
$weight = (float)($data['adjustment_weight'] ?? 0);
$rate = (float)($data['rate'] ?? 0);
$remarks = trim((string)($data['remarks'] ?? ''));
$movementDate = trim((string)($data['movement_date'] ?? date('Y-m-d H:i:s')));

if ($productId <= 0) {
    apiResponse(false, 'Please select a product.', [], 422);
}

if (!in_array($mode, ['add', 'subtract', 'set'], true)) {
    apiResponse(false, 'Invalid adjustment mode.', [], 422);
}

if ($quantity < 0 || $weight < 0 || $rate < 0) {
    apiResponse(false, 'Quantity, weight and rate cannot be negative.', [], 422);
}

if ($quantity == 0.0 && $weight == 0.0) {
    apiResponse(false, 'Enter quantity or weight.', [], 422);
}

$stmt = $conn->prepare(
    'SELECT id, product_name
     FROM products
     WHERE id = ? AND business_id = ?
     LIMIT 1'
);

if (!$stmt) {
    apiResponse(false, 'Unable to prepare product query: ' . $conn->error, [], 500);
}

$stmt->bind_param('ii', $productId, $businessId);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$product) {
    apiResponse(false, 'Product not found.', [], 404);
}

$conn->begin_transaction();

try {
    $stmt = $conn->prepare(
        'SELECT id, quantity, gross_weight, net_weight, stock_value
         FROM product_stock
         WHERE business_id = ? AND branch_id = ? AND product_id = ?
         LIMIT 1
         FOR UPDATE'
    );

    if (!$stmt) {
        throw new Exception('Unable to prepare stock lookup: ' . $conn->error);
    }

    $stmt->bind_param('iii', $businessId, $branchId, $productId);
    $stmt->execute();
    $stock = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $oldQty = (float)($stock['quantity'] ?? 0);
    $oldGross = (float)($stock['gross_weight'] ?? 0);
    $oldNet = (float)($stock['net_weight'] ?? 0);
    $oldValue = (float)($stock['stock_value'] ?? 0);

    if ($mode === 'add') {
        $newQty = $oldQty + $quantity;
        $newGross = $oldGross + $weight;
        $newNet = $oldNet + $weight;
        $qtyIn = $quantity;
        $qtyOut = 0.0;
        $weightIn = $weight;
        $weightOut = 0.0;
    } elseif ($mode === 'subtract') {
        if ($quantity > $oldQty || $weight > $oldNet) {
            throw new Exception('Adjustment exceeds available stock.');
        }

        $newQty = $oldQty - $quantity;
        $newGross = max(0, $oldGross - $weight);
        $newNet = max(0, $oldNet - $weight);
        $qtyIn = 0.0;
        $qtyOut = $quantity;
        $weightIn = 0.0;
        $weightOut = $weight;
    } else {
        $newQty = $quantity;
        $newGross = $weight;
        $newNet = $weight;
        $qtyDifference = $newQty - $oldQty;
        $weightDifference = $newNet - $oldNet;
        $qtyIn = max(0, $qtyDifference);
        $qtyOut = max(0, -$qtyDifference);
        $weightIn = max(0, $weightDifference);
        $weightOut = max(0, -$weightDifference);
    }

    $valueAmount = ($qtyIn - $qtyOut) * $rate;
    $newStockValue = max(0, $oldValue + $valueAmount);
    $averageCost = $newQty > 0 ? $newStockValue / $newQty : 0.0;

    if ($stock) {
        $stockId = (int)$stock['id'];

        $stmt = $conn->prepare(
            'UPDATE product_stock
             SET quantity = ?, gross_weight = ?, net_weight = ?,
                 average_cost = ?, stock_value = ?
             WHERE id = ?'
        );

        if (!$stmt) {
            throw new Exception('Unable to prepare stock update: ' . $conn->error);
        }

        $stmt->bind_param(
            'dddddi',
            $newQty,
            $newGross,
            $newNet,
            $averageCost,
            $newStockValue,
            $stockId
        );
    } else {
        $stmt = $conn->prepare(
            'INSERT INTO product_stock
             (business_id, branch_id, product_id, quantity, gross_weight,
              net_weight, average_cost, stock_value)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );

        if (!$stmt) {
            throw new Exception('Unable to prepare stock insert: ' . $conn->error);
        }

        $stmt->bind_param(
            'iiiddddd',
            $businessId,
            $branchId,
            $productId,
            $newQty,
            $newGross,
            $newNet,
            $averageCost,
            $newStockValue
        );
    }

    if (!$stmt->execute()) {
        throw new Exception('Unable to save product stock: ' . $stmt->error);
    }

    $stockId = $stock ? (int)$stock['id'] : (int)$stmt->insert_id;
    $stmt->close();

    $stmt = $conn->prepare(
        "INSERT INTO stock_movements
         (
            business_id, branch_id, product_id, movement_date,
            movement_type, reference_table, reference_id,
            quantity_in, quantity_out, weight_in, weight_out,
            rate, value_amount, remarks, created_by
         )
         VALUES (?, ?, ?, ?, 'Adjustment', 'product_stock', ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    if (!$stmt) {
        throw new Exception('Unable to prepare movement insert: ' . $conn->error);
    }

    $stmt->bind_param(
        'iiisiddddddsi',
        $businessId,
        $branchId,
        $productId,
        $movementDate,
        $stockId,
        $qtyIn,
        $qtyOut,
        $weightIn,
        $weightOut,
        $rate,
        $valueAmount,
        $remarks,
        $userId
    );

    if (!$stmt->execute()) {
        throw new Exception('Unable to insert stock movement: ' . $stmt->error);
    }

    $movementId = (int)$stmt->insert_id;
    $stmt->close();

    addAuditLogSafe(
        $conn,
        $businessId,
        $userId,
        'Stock',
        'Adjustment',
        $movementId,
        'Adjusted stock for ' . (string)$product['product_name']
    );

    $conn->commit();

    apiResponse(
        true,
        'Stock adjustment saved successfully.',
        [
            'movement_id' => $movementId,
            'product_id' => $productId,
            'quantity' => $newQty,
            'gross_weight' => $newGross,
            'net_weight' => $newNet,
            'average_cost' => $averageCost,
            'stock_value' => $newStockValue,
        ]
    );
} catch (Throwable $e) {
    $conn->rollback();
    apiResponse(false, $e->getMessage(), [], 500);
}
