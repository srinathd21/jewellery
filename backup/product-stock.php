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

function hasColumn(mysqli $conn, string $table, string $column): bool
{
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    return $res && $res->num_rows > 0;
}

function qtyf($qty): string
{
    $qty = (float)$qty;
    if ((int)$qty == $qty) {
        return number_format($qty, 0);
    }
    return number_format($qty, 3);
}

function money($amount): string
{
    return number_format((float)$amount, 2);
}

$pageTitle = 'Product Stock';

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
$prodHasBusinessId        = hasColumn($conn, 'products', 'business_id');
$prodHasCategoryId        = hasColumn($conn, 'products', 'category_id');
$prodHasProductCode       = hasColumn($conn, 'products', 'product_code');
$prodHasBarcode           = hasColumn($conn, 'products', 'barcode');
$prodHasDesignName        = hasColumn($conn, 'products', 'design_name');
$prodHasPurity            = hasColumn($conn, 'products', 'purity');
$prodHasUnit              = hasColumn($conn, 'products', 'unit');
$prodHasGrossWeight       = hasColumn($conn, 'products', 'gross_weight');
$prodHasLessWeight        = hasColumn($conn, 'products', 'less_weight');
$prodHasNetWeight         = hasColumn($conn, 'products', 'net_weight');
$prodHasPurchaseRate      = hasColumn($conn, 'products', 'purchase_rate');
$prodHasSaleRate          = hasColumn($conn, 'products', 'sale_rate');
$prodHasMinStockQty       = hasColumn($conn, 'products', 'min_stock_qty');
$prodHasCurrentStockQty   = hasColumn($conn, 'products', 'current_stock_qty');
$prodHasImagePath         = hasColumn($conn, 'products', 'image_path');
$prodHasDescription       = hasColumn($conn, 'products', 'description');
$prodHasIsActive          = hasColumn($conn, 'products', 'is_active');

$catHasBusinessId         = hasColumn($conn, 'product_categories', 'business_id');
$catHasIsActive           = hasColumn($conn, 'product_categories', 'is_active');

$stockTableExists         = tableExists($conn, 'product_stock');
$stockHasBusinessId       = $stockTableExists && hasColumn($conn, 'product_stock', 'business_id');
$stockHasOpeningQty       = $stockTableExists && hasColumn($conn, 'product_stock', 'opening_qty');
$stockHasOpeningWeight    = $stockTableExists && hasColumn($conn, 'product_stock', 'opening_weight');
$stockHasInQty            = $stockTableExists && hasColumn($conn, 'product_stock', 'in_qty');
$stockHasInWeight         = $stockTableExists && hasColumn($conn, 'product_stock', 'in_weight');
$stockHasOutQty           = $stockTableExists && hasColumn($conn, 'product_stock', 'out_qty');
$stockHasOutWeight        = $stockTableExists && hasColumn($conn, 'product_stock', 'out_weight');
$stockHasClosingQty       = $stockTableExists && hasColumn($conn, 'product_stock', 'closing_qty');
$stockHasClosingWeight    = $stockTableExists && hasColumn($conn, 'product_stock', 'closing_weight');

$movementsTableExists     = tableExists($conn, 'stock_movements');
$movHasBusinessId         = $movementsTableExists && hasColumn($conn, 'stock_movements', 'business_id');

/* -------------------------------------------------------
   FILTERS
------------------------------------------------------- */
$search = trim((string)($_GET['search'] ?? ''));
$categoryId = (int)($_GET['category_id'] ?? 0);
$status = trim((string)($_GET['status'] ?? 'all')); // all, in_stock, low_stock, out_stock, inactive

/* -------------------------------------------------------
   CATEGORY LIST
------------------------------------------------------- */
$categories = [];

if ($catHasBusinessId) {
    $sql = "
        SELECT id, category_name
        FROM product_categories
        WHERE business_id = ?
          AND " . ($catHasIsActive ? "is_active = 1" : "1=1") . "
        ORDER BY category_name ASC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $businessId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && $row = $res->fetch_assoc()) {
        $categories[] = $row;
    }
    $stmt->close();
} else {
    $sql = "
        SELECT id, category_name
        FROM product_categories
        WHERE " . ($catHasIsActive ? "is_active = 1" : "1=1") . "
        ORDER BY category_name ASC
    ";
    $res = $conn->query($sql);
    while ($res && $row = $res->fetch_assoc()) {
        $categories[] = $row;
    }
}

/* -------------------------------------------------------
   BUILD STOCK QUERY
------------------------------------------------------- */
$where = [];
$types = '';
$params = [];

if ($prodHasBusinessId) {
    $where[] = "p.business_id = ?";
    $types .= 'i';
    $params[] = $businessId;
}

if ($categoryId > 0) {
    $where[] = "p.category_id = ?";
    $types .= 'i';
    $params[] = $categoryId;
}

if ($search !== '') {
    $searchLike = '%' . $search . '%';
    $searchParts = [];

    $searchParts[] = "p.product_name LIKE ?";
    $types .= 's';
    $params[] = $searchLike;

    if ($prodHasProductCode) {
        $searchParts[] = "p.product_code LIKE ?";
        $types .= 's';
        $params[] = $searchLike;
    }

    if ($prodHasBarcode) {
        $searchParts[] = "p.barcode LIKE ?";
        $types .= 's';
        $params[] = $searchLike;
    }

    if ($prodHasDesignName) {
        $searchParts[] = "p.design_name LIKE ?";
        $types .= 's';
        $params[] = $searchLike;
    }

    $searchParts[] = "pc.category_name LIKE ?";
    $types .= 's';
    $params[] = $searchLike;

    $where[] = '(' . implode(' OR ', $searchParts) . ')';
}

if ($status === 'inactive' && $prodHasIsActive) {
    $where[] = "p.is_active = 0";
} elseif ($status !== 'inactive' && $prodHasIsActive) {
    $where[] = "p.is_active = 1";
}

$stockQtyExpr = "0";
$stockWeightExpr = "0";
$openingQtyExpr = "0";
$openingWeightExpr = "0";
$inQtyExpr = "0";
$inWeightExpr = "0";
$outQtyExpr = "0";
$outWeightExpr = "0";

$joins = "
    LEFT JOIN product_categories pc ON pc.id = p.category_id
";

if ($stockTableExists) {
    $joins .= " LEFT JOIN product_stock ps ON ps.product_id = p.id ";
    if ($stockHasClosingQty) {
        $stockQtyExpr = "COALESCE(ps.closing_qty, 0)";
    } elseif ($prodHasCurrentStockQty) {
        $stockQtyExpr = "COALESCE(p.current_stock_qty, 0)";
    }

    if ($stockHasClosingWeight) {
        $stockWeightExpr = "COALESCE(ps.closing_weight, 0)";
    } elseif ($prodHasNetWeight && $prodHasCurrentStockQty) {
        $stockWeightExpr = "COALESCE(p.net_weight * p.current_stock_qty, 0)";
    }

    if ($stockHasOpeningQty) {
        $openingQtyExpr = "COALESCE(ps.opening_qty, 0)";
    }
    if ($stockHasOpeningWeight) {
        $openingWeightExpr = "COALESCE(ps.opening_weight, 0)";
    }
    if ($stockHasInQty) {
        $inQtyExpr = "COALESCE(ps.in_qty, 0)";
    }
    if ($stockHasInWeight) {
        $inWeightExpr = "COALESCE(ps.in_weight, 0)";
    }
    if ($stockHasOutQty) {
        $outQtyExpr = "COALESCE(ps.out_qty, 0)";
    }
    if ($stockHasOutWeight) {
        $outWeightExpr = "COALESCE(ps.out_weight, 0)";
    }
} else {
    if ($prodHasCurrentStockQty) {
        $stockQtyExpr = "COALESCE(p.current_stock_qty, 0)";
    }
    if ($prodHasNetWeight && $prodHasCurrentStockQty) {
        $stockWeightExpr = "COALESCE(p.net_weight * p.current_stock_qty, 0)";
    } elseif ($prodHasNetWeight) {
        $stockWeightExpr = "COALESCE(p.net_weight, 0)";
    }
}

if ($status === 'out_stock') {
    $where[] = "(" . $stockQtyExpr . " <= 0)";
} elseif ($status === 'low_stock') {
    if ($prodHasMinStockQty) {
        $where[] = "(" . $stockQtyExpr . " > 0 AND " . $stockQtyExpr . " <= COALESCE(p.min_stock_qty, 0) AND COALESCE(p.min_stock_qty, 0) > 0)";
    }
} elseif ($status === 'in_stock') {
    $where[] = "(" . $stockQtyExpr . " > 0)";
}

$sql = "
    SELECT
        p.id,
        p.product_name,
        " . ($prodHasProductCode ? "p.product_code," : "'' AS product_code,") . "
        " . ($prodHasBarcode ? "p.barcode," : "'' AS barcode,") . "
        " . ($prodHasDesignName ? "p.design_name," : "'' AS design_name,") . "
        " . ($prodHasPurity ? "p.purity," : "'925' AS purity,") . "
        " . ($prodHasUnit ? "p.unit," : "'pcs' AS unit,") . "
        " . ($prodHasGrossWeight ? "p.gross_weight," : "0 AS gross_weight,") . "
        " . ($prodHasLessWeight ? "p.less_weight," : "0 AS less_weight,") . "
        " . ($prodHasNetWeight ? "p.net_weight," : "0 AS net_weight,") . "
        " . ($prodHasPurchaseRate ? "p.purchase_rate," : "0 AS purchase_rate,") . "
        " . ($prodHasSaleRate ? "p.sale_rate," : "0 AS sale_rate,") . "
        " . ($prodHasMinStockQty ? "p.min_stock_qty," : "0 AS min_stock_qty,") . "
        " . ($prodHasCurrentStockQty ? "p.current_stock_qty," : "0 AS current_stock_qty,") . "
        " . ($prodHasImagePath ? "p.image_path," : "'' AS image_path,") . "
        " . ($prodHasDescription ? "p.description," : "'' AS description,") . "
        " . ($prodHasIsActive ? "p.is_active," : "1 AS is_active,") . "
        pc.category_name,
        {$openingQtyExpr} AS opening_qty,
        {$openingWeightExpr} AS opening_weight,
        {$inQtyExpr} AS in_qty,
        {$inWeightExpr} AS in_weight,
        {$outQtyExpr} AS out_qty,
        {$outWeightExpr} AS out_weight,
        {$stockQtyExpr} AS stock_qty,
        {$stockWeightExpr} AS stock_weight
    FROM products p
    {$joins}
";

if (!empty($where)) {
    $sql .= " WHERE " . implode(' AND ', $where);
}

$sql .= " ORDER BY p.product_name ASC, p.id DESC";

$products = [];

$stmt = $conn->prepare($sql);
if ($stmt) {
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
    while ($res && $row = $res->fetch_assoc()) {
        $products[] = $row;
    }
    $stmt->close();
}

/* -------------------------------------------------------
   SUMMARY
------------------------------------------------------- */
$totalProducts = count($products);
$totalStockQty = 0;
$totalStockWeight = 0;
$lowStockCount = 0;
$outStockCount = 0;
$inactiveCount = 0;
$totalStockValuePurchase = 0;
$totalStockValueSale = 0;

foreach ($products as $row) {
    $stockQty = (float)$row['stock_qty'];
    $stockWeight = (float)$row['stock_weight'];
    $minStockQty = (float)$row['min_stock_qty'];
    $purchaseRate = (float)$row['purchase_rate'];
    $saleRate = (float)$row['sale_rate'];
    $isActive = (int)$row['is_active'];

    $totalStockQty += $stockQty;
    $totalStockWeight += $stockWeight;
    $totalStockValuePurchase += ($stockQty * $purchaseRate);
    $totalStockValueSale += ($stockQty * $saleRate);

    if ($isActive !== 1) {
        $inactiveCount++;
    }

    if ($stockQty <= 0) {
        $outStockCount++;
    } elseif ($minStockQty > 0 && $stockQty <= $minStockQty) {
        $lowStockCount++;
    }
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

                <?php if (isset($_GET['msg']) && $_GET['msg'] === 'created'): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        Product created successfully.
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-md-3">
                        <div class="card mini-stats-wid">
                            <div class="card-body">
                                <p class="text-muted fw-medium mb-2">Total Products</p>
                                <h4 class="mb-0"><?php echo number_format($totalProducts); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card mini-stats-wid">
                            <div class="card-body">
                                <p class="text-muted fw-medium mb-2">Total Stock Qty</p>
                                <h4 class="mb-0"><?php echo qtyf($totalStockQty); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card mini-stats-wid">
                            <div class="card-body">
                                <p class="text-muted fw-medium mb-2">Low Stock Items</p>
                                <h4 class="mb-0 text-warning"><?php echo number_format($lowStockCount); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card mini-stats-wid">
                            <div class="card-body">
                                <p class="text-muted fw-medium mb-2">Out of Stock</p>
                                <h4 class="mb-0 text-danger"><?php echo number_format($outStockCount); ?></h4>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <form method="get" class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Search</label>
                                <input
                                    type="text"
                                    name="search"
                                    class="form-control"
                                    placeholder="Product name / code / barcode / category"
                                    value="<?php echo h($search); ?>"
                                >
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Category</label>
                                <select name="category_id" class="form-select">
                                    <option value="0">All Categories</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo (int)$cat['id']; ?>" <?php echo $categoryId === (int)$cat['id'] ? 'selected' : ''; ?>>
                                            <?php echo h($cat['category_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Stock Status</label>
                                <select name="status" class="form-select">
                                    <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All</option>
                                    <option value="in_stock" <?php echo $status === 'in_stock' ? 'selected' : ''; ?>>In Stock</option>
                                    <option value="low_stock" <?php echo $status === 'low_stock' ? 'selected' : ''; ?>>Low Stock</option>
                                    <option value="out_stock" <?php echo $status === 'out_stock' ? 'selected' : ''; ?>>Out of Stock</option>
                                    <?php if ($prodHasIsActive): ?>
                                        <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    <?php endif; ?>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label d-block">&nbsp;</label>
                                <div class="d-grid">
                                    <button type="submit" class="btn btn-primary">
                                        Filter
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-4">
                        <div class="card border">
                            <div class="card-body">
                                <h6 class="mb-2">Total Stock Weight</h6>
                                <h4 class="mb-0"><?php echo qtyf($totalStockWeight); ?> g</h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="card border">
                            <div class="card-body">
                                <h6 class="mb-2">Purchase Value</h6>
                                <h4 class="mb-0">₹ <?php echo money($totalStockValuePurchase); ?></h4>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="card border">
                            <div class="card-body">
                                <h6 class="mb-2">Sale Value</h6>
                                <h4 class="mb-0">₹ <?php echo money($totalStockValueSale); ?></h4>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th style="min-width: 70px;">#</th>
                                        <th style="min-width: 280px;">Product</th>
                                        <th>Category</th>
                                        <th>Code</th>
                                        <th>Barcode</th>
                                        <th>Purity</th>
                                        <th>Unit</th>
                                        <th class="text-end">Net Wt / Item</th>
                                        <th class="text-end">Opening Qty</th>
                                        <th class="text-end">In Qty</th>
                                        <th class="text-end">Out Qty</th>
                                        <th class="text-end">Stock Qty</th>
                                        <th class="text-end">Stock Weight</th>
                                        <th class="text-end">Min Qty</th>
                                        <th class="text-end">Purchase Rate</th>
                                        <th class="text-end">Sale Rate</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($products)): ?>
                                        <tr>
                                            <td colspan="17" class="text-center text-muted py-4">
                                                No products found.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($products as $index => $row): ?>
                                            <?php
                                            $stockQty = (float)$row['stock_qty'];
                                            $stockWeight = (float)$row['stock_weight'];
                                            $minQty = (float)$row['min_stock_qty'];
                                            $isActive = (int)$row['is_active'];

                                            $badgeClass = 'success';
                                            $badgeText = 'In Stock';

                                            if ($isActive !== 1) {
                                                $badgeClass = 'secondary';
                                                $badgeText = 'Inactive';
                                            } elseif ($stockQty <= 0) {
                                                $badgeClass = 'danger';
                                                $badgeText = 'Out of Stock';
                                            } elseif ($minQty > 0 && $stockQty <= $minQty) {
                                                $badgeClass = 'warning';
                                                $badgeText = 'Low Stock';
                                            }
                                            ?>
                                            <tr>
                                                <td><?php echo $index + 1; ?></td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <?php if (!empty($row['image_path']) && file_exists($row['image_path'])): ?>
                                                            <img
                                                                src="<?php echo h($row['image_path']); ?>"
                                                                alt="Product"
                                                                style="width:50px;height:50px;object-fit:cover;border-radius:8px;margin-right:10px;border:1px solid #e2e8f0;"
                                                            >
                                                        <?php else: ?>
                                                            <div
                                                                style="width:50px;height:50px;border-radius:8px;margin-right:10px;border:1px solid #e2e8f0;display:flex;align-items:center;justify-content:center;background:#f8f9fa;color:#6c757d;font-size:12px;"
                                                            >
                                                                N/A
                                                            </div>
                                                        <?php endif; ?>

                                                        <div>
                                                            <div class="fw-semibold"><?php echo h($row['product_name']); ?></div>
                                                            <?php if (!empty($row['design_name'])): ?>
                                                                <div class="text-muted small">Design: <?php echo h($row['design_name']); ?></div>
                                                            <?php endif; ?>
                                                            <?php if (!empty($row['description'])): ?>
                                                                <div class="text-muted small"><?php echo h($row['description']); ?></div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td><?php echo h($row['category_name']); ?></td>
                                                <td><?php echo h($row['product_code']); ?></td>
                                                <td><?php echo h($row['barcode']); ?></td>
                                                <td><?php echo h($row['purity']); ?></td>
                                                <td><?php echo h($row['unit']); ?></td>
                                                <td class="text-end"><?php echo qtyf($row['net_weight']); ?></td>
                                                <td class="text-end"><?php echo qtyf($row['opening_qty']); ?></td>
                                                <td class="text-end"><?php echo qtyf($row['in_qty']); ?></td>
                                                <td class="text-end"><?php echo qtyf($row['out_qty']); ?></td>
                                                <td class="text-end fw-bold"><?php echo qtyf($stockQty); ?></td>
                                                <td class="text-end"><?php echo qtyf($stockWeight); ?></td>
                                                <td class="text-end"><?php echo qtyf($row['min_stock_qty']); ?></td>
                                                <td class="text-end">₹ <?php echo money($row['purchase_rate']); ?></td>
                                                <td class="text-end">₹ <?php echo money($row['sale_rate']); ?></td>
                                                <td>
                                                    <span class="badge bg-<?php echo $badgeClass; ?>">
                                                        <?php echo h($badgeText); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
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