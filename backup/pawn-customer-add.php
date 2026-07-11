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
$currentPage = 'pawn-customer-add';

// Helper functions
if (!function_exists('h')) {
    function h($string) { return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('money')) {
    function money($amount) { return number_format((float)$amount, 2); }
}

// Check if editing existing customer
$edit_id = (int)($_GET['id'] ?? 0);
$edit_customer = null;
$edit_pawn_data = null;

if ($edit_id > 0) {
    // Fetch existing pawn customer data for editing
    $edit_sql = "SELECT pc.*, c.customer_name, c.customer_code, c.mobile, c.email, c.alternate_mobile,
                        c.address_line1, c.address_line2, c.city, c.state, c.pincode, c.gstin,
                        c.date_of_birth, c.anniversary_date, c.opening_balance, c.notes as customer_notes
                 FROM pawn_customers pc 
                 INNER JOIN customers c ON pc.customer_id = c.id 
                 WHERE pc.id = $edit_id AND pc.business_id = $business_id";
    $edit_res = $conn->query($edit_sql);
    if ($edit_res && $edit_res->num_rows > 0) {
        $edit_pawn_data = $edit_res->fetch_assoc();
        $edit_customer = $edit_pawn_data;
    }
}

// Fetch existing customers for linking
$customers = [];
$customer_sql = "SELECT id, customer_code, customer_name, mobile, address_line1, city 
                 FROM customers WHERE business_id = $business_id AND is_active = 1 
                 ORDER BY customer_name";
$cust_res = $conn->query($customer_sql);
if ($cust_res) {
    while ($row = $cust_res->fetch_assoc()) {
        $customers[] = $row;
    }
}

// Process form submission
$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_pawn_customer'])) {
    // Get form data
    $customer_id = (int)($_POST['customer_id'] ?? 0);
    $guardian_name = $conn->real_escape_string(trim($_POST['guardian_name'] ?? ''));
    $occupation = $conn->real_escape_string(trim($_POST['occupation'] ?? ''));
    $annual_income = (float)($_POST['annual_income'] ?? 0);
    $reference_name = $conn->real_escape_string(trim($_POST['reference_name'] ?? ''));
    $reference_mobile = $conn->real_escape_string(trim($_POST['reference_mobile'] ?? ''));
    $credit_limit = (float)($_POST['credit_limit'] ?? 0);
    $risk_category = $conn->real_escape_string($_POST['risk_category'] ?? 'Low');
    $notes = $conn->real_escape_string(trim($_POST['notes'] ?? ''));
    
    // KYC verification status
    $kyc_verified = isset($_POST['kyc_verified']) ? 1 : 0;
    
    // File uploads
    $photo_path = $_POST['existing_photo'] ?? '';
    $signature_path = $_POST['existing_signature'] ?? '';
    $kyc_document = $_POST['existing_kyc'] ?? '';
    
    $errors = [];
    
    if ($customer_id <= 0 && empty($_POST['customer_name_new'])) {
        $errors[] = "Please select an existing customer or enter a new customer name";
    }
    
    // Handle new file uploads
    $upload_dir = __DIR__ . '/uploads/pawn_customers/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    // Photo upload
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $photo_ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
        $photo_name = 'photo_' . time() . '_' . rand(1000, 9999) . '.' . $photo_ext;
        $photo_path_upload = $upload_dir . $photo_name;
        if (move_uploaded_file($_FILES['photo']['tmp_name'], $photo_path_upload)) {
            $photo_path = 'uploads/pawn_customers/' . $photo_name;
        } else {
            $errors[] = "Failed to upload photo";
        }
    }
    
    // Signature upload
    if (isset($_FILES['signature']) && $_FILES['signature']['error'] === UPLOAD_ERR_OK) {
        $sig_ext = pathinfo($_FILES['signature']['name'], PATHINFO_EXTENSION);
        $sig_name = 'sign_' . time() . '_' . rand(1000, 9999) . '.' . $sig_ext;
        $sig_path_upload = $upload_dir . $sig_name;
        if (move_uploaded_file($_FILES['signature']['tmp_name'], $sig_path_upload)) {
            $signature_path = 'uploads/pawn_customers/' . $sig_name;
        } else {
            $errors[] = "Failed to upload signature";
        }
    }
    
    // KYC Document upload
    if (isset($_FILES['kyc_document']) && $_FILES['kyc_document']['error'] === UPLOAD_ERR_OK) {
        $kyc_ext = pathinfo($_FILES['kyc_document']['name'], PATHINFO_EXTENSION);
        $kyc_name = 'kyc_' . time() . '_' . rand(1000, 9999) . '.' . $kyc_ext;
        $kyc_path_upload = $upload_dir . $kyc_name;
        if (move_uploaded_file($_FILES['kyc_document']['tmp_name'], $kyc_path_upload)) {
            $kyc_document = 'uploads/pawn_customers/' . $kyc_name;
        } else {
            $errors[] = "Failed to upload KYC document";
        }
    }
    
    // If new customer, create entry in customers table first
    if ($customer_id <= 0 && !empty($_POST['customer_name_new'])) {
        $customer_name_new = $conn->real_escape_string(trim($_POST['customer_name_new']));
        $customer_mobile = $conn->real_escape_string(trim($_POST['customer_mobile_new'] ?? ''));
        $customer_email = $conn->real_escape_string(trim($_POST['customer_email_new'] ?? ''));
        $customer_address = $conn->real_escape_string(trim($_POST['customer_address_new'] ?? ''));
        $customer_city = $conn->real_escape_string(trim($_POST['customer_city_new'] ?? ''));
        $customer_state = $conn->real_escape_string(trim($_POST['customer_state_new'] ?? ''));
        $customer_pincode = $conn->real_escape_string(trim($_POST['customer_pincode_new'] ?? ''));
        $date_of_birth = !empty($_POST['date_of_birth_new']) ? $conn->real_escape_string($_POST['date_of_birth_new']) : null;
        
        // Generate customer code
        $prefix = 'CUST';
        $code_sql = "SELECT MAX(CAST(SUBSTRING(customer_code, 5) AS UNSIGNED)) as max_code 
                     FROM customers WHERE business_id = $business_id";
        $code_res = $conn->query($code_sql);
        $max_code = 0;
        if ($code_res && ($row = $code_res->fetch_assoc())) {
            $max_code = (int)($row['max_code'] ?? 0);
        }
        $new_code = $prefix . str_pad($max_code + 1, 4, '0', STR_PAD_LEFT);
        
        $insert_customer_sql = "INSERT INTO customers (
            business_id, customer_code, customer_type, customer_name, mobile, email,
            address_line1, city, state, pincode, date_of_birth, is_active, created_at
        ) VALUES (
            $business_id, '$new_code', 'PawnBroking', '$customer_name_new', '$customer_mobile', 
            '$customer_email', '$customer_address', '$customer_city', '$customer_state', 
            '$customer_pincode', " . ($date_of_birth ? "'$date_of_birth'" : "NULL") . ", 1, NOW()
        )";
        
        if ($conn->query($insert_customer_sql)) {
            $customer_id = $conn->insert_id;
        } else {
            $errors[] = "Failed to create customer: " . $conn->error;
        }
    }
    
    if (empty($errors) && $customer_id > 0) {
        // Check if pawn customer already exists
        $check_sql = "SELECT id FROM pawn_customers WHERE business_id = $business_id AND customer_id = $customer_id";
        $check_res = $conn->query($check_sql);
        $existing_id = null;
        
        if ($check_res && $check_res->num_rows > 0) {
            $existing = $check_res->fetch_assoc();
            $existing_id = $existing['id'];
        }
        
        if ($existing_id > 0) {
            // Update existing
            $update_sql = "UPDATE pawn_customers SET 
                guardian_name = '$guardian_name',
                occupation = '$occupation',
                annual_income = $annual_income,
                reference_name = '$reference_name',
                reference_mobile = '$reference_mobile',
                credit_limit = $credit_limit,
                risk_category = '$risk_category',
                notes = '$notes',
                kyc_verified = $kyc_verified,
                updated_at = NOW()";
            
            if ($photo_path) $update_sql .= ", photo_path = '$photo_path'";
            if ($signature_path) $update_sql .= ", signature_path = '$signature_path'";
            if ($kyc_document) $update_sql .= ", kyc_document = '$kyc_document'";
            
            $update_sql .= " WHERE business_id = $business_id AND customer_id = $customer_id";
            
            if ($conn->query($update_sql)) {
                $success_msg = "Pawn customer updated successfully!";
                // Refresh edit data
                $edit_customer = null;
                $edit_id = $existing_id;
                // Fetch updated data
                $refresh_sql = "SELECT pc.*, c.customer_name, c.customer_code, c.mobile, c.email 
                               FROM pawn_customers pc 
                               INNER JOIN customers c ON pc.customer_id = c.id 
                               WHERE pc.id = $edit_id";
                $refresh_res = $conn->query($refresh_sql);
                if ($refresh_res && $refresh_res->num_rows > 0) {
                    $edit_pawn_data = $refresh_res->fetch_assoc();
                    $edit_customer = $edit_pawn_data;
                }
            } else {
                $errors[] = "Failed to update pawn customer: " . $conn->error;
            }
        } else {
            // Insert new
            $insert_sql = "INSERT INTO pawn_customers (
                business_id, customer_id, guardian_name, occupation, annual_income,
                reference_name, reference_mobile, photo_path, signature_path, kyc_document,
                kyc_verified, credit_limit, risk_category, notes, created_at
            ) VALUES (
                $business_id, $customer_id, '$guardian_name', '$occupation', $annual_income,
                '$reference_name', '$reference_mobile', '$photo_path', '$signature_path', '$kyc_document',
                $kyc_verified, $credit_limit, '$risk_category', '$notes', NOW()
            )";
            
            if ($conn->query($insert_sql)) {
                $success_msg = "Pawn customer added successfully!";
                // Reset form after successful add
                if ($edit_id <= 0) {
                    $_POST = [];
                    $edit_customer = null;
                    $edit_pawn_data = null;
                }
            } else {
                $errors[] = "Failed to save pawn customer: " . $conn->error;
            }
        }
    }
    
    if (!empty($errors)) {
        $error_msg = implode("<br>", $errors);
    }
}

// Set form values for editing
$form_values = [];
if ($edit_pawn_data && $edit_id > 0) {
    // For editing, use the fetched data
    $form_values['customer_id'] = $edit_pawn_data['customer_id'];
    $form_values['customer_name'] = $edit_pawn_data['customer_name'];
    $form_values['customer_mobile'] = $edit_pawn_data['mobile'];
    $form_values['guardian_name'] = $edit_pawn_data['guardian_name'];
    $form_values['occupation'] = $edit_pawn_data['occupation'];
    $form_values['annual_income'] = $edit_pawn_data['annual_income'];
    $form_values['reference_name'] = $edit_pawn_data['reference_name'];
    $form_values['reference_mobile'] = $edit_pawn_data['reference_mobile'];
    $form_values['credit_limit'] = $edit_pawn_data['credit_limit'];
    $form_values['risk_category'] = $edit_pawn_data['risk_category'] ?? 'Low';
    $form_values['notes'] = $edit_pawn_data['notes'];
    $form_values['kyc_verified'] = $edit_pawn_data['kyc_verified'];
    $form_values['photo_path'] = $edit_pawn_data['photo_path'];
    $form_values['signature_path'] = $edit_pawn_data['signature_path'];
    $form_values['kyc_document'] = $edit_pawn_data['kyc_document'];
    $form_values['address'] = $edit_pawn_data['address_line1'];
    $form_values['city'] = $edit_pawn_data['city'];
    $form_values['state'] = $edit_pawn_data['state'];
    $form_values['pincode'] = $edit_pawn_data['pincode'];
    $form_values['date_of_birth'] = $edit_pawn_data['date_of_birth'];
} elseif (isset($_POST['submit_pawn_customer'])) {
    // For form repopulation after error
    $form_values = $_POST;
}
?>
<!doctype html>
<html lang="en">
<?php include('includes/head.php'); ?>
<title><?php echo $edit_id > 0 ? 'Edit' : 'Add'; ?> Pawn Customer | Pawn Broking</title>
<style>
    .customer-card { border-radius: 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 24px; }
    .customer-card .card-header { background: #f8fafc; border-bottom: 1px solid #eef2f6; padding: 16px 20px; font-weight: 600; }
    .customer-card .card-body { padding: 20px; }
    .required:after { content: "*"; color: #dc3545; margin-left: 4px; }
    .preview-image { width: 100px; height: 100px; object-fit: cover; border-radius: 8px; border: 1px solid #ddd; margin-top: 8px; }
    .existing-file { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 8px 12px; }
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
                                    <h4 class="mb-1"><?php echo $edit_id > 0 ? 'Edit' : 'Add'; ?> Pawn Customer</h4>
                                    <p class="text-muted mb-0"><?php echo $edit_id > 0 ? 'Update' : 'Register a new'; ?> pawn broking customer</p>
                                </div>
                                <div>
                                    <?php if ($edit_id > 0): ?>
                                        <a href="pawn-customer-view.php?id=<?php echo $edit_id; ?>" class="btn btn-outline-info">
                                            <i class="fas fa-eye"></i> View Customer
                                        </a>
                                    <?php endif; ?>
                                    <a href="pawn-customers.php" class="btn btn-outline-secondary">
                                        <i class="fas fa-list"></i> Customer List
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
                    
                    <form method="POST" enctype="multipart/form-data" id="pawnCustomerForm" onsubmit="return validateForm()">
                        <input type="hidden" name="submit_pawn_customer" value="1">
                        <?php if ($edit_id > 0): ?>
                            <input type="hidden" name="edit_id" value="<?php echo $edit_id; ?>">
                        <?php endif; ?>
                        
                        <!-- Customer Selection / New Customer -->
                        <div class="card customer-card">
                            <div class="card-header">
                                <i class="fas fa-user me-2"></i> Customer Information
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Select Existing Customer</label>
                                        <select name="customer_id" id="customer_id" class="form-select" onchange="toggleNewCustomerForm()">
                                            <option value="">-- Select Existing Customer --</option>
                                            <?php foreach ($customers as $c): ?>
                                                <option value="<?php echo $c['id']; ?>" 
                                                    data-name="<?php echo h($c['customer_name']); ?>"
                                                    data-mobile="<?php echo h($c['mobile']); ?>"
                                                    data-address="<?php echo h($c['address_line1'] . ' ' . ($c['city'] ?? '')); ?>"
                                                    <?php echo (($form_values['customer_id'] ?? 0) == $c['id']) ? 'selected' : ''; ?>
                                                    <?php echo ($edit_id > 0 && ($form_values['customer_id'] ?? 0) == $c['id']) ? 'selected' : ''; ?>>
                                                    <?php echo h($c['customer_name']); ?> (<?php echo h($c['customer_code']); ?>) - <?php echo h($c['mobile']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="text-muted">Select existing customer or fill new customer details below</small>
                                    </div>
                                </div>
                                
                                <hr class="my-3">
                                
                                <div id="newCustomerForm" style="display: <?php echo (($form_values['customer_id'] ?? 0) > 0 && $edit_id == 0) ? 'none' : 'block'; ?>;">
                                    <h6 class="mb-3"><i class="fas fa-plus-circle"></i> New Customer Details</h6>
                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <label class="form-label required">Customer Name</label>
                                            <input type="text" name="customer_name_new" id="customer_name_new" class="form-control" value="<?php echo h($form_values['customer_name_new'] ?? ($form_values['customer_name'] ?? '')); ?>">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Mobile Number</label>
                                            <input type="text" name="customer_mobile_new" id="customer_mobile_new" class="form-control" value="<?php echo h($form_values['customer_mobile_new'] ?? ($form_values['customer_mobile'] ?? '')); ?>">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Email</label>
                                            <input type="email" name="customer_email_new" class="form-control" value="<?php echo h($form_values['customer_email_new'] ?? ''); ?>">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Date of Birth</label>
                                            <input type="date" name="date_of_birth_new" class="form-control" value="<?php echo h($form_values['date_of_birth'] ?? ''); ?>">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Anniversary Date</label>
                                            <input type="date" name="anniversary_date_new" class="form-control" value="<?php echo h($form_values['anniversary_date'] ?? ''); ?>">
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label">Address</label>
                                            <textarea name="customer_address_new" class="form-control" rows="2"><?php echo h($form_values['customer_address_new'] ?? ($form_values['address'] ?? '')); ?></textarea>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">City</label>
                                            <input type="text" name="customer_city_new" class="form-control" value="<?php echo h($form_values['customer_city_new'] ?? ($form_values['city'] ?? '')); ?>">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">State</label>
                                            <input type="text" name="customer_state_new" class="form-control" value="<?php echo h($form_values['customer_state_new'] ?? ($form_values['state'] ?? '')); ?>">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Pincode</label>
                                            <input type="text" name="customer_pincode_new" class="form-control" value="<?php echo h($form_values['customer_pincode_new'] ?? ($form_values['pincode'] ?? '')); ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Pawn Customer Details -->
                        <div class="card customer-card">
                            <div class="card-header">
                                <i class="fas fa-hand-holding-usd me-2"></i> Pawn Customer Details
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Guardian / Father's Name</label>
                                        <input type="text" name="guardian_name" class="form-control" value="<?php echo h($form_values['guardian_name'] ?? ''); ?>" placeholder="Father/Husband name">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Occupation</label>
                                        <select name="occupation" class="form-select">
                                            <option value="">-- Select --</option>
                                            <option value="Business" <?php echo (($form_values['occupation'] ?? '') == 'Business') ? 'selected' : ''; ?>>Business</option>
                                            <option value="Salaried" <?php echo (($form_values['occupation'] ?? '') == 'Salaried') ? 'selected' : ''; ?>>Salaried</option>
                                            <option value="Self Employed" <?php echo (($form_values['occupation'] ?? '') == 'Self Employed') ? 'selected' : ''; ?>>Self Employed</option>
                                            <option value="Professional" <?php echo (($form_values['occupation'] ?? '') == 'Professional') ? 'selected' : ''; ?>>Professional</option>
                                            <option value="Retired" <?php echo (($form_values['occupation'] ?? '') == 'Retired') ? 'selected' : ''; ?>>Retired</option>
                                            <option value="Housewife" <?php echo (($form_values['occupation'] ?? '') == 'Housewife') ? 'selected' : ''; ?>>Housewife</option>
                                            <option value="Student" <?php echo (($form_values['occupation'] ?? '') == 'Student') ? 'selected' : ''; ?>>Student</option>
                                            <option value="Other" <?php echo (($form_values['occupation'] ?? '') == 'Other') ? 'selected' : ''; ?>>Other</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Annual Income (₹)</label>
                                        <input type="number" step="0.01" name="annual_income" class="form-control" value="<?php echo h($form_values['annual_income'] ?? '0'); ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Reference Details -->
                        <div class="card customer-card">
                            <div class="card-header">
                                <i class="fas fa-user-friends me-2"></i> Reference Details
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Reference Person Name</label>
                                        <input type="text" name="reference_name" class="form-control" value="<?php echo h($form_values['reference_name'] ?? ''); ?>" placeholder="Name of reference person">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Reference Mobile No</label>
                                        <input type="text" name="reference_mobile" class="form-control" value="<?php echo h($form_values['reference_mobile'] ?? ''); ?>" placeholder="Reference contact number">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- KYC Documents -->
                        <div class="card customer-card">
                            <div class="card-header">
                                <i class="fas fa-id-card me-2"></i> KYC Documents
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Customer Photo</label>
                                        <input type="file" name="photo" class="form-control" accept="image/*" onchange="previewImage(this, 'photo_preview')">
                                        <div id="photo_preview" class="mt-2">
                                            <?php if (!empty($form_values['photo_path']) && file_exists($form_values['photo_path'])): ?>
                                                <div class="existing-file">
                                                    <img src="<?php echo h($form_values['photo_path']); ?>" class="preview-image" alt="Current Photo">
                                                    <input type="hidden" name="existing_photo" value="<?php echo h($form_values['photo_path']); ?>">
                                                    <small class="text-muted d-block">Current photo (upload new to replace)</small>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Signature</label>
                                        <input type="file" name="signature" class="form-control" accept="image/*" onchange="previewImage(this, 'signature_preview')">
                                        <div id="signature_preview" class="mt-2">
                                            <?php if (!empty($form_values['signature_path']) && file_exists($form_values['signature_path'])): ?>
                                                <div class="existing-file">
                                                    <img src="<?php echo h($form_values['signature_path']); ?>" class="preview-image" alt="Current Signature">
                                                    <input type="hidden" name="existing_signature" value="<?php echo h($form_values['signature_path']); ?>">
                                                    <small class="text-muted d-block">Current signature (upload new to replace)</small>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">KYC Document</label>
                                        <input type="file" name="kyc_document" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                                        <div id="kyc_preview" class="mt-2">
                                            <?php if (!empty($form_values['kyc_document']) && file_exists($form_values['kyc_document'])): ?>
                                                <div class="existing-file">
                                                    <a href="<?php echo h($form_values['kyc_document']); ?>" target="_blank" class="btn btn-sm btn-outline-info">
                                                        <i class="fas fa-file"></i> View Current Document
                                                    </a>
                                                    <input type="hidden" name="existing_kyc" value="<?php echo h($form_values['kyc_document']); ?>">
                                                    <small class="text-muted d-block">Current document (upload new to replace)</small>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="col-md-12">
                                        <div class="form-check">
                                            <input type="checkbox" name="kyc_verified" class="form-check-input" id="kyc_verified" value="1" <?php echo (($form_values['kyc_verified'] ?? 0) == 1) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="kyc_verified">
                                                <i class="fas fa-check-circle text-success"></i> KYC Verified
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Credit & Risk Assessment -->
                        <div class="card customer-card">
                            <div class="card-header">
                                <i class="fas fa-chart-line me-2"></i> Credit & Risk Assessment
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Credit Limit (₹)</label>
                                        <input type="number" step="0.01" name="credit_limit" class="form-control" value="<?php echo h($form_values['credit_limit'] ?? '0'); ?>" placeholder="Maximum loan amount allowed">
                                        <small class="text-muted">Maximum loan amount this customer can avail</small>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Risk Category</label>
                                        <select name="risk_category" class="form-select">
                                            <option value="Low" <?php echo (($form_values['risk_category'] ?? 'Low') == 'Low') ? 'selected' : ''; ?>>Low Risk</option>
                                            <option value="Medium" <?php echo (($form_values['risk_category'] ?? '') == 'Medium') ? 'selected' : ''; ?>>Medium Risk</option>
                                            <option value="High" <?php echo (($form_values['risk_category'] ?? '') == 'High') ? 'selected' : ''; ?>>High Risk</option>
                                        </select>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Additional Notes</label>
                                        <textarea name="notes" class="form-control" rows="3" placeholder="Any additional information about the customer..."><?php echo h($form_values['notes'] ?? ''); ?></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Submit Buttons -->
                        <div class="row mb-5">
                            <div class="col-12 text-end">
                                <button type="reset" class="btn btn-light me-2" onclick="resetForm()"><i class="fas fa-undo"></i> Reset</button>
                                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> <?php echo $edit_id > 0 ? 'Update' : 'Save'; ?> Pawn Customer</button>
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
        function toggleNewCustomerForm() {
            const customerSelect = document.getElementById('customer_id');
            const newCustomerForm = document.getElementById('newCustomerForm');
            const customerNameNew = document.getElementById('customer_name_new');
            
            if (customerSelect.value === '') {
                newCustomerForm.style.display = 'block';
                if (customerNameNew) customerNameNew.required = true;
            } else {
                newCustomerForm.style.display = 'none';
                if (customerNameNew) customerNameNew.required = false;
                
                // Auto-fill existing customer details
                const selectedOption = customerSelect.options[customerSelect.selectedIndex];
                if (selectedOption && selectedOption.value) {
                    document.getElementById('customer_name_new').value = selectedOption.dataset.name || '';
                    document.getElementById('customer_mobile_new').value = selectedOption.dataset.mobile || '';
                }
            }
        }
        
        function previewImage(input, previewId) {
            const preview = document.getElementById(previewId);
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.innerHTML = `<img src="${e.target.result}" class="preview-image">`;
                };
                reader.readAsDataURL(input.files[0]);
            }
        }
        
        function validateForm() {
            const customerSelect = document.getElementById('customer_id').value;
            const customerNameNew = document.getElementById('customer_name_new').value.trim();
            
            if (customerSelect === '' && customerNameNew === '') {
                alert("Please either select an existing customer or enter a new customer name");
                return false;
            }
            
            return true;
        }
        
        function resetForm() {
            if (confirm('Are you sure you want to reset the form? All entered data will be lost.')) {
                location.reload();
            }
        }
        
        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            toggleNewCustomerForm();
        });
    </script>
</body>
</html>