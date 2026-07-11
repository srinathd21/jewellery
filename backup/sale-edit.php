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

if (!function_exists('hasColumn')) {
    function hasColumn(mysqli $conn, string $table, string $column): bool
    {
        $table = $conn->real_escape_string($table);
        $column = $conn->real_escape_string($column);
        $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
        return $res && $res->num_rows > 0;
    }
}

function addAuditLogSafe(mysqli $conn, ?int $businessId, ?int $userId, string $module, string $action, int $refId, string $desc): void
{
    if (function_exists('addAuditLog')) {
        addAuditLog($conn, $businessId, $userId, $module, $action, $refId, $desc);
        return;
    }

    if (!tableExists($conn, 'audit_logs')) {
        return;
    }

    $sql = "INSERT INTO audit_logs (
                business_id, user_id, module_name, action_type, reference_id, description, ip_address, user_agent, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return;
    }

    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

    $stmt->bind_param('iississs', $businessId, $userId, $module, $action, $refId, $desc, $ip, $ua);
    $stmt->execute();
    $stmt->close();
}

function ensureProductStockRow(mysqli $conn, int $businessId, int $productId): bool
{
    $stmt = $conn->prepare("SELECT id FROM product_stock WHERE product_id = ? LIMIT 1");
    if (!$stmt) return false;

    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $res = $stmt->get_result();
    $exists = $res && $res->fetch_assoc();
    $stmt->close();

    if ($exists) return true;

    $stmt = $conn->prepare("
        INSERT INTO product_stock
        (business_id, product_id, opening_qty, opening_weight, in_qty, in_weight, out_qty, out_weight, closing_qty, closing_weight)
        VALUES (?, ?, 0, 0, 0, 0, 0, 0, 0, 0)
    ");
    if (!$stmt) return false;

    $stmt->bind_param('ii', $businessId, $productId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function generateCustomerCode(mysqli $conn, int $businessId): string
{
    $sql = "SELECT COUNT(*) AS cnt FROM customers WHERE business_id = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('i', $businessId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        $next = ((int)($row['cnt'] ?? 0)) + 1;
    } else {
        $next = rand(1, 9999);
    }
    return 'CUS' . date('ymd') . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

$pageTitle = 'Edit Sale';

/* ---------------- AUTH ---------------- */
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
if (!in_array($roleName, ['admin', 'manager', 'billing'], true)) {
    die('Access denied.');
}

/* ---------------- REQUIRED TABLES ---------------- */
$requiredTables = ['sales', 'sale_items', 'products', 'customers', 'payment_methods', 'product_categories'];
foreach ($requiredTables as $tbl) {
    if (!tableExists($conn, $tbl)) {
        die('Required table `' . h($tbl) . '` not found.');
    }
}

/* ---------------- COLUMN CHECKS ---------------- */
$hasProductStockTable = tableExists($conn, 'product_stock');
$hasStockMovementTable = tableExists($conn, 'stock_movements');
$hasSalePaymentsTable = tableExists($conn, 'sale_payments');

$productsHasBusinessId      = hasColumn($conn, 'products', 'business_id');
$productsHasCategoryId      = hasColumn($conn, 'products', 'category_id');
$productsHasCode            = hasColumn($conn, 'products', 'product_code');
$productsHasBarcode         = hasColumn($conn, 'products', 'barcode');
$productsHasName            = hasColumn($conn, 'products', 'product_name');
$productsHasDesignName      = hasColumn($conn, 'products', 'design_name');
$productsHasPurity          = hasColumn($conn, 'products', 'purity');
$productsHasUnit            = hasColumn($conn, 'products', 'unit');
$productsHasGrossWeight     = hasColumn($conn, 'products', 'gross_weight');
$productsHasLessWeight      = hasColumn($conn, 'products', 'less_weight');
$productsHasNetWeight       = hasColumn($conn, 'products', 'net_weight');
$productsHasMakingType      = hasColumn($conn, 'products', 'making_charge_type');
$productsHasMakingCharge    = hasColumn($conn, 'products', 'making_charge');
$productsHasWastagePercent  = hasColumn($conn, 'products', 'wastage_percent');
$productsHasStoneCharge     = hasColumn($conn, 'products', 'stone_charge');
$productsHasSaleRate        = hasColumn($conn, 'products', 'sale_rate');
$productsHasCurrentStockQty = hasColumn($conn, 'products', 'current_stock_qty');
$productsHasIsActive        = hasColumn($conn, 'products', 'is_active');

$categoriesHasName          = hasColumn($conn, 'product_categories', 'category_name');
$categoriesHasHsn           = hasColumn($conn, 'product_categories', 'hsn_code');
$categoriesHasGst           = hasColumn($conn, 'product_categories', 'gst_percent');

$customersHasBusinessId     = hasColumn($conn, 'customers', 'business_id');
$customersHasCode           = hasColumn($conn, 'customers', 'customer_code');
$customersHasName           = hasColumn($conn, 'customers', 'customer_name');
$customersHasMobile         = hasColumn($conn, 'customers', 'mobile');
$customersHasGstin          = hasColumn($conn, 'customers', 'gstin');
$customersHasIsActive       = hasColumn($conn, 'customers', 'is_active');

$salesHasBusinessId         = hasColumn($conn, 'sales', 'business_id');
$salesHasBillNo             = hasColumn($conn, 'sales', 'bill_no');
$salesHasBillDate           = hasColumn($conn, 'sales', 'bill_date');
$salesHasBillTime           = hasColumn($conn, 'sales', 'bill_time');
$salesHasCustomerId         = hasColumn($conn, 'sales', 'customer_id');
$salesHasCustomerName       = hasColumn($conn, 'sales', 'customer_name');
$salesHasCustomerMobile     = hasColumn($conn, 'sales', 'customer_mobile');
$salesHasBillType           = hasColumn($conn, 'sales', 'bill_type');
$salesHasPaymentMethodId    = hasColumn($conn, 'sales', 'payment_method_id');
$salesHasPaymentReference   = hasColumn($conn, 'sales', 'payment_reference');
$salesHasSubtotal           = hasColumn($conn, 'sales', 'subtotal');
$salesHasDiscountAmount     = hasColumn($conn, 'sales', 'discount_amount');
$salesHasTaxableAmount      = hasColumn($conn, 'sales', 'taxable_amount');
$salesHasCgstAmount         = hasColumn($conn, 'sales', 'cgst_amount');
$salesHasSgstAmount         = hasColumn($conn, 'sales', 'sgst_amount');
$salesHasIgstAmount         = hasColumn($conn, 'sales', 'igst_amount');
$salesHasRoundOff           = hasColumn($conn, 'sales', 'round_off');
$salesHasGrandTotal         = hasColumn($conn, 'sales', 'grand_total');
$salesHasPaidAmount         = hasColumn($conn, 'sales', 'paid_amount');
$salesHasBalanceAmount      = hasColumn($conn, 'sales', 'balance_amount');
$salesHasPaymentStatus      = hasColumn($conn, 'sales', 'payment_status');
$salesHasNotes              = hasColumn($conn, 'sales', 'notes');
$salesHasStatus             = hasColumn($conn, 'sales', 'status');

$salePaymentsHasBusinessId       = $hasSalePaymentsTable && hasColumn($conn, 'sale_payments', 'business_id');
$salePaymentsHasSaleId           = $hasSalePaymentsTable && hasColumn($conn, 'sale_payments', 'sale_id');
$salePaymentsHasMethodId         = $hasSalePaymentsTable && hasColumn($conn, 'sale_payments', 'payment_method_id');
$salePaymentsHasAmount           = $hasSalePaymentsTable && hasColumn($conn, 'sale_payments', 'amount');
$salePaymentsHasReference        = $hasSalePaymentsTable && hasColumn($conn, 'sale_payments', 'reference_no');
$salePaymentsHasCreatedBy        = $hasSalePaymentsTable && hasColumn($conn, 'sale_payments', 'created_by');

$productStockHasProductId        = $hasProductStockTable && hasColumn($conn, 'product_stock', 'product_id');
$stockMovementsHasRefTable       = $hasStockMovementTable && hasColumn($conn, 'stock_movements', 'ref_table');
$stockMovementsHasRefId          = $hasStockMovementTable && hasColumn($conn, 'stock_movements', 'ref_id');

/* ---------------- SALE ID ---------------- */
$saleId = isset($_GET['id']) ? (int)$_GET['id'] : (int)($_POST['sale_id'] ?? 0);
if ($saleId <= 0) {
    die('Invalid sale ID.');
}

/* ---------------- CUSTOMERS ---------------- */
$customers = [];
$sql = "SELECT id";
if ($customersHasCode)   $sql .= ", customer_code";
if ($customersHasName)   $sql .= ", customer_name";
if ($customersHasMobile) $sql .= ", mobile";
if ($customersHasGstin)  $sql .= ", gstin";
$sql .= " FROM customers WHERE 1=1";
$params = [];
$types = '';

if ($customersHasBusinessId) {
    $sql .= " AND business_id = ?";
    $params[] = $businessId;
    $types .= 'i';
}
if ($customersHasIsActive) {
    $sql .= " AND is_active = 1";
}
$sql .= " ORDER BY customer_name ASC";

$stmt = $conn->prepare($sql);
if ($stmt) {
    if (!empty($params)) {
        $bind = [];
        $bind[] = $types;
        for ($i = 0; $i < count($params); $i++) $bind[] = &$params[$i];
        call_user_func_array([$stmt, 'bind_param'], $bind);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && $row = $res->fetch_assoc()) {
        $customers[] = $row;
    }
    $stmt->close();
}

/* ---------------- PRODUCTS ---------------- */
$products = [];
$sql = "
    SELECT
        p.id,
        " . ($productsHasCode ? "p.product_code," : "'' AS product_code,") . "
        " . ($productsHasBarcode ? "p.barcode," : "'' AS barcode,") . "
        " . ($productsHasName ? "p.product_name," : "'' AS product_name,") . "
        " . ($productsHasDesignName ? "p.design_name," : "'' AS design_name,") . "
        " . ($productsHasPurity ? "p.purity," : "'925' AS purity,") . "
        " . ($productsHasUnit ? "p.unit," : "'pcs' AS unit,") . "
        " . ($productsHasGrossWeight ? "IFNULL(p.gross_weight,0) AS gross_weight," : "0 AS gross_weight,") . "
        " . ($productsHasLessWeight ? "IFNULL(p.less_weight,0) AS less_weight," : "0 AS less_weight,") . "
        " . ($productsHasNetWeight ? "IFNULL(p.net_weight,0) AS net_weight," : "0 AS net_weight,") . "
        " . ($productsHasMakingType ? "p.making_charge_type," : "'fixed' AS making_charge_type,") . "
        " . ($productsHasMakingCharge ? "IFNULL(p.making_charge,0) AS making_charge," : "0 AS making_charge,") . "
        " . ($productsHasWastagePercent ? "IFNULL(p.wastage_percent,0) AS wastage_percent," : "0 AS wastage_percent,") . "
        " . ($productsHasStoneCharge ? "IFNULL(p.stone_charge,0) AS stone_charge," : "0 AS stone_charge,") . "
        " . ($productsHasSaleRate ? "IFNULL(p.sale_rate,0) AS sale_rate," : "0 AS sale_rate,") . "
        " . ($productsHasCurrentStockQty ? "IFNULL(p.current_stock_qty,0) AS current_stock_qty," : "0 AS current_stock_qty,") . "
        " . ($categoriesHasName ? "IFNULL(pc.category_name,'') AS category_name," : "'' AS category_name,") . "
        " . ($categoriesHasHsn ? "IFNULL(pc.hsn_code,'') AS hsn_code," : "'' AS hsn_code,") . "
        " . ($categoriesHasGst ? "IFNULL(pc.gst_percent,3) AS gst_percent" : "3 AS gst_percent") . "
    FROM products p
    LEFT JOIN product_categories pc ON pc.id = p.category_id
    WHERE 1=1
";
$params = [];
$types = '';
if ($productsHasBusinessId) {
    $sql .= " AND p.business_id = ?";
    $params[] = $businessId;
    $types .= 'i';
}
if ($productsHasIsActive) {
    $sql .= " AND p.is_active = 1";
}
$sql .= " ORDER BY p.product_name ASC";

$stmt = $conn->prepare($sql);
if ($stmt) {
    if (!empty($params)) {
        $bind = [];
        $bind[] = $types;
        for ($i = 0; $i < count($params); $i++) $bind[] = &$params[$i];
        call_user_func_array([$stmt, 'bind_param'], $bind);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && $row = $res->fetch_assoc()) {
        $products[] = $row;
    }
    $stmt->close();
}

$productMap = [];
foreach ($products as $p) {
    $productMap[(int)$p['id']] = $p;
}

/* ---------------- PAYMENT METHODS ---------------- */
$paymentMethods = [];
$res = $conn->query("SELECT id, method_name FROM payment_methods WHERE is_active = 1 ORDER BY method_name ASC");
while ($res && $row = $res->fetch_assoc()) {
    $paymentMethods[] = $row;
}
$paymentMethodMap = [];
foreach ($paymentMethods as $pm) {
    $paymentMethodMap[(int)$pm['id']] = (string)$pm['method_name'];
}

/* ---------------- FETCH EXISTING SALE ---------------- */
$stmt = $conn->prepare("
    SELECT *
    FROM sales
    WHERE id = ? " . ($salesHasBusinessId ? "AND business_id = ?" : "") . "
    LIMIT 1
");
if (!$stmt) {
    die('Failed to prepare sale query.');
}
if ($salesHasBusinessId) {
    $stmt->bind_param('ii', $saleId, $businessId);
} else {
    $stmt->bind_param('i', $saleId);
}
$stmt->execute();
$res = $stmt->get_result();
$existingSale = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$existingSale) {
    die('Sale not found.');
}

if ($salesHasStatus && isset($existingSale['status']) && $existingSale['status'] === 'Cancelled') {
    die('Cancelled sale cannot be edited.');
}

/* ---------------- FETCH EXISTING ITEMS ---------------- */
$existingItems = [];
$stmt = $conn->prepare("
    SELECT *
    FROM sale_items
    WHERE sale_id = ? " . (hasColumn($conn, 'sale_items', 'business_id') ? "AND business_id = ?" : "") . "
    ORDER BY id ASC
");
if ($stmt) {
    if (hasColumn($conn, 'sale_items', 'business_id')) {
        $stmt->bind_param('ii', $saleId, $businessId);
    } else {
        $stmt->bind_param('i', $saleId);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && $row = $res->fetch_assoc()) {
        $existingItems[] = $row;
    }
    $stmt->close();
}

/* ---------------- FETCH EXISTING SPLIT PAYMENTS ---------------- */
$existingSplitPayments = [];
if ($hasSalePaymentsTable && $salePaymentsHasSaleId) {
    $sql = "SELECT * FROM sale_payments WHERE sale_id = ?";
    if ($salePaymentsHasBusinessId) {
        $sql .= " AND business_id = ?";
    }
    $sql .= " ORDER BY id ASC";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if ($salePaymentsHasBusinessId) {
            $stmt->bind_param('ii', $saleId, $businessId);
        } else {
            $stmt->bind_param('i', $saleId);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && $row = $res->fetch_assoc()) {
            $existingSplitPayments[] = $row;
        }
        $stmt->close();
    }
}

/* ---------------- DEFAULT FORM DATA ---------------- */
$success = '';
$error = '';

$billDate = (string)($existingSale['bill_date'] ?? date('Y-m-d'));
$billTime = (string)(isset($existingSale['bill_time']) ? substr((string)$existingSale['bill_time'], 0, 5) : date('H:i'));
$billType = (string)($existingSale['bill_type'] ?? 'Retail');
$customerId = (int)($existingSale['customer_id'] ?? 0);
$overallDiscount = (float)($existingSale['discount_amount'] ?? 0);
$roundOff = (float)($existingSale['round_off'] ?? 0);
$paidAmountInput = (float)($existingSale['paid_amount'] ?? 0);
$notes = trim((string)($existingSale['notes'] ?? ''));

$quickCustomerName = '';
$quickCustomerMobile = '';
$quickCustomerGstin = '';

$itemProductIds = [];
$itemQtys = [];
$itemRates = [];
$itemMakingCharges = [];
$itemStoneCharges = [];
$itemOtherCharges = [];
$itemDiscounts = [];

foreach ($existingItems as $it) {
    $itemProductIds[] = (int)($it['product_id'] ?? 0);
    $itemQtys[] = (string)($it['qty'] ?? 1);
    $itemRates[] = (string)($it['rate_per_gram'] ?? 0);
    $itemMakingCharges[] = (string)($it['making_charge'] ?? 0);
    $itemStoneCharges[] = (string)($it['stone_charge'] ?? 0);
    $itemOtherCharges[] = (string)($it['other_charge'] ?? 0);
    $itemDiscounts[] = (string)($it['discount_amount'] ?? 0);
}

if (empty($itemProductIds)) {
    $itemProductIds = [0];
    $itemQtys = ['1'];
    $itemRates = ['0'];
    $itemMakingCharges = ['0'];
    $itemStoneCharges = ['0'];
    $itemOtherCharges = ['0'];
    $itemDiscounts = ['0'];
}

$splitPaymentMethodIds = [];
$splitPaymentAmounts = [];
$splitPaymentRefs = [];

if (!empty($existingSplitPayments)) {
    foreach ($existingSplitPayments as $sp) {
        $splitPaymentMethodIds[] = (int)($sp['payment_method_id'] ?? 0);
        $splitPaymentAmounts[] = (string)($sp['amount'] ?? 0);
        $splitPaymentRefs[] = (string)($sp['reference_no'] ?? '');
    }
} else {
    $splitPaymentMethodIds = [(int)($existingSale['payment_method_id'] ?? 0)];
    $splitPaymentAmounts = [(string)($existingSale['paid_amount'] ?? 0)];
    $splitPaymentRefs = [(string)($existingSale['payment_reference'] ?? '')];
}

/* ---------------- HANDLE UPDATE ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_sale'])) {
    $billDate = trim((string)($_POST['bill_date'] ?? date('Y-m-d')));
    $billTime = trim((string)($_POST['bill_time'] ?? date('H:i')));
    $billType = trim((string)($_POST['bill_type'] ?? 'Retail'));
    $customerId = (int)($_POST['customer_id'] ?? 0);
    $overallDiscount = (float)($_POST['overall_discount'] ?? 0);
    $roundOff = (float)($_POST['round_off'] ?? 0);
    $paidAmountInput = (float)($_POST['paid_amount'] ?? 0);
    $notes = trim((string)($_POST['notes'] ?? ''));

    $quickCustomerName = trim((string)($_POST['quick_customer_name'] ?? ''));
    $quickCustomerMobile = trim((string)($_POST['quick_customer_mobile'] ?? ''));
    $quickCustomerGstin = trim((string)($_POST['quick_customer_gstin'] ?? ''));

    $itemProductIds = $_POST['product_id'] ?? [];
    $itemQtys = $_POST['qty'] ?? [];
    $itemRates = $_POST['rate_per_gram'] ?? [];
    $itemMakingCharges = $_POST['making_charge'] ?? [];
    $itemStoneCharges = $_POST['stone_charge'] ?? [];
    $itemOtherCharges = $_POST['other_charge'] ?? [];
    $itemDiscounts = $_POST['item_discount'] ?? [];

    $splitPaymentMethodIds = $_POST['split_payment_method_id'] ?? [];
    $splitPaymentAmounts = $_POST['split_payment_amount'] ?? [];
    $splitPaymentRefs = $_POST['split_payment_reference'] ?? [];

    if ($customerId <= 0 && $quickCustomerName === '') {
        $error = 'Please select customer or enter quick customer name.';
    } elseif (empty($itemProductIds)) {
        $error = 'Please add at least one product.';
    } else {
        /* reverse old sale quantities to calculate available stock */
        $oldQtyMap = [];
        foreach ($existingItems as $oldItem) {
            $pid = (int)($oldItem['product_id'] ?? 0);
            $qty = (float)($oldItem['qty'] ?? 0);
            if ($pid > 0) {
                if (!isset($oldQtyMap[$pid])) $oldQtyMap[$pid] = 0;
                $oldQtyMap[$pid] += $qty;
            }
        }

        $lineItems = [];
        $subtotal = 0.0;
        $discountAmount = 0.0;
        $taxableAmount = 0.0;
        $cgstAmount = 0.0;
        $sgstAmount = 0.0;
        $igstAmount = 0.0;

        for ($i = 0; $i < count($itemProductIds); $i++) {
            $pid = (int)($itemProductIds[$i] ?? 0);
            $qty = (float)($itemQtys[$i] ?? 0);
            $ratePerGram = (float)($itemRates[$i] ?? 0);
            $makingCharge = (float)($itemMakingCharges[$i] ?? 0);
            $stoneCharge = (float)($itemStoneCharges[$i] ?? 0);
            $otherCharge = (float)($itemOtherCharges[$i] ?? 0);
            $itemDiscount = (float)($itemDiscounts[$i] ?? 0);

            if ($pid <= 0 || $qty <= 0) {
                continue;
            }

            if (!isset($productMap[$pid])) {
                $error = 'Invalid product selected.';
                break;
            }

            $prod = $productMap[$pid];
            $currentStock = (float)($prod['current_stock_qty'] ?? 0);
            $availableStock = $currentStock + (float)($oldQtyMap[$pid] ?? 0);

            if ($qty > $availableStock) {
                $error = 'Not enough stock for product: ' . (string)($prod['product_name'] ?? '');
                break;
            }

            $grossWeight = (float)($prod['gross_weight'] ?? 0) * $qty;
            $lessWeight = (float)($prod['less_weight'] ?? 0) * $qty;
            $netWeight = (float)($prod['net_weight'] ?? 0) * $qty;

            if ($ratePerGram <= 0) {
                $ratePerGram = (float)($prod['sale_rate'] ?? 0);
            }

            if ($makingCharge < 0) $makingCharge = 0;

            $gstPercent = (float)($prod['gst_percent'] ?? 3);
            $metalValue = $netWeight > 0 ? ($netWeight * $ratePerGram) : ($qty * $ratePerGram);
            $wastagePercent = (float)($prod['wastage_percent'] ?? 0);
            $wastageAmount = ($metalValue * $wastagePercent) / 100;

            $rowSubtotal = $metalValue + $makingCharge + $wastageAmount + $stoneCharge + $otherCharge;
            $rowTaxable = max(0, $rowSubtotal - $itemDiscount);
            $rowGst = ($rowTaxable * $gstPercent) / 100;
            $rowCgst = $rowGst / 2;
            $rowSgst = $rowGst / 2;
            $rowTotal = $rowTaxable + $rowGst;

            $lineItems[] = [
                'product_id' => $pid,
                'product_code' => (string)($prod['product_code'] ?? ''),
                'barcode' => (string)($prod['barcode'] ?? ''),
                'item_name' => (string)($prod['product_name'] ?? ''),
                'category_name' => (string)($prod['category_name'] ?? ''),
                'purity' => (string)($prod['purity'] ?? '925'),
                'hsn_code' => (string)($prod['hsn_code'] ?? ''),
                'qty' => $qty,
                'gross_weight' => $grossWeight,
                'less_weight' => $lessWeight,
                'net_weight' => $netWeight,
                'rate_per_gram' => $ratePerGram,
                'metal_value' => $metalValue,
                'making_charge_type' => (string)($prod['making_charge_type'] ?? 'fixed'),
                'making_charge' => $makingCharge,
                'wastage_percent' => $wastagePercent,
                'wastage_amount' => $wastageAmount,
                'stone_charge' => $stoneCharge,
                'other_charge' => $otherCharge,
                'discount_amount' => $itemDiscount,
                'taxable_amount' => $rowTaxable,
                'gst_percent' => $gstPercent,
                'gst_amount' => $rowGst,
                'cgst_amount' => $rowCgst,
                'sgst_amount' => $rowSgst,
                'total_amount' => $rowTotal
            ];

            $subtotal += $rowSubtotal;
            $discountAmount += $itemDiscount;
            $taxableAmount += $rowTaxable;
            $cgstAmount += $rowCgst;
            $sgstAmount += $rowSgst;
        }

        if ($error === '' && empty($lineItems)) {
            $error = 'Please add valid products.';
        }

        if ($error === '') {
            if ($overallDiscount > 0) {
                $discountAmount += $overallDiscount;
                $taxableAmount = max(0, $taxableAmount - $overallDiscount);
            }

            $grandTotal = $taxableAmount + $cgstAmount + $sgstAmount + $igstAmount + $roundOff;

            $paidAmount = $paidAmountInput > 0 ? $paidAmountInput : 0;
            if ($paidAmount > $grandTotal) $paidAmount = $grandTotal;
            $balanceAmount = $grandTotal - $paidAmount;

            $paymentRows = [];
            $paymentSplitTotal = 0.0;

            for ($i = 0; $i < count($splitPaymentMethodIds); $i++) {
                $pmid = (int)($splitPaymentMethodIds[$i] ?? 0);
                $pamt = (float)($splitPaymentAmounts[$i] ?? 0);
                $pref = trim((string)($splitPaymentRefs[$i] ?? ''));

                if ($pmid <= 0 && $pamt <= 0 && $pref === '') continue;

                if ($pmid <= 0) {
                    $error = 'Please select payment method in all payment rows.';
                    break;
                }
                if ($pamt <= 0) {
                    $error = 'Payment split amount must be greater than 0.';
                    break;
                }

                $paymentRows[] = [
                    'payment_method_id' => $pmid,
                    'amount' => $pamt,
                    'reference' => $pref,
                    'method_name' => $paymentMethodMap[$pmid] ?? ('Method #' . $pmid)
                ];
                $paymentSplitTotal += $pamt;
            }

            if ($error === '') {
                if ($paidAmount > 0 && empty($paymentRows)) {
                    $error = 'Please add at least one payment split row.';
                } elseif ($paidAmount <= 0 && !empty($paymentRows)) {
                    $error = 'Paid amount is 0, so remove payment split rows or enter paid amount.';
                } elseif (abs($paymentSplitTotal - $paidAmount) > 0.01) {
                    $error = 'Split payment total must match Paid Amount.';
                }
            }

            $paymentStatus = 'Paid';
            if ($paidAmount <= 0) {
                $paymentStatus = 'Unpaid';
            } elseif ($balanceAmount > 0) {
                $paymentStatus = 'Partial';
            }

            $primaryPaymentMethodId = !empty($paymentRows) ? (int)$paymentRows[0]['payment_method_id'] : 0;

            $paymentReferenceText = '';
            if (!empty($paymentRows)) {
                $parts = [];
                foreach ($paymentRows as $prow) {
                    $line = $prow['method_name'] . ' - ₹' . number_format((float)$prow['amount'], 2);
                    if ($prow['reference'] !== '') {
                        $line .= ' (' . $prow['reference'] . ')';
                    }
                    $parts[] = $line;
                }
                $paymentReferenceText = implode(' | ', $parts);
            }

            $conn->begin_transaction();

            try {
                if ($customerId <= 0 && $quickCustomerName !== '') {
                    $customerCode = generateCustomerCode($conn, $businessId);

                    $stmt = $conn->prepare("
                        INSERT INTO customers
                        (business_id, customer_code, customer_name, mobile, gstin, opening_balance, balance_type, notes, is_active, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, 0, 'Dr', '', 1, NOW(), NOW())
                    ");
                    if (!$stmt) throw new Exception('Failed to prepare customer insert.');

                    $stmt->bind_param('issss', $businessId, $customerCode, $quickCustomerName, $quickCustomerMobile, $quickCustomerGstin);
                    if (!$stmt->execute()) {
                        $stmt->close();
                        throw new Exception('Failed to create quick customer.');
                    }
                    $customerId = (int)$stmt->insert_id;
                    $stmt->close();
                }

                $customerName = '';
                $customerMobile = '';
                if ($customerId > 0) {
                    $stmt = $conn->prepare("SELECT customer_name, mobile FROM customers WHERE id = ? LIMIT 1");
                    if ($stmt) {
                        $stmt->bind_param('i', $customerId);
                        $stmt->execute();
                        $res = $stmt->get_result();
                        $crow = $res ? $res->fetch_assoc() : null;
                        $stmt->close();
                        $customerName = (string)($crow['customer_name'] ?? '');
                        $customerMobile = (string)($crow['mobile'] ?? '');
                    }
                }

                /* reverse old stock first */
                foreach ($existingItems as $oldItem) {
                    $oldPid = (int)($oldItem['product_id'] ?? 0);
                    $oldQty = (float)($oldItem['qty'] ?? 0);
                    $oldNetWeight = (float)($oldItem['net_weight'] ?? 0);

                    if ($oldPid <= 0 || $oldQty <= 0) continue;

                    if ($productsHasCurrentStockQty) {
                        $stmt = $conn->prepare("
                            UPDATE products
                            SET current_stock_qty = IFNULL(current_stock_qty,0) + ?, updated_at = NOW()
                            WHERE id = ? AND business_id = ?
                            LIMIT 1
                        ");
                        if (!$stmt) throw new Exception('Failed to prepare reverse stock update.');
                        $stmt->bind_param('dii', $oldQty, $oldPid, $businessId);
                        if (!$stmt->execute()) {
                            $stmt->close();
                            throw new Exception('Failed to reverse old product stock.');
                        }
                        $stmt->close();
                    }

                    if ($hasProductStockTable && $productStockHasProductId) {
                        if (!ensureProductStockRow($conn, $businessId, $oldPid)) {
                            throw new Exception('Failed to ensure product_stock row.');
                        }

                        $stmt = $conn->prepare("
                            UPDATE product_stock
                            SET
                                out_qty = GREATEST(IFNULL(out_qty,0) - ?, 0),
                                out_weight = GREATEST(IFNULL(out_weight,0) - ?, 0),
                                closing_qty = IFNULL(closing_qty,0) + ?,
                                closing_weight = IFNULL(closing_weight,0) + ?,
                                updated_at = NOW()
                            WHERE product_id = ?
                            LIMIT 1
                        ");
                        if (!$stmt) throw new Exception('Failed to prepare reverse product_stock update.');
                        $stmt->bind_param('ddddi', $oldQty, $oldNetWeight, $oldQty, $oldNetWeight, $oldPid);
                        if (!$stmt->execute()) {
                            $stmt->close();
                            throw new Exception('Failed to reverse product_stock.');
                        }
                        $stmt->close();
                    }
                }

                /* delete old sale items */
                $stmt = $conn->prepare("DELETE FROM sale_items WHERE sale_id = ?" . (hasColumn($conn, 'sale_items', 'business_id') ? " AND business_id = ?" : ""));
                if (!$stmt) throw new Exception('Failed to prepare delete old items.');
                if (hasColumn($conn, 'sale_items', 'business_id')) {
                    $stmt->bind_param('ii', $saleId, $businessId);
                } else {
                    $stmt->bind_param('i', $saleId);
                }
                if (!$stmt->execute()) {
                    $stmt->close();
                    throw new Exception('Failed to delete old sale items.');
                }
                $stmt->close();

                /* delete old sale payment splits */
                if ($hasSalePaymentsTable && $salePaymentsHasSaleId) {
                    $sql = "DELETE FROM sale_payments WHERE sale_id = ?";
                    if ($salePaymentsHasBusinessId) $sql .= " AND business_id = ?";
                    $stmt = $conn->prepare($sql);
                    if ($stmt) {
                        if ($salePaymentsHasBusinessId) {
                            $stmt->bind_param('ii', $saleId, $businessId);
                        } else {
                            $stmt->bind_param('i', $saleId);
                        }
                        $stmt->execute();
                        $stmt->close();
                    }
                }

                /* delete old stock movements for this sale */
                if ($hasStockMovementTable && $stockMovementsHasRefTable && $stockMovementsHasRefId) {
                    $sql = "DELETE FROM stock_movements WHERE ref_table = 'sales' AND ref_id = ?";
                    if (hasColumn($conn, 'stock_movements', 'business_id')) {
                        $sql .= " AND business_id = ?";
                    }
                    $stmt = $conn->prepare($sql);
                    if ($stmt) {
                        if (hasColumn($conn, 'stock_movements', 'business_id')) {
                            $stmt->bind_param('ii', $saleId, $businessId);
                        } else {
                            $stmt->bind_param('i', $saleId);
                        }
                        $stmt->execute();
                        $stmt->close();
                    }
                }

                /* update sale */
                $stmt = $conn->prepare("
                    UPDATE sales SET
                        bill_date = ?,
                        bill_time = ?,
                        customer_id = ?,
                        customer_name = ?,
                        customer_mobile = ?,
                        bill_type = ?,
                        payment_method_id = ?,
                        payment_reference = ?,
                        subtotal = ?,
                        discount_amount = ?,
                        taxable_amount = ?,
                        cgst_amount = ?,
                        sgst_amount = ?,
                        igst_amount = ?,
                        round_off = ?,
                        grand_total = ?,
                        paid_amount = ?,
                        balance_amount = ?,
                        payment_status = ?,
                        notes = ?,
                        updated_at = NOW()
                    WHERE id = ? " . ($salesHasBusinessId ? "AND business_id = ?" : "") . "
                    LIMIT 1
                ");
                if (!$stmt) throw new Exception('Failed to prepare sales update.');

                if ($salesHasBusinessId) {
                    $stmt->bind_param(
                        'ssisssissdddddddddssii',
                        $billDate,
                        $billTime,
                        $customerId,
                        $customerName,
                        $customerMobile,
                        $billType,
                        $primaryPaymentMethodId,
                        $paymentReferenceText,
                        $subtotal,
                        $discountAmount,
                        $taxableAmount,
                        $cgstAmount,
                        $sgstAmount,
                        $igstAmount,
                        $roundOff,
                        $grandTotal,
                        $paidAmount,
                        $balanceAmount,
                        $paymentStatus,
                        $notes,
                        $saleId,
                        $businessId
                    );
                } else {
                    $stmt->bind_param(
                        'ssisssissdddddddddssi',
                        $billDate,
                        $billTime,
                        $customerId,
                        $customerName,
                        $customerMobile,
                        $billType,
                        $primaryPaymentMethodId,
                        $paymentReferenceText,
                        $subtotal,
                        $discountAmount,
                        $taxableAmount,
                        $cgstAmount,
                        $sgstAmount,
                        $igstAmount,
                        $roundOff,
                        $grandTotal,
                        $paidAmount,
                        $balanceAmount,
                        $paymentStatus,
                        $notes,
                        $saleId
                    );
                }

                if (!$stmt->execute()) {
                    $stmt->close();
                    throw new Exception('Failed to update sale.');
                }
                $stmt->close();

                /* insert new items and apply new stock */
                foreach ($lineItems as $item) {
                    $stmt = $conn->prepare("
                        INSERT INTO sale_items
                        (business_id, sale_id, product_id, product_code, barcode, item_name, category_name, purity, hsn_code, qty, gross_weight, less_weight, net_weight, rate_date, rate_per_gram, metal_value, making_charge_type, making_charge, wastage_percent, wastage_amount, stone_charge, other_charge, discount_amount, taxable_amount, gst_percent, gst_amount, total_amount)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    if (!$stmt) throw new Exception('Failed to prepare sale item insert.');

                    $stmt->bind_param(
                        'iiissssssddddddsdddddddddd',
                        $businessId,
                        $saleId,
                        $item['product_id'],
                        $item['product_code'],
                        $item['barcode'],
                        $item['item_name'],
                        $item['category_name'],
                        $item['purity'],
                        $item['hsn_code'],
                        $item['qty'],
                        $item['gross_weight'],
                        $item['less_weight'],
                        $item['net_weight'],
                        $item['rate_per_gram'],
                        $item['metal_value'],
                        $item['making_charge_type'],
                        $item['making_charge'],
                        $item['wastage_percent'],
                        $item['wastage_amount'],
                        $item['stone_charge'],
                        $item['other_charge'],
                        $item['discount_amount'],
                        $item['taxable_amount'],
                        $item['gst_percent'],
                        $item['gst_amount'],
                        $item['total_amount']
                    );

                    if (!$stmt->execute()) {
                        $stmt->close();
                        throw new Exception('Failed to save sale item.');
                    }
                    $stmt->close();

                    if ($productsHasCurrentStockQty) {
                        $stmt = $conn->prepare("
                            UPDATE products
                            SET current_stock_qty = GREATEST(IFNULL(current_stock_qty,0) - ?, 0), updated_at = NOW()
                            WHERE id = ? AND business_id = ?
                            LIMIT 1
                        ");
                        if (!$stmt) throw new Exception('Failed to prepare product stock update.');
                        $stmt->bind_param('dii', $item['qty'], $item['product_id'], $businessId);
                        if (!$stmt->execute()) {
                            $stmt->close();
                            throw new Exception('Failed to update product stock.');
                        }
                        $stmt->close();
                    }

                    if ($hasProductStockTable && $productStockHasProductId) {
                        if (!ensureProductStockRow($conn, $businessId, (int)$item['product_id'])) {
                            throw new Exception('Failed to create product_stock row.');
                        }

                        $stmt = $conn->prepare("
                            UPDATE product_stock
                            SET
                                out_qty = IFNULL(out_qty,0) + ?,
                                out_weight = IFNULL(out_weight,0) + ?,
                                closing_qty = GREATEST(IFNULL(closing_qty,0) - ?, 0),
                                closing_weight = GREATEST(IFNULL(closing_weight,0) - ?, 0),
                                updated_at = NOW()
                            WHERE product_id = ?
                            LIMIT 1
                        ");
                        if (!$stmt) throw new Exception('Failed to prepare product_stock update.');
                        $stmt->bind_param('ddddi', $item['qty'], $item['net_weight'], $item['qty'], $item['net_weight'], $item['product_id']);
                        if (!$stmt->execute()) {
                            $stmt->close();
                            throw new Exception('Failed to update product_stock.');
                        }
                        $stmt->close();
                    }

                    if ($hasStockMovementTable) {
                        $stmt = $conn->prepare("
                            INSERT INTO stock_movements
                            (business_id, movement_date, product_id, movement_type, ref_table, ref_id, qty_in, qty_out, weight_in, weight_out, remarks, created_by, created_at)
                            VALUES (?, NOW(), ?, 'Sale', 'sales', ?, 0, ?, 0, ?, ?, ?, NOW())
                        ");
                        if (!$stmt) throw new Exception('Failed to prepare stock movement insert.');

                        $remarksText = 'Edited sale bill ' . ($existingSale['bill_no'] ?? ('SALE-' . $saleId));
                        $stmt->bind_param(
                            'iiiddsi',
                            $businessId,
                            $item['product_id'],
                            $saleId,
                            $item['qty'],
                            $item['net_weight'],
                            $remarksText,
                            $userId
                        );
                        if (!$stmt->execute()) {
                            $stmt->close();
                            throw new Exception('Failed to insert stock movement.');
                        }
                        $stmt->close();
                    }
                }

                /* insert payment split rows again */
                if ($hasSalePaymentsTable && $salePaymentsHasSaleId && $salePaymentsHasMethodId && $salePaymentsHasAmount) {
                    foreach ($paymentRows as $prow) {
                        $insertCols = ['sale_id', 'payment_method_id', 'amount'];
                        $insertVals = ['?', '?', '?'];
                        $types = 'iid';
                        $values = [$saleId, $prow['payment_method_id'], $prow['amount']];

                        if ($salePaymentsHasBusinessId) {
                            $insertCols[] = 'business_id';
                            $insertVals[] = '?';
                            $types .= 'i';
                            $values[] = $businessId;
                        }
                        if ($salePaymentsHasReference) {
                            $insertCols[] = 'reference_no';
                            $insertVals[] = '?';
                            $types .= 's';
                            $values[] = $prow['reference'];
                        }
                        if ($salePaymentsHasCreatedBy) {
                            $insertCols[] = 'created_by';
                            $insertVals[] = '?';
                            $types .= 'i';
                            $values[] = $userId;
                        }
                        if (hasColumn($conn, 'sale_payments', 'created_at')) {
                            $insertCols[] = 'created_at';
                            $insertVals[] = 'NOW()';
                        }

                        $sqlPay = "INSERT INTO sale_payments (" . implode(', ', $insertCols) . ") VALUES (" . implode(', ', $insertVals) . ")";
                        $stmt = $conn->prepare($sqlPay);
                        if (!$stmt) throw new Exception('Failed to prepare sale payment insert.');

                        $bind = [];
                        $bind[] = $types;
                        foreach ($values as $k => $v) $bind[] = &$values[$k];
                        call_user_func_array([$stmt, 'bind_param'], $bind);

                        if (!$stmt->execute()) {
                            $stmt->close();
                            throw new Exception('Failed to save payment split.');
                        }
                        $stmt->close();
                    }
                }

                addAuditLogSafe($conn, $businessId, $userId, 'Sales', 'Update', $saleId, 'Updated sale');

                $conn->commit();
                header('Location: sale-view.php?id=' . $saleId . '&msg=updated');
                exit;
            } catch (Throwable $e) {
                $conn->rollback();
                $error = 'Error: ' . $e->getMessage();
            }
        }
    }
}

$paymentRowCount = max(1, count($splitPaymentMethodIds));
?>
<!doctype html>
<html lang="en">
<?php include('includes/head.php'); ?>

<body class="billing-page-only">

<style>
    html, body {
        margin: 0;
        padding: 0;
        background: #f5f7fb;
        overflow-x: hidden;
        font-size: 13px;
    }
    .billing-page-wrapper { min-height: 100vh; padding: 8px; }
    .billing-header {
        display: flex; justify-content: space-between; align-items: center;
        gap: 8px; margin-bottom: 8px;
    }
    .billing-title {
        margin: 0; font-size: 20px; font-weight: 700; color: #1f2937; line-height: 1.1;
    }
    .billing-close-btn { min-width: 90px; padding: 6px 10px; font-size: 12px; }
    .card { border: 0; box-shadow: 0 2px 10px rgba(18, 38, 63, 0.05); margin-bottom: 10px; }
    .card-header { padding: 10px 12px; background: #fff; border-bottom: 1px solid #edf1f7; }
    .card-header h5 { font-size: 14px; font-weight: 700; margin: 0; }
    .card-body { padding: 12px; }
    .form-label { margin-bottom: 4px; font-size: 12px; font-weight: 600; color: #374151; }
    .form-control, .form-select { min-height: 34px; padding: 5px 10px; font-size: 12px; }
    .btn { font-size: 12px; padding: 6px 10px; }
    .btn-sm { padding: 4px 8px; font-size: 11px; }
    .alert { padding: 10px 12px; font-size: 12px; margin-bottom: 10px; }
    .sticky-summary { position: sticky; top: 8px; }
    .table { margin-bottom: 0; }
    .table > :not(caption) > * > * { vertical-align: middle; padding: 7px 8px; font-size: 12px; }
    .table thead th {
        white-space: nowrap; font-size: 11px; text-transform: uppercase;
        letter-spacing: .2px; background: #f8fafc; color: #475569;
    }
    .table td input, .table td select { min-height: 32px; padding: 4px 8px; font-size: 12px; }
    .compact-note { font-size: 11px; color: #6b7280; }
    .stock-info { display: block; margin-top: 4px; font-size: 10px; color: #6b7280; line-height: 1.3; }
    .summary-table th, .summary-table td { padding: 8px 8px !important; font-size: 12px; }
    .summary-table th { width: 55%; background: #fafafa; }
    .summary-highlight { background: #f8fafc; font-weight: 700; }
    .summary-total { font-size: 15px; font-weight: 800; color: #111827; }
    .badge { font-size: 11px; padding: 6px 8px; }
    .table-responsive { overflow-x: auto; }
    @media (max-width: 991.98px) {
        .sticky-summary { position: static; }
        .billing-title { font-size: 18px; }
        .billing-page-wrapper { padding: 6px; }
        .card-body, .card-header { padding: 10px; }
        .table > :not(caption) > * > * { padding: 6px; }
    }
</style>

<div class="billing-page-wrapper">
    <div class="container-fluid px-0">

        <div class="billing-header">
            <h1 class="billing-title">Edit Sale</h1>
            <div class="d-flex gap-2">
                <a href="sale-view.php?id=<?php echo (int)$saleId; ?>" class="btn btn-secondary billing-close-btn">Back</a>
                <button type="button" class="btn btn-danger billing-close-btn" onclick="window.location='sales.php'">Close</button>
            </div>
        </div>

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

        <form method="post" id="editSaleForm" autocomplete="off">
            <input type="hidden" name="sale_id" value="<?php echo (int)$saleId; ?>">

            <div class="row g-2">
                <div class="col-xl-9 col-lg-8">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Bill Details</h5>
                        </div>
                        <div class="card-body">
                            <div class="row g-2">
                                <div class="col-md-3">
                                    <label class="form-label">Bill No</label>
                                    <input type="text" class="form-control" value="<?php echo h($existingSale['bill_no'] ?? ('SALE-' . $saleId)); ?>" readonly>
                                </div>

                                <div class="col-md-3">
                                    <label class="form-label">Bill Date</label>
                                    <input type="date" name="bill_date" class="form-control" value="<?php echo h($billDate); ?>" required>
                                </div>

                                <div class="col-md-3">
                                    <label class="form-label">Bill Time</label>
                                    <input type="time" name="bill_time" class="form-control" value="<?php echo h($billTime); ?>" required>
                                </div>

                                <div class="col-md-3">
                                    <label class="form-label">Bill Type</label>
                                    <select name="bill_type" class="form-select">
                                        <option value="Retail" <?php echo $billType === 'Retail' ? 'selected' : ''; ?>>Retail</option>
                                        <option value="GST" <?php echo $billType === 'GST' ? 'selected' : ''; ?>>GST</option>
                                        <option value="Estimate" <?php echo $billType === 'Estimate' ? 'selected' : ''; ?>>Estimate</option>
                                        <option value="Exchange" <?php echo $billType === 'Exchange' ? 'selected' : ''; ?>>Exchange</option>
                                    </select>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Customer</label>
                                    <select name="customer_id" class="form-select">
                                        <option value="0">Select Customer</option>
                                        <?php foreach ($customers as $customer): ?>
                                            <option value="<?php echo (int)$customer['id']; ?>" <?php echo $customerId === (int)$customer['id'] ? 'selected' : ''; ?>>
                                                <?php
                                                echo h(
                                                    ($customer['customer_name'] ?? '')
                                                    . (!empty($customer['customer_code']) ? ' - ' . $customer['customer_code'] : '')
                                                    . (!empty($customer['mobile']) ? ' - ' . $customer['mobile'] : '')
                                                );
                                                ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Notes</label>
                                    <input type="text" name="notes" class="form-control" value="<?php echo h($notes); ?>" placeholder="Bill notes">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Quick Customer</h5>
                        </div>
                        <div class="card-body">
                            <div class="row g-2">
                                <div class="col-md-4">
                                    <label class="form-label">Customer Name</label>
                                    <input type="text" name="quick_customer_name" class="form-control" value="<?php echo h($quickCustomerName); ?>" placeholder="New customer name">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Mobile</label>
                                    <input type="text" name="quick_customer_mobile" class="form-control" value="<?php echo h($quickCustomerMobile); ?>" placeholder="Mobile number">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">GSTIN</label>
                                    <input type="text" name="quick_customer_gstin" class="form-control" value="<?php echo h($quickCustomerGstin); ?>" placeholder="GSTIN">
                                </div>
                            </div>
                            <small class="compact-note d-block mt-2">Use this only if customer is not already in the list.</small>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Bill Items</h5>
                            <button type="button" class="btn btn-sm btn-primary" onclick="addItemRow()">Add Item</button>
                        </div>
                        <div class="card-body p-2">
                            <div class="table-responsive">
                                <table class="table table-bordered align-middle" id="itemsTable">
                                    <thead>
                                        <tr>
                                            <th style="min-width:220px;">Product</th>
                                            <th style="width:80px;">Qty</th>
                                            <th style="width:100px;">Rate</th>
                                            <th style="width:95px;">Making</th>
                                            <th style="width:90px;">Stone</th>
                                            <th style="width:90px;">Other</th>
                                            <th style="width:95px;">Discount</th>
                                            <th style="width:120px;">Line Total</th>
                                            <th style="width:55px;">Act</th>
                                        </tr>
                                    </thead>
                                    <tbody id="itemsBody">
                                        <?php
                                        $existingRows = max(1, count($itemProductIds));
                                        for ($r = 0; $r < $existingRows; $r++):
                                        ?>
                                        <tr class="item-row">
                                            <td>
                                                <select name="product_id[]" class="form-select product-select">
                                                    <option value="0">Select Product</option>
                                                    <?php foreach ($products as $p): ?>
                                                        <option
                                                            value="<?php echo (int)$p['id']; ?>"
                                                            data-sale-rate="<?php echo h((string)($p['sale_rate'] ?? 0)); ?>"
                                                            data-making-charge="<?php echo h((string)($p['making_charge'] ?? 0)); ?>"
                                                            data-stone-charge="<?php echo h((string)($p['stone_charge'] ?? 0)); ?>"
                                                            data-stock="<?php echo h((string)($p['current_stock_qty'] ?? 0)); ?>"
                                                            data-name="<?php echo h((string)($p['product_name'] ?? '')); ?>"
                                                            data-net-weight="<?php echo h((string)($p['net_weight'] ?? 0)); ?>"
                                                            data-gst-percent="<?php echo h((string)($p['gst_percent'] ?? 3)); ?>"
                                                            data-wastage-percent="<?php echo h((string)($p['wastage_percent'] ?? 0)); ?>"
                                                            <?php echo ((int)($itemProductIds[$r] ?? 0) === (int)$p['id']) ? 'selected' : ''; ?>
                                                        >
                                                            <?php
                                                            echo h(
                                                                ($p['product_name'] ?? '')
                                                                . (!empty($p['product_code']) ? ' - ' . $p['product_code'] : '')
                                                                . ' (Stock: ' . number_format((float)($p['current_stock_qty'] ?? 0), 3) . ')'
                                                            );
                                                            ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <small class="stock-info"></small>
                                            </td>
                                            <td><input type="number" step="0.001" min="0.001" name="qty[]" class="form-control qty-input" value="<?php echo h($itemQtys[$r] ?? '1'); ?>"></td>
                                            <td><input type="number" step="0.01" min="0" name="rate_per_gram[]" class="form-control rate-input" value="<?php echo h($itemRates[$r] ?? '0'); ?>"></td>
                                            <td><input type="number" step="0.01" min="0" name="making_charge[]" class="form-control making-input" value="<?php echo h($itemMakingCharges[$r] ?? '0'); ?>"></td>
                                            <td><input type="number" step="0.01" min="0" name="stone_charge[]" class="form-control stone-input" value="<?php echo h($itemStoneCharges[$r] ?? '0'); ?>"></td>
                                            <td><input type="number" step="0.01" min="0" name="other_charge[]" class="form-control other-input" value="<?php echo h($itemOtherCharges[$r] ?? '0'); ?>"></td>
                                            <td><input type="number" step="0.01" min="0" name="item_discount[]" class="form-control discount-input" value="<?php echo h($itemDiscounts[$r] ?? '0'); ?>"></td>
                                            <td><input type="text" class="form-control line-total" readonly value="0.00"></td>
                                            <td><button type="button" class="btn btn-sm btn-danger" onclick="removeRow(this)">X</button></td>
                                        </tr>
                                        <?php endfor; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Split Payments</h5>
                            <button type="button" class="btn btn-sm btn-success" onclick="addPaymentRow()">Add Payment</button>
                        </div>
                        <div class="card-body p-2">
                            <div class="table-responsive">
                                <table class="table table-bordered align-middle" id="paymentsTable">
                                    <thead>
                                        <tr>
                                            <th style="min-width:170px;">Method</th>
                                            <th style="width:130px;">Amount</th>
                                            <th>Reference</th>
                                            <th style="width:55px;">Act</th>
                                        </tr>
                                    </thead>
                                    <tbody id="paymentsBody">
                                        <?php for ($p = 0; $p < $paymentRowCount; $p++): ?>
                                        <tr class="payment-row">
                                            <td>
                                                <select name="split_payment_method_id[]" class="form-select payment-method-select">
                                                    <option value="0">Select Method</option>
                                                    <?php foreach ($paymentMethods as $pm): ?>
                                                        <option value="<?php echo (int)$pm['id']; ?>" <?php echo ((int)($splitPaymentMethodIds[$p] ?? 0) === (int)$pm['id']) ? 'selected' : ''; ?>>
                                                            <?php echo h($pm['method_name']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td><input type="number" step="0.01" min="0" name="split_payment_amount[]" class="form-control payment-amount-input" value="<?php echo h($splitPaymentAmounts[$p] ?? '0'); ?>"></td>
                                            <td><input type="text" name="split_payment_reference[]" class="form-control payment-reference-input" value="<?php echo h($splitPaymentRefs[$p] ?? ''); ?>" placeholder="UPI / Txn / Ref"></td>
                                            <td><button type="button" class="btn btn-sm btn-danger" onclick="removePaymentRow(this)">X</button></td>
                                        </tr>
                                        <?php endfor; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="mt-2 text-end">
                                <strong>Split Total: ₹<span id="split_total">0.00</span></strong>
                            </div>
                            <small class="compact-note d-block mt-1">Example: Cash ₹5000 + UPI ₹3000 + Card ₹2000</small>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-lg-4">
                    <div class="card sticky-summary">
                        <div class="card-header">
                            <h5 class="mb-0">Bill Summary</h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-2">
                                <label class="form-label">Overall Discount</label>
                                <input type="number" step="0.01" min="0" name="overall_discount" id="overall_discount" class="form-control" value="<?php echo h((string)$overallDiscount); ?>">
                            </div>

                            <div class="mb-2">
                                <label class="form-label">Round Off</label>
                                <input type="number" step="0.01" name="round_off" id="round_off" class="form-control" value="<?php echo h((string)$roundOff); ?>">
                            </div>

                            <div class="mb-2">
                                <label class="form-label">Paid Amount</label>
                                <input type="number" step="0.01" min="0" name="paid_amount" id="paid_amount" class="form-control" value="<?php echo h((string)$paidAmountInput); ?>">
                            </div>

                            <table class="table table-bordered summary-table mb-0">
                                <tr><th>Subtotal</th><td class="text-end">₹<span id="sum_subtotal">0.00</span></td></tr>
                                <tr><th>Item Discount</th><td class="text-end">₹<span id="sum_item_discount">0.00</span></td></tr>
                                <tr><th>Overall Discount</th><td class="text-end">₹<span id="sum_overall_discount">0.00</span></td></tr>
                                <tr><th>Taxable</th><td class="text-end">₹<span id="sum_taxable">0.00</span></td></tr>
                                <tr><th>CGST</th><td class="text-end">₹<span id="sum_cgst">0.00</span></td></tr>
                                <tr><th>SGST</th><td class="text-end">₹<span id="sum_sgst">0.00</span></td></tr>
                                <tr><th>Round Off</th><td class="text-end">₹<span id="sum_roundoff">0.00</span></td></tr>
                                <tr><th>Split Total</th><td class="text-end">₹<span id="sum_split_total">0.00</span></td></tr>
                                <tr class="summary-highlight"><th>Grand Total</th><td class="text-end"><span class="summary-total">₹<span id="sum_grand_total">0.00</span></span></td></tr>
                                <tr><th>Paid Amount</th><td class="text-end">₹<span id="sum_paid">0.00</span></td></tr>
                                <tr><th>Balance</th><td class="text-end">₹<span id="sum_balance">0.00</span></td></tr>
                                <tr><th>Payment Status</th><td class="text-end"><span id="sum_payment_status" class="badge bg-success">Paid</span></td></tr>
                            </table>

                            <div class="mt-3 d-grid gap-2">
                                <button type="submit" name="update_sale" value="1" class="btn btn-primary">Update Sale</button>
                                <a href="sale-view.php?id=<?php echo (int)$saleId; ?>" class="btn btn-secondary">Back</a>
                                <button type="button" class="btn btn-danger" onclick="window.location='sales.php'">Close</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>

    </div>
</div>

<?php include('includes/scripts.php'); ?>

<script>
function parseNum(val) {
    const n = parseFloat(val);
    return isNaN(n) ? 0 : n;
}

function addItemRow() {
    const tbody = document.getElementById('itemsBody');
    const firstRow = tbody.querySelector('tr');
    if (!firstRow) return;

    const clone = firstRow.cloneNode(true);

    clone.querySelectorAll('select').forEach(function(select) {
        select.selectedIndex = 0;
    });

    clone.querySelectorAll('input').forEach(function(input) {
        if (input.classList.contains('qty-input')) {
            input.value = '1';
        } else if (input.classList.contains('line-total')) {
            input.value = '0.00';
        } else {
            input.value = '0';
        }
    });

    const stockInfo = clone.querySelector('.stock-info');
    if (stockInfo) stockInfo.textContent = '';

    tbody.appendChild(clone);
    calculateSummary();
}

function removeRow(btn) {
    const tbody = document.getElementById('itemsBody');
    if (tbody.querySelectorAll('tr').length <= 1) {
        alert('At least one row required.');
        return;
    }
    btn.closest('tr').remove();
    calculateSummary();
}

function fillProductDetails(selectEl) {
    const row = selectEl.closest('tr');
    const opt = selectEl.options[selectEl.selectedIndex];

    const rateInput = row.querySelector('.rate-input');
    const makingInput = row.querySelector('.making-input');
    const stoneInput = row.querySelector('.stone-input');
    const stockInfo = row.querySelector('.stock-info');

    if (rateInput) rateInput.value = opt.getAttribute('data-sale-rate') || '0';
    if (makingInput) makingInput.value = opt.getAttribute('data-making-charge') || '0';
    if (stoneInput) stoneInput.value = opt.getAttribute('data-stone-charge') || '0';

    if (stockInfo) {
        const stock = opt.getAttribute('data-stock') || '0';
        const netWt = opt.getAttribute('data-net-weight') || '0';
        const gst = opt.getAttribute('data-gst-percent') || '3';
        stockInfo.textContent = 'Stock: ' + stock + ' | Net Wt: ' + netWt + ' | GST: ' + gst + '%';
    }

    calculateRow(row);
}

function calculateRow(row) {
    const productSelect = row.querySelector('.product-select');
    if (!productSelect) return;

    const opt = productSelect.options[productSelect.selectedIndex];
    const qty = parseNum(row.querySelector('.qty-input').value);
    const rate = parseNum(row.querySelector('.rate-input').value);
    const making = parseNum(row.querySelector('.making-input').value);
    const stone = parseNum(row.querySelector('.stone-input').value);
    const other = parseNum(row.querySelector('.other-input').value);
    const discount = parseNum(row.querySelector('.discount-input').value);

    if (!productSelect.value || parseInt(productSelect.value, 10) <= 0 || qty <= 0) {
        row.querySelector('.line-total').value = '0.00';
        return;
    }

    const netWeight = parseNum(opt.getAttribute('data-net-weight'));
    const gstPercent = parseNum(opt.getAttribute('data-gst-percent')) || 3;
    const wastagePercent = parseNum(opt.getAttribute('data-wastage-percent'));

    let metalValue = 0;
    if (netWeight > 0) {
        metalValue = (netWeight * qty) * rate;
    } else {
        metalValue = qty * rate;
    }

    const wastageAmount = (metalValue * wastagePercent) / 100;
    const subtotal = metalValue + making + wastageAmount + stone + other;
    const taxable = Math.max(0, subtotal - discount);
    const gst = (taxable * gstPercent) / 100;
    const total = taxable + gst;

    row.querySelector('.line-total').value = total.toFixed(2);
}

function addPaymentRow() {
    const tbody = document.getElementById('paymentsBody');
    const firstRow = tbody.querySelector('tr');
    if (!firstRow) return;

    const clone = firstRow.cloneNode(true);

    clone.querySelectorAll('select').forEach(function(select) {
        select.selectedIndex = 0;
    });

    clone.querySelectorAll('input').forEach(function(input) {
        input.value = input.classList.contains('payment-reference-input') ? '' : '0';
    });

    tbody.appendChild(clone);
    calculatePaymentSplit();
}

function removePaymentRow(btn) {
    const tbody = document.getElementById('paymentsBody');
    if (tbody.querySelectorAll('tr').length <= 1) {
        const row = btn.closest('tr');
        row.querySelectorAll('select').forEach(function(select) {
            select.selectedIndex = 0;
        });
        row.querySelectorAll('input').forEach(function(input) {
            input.value = input.classList.contains('payment-reference-input') ? '' : '0';
        });
        calculatePaymentSplit();
        return;
    }

    btn.closest('tr').remove();
    calculatePaymentSplit();
}

function calculatePaymentSplit() {
    let splitTotal = 0;

    document.querySelectorAll('#paymentsBody tr').forEach(function(row) {
        const amount = parseNum(row.querySelector('.payment-amount-input').value);
        if (amount > 0) {
            splitTotal += amount;
        }
    });

    document.getElementById('split_total').textContent = splitTotal.toFixed(2);
    document.getElementById('sum_split_total').textContent = splitTotal.toFixed(2);

    const paidInput = document.getElementById('paid_amount');
    if (splitTotal > 0) {
        paidInput.value = splitTotal.toFixed(2);
    } else if (parseNum(paidInput.value) > 0) {
        paidInput.value = '0.00';
    }

    calculateSummary(false);
}

function calculateSummary(syncPaidWithSplit = true) {
    let subtotal = 0;
    let itemDiscount = 0;
    let taxable = 0;
    let cgst = 0;
    let sgst = 0;

    document.querySelectorAll('#itemsBody tr').forEach(function(row) {
        calculateRow(row);

        const productSelect = row.querySelector('.product-select');
        if (!productSelect || !productSelect.value || parseInt(productSelect.value, 10) <= 0) {
            return;
        }

        const opt = productSelect.options[productSelect.selectedIndex];
        const qty = parseNum(row.querySelector('.qty-input').value);
        const rate = parseNum(row.querySelector('.rate-input').value);
        const making = parseNum(row.querySelector('.making-input').value);
        const stone = parseNum(row.querySelector('.stone-input').value);
        const other = parseNum(row.querySelector('.other-input').value);
        const discount = parseNum(row.querySelector('.discount-input').value);

        if (qty <= 0) return;

        const netWeight = parseNum(opt.getAttribute('data-net-weight'));
        const gstPercent = parseNum(opt.getAttribute('data-gst-percent')) || 3;
        const wastagePercent = parseNum(opt.getAttribute('data-wastage-percent'));

        let metalValue = 0;
        if (netWeight > 0) {
            metalValue = (netWeight * qty) * rate;
        } else {
            metalValue = qty * rate;
        }

        const wastageAmount = (metalValue * wastagePercent) / 100;
        const rowSubtotal = metalValue + making + wastageAmount + stone + other;
        const rowTaxable = Math.max(0, rowSubtotal - discount);
        const rowGst = (rowTaxable * gstPercent) / 100;
        const rowCgst = rowGst / 2;
        const rowSgst = rowGst / 2;

        subtotal += rowSubtotal;
        itemDiscount += discount;
        taxable += rowTaxable;
        cgst += rowCgst;
        sgst += rowSgst;
    });

    const overallDiscount = parseNum(document.getElementById('overall_discount').value);
    const roundOff = parseNum(document.getElementById('round_off').value);

    taxable = Math.max(0, taxable - overallDiscount);

    let splitTotal = 0;
    document.querySelectorAll('#paymentsBody tr').forEach(function(row) {
        const amount = parseNum(row.querySelector('.payment-amount-input').value);
        if (amount > 0) {
            splitTotal += amount;
        }
    });

    let paidAmount = parseNum(document.getElementById('paid_amount').value);
    if (syncPaidWithSplit && splitTotal > 0) {
        paidAmount = splitTotal;
        document.getElementById('paid_amount').value = paidAmount.toFixed(2);
    }

    const grandTotal = taxable + cgst + sgst + roundOff;

    if (paidAmount > grandTotal) {
        paidAmount = grandTotal;
        document.getElementById('paid_amount').value = paidAmount.toFixed(2);
    }

    const balance = grandTotal - paidAmount;

    let statusText = 'Paid';
    let statusClass = 'bg-success';

    if (paidAmount <= 0) {
        statusText = 'Unpaid';
        statusClass = 'bg-danger';
    } else if (balance > 0.009) {
        statusText = 'Partial';
        statusClass = 'bg-warning text-dark';
    }

    document.getElementById('sum_subtotal').textContent = subtotal.toFixed(2);
    document.getElementById('sum_item_discount').textContent = itemDiscount.toFixed(2);
    document.getElementById('sum_overall_discount').textContent = overallDiscount.toFixed(2);
    document.getElementById('sum_taxable').textContent = taxable.toFixed(2);
    document.getElementById('sum_cgst').textContent = cgst.toFixed(2);
    document.getElementById('sum_sgst').textContent = sgst.toFixed(2);
    document.getElementById('sum_roundoff').textContent = roundOff.toFixed(2);
    document.getElementById('sum_grand_total').textContent = grandTotal.toFixed(2);
    document.getElementById('sum_paid').textContent = paidAmount.toFixed(2);
    document.getElementById('sum_balance').textContent = balance.toFixed(2);
    document.getElementById('split_total').textContent = splitTotal.toFixed(2);
    document.getElementById('sum_split_total').textContent = splitTotal.toFixed(2);

    const statusEl = document.getElementById('sum_payment_status');
    statusEl.textContent = statusText;
    statusEl.className = 'badge ' + statusClass;
}

document.addEventListener('change', function(e) {
    if (e.target.classList.contains('product-select')) {
        fillProductDetails(e.target);
        calculateSummary();
    }

    if (e.target.classList.contains('payment-method-select')) {
        calculatePaymentSplit();
    }
});

document.addEventListener('input', function(e) {
    if (
        e.target.classList.contains('qty-input') ||
        e.target.classList.contains('rate-input') ||
        e.target.classList.contains('making-input') ||
        e.target.classList.contains('stone-input') ||
        e.target.classList.contains('other-input') ||
        e.target.classList.contains('discount-input') ||
        e.target.id === 'overall_discount' ||
        e.target.id === 'round_off' ||
        e.target.id === 'paid_amount'
    ) {
        calculateSummary();
    }

    if (e.target.classList.contains('payment-amount-input')) {
        calculatePaymentSplit();
    }
});

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.product-select').forEach(function(selectEl) {
        if (selectEl.value && parseInt(selectEl.value, 10) > 0) {
            fillProductDetails(selectEl);
        }
    });

    calculatePaymentSplit();
    calculateSummary();
});
</script>

</body>
</html>