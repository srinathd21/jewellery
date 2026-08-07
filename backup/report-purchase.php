<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/config.php';

if (!$conn || $conn->connect_error) {
    die('Database connection failed: ' . ($conn->connect_error ?? 'config error'));
}

$business_id = (int)($_SESSION['business_id'] ?? 1);
$currentPage = 'report-purchase';

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
$supplier_id = (int)($_GET['supplier_id'] ?? 0);
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
$where_conditions = ["p.business_id = $business_id"];
$where_conditions[] = "DATE(p.purchase_date) BETWEEN '$start_date' AND '$end_date'";

if ($supplier_id > 0) {
    $where_conditions[] = "p.supplier_id = $supplier_id";
}
if ($payment_status != 'all') {
    $where_conditions[] = "p.payment_status = '" . $conn->real_escape_string($payment_status) . "'";
}

$where_clause = implode(" AND ", $where_conditions);

// Fetch purchase data
$purchases = [];
$sql = "SELECT p.*, s.supplier_name, s.supplier_code, s.mobile, s.gstin,
               (SELECT COUNT(*) FROM purchase_items WHERE purchase_id = p.id) as item_count,
               (SELECT SUM(total_amount) FROM purchase_items WHERE purchase_id = p.id) as items_total
        FROM purchases p
        LEFT JOIN suppliers s ON p.supplier_id = s.id
        WHERE $where_clause
        ORDER BY p.purchase_date DESC, p.id DESC";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $purchases[] = $row;
    }
}

// Calculate summary statistics
$total_purchases = count($purchases);
$total_subtotal = array_sum(array_column($purchases, 'subtotal'));
$total_discount = array_sum(array_column($purchases, 'discount_amount'));
$total_taxable = array_sum(array_column($purchases, 'taxable_amount'));
$total_cgst = array_sum(array_column($purchases, 'cgst_amount'));
$total_sgst = array_sum(array_column($purchases, 'sgst_amount'));
$total_igst = array_sum(array_column($purchases, 'igst_amount'));
$total_grand = array_sum(array_column($purchases, 'grand_total'));
$total_paid = array_sum(array_column($purchases, 'paid_amount'));
$total_balance = array_sum(array_column($purchases, 'balance_amount'));

// Calculate by payment status
$paid_total = array_sum(array_filter(array_column($purchases, 'grand_total'), function($key) use ($purchases) {
    return $purchases[$key]['payment_status'] == 'Paid';
}, ARRAY_FILTER_USE_KEY));
$partial_total = array_sum(array_filter(array_column($purchases, 'grand_total'), function($key) use ($purchases) {
    return $purchases[$key]['payment_status'] == 'Partial';
}, ARRAY_FILTER_USE_KEY));
$unpaid_total = array_sum(array_filter(array_column($purchases, 'grand_total'), function($key) use ($purchases) {
    return $purchases[$key]['payment_status'] == 'Unpaid';
}, ARRAY_FILTER_USE_KEY));

// Fetch suppliers for filter
$suppliers = [];
$sup_sql = "SELECT id, supplier_name, supplier_code FROM suppliers WHERE business_id = $business_id AND is_active = 1 ORDER BY supplier_name";
$sup_res = $conn->query($sup_sql);
if ($sup_res) {
    while ($row = $sup_res->fetch_assoc()) {
        $suppliers[] = $row;
    }
}

// Handle Export
if ($export == '1') {
    if ($format == 'excel') {
        exportToExcel($purchases, $start_date, $end_date, $total_subtotal, $total_discount, $total_taxable, $total_cgst, $total_sgst, $total_igst, $total_grand, $total_paid, $total_balance);
    } elseif ($format == 'pdf') {
        exportToPDF($conn, $purchases, $start_date, $end_date, $total_subtotal, $total_discount, $total_taxable, $total_cgst, $total_sgst, $total_igst, $total_grand, $total_paid, $total_balance);
    }
    exit;
}

// Export functions
function exportToExcel($purchases, $start_date, $end_date, $total_subtotal, $total_discount, $total_taxable, $total_cgst, $total_sgst, $total_igst, $total_grand, $total_paid, $total_balance) {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="purchase_report_' . date('Y-m-d') . '.xls"');
    
    echo "Purchase Report\n";
    echo "Period: " . date('d-m-Y', strtotime($start_date)) . " to " . date('d-m-Y', strtotime($end_date)) . "\n";
    echo "Generated On: " . date('d-m-Y H:i:s') . "\n\n";
    
    echo "Purchase No\tPurchase Date\tSupplier Name\tMobile\tInvoice No\tSubtotal\tDiscount\tTaxable\tCGST\tSGST\tIGST\tGrand Total\tPaid\tBalance\tStatus\n";
    
    foreach ($purchases as $purchase) {
        echo $purchase['purchase_no'] . "\t";
        echo date('d-m-Y', strtotime($purchase['purchase_date'])) . "\t";
        echo $purchase['supplier_name'] . "\t";
        echo $purchase['mobile'] . "\t";
        echo $purchase['invoice_no'] . "\t";
        echo number_format($purchase['subtotal'], 2) . "\t";
        echo number_format($purchase['discount_amount'], 2) . "\t";
        echo number_format($purchase['taxable_amount'], 2) . "\t";
        echo number_format($purchase['cgst_amount'], 2) . "\t";
        echo number_format($purchase['sgst_amount'], 2) . "\t";
        echo number_format($purchase['igst_amount'], 2) . "\t";
        echo number_format($purchase['grand_total'], 2) . "\t";
        echo number_format($purchase['paid_amount'], 2) . "\t";
        echo number_format($purchase['balance_amount'], 2) . "\t";
        echo $purchase['payment_status'] . "\n";
    }
    
    echo "\n\nSUMMARY\n";
    echo "Total Purchases Count:\t" . count($purchases) . "\n";
    echo "Total Subtotal:\t" . number_format($total_subtotal, 2) . "\n";
    echo "Total Discount:\t" . number_format($total_discount, 2) . "\n";
    echo "Total Taxable:\t" . number_format($total_taxable, 2) . "\n";
    echo "Total CGST:\t" . number_format($total_cgst, 2) . "\n";
    echo "Total SGST:\t" . number_format($total_sgst, 2) . "\n";
    echo "Total IGST:\t" . number_format($total_igst, 2) . "\n";
    echo "Total Grand Total:\t" . number_format($total_grand, 2) . "\n";
    echo "Total Paid:\t" . number_format($total_paid, 2) . "\n";
    echo "Total Balance:\t" . number_format($total_balance, 2) . "\n";
}

function exportToPDF($conn, $purchases, $start_date, $end_date, $total_subtotal, $total_discount, $total_taxable, $total_cgst, $total_sgst, $total_igst, $total_grand, $total_paid, $total_balance) {
    require_once __DIR__ . '/libs/fpdf.php';
    
    class PDF_Purchase extends FPDF {
        function Header() {
            $this->SetFont('Arial', 'B', 14);
            $this->Cell(0, 10, 'Purchase Report', 0, 1, 'C');
            $this->SetFont('Arial', '', 10);
            $this->Cell(0, 5, 'Generated on: ' . date('d-m-Y H:i:s'), 0, 1, 'C');
            $this->Ln(5);
        }
        
        function Footer() {
            $this->SetY(-15);
            $this->SetFont('Arial', 'I', 8);
            $this->Cell(0, 10, 'Page ' . $this->PageNo(), 0, 0, 'C');
        }
        
        function PurchaseTable($header, $data) {
            $this->SetFillColor(50, 50, 100);
            $this->SetTextColor(255);
            $this->SetFont('Arial', 'B', 8);
            
            $w = array(25, 22, 40, 20, 20, 25, 25, 20);
            for($i=0; $i<count($header); $i++) {
                $this->Cell($w[$i], 7, $header[$i], 1, 0, 'C', true);
            }
            $this->Ln();
            
            $this->SetFillColor(255);
            $this->SetTextColor(0);
            $this->SetFont('Arial', '', 7);
            $fill = false;
            
            foreach($data as $row) {
                $this->Cell($w[0], 6, substr($row['purchase_no'], 0, 12), 1, 0, 'L', $fill);
                $this->Cell($w[1], 6, date('d-m-y', strtotime($row['purchase_date'])), 1, 0, 'C', $fill);
                $this->Cell($w[2], 6, substr($row['supplier_name'], 0, 20), 1, 0, 'L', $fill);
                $this->Cell($w[3], 6, substr($row['invoice_no'], 0, 10), 1, 0, 'L', $fill);
                $this->Cell($w[4], 6, number_format($row['grand_total'], 0), 1, 0, 'R', $fill);
                $this->Cell($w[5], 6, number_format($row['paid_amount'], 0), 1, 0, 'R', $fill);
                $this->Cell($w[6], 6, number_format($row['balance_amount'], 0), 1, 0, 'R', $fill);
                $this->Cell($w[7], 6, $row['payment_status'], 1, 0, 'C', $fill);
                $this->Ln();
                $fill = !$fill;
            }
        }
    }
    
    $pdf = new PDF_Purchase('L', 'mm', 'A4');
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
    $pdf->Cell(60, 6, 'Total Purchases:', 0, 0);
    $pdf->Cell(40, 6, count($purchases), 0, 1);
    $pdf->Cell(60, 6, 'Total Amount:', 0, 0);
    $pdf->Cell(40, 6, '₹' . number_format($total_grand, 2), 0, 1);
    $pdf->Cell(60, 6, 'Total Paid:', 0, 0);
    $pdf->Cell(40, 6, '₹' . number_format($total_paid, 2), 0, 1);
    $pdf->Cell(60, 6, 'Total Balance:', 0, 0);
    $pdf->Cell(40, 6, '₹' . number_format($total_balance, 2), 0, 1);
    $pdf->Ln(8);
    
    // Table Header
    $header = array('Purchase No', 'Date', 'Supplier', 'Invoice', 'Amount', 'Paid', 'Balance', 'Status');
    
    $pdf->PurchaseTable($header, $purchases);
    $pdf->Output('D', 'purchase_report_' . date('Y-m-d') . '.pdf');
}
?>
<!doctype html>
<html lang="en">
<?php include('includes/head.php'); ?>
<title>Purchase Report | Reports</title>
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
                                    <h4 class="mb-1">Purchase Report</h4>
                                    <p class="text-muted mb-0">View and export purchase reports with advanced filtering</p>
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
                            <div class="col-md-3">
                                <label class="form-label">Supplier</label>
                                <select name="supplier_id" class="form-select">
                                    <option value="0">All Suppliers</option>
                                    <?php foreach ($suppliers as $s): ?>
                                        <option value="<?php echo $s['id']; ?>" <?php echo $supplier_id == $s['id'] ? 'selected' : ''; ?>>
                                            <?php echo h($s['supplier_name']); ?> (<?php echo h($s['supplier_code']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
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
                                        <a href="report-purchase.php" class="btn btn-secondary">
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
                        <?php if ($supplier_id > 0 || $payment_status != 'all' || $date_range == 'custom'): ?>
                            <div class="mt-3">
                                <small class="text-muted">Active Filters:</small>
                                <?php if ($supplier_id > 0): ?>
                                    <span class="badge bg-info me-1">Supplier ID: <?php echo $supplier_id; ?></span>
                                <?php endif; ?>
                                <?php if ($payment_status != 'all'): ?>
                                    <span class="badge bg-info me-1">Payment: <?php echo $payment_status; ?></span>
                                <?php endif; ?>
                                <span class="text-muted ms-2">Found <?php echo $total_purchases; ?> records</span>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Summary Cards -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <div class="summary-box">
                                <div class="summary-value"><?php echo $total_purchases; ?></div>
                                <div class="summary-label">Total Purchases</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="summary-box" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                                <div class="summary-value">₹<?php echo money($total_grand); ?></div>
                                <div class="summary-label">Total Purchase Value</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="summary-box" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                                <div class="summary-value">₹<?php echo money($total_paid); ?></div>
                                <div class="summary-label">Amount Paid</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="summary-box" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                                <div class="summary-value">₹<?php echo money($total_balance); ?></div>
                                <div class="summary-label">Balance Payable</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Payment Status Summary -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <div class="card report-card">
                                <div class="card-body text-center">
                                    <h4 class="text-success">₹<?php echo money($paid_total); ?></h4>
                                    <p class="mb-0">Paid Purchases</p>
                                    <small class="text-muted">Fully settled</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card report-card">
                                <div class="card-body text-center">
                                    <h4 class="text-warning">₹<?php echo money($partial_total); ?></h4>
                                    <p class="mb-0">Partial Payments</p>
                                    <small class="text-muted">Partially paid</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card report-card">
                                <div class="card-body text-center">
                                    <h4 class="text-danger">₹<?php echo money($unpaid_total); ?></h4>
                                    <p class="mb-0">Unpaid Purchases</p>
                                    <small class="text-muted">Pending payment</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Tax Summary Row -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <div class="card report-card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between">
                                        <span>Subtotal:</span>
                                        <strong>₹<?php echo money($total_subtotal); ?></strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card report-card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between">
                                        <span>Discount:</span>
                                        <strong class="text-danger">₹<?php echo money($total_discount); ?></strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card report-card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between">
                                        <span>Taxable Value:</span>
                                        <strong>₹<?php echo money($total_taxable); ?></strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card report-card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between">
                                        <span>Total Tax:</span>
                                        <strong>₹<?php echo money($total_cgst + $total_sgst + $total_igst); ?></strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Tax Breakdown Row -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <div class="card report-card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between">
                                        <span>CGST:</span>
                                        <strong>₹<?php echo money($total_cgst); ?></strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card report-card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between">
                                        <span>SGST:</span>
                                        <strong>₹<?php echo money($total_sgst); ?></strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card report-card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between">
                                        <span>IGST:</span>
                                        <strong>₹<?php echo money($total_igst); ?></strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Purchase Table -->
                    <div class="card report-card">
                        <div class="card-header bg-transparent">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">Purchase Details</h5>
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
                                            <th>Purchase No</th>
                                            <th>Date</th>
                                            <th>Supplier</th>
                                            <th>Mobile</th>
                                            <th>Invoice No</th>
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
                                        <?php if (empty($purchases)): ?>
                                            <tr>
                                                <td colspan="17" class="text-center text-muted py-5">
                                                    <i class="fas fa-inbox fa-3x mb-3 d-block text-muted"></i>
                                                    No purchase records found for the selected period.
                                                 </td>
                                            </tr>
                                        <?php else: foreach ($purchases as $purchase): ?>
                                            <tr>
                                                <td><strong><?php echo h($purchase['purchase_no']); ?></strong></td>
                                                <td><?php echo date('d-m-Y', strtotime($purchase['purchase_date'])); ?></td>
                                                <td>
                                                    <?php echo h($purchase['supplier_name']); ?><br>
                                                    <small class="text-muted"><?php echo h($purchase['supplier_code'] ?? ''); ?></small>
                                                  </td>
                                                <td><?php echo h($purchase['mobile'] ?? '-'); ?></td>
                                                <td><?php echo h($purchase['invoice_no'] ?? '-'); ?></td>
                                                <td><?php echo (int)$purchase['item_count']; ?></td>
                                                <td class="text-end">₹<?php echo money($purchase['subtotal']); ?></td>
                                                <td class="text-end text-danger">₹<?php echo money($purchase['discount_amount']); ?></td>
                                                <td class="text-end">₹<?php echo money($purchase['taxable_amount']); ?></td>
                                                <td class="text-end">₹<?php echo money($purchase['cgst_amount']); ?></td>
                                                <td class="text-end">₹<?php echo money($purchase['sgst_amount']); ?></td>
                                                <td class="text-end">₹<?php echo money($purchase['igst_amount']); ?></td>
                                                <td class="text-end"><strong>₹<?php echo money($purchase['grand_total']); ?></strong></td>
                                                <td class="text-end text-success">₹<?php echo money($purchase['paid_amount']); ?></td>
                                                <td class="text-end text-danger">₹<?php echo money($purchase['balance_amount']); ?></td>
                                                <td>
                                                    <?php if ($purchase['payment_status'] == 'Paid'): ?>
                                                        <span class="status-paid">Paid</span>
                                                    <?php elseif ($purchase['payment_status'] == 'Partial'): ?>
                                                        <span class="status-partial">Partial</span>
                                                    <?php else: ?>
                                                        <span class="status-unpaid">Unpaid</span>
                                                    <?php endif; ?>
                                                  </td>
                                                <td>
                                                    <a href="purchase-view.php?id=<?php echo $purchase['id']; ?>" class="btn btn-sm btn-outline-info" title="View Purchase">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <a href="supplier-payments.php?purchase_id=<?php echo $purchase['id']; ?>" class="btn btn-sm btn-outline-success" title="Make Payment">
                                                        <i class="fas fa-rupee-sign"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; endif; ?>
                                    </tbody>
                                    <?php if (!empty($purchases)): ?>
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
            
            window.location.href = 'report-purchase.php?' + params.toString();
        }
        
        // Initialize
        toggleCustomDate();
    </script>
</body>
</html>