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

$pageTitle = 'Add Category';
$page_title = 'Add Category';
$currentPage = 'category-add';

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

if (!function_exists('hasColumn')) {
    function hasColumn(mysqli $conn, string $table, string $column): bool {
        $table = $conn->real_escape_string($table);
        $column = $conn->real_escape_string($column);
        $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
        return $res && $res->num_rows > 0;
    }
}

if (!function_exists('getClientIp')) {
    function getClientIp(): string {
        return $_SERVER['HTTP_CLIENT_IP']
            ?? $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['REMOTE_ADDR']
            ?? '';
    }
}

if (!function_exists('addAuditLog')) {
    function addAuditLog(
        mysqli $conn,
        int $businessId,
        int $userId,
        string $moduleName,
        string $actionType,
        int $referenceId,
        string $description
    ): void {
        if (!tableExists($conn, 'audit_logs')) {
            return;
        }

        $ipAddress = getClientIp();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $stmt = $conn->prepare("
            INSERT INTO audit_logs
            (business_id, user_id, module_name, action_type, reference_id, description, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        if ($stmt) {
            $stmt->bind_param(
                'iississs',
                $businessId,
                $userId,
                $moduleName,
                $actionType,
                $referenceId,
                $description,
                $ipAddress,
                $userAgent
            );
            $stmt->execute();
            $stmt->close();
        }
    }
}

if (!tableExists($conn, 'product_categories')) {
    die('Product categories table not found. Please import the database first.');
}

$catHasBusinessId    = hasColumn($conn, 'product_categories', 'business_id');
$catHasCategoryName  = hasColumn($conn, 'product_categories', 'category_name');
$catHasHsnCode       = hasColumn($conn, 'product_categories', 'hsn_code');
$catHasGstPercent    = hasColumn($conn, 'product_categories', 'gst_percent');
$catHasDescription   = hasColumn($conn, 'product_categories', 'description');
$catHasIsActive      = hasColumn($conn, 'product_categories', 'is_active');
$catHasCreatedAt     = hasColumn($conn, 'product_categories', 'created_at');
$catHasUpdatedAt     = hasColumn($conn, 'product_categories', 'updated_at');

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_category') {
    $categoryName = trim($_POST['category_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $status = trim($_POST['status'] ?? 'active');

    $isActive = ($status === 'active') ? 1 : 0;

    $errors = [];

    if ($categoryName === '') {
        $errors[] = "Category name is required";
    }

    if (!$catHasCategoryName) {
        $errors[] = "Column category_name not found in product_categories table.";
    }

    if (empty($errors)) {
        $checkQuery = "SELECT id FROM product_categories WHERE category_name = ?";

        if ($catHasBusinessId) {
            $checkQuery .= " AND business_id = ?";
        }

        $checkQuery .= " LIMIT 1";

        $checkStmt = $conn->prepare($checkQuery);

        if (!$checkStmt) {
            $errors[] = "Duplicate check prepare error: " . $conn->error;
        } else {
            if ($catHasBusinessId) {
                $checkStmt->bind_param('si', $categoryName, $businessId);
            } else {
                $checkStmt->bind_param('s', $categoryName);
            }

            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();

            if ($checkResult && $checkResult->num_rows > 0) {
                $errors[] = "Category with this name already exists!";
            }

            $checkStmt->close();
        }
    }

    if (empty($errors)) {
        $columns = [];
        $placeholders = [];
        $params = [];
        $types = '';

        if ($catHasBusinessId) {
            $columns[] = 'business_id';
            $placeholders[] = '?';
            $params[] = $businessId;
            $types .= 'i';
        }

        if ($catHasCategoryName) {
            $columns[] = 'category_name';
            $placeholders[] = '?';
            $params[] = $categoryName;
            $types .= 's';
        }

        if ($catHasHsnCode) {
            $columns[] = 'hsn_code';
            $placeholders[] = '?';
            $params[] = '';
            $types .= 's';
        }

        if ($catHasGstPercent) {
            $columns[] = 'gst_percent';
            $placeholders[] = '?';
            $params[] = 3.00;
            $types .= 'd';
        }

        if ($catHasDescription) {
            $columns[] = 'description';
            $placeholders[] = '?';
            $params[] = $description;
            $types .= 's';
        }

        if ($catHasIsActive) {
            $columns[] = 'is_active';
            $placeholders[] = '?';
            $params[] = $isActive;
            $types .= 'i';
        }

        $insertQuery = "INSERT INTO product_categories (" . implode(', ', $columns) . ")
                        VALUES (" . implode(', ', $placeholders) . ")";

        $stmt = $conn->prepare($insertQuery);

        if (!$stmt) {
            $message = "Insert prepare error: " . $conn->error;
            $messageType = 'danger';
        } else {
            $bindValues = [];
            $bindValues[] = $types;

            for ($i = 0; $i < count($params); $i++) {
                $bindValues[] = &$params[$i];
            }

            call_user_func_array([$stmt, 'bind_param'], $bindValues);

            if ($stmt->execute()) {
                $categoryId = (int)$stmt->insert_id;

                addAuditLog(
                    $conn,
                    $businessId,
                    $userId,
                    'Categories',
                    'Create',
                    $categoryId,
                    'Created product category: ' . $categoryName
                );

                $message = "Category added successfully!";
                $messageType = 'success';

                $_POST = [];

                echo "<script>
                    setTimeout(function(){
                        window.location.href = 'categories.php';
                    }, 1500);
                </script>";
            } else {
                $message = "Error: " . $stmt->error;
                $messageType = 'danger';
            }

            $stmt->close();
        }
    } else {
        $message = implode('<br>', array_map('h', $errors));
        $messageType = 'danger';
    }
}

$postCategoryName = $_POST['category_name'] ?? '';
$postDescription = $_POST['description'] ?? '';
$postStatus = $_POST['status'] ?? 'active';
?>
<!doctype html>
<html lang="en">
<?php include('includes/head.php'); ?>

<style>
    .form-section {
        background: #fff;
        border-radius: 12px;
        padding: 25px;
        border: 1px solid #eef0f4;
    }
    .form-section h5 {
        margin-bottom: 20px;
        padding-bottom: 10px;
        border-bottom: 2px solid #eef0f4;
        font-size: 16px;
        font-weight: 600;
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
                                <h4 class="mb-1">Add Product Category</h4>
                                <div class="text-muted">Create a new product category for your inventory</div>
                            </div>
                            <div>
                                <a href="categories.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-arrow-left"></i> Back to Categories
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($message): ?>
                <div class="alert alert-<?php echo h($messageType); ?> alert-dismissible fade show" role="alert">
                    <?php echo $message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php endif; ?>

                <div class="row justify-content-center">
                    <div class="col-lg-8">
                        <div class="form-section">
                            <h5><i class="fas fa-tag me-2"></i> Category Information</h5>

                            <form method="POST" action="" id="categoryForm">
                                <input type="hidden" name="action" value="add_category">

                                <div class="mb-3">
                                    <label class="form-label">Category Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="category_name" value="<?php echo h($postCategoryName); ?>" required autofocus>
                                    <div class="form-text">Example: Ice Apple, Beverages, Snacks, etc.</div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Description</label>
                                    <textarea class="form-control" name="description" rows="3"><?php echo h($postDescription); ?></textarea>
                                    <div class="form-text">Optional description about this category</div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Status</label>
                                    <select class="form-select" name="status">
                                        <option value="active" <?php echo $postStatus == 'active' ? 'selected' : ''; ?>>Active</option>
                                        <option value="inactive" <?php echo $postStatus == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    </select>
                                </div>

                                <div class="text-end mt-4">
                                    <button type="reset" class="btn btn-outline-secondary me-2">Reset</button>
                                    <button type="submit" class="btn btn-primary">Add Category</button>
                                </div>
                            </form>
                        </div>
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
document.addEventListener('DOMContentLoaded', function() {
    document.body.setAttribute('data-sidebar', 'dark');

    var sidebarScroll = document.querySelector('.vertical-menu [data-simplebar]');
    if (sidebarScroll) {
        sidebarScroll.style.height = '100vh';
        sidebarScroll.style.overflowY = 'auto';
        sidebarScroll.style.overflowX = 'hidden';
    }

    const form = document.getElementById('categoryForm');

    if (form) {
        form.addEventListener('submit', function(e) {
            const categoryName = document.querySelector('input[name="category_name"]').value.trim();

            if (!categoryName) {
                e.preventDefault();
                alert('Please enter category name');
                return false;
            }

            return true;
        });
    }
});
</script>

</body>
</html>