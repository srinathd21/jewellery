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

// Get pawn ID from URL
$pawn_id = (int)($_GET['id'] ?? 0);
if ($pawn_id <= 0) {
    header('Location: pawn-list.php');
    exit;
}

// Fetch pawn entry details
$pawn = null;
$sql = "SELECT pe.*, c.customer_name as billing_customer_name, c.mobile as billing_mobile, 
               c.email, c.address_line1, c.city
        FROM pawn_entries pe
        LEFT JOIN customers c ON pe.customer_id = c.id
        WHERE pe.id = $pawn_id AND pe.business_id = $business_id";
$result = $conn->query($sql);
if ($result && $result->num_rows > 0) {
    $pawn = $result->fetch_assoc();
} else {
    header('Location: pawn-list.php?error=notfound');
    exit;
}

// Fetch pawn items
$items = [];
$items_sql = "SELECT * FROM pawn_items WHERE pawn_id = $pawn_id AND business_id = $business_id";
$items_res = $conn->query($items_sql);
if ($items_res) {
    while ($row = $items_res->fetch_assoc()) {
        $items[] = $row;
    }
}

// Fetch payment history
$payments = [];
$payment_sql = "SELECT * FROM pawn_payments WHERE pawn_id = $pawn_id AND business_id = $business_id ORDER BY payment_date DESC";
$payment_res = $conn->query($payment_sql);
if ($payment_res) {
    while ($row = $payment_res->fetch_assoc()) {
        $payments[] = $row;
    }
}

// Fetch interest collections
$interests = [];
$interest_sql = "SELECT * FROM pawn_interest_collections WHERE pawn_id = $pawn_id AND business_id = $business_id ORDER BY collection_date DESC";
$interest_res = $conn->query($interest_sql);
if ($interest_res) {
    while ($row = $interest_res->fetch_assoc()) {
        $interests[] = $row;
    }
}

// Calculate total payments
$total_principal_paid = 0;
$total_interest_paid = 0;
foreach ($payments as $payment) {
    $total_principal_paid += $payment['principal_amount'];
    $total_interest_paid += $payment['interest_amount'];
}

// Calculate total interest collections
$total_interest_collected = 0;
foreach ($interests as $interest) {
    $total_interest_collected += $interest['interest_amount'];
}

// Calculate remaining balance
$remaining_balance = $pawn['principal_balance'];

// Check if loan is overdue
$is_overdue = false;
if (!empty($pawn['maturity_date']) && strtotime($pawn['maturity_date']) < time() && $pawn['status'] == 'Active') {
    $is_overdue = true;
}

// Calculate days overdue
$days_overdue = 0;
if ($is_overdue && !empty($pawn['maturity_date'])) {
    $maturity = new DateTime($pawn['maturity_date']);
    $today = new DateTime();
    $days_overdue = $today->diff($maturity)->days;
}
?>
<!doctype html>
<html lang="en">
<?php include('includes/head.php'); ?>
<title>Pawn Details | <?php echo h($pawn['pawn_no']); ?></title>
<style>
    .detail-card { border-radius: 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 24px; }
    .detail-card .card-header { background: #f8fafc; border-bottom: 1px solid #eef2f6; padding: 16px 20px; font-weight: 600; }
    .detail-card .card-body { padding: 20px; }
    .info-row { display: flex; padding: 10px 0; border-bottom: 1px solid #f0f0f0; }
    .info-label { width: 160px; font-weight: 600; color: #4b5563; }
    .info-value { flex: 1; color: #1f2937; }
    .status-badge { padding: 6px 16px; border-radius: 20px; font-size: 13px; font-weight: 500; display: inline-block; }
    .status-active { background: #dbeafe; color: #1e40af; }
    .status-released { background: #dcfce7; color: #166534; }
    .status-auctioned { background: #fee2e2; color: #991b1b; }
    .status-closed { background: #e5e7eb; color: #4b5563; }
    .summary-box { background: #f8fafc; border-radius: 12px; padding: 15px; text-align: center; }
    .summary-value { font-size: 24px; font-weight: 700; }
    .summary-label { font-size: 12px; color: #6c757d; margin-top: 5px; }
    .action-btn { margin-right: 8px; }
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
                                    <h4 class="mb-1">Pawn Details</h4>
                                    <p class="text-muted mb-0">Complete information for pawn entry <?php echo h($pawn['pawn_no']); ?></p>
                                </div>
                                <div>
                                    <a href="pawn-list.php" class="btn btn-outline-secondary">
                                        <i class="fas fa-arrow-left"></i> Back to List
                                    </a>
                                    <?php if ($pawn['status'] == 'Active'): ?>
                                        <a href="pawn-payment.php?pawn_id=<?php echo $pawn_id; ?>" class="btn btn-success">
                                            <i class="fas fa-rupee-sign"></i> Make Payment
                                        </a>
                                        <a href="pawn-interest.php?pawn_id=<?php echo $pawn_id; ?>" class="btn btn-warning">
                                            <i class="fas fa-percent"></i> Collect Interest
                                        </a>
                                        <a href="pawn-release.php?id=<?php echo $pawn_id; ?>" class="btn btn-primary">
                                            <i class="fas fa-hand-peace"></i> Release
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Overdue Alert -->
                    <?php if ($is_overdue): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Overdue Alert!</strong> This pawn entry is overdue by <?php echo $days_overdue; ?> days. Maturity date was <?php echo date('d-m-Y', strtotime($pawn['maturity_date'])); ?>.
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Summary Cards -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <div class="summary-box">
                                <div class="summary-value">₹<?php echo money($pawn['loan_amount']); ?></div>
                                <div class="summary-label">Original Loan Amount</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="summary-box">
                                <div class="summary-value text-warning">₹<?php echo money($remaining_balance); ?></div>
                                <div class="summary-label">Remaining Balance</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="summary-box">
                                <div class="summary-value text-success">₹<?php echo money($total_principal_paid); ?></div>
                                <div class="summary-label">Principal Paid</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="summary-box">
                                <div class="summary-value text-info">₹<?php echo money($total_interest_collected); ?></div>
                                <div class="summary-label">Interest Collected</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <!-- Loan Information -->
                        <div class="col-md-6">
                            <div class="card detail-card">
                                <div class="card-header">
                                    <i class="fas fa-info-circle me-2"></i> Loan Information
                                </div>
                                <div class="card-body">
                                    <div class="info-row">
                                        <div class="info-label">Pawn No:</div>
                                        <div class="info-value"><strong><?php echo h($pawn['pawn_no']); ?></strong></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Entry Date:</div>
                                        <div class="info-value"><?php echo date('d-m-Y', strtotime($pawn['entry_date'])); ?></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Status:</div>
                                        <div class="info-value">
                                            <span class="status-badge status-<?php echo strtolower(str_replace(' ', '', $pawn['status'])); ?>">
                                                <?php echo h($pawn['status']); ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Metal Type:</div>
                                        <div class="info-value"><?php echo h($pawn['metal_type']); ?></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Loan Type:</div>
                                        <div class="info-value"><?php echo h($pawn['loan_type']); ?></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Total Net Weight:</div>
                                        <div class="info-value"><?php echo number_format($pawn['total_net_weight'], 3); ?> g</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Interest Details -->
                        <div class="col-md-6">
                            <div class="card detail-card">
                                <div class="card-header">
                                    <i class="fas fa-percent me-2"></i> Interest Details
                                </div>
                                <div class="card-body">
                                    <div class="info-row">
                                        <div class="info-label">Interest Rate:</div>
                                        <div class="info-value"><?php echo h($pawn['interest_rate']); ?>% per annum</div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Interest Type:</div>
                                        <div class="info-value"><?php echo h($pawn['interest_type']); ?></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Interest Method:</div>
                                        <div class="info-value"><?php echo h($pawn['interest_method']); ?></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Tenure:</div>
                                        <div class="info-value"><?php echo (int)$pawn['tenure_months']; ?> months</div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Maturity Date:</div>
                                        <div class="info-value">
                                            <?php echo !empty($pawn['maturity_date']) ? date('d-m-Y', strtotime($pawn['maturity_date'])) : '-'; ?>
                                            <?php if ($is_overdue): ?>
                                                <span class="badge bg-danger ms-2">Overdue by <?php echo $days_overdue; ?> days</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Charges:</div>
                                        <div class="info-value">
                                            Ticket: ₹<?php echo money($pawn['ticket_charge']); ?> | 
                                            Other: ₹<?php echo money($pawn['other_charge']); ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Customer Information -->
                        <div class="col-md-6">
                            <div class="card detail-card">
                                <div class="card-header">
                                    <i class="fas fa-user me-2"></i> Customer Information
                                </div>
                                <div class="card-body">
                                    <div class="info-row">
                                        <div class="info-label">Customer Name:</div>
                                        <div class="info-value"><strong><?php echo h($pawn['customer_name']); ?></strong></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Mobile Number:</div>
                                        <div class="info-value"><?php echo h($pawn['customer_mobile']); ?></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Address:</div>
                                        <div class="info-value"><?php echo nl2br(h($pawn['address'])); ?></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">ID Proof:</div>
                                        <div class="info-value"><?php echo h($pawn['id_proof_type']); ?>: <?php echo h($pawn['id_proof_number']); ?></div>
                                    </div>
                                    <?php if ($pawn['remarks']): ?>
                                        <div class="info-row">
                                            <div class="info-label">Remarks:</div>
                                            <div class="info-value"><?php echo nl2br(h($pawn['remarks'])); ?></div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Payment Information -->
                        <div class="col-md-6">
                            <div class="card detail-card">
                                <div class="card-header">
                                    <i class="fas fa-credit-card me-2"></i> Payment Information
                                </div>
                                <div class="card-body">
                                    <div class="info-row">
                                        <div class="info-label">Payment Method:</div>
                                        <div class="info-value">
                                            <?php 
                                            $method_name = '';
                                            if ($pawn['payment_method_id']) {
                                                $method_sql = "SELECT method_name FROM payment_methods WHERE id = {$pawn['payment_method_id']}";
                                                $method_res = $conn->query($method_sql);
                                                if ($method_res && $method_res->num_rows > 0) {
                                                    $method_name = $method_res->fetch_assoc()['method_name'];
                                                }
                                            }
                                            echo $method_name ?: 'Not specified';
                                            ?>
                                        </div>
                                    </div>
                                    <?php if ($pawn['payment_reference']): ?>
                                        <div class="info-row">
                                            <div class="info-label">Reference No:</div>
                                            <div class="info-value"><?php echo h($pawn['payment_reference']); ?></div>
                                        </div>
                                    <?php endif; ?>
                                    <div class="info-row">
                                        <div class="info-label">Created By:</div>
                                        <div class="info-value">
                                            <?php 
                                            $creator_name = '';
                                            if ($pawn['created_by']) {
                                                $creator_sql = "SELECT full_name FROM users WHERE id = {$pawn['created_by']}";
                                                $creator_res = $conn->query($creator_sql);
                                                if ($creator_res && $creator_res->num_rows > 0) {
                                                    $creator_name = $creator_res->fetch_assoc()['full_name'];
                                                }
                                            }
                                            echo $creator_name ?: 'System';
                                            ?>
                                        </div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Created At:</div>
                                        <div class="info-value"><?php echo date('d-m-Y H:i:s', strtotime($pawn['created_at'])); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Pawn Items -->
                    <div class="card detail-card">
                        <div class="card-header">
                            <i class="fas fa-gem me-2"></i> Pawn Items
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>#</th>
                                            <th>Item Name</th>
                                            <th>Category</th>
                                            <th>Purity</th>
                                            <th>Gross Weight (g)</th>
                                            <th>Less (g)</th>
                                            <th>Net Weight (g)</th>
                                            <th>Pawn Value (₹/g)</th>
                                            <th>Est. Amount</th>
                                            <th>Remarks</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($items)): ?>
                                            <tr><td colspan="10" class="text-center text-muted py-4">No items found</td></tr>
                                        <?php else: 
                                            $sn = 1;
                                            foreach ($items as $item): 
                                        ?>
                                            <tr>
                                                <td><?php echo $sn++; ?></td>
                                                <td><strong><?php echo h($item['item_name']); ?></strong></td>
                                                <td><?php echo h($item['item_category']); ?></td>
                                                <td><?php echo h($item['purity']); ?></td>
                                                <td><?php echo number_format($item['gross_weight'], 3); ?></td>
                                                <td><?php echo number_format($item['less_weight'], 3); ?></td>
                                                <td><strong><?php echo number_format($item['net_weight'], 3); ?></strong></td>
                                                <td>₹<?php echo money($item['rate_per_gram']); ?></td>
                                                <td>₹<?php echo money($item['estimated_amount']); ?></td>
                                                <td><small><?php echo h($item['remarks']); ?></small></td>
                                            </tr>
                                        <?php endforeach; endif; ?>
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr>
                                            <td colspan="6" class="text-end"><strong>Totals:</strong></td>
                                            <td><strong><?php echo number_format($pawn['total_net_weight'], 3); ?> g</strong></td>
                                            <td colspan="2"></td>
                                            <td></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Payment History -->
                    <div class="card detail-card">
                        <div class="card-header">
                            <i class="fas fa-history me-2"></i> Payment History
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Receipt No</th>
                                            <th>Date</th>
                                            <th>Type</th>
                                            <th>Principal</th>
                                            <th>Interest</th>
                                            <th>Penalty</th>
                                            <th>Discount</th>
                                            <th>Total</th>
                                            <th>Method</th>
                                            <th>Reference</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($payments)): ?>
                                            <tr><td colspan="10" class="text-center text-muted py-4">No payment history found</td></tr>
                                        <?php else: foreach ($payments as $payment): ?>
                                            <tr>
                                                <td><?php echo h($payment['receipt_no']); ?></td>
                                                <td><?php echo date('d-m-Y', strtotime($payment['payment_date'])); ?></td>
                                                <td><?php echo h($payment['payment_type']); ?></td>
                                                <td>₹<?php echo money($payment['principal_amount']); ?></td>
                                                <td>₹<?php echo money($payment['interest_amount']); ?></td>
                                                <td>₹<?php echo money($payment['penalty_amount']); ?></td>
                                                <td>₹<?php echo money($payment['discount_amount']); ?></td>
                                                <td><strong>₹<?php echo money($payment['total_amount']); ?></strong></td>
                                                <td>
                                                    <?php 
                                                    $pm_name = '';
                                                    if ($payment['payment_method_id']) {
                                                        $pm_sql = "SELECT method_name FROM payment_methods WHERE id = {$payment['payment_method_id']}";
                                                        $pm_res = $conn->query($pm_sql);
                                                        if ($pm_res && $pm_res->num_rows > 0) {
                                                            $pm_name = $pm_res->fetch_assoc()['method_name'];
                                                        }
                                                    }
                                                    echo h($pm_name);
                                                    ?>
                                                </td>
                                                <td><?php echo h($payment['reference_no']); ?></td>
                                            </tr>
                                        <?php endforeach; endif; ?>
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr>
                                            <td colspan="3" class="text-end"><strong>Totals:</strong></td>
                                            <td><strong>₹<?php echo money($total_principal_paid); ?></strong></td>
                                            <td><strong>₹<?php echo money($total_interest_paid); ?></strong></td>
                                            <td colspan="5"></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Interest Collection History -->
                    <div class="card detail-card">
                        <div class="card-header">
                            <i class="fas fa-percent me-2"></i> Interest Collection History
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Receipt No</th>
                                            <th>Collection Date</th>
                                            <th>From Date</th>
                                            <th>To Date</th>
                                            <th>Days</th>
                                            <th>Interest Amount</th>
                                            <th>Penalty</th>
                                            <th>Discount</th>
                                            <th>Net Amount</th>
                                            <th>Method</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($interests)): ?>
                                            <tr><td colspan="10" class="text-center text-muted py-4">No interest collection history found</td></tr>
                                        <?php else: foreach ($interests as $interest): ?>
                                            <tr>
                                                <td><?php echo h($interest['receipt_no']); ?></td>
                                                <td><?php echo date('d-m-Y', strtotime($interest['collection_date'])); ?></td>
                                                <td><?php echo !empty($interest['interest_from']) ? date('d-m-Y', strtotime($interest['interest_from'])) : '-'; ?></td>
                                                <td><?php echo !empty($interest['interest_to']) ? date('d-m-Y', strtotime($interest['interest_to'])) : '-'; ?></td>
                                                <td><?php echo (int)$interest['days_count']; ?></td>
                                                <td>₹<?php echo money($interest['interest_amount']); ?></td>
                                                <td>₹<?php echo money($interest['penalty_amount']); ?></td>
                                                <td>₹<?php echo money($interest['discount_amount']); ?></td>
                                                <td><strong>₹<?php echo money($interest['net_amount']); ?></strong></td>
                                                <td>
                                                    <?php 
                                                    $pm_name = '';
                                                    if ($interest['payment_method_id']) {
                                                        $pm_sql = "SELECT method_name FROM payment_methods WHERE id = {$interest['payment_method_id']}";
                                                        $pm_res = $conn->query($pm_sql);
                                                        if ($pm_res && $pm_res->num_rows > 0) {
                                                            $pm_name = $pm_res->fetch_assoc()['method_name'];
                                                        }
                                                    }
                                                    echo h($pm_name);
                                                    ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; endif; ?>
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr>
                                            <td colspan="5" class="text-end"><strong>Total Interest:</strong></td>
                                            <td><strong>₹<?php echo money($total_interest_collected); ?></strong></td>
                                            <td colspan="4"></td>
                                        </tr>
                                    </tfoot>
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
</body>
</html>