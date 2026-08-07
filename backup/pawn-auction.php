<?php
session_start();
date_default_timezone_set('Asia/Kolkata');
require_once 'includes/config.php';

if (!isset($conn) || !($conn instanceof mysqli)) { 
    die('Database connection not available.'); 
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn->set_charset('utf8mb4');

// Helper functions
if (!function_exists('h')) { 
    function h($v){ return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); } 
}
if (!function_exists('money')) { 
    function money($v){ return number_format((float)$v, 2); } 
}
if (!function_exists('nextNo')) {
    function nextNo(mysqli $conn, string $table, string $col, string $prefix, int $businessId): string {
        $like = $prefix . date('Ym') . '%';
        $sql = "SELECT `$col` FROM `$table` WHERE `$col` LIKE ? AND business_id=? ORDER BY id DESC LIMIT 1";
        $st = $conn->prepare($sql);
        if (!$st) return $prefix.date('Ym').'0001';
        $st->bind_param('si', $like, $businessId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        $n = 1;
        if ($row && !empty($row[$col])) $n = ((int)substr(preg_replace('/\D/','',$row[$col]), -4)) + 1;
        return $prefix.date('Ym').str_pad((string)$n, 4, '0', STR_PAD_LEFT);
    }
}

// Authentication check
if (!isset($_SESSION['user_id'])) { 
    header('Location: ../login.php'); 
    exit; 
}

$userId = (int)$_SESSION['user_id'];
$businessId = (int)($_SESSION['business_id'] ?? 1);
$currentPage = 'pawn-auction';

// Get pawn ID from URL
$pawn_id = (int)($_GET['id'] ?? $_POST['pawn_id'] ?? 0);
$pawn = null;
$error = '';
$success = '';

if ($pawn_id > 0) {
    // Fetch pawn entry details - including overdue for auction
    $sql = "SELECT pe.*, c.customer_name, c.mobile, c.email, c.address_line1, c.city, c.state
            FROM pawn_entries pe
            LEFT JOIN customers c ON pe.customer_id = c.id
            WHERE pe.id = {$pawn_id} AND pe.business_id = {$businessId} 
            AND pe.status IN ('Active', 'Partially Paid', 'Overdue')";
    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) {
        $pawn = $result->fetch_assoc();
        
        // Check if eligible for auction (overdue by more than 30 days)
        $maturity_date = new DateTime($pawn['maturity_date']);
        $today = new DateTime();
        $days_overdue = $today->diff($maturity_date)->days;
        
        if ($maturity_date > $today) {
            $error = "This pawn is not overdue yet. Auction can only be done after maturity date.";
            $pawn = null;
        } elseif ($days_overdue < 30) {
            $warning_days = 30 - $days_overdue;
            $error = "Warning: This pawn is overdue by {$days_overdue} days. Auction recommended after 30 days overdue. ({$warning_days} days remaining)";
        }
    } else {
        $error = "Pawn entry not found or already auctioned/released.";
    }
}

// Fetch payment methods
$paymentMethods = [];
$pm_sql = "SELECT id, method_name FROM payment_methods WHERE is_active = 1 ORDER BY method_name";
$pm_res = $conn->query($pm_sql);
if ($pm_res) {
    while ($row = $pm_res->fetch_assoc()) {
        $paymentMethods[] = $row;
    }
}

// Get pawn items for valuation
$pawn_items = [];
$total_gross_weight = 0;
$total_net_weight = 0;
if ($pawn_id > 0) {
    $items_sql = "SELECT * FROM pawn_items WHERE pawn_id = {$pawn_id} AND business_id = {$businessId}";
    $items_res = $conn->query($items_sql);
    if ($items_res) {
        while ($row = $items_res->fetch_assoc()) {
            $pawn_items[] = $row;
            $total_gross_weight += $row['gross_weight'];
            $total_net_weight += $row['net_weight'];
        }
    }
}

// Get current metal rates for valuation
$metal_rates = [];
$rate_sql = "SELECT metal_type, purity, rate_per_gram FROM metal_rates 
             WHERE business_id = {$businessId} AND effective_date <= CURDATE() 
             ORDER BY effective_date DESC";
$rate_res = $conn->query($rate_sql);
if ($rate_res) {
    while ($row = $rate_res->fetch_assoc()) {
        $metal_rates[$row['metal_type']][$row['purity']] = $row['rate_per_gram'];
    }
}

// Calculate estimated auction value
$estimated_auction_value = 0;
if ($pawn && $total_net_weight > 0) {
    // Default to 70% of current market value
    $current_market_rate = 5500; // Default gold rate
    if ($pawn['metal_type'] == 'Gold' && isset($metal_rates['Gold']['24K'])) {
        $current_market_rate = $metal_rates['Gold']['24K'];
    } elseif ($pawn['metal_type'] == 'Silver' && isset($metal_rates['Silver']['999'])) {
        $current_market_rate = $metal_rates['Silver']['999'];
    }
    $estimated_auction_value = $total_net_weight * $current_market_rate * 0.70;
}

// Get payment history
$payment_history = [];
$total_paid = 0;
$total_interest = 0;
if ($pawn_id > 0) {
    $history_sql = "SELECT * FROM pawn_payments WHERE pawn_id = {$pawn_id} AND business_id = {$businessId} ORDER BY payment_date DESC";
    $history_res = $conn->query($history_sql);
    if ($history_res) {
        while ($row = $history_res->fetch_assoc()) {
            $payment_history[] = $row;
            $total_paid += $row['principal_amount'];
            $total_interest += $row['interest_amount'];
        }
    }
}

// Get interest collections
$interest_collections = [];
$total_interest_collected = 0;
if ($pawn_id > 0) {
    $interest_sql = "SELECT * FROM pawn_interest_collections WHERE pawn_id = {$pawn_id} AND business_id = {$businessId} ORDER BY collection_date DESC";
    $interest_res = $conn->query($interest_sql);
    if ($interest_res) {
        while ($row = $interest_res->fetch_assoc()) {
            $interest_collections[] = $row;
            $total_interest_collected += $row['interest_amount'];
        }
    }
}

// Calculate total due
$total_due = ($pawn['loan_amount'] ?? 0) - $total_paid;
$total_interest_due = ($pawn['interest_rate'] ?? 0) > 0 ? ($total_due * $pawn['interest_rate'] / 100) : 0;

// Process auction form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_auction'])) {
    $pawn_id = (int)$_POST['pawn_id'];
    $auction_date = $_POST['auction_date'] ?? date('Y-m-d');
    $auction_amount = (float)($_POST['auction_amount'] ?? 0);
    $expenses_amount = (float)($_POST['expenses_amount'] ?? 0);
    $net_amount = (float)($_POST['net_amount'] ?? 0);
    $surplus_amount = (float)($_POST['surplus_amount'] ?? 0);
    $auction_remarks = trim($_POST['auction_remarks'] ?? '');
    $buyer_name = trim($_POST['buyer_name'] ?? '');
    $buyer_contact = trim($_POST['buyer_contact'] ?? '');
    
    $errors = [];
    
    if ($auction_amount <= 0) {
        $errors[] = "Please enter a valid auction amount";
    }
    
    if (empty($errors)) {
        $conn->begin_transaction();
        
        try {
            // Generate auction number
            $auction_no = nextNo($conn, 'pawn_auctions', 'auction_no', 'AUC', $businessId);
            
            // Insert auction record
            $insert_sql = "INSERT INTO pawn_auctions (
                business_id, pawn_id, auction_no, auction_date, auction_amount,
                expenses_amount, net_amount, surplus_amount, remarks, created_by, created_at
            ) VALUES (
                {$businessId}, {$pawn_id}, '{$auction_no}', '{$auction_date}', {$auction_amount},
                {$expenses_amount}, {$net_amount}, {$surplus_amount}, 
                '" . $conn->real_escape_string($auction_remarks) . "', {$userId}, NOW()
            )";
            
            if (!$conn->query($insert_sql)) {
                throw new Exception("Failed to save auction record: " . $conn->error);
            }
            
            // Update pawn entry status
            $update_sql = "UPDATE pawn_entries SET 
                          status = 'Auctioned',
                          auctioned_at = NOW(),
                          updated_at = NOW(),
                          remarks = CONCAT(IFNULL(remarks, ''), '\nAuctioned on: {$auction_date}. Amount: ₹{$auction_amount}. ', '{$auction_remarks}')
                          WHERE id = {$pawn_id}";
            
            if (!$conn->query($update_sql)) {
                throw new Exception("Failed to update pawn status: " . $conn->error);
            }
            
            // If there's surplus, create a credit note or record for customer
            if ($surplus_amount > 0) {
                $surplus_sql = "INSERT INTO customer_payments (
                    business_id, customer_id, receipt_no, receipt_date, amount, 
                    notes, created_by, created_at
                ) VALUES (
                    {$businessId}, {$pawn['customer_id']}, 'SURPLUS_{$auction_no}', '{$auction_date}', 
                    {$surplus_amount}, 'Surplus from auction of pawn {$pawn['pawn_no']}', {$userId}, NOW()
                )";
                // Note: This is optional - execute if needed
                // $conn->query($surplus_sql);
            }
            
            $conn->commit();
            $success = "Pawn auctioned successfully! Auction No: {$auction_no}";
            
            // Redirect after 2 seconds
            echo '<script>setTimeout(function(){ window.location.href = "pawn-view.php?id=' . $pawn_id . '&auctioned=1"; }, 2000);</script>';
            
        } catch (Exception $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    } else {
        $error = implode("<br>", $errors);
    }
}

// Get all overdue pawns for listing
$overdue_pawns = [];
$overdue_query = "SELECT pe.id, pe.pawn_no, pe.customer_name, pe.customer_mobile, 
                         pe.principal_balance, pe.loan_amount, pe.entry_date, pe.maturity_date,
                         DATEDIFF(CURDATE(), pe.maturity_date) as days_overdue
                  FROM pawn_entries pe
                  WHERE pe.business_id = {$businessId} 
                  AND pe.status IN ('Active', 'Partially Paid')
                  AND pe.maturity_date < CURDATE()
                  ORDER BY pe.maturity_date ASC";
$overdue_res = $conn->query($overdue_query);
if ($overdue_res) {
    while ($row = $overdue_res->fetch_assoc()) {
        $overdue_pawns[] = $row;
    }
}
?>
<!doctype html>
<html lang="en">
<?php include('includes/head.php'); ?>
<title>Pawn Auction | Pawn Broking</title>
<style>
    .auction-card { border-radius: 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 24px; }
    .auction-card .card-header { background: #f8fafc; border-bottom: 1px solid #eef2f6; padding: 16px 20px; font-weight: 600; }
    .auction-card .card-body { padding: 20px; }
    .info-row { display: flex; padding: 8px 0; border-bottom: 1px solid #f0f0f0; }
    .info-label { width: 160px; font-weight: 600; color: #4b5563; }
    .info-value { flex: 1; color: #1f2937; }
    .summary-box { background: #f8fafc; border-radius: 12px; padding: 15px; text-align: center; }
    .summary-value { font-size: 22px; font-weight: 700; }
    .summary-label { font-size: 12px; color: #6c757d; margin-top: 5px; }
    .auction-box { background: #fef3c7; border: 2px solid #f59e0b; border-radius: 12px; padding: 20px; text-align: center; }
    .auction-amount { font-size: 32px; font-weight: 800; color: #d97706; }
    .status-badge { padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 500; display: inline-block; }
    .status-overdue { background: #fee2e2; color: #991b1b; }
    .search-result-item { cursor: pointer; padding: 10px; border-bottom: 1px solid #e5e7eb; transition: background 0.2s; }
    .search-result-item:hover { background: #f3f4f6; }
    .overdue-warning { background: #fff3cd; border-left: 4px solid #ffc107; }
    .item-table th, .item-table td { padding: 8px; vertical-align: middle; }
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
                                    <h4 class="mb-1">Pawn Auction / Forfeit</h4>
                                    <p class="text-muted mb-0">Auction pawned items for overdue loans</p>
                                </div>
                                <div>
                                    <a href="pawn-list.php" class="btn btn-outline-secondary">
                                        <i class="fas fa-arrow-left"></i> Back to Pawn List
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <?php if ($success): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="fas fa-check-circle me-2"></i> <?php echo h($success); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="fas fa-exclamation-circle me-2"></i> <?php echo $error; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Overdue Pawns List Section -->
                    <div class="card auction-card">
                        <div class="card-header">
                            <i class="fas fa-clock me-2"></i> Overdue Pawns (Eligible for Auction)
                            <span class="badge bg-warning ms-2"><?php echo count($overdue_pawns); ?> Overdue</span>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Pawn No</th>
                                            <th>Customer</th>
                                            <th>Loan Amount</th>
                                            <th>Outstanding</th>
                                            <th>Maturity Date</th>
                                            <th>Days Overdue</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($overdue_pawns)): ?>
                                            <tr><td colspan="7" class="text-center text-muted py-4">
                                                No overdue pawns found for auction.
                                            </td></tr>
                                        <?php else: foreach ($overdue_pawns as $op): ?>
                                            <tr class="overdue-warning">
                                                <td><strong><?php echo h($op['pawn_no']); ?></strong></td>
                                                <td><?php echo h($op['customer_name']); ?><br><small><?php echo h($op['customer_mobile']); ?></small></td>
                                                <td>₹<?php echo money($op['loan_amount']); ?></td>
                                                <td><strong class="text-danger">₹<?php echo money($op['principal_balance']); ?></strong></td>
                                                <td><?php echo date('d-m-Y', strtotime($op['maturity_date'])); ?></td>
                                                <td>
                                                    <span class="badge bg-danger"><?php echo (int)$op['days_overdue']; ?> days</span>
                                                </td>
                                                <td>
                                                    <a href="pawn-auction.php?id=<?php echo $op['id']; ?>" class="btn btn-sm btn-warning">
                                                        <i class="fas fa-gavel"></i> Auction
                                                    </a>
                                                 </td>
                                             </tr>
                                        <?php endforeach; endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <?php if ($pawn): ?>
                    <!-- Selected Pawn Details -->
                    <div class="card auction-card">
                        <div class="card-header">
                            <i class="fas fa-info-circle me-2"></i> Pawn Information for Auction
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="info-row">
                                        <div class="info-label">Pawn Number:</div>
                                        <div class="info-value"><strong><?php echo h($pawn['pawn_no']); ?></strong></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Customer Name:</div>
                                        <div class="info-value"><strong><?php echo h($pawn['customer_name']); ?></strong></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Mobile:</div>
                                        <div class="info-value"><?php echo h($pawn['mobile'] ?? $pawn['customer_mobile']); ?></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Address:</div>
                                        <div class="info-value"><?php echo h($pawn['address_line1'] ?? ''); ?></div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="info-row">
                                        <div class="info-label">Original Loan:</div>
                                        <div class="info-value">₹<?php echo money($pawn['loan_amount']); ?></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Outstanding Principal:</div>
                                        <div class="info-value"><strong class="text-danger">₹<?php echo money($pawn['principal_balance']); ?></strong></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Maturity Date:</div>
                                        <div class="info-value">
                                            <?php echo date('d-m-Y', strtotime($pawn['maturity_date'])); ?>
                                            <span class="status-badge status-overdue ms-2">Overdue</span>
                                        </div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Metal Type:</div>
                                        <div class="info-value"><?php echo h($pawn['metal_type']); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Pawn Items -->
                    <?php if (!empty($pawn_items)): ?>
                    <div class="card auction-card">
                        <div class="card-header">
                            <i class="fas fa-gem me-2"></i> Pawned Items
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0 item-table">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Item Name</th>
                                            <th>Category</th>
                                            <th>Purity</th>
                                            <th>Gross Wt (g)</th>
                                            <th>Net Wt (g)</th>
                                            <th>Pawn Value</th>
                                            <th>Est. Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pawn_items as $item): ?>
                                        <tr>
                                            <td><?php echo h($item['item_name']); ?></td>
                                            <td><?php echo h($item['item_category']); ?></td>
                                            <td><?php echo h($item['purity']); ?></td>
                                            <td><?php echo number_format($item['gross_weight'], 3); ?></td>
                                            <td><strong><?php echo number_format($item['net_weight'], 3); ?></strong></td>
                                            <td>₹<?php echo money($item['rate_per_gram']); ?>/g</td>
                                            <td>₹<?php echo money($item['estimated_amount']); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr>
                                            <td colspan="3" class="text-end"><strong>Totals:</strong></td>
                                            <td><strong><?php echo number_format($total_gross_weight, 3); ?> g</strong></td>
                                            <td><strong><?php echo number_format($total_net_weight, 3); ?> g</strong></td>
                                            <td colspan="2"></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Auction Form -->
                    <div class="card auction-card">
                        <div class="card-header">
                            <i class="fas fa-gavel me-2"></i> Auction Details
                        </div>
                        <div class="card-body">
                            <form method="POST" id="auctionForm" onsubmit="return validateAuctionForm()">
                                <input type="hidden" name="submit_auction" value="1">
                                <input type="hidden" name="pawn_id" value="<?php echo $pawn_id; ?>">
                                
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label required">Auction Date</label>
                                        <input type="date" name="auction_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Buyer Name</label>
                                        <input type="text" name="buyer_name" class="form-control" placeholder="Buyer information">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Buyer Contact</label>
                                        <input type="text" name="buyer_contact" class="form-control" placeholder="Buyer mobile number">
                                    </div>
                                </div>
                                
                                <div class="row g-3 mt-2">
                                    <div class="col-md-4">
                                        <label class="form-label required">Auction Amount (₹)</label>
                                        <input type="number" step="0.01" name="auction_amount" id="auction_amount" class="form-control" 
                                               value="<?php echo money($estimated_auction_value); ?>" required onchange="calculateAuction()">
                                        <small class="text-muted">Estimated value: ₹<?php echo money($estimated_auction_value); ?></small>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Expenses (₹)</label>
                                        <input type="number" step="0.01" name="expenses_amount" id="expenses_amount" class="form-control" value="0" onchange="calculateAuction()">
                                        <small class="text-muted">Auction fees, transport, etc.</small>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Net Amount (₹)</label>
                                        <input type="number" step="0.01" name="net_amount" id="net_amount" class="form-control" readonly style="background:#f0f0f0;">
                                    </div>
                                </div>
                                
                                <div class="row g-3 mt-3">
                                    <div class="col-md-12">
                                        <div class="auction-box">
                                            <div class="row align-items-center">
                                                <div class="col-md-6">
                                                    <div class="summary-label">Total Due to Customer</div>
                                                    <div class="auction-amount" id="surplus_display">₹0.00</div>
                                                    <input type="hidden" name="surplus_amount" id="surplus_amount" value="0">
                                                    <small>Surplus after deducting loan + expenses</small>
                                                </div>
                                                <div class="col-md-6 text-end">
                                                    <div class="summary-label">Loan Outstanding</div>
                                                    <div class="summary-value text-danger">₹<?php echo money($pawn['principal_balance']); ?></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row g-3 mt-2">
                                    <div class="col-md-12">
                                        <label class="form-label">Auction Remarks</label>
                                        <textarea name="auction_remarks" class="form-control" rows="2" placeholder="Any notes about the auction..."></textarea>
                                    </div>
                                </div>
                                
                                <div class="row mt-4">
                                    <div class="col-12 text-end">
                                        <button type="button" class="btn btn-secondary me-2" onclick="window.location.href='pawn-view.php?id=<?php echo $pawn_id; ?>'">
                                            <i class="fas fa-times"></i> Cancel
                                        </button>
                                        <button type="submit" class="btn btn-warning btn-lg">
                                            <i class="fas fa-gavel"></i> Confirm Auction
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Payment & Interest History -->
                    <div class="card auction-card">
                        <div class="card-header">
                            <i class="fas fa-history me-2"></i> Payment History
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Date</th>
                                            <th>Type</th>
                                            <th>Receipt No</th>
                                            <th>Principal</th>
                                            <th>Interest</th>
                                            <th>Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($payment_history) && empty($interest_collections)): ?>
                                            <tr><td colspan="6" class="text-center text-muted py-4">No payment history found</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($payment_history as $payment): ?>
                                            <tr>
                                                <td><?php echo date('d-m-Y', strtotime($payment['payment_date'])); ?></td>
                                                <td><?php echo h($payment['payment_type']); ?></td>
                                                <td><?php echo h($payment['receipt_no']); ?></td>
                                                <td>₹<?php echo money($payment['principal_amount']); ?></td>
                                                <td>₹<?php echo money($payment['interest_amount']); ?></td>
                                                <td><strong>₹<?php echo money($payment['total_amount']); ?></strong></td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <?php foreach ($interest_collections as $interest): ?>
                                            <tr>
                                                <td><?php echo date('d-m-Y', strtotime($interest['collection_date'])); ?></td>
                                                <td>Interest Collection</td>
                                                <td><?php echo h($interest['receipt_no']); ?></td>
                                                <td>-</td>
                                                <td>₹<?php echo money($interest['interest_amount']); ?></td>
                                                <td><strong>₹<?php echo money($interest['net_amount']); ?></strong></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr>
                                            <td colspan="3" class="text-end"><strong>Totals:</strong></td>
                                            <td><strong>₹<?php echo money($total_paid); ?></strong></td>
                                            <td><strong>₹<?php echo money($total_interest_collected); ?></strong></td>
                                            <td></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <?php endif; ?>
                    
                </div>
            </div>
            <?php include('includes/footer.php'); ?>
        </div>
    </div>
    
    <?php include('includes/scripts.php'); ?>
    
    <script>
        let outstandingBalance = <?php echo $pawn['principal_balance'] ?? 0; ?>;
        
        function calculateAuction() {
            const auctionAmount = parseFloat(document.getElementById('auction_amount').value) || 0;
            const expenses = parseFloat(document.getElementById('expenses_amount').value) || 0;
            
            const netAmount = auctionAmount - expenses;
            document.getElementById('net_amount').value = netAmount.toFixed(2);
            
            const surplus = netAmount - outstandingBalance;
            const surplusDisplay = surplus > 0 ? surplus : 0;
            
            document.getElementById('surplus_amount').value = surplusDisplay.toFixed(2);
            document.getElementById('surplus_display').innerHTML = '₹' + surplusDisplay.toFixed(2);
            
            // Color coding based on surplus/deficit
            const surplusElement = document.getElementById('surplus_display');
            if (surplus < 0) {
                surplusElement.style.color = '#dc2626';
                surplusElement.parentElement.parentElement.style.background = '#fee2e2';
            } else {
                surplusElement.style.color = '#059669';
                surplusElement.parentElement.parentElement.style.background = '#f0fdf4';
            }
        }
        
        function validateAuctionForm() {
            const auctionAmount = parseFloat(document.getElementById('auction_amount').value) || 0;
            
            if (auctionAmount <= 0) {
                alert('Please enter a valid auction amount');
                return false;
            }
            
            const netAmount = parseFloat(document.getElementById('net_amount').value) || 0;
            const surplus = netAmount - outstandingBalance;
            
            let confirmMsg = '⚠️ CONFIRM AUCTION\n\n' +
                'Are you sure you want to auction this pawn?\n\n' +
                'Pawn Number: <?php echo h($pawn['pawn_no'] ?? ''); ?>\n' +
                'Customer: <?php echo h($pawn['customer_name'] ?? ''); ?>\n' +
                'Auction Amount: ₹' + auctionAmount.toFixed(2) + '\n' +
                'Expenses: ₹' + (parseFloat(document.getElementById('expenses_amount').value) || 0).toFixed(2) + '\n' +
                'Net Amount: ₹' + netAmount.toFixed(2) + '\n' +
                'Outstanding Loan: ₹' + outstandingBalance.toFixed(2) + '\n';
            
            if (surplus > 0) {
                confirmMsg += '\n✅ Surplus Amount to be paid to customer: ₹' + surplus.toFixed(2) + '\n';
            } else if (surplus < 0) {
                confirmMsg += '\n⚠️ Deficit: ₹' + Math.abs(surplus).toFixed(2) + ' (Loss to be written off)\n';
            }
            
            confirmMsg += '\nThis action cannot be undone once confirmed!';
            
            return confirm(confirmMsg);
        }
        
        // Initialize calculation
        calculateAuction();
    </script>
</body>
</html>