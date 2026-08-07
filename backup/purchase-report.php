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

$pageTitle = 'Purchase Report';

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
if (!in_array($roleName, ['admin', 'manager', 'stock'], true)) {
    die('Access denied.');
}

/* -------------------------------------------------------
   REQUIRED TABLES
------------------------------------------------------- */
if (!tableExists($conn, 'purchases')) {
    die('purchases table not found. Please import the SQL first.');
}

/* -------------------------------------------------------
   FILTERS
------------------------------------------------------- */
$fromDate = trim((string)($_GET['from_date'] ?? date('Y-m-01')));
$toDate = trim((string)($_GET['to_date'] ?? date('Y-m-d')));
$supplierId = (int)($_GET['supplier_id'] ?? 0);
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
   LOAD SUPPLIERS
------------------------------------------------------- */
$suppliers = [];
if (tableExists($conn, 'suppliers')) {
    $sql = "SELECT id, supplier_name, supplier_code, mobile
            FROM suppliers
            WHERE business_id = ?";
    if (hasColumn($conn, 'suppliers', 'is_active')) {
        $sql .= " AND is_active = 1";
    }
    $sql .= " ORDER BY supplier_name ASC";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('i', $businessId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && $row = $res->fetch_assoc()) {
            $suppliers[] = $row;
        }
        $stmt->close();
    }
}

/* -------------------------------------------------------
   BUILD WHERE
------------------------------------------------------- */
$where = " WHERE p.business_id = ? ";
$params = [$businessId];
$types = 'i';

if ($fromDate !== '') {
    $where .= " AND p.purchase_date >= ? ";
    $params[] = $fromDate;
    $types .= 's';
}

if ($toDate !== '') {
    $where .= " AND p.purchase_date <= ? ";
    $params[] = $toDate;
    $types .= 's';
}

if ($supplierId > 0 && hasColumn($conn, 'purchases', 'supplier_id')) {
    $where .= " AND p.supplier_id = ? ";
    $params[] = $supplierId;
    $types .= 'i';
}

if ($paymentStatus !== '' && hasColumn($conn, 'purchases', 'payment_status')) {
    $where .= " AND p.payment_status = ? ";
    $params[] = $paymentStatus;
    $types .= 's';
}

if ($search !== '') {
    $where .= " AND (
        p.purchase_no LIKE ?
        OR p.invoice_no LIKE ?
    ";
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $types .= 'ss';

    if (tableExists($conn, 'suppliers')) {
        $where .= " OR s.supplier_name LIKE ? OR s.mobile LIKE ? ";
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
        $types .= 'ss';

        if (hasColumn($conn, 'suppliers', 'supplier_code')) {
            $where .= " OR s.supplier_code LIKE ? ";
            $params[] = '%' . $search . '%';
            $types .= 's';
        }
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
        COALESCE(SUM(p.subtotal),0) AS subtotal,
        COALESCE(SUM(p.discount_amount),0) AS discount_amount,
        COALESCE(SUM(p.taxable_amount),0) AS taxable_amount,
        COALESCE(SUM(p.cgst_amount),0) AS cgst_amount,
        COALESCE(SUM(p.sgst_amount),0) AS sgst_amount,
        COALESCE(SUM(p.igst_amount),0) AS igst_amount,
        COALESCE(SUM(p.round_off),0) AS round_off,
        COALESCE(SUM(p.grand_total),0) AS grand_total,
        COALESCE(SUM(p.paid_amount),0) AS paid_amount,
        COALESCE(SUM(p.balance_amount),0) AS balance_amount
    FROM purchases p
    LEFT JOIN suppliers s ON s.id = p.supplier_id
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
   PURCHASE LIST
------------------------------------------------------- */
$purchases = [];
$sql = "
    SELECT
        p.*,
        s.supplier_name,
        s.supplier_code,
        s.mobile AS supplier_mobile
    FROM purchases p
    LEFT JOIN suppliers s ON s.id = p.supplier_id
    $where
    ORDER BY p.purchase_date DESC, p.id DESC
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
        $purchases[] = $row;
    }
    $stmt->close();
}

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
                        <h4 class="card-title mb-4">Purchase Report Filter</h4>

                        <form method="get" class="row g-2 align-items-end">
                            <div class="col-md-2">
                                <label class="form-label">From Date</label>
                                <input type="date" name="from_date" class="form-control" value="<?php echo h($fromDate); ?>">
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">To Date</label>
                                <input type="date" name="to_date" class="form-control" value="<?php echo h($toDate); ?>">
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Supplier</label>
                                <select name="supplier_id" class="form-select">
                                    <option value="0">All Suppliers</option>
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

                            <div class="col-md-3">
                                <label class="form-label">Search</label>
                                <input type="text" name="search" class="form-control" placeholder="Purchase no / invoice / supplier" value="<?php echo h($search); ?>">
                            </div>

                            <div class="col-md-12 mt-2">
                                <button type="submit" class="btn btn-primary">Show Report</button>
                                <a href="purchase-report.php" class="btn btn-secondary">Reset</a>
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
                                <p class="text-muted mb-0">Total Purchases</p>
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
                        <h4 class="card-title mb-4">Purchase Report</h4>

                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Purchase No</th>
                                        <th>Date</th>
                                        <th>Supplier</th>
                                        <th>Mobile</th>
                                        <th>Invoice No</th>
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
                                    <?php if (!empty($purchases)): ?>
                                        <?php foreach ($purchases as $index => $purchase): ?>
                                            <tr>
                                                <td><?php echo $index + 1; ?></td>
                                                <td>
                                                    <strong><?php echo h($purchase['purchase_no'] ?? ''); ?></strong>
                                                </td>
                                                <td>
                                                    <?php
                                                    if (!empty($purchase['purchase_date'])) {
                                                        echo date('d-m-Y', strtotime($purchase['purchase_date']));
                                                    } else {
                                                        echo '-';
                                                    }
                                                    ?>
                                                </td>
                                                <td>
                                                    <?php echo h($purchase['supplier_name'] ?? ''); ?>
                                                    <?php if (!empty($purchase['supplier_code'])): ?>
                                                        <br><small class="text-muted"><?php echo h($purchase['supplier_code']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo h($purchase['supplier_mobile'] ?? ''); ?></td>
                                                <td><?php echo h($purchase['invoice_no'] ?? ''); ?></td>
                                                <td>₹ <?php echo money($purchase['subtotal'] ?? 0); ?></td>
                                                <td>₹ <?php echo money($purchase['discount_amount'] ?? 0); ?></td>
                                                <td>₹ <?php echo money($purchase['taxable_amount'] ?? 0); ?></td>
                                                <td>₹ <?php echo money($purchase['cgst_amount'] ?? 0); ?></td>
                                                <td>₹ <?php echo money($purchase['sgst_amount'] ?? 0); ?></td>
                                                <td>₹ <?php echo money($purchase['igst_amount'] ?? 0); ?></td>
                                                <td>₹ <?php echo money($purchase['round_off'] ?? 0); ?></td>
                                                <td><strong>₹ <?php echo money($purchase['grand_total'] ?? 0); ?></strong></td>
                                                <td class="text-success">₹ <?php echo money($purchase['paid_amount'] ?? 0); ?></td>
                                                <td class="text-danger">₹ <?php echo money($purchase['balance_amount'] ?? 0); ?></td>
                                                <td>
                                                    <?php
                                                    $status = (string)($purchase['payment_status'] ?? 'Unpaid');
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
                                            <td colspan="17" class="text-center text-muted">No purchases found for selected filters.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>

                                <?php if (!empty($purchases)): ?>
                                    <tfoot>
                                        <tr>
                                            <th colspan="6" class="text-end">Totals</th>
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