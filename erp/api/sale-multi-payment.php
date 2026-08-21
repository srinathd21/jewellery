<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));
header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_OFF);

foreach ([
    dirname(__DIR__) . '/config/config.php',
    dirname(__DIR__) . '/config.php',
    dirname(__DIR__) . '/includes/config.php',
    dirname(__DIR__) . '/super-admin/includes/config.php'
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

function prepareOrFail(mysqli $conn, string $sql, string $label): mysqli_stmt
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($label . ': ' . $conn->error);
    }
    return $stmt;
}

function tableExists(mysqli $conn, string $table): bool
{
    $safe = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $result && $result->num_rows > 0;
}

function paymentPermission(mysqli $conn): bool
{
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin') {
        return true;
    }

    $permissionCodes = ['perm.payments', 'perm.sales.list', 'perm.sales', 'perm.billing'];

    foreach ($permissionCodes as $code) {
        if (!empty($_SESSION['permissions'][$code]['can_create']) || !empty($_SESSION['permissions'][$code]['can_update'])) {
            return true;
        }
    }

    $businessId = (int)($_SESSION['business_id'] ?? 0);
    $roleId = (int)($_SESSION['role_id'] ?? 0);
    if ($businessId <= 0 || $roleId <= 0) {
        return false;
    }

    $sql = "SELECT rp.can_create, rp.can_update
            FROM role_permissions rp
            INNER JOIN permissions p ON p.id = rp.permission_id
            WHERE rp.business_id = ?
              AND rp.role_id = ?
              AND p.is_active = 1
              AND p.permission_code IN ('perm.payments','perm.sales.list','perm.sales','perm.billing')";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ii', $businessId, $roleId);
    $stmt->execute();
    $result = $stmt->get_result();
    $allowed = false;
    while ($row = $result->fetch_assoc()) {
        if ((int)($row['can_create'] ?? 0) === 1 || (int)($row['can_update'] ?? 0) === 1) {
            $allowed = true;
            break;
        }
    }
    $stmt->close();
    return $allowed;
}

function saveCustomerReceipt(
    mysqli $conn,
    int $businessId,
    int $branchId,
    int $customerId,
    int $saleId,
    string $invoiceNo,
    float $paymentTotal,
    array $payments,
    string $remarks,
    int $userId
): ?array {
    if ($paymentTotal <= 0.009 || $customerId <= 0) {
        return null;
    }

    if (!tableExists($conn, 'customer_payments')) {
        return null;
    }

    $primaryMethodId = 0;
    foreach ($payments as $payment) {
        if ((int)$payment['method_id'] > 0 && (float)$payment['amount'] > 0) {
            $primaryMethodId = (int)$payment['method_id'];
            break;
        }
    }
    if ($primaryMethodId <= 0) {
        throw new RuntimeException('Unable to determine the payment method for the customer receipt.');
    }

    $receiptDate = date('Y-m-d');
    $paymentDate = date('Y-m-d H:i:s');
    $temporaryNo = 'TMP-' . $businessId . '-' . bin2hex(random_bytes(8));
    $headerReference = 'Balance payment - ' . $invoiceNo;

    $stmt = prepareOrFail(
        $conn,
        "INSERT INTO customer_payments
            (business_id,branch_id,customer_id,sale_id,receipt_no,receipt_date,
             payment_method_id,amount,reference_no,remarks,created_by,payment_no,payment_date)
         VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)",
        'Unable to prepare customer payment receipt'
    );

    $stmt->bind_param(
        'iiiissidssiss',
        $businessId,
        $branchId,
        $customerId,
        $saleId,
        $temporaryNo,
        $receiptDate,
        $primaryMethodId,
        $paymentTotal,
        $headerReference,
        $remarks,
        $userId,
        $temporaryNo,
        $paymentDate
    );

    if (!$stmt->execute()) {
        throw new RuntimeException('Unable to save customer payment history: ' . $stmt->error);
    }

    $paymentId = (int)$stmt->insert_id;
    $stmt->close();

    $receiptNo = 'CPY/' . date('Ym', strtotime($receiptDate)) . '/' . str_pad((string)$paymentId, 6, '0', STR_PAD_LEFT);

    $stmt = prepareOrFail(
        $conn,
        'UPDATE customer_payments SET receipt_no=?, payment_no=? WHERE id=? AND business_id=? LIMIT 1',
        'Unable to finalize customer receipt number'
    );
    $stmt->bind_param('ssii', $receiptNo, $receiptNo, $paymentId, $businessId);
    if (!$stmt->execute()) {
        throw new RuntimeException('Unable to finalize customer receipt number: ' . $stmt->error);
    }
    $stmt->close();

    if (tableExists($conn, 'customer_payment_splits')) {
        $splitStmt = prepareOrFail(
            $conn,
            'INSERT INTO customer_payment_splits(payment_id,payment_method_id,amount,reference_no,created_by) VALUES(?,?,?,?,?)',
            'Unable to prepare customer payment split'
        );

        foreach ($payments as $payment) {
            $methodId = (int)$payment['method_id'];
            $amount = (float)$payment['amount'];
            $reference = (string)$payment['reference'];
            $splitStmt->bind_param('iidsi', $paymentId, $methodId, $amount, $reference, $userId);
            if (!$splitStmt->execute()) {
                throw new RuntimeException('Unable to save customer payment split: ' . $splitStmt->error);
            }
        }
        $splitStmt->close();
    }

    return [
        'payment_id' => $paymentId,
        'receipt_no' => $receiptNo,
        'receipt_date' => $receiptDate,
    ];
}

function auditPaymentAdjustment(
    mysqli $conn,
    int $businessId,
    int $branchId,
    int $userId,
    int $saleId,
    string $invoiceNo,
    array $oldValues,
    array $newValues
): void {
    if (!tableExists($conn, 'audit_logs')) {
        return;
    }

    $description = 'Balance payment / discount updated for ' . $invoiceNo;
    $oldJson = json_encode($oldValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $newJson = json_encode($newValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

    $stmt = $conn->prepare("INSERT INTO audit_logs
        (business_id,branch_id,user_id,module_code,action_type,reference_table,reference_id,
         description,old_values_json,new_values_json,ip_address,user_agent)
        VALUES(?,?,?,'sales.payment','Update','sales',?,?,?,?,?,?)");
    if (!$stmt) {
        return;
    }

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

if (
    empty($_SESSION['sales_payment_csrf']) ||
    !hash_equals(
        (string)$_SESSION['sales_payment_csrf'],
        (string)($_POST['csrf_token'] ?? '')
    )
) {
    respond(false, 'Invalid or expired security token.', [], 419);
}

if (!paymentPermission($conn)) {
    respond(false, 'You do not have permission to receive or adjust sale payments.', [], 403);
}

$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? ($_SESSION['default_branch_id'] ?? 0));
$userId = (int)($_SESSION['user_id'] ?? 0);
$saleId = (int)($_POST['sale_id'] ?? 0);

if ($businessId <= 0 || $branchId <= 0 || $saleId <= 0) {
    respond(false, 'Invalid sale or business context.', [], 422);
}

$methodIds = $_POST['payment_method_id'] ?? [];
$amounts = $_POST['payment_amount'] ?? [];
$references = $_POST['payment_reference'] ?? [];
$remarks = trim((string)($_POST['remarks'] ?? ''));
$additionalDiscount = round(max(0, (float)($_POST['additional_discount'] ?? 0)), 2);
$discountReason = trim((string)($_POST['discount_reason'] ?? ''));

if (!is_array($methodIds) || !is_array($amounts) || !is_array($references)) {
    respond(false, 'Invalid payment rows.', [], 422);
}

$conn->begin_transaction();

try {
    $saleStmt = prepareOrFail(
        $conn,
        "SELECT id,invoice_no,customer_id,discount_amount,taxable_amount,grand_total,
                exchange_amount,chit_claim_amount,net_payable_amount,paid_amount,balance_amount,
                payment_status,workflow_status
         FROM sales
         WHERE id=?
           AND business_id=?
           AND branch_id=?
         LIMIT 1
         FOR UPDATE",
        'Unable to load sale'
    );
    $saleStmt->bind_param('iii', $saleId, $businessId, $branchId);
    if (!$saleStmt->execute()) {
        throw new RuntimeException('Unable to load sale: ' . $saleStmt->error);
    }
    $sale = $saleStmt->get_result()->fetch_assoc();
    $saleStmt->close();

    if (!$sale) {
        throw new RuntimeException('Sale not found.');
    }
    if ((string)$sale['workflow_status'] === 'Cancelled') {
        throw new RuntimeException('Payment cannot be added to a cancelled invoice.');
    }

    $currentBalance = round(max(0, (float)$sale['balance_amount']), 2);
    if ($currentBalance <= 0.009) {
        throw new RuntimeException('This invoice is already fully paid.');
    }

    if ($additionalDiscount > $currentBalance + 0.009) {
        throw new RuntimeException(
            'Additional discount cannot exceed the current outstanding balance of ₹' .
            number_format($currentBalance, 2) . '.'
        );
    }

    $balanceAfterDiscount = round(max(0, $currentBalance - $additionalDiscount), 2);

    $methodStmt = prepareOrFail(
        $conn,
        "SELECT id,method_name,method_type
         FROM payment_methods
         WHERE id=?
           AND business_id=?
           AND is_active=1
         LIMIT 1",
        'Unable to validate payment method'
    );

    $payments = [];
    $paymentTotal = 0.0;
    $maxRows = max(count($methodIds), count($amounts), count($references));

    for ($index = 0; $index < $maxRows; $index++) {
        $methodId = (int)($methodIds[$index] ?? 0);
        $amount = round(max(0, (float)($amounts[$index] ?? 0)), 2);
        $reference = trim((string)($references[$index] ?? ''));

        if ($methodId <= 0 && $amount <= 0) {
            continue;
        }
        if ($methodId <= 0 || $amount <= 0) {
            throw new RuntimeException('Each used payment row must have both payment method and amount.');
        }

        $methodStmt->bind_param('ii', $methodId, $businessId);
        if (!$methodStmt->execute()) {
            throw new RuntimeException('Unable to validate payment method: ' . $methodStmt->error);
        }
        $method = $methodStmt->get_result()->fetch_assoc();
        if (!$method) {
            throw new RuntimeException('A selected payment method is invalid or inactive.');
        }

        $methodName = strtolower(trim((string)$method['method_name']));
        $methodType = strtolower(trim((string)$method['method_type']));
        $isCredit =
            $methodType === 'credit' ||
            strpos($methodName, 'credit') !== false ||
            strpos($methodName, 'due') !== false ||
            strpos($methodName, 'pay later') !== false ||
            strpos($methodName, 'paylater') !== false;

        if ($isCredit) {
            throw new RuntimeException('Credit / Due cannot be used as a received balance payment method.');
        }

        $payments[] = [
            'method_id' => $methodId,
            'amount' => $amount,
            'reference' => $reference,
            'method_name' => (string)$method['method_name'],
        ];
        $paymentTotal += $amount;
    }
    $methodStmt->close();
    $paymentTotal = round($paymentTotal, 2);

    if ($additionalDiscount <= 0.009 && $paymentTotal <= 0.009) {
        throw new RuntimeException('Enter an additional discount or add at least one payment.');
    }

    if ($paymentTotal > $balanceAfterDiscount + 0.009) {
        throw new RuntimeException(
            'Payment total cannot exceed the balance after discount of ₹' .
            number_format($balanceAfterDiscount, 2) . '.'
        );
    }

    $paymentDate = date('Y-m-d H:i:s');

    if ($paymentTotal > 0.009) {
        $insertPayment = prepareOrFail(
            $conn,
            "INSERT INTO sale_payments
                (business_id,branch_id,sale_id,payment_method_id,amount,
                 reference_no,payment_date,created_by)
             VALUES(?,?,?,?,?,?,?,?)",
            'Unable to prepare payment insert'
        );

        foreach ($payments as $payment) {
            $methodId = (int)$payment['method_id'];
            $amount = (float)$payment['amount'];
            $reference = (string)$payment['reference'];
            if ($remarks !== '') {
                $reference = trim($reference . ($reference !== '' ? ' | ' : '') . $remarks);
            }

            $insertPayment->bind_param(
                'iiiidssi',
                $businessId,
                $branchId,
                $saleId,
                $methodId,
                $amount,
                $reference,
                $paymentDate,
                $userId
            );
            if (!$insertPayment->execute()) {
                throw new RuntimeException('Unable to save payment: ' . $insertPayment->error);
            }
        }
        $insertPayment->close();
    }

    $oldDiscount = round((float)$sale['discount_amount'], 2);
    $oldTaxable = round((float)$sale['taxable_amount'], 2);
    $oldGrandTotal = round((float)$sale['grand_total'], 2);
    $oldNetPayable = round((float)$sale['net_payable_amount'], 2);
    $oldPaidAmount = round((float)$sale['paid_amount'], 2);

    // Same cumulative behavior as Billing Overall Discount:
    // existing invoice discount + additional settlement discount.
    // Existing GST amounts are not recalculated here; this mirrors the current overall-discount behavior.
    $newDiscount = round($oldDiscount + $additionalDiscount, 2);
    $newTaxable = round(max(0, $oldTaxable - $additionalDiscount), 2);
    $newGrandTotal = round(max(0, $oldGrandTotal - $additionalDiscount), 2);
    $newNetPayable = round(max(0, $oldNetPayable - $additionalDiscount), 2);
    $newPaidAmount = round($oldPaidAmount + $paymentTotal, 2);
    $newBalanceAmount = round(max(0, $currentBalance - $additionalDiscount - $paymentTotal), 2);

    if ($newPaidAmount > $newNetPayable + 0.009) {
        throw new RuntimeException('Discount/payment would make the invoice total lower than the amount already received.');
    }

    $newPaymentStatus = $newBalanceAmount <= 0.009
        ? 'Paid'
        : ($newPaidAmount > 0 ? 'Partial' : 'Unpaid');

    $updateSale = prepareOrFail(
        $conn,
        "UPDATE sales
         SET discount_amount=?,
             taxable_amount=?,
             grand_total=?,
             net_payable_amount=?,
             paid_amount=?,
             balance_amount=?,
             payment_status=?,
             updated_at=CURRENT_TIMESTAMP
         WHERE id=?
           AND business_id=?
           AND branch_id=?
         LIMIT 1",
        'Unable to prepare sale payment/discount update'
    );

    $updateSale->bind_param(
        'ddddddsiii',
        $newDiscount,
        $newTaxable,
        $newGrandTotal,
        $newNetPayable,
        $newPaidAmount,
        $newBalanceAmount,
        $newPaymentStatus,
        $saleId,
        $businessId,
        $branchId
    );

    if (!$updateSale->execute()) {
        throw new RuntimeException('Unable to update invoice totals: ' . $updateSale->error);
    }
    $updateSale->close();

    $receiptRemarks = $remarks;
    if ($additionalDiscount > 0.009) {
        $discountText = 'Additional discount ₹' . number_format($additionalDiscount, 2);
        if ($discountReason !== '') {
            $discountText .= ' - ' . $discountReason;
        }
        $receiptRemarks = trim($receiptRemarks . ($receiptRemarks !== '' ? ' | ' : '') . $discountText);
    }

    $receipt = saveCustomerReceipt(
        $conn,
        $businessId,
        $branchId,
        (int)$sale['customer_id'],
        $saleId,
        (string)$sale['invoice_no'],
        $paymentTotal,
        $payments,
        $receiptRemarks,
        $userId
    );

    auditPaymentAdjustment(
        $conn,
        $businessId,
        $branchId,
        $userId,
        $saleId,
        (string)$sale['invoice_no'],
        [
            'discount_amount' => $oldDiscount,
            'taxable_amount' => $oldTaxable,
            'grand_total' => $oldGrandTotal,
            'net_payable_amount' => $oldNetPayable,
            'paid_amount' => $oldPaidAmount,
            'balance_amount' => $currentBalance,
            'payment_status' => (string)$sale['payment_status'],
        ],
        [
            'additional_discount' => $additionalDiscount,
            'discount_reason' => $discountReason,
            'discount_amount' => $newDiscount,
            'taxable_amount' => $newTaxable,
            'grand_total' => $newGrandTotal,
            'net_payable_amount' => $newNetPayable,
            'payment_received_now' => $paymentTotal,
            'paid_amount' => $newPaidAmount,
            'balance_amount' => $newBalanceAmount,
            'payment_status' => $newPaymentStatus,
            'receipt_no' => $receipt['receipt_no'] ?? null,
            'payments' => $payments,
        ]
    );

    $conn->commit();

    respond(true, 'Payment / discount saved successfully.', [
        'sale_id' => $saleId,
        'invoice_no' => (string)$sale['invoice_no'],
        'additional_discount' => $additionalDiscount,
        'total_discount' => $newDiscount,
        'payment_total' => $paymentTotal,
        'paid_amount' => $newPaidAmount,
        'balance_amount' => $newBalanceAmount,
        'net_payable_amount' => $newNetPayable,
        'payment_status' => $newPaymentStatus,
        'receipt_no' => $receipt['receipt_no'] ?? null,
        'payments' => $payments,
    ]);
} catch (Throwable $error) {
    $conn->rollback();
    respond(false, $error->getMessage(), [], 422);
}
