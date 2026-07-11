<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/config.php';

if (!$conn || $conn->connect_error) {
    die('Database connection failed: ' . ($conn->connect_error ?? 'config error'));
}

$business_id = (int)($_SESSION['business_id'] ?? 1);
$currentPage = 'report-sales';

// Helper functions
if (!function_exists('h')) {
    function h($string) { return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('money')) {
    function money($amount) { return number_format((float)$amount, 2); }
}

// Get filter parameters
$date_range = $_GET['date_range'] ?? 'today';
$from_date = $_GET['from_date'] ?? '';
$to_date = $_GET['to_date'] ?? '';
$customer_id = (int)($_GET['customer_id'] ?? 0);
$bill_type = $_GET['bill_type'] ?? 'all';
$payment_status = $_GET['payment_status'] ?? 'all';
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
$where_conditions = ["s.business_id = $business_id", "s.status = 'Active'"];
$where_conditions[] = "DATE(s.bill_date) BETWEEN '$start_date' AND '$end_date'";

if ($customer_id > 0) {
    $where_conditions[] = "s.customer_id = $customer_id";
}
if ($bill_type != 'all') {
    $where_conditions[] = "s.bill_type = '" . $conn->real_escape_string($bill_type) . "'";
}
if ($payment_status != 'all') {
    $where_conditions[] = "s.payment_status = '" . $conn->real_escape_string($payment_status) . "'";
}

$where_clause = implode(" AND ", $where_conditions);

// Fetch sales data
$sales = [];
$sql = "SELECT s.*, c.customer_name, c.customer_code, c.mobile,
               (SELECT COUNT(*) FROM sale_items WHERE sale_id = s.id) as item_count
        FROM sales s
        LEFT JOIN customers c ON s.customer_id = c.id
        WHERE $where_clause
        ORDER BY s.bill_date DESC, s.id DESC";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $sales[] = $row;
    }
}

// Calculate summary statistics
$total_sales = count($sales);
$total_subtotal = array_sum(array_column($sales, 'subtotal'));
$total_discount = array_sum(array_column($sales, 'discount_amount'));
$total_taxable = array_sum(array_column($sales, 'taxable_amount'));
$total_cgst = array_sum(array_column($sales, 'cgst_amount'));
$total_sgst = array_sum(array_column($sales, 'sgst_amount'));
$total_igst = array_sum(array_column($sales, 'igst_amount'));
$total_grand = array_sum(array_column($sales, 'grand_total'));
$total_paid = array_sum(array_column($sales, 'paid_amount'));
$total_balance = array_sum(array_column($sales, 'balance_amount'));

// Calculate by bill type
$retail_total = array_sum(array_filter(array_column($sales, 'grand_total'), function($key) use ($sales) {
    return $sales[$key]['bill_type'] == 'Retail';
}, ARRAY_FILTER_USE_KEY));
$gst_total = array_sum(array_filter(array_column($sales, 'grand_total'), function($key) use ($sales) {
    return $sales[$key]['bill_type'] == 'GST';
}, ARRAY_FILTER_USE_KEY));
$estimate_total = array_sum(array_filter(array_column($sales, 'grand_total'), function($key) use ($sales) {
    return $sales[$key]['bill_type'] == 'Estimate';
}, ARRAY_FILTER_USE_KEY));
$exchange_total = array_sum(array_filter(array_column($sales, 'grand_total'), function($key) use ($sales) {
    return $sales[$key]['bill_type'] == 'Exchange';
}, ARRAY_FILTER_USE_KEY));

// Fetch customers for filter
$customers = [];
$cust_sql = "SELECT id, customer_name, customer_code FROM customers WHERE business_id = $business_id AND is_active = 1 ORDER BY customer_name";
$cust_res = $conn->query($cust_sql);
if ($cust_res) {
    while ($row = $cust_res->fetch_assoc()) {
        $customers[] = $row;
    }
}

// Handle Export
if ($export == '1') {
    if ($format == 'excel') {
        exportToExcel($sales, $start_date, $end_date, $total_subtotal, $total_discount, $total_taxable, $total_cgst, $total_sgst, $total_igst, $total_grand);
    } elseif ($format == 'pdf') {
        exportToPDF($conn, $sales, $start_date, $end_date, $total_subtotal, $total_discount, $total_taxable, $total_cgst, $total_sgst, $total_igst, $total_grand);
    }
    exit;
}

// Export functions
function exportToExcel($sales, $start_date, $end_date, $total_subtotal, $total_discount, $total_taxable, $total_cgst, $total_sgst, $total_igst, $total_grand) {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="sales_report_' . date('Y-m-d') . '.xls"');
    
    echo "Sales Report\n";
    echo "Period: " . date('d-m-Y', strtotime($start_date)) . " to " . date('d-m-Y', strtotime($end_date)) . "\n";
    echo "Generated On: " . date('d-m-Y H:i:s') . "\n\n";
    
    echo "Bill No\tBill Date\tCustomer Name\tMobile\tBill Type\tSubtotal\tDiscount\tTaxable\tCGST\tSGST\tIGST\tGrand Total\tPaid\tBalance\tStatus\n";
    
    foreach ($sales as $sale) {
        echo $sale['bill_no'] . "\t";
        echo date('d-m-Y', strtotime($sale['bill_date'])) . "\t";
        echo $sale['customer_name'] . "\t";
        echo $sale['customer_mobile'] . "\t";
        echo $sale['bill_type'] . "\t";
        echo number_format($sale['subtotal'], 2) . "\t";
        echo number_format($sale['discount_amount'], 2) . "\t";
        echo number_format($sale['taxable_amount'], 2) . "\t";
        echo number_format($sale['cgst_amount'], 2) . "\t";
        echo number_format($sale['sgst_amount'], 2) . "\t";
        echo number_format($sale['igst_amount'], 2) . "\t";
        echo number_format($sale['grand_total'], 2) . "\t";
        echo number_format($sale['paid_amount'], 2) . "\t";
        echo number_format($sale['balance_amount'], 2) . "\t";
        echo $sale['payment_status'] . "\n";
    }
    
    echo "\n\nSUMMARY\n";
    echo "Total Sales Count:\t" . count($sales) . "\n";
    echo "Total Subtotal:\t" . number_format($total_subtotal, 2) . "\n";
    echo "Total Discount:\t" . number_format($total_discount, 2) . "\n";
    echo "Total Taxable:\t" . number_format($total_taxable, 2) . "\n";
    echo "Total CGST:\t" . number_format($total_cgst, 2) . "\n";
    echo "Total SGST:\t" . number_format($total_sgst, 2) . "\n";
    echo "Total IGST:\t" . number_format($total_igst, 2) . "\n";
    echo "Total Grand Total:\t" . number_format($total_grand, 2) . "\n";
}

function exportToPDF($conn, $sales, $start_date, $end_date, $total_subtotal, $total_discount, $total_taxable, $total_cgst, $total_sgst, $total_igst, $total_grand) {
    require_once __DIR__ . '/libs/fpdf.php';
    
    class PDF_Sales extends FPDF {
        function Header() {
            $this->SetFont('Arial', 'B', 14);
            $this->Cell(0, 10, 'Sales Report', 0, 1, 'C');
            $this->SetFont('Arial', '', 10);
            $this->Cell(0, 5, 'Generated on: ' . date('d-m-Y H:i:s'), 0, 1, 'C');
            $this->Ln(5);
        }
        
        function Footer() {
            $this->SetY(-15);
            $this->SetFont('Arial', 'I', 8);
            $this->Cell(0, 10, 'Page ' . $this->PageNo(), 0, 0, 'C');
        }
        
        function SalesTable($header, $data) {
            $this->SetFillColor(50, 50, 100);
            $this->SetTextColor(255);
            $this->SetFont('Arial', 'B', 8);
            
            // Header
            $w = array(25, 25, 40, 25, 20, 25, 25, 25, 25);
            for($i=0; $i<count($header); $i++) {
                $this->Cell($w[$i], 7, $header[$i], 1, 0, 'C', true);
            }
            $this->Ln();
            
            // Data
            $this->SetFillColor(255);
            $this->SetTextColor(0);
            $this->SetFont('Arial', '', 7);
            $fill = false;
            
            foreach($data as $row) {
                $this->Cell($w[0], 6, substr($row['bill_no'], 0, 12), 1, 0, 'L', $fill);
                $this->Cell($w[1], 6, date('d-m-y', strtotime($row['bill_date'])), 1, 0, 'C', $fill);
                $this->Cell($w[2], 6, substr($row['customer_name'], 0, 20), 1, 0, 'L', $fill);
                $this->Cell($w[3], 6, substr($row['customer_mobile'], 0, 12), 1, 0, 'C', $fill);
                $this->Cell($w[4], 6, $row['bill_type'], 1, 0, 'C', $fill);
                $this->Cell($w[5], 6, number_format($row['grand_total'], 0), 1, 0, 'R', $fill);
                $this->Cell($w[6], 6, number_format($row['paid_amount'], 0), 1, 0, 'R', $fill);
                $this->Cell($w[7], 6, number_format($row['balance_amount'], 0), 1, 0, 'R', $fill);
                $this->Cell($w[8], 6, $row['payment_status'], 1, 0, 'C', $fill);
                $this->Ln();
                $fill = !$fill;
            }
        }
    }
    
    $pdf = new PDF_Sales('L', 'mm', 'A4');
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
    $pdf->Cell(50, 6, 'Total Sales:', 0, 0);
    $pdf->Cell(40, 6, count($data), 0, 1);
    $pdf->Cell(50, 6, 'Total Amount:', 0, 0);
    $pdf->Cell(40, 6, '₹' . number_format($total_grand, 2), 0, 1);
    $pdf->Cell(50, 6, 'Total Tax:', 0, 0);
    $pdf->Cell(40, 6, '₹' . number_format($total_cgst + $total_sgst + $total_igst, 2), 0, 1);
    $pdf->Ln(8);
    
    // Table Header
    $header = array('Bill No', 'Date', 'Customer', 'Mobile', 'Type', 'Amount', 'Paid', 'Balance', 'Status');
    
    $pdf->SalesTable($header, $sales);
    $pdf->Output('D', 'sales_report_' . date('Y-m-d') . '.pdf');
}
?>
<!doctype html>
<html lang="en">
<?php include('includes/head.php'); ?>
<title>Sales Report | Reports</title>
<style>
    .report-card { border-radius: 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 24px; }
    .report-card .card-header { background: #f8fafc; border-bottom: 1px solid #eef2f6; padding: 16px 20px; font-weight: 600; }
    .report-card .card-body { padding: 20px; }
    .filter-section { background: #f8fafc; padding: 20px; border-radius: 12px; margin-bottom: 20px; }
    .summary-box { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 12px; padding: 20px; color: white; text-align: center; }
    .summary-value { font-size: 28px; font-weight: 700; }
    .summary-label { font-size: 12px; opacity: 0.9; margin-top: 5px; }
    .status-paid { background: #dcfce7; color: #166534; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 500; display: inline-block; }
    .status-partial { background: #fef3c7; color: #92400e; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 500; display: inline-block; }
    .status-unpaid { background: #fee2e2; color: #991b1b; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 500; display: inline-block; }
    .btn-export { margin-right: 10px; }
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
                                    <h4 class="mb-1">Sales Report</h4>
                                    <p class="text-muted mb-0">View and export sales reports with advanced filtering</p>
                                </div>
                                <div>
                                    <a href="index.php" class="btn btn-outline-secondary">
                                        <i class="fas fa-arrow-left"></i> Back to Dashboard
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
                                <label class="form-label">Bill Type</label>
                                <select name="bill_type" class="form-select">
                                    <option value="all" <?php echo $bill_type == 'all' ? 'selected' : ''; ?>>All Types</option>
                                    <option value="Retail" <?php echo $bill_type == 'Retail' ? 'selected' : ''; ?>>Retail</option>
                                    <option value="GST" <?php echo $bill_type == 'GST' ? 'selected' : ''; ?>>GST</option>
                                    <option value="Estimate" <?php echo $bill_type == 'Estimate' ? 'selected' : ''; ?>>Estimate</option>
                                    <option value="Exchange" <?php echo $bill_type == 'Exchange' ? 'selected' : ''; ?>>Exchange</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Payment Status</label>
                                <select name="payment_status" class="form-select">
                                    <option value="all" <?php echo $payment_status == 'all' ? 'selected' : ''; ?>>All Status</option>
                                    <option value="Paid" <?php echo $payment_status == 'Paid' ? 'selected' : ''; ?>>Paid</option>
                                    <option value="Partial" <?php echo $payment_status == 'Partial' ? 'selected' : ''; ?>>Partial</option>
                                    <option value="Unpaid" <?php echo $payment_status == 'Unpaid' ? 'selected' : ''; ?>>Unpaid</option>
                                </select>
                            </div>
                            <div class="col-md-12 mt-3">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-search"></i> Apply Filters
                                        </button>
                                        <a href="report-sales.php" class="btn btn-secondary">
                                            <i class="fas fa-times"></i> Reset
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
                        <?php if ($customer_id > 0 || $bill_type != 'all' || $payment_status != 'all' || $date_range == 'custom'): ?>
                            <div class="mt-3">
                                <small class="text-muted">Active Filters:</small>
                                <?php if ($customer_id > 0): ?>
                                    <span class="badge bg-info me-1">Customer ID: <?php echo $customer_id; ?></span>
                                <?php endif; ?>
                                <?php if ($bill_type != 'all'): ?>
                                    <span class="badge bg-info me-1">Bill Type: <?php echo $bill_type; ?></span>
                                <?php endif; ?>
                                <?php if ($payment_status != 'all'): ?>
                                    <span class="badge bg-info me-1">Payment: <?php echo $payment_status; ?></span>
                                <?php endif; ?>
                                <span class="text-muted ms-2">Found <?php echo $total_sales; ?> records</span>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Summary Cards -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <div class="summary-box">
                                <div class="summary-value"><?php echo $total_sales; ?></div>
                                <div class="summary-label">Total Invoices</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="summary-box" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                                <div class="summary-value">₹<?php echo money($total_grand); ?></div>
                                <div class="summary-label">Total Sales</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="summary-box" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                                <div class="summary-value">₹<?php echo money($total_paid); ?></div>
                                <div class="summary-label">Amount Received</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="summary-box" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                                <div class="summary-value">₹<?php echo money($total_balance); ?></div>
                                <div class="summary-label">Outstanding</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Bill Type Summary -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <div class="card report-card">
                                <div class="card-body text-center">
                                    <h4 class="text-primary">₹<?php echo money($retail_total); ?></h4>
                                    <p class="mb-0">Retail Sales</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card report-card">
                                <div class="card-body text-center">
                                    <h4 class="text-success">₹<?php echo money($gst_total); ?></h4>
                                    <p class="mb-0">GST Sales</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card report-card">
                                <div class="card-body text-center">
                                    <h4 class="text-warning">₹<?php echo money($estimate_total); ?></h4>
                                    <p class="mb-0">Estimates</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card report-card">
                                <div class="card-body text-center">
                                    <h4 class="text-info">₹<?php echo money($exchange_total); ?></h4>
                                    <p class="mb-0">Exchange Sales</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Tax Summary Row -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <div class="card report-card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between">
                                        <span>CGST Collected:</span>
                                        <strong>₹<?php echo money($total_cgst); ?></strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card report-card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between">
                                        <span>SGST Collected:</span>
                                        <strong>₹<?php echo money($total_sgst); ?></strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card report-card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between">
                                        <span>IGST Collected:</span>
                                        <strong>₹<?php echo money($total_igst); ?></strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Sales Table -->
                    <div class="card report-card">
                        <div class="card-header bg-transparent">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">Sales Details</h5>
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
                                            <th>Bill No</th>
                                            <th>Date</th>
                                            <th>Customer</th>
                                            <th>Mobile</th>
                                            <th>Type</th>
                                            <th>Items</th>
                                            <th>Subtotal</th>
                                            <th>Discount</th>
                                            <th>Taxable</th>
                                            <th>CGST</th>
                                            <th>SGST</th>
                                            <th>IGST</th>
                                            <th>Grand Total</th>
                                            <th>Paid</th>
                                            <th>Balance</th>
                                            <th>Status</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($sales)): ?>
                                            <tr>
                                                <td colspan="17" class="text-center text-muted py-5">
                                                    <i class="fas fa-inbox fa-3x mb-3 d-block text-muted"></i>
                                                    No sales records found for the selected period.
                                                </td>
                                            </table>
                                        <?php else: foreach ($sales as $sale): ?>
                                            <tr>
                                                <td><strong><?php echo h($sale['bill_no']); ?></strong></td>
                                                <td><?php echo date('d-m-Y', strtotime($sale['bill_date'])); ?><br><small><?php echo date('h:i A', strtotime($sale['bill_time'])); ?></small></td>
                                                <td>
                                                    <?php echo h($sale['customer_name'] ?? 'Walk-in Customer'); ?><br>
                                                    <small class="text-muted"><?php echo h($sale['customer_code'] ?? ''); ?></small>
                                                 </td>
                                                <td><?php echo h($sale['customer_mobile'] ?? '-'); ?></td>
                                                <td>
                                                    <?php if ($sale['bill_type'] == 'Retail'): ?>
                                                        <span class="badge bg-secondary">Retail</span>
                                                    <?php elseif ($sale['bill_type'] == 'GST'): ?>
                                                        <span class="badge bg-primary">GST</span>
                                                    <?php elseif ($sale['bill_type'] == 'Estimate'): ?>
                                                        <span class="badge bg-warning">Estimate</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-info">Exchange</span>
                                                    <?php endif; ?>
                                                 </td>
                                                <td><?php echo (int)$sale['item_count']; ?></td>
                                                <td class="text-end">₹<?php echo money($sale['subtotal']); ?></td>
                                                <td class="text-end text-danger">₹<?php echo money($sale['discount_amount']); ?></td>
                                                <td class="text-end">₹<?php echo money($sale['taxable_amount']); ?></td>
                                                <td class="text-end">₹<?php echo money($sale['cgst_amount']); ?></td>
                                                <td class="text-end">₹<?php echo money($sale['sgst_amount']); ?></td>
                                                <td class="text-end">₹<?php echo money($sale['igst_amount']); ?></td>
                                                <td class="text-end"><strong>₹<?php echo money($sale['grand_total']); ?></strong></td>
                                                <td class="text-end text-success">₹<?php echo money($sale['paid_amount']); ?></td>
                                                <td class="text-end text-danger">₹<?php echo money($sale['balance_amount']); ?></td>
                                                <td>
                                                    <?php if ($sale['payment_status'] == 'Paid'): ?>
                                                        <span class="status-paid">Paid</span>
                                                    <?php elseif ($sale['payment_status'] == 'Partial'): ?>
                                                        <span class="status-partial">Partial</span>
                                                    <?php else: ?>
                                                        <span class="status-unpaid">Unpaid</span>
                                                    <?php endif; ?>
                                                 </td>
                                                <td>
                                                    <a href="sales-view.php?id=<?php echo $sale['id']; ?>" class="btn btn-sm btn-outline-info" title="View Invoice">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <a href="invoice.php?id=<?php echo $sale['id']; ?>" class="btn btn-sm btn-outline-secondary" title="Print Invoice" target="_blank">
                                                        <i class="fas fa-print"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; endif; ?>
                                    </tbody>
                                    <?php if (!empty($sales)): ?>
                                    <tfoot class="table-light">
                                        <tr>
                                            <td colspan="6" class="text-end"><strong>Totals:</strong></td>
                                            <td class="text-end"><strong>₹<?php echo money($total_subtotal); ?></strong></td>
                                            <td class="text-end"><strong>₹<?php echo money($total_discount); ?></strong></td>
                                            <td class="text-end"><strong>₹<?php echo money($total_taxable); ?></strong></td>
                                            <td class="text-end"><strong>₹<?php echo money($total_cgst); ?></strong></td>
                                            <td class="text-end"><strong>₹<?php echo money($total_sgst); ?></strong></td>
                                            <td class="text-end"><strong>₹<?php echo money($total_igst); ?></strong></td>
                                            <td class="text-end"><strong>₹<?php echo money($total_grand); ?></strong></td>
                                            <td class="text-end"><strong>₹<?php echo money($total_paid); ?></strong></td>
                                            <td class="text-end"><strong>₹<?php echo money($total_balance); ?></strong></td>
                                            <td colspan="2"></td>
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
            
            window.location.href = 'report-sales.php?' + params.toString();
        }
        
        // Initialize
        toggleCustomDate();
    </script>
</body>
</html>