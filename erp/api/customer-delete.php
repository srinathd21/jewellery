<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_OFF);

function respond(bool $success, string $message, array $extra = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
    ], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

set_exception_handler(function (Throwable $exception): void {
    error_log('Customer delete API: ' . $exception->getMessage());
    respond(false, 'Server error while deleting the customer.', [], 500);
});

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
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(false, 'Invalid request method.', [], 405);
}
if (!hash_equals((string)($_SESSION['customers_csrf'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) {
    respond(false, 'Invalid or expired request token. Refresh the page.', [], 419);
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

function canDeleteCustomer(mysqli $conn): bool
{
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin') {
        return true;
    }

    foreach (['perm.customers.list', 'perm.customers', 'perm.billing.customers'] as $code) {
        if (isset($_SESSION['permissions'][$code]['can_delete'])) {
            return (int)$_SESSION['permissions'][$code]['can_delete'] === 1;
        }
    }

    $roleName = strtolower(trim((string)($_SESSION['role_name'] ?? '')));
    $roleCode = strtolower(trim((string)($_SESSION['role_code'] ?? '')));
    return in_array($roleName, ['admin', 'business admin', 'manager'], true)
        || in_array($roleCode, ['admin', 'business_admin', 'manager'], true);
}

if (!canDeleteCustomer($conn)) {
    respond(false, 'You do not have permission to delete customers.', [], 403);
}

$businessId = (int)($_SESSION['business_id'] ?? 0);
$userId = (int)($_SESSION['user_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? $_SESSION['default_branch_id'] ?? 0);
$customerId = filter_var($_POST['customer_id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);

if ($businessId <= 0 || !$customerId) {
    respond(false, 'Invalid customer or business.', [], 422);
}

$stmt = $conn->prepare('SELECT * FROM customers WHERE id = ? AND business_id = ? LIMIT 1');
if (!$stmt) {
    respond(false, 'Unable to prepare customer lookup.', [], 500);
}
$stmt->bind_param('ii', $customerId, $businessId);
$stmt->execute();
$customer = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$customer) {
    respond(false, 'Customer not found for the selected business.', [], 404);
}

/* Block deletion when operational records are linked. */
$relatedTables = [
    'sales' => 'invoice',
    'customer_payments' => 'customer payment',
    'pawn_customers' => 'pawn profile',
    'chit_members' => 'chit membership',
    'sales_chit_claims' => 'chit claim',
    'old_metal_entries' => 'old metal entry',
    'sale_returns' => 'sales return',
];
$links = [];

foreach ($relatedTables as $table => $label) {
    if (!tableExists($conn, $table) || !hasColumn($conn, $table, 'customer_id')) {
        continue;
    }

    $sql = "SELECT COUNT(*) AS total FROM `{$table}` WHERE customer_id = ?";
    if (hasColumn($conn, $table, 'business_id')) {
        $sql .= ' AND business_id = ?';
    }

    $check = $conn->prepare($sql);
    if (!$check) {
        continue;
    }

    if (hasColumn($conn, $table, 'business_id')) {
        $check->bind_param('ii', $customerId, $businessId);
    } else {
        $check->bind_param('i', $customerId);
    }

    $check->execute();
    $count = (int)($check->get_result()->fetch_assoc()['total'] ?? 0);
    $check->close();

    if ($count > 0) {
        $links[] = $count . ' ' . $label . ($count === 1 ? '' : 's');
    }
}

if ($links) {
    respond(
        false,
        'Customer cannot be deleted because linked records exist: ' . implode(', ', $links) . '. Deactivate the customer instead.',
        ['linked_records' => $links],
        409
    );
}

$conn->begin_transaction();
try {
    if (tableExists($conn, 'customer_services')) {
        $stmt = $conn->prepare('DELETE FROM customer_services WHERE customer_id = ? AND business_id = ?');
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare customer service cleanup.');
        }
        $stmt->bind_param('ii', $customerId, $businessId);
        if (!$stmt->execute()) {
            throw new RuntimeException($stmt->error);
        }
        $stmt->close();
    }

    $stmt = $conn->prepare('DELETE FROM customers WHERE id = ? AND business_id = ? LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare customer delete.');
    }
    $stmt->bind_param('ii', $customerId, $businessId);
    if (!$stmt->execute()) {
        throw new RuntimeException($stmt->error);
    }
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected !== 1) {
        throw new RuntimeException('Customer was not deleted.');
    }

    if (tableExists($conn, 'audit_logs')) {
        $description = 'Deleted customer ' . (string)($customer['customer_name'] ?? ('#' . $customerId));
        $oldJson = json_encode($customer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $userAgent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        $stmt = $conn->prepare("INSERT INTO audit_logs (business_id, branch_id, user_id, module_code, action_type, reference_table, reference_id, description, old_values_json, ip_address, user_agent) VALUES (?, NULLIF(?,0), ?, 'customers', 'Delete', 'customers', ?, ?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param('iiiissss', $businessId, $branchId, $userId, $customerId, $description, $oldJson, $ip, $userAgent);
            $stmt->execute();
            $stmt->close();
        }
    }

    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    $message = $exception->getMessage();
    if (stripos($message, 'foreign key constraint') !== false) {
        $message = 'Customer has linked transactions and cannot be deleted. Deactivate the customer instead.';
    }
    respond(false, $message, [], 409);
}

respond(true, 'Customer deleted successfully.', ['customer_id' => (int)$customerId]);