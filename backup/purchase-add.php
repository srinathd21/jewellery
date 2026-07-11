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
                /* INSERT PURCHASE */
                $purFields = [];
                $purPlaceholders = [];
                $purTypes = '';
                $purValues = [];

                if ($purHasBusinessId) {
                    $purFields[] = 'business_id';
                    $purPlaceholders[] = '?';
                    $purTypes .= 'i';
                    $purValues[] = $businessId;
                }

                $purchaseColumns = [
                    'purchase_no'     => [$purchaseNo, 's'],
                    'purchase_date'   => [$purchaseDate, 's'],
                    'supplier_id'     => [$supplierId, 'i'],
                    'invoice_no'      => [$invoiceNo, 's'],
                    'subtotal'        => [$subtotal, 'd'],
                    'discount_amount' => [$discountAmountF, 'd'],
                    'taxable_amount'  => [$taxableTotal, 'd'],
                    'cgst_amount'     => [$cgstAmount, 'd'],
                    'sgst_amount'     => [$sgstAmount, 'd'],
                    'igst_amount'     => [$igstAmount, 'd'],
                    'round_off'       => [$roundOffF, 'd'],
                    'grand_total'     => [$grandTotal, 'd'],
                    'paid_amount'     => [$paidAmountF, 'd'],
                    'balance_amount'  => [$balanceAmount, 'd'],
                    'payment_status'  => [$paymentStatus, 's'],
                    'notes'           => [$notes, 's'],
                    'created_by'      => [$userId, 'i']
                ];

                foreach ($purchaseColumns as $col => $cfg) {
                    if (hasColumn($conn, 'purchases', $col)) {
                        $purFields[] = $col;
                        $purPlaceholders[] = '?';
                        $purTypes .= $cfg[1];
                        $purValues[] = $cfg[0];
                    }
                }

                if (hasColumn($conn, 'purchases', 'created_at')) {
                    $purFields[] = 'created_at';
                    $purPlaceholders[] = 'NOW()';
                }
                if (hasColumn($conn, 'purchases', 'updated_at')) {
                    $purFields[] = 'updated_at';
                    $purPlaceholders[] = 'NOW()';
                }

                $sql = "INSERT INTO purchases (" . implode(', ', $purFields) . ") VALUES (" . implode(', ', $purPlaceholders) . ")";
                $stmt = $conn->prepare($sql);
                if (!$stmt) {
                    throw new Exception('Failed to prepare purchase insert.');
                }

                $bindValues = [];
                $bindValues[] = $purTypes;
                for ($i = 0; $i < count($purValues); $i++) {
                    $bindValues[] = &$purValues[$i];
                }
                call_user_func_array([$stmt, 'bind_param'], $bindValues);

                if (!$stmt->execute()) {
                    throw new Exception('Failed to save purchase.');
                }

                $purchaseId = (int)$stmt->insert_id;
                $stmt->close();

                /* INSERT PURCHASE ITEMS */
                foreach ($cleanItems as $row) {
                    $pitFields = [];
                    $pitPlaceholders = [];
                    $pitTypes = '';
                    $pitValues = [];

                    if ($pitHasBusinessId) {
                        $pitFields[] = 'business_id';
                        $pitPlaceholders[] = '?';
                        $pitTypes .= 'i';
                        $pitValues[] = $businessId;
                    }

                    $itemColumns = [
                        'purchase_id'     => [$purchaseId, 'i'],
                        'product_id'      => [$row['product_id'] > 0 ? $row['product_id'] : null, 'i'],
                        'item_name'       => [$row['item_name'], 's'],
                        'purity'          => [$row['purity'], 's'],
                        'hsn_code'        => [$row['hsn_code'], 's'],
                        'qty'             => [(float)$row['qty'], 'd'],
                        'gross_weight'    => [(float)$row['gross_weight'], 'd'],
                        'less_weight'     => [(float)$row['less_weight'], 'd'],
                        'net_weight'      => [(float)$row['net_weight'], 'd'],
                        'rate_per_gram'   => [(float)$row['rate_per_gram'], 'd'],
                        'making_charge'   => [(float)$row['making_charge'], 'd'],
                        'stone_charge'    => [(float)$row['stone_charge'], 'd'],
                        'item_amount'     => [(float)$row['item_amount'], 'd'],
                        'discount_amount' => [(float)$row['discount_amount'], 'd'],
                        'taxable_amount'  => [(float)$row['taxable_amount'], 'd'],
                        'gst_percent'     => [(float)$row['gst_percent'], 'd'],
                        'gst_amount'      => [(float)$row['gst_amount'], 'd'],
                        'total_amount'    => [(float)$row['total_amount'], 'd']
                    ];

                    foreach ($itemColumns as $col => $cfg) {
                        if (hasColumn($conn, 'purchase_items', $col)) {
                            $pitFields[] = $col;
                            $pitPlaceholders[] = '?';
                            $pitTypes .= $cfg[1];
                            $pitValues[] = $cfg[0];
                        }
                    }

                    $sql = "INSERT INTO purchase_items (" . implode(', ', $pitFields) . ") VALUES (" . implode(', ', $pitPlaceholders) . ")";
                    $stmt = $conn->prepare($sql);
                    if (!$stmt) {
                        throw new Exception('Failed to prepare purchase item insert.');
                    }

                    $bindValues = [];
                    $bindValues[] = $pitTypes;
                    for ($i = 0; $i < count($pitValues); $i++) {
                        $bindValues[] = &$pitValues[$i];
                    }
                    call_user_func_array([$stmt, 'bind_param'], $bindValues);

                    if (!$stmt->execute()) {
                        throw new Exception('Failed to save purchase item.');
                    }
                    $stmt->close();

                    $productId = (int)$row['product_id'];
                    $qty = (float)$row['qty'];
                    $netWeight = (float)$row['net_weight'];

                    if ($productId > 0) {
                        /* UPDATE PRODUCTS STOCK */
                        if ($prdHasCurrentStockQty) {
                            if ($prdHasBusinessId) {
                                $stmt = $conn->prepare("UPDATE products SET current_stock_qty = COALESCE(current_stock_qty, 0) + ? WHERE id = ? AND business_id = ? LIMIT 1");
                                $stmt->bind_param('dii', $qty, $productId, $businessId);
                            } else {
                                $stmt = $conn->prepare("UPDATE products SET current_stock_qty = COALESCE(current_stock_qty, 0) + ? WHERE id = ? LIMIT 1");
                                $stmt->bind_param('di', $qty, $productId);
                            }

                            if ($stmt) {
                                if (!$stmt->execute()) {
                                    throw new Exception('Failed to update products stock.');
                                }
                                $stmt->close();
                            }
                        }

                        /* UPDATE PRODUCT_STOCK */
                        if ($productStockExists && $productStockHasProduct) {
                            if ($productStockHasBiz) {
                                $stmt = $conn->prepare("SELECT id FROM product_stock WHERE product_id = ? AND business_id = ? LIMIT 1");
                                $stmt->bind_param('ii', $productId, $businessId);
                            } else {
                                $stmt = $conn->prepare("SELECT id FROM product_stock WHERE product_id = ? LIMIT 1");
                                $stmt->bind_param('i', $productId);
                            }

                            $stockRow = null;
                            if ($stmt) {
                                $stmt->execute();
                                $res = $stmt->get_result();
                                $stockRow = $res ? $res->fetch_assoc() : null;
                                $stmt->close();
                            }

                            if ($stockRow) {
                                $updates = [];
                                $types = '';
                                $values = [];

                                if ($productStockHasInQty) {
                                    $updates[] = "in_qty = COALESCE(in_qty, 0) + ?";
                                    $types .= 'd';
                                    $values[] = $qty;
                                }
                                if ($productStockHasInWt) {
                                    $updates[] = "in_weight = COALESCE(in_weight, 0) + ?";
                                    $types .= 'd';
                                    $values[] = $netWeight;
                                }
                                if ($productStockHasCloseQty) {
                                    $updates[] = "closing_qty = COALESCE(closing_qty, 0) + ?";
                                    $types .= 'd';
                                    $values[] = $qty;
                                }
                                if ($productStockHasCloseWt) {
                                    $updates[] = "closing_weight = COALESCE(closing_weight, 0) + ?";
                                    $types .= 'd';
                                    $values[] = $netWeight;
                                }

                                if (!empty($updates)) {
                                    $sql = "UPDATE product_stock SET " . implode(', ', $updates) . " WHERE id = ?";
                                    $types .= 'i';
                                    $values[] = (int)$stockRow['id'];

                                    $stmt = $conn->prepare($sql);
                                    if ($stmt) {
                                        $bindValues = [];
                                        $bindValues[] = $types;
                                        for ($i = 0; $i < count($values); $i++) {
                                            $bindValues[] = &$values[$i];
                                        }
                                        call_user_func_array([$stmt, 'bind_param'], $bindValues);

                                        if (!$stmt->execute()) {
                                            throw new Exception('Failed to update product_stock.');
                                        }
                                        $stmt->close();
                                    }
                                }
                            } else {
                                $fields = [];
                                $placeholders = [];
                                $types = '';
                                $values = [];

                                if ($productStockHasBiz) {
                                    $fields[] = 'business_id';
                                    $placeholders[] = '?';
                                    $types .= 'i';
                                    $values[] = $businessId;
                                }

                                if ($productStockHasProduct) {
                                    $fields[] = 'product_id';
                                    $placeholders[] = '?';
                                    $types .= 'i';
                                    $values[] = $productId;
                                }

                                if ($productStockHasInQty) {
                                    $fields[] = 'in_qty';
                                    $placeholders[] = '?';
                                    $types .= 'd';
                                    $values[] = $qty;
                                }
                                if ($productStockHasInWt) {
                                    $fields[] = 'in_weight';
                                    $placeholders[] = '?';
                                    $types .= 'd';
                                    $values[] = $netWeight;
                                }
                                if ($productStockHasCloseQty) {
                                    $fields[] = 'closing_qty';
                                    $placeholders[] = '?';
                                    $types .= 'd';
                                    $values[] = $qty;
                                }
                                if ($productStockHasCloseWt) {
                                    $fields[] = 'closing_weight';
                                    $placeholders[] = '?';
                                    $types .= 'd';
                                    $values[] = $netWeight;
                                }

                                $sql = "INSERT INTO product_stock (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
                                $stmt = $conn->prepare($sql);
                                if ($stmt) {
                                    $bindValues = [];
                                    $bindValues[] = $types;
                                    for ($i = 0; $i < count($values); $i++) {
                                        $bindValues[] = &$values[$i];
                                    }
                                    call_user_func_array([$stmt, 'bind_param'], $bindValues);

                                    if (!$stmt->execute()) {
                                        throw new Exception('Failed to insert product_stock.');
                                    }
                                    $stmt->close();
                                }
                            }
                        }

                        /* STOCK MOVEMENT */
                        if ($stockMovementExists && $stockMoveHasProductId) {
                            $fields = [];
                            $placeholders = [];
                            $types = '';
                            $values = [];

                            if ($stockMoveHasBiz) {
                                $fields[] = 'business_id';
                                $placeholders[] = '?';
                                $types .= 'i';
                                $values[] = $businessId;
                            }
                            if ($stockMoveHasDate) {
                                $fields[] = 'movement_date';
                                $placeholders[] = 'NOW()';
                            }
                            if ($stockMoveHasProductId) {
                                $fields[] = 'product_id';
                                $placeholders[] = '?';
                                $types .= 'i';
                                $values[] = $productId;
                            }
                            if ($stockMoveHasType) {
                                $fields[] = 'movement_type';
                                $placeholders[] = '?';
                                $types .= 's';
                                $values[] = 'Purchase';
                            }
                            if ($stockMoveHasRefTable) {
                                $fields[] = 'ref_table';
                                $placeholders[] = '?';
                                $types .= 's';
                                $values[] = 'purchases';
                            }
                            if ($stockMoveHasRefId) {
                                $fields[] = 'ref_id';
                                $placeholders[] = '?';
                                $types .= 'i';
                                $values[] = $purchaseId;
                            }
                            if ($stockMoveHasQtyIn) {
                                $fields[] = 'qty_in';
                                $placeholders[] = '?';
                                $types .= 'd';
                                $values[] = $qty;
                            }
                            if ($stockMoveHasWeightIn) {
                                $fields[] = 'weight_in';
                                $placeholders[] = '?';
                                $types .= 'd';
                                $values[] = $netWeight;
                            }
                            if ($stockMoveHasRemarks) {
                                $fields[] = 'remarks';
                                $placeholders[] = '?';
                                $types .= 's';
                                $values[] = 'Purchase entry';
                            }
                            if ($stockMoveHasCreatedBy) {
                                $fields[] = 'created_by';
                                $placeholders[] = '?';
                                $types .= 'i';
                                $values[] = $userId;
                            }

                            $sql = "INSERT INTO stock_movements (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
                            $stmt = $conn->prepare($sql);
                            if ($stmt) {
                                $bindValues = [];
                                $bindValues[] = $types;
                                for ($i = 0; $i < count($values); $i++) {
                                    $bindValues[] = &$values[$i];
                                }
                                call_user_func_array([$stmt, 'bind_param'], $bindValues);

                                if (!$stmt->execute()) {
                                    throw new Exception('Failed to insert stock movement.');
                                }
                                $stmt->close();
                            }
                        }
                    }
                }

                addAuditLog(
                    $conn,
                    $businessId,
                    $userId,
                    'Purchases',
                    'Create',
                    $purchaseId,
                    'Created purchase ' . $purchaseNo
                );

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
<!doctype html>
<html lang="en">
<?php include('includes/head.php'); ?>

<style>
    #itemsTable {
        min-width: 2100px;
    }

    #itemsTable th,
    #itemsTable td {
        vertical-align: middle;
        white-space: nowrap;
    }

    #itemsTable input,
    #itemsTable select {
        min-width: 75px;
        height: 40px;
        font-size: 14px;
    }

    .purchase-summary-table th {
        width: 45%;
        background: #f8f9fa;
    }

    .purchase-summary-table input {
        min-width: 160px;
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

                <form method="post" id="purchaseForm">
                    <div class="row">
                        <div class="col-xl-12">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title mb-4">Purchase Entry</h4>

                                    <div class="row">
                                        <div class="col-md-3 mb-3">
                                            <label class="form-label">Purchase No</label>
                                            <input type="text" name="purchase_no" class="form-control" value="<?php echo h($purchaseNo); ?>" required>
                                        </div>

                                        <div class="col-md-3 mb-3">
                                            <label class="form-label">Purchase Date</label>
                                            <input type="date" name="purchase_date" class="form-control" value="<?php echo h($purchaseDate); ?>" required>
                                        </div>

                                        <div class="col-md-3 mb-3">
                                            <label class="form-label">Supplier <span class="text-danger">*</span></label>
                                            <select name="supplier_id" class="form-select" required>
                                                <option value="">Select Supplier</option>
                                                <?php foreach ($suppliers as $sup): ?>
                                                    <option value="<?php echo (int)$sup['id']; ?>" <?php echo $supplierId === (int)$sup['id'] ? 'selected' : ''; ?>>
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

                                        <div class="col-md-3 mb-3">
                                            <label class="form-label">Invoice No</label>
                                            <input type="text" name="invoice_no" class="form-control" value="<?php echo h($invoiceNo); ?>">
                                        </div>
                                    </div>

                                    <div class="table-responsive" style="overflow-x:auto;">
                                        <table class="table table-bordered align-middle mb-0" id="itemsTable">
                                            <thead class="table-light">
                                                <tr>
                                                    <th style="min-width:220px;">Product</th>
                                                    <th style="min-width:220px;">Item Name</th>
                                                    <th style="min-width:100px;">Purity</th>
                                                    <th style="min-width:100px;">HSN</th>
                                                    <th style="min-width:90px;">Qty</th>
                                                    <th style="min-width:110px;">Gross Wt</th>
                                                    <th style="min-width:110px;">Less Wt</th>
                                                    <th style="min-width:110px;">Net Wt</th>
                                                    <th style="min-width:120px;">Rate/Gm</th>
                                                    <th style="min-width:120px;">Making</th>
                                                    <th style="min-width:120px;">Stone</th>
                                                    <th style="min-width:120px;">Discount</th>
                                                    <th style="min-width:100px;">GST %</th>
                                                    <th style="min-width:130px;">Taxable</th>
                                                    <th style="min-width:130px;">GST Amt</th>
                                                    <th style="min-width:130px;">Total</th>
                                                    <th style="min-width:90px;">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody id="itemRows">
                                                <?php foreach ($formItems as $index => $item): ?>
                                                    <tr>
                                                        <td>
                                                            <select name="items[<?php echo $index; ?>][product_id]" class="form-select product-select">
                                                                <option value="">Select</option>
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
                                                        <td><input type="text" name="items[<?php echo $index; ?>][item_name]" class="form-control item-name" value="<?php echo h($item['item_name']); ?>"></td>
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
                                                            <input type="number" step="0.01" min="0" name="items[<?php echo $index; ?>][taxable_amount]" class="form-control taxable-amount" value="<?php echo h($item['taxable_amount']); ?>" readonly>
                                                            <input type="hidden" name="items[<?php echo $index; ?>][item_amount]" class="item-amount" value="<?php echo h($item['item_amount']); ?>">
                                                        </td>
                                                        <td><input type="number" step="0.01" min="0" name="items[<?php echo $index; ?>][gst_amount]" class="form-control gst-amount" value="<?php echo h($item['gst_amount']); ?>" readonly></td>
                                                        <td><input type="number" step="0.01" min="0" name="items[<?php echo $index; ?>][total_amount]" class="form-control total-amount" value="<?php echo h($item['total_amount']); ?>" readonly></td>
                                                        <td><button type="button" class="btn btn-danger btn-sm remove-row">X</button></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>

                                    <button type="button" class="btn btn-info btn-sm mt-3 mb-4" id="addRowBtn">Add Item</button>

                                    <div class="row">
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Payment Method</label>
                                            <select name="payment_method_id" class="form-select">
                                                <option value="">Select</option>
                                                <?php foreach ($paymentMethods as $pm): ?>
                                                    <option value="<?php echo (int)$pm['id']; ?>" <?php echo $paymentMethodId === (int)$pm['id'] ? 'selected' : ''; ?>>
                                                        <?php echo h($pm['method_name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="col-md-8 mb-3">
                                            <label class="form-label">Notes</label>
                                            <input type="text" name="notes" class="form-control" value="<?php echo h($notes); ?>">
                                        </div>
                                    </div>

                                    <div class="row justify-content-end">
                                        <div class="col-md-4">
                                            <table class="table table-bordered purchase-summary-table">
                                                <tr>
                                                    <th>Subtotal</th>
                                                    <td><input type="number" step="0.01" class="form-control" id="subtotal" readonly></td>
                                                </tr>
                                                <tr>
                                                    <th>Discount</th>
                                                    <td><input type="number" step="0.01" min="0" name="discount_amount" id="discount_amount" class="form-control" value="<?php echo h($discountAmount); ?>"></td>
                                                </tr>
                                                <tr>
                                                    <th>Taxable</th>
                                                    <td><input type="number" step="0.01" class="form-control" id="taxable_total" readonly></td>
                                                </tr>
                                                <tr>
                                                    <th>GST Total</th>
                                                    <td><input type="number" step="0.01" class="form-control" id="gst_total" readonly></td>
                                                </tr>
                                                <tr>
                                                    <th>Round Off</th>
                                                    <td><input type="number" step="0.01" name="round_off" id="round_off" class="form-control" value="<?php echo h($roundOff); ?>"></td>
                                                </tr>
                                                <tr>
                                                    <th>Grand Total</th>
                                                    <td><input type="number" step="0.01" class="form-control" id="grand_total" readonly></td>
                                                </tr>
                                                <tr>
                                                    <th>Paid Amount</th>
                                                    <td><input type="number" step="0.01" min="0" name="paid_amount" id="paid_amount" class="form-control" value="<?php echo h($paidAmount); ?>"></td>
                                                </tr>
                                                <tr>
                                                    <th>Balance</th>
                                                    <td><input type="number" step="0.01" class="form-control" id="balance_amount" readonly></td>
                                                </tr>
                                            </table>
                                        </div>
                                    </div>

                                    <div class="d-flex gap-2">
                                        <button type="submit" class="btn btn-primary">Save Purchase</button>
                                        <a href="purchases.php" class="btn btn-secondary">Back to Purchases</a>
                                    </div>
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

<script>
(function () {
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
        let html = '<option value="">Select</option>';
        products.forEach(function (p) {
            const selected = String(selectedId) === String(p.id) ? 'selected' : '';
            html += `<option value="${p.id}" data-name="${escapeHtml(p.product_name)}" data-purity="${escapeHtml(p.purity)}" data-rate="${p.purchase_rate}" data-weight="${p.net_weight}" ${selected}>${escapeHtml(p.product_name)} (${escapeHtml(p.product_code)})</option>`;
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
            <td><input type="text" name="items[${rowIndex}][item_name]" class="form-control item-name" value="${item.item_name || ''}"></td>
            <td><input type="text" name="items[${rowIndex}][purity]" class="form-control purity" value="${item.purity || '925'}"></td>
            <td><input type="text" name="items[${rowIndex}][hsn_code]" class="form-control hsn-code" value="${item.hsn_code || ''}"></td>
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
            <td><button type="button" class="btn btn-danger btn-sm remove-row">X</button></td>
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
        if (net < 0) net = 0;
        tr.querySelector('.net-weight').value = net.toFixed(3);

        const netWeight = parseFloat(tr.querySelector('.net-weight').value || 0);
        const rate = parseFloat(tr.querySelector('.rate-per-gram').value || 0);
        const making = parseFloat(tr.querySelector('.making-charge').value || 0);
        const stone = parseFloat(tr.querySelector('.stone-charge').value || 0);
        const discount = parseFloat(tr.querySelector('.item-discount').value || 0);
        const gstPercent = parseFloat(tr.querySelector('.gst-percent').value || 0);

        const itemAmount = (netWeight * rate) + making + stone;
        let taxable = itemAmount - discount;
        if (taxable < 0) taxable = 0;

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
        if (finalTaxable < 0) finalTaxable = 0;

        const grandTotal = finalTaxable + gstTotal + roundOff;
        let balance = grandTotal - paidAmount;
        if (balance < 0) balance = 0;

        document.getElementById('subtotal').value = subtotal.toFixed(2);
        document.getElementById('taxable_total').value = finalTaxable.toFixed(2);
        document.getElementById('gst_total').value = gstTotal.toFixed(2);
        document.getElementById('grand_total').value = grandTotal.toFixed(2);
        document.getElementById('balance_amount').value = balance.toFixed(2);
    }

    function bindRow(tr) {
        const productSelect = tr.querySelector('.product-select');

        if (productSelect) {
            productSelect.addEventListener('change', function () {
                const opt = this.options[this.selectedIndex];
                if (!opt || !opt.value) {
                    return;
                }

                const name = opt.getAttribute('data-name') || '';
                const purity = opt.getAttribute('data-purity') || '925';
                const rate = parseFloat(opt.getAttribute('data-rate') || 0);
                const weight = parseFloat(opt.getAttribute('data-weight') || 0);

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

        const removeBtn = tr.querySelector('.remove-row');
        if (removeBtn) {
            removeBtn.addEventListener('click', function () {
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
        const el = document.getElementById(id);
        if (el) {
            el.addEventListener('input', calculateAll);
        }
    });

    calculateAll();
})();
</script>

</body>
</html>