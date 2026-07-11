<?php
session_start();
date_default_timezone_set('Asia/Kolkata');

require_once 'includes/config.php';

// Check if config loaded properly
if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not available.');
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn->set_charset('utf8mb4');

// Helper function for HTML escaping
if (!function_exists('h')) {
    function h($value) {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

// Page variables
$pageTitle = 'Add Pawn Category';
$currentPage = 'pawn-category-add';

/* -------------------------------------------------------
   AUTH CHECK
------------------------------------------------------- */
function isUserLoggedIn() {
    return isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0;
}

if (!isUserLoggedIn()) {
    $current_file = basename($_SERVER['PHP_SELF']);
    if ($current_file != 'login.php') {
        header('Location: login.php');
        exit;
    }
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$businessId = (int)($_SESSION['business_id'] ?? 0);

// If business_id is not set in session, try to get from database
if ($businessId <= 0 && isUserLoggedIn()) {
    $stmt = $conn->prepare("SELECT business_id FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $businessId = (int)$row['business_id'];
        $_SESSION['business_id'] = $businessId;
    }
    $stmt->close();
}

if ($businessId <= 0) {
    die('Business not configured. Please contact administrator.');
}

// Get user role for permission checks
$userRole = $_SESSION['role_name'] ?? '';
$isAdmin = in_array($userRole, ['Super Admin', 'Admin', 'Manager']);

// Check if pawn_categories table exists, if not create it
function createPawnCategoriesTable($conn, $businessId) {
    $tableCheck = $conn->query("SHOW TABLES LIKE 'pawn_categories'");
    if ($tableCheck->num_rows == 0) {
        $sql = "CREATE TABLE IF NOT EXISTS `pawn_categories` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `business_id` int(10) UNSIGNED NOT NULL,
            `category_code` varchar(30) NOT NULL,
            `category_name` varchar(100) NOT NULL,
            `category_type` enum('Metal','Ornament','Document','Other') DEFAULT 'Ornament',
            `metal_type` enum('Gold','Silver','Platinum','Other') DEFAULT NULL,
            `purity_standard` varchar(50) DEFAULT NULL,
            `min_purity_percent` decimal(5,2) DEFAULT NULL,
            `max_purity_percent` decimal(5,2) DEFAULT NULL,
            `default_interest_rate` decimal(8,2) DEFAULT NULL,
            `max_loan_percent` decimal(5,2) DEFAULT 70.00,
            `storage_fee_percent` decimal(5,2) DEFAULT 0.00,
            `valuation_method` enum('Weight','Piece','Stone','Combined') DEFAULT 'Weight',
            `requires_certificate` tinyint(1) DEFAULT 0,
            `requires_valuation` tinyint(1) DEFAULT 1,
            `is_active` tinyint(1) DEFAULT 1,
            `description` text DEFAULT NULL,
            `created_by` int(10) UNSIGNED DEFAULT NULL,
            `created_at` timestamp NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_category_code_business` (`business_id`,`category_code`),
            UNIQUE KEY `uniq_category_name_business` (`business_id`,`category_name`),
            KEY `idx_pawn_categories_business` (`business_id`),
            KEY `idx_pawn_categories_type` (`category_type`),
            KEY `idx_pawn_categories_metal` (`metal_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        if ($conn->query($sql)) {
            // Insert default categories
            $defaultCategories = [
                ['ORN001', 'Gold Chain', 'Ornament', 'Gold', '22K (916)', 91.6, 91.6, 1.00, 75.00, 'Weight'],
                ['ORN002', 'Gold Ring', 'Ornament', 'Gold', '22K (916)', 91.6, 91.6, 1.00, 70.00, 'Weight'],
                ['ORN003', 'Gold Earrings', 'Ornament', 'Gold', '22K (916)', 91.6, 91.6, 1.00, 65.00, 'Weight'],
                ['ORN004', 'Gold Bangles', 'Ornament', 'Gold', '22K (916)', 91.6, 91.6, 1.00, 75.00, 'Weight'],
                ['ORN005', 'Gold Mangalsutra', 'Ornament', 'Gold', '22K (916)', 91.6, 91.6, 1.00, 70.00, 'Combined'],
                ['ORN006', 'Gold Pendant', 'Ornament', 'Gold', '22K (916)', 91.6, 91.6, 1.00, 65.00, 'Combined'],
                ['ORN007', 'Silver Chain', 'Ornament', 'Silver', '925', 92.5, 92.5, 0.75, 70.00, 'Weight'],
                ['ORN008', 'Silver Ring', 'Ornament', 'Silver', '925', 92.5, 92.5, 0.75, 65.00, 'Weight'],
                ['ORN009', 'Silver Bangles', 'Ornament', 'Silver', '925', 92.5, 92.5, 0.75, 70.00, 'Weight'],
                ['MTL001', 'Gold Coin', 'Metal', 'Gold', '24K (999)', 99.9, 99.9, 0.85, 90.00, 'Weight'],
                ['MTL002', 'Silver Coin', 'Metal', 'Silver', '999', 99.9, 99.9, 0.60, 85.00, 'Weight'],
                ['MTL003', 'Gold Bar', 'Metal', 'Gold', '24K (999.9)', 99.99, 99.99, 0.80, 88.00, 'Weight'],
                ['DOC001', 'Property Document', 'Document', NULL, NULL, NULL, NULL, 1.50, 50.00, 'Piece'],
                ['DOC002', 'Gold Loan Certificate', 'Document', NULL, NULL, NULL, NULL, 1.25, 60.00, 'Piece'],
                ['DOC003', 'Fixed Deposit Receipt', 'Document', NULL, NULL, NULL, NULL, 1.00, 75.00, 'Piece']
            ];
            
            $insertSql = "INSERT INTO pawn_categories (business_id, category_code, category_name, category_type, metal_type, purity_standard, min_purity_percent, max_purity_percent, default_interest_rate, max_loan_percent, valuation_method) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($insertSql);
            
            foreach ($defaultCategories as $cat) {
                $stmt->bind_param('isssssdddd s', $businessId, $cat[0], $cat[1], $cat[2], $cat[3], $cat[4], $cat[5], $cat[6], $cat[7], $cat[8], $cat[9]);
                $stmt->execute();
            }
            $stmt->close();
        }
    }
}

// Create table if not exists
createPawnCategoriesTable($conn, $businessId);

// Process form submission
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize and validate input
    $category_name = trim($_POST['category_name'] ?? '');
    $category_type = trim($_POST['category_type'] ?? 'Ornament');
    $metal_type = !empty($_POST['metal_type']) ? trim($_POST['metal_type']) : null;
    $purity_standard = trim($_POST['purity_standard'] ?? '');
    $min_purity = !empty($_POST['min_purity']) ? (float)$_POST['min_purity'] : null;
    $max_purity = !empty($_POST['max_purity']) ? (float)$_POST['max_purity'] : null;
    $default_interest_rate = (float)($_POST['default_interest_rate'] ?? 0);
    $max_loan_percent = (float)($_POST['max_loan_percent'] ?? 70);
    $storage_fee = (float)($_POST['storage_fee'] ?? 0);
    $valuation_method = trim($_POST['valuation_method'] ?? 'Weight');
    $requires_certificate = isset($_POST['requires_certificate']) ? 1 : 0;
    $requires_valuation = isset($_POST['requires_valuation']) ? 1 : 0;
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $description = trim($_POST['description'] ?? '');
    
    // Validation
    $errors = [];
    
    if (empty($category_name)) {
        $errors[] = 'Category name is required.';
    }
    
    if (strlen($category_name) < 2) {
        $errors[] = 'Category name must be at least 2 characters long.';
    }
    
    if ($default_interest_rate < 0) {
        $errors[] = 'Interest rate cannot be negative.';
    }
    
    if ($default_interest_rate > 50) {
        $errors[] = 'Interest rate seems too high (max 50%). Please verify.';
    }
    
    if ($max_loan_percent < 0 || $max_loan_percent > 100) {
        $errors[] = 'Maximum loan percentage must be between 0 and 100.';
    }
    
    if ($storage_fee < 0) {
        $errors[] = 'Storage fee cannot be negative.';
    }
    
    // Validate purity range for metal categories
    if (in_array($category_type, ['Ornament', 'Metal'])) {
        if ($min_purity !== null && $max_purity !== null) {
            if ($min_purity > $max_purity) {
                $errors[] = 'Minimum purity cannot be greater than maximum purity.';
            }
            if ($min_purity < 0 || $max_purity > 100) {
                $errors[] = 'Purity percentage must be between 0 and 100.';
            }
        }
    }
    
    // Check if category already exists
    $checkQuery = "SELECT id FROM pawn_categories WHERE business_id = ? AND category_name = ?";
    $checkStmt = $conn->prepare($checkQuery);
    $checkStmt->bind_param('is', $businessId, $category_name);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    if ($checkResult->num_rows > 0) {
        $errors[] = 'A category with this name already exists.';
    }
    $checkStmt->close();
    
    if (empty($errors)) {
        // Generate category code
        $prefix = '';
        switch ($category_type) {
            case 'Ornament':
                $prefix = 'ORN';
                break;
            case 'Metal':
                $prefix = 'MTL';
                break;
            case 'Document':
                $prefix = 'DOC';
                break;
            default:
                $prefix = 'CAT';
        }
        
        // Get the last category code for this business and type
        $query = "SELECT category_code FROM pawn_categories 
                  WHERE business_id = ? AND category_code LIKE ? 
                  ORDER BY id DESC LIMIT 1";
        $likePattern = $prefix . '%';
        $stmt = $conn->prepare($query);
        $stmt->bind_param('is', $businessId, $likePattern);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $lastNumber = 0;
        if ($row = $result->fetch_assoc()) {
            $lastCode = $row['category_code'];
            $numberPart = (int) substr($lastCode, strlen($prefix));
            $lastNumber = $numberPart;
        }
        $stmt->close();
        
        $newNumber = $lastNumber + 1;
        $category_code = $prefix . str_pad($newNumber, 4, '0', STR_PAD_LEFT);
        
        // Insert into pawn_categories table
        $insertSql = "INSERT INTO pawn_categories (
            business_id, category_code, category_name, category_type, metal_type,
            purity_standard, min_purity_percent, max_purity_percent, default_interest_rate,
            max_loan_percent, storage_fee_percent, valuation_method, requires_certificate,
            requires_valuation, is_active, description, created_by, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $stmt = $conn->prepare($insertSql);
        if (!$stmt) {
            $error = "Failed to prepare statement: " . $conn->error;
        } else {
            $stmt->bind_param(
                'isssssdddddsiiiss',
                $businessId, $category_code, $category_name, $category_type, $metal_type,
                $purity_standard, $min_purity, $max_purity, $default_interest_rate,
                $max_loan_percent, $storage_fee, $valuation_method, $requires_certificate,
                $requires_valuation, $is_active, $description, $userId
            );
            
            if ($stmt->execute()) {
                $_SESSION['toastr_message'] = [
                    'type' => 'success',
                    'message' => 'Pawn category added successfully! Category Code: ' . $category_code
                ];
                header('Location: pawn-categories.php');
                exit;
            } else {
                $error = 'Failed to add category: ' . $stmt->error;
            }
            $stmt->close();
        }
    } else {
        $error = implode('<br>', $errors);
    }
}

include('includes/head.php');
?>

<style>
    .form-section {
        background: #f8f9fa;
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 25px;
        border-left: 4px solid #556ee6;
        transition: all 0.3s ease;
    }
    .form-section:hover {
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    .form-section h5 {
        margin-bottom: 20px;
        color: #556ee6;
        font-weight: 600;
    }
    .required-field:after {
        content: '*';
        color: red;
        margin-left: 4px;
    }
    .card-header-custom {
        background: linear-gradient(135deg, #556ee6 0%, #434fd3 100%);
        color: white;
        padding: 15px 20px;
        border-radius: 8px 8px 0 0;
    }
    .info-text {
        font-size: 12px;
        color: #6c757d;
        margin-top: 5px;
    }
    .preview-box {
        background: #e9ecef;
        border-radius: 6px;
        padding: 12px;
        margin-top: 10px;
    }
    .preview-box strong {
        color: #2c3e50;
        min-width: 140px;
        display: inline-block;
    }
    .preview-box span {
        color: #556ee6;
        font-weight: 500;
    }
    .invalid-feedback {
        display: block;
        font-size: 12px;
    }
    .is-invalid {
        border-color: #dc3545;
    }
    .badge-preview {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 4px;
        font-size: 10px;
        font-weight: 600;
        margin-left: 8px;
    }
    .badge-ornament { background: #ffeaa7; color: #d63031; }
    .badge-metal { background: #dfe6e9; color: #2d3436; }
    .badge-document { background: #a29bfe; color: white; }
    .badge-other { background: #74b9ff; color: white; }
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

                <div class="row">
                    <div class="col-12">
                        <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                            <h4 class="mb-sm-0">Add Pawn Category</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="pawn-categories.php">Pawn Categories</a></li>
                                    <li class="breadcrumb-item active">Add Category</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle"></i> <strong>Error:</strong> 
                    <?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-header-custom">
                        <h5 class="mb-0"><i class="fas fa-tags me-2"></i> Category Information</h5>
                        <small class="text-white-50">Create a new pawn category with all required settings</small>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="" id="categoryForm" novalidate>
                            
                            <!-- Basic Information Section -->
                            <div class="form-section">
                                <h5><i class="fas fa-info-circle me-2"></i> Basic Information</h5>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label required-field">Category Name</label>
                                        <input type="text" name="category_name" class="form-control" 
                                               value="<?php echo h($_POST['category_name'] ?? ''); ?>" 
                                               placeholder="e.g., Gold Chain, Silver Ring, Property Document"
                                               required>
                                        <div class="info-text">Example: Gold Chain, Silver Ring, Property Document</div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label required-field">Category Type</label>
                                        <select name="category_type" id="category_type" class="form-select" required>
                                            <option value="Ornament" <?php echo (($_POST['category_type'] ?? '') == 'Ornament') ? 'selected' : ''; ?>>🏷️ Ornament</option>
                                            <option value="Metal" <?php echo (($_POST['category_type'] ?? '') == 'Metal') ? 'selected' : ''; ?>>💰 Metal (Bars/Coins)</option>
                                            <option value="Document" <?php echo (($_POST['category_type'] ?? '') == 'Document') ? 'selected' : ''; ?>>📄 Document</option>
                                            <option value="Other" <?php echo (($_POST['category_type'] ?? '') == 'Other') ? 'selected' : ''; ?>>📦 Other</option>
                                        </select>
                                        <div class="info-text">Type of category - affects rating and valuation method</div>
                                    </div>
                                </div>
                            </div>

                            <!-- Metal Specific Information Section -->
                            <div class="form-section" id="metalSection">
                                <h5><i class="fas fa-gem me-2"></i> Metal Specific Information</h5>
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Metal Type</label>
                                        <select name="metal_type" id="metal_type" class="form-select">
                                            <option value="">Select Metal Type</option>
                                            <option value="Gold" <?php echo (($_POST['metal_type'] ?? '') == 'Gold') ? 'selected' : ''; ?>>🥇 Gold</option>
                                            <option value="Silver" <?php echo (($_POST['metal_type'] ?? '') == 'Silver') ? 'selected' : ''; ?>>🥈 Silver</option>
                                            <option value="Platinum" <?php echo (($_POST['metal_type'] ?? '') == 'Platinum') ? 'selected' : ''; ?>>💎 Platinum</option>
                                            <option value="Other" <?php echo (($_POST['metal_type'] ?? '') == 'Other') ? 'selected' : ''; ?>>📦 Other</option>
                                        </select>
                                        <div class="info-text">Type of metal for this category</div>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Purity Standard</label>
                                        <input type="text" name="purity_standard" class="form-control" 
                                               value="<?php echo h($_POST['purity_standard'] ?? ''); ?>"
                                               placeholder="e.g., 22K (916), 24K (999), 925"
                                               list="puritySuggestions">
                                        <datalist id="puritySuggestions">
                                            <option value="24K (999)">
                                            <option value="22K (916)">
                                            <option value="18K (750)">
                                            <option value="14K (585)">
                                            <option value="999 (Fine Silver)">
                                            <option value="925 (Sterling Silver)">
                                            <option value="900 (Coin Silver)">
                                            <option value="999 (Fine Platinum)">
                                            <option value="950 (Platinum)">
                                        </datalist>
                                        <div class="info-text">Standard purity mark for this category</div>
                                    </div>
                                    <div class="col-md-2 mb-3">
                                        <label class="form-label">Min Purity (%)</label>
                                        <input type="number" step="0.01" name="min_purity" class="form-control" 
                                               value="<?php echo h($_POST['min_purity'] ?? ''); ?>"
                                               placeholder="0-100">
                                        <div class="info-text">Minimum acceptable purity</div>
                                    </div>
                                    <div class="col-md-2 mb-3">
                                        <label class="form-label">Max Purity (%)</label>
                                        <input type="number" step="0.01" name="max_purity" class="form-control" 
                                               value="<?php echo h($_POST['max_purity'] ?? ''); ?>"
                                               placeholder="0-100">
                                        <div class="info-text">Maximum purity possible</div>
                                    </div>
                                </div>
                            </div>

                            <!-- Valuation & Loan Settings Section -->
                            <div class="form-section">
                                <h5><i class="fas fa-calculator me-2"></i> Valuation & Loan Settings</h5>
                                <div class="row">
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label required-field">Default Interest Rate (%)</label>
                                        <input type="number" step="0.01" name="default_interest_rate" class="form-control" 
                                               value="<?php echo h($_POST['default_interest_rate'] ?? '1.00'); ?>" 
                                               required min="0" max="50">
                                        <div class="info-text">Monthly interest rate for this category (0-50%)</div>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label required-field">Max Loan Percentage (%)</label>
                                        <input type="number" step="0.01" name="max_loan_percent" class="form-control" 
                                               value="<?php echo h($_POST['max_loan_percent'] ?? '70'); ?>" 
                                               required min="0" max="100">
                                        <div class="info-text">Maximum % of value that can be given as loan (0-100%)</div>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Storage Fee (%)</label>
                                        <input type="number" step="0.01" name="storage_fee" class="form-control" 
                                               value="<?php echo h($_POST['storage_fee'] ?? '0'); ?>"
                                               min="0" max="10">
                                        <div class="info-text">Monthly storage/handling fee percentage (0-10%)</div>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label required-field">Valuation Method</label>
                                        <select name="valuation_method" class="form-select" required>
                                            <option value="Weight" <?php echo (($_POST['valuation_method'] ?? '') == 'Weight') ? 'selected' : ''; ?>>⚖️ By Weight</option>
                                            <option value="Piece" <?php echo (($_POST['valuation_method'] ?? '') == 'Piece') ? 'selected' : ''; ?>>🔢 By Piece (Fixed Value)</option>
                                            <option value="Stone" <?php echo (($_POST['valuation_method'] ?? '') == 'Stone') ? 'selected' : ''; ?>>💎 By Stone Value</option>
                                            <option value="Combined" <?php echo (($_POST['valuation_method'] ?? '') == 'Combined') ? 'selected' : ''; ?>>🔗 Combined (Weight + Stone)</option>
                                        </select>
                                        <div class="info-text">How the item value is calculated</div>
                                    </div>
                                </div>
                            </div>

                            <!-- Additional Requirements Section -->
                            <div class="form-section">
                                <h5><i class="fas fa-clipboard-list me-2"></i> Additional Requirements</h5>
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <div class="form-check form-switch">
                                            <input type="checkbox" name="requires_certificate" class="form-check-input" 
                                                   id="requires_certificate" value="1" <?php echo isset($_POST['requires_certificate']) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="requires_certificate">
                                                <i class="fas fa-certificate"></i> Requires Purity Certificate
                                            </label>
                                        </div>
                                        <div class="info-text">Check if items need hallmarked certificate</div>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <div class="form-check form-switch">
                                            <input type="checkbox" name="requires_valuation" class="form-check-input" 
                                                   id="requires_valuation" value="1" <?php echo (!isset($_POST['requires_valuation']) || $_POST['requires_valuation'] == '1') ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="requires_valuation">
                                                <i class="fas fa-search-dollar"></i> Requires Professional Valuation
                                            </label>
                                        </div>
                                        <div class="info-text">Check if third-party valuation is needed</div>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <div class="form-check form-switch">
                                            <input type="checkbox" name="is_active" class="form-check-input" 
                                                   id="is_active" value="1" <?php echo (!isset($_POST['is_active']) || $_POST['is_active'] == '1') ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="is_active">
                                                <i class="fas fa-check-circle"></i> Active
                                            </label>
                                        </div>
                                        <div class="info-text">Enable this category for use</div>
                                    </div>
                                </div>
                            </div>

                            <!-- Description Section -->
                            <div class="form-section">
                                <h5><i class="fas fa-align-left me-2"></i> Description</h5>
                                <div class="row">
                                    <div class="col-12 mb-3">
                                        <label class="form-label">Category Description</label>
                                        <textarea name="description" class="form-control" rows="4" 
                                                  placeholder="Describe the category, accepted items, special conditions, etc..."><?php echo h($_POST['description'] ?? ''); ?></textarea>
                                        <div class="info-text">Describe the category, accepted items, special conditions, etc.</div>
                                    </div>
                                </div>
                            </div>

                            <!-- Preview Section -->
                            <div class="form-section">
                                <h5><i class="fas fa-eye me-2"></i> Preview</h5>
                                <div class="row">
                                    <div class="col-md-12">
                                        <div class="preview-box" id="previewBox">
                                            <strong>Category Code:</strong> <span id="previewCode">[Will be auto-generated]</span><br>
                                            <strong>Category Name:</strong> <span id="previewName">-</span><br>
                                            <strong>Type:</strong> <span id="previewType">-</span> <span id="previewTypeBadge"></span><br>
                                            <strong>Interest Rate:</strong> <span id="previewInterest">-</span><br>
                                            <strong>Max Loan %:</strong> <span id="previewMaxLoan">-</span><br>
                                            <strong>Valuation Method:</strong> <span id="previewValuation">-</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Form Actions -->
                            <div class="d-flex justify-content-between align-items-center mt-4">
                                <a href="pawn-categories.php" class="btn btn-secondary">
                                    <i class="fas fa-arrow-left"></i> Back to Categories
                                </a>
                                <div>
                                    <button type="reset" class="btn btn-warning me-2">
                                        <i class="fas fa-undo"></i> Reset
                                    </button>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save"></i> Save Category
                                    </button>
                                </div>
                            </div>
                        </form>
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
$(document).ready(function() {
    // Toggle metal section based on category type
    function toggleMetalSection() {
        var categoryType = $('#category_type').val();
        if (categoryType === 'Ornament' || categoryType === 'Metal') {
            $('#metalSection').slideDown(300);
        } else {
            $('#metalSection').slideUp(300);
            // Clear metal-related fields
            $('#metal_type').val('');
            $('input[name="purity_standard"]').val('');
            $('input[name="min_purity"]').val('');
            $('input[name="max_purity"]').val('');
        }
    }
    
    $('#category_type').change(function() {
        toggleMetalSection();
        updatePreview();
    });
    
    toggleMetalSection();
    
    // Update preview
    function updatePreview() {
        var categoryName = $('input[name="category_name"]').val() || '-';
        var categoryType = $('#category_type option:selected').text().replace(/[🏷️💰📄📦]/g, '').trim() || '-';
        var interestRate = $('input[name="default_interest_rate"]').val() || '0';
        var maxLoan = $('input[name="max_loan_percent"]').val() || '0';
        var valuationMethod = $('select[name="valuation_method"] option:selected').text().replace(/[⚖️🔢💎🔗]/g, '').trim() || '-';
        
        $('#previewName').text(categoryName);
        $('#previewType').text(categoryType);
        $('#previewInterest').text(interestRate + '%');
        $('#previewMaxLoan').text(maxLoan + '%');
        $('#previewValuation').text(valuationMethod);
        
        // Update badge
        var typeValue = $('#category_type').val();
        var badgeClass = '';
        var badgeText = '';
        switch(typeValue) {
            case 'Ornament':
                badgeClass = 'badge-ornament';
                badgeText = '🏷️ Ornament';
                break;
            case 'Metal':
                badgeClass = 'badge-metal';
                badgeText = '💰 Metal';
                break;
            case 'Document':
                badgeClass = 'badge-document';
                badgeText = '📄 Document';
                break;
            default:
                badgeClass = 'badge-other';
                badgeText = '📦 Other';
        }
        $('#previewTypeBadge').html('<span class="badge-preview ' + badgeClass + '">' + badgeText + '</span>');
        
        // Estimate category code preview
        var prefix = '';
        switch(typeValue) {
            case 'Ornament': prefix = 'ORN'; break;
            case 'Metal': prefix = 'MTL'; break;
            case 'Document': prefix = 'DOC'; break;
            default: prefix = 'CAT';
        }
        $('#previewCode').text(prefix + 'XXXX');
    }
    
    $('input[name="category_name"], input[name="default_interest_rate"], input[name="max_loan_percent"], select[name="valuation_method"]').on('keyup change', updatePreview);
    
    // Form validation
    $('#categoryForm').on('submit', function(e) {
        let isValid = true;
        let errorMessages = [];
        
        const requiredFields = ['category_name', 'category_type', 'default_interest_rate', 'max_loan_percent', 'valuation_method'];
        
        requiredFields.forEach(function(field) {
            const input = $('[name="' + field + '"]');
            if (!input.val()) {
                input.addClass('is-invalid');
                isValid = false;
                errorMessages.push(field.replace('_', ' ').toUpperCase() + ' is required');
            } else {
                input.removeClass('is-invalid');
            }
        });
        
        // Validate percentages
        var maxLoanPercent = parseFloat($('input[name="max_loan_percent"]').val());
        if (isNaN(maxLoanPercent) || maxLoanPercent < 0 || maxLoanPercent > 100) {
            $('input[name="max_loan_percent"]').addClass('is-invalid');
            isValid = false;
            errorMessages.push('Maximum loan percentage must be between 0 and 100');
        }
        
        var interestRate = parseFloat($('input[name="default_interest_rate"]').val());
        if (isNaN(interestRate) || interestRate < 0) {
            $('input[name="default_interest_rate"]').addClass('is-invalid');
            isValid = false;
            errorMessages.push('Interest rate cannot be negative');
        }
        if (interestRate > 50) {
            $('input[name="default_interest_rate"]').addClass('is-invalid');
            isValid = false;
            errorMessages.push('Interest rate seems too high (max 50%)');
        }
        
        var storageFee = parseFloat($('input[name="storage_fee"]').val());
        if (isNaN(storageFee) || storageFee < 0) {
            $('input[name="storage_fee"]').addClass('is-invalid');
            isValid = false;
            errorMessages.push('Storage fee cannot be negative');
        }
        
        // Validate purity range
        var categoryType = $('#category_type').val();
        if (categoryType === 'Ornament' || categoryType === 'Metal') {
            var minPurity = parseFloat($('input[name="min_purity"]').val());
            var maxPurity = parseFloat($('input[name="max_purity"]').val());
            
            if (!isNaN(minPurity) && !isNaN(maxPurity)) {
                if (minPurity > maxPurity) {
                    $('input[name="min_purity"], input[name="max_purity"]').addClass('is-invalid');
                    isValid = false;
                    errorMessages.push('Minimum purity cannot be greater than maximum purity');
                }
                if (minPurity < 0 || maxPurity > 100) {
                    $('input[name="min_purity"], input[name="max_purity"]').addClass('is-invalid');
                    isValid = false;
                    errorMessages.push('Purity percentage must be between 0 and 100');
                }
            }
        }
        
        if (!isValid) {
            e.preventDefault();
            var errorMsg = errorMessages.join('<br>');
            toastr.error(errorMsg, 'Validation Error', { timeOut: 5000 });
        }
    });
    
    // Remove is-invalid class on input
    $('input, select, textarea').on('input change', function() {
        $(this).removeClass('is-invalid');
    });
    
    // Auto-uppercase first letter for category name
    $('input[name="category_name"]').on('blur', function() {
        var value = $(this).val();
        if (value) {
            $(this).val(value.charAt(0).toUpperCase() + value.slice(1).toLowerCase());
            updatePreview();
        }
    });
    
    // Initialize tooltips
    $('[data-toggle="tooltip"]').tooltip();
    
    // Trigger initial preview update
    updatePreview();
});
</script>

</body>
</html>