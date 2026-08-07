<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/config.php';

if (!$conn || $conn->connect_error) {
    die('Database connection failed: ' . ($conn->connect_error ?? 'config error'));
}

$business_id = (int)($_SESSION['business_id'] ?? 1);
$currentPage = 'report-pawn';

// Helper functions
if (!function_exists('h')) {
    function h($string) { return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('money')) {
    function money($amount) { return number_format((float)$amount, 2); }
}

// Get filter parameters
$report_type = $_GET['report_type'] ?? 'all';
$date_range = $_GET['date_range'] ?? 'today';
$from_date = $_GET['from_date'] ?? '';
$to_date = $_GET['to_date'] ?? '';
$metal_type = $_GET['metal_type'] ?? 'all';
$status = $_GET['status'] ?? 'all';
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
$where_conditions = ["pe.business_id = $business_id"];
$where_conditions[] = "pe.entry_date BETWEEN '$start_date' AND '$end_date'";

if ($metal_type != 'all') {
    $where_conditions[] = "pe.metal_type = '" . $conn->real_escape_string($metal_type) . "'";
}
if ($status != 'all') {
    $where_conditions[] = "pe.status = '" . $conn->real_escape_string($status) . "'";
}

$where_clause = implode(" AND ", $where_conditions);

// Fetch pawn entries with filters
$pawns = [];
$sql = "SELECT pe.*, 
               DATEDIFF(CURDATE(), pe.maturity_date) as days_overdue,
               (SELECT SUM(principal_amount) FROM pawn_payments WHERE pawn_id = pe.id AND business_id = $business_id) as total_principal_paid,
               (SELECT SUM(interest_amount) FROM pawn_interest_collections WHERE pawn_id = pe.id AND business_id = $business_id) as total_interest_collected
        FROM pawn_entries pe
        WHERE $where_clause
        ORDER BY pe.entry_date DESC";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $pawns[] = $row;
    }
}

// Calculate summary statistics
$total_loans = count($pawns);
$total_loan_amount = array_sum(array_column($pawns, 'loan_amount'));
$total_outstanding = array_sum(array_column($pawns, 'principal_balance'));
$total_paid = array_sum(array_column($pawns, 'total_principal_paid'));
$total_interest = array_sum(array_column($pawns, 'total_interest_collected'));

// Calculate by status
$active_count = count(array_filter($pawns, function($p) { return $p['status'] == 'Active'; }));
$released_count = count(array_filter($pawns, function($p) { return $p['status'] == 'Released'; }));
$auctioned_count = count(array_filter($pawns, function($p) { return $p['status'] == 'Auctioned'; }));

// Handle Export
if ($export == '1') {
    if ($format == 'excel') {
        exportToExcel($pawns, $start_date, $end_date, $metal_type, $status, $total_loan_amount, $total_outstanding, $total_paid, $total_interest);
    } elseif ($format == 'pdf') {
        exportToPDF($conn, $pawns, $start_date, $end_date, $metal_type, $status, $total_loan_amount, $total_outstanding, $total_paid, $total_interest);
    }
    exit;
}

// Export functions
function exportToExcel($pawns, $start_date, $end_date, $metal_type, $status, $total_loan_amount, $total_outstanding, $total_paid, $total_interest) {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="pawn_report_' . date('Y-m-d') . '.xls"');
    
    echo "Pawn Report\n";
    echo "Period: " . date('d-m-Y', strtotime($start_date)) . " to " . date('d-m-Y', strtotime($end_date)) . "\n";
    echo "Metal Type: " . ($metal_type == 'all' ? 'All' : $metal_type) . "\n";
    echo "Status: " . ($status == 'all' ? 'All' : $status) . "\n\n";
    
    echo "Pawn No\tEntry Date\tCustomer Name\tMobile\tMetal Type\tLoan Amount\tOutstanding\tPaid Amount\tInterest\tStatus\tMaturity Date\n";
    
    foreach ($pawns as $pawn) {
        echo $pawn['pawn_no'] . "\t";
        echo date('d-m-Y', strtotime($pawn['entry_date'])) . "\t";
        echo $pawn['customer_name'] . "\t";
        echo $pawn['customer_mobile'] . "\t";
        echo $pawn['metal_type'] . "\t";
        echo number_format($pawn['loan_amount'], 2) . "\t";
        echo number_format($pawn['principal_balance'], 2) . "\t";
        echo number_format($pawn['total_principal_paid'], 2) . "\t";
        echo number_format($pawn['total_interest_collected'], 2) . "\t";
        echo $pawn['status'] . "\t";
        echo ($pawn['maturity_date'] ? date('d-m-Y', strtotime($pawn['maturity_date'])) : '') . "\n";
    }
    
    echo "\n\nSUMMARY\n";
    echo "Total Loans:\t" . count($pawns) . "\n";
    echo "Total Loan Amount:\t" . number_format($total_loan_amount, 2) . "\n";
    echo "Total Outstanding:\t" . number_format($total_outstanding, 2) . "\n";
    echo "Total Paid:\t" . number_format($total_paid, 2) . "\n";
    echo "Total Interest:\t" . number_format($total_interest, 2) . "\n";
}

function exportToPDF($conn, $pawns, $start_date, $end_date, $metal_type, $status, $total_loan_amount, $total_outstanding, $total_paid, $total_interest) {
    require_once __DIR__ . '/libs/fpdf.php';
    
    class PDF extends FPDF {
        function Header() {
            $this->SetFont('Arial', 'B', 14);
            $this->Cell(0, 10, 'Pawn Broking Report', 0, 1, 'C');
            $this->SetFont('Arial', '', 10);
            $this->Cell(0, 5, 'Generated on: ' . date('d-m-Y H:i:s'), 0, 1, 'C');
            $this->Ln(5);
        }
        
        function Footer() {
            $this->SetY(-15);
            $this->SetFont('Arial', 'I', 8);
            $this->Cell(0, 10, 'Page ' . $this->PageNo(), 0, 0, 'C');
        }
        
        function FancyTable($header, $data) {
            $this->SetFillColor(220, 220, 255);
            $this->SetFont('Arial', 'B', 8);
            
            // Header
            $w = array(30, 25, 45, 30, 25, 30, 30, 30, 25);
            for($i=0; $i<count($header); $i++) {
                $this->Cell($w[$i], 7, $header[$i], 1, 0, 'C', true);
            }
            $this->Ln();
            
            // Data
            $this->SetFont('Arial', '', 7);
            foreach($data as $row) {
                $this->Cell($w[0], 6, substr($row['pawn_no'], 0, 15), 1);
                $this->Cell($w[1], 6, date('d-m-y', strtotime($row['entry_date'])), 1, 0, 'C');
                $this->Cell($w[2], 6, substr($row['customer_name'], 0, 20), 1);
                $this->Cell($w[3], 6, substr($row['customer_mobile'], 0, 12), 1);
                $this->Cell($w[4], 6, $row['metal_type'], 1, 0, 'C');
                $this->Cell($w[5], 6, number_format($row['loan_amount'], 0), 1, 0, 'R');
                $this->Cell($w[6], 6, number_format($row['principal_balance'], 0), 1, 0, 'R');
                $this->Cell($w[7], 6, number_format($row['total_principal_paid'], 0), 1, 0, 'R');
                $this->Cell($w[8], 6, $row['status'], 1, 0, 'C');
                $this->Ln();
            }
        }
    }
    
    $pdf = new PDF('L', 'mm', 'A4');
    $pdf->AddPage();
    $pdf->SetFont('Arial', '', 10);
    
    // Report Info
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 8, 'Report Period: ' . date('d-m-Y', strtotime($start_date)) . ' to ' . date('d-m-Y', strtotime($end_date)), 0, 1);
    $pdf->Cell(0, 8, 'Metal Type: ' . ($metal_type == 'all' ? 'All' : $metal_type), 0, 1);
    $pdf->Cell(0, 8, 'Status: ' . ($status == 'all' ? 'All' : $status), 0, 1);
    $pdf->Ln(5);
    
    // Summary
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 8, 'SUMMARY', 0, 1);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(60, 6, 'Total Loans:', 0, 0);
    $pdf->Cell(40, 6, count($pawns), 0, 1);
    $pdf->Cell(60, 6, 'Total Loan Amount:', 0, 0);
    $pdf->Cell(40, 6, '' . number_format($total_loan_amount, 2), 0, 1);
    $pdf->Cell(60, 6, 'Total Outstanding:', 0, 0);
    $pdf->Cell(40, 6, '' . number_format($total_outstanding, 2), 0, 1);
    $pdf->Cell(60, 6, 'Total Paid:', 0, 0);
    $pdf->Cell(40, 6, '' . number_format($total_paid, 2), 0, 1);
    $pdf->Cell(60, 6, 'Total Interest:', 0, 0);
    $pdf->Cell(40, 6, '' . number_format($total_interest, 2), 0, 1);
    $pdf->Ln(8);
    
    // Table Header
    $header = array('Pawn No', 'Date', 'Customer', 'Mobile', 'Metal', 'Loan Amt', 'Outstanding', 'Paid', 'Status');
    
    // Prepare data for table
    $table_data = [];
    foreach ($pawns as $pawn) {
        $table_data[] = [
            'pawn_no' => $pawn['pawn_no'],
            'entry_date' => $pawn['entry_date'],
            'customer_name' => $pawn['customer_name'],
            'customer_mobile' => $pawn['customer_mobile'],
            'metal_type' => $pawn['metal_type'],
            'loan_amount' => $pawn['loan_amount'],
            'principal_balance' => $pawn['principal_balance'],
            'total_principal_paid' => $pawn['total_principal_paid'],
            'status' => $pawn['status']
        ];
    }
    
    $pdf->FancyTable($header, $table_data);
    $pdf->Output('D', 'pawn_report_' . date('Y-m-d') . '.pdf');
}
?>
<!doctype html>
<html lang="en">
<?php include('includes/head.php'); ?>
<title>Pawn Reports | Pawn Broking</title>
<style>
    .report-card { border-radius: 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 24px; }
    .report-card .card-header { background: #f8fafc; border-bottom: 1px solid #eef2f6; padding: 16px 20px; font-weight: 600; }
    .report-card .card-body { padding: 20px; }
    .filter-section { background: #f8fafc; padding: 20px; border-radius: 12px; margin-bottom: 20px; }
    .summary-box { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 12px; padding: 20px; color: white; text-align: center; }
    .summary-value { font-size: 28px; font-weight: 700; }
    .summary-label { font-size: 12px; opacity: 0.9; margin-top: 5px; }
    .status-badge { padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 500; display: inline-block; }
    .status-active { background: #dbeafe; color: #1e40af; }
    .status-released { background: #dcfce7; color: #166534; }
    .status-auctioned { background: #fee2e2; color: #991b1b; }
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
                                    <h4 class="mb-1">Pawn Reports</h4>
                                    <p class="text-muted mb-0">View and export pawn reports with advanced filtering</p>
                                </div>
                                <div>
                                    <a href="pawn-list.php" class="btn btn-outline-secondary">
                                        <i class="fas fa-arrow-left"></i> Back to Pawn List
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
                                <label class="form-label">Metal Type</label>
                                <select name="metal_type" class="form-select">
                                    <option value="all" <?php echo $metal_type == 'all' ? 'selected' : ''; ?>>All Metals</option>
                                    <option value="Gold" <?php echo $metal_type == 'Gold' ? 'selected' : ''; ?>>Gold</option>
                                    <option value="Silver" <?php echo $metal_type == 'Silver' ? 'selected' : ''; ?>>Silver</option>
                                    <option value="Other" <?php echo $metal_type == 'Other' ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select">
                                    <option value="all" <?php echo $status == 'all' ? 'selected' : ''; ?>>All Status</option>
                                    <option value="Active" <?php echo $status == 'Active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="Released" <?php echo $status == 'Released' ? 'selected' : ''; ?>>Released</option>
                                    <option value="Auctioned" <?php echo $status == 'Auctioned' ? 'selected' : ''; ?>>Auctioned</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">&nbsp;</label>
                                <div>
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="fas fa-search"></i> Apply Filters
                                    </button>
                                </div>
                            </div>
                        </form>
                        
                        <!-- Export Buttons -->
                        <div class="row mt-3">
                            <div class="col-12 text-end">
                                <button type="button" class="btn btn-success btn-export" onclick="exportReport('excel')">
                                    <i class="fas fa-file-excel"></i> Export to Excel
                                </button>
                                <button type="button" class="btn btn-danger btn-export" onclick="exportReport('pdf')">
                                    <i class="fas fa-file-pdf"></i> Export to PDF
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Summary Cards -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <div class="summary-box">
                                <div class="summary-value"><?php echo $total_loans; ?></div>
                                <div class="summary-label">Total Loans</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="summary-box" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                                <div class="summary-value"><?php echo money($total_loan_amount); ?></div>
                                <div class="summary-label">Total Loan Amount</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="summary-box" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                                <div class="summary-value"><?php echo money($total_outstanding); ?></div>
                                <div class="summary-label">Total Outstanding</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="summary-box" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                                <div class="summary-value"><?php echo money($total_paid); ?></div>
                                <div class="summary-label">Total Paid</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Status-wise Summary -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <div class="card report-card">
                                <div class="card-body text-center">
                                    <h4 class="text-primary"><?php echo $active_count; ?></h4>
                                    <p class="mb-0">Active Loans</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card report-card">
                                <div class="card-body text-center">
                                    <h4 class="text-success"><?php echo $released_count; ?></h4>
                                    <p class="mb-0">Released Loans</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card report-card">
                                <div class="card-body text-center">
                                    <h4 class="text-danger"><?php echo $auctioned_count; ?></h4>
                                    <p class="mb-0">Auctioned Loans</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Report Table -->
                    <div class="card report-card">
                        <div class="card-header bg-transparent">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">Pawn Report Details</h5>
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
                                            <th>Pawn No</th>
                                            <th>Date</th>
                                            <th>Customer</th>
                                            <th>Mobile</th>
                                            <th>Metal Type</th>
                                            <th>Loan Amount</th>
                                            <th>Outstanding</th>
                                            <th>Paid</th>
                                            <th>Interest</th>
                                            <th>Status</th>
                                            <th>Maturity Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($pawns)): ?>
                                            <tr>
                                                <td colspan="11" class="text-center text-muted py-5">
                                                    <i class="fas fa-inbox fa-3x mb-3 d-block text-muted"></i>
                                                    No pawn records found for the selected period.
                                                </td>
                                            </tr>
                                        <?php else: foreach ($pawns as $pawn): ?>
                                            <tr>
                                                <td><strong><?php echo h($pawn['pawn_no']); ?></strong></td>
                                                <td><?php echo date('d-m-Y', strtotime($pawn['entry_date'])); ?></td>
                                                <td><?php echo h($pawn['customer_name']); ?></td>
                                                <td><?php echo h($pawn['customer_mobile']); ?></td>
                                                <td>
                                                    <?php if ($pawn['metal_type'] == 'Gold'): ?>
                                                        <span class="badge bg-warning text-dark"><i class="fas fa-gem"></i> Gold</span>
                                                    <?php elseif ($pawn['metal_type'] == 'Silver'): ?>
                                                        <span class="badge bg-secondary">Silver</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-info"><?php echo h($pawn['metal_type']); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end"><?php echo money($pawn['loan_amount']); ?></td>
                                                <td class="text-end"><strong class="text-danger"><?php echo money($pawn['principal_balance']); ?></strong></td>
                                                <td class="text-end text-success"><?php echo money($pawn['total_principal_paid']); ?></td>
                                                <td class="text-end text-info"><?php echo money($pawn['total_interest_collected']); ?></td>
                                                <td>
                                                    <span class="status-badge status-<?php echo strtolower($pawn['status']); ?>">
                                                        <?php echo h($pawn['status']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo !empty($pawn['maturity_date']) ? date('d-m-Y', strtotime($pawn['maturity_date'])) : '-'; ?></td>
                                            </tr>
                                        <?php endforeach; endif; ?>
                                    </tbody>
                                    <?php if (!empty($pawns)): ?>
                                    <tfoot class="table-light">
                                        <tr>
                                            <td colspan="5" class="text-end"><strong>Totals:</strong></td>
                                            <td class="text-end"><strong><?php echo money($total_loan_amount); ?></strong></td>
                                            <td class="text-end"><strong><?php echo money($total_outstanding); ?></strong></td>
                                            <td class="text-end"><strong><?php echo money($total_paid); ?></strong></td>
                                            <td class="text-end"><strong><?php echo money($total_interest); ?></strong></td>
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
            const actionUrl = window.location.pathname;
            
            // Get all form values
            const dateRange = document.querySelector('select[name="date_range"]').value;
            const fromDate = document.querySelector('input[name="from_date"]')?.value || '';
            const toDate = document.querySelector('input[name="to_date"]')?.value || '';
            const metalType = document.querySelector('select[name="metal_type"]').value;
            const status = document.querySelector('select[name="status"]').value;
            
            // Build URL with parameters
            let url = `${actionUrl}?export=1&format=${format}&date_range=${dateRange}&metal_type=${metalType}&status=${status}`;
            
            if (dateRange === 'custom') {
                if (fromDate) url += `&from_date=${fromDate}`;
                if (toDate) url += `&to_date=${toDate}`;
            }
            
            window.location.href = url;
        }
        
        // Initialize
        toggleCustomDate();
    </script>
</body>
</html>