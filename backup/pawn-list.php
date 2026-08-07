<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/config.php';

if (!$conn || $conn->connect_error) {
    die('Database connection failed: ' . ($conn->connect_error ?? 'config error'));
}

$business_id = (int)($_SESSION['business_id'] ?? 1);
$currentPage = 'pawn-list';

// Helper functions
if (!function_exists('h')) {
    function h($string) { return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('money')) {
    function money($amount) { return number_format((float)$amount, 2); }
}

// Get filter parameters
$status_filter = $_GET['status'] ?? '';
$search_term = $_GET['search'] ?? '';
$customer_id = (int)($_GET['customer_id'] ?? 0);
$from_date = $_GET['from_date'] ?? '';
$to_date = $_GET['to_date'] ?? '';

// Build WHERE clause
$where_conditions = ["business_id = $business_id"];
if ($status_filter && $status_filter != 'all') {
    $where_conditions[] = "status = '" . $conn->real_escape_string($status_filter) . "'";
}
if ($search_term) {
    $search = $conn->real_escape_string($search_term);
    $where_conditions[] = "(pawn_no LIKE '%$search%' OR customer_name LIKE '%$search%' OR customer_mobile LIKE '%$search%')";
}
if ($customer_id > 0) {
    $where_conditions[] = "customer_id = $customer_id";
}
if ($from_date) {
    $where_conditions[] = "entry_date >= '$from_date'";
}
if ($to_date) {
    $where_conditions[] = "entry_date <= '$to_date'";
}

$where_clause = implode(" AND ", $where_conditions);

// Pagination
$page = (int)($_GET['page'] ?? 1);
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Get total count
$count_sql = "SELECT COUNT(*) as total FROM pawn_entries WHERE $where_clause";
$count_res = $conn->query($count_sql);
$total_records = 0;
if ($count_res) {
    $total_records = $count_res->fetch_assoc()['total'];
}
$total_pages = ceil($total_records / $per_page);

// Fetch pawn entries
$pawns = [];
$sql = "SELECT id, pawn_no, entry_date, customer_name, customer_mobile, 
               loan_amount, principal_balance, interest_rate, tenure_months, 
               maturity_date, status, created_at 
        FROM pawn_entries 
        WHERE $where_clause 
        ORDER BY id DESC 
        LIMIT $offset, $per_page";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $pawns[] = $row;
    }
}

// Get summary statistics
$summary_sql = "SELECT 
                    COUNT(*) as total_pawns,
                    SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) as active_pawns,
                    SUM(CASE WHEN status = 'Released' THEN 1 ELSE 0 END) as released_pawns,
                    SUM(CASE WHEN status = 'Auctioned' THEN 1 ELSE 0 END) as auctioned_pawns,
                    SUM(CASE WHEN status = 'Closed' THEN 1 ELSE 0 END) as closed_pawns,
                    COALESCE(SUM(loan_amount), 0) as total_loan_amount,
                    COALESCE(SUM(principal_balance), 0) as total_outstanding
                FROM pawn_entries 
                WHERE $where_clause";
$summary_res = $conn->query($summary_sql);
$summary = $summary_res ? $summary_res->fetch_assoc() : [];

// Get status counts for filter badges
$status_counts = [];
$status_sql = "SELECT status, COUNT(*) as count FROM pawn_entries WHERE business_id = $business_id GROUP BY status";
$status_res = $conn->query($status_sql);
if ($status_res) {
    while ($row = $status_res->fetch_assoc()) {
        $status_counts[$row['status']] = $row['count'];
    }
}
?>
<!doctype html>
<html lang="en">
<?php include('includes/head.php'); ?>
<title>Pawn List | Pawn Broking</title>
<style>
    .pawn-card { border-radius: 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 24px; }
    .pawn-card .card-header { background: #f8fafc; border-bottom: 1px solid #eef2f6; padding: 16px 20px; font-weight: 600; }
    .status-badge { padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 500; display: inline-block; }
    .status-active { background: #dbeafe; color: #1e40af; }
    .status-released { background: #dcfce7; color: #166534; }
    .status-auctioned { background: #fee2e2; color: #991b1b; }
    .status-closed { background: #e5e7eb; color: #4b5563; }
    .status-partially-paid { background: #fef3c7; color: #92400e; }
    .filter-badge { background: #f1f5f9; padding: 6px 14px; border-radius: 30px; cursor: pointer; transition: all 0.2s; }
    .filter-badge.active { background: #4f46e5; color: white; }
    .filter-badge:hover { background: #e2e8f0; }
    .stat-box { text-align: center; padding: 12px; background: #f8fafc; border-radius: 12px; }
    .stat-value { font-size: 20px; font-weight: 700; }
    .stat-label { font-size: 11px; color: #6c757d; margin-top: 4px; }
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
                                    <h4 class="mb-1">Pawn List</h4>
                                    <p class="text-muted mb-0">Manage and track all pawn broking entries</p>
                                </div>
                                <div>
                                    <a href="pawn-entry.php" class="btn btn-primary">
                                        <i class="fas fa-plus"></i> New Pawn Entry
                                    </a>
                                    <a href="pawn-report.php" class="btn btn-outline-secondary">
                                        <i class="fas fa-download"></i> Export
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Summary Cards -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <div class="stat-box">
                                <div class="stat-value"><?php echo (int)($summary['total_pawns'] ?? 0); ?></div>
                                <div class="stat-label">Total Pawns</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-box">
                                <div class="stat-value text-primary"><?php echo (int)($summary['active_pawns'] ?? 0); ?></div>
                                <div class="stat-label">Active</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-box">
                                <div class="stat-value text-success">₹<?php echo money($summary['total_loan_amount'] ?? 0); ?></div>
                                <div class="stat-label">Total Loan Amount</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-box">
                                <div class="stat-value text-warning">₹<?php echo money($summary['total_outstanding'] ?? 0); ?></div>
                                <div class="stat-label">Total Outstanding</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Filters -->
                    <div class="card pawn-card">
                        <div class="card-body">
                            <form method="GET" class="row g-3" id="filterForm">
                                <div class="col-md-2">
                                    <label class="form-label">Status</label>
                                    <select name="status" class="form-select" onchange="this.form.submit()">
                                        <option value="all" <?php echo $status_filter == 'all' || $status_filter == '' ? 'selected' : ''; ?>>All Status</option>
                                        <option value="Active" <?php echo $status_filter == 'Active' ? 'selected' : ''; ?>>Active (<?php echo (int)($status_counts['Active'] ?? 0); ?>)</option>
                                        <option value="Released" <?php echo $status_filter == 'Released' ? 'selected' : ''; ?>>Released (<?php echo (int)($status_counts['Released'] ?? 0); ?>)</option>
                                        <option value="Auctioned" <?php echo $status_filter == 'Auctioned' ? 'selected' : ''; ?>>Auctioned (<?php echo (int)($status_counts['Auctioned'] ?? 0); ?>)</option>
                                        <option value="Closed" <?php echo $status_filter == 'Closed' ? 'selected' : ''; ?>>Closed (<?php echo (int)($status_counts['Closed'] ?? 0); ?>)</option>
                                        <option value="Partially Paid" <?php echo $status_filter == 'Partially Paid' ? 'selected' : ''; ?>>Partially Paid (<?php echo (int)($status_counts['Partially Paid'] ?? 0); ?>)</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">From Date</label>
                                    <input type="date" name="from_date" class="form-control" value="<?php echo h($from_date); ?>" onchange="this.form.submit()">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">To Date</label>
                                    <input type="date" name="to_date" class="form-control" value="<?php echo h($to_date); ?>" onchange="this.form.submit()">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Search</label>
                                    <div class="input-group">
                                        <input type="text" name="search" class="form-control" placeholder="Pawn No, Customer, Mobile..." value="<?php echo h($search_term); ?>">
                                        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i></button>
                                        <?php if ($search_term || $status_filter || $from_date || $to_date): ?>
                                            <a href="pawn-list.php" class="btn btn-secondary"><i class="fas fa-times"></i> Clear</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php if ($customer_id > 0): ?>
                                    <div class="col-md-2">
                                        <label class="form-label">&nbsp;</label>
                                        <div>
                                            <a href="pawn-customer-view.php?customer_id=<?php echo $customer_id; ?>" class="btn btn-outline-info w-100">
                                                <i class="fas fa-user"></i> View Customer
                                            </a>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Pawn List Table -->
                    <div class="card pawn-card">
                        <div class="card-header bg-transparent">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">Pawn Entries</h5>
                                <span class="text-muted">Total: <?php echo $total_records; ?> records</span>
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
                                            <th>Loan Amount</th>
                                            <th>Outstanding</th>
                                            <th>Interest</th>
                                            <th>Maturity Date</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($pawns)): ?>
                                            <tr><td colspan="10" class="text-center text-muted py-5">
                                                <i class="fas fa-inbox fa-3x mb-3 d-block text-muted"></i>
                                                No pawn entries found
                                                <div class="mt-2">
                                                    <a href="pawn-entry.php" class="btn btn-sm btn-primary">Create New Pawn Entry</a>
                                                </div>
                                             </td></tr>
                                        <?php else: foreach ($pawns as $pawn): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo h($pawn['pawn_no']); ?></strong>
                                                </td>
                                                <td><?php echo date('d-m-Y', strtotime($pawn['entry_date'])); ?></td>
                                                <td>
                                                    <?php echo h($pawn['customer_name']); ?>
                                                    <small class="d-block text-muted"><?php echo h($pawn['customer_mobile']); ?></small>
                                                </td>
                                                <td><?php echo h($pawn['customer_mobile']); ?></td>
                                                <td>₹<?php echo money($pawn['loan_amount']); ?></td>
                                                <td>
                                                    <strong class="text-warning">₹<?php echo money($pawn['principal_balance']); ?></strong>
                                                </td>
                                                <td><?php echo h($pawn['interest_rate']); ?>%</td>
                                                <td>
                                                    <?php echo !empty($pawn['maturity_date']) ? date('d-m-Y', strtotime($pawn['maturity_date'])) : '-'; ?>
                                                    <?php 
                                                    if (!empty($pawn['maturity_date']) && strtotime($pawn['maturity_date']) < time() && $pawn['status'] == 'Active') {
                                                        echo '<span class="badge bg-danger ms-1">Overdue</span>';
                                                    }
                                                    ?>
                                                </td>
                                                <td>
                                                    <span class="status-badge status-<?php echo strtolower(str_replace(' ', '', $pawn['status'])); ?>">
                                                        <?php echo h($pawn['status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="btn-group">
                                                        <a href="pawn-view.php?id=<?php echo $pawn['id']; ?>" class="btn btn-sm btn-outline-info" title="View Details">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                        <a href="pawn-payment.php?pawn_id=<?php echo $pawn['id']; ?>" class="btn btn-sm btn-outline-success" title="Make Payment">
                                                            <i class="fas fa-rupee-sign"></i>
                                                        </a>
                                                        <a href="pawn-interest.php?pawn_id=<?php echo $pawn['id']; ?>" class="btn btn-sm btn-outline-warning" title="Interest Collection">
                                                            <i class="fas fa-percent"></i>
                                                        </a>
                                                        <?php if ($pawn['status'] == 'Active'): ?>
                                                            <a href="pawn-release.php?id=<?php echo $pawn['id']; ?>" class="btn btn-sm btn-outline-primary" title="Release">
                                                                <i class="fas fa-hand-peace"></i>
                                                            </a>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <?php if ($total_pages > 1): ?>
                            <div class="card-footer bg-transparent">
                                <nav aria-label="Page navigation">
                                    <ul class="pagination justify-content-end mb-0">
                                        <?php if ($page > 1): ?>
                                            <li class="page-item">
                                                <a class="page-link" href="?page=<?php echo $page - 1; ?>&status=<?php echo urlencode($status_filter); ?>&search=<?php echo urlencode($search_term); ?>&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>">
                                                    Previous
                                                </a>
                                            </li>
                                        <?php endif; ?>
                                        
                                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                                <a class="page-link" href="?page=<?php echo $i; ?>&status=<?php echo urlencode($status_filter); ?>&search=<?php echo urlencode($search_term); ?>&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>">
                                                    <?php echo $i; ?>
                                                </a>
                                            </li>
                                        <?php endfor; ?>
                                        
                                        <?php if ($page < $total_pages): ?>
                                            <li class="page-item">
                                                <a class="page-link" href="?page=<?php echo $page + 1; ?>&status=<?php echo urlencode($status_filter); ?>&search=<?php echo urlencode($search_term); ?>&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>">
                                                    Next
                                                </a>
                                            </li>
                                        <?php endif; ?>
                                    </ul>
                                </nav>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                </div>
            </div>
            <?php include('includes/footer.php'); ?>
        </div>
    </div>
    
    <?php include('includes/scripts.php'); ?>
</body>
</html>