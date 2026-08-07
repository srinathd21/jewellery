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
            INSERT INTO audit_logs
            (business_id, user_id, module_name, action_type, reference_id, description, ip_address, user_agent, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        if ($stmt) {
            $stmt->bind_param('iississs', $businessId, $userId, $module, $action, $referenceId, $description, $ip, $ua);
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

function generateReturnNo(mysqli $conn, int $businessId): string
{
    $prefix = 'PRN' . date('Ymd');
    $running = 1;

    if (tableExists($conn, 'purchase_returns')) {
        $stmt = $conn->prepare("
            SELECT return_no
            FROM purchase_returns
            WHERE business_id = ? AND return_no LIKE ?
            ORDER BY id DESC
            LIMIT 1
        ");
        if ($stmt) {
            $like = $prefix . '%';
            $stmt->bind_param('is', $businessId, $like);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $stmt->close();

            if ($row && !empty($row['return_no']) && preg_match('/(\d{4})$/', $row['return_no'], $m)) {
                $running = ((int)$m[1]) + 1;
            }
        }
    }

    return $prefix . str_pad((string)$running, 4, '0', STR_PAD_LEFT);
}

$pageTitle = 'Purchase Return';

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

if (!tableExists($conn, 'purchase_returns') || !tableExists($conn, 'purchase_return_items')) {
    die('purchase_returns / purchase_return_items tables not found. Please run the purchase return SQL first.');
}

/* -------------------------------------------------------
   COLUMN FLAGS
------------------------------------------------------- */
$prdHasBusinessId = tableExists($conn, 'products') && hasColumn($conn, 'products', 'business_id');
$prdHasCurrentStockQty = tableExists($conn, 'products') && hasColumn($conn, 'products', 'current_stock_qty');

$productStockExists = tableExists($conn, 'product_stock');
$stockMovementsExists = tableExists($conn, 'stock_movements');

$returnNo = generateReturnNo($conn, $businessId);
$returnDate = date('Y-m-d');
$purchaseId = (int)($_GET['purchase_id'] ?? $_POST['purchase_id'] ?? 0);
$notes = trim((string)($_POST['notes'] ?? ''));
$success = '';
$error = '';

/* -------------------------------------------------------
   PURCHASE SEARCH LIST
------------------------------------------------------- */
$purchaseSearch = trim((string)($_GET['search'] ?? ''));
$purchases = [];

$sql = "
    SELECT p.id, p.purchase_no, p.purchase_date, p.invoice_no,
           s.supplier_name
    FROM purchases p
    LEFT JOIN suppliers s ON s.id = p.supplier_id
    WHERE p.business_id = ?
";
$params = [$businessId];
$types = 'i';

if ($purchaseSearch !== '') {
    $sql .= " AND (
        p.purchase_no LIKE ?
        OR p.invoice_no LIKE ?
        OR s.supplier_name LIKE ?
    )";
    $like = '%' . $purchaseSearch . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'sss';
}

$sql .= " ORDER BY p.id DESC LIMIT 50";

$stmt = $conn->prepare($sql);
if ($stmt) {
    $bindValues = [];
    $bindValues[] = $types;
    for ($i = 0; $i < count($params); $i++) {
        $bindValues[] = &$params[$i];
    }
    call_user_func_array([$stmt, 'bind_param'], $bindValues);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && $row = $res->fetch_assoc()) {
        $purchases[] = $row;
    }
    $stmt->close();
}

/* -------------------------------------------------------
   LOAD SELECTED PURCHASE + ITEMS
------------------------------------------------------- */
$selectedPurchase = null;
$purchaseItems = [];

if ($purchaseId > 0) {
    $stmt = $conn->prepare("
        SELECT p.*, s.supplier_name
        FROM purchases p
        LEFT JOIN suppliers s ON s.id = p.supplier_id
        WHERE p.id = ? AND p.business_id = ?
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param('ii', $purchaseId, $businessId);
        $stmt->execute();
        $res = $stmt->get_result();
        $selectedPurchase = $res ? $res->fetch_assoc() : null;
        $stmt->close();
    }

    if ($selectedPurchase) {
        $stmt = $conn->prepare("
            SELECT pi.*
            FROM purchase_items pi
            WHERE pi.purchase_id = ? AND pi.business_id = ?
            ORDER BY pi.id ASC
        ");
        if ($stmt) {
            $stmt->bind_param('ii', $purchaseId, $businessId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($res && $row = $res->fetch_assoc()) {
                $row['return_qty'] = '';
                $row['return_weight'] = '';
                $row['return_taxable'] = '0.00';
                $row['return_gst'] = '0.00';
                $row['return_total'] = '0.00';
                $purchaseItems[] = $row;
            }
            $stmt->close();
        }
    }
}

/* -------------------------------------------------------
   SAVE RETURN
------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_return'])) {
    $returnNo = trim((string)($_POST['return_no'] ?? ''));
    $returnDate = trim((string)($_POST['return_date'] ?? ''));
    $purchaseId = (int)($_POST['purchase_id'] ?? 0);
    $notes = trim((string)($_POST['notes'] ?? ''));
    $postedItems = $_POST['items'] ?? [];

    if ($returnNo === '') {
        $returnNo = generateReturnNo($conn, $businessId);
    }

    if ($returnDate === '') {
        $error = 'Return date is required.';
    } elseif ($purchaseId <= 0) {
        $error = 'Please select purchase.';
    } elseif (!is_array($postedItems) || empty($postedItems)) {
        $error = 'No return items found.';
    } else {
        $stmt = $conn->prepare("
            SELECT p.*, s.supplier_name
            FROM purchases p
            LEFT JOIN suppliers s ON s.id = p.supplier_id
            WHERE p.id = ? AND p.business_id = ?
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param('ii', $purchaseId, $businessId);
            $stmt->execute();
            $res = $stmt->get_result();
            $selectedPurchase = $res ? $res->fetch_assoc() : null;
            $stmt->close();
        }

        if (!$selectedPurchase) {
            $error = 'Purchase not found.';
        } else {
            $cleanItems = [];
            $subtotal = 0;
            $gstTotal = 0;
            $grandTotal = 0;

            foreach ($postedItems as $item) {
                $purchaseItemId = (int)($item['purchase_item_id'] ?? 0);
                $productId = (int)($item['product_id'] ?? 0);
                $itemName = trim((string)($item['item_name'] ?? ''));
                $qty = (float)($item['qty'] ?? 0);
                $netWeight = (float)($item['net_weight'] ?? 0);
                $ratePerGram = (float)($item['rate_per_gram'] ?? 0);
                $gstPercent = (float)($item['gst_percent'] ?? 0);

                $returnQty = (float)($item['return_qty'] ?? 0);
                $returnWeight = (float)($item['return_weight'] ?? 0);

                if ($returnQty <= 0 && $returnWeight <= 0) {
                    continue;
                }

                if ($returnQty < 0 || $returnWeight < 0) {
                    $error = 'Return qty / weight cannot be negative.';
                    break;
                }

                if ($returnQty > $qty) {
                    $error = 'Return qty cannot exceed purchased qty.';
                    break;
                }

                if ($returnWeight > $netWeight) {
                    $error = 'Return weight cannot exceed purchased net weight.';
                    break;
                }

                if ($returnWeight <= 0 && $qty > 0 && $netWeight > 0 && $returnQty > 0) {
                    $returnWeight = ($netWeight / $qty) * $returnQty;
                }

                $taxableAmount = $returnWeight * $ratePerGram;
                $gstAmount = ($taxableAmount * $gstPercent) / 100;
                $totalAmount = $taxableAmount + $gstAmount;

                $subtotal += $taxableAmount;
                $gstTotal += $gstAmount;
                $grandTotal += $totalAmount;

                $cleanItems[] = [
                    'purchase_item_id' => $purchaseItemId,
                    'product_id' => $productId,
                    'item_name' => $itemName,
                    'qty' => $returnQty,
                    'net_weight' => $returnWeight,
                    'rate_per_gram' => $ratePerGram,
                    'taxable_amount' => $taxableAmount,
                    'gst_percent' => $gstPercent,
                    'gst_amount' => $gstAmount,
                    'total_amount' => $totalAmount
                ];
            }

            if ($error === '' && empty($cleanItems)) {
                $error = 'Please enter at least one return item.';
            }

            if ($error === '') {
                $conn->begin_transaction();

                try {
                    /* INSERT RETURN MASTER */
                    $stmt = $conn->prepare("
                        INSERT INTO purchase_returns
                        (business_id, return_no, return_date, purchase_id, supplier_id, subtotal, gst_amount, total_amount, notes, created_by, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                    ");
                    if (!$stmt) {
                        throw new Exception('Failed to prepare return insert.');
                    }

                    $supplierId = (int)($selectedPurchase['supplier_id'] ?? 0);
                    $stmt->bind_param(
                        'issiidddsi',
                        $businessId,
                        $returnNo,
                        $returnDate,
                        $purchaseId,
                        $supplierId,
                        $subtotal,
                        $gstTotal,
                        $grandTotal,
                        $notes,
                        $userId
                    );

                    if (!$stmt->execute()) {
                        throw new Exception('Failed to save purchase return.');
                    }

                    $purchaseReturnId = (int)$stmt->insert_id;
                    $stmt->close();

                    foreach ($cleanItems as $row) {
                        /* INSERT RETURN ITEM */
                        $stmt = $conn->prepare("
                            INSERT INTO purchase_return_items
                            (business_id, purchase_return_id, purchase_item_id, product_id, item_name, qty, net_weight, rate_per_gram, taxable_amount, gst_percent, gst_amount, total_amount)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        if (!$stmt) {
                            throw new Exception('Failed to prepare return item insert.');
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
                            throw new Exception('Failed to save purchase return item.');
                        }
                        $stmt->close();

                        $productId = (int)$row['product_id'];
                        $returnQty = (float)$row['qty'];
                        $returnWeight = (float)$row['net_weight'];

                        if ($productId > 0) {
                            /* UPDATE PRODUCTS */
                            if ($prdHasCurrentStockQty) {
                                if ($prdHasBusinessId) {
                                    $stmt = $conn->prepare("
                                        UPDATE products
                                        SET current_stock_qty = GREATEST(COALESCE(current_stock_qty, 0) - ?, 0)
                                        WHERE id = ? AND business_id = ?
                                        LIMIT 1
                                    ");
                                    $stmt->bind_param('dii', $returnQty, $productId, $businessId);
                                } else {
                                    $stmt = $conn->prepare("
                                        UPDATE products
                                        SET current_stock_qty = GREATEST(COALESCE(current_stock_qty, 0) - ?, 0)
                                        WHERE id = ?
                                        LIMIT 1
                                    ");
                                    $stmt->bind_param('di', $returnQty, $productId);
                                }

                                if ($stmt) {
                                    if (!$stmt->execute()) {
                                        throw new Exception('Failed to update product stock.');
                                    }
                                    $stmt->close();
                                }
                            }

                            /* UPDATE PRODUCT_STOCK */
                            if ($productStockExists && hasColumn($conn, 'product_stock', 'product_id')) {
                                $updates = [];
                                $types = '';
                                $values = [];

                                if (hasColumn($conn, 'product_stock', 'out_qty')) {
                                    $updates[] = "out_qty = COALESCE(out_qty, 0) + ?";
                                    $types .= 'd';
                                    $values[] = $returnQty;
                                }

                                if (hasColumn($conn, 'product_stock', 'out_weight')) {
                                    $updates[] = "out_weight = COALESCE(out_weight, 0) + ?";
                                    $types .= 'd';
                                    $values[] = $returnWeight;
                                }

                                if (hasColumn($conn, 'product_stock', 'closing_qty')) {
                                    $updates[] = "closing_qty = GREATEST(COALESCE(closing_qty, 0) - ?, 0)";
                                    $types .= 'd';
                                    $values[] = $returnQty;
                                }

                                if (hasColumn($conn, 'product_stock', 'closing_weight')) {
                                    $updates[] = "closing_weight = GREATEST(COALESCE(closing_weight, 0) - ?, 0)";
                                    $types .= 'd';
                                    $values[] = $returnWeight;
                                }

                                if (!empty($updates)) {
                                    if (hasColumn($conn, 'product_stock', 'business_id')) {
                                        $sql = "UPDATE product_stock SET " . implode(', ', $updates) . " WHERE product_id = ? AND business_id = ?";
                                        $types .= 'ii';
                                        $values[] = $productId;
                                        $values[] = $businessId;
                                    } else {
                                        $sql = "UPDATE product_stock SET " . implode(', ', $updates) . " WHERE product_id = ?";
                                        $types .= 'i';
                                        $values[] = $productId;
                                    }

                                    $stmt = $conn->prepare($sql);
                                    if ($stmt) {
                                        $bindValues = [];
                                        $bindValues[] = $types;
                                        for ($i = 0; $i < count($values); $i++) {
                                            $bindValues[] = &$values[$i];
                                        }
                                        call_user_func_array([$stmt, 'bind_param'], $bindValues);

                                        if (!$stmt->execute()) {
                                            throw new Exception('Failed to update product stock summary.');
                                        }
                                        $stmt->close();
                                    }
                                }
                            }

                            /* STOCK MOVEMENT */
                            if ($stockMovementsExists && $stockMoveHasProductId) {
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
                                    $values[] = 'Purchase Return';
                                }

                                if ($stockMoveHasRefTable) {
                                    $fields[] = 'ref_table';
                                    $placeholders[] = '?';
                                    $types .= 's';
                                    $values[] = 'purchase_returns';
                                }

                                if ($stockMoveHasRefId) {
                                    $fields[] = 'ref_id';
                                    $placeholders[] = '?';
                                    $types .= 'i';
                                    $values[] = $purchaseReturnId;
                                }

                                if (hasColumn($conn, 'stock_movements', 'qty_out')) {
                                    $fields[] = 'qty_out';
                                    $placeholders[] = '?';
                                    $types .= 'd';
                                    $values[] = $returnQty;
                                }

                                if (hasColumn($conn, 'stock_movements', 'weight_out')) {
                                    $fields[] = 'weight_out';
                                    $placeholders[] = '?';
                                    $types .= 'd';
                                    $values[] = $returnWeight;
                                }

                                if ($stockMoveHasRemarks) {
                                    $fields[] = 'remarks';
                                    $placeholders[] = '?';
                                    $types .= 's';
                                    $values[] = 'Purchase return entry';
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
                                        throw new Exception('Failed to add stock movement.');
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
                        'Purchase Return',
                        'Create',
                        $purchaseReturnId,
                        'Created purchase return ' . $returnNo
                    );

                    $conn->commit();
                    header('Location: purchase-return.php?purchase_id=' . $purchaseId . '&msg=created');
                    exit;
                } catch (Throwable $e) {
                    $conn->rollback();
                    $error = $e->getMessage();
                }
            }
        }
    }
}

if (isset($_GET['msg']) && $_GET['msg'] === 'created') {
    $success = 'Purchase return created successfully.';
}
?>
<!doctype html>
<html lang="en">
<?php include('includes/head.php'); ?>

<style>
    #returnTable {
        min-width: 1500px;
    }
    #returnTable th,
    #returnTable td {
        vertical-align: middle;
        white-space: nowrap;
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

                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-3">Search Purchase</h4>

                        <form method="get" class="row g-2">
                            <div class="col-md-10">
                                <input type="text" name="search" class="form-control" placeholder="Search purchase no, invoice no, supplier..." value="<?php echo h($purchaseSearch); ?>">
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-primary w-100">Search</button>
                            </div>
                        </form>

                        <div class="table-responsive mt-3">
                            <table class="table table-bordered table-striped mb-0">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Purchase No</th>
                                        <th>Date</th>
                                        <th>Supplier</th>
                                        <th>Invoice No</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($purchases)): ?>
                                        <?php foreach ($purchases as $index => $purchase): ?>
                                            <tr>
                                                <td><?php echo $index + 1; ?></td>
                                                <td><?php echo h($purchase['purchase_no'] ?? ''); ?></td>
                                                <td><?php echo !empty($purchase['purchase_date']) ? date('d-m-Y', strtotime($purchase['purchase_date'])) : '-'; ?></td>
                                                <td><?php echo h($purchase['supplier_name'] ?? ''); ?></td>
                                                <td><?php echo h($purchase['invoice_no'] ?? ''); ?></td>
                                                <td>
                                                    <a href="purchase-return.php?purchase_id=<?php echo (int)$purchase['id']; ?>" class="btn btn-sm btn-primary">
                                                        Select
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-muted">No purchases found.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <?php if ($selectedPurchase): ?>
                    <form method="post" id="purchaseReturnForm">
                        <input type="hidden" name="save_return" value="1">
                        <input type="hidden" name="purchase_id" value="<?php echo (int)$selectedPurchase['id']; ?>">

                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Purchase Return Entry</h4>

                                <div class="row">
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Return No</label>
                                        <input type="text" name="return_no" class="form-control" value="<?php echo h($returnNo); ?>" required>
                                    </div>

                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Return Date</label>
                                        <input type="date" name="return_date" class="form-control" value="<?php echo h($returnDate); ?>" required>
                                    </div>

                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Purchase No</label>
                                        <input type="text" class="form-control" value="<?php echo h($selectedPurchase['purchase_no'] ?? ''); ?>" readonly>
                                    </div>

                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Supplier</label>
                                        <input type="text" class="form-control" value="<?php echo h($selectedPurchase['supplier_name'] ?? ''); ?>" readonly>
                                    </div>
                                </div>

                                <div class="table-responsive">
                                    <table class="table table-bordered align-middle" id="returnTable">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Item</th>
                                                <th>Purchased Qty</th>
                                                <th>Purchased Net Wt</th>
                                                <th>Rate/Gm</th>
                                                <th>GST %</th>
                                                <th>Return Qty</th>
                                                <th>Return Wt</th>
                                                <th>Taxable</th>
                                                <th>GST</th>
                                                <th>Total</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($purchaseItems as $index => $item): ?>
                                                <tr>
                                                    <td>
                                                        <?php echo h($item['item_name'] ?? ''); ?>
                                                        <input type="hidden" name="items[<?php echo $index; ?>][purchase_item_id]" value="<?php echo (int)$item['id']; ?>">
                                                        <input type="hidden" name="items[<?php echo $index; ?>][product_id]" value="<?php echo (int)($item['product_id'] ?? 0); ?>">
                                                        <input type="hidden" name="items[<?php echo $index; ?>][item_name]" value="<?php echo h($item['item_name'] ?? ''); ?>">
                                                    </td>
                                                    <td>
                                                        <?php echo qtyf($item['qty'] ?? 0); ?>
                                                        <input type="hidden" name="items[<?php echo $index; ?>][qty]" value="<?php echo h($item['qty'] ?? 0); ?>">
                                                    </td>
                                                    <td>
                                                        <?php echo qtyf($item['net_weight'] ?? 0); ?>
                                                        <input type="hidden" name="items[<?php echo $index; ?>][net_weight]" value="<?php echo h($item['net_weight'] ?? 0); ?>">
                                                    </td>
                                                    <td>
                                                        <?php echo moneyf($item['rate_per_gram'] ?? 0); ?>
                                                        <input type="hidden" name="items[<?php echo $index; ?>][rate_per_gram]" class="rate-per-gram" value="<?php echo h($item['rate_per_gram'] ?? 0); ?>">
                                                    </td>
                                                    <td>
                                                        <?php echo moneyf($item['gst_percent'] ?? 0); ?>
                                                        <input type="hidden" name="items[<?php echo $index; ?>][gst_percent]" class="gst-percent" value="<?php echo h($item['gst_percent'] ?? 0); ?>">
                                                    </td>
                                                    <td>
                                                        <input type="number" step="0.001" min="0" max="<?php echo h($item['qty'] ?? 0); ?>" name="items[<?php echo $index; ?>][return_qty]" class="form-control return-qty" value="">
                                                    </td>
                                                    <td>
                                                        <input type="number" step="0.001" min="0" max="<?php echo h($item['net_weight'] ?? 0); ?>" name="items[<?php echo $index; ?>][return_weight]" class="form-control return-weight" value="">
                                                    </td>
                                                    <td>
                                                        <input type="number" step="0.01" min="0" name="items[<?php echo $index; ?>][return_taxable]" class="form-control return-taxable" value="0.00" readonly>
                                                    </td>
                                                    <td>
                                                        <input type="number" step="0.01" min="0" name="items[<?php echo $index; ?>][return_gst]" class="form-control return-gst" value="0.00" readonly>
                                                    </td>
                                                    <td>
                                                        <input type="number" step="0.01" min="0" name="items[<?php echo $index; ?>][return_total]" class="form-control return-total" value="0.00" readonly>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <div class="row mt-3">
                                    <div class="col-md-8 mb-3">
                                        <label class="form-label">Notes</label>
                                        <input type="text" name="notes" class="form-control" value="<?php echo h($notes); ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <table class="table table-bordered">
                                            <tr>
                                                <th>Subtotal</th>
                                                <td><input type="number" step="0.01" id="summarySubtotal" class="form-control" readonly></td>
                                            </tr>
                                            <tr>
                                                <th>GST Total</th>
                                                <td><input type="number" step="0.01" id="summaryGst" class="form-control" readonly></td>
                                            </tr>
                                            <tr>
                                                <th>Grand Total</th>
                                                <td><input type="number" step="0.01" id="summaryGrand" class="form-control" readonly></td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>

                                <div class="d-flex gap-2">
                                    <button type="submit" class="btn btn-primary">Save Purchase Return</button>
                                    <a href="purchases.php" class="btn btn-secondary">Back to Purchases</a>
                                </div>
                            </div>
                        </div>
                    </form>
                <?php endif; ?>

            </div>
        </div>

        <?php include('includes/footer.php'); ?>
    </div>
</div>

<?php include('includes/rightbar.php'); ?>
<?php include('includes/scripts.php'); ?>

<script>
(function () {
    function calcRow(tr) {
        const purchasedQty = parseFloat(tr.querySelector('input[name*="[qty]"]').value || 0);
        const purchasedWt = parseFloat(tr.querySelector('input[name*="[net_weight]"]').value || 0);
        const rate = parseFloat(tr.querySelector('.rate-per-gram').value || 0);
        const gstPercent = parseFloat(tr.querySelector('.gst-percent').value || 0);

        let returnQty = parseFloat(tr.querySelector('.return-qty').value || 0);
        let returnWt = parseFloat(tr.querySelector('.return-weight').value || 0);

        if (returnQty > purchasedQty) {
            returnQty = purchasedQty;
            tr.querySelector('.return-qty').value = returnQty.toFixed(3);
        }

        if (returnWt > purchasedWt) {
            returnWt = purchasedWt;
            tr.querySelector('.return-weight').value = returnWt.toFixed(3);
        }

        if (returnWt <= 0 && purchasedQty > 0 && purchasedWt > 0 && returnQty > 0) {
            returnWt = (purchasedWt / purchasedQty) * returnQty;
            tr.querySelector('.return-weight').value = returnWt.toFixed(3);
        }

        const taxable = returnWt * rate;
        const gst = (taxable * gstPercent) / 100;
        const total = taxable + gst;

        tr.querySelector('.return-taxable').value = taxable.toFixed(2);
        tr.querySelector('.return-gst').value = gst.toFixed(2);
        tr.querySelector('.return-total').value = total.toFixed(2);

        calcSummary();
    }

    function calcSummary() {
        let subtotal = 0;
        let gst = 0;
        let grand = 0;

        document.querySelectorAll('#returnTable tbody tr').forEach(function (tr) {
            subtotal += parseFloat(tr.querySelector('.return-taxable').value || 0);
            gst += parseFloat(tr.querySelector('.return-gst').value || 0);
            grand += parseFloat(tr.querySelector('.return-total').value || 0);
        });

        const s1 = document.getElementById('summarySubtotal');
        const s2 = document.getElementById('summaryGst');
        const s3 = document.getElementById('summaryGrand');

        if (s1) s1.value = subtotal.toFixed(2);
        if (s2) s2.value = gst.toFixed(2);
        if (s3) s3.value = grand.toFixed(2);
    }

    document.querySelectorAll('#returnTable tbody tr').forEach(function (tr) {
        tr.querySelectorAll('.return-qty, .return-weight').forEach(function (el) {
            el.addEventListener('input', function () {
                calcRow(tr);
            });
        });
    });

    calcSummary();
})();
</script>

</body>
</html>