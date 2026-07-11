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
$currentPage = 'pawn-entry';

// Helper functions
if (!function_exists('h')) {
    function h($string) { return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('money')) {
    function money($amount) { return number_format((float)$amount, 2); }
}

// Fetch customers for dropdown and search
$customers = [];
$customer_sql = "SELECT id, customer_code, customer_name, mobile, email, address_line1, address_line2, city, state, pincode, gstin 
                 FROM customers WHERE business_id = $business_id AND is_active = 1 
                 ORDER BY customer_name";
$cust_res = $conn->query($customer_sql);
if ($cust_res) {
    while ($row = $cust_res->fetch_assoc()) {
        $customers[] = $row;
    }
}

// Fetch pawn categories with their default values
$categories = [];
$cat_sql = "SELECT * FROM pawn_categories WHERE business_id = $business_id AND status = 'active' ORDER BY category_name";
$cat_res = $conn->query($cat_sql);
if ($cat_res) {
    while ($row = $cat_res->fetch_assoc()) {
        $categories[] = $row;
    }
}

// Fetch metal rates
$metal_rates = [];
$rate_sql = "SELECT metal_type, purity, rate_per_gram FROM metal_rates 
             WHERE business_id = $business_id 
             ORDER BY metal_type, purity";
$rate_res = $conn->query($rate_sql);
if ($rate_res) {
    while ($row = $rate_res->fetch_assoc()) {
        $metal_rates[$row['metal_type']][$row['purity']] = $row['rate_per_gram'];
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

// Get latest pawn number
$last_pawn_no = 'PN0001';
$last_sql = "SELECT pawn_no FROM pawn_entries WHERE business_id = $business_id ORDER BY id DESC LIMIT 1";
$last_res = $conn->query($last_sql);
if ($last_res && $last_res->num_rows > 0) {
    $last = $last_res->fetch_assoc();
    $num = (int)substr($last['pawn_no'], 2);
    $new_num = str_pad($num + 1, 4, '0', STR_PAD_LEFT);
    $last_pawn_no = 'PN' . $new_num;
}

// Process form submission
$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_pawn'])) {
    // Sanitize inputs
    $pawn_no = $conn->real_escape_string(trim($_POST['pawn_no'] ?? $last_pawn_no));
    $entry_date = $conn->real_escape_string($_POST['entry_date'] ?? date('Y-m-d'));
    $customer_id = (int)($_POST['customer_id'] ?? 0);
    $pawn_customer_id = (int)($_POST['pawn_customer_id'] ?? 0);
    $customer_name = $conn->real_escape_string(trim($_POST['customer_name'] ?? ''));
    $customer_mobile = $conn->real_escape_string(trim($_POST['customer_mobile'] ?? ''));
    $address = $conn->real_escape_string(trim($_POST['address'] ?? ''));
    $id_proof_type = $conn->real_escape_string(trim($_POST['id_proof_type'] ?? ''));
    $id_proof_number = $conn->real_escape_string(trim($_POST['id_proof_number'] ?? ''));
    $metal_type = $conn->real_escape_string($_POST['metal_type'] ?? 'Gold');
    $loan_type = $conn->real_escape_string(trim($_POST['loan_type'] ?? 'General'));
    
    $total_gross_weight = (float)($_POST['total_gross_weight'] ?? 0);
    $total_less_weight = (float)($_POST['total_less_weight'] ?? 0);
    $total_net_weight = (float)($_POST['total_net_weight'] ?? 0);
    $loan_amount = (float)($_POST['loan_amount'] ?? 0);
    $principal_balance = $loan_amount;
    $interest_rate = (float)($_POST['interest_rate'] ?? 12.00);
    $interest_type = $conn->real_escape_string($_POST['interest_type'] ?? 'Monthly');
    $interest_method = $conn->real_escape_string($_POST['interest_method'] ?? 'Simple');
    $tenure_months = (int)($_POST['tenure_months'] ?? 0);
    $maturity_date = !empty($_POST['maturity_date']) ? $conn->real_escape_string($_POST['maturity_date']) : null;
    $ticket_charge = (float)($_POST['ticket_charge'] ?? 0);
    $other_charge = (float)($_POST['other_charge'] ?? 0);
    $payment_method_id = !empty($_POST['payment_method_id']) ? (int)$_POST['payment_method_id'] : null;
    $payment_reference = $conn->real_escape_string(trim($_POST['payment_reference'] ?? ''));
    $remarks = $conn->real_escape_string(trim($_POST['remarks'] ?? ''));
    
    // Items data
    $item_names = $_POST['item_name'] ?? [];
    $item_categories = $_POST['item_category'] ?? [];
    $purities = $_POST['purity'] ?? [];
    $gross_weights = $_POST['gross_weight'] ?? [];
    $less_weights = $_POST['less_weight'] ?? [];
    $net_weights = $_POST['net_weight'] ?? [];
    $pawn_values = $_POST['pawn_value'] ?? [];
    $item_remarks = $_POST['item_remarks'] ?? [];
    
    $errors = [];
    
    if (empty($customer_name)) {
        $errors[] = "Customer name is required";
    }
    if ($total_net_weight <= 0) {
        $errors[] = "Total net weight must be greater than 0";
    }
    if ($loan_amount <= 0) {
        $errors[] = "Loan amount must be greater than 0";
    }
    if ($tenure_months <= 0) {
        $errors[] = "Tenure months must be greater than 0";
    }
    
    if (empty($errors)) {
        $conn->begin_transaction();
        
        try {
            // Insert pawn entry
            $sql = "INSERT INTO pawn_entries (
                business_id, pawn_no, entry_date, customer_id, pawn_customer_id,
                customer_name, customer_mobile, address, id_proof_type, id_proof_number,
                metal_type, loan_type, item_count, total_gross_weight, total_less_weight,
                total_net_weight, loan_amount, principal_balance, interest_rate,
                interest_type, interest_method, tenure_months, maturity_date,
                ticket_charge, other_charge, payment_method_id, payment_reference,
                remarks, status, created_by, created_at
            ) VALUES (
                $business_id, '$pawn_no', '$entry_date', " . ($customer_id ?: 'NULL') . ", " . ($pawn_customer_id ?: 'NULL') . ",
                '$customer_name', '$customer_mobile', '$address', '$id_proof_type', '$id_proof_number',
                '$metal_type', '$loan_type', " . count($item_names) . ", $total_gross_weight, $total_less_weight,
                $total_net_weight, $loan_amount, $principal_balance, $interest_rate,
                '$interest_type', '$interest_method', $tenure_months, " . ($maturity_date ? "'$maturity_date'" : "NULL") . ",
                $ticket_charge, $other_charge, " . ($payment_method_id ?: 'NULL') . ", '$payment_reference',
                '$remarks', 'Active', $created_by, NOW()
            )";
            
            if (!$conn->query($sql)) {
                throw new Exception("Failed to save pawn entry: " . $conn->error);
            }
            
            $pawn_id = $conn->insert_id;
            
            // Insert pawn items
            for ($i = 0; $i < count($item_names); $i++) {
                if (empty($item_names[$i])) continue;
                
                $item_name = $conn->real_escape_string($item_names[$i]);
                $item_category = $conn->real_escape_string($item_categories[$i] ?? '');
                $purity = $conn->real_escape_string($purities[$i] ?? '');
                $gross_weight = (float)($gross_weights[$i] ?? 0);
                $less_weight = (float)($less_weights[$i] ?? 0);
                $net_weight = (float)($net_weights[$i] ?? 0);
                $pawn_value = (float)($pawn_values[$i] ?? 0);
                $estimated_amount = $net_weight * $pawn_value;
                $item_remark = $conn->real_escape_string($item_remarks[$i] ?? '');
                
                $item_sql = "INSERT INTO pawn_items (
                    business_id, pawn_id, item_name, category_id, item_category, purity,
                    gross_weight, less_weight, net_weight, rate_per_gram, estimated_amount, remarks
                ) VALUES (
                    $business_id, $pawn_id, '$item_name', NULL, '$item_category', '$purity',
                    $gross_weight, $less_weight, $net_weight, $pawn_value, $estimated_amount, '$item_remark'
                )";
                
                if (!$conn->query($item_sql)) {
                    throw new Exception("Failed to save pawn item: " . $conn->error);
                }
            }
            
            $conn->commit();
            $success_msg = "Pawn entry created successfully! Pawn No: $pawn_no";
            
            // Reset form and generate new pawn number
            $_POST = [];
            $last_pawn_no = 'PN' . str_pad((int)substr($pawn_no, 2) + 1, 4, '0', STR_PAD_LEFT);
            
        } catch (Exception $e) {
            $conn->rollback();
            $error_msg = $e->getMessage();
        }
    } else {
        $error_msg = implode("<br>", $errors);
    }
}

// Get existing values for form repopulation
$form_values = $_POST;
?>
<!doctype html>
<html lang="en">
<?php include('includes/head.php'); ?>
<title>New Pawn Entry | Pawn Broking</title>
<style>
    .pawn-card { border-radius: 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 24px; }
    .pawn-card .card-header { background: #f8fafc; border-bottom: 1px solid #eef2f6; padding: 16px 20px; font-weight: 600; }
    .pawn-card .card-body { padding: 20px; }
    .item-row { background: #f9fafb; border-radius: 12px; padding: 16px; margin-bottom: 16px; position: relative; border: 1px solid #eef2f6; }
    .remove-item { position: absolute; top: 12px; right: 12px; cursor: pointer; color: #dc3545; background: white; border-radius: 50%; width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; box-shadow: 0 1px 3px rgba(0,0,0,0.1); z-index: 10; }
    .remove-item:hover { background: #dc3545; color: white; }
    .weight-summary { background: #eef2ff; border-radius: 12px; padding: 16px 20px; }
    .summary-value { font-weight: 700; font-size: 20px; color: #1e293b; }
    .required:after { content: "*"; color: #dc3545; margin-left: 4px; }
    .rate-info { background: #fef3c7; border-radius: 8px; padding: 8px 12px; font-size: 13px; }
    .est-amount { font-weight: 700; color: #059669; }
    .search-result-item { cursor: pointer; padding: 10px; border-bottom: 1px solid #e5e7eb; transition: background 0.2s; }
    .search-result-item:hover { background: #f3f4f6; }
    .search-result-item.active { background: #e0e7ff; }
    .customer-highlight { background: #f0fdf4; border: 1px solid #bbf7d0; }
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
                                    <h4 class="mb-1">New Pawn Entry</h4>
                                    <p class="text-muted mb-0">Create a new pawn broking loan entry with rate calculations</p>
                                </div>
                                <div>
                                    <a href="metal-rates.php" class="btn btn-outline-info me-2">
                                        <i class="fas fa-chart-line"></i> Metal Rates
                                    </a>
                                    <a href="pawn-list.php" class="btn btn-outline-secondary">
                                        <i class="fas fa-list"></i> Pawn List
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                    
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
                    
                    <form method="POST" id="pawnForm" onsubmit="return validateForm()">
                        <input type="hidden" name="submit_pawn" value="1">
                        
                        <!-- Basic Information -->
                        <div class="card pawn-card">
                            <div class="card-header">
                                <i class="fas fa-info-circle me-2"></i> Basic Information
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label required">Pawn No</label>
                                        <input type="text" name="pawn_no" class="form-control" value="<?php echo h($last_pawn_no); ?>" readonly>
                                        <small class="text-muted">Auto-generated</small>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label required">Entry Date</label>
                                        <input type="date" name="entry_date" class="form-control" value="<?php echo h($form_values['entry_date'] ?? date('Y-m-d')); ?>" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Metal Type</label>
                                        <select name="metal_type" class="form-select" id="metal_type">
                                            <option value="Gold" <?php echo (($form_values['metal_type'] ?? 'Gold') == 'Gold') ? 'selected' : ''; ?>>Gold</option>
                                            <option value="Silver" <?php echo (($form_values['metal_type'] ?? '') == 'Silver') ? 'selected' : ''; ?>>Silver</option>
                                            <option value="Other" <?php echo (($form_values['metal_type'] ?? '') == 'Other') ? 'selected' : ''; ?>>Other</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Loan Type</label>
                                        <input type="text" name="loan_type" class="form-control" value="<?php echo h($form_values['loan_type'] ?? 'General'); ?>" placeholder="General/Agricultural/etc">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Customer Information with Search -->
                        <div class="card pawn-card">
                            <div class="card-header">
                                <i class="fas fa-user me-2"></i> Customer Information
                            </div>
                            <div class="card-body">
                                <!-- Search Box for Customers -->
                                <div class="row g-3 mb-3">
                                    <div class="col-md-12">
                                        <label class="form-label">Search Customer</label>
                                        <div class="input-group">
                                            <input type="text" id="customerSearch" class="form-control" placeholder="Type customer name, code or mobile number..." autocomplete="off">
                                            <button class="btn btn-primary" type="button" onclick="searchCustomer()">
                                                <i class="fas fa-search"></i> Search
                                            </button>
                                        </div>
                                        <div id="searchResults" class="mt-2" style="display: none; max-height: 250px; overflow-y: auto; border: 1px solid #e5e7eb; border-radius: 8px; background: white; position: absolute; z-index: 1000; width: calc(100% - 30px);"></div>
                                    </div>
                                </div>
                                
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Select Existing Customer</label>
                                        <select name="customer_id" id="customer_id" class="form-select" onchange="loadCustomerDetails()">
                                            <option value="">-- Select Existing Customer --</option>
                                            <?php foreach ($customers as $c): ?>
                                                <option value="<?php echo $c['id']; ?>" 
                                                    data-name="<?php echo h($c['customer_name']); ?>"
                                                    data-mobile="<?php echo h($c['mobile']); ?>"
                                                    data-email="<?php echo h($c['email']); ?>"
                                                    data-address="<?php echo h($c['address_line1'] . ' ' . ($c['address_line2'] ?? '') . ' ' . ($c['city'] ?? '') . ' - ' . ($c['pincode'] ?? '')); ?>"
                                                    data-gstin="<?php echo h($c['gstin']); ?>"
                                                    <?php echo (($form_values['customer_id'] ?? 0) == $c['id']) ? 'selected' : ''; ?>>
                                                    <?php echo h($c['customer_name']); ?> (<?php echo h($c['customer_code']); ?>) - <?php echo h($c['mobile']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="text-muted">Select existing customer or fill new customer details below</small>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label required">Customer Name</label>
                                        <input type="text" name="customer_name" id="customer_name" class="form-control" value="<?php echo h($form_values['customer_name'] ?? ''); ?>" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Mobile Number</label>
                                        <input type="text" name="customer_mobile" id="customer_mobile" class="form-control" value="<?php echo h($form_values['customer_mobile'] ?? ''); ?>">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Address</label>
                                        <textarea name="address" id="customer_address" class="form-control" rows="2"><?php echo h($form_values['address'] ?? ''); ?></textarea>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">ID Proof Type</label>
                                        <select name="id_proof_type" class="form-select">
                                            <option value="">-- Select --</option>
                                            <option value="Aadhar" <?php echo (($form_values['id_proof_type'] ?? '') == 'Aadhar') ? 'selected' : ''; ?>>Aadhar Card</option>
                                            <option value="PAN" <?php echo (($form_values['id_proof_type'] ?? '') == 'PAN') ? 'selected' : ''; ?>>PAN Card</option>
                                            <option value="Voter" <?php echo (($form_values['id_proof_type'] ?? '') == 'Voter') ? 'selected' : ''; ?>>Voter ID</option>
                                            <option value="Driving" <?php echo (($form_values['id_proof_type'] ?? '') == 'Driving') ? 'selected' : ''; ?>>Driving License</option>
                                            <option value="Passport" <?php echo (($form_values['id_proof_type'] ?? '') == 'Passport') ? 'selected' : ''; ?>>Passport</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">ID Proof Number</label>
                                        <input type="text" name="id_proof_number" class="form-control" value="<?php echo h($form_values['id_proof_number'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Email (Optional)</label>
                                        <input type="email" name="customer_email" id="customer_email" class="form-control" value="<?php echo h($form_values['customer_email'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Pawn Customer ID</label>
                                        <input type="number" name="pawn_customer_id" class="form-control" value="<?php echo h($form_values['pawn_customer_id'] ?? ''); ?>" placeholder="Optional">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Item Details -->
                        <div class="card pawn-card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <span><i class="fas fa-gem me-2"></i> Item Details</span>
                                <button type="button" class="btn btn-sm btn-primary" onclick="addItemRow()">
                                    <i class="fas fa-plus"></i> Add Item
                                </button>
                            </div>
                            <div class="card-body">
                                <div id="items-container">
                                    <?php 
                                    $item_count = max(1, count($form_values['item_name'] ?? []));
                                    for ($i = 0; $i < $item_count; $i++): 
                                    ?>
                                        <div class="item-row" data-index="<?php echo $i; ?>">
                                            <div class="remove-item" onclick="removeItemRow(this)" style="display: <?php echo ($i === 0 && $item_count === 1) ? 'none' : 'flex'; ?>;">
                                                <i class="fas fa-times"></i>
                                            </div>
                                            <div class="row g-3">
                                                <div class="col-md-4">
                                                    <label class="form-label required">Item Name</label>
                                                    <input type="text" name="item_name[]" class="form-control item-name" value="<?php echo h($form_values['item_name'][$i] ?? ''); ?>" required>
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="form-label">Category</label>
                                                    <select name="item_category[]" class="form-select category-select" onchange="updateItemRate(this)">
                                                        <option value="">-- Select --</option>
                                                        <?php foreach ($categories as $cat): ?>
                                                            <option value="<?php echo h($cat['category_name']); ?>" 
                                                                    data-pawn-value="<?php echo h($cat['default_pawn_value_per_gram'] ?? 0); ?>"
                                                                    data-purity="<?php echo h($cat['default_purity'] ?? ''); ?>"
                                                                    data-interest="<?php echo h($cat['default_interest_rate']); ?>"
                                                                    <?php echo (($form_values['item_category'][$i] ?? '') == $cat['category_name']) ? 'selected' : ''; ?>>
                                                                <?php echo h($cat['category_name']); ?> (<?php echo h($cat['default_purity'] ?? 'N/A'); ?>)
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label">Purity</label>
                                                    <input type="text" name="purity[]" class="form-control purity-input" value="<?php echo h($form_values['purity'][$i] ?? ''); ?>" placeholder="e.g., 916, 22K">
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label required">Gross Weight (g)</label>
                                                    <input type="number" step="0.001" name="gross_weight[]" class="form-control gross-weight" value="<?php echo h($form_values['gross_weight'][$i] ?? '0'); ?>" required onchange="updateItemNetWeight(this); updateTotals();">
                                                </div>
                                                <div class="col-md-1">
                                                    <label class="form-label">Less (g)</label>
                                                    <input type="number" step="0.001" name="less_weight[]" class="form-control less-weight" value="<?php echo h($form_values['less_weight'][$i] ?? '0'); ?>" onchange="updateItemNetWeight(this); updateTotals();">
                                                </div>
                                            </div>
                                            <div class="row g-3 mt-2">
                                                <div class="col-md-3">
                                                    <label class="form-label">Net Weight (g)</label>
                                                    <input type="number" step="0.001" name="net_weight[]" class="form-control net-weight" value="<?php echo h($form_values['net_weight'][$i] ?? '0'); ?>" readonly style="background:#f0f0f0;">
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="form-label">Current Metal Rate</label>
                                                    <input type="text" class="form-control metal-rate-display" readonly style="background:#f0f0f0;" value="₹0.00">
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="form-label">Pawn Value (₹/gram)</label>
                                                    <input type="number" step="0.01" name="pawn_value[]" class="form-control pawn-value" value="<?php echo h($form_values['pawn_value'][$i] ?? '0'); ?>" onchange="updateItemLoanAmount(this)">
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="form-label est-label">Est. Loan Amount</label>
                                                    <input type="text" class="form-control est-loan-display" readonly style="background:#e8f5e9; font-weight:700; color:#059669;" value="₹0.00">
                                                </div>
                                            </div>
                                            <div class="row g-3 mt-1">
                                                <div class="col-12">
                                                    <label class="form-label">Remarks</label>
                                                    <input type="text" name="item_remarks[]" class="form-control" value="<?php echo h($form_values['item_remarks'][$i] ?? ''); ?>" placeholder="Any special notes about this item">
                                                </div>
                                            </div>
                                        </div>
                                    <?php endfor; ?>
                                </div>
                                
                                <!-- Weight Summary -->
                                <div class="row mt-4">
                                    <div class="col-md-12">
                                        <div class="weight-summary">
                                            <div class="row">
                                                <div class="col-md-3">
                                                    <small class="text-muted">Total Gross Weight</small>
                                                    <div class="summary-value" id="total_gross_display">0.000</div>
                                                    <input type="hidden" name="total_gross_weight" id="total_gross_weight" value="0">
                                                </div>
                                                <div class="col-md-3">
                                                    <small class="text-muted">Total Less Weight</small>
                                                    <div class="summary-value" id="total_less_display">0.000</div>
                                                    <input type="hidden" name="total_less_weight" id="total_less_weight" value="0">
                                                </div>
                                                <div class="col-md-3">
                                                    <small class="text-muted">Total Net Weight</small>
                                                    <div class="summary-value" id="total_net_display">0.000</div>
                                                    <input type="hidden" name="total_net_weight" id="total_net_weight" value="0">
                                                </div>
                                                <div class="col-md-3">
                                                    <small class="text-muted">No. of Items</small>
                                                    <div class="summary-value" id="item_count_display">0</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Loan Details -->
                        <div class="card pawn-card">
                            <div class="card-header">
                                <i class="fas fa-rupee-sign me-2"></i> Loan Details
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label required">Total Loan Amount (₹)</label>
                                        <input type="number" step="0.01" name="loan_amount" id="loan_amount" class="form-control" value="<?php echo h($form_values['loan_amount'] ?? '0'); ?>" required readonly style="background:#f0f0f0; font-weight:700;">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label required">Interest Rate (%)</label>
                                        <input type="number" step="0.01" name="interest_rate" id="interest_rate" class="form-control" value="<?php echo h($form_values['interest_rate'] ?? '12.00'); ?>" required>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Interest Type</label>
                                        <select name="interest_type" class="form-select">
                                            <option value="Monthly" <?php echo (($form_values['interest_type'] ?? 'Monthly') == 'Monthly') ? 'selected' : ''; ?>>Monthly</option>
                                            <option value="Weekly" <?php echo (($form_values['interest_type'] ?? '') == 'Weekly') ? 'selected' : ''; ?>>Weekly</option>
                                            <option value="Daily" <?php echo (($form_values['interest_type'] ?? '') == 'Daily') ? 'selected' : ''; ?>>Daily</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Interest Method</label>
                                        <select name="interest_method" class="form-select">
                                            <option value="Simple" <?php echo (($form_values['interest_method'] ?? 'Simple') == 'Simple') ? 'selected' : ''; ?>>Simple</option>
                                            <option value="Flat" <?php echo (($form_values['interest_method'] ?? '') == 'Flat') ? 'selected' : ''; ?>>Flat</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label required">Tenure (Months)</label>
                                        <input type="number" name="tenure_months" id="tenure_months" class="form-control" value="<?php echo h($form_values['tenure_months'] ?? '12'); ?>" required onchange="updateMaturityDate()">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Maturity Date</label>
                                        <input type="date" name="maturity_date" id="maturity_date" class="form-control" value="<?php echo h($form_values['maturity_date'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Ticket Charge (₹)</label>
                                        <input type="number" step="0.01" name="ticket_charge" class="form-control" value="<?php echo h($form_values['ticket_charge'] ?? '0'); ?>">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Other Charges (₹)</label>
                                        <input type="number" step="0.01" name="other_charge" class="form-control" value="<?php echo h($form_values['other_charge'] ?? '0'); ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Payment Details -->
                        <div class="card pawn-card">
                            <div class="card-header">
                                <i class="fas fa-credit-card me-2"></i> Payment Details
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label">Payment Method</label>
                                        <select name="payment_method_id" class="form-select">
                                            <option value="">-- Select --</option>
                                            <?php foreach ($paymentMethods as $pm): ?>
                                                <option value="<?php echo $pm['id']; ?>" <?php echo (($form_values['payment_method_id'] ?? '') == $pm['id']) ? 'selected' : ''; ?>>
                                                    <?php echo h($pm['method_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Reference No</label>
                                        <input type="text" name="payment_reference" class="form-control" value="<?php echo h($form_values['payment_reference'] ?? ''); ?>" placeholder="Cheque/UTR No">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Remarks</label>
                                        <textarea name="remarks" class="form-control" rows="2"><?php echo h($form_values['remarks'] ?? ''); ?></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Submit Buttons -->
                        <div class="row mb-5">
                            <div class="col-12 text-end">
                                <button type="reset" class="btn btn-light me-2" onclick="resetForm()"><i class="fas fa-undo"></i> Reset</button>
                                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Pawn Entry</button>
                            </div>
                        </div>
                    </form>
                    
                </div>
            </div>
            <?php include('includes/footer.php'); ?>
        </div>
    </div>
    
    <?php include('includes/scripts.php'); ?>
    
    <script>
        // Customer data for search
        const customerData = <?php echo json_encode($customers); ?>;
        
        // Metal rates data from PHP
        const metalRates = <?php echo json_encode($metal_rates); ?>;
        let itemCounter = <?php echo $item_count; ?>;
        
        // Initialize all calculations on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize all items
            document.querySelectorAll('.item-row').forEach(row => {
                initItemRow(row);
            });
            updateTotals();
            updateMaturityDate();
            
            // Add event listener for metal type change
            document.getElementById('metal_type')?.addEventListener('change', function() {
                document.querySelectorAll('.category-select').forEach(select => {
                    updateItemRate(select);
                });
            });
            
            // Auto-load customer if selected from dropdown
            if (document.getElementById('customer_id').value) {
                loadCustomerDetails();
            }
        });
        
        // Search Customer Function
        function searchCustomer() {
            const searchTerm = document.getElementById('customerSearch').value.toLowerCase().trim();
            const resultsDiv = document.getElementById('searchResults');
            
            if (searchTerm.length < 2) {
                resultsDiv.style.display = 'none';
                return;
            }
            
            const filtered = customerData.filter(customer => 
                customer.customer_name.toLowerCase().includes(searchTerm) || 
                customer.customer_code.toLowerCase().includes(searchTerm) ||
                (customer.mobile && customer.mobile.includes(searchTerm))
            );
            
            if (filtered.length === 0) {
                resultsDiv.innerHTML = '<div class="p-3 text-center text-muted">No customers found</div>';
                resultsDiv.style.display = 'block';
                return;
            }
            
            let html = '';
            filtered.forEach(customer => {
                html += `
                    <div class="search-result-item" onclick="selectCustomer(${customer.id})">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <strong>${escapeHtml(customer.customer_name)}</strong><br>
                                <small>${escapeHtml(customer.customer_code)}</small>
                            </div>
                            <div class="text-end">
                                <span class="text-muted">${customer.mobile || 'No mobile'}</span>
                            </div>
                        </div>
                    </div>
                `;
            });
            
            resultsDiv.innerHTML = html;
            resultsDiv.style.display = 'block';
        }
        
        function selectCustomer(customerId) {
            // Set dropdown value
            const dropdown = document.getElementById('customer_id');
            dropdown.value = customerId;
            
            // Load customer details
            loadCustomerDetails();
            
            // Hide search results
            document.getElementById('searchResults').style.display = 'none';
            document.getElementById('customerSearch').value = '';
            
            // Highlight the customer section
            const customerCard = document.querySelector('.pawn-card:nth-child(2)');
            customerCard.classList.add('customer-highlight');
            setTimeout(() => {
                customerCard.classList.remove('customer-highlight');
            }, 1000);
        }
        
        function loadCustomerDetails() {
            const dropdown = document.getElementById('customer_id');
            const selectedOption = dropdown.options[dropdown.selectedIndex];
            
            if (selectedOption && selectedOption.value) {
                const customerName = selectedOption.getAttribute('data-name') || '';
                const mobile = selectedOption.getAttribute('data-mobile') || '';
                const address = selectedOption.getAttribute('data-address') || '';
                const email = selectedOption.getAttribute('data-email') || '';
                
                document.getElementById('customer_name').value = customerName;
                document.getElementById('customer_mobile').value = mobile;
                document.getElementById('customer_address').value = address;
                document.getElementById('customer_email').value = email;
                
                // Optional: Fetch pawn customer details if exists
                fetchPawnCustomerDetails(selectedOption.value);
            }
        }
        
        function fetchPawnCustomerDetails(customerId) {
            fetch(`get_pawn_customer_details.php?customer_id=${customerId}`)
                .then(res => res.json())
                .then(data => {
                    if (data && data.success) {
                        if (data.id_proof_type) {
                            document.querySelector('select[name="id_proof_type"]').value = data.id_proof_type;
                        }
                        if (data.id_proof_number) {
                            document.querySelector('input[name="id_proof_number"]').value = data.id_proof_number;
                        }
                        if (data.pawn_customer_id) {
                            document.querySelector('input[name="pawn_customer_id"]').value = data.pawn_customer_id;
                        }
                    }
                })
                .catch(err => console.log('Error fetching pawn customer details:', err));
        }
        
        function initItemRow(row) {
            const categorySelect = row.querySelector('.category-select');
            const grossWeight = row.querySelector('.gross-weight');
            const lessWeight = row.querySelector('.less-weight');
            const pawnValue = row.querySelector('.pawn-value');
            
            if (categorySelect) updateItemRate(categorySelect);
            if (grossWeight) updateItemNetWeight(grossWeight);
            if (pawnValue) updateItemLoanAmount(pawnValue);
        }
        
        function addItemRow() {
            const container = document.getElementById('items-container');
            const template = document.querySelector('.item-row').cloneNode(true);
            
            // Clear all input values
            template.querySelectorAll('input, select').forEach(input => {
                if (input.type === 'number') {
                    input.value = '0';
                } else if (input.type !== 'hidden' && input.tagName !== 'SELECT') {
                    input.value = '';
                }
            });
            
            // Reset select dropdowns
            template.querySelectorAll('select').forEach(select => {
                select.selectedIndex = 0;
            });
            
            // Show remove button
            const removeBtn = template.querySelector('.remove-item');
            if (removeBtn) removeBtn.style.display = 'flex';
            
            // Reset displays
            const estDisplay = template.querySelector('.est-loan-display');
            if (estDisplay) estDisplay.value = '₹0.00';
            
            const metalRateDisplay = template.querySelector('.metal-rate-display');
            if (metalRateDisplay) metalRateDisplay.value = '₹0.00';
            
            container.appendChild(template);
            initItemRow(template);
            updateTotals();
        }
        
        function removeItemRow(element) {
            const container = document.getElementById('items-container');
            if (container.children.length > 1) {
                element.closest('.item-row').remove();
                updateTotals();
            } else {
                alert("At least one item is required");
            }
        }
        
        function updateItemRate(selectElement) {
            const row = selectElement.closest('.item-row');
            const selectedOption = selectElement.options[selectElement.selectedIndex];
            const pawnValue = selectedOption.getAttribute('data-pawn-value') || 0;
            const purity = selectedOption.getAttribute('data-purity') || '';
            const interestRate = selectedOption.getAttribute('data-interest') || '';
            
            // Set pawn value
            const pawnValueInput = row.querySelector('.pawn-value');
            if (pawnValueInput && pawnValue) {
                pawnValueInput.value = pawnValue;
            }
            
            // Set purity
            const purityInput = row.querySelector('.purity-input');
            if (purityInput && purity) {
                purityInput.value = purity;
            }
            
            // Set interest rate if selected
            if (interestRate && document.getElementById('interest_rate')) {
                document.getElementById('interest_rate').value = interestRate;
            }
            
            // Fetch and display metal rate
            const metalType = document.getElementById('metal_type')?.value || 'Gold';
            if (purity && metalRates[metalType] && metalRates[metalType][purity]) {
                const rate = metalRates[metalType][purity];
                const rateDisplay = row.querySelector('.metal-rate-display');
                if (rateDisplay) rateDisplay.value = '₹' + rate.toFixed(2);
            } else if (purity) {
                fetchMetalRate(metalType, purity, row);
            }
            
            updateItemLoanAmount(pawnValueInput);
        }
        
        function fetchMetalRate(metalType, purity, row) {
            fetch(`get_metal_rate.php?metal_type=${encodeURIComponent(metalType)}&purity=${encodeURIComponent(purity)}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        const rateDisplay = row.querySelector('.metal-rate-display');
                        if (rateDisplay) rateDisplay.value = '₹' + data.rate.toFixed(2);
                    }
                })
                .catch(err => console.log('Error fetching rate:', err));
        }
        
        function updateItemNetWeight(element) {
            const row = element.closest('.item-row');
            const gross = parseFloat(row.querySelector('.gross-weight')?.value) || 0;
            const less = parseFloat(row.querySelector('.less-weight')?.value) || 0;
            const net = gross - less;
            
            const netInput = row.querySelector('.net-weight');
            if (netInput) netInput.value = net.toFixed(3);
            
            updateItemLoanAmount(row.querySelector('.pawn-value'));
        }
        
        function updateItemLoanAmount(element) {
            const row = element.closest('.item-row');
            const netWeight = parseFloat(row.querySelector('.net-weight')?.value) || 0;
            const pawnValue = parseFloat(row.querySelector('.pawn-value')?.value) || 0;
            const estimatedAmount = netWeight * pawnValue;
            
            const estDisplay = row.querySelector('.est-loan-display');
            if (estDisplay) estDisplay.value = '₹' + estimatedAmount.toFixed(2);
            
            updateTotalLoanAmount();
        }
        
        function updateTotalLoanAmount() {
            let totalLoan = 0;
            document.querySelectorAll('.est-loan-display').forEach(display => {
                const value = display.value.replace('₹', '');
                totalLoan += parseFloat(value) || 0;
            });
            document.getElementById('loan_amount').value = totalLoan.toFixed(2);
        }
        
        function updateTotals() {
            let totalGross = 0, totalLess = 0, totalNet = 0;
            let itemCount = 0;
            
            document.querySelectorAll('.item-row').forEach(row => {
                const gross = parseFloat(row.querySelector('.gross-weight')?.value) || 0;
                const less = parseFloat(row.querySelector('.less-weight')?.value) || 0;
                const net = gross - less;
                
                totalGross += gross;
                totalLess += less;
                totalNet += net;
                itemCount++;
            });
            
            document.getElementById('total_gross_display').innerText = totalGross.toFixed(3);
            document.getElementById('total_less_display').innerText = totalLess.toFixed(3);
            document.getElementById('total_net_display').innerText = totalNet.toFixed(3);
            document.getElementById('item_count_display').innerText = itemCount;
            
            document.getElementById('total_gross_weight').value = totalGross.toFixed(3);
            document.getElementById('total_less_weight').value = totalLess.toFixed(3);
            document.getElementById('total_net_weight').value = totalNet.toFixed(3);
        }
        
        function updateMaturityDate() {
            const entryDate = document.querySelector('input[name="entry_date"]').value;
            const tenure = parseInt(document.getElementById('tenure_months').value) || 0;
            if (entryDate && tenure > 0) {
                const date = new Date(entryDate);
                date.setMonth(date.getMonth() + tenure);
                const year = date.getFullYear();
                const month = String(date.getMonth() + 1).padStart(2, '0');
                const day = String(date.getDate()).padStart(2, '0');
                document.getElementById('maturity_date').value = `${year}-${month}-${day}`;
            }
        }
        
        function validateForm() {
            const customerName = document.getElementById('customer_name').value.trim();
            if (!customerName) {
                alert("Please enter customer name");
                document.getElementById('customer_name').focus();
                return false;
            }
            
            const totalNet = parseFloat(document.getElementById('total_net_weight').value) || 0;
            if (totalNet <= 0) {
                alert("Total net weight must be greater than 0");
                return false;
            }
            
            const loanAmount = parseFloat(document.getElementById('loan_amount').value) || 0;
            if (loanAmount <= 0) {
                alert("Loan amount must be greater than 0");
                return false;
            }
            
            const tenure = parseInt(document.getElementById('tenure_months').value) || 0;
            if (tenure <= 0) {
                alert("Tenure must be greater than 0");
                return false;
            }
            
            let hasItem = false;
            document.querySelectorAll('.item-name').forEach(input => {
                if (input.value.trim()) hasItem = true;
            });
            if (!hasItem) {
                alert("Please add at least one item");
                return false;
            }
            
            return true;
        }
        
        function resetForm() {
            if (confirm('Are you sure you want to reset the form? All entered data will be lost.')) {
                location.reload();
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
        
        // Close search results when clicking outside
        document.addEventListener('click', function(event) {
            const searchInput = document.getElementById('customerSearch');
            const resultsDiv = document.getElementById('searchResults');
            if (searchInput && resultsDiv && !searchInput.contains(event.target) && !resultsDiv.contains(event.target)) {
                resultsDiv.style.display = 'none';
            }
        });
        
        // Live search on input
        document.getElementById('customerSearch')?.addEventListener('input', function() {
            if (this.value.length >= 2) {
                searchCustomer();
            } else {
                document.getElementById('searchResults').style.display = 'none';
            }
        });
        
        // Enter key search
        document.getElementById('customerSearch')?.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                searchCustomer();
            }
        });
        
        // Event listeners for dynamic updates
        document.querySelector('input[name="entry_date"]')?.addEventListener('change', updateMaturityDate);
        document.getElementById('tenure_months')?.addEventListener('change', updateMaturityDate);
    </script>
</body>
</html>