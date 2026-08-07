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
$currentPage = 'pawn-payment';

// Get pawn ID from URL or POST
$pawn_id = (int)($_GET['pawn_id'] ?? $_POST['pawn_id'] ?? 0);

// Fetch active pawn entries for dropdown
$active_pawns = [];
$active_query = "SELECT id, pawn_no, customer_name, customer_mobile, loan_amount, principal_balance, 
                        interest_rate, entry_date, status 
                 FROM pawn_entries 
                 WHERE business_id = {$businessId} AND status IN ('Active', 'Partially Paid') 
                 ORDER BY id DESC";
$active_result = $conn->query($active_query);
if ($active_result) {
    while ($row = $active_result->fetch_assoc()) {
        $active_pawns[] = $row;
    }
}

// Get selected pawn details
$selected_pawn = null;
if ($pawn_id > 0) {
    $pawn_query = "SELECT pe.*, c.customer_name as full_name, c.mobile, c.email 
                   FROM pawn_entries pe
                   LEFT JOIN customers c ON pe.customer_id = c.id
                   WHERE pe.id = {$pawn_id} AND pe.business_id = {$businessId}";
    $pawn_result = $conn->query($pawn_query);
    if ($pawn_result && $pawn_result->num_rows > 0) {
        $selected_pawn = $pawn_result->fetch_assoc();
    }
}

// Get payment history for selected pawn
$payment_history = [];
$total_paid = 0;
$total_interest_paid = 0;

if ($pawn_id > 0) {
    $history_query = "SELECT * FROM pawn_payments 
                      WHERE pawn_id = {$pawn_id} AND business_id = {$businessId} 
                      ORDER BY payment_date DESC, id DESC LIMIT 20";
    $history_result = $conn->query($history_query);
    if ($history_result) {
        while ($row = $history_result->fetch_assoc()) {
            $payment_history[] = $row;
            $total_paid += $row['principal_amount'];
            $total_interest_paid += $row['interest_amount'] ?? 0;
        }
    }
}

// Process form submission
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_payment'])) {
    $pawn_id = (int)$_POST['pawn_id'];
    $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
    $payment_type = $_POST['payment_type'] ?? 'Part Payment';
    $principal_amount = (float)($_POST['principal_amount'] ?? 0);
    $interest_amount = (float)($_POST['interest_amount'] ?? 0);
    $penalty_amount = (float)($_POST['penalty_amount'] ?? 0);
    $charges_amount = (float)($_POST['charges_amount'] ?? 0);
    $discount_amount = (float)($_POST['discount_amount'] ?? 0);
    $total_amount = (float)($_POST['total_amount'] ?? 0);
    $payment_method_id = !empty($_POST['payment_method_id']) ? (int)$_POST['payment_method_id'] : null;
    $reference_no = trim($_POST['reference_no'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');
    
    $errors = [];
    
    if ($pawn_id <= 0) {
        $errors[] = "Please select a pawn entry";
    }
    if ($principal_amount <= 0 && $interest_amount <= 0 && $penalty_amount <= 0) {
        $errors[] = "Please enter at least one payment amount";
    }
    if ($total_amount <= 0) {
        $errors[] = "Total amount must be greater than 0";
    }
    
    // Get current balance
    $balance_query = $conn->query("SELECT principal_balance FROM pawn_entries WHERE id = {$pawn_id} AND business_id = {$businessId}");
    if ($balance_query && $balance_query->num_rows > 0) {
        $current_balance = $balance_query->fetch_assoc()['principal_balance'];
        if ($principal_amount > $current_balance) {
            $errors[] = "Principal amount cannot exceed outstanding balance of ₹" . money($current_balance);
        }
    }
    
    if (empty($errors)) {
        $receipt_no = nextNo($conn, 'pawn_payments', 'receipt_no', 'PAY', $businessId);
        
        $conn->begin_transaction();
        
        try {
            // Insert payment record
            $insert_sql = "INSERT INTO pawn_payments (
                business_id, pawn_id, receipt_no, payment_date, payment_type,
                principal_amount, interest_amount, penalty_amount, charges_amount,
                discount_amount, total_amount, payment_method_id, reference_no,
                remarks, created_by, created_at
            ) VALUES (
                {$businessId}, {$pawn_id}, '{$receipt_no}', '{$payment_date}', '{$payment_type}',
                {$principal_amount}, {$interest_amount}, {$penalty_amount}, {$charges_amount},
                {$discount_amount}, {$total_amount}, " . ($payment_method_id ?: 'NULL') . ", 
                '" . $conn->real_escape_string($reference_no) . "',
                '" . $conn->real_escape_string($remarks) . "', {$userId}, NOW()
            )";
            
            if (!$conn->query($insert_sql)) {
                throw new Exception("Failed to save payment: " . $conn->error);
            }
            
            // Update pawn entry balance
            $new_balance = $current_balance - $principal_amount;
            $new_status = ($new_balance <= 0) ? 'Released' : 'Partially Paid';
            
            $update_sql = "UPDATE pawn_entries SET 
                          principal_balance = GREATEST(0, {$new_balance}),
                          status = '{$new_status}',
                          updated_at = NOW()";
            
            if ($new_balance <= 0) {
                $update_sql .= ", released_at = NOW()";
            }
            
            $update_sql .= " WHERE id = {$pawn_id}";
            
            if (!$conn->query($update_sql)) {
                throw new Exception("Failed to update pawn balance: " . $conn->error);
            }
            
            $conn->commit();
            $success = "Payment recorded successfully! Receipt No: {$receipt_no}";
            
            // Refresh data
            header("Location: pawn-payment.php?pawn_id={$pawn_id}&success=1");
            exit;
            
        } catch (Exception $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    } else {
        $error = implode("<br>", $errors);
    }
}

// Check for success parameter
if (isset($_GET['success']) && $_GET['success'] == 1) {
    $success = "Payment recorded successfully!";
}
?>
<!doctype html>
<html lang="en">
<?php include('includes/head.php'); ?>
<title>Pawn Payment | Pawn Broking</title>
<style>
    .payment-card { border-radius: 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 24px; }
    .payment-card .card-header { background: #f8fafc; border-bottom: 1px solid #eef2f6; padding: 16px 20px; font-weight: 600; }
    .payment-card .card-body { padding: 20px; }
    .info-row { display: flex; padding: 8px 0; border-bottom: 1px solid #f0f0f0; }
    .info-label { width: 140px; font-weight: 600; color: #4b5563; }
    .info-value { flex: 1; color: #1f2937; }
    .summary-box { background: #f8fafc; border-radius: 12px; padding: 15px; text-align: center; }
    .summary-value { font-size: 22px; font-weight: 700; }
    .summary-label { font-size: 12px; color: #6c757d; margin-top: 5px; }
    .status-badge { padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 500; display: inline-block; }
    .status-active { background: #dbeafe; color: #1e40af; }
    .status-partially-paid { background: #fef3c7; color: #92400e; }
    .quick-amount { cursor: pointer; padding: 4px 8px; background: #e5e7eb; border-radius: 6px; font-size: 12px; margin-right: 5px; display: inline-block; }
    .quick-amount:hover { background: #d1d5db; }
    .search-result-item { cursor: pointer; padding: 10px; border-bottom: 1px solid #e5e7eb; transition: background 0.2s; }
    .search-result-item:hover { background: #f3f4f6; }
    .search-result-item.active { background: #e0e7ff; }
    .pawn-card-selected { background: #f0fdf4; border: 2px solid #22c55e; }
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
                                    <h4 class="mb-1">Pawn Payment</h4>
                                    <p class="text-muted mb-0">Record principal and interest payments for pawn loans</p>
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
                    <div class="card payment-card">
                        <div class="card-header">
                            <i class="fas fa-search me-2"></i> Search Pawn Entry
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
                                        <?php foreach ($active_pawns as $pawn): ?>
                                            <option value="<?php echo $pawn['id']; ?>" data-pawn='<?php echo json_encode($pawn); ?>' <?php echo ($pawn_id == $pawn['id']) ? 'selected' : ''; ?>>
                                                <?php echo h($pawn['pawn_no']); ?> - <?php echo h($pawn['customer_name']); ?> (₹<?php echo money($pawn['principal_balance']); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <?php if ($selected_pawn || $pawn_id > 0): ?>
                    <!-- Selected Pawn Details -->
                    <div class="card payment-card" id="pawnDetailsCard">
                        <div class="card-header">
                            <i class="fas fa-info-circle me-2"></i> Selected Pawn Details
                            <button type="button" class="btn btn-sm btn-outline-danger float-end" onclick="clearSelection()">
                                <i class="fas fa-times"></i> Clear
                            </button>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="summary-box">
                                        <div class="summary-value"><?php echo h($selected_pawn['pawn_no'] ?? 'N/A'); ?></div>
                                        <div class="summary-label">Pawn Number</div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="summary-box">
                                        <div class="summary-value text-primary">₹<?php echo money($selected_pawn['principal_balance'] ?? 0); ?></div>
                                        <div class="summary-label">Outstanding Balance</div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="summary-box">
                                        <div class="summary-value text-success">₹<?php echo money($total_paid); ?></div>
                                        <div class="summary-label">Total Paid</div>
                                    </div>
                                </div>
                            </div>
                            <div class="row mt-3">
                                <div class="col-md-6">
                                    <div class="info-row">
                                        <div class="info-label">Customer Name:</div>
                                        <div class="info-value"><strong><?php echo h($selected_pawn['customer_name'] ?? ''); ?></strong></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Mobile:</div>
                                        <div class="info-value"><?php echo h($selected_pawn['mobile'] ?? $selected_pawn['customer_mobile'] ?? 'N/A'); ?></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Entry Date:</div>
                                        <div class="info-value"><?php echo isset($selected_pawn['entry_date']) ? date('d-m-Y', strtotime($selected_pawn['entry_date'])) : 'N/A'; ?></div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="info-row">
                                        <div class="info-label">Original Loan:</div>
                                        <div class="info-value">₹<?php echo money($selected_pawn['loan_amount'] ?? 0); ?></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Interest Rate:</div>
                                        <div class="info-value"><?php echo h($selected_pawn['interest_rate'] ?? 0); ?>%</div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Status:</div>
                                        <div class="info-value">
                                            <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $selected_pawn['status'] ?? 'Active')); ?>">
                                                <?php echo h($selected_pawn['status'] ?? 'Active'); ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Payment Form -->
                    <div class="card payment-card">
                        <div class="card-header">
                            <i class="fas fa-rupee-sign me-2"></i> Payment Entry
                        </div>
                        <div class="card-body">
                            <form method="POST" id="paymentForm" onsubmit="return validatePaymentForm()">
                                <input type="hidden" name="submit_payment" value="1">
                                <input type="hidden" name="pawn_id" id="pawn_id" value="<?php echo $pawn_id; ?>">
                                
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label required">Payment Date</label>
                                        <input type="date" name="payment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label required">Payment Type</label>
                                        <select name="payment_type" id="payment_type" class="form-select" onchange="togglePaymentType()">
                                            <option value="Part Payment">Part Payment</option>
                                            <option value="Interest Only">Interest Only</option>
                                            <option value="Settlement">Settlement</option>
                                            <option value="Release">Full Release</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Payment Method</label>
                                        <select name="payment_method_id" class="form-select">
                                            <option value="">-- Select --</option>
                                            <?php
                                            $pm_query = "SELECT id, method_name FROM payment_methods WHERE is_active = 1 ORDER BY method_name";
                                            $pm_result = $conn->query($pm_query);
                                            if ($pm_result) {
                                                while ($pm = $pm_result->fetch_assoc()) {
                                                    echo '<option value="' . $pm['id'] . '">' . h($pm['method_name']) . '</option>';
                                                }
                                            }
                                            ?>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="row g-3 mt-2">
                                    <div class="col-md-3">
                                        <label class="form-label">Principal Amount (₹)</label>
                                        <div class="input-group">
                                            <input type="number" step="0.01" name="principal_amount" id="principal_amount" class="form-control" value="0" oninput="calculateTotal()">
                                            <button type="button" class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">Quick</button>
                                            <ul class="dropdown-menu">
                                                <li><a class="dropdown-item" href="#" onclick="setQuickAmount('principal', 500); return false;">₹500</a></li>
                                                <li><a class="dropdown-item" href="#" onclick="setQuickAmount('principal', 1000); return false;">₹1,000</a></li>
                                                <li><a class="dropdown-item" href="#" onclick="setQuickAmount('principal', 5000); return false;">₹5,000</a></li>
                                                <li><a class="dropdown-item" href="#" onclick="setQuickAmount('principal', 10000); return false;">₹10,000</a></li>
                                                <li><hr class="dropdown-divider"></li>
                                                <li><a class="dropdown-item" href="#" onclick="setFullSettlement(); return false;">Full Settlement</a></li>
                                            </ul>
                                        </div>
                                        <small class="text-muted">Balance: ₹<?php echo money($selected_pawn['principal_balance'] ?? 0); ?></small>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Interest Amount (₹)</label>
                                        <input type="number" step="0.01" name="interest_amount" id="interest_amount" class="form-control" value="0" oninput="calculateTotal()">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Penalty (₹)</label>
                                        <input type="number" step="0.01" name="penalty_amount" id="penalty_amount" class="form-control" value="0" oninput="calculateTotal()">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Charges (₹)</label>
                                        <input type="number" step="0.01" name="charges_amount" id="charges_amount" class="form-control" value="0" oninput="calculateTotal()">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Discount (₹)</label>
                                        <input type="number" step="0.01" name="discount_amount" id="discount_amount" class="form-control" value="0" oninput="calculateTotal()">
                                    </div>
                                </div>
                                
                                <div class="row g-3 mt-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Reference No (Cheque/UTR)</label>
                                        <input type="text" name="reference_no" class="form-control" placeholder="Optional">
                                    </div>
                                    <div class="col-md-8">
                                        <label class="form-label">Remarks</label>
                                        <input type="text" name="remarks" class="form-control" placeholder="Any notes">
                                    </div>
                                </div>
                                
                                <div class="row g-3 mt-3">
                                    <div class="col-md-12">
                                        <div class="alert alert-info">
                                            <div class="row align-items-center">
                                                <div class="col-md-6">
                                                    <strong>Total Amount: </strong>
                                                    <span style="font-size: 28px; font-weight: 700; color: #059669;" id="total_amount_display">₹0.00</span>
                                                    <input type="hidden" name="total_amount" id="total_amount" value="0">
                                                </div>
                                                <div class="col-md-6 text-end">
                                                    <button type="button" class="btn btn-success" onclick="setFullSettlement()">
                                                        <i class="fas fa-check-circle"></i> Full Settlement (₹<?php echo money($selected_pawn['principal_balance'] ?? 0); ?>)
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row mt-4">
                                    <div class="col-12 text-end">
                                        <button type="reset" class="btn btn-light me-2" onclick="resetForm()">
                                            <i class="fas fa-undo"></i> Reset
                                        </button>
                                        <button type="submit" class="btn btn-primary btn-lg">
                                            <i class="fas fa-save"></i> Record Payment
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Payment History -->
                    <?php if (!empty($payment_history)): ?>
                    <div class="card payment-card">
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
                                            <th>Total</th>
                                            <th>Method</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($payment_history as $payment): ?>
                                        <tr>
                                            <td><strong><?php echo h($payment['receipt_no']); ?></strong></td>
                                            <td><?php echo date('d-m-Y', strtotime($payment['payment_date'])); ?></td>
                                            <td><?php echo h($payment['payment_type']); ?></td>
                                            <td>₹<?php echo money($payment['principal_amount']); ?></td>
                                            <td>₹<?php echo money($payment['interest_amount']); ?></td>
                                            <td>₹<?php echo money($payment['penalty_amount']); ?></td>
                                            <td><strong class="text-success">₹<?php echo money($payment['total_amount']); ?></strong></td>
                                            <td>
                                                <?php 
                                                $method_name = '';
                                                if (!empty($payment['payment_method_id'])) {
                                                    $m_sql = "SELECT method_name FROM payment_methods WHERE id = {$payment['payment_method_id']}";
                                                    $m_res = $conn->query($m_sql);
                                                    if ($m_res && $m_res->num_rows > 0) {
                                                        $method_name = $m_res->fetch_assoc()['method_name'];
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
                                            <td colspan="3" class="text-end"><strong>Totals:</strong></td>
                                            <td><strong>₹<?php echo money($total_paid); ?></strong></td>
                                            <td><strong>₹<?php echo money($total_interest_paid); ?></strong></td>
                                            <td colspan="3"></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php else: ?>
                    <!-- No Pawn Selected Message -->
                    <div class="alert alert-info text-center">
                        <i class="fas fa-info-circle fa-2x mb-2 d-block"></i>
                        <h5>No Pawn Entry Selected</h5>
                        <p>Please search and select a pawn entry to make a payment.</p>
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
        let currentBalance = <?php echo $selected_pawn['principal_balance'] ?? 0; ?>;
        
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
            // Redirect to same page with selected pawn
            window.location.href = `pawn-payment.php?pawn_id=${pawnId}`;
        }
        
        function clearSelection() {
            window.location.href = 'pawn-payment.php';
        }
        
        function calculateTotal() {
            const principal = parseFloat(document.getElementById('principal_amount').value) || 0;
            const interest = parseFloat(document.getElementById('interest_amount').value) || 0;
            const penalty = parseFloat(document.getElementById('penalty_amount').value) || 0;
            const charges = parseFloat(document.getElementById('charges_amount').value) || 0;
            const discount = parseFloat(document.getElementById('discount_amount').value) || 0;
            
            let total = principal + interest + penalty + charges - discount;
            total = Math.max(0, total);
            
            document.getElementById('total_amount').value = total.toFixed(2);
            document.getElementById('total_amount_display').innerHTML = '₹' + total.toFixed(2);
            
            // Validate principal
            if (principal > currentBalance) {
                document.getElementById('principal_amount').classList.add('is-invalid');
            } else {
                document.getElementById('principal_amount').classList.remove('is-invalid');
            }
        }
        
        function setQuickAmount(field, amount) {
            const currentValue = parseFloat(document.getElementById(field + '_amount').value) || 0;
            let newValue = currentValue + amount;
            
            if (field === 'principal' && newValue > currentBalance) {
                newValue = currentBalance;
            }
            
            document.getElementById(field + '_amount').value = newValue.toFixed(2);
            calculateTotal();
        }
        
        function setFullSettlement() {
            document.getElementById('principal_amount').value = currentBalance.toFixed(2);
            document.getElementById('payment_type').value = 'Release';
            calculateTotal();
            
            // Highlight the field
            const principalField = document.getElementById('principal_amount');
            principalField.style.backgroundColor = '#e8f5e9';
            setTimeout(() => {
                principalField.style.backgroundColor = '';
            }, 1000);
        }
        
        function togglePaymentType() {
            const paymentType = document.getElementById('payment_type').value;
            if (paymentType === 'Release') {
                setFullSettlement();
            } else if (paymentType === 'Interest Only') {
                document.getElementById('principal_amount').value = '0';
                calculateTotal();
            }
        }
        
        function validatePaymentForm() {
            const total = parseFloat(document.getElementById('total_amount').value) || 0;
            if (total <= 0) {
                alert('Please enter a valid payment amount (greater than 0)');
                return false;
            }
            
            const principal = parseFloat(document.getElementById('principal_amount').value) || 0;
            if (principal > currentBalance) {
                alert('Principal amount cannot exceed outstanding balance');
                return false;
            }
            
            return confirm('Are you sure you want to record this payment?\n\n' +
                'Principal: ₹' + parseFloat(document.getElementById('principal_amount').value || 0).toFixed(2) + '\n' +
                'Interest: ₹' + parseFloat(document.getElementById('interest_amount').value || 0).toFixed(2) + '\n' +
                'Penalty: ₹' + parseFloat(document.getElementById('penalty_amount').value || 0).toFixed(2) + '\n' +
                'Total: ₹' + parseFloat(document.getElementById('total_amount').value || 0).toFixed(2));
        }
        
        function resetForm() {
            if (confirm('Reset all payment fields?')) {
                document.getElementById('principal_amount').value = '0';
                document.getElementById('interest_amount').value = '0';
                document.getElementById('penalty_amount').value = '0';
                document.getElementById('charges_amount').value = '0';
                document.getElementById('discount_amount').value = '0';
                document.getElementById('reference_no').value = '';
                document.getElementById('remarks').value = '';
                calculateTotal();
            }
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
            const searchTerm = this.value;
            if (searchTerm.length >= 2) {
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
        
        // Set current balance from PHP
        currentBalance = <?php echo $selected_pawn['principal_balance'] ?? 0; ?>;
    </script>
</body>
</html>