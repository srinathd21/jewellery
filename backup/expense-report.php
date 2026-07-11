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

$pageTitle = 'Expense Report';

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
if (!in_array($roleName, ['admin', 'manager'], true)) {
    die('Access denied.');
}

/* -------------------------------------------------------
   REQUIRED TABLES
------------------------------------------------------- */
if (!tableExists($conn, 'expenses')) {
    die('Required table `expenses` not found.');
}

$paymentMethodsTableExists = tableExists($conn, 'payment_methods');
$usersTableExists = tableExists($conn, 'users');

/* -------------------------------------------------------
   COLUMN CHECKS
------------------------------------------------------- */
$expHasBusinessId      = hasColumn($conn, 'expenses', 'business_id');
$expHasExpenseDate     = hasColumn($conn, 'expenses', 'expense_date');
$expHasCategory        = hasColumn($conn, 'expenses', 'expense_category');
$expHasDescription     = hasColumn($conn, 'expenses', 'description');
$expHasAmount          = hasColumn($conn, 'expenses', 'amount');
$expHasPaymentMethodId = hasColumn($conn, 'expenses', 'payment_method_id');
$expHasReferenceNo     = hasColumn($conn, 'expenses', 'reference_no');
$expHasCreatedBy       = hasColumn($conn, 'expenses', 'created_by');
$expHasCreatedAt       = hasColumn($conn, 'expenses', 'created_at');
$expHasUpdatedAt       = hasColumn($conn, 'expenses', 'updated_at');

$pmHasId               = $paymentMethodsTableExists && hasColumn($conn, 'payment_methods', 'id');
$pmHasMethodName       = $paymentMethodsTableExists && hasColumn($conn, 'payment_methods', 'method_name');
$pmHasActive           = $paymentMethodsTableExists && hasColumn($conn, 'payment_methods', 'is_active');

$userHasId             = $usersTableExists && hasColumn($conn, 'users', 'id');
$userHasName           = $usersTableExists && hasColumn($conn, 'users', 'full_name');

/* -------------------------------------------------------
   FILTERS
------------------------------------------------------- */
$period = trim((string)($_GET['period'] ?? 'this_month'));
$fromDate = trim((string)($_GET['from_date'] ?? ''));
$toDate   = trim((string)($_GET['to_date'] ?? ''));
$categoryFilter = trim((string)($_GET['category'] ?? ''));
$paymentMethodFilter = (int)($_GET['payment_method_id'] ?? 0);
$search = trim((string)($_GET['search'] ?? ''));

$today = date('Y-m-d');

if ($period === 'today') {
    $fromDate = $today;
    $toDate = $today;
} elseif ($period === 'this_week') {
    $fromDate = date('Y-m-d', strtotime('monday this week'));
    $toDate = $today;
} elseif ($period === 'this_month') {
    $fromDate = date('Y-m-01');
    $toDate = $today;
} elseif ($period === 'last_month') {
    $fromDate = date('Y-m-01', strtotime('first day of last month'));
    $toDate = date('Y-m-t', strtotime('last day of last month'));
} elseif ($period === 'this_year') {
    $fromDate = date('Y-01-01');
    $toDate = $today;
} elseif ($period === 'custom') {
    if ($fromDate === '') {
        $fromDate = date('Y-m-01');
    }
    if ($toDate === '') {
        $toDate = $today;
    }
} else {
    $period = 'this_month';
    $fromDate = date('Y-m-01');
    $toDate = $today;
}

if ($fromDate > $toDate) {
    $tmp = $fromDate;
    $fromDate = $toDate;
    $toDate = $tmp;
}

/* -------------------------------------------------------
   PAYMENT METHODS
------------------------------------------------------- */
$paymentMethods = [];
if ($paymentMethodsTableExists && $pmHasId && $pmHasMethodName) {
    $sql = "SELECT id, method_name FROM payment_methods WHERE 1=1";
    if ($pmHasActive) {
        $sql .= " AND is_active = 1";
    }
    $sql .= " ORDER BY method_name ASC";

    $res = $conn->query($sql);
    while ($res && $row = $res->fetch_assoc()) {
        $paymentMethods[] = $row;
    }
}

/* -------------------------------------------------------
   CATEGORY OPTIONS
------------------------------------------------------- */
$categories = [];
$catSql = "SELECT DISTINCT expense_category FROM expenses WHERE 1=1";
$catParams = [];
$catTypes = '';

if ($expHasBusinessId) {
    $catSql .= " AND business_id = ? ";
    $catParams[] = $businessId;
    $catTypes .= 'i';
}
if ($expHasExpenseDate) {
    $catSql .= " AND expense_date BETWEEN ? AND ? ";
    $catParams[] = $fromDate;
    $catParams[] = $toDate;
    $catTypes .= 'ss';
}
$catSql .= " AND expense_category IS NOT NULL AND expense_category != '' ORDER BY expense_category ASC";

$stmt = $conn->prepare($catSql);
if ($stmt) {
    if (!empty($catParams)) {
        $bind = [];
        $bind[] = $catTypes;
        for ($i = 0; $i < count($catParams); $i++) {
            $bind[] = &$catParams[$i];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && $row = $res->fetch_assoc()) {
        $categories[] = $row['expense_category'];
    }
    $stmt->close();
}

/* -------------------------------------------------------
   COMMON WHERE
------------------------------------------------------- */
$where = " WHERE 1=1 ";
$params = [];
$types = '';

if ($expHasBusinessId) {
    $where .= " AND e.business_id = ? ";
    $params[] = $businessId;
    $types .= 'i';
}

if ($expHasExpenseDate) {
    $where .= " AND e.expense_date BETWEEN ? AND ? ";
    $params[] = $fromDate;
    $params[] = $toDate;
    $types .= 'ss';
}

if ($categoryFilter !== '' && $expHasCategory) {
    $where .= " AND e.expense_category = ? ";
    $params[] = $categoryFilter;
    $types .= 's';
}

if ($paymentMethodFilter > 0 && $expHasPaymentMethodId) {
    $where .= " AND e.payment_method_id = ? ";
    $params[] = $paymentMethodFilter;
    $types .= 'i';
}

if ($search !== '') {
    $like = '%' . $search . '%';
    $where .= " AND (";
    $searchParts = [];

    if ($expHasCategory) {
        $searchParts[] = "e.expense_category LIKE ?";
        $params[] = $like;
        $types .= 's';
    }
    if ($expHasDescription) {
        $searchParts[] = "e.description LIKE ?";
        $params[] = $like;
        $types .= 's';
    }
    if ($expHasReferenceNo) {
        $searchParts[] = "e.reference_no LIKE ?";
        $params[] = $like;
        $types .= 's';
    }
    if ($paymentMethodsTableExists && $pmHasMethodName && $expHasPaymentMethodId) {
        $searchParts[] = "pm.method_name LIKE ?";
        $params[] = $like;
        $types .= 's';
    }

    if (empty($searchParts)) {
        $where .= "1=1";
    } else {
        $where .= implode(' OR ', $searchParts);
    }
    $where .= ")";
}

/* -------------------------------------------------------
   SUMMARY
------------------------------------------------------- */
$totalExpenses = 0.00;
$totalEntries = 0;
$avgExpense = 0.00;
$highestExpense = 0.00;

$sumSql = "
    SELECT
        COUNT(*) AS total_entries,
        SUM(" . ($expHasAmount ? "IFNULL(e.amount,0)" : "0") . ") AS total_expenses,
        AVG(" . ($expHasAmount ? "IFNULL(e.amount,0)" : "0") . ") AS avg_expense,
        MAX(" . ($expHasAmount ? "IFNULL(e.amount,0)" : "0") . ") AS highest_expense
    FROM expenses e
";

if ($paymentMethodsTableExists && $pmHasMethodName && $expHasPaymentMethodId) {
    $sumSql .= " LEFT JOIN payment_methods pm ON pm.id = e.payment_method_id ";
}

$sumSql .= $where;

$stmt = $conn->prepare($sumSql);
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
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if ($row) {
        $totalEntries = (int)($row['total_entries'] ?? 0);
        $totalExpenses = (float)($row['total_expenses'] ?? 0);
        $avgExpense = (float)($row['avg_expense'] ?? 0);
        $highestExpense = (float)($row['highest_expense'] ?? 0);
    }
}

/* -------------------------------------------------------
   CATEGORY SUMMARY
------------------------------------------------------- */
$categorySummary = [];
if ($expHasCategory) {
    $catSumSql = "
        SELECT
            e.expense_category,
            COUNT(*) AS total_count,
            SUM(" . ($expHasAmount ? "IFNULL(e.amount,0)" : "0") . ") AS total_amount
        FROM expenses e
    ";

    if ($paymentMethodsTableExists && $pmHasMethodName && $expHasPaymentMethodId) {
        $catSumSql .= " LEFT JOIN payment_methods pm ON pm.id = e.payment_method_id ";
    }

    $catSumSql .= $where . "
        GROUP BY e.expense_category
        ORDER BY total_amount DESC
    ";

    $stmt = $conn->prepare($catSumSql);
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
            $categorySummary[] = $row;
        }
        $stmt->close();
    }
}

/* -------------------------------------------------------
   PAYMENT METHOD SUMMARY
------------------------------------------------------- */
$paymentMethodSummary = [];
if ($paymentMethodsTableExists && $pmHasMethodName && $expHasPaymentMethodId) {
    $pmSumSql = "
        SELECT
            IFNULL(pm.method_name, 'Unknown') AS method_name,
            COUNT(*) AS total_count,
            SUM(" . ($expHasAmount ? "IFNULL(e.amount,0)" : "0") . ") AS total_amount
        FROM expenses e
        LEFT JOIN payment_methods pm ON pm.id = e.payment_method_id
        " . $where . "
        GROUP BY pm.method_name
        ORDER BY total_amount DESC
    ";

    $stmt = $conn->prepare($pmSumSql);
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
            $paymentMethodSummary[] = $row;
        }
        $stmt->close();
    }
}

/* -------------------------------------------------------
   EXPENSE LIST
------------------------------------------------------- */
$listSql = "
    SELECT
        e.id,
        " . ($expHasExpenseDate ? "e.expense_date," : "NULL AS expense_date,") . "
        " . ($expHasCategory ? "e.expense_category," : "'' AS expense_category,") . "
        " . ($expHasDescription ? "e.description," : "'' AS description,") . "
        " . ($expHasAmount ? "e.amount," : "0 AS amount,") . "
        " . ($expHasReferenceNo ? "e.reference_no," : "'' AS reference_no,") . "
        " . ($expHasCreatedAt ? "e.created_at," : "NULL AS created_at,") . "
        " . ($expHasUpdatedAt ? "e.updated_at," : "NULL AS updated_at,") . "
        " . ($paymentMethodsTableExists && $pmHasMethodName && $expHasPaymentMethodId ? "IFNULL(pm.method_name, 'Unknown') AS payment_method_name," : "'-' AS payment_method_name,") . "
        " . ($usersTableExists && $userHasId && $userHasName && $expHasCreatedBy ? "IFNULL(u.full_name, '-') AS created_by_name" : "'-' AS created_by_name") . "
    FROM expenses e
";

if ($paymentMethodsTableExists && $pmHasMethodName && $expHasPaymentMethodId) {
    $listSql .= " LEFT JOIN payment_methods pm ON pm.id = e.payment_method_id ";
}
if ($usersTableExists && $userHasId && $userHasName && $expHasCreatedBy) {
    $listSql .= " LEFT JOIN users u ON u.id = e.created_by ";
}

$listSql .= $where . " ORDER BY " . ($expHasExpenseDate ? "e.expense_date DESC, " : "") . " e.id DESC";

$stmt = $conn->prepare($listSql);
$expenses = [];
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
        $expenses[] = $row;
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


                <div class="card">
                    <div class="card-body">
                        <form method="get" class="row g-2 align-items-end">
                            <div class="col-md-2">
                                <label class="form-label">Period</label>
                                <select name="period" class="form-select" onchange="toggleCustomDates(this.value)">
                                    <option value="today" <?php echo $period === 'today' ? 'selected' : ''; ?>>Today</option>
                                    <option value="this_week" <?php echo $period === 'this_week' ? 'selected' : ''; ?>>This Week</option>
                                    <option value="this_month" <?php echo $period === 'this_month' ? 'selected' : ''; ?>>This Month</option>
                                    <option value="last_month" <?php echo $period === 'last_month' ? 'selected' : ''; ?>>Last Month</option>
                                    <option value="this_year" <?php echo $period === 'this_year' ? 'selected' : ''; ?>>This Year</option>
                                    <option value="custom" <?php echo $period === 'custom' ? 'selected' : ''; ?>>Custom</option>
                                </select>
                            </div>

                            <div class="col-md-2 custom-date-box" style="<?php echo $period === 'custom' ? '' : 'display:none;'; ?>">
                                <label class="form-label">From Date</label>
                                <input type="date" name="from_date" class="form-control" value="<?php echo h($fromDate); ?>">
                            </div>

                            <div class="col-md-2 custom-date-box" style="<?php echo $period === 'custom' ? '' : 'display:none;'; ?>">
                                <label class="form-label">To Date</label>
                                <input type="date" name="to_date" class="form-control" value="<?php echo h($toDate); ?>">
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Category</label>
                                <select name="category" class="form-select">
                                    <option value="">All Categories</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo h($cat); ?>" <?php echo $categoryFilter === $cat ? 'selected' : ''; ?>>
                                            <?php echo h($cat); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Payment Method</label>
                                <select name="payment_method_id" class="form-select">
                                    <option value="0">All Methods</option>
                                    <?php foreach ($paymentMethods as $pm): ?>
                                        <option value="<?php echo (int)$pm['id']; ?>" <?php echo $paymentMethodFilter === (int)$pm['id'] ? 'selected' : ''; ?>>
                                            <?php echo h($pm['method_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Search</label>
                                <input type="text" name="search" class="form-control" value="<?php echo h($search); ?>" placeholder="Category, desc, ref...">
                            </div>

                            <div class="col-md-12 d-flex gap-2 flex-wrap">
                                <button type="submit" class="btn btn-primary">Generate Report</button>
                                <a href="expense-report.php" class="btn btn-secondary">Reset</a>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="alert alert-secondary">
                    <strong>Period:</strong> <?php echo h(date('d-m-Y', strtotime($fromDate))); ?> to <?php echo h(date('d-m-Y', strtotime($toDate))); ?>
                </div>

                <div class="row">
                    <div class="col-md-3 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-primary mt-2"><?php echo $totalEntries; ?></h3>
                                <p class="text-muted mb-0">Total Entries</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-danger mt-2">₹<?php echo number_format($totalExpenses, 2); ?></h3>
                                <p class="text-muted mb-0">Total Expenses</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-warning mt-2">₹<?php echo number_format($avgExpense, 2); ?></h3>
                                <p class="text-muted mb-0">Average Expense</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-success mt-2">₹<?php echo number_format($highestExpense, 2); ?></h3>
                                <p class="text-muted mb-0">Highest Expense</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-lg-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Expense by Category</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-bordered table-striped align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Category</th>
                                                <th>Entries</th>
                                                <th>Amount</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($categorySummary)): ?>
                                                <?php foreach ($categorySummary as $index => $row): ?>
                                                    <tr>
                                                        <td><?php echo $index + 1; ?></td>
                                                        <td><?php echo h($row['expense_category'] ?? 'Others'); ?></td>
                                                        <td><?php echo (int)($row['total_count'] ?? 0); ?></td>
                                                        <td>₹<?php echo number_format((float)($row['total_amount'] ?? 0), 2); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="4" class="text-center text-muted">No category summary found.</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Expense by Payment Method</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-bordered table-striped align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Method</th>
                                                <th>Entries</th>
                                                <th>Amount</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($paymentMethodSummary)): ?>
                                                <?php foreach ($paymentMethodSummary as $index => $row): ?>
                                                    <tr>
                                                        <td><?php echo $index + 1; ?></td>
                                                        <td><?php echo h($row['method_name'] ?? 'Unknown'); ?></td>
                                                        <td><?php echo (int)($row['total_count'] ?? 0); ?></td>
                                                        <td>₹<?php echo number_format((float)($row['total_amount'] ?? 0), 2); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="4" class="text-center text-muted">No payment method summary found.</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Expense Details</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Date</th>
                                        <th>Category</th>
                                        <th>Description</th>
                                        <th>Payment Method</th>
                                        <th>Reference No</th>
                                        <th>Amount</th>
                                        <th>Created By</th>
                                        <th>Created</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($expenses)): ?>
                                        <?php foreach ($expenses as $index => $row): ?>
                                            <tr>
                                                <td><?php echo $index + 1; ?></td>
                                                <td>
                                                    <?php
                                                    if (!empty($row['expense_date'])) {
                                                        echo date('d-m-Y', strtotime($row['expense_date']));
                                                    } else {
                                                        echo '-';
                                                    }
                                                    ?>
                                                </td>
                                                <td><?php echo h($row['expense_category'] ?? ''); ?></td>
                                                <td><?php echo h($row['description'] ?? ''); ?></td>
                                                <td><?php echo h($row['payment_method_name'] ?? '-'); ?></td>
                                                <td><?php echo h($row['reference_no'] ?? ''); ?></td>
                                                <td><strong>₹<?php echo number_format((float)($row['amount'] ?? 0), 2); ?></strong></td>
                                                <td><?php echo h($row['created_by_name'] ?? '-'); ?></td>
                                                <td>
                                                    <?php
                                                    if (!empty($row['created_at'])) {
                                                        echo date('d-m-Y h:i A', strtotime($row['created_at']));
                                                    } else {
                                                        echo '-';
                                                    }
                                                    ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="9" class="text-center text-muted">No expense records found.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                                <?php if (!empty($expenses)): ?>
                                    <tfoot>
                                        <tr>
                                            <th colspan="6" class="text-end">Total</th>
                                            <th>₹<?php echo number_format($totalExpenses, 2); ?></th>
                                            <th colspan="2"></th>
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

<script>
function toggleCustomDates(value) {
    var boxes = document.querySelectorAll('.custom-date-box');
    for (var i = 0; i < boxes.length; i++) {
        boxes[i].style.display = (value === 'custom') ? '' : 'none';
    }
}
</script>

</body>
</html>