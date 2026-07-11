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

$pageTitle = 'Categories';
$page_title = 'Categories';
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
   REQUIRED TABLES
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

$prodTableExists     = tableExists($conn, 'products');
$prodHasBusinessId   = $prodTableExists && hasColumn($conn, 'products', 'business_id');
$prodHasCategoryId   = $prodTableExists && hasColumn($conn, 'products', 'category_id');

/* -------------------------------------------------------
   FLASH MESSAGES
------------------------------------------------------- */
$success = '';
$error = '';

$msg = trim((string)($_GET['msg'] ?? ''));

if ($msg === 'created') {
    $success = 'Category created successfully.';
} elseif ($msg === 'updated') {
    $success = 'Category updated successfully.';
} elseif ($msg === 'deleted') {
    $success = 'Category deleted successfully.';
} elseif ($msg === 'status_changed') {
    $success = 'Category status changed successfully.';
}

/* -------------------------------------------------------
   DELETE CATEGORY
------------------------------------------------------- */
if (isset($_GET['delete']) && (int)$_GET['delete'] > 0) {
    $deleteId = (int)$_GET['delete'];
    $productCount = 0;

    if ($prodHasCategoryId) {
        $sql = "SELECT COUNT(*) AS cnt FROM products WHERE category_id = ?";

        if ($prodHasBusinessId) {
            $sql .= " AND business_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ii', $deleteId, $businessId);
        } else {
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('i', $deleteId);
        }

        if ($stmt) {
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $productCount = (int)($row['cnt'] ?? 0);
            $stmt->close();
        }
    }

    if ($productCount > 0) {
        $error = 'Cannot delete this category because it has ' . $productCount . ' product(s).';
    } else {
        if ($catHasBusinessId) {
            $stmt = $conn->prepare("DELETE FROM product_categories WHERE id = ? AND business_id = ? LIMIT 1");
            $stmt->bind_param('ii', $deleteId, $businessId);
        } else {
            $stmt = $conn->prepare("DELETE FROM product_categories WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $deleteId);
        }

        if ($stmt && $stmt->execute()) {
            addAuditLog(
                $conn,
                $businessId,
                $userId,
                'Categories',
                'Delete',
                $deleteId,
                'Deleted product category'
            );

            header('Location: categories.php?msg=deleted');
            exit;
        } else {
            $error = 'Failed to delete category.';
        }

        if ($stmt) {
            $stmt->close();
        }
    }
}

/* -------------------------------------------------------
   TOGGLE CATEGORY STATUS
------------------------------------------------------- */
if ($catHasIsActive && isset($_GET['toggle']) && (int)$_GET['toggle'] > 0) {
    $toggleId = (int)$_GET['toggle'];

    if ($catHasBusinessId) {
        $stmt = $conn->prepare("SELECT is_active FROM product_categories WHERE id = ? AND business_id = ? LIMIT 1");
        $stmt->bind_param('ii', $toggleId, $businessId);
    } else {
        $stmt = $conn->prepare("SELECT is_active FROM product_categories WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $toggleId);
    }

    if ($stmt) {
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if ($row) {
            $newStatus = ((int)$row['is_active'] === 1) ? 0 : 1;

            $sql = "UPDATE product_categories SET is_active = ?";

            if ($catHasUpdatedAt) {
                $sql .= ", updated_at = NOW()";
            }

            $sql .= " WHERE id = ?";

            if ($catHasBusinessId) {
                $sql .= " AND business_id = ?";
            }

            $sql .= " LIMIT 1";

            $stmt = $conn->prepare($sql);

            if ($stmt) {
                if ($catHasBusinessId) {
                    $stmt->bind_param('iii', $newStatus, $toggleId, $businessId);
                } else {
                    $stmt->bind_param('ii', $newStatus, $toggleId);
                }

                if ($stmt->execute()) {
                    addAuditLog(
                        $conn,
                        $businessId,
                        $userId,
                        'Categories',
                        'Status Change',
                        $toggleId,
                        'Changed category status'
                    );
                }

                $stmt->close();
            }
        }
    }

    header('Location: categories.php?msg=status_changed');
    exit;
}

/* -------------------------------------------------------
   COUNTS
------------------------------------------------------- */
$totalCategories = 0;
$activeCategories = 0;
$inactiveCategories = 0;

$sql = "SELECT COUNT(*) AS cnt FROM product_categories WHERE 1=1";

if ($catHasBusinessId) {
    $sql .= " AND business_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $businessId);
} else {
    $stmt = $conn->prepare($sql);
}

if ($stmt) {
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $totalCategories = (int)($row['cnt'] ?? 0);
    $stmt->close();
}

if ($catHasIsActive) {
    $sql = "SELECT COUNT(*) AS cnt FROM product_categories WHERE is_active = 1";

    if ($catHasBusinessId) {
        $sql .= " AND business_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $businessId);
    } else {
        $stmt = $conn->prepare($sql);
    }

    if ($stmt) {
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $activeCategories = (int)($row['cnt'] ?? 0);
        $stmt->close();
    }

    $sql = "SELECT COUNT(*) AS cnt FROM product_categories WHERE is_active = 0";

    if ($catHasBusinessId) {
        $sql .= " AND business_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $businessId);
    } else {
        $stmt = $conn->prepare($sql);
    }

    if ($stmt) {
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $inactiveCategories = (int)($row['cnt'] ?? 0);
        $stmt->close();
    }
} else {
    $activeCategories = $totalCategories;
}

/* -------------------------------------------------------
   FILTERS
------------------------------------------------------- */
$search = trim((string)($_GET['search'] ?? ''));
$status = trim((string)($_GET['status'] ?? 'all'));

$where = " WHERE 1=1 ";
$params = [];
$types = '';

if ($catHasBusinessId) {
    $where .= " AND c.business_id = ? ";
    $params[] = $businessId;
    $types .= 'i';
}

if ($search !== '') {
    $where .= " AND (";
    $parts = [];

    if ($catHasCategoryName) {
        $parts[] = "c.category_name LIKE ?";
        $params[] = '%' . $search . '%';
        $types .= 's';
    }

    if ($catHasHsnCode) {
        $parts[] = "c.hsn_code LIKE ?";
        $params[] = '%' . $search . '%';
        $types .= 's';
    }

    if ($catHasDescription) {
        $parts[] = "c.description LIKE ?";
        $params[] = '%' . $search . '%';
        $types .= 's';
    }

    if (empty($parts)) {
        $parts[] = "c.id = 0";
    }

    $where .= implode(' OR ', $parts) . ")";
}

if ($catHasIsActive) {
    if ($status === 'active') {
        $where .= " AND c.is_active = 1 ";
    } elseif ($status === 'inactive') {
        $where .= " AND c.is_active = 0 ";
    }
}

/* -------------------------------------------------------
   CATEGORY LIST
------------------------------------------------------- */
$sql = "SELECT 
            c.id";

if ($catHasCategoryName) {
    $sql .= ", c.category_name";
}
if ($catHasHsnCode) {
    $sql .= ", c.hsn_code";
}
if ($catHasGstPercent) {
    $sql .= ", c.gst_percent";
}
if ($catHasDescription) {
    $sql .= ", c.description";
}
if ($catHasIsActive) {
    $sql .= ", c.is_active";
}
if ($catHasCreatedAt) {
    $sql .= ", c.created_at";
}

if ($prodHasCategoryId) {
    $sql .= ", COUNT(p.id) AS product_count";
} else {
    $sql .= ", 0 AS product_count";
}

$sql .= " FROM product_categories c";

if ($prodHasCategoryId) {
    $sql .= " LEFT JOIN products p ON p.category_id = c.id";

    if ($prodHasBusinessId && $catHasBusinessId) {
        $sql .= " AND p.business_id = c.business_id";
    }
}

$sql .= " $where
          GROUP BY c.id
          ORDER BY c.id DESC";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die('Failed to prepare categories query: ' . $conn->error);
}

if (!empty($params)) {
    $bindValues = [];
    $bindValues[] = $types;

    for ($i = 0; $i < count($params); $i++) {
        $bindValues[] = &$params[$i];
    }

    call_user_func_array([$stmt, 'bind_param'], $bindValues);
}

$stmt->execute();
$res = $stmt->get_result();

$categories = [];

if ($res) {
    while ($row = $res->fetch_assoc()) {
        $categories[] = $row;
    }
}

$stmt->close();
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
                        <?php echo h($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-md-4 col-xl-4">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-primary mt-2"><?php echo (int)$totalCategories; ?></h3>
                                <p class="text-muted mb-0">Total Categories</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4 col-xl-4">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-success mt-2"><?php echo (int)$activeCategories; ?></h3>
                                <p class="text-muted mb-0">Active Categories</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4 col-xl-4">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-danger mt-2"><?php echo (int)$inactiveCategories; ?></h3>
                                <p class="text-muted mb-0">Inactive Categories</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <form method="get" class="row g-2 align-items-end">
                            <div class="col-md-5">
                                <label class="form-label">Search</label>
                                <input type="text" name="search" class="form-control" placeholder="Category name, HSN, description..." value="<?php echo h($search); ?>">
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select">
                                    <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All Status</option>
                                    <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <button type="submit" class="btn btn-primary w-100">Search</button>
                            </div>

                            <div class="col-md-12">
                                <a href="categories.php" class="btn btn-secondary">Reset</a>
                                <a href="category-add.php" class="btn btn-primary">Add Category</a>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Category</th>
                                        <?php if ($catHasHsnCode): ?><th>HSN Code</th><?php endif; ?>
                                        <?php if ($catHasGstPercent): ?><th>GST %</th><?php endif; ?>
                                        <th>Products</th>
                                        <th>Status</th>
                                        <th>Created</th>
                                        <th style="min-width: 210px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($categories)): ?>
                                        <?php foreach ($categories as $index => $category): ?>
                                            <tr>
                                                <td><?php echo $index + 1; ?></td>

                                                <td>
                                                    <strong><?php echo h($category['category_name'] ?? ''); ?></strong>

                                                    <?php if ($catHasDescription && !empty($category['description'])): ?>
                                                        <br>
                                                        <small class="text-muted">
                                                            <?php echo h($category['description']); ?>
                                                        </small>
                                                    <?php endif; ?>
                                                </td>

                                                <?php if ($catHasHsnCode): ?>
                                                    <td><?php echo h($category['hsn_code'] ?? ''); ?></td>
                                                <?php endif; ?>

                                                <?php if ($catHasGstPercent): ?>
                                                    <td>
                                                        <?php echo number_format((float)($category['gst_percent'] ?? 0), 2); ?>%
                                                    </td>
                                                <?php endif; ?>

                                                <td>
                                                    <?php echo (int)($category['product_count'] ?? 0); ?>
                                                </td>

                                                <td>
                                                    <?php if ($catHasIsActive): ?>
                                                        <?php if ((int)($category['is_active'] ?? 0) === 1): ?>
                                                            <span class="badge bg-success">Active</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-danger">Inactive</span>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="badge bg-success">Active</span>
                                                    <?php endif; ?>
                                                </td>

                                                <td>
                                                    <?php
                                                    if ($catHasCreatedAt && !empty($category['created_at'])) {
                                                        echo date('d-m-Y h:i A', strtotime($category['created_at']));
                                                    } else {
                                                        echo '-';
                                                    }
                                                    ?>
                                                </td>

                                                <td>
                                                    <a href="category-edit.php?id=<?php echo (int)$category['id']; ?>" class="btn btn-sm btn-primary mb-1">
                                                        Edit
                                                    </a>

                                                    <?php if ($catHasIsActive): ?>
                                                        <a href="categories.php?toggle=<?php echo (int)$category['id']; ?>"
                                                           class="btn btn-sm btn-<?php echo (int)($category['is_active'] ?? 0) === 1 ? 'warning' : 'success'; ?> mb-1"
                                                           onclick="return confirm('Are you sure?');">
                                                            <?php echo (int)($category['is_active'] ?? 0) === 1 ? 'Deactivate' : 'Activate'; ?>
                                                        </a>
                                                    <?php endif; ?>

                                                    <?php if ((int)($category['product_count'] ?? 0) === 0): ?>
                                                        <a href="categories.php?delete=<?php echo (int)$category['id']; ?>"
                                                           class="btn btn-sm btn-danger mb-1"
                                                           onclick="return confirm('Are you sure you want to delete this category?');">
                                                            Delete
                                                        </a>
                                                    <?php else: ?>
                                                        <button class="btn btn-sm btn-secondary mb-1" disabled
                                                                title="Cannot delete category with products">
                                                            Delete
                                                        </button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="<?php echo 6 + ($catHasHsnCode ? 1 : 0) + ($catHasGstPercent ? 1 : 0); ?>" class="text-center text-muted">
                                                No categories found.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
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

<?php include('includes/rightbar.php'); ?>
<?php include('includes/scripts.php'); ?>

</body>
</html>