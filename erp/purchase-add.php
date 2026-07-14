<?php
session_start();
date_default_timezone_set('Asia/Kolkata');

$configCandidates = [
    __DIR__ . '/config/config.php',
    __DIR__ . '/config.php',
    __DIR__ . '/includes/config.php',
    __DIR__ . '/super-admin/includes/config.php',
];

$configLoaded = false;
foreach ($configCandidates as $configFile) {
    if (is_file($configFile)) {
        require_once $configFile;
        $configLoaded = true;
        break;
    }
}

if (!$configLoaded) {
    die('Database configuration file not found.');
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection is not available. Check the common config file.');
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

if (!function_exists('addAuditLog')) {
    function addAuditLog(mysqli $conn, ?int $businessId, ?int $userId, string $module, string $action, ?int $referenceId, string $description): void
    {
        if (!tableExists($conn, 'audit_logs')) {
            return;
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $stmt = $conn->prepare("
            INSERT INTO audit_logs (business_id, user_id, module_name, action_type, reference_id, description, ip_address, user_agent, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        if ($stmt) {
            $stmt->bind_param(
                'iississs',
                $businessId,
                $userId,
                $module,
                $action,
                $referenceId,
                $description,
                $ip,
                $ua
            );
            $stmt->execute();
            $stmt->close();
        }
    }
}

function moneyf($amount): string
{
    return number_format((float)$amount, 2, '.', '');
}

function qtyf($qty): string
{
    return number_format((float)$qty, 3, '.', '');
}

function generatePurchaseNo(mysqli $conn, int $businessId, bool $hasBusinessId): string
{
    $prefix = 'PUR' . date('Ymd');
    $lastNo = '';

    if ($hasBusinessId) {
        $stmt = $conn->prepare("SELECT purchase_no FROM purchases WHERE business_id = ? AND purchase_no LIKE ? ORDER BY id DESC LIMIT 1");
        $like = $prefix . '%';
        $stmt->bind_param('is', $businessId, $like);
    } else {
        $stmt = $conn->prepare("SELECT purchase_no FROM purchases WHERE purchase_no LIKE ? ORDER BY id DESC LIMIT 1");
        $like = $prefix . '%';
        $stmt->bind_param('s', $like);
    }

    if ($stmt) {
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $lastNo = (string)($row['purchase_no'] ?? '');
        $stmt->close();
    }

    $running = 1;
    if ($lastNo !== '' && preg_match('/(\d{4})$/', $lastNo, $m)) {
        $running = ((int)$m[1]) + 1;
    }

    return $prefix . str_pad((string)$running, 4, '0', STR_PAD_LEFT);
}

function getProductRow(mysqli $conn, int $productId, int $businessId, bool $prdHasBusinessId): ?array
{
    $sql = "SELECT p.* FROM products p WHERE p.id = ?";
    if ($prdHasBusinessId) {
        $sql .= " AND p.business_id = ?";
    }
    $sql .= " LIMIT 1";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }

    if ($prdHasBusinessId) {
        $stmt->bind_param('ii', $productId, $businessId);
    } else {
        $stmt->bind_param('i', $productId);
    }

    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    return $row ?: null;
}

$pageTitle = 'Add Purchase';

/* -------------------------------------------------------
   AUTH CHECK
------------------------------------------------------- */
if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    header('Location: ../login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? $_SESSION['default_branch_id'] ?? 0);

if ($businessId <= 0 || $branchId <= 0) {
    die('Business or branch session not found. Please login again.');
}

/* -------------------------------------------------------
   ROLE CHECK
   The new authentication flow loads the effective role into session.
------------------------------------------------------- */
$roleName = strtolower(trim((string)($_SESSION['role_name'] ?? '')));
$roleCode = strtolower(trim((string)($_SESSION['role_code'] ?? '')));
$userType = (string)($_SESSION['user_type'] ?? '');

$allowedByRole = (
    $userType === 'Platform Admin'
    || in_array($roleName, ['admin', 'business admin', 'manager', 'stock'], true)
    || in_array($roleCode, ['admin', 'business_admin', 'manager', 'stock'], true)
);

$allowedByPermission = false;
$sessionPermissions = $_SESSION['permissions'] ?? [];

foreach (['perm.purchases', 'perm.purchase'] as $permissionCode) {
    if (
        isset($sessionPermissions[$permissionCode])
        && (
            (int)($sessionPermissions[$permissionCode]['can_open'] ?? 0) === 1
            || (int)($sessionPermissions[$permissionCode]['can_create'] ?? 0) === 1
        )
    ) {
        $allowedByPermission = true;
        break;
    }
}

if (!$allowedByRole && !$allowedByPermission) {
    http_response_code(403);
    die('Access denied. You do not have permission to create purchases.');
}

/* -------------------------------------------------------
   REQUIRED TABLES
------------------------------------------------------- */
if (!tableExists($conn, 'purchases') || !tableExists($conn, 'purchase_items') || !tableExists($conn, 'suppliers')) {
    die('Required tables not found. Please import the SQL first.');
}

/* -------------------------------------------------------
   COLUMN CHECKS
------------------------------------------------------- */
$purHasBusinessId      = hasColumn($conn, 'purchases', 'business_id');
$pitHasBusinessId      = hasColumn($conn, 'purchase_items', 'business_id');
$supHasBusinessId      = hasColumn($conn, 'suppliers', 'business_id');
$prdHasBusinessId      = tableExists($conn, 'products') && hasColumn($conn, 'products', 'business_id');
$prdHasCurrentStockQty = tableExists($conn, 'products') && hasColumn($conn, 'products', 'current_stock_qty');

$productStockExists     = tableExists($conn, 'product_stock');
$stockMovementExists    = tableExists($conn, 'stock_movements');

$productStockHasBiz     = $productStockExists && hasColumn($conn, 'product_stock', 'business_id');
$productStockHasProduct = $productStockExists && hasColumn($conn, 'product_stock', 'product_id');
$productStockHasInQty   = $productStockExists && hasColumn($conn, 'product_stock', 'in_qty');
$productStockHasInWt    = $productStockExists && hasColumn($conn, 'product_stock', 'in_weight');
$productStockHasCloseQty= $productStockExists && hasColumn($conn, 'product_stock', 'closing_qty');
$productStockHasCloseWt = $productStockExists && hasColumn($conn, 'product_stock', 'closing_weight');

$stockMoveHasBiz        = $stockMovementExists && hasColumn($conn, 'stock_movements', 'business_id');
$stockMoveHasDate       = $stockMovementExists && hasColumn($conn, 'stock_movements', 'movement_date');
$stockMoveHasProductId  = $stockMovementExists && hasColumn($conn, 'stock_movements', 'product_id');
$stockMoveHasType       = $stockMovementExists && hasColumn($conn, 'stock_movements', 'movement_type');
$stockMoveHasRefTable   = $stockMovementExists && hasColumn($conn, 'stock_movements', 'ref_table');
$stockMoveHasRefId      = $stockMovementExists && hasColumn($conn, 'stock_movements', 'ref_id');
$stockMoveHasQtyIn      = $stockMovementExists && hasColumn($conn, 'stock_movements', 'qty_in');
$stockMoveHasWeightIn   = $stockMovementExists && hasColumn($conn, 'stock_movements', 'weight_in');
$stockMoveHasRemarks    = $stockMovementExists && hasColumn($conn, 'stock_movements', 'remarks');
$stockMoveHasCreatedBy  = $stockMovementExists && hasColumn($conn, 'stock_movements', 'created_by');

$paymentMethodExists    = tableExists($conn, 'payment_methods');

/* -------------------------------------------------------
   LOAD SUPPLIERS
------------------------------------------------------- */
$suppliers = [];
$sql = "SELECT id, supplier_name";
if (hasColumn($conn, 'suppliers', 'supplier_code')) {
    $sql .= ", supplier_code";
}
if (hasColumn($conn, 'suppliers', 'mobile')) {
    $sql .= ", mobile";
}
$sql .= " FROM suppliers WHERE 1=1";
if ($supHasBusinessId) {
    $sql .= " AND business_id = ?";
}
if (hasColumn($conn, 'suppliers', 'is_active')) {
    $sql .= " AND is_active = 1";
}
$sql .= " ORDER BY supplier_name ASC";

$stmt = $conn->prepare($sql);
if ($stmt) {
    if ($supHasBusinessId) {
        $stmt->bind_param('i', $businessId);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && $row = $res->fetch_assoc()) {
        $suppliers[] = $row;
    }
    $stmt->close();
}

/* -------------------------------------------------------
   LOAD PRODUCTS
------------------------------------------------------- */
$products = [];
if (tableExists($conn, 'products')) {
    $sql = "SELECT id, product_name, product_code";
    if (hasColumn($conn, 'products', 'barcode')) {
        $sql .= ", barcode";
    }
    if (hasColumn($conn, 'products', 'purity')) {
        $sql .= ", purity";
    }
    if (hasColumn($conn, 'products', 'purchase_rate')) {
        $sql .= ", purchase_rate";
    }
    if (hasColumn($conn, 'products', 'net_weight')) {
        $sql .= ", net_weight";
    }
    if (hasColumn($conn, 'products', 'category_id')) {
        $sql .= ", category_id";
    }
    $sql .= " FROM products WHERE 1=1";
    if ($prdHasBusinessId) {
        $sql .= " AND business_id = ?";
    }
    if (hasColumn($conn, 'products', 'is_active')) {
        $sql .= " AND is_active = 1";
    }
    $sql .= " ORDER BY product_name ASC";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if ($prdHasBusinessId) {
            $stmt->bind_param('i', $businessId);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && $row = $res->fetch_assoc()) {
            $products[] = $row;
        }
        $stmt->close();
    }
}

/* -------------------------------------------------------
   LOAD PAYMENT METHODS
------------------------------------------------------- */
$paymentMethods = [];
if ($paymentMethodExists) {
    $sql = "SELECT id, method_name FROM payment_methods WHERE 1=1";
    if (hasColumn($conn, 'payment_methods', 'is_active')) {
        $sql .= " AND is_active = 1";
    }
    $sql .= " ORDER BY id ASC";
    $res = $conn->query($sql);
    while ($res && $row = $res->fetch_assoc()) {
        $paymentMethods[] = $row;
    }
}

/* -------------------------------------------------------
   DEFAULT VALUES
------------------------------------------------------- */
$success = '';
$error = '';

$purchaseNo = generatePurchaseNo($conn, $businessId, $purHasBusinessId);
$purchaseDate = date('Y-m-d');
$supplierId = 0;
$invoiceNo = '';
$paymentMethodId = 0;
$notes = '';
$discountAmount = '0.00';
$roundOff = '0.00';
$paidAmount = '0.00';

$formItems = [
    [
        'product_id' => '',
        'item_name' => '',
        'purity' => '925',
        'hsn_code' => '',
        'qty' => '1.000',
        'gross_weight' => '0.000',
        'less_weight' => '0.000',
        'net_weight' => '0.000',
        'rate_per_gram' => '0.00',
        'making_charge' => '0.00',
        'stone_charge' => '0.00',
        'item_amount' => '0.00',
        'discount_amount' => '0.00',
        'taxable_amount' => '0.00',
        'gst_percent' => '3.00',
        'gst_amount' => '0.00',
        'total_amount' => '0.00'
    ]
];

/* -------------------------------------------------------
   SAVE
------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $purchaseNo = trim((string)($_POST['purchase_no'] ?? ''));
    $purchaseDate = trim((string)($_POST['purchase_date'] ?? ''));
    $supplierId = (int)($_POST['supplier_id'] ?? 0);
    $invoiceNo = trim((string)($_POST['invoice_no'] ?? ''));
    $paymentMethodId = (int)($_POST['payment_method_id'] ?? 0);
    $notes = trim((string)($_POST['notes'] ?? ''));
    $discountAmount = trim((string)($_POST['discount_amount'] ?? '0'));
    $roundOff = trim((string)($_POST['round_off'] ?? '0'));
    $paidAmount = trim((string)($_POST['paid_amount'] ?? '0'));

    if ($purchaseNo === '') {
        $purchaseNo = generatePurchaseNo($conn, $businessId, $purHasBusinessId);
    }

    $postedItems = $_POST['items'] ?? [];
    $cleanItems = [];
    $formItems = [];

    if ($purchaseDate === '') {
        $error = 'Purchase date is required.';
    } elseif ($supplierId <= 0) {
        $error = 'Please select supplier.';
    } elseif (!is_array($postedItems) || empty($postedItems)) {
        $error = 'Please add at least one item.';
    } elseif (!is_numeric($discountAmount) || !is_numeric($roundOff) || !is_numeric($paidAmount)) {
        $error = 'Invalid totals.';
    } else {
        foreach ($postedItems as $item) {
            $row = [
                'product_id'       => (int)($item['product_id'] ?? 0),
                'item_name'        => trim((string)($item['item_name'] ?? '')),
                'purity'           => trim((string)($item['purity'] ?? '925')),
                'hsn_code'         => trim((string)($item['hsn_code'] ?? '')),
                'qty'              => trim((string)($item['qty'] ?? '0')),
                'gross_weight'     => trim((string)($item['gross_weight'] ?? '0')),
                'less_weight'      => trim((string)($item['less_weight'] ?? '0')),
                'net_weight'       => trim((string)($item['net_weight'] ?? '0')),
                'rate_per_gram'    => trim((string)($item['rate_per_gram'] ?? '0')),
                'making_charge'    => trim((string)($item['making_charge'] ?? '0')),
                'stone_charge'     => trim((string)($item['stone_charge'] ?? '0')),
                'item_amount'      => '0.00',
                'discount_amount'  => trim((string)($item['discount_amount'] ?? '0')),
                'taxable_amount'   => '0.00',
                'gst_percent'      => trim((string)($item['gst_percent'] ?? '3')),
                'gst_amount'       => '0.00',
                'total_amount'     => '0.00'
            ];

            $blankRow = (
                $row['product_id'] <= 0 &&
                $row['item_name'] === '' &&
                (float)$row['qty'] <= 0 &&
                (float)$row['net_weight'] <= 0
            );

            if ($blankRow) {
                continue;
            }

            if ($row['item_name'] === '' && $row['product_id'] > 0) {
                $prd = getProductRow($conn, $row['product_id'], $businessId, $prdHasBusinessId);
                if ($prd) {
                    $row['item_name'] = (string)($prd['product_name'] ?? '');
                    if ($row['purity'] === '' && isset($prd['purity'])) {
                        $row['purity'] = (string)$prd['purity'];
                    }
                }
            }

            if (
                $row['item_name'] === '' ||
                !is_numeric($row['qty']) ||
                !is_numeric($row['gross_weight']) ||
                !is_numeric($row['less_weight']) ||
                !is_numeric($row['net_weight']) ||
                !is_numeric($row['rate_per_gram']) ||
                !is_numeric($row['making_charge']) ||
                !is_numeric($row['stone_charge']) ||
                !is_numeric($row['discount_amount']) ||
                !is_numeric($row['gst_percent'])
            ) {
                $error = 'Invalid item values found.';
                break;
            }

            $qty = (float)$row['qty'];
            $grossWeight = (float)$row['gross_weight'];
            $lessWeight = (float)$row['less_weight'];
            $netWeight = (float)$row['net_weight'];
            $ratePerGram = (float)$row['rate_per_gram'];
            $makingCharge = (float)$row['making_charge'];
            $stoneCharge = (float)$row['stone_charge'];
            $itemDiscount = (float)$row['discount_amount'];
            $gstPercent = (float)$row['gst_percent'];

            if ($qty <= 0) {
                $error = 'Item quantity must be greater than zero.';
                break;
            }

            $itemAmount = ($netWeight * $ratePerGram) + $makingCharge + $stoneCharge;
            $taxableAmount = $itemAmount - $itemDiscount;
            if ($taxableAmount < 0) {
                $taxableAmount = 0;
            }

            $gstAmount = ($taxableAmount * $gstPercent) / 100;
            $totalAmount = $taxableAmount + $gstAmount;

            $row['item_amount'] = moneyf($itemAmount);
            $row['taxable_amount'] = moneyf($taxableAmount);
            $row['gst_amount'] = moneyf($gstAmount);
            $row['total_amount'] = moneyf($totalAmount);

            $cleanItems[] = $row;
        }

        $formItems = !empty($cleanItems) ? $cleanItems : $formItems;

        if ($error === '' && empty($cleanItems)) {
            $error = 'Please add at least one valid item.';
        }

        if ($error === '') {
            $subtotal = 0.00;
            $taxableTotal = 0.00;
            $gstTotal = 0.00;

            foreach ($cleanItems as $row) {
                $subtotal += (float)$row['item_amount'];
                $taxableTotal += (float)$row['taxable_amount'];
                $gstTotal += (float)$row['gst_amount'];
            }

            $discountAmountF = (float)$discountAmount;
            $roundOffF = (float)$roundOff;
            $paidAmountF = (float)$paidAmount;

            $cgstAmount = round($gstTotal / 2, 2);
            $sgstAmount = round($gstTotal / 2, 2);
            $igstAmount = 0.00;
            $grandTotal = $taxableTotal + $gstTotal + $roundOffF;
            $balanceAmount = $grandTotal - $paidAmountF;
            if ($balanceAmount < 0) {
                $balanceAmount = 0;
            }

            $paymentStatus = 'Unpaid';
            if ($paidAmountF > 0 && $paidAmountF < $grandTotal) {
                $paymentStatus = 'Partial';
            } elseif ($paidAmountF >= $grandTotal && $grandTotal > 0) {
                $paymentStatus = 'Paid';
            }

            $conn->begin_transaction();

            try {
                $supplierInvoiceNo = $invoiceNo !== '' ? $invoiceNo : null;
                $workflowStatus = 'Posted';

                $stmt = $conn->prepare(
                    "INSERT INTO purchases
                    (business_id, branch_id, purchase_no, supplier_invoice_no,
                     purchase_date, supplier_id, subtotal, discount_amount,
                     taxable_amount, cgst_amount, sgst_amount, igst_amount,
                     grand_total, paid_amount, balance_amount, payment_status,
                     workflow_status, notes, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );

                if (!$stmt) {
                    throw new Exception('Failed to prepare purchase insert: ' . $conn->error);
                }

                $stmt->bind_param(
                    'iisssidddddddddsssi',
                    $businessId,
                    $branchId,
                    $purchaseNo,
                    $supplierInvoiceNo,
                    $purchaseDate,
                    $supplierId,
                    $subtotal,
                    $discountAmountF,
                    $taxableTotal,
                    $cgstAmount,
                    $sgstAmount,
                    $igstAmount,
                    $grandTotal,
                    $paidAmountF,
                    $balanceAmount,
                    $paymentStatus,
                    $workflowStatus,
                    $notes,
                    $userId
                );

                if (!$stmt->execute()) {
                    throw new Exception('Failed to save purchase: ' . $stmt->error);
                }

                $purchaseId = (int)$stmt->insert_id;
                $stmt->close();

                $itemStmt = $conn->prepare(
                    "INSERT INTO purchase_items
                    (business_id, branch_id, purchase_id, product_id, item_name,
                     quantity, gross_weight, net_weight, rate, tax_percent,
                     tax_amount, line_total)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );

                if (!$itemStmt) {
                    throw new Exception('Failed to prepare purchase item insert: ' . $conn->error);
                }

                $stockSelectStmt = $conn->prepare(
                    "SELECT id, quantity, gross_weight, net_weight, average_cost, stock_value
                     FROM product_stock
                     WHERE business_id = ? AND branch_id = ? AND product_id = ?
                     LIMIT 1 FOR UPDATE"
                );

                $stockUpdateStmt = $conn->prepare(
                    "UPDATE product_stock
                     SET quantity = ?, gross_weight = ?, net_weight = ?,
                         average_cost = ?, stock_value = ?
                     WHERE id = ?"
                );

                $stockInsertStmt = $conn->prepare(
                    "INSERT INTO product_stock
                    (business_id, branch_id, product_id, quantity, gross_weight,
                     net_weight, average_cost, stock_value)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                );

                $movementStmt = $conn->prepare(
                    "INSERT INTO stock_movements
                    (business_id, branch_id, product_id, movement_date, movement_type,
                     reference_table, reference_id, quantity_in, weight_in, rate,
                     value_amount, remarks, created_by)
                    VALUES (?, ?, ?, NOW(), 'Purchase', 'purchases', ?, ?, ?, ?, ?, ?, ?)"
                );

                foreach ($cleanItems as $row) {
                    $productIdValue = (int)$row['product_id'] > 0 ? (int)$row['product_id'] : null;
                    $quantityValue = (float)$row['qty'];
                    $grossWeightValue = (float)$row['gross_weight'];
                    $netWeightValue = (float)$row['net_weight'];
                    $rateValue = (float)$row['rate_per_gram'];
                    $taxPercentValue = (float)$row['gst_percent'];
                    $taxAmountValue = (float)$row['gst_amount'];
                    $lineTotalValue = (float)$row['total_amount'];
                    $itemNameValue = (string)$row['item_name'];

                    $itemStmt->bind_param(
                        'iiiisddddddd',
                        $businessId,
                        $branchId,
                        $purchaseId,
                        $productIdValue,
                        $itemNameValue,
                        $quantityValue,
                        $grossWeightValue,
                        $netWeightValue,
                        $rateValue,
                        $taxPercentValue,
                        $taxAmountValue,
                        $lineTotalValue
                    );

                    if (!$itemStmt->execute()) {
                        throw new Exception('Failed to save purchase item: ' . $itemStmt->error);
                    }

                    if ($productIdValue === null) {
                        continue;
                    }

                    if (!$stockSelectStmt || !$stockUpdateStmt || !$stockInsertStmt || !$movementStmt) {
                        throw new Exception('Failed to prepare stock statements: ' . $conn->error);
                    }

                    $stockSelectStmt->bind_param('iii', $businessId, $branchId, $productIdValue);
                    if (!$stockSelectStmt->execute()) {
                        throw new Exception('Failed to read product stock: ' . $stockSelectStmt->error);
                    }
                    $stockRow = $stockSelectStmt->get_result()->fetch_assoc();

                    $incomingValue = $lineTotalValue;

                    if ($stockRow) {
                        $newQty = (float)$stockRow['quantity'] + $quantityValue;
                        $newGross = (float)$stockRow['gross_weight'] + $grossWeightValue;
                        $newNet = (float)$stockRow['net_weight'] + $netWeightValue;
                        $newValue = (float)$stockRow['stock_value'] + $incomingValue;
                        $averageCost = $newQty > 0 ? $newValue / $newQty : 0;
                        $stockId = (int)$stockRow['id'];

                        $stockUpdateStmt->bind_param(
                            'dddddi',
                            $newQty,
                            $newGross,
                            $newNet,
                            $averageCost,
                            $newValue,
                            $stockId
                        );

                        if (!$stockUpdateStmt->execute()) {
                            throw new Exception('Failed to update product stock: ' . $stockUpdateStmt->error);
                        }
                    } else {
                        $averageCost = $quantityValue > 0 ? $incomingValue / $quantityValue : 0;

                        $stockInsertStmt->bind_param(
                            'iiiddddd',
                            $businessId,
                            $branchId,
                            $productIdValue,
                            $quantityValue,
                            $grossWeightValue,
                            $netWeightValue,
                            $averageCost,
                            $incomingValue
                        );

                        if (!$stockInsertStmt->execute()) {
                            throw new Exception('Failed to insert product stock: ' . $stockInsertStmt->error);
                        }
                    }

                    $remarks = 'Purchase ' . $purchaseNo;
                    $movementStmt->bind_param(
                        'iiiiddddsi',
                        $businessId,
                        $branchId,
                        $productIdValue,
                        $purchaseId,
                        $quantityValue,
                        $netWeightValue,
                        $rateValue,
                        $incomingValue,
                        $remarks,
                        $userId
                    );

                    if (!$movementStmt->execute()) {
                        throw new Exception('Failed to insert stock movement: ' . $movementStmt->error);
                    }
                }

                $itemStmt->close();
                if ($stockSelectStmt) $stockSelectStmt->close();
                if ($stockUpdateStmt) $stockUpdateStmt->close();
                if ($stockInsertStmt) $stockInsertStmt->close();
                if ($movementStmt) $movementStmt->close();

                if (hasColumn($conn, 'suppliers', 'current_balance')) {
                    $supplierBalanceStmt = $conn->prepare(
                        "UPDATE suppliers
                         SET current_balance = COALESCE(current_balance, 0) + ?
                         WHERE id = ? AND business_id = ?"
                    );
                    if (!$supplierBalanceStmt) {
                        throw new Exception('Failed to prepare supplier balance update: ' . $conn->error);
                    }
                    $supplierBalanceStmt->bind_param('dii', $balanceAmount, $supplierId, $businessId);
                    if (!$supplierBalanceStmt->execute()) {
                        throw new Exception('Failed to update supplier balance: ' . $supplierBalanceStmt->error);
                    }
                    $supplierBalanceStmt->close();
                }

                if (tableExists($conn, 'audit_logs')) {
                    $description = 'Created purchase ' . $purchaseNo;
                    $newValuesJson = json_encode([
                        'purchase_no' => $purchaseNo,
                        'supplier_id' => $supplierId,
                        'grand_total' => $grandTotal,
                        'paid_amount' => $paidAmountF,
                        'balance_amount' => $balanceAmount,
                        'payment_status' => $paymentStatus,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
                    $agent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

                    $auditStmt = $conn->prepare(
                        "INSERT INTO audit_logs
                        (business_id, branch_id, user_id, module_code, action_type,
                         reference_table, reference_id, description, new_values_json,
                         ip_address, user_agent)
                        VALUES (?, ?, ?, 'purchases', 'Create', 'purchases', ?, ?, ?, ?, ?)"
                    );
                    if ($auditStmt) {
                        $auditStmt->bind_param(
                            'iiiissss',
                            $businessId,
                            $branchId,
                            $userId,
                            $purchaseId,
                            $description,
                            $newValuesJson,
                            $ip,
                            $agent
                        );
                        $auditStmt->execute();
                        $auditStmt->close();
                    }
                }

                $conn->commit();
                header('Location: purchases.php?msg=created');
                exit;
            } catch (Throwable $e) {
                $conn->rollback();
                $error = $e->getMessage();
            }
        }
    }
}
?>

<?php
$theme = [
    'primary_color' => '#d89416',
    'primary_dark_color' => '#b86a0b',
    'primary_soft_color' => '#fff6e5',
    'sidebar_gradient_1' => '#171c21',
    'sidebar_gradient_2' => '#20272d',
    'sidebar_gradient_3' => '#101419',
    'page_background' => '#f4f3f0',
    'card_background' => '#ffffff',
    'text_color' => '#171717',
    'muted_text_color' => '#7d8794',
    'border_color' => '#e8e8e8',
    'font_family' => 'Inter',
    'heading_font_family' => 'Playfair Display',
    'border_radius_px' => 12,
    'sidebar_width_px' => 230,
];

if (tableExists($conn, 'business_theme_settings')) {
    $themeStmt = $conn->prepare('SELECT * FROM business_theme_settings WHERE business_id = ? LIMIT 1');
    if ($themeStmt) {
        $themeStmt->bind_param('i', $businessId);
        $themeStmt->execute();
        $themeRow = $themeStmt->get_result()->fetch_assoc() ?: [];
        $themeStmt->close();

        foreach ($theme as $key => $defaultValue) {
            if (isset($themeRow[$key]) && $themeRow[$key] !== '') {
                $theme[$key] = $themeRow[$key];
            }
        }
    }
}

$businessName = (string)($_SESSION['business_name'] ?? 'Jewellery ERP');
$currencySymbol = (string)($_SESSION['currency_symbol'] ?? '₹');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo h($businessName); ?> - Add Purchase</title>
    <?php include('includes/links.php'); ?>

    <style>
        :root {
            --primary: <?php echo h($theme['primary_color']); ?>;
            --primary-dark: <?php echo h($theme['primary_dark_color']); ?>;
            --primary-soft: <?php echo h($theme['primary_soft_color']); ?>;
            --sidebar-gradient-1: <?php echo h($theme['sidebar_gradient_1']); ?>;
            --sidebar-gradient-2: <?php echo h($theme['sidebar_gradient_2']); ?>;
            --sidebar-gradient-3: <?php echo h($theme['sidebar_gradient_3']); ?>;
            --page-bg: <?php echo h($theme['page_background']); ?>;
            --card-bg: <?php echo h($theme['card_background']); ?>;
            --text-color: <?php echo h($theme['text_color']); ?>;
            --muted-color: <?php echo h($theme['muted_text_color']); ?>;
            --border-color: <?php echo h($theme['border_color']); ?>;
            --sidebar-width: <?php echo (int)$theme['sidebar_width_px']; ?>px;
            --radius: <?php echo (int)$theme['border_radius_px']; ?>px;
        }

        body {
            background: var(--page-bg);
            color: var(--text-color);
            font-family: <?php echo json_encode((string)$theme['font_family']); ?>, sans-serif;
        }

        .sidebar {
            background: linear-gradient(
                180deg,
                var(--sidebar-gradient-1),
                var(--sidebar-gradient-2),
                var(--sidebar-gradient-3)
            ) !important;
        }

        .purchase-layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 320px;
            gap: 12px;
            align-items: start;
        }

        .section-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            overflow: hidden;
            margin-bottom: 12px;
        }

        .section-head {
            padding: 12px 14px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }

        .section-title {
            margin: 0;
            font-family: <?php echo json_encode((string)$theme['heading_font_family']); ?>, serif;
            font-size: 15px;
            font-weight: 800;
        }

        .section-subtitle {
            margin-top: 2px;
            color: var(--muted-color);
            font-size: 9px;
        }

        .section-body {
            padding: 14px;
        }

        .form-label {
            margin-bottom: 5px;
            font-size: 10px;
            font-weight: 700;
        }

        .form-control,
        .form-select {
            min-height: 38px;
            border-color: var(--border-color);
            border-radius: 9px;
            background: var(--card-bg);
            color: var(--text-color);
            font-size: 11px;
            box-shadow: none;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 .2rem color-mix(in srgb, var(--primary) 13%, transparent);
        }

        .btn-theme {
            border: 0;
            border-radius: 9px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: #fff;
            padding: 9px 14px;
            font-size: 11px;
            font-weight: 700;
        }

        .btn-theme:hover {
            color: #fff;
            filter: brightness(1.03);
        }

        .btn-soft {
            border: 1px solid color-mix(in srgb, var(--primary) 26%, var(--border-color));
            border-radius: 9px;
            background: var(--primary-soft);
            color: var(--primary-dark);
            padding: 8px 12px;
            font-size: 11px;
            font-weight: 700;
        }

        .purchase-items-wrap {
            width: 100%;
            overflow-x: auto;
        }

        #itemsTable {
            min-width: 2070px;
            margin: 0;
            font-size: 10px;
        }

        #itemsTable th {
            padding: 9px 7px;
            border-color: var(--border-color);
            background: color-mix(in srgb, var(--muted-color) 6%, var(--card-bg));
            color: var(--muted-color);
            font-size: 9px;
            letter-spacing: .04em;
            text-transform: uppercase;
            white-space: nowrap;
        }

        #itemsTable td {
            padding: 6px;
            border-color: var(--border-color);
            background: var(--card-bg) !important;
            vertical-align: middle;
        }

        #itemsTable .form-control,
        #itemsTable .form-select {
            min-width: 82px;
            min-height: 34px;
            height: 34px;
            padding: 5px 7px;
            font-size: 10px;
        }

        #itemsTable .product-select {
            min-width: 210px;
        }

        #itemsTable .item-name {
            min-width: 180px;
        }

        .remove-row {
            width: 30px;
            height: 30px;
            border: 1px solid #f1caca;
            border-radius: 8px;
            background: #fff0f0;
            color: #b42318;
            display: inline-flex;
            justify-content: center;
            align-items: center;
        }

        .summary-card {
            position: sticky;
            top: 82px;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            padding: 9px 0;
            border-bottom: 1px dashed var(--border-color);
            font-size: 11px;
        }

        .summary-row:last-child {
            border-bottom: 0;
        }

        .summary-label {
            color: var(--muted-color);
        }

        .summary-value {
            font-weight: 800;
            text-align: right;
        }

        .summary-input {
            width: 116px;
        }

        .grand-total-row {
            margin-top: 7px;
            padding-top: 12px;
            border-top: 1px solid var(--border-color);
            border-bottom: 0;
        }

        .grand-total-row .summary-label {
            color: var(--text-color);
            font-weight: 800;
        }

        .grand-total-row .summary-value {
            color: var(--primary-dark);
            font-size: 20px;
        }

        .balance-box {
            margin-top: 10px;
            padding: 11px;
            border-radius: 10px;
            background: var(--primary-soft);
        }

        .status-pill {
            display: inline-flex;
            padding: 5px 9px;
            border-radius: 999px;
            background: #fdecec;
            color: #bd2d2d;
            font-size: 9px;
            font-weight: 800;
        }

        .status-pill.partial {
            background: #fff4dc;
            color: #9a6200;
        }

        .status-pill.paid {
            background: #eaf8f0;
            color: #168449;
        }

        .modern-alert {
            margin-bottom: 10px;
            border: 0;
            border-radius: 10px;
            font-size: 11px;
        }

        .toast-stack {
            position: fixed;
            top: 84px;
            right: 18px;
            z-index: 25000;
            display: grid;
            gap: 10px;
            width: min(390px, calc(100vw - 28px));
            pointer-events: none;
        }

        .app-toast {
            display: grid;
            grid-template-columns: 22px minmax(0, 1fr) 24px;
            align-items: center;
            gap: 10px;
            padding: 12px 13px;
            border-radius: 11px;
            color: #fff;
            box-shadow: 0 16px 36px rgba(0,0,0,.24);
            opacity: 0;
            transform: translateX(18px);
            transition: opacity .22s ease, transform .22s ease;
            pointer-events: auto;
            font-size: 11px;
            font-weight: 600;
        }

        .app-toast.show {
            opacity: 1;
            transform: translateX(0);
        }

        .app-toast.success { background: #168449; }
        .app-toast.error { background: #c0392b; }
        .app-toast.warning { background: #a96b00; }
        .app-toast.info { background: #2367a8; }

        .app-toast-message {
            line-height: 1.4;
            overflow-wrap: anywhere;
        }

        .app-toast-close {
            width: 24px;
            height: 24px;
            border: 0;
            border-radius: 7px;
            background: rgba(255,255,255,.15);
            color: #fff;
            display: grid;
            place-items: center;
            cursor: pointer;
        }

        @media (max-width: 767.98px) {
            .toast-stack {
                top: 72px;
                left: 12px;
                right: 12px;
                width: auto;
            }
        }

        body.dark-mode,
        body[data-theme="dark"],
        html.dark-mode body,
        html[data-theme="dark"] body {
            --page-bg: #0f151b;
            --card-bg: #182129;
            --text-color: #f3f6f8;
            --muted-color: #9aa7b3;
            --border-color: #2c3944;
        }

        @media (max-width: 1199.98px) {
            .purchase-layout {
                grid-template-columns: 1fr;
            }

            .summary-card {
                position: static;
            }
        }

        @media (max-width: 767.98px) {
            .content-wrap {
                padding-left: 10px;
                padding-right: 10px;
            }

            .section-body {
                padding: 11px;
            }

            .section-head {
                align-items: flex-start;
            }
        }
    </style>
</head>

<body>
<?php include('includes/sidebar.php'); ?>

<main class="app-main">
    <?php include('includes/nav.php'); ?>

    <div class="content-wrap">
        <div class="toast-stack" id="toastStack" aria-live="polite" aria-atomic="true"></div>

        <div
            id="serverToastData"
            data-success="<?php echo h($success); ?>"
            data-error="<?php echo h($error); ?>"
            hidden
        ></div>

        <form method="post" id="purchaseForm" autocomplete="off">
            <div class="purchase-layout">
                <div>
                    <section class="section-card">
                        <div class="section-head">
                            <div>
                                <h2 class="section-title">Purchase Details</h2>
                                <div class="section-subtitle">Enter purchase number, supplier and invoice information.</div>
                            </div>

                            <a href="purchases.php" class="btn btn-light btn-sm">
                                <i class="fa-solid fa-arrow-left me-1"></i>Back
                            </a>
                        </div>

                        <div class="section-body">
                            <div class="row g-3">
                                <div class="col-lg-3 col-md-6">
                                    <label class="form-label">Purchase No</label>
                                    <input
                                        type="text"
                                        name="purchase_no"
                                        class="form-control"
                                        value="<?php echo h($purchaseNo); ?>"
                                        required
                                    >
                                </div>

                                <div class="col-lg-3 col-md-6">
                                    <label class="form-label">Purchase Date</label>
                                    <input
                                        type="date"
                                        name="purchase_date"
                                        class="form-control"
                                        value="<?php echo h($purchaseDate); ?>"
                                        required
                                    >
                                </div>

                                <div class="col-lg-3 col-md-6">
                                    <label class="form-label">Supplier <span class="text-danger">*</span></label>
                                    <select name="supplier_id" class="form-select" required>
                                        <option value="">Select Supplier</option>

                                        <?php foreach ($suppliers as $sup): ?>
                                            <option
                                                value="<?php echo (int)$sup['id']; ?>"
                                                <?php echo $supplierId === (int)$sup['id'] ? 'selected' : ''; ?>
                                            >
                                                <?php
                                                echo h($sup['supplier_name']);
                                                if (!empty($sup['supplier_code'])) {
                                                    echo ' (' . h($sup['supplier_code']) . ')';
                                                }
                                                ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-lg-3 col-md-6">
                                    <label class="form-label">Invoice No</label>
                                    <input
                                        type="text"
                                        name="invoice_no"
                                        class="form-control"
                                        value="<?php echo h($invoiceNo); ?>"
                                    >
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="section-card">
                        <div class="section-head">
                            <div>
                                <h2 class="section-title">Purchase Items</h2>
                                <div class="section-subtitle">Add products, weights, charges, discount and GST.</div>
                            </div>

                            <button type="button" class="btn-soft" id="addRowBtn">
                                <i class="fa-solid fa-plus me-1"></i>Add Item
                            </button>
                        </div>

                        <div class="purchase-items-wrap">
                            <table class="table align-middle" id="itemsTable">
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th>Item Name</th>
                                        <th>Purity</th>
                                        <th>HSN</th>
                                        <th>Qty</th>
                                        <th>Gross Wt</th>
                                        <th>Less Wt</th>
                                        <th>Net Wt</th>
                                        <th>Rate/Gm</th>
                                        <th>Making</th>
                                        <th>Stone</th>
                                        <th>Discount</th>
                                        <th>GST %</th>
                                        <th>Taxable</th>
                                        <th>GST Amt</th>
                                        <th>Total</th>
                                        <th></th>
                                    </tr>
                                </thead>

                                <tbody id="itemRows">
                                <?php foreach ($formItems as $index => $item): ?>
                                    <tr>
                                        <td>
                                            <select
                                                name="items[<?php echo $index; ?>][product_id]"
                                                class="form-select product-select"
                                            >
                                                <option value="">Select Product</option>

                                                <?php foreach ($products as $prd): ?>
                                                    <option
                                                        value="<?php echo (int)$prd['id']; ?>"
                                                        data-name="<?php echo h($prd['product_name']); ?>"
                                                        data-purity="<?php echo h($prd['purity'] ?? '925'); ?>"
                                                        data-rate="<?php echo h($prd['purchase_rate'] ?? '0'); ?>"
                                                        data-weight="<?php echo h($prd['net_weight'] ?? '0'); ?>"
                                                        <?php echo (int)($item['product_id'] ?? 0) === (int)$prd['id'] ? 'selected' : ''; ?>
                                                    >
                                                        <?php echo h($prd['product_name'] . ' (' . $prd['product_code'] . ')'); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>

                                        <td>
                                            <input
                                                type="text"
                                                name="items[<?php echo $index; ?>][item_name]"
                                                class="form-control item-name"
                                                value="<?php echo h($item['item_name']); ?>"
                                            >
                                        </td>

                                        <td><input type="text" name="items[<?php echo $index; ?>][purity]" class="form-control purity" value="<?php echo h($item['purity']); ?>"></td>
                                        <td><input type="text" name="items[<?php echo $index; ?>][hsn_code]" class="form-control hsn-code" value="<?php echo h($item['hsn_code']); ?>"></td>
                                        <td><input type="number" step="0.001" min="0" name="items[<?php echo $index; ?>][qty]" class="form-control qty" value="<?php echo h($item['qty']); ?>"></td>
                                        <td><input type="number" step="0.001" min="0" name="items[<?php echo $index; ?>][gross_weight]" class="form-control gross-weight" value="<?php echo h($item['gross_weight']); ?>"></td>
                                        <td><input type="number" step="0.001" min="0" name="items[<?php echo $index; ?>][less_weight]" class="form-control less-weight" value="<?php echo h($item['less_weight']); ?>"></td>
                                        <td><input type="number" step="0.001" min="0" name="items[<?php echo $index; ?>][net_weight]" class="form-control net-weight" value="<?php echo h($item['net_weight']); ?>"></td>
                                        <td><input type="number" step="0.01" min="0" name="items[<?php echo $index; ?>][rate_per_gram]" class="form-control rate-per-gram" value="<?php echo h($item['rate_per_gram']); ?>"></td>
                                        <td><input type="number" step="0.01" min="0" name="items[<?php echo $index; ?>][making_charge]" class="form-control making-charge" value="<?php echo h($item['making_charge']); ?>"></td>
                                        <td><input type="number" step="0.01" min="0" name="items[<?php echo $index; ?>][stone_charge]" class="form-control stone-charge" value="<?php echo h($item['stone_charge']); ?>"></td>
                                        <td><input type="number" step="0.01" min="0" name="items[<?php echo $index; ?>][discount_amount]" class="form-control item-discount" value="<?php echo h($item['discount_amount']); ?>"></td>
                                        <td><input type="number" step="0.01" min="0" name="items[<?php echo $index; ?>][gst_percent]" class="form-control gst-percent" value="<?php echo h($item['gst_percent']); ?>"></td>

                                        <td>
                                            <input
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                name="items[<?php echo $index; ?>][taxable_amount]"
                                                class="form-control taxable-amount"
                                                value="<?php echo h($item['taxable_amount']); ?>"
                                                readonly
                                            >
                                            <input
                                                type="hidden"
                                                name="items[<?php echo $index; ?>][item_amount]"
                                                class="item-amount"
                                                value="<?php echo h($item['item_amount']); ?>"
                                            >
                                        </td>

                                        <td><input type="number" step="0.01" min="0" name="items[<?php echo $index; ?>][gst_amount]" class="form-control gst-amount" value="<?php echo h($item['gst_amount']); ?>" readonly></td>
                                        <td><input type="number" step="0.01" min="0" name="items[<?php echo $index; ?>][total_amount]" class="form-control total-amount" value="<?php echo h($item['total_amount']); ?>" readonly></td>

                                        <td>
                                            <button type="button" class="remove-row" title="Remove item">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section class="section-card">
                        <div class="section-head">
                            <div>
                                <h2 class="section-title">Payment & Notes</h2>
                                <div class="section-subtitle">Select payment mode and enter purchase notes.</div>
                            </div>
                        </div>

                        <div class="section-body">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label">Payment Method</label>
                                    <select name="payment_method_id" class="form-select">
                                        <option value="">Select Payment Method</option>

                                        <?php foreach ($paymentMethods as $pm): ?>
                                            <option
                                                value="<?php echo (int)$pm['id']; ?>"
                                                <?php echo $paymentMethodId === (int)$pm['id'] ? 'selected' : ''; ?>
                                            >
                                                <?php echo h($pm['method_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-8">
                                    <label class="form-label">Notes</label>
                                    <input
                                        type="text"
                                        name="notes"
                                        class="form-control"
                                        value="<?php echo h($notes); ?>"
                                        placeholder="Optional purchase notes"
                                    >
                                </div>
                            </div>
                        </div>
                    </section>
                </div>

                <aside>
                    <section class="section-card summary-card">
                        <div class="section-head">
                            <div>
                                <h2 class="section-title">Purchase Summary</h2>
                                <div class="section-subtitle">Calculated from the item rows.</div>
                            </div>
                        </div>

                        <div class="section-body">
                            <div class="summary-row">
                                <span class="summary-label">Subtotal</span>
                                <span class="summary-value">
                                    <?php echo h($currencySymbol); ?>
                                    <span id="subtotalText">0.00</span>
                                </span>
                            </div>

                            <div class="summary-row">
                                <span class="summary-label">Discount</span>
                                <input
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    name="discount_amount"
                                    id="discount_amount"
                                    class="form-control text-end summary-input"
                                    value="<?php echo h($discountAmount); ?>"
                                >
                            </div>

                            <div class="summary-row">
                                <span class="summary-label">Taxable</span>
                                <span class="summary-value">
                                    <?php echo h($currencySymbol); ?>
                                    <span id="taxableText">0.00</span>
                                </span>
                            </div>

                            <div class="summary-row">
                                <span class="summary-label">GST Total</span>
                                <span class="summary-value">
                                    <?php echo h($currencySymbol); ?>
                                    <span id="gstText">0.00</span>
                                </span>
                            </div>

                            <div class="summary-row">
                                <span class="summary-label">Round Off</span>
                                <input
                                    type="number"
                                    step="0.01"
                                    name="round_off"
                                    id="round_off"
                                    class="form-control text-end summary-input"
                                    value="<?php echo h($roundOff); ?>"
                                >
                            </div>

                            <div class="summary-row grand-total-row">
                                <span class="summary-label">Grand Total</span>
                                <span class="summary-value">
                                    <?php echo h($currencySymbol); ?>
                                    <span id="grandTotalText">0.00</span>
                                </span>
                            </div>

                            <div class="summary-row">
                                <span class="summary-label">Paid Amount</span>
                                <input
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    name="paid_amount"
                                    id="paid_amount"
                                    class="form-control text-end summary-input"
                                    value="<?php echo h($paidAmount); ?>"
                                >
                            </div>

                            <div class="balance-box">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="summary-label">Balance</span>
                                    <span class="summary-value">
                                        <?php echo h($currencySymbol); ?>
                                        <span id="balanceText">0.00</span>
                                    </span>
                                </div>

                                <span class="status-pill" id="paymentStatus">Unpaid</span>
                            </div>

                            <input type="hidden" id="subtotal">
                            <input type="hidden" id="taxable_total">
                            <input type="hidden" id="gst_total">
                            <input type="hidden" id="grand_total">
                            <input type="hidden" id="balance_amount">

                            <div class="d-grid gap-2 mt-3">
                                <button type="submit" class="btn btn-theme">
                                    <i class="fa-solid fa-floppy-disk me-2"></i>Save Purchase
                                </button>

                                <a href="purchases.php" class="btn btn-light btn-sm">Cancel</a>
                            </div>
                        </div>
                    </section>
                </aside>
            </div>
        </form>

        <?php include('includes/footer.php'); ?>
    </div>
</main>

<?php include('includes/script.php'); ?>
<script src="assets/js/script.js"></script>

<script>
(function () {
    const toastStack = document.getElementById('toastStack');

    function showToast(type, message, duration = 3600) {
        const cleanMessage = String(message || '').trim();
        if (!cleanMessage || !toastStack) return;

        const icons = {
            success: 'fa-circle-check',
            error: 'fa-circle-exclamation',
            warning: 'fa-triangle-exclamation',
            info: 'fa-circle-info'
        };

        const toast = document.createElement('div');
        toast.className = 'app-toast ' + (type || 'info');
        toast.innerHTML = `
            <i class="fa-solid ${icons[type] || icons.info}"></i>
            <div class="app-toast-message"></div>
            <button type="button" class="app-toast-close" aria-label="Close notification">
                <i class="fa-solid fa-xmark"></i>
            </button>
        `;

        toast.querySelector('.app-toast-message').textContent = cleanMessage;
        toastStack.appendChild(toast);

        const removeToast = () => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 220);
        };

        toast.querySelector('.app-toast-close').addEventListener('click', removeToast);
        requestAnimationFrame(() => toast.classList.add('show'));
        setTimeout(removeToast, duration);
    }

    const serverToastData = document.getElementById('serverToastData');
    if (serverToastData) {
        const successMessage = serverToastData.dataset.success || '';
        const errorMessage = serverToastData.dataset.error || '';

        if (successMessage) showToast('success', successMessage);
        if (errorMessage) showToast('error', errorMessage, 4800);
    }

    window.showPurchaseToast = showToast;

    const products = <?php
        $productJs = [];
        foreach ($products as $p) {
            $productJs[] = [
                'id' => (int)$p['id'],
                'product_name' => (string)$p['product_name'],
                'product_code' => (string)$p['product_code'],
                'purity' => (string)($p['purity'] ?? '925'),
                'purchase_rate' => (float)($p['purchase_rate'] ?? 0),
                'net_weight' => (float)($p['net_weight'] ?? 0)
            ];
        }
        echo json_encode($productJs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    ?>;

    let rowIndex = document.querySelectorAll('#itemRows tr').length;

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.innerText = text == null ? '' : text;
        return div.innerHTML;
    }

    function optionHtml(selectedId = '') {
        let html = '<option value="">Select Product</option>';

        products.forEach(function (p) {
            const selected = String(selectedId) === String(p.id) ? 'selected' : '';

            html += `<option
                value="${p.id}"
                data-name="${escapeHtml(p.product_name)}"
                data-purity="${escapeHtml(p.purity)}"
                data-rate="${p.purchase_rate}"
                data-weight="${p.net_weight}"
                ${selected}
            >${escapeHtml(p.product_name)} (${escapeHtml(p.product_code)})</option>`;
        });

        return html;
    }

    function addRow(item = {}) {
        const tr = document.createElement('tr');

        tr.innerHTML = `
            <td>
                <select name="items[${rowIndex}][product_id]" class="form-select product-select">
                    ${optionHtml(item.product_id || '')}
                </select>
            </td>

            <td><input type="text" name="items[${rowIndex}][item_name]" class="form-control item-name" value="${escapeHtml(item.item_name || '')}"></td>
            <td><input type="text" name="items[${rowIndex}][purity]" class="form-control purity" value="${escapeHtml(item.purity || '925')}"></td>
            <td><input type="text" name="items[${rowIndex}][hsn_code]" class="form-control hsn-code" value="${escapeHtml(item.hsn_code || '')}"></td>
            <td><input type="number" step="0.001" min="0" name="items[${rowIndex}][qty]" class="form-control qty" value="${item.qty || '1.000'}"></td>
            <td><input type="number" step="0.001" min="0" name="items[${rowIndex}][gross_weight]" class="form-control gross-weight" value="${item.gross_weight || '0.000'}"></td>
            <td><input type="number" step="0.001" min="0" name="items[${rowIndex}][less_weight]" class="form-control less-weight" value="${item.less_weight || '0.000'}"></td>
            <td><input type="number" step="0.001" min="0" name="items[${rowIndex}][net_weight]" class="form-control net-weight" value="${item.net_weight || '0.000'}"></td>
            <td><input type="number" step="0.01" min="0" name="items[${rowIndex}][rate_per_gram]" class="form-control rate-per-gram" value="${item.rate_per_gram || '0.00'}"></td>
            <td><input type="number" step="0.01" min="0" name="items[${rowIndex}][making_charge]" class="form-control making-charge" value="${item.making_charge || '0.00'}"></td>
            <td><input type="number" step="0.01" min="0" name="items[${rowIndex}][stone_charge]" class="form-control stone-charge" value="${item.stone_charge || '0.00'}"></td>
            <td><input type="number" step="0.01" min="0" name="items[${rowIndex}][discount_amount]" class="form-control item-discount" value="${item.discount_amount || '0.00'}"></td>
            <td><input type="number" step="0.01" min="0" name="items[${rowIndex}][gst_percent]" class="form-control gst-percent" value="${item.gst_percent || '3.00'}"></td>

            <td>
                <input type="number" step="0.01" min="0" name="items[${rowIndex}][taxable_amount]" class="form-control taxable-amount" value="${item.taxable_amount || '0.00'}" readonly>
                <input type="hidden" name="items[${rowIndex}][item_amount]" class="item-amount" value="${item.item_amount || '0.00'}">
            </td>

            <td><input type="number" step="0.01" min="0" name="items[${rowIndex}][gst_amount]" class="form-control gst-amount" value="${item.gst_amount || '0.00'}" readonly></td>
            <td><input type="number" step="0.01" min="0" name="items[${rowIndex}][total_amount]" class="form-control total-amount" value="${item.total_amount || '0.00'}" readonly></td>

            <td>
                <button type="button" class="remove-row" title="Remove item">
                    <i class="fa-solid fa-trash"></i>
                </button>
            </td>
        `;

        document.getElementById('itemRows').appendChild(tr);
        rowIndex++;
        bindRow(tr);
        calculateAll();
    }

    function calculateRow(tr) {
        const gross = parseFloat(tr.querySelector('.gross-weight').value || 0);
        const less = parseFloat(tr.querySelector('.less-weight').value || 0);

        let net = gross - less;
        if (net < 0) {
            net = 0;
        }

        tr.querySelector('.net-weight').value = net.toFixed(3);

        const netWeight = parseFloat(tr.querySelector('.net-weight').value || 0);
        const rate = parseFloat(tr.querySelector('.rate-per-gram').value || 0);
        const making = parseFloat(tr.querySelector('.making-charge').value || 0);
        const stone = parseFloat(tr.querySelector('.stone-charge').value || 0);
        const discount = parseFloat(tr.querySelector('.item-discount').value || 0);
        const gstPercent = parseFloat(tr.querySelector('.gst-percent').value || 0);

        const itemAmount = (netWeight * rate) + making + stone;

        let taxable = itemAmount - discount;
        if (taxable < 0) {
            taxable = 0;
        }

        const gstAmount = taxable * gstPercent / 100;
        const total = taxable + gstAmount;

        tr.querySelector('.item-amount').value = itemAmount.toFixed(2);
        tr.querySelector('.taxable-amount').value = taxable.toFixed(2);
        tr.querySelector('.gst-amount').value = gstAmount.toFixed(2);
        tr.querySelector('.total-amount').value = total.toFixed(2);

        calculateAll();
    }

    function calculateAll() {
        let subtotal = 0;
        let taxableTotal = 0;
        let gstTotal = 0;

        document.querySelectorAll('#itemRows tr').forEach(function (tr) {
            subtotal += parseFloat(tr.querySelector('.item-amount').value || 0);
            taxableTotal += parseFloat(tr.querySelector('.taxable-amount').value || 0);
            gstTotal += parseFloat(tr.querySelector('.gst-amount').value || 0);
        });

        const discountAmount = parseFloat(document.getElementById('discount_amount').value || 0);
        const roundOff = parseFloat(document.getElementById('round_off').value || 0);
        const paidAmount = parseFloat(document.getElementById('paid_amount').value || 0);

        let finalTaxable = taxableTotal - discountAmount;
        if (finalTaxable < 0) {
            finalTaxable = 0;
        }

        const grandTotal = finalTaxable + gstTotal + roundOff;

        let balance = grandTotal - paidAmount;
        if (balance < 0) {
            balance = 0;
        }

        document.getElementById('subtotal').value = subtotal.toFixed(2);
        document.getElementById('taxable_total').value = finalTaxable.toFixed(2);
        document.getElementById('gst_total').value = gstTotal.toFixed(2);
        document.getElementById('grand_total').value = grandTotal.toFixed(2);
        document.getElementById('balance_amount').value = balance.toFixed(2);

        document.getElementById('subtotalText').textContent = subtotal.toFixed(2);
        document.getElementById('taxableText').textContent = finalTaxable.toFixed(2);
        document.getElementById('gstText').textContent = gstTotal.toFixed(2);
        document.getElementById('grandTotalText').textContent = grandTotal.toFixed(2);
        document.getElementById('balanceText').textContent = balance.toFixed(2);

        const status = document.getElementById('paymentStatus');
        status.className = 'status-pill';

        if (grandTotal > 0 && paidAmount >= grandTotal) {
            status.textContent = 'Paid';
            status.classList.add('paid');
        } else if (paidAmount > 0) {
            status.textContent = 'Partial';
            status.classList.add('partial');
        } else {
            status.textContent = 'Unpaid';
        }
    }

    function bindRow(tr) {
        const productSelect = tr.querySelector('.product-select');

        if (productSelect) {
            productSelect.addEventListener('change', function () {
                const option = this.options[this.selectedIndex];

                if (!option || !option.value) {
                    return;
                }

                const name = option.getAttribute('data-name') || '';
                const purity = option.getAttribute('data-purity') || '925';
                const rate = parseFloat(option.getAttribute('data-rate') || 0);
                const weight = parseFloat(option.getAttribute('data-weight') || 0);

                tr.querySelector('.item-name').value = name;
                tr.querySelector('.purity').value = purity;
                tr.querySelector('.rate-per-gram').value = rate.toFixed(2);

                const grossInput = tr.querySelector('.gross-weight');
                const netInput = tr.querySelector('.net-weight');

                if (parseFloat(grossInput.value || 0) <= 0) {
                    grossInput.value = weight.toFixed(3);
                }

                if (parseFloat(netInput.value || 0) <= 0) {
                    netInput.value = weight.toFixed(3);
                }

                calculateRow(tr);
            });
        }

        tr.querySelectorAll('input').forEach(function (input) {
            input.addEventListener('input', function () {
                calculateRow(tr);
            });
        });

        const removeButton = tr.querySelector('.remove-row');

        if (removeButton) {
            removeButton.addEventListener('click', function () {
                const rows = document.querySelectorAll('#itemRows tr');

                if (rows.length > 1) {
                    tr.remove();
                    calculateAll();
                }
            });
        }
    }

    document.querySelectorAll('#itemRows tr').forEach(function (tr) {
        bindRow(tr);
    });

    document.getElementById('addRowBtn').addEventListener('click', function () {
        addRow();
    });

    ['discount_amount', 'round_off', 'paid_amount'].forEach(function (id) {
        const element = document.getElementById(id);

        if (element) {
            element.addEventListener('input', calculateAll);
        }
    });

    calculateAll();
})();
</script>
</body>
</html>
