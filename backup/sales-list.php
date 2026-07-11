<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Kolkata');
require_once __DIR__ . '/includes/config.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not available. Check includes/config.php');
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn->set_charset('utf8mb4');

$pageTitle = 'Sales List';
$page_title = 'Sales List';
$currentPage = 'sales-list';

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('money')) {
    function money($amount): string
    {
        return number_format((float)$amount, 2);
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

function addAuditLogSafe(mysqli $conn, ?int $businessId, ?int $userId, string $module, string $action, int $refId, string $desc): void
{
    if (function_exists('addAuditLog')) {
        addAuditLog($conn, $businessId, $userId, $module, $action, $refId, $desc);
        return;
    }

    if (!tableExists($conn, 'audit_logs')) {
        return;
    }

    $sql = "INSERT INTO audit_logs
            (business_id, user_id, module_name, action_type, reference_id, description, ip_address, user_agent, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
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

if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    header('Location: ../login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$businessId = (int)($_SESSION['business_id'] ?? 0);
if ($businessId <= 0) {
    die('Business session not found. Please login again.');
}

$requiredTables = ['sales', 'sale_items'];
foreach ($requiredTables as $tbl) {
    if (!tableExists($conn, $tbl)) {
        die('Required table `' . h($tbl) . '` not found.');
    }
}

$hasProductStockTable = tableExists($conn, 'product_stock');
$hasStockMovementTable = tableExists($conn, 'stock_movements');
$hasProductsTable = tableExists($conn, 'products');
$hasSalePaymentsTable = tableExists($conn, 'sale_payments');
$hasPaymentMethodsTable = tableExists($conn, 'payment_methods');

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_sale'])) {
    $saleId = (int)($_POST['sale_id'] ?? 0);
    $reason = trim((string)($_POST['cancel_reason'] ?? 'Deleted from sales list'));

    if ($saleId <= 0) {
        $error = 'Invalid sale selected.';
    } else {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("SELECT id, bill_no, status FROM sales WHERE id = ? AND business_id = ? LIMIT 1 FOR UPDATE");
            if (!$stmt) {
                throw new Exception('Failed to prepare sale check.');
            }
            $stmt->bind_param('ii', $saleId, $businessId);
            $stmt->execute();
            $res = $stmt->get_result();
            $sale = $res ? $res->fetch_assoc() : null;
            $stmt->close();

            if (!$sale) {
                throw new Exception('Sale not found.');
            }
            if ((string)$sale['status'] === 'Cancelled') {
                throw new Exception('This sale is already cancelled.');
            }

            $items = [];
            $stmt = $conn->prepare("SELECT product_id, qty, net_weight FROM sale_items WHERE sale_id = ? AND business_id = ?");
            if (!$stmt) {
                throw new Exception('Failed to prepare sale items.');
            }
            $stmt->bind_param('ii', $saleId, $businessId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($res && $row = $res->fetch_assoc()) {
                $items[] = $row;
            }
            $stmt->close();

            $stmt = $conn->prepare("UPDATE sales SET status='Cancelled', cancelled_by=?, cancelled_at=NOW(), cancel_reason=?, updated_at=NOW() WHERE id=? AND business_id=? LIMIT 1");
            if (!$stmt) {
                throw new Exception('Failed to prepare sale cancellation.');
            }
            $stmt->bind_param('isii', $userId, $reason, $saleId, $businessId);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new Exception('Failed to cancel sale.');
            }
            $stmt->close();

            foreach ($items as $item) {
                $productId = (int)($item['product_id'] ?? 0);
                $qty = (float)($item['qty'] ?? 0);
                $netWeight = (float)($item['net_weight'] ?? 0);
                if ($productId <= 0 || $qty <= 0) {
                    continue;
                }

                if ($hasProductsTable && hasColumn($conn, 'products', 'current_stock_qty')) {
                    $stmt = $conn->prepare("UPDATE products SET current_stock_qty = IFNULL(current_stock_qty,0) + ?, updated_at = NOW() WHERE id = ? AND business_id = ? LIMIT 1");
                    if ($stmt) {
                        $stmt->bind_param('dii', $qty, $productId, $businessId);
                        $stmt->execute();
                        $stmt->close();
                    }
                }

                if ($hasProductStockTable && hasColumn($conn, 'product_stock', 'product_id')) {
                    $stmt = $conn->prepare("UPDATE product_stock SET out_qty = GREATEST(IFNULL(out_qty,0) - ?, 0), out_weight = GREATEST(IFNULL(out_weight,0) - ?, 0), closing_qty = IFNULL(closing_qty,0) + ?, closing_weight = IFNULL(closing_weight,0) + ?, updated_at = NOW() WHERE product_id = ? LIMIT 1");
                    if ($stmt) {
                        $stmt->bind_param('ddddi', $qty, $netWeight, $qty, $netWeight, $productId);
                        $stmt->execute();
                        $stmt->close();
                    }
                }

                if ($hasStockMovementTable) {
                    $remarksText = 'Sale cancelled / stock restored for bill ' . (string)$sale['bill_no'];
                    $stmt = $conn->prepare("INSERT INTO stock_movements (business_id, movement_date, product_id, movement_type, ref_table, ref_id, qty_in, qty_out, weight_in, weight_out, remarks, created_by, created_at) VALUES (?, NOW(), ?, 'Adjustment', 'sales', ?, ?, 0, ?, 0, ?, ?, NOW())");
                    if ($stmt) {
                        $stmt->bind_param('iiiddsi', $businessId, $productId, $saleId, $qty, $netWeight, $remarksText, $userId);
                        $stmt->execute();
                        $stmt->close();
                    }
                }
            }

            addAuditLogSafe($conn, $businessId, $userId, 'Sales', 'DELETE', $saleId, 'Sale bill ' . (string)$sale['bill_no'] . ' cancelled from sales list. Reason: ' . $reason);
            $conn->commit();
            $success = 'Sale cancelled successfully and stock restored.';
        } catch (Throwable $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    }
}

$fromDate = trim((string)($_GET['from_date'] ?? date('Y-m-01')));
$toDate = trim((string)($_GET['to_date'] ?? date('Y-m-d')));
$status = trim((string)($_GET['status'] ?? ''));
$search = trim((string)($_GET['search'] ?? ''));
$viewId = (int)($_GET['view_id'] ?? 0);

$where = 's.business_id = ?';
$params = [$businessId];
$types = 'i';

if ($fromDate !== '') {
    $where .= ' AND s.bill_date >= ?';
    $params[] = $fromDate;
    $types .= 's';
}
if ($toDate !== '') {
    $where .= ' AND s.bill_date <= ?';
    $params[] = $toDate;
    $types .= 's';
}
if ($status !== '' && in_array($status, ['Active', 'Cancelled'], true)) {
    $where .= ' AND s.status = ?';
    $params[] = $status;
    $types .= 's';
}
if ($search !== '') {
    $like = '%' . $search . '%';
    $where .= ' AND (s.bill_no LIKE ? OR s.customer_name LIKE ? OR s.customer_mobile LIKE ? OR s.payment_reference LIKE ?)';
    array_push($params, $like, $like, $like, $like);
    $types .= 'ssss';
}

$sales = [];
$sql = "SELECT s.*, pm.method_name
        FROM sales s
        LEFT JOIN payment_methods pm ON pm.id = s.payment_method_id
        WHERE {$where}
        ORDER BY s.bill_date DESC, s.id DESC";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $bind = [$types];
    foreach ($params as $k => $v) {
        $bind[] = &$params[$k];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && $row = $res->fetch_assoc()) {
        $sales[] = $row;
    }
    $stmt->close();
}

$totals = ['grand_total' => 0, 'paid_amount' => 0, 'balance_amount' => 0];
foreach ($sales as $row) {
    if ((string)($row['status'] ?? '') !== 'Cancelled') {
        $totals['grand_total'] += (float)$row['grand_total'];
        $totals['paid_amount'] += (float)$row['paid_amount'];
        $totals['balance_amount'] += (float)$row['balance_amount'];
    }
}

$viewSale = null;
$viewItems = [];
$viewPayments = [];
if ($viewId > 0) {
    $stmt = $conn->prepare("SELECT s.*, pm.method_name FROM sales s LEFT JOIN payment_methods pm ON pm.id = s.payment_method_id WHERE s.id=? AND s.business_id=? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('ii', $viewId, $businessId);
        $stmt->execute();
        $res = $stmt->get_result();
        $viewSale = $res ? $res->fetch_assoc() : null;
        $stmt->close();
    }

    if ($viewSale) {
        $stmt = $conn->prepare("SELECT * FROM sale_items WHERE sale_id=? AND business_id=? ORDER BY id ASC");
        if ($stmt) {
            $stmt->bind_param('ii', $viewId, $businessId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($res && $row = $res->fetch_assoc()) {
                $viewItems[] = $row;
            }
            $stmt->close();
        }

        if ($hasSalePaymentsTable && $hasPaymentMethodsTable) {
            $stmt = $conn->prepare("SELECT sp.*, pm.method_name FROM sale_payments sp LEFT JOIN payment_methods pm ON pm.id=sp.payment_method_id WHERE sp.sale_id=? ORDER BY sp.id ASC");
            if ($stmt) {
                $stmt->bind_param('i', $viewId);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($res && $row = $res->fetch_assoc()) {
                    $viewPayments[] = $row;
                }
                $stmt->close();
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<?php include('includes/head.php'); ?>

<style>
    html, body { min-height: 100%; }
    body[data-sidebar="dark"] { overflow-x: hidden; }
    .vertical-menu { height: 100vh !important; position: fixed; top: 0; bottom: 0; z-index: 1002; }
    .vertical-menu .h-100 { height: 100vh !important; overflow-y: auto !important; overflow-x: hidden !important; }
    #sidebar-menu { padding-bottom: 90px; }
    #sidebar-menu .metismenu { margin-bottom: 80px; }
    .main-content { min-height: 100vh; }
    .summary-card { border: 1px solid #eef0f4; border-radius: 12px; }
    .summary-label { color:#6c757d; font-size:13px; margin-bottom:4px; }
    .summary-value { font-size:22px; font-weight:800; color:#111827; margin:0; }
    .action-btns { display:flex; align-items:center; gap:6px; justify-content:flex-end; flex-wrap:wrap; }
    .table-nowrap td, .table-nowrap th { white-space: nowrap; }
    @media (max-width: 767px) {
        .action-btns { justify-content:flex-start; }
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
                <div class="row mb-3">
                    <div class="col-12">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div>
                                <h4 class="mb-1">Sales List</h4>
                                <div class="text-muted">View, print, edit and cancel sales bills</div>
                            </div>
                            <a href="billing.php" class="btn btn-primary"><i class="fas fa-plus me-1"></i> New Bill</a>
                        </div>
                    </div>
                </div>

                <?php if ($success !== ''): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo h($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo h($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <div class="card summary-card h-100"><div class="card-body"><div class="summary-label">Sales Total</div><h3 class="summary-value">₹<?php echo money($totals['grand_total']); ?></h3></div></div>
                    </div>
                    <div class="col-md-4">
                        <div class="card summary-card h-100"><div class="card-body"><div class="summary-label">Paid Amount</div><h3 class="summary-value">₹<?php echo money($totals['paid_amount']); ?></h3></div></div>
                    </div>
                    <div class="col-md-4">
                        <div class="card summary-card h-100"><div class="card-body"><div class="summary-label">Balance Amount</div><h3 class="summary-value">₹<?php echo money($totals['balance_amount']); ?></h3></div></div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-body">
                        <form method="get" class="row g-3 align-items-end">
                            <div class="col-md-2">
                                <label class="form-label">From Date</label>
                                <input type="date" name="from_date" class="form-control" value="<?php echo h($fromDate); ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">To Date</label>
                                <input type="date" name="to_date" class="form-control" value="<?php echo h($toDate); ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select">
                                    <option value="">All</option>
                                    <option value="Active" <?php echo $status === 'Active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="Cancelled" <?php echo $status === 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Search</label>
                                <input type="text" name="search" class="form-control" placeholder="Bill no / customer / mobile / reference" value="<?php echo h($search); ?>">
                            </div>
                            <div class="col-md-2 d-flex gap-2">
                                <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search me-1"></i> Search</button>
                                <a href="sales-list.php" class="btn btn-light border"><i class="fas fa-redo"></i></a>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                            <h5 class="mb-0">Sales Bills</h5>
                            <span class="badge bg-primary"><?php echo count($sales); ?> Records</span>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle table-nowrap mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Bill No</th>
                                        <th>Date</th>
                                        <th>Customer</th>
                                        <th>Type</th>
                                        <th class="text-end">Grand Total</th>
                                        <th class="text-end">Paid</th>
                                        <th class="text-end">Balance</th>
                                        <th>Status</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($sales)): ?>
                                    <tr><td colspan="9" class="text-center text-muted py-4">No sales found</td></tr>
                                <?php else: foreach ($sales as $row): ?>
                                    <?php $isCancelled = ((string)($row['status'] ?? '') === 'Cancelled'); ?>
                                    <tr class="<?php echo $isCancelled ? 'table-danger' : ''; ?>">
                                        <td><strong><?php echo h($row['bill_no']); ?></strong><br><small class="text-muted"><?php echo h($row['method_name'] ?? '-'); ?></small></td>
                                        <td><?php echo !empty($row['bill_date']) ? h(date('d-m-Y', strtotime($row['bill_date']))) : '-'; ?><br><small class="text-muted"><?php echo h(substr((string)($row['bill_time'] ?? ''), 0, 5)); ?></small></td>
                                        <td><?php echo h($row['customer_name'] ?: 'Walk-in Customer'); ?><br><small class="text-muted"><?php echo h($row['customer_mobile']); ?></small></td>
                                        <td><?php echo h($row['bill_type']); ?></td>
                                        <td class="text-end">₹<?php echo money($row['grand_total']); ?></td>
                                        <td class="text-end">₹<?php echo money($row['paid_amount']); ?></td>
                                        <td class="text-end">₹<?php echo money($row['balance_amount']); ?></td>
                                        <td>
                                            <?php if ($isCancelled): ?>
                                                <span class="badge bg-danger">Cancelled</span>
                                            <?php else: ?>
                                                <span class="badge bg-success">Active</span>
                                                <span class="badge bg-secondary"><?php echo h($row['payment_status']); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="action-btns">
                                                <a href="sales-list.php?<?php echo h(http_build_query(array_merge($_GET, ['view_id' => $row['id']]))); ?>" class="btn btn-sm btn-outline-info" title="View"><i class="fas fa-eye"></i></a>
                                                <a href="sales-print.php?id=<?php echo (int)$row['id']; ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="Print"><i class="fas fa-print"></i></a>
                                                <?php if (!$isCancelled): ?>
                                                    
                                                    <form method="post" class="d-inline" onsubmit="return confirm('Are you sure you want to cancel this sale? Stock will be restored.');">
                                                        <input type="hidden" name="sale_id" value="<?php echo (int)$row['id']; ?>">
                                                        <input type="hidden" name="cancel_reason" value="Deleted from sales list">
                                                        <button type="submit" name="delete_sale" class="btn btn-sm btn-outline-danger" title="Delete"><i class="fas fa-trash"></i></button>
                                                    </form>
                                                <?php else: ?>
                                                    <button type="button" class="btn btn-sm btn-outline-primary" disabled><i class="fas fa-edit"></i></button>
                                                    <button type="button" class="btn btn-sm btn-outline-danger" disabled><i class="fas fa-trash"></i></button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <?php if ($viewSale): ?>
                <div class="modal fade" id="saleViewModal" tabindex="-1" aria-labelledby="saleViewModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-xl modal-dialog-scrollable">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="saleViewModalLabel">Sale Details - <?php echo h($viewSale['bill_no']); ?></h5>
                                <a href="sales-list.php?<?php $tmp = $_GET; unset($tmp['view_id']); echo h(http_build_query($tmp)); ?>" class="btn-close" aria-label="Close"></a>
                            </div>
                            <div class="modal-body">
                                <div class="row g-3 mb-3">
                                    <div class="col-md-3"><strong>Bill No:</strong><br><?php echo h($viewSale['bill_no']); ?></div>
                                    <div class="col-md-3"><strong>Date:</strong><br><?php echo h(date('d-m-Y', strtotime($viewSale['bill_date']))); ?> <?php echo h(substr((string)$viewSale['bill_time'], 0, 5)); ?></div>
                                    <div class="col-md-3"><strong>Customer:</strong><br><?php echo h($viewSale['customer_name'] ?: 'Walk-in Customer'); ?><br><small><?php echo h($viewSale['customer_mobile']); ?></small></div>
                                    <div class="col-md-3"><strong>Status:</strong><br><?php echo h($viewSale['status']); ?> / <?php echo h($viewSale['payment_status']); ?></div>
                                </div>
                                <div class="table-responsive mb-3">
                                    <table class="table table-sm table-bordered align-middle">
                                        <thead class="table-light">
                                            <tr>
                                                <th>#</th><th>Item</th><th>Purity</th><th class="text-end">Qty</th><th class="text-end">Net Wt</th><th class="text-end">Rate</th><th class="text-end">Taxable</th><th class="text-end">GST</th><th class="text-end">Total</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($viewItems as $i => $item): ?>
                                            <tr>
                                                <td><?php echo $i + 1; ?></td>
                                                <td><?php echo h($item['item_name']); ?><br><small class="text-muted"><?php echo h($item['product_code']); ?> <?php echo h($item['barcode']); ?></small></td>
                                                <td><?php echo h($item['purity']); ?></td>
                                                <td class="text-end"><?php echo money($item['qty']); ?></td>
                                                <td class="text-end"><?php echo money($item['net_weight']); ?></td>
                                                <td class="text-end">₹<?php echo money($item['rate_per_gram']); ?></td>
                                                <td class="text-end">₹<?php echo money($item['taxable_amount']); ?></td>
                                                <td class="text-end">₹<?php echo money($item['gst_amount']); ?></td>
                                                <td class="text-end">₹<?php echo money($item['total_amount']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php if (!empty($viewPayments)): ?>
                                <h6>Payment Split</h6>
                                <div class="table-responsive mb-3">
                                    <table class="table table-sm table-bordered align-middle">
                                        <thead class="table-light"><tr><th>Method</th><th>Reference</th><th class="text-end">Amount</th></tr></thead>
                                        <tbody>
                                        <?php foreach ($viewPayments as $pay): ?>
                                            <tr><td><?php echo h($pay['method_name']); ?></td><td><?php echo h($pay['reference_no'] ?? ''); ?></td><td class="text-end">₹<?php echo money($pay['amount']); ?></td></tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php endif; ?>
                                <div class="row justify-content-end">
                                    <div class="col-md-5">
                                        <table class="table table-sm">
                                            <tr><th>Subtotal</th><td class="text-end">₹<?php echo money($viewSale['subtotal']); ?></td></tr>
                                            <tr><th>Discount</th><td class="text-end">₹<?php echo money($viewSale['discount_amount']); ?></td></tr>
                                            <tr><th>Taxable</th><td class="text-end">₹<?php echo money($viewSale['taxable_amount']); ?></td></tr>
                                            <tr><th>CGST</th><td class="text-end">₹<?php echo money($viewSale['cgst_amount']); ?></td></tr>
                                            <tr><th>SGST</th><td class="text-end">₹<?php echo money($viewSale['sgst_amount']); ?></td></tr>
                                            <tr><th>Round Off</th><td class="text-end">₹<?php echo money($viewSale['round_off']); ?></td></tr>
                                            <tr class="table-light"><th>Grand Total</th><td class="text-end"><strong>₹<?php echo money($viewSale['grand_total']); ?></strong></td></tr>
                                            <tr><th>Paid</th><td class="text-end">₹<?php echo money($viewSale['paid_amount']); ?></td></tr>
                                            <tr><th>Balance</th><td class="text-end">₹<?php echo money($viewSale['balance_amount']); ?></td></tr>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <a href="sales-print.php?id=<?php echo (int)$viewSale['id']; ?>" target="_blank" class="btn btn-secondary"><i class="fas fa-print me-1"></i> Print</a>
                                
                                <a href="sales-list.php?<?php $tmp = $_GET; unset($tmp['view_id']); echo h(http_build_query($tmp)); ?>" class="btn btn-light border">Close</a>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </div>

        <?php include('includes/footer.php'); ?>
    </div>
</div>

<?php include('includes/rightbar.php'); ?>
<?php include('includes/scripts.php'); ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.body.setAttribute('data-sidebar', 'dark');
    var sidebarScroll = document.querySelector('.vertical-menu [data-simplebar]');
    if (sidebarScroll) {
        sidebarScroll.style.height = '100vh';
        sidebarScroll.style.overflowY = 'auto';
        sidebarScroll.style.overflowX = 'hidden';
    }
    <?php if ($viewSale): ?>
    var modalEl = document.getElementById('saleViewModal');
    if (modalEl && window.bootstrap) {
        var modal = new bootstrap.Modal(modalEl);
        modal.show();
    }
    <?php endif; ?>
});
</script>

</body>
</html>
