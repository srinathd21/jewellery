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

if (!hasStockPermission()) {
    apiResponse(false, 'Access denied.', [], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    apiResponse(false, 'GET method required.', [], 405);
}

if (
    !tableExists($conn, 'products') ||
    !tableExists($conn, 'product_categories')
) {
    apiResponse(false, 'Required product tables are missing.', [], 500);
}

$hasProductStock = tableExists($conn, 'product_stock');
$hasStockMovements = tableExists($conn, 'stock_movements');

$search = trim((string)($_GET['search'] ?? ''));
$categoryId = (int)($_GET['category_id'] ?? 0);
$stockStatus = trim((string)($_GET['stock_status'] ?? 'all'));
$productStatus = trim((string)($_GET['status'] ?? 'all'));
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = min(200, max(10, (int)($_GET['limit'] ?? 50)));
$offset = ($page - 1) * $limit;

$branchJoin = '';
if ($hasProductStock && hasColumn($conn, 'product_stock', 'branch_id') && $branchId > 0) {
    $branchJoin = ' AND ps.branch_id = ' . (int)$branchId;
}

$stockQtyExpr = $hasProductStock && hasColumn($conn, 'product_stock', 'quantity')
    ? 'COALESCE(ps.quantity, 0)'
    : (hasColumn($conn, 'products', 'current_stock_qty') ? 'COALESCE(p.current_stock_qty, 0)' : '0');

$stockWeightExpr = $hasProductStock && hasColumn($conn, 'product_stock', 'net_weight')
    ? 'COALESCE(ps.net_weight, 0)'
    : (hasColumn($conn, 'products', 'net_weight') ? 'COALESCE(p.net_weight, 0)' : '0');

$grossWeightExpr = $hasProductStock && hasColumn($conn, 'product_stock', 'gross_weight')
    ? 'COALESCE(ps.gross_weight, 0)'
    : (hasColumn($conn, 'products', 'gross_weight') ? 'COALESCE(p.gross_weight, 0)' : '0');

$averageCostExpr = $hasProductStock && hasColumn($conn, 'product_stock', 'average_cost')
    ? 'COALESCE(ps.average_cost, 0)'
    : '0';

$stockValueExpr = $hasProductStock && hasColumn($conn, 'product_stock', 'stock_value')
    ? 'COALESCE(ps.stock_value, 0)'
    : '0';

$lastMovementJoin = '';
$lastMovementExpr = 'NULL';

if (
    $hasStockMovements &&
    hasColumn($conn, 'stock_movements', 'product_id') &&
    hasColumn($conn, 'stock_movements', 'movement_date')
) {
    $movementWhere = 'business_id = ' . (int)$businessId;

    if (hasColumn($conn, 'stock_movements', 'branch_id') && $branchId > 0) {
        $movementWhere .= ' AND branch_id = ' . (int)$branchId;
    }

    $lastMovementJoin = "
        LEFT JOIN (
            SELECT product_id, MAX(movement_date) AS last_movement_date
            FROM stock_movements
            WHERE {$movementWhere}
            GROUP BY product_id
        ) smx ON smx.product_id = p.id
    ";

    $lastMovementExpr = 'smx.last_movement_date';
}

$categorySql = "
    SELECT id, category_code, category_name
    FROM product_categories
    WHERE business_id = ?
";

if (hasColumn($conn, 'product_categories', 'is_active')) {
    $categorySql .= " AND is_active = 1";
}

$categorySql .= " ORDER BY category_name ASC";

$stmt = $conn->prepare($categorySql);

if (!$stmt) {
    apiResponse(false, 'Unable to prepare category query: ' . $conn->error, [], 500);
}

$stmt->bind_param('i', $businessId);
$stmt->execute();

$categories = [];
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $categories[] = $row;
}

$stmt->close();

$where = ['p.business_id = ?'];
$params = [$businessId];
$types = 'i';

if ($search !== '') {
    $parts = ['p.product_name LIKE ?', 'p.product_code LIKE ?'];
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $types .= 'ss';

    if (hasColumn($conn, 'products', 'barcode')) {
        $parts[] = 'p.barcode LIKE ?';
        $params[] = $like;
        $types .= 's';
    }

    if (hasColumn($conn, 'products', 'design_name')) {
        $parts[] = 'p.design_name LIKE ?';
        $params[] = $like;
        $types .= 's';
    }

    $parts[] = 'pc.category_name LIKE ?';
    $params[] = $like;
    $types .= 's';

    $where[] = '(' . implode(' OR ', $parts) . ')';
}

if ($categoryId > 0) {
    $where[] = 'p.category_id = ?';
    $params[] = $categoryId;
    $types .= 'i';
}

if (hasColumn($conn, 'products', 'is_active')) {
    if ($productStatus === 'active') {
        $where[] = 'p.is_active = 1';
    } elseif ($productStatus === 'inactive') {
        $where[] = 'p.is_active = 0';
    }
}

if ($stockStatus === 'in_stock') {
    $where[] = "{$stockQtyExpr} > 0";
} elseif ($stockStatus === 'out_of_stock') {
    $where[] = "{$stockQtyExpr} <= 0";
} elseif ($stockStatus === 'low_stock' && hasColumn($conn, 'products', 'min_stock_qty')) {
    $where[] = "{$stockQtyExpr} > 0 AND {$stockQtyExpr} <= COALESCE(p.min_stock_qty, 0) AND COALESCE(p.min_stock_qty, 0) > 0";
}

$whereSql = implode(' AND ', $where);

$productStockJoin = '';
if ($hasProductStock) {
    $productStockJoin = "
        LEFT JOIN product_stock ps
            ON ps.product_id = p.id
           AND ps.business_id = p.business_id
           {$branchJoin}
    ";
}

$summarySql = "
    SELECT
        COUNT(*) AS total_products,
        SUM(CASE WHEN {$stockQtyExpr} > 0 THEN 1 ELSE 0 END) AS in_stock_count,
        SUM(
            CASE
                WHEN " . (hasColumn($conn, 'products', 'min_stock_qty')
                    ? "{$stockQtyExpr} > 0 AND {$stockQtyExpr} <= COALESCE(p.min_stock_qty, 0) AND COALESCE(p.min_stock_qty, 0) > 0"
                    : "0 = 1") . "
                THEN 1 ELSE 0
            END
        ) AS low_stock_count,
        SUM(CASE WHEN {$stockQtyExpr} <= 0 THEN 1 ELSE 0 END) AS out_of_stock_count,
        SUM({$stockQtyExpr}) AS total_stock_qty,
        SUM({$stockWeightExpr}) AS total_stock_weight,
        SUM({$stockValueExpr}) AS total_stock_value
    FROM products p
    LEFT JOIN product_categories pc
        ON pc.id = p.category_id
       AND pc.business_id = p.business_id
    {$productStockJoin}
    WHERE {$whereSql}
";

$stmt = $conn->prepare($summarySql);

if (!$stmt) {
    apiResponse(false, 'Unable to prepare stock summary query: ' . $conn->error, [], 500);
}

$summaryParams = $params;
bindDynamic($stmt, $types, $summaryParams);
$stmt->execute();
$summary = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();

$countSql = "
    SELECT COUNT(*) AS total
    FROM products p
    LEFT JOIN product_categories pc
        ON pc.id = p.category_id
       AND pc.business_id = p.business_id
    {$productStockJoin}
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

$sql = "
    SELECT
        p.id,
        p.product_code,
        p.product_name,
        p.category_id,
        pc.category_name,
        " . (hasColumn($conn, 'products', 'barcode') ? "p.barcode" : "''") . " AS barcode,
        " . (hasColumn($conn, 'products', 'design_name') ? "p.design_name" : "''") . " AS design_name,
        " . (hasColumn($conn, 'products', 'purity') ? "p.purity" : "''") . " AS purity,
        " . (hasColumn($conn, 'products', 'unit') ? "p.unit" : "'pcs'") . " AS unit,
        " . (hasColumn($conn, 'products', 'min_stock_qty') ? "p.min_stock_qty" : "0") . " AS min_stock_qty,
        " . (hasColumn($conn, 'products', 'sale_rate') ? "p.sale_rate" : "0") . " AS sale_rate,
        " . (hasColumn($conn, 'products', 'image_path') ? "p.image_path" : "''") . " AS image_path,
        " . (hasColumn($conn, 'products', 'is_active') ? "p.is_active" : "1") . " AS is_active,
        {$stockQtyExpr} AS stock_qty,
        {$grossWeightExpr} AS gross_weight,
        {$stockWeightExpr} AS stock_weight,
        {$averageCostExpr} AS average_cost,
        {$stockValueExpr} AS stock_value,
        {$lastMovementExpr} AS last_movement_date,
        " . ($hasProductStock && hasColumn($conn, 'product_stock', 'updated_at')
            ? "ps.updated_at"
            : (hasColumn($conn, 'products', 'updated_at') ? "p.updated_at" : "NULL")) . " AS stock_updated_at
    FROM products p
    LEFT JOIN product_categories pc
        ON pc.id = p.category_id
       AND pc.business_id = p.business_id
    {$productStockJoin}
    {$lastMovementJoin}
    WHERE {$whereSql}
    ORDER BY p.id DESC
    LIMIT ? OFFSET ?
";

$params[] = $limit;
$params[] = $offset;
$types .= 'ii';

$stmt = $conn->prepare($sql);

if (!$stmt) {
    apiResponse(false, 'Unable to prepare stock overview query: ' . $conn->error, [], 500);
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
    'Stock overview loaded.',
    [
        'categories' => $categories,
        'summary' => [
            'total_products' => (int)($summary['total_products'] ?? 0),
            'in_stock_count' => (int)($summary['in_stock_count'] ?? 0),
            'low_stock_count' => (int)($summary['low_stock_count'] ?? 0),
            'out_of_stock_count' => (int)($summary['out_of_stock_count'] ?? 0),
            'total_stock_qty' => (float)($summary['total_stock_qty'] ?? 0),
            'total_stock_weight' => (float)($summary['total_stock_weight'] ?? 0),
            'total_stock_value' => (float)($summary['total_stock_value'] ?? 0),
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
