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

function responseJson($success, $message, $extra = [], $code = 200)
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

$itemsJson = (string)($_POST['items_json'] ?? '[]');
$items = json_decode($itemsJson, true);
$reasonType = trim((string)($_POST['reason_type'] ?? 'Bulk Stock Add'));
$remarks = trim((string)($_POST['remarks'] ?? ''));
$movementDateInput = trim((string)($_POST['movement_date'] ?? ''));

if (!is_array($items) || empty($items)) {
    responseJson(false, 'Add at least one product to the cart.', [], 422);
}
if (count($items) > 200) {
    responseJson(false, 'Maximum 200 products can be saved in one bulk adjustment.', [], 422);
}
if ($reasonType === '') {
    $reasonType = 'Bulk Stock Add';
}

$movementTimestamp = date('Y-m-d H:i:s');
if ($movementDateInput !== '') {
    $parsed = strtotime($movementDateInput);
    if ($parsed === false) {
        responseJson(false, 'Invalid movement date and time.', [], 422);
    }
    $movementTimestamp = date('Y-m-d H:i:s', $parsed);
}

// Normalize duplicate products in the cart into one quantity.
$normalized = [];
foreach ($items as $item) {
    $productId = (int)($item['product_id'] ?? 0);
    $qty = round((float)($item['qty'] ?? 0), 3);
    if ($productId <= 0 || $qty <= 0) {
        continue;
    }
    if (!isset($normalized[$productId])) {
        $normalized[$productId] = 0.0;
    }
    $normalized[$productId] = round($normalized[$productId] + $qty, 3);
}
if (empty($normalized)) {
    responseJson(false, 'Enter a quantity greater than zero for at least one product.', [], 422);
}

$conn->begin_transaction();
try {
    $saved = [];

    foreach ($normalized as $productId => $addQty) {
        $productStmt = $conn->prepare(
            "SELECT id,product_name,product_code,purchase_rate,track_stock,COALESCE(net_weight,0) AS unit_net_weight
             FROM products
             WHERE id=? AND business_id=? AND is_active=1
             LIMIT 1 FOR UPDATE"
        );
        if (!$productStmt) {
            throw new RuntimeException('Unable to prepare product query: ' . $conn->error);
        }
        $productStmt->bind_param('ii', $productId, $businessId);
        $productStmt->execute();
        $product = $productStmt->get_result()->fetch_assoc();
        $productStmt->close();

        if (!$product) {
            throw new RuntimeException('One selected product is missing or inactive. Refresh the page and try again.');
        }
        if ((int)($product['track_stock'] ?? 1) !== 1) {
            throw new RuntimeException('Stock tracking is disabled for ' . $product['product_name'] . '.');
        }

        $unitNetWeight = round(max(0, (float)$product['unit_net_weight']), 3);
        if ($unitNetWeight <= 0) {
            throw new RuntimeException($product['product_name'] . ' does not have a valid net weight.');
        }

        $ensureStmt = $conn->prepare(
            "INSERT INTO product_stock
             (business_id,branch_id,product_id,quantity,gross_weight,net_weight,average_cost,stock_value)
             VALUES (?,?,?,0,0,0,0,0)
             ON DUPLICATE KEY UPDATE product_id=VALUES(product_id)"
        );
        if (!$ensureStmt) {
            throw new RuntimeException('Unable to initialise stock for ' . $product['product_name'] . '.');
        }
        $ensureStmt->bind_param('iii', $businessId, $branchId, $productId);
        if (!$ensureStmt->execute()) {
            throw new RuntimeException('Unable to initialise stock for ' . $product['product_name'] . '.');
        }
        $ensureStmt->close();

        $stockStmt = $conn->prepare(
            "SELECT quantity,average_cost
             FROM product_stock
             WHERE business_id=? AND branch_id=? AND product_id=?
             LIMIT 1 FOR UPDATE"
        );
        if (!$stockStmt) {
            throw new RuntimeException('Unable to read stock for ' . $product['product_name'] . '.');
        }
        $stockStmt->bind_param('iii', $businessId, $branchId, $productId);
        $stockStmt->execute();
        $old = $stockStmt->get_result()->fetch_assoc();
        $stockStmt->close();
        if (!$old) {
            throw new RuntimeException('Unable to read stock for ' . $product['product_name'] . '.');
        }

        $oldQty = round(max(0, (float)$old['quantity']), 3);
        $newQty = round($oldQty + $addQty, 3);
        $oldWeight = round($oldQty * $unitNetWeight, 3);
        $addWeight = round($addQty * $unitNetWeight, 3);
        $newWeight = round($newQty * $unitNetWeight, 3);
        $oldAverageCost = round((float)$old['average_cost'], 2);
        $purchaseRate = round((float)($product['purchase_rate'] ?? 0), 2);
        $newAverageCost = $oldAverageCost > 0 ? $oldAverageCost : $purchaseRate;
        $valuationBase = $newWeight > 0 ? $newWeight : $newQty;
        $stockValue = round($valuationBase * $newAverageCost, 2);

        $updateStmt = $conn->prepare(
            "UPDATE product_stock
             SET quantity=?,gross_weight=?,net_weight=?,average_cost=?,stock_value=?,updated_at=CURRENT_TIMESTAMP
             WHERE business_id=? AND branch_id=? AND product_id=?"
        );
        if (!$updateStmt) {
            throw new RuntimeException('Unable to prepare stock update for ' . $product['product_name'] . '.');
        }
        $updateStmt->bind_param('dddddiii', $newQty, $newWeight, $newWeight, $newAverageCost, $stockValue, $businessId, $branchId, $productId);
        if (!$updateStmt->execute()) {
            throw new RuntimeException('Unable to update stock for ' . $product['product_name'] . '.');
        }
        $updateStmt->close();

        $detailParts = [
            'Bulk Stock Adjustment',
            'Mode: Add Stock',
            'Unit Net Weight: ' . number_format($unitNetWeight, 3, '.', ''),
            'Previous Qty: ' . number_format($oldQty, 3, '.', ''),
            'Change Qty: ' . number_format($addQty, 3, '.', ''),
            'New Qty: ' . number_format($newQty, 3, '.', ''),
            'Previous Weight: ' . number_format($oldWeight, 3, '.', ''),
            'Change Weight: ' . number_format($addWeight, 3, '.', ''),
            'New Weight: ' . number_format($newWeight, 3, '.', ''),
            'Reason Type: ' . $reasonType
        ];
        if ($remarks !== '') {
            $detailParts[] = 'Reason: ' . $remarks;
        }
        $detailRemarks = implode(' | ', $detailParts);
        $movementValue = round($addWeight * $newAverageCost, 2);
        $referenceTable = 'stock_adjustment';
        $movementType = 'Adjustment In';
        $zero = 0.0;

        $movementStmt = $conn->prepare(
            "INSERT INTO stock_movements
             (business_id,branch_id,product_id,movement_date,movement_type,reference_table,reference_id,
              quantity_in,quantity_out,weight_in,weight_out,rate,value_amount,remarks,created_by)
             VALUES (?,?,?,?,?,?,NULL,?,?,?,?,?,?,?,?)"
        );
        if (!$movementStmt) {
            throw new RuntimeException('Unable to prepare stock movement for ' . $product['product_name'] . '.');
        }
        $movementStmt->bind_param(
            'iiisssddddddsi',
            $businessId,
            $branchId,
            $productId,
            $movementTimestamp,
            $movementType,
            $referenceTable,
            $addQty,
            $zero,
            $addWeight,
            $zero,
            $newAverageCost,
            $movementValue,
            $detailRemarks,
            $userId
        );
        if (!$movementStmt->execute()) {
            throw new RuntimeException('Unable to save stock movement for ' . $product['product_name'] . '.');
        }
        $movementId = (int)$movementStmt->insert_id;
        $movementStmt->close();

        $refStmt = $conn->prepare('UPDATE stock_movements SET reference_id=? WHERE id=? AND business_id=?');
        if ($refStmt) {
            $refStmt->bind_param('iii', $movementId, $movementId, $businessId);
            $refStmt->execute();
            $refStmt->close();
        }

        $saved[] = [
            'product_id' => $productId,
            'product_name' => (string)$product['product_name'],
            'added_qty' => $addQty,
            'new_qty' => $newQty
        ];
    }

    $conn->commit();
    responseJson(true, count($saved) . ' product(s) stock added successfully.', [
        'saved_count' => count($saved),
        'items' => $saved
    ]);
} catch (Throwable $e) {
    $conn->rollback();
    responseJson(false, $e->getMessage(), [], 422);
}
