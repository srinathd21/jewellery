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
$currentPage = 'pawn-release';

// Get pawn ID from URL
$pawn_id = (int)($_GET['id'] ?? $_POST['pawn_id'] ?? 0);
$pawn = null;
$error = '';
$success = '';

if ($pawn_id > 0) {
    // Fetch pawn entry details
    $sql = "SELECT pe.*, c.customer_name, c.mobile, c.email, c.address_line1, c.city, c.state
            FROM pawn_entries pe
            LEFT JOIN customers c ON pe.customer_id = c.id
            WHERE pe.id = {$pawn_id} AND pe.business_id = {$businessId} AND pe.status IN ('Active', 'Partially Paid')";
    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) {
        $pawn = $result->fetch_assoc();
    } else {
        $error = "Pawn entry not found or already released/closed.";
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

// Get payment history
$payment_history = [];
$total_paid = 0;
if ($pawn_id > 0) {
    $history_sql = "SELECT * FROM pawn_payments WHERE pawn_id = {$pawn_id} AND business_id = {$businessId} ORDER BY payment_date DESC";
    $history_res = $conn->query($history_sql);
    if ($history_res) {
        while ($row = $history_res->fetch_assoc()) {
            $payment_history[] = $row;
            $total_paid += $row['principal_amount'];
        }
    }
}

// Get interest collections
$interest_collections = [];
$total_interest = 0;
if ($pawn_id > 0) {
    $interest_sql = "SELECT * FROM pawn_interest_collections WHERE pawn_id = {$pawn_id} AND business_id = {$businessId} ORDER BY collection_date DESC";
    $interest_res = $conn->query($interest_sql);
    if ($interest_res) {
        while ($row = $interest_res->fetch_assoc()) {
            $interest_collections[] = $row;
            $total_interest += $row['interest_amount'];
        }
    }
}

// Calculate release amount
$release_principal = $pawn['principal_balance'] ?? 0;
$pending_interest = 0;
$release_penalty = 0;
$release_total = $release_principal;

// Calculate pending interest
if ($pawn && $pawn['status'] != 'Released') {
    // Get last interest collection date
    $last_interest_date = null;
    $last_sql = "SELECT MAX(interest_to) as last_date FROM pawn_interest_collections 
                 WHERE pawn_id = {$pawn_id} AND business_id = {$businessId}";
    $last_res = $conn->query($last_sql);
    if ($last_res && $last_res->num_rows > 0) {
        $last_row = $last_res->fetch_assoc();
        if ($last_row['last_date']) {
            $last_interest_date = $last_row['last_date'];
        }
    }
    
    $from_date = $last_interest_date ?: $pawn['entry_date'];
    $to_date = date('Y-m-d');
    
    if ($from_date && $to_date) {
        $date1 = new DateTime($from_date);
        $date2 = new DateTime($to_date);
        $days = $date1->diff($date2)->days + 1;
        
        if ($pawn['interest_method'] == 'Simple') {
            $pending_interest = ($release_principal * $pawn['interest_rate'] * $days) / (100 * 365);
        } else {
            $months = $days / 30;
            $monthly_rate = $pawn['interest_rate'] / (12 * 100);
            $pending_interest = $release_principal * pow(1 + $monthly_rate, $months) - $release_principal;
        }
        
        // Calculate penalty (10% of pending interest if overdue)
        $maturity_date = new DateTime($pawn['maturity_date']);
        $today = new DateTime();
        if ($maturity_date < $today) {
            $release_penalty = $pending_interest * 0.10;
        }
    }
}

$release_total = $release_principal + $pending_interest + $release_penalty;

// Process release form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_release'])) {
    $pawn_id = (int)$_POST['pawn_id'];
    $release_date = $_POST['release_date'] ?? date('Y-m-d');
    $principal_amount = (float)($_POST['principal_amount'] ?? 0);
    $interest_amount = (float)($_POST['interest_amount'] ?? 0);
    $penalty_amount = (float)($_POST['penalty_amount'] ?? 0);
    $discount_amount = (float)($_POST['discount_amount'] ?? 0);
    $total_amount = (float)($_POST['total_amount'] ?? 0);
    $payment_method_id = !empty($_POST['payment_method_id']) ? (int)$_POST['payment_method_id'] : null;
    $reference_no = trim($_POST['reference_no'] ?? '');
    $release_remarks = trim($_POST['release_remarks'] ?? '');
    $waive_interest = isset($_POST['waive_interest']) ? 1 : 0;
    $waive_penalty = isset($_POST['waive_penalty']) ? 1 : 0;
    
    $errors = [];
    
    if ($principal_amount <= 0 && $interest_amount <= 0 && $penalty_amount <= 0) {
        $errors[] = "Please enter a valid release amount";
    }
    
    if (empty($errors)) {
        $conn->begin_transaction();
        
        try {
            // Generate receipt number for final payment
            $receipt_no = nextNo($conn, 'pawn_payments', 'receipt_no', 'REL', $businessId);
            
            // Insert final payment record
            $insert_sql = "INSERT INTO pawn_payments (
                business_id, pawn_id, receipt_no, payment_date, payment_type,
                principal_amount, interest_amount, penalty_amount, discount_amount,
                total_amount, payment_method_id, reference_no, remarks, created_by, created_at
            ) VALUES (
                {$businessId}, {$pawn_id}, '{$receipt_no}', '{$release_date}', 'Release',
                {$principal_amount}, {$interest_amount}, {$penalty_amount}, {$discount_amount},
                {$total_amount}, " . ($payment_method_id ?: 'NULL') . ", 
                '" . $conn->real_escape_string($reference_no) . "',
                '" . $conn->real_escape_string($release_remarks) . "', {$userId}, NOW()
            )";
            
            if (!$conn->query($insert_sql)) {
                throw new Exception("Failed to save release payment: " . $conn->error);
            }
            
            // If waiving interest, insert a waiver record or just note in remarks
            if ($waive_interest || $waive_penalty) {
                $waiver_remarks = [];
                if ($waive_interest) $waiver_remarks[] = "Interest waived";
                if ($waive_penalty) $waiver_remarks[] = "Penalty waived";
                // Could insert into a waivers table if exists
            }
            
            // Update pawn entry status
            $update_sql = "UPDATE pawn_entries SET 
                          principal_balance = 0,
                          status = 'Released',
                          released_at = NOW(),
                          updated_at = NOW(),
                          remarks = CONCAT(IFNULL(remarks, ''), '\nReleased on: {$release_date}. ', '{$release_remarks}')
                          WHERE id = {$pawn_id}";
            
            if (!$conn->query($update_sql)) {
                throw new Exception("Failed to update pawn status: " . $conn->error);
            }
            
            $conn->commit();
            $success = "Pawn released successfully! Receipt No: {$receipt_no}";
            
            // Redirect to view page after 2 seconds
            echo '<script>setTimeout(function(){ window.location.href = "pawn-view.php?id=' . $pawn_id . '&released=1"; }, 2000);</script>';
            
        } catch (Exception $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    } else {
        $error = implode("<br>", $errors);
    }
}

// Get all active pawns for listing
$active_pawns = [];
$active_query = "SELECT pe.id, pe.pawn_no, pe.customer_name, pe.customer_mobile, 
                        pe.principal_balance, pe.loan_amount, pe.entry_date
                 FROM pawn_entries pe
                 WHERE pe.business_id = {$businessId} AND pe.status IN ('Active', 'Partially Paid')
                 ORDER BY pe.id DESC";
$active_res = $conn->query($active_query);
if ($active_res) {
    while ($row = $active_res->fetch_assoc()) {
        $active_pawns[] = $row;
    }
}
?>
<!doctype html>
<html lang="en">
<?php include('includes/head.php'); ?>
<title>Pawn Release | Pawn Broking</title>
<style>
    .release-card { border-radius: 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 24px; }
    .release-card .card-header { background: #f8fafc; border-bottom: 1px solid #eef2f6; padding: 16px 20px; font-weight: 600; }
    .release-card .card-body { padding: 20px; }
    .info-row { display: flex; padding: 8px 0; border-bottom: 1px solid #f0f0f0; }
    .info-label { width: 160px; font-weight: 600; color: #4b5563; }
    .info-value { flex: 1; color: #1f2937; }
    .summary-box { background: #f8fafc; border-radius: 12px; padding: 15px; text-align: center; }
    .summary-value { font-size: 24px; font-weight: 700; }
    .summary-label { font-size: 12px; color: #6c757d; margin-top: 5px; }
    .release-amount-box { background: #f0fdf4; border: 2px solid #22c55e; border-radius: 12px; padding: 20px; text-align: center; }
    .release-amount { font-size: 36px; font-weight: 800; color: #059669; }
    .status-badge { padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 500; display: inline-block; }
    .status-active { background: #dbeafe; color: #1e40af; }
    .due-badge { background: #fee2e2; color: #991b1b; }
    .search-result-item { cursor: pointer; padding: 10px; border-bottom: 1px solid #e5e7eb; transition: background 0.2s; }
    .search-result-item:hover { background: #f3f4f6; }
    .receipt-box { background: #fef3c7; border-radius: 8px; padding: 10px; font-family: monospace; }
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
                                    <h4 class="mb-1">Pawn Release</h4>
                                    <p class="text-muted mb-0">Release pawned items after full settlement</p>
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
                    
                    <!-- Search Section -->
                    <div class="card release-card">
                        <div class="card-header">
                            <i class="fas fa-search me-2"></i> Select Pawn Entry for Release
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <label class="form-label">Search by Pawn Number or Customer Name</label>
                                    <div class="input-group">
                                        <input type="text" id="searchInput" class="form-control" placeholder="Type pawn number or customer name..." autocomplete="off">
                                        <button class="btn btn-primary" type="button" onclick="searchPawn()">
                                            <i class="fas fa-search"></i> Search
                                        </button>
                                    </div>
                                    <div id="searchResults" class="mt-2" style="display: none; max-height: 300px; overflow-y: auto; border: 1px solid #e5e7eb; border-radius: 8px; background: white;"></div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Or Select from Dropdown</label>
                                    <select id="pawnSelect" class="form-select" onchange="selectPawn(this.value)">
                                        <option value="">-- Select Pawn Entry --</option>
                                        <?php foreach ($active_pawns as $p): ?>
                                            <option value="<?php echo $p['id']; ?>" <?php echo ($pawn_id == $p['id']) ? 'selected' : ''; ?>>
                                                <?php echo h($p['pawn_no']); ?> - <?php echo h($p['customer_name']); ?> (₹<?php echo money($p['principal_balance']); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <?php if ($pawn): ?>
                    <!-- Pawn Details Summary -->
                    <div class="row">
                        <div class="col-md-6">
                            <div class="card release-card">
                                <div class="card-header">
                                    <i class="fas fa-info-circle me-2"></i> Pawn Information
                                </div>
                                <div class="card-body">
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
                                    <div class="info-row">
                                        <div class="info-label">Entry Date:</div>
                                        <div class="info-value"><?php echo date('d-m-Y', strtotime($pawn['entry_date'])); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="card release-card">
                                <div class="card-header">
                                    <i class="fas fa-chart-line me-2"></i> Loan Summary
                                </div>
                                <div class="card-body">
                                    <div class="info-row">
                                        <div class="info-label">Original Loan Amount:</div>
                                        <div class="info-value">₹<?php echo money($pawn['loan_amount']); ?></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Outstanding Principal:</div>
                                        <div class="info-value"><strong class="text-primary">₹<?php echo money($pawn['principal_balance']); ?></strong></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Total Principal Paid:</div>
                                        <div class="info-value">₹<?php echo money($pawn['loan_amount'] - $pawn['principal_balance']); ?></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Interest Rate:</div>
                                        <div class="info-value"><?php echo h($pawn['interest_rate']); ?>% (<?php echo h($pawn['interest_method']); ?>)</div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Maturity Date:</div>
                                        <div class="info-value">
                                            <?php echo date('d-m-Y', strtotime($pawn['maturity_date'])); ?>
                                            <?php if (strtotime($pawn['maturity_date']) < time()): ?>
                                                <span class="badge bg-danger ms-2">Overdue</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Release Amount Calculation -->
                    <div class="card release-card">
                        <div class="card-header">
                            <i class="fas fa-calculator me-2"></i> Release Amount Calculation
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="release-amount-box">
                                        <div class="summary-label">Total Amount to be Paid for Release</div>
                                        <div class="release-amount">₹<?php echo money($release_total); ?></div>
                                        <small class="text-muted">Includes principal + pending interest + penalty (if any)</small>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row mt-4">
                                <div class="col-md-4">
                                    <div class="summary-box">
                                        <div class="summary-value">₹<?php echo money($release_principal); ?></div>
                                        <div class="summary-label">Principal Balance</div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="summary-box">
                                        <div class="summary-value text-warning">₹<?php echo money($pending_interest); ?></div>
                                        <div class="summary-label">Pending Interest</div>
                                        <small class="text-muted">Calculated up to today</small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="summary-box">
                                        <div class="summary-value text-danger">₹<?php echo money($release_penalty); ?></div>
                                        <div class="summary-label">Penalty (if overdue)</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Release Form -->
                    <div class="card release-card">
                        <div class="card-header">
                            <i class="fas fa-hand-peace me-2"></i> Release Confirmation
                        </div>
                        <div class="card-body">
                            <form method="POST" id="releaseForm" onsubmit="return validateReleaseForm()">
                                <input type="hidden" name="submit_release" value="1">
                                <input type="hidden" name="pawn_id" value="<?php echo $pawn_id; ?>">
                                
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label required">Release Date</label>
                                        <input type="date" name="release_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Payment Method</label>
                                        <select name="payment_method_id" class="form-select">
                                            <option value="">-- Select --</option>
                                            <?php foreach ($paymentMethods as $pm): ?>
                                                <option value="<?php echo $pm['id']; ?>"><?php echo h($pm['method_name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Reference No (Cheque/UTR)</label>
                                        <input type="text" name="reference_no" class="form-control" placeholder="Optional">
                                    </div>
                                </div>
                                
                                <div class="row g-3 mt-2">
                                    <div class="col-md-3">
                                        <label class="form-label">Principal Amount (₹)</label>
                                        <input type="number" step="0.01" name="principal_amount" id="principal_amount" class="form-control" value="<?php echo money($release_principal); ?>" readonly style="background:#f0f0f0;">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Interest Amount (₹)</label>
                                        <div class="input-group">
                                            <input type="number" step="0.01" name="interest_amount" id="interest_amount" class="form-control" value="<?php echo money($pending_interest); ?>" onchange="calculateTotal()">
                                            <div class="input-group-text">
                                                <input type="checkbox" name="waive_interest" id="waive_interest" value="1" onchange="toggleWaiveInterest()">
                                                <label class="ms-1 mb-0">Waive</label>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Penalty (₹)</label>
                                        <div class="input-group">
                                            <input type="number" step="0.01" name="penalty_amount" id="penalty_amount" class="form-control" value="<?php echo money($release_penalty); ?>" onchange="calculateTotal()">
                                            <div class="input-group-text">
                                                <input type="checkbox" name="waive_penalty" id="waive_penalty" value="1" onchange="toggleWaivePenalty()">
                                                <label class="ms-1 mb-0">Waive</label>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Discount (₹)</label>
                                        <input type="number" step="0.01" name="discount_amount" id="discount_amount" class="form-control" value="0" onchange="calculateTotal()">
                                    </div>
                                </div>
                                
                                <div class="row g-3 mt-3">
                                    <div class="col-md-12">
                                        <label class="form-label">Release Remarks</label>
                                        <textarea name="release_remarks" class="form-control" rows="2" placeholder="Any notes about the release..."></textarea>
                                    </div>
                                </div>
                                
                                <div class="row g-3 mt-3">
                                    <div class="col-md-12">
                                        <div class="alert alert-success">
                                            <div class="row align-items-center">
                                                <div class="col-md-6">
                                                    <strong>Final Release Amount: </strong>
                                                    <span style="font-size: 28px; font-weight: 700;" id="total_amount_display">₹<?php echo money($release_total); ?></span>
                                                    <input type="hidden" name="total_amount" id="total_amount" value="<?php echo $release_total; ?>">
                                                </div>
                                                <div class="col-md-6 text-end">
                                                    <button type="button" class="btn btn-warning" onclick="resetToCalculated()">
                                                        <i class="fas fa-undo"></i> Reset to Calculated
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row mt-4">
                                    <div class="col-12 text-end">
                                        <button type="button" class="btn btn-secondary me-2" onclick="window.location.href='pawn-view.php?id=<?php echo $pawn_id; ?>'">
                                            <i class="fas fa-times"></i> Cancel
                                        </button>
                                        <button type="submit" class="btn btn-success btn-lg">
                                            <i class="fas fa-hand-peace"></i> Confirm Release
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Payment History -->
                    <?php if (!empty($payment_history) || !empty($interest_collections)): ?>
                    <div class="card release-card">
                        <div class="card-header">
                            <i class="fas fa-history me-2"></i> Payment & Interest History
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
                                            <th>Penalty</th>
                                            <th>Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($payment_history as $payment): ?>
                                        <tr>
                                            <td><?php echo date('d-m-Y', strtotime($payment['payment_date'])); ?></td>
                                            <td><?php echo h($payment['payment_type']); ?></td>
                                            <td><?php echo h($payment['receipt_no']); ?></td>
                                            <td>₹<?php echo money($payment['principal_amount']); ?></td>
                                            <td>₹<?php echo money($payment['interest_amount']); ?></td>
                                            <td>₹<?php echo money($payment['penalty_amount']); ?></td>
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
                                            <td>₹<?php echo money($interest['penalty_amount']); ?></td>
                                            <td><strong>₹<?php echo money($interest['net_amount']); ?></strong></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr>
                                            <td colspan="3" class="text-end"><strong>Totals:</strong></td>
                                            <td><strong>₹<?php echo money($pawn['loan_amount'] - $pawn['principal_balance']); ?></strong></td>
                                            <td><strong>₹<?php echo money($total_interest); ?></strong></td>
                                            <td colspan="2"></td>
                                        </tr>
                                    </tfoot>
                                <table>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php elseif ($pawn_id > 0 && !$pawn): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Pawn entry not found or is already released/closed.
                        <a href="pawn-list.php" class="alert-link">View active pawns</a>
                    </div>
                    <?php endif; ?>
                    
                </div>
            </div>
            <?php include('includes/footer.php'); ?>
        </div>
    </div>
    
    <?php include('includes/scripts.php'); ?>
    
    <script>
        let pawnData = <?php echo json_encode($active_pawns); ?>;
        let originalInterest = <?php echo $pending_interest; ?>;
        let originalPenalty = <?php echo $release_penalty; ?>;
        let originalPrincipal = <?php echo $release_principal; ?>;
        
        function searchPawn() {
            const searchTerm = document.getElementById('searchInput').value.toLowerCase().trim();
            const resultsDiv = document.getElementById('searchResults');
            
            if (searchTerm.length < 2) {
                resultsDiv.style.display = 'none';
                return;
            }
            
            const filtered = pawnData.filter(pawn => 
                pawn.pawn_no.toLowerCase().includes(searchTerm) || 
                pawn.customer_name.toLowerCase().includes(searchTerm) ||
                (pawn.customer_mobile && pawn.customer_mobile.includes(searchTerm))
            );
            
            if (filtered.length === 0) {
                resultsDiv.innerHTML = '<div class="p-3 text-center text-muted">No results found</div>';
                resultsDiv.style.display = 'block';
                return;
            }
            
            let html = '';
            filtered.forEach(pawn => {
                html += `
                    <div class="search-result-item" onclick="selectPawn(${pawn.id})">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <strong>${escapeHtml(pawn.pawn_no)}</strong><br>
                                <small>${escapeHtml(pawn.customer_name)}</small>
                            </div>
                            <div class="text-end">
                                <span class="badge bg-warning">₹${formatMoney(pawn.principal_balance)}</span>
                                <br>
                                <small class="text-muted">${pawn.customer_mobile || 'No mobile'}</small>
                            </div>
                        </div>
                    </div>
                `;
            });
            
            resultsDiv.innerHTML = html;
            resultsDiv.style.display = 'block';
        }
        
        function selectPawn(pawnId) {
            window.location.href = `pawn-release.php?id=${pawnId}`;
        }
        
        function calculateTotal() {
            const principal = parseFloat(document.getElementById('principal_amount').value) || 0;
            const interest = parseFloat(document.getElementById('interest_amount').value) || 0;
            const penalty = parseFloat(document.getElementById('penalty_amount').value) || 0;
            const discount = parseFloat(document.getElementById('discount_amount').value) || 0;
            
            let total = principal + interest + penalty - discount;
            total = Math.max(0, total);
            
            document.getElementById('total_amount').value = total.toFixed(2);
            document.getElementById('total_amount_display').innerHTML = '₹' + total.toFixed(2);
        }
        
        function toggleWaiveInterest() {
            const waiveCheckbox = document.getElementById('waive_interest');
            const interestField = document.getElementById('interest_amount');
            
            if (waiveCheckbox.checked) {
                interestField.value = '0';
                interestField.readOnly = true;
            } else {
                interestField.value = originalInterest.toFixed(2);
                interestField.readOnly = false;
            }
            calculateTotal();
        }
        
        function toggleWaivePenalty() {
            const waiveCheckbox = document.getElementById('waive_penalty');
            const penaltyField = document.getElementById('penalty_amount');
            
            if (waiveCheckbox.checked) {
                penaltyField.value = '0';
                penaltyField.readOnly = true;
            } else {
                penaltyField.value = originalPenalty.toFixed(2);
                penaltyField.readOnly = false;
            }
            calculateTotal();
        }
        
        function resetToCalculated() {
            document.getElementById('interest_amount').value = originalInterest.toFixed(2);
            document.getElementById('penalty_amount').value = originalPenalty.toFixed(2);
            document.getElementById('discount_amount').value = '0';
            document.getElementById('waive_interest').checked = false;
            document.getElementById('waive_penalty').checked = false;
            document.getElementById('interest_amount').readOnly = false;
            document.getElementById('penalty_amount').readOnly = false;
            calculateTotal();
        }
        
        function validateReleaseForm() {
            const total = parseFloat(document.getElementById('total_amount').value) || 0;
            if (total <= 0) {
                alert('Please enter a valid release amount');
                return false;
            }
            
            const principal = parseFloat(document.getElementById('principal_amount').value) || 0;
            if (principal != originalPrincipal) {
                alert('Principal amount has been modified. Please reset to calculated value.');
                return false;
            }
            
            return confirm('⚠️ CONFIRM RELEASE\n\n' +
                'Are you sure you want to release this pawn?\n\n' +
                'Pawn Number: <?php echo h($pawn['pawn_no'] ?? ''); ?>\n' +
                'Customer: <?php echo h($pawn['customer_name'] ?? ''); ?>\n' +
                'Total Amount: ₹' + parseFloat(document.getElementById('total_amount').value || 0).toFixed(2) + '\n\n' +
                'This action cannot be undone once confirmed!');
        }
        
        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/[&<>]/g, function(m) {
                if (m === '&') return '&amp;';
                if (m === '<') return '&lt;';
                if (m === '>') return '&gt;';
                return m;
            });
        }
        
        function formatMoney(amount) {
            return parseFloat(amount).toFixed(2);
        }
        
        // Live search on input
        document.getElementById('searchInput')?.addEventListener('input', function() {
            if (this.value.length >= 2) {
                searchPawn();
            } else {
                document.getElementById('searchResults').style.display = 'none';
            }
        });
        
        // Close search results when clicking outside
        document.addEventListener('click', function(event) {
            const searchInput = document.getElementById('searchInput');
            const resultsDiv = document.getElementById('searchResults');
            if (searchInput && resultsDiv && !searchInput.contains(event.target) && !resultsDiv.contains(event.target)) {
                resultsDiv.style.display = 'none';
            }
        });
        
        // Initialize
        calculateTotal();
    </script>
</body>
</html>