<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/config.php';

if (!$conn || $conn->connect_error) {
    die('Database connection failed: ' . ($conn->connect_error ?? 'config error'));
}

$business_id = (int)($_SESSION['business_id'] ?? 1);
$currentPage = 'pawn-customers';

// Helper functions
if (!function_exists('h')) {
    function h($string) { return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('money')) {
    function money($amount) { return number_format((float)$amount, 2); }
}

// Get filter parameters
$search_term = $_GET['search'] ?? '';
$risk_filter = $_GET['risk'] ?? '';
$kyc_filter = $_GET['kyc'] ?? '';
$occupation_filter = $_GET['occupation'] ?? '';
$sort_by = $_GET['sort_by'] ?? 'customer_name';
$sort_order = $_GET['sort_order'] ?? 'ASC';

// Build WHERE clause
$where_conditions = ["pc.business_id = $business_id"];
if ($search_term) {
    $search = $conn->real_escape_string($search_term);
    $where_conditions[] = "(c.customer_name LIKE '%$search%' OR c.customer_code LIKE '%$search%' OR c.mobile LIKE '%$search%' OR c.email LIKE '%$search%')";
}
if ($risk_filter && $risk_filter != 'all') {
    $where_conditions[] = "pc.risk_category = '" . $conn->real_escape_string($risk_filter) . "'";
}
if ($kyc_filter && $kyc_filter != 'all') {
    $where_conditions[] = "pc.kyc_verified = " . ($kyc_filter == 'verified' ? 1 : 0);
}
if ($occupation_filter && $occupation_filter != 'all') {
    $where_conditions[] = "pc.occupation = '" . $conn->real_escape_string($occupation_filter) . "'";
}

$where_clause = implode(" AND ", $where_conditions);

// Pagination
$page = (int)($_GET['page'] ?? 1);
$per_page = 15;
$offset = ($page - 1) * $per_page;

// Get total count for pagination
$count_sql = "SELECT COUNT(*) as total 
              FROM pawn_customers pc 
              INNER JOIN customers c ON pc.customer_id = c.id 
              WHERE $where_clause";
$count_res = $conn->query($count_sql);
$total_records = 0;
if ($count_res && $count_res->num_rows > 0) {
    $total_records = $count_res->fetch_assoc()['total'];
}
$total_pages = ceil($total_records / $per_page);

// Fetch pawn customers with filters
$customers = [];
$customer_sql = "SELECT pc.*, c.customer_name, c.customer_code, c.mobile, c.email, c.address_line1, c.city 
                 FROM pawn_customers pc 
                 INNER JOIN customers c ON pc.customer_id = c.id 
                 WHERE $where_clause 
                 ORDER BY $sort_by $sort_order
                 LIMIT $offset, $per_page";
$cust_res = $conn->query($customer_sql);
if ($cust_res) {
    while ($row = $cust_res->fetch_assoc()) {
        // Fetch active loans count for each customer
        $loan_count_sql = "SELECT COUNT(*) as active_loans FROM pawn_entries 
                          WHERE customer_id = {$row['customer_id']} 
                          AND business_id = $business_id 
                          AND status IN ('Active', 'Partially Paid')";
        $loan_res = $conn->query($loan_count_sql);
        if ($loan_res && $loan_res->num_rows > 0) {
            $row['active_loans'] = $loan_res->fetch_assoc()['active_loans'];
        } else {
            $row['active_loans'] = 0;
        }
        $customers[] = $row;
    }
}

// Get unique occupations for filter dropdown
$occupations = [];
$occ_sql = "SELECT DISTINCT occupation FROM pawn_customers WHERE business_id = $business_id AND occupation IS NOT NULL AND occupation != '' ORDER BY occupation";
$occ_res = $conn->query($occ_sql);
if ($occ_res) {
    while ($row = $occ_res->fetch_assoc()) {
        $occupations[] = $row['occupation'];
    }
}

// Get summary stats with filters applied
$summary_sql = "SELECT 
                   COUNT(*) as total_customers,
                   COALESCE(SUM(pc.credit_limit), 0) as total_credit_limit,
                   SUM(CASE WHEN pc.kyc_verified = 1 THEN 1 ELSE 0 END) as kyc_verified_count,
                   SUM(CASE WHEN pc.risk_category = 'Low' THEN 1 ELSE 0 END) as low_risk,
                   SUM(CASE WHEN pc.risk_category = 'Medium' THEN 1 ELSE 0 END) as medium_risk,
                   SUM(CASE WHEN pc.risk_category = 'High' THEN 1 ELSE 0 END) as high_risk
                FROM pawn_customers pc 
                INNER JOIN customers c ON pc.customer_id = c.id 
                WHERE $where_clause";
$summary_res = $conn->query($summary_sql);
$summary = $summary_res ? $summary_res->fetch_assoc() : [];
$total_customers = $summary['total_customers'] ?? 0;
$total_credit_limit = $summary['total_credit_limit'] ?? 0;
$kyc_verified_count = $summary['kyc_verified_count'] ?? 0;
?>
<!doctype html>
<html lang="en">
<?php include('includes/head.php'); ?>
<title>Pawn Customers | Pawn Broking</title>
<style>
    .customer-card { border-radius: 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 24px; }
    .risk-badge { padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 500; display: inline-block; }
    .risk-low { background: #dcfce7; color: #166534; }
    .risk-medium { background: #fed7aa; color: #92400e; }
    .risk-high { background: #fee2e2; color: #991b1b; }
    .verified-badge { color: #059669; }
    .customer-avatar { width: 48px; height: 48px; border-radius: 50%; background: #eef2ff; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 600; color: #4f46e5; }
    .filter-section { background: #f8fafc; padding: 16px; border-radius: 12px; margin-bottom: 20px; }
    .sort-link { cursor: pointer; color: #4b5563; text-decoration: none; }
    .sort-link:hover { color: #4f46e5; }
    .sort-active { color: #4f46e5; font-weight: 600; }
    .clear-filter { text-decoration: none; font-size: 12px; }
    .pagination { margin: 0; }
    .filter-badge { background: #e5e7eb; padding: 4px 10px; border-radius: 20px; font-size: 12px; margin-right: 8px; display: inline-flex; align-items: center; gap: 6px; }
    .filter-badge .remove { cursor: pointer; color: #dc2626; }
    .filter-badge .remove:hover { color: #991b1b; }
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
                                    <h4 class="mb-1">Pawn Customers</h4>
                                    <p class="text-muted mb-0">Manage pawn broking customers and their KYC details</p>
                                </div>
                                <div>
                                    <a href="pawn-customer-add.php" class="btn btn-primary">
                                        <i class="fas fa-plus"></i> Add Pawn Customer
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Stats Cards -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <div class="card customer-card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="text-muted mb-1">Total Customers</h6>
                                            <h3 class="mb-0"><?php echo $total_customers; ?></h3>
                                        </div>
                                        <div class="bg-primary bg-opacity-10 p-3 rounded-circle">
                                            <i class="fas fa-users fa-2x text-primary"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card customer-card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="text-muted mb-1">Total Credit Limit</h6>
                                            <h3 class="mb-0">₹<?php echo money($total_credit_limit); ?></h3>
                                        </div>
                                        <div class="bg-success bg-opacity-10 p-3 rounded-circle">
                                            <i class="fas fa-credit-card fa-2x text-success"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card customer-card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="text-muted mb-1">KYC Verified</h6>
                                            <h3 class="mb-0"><?php echo $kyc_verified_count; ?> / <?php echo $total_customers; ?></h3>
                                        </div>
                                        <div class="bg-info bg-opacity-10 p-3 rounded-circle">
                                            <i class="fas fa-id-card fa-2x text-info"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card customer-card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="text-muted mb-1">Risk Distribution</h6>
                                            <h3 class="mb-0">
                                                <span class="risk-low" style="padding: 2px 6px;">L:<?php echo $summary['low_risk'] ?? 0; ?></span>
                                                <span class="risk-medium" style="padding: 2px 6px;">M:<?php echo $summary['medium_risk'] ?? 0; ?></span>
                                                <span class="risk-high" style="padding: 2px 6px;">H:<?php echo $summary['high_risk'] ?? 0; ?></span>
                                            </h3>
                                        </div>
                                        <div class="bg-warning bg-opacity-10 p-3 rounded-circle">
                                            <i class="fas fa-chart-pie fa-2x text-warning"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Filter Section -->
                    <div class="filter-section">
                        <form method="GET" id="filterForm" class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Search</label>
                                <div class="input-group">
                                    <input type="text" name="search" class="form-control" placeholder="Name, Code, Mobile, Email..." value="<?php echo h($search_term); ?>">
                                    <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i></button>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Risk Category</label>
                                <select name="risk" class="form-select" onchange="this.form.submit()">
                                    <option value="all" <?php echo $risk_filter == 'all' || !$risk_filter ? 'selected' : ''; ?>>All Risks</option>
                                    <option value="Low" <?php echo $risk_filter == 'Low' ? 'selected' : ''; ?>>Low Risk</option>
                                    <option value="Medium" <?php echo $risk_filter == 'Medium' ? 'selected' : ''; ?>>Medium Risk</option>
                                    <option value="High" <?php echo $risk_filter == 'High' ? 'selected' : ''; ?>>High Risk</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">KYC Status</label>
                                <select name="kyc" class="form-select" onchange="this.form.submit()">
                                    <option value="all" <?php echo $kyc_filter == 'all' || !$kyc_filter ? 'selected' : ''; ?>>All</option>
                                    <option value="verified" <?php echo $kyc_filter == 'verified' ? 'selected' : ''; ?>>KYC Verified</option>
                                    <option value="pending" <?php echo $kyc_filter == 'pending' ? 'selected' : ''; ?>>KYC Pending</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Occupation</label>
                                <select name="occupation" class="form-select" onchange="this.form.submit()">
                                    <option value="all" <?php echo $occupation_filter == 'all' || !$occupation_filter ? 'selected' : ''; ?>>All Occupations</option>
                                    <?php foreach ($occupations as $occ): ?>
                                        <option value="<?php echo h($occ); ?>" <?php echo $occupation_filter == $occ ? 'selected' : ''; ?>>
                                            <?php echo h($occ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">&nbsp;</label>
                                <div>
                                    <?php if ($search_term || $risk_filter != 'all' || $kyc_filter != 'all' || $occupation_filter != 'all'): ?>
                                        <a href="pawn-customers.php" class="btn btn-secondary">
                                            <i class="fas fa-times"></i> Clear All Filters
                                        </a>
                                    <?php endif ?>
                                    <button type="button" class="btn btn-outline-info" onclick="exportToExcel()">
                                        <i class="fas fa-file-excel"></i> Export
                                    </button>
                                </div>
                            </div>
                        </form>
                        
                        <!-- Active Filters Display -->
                        <?php if ($search_term || $risk_filter != 'all' || $kyc_filter != 'all' || $occupation_filter != 'all'): ?>
                            <div class="mt-3">
                                <small class="text-muted">Active Filters:</small>
                                <?php if ($search_term): ?>
                                    <span class="filter-badge">
                                        Search: <?php echo h($search_term); ?>
                                        <a href="javascript:void(0)" onclick="removeFilter('search')" class="remove"><i class="fas fa-times-circle"></i></a>
                                    </span>
                                <?php endif; ?>
                                <?php if ($risk_filter != 'all' && $risk_filter): ?>
                                    <span class="filter-badge">
                                        Risk: <?php echo h($risk_filter); ?>
                                        <a href="javascript:void(0)" onclick="removeFilter('risk')" class="remove"><i class="fas fa-times-circle"></i></a>
                                    </span>
                                <?php endif; ?>
                                <?php if ($kyc_filter != 'all' && $kyc_filter): ?>
                                    <span class="filter-badge">
                                        KYC: <?php echo $kyc_filter == 'verified' ? 'Verified' : 'Pending'; ?>
                                        <a href="javascript:void(0)" onclick="removeFilter('kyc')" class="remove"><i class="fas fa-times-circle"></i></a>
                                    </span>
                                <?php endif; ?>
                                <?php if ($occupation_filter != 'all' && $occupation_filter): ?>
                                    <span class="filter-badge">
                                        Occupation: <?php echo h($occupation_filter); ?>
                                        <a href="javascript:void(0)" onclick="removeFilter('occupation')" class="remove"><i class="fas fa-times-circle"></i></a>
                                    </span>
                                <?php endif; ?>
                                <span class="text-muted ms-2">Found <?php echo $total_records; ?> records</span>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Customers Table -->
                    <div class="card customer-card">
                        <div class="card-header bg-transparent">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">Pawn Customers List</h5>
                                <div class="text-muted small">
                                    <i class="fas fa-arrow-up"></i> <i class="fas fa-arrow-down"></i> Click on column headers to sort
                                </div>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>
                                                <a href="javascript:void(0)" class="sort-link <?php echo $sort_by == 'customer_name' ? 'sort-active' : ''; ?>" onclick="sortBy('customer_name')">
                                                    Customer
                                                    <?php if ($sort_by == 'customer_name'): ?>
                                                        <i class="fas fa-sort-<?php echo $sort_order == 'ASC' ? 'up' : 'down'; ?>"></i>
                                                    <?php else: ?>
                                                        <i class="fas fa-sort text-muted"></i>
                                                    <?php endif; ?>
                                                </a>
                                            </th>
                                            <th>
                                                <a href="javascript:void(0)" class="sort-link <?php echo $sort_by == 'mobile' ? 'sort-active' : ''; ?>" onclick="sortBy('mobile')">
                                                    Contact
                                                    <?php if ($sort_by == 'mobile'): ?>
                                                        <i class="fas fa-sort-<?php echo $sort_order == 'ASC' ? 'up' : 'down'; ?>"></i>
                                                    <?php else: ?>
                                                        <i class="fas fa-sort text-muted"></i>
                                                    <?php endif; ?>
                                                </a>
                                            </th>
                                            <th>
                                                <a href="javascript:void(0)" class="sort-link <?php echo $sort_by == 'occupation' ? 'sort-active' : ''; ?>" onclick="sortBy('occupation')">
                                                    Occupation
                                                    <?php if ($sort_by == 'occupation'): ?>
                                                        <i class="fas fa-sort-<?php echo $sort_order == 'ASC' ? 'up' : 'down'; ?>"></i>
                                                    <?php else: ?>
                                                        <i class="fas fa-sort text-muted"></i>
                                                    <?php endif; ?>
                                                </a>
                                            </th>
                                            <th>
                                                <a href="javascript:void(0)" class="sort-link <?php echo $sort_by == 'credit_limit' ? 'sort-active' : ''; ?>" onclick="sortBy('credit_limit')">
                                                    Credit Limit
                                                    <?php if ($sort_by == 'credit_limit'): ?>
                                                        <i class="fas fa-sort-<?php echo $sort_order == 'ASC' ? 'up' : 'down'; ?>"></i>
                                                    <?php else: ?>
                                                        <i class="fas fa-sort text-muted"></i>
                                                    <?php endif; ?>
                                                </a>
                                            </th>
                                            <th>Active Loans</th>
                                            <th>Risk</th>
                                            <th>KYC</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($customers)): ?>
                                            <tr>
                                                <td colspan="8" class="text-center text-muted py-5">
                                                    <i class="fas fa-inbox fa-3x mb-3 d-block text-muted"></i>
                                                    No pawn customers found matching your filters.
                                                    <?php if ($search_term || $risk_filter != 'all' || $kyc_filter != 'all' || $occupation_filter != 'all'): ?>
                                                        <br><a href="pawn-customers.php" class="btn btn-sm btn-primary mt-2">Clear Filters</a>
                                                    <?php else: ?>
                                                        <br><a href="pawn-customer-add.php" class="btn btn-sm btn-primary mt-2">Add your first pawn customer</a>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php else: foreach ($customers as $row): ?>
                                            <tr>
                                                <td>
                                                    <div class="d-flex align-items-center gap-3">
                                                        <div class="customer-avatar">
                                                            <?php echo strtoupper(substr($row['customer_name'], 0, 2)); ?>
                                                        </div>
                                                        <div>
                                                            <strong><?php echo h($row['customer_name']); ?></strong><br>
                                                            <small class="text-muted"><?php echo h($row['customer_code']); ?></small>
                                                        </div>
                                                    </div>
                                                 </td>
                                                <td>
                                                    <?php if ($row['mobile']): ?>
                                                        <i class="fas fa-phone-alt text-muted me-1"></i> <?php echo h($row['mobile']); ?><br>
                                                    <?php endif; ?>
                                                    <?php if ($row['email']): ?>
                                                        <small class="text-muted"><?php echo h($row['email']); ?></small>
                                                    <?php endif; ?>
                                                 </td>
                                                <td><?php echo h($row['occupation'] ?? '-'); ?></td>
                                                <td>
                                                    <strong class="text-primary">₹<?php echo money($row['credit_limit']); ?></strong>
                                                 </td>
                                                <td>
                                                    <span class="badge bg-<?php echo ($row['active_loans'] > 0) ? 'warning' : 'secondary'; ?>">
                                                        <?php echo (int)($row['active_loans'] ?? 0); ?> Active
                                                    </span>
                                                 </td>
                                                <td>
                                                    <span class="risk-badge risk-<?php echo strtolower($row['risk_category'] ?? 'low'); ?>">
                                                        <?php echo h($row['risk_category'] ?? 'Low'); ?>
                                                    </span>
                                                 </td>
                                                <td>
                                                    <?php if ($row['kyc_verified']): ?>
                                                        <i class="fas fa-check-circle verified-badge"></i> Verified
                                                    <?php else: ?>
                                                        <span class="text-muted"><i class="fas fa-clock"></i> Pending</span>
                                                    <?php endif; ?>
                                                 </td>
                                                <td>
                                                    <div class="btn-group">
                                                        <a href="pawn-customer-view.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-info" title="View Details">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                        <a href="pawn-customer-add.php?id=<?php echo $row['customer_id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        <a href="pawn-list.php?customer_id=<?php echo $row['customer_id']; ?>" class="btn btn-sm btn-outline-secondary" title="View Loans">
                                                            <i class="fas fa-hand-holding-usd"></i>
                                                        </a>
                                                    </div>
                                                 </td>
                                             </tr>
                                        <?php endforeach; endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        
                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                            <div class="card-footer bg-transparent">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <small class="text-muted">
                                            Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $per_page, $total_records); ?> of <?php echo $total_records; ?> entries
                                        </small>
                                    </div>
                                    <nav aria-label="Page navigation">
                                        <ul class="pagination pagination-sm mb-0">
                                            <?php if ($page > 1): ?>
                                                <li class="page-item">
                                                    <a class="page-link" href="?page=1&search=<?php echo urlencode($search_term); ?>&risk=<?php echo urlencode($risk_filter); ?>&kyc=<?php echo urlencode($kyc_filter); ?>&occupation=<?php echo urlencode($occupation_filter); ?>&sort_by=<?php echo $sort_by; ?>&sort_order=<?php echo $sort_order; ?>">
                                                        <i class="fas fa-angle-double-left"></i>
                                                    </a>
                                                </li>
                                                <li class="page-item">
                                                    <a class="page-link" href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search_term); ?>&risk=<?php echo urlencode($risk_filter); ?>&kyc=<?php echo urlencode($kyc_filter); ?>&occupation=<?php echo urlencode($occupation_filter); ?>&sort_by=<?php echo $sort_by; ?>&sort_order=<?php echo $sort_order; ?>">
                                                        <i class="fas fa-chevron-left"></i>
                                                    </a>
                                                </li>
                                            <?php endif; ?>
                                            
                                            <?php
                                            $start_page = max(1, $page - 2);
                                            $end_page = min($total_pages, $page + 2);
                                            for ($i = $start_page; $i <= $end_page; $i++):
                                            ?>
                                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                                    <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search_term); ?>&risk=<?php echo urlencode($risk_filter); ?>&kyc=<?php echo urlencode($kyc_filter); ?>&occupation=<?php echo urlencode($occupation_filter); ?>&sort_by=<?php echo $sort_by; ?>&sort_order=<?php echo $sort_order; ?>">
                                                        <?php echo $i; ?>
                                                    </a>
                                                </li>
                                            <?php endfor; ?>
                                            
                                            <?php if ($page < $total_pages): ?>
                                                <li class="page-item">
                                                    <a class="page-link" href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search_term); ?>&risk=<?php echo urlencode($risk_filter); ?>&kyc=<?php echo urlencode($kyc_filter); ?>&occupation=<?php echo urlencode($occupation_filter); ?>&sort_by=<?php echo $sort_by; ?>&sort_order=<?php echo $sort_order; ?>">
                                                        <i class="fas fa-chevron-right"></i>
                                                    </a>
                                                </li>
                                                <li class="page-item">
                                                    <a class="page-link" href="?page=<?php echo $total_pages; ?>&search=<?php echo urlencode($search_term); ?>&risk=<?php echo urlencode($risk_filter); ?>&kyc=<?php echo urlencode($kyc_filter); ?>&occupation=<?php echo urlencode($occupation_filter); ?>&sort_by=<?php echo $sort_by; ?>&sort_order=<?php echo $sort_order; ?>">
                                                        <i class="fas fa-angle-double-right"></i>
                                                    </a>
                                                </li>
                                            <?php endif; ?>
                                        </ul>
                                    </nav>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                </div>
            </div>
            <?php include('includes/footer.php'); ?>
        </div>
    </div>
    
    <?php include('includes/scripts.php'); ?>
    
    <script>
        function sortBy(column) {
            let currentSort = '<?php echo $sort_by; ?>';
            let currentOrder = '<?php echo $sort_order; ?>';
            let newOrder = 'ASC';
            
            if (currentSort === column) {
                newOrder = currentOrder === 'ASC' ? 'DESC' : 'ASC';
            }
            
            window.location.href = `?page=<?php echo $page; ?>&search=<?php echo urlencode($search_term); ?>&risk=<?php echo urlencode($risk_filter); ?>&kyc=<?php echo urlencode($kyc_filter); ?>&occupation=<?php echo urlencode($occupation_filter); ?>&sort_by=${column}&sort_order=${newOrder}`;
        }
        
        function removeFilter(filter) {
            let url = new URL(window.location.href);
            url.searchParams.delete(filter);
            if (filter === 'search') {
                url.searchParams.delete('search');
            }
            url.searchParams.delete('page');
            window.location.href = url.toString();
        }
        
        function exportToExcel() {
            let params = new URLSearchParams(window.location.search);
            params.set('export', 'excel');
            window.location.href = `export_pawn_customers.php?${params.toString()}`;
        }
        
        // Live search with debounce
        let searchTimeout;
        const searchInput = document.querySelector('input[name="search"]');
        if (searchInput) {
            searchInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    document.getElementById('filterForm').submit();
                }
            });
        }
    </script>
</body>
</html>