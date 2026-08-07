<?php
session_start();
date_default_timezone_set('Asia/Kolkata');
require_once 'includes/config.php';
if (!isset($conn) || !($conn instanceof mysqli)) { die('Database connection not available. Check includes/config.php'); }
mysqli_report(MYSQLI_REPORT_OFF);
$conn->set_charset('utf8mb4');
if (!function_exists('h')) { function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('money')) { function money($v): string { return number_format((float)$v, 2); } }
if (!function_exists('tableExists')) { function tableExists(mysqli $conn, string $t): bool { $t=$conn->real_escape_string($t); $r=$conn->query("SHOW TABLES LIKE '{$t}'"); return $r && $r->num_rows>0; } }
if (!function_exists('nextNo')) { function nextNo(mysqli $conn, string $table, string $col, string $prefix, int $businessId): string { $like=$prefix.'%'; $sql="SELECT {$col} FROM {$table} WHERE business_id=? AND {$col} LIKE ? ORDER BY id DESC LIMIT 1"; $st=$conn->prepare($sql); if(!$st) return $prefix.'0001'; $st->bind_param('is',$businessId,$like); $st->execute(); $row=$st->get_result()->fetch_assoc(); $st->close(); $n=1; if($row){ $last=(string)$row[$col]; if(preg_match('/(\d+)$/',$last,$m)) $n=((int)$m[1])+1; } return $prefix.str_pad((string)$n,4,'0',STR_PAD_LEFT); } }
$userId=(int)($_SESSION['user_id']??0); $businessId=(int)($_SESSION['business_id']??1); if($userId<=0){ header('Location: ../login.php'); exit; }
$roleName = function_exists('currentRoleName') ? currentRoleName() : ($_SESSION['role_name'] ?? 'Admin');
if (!in_array($roleName, ['Super Admin','Admin','Manager','Billing'], true)) { die('Access denied.'); }

// Handle Export Requests
if (isset($_GET['export']) && in_array($_GET['export'], ['pdf', 'excel'])) {
    $filter_type = $_GET['filter_type'] ?? 'all';
    $start_date = $_GET['start_date'] ?? '';
    $end_date = $_GET['end_date'] ?? '';
    $group_filter = (int)($_GET['group_filter'] ?? 0);
    $search_member = $_GET['search_member'] ?? '';
    
    $where_sql = "ci.business_id = ? AND m.status = 'Active' AND ci.status = 'Open'";
    $params = [$businessId];
    $types = "i";
    
    if ($filter_type == 'overdue') {
        $where_sql .= " AND ci.due_date < CURDATE()";
    } elseif ($filter_type == 'today') {
        $where_sql .= " AND ci.due_date = CURDATE()";
    } elseif ($filter_type == 'week') {
        $where_sql .= " AND YEARWEEK(ci.due_date, 1) = YEARWEEK(CURDATE(), 1)";
    } elseif ($filter_type == 'month') {
        $where_sql .= " AND MONTH(ci.due_date) = MONTH(CURDATE()) AND YEAR(ci.due_date) = YEAR(CURDATE())";
    } elseif ($filter_type == 'range' && !empty($start_date) && !empty($end_date)) {
        $where_sql .= " AND ci.due_date BETWEEN ? AND ?";
        $params[] = $start_date;
        $params[] = $end_date;
        $types .= "ss";
    }
    
    if ($group_filter > 0) {
        $where_sql .= " AND g.id = ?";
        $params[] = $group_filter;
        $types .= "i";
    }
    
    if (!empty($search_member)) {
        $where_sql .= " AND (m.member_no LIKE ? OR m.subscriber_name LIKE ? OR m.subscriber_mobile LIKE ?)";
        $search_param = "%$search_member%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $types .= "sss";
    }
    
    $query = "SELECT m.id as member_id, m.member_no, m.subscriber_name, m.subscriber_mobile, 
                     g.group_name, g.installment_amount, ci.id as installment_id, ci.installment_no, ci.due_date,
                     COALESCE(SUM(cc.paid_amount), 0) as paid 
              FROM chit_installments ci 
              INNER JOIN chit_groups g ON g.id = ci.group_id 
              INNER JOIN chit_members m ON m.group_id = g.id 
              LEFT JOIN chit_collections cc ON cc.installment_id = ci.id AND cc.member_id = m.id 
              WHERE $where_sql 
              GROUP BY m.id, ci.id 
              HAVING paid < g.installment_amount 
              ORDER BY ci.due_date ASC";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $export_result = $stmt->get_result();
    $export_rows = [];
    $total_due_amount = 0;
    $total_pending = 0;
    $overdue_count = 0;
    $today_date = date('Y-m-d');
    
    while ($row = $export_result->fetch_assoc()) {
        $row['pending'] = (float)$row['installment_amount'] - (float)$row['paid'];
        $row['is_overdue'] = strtotime($row['due_date']) < strtotime($today_date);
        if ($row['is_overdue']) $overdue_count++;
        $export_rows[] = $row;
        $total_due_amount += (float)$row['installment_amount'];
        $total_pending += $row['pending'];
    }
    $stmt->close();
    
    // Get summary for export header
    $summary = [
        'total_members' => count($export_rows),
        'total_due' => $total_due_amount,
        'total_pending' => $total_pending,
        'overdue_count' => $overdue_count
    ];
    
    if ($_GET['export'] == 'excel') {
        // Excel Export
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="chit_due_list_' . date('Ymd_His') . '.xls"');
        
        echo '<html><head><meta charset="UTF-8"></head><body>';
        echo '<h2>Chit Due List Report</h2>';
        echo '<p>Generated on: ' . date('d-m-Y H:i:s') . '</p>';
        
        // Filter info
        echo '<h4>Filter Applied:</h4>';
        echo '<table border="0" cellpadding="3">';
        if ($filter_type == 'overdue') echo '<tr><td><strong>Filter:</strong></td><td>Overdue Only</td></tr>';
        elseif ($filter_type == 'today') echo '<tr><td><strong>Filter:</strong></td><td>Today\'s Due</td></tr>';
        elseif ($filter_type == 'week') echo '<tr><td><strong>Filter:</strong></td><td>This Week</td></tr>';
        elseif ($filter_type == 'month') echo '<tr><td><strong>Filter:</strong></td><td>This Month</td></tr>';
        elseif ($filter_type == 'range') echo '<tr><td><strong>Date Range:</strong></td><td>' . date('d-m-Y', strtotime($start_date)) . ' to ' . date('d-m-Y', strtotime($end_date)) . '</td></tr>';
        if ($group_filter > 0) echo '<tr><td><strong>Group Filter:</strong></td><td>Applied</td></tr>';
        if (!empty($search_member)) echo '<tr><td><strong>Search:</strong></td><td>' . htmlspecialchars($search_member) . '</td></tr>';
        echo '</table><br>';
        
        // Summary
        echo '<h4>Summary:</h4>';
        echo '<table border="0" cellpadding="3">';
        echo '<tr><td><strong>Total Due Members:</strong></td><td>' . $summary['total_members'] . '</td></tr>';
        echo '<tr><td><strong>Total Due Amount:</strong></td><td>' . money($summary['total_due']) . '</td></tr>';
        echo '<tr><td><strong>Total Pending Amount:</strong></td><td>' . money($summary['total_pending']) . '</td></tr>';
        echo '<tr><td><strong>Overdue Count:</strong></td><td>' . $summary['overdue_count'] . '</td></tr>';
        echo '</table><br>';
        
        echo '<table border="1" cellpadding="5">';
        echo '<thead>';
        echo '<tr bgcolor="#4e73df" style="color:white">';
        echo '<th>#</th><th>Due Date</th><th>Group Name</th><th>Member No</th><th>Member Name</th><th>Mobile</th>';
        echo '<th>Installment</th><th>Due Amount</th><th>Paid Amount</th><th>Pending Amount</th><th>Status</th>';
        echo '</tr></thead><tbody>';
        
        $counter = 1;
        foreach ($export_rows as $row) {
            $status = $row['is_overdue'] ? 'Overdue' : 'Upcoming';
            $status_color = $row['is_overdue'] ? '#ffcccc' : '#ffffff';
            echo '<tr style="background-color:' . $status_color . '">';
            echo '<td>' . $counter++ . '</td>';
            echo '<td>' . date('d-m-Y', strtotime($row['due_date'])) . '</td>';
            echo '<td>' . htmlspecialchars($row['group_name']) . '</td>';
            echo '<td>' . htmlspecialchars($row['member_no']) . '</td>';
            echo '<td>' . htmlspecialchars($row['subscriber_name']) . '</td>';
            echo '<td>' . htmlspecialchars($row['subscriber_mobile']) . '</td>';
            echo '<td>' . $row['installment_no'] . '</td>';
            echo '<td align="right">' . money($row['installment_amount']) . '</td>';
            echo '<td align="right">' . money($row['paid']) . '</td>';
            echo '<td align="right"><strong>' . money($row['pending']) . '</strong></td>';
            echo '<td>' . $status . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</body></html>';
        exit;
    }
    
    if ($_GET['export'] == 'pdf') {
        // PDF Export
        define('FPDF_FONTPATH', __DIR__ . '/libs/font/');
        require_once 'libs/fpdf.php';
        
        class PDF extends FPDF {
            function Header() {
                $this->SetFont('Arial', 'B', 14);
                $this->Cell(0, 10, 'Chit Due List Report', 0, 1, 'C');
                $this->SetFont('Arial', '', 10);
                $this->Cell(0, 5, 'Generated on: ' . date('d-m-Y H:i:s'), 0, 1, 'C');
                $this->Ln(5);
            }
            
            function Footer() {
                $this->SetY(-15);
                $this->SetFont('Arial', 'I', 8);
                $this->Cell(0, 10, 'Page ' . $this->PageNo(), 0, 0, 'C');
            }
        }
        
        $pdf = new PDF('L', 'mm', 'A4');
        $pdf->AddPage();
        
        // Filter Info
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(0, 8, 'Filter Applied:', 0, 1);
        $pdf->SetFont('Arial', '', 9);
        if ($filter_type == 'overdue') $pdf->Cell(0, 6, '- Overdue Only', 0, 1);
        elseif ($filter_type == 'today') $pdf->Cell(0, 6, '- Today\'s Due', 0, 1);
        elseif ($filter_type == 'week') $pdf->Cell(0, 6, '- This Week', 0, 1);
        elseif ($filter_type == 'month') $pdf->Cell(0, 6, '- This Month', 0, 1);
        elseif ($filter_type == 'range') $pdf->Cell(0, 6, '- Date Range: ' . date('d-m-Y', strtotime($start_date)) . ' to ' . date('d-m-Y', strtotime($end_date)), 0, 1);
        if ($group_filter > 0) $pdf->Cell(0, 6, '- Group Filter: Applied', 0, 1);
        if (!empty($search_member)) $pdf->Cell(0, 6, '- Search: ' . $search_member, 0, 1);
        $pdf->Ln(3);
        
        // Summary Box
        $pdf->SetFillColor(230, 240, 255);
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(45, 8, 'Total Due Members:', 1, 0, 'L', true);
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(35, 8, $summary['total_members'], 1, 0, 'R');
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(45, 8, 'Total Pending:', 1, 0, 'L', true);
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(40, 8, '' . money($summary['total_pending']), 1, 0, 'R');
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(40, 8, 'Overdue:', 1, 0, 'L', true);
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(35, 8, $summary['overdue_count'], 1, 0, 'R');
        $pdf->Ln(10);
        
        // Table Header
        $pdf->SetFillColor(78, 115, 223);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 8);
        $headers = ['#', 'Due Date', 'Group', 'Member No', 'Member Name', 'Mobile', 'Inst.', 'Due ()', 'Paid ()', 'Pending ()', 'Status'];
        $widths = [8, 22, 45, 22, 45, 30, 12, 22, 22, 22, 22];
        foreach ($headers as $i => $header) {
            $pdf->Cell($widths[$i], 8, $header, 1, 0, 'C', true);
        }
        $pdf->Ln();
        
        // Table Data
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Arial', '', 7);
        $counter = 1;
        foreach ($export_rows as $row) {
            $status = $row['is_overdue'] ? 'Overdue' : 'Upcoming';
            if ($row['is_overdue']) {
                $pdf->SetFillColor(255, 220, 220);
                $fill = true;
            } else {
                $fill = false;
            }
            
            $pdf->Cell($widths[0], 7, $counter++, 1, 0, 'C', $fill);
            $pdf->Cell($widths[1], 7, date('d-m-Y', strtotime($row['due_date'])), 1, 0, 'C', $fill);
            $pdf->Cell($widths[2], 7, substr($row['group_name'], 0, 20), 1, 0, 'L', $fill);
            $pdf->Cell($widths[3], 7, $row['member_no'], 1, 0, 'L', $fill);
            $pdf->Cell($widths[4], 7, substr($row['subscriber_name'], 0, 22), 1, 0, 'L', $fill);
            $pdf->Cell($widths[5], 7, $row['subscriber_mobile'], 1, 0, 'L', $fill);
            $pdf->Cell($widths[6], 7, $row['installment_no'], 1, 0, 'C', $fill);
            $pdf->Cell($widths[7], 7, money($row['installment_amount']), 1, 0, 'R', $fill);
            $pdf->Cell($widths[8], 7, money($row['paid']), 1, 0, 'R', $fill);
            $pdf->Cell($widths[9], 7, money($row['pending']), 1, 0, 'R', $fill);
            $pdf->Cell($widths[10], 7, $status, 1, 0, 'C', $fill);
            $pdf->Ln();
        }
        
        $pdf->Output('D', 'chit_due_list_' . date('Ymd_His') . '.pdf');
        exit;
    }
}

// Get Groups for filter
$groups = [];
$group_res = $conn->query("SELECT id, group_name FROM chit_groups WHERE business_id = {$businessId} AND status = 'Active' ORDER BY group_name");
if ($group_res) while ($g = $group_res->fetch_assoc()) $groups[] = $g;

// Get filter values
$filter_type = $_GET['filter_type'] ?? 'all';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$group_filter = (int)($_GET['group_filter'] ?? 0);
$search_member = $_GET['search_member'] ?? '';

// Main Query with Filters
$where_sql = "ci.business_id = ? AND m.status = 'Active' AND ci.status = 'Open'";
$params = [$businessId];
$types = "i";

if ($filter_type == 'overdue') {
    $where_sql .= " AND ci.due_date < CURDATE()";
} elseif ($filter_type == 'today') {
    $where_sql .= " AND ci.due_date = CURDATE()";
} elseif ($filter_type == 'week') {
    $where_sql .= " AND YEARWEEK(ci.due_date, 1) = YEARWEEK(CURDATE(), 1)";
} elseif ($filter_type == 'month') {
    $where_sql .= " AND MONTH(ci.due_date) = MONTH(CURDATE()) AND YEAR(ci.due_date) = YEAR(CURDATE())";
} elseif ($filter_type == 'range' && !empty($start_date) && !empty($end_date)) {
    $where_sql .= " AND ci.due_date BETWEEN ? AND ?";
    $params[] = $start_date;
    $params[] = $end_date;
    $types .= "ss";
}

if ($group_filter > 0) {
    $where_sql .= " AND g.id = ?";
    $params[] = $group_filter;
    $types .= "i";
}

if (!empty($search_member)) {
    $where_sql .= " AND (m.member_no LIKE ? OR m.subscriber_name LIKE ? OR m.subscriber_mobile LIKE ?)";
    $search_param = "%$search_member%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

$query = "SELECT m.id as member_id, m.member_no, m.subscriber_name, m.subscriber_mobile, 
                 g.group_name, g.id as group_id, g.installment_amount, ci.id as installment_id, ci.installment_no, ci.due_date,
                 COALESCE(SUM(cc.paid_amount), 0) as paid 
          FROM chit_installments ci 
          INNER JOIN chit_groups g ON g.id = ci.group_id 
          INNER JOIN chit_members m ON m.group_id = g.id 
          LEFT JOIN chit_collections cc ON cc.installment_id = ci.id AND cc.member_id = m.id 
          WHERE $where_sql 
          GROUP BY m.id, ci.id 
          HAVING paid < g.installment_amount 
          ORDER BY ci.due_date ASC";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$rows = [];
$total_due_amount = 0;
$total_pending = 0;
$overdue_count = 0;
$today_date = date('Y-m-d');

while ($row = $result->fetch_assoc()) {
    $row['pending'] = (float)$row['installment_amount'] - (float)$row['paid'];
    $row['is_overdue'] = strtotime($row['due_date']) < strtotime($today_date);
    if ($row['is_overdue']) $overdue_count++;
    $rows[] = $row;
    $total_due_amount += (float)$row['installment_amount'];
    $total_pending += $row['pending'];
}
$stmt->close();
?>
<!doctype html><html lang="en"><?php include('includes/head.php'); ?>
<style>
.filter-card { background: #f8f9fc; border: 1px solid #e3e6f0; border-radius: 10px; padding: 15px; margin-bottom: 20px; }
.filter-label { font-weight: 600; margin-bottom: 5px; font-size: 13px; color: #4e73df; }
.summary-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 10px; padding: 15px; margin-bottom: 20px; }
.summary-item { text-align: center; border-right: 1px solid rgba(255,255,255,0.3); }
.summary-item:last-child { border-right: none; }
.summary-number { font-size: 24px; font-weight: bold; }
.summary-label { font-size: 12px; opacity: 0.9; }
.badge-overdue { background: #dc3545; color: white; padding: 3px 8px; border-radius: 20px; font-size: 11px; }
.badge-upcoming { background: #28a745; color: white; padding: 3px 8px; border-radius: 20px; font-size: 11px; }
.btn-export { margin-left: 10px; }
.export-buttons { margin-top: 15px; text-align: right; }
@media print { .no-print { display: none; } .main-content { margin: 0; padding: 0; } .page-content { padding: 10px; } }
</style>
<body data-sidebar="dark"><?php include('includes/pre-loader.php'); ?><div id="layout-wrapper"><?php include('includes/topbar.php'); ?><div class="vertical-menu"><div data-simplebar class="h-100"><?php include('includes/sidebar.php'); ?></div></div><div class="main-content"><div class="page-content"><div class="container-fluid">
<?php if(isset($_SESSION['success'])): ?><div class="alert alert-success alert-dismissible fade show"><?php echo h($_SESSION['success']); unset($_SESSION['success']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<?php if(isset($_SESSION['error'])): ?><div class="alert alert-danger alert-dismissible fade show"><?php echo h($_SESSION['error']); unset($_SESSION['error']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

<div class="card no-print">
    <div class="card-body">
        <h4 class="card-title mb-3">Chit Due List</h4>
        <form method="get" id="filterForm">
            <div class="row g-3">
                <div class="col-md-2">
                    <label class="filter-label">Filter Type</label>
                    <select name="filter_type" id="filter_type" class="form-select">
                        <option value="all" <?php echo $filter_type == 'all' ? 'selected' : ''; ?>>All Dues</option>
                        <option value="overdue" <?php echo $filter_type == 'overdue' ? 'selected' : ''; ?>>Overdue Only</option>
                        <option value="today" <?php echo $filter_type == 'today' ? 'selected' : ''; ?>>Today's Due</option>
                        <option value="week" <?php echo $filter_type == 'week' ? 'selected' : ''; ?>>This Week</option>
                        <option value="month" <?php echo $filter_type == 'month' ? 'selected' : ''; ?>>This Month</option>
                        <option value="range" <?php echo $filter_type == 'range' ? 'selected' : ''; ?>>Date Range</option>
                    </select>
                </div>
                <div class="col-md-2" id="date_range_div" style="display: <?php echo $filter_type == 'range' ? 'block' : 'none'; ?>">
                    <label class="filter-label">From Date</label>
                    <input type="date" name="start_date" class="form-control" value="<?php echo h($start_date); ?>">
                </div>
                <div class="col-md-2" id="date_range_div2" style="display: <?php echo $filter_type == 'range' ? 'block' : 'none'; ?>">
                    <label class="filter-label">To Date</label>
                    <input type="date" name="end_date" class="form-control" value="<?php echo h($end_date); ?>">
                </div>
                <div class="col-md-2">
                    <label class="filter-label">Select Group</label>
                    <select name="group_filter" class="form-select">
                        <option value="0">All Groups</option>
                        <?php foreach($groups as $g): ?>
                            <option value="<?php echo (int)$g['id']; ?>" <?php echo $group_filter === (int)$g['id'] ? 'selected' : ''; ?>>
                                <?php echo h($g['group_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="filter-label">Search Member</label>
                    <input type="text" name="search_member" class="form-control" placeholder="Member No / Name / Mobile" value="<?php echo h($search_member); ?>">
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">Filter</button>
                </div>
            </div>
        </form>
        
        <?php if(!empty($rows)): ?>
        <div class="export-buttons">
            <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'excel'])); ?>" class="btn btn-success btn-sm">
                <i class="fas fa-file-excel"></i> Export Excel
            </a>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'pdf'])); ?>" class="btn btn-danger btn-sm">
                <i class="fas fa-file-pdf"></i> Export PDF
            </a>
            <button onclick="window.print();" class="btn btn-secondary btn-sm">
                <i class="fas fa-print"></i> Print
            </button>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if(!empty($rows)): ?>
<!-- Summary Cards -->
<div class="row">
    <div class="col-md-3">
        <div class="summary-card">
            <div class="summary-item">
                <div class="summary-number"><?php echo count($rows); ?></div>
                <div class="summary-label">Total Due Members</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="summary-card" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
            <div class="summary-item">
                <div class="summary-number"><?php echo money($total_due_amount); ?></div>
                <div class="summary-label">Total Due Amount</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="summary-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
            <div class="summary-item">
                <div class="summary-number"><?php echo money($total_pending); ?></div>
                <div class="summary-label">Total Pending</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="summary-card" style="background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);">
            <div class="summary-item">
                <div class="summary-number"><?php echo $overdue_count; ?></div>
                <div class="summary-label">Overdue Count</div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-sm table-striped">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Due Date</th>
                        <th>Group Name</th>
                        <th>Member No</th>
                        <th>Member Name</th>
                        <th>Mobile</th>
                        <th>Installment</th>
                        <th class="text-end">Due Amount</th>
                        <th class="text-end">Paid Amount</th>
                        <th class="text-end">Pending Amount</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($rows)): ?>
                        <tr>
                            <td colspan="12" class="text-center text-muted">
                                <?php if(!empty($search_member) || $filter_type != 'all' || $group_filter > 0): ?>
                                    No due records found for the selected filters.
                                <?php else: ?>
                                    No due records available.
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach($rows as $i=>$r): ?>
                            <tr class="<?php echo $r['is_overdue'] ? 'table-danger' : ''; ?>">
                                <td><?php echo $i+1; ?></td>
                                <td>
                                    <?php echo h(date('d-m-Y',strtotime($r['due_date']))); ?>
                                    <?php if($r['is_overdue']): ?>
                                        <span class="badge-overdue ms-1">Overdue</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo h($r['group_name']); ?></td>
                                <td><?php echo h($r['member_no']); ?></td>
                                <td><?php echo h($r['subscriber_name']); ?></td>
                                <td>
                                    <a href="tel:<?php echo h($r['subscriber_mobile']); ?>" class="text-decoration-none">
                                        <?php echo h($r['subscriber_mobile']); ?>
                                    </a>
                                </td>
                                <td class="text-center"><?php echo (int)$r['installment_no']; ?></td>
                                <td class="text-end"><?php echo money($r['installment_amount']); ?></td>
                                <td class="text-end"><?php echo money($r['paid']); ?></td>
                                <td class="text-end fw-bold text-danger"><?php echo money($r['pending']); ?></td>
                                <td>
                                    <?php if($r['is_overdue']): ?>
                                        <span class="badge-overdue">Overdue</span>
                                    <?php else: ?>
                                        <span class="badge-upcoming">Upcoming</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a class="btn btn-success btn-sm" href="chit-collection.php?member_id=<?php echo (int)$r['member_id']; ?>&installment_id=<?php echo (int)$r['installment_id']; ?>">
                                        <i class="fas fa-hand-holding-usd"></i> Collect
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <?php if(!empty($rows)): ?>
                <tfoot class="table-secondary">
                    <tr>
                        <th colspan="7" class="text-end">Totals</th>
                        <th class="text-end"><?php echo money($total_due_amount); ?></th>
                        <th class="text-end"></th>
                        <th class="text-end text-danger"><?php echo money($total_pending); ?></th>
                        <th colspan="2"></th>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

</div></div><?php include('includes/footer.php'); ?></div></div><?php include('includes/rightbar.php'); ?><?php include('includes/scripts.php'); ?>
<script>
document.getElementById('filter_type').addEventListener('change', function() {
    var rangeDiv = document.getElementById('date_range_div');
    var rangeDiv2 = document.getElementById('date_range_div2');
    if (this.value === 'range') {
        rangeDiv.style.display = 'block';
        rangeDiv2.style.display = 'block';
    } else {
        rangeDiv.style.display = 'none';
        rangeDiv2.style.display = 'none';
    }
});
</script>
</body></html>