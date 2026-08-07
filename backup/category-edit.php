<?php
session_start();
date_default_timezone_set('Asia/Kolkata');

require_once 'includes/config.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not available. Check includes/config.php');
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn->set_charset('utf8mb4');

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('tableExists')) {
    function tableExists(mysqli $conn, string $tableName): bool
    {
        $safe = $conn->real_escape_string($tableName);
        $res = $conn->query("SHOW TABLES LIKE '{$safe}'");
        return $res && $res->num_rows > 0;
    }
}

if (!function_exists('hasColumn')) {
    function hasColumn(mysqli $conn, string $table, string $column): bool
    {
        $table = $conn->real_escape_string($table);
        $column = $conn->real_escape_string($column);
        $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
        return $res && $res->num_rows > 0;
    }
}

if (!function_exists('getClientIp')) {
    function getClientIp(): string
    {
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

$pageTitle = 'Edit Category';
$page_title = 'Edit Category';
$currentPage = 'categories';

/* -------------------------------------------------------
   AUTH CHECK
------------------------------------------------------- */
if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    header('Location: ../login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$businessId = (int)($_SESSION['business_id'] ?? 0);

if ($businessId <= 0) {
    die('Business session not found. Please login again.');
}

/* -------------------------------------------------------
   ROLE CHECK
------------------------------------------------------- */
$stmt = $conn->prepare("
    SELECT r.role_name
    FROM users u
    INNER JOIN roles r ON r.id = u.role_id
    WHERE u.id = ?
    LIMIT 1
");

if (!$stmt) {
    die('Role check failed.');
}

$stmt->bind_param('i', $userId);
$stmt->execute();
$res = $stmt->get_result();
$userRow = $res ? $res->fetch_assoc() : null;
$stmt->close();

$roleName = strtolower(trim((string)($userRow['role_name'] ?? '')));

if (!in_array($roleName, ['admin', 'manager'], true)) {
    die('Access denied.');
}

/* -------------------------------------------------------
   REQUIRED TABLE
------------------------------------------------------- */
if (!tableExists($conn, 'product_categories')) {
    die('Product categories table not found. Please import the SQL first.');
}

/* -------------------------------------------------------
   COLUMN CHECKS
------------------------------------------------------- */
$catHasBusinessId    = hasColumn($conn, 'product_categories', 'business_id');
$catHasCategoryName  = hasColumn($conn, 'product_categories', 'category_name');
$catHasHsnCode       = hasColumn($conn, 'product_categories', 'hsn_code');
$catHasGstPercent    = hasColumn($conn, 'product_categories', 'gst_percent');
$catHasDescription   = hasColumn($conn, 'product_categories', 'description');
$catHasIsActive      = hasColumn($conn, 'product_categories', 'is_active');
$catHasCreatedAt     = hasColumn($conn, 'product_categories', 'created_at');
$catHasUpdatedAt     = hasColumn($conn, 'product_categories', 'updated_at');

if (!$catHasCategoryName) {
    die('category_name column not found in product_categories table.');
}

/* -------------------------------------------------------
   CATEGORY ID
------------------------------------------------------- */
$categoryId = (int)($_GET['id'] ?? $_POST['category_id'] ?? 0);

if ($categoryId <= 0) {
    die('Invalid category ID.');
}

/* -------------------------------------------------------
   FETCH CATEGORY
------------------------------------------------------- */
$sql = "SELECT * FROM product_categories WHERE id = ?";

if ($catHasBusinessId) {
    $sql .= " AND business_id = ?";
}

$sql .= " LIMIT 1";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die('Failed to prepare category query.');
}

if ($catHasBusinessId) {
    $stmt->bind_param('ii', $categoryId, $businessId);
} else {
    $stmt->bind_param('i', $categoryId);
}

$stmt->execute();
$res = $stmt->get_result();
$category = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$category) {
    die('Category not found.');
}

/* -------------------------------------------------------
   FORM PROCESS
------------------------------------------------------- */
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_category') {
    $categoryName = trim((string)($_POST['category_name'] ?? ''));
    $hsnCode = trim((string)($_POST['hsn_code'] ?? ''));
    $gstPercent = (float)($_POST['gst_percent'] ?? 0);
    $description = trim((string)($_POST['description'] ?? ''));
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    $errors = [];

    if ($categoryName === '') {
        $errors[] = 'Category name is required.';
    }

    if ($catHasGstPercent && $gstPercent < 0) {
        $errors[] = 'GST percent cannot be negative.';
    }

    /* Duplicate category check */
    if (empty($errors)) {
        $checkSql = "SELECT id FROM product_categories WHERE category_name = ? AND id != ?";

        if ($catHasBusinessId) {
            $checkSql .= " AND business_id = ?";
        }

        $checkSql .= " LIMIT 1";

        $checkStmt = $conn->prepare($checkSql);

        if ($checkStmt) {
            if ($catHasBusinessId) {
                $checkStmt->bind_param('sii', $categoryName, $categoryId, $businessId);
            } else {
                $checkStmt->bind_param('si', $categoryName, $categoryId);
            }

            $checkStmt->execute();
            $checkRes = $checkStmt->get_result();

            if ($checkRes && $checkRes->num_rows > 0) {
                $errors[] = 'Another category with this name already exists.';
            }

            $checkStmt->close();
        } else {
            $errors[] = 'Duplicate check failed: ' . $conn->error;
        }
    }

    if (empty($errors)) {
        $setParts = [];
        $params = [];
        $types = '';

        if ($catHasCategoryName) {
            $setParts[] = "category_name = ?";
            $params[] = $categoryName;
            $types .= 's';
        }

        if ($catHasHsnCode) {
            $setParts[] = "hsn_code = ?";
            $params[] = $hsnCode;
            $types .= 's';
        }

        if ($catHasGstPercent) {
            $setParts[] = "gst_percent = ?";
            $params[] = $gstPercent;
            $types .= 'd';
        }

        if ($catHasDescription) {
            $setParts[] = "description = ?";
            $params[] = $description;
            $types .= 's';
        }

        if ($catHasIsActive) {
            $setParts[] = "is_active = ?";
            $params[] = $isActive;
            $types .= 'i';
        }

        if ($catHasUpdatedAt) {
            $setParts[] = "updated_at = NOW()";
        }

        if (empty($setParts)) {
            $error = 'No editable category columns found.';
        } else {
            $updateSql = "UPDATE product_categories SET " . implode(', ', $setParts) . " WHERE id = ?";
            $params[] = $categoryId;
            $types .= 'i';

            if ($catHasBusinessId) {
                $updateSql .= " AND business_id = ?";
                $params[] = $businessId;
                $types .= 'i';
            }

            $updateSql .= " LIMIT 1";

            $stmt = $conn->prepare($updateSql);

            if (!$stmt) {
                $error = 'Failed to prepare update query: ' . $conn->error;
            } else {
                $bindValues = [];
                $bindValues[] = $types;

                for ($i = 0; $i < count($params); $i++) {
                    $bindValues[] = &$params[$i];
                }

                call_user_func_array([$stmt, 'bind_param'], $bindValues);

                if ($stmt->execute()) {
                    addAuditLog(
                        $conn,
                        $businessId,
                        $userId,
                        'Categories',
                        'Update',
                        $categoryId,
                        'Updated product category: ' . $categoryName
                    );

                    header('Location: categories.php?msg=updated');
                    exit;
                } else {
                    $error = 'Failed to update category: ' . $stmt->error;
                }

                $stmt->close();
            }
        }
    } else {
        $error = implode('<br>', array_map('h', $errors));
    }

    $category['category_name'] = $categoryName;
    $category['hsn_code'] = $hsnCode;
    $category['gst_percent'] = $gstPercent;
    $category['description'] = $description;
    $category['is_active'] = $isActive;
}
?>
<!doctype html>
<html lang="en">

<?php include('includes/head.php'); ?>

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

                <?php if ($success !== ''): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo h($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo $error; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                            <div>
                                <h4 class="mb-1">Edit Category</h4>
                                <p class="text-muted mb-0">Update product category details</p>
                            </div>
                            <div>
                                <a href="categories.php" class="btn btn-secondary">
                                    <i class="fas fa-arrow-left"></i> Back
                                </a>
                            </div>
                        </div>

                        <form method="post" action="">
                            <input type="hidden" name="action" value="update_category">
                            <input type="hidden" name="category_id" value="<?php echo (int)$categoryId; ?>">

                            <div class="row">
                                <?php if ($catHasCategoryName): ?>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Category Name <span class="text-danger">*</span></label>
                                        <input type="text"
                                               name="category_name"
                                               class="form-control"
                                               value="<?php echo h($category['category_name'] ?? ''); ?>"
                                               required>
                                    </div>
                                <?php endif; ?>

                                <?php if ($catHasHsnCode): ?>
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">HSN Code</label>
                                        <input type="text"
                                               name="hsn_code"
                                               class="form-control"
                                               value="<?php echo h($category['hsn_code'] ?? ''); ?>">
                                    </div>
                                <?php endif; ?>

                                <?php if ($catHasGstPercent): ?>
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">GST %</label>
                                        <input type="number"
                                               step="0.01"
                                               min="0"
                                               name="gst_percent"
                                               class="form-control"
                                               value="<?php echo h($category['gst_percent'] ?? '0.00'); ?>">
                                    </div>
                                <?php endif; ?>

                                <?php if ($catHasDescription): ?>
                                    <div class="col-md-12 mb-3">
                                        <label class="form-label">Description</label>
                                        <textarea name="description"
                                                  class="form-control"
                                                  rows="4"><?php echo h($category['description'] ?? ''); ?></textarea>
                                    </div>
                                <?php endif; ?>

                                <?php if ($catHasIsActive): ?>
                                    <div class="col-md-12 mb-3">
                                        <label class="form-label d-block">Status</label>
                                        <div class="form-check form-switch mt-2">
                                            <input class="form-check-input"
                                                   type="checkbox"
                                                   name="is_active"
                                                   id="is_active"
                                                   <?php echo (int)($category['is_active'] ?? 1) === 1 ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="is_active">Active</label>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="text-end">
                                <a href="categories.php" class="btn btn-secondary">Cancel</a>
                                <button type="submit" class="btn btn-primary">Update Category</button>
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

</body>
</html>