<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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

function respond(bool $success, string $message, array $extra = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode(
        array_merge(['success' => $success, 'message' => $message], $extra),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    respond(false, 'Database configuration is not available.', [], 500);
}
$conn->set_charset('utf8mb4');

function tableHasColumn(mysqli $conn, string $table, string $column): bool
{
    $safeTable = $conn->real_escape_string($table);
    $safeColumn = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    return $result && $result->num_rows > 0;
}

function firstExistingColumn(mysqli $conn, string $table, array $columns): string
{
    foreach ($columns as $column) {
        if (tableHasColumn($conn, $table, $column)) {
            return $column;
        }
    }
    return '';
}

if (empty($_SESSION['user_id'])) {
    respond(false, 'Session expired.', [], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Invalid request method.', [], 405);
}

if (!hash_equals(
    (string)($_SESSION['supplier_payment_csrf'] ?? ''),
    (string)($_POST['csrf_token'] ?? '')
)) {
    respond(false, 'Invalid request token.', [], 419);
}

$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? 0);
$userId = (int)($_SESSION['user_id'] ?? 0);

if ($businessId <= 0 || $branchId <= 0) {
    respond(false, 'A valid business and branch must be selected.', [], 403);
}

$action = (string)($_POST['action'] ?? '');
if ($action !== 'save') {
    respond(false, 'Invalid action.', [], 400);
}

$supplierId = (int)($_POST['supplier_id'] ?? 0);
$paymentDate = trim((string)($_POST['payment_date'] ?? ''));
$notes = trim((string)($_POST['notes'] ?? ''));

if ($supplierId <= 0) {
    respond(false, 'Select a supplier.');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paymentDate)) {
    respond(false, 'Enter a valid payment date.');
}

$purchaseIds = array_values(array_unique(array_map('intval', (array)($_POST['purchase_id'] ?? []))));
$allocationMap = (array)($_POST['allocation_amount'] ?? []);

$methodIds = (array)($_POST['split_method_id'] ?? []);
$amounts = (array)($_POST['split_amount'] ?? []);
$references = (array)($_POST['split_reference'] ?? []);
$remarks = (array)($_POST['split_remarks'] ?? []);

$allocations = [];
$allocationTotal = 0.0;

foreach ($purchaseIds as $purchaseId) {
    if ($purchaseId <= 0) continue;
    $amount = round((float)($allocationMap[$purchaseId] ?? 0), 2);
    if ($amount <= 0) continue;
    $allocations[] = ['purchase_id' => $purchaseId, 'amount' => $amount];
    $allocationTotal += $amount;
}

if (!$allocations || $allocationTotal <= 0) {
    respond(false, 'Allocate the payment to at least one purchase.');
}

$paymentMethodIdColumn = firstExistingColumn($conn, 'payment_methods', ['id','payment_method_id','method_id']);
$paymentMethodNameColumn = firstExistingColumn($conn, 'payment_methods', ['method_name','payment_method_name','name']);
$paymentMethodStatusColumn = firstExistingColumn($conn, 'payment_methods', ['is_active','status','active']);
$paymentMethodHasBusiness = tableHasColumn($conn, 'payment_methods', 'business_id');

if ($paymentMethodIdColumn === '' || $paymentMethodNameColumn === '') {
    respond(false, 'Payment methods table does not contain supported ID and name columns.', [], 500);
}

$splits = [];
$splitTotal = 0.0;

foreach ($methodIds as $index => $methodRaw) {
    $methodId = (int)$methodRaw;
    $amount = round((float)($amounts[$index] ?? 0), 2);
    $reference = trim((string)($references[$index] ?? ''));
    $remark = trim((string)($remarks[$index] ?? ''));

    if ($methodId <= 0 && $amount <= 0) continue;
    if ($methodId <= 0 || $amount <= 0) {
        respond(false, 'Select a valid payment method and enter an amount greater than zero.');
    }

    $sql = "SELECT `{$paymentMethodIdColumn}` AS method_id,
                   `{$paymentMethodNameColumn}` AS method_name
            FROM payment_methods
            WHERE `{$paymentMethodIdColumn}`=?";
    $types = 'i';
    $params = [$methodId];

    if ($paymentMethodHasBusiness) {
        $sql .= " AND (business_id=? OR business_id IS NULL)";
        $types .= 'i';
        $params[] = $businessId;
    }

    if ($paymentMethodStatusColumn !== '') {
        $sql .= " AND COALESCE(`{$paymentMethodStatusColumn}`,1)=1";
    }

    $sql .= " LIMIT 1";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        respond(false, 'Unable to validate payment method: ' . $conn->error, [], 500);
    }

    $bind = [$types];
    foreach ($params as $key => $value) {
        $bind[] = &$params[$key];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind);
    $stmt->execute();
    $methodRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$methodRow) {
        respond(false, 'One of the selected payment methods is invalid or inactive.');
    }

    $splits[] = [
        'method_id' => (int)$methodRow['method_id'],
        'method' => (string)$methodRow['method_name'],
        'amount' => $amount,
        'reference' => $reference,
        'remarks' => $remark,
    ];
    $splitTotal += $amount;
}

if (!$splits) {
    respond(false, 'Add at least one payment method.');
}

if (abs($allocationTotal - $splitTotal) > 0.01) {
    respond(false, 'Allocation total and split payment total must match.');
}

$conn->begin_transaction();

try {
    $supplierStmt = $conn->prepare(
        "SELECT id FROM suppliers
         WHERE id=? AND business_id=? AND is_active=1
         LIMIT 1 FOR UPDATE"
    );
    if (!$supplierStmt) throw new RuntimeException($conn->error);
    $supplierStmt->bind_param('ii', $supplierId, $businessId);
    $supplierStmt->execute();
    $supplierExists = $supplierStmt->get_result()->fetch_assoc();
    $supplierStmt->close();

    if (!$supplierExists) {
        throw new RuntimeException('Supplier not found.');
    }

    $validatedPurchases = [];
    foreach ($allocations as $allocation) {
        $purchaseId = (int)$allocation['purchase_id'];
        $allocatedAmount = (float)$allocation['amount'];

        $stmt = $conn->prepare(
            "SELECT id,purchase_no,grand_total,paid_amount,balance_amount
             FROM purchases
             WHERE id=? AND business_id=? AND branch_id=? AND supplier_id=?
             LIMIT 1 FOR UPDATE"
        );
        if (!$stmt) throw new RuntimeException($conn->error);
        $stmt->bind_param('iiii', $purchaseId, $businessId, $branchId, $supplierId);
        $stmt->execute();
        $purchase = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$purchase) {
            throw new RuntimeException('One of the selected purchases was not found.');
        }

        $balance = round((float)$purchase['balance_amount'], 2);
        if ($allocatedAmount > $balance + 0.01) {
            throw new RuntimeException(
                'Allocation for ' . $purchase['purchase_no'] .
                ' exceeds the current balance.'
            );
        }

        $validatedPurchases[] = [
            'id' => $purchaseId,
            'purchase_no' => $purchase['purchase_no'],
            'grand_total' => (float)$purchase['grand_total'],
            'paid_amount' => (float)$purchase['paid_amount'],
            'balance_amount' => $balance,
            'allocated_amount' => $allocatedAmount,
        ];
    }

    $temporaryNo = 'TMP-' . bin2hex(random_bytes(8));
    $amountColumn = firstExistingColumn($conn, 'supplier_payments', ['total_amount','amount','payment_amount']);
    if ($amountColumn === '') {
        throw new RuntimeException('Supplier payments table is missing an amount column. Run supplier-payment-migration.sql.');
    }

    $columns = ['business_id','branch_id','supplier_id','payment_no','payment_date',$amountColumn];
    $placeholders = ['?','?','?','?','?','?'];
    $types = 'iiissd';
    $values = [$businessId,$branchId,$supplierId,$temporaryNo,$paymentDate,$allocationTotal];

    $primaryMethodId = (int)$splits[0]['method_id'];
    $primaryPurchaseId = (int)$validatedPurchases[0]['id'];
    $primaryReference = (string)$splits[0]['reference'];

    if (tableHasColumn($conn, 'supplier_payments', 'payment_method_id')) {
        $columns[] = 'payment_method_id';
        $placeholders[] = '?';
        $types .= 'i';
        $values[] = $primaryMethodId;
    }

    if (tableHasColumn($conn, 'supplier_payments', 'purchase_id')) {
        $columns[] = 'purchase_id';
        $placeholders[] = '?';
        $types .= 'i';
        $values[] = $primaryPurchaseId;
    }

    $referenceColumn = firstExistingColumn(
        $conn,
        'supplier_payments',
        ['reference_no','transaction_reference','reference_number']
    );
    if ($referenceColumn !== '') {
        $columns[] = $referenceColumn;
        $placeholders[] = '?';
        $types .= 's';
        $values[] = $primaryReference;
    }

    if (tableHasColumn($conn, 'supplier_payments', 'notes')) {
        $columns[] = 'notes';
        $placeholders[] = '?';
        $types .= 's';
        $values[] = $notes;
    }
    if (tableHasColumn($conn, 'supplier_payments', 'created_by')) {
        $columns[] = 'created_by';
        $placeholders[] = '?';
        $types .= 'i';
        $values[] = $userId;
    }

    $insertSql = 'INSERT INTO supplier_payments (' . implode(',', $columns) . ') VALUES (' . implode(',', $placeholders) . ')';
    $stmt = $conn->prepare($insertSql);
    if (!$stmt) throw new RuntimeException($conn->error);
    $bind = [$types];
    foreach ($values as $key => $value) {
        $bind[] = &$values[$key];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind);
    if (!$stmt->execute()) throw new RuntimeException($stmt->error);
    $paymentId = (int)$stmt->insert_id;
    $stmt->close();

    $paymentNo = 'SPY/' . date('Ym', strtotime($paymentDate)) . '/' . str_pad((string)$paymentId, 6, '0', STR_PAD_LEFT);
    $stmt = $conn->prepare(
        "UPDATE supplier_payments SET payment_no=? WHERE id=? AND business_id=?"
    );
    if (!$stmt) throw new RuntimeException($conn->error);
    $stmt->bind_param('sii', $paymentNo, $paymentId, $businessId);
    if (!$stmt->execute()) throw new RuntimeException($stmt->error);
    $stmt->close();

    $splitStmt = $conn->prepare(
        "INSERT INTO supplier_payment_splits
         (payment_id,payment_method,amount,reference_no,remarks)
         VALUES (?,?,?,?,?)"
    );
    if (!$splitStmt) throw new RuntimeException($conn->error);

    foreach ($splits as $split) {
        $method = $split['method'];
        $amount = $split['amount'];
        $reference = $split['reference'];
        $splitRemark = $split['remarks'];
        $splitStmt->bind_param('isdss', $paymentId, $method, $amount, $reference, $splitRemark);
        if (!$splitStmt->execute()) throw new RuntimeException($splitStmt->error);
    }
    $splitStmt->close();

    $allocationStmt = $conn->prepare(
        "INSERT INTO supplier_payment_allocations
         (payment_id,purchase_id,allocated_amount)
         VALUES (?,?,?)"
    );
    if (!$allocationStmt) throw new RuntimeException($conn->error);

    $updateStmt = $conn->prepare(
        "UPDATE purchases
         SET paid_amount=?,
             balance_amount=?,
             payment_status=?
         WHERE id=? AND business_id=? AND branch_id=?"
    );
    if (!$updateStmt) throw new RuntimeException($conn->error);

    foreach ($validatedPurchases as $purchase) {
        $purchaseId = (int)$purchase['id'];
        $allocatedAmount = (float)$purchase['allocated_amount'];

        $allocationStmt->bind_param('iid', $paymentId, $purchaseId, $allocatedAmount);
        if (!$allocationStmt->execute()) throw new RuntimeException($allocationStmt->error);

        $newPaid = round($purchase['paid_amount'] + $allocatedAmount, 2);
        $newBalance = max(0, round($purchase['grand_total'] - $newPaid, 2));
        $paymentStatus = $newBalance <= 0.01
            ? 'Paid'
            : ($newPaid > 0 ? 'Partial' : 'Unpaid');

        $updateStmt->bind_param(
            'ddsiii',
            $newPaid,
            $newBalance,
            $paymentStatus,
            $purchaseId,
            $businessId,
            $branchId
        );
        if (!$updateStmt->execute()) throw new RuntimeException($updateStmt->error);
    }

    $allocationStmt->close();
    $updateStmt->close();

    $conn->commit();

    respond(true, 'Supplier payment saved successfully.', [
        'payment_id' => $paymentId,
        'payment_no' => $paymentNo,
        'total_amount' => $allocationTotal,
    ]);
} catch (Throwable $error) {
    $conn->rollback();
    respond(false, $error->getMessage(), [], 500);
}