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

$pageTitle = 'Pawn Categories';
$page_title = 'Pawn Categories';
$currentPage = 'pawn-categories';

$businessId = (int)($_SESSION['business_id'] ?? 1);
$userId = (int)($_SESSION['user_id'] ?? 0);

if (!function_exists('h')) {
    function h($value): string {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('tableExists')) {
    function tableExists(mysqli $conn, string $tableName): bool {
        $safe = $conn->real_escape_string($tableName);
        $res = $conn->query("SHOW TABLES LIKE '{$safe}'");
        return $res && $res->num_rows > 0;
    }
}

// Create pawn_categories table if it doesn't exist
if (!tableExists($conn, 'pawn_categories')) {
    $createTable = "CREATE TABLE IF NOT EXISTS `pawn_categories` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `business_id` int(11) NOT NULL,
        `category_name` varchar(100) NOT NULL,
        `category_code` varchar(20) NOT NULL,
        `description` text DEFAULT NULL,
        `default_purity` varchar(50) DEFAULT NULL,
        `default_interest_rate` decimal(5,2) DEFAULT 12.00,
        `default_pawn_value_per_gram` decimal(12,2) DEFAULT 0.00,
        `min_loan_amount` decimal(12,2) DEFAULT 0.00,
        `max_loan_amount` decimal(12,2) DEFAULT 0.00,
        `status` enum('active','inactive') DEFAULT 'active',
        `created_by` int(11) DEFAULT NULL,
        `created_at` timestamp NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `category_code` (`category_code`),
        KEY `business_id` (`business_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $conn->query($createTable);
}

// Handle category addition
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'add_category') {
        $categoryName = trim($_POST['category_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $defaultPurity = trim($_POST['default_purity'] ?? '');
        $defaultInterestRate = (float)($_POST['default_interest_rate'] ?? 12.00);
        $defaultPawnValue = (float)($_POST['default_pawn_value_per_gram'] ?? 0);
        $minLoanAmount = (float)($_POST['min_loan_amount'] ?? 0);
        $maxLoanAmount = (float)($_POST['max_loan_amount'] ?? 0);
        
        // Generate category code
        $prefix = 'PCAT';
        $query = $conn->query("SELECT category_code FROM pawn_categories WHERE category_code LIKE '{$prefix}%' ORDER BY id DESC LIMIT 1");
        $lastNumber = 0;
        if ($query && $row = $query->fetch_assoc()) {
            $lastNumber = (int)substr($row['category_code'], -4);
        }
        $categoryCode = $prefix . str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
        
        if (empty($categoryName)) {
            $message = "Category name is required";
            $messageType = 'danger';
        } else {
            $insertQuery = "INSERT INTO pawn_categories (
                business_id, category_name, category_code, description, default_purity,
                default_interest_rate, default_pawn_value_per_gram, min_loan_amount,
                max_loan_amount, status, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?)";
            
            $stmt = $conn->prepare($insertQuery);
            $stmt->bind_param('issssddddi', 
                $businessId, $categoryName, $categoryCode, $description, $defaultPurity,
                $defaultInterestRate, $defaultPawnValue, $minLoanAmount, $maxLoanAmount, $userId
            );
            
            if ($stmt->execute()) {
                $message = "Category added successfully! Code: " . $categoryCode;
                $messageType = 'success';
            } else {
                $message = "Error: " . $stmt->error;
                $messageType = 'danger';
            }
            $stmt->close();
        }
    }
    
    // Handle category edit
    if (isset($_POST['action']) && $_POST['action'] === 'edit_category') {
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $categoryName = trim($_POST['category_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $defaultPurity = trim($_POST['default_purity'] ?? '');
        $defaultInterestRate = (float)($_POST['default_interest_rate'] ?? 12.00);
        $defaultPawnValue = (float)($_POST['default_pawn_value_per_gram'] ?? 0);
        $minLoanAmount = (float)($_POST['min_loan_amount'] ?? 0);
        $maxLoanAmount = (float)($_POST['max_loan_amount'] ?? 0);
        $status = trim($_POST['status'] ?? 'active');
        
        if ($categoryId > 0 && !empty($categoryName)) {
            $updateQuery = "UPDATE pawn_categories SET 
                category_name = ?, description = ?, default_purity = ?,
                default_interest_rate = ?, default_pawn_value_per_gram = ?,
                min_loan_amount = ?, max_loan_amount = ?, status = ?
                WHERE id = ? AND business_id = ?";
            
            $stmt = $conn->prepare($updateQuery);
            $stmt->bind_param('sssddddsii', 
                $categoryName, $description, $defaultPurity,
                $defaultInterestRate, $defaultPawnValue,
                $minLoanAmount, $maxLoanAmount, $status, $categoryId, $businessId
            );
            
            if ($stmt->execute()) {
                $message = "Category updated successfully!";
                $messageType = 'success';
            } else {
                $message = "Error: " . $stmt->error;
                $messageType = 'danger';
            }
            $stmt->close();
        }
    }
    
    // Handle category delete
    if (isset($_POST['action']) && $_POST['action'] === 'delete_category') {
        $categoryId = (int)($_POST['category_id'] ?? 0);
        
        if ($categoryId > 0) {
            $deleteQuery = "DELETE FROM pawn_categories WHERE id = ? AND business_id = ?";
            $stmt = $conn->prepare($deleteQuery);
            $stmt->bind_param('ii', $categoryId, $businessId);
            
            if ($stmt->execute()) {
                $message = "Category deleted successfully!";
                $messageType = 'success';
            } else {
                $message = "Error: " . $stmt->error;
                $messageType = 'danger';
            }
            $stmt->close();
        }
    }
}

// Fetch all pawn categories
$categories = [];
$categoryQuery = "SELECT * FROM pawn_categories WHERE business_id = $businessId ORDER BY category_name";
$categoryRes = $conn->query($categoryQuery);
if ($categoryRes) {
    while ($row = $categoryRes->fetch_assoc()) {
        $categories[] = $row;
    }
}
?>
<!doctype html>
<html lang="en">
<?php include('includes/head.php'); ?>

<style>
    .category-card {
        background: #fff;
        border-radius: 12px;
        margin-bottom: 20px;
        border: 1px solid #eef0f4;
        transition: all 0.3s;
    }
    .category-card:hover {
        box-shadow: 0 5px 20px rgba(0,0,0,0.08);
    }
    .category-header {
        padding: 15px 20px;
        border-bottom: 1px solid #eef0f4;
        background: #f8f9fa;
        border-radius: 12px 12px 0 0;
        cursor: pointer;
    }
    .category-body {
        padding: 20px;
        display: none;
    }
    .category-body.active {
        display: block;
    }
    .badge-active {
        background: #d4edda;
        color: #155724;
    }
    .badge-inactive {
        background: #f8d7da;
        color: #721c24;
    }
    .form-section {
        background: #fff;
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 25px;
        border: 1px solid #eef0f4;
    }
    .category-stats {
        font-size: 12px;
        color: #6c757d;
    }
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
                                <h4 class="mb-1">Pawn Categories</h4>
                                <div class="text-muted">Manage pawn item categories and their default values</div>
                            </div>
                            <div>
                                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                                    <i class="fas fa-plus"></i> Add New Category
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($message): ?>
                <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                    <?php echo $message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php endif; ?>

                <!-- Categories List -->
                <div class="row">
                    <?php if (empty($categories)): ?>
                        <div class="col-12">
                            <div class="text-center py-5">
                                <i class="fas fa-folder-open fa-3x text-muted mb-3"></i>
                                <h5>No Categories Found</h5>
                                <p class="text-muted">Click "Add New Category" to create your first pawn category.</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($categories as $category): ?>
                            <div class="col-md-6 col-xl-4">
                                <div class="category-card">
                                    <div class="category-header" onclick="toggleCategory(<?php echo $category['id']; ?>)">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <h6 class="mb-1"><?php echo h($category['category_name']); ?></h6>
                                                <small class="text-muted">Code: <?php echo h($category['category_code']); ?></small>
                                            </div>
                                            <div>
                                                <span class="badge <?php echo $category['status'] == 'active' ? 'badge-active' : 'badge-inactive'; ?> me-2">
                                                    <?php echo ucfirst($category['status']); ?>
                                                </span>
                                                <i class="fas fa-chevron-down"></i>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="category-body" id="category-body-<?php echo $category['id']; ?>">
                                        <div class="mb-3">
                                            <div class="row g-2">
                                                <div class="col-6">
                                                    <small class="text-muted d-block">Default Purity</small>
                                                    <strong><?php echo h($category['default_purity'] ?? 'N/A'); ?></strong>
                                                </div>
                                                <div class="col-6">
                                                    <small class="text-muted d-block">Interest Rate</small>
                                                    <strong><?php echo number_format($category['default_interest_rate'], 2); ?>%</strong>
                                                </div>
                                                <div class="col-6">
                                                    <small class="text-muted d-block">Pawn Value/g</small>
                                                    <strong>₹<?php echo number_format($category['default_pawn_value_per_gram'], 2); ?></strong>
                                                </div>
                                                <div class="col-12">
                                                    <small class="text-muted d-block">Loan Range</small>
                                                    <strong>₹<?php echo number_format($category['min_loan_amount'], 2); ?> - ₹<?php echo number_format($category['max_loan_amount'], 2); ?></strong>
                                                </div>
                                            </div>
                                        </div>
                                        <?php if (!empty($category['description'])): ?>
                                            <div class="mb-3">
                                                <small class="text-muted">Description:</small>
                                                <p class="mb-0 small"><?php echo nl2br(h($category['description'])); ?></p>
                                            </div>
                                        <?php endif; ?>
                                        <div class="d-flex gap-2">
                                            <button class="btn btn-sm btn-outline-primary" onclick="editCategory(<?php echo htmlspecialchars(json_encode($category)); ?>)">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            <button class="btn btn-sm btn-outline-danger" onclick="deleteCategory(<?php echo $category['id']; ?>, '<?php echo h($category['category_name']); ?>')">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

            </div>
        </div>

        <?php include('includes/footer.php'); ?>
    </div>
</div>

<!-- Add Category Modal -->
<div class="modal fade" id="addCategoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Pawn Category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_category">
                    
                    <div class="mb-3">
                        <label class="form-label">Category Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="category_name" required>
                        <small class="text-muted">e.g., Gold Necklace, Silver Bracelet, Diamond Ring</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Default Purity</label>
                        <select class="form-select" name="default_purity">
                            <option value="">-- Select --</option>
                            <option value="24K (999)">24K (999)</option>
                            <option value="22K (916)">22K (916)</option>
                            <option value="18K (750)">18K (750)</option>
                            <option value="14K (585)">14K (585)</option>
                            <option value="999">Silver 999</option>
                            <option value="925">Silver 925</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Default Interest Rate (%) per annum</label>
                        <input type="number" step="0.01" class="form-control" name="default_interest_rate" value="12.00">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Default Pawn Value per Gram (₹)</label>
                        <input type="number" step="0.01" class="form-control" name="default_pawn_value_per_gram" value="0">
                        <small class="text-muted">Leave 0 to use current market rate</small>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Minimum Loan Amount (₹)</label>
                            <input type="number" step="0.01" class="form-control" name="min_loan_amount" value="0">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Maximum Loan Amount (₹)</label>
                            <input type="number" step="0.01" class="form-control" name="max_loan_amount" value="0">
                            <small class="text-muted">Leave 0 for no limit</small>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Category</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Category Modal -->
<div class="modal fade" id="editCategoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Pawn Category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit_category">
                    <input type="hidden" name="category_id" id="edit_category_id">
                    
                    <div class="mb-3">
                        <label class="form-label">Category Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="category_name" id="edit_category_name" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Default Purity</label>
                        <select class="form-select" name="default_purity" id="edit_default_purity">
                            <option value="">-- Select --</option>
                            <option value="24K (999)">24K (999)</option>
                            <option value="22K (916)">22K (916)</option>
                            <option value="18K (750)">18K (750)</option>
                            <option value="14K (585)">14K (585)</option>
                            <option value="999">Silver 999</option>
                            <option value="925">Silver 925</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Default Interest Rate (%) per annum</label>
                        <input type="number" step="0.01" class="form-control" name="default_interest_rate" id="edit_default_interest_rate">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Default Pawn Value per Gram (₹)</label>
                        <input type="number" step="0.01" class="form-control" name="default_pawn_value_per_gram" id="edit_default_pawn_value">
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Minimum Loan Amount (₹)</label>
                            <input type="number" step="0.01" class="form-control" name="min_loan_amount" id="edit_min_loan_amount">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Maximum Loan Amount (₹)</label>
                            <input type="number" step="0.01" class="form-control" name="max_loan_amount" id="edit_max_loan_amount">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status" id="edit_status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" id="edit_description" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Category</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Category Form -->
<form id="deleteForm" method="POST" style="display: none;">
    <input type="hidden" name="action" value="delete_category">
    <input type="hidden" name="category_id" id="delete_category_id">
</form>

<?php include('includes/rightbar.php'); ?>
<?php include('includes/scripts.php'); ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.body.setAttribute('data-sidebar', 'dark');
    
    var sidebarScroll = document.querySelector('.vertical-menu [data-simplebar]');
    if (sidebarScroll) {
        sidebarScroll.style.height = '100vh';
        sidebarScroll.style.overflowY = 'auto';
        sidebarScroll.style.overflowX = 'hidden';
    }
});

function toggleCategory(id) {
    const body = document.getElementById(`category-body-${id}`);
    if (body) {
        body.classList.toggle('active');
    }
}

function editCategory(category) {
    document.getElementById('edit_category_id').value = category.id;
    document.getElementById('edit_category_name').value = category.category_name;
    document.getElementById('edit_default_purity').value = category.default_purity || '';
    document.getElementById('edit_default_interest_rate').value = category.default_interest_rate;
    document.getElementById('edit_default_pawn_value').value = category.default_pawn_value_per_gram;
    document.getElementById('edit_min_loan_amount').value = category.min_loan_amount;
    document.getElementById('edit_max_loan_amount').value = category.max_loan_amount;
    document.getElementById('edit_status').value = category.status;
    document.getElementById('edit_description').value = category.description || '';
    
    new bootstrap.Modal(document.getElementById('editCategoryModal')).show();
}

function deleteCategory(id, name) {
    if (confirm(`Are you sure you want to delete category "${name}"? This action cannot be undone.`)) {
        document.getElementById('delete_category_id').value = id;
        document.getElementById('deleteForm').submit();
    }
}
</script>

</body>
</html>