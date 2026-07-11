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

$pageTitle = 'Products';

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

$stmt = $conn->prepare("
    SELECT r.role_name
    FROM users u
    INNER JOIN roles r ON r.id = u.role_id
    WHERE u.id = ?
    LIMIT 1
");
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
if (!tableExists($conn, 'products') || !tableExists($conn, 'product_categories')) {
    die('Required tables not found. Please import the SQL first.');
}

/* -------------------------------------------------------
   COLUMN CHECKS
------------------------------------------------------- */
function hasColumn(mysqli $conn, string $table, string $column): bool
{
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    return $res && $res->num_rows > 0;
}

$prodHasBusinessId       = hasColumn($conn, 'products', 'business_id');
$prodHasBarcode          = hasColumn($conn, 'products', 'barcode');
$prodHasDesignName       = hasColumn($conn, 'products', 'design_name');
$prodHasPurity           = hasColumn($conn, 'products', 'purity');
$prodHasUnit             = hasColumn($conn, 'products', 'unit');
$prodHasGrossWeight      = hasColumn($conn, 'products', 'gross_weight');
$prodHasLessWeight       = hasColumn($conn, 'products', 'less_weight');
$prodHasNetWeight        = hasColumn($conn, 'products', 'net_weight');
$prodHasMakingChargeType = hasColumn($conn, 'products', 'making_charge_type');
$prodHasMakingCharge     = hasColumn($conn, 'products', 'making_charge');
$prodHasWastagePercent   = hasColumn($conn, 'products', 'wastage_percent');
$prodHasStoneCharge      = hasColumn($conn, 'products', 'stone_charge');
$prodHasPurchaseRate     = hasColumn($conn, 'products', 'purchase_rate');
$prodHasSaleRate         = hasColumn($conn, 'products', 'sale_rate');
$prodHasMinStockQty      = hasColumn($conn, 'products', 'min_stock_qty');
$prodHasCurrentStockQty  = hasColumn($conn, 'products', 'current_stock_qty');
$prodHasImagePath        = hasColumn($conn, 'products', 'image_path');
$prodHasDescription      = hasColumn($conn, 'products', 'description');
$prodHasIsActive         = hasColumn($conn, 'products', 'is_active');
$prodHasCreatedAt        = hasColumn($conn, 'products', 'created_at');

$catHasBusinessId        = hasColumn($conn, 'product_categories', 'business_id');

/* -------------------------------------------------------
   FLASH MESSAGES
------------------------------------------------------- */
$success = '';
$error = '';

$msg = trim((string)($_GET['msg'] ?? ''));
if ($msg === 'created') {
    $success = 'Product created successfully.';
} elseif ($msg === 'updated') {
    $success = 'Product updated successfully.';
} elseif ($msg === 'deleted') {
    $success = 'Product deleted successfully.';
} elseif ($msg === 'status_changed') {
    $success = 'Product status changed successfully.';
}

/* -------------------------------------------------------
   DELETE PRODUCT
------------------------------------------------------- */
if (isset($_GET['delete']) && (int)$_GET['delete'] > 0) {
    $deleteId = (int)$_GET['delete'];

    if ($prodHasBusinessId) {
        $stmt = $conn->prepare("DELETE FROM products WHERE id = ? AND business_id = ? LIMIT 1");
        $stmt->bind_param('ii', $deleteId, $businessId);
    } else {
        $stmt = $conn->prepare("DELETE FROM products WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $deleteId);
    }

    if ($stmt && $stmt->execute()) {
        if (function_exists('addAuditLog')) {
            addAuditLog(
                $conn,
                $businessId,
                $userId,
                'Products',
                'Delete',
                $deleteId,
                'Deleted product'
            );
        }
    }
    if ($stmt) {
        $stmt->close();
    }

    header('Location: products.php?msg=deleted');
    exit;
}

/* -------------------------------------------------------
   TOGGLE PRODUCT STATUS
------------------------------------------------------- */
if ($prodHasIsActive && isset($_GET['toggle']) && (int)$_GET['toggle'] > 0) {
    $toggleId = (int)$_GET['toggle'];

    if ($prodHasBusinessId) {
        $stmt = $conn->prepare("SELECT is_active FROM products WHERE id = ? AND business_id = ? LIMIT 1");
        $stmt->bind_param('ii', $toggleId, $businessId);
    } else {
        $stmt = $conn->prepare("SELECT is_active FROM products WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $toggleId);
    }

    if ($stmt) {
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if ($row) {
            $newStatus = ((int)$row['is_active'] === 1) ? 0 : 1;

            if ($prodHasBusinessId) {
                $stmt = $conn->prepare("UPDATE products SET is_active = ?, updated_at = NOW() WHERE id = ? AND business_id = ? LIMIT 1");
                $stmt->bind_param('iii', $newStatus, $toggleId, $businessId);
            } else {
                $stmt = $conn->prepare("UPDATE products SET is_active = ?, updated_at = NOW() WHERE id = ? LIMIT 1");
                $stmt->bind_param('ii', $newStatus, $toggleId);
            }

            if ($stmt && $stmt->execute()) {
                if (function_exists('addAuditLog')) {
                    addAuditLog(
                        $conn,
                        $businessId,
                        $userId,
                        'Products',
                        'Status Change',
                        $toggleId,
                        'Changed product status'
                    );
                }
            }
            if ($stmt) {
                $stmt->close();
            }
        }
    }

    header('Location: products.php?msg=status_changed');
    exit;
}

/* -------------------------------------------------------
   COUNTS
------------------------------------------------------- */
$totalProducts = 0;
$activeProducts = 0;
$inactiveProducts = 0;

$sql = "SELECT COUNT(*) AS cnt FROM products WHERE 1=1";
if ($prodHasBusinessId) {
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
    $totalProducts = (int)($row['cnt'] ?? 0);
    $stmt->close();
}

if ($prodHasIsActive) {
    $sql = "SELECT COUNT(*) AS cnt FROM products WHERE is_active = 1";
    if ($prodHasBusinessId) {
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
        $activeProducts = (int)($row['cnt'] ?? 0);
        $stmt->close();
    }

    $sql = "SELECT COUNT(*) AS cnt FROM products WHERE is_active = 0";
    if ($prodHasBusinessId) {
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
        $inactiveProducts = (int)($row['cnt'] ?? 0);
        $stmt->close();
    }
}

/* -------------------------------------------------------
   FILTERS
------------------------------------------------------- */
$search = trim((string)($_GET['search'] ?? ''));
$categoryFilter = (int)($_GET['category_id'] ?? 0);
$status = trim((string)($_GET['status'] ?? 'all'));

$where = " WHERE 1=1 ";
$params = [];
$types = '';

if ($prodHasBusinessId) {
    $where .= " AND p.business_id = ? ";
    $params[] = $businessId;
    $types .= 'i';
}

if ($search !== '') {
    $where .= " AND (p.product_name LIKE ? OR p.product_code LIKE ?";
    if ($prodHasBarcode) {
        $where .= " OR p.barcode LIKE ?";
    }
    if ($prodHasDesignName) {
        $where .= " OR p.design_name LIKE ?";
    }
    $where .= " OR c.category_name LIKE ?)";

    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $types .= 'ss';

    if ($prodHasBarcode) {
        $params[] = $like;
        $types .= 's';
    }
    if ($prodHasDesignName) {
        $params[] = $like;
        $types .= 's';
    }

    $params[] = $like;
    $types .= 's';
}

if ($categoryFilter > 0) {
    $where .= " AND p.category_id = ? ";
    $params[] = $categoryFilter;
    $types .= 'i';
}

if ($prodHasIsActive) {
    if ($status === 'active') {
        $where .= " AND p.is_active = 1 ";
    } elseif ($status === 'inactive') {
        $where .= " AND p.is_active = 0 ";
    }
}

/* -------------------------------------------------------
   CATEGORY OPTIONS
------------------------------------------------------- */
$categorySql = "SELECT id, category_name FROM product_categories WHERE 1=1";
$categoryParams = [];
$categoryTypes = '';

if ($catHasBusinessId) {
    $categorySql .= " AND business_id = ?";
    $categoryParams[] = $businessId;
    $categoryTypes .= 'i';
}
if (hasColumn($conn, 'product_categories', 'is_active')) {
    $categorySql .= " AND is_active = 1";
}
$categorySql .= " ORDER BY category_name ASC";

$categories = [];
$stmt = $conn->prepare($categorySql);
if ($stmt) {
    if (!empty($categoryParams)) {
        $bindValues = [];
        $bindValues[] = $categoryTypes;
        for ($i = 0; $i < count($categoryParams); $i++) {
            $bindValues[] = &$categoryParams[$i];
        }
        call_user_func_array([$stmt, 'bind_param'], $bindValues);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && $row = $res->fetch_assoc()) {
        $categories[] = $row;
    }
    $stmt->close();
}

/* -------------------------------------------------------
   PRODUCT LIST
------------------------------------------------------- */
$sql = "SELECT 
            p.id,
            p.product_code,
            p.product_name,
            c.category_name";

if ($prodHasBarcode) {
    $sql .= ", p.barcode";
}
if ($prodHasDesignName) {
    $sql .= ", p.design_name";
}
if ($prodHasPurity) {
    $sql .= ", p.purity";
}
if ($prodHasUnit) {
    $sql .= ", p.unit";
}
if ($prodHasGrossWeight) {
    $sql .= ", p.gross_weight";
}
if ($prodHasLessWeight) {
    $sql .= ", p.less_weight";
}
if ($prodHasNetWeight) {
    $sql .= ", p.net_weight";
}
if ($prodHasPurchaseRate) {
    $sql .= ", p.purchase_rate";
}
if ($prodHasSaleRate) {
    $sql .= ", p.sale_rate";
}
if ($prodHasCurrentStockQty) {
    $sql .= ", p.current_stock_qty";
}
if ($prodHasImagePath) {
    $sql .= ", p.image_path";
}
if ($prodHasIsActive) {
    $sql .= ", p.is_active";
}
if ($prodHasCreatedAt) {
    $sql .= ", p.created_at";
}

$sql .= " FROM products p
          LEFT JOIN product_categories c ON c.id = p.category_id
          $where
          ORDER BY p.id DESC";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die('Failed to prepare products query.');
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
$products = [];
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $products[] = $row;
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
                                <h3 class="text-primary mt-2"><?php echo $totalProducts; ?></h3>
                                <p class="text-muted mb-0">Total Products</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4 col-xl-4">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-success mt-2"><?php echo $activeProducts; ?></h3>
                                <p class="text-muted mb-0">Active Products</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4 col-xl-4">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-danger mt-2"><?php echo $inactiveProducts; ?></h3>
                                <p class="text-muted mb-0">Inactive Products</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <form method="get" class="row g-2 align-items-end">
                            <div class="col-md-4">
                                <label class="form-label">Search</label>
                                <input type="text" name="search" class="form-control" placeholder="Product name, code, barcode..." value="<?php echo h($search); ?>">
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Category</label>
                                <select name="category_id" class="form-select">
                                    <option value="0">All Categories</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo (int)$cat['id']; ?>" <?php echo $categoryFilter === (int)$cat['id'] ? 'selected' : ''; ?>>
                                            <?php echo h($cat['category_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
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
                                <a href="products.php" class="btn btn-secondary">Reset</a>
                                <a href="product-add.php" class="btn btn-primary">Add Product</a>
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
                                        <th>Product</th>
                                        <th>Category</th>
                                        <?php if ($prodHasBarcode): ?><th>Barcode</th><?php endif; ?>
                                        <?php if ($prodHasPurity): ?><th>Purity</th><?php endif; ?>
                                        <?php if ($prodHasNetWeight): ?><th>Net Weight</th><?php endif; ?>
                                        <?php if ($prodHasSaleRate): ?><th>Sale Rate</th><?php endif; ?>
                                        <?php if ($prodHasCurrentStockQty): ?><th>Stock Qty</th><?php endif; ?>
                                        <th>Status</th>
                                        <th>Created</th>
                                        <th style="min-width: 210px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($products)): ?>
                                        <?php foreach ($products as $index => $product): ?>
                                            <tr>
                                                <td><?php echo $index + 1; ?></td>

                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <?php if ($prodHasImagePath && !empty($product['image_path'])): ?>
                                                            <img src="<?php echo h($product['image_path']); ?>" alt="Product" class="rounded me-2" style="width:50px;height:50px;object-fit:cover;">
                                                        <?php endif; ?>

                                                        <div>
                                                            <strong><?php echo h($product['product_name']); ?></strong><br>
                                                            <small class="text-muted"><?php echo h($product['product_code']); ?></small>
                                                            <?php if ($prodHasDesignName && !empty($product['design_name'])): ?>
                                                                <br><small class="text-muted"><?php echo h($product['design_name']); ?></small>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </td>

                                                <td><?php echo h($product['category_name'] ?? ''); ?></td>

                                                <?php if ($prodHasBarcode): ?>
                                                    <td><?php echo h($product['barcode'] ?? ''); ?></td>
                                                <?php endif; ?>

                                                <?php if ($prodHasPurity): ?>
                                                    <td><?php echo h($product['purity'] ?? ''); ?></td>
                                                <?php endif; ?>

                                                <?php if ($prodHasNetWeight): ?>
                                                    <td><?php echo number_format((float)($product['net_weight'] ?? 0), 3); ?></td>
                                                <?php endif; ?>

                                                <?php if ($prodHasSaleRate): ?>
                                                    <td>₹<?php echo number_format((float)($product['sale_rate'] ?? 0), 2); ?></td>
                                                <?php endif; ?>

                                                <?php if ($prodHasCurrentStockQty): ?>
                                                    <td><?php echo number_format((float)($product['current_stock_qty'] ?? 0), 3); ?></td>
                                                <?php endif; ?>

                                                <td>
                                                    <?php if ($prodHasIsActive): ?>
                                                        <?php if ((int)($product['is_active'] ?? 0) === 1): ?>
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
                                                    if ($prodHasCreatedAt && !empty($product['created_at'])) {
                                                        echo date('d-m-Y h:i A', strtotime($product['created_at']));
                                                    } else {
                                                        echo '-';
                                                    }
                                                    ?>
                                                </td>

                                                <td>
                                                    <a href="product-edit.php?id=<?php echo (int)$product['id']; ?>" class="btn btn-sm btn-primary mb-1">Edit</a>

                                                    <?php if ($prodHasIsActive): ?>
                                                        <a href="products.php?toggle=<?php echo (int)$product['id']; ?>" class="btn btn-sm btn-<?php echo (int)($product['is_active'] ?? 0) === 1 ? 'warning' : 'success'; ?> mb-1" onclick="return confirm('Are you sure?');">
                                                            <?php echo (int)($product['is_active'] ?? 0) === 1 ? 'Deactivate' : 'Activate'; ?>
                                                        </a>
                                                    <?php endif; ?>

                                                    <a href="products.php?delete=<?php echo (int)$product['id']; ?>" class="btn btn-sm btn-danger mb-1" onclick="return confirm('Are you sure you want to delete this product?');">Delete</a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="<?php echo 8 + ($prodHasBarcode ? 1 : 0) + ($prodHasPurity ? 1 : 0) + ($prodHasNetWeight ? 1 : 0) + ($prodHasSaleRate ? 1 : 0) + ($prodHasCurrentStockQty ? 1 : 0); ?>" class="text-center text-muted">
                                                No products found.
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