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

function auditSaleDelete(
    mysqli $conn,
    int $businessId,
    int $branchId,
    int $userId,
    int $saleId,
    string $invoiceNo,
    string $reason,
    array $oldSale,
    array $reversalDetails = []
): void {
    $stmt = $conn->prepare("INSERT INTO audit_logs
        (business_id,branch_id,user_id,module_code,action_type,reference_table,reference_id,
         description,old_values_json,new_values_json,ip_address,user_agent)
        VALUES (?,?,?,'sales.list','Delete','sales',?,?,?,?,?,?)");
    if (!$stmt) return;

    $description = 'Cancelled invoice ' . $invoiceNo . ' and reversed linked sale effects';
    $oldJson = json_encode($oldSale, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $newJson = json_encode([
        'invoice_no' => $invoiceNo,
        'workflow_status' => 'Cancelled',
        'reason' => $reason,
        'reversal' => $reversalDetails,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

    $stmt->bind_param(
        'iiiisssss',
        $businessId,
        $branchId,
        $userId,
        $saleId,
        $description,
        $oldJson,
        $newJson,
        $ip,
        $ua
    );
    $stmt->execute();
    $stmt->close();
}

function deleteCustomerReceiptsForSale(mysqli $conn, int $businessId, int $saleId): int
{
    if (!tableExists($conn, 'customer_payments')) {
        return 0;
    }

    $ids = [];
    $stmt = $conn->prepare('SELECT id FROM customer_payments WHERE business_id=? AND sale_id=? FOR UPDATE');
    if ($stmt) {
        $stmt->bind_param('ii', $businessId, $saleId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $ids[] = (int)$row['id'];
        }
        $stmt->close();
    }

    if ($ids && tableExists($conn, 'customer_payment_splits')) {
        $deleteSplit = $conn->prepare('DELETE FROM customer_payment_splits WHERE payment_id=?');
        if (!$deleteSplit) {
            throw new RuntimeException('Unable to prepare customer payment split reversal: ' . $conn->error);
        }
        foreach ($ids as $paymentId) {
            $deleteSplit->bind_param('i', $paymentId);
            if (!$deleteSplit->execute()) {
                throw new RuntimeException('Unable to reverse customer payment split: ' . $deleteSplit->error);
            }
        }
        $deleteSplit->close();
    }

    $stmt = $conn->prepare('DELETE FROM customer_payments WHERE business_id=? AND sale_id=?');
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare customer receipt reversal: ' . $conn->error);
    }
    $stmt->bind_param('ii', $businessId, $saleId);
    if (!$stmt->execute()) {
        throw new RuntimeException('Unable to reverse customer receipts: ' . $stmt->error);
    }
    $count = $stmt->affected_rows;
    $stmt->close();
    return max(0, $count);
}

function tableExists(mysqli $conn, string $table): bool
{
    $safe = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $result && $result->num_rows > 0;
}


function reactivateProductAfterRestock(mysqli $conn, int $businessId, int $productId): void
{
    if ($businessId <= 0 || $productId <= 0) {
        return;
    }

    // Reactivate only when restored stock is actually available somewhere in the business.
    // This matches the billing-side auto-inactive rule that deactivates a product only
    // when its total stock across branches reaches zero.
    $stmt = $conn->prepare(
        'SELECT COALESCE(SUM(quantity),0) AS total_qty
'
        . 'FROM product_stock
'
        . 'WHERE business_id=? AND product_id=?'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to check restored product stock: ' . $conn->error);
    }

    $stmt->bind_param('ii', $businessId, $productId);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to check restored product stock: ' . $error);
    }

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $totalQty = (float)($row['total_qty'] ?? 0);

    if ($totalQty <= 0.0001) {
        return;
    }

    $update = $conn->prepare(
        'UPDATE products SET is_active=1 WHERE id=? AND business_id=? AND is_active=0'
    );
    if (!$update) {
        throw new RuntimeException('Unable to prepare product reactivation: ' . $conn->error);
    }

    $update->bind_param('ii', $productId, $businessId);
    if (!$update->execute()) {
        $error = $update->error;
        $update->close();
        throw new RuntimeException('Unable to reactivate restored product: ' . $error);
    }
    $update->close();
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

    $where = ' WHERE s.business_id = ? AND s.branch_id = ?';
    $types = 'ii';
    $params = [$businessId, $branchId];

    // Default Sales List shows only active/posted invoices. Cancelled invoices
    // are available only when the user explicitly selects Cancelled status.
    if ($status === '') {
        $where .= " AND COALESCE(s.workflow_status,'Posted') <> 'Cancelled'";
    }

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
        COALESCE(SUM(CASE WHEN workflow_status <> 'Cancelled' THEN 1 ELSE 0 END),0) AS total_bills,
        COALESCE(SUM(CASE WHEN workflow_status <> 'Cancelled' THEN grand_total ELSE 0 END),0) AS sales_total,
        COALESCE(SUM(CASE WHEN workflow_status <> 'Cancelled' THEN paid_amount ELSE 0 END),0) AS paid_total,
        COALESCE(SUM(CASE WHEN workflow_status <> 'Cancelled' THEN balance_amount ELSE 0 END),0) AS balance_total
        FROM sales
        WHERE business_id = ? AND branch_id = ?";
    $stmt = $conn->prepare($statsSql);
    $stmt->bind_param('ii', $businessId, $branchId);
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

    $stmt = $conn->prepare('SELECT * FROM sales WHERE id = ? AND business_id = ? AND branch_id = ? LIMIT 1');
    $stmt->bind_param('iii', $saleId, $businessId, $branchId);
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

if ($action === 'delete' || $action === 'cancel') {
    if (!permission($conn, 'delete')) {
        respond(false, 'You do not have permission to delete sales.', [], 403);
    }

    $saleId = (int)($_POST['sale_id'] ?? 0);
    $reason = trim((string)($_POST['cancel_reason'] ?? ''));

    if ($saleId <= 0) respond(false, 'Invalid sale selected.');
    if ($reason === '') respond(false, 'Delete reason is required.');

    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare("SELECT *
            FROM sales
            WHERE id = ? AND business_id = ? AND branch_id = ?
            LIMIT 1 FOR UPDATE");
        if (!$stmt) throw new Exception('Unable to prepare sale check: ' . $conn->error);

        $stmt->bind_param('iii', $saleId, $businessId, $branchId);
        $stmt->execute();
        $sale = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$sale) throw new Exception('Sale not found.');
        if ((string)$sale['workflow_status'] === 'Cancelled') {
            throw new Exception('This invoice is already deleted/cancelled.');
        }
        $saleBranchId = (int)$sale['branch_id'];

        // If old-gold stock has already moved to another workflow, cancellation is unsafe.
        if (tableExists($conn, 'exchange_items_stock')) {
            $stmt = $conn->prepare("SELECT status,COUNT(*) AS cnt
                FROM exchange_items_stock
                WHERE business_id=? AND branch_id=? AND sale_id=?
                GROUP BY status
                FOR UPDATE");
            if (!$stmt) throw new Exception('Unable to inspect exchange stock: ' . $conn->error);
            $stmt->bind_param('iii', $businessId, $saleBranchId, $saleId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                if ((string)$row['status'] !== 'Available' && (int)$row['cnt'] > 0) {
                    throw new Exception(
                        'This invoice has exchange stock already marked ' . $row['status'] .
                        '. Reverse that exchange workflow before cancelling the invoice.'
                    );
                }
            }
            $stmt->close();
        }

        $items = [];
        $restocked = [];
        $stmt = $conn->prepare("SELECT si.product_id,si.item_name,si.quantity,si.gross_weight,
                si.net_weight,si.metal_rate,si.line_total,COALESCE(p.track_stock,0) AS track_stock
            FROM sale_items si
            LEFT JOIN products p ON p.id=si.product_id AND p.business_id=si.business_id
            WHERE si.sale_id=? AND si.business_id=? AND si.branch_id=?");
        if (!$stmt) throw new Exception('Unable to prepare sale items: ' . $conn->error);
        $stmt->bind_param('iii', $saleId, $businessId, $saleBranchId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) $items[] = $row;
        $stmt->close();

        foreach ($items as $item) {
            if ((int)($item['track_stock'] ?? 0) !== 1) continue;
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

            // If this product was auto-inactivated because its stock reached zero,
            // restoring stock from a cancelled/deleted invoice makes it active again.
            reactivateProductAfterRestock($conn, $businessId, $productId);

            $movementDate = date('Y-m-d H:i:s');
            $remarksText = 'Cancelled invoice ' . $sale['invoice_no'] . ' - stock restored';
            $stmt = $conn->prepare("INSERT INTO stock_movements
                (business_id,branch_id,product_id,movement_date,movement_type,reference_table,reference_id,
                 quantity_in,quantity_out,weight_in,weight_out,rate,value_amount,remarks,created_by)
                VALUES (?,?,?,?,'Adjustment In','sales',?,?,0,?,0,?,?,?,?)");
            if (!$stmt) throw new Exception('Unable to prepare stock movement: ' . $conn->error);
            $stmt->bind_param(
                'iiisiddddsi',
                $businessId,
                $saleBranchId,
                $productId,
                $movementDate,
                $saleId,
                $quantity,
                $netWeight,
                $rate,
                $value,
                $remarksText,
                $userId
            );
            if (!$stmt->execute()) throw new Exception('Unable to add stock movement: ' . $stmt->error);
            $stmt->close();

            $restocked[] = [
                'product_id' => $productId,
                'item_name' => (string)($item['item_name'] ?? ''),
                'quantity' => $quantity,
                'gross_weight' => $grossWeight,
                'net_weight' => $netWeight,
            ];
        }

        $reversedCustomerReceipts = deleteCustomerReceiptsForSale($conn, $businessId, $saleId);

        $reversedPayments = 0;
        if (tableExists($conn, 'sale_payments')) {
            $stmt = $conn->prepare('DELETE FROM sale_payments WHERE business_id=? AND branch_id=? AND sale_id=?');
            if (!$stmt) throw new Exception('Unable to prepare payment reversal: ' . $conn->error);
            $stmt->bind_param('iii', $businessId, $saleBranchId, $saleId);
            if (!$stmt->execute()) throw new Exception('Unable to reverse sale payments: ' . $stmt->error);
            $reversedPayments = max(0, $stmt->affected_rows);
            $stmt->close();
        }

        $cancelledClaims = 0;
        if (tableExists($conn, 'sales_chit_claims')) {
            $stmt = $conn->prepare("UPDATE sales_chit_claims
                SET status='Cancelled',cancelled_by=?,cancelled_at=NOW()
                WHERE business_id=? AND branch_id=? AND sale_id=? AND status='Posted'");
            if (!$stmt) throw new Exception('Unable to prepare chit claim reversal: ' . $conn->error);
            $stmt->bind_param('iiii', $userId, $businessId, $saleBranchId, $saleId);
            if (!$stmt->execute()) throw new Exception('Unable to reverse chit claims: ' . $stmt->error);
            $cancelledClaims = max(0, $stmt->affected_rows);
            $stmt->close();
        }

        $removedExchangeStock = 0;
        if (tableExists($conn, 'exchange_items_stock')) {
            $stmt = $conn->prepare("DELETE FROM exchange_items_stock
                WHERE business_id=? AND branch_id=? AND sale_id=? AND status='Available'");
            if (!$stmt) throw new Exception('Unable to prepare exchange stock reversal: ' . $conn->error);
            $stmt->bind_param('iii', $businessId, $saleBranchId, $saleId);
            if (!$stmt->execute()) throw new Exception('Unable to reverse exchange stock: ' . $stmt->error);
            $removedExchangeStock = max(0, $stmt->affected_rows);
            $stmt->close();
        }

        $stmt = $conn->prepare("UPDATE sales
            SET workflow_status='Cancelled',
                paid_amount=0,
                balance_amount=0,
                payment_status='Unpaid',
                cancelled_by=?,
                cancelled_at=NOW(),
                cancel_reason=?
            WHERE id=? AND business_id=? AND branch_id=?");
        if (!$stmt) throw new Exception('Unable to prepare cancellation: ' . $conn->error);
        $stmt->bind_param('isiii', $userId, $reason, $saleId, $businessId, $saleBranchId);
        if (!$stmt->execute()) throw new Exception('Unable to cancel sale: ' . $stmt->error);
        $stmt->close();

        $reversal = [
            'restocked_items' => $restocked,
            'customer_receipts_reversed' => $reversedCustomerReceipts,
            'sale_payments_reversed' => $reversedPayments,
            'chit_claims_cancelled' => $cancelledClaims,
            'exchange_stock_removed' => $removedExchangeStock,
        ];

        auditSaleDelete(
            $conn,
            $businessId,
            $saleBranchId,
            $userId,
            $saleId,
            (string)$sale['invoice_no'],
            $reason,
            $sale,
            $reversal
        );

        $conn->commit();

        respond(true, 'Invoice cancelled successfully. Linked stock/payment/chit/exchange effects were reversed.', [
            'restocked_items' => count($restocked),
            'customer_receipts_reversed' => $reversedCustomerReceipts,
            'sale_payments_reversed' => $reversedPayments,
            'chit_claims_cancelled' => $cancelledClaims,
            'exchange_stock_removed' => $removedExchangeStock,
        ]);
    } catch (Throwable $e) {
        $conn->rollback();
        respond(false, $e->getMessage(), [], 422);
    }
}

respond(false, 'Invalid action.', [], 400);