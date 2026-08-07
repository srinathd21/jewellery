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
    function h($value): string {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('tableExists')) {
    function tableExists(mysqli $conn, string $tableName): bool {
        $safe = $conn->real_escape_string($tableName);
        $res = $conn->query("SHOW TABLES LIKE '{$safe}'");
        return $res && $res->num_rows > 0;
    }
}

if (!function_exists('hasColumn')) {
    function hasColumn(mysqli $conn, string $table, string $column): bool {
        $table = $conn->real_escape_string($table);
        $column = $conn->real_escape_string($column);
        $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
        return $res && $res->num_rows > 0;
    }
}

function money($amount): string {
    return number_format((float)$amount, 2);
}

function qty($amount): string {
    return number_format((float)$amount, 3);
}

$pageTitle = 'View Purchase';
$page_title = 'View Purchase';
$currentPage = 'purchases';

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

if (!$stmt) {
    die('Role check failed.');
}

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
if (!tableExists($conn, 'purchases') || !tableExists($conn, 'purchase_items')) {
    die('Required tables not found. Please import the SQL first.');
}

$purHasBusinessId = hasColumn($conn, 'purchases', 'business_id');
$pitHasBusinessId = hasColumn($conn, 'purchase_items', 'business_id');
$supHasBusinessId = tableExists($conn, 'suppliers') && hasColumn($conn, 'suppliers', 'business_id');

$purchaseId = 0;

if (isset($_GET['id']) && (int)$_GET['id'] > 0) {
    $purchaseId = (int)$_GET['id'];
} elseif (isset($_GET['purchase_id']) && (int)$_GET['purchase_id'] > 0) {
    $purchaseId = (int)$_GET['purchase_id'];
}

if ($purchaseId <= 0) {
    header('Location: purchases.php');
    exit;
}

/* -------------------------------------------------------
   LOAD PURCHASE
------------------------------------------------------- */
$sql = "
    SELECT 
        p.*,
        s.supplier_name,
        " . (tableExists($conn, 'suppliers') && hasColumn($conn, 'suppliers', 'supplier_code') ? "s.supplier_code," : "'' AS supplier_code,") . "
        " . (tableExists($conn, 'suppliers') && hasColumn($conn, 'suppliers', 'mobile') ? "s.mobile AS supplier_mobile," : "'' AS supplier_mobile,") . "
        " . (tableExists($conn, 'suppliers') && hasColumn($conn, 'suppliers', 'gstin') ? "s.gstin AS supplier_gstin," : "'' AS supplier_gstin,") . "
        " . (tableExists($conn, 'suppliers') && hasColumn($conn, 'suppliers', 'address_line1') ? "s.address_line1 AS supplier_address," : "'' AS supplier_address,") . "
        " . (tableExists($conn, 'payment_methods') && hasColumn($conn, 'purchases', 'payment_method_id') ? "pm.method_name AS payment_method_name" : "'' AS payment_method_name") . "
    FROM purchases p
    LEFT JOIN suppliers s ON s.id = p.supplier_id
    " . (tableExists($conn, 'payment_methods') && hasColumn($conn, 'purchases', 'payment_method_id') ? "LEFT JOIN payment_methods pm ON pm.id = p.payment_method_id" : "") . "
    WHERE p.id = ?
";

if ($purHasBusinessId) {
    $sql .= " AND p.business_id = ?";
}

$sql .= " LIMIT 1";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die('Failed to prepare purchase query: ' . $conn->error);
}

if ($purHasBusinessId) {
    $stmt->bind_param('ii', $purchaseId, $businessId);
} else {
    $stmt->bind_param('i', $purchaseId);
}

$stmt->execute();
$res = $stmt->get_result();
$purchase = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$purchase) {
    die('Purchase not found.');
}

/* -------------------------------------------------------
   LOAD ITEMS
------------------------------------------------------- */
$items = [];

$sql = "
    SELECT 
        pi.*,
        " . (tableExists($conn, 'products') ? "p.product_code," : "'' AS product_code,") . "
        " . (tableExists($conn, 'products') && hasColumn($conn, 'products', 'barcode') ? "p.barcode" : "'' AS barcode") . "
    FROM purchase_items pi
    LEFT JOIN products p ON p.id = pi.product_id
    WHERE pi.purchase_id = ?
";

if ($pitHasBusinessId) {
    $sql .= " AND pi.business_id = ?";
}

$sql .= " ORDER BY pi.id ASC";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die('Failed to prepare purchase item query: ' . $conn->error);
}

if ($pitHasBusinessId) {
    $stmt->bind_param('ii', $purchaseId, $businessId);
} else {
    $stmt->bind_param('i', $purchaseId);
}

$stmt->execute();
$res = $stmt->get_result();

while ($res && ($row = $res->fetch_assoc())) {
    $items[] = $row;
}

$stmt->close();

include('includes/head.php');
?>

<style>
    .purchase-summary-table th {
        width: 45%;
        background: #f8f9fa;
    }

    .info-table th {
        width: 180px;
        background: #f8f9fa;
    }

    #itemsTable {
        min-width: 1600px;
    }

    #itemsTable th,
    #itemsTable td {
        white-space: nowrap;
        vertical-align: middle;
    }

    @media print {
        .vertical-menu,
        .navbar-header,
        .right-bar,
        .footer,
        .no-print {
            display: none !important;
        }

        .main-content {
            margin-left: 0 !important;
        }

        .page-content {
            padding: 0 !important;
        }

        .card {
            border: 1px solid #ddd !important;
            box-shadow: none !important;
        }
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

                <div class="row mb-3 no-print">
                    <div class="col-12 d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div>
                            <h4 class="mb-1">View Purchase</h4>
                            <p class="text-muted mb-0">Purchase details and item summary</p>
                        </div>
                        <div class="d-flex gap-2 flex-wrap">
                            <button type="button" onclick="window.print()" class="btn btn-info">
                                <i class="fas fa-print"></i> Print
                            </button>
                            <a href="purchase-edit.php?id=<?php echo (int)$purchaseId; ?>" class="btn btn-primary">
                                <i class="fas fa-edit"></i> Edit
                            </a>
                            <a href="purchases.php" class="btn btn-secondary">
                                <i class="fas fa-arrow-left"></i> Back
                            </a>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
                            <div>
                                <h4 class="mb-1">Purchase Invoice</h4>
                                <p class="text-muted mb-0"><?php echo h($purchase['purchase_no'] ?? ''); ?></p>
                            </div>
                            <div class="text-end">
                                <h5 class="mb-1">
                                    <?php
                                    $status = (string)($purchase['payment_status'] ?? 'Unpaid');
                                    $badge = 'danger';
                                    if ($status === 'Paid') {
                                        $badge = 'success';
                                    } elseif ($status === 'Partial') {
                                        $badge = 'warning';
                                    }
                                    ?>
                                    <span class="badge bg-<?php echo h($badge); ?>"><?php echo h($status); ?></span>
                                </h5>
                                <p class="text-muted mb-0">
                                    Date:
                                    <?php echo !empty($purchase['purchase_date']) ? date('d-m-Y', strtotime($purchase['purchase_date'])) : '-'; ?>
                                </p>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-lg-6 mb-3">
                                <table class="table table-bordered info-table mb-0">
                                    <tr>
                                        <th>Purchase No</th>
                                        <td><?php echo h($purchase['purchase_no'] ?? ''); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Purchase Date</th>
                                        <td><?php echo !empty($purchase['purchase_date']) ? date('d-m-Y', strtotime($purchase['purchase_date'])) : '-'; ?></td>
                                    </tr>
                                    <tr>
                                        <th>Invoice No</th>
                                        <td><?php echo h($purchase['invoice_no'] ?? ''); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Payment Method</th>
                                        <td><?php echo h($purchase['payment_method_name'] ?? ''); ?></td>
                                    </tr>
                                </table>
                            </div>

                            <div class="col-lg-6 mb-3">
                                <table class="table table-bordered info-table mb-0">
                                    <tr>
                                        <th>Supplier</th>
                                        <td>
                                            <strong><?php echo h($purchase['supplier_name'] ?? ''); ?></strong>
                                            <?php if (!empty($purchase['supplier_code'])): ?>
                                                <br><small class="text-muted"><?php echo h($purchase['supplier_code']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Mobile</th>
                                        <td><?php echo h($purchase['supplier_mobile'] ?? ''); ?></td>
                                    </tr>
                                    <tr>
                                        <th>GSTIN</th>
                                        <td><?php echo h($purchase['supplier_gstin'] ?? ''); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Address</th>
                                        <td><?php echo h($purchase['supplier_address'] ?? ''); ?></td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        <?php if (!empty($purchase['notes'])): ?>
                            <div class="alert alert-light border">
                                <strong>Notes:</strong> <?php echo h($purchase['notes']); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-list"></i> Purchase Items
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle mb-0" id="itemsTable">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Product Code</th>
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
                                        <th>Item Amount</th>
                                        <th>Discount</th>
                                        <th>Taxable</th>
                                        <th>GST %</th>
                                        <th>GST Amt</th>
                                        <th>Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($items)): ?>
                                        <?php foreach ($items as $index => $item): ?>
                                            <tr>
                                                <td><?php echo $index + 1; ?></td>
                                                <td><?php echo h($item['product_code'] ?? ''); ?></td>
                                                <td><strong><?php echo h($item['item_name'] ?? ''); ?></strong></td>
                                                <td><?php echo h($item['purity'] ?? ''); ?></td>
                                                <td><?php echo h($item['hsn_code'] ?? ''); ?></td>
                                                <td><?php echo qty($item['qty'] ?? 0); ?></td>
                                                <td><?php echo qty($item['gross_weight'] ?? 0); ?></td>
                                                <td><?php echo qty($item['less_weight'] ?? 0); ?></td>
                                                <td><?php echo qty($item['net_weight'] ?? 0); ?></td>
                                                <td>₹<?php echo money($item['rate_per_gram'] ?? 0); ?></td>
                                                <td>₹<?php echo money($item['making_charge'] ?? 0); ?></td>
                                                <td>₹<?php echo money($item['stone_charge'] ?? 0); ?></td>
                                                <td>₹<?php echo money($item['item_amount'] ?? 0); ?></td>
                                                <td>₹<?php echo money($item['discount_amount'] ?? 0); ?></td>
                                                <td>₹<?php echo money($item['taxable_amount'] ?? 0); ?></td>
                                                <td><?php echo money($item['gst_percent'] ?? 0); ?>%</td>
                                                <td>₹<?php echo money($item['gst_amount'] ?? 0); ?></td>
                                                <td><strong>₹<?php echo money($item['total_amount'] ?? 0); ?></strong></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="18" class="text-center text-muted">No purchase items found.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="row justify-content-end">
                    <div class="col-lg-5">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-calculator"></i> Purchase Summary
                                </h5>
                            </div>
                            <div class="card-body">
                                <table class="table table-bordered purchase-summary-table mb-0">
                                    <tr>
                                        <th>Subtotal</th>
                                        <td class="text-end">₹<?php echo money($purchase['subtotal'] ?? 0); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Discount</th>
                                        <td class="text-end">₹<?php echo money($purchase['discount_amount'] ?? 0); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Taxable Amount</th>
                                        <td class="text-end">₹<?php echo money($purchase['taxable_amount'] ?? 0); ?></td>
                                    </tr>
                                    <tr>
                                        <th>CGST</th>
                                        <td class="text-end">₹<?php echo money($purchase['cgst_amount'] ?? 0); ?></td>
                                    </tr>
                                    <tr>
                                        <th>SGST</th>
                                        <td class="text-end">₹<?php echo money($purchase['sgst_amount'] ?? 0); ?></td>
                                    </tr>
                                    <tr>
                                        <th>IGST</th>
                                        <td class="text-end">₹<?php echo money($purchase['igst_amount'] ?? 0); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Round Off</th>
                                        <td class="text-end">₹<?php echo money($purchase['round_off'] ?? 0); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Grand Total</th>
                                        <td class="text-end"><strong>₹<?php echo money($purchase['grand_total'] ?? 0); ?></strong></td>
                                    </tr>
                                    <tr>
                                        <th>Paid Amount</th>
                                        <td class="text-end text-success">₹<?php echo money($purchase['paid_amount'] ?? 0); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Balance</th>
                                        <td class="text-end text-danger"><strong>₹<?php echo money($purchase['balance_amount'] ?? 0); ?></strong></td>
                                    </tr>
                                </table>
                            </div>
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