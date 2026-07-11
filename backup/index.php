<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/includes/config.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not available. Check includes/config.php');
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn->set_charset('utf8mb4');

$pageTitle = 'Dashboard';
$page_title = 'Dashboard';
$currentPage = 'dashboard';

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('money')) {
    function money($amount): string
    {
        return number_format((float)$amount, 2);
    }
}

if (!function_exists('tableExists')) {
    function tableExists(mysqli $conn, string $tableName): bool
    {
        $safe = $conn->real_escape_string($tableName);
        $res = $conn->query("SHOW TABLES LIKE '{$safe}'");
        return $res && $res->num_rows > 0;
    }
}

if (!function_exists('getCount')) {
    function getCount(mysqli $conn, string $table, string $where = '1=1'): int
    {
        if (!tableExists($conn, $table)) {
            return 0;
        }
        $sql = "SELECT COUNT(*) AS cnt FROM `{$table}` WHERE {$where}";
        $res = $conn->query($sql);
        if ($res && ($row = $res->fetch_assoc())) {
            return (int)($row['cnt'] ?? 0);
        }
        return 0;
    }
}

if (!function_exists('getSum')) {
    function getSum(mysqli $conn, string $table, string $column, string $where = '1=1'): float
    {
        if (!tableExists($conn, $table)) {
            return 0.00;
        }
        $sql = "SELECT COALESCE(SUM(`{$column}`),0) AS total FROM `{$table}` WHERE {$where}";
        $res = $conn->query($sql);
        if ($res && ($row = $res->fetch_assoc())) {
            return (float)($row['total'] ?? 0);
        }
        return 0.00;
    }
}

$businessId = (int)($_SESSION['business_id'] ?? 1);
$userName = $_SESSION['user_name'] ?? $_SESSION['name'] ?? 'Admin';
$roleName = $_SESSION['role_name'] ?? $_SESSION['user_role'] ?? 'Admin';

if (!function_exists('currentRoleName')) {
    function currentRoleName(): string
    {
        return $_SESSION['role_name'] ?? $_SESSION['user_role'] ?? 'Admin';
    }
}

$businessWhere = '1=1';
if ($businessId > 0) {
    $businessWhere = 'business_id=' . $businessId;
}

$totalCustomers = getCount($conn, 'customers', $businessWhere . " AND is_active=1");
$totalProducts = getCount($conn, 'products', $businessWhere . " AND is_active=1");
$activePawns = getCount($conn, 'pawn_entries', $businessWhere . " AND status='Active'");
$activeChits = getCount($conn, 'chit_groups', $businessWhere . " AND status='Active'");

$today = date('Y-m-d');
$todaySales = getSum($conn, 'sales', 'grand_total', $businessWhere . " AND sale_date='{$today}'");
$todayPurchases = getSum($conn, 'purchases', 'grand_total', $businessWhere . " AND purchase_date='{$today}'");
$pawnOutstanding = getSum($conn, 'pawn_entries', 'principal_balance', $businessWhere . " AND status IN ('Active','Partially Paid')");
$chitCollections = getSum($conn, 'chit_collections', 'net_amount', $businessWhere . " AND collection_date='{$today}'");

$recentPawns = [];
if (tableExists($conn, 'pawn_entries')) {
    $res = $conn->query("SELECT pawn_no, entry_date, customer_name, customer_mobile, loan_amount, principal_balance, status FROM pawn_entries WHERE {$businessWhere} ORDER BY id DESC LIMIT 6");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $recentPawns[] = $row;
        }
    }
}

$recentChits = [];
if (tableExists($conn, 'chit_groups')) {
    $res = $conn->query("SELECT group_no, group_name, chit_type, installment_amount, total_members, status FROM chit_groups WHERE {$businessWhere} ORDER BY id DESC LIMIT 6");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $recentChits[] = $row;
        }
    }
}
?>
<!doctype html>
<html lang="en">
<?php include('includes/head.php'); ?>

<style>
    html, body { min-height: 100%; }
    body[data-sidebar="dark"] { overflow-x: hidden; }
    .vertical-menu { height: 100vh !important; position: fixed; top: 0; bottom: 0; z-index: 1002; }
    .vertical-menu .h-100 { height: 100vh !important; overflow-y: auto !important; overflow-x: hidden !important; }
    #sidebar-menu { padding-bottom: 90px; }
    #sidebar-menu .metismenu { margin-bottom: 80px; }
    .main-content { min-height: 100vh; }
    .dashboard-card .card-body { padding: 18px; }
    .dashboard-card .icon-box { width: 44px; height: 44px; border-radius: 12px; display:flex; align-items:center; justify-content:center; font-size:18px; }
    .metric-label { color:#6c757d; font-size:13px; margin-bottom:4px; }
    .metric-value { font-size:24px; font-weight:800; margin:0; color:#111827; }
    .mini-text { font-size:12px; color:#6c757d; }
    .quick-link { display:flex; align-items:center; gap:10px; padding:12px 14px; border:1px solid #eef0f4; border-radius:10px; color:#111827; background:#fff; margin-bottom:10px; }
    .quick-link:hover { background:#f8fbff; color:#0d6efd; }
    .quick-link i { width:20px; text-align:center; }
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

                <div class="row mb-3">
                    <div class="col-12">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div>
                                <h4 class="mb-1">Dashboard</h4>
                                <div class="text-muted">Welcome, <?php echo h($userName); ?> / <?php echo h($roleName); ?></div>
                            </div>
                            <div class="text-muted small">
                                <?php echo date('d-m-Y h:i A'); ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6 col-xl-3">
                        <div class="card dashboard-card">
                            <div class="card-body d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="metric-label">Today Sales</div>
                                    <h3 class="metric-value">₹<?php echo money($todaySales); ?></h3>
                                    <div class="mini-text">Billing total</div>
                                </div>
                                <div class="icon-box bg-success bg-opacity-10 text-success"><i class="fas fa-file-invoice-dollar"></i></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card dashboard-card">
                            <div class="card-body d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="metric-label">Today Purchases</div>
                                    <h3 class="metric-value">₹<?php echo money($todayPurchases); ?></h3>
                                    <div class="mini-text">Purchase total</div>
                                </div>
                                <div class="icon-box bg-primary bg-opacity-10 text-primary"><i class="fas fa-shopping-bag"></i></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card dashboard-card">
                            <div class="card-body d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="metric-label">Pawn Outstanding</div>
                                    <h3 class="metric-value">₹<?php echo money($pawnOutstanding); ?></h3>
                                    <div class="mini-text"><?php echo (int)$activePawns; ?> active loans</div>
                                </div>
                                <div class="icon-box bg-warning bg-opacity-10 text-warning"><i class="fas fa-hand-holding-usd"></i></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card dashboard-card">
                            <div class="card-body d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="metric-label">Chit Collection</div>
                                    <h3 class="metric-value">₹<?php echo money($chitCollections); ?></h3>
                                    <div class="mini-text"><?php echo (int)$activeChits; ?> active groups</div>
                                </div>
                                <div class="icon-box bg-info bg-opacity-10 text-info"><i class="fas fa-coins"></i></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6 col-xl-3">
                        <div class="card dashboard-card">
                            <div class="card-body d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="metric-label">Customers</div>
                                    <h3 class="metric-value"><?php echo (int)$totalCustomers; ?></h3>
                                </div>
                                <div class="icon-box bg-secondary bg-opacity-10 text-secondary"><i class="fas fa-users"></i></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="card dashboard-card">
                            <div class="card-body d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="metric-label">Products</div>
                                    <h3 class="metric-value"><?php echo (int)$totalProducts; ?></h3>
                                </div>
                                <div class="icon-box bg-danger bg-opacity-10 text-danger"><i class="fas fa-boxes"></i></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <a href="pawn-entry.php" class="quick-link h-100 mb-0"><i class="fas fa-plus-circle text-primary"></i><span>New Pawn Entry</span></a>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <a href="chit-create.php" class="quick-link h-100 mb-0"><i class="fas fa-coins text-warning"></i><span>Create Chit</span></a>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-xl-7">
                        <div class="card h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h5 class="mb-0">Recent Pawn Entries</h5>
                                    <a href="pawn-list.php" class="btn btn-sm btn-outline-primary">View All</a>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover align-middle mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Pawn No</th>
                                                <th>Date</th>
                                                <th>Customer</th>
                                                <th class="text-end">Loan</th>
                                                <th class="text-end">Balance</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php if (empty($recentPawns)): ?>
                                            <tr><td colspan="6" class="text-center text-muted py-4">No pawn entries found</td></tr>
                                        <?php else: foreach ($recentPawns as $row): ?>
                                            <tr>
                                                <td><strong><?php echo h($row['pawn_no']); ?></strong></td>
                                                <td><?php echo !empty($row['entry_date']) ? h(date('d-m-Y', strtotime($row['entry_date']))) : '-'; ?></td>
                                                <td>
                                                    <?php echo h($row['customer_name']); ?><br>
                                                    <small class="text-muted"><?php echo h($row['customer_mobile']); ?></small>
                                                </td>
                                                <td class="text-end">₹<?php echo money($row['loan_amount']); ?></td>
                                                <td class="text-end">₹<?php echo money($row['principal_balance']); ?></td>
                                                <td><span class="badge bg-success"><?php echo h($row['status']); ?></span></td>
                                            </tr>
                                        <?php endforeach; endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-5">
                        <div class="card h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h5 class="mb-0">Recent Chit Groups</h5>
                                    <a href="chit-groups.php" class="btn btn-sm btn-outline-primary">View All</a>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover align-middle mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Group</th>
                                                <th>Type</th>
                                                <th class="text-end">Amount</th>
                                                <th>Members</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php if (empty($recentChits)): ?>
                                            <tr><td colspan="5" class="text-center text-muted py-4">No chit groups found</td></tr>
                                        <?php else: foreach ($recentChits as $row): ?>
                                            <tr>
                                                <td><strong><?php echo h($row['group_no']); ?></strong><br><small><?php echo h($row['group_name']); ?></small></td>
                                                <td><?php echo h($row['chit_type']); ?></td>
                                                <td class="text-end">₹<?php echo money($row['installment_amount']); ?></td>
                                                <td><?php echo (int)$row['total_members']; ?></td>
                                                <td><span class="badge bg-info"><?php echo h($row['status']); ?></span></td>
                                            </tr>
                                        <?php endforeach; endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <?php include('includes/footer.php'); ?>
    </div>
</div>

<?php include('includes/rightbar.php'); ?>
<?php include('includes/scripts.php'); ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.body.setAttribute('data-sidebar', 'dark');

    var sidebarScroll = document.querySelector('.vertical-menu [data-simplebar]');
    if (sidebarScroll) {
        sidebarScroll.style.height = '100vh';
        sidebarScroll.style.overflowY = 'auto';
        sidebarScroll.style.overflowX = 'hidden';
    }
});
</script>

</body>
</html>
