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

$pageTitle = 'Expenses';

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
if (!tableExists($conn, 'expenses')) {
    die('Expenses table not found. Please import the SQL first.');
}

/* -------------------------------------------------------
   FLASH
------------------------------------------------------- */
$success = '';
$error = '';

$msg = trim((string)($_GET['msg'] ?? ''));
if ($msg === 'created') {
    $success = 'Expense added successfully.';
} elseif ($msg === 'deleted') {
    $success = 'Expense deleted successfully.';
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
   DELETE EXPENSE
------------------------------------------------------- */
if (isset($_GET['delete']) && (int)$_GET['delete'] > 0) {
    $deleteId = (int)$_GET['delete'];

    $stmt = $conn->prepare("DELETE FROM expenses WHERE id = ? AND business_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('ii', $deleteId, $businessId);
        if ($stmt->execute()) {
            addAuditLog(
                $conn,
                $businessId,
                $userId,
                'Expenses',
                'Delete',
                $deleteId,
                'Deleted expense'
            );
        }
        $stmt->close();
    }

    header('Location: expenses.php?msg=deleted');
    exit;
}

/* -------------------------------------------------------
   ADD EXPENSE
------------------------------------------------------- */
$expenseDate = date('Y-m-d');
$expenseCategory = '';
$description = '';
$amount = '0.00';
$paymentMethodId = 0;
$referenceNo = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_expense'])) {
    $expenseDate = trim((string)($_POST['expense_date'] ?? ''));
    $expenseCategory = trim((string)($_POST['expense_category'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $amount = trim((string)($_POST['amount'] ?? '0'));
    $paymentMethodId = (int)($_POST['payment_method_id'] ?? 0);
    $referenceNo = trim((string)($_POST['reference_no'] ?? ''));

    if ($expenseDate === '') {
        $error = 'Expense date is required.';
    } elseif ($expenseCategory === '') {
        $error = 'Expense category is required.';
    } elseif ($description === '') {
        $error = 'Description is required.';
    } elseif (!is_numeric($amount) || (float)$amount <= 0) {
        $error = 'Please enter a valid amount.';
    } else {
        $amountF = (float)$amount;
        $paymentMethodValue = $paymentMethodId > 0 ? $paymentMethodId : null;

        $stmt = $conn->prepare("
            INSERT INTO expenses
            (business_id, expense_date, expense_category, description, amount, payment_method_id, reference_no, created_by, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");

        if (!$stmt) {
            $error = 'Failed to prepare expense insert.';
        } else {
            $stmt->bind_param(
                'isssdisi',
                $businessId,
                $expenseDate,
                $expenseCategory,
                $description,
                $amountF,
                $paymentMethodValue,
                $referenceNo,
                $userId
            );

            if ($stmt->execute()) {
                $expenseId = (int)$stmt->insert_id;

                addAuditLog(
                    $conn,
                    $businessId,
                    $userId,
                    'Expenses',
                    'Create',
                    $expenseId,
                    'Created expense ' . $expenseCategory
                );

                $stmt->close();
                header('Location: expenses.php?msg=created');
                exit;
            } else {
                $error = 'Failed to save expense.';
            }
            $stmt->close();
        }
    }
}

/* -------------------------------------------------------
   FILTERS
------------------------------------------------------- */
$search = trim((string)($_GET['search'] ?? ''));
$fromDate = trim((string)($_GET['from_date'] ?? ''));
$toDate = trim((string)($_GET['to_date'] ?? ''));
$categoryFilter = trim((string)($_GET['category'] ?? ''));

$where = " WHERE e.business_id = ? ";
$params = [$businessId];
$types = 'i';

if ($search !== '') {
    $where .= " AND (
        e.expense_category LIKE ?
        OR e.description LIKE ?
        OR e.reference_no LIKE ?
        OR pm.method_name LIKE ?
    )";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'ssss';
}

if ($fromDate !== '') {
    $where .= " AND e.expense_date >= ? ";
    $params[] = $fromDate;
    $types .= 's';
}

if ($toDate !== '') {
    $where .= " AND e.expense_date <= ? ";
    $params[] = $toDate;
    $types .= 's';
}

if ($categoryFilter !== '') {
    $where .= " AND e.expense_category = ? ";
    $params[] = $categoryFilter;
    $types .= 's';
}

/* -------------------------------------------------------
   CATEGORY OPTIONS
------------------------------------------------------- */
$categories = [];
$sql = "SELECT DISTINCT expense_category FROM expenses WHERE business_id = ? ORDER BY expense_category ASC";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param('i', $businessId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && $row = $res->fetch_assoc()) {
        if (!empty($row['expense_category'])) {
            $categories[] = $row['expense_category'];
        }
    }
    $stmt->close();
}

/* -------------------------------------------------------
   SUMMARY
------------------------------------------------------- */
$totalExpenses = 0;
$totalExpenseAmount = 0.00;

$sql = "
    SELECT COUNT(*) AS cnt, COALESCE(SUM(e.amount),0) AS total_amount
    FROM expenses e
    LEFT JOIN payment_methods pm ON pm.id = e.payment_method_id
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
    $totalExpenses = (int)($row['cnt'] ?? 0);
    $totalExpenseAmount = (float)($row['total_amount'] ?? 0);
    $stmt->close();
}

/* -------------------------------------------------------
   LIST
------------------------------------------------------- */
$expenses = [];
$sql = "
    SELECT
        e.*,
        pm.method_name
    FROM expenses e
    LEFT JOIN payment_methods pm ON pm.id = e.payment_method_id
    $where
    ORDER BY e.id DESC
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
                                <h3 class="text-primary mt-2"><?php echo $totalExpenses; ?></h3>
                                <p class="text-muted mb-0">Total Expense Entries</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-danger mt-2">₹ <?php echo money($totalExpenseAmount); ?></h3>
                                <p class="text-muted mb-0">Total Expense Amount</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">Add Expense</h4>

                        <form method="post">
                            <input type="hidden" name="save_expense" value="1">

                            <div class="row">
                                <div class="col-md-2 mb-3">
                                    <label class="form-label">Expense Date</label>
                                    <input type="date" name="expense_date" class="form-control" value="<?php echo h($expenseDate); ?>" required>
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Category</label>
                                    <input type="text" name="expense_category" class="form-control" value="<?php echo h($expenseCategory); ?>" placeholder="Rent, Salary, Transport..." required>
                                </div>

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Description</label>
                                    <input type="text" name="description" class="form-control" value="<?php echo h($description); ?>" required>
                                </div>

                                <div class="col-md-2 mb-3">
                                    <label class="form-label">Amount</label>
                                    <input type="number" step="0.01" min="0" name="amount" class="form-control" value="<?php echo h($amount); ?>" required>
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

                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Reference No</label>
                                    <input type="text" name="reference_no" class="form-control" value="<?php echo h($referenceNo); ?>">
                                </div>

                                <div class="col-md-9 mb-3 d-flex align-items-end">
                                    <button type="submit" class="btn btn-primary">Save Expense</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">Filter Expenses</h4>

                        <form method="get" class="row g-2 align-items-end">
                            <div class="col-md-3">
                                <label class="form-label">Search</label>
                                <input type="text" name="search" class="form-control" placeholder="Category, description, reference..." value="<?php echo h($search); ?>">
                            </div>

                            <div class="col-md-3">
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
                                <a href="expenses.php" class="btn btn-secondary">Reset</a>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">Expense List</h4>

                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Date</th>
                                        <th>Category</th>
                                        <th>Description</th>
                                        <th>Method</th>
                                        <th>Reference</th>
                                        <th>Amount</th>
                                        <th>Created</th>
                                        <th style="min-width:120px;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($expenses)): ?>
                                        <?php foreach ($expenses as $index => $expense): ?>
                                            <tr>
                                                <td><?php echo $index + 1; ?></td>
                                                <td>
                                                    <?php
                                                    if (!empty($expense['expense_date'])) {
                                                        echo date('d-m-Y', strtotime($expense['expense_date']));
                                                    } else {
                                                        echo '-';
                                                    }
                                                    ?>
                                                </td>
                                                <td><?php echo h($expense['expense_category'] ?? ''); ?></td>
                                                <td><?php echo h($expense['description'] ?? ''); ?></td>
                                                <td><?php echo h($expense['method_name'] ?? '-'); ?></td>
                                                <td><?php echo h($expense['reference_no'] ?? ''); ?></td>
                                                <td>₹ <?php echo money($expense['amount'] ?? 0); ?></td>
                                                <td>
                                                    <?php
                                                    if (!empty($expense['created_at'])) {
                                                        echo date('d-m-Y h:i A', strtotime($expense['created_at']));
                                                    } else {
                                                        echo '-';
                                                    }
                                                    ?>
                                                </td>
                                                <td>
                                                    <a href="expenses.php?delete=<?php echo (int)$expense['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this expense?');">
                                                        Delete
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="9" class="text-center text-muted">No expenses found.</td>
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