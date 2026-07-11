<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Kolkata');
require_once __DIR__ . '/includes/config.php';

if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_error) {
    die('Database connection failed: ' . ($conn->connect_error ?? 'config error'));
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn->set_charset('utf8mb4');

$pageTitle = 'Sales View';
$page_title = 'Sales View';
$currentPage = 'report-sales';

$business_id = (int)($_SESSION['business_id'] ?? 1);
$user_id = (int)($_SESSION['user_id'] ?? 0);

if (!function_exists('h')) {
    function h($string): string {
        return htmlspecialchars((string)($string ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('money')) {
    function money($amount): string {
        return number_format((float)$amount, 2);
    }
}

if (!function_exists('tableExists')) {
    function tableExists(mysqli $conn, string $tableName): bool {
        $safe = $conn->real_escape_string($tableName);
        $res = $conn->query("SHOW TABLES LIKE '{$safe}'");
        return $res && $res->num_rows > 0;
    }
}

if (!function_exists('hasColumn')) {
    function hasColumn(mysqli $conn, string $table, string $column): bool {
        $table = $conn->real_escape_string($table);
        $column = $conn->real_escape_string($column);
        $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
        return $res && $res->num_rows > 0;
    }
}

$sale_id = (int)($_GET['id'] ?? 0);

if ($sale_id <= 0) {
    die('Invalid sale selected.');
}

$requiredTables = ['sales', 'sale_items'];
foreach ($requiredTables as $tbl) {
    if (!tableExists($conn, $tbl)) {
        die('Required table `' . h($tbl) . '` not found.');
    }
}

$hasCustomersTable = tableExists($conn, 'customers');
$hasPaymentMethodsTable = tableExists($conn, 'payment_methods');
$hasSalePaymentsTable = tableExists($conn, 'sale_payments');
$hasCompanySettingsTable = tableExists($conn, 'company_settings');

/* ---------------- COMPANY DETAILS ---------------- */

$company = [
    'company_name' => '',
    'business_type' => '',
    'owner_name' => '',
    'mobile' => '',
    'email' => '',
    'address_line1' => '',
    'address_line2' => '',
    'city' => '',
    'state' => '',
    'pincode' => '',
    'country' => 'India',
    'gstin' => '',
    'pan_no' => '',
    'logo_path' => ''
];

if ($hasCompanySettingsTable) {
    $stmt = $conn->prepare("SELECT * FROM company_settings WHERE business_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $business_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        if ($row) {
            foreach ($company as $k => $v) {
                if (array_key_exists($k, $row)) {
                    $company[$k] = (string)($row[$k] ?? '');
                }
            }
        }
        $stmt->close();
    }
}

/* ---------------- SALE DETAILS ---------------- */

$sale = null;

$sql = "
    SELECT
        s.*,
        " . ($hasCustomersTable ? "c.customer_code, c.gstin AS customer_gstin, c.email AS customer_email, c.address_line1 AS customer_address1, c.address_line2 AS customer_address2, c.city AS customer_city, c.state AS customer_state, c.pincode AS customer_pincode," : "'' AS customer_code, '' AS customer_gstin, '' AS customer_email, '' AS customer_address1, '' AS customer_address2, '' AS customer_city, '' AS customer_state, '' AS customer_pincode,") . "
        " . ($hasPaymentMethodsTable ? "pm.method_name" : "'' AS method_name") . "
    FROM sales s
    " . ($hasCustomersTable ? "LEFT JOIN customers c ON c.id = s.customer_id" : "") . "
    " . ($hasPaymentMethodsTable ? "LEFT JOIN payment_methods pm ON pm.id = s.payment_method_id" : "") . "
    WHERE s.id = ? AND s.business_id = ?
    LIMIT 1
";

$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param('ii', $sale_id, $business_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $sale = $res ? $res->fetch_assoc() : null;
    $stmt->close();
}

if (!$sale) {
    die('Sale not found.');
}

/* ---------------- ITEMS ---------------- */

$items = [];
$stmt = $conn->prepare("SELECT * FROM sale_items WHERE sale_id = ? AND business_id = ? ORDER BY id ASC");
if ($stmt) {
    $stmt->bind_param('ii', $sale_id, $business_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && $row = $res->fetch_assoc()) {
        $items[] = $row;
    }
    $stmt->close();
}

/* ---------------- PAYMENTS ---------------- */

$payments = [];
if ($hasSalePaymentsTable) {
    $sql = "
        SELECT sp.*, " . ($hasPaymentMethodsTable ? "pm.method_name" : "'' AS method_name") . "
        FROM sale_payments sp
        " . ($hasPaymentMethodsTable ? "LEFT JOIN payment_methods pm ON pm.id = sp.payment_method_id" : "") . "
        WHERE sp.sale_id = ?
        ORDER BY sp.id ASC
    ";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('i', $sale_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && $row = $res->fetch_assoc()) {
            $payments[] = $row;
        }
        $stmt->close();
    }
}

/* ---------------- TOTALS ---------------- */

$itemCount = count($items);
$totalQty = 0.0;
$totalGrossWeight = 0.0;
$totalLessWeight = 0.0;
$totalNetWeight = 0.0;
$totalItemAmount = 0.0;

foreach ($items as $item) {
    $totalQty += (float)($item['qty'] ?? 0);
    $totalGrossWeight += (float)($item['gross_weight'] ?? 0);
    $totalLessWeight += (float)($item['less_weight'] ?? 0);
    $totalNetWeight += (float)($item['net_weight'] ?? 0);
    $totalItemAmount += (float)($item['total_amount'] ?? 0);
}

$billNo = (string)($sale['bill_no'] ?? '');
$billDate = !empty($sale['bill_date']) ? date('d-m-Y', strtotime($sale['bill_date'])) : '-';
$billTime = !empty($sale['bill_time']) ? date('h:i A', strtotime($sale['bill_time'])) : '-';
$billType = (string)($sale['bill_type'] ?? '');
$paymentStatus = (string)($sale['payment_status'] ?? '');
$status = (string)($sale['status'] ?? '');

$backUrl = 'report-sales.php';
if (!empty($_SERVER['HTTP_REFERER']) && strpos($_SERVER['HTTP_REFERER'], 'report-sales.php') !== false) {
    $backUrl = $_SERVER['HTTP_REFERER'];
}

function statusBadgeClass(string $status): string {
    if ($status === 'Paid') {
        return 'status-paid';
    }
    if ($status === 'Partial') {
        return 'status-partial';
    }
    return 'status-unpaid';
}

function billTypeClass(string $type): string {
    if ($type === 'GST') return 'bg-primary';
    if ($type === 'Estimate') return 'bg-warning';
    if ($type === 'Exchange') return 'bg-info';
    return 'bg-secondary';
}
?>
<!doctype html>
<html lang="en">
<?php include('includes/head.php'); ?>
<title>Sales View | Reports</title>

<style>
    .report-card {
        border-radius: 16px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        margin-bottom: 24px;
        border: 0;
    }
    .report-card .card-header {
        background: #f8fafc;
        border-bottom: 1px solid #eef2f6;
        padding: 16px 20px;
        font-weight: 600;
        border-radius: 16px 16px 0 0;
    }
    .report-card .card-body {
        padding: 20px;
    }
    .summary-box {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border-radius: 12px;
        padding: 20px;
        color: white;
        text-align: center;
        height: 100%;
    }
    .summary-value {
        font-size: 26px;
        font-weight: 700;
        line-height: 1.2;
        word-break: break-word;
    }
    .summary-label {
        font-size: 12px;
        opacity: 0.9;
        margin-top: 5px;
    }
    .status-paid {
        background: #dcfce7;
        color: #166534;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 500;
        display: inline-block;
    }
    .status-partial {
        background: #fef3c7;
        color: #92400e;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 500;
        display: inline-block;
    }
    .status-unpaid {
        background: #fee2e2;
        color: #991b1b;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 500;
        display: inline-block;
    }
    .info-label {
        color: #64748b;
        font-size: 12px;
        margin-bottom: 4px;
    }
    .info-value {
        font-weight: 700;
        color: #111827;
        word-break: break-word;
    }
    .invoice-header-box {
        background: #f8fafc;
        border: 1px solid #eef2f6;
        border-radius: 14px;
        padding: 18px;
    }
    .company-logo {
        max-width: 90px;
        max-height: 70px;
        object-fit: contain;
    }
    .table-nowrap th,
    .table-nowrap td {
        white-space: nowrap;
    }
    .totals-table th {
        background: #f8fafc;
        width: 55%;
    }
    .totals-table td,
    .totals-table th {
        padding: 9px 12px;
    }
    .grand-total-row th,
    .grand-total-row td {
        font-size: 16px;
        font-weight: 800;
        background: #eef2ff;
    }
    .action-group {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        justify-content: flex-end;
    }
    @media (max-width: 767px) {
        .action-group {
            justify-content: flex-start;
        }
        .summary-value {
            font-size: 20px;
        }
    }
</style>

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

                <!-- Page Header -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div>
                                <h4 class="mb-1">Sales View</h4>
                                <p class="text-muted mb-0">Detailed view for invoice <?php echo h($billNo); ?></p>
                            </div>
                            <div class="action-group">
                                <a href="<?php echo h($backUrl); ?>" class="btn btn-outline-secondary">
                                    <i class="fas fa-arrow-left"></i> Back to Report
                                </a>
                                <a href="invoice.php?id=<?php echo (int)$sale_id; ?>" target="_blank" class="btn btn-secondary">
                                    <i class="fas fa-print"></i> Print Invoice
                                </a>
                                <?php if ($billType === 'Estimate'): ?>
                                    
                                <?php else: ?>
                                    <a href="billing.php?edit_id=<?php echo (int)$sale_id; ?>" class="btn btn-primary">
                                        <i class="fas fa-edit"></i> Edit Bill
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Summary Cards -->
                <div class="row g-3 mb-4">
                    <div class="col-md-3">
                        <div class="summary-box">
                            <div class="summary-value"><?php echo h($billNo); ?></div>
                            <div class="summary-label">Bill No</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="summary-box" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                            <div class="summary-value">₹<?php echo money($sale['grand_total'] ?? 0); ?></div>
                            <div class="summary-label">Grand Total</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="summary-box" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                            <div class="summary-value">₹<?php echo money($sale['paid_amount'] ?? 0); ?></div>
                            <div class="summary-label">Paid Amount</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="summary-box" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                            <div class="summary-value">₹<?php echo money($sale['balance_amount'] ?? 0); ?></div>
                            <div class="summary-label">Balance Amount</div>
                        </div>
                    </div>
                </div>

                <!-- Invoice Header -->
                <div class="card report-card">
                    <div class="card-header bg-transparent">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <h5 class="mb-0">Invoice Details</h5>
                            <div>
                                <span class="badge <?php echo billTypeClass($billType); ?>"><?php echo h($billType); ?></span>
                                <span class="<?php echo statusBadgeClass($paymentStatus); ?> ms-1"><?php echo h($paymentStatus); ?></span>
                                <?php if ($status !== ''): ?>
                                    <span class="badge bg-light text-dark border ms-1"><?php echo h($status); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="invoice-header-box">
                            <div class="row g-4">
                                <div class="col-lg-4">
                                    <div class="d-flex gap-3 align-items-start">
                                        <?php if (!empty($company['logo_path'])): ?>
                                            <img src="<?php echo h($company['logo_path']); ?>" class="company-logo" alt="Logo">
                                        <?php endif; ?>
                                        <div>
                                            <h5 class="mb-1"><?php echo h($company['company_name'] ?: 'Company'); ?></h5>
                                            <?php if (!empty($company['business_type'])): ?>
                                                <div class="text-muted small"><?php echo h($company['business_type']); ?></div>
                                            <?php endif; ?>
                                            <div class="small mt-2">
                                                <?php echo h($company['address_line1']); ?>
                                                <?php echo !empty($company['address_line2']) ? '<br>' . h($company['address_line2']) : ''; ?>
                                                <?php
                                                    $companyPlace = trim(($company['city'] ?? '') . ', ' . ($company['state'] ?? '') . ' ' . ($company['pincode'] ?? ''));
                                                    echo $companyPlace !== ', ' ? '<br>' . h($companyPlace) : '';
                                                ?>
                                            </div>
                                            <?php if (!empty($company['mobile'])): ?>
                                                <div class="small mt-1"><strong>Mobile:</strong> <?php echo h($company['mobile']); ?></div>
                                            <?php endif; ?>
                                            <?php if (!empty($company['gstin'])): ?>
                                                <div class="small"><strong>GSTIN:</strong> <?php echo h($company['gstin']); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-lg-4">
                                    <h6 class="mb-3">Customer Details</h6>
                                    <div class="info-label">Customer Name</div>
                                    <div class="info-value"><?php echo h($sale['customer_name'] ?: 'Walk-in Customer'); ?></div>

                                    <div class="row mt-3">
                                        <div class="col-6">
                                            <div class="info-label">Mobile</div>
                                            <div class="info-value"><?php echo h($sale['customer_mobile'] ?: ($sale['mobile'] ?? '-')); ?></div>
                                        </div>
                                        <div class="col-6">
                                            <div class="info-label">Code</div>
                                            <div class="info-value"><?php echo h($sale['customer_code'] ?? '-'); ?></div>
                                        </div>
                                    </div>

                                    <?php if (!empty($sale['customer_gstin'])): ?>
                                        <div class="mt-3">
                                            <div class="info-label">GSTIN</div>
                                            <div class="info-value"><?php echo h($sale['customer_gstin']); ?></div>
                                        </div>
                                    <?php endif; ?>

                                    <?php
                                        $customerAddress = trim(
                                            (string)($sale['customer_address1'] ?? '') . ' ' .
                                            (string)($sale['customer_address2'] ?? '') . ' ' .
                                            (string)($sale['customer_city'] ?? '') . ' ' .
                                            (string)($sale['customer_state'] ?? '') . ' ' .
                                            (string)($sale['customer_pincode'] ?? '')
                                        );
                                    ?>
                                    <?php if ($customerAddress !== ''): ?>
                                        <div class="mt-3">
                                            <div class="info-label">Address</div>
                                            <div class="info-value"><?php echo h($customerAddress); ?></div>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="col-lg-4">
                                    <h6 class="mb-3">Bill Details</h6>
                                    <div class="row g-3">
                                        <div class="col-6">
                                            <div class="info-label">Bill No</div>
                                            <div class="info-value"><?php echo h($billNo); ?></div>
                                        </div>
                                        <div class="col-6">
                                            <div class="info-label">Bill Date</div>
                                            <div class="info-value"><?php echo h($billDate); ?></div>
                                        </div>
                                        <div class="col-6">
                                            <div class="info-label">Bill Time</div>
                                            <div class="info-value"><?php echo h($billTime); ?></div>
                                        </div>
                                        <div class="col-6">
                                            <div class="info-label">Bill Type</div>
                                            <div class="info-value"><?php echo h($billType); ?></div>
                                        </div>
                                        <div class="col-6">
                                            <div class="info-label">Payment Method</div>
                                            <div class="info-value"><?php echo h($sale['method_name'] ?: '-'); ?></div>
                                        </div>
                                        <div class="col-6">
                                            <div class="info-label">Reference</div>
                                            <div class="info-value"><?php echo h($sale['payment_reference'] ?: '-'); ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php if (!empty($sale['notes'])): ?>
                            <div class="alert alert-info mt-3 mb-0">
                                <strong>Notes:</strong> <?php echo h($sale['notes']); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Item Summary -->
                <div class="row g-3 mb-4">
                    <div class="col-md-3">
                        <div class="card report-card h-100">
                            <div class="card-body text-center">
                                <h4 class="text-primary mb-1"><?php echo (int)$itemCount; ?></h4>
                                <p class="mb-0">Total Items</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card report-card h-100">
                            <div class="card-body text-center">
                                <h4 class="text-success mb-1"><?php echo money($totalQty); ?></h4>
                                <p class="mb-0">Total Qty</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card report-card h-100">
                            <div class="card-body text-center">
                                <h4 class="text-warning mb-1"><?php echo money($totalNetWeight); ?></h4>
                                <p class="mb-0">Net Weight</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card report-card h-100">
                            <div class="card-body text-center">
                                <h4 class="text-info mb-1">₹<?php echo money($totalItemAmount); ?></h4>
                                <p class="mb-0">Items Total</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Items Table + Totals -->
                <div class="row">
                    <div class="col-xl-8">
                        <div class="card report-card">
                            <div class="card-header bg-transparent">
                                <h5 class="mb-0">Item Details</h5>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle table-nowrap mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>#</th>
                                                <th>Item</th>
                                                <th>Purity</th>
                                                <th class="text-end">Qty</th>
                                                <th class="text-end">Gross Wt</th>
                                                <th class="text-end">Less Wt</th>
                                                <th class="text-end">Net Wt</th>
                                                <th class="text-end">Rate</th>
                                                <th class="text-end">Making</th>
                                                <th class="text-end">Taxable</th>
                                                <th class="text-end">GST</th>
                                                <th class="text-end">Total</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($items)): ?>
                                                <tr>
                                                    <td colspan="12" class="text-center text-muted py-5">
                                                        <i class="fas fa-inbox fa-3x mb-3 d-block text-muted"></i>
                                                        No item records found.
                                                    </td>
                                                </tr>
                                            <?php else: foreach ($items as $index => $item): ?>
                                                <tr>
                                                    <td><?php echo $index + 1; ?></td>
                                                    <td>
                                                        <strong><?php echo h($item['item_name'] ?? ''); ?></strong><br>
                                                        <small class="text-muted">
                                                            <?php echo h($item['product_code'] ?? ''); ?>
                                                            <?php echo !empty($item['barcode']) ? ' | ' . h($item['barcode']) : ''; ?>
                                                            <?php echo !empty($item['category_name']) ? ' | ' . h($item['category_name']) : ''; ?>
                                                        </small>
                                                    </td>
                                                    <td><?php echo h($item['purity'] ?? '-'); ?></td>
                                                    <td class="text-end"><?php echo money($item['qty'] ?? 0); ?></td>
                                                    <td class="text-end"><?php echo money($item['gross_weight'] ?? 0); ?></td>
                                                    <td class="text-end"><?php echo money($item['less_weight'] ?? 0); ?></td>
                                                    <td class="text-end"><?php echo money($item['net_weight'] ?? 0); ?></td>
                                                    <td class="text-end">₹<?php echo money($item['rate_per_gram'] ?? 0); ?></td>
                                                    <td class="text-end">₹<?php echo money($item['making_charge'] ?? 0); ?></td>
                                                    <td class="text-end">₹<?php echo money($item['taxable_amount'] ?? 0); ?></td>
                                                    <td class="text-end">
                                                        ₹<?php echo money($item['gst_amount'] ?? 0); ?><br>
                                                        <small class="text-muted"><?php echo money($item['gst_percent'] ?? 0); ?>%</small>
                                                    </td>
                                                    <td class="text-end"><strong>₹<?php echo money($item['total_amount'] ?? 0); ?></strong></td>
                                                </tr>
                                            <?php endforeach; endif; ?>
                                        </tbody>
                                        <?php if (!empty($items)): ?>
                                            <tfoot class="table-light">
                                                <tr>
                                                    <td colspan="3" class="text-end"><strong>Totals:</strong></td>
                                                    <td class="text-end"><strong><?php echo money($totalQty); ?></strong></td>
                                                    <td class="text-end"><strong><?php echo money($totalGrossWeight); ?></strong></td>
                                                    <td class="text-end"><strong><?php echo money($totalLessWeight); ?></strong></td>
                                                    <td class="text-end"><strong><?php echo money($totalNetWeight); ?></strong></td>
                                                    <td colspan="4"></td>
                                                    <td class="text-end"><strong>₹<?php echo money($totalItemAmount); ?></strong></td>
                                                </tr>
                                            </tfoot>
                                        <?php endif; ?>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <?php if (!empty($payments)): ?>
                            <div class="card report-card">
                                <div class="card-header bg-transparent">
                                    <h5 class="mb-0">Payment Split</h5>
                                </div>
                                <div class="card-body p-0">
                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Method</th>
                                                    <th>Reference</th>
                                                    <th>Date</th>
                                                    <th class="text-end">Amount</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($payments as $payment): ?>
                                                    <tr>
                                                        <td><strong><?php echo h($payment['method_name'] ?? '-'); ?></strong></td>
                                                        <td><?php echo h($payment['reference_no'] ?? '-'); ?></td>
                                                        <td>
                                                            <?php
                                                                if (!empty($payment['created_at'])) {
                                                                    echo h(date('d-m-Y h:i A', strtotime($payment['created_at'])));
                                                                } else {
                                                                    echo '-';
                                                                }
                                                            ?>
                                                        </td>
                                                        <td class="text-end">₹<?php echo money($payment['amount'] ?? 0); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                            <tfoot class="table-light">
                                                <tr>
                                                    <td colspan="3" class="text-end"><strong>Total Paid:</strong></td>
                                                    <td class="text-end"><strong>₹<?php echo money(array_sum(array_column($payments, 'amount'))); ?></strong></td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="col-xl-4">
                        <div class="card report-card">
                            <div class="card-header bg-transparent">
                                <h5 class="mb-0">Bill Summary</h5>
                            </div>
                            <div class="card-body">
                                <table class="table table-bordered totals-table mb-0">
                                    <tr>
                                        <th>Subtotal</th>
                                        <td class="text-end">₹<?php echo money($sale['subtotal'] ?? 0); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Discount</th>
                                        <td class="text-end text-danger">₹<?php echo money($sale['discount_amount'] ?? 0); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Taxable</th>
                                        <td class="text-end">₹<?php echo money($sale['taxable_amount'] ?? 0); ?></td>
                                    </tr>
                                    <tr>
                                        <th>CGST</th>
                                        <td class="text-end">₹<?php echo money($sale['cgst_amount'] ?? 0); ?></td>
                                    </tr>
                                    <tr>
                                        <th>SGST</th>
                                        <td class="text-end">₹<?php echo money($sale['sgst_amount'] ?? 0); ?></td>
                                    </tr>
                                    <tr>
                                        <th>IGST</th>
                                        <td class="text-end">₹<?php echo money($sale['igst_amount'] ?? 0); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Round Off</th>
                                        <td class="text-end">₹<?php echo money($sale['round_off'] ?? 0); ?></td>
                                    </tr>
                                    <tr class="grand-total-row">
                                        <th>Grand Total</th>
                                        <td class="text-end">₹<?php echo money($sale['grand_total'] ?? 0); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Paid</th>
                                        <td class="text-end text-success">₹<?php echo money($sale['paid_amount'] ?? 0); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Balance</th>
                                        <td class="text-end text-danger">₹<?php echo money($sale['balance_amount'] ?? 0); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Payment Status</th>
                                        <td class="text-end"><span class="<?php echo statusBadgeClass($paymentStatus); ?>"><?php echo h($paymentStatus); ?></span></td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        <div class="card report-card">
                            <div class="card-header bg-transparent">
                                <h5 class="mb-0">Quick Actions</h5>
                            </div>
                            <div class="card-body">
                                <div class="d-grid gap-2">
                                    <a href="invoice.php?id=<?php echo (int)$sale_id; ?>" target="_blank" class="btn btn-secondary">
                                        <i class="fas fa-print"></i> Print Invoice
                                    </a>
                                    <?php if ($billType === 'Estimate'): ?>
                                        <a href="estimate-print.php?id=<?php echo (int)$sale_id; ?>" target="_blank" class="btn btn-outline-secondary">
                                            <i class="fas fa-file-alt"></i> Print Estimate
                                        </a>
                                       
                                    <?php else: ?>
                                        <a href="billing.php?edit_id=<?php echo (int)$sale_id; ?>" class="btn btn-primary">
                                            <i class="fas fa-edit"></i> Edit Bill
                                        </a>
                                    <?php endif; ?>
                                    <a href="<?php echo h($backUrl); ?>" class="btn btn-outline-secondary">
                                        <i class="fas fa-arrow-left"></i> Back to Report
                                    </a>
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
document.addEventListener('DOMContentLoaded', function () {
    document.body.setAttribute('data-sidebar', 'dark');

    var sidebarScroll = document.querySelector('.vertical-menu [data-simplebar]');
    if (sidebarScroll) {
        sidebarScroll.style.height = '100vh';
        sidebarScroll.style.overflowY = 'auto';
        sidebarScroll.style.overflowX = 'hidden';
    }
});
</script>

</body>
</html>
