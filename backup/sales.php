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

if (!function_exists('hasColumn')) {
    function hasColumn(mysqli $conn, string $table, string $column): bool
    {
        $table = $conn->real_escape_string($table);
        $column = $conn->real_escape_string($column);
        $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
        return $res && $res->num_rows > 0;
    }
}

$pageTitle = 'Sales';

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
if (!tableExists($conn, 'sales')) {
    die('Required table `sales` not found. Please import the SQL first.');
}

/* -------------------------------------------------------
   COLUMN CHECKS
------------------------------------------------------- */
$salesHasBusinessId      = hasColumn($conn, 'sales', 'business_id');
$salesHasBillNo          = hasColumn($conn, 'sales', 'bill_no');
$salesHasBillDate        = hasColumn($conn, 'sales', 'bill_date');
$salesHasBillTime        = hasColumn($conn, 'sales', 'bill_time');
$salesHasCustomerId      = hasColumn($conn, 'sales', 'customer_id');
$salesHasCustomerName    = hasColumn($conn, 'sales', 'customer_name');
$salesHasCustomerMobile  = hasColumn($conn, 'sales', 'customer_mobile');
$salesHasBillType        = hasColumn($conn, 'sales', 'bill_type');
$salesHasPaymentMethodId = hasColumn($conn, 'sales', 'payment_method_id');
$salesHasPaymentRef      = hasColumn($conn, 'sales', 'payment_reference');
$salesHasSubtotal        = hasColumn($conn, 'sales', 'subtotal');
$salesHasDiscount        = hasColumn($conn, 'sales', 'discount_amount');
$salesHasTaxable         = hasColumn($conn, 'sales', 'taxable_amount');
$salesHasCgst           = hasColumn($conn, 'sales', 'cgst_amount');
$salesHasSgst           = hasColumn($conn, 'sales', 'sgst_amount');
$salesHasIgst           = hasColumn($conn, 'sales', 'igst_amount');
$salesHasRoundOff       = hasColumn($conn, 'sales', 'round_off');
$salesHasGrandTotal     = hasColumn($conn, 'sales', 'grand_total');
$salesHasPaidAmount     = hasColumn($conn, 'sales', 'paid_amount');
$salesHasBalanceAmount  = hasColumn($conn, 'sales', 'balance_amount');
$salesHasPaymentStatus  = hasColumn($conn, 'sales', 'payment_status');
$salesHasNotes          = hasColumn($conn, 'sales', 'notes');
$salesHasStatus         = hasColumn($conn, 'sales', 'status');
$salesHasCreatedBy      = hasColumn($conn, 'sales', 'created_by');
$salesHasCancelledBy    = hasColumn($conn, 'sales', 'cancelled_by');
$salesHasCancelledAt    = hasColumn($conn, 'sales', 'cancelled_at');
$salesHasCancelReason   = hasColumn($conn, 'sales', 'cancel_reason');
$salesHasCreatedAt      = hasColumn($conn, 'sales', 'created_at');

$customersTableExists = tableExists($conn, 'customers');
$paymentMethodsExists = tableExists($conn, 'payment_methods');

/* -------------------------------------------------------
   FLASH MESSAGES
------------------------------------------------------- */
$success = '';
$error = '';

$msg = trim((string)($_GET['msg'] ?? ''));
if ($msg === 'deleted') {
    $success = 'Sale deleted successfully.';
} elseif ($msg === 'cancelled') {
    $success = 'Sale cancelled successfully.';
}

/* -------------------------------------------------------
   DELETE SALE
------------------------------------------------------- */
if (isset($_GET['delete']) && (int)$_GET['delete'] > 0) {
    $deleteId = (int)$_GET['delete'];

    if ($salesHasBusinessId) {
        $stmt = $conn->prepare("DELETE FROM sales WHERE id = ? AND business_id = ? LIMIT 1");
        $stmt->bind_param('ii', $deleteId, $businessId);
    } else {
        $stmt = $conn->prepare("DELETE FROM sales WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $deleteId);
    }

    if ($stmt && $stmt->execute()) {
        if (function_exists('addAuditLog')) {
            addAuditLog(
                $conn,
                $businessId,
                $userId,
                'Sales',
                'Delete',
                $deleteId,
                'Deleted sale'
            );
        }
    }

    if ($stmt) {
        $stmt->close();
    }

    header('Location: sales.php?msg=deleted');
    exit;
}

/* -------------------------------------------------------
   CANCEL SALE
------------------------------------------------------- */
if ($salesHasStatus && isset($_GET['cancel']) && (int)$_GET['cancel'] > 0) {
    $cancelId = (int)$_GET['cancel'];

    if ($salesHasBusinessId && $salesHasCancelledBy && $salesHasCancelledAt && $salesHasCancelReason) {
        $reason = 'Cancelled from sales list';
        $stmt = $conn->prepare("
            UPDATE sales 
            SET status = 'Cancelled',
                cancelled_by = ?,
                cancelled_at = NOW(),
                cancel_reason = ?
            WHERE id = ? AND business_id = ? AND status <> 'Cancelled'
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param('isii', $userId, $reason, $cancelId, $businessId);
            $stmt->execute();
            $stmt->close();
        }
    } elseif ($salesHasBusinessId) {
        $stmt = $conn->prepare("
            UPDATE sales 
            SET status = 'Cancelled'
            WHERE id = ? AND business_id = ? AND status <> 'Cancelled'
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param('ii', $cancelId, $businessId);
            $stmt->execute();
            $stmt->close();
        }
    } else {
        $stmt = $conn->prepare("
            UPDATE sales 
            SET status = 'Cancelled'
            WHERE id = ? AND status <> 'Cancelled'
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param('i', $cancelId);
            $stmt->execute();
            $stmt->close();
        }
    }

    if (function_exists('addAuditLog')) {
        addAuditLog(
            $conn,
            $businessId,
            $userId,
            'Sales',
            'Cancel',
            $cancelId,
            'Cancelled sale'
        );
    }

    header('Location: sales.php?msg=cancelled');
    exit;
}

/* -------------------------------------------------------
   COUNTS
------------------------------------------------------- */
$totalSales = 0;
$activeSales = 0;
$cancelledSales = 0;
$totalAmount = 0;

$sql = "SELECT COUNT(*) AS cnt, " . ($salesHasGrandTotal ? "SUM(IFNULL(grand_total,0))" : "0") . " AS total_amt FROM sales WHERE 1=1";
if ($salesHasBusinessId) {
    $sql .= " AND business_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $businessId);
} else {
    $stmt = $conn->prepare($sql);
}
if ($stmt) {
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $totalSales = (int)($row['cnt'] ?? 0);
    $totalAmount = (float)($row['total_amt'] ?? 0);
    $stmt->close();
}

if ($salesHasStatus) {
    $sql = "SELECT COUNT(*) AS cnt FROM sales WHERE status = 'Active'";
    if ($salesHasBusinessId) {
        $sql .= " AND business_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $businessId);
    } else {
        $stmt = $conn->prepare($sql);
    }
    if ($stmt) {
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $activeSales = (int)($row['cnt'] ?? 0);
        $stmt->close();
    }

    $sql = "SELECT COUNT(*) AS cnt FROM sales WHERE status = 'Cancelled'";
    if ($salesHasBusinessId) {
        $sql .= " AND business_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $businessId);
    } else {
        $stmt = $conn->prepare($sql);
    }
    if ($stmt) {
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $cancelledSales = (int)($row['cnt'] ?? 0);
        $stmt->close();
    }
}

/* -------------------------------------------------------
   FILTERS
------------------------------------------------------- */
$search = trim((string)($_GET['search'] ?? ''));
$billTypeFilter = trim((string)($_GET['bill_type'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? 'all'));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));

$where = " WHERE 1=1 ";
$params = [];
$types = '';

if ($salesHasBusinessId) {
    $where .= " AND s.business_id = ? ";
    $params[] = $businessId;
    $types .= 'i';
}

if ($search !== '') {
    $where .= " AND (1=0";
    if ($salesHasBillNo) {
        $where .= " OR s.bill_no LIKE ?";
        $params[] = '%' . $search . '%';
        $types .= 's';
    }
    if ($salesHasCustomerName) {
        $where .= " OR s.customer_name LIKE ?";
        $params[] = '%' . $search . '%';
        $types .= 's';
    }
    if ($salesHasCustomerMobile) {
        $where .= " OR s.customer_mobile LIKE ?";
        $params[] = '%' . $search . '%';
        $types .= 's';
    }
    $where .= ")";
}

if ($salesHasBillType && $billTypeFilter !== '') {
    $where .= " AND s.bill_type = ? ";
    $params[] = $billTypeFilter;
    $types .= 's';
}

if ($salesHasStatus) {
    if ($statusFilter === 'active') {
        $where .= " AND s.status = 'Active' ";
    } elseif ($statusFilter === 'cancelled') {
        $where .= " AND s.status = 'Cancelled' ";
    }
}

if ($salesHasBillDate && $dateFrom !== '') {
    $where .= " AND s.bill_date >= ? ";
    $params[] = $dateFrom;
    $types .= 's';
}

if ($salesHasBillDate && $dateTo !== '') {
    $where .= " AND s.bill_date <= ? ";
    $params[] = $dateTo;
    $types .= 's';
}

/* -------------------------------------------------------
   SALES LIST
------------------------------------------------------- */
$sql = "SELECT 
            s.id";

if ($salesHasBillNo) {
    $sql .= ", s.bill_no";
}
if ($salesHasBillDate) {
    $sql .= ", s.bill_date";
}
if ($salesHasBillTime) {
    $sql .= ", s.bill_time";
}
if ($salesHasCustomerName) {
    $sql .= ", s.customer_name";
}
if ($salesHasCustomerMobile) {
    $sql .= ", s.customer_mobile";
}
if ($salesHasBillType) {
    $sql .= ", s.bill_type";
}
if ($salesHasGrandTotal) {
    $sql .= ", s.grand_total";
}
if ($salesHasPaidAmount) {
    $sql .= ", s.paid_amount";
}
if ($salesHasBalanceAmount) {
    $sql .= ", s.balance_amount";
}
if ($salesHasPaymentStatus) {
    $sql .= ", s.payment_status";
}
if ($salesHasStatus) {
    $sql .= ", s.status";
}
if ($salesHasCreatedAt) {
    $sql .= ", s.created_at";
}
if ($salesHasPaymentRef) {
    $sql .= ", s.payment_reference";
}

$sql .= " FROM sales s
          $where
          ORDER BY s.id DESC";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die('Failed to prepare sales query.');
}

if (!empty($params)) {
    $bindValues = [];
    $bindValues[] = $types;
    for ($i = 0; $i < count($params); $i++) {
        $bindValues[] = &$params[$i];
    }
    call_user_func_array([$stmt, 'bind_param'], $bindValues);
}

$stmt->execute();
$res = $stmt->get_result();
$sales = [];
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $sales[] = $row;
    }
}
$stmt->close();
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
                    <div class="col-md-3 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-primary mt-2"><?php echo $totalSales; ?></h3>
                                <p class="text-muted mb-0">Total Sales</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-success mt-2"><?php echo $activeSales; ?></h3>
                                <p class="text-muted mb-0">Active Sales</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-danger mt-2"><?php echo $cancelledSales; ?></h3>
                                <p class="text-muted mb-0">Cancelled Sales</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-dark mt-2">₹<?php echo number_format($totalAmount, 2); ?></h3>
                                <p class="text-muted mb-0">Total Amount</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <form method="get" class="row g-2 align-items-end">
                            <div class="col-md-3">
                                <label class="form-label">Search</label>
                                <input type="text" name="search" class="form-control" placeholder="Bill no, customer, mobile..." value="<?php echo h($search); ?>">
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Bill Type</label>
                                <select name="bill_type" class="form-select">
                                    <option value="">All Types</option>
                                    <option value="Retail" <?php echo $billTypeFilter === 'Retail' ? 'selected' : ''; ?>>Retail</option>
                                    <option value="GST" <?php echo $billTypeFilter === 'GST' ? 'selected' : ''; ?>>GST</option>
                                    <option value="Estimate" <?php echo $billTypeFilter === 'Estimate' ? 'selected' : ''; ?>>Estimate</option>
                                    <option value="Exchange" <?php echo $billTypeFilter === 'Exchange' ? 'selected' : ''; ?>>Exchange</option>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select">
                                    <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All Status</option>
                                    <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="cancelled" <?php echo $statusFilter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">From Date</label>
                                <input type="date" name="date_from" class="form-control" value="<?php echo h($dateFrom); ?>">
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">To Date</label>
                                <input type="date" name="date_to" class="form-control" value="<?php echo h($dateTo); ?>">
                            </div>

                            <div class="col-md-1">
                                <button type="submit" class="btn btn-primary w-100">Go</button>
                            </div>

                            <div class="col-md-12">
                                <a href="sales.php" class="btn btn-secondary">Reset</a>
                                <a href="billing.php" class="btn btn-primary">Add Sale</a>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Bill No</th>
                                        <th>Date & Time</th>
                                        <th>Customer</th>
                                        <th>Bill Type</th>
                                        <th>Grand Total</th>
                                        <th>Paid</th>
                                        <th>Balance</th>
                                        <th>Payment Status</th>
                                        <th>Sale Status</th>
                                        <th>Created</th>
                                        <th style="min-width:230px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($sales)): ?>
                                        <?php foreach ($sales as $index => $sale): ?>
                                            <tr>
                                                <td><?php echo $index + 1; ?></td>

                                                <td>
                                                    <strong><?php echo h($sale['bill_no'] ?? ('SALE-' . $sale['id'])); ?></strong>
                                                    <?php if (!empty($sale['payment_reference'])): ?>
                                                        <br><small class="text-muted"><?php echo h($sale['payment_reference']); ?></small>
                                                    <?php endif; ?>
                                                </td>

                                                <td>
                                                    <?php
                                                    $dateText = !empty($sale['bill_date']) ? date('d-m-Y', strtotime($sale['bill_date'])) : '-';
                                                    $timeText = !empty($sale['bill_time']) ? date('h:i A', strtotime($sale['bill_time'])) : '';
                                                    echo h(trim($dateText . ' ' . $timeText));
                                                    ?>
                                                </td>

                                                <td>
                                                    <?php echo h($sale['customer_name'] ?? 'Walk-in Customer'); ?>
                                                    <?php if (!empty($sale['customer_mobile'])): ?>
                                                        <br><small class="text-muted"><?php echo h($sale['customer_mobile']); ?></small>
                                                    <?php endif; ?>
                                                </td>

                                                <td><?php echo h($sale['bill_type'] ?? 'Retail'); ?></td>

                                                <td>₹<?php echo number_format((float)($sale['grand_total'] ?? 0), 2); ?></td>
                                                <td>₹<?php echo number_format((float)($sale['paid_amount'] ?? 0), 2); ?></td>
                                                <td>₹<?php echo number_format((float)($sale['balance_amount'] ?? 0), 2); ?></td>

                                                <td>
                                                    <?php
                                                    $paymentStatus = (string)($sale['payment_status'] ?? 'Paid');
                                                    if ($paymentStatus === 'Paid') {
                                                        echo '<span class="badge bg-success">Paid</span>';
                                                    } elseif ($paymentStatus === 'Partial') {
                                                        echo '<span class="badge bg-warning text-dark">Partial</span>';
                                                    } else {
                                                        echo '<span class="badge bg-danger">Unpaid</span>';
                                                    }
                                                    ?>
                                                </td>

                                                <td>
                                                    <?php
                                                    $saleStatus = (string)($sale['status'] ?? 'Active');
                                                    if ($saleStatus === 'Cancelled') {
                                                        echo '<span class="badge bg-danger">Cancelled</span>';
                                                    } else {
                                                        echo '<span class="badge bg-success">Active</span>';
                                                    }
                                                    ?>
                                                </td>

                                                <td>
                                                    <?php
                                                    if (!empty($sale['created_at'])) {
                                                        echo date('d-m-Y h:i A', strtotime($sale['created_at']));
                                                    } else {
                                                        echo '-';
                                                    }
                                                    ?>
                                                </td>

                                                <td>
                                                    <a href="sale-view.php?id=<?php echo (int)$sale['id']; ?>" class="btn btn-sm btn-info mb-1">View</a>
                                                    <a href="sale-edit.php?id=<?php echo (int)$sale['id']; ?>" class="btn btn-sm btn-primary mb-1">Edit</a>

                                                    <?php if (($sale['status'] ?? 'Active') !== 'Cancelled'): ?>
                                                        <a href="sales.php?cancel=<?php echo (int)$sale['id']; ?>" class="btn btn-sm btn-warning mb-1" onclick="return confirm('Are you sure you want to cancel this sale?');">Cancel</a>
                                                    <?php endif; ?>

                                                    <a href="sales.php?delete=<?php echo (int)$sale['id']; ?>" class="btn btn-sm btn-danger mb-1" onclick="return confirm('Are you sure you want to delete this sale?');">Delete</a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="12" class="text-center text-muted">No sales found.</td>
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