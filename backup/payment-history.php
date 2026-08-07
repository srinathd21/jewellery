<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/config.php';

if (!$conn || $conn->connect_error) {
    die('Database connection failed: ' . ($conn->connect_error ?? 'config error'));
}

$business_id = (int)($_SESSION['business_id'] ?? 1);
$currentPage = 'payment-history';

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
$payment_type = $_GET['payment_type'] ?? 'all';
$customer_id = (int)($_GET['customer_id'] ?? 0);
$supplier_id = (int)($_GET['supplier_id'] ?? 0);
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

if ($from_date && $to_date) {
    $start_date = $from_date;
    $end_date = $to_date;
    $date_range = 'custom';
}

// ==================== CUSTOMER PAYMENTS ====================
$customer_payments_where = "cp.business_id = $business_id AND DATE(cp.receipt_date) BETWEEN '$start_date' AND '$end_date'";
if ($customer_id > 0) {
    $customer_payments_where .= " AND cp.customer_id = $customer_id";
}
if ($search_term) {
    $search = $conn->real_escape_string($search_term);
    $customer_payments_where .= " AND (c.customer_name LIKE '%$search%' OR cp.receipt_no LIKE '%$search%' OR cp.reference_no LIKE '%$search%')";
}

$customer_payments_query = "SELECT cp.*, c.customer_name, c.customer_code, c.mobile,
                                   pm.method_name as payment_method_name
                            FROM customer_payments cp
                            LEFT JOIN customers c ON cp.customer_id = c.id
                            LEFT JOIN payment_methods pm ON cp.payment_method_id = pm.id
                            WHERE $customer_payments_where
                            ORDER BY cp.receipt_date DESC, cp.id DESC";
$customer_payments_result = $conn->query($customer_payments_query);
$customer_payments = [];
$total_customer_payments = 0;
if ($customer_payments_result) {
    while ($row = $customer_payments_result->fetch_assoc()) {
        $customer_payments[] = $row;
        $total_customer_payments += $row['amount'];
    }
}

// ==================== SUPPLIER PAYMENTS ====================
$supplier_payments_where = "sp.business_id = $business_id AND DATE(sp.payment_date) BETWEEN '$start_date' AND '$end_date'";
if ($supplier_id > 0) {
    $supplier_payments_where .= " AND sp.supplier_id = $supplier_id";
}
if ($search_term) {
    $search = $conn->real_escape_string($search_term);
    $supplier_payments_where .= " AND (s.supplier_name LIKE '%$search%' OR sp.payment_no LIKE '%$search%' OR sp.reference_no LIKE '%$search%')";
}

$supplier_payments_query = "SELECT sp.*, s.supplier_name, s.supplier_code, s.mobile,
                                   pm.method_name as payment_method_name
                            FROM supplier_payments sp
                            LEFT JOIN suppliers s ON sp.supplier_id = s.id
                            LEFT JOIN payment_methods pm ON sp.payment_method_id = pm.id
                            WHERE $supplier_payments_where
                            ORDER BY sp.payment_date DESC, sp.id DESC";
$supplier_payments_result = $conn->query($supplier_payments_query);
$supplier_payments = [];
$total_supplier_payments = 0;
if ($supplier_payments_result) {
    while ($row = $supplier_payments_result->fetch_assoc()) {
        $supplier_payments[] = $row;
        $total_supplier_payments += $row['amount'];
    }
}

// ==================== PAWN PAYMENTS ====================
$pawn_payments_where = "pp.business_id = $business_id AND DATE(pp.payment_date) BETWEEN '$start_date' AND '$end_date'";
if ($search_term) {
    $search = $conn->real_escape_string($search_term);
    $pawn_payments_where .= " AND (pe.customer_name LIKE '%$search%' OR pe.pawn_no LIKE '%$search%' OR pp.receipt_no LIKE '%$search%')";
}

$pawn_payments_query = "SELECT pp.*, pe.pawn_no, pe.customer_name, pe.customer_mobile,
                               pm.method_name as payment_method_name
                        FROM pawn_payments pp
                        LEFT JOIN pawn_entries pe ON pp.pawn_id = pe.id
                        LEFT JOIN payment_methods pm ON pp.payment_method_id = pm.id
                        WHERE $pawn_payments_where
                        ORDER BY pp.payment_date DESC, pp.id DESC";
$pawn_payments_result = $conn->query($pawn_payments_query);
$pawn_payments = [];
$total_pawn_payments = 0;
if ($pawn_payments_result) {
    while ($row = $pawn_payments_result->fetch_assoc()) {
        $pawn_payments[] = $row;
        $total_pawn_payments += $row['total_amount'];
    }
}

// ==================== PAWN INTEREST COLLECTIONS ====================
$pawn_interest_where = "pic.business_id = $business_id AND DATE(pic.collection_date) BETWEEN '$start_date' AND '$end_date'";
if ($search_term) {
    $search = $conn->real_escape_string($search_term);
    $pawn_interest_where .= " AND (pe.customer_name LIKE '%$search%' OR pe.pawn_no LIKE '%$search%' OR pic.receipt_no LIKE '%$search%')";
}

$pawn_interest_query = "SELECT pic.*, pe.pawn_no, pe.customer_name, pe.customer_mobile,
                               pm.method_name as payment_method_name
                        FROM pawn_interest_collections pic
                        LEFT JOIN pawn_entries pe ON pic.pawn_id = pe.id
                        LEFT JOIN payment_methods pm ON pic.payment_method_id = pm.id
                        WHERE $pawn_interest_where
                        ORDER BY pic.collection_date DESC, pic.id DESC";
$pawn_interest_result = $conn->query($pawn_interest_query);
$pawn_interests = [];
$total_pawn_interest = 0;
if ($pawn_interest_result) {
    while ($row = $pawn_interest_result->fetch_assoc()) {
        $pawn_interests[] = $row;
        $total_pawn_interest += $row['net_amount'];
    }
}

// ==================== CHIT COLLECTIONS ====================
$chit_collections_where = "cc.business_id = $business_id AND DATE(cc.collection_date) BETWEEN '$start_date' AND '$end_date'";
if ($search_term) {
    $search = $conn->real_escape_string($search_term);
    $chit_collections_where .= " AND (cg.group_no LIKE '%$search%' OR cm.subscriber_name LIKE '%$search%' OR cc.receipt_no LIKE '%$search%')";
}

$chit_collections_query = "SELECT cc.*, cg.group_no, cg.group_name, cm.subscriber_name, cm.member_no,
                                  pm.method_name as payment_method_name
                           FROM chit_collections cc
                           LEFT JOIN chit_groups cg ON cc.group_id = cg.id
                           LEFT JOIN chit_members cm ON cc.member_id = cm.id
                           LEFT JOIN payment_methods pm ON cc.payment_method_id = pm.id
                           WHERE $chit_collections_where
                           ORDER BY cc.collection_date DESC, cc.id DESC";
$chit_collections_result = $conn->query($chit_collections_query);
$chit_collections = [];
$total_chit_collections = 0;
if ($chit_collections_result) {
    while ($row = $chit_collections_result->fetch_assoc()) {
        $chit_collections[] = $row;
        $total_chit_collections += $row['net_amount'];
    }
}

// ==================== OVERALL TOTALS ====================
$total_incoming = $total_customer_payments + $total_pawn_payments + $total_pawn_interest + $total_chit_collections;
$total_outgoing = $total_supplier_payments;
$net_balance = $total_incoming - $total_outgoing;

// Get customers and suppliers for filters
$customers = [];
$cust_sql = "SELECT id, customer_name, customer_code FROM customers WHERE business_id = $business_id AND is_active = 1 ORDER BY customer_name";
$cust_res = $conn->query($cust_sql);
if ($cust_res) {
    while ($row = $cust_res->fetch_assoc()) {
        $customers[] = $row;
    }
}

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
        exportToExcel($customer_payments, $supplier_payments, $pawn_payments, $pawn_interests, $chit_collections, 
                      $start_date, $end_date, $total_incoming, $total_outgoing, $net_balance);
    } elseif ($format == 'pdf') {
        exportToPDF($conn, $customer_payments, $supplier_payments, $pawn_payments, $pawn_interests, $chit_collections, 
                    $start_date, $end_date, $total_incoming, $total_outgoing, $net_balance);
    }
    exit;
}

// Export functions (same as before)
function exportToExcel($customer_payments, $supplier_payments, $pawn_payments, $pawn_interests, $chit_collections, 
                       $start_date, $end_date, $total_incoming, $total_outgoing, $net_balance) {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="payment_history_' . date('Y-m-d') . '.xls"');
    
    echo "PAYMENT HISTORY REPORT\n";
    echo "Period: " . date('d-m-Y', strtotime($start_date)) . " to " . date('d-m-Y', strtotime($end_date)) . "\n";
    echo "Generated On: " . date('d-m-Y H:i:s') . "\n\n";
    
    // Customer Payments
    echo "CUSTOMER PAYMENTS (Incoming)\n";
    echo "Date\tReceipt No\tCustomer\tAmount\tMethod\tReference\n";
    foreach ($customer_payments as $cp) {
        echo date('d-m-Y', strtotime($cp['receipt_date'])) . "\t";
        echo $cp['receipt_no'] . "\t";
        echo $cp['customer_name'] . "\t";
        echo number_format($cp['amount'], 2) . "\t";
        echo $cp['payment_method_name'] . "\t";
        echo $cp['reference_no'] . "\n";
    }
    echo "\n";
    
    // Pawn Payments
    echo "PAWN PAYMENTS (Incoming)\n";
    echo "Date\tReceipt No\tPawn No\tCustomer\tAmount\tType\n";
    foreach ($pawn_payments as $pp) {
        echo date('d-m-Y', strtotime($pp['payment_date'])) . "\t";
        echo $pp['receipt_no'] . "\t";
        echo $pp['pawn_no'] . "\t";
        echo $pp['customer_name'] . "\t";
        echo number_format($pp['total_amount'], 2) . "\t";
        echo $pp['payment_type'] . "\n";
    }
    echo "\n";
    
    // Pawn Interest
    echo "PAWN INTEREST COLLECTIONS (Incoming)\n";
    echo "Date\tReceipt No\tPawn No\tCustomer\tAmount\n";
    foreach ($pawn_interests as $pi) {
        echo date('d-m-Y', strtotime($pi['collection_date'])) . "\t";
        echo $pi['receipt_no'] . "\t";
        echo $pi['pawn_no'] . "\t";
        echo $pi['customer_name'] . "\t";
        echo number_format($pi['net_amount'], 2) . "\n";
    }
    echo "\n";
    
    // Chit Collections
    echo "CHIT COLLECTIONS (Incoming)\n";
    echo "Date\tReceipt No\tGroup\tMember\tAmount\n";
    foreach ($chit_collections as $cc) {
        echo date('d-m-Y', strtotime($cc['collection_date'])) . "\t";
        echo $cc['receipt_no'] . "\t";
        echo $cc['group_no'] . "\t";
        echo $cc['subscriber_name'] . "\t";
        echo number_format($cc['net_amount'], 2) . "\n";
    }
    echo "\n";
    
    // Supplier Payments
    echo "SUPPLIER PAYMENTS (Outgoing)\n";
    echo "Date\tPayment No\tSupplier\tAmount\tMethod\tReference\n";
    foreach ($supplier_payments as $sp) {
        echo date('d-m-Y', strtotime($sp['payment_date'])) . "\t";
        echo $sp['payment_no'] . "\t";
        echo $sp['supplier_name'] . "\t";
        echo number_format($sp['amount'], 2) . "\t";
        echo $sp['payment_method_name'] . "\t";
        echo $sp['reference_no'] . "\n";
    }
    echo "\n";
    
    // Summary
    echo "SUMMARY\n";
    echo "Total Incoming (Customer + Pawn + Chit):\t" . number_format($total_incoming, 2) . "\n";
    echo "Total Outgoing (Supplier Payments):\t" . number_format($total_outgoing, 2) . "\n";
    echo "Net Balance:\t" . number_format($net_balance, 2) . "\n";
}

function exportToPDF($conn, $customer_payments, $supplier_payments, $pawn_payments, $pawn_interests, $chit_collections, 
                     $start_date, $end_date, $total_incoming, $total_outgoing, $net_balance) {
    require_once __DIR__ . '/libs/fpdf.php';
    
    class PDF_PaymentHistory extends FPDF {
        function Header() {
            $this->SetFont('Arial', 'B', 14);
            $this->Cell(0, 10, 'PAYMENT HISTORY REPORT', 0, 1, 'C');
            $this->SetFont('Arial', '', 10);
            $this->Cell(0, 5, 'Generated on: ' . date('d-m-Y H:i:s'), 0, 1, 'C');
            $this->Ln(5);
        }
        
        function Footer() {
            $this->SetY(-15);
            $this->SetFont('Arial', 'I', 8);
            $this->Cell(0, 10, 'Page ' . $this->PageNo(), 0, 0, 'C');
        }
        
        function SectionHeader($title) {
            $this->SetFont('Arial', 'B', 11);
            $this->SetFillColor(200, 220, 255);
            $this->Cell(0, 8, $title, 0, 1, 'L', true);
            $this->Ln(2);
        }
    }
    
    $pdf = new PDF_PaymentHistory('L', 'mm', 'A4');
    $pdf->AddPage();
    $pdf->SetFont('Arial', '', 10);
    
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 8, 'Period: ' . date('d-m-Y', strtotime($start_date)) . ' to ' . date('d-m-Y', strtotime($end_date)), 0, 1);
    $pdf->Ln(5);
    
    // Summary
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 8, 'SUMMARY', 0, 1);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(60, 6, 'Total Incoming:', 0, 0);
    $pdf->Cell(40, 6, '₹ ' . number_format($total_incoming, 2), 0, 1);
    $pdf->Cell(60, 6, 'Total Outgoing:', 0, 0);
    $pdf->Cell(40, 6, '₹ ' . number_format($total_outgoing, 2), 0, 1);
    $pdf->Cell(60, 6, 'Net Balance:', 0, 0);
    $pdf->Cell(40, 6, '₹ ' . number_format($net_balance, 2), 0, 1);
    $pdf->Ln(8);
    
    $pdf->Output('D', 'payment_history_' . date('Y-m-d') . '.pdf');
}
?>
<!DOCTYPE html>
<html lang="en">
<?php include('includes/head.php'); ?>
<title>Payment History | Accounts</title>
<style>
    .payment-card { border-radius: 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 24px; }
    .payment-card .card-header { background: #f8fafc; border-bottom: 1px solid #eef2f6; padding: 16px 20px; font-weight: 600; }
    .payment-card .card-body { padding: 20px; }
    .payment-card .card-body.p-0 { padding: 0; }
    .filter-section { background: #f8fafc; padding: 20px; border-radius: 12px; margin-bottom: 20px; }
    .summary-box { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 12px; padding: 20px; color: white; text-align: center; }
    .summary-value { font-size: 28px; font-weight: 700; }
    .summary-label { font-size: 12px; opacity: 0.9; margin-top: 5px; }
    .incoming-box { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); }
    .outgoing-box { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
    .net-box { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
    .btn-export { margin-right: 10px; }
    .nav-tabs .nav-link { color: #4b5563; font-weight: 500; }
    .nav-tabs .nav-link.active { color: #4f46e5; border-bottom-color: #4f46e5; }
    .table-responsive { overflow-x: auto; }
    .table th, .table td { white-space: nowrap; }
    .badge-customer { background: #dbeafe; color: #1e40af; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 500; }
    .badge-pawn { background: #fef3c7; color: #92400e; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 500; }
    .badge-chit { background: #dcfce7; color: #166534; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 500; }
    .badge-supplier { background: #fee2e2; color: #991b1b; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 500; }
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
                                    <h4 class="mb-1">Payment History</h4>
                                    <p class="text-muted mb-0">Track all incoming and outgoing payments</p>
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
                    </div>
                    
                    <!-- Filter Section -->
                    <div class="filter-section">
                        <form method="GET" id="filterForm" class="row g-3">
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
                                            <?php echo h($c['customer_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Supplier</label>
                                <select name="supplier_id" class="form-select">
                                    <option value="0">All Suppliers</option>
                                    <?php foreach ($suppliers as $s): ?>
                                        <option value="<?php echo $s['id']; ?>" <?php echo $supplier_id == $s['id'] ? 'selected' : ''; ?>>
                                            <?php echo h($s['supplier_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Search</label>
                                <input type="text" name="search" class="form-control" placeholder="Receipt No, Name..." value="<?php echo h($search_term); ?>">
                            </div>
                            <div class="col-md-12 mt-3">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-search"></i> Apply Filters
                                        </button>
                                        <a href="payment-history.php" class="btn btn-secondary">
                                            <i class="fas fa-times"></i> Reset
                                        </a>
                                    </div>
                                    <div class="text-muted">
                                        Period: <?php echo date('d-m-Y', strtotime($start_date)); ?> to <?php echo date('d-m-Y', strtotime($end_date)); ?>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Summary Cards -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <div class="summary-box incoming-box">
                                <div class="summary-value">₹<?php echo money($total_incoming); ?></div>
                                <div class="summary-label">Total Incoming</div>
                                <small>Customer + Pawn + Chit Payments</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="summary-box outgoing-box">
                                <div class="summary-value">₹<?php echo money($total_outgoing); ?></div>
                                <div class="summary-label">Total Outgoing</div>
                                <small>Supplier Payments</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="summary-box net-box">
                                <div class="summary-value" style="color: <?php echo $net_balance >= 0 ? '#fff' : '#ffcccc'; ?>">
                                    ₹<?php echo money($net_balance); ?>
                                </div>
                                <div class="summary-label">Net Balance</div>
                                <small>Incoming - Outgoing</small>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Payment Type Tabs -->
                    <ul class="nav nav-tabs mb-4">
                        <li class="nav-item">
                            <a class="nav-link <?php echo ($payment_type == 'all' || $payment_type == '') ? 'active' : ''; ?>" href="?payment_type=all&date_range=<?php echo $date_range; ?>&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>&customer_id=<?php echo $customer_id; ?>&supplier_id=<?php echo $supplier_id; ?>&search=<?php echo urlencode($search_term); ?>">
                                <i class="fas fa-list"></i> All Payments
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $payment_type == 'customer' ? 'active' : ''; ?>" href="?payment_type=customer&date_range=<?php echo $date_range; ?>&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>&customer_id=<?php echo $customer_id; ?>&search=<?php echo urlencode($search_term); ?>">
                                <i class="fas fa-users"></i> Customer Payments
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $payment_type == 'pawn' ? 'active' : ''; ?>" href="?payment_type=pawn&date_range=<?php echo $date_range; ?>&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>&search=<?php echo urlencode($search_term); ?>">
                                <i class="fas fa-hand-holding-usd"></i> Pawn Payments
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $payment_type == 'chit' ? 'active' : ''; ?>" href="?payment_type=chit&date_range=<?php echo $date_range; ?>&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>&search=<?php echo urlencode($search_term); ?>">
                                <i class="fas fa-coins"></i> Chit Collections
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $payment_type == 'supplier' ? 'active' : ''; ?>" href="?payment_type=supplier&date_range=<?php echo $date_range; ?>&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>&supplier_id=<?php echo $supplier_id; ?>&search=<?php echo urlencode($search_term); ?>">
                                <i class="fas fa-truck"></i> Supplier Payments
                            </a>
                        </li>
                    </ul>
                    
                    <!-- Customer Payments Table -->
                    <?php if (($payment_type == 'all' || $payment_type == 'customer') && !empty($customer_payments)): ?>
                    <div class="card payment-card">
                        <div class="card-header">
                            <i class="fas fa-users text-success me-2"></i> Customer Payments (Incoming)
                            <span class="float-end">Total: ₹<?php echo money($total_customer_payments); ?></span>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Date</th>
                                            <th>Receipt No</th>
                                            <th>Customer</th>
                                            <th>Code</th>
                                            <th>Mobile</th>
                                            <th class="text-end">Amount</th>
                                            <th>Method</th>
                                            <th>Reference</th>
                                            <th>Notes</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($customer_payments as $cp): ?>
                                        <tr>
                                            <td><?php echo date('d-m-Y', strtotime($cp['receipt_date'])); ?></td>
                                            <td><strong><?php echo h($cp['receipt_no']); ?></strong></td>
                                            <td><?php echo h($cp['customer_name'] ?? 'N/A'); ?></td>
                                            <td><?php echo h($cp['customer_code'] ?? '-'); ?></td>
                                            <td><?php echo h($cp['mobile'] ?? '-'); ?></td>
                                            <td class="text-end text-success">₹<?php echo money($cp['amount']); ?></td>
                                            <td><?php echo h($cp['payment_method_name'] ?? '-'); ?></td>
                                            <td><?php echo h($cp['reference_no'] ?? '-'); ?></td>
                                            <td><?php echo h($cp['notes'] ?? '-'); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr>
                                            <td colspan="5" class="text-end"><strong>Total:</strong></td>
                                            <td class="text-end"><strong>₹<?php echo money($total_customer_payments); ?></strong></td>
                                            <td colspan="3"></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Pawn Payments Table -->
                    <?php if (($payment_type == 'all' || $payment_type == 'pawn') && !empty($pawn_payments)): ?>
                    <div class="card payment-card">
                        <div class="card-header">
                            <i class="fas fa-hand-holding-usd text-warning me-2"></i> Pawn Payments (Incoming)
                            <span class="float-end">Total: ₹<?php echo money($total_pawn_payments); ?></span>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Date</th>
                                            <th>Receipt No</th>
                                            <th>Pawn No</th>
                                            <th>Customer</th>
                                            <th>Type</th>
                                            <th class="text-end">Principal</th>
                                            <th class="text-end">Interest</th>
                                            <th class="text-end">Total</th>
                                            <th>Method</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pawn_payments as $pp): ?>
                                        <tr>
                                            <td><?php echo date('d-m-Y', strtotime($pp['payment_date'])); ?></td>
                                            <td><strong><?php echo h($pp['receipt_no']); ?></strong></td>
                                            <td><?php echo h($pp['pawn_no']); ?></td>
                                            <td><?php echo h($pp['customer_name']); ?></td>
                                            <td><?php echo h($pp['payment_type']); ?></td>
                                            <td class="text-end">₹<?php echo money($pp['principal_amount']); ?></td>
                                            <td class="text-end">₹<?php echo money($pp['interest_amount']); ?></td>
                                            <td class="text-end text-success">₹<?php echo money($pp['total_amount']); ?></td>
                                            <td><?php echo h($pp['payment_method_name'] ?? '-'); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr>
                                            <td colspan="7" class="text-end"><strong>Total:</strong></td>
                                            <td class="text-end"><strong>₹<?php echo money($total_pawn_payments); ?></strong></td>
                                            <td></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Pawn Interest Collections Table -->
                    <?php if (($payment_type == 'all' || $payment_type == 'pawn') && !empty($pawn_interests)): ?>
                    <div class="card payment-card">
                        <div class="card-header">
                            <i class="fas fa-percent text-info me-2"></i> Pawn Interest Collections (Incoming)
                            <span class="float-end">Total: ₹<?php echo money($total_pawn_interest); ?></span>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Date</th>
                                            <th>Receipt No</th>
                                            <th>Pawn No</th>
                                            <th>Customer</th>
                                            <th>Period</th>
                                            <th>Days</th>
                                            <th class="text-end">Interest</th>
                                            <th class="text-end">Penalty</th>
                                            <th class="text-end">Net Amount</th>
                                            <th>Method</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pawn_interests as $pi): ?>
                                        <tr>
                                            <td><?php echo date('d-m-Y', strtotime($pi['collection_date'])); ?></td>
                                            <td><strong><?php echo h($pi['receipt_no']); ?></strong></td>
                                            <td><?php echo h($pi['pawn_no']); ?></td>
                                            <td><?php echo h($pi['customer_name']); ?></td>
                                            <td><small><?php echo date('d-m-y', strtotime($pi['interest_from'])) . ' to ' . date('d-m-y', strtotime($pi['interest_to'])); ?></small></td>
                                            <td class="text-center"><?php echo (int)$pi['days_count']; ?></td>
                                            <td class="text-end">₹<?php echo money($pi['interest_amount']); ?></td>
                                            <td class="text-end">₹<?php echo money($pi['penalty_amount']); ?></td>
                                            <td class="text-end text-success">₹<?php echo money($pi['net_amount']); ?></td>
                                            <td><?php echo h($pi['payment_method_name'] ?? '-'); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr>
                                            <td colspan="8" class="text-end"><strong>Total:</strong></td>
                                            <td class="text-end"><strong>₹<?php echo money($total_pawn_interest); ?></strong></td>
                                            <td></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Chit Collections Table -->
                    <?php if (($payment_type == 'all' || $payment_type == 'chit') && !empty($chit_collections)): ?>
                    <div class="card payment-card">
                        <div class="card-header">
                            <i class="fas fa-coins text-primary me-2"></i> Chit Collections (Incoming)
                            <span class="float-end">Total: ₹<?php echo money($total_chit_collections); ?></span>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Date</th>
                                            <th>Receipt No</th>
                                            <th>Group</th>
                                            <th>Member No</th>
                                            <th>Member Name</th>
                                            <th class="text-end">Due</th>
                                            <th class="text-end">Paid</th>
                                            <th class="text-end">Net</th>
                                            <th>Method</th>
                                        </td>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($chit_collections as $cc): ?>
                                        <tr>
                                            <td><?php echo date('d-m-Y', strtotime($cc['collection_date'])); ?></td>
                                            <td><strong><?php echo h($cc['receipt_no']); ?></strong></td>
                                            <td><?php echo h($cc['group_no']); ?></td>
                                            <td><?php echo h($cc['member_no']); ?></td>
                                            <td><?php echo h($cc['subscriber_name']); ?></td>
                                            <td class="text-end">₹<?php echo money($cc['due_amount']); ?></td>
                                            <td class="text-end">₹<?php echo money($cc['paid_amount']); ?></td>
                                            <td class="text-end text-success">₹<?php echo money($cc['net_amount']); ?></td>
                                            <td><?php echo h($cc['payment_method_name'] ?? '-'); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr>
                                            <td colspan="7" class="text-end"><strong>Total:</strong></td>
                                            <td class="text-end"><strong>₹<?php echo money($total_chit_collections); ?></strong></td>
                                            <td></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Supplier Payments Table -->
                    <?php if (($payment_type == 'all' || $payment_type == 'supplier') && !empty($supplier_payments)): ?>
                    <div class="card payment-card">
                        <div class="card-header">
                            <i class="fas fa-truck text-danger me-2"></i> Supplier Payments (Outgoing)
                            <span class="float-end">Total: ₹<?php echo money($total_supplier_payments); ?></span>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Date</th>
                                            <th>Payment No</th>
                                            <th>Supplier</th>
                                            <th>Code</th>
                                            <th class="text-end">Amount</th>
                                            <th>Method</th>
                                            <th>Reference</th>
                                            <th>Notes</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($supplier_payments as $sp): ?>
                                        <tr>
                                            <td><?php echo date('d-m-Y', strtotime($sp['payment_date'])); ?></td>
                                            <td><strong><?php echo h($sp['payment_no']); ?></strong></td>
                                            <td><?php echo h($sp['supplier_name'] ?? 'N/A'); ?></td>
                                            <td><?php echo h($sp['supplier_code'] ?? '-'); ?></td>
                                            <td class="text-end text-danger">₹<?php echo money($sp['amount']); ?></td>
                                            <td><?php echo h($sp['payment_method_name'] ?? '-'); ?></td>
                                            <td><?php echo h($sp['reference_no'] ?? '-'); ?></td>
                                            <td><?php echo h($sp['notes'] ?? '-'); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr>
                                            <td colspan="4" class="text-end"><strong>Total:</strong></td>
                                            <td class="text-end"><strong>₹<?php echo money($total_supplier_payments); ?></strong></td>
                                            <td colspan="3"></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- No Records Message -->
                    <?php if (empty($customer_payments) && empty($supplier_payments) && empty($pawn_payments) && empty($pawn_interests) && empty($chit_collections)): ?>
                    <div class="alert alert-info text-center py-5">
                        <i class="fas fa-info-circle fa-3x mb-3 d-block text-muted"></i>
                        <h5>No Payment Records Found</h5>
                        <p>No payment transactions found for the selected period and filters.</p>
                        <a href="payment-history.php" class="btn btn-primary mt-2">Clear Filters</a>
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
            const form = document.getElementById('filterForm');
            const formData = new FormData(form);
            const params = new URLSearchParams();
            
            for (let [key, value] of formData.entries()) {
                if (value) params.append(key, value);
            }
            params.append('export', '1');
            params.append('format', format);
            
            window.location.href = 'payment-history.php?' + params.toString();
        }
        
        // Initialize
        toggleCustomDate();
    </script>
</body>
</html>