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

$pageTitle = 'Edit Product';
$page_title = 'Edit Product';
$currentPage = 'products';

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
if (!tableExists($conn, 'products') || !tableExists($conn, 'product_categories')) {
    die('Required tables not found. Please import the SQL first.');
}

/* -------------------------------------------------------
   COLUMN CHECKS
------------------------------------------------------- */
$catHasBusinessId = hasColumn($conn, 'product_categories', 'business_id');
$catHasIsActive = hasColumn($conn, 'product_categories', 'is_active');

$prodHasBusinessId = hasColumn($conn, 'products', 'business_id');
$prodHasCategoryId = hasColumn($conn, 'products', 'category_id');
$prodHasProductCode = hasColumn($conn, 'products', 'product_code');
$prodHasProductName = hasColumn($conn, 'products', 'product_name');
$prodHasBarcode = hasColumn($conn, 'products', 'barcode');
$prodHasDesignName = hasColumn($conn, 'products', 'design_name');
$prodHasPurity = hasColumn($conn, 'products', 'purity');
$prodHasUnit = hasColumn($conn, 'products', 'unit');
$prodHasGrossWeight = hasColumn($conn, 'products', 'gross_weight');
$prodHasLessWeight = hasColumn($conn, 'products', 'less_weight');
$prodHasNetWeight = hasColumn($conn, 'products', 'net_weight');
$prodHasMakingChargeType = hasColumn($conn, 'products', 'making_charge_type');
$prodHasMakingCharge = hasColumn($conn, 'products', 'making_charge');
$prodHasWastagePercent = hasColumn($conn, 'products', 'wastage_percent');
$prodHasStoneCharge = hasColumn($conn, 'products', 'stone_charge');
$prodHasPurchaseRate = hasColumn($conn, 'products', 'purchase_rate');
$prodHasSaleRate = hasColumn($conn, 'products', 'sale_rate');
$prodHasMinStockQty = hasColumn($conn, 'products', 'min_stock_qty');
$prodHasCurrentStockQty = hasColumn($conn, 'products', 'current_stock_qty');
$prodHasImagePath = hasColumn($conn, 'products', 'image_path');
$prodHasDescription = hasColumn($conn, 'products', 'description');
$prodHasIsActive = hasColumn($conn, 'products', 'is_active');
$prodHasCreatedAt = hasColumn($conn, 'products', 'created_at');
$prodHasUpdatedAt = hasColumn($conn, 'products', 'updated_at');

if (!$prodHasCategoryId || !$prodHasProductCode || !$prodHasProductName) {
    die('Required product columns are missing.');
}

/* -------------------------------------------------------
   PRODUCT ID
------------------------------------------------------- */
$productId = (int)($_GET['id'] ?? $_POST['product_id'] ?? 0);

if ($productId <= 0) {
    die('Invalid product ID.');
}

/* -------------------------------------------------------
   HELPERS
------------------------------------------------------- */
function productCodeExistsForOther(mysqli $conn, string $productCode, int $productId, int $businessId, bool $hasBusinessId): bool
{
    if ($productCode === '') {
        return false;
    }

    if ($hasBusinessId) {
        $stmt = $conn->prepare("SELECT id FROM products WHERE product_code = ? AND id != ? AND business_id = ? LIMIT 1");
        $stmt->bind_param('sii', $productCode, $productId, $businessId);
    } else {
        $stmt = $conn->prepare("SELECT id FROM products WHERE product_code = ? AND id != ? LIMIT 1");
        $stmt->bind_param('si', $productCode, $productId);
    }

    if (!$stmt) {
        return true;
    }

    $stmt->execute();
    $res = $stmt->get_result();
    $exists = $res && $res->num_rows > 0;
    $stmt->close();

    return $exists;
}

function barcodeExistsForOther(mysqli $conn, string $barcode, int $productId, int $businessId, bool $hasBusinessId): bool
{
    $barcode = trim($barcode);

    if ($barcode === '') {
        return false;
    }

    if ($hasBusinessId) {
        $stmt = $conn->prepare("SELECT id FROM products WHERE barcode = ? AND id != ? AND business_id = ? LIMIT 1");
        $stmt->bind_param('sii', $barcode, $productId, $businessId);
    } else {
        $stmt = $conn->prepare("SELECT id FROM products WHERE barcode = ? AND id != ? LIMIT 1");
        $stmt->bind_param('si', $barcode, $productId);
    }

    if (!$stmt) {
        return true;
    }

    $stmt->execute();
    $res = $stmt->get_result();
    $exists = $res && $res->num_rows > 0;
    $stmt->close();

    return $exists;
}

function categoryBelongsToBusiness(mysqli $conn, int $categoryId, int $businessId, bool $catHasBusinessId, bool $catHasIsActive): bool
{
    if ($categoryId <= 0) {
        return false;
    }

    $sql = "SELECT id FROM product_categories WHERE id = ?";

    if ($catHasBusinessId) {
        $sql .= " AND business_id = ?";
    }

    if ($catHasIsActive) {
        $sql .= " AND is_active = 1";
    }

    $sql .= " LIMIT 1";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return false;
    }

    if ($catHasBusinessId) {
        $stmt->bind_param('ii', $categoryId, $businessId);
    } else {
        $stmt->bind_param('i', $categoryId);
    }

    $stmt->execute();
    $res = $stmt->get_result();
    $ok = $res && $res->num_rows > 0;
    $stmt->close();

    return $ok;
}

function uploadProductImage(string $fieldName, string $uploadDir = 'uploads/products/'): array
{
    if (!isset($_FILES[$fieldName]) || !is_array($_FILES[$fieldName])) {
        return ['ok' => true, 'path' => '', 'error' => ''];
    }

    $file = $_FILES[$fieldName];

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'path' => '', 'error' => ''];
    }

    if (($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'path' => '', 'error' => 'File upload failed.'];
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif'
    ];

    $tmp = $file['tmp_name'] ?? '';
    $mime = function_exists('mime_content_type') ? mime_content_type($tmp) : '';

    if (!isset($allowed[$mime])) {
        return ['ok' => false, 'path' => '', 'error' => 'Only JPG, PNG, WEBP, GIF allowed.'];
    }

    if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
        return ['ok' => false, 'path' => '', 'error' => 'Image size must be below 2MB.'];
    }

    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0777, true);
    }

    if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
        return ['ok' => false, 'path' => '', 'error' => 'Upload folder is not writable: ' . $uploadDir];
    }

    $ext = $allowed[$mime];
    $name = 'product_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $target = rtrim($uploadDir, '/') . '/' . $name;

    if (!move_uploaded_file($tmp, $target)) {
        return ['ok' => false, 'path' => '', 'error' => 'Unable to save uploaded image.'];
    }

    return ['ok' => true, 'path' => $target, 'error' => ''];
}

/* -------------------------------------------------------
   CATEGORY LIST
------------------------------------------------------- */
$categories = [];

$categorySql = "SELECT id, category_name";

if (hasColumn($conn, 'product_categories', 'hsn_code')) {
    $categorySql .= ", hsn_code";
}

if (hasColumn($conn, 'product_categories', 'gst_percent')) {
    $categorySql .= ", gst_percent";
}

$categorySql .= " FROM product_categories WHERE 1=1";

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

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $categories[] = $row;
        }
    }

    $stmt->close();
}

/* -------------------------------------------------------
   FETCH PRODUCT
------------------------------------------------------- */
$sql = "SELECT * FROM products WHERE id = ?";

if ($prodHasBusinessId) {
    $sql .= " AND business_id = ?";
}

$sql .= " LIMIT 1";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die('Failed to prepare product query: ' . $conn->error);
}

if ($prodHasBusinessId) {
    $stmt->bind_param('ii', $productId, $businessId);
} else {
    $stmt->bind_param('i', $productId);
}

$stmt->execute();
$res = $stmt->get_result();
$product = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$product) {
    die('Product not found.');
}

/* -------------------------------------------------------
   DEFAULT VALUES FROM DATABASE
------------------------------------------------------- */
$success = '';
$error = '';

$productCode = (string)($product['product_code'] ?? '');
$barcode = (string)($product['barcode'] ?? '');
$categoryId = (int)($product['category_id'] ?? 0);
$productName = (string)($product['product_name'] ?? '');
$designName = (string)($product['design_name'] ?? '');
$purity = (string)($product['purity'] ?? '925');
$unit = (string)($product['unit'] ?? 'pcs');
$grossWeight = (string)($product['gross_weight'] ?? '0');
$lessWeight = (string)($product['less_weight'] ?? '0');
$netWeight = (string)($product['net_weight'] ?? '0');
$makingChargeType = (string)($product['making_charge_type'] ?? 'fixed');
$makingCharge = (string)($product['making_charge'] ?? '0');
$wastagePercent = (string)($product['wastage_percent'] ?? '0');
$stoneCharge = (string)($product['stone_charge'] ?? '0');
$purchaseRate = (string)($product['purchase_rate'] ?? '0');
$saleRate = (string)($product['sale_rate'] ?? '0');
$minStockQty = (string)($product['min_stock_qty'] ?? '0');
$currentStockQty = (string)($product['current_stock_qty'] ?? '0');
$description = (string)($product['description'] ?? '');
$imagePath = (string)($product['image_path'] ?? '');
$isActive = (int)($product['is_active'] ?? 1);

/* -------------------------------------------------------
   UPDATE PRODUCT
------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $productCode = strtoupper(trim((string)($_POST['product_code'] ?? '')));
    $barcode = trim((string)($_POST['barcode'] ?? ''));
    $categoryId = (int)($_POST['category_id'] ?? 0);
    $productName = trim((string)($_POST['product_name'] ?? ''));
    $designName = trim((string)($_POST['design_name'] ?? ''));
    $purity = trim((string)($_POST['purity'] ?? '925'));
    $unit = trim((string)($_POST['unit'] ?? 'pcs'));
    $grossWeight = trim((string)($_POST['gross_weight'] ?? '0'));
    $lessWeight = trim((string)($_POST['less_weight'] ?? '0'));
    $netWeight = trim((string)($_POST['net_weight'] ?? '0'));
    $makingChargeType = trim((string)($_POST['making_charge_type'] ?? 'fixed'));
    $makingCharge = trim((string)($_POST['making_charge'] ?? '0'));
    $wastagePercent = trim((string)($_POST['wastage_percent'] ?? '0'));
    $stoneCharge = trim((string)($_POST['stone_charge'] ?? '0'));
    $purchaseRate = trim((string)($_POST['purchase_rate'] ?? '0'));
    $saleRate = trim((string)($_POST['sale_rate'] ?? '0'));
    $minStockQty = trim((string)($_POST['min_stock_qty'] ?? '0'));
    $currentStockQty = trim((string)($_POST['current_stock_qty'] ?? '0'));
    $description = trim((string)($_POST['description'] ?? ''));
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    $validMakingChargeTypes = ['fixed', 'per_gram', 'percentage'];
    if (!in_array($makingChargeType, $validMakingChargeTypes, true)) {
        $makingChargeType = 'fixed';
    }

    if ($productCode === '') {
        $error = 'Product code is required.';
    } elseif ($categoryId <= 0) {
        $error = 'Please select category.';
    } elseif (!categoryBelongsToBusiness($conn, $categoryId, $businessId, $catHasBusinessId, $catHasIsActive)) {
        $error = 'Selected category is invalid or inactive.';
    } elseif ($productName === '') {
        $error = 'Product name is required.';
    } elseif (productCodeExistsForOther($conn, $productCode, $productId, $businessId, $prodHasBusinessId)) {
        $error = 'Product code already exists.';
    } elseif ($prodHasBarcode && $barcode !== '' && barcodeExistsForOther($conn, $barcode, $productId, $businessId, $prodHasBusinessId)) {
        $error = 'Barcode already exists.';
    } elseif (!is_numeric($grossWeight) || !is_numeric($lessWeight) || !is_numeric($netWeight)) {
        $error = 'Weight fields must be numeric.';
    } elseif (!is_numeric($makingCharge) || !is_numeric($wastagePercent) || !is_numeric($stoneCharge) || !is_numeric($purchaseRate) || !is_numeric($saleRate) || !is_numeric($minStockQty) || !is_numeric($currentStockQty)) {
        $error = 'Numeric fields contain invalid value.';
    } else {
        $newImagePath = $imagePath;

        if ($prodHasImagePath) {
            $upload = uploadProductImage('image');

            if (!$upload['ok']) {
                $error = $upload['error'];
            } elseif ($upload['path'] !== '') {
                $newImagePath = $upload['path'];
            }
        }

        if ($error === '') {
            $setParts = [];
            $types = '';
            $values = [];

            if ($prodHasCategoryId) {
                $setParts[] = 'category_id = ?';
                $types .= 'i';
                $values[] = $categoryId;
            }

            if ($prodHasProductCode) {
                $setParts[] = 'product_code = ?';
                $types .= 's';
                $values[] = $productCode;
            }

            if ($prodHasBarcode) {
                $setParts[] = 'barcode = ?';
                $types .= 's';

                /*
                 * Important:
                 * Empty barcode should be NULL, not empty string.
                 * This avoids duplicate entry errors for unique barcode indexes.
                 */
                $barcodeValue = ($barcode === '') ? null : $barcode;
                $values[] = $barcodeValue;
            }

            if ($prodHasProductName) {
                $setParts[] = 'product_name = ?';
                $types .= 's';
                $values[] = $productName;
            }

            if ($prodHasDesignName) {
                $setParts[] = 'design_name = ?';
                $types .= 's';
                $values[] = $designName;
            }

            if ($prodHasPurity) {
                $setParts[] = 'purity = ?';
                $types .= 's';
                $values[] = $purity;
            }

            if ($prodHasUnit) {
                $setParts[] = 'unit = ?';
                $types .= 's';
                $values[] = $unit;
            }

            if ($prodHasGrossWeight) {
                $setParts[] = 'gross_weight = ?';
                $types .= 'd';
                $values[] = (float)$grossWeight;
            }

            if ($prodHasLessWeight) {
                $setParts[] = 'less_weight = ?';
                $types .= 'd';
                $values[] = (float)$lessWeight;
            }

            if ($prodHasNetWeight) {
                $setParts[] = 'net_weight = ?';
                $types .= 'd';
                $values[] = (float)$netWeight;
            }

            if ($prodHasMakingChargeType) {
                $setParts[] = 'making_charge_type = ?';
                $types .= 's';
                $values[] = $makingChargeType;
            }

            if ($prodHasMakingCharge) {
                $setParts[] = 'making_charge = ?';
                $types .= 'd';
                $values[] = (float)$makingCharge;
            }

            if ($prodHasWastagePercent) {
                $setParts[] = 'wastage_percent = ?';
                $types .= 'd';
                $values[] = (float)$wastagePercent;
            }

            if ($prodHasStoneCharge) {
                $setParts[] = 'stone_charge = ?';
                $types .= 'd';
                $values[] = (float)$stoneCharge;
            }

            if ($prodHasPurchaseRate) {
                $setParts[] = 'purchase_rate = ?';
                $types .= 'd';
                $values[] = (float)$purchaseRate;
            }

            if ($prodHasSaleRate) {
                $setParts[] = 'sale_rate = ?';
                $types .= 'd';
                $values[] = (float)$saleRate;
            }

            if ($prodHasMinStockQty) {
                $setParts[] = 'min_stock_qty = ?';
                $types .= 'd';
                $values[] = (float)$minStockQty;
            }

            if ($prodHasCurrentStockQty) {
                $setParts[] = 'current_stock_qty = ?';
                $types .= 'd';
                $values[] = (float)$currentStockQty;
            }

            if ($prodHasImagePath) {
                $setParts[] = 'image_path = ?';
                $types .= 's';
                $values[] = $newImagePath;
            }

            if ($prodHasDescription) {
                $setParts[] = 'description = ?';
                $types .= 's';
                $values[] = $description;
            }

            if ($prodHasIsActive) {
                $setParts[] = 'is_active = ?';
                $types .= 'i';
                $values[] = $isActive;
            }

            if ($prodHasUpdatedAt) {
                $setParts[] = 'updated_at = NOW()';
            }

            if (empty($setParts)) {
                $error = 'No editable product columns found.';
            } else {
                $sql = "UPDATE products SET " . implode(', ', $setParts) . " WHERE id = ?";
                $types .= 'i';
                $values[] = $productId;

                if ($prodHasBusinessId) {
                    $sql .= " AND business_id = ?";
                    $types .= 'i';
                    $values[] = $businessId;
                }

                $sql .= " LIMIT 1";

                $stmt = $conn->prepare($sql);

                if (!$stmt) {
                    $error = 'Failed to prepare update query: ' . $conn->error;
                } else {
                    $bindValues = [];
                    $bindValues[] = $types;

                    for ($i = 0; $i < count($values); $i++) {
                        $bindValues[] = &$values[$i];
                    }

                    call_user_func_array([$stmt, 'bind_param'], $bindValues);

                    if ($stmt->execute()) {
                        addAuditLog(
                            $conn,
                            $businessId,
                            $userId,
                            'Products',
                            'Update',
                            $productId,
                            'Updated product ' . $productName
                        );

                        $stmt->close();

                        header('Location: products.php?msg=updated');
                        exit;
                    } else {
                        $error = 'Failed to update product: ' . $stmt->error;
                    }

                    $stmt->close();
                }
            }
        }
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

                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="product_id" value="<?php echo (int)$productId; ?>">

                    <div class="row">
                        <div class="col-xl-8">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title mb-4">Product Details</h4>

                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Category <span class="text-danger">*</span></label>
                                            <select name="category_id" class="form-select" required>
                                                <option value="">Select Category</option>
                                                <?php foreach ($categories as $cat): ?>
                                                    <option value="<?php echo (int)$cat['id']; ?>" <?php echo $categoryId === (int)$cat['id'] ? 'selected' : ''; ?>>
                                                        <?php echo h($cat['category_name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Product Code</label>
                                            <input type="text" name="product_code" class="form-control" value="<?php echo h($productCode); ?>" placeholder="Product code">
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Barcode</label>
                                            <input type="text" name="barcode" class="form-control" value="<?php echo h($barcode); ?>">
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Product Name <span class="text-danger">*</span></label>
                                            <input type="text" name="product_name" class="form-control" value="<?php echo h($productName); ?>" required>
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Design Name</label>
                                            <input type="text" name="design_name" class="form-control" value="<?php echo h($designName); ?>">
                                        </div>

                                        <div class="col-md-3 mb-3">
                                            <label class="form-label">Purity</label>
                                            <input type="text" name="purity" class="form-control" value="<?php echo h($purity); ?>">
                                        </div>

                                        <div class="col-md-3 mb-3">
                                            <label class="form-label">Unit</label>
                                            <input type="text" name="unit" class="form-control" value="<?php echo h($unit); ?>">
                                        </div>

                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Gross Weight</label>
                                            <input type="text" name="gross_weight" class="form-control" value="<?php echo h($grossWeight); ?>">
                                        </div>

                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Less Weight</label>
                                            <input type="text" name="less_weight" class="form-control" value="<?php echo h($lessWeight); ?>">
                                        </div>

                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Net Weight</label>
                                            <input type="text" name="net_weight" class="form-control" value="<?php echo h($netWeight); ?>">
                                        </div>

                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Making Charge Type</label>
                                            <select name="making_charge_type" class="form-select">
                                                <option value="fixed" <?php echo $makingChargeType === 'fixed' ? 'selected' : ''; ?>>Fixed</option>
                                                <option value="per_gram" <?php echo $makingChargeType === 'per_gram' ? 'selected' : ''; ?>>Per Gram</option>
                                                <option value="percentage" <?php echo $makingChargeType === 'percentage' ? 'selected' : ''; ?>>Percentage</option>
                                            </select>
                                        </div>

                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Making Charge</label>
                                            <input type="text" name="making_charge" class="form-control" value="<?php echo h($makingCharge); ?>">
                                        </div>

                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Wastage %</label>
                                            <input type="text" name="wastage_percent" class="form-control" value="<?php echo h($wastagePercent); ?>">
                                        </div>

                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Stone Charge</label>
                                            <input type="text" name="stone_charge" class="form-control" value="<?php echo h($stoneCharge); ?>">
                                        </div>

                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Purchase Rate</label>
                                            <input type="text" name="purchase_rate" class="form-control" value="<?php echo h($purchaseRate); ?>">
                                        </div>

                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Sale Rate</label>
                                            <input type="text" name="sale_rate" class="form-control" value="<?php echo h($saleRate); ?>">
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Min Stock Qty</label>
                                            <input type="text" name="min_stock_qty" class="form-control" value="<?php echo h($minStockQty); ?>">
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Current Stock Qty</label>
                                            <input type="text" name="current_stock_qty" class="form-control" value="<?php echo h($currentStockQty); ?>">
                                        </div>

                                        <div class="col-md-12 mb-3">
                                            <label class="form-label">Description</label>
                                            <textarea name="description" class="form-control" rows="4"><?php echo h($description); ?></textarea>
                                        </div>

                                        <div class="col-md-12 mb-3">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="is_active" id="is_active" value="1" <?php echo (int)$isActive === 1 ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="is_active">
                                                    Active Product
                                                </label>
                                            </div>
                                        </div>
                                    </div>

                                </div>
                            </div>
                        </div>

                        <div class="col-xl-4">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title mb-4">Product Image</h4>

                                    <?php if ($prodHasImagePath && !empty($imagePath)): ?>
                                        <div class="mb-3">
                                            <label class="form-label">Current Image</label>
                                            <div>
                                                <img src="<?php echo h($imagePath); ?>" alt="Product Image" class="img-thumbnail" style="max-width: 100%; max-height: 180px; object-fit: contain;">
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <div class="mb-3">
                                        <label class="form-label">Upload Image</label>
                                        <input type="file" name="image" class="form-control" accept=".jpg,.jpeg,.png,.webp,.gif">
                                        <small class="text-muted">Allowed: JPG, PNG, WEBP, GIF. Max 2MB.</small>
                                    </div>

                                    <div class="d-grid gap-2">
                                        <button type="submit" class="btn btn-primary waves-effect waves-light">
                                            Update Product
                                        </button>
                                        <a href="products.php" class="btn btn-secondary waves-effect">
                                            Back to Products
                                        </a>
                                    </div>

                                    <hr>

                                    <h5 class="mb-3">Notes</h5>
                                    <ul class="mb-0 ps-3">
                                        <li>Product code must be unique.</li>
                                        <li>Empty barcode will be saved as NULL to avoid duplicate errors.</li>
                                        <li>New image will replace the current image path.</li>
                                        <li>Current stock qty is editable from this page.</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>

            </div>
        </div>

        <?php include('includes/footer.php'); ?>
    </div>
</div>

<?php include('includes/rightbar.php'); ?>
<?php include('includes/scripts.php'); ?>

</body>
</html>