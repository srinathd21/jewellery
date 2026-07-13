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

if (!$configLoaded || !isset($conn) || !($conn instanceof mysqli)) {
    apiResponse(false, 'Database configuration is not available.', [], 500);
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn->set_charset('utf8mb4');

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

function hasStockPermission(): bool
{
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin') {
        return true;
    }

    $roleName = strtolower(trim((string)($_SESSION['role_name'] ?? '')));
    $roleCode = strtolower(trim((string)($_SESSION['role_code'] ?? '')));

    if (
        in_array($roleName, ['admin', 'business admin', 'manager', 'stock'], true) ||
        in_array($roleCode, ['admin', 'business_admin', 'manager', 'stock'], true)
    ) {
        return true;
    }

    $permissions = $_SESSION['permissions'] ?? [];

    foreach (['perm.stock', 'perm.inventory'] as $code) {
        if (
            isset($permissions[$code]) &&
            (
                (int)($permissions[$code]['can_open'] ?? 0) === 1 ||
                (int)($permissions[$code]['can_view'] ?? 0) === 1
            )
        ) {
            return true;
        }
    }

    return false;
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

[$userId, $businessId, $branchId] = requireLogin();

if (!hasStockPermission()) {
    apiResponse(false, 'Access denied.', [], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    apiResponse(false, 'GET method required.', [], 405);
}

if (!tableExists($conn, 'stock_movements') || !tableExists($conn, 'products')) {
    apiResponse(false, 'Required stock tables are missing.', [], 500);
}

$search = trim((string)($_GET['search'] ?? ''));
$movementType = trim((string)($_GET['movement_type'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$productId = (int)($_GET['product_id'] ?? 0);
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = min(200, max(10, (int)($_GET['limit'] ?? 50)));
$offset = ($page - 1) * $limit;

$refTableColumn = hasColumn($conn, 'stock_movements', 'reference_table')
    ? 'reference_table'
    : (hasColumn($conn, 'stock_movements', 'ref_table') ? 'ref_table' : null);

$refIdColumn = hasColumn($conn, 'stock_movements', 'reference_id')
    ? 'reference_id'
    : (hasColumn($conn, 'stock_movements', 'ref_id') ? 'ref_id' : null);

$qtyInColumn = hasColumn($conn, 'stock_movements', 'quantity_in')
    ? 'quantity_in'
    : (hasColumn($conn, 'stock_movements', 'qty_in') ? 'qty_in' : null);

$qtyOutColumn = hasColumn($conn, 'stock_movements', 'quantity_out')
    ? 'quantity_out'
    : (hasColumn($conn, 'stock_movements', 'qty_out') ? 'qty_out' : null);

$weightInColumn = hasColumn($conn, 'stock_movements', 'weight_in') ? 'weight_in' : null;
$weightOutColumn = hasColumn($conn, 'stock_movements', 'weight_out') ? 'weight_out' : null;

$productSql = "
    SELECT id, product_name, product_code
    FROM products
    WHERE business_id = ?
";

if (hasColumn($conn, 'products', 'is_active')) {
    $productSql .= " AND is_active = 1";
}

$productSql .= " ORDER BY product_name ASC";

$stmt = $conn->prepare($productSql);

if (!$stmt) {
    apiResponse(false, 'Unable to prepare product list: ' . $conn->error, [], 500);
}

$stmt->bind_param('i', $businessId);
$stmt->execute();

$products = [];
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $products[] = $row;
}

$stmt->close();

$where = ['sm.business_id = ?'];
$params = [$businessId];
$types = 'i';

if ($branchId > 0 && hasColumn($conn, 'stock_movements', 'branch_id')) {
    $where[] = 'sm.branch_id = ?';
    $params[] = $branchId;
    $types .= 'i';
}

if ($search !== '') {
    $searchParts = ['p.product_name LIKE ?', 'p.product_code LIKE ?'];
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $types .= 'ss';

    if (hasColumn($conn, 'products', 'barcode')) {
        $searchParts[] = 'p.barcode LIKE ?';
        $params[] = $like;
        $types .= 's';
    }

    if (hasColumn($conn, 'stock_movements', 'remarks')) {
        $searchParts[] = 'sm.remarks LIKE ?';
        $params[] = $like;
        $types .= 's';
    }

    if ($refTableColumn !== null) {
        $searchParts[] = "sm.{$refTableColumn} LIKE ?";
        $params[] = $like;
        $types .= 's';
    }

    $where[] = '(' . implode(' OR ', $searchParts) . ')';
}

if ($movementType !== '') {
    $where[] = 'sm.movement_type = ?';
    $params[] = $movementType;
    $types .= 's';
}

if ($productId > 0) {
    $where[] = 'sm.product_id = ?';
    $params[] = $productId;
    $types .= 'i';
}

if ($dateFrom !== '') {
    $where[] = 'DATE(sm.movement_date) >= ?';
    $params[] = $dateFrom;
    $types .= 's';
}

if ($dateTo !== '') {
    $where[] = 'DATE(sm.movement_date) <= ?';
    $params[] = $dateTo;
    $types .= 's';
}

$whereSql = implode(' AND ', $where);

$qtyInExpr = $qtyInColumn ? "COALESCE(sm.{$qtyInColumn}, 0)" : '0';
$qtyOutExpr = $qtyOutColumn ? "COALESCE(sm.{$qtyOutColumn}, 0)" : '0';
$weightInExpr = $weightInColumn ? "COALESCE(sm.{$weightInColumn}, 0)" : '0';
$weightOutExpr = $weightOutColumn ? "COALESCE(sm.{$weightOutColumn}, 0)" : '0';

$summarySql = "
    SELECT
        COUNT(*) AS total_movements,
        SUM({$qtyInExpr}) AS total_qty_in,
        SUM({$qtyOutExpr}) AS total_qty_out,
        SUM({$weightInExpr}) AS total_weight_in,
        SUM({$weightOutExpr}) AS total_weight_out
    FROM stock_movements sm
    INNER JOIN products p
        ON p.id = sm.product_id
       AND p.business_id = sm.business_id
    WHERE {$whereSql}
";

$stmt = $conn->prepare($summarySql);

if (!$stmt) {
    apiResponse(false, 'Unable to prepare summary query: ' . $conn->error, [], 500);
}

$summaryParams = $params;
bindDynamic($stmt, $types, $summaryParams);
$stmt->execute();
$summary = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();

$countSql = "
    SELECT COUNT(*) AS total
    FROM stock_movements sm
    INNER JOIN products p
        ON p.id = sm.product_id
       AND p.business_id = sm.business_id
    WHERE {$whereSql}
";

$stmt = $conn->prepare($countSql);

if (!$stmt) {
    apiResponse(false, 'Unable to prepare count query: ' . $conn->error, [], 500);
}

$countParams = $params;
bindDynamic($stmt, $types, $countParams);
$stmt->execute();
$total = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
$stmt->close();

$refTableExpr = $refTableColumn ? "sm.{$refTableColumn}" : "''";
$refIdExpr = $refIdColumn ? "sm.{$refIdColumn}" : "NULL";
$createdByNameExpr = tableExists($conn, 'users') && hasColumn($conn, 'stock_movements', 'created_by')
    ? "COALESCE(u.full_name, u.username, '')"
    : "''";

$sql = "
    SELECT
        sm.id,
        sm.movement_date,
        sm.movement_type,
        {$refTableExpr} AS reference_table,
        {$refIdExpr} AS reference_id,
        {$qtyInExpr} AS quantity_in,
        {$qtyOutExpr} AS quantity_out,
        {$weightInExpr} AS weight_in,
        {$weightOutExpr} AS weight_out,
        " . (hasColumn($conn, 'stock_movements', 'rate') ? "COALESCE(sm.rate, 0)" : "0") . " AS rate,
        " . (hasColumn($conn, 'stock_movements', 'value_amount') ? "COALESCE(sm.value_amount, 0)" : "0") . " AS value_amount,
        " . (hasColumn($conn, 'stock_movements', 'remarks') ? "COALESCE(sm.remarks, '')" : "''") . " AS remarks,
        p.product_name,
        p.product_code,
        " . (hasColumn($conn, 'products', 'barcode') ? "COALESCE(p.barcode, '')" : "''") . " AS barcode,
        " . (hasColumn($conn, 'products', 'unit') ? "COALESCE(p.unit, 'pcs')" : "'pcs'") . " AS unit,
        {$createdByNameExpr} AS created_by_name
    FROM stock_movements sm
    INNER JOIN products p
        ON p.id = sm.product_id
       AND p.business_id = sm.business_id
    " . (tableExists($conn, 'users') && hasColumn($conn, 'stock_movements', 'created_by')
        ? "LEFT JOIN users u ON u.id = sm.created_by"
        : "") . "
    WHERE {$whereSql}
    ORDER BY sm.movement_date DESC, sm.id DESC
    LIMIT ? OFFSET ?
";

$params[] = $limit;
$params[] = $offset;
$types .= 'ii';

$stmt = $conn->prepare($sql);

if (!$stmt) {
    apiResponse(false, 'Unable to prepare stock movement query: ' . $conn->error, [], 500);
}

bindDynamic($stmt, $types, $params);
$stmt->execute();

$rows = [];
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $rows[] = $row;
}

$stmt->close();

apiResponse(
    true,
    'Stock movements loaded.',
    [
        'products' => $products,
        'movement_types' => [
            'Opening',
            'Purchase',
            'Sale',
            'Sale Return',
            'Purchase Return',
            'Adjustment',
            'Old Silver Inward',
            'Damage',
            'Manual',
        ],
        'summary' => [
            'total_movements' => (int)($summary['total_movements'] ?? 0),
            'total_qty_in' => (float)($summary['total_qty_in'] ?? 0),
            'total_qty_out' => (float)($summary['total_qty_out'] ?? 0),
            'total_weight_in' => (float)($summary['total_weight_in'] ?? 0),
            'total_weight_out' => (float)($summary['total_weight_out'] ?? 0),
            'net_weight_change' =>
                (float)($summary['total_weight_in'] ?? 0) -
                (float)($summary['total_weight_out'] ?? 0),
        ],
        'rows' => $rows,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => max(1, (int)ceil($total / $limit)),
        ],
    ]
);
