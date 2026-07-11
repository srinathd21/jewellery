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
    function tableExists(mysqli $conn, string $table): bool
    {
        $table = $conn->real_escape_string($table);
        $res = $conn->query("SHOW TABLES LIKE '{$table}'");
        return $res && $res->num_rows > 0;
    }
}

function hasColumn(mysqli $conn, string $table, string $column): bool
{
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    return $res && $res->num_rows > 0;
}

$pageTitle = 'Low Stock Report';

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
$stmt->bind_param('i', $userId);
$stmt->execute();
$res = $stmt->get_result();
$userRow = $res ? $res->fetch_assoc() : null;
$stmt->close();

$roleName = strtolower(trim((string)($userRow['role_name'] ?? '')));
if (!in_array($roleName, ['admin', 'manager', 'stock'], true)) {
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
$hasProductStockTable    = tableExists($conn, 'product_stock');
$hasStockMovementTable   = tableExists($conn, 'stock_movements');

$prodHasBusinessId       = hasColumn($conn, 'products', 'business_id');
$prodHasBarcode          = hasColumn($conn, 'products', 'barcode');
$prodHasDesignName       = hasColumn($conn, 'products', 'design_name');
$prodHasPurity           = hasColumn($conn, 'products', 'purity');
$prodHasUnit             = hasColumn($conn, 'products', 'unit');
$prodHasGrossWeight      = hasColumn($conn, 'products', 'gross_weight');
$prodHasLessWeight       = hasColumn($conn, 'products', 'less_weight');
$prodHasNetWeight        = hasColumn($conn, 'products', 'net_weight');
$prodHasPurchaseRate     = hasColumn($conn, 'products', 'purchase_rate');
$prodHasSaleRate         = hasColumn($conn, 'products', 'sale_rate');
$prodHasMinStockQty      = hasColumn($conn, 'products', 'min_stock_qty');
$prodHasCurrentStockQty  = hasColumn($conn, 'products', 'current_stock_qty');
$prodHasImagePath        = hasColumn($conn, 'products', 'image_path');
$prodHasIsActive         = hasColumn($conn, 'products', 'is_active');
$prodHasCreatedAt        = hasColumn($conn, 'products', 'created_at');

$catHasBusinessId        = hasColumn($conn, 'product_categories', 'business_id');
$catHasIsActive          = hasColumn($conn, 'product_categories', 'is_active');

$psHasProductId          = $hasProductStockTable && hasColumn($conn, 'product_stock', 'product_id');
$psHasBusinessId         = $hasProductStockTable && hasColumn($conn, 'product_stock', 'business_id');
$psHasOpeningQty         = $hasProductStockTable && hasColumn($conn, 'product_stock', 'opening_qty');
$psHasInQty              = $hasProductStockTable && hasColumn($conn, 'product_stock', 'in_qty');
$psHasOutQty             = $hasProductStockTable && hasColumn($conn, 'product_stock', 'out_qty');
$psHasClosingQty         = $hasProductStockTable && hasColumn($conn, 'product_stock', 'closing_qty');
$psHasClosingWeight      = $hasProductStockTable && hasColumn($conn, 'product_stock', 'closing_weight');
$psHasUpdatedAt          = $hasProductStockTable && hasColumn($conn, 'product_stock', 'updated_at');

$smHasBusinessId         = $hasStockMovementTable && hasColumn($conn, 'stock_movements', 'business_id');
$smHasProductId          = $hasStockMovementTable && hasColumn($conn, 'stock_movements', 'product_id');
$smHasMovementDate       = $hasStockMovementTable && hasColumn($conn, 'stock_movements', 'movement_date');

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
if ($catHasIsActive) {
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
   FILTERS
------------------------------------------------------- */
$search         = trim((string)($_GET['search'] ?? ''));
$categoryFilter = (int)($_GET['category_id'] ?? 0);
$productStatus  = trim((string)($_GET['status'] ?? 'active'));
$reportType     = trim((string)($_GET['report_type'] ?? 'low_stock')); // low_stock / out_of_stock / both

/* -------------------------------------------------------
   STOCK EXPRESSIONS
------------------------------------------------------- */
$stockQtyExpr = $prodHasCurrentStockQty ? "IFNULL(p.current_stock_qty, 0)" : "0";
$stockWeightExpr = $prodHasNetWeight ? "IFNULL(p.net_weight, 0)" : "0";
$openingQtyExpr = "0";
$inQtyExpr = "0";
$outQtyExpr = "0";
$lastUpdatedExpr = "p.updated_at";

$joinProductStock = "";
if ($hasProductStockTable && $psHasProductId) {
    $joinProductStock = " LEFT JOIN product_stock ps ON ps.product_id = p.id ";
    if ($psHasBusinessId && $prodHasBusinessId) {
        $joinProductStock = " LEFT JOIN product_stock ps ON ps.product_id = p.id AND ps.business_id = p.business_id ";
    }

    if ($psHasClosingQty) {
        $stockQtyExpr = "IFNULL(ps.closing_qty, " . ($prodHasCurrentStockQty ? "IFNULL(p.current_stock_qty, 0)" : "0") . ")";
    }
    if ($psHasClosingWeight) {
        $stockWeightExpr = "IFNULL(ps.closing_weight, " . ($prodHasNetWeight ? "IFNULL(p.net_weight, 0)" : "0") . ")";
    }
    if ($psHasOpeningQty) {
        $openingQtyExpr = "IFNULL(ps.opening_qty, 0)";
    }
    if ($psHasInQty) {
        $inQtyExpr = "IFNULL(ps.in_qty, 0)";
    }
    if ($psHasOutQty) {
        $outQtyExpr = "IFNULL(ps.out_qty, 0)";
    }
    if ($psHasUpdatedAt) {
        $lastUpdatedExpr = "COALESCE(ps.updated_at, p.updated_at)";
    }
}

$lastMovementExpr = "NULL";
$joinLastMovement = "";
if ($hasStockMovementTable && $smHasProductId && $smHasMovementDate) {
    $lastMovementSubWhere = " WHERE 1=1 ";
    if ($smHasBusinessId) {
        $lastMovementSubWhere .= " AND business_id = " . (int)$businessId . " ";
    }

    $joinLastMovement = "
        LEFT JOIN (
            SELECT product_id, MAX(movement_date) AS last_movement_date
            FROM stock_movements
            {$lastMovementSubWhere}
            GROUP BY product_id
        ) smx ON smx.product_id = p.id
    ";
    $lastMovementExpr = "smx.last_movement_date";
}

/* -------------------------------------------------------
   SUMMARY
------------------------------------------------------- */
$baseWhere = " WHERE 1=1 ";
$baseParams = [];
$baseTypes = '';

if ($prodHasBusinessId) {
    $baseWhere .= " AND p.business_id = ? ";
    $baseParams[] = $businessId;
    $baseTypes .= 'i';
}

if ($prodHasIsActive) {
    if ($productStatus === 'active') {
        $baseWhere .= " AND p.is_active = 1 ";
    } elseif ($productStatus === 'inactive') {
        $baseWhere .= " AND p.is_active = 0 ";
    }
}

$totalMatched = 0;
$lowStockCount = 0;
$outOfStockCount = 0;
$totalShortageQty = 0;

$summarySql = "
    SELECT
        COUNT(*) AS total_products,
        SUM(
            CASE
                WHEN {$stockQtyExpr} <= 0 THEN 1
                ELSE 0
            END
        ) AS out_of_stock_count,
        SUM(
            CASE
                WHEN IFNULL(p.min_stock_qty, 0) > 0
                     AND {$stockQtyExpr} > 0
                     AND {$stockQtyExpr} <= IFNULL(p.min_stock_qty, 0)
                THEN 1
                ELSE 0
            END
        ) AS low_stock_count,
        SUM(
            CASE
                WHEN IFNULL(p.min_stock_qty, 0) > {$stockQtyExpr}
                THEN IFNULL(p.min_stock_qty, 0) - {$stockQtyExpr}
                ELSE 0
            END
        ) AS total_shortage_qty
    FROM products p
    {$joinProductStock}
    {$baseWhere}
";

$stmt = $conn->prepare($summarySql);
if ($stmt) {
    if (!empty($baseParams)) {
        $bindValues = [];
        $bindValues[] = $baseTypes;
        for ($i = 0; $i < count($baseParams); $i++) {
            $bindValues[] = &$baseParams[$i];
        }
        call_user_func_array([$stmt, 'bind_param'], $bindValues);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if ($row) {
        $totalMatched = (int)($row['total_products'] ?? 0);
        $lowStockCount = (int)($row['low_stock_count'] ?? 0);
        $outOfStockCount = (int)($row['out_of_stock_count'] ?? 0);
        $totalShortageQty = (float)($row['total_shortage_qty'] ?? 0);
    }
}

/* -------------------------------------------------------
   MAIN WHERE
------------------------------------------------------- */
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
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $types .= 'ss';

    if ($prodHasBarcode) {
        $where .= " OR p.barcode LIKE ?";
        $params[] = $like;
        $types .= 's';
    }

    if ($prodHasDesignName) {
        $where .= " OR p.design_name LIKE ?";
        $params[] = $like;
        $types .= 's';
    }

    $where .= " OR c.category_name LIKE ?)";
    $params[] = $like;
    $types .= 's';
}

if ($categoryFilter > 0) {
    $where .= " AND p.category_id = ? ";
    $params[] = $categoryFilter;
    $types .= 'i';
}

if ($prodHasIsActive) {
    if ($productStatus === 'active') {
        $where .= " AND p.is_active = 1 ";
    } elseif ($productStatus === 'inactive') {
        $where .= " AND p.is_active = 0 ";
    }
}

if ($prodHasMinStockQty) {
    if ($reportType === 'low_stock') {
        $where .= " AND IFNULL(p.min_stock_qty, 0) > 0 AND {$stockQtyExpr} > 0 AND {$stockQtyExpr} <= IFNULL(p.min_stock_qty, 0) ";
    } elseif ($reportType === 'out_of_stock') {
        $where .= " AND {$stockQtyExpr} <= 0 ";
    } else {
        $where .= " AND ((IFNULL(p.min_stock_qty, 0) > 0 AND {$stockQtyExpr} > 0 AND {$stockQtyExpr} <= IFNULL(p.min_stock_qty, 0)) OR {$stockQtyExpr} <= 0) ";
    }
} else {
    $where .= " AND {$stockQtyExpr} <= 0 ";
}

/* -------------------------------------------------------
   LIST QUERY
------------------------------------------------------- */
$sql = "
    SELECT
        p.id,
        p.product_code,
        p.product_name,
        c.category_name,
        " . ($prodHasBarcode ? "p.barcode," : "'' AS barcode,") . "
        " . ($prodHasDesignName ? "p.design_name," : "'' AS design_name,") . "
        " . ($prodHasPurity ? "p.purity," : "'' AS purity,") . "
        " . ($prodHasUnit ? "p.unit," : "'pcs' AS unit,") . "
        " . ($prodHasSaleRate ? "p.sale_rate," : "0 AS sale_rate,") . "
        " . ($prodHasPurchaseRate ? "p.purchase_rate," : "0 AS purchase_rate,") . "
        " . ($prodHasMinStockQty ? "p.min_stock_qty," : "0 AS min_stock_qty,") . "
        " . ($prodHasImagePath ? "p.image_path," : "'' AS image_path,") . "
        {$openingQtyExpr} AS opening_qty,
        {$inQtyExpr} AS in_qty,
        {$outQtyExpr} AS out_qty,
        {$stockQtyExpr} AS stock_qty,
        {$stockWeightExpr} AS stock_weight,
        {$lastUpdatedExpr} AS stock_updated_at,
        {$lastMovementExpr} AS last_movement_date
    FROM products p
    LEFT JOIN product_categories c ON c.id = p.category_id
    {$joinProductStock}
    {$joinLastMovement}
    {$where}
    ORDER BY ({$stockQtyExpr} <= 0) DESC, {$stockQtyExpr} ASC, p.product_name ASC
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die('Failed to prepare low stock report query.');
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

                <div class="row mb-3">
                    <div class="col-12 d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div>
                            <h4 class="mb-1">Low Stock Report</h4>
                            <p class="text-muted mb-0">Products that need restocking attention</p>
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                            <button type="button" class="btn btn-info" onclick="window.print();">Print</button>
                            <a href="stock-overview.php" class="btn btn-secondary">Stock Overview</a>
                            <a href="stock-movements.php" class="btn btn-secondary">Stock Movements</a>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-3 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-warning mt-2"><?php echo $lowStockCount; ?></h3>
                                <p class="text-muted mb-0">Low Stock Items</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-danger mt-2"><?php echo $outOfStockCount; ?></h3>
                                <p class="text-muted mb-0">Out of Stock Items</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-primary mt-2"><?php echo count($products); ?></h3>
                                <p class="text-muted mb-0">Report Results</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-dark mt-2"><?php echo number_format($totalShortageQty, 3); ?></h3>
                                <p class="text-muted mb-0">Total Shortage Qty</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <form method="get" class="row g-2 align-items-end">
                            <div class="col-md-3">
                                <label class="form-label">Search</label>
                                <input
                                    type="text"
                                    name="search"
                                    class="form-control"
                                    placeholder="Product name, code, barcode..."
                                    value="<?php echo h($search); ?>"
                                >
                            </div>

                            <div class="col-md-2">
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

                            <div class="col-md-2">
                                <label class="form-label">Report Type</label>
                                <select name="report_type" class="form-select">
                                    <option value="low_stock" <?php echo $reportType === 'low_stock' ? 'selected' : ''; ?>>Low Stock</option>
                                    <option value="out_of_stock" <?php echo $reportType === 'out_of_stock' ? 'selected' : ''; ?>>Out of Stock</option>
                                    <option value="both" <?php echo $reportType === 'both' ? 'selected' : ''; ?>>Both</option>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Product Status</label>
                                <select name="status" class="form-select">
                                    <option value="active" <?php echo $productStatus === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo $productStatus === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    <option value="all" <?php echo $productStatus === 'all' ? 'selected' : ''; ?>>All</option>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <div class="d-flex gap-2">
                                    <button type="submit" class="btn btn-primary w-100">Search</button>
                                    <a href="low-stock-report.php" class="btn btn-secondary w-100">Reset</a>
                                </div>
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
                                        <th>Min Qty</th>
                                        <th>Current Qty</th>
                                        <th>Shortage Qty</th>
                                        <th>Stock Weight</th>
                                        <?php if ($prodHasSaleRate): ?><th>Sale Rate</th><?php endif; ?>
                                        <th>Status</th>
                                        <th>Last Movement</th>
                                        <th>Last Updated</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($products)): ?>
                                        <?php foreach ($products as $index => $product): ?>
                                            <?php
                                                $minQty = (float)($product['min_stock_qty'] ?? 0);
                                                $stockQty = (float)($product['stock_qty'] ?? 0);
                                                $stockWeight = (float)($product['stock_weight'] ?? 0);
                                                $shortageQty = $minQty > $stockQty ? ($minQty - $stockQty) : 0;

                                                $statusBadge = '<span class="badge bg-warning text-dark">Low Stock</span>';
                                                if ($stockQty <= 0) {
                                                    $statusBadge = '<span class="badge bg-danger">Out of Stock</span>';
                                                }
                                            ?>
                                            <tr>
                                                <td><?php echo $index + 1; ?></td>

                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <?php if (!empty($product['image_path'])): ?>
                                                            <img
                                                                src="<?php echo h($product['image_path']); ?>"
                                                                alt="Product"
                                                                class="rounded me-2"
                                                                style="width:50px;height:50px;object-fit:cover;"
                                                            >
                                                        <?php endif; ?>

                                                        <div>
                                                            <strong><?php echo h($product['product_name']); ?></strong><br>
                                                            <small class="text-muted"><?php echo h($product['product_code']); ?></small>
                                                            <?php if (!empty($product['design_name'])): ?>
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

                                                <td><?php echo number_format($minQty, 3); ?> <?php echo h($product['unit'] ?? ''); ?></td>
                                                <td><strong><?php echo number_format($stockQty, 3); ?></strong> <?php echo h($product['unit'] ?? ''); ?></td>
                                                <td class="text-danger"><strong><?php echo number_format($shortageQty, 3); ?></strong> <?php echo h($product['unit'] ?? ''); ?></td>
                                                <td><?php echo number_format($stockWeight, 3); ?></td>

                                                <?php if ($prodHasSaleRate): ?>
                                                    <td>₹<?php echo number_format((float)($product['sale_rate'] ?? 0), 2); ?></td>
                                                <?php endif; ?>

                                                <td><?php echo $statusBadge; ?></td>

                                                <td>
                                                    <?php
                                                    if (!empty($product['last_movement_date'])) {
                                                        echo date('d-m-Y h:i A', strtotime($product['last_movement_date']));
                                                    } else {
                                                        echo '-';
                                                    }
                                                    ?>
                                                </td>

                                                <td>
                                                    <?php
                                                    if (!empty($product['stock_updated_at'])) {
                                                        echo date('d-m-Y h:i A', strtotime($product['stock_updated_at']));
                                                    } else {
                                                        echo '-';
                                                    }
                                                    ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <?php
                                            $colspan = 10;
                                            if ($prodHasBarcode) $colspan++;
                                            if ($prodHasPurity) $colspan++;
                                            if ($prodHasSaleRate) $colspan++;
                                        ?>
                                        <tr>
                                            <td colspan="<?php echo $colspan; ?>" class="text-center text-muted">
                                                No low stock products found.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if (!$hasProductStockTable): ?>
                            <div class="alert alert-warning mt-3 mb-0">
                                <strong>Note:</strong> `product_stock` table not found. Current stock is being taken from `products.current_stock_qty`.
                            </div>
                        <?php endif; ?>

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