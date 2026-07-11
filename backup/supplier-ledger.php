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

$pageTitle = 'Supplier Ledger';

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
if (!tableExists($conn, 'suppliers') || !tableExists($conn, 'purchases') || !tableExists($conn, 'supplier_payments')) {
    die('Required tables not found. Please import the SQL first.');
}

/* -------------------------------------------------------
   LOAD SUPPLIERS
------------------------------------------------------- */
$suppliers = [];
$sql = "SELECT id, supplier_name, supplier_code, opening_balance, balance_type
        FROM suppliers
        WHERE business_id = ?";
if (hasColumn($conn, 'suppliers', 'is_active')) {
    $sql .= " AND is_active = 1";
}
$sql .= " ORDER BY supplier_name ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $businessId);
$stmt->execute();
$res = $stmt->get_result();
while ($res && $row = $res->fetch_assoc()) {
    $suppliers[] = $row;
}
$stmt->close();

/* -------------------------------------------------------
   FILTERS
------------------------------------------------------- */
$supplierId = (int)($_GET['supplier_id'] ?? 0);
$fromDate = trim((string)($_GET['from_date'] ?? ''));
$toDate = trim((string)($_GET['to_date'] ?? ''));
$search = trim((string)($_GET['search'] ?? ''));

$selectedSupplier = null;
$openingBalance = 0.00;
$openingBalanceType = 'Cr';

if ($supplierId > 0) {
    $stmt = $conn->prepare("
        SELECT *
        FROM suppliers
        WHERE id = ? AND business_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('ii', $supplierId, $businessId);
    $stmt->execute();
    $res = $stmt->get_result();
    $selectedSupplier = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if ($selectedSupplier) {
        $openingBalance = (float)($selectedSupplier['opening_balance'] ?? 0);
        $openingBalanceType = (string)($selectedSupplier['balance_type'] ?? 'Cr');
    }
}

/* -------------------------------------------------------
   LEDGER DATA
------------------------------------------------------- */
$ledgerRows = [];
$totalPurchase = 0.00;
$totalPayment = 0.00;
$runningBalance = 0.00;

if ($supplierId > 0 && $selectedSupplier) {
    /* Opening balance logic:
       Cr => supplier payable => debit side
       Dr => advance/receivable from supplier => credit side
    */
    if ($openingBalanceType === 'Cr') {
        $runningBalance = $openingBalance;
    } else {
        $runningBalance = -$openingBalance;
    }

    $openingRow = [
        'entry_date' => '',
        'type' => 'Opening Balance',
        'ref_no' => '',
        'description' => 'Opening balance',
        'debit' => $openingBalanceType === 'Cr' ? $openingBalance : 0.00,
        'credit' => $openingBalanceType === 'Dr' ? $openingBalance : 0.00,
        'sort_date' => '0000-00-00 00:00:00'
    ];
    $ledgerRows[] = $openingRow;

    /* Purchases = Debit */
    $sql = "
        SELECT
            p.id,
            p.purchase_date AS entry_date,
            p.purchase_no AS ref_no,
            p.invoice_no,
            p.grand_total AS amount,
            p.notes,
            CONCAT('Purchase', IF(p.invoice_no IS NOT NULL AND p.invoice_no != '', CONCAT(' / Invoice: ', p.invoice_no), '')) AS description
        FROM purchases p
        WHERE p.business_id = ? AND p.supplier_id = ?
    ";
    $params = [$businessId, $supplierId];
    $types = 'ii';

    if ($fromDate !== '') {
        $sql .= " AND p.purchase_date >= ?";
        $params[] = $fromDate;
        $types .= 's';
    }
    if ($toDate !== '') {
        $sql .= " AND p.purchase_date <= ?";
        $params[] = $toDate;
        $types .= 's';
    }
    if ($search !== '') {
        $sql .= " AND (p.purchase_no LIKE ? OR p.invoice_no LIKE ? OR p.notes LIKE ?)";
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
            $totalPurchase += $amount;

            $ledgerRows[] = [
                'entry_date' => $row['entry_date'],
                'type' => 'Purchase',
                'ref_no' => $row['ref_no'],
                'description' => $row['description'],
                'debit' => $amount,
                'credit' => 0.00,
                'sort_date' => $row['entry_date'] . ' 09:00:00'
            ];
        }
        $stmt->close();
    }

    /* Supplier Payments = Credit */
    $sql = "
        SELECT
            sp.id,
            sp.payment_date AS entry_date,
            sp.payment_no AS ref_no,
            sp.reference_no,
            sp.amount,
            sp.notes,
            CONCAT('Payment', IF(sp.reference_no IS NOT NULL AND sp.reference_no != '', CONCAT(' / Ref: ', sp.reference_no), '')) AS description
        FROM supplier_payments sp
        WHERE sp.business_id = ? AND sp.supplier_id = ?
    ";
    $params = [$businessId, $supplierId];
    $types = 'ii';

    if ($fromDate !== '') {
        $sql .= " AND sp.payment_date >= ?";
        $params[] = $fromDate;
        $types .= 's';
    }
    if ($toDate !== '') {
        $sql .= " AND sp.payment_date <= ?";
        $params[] = $toDate;
        $types .= 's';
    }
    if ($search !== '') {
        $sql .= " AND (sp.payment_no LIKE ? OR sp.reference_no LIKE ? OR sp.notes LIKE ?)";
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
            $totalPayment += $amount;

            $ledgerRows[] = [
                'entry_date' => $row['entry_date'],
                'type' => 'Payment',
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
        $ledgerRows[$key]['running_type'] = $runningBalance >= 0 ? 'Cr' : 'Dr';
    }
}

$netBalance = abs($runningBalance);
$netBalanceType = $runningBalance >= 0 ? 'Cr' : 'Dr';
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
                        <h4 class="card-title mb-4">Supplier Ledger Filter</h4>

                        <form method="get" class="row g-2 align-items-end">
                            <div class="col-md-4">
                                <label class="form-label">Supplier</label>
                                <select name="supplier_id" class="form-select" required>
                                    <option value="0">Select Supplier</option>
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
                                <label class="form-label">From Date</label>
                                <input type="date" name="from_date" class="form-control" value="<?php echo h($fromDate); ?>">
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">To Date</label>
                                <input type="date" name="to_date" class="form-control" value="<?php echo h($toDate); ?>">
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Search</label>
                                <input type="text" name="search" class="form-control" placeholder="Purchase no, invoice, payment no..." value="<?php echo h($search); ?>">
                            </div>

                            <div class="col-md-1">
                                <button type="submit" class="btn btn-primary w-100">Go</button>
                            </div>

                            <div class="col-md-12">
                                <a href="supplier-ledger.php" class="btn btn-secondary">Reset</a>
                            </div>
                        </form>
                    </div>
                </div>

                <?php if ($selectedSupplier): ?>
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
                                    <h5 class="text-danger mt-2">₹ <?php echo money($totalPurchase); ?></h5>
                                    <p class="text-muted mb-0">Total Purchases</p>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="card text-center">
                                <div class="card-body">
                                    <h5 class="text-success mt-2">₹ <?php echo money($totalPayment); ?></h5>
                                    <p class="text-muted mb-0">Total Payments</p>
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
                                    <h4 class="card-title mb-0">Ledger - <?php echo h($selectedSupplier['supplier_name'] ?? ''); ?></h4>
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
                                                        <span class="badge bg-<?php echo ($row['running_type'] ?? 'Cr') === 'Cr' ? 'danger' : 'success'; ?>">
                                                            <?php echo h($row['running_type'] ?? 'Cr'); ?>
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
                                                <th class="text-danger">₹ <?php echo money($totalPurchase + ($openingBalanceType === 'Cr' ? $openingBalance : 0)); ?></th>
                                                <th class="text-success">₹ <?php echo money($totalPayment + ($openingBalanceType === 'Dr' ? $openingBalance : 0)); ?></th>
                                                <th>
                                                    ₹ <?php echo money($netBalance); ?>
                                                    <span class="badge bg-<?php echo $netBalanceType === 'Cr' ? 'danger' : 'success'; ?>">
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
                <?php elseif ($supplierId > 0): ?>
                    <div class="alert alert-warning">Supplier not found.</div>
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