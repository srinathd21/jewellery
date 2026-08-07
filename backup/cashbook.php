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

$pageTitle = 'Cash Book';

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
   FILTERS
------------------------------------------------------- */
$fromDate = trim((string)($_GET['from_date'] ?? date('Y-m-01')));
$toDate   = trim((string)($_GET['to_date'] ?? date('Y-m-d')));

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
   CASH METHOD IDS
------------------------------------------------------- */
$cashMethodIds = [];
if (tableExists($conn, 'payment_methods')) {
    $res = $conn->query("SELECT id, method_name FROM payment_methods");
    while ($res && $row = $res->fetch_assoc()) {
        if (strtolower(trim((string)$row['method_name'])) === 'cash') {
            $cashMethodIds[] = (int)$row['id'];
        }
    }
}
$cashMethodIds = array_values(array_unique($cashMethodIds));

function isCashCondition(string $alias, array $cashMethodIds): string
{
    if (empty($cashMethodIds)) {
        return "1=1";
    }
    $ids = implode(',', array_map('intval', $cashMethodIds));
    return "{$alias}.payment_method_id IN ({$ids})";
}

/* -------------------------------------------------------
   COLLECT ENTRIES BEFORE FROM DATE FOR OPENING CASH
------------------------------------------------------- */
$openingCash = 0.00;

function addAmountToOpening(float &$openingCash, float $amount, string $flow): void
{
    if ($flow === 'in') {
        $openingCash += $amount;
    } else {
        $openingCash -= $amount;
    }
}

/* SALES BEFORE FROM DATE */
if (tableExists($conn, 'sales')) {
    $sql = "
        SELECT COALESCE(SUM(s.paid_amount),0) AS amount
        FROM sales s
        WHERE s.business_id = ?
          AND s.bill_date < ?
          AND COALESCE(s.paid_amount,0) > 0
    ";
    if (hasColumn($conn, 'sales', 'status')) {
        $sql .= " AND s.status = 'Active'";
    }
    if (hasColumn($conn, 'sales', 'payment_method_id')) {
        $sql .= " AND " . isCashCondition('s', $cashMethodIds);
    }

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('is', $businessId, $fromDate);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        addAmountToOpening($openingCash, (float)($row['amount'] ?? 0), 'in');
        $stmt->close();
    }
}

/* CUSTOMER PAYMENTS BEFORE FROM DATE */
if (tableExists($conn, 'customer_payments')) {
    $sql = "
        SELECT COALESCE(SUM(cp.amount),0) AS amount
        FROM customer_payments cp
        WHERE cp.business_id = ?
          AND cp.receipt_date < ?
    ";
    if (hasColumn($conn, 'customer_payments', 'payment_method_id')) {
        $sql .= " AND " . isCashCondition('cp', $cashMethodIds);
    }

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('is', $businessId, $fromDate);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        addAmountToOpening($openingCash, (float)($row['amount'] ?? 0), 'in');
        $stmt->close();
    }
}

/* PURCHASES BEFORE FROM DATE */
if (tableExists($conn, 'purchases')) {
    $sql = "
        SELECT COALESCE(SUM(p.paid_amount),0) AS amount
        FROM purchases p
        WHERE p.business_id = ?
          AND p.purchase_date < ?
          AND COALESCE(p.paid_amount,0) > 0
    ";
    if (hasColumn($conn, 'purchases', 'payment_method_id')) {
        $sql .= " AND " . isCashCondition('p', $cashMethodIds);
    }

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('is', $businessId, $fromDate);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        addAmountToOpening($openingCash, (float)($row['amount'] ?? 0), 'out');
        $stmt->close();
    }
}

/* SUPPLIER PAYMENTS BEFORE FROM DATE */
if (tableExists($conn, 'supplier_payments')) {
    $sql = "
        SELECT COALESCE(SUM(sp.amount),0) AS amount
        FROM supplier_payments sp
        WHERE sp.business_id = ?
          AND sp.payment_date < ?
    ";
    if (hasColumn($conn, 'supplier_payments', 'payment_method_id')) {
        $sql .= " AND " . isCashCondition('sp', $cashMethodIds);
    }

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('is', $businessId, $fromDate);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        addAmountToOpening($openingCash, (float)($row['amount'] ?? 0), 'out');
        $stmt->close();
    }
}

/* EXPENSES BEFORE FROM DATE */
if (tableExists($conn, 'expenses')) {
    $sql = "
        SELECT COALESCE(SUM(e.amount),0) AS amount
        FROM expenses e
        WHERE e.business_id = ?
          AND e.expense_date < ?
    ";
    if (hasColumn($conn, 'expenses', 'payment_method_id')) {
        $sql .= " AND " . isCashCondition('e', $cashMethodIds);
    }

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('is', $businessId, $fromDate);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        addAmountToOpening($openingCash, (float)($row['amount'] ?? 0), 'out');
        $stmt->close();
    }
}

/* -------------------------------------------------------
   CURRENT PERIOD ENTRIES
------------------------------------------------------- */
$entries = [];

/* SALES = CASH IN */
if (tableExists($conn, 'sales')) {
    $sql = "
        SELECT
            s.bill_date AS entry_date,
            'Sale' AS entry_type,
            s.bill_no AS ref_no,
            COALESCE(c.customer_name, s.customer_name, 'Walk-in Customer') AS party_name,
            COALESCE(s.paid_amount,0) AS amount,
            'in' AS cash_flow,
            COALESCE(s.notes,'') AS notes
        FROM sales s
        LEFT JOIN customers c ON c.id = s.customer_id
        WHERE s.business_id = ?
          AND s.bill_date >= ?
          AND s.bill_date <= ?
          AND COALESCE(s.paid_amount,0) > 0
    ";
    if (hasColumn($conn, 'sales', 'status')) {
        $sql .= " AND s.status = 'Active'";
    }
    if (hasColumn($conn, 'sales', 'payment_method_id')) {
        $sql .= " AND " . isCashCondition('s', $cashMethodIds);
    }

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('iss', $businessId, $fromDate, $toDate);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && $row = $res->fetch_assoc()) {
            $entries[] = $row;
        }
        $stmt->close();
    }
}

/* CUSTOMER COLLECTIONS = CASH IN */
if (tableExists($conn, 'customer_payments')) {
    $sql = "
        SELECT
            cp.receipt_date AS entry_date,
            'Customer Collection' AS entry_type,
            cp.receipt_no AS ref_no,
            c.customer_name AS party_name,
            cp.amount AS amount,
            'in' AS cash_flow,
            COALESCE(cp.notes,'') AS notes
        FROM customer_payments cp
        LEFT JOIN customers c ON c.id = cp.customer_id
        WHERE cp.business_id = ?
          AND cp.receipt_date >= ?
          AND cp.receipt_date <= ?
    ";
    if (hasColumn($conn, 'customer_payments', 'payment_method_id')) {
        $sql .= " AND " . isCashCondition('cp', $cashMethodIds);
    }

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('iss', $businessId, $fromDate, $toDate);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && $row = $res->fetch_assoc()) {
            $entries[] = $row;
        }
        $stmt->close();
    }
}

/* PURCHASES = CASH OUT */
if (tableExists($conn, 'purchases')) {
    $sql = "
        SELECT
            p.purchase_date AS entry_date,
            'Purchase' AS entry_type,
            p.purchase_no AS ref_no,
            s.supplier_name AS party_name,
            COALESCE(p.paid_amount,0) AS amount,
            'out' AS cash_flow,
            COALESCE(p.notes,'') AS notes
        FROM purchases p
        LEFT JOIN suppliers s ON s.id = p.supplier_id
        WHERE p.business_id = ?
          AND p.purchase_date >= ?
          AND p.purchase_date <= ?
          AND COALESCE(p.paid_amount,0) > 0
    ";
    if (hasColumn($conn, 'purchases', 'payment_method_id')) {
        $sql .= " AND " . isCashCondition('p', $cashMethodIds);
    }

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('iss', $businessId, $fromDate, $toDate);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && $row = $res->fetch_assoc()) {
            $entries[] = $row;
        }
        $stmt->close();
    }
}

/* SUPPLIER PAYMENTS = CASH OUT */
if (tableExists($conn, 'supplier_payments')) {
    $sql = "
        SELECT
            sp.payment_date AS entry_date,
            'Supplier Payment' AS entry_type,
            sp.payment_no AS ref_no,
            s.supplier_name AS party_name,
            sp.amount AS amount,
            'out' AS cash_flow,
            COALESCE(sp.notes,'') AS notes
        FROM supplier_payments sp
        LEFT JOIN suppliers s ON s.id = sp.supplier_id
        WHERE sp.business_id = ?
          AND sp.payment_date >= ?
          AND sp.payment_date <= ?
    ";
    if (hasColumn($conn, 'supplier_payments', 'payment_method_id')) {
        $sql .= " AND " . isCashCondition('sp', $cashMethodIds);
    }

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('iss', $businessId, $fromDate, $toDate);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && $row = $res->fetch_assoc()) {
            $entries[] = $row;
        }
        $stmt->close();
    }
}

/* EXPENSES = CASH OUT */
if (tableExists($conn, 'expenses')) {
    $sql = "
        SELECT
            e.expense_date AS entry_date,
            'Expense' AS entry_type,
            CONCAT('EXP-', e.id) AS ref_no,
            e.expense_category AS party_name,
            e.amount AS amount,
            'out' AS cash_flow,
            e.description AS notes
        FROM expenses e
        WHERE e.business_id = ?
          AND e.expense_date >= ?
          AND e.expense_date <= ?
    ";
    if (hasColumn($conn, 'expenses', 'payment_method_id')) {
        $sql .= " AND " . isCashCondition('e', $cashMethodIds);
    }

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('iss', $businessId, $fromDate, $toDate);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && $row = $res->fetch_assoc()) {
            $entries[] = $row;
        }
        $stmt->close();
    }
}

/* -------------------------------------------------------
   SORT + RUNNING
------------------------------------------------------- */
usort($entries, function ($a, $b) {
    $cmp = strcmp((string)$a['entry_date'], (string)$b['entry_date']);
    if ($cmp !== 0) {
        return $cmp;
    }
    return strcmp((string)$a['entry_type'], (string)$b['entry_type']);
});

$totalCashIn = 0.00;
$totalCashOut = 0.00;
$runningBalance = $openingCash;

foreach ($entries as $index => $entry) {
    $amount = (float)($entry['amount'] ?? 0);

    if (($entry['cash_flow'] ?? '') === 'in') {
        $totalCashIn += $amount;
        $runningBalance += $amount;
    } else {
        $totalCashOut += $amount;
        $runningBalance -= $amount;
    }

    $entries[$index]['running_balance'] = $runningBalance;
}

$closingCash = $openingCash + $totalCashIn - $totalCashOut;
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
                        <h4 class="card-title mb-4">Cash Book Filter</h4>

                        <form method="get" class="row g-2 align-items-end">
                            <div class="col-md-4">
                                <label class="form-label">From Date</label>
                                <input type="date" name="from_date" class="form-control" value="<?php echo h($fromDate); ?>" required>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label">To Date</label>
                                <input type="date" name="to_date" class="form-control" value="<?php echo h($toDate); ?>" required>
                            </div>

                            <div class="col-md-2">
                                <button type="submit" class="btn btn-primary w-100">Show</button>
                            </div>

                            <div class="col-md-2">
                                <a href="cashbook.php" class="btn btn-secondary w-100">Reset</a>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-primary mt-2">₹ <?php echo money($openingCash); ?></h3>
                                <p class="text-muted mb-0">Opening Cash</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-success mt-2">₹ <?php echo money($totalCashIn); ?></h3>
                                <p class="text-muted mb-0">Cash In</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-danger mt-2">₹ <?php echo money($totalCashOut); ?></h3>
                                <p class="text-muted mb-0">Cash Out</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-info mt-2">₹ <?php echo money($closingCash); ?></h3>
                                <p class="text-muted mb-0">Closing Cash</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <h4 class="card-title mb-0">Cash Book</h4>
                                <small class="text-muted">
                                    From <?php echo h(date('d-m-Y', strtotime($fromDate))); ?>
                                    to <?php echo h(date('d-m-Y', strtotime($toDate))); ?>
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
                                        <th>Party / Category</th>
                                        <th>Notes</th>
                                        <th>Cash In</th>
                                        <th>Cash Out</th>
                                        <th>Running Cash</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr class="table-light">
                                        <td>1</td>
                                        <td><?php echo h(date('d-m-Y', strtotime($fromDate))); ?></td>
                                        <td>Opening</td>
                                        <td>-</td>
                                        <td>Opening Cash Balance</td>
                                        <td>-</td>
                                        <td>-</td>
                                        <td>-</td>
                                        <td>₹ <?php echo money($openingCash); ?></td>
                                    </tr>

                                    <?php if (!empty($entries)): ?>
                                        <?php foreach ($entries as $index => $entry): ?>
                                            <tr>
                                                <td><?php echo $index + 2; ?></td>
                                                <td><?php echo h(date('d-m-Y', strtotime($entry['entry_date']))); ?></td>
                                                <td><?php echo h($entry['entry_type']); ?></td>
                                                <td><?php echo h($entry['ref_no']); ?></td>
                                                <td><?php echo h($entry['party_name']); ?></td>
                                                <td><?php echo h($entry['notes']); ?></td>
                                                <td class="text-success">
                                                    <?php echo ($entry['cash_flow'] === 'in') ? '₹ ' . money($entry['amount']) : '-'; ?>
                                                </td>
                                                <td class="text-danger">
                                                    <?php echo ($entry['cash_flow'] === 'out') ? '₹ ' . money($entry['amount']) : '-'; ?>
                                                </td>
                                                <td>₹ <?php echo money($entry['running_balance']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="9" class="text-center text-muted">No cashbook entries found for selected dates.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>

                                <tfoot>
                                    <tr>
                                        <th colspan="6" class="text-end">Totals</th>
                                        <th class="text-success">₹ <?php echo money($totalCashIn); ?></th>
                                        <th class="text-danger">₹ <?php echo money($totalCashOut); ?></th>
                                        <th class="text-primary">₹ <?php echo money($closingCash); ?></th>
                                    </tr>
                                </tfoot>
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