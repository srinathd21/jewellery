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

if (!is_array($methodIds) || !is_array($amounts)) {
    respond(false, 'Invalid payment rows.', [], 422);
}

$conn->begin_transaction();

try {
    $saleStmt = prepareOrFail(
        $conn,
        "SELECT id,invoice_no,customer_id,net_payable_amount,paid_amount,balance_amount,
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

    if ($sale['workflow_status'] === 'Cancelled') {
        throw new RuntimeException('Payment cannot be added to a cancelled invoice.');
    }

    $currentBalance = round(max(0, (float)$sale['balance_amount']), 2);

    if ($currentBalance <= 0.009) {
        throw new RuntimeException('This invoice is already fully paid.');
    }

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

    foreach ($methodIds as $index => $methodRaw) {
        $methodId = (int)$methodRaw;
        $amount = round((float)($amounts[$index] ?? 0), 2);
        $reference = trim((string)($references[$index] ?? ''));

        if ($methodId <= 0 && $amount <= 0) {
            continue;
        }

        if ($methodId <= 0 || $amount <= 0) {
            throw new RuntimeException(
                'Each payment row must have a payment method and amount.'
            );
        }

        $methodStmt->bind_param('ii', $methodId, $businessId);

        if (!$methodStmt->execute()) {
            throw new RuntimeException(
                'Unable to validate payment method: ' . $methodStmt->error
            );
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
            throw new RuntimeException(
                'Credit / Due cannot be used as a received balance payment method.'
            );
        }

        $payments[] = [
            'method_id' => $methodId,
            'amount' => $amount,
            'reference' => $reference,
            'method_name' => (string)$method['method_name']
        ];

        $paymentTotal += $amount;
    }

    $methodStmt->close();

    $paymentTotal = round($paymentTotal, 2);

    if (!$payments || $paymentTotal <= 0) {
        throw new RuntimeException('Add at least one valid payment.');
    }

    if ($paymentTotal > $currentBalance + 0.009) {
        throw new RuntimeException(
            'Payment total cannot exceed the outstanding balance of ₹' .
            number_format($currentBalance, 2) . '.'
        );
    }

    $paymentDate = date('Y-m-d H:i:s');

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
            $reference = trim(
                $reference .
                ($reference !== '' ? ' | ' : '') .
                $remarks
            );
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
            throw new RuntimeException(
                'Unable to save payment: ' . $insertPayment->error
            );
        }
    }

    $insertPayment->close();

    $newPaidAmount = round((float)$sale['paid_amount'] + $paymentTotal, 2);
    $newBalanceAmount = round(max(0, $currentBalance - $paymentTotal), 2);

    $newPaymentStatus = $newBalanceAmount <= 0.009
        ? 'Paid'
        : ($newPaidAmount > 0 ? 'Partial' : 'Unpaid');

    $updateSale = prepareOrFail(
        $conn,
        "UPDATE sales
         SET paid_amount=?,
             balance_amount=?,
             payment_status=?,
             updated_at=CURRENT_TIMESTAMP
         WHERE id=?
           AND business_id=?
           AND branch_id=?
         LIMIT 1",
        'Unable to prepare sale payment update'
    );

    $updateSale->bind_param(
        'ddsiii',
        $newPaidAmount,
        $newBalanceAmount,
        $newPaymentStatus,
        $saleId,
        $businessId,
        $branchId
    );

    if (!$updateSale->execute()) {
        throw new RuntimeException(
            'Unable to update invoice balance: ' . $updateSale->error
        );
    }

    if ($updateSale->affected_rows < 1 && abs($paymentTotal) > 0.009) {
        throw new RuntimeException('Invoice payment totals were not updated.');
    }

    $updateSale->close();

    /*
     * Optional customer payment history.
     * This inserts one receipt per split row only when the expected table/columns exist.
     * Failure here does not occur because the insert is attempted only after schema inspection.
     */
    $customerId = (int)($sale['customer_id'] ?? 0);

    if ($customerId > 0) {
        $tableResult = $conn->query("SHOW TABLES LIKE 'customer_payments'");

        if ($tableResult && $tableResult->num_rows > 0) {
            $columnResult = $conn->query("SHOW COLUMNS FROM customer_payments");
            $columns = [];

            if ($columnResult) {
                while ($column = $columnResult->fetch_assoc()) {
                    $columns[$column['Field']] = true;
                }
            }

            $requiredColumns = [
                'business_id',
                'branch_id',
                'customer_id',
                'sale_id',
                'payment_method_id',
                'amount',
                'payment_date',
                'created_by'
            ];

            $canInsertCustomerPayment = true;
            foreach ($requiredColumns as $requiredColumn) {
                if (!isset($columns[$requiredColumn])) {
                    $canInsertCustomerPayment = false;
                    break;
                }
            }

            if ($canInsertCustomerPayment) {
                $customerPayment = prepareOrFail(
                    $conn,
                    "INSERT INTO customer_payments
                        (business_id,branch_id,customer_id,sale_id,
                         payment_method_id,amount,reference_no,payment_date,created_by)
                     VALUES(?,?,?,?,?,?,?,?,?)",
                    'Unable to prepare customer payment insert'
                );

                foreach ($payments as $payment) {
                    $methodId = (int)$payment['method_id'];
                    $amount = (float)$payment['amount'];
                    $reference = (string)$payment['reference'];

                    if ($remarks !== '') {
                        $reference = trim(
                            $reference .
                            ($reference !== '' ? ' | ' : '') .
                            $remarks
                        );
                    }

                    $customerPayment->bind_param(
                        'iiiiidssi',
                        $businessId,
                        $branchId,
                        $customerId,
                        $saleId,
                        $methodId,
                        $amount,
                        $reference,
                        $paymentDate,
                        $userId
                    );

                    if (!$customerPayment->execute()) {
                        throw new RuntimeException(
                            'Unable to save customer payment history: ' .
                            $customerPayment->error
                        );
                    }
                }

                $customerPayment->close();
            }
        }
    }

    $conn->commit();

    respond(true, 'Split payment saved successfully.', [
        'sale_id' => $saleId,
        'invoice_no' => (string)$sale['invoice_no'],
        'payment_total' => $paymentTotal,
        'paid_amount' => $newPaidAmount,
        'balance_amount' => $newBalanceAmount,
        'payment_status' => $newPaymentStatus,
        'payments' => $payments
    ]);
} catch (Throwable $error) {
    $conn->rollback();
    respond(false, $error->getMessage(), [], 422);
}
