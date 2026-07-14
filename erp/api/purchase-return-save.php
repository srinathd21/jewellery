<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));
header('Content-Type: application/json; charset=utf-8');

function respond(bool $success, string $message, array $extra = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

$configCandidates = [
    dirname(__DIR__) . '/config/config.php',
    dirname(__DIR__) . '/config.php',
    dirname(__DIR__) . '/includes/config.php',
    dirname(__DIR__) . '/super-admin/includes/config.php',
];

foreach ($configCandidates as $configFile) {
    if (is_file($configFile)) {
        require_once $configFile;
        break;
    }
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    respond(false, 'Database configuration is not available.', [], 500);
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn->set_charset('utf8mb4');

if (empty($_SESSION['user_id'])) {
    respond(false, 'Your session has expired. Please log in again.', [], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Invalid request method.', [], 405);
}
if (!hash_equals((string)($_SESSION['purchase_return_csrf'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) {
    respond(false, 'Invalid or expired request token. Refresh the page and try again.', [], 419);
}

function tableExists(mysqli $conn, string $table): bool
{
    $table = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '{$table}'");
    return $res && $res->num_rows > 0;
}

function hasColumn(mysqli $conn, string $table, string $column): bool
{
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    return $res && $res->num_rows > 0;
}

function hasPermission(mysqli $conn, string $action): bool
{
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin') {
        return true;
    }

    $map = [
        'open' => 'can_open',
        'view' => 'can_view',
        'create' => 'can_create',
        'update' => 'can_update',
        'delete' => 'can_delete',
    ];

    $field = $map[$action] ?? '';
    if ($field === '') {
        return false;
    }

    $permissions = $_SESSION['permissions'] ?? [];
    foreach (['perm.purchases.returns', 'perm.purchases'] as $key) {
        if (isset($permissions[$key][$field])) {
            return (int)$permissions[$key][$field] === 1;
        }
    }

    $businessId = (int)($_SESSION['business_id'] ?? 0);
    $roleId = (int)($_SESSION['role_id'] ?? 0);
    if ($businessId <= 0 || $roleId <= 0) {
        $roleName = strtolower(trim((string)($_SESSION['role_name'] ?? '')));
        return in_array($roleName, ['admin', 'manager', 'stock'], true);
    }

    $sql = "SELECT rp.`{$field}`
            FROM role_permissions rp
            INNER JOIN permissions p ON p.id = rp.permission_id
            WHERE rp.business_id = ?
              AND rp.role_id = ?
              AND p.is_active = 1
              AND p.permission_code IN ('perm.purchases.returns','perm.purchases')
            ORDER BY FIELD(p.permission_code,'perm.purchases.returns','perm.purchases')
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ii', $businessId, $roleId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row[$field] ?? 0) === 1;
}

function audit(mysqli $conn, int $businessId, int $branchId, int $userId, int $referenceId, string $returnNo, array $newValues): void
{
    if (!tableExists($conn, 'audit_logs')) {
        return;
    }

    $newJson = json_encode($newValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $agent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

    $stmt = $conn->prepare("INSERT INTO audit_logs
        (business_id, branch_id, user_id, module_code, action_type, reference_table, reference_id, description, new_values_json, ip_address, user_agent)
        VALUES (?, ?, ?, 'purchases.returns', 'Create', 'purchase_returns', ?, ?, ?, ?, ?, ?)");

    if ($stmt) {
        $description = 'Created purchase return ' . $returnNo;
        $stmt->bind_param('iiiisssss', $businessId, $branchId, $userId, $referenceId, $description, $newJson, $ip, $agent);
        $stmt->execute();
        $stmt->close();
    }
}

if (!hasPermission($conn, 'create')) {
    respond(false, 'You do not have permission to create purchase returns.', [], 403);
}

$businessId = (int)($_SESSION['business_id'] ?? 0);
$userId = (int)($_SESSION['user_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? 0);

if ($businessId <= 0 || $branchId <= 0) {
    respond(false, 'A valid business and branch must be selected.', [], 403);
}

$returnNo = trim((string)($_POST['return_no'] ?? ''));
$returnDate = trim((string)($_POST['return_date'] ?? ''));
$purchaseId = (int)($_POST['purchase_id'] ?? 0);
$notes = trim((string)($_POST['notes'] ?? ''));
$postedItems = $_POST['items'] ?? [];

if ($returnNo === '') {
    respond(false, 'Return number is required.');
}
if ($returnDate === '') {
    respond(false, 'Return date is required.');
}
if ($purchaseId <= 0) {
    respond(false, 'Please select a purchase.');
}
if (!is_array($postedItems) || !$postedItems) {
    respond(false, 'No return items were submitted.');
}
if (mb_strlen($notes) > 1000) {
    respond(false, 'Notes must not exceed 1000 characters.');
}

$stmt = $conn->prepare(
    "SELECT p.*, s.supplier_name
     FROM purchases p
     LEFT JOIN suppliers s
        ON s.id = p.supplier_id
       AND s.business_id = p.business_id
     WHERE p.id = ?
       AND p.business_id = ?
       AND p.branch_id = ?
     LIMIT 1"
);

if (!$stmt) {
    respond(false, 'Unable to validate the selected purchase: ' . $conn->error, [], 500);
}

$stmt->bind_param('iii', $purchaseId, $businessId, $branchId);
$stmt->execute();
$purchase = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$purchase) {
    respond(false, 'Purchase not found.', [], 404);
}

$stmt = $conn->prepare("SELECT id FROM purchase_returns WHERE business_id = ? AND return_no = ? LIMIT 1");
$stmt->bind_param('is', $businessId, $returnNo);
$stmt->execute();
$duplicate = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($duplicate) {
    respond(false, 'This return number is already used.', [], 409);
}

$cleanItems = [];
$subtotal = 0.0;
$gstTotal = 0.0;
$grandTotal = 0.0;

foreach ($postedItems as $index => $item) {
    $purchaseItemId = (int)($item['purchase_item_id'] ?? 0);
    $productId = (int)($item['product_id'] ?? 0);
    $itemName = trim((string)($item['item_name'] ?? ''));
    $purchasedQty = (float)($item['qty'] ?? 0);
    $purchasedWeight = (float)($item['net_weight'] ?? 0);
    $rate = (float)($item['rate_per_gram'] ?? 0);
    $gstPercent = (float)($item['gst_percent'] ?? 0);
    $returnQty = (float)($item['return_qty'] ?? 0);
    $returnWeight = (float)($item['return_weight'] ?? 0);

    if ($returnQty <= 0 && $returnWeight <= 0) {
        continue;
    }
    if ($returnQty < 0 || $returnWeight < 0) {
        respond(false, 'Return quantity and weight cannot be negative.');
    }
    if ($returnQty > $purchasedQty) {
        respond(false, 'Return quantity exceeds purchased quantity for ' . $itemName . '.');
    }
    if ($returnWeight > $purchasedWeight) {
        respond(false, 'Return weight exceeds purchased weight for ' . $itemName . '.');
    }
    if ($returnWeight <= 0 && $purchasedQty > 0 && $purchasedWeight > 0 && $returnQty > 0) {
        $returnWeight = ($purchasedWeight / $purchasedQty) * $returnQty;
    }

    $taxable = $returnWeight * $rate;
    $gst = ($taxable * $gstPercent) / 100;
    $total = $taxable + $gst;

    $subtotal += $taxable;
    $gstTotal += $gst;
    $grandTotal += $total;

    $cleanItems[] = [
        'purchase_item_id' => $purchaseItemId,
        'product_id' => $productId,
        'item_name' => $itemName,
        'qty' => $returnQty,
        'net_weight' => $returnWeight,
        'rate_per_gram' => $rate,
        'taxable_amount' => $taxable,
        'gst_percent' => $gstPercent,
        'gst_amount' => $gst,
        'total_amount' => $total,
    ];
}

if (!$cleanItems) {
    respond(false, 'Please enter at least one return item.');
}

$productsExists = tableExists($conn, 'products');
$productStockExists = tableExists($conn, 'product_stock');
$stockMovementsExists = tableExists($conn, 'stock_movements');

$conn->begin_transaction();

try {
    $supplierId = (int)($purchase['supplier_id'] ?? 0);
    $stmt = $conn->prepare("INSERT INTO purchase_returns
        (business_id, return_no, return_date, purchase_id, supplier_id, subtotal, gst_amount, total_amount, notes, created_by, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
    if (!$stmt) {
        throw new RuntimeException('Failed to prepare purchase return.');
    }

    $stmt->bind_param('issiidddsi', $businessId, $returnNo, $returnDate, $purchaseId, $supplierId, $subtotal, $gstTotal, $grandTotal, $notes, $userId);
    if (!$stmt->execute()) {
        throw new RuntimeException('Failed to save purchase return.');
    }
    $purchaseReturnId = (int)$stmt->insert_id;
    $stmt->close();

    foreach ($cleanItems as $row) {
        $stmt = $conn->prepare("INSERT INTO purchase_return_items
            (business_id, purchase_return_id, purchase_item_id, product_id, item_name, qty, net_weight, rate_per_gram, taxable_amount, gst_percent, gst_amount, total_amount)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) {
            throw new RuntimeException('Failed to prepare purchase return item.');
        }

        $stmt->bind_param(
            'iiiisddddddd',
            $businessId,
            $purchaseReturnId,
            $row['purchase_item_id'],
            $row['product_id'],
            $row['item_name'],
            $row['qty'],
            $row['net_weight'],
            $row['rate_per_gram'],
            $row['taxable_amount'],
            $row['gst_percent'],
            $row['gst_amount'],
            $row['total_amount']
        );

        if (!$stmt->execute()) {
            throw new RuntimeException('Failed to save purchase return item.');
        }
        $stmt->close();

        $productId = (int)$row['product_id'];
        if ($productId <= 0) {
            continue;
        }

        if ($productsExists && hasColumn($conn, 'products', 'current_stock_qty')) {
            if (hasColumn($conn, 'products', 'business_id')) {
                $stmt = $conn->prepare("UPDATE products SET current_stock_qty = GREATEST(COALESCE(current_stock_qty,0) - ?,0) WHERE id = ? AND business_id = ?");
                $stmt->bind_param('dii', $row['qty'], $productId, $businessId);
            } else {
                $stmt = $conn->prepare("UPDATE products SET current_stock_qty = GREATEST(COALESCE(current_stock_qty,0) - ?,0) WHERE id = ?");
                $stmt->bind_param('di', $row['qty'], $productId);
            }
            if (!$stmt->execute()) {
                throw new RuntimeException('Failed to update product stock.');
            }
            $stmt->close();
        }

        if ($productStockExists && hasColumn($conn, 'product_stock', 'product_id')) {
            $updates = [];
            $values = [];
            $types = '';

            foreach ([
                'out_qty' => $row['qty'],
                'out_weight' => $row['net_weight'],
            ] as $column => $value) {
                if (hasColumn($conn, 'product_stock', $column)) {
                    $updates[] = "{$column} = COALESCE({$column},0) + ?";
                    $types .= 'd';
                    $values[] = $value;
                }
            }

            foreach ([
                'closing_qty' => $row['qty'],
                'closing_weight' => $row['net_weight'],
            ] as $column => $value) {
                if (hasColumn($conn, 'product_stock', $column)) {
                    $updates[] = "{$column} = GREATEST(COALESCE({$column},0) - ?,0)";
                    $types .= 'd';
                    $values[] = $value;
                }
            }

            if ($updates) {
                $sql = "UPDATE product_stock SET " . implode(', ', $updates) . " WHERE product_id = ?";
                $types .= 'i';
                $values[] = $productId;

                if (hasColumn($conn, 'product_stock', 'business_id')) {
                    $sql .= " AND business_id = ?";
                    $types .= 'i';
                    $values[] = $businessId;
                }

                $stmt = $conn->prepare($sql);
                if (!$stmt) {
                    throw new RuntimeException('Failed to prepare product stock update.');
                }
                $bind = [$types];
                foreach ($values as $key => $value) {
                    $bind[] = &$values[$key];
                }
                call_user_func_array([$stmt, 'bind_param'], $bind);
                if (!$stmt->execute()) {
                    throw new RuntimeException('Failed to update product stock summary.');
                }
                $stmt->close();
            }
        }

        if ($stockMovementsExists && hasColumn($conn, 'stock_movements', 'product_id')) {
            $fields = [];
            $placeholders = [];
            $values = [];
            $types = '';

            $columnValues = [
                'business_id' => ['i', $businessId],
                'branch_id' => ['i', $branchId],
                'product_id' => ['i', $productId],
                'movement_type' => ['s', 'Purchase Return'],
                'ref_table' => ['s', 'purchase_returns'],
                'ref_id' => ['i', $purchaseReturnId],
                'qty_out' => ['d', $row['qty']],
                'weight_out' => ['d', $row['net_weight']],
                'remarks' => ['s', 'Purchase return entry'],
                'created_by' => ['i', $userId],
            ];

            foreach ($columnValues as $column => [$type, $value]) {
                if (hasColumn($conn, 'stock_movements', $column)) {
                    $fields[] = $column;
                    $placeholders[] = '?';
                    $types .= $type;
                    $values[] = $value;
                }
            }

            if (hasColumn($conn, 'stock_movements', 'movement_date')) {
                $fields[] = 'movement_date';
                $placeholders[] = 'NOW()';
            }

            if ($fields) {
                $sql = "INSERT INTO stock_movements (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
                $stmt = $conn->prepare($sql);
                if (!$stmt) {
                    throw new RuntimeException('Failed to prepare stock movement.');
                }
                if ($values) {
                    $bind = [$types];
                    foreach ($values as $key => $value) {
                        $bind[] = &$values[$key];
                    }
                    call_user_func_array([$stmt, 'bind_param'], $bind);
                }
                if (!$stmt->execute()) {
                    throw new RuntimeException('Failed to add stock movement.');
                }
                $stmt->close();
            }
        }
    }

    audit($conn, $businessId, $branchId, $userId, $purchaseReturnId, $returnNo, [
        'return_no' => $returnNo,
        'return_date' => $returnDate,
        'purchase_id' => $purchaseId,
        'supplier_id' => $supplierId,
        'subtotal' => $subtotal,
        'gst_amount' => $gstTotal,
        'total_amount' => $grandTotal,
        'items' => $cleanItems,
    ]);

    $conn->commit();
    respond(true, 'Purchase return created successfully.', [
        'purchase_return_id' => $purchaseReturnId,
        'purchase_id' => $purchaseId,
    ]);
} catch (Throwable $e) {
    $conn->rollback();
    respond(false, $e->getMessage(), [], 500);
}
