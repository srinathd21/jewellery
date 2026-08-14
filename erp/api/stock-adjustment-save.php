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
    echo json_encode(
        array_merge(['success' => $success, 'message' => $message], $extra),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function tableHasColumn(mysqli $conn, string $table, string $column): bool
{
    static $cache = [];
    $key = strtolower($table . '.' . $column);

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $safeTable = $conn->real_escape_string($table);
    $safeColumn = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    $cache[$key] = (bool)($result && $result->num_rows > 0);
    return $cache[$key];
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

if (!hash_equals(
    (string)($_SESSION['stock_adjustment_csrf'] ?? ''),
    (string)($_POST['csrf_token'] ?? '')
)) {
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
$reasonType = trim((string)($_POST['reason_type'] ?? 'Adjustment'));
$remarks = trim((string)($_POST['remarks'] ?? ''));
$movementDateInput = trim((string)($_POST['movement_date'] ?? ''));

if (!in_array($mode, ['add', 'subtract', 'set'], true)) {
    responseJson(false, 'Invalid adjustment mode.', [], 422);
}

if ($productId <= 0) {
    responseJson(false, 'Select a product.', [], 422);
}

if ($qty < 0) {
    responseJson(false, 'Quantity cannot be negative.', [], 422);
}

if ($mode !== 'set' && $qty <= 0) {
    responseJson(false, 'Enter an adjustment quantity greater than zero.', [], 422);
}

if ($reasonType === '') {
    responseJson(false, 'Select a reason type.', [], 422);
}

/* Remarks are intentionally optional. */

$movementTimestamp = date('Y-m-d H:i:s');
if ($movementDateInput !== '') {
    $parsed = strtotime($movementDateInput);
    if ($parsed === false) {
        responseJson(false, 'Invalid movement date and time.', [], 422);
    }
    $movementTimestamp = date('Y-m-d H:i:s', $parsed);
}

if (!tableHasColumn($conn, 'products', 'net_weight')) {
    responseJson(false, 'Products table does not contain net_weight.', [], 500);
}

$conn->begin_transaction();

try {
    /*
     * products.net_weight = NET WEIGHT PER QUANTITY.
     * Total stock weight = quantity x products.net_weight.
     */
    $productStmt = $conn->prepare(
        'SELECT id, product_name, purchase_rate, track_stock, COALESCE(net_weight,0) AS unit_net_weight
         FROM products
         WHERE id=? AND business_id=? AND is_active=1
         LIMIT 1 FOR UPDATE'
    );

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

    $unitNetWeight = round(max(0, (float)($product['unit_net_weight'] ?? 0)), 3);

    if ($unitNetWeight <= 0) {
        throw new RuntimeException('Selected product does not have a valid net weight in the products table.');
    }

    $ensureStmt = $conn->prepare(
        'INSERT INTO product_stock
         (business_id,branch_id,product_id,quantity,gross_weight,net_weight,average_cost,stock_value)
         VALUES (?,?,?,0,0,0,0,0)
         ON DUPLICATE KEY UPDATE product_id=VALUES(product_id)'
    );

    if (!$ensureStmt) {
        throw new RuntimeException('Unable to prepare stock-row creation: ' . $conn->error);
    }

    $ensureStmt->bind_param('iii', $businessId, $branchId, $productId);
    if (!$ensureStmt->execute()) {
        throw new RuntimeException('Unable to create stock row: ' . $ensureStmt->error);
    }
    $ensureStmt->close();

    $stockStmt = $conn->prepare(
        'SELECT id,quantity,gross_weight,net_weight,average_cost,stock_value
         FROM product_stock
         WHERE business_id=? AND branch_id=? AND product_id=?
         LIMIT 1 FOR UPDATE'
    );

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

    $oldQty = round(max(0, (float)$old['quantity']), 3);

    /*
     * Recalculate current total weight from the current quantity and
     * products.net_weight so stale values in product_stock do not affect
     * this adjustment.
     */
    $oldTotalWeight = round($oldQty * $unitNetWeight, 3);
    $oldAverageCost = round((float)$old['average_cost'], 2);

    $qtyIn = 0.0;
    $qtyOut = 0.0;
    $weightIn = 0.0;
    $weightOut = 0.0;

    if ($mode === 'add') {
        $newQty = $oldQty + $qty;
        $qtyIn = $qty;
        $weightIn = round($qty * $unitNetWeight, 3);
        $movementType = 'Adjustment In';
        $modeLabel = 'Add Stock';
    } elseif ($mode === 'subtract') {
        if ($qty > $oldQty) {
            throw new RuntimeException(
                'Only ' . number_format($oldQty, 3) . ' quantity is available.'
            );
        }

        $newQty = $oldQty - $qty;
        $qtyOut = $qty;
        $weightOut = round($qty * $unitNetWeight, 3);
        $movementType = 'Adjustment Out';
        $modeLabel = 'Subtract Stock';
    } else {
        /* Set Exact Stock: posted quantity is the desired final quantity. */
        $newQty = $qty;
        $qtyDiff = round($newQty - $oldQty, 3);
        $weightDiff = round($qtyDiff * $unitNetWeight, 3);

        if ($qtyDiff >= 0) {
            $qtyIn = $qtyDiff;
            $weightIn = max(0, $weightDiff);
        } else {
            $qtyOut = abs($qtyDiff);
            $weightOut = abs($weightDiff);
        }

        $movementType = $qtyDiff >= 0 ? 'Adjustment In' : 'Adjustment Out';
        $modeLabel = 'Set Exact Stock';
    }

    $newQty = round(max(0, $newQty), 3);
    $newTotalWeight = round($newQty * $unitNetWeight, 3);

    /*
     * product_stock.net_weight remains TOTAL stock net weight for compatibility
     * with billing/stock modules. The per-piece net weight always lives in
     * products.net_weight.
     *
     * In this stock model Gross/Total Weight is also quantity x unit net weight.
     */
    $newGrossWeight = $newTotalWeight;
    $newNetWeight = $newTotalWeight;

    $purchaseRate = round((float)($product['purchase_rate'] ?? 0), 2);
    $newAverageCost = $oldAverageCost > 0 ? $oldAverageCost : $purchaseRate;
    $valuationBase = $newTotalWeight > 0 ? $newTotalWeight : $newQty;
    $newStockValue = round($valuationBase * $newAverageCost, 2);

    $updateStmt = $conn->prepare(
        'UPDATE product_stock
         SET quantity=?, gross_weight=?, net_weight=?, average_cost=?, stock_value=?, updated_at=CURRENT_TIMESTAMP
         WHERE business_id=? AND branch_id=? AND product_id=?'
    );

    if (!$updateStmt) {
        throw new RuntimeException('Unable to prepare stock update: ' . $conn->error);
    }

    $updateStmt->bind_param(
        'dddddiii',
        $newQty,
        $newGrossWeight,
        $newNetWeight,
        $newAverageCost,
        $newStockValue,
        $businessId,
        $branchId,
        $productId
    );

    if (!$updateStmt->execute()) {
        throw new RuntimeException('Unable to update product stock: ' . $updateStmt->error);
    }
    $updateStmt->close();

    $changeQty = round($newQty - $oldQty, 3);
    $changeWeight = round($newTotalWeight - $oldTotalWeight, 3);

    $detailParts = [
        'Stock Adjustment',
        'Mode: ' . $modeLabel,
        'Unit Net Weight: ' . number_format($unitNetWeight, 3, '.', ''),
        'Previous Qty: ' . number_format($oldQty, 3, '.', ''),
        'Change Qty: ' . number_format($changeQty, 3, '.', ''),
        'New Qty: ' . number_format($newQty, 3, '.', ''),
        'Previous Weight: ' . number_format($oldTotalWeight, 3, '.', ''),
        'Change Weight: ' . number_format($changeWeight, 3, '.', ''),
        'New Weight: ' . number_format($newTotalWeight, 3, '.', ''),
        'Reason Type: ' . $reasonType
    ];

    if ($remarks !== '') {
        $detailParts[] = 'Reason: ' . $remarks;
    }

    $detailRemarks = implode(' | ', $detailParts);

    $rate = $newAverageCost;
    $movementWeight = round($weightIn + $weightOut, 3);
    $movementBase = $movementWeight > 0 ? $movementWeight : ($qtyIn + $qtyOut);
    $movementValue = round($movementBase * $rate, 2);
    $referenceTable = 'stock_adjustment';

    $movementStmt = $conn->prepare(
        'INSERT INTO stock_movements
         (business_id,branch_id,product_id,movement_date,movement_type,reference_table,reference_id,
          quantity_in,quantity_out,weight_in,weight_out,rate,value_amount,remarks,created_by)
         VALUES (?,?,?,?,?,?,NULL,?,?,?,?,?,?,?,?)'
    );

    if (!$movementStmt) {
        throw new RuntimeException('Unable to prepare movement insert: ' . $conn->error);
    }

    $movementStmt->bind_param(
        'iiisssddddddsi',
        $businessId,
        $branchId,
        $productId,
        $movementTimestamp,
        $movementType,
        $referenceTable,
        $qtyIn,
        $qtyOut,
        $weightIn,
        $weightOut,
        $rate,
        $movementValue,
        $detailRemarks,
        $userId
    );

    if (!$movementStmt->execute()) {
        throw new RuntimeException('Unable to save stock movement: ' . $movementStmt->error);
    }

    $movementId = (int)$movementStmt->insert_id;
    $movementStmt->close();

    $referenceStmt = $conn->prepare(
        'UPDATE stock_movements SET reference_id=? WHERE id=? AND business_id=?'
    );

    if ($referenceStmt) {
        $referenceStmt->bind_param('iii', $movementId, $movementId, $businessId);
        $referenceStmt->execute();
        $referenceStmt->close();
    }

    $conn->commit();

    responseJson(true, 'Stock adjustment saved successfully.', [
        'product_id' => $productId,
        'movement_id' => $movementId,
        'unit_net_weight' => $unitNetWeight,
        'current_stock' => [
            'quantity' => $newQty,
            'gross_weight' => $newGrossWeight,
            'net_weight' => $newNetWeight,
            'unit_net_weight' => $unitNetWeight,
            'average_cost' => $newAverageCost,
            'stock_value' => $newStockValue
        ]
    ]);
} catch (Throwable $e) {
    $conn->rollback();
    responseJson(false, $e->getMessage(), [], 500);
}
