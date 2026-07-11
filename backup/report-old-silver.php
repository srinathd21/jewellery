<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/config.php';

if (!$conn || $conn->connect_error) {
    die('Database connection failed: ' . ($conn->connect_error ?? 'config error'));
}

$business_id = (int)($_SESSION['business_id'] ?? 1);
$currentPage = 'report-old-silver';

// Helper functions
if (!function_exists('h')) {
    function h($string) { return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('money')) {
    function money($amount) { return number_format((float)$amount, 2); }
}
if (!function_exists('formatWeight')) {
    function formatWeight($weight) { return number_format((float)$weight, 3); }
}

// Get filter parameters
$date_range = $_GET['date_range'] ?? 'today';
$from_date = $_GET['from_date'] ?? '';
$to_date = $_GET['to_date'] ?? '';
$customer_id = (int)($_GET['customer_id'] ?? 0);
$adjustment_type = $_GET['adjustment_type'] ?? 'all';
$search_term = $_GET['search'] ?? '';
$export = $_GET['export'] ?? '';
$format = $_GET['format'] ?? '';

// Set dates based on date range
$today = date('Y-m-d');
$start_date = '';
$end_date = $today;

switch ($date_range) {
    case 'today':
        $start_date = $today;
        $end_date = $today;
        break;
    case 'yesterday':
        $start_date = date('Y-m-d', strtotime('-1 day'));
        $end_date = $start_date;
        break;
    case 'week':
        $start_date = date('Y-m-d', strtotime('monday this week'));
        $end_date = $today;
        break;
    case 'month':
        $start_date = date('Y-m-d', strtotime('first day of this month'));
        $end_date = $today;
        break;
    case 'custom':
        $start_date = $from_date ?: date('Y-m-d', strtotime('-30 days'));
        $end_date = $to_date ?: $today;
        break;
    default:
        $start_date = date('Y-m-d', strtotime('-30 days'));
        $end_date = $today;
}

// Use custom dates if provided
if ($from_date && $to_date) {
    $start_date = $from_date;
    $end_date = $to_date;
    $date_range = 'custom';
}

// Build WHERE clause
$where_conditions = ["ose.business_id = $business_id"];
$where_conditions[] = "DATE(ose.entry_date) BETWEEN '$start_date' AND '$end_date'";

if ($customer_id > 0) {
    $where_conditions[] = "ose.customer_id = $customer_id";
}
if ($adjustment_type != 'all') {
    $where_conditions[] = "ose.adjustment_type = '" . $conn->real_escape_string($adjustment_type) . "'";
}
if ($search_term) {
    $search = $conn->real_escape_string($search_term);
    $where_conditions[] = "(ose.entry_no LIKE '%$search%' OR ose.customer_name LIKE '%$search%' OR ose.customer_mobile LIKE '%$search%')";
}

$where_clause = implode(" AND ", $where_conditions);

// Fetch old silver entries
$entries = [];
$sql = "SELECT ose.*, c.customer_code, c.mobile as customer_mobile_main
        FROM old_silver_entries ose
        LEFT JOIN customers c ON ose.customer_id = c.id
        WHERE $where_clause
        ORDER BY ose.entry_date DESC, ose.id DESC";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        // Fetch items for this entry
        $items_sql = "SELECT * FROM old_silver_items WHERE old_silver_entry_id = {$row['id']} AND business_id = $business_id";
        $items_res = $conn->query($items_sql);
        $items = [];
        if ($items_res) {
            while ($item = $items_res->fetch_assoc()) {
                $items[] = $item;
            }
        }
        $row['items'] = $items;
        $row['item_count'] = count($items);
        $entries[] = $row;
    }
}

// Get customers for filter
$customers = [];
$cust_sql = "SELECT id, customer_name, customer_code FROM customers WHERE business_id = $business_id AND is_active = 1 ORDER BY customer_name";
$cust_res = $conn->query($cust_sql);
if ($cust_res) {
    while ($row = $cust_res->fetch_assoc()) {
        $customers[] = $row;
    }
}

// Calculate summary statistics
$total_entries = count($entries);
$total_gross_weight = array_sum(array_column($entries, 'total_gross_weight'));
$total_less_weight = array_sum(array_column($entries, 'total_less_weight'));
$total_net_weight = array_sum(array_column($entries, 'total_net_weight'));
$total_deduction = array_sum(array_column($entries, 'deduction_amount'));
$total_final_amount = array_sum(array_column($entries, 'final_amount'));

// Calculate by adjustment type
$cash_total = array_sum(array_filter(array_column($entries, 'final_amount'), function($key) use ($entries) {
    return $entries[$key]['adjustment_type'] == 'Cash';
}, ARRAY_FILTER_USE_KEY));
$exchange_total = array_sum(array_filter(array_column($entries, 'final_amount'), function($key) use ($entries) {
    return $entries[$key]['adjustment_type'] == 'Exchange';
}, ARRAY_FILTER_USE_KEY));
$pending_total = array_sum(array_filter(array_column($entries, 'final_amount'), function($key) use ($entries) {
    return $entries[$key]['adjustment_type'] == 'Pending';
}, ARRAY_FILTER_USE_KEY));

// Handle Export
if ($export == '1') {
    if ($format == 'excel') {
        exportToExcel($entries, $start_date, $end_date, $total_entries, $total_gross_weight, $total_less_weight, $total_net_weight, $total_deduction, $total_final_amount);
    } elseif ($format == 'pdf') {
        exportToPDF($conn, $entries, $start_date, $end_date, $total_entries, $total_gross_weight, $total_less_weight, $total_net_weight, $total_deduction, $total_final_amount);
    }
    exit;
}

// Export functions
function exportToExcel($entries, $start_date, $end_date, $total_entries, $total_gross_weight, $total_less_weight, $total_net_weight, $total_deduction, $total_final_amount) {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="old_silver_report_' . date('Y-m-d') . '.xls"');
    
    echo "Old Silver Purchase Report\n";
    echo "Period: " . date('d-m-Y', strtotime($start_date)) . " to " . date('d-m-Y', strtotime($end_date)) . "\n";
    echo "Generated On: " . date('d-m-Y H:i:s') . "\n\n";
    
    echo "Entry No\tDate\tCustomer Name\tMobile\tItems\tGross Weight\tLess Weight\tNet Weight\tRate/g\tDeduction %\tDeduction Amt\tFinal Amount\tAdjustment Type\tLinked Sale\n";
    
    foreach ($entries as $entry) {
        echo $entry['entry_no'] . "\t";
        echo date('d-m-Y', strtotime($entry['entry_date'])) . "\t";
        echo $entry['customer_name'] . "\t";
        echo ($entry['customer_mobile'] ?: $entry['customer_mobile_main']) . "\t";
        echo $entry['item_count'] . "\t";
        echo number_format($entry['total_gross_weight'], 3) . "\t";
        echo number_format($entry['total_less_weight'], 3) . "\t";
        echo number_format($entry['total_net_weight'], 3) . "\t";
        echo number_format($entry['rate_per_gram'], 2) . "\t";
        echo number_format($entry['deduction_percent'], 2) . "\t";
        echo number_format($entry['deduction_amount'], 2) . "\t";
        echo number_format($entry['final_amount'], 2) . "\t";
        echo $entry['adjustment_type'] . "\t";
        echo ($entry['linked_sale_id'] ? 'Yes' : 'No') . "\n";
    }
    
    echo "\n\nSUMMARY\n";
    echo "Total Entries:\t" . $total_entries . "\n";
    echo "Total Gross Weight:\t" . number_format($total_gross_weight, 3) . " g\n";
    echo "Total Less Weight:\t" . number_format($total_less_weight, 3) . " g\n";
    echo "Total Net Weight:\t" . number_format($total_net_weight, 3) . " g\n";
    echo "Total Deduction:\t" . number_format($total_deduction, 2) . "\n";
    echo "Total Final Amount:\t" . number_format($total_final_amount, 2) . "\n";
}

function exportToPDF($conn, $entries, $start_date, $end_date, $total_entries, $total_gross_weight, $total_less_weight, $total_net_weight, $total_deduction, $total_final_amount) {
    require_once __DIR__ . '/libs/fpdf.php';
    
    class PDF_OldSilver extends FPDF {
        function Header() {
            $this->SetFont('Arial', 'B', 14);
            $this->Cell(0, 10, 'Old Silver Purchase Report', 0, 1, 'C');
            $this->SetFont('Arial', '', 10);
            $this->Cell(0, 5, 'Generated on: ' . date('d-m-Y H:i:s'), 0, 1, 'C');
            $this->Ln(5);
        }
        
        function Footer() {
            $this->SetY(-15);
            $this->SetFont('Arial', 'I', 8);
            $this->Cell(0, 10, 'Page ' . $this->PageNo(), 0, 0, 'C');
        }
        
        function ReportTable($header, $data) {
            $this->SetFillColor(50, 50, 100);
            $this->SetTextColor(255);
            $this->SetFont('Arial', 'B', 8);
            
            $w = array(22, 22, 35, 20, 25, 25, 30);
            for($i=0; $i<count($header); $i++) {
                $this->Cell($w[$i], 7, $header[$i], 1, 0, 'C', true);
            }
            $this->Ln();
            
            $this->SetFillColor(255);
            $this->SetTextColor(0);
            $this->SetFont('Arial', '', 7);
            $fill = false;
            
            foreach($data as $row) {
                $this->Cell($w[0], 6, substr($row['entry_no'], 0, 10), 1, 0, 'L', $fill);
                $this->Cell($w[1], 6, date('d-m-y', strtotime($row['entry_date'])), 1, 0, 'C', $fill);
                $this->Cell($w[2], 6, substr($row['customer_name'], 0, 20), 1, 0, 'L', $fill);
                $this->Cell($w[3], 6, number_format($row['total_net_weight'], 0), 1, 0, 'R', $fill);
                $this->Cell($w[4], 6, number_format($row['final_amount'], 0), 1, 0, 'R', $fill);
                $this->Cell($w[5], 6, $row['adjustment_type'], 1, 0, 'C', $fill);
                $this->Cell($w[6], 6, substr($row['entry_no'], 0, 10), 1, 0, 'C', $fill);
                $this->Ln();
                $fill = !$fill;
            }
        }
    }
    
    $pdf = new PDF_OldSilver('L', 'mm', 'A4');
    $pdf->AddPage();
    $pdf->SetFont('Arial', '', 10);
    
    // Report Info
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 8, 'Report Period: ' . date('d-m-Y', strtotime($start_date)) . ' to ' . date('d-m-Y', strtotime($end_date)), 0, 1);
    $pdf->Ln(5);
    
    // Summary
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 8, 'SUMMARY', 0, 1);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(60, 6, 'Total Entries:', 0, 0);
    $pdf->Cell(40, 6, $total_entries, 0, 1);
    $pdf->Cell(60, 6, 'Total Net Weight:', 0, 0);
    $pdf->Cell(40, 6, number_format($total_net_weight, 2) . ' g', 0, 1);
    $pdf->Cell(60, 6, 'Total Final Amount:', 0, 0);
    $pdf->Cell(40, 6, '₹' . number_format($total_final_amount, 2), 0, 1);
    $pdf->Ln(8);
    
    // Table Header
    $header = array('Entry No', 'Date', 'Customer', 'Weight(g)', 'Amount', 'Type', 'Status');
    
    $pdf->ReportTable($header, $entries);
    $pdf->Output('D', 'old_silver_report_' . date('Y-m-d') . '.pdf');
}
?>
<!doctype html>
<html lang="en">
<?php include('includes/head.php'); ?>
<title>Old Silver Report | Reports</title>
<style>
    .report-card { border-radius: 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 24px; }
    .report-card .card-header { background: #f8fafc; border-bottom: 1px solid #eef2f6; padding: 16px 20px; font-weight: 600; }
    .report-card .card-body { padding: 20px; }
    .filter-section { background: #f8fafc; padding: 20px; border-radius: 12px; margin-bottom: 20px; }
    .summary-box { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 12px; padding: 20px; color: white; text-align: center; }
    .summary-value { font-size: 28px; font-weight: 700; }
    .summary-label { font-size: 12px; opacity: 0.9; margin-top: 5px; }
    .status-cash { background: #dcfce7; color: #166534; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 500; display: inline-block; }
    .status-exchange { background: #dbeafe; color: #1e40af; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 500; display: inline-block; }
    .status-pending { background: #fef3c7; color: #92400e; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 500; display: inline-block; }
    .btn-export { margin-right: 10px; }
    .item-badge { background: #e5e7eb; color: #4b5563; padding: 2px 8px; border-radius: 12px; font-size: 10px; }
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
                                    <h4 class="mb-1">Old Silver Purchase Report</h4>
                                    <p class="text-muted mb-0">Track and analyze old silver purchases and adjustments</p>
                                </div>
                                <div>
                                    <a href="old-silver-entry.php" class="btn btn-primary">
                                        <i class="fas fa-plus"></i> New Entry
                                    </a>
                                    <a href="old-silver-list.php" class="btn btn-outline-secondary">
                                        <i class="fas fa-list"></i> View All Entries
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Filter Section -->
                    <div class="filter-section">
                        <form method="GET" id="reportForm" class="row g-3">
                            <div class="col-md-2">
                                <label class="form-label">Date Range</label>
                                <select name="date_range" class="form-select" onchange="toggleCustomDate()">
                                    <option value="today" <?php echo $date_range == 'today' ? 'selected' : ''; ?>>Today</option>
                                    <option value="yesterday" <?php echo $date_range == 'yesterday' ? 'selected' : ''; ?>>Yesterday</option>
                                    <option value="week" <?php echo $date_range == 'week' ? 'selected' : ''; ?>>This Week</option>
                                    <option value="month" <?php echo $date_range == 'month' ? 'selected' : ''; ?>>This Month</option>
                                    <option value="custom" <?php echo $date_range == 'custom' ? 'selected' : ''; ?>>Custom Range</option>
                                </select>
                            </div>
                            <div class="col-md-2" id="fromDateDiv" style="display: <?php echo $date_range == 'custom' ? 'block' : 'none'; ?>">
                                <label class="form-label">From Date</label>
                                <input type="date" name="from_date" class="form-control" value="<?php echo h($from_date); ?>">
                            </div>
                            <div class="col-md-2" id="toDateDiv" style="display: <?php echo $date_range == 'custom' ? 'block' : 'none'; ?>">
                                <label class="form-label">To Date</label>
                                <input type="date" name="to_date" class="form-control" value="<?php echo h($to_date); ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Customer</label>
                                <select name="customer_id" class="form-select">
                                    <option value="0">All Customers</option>
                                    <?php foreach ($customers as $c): ?>
                                        <option value="<?php echo $c['id']; ?>" <?php echo $customer_id == $c['id'] ? 'selected' : ''; ?>>
                                            <?php echo h($c['customer_name']); ?> (<?php echo h($c['customer_code']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Adjustment Type</label>
                                <select name="adjustment_type" class="form-select">
                                    <option value="all" <?php echo $adjustment_type == 'all' ? 'selected' : ''; ?>>All Types</option>
                                    <option value="Cash" <?php echo $adjustment_type == 'Cash' ? 'selected' : ''; ?>>Cash Payment</option>
                                    <option value="Exchange" <?php echo $adjustment_type == 'Exchange' ? 'selected' : ''; ?>>Exchange</option>
                                    <option value="Pending" <?php echo $adjustment_type == 'Pending' ? 'selected' : ''; ?>>Pending</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Search</label>
                                <div class="input-group">
                                    <input type="text" name="search" class="form-control" placeholder="Entry No, Customer..." value="<?php echo h($search_term); ?>">
                                    <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i></button>
                                </div>
                            </div>
                            <div class="col-md-12 mt-3">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <a href="report-old-silver.php" class="btn btn-secondary">
                                            <i class="fas fa-times"></i> Reset Filters
                                        </a>
                                    </div>
                                    <div>
                                        <button type="button" class="btn btn-success btn-export" onclick="exportReport('excel')">
                                            <i class="fas fa-file-excel"></i> Export to Excel
                                        </button>
                                        <button type="button" class="btn btn-danger btn-export" onclick="exportReport('pdf')">
                                            <i class="fas fa-file-pdf"></i> Export to PDF
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>
                        
                        <!-- Active Filters Display -->
                        <?php if ($customer_id > 0 || $adjustment_type != 'all' || $date_range == 'custom' || $search_term): ?>
                            <div class="mt-3">
                                <small class="text-muted">Active Filters:</small>
                                <?php if ($customer_id > 0): ?>
                                    <span class="badge bg-info me-1">Customer ID: <?php echo $customer_id; ?></span>
                                <?php endif; ?>
                                <?php if ($adjustment_type != 'all'): ?>
                                    <span class="badge bg-info me-1">Type: <?php echo $adjustment_type; ?></span>
                                <?php endif; ?>
                                <?php if ($search_term): ?>
                                    <span class="badge bg-info me-1">Search: <?php echo h($search_term); ?></span>
                                <?php endif; ?>
                                <span class="text-muted ms-2">Found <?php echo $total_entries; ?> entries</span>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Summary Cards -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <div class="summary-box">
                                <div class="summary-value"><?php echo $total_entries; ?></div>
                                <div class="summary-label">Total Entries</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="summary-box" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                                <div class="summary-value"><?php echo formatWeight($total_net_weight); ?> g</div>
                                <div class="summary-label">Total Net Weight</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="summary-box" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                                <div class="summary-value">₹<?php echo money($total_final_amount); ?></div>
                                <div class="summary-label">Total Amount</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="summary-box" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                                <div class="summary-value">₹<?php echo money($total_deduction); ?></div>
                                <div class="summary-label">Total Deduction</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Adjustment Type Summary -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <div class="card report-card">
                                <div class="card-body text-center">
                                    <h4 class="text-success">₹<?php echo money($cash_total); ?></h4>
                                    <p class="mb-0">Cash Payments</p>
                                    <small class="text-muted">Direct cash settlement</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card report-card">
                                <div class="card-body text-center">
                                    <h4 class="text-primary">₹<?php echo money($exchange_total); ?></h4>
                                    <p class="mb-0">Exchange</p>
                                    <small class="text-muted">Adjusted against purchases</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card report-card">
                                <div class="card-body text-center">
                                    <h4 class="text-warning">₹<?php echo money($pending_total); ?></h4>
                                    <p class="mb-0">Pending</p>
                                    <small class="text-muted">Awaiting settlement</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Weight Summary Row -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <div class="card report-card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between">
                                        <span>Total Gross Weight:</span>
                                        <strong><?php echo formatWeight($total_gross_weight); ?> g</strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card report-card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between">
                                        <span>Total Less Weight:</span>
                                        <strong><?php echo formatWeight($total_less_weight); ?> g</strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card report-card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between">
                                        <span>Average Rate/g:</span>
                                        <strong>₹<?php echo $total_net_weight > 0 ? money($total_final_amount / $total_net_weight) : '0.00'; ?></strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Old Silver Entries Table -->
                    <div class="card report-card">
                        <div class="card-header bg-transparent">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">Old Silver Purchase Details</h5>
                                <div class="text-muted small">
                                    Period: <?php echo date('d-m-Y', strtotime($start_date)); ?> to <?php echo date('d-m-Y', strtotime($end_date)); ?>
                                </div>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Entry No</th>
                                            <th>Date</th>
                                            <th>Customer</th>
                                            <th>Mobile</th>
                                            <th>Items</th>
                                            <th>Gross Wt (g)</th>
                                            <th>Less (g)</th>
                                            <th>Net Wt (g)</th>
                                            <th>Rate/g</th>
                                            <th>Deduction %</th>
                                            <th>Deduction Amt</th>
                                            <th>Final Amount</th>
                                            <th>Adjustment Type</th>
                                            <th>Linked Sale</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($entries)): ?>
                                            <tr>
                                                <td colspan="15" class="text-center text-muted py-5">
                                                    <i class="fas fa-inbox fa-3x mb-3 d-block text-muted"></i>
                                                    No old silver entries found for the selected period.
                                                 </td>
                                            </tr>
                                        <?php else: foreach ($entries as $entry): ?>
                                            <tr>
                                                <td><strong><?php echo h($entry['entry_no']); ?></strong></td>
                                                <td><?php echo date('d-m-Y', strtotime($entry['entry_date'])); ?></td>
                                                <td>
                                                    <?php echo h($entry['customer_name']); ?>
                                                    <?php if ($entry['customer_code']): ?>
                                                        <br><small class="text-muted"><?php echo h($entry['customer_code']); ?></small>
                                                    <?php endif; ?>
                                                 </td>
                                                <td><?php echo h($entry['customer_mobile'] ?: $entry['customer_mobile_main']); ?></td>
                                                <td>
                                                    <span class="item-badge"><?php echo (int)$entry['item_count']; ?> items</span>
                                                    <?php if (!empty($entry['items'])): ?>
                                                        <br><small class="text-muted">
                                                        <?php 
                                                        $item_names = array_slice(array_column($entry['items'], 'item_name'), 0, 2);
                                                        echo h(implode(', ', $item_names));
                                                        if ($entry['item_count'] > 2) echo '...';
                                                        ?>
                                                        </small>
                                                    <?php endif; ?>
                                                 </td>
                                                <td class="text-end"><?php echo formatWeight($entry['total_gross_weight']); ?></td>
                                                <td class="text-end"><?php echo formatWeight($entry['total_less_weight']); ?></td>
                                                <td class="text-end"><strong><?php echo formatWeight($entry['total_net_weight']); ?></strong></td>
                                                <td class="text-end">₹<?php echo money($entry['rate_per_gram']); ?></td>
                                                <td class="text-end"><?php echo number_format($entry['deduction_percent'], 2); ?>%</td>
                                                <td class="text-end text-danger">₹<?php echo money($entry['deduction_amount']); ?></td>
                                                <td class="text-end"><strong class="text-success">₹<?php echo money($entry['final_amount']); ?></strong></td>
                                                <td>
                                                    <?php if ($entry['adjustment_type'] == 'Cash'): ?>
                                                        <span class="status-cash">Cash</span>
                                                    <?php elseif ($entry['adjustment_type'] == 'Exchange'): ?>
                                                        <span class="status-exchange">Exchange</span>
                                                    <?php else: ?>
                                                        <span class="status-pending">Pending</span>
                                                    <?php endif; ?>
                                                 </td>
                                                <td>
                                                    <?php if ($entry['linked_sale_id']): ?>
                                                        <span class="badge bg-info">Linked</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">No</span>
                                                    <?php endif; ?>
                                                 </td>
                                                <td>
                                                    <div class="btn-group">
                                                        <a href="old-silver-view.php?id=<?php echo $entry['id']; ?>" class="btn btn-sm btn-outline-info" title="View Details">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                        <?php if ($entry['adjustment_type'] == 'Pending'): ?>
                                                            <a href="old-silver-settle.php?id=<?php echo $entry['id']; ?>" class="btn btn-sm btn-outline-success" title="Settle">
                                                                <i class="fas fa-check"></i>
                                                            </a>
                                                        <?php endif; ?>
                                                    </div>
                                                 </td>
                                             </tr>
                                        <?php endforeach; endif; ?>
                                    </tbody>
                                    <?php if (!empty($entries)): ?>
                                    <tfoot class="table-light">
                                        <tr>
                                            <td colspan="5" class="text-end"><strong>Totals:</strong></td>
                                            <td class="text-end"><strong><?php echo formatWeight($total_gross_weight); ?></strong></td>
                                            <td class="text-end"><strong><?php echo formatWeight($total_less_weight); ?></strong></td>
                                            <td class="text-end"><strong><?php echo formatWeight($total_net_weight); ?></strong></td>
                                            <td colspan="3"></td>
                                            <td class="text-end"><strong>₹<?php echo money($total_final_amount); ?></strong></td>
                                            <td colspan="3"></td>
                                        </tr>
                                    </tfoot>
                                    <?php endif; ?>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Items Summary Section (if entries exist) -->
                    <?php if (!empty($entries)): ?>
                    <div class="card report-card">
                        <div class="card-header">
                            <i class="fas fa-list-alt me-2"></i> Items Summary (Top 10 Items by Weight)
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-sm table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Item Name</th>
                                            <th>Purity</th>
                                            <th>Total Gross Weight (g)</th>
                                            <th>Total Less Weight (g)</th>
                                            <th>Total Net Weight (g)</th>
                                            <th>Appearances</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        // Aggregate items across all entries
                                        $item_summary = [];
                                        foreach ($entries as $entry) {
                                            foreach ($entry['items'] as $item) {
                                                $key = $item['item_name'] . '|' . ($item['purity'] ?? 'N/A');
                                                if (!isset($item_summary[$key])) {
                                                    $item_summary[$key] = [
                                                        'name' => $item['item_name'],
                                                        'purity' => $item['purity'] ?? 'N/A',
                                                        'gross_weight' => 0,
                                                        'less_weight' => 0,
                                                        'net_weight' => 0,
                                                        'count' => 0
                                                    ];
                                                }
                                                $item_summary[$key]['gross_weight'] += $item['gross_weight'];
                                                $item_summary[$key]['less_weight'] += $item['less_weight'];
                                                $item_summary[$key]['net_weight'] += $item['net_weight'];
                                                $item_summary[$key]['count']++;
                                            }
                                        }
                                        
                                        // Sort by net weight descending and take top 10
                                        usort($item_summary, function($a, $b) {
                                            return $b['net_weight'] <=> $a['net_weight'];
                                        });
                                        $item_summary = array_slice($item_summary, 0, 10);
                                        ?>
                                        <?php if (empty($item_summary)): ?>
                                            <tr><td colspan="6" class="text-center text-muted">No item data available</td></tr>
                                        <?php else: foreach ($item_summary as $item): ?>
                                            <tr>
                                                <td><strong><?php echo h($item['name']); ?></strong></td>                                                <td><?php echo h($item['purity']); ?></td>
                                                <td class="text-end"><?php echo formatWeight($item['gross_weight']); ?></td>
                                                <td class="text-end"><?php echo formatWeight($item['less_weight']); ?></td>
                                                <td class="text-end"><strong><?php echo formatWeight($item['net_weight']); ?></strong></td>
                                                <td class="text-center"><?php echo $item['count']; ?> times</td>
                                            </tr>
                                        <?php endforeach; endif; ?>
                                    </tbody>
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
    
    <?php include('includes/scripts.php'); ?>
    
    <script>
        function toggleCustomDate() {
            const dateRange = document.querySelector('select[name="date_range"]').value;
            const fromDiv = document.getElementById('fromDateDiv');
            const toDiv = document.getElementById('toDateDiv');
            
            if (dateRange === 'custom') {
                fromDiv.style.display = 'block';
                toDiv.style.display = 'block';
            } else {
                fromDiv.style.display = 'none';
                toDiv.style.display = 'none';
            }
        }
        
        function exportReport(format) {
            const form = document.getElementById('reportForm');
            const formData = new FormData(form);
            const params = new URLSearchParams();
            
            for (let [key, value] of formData.entries()) {
                if (value) params.append(key, value);
            }
            params.append('export', '1');
            params.append('format', format);
            
            window.location.href = 'report-old-silver.php?' + params.toString();
        }
        
        // Initialize
        toggleCustomDate();
    </script>
</body>
</html>