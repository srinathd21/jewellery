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

$pageTitle = 'Sales Report';

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
if (!tableExists($conn, 'sales')) {
    die('sales table not found. Please import the SQL first.');
}

/* -------------------------------------------------------
   FILTERS
------------------------------------------------------- */
$fromDate = trim((string)($_GET['from_date'] ?? date('Y-m-01')));
$toDate = trim((string)($_GET['to_date'] ?? date('Y-m-d')));
$customerId = (int)($_GET['customer_id'] ?? 0);
$billType = trim((string)($_GET['bill_type'] ?? ''));
$paymentStatus = trim((string)($_GET['payment_status'] ?? ''));
$search = trim((string)($_GET['search'] ?? ''));

if ($fromDate === '') {
    $fromDate = date('Y-m-01');
}
if ($toDate === '') {
    $toDate = date('Y-m-d');
}

if ($fromDate > $toDate) {
    $tmp = $fromDate;
    $fromDate = $toDate;
    $toDate = $tmp;
}

/* -------------------------------------------------------
   LOAD CUSTOMERS
------------------------------------------------------- */
$customers = [];
if (tableExists($conn, 'customers')) {
    $sql = "SELECT id, customer_name, customer_code, mobile
            FROM customers
            WHERE business_id = ?";
    if (hasColumn($conn, 'customers', 'is_active')) {
        $sql .= " AND is_active = 1";
    }
    $sql .= " ORDER BY customer_name ASC";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('i', $businessId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && $row = $res->fetch_assoc()) {
            $customers[] = $row;
        }
        $stmt->close();
    }
}

/* -------------------------------------------------------
   BUILD WHERE
------------------------------------------------------- */
$where = " WHERE s.business_id = ? ";
$params = [$businessId];
$types = 'i';

if (hasColumn($conn, 'sales', 'status')) {
    $where .= " AND s.status = 'Active' ";
}

if ($fromDate !== '') {
    $where .= " AND s.bill_date >= ? ";
    $params[] = $fromDate;
    $types .= 's';
}

if ($toDate !== '') {
    $where .= " AND s.bill_date <= ? ";
    $params[] = $toDate;
    $types .= 's';
}

if ($customerId > 0 && hasColumn($conn, 'sales', 'customer_id')) {
    $where .= " AND s.customer_id = ? ";
    $params[] = $customerId;
    $types .= 'i';
}

if ($billType !== '' && hasColumn($conn, 'sales', 'bill_type')) {
    $where .= " AND s.bill_type = ? ";
    $params[] = $billType;
    $types .= 's';
}

if ($paymentStatus !== '' && hasColumn($conn, 'sales', 'payment_status')) {
    $where .= " AND s.payment_status = ? ";
    $params[] = $paymentStatus;
    $types .= 's';
}

if ($search !== '') {
    $where .= " AND (
        s.bill_no LIKE ?
        OR s.customer_name LIKE ?
        OR s.customer_mobile LIKE ?
    ";
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $types .= 'sss';

    if (hasColumn($conn, 'customers', 'customer_name')) {
        $where .= " OR c.customer_name LIKE ? ";
        $params[] = '%' . $search . '%';
        $types .= 's';
    }

    $where .= " ) ";
}

/* -------------------------------------------------------
   SUMMARY
------------------------------------------------------- */
$summary = [
    'total_bills' => 0,
    'subtotal' => 0,
    'discount_amount' => 0,
    'taxable_amount' => 0,
    'cgst_amount' => 0,
    'sgst_amount' => 0,
    'igst_amount' => 0,
    'round_off' => 0,
    'grand_total' => 0,
    'paid_amount' => 0,
    'balance_amount' => 0
];

$sql = "
    SELECT
        COUNT(*) AS total_bills,
        COALESCE(SUM(s.subtotal),0) AS subtotal,
        COALESCE(SUM(s.discount_amount),0) AS discount_amount,
        COALESCE(SUM(s.taxable_amount),0) AS taxable_amount,
        COALESCE(SUM(s.cgst_amount),0) AS cgst_amount,
        COALESCE(SUM(s.sgst_amount),0) AS sgst_amount,
        COALESCE(SUM(s.igst_amount),0) AS igst_amount,
        COALESCE(SUM(s.round_off),0) AS round_off,
        COALESCE(SUM(s.grand_total),0) AS grand_total,
        COALESCE(SUM(s.paid_amount),0) AS paid_amount,
        COALESCE(SUM(s.balance_amount),0) AS balance_amount
    FROM sales s
    LEFT JOIN customers c ON c.id = s.customer_id
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
    if ($row) {
        $summary = $row;
    }
    $stmt->close();
}

/* -------------------------------------------------------
   SALES LIST
------------------------------------------------------- */
$sales = [];
$sql = "
    SELECT
        s.*,
        c.customer_name AS master_customer_name,
        pm.method_name
    FROM sales s
    LEFT JOIN customers c ON c.id = s.customer_id
    LEFT JOIN payment_methods pm ON pm.id = s.payment_method_id
    $where
    ORDER BY s.bill_date DESC, s.id DESC
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
        $sales[] = $row;
    }
    $stmt->close();
}

/* -------------------------------------------------------
   BILL TYPES
------------------------------------------------------- */
$billTypes = ['Retail', 'GST', 'Estimate', 'Exchange'];
$paymentStatuses = ['Paid', 'Partial', 'Unpaid'];
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

                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">Sales Report Filter</h4>

                        <form method="get" class="row g-2 align-items-end">
                            <div class="col-md-2">
                                <label class="form-label">From Date</label>
                                <input type="date" name="from_date" class="form-control" value="<?php echo h($fromDate); ?>">
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">To Date</label>
                                <input type="date" name="to_date" class="form-control" value="<?php echo h($toDate); ?>">
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Customer</label>
                                <select name="customer_id" class="form-select">
                                    <option value="0">All Customers</option>
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

                            <div class="col-md-2">
                                <label class="form-label">Bill Type</label>
                                <select name="bill_type" class="form-select">
                                    <option value="">All Types</option>
                                    <?php foreach ($billTypes as $type): ?>
                                        <option value="<?php echo h($type); ?>" <?php echo $billType === $type ? 'selected' : ''; ?>>
                                            <?php echo h($type); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Payment Status</label>
                                <select name="payment_status" class="form-select">
                                    <option value="">All Status</option>
                                    <?php foreach ($paymentStatuses as $status): ?>
                                        <option value="<?php echo h($status); ?>" <?php echo $paymentStatus === $status ? 'selected' : ''; ?>>
                                            <?php echo h($status); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Search</label>
                                <input type="text" name="search" class="form-control" placeholder="Bill no / customer / mobile" value="<?php echo h($search); ?>">
                            </div>

                            <div class="col-md-12 mt-2">
                                <button type="submit" class="btn btn-primary">Show Report</button>
                                <a href="sales-report.php" class="btn btn-secondary">Reset</a>
                                <button type="button" onclick="window.print()" class="btn btn-dark">Print</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h4 class="text-primary"><?php echo (int)$summary['total_bills']; ?></h4>
                                <p class="text-muted mb-0">Total Bills</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h4 class="text-success">₹ <?php echo money($summary['grand_total']); ?></h4>
                                <p class="text-muted mb-0">Grand Total</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h4 class="text-info">₹ <?php echo money($summary['paid_amount']); ?></h4>
                                <p class="text-muted mb-0">Paid Amount</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h4 class="text-danger">₹ <?php echo money($summary['balance_amount']); ?></h4>
                                <p class="text-muted mb-0">Balance Amount</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-2">
                        <div class="card text-center">
                            <div class="card-body">
                                <h6>₹ <?php echo money($summary['subtotal']); ?></h6>
                                <p class="text-muted mb-0">Subtotal</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-2">
                        <div class="card text-center">
                            <div class="card-body">
                                <h6>₹ <?php echo money($summary['discount_amount']); ?></h6>
                                <p class="text-muted mb-0">Discount</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-2">
                        <div class="card text-center">
                            <div class="card-body">
                                <h6>₹ <?php echo money($summary['taxable_amount']); ?></h6>
                                <p class="text-muted mb-0">Taxable</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-2">
                        <div class="card text-center">
                            <div class="card-body">
                                <h6>₹ <?php echo money($summary['cgst_amount'] + $summary['sgst_amount'] + $summary['igst_amount']); ?></h6>
                                <p class="text-muted mb-0">Total GST</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-2">
                        <div class="card text-center">
                            <div class="card-body">
                                <h6>₹ <?php echo money($summary['cgst_amount']); ?></h6>
                                <p class="text-muted mb-0">CGST</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-2">
                        <div class="card text-center">
                            <div class="card-body">
                                <h6>₹ <?php echo money($summary['sgst_amount'] + $summary['igst_amount']); ?></h6>
                                <p class="text-muted mb-0">SGST/IGST</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">Sales Report</h4>

                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Bill No</th>
                                        <th>Date</th>
                                        <th>Customer</th>
                                        <th>Mobile</th>
                                        <th>Bill Type</th>
                                        <th>Method</th>
                                        <th>Subtotal</th>
                                        <th>Discount</th>
                                        <th>Taxable</th>
                                        <th>CGST</th>
                                        <th>SGST</th>
                                        <th>IGST</th>
                                        <th>Round Off</th>
                                        <th>Grand Total</th>
                                        <th>Paid</th>
                                        <th>Balance</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($sales)): ?>
                                        <?php foreach ($sales as $index => $sale): ?>
                                            <tr>
                                                <td><?php echo $index + 1; ?></td>
                                                <td>
                                                    <strong><?php echo h($sale['bill_no'] ?? ''); ?></strong>
                                                </td>
                                                <td>
                                                    <?php
                                                    if (!empty($sale['bill_date'])) {
                                                        echo date('d-m-Y', strtotime($sale['bill_date']));
                                                    } else {
                                                        echo '-';
                                                    }
                                                    ?>
                                                    <?php if (!empty($sale['bill_time'])): ?>
                                                        <br><small class="text-muted"><?php echo h(date('h:i A', strtotime($sale['bill_time']))); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php
                                                    echo h(
                                                        $sale['master_customer_name']
                                                        ?? $sale['customer_name']
                                                        ?? 'Walk-in Customer'
                                                    );
                                                    ?>
                                                </td>
                                                <td><?php echo h($sale['customer_mobile'] ?? ''); ?></td>
                                                <td><?php echo h($sale['bill_type'] ?? ''); ?></td>
                                                <td><?php echo h($sale['method_name'] ?? '-'); ?></td>
                                                <td>₹ <?php echo money($sale['subtotal'] ?? 0); ?></td>
                                                <td>₹ <?php echo money($sale['discount_amount'] ?? 0); ?></td>
                                                <td>₹ <?php echo money($sale['taxable_amount'] ?? 0); ?></td>
                                                <td>₹ <?php echo money($sale['cgst_amount'] ?? 0); ?></td>
                                                <td>₹ <?php echo money($sale['sgst_amount'] ?? 0); ?></td>
                                                <td>₹ <?php echo money($sale['igst_amount'] ?? 0); ?></td>
                                                <td>₹ <?php echo money($sale['round_off'] ?? 0); ?></td>
                                                <td><strong>₹ <?php echo money($sale['grand_total'] ?? 0); ?></strong></td>
                                                <td class="text-success">₹ <?php echo money($sale['paid_amount'] ?? 0); ?></td>
                                                <td class="text-danger">₹ <?php echo money($sale['balance_amount'] ?? 0); ?></td>
                                                <td>
                                                    <?php
                                                    $status = (string)($sale['payment_status'] ?? 'Unpaid');
                                                    $badge = 'secondary';

                                                    if ($status === 'Paid') {
                                                        $badge = 'success';
                                                    } elseif ($status === 'Partial') {
                                                        $badge = 'warning';
                                                    } elseif ($status === 'Unpaid') {
                                                        $badge = 'danger';
                                                    }
                                                    ?>
                                                    <span class="badge bg-<?php echo $badge; ?>">
                                                        <?php echo h($status); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="18" class="text-center text-muted">No sales found for selected filters.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>

                                <?php if (!empty($sales)): ?>
                                    <tfoot>
                                        <tr>
                                            <th colspan="7" class="text-end">Totals</th>
                                            <th>₹ <?php echo money($summary['subtotal']); ?></th>
                                            <th>₹ <?php echo money($summary['discount_amount']); ?></th>
                                            <th>₹ <?php echo money($summary['taxable_amount']); ?></th>
                                            <th>₹ <?php echo money($summary['cgst_amount']); ?></th>
                                            <th>₹ <?php echo money($summary['sgst_amount']); ?></th>
                                            <th>₹ <?php echo money($summary['igst_amount']); ?></th>
                                            <th>₹ <?php echo money($summary['round_off']); ?></th>
                                            <th>₹ <?php echo money($summary['grand_total']); ?></th>
                                            <th>₹ <?php echo money($summary['paid_amount']); ?></th>
                                            <th>₹ <?php echo money($summary['balance_amount']); ?></th>
                                            <th>-</th>
                                        </tr>
                                    </tfoot>
                                <?php endif; ?>
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