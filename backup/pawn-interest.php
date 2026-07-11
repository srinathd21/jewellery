<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/config.php';

if (!$conn || $conn->connect_error) {
    die('Database connection failed: ' . ($conn->connect_error ?? 'config error'));
}

$business_id = (int)($_SESSION['business_id'] ?? 1);
$created_by = (int)($_SESSION['user_id'] ?? 0);
$currentPage = 'pawn-interest';

// Helper functions
if (!function_exists('h')) {
    function h($string) { return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('money')) {
    function money($amount) { return number_format((float)$amount, 2); }
}

// Get pawn ID from URL
$pawn_id = (int)($_GET['pawn_id'] ?? 0);
$pawn = null;

if ($pawn_id > 0) {
    // Fetch pawn entry details
    $sql = "SELECT pe.*, c.customer_name, c.mobile 
            FROM pawn_entries pe
            LEFT JOIN customers c ON pe.customer_id = c.id
            WHERE pe.id = $pawn_id AND pe.business_id = $business_id AND pe.status IN ('Active', 'Partially Paid')";
    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) {
        $pawn = $result->fetch_assoc();
    }
}

// If no pawn found, show error or redirect
if (!$pawn && $pawn_id > 0) {
    header('Location: pawn-list.php?error=invalid_pawn');
    exit;
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

// Calculate interest functions
function calculateSimpleInterest($principal, $rate, $days) {
    // Simple Interest = P * R * T / (100 * 365)
    return ($principal * $rate * $days) / (100 * 365);
}

function calculateCompoundInterest($principal, $rate, $months) {
    // Monthly compounding
    $monthly_rate = $rate / (12 * 100);
    return $principal * pow(1 + $monthly_rate, $months) - $principal;
}

// Get last interest collection date
$last_interest_date = null;
$last_interest_sql = "SELECT MAX(interest_to) as last_date FROM pawn_interest_collections 
                      WHERE pawn_id = $pawn_id AND business_id = $business_id";
$last_interest_res = $conn->query($last_interest_sql);
if ($last_interest_res && $last_interest_res->num_rows > 0) {
    $row = $last_interest_res->fetch_assoc();
    if ($row['last_date']) {
        $last_interest_date = $row['last_date'];
    }
}

// Set default from date
$default_from_date = $last_interest_date ?: $pawn['entry_date'];
$default_to_date = date('Y-m-d');

// Get existing interest collections for this pawn
$existing_interests = [];
$existing_sql = "SELECT * FROM pawn_interest_collections 
                 WHERE pawn_id = $pawn_id AND business_id = $business_id 
                 ORDER BY collection_date DESC";
$existing_res = $conn->query($existing_sql);
if ($existing_res) {
    while ($row = $existing_res->fetch_assoc()) {
        $existing_interests[] = $row;
    }
}

// Calculate total interest collected so far
$total_interest_collected = 0;
foreach ($existing_interests as $interest) {
    $total_interest_collected += $interest['interest_amount'];
}

// Process form submission
$success_msg = '';
$error_msg = '';
$calculated_interest = 0;
$calculated_penalty = 0;
$days_count = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_interest'])) {
    $interest_from = $conn->real_escape_string($_POST['interest_from'] ?? '');
    $interest_to = $conn->real_escape_string($_POST['interest_to'] ?? '');
    $interest_amount = (float)($_POST['interest_amount'] ?? 0);
    $penalty_amount = (float)($_POST['penalty_amount'] ?? 0);
    $discount_amount = (float)($_POST['discount_amount'] ?? 0);
    $net_amount = (float)($_POST['net_amount'] ?? 0);
    $payment_method_id = !empty($_POST['payment_method_id']) ? (int)$_POST['payment_method_id'] : null;
    $reference_no = $conn->real_escape_string(trim($_POST['reference_no'] ?? ''));
    $remarks = $conn->real_escape_string(trim($_POST['remarks'] ?? ''));
    
    // Calculate days between dates
    $date1 = new DateTime($interest_from);
    $date2 = new DateTime($interest_to);
    $days_count = $date1->diff($date2)->days + 1;
    
    $errors = [];
    
    if (empty($interest_from) || empty($interest_to)) {
        $errors[] = "Please select interest period";
    }
    if ($interest_amount <= 0 && $net_amount <= 0) {
        $errors[] = "Interest amount must be greater than 0";
    }
    
    // Generate receipt number
    $receipt_no = '';
    $prefix = 'INT';
    $receipt_sql = "SELECT MAX(CAST(SUBSTRING(receipt_no, 4) AS UNSIGNED)) as max_no 
                    FROM pawn_interest_collections WHERE business_id = $business_id";
    $receipt_res = $conn->query($receipt_sql);
    $max_no = 0;
    if ($receipt_res && ($row = $receipt_res->fetch_assoc())) {
        $max_no = (int)($row['max_no'] ?? 0);
    }
    $receipt_no = $prefix . str_pad($max_no + 1, 6, '0', STR_PAD_LEFT);
    
    if (empty($errors)) {
        $insert_sql = "INSERT INTO pawn_interest_collections (
            business_id, pawn_id, receipt_no, collection_date, interest_from, interest_to,
            days_count, interest_amount, penalty_amount, discount_amount, net_amount,
            payment_method_id, reference_no, remarks, created_by, created_at
        ) VALUES (
            $business_id, $pawn_id, '$receipt_no', CURDATE(), '$interest_from', '$interest_to',
            $days_count, $interest_amount, $penalty_amount, $discount_amount, $net_amount,
            " . ($payment_method_id ?: 'NULL') . ", '$reference_no', '$remarks', $created_by, NOW()
        )";
        
        if ($conn->query($insert_sql)) {
            $success_msg = "Interest collected successfully! Receipt No: $receipt_no";
            
            // Refresh the page to show new entry
            echo '<script>setTimeout(function(){ window.location.href = "pawn-interest.php?pawn_id=' . $pawn_id . '&success=1"; }, 1500);</script>';
        } else {
            $error_msg = "Failed to save interest collection: " . $conn->error;
        }
    } else {
        $error_msg = implode("<br>", $errors);
    }
}

// Calculate preview interest
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['calculate'])) {
    $preview_from = $conn->real_escape_string($_POST['preview_from'] ?? '');
    $preview_to = $conn->real_escape_string($_POST['preview_to'] ?? '');
    
    if ($preview_from && $preview_to) {
        $date1 = new DateTime($preview_from);
        $date2 = new DateTime($preview_to);
        $days_count = $date1->diff($date2)->days + 1;
        
        $principal = $pawn['principal_balance'];
        $rate = $pawn['interest_rate'];
        
        if ($pawn['interest_method'] == 'Simple') {
            $calculated_interest = calculateSimpleInterest($principal, $rate, $days_count);
        } else {
            $months = $days_count / 30;
            $calculated_interest = calculateCompoundInterest($principal, $rate, $months);
        }
        
        // Calculate penalty if overdue (10% of interest as penalty)
        $today = new DateTime();
        $maturity_date = new DateTime($pawn['maturity_date']);
        if ($maturity_date < $today) {
            $calculated_penalty = $calculated_interest * 0.10;
        }
    }
}
?>
<!doctype html>
<html lang="en">
<?php include('includes/head.php'); ?>
<title>Interest Collection | Pawn Broking</title>
<style>
    .interest-card { border-radius: 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 24px; }
    .interest-card .card-header { background: #f8fafc; border-bottom: 1px solid #eef2f6; padding: 16px 20px; font-weight: 600; }
    .interest-card .card-body { padding: 20px; }
    .info-row { display: flex; padding: 8px 0; border-bottom: 1px solid #f0f0f0; }
    .info-label { width: 140px; font-weight: 600; color: #4b5563; }
    .info-value { flex: 1; color: #1f2937; }
    .calculation-box { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 12px; padding: 16px; margin-top: 16px; }
    .calculation-item { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px dashed #ddd; }
    .calculation-total { font-size: 18px; font-weight: 700; color: #059669; }
    .status-badge { padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 500; display: inline-block; }
    .status-active { background: #dbeafe; color: #1e40af; }
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
                                    <h4 class="mb-1">Interest Collection</h4>
                                    <p class="text-muted mb-0">Collect interest payments for pawn broking loans</p>
                                </div>
                                <div>
                                    <a href="pawn-list.php" class="btn btn-outline-secondary">
                                        <i class="fas fa-arrow-left"></i> Back to Pawn List
                                    </a>
                                    <?php if ($pawn_id > 0): ?>
                                        <a href="pawn-view.php?id=<?php echo $pawn_id; ?>" class="btn btn-outline-info">
                                            <i class="fas fa-eye"></i> View Pawn Details
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <?php if (isset($_GET['success']) && $_GET['success'] == 1): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="fas fa-check-circle me-2"></i> Interest collection recorded successfully!
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($success_msg): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="fas fa-check-circle me-2"></i> <?php echo $success_msg; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($error_msg): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="fas fa-exclamation-circle me-2"></i> <?php echo $error_msg; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!$pawn && $pawn_id > 0): ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            Pawn entry not found or is not active. <a href="pawn-list.php">View active pawns</a>
                        </div>
                    <?php elseif (!$pawn): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            Please select a pawn entry from the <a href="pawn-list.php">pawn list</a> to collect interest.
                        </div>
                    <?php else: ?>
                    
                    <!-- Pawn Information Summary -->
                    <div class="row">
                        <div class="col-md-6">
                            <div class="card interest-card">
                                <div class="card-header">
                                    <i class="fas fa-info-circle me-2"></i> Pawn Information
                                </div>
                                <div class="card-body">
                                    <div class="info-row">
                                        <div class="info-label">Pawn No:</div>
                                        <div class="info-value"><strong><?php echo h($pawn['pawn_no']); ?></strong></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Customer:</div>
                                        <div class="info-value"><?php echo h($pawn['customer_name']); ?> (<?php echo h($pawn['mobile']); ?>)</div>
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
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="card interest-card">
                                <div class="card-header">
                                    <i class="fas fa-chart-line me-2"></i> Loan Summary
                                </div>
                                <div class="card-body">
                                    <div class="info-row">
                                        <div class="info-label">Principal Balance:</div>
                                        <div class="info-value"><strong class="text-primary">₹<?php echo money($pawn['principal_balance']); ?></strong></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Interest Rate:</div>
                                        <div class="info-value"><?php echo h($pawn['interest_rate']); ?>% (<?php echo h($pawn['interest_method']); ?> Interest)</div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Interest Collected:</div>
                                        <div class="info-value">₹<?php echo money($total_interest_collected); ?></div>
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
                    
                    <!-- Interest Collection Form -->
                    <div class="card interest-card">
                        <div class="card-header">
                            <i class="fas fa-percent me-2"></i> Interest Collection Form
                        </div>
                        <div class="card-body">
                            <form method="POST" id="interestForm" onsubmit="return validateForm()">
                                <input type="hidden" name="submit_interest" value="1">
                                
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label required">Interest From Date</label>
                                        <input type="date" name="interest_from" id="interest_from" class="form-control" 
                                               value="<?php echo h($_POST['interest_from'] ?? $default_from_date); ?>" required>
                                        <small class="text-muted">Last collection: <?php echo $last_interest_date ? date('d-m-Y', strtotime($last_interest_date)) : 'No previous collection'; ?></small>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label required">Interest To Date</label>
                                        <input type="date" name="interest_to" id="interest_to" class="form-control" 
                                               value="<?php echo h($_POST['interest_to'] ?? $default_to_date); ?>" required>
                                    </div>
                                </div>
                                
                                <div class="row g-3 mt-2">
                                    <div class="col-md-3">
                                        <label class="form-label">Days Count</label>
                                        <input type="text" id="days_count" class="form-control" readonly style="background:#f0f0f0;" value="0">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Interest Amount (₹)</label>
                                        <input type="number" step="0.01" name="interest_amount" id="interest_amount" class="form-control" value="0" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Penalty Amount (₹)</label>
                                        <input type="number" step="0.01" name="penalty_amount" id="penalty_amount" class="form-control" value="0" onchange="calculateNet()">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Discount Amount (₹)</label>
                                        <input type="number" step="0.01" name="discount_amount" id="discount_amount" class="form-control" value="0" onchange="calculateNet()">
                                    </div>
                                </div>
                                
                                <div class="row g-3 mt-2">
                                    <div class="col-md-6">
                                        <label class="form-label">Net Amount (₹)</label>
                                        <input type="number" step="0.01" name="net_amount" id="net_amount" class="form-control" readonly style="background:#f0f0f0; font-weight:700;" value="0">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Payment Method</label>
                                        <select name="payment_method_id" class="form-select">
                                            <option value="">-- Select --</option>
                                            <?php foreach ($paymentMethods as $pm): ?>
                                                <option value="<?php echo $pm['id']; ?>"><?php echo h($pm['method_name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="row g-3 mt-2">
                                    <div class="col-md-6">
                                        <label class="form-label">Reference No (Cheque/UTR)</label>
                                        <input type="text" name="reference_no" class="form-control" placeholder="Optional">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Remarks</label>
                                        <input type="text" name="remarks" class="form-control" placeholder="Any notes">
                                    </div>
                                </div>
                                
                                <div class="row mt-4">
                                    <div class="col-12 text-end">
                                        <button type="button" class="btn btn-secondary me-2" onclick="calculateInterest()">
                                            <i class="fas fa-calculator"></i> Calculate Interest
                                        </button>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-save"></i> Collect Interest
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Interest Calculation Preview -->
                    <div class="card interest-card" id="calculationPreview" style="display: none;">
                        <div class="card-header">
                            <i class="fas fa-calculator me-2"></i> Interest Calculation Preview
                        </div>
                        <div class="card-body">
                            <div class="calculation-box">
                                <div class="calculation-item">
                                    <span>Principal Amount:</span>
                                    <strong>₹<?php echo money($pawn['principal_balance']); ?></strong>
                                </div>
                                <div class="calculation-item">
                                    <span>Interest Rate:</span>
                                    <span><?php echo h($pawn['interest_rate']); ?>% per annum</span>
                                </div>
                                <div class="calculation-item">
                                    <span>Period:</span>
                                    <span id="calc_period">-</span>
                                </div>
                                <div class="calculation-item">
                                    <span>Days Count:</span>
                                    <span id="calc_days">0</span>
                                </div>
                                <div class="calculation-item">
                                    <span>Calculated Interest:</span>
                                    <strong id="calc_interest">₹0.00</strong>
                                </div>
                                <div class="calculation-item">
                                    <span>Penalty (10% if overdue):</span>
                                    <strong id="calc_penalty">₹0.00</strong>
                                </div>
                                <div class="calculation-item calculation-total">
                                    <span>Total Amount:</span>
                                    <strong id="calc_total">₹0.00</strong>
                                </div>
                            </div>
                            <div class="text-center mt-3">
                                <button type="button" class="btn btn-success" onclick="applyCalculatedInterest()">
                                    <i class="fas fa-check"></i> Apply to Form
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Interest Collection History -->
                    <?php if (!empty($existing_interests)): ?>
                    <div class="card interest-card">
                        <div class="card-header">
                            <i class="fas fa-history me-2"></i> Interest Collection History
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Receipt No</th>
                                            <th>Collection Date</th>
                                            <th>Period (From - To)</th>
                                            <th>Days</th>
                                            <th>Interest</th>
                                            <th>Penalty</th>
                                            <th>Discount</th>
                                            <th>Net Amount</th>
                                            <th>Method</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($existing_interests as $interest): ?>
                                        <tr>
                                            <td><?php echo h($interest['receipt_no']); ?></td>
                                            <td><?php echo date('d-m-Y', strtotime($interest['collection_date'])); ?></td>
                                            <td><?php echo date('d-m-Y', strtotime($interest['interest_from'])); ?> to <?php echo date('d-m-Y', strtotime($interest['interest_to'])); ?></td>
                                            <td><?php echo (int)$interest['days_count']; ?> days</td>
                                            <td>₹<?php echo money($interest['interest_amount']); ?></td>
                                            <td>₹<?php echo money($interest['penalty_amount']); ?></td>
                                            <td>₹<?php echo money($interest['discount_amount']); ?></td>
                                            <td><strong>₹<?php echo money($interest['net_amount']); ?></strong></td>
                                            <td>
                                                <?php 
                                                $method_name = '';
                                                if ($interest['payment_method_id']) {
                                                    $method_sql = "SELECT method_name FROM payment_methods WHERE id = {$interest['payment_method_id']}";
                                                    $method_res = $conn->query($method_sql);
                                                    if ($method_res && $method_res->num_rows > 0) {
                                                        $method_name = $method_res->fetch_assoc()['method_name'];
                                                    }
                                                }
                                                echo h($method_name);
                                                ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr>
                                            <td colspan="4" class="text-end"><strong>Total:</strong></td>
                                            <td><strong>₹<?php echo money($total_interest_collected); ?></strong></td>
                                            <td colspan="4"></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php endif; ?>
                    
                </div>
            </div>
            <?php include('includes/footer.php'); ?>
        </div>
    </div>
    
    <?php include('includes/scripts.php'); ?>
    
    <script>
        // Calculate days between dates
        function calculateDays() {
            const fromDate = document.getElementById('interest_from').value;
            const toDate = document.getElementById('interest_to').value;
            
            if (fromDate && toDate) {
                const from = new Date(fromDate);
                const to = new Date(toDate);
                const diffTime = Math.abs(to - from);
                const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;
                document.getElementById('days_count').value = diffDays;
                return diffDays;
            }
            return 0;
        }
        
        // Calculate net amount
        function calculateNet() {
            const interest = parseFloat(document.getElementById('interest_amount').value) || 0;
            const penalty = parseFloat(document.getElementById('penalty_amount').value) || 0;
            const discount = parseFloat(document.getElementById('discount_amount').value) || 0;
            const net = interest + penalty - discount;
            document.getElementById('net_amount').value = net.toFixed(2);
        }
        
        // Calculate interest via AJAX
        function calculateInterest() {
            const fromDate = document.getElementById('interest_from').value;
            const toDate = document.getElementById('interest_to').value;
            const pawnId = <?php echo $pawn_id; ?>;
            
            if (!fromDate || !toDate) {
                alert('Please select both From and To dates');
                return;
            }
            
            if (new Date(fromDate) > new Date(toDate)) {
                alert('From date cannot be greater than To date');
                return;
            }
            
            // Show loading
            const btn = event.target;
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Calculating...';
            btn.disabled = true;
            
            fetch(`calculate_interest_ajax.php?pawn_id=${pawnId}&from=${fromDate}&to=${toDate}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('calc_period').innerHTML = `${formatDate(fromDate)} to ${formatDate(toDate)}`;
                        document.getElementById('calc_days').innerHTML = data.days;
                        document.getElementById('calc_interest').innerHTML = '₹' + data.interest.toFixed(2);
                        document.getElementById('calc_penalty').innerHTML = '₹' + data.penalty.toFixed(2);
                        document.getElementById('calc_total').innerHTML = '₹' + (data.interest + data.penalty).toFixed(2);
                        
                        // Store calculated values
                        window.calculatedInterest = data.interest;
                        window.calculatedPenalty = data.penalty;
                        window.calculatedDays = data.days;
                        
                        document.getElementById('calculationPreview').style.display = 'block';
                        document.getElementById('calculationPreview').scrollIntoView({ behavior: 'smooth' });
                    } else {
                        alert(data.message || 'Error calculating interest');
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert('Error calculating interest. Please try again.');
                })
                .finally(() => {
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                });
        }
        
        function applyCalculatedInterest() {
            if (window.calculatedInterest !== undefined) {
                document.getElementById('interest_amount').value = window.calculatedInterest.toFixed(2);
                document.getElementById('penalty_amount').value = window.calculatedPenalty.toFixed(2);
                document.getElementById('days_count').value = window.calculatedDays;
                calculateNet();
                
                // Hide preview
                document.getElementById('calculationPreview').style.display = 'none';
                
                // Show success message
                const alertDiv = document.createElement('div');
                alertDiv.className = 'alert alert-success alert-dismissible fade show mt-3';
                alertDiv.innerHTML = `
                    <i class="fas fa-check-circle me-2"></i> 
                    Interest calculated and applied! Total: ₹${(window.calculatedInterest + window.calculatedPenalty).toFixed(2)}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                `;
                document.querySelector('#interestForm').insertBefore(alertDiv, document.querySelector('#interestForm .row'));
                setTimeout(() => alertDiv.remove(), 3000);
            }
        }
        
        function formatDate(dateString) {
            const date = new Date(dateString);
            return date.toLocaleDateString('en-IN');
        }
        
        function validateForm() {
            const interestAmount = parseFloat(document.getElementById('interest_amount').value) || 0;
            if (interestAmount <= 0) {
                alert('Please enter a valid interest amount (greater than 0)');
                return false;
            }
            
            const fromDate = document.getElementById('interest_from').value;
            const toDate = document.getElementById('interest_to').value;
            
            if (new Date(fromDate) > new Date(toDate)) {
                alert('From date cannot be greater than To date');
                return false;
            }
            
            return true;
        }
        
        // Event listeners
        document.getElementById('interest_from')?.addEventListener('change', () => {
            calculateDays();
            calculateNet();
        });
        
        document.getElementById('interest_to')?.addEventListener('change', () => {
            calculateDays();
            calculateNet();
        });
        
        document.getElementById('interest_amount')?.addEventListener('input', calculateNet);
        document.getElementById('penalty_amount')?.addEventListener('input', calculateNet);
        document.getElementById('discount_amount')?.addEventListener('input', calculateNet);
        
        // Initial calculation
        calculateDays();
        calculateNet();
    </script>
</body>
</html>