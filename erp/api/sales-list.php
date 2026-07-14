<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_OFF);
ini_set('display_errors', '0');

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if (!$error || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'success' => false,
        'message' => 'Fatal API error: ' . $error['message'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
});

function respond(bool $success, string $message, array $extra = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

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

if (!isset($conn) || !($conn instanceof mysqli)) {
    respond(false, 'Database configuration is not available.', [], 500);
}

$conn->set_charset('utf8mb4');

if (empty($_SESSION['user_id'])) {
    respond(false, 'Your session has expired. Please log in again.', [], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Invalid request method.', [], 405);
}

if (!hash_equals((string)($_SESSION['sales_csrf'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) {
    respond(false, 'Invalid or expired request token. Refresh the page and try again.', [], 419);
}

function permission(mysqli $conn, string $action): bool
{
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin') {
        return true;
    }

    $map = [
        'open' => 'can_open',
        'view' => 'can_view',
        'update' => 'can_update',
        'delete' => 'can_delete',
        'value' => 'can_view_value',
    ];

    $field = $map[$action] ?? '';
    if ($field === '') {
        return false;
    }

    foreach (['perm.sales.list', 'perm.sales', 'perm.billing'] as $code) {
        if (isset($_SESSION['permissions'][$code][$field])) {
            return (int)$_SESSION['permissions'][$code][$field] === 1;
        }
    }

    $businessId = (int)($_SESSION['business_id'] ?? 0);
    $roleId = (int)($_SESSION['role_id'] ?? 0);
    if ($businessId <= 0 || $roleId <= 0) {
        return false;
    }

    $sql = "SELECT rp.`{$field}`
            FROM role_permissions rp
            INNER JOIN permissions p ON p.id = rp.permission_id
            WHERE rp.business_id = ?
              AND rp.role_id = ?
              AND p.is_active = 1
              AND p.permission_code IN ('perm.sales.list','perm.sales','perm.billing')
            ORDER BY FIELD(p.permission_code,'perm.sales.list','perm.sales','perm.billing')
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

function bindDynamic(mysqli_stmt $stmt, string $types, array &$params): void
{
    if ($types === '') return;
    $bind = [$types];
    foreach ($params as $key => $value) {
        $bind[] = &$params[$key];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind);
}

function auditCancel(mysqli $conn, int $businessId, int $branchId, int $userId, int $saleId, string $invoiceNo, string $reason): void
{
    $stmt = $conn->prepare("INSERT INTO audit_logs
        (business_id,branch_id,user_id,module_code,action_type,reference_table,reference_id,description,new_values_json,ip_address,user_agent)
        VALUES (?,?,?,'sales.list','Cancel','sales',?,?,?,?,?,?)");
    if (!$stmt) return;

    $description = 'Cancelled invoice ' . $invoiceNo;
    $json = json_encode(['invoice_no' => $invoiceNo, 'reason' => $reason], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

    $stmt->bind_param('iiiissss', $businessId, $branchId, $userId, $saleId, $description, $json, $ip, $ua);
    $stmt->execute();
    $stmt->close();
}

function tableExists(mysqli $conn, string $table): bool
{
    $safe = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $result && $result->num_rows > 0;
}

$action = (string)($_POST['action'] ?? '');
$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? ($_SESSION['default_branch_id'] ?? 0));
$userId = (int)($_SESSION['user_id'] ?? 0);

if ($businessId <= 0 || $branchId <= 0) {
    respond(false, 'A valid business and branch must be selected.', [], 403);
}

if ($action === 'list') {
    if (!permission($conn, 'view') && !permission($conn, 'open')) {
        respond(false, 'You do not have permission to view sales.', [], 403);
    }

    $fromDate = trim((string)($_POST['from_date'] ?? ''));
    $toDate = trim((string)($_POST['to_date'] ?? ''));
    $status = trim((string)($_POST['status'] ?? ''));
    $search = trim((string)($_POST['search'] ?? ''));
    $page = max(1, (int)($_POST['page'] ?? 1));
    $perPage = max(5, min(100, (int)($_POST['per_page'] ?? 10)));

    $where = ' WHERE s.business_id = ?';
    $types = 'i';
    $params = [$businessId];

    if ($fromDate !== '') {
        $where .= ' AND s.invoice_date >= ?';
        $types .= 's';
        $params[] = $fromDate;
    }

    if ($toDate !== '') {
        $where .= ' AND s.invoice_date <= ?';
        $types .= 's';
        $params[] = $toDate;
    }

    if (in_array($status, ['Posted', 'Cancelled'], true)) {
        $where .= ' AND s.workflow_status = ?';
        $types .= 's';
        $params[] = $status;
    }

    if ($search !== '') {
        $like = '%' . $search . '%';
        $where .= ' AND (s.invoice_no LIKE ? OR COALESCE(s.customer_name,\'\') LIKE ? OR COALESCE(s.customer_mobile,\'\') LIKE ?)';
        $types .= 'sss';
        array_push($params, $like, $like, $like);
    }

    $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM sales s' . $where);
    if (!$stmt) respond(false, 'Unable to prepare sales count: ' . $conn->error, [], 500);
    bindDynamic($stmt, $types, $params);
    $stmt->execute();
    $total = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();

    $totalPages = max(1, (int)ceil($total / $perPage));
    if ($page > $totalPages) $page = $totalPages;
    $offset = ($page - 1) * $perPage;

    $paymentMethodSelect = "'' AS primary_payment_method";
    if (tableExists($conn, 'sale_payments') && tableExists($conn, 'payment_methods')) {
        $paymentMethodSelect = "COALESCE((
            SELECT pm.method_name
            FROM sale_payments sp
            INNER JOIN payment_methods pm ON pm.id = sp.payment_method_id
            WHERE sp.sale_id = s.id
              AND sp.business_id = s.business_id
            ORDER BY sp.id ASC
            LIMIT 1
        ),'') AS primary_payment_method";
    }

    $listSql = "SELECT s.*, {$paymentMethodSelect}
        FROM sales s
        {$where}
        ORDER BY s.invoice_date DESC, s.invoice_time DESC, s.id DESC
        LIMIT ? OFFSET ?";

    $listParams = $params;
    $listParams[] = $perPage;
    $listParams[] = $offset;
    $listTypes = $types . 'ii';

    $stmt = $conn->prepare($listSql);
    if (!$stmt) respond(false, 'Unable to prepare sales list: ' . $conn->error, [], 500);
    bindDynamic($stmt, $listTypes, $listParams);
    $stmt->execute();
    $result = $stmt->get_result();
    $sales = [];

    while ($row = $result->fetch_assoc()) {
        $row['invoice_date_display'] = !empty($row['invoice_date']) ? date('d-m-Y', strtotime($row['invoice_date'])) : '';
        $row['invoice_time_display'] = !empty($row['invoice_time']) ? date('h:i A', strtotime($row['invoice_time'])) : '';
        $sales[] = $row;
    }
    $stmt->close();

    $statsSql = "SELECT
        COUNT(*) AS total_bills,
        COALESCE(SUM(CASE WHEN workflow_status <> 'Cancelled' THEN grand_total ELSE 0 END),0) AS sales_total,
        COALESCE(SUM(CASE WHEN workflow_status <> 'Cancelled' THEN paid_amount ELSE 0 END),0) AS paid_total,
        COALESCE(SUM(CASE WHEN workflow_status <> 'Cancelled' THEN balance_amount ELSE 0 END),0) AS balance_total
        FROM sales
        WHERE business_id = ?";
    $stmt = $conn->prepare($statsSql);
    $stmt->bind_param('i', $businessId);
    $stmt->execute();
    $stats = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    $from = $total > 0 ? $offset + 1 : 0;
    $to = $total > 0 ? min($offset + $perPage, $total) : 0;

    respond(true, 'Sales loaded.', [
        'sales' => $sales,
        'stats' => $stats,
        'meta' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
            'from' => $from,
            'to' => $to,
        ],
    ]);
}

if ($action === 'view') {
    if (!permission($conn, 'view') && !permission($conn, 'open')) {
        respond(false, 'You do not have permission to view sales.', [], 403);
    }

    $saleId = (int)($_POST['sale_id'] ?? 0);
    if ($saleId <= 0) respond(false, 'Invalid sale selected.');

    $stmt = $conn->prepare('SELECT * FROM sales WHERE id = ? AND business_id = ? LIMIT 1');
    $stmt->bind_param('ii', $saleId, $businessId);
    $stmt->execute();
    $sale = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$sale) respond(false, 'Sale not found.', [], 404);

    $sale['invoice_date_display'] = date('d-m-Y', strtotime($sale['invoice_date']));
    $sale['invoice_time_display'] = date('h:i A', strtotime($sale['invoice_time']));

    $items = [];
    $stmt = $conn->prepare('SELECT * FROM sale_items WHERE sale_id = ? AND business_id = ? ORDER BY sort_order ASC, id ASC');
    $stmt->bind_param('ii', $saleId, $businessId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $items[] = $row;
    $stmt->close();

    $payments = [];
    if (tableExists($conn, 'sale_payments') && tableExists($conn, 'payment_methods')) {
        $stmt = $conn->prepare("SELECT sp.*, pm.method_name
            FROM sale_payments sp
            INNER JOIN payment_methods pm ON pm.id = sp.payment_method_id
            WHERE sp.sale_id = ? AND sp.business_id = ?
            ORDER BY sp.id ASC");
        if ($stmt) {
            $stmt->bind_param('ii', $saleId, $businessId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) $payments[] = $row;
            $stmt->close();
        }
    }

    respond(true, 'Sale loaded.', ['sale' => $sale, 'items' => $items, 'payments' => $payments]);
}

if ($action === 'cancel') {
    if (!permission($conn, 'delete') && !permission($conn, 'update')) {
        respond(false, 'You do not have permission to cancel sales.', [], 403);
    }

    $saleId = (int)($_POST['sale_id'] ?? 0);
    $reason = trim((string)($_POST['cancel_reason'] ?? ''));

    if ($saleId <= 0) respond(false, 'Invalid sale selected.');
    if ($reason === '') respond(false, 'Cancellation reason is required.');

    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare("SELECT id, branch_id, invoice_no, workflow_status
            FROM sales
            WHERE id = ? AND business_id = ?
            LIMIT 1 FOR UPDATE");
        if (!$stmt) throw new Exception('Unable to prepare sale check: ' . $conn->error);

        $stmt->bind_param('ii', $saleId, $businessId);
        $stmt->execute();
        $sale = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$sale) throw new Exception('Sale not found.');
        if ($sale['workflow_status'] === 'Cancelled') throw new Exception('This sale is already cancelled.');
        $saleBranchId = (int)$sale['branch_id'];

        $items = [];
        $stmt = $conn->prepare('SELECT product_id, quantity, gross_weight, net_weight, metal_rate, line_total FROM sale_items WHERE sale_id = ? AND business_id = ?');
        $stmt->bind_param('ii', $saleId, $businessId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) $items[] = $row;
        $stmt->close();

        $stmt = $conn->prepare("UPDATE sales
            SET workflow_status = 'Cancelled',
                cancelled_by = ?,
                cancelled_at = NOW(),
                cancel_reason = ?
            WHERE id = ? AND business_id = ?");
        if (!$stmt) throw new Exception('Unable to prepare cancellation: ' . $conn->error);
        $stmt->bind_param('isii', $userId, $reason, $saleId, $businessId);
        if (!$stmt->execute()) throw new Exception('Unable to cancel sale: ' . $stmt->error);
        $stmt->close();

        foreach ($items as $item) {
            $productId = (int)($item['product_id'] ?? 0);
            if ($productId <= 0) continue;

            $quantity = (float)$item['quantity'];
            $grossWeight = (float)$item['gross_weight'];
            $netWeight = (float)$item['net_weight'];
            $rate = (float)$item['metal_rate'];
            $value = (float)$item['line_total'];

            $stmt = $conn->prepare("INSERT INTO product_stock
                (business_id,branch_id,product_id,quantity,gross_weight,net_weight,average_cost,stock_value)
                VALUES (?,?,?,?,?,?,0,0)
                ON DUPLICATE KEY UPDATE
                    quantity = quantity + VALUES(quantity),
                    gross_weight = gross_weight + VALUES(gross_weight),
                    net_weight = net_weight + VALUES(net_weight)");
            if (!$stmt) throw new Exception('Unable to prepare stock restoration: ' . $conn->error);
            $stmt->bind_param('iiiddd', $businessId, $saleBranchId, $productId, $quantity, $grossWeight, $netWeight);
            if (!$stmt->execute()) throw new Exception('Unable to restore product stock: ' . $stmt->error);
            $stmt->close();

            $remarks = 'Cancelled invoice ' . $sale['invoice_no'];
            $stmt = $conn->prepare("INSERT INTO stock_movements
                (business_id,branch_id,product_id,movement_type,reference_table,reference_id,quantity_in,quantity_out,weight_in,weight_out,rate,value_amount,remarks,created_by)
                VALUES (?,?,?,'Adjustment In','sales',?,?,0,?,0,?,?,?,?)");
            if (!$stmt) throw new Exception('Unable to prepare stock movement: ' . $conn->error);
            $stmt->bind_param('iiiiddddsi', $businessId, $saleBranchId, $productId, $saleId, $quantity, $netWeight, $rate, $value, $remarks, $userId);
            if (!$stmt->execute()) throw new Exception('Unable to add stock movement: ' . $stmt->error);
            $stmt->close();
        }

        auditCancel($conn, $businessId, $saleBranchId, $userId, $saleId, (string)$sale['invoice_no'], $reason);
        $conn->commit();

        respond(true, 'Sale cancelled successfully and stock restored.');
    } catch (Throwable $e) {
        $conn->rollback();
        respond(false, $e->getMessage(), [], 500);
    }
}

respond(false, 'Invalid action.', [], 400);
