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
if (isset($_GET['export']) && in_array($_GET['export'], ['pdf', 'excel']) && isset($_GET['member_id']) && $_GET['member_id'] > 0) {
    $export_member_id = (int)$_GET['member_id'];
    $filter_type = $_GET['filter_type'] ?? 'all';
    $start_date = $_GET['start_date'] ?? '';
    $end_date = $_GET['end_date'] ?? '';
    $group_filter = (int)($_GET['group_filter'] ?? 0);
    
    // Build query similar to main query
    $where_sql = "cc.business_id = ? AND cc.member_id = ?";
    $params = [$businessId, $export_member_id];
    $types = "ii";
    
    if ($filter_type == 'today') {
        $where_sql .= " AND DATE(cc.collection_date) = CURDATE()";
    } elseif ($filter_type == 'week') {
        $where_sql .= " AND YEARWEEK(cc.collection_date, 1) = YEARWEEK(CURDATE(), 1)";
    } elseif ($filter_type == 'month') {
        $where_sql .= " AND MONTH(cc.collection_date) = MONTH(CURDATE()) AND YEAR(cc.collection_date) = YEAR(CURDATE())";
    } elseif ($filter_type == 'range' && !empty($start_date) && !empty($end_date)) {
        $where_sql .= " AND cc.collection_date BETWEEN ? AND ?";
        $params[] = $start_date;
        $params[] = $end_date;
        $types .= "ss";
    }
    if ($group_filter > 0) {
        $where_sql .= " AND cc.group_id = ?";
        $params[] = $group_filter;
        $types .= "i";
    }
    
    $query = "SELECT cc.*, g.group_name, ci.installment_no, pm.method_name, cm.subscriber_name, cm.member_no 
              FROM chit_collections cc 
              INNER JOIN chit_groups g ON g.id = cc.group_id 
              INNER JOIN chit_installments ci ON ci.id = cc.installment_id 
              INNER JOIN chit_members cm ON cm.id = cc.member_id
              LEFT JOIN payment_methods pm ON pm.id = cc.payment_method_id 
              WHERE $where_sql 
              ORDER BY cc.collection_date DESC, cc.id DESC";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $export_result = $stmt->get_result();
    $export_rows = [];
    $total_paid = 0;
    while ($row = $export_result->fetch_assoc()) {
        $export_rows[] = $row;
        $total_paid += (float)$row['paid_amount'];
    }
    $stmt->close();
    
    // Get member info
    $member_query = "SELECT subscriber_name, member_no, group_id FROM chit_members WHERE id = ? AND business_id = ?";
    $member_stmt = $conn->prepare($member_query);
    $member_stmt->bind_param('ii', $export_member_id, $businessId);
    $member_stmt->execute();
    $member_info = $member_stmt->get_result()->fetch_assoc();
    $member_stmt->close();
    
    if ($_GET['export'] == 'excel') {
        // Excel Export
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="chit_ledger_' . date('Ymd_His') . '.xls"');
        
        echo '<html><head><meta charset="UTF-8"></head><body>';
        echo '<h2>Chit Collection Ledger</h2>';
        echo '<h3>Member: ' . htmlspecialchars($member_info['subscriber_name'] ?? 'N/A') . ' (' . htmlspecialchars($member_info['member_no'] ?? '') . ')</h3>';
        echo '<p>Generated on: ' . date('d-m-Y H:i:s') . '</p>';
        echo '<table border="1" cellpadding="5">';
        echo '<thead><tr>';
        echo '<th>#</th><th>Receipt No</th><th>Collection Date</th><th>Group Name</th><th>Installment</th>';
        echo '<th>Due Amount</th><th>Paid Amount</th><th>Discount</th><th>Penalty</th><th>Net Amount</th><th>Payment Method</th>';
        echo '</tr></thead><tbody>';
        
        $counter = 1;
        foreach ($export_rows as $row) {
            echo '<tr>';
            echo '<td>' . $counter++ . '</td>';
            echo '<td>' . htmlspecialchars($row['receipt_no']) . '</td>';
            echo '<td>' . date('d-m-Y', strtotime($row['collection_date'])) . '</td>';
            echo '<td>' . htmlspecialchars($row['group_name']) . '</td>';
            echo '<td>' . $row['installment_no'] . '</td>';
            echo '<td>' . money($row['due_amount']) . '</td>';
            echo '<td>' . money($row['paid_amount']) . '</td>';
            echo '<td>' . money($row['discount_amount']) . '</td>';
            echo '<td>' . money($row['penalty_amount']) . '</td>';
            echo '<td>' . money($row['net_amount']) . '</td>';
            echo '<td>' . htmlspecialchars($row['method_name']) . '</td>';
            echo '</tr>';
        }
        echo '<tr><td colspan="6" align="right"><strong>Total Paid</strong></td><td><strong>' . money($total_paid) . '</strong></td><td colspan="4"></td></tr>';
        echo '</tbody></table>';
        echo '</body></html>';
        exit;
    }
    
    if ($_GET['export'] == 'pdf') {
        // PDF Export using libs/fpdf.php
        define('FPDF_FONTPATH', __DIR__ . '/libs/font/');
        require_once 'libs/fpdf.php';
        
        class PDF extends FPDF {
            function Header() {
                $this->SetFont('Arial', 'B', 14);
                $this->Cell(0, 10, 'Chit Collection Ledger', 0, 1, 'C');
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
        $pdf->SetFont('Arial', 'B', 11);
        
        // Member Info
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(0, 8, 'Member: ' . ($member_info['subscriber_name'] ?? 'N/A') . ' (' . ($member_info['member_no'] ?? '') . ')', 0, 1);
        $pdf->Ln(3);
        
        // Table Header
        $pdf->SetFillColor(200, 220, 255);
        $pdf->SetFont('Arial', 'B', 8);
        $headers = ['#', 'Receipt No', 'Date', 'Group', 'Inst.', 'Due (₹)', 'Paid (₹)', 'Discount', 'Penalty', 'Net (₹)', 'Method'];
        $widths = [10, 30, 25, 45, 12, 28, 28, 25, 25, 28, 30];
        foreach ($headers as $i => $header) {
            $pdf->Cell($widths[$i], 8, $header, 1, 0, 'C', true);
        }
        $pdf->Ln();
        
        // Table Data
        $pdf->SetFont('Arial', '', 8);
        $counter = 1;
        foreach ($export_rows as $row) {
            $pdf->Cell($widths[0], 7, $counter++, 1, 0, 'C');
            $pdf->Cell($widths[1], 7, substr($row['receipt_no'], 0, 18), 1, 0, 'L');
            $pdf->Cell($widths[2], 7, date('d-m-Y', strtotime($row['collection_date'])), 1, 0, 'C');
            $pdf->Cell($widths[3], 7, substr($row['group_name'], 0, 20), 1, 0, 'L');
            $pdf->Cell($widths[4], 7, $row['installment_no'], 1, 0, 'C');
            $pdf->Cell($widths[5], 7, money($row['due_amount']), 1, 0, 'R');
            $pdf->Cell($widths[6], 7, money($row['paid_amount']), 1, 0, 'R');
            $pdf->Cell($widths[7], 7, money($row['discount_amount']), 1, 0, 'R');
            $pdf->Cell($widths[8], 7, money($row['penalty_amount']), 1, 0, 'R');
            $pdf->Cell($widths[9], 7, money($row['net_amount']), 1, 0, 'R');
            $pdf->Cell($widths[10], 7, substr($row['method_name'] ?? '', 0, 12), 1, 0, 'L');
            $pdf->Ln();
        }
        
        // Total Row
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Cell(array_sum($widths) - $widths[6], 7, 'Total Paid:', 1, 0, 'R');
        $pdf->Cell($widths[6], 7, money($total_paid), 1, 0, 'R');
        $pdf->Cell(array_sum(array_slice($widths, 7)), 7, '', 1, 0, 'R');
        
        $pdf->Output('D', 'chit_ledger_' . date('Ymd_His') . '.pdf');
        exit;
    }
}

// Get Members
$members = [];
$res = $conn->query("SELECT id, member_no, subscriber_name FROM chit_members WHERE business_id = {$businessId} ORDER BY subscriber_name");
if ($res) while ($r = $res->fetch_assoc()) $members[] = $r;

// Get Groups for filter
$groups = [];
$group_res = $conn->query("SELECT id, group_name FROM chit_groups WHERE business_id = {$businessId} AND status = 'Active' ORDER BY group_name");
if ($group_res) while ($g = $group_res->fetch_assoc()) $groups[] = $g;

// Main Query with Filters
$member_id = (int)($_GET['member_id'] ?? 0);
$filter_type = $_GET['filter_type'] ?? 'all';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$group_filter = (int)($_GET['group_filter'] ?? 0);
$rows = [];
$total_paid = 0;

if ($member_id > 0) {
    $where_sql = "cc.business_id = ? AND cc.member_id = ?";
    $params = [$businessId, $member_id];
    $types = "ii";
    
    if ($filter_type == 'today') {
        $where_sql .= " AND DATE(cc.collection_date) = CURDATE()";
    } elseif ($filter_type == 'week') {
        $where_sql .= " AND YEARWEEK(cc.collection_date, 1) = YEARWEEK(CURDATE(), 1)";
    } elseif ($filter_type == 'month') {
        $where_sql .= " AND MONTH(cc.collection_date) = MONTH(CURDATE()) AND YEAR(cc.collection_date) = YEAR(CURDATE())";
    } elseif ($filter_type == 'range' && !empty($start_date) && !empty($end_date)) {
        $where_sql .= " AND cc.collection_date BETWEEN ? AND ?";
        $params[] = $start_date;
        $params[] = $end_date;
        $types .= "ss";
    }
    
    if ($group_filter > 0) {
        $where_sql .= " AND cc.group_id = ?";
        $params[] = $group_filter;
        $types .= "i";
    }
    
    $query = "SELECT cc.*, g.group_name, ci.installment_no, pm.method_name, cm.subscriber_name, cm.member_no 
              FROM chit_collections cc 
              INNER JOIN chit_groups g ON g.id = cc.group_id 
              INNER JOIN chit_installments ci ON ci.id = cc.installment_id 
              INNER JOIN chit_members cm ON cm.id = cc.member_id
              LEFT JOIN payment_methods pm ON pm.id = cc.payment_method_id 
              WHERE $where_sql 
              ORDER BY cc.collection_date DESC, cc.id DESC";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
        $total_paid += (float)$row['paid_amount'];
    }
    $stmt->close();
}
?>
<!doctype html><html lang="en"><?php include('includes/head.php'); ?>
<style>
.filter-card { background: #f8f9fc; border: 1px solid #e3e6f0; border-radius: 10px; padding: 15px; margin-bottom: 20px; }
.filter-label { font-weight: 600; margin-bottom: 5px; font-size: 13px; color: #4e73df; }
.btn-export { margin-left: 10px; }
.export-buttons { margin-top: 15px; text-align: right; }
@media print { .no-print { display: none; } .main-content { margin: 0; padding: 0; } .page-content { padding: 10px; } }
</style>
<body data-sidebar="dark"><?php include('includes/pre-loader.php'); ?><div id="layout-wrapper"><?php include('includes/topbar.php'); ?><div class="vertical-menu"><div data-simplebar class="h-100"><?php include('includes/sidebar.php'); ?></div></div><div class="main-content"><div class="page-content"><div class="container-fluid">
<?php if(isset($_SESSION['success'])): ?><div class="alert alert-success alert-dismissible fade show"><?php echo h($_SESSION['success']); unset($_SESSION['success']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<?php if(isset($_SESSION['error'])): ?><div class="alert alert-danger alert-dismissible fade show"><?php echo h($_SESSION['error']); unset($_SESSION['error']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

<div class="card no-print">
    <div class="card-body">
        <h4 class="card-title mb-3">Chit Collection Ledger</h4>
        <form method="get" id="filterForm">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="filter-label">Select Member *</label>
                    <select name="member_id" class="form-select" required>
                        <option value="">-- Select Member --</option>
                        <?php foreach($members as $m): ?>
                            <option value="<?php echo (int)$m['id']; ?>" <?php echo $member_id === (int)$m['id'] ? 'selected' : ''; ?>>
                                <?php echo h($m['member_no'] . ' - ' . $m['subscriber_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="filter-label">Filter Type</label>
                    <select name="filter_type" id="filter_type" class="form-select">
                        <option value="all" <?php echo $filter_type == 'all' ? 'selected' : ''; ?>>All Records</option>
                        <option value="today" <?php echo $filter_type == 'today' ? 'selected' : ''; ?>>Today</option>
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
                    <label class="filter-label">Group (Optional)</label>
                    <select name="group_filter" class="form-select">
                        <option value="0">All Groups</option>
                        <?php foreach($groups as $g): ?>
                            <option value="<?php echo (int)$g['id']; ?>" <?php echo $group_filter === (int)$g['id'] ? 'selected' : ''; ?>>
                                <?php echo h($g['group_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">View</button>
                </div>
            </div>
        </form>
        
        <?php if($member_id > 0 && !empty($rows)): ?>
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

<div class="card">
    <div class="card-body">
        <?php if($member_id > 0 && !empty($rows)): 
            $member_name = $rows[0]['subscriber_name'] ?? 'N/A';
            $member_no = $rows[0]['member_no'] ?? 'N/A';
        ?>
        <div class="mb-3">
            <h5>Member: <?php echo h($member_name); ?> (<?php echo h($member_no); ?>)</h5>
            <p class="text-muted mb-0">
                Filter: 
                <?php if($filter_type == 'today'): ?>Today's Collections
                <?php elseif($filter_type == 'week'): ?>This Week's Collections
                <?php elseif($filter_type == 'month'): ?>This Month's Collections
                <?php elseif($filter_type == 'range' && !empty($start_date) && !empty($end_date)): ?>
                    <?php echo date('d-m-Y', strtotime($start_date)); ?> to <?php echo date('d-m-Y', strtotime($end_date)); ?>
                <?php else: ?>All Collections<?php endif; ?>
                <?php if($group_filter > 0): ?> | Group Filtered<?php endif; ?>
            </p>
        </div>
        <?php endif; ?>
        
        <div class="table-responsive">
            <table class="table table-bordered table-sm table-striped">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Receipt No</th>
                        <th>Collection Date</th>
                        <th>Group Name</th>
                        <th>Installment</th>
                        <th class="text-end">Due Amount (₹)</th>
                        <th class="text-end">Paid Amount (₹)</th>
                        <th class="text-end">Discount (₹)</th>
                        <th class="text-end">Penalty (₹)</th>
                        <th class="text-end">Net Amount (₹)</th>
                        <th>Payment Method</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($rows) && $member_id > 0): ?>
                        <tr><td colspan="11" class="text-center text-danger">No collections found for this member with the selected filters.</td></tr>
                    <?php elseif(empty($rows) && $member_id == 0): ?>
                        <tr><td colspan="11" class="text-center text-muted">Select a member and click View to see ledger.</td></tr>
                    <?php else: ?>
                        <?php $counter = 1; foreach($rows as $r): ?>
                        <tr>
                            <td><?php echo $counter++; ?></td>
                            <td><?php echo h($r['receipt_no']); ?></td>
                            <td><?php echo h(date('d-m-Y', strtotime($r['collection_date']))); ?></td>
                            <td><?php echo h($r['group_name']); ?></td>
                            <td class="text-center"><?php echo (int)$r['installment_no']; ?></td>
                            <td class="text-end">₹<?php echo money($r['due_amount']); ?></td>
                            <td class="text-end">₹<?php echo money($r['paid_amount']); ?></td>
                            <td class="text-end">₹<?php echo money($r['discount_amount']); ?></td>
                            <td class="text-end">₹<?php echo money($r['penalty_amount']); ?></td>
                            <td class="text-end">₹<?php echo money($r['net_amount']); ?></td>
                            <td><?php echo h($r['method_name']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <?php if(!empty($rows)): ?>
                <tfoot class="table-secondary">
                    <tr>
                        <th colspan="6" class="text-end">Total Paid Amount</th>
                        <th class="text-end">₹<?php echo money($total_paid); ?></th>
                        <th colspan="4"></th>
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