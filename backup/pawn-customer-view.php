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

// Get customer ID from URL
$pawn_customer_id = (int)($_GET['id'] ?? 0);
$customer_id = (int)($_GET['customer_id'] ?? 0);

if ($pawn_customer_id <= 0 && $customer_id <= 0) {
    header('Location: pawn-customers.php');
    exit;
}

// Fetch pawn customer details
$customer = null;
$loan_summary = null;
$recent_loans = [];

// Build query based on which ID is provided
if ($pawn_customer_id > 0) {
    $sql = "SELECT pc.*, c.customer_name, c.customer_code, c.mobile, c.email, c.alternate_mobile, 
                   c.address_line1, c.address_line2, c.city, c.state, c.pincode, c.gstin,
                   c.date_of_birth, c.anniversary_date, c.opening_balance, c.notes as customer_notes
            FROM pawn_customers pc 
            INNER JOIN customers c ON pc.customer_id = c.id 
            WHERE pc.id = $pawn_customer_id AND pc.business_id = $business_id";
} else {
    $sql = "SELECT pc.*, c.customer_name, c.customer_code, c.mobile, c.email, c.alternate_mobile, 
                   c.address_line1, c.address_line2, c.city, c.state, c.pincode, c.gstin,
                   c.date_of_birth, c.anniversary_date, c.opening_balance, c.notes as customer_notes
            FROM pawn_customers pc 
            INNER JOIN customers c ON pc.customer_id = c.id 
            WHERE pc.customer_id = $customer_id AND pc.business_id = $business_id";
}

$result = $conn->query($sql);
if ($result && $result->num_rows > 0) {
    $customer = $result->fetch_assoc();
} else {
    header('Location: pawn-customers.php?error=notfound');
    exit;
}

// Fetch loan summary - FIXED: removed interest_collected column
$loan_sql = "SELECT 
                COUNT(*) as total_loans,
                SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) as active_loans,
                SUM(CASE WHEN status = 'Released' THEN 1 ELSE 0 END) as released_loans,
                SUM(CASE WHEN status = 'Auctioned' THEN 1 ELSE 0 END) as auctioned_loans,
                COALESCE(SUM(loan_amount), 0) as total_loan_amount,
                COALESCE(SUM(principal_balance), 0) as total_outstanding
             FROM pawn_entries 
             WHERE customer_id = {$customer['customer_id']} AND business_id = $business_id";
$loan_res = $conn->query($loan_sql);
if ($loan_res && $loan_res->num_rows > 0) {
    $loan_summary = $loan_res->fetch_assoc();
}

// Calculate interest collected from pawn_interest_collections table
$interest_sql = "SELECT COALESCE(SUM(interest_amount), 0) as total_interest_collected
                 FROM pawn_interest_collections pic
                 INNER JOIN pawn_entries pe ON pic.pawn_id = pe.id
                 WHERE pe.customer_id = {$customer['customer_id']} AND pic.business_id = $business_id";
$interest_res = $conn->query($interest_sql);
$total_interest_collected = 0;
if ($interest_res && $interest_res->num_rows > 0) {
    $interest_row = $interest_res->fetch_assoc();
    $total_interest_collected = $interest_row['total_interest_collected'];
}

// Fetch recent loans
$recent_sql = "SELECT id, pawn_no, entry_date, loan_amount, principal_balance, interest_rate, 
                      tenure_months, maturity_date, status 
               FROM pawn_entries 
               WHERE customer_id = {$customer['customer_id']} AND business_id = $business_id 
               ORDER BY id DESC LIMIT 10";
$recent_res = $conn->query($recent_sql);
if ($recent_res) {
    while ($row = $recent_res->fetch_assoc()) {
        $recent_loans[] = $row;
    }
}

// Fetch payment history from pawn_payments
$payment_sql = "SELECT pp.*, pe.pawn_no 
                FROM pawn_payments pp 
                INNER JOIN pawn_entries pe ON pp.pawn_id = pe.id 
                WHERE pe.customer_id = {$customer['customer_id']} AND pp.business_id = $business_id 
                ORDER BY pp.payment_date DESC LIMIT 10";
$payments = [];
$payment_res = $conn->query($payment_sql);
if ($payment_res) {
    while ($row = $payment_res->fetch_assoc()) {
        $payments[] = $row;
    }
}

// Fetch interest collection history
$interest_history_sql = "SELECT pic.*, pe.pawn_no 
                         FROM pawn_interest_collections pic 
                         INNER JOIN pawn_entries pe ON pic.pawn_id = pe.id 
                         WHERE pe.customer_id = {$customer['customer_id']} AND pic.business_id = $business_id 
                         ORDER BY pic.collection_date DESC LIMIT 10";
$interest_history = [];
$interest_history_res = $conn->query($interest_history_sql);
if ($interest_history_res) {
    while ($row = $interest_history_res->fetch_assoc()) {
        $interest_history[] = $row;
    }
}
?>
<!doctype html>
<html lang="en">
<?php include('includes/head.php'); ?>
<title>Pawn Customer Details | Pawn Broking</title>
<style>
    .detail-card { border-radius: 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 24px; }
    .detail-card .card-header { background: #f8fafc; border-bottom: 1px solid #eef2f6; padding: 16px 20px; font-weight: 600; }
    .detail-card .card-body { padding: 20px; }
    .info-row { display: flex; padding: 8px 0; border-bottom: 1px solid #f0f0f0; }
    .info-label { width: 150px; font-weight: 600; color: #4b5563; }
    .info-value { flex: 1; color: #1f2937; }
    .risk-badge { padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 500; display: inline-block; }
    .risk-low { background: #dcfce7; color: #166534; }
    .risk-medium { background: #fed7aa; color: #92400e; }
    .risk-high { background: #fee2e2; color: #991b1b; }
    .status-active { background: #dbeafe; color: #1e40af; padding: 4px 12px; border-radius: 20px; font-size: 12px; display: inline-block; }
    .status-released { background: #dcfce7; color: #166534; padding: 4px 12px; border-radius: 20px; font-size: 12px; display: inline-block; }
    .status-auctioned { background: #fee2e2; color: #991b1b; padding: 4px 12px; border-radius: 20px; font-size: 12px; display: inline-block; }
    .status-closed { background: #e5e7eb; color: #4b5563; padding: 4px 12px; border-radius: 20px; font-size: 12px; display: inline-block; }
    .customer-avatar-lg { width: 100px; height: 100px; border-radius: 50%; background: #eef2ff; display: flex; align-items: center; justify-content: center; font-size: 36px; font-weight: 600; color: #4f46e5; margin: 0 auto; }
    .stat-box { text-align: center; padding: 15px; background: #f8fafc; border-radius: 12px; }
    .stat-value { font-size: 24px; font-weight: 700; }
    .stat-label { font-size: 12px; color: #6c757d; margin-top: 5px; }
    .preview-image { max-width: 150px; max-height: 150px; border-radius: 8px; border: 1px solid #ddd; }
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
                                    <h4 class="mb-1">Pawn Customer Details</h4>
                                    <p class="text-muted mb-0">Complete customer information and loan history</p>
                                </div>
                                <div>
                                    <a href="pawn-customer-add.php?id=<?php echo $customer['customer_id']; ?>" class="btn btn-primary">
                                        <i class="fas fa-edit"></i> Edit Customer
                                    </a>
                                    <a href="pawn-customers.php" class="btn btn-outline-secondary">
                                        <i class="fas fa-arrow-left"></i> Back to List
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Customer Profile Header -->
                    <div class="row mb-4">
                        <div class="col-md-12">
                            <div class="card detail-card">
                                <div class="card-body">
                                    <div class="row align-items-center">
                                        <div class="col-md-2 text-center">
                                            <div class="customer-avatar-lg">
                                                <?php echo strtoupper(substr($customer['customer_name'], 0, 2)); ?>
                                            </div>
                                            <?php if (!empty($customer['photo_path']) && file_exists($customer['photo_path'])): ?>
                                                <div class="mt-2">
                                                    <small class="text-muted">Photo uploaded</small>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="col-md-6">
                                            <h3 class="mb-1"><?php echo h($customer['customer_name']); ?></h3>
                                            <p class="text-muted mb-2">Customer Code: <?php echo h($customer['customer_code']); ?></p>
                                            <div class="d-flex flex-wrap gap-2">
                                                <span class="risk-badge risk-<?php echo strtolower($customer['risk_category'] ?? 'low'); ?>">
                                                    <i class="fas fa-shield-alt"></i> <?php echo h($customer['risk_category'] ?? 'Low'); ?> Risk
                                                </span>
                                                <?php if ($customer['kyc_verified']): ?>
                                                    <span class="risk-badge risk-low">
                                                        <i class="fas fa-check-circle"></i> KYC Verified
                                                    </span>
                                                <?php else: ?>
                                                    <span class="risk-badge risk-medium">
                                                        <i class="fas fa-clock"></i> KYC Pending
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="col-md-4 text-end">
                                            <div class="stat-box">
                                                <div class="stat-value">₹<?php echo money($loan_summary['total_outstanding'] ?? 0); ?></div>
                                                <div class="stat-label">Total Outstanding</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Statistics Row -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <div class="stat-box">
                                <div class="stat-value"><?php echo (int)($loan_summary['total_loans'] ?? 0); ?></div>
                                <div class="stat-label">Total Loans</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-box">
                                <div class="stat-value text-primary"><?php echo (int)($loan_summary['active_loans'] ?? 0); ?></div>
                                <div class="stat-label">Active Loans</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-box">
                                <div class="stat-value text-success">₹<?php echo money($loan_summary['total_loan_amount'] ?? 0); ?></div>
                                <div class="stat-label">Total Loan Amount</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-box">
                                <div class="stat-value text-info">₹<?php echo money($total_interest_collected); ?></div>
                                <div class="stat-label">Interest Collected</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <!-- Personal Information -->
                        <div class="col-md-6">
                            <div class="card detail-card">
                                <div class="card-header">
                                    <i class="fas fa-user me-2"></i> Personal Information
                                </div>
                                <div class="card-body">
                                    <div class="info-row">
                                        <div class="info-label">Full Name:</div>
                                        <div class="info-value"><?php echo h($customer['customer_name']); ?></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Guardian Name:</div>
                                        <div class="info-value"><?php echo h($customer['guardian_name'] ?? 'N/A'); ?></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Date of Birth:</div>
                                        <div class="info-value"><?php echo !empty($customer['date_of_birth']) ? date('d-m-Y', strtotime($customer['date_of_birth'])) : 'N/A'; ?></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Occupation:</div>
                                        <div class="info-value"><?php echo h($customer['occupation'] ?? 'N/A'); ?></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Annual Income:</div>
                                        <div class="info-value">₹<?php echo money($customer['annual_income'] ?? 0); ?></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Credit Limit:</div>
                                        <div class="info-value">₹<?php echo money($customer['credit_limit'] ?? 0); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Contact Information -->
                        <div class="col-md-6">
                            <div class="card detail-card">
                                <div class="card-header">
                                    <i class="fas fa-phone me-2"></i> Contact Information
                                </div>
                                <div class="card-body">
                                    <div class="info-row">
                                        <div class="info-label">Mobile Number:</div>
                                        <div class="info-value"><?php echo h($customer['mobile'] ?? 'N/A'); ?></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Alternate Mobile:</div>
                                        <div class="info-value"><?php echo h($customer['alternate_mobile'] ?? 'N/A'); ?></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Email:</div>
                                        <div class="info-value"><?php echo h($customer['email'] ?? 'N/A'); ?></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Address:</div>
                                        <div class="info-value">
                                            <?php echo h($customer['address_line1'] ?? ''); ?><br>
                                            <?php echo h($customer['address_line2'] ?? ''); ?><br>
                                            <?php echo h($customer['city'] ?? ''); ?> - <?php echo h($customer['pincode'] ?? ''); ?><br>
                                            <?php echo h($customer['state'] ?? ''); ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Reference Details -->
                        <div class="col-md-6">
                            <div class="card detail-card">
                                <div class="card-header">
                                    <i class="fas fa-user-friends me-2"></i> Reference Details
                                </div>
                                <div class="card-body">
                                    <div class="info-row">
                                        <div class="info-label">Reference Name:</div>
                                        <div class="info-value"><?php echo h($customer['reference_name'] ?? 'N/A'); ?></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Reference Mobile:</div>
                                        <div class="info-value"><?php echo h($customer['reference_mobile'] ?? 'N/A'); ?></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Notes:</div>
                                        <div class="info-value"><?php echo nl2br(h($customer['notes'] ?? 'N/A')); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- KYC Documents -->
                        <div class="col-md-6">
                            <div class="card detail-card">
                                <div class="card-header">
                                    <i class="fas fa-id-card me-2"></i> KYC Documents
                                </div>
                                <div class="card-body">
                                    <?php if (!empty($customer['photo_path']) && file_exists($customer['photo_path'])): ?>
                                        <div class="mb-3">
                                            <label class="fw-bold">Customer Photo:</label><br>
                                            <img src="<?php echo h($customer['photo_path']); ?>" class="preview-image" alt="Customer Photo">
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($customer['signature_path']) && file_exists($customer['signature_path'])): ?>
                                        <div class="mb-3">
                                            <label class="fw-bold">Signature:</label><br>
                                            <img src="<?php echo h($customer['signature_path']); ?>" class="preview-image" alt="Signature">
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($customer['kyc_document']) && file_exists($customer['kyc_document'])): ?>
                                        <div class="mb-3">
                                            <label class="fw-bold">KYC Document:</label><br>
                                            <a href="<?php echo h($customer['kyc_document']); ?>" target="_blank" class="btn btn-sm btn-outline-info">
                                                <i class="fas fa-file-pdf"></i> View Document
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if (empty($customer['photo_path']) && empty($customer['signature_path']) && empty($customer['kyc_document'])): ?>
                                        <p class="text-muted mb-0">No documents uploaded</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Recent Loans -->
                    <div class="card detail-card">
                        <div class="card-header">
                            <i class="fas fa-hand-holding-usd me-2"></i> Recent Loans
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Pawn No</th>
                                            <th>Date</th>
                                            <th>Loan Amount</th>
                                            <th>Outstanding</th>
                                            <th>Interest Rate</th>
                                            <th>Tenure</th>
                                            <th>Maturity Date</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($recent_loans)): ?>
                                            <tr><td colspan="9" class="text-center text-muted py-4">No loans found for this customer</td>79
                                        <?php else: foreach ($recent_loans as $loan): ?>
                                            <tr>
                                                <td><strong><?php echo h($loan['pawn_no']); ?></strong></td>
                                                <td><?php echo date('d-m-Y', strtotime($loan['entry_date'])); ?></td>
                                                <td>₹<?php echo money($loan['loan_amount']); ?></td>
                                                <td>₹<?php echo money($loan['principal_balance']); ?></td>
                                                <td><?php echo h($loan['interest_rate']); ?>%</td>
                                                <td><?php echo (int)$loan['tenure_months']; ?> months</td>
                                                <td><?php echo !empty($loan['maturity_date']) ? date('d-m-Y', strtotime($loan['maturity_date'])) : '-'; ?></td>
                                                <td>
                                                    <span class="status-<?php echo strtolower($loan['status']); ?>">
                                                        <?php echo h($loan['status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <a href="pawn-view.php?id=<?php echo $loan['id']; ?>" class="btn btn-sm btn-outline-info">
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
                    
                    <!-- Recent Payments -->
                    <div class="card detail-card">
                        <div class="card-header">
                            <i class="fas fa-rupee-sign me-2"></i> Recent Payments
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Receipt No</th>
                                            <th>Pawn No</th>
                                            <th>Payment Date</th>
                                            <th>Type</th>
                                            <th>Principal</th>
                                            <th>Interest</th>
                                            <th>Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($payments)): ?>
                                            <tr><td colspan="7" class="text-center text-muted py-4">No payment history found</td></tr>
                                        <?php else: foreach ($payments as $payment): ?>
                                            <tr>
                                                <td><?php echo h($payment['receipt_no']); ?></td>
                                                <td><?php echo h($payment['pawn_no']); ?></td>
                                                <td><?php echo date('d-m-Y', strtotime($payment['payment_date'])); ?></td>
                                                <td><?php echo h($payment['payment_type']); ?></td>
                                                <td>₹<?php echo money($payment['principal_amount']); ?></td>
                                                <td>₹<?php echo money($payment['interest_amount']); ?></td>
                                                <td><strong>₹<?php echo money($payment['total_amount']); ?></strong></td>
                                            </tr>
                                        <?php endforeach; endif; ?>
                                    </tbody>
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
                                            <th>Pawn No</th>
                                            <th>Collection Date</th>
                                            <th>Interest From</th>
                                            <th>Interest To</th>
                                            <th>Days</th>
                                            <th>Interest Amount</th>
                                            <th>Net Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($interest_history)): ?>
                                            <tr><td colspan="8" class="text-center text-muted py-4">No interest collection history found</td>79
                                        <?php else: foreach ($interest_history as $interest): ?>
                                            <tr>
                                                <td><?php echo h($interest['receipt_no']); ?></td>
                                                <td><?php echo h($interest['pawn_no']); ?></td>
                                                <td><?php echo date('d-m-Y', strtotime($interest['collection_date'])); ?></td>
                                                <td><?php echo !empty($interest['interest_from']) ? date('d-m-Y', strtotime($interest['interest_from'])) : '-'; ?></td>
                                                <td><?php echo !empty($interest['interest_to']) ? date('d-m-Y', strtotime($interest['interest_to'])) : '-'; ?></td>
                                                <td><?php echo (int)$interest['days_count']; ?></td>
                                                <td>₹<?php echo money($interest['interest_amount']); ?></td>
                                                <td><strong>₹<?php echo money($interest['net_amount']); ?></strong></td>
                                            </tr>
                                        <?php endforeach; endif; ?>
                                    </tbody>
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