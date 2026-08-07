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
    if (!tableExists($conn, 'product_stock')) {
        return true;
    }

    $stmt = $conn->prepare("SELECT id FROM product_stock WHERE product_id = ? LIMIT 1");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $res = $stmt->get_result();
    $exists = $res && $res->fetch_assoc();
    $stmt->close();

    if ($exists) {
        return true;
    }

    $columns = ['business_id', 'product_id'];
    $placeholders = ['?', '?'];
    $types = 'ii';
    $values = [$businessId, $productId];

    $zeroColumns = [
        'opening_qty',
        'opening_weight',
        'in_qty',
        'in_weight',
        'out_qty',
        'out_weight',
        'closing_qty',
        'closing_weight'
    ];

    foreach ($zeroColumns as $col) {
        if (hasColumn($conn, 'product_stock', $col)) {
            $columns[] = $col;
            $placeholders[] = '0';
        }
    }

    if (hasColumn($conn, 'product_stock', 'created_at')) {
        $columns[] = 'created_at';
        $placeholders[] = 'NOW()';
    }

    if (hasColumn($conn, 'product_stock', 'updated_at')) {
        $columns[] = 'updated_at';
        $placeholders[] = 'NOW()';
    }

    $sql = "INSERT INTO product_stock (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param($types, $values[0], $values[1]);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

function generateSalesReturnNo(mysqli $conn, int $businessId, string $prefix = 'SR'): string
{
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM sales_returns WHERE business_id = ?");
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

    return $prefix . date('ymd') . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

$pageTitle = 'Sales Return';

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

if (!$stmt) {
    die('Role check failed.');
}

$stmt->bind_param('i', $userId);
$stmt->execute();
$res = $stmt->get_result();
$userRow = $res ? $res->fetch_assoc() : null;
$stmt->close();

$roleName = strtolower(trim((string)($userRow['role_name'] ?? '')));
if (!in_array($roleName, ['admin', 'manager', 'billing'], true)) {
    die('Access denied.');
}

/* -------------------------------------------------------
   REQUIRED TABLES
------------------------------------------------------- */
$requiredTables = ['sales', 'sale_items', 'sales_returns', 'sales_return_items', 'payment_methods'];
foreach ($requiredTables as $tbl) {
    if (!tableExists($conn, $tbl)) {
        die('Required table `' . h($tbl) . '` not found.');
    }
}

/* -------------------------------------------------------
   COLUMN CHECKS
------------------------------------------------------- */
$hasProductStockTable = tableExists($conn, 'product_stock');
$hasStockMovementTable = tableExists($conn, 'stock_movements');
$hasProductsTable = tableExists($conn, 'products');

$salesHasBusinessId       = hasColumn($conn, 'sales', 'business_id');
$salesHasBillNo           = hasColumn($conn, 'sales', 'bill_no');
$salesHasBillDate         = hasColumn($conn, 'sales', 'bill_date');
$salesHasCustomerId       = hasColumn($conn, 'sales', 'customer_id');
$salesHasCustomerName     = hasColumn($conn, 'sales', 'customer_name');
$salesHasCustomerMobile   = hasColumn($conn, 'sales', 'customer_mobile');
$salesHasGrandTotal       = hasColumn($conn, 'sales', 'grand_total');
$salesHasStatus           = hasColumn($conn, 'sales', 'status');

$saleItemsHasBusinessId   = hasColumn($conn, 'sale_items', 'business_id');

$productsHasCurrentStockQty = $hasProductsTable && hasColumn($conn, 'products', 'current_stock_qty');

$productStockHasProductId = $hasProductStockTable && hasColumn($conn, 'product_stock', 'product_id');

$salesReturnItemsReturnColumn = 'sales_return_id';
if (!hasColumn($conn, 'sales_return_items', 'sales_return_id') && hasColumn($conn, 'sales_return_items', 'return_id')) {
    $salesReturnItemsReturnColumn = 'return_id';
}

/* -------------------------------------------------------
   PAYMENT METHODS
------------------------------------------------------- */
$paymentMethods = [];
$res = $conn->query("SELECT id, method_name FROM payment_methods WHERE is_active = 1 ORDER BY method_name ASC");
while ($res && $row = $res->fetch_assoc()) {
    $paymentMethods[] = $row;
}

/* -------------------------------------------------------
   FLASH / MESSAGE
------------------------------------------------------- */
$success = '';
$error = '';

if (isset($_GET['msg']) && $_GET['msg'] === 'created') {
    $success = 'Sales return saved successfully and stock restored.';
}

/* -------------------------------------------------------
   SEARCH SALES / LOAD ALL SALES FOR DROPDOWN
------------------------------------------------------- */
$searchSale = trim((string)($_GET['search_sale'] ?? ''));
$salesList = [];

$sql = "SELECT id";
if ($salesHasBillNo) {
    $sql .= ", bill_no";
}
if ($salesHasBillDate) {
    $sql .= ", bill_date";
}
if ($salesHasCustomerName) {
    $sql .= ", customer_name";
}
if ($salesHasCustomerMobile) {
    $sql .= ", customer_mobile";
}
if ($salesHasGrandTotal) {
    $sql .= ", grand_total";
}

$sql .= " FROM sales WHERE 1=1";

$params = [];
$types = '';

if ($salesHasBusinessId) {
    $sql .= " AND business_id = ?";
    $params[] = $businessId;
    $types .= 'i';
}

if ($salesHasStatus) {
    $sql .= " AND status = 'Active'";
}

if ($searchSale !== '') {
    $sql .= " AND (1=0";

    if ($salesHasBillNo) {
        $sql .= " OR bill_no LIKE ?";
        $params[] = '%' . $searchSale . '%';
        $types .= 's';
    }

    if ($salesHasCustomerName) {
        $sql .= " OR customer_name LIKE ?";
        $params[] = '%' . $searchSale . '%';
        $types .= 's';
    }

    if ($salesHasCustomerMobile) {
        $sql .= " OR customer_mobile LIKE ?";
        $params[] = '%' . $searchSale . '%';
        $types .= 's';
    }

    $sql .= ")";
}

$sql .= " ORDER BY id DESC";

$stmt = $conn->prepare($sql);
if ($stmt) {
    if (!empty($params)) {
        $bind = [];
        $bind[] = $types;

        for ($i = 0; $i < count($params); $i++) {
            $bind[] = &$params[$i];
        }

        call_user_func_array([$stmt, 'bind_param'], $bind);
    }

    $stmt->execute();
    $res = $stmt->get_result();

    while ($res && $row = $res->fetch_assoc()) {
        $salesList[] = $row;
    }

    $stmt->close();
}

/* -------------------------------------------------------
   SELECTED SALE
------------------------------------------------------- */
$selectedSaleId = (int)($_GET['sale_id'] ?? $_POST['sale_id'] ?? 0);
$selectedSale = null;
$saleItems = [];
$returnedQtyMap = [];

if ($selectedSaleId > 0) {
    $sql = "SELECT * FROM sales WHERE id = ?";
    if ($salesHasBusinessId) {
        $sql .= " AND business_id = ?";
    }
    if ($salesHasStatus) {
        $sql .= " AND status = 'Active'";
    }
    $sql .= " LIMIT 1";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if ($salesHasBusinessId) {
            $stmt->bind_param('ii', $selectedSaleId, $businessId);
        } else {
            $stmt->bind_param('i', $selectedSaleId);
        }

        $stmt->execute();
        $res = $stmt->get_result();
        $selectedSale = $res ? $res->fetch_assoc() : null;
        $stmt->close();
    }

    if ($selectedSale) {
        $sql = "SELECT * FROM sale_items WHERE sale_id = ?";
        if ($saleItemsHasBusinessId) {
            $sql .= " AND business_id = ?";
        }
        $sql .= " ORDER BY id ASC";

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            if ($saleItemsHasBusinessId) {
                $stmt->bind_param('ii', $selectedSaleId, $businessId);
            } else {
                $stmt->bind_param('i', $selectedSaleId);
            }

            $stmt->execute();
            $res = $stmt->get_result();

            while ($res && $row = $res->fetch_assoc()) {
                $saleItems[] = $row;
            }

            $stmt->close();
        }

        $sql = "
            SELECT sri.sale_item_id, COALESCE(SUM(sri.qty), 0) AS returned_qty
            FROM sales_return_items sri
            INNER JOIN sales_returns sr ON sr.id = sri.`{$salesReturnItemsReturnColumn}`
            WHERE sr.sale_id = ?
        ";

        if (hasColumn($conn, 'sales_returns', 'business_id')) {
            $sql .= " AND sr.business_id = ?";
        }

        $sql .= " GROUP BY sri.sale_item_id";

        $stmt = $conn->prepare($sql);

        if ($stmt) {
            if (hasColumn($conn, 'sales_returns', 'business_id')) {
                $stmt->bind_param('ii', $selectedSaleId, $businessId);
            } else {
                $stmt->bind_param('i', $selectedSaleId);
            }

            $stmt->execute();
            $res = $stmt->get_result();

            while ($res && $row = $res->fetch_assoc()) {
                $returnedQtyMap[(int)$row['sale_item_id']] = (float)$row['returned_qty'];
            }

            $stmt->close();
        }
    }
}

/* -------------------------------------------------------
   POST VALUES
------------------------------------------------------- */
$returnDate = (string)($_POST['return_date'] ?? date('Y-m-d'));
$refundMethodId = (int)($_POST['refund_method_id'] ?? 0);
$reason = trim((string)($_POST['reason'] ?? ''));
$notes = trim((string)($_POST['notes'] ?? ''));
$returnQtys = $_POST['return_qty'] ?? [];

/* -------------------------------------------------------
   SAVE RETURN
------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_return'])) {
    $selectedSaleId = (int)($_POST['sale_id'] ?? 0);
    $returnDate = trim((string)($_POST['return_date'] ?? date('Y-m-d')));
    $refundMethodId = (int)($_POST['refund_method_id'] ?? 0);
    $reason = trim((string)($_POST['reason'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));
    $returnQtys = $_POST['return_qty'] ?? [];

    if ($selectedSaleId <= 0) {
        $error = 'Please select a sale.';
    } else {
        $sql = "SELECT * FROM sales WHERE id = ?";
        if ($salesHasBusinessId) {
            $sql .= " AND business_id = ?";
        }
        if ($salesHasStatus) {
            $sql .= " AND status = 'Active'";
        }
        $sql .= " LIMIT 1";

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            if ($salesHasBusinessId) {
                $stmt->bind_param('ii', $selectedSaleId, $businessId);
            } else {
                $stmt->bind_param('i', $selectedSaleId);
            }

            $stmt->execute();
            $res = $stmt->get_result();
            $selectedSale = $res ? $res->fetch_assoc() : null;
            $stmt->close();
        }

        if (!$selectedSale) {
            $error = 'Selected sale not found.';
        } else {
            $saleItems = [];

            $sql = "SELECT * FROM sale_items WHERE sale_id = ?";
            if ($saleItemsHasBusinessId) {
                $sql .= " AND business_id = ?";
            }
            $sql .= " ORDER BY id ASC";

            $stmt = $conn->prepare($sql);
            if ($stmt) {
                if ($saleItemsHasBusinessId) {
                    $stmt->bind_param('ii', $selectedSaleId, $businessId);
                } else {
                    $stmt->bind_param('i', $selectedSaleId);
                }

                $stmt->execute();
                $res = $stmt->get_result();

                while ($res && $row = $res->fetch_assoc()) {
                    $saleItems[] = $row;
                }

                $stmt->close();
            }

            if (empty($saleItems)) {
                $error = 'No sale items found.';
            } else {
                $returnedQtyMap = [];

                $sql = "
                    SELECT sri.sale_item_id, COALESCE(SUM(sri.qty), 0) AS returned_qty
                    FROM sales_return_items sri
                    INNER JOIN sales_returns sr ON sr.id = sri.`{$salesReturnItemsReturnColumn}`
                    WHERE sr.sale_id = ?
                ";

                if (hasColumn($conn, 'sales_returns', 'business_id')) {
                    $sql .= " AND sr.business_id = ?";
                }

                $sql .= " GROUP BY sri.sale_item_id";

                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    if (hasColumn($conn, 'sales_returns', 'business_id')) {
                        $stmt->bind_param('ii', $selectedSaleId, $businessId);
                    } else {
                        $stmt->bind_param('i', $selectedSaleId);
                    }

                    $stmt->execute();
                    $res = $stmt->get_result();

                    while ($res && $row = $res->fetch_assoc()) {
                        $returnedQtyMap[(int)$row['sale_item_id']] = (float)$row['returned_qty'];
                    }

                    $stmt->close();
                }

                $returnItems = [];
                $subtotal = 0.0;
                $discountAmount = 0.0;
                $taxableAmount = 0.0;
                $gstAmount = 0.0;
                $refundAmount = 0.0;

                foreach ($saleItems as $item) {
                    $saleItemId = (int)($item['id'] ?? 0);
                    $soldQty = (float)($item['qty'] ?? 0);
                    $alreadyReturned = (float)($returnedQtyMap[$saleItemId] ?? 0);
                    $allowedQty = max(0, $soldQty - $alreadyReturned);
                    $returnQty = (float)($returnQtys[$saleItemId] ?? 0);

                    if ($returnQty <= 0) {
                        continue;
                    }

                    if ($allowedQty <= 0) {
                        $error = 'This item is already fully returned: ' . (string)($item['item_name'] ?? '');
                        break;
                    }

                    if ($returnQty > $allowedQty) {
                        $error = 'Return qty exceeds available returnable qty for item: ' . (string)($item['item_name'] ?? '');
                        break;
                    }

                    $soldNetWeight = (float)($item['net_weight'] ?? 0);
                    $unitNetWeight = $soldQty > 0 ? ($soldNetWeight / $soldQty) : 0;
                    $returnNetWeight = $unitNetWeight * $returnQty;

                    $soldTaxable = (float)($item['taxable_amount'] ?? 0);
                    $soldGst = (float)($item['gst_amount'] ?? 0);
                    $soldTotal = (float)($item['total_amount'] ?? 0);

                    $taxablePerQty = $soldQty > 0 ? ($soldTaxable / $soldQty) : 0;
                    $gstPerQty = $soldQty > 0 ? ($soldGst / $soldQty) : 0;
                    $totalPerQty = $soldQty > 0 ? ($soldTotal / $soldQty) : 0;

                    $itemTaxable = round($taxablePerQty * $returnQty, 2);
                    $itemGst = round($gstPerQty * $returnQty, 2);
                    $itemTotal = round($totalPerQty * $returnQty, 2);

                    $returnItems[] = [
                        'sale_item_id' => $saleItemId,
                        'product_id' => (int)($item['product_id'] ?? 0),
                        'item_name' => (string)($item['item_name'] ?? ''),
                        'qty' => $returnQty,
                        'net_weight' => $returnNetWeight,
                        'rate_per_gram' => (float)($item['rate_per_gram'] ?? 0),
                        'taxable_amount' => $itemTaxable,
                        'gst_percent' => (float)($item['gst_percent'] ?? 0),
                        'gst_amount' => $itemGst,
                        'total_amount' => $itemTotal
                    ];

                    $subtotal += $itemTaxable;
                    $taxableAmount += $itemTaxable;
                    $gstAmount += $itemGst;
                    $refundAmount += $itemTotal;
                }

                if ($error === '' && empty($returnItems)) {
                    $error = 'Please enter at least one valid return quantity.';
                }

                if ($error === '') {
                    $returnNo = generateSalesReturnNo($conn, $businessId, 'SR');

                    $conn->begin_transaction();

                    try {
                        $stmt = $conn->prepare("
                            INSERT INTO sales_returns
                            (business_id, return_no, return_date, sale_id, customer_id, subtotal, discount_amount, taxable_amount, gst_amount, refund_amount, refund_method_id, reason, notes, created_by, created_at, updated_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                        ");

                        if (!$stmt) {
                            throw new Exception('Failed to prepare sales return insert: ' . $conn->error);
                        }

                        $customerId = (int)($selectedSale['customer_id'] ?? 0);

                        $stmt->bind_param(
                            'issiidddddissi',
                            $businessId,
                            $returnNo,
                            $returnDate,
                            $selectedSaleId,
                            $customerId,
                            $subtotal,
                            $discountAmount,
                            $taxableAmount,
                            $gstAmount,
                            $refundAmount,
                            $refundMethodId,
                            $reason,
                            $notes,
                            $userId
                        );

                        if (!$stmt->execute()) {
                            $stmtError = $stmt->error;
                            $stmt->close();
                            throw new Exception('Failed to save sales return: ' . $stmtError);
                        }

                        $salesReturnId = (int)$stmt->insert_id;
                        $stmt->close();

                        foreach ($returnItems as $ritem) {
                            if ($salesReturnItemsReturnColumn === 'sales_return_id') {
                                $stmt = $conn->prepare("
                                    INSERT INTO sales_return_items
                                    (business_id, sales_return_id, sale_item_id, product_id, item_name, qty, net_weight, rate_per_gram, taxable_amount, gst_percent, gst_amount, total_amount)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                                ");
                            } else {
                                $stmt = $conn->prepare("
                                    INSERT INTO sales_return_items
                                    (business_id, return_id, sale_item_id, product_id, item_name, qty, net_weight, rate_per_gram, taxable_amount, gst_percent, gst_amount, total_amount)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                                ");
                            }

                            if (!$stmt) {
                                throw new Exception('Failed to prepare return item insert: ' . $conn->error);
                            }

                            $stmt->bind_param(
                                'iiiisddddddd',
                                $businessId,
                                $salesReturnId,
                                $ritem['sale_item_id'],
                                $ritem['product_id'],
                                $ritem['item_name'],
                                $ritem['qty'],
                                $ritem['net_weight'],
                                $ritem['rate_per_gram'],
                                $ritem['taxable_amount'],
                                $ritem['gst_percent'],
                                $ritem['gst_amount'],
                                $ritem['total_amount']
                            );

                            if (!$stmt->execute()) {
                                $stmtError = $stmt->error;
                                $stmt->close();
                                throw new Exception('Failed to save sales return item: ' . $stmtError);
                            }

                            $stmt->close();

                            if ($ritem['product_id'] > 0 && $productsHasCurrentStockQty) {
                                $stmt = $conn->prepare("
                                    UPDATE products
                                    SET current_stock_qty = IFNULL(current_stock_qty,0) + ?, updated_at = NOW()
                                    WHERE id = ? AND business_id = ?
                                    LIMIT 1
                                ");

                                if (!$stmt) {
                                    throw new Exception('Failed to prepare product stock restore.');
                                }

                                $stmt->bind_param('dii', $ritem['qty'], $ritem['product_id'], $businessId);

                                if (!$stmt->execute()) {
                                    $stmtError = $stmt->error;
                                    $stmt->close();
                                    throw new Exception('Failed to restore product stock: ' . $stmtError);
                                }

                                $stmt->close();
                            }

                            if ($ritem['product_id'] > 0 && $hasProductStockTable && $productStockHasProductId) {
                                if (!ensureProductStockRow($conn, $businessId, (int)$ritem['product_id'])) {
                                    throw new Exception('Failed to create product_stock row.');
                                }

                                $stmt = $conn->prepare("
                                    UPDATE product_stock
                                    SET
                                        in_qty = IFNULL(in_qty,0) + ?,
                                        in_weight = IFNULL(in_weight,0) + ?,
                                        closing_qty = IFNULL(closing_qty,0) + ?,
                                        closing_weight = IFNULL(closing_weight,0) + ?,
                                        updated_at = NOW()
                                    WHERE product_id = ?
                                    LIMIT 1
                                ");

                                if (!$stmt) {
                                    throw new Exception('Failed to prepare product_stock restore.');
                                }

                                $stmt->bind_param(
                                    'ddddi',
                                    $ritem['qty'],
                                    $ritem['net_weight'],
                                    $ritem['qty'],
                                    $ritem['net_weight'],
                                    $ritem['product_id']
                                );

                                if (!$stmt->execute()) {
                                    $stmtError = $stmt->error;
                                    $stmt->close();
                                    throw new Exception('Failed to update product_stock: ' . $stmtError);
                                }

                                $stmt->close();
                            }

                            if ($ritem['product_id'] > 0 && $hasStockMovementTable) {
                                $stmt = $conn->prepare("
                                    INSERT INTO stock_movements
                                    (business_id, movement_date, product_id, movement_type, ref_table, ref_id, qty_in, qty_out, weight_in, weight_out, remarks, created_by, created_at)
                                    VALUES (?, NOW(), ?, 'Sale Return', 'sales_returns', ?, ?, 0, ?, 0, ?, ?, NOW())
                                ");

                                if (!$stmt) {
                                    throw new Exception('Failed to prepare stock movement insert.');
                                }

                                $remarksText = 'Sales return ' . $returnNo;

                                $stmt->bind_param(
                                    'iiiddsi',
                                    $businessId,
                                    $ritem['product_id'],
                                    $salesReturnId,
                                    $ritem['qty'],
                                    $ritem['net_weight'],
                                    $remarksText,
                                    $userId
                                );

                                if (!$stmt->execute()) {
                                    $stmtError = $stmt->error;
                                    $stmt->close();
                                    throw new Exception('Failed to insert stock movement: ' . $stmtError);
                                }

                                $stmt->close();
                            }
                        }

                        addAuditLogSafe(
                            $conn,
                            $businessId,
                            $userId,
                            'Sales Return',
                            'Create',
                            $salesReturnId,
                            'Created sales return ' . $returnNo
                        );

                        $conn->commit();

                        header('Location: sales-return.php?msg=created&sale_id=' . $selectedSaleId);
                        exit;
                    } catch (Throwable $e) {
                        $conn->rollback();
                        $error = 'Error: ' . $e->getMessage();
                    }
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

                <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
                    <div>
                        <h4 class="mb-1">Sales Return</h4>
                        <p class="text-muted mb-0">Create return against an existing sale</p>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="sales-list.php" class="btn btn-secondary">Back to Sales</a>
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

                <div class="card">
                    <div class="card-body">
                        <form method="get" class="row g-2 align-items-end">
                            <div class="col-md-6">
                                <label class="form-label">Search Sale</label>
                                <input type="text" name="search_sale" class="form-control" placeholder="Bill no / customer / mobile" value="<?php echo h($searchSale); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Select Sale</label>
                                <select name="sale_id" class="form-select">
                                    <option value="0">Select Sale</option>
                                    <?php foreach ($salesList as $sale): ?>
                                        <option value="<?php echo (int)$sale['id']; ?>" <?php echo $selectedSaleId === (int)$sale['id'] ? 'selected' : ''; ?>>
                                            <?php
                                            echo h(
                                                ($sale['bill_no'] ?? ('SALE-' . $sale['id']))
                                                . ' - '
                                                . ($sale['customer_name'] ?? 'Walk-in')
                                                . (!empty($sale['customer_mobile']) ? ' - ' . $sale['customer_mobile'] : '')
                                                . (!empty($sale['bill_date']) ? ' - ' . date('d-m-Y', strtotime($sale['bill_date'])) : '')
                                                . ' - ₹' . number_format((float)($sale['grand_total'] ?? 0), 2)
                                            );
                                            ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-primary w-100">Load Sale</button>
                            </div>
                        </form>
                    </div>
                </div>

                <?php if ($selectedSale): ?>
                    <form method="post" id="salesReturnForm">
                        <input type="hidden" name="sale_id" value="<?php echo (int)$selectedSaleId; ?>">

                        <div class="row g-3">
                            <div class="col-xl-9">
                                <div class="card">
                                    <div class="card-header">
                                        <h5 class="mb-0">Sale Details</h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="row g-3">
                                            <div class="col-md-3">
                                                <label class="form-label">Bill No</label>
                                                <input type="text" class="form-control" value="<?php echo h($selectedSale['bill_no'] ?? ('SALE-' . $selectedSale['id'])); ?>" readonly>
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label">Bill Date</label>
                                                <input type="text" class="form-control" value="<?php echo !empty($selectedSale['bill_date']) ? h(date('d-m-Y', strtotime($selectedSale['bill_date']))) : ''; ?>" readonly>
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label">Customer</label>
                                                <input type="text" class="form-control" value="<?php echo h($selectedSale['customer_name'] ?? 'Walk-in Customer'); ?>" readonly>
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label">Mobile</label>
                                                <input type="text" class="form-control" value="<?php echo h($selectedSale['customer_mobile'] ?? ''); ?>" readonly>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="card">
                                    <div class="card-header">
                                        <h5 class="mb-0">Return Items</h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="table-responsive">
                                            <table class="table table-bordered align-middle mb-0" id="returnItemsTable">
                                                <thead>
                                                    <tr>
                                                        <th>#</th>
                                                        <th>Item</th>
                                                        <th>Sold Qty</th>
                                                        <th>Returned</th>
                                                        <th>Returnable</th>
                                                        <th>Net Weight</th>
                                                        <th>Rate</th>
                                                        <th>Total</th>
                                                        <th style="width:140px;">Return Qty</th>
                                                        <th style="width:140px;">Return Total</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php if (empty($saleItems)): ?>
                                                        <tr>
                                                            <td colspan="10" class="text-center text-muted">No sale items found.</td>
                                                        </tr>
                                                    <?php else: ?>
                                                        <?php foreach ($saleItems as $index => $item): ?>
                                                            <?php
                                                            $saleItemId = (int)($item['id'] ?? 0);
                                                            $soldQty = (float)($item['qty'] ?? 0);
                                                            $alreadyReturned = (float)($returnedQtyMap[$saleItemId] ?? 0);
                                                            $returnableQty = max(0, $soldQty - $alreadyReturned);
                                                            $soldTotal = (float)($item['total_amount'] ?? 0);
                                                            $returnQtyValue = (float)($returnQtys[$saleItemId] ?? 0);
                                                            ?>
                                                            <tr>
                                                                <td><?php echo $index + 1; ?></td>
                                                                <td>
                                                                    <strong><?php echo h($item['item_name'] ?? '-'); ?></strong>
                                                                    <?php if (!empty($item['product_code'])): ?>
                                                                        <br><small class="text-muted"><?php echo h($item['product_code']); ?></small>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td class="sold-qty"><?php echo number_format($soldQty, 3); ?></td>
                                                                <td><?php echo number_format($alreadyReturned, 3); ?></td>
                                                                <td><?php echo number_format($returnableQty, 3); ?></td>
                                                                <td class="sold-net-weight"><?php echo number_format((float)($item['net_weight'] ?? 0), 3); ?></td>
                                                                <td class="sold-rate"><?php echo number_format((float)($item['rate_per_gram'] ?? 0), 2); ?></td>
                                                                <td class="sold-total"><?php echo number_format($soldTotal, 2); ?></td>
                                                                <td>
                                                                    <input
                                                                        type="number"
                                                                        step="0.001"
                                                                        min="0"
                                                                        max="<?php echo number_format($returnableQty, 3, '.', ''); ?>"
                                                                        name="return_qty[<?php echo $saleItemId; ?>]"
                                                                        class="form-control return-qty-input"
                                                                        value="<?php echo h((string)$returnQtyValue); ?>"
                                                                        data-sold-qty="<?php echo number_format($soldQty, 3, '.', ''); ?>"
                                                                        data-returnable-qty="<?php echo number_format($returnableQty, 3, '.', ''); ?>"
                                                                        data-sold-total="<?php echo number_format($soldTotal, 2, '.', ''); ?>"
                                                                        placeholder="0.000"
                                                                    >
                                                                    <?php if ($returnableQty <= 0): ?>
                                                                        
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td>
                                                                    <input type="text" class="form-control return-line-total" readonly value="0.00">
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-xl-3">
                                <div class="card">
                                    <div class="card-header">
                                        <h5 class="mb-0">Return Details</h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="mb-3">
                                            <label class="form-label">Return Date</label>
                                            <input type="date" name="return_date" class="form-control" value="<?php echo h($returnDate); ?>" required>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label">Refund Method</label>
                                            <select name="refund_method_id" class="form-select">
                                                <option value="0">Select Method</option>
                                                <?php foreach ($paymentMethods as $pm): ?>
                                                    <option value="<?php echo (int)$pm['id']; ?>" <?php echo $refundMethodId === (int)$pm['id'] ? 'selected' : ''; ?>>
                                                        <?php echo h($pm['method_name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label">Reason</label>
                                            <input type="text" name="reason" class="form-control" value="<?php echo h($reason); ?>" placeholder="Reason for return">
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label">Notes</label>
                                            <textarea name="notes" class="form-control" rows="3" placeholder="Notes"><?php echo h($notes); ?></textarea>
                                        </div>

                                        <table class="table table-bordered mb-0">
                                            <tr>
                                                <th>Refund Amount</th>
                                                <td class="text-end"><strong>₹<span id="refund_total">0.00</span></strong></td>
                                            </tr>
                                        </table>

                                        <div class="mt-3 d-grid gap-2">
                                            <button type="submit" name="save_return" value="1" class="btn btn-primary">Save Return</button>
                                            <a href="sales-list.php" class="btn btn-secondary">Cancel</a>
                                        </div>
                                    </div>
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
function parseNum(val) {
    const n = parseFloat(val);
    return isNaN(n) ? 0 : n;
}

function calculateReturnSummary() {
    let refundTotal = 0;

    document.querySelectorAll('#returnItemsTable tbody tr').forEach(function(row) {
        const qtyInput = row.querySelector('.return-qty-input');
        const lineTotalInput = row.querySelector('.return-line-total');

        if (!qtyInput || !lineTotalInput) {
            return;
        }

        const soldQty = parseNum(qtyInput.getAttribute('data-sold-qty'));
        const returnableQty = parseNum(qtyInput.getAttribute('data-returnable-qty'));
        const soldTotal = parseNum(qtyInput.getAttribute('data-sold-total'));

        let returnQty = parseNum(qtyInput.value);

        if (returnQty < 0) {
            returnQty = 0;
            qtyInput.value = '0.000';
        }

        if (returnableQty <= 0 && returnQty > 0) {
            alert('This item is already fully returned.');
            returnQty = 0;
            qtyInput.value = '0.000';
        }

        if (returnQty > returnableQty) {
            alert('Return qty cannot be greater than returnable qty.');
            returnQty = returnableQty;
            qtyInput.value = returnableQty.toFixed(3);
        }

        const perQty = soldQty > 0 ? (soldTotal / soldQty) : 0;
        const returnTotal = perQty * returnQty;

        lineTotalInput.value = returnTotal.toFixed(2);
        refundTotal += returnTotal;
    });

    const refundEl = document.getElementById('refund_total');
    if (refundEl) {
        refundEl.textContent = refundTotal.toFixed(2);
    }
}

document.addEventListener('input', function(e) {
    if (e.target.classList.contains('return-qty-input')) {
        calculateReturnSummary();
    }
});

document.addEventListener('DOMContentLoaded', function() {
    calculateReturnSummary();
});
</script>

</body>
</html>