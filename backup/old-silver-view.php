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

$pageTitle = 'Old Silver View';
$page_title = 'Old Silver View';
$currentPage = 'report-old-silver';

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

if (!function_exists('formatWeight')) {
    function formatWeight($weight): string {
        return number_format((float)$weight, 3);
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

$entry_id = (int)($_GET['id'] ?? 0);

if ($entry_id <= 0) {
    die('Invalid old silver entry selected.');
}

$requiredTables = ['old_silver_entries', 'old_silver_items'];
foreach ($requiredTables as $tbl) {
    if (!tableExists($conn, $tbl)) {
        die('Required table `' . h($tbl) . '` not found.');
    }
}

$hasCustomersTable = tableExists($conn, 'customers');
$hasSalesTable = tableExists($conn, 'sales');
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

/* ---------------- ENTRY DETAILS ---------------- */

$entry = null;

$sql = "
    SELECT
        ose.*,
        " . ($hasCustomersTable ? "c.customer_code, c.mobile AS customer_mobile_main, c.email AS customer_email, c.gstin AS customer_gstin, c.address_line1 AS customer_address1, c.address_line2 AS customer_address2, c.city AS customer_city, c.state AS customer_state, c.pincode AS customer_pincode" : "'' AS customer_code, '' AS customer_mobile_main, '' AS customer_email, '' AS customer_gstin, '' AS customer_address1, '' AS customer_address2, '' AS customer_city, '' AS customer_state, '' AS customer_pincode") . "
    FROM old_silver_entries ose
    " . ($hasCustomersTable ? "LEFT JOIN customers c ON c.id = ose.customer_id" : "") . "
    WHERE ose.id = ? AND ose.business_id = ?
    LIMIT 1
";

$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param('ii', $entry_id, $business_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $entry = $res ? $res->fetch_assoc() : null;
    $stmt->close();
}

if (!$entry) {
    die('Old silver entry not found.');
}

/* ---------------- ITEMS ---------------- */

$items = [];
$stmt = $conn->prepare("SELECT * FROM old_silver_items WHERE old_silver_entry_id = ? AND business_id = ? ORDER BY id ASC");
if ($stmt) {
    $stmt->bind_param('ii', $entry_id, $business_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && $row = $res->fetch_assoc()) {
        $items[] = $row;
    }
    $stmt->close();
}

/* ---------------- LINKED SALE ---------------- */

$linkedSale = null;
$linkedSaleId = (int)($entry['linked_sale_id'] ?? 0);

if ($linkedSaleId > 0 && $hasSalesTable) {
    $stmt = $conn->prepare("SELECT id, bill_no, bill_date, bill_time, customer_name, customer_mobile, bill_type, grand_total, paid_amount, balance_amount, payment_status, status FROM sales WHERE id = ? AND business_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('ii', $linkedSaleId, $business_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $linkedSale = $res ? ($res->fetch_assoc() ?: null) : null;
        $stmt->close();
    }
}

/* ---------------- SUMMARY ---------------- */

$itemCount = count($items);
$totalGrossWeight = 0.0;
$totalLessWeight = 0.0;
$totalNetWeight = 0.0;
$totalStoneWeight = 0.0;
$totalAmount = 0.0;

foreach ($items as $item) {
    $totalGrossWeight += (float)($item['gross_weight'] ?? 0);
    $totalLessWeight += (float)($item['less_weight'] ?? 0);
    $totalNetWeight += (float)($item['net_weight'] ?? 0);
    $totalStoneWeight += (float)($item['stone_weight'] ?? 0);
    $totalAmount += (float)($item['amount'] ?? 0);
}

$entryNo = (string)($entry['entry_no'] ?? '');
$entryDate = !empty($entry['entry_date']) ? date('d-m-Y', strtotime($entry['entry_date'])) : '-';
$adjustmentType = (string)($entry['adjustment_type'] ?? '');
$customerMobile = (string)($entry['customer_mobile'] ?: ($entry['customer_mobile_main'] ?? ''));
$backUrl = 'report-old-silver.php';

if (!empty($_SERVER['HTTP_REFERER']) && (strpos($_SERVER['HTTP_REFERER'], 'report-old-silver.php') !== false || strpos($_SERVER['HTTP_REFERER'], 'old-silver-list.php') !== false)) {
    $backUrl = $_SERVER['HTTP_REFERER'];
}

function adjustmentBadgeClass(string $type): string {
    if ($type === 'Cash') {
        return 'status-cash';
    }
    if ($type === 'Exchange') {
        return 'status-exchange';
    }
    return 'status-pending';
}
?>
<!doctype html>
<html lang="en">
<?php include('includes/head.php'); ?>
<title>Old Silver View | Reports</title>

<style>
    .report-card { border-radius: 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 24px; border: 0; }
    .report-card .card-header { background: #f8fafc; border-bottom: 1px solid #eef2f6; padding: 16px 20px; font-weight: 600; border-radius: 16px 16px 0 0; }
    .report-card .card-body { padding: 20px; }
    .summary-box { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 12px; padding: 20px; color: white; text-align: center; height: 100%; }
    .summary-value { font-size: 26px; font-weight: 700; line-height: 1.2; word-break: break-word; }
    .summary-label { font-size: 12px; opacity: 0.9; margin-top: 5px; }
    .status-cash { background: #dcfce7; color: #166534; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 500; display: inline-block; }
    .status-exchange { background: #dbeafe; color: #1e40af; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 500; display: inline-block; }
    .status-pending { background: #fef3c7; color: #92400e; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 500; display: inline-block; }
    .info-label { color: #64748b; font-size: 12px; margin-bottom: 4px; }
    .info-value { font-weight: 700; color: #111827; word-break: break-word; }
    .invoice-header-box { background: #f8fafc; border: 1px solid #eef2f6; border-radius: 14px; padding: 18px; }
    .company-logo { max-width: 90px; max-height: 70px; object-fit: contain; }
    .table-nowrap th, .table-nowrap td { white-space: nowrap; }
    .totals-table th { background: #f8fafc; width: 55%; }
    .totals-table td, .totals-table th { padding: 9px 12px; }
    .grand-total-row th, .grand-total-row td { font-size: 16px; font-weight: 800; background: #eef2ff; }
    .action-group { display: flex; gap: 8px; flex-wrap: wrap; justify-content: flex-end; }
    .item-badge { background: #e5e7eb; color: #4b5563; padding: 2px 8px; border-radius: 12px; font-size: 10px; }
    @media (max-width: 767px) {
        .action-group { justify-content: flex-start; }
        .summary-value { font-size: 20px; }
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
                                <h4 class="mb-1">Old Silver View</h4>
                                <p class="text-muted mb-0">Detailed view for old silver entry <?php echo h($entryNo); ?></p>
                            </div>
                            <div class="action-group">
                                <a href="<?php echo h($backUrl); ?>" class="btn btn-outline-secondary">
                                    <i class="fas fa-arrow-left"></i> Back
                                </a>
                                <a href="old-silver-entry.php" class="btn btn-primary">
                                    <i class="fas fa-plus"></i> New Entry
                                </a>
                                <?php if ($adjustmentType === 'Pending'): ?>
                                    <a href="old-silver-settle.php?id=<?php echo (int)$entry_id; ?>" class="btn btn-success">
                                        <i class="fas fa-check"></i> Settle
                                    </a>
                                <?php endif; ?>
                                <?php if ($linkedSaleId > 0): ?>
                                    <a href="sales-view.php?id=<?php echo (int)$linkedSaleId; ?>" class="btn btn-info">
                                        <i class="fas fa-link"></i> View Linked Sale
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
                            <div class="summary-value"><?php echo h($entryNo); ?></div>
                            <div class="summary-label">Entry No</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="summary-box" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                            <div class="summary-value"><?php echo formatWeight($entry['total_net_weight'] ?? 0); ?> g</div>
                            <div class="summary-label">Net Weight</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="summary-box" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                            <div class="summary-value">₹<?php echo money($entry['final_amount'] ?? 0); ?></div>
                            <div class="summary-label">Final Amount</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="summary-box" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                            <div class="summary-value">₹<?php echo money($entry['deduction_amount'] ?? 0); ?></div>
                            <div class="summary-label">Deduction</div>
                        </div>
                    </div>
                </div>

                <!-- Entry Header -->
                <div class="card report-card">
                    <div class="card-header bg-transparent">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <h5 class="mb-0">Entry Details</h5>
                            <div>
                                <span class="<?php echo adjustmentBadgeClass($adjustmentType); ?>"><?php echo h($adjustmentType); ?></span>
                                <?php if ($linkedSaleId > 0): ?>
                                    <span class="badge bg-info ms-1">Linked Sale</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary ms-1">No Linked Sale</span>
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
                                    <div class="info-value"><?php echo h($entry['customer_name'] ?: 'Walk-in Customer'); ?></div>

                                    <div class="row mt-3">
                                        <div class="col-6">
                                            <div class="info-label">Mobile</div>
                                            <div class="info-value"><?php echo h($customerMobile ?: '-'); ?></div>
                                        </div>
                                        <div class="col-6">
                                            <div class="info-label">Code</div>
                                            <div class="info-value"><?php echo h($entry['customer_code'] ?? '-'); ?></div>
                                        </div>
                                    </div>

                                    <?php if (!empty($entry['customer_gstin'])): ?>
                                        <div class="mt-3">
                                            <div class="info-label">GSTIN</div>
                                            <div class="info-value"><?php echo h($entry['customer_gstin']); ?></div>
                                        </div>
                                    <?php endif; ?>

                                    <?php
                                        $customerAddress = trim(
                                            (string)($entry['customer_address1'] ?? '') . ' ' .
                                            (string)($entry['customer_address2'] ?? '') . ' ' .
                                            (string)($entry['customer_city'] ?? '') . ' ' .
                                            (string)($entry['customer_state'] ?? '') . ' ' .
                                            (string)($entry['customer_pincode'] ?? '')
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
                                    <h6 class="mb-3">Old Silver Details</h6>
                                    <div class="row g-3">
                                        <div class="col-6">
                                            <div class="info-label">Entry No</div>
                                            <div class="info-value"><?php echo h($entryNo); ?></div>
                                        </div>
                                        <div class="col-6">
                                            <div class="info-label">Entry Date</div>
                                            <div class="info-value"><?php echo h($entryDate); ?></div>
                                        </div>
                                        <div class="col-6">
                                            <div class="info-label">Adjustment</div>
                                            <div class="info-value"><?php echo h($adjustmentType); ?></div>
                                        </div>
                                        <div class="col-6">
                                            <div class="info-label">Rate / Gram</div>
                                            <div class="info-value">₹<?php echo money($entry['rate_per_gram'] ?? 0); ?></div>
                                        </div>
                                        <div class="col-6">
                                            <div class="info-label">Deduction %</div>
                                            <div class="info-value"><?php echo money($entry['deduction_percent'] ?? 0); ?>%</div>
                                        </div>
                                        <div class="col-6">
                                            <div class="info-label">Items</div>
                                            <div class="info-value"><?php echo (int)$itemCount; ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php if (!empty($entry['remarks']) || !empty($entry['notes'])): ?>
                            <div class="alert alert-info mt-3 mb-0">
                                <strong>Notes:</strong> <?php echo h($entry['remarks'] ?? $entry['notes'] ?? ''); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Weight Summary Row -->
                <div class="row g-3 mb-4">
                    <div class="col-md-3">
                        <div class="card report-card h-100">
                            <div class="card-body text-center">
                                <h4 class="text-primary mb-1"><?php echo formatWeight($entry['total_gross_weight'] ?? 0); ?> g</h4>
                                <p class="mb-0">Gross Weight</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card report-card h-100">
                            <div class="card-body text-center">
                                <h4 class="text-danger mb-1"><?php echo formatWeight($entry['total_less_weight'] ?? 0); ?> g</h4>
                                <p class="mb-0">Less Weight</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card report-card h-100">
                            <div class="card-body text-center">
                                <h4 class="text-success mb-1"><?php echo formatWeight($entry['total_net_weight'] ?? 0); ?> g</h4>
                                <p class="mb-0">Net Weight</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card report-card h-100">
                            <div class="card-body text-center">
                                <h4 class="text-info mb-1">₹<?php echo money($entry['rate_per_gram'] ?? 0); ?></h4>
                                <p class="mb-0">Rate / Gram</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Items + Summary -->
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
                                                <th class="text-end">Gross Wt</th>
                                                <th class="text-end">Less Wt</th>
                                                <th class="text-end">Stone Wt</th>
                                                <th class="text-end">Net Wt</th>
                                                <th class="text-end">Rate/g</th>
                                                <th class="text-end">Amount</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($items)): ?>
                                                <tr>
                                                    <td colspan="9" class="text-center text-muted py-5">
                                                        <i class="fas fa-inbox fa-3x mb-3 d-block text-muted"></i>
                                                        No item records found.
                                                    </td>
                                                </tr>
                                            <?php else: foreach ($items as $index => $item): ?>
                                                <tr>
                                                    <td><?php echo $index + 1; ?></td>
                                                    <td>
                                                        <strong><?php echo h($item['item_name'] ?? ''); ?></strong><br>
                                                        <?php if (!empty($item['description'])): ?>
                                                            <small class="text-muted"><?php echo h($item['description']); ?></small>
                                                        <?php else: ?>
                                                            <span class="item-badge">Old Silver</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo h($item['purity'] ?? '-'); ?></td>
                                                    <td class="text-end"><?php echo formatWeight($item['gross_weight'] ?? 0); ?></td>
                                                    <td class="text-end"><?php echo formatWeight($item['less_weight'] ?? 0); ?></td>
                                                    <td class="text-end"><?php echo formatWeight($item['stone_weight'] ?? 0); ?></td>
                                                    <td class="text-end"><strong><?php echo formatWeight($item['net_weight'] ?? 0); ?></strong></td>
                                                    <td class="text-end">₹<?php echo money($item['rate_per_gram'] ?? ($entry['rate_per_gram'] ?? 0)); ?></td>
                                                    <td class="text-end"><strong>₹<?php echo money($item['amount'] ?? 0); ?></strong></td>
                                                </tr>
                                            <?php endforeach; endif; ?>
                                        </tbody>
                                        <?php if (!empty($items)): ?>
                                            <tfoot class="table-light">
                                                <tr>
                                                    <td colspan="3" class="text-end"><strong>Totals:</strong></td>
                                                    <td class="text-end"><strong><?php echo formatWeight($totalGrossWeight); ?></strong></td>
                                                    <td class="text-end"><strong><?php echo formatWeight($totalLessWeight); ?></strong></td>
                                                    <td class="text-end"><strong><?php echo formatWeight($totalStoneWeight); ?></strong></td>
                                                    <td class="text-end"><strong><?php echo formatWeight($totalNetWeight); ?></strong></td>
                                                    <td></td>
                                                    <td class="text-end"><strong>₹<?php echo money($totalAmount); ?></strong></td>
                                                </tr>
                                            </tfoot>
                                        <?php endif; ?>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <?php if ($linkedSale): ?>
                            <div class="card report-card">
                                <div class="card-header bg-transparent">
                                    <h5 class="mb-0">Linked Sale Details</h5>
                                </div>
                                <div class="card-body">
                                    <div class="row g-3">
                                        <div class="col-md-3">
                                            <div class="info-label">Bill No</div>
                                            <div class="info-value"><?php echo h($linkedSale['bill_no']); ?></div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="info-label">Date</div>
                                            <div class="info-value"><?php echo !empty($linkedSale['bill_date']) ? h(date('d-m-Y', strtotime($linkedSale['bill_date']))) : '-'; ?></div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="info-label">Bill Type</div>
                                            <div class="info-value"><?php echo h($linkedSale['bill_type']); ?></div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="info-label">Grand Total</div>
                                            <div class="info-value">₹<?php echo money($linkedSale['grand_total'] ?? 0); ?></div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="info-label">Paid</div>
                                            <div class="info-value text-success">₹<?php echo money($linkedSale['paid_amount'] ?? 0); ?></div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="info-label">Balance</div>
                                            <div class="info-value text-danger">₹<?php echo money($linkedSale['balance_amount'] ?? 0); ?></div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="info-label">Payment Status</div>
                                            <div class="info-value"><?php echo h($linkedSale['payment_status']); ?></div>
                                        </div>
                                        <div class="col-md-3">
                                            <a href="sales-view.php?id=<?php echo (int)$linkedSale['id']; ?>" class="btn btn-sm btn-outline-info mt-3">
                                                <i class="fas fa-eye"></i> View Sale
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="col-xl-4">
                        <div class="card report-card">
                            <div class="card-header bg-transparent">
                                <h5 class="mb-0">Amount Summary</h5>
                            </div>
                            <div class="card-body">
                                <table class="table table-bordered totals-table mb-0">
                                    <tr>
                                        <th>Gross Weight</th>
                                        <td class="text-end"><?php echo formatWeight($entry['total_gross_weight'] ?? 0); ?> g</td>
                                    </tr>
                                    <tr>
                                        <th>Less Weight</th>
                                        <td class="text-end text-danger"><?php echo formatWeight($entry['total_less_weight'] ?? 0); ?> g</td>
                                    </tr>
                                    <tr>
                                        <th>Net Weight</th>
                                        <td class="text-end"><strong><?php echo formatWeight($entry['total_net_weight'] ?? 0); ?> g</strong></td>
                                    </tr>
                                    <tr>
                                        <th>Rate / Gram</th>
                                        <td class="text-end">₹<?php echo money($entry['rate_per_gram'] ?? 0); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Gross Amount</th>
                                        <td class="text-end">₹<?php echo money($entry['gross_amount'] ?? $totalAmount); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Deduction %</th>
                                        <td class="text-end"><?php echo money($entry['deduction_percent'] ?? 0); ?>%</td>
                                    </tr>
                                    <tr>
                                        <th>Deduction Amount</th>
                                        <td class="text-end text-danger">₹<?php echo money($entry['deduction_amount'] ?? 0); ?></td>
                                    </tr>
                                    <tr class="grand-total-row">
                                        <th>Final Amount</th>
                                        <td class="text-end">₹<?php echo money($entry['final_amount'] ?? 0); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Adjustment Type</th>
                                        <td class="text-end"><span class="<?php echo adjustmentBadgeClass($adjustmentType); ?>"><?php echo h($adjustmentType); ?></span></td>
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
                                    <a href="<?php echo h($backUrl); ?>" class="btn btn-outline-secondary">
                                        <i class="fas fa-arrow-left"></i> Back
                                    </a>
                                    <a href="old-silver-entry.php" class="btn btn-primary">
                                        <i class="fas fa-plus"></i> New Entry
                                    </a>
                                    <?php if ($adjustmentType === 'Pending'): ?>
                                        <a href="old-silver-settle.php?id=<?php echo (int)$entry_id; ?>" class="btn btn-success">
                                            <i class="fas fa-check"></i> Settle Pending
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($linkedSaleId > 0): ?>
                                        <a href="sales-view.php?id=<?php echo (int)$linkedSaleId; ?>" class="btn btn-info">
                                            <i class="fas fa-link"></i> View Linked Sale
                                        </a>
                                    <?php endif; ?>
                                    <button type="button" onclick="window.print()" class="btn btn-secondary">
                                        <i class="fas fa-print"></i> Print Page
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="card report-card">
                            <div class="card-header bg-transparent">
                                <h5 class="mb-0">Entry Metadata</h5>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <div class="info-label">Created At</div>
                                    <div class="info-value">
                                        <?php echo !empty($entry['created_at']) ? h(date('d-m-Y h:i A', strtotime($entry['created_at']))) : '-'; ?>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <div class="info-label">Updated At</div>
                                    <div class="info-value">
                                        <?php echo !empty($entry['updated_at']) ? h(date('d-m-Y h:i A', strtotime($entry['updated_at']))) : '-'; ?>
                                    </div>
                                </div>
                                <div>
                                    <div class="info-label">Created By</div>
                                    <div class="info-value"><?php echo h($entry['created_by'] ?? '-'); ?></div>
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
