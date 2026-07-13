<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));
header('Content-Type: application/json; charset=utf-8');

function respond(bool $success, string $message, array $extra = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

$configCandidates = [
    dirname(__DIR__) . '/config/config.php',
    dirname(__DIR__) . '/config.php',
    dirname(__DIR__) . '/includes/config.php',
    dirname(__DIR__) . '/super-admin/includes/config.php',
];

foreach ($configCandidates as $configFile) {
    if (is_file($configFile)) {
        require_once $configFile;
        break;
    }
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    respond(false, 'Database configuration is not available.', [], 500);
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn->set_charset('utf8mb4');

if (empty($_SESSION['user_id'])) {
    respond(false, 'Your session has expired. Please log in again.', [], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Invalid request method.', [], 405);
}
if (!hash_equals((string)($_SESSION['supplier_payment_csrf'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) {
    respond(false, 'Invalid or expired request token. Refresh the page and try again.', [], 419);
}

function tableExists(mysqli $conn, string $table): bool
{
    $table = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '{$table}'");
    return $res && $res->num_rows > 0;
}

function hasColumn(mysqli $conn, string $table, string $column): bool
{
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    return $res && $res->num_rows > 0;
}

function hasPermission(mysqli $conn, string $action): bool
{
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin') {
        return true;
    }

    $map = [
        'open' => 'can_open',
        'view' => 'can_view',
        'create' => 'can_create',
        'update' => 'can_update',
        'delete' => 'can_delete',
    ];

    $field = $map[$action] ?? '';
    if ($field === '') {
        return false;
    }

    $permissions = $_SESSION['permissions'] ?? [];
    foreach (['perm.purchases.supplier_payments', 'perm.purchases', 'perm.suppliers'] as $key) {
        if (isset($permissions[$key][$field])) {
            return (int)$permissions[$key][$field] === 1;
        }
    }

    $businessId = (int)($_SESSION['business_id'] ?? 0);
    $roleId = (int)($_SESSION['role_id'] ?? 0);

    if ($businessId <= 0 || $roleId <= 0) {
        $roleName = strtolower(trim((string)($_SESSION['role_name'] ?? '')));
        $roleCode = strtolower(trim((string)($_SESSION['role_code'] ?? '')));
        return in_array($roleName, ['admin', 'manager', 'stock'], true)
            || in_array($roleCode, ['admin', 'manager', 'stock'], true);
    }

    $sql = "SELECT rp.`{$field}`
            FROM role_permissions rp
            INNER JOIN permissions p ON p.id = rp.permission_id
            WHERE rp.business_id = ?
              AND rp.role_id = ?
              AND p.is_active = 1
              AND p.permission_code IN ('perm.purchases.supplier_payments','perm.purchases','perm.suppliers')
            ORDER BY FIELD(p.permission_code,'perm.purchases.supplier_payments','perm.purchases','perm.suppliers')
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

function audit(mysqli $conn, int $businessId, int $branchId, int $userId, int $referenceId, string $paymentNo, array $newValues): void
{
    if (!tableExists($conn, 'audit_logs')) {
        return;
    }

    $newJson = json_encode($newValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $agent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $description = 'Created supplier payment ' . $paymentNo;

    if (hasColumn($conn, 'audit_logs', 'module_code')) {
        $stmt = $conn->prepare("INSERT INTO audit_logs
            (business_id, branch_id, user_id, module_code, action_type, reference_table, reference_id, description, new_values_json, ip_address, user_agent)
            VALUES (?, ?, ?, 'purchases.supplier_payments', 'Create', 'supplier_payments', ?, ?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param('iiiissss', $businessId, $branchId, $userId, $referenceId, $description, $newJson, $ip, $agent);
            $stmt->execute();
            $stmt->close();
        }
        return;
    }

    if (hasColumn($conn, 'audit_logs', 'module_name')) {
        $stmt = $conn->prepare("INSERT INTO audit_logs
            (business_id, user_id, module_name, action_type, reference_id, description, ip_address, user_agent, created_at)
            VALUES (?, ?, 'Supplier Payments', 'Create', ?, ?, ?, ?, NOW())");
        if ($stmt) {
            $stmt->bind_param('iiisss', $businessId, $userId, $referenceId, $description, $ip, $agent);
            $stmt->execute();
            $stmt->close();
        }
    }
}

if (!hasPermission($conn, 'create')) {
    respond(false, 'You do not have permission to create supplier payments.', [], 403);
}

$businessId = (int)($_SESSION['business_id'] ?? 0);
$userId = (int)($_SESSION['user_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? 0);

if ($businessId <= 0) {
    respond(false, 'A valid business must be selected.', [], 403);
}

foreach (['suppliers', 'purchases', 'supplier_payments'] as $requiredTable) {
    if (!tableExists($conn, $requiredTable)) {
        respond(false, "Required table `{$requiredTable}` was not found.", [], 500);
    }
}

$paymentNo = trim((string)($_POST['payment_no'] ?? ''));
$paymentDate = trim((string)($_POST['payment_date'] ?? ''));
$supplierId = (int)($_POST['supplier_id'] ?? 0);
$purchaseId = (int)($_POST['purchase_id'] ?? 0);
$paymentMethodId = (int)($_POST['payment_method_id'] ?? 0);
$referenceNo = trim((string)($_POST['reference_no'] ?? ''));
$amount = (float)($_POST['amount'] ?? 0);
$notes = trim((string)($_POST['notes'] ?? ''));

if ($paymentNo === '') {
    respond(false, 'Payment number is required.');
}
if ($paymentDate === '') {
    respond(false, 'Payment date is required.');
}
if ($supplierId <= 0) {
    respond(false, 'Please select a supplier.');
}
if ($amount <= 0) {
    respond(false, 'Payment amount must be greater than zero.');
}
if (mb_strlen($referenceNo) > 150) {
    respond(false, 'Reference number must not exceed 150 characters.');
}
if (mb_strlen($notes) > 1000) {
    respond(false, 'Notes must not exceed 1000 characters.');
}

$supHasBusinessId = hasColumn($conn, 'suppliers', 'business_id');
$purHasBusinessId = hasColumn($conn, 'purchases', 'business_id');

$sql = "SELECT id FROM suppliers WHERE id = ?";
if ($supHasBusinessId) {
    $sql .= " AND business_id = ?";
}
$sql .= " LIMIT 1";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    respond(false, 'Supplier validation failed.', [], 500);
}
if ($supHasBusinessId) {
    $stmt->bind_param('ii', $supplierId, $businessId);
} else {
    $stmt->bind_param('i', $supplierId);
}
$stmt->execute();
$supplier = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$supplier) {
    respond(false, 'Invalid supplier selected.', [], 404);
}

if (hasColumn($conn, 'supplier_payments', 'payment_no')) {
    $sql = "SELECT id FROM supplier_payments WHERE payment_no = ?";
    if (hasColumn($conn, 'supplier_payments', 'business_id')) {
        $sql .= " AND business_id = ?";
    }
    $sql .= " LIMIT 1";

    $stmt = $conn->prepare($sql);
    if (hasColumn($conn, 'supplier_payments', 'business_id')) {
        $stmt->bind_param('si', $paymentNo, $businessId);
    } else {
        $stmt->bind_param('s', $paymentNo);
    }
    $stmt->execute();
    $duplicate = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($duplicate) {
        respond(false, 'This payment number is already used.', [], 409);
    }
}

$purchase = null;
if ($purchaseId > 0) {
    $sql = "SELECT id, supplier_id,
            " . (hasColumn($conn, 'purchases', 'purchase_no') ? "purchase_no" : "'' AS purchase_no") . ",
            " . (hasColumn($conn, 'purchases', 'grand_total') ? "grand_total" : "0 AS grand_total") . ",
            " . (hasColumn($conn, 'purchases', 'paid_amount') ? "paid_amount" : "0 AS paid_amount") . ",
            " . (hasColumn($conn, 'purchases', 'balance_amount') ? "balance_amount" : "0 AS balance_amount") . "
            FROM purchases
            WHERE id = ? AND supplier_id = ?";

    if ($purHasBusinessId) {
        $sql .= " AND business_id = ?";
    }
    $sql .= " LIMIT 1";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        respond(false, 'Purchase validation failed.', [], 500);
    }

    if ($purHasBusinessId) {
        $stmt->bind_param('iii', $purchaseId, $supplierId, $businessId);
    } else {
        $stmt->bind_param('ii', $purchaseId, $supplierId);
    }

    $stmt->execute();
    $purchase = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$purchase) {
        respond(false, 'Selected purchase was not found for this supplier.', [], 404);
    }

    $balance = (float)($purchase['balance_amount'] ?? 0);
    if ($balance > 0 && $amount > $balance) {
        respond(false, 'Payment amount cannot be greater than the selected purchase balance.');
    }
}

$purchaseIdDb = $purchaseId > 0 ? $purchaseId : null;
$paymentMethodIdDb = $paymentMethodId > 0 ? $paymentMethodId : null;

$conn->begin_transaction();

try {
    $fields = [];
    $placeholders = [];
    $types = '';
    $values = [];

    $columnValues = [
        'business_id' => ['i', $businessId],
        'branch_id' => ['i', $branchId],
        'payment_no' => ['s', $paymentNo],
        'payment_date' => ['s', $paymentDate],
        'supplier_id' => ['i', $supplierId],
        'purchase_id' => ['i', $purchaseIdDb],
        'payment_method_id' => ['i', $paymentMethodIdDb],
        'reference_no' => ['s', $referenceNo !== '' ? $referenceNo : null],
        'amount' => ['d', $amount],
        'notes' => ['s', $notes !== '' ? $notes : null],
        'created_by' => ['i', $userId],
    ];

    foreach ($columnValues as $column => [$type, $value]) {
        if (hasColumn($conn, 'supplier_payments', $column)) {
            $fields[] = $column;
            $placeholders[] = '?';
            $types .= $type;
            $values[] = $value;
        }
    }

    if (hasColumn($conn, 'supplier_payments', 'created_at')) {
        $fields[] = 'created_at';
        $placeholders[] = 'NOW()';
    }

    if (!$fields) {
        throw new RuntimeException('No supplier payment columns were found.');
    }

    $sql = "INSERT INTO supplier_payments (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException('Failed to prepare supplier payment insert.');
    }

    if ($values) {
        $bind = [$types];
        foreach ($values as $key => $value) {
            $bind[] = &$values[$key];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind);
    }

    if (!$stmt->execute()) {
        throw new RuntimeException('Failed to save supplier payment: ' . $stmt->error);
    }

    $supplierPaymentId = (int)$stmt->insert_id;
    $stmt->close();

    if ($purchaseId > 0 && $purchase) {
        $grandTotal = (float)($purchase['grand_total'] ?? 0);
        $oldPaid = (float)($purchase['paid_amount'] ?? 0);
        $newPaid = $oldPaid + $amount;
        $newBalance = max(0, $grandTotal - $newPaid);

        $status = 'Unpaid';
        if ($newPaid > 0 && $newPaid < $grandTotal) {
            $status = 'Partial';
        } elseif ($grandTotal > 0 && $newPaid >= $grandTotal) {
            $status = 'Paid';
        }

        $updates = [];
        $updateValues = [];
        $updateTypes = '';

        if (hasColumn($conn, 'purchases', 'paid_amount')) {
            $updates[] = 'paid_amount = ?';
            $updateTypes .= 'd';
            $updateValues[] = $newPaid;
        }
        if (hasColumn($conn, 'purchases', 'balance_amount')) {
            $updates[] = 'balance_amount = ?';
            $updateTypes .= 'd';
            $updateValues[] = $newBalance;
        }
        if (hasColumn($conn, 'purchases', 'payment_status')) {
            $updates[] = 'payment_status = ?';
            $updateTypes .= 's';
            $updateValues[] = $status;
        }
        if (hasColumn($conn, 'purchases', 'updated_at')) {
            $updates[] = 'updated_at = NOW()';
        }

        if ($updates) {
            $sql = "UPDATE purchases SET " . implode(', ', $updates) . " WHERE id = ?";
            $updateTypes .= 'i';
            $updateValues[] = $purchaseId;

            if ($purHasBusinessId) {
                $sql .= " AND business_id = ?";
                $updateTypes .= 'i';
                $updateValues[] = $businessId;
            }

            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new RuntimeException('Failed to prepare purchase balance update.');
            }

            $bind = [$updateTypes];
            foreach ($updateValues as $key => $value) {
                $bind[] = &$updateValues[$key];
            }
            call_user_func_array([$stmt, 'bind_param'], $bind);

            if (!$stmt->execute()) {
                throw new RuntimeException('Failed to update purchase balance.');
            }
            $stmt->close();
        }
    }

    audit($conn, $businessId, $branchId, $userId, $supplierPaymentId, $paymentNo, [
        'payment_no' => $paymentNo,
        'payment_date' => $paymentDate,
        'supplier_id' => $supplierId,
        'purchase_id' => $purchaseIdDb,
        'payment_method_id' => $paymentMethodIdDb,
        'reference_no' => $referenceNo,
        'amount' => $amount,
        'notes' => $notes,
    ]);

    $conn->commit();

    respond(true, 'Supplier payment saved successfully. Payment No: ' . $paymentNo, [
        'supplier_payment_id' => $supplierPaymentId,
        'supplier_id' => $supplierId,
        'payment_no' => $paymentNo,
    ]);
} catch (Throwable $e) {
    $conn->rollback();
    respond(false, $e->getMessage(), [], 500);
}
