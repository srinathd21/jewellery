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

function generateReceiptNo(mysqli $conn, int $businessId): string
{
    $prefix = 'RCT' . date('Ymd');
    $running = 1;

    $stmt = $conn->prepare("
        SELECT receipt_no
        FROM customer_payments
        WHERE business_id = ? AND receipt_no LIKE ?
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

        if ($row && !empty($row['receipt_no']) && preg_match('/(\d{4})$/', $row['receipt_no'], $m)) {
            $running = ((int)$m[1]) + 1;
        }
    }

    return $prefix . str_pad((string)$running, 4, '0', STR_PAD_LEFT);
}

$pageTitle = 'Collections';

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
if (!tableExists($conn, 'customer_payments') || !tableExists($conn, 'customers')) {
    die('Required tables not found. Please import the SQL first.');
}

/* -------------------------------------------------------
   FLASH
------------------------------------------------------- */
$success = '';
$error = '';

$msg = trim((string)($_GET['msg'] ?? ''));
if ($msg === 'created') {
    $success = 'Collection added successfully.';
} elseif ($msg === 'deleted') {
    $success = 'Collection deleted successfully.';
}

/* -------------------------------------------------------
   LOAD CUSTOMERS
------------------------------------------------------- */
$customers = [];
$stmt = $conn->prepare("
    SELECT id, customer_name, customer_code, mobile
    FROM customers
    WHERE business_id = ?
    " . (hasColumn($conn, 'customers', 'is_active') ? "AND is_active = 1" : "") . "
    ORDER BY customer_name ASC
");
if ($stmt) {
    $stmt->bind_param('i', $businessId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && $row = $res->fetch_assoc()) {
        $customers[] = $row;
    }
    $stmt->close();
}

/* -------------------------------------------------------
   LOAD SALES
------------------------------------------------------- */
$sales = [];
if (tableExists($conn, 'sales')) {
    $stmt = $conn->prepare("
        SELECT id, bill_no, bill_date, customer_id, grand_total, paid_amount, balance_amount, payment_status
        FROM sales
        WHERE business_id = ?
        " . (hasColumn($conn, 'sales', 'status') ? "AND status = 'Active'" : "") . "
        ORDER BY id DESC
    ");
    if ($stmt) {
        $stmt->bind_param('i', $businessId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && $row = $res->fetch_assoc()) {
            $sales[] = $row;
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
   DELETE COLLECTION
------------------------------------------------------- */
if (isset($_GET['delete']) && (int)$_GET['delete'] > 0) {
    $deleteId = (int)$_GET['delete'];

    $conn->begin_transaction();

    try {
        $paymentRow = null;
        $stmt = $conn->prepare("
            SELECT *
            FROM customer_payments
            WHERE id = ? AND business_id = ?
            LIMIT 1
        ");
        if (!$stmt) {
            throw new Exception('Failed to prepare collection fetch.');
        }

        $stmt->bind_param('ii', $deleteId, $businessId);
        $stmt->execute();
        $res = $stmt->get_result();
        $paymentRow = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!$paymentRow) {
            throw new Exception('Collection not found.');
        }

        $saleId = (int)($paymentRow['sale_id'] ?? 0);
        $amount = (float)($paymentRow['amount'] ?? 0);

        if ($saleId > 0 && tableExists($conn, 'sales')) {
            $stmt = $conn->prepare("
                SELECT grand_total, paid_amount
                FROM sales
                WHERE id = ? AND business_id = ?
                LIMIT 1
            ");
            if ($stmt) {
                $stmt->bind_param('ii', $saleId, $businessId);
                $stmt->execute();
                $res = $stmt->get_result();
                $saleRow = $res ? $res->fetch_assoc() : null;
                $stmt->close();

                if ($saleRow) {
                    $newPaid = (float)$saleRow['paid_amount'] - $amount;
                    if ($newPaid < 0) {
                        $newPaid = 0;
                    }

                    $grandTotal = (float)$saleRow['grand_total'];
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
                        UPDATE sales
                        SET paid_amount = ?, balance_amount = ?, payment_status = ?, updated_at = NOW()
                        WHERE id = ? AND business_id = ?
                        LIMIT 1
                    ");
                    if ($stmt) {
                        $stmt->bind_param('ddsii', $newPaid, $newBalance, $paymentStatus, $saleId, $businessId);
                        if (!$stmt->execute()) {
                            throw new Exception('Failed to update sale payment status.');
                        }
                        $stmt->close();
                    }
                }
            }
        }

        $stmt = $conn->prepare("DELETE FROM customer_payments WHERE id = ? AND business_id = ? LIMIT 1");
        if (!$stmt) {
            throw new Exception('Failed to prepare collection delete.');
        }

        $stmt->bind_param('ii', $deleteId, $businessId);
        if (!$stmt->execute()) {
            throw new Exception('Failed to delete collection.');
        }
        $stmt->close();

        addAuditLog(
            $conn,
            $businessId,
            $userId,
            'Collections',
            'Delete',
            $deleteId,
            'Deleted customer collection'
        );

        $conn->commit();
        header('Location: collections.php?msg=deleted');
        exit;
    } catch (Throwable $e) {
        $conn->rollback();
        $error = $e->getMessage();
    }
}

/* -------------------------------------------------------
   ADD COLLECTION
------------------------------------------------------- */
$receiptNo = generateReceiptNo($conn, $businessId);
$receiptDate = date('Y-m-d');
$customerId = 0;
$saleId = 0;
$paymentMethodId = 0;
$referenceNo = '';
$amount = '0.00';
$notes = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_collection'])) {
    $receiptNo = trim((string)($_POST['receipt_no'] ?? ''));
    $receiptDate = trim((string)($_POST['receipt_date'] ?? ''));
    $customerId = (int)($_POST['customer_id'] ?? 0);
    $saleId = (int)($_POST['sale_id'] ?? 0);
    $paymentMethodId = (int)($_POST['payment_method_id'] ?? 0);
    $referenceNo = trim((string)($_POST['reference_no'] ?? ''));
    $amount = trim((string)($_POST['amount'] ?? '0'));
    $notes = trim((string)($_POST['notes'] ?? ''));

    if ($receiptNo === '') {
        $receiptNo = generateReceiptNo($conn, $businessId);
    }

    if ($receiptDate === '') {
        $error = 'Receipt date is required.';
    } elseif ($customerId <= 0) {
        $error = 'Please select customer.';
    } elseif (!is_numeric($amount) || (float)$amount <= 0) {
        $error = 'Please enter valid amount.';
    } else {
        $amountF = (float)$amount;
        $saleIdValue = $saleId > 0 ? $saleId : null;
        $paymentMethodValue = $paymentMethodId > 0 ? $paymentMethodId : null;

        $conn->begin_transaction();

        try {
            if ($saleId > 0 && tableExists($conn, 'sales')) {
                $stmt = $conn->prepare("
                    SELECT grand_total, paid_amount, customer_id
                    FROM sales
                    WHERE id = ? AND business_id = ?
                    LIMIT 1
                ");
                if (!$stmt) {
                    throw new Exception('Failed to prepare sale validation.');
                }

                $stmt->bind_param('ii', $saleId, $businessId);
                $stmt->execute();
                $res = $stmt->get_result();
                $saleRow = $res ? $res->fetch_assoc() : null;
                $stmt->close();

                if (!$saleRow) {
                    throw new Exception('Sale not found.');
                }

                if ((int)$saleRow['customer_id'] !== $customerId) {
                    throw new Exception('Selected sale does not belong to selected customer.');
                }

                $grandTotal = (float)$saleRow['grand_total'];
                $currentPaid = (float)$saleRow['paid_amount'];
                $newPaid = $currentPaid + $amountF;
                if ($newPaid > $grandTotal) {
                    throw new Exception('Collection amount exceeds sale balance.');
                }
            }

            $stmt = $conn->prepare("
                INSERT INTO customer_payments
                (business_id, receipt_no, receipt_date, customer_id, sale_id, payment_method_id, reference_no, amount, notes, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            if (!$stmt) {
                throw new Exception('Failed to prepare collection insert.');
            }

            $stmt->bind_param(
                'issiiisdsi',
                $businessId,
                $receiptNo,
                $receiptDate,
                $customerId,
                $saleIdValue,
                $paymentMethodValue,
                $referenceNo,
                $amountF,
                $notes,
                $userId
            );

            if (!$stmt->execute()) {
                throw new Exception('Failed to save collection.');
            }

            $paymentInsertId = (int)$stmt->insert_id;
            $stmt->close();

            if ($saleId > 0 && tableExists($conn, 'sales')) {
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
                    UPDATE sales
                    SET paid_amount = ?, balance_amount = ?, payment_status = ?, updated_at = NOW()
                    WHERE id = ? AND business_id = ?
                    LIMIT 1
                ");
                if (!$stmt) {
                    throw new Exception('Failed to prepare sale update.');
                }

                $stmt->bind_param('ddsii', $newPaid, $newBalance, $paymentStatus, $saleId, $businessId);
                if (!$stmt->execute()) {
                    throw new Exception('Failed to update sale payment status.');
                }
                $stmt->close();
            }

            addAuditLog(
                $conn,
                $businessId,
                $userId,
                'Collections',
                'Create',
                $paymentInsertId,
                'Created customer collection ' . $receiptNo
            );

            $conn->commit();
            header('Location: collections.php?msg=created');
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
$filterCustomerId = (int)($_GET['filter_customer_id'] ?? 0);
$fromDate = trim((string)($_GET['from_date'] ?? ''));
$toDate = trim((string)($_GET['to_date'] ?? ''));

$where = " WHERE cp.business_id = ? ";
$params = [$businessId];
$types = 'i';

if ($search !== '') {
    $where .= " AND (
        cp.receipt_no LIKE ?
        OR cp.reference_no LIKE ?
        OR c.customer_name LIKE ?
        OR s.bill_no LIKE ?
    )";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'ssss';
}

if ($filterCustomerId > 0) {
    $where .= " AND cp.customer_id = ? ";
    $params[] = $filterCustomerId;
    $types .= 'i';
}

if ($fromDate !== '') {
    $where .= " AND cp.receipt_date >= ? ";
    $params[] = $fromDate;
    $types .= 's';
}

if ($toDate !== '') {
    $where .= " AND cp.receipt_date <= ? ";
    $params[] = $toDate;
    $types .= 's';
}

/* -------------------------------------------------------
   SUMMARY
------------------------------------------------------- */
$totalCollections = 0;
$totalCollectionAmount = 0.00;

$sql = "
    SELECT COUNT(*) AS cnt, COALESCE(SUM(cp.amount),0) AS total_amount
    FROM customer_payments cp
    LEFT JOIN customers c ON c.id = cp.customer_id
    LEFT JOIN sales s ON s.id = cp.sale_id
    LEFT JOIN payment_methods pm ON pm.id = cp.payment_method_id
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
    $totalCollections = (int)($row['cnt'] ?? 0);
    $totalCollectionAmount = (float)($row['total_amount'] ?? 0);
    $stmt->close();
}

/* -------------------------------------------------------
   LIST
------------------------------------------------------- */
$collections = [];
$sql = "
    SELECT
        cp.*,
        c.customer_name,
        c.customer_code,
        s.bill_no,
        pm.method_name
    FROM customer_payments cp
    LEFT JOIN customers c ON c.id = cp.customer_id
    LEFT JOIN sales s ON s.id = cp.sale_id
    LEFT JOIN payment_methods pm ON pm.id = cp.payment_method_id
    $where
    ORDER BY cp.id DESC
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
        $collections[] = $row;
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
                                <h3 class="text-primary mt-2"><?php echo $totalCollections; ?></h3>
                                <p class="text-muted mb-0">Total Collections</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-success mt-2">₹ <?php echo money($totalCollectionAmount); ?></h3>
                                <p class="text-muted mb-0">Total Collection Amount</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">Add Collection</h4>

                        <form method="post">
                            <input type="hidden" name="save_collection" value="1">

                            <div class="row">
                                <div class="col-md-2 mb-3">
                                    <label class="form-label">Receipt No</label>
                                    <input type="text" name="receipt_no" class="form-control" value="<?php echo h($receiptNo); ?>" required>
                                </div>

                                <div class="col-md-2 mb-3">
                                    <label class="form-label">Receipt Date</label>
                                    <input type="date" name="receipt_date" class="form-control" value="<?php echo h($receiptDate); ?>" required>
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Customer <span class="text-danger">*</span></label>
                                    <select name="customer_id" class="form-select" required>
                                        <option value="">Select Customer</option>
                                        <?php foreach ($customers as $customer): ?>
                                            <option value="<?php echo (int)$customer['id']; ?>" <?php echo $customerId === (int)$customer['id'] ? 'selected' : ''; ?>>
                                                <?php
                                                echo h($customer['customer_name']);
                                                if (!empty($customer['customer_code'])) {
                                                    echo ' (' . h($customer['customer_code']) . ')';
                                                }
                                                ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Sale Bill</label>
                                    <select name="sale_id" class="form-select">
                                        <option value="0">Select Sale</option>
                                        <?php foreach ($sales as $sale): ?>
                                            <option value="<?php echo (int)$sale['id']; ?>" <?php echo $saleId === (int)$sale['id'] ? 'selected' : ''; ?>>
                                                <?php echo h(($sale['bill_no'] ?? '') . ' - ' . date('d-m-Y', strtotime($sale['bill_date'])) . ' - Bal ₹' . money($sale['balance_amount'] ?? 0)); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-2 mb-3">
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

                                <div class="col-md-2 mb-3">
                                    <label class="form-label">Reference No</label>
                                    <input type="text" name="reference_no" class="form-control" value="<?php echo h($referenceNo); ?>">
                                </div>

                                <div class="col-md-2 mb-3">
                                    <label class="form-label">Amount <span class="text-danger">*</span></label>
                                    <input type="number" step="0.01" min="0" name="amount" class="form-control" value="<?php echo h($amount); ?>" required>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Notes</label>
                                    <input type="text" name="notes" class="form-control" value="<?php echo h($notes); ?>">
                                </div>

                                <div class="col-md-12">
                                    <button type="submit" class="btn btn-primary">Save Collection</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">Filter Collections</h4>

                        <form method="get" class="row g-2 align-items-end">
                            <div class="col-md-3">
                                <label class="form-label">Search</label>
                                <input type="text" name="search" class="form-control" placeholder="Receipt no, customer, bill..." value="<?php echo h($search); ?>">
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Customer</label>
                                <select name="filter_customer_id" class="form-select">
                                    <option value="0">All Customers</option>
                                    <?php foreach ($customers as $customer): ?>
                                        <option value="<?php echo (int)$customer['id']; ?>" <?php echo $filterCustomerId === (int)$customer['id'] ? 'selected' : ''; ?>>
                                            <?php echo h($customer['customer_name']); ?>
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
                                <a href="collections.php" class="btn btn-secondary">Reset</a>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">Collection List</h4>

                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Receipt No</th>
                                        <th>Date</th>
                                        <th>Customer</th>
                                        <th>Sale Bill</th>
                                        <th>Method</th>
                                        <th>Reference</th>
                                        <th>Amount</th>
                                        <th>Notes</th>
                                        <th>Created</th>
                                        <th style="min-width:120px;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($collections)): ?>
                                        <?php foreach ($collections as $index => $row): ?>
                                            <tr>
                                                <td><?php echo $index + 1; ?></td>
                                                <td><strong><?php echo h($row['receipt_no'] ?? ''); ?></strong></td>
                                                <td>
                                                    <?php
                                                    if (!empty($row['receipt_date'])) {
                                                        echo date('d-m-Y', strtotime($row['receipt_date']));
                                                    } else {
                                                        echo '-';
                                                    }
                                                    ?>
                                                </td>
                                                <td>
                                                    <?php echo h($row['customer_name'] ?? ''); ?>
                                                    <?php if (!empty($row['customer_code'])): ?>
                                                        <br><small class="text-muted"><?php echo h($row['customer_code']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo h($row['bill_no'] ?? '-'); ?></td>
                                                <td><?php echo h($row['method_name'] ?? '-'); ?></td>
                                                <td><?php echo h($row['reference_no'] ?? ''); ?></td>
                                                <td>₹ <?php echo money($row['amount'] ?? 0); ?></td>
                                                <td><?php echo h($row['notes'] ?? ''); ?></td>
                                                <td>
                                                    <?php
                                                    if (!empty($row['created_at'])) {
                                                        echo date('d-m-Y h:i A', strtotime($row['created_at']));
                                                    } else {
                                                        echo '-';
                                                    }
                                                    ?>
                                                </td>
                                                <td>
                                                    <a href="collections.php?delete=<?php echo (int)$row['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this collection?');">
                                                        Delete
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="11" class="text-center text-muted">No collections found.</td>
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