<?php
session_start();
date_default_timezone_set('Asia/Kolkata');

require_once 'includes/config.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not available. Check includes/config.php');
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn->set_charset('utf8mb4');

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('tableExists')) {
    function tableExists(mysqli $conn, string $table): bool
    {
        $table = $conn->real_escape_string($table);
        $res = $conn->query("SHOW TABLES LIKE '{$table}'");
        return $res && $res->num_rows > 0;
    }
}

function hasColumn(mysqli $conn, string $table, string $column): bool
{
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    return $res && $res->num_rows > 0;
}

function addAuditLogSafe(mysqli $conn, int $businessId, int $userId, string $module, string $action, int $refId, string $desc): void
{
    if (function_exists('addAuditLog')) {
        addAuditLog($conn, $businessId, $userId, $module, $action, $refId, $desc);
        return;
    }

    if (!tableExists($conn, 'audit_logs')) {
        return;
    }

    $sql = "INSERT INTO audit_logs (
                business_id, user_id, module_name, action_type, reference_id, description, ip_address, user_agent, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return;
    }

    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

    $stmt->bind_param(
        'iississs',
        $businessId,
        $userId,
        $module,
        $action,
        $refId,
        $desc,
        $ip,
        $ua
    );
    $stmt->execute();
    $stmt->close();
}

function generateReceiptNo(mysqli $conn, int $businessId): string
{
    $prefix = 'RCPT';
    $sql = "SELECT COUNT(*) AS cnt FROM customer_payments WHERE business_id = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('i', $businessId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        $next = ((int)($row['cnt'] ?? 0)) + 1;
    } else {
        $next = rand(1, 9999);
    }

    return $prefix . date('ymd') . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

$pageTitle = 'Customer Payments';

/* -------------------------------------------------------
   AUTH CHECK
------------------------------------------------------- */
if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    header('Location: ../login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$businessId = (int)($_SESSION['business_id'] ?? 0);

if ($businessId <= 0) {
    die('Business session not found. Please login again.');
}

/* -------------------------------------------------------
   ROLE CHECK
------------------------------------------------------- */
$stmt = $conn->prepare("
    SELECT r.role_name
    FROM users u
    INNER JOIN roles r ON r.id = u.role_id
    WHERE u.id = ?
    LIMIT 1
");
$stmt->bind_param('i', $userId);
$stmt->execute();
$res = $stmt->get_result();
$userRow = $res ? $res->fetch_assoc() : null;
$stmt->close();

$roleName = strtolower(trim((string)($userRow['role_name'] ?? '')));
if (!in_array($roleName, ['admin', 'manager', 'billing'], true)) {
    die('Access denied.');
}

/* -------------------------------------------------------
   REQUIRED TABLES
------------------------------------------------------- */
if (
    !tableExists($conn, 'customer_payments') ||
    !tableExists($conn, 'customers') ||
    !tableExists($conn, 'payment_methods')
) {
    die('Required tables not found. Please import the SQL first.');
}

/* -------------------------------------------------------
   COLUMN CHECKS
------------------------------------------------------- */
$cpHasReceiptNo       = hasColumn($conn, 'customer_payments', 'receipt_no');
$cpHasReceiptDate     = hasColumn($conn, 'customer_payments', 'receipt_date');
$cpHasCustomerId      = hasColumn($conn, 'customer_payments', 'customer_id');
$cpHasSaleId          = hasColumn($conn, 'customer_payments', 'sale_id');
$cpHasPaymentMethodId = hasColumn($conn, 'customer_payments', 'payment_method_id');
$cpHasReferenceNo     = hasColumn($conn, 'customer_payments', 'reference_no');
$cpHasAmount          = hasColumn($conn, 'customer_payments', 'amount');
$cpHasNotes           = hasColumn($conn, 'customer_payments', 'notes');
$cpHasCreatedBy       = hasColumn($conn, 'customer_payments', 'created_by');
$cpHasCreatedAt       = hasColumn($conn, 'customer_payments', 'created_at');
$cpHasBusinessId      = hasColumn($conn, 'customer_payments', 'business_id');

$custHasBusinessId    = hasColumn($conn, 'customers', 'business_id');
$custHasMobile        = hasColumn($conn, 'customers', 'mobile');
$custHasCode          = hasColumn($conn, 'customers', 'customer_code');
$custHasActive        = hasColumn($conn, 'customers', 'is_active');

$salesTableExists     = tableExists($conn, 'sales');
$salesHasBusinessId   = $salesTableExists && hasColumn($conn, 'sales', 'business_id');
$salesHasBillNo       = $salesTableExists && hasColumn($conn, 'sales', 'bill_no');
$salesHasGrandTotal   = $salesTableExists && hasColumn($conn, 'sales', 'grand_total');
$salesHasPaidAmount   = $salesTableExists && hasColumn($conn, 'sales', 'paid_amount');
$salesHasBalanceAmt   = $salesTableExists && hasColumn($conn, 'sales', 'balance_amount');
$salesHasCustomerId   = $salesTableExists && hasColumn($conn, 'sales', 'customer_id');
$salesHasBillDate     = $salesTableExists && hasColumn($conn, 'sales', 'bill_date');
$salesHasStatus       = $salesTableExists && hasColumn($conn, 'sales', 'status');

/* -------------------------------------------------------
   FLASH
------------------------------------------------------- */
$success = '';
$error = '';

$msg = trim((string)($_GET['msg'] ?? ''));
if ($msg === 'created') {
    $success = 'Customer payment saved successfully.';
}

/* -------------------------------------------------------
   LOAD CUSTOMERS
------------------------------------------------------- */
$customers = [];
$sql = "SELECT id, customer_name";
if ($custHasCode) {
    $sql .= ", customer_code";
}
if ($custHasMobile) {
    $sql .= ", mobile";
}
$sql .= " FROM customers WHERE 1=1";

$params = [];
$types = '';

if ($custHasBusinessId) {
    $sql .= " AND business_id = ?";
    $params[] = $businessId;
    $types .= 'i';
}
if ($custHasActive) {
    $sql .= " AND is_active = 1";
}
$sql .= " ORDER BY customer_name ASC";

$stmt = $conn->prepare($sql);
if ($stmt) {
    if (!empty($params)) {
        $bind = [];
        $bind[] = $types;
        for ($i = 0; $i < count($params); $i++) {
            $bind[] = &$params[$i];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && $row = $res->fetch_assoc()) {
        $customers[] = $row;
    }
    $stmt->close();
}

/* -------------------------------------------------------
   LOAD PAYMENT METHODS
------------------------------------------------------- */
$paymentMethods = [];
$sql = "SELECT id, method_name FROM payment_methods WHERE is_active = 1 ORDER BY method_name ASC";
$res = $conn->query($sql);
while ($res && $row = $res->fetch_assoc()) {
    $paymentMethods[] = $row;
}

/* -------------------------------------------------------
   CREATE PAYMENT
------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_payment'])) {
    $receiptDate = trim((string)($_POST['receipt_date'] ?? date('Y-m-d')));
    $customerId = (int)($_POST['customer_id'] ?? 0);
    $saleId = (int)($_POST['sale_id'] ?? 0);
    $paymentMethodId = (int)($_POST['payment_method_id'] ?? 0);
    $referenceNo = trim((string)($_POST['reference_no'] ?? ''));
    $amount = (float)($_POST['amount'] ?? 0);
    $notes = trim((string)($_POST['notes'] ?? ''));

    if ($customerId <= 0) {
        $error = 'Please select customer.';
    } elseif ($amount <= 0) {
        $error = 'Please enter valid amount.';
    } elseif ($paymentMethodId <= 0) {
        $error = 'Please select payment method.';
    } else {
        $checkSql = "SELECT id, customer_name FROM customers WHERE id = ?";
        if ($custHasBusinessId) {
            $checkSql .= " AND business_id = ?";
        }
        $checkSql .= " LIMIT 1";

        $stmt = $conn->prepare($checkSql);
        if ($stmt) {
            if ($custHasBusinessId) {
                $stmt->bind_param('ii', $customerId, $businessId);
            } else {
                $stmt->bind_param('i', $customerId);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            $customerRow = $res ? $res->fetch_assoc() : null;
            $stmt->close();
        } else {
            $customerRow = null;
        }

        if (!$customerRow) {
            $error = 'Selected customer not found.';
        } else {
            $receiptNo = generateReceiptNo($conn, $businessId);

            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("
                    INSERT INTO customer_payments
                    (business_id, receipt_no, receipt_date, customer_id, sale_id, payment_method_id, reference_no, amount, notes, created_by, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                if (!$stmt) {
                    throw new Exception('Failed to prepare payment insert.');
                }

                $saleIdParam = $saleId > 0 ? $saleId : null;
                $stmt->bind_param(
                    'issiiisdsi',
                    $businessId,
                    $receiptNo,
                    $receiptDate,
                    $customerId,
                    $saleIdParam,
                    $paymentMethodId,
                    $referenceNo,
                    $amount,
                    $notes,
                    $userId
                );

                if (!$stmt->execute()) {
                    $stmt->close();
                    throw new Exception('Failed to save payment.');
                }
                $paymentId = (int)$stmt->insert_id;
                $stmt->close();

                if ($salesTableExists && $saleId > 0 && $salesHasPaidAmount && $salesHasBalanceAmt) {
                    $salesSql = "UPDATE sales 
                                 SET paid_amount = IFNULL(paid_amount,0) + ?,
                                     balance_amount = GREATEST(IFNULL(grand_total,0) - (IFNULL(paid_amount,0) + ?), 0),
                                     payment_status = CASE
                                         WHEN (IFNULL(paid_amount,0) + ?) <= 0 THEN 'Unpaid'
                                         WHEN (IFNULL(paid_amount,0) + ?) < IFNULL(grand_total,0) THEN 'Partial'
                                         ELSE 'Paid'
                                     END,
                                     updated_at = NOW()
                                 WHERE id = ?";
                    if ($salesHasBusinessId) {
                        $salesSql .= " AND business_id = ?";
                    }
                    $salesSql .= " LIMIT 1";

                    $stmt = $conn->prepare($salesSql);
                    if (!$stmt) {
                        throw new Exception('Failed to prepare sales update.');
                    }

                    if ($salesHasBusinessId) {
                        $stmt->bind_param('ddddii', $amount, $amount, $amount, $amount, $saleId, $businessId);
                    } else {
                        $stmt->bind_param('ddddi', $amount, $amount, $amount, $amount, $saleId);
                    }

                    if (!$stmt->execute()) {
                        $stmt->close();
                        throw new Exception('Failed to update linked sale.');
                    }
                    $stmt->close();
                }

                addAuditLogSafe(
                    $conn,
                    $businessId,
                    $userId,
                    'Customer Payments',
                    'Create',
                    $paymentId,
                    'Created customer payment ' . $receiptNo
                );

                $conn->commit();
                header('Location: customer-payments.php?msg=created');
                exit;
            } catch (Throwable $e) {
                $conn->rollback();
                $error = 'Error: ' . $e->getMessage();
            }
        }
    }
}

/* -------------------------------------------------------
   FILTERS
------------------------------------------------------- */
$search = trim((string)($_GET['search'] ?? ''));
$customerFilter = (int)($_GET['customer_id'] ?? 0);
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));

/* -------------------------------------------------------
   SUMMARY
------------------------------------------------------- */
$totalPayments = 0;
$totalAmount = 0;

$sumSql = "
    SELECT COUNT(*) AS total_payments, SUM(IFNULL(cp.amount,0)) AS total_amount
    FROM customer_payments cp
    WHERE 1=1
";
$sumParams = [];
$sumTypes = '';

if ($cpHasBusinessId) {
    $sumSql .= " AND cp.business_id = ?";
    $sumParams[] = $businessId;
    $sumTypes .= 'i';
}

$stmt = $conn->prepare($sumSql);
if ($stmt) {
    if (!empty($sumParams)) {
        $bind = [];
        $bind[] = $sumTypes;
        for ($i = 0; $i < count($sumParams); $i++) {
            $bind[] = &$sumParams[$i];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    $totalPayments = (int)($row['total_payments'] ?? 0);
    $totalAmount = (float)($row['total_amount'] ?? 0);
}

/* -------------------------------------------------------
   LIST PAYMENTS
------------------------------------------------------- */
$sql = "
    SELECT
        cp.id,
        cp.receipt_no,
        cp.receipt_date,
        cp.customer_id,
        cp.sale_id,
        cp.payment_method_id,
        cp.reference_no,
        cp.amount,
        cp.notes,
        cp.created_at,
        c.customer_name,
        " . ($custHasCode ? "c.customer_code," : "'' AS customer_code,") . "
        " . ($custHasMobile ? "c.mobile," : "'' AS mobile,") . "
        pm.method_name
";

if ($salesTableExists) {
    $sql .= ",
        " . ($salesHasBillNo ? "s.bill_no," : "'' AS bill_no,") . "
        " . ($salesHasBillDate ? "s.bill_date," : "NULL AS bill_date,") . "
        " . ($salesHasGrandTotal ? "s.grand_total," : "0 AS grand_total,") . "
        " . ($salesHasPaidAmount ? "s.paid_amount," : "0 AS paid_amount,") . "
        " . ($salesHasBalanceAmt ? "s.balance_amount" : "0 AS balance_amount");
} else {
    $sql .= ",
        '' AS bill_no,
        NULL AS bill_date,
        0 AS grand_total,
        0 AS paid_amount,
        0 AS balance_amount";
}

$sql .= "
    FROM customer_payments cp
    INNER JOIN customers c ON c.id = cp.customer_id
    LEFT JOIN payment_methods pm ON pm.id = cp.payment_method_id
";

if ($salesTableExists) {
    $sql .= " LEFT JOIN sales s ON s.id = cp.sale_id ";
}

$sql .= " WHERE 1=1 ";

$params = [];
$types = '';

if ($cpHasBusinessId) {
    $sql .= " AND cp.business_id = ? ";
    $params[] = $businessId;
    $types .= 'i';
}

if ($search !== '') {
    $like = '%' . $search . '%';
    $sql .= " AND (
                cp.receipt_no LIKE ?
                OR c.customer_name LIKE ?
                OR cp.reference_no LIKE ?
             ";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'sss';

    if ($custHasCode) {
        $sql .= " OR c.customer_code LIKE ? ";
        $params[] = $like;
        $types .= 's';
    }
    if ($custHasMobile) {
        $sql .= " OR c.mobile LIKE ? ";
        $params[] = $like;
        $types .= 's';
    }
    if ($salesTableExists && $salesHasBillNo) {
        $sql .= " OR s.bill_no LIKE ? ";
        $params[] = $like;
        $types .= 's';
    }

    $sql .= ")";
}

if ($customerFilter > 0) {
    $sql .= " AND cp.customer_id = ? ";
    $params[] = $customerFilter;
    $types .= 'i';
}

if ($dateFrom !== '') {
    $sql .= " AND cp.receipt_date >= ? ";
    $params[] = $dateFrom;
    $types .= 's';
}

if ($dateTo !== '') {
    $sql .= " AND cp.receipt_date <= ? ";
    $params[] = $dateTo;
    $types .= 's';
}

$sql .= " ORDER BY cp.id DESC";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die('Failed to prepare payments query.');
}

if (!empty($params)) {
    $bind = [];
    $bind[] = $types;
    for ($i = 0; $i < count($params); $i++) {
        $bind[] = &$params[$i];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind);
}

$stmt->execute();
$res = $stmt->get_result();
$payments = [];
while ($res && $row = $res->fetch_assoc()) {
    $payments[] = $row;
}
$stmt->close();

/* -------------------------------------------------------
   LOAD SALES FOR FORM
------------------------------------------------------- */
$salesOptions = [];
if ($salesTableExists) {
    $sql = "SELECT id";
    if ($salesHasBillNo) {
        $sql .= ", bill_no";
    }
    if ($salesHasBillDate) {
        $sql .= ", bill_date";
    }
    if ($salesHasGrandTotal) {
        $sql .= ", grand_total";
    }
    if ($salesHasBalanceAmt) {
        $sql .= ", balance_amount";
    }
    if ($salesHasCustomerId) {
        $sql .= ", customer_id";
    }
    $sql .= " FROM sales WHERE 1=1";

    $params = [];
    $types = '';

    if ($salesHasBusinessId) {
        $sql .= " AND business_id = ?";
        $params[] = $businessId;
        $types .= 'i';
    }
    if ($salesHasStatus) {
        $sql .= " AND status = 'Active'";
    }
    if ($salesHasBalanceAmt) {
        $sql .= " AND balance_amount > 0";
    }

    $sql .= " ORDER BY id DESC";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if (!empty($params)) {
            $bind = [];
            $bind[] = $types;
            for ($i = 0; $i < count($params); $i++) {
                $bind[] = &$params[$i];
            }
            call_user_func_array([$stmt, 'bind_param'], $bind);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && $row = $res->fetch_assoc()) {
            $salesOptions[] = $row;
        }
        $stmt->close();
    }
}
?>
<!doctype html>
<html lang="en">

<?php include('includes/head.php'); ?>

<body data-sidebar="dark">

<?php include('includes/pre-loader.php'); ?>

<div id="layout-wrapper">

    <?php include('includes/topbar.php'); ?>

    <div class="vertical-menu">
        <div data-simplebar class="h-100">
            <?php include('includes/sidebar.php'); ?>
        </div>
    </div>

    <div class="main-content">
        <div class="page-content">
            <div class="container-fluid">

                <div class="row mb-3">
                    <div class="col-12 d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div>
                            <h4 class="mb-1">Customer Payments</h4>
                            <p class="text-muted mb-0">Receive and manage payments from customers</p>
                        </div>
                        <div class="d-flex gap-2 flex-wrap">
                            <button type="button" class="btn btn-info" onclick="window.print();">Print</button>
                        </div>
                    </div>
                </div>

                <?php if ($success !== ''): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo h($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo h($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-md-6 col-xl-6">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-primary mt-2"><?php echo $totalPayments; ?></h3>
                                <p class="text-muted mb-0">Total Payments</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-6">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-success mt-2">₹<?php echo number_format($totalAmount, 2); ?></h3>
                                <p class="text-muted mb-0">Total Received Amount</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-lg-5">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Add Customer Payment</h5>
                            </div>
                            <div class="card-body">
                                <form method="post">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label">Receipt Date</label>
                                            <input type="date" name="receipt_date" class="form-control" value="<?php echo h($_POST['receipt_date'] ?? date('Y-m-d')); ?>" required>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Amount</label>
                                            <input type="number" step="0.01" min="0.01" name="amount" class="form-control" value="<?php echo h($_POST['amount'] ?? ''); ?>" required>
                                        </div>

                                        <div class="col-md-12">
                                            <label class="form-label">Customer</label>
                                            <select name="customer_id" class="form-select" required>
                                                <option value="">Select Customer</option>
                                                <?php foreach ($customers as $customer): ?>
                                                    <option value="<?php echo (int)$customer['id']; ?>" <?php echo ((int)($_POST['customer_id'] ?? 0) === (int)$customer['id']) ? 'selected' : ''; ?>>
                                                        <?php
                                                        echo h(
                                                            $customer['customer_name']
                                                            . (!empty($customer['customer_code']) ? ' - ' . $customer['customer_code'] : '')
                                                            . (!empty($customer['mobile']) ? ' - ' . $customer['mobile'] : '')
                                                        );
                                                        ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <?php if ($salesTableExists): ?>
                                            <div class="col-md-12">
                                                <label class="form-label">Linked Sale (Optional)</label>
                                                <select name="sale_id" class="form-select">
                                                    <option value="0">Select Sale</option>
                                                    <?php foreach ($salesOptions as $sale): ?>
                                                        <option value="<?php echo (int)$sale['id']; ?>" <?php echo ((int)($_POST['sale_id'] ?? 0) === (int)$sale['id']) ? 'selected' : ''; ?>>
                                                            <?php
                                                            echo h(
                                                                ($sale['bill_no'] ?? ('Sale #' . $sale['id']))
                                                                . (!empty($sale['bill_date']) ? ' - ' . $sale['bill_date'] : '')
                                                                . (isset($sale['balance_amount']) ? ' - Bal: ₹' . number_format((float)$sale['balance_amount'], 2) : '')
                                                            );
                                                            ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        <?php endif; ?>

                                        <div class="col-md-6">
                                            <label class="form-label">Payment Method</label>
                                            <select name="payment_method_id" class="form-select" required>
                                                <option value="">Select Method</option>
                                                <?php foreach ($paymentMethods as $method): ?>
                                                    <option value="<?php echo (int)$method['id']; ?>" <?php echo ((int)($_POST['payment_method_id'] ?? 0) === (int)$method['id']) ? 'selected' : ''; ?>>
                                                        <?php echo h($method['method_name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Reference No</label>
                                            <input type="text" name="reference_no" class="form-control" value="<?php echo h($_POST['reference_no'] ?? ''); ?>" placeholder="UPI Ref / Txn ID / Cheque No">
                                        </div>

                                        <div class="col-md-12">
                                            <label class="form-label">Notes</label>
                                            <textarea name="notes" class="form-control" rows="3" placeholder="Notes"><?php echo h($_POST['notes'] ?? ''); ?></textarea>
                                        </div>

                                        <div class="col-md-12">
                                            <button type="submit" name="save_payment" value="1" class="btn btn-primary">Save Payment</button>
                                            <a href="customer-payments.php" class="btn btn-secondary">Reset</a>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-7">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Filter Payments</h5>
                            </div>
                            <div class="card-body">
                                <form method="get" class="row g-2 align-items-end">
                                    <div class="col-md-4">
                                        <label class="form-label">Search</label>
                                        <input type="text" name="search" class="form-control" value="<?php echo h($search); ?>" placeholder="Receipt, customer, bill no...">
                                    </div>

                                    <div class="col-md-3">
                                        <label class="form-label">Customer</label>
                                        <select name="customer_id" class="form-select">
                                            <option value="0">All Customers</option>
                                            <?php foreach ($customers as $customer): ?>
                                                <option value="<?php echo (int)$customer['id']; ?>" <?php echo $customerFilter === (int)$customer['id'] ? 'selected' : ''; ?>>
                                                    <?php echo h($customer['customer_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="col-md-2">
                                        <label class="form-label">From</label>
                                        <input type="date" name="date_from" class="form-control" value="<?php echo h($dateFrom); ?>">
                                    </div>

                                    <div class="col-md-2">
                                        <label class="form-label">To</label>
                                        <input type="date" name="date_to" class="form-control" value="<?php echo h($dateTo); ?>">
                                    </div>

                                    <div class="col-md-1">
                                        <button type="submit" class="btn btn-primary w-100">Go</button>
                                    </div>

                                    <div class="col-md-12">
                                        <a href="customer-payments.php" class="btn btn-secondary">Reset</a>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Payment List</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Receipt No</th>
                                        <th>Date</th>
                                        <th>Customer</th>
                                        <th>Linked Sale</th>
                                        <th>Method</th>
                                        <th>Reference</th>
                                        <th>Amount</th>
                                        <th>Notes</th>
                                        <th>Created</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($payments)): ?>
                                        <?php foreach ($payments as $index => $payment): ?>
                                            <tr>
                                                <td><?php echo $index + 1; ?></td>
                                                <td>
                                                    <strong><?php echo h($payment['receipt_no'] ?? ''); ?></strong>
                                                </td>
                                                <td>
                                                    <?php
                                                    if (!empty($payment['receipt_date'])) {
                                                        echo date('d-m-Y', strtotime($payment['receipt_date']));
                                                    } else {
                                                        echo '-';
                                                    }
                                                    ?>
                                                </td>
                                                <td>
                                                    <strong><?php echo h($payment['customer_name'] ?? ''); ?></strong>
                                                    <?php if (!empty($payment['customer_code'])): ?>
                                                        <br><small class="text-muted"><?php echo h($payment['customer_code']); ?></small>
                                                    <?php endif; ?>
                                                    <?php if (!empty($payment['mobile'])): ?>
                                                        <br><small class="text-muted"><?php echo h($payment['mobile']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($payment['bill_no'])): ?>
                                                        <strong><?php echo h($payment['bill_no']); ?></strong>
                                                        <?php if (!empty($payment['bill_date'])): ?>
                                                            <br><small class="text-muted"><?php echo date('d-m-Y', strtotime($payment['bill_date'])); ?></small>
                                                        <?php endif; ?>
                                                        <?php if (isset($payment['balance_amount'])): ?>
                                                            <br><small class="text-danger">Bal: ₹<?php echo number_format((float)$payment['balance_amount'], 2); ?></small>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        -
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo h($payment['method_name'] ?? ''); ?></td>
                                                <td><?php echo h($payment['reference_no'] ?? ''); ?></td>
                                                <td><strong>₹<?php echo number_format((float)($payment['amount'] ?? 0), 2); ?></strong></td>
                                                <td><?php echo h($payment['notes'] ?? ''); ?></td>
                                                <td>
                                                    <?php
                                                    if (!empty($payment['created_at'])) {
                                                        echo date('d-m-Y h:i A', strtotime($payment['created_at']));
                                                    } else {
                                                        echo '-';
                                                    }
                                                    ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="10" class="text-center text-muted">No customer payments found.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <?php include('includes/footer.php'); ?>
    </div>
</div>

<?php include('includes/rightbar.php'); ?>
<?php include('includes/scripts.php'); ?>

</body>
</html>