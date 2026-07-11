<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/config.php';

if (!$conn || $conn->connect_error) {
    die('Database connection failed: ' . ($conn->connect_error ?? 'config error'));
}

$business_id = (int)($_SESSION['business_id'] ?? 1);
$currentPage = 'report-chit';

// Helper functions
if (!function_exists('h')) {
    function h($string) { return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('money')) {
    function money($amount) { return number_format((float)$amount, 2); }
}

// Get filter parameters
$report_type = $_GET['report_type'] ?? 'groups';
$date_range = $_GET['date_range'] ?? 'all';
$from_date = $_GET['from_date'] ?? '';
$to_date = $_GET['to_date'] ?? '';
$group_id = (int)($_GET['group_id'] ?? 0);
$status_filter = $_GET['status'] ?? 'all';
$chit_type = $_GET['chit_type'] ?? 'all';
$export = $_GET['export'] ?? '';
$format = $_GET['format'] ?? '';

// Set dates based on date range
$today = date('Y-m-d');
$start_date = '';
$end_date = $today;

if ($date_range != 'all') {
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
    }
}

if ($from_date && $to_date) {
    $start_date = $from_date;
    $end_date = $to_date;
    $date_range = 'custom';
}

// Build WHERE clause for groups
$where_conditions = ["business_id = $business_id"];
if ($status_filter != 'all') {
    $where_conditions[] = "status = '" . $conn->real_escape_string($status_filter) . "'";
}
if ($chit_type != 'all') {
    $where_conditions[] = "chit_type = '" . $conn->real_escape_string($chit_type) . "'";
}
if ($date_range != 'all' && $start_date && $end_date) {
    $where_conditions[] = "start_date BETWEEN '$start_date' AND '$end_date'";
}
$where_clause = implode(" AND ", $where_conditions);

// Fetch chit groups
$groups = [];
$sql = "SELECT cg.*, 
               (SELECT COUNT(*) FROM chit_members WHERE group_id = cg.id AND status = 'Active') as active_members,
               (SELECT COUNT(*) FROM chit_collections WHERE group_id = cg.id) as total_collections,
               (SELECT SUM(net_amount) FROM chit_collections WHERE group_id = cg.id) as total_collected
        FROM chit_groups cg
        WHERE $where_clause
        ORDER BY cg.start_date DESC, cg.id DESC";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $groups[] = $row;
    }
}

// Fetch chit customers
$customers = [];
$cust_sql = "SELECT cc.*, c.customer_name, c.customer_code, c.mobile, c.email
             FROM chit_customers cc
             INNER JOIN customers c ON cc.customer_id = c.id
             WHERE cc.business_id = $business_id
             ORDER BY c.customer_name";
$cust_res = $conn->query($cust_sql);
if ($cust_res) {
    while ($row = $cust_res->fetch_assoc()) {
        $customers[] = $row;
    }
}

// Calculate summary statistics for groups
$total_groups = count($groups);
$active_groups = count(array_filter($groups, function($g) { return $g['status'] == 'Active'; }));
$closed_groups = count(array_filter($groups, function($g) { return $g['status'] == 'Closed'; }));
$total_chit_value = array_sum(array_column($groups, 'chit_value'));
$total_installment_amount = array_sum(array_column($groups, 'installment_amount'));
$total_collected = array_sum(array_column($groups, 'total_collected'));

// Fetch collections summary
$collections_where = ["business_id = $business_id"];
if ($group_id > 0) {
    $collections_where[] = "group_id = $group_id";
}
if ($date_range != 'all' && $start_date && $end_date) {
    $collections_where[] = "collection_date BETWEEN '$start_date' AND '$end_date'";
}
$collections_where_clause = implode(" AND ", $collections_where);

$collections_sql = "SELECT 
                       COUNT(*) as total_collections,
                       SUM(due_amount) as total_due,
                       SUM(paid_amount) as total_paid,
                       SUM(discount_amount) as total_discount,
                       SUM(penalty_amount) as total_penalty,
                       SUM(net_amount) as total_net
                    FROM chit_collections
                    WHERE $collections_where_clause";
$collections_res = $conn->query($collections_sql);
$collections_summary = $collections_res ? $collections_res->fetch_assoc() : [];

// Fetch collection details if group selected
$collections = [];
if ($group_id > 0 || ($report_type == 'collections' && $date_range != 'all')) {
    $coll_sql = "SELECT cc.*, cg.group_no, cg.group_name, cm.subscriber_name, cm.member_no,
                        pm.method_name as payment_method_name
                 FROM chit_collections cc
                 INNER JOIN chit_groups cg ON cc.group_id = cg.id
                 INNER JOIN chit_members cm ON cc.member_id = cm.id
                 LEFT JOIN payment_methods pm ON cc.payment_method_id = pm.id
                 WHERE cc.business_id = $business_id";
    if ($group_id > 0) {
        $coll_sql .= " AND cc.group_id = $group_id";
    }
    if ($date_range != 'all' && $start_date && $end_date) {
        $coll_sql .= " AND cc.collection_date BETWEEN '$start_date' AND '$end_date'";
    }
    $coll_sql .= " ORDER BY cc.collection_date DESC, cc.id DESC LIMIT 500";
    $coll_res = $conn->query($coll_sql);
    if ($coll_res) {
        while ($row = $coll_res->fetch_assoc()) {
            $collections[] = $row;
        }
    }
}

// Get single group details if selected
$selected_group = null;
if ($group_id > 0) {
    $group_sql = "SELECT cg.*, 
                         (SELECT COUNT(*) FROM chit_members WHERE group_id = cg.id) as total_members_count,
                         (SELECT COUNT(*) FROM chit_installments WHERE group_id = cg.id) as installments_count,
                         (SELECT COUNT(*) FROM chit_prizes WHERE group_id = cg.id) as prizes_count
                  FROM chit_groups cg
                  WHERE cg.id = $group_id AND cg.business_id = $business_id";
    $group_res = $conn->query($group_sql);
    if ($group_res && $group_res->num_rows > 0) {
        $selected_group = $group_res->fetch_assoc();
        
        // Fetch members for this group
        $members_sql = "SELECT cm.*, c.customer_name as billing_name
                        FROM chit_members cm
                        LEFT JOIN customers c ON cm.customer_id = c.id
                        WHERE cm.group_id = $group_id AND cm.business_id = $business_id
                        ORDER BY cm.member_no";
        $members_res = $conn->query($members_sql);
        $members = [];
        if ($members_res) {
            while ($row = $members_res->fetch_assoc()) {
                $members[] = $row;
            }
        }
        $selected_group['members'] = $members;
    }
}

// Handle Export
if ($export == '1') {
    if ($format == 'excel') {
        if ($report_type == 'groups') {
            exportGroupsToExcel($groups, $total_groups, $active_groups, $closed_groups, $total_chit_value, $total_installment_amount, $total_collected);
        } else {
            exportCollectionsToExcel($collections, $collections_summary);
        }
    } elseif ($format == 'pdf') {
        if ($report_type == 'groups') {
            exportGroupsToPDF($conn, $groups, $total_groups, $active_groups, $closed_groups, $total_chit_value, $total_installment_amount, $total_collected);
        } else {
            exportCollectionsToPDF($conn, $collections, $collections_summary);
        }
    }
    exit;
}

// Export functions
function exportGroupsToExcel($groups, $total_groups, $active_groups, $closed_groups, $total_chit_value, $total_installment_amount, $total_collected) {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="chit_groups_report_' . date('Y-m-d') . '.xls"');
    
    echo "Chit Groups Report\n";
    echo "Generated On: " . date('d-m-Y H:i:s') . "\n\n";
    
    echo "Group No\tGroup Name\tChit Type\tStart Date\tEnd Date\tTotal Members\tMonthly Amount\tChit Value\tStatus\tTotal Collections\tTotal Collected\n";
    
    foreach ($groups as $group) {
        echo $group['group_no'] . "\t";
        echo $group['group_name'] . "\t";
        echo $group['chit_type'] . "\t";
        echo date('d-m-Y', strtotime($group['start_date'])) . "\t";
        echo ($group['end_date'] ? date('d-m-Y', strtotime($group['end_date'])) : 'Ongoing') . "\t";
        echo $group['total_members'] . "\t";
        echo number_format($group['installment_amount'], 2) . "\t";
        echo number_format($group['chit_value'], 2) . "\t";
        echo $group['status'] . "\t";
        echo $group['total_collections'] . "\t";
        echo number_format($group['total_collected'], 2) . "\n";
    }
    
    echo "\n\nSUMMARY\n";
    echo "Total Groups:\t" . $total_groups . "\n";
    echo "Active Groups:\t" . $active_groups . "\n";
    echo "Closed Groups:\t" . $closed_groups . "\n";
    echo "Total Chit Value:\t" . number_format($total_chit_value, 2) . "\n";
    echo "Total Monthly Collection:\t" . number_format($total_installment_amount, 2) . "\n";
    echo "Total Amount Collected:\t" . number_format($total_collected, 2) . "\n";
}

function exportCollectionsToExcel($collections, $summary) {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="chit_collections_report_' . date('Y-m-d') . '.xls"');
    
    echo "Chit Collections Report\n";
    echo "Generated On: " . date('d-m-Y H:i:s') . "\n\n";
    
    echo "Receipt No\tDate\tGroup No\tGroup Name\tMember No\tMember Name\tDue Amount\tPaid Amount\tDiscount\tPenalty\tNet Amount\tMethod\tReference\n";
    
    foreach ($collections as $col) {
        echo $col['receipt_no'] . "\t";
        echo date('d-m-Y', strtotime($col['collection_date'])) . "\t";
        echo $col['group_no'] . "\t";
        echo $col['group_name'] . "\t";
        echo $col['member_no'] . "\t";
        echo $col['subscriber_name'] . "\t";
        echo number_format($col['due_amount'], 2) . "\t";
        echo number_format($col['paid_amount'], 2) . "\t";
        echo number_format($col['discount_amount'], 2) . "\t";
        echo number_format($col['penalty_amount'], 2) . "\t";
        echo number_format($col['net_amount'], 2) . "\t";
        echo $col['payment_method_name'] . "\t";
        echo $col['reference_no'] . "\n";
    }
    
    echo "\n\nSUMMARY\n";
    echo "Total Collections:\t" . ($summary['total_collections'] ?? 0) . "\n";
    echo "Total Due:\t" . number_format($summary['total_due'] ?? 0, 2) . "\n";
    echo "Total Paid:\t" . number_format($summary['total_paid'] ?? 0, 2) . "\n";
    echo "Total Discount:\t" . number_format($summary['total_discount'] ?? 0, 2) . "\n";
    echo "Total Penalty:\t" . number_format($summary['total_penalty'] ?? 0, 2) . "\n";
    echo "Total Net Amount:\t" . number_format($summary['total_net'] ?? 0, 2) . "\n";
}

function exportGroupsToPDF($conn, $groups, $total_groups, $active_groups, $closed_groups, $total_chit_value, $total_installment_amount, $total_collected) {
    require_once __DIR__ . '/libs/fpdf.php';
    
    class PDF_ChitGroups extends FPDF {
        function Header() {
            $this->SetFont('Arial', 'B', 14);
            $this->Cell(0, 10, 'Chit Groups Report', 0, 1, 'C');
            $this->SetFont('Arial', '', 10);
            $this->Cell(0, 5, 'Generated on: ' . date('d-m-Y H:i:s'), 0, 1, 'C');
            $this->Ln(5);
        }
        
        function Footer() {
            $this->SetY(-15);
            $this->SetFont('Arial', 'I', 8);
            $this->Cell(0, 10, 'Page ' . $this->PageNo(), 0, 0, 'C');
        }
        
        function GroupsTable($header, $data) {
            $this->SetFillColor(50, 50, 100);
            $this->SetTextColor(255);
            $this->SetFont('Arial', 'B', 8);
            
            $w = array(25, 40, 20, 20, 25, 25, 20);
            for($i=0; $i<count($header); $i++) {
                $this->Cell($w[$i], 7, $header[$i], 1, 0, 'C', true);
            }
            $this->Ln();
            
            $this->SetFillColor(255);
            $this->SetTextColor(0);
            $this->SetFont('Arial', '', 7);
            $fill = false;
            
            foreach($data as $row) {
                $this->Cell($w[0], 6, substr($row['group_no'], 0, 10), 1, 0, 'L', $fill);
                $this->Cell($w[1], 6, substr($row['group_name'], 0, 20), 1, 0, 'L', $fill);
                $this->Cell($w[2], 6, $row['chit_type'], 1, 0, 'C', $fill);
                $this->Cell($w[3], 6, $row['total_members'], 1, 0, 'C', $fill);
                $this->Cell($w[4], 6, number_format($row['installment_amount'], 0), 1, 0, 'R', $fill);
                $this->Cell($w[5], 6, number_format($row['chit_value'], 0), 1, 0, 'R', $fill);
                $this->Cell($w[6], 6, $row['status'], 1, 0, 'C', $fill);
                $this->Ln();
                $fill = !$fill;
            }
        }
    }
    
    $pdf = new PDF_ChitGroups('L', 'mm', 'A4');
    $pdf->AddPage();
    $pdf->SetFont('Arial', '', 10);
    
    // Summary
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 8, 'SUMMARY', 0, 1);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(60, 6, 'Total Groups:', 0, 0);
    $pdf->Cell(40, 6, $total_groups, 0, 1);
    $pdf->Cell(60, 6, 'Active Groups:', 0, 0);
    $pdf->Cell(40, 6, $active_groups, 0, 1);
    $pdf->Cell(60, 6, 'Closed Groups:', 0, 0);
    $pdf->Cell(40, 6, $closed_groups, 0, 1);
    $pdf->Cell(60, 6, 'Total Chit Value:', 0, 0);
    $pdf->Cell(40, 6, '₹' . number_format($total_chit_value, 2), 0, 1);
    $pdf->Ln(8);
    
    // Table Header
    $header = array('Group No', 'Group Name', 'Type', 'Members', 'Installment', 'Chit Value', 'Status');
    
    $pdf->GroupsTable($header, $groups);
    $pdf->Output('D', 'chit_groups_report_' . date('Y-m-d') . '.pdf');
}

function exportCollectionsToPDF($conn, $collections, $summary) {
    require_once __DIR__ . '/libs/fpdf.php';
    
    class PDF_ChitCollections extends FPDF {
        function Header() {
            $this->SetFont('Arial', 'B', 14);
            $this->Cell(0, 10, 'Chit Collections Report', 0, 1, 'C');
            $this->SetFont('Arial', '', 10);
            $this->Cell(0, 5, 'Generated on: ' . date('d-m-Y H:i:s'), 0, 1, 'C');
            $this->Ln(5);
        }
        
        function Footer() {
            $this->SetY(-15);
            $this->SetFont('Arial', 'I', 8);
            $this->Cell(0, 10, 'Page ' . $this->PageNo(), 0, 0, 'C');
        }
        
        function CollectionsTable($header, $data) {
            $this->SetFillColor(50, 50, 100);
            $this->SetTextColor(255);
            $this->SetFont('Arial', 'B', 8);
            
            $w = array(25, 22, 35, 30, 25, 25);
            for($i=0; $i<count($header); $i++) {
                $this->Cell($w[$i], 7, $header[$i], 1, 0, 'C', true);
            }
            $this->Ln();
            
            $this->SetFillColor(255);
            $this->SetTextColor(0);
            $this->SetFont('Arial', '', 6);
            $fill = false;
            
            foreach($data as $row) {
                $this->Cell($w[0], 5, substr($row['receipt_no'], 0, 12), 1, 0, 'L', $fill);
                $this->Cell($w[1], 5, date('d-m-y', strtotime($row['collection_date'])), 1, 0, 'C', $fill);
                $this->Cell($w[2], 5, substr($row['subscriber_name'], 0, 18), 1, 0, 'L', $fill);
                $this->Cell($w[3], 5, number_format($row['net_amount'], 0), 1, 0, 'R', $fill);
                $this->Cell($w[4], 5, substr($row['payment_method_name'], 0, 12), 1, 0, 'L', $fill);
                $this->Cell($w[5], 5, substr($row['group_no'], 0, 10), 1, 0, 'L', $fill);
                $this->Ln();
                $fill = !$fill;
            }
        }
    }
    
    $pdf = new PDF_ChitCollections('L', 'mm', 'A4');
    $pdf->AddPage();
    $pdf->SetFont('Arial', '', 10);
    
    // Summary
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 8, 'SUMMARY', 0, 1);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(60, 6, 'Total Collections:', 0, 0);
    $pdf->Cell(40, 6, $summary['total_collections'] ?? 0, 0, 1);
    $pdf->Cell(60, 6, 'Total Amount:', 0, 0);
    $pdf->Cell(40, 6, '₹' . number_format($summary['total_net'] ?? 0, 2), 0, 1);
    $pdf->Ln(8);
    
    // Table Header
    $header = array('Receipt No', 'Date', 'Member', 'Amount', 'Method', 'Group');
    
    $pdf->CollectionsTable($header, $collections);
    $pdf->Output('D', 'chit_collections_report_' . date('Y-m-d') . '.pdf');
}
?>
<!doctype html>
<html lang="en">
<?php include('includes/head.php'); ?>
<title>Chit Report | Reports</title>
<style>
    .report-card { border-radius: 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 24px; }
    .report-card .card-header { background: #f8fafc; border-bottom: 1px solid #eef2f6; padding: 16px 20px; font-weight: 600; }
    .report-card .card-body { padding: 20px; }
    .filter-section { background: #f8fafc; padding: 20px; border-radius: 12px; margin-bottom: 20px; }
    .summary-box { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 12px; padding: 20px; color: white; text-align: center; }
    .summary-value { font-size: 28px; font-weight: 700; }
    .summary-label { font-size: 12px; opacity: 0.9; margin-top: 5px; }
    .status-active { background: #dcfce7; color: #166534; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 500; display: inline-block; }
    .status-closed { background: #e5e7eb; color: #4b5563; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 500; display: inline-block; }
    .status-draft { background: #fef3c7; color: #92400e; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 500; display: inline-block; }
    .btn-export { margin-right: 10px; }
    .nav-tabs .nav-link { color: #4b5563; font-weight: 500; }
    .nav-tabs .nav-link.active { color: #4f46e5; border-bottom-color: #4f46e5; }
    .group-card { border-left: 4px solid #4f46e5; margin-bottom: 15px; }
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
                                    <h4 class="mb-1">Chit Fund Report</h4>
                                    <p class="text-muted mb-0">View chit groups, collections, and member reports</p>
                                </div>
                                <div>
                                    <a href="chit-create.php" class="btn btn-primary">
                                        <i class="fas fa-plus"></i> New Chit Group
                                    </a>
                                    <a href="chit-groups.php" class="btn btn-outline-secondary">
                                        <i class="fas fa-list"></i> Manage Groups
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Report Type Tabs -->
                    <ul class="nav nav-tabs mb-4">
                        <li class="nav-item">
                            <a class="nav-link <?php echo $report_type == 'groups' ? 'active' : ''; ?>" href="?report_type=groups&<?php echo http_build_query(array_filter(['date_range'=>$date_range,'from_date'=>$from_date,'to_date'=>$to_date,'status'=>$status_filter,'chit_type'=>$chit_type])); ?>">
                                <i class="fas fa-layer-group"></i> Groups Report
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $report_type == 'collections' ? 'active' : ''; ?>" href="?report_type=collections&<?php echo http_build_query(array_filter(['date_range'=>$date_range,'from_date'=>$from_date,'to_date'=>$to_date,'group_id'=>$group_id])); ?>">
                                <i class="fas fa-money-bill-wave"></i> Collections Report
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $report_type == 'members' ? 'active' : ''; ?>" href="?report_type=members&<?php echo http_build_query(array_filter(['group_id'=>$group_id])); ?>">
                                <i class="fas fa-users"></i> Members Report
                            </a>
                        </li>
                    </ul>
                    
                    <!-- Filter Section -->
                    <div class="filter-section">
                        <form method="GET" id="reportForm" class="row g-3">
                            <input type="hidden" name="report_type" value="<?php echo $report_type; ?>">
                            
                            <?php if ($report_type == 'groups'): ?>
                            <div class="col-md-2">
                                <label class="form-label">Date Range</label>
                                <select name="date_range" class="form-select" onchange="toggleCustomDate()">
                                    <option value="all" <?php echo $date_range == 'all' ? 'selected' : ''; ?>>All Time</option>
                                    <option value="today" <?php echo $date_range == 'today' ? 'selected' : ''; ?>>Today</option>
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
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select">
                                    <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>>All Status</option>
                                    <option value="Active" <?php echo $status_filter == 'Active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="Closed" <?php echo $status_filter == 'Closed' ? 'selected' : ''; ?>>Closed</option>
                                    <option value="Draft" <?php echo $status_filter == 'Draft' ? 'selected' : ''; ?>>Draft</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Chit Type</label>
                                <select name="chit_type" class="form-select">
                                    <option value="all" <?php echo $chit_type == 'all' ? 'selected' : ''; ?>>All Types</option>
                                    <option value="Money" <?php echo $chit_type == 'Money' ? 'selected' : ''; ?>>Money</option>
                                    <option value="Silver" <?php echo $chit_type == 'Silver' ? 'selected' : ''; ?>>Silver</option>
                                    <option value="Gold" <?php echo $chit_type == 'Gold' ? 'selected' : ''; ?>>Gold</option>
                                </select>
                            </div>
                            <?php elseif ($report_type == 'collections'): ?>
                            <div class="col-md-3">
                                <label class="form-label">Date Range</label>
                                <select name="date_range" class="form-select" onchange="toggleCustomDate()">
                                    <option value="all" <?php echo $date_range == 'all' ? 'selected' : ''; ?>>All Time</option>
                                    <option value="today" <?php echo $date_range == 'today' ? 'selected' : ''; ?>>Today</option>
                                    <option value="week" <?php echo $date_range == 'week' ? 'selected' : ''; ?>>This Week</option>
                                    <option value="month" <?php echo $date_range == 'month' ? 'selected' : ''; ?>>This Month</option>
                                    <option value="custom" <?php echo $date_range == 'custom' ? 'selected' : ''; ?>>Custom Range</option>
                                </select>
                            </div>
                            <div class="col-md-2" id="fromDateDiv2" style="display: <?php echo $date_range == 'custom' ? 'block' : 'none'; ?>">
                                <label class="form-label">From Date</label>
                                <input type="date" name="from_date" class="form-control" value="<?php echo h($from_date); ?>">
                            </div>
                            <div class="col-md-2" id="toDateDiv2" style="display: <?php echo $date_range == 'custom' ? 'block' : 'none'; ?>">
                                <label class="form-label">To Date</label>
                                <input type="date" name="to_date" class="form-control" value="<?php echo h($to_date); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Chit Group</label>
                                <select name="group_id" class="form-select">
                                    <option value="0">All Groups</option>
                                    <?php foreach ($groups as $g): ?>
                                        <option value="<?php echo $g['id']; ?>" <?php echo $group_id == $g['id'] ? 'selected' : ''; ?>>
                                            <?php echo h($g['group_no']); ?> - <?php echo h($g['group_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php elseif ($report_type == 'members'): ?>
                            <div class="col-md-6">
                                <label class="form-label">Select Chit Group</label>
                                <select name="group_id" class="form-select" onchange="this.form.submit()">
                                    <option value="0">-- Select Group --</option>
                                    <?php foreach ($groups as $g): ?>
                                        <option value="<?php echo $g['id']; ?>" <?php echo $group_id == $g['id'] ? 'selected' : ''; ?>>
                                            <?php echo h($g['group_no']); ?> - <?php echo h($g['group_name']); ?> (<?php echo $g['chit_type']; ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                            
                            <div class="col-md-12 mt-3">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-search"></i> Apply Filters
                                        </button>
                                        <a href="report-chit.php?report_type=<?php echo $report_type; ?>" class="btn btn-secondary">
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
                    </div>
                    
                    <?php if ($report_type == 'groups'): ?>
                    <!-- Groups Report -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <div class="summary-box">
                                <div class="summary-value"><?php echo $total_groups; ?></div>
                                <div class="summary-label">Total Groups</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="summary-box" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                                <div class="summary-value"><?php echo $active_groups; ?></div>
                                <div class="summary-label">Active Groups</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="summary-box" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                                <div class="summary-value">₹<?php echo money($total_collected); ?></div>
                                <div class="summary-label">Total Collected</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="summary-box" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                                <div class="summary-value">₹<?php echo money($total_chit_value); ?></div>
                                <div class="summary-label">Total Chit Value</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card report-card">
                        <div class="card-header bg-transparent">
                            <h5 class="mb-0">Chit Groups List</h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Group No</th>
                                            <th>Group Name</th>
                                            <th>Chit Type</th>
                                            <th>Start Date</th>
                                            <th>End Date</th>
                                            <th>Total Members</th>
                                            <th>Active Members</th>
                                            <th>Installment (₹)</th>
                                            <th>Chit Value (₹)</th>
                                            <th>Collections</th>
                                            <th>Collected (₹)</th>
                                            <th>Status</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($groups)): ?>
                                            <tr><td colspan="13" class="text-center text-muted py-5">No chit groups found.</td></tr>
                                        <?php else: foreach ($groups as $group): ?>
                                            <tr>
                                                <td><strong><?php echo h($group['group_no']); ?></strong></td>
                                                <td><?php echo h($group['group_name']); ?></td>
                                                <td>
                                                    <?php if ($group['chit_type'] == 'Money'): ?>
                                                        <span class="badge bg-success">Money</span>
                                                    <?php elseif ($group['chit_type'] == 'Silver'): ?>
                                                        <span class="badge bg-secondary">Silver</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning">Gold</span>
                                                    <?php endif; ?>
                                                 </td>
                                                <td><?php echo date('d-m-Y', strtotime($group['start_date'])); ?></td>
                                                <td><?php echo $group['end_date'] ? date('d-m-Y', strtotime($group['end_date'])) : '-'; ?></td>
                                                <td class="text-center"><?php echo (int)$group['total_members']; ?></td>
                                                <td class="text-center"><?php echo (int)$group['active_members']; ?></td>
                                                <td class="text-end">₹<?php echo money($group['installment_amount']); ?></td>
                                                <td class="text-end">₹<?php echo money($group['chit_value']); ?></td>
                                                <td class="text-center"><?php echo (int)$group['total_collections']; ?></td>
                                                <td class="text-end">₹<?php echo money($group['total_collected']); ?></td>
                                                <td>
                                                    <?php if ($group['status'] == 'Active'): ?>
                                                        <span class="status-active">Active</span>
                                                    <?php elseif ($group['status'] == 'Closed'): ?>
                                                        <span class="status-closed">Closed</span>
                                                    <?php else: ?>
                                                        <span class="status-draft">Draft</span>
                                                    <?php endif; ?>
                                                 </td>
                                                <td>
                                                    <a href="chit-view.php?id=<?php echo $group['id']; ?>" class="btn btn-sm btn-outline-info">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                 </td>
                                            </tr>
                                        <?php endforeach; endif; ?>
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr>
                                            <td colspan="8" class="text-end"><strong>Totals:</strong></td>
                                            <td class="text-end"><strong>₹<?php echo money($total_installment_amount); ?></strong></td>
                                            <td class="text-end"><strong>₹<?php echo money($total_chit_value); ?></strong></td>
                                            <td colspan="2"><td class="text-end"><strong>₹<?php echo money($total_collected); ?></strong></td></td>
                                            <td></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <?php elseif ($report_type == 'collections'): ?>
                    <!-- Collections Report -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <div class="summary-box">
                                <div class="summary-value"><?php echo (int)($collections_summary['total_collections'] ?? 0); ?></div>
                                <div class="summary-label">Total Collections</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="summary-box" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                                <div class="summary-value">₹<?php echo money($collections_summary['total_net'] ?? 0); ?></div>
                                <div class="summary-label">Total Amount</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="summary-box" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                                <div class="summary-value">₹<?php echo money($collections_summary['total_discount'] ?? 0); ?></div>
                                <div class="summary-label">Total Discount</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="summary-box" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                                <div class="summary-value">₹<?php echo money($collections_summary['total_penalty'] ?? 0); ?></div>
                                <div class="summary-label">Total Penalty</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card report-card">
                        <div class="card-header bg-transparent">
                            <h5 class="mb-0">Collection Details</h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Receipt No</th>
                                            <th>Date</th>
                                            <th>Group</th>
                                            <th>Member No</th>
                                            <th>Member Name</th>
                                            <th>Due Amount</th>
                                            <th>Paid Amount</th>
                                            <th>Discount</th>
                                            <th>Penalty</th>
                                            <th>Net Amount</th>
                                            <th>Method</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($collections)): ?>
                                            <tr><td colspan="11" class="text-center text-muted py-5">No collections found.</td></tr>
                                        <?php else: foreach ($collections as $col): ?>
                                            <tr>
                                                <td><strong><?php echo h($col['receipt_no']); ?></strong></td>
                                                <td><?php echo date('d-m-Y', strtotime($col['collection_date'])); ?></td>
                                                <td><?php echo h($col['group_no']); ?></td>
                                                <td><?php echo h($col['member_no']); ?></td>
                                                <td><?php echo h($col['subscriber_name']); ?></td>
                                                <td class="text-end">₹<?php echo money($col['due_amount']); ?></td>
                                                <td class="text-end">₹<?php echo money($col['paid_amount']); ?></td>
                                                <td class="text-end text-danger">₹<?php echo money($col['discount_amount']); ?></td>
                                                <td class="text-end text-warning">₹<?php echo money($col['penalty_amount']); ?></td>
                                                <td class="text-end"><strong>₹<?php echo money($col['net_amount']); ?></strong></td>
                                                <td><?php echo h($col['payment_method_name'] ?: '-'); ?></td>
                                            </tr>
                                        <?php endforeach; endif; ?>
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr>
                                            <td colspan="5" class="text-end"><strong>Totals:</strong></td>
                                            <td class="text-end"><strong>₹<?php echo money($collections_summary['total_due'] ?? 0); ?></strong></td>
                                            <td class="text-end"><strong>₹<?php echo money($collections_summary['total_paid'] ?? 0); ?></strong></td>
                                            <td class="text-end"><strong>₹<?php echo money($collections_summary['total_discount'] ?? 0); ?></strong></td>
                                            <td class="text-end"><strong>₹<?php echo money($collections_summary['total_penalty'] ?? 0); ?></strong></td>
                                            <td class="text-end"><strong>₹<?php echo money($collections_summary['total_net'] ?? 0); ?></strong></td>
                                            <td></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <?php elseif ($report_type == 'members' && $selected_group): ?>
                    <!-- Members Report -->
                    <div class="card report-card mb-4">
                        <div class="card-header">
                            <i class="fas fa-info-circle me-2"></i> Group Information
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-3">
                                    <strong>Group No:</strong><br>
                                    <?php echo h($selected_group['group_no']); ?>
                                </div>
                                <div class="col-md-3">
                                    <strong>Group Name:</strong><br>
                                    <?php echo h($selected_group['group_name']); ?>
                                </div>
                                <div class="col-md-3">
                                    <strong>Chit Type:</strong><br>
                                    <?php echo h($selected_group['chit_type']); ?>
                                </div>
                                <div class="col-md-3">
                                    <strong>Status:</strong><br>
                                    <?php echo h($selected_group['status']); ?>
                                </div>
                                <div class="col-md-3">
                                    <strong>Start Date:</strong><br>
                                    <?php echo date('d-m-Y', strtotime($selected_group['start_date'])); ?>
                                </div>
                                <div class="col-md-3">
                                    <strong>Total Members:</strong><br>
                                    <?php echo $selected_group['total_members']; ?>
                                </div>
                                <div class="col-md-3">
                                    <strong>Installment Amount:</strong><br>
                                    ₹<?php echo money($selected_group['installment_amount']); ?>
                                </div>
                                <div class="col-md-3">
                                    <strong>Chit Value:</strong><br>
                                    ₹<?php echo money($selected_group['chit_value']); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card report-card">
                        <div class="card-header bg-transparent">
                            <h5 class="mb-0">Member List</h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Member No</th>
                                            <th>Subscriber Name</th>
                                            <th>Mobile</th>
                                            <th>Nominee Name</th>
                                            <th>Nominee Mobile</th>
                                            <th>Join Date</th>
                                            <th>Security Deposit</th>
                                            <th>Opening Due</th>
                                            <th>Status</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($selected_group['members'])): ?>
                                            <tr><td colspan="10" class="text-center text-muted py-5">No members found in this group.</td></tr>
                                        <?php else: foreach ($selected_group['members'] as $member): ?>
                                            <tr>
                                                <td><strong><?php echo h($member['member_no']); ?></strong></td>
                                                <td><?php echo h($member['subscriber_name']); ?></td>
                                                <td><?php echo h($member['subscriber_mobile']); ?></td>
                                                <td><?php echo h($member['nominee_name'] ?: '-'); ?></td>
                                                <td><?php echo h($member['nominee_mobile'] ?: '-'); ?></td>
                                                <td><?php echo date('d-m-Y', strtotime($member['join_date'])); ?></td>
                                                <td class="text-end">₹<?php echo money($member['security_deposit']); ?></td>
                                                <td class="text-end">₹<?php echo money($member['opening_due']); ?></td>
                                                <td>
                                                    <?php if ($member['status'] == 'Active'): ?>
                                                        <span class="status-active">Active</span>
                                                    <?php else: ?>
                                                        <span class="status-closed"><?php echo h($member['status']); ?></span>
                                                    <?php endif; ?>
                                                  </td>
                                                <td>
                                                    <a href="chit-member-view.php?id=<?php echo $member['id']; ?>" class="btn btn-sm btn-outline-info">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                  </td>
                                            </tr>
                                        <?php endforeach; endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <?php elseif ($report_type == 'members' && $group_id == 0): ?>
                    <div class="alert alert-info text-center">
                        <i class="fas fa-info-circle fa-2x mb-2 d-block"></i>
                        <h5>Select a Chit Group</h5>
                        <p>Please select a chit group from the filter above to view member details.</p>
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
            const fromDiv2 = document.getElementById('fromDateDiv2');
            const toDiv2 = document.getElementById('toDateDiv2');
            
            if (dateRange === 'custom') {
                if (fromDiv) fromDiv.style.display = 'block';
                if (toDiv) toDiv.style.display = 'block';
                if (fromDiv2) fromDiv2.style.display = 'block';
                if (toDiv2) toDiv2.style.display = 'block';
            } else {
                if (fromDiv) fromDiv.style.display = 'none';
                if (toDiv) toDiv.style.display = 'none';
                if (fromDiv2) fromDiv2.style.display = 'none';
                if (toDiv2) toDiv2.style.display = 'none';
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
            
            window.location.href = 'report-chit.php?' + params.toString();
        }
        
        // Initialize
        toggleCustomDate();
    </script>
</body>
</html>