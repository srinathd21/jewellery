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

$pageTitle = 'GST Report';

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
    die('Required table `sales` not found.');
}

$purchasesTableExists = tableExists($conn, 'purchases');
$customersTableExists = tableExists($conn, 'customers');
$suppliersTableExists = tableExists($conn, 'suppliers');

/* -------------------------------------------------------
   COLUMN CHECKS - SALES
------------------------------------------------------- */
$salesHasBusinessId     = hasColumn($conn, 'sales', 'business_id');
$salesHasBillNo         = hasColumn($conn, 'sales', 'bill_no');
$salesHasBillDate       = hasColumn($conn, 'sales', 'bill_date');
$salesHasCustomerId     = hasColumn($conn, 'sales', 'customer_id');
$salesHasCustomerName   = hasColumn($conn, 'sales', 'customer_name');
$salesHasBillType       = hasColumn($conn, 'sales', 'bill_type');
$salesHasTaxableAmount  = hasColumn($conn, 'sales', 'taxable_amount');
$salesHasCgstAmount     = hasColumn($conn, 'sales', 'cgst_amount');
$salesHasSgstAmount     = hasColumn($conn, 'sales', 'sgst_amount');
$salesHasIgstAmount     = hasColumn($conn, 'sales', 'igst_amount');
$salesHasGrandTotal     = hasColumn($conn, 'sales', 'grand_total');
$salesHasStatus         = hasColumn($conn, 'sales', 'status');

/* -------------------------------------------------------
   COLUMN CHECKS - PURCHASES
------------------------------------------------------- */
$purchasesHasBusinessId    = $purchasesTableExists && hasColumn($conn, 'purchases', 'business_id');
$purchasesHasPurchaseNo    = $purchasesTableExists && hasColumn($conn, 'purchases', 'purchase_no');
$purchasesHasPurchaseDate  = $purchasesTableExists && hasColumn($conn, 'purchases', 'purchase_date');
$purchasesHasSupplierId    = $purchasesTableExists && hasColumn($conn, 'purchases', 'supplier_id');
$purchasesHasTaxableAmount = $purchasesTableExists && hasColumn($conn, 'purchases', 'taxable_amount');
$purchasesHasCgstAmount    = $purchasesTableExists && hasColumn($conn, 'purchases', 'cgst_amount');
$purchasesHasSgstAmount    = $purchasesTableExists && hasColumn($conn, 'purchases', 'sgst_amount');
$purchasesHasIgstAmount    = $purchasesTableExists && hasColumn($conn, 'purchases', 'igst_amount');
$purchasesHasGrandTotal    = $purchasesTableExists && hasColumn($conn, 'purchases', 'grand_total');

/* -------------------------------------------------------
   COLUMN CHECKS - CUSTOMERS / SUPPLIERS
------------------------------------------------------- */
$customersHasId         = $customersTableExists && hasColumn($conn, 'customers', 'id');
$customersHasName       = $customersTableExists && hasColumn($conn, 'customers', 'customer_name');
$customersHasGstin      = $customersTableExists && hasColumn($conn, 'customers', 'gstin');

$suppliersHasId         = $suppliersTableExists && hasColumn($conn, 'suppliers', 'id');
$suppliersHasName       = $suppliersTableExists && hasColumn($conn, 'suppliers', 'supplier_name');
$suppliersHasGstin      = $suppliersTableExists && hasColumn($conn, 'suppliers', 'gstin');

/* -------------------------------------------------------
   FILTERS
------------------------------------------------------- */
$reportType = trim((string)($_GET['report_type'] ?? 'sales'));
$period     = trim((string)($_GET['period'] ?? 'this_month'));
$fromDate   = trim((string)($_GET['from_date'] ?? ''));
$toDate     = trim((string)($_GET['to_date'] ?? ''));
$billType   = trim((string)($_GET['bill_type'] ?? 'all'));
$search     = trim((string)($_GET['search'] ?? ''));

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

if (!in_array($reportType, ['sales', 'purchases', 'summary'], true)) {
    $reportType = 'sales';
}

/* -------------------------------------------------------
   SALES SUMMARY
------------------------------------------------------- */
$salesSummary = [
    'count' => 0,
    'taxable' => 0,
    'cgst' => 0,
    'sgst' => 0,
    'igst' => 0,
    'grand_total' => 0
];

$salesSummarySql = "
    SELECT
        COUNT(*) AS total_count,
        SUM(" . ($salesHasTaxableAmount ? "IFNULL(s.taxable_amount,0)" : "0") . ") AS total_taxable,
        SUM(" . ($salesHasCgstAmount ? "IFNULL(s.cgst_amount,0)" : "0") . ") AS total_cgst,
        SUM(" . ($salesHasSgstAmount ? "IFNULL(s.sgst_amount,0)" : "0") . ") AS total_sgst,
        SUM(" . ($salesHasIgstAmount ? "IFNULL(s.igst_amount,0)" : "0") . ") AS total_igst,
        SUM(" . ($salesHasGrandTotal ? "IFNULL(s.grand_total,0)" : "0") . ") AS total_grand
    FROM sales s
    WHERE 1=1
";
$salesSummaryParams = [];
$salesSummaryTypes = '';

if ($salesHasBusinessId) {
    $salesSummarySql .= " AND s.business_id = ? ";
    $salesSummaryParams[] = $businessId;
    $salesSummaryTypes .= 'i';
}
if ($salesHasBillDate) {
    $salesSummarySql .= " AND s.bill_date BETWEEN ? AND ? ";
    $salesSummaryParams[] = $fromDate;
    $salesSummaryParams[] = $toDate;
    $salesSummaryTypes .= 'ss';
}
if ($salesHasStatus) {
    $salesSummarySql .= " AND s.status = 'Active' ";
}
if ($salesHasBillType && $billType !== 'all' && $reportType !== 'purchases') {
    $salesSummarySql .= " AND s.bill_type = ? ";
    $salesSummaryParams[] = $billType;
    $salesSummaryTypes .= 's';
}

$stmt = $conn->prepare($salesSummarySql);
if ($stmt) {
    if (!empty($salesSummaryParams)) {
        $bind = [];
        $bind[] = $salesSummaryTypes;
        for ($i = 0; $i < count($salesSummaryParams); $i++) {
            $bind[] = &$salesSummaryParams[$i];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if ($row) {
        $salesSummary['count'] = (int)($row['total_count'] ?? 0);
        $salesSummary['taxable'] = (float)($row['total_taxable'] ?? 0);
        $salesSummary['cgst'] = (float)($row['total_cgst'] ?? 0);
        $salesSummary['sgst'] = (float)($row['total_sgst'] ?? 0);
        $salesSummary['igst'] = (float)($row['total_igst'] ?? 0);
        $salesSummary['grand_total'] = (float)($row['total_grand'] ?? 0);
    }
}

/* -------------------------------------------------------
   PURCHASE SUMMARY
------------------------------------------------------- */
$purchaseSummary = [
    'count' => 0,
    'taxable' => 0,
    'cgst' => 0,
    'sgst' => 0,
    'igst' => 0,
    'grand_total' => 0
];

if ($purchasesTableExists) {
    $purchaseSummarySql = "
        SELECT
            COUNT(*) AS total_count,
            SUM(" . ($purchasesHasTaxableAmount ? "IFNULL(p.taxable_amount,0)" : "0") . ") AS total_taxable,
            SUM(" . ($purchasesHasCgstAmount ? "IFNULL(p.cgst_amount,0)" : "0") . ") AS total_cgst,
            SUM(" . ($purchasesHasSgstAmount ? "IFNULL(p.sgst_amount,0)" : "0") . ") AS total_sgst,
            SUM(" . ($purchasesHasIgstAmount ? "IFNULL(p.igst_amount,0)" : "0") . ") AS total_igst,
            SUM(" . ($purchasesHasGrandTotal ? "IFNULL(p.grand_total,0)" : "0") . ") AS total_grand
        FROM purchases p
        WHERE 1=1
    ";
    $purchaseParams = [];
    $purchaseTypes = '';

    if ($purchasesHasBusinessId) {
        $purchaseSummarySql .= " AND p.business_id = ? ";
        $purchaseParams[] = $businessId;
        $purchaseTypes .= 'i';
    }
    if ($purchasesHasPurchaseDate) {
        $purchaseSummarySql .= " AND p.purchase_date BETWEEN ? AND ? ";
        $purchaseParams[] = $fromDate;
        $purchaseParams[] = $toDate;
        $purchaseTypes .= 'ss';
    }

    $stmt = $conn->prepare($purchaseSummarySql);
    if ($stmt) {
        if (!empty($purchaseParams)) {
            $bind = [];
            $bind[] = $purchaseTypes;
            for ($i = 0; $i < count($purchaseParams); $i++) {
                $bind[] = &$purchaseParams[$i];
            }
            call_user_func_array([$stmt, 'bind_param'], $bind);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if ($row) {
            $purchaseSummary['count'] = (int)($row['total_count'] ?? 0);
            $purchaseSummary['taxable'] = (float)($row['total_taxable'] ?? 0);
            $purchaseSummary['cgst'] = (float)($row['total_cgst'] ?? 0);
            $purchaseSummary['sgst'] = (float)($row['total_sgst'] ?? 0);
            $purchaseSummary['igst'] = (float)($row['total_igst'] ?? 0);
            $purchaseSummary['grand_total'] = (float)($row['total_grand'] ?? 0);
        }
    }
}

/* -------------------------------------------------------
   NET GST
------------------------------------------------------- */
$netCgst = $salesSummary['cgst'] - $purchaseSummary['cgst'];
$netSgst = $salesSummary['sgst'] - $purchaseSummary['sgst'];
$netIgst = $salesSummary['igst'] - $purchaseSummary['igst'];
$netTotalGst = $netCgst + $netSgst + $netIgst;

/* -------------------------------------------------------
   SALES LIST
------------------------------------------------------- */
$salesRows = [];
if ($reportType === 'sales' || $reportType === 'summary') {
    $salesSql = "
        SELECT
            s.id,
            " . ($salesHasBillNo ? "s.bill_no," : "CONCAT('SALE-', s.id) AS bill_no,") . "
            " . ($salesHasBillDate ? "s.bill_date," : "NULL AS bill_date,") . "
            " . ($salesHasBillType ? "s.bill_type," : "'Retail' AS bill_type,") . "
            " . ($salesHasCustomerName ? "s.customer_name," : "'' AS customer_name,") . "
            " . ($salesHasTaxableAmount ? "s.taxable_amount," : "0 AS taxable_amount,") . "
            " . ($salesHasCgstAmount ? "s.cgst_amount," : "0 AS cgst_amount,") . "
            " . ($salesHasSgstAmount ? "s.sgst_amount," : "0 AS sgst_amount,") . "
            " . ($salesHasIgstAmount ? "s.igst_amount," : "0 AS igst_amount,") . "
            " . ($salesHasGrandTotal ? "s.grand_total," : "0 AS grand_total,") . "
            " . ($customersTableExists && $customersHasId && $customersHasName && $salesHasCustomerId ? "c.customer_name AS customer_master_name," : "'' AS customer_master_name,") . "
            " . ($customersTableExists && $customersHasGstin && $salesHasCustomerId ? "c.gstin AS customer_gstin" : "'' AS customer_gstin") . "
        FROM sales s
    ";

    if ($customersTableExists && $customersHasId && $salesHasCustomerId) {
        $salesSql .= " LEFT JOIN customers c ON c.id = s.customer_id ";
    }

    $salesSql .= " WHERE 1=1 ";
    $listParams = [];
    $listTypes = '';

    if ($salesHasBusinessId) {
        $salesSql .= " AND s.business_id = ? ";
        $listParams[] = $businessId;
        $listTypes .= 'i';
    }
    if ($salesHasBillDate) {
        $salesSql .= " AND s.bill_date BETWEEN ? AND ? ";
        $listParams[] = $fromDate;
        $listParams[] = $toDate;
        $listTypes .= 'ss';
    }
    if ($salesHasStatus) {
        $salesSql .= " AND s.status = 'Active' ";
    }
    if ($salesHasBillType && $billType !== 'all') {
        $salesSql .= " AND s.bill_type = ? ";
        $listParams[] = $billType;
        $listTypes .= 's';
    }
    if ($search !== '') {
        $like = '%' . $search . '%';
        $salesSql .= " AND (";
        $searchParts = [];

        if ($salesHasBillNo) {
            $searchParts[] = "s.bill_no LIKE ?";
            $listParams[] = $like;
            $listTypes .= 's';
        }
        if ($salesHasCustomerName) {
            $searchParts[] = "s.customer_name LIKE ?";
            $listParams[] = $like;
            $listTypes .= 's';
        }
        if ($customersTableExists && $customersHasName && $salesHasCustomerId) {
            $searchParts[] = "c.customer_name LIKE ?";
            $listParams[] = $like;
            $listTypes .= 's';
        }
        if ($customersTableExists && $customersHasGstin && $salesHasCustomerId) {
            $searchParts[] = "c.gstin LIKE ?";
            $listParams[] = $like;
            $listTypes .= 's';
        }

        if (empty($searchParts)) {
            $salesSql .= "1=1";
        } else {
            $salesSql .= implode(' OR ', $searchParts);
        }
        $salesSql .= ")";
    }

    $salesSql .= " ORDER BY " . ($salesHasBillDate ? "s.bill_date DESC," : "") . " s.id DESC";

    $stmt = $conn->prepare($salesSql);
    if ($stmt) {
        if (!empty($listParams)) {
            $bind = [];
            $bind[] = $listTypes;
            for ($i = 0; $i < count($listParams); $i++) {
                $bind[] = &$listParams[$i];
            }
            call_user_func_array([$stmt, 'bind_param'], $bind);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && $row = $res->fetch_assoc()) {
            $salesRows[] = $row;
        }
        $stmt->close();
    }
}

/* -------------------------------------------------------
   PURCHASE LIST
------------------------------------------------------- */
$purchaseRows = [];
if (($reportType === 'purchases' || $reportType === 'summary') && $purchasesTableExists) {
    $purchaseSql = "
        SELECT
            p.id,
            " . ($purchasesHasPurchaseNo ? "p.purchase_no," : "CONCAT('PUR-', p.id) AS purchase_no,") . "
            " . ($purchasesHasPurchaseDate ? "p.purchase_date," : "NULL AS purchase_date,") . "
            " . ($purchasesHasTaxableAmount ? "p.taxable_amount," : "0 AS taxable_amount,") . "
            " . ($purchasesHasCgstAmount ? "p.cgst_amount," : "0 AS cgst_amount,") . "
            " . ($purchasesHasSgstAmount ? "p.sgst_amount," : "0 AS sgst_amount,") . "
            " . ($purchasesHasIgstAmount ? "p.igst_amount," : "0 AS igst_amount,") . "
            " . ($purchasesHasGrandTotal ? "p.grand_total," : "0 AS grand_total,") . "
            " . ($suppliersTableExists && $suppliersHasId && $suppliersHasName && $purchasesHasSupplierId ? "sp.supplier_name," : "'' AS supplier_name,") . "
            " . ($suppliersTableExists && $suppliersHasGstin && $purchasesHasSupplierId ? "sp.gstin AS supplier_gstin" : "'' AS supplier_gstin") . "
        FROM purchases p
    ";

    if ($suppliersTableExists && $suppliersHasId && $purchasesHasSupplierId) {
        $purchaseSql .= " LEFT JOIN suppliers sp ON sp.id = p.supplier_id ";
    }

    $purchaseSql .= " WHERE 1=1 ";
    $listParams = [];
    $listTypes = '';

    if ($purchasesHasBusinessId) {
        $purchaseSql .= " AND p.business_id = ? ";
        $listParams[] = $businessId;
        $listTypes .= 'i';
    }
    if ($purchasesHasPurchaseDate) {
        $purchaseSql .= " AND p.purchase_date BETWEEN ? AND ? ";
        $listParams[] = $fromDate;
        $listParams[] = $toDate;
        $listTypes .= 'ss';
    }
    if ($search !== '') {
        $like = '%' . $search . '%';
        $purchaseSql .= " AND (";
        $searchParts = [];

        if ($purchasesHasPurchaseNo) {
            $searchParts[] = "p.purchase_no LIKE ?";
            $listParams[] = $like;
            $listTypes .= 's';
        }
        if ($suppliersTableExists && $suppliersHasName && $purchasesHasSupplierId) {
            $searchParts[] = "sp.supplier_name LIKE ?";
            $listParams[] = $like;
            $listTypes .= 's';
        }
        if ($suppliersTableExists && $suppliersHasGstin && $purchasesHasSupplierId) {
            $searchParts[] = "sp.gstin LIKE ?";
            $listParams[] = $like;
            $listTypes .= 's';
        }

        if (empty($searchParts)) {
            $purchaseSql .= "1=1";
        } else {
            $purchaseSql .= implode(' OR ', $searchParts);
        }
        $purchaseSql .= ")";
    }

    $purchaseSql .= " ORDER BY " . ($purchasesHasPurchaseDate ? "p.purchase_date DESC," : "") . " p.id DESC";

    $stmt = $conn->prepare($purchaseSql);
    if ($stmt) {
        if (!empty($listParams)) {
            $bind = [];
            $bind[] = $listTypes;
            for ($i = 0; $i < count($listParams); $i++) {
                $bind[] = &$listParams[$i];
            }
            call_user_func_array([$stmt, 'bind_param'], $bind);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && $row = $res->fetch_assoc()) {
            $purchaseRows[] = $row;
        }
        $stmt->close();
    }
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
                                <label class="form-label">Report Type</label>
                                <select name="report_type" class="form-select">
                                    <option value="sales" <?php echo $reportType === 'sales' ? 'selected' : ''; ?>>Sales GST</option>
                                    <option value="purchases" <?php echo $reportType === 'purchases' ? 'selected' : ''; ?>>Purchase GST</option>
                                    <option value="summary" <?php echo $reportType === 'summary' ? 'selected' : ''; ?>>Summary</option>
                                </select>
                            </div>

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

                            <?php if ($salesHasBillType): ?>
                                <div class="col-md-2">
                                    <label class="form-label">Bill Type</label>
                                    <select name="bill_type" class="form-select">
                                        <option value="all" <?php echo $billType === 'all' ? 'selected' : ''; ?>>All</option>
                                        <option value="Retail" <?php echo $billType === 'Retail' ? 'selected' : ''; ?>>Retail</option>
                                        <option value="GST" <?php echo $billType === 'GST' ? 'selected' : ''; ?>>GST</option>
                                        <option value="Estimate" <?php echo $billType === 'Estimate' ? 'selected' : ''; ?>>Estimate</option>
                                        <option value="Exchange" <?php echo $billType === 'Exchange' ? 'selected' : ''; ?>>Exchange</option>
                                    </select>
                                </div>
                            <?php endif; ?>

                            <div class="col-md-2">
                                <label class="form-label">Search</label>
                                <input type="text" name="search" class="form-control" value="<?php echo h($search); ?>" placeholder="Bill / GSTIN / Name">
                            </div>

                            <div class="col-md-12 d-flex gap-2 flex-wrap">
                                <button type="submit" class="btn btn-primary">Generate Report</button>
                                <a href="gst-report.php" class="btn btn-secondary">Reset</a>
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
                                <h3 class="text-primary mt-2">₹<?php echo number_format($salesSummary['taxable'], 2); ?></h3>
                                <p class="text-muted mb-0">Sales Taxable Value</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-success mt-2">₹<?php echo number_format($salesSummary['cgst'] + $salesSummary['sgst'] + $salesSummary['igst'], 2); ?></h3>
                                <p class="text-muted mb-0">Output GST</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-warning mt-2">₹<?php echo number_format($purchaseSummary['cgst'] + $purchaseSummary['sgst'] + $purchaseSummary['igst'], 2); ?></h3>
                                <p class="text-muted mb-0">Input GST</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="mt-2 <?php echo $netTotalGst >= 0 ? 'text-danger' : 'text-success'; ?>">
                                    ₹<?php echo number_format($netTotalGst, 2); ?>
                                </h3>
                                <p class="text-muted mb-0">Net GST Payable / Credit</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-lg-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Sales GST Summary</h5>
                            </div>
                            <div class="card-body">
                                <table class="table table-bordered align-middle mb-0">
                                    <tbody>
                                        <tr>
                                            <th>Total Sales Entries</th>
                                            <td class="text-end"><?php echo (int)$salesSummary['count']; ?></td>
                                        </tr>
                                        <tr>
                                            <th>Taxable Value</th>
                                            <td class="text-end">₹<?php echo number_format($salesSummary['taxable'], 2); ?></td>
                                        </tr>
                                        <tr>
                                            <th>CGST</th>
                                            <td class="text-end">₹<?php echo number_format($salesSummary['cgst'], 2); ?></td>
                                        </tr>
                                        <tr>
                                            <th>SGST</th>
                                            <td class="text-end">₹<?php echo number_format($salesSummary['sgst'], 2); ?></td>
                                        </tr>
                                        <tr>
                                            <th>IGST</th>
                                            <td class="text-end">₹<?php echo number_format($salesSummary['igst'], 2); ?></td>
                                        </tr>
                                        <tr class="table-light">
                                            <th>Total Invoice Value</th>
                                            <td class="text-end fw-bold">₹<?php echo number_format($salesSummary['grand_total'], 2); ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <?php if ($purchasesTableExists): ?>
                    <div class="col-lg-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Purchase GST Summary</h5>
                            </div>
                            <div class="card-body">
                                <table class="table table-bordered align-middle mb-0">
                                    <tbody>
                                        <tr>
                                            <th>Total Purchase Entries</th>
                                            <td class="text-end"><?php echo (int)$purchaseSummary['count']; ?></td>
                                        </tr>
                                        <tr>
                                            <th>Taxable Value</th>
                                            <td class="text-end">₹<?php echo number_format($purchaseSummary['taxable'], 2); ?></td>
                                        </tr>
                                        <tr>
                                            <th>CGST</th>
                                            <td class="text-end">₹<?php echo number_format($purchaseSummary['cgst'], 2); ?></td>
                                        </tr>
                                        <tr>
                                            <th>SGST</th>
                                            <td class="text-end">₹<?php echo number_format($purchaseSummary['sgst'], 2); ?></td>
                                        </tr>
                                        <tr>
                                            <th>IGST</th>
                                            <td class="text-end">₹<?php echo number_format($purchaseSummary['igst'], 2); ?></td>
                                        </tr>
                                        <tr class="table-light">
                                            <th>Total Purchase Value</th>
                                            <td class="text-end fw-bold">₹<?php echo number_format($purchaseSummary['grand_total'], 2); ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ($reportType === 'summary'): ?>
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Net GST Summary</h5>
                        </div>
                        <div class="card-body">
                            <table class="table table-bordered align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Tax Type</th>
                                        <th>Output GST</th>
                                        <th>Input GST</th>
                                        <th>Net Payable / Credit</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>CGST</td>
                                        <td>₹<?php echo number_format($salesSummary['cgst'], 2); ?></td>
                                        <td>₹<?php echo number_format($purchaseSummary['cgst'], 2); ?></td>
                                        <td class="<?php echo $netCgst >= 0 ? 'text-danger' : 'text-success'; ?>">
                                            <strong>₹<?php echo number_format($netCgst, 2); ?></strong>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>SGST</td>
                                        <td>₹<?php echo number_format($salesSummary['sgst'], 2); ?></td>
                                        <td>₹<?php echo number_format($purchaseSummary['sgst'], 2); ?></td>
                                        <td class="<?php echo $netSgst >= 0 ? 'text-danger' : 'text-success'; ?>">
                                            <strong>₹<?php echo number_format($netSgst, 2); ?></strong>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>IGST</td>
                                        <td>₹<?php echo number_format($salesSummary['igst'], 2); ?></td>
                                        <td>₹<?php echo number_format($purchaseSummary['igst'], 2); ?></td>
                                        <td class="<?php echo $netIgst >= 0 ? 'text-danger' : 'text-success'; ?>">
                                            <strong>₹<?php echo number_format($netIgst, 2); ?></strong>
                                        </td>
                                    </tr>
                                </tbody>
                                <tfoot>
                                    <tr class="table-light">
                                        <th>Total</th>
                                        <th>₹<?php echo number_format($salesSummary['cgst'] + $salesSummary['sgst'] + $salesSummary['igst'], 2); ?></th>
                                        <th>₹<?php echo number_format($purchaseSummary['cgst'] + $purchaseSummary['sgst'] + $purchaseSummary['igst'], 2); ?></th>
                                        <th class="<?php echo $netTotalGst >= 0 ? 'text-danger' : 'text-success'; ?>">
                                            <strong>₹<?php echo number_format($netTotalGst, 2); ?></strong>
                                        </th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($reportType === 'sales' || $reportType === 'summary'): ?>
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Sales GST Details</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Bill No</th>
                                            <th>Date</th>
                                            <th>Bill Type</th>
                                            <th>Customer</th>
                                            <th>GSTIN</th>
                                            <th>Taxable Value</th>
                                            <th>CGST</th>
                                            <th>SGST</th>
                                            <th>IGST</th>
                                            <th>Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($salesRows)): ?>
                                            <?php foreach ($salesRows as $index => $row): ?>
                                                <tr>
                                                    <td><?php echo $index + 1; ?></td>
                                                    <td><strong><?php echo h($row['bill_no'] ?? ''); ?></strong></td>
                                                    <td>
                                                        <?php
                                                        if (!empty($row['bill_date'])) {
                                                            echo date('d-m-Y', strtotime($row['bill_date']));
                                                        } else {
                                                            echo '-';
                                                        }
                                                        ?>
                                                    </td>
                                                    <td><?php echo h($row['bill_type'] ?? ''); ?></td>
                                                    <td>
                                                        <?php
                                                        echo h(
                                                            !empty($row['customer_master_name'])
                                                                ? $row['customer_master_name']
                                                                : ($row['customer_name'] ?? '')
                                                        );
                                                        ?>
                                                    </td>
                                                    <td><?php echo h($row['customer_gstin'] ?? ''); ?></td>
                                                    <td>₹<?php echo number_format((float)($row['taxable_amount'] ?? 0), 2); ?></td>
                                                    <td>₹<?php echo number_format((float)($row['cgst_amount'] ?? 0), 2); ?></td>
                                                    <td>₹<?php echo number_format((float)($row['sgst_amount'] ?? 0), 2); ?></td>
                                                    <td>₹<?php echo number_format((float)($row['igst_amount'] ?? 0), 2); ?></td>
                                                    <td><strong>₹<?php echo number_format((float)($row['grand_total'] ?? 0), 2); ?></strong></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="11" class="text-center text-muted">No sales GST records found.</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                    <?php if (!empty($salesRows)): ?>
                                        <tfoot>
                                            <tr class="table-light">
                                                <th colspan="6" class="text-end">Total</th>
                                                <th>₹<?php echo number_format($salesSummary['taxable'], 2); ?></th>
                                                <th>₹<?php echo number_format($salesSummary['cgst'], 2); ?></th>
                                                <th>₹<?php echo number_format($salesSummary['sgst'], 2); ?></th>
                                                <th>₹<?php echo number_format($salesSummary['igst'], 2); ?></th>
                                                <th>₹<?php echo number_format($salesSummary['grand_total'], 2); ?></th>
                                            </tr>
                                        </tfoot>
                                    <?php endif; ?>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (($reportType === 'purchases' || $reportType === 'summary') && $purchasesTableExists): ?>
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Purchase GST Details</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Purchase No</th>
                                            <th>Date</th>
                                            <th>Supplier</th>
                                            <th>GSTIN</th>
                                            <th>Taxable Value</th>
                                            <th>CGST</th>
                                            <th>SGST</th>
                                            <th>IGST</th>
                                            <th>Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($purchaseRows)): ?>
                                            <?php foreach ($purchaseRows as $index => $row): ?>
                                                <tr>
                                                    <td><?php echo $index + 1; ?></td>
                                                    <td><strong><?php echo h($row['purchase_no'] ?? ''); ?></strong></td>
                                                    <td>
                                                        <?php
                                                        if (!empty($row['purchase_date'])) {
                                                            echo date('d-m-Y', strtotime($row['purchase_date']));
                                                        } else {
                                                            echo '-';
                                                        }
                                                        ?>
                                                    </td>
                                                    <td><?php echo h($row['supplier_name'] ?? ''); ?></td>
                                                    <td><?php echo h($row['supplier_gstin'] ?? ''); ?></td>
                                                    <td>₹<?php echo number_format((float)($row['taxable_amount'] ?? 0), 2); ?></td>
                                                    <td>₹<?php echo number_format((float)($row['cgst_amount'] ?? 0), 2); ?></td>
                                                    <td>₹<?php echo number_format((float)($row['sgst_amount'] ?? 0), 2); ?></td>
                                                    <td>₹<?php echo number_format((float)($row['igst_amount'] ?? 0), 2); ?></td>
                                                    <td><strong>₹<?php echo number_format((float)($row['grand_total'] ?? 0), 2); ?></strong></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="10" class="text-center text-muted">No purchase GST records found.</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                    <?php if (!empty($purchaseRows)): ?>
                                        <tfoot>
                                            <tr class="table-light">
                                                <th colspan="5" class="text-end">Total</th>
                                                <th>₹<?php echo number_format($purchaseSummary['taxable'], 2); ?></th>
                                                <th>₹<?php echo number_format($purchaseSummary['cgst'], 2); ?></th>
                                                <th>₹<?php echo number_format($purchaseSummary['sgst'], 2); ?></th>
                                                <th>₹<?php echo number_format($purchaseSummary['igst'], 2); ?></th>
                                                <th>₹<?php echo number_format($purchaseSummary['grand_total'], 2); ?></th>
                                            </tr>
                                        </tfoot>
                                    <?php endif; ?>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

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