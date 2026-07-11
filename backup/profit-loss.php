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

$pageTitle = 'Profit & Loss';

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
if (!tableExists($conn, 'sales')) {
    die('Required table `sales` not found.');
}
if (!tableExists($conn, 'sale_items')) {
    die('Required table `sale_items` not found.');
}

$expensesTableExists = tableExists($conn, 'expenses');
$purchasesTableExists = tableExists($conn, 'purchases');
$customerPaymentsTableExists = tableExists($conn, 'customer_payments');
$supplierPaymentsTableExists = tableExists($conn, 'supplier_payments');

/* -------------------------------------------------------
   COLUMN CHECKS
------------------------------------------------------- */
$salesHasBusinessId      = hasColumn($conn, 'sales', 'business_id');
$salesHasBillDate        = hasColumn($conn, 'sales', 'bill_date');
$salesHasGrandTotal      = hasColumn($conn, 'sales', 'grand_total');
$salesHasTaxableAmount   = hasColumn($conn, 'sales', 'taxable_amount');
$salesHasCgstAmount      = hasColumn($conn, 'sales', 'cgst_amount');
$salesHasSgstAmount      = hasColumn($conn, 'sales', 'sgst_amount');
$salesHasIgstAmount      = hasColumn($conn, 'sales', 'igst_amount');
$salesHasDiscountAmount  = hasColumn($conn, 'sales', 'discount_amount');
$salesHasPaidAmount      = hasColumn($conn, 'sales', 'paid_amount');
$salesHasBalanceAmount   = hasColumn($conn, 'sales', 'balance_amount');
$salesHasStatus          = hasColumn($conn, 'sales', 'status');
$salesHasPaymentStatus   = hasColumn($conn, 'sales', 'payment_status');

$saleItemsHasBusinessId  = hasColumn($conn, 'sale_items', 'business_id');
$saleItemsHasSaleId      = hasColumn($conn, 'sale_items', 'sale_id');
$saleItemsHasQty         = hasColumn($conn, 'sale_items', 'qty');
$saleItemsHasMetalValue  = hasColumn($conn, 'sale_items', 'metal_value');
$saleItemsHasMakingCharge= hasColumn($conn, 'sale_items', 'making_charge');
$saleItemsHasWastageAmt  = hasColumn($conn, 'sale_items', 'wastage_amount');
$saleItemsHasStoneCharge = hasColumn($conn, 'sale_items', 'stone_charge');
$saleItemsHasOtherCharge = hasColumn($conn, 'sale_items', 'other_charge');
$saleItemsHasDiscountAmt = hasColumn($conn, 'sale_items', 'discount_amount');
$saleItemsHasTaxableAmt  = hasColumn($conn, 'sale_items', 'taxable_amount');
$saleItemsHasTotalAmount = hasColumn($conn, 'sale_items', 'total_amount');
$saleItemsHasProductId   = hasColumn($conn, 'sale_items', 'product_id');

$productsTableExists = tableExists($conn, 'products');
$productsHasId = $productsTableExists && hasColumn($conn, 'products', 'id');
$productsHasPurchaseRate = $productsTableExists && hasColumn($conn, 'products', 'purchase_rate');
$productsHasSaleRate = $productsTableExists && hasColumn($conn, 'products', 'sale_rate');
$productsHasProductName = $productsTableExists && hasColumn($conn, 'products', 'product_name');

$expensesHasBusinessId   = $expensesTableExists && hasColumn($conn, 'expenses', 'business_id');
$expensesHasDate         = $expensesTableExists && hasColumn($conn, 'expenses', 'expense_date');
$expensesHasAmount       = $expensesTableExists && hasColumn($conn, 'expenses', 'amount');
$expensesHasCategory     = $expensesTableExists && hasColumn($conn, 'expenses', 'expense_category');
$expensesHasDescription  = $expensesTableExists && hasColumn($conn, 'expenses', 'description');

$purchasesHasBusinessId  = $purchasesTableExists && hasColumn($conn, 'purchases', 'business_id');
$purchasesHasDate        = $purchasesTableExists && hasColumn($conn, 'purchases', 'purchase_date');
$purchasesHasGrandTotal  = $purchasesTableExists && hasColumn($conn, 'purchases', 'grand_total');
$purchasesHasTaxableAmt  = $purchasesTableExists && hasColumn($conn, 'purchases', 'taxable_amount');
$purchasesHasStatus      = $purchasesTableExists && hasColumn($conn, 'purchases', 'payment_status');

$customerPaymentsHasBusinessId = $customerPaymentsTableExists && hasColumn($conn, 'customer_payments', 'business_id');
$customerPaymentsHasDate       = $customerPaymentsTableExists && hasColumn($conn, 'customer_payments', 'receipt_date');
$customerPaymentsHasAmount     = $customerPaymentsTableExists && hasColumn($conn, 'customer_payments', 'amount');

$supplierPaymentsHasBusinessId = $supplierPaymentsTableExists && hasColumn($conn, 'supplier_payments', 'business_id');
$supplierPaymentsHasDate       = $supplierPaymentsTableExists && hasColumn($conn, 'supplier_payments', 'payment_date');
$supplierPaymentsHasAmount     = $supplierPaymentsTableExists && hasColumn($conn, 'supplier_payments', 'amount');

/* -------------------------------------------------------
   FILTERS
------------------------------------------------------- */
$period = trim((string)($_GET['period'] ?? 'this_month'));
$fromDate = trim((string)($_GET['from_date'] ?? ''));
$toDate   = trim((string)($_GET['to_date'] ?? ''));

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
    $fromDate = date('Y-m-01');
    $toDate = $today;
    $period = 'this_month';
}

if ($fromDate > $toDate) {
    $tmp = $fromDate;
    $fromDate = $toDate;
    $toDate = $tmp;
}

/* -------------------------------------------------------
   SUMMARY VARIABLES
------------------------------------------------------- */
$totalSales = 0.00;
$totalSalesTaxable = 0.00;
$totalSalesTax = 0.00;
$totalDiscount = 0.00;
$totalCollections = 0.00;
$totalOutstanding = 0.00;

$totalExpenses = 0.00;
$totalPurchases = 0.00;
$totalSupplierPayments = 0.00;

$estimatedCOGS = 0.00;
$grossProfit = 0.00;
$netProfit = 0.00;

/* -------------------------------------------------------
   SALES SUMMARY
------------------------------------------------------- */
$salesSql = "
    SELECT
        SUM(" . ($salesHasGrandTotal ? "IFNULL(grand_total,0)" : "0") . ") AS total_sales,
        SUM(" . ($salesHasTaxableAmount ? "IFNULL(taxable_amount,0)" : "0") . ") AS taxable_sales,
        SUM(" .
            (($salesHasCgstAmount ? "IFNULL(cgst_amount,0)" : "0") . " + " .
             ($salesHasSgstAmount ? "IFNULL(sgst_amount,0)" : "0") . " + " .
             ($salesHasIgstAmount ? "IFNULL(igst_amount,0)" : "0"))
        . ") AS total_tax,
        SUM(" . ($salesHasDiscountAmount ? "IFNULL(discount_amount,0)" : "0") . ") AS total_discount,
        SUM(" . ($salesHasPaidAmount ? "IFNULL(paid_amount,0)" : "0") . ") AS total_collections,
        SUM(" . ($salesHasBalanceAmount ? "IFNULL(balance_amount,0)" : "0") . ") AS total_outstanding
    FROM sales
    WHERE 1=1
";
$salesParams = [];
$salesTypes = '';

if ($salesHasBusinessId) {
    $salesSql .= " AND business_id = ? ";
    $salesParams[] = $businessId;
    $salesTypes .= 'i';
}
if ($salesHasBillDate) {
    $salesSql .= " AND bill_date BETWEEN ? AND ? ";
    $salesParams[] = $fromDate;
    $salesParams[] = $toDate;
    $salesTypes .= 'ss';
}
if ($salesHasStatus) {
    $salesSql .= " AND status = 'Active' ";
}

$stmt = $conn->prepare($salesSql);
if ($stmt) {
    if (!empty($salesParams)) {
        $bind = [];
        $bind[] = $salesTypes;
        for ($i = 0; $i < count($salesParams); $i++) {
            $bind[] = &$salesParams[$i];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if ($row) {
        $totalSales = (float)($row['total_sales'] ?? 0);
        $totalSalesTaxable = (float)($row['taxable_sales'] ?? 0);
        $totalSalesTax = (float)($row['total_tax'] ?? 0);
        $totalDiscount = (float)($row['total_discount'] ?? 0);
        $totalCollections = (float)($row['total_collections'] ?? 0);
        $totalOutstanding = (float)($row['total_outstanding'] ?? 0);
    }
}

/* -------------------------------------------------------
   ESTIMATED COGS FROM SALE ITEMS + PRODUCTS.PURCHASE_RATE
------------------------------------------------------- */
if ($productsTableExists && $saleItemsHasSaleId && $saleItemsHasProductId && $productsHasId && $productsHasPurchaseRate) {
    $cogsSql = "
        SELECT
            SUM(IFNULL(si.qty,0) * IFNULL(p.purchase_rate,0)) AS estimated_cogs
        FROM sale_items si
        INNER JOIN sales s ON s.id = si.sale_id
        LEFT JOIN products p ON p.id = si.product_id
        WHERE 1=1
    ";
    $cogsParams = [];
    $cogsTypes = '';

    if ($saleItemsHasBusinessId) {
        $cogsSql .= " AND si.business_id = ? ";
        $cogsParams[] = $businessId;
        $cogsTypes .= 'i';
    } elseif ($salesHasBusinessId) {
        $cogsSql .= " AND s.business_id = ? ";
        $cogsParams[] = $businessId;
        $cogsTypes .= 'i';
    }

    if ($salesHasBillDate) {
        $cogsSql .= " AND s.bill_date BETWEEN ? AND ? ";
        $cogsParams[] = $fromDate;
        $cogsParams[] = $toDate;
        $cogsTypes .= 'ss';
    }

    if ($salesHasStatus) {
        $cogsSql .= " AND s.status = 'Active' ";
    }

    $stmt = $conn->prepare($cogsSql);
    if ($stmt) {
        if (!empty($cogsParams)) {
            $bind = [];
            $bind[] = $cogsTypes;
            for ($i = 0; $i < count($cogsParams); $i++) {
                $bind[] = &$cogsParams[$i];
            }
            call_user_func_array([$stmt, 'bind_param'], $bind);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        $estimatedCOGS = (float)($row['estimated_cogs'] ?? 0);
    }
}

/* -------------------------------------------------------
   EXPENSES SUMMARY
------------------------------------------------------- */
if ($expensesTableExists) {
    $expensesSql = "
        SELECT SUM(" . ($expensesHasAmount ? "IFNULL(amount,0)" : "0") . ") AS total_expenses
        FROM expenses
        WHERE 1=1
    ";
    $expensesParams = [];
    $expensesTypes = '';

    if ($expensesHasBusinessId) {
        $expensesSql .= " AND business_id = ? ";
        $expensesParams[] = $businessId;
        $expensesTypes .= 'i';
    }
    if ($expensesHasDate) {
        $expensesSql .= " AND expense_date BETWEEN ? AND ? ";
        $expensesParams[] = $fromDate;
        $expensesParams[] = $toDate;
        $expensesTypes .= 'ss';
    }

    $stmt = $conn->prepare($expensesSql);
    if ($stmt) {
        if (!empty($expensesParams)) {
            $bind = [];
            $bind[] = $expensesTypes;
            for ($i = 0; $i < count($expensesParams); $i++) {
                $bind[] = &$expensesParams[$i];
            }
            call_user_func_array([$stmt, 'bind_param'], $bind);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        $totalExpenses = (float)($row['total_expenses'] ?? 0);
    }
}

/* -------------------------------------------------------
   PURCHASES SUMMARY
------------------------------------------------------- */
if ($purchasesTableExists) {
    $purchaseSql = "
        SELECT SUM(" . ($purchasesHasGrandTotal ? "IFNULL(grand_total,0)" : ($purchasesHasTaxableAmt ? "IFNULL(taxable_amount,0)" : "0")) . ") AS total_purchases
        FROM purchases
        WHERE 1=1
    ";
    $purchaseParams = [];
    $purchaseTypes = '';

    if ($purchasesHasBusinessId) {
        $purchaseSql .= " AND business_id = ? ";
        $purchaseParams[] = $businessId;
        $purchaseTypes .= 'i';
    }
    if ($purchasesHasDate) {
        $purchaseSql .= " AND purchase_date BETWEEN ? AND ? ";
        $purchaseParams[] = $fromDate;
        $purchaseParams[] = $toDate;
        $purchaseTypes .= 'ss';
    }

    $stmt = $conn->prepare($purchaseSql);
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

        $totalPurchases = (float)($row['total_purchases'] ?? 0);
    }
}

/* -------------------------------------------------------
   SUPPLIER PAYMENTS SUMMARY
------------------------------------------------------- */
if ($supplierPaymentsTableExists) {
    $supSql = "
        SELECT SUM(" . ($supplierPaymentsHasAmount ? "IFNULL(amount,0)" : "0") . ") AS total_supplier_payments
        FROM supplier_payments
        WHERE 1=1
    ";
    $supParams = [];
    $supTypes = '';

    if ($supplierPaymentsHasBusinessId) {
        $supSql .= " AND business_id = ? ";
        $supParams[] = $businessId;
        $supTypes .= 'i';
    }
    if ($supplierPaymentsHasDate) {
        $supSql .= " AND payment_date BETWEEN ? AND ? ";
        $supParams[] = $fromDate;
        $supParams[] = $toDate;
        $supTypes .= 'ss';
    }

    $stmt = $conn->prepare($supSql);
    if ($stmt) {
        if (!empty($supParams)) {
            $bind = [];
            $bind[] = $supTypes;
            for ($i = 0; $i < count($supParams); $i++) {
                $bind[] = &$supParams[$i];
            }
            call_user_func_array([$stmt, 'bind_param'], $bind);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        $totalSupplierPayments = (float)($row['total_supplier_payments'] ?? 0);
    }
}

/* -------------------------------------------------------
   PROFIT CALCULATION
------------------------------------------------------- */
$grossProfit = $totalSales - $estimatedCOGS;
$netProfit = $grossProfit - $totalExpenses;

/* -------------------------------------------------------
   EXPENSE BREAKUP
------------------------------------------------------- */
$expenseBreakup = [];
if ($expensesTableExists && $expensesHasCategory && $expensesHasAmount) {
    $expBreakSql = "
        SELECT
            IFNULL(expense_category, 'Others') AS expense_category,
            SUM(IFNULL(amount,0)) AS total_amount
        FROM expenses
        WHERE 1=1
    ";
    $expBreakParams = [];
    $expBreakTypes = '';

    if ($expensesHasBusinessId) {
        $expBreakSql .= " AND business_id = ? ";
        $expBreakParams[] = $businessId;
        $expBreakTypes .= 'i';
    }
    if ($expensesHasDate) {
        $expBreakSql .= " AND expense_date BETWEEN ? AND ? ";
        $expBreakParams[] = $fromDate;
        $expBreakParams[] = $toDate;
        $expBreakTypes .= 'ss';
    }

    $expBreakSql .= " GROUP BY expense_category ORDER BY total_amount DESC";

    $stmt = $conn->prepare($expBreakSql);
    if ($stmt) {
        if (!empty($expBreakParams)) {
            $bind = [];
            $bind[] = $expBreakTypes;
            for ($i = 0; $i < count($expBreakParams); $i++) {
                $bind[] = &$expBreakParams[$i];
            }
            call_user_func_array([$stmt, 'bind_param'], $bind);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && $row = $res->fetch_assoc()) {
            $expenseBreakup[] = $row;
        }
        $stmt->close();
    }
}

/* -------------------------------------------------------
   TOP SELLING / PROFIT PRODUCTS
------------------------------------------------------- */
$productProfitRows = [];
if (
    $productsTableExists &&
    $saleItemsHasSaleId &&
    $saleItemsHasProductId &&
    $saleItemsHasQty &&
    $productsHasId &&
    $productsHasPurchaseRate
) {
    $prodProfitSql = "
        SELECT
            si.product_id,
            " . ($productsHasProductName ? "p.product_name," : "CONCAT('Product #', si.product_id) AS product_name,") . "
            SUM(IFNULL(si.qty,0)) AS total_qty,
            SUM(" . ($saleItemsHasTotalAmount ? "IFNULL(si.total_amount,0)" : "0") . ") AS total_sales_amount,
            SUM(IFNULL(si.qty,0) * IFNULL(p.purchase_rate,0)) AS total_cost_amount,
            (SUM(" . ($saleItemsHasTotalAmount ? "IFNULL(si.total_amount,0)" : "0") . ") - SUM(IFNULL(si.qty,0) * IFNULL(p.purchase_rate,0))) AS product_profit
        FROM sale_items si
        INNER JOIN sales s ON s.id = si.sale_id
        LEFT JOIN products p ON p.id = si.product_id
        WHERE si.product_id IS NOT NULL
    ";
    $prodParams = [];
    $prodTypes = '';

    if ($saleItemsHasBusinessId) {
        $prodProfitSql .= " AND si.business_id = ? ";
        $prodParams[] = $businessId;
        $prodTypes .= 'i';
    } elseif ($salesHasBusinessId) {
        $prodProfitSql .= " AND s.business_id = ? ";
        $prodParams[] = $businessId;
        $prodTypes .= 'i';
    }

    if ($salesHasBillDate) {
        $prodProfitSql .= " AND s.bill_date BETWEEN ? AND ? ";
        $prodParams[] = $fromDate;
        $prodParams[] = $toDate;
        $prodTypes .= 'ss';
    }

    if ($salesHasStatus) {
        $prodProfitSql .= " AND s.status = 'Active' ";
    }

    $prodProfitSql .= "
        GROUP BY si.product_id, " . ($productsHasProductName ? "p.product_name" : "si.product_id") . "
        ORDER BY product_profit DESC
        LIMIT 10
    ";

    $stmt = $conn->prepare($prodProfitSql);
    if ($stmt) {
        if (!empty($prodParams)) {
            $bind = [];
            $bind[] = $prodTypes;
            for ($i = 0; $i < count($prodParams); $i++) {
                $bind[] = &$prodParams[$i];
            }
            call_user_func_array([$stmt, 'bind_param'], $bind);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && $row = $res->fetch_assoc()) {
            $productProfitRows[] = $row;
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
                            <div class="col-md-3">
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

                            <div class="col-md-3 custom-date-box" style="<?php echo $period === 'custom' ? '' : 'display:none;'; ?>">
                                <label class="form-label">From Date</label>
                                <input type="date" name="from_date" class="form-control" value="<?php echo h($fromDate); ?>">
                            </div>

                            <div class="col-md-3 custom-date-box" style="<?php echo $period === 'custom' ? '' : 'display:none;'; ?>">
                                <label class="form-label">To Date</label>
                                <input type="date" name="to_date" class="form-control" value="<?php echo h($toDate); ?>">
                            </div>

                            <div class="col-md-3">
                                <button type="submit" class="btn btn-primary w-100">Generate</button>
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
                                <h3 class="text-success mt-2">₹<?php echo number_format($totalSales, 2); ?></h3>
                                <p class="text-muted mb-0">Total Sales</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-danger mt-2">₹<?php echo number_format($estimatedCOGS, 2); ?></h3>
                                <p class="text-muted mb-0">Estimated Cost of Goods Sold</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-primary mt-2">₹<?php echo number_format($grossProfit, 2); ?></h3>
                                <p class="text-muted mb-0">Gross Profit</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="mt-2 <?php echo $netProfit >= 0 ? 'text-success' : 'text-danger'; ?>">
                                    ₹<?php echo number_format($netProfit, 2); ?>
                                </h3>
                                <p class="text-muted mb-0">Net Profit</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-warning mt-2">₹<?php echo number_format($totalExpenses, 2); ?></h3>
                                <p class="text-muted mb-0">Operating Expenses</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-info mt-2">₹<?php echo number_format($totalCollections, 2); ?></h3>
                                <p class="text-muted mb-0">Collections Received</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-danger mt-2">₹<?php echo number_format($totalOutstanding, 2); ?></h3>
                                <p class="text-muted mb-0">Outstanding from Sales</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-secondary mt-2">₹<?php echo number_format($totalPurchases, 2); ?></h3>
                                <p class="text-muted mb-0">Purchases Value</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-lg-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Profit & Loss Statement</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-bordered align-middle mb-0">
                                        <tbody>
                                            <tr>
                                                <th>Total Sales</th>
                                                <td class="text-end">₹<?php echo number_format($totalSales, 2); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Sales Taxable Amount</th>
                                                <td class="text-end">₹<?php echo number_format($totalSalesTaxable, 2); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Sales Tax</th>
                                                <td class="text-end">₹<?php echo number_format($totalSalesTax, 2); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Discount Given</th>
                                                <td class="text-end">₹<?php echo number_format($totalDiscount, 2); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Estimated Cost of Goods Sold</th>
                                                <td class="text-end text-danger">₹<?php echo number_format($estimatedCOGS, 2); ?></td>
                                            </tr>
                                            <tr class="table-light">
                                                <th>Gross Profit</th>
                                                <td class="text-end fw-bold">₹<?php echo number_format($grossProfit, 2); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Operating Expenses</th>
                                                <td class="text-end text-danger">₹<?php echo number_format($totalExpenses, 2); ?></td>
                                            </tr>
                                            <tr class="table-<?php echo $netProfit >= 0 ? 'success' : 'danger'; ?>">
                                                <th>Net Profit / Loss</th>
                                                <td class="text-end fw-bold">₹<?php echo number_format($netProfit, 2); ?></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>

                                <div class="mt-3">
                                    <small class="text-muted">
                                        Note: Cost of goods sold is estimated using <strong>sale_items.qty × products.purchase_rate</strong>.
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Cash Flow Snapshot</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-bordered align-middle mb-0">
                                        <tbody>
                                            <tr>
                                                <th>Collections from Customers</th>
                                                <td class="text-end">₹<?php echo number_format($totalCollections, 2); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Outstanding from Sales</th>
                                                <td class="text-end">₹<?php echo number_format($totalOutstanding, 2); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Supplier Payments</th>
                                                <td class="text-end">₹<?php echo number_format($totalSupplierPayments, 2); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Purchases</th>
                                                <td class="text-end">₹<?php echo number_format($totalPurchases, 2); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Expenses</th>
                                                <td class="text-end">₹<?php echo number_format($totalExpenses, 2); ?></td>
                                            </tr>
                                            <tr class="table-light">
                                                <th>Net Cash Position (Collections - Supplier Payments - Expenses)</th>
                                                <td class="text-end fw-bold">
                                                    ₹<?php echo number_format($totalCollections - $totalSupplierPayments - $totalExpenses, 2); ?>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-lg-5">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Expense Breakup</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-bordered table-striped align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Category</th>
                                                <th>Amount</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($expenseBreakup)): ?>
                                                <?php foreach ($expenseBreakup as $index => $expense): ?>
                                                    <tr>
                                                        <td><?php echo $index + 1; ?></td>
                                                        <td><?php echo h($expense['expense_category'] ?? 'Others'); ?></td>
                                                        <td>₹<?php echo number_format((float)($expense['total_amount'] ?? 0), 2); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="3" class="text-center text-muted">No expense data found for selected period.</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-7">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Top Product Profitability</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-bordered table-striped align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Product</th>
                                                <th>Qty Sold</th>
                                                <th>Sales Amount</th>
                                                <th>Estimated Cost</th>
                                                <th>Profit</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($productProfitRows)): ?>
                                                <?php foreach ($productProfitRows as $index => $row): ?>
                                                    <tr>
                                                        <td><?php echo $index + 1; ?></td>
                                                        <td><?php echo h($row['product_name'] ?? ('Product #' . ($row['product_id'] ?? ''))); ?></td>
                                                        <td><?php echo number_format((float)($row['total_qty'] ?? 0), 3); ?></td>
                                                        <td>₹<?php echo number_format((float)($row['total_sales_amount'] ?? 0), 2); ?></td>
                                                        <td>₹<?php echo number_format((float)($row['total_cost_amount'] ?? 0), 2); ?></td>
                                                        <td class="<?php echo ((float)($row['product_profit'] ?? 0) >= 0) ? 'text-success' : 'text-danger'; ?>">
                                                            <strong>₹<?php echo number_format((float)($row['product_profit'] ?? 0), 2); ?></strong>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="6" class="text-center text-muted">No product profit data found.</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="mt-3">
                                    <small class="text-muted">
                                        Profit is estimated from sale amount minus purchase-rate-based cost.
                                    </small>
                                </div>
                            </div>
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