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
    function tableExists(mysqli $conn, string $tableName): bool
    {
        $safe = $conn->real_escape_string($tableName);
        $res = $conn->query("SHOW TABLES LIKE '{$safe}'");
        return $res && $res->num_rows > 0;
    }
}

if (!function_exists('hasColumn')) {
    function hasColumn(mysqli $conn, string $table, string $column): bool
    {
        $table = $conn->real_escape_string($table);
        $column = $conn->real_escape_string($column);
        $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
        return $res && $res->num_rows > 0;
    }
}

if (!function_exists('money')) {
    function money($amount): string
    {
        return number_format((float)$amount, 2);
    }
}

if (!function_exists('addAuditLog')) {
    function addAuditLog(mysqli $conn, ?int $businessId, ?int $userId, string $module, string $action, ?int $referenceId, string $description): void
    {
        if (!tableExists($conn, 'audit_logs')) {
            return;
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $stmt = $conn->prepare("
            INSERT INTO audit_logs
            (business_id, user_id, module_name, action_type, reference_id, description, ip_address, user_agent, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        if ($stmt) {
            $stmt->bind_param('iississs', $businessId, $userId, $module, $action, $referenceId, $description, $ip, $ua);
            $stmt->execute();
            $stmt->close();
        }
    }
}

function generatePaymentNo(mysqli $conn, int $businessId): string
{
    $prefix = 'SPY' . date('Ymd');
    $running = 1;

    $stmt = $conn->prepare("
        SELECT payment_no
        FROM supplier_payments
        WHERE business_id = ? AND payment_no LIKE ?
        ORDER BY id DESC
        LIMIT 1
    ");
    if ($stmt) {
        $like = $prefix . '%';
        $stmt->bind_param('is', $businessId, $like);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if ($row && !empty($row['payment_no']) && preg_match('/(\d{4})$/', $row['payment_no'], $m)) {
            $running = ((int)$m[1]) + 1;
        }
    }

    return $prefix . str_pad((string)$running, 4, '0', STR_PAD_LEFT);
}

$pageTitle = 'Supplier Payments';

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
if (!in_array($roleName, ['admin', 'manager', 'billing', 'stock'], true)) {
    die('Access denied.');
}

/* -------------------------------------------------------
   REQUIRED TABLES
------------------------------------------------------- */
if (!tableExists($conn, 'supplier_payments') || !tableExists($conn, 'suppliers')) {
    die('Required tables not found. Please import the SQL first.');
}

/* -------------------------------------------------------
   FLASH
------------------------------------------------------- */
$success = '';
$error = '';

$msg = trim((string)($_GET['msg'] ?? ''));
if ($msg === 'created') {
    $success = 'Supplier payment added successfully.';
} elseif ($msg === 'deleted') {
    $success = 'Supplier payment deleted successfully.';
}

/* -------------------------------------------------------
   DELETE PAYMENT
------------------------------------------------------- */
if (isset($_GET['delete']) && (int)$_GET['delete'] > 0) {
    $deleteId = (int)$_GET['delete'];

    $stmt = $conn->prepare("DELETE FROM supplier_payments WHERE id = ? AND business_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('ii', $deleteId, $businessId);
        if ($stmt->execute()) {
            addAuditLog(
                $conn,
                $businessId,
                $userId,
                'Supplier Payments',
                'Delete',
                $deleteId,
                'Deleted supplier payment'
            );
        }
        $stmt->close();
    }

    header('Location: suppliers-payments.php?msg=deleted');
    exit;
}

/* -------------------------------------------------------
   LOAD SUPPLIERS
------------------------------------------------------- */
$suppliers = [];
$stmt = $conn->prepare("
    SELECT id, supplier_name, supplier_code, mobile
    FROM suppliers
    WHERE business_id = ?
    " . (hasColumn($conn, 'suppliers', 'is_active') ? "AND is_active = 1" : "") . "
    ORDER BY supplier_name ASC
");
if ($stmt) {
    $stmt->bind_param('i', $businessId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && $row = $res->fetch_assoc()) {
        $suppliers[] = $row;
    }
    $stmt->close();
}

/* -------------------------------------------------------
   LOAD PURCHASES
------------------------------------------------------- */
$purchases = [];
if (tableExists($conn, 'purchases')) {
    $stmt = $conn->prepare("
        SELECT id, purchase_no, purchase_date, supplier_id, grand_total, balance_amount
        FROM purchases
        WHERE business_id = ?
        ORDER BY id DESC
    ");
    if ($stmt) {
        $stmt->bind_param('i', $businessId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && $row = $res->fetch_assoc()) {
            $purchases[] = $row;
        }
        $stmt->close();
    }
}

/* -------------------------------------------------------
   LOAD PAYMENT METHODS
------------------------------------------------------- */
$paymentMethods = [];
if (tableExists($conn, 'payment_methods')) {
    $sql = "SELECT id, method_name FROM payment_methods WHERE 1=1";
    if (hasColumn($conn, 'payment_methods', 'is_active')) {
        $sql .= " AND is_active = 1";
    }
    $sql .= " ORDER BY id ASC";
    $res = $conn->query($sql);
    while ($res && $row = $res->fetch_assoc()) {
        $paymentMethods[] = $row;
    }
}

/* -------------------------------------------------------
   ADD PAYMENT
------------------------------------------------------- */
$paymentNo = generatePaymentNo($conn, $businessId);
$paymentDate = date('Y-m-d');
$supplierId = 0;
$purchaseId = 0;
$paymentMethodId = 0;
$referenceNo = '';
$amount = '0.00';
$notes = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_payment'])) {
    $paymentNo = trim((string)($_POST['payment_no'] ?? ''));
    $paymentDate = trim((string)($_POST['payment_date'] ?? ''));
    $supplierId = (int)($_POST['supplier_id'] ?? 0);
    $purchaseId = (int)($_POST['purchase_id'] ?? 0);
    $paymentMethodId = (int)($_POST['payment_method_id'] ?? 0);
    $referenceNo = trim((string)($_POST['reference_no'] ?? ''));
    $amount = trim((string)($_POST['amount'] ?? '0'));
    $notes = trim((string)($_POST['notes'] ?? ''));

    if ($paymentNo === '') {
        $paymentNo = generatePaymentNo($conn, $businessId);
    }

    if ($paymentDate === '') {
        $error = 'Payment date is required.';
    } elseif ($supplierId <= 0) {
        $error = 'Please select supplier.';
    } elseif (!is_numeric($amount) || (float)$amount <= 0) {
        $error = 'Please enter valid payment amount.';
    } else {
        $amountF = (float)$amount;

        $conn->begin_transaction();

        try {
            $stmt = $conn->prepare("
                INSERT INTO supplier_payments
                (business_id, payment_no, payment_date, supplier_id, purchase_id, payment_method_id, reference_no, amount, notes, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            if (!$stmt) {
                throw new Exception('Failed to prepare payment insert.');
            }

            $purchaseIdValue = $purchaseId > 0 ? $purchaseId : null;
            $paymentMethodValue = $paymentMethodId > 0 ? $paymentMethodId : null;

            $stmt->bind_param(
                'issiiisdsi',
                $businessId,
                $paymentNo,
                $paymentDate,
                $supplierId,
                $purchaseIdValue,
                $paymentMethodValue,
                $referenceNo,
                $amountF,
                $notes,
                $userId
            );

            if (!$stmt->execute()) {
                throw new Exception('Failed to save supplier payment.');
            }

            $paymentInsertId = (int)$stmt->insert_id;
            $stmt->close();

            if ($purchaseId > 0 && tableExists($conn, 'purchases')) {
                $stmt = $conn->prepare("
                    SELECT paid_amount, grand_total, balance_amount
                    FROM purchases
                    WHERE id = ? AND business_id = ?
                    LIMIT 1
                ");
                if ($stmt) {
                    $stmt->bind_param('ii', $purchaseId, $businessId);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    $purchaseRow = $res ? $res->fetch_assoc() : null;
                    $stmt->close();

                    if ($purchaseRow) {
                        $newPaid = (float)$purchaseRow['paid_amount'] + $amountF;
                        $grandTotal = (float)$purchaseRow['grand_total'];
                        $newBalance = $grandTotal - $newPaid;
                        if ($newBalance < 0) {
                            $newBalance = 0;
                        }

                        $paymentStatus = 'Unpaid';
                        if ($newPaid > 0 && $newPaid < $grandTotal) {
                            $paymentStatus = 'Partial';
                        } elseif ($newPaid >= $grandTotal && $grandTotal > 0) {
                            $paymentStatus = 'Paid';
                        }

                        $stmt = $conn->prepare("
                            UPDATE purchases
                            SET paid_amount = ?, balance_amount = ?, payment_status = ?, updated_at = NOW()
                            WHERE id = ? AND business_id = ?
                            LIMIT 1
                        ");
                        if ($stmt) {
                            $stmt->bind_param('ddsii', $newPaid, $newBalance, $paymentStatus, $purchaseId, $businessId);
                            if (!$stmt->execute()) {
                                throw new Exception('Failed to update purchase payment status.');
                            }
                            $stmt->close();
                        }
                    }
                }
            }

            addAuditLog(
                $conn,
                $businessId,
                $userId,
                'Supplier Payments',
                'Create',
                $paymentInsertId,
                'Created supplier payment ' . $paymentNo
            );

            $conn->commit();
            header('Location: suppliers-payments.php?msg=created');
            exit;
        } catch (Throwable $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    }
}

/* -------------------------------------------------------
   FILTERS
------------------------------------------------------- */
$search = trim((string)($_GET['search'] ?? ''));
$filterSupplierId = (int)($_GET['filter_supplier_id'] ?? 0);
$fromDate = trim((string)($_GET['from_date'] ?? ''));
$toDate = trim((string)($_GET['to_date'] ?? ''));

$where = " WHERE sp.business_id = ? ";
$params = [$businessId];
$types = 'i';

if ($search !== '') {
    $where .= " AND (
        sp.payment_no LIKE ?
        OR sp.reference_no LIKE ?
        OR s.supplier_name LIKE ?
        OR p.purchase_no LIKE ?
    )";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'ssss';
}

if ($filterSupplierId > 0) {
    $where .= " AND sp.supplier_id = ? ";
    $params[] = $filterSupplierId;
    $types .= 'i';
}

if ($fromDate !== '') {
    $where .= " AND sp.payment_date >= ? ";
    $params[] = $fromDate;
    $types .= 's';
}

if ($toDate !== '') {
    $where .= " AND sp.payment_date <= ? ";
    $params[] = $toDate;
    $types .= 's';
}

/* -------------------------------------------------------
   SUMMARY
------------------------------------------------------- */
$totalPayments = 0;
$totalPaymentAmount = 0.00;

$sql = "
    SELECT COUNT(*) AS cnt, COALESCE(SUM(sp.amount),0) AS total_amount
    FROM supplier_payments sp
    LEFT JOIN suppliers s ON s.id = sp.supplier_id
    LEFT JOIN purchases p ON p.id = sp.purchase_id
    $where
";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $bindValues = [];
    $bindValues[] = $types;
    for ($i = 0; $i < count($params); $i++) {
        $bindValues[] = &$params[$i];
    }
    call_user_func_array([$stmt, 'bind_param'], $bindValues);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $totalPayments = (int)($row['cnt'] ?? 0);
    $totalPaymentAmount = (float)($row['total_amount'] ?? 0);
    $stmt->close();
}

/* -------------------------------------------------------
   LIST
------------------------------------------------------- */
$payments = [];
$sql = "
    SELECT
        sp.*,
        s.supplier_name,
        s.supplier_code,
        p.purchase_no,
        pm.method_name
    FROM supplier_payments sp
    LEFT JOIN suppliers s ON s.id = sp.supplier_id
    LEFT JOIN purchases p ON p.id = sp.purchase_id
    LEFT JOIN payment_methods pm ON pm.id = sp.payment_method_id
    $where
    ORDER BY sp.id DESC
";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $bindValues = [];
    $bindValues[] = $types;
    for ($i = 0; $i < count($params); $i++) {
        $bindValues[] = &$params[$i];
    }
    call_user_func_array([$stmt, 'bind_param'], $bindValues);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && $row = $res->fetch_assoc()) {
        $payments[] = $row;
    }
    $stmt->close();
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
                    <div class="col-md-6">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-primary mt-2"><?php echo $totalPayments; ?></h3>
                                <p class="text-muted mb-0">Total Supplier Payments</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-success mt-2">₹ <?php echo money($totalPaymentAmount); ?></h3>
                                <p class="text-muted mb-0">Total Paid Amount</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">Add Supplier Payment</h4>

                        <form method="post">
                            <input type="hidden" name="save_payment" value="1">

                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Payment No</label>
                                    <input type="text" name="payment_no" class="form-control" value="<?php echo h($paymentNo); ?>" required>
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Payment Date</label>
                                    <input type="date" name="payment_date" class="form-control" value="<?php echo h($paymentDate); ?>" required>
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Supplier <span class="text-danger">*</span></label>
                                    <select name="supplier_id" class="form-select" required>
                                        <option value="">Select Supplier</option>
                                        <?php foreach ($suppliers as $supplier): ?>
                                            <option value="<?php echo (int)$supplier['id']; ?>" <?php echo $supplierId === (int)$supplier['id'] ? 'selected' : ''; ?>>
                                                <?php
                                                echo h($supplier['supplier_name']);
                                                if (!empty($supplier['supplier_code'])) {
                                                    echo ' (' . h($supplier['supplier_code']) . ')';
                                                }
                                                ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Purchase</label>
                                    <select name="purchase_id" class="form-select">
                                        <option value="0">Select Purchase</option>
                                        <?php foreach ($purchases as $purchase): ?>
                                            <option value="<?php echo (int)$purchase['id']; ?>" <?php echo $purchaseId === (int)$purchase['id'] ? 'selected' : ''; ?>>
                                                <?php echo h(($purchase['purchase_no'] ?? '') . ' - ' . date('d-m-Y', strtotime($purchase['purchase_date'])) . ' - Bal ₹' . money($purchase['balance_amount'] ?? 0)); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Payment Method</label>
                                    <select name="payment_method_id" class="form-select">
                                        <option value="0">Select Method</option>
                                        <?php foreach ($paymentMethods as $pm): ?>
                                            <option value="<?php echo (int)$pm['id']; ?>" <?php echo $paymentMethodId === (int)$pm['id'] ? 'selected' : ''; ?>>
                                                <?php echo h($pm['method_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Reference No</label>
                                    <input type="text" name="reference_no" class="form-control" value="<?php echo h($referenceNo); ?>">
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Amount <span class="text-danger">*</span></label>
                                    <input type="number" step="0.01" min="0" name="amount" class="form-control" value="<?php echo h($amount); ?>" required>
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Notes</label>
                                    <input type="text" name="notes" class="form-control" value="<?php echo h($notes); ?>">
                                </div>

                                <div class="col-md-12">
                                    <button type="submit" class="btn btn-primary">Save Payment</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">Filter Payments</h4>

                        <form method="get" class="row g-2 align-items-end">
                            <div class="col-md-3">
                                <label class="form-label">Search</label>
                                <input type="text" name="search" class="form-control" placeholder="Payment no, supplier, purchase..." value="<?php echo h($search); ?>">
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Supplier</label>
                                <select name="filter_supplier_id" class="form-select">
                                    <option value="0">All Suppliers</option>
                                    <?php foreach ($suppliers as $supplier): ?>
                                        <option value="<?php echo (int)$supplier['id']; ?>" <?php echo $filterSupplierId === (int)$supplier['id'] ? 'selected' : ''; ?>>
                                            <?php echo h($supplier['supplier_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">From Date</label>
                                <input type="date" name="from_date" class="form-control" value="<?php echo h($fromDate); ?>">
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">To Date</label>
                                <input type="date" name="to_date" class="form-control" value="<?php echo h($toDate); ?>">
                            </div>

                            <div class="col-md-2">
                                <button type="submit" class="btn btn-primary w-100">Search</button>
                            </div>

                            <div class="col-md-12">
                                <a href="suppliers-payments.php" class="btn btn-secondary">Reset</a>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">Supplier Payment List</h4>

                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Payment No</th>
                                        <th>Date</th>
                                        <th>Supplier</th>
                                        <th>Purchase</th>
                                        <th>Method</th>
                                        <th>Reference</th>
                                        <th>Amount</th>
                                        <th>Notes</th>
                                        <th>Created</th>
                                        <th style="min-width: 120px;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($payments)): ?>
                                        <?php foreach ($payments as $index => $payment): ?>
                                            <tr>
                                                <td><?php echo $index + 1; ?></td>
                                                <td><strong><?php echo h($payment['payment_no'] ?? ''); ?></strong></td>
                                                <td>
                                                    <?php
                                                    if (!empty($payment['payment_date'])) {
                                                        echo date('d-m-Y', strtotime($payment['payment_date']));
                                                    } else {
                                                        echo '-';
                                                    }
                                                    ?>
                                                </td>
                                                <td>
                                                    <?php echo h($payment['supplier_name'] ?? ''); ?>
                                                    <?php if (!empty($payment['supplier_code'])): ?>
                                                        <br><small class="text-muted"><?php echo h($payment['supplier_code']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo h($payment['purchase_no'] ?? '-'); ?></td>
                                                <td><?php echo h($payment['method_name'] ?? '-'); ?></td>
                                                <td><?php echo h($payment['reference_no'] ?? ''); ?></td>
                                                <td>₹ <?php echo money($payment['amount'] ?? 0); ?></td>
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
                                                <td>
                                                    <a href="suppliers-payments.php?delete=<?php echo (int)$payment['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this payment?');">
                                                        Delete
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="11" class="text-center text-muted">No supplier payments found.</td>
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