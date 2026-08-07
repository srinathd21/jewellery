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

function money($amount): string
{
    return number_format((float)$amount, 2);
}

function qtyf($qty): string
{
    $qty = (float)$qty;
    if ((int)$qty == $qty) {
        return number_format($qty, 0);
    }
    return number_format($qty, 3);
}

$pageTitle = 'Barcode Print';

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
if (!in_array($roleName, ['admin', 'manager', 'stock', 'billing'], true)) {
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
$prodHasBusinessId       = hasColumn($conn, 'products', 'business_id');
$prodHasBarcode          = hasColumn($conn, 'products', 'barcode');
$prodHasDesignName       = hasColumn($conn, 'products', 'design_name');
$prodHasPurity           = hasColumn($conn, 'products', 'purity');
$prodHasUnit             = hasColumn($conn, 'products', 'unit');
$prodHasGrossWeight      = hasColumn($conn, 'products', 'gross_weight');
$prodHasLessWeight       = hasColumn($conn, 'products', 'less_weight');
$prodHasNetWeight        = hasColumn($conn, 'products', 'net_weight');
$prodHasSaleRate         = hasColumn($conn, 'products', 'sale_rate');
$prodHasCurrentStockQty  = hasColumn($conn, 'products', 'current_stock_qty');
$prodHasImagePath        = hasColumn($conn, 'products', 'image_path');
$prodHasIsActive         = hasColumn($conn, 'products', 'is_active');
$prodHasCreatedAt        = hasColumn($conn, 'products', 'created_at');

$catHasBusinessId        = hasColumn($conn, 'product_categories', 'business_id');
$catHasIsActive          = hasColumn($conn, 'product_categories', 'is_active');

/* -------------------------------------------------------
   COMPANY NAME
------------------------------------------------------- */
$companyName = '';
if (tableExists($conn, 'company_settings') && hasColumn($conn, 'company_settings', 'company_name') && hasColumn($conn, 'company_settings', 'business_id')) {
    $stmt = $conn->prepare("SELECT company_name FROM company_settings WHERE business_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $businessId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $companyName = trim((string)($row['company_name'] ?? ''));
        $stmt->close();
    }
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
$search = trim((string)($_GET['search'] ?? $_POST['search'] ?? ''));
$categoryFilter = (int)($_GET['category_id'] ?? $_POST['category_id'] ?? 0);
$status = trim((string)($_GET['status'] ?? $_POST['status'] ?? 'all'));
$printMode = (int)($_GET['print'] ?? $_POST['print'] ?? 0);
$singleProductId = (int)($_GET['product_id'] ?? $_POST['product_id'] ?? 0);
$defaultCopies = (int)($_GET['copies'] ?? $_POST['copies'] ?? 1);

if ($defaultCopies <= 0) {
    $defaultCopies = 1;
}
if ($defaultCopies > 100) {
    $defaultCopies = 100;
}

$where = " WHERE 1=1 ";
$params = [];
$types = '';

if ($prodHasBusinessId) {
    $where .= " AND p.business_id = ? ";
    $params[] = $businessId;
    $types .= 'i';
}

if ($singleProductId > 0) {
    $where .= " AND p.id = ? ";
    $params[] = $singleProductId;
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
        $barcodeValue = trim((string)($row['barcode'] ?? ''));
        if ($barcodeValue === '') {
            $barcodeValue = trim((string)($row['product_code'] ?? ''));
        }
        if ($barcodeValue === '') {
            $barcodeValue = 'PRD' . str_pad((string)$row['id'], 6, '0', STR_PAD_LEFT);
        }

        $row['barcode_value'] = $barcodeValue;
        $row['barcode_image_url'] = 'https://barcode.tec-it.com/barcode.ashx?data=' . rawurlencode($barcodeValue) . '&code=Code128&dpi=96';
        $products[] = $row;
    }
}
$stmt->close();

/* -------------------------------------------------------
   PRINT LABELS
------------------------------------------------------- */
$labels = [];

if ($printMode === 1) {
    foreach ($products as $product) {
        $copiesForThis = $defaultCopies;

        if (isset($_POST['copies_map'][$product['id']])) {
            $copiesForThis = (int)$_POST['copies_map'][$product['id']];
        }

        if ($copiesForThis <= 0) {
            continue;
        }

        if ($copiesForThis > 100) {
            $copiesForThis = 100;
        }

        for ($i = 0; $i < $copiesForThis; $i++) {
            $labels[] = $product;
        }
    }
}
?>
<!doctype html>
<html lang="en">

<?php include('includes/head.php'); ?>

<style>
    .barcode-page-card {
        background: #fff;
        border-radius: 10px;
        box-shadow: 0 1px 6px rgba(0,0,0,0.08);
        padding: 18px;
        margin-bottom: 20px;
    }
    .barcode-thumb {
        width: 50px;
        height: 50px;
        object-fit: cover;
        border-radius: 8px;
        border: 1px solid #e5e7eb;
    }
    .barcode-placeholder {
        width: 50px;
        height: 50px;
        border-radius: 8px;
        border: 1px solid #e5e7eb;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 11px;
        color: #6b7280;
        background: #f8f9fa;
    }
    .copies-box {
        width: 80px;
    }
    .barcode-labels-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
        gap: 14px;
    }
    .barcode-label-item {
        width: 230px;
        min-height: 145px;
        border: 1px dashed #222;
        background: #fff;
        padding: 8px;
        box-sizing: border-box;
        page-break-inside: avoid;
        border-radius: 6px;
    }
    .barcode-company {
        text-align: center;
        font-size: 11px;
        font-weight: 700;
        margin-bottom: 3px;
    }
    .barcode-title {
        text-align: center;
        font-size: 13px;
        font-weight: 700;
        line-height: 1.25;
        min-height: 32px;
        margin-bottom: 4px;
    }
    .barcode-sub {
        text-align: center;
        font-size: 11px;
        color: #374151;
        margin-bottom: 4px;
    }
    .barcode-image {
        text-align: center;
        margin: 4px 0;
    }
    .barcode-image img {
        max-width: 100%;
        height: 42px;
        object-fit: contain;
    }
    .barcode-code {
        text-align: center;
        font-size: 12px;
        font-weight: 700;
        letter-spacing: 1px;
        margin-top: 2px;
    }
    .barcode-price {
        text-align: center;
        font-size: 15px;
        font-weight: 700;
        margin-top: 5px;
    }
    .barcode-meta {
        display: flex;
        justify-content: space-between;
        font-size: 11px;
        margin-top: 5px;
        gap: 8px;
    }

    @media print {
        body * {
            visibility: hidden;
        }
        #printArea, #printArea * {
            visibility: visible;
        }
        #printArea {
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
            background: #fff;
            padding: 8mm;
        }
        .barcode-labels-grid {
            grid-template-columns: repeat(3, 1fr);
            gap: 6mm;
        }
        .barcode-label-item {
            width: 100%;
            min-height: 36mm;
            border: 1px solid #000;
            border-radius: 0;
        }
        .no-print {
            display: none !important;
        }
        @page {
            size: A4 portrait;
            margin: 8mm;
        }
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

                <div class="row no-print">
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

                <div class="card no-print">
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

                            <div class="col-md-2">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select">
                                    <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All Status</option>
                                    <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Default Copies</label>
                                <input type="number" name="copies" class="form-control" min="1" max="100" value="<?php echo (int)$defaultCopies; ?>">
                            </div>

                            <div class="col-md-1">
                                <button type="submit" class="btn btn-primary w-100">Go</button>
                            </div>

                            <div class="col-md-12">
                                <a href="barcode-print.php" class="btn btn-secondary">Reset</a>
                                <button type="button" onclick="window.print()" class="btn btn-dark <?php echo empty($labels) ? 'd-none' : ''; ?>">Print Labels</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card no-print">
                    <div class="card-body">
                        <form method="post">
                            <input type="hidden" name="search" value="<?php echo h($search); ?>">
                            <input type="hidden" name="category_id" value="<?php echo (int)$categoryFilter; ?>">
                            <input type="hidden" name="status" value="<?php echo h($status); ?>">
                            <input type="hidden" name="copies" value="<?php echo (int)$defaultCopies; ?>">
                            <input type="hidden" name="print" value="1">

                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h4 class="card-title mb-0">Products</h4>
                                <?php if (!empty($products)): ?>
                                    <button type="submit" class="btn btn-primary">Generate All Filtered Labels</button>
                                <?php endif; ?>
                            </div>

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
                                            <th>Copies</th>
                                            <th style="min-width: 180px;">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($products)): ?>
                                            <?php foreach ($products as $index => $product): ?>
                                                <tr>
                                                    <td><?php echo $index + 1; ?></td>

                                                    <td>
                                                        <div class="d-flex align-items-center">
                                                            <?php if ($prodHasImagePath && !empty($product['image_path']) && file_exists($product['image_path'])): ?>
                                                                <img src="<?php echo h($product['image_path']); ?>" alt="Product" class="barcode-thumb me-2">
                                                            <?php else: ?>
                                                                <span class="barcode-placeholder me-2">No Img</span>
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
                                                        <td><?php echo h($product['barcode_value']); ?></td>
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
                                                        <input
                                                            type="number"
                                                            name="copies_map[<?php echo (int)$product['id']; ?>]"
                                                            class="form-control copies-box"
                                                            min="0"
                                                            max="100"
                                                            value="<?php echo (int)$defaultCopies; ?>"
                                                        >
                                                    </td>

                                                    <td>
                                                        <a
                                                            href="barcode-print.php?product_id=<?php echo (int)$product['id']; ?>&print=1&copies=1"
                                                            class="btn btn-sm btn-dark"
                                                            target="_blank"
                                                        >
                                                            Print Single
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="<?php echo 7 + ($prodHasBarcode ? 1 : 0) + ($prodHasPurity ? 1 : 0) + ($prodHasNetWeight ? 1 : 0) + ($prodHasSaleRate ? 1 : 0) + ($prodHasCurrentStockQty ? 1 : 0) + 2; ?>" class="text-center text-muted">
                                                    No products found.
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </form>
                    </div>
                </div>

                <div id="printArea" class="<?php echo empty($labels) ? 'd-none' : ''; ?>">
                    <?php if (!empty($labels)): ?>
                        <div class="barcode-page-card">
                            <div class="d-flex justify-content-between align-items-center mb-3 no-print">
                                <h4 class="mb-0">Generated Barcode Labels</h4>
                                <button type="button" onclick="window.print()" class="btn btn-dark">Print Now</button>
                            </div>

                            <div class="barcode-labels-grid">
                                <?php foreach ($labels as $product): ?>
                                    <div class="barcode-label-item">
                                        <?php if ($companyName !== ''): ?>
                                            <div class="barcode-company"><?php echo h($companyName); ?></div>
                                        <?php endif; ?>

                                        <div class="barcode-title"><?php echo h($product['product_name']); ?></div>

                                        <div class="barcode-sub">
                                            <?php
                                            $sub = [];
                                            if (!empty($product['purity'])) {
                                                $sub[] = 'Purity: ' . $product['purity'];
                                            }
                                            if (isset($product['net_weight']) && (float)$product['net_weight'] > 0) {
                                                $sub[] = 'Wt: ' . qtyf($product['net_weight']) . ' g';
                                            }
                                            echo h(implode(' | ', $sub));
                                            ?>
                                        </div>

                                        <div class="barcode-image">
                                            <img src="<?php echo h($product['barcode_image_url']); ?>" alt="Barcode">
                                        </div>

                                        <div class="barcode-code"><?php echo h($product['barcode_value']); ?></div>

                                        <div class="barcode-price">₹ <?php echo money($product['sale_rate'] ?? 0); ?></div>

                                        <div class="barcode-meta">
                                            <span><?php echo h($product['product_code'] ?? ''); ?></span>
                                            <span><?php echo h($product['unit'] ?? ''); ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
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