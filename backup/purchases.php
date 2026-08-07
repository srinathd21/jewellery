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

if (!function_exists('money')) {
    function money($amount): string
    {
        return number_format((float)$amount, 2);
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

$pageTitle = 'Purchases';

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
if (!tableExists($conn, 'purchases') || !tableExists($conn, 'suppliers')) {
    die('Required tables not found. Please import the SQL first.');
}

/* -------------------------------------------------------
   COLUMN CHECKS
------------------------------------------------------- */
$purHasBusinessId      = hasColumn($conn, 'purchases', 'business_id');
$purHasPurchaseNo      = hasColumn($conn, 'purchases', 'purchase_no');
$purHasPurchaseDate    = hasColumn($conn, 'purchases', 'purchase_date');
$purHasSupplierId      = hasColumn($conn, 'purchases', 'supplier_id');
$purHasInvoiceNo       = hasColumn($conn, 'purchases', 'invoice_no');
$purHasSubtotal        = hasColumn($conn, 'purchases', 'subtotal');
$purHasDiscountAmount  = hasColumn($conn, 'purchases', 'discount_amount');
$purHasTaxableAmount   = hasColumn($conn, 'purchases', 'taxable_amount');
$purHasCgstAmount      = hasColumn($conn, 'purchases', 'cgst_amount');
$purHasSgstAmount      = hasColumn($conn, 'purchases', 'sgst_amount');
$purHasIgstAmount      = hasColumn($conn, 'purchases', 'igst_amount');
$purHasRoundOff        = hasColumn($conn, 'purchases', 'round_off');
$purHasGrandTotal      = hasColumn($conn, 'purchases', 'grand_total');
$purHasPaidAmount      = hasColumn($conn, 'purchases', 'paid_amount');
$purHasBalanceAmount   = hasColumn($conn, 'purchases', 'balance_amount');
$purHasPaymentStatus   = hasColumn($conn, 'purchases', 'payment_status');
$purHasNotes           = hasColumn($conn, 'purchases', 'notes');
$purHasCreatedBy       = hasColumn($conn, 'purchases', 'created_by');
$purHasCreatedAt       = hasColumn($conn, 'purchases', 'created_at');
$purHasUpdatedAt       = hasColumn($conn, 'purchases', 'updated_at');

$supHasBusinessId      = hasColumn($conn, 'suppliers', 'business_id');
$supHasSupplierName    = hasColumn($conn, 'suppliers', 'supplier_name');
$supHasSupplierCode    = hasColumn($conn, 'suppliers', 'supplier_code');
$supHasMobile          = hasColumn($conn, 'suppliers', 'mobile');

$purchaseItemsExists   = tableExists($conn, 'purchase_items');
$productsExists        = tableExists($conn, 'products');
$productStockExists    = tableExists($conn, 'product_stock');
$stockMovementsExists  = tableExists($conn, 'stock_movements');

/* -------------------------------------------------------
   FLASH MESSAGES
------------------------------------------------------- */
$success = '';
$error = '';

$msg = trim((string)($_GET['msg'] ?? ''));
if ($msg === 'created') {
    $success = 'Purchase created successfully.';
} elseif ($msg === 'updated') {
    $success = 'Purchase updated successfully.';
} elseif ($msg === 'deleted') {
    $success = 'Purchase deleted successfully.';
}

/* -------------------------------------------------------
   DELETE PURCHASE
------------------------------------------------------- */
if (isset($_GET['delete']) && (int)$_GET['delete'] > 0) {
    $deleteId = (int)$_GET['delete'];

    $conn->begin_transaction();

    try {
        $purchase = null;
        if ($purHasBusinessId) {
            $stmt = $conn->prepare("SELECT * FROM purchases WHERE id = ? AND business_id = ? LIMIT 1");
            $stmt->bind_param('ii', $deleteId, $businessId);
        } else {
            $stmt = $conn->prepare("SELECT * FROM purchases WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $deleteId);
        }

        if (!$stmt) {
            throw new Exception('Failed to prepare purchase query.');
        }

        $stmt->execute();
        $res = $stmt->get_result();
        $purchase = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!$purchase) {
            throw new Exception('Purchase not found.');
        }

        $purchaseItems = [];
        if ($purchaseItemsExists) {
            if (hasColumn($conn, 'purchase_items', 'business_id') && $purHasBusinessId) {
                $stmt = $conn->prepare("SELECT * FROM purchase_items WHERE purchase_id = ? AND business_id = ?");
                $stmt->bind_param('ii', $deleteId, $businessId);
            } else {
                $stmt = $conn->prepare("SELECT * FROM purchase_items WHERE purchase_id = ?");
                $stmt->bind_param('i', $deleteId);
            }

            if ($stmt) {
                $stmt->execute();
                $res = $stmt->get_result();
                while ($res && $row = $res->fetch_assoc()) {
                    $purchaseItems[] = $row;
                }
                $stmt->close();
            }
        }

        foreach ($purchaseItems as $item) {
            $productId = (int)($item['product_id'] ?? 0);
            $qty = (float)($item['qty'] ?? 0);
            $netWeight = (float)($item['net_weight'] ?? 0);

            if ($productId > 0 && $productsExists && hasColumn($conn, 'products', 'current_stock_qty')) {
                if ($purHasBusinessId && hasColumn($conn, 'products', 'business_id')) {
                    $stmt = $conn->prepare("
                        UPDATE products
                        SET current_stock_qty = GREATEST(COALESCE(current_stock_qty, 0) - ?, 0)
                        WHERE id = ? AND business_id = ?
                        LIMIT 1
                    ");
                    $stmt->bind_param('dii', $qty, $productId, $businessId);
                } else {
                    $stmt = $conn->prepare("
                        UPDATE products
                        SET current_stock_qty = GREATEST(COALESCE(current_stock_qty, 0) - ?, 0)
                        WHERE id = ?
                        LIMIT 1
                    ");
                    $stmt->bind_param('di', $qty, $productId);
                }

                if ($stmt) {
                    $stmt->execute();
                    $stmt->close();
                }
            }

            if ($productStockExists && hasColumn($conn, 'product_stock', 'product_id')) {
                $updates = [];
                $types = '';
                $values = [];

                if (hasColumn($conn, 'product_stock', 'in_qty')) {
                    $updates[] = "in_qty = GREATEST(COALESCE(in_qty, 0) - ?, 0)";
                    $types .= 'd';
                    $values[] = $qty;
                }
                if (hasColumn($conn, 'product_stock', 'in_weight')) {
                    $updates[] = "in_weight = GREATEST(COALESCE(in_weight, 0) - ?, 0)";
                    $types .= 'd';
                    $values[] = $netWeight;
                }
                if (hasColumn($conn, 'product_stock', 'closing_qty')) {
                    $updates[] = "closing_qty = GREATEST(COALESCE(closing_qty, 0) - ?, 0)";
                    $types .= 'd';
                    $values[] = $qty;
                }
                if (hasColumn($conn, 'product_stock', 'closing_weight')) {
                    $updates[] = "closing_weight = GREATEST(COALESCE(closing_weight, 0) - ?, 0)";
                    $types .= 'd';
                    $values[] = $netWeight;
                }

                if (!empty($updates)) {
                    if (hasColumn($conn, 'product_stock', 'business_id') && $purHasBusinessId) {
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
                        $stmt->execute();
                        $stmt->close();
                    }
                }
            }
        }

        if ($stockMovementsExists && hasColumn($conn, 'stock_movements', 'ref_table') && hasColumn($conn, 'stock_movements', 'ref_id')) {
            if (hasColumn($conn, 'stock_movements', 'business_id') && $purHasBusinessId) {
                $stmt = $conn->prepare("DELETE FROM stock_movements WHERE ref_table = 'purchases' AND ref_id = ? AND business_id = ?");
                $stmt->bind_param('ii', $deleteId, $businessId);
            } else {
                $stmt = $conn->prepare("DELETE FROM stock_movements WHERE ref_table = 'purchases' AND ref_id = ?");
                $stmt->bind_param('i', $deleteId);
            }
            if ($stmt) {
                $stmt->execute();
                $stmt->close();
            }
        }

        if ($purchaseItemsExists) {
            if (hasColumn($conn, 'purchase_items', 'business_id') && $purHasBusinessId) {
                $stmt = $conn->prepare("DELETE FROM purchase_items WHERE purchase_id = ? AND business_id = ?");
                $stmt->bind_param('ii', $deleteId, $businessId);
            } else {
                $stmt = $conn->prepare("DELETE FROM purchase_items WHERE purchase_id = ?");
                $stmt->bind_param('i', $deleteId);
            }
            if ($stmt) {
                $stmt->execute();
                $stmt->close();
            }
        }

        if ($purHasBusinessId) {
            $stmt = $conn->prepare("DELETE FROM purchases WHERE id = ? AND business_id = ? LIMIT 1");
            $stmt->bind_param('ii', $deleteId, $businessId);
        } else {
            $stmt = $conn->prepare("DELETE FROM purchases WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $deleteId);
        }

        if (!$stmt) {
            throw new Exception('Failed to prepare purchase delete.');
        }

        $stmt->execute();
        $stmt->close();

        addAuditLog(
            $conn,
            $businessId,
            $userId,
            'Purchases',
            'Delete',
            $deleteId,
            'Deleted purchase'
        );

        $conn->commit();
        header('Location: purchases.php?msg=deleted');
        exit;
    } catch (Throwable $e) {
        $conn->rollback();
        $error = $e->getMessage();
    }
}

/* -------------------------------------------------------
   COUNTS
------------------------------------------------------- */
$totalPurchases = 0;
$paidPurchases = 0;
$partialPurchases = 0;
$unpaidPurchases = 0;
$totalPurchaseAmount = 0.00;

$sql = "SELECT COUNT(*) AS cnt, COALESCE(SUM(grand_total),0) AS total_amount FROM purchases WHERE 1=1";
if ($purHasBusinessId) {
    $sql .= " AND business_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $businessId);
} else {
    $stmt = $conn->prepare($sql);
}
if ($stmt) {
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $totalPurchases = (int)($row['cnt'] ?? 0);
    $totalPurchaseAmount = (float)($row['total_amount'] ?? 0);
    $stmt->close();
}

if ($purHasPaymentStatus) {
    foreach (['Paid', 'Partial', 'Unpaid'] as $statusName) {
        $sql = "SELECT COUNT(*) AS cnt FROM purchases WHERE payment_status = ?";
        if ($purHasBusinessId) {
            $sql .= " AND business_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('si', $statusName, $businessId);
        } else {
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('s', $statusName);
        }

        if ($stmt) {
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $count = (int)($row['cnt'] ?? 0);
            $stmt->close();

            if ($statusName === 'Paid') {
                $paidPurchases = $count;
            } elseif ($statusName === 'Partial') {
                $partialPurchases = $count;
            } elseif ($statusName === 'Unpaid') {
                $unpaidPurchases = $count;
            }
        }
    }
}

/* -------------------------------------------------------
   SUPPLIER FILTER OPTIONS
------------------------------------------------------- */
$suppliers = [];
$sql = "SELECT id";
if ($supHasSupplierName) {
    $sql .= ", supplier_name";
}
if ($supHasSupplierCode) {
    $sql .= ", supplier_code";
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
   FILTERS
------------------------------------------------------- */
$search = trim((string)($_GET['search'] ?? ''));
$supplierFilter = (int)($_GET['supplier_id'] ?? 0);
$statusFilter = trim((string)($_GET['payment_status'] ?? 'all'));
$fromDate = trim((string)($_GET['from_date'] ?? ''));
$toDate = trim((string)($_GET['to_date'] ?? ''));

$where = " WHERE 1=1 ";
$params = [];
$types = '';

if ($purHasBusinessId) {
    $where .= " AND p.business_id = ? ";
    $params[] = $businessId;
    $types .= 'i';
}

if ($search !== '') {
    $parts = [];

    if ($purHasPurchaseNo) {
        $parts[] = "p.purchase_no LIKE ?";
        $params[] = '%' . $search . '%';
        $types .= 's';
    }
    if ($purHasInvoiceNo) {
        $parts[] = "p.invoice_no LIKE ?";
        $params[] = '%' . $search . '%';
        $types .= 's';
    }
    if ($supHasSupplierName) {
        $parts[] = "s.supplier_name LIKE ?";
        $params[] = '%' . $search . '%';
        $types .= 's';
    }
    if ($supHasSupplierCode) {
        $parts[] = "s.supplier_code LIKE ?";
        $params[] = '%' . $search . '%';
        $types .= 's';
    }
    if ($supHasMobile) {
        $parts[] = "s.mobile LIKE ?";
        $params[] = '%' . $search . '%';
        $types .= 's';
    }

    if (!empty($parts)) {
        $where .= " AND (" . implode(' OR ', $parts) . ")";
    }
}

if ($supplierFilter > 0 && $purHasSupplierId) {
    $where .= " AND p.supplier_id = ? ";
    $params[] = $supplierFilter;
    $types .= 'i';
}

if ($statusFilter !== 'all' && $purHasPaymentStatus) {
    $where .= " AND p.payment_status = ? ";
    $params[] = $statusFilter;
    $types .= 's';
}

if ($fromDate !== '' && $purHasPurchaseDate) {
    $where .= " AND p.purchase_date >= ? ";
    $params[] = $fromDate;
    $types .= 's';
}

if ($toDate !== '' && $purHasPurchaseDate) {
    $where .= " AND p.purchase_date <= ? ";
    $params[] = $toDate;
    $types .= 's';
}

/* -------------------------------------------------------
   PURCHASE LIST
------------------------------------------------------- */
$sql = "SELECT p.id";

if ($purHasPurchaseNo) {
    $sql .= ", p.purchase_no";
}
if ($purHasPurchaseDate) {
    $sql .= ", p.purchase_date";
}
if ($purHasInvoiceNo) {
    $sql .= ", p.invoice_no";
}
if ($purHasSubtotal) {
    $sql .= ", p.subtotal";
}
if ($purHasDiscountAmount) {
    $sql .= ", p.discount_amount";
}
if ($purHasTaxableAmount) {
    $sql .= ", p.taxable_amount";
}
if ($purHasCgstAmount) {
    $sql .= ", p.cgst_amount";
}
if ($purHasSgstAmount) {
    $sql .= ", p.sgst_amount";
}
if ($purHasIgstAmount) {
    $sql .= ", p.igst_amount";
}
if ($purHasRoundOff) {
    $sql .= ", p.round_off";
}
if ($purHasGrandTotal) {
    $sql .= ", p.grand_total";
}
if ($purHasPaidAmount) {
    $sql .= ", p.paid_amount";
}
if ($purHasBalanceAmount) {
    $sql .= ", p.balance_amount";
}
if ($purHasPaymentStatus) {
    $sql .= ", p.payment_status";
}
if ($purHasNotes) {
    $sql .= ", p.notes";
}
if ($purHasCreatedAt) {
    $sql .= ", p.created_at";
}

if ($supHasSupplierName) {
    $sql .= ", s.supplier_name";
}
if ($supHasSupplierCode) {
    $sql .= ", s.supplier_code";
}
if ($supHasMobile) {
    $sql .= ", s.mobile AS supplier_mobile";
}

$sql .= " FROM purchases p
          LEFT JOIN suppliers s ON s.id = p.supplier_id
          $where
          ORDER BY p.id DESC";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die('Failed to prepare purchases query.');
}

if (!empty($params)) {
    $bindValues = [];
    $bindValues[] = $types;
    for ($i = 0; $i < count($params); $i++) {
        $bindValues[] = &$params[$i];
    }
    call_user_func_array([$stmt, 'bind_param'], $bindValues);
}

$stmt->execute();
$res = $stmt->get_result();
$purchases = [];
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $purchases[] = $row;
    }
}
$stmt->close();
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

                <div class="row">
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-primary mt-2"><?php echo $totalPurchases; ?></h3>
                                <p class="text-muted mb-0">Total Purchases</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-success mt-2"><?php echo $paidPurchases; ?></h3>
                                <p class="text-muted mb-0">Paid</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-warning mt-2"><?php echo $partialPurchases; ?></h3>
                                <p class="text-muted mb-0">Partial</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-danger mt-2"><?php echo $unpaidPurchases; ?></h3>
                                <p class="text-muted mb-0">Unpaid</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-12">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-info mt-2">₹ <?php echo money($totalPurchaseAmount); ?></h3>
                                <p class="text-muted mb-0">Total Purchase Amount</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <form method="get" class="row g-2 align-items-end">
                            <div class="col-md-3">
                                <label class="form-label">Search</label>
                                <input type="text" name="search" class="form-control" placeholder="Purchase no, invoice, supplier..." value="<?php echo h($search); ?>">
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Supplier</label>
                                <select name="supplier_id" class="form-select">
                                    <option value="0">All Suppliers</option>
                                    <?php foreach ($suppliers as $supplier): ?>
                                        <option value="<?php echo (int)$supplier['id']; ?>" <?php echo $supplierFilter === (int)$supplier['id'] ? 'selected' : ''; ?>>
                                            <?php
                                            echo h($supplier['supplier_name'] ?? '');
                                            if (!empty($supplier['supplier_code'])) {
                                                echo ' (' . h($supplier['supplier_code']) . ')';
                                            }
                                            ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Payment Status</label>
                                <select name="payment_status" class="form-select">
                                    <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All</option>
                                    <option value="Paid" <?php echo $statusFilter === 'Paid' ? 'selected' : ''; ?>>Paid</option>
                                    <option value="Partial" <?php echo $statusFilter === 'Partial' ? 'selected' : ''; ?>>Partial</option>
                                    <option value="Unpaid" <?php echo $statusFilter === 'Unpaid' ? 'selected' : ''; ?>>Unpaid</option>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">From Date</label>
                                <input type="date" name="from_date" class="form-control" value="<?php echo h($fromDate); ?>">
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">To Date</label>
                                <input type="date" name="to_date" class="form-control" value="<?php echo h($toDate); ?>">
                            </div>

                            <div class="col-md-1">
                                <button type="submit" class="btn btn-primary w-100">Go</button>
                            </div>

                            <div class="col-md-12">
                                <a href="purchases.php" class="btn btn-secondary">Reset</a>
                                <a href="purchase-add.php" class="btn btn-primary">Add Purchase</a>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Purchase No</th>
                                        <th>Date</th>
                                        <th>Supplier</th>
                                        <th>Invoice No</th>
                                        <th>Grand Total</th>
                                        <th>Paid</th>
                                        <th>Balance</th>
                                        <th>Status</th>
                                        <th>Created</th>
                                        <th style="min-width: 210px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($purchases)): ?>
                                        <?php foreach ($purchases as $index => $purchase): ?>
                                            <tr>
                                                <td><?php echo $index + 1; ?></td>

                                                <td>
                                                    <strong><?php echo h($purchase['purchase_no'] ?? ''); ?></strong>
                                                </td>

                                                <td>
                                                    <?php
                                                    if (!empty($purchase['purchase_date'])) {
                                                        echo date('d-m-Y', strtotime($purchase['purchase_date']));
                                                    } else {
                                                        echo '-';
                                                    }
                                                    ?>
                                                </td>

                                                <td>
                                                    <?php echo h($purchase['supplier_name'] ?? ''); ?>
                                                    <?php if (!empty($purchase['supplier_code'])): ?>
                                                        <br><small class="text-muted"><?php echo h($purchase['supplier_code']); ?></small>
                                                    <?php endif; ?>
                                                    <?php if (!empty($purchase['supplier_mobile'])): ?>
                                                        <br><small class="text-muted"><?php echo h($purchase['supplier_mobile']); ?></small>
                                                    <?php endif; ?>
                                                </td>

                                                <td><?php echo h($purchase['invoice_no'] ?? ''); ?></td>

                                                <td>₹ <?php echo money($purchase['grand_total'] ?? 0); ?></td>
                                                <td>₹ <?php echo money($purchase['paid_amount'] ?? 0); ?></td>
                                                <td>₹ <?php echo money($purchase['balance_amount'] ?? 0); ?></td>

                                                <td>
                                                    <?php
                                                    $status = (string)($purchase['payment_status'] ?? 'Unpaid');
                                                    $badge = 'secondary';

                                                    if ($status === 'Paid') {
                                                        $badge = 'success';
                                                    } elseif ($status === 'Partial') {
                                                        $badge = 'warning';
                                                    } elseif ($status === 'Unpaid') {
                                                        $badge = 'danger';
                                                    }
                                                    ?>
                                                    <span class="badge bg-<?php echo $badge; ?>">
                                                        <?php echo h($status); ?>
                                                    </span>
                                                </td>

                                                <td>
                                                    <?php
                                                    if (!empty($purchase['created_at'])) {
                                                        echo date('d-m-Y h:i A', strtotime($purchase['created_at']));
                                                    } else {
                                                        echo '-';
                                                    }
                                                    ?>
                                                </td>

                                                <td>
                                                    <a href="purchase-view.php?id=<?php echo (int)$purchase['id']; ?>" class="btn btn-sm btn-info mb-1">View</a>
                                                    <a href="purchase-edit.php?id=<?php echo (int)$purchase['id']; ?>" class="btn btn-sm btn-primary mb-1">Edit</a>
                                                    <a href="purchases.php?delete=<?php echo (int)$purchase['id']; ?>" class="btn btn-sm btn-danger mb-1" onclick="return confirm('Are you sure you want to delete this purchase?');">Delete</a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="11" class="text-center text-muted">
                                                No purchases found.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <?php include('includes/footer.php'); ?>
    </div>
</div>

<?php include('includes/rightbar.php'); ?>
<?php include('includes/scripts.php'); ?>

</body>
</html>