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

$pageTitle = 'Customer Ledger';

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
if (!tableExists($conn, 'customers') || !tableExists($conn, 'sales') || !tableExists($conn, 'customer_payments')) {
    die('Required tables not found. Please import the SQL first.');
}

/* -------------------------------------------------------
   LOAD CUSTOMERS
------------------------------------------------------- */
$customers = [];
$sql = "SELECT id, customer_name, customer_code, opening_balance, balance_type
        FROM customers
        WHERE business_id = ?";
if (hasColumn($conn, 'customers', 'is_active')) {
    $sql .= " AND is_active = 1";
}
$sql .= " ORDER BY customer_name ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $businessId);
$stmt->execute();
$res = $stmt->get_result();
while ($res && $row = $res->fetch_assoc()) {
    $customers[] = $row;
}
$stmt->close();

/* -------------------------------------------------------
   FILTERS
------------------------------------------------------- */
$customerId = (int)($_GET['customer_id'] ?? 0);
$fromDate = trim((string)($_GET['from_date'] ?? ''));
$toDate = trim((string)($_GET['to_date'] ?? ''));
$search = trim((string)($_GET['search'] ?? ''));

$selectedCustomer = null;
$openingBalance = 0.00;
$openingBalanceType = 'Dr';

if ($customerId > 0) {
    $stmt = $conn->prepare("
        SELECT *
        FROM customers
        WHERE id = ? AND business_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('ii', $customerId, $businessId);
    $stmt->execute();
    $res = $stmt->get_result();
    $selectedCustomer = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if ($selectedCustomer) {
        $openingBalance = (float)($selectedCustomer['opening_balance'] ?? 0);
        $openingBalanceType = (string)($selectedCustomer['balance_type'] ?? 'Dr');
    }
}

/* -------------------------------------------------------
   LEDGER DATA
------------------------------------------------------- */
$ledgerRows = [];
$totalSales = 0.00;
$totalReceipts = 0.00;
$runningBalance = 0.00;

if ($customerId > 0 && $selectedCustomer) {
    /* Opening balance logic:
       Dr => customer receivable => debit side
       Cr => advance from customer => credit side
    */
    if ($openingBalanceType === 'Dr') {
        $runningBalance = $openingBalance;
    } else {
        $runningBalance = -$openingBalance;
    }

    $ledgerRows[] = [
        'entry_date' => '',
        'type' => 'Opening Balance',
        'ref_no' => '',
        'description' => 'Opening balance',
        'debit' => $openingBalanceType === 'Dr' ? $openingBalance : 0.00,
        'credit' => $openingBalanceType === 'Cr' ? $openingBalance : 0.00,
        'sort_date' => '0000-00-00 00:00:00'
    ];

    /* SALES = DEBIT */
    $sql = "
        SELECT
            s.id,
            s.bill_date AS entry_date,
            s.bill_no AS ref_no,
            s.grand_total AS amount,
            s.notes,
            CONCAT('Sale', IF(s.bill_type IS NOT NULL AND s.bill_type != '', CONCAT(' / ', s.bill_type), '')) AS description
        FROM sales s
        WHERE s.business_id = ?
          AND s.customer_id = ?
    ";
    $params = [$businessId, $customerId];
    $types = 'ii';

    if ($fromDate !== '') {
        $sql .= " AND s.bill_date >= ?";
        $params[] = $fromDate;
        $types .= 's';
    }
    if ($toDate !== '') {
        $sql .= " AND s.bill_date <= ?";
        $params[] = $toDate;
        $types .= 's';
    }
    if (hasColumn($conn, 'sales', 'status')) {
        $sql .= " AND s.status = 'Active'";
    }
    if ($search !== '') {
        $sql .= " AND (s.bill_no LIKE ? OR s.notes LIKE ? OR s.bill_type LIKE ?)";
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $types .= 'sss';
    }

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
            $amount = (float)($row['amount'] ?? 0);
            $totalSales += $amount;

            $ledgerRows[] = [
                'entry_date' => $row['entry_date'],
                'type' => 'Sale',
                'ref_no' => $row['ref_no'],
                'description' => $row['description'],
                'debit' => $amount,
                'credit' => 0.00,
                'sort_date' => $row['entry_date'] . ' 09:00:00'
            ];
        }
        $stmt->close();
    }

    /* CUSTOMER PAYMENTS = CREDIT */
    $sql = "
        SELECT
            cp.id,
            cp.receipt_date AS entry_date,
            cp.receipt_no AS ref_no,
            cp.reference_no,
            cp.amount,
            cp.notes,
            CONCAT('Receipt', IF(cp.reference_no IS NOT NULL AND cp.reference_no != '', CONCAT(' / Ref: ', cp.reference_no), '')) AS description
        FROM customer_payments cp
        WHERE cp.business_id = ?
          AND cp.customer_id = ?
    ";
    $params = [$businessId, $customerId];
    $types = 'ii';

    if ($fromDate !== '') {
        $sql .= " AND cp.receipt_date >= ?";
        $params[] = $fromDate;
        $types .= 's';
    }
    if ($toDate !== '') {
        $sql .= " AND cp.receipt_date <= ?";
        $params[] = $toDate;
        $types .= 's';
    }
    if ($search !== '') {
        $sql .= " AND (cp.receipt_no LIKE ? OR cp.reference_no LIKE ? OR cp.notes LIKE ?)";
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $types .= 'sss';
    }

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
            $amount = (float)($row['amount'] ?? 0);
            $totalReceipts += $amount;

            $ledgerRows[] = [
                'entry_date' => $row['entry_date'],
                'type' => 'Receipt',
                'ref_no' => $row['ref_no'],
                'description' => $row['description'],
                'debit' => 0.00,
                'credit' => $amount,
                'sort_date' => $row['entry_date'] . ' 18:00:00'
            ];
        }
        $stmt->close();
    }

    usort($ledgerRows, function ($a, $b) {
        return strcmp($a['sort_date'], $b['sort_date']);
    });

    foreach ($ledgerRows as $key => $row) {
        $runningBalance += (float)$row['debit'];
        $runningBalance -= (float)$row['credit'];

        $ledgerRows[$key]['running_balance'] = abs($runningBalance);
        $ledgerRows[$key]['running_type'] = $runningBalance >= 0 ? 'Dr' : 'Cr';
    }
}

$netBalance = abs($runningBalance);
$netBalanceType = $runningBalance >= 0 ? 'Dr' : 'Cr';
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
                        <h4 class="card-title mb-4">Customer Ledger Filter</h4>

                        <form method="get" class="row g-2 align-items-end">
                            <div class="col-md-4">
                                <label class="form-label">Customer</label>
                                <select name="customer_id" class="form-select" required>
                                    <option value="0">Select Customer</option>
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
                                <label class="form-label">From Date</label>
                                <input type="date" name="from_date" class="form-control" value="<?php echo h($fromDate); ?>">
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">To Date</label>
                                <input type="date" name="to_date" class="form-control" value="<?php echo h($toDate); ?>">
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Search</label>
                                <input type="text" name="search" class="form-control" placeholder="Bill no, receipt no..." value="<?php echo h($search); ?>">
                            </div>

                            <div class="col-md-1">
                                <button type="submit" class="btn btn-primary w-100">Go</button>
                            </div>

                            <div class="col-md-12">
                                <a href="customer-ledger.php" class="btn btn-secondary">Reset</a>
                            </div>
                        </form>
                    </div>
                </div>

                <?php if ($selectedCustomer): ?>
                    <div class="row">
                        <div class="col-md-3">
                            <div class="card text-center">
                                <div class="card-body">
                                    <h5 class="text-primary mt-2">₹ <?php echo money($openingBalance); ?></h5>
                                    <p class="text-muted mb-0">Opening Balance (<?php echo h($openingBalanceType); ?>)</p>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="card text-center">
                                <div class="card-body">
                                    <h5 class="text-danger mt-2">₹ <?php echo money($totalSales); ?></h5>
                                    <p class="text-muted mb-0">Total Sales</p>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="card text-center">
                                <div class="card-body">
                                    <h5 class="text-success mt-2">₹ <?php echo money($totalReceipts); ?></h5>
                                    <p class="text-muted mb-0">Total Receipts</p>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="card text-center">
                                <div class="card-body">
                                    <h5 class="text-info mt-2">₹ <?php echo money($netBalance); ?></h5>
                                    <p class="text-muted mb-0">Closing Balance (<?php echo h($netBalanceType); ?>)</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <div>
                                    <h4 class="card-title mb-0">Ledger - <?php echo h($selectedCustomer['customer_name'] ?? ''); ?></h4>
                                    <small class="text-muted">
                                        <?php if ($fromDate !== '' || $toDate !== ''): ?>
                                            Period:
                                            <?php echo $fromDate !== '' ? h(date('d-m-Y', strtotime($fromDate))) : 'Beginning'; ?>
                                            to
                                            <?php echo $toDate !== '' ? h(date('d-m-Y', strtotime($toDate))) : 'Today'; ?>
                                        <?php else: ?>
                                            Full Ledger
                                        <?php endif; ?>
                                    </small>
                                </div>

                                <button class="btn btn-dark" onclick="window.print()">Print</button>
                            </div>

                            <div class="table-responsive">
                                <table class="table table-bordered table-striped align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Date</th>
                                            <th>Type</th>
                                            <th>Reference No</th>
                                            <th>Description</th>
                                            <th>Debit</th>
                                            <th>Credit</th>
                                            <th>Running Balance</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($ledgerRows)): ?>
                                            <?php foreach ($ledgerRows as $index => $row): ?>
                                                <tr>
                                                    <td><?php echo $index + 1; ?></td>
                                                    <td>
                                                        <?php
                                                        if (!empty($row['entry_date'])) {
                                                            echo date('d-m-Y', strtotime($row['entry_date']));
                                                        } else {
                                                            echo '-';
                                                        }
                                                        ?>
                                                    </td>
                                                    <td><?php echo h($row['type']); ?></td>
                                                    <td><?php echo h($row['ref_no'] ?? ''); ?></td>
                                                    <td><?php echo h($row['description'] ?? ''); ?></td>
                                                    <td class="text-danger">
                                                        <?php echo (float)$row['debit'] > 0 ? '₹ ' . money($row['debit']) : '-'; ?>
                                                    </td>
                                                    <td class="text-success">
                                                        <?php echo (float)$row['credit'] > 0 ? '₹ ' . money($row['credit']) : '-'; ?>
                                                    </td>
                                                    <td>
                                                        ₹ <?php echo money($row['running_balance'] ?? 0); ?>
                                                        <span class="badge bg-<?php echo ($row['running_type'] ?? 'Dr') === 'Dr' ? 'danger' : 'success'; ?>">
                                                            <?php echo h($row['running_type'] ?? 'Dr'); ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="8" class="text-center text-muted">No ledger entries found.</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                    <?php if (!empty($ledgerRows)): ?>
                                        <tfoot>
                                            <tr>
                                                <th colspan="5" class="text-end">Totals</th>
                                                <th class="text-danger">₹ <?php echo money($totalSales + ($openingBalanceType === 'Dr' ? $openingBalance : 0)); ?></th>
                                                <th class="text-success">₹ <?php echo money($totalReceipts + ($openingBalanceType === 'Cr' ? $openingBalance : 0)); ?></th>
                                                <th>
                                                    ₹ <?php echo money($netBalance); ?>
                                                    <span class="badge bg-<?php echo $netBalanceType === 'Dr' ? 'danger' : 'success'; ?>">
                                                        <?php echo h($netBalanceType); ?>
                                                    </span>
                                                </th>
                                            </tr>
                                        </tfoot>
                                    <?php endif; ?>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php elseif ($customerId > 0): ?>
                    <div class="alert alert-warning">Customer not found.</div>
                <?php endif; ?>

            </div>
        </div>

        <?php include('includes/footer.php'); ?>
    </div>
</div>

<?php include('includes/rightbar.php'); ?>
<?php include('includes/scripts.php'); ?>

</body>
</html>