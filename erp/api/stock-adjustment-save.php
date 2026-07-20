<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

foreach ([
    dirname(__DIR__) . '/config/config.php',
    dirname(__DIR__) . '/config.php',
    dirname(__DIR__) . '/includes/config.php',
    dirname(__DIR__) . '/super-admin/includes/config.php'
] as $file) {
    if (is_file($file)) {
        require_once $file;
        break;
    }
}

function responseJson(bool $success, string $message, array $extra = [], int $code = 200): void
{
    http_response_code($code);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    responseJson(false, 'Database configuration is not available.', [], 500);
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn->set_charset('utf8mb4');

if (empty($_SESSION['user_id'])) {
    responseJson(false, 'Session expired.', [], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responseJson(false, 'Invalid request method.', [], 405);
}

if (!hash_equals((string)($_SESSION['stock_adjustment_csrf'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) {
    responseJson(false, 'Invalid security token.', [], 419);
}

$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? ($_SESSION['default_branch_id'] ?? 0));
$userId = (int)($_SESSION['user_id'] ?? 0);

if ($businessId <= 0 || $branchId <= 0) {
    responseJson(false, 'Business or branch session is missing.', [], 403);
}

$productId = (int)($_POST['product_id'] ?? 0);
$mode = trim((string)($_POST['adjustment_mode'] ?? 'add'));
$qty = round((float)($_POST['adjustment_qty'] ?? 0), 3);
$weight = round((float)($_POST['adjustment_weight'] ?? 0), 3);
$reasonType = trim((string)($_POST['reason_type'] ?? 'Adjustment'));
$remarks = trim((string)($_POST['remarks'] ?? ''));
$movementDateInput = trim((string)($_POST['movement_date'] ?? ''));

if (!in_array($mode, ['add', 'subtract', 'set'], true)) {
    responseJson(false, 'Invalid adjustment mode.', [], 422);
}
if ($productId <= 0) {
    responseJson(false, 'Select a product.', [], 422);
}
if ($qty < 0 || $weight < 0) {
    responseJson(false, 'Quantity and weight cannot be negative.', [], 422);
}
if ($mode !== 'set' && $qty <= 0 && $weight <= 0) {
    responseJson(false, 'Enter quantity or weight.', [], 422);
}
if ($reasonType === '') {
    responseJson(false, 'Select a reason type.', [], 422);
}
if ($remarks === '') {
    responseJson(false, 'Enter a clear reason or remarks.', [], 422);
}

$movementTimestamp = date('Y-m-d H:i:s');
if ($movementDateInput !== '') {
    $parsed = strtotime($movementDateInput);
    if ($parsed === false) {
        responseJson(false, 'Invalid movement date and time.', [], 422);
    }
    $movementTimestamp = date('Y-m-d H:i:s', $parsed);
}

$conn->begin_transaction();

try {
    $productStmt = $conn->prepare('SELECT id, product_name, purchase_rate, track_stock FROM products WHERE id=? AND business_id=? AND is_active=1 LIMIT 1 FOR UPDATE');
    if (!$productStmt) {
        throw new RuntimeException('Unable to prepare product query: ' . $conn->error);
    }
    $productStmt->bind_param('ii', $productId, $businessId);
    if (!$productStmt->execute()) {
        throw new RuntimeException('Unable to validate product: ' . $productStmt->error);
    }
    $product = $productStmt->get_result()->fetch_assoc();
    $productStmt->close();

    if (!$product) {
        throw new RuntimeException('Product not found or inactive.');
    }
    if ((int)($product['track_stock'] ?? 1) !== 1) {
        throw new RuntimeException('Stock tracking is disabled for this product.');
    }

    $ensureStmt = $conn->prepare('INSERT INTO product_stock (business_id,branch_id,product_id,quantity,gross_weight,net_weight,average_cost,stock_value) VALUES (?,?,?,0,0,0,0,0) ON DUPLICATE KEY UPDATE product_id=VALUES(product_id)');
    if (!$ensureStmt) {
        throw new RuntimeException('Unable to prepare stock-row creation: ' . $conn->error);
    }
    $ensureStmt->bind_param('iii', $businessId, $branchId, $productId);
    if (!$ensureStmt->execute()) {
        throw new RuntimeException('Unable to create stock row: ' . $ensureStmt->error);
    }
    $ensureStmt->close();

    $stockStmt = $conn->prepare('SELECT id,quantity,gross_weight,net_weight,average_cost,stock_value FROM product_stock WHERE business_id=? AND branch_id=? AND product_id=? LIMIT 1 FOR UPDATE');
    if (!$stockStmt) {
        throw new RuntimeException('Unable to prepare current stock query: ' . $conn->error);
    }
    $stockStmt->bind_param('iii', $businessId, $branchId, $productId);
    if (!$stockStmt->execute()) {
        throw new RuntimeException('Unable to read current stock: ' . $stockStmt->error);
    }
    $old = $stockStmt->get_result()->fetch_assoc();
    $stockStmt->close();

    if (!$old) {
        throw new RuntimeException('Unable to initialise product stock.');
    }

    $oldQty = round((float)$old['quantity'], 3);
    $oldGrossWeight = round((float)$old['gross_weight'], 3);
    $oldNetWeight = round((float)$old['net_weight'], 3);
    $oldAverageCost = round((float)$old['average_cost'], 2);

    $qtyIn = $qtyOut = $weightIn = $weightOut = 0.0;

    if ($mode === 'add') {
        $newQty = $oldQty + $qty;
        $newNetWeight = $oldNetWeight + $weight;
        $newGrossWeight = $oldGrossWeight + $weight;
        $qtyIn = $qty;
        $weightIn = $weight;
        $movementType = 'Adjustment In';
        $modeLabel = 'Add Stock';
    } elseif ($mode === 'subtract') {
        if ($qty > $oldQty) {
            throw new RuntimeException('Only ' . number_format($oldQty, 3) . ' quantity is available.');
        }
        if ($weight > $oldNetWeight) {
            throw new RuntimeException('Only ' . number_format($oldNetWeight, 3) . ' net weight is available.');
        }
        $newQty = $oldQty - $qty;
        $newNetWeight = $oldNetWeight - $weight;
        $grossReduction = ($oldNetWeight > 0 && $weight > 0) ? ($oldGrossWeight * ($weight / $oldNetWeight)) : $weight;
        $newGrossWeight = max(0, $oldGrossWeight - $grossReduction);
        $qtyOut = $qty;
        $weightOut = $weight;
        $movementType = 'Adjustment Out';
        $modeLabel = 'Subtract Stock';
    } else {
        $newQty = $qty;
        $newNetWeight = $weight;
        $newGrossWeight = $weight;
        $qtyDiff = $newQty - $oldQty;
        $weightDiff = $newNetWeight - $oldNetWeight;
        if ($qtyDiff >= 0) $qtyIn = $qtyDiff; else $qtyOut = abs($qtyDiff);
        if ($weightDiff >= 0) $weightIn = $weightDiff; else $weightOut = abs($weightDiff);
        $movementType = ($qtyDiff >= 0 && $weightDiff >= 0) ? 'Adjustment In' : 'Adjustment Out';
        $modeLabel = 'Set Exact Stock';
    }

    $newQty = round(max(0, $newQty), 3);
    $newGrossWeight = round(max(0, $newGrossWeight), 3);
    $newNetWeight = round(max(0, $newNetWeight), 3);

    $purchaseRate = round((float)($product['purchase_rate'] ?? 0), 2);
    $newAverageCost = $oldAverageCost > 0 ? $oldAverageCost : $purchaseRate;
    $valuationBase = $newNetWeight > 0 ? $newNetWeight : $newQty;
    $newStockValue = round($valuationBase * $newAverageCost, 2);

    $updateStmt = $conn->prepare('UPDATE product_stock SET quantity=?,gross_weight=?,net_weight=?,average_cost=?,stock_value=?,updated_at=CURRENT_TIMESTAMP WHERE business_id=? AND branch_id=? AND product_id=?');
    if (!$updateStmt) {
        throw new RuntimeException('Unable to prepare stock update: ' . $conn->error);
    }
    $updateStmt->bind_param('dddddiii', $newQty, $newGrossWeight, $newNetWeight, $newAverageCost, $newStockValue, $businessId, $branchId, $productId);
    if (!$updateStmt->execute()) {
        throw new RuntimeException('Unable to update product stock: ' . $updateStmt->error);
    }
    $updateStmt->close();

    $detailRemarks = implode(' | ', [
        'Stock Adjustment',
        'Mode: ' . $modeLabel,
        'Previous Qty: ' . number_format($oldQty, 3, '.', ''),
        'Change Qty: ' . number_format($newQty - $oldQty, 3, '.', ''),
        'New Qty: ' . number_format($newQty, 3, '.', ''),
        'Previous Weight: ' . number_format($oldNetWeight, 3, '.', ''),
        'Change Weight: ' . number_format($newNetWeight - $oldNetWeight, 3, '.', ''),
        'New Weight: ' . number_format($newNetWeight, 3, '.', ''),
        'Reason Type: ' . $reasonType,
        'Reason: ' . $remarks
    ]);

    $rate = $newAverageCost;
    $movementBase = ($weightIn + $weightOut) > 0 ? ($weightIn + $weightOut) : ($qtyIn + $qtyOut);
    $movementValue = round($movementBase * $rate, 2);
    $referenceTable = 'stock_adjustment';

    $movementStmt = $conn->prepare('INSERT INTO stock_movements (business_id,branch_id,product_id,movement_date,movement_type,reference_table,reference_id,quantity_in,quantity_out,weight_in,weight_out,rate,value_amount,remarks,created_by) VALUES (?,?,?,?,?,?,NULL,?,?,?,?,?,?,?,?)');
    if (!$movementStmt) {
        throw new RuntimeException('Unable to prepare movement insert: ' . $conn->error);
    }
    $movementStmt->bind_param('iiisssddddddsi', $businessId, $branchId, $productId, $movementTimestamp, $movementType, $referenceTable, $qtyIn, $qtyOut, $weightIn, $weightOut, $rate, $movementValue, $detailRemarks, $userId);
    if (!$movementStmt->execute()) {
        throw new RuntimeException('Unable to save stock movement: ' . $movementStmt->error);
    }
    $movementId = (int)$movementStmt->insert_id;
    $movementStmt->close();

    $referenceStmt = $conn->prepare('UPDATE stock_movements SET reference_id=? WHERE id=? AND business_id=?');
    if ($referenceStmt) {
        $referenceStmt->bind_param('iii', $movementId, $movementId, $businessId);
        $referenceStmt->execute();
        $referenceStmt->close();
    }

    $conn->commit();

    responseJson(true, 'Stock adjustment saved successfully.', [
        'product_id' => $productId,
        'movement_id' => $movementId,
        'current_stock' => [
            'quantity' => $newQty,
            'gross_weight' => $newGrossWeight,
            'net_weight' => $newNetWeight,
            'average_cost' => $newAverageCost,
            'stock_value' => $newStockValue
        ]
    ]);
} catch (Throwable $e) {
    $conn->rollback();
    responseJson(false, $e->getMessage(), [], 500);
}