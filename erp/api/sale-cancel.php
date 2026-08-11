<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));

header('Content-Type: application/json; charset=utf-8');

foreach ([
    dirname(__DIR__) . '/config/config.php',
    dirname(__DIR__) . '/config.php',
    dirname(__DIR__) . '/includes/config.php',
    dirname(__DIR__) . '/super-admin/includes/config.php',
] as $configFile) {
    if (is_file($configFile)) {
        require_once $configFile;
        break;
    }
}

function out(bool $success, string $message, array $data = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode(
        array_merge(['success' => $success, 'message' => $message], $data),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    out(false, 'Database configuration is not available.', [], 500);
}
$conn->set_charset('utf8mb4');

if (empty($_SESSION['user_id'])) {
    out(false, 'Session expired.', [], 401);
}

function tableExists(mysqli $conn, string $table): bool
{
    static $cache = [];
    $key = strtolower($table);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $safe = $conn->real_escape_string($table);
    $r = $conn->query("SHOW TABLES LIKE '{$safe}'");
    $cache[$key] = (bool)($r && $r->num_rows > 0);
    return $cache[$key];
}

function hasColumn(mysqli $conn, string $table, string $column): bool
{
    static $cache = [];
    $key = strtolower($table . '.' . $column);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $safeTable = $conn->real_escape_string($table);
    $safeColumn = $conn->real_escape_string($column);
    $r = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    $cache[$key] = (bool)($r && $r->num_rows > 0);
    return $cache[$key];
}

function bindDynamic(mysqli_stmt $stmt, string $types, array &$values): void
{
    if ($types === '' || !$values) {
        return;
    }

    $args = [$types];
    foreach ($values as $key => $value) {
        $args[] = &$values[$key];
    }
    call_user_func_array([$stmt, 'bind_param'], $args);
}

function canCancelSale(mysqli $conn): bool
{
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin') {
        return true;
    }

    $permissions = $_SESSION['permissions'] ?? [];
    foreach (['perm.sales.list', 'perm.sales', 'perm.billing'] as $code) {
        if (!empty($permissions[$code]['can_delete']) || !empty($permissions[$code]['can_update'])) {
            return true;
        }
    }

    $businessId = (int)($_SESSION['business_id'] ?? 0);
    $roleId = (int)($_SESSION['role_id'] ?? 0);

    if (
        $businessId <= 0 ||
        $roleId <= 0 ||
        !tableExists($conn, 'permissions') ||
        !tableExists($conn, 'role_permissions')
    ) {
        return false;
    }

    $sql = "SELECT MAX(
                GREATEST(
                    COALESCE(rp.can_delete,0),
                    COALESCE(rp.can_update,0)
                )
            ) AS allowed
            FROM role_permissions rp
            INNER JOIN permissions p ON p.id=rp.permission_id
            WHERE rp.business_id=?
              AND rp.role_id=?
              AND p.permission_code IN ('perm.sales.list','perm.sales','perm.billing')";

    if (hasColumn($conn, 'permissions', 'is_active')) {
        $sql .= ' AND p.is_active=1';
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ii', $businessId, $roleId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row['allowed'] ?? 0) === 1;
}

function insertAuditLog(
    mysqli $conn,
    int $businessId,
    int $branchId,
    int $userId,
    int $saleId,
    string $invoiceNo,
    string $reason,
    array $oldValues,
    array $newValues
): void {
    if (!tableExists($conn, 'audit_logs')) {
        return;
    }

    $oldJson = json_encode($oldValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $newJson = json_encode($newValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $fieldValues = [
        'business_id' => [$businessId, 'i'],
        'branch_id' => [$branchId, 'i'],
        'user_id' => [$userId, 'i'],
        'module_code' => ['billing.sales', 's'],
        'module_name' => ['Sales', 's'],
        'action_type' => ['Cancel', 's'],
        'reference_table' => ['sales', 's'],
        'reference_id' => [$saleId, 'i'],
        'description' => ['Cancelled invoice ' . $invoiceNo . '. Reason: ' . $reason, 's'],
        'old_values_json' => [$oldJson === false ? null : $oldJson, 's'],
        'new_values_json' => [$newJson === false ? null : $newJson, 's'],
        'ip_address' => [(string)($_SERVER['REMOTE_ADDR'] ?? ''), 's'],
        'user_agent' => [substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500), 's'],
        'created_by' => [$userId, 'i'],
    ];

    $columns = [];
    $placeholders = [];
    $types = '';
    $params = [];

    foreach ($fieldValues as $column => $pair) {
        if (hasColumn($conn, 'audit_logs', $column)) {
            $columns[] = "`{$column}`";
            $placeholders[] = '?';
            $types .= $pair[1];
            $params[] = $pair[0];
        }
    }

    if (hasColumn($conn, 'audit_logs', 'created_at')) {
        $columns[] = '`created_at`';
        $placeholders[] = 'NOW()';
    }

    if (!$columns) {
        return;
    }

    $stmt = $conn->prepare(
        'INSERT INTO audit_logs (' . implode(',', $columns) . ')
         VALUES (' . implode(',', $placeholders) . ')'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare audit log: ' . $conn->error);
    }

    bindDynamic($stmt, $types, $params);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to save cancellation audit: ' . $error);
    }
    $stmt->close();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    out(false, 'Invalid request method.', [], 405);
}

$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? ($_SESSION['default_branch_id'] ?? 0));
$userId = (int)($_SESSION['user_id'] ?? 0);
$saleId = (int)($_POST['sale_id'] ?? 0);
$reason = trim((string)($_POST['cancel_reason'] ?? ''));
$csrf = (string)($_POST['csrf_token'] ?? '');

if ($businessId <= 0 || $branchId <= 0 || $userId <= 0) {
    out(false, 'A valid business, branch and user are required.', [], 403);
}

if (
    empty($_SESSION['sales_csrf']) ||
    !hash_equals((string)$_SESSION['sales_csrf'], $csrf)
) {
    out(false, 'Session expired. Refresh the page and try again.', [], 419);
}

if (!canCancelSale($conn)) {
    out(false, 'You do not have permission to cancel sales.', [], 403);
}

if ($saleId <= 0) {
    out(false, 'Invalid sale selected.', [], 422);
}

if ($reason === '') {
    out(false, 'Cancellation reason is required.', [], 422);
}

if (!tableExists($conn, 'sales') || !tableExists($conn, 'sale_items')) {
    out(false, 'Sales tables are not available.', [], 500);
}

try {
    $conn->begin_transaction();

    $stmt = $conn->prepare(
        'SELECT * FROM sales
         WHERE id=? AND business_id=? AND branch_id=?
         LIMIT 1 FOR UPDATE'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare sale lookup: ' . $conn->error);
    }

    $stmt->bind_param('iii', $saleId, $businessId, $branchId);
    if (!$stmt->execute()) {
        throw new RuntimeException('Unable to read sale: ' . $stmt->error);
    }

    $sale = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$sale) {
        throw new RuntimeException('Sale not found.');
    }

    $currentStatus = '';
    foreach (['workflow_status', 'status'] as $statusColumn) {
        if (isset($sale[$statusColumn]) && trim((string)$sale[$statusColumn]) !== '') {
            $currentStatus = trim((string)$sale[$statusColumn]);
            break;
        }
    }

    if (strcasecmp($currentStatus, 'Cancelled') === 0) {
        throw new RuntimeException('This invoice is already cancelled.');
    }

    $invoiceNo = (string)($sale['invoice_no'] ?? $sale['invoice_number'] ?? ('Sale #' . $saleId));

    /*
     * Read sale items and restore only stock-tracked products.
     * The billing save path reduces quantity/gross/net from product_stock,
     * so cancellation reverses those exact fields.
     */
    $sql = "SELECT
                si.product_id,
                SUM(COALESCE(si.quantity,0)) AS qty,
                SUM(COALESCE(si.gross_weight,0)) AS gross_weight,
                SUM(COALESCE(si.net_weight,0)) AS net_weight,
                MAX(COALESCE(si.item_name,'')) AS item_name,
                MAX(COALESCE(si.metal_rate,0)) AS metal_rate";

    if (tableExists($conn, 'products') && hasColumn($conn, 'products', 'track_stock')) {
        $sql .= ", MAX(COALESCE(p.track_stock,1)) AS track_stock";
    } else {
        $sql .= ", 1 AS track_stock";
    }

    $sql .= " FROM sale_items si";

    if (tableExists($conn, 'products')) {
        $sql .= " LEFT JOIN products p ON p.id=si.product_id";
    }

    $sql .= " WHERE si.sale_id=? AND si.business_id=? AND si.product_id IS NOT NULL
              GROUP BY si.product_id";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare sale item restock lookup: ' . $conn->error);
    }

    $stmt->bind_param('ii', $saleId, $businessId);
    if (!$stmt->execute()) {
        throw new RuntimeException('Unable to read sale items: ' . $stmt->error);
    }

    $itemsResult = $stmt->get_result();
    $restockRows = [];
    while ($row = $itemsResult->fetch_assoc()) {
        if ((int)($row['track_stock'] ?? 1) !== 1) {
            continue;
        }

        $productId = (int)($row['product_id'] ?? 0);
        if ($productId <= 0) {
            continue;
        }

        $restockRows[] = [
            'product_id' => $productId,
            'item_name' => (string)($row['item_name'] ?? ''),
            'quantity' => max(0, (float)($row['qty'] ?? 0)),
            'gross_weight' => max(0, (float)($row['gross_weight'] ?? 0)),
            'net_weight' => max(0, (float)($row['net_weight'] ?? 0)),
            'metal_rate' => max(0, (float)($row['metal_rate'] ?? 0)),
        ];
    }
    $stmt->close();

    $restocked = 0;
    $restockDetails = [];

    if ($restockRows && tableExists($conn, 'product_stock')) {
        foreach ($restockRows as $row) {
            $productId = (int)$row['product_id'];
            $qty = (float)$row['quantity'];
            $gross = (float)$row['gross_weight'];
            $net = (float)$row['net_weight'];

            $updates = [];
            $types = '';
            $params = [];

            if (hasColumn($conn, 'product_stock', 'quantity')) {
                $updates[] = 'quantity=COALESCE(quantity,0)+?';
                $types .= 'd';
                $params[] = $qty;
            }
            if (hasColumn($conn, 'product_stock', 'gross_weight')) {
                $updates[] = 'gross_weight=COALESCE(gross_weight,0)+?';
                $types .= 'd';
                $params[] = $gross;
            }
            if (hasColumn($conn, 'product_stock', 'net_weight')) {
                $updates[] = 'net_weight=COALESCE(net_weight,0)+?';
                $types .= 'd';
                $params[] = $net;
            }
            if (hasColumn($conn, 'product_stock', 'updated_at')) {
                $updates[] = 'updated_at=NOW()';
            }

            if (!$updates) {
                continue;
            }

            $types .= 'iii';
            $params[] = $businessId;
            $params[] = $branchId;
            $params[] = $productId;

            $update = $conn->prepare(
                'UPDATE product_stock SET ' . implode(',', $updates) .
                ' WHERE business_id=? AND branch_id=? AND product_id=?'
            );
            if (!$update) {
                throw new RuntimeException('Unable to prepare stock restoration: ' . $conn->error);
            }

            bindDynamic($update, $types, $params);
            if (!$update->execute()) {
                $error = $update->error;
                $update->close();
                throw new RuntimeException('Unable to restore stock: ' . $error);
            }

            /*
             * If no product_stock row exists, create one with the returned stock.
             */
            if ($update->affected_rows < 1) {
                $update->close();

                $columns = ['business_id', 'branch_id', 'product_id'];
                $ph = ['?', '?', '?'];
                $insertTypes = 'iii';
                $insertParams = [$businessId, $branchId, $productId];

                foreach ([
                    'quantity' => $qty,
                    'gross_weight' => $gross,
                    'net_weight' => $net
                ] as $column => $value) {
                    if (hasColumn($conn, 'product_stock', $column)) {
                        $columns[] = "`{$column}`";
                        $ph[] = '?';
                        $insertTypes .= 'd';
                        $insertParams[] = $value;
                    }
                }

                if (hasColumn($conn, 'product_stock', 'created_at')) {
                    $columns[] = '`created_at`';
                    $ph[] = 'NOW()';
                }
                if (hasColumn($conn, 'product_stock', 'updated_at')) {
                    $columns[] = '`updated_at`';
                    $ph[] = 'NOW()';
                }

                $insert = $conn->prepare(
                    'INSERT INTO product_stock (' . implode(',', $columns) . ')
                     VALUES (' . implode(',', $ph) . ')'
                );
                if (!$insert) {
                    throw new RuntimeException('Unable to prepare restored stock insert: ' . $conn->error);
                }

                bindDynamic($insert, $insertTypes, $insertParams);
                if (!$insert->execute()) {
                    $error = $insert->error;
                    $insert->close();
                    throw new RuntimeException('Unable to create restored stock row: ' . $error);
                }
                $insert->close();
            } else {
                $update->close();
            }

            /*
             * Record a reversing stock movement using the same schema used by billing-save.
             */
            if (tableExists($conn, 'stock_movements')) {
                $fieldValues = [
                    'business_id' => [$businessId, 'i'],
                    'branch_id' => [$branchId, 'i'],
                    'product_id' => [$productId, 'i'],
                    'movement_type' => ['Sale Cancellation', 's'],
                    'reference_table' => ['sales', 's'],
                    'reference_id' => [$saleId, 'i'],
                    'quantity_in' => [$qty, 'd'],
                    'quantity_out' => [0.0, 'd'],
                    'weight_in' => [$net, 'd'],
                    'weight_out' => [0.0, 'd'],
                    'rate' => [(float)$row['metal_rate'], 'd'],
                    'value_amount' => [0.0, 'd'],
                    'remarks' => ['Cancelled sale ' . $invoiceNo . ': ' . $reason, 's'],
                    'created_by' => [$userId, 'i'],
                ];

                $columns = [];
                $ph = [];
                $moveTypes = '';
                $moveParams = [];

                foreach ($fieldValues as $column => $pair) {
                    if (hasColumn($conn, 'stock_movements', $column)) {
                        $columns[] = "`{$column}`";
                        $ph[] = '?';
                        $moveTypes .= $pair[1];
                        $moveParams[] = $pair[0];
                    }
                }

                if (hasColumn($conn, 'stock_movements', 'movement_date')) {
                    $columns[] = '`movement_date`';
                    $ph[] = 'NOW()';
                }
                if (hasColumn($conn, 'stock_movements', 'created_at')) {
                    $columns[] = '`created_at`';
                    $ph[] = 'NOW()';
                }

                if ($columns) {
                    $move = $conn->prepare(
                        'INSERT INTO stock_movements (' . implode(',', $columns) . ')
                         VALUES (' . implode(',', $ph) . ')'
                    );
                    if (!$move) {
                        throw new RuntimeException('Unable to prepare cancellation stock movement: ' . $conn->error);
                    }

                    bindDynamic($move, $moveTypes, $moveParams);
                    if (!$move->execute()) {
                        $error = $move->error;
                        $move->close();
                        throw new RuntimeException('Unable to save cancellation stock movement: ' . $error);
                    }
                    $move->close();
                }
            }

            $restocked++;
            $restockDetails[] = [
                'product_id' => $productId,
                'item_name' => $row['item_name'],
                'quantity' => $qty,
                'gross_weight' => $gross,
                'net_weight' => $net,
            ];
        }
    }

    /*
     * Mark sale cancelled using whichever cancellation columns exist.
     */
    $sets = [];
    $types = '';
    $params = [];

    if (hasColumn($conn, 'sales', 'workflow_status')) {
        $sets[] = 'workflow_status=?';
        $types .= 's';
        $params[] = 'Cancelled';
    }
    if (hasColumn($conn, 'sales', 'status')) {
        $sets[] = 'status=?';
        $types .= 's';
        $params[] = 'Cancelled';
    }
    if (hasColumn($conn, 'sales', 'cancel_reason')) {
        $sets[] = 'cancel_reason=?';
        $types .= 's';
        $params[] = $reason;
    }
    if (hasColumn($conn, 'sales', 'cancelled_by')) {
        $sets[] = 'cancelled_by=?';
        $types .= 'i';
        $params[] = $userId;
    }
    if (hasColumn($conn, 'sales', 'cancelled_at')) {
        $sets[] = 'cancelled_at=NOW()';
    }
    if (hasColumn($conn, 'sales', 'updated_at')) {
        $sets[] = 'updated_at=NOW()';
    }

    if (!$sets) {
        throw new RuntimeException('Sales table has no cancellation/status columns.');
    }

    $types .= 'ii';
    $params[] = $saleId;
    $params[] = $businessId;

    $stmt = $conn->prepare(
        'UPDATE sales SET ' . implode(',', $sets) . ' WHERE id=? AND business_id=?'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare sale cancellation: ' . $conn->error);
    }

    bindDynamic($stmt, $types, $params);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to cancel sale: ' . $error);
    }
    $stmt->close();

    $oldAudit = [
        'sale_id' => $saleId,
        'invoice_no' => $invoiceNo,
        'status' => $currentStatus,
        'grand_total' => (float)($sale['grand_total'] ?? 0),
        'paid_amount' => (float)($sale['paid_amount'] ?? 0),
        'balance_amount' => (float)($sale['balance_amount'] ?? 0),
    ];

    $newAudit = [
        'sale_id' => $saleId,
        'invoice_no' => $invoiceNo,
        'status' => 'Cancelled',
        'cancel_reason' => $reason,
        'cancelled_by' => $userId,
        'restocked_items' => $restocked,
        'restock' => $restockDetails,
    ];

    insertAuditLog(
        $conn,
        $businessId,
        $branchId,
        $userId,
        $saleId,
        $invoiceNo,
        $reason,
        $oldAudit,
        $newAudit
    );

    $conn->commit();

    out(true, 'Invoice ' . $invoiceNo . ' cancelled successfully.', [
        'sale_id' => $saleId,
        'invoice_no' => $invoiceNo,
        'restocked_items' => $restocked,
        'restock' => $restockDetails,
        'audit_saved' => tableExists($conn, 'audit_logs'),
    ]);
} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $ignored) {
    }

    out(false, $e->getMessage(), [], 500);
}
