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

$pageTitle = 'View Sale';

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
if (!tableExists($conn, 'sales') || !tableExists($conn, 'sale_items')) {
    die('Required tables not found.');
}

/* -------------------------------------------------------
   GET SALE ID
------------------------------------------------------- */
$saleId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($saleId <= 0) {
    die('Invalid sale ID.');
}

/* -------------------------------------------------------
   COLUMN CHECKS - SALES
------------------------------------------------------- */
$salesHasBusinessId      = hasColumn($conn, 'sales', 'business_id');
$salesHasBillNo          = hasColumn($conn, 'sales', 'bill_no');
$salesHasBillDate        = hasColumn($conn, 'sales', 'bill_date');
$salesHasBillTime        = hasColumn($conn, 'sales', 'bill_time');
$salesHasCustomerId      = hasColumn($conn, 'sales', 'customer_id');
$salesHasCustomerName    = hasColumn($conn, 'sales', 'customer_name');
$salesHasCustomerMobile  = hasColumn($conn, 'sales', 'customer_mobile');
$salesHasBillType        = hasColumn($conn, 'sales', 'bill_type');
$salesHasPaymentMethodId = hasColumn($conn, 'sales', 'payment_method_id');
$salesHasPaymentRef      = hasColumn($conn, 'sales', 'payment_reference');
$salesHasSubtotal        = hasColumn($conn, 'sales', 'subtotal');
$salesHasDiscount        = hasColumn($conn, 'sales', 'discount_amount');
$salesHasTaxable         = hasColumn($conn, 'sales', 'taxable_amount');
$salesHasCgst           = hasColumn($conn, 'sales', 'cgst_amount');
$salesHasSgst           = hasColumn($conn, 'sales', 'sgst_amount');
$salesHasIgst           = hasColumn($conn, 'sales', 'igst_amount');
$salesHasRoundOff       = hasColumn($conn, 'sales', 'round_off');
$salesHasGrandTotal     = hasColumn($conn, 'sales', 'grand_total');
$salesHasPaidAmount     = hasColumn($conn, 'sales', 'paid_amount');
$salesHasBalanceAmount  = hasColumn($conn, 'sales', 'balance_amount');
$salesHasPaymentStatus  = hasColumn($conn, 'sales', 'payment_status');
$salesHasNotes          = hasColumn($conn, 'sales', 'notes');
$salesHasStatus         = hasColumn($conn, 'sales', 'status');
$salesHasCreatedBy      = hasColumn($conn, 'sales', 'created_by');
$salesHasCancelledBy    = hasColumn($conn, 'sales', 'cancelled_by');
$salesHasCancelledAt    = hasColumn($conn, 'sales', 'cancelled_at');
$salesHasCancelReason   = hasColumn($conn, 'sales', 'cancel_reason');
$salesHasCreatedAt      = hasColumn($conn, 'sales', 'created_at');

/* -------------------------------------------------------
   COLUMN CHECKS - SALE ITEMS
------------------------------------------------------- */
$itemsHasBusinessId        = hasColumn($conn, 'sale_items', 'business_id');
$itemsHasSaleId            = hasColumn($conn, 'sale_items', 'sale_id');
$itemsHasProductId         = hasColumn($conn, 'sale_items', 'product_id');
$itemsHasProductCode       = hasColumn($conn, 'sale_items', 'product_code');
$itemsHasBarcode           = hasColumn($conn, 'sale_items', 'barcode');
$itemsHasItemName          = hasColumn($conn, 'sale_items', 'item_name');
$itemsHasCategoryName      = hasColumn($conn, 'sale_items', 'category_name');
$itemsHasPurity            = hasColumn($conn, 'sale_items', 'purity');
$itemsHasHsnCode           = hasColumn($conn, 'sale_items', 'hsn_code');
$itemsHasQty               = hasColumn($conn, 'sale_items', 'qty');
$itemsHasGrossWeight       = hasColumn($conn, 'sale_items', 'gross_weight');
$itemsHasLessWeight        = hasColumn($conn, 'sale_items', 'less_weight');
$itemsHasNetWeight         = hasColumn($conn, 'sale_items', 'net_weight');
$itemsHasRateDate          = hasColumn($conn, 'sale_items', 'rate_date');
$itemsHasRatePerGram       = hasColumn($conn, 'sale_items', 'rate_per_gram');
$itemsHasMetalValue        = hasColumn($conn, 'sale_items', 'metal_value');
$itemsHasMakingChargeType  = hasColumn($conn, 'sale_items', 'making_charge_type');
$itemsHasMakingCharge      = hasColumn($conn, 'sale_items', 'making_charge');
$itemsHasWastagePercent    = hasColumn($conn, 'sale_items', 'wastage_percent');
$itemsHasWastageAmount     = hasColumn($conn, 'sale_items', 'wastage_amount');
$itemsHasStoneCharge       = hasColumn($conn, 'sale_items', 'stone_charge');
$itemsHasOtherCharge       = hasColumn($conn, 'sale_items', 'other_charge');
$itemsHasDiscountAmount    = hasColumn($conn, 'sale_items', 'discount_amount');
$itemsHasTaxableAmount     = hasColumn($conn, 'sale_items', 'taxable_amount');
$itemsHasGstPercent        = hasColumn($conn, 'sale_items', 'gst_percent');
$itemsHasGstAmount         = hasColumn($conn, 'sale_items', 'gst_amount');
$itemsHasTotalAmount       = hasColumn($conn, 'sale_items', 'total_amount');

/* -------------------------------------------------------
   OPTIONAL TABLES
------------------------------------------------------- */
$paymentMethodsExists = tableExists($conn, 'payment_methods');
$usersTableExists = tableExists($conn, 'users');

/* -------------------------------------------------------
   FETCH SALE
------------------------------------------------------- */
$sql = "SELECT s.id";

if ($salesHasBillNo)         $sql .= ", s.bill_no";
if ($salesHasBillDate)       $sql .= ", s.bill_date";
if ($salesHasBillTime)       $sql .= ", s.bill_time";
if ($salesHasCustomerId)     $sql .= ", s.customer_id";
if ($salesHasCustomerName)   $sql .= ", s.customer_name";
if ($salesHasCustomerMobile) $sql .= ", s.customer_mobile";
if ($salesHasBillType)       $sql .= ", s.bill_type";
if ($salesHasPaymentMethodId)$sql .= ", s.payment_method_id";
if ($salesHasPaymentRef)     $sql .= ", s.payment_reference";
if ($salesHasSubtotal)       $sql .= ", s.subtotal";
if ($salesHasDiscount)       $sql .= ", s.discount_amount";
if ($salesHasTaxable)        $sql .= ", s.taxable_amount";
if ($salesHasCgst)           $sql .= ", s.cgst_amount";
if ($salesHasSgst)           $sql .= ", s.sgst_amount";
if ($salesHasIgst)           $sql .= ", s.igst_amount";
if ($salesHasRoundOff)       $sql .= ", s.round_off";
if ($salesHasGrandTotal)     $sql .= ", s.grand_total";
if ($salesHasPaidAmount)     $sql .= ", s.paid_amount";
if ($salesHasBalanceAmount)  $sql .= ", s.balance_amount";
if ($salesHasPaymentStatus)  $sql .= ", s.payment_status";
if ($salesHasNotes)          $sql .= ", s.notes";
if ($salesHasStatus)         $sql .= ", s.status";
if ($salesHasCreatedBy)      $sql .= ", s.created_by";
if ($salesHasCancelledBy)    $sql .= ", s.cancelled_by";
if ($salesHasCancelledAt)    $sql .= ", s.cancelled_at";
if ($salesHasCancelReason)   $sql .= ", s.cancel_reason";
if ($salesHasCreatedAt)      $sql .= ", s.created_at";

if ($paymentMethodsExists && $salesHasPaymentMethodId) {
    $sql .= ", pm.method_name AS payment_method_name";
}

if ($usersTableExists && $salesHasCreatedBy) {
    $sql .= ", uc.full_name AS created_user_name";
}
if ($usersTableExists && $salesHasCancelledBy) {
    $sql .= ", ux.full_name AS cancelled_user_name";
}

$sql .= " FROM sales s";

if ($paymentMethodsExists && $salesHasPaymentMethodId) {
    $sql .= " LEFT JOIN payment_methods pm ON pm.id = s.payment_method_id";
}
if ($usersTableExists && $salesHasCreatedBy) {
    $sql .= " LEFT JOIN users uc ON uc.id = s.created_by";
}
if ($usersTableExists && $salesHasCancelledBy) {
    $sql .= " LEFT JOIN users ux ON ux.id = s.cancelled_by";
}

$sql .= " WHERE s.id = ?";
if ($salesHasBusinessId) {
    $sql .= " AND s.business_id = ?";
}

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die('Failed to prepare sale query.');
}

if ($salesHasBusinessId) {
    $stmt->bind_param('ii', $saleId, $businessId);
} else {
    $stmt->bind_param('i', $saleId);
}

$stmt->execute();
$res = $stmt->get_result();
$sale = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$sale) {
    die('Sale not found.');
}

/* -------------------------------------------------------
   FETCH SALE ITEMS
------------------------------------------------------- */
$itemSql = "SELECT id";
if ($itemsHasProductId)        $itemSql .= ", product_id";
if ($itemsHasProductCode)      $itemSql .= ", product_code";
if ($itemsHasBarcode)          $itemSql .= ", barcode";
if ($itemsHasItemName)         $itemSql .= ", item_name";
if ($itemsHasCategoryName)     $itemSql .= ", category_name";
if ($itemsHasPurity)           $itemSql .= ", purity";
if ($itemsHasHsnCode)          $itemSql .= ", hsn_code";
if ($itemsHasQty)              $itemSql .= ", qty";
if ($itemsHasGrossWeight)      $itemSql .= ", gross_weight";
if ($itemsHasLessWeight)       $itemSql .= ", less_weight";
if ($itemsHasNetWeight)        $itemSql .= ", net_weight";
if ($itemsHasRateDate)         $itemSql .= ", rate_date";
if ($itemsHasRatePerGram)      $itemSql .= ", rate_per_gram";
if ($itemsHasMetalValue)       $itemSql .= ", metal_value";
if ($itemsHasMakingChargeType) $itemSql .= ", making_charge_type";
if ($itemsHasMakingCharge)     $itemSql .= ", making_charge";
if ($itemsHasWastagePercent)   $itemSql .= ", wastage_percent";
if ($itemsHasWastageAmount)    $itemSql .= ", wastage_amount";
if ($itemsHasStoneCharge)      $itemSql .= ", stone_charge";
if ($itemsHasOtherCharge)      $itemSql .= ", other_charge";
if ($itemsHasDiscountAmount)   $itemSql .= ", discount_amount";
if ($itemsHasTaxableAmount)    $itemSql .= ", taxable_amount";
if ($itemsHasGstPercent)       $itemSql .= ", gst_percent";
if ($itemsHasGstAmount)        $itemSql .= ", gst_amount";
if ($itemsHasTotalAmount)      $itemSql .= ", total_amount";

$itemSql .= " FROM sale_items WHERE sale_id = ?";
if ($itemsHasBusinessId) {
    $itemSql .= " AND business_id = ?";
}
$itemSql .= " ORDER BY id ASC";

$stmt = $conn->prepare($itemSql);
if (!$stmt) {
    die('Failed to prepare sale items query.');
}

if ($itemsHasBusinessId) {
    $stmt->bind_param('ii', $saleId, $businessId);
} else {
    $stmt->bind_param('i', $saleId);
}

$stmt->execute();
$res = $stmt->get_result();
$saleItems = [];
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $saleItems[] = $row;
    }
}
$stmt->close();

function showMoney($value): string
{
    return '₹' . number_format((float)$value, 2);
}

function showQty($value): string
{
    return number_format((float)$value, 3);
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
                        <h4 class="mb-1">View Sale</h4>
                        <p class="text-muted mb-0">
                            <?php echo h($sale['bill_no'] ?? ('SALE-' . $sale['id'])); ?>
                        </p>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="sale-edit.php?id=<?php echo (int)$sale['id']; ?>" class="btn btn-primary">Edit</a>
<a href="print-bill.php?id=<?php echo (int)$sale['id']; ?>&auto_print=1" target="_blank" class="btn btn-info">Print</a>
                        <a href="sales.php" class="btn btn-secondary">Back</a>
                    </div>
                </div>

                <div class="row">
                    <div class="col-xl-8">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Sale Information</h5>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label text-muted">Bill No</label>
                                        <div><strong><?php echo h($sale['bill_no'] ?? ('SALE-' . $sale['id'])); ?></strong></div>
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label text-muted">Bill Date</label>
                                        <div>
                                            <?php
                                            echo !empty($sale['bill_date']) ? date('d-m-Y', strtotime($sale['bill_date'])) : '-';
                                            ?>
                                        </div>
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label text-muted">Bill Time</label>
                                        <div>
                                            <?php
                                            echo !empty($sale['bill_time']) ? date('h:i A', strtotime($sale['bill_time'])) : '-';
                                            ?>
                                        </div>
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label text-muted">Customer Name</label>
                                        <div><?php echo h($sale['customer_name'] ?? 'Walk-in Customer'); ?></div>
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label text-muted">Customer Mobile</label>
                                        <div><?php echo h($sale['customer_mobile'] ?? '-'); ?></div>
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label text-muted">Bill Type</label>
                                        <div><?php echo h($sale['bill_type'] ?? 'Retail'); ?></div>
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label text-muted">Payment Method</label>
                                        <div><?php echo h($sale['payment_method_name'] ?? '-'); ?></div>
                                    </div>

                                    <div class="col-md-8">
                                        <label class="form-label text-muted">Payment Reference</label>
                                        <div><?php echo h($sale['payment_reference'] ?? '-'); ?></div>
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label text-muted">Payment Status</label>
                                        <div>
                                            <?php
                                            $paymentStatus = (string)($sale['payment_status'] ?? 'Paid');
                                            if ($paymentStatus === 'Paid') {
                                                echo '<span class="badge bg-success">Paid</span>';
                                            } elseif ($paymentStatus === 'Partial') {
                                                echo '<span class="badge bg-warning text-dark">Partial</span>';
                                            } else {
                                                echo '<span class="badge bg-danger">Unpaid</span>';
                                            }
                                            ?>
                                        </div>
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label text-muted">Sale Status</label>
                                        <div>
                                            <?php
                                            $saleStatus = (string)($sale['status'] ?? 'Active');
                                            if ($saleStatus === 'Cancelled') {
                                                echo '<span class="badge bg-danger">Cancelled</span>';
                                            } else {
                                                echo '<span class="badge bg-success">Active</span>';
                                            }
                                            ?>
                                        </div>
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label text-muted">Created By</label>
                                        <div><?php echo h($sale['created_user_name'] ?? '-'); ?></div>
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label text-muted">Created At</label>
                                        <div>
                                            <?php
                                            echo !empty($sale['created_at']) ? date('d-m-Y h:i A', strtotime($sale['created_at'])) : '-';
                                            ?>
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <label class="form-label text-muted">Notes</label>
                                        <div><?php echo nl2br(h($sale['notes'] ?? '-')); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php if (($sale['status'] ?? '') === 'Cancelled'): ?>
                            <div class="card border-danger">
                                <div class="card-header bg-danger text-white">
                                    <h5 class="mb-0">Cancellation Details</h5>
                                </div>
                                <div class="card-body">
                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <label class="form-label text-muted">Cancelled By</label>
                                            <div><?php echo h($sale['cancelled_user_name'] ?? '-'); ?></div>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label text-muted">Cancelled At</label>
                                            <div>
                                                <?php
                                                echo !empty($sale['cancelled_at']) ? date('d-m-Y h:i A', strtotime($sale['cancelled_at'])) : '-';
                                                ?>
                                            </div>
                                        </div>
                                        <div class="col-md-12">
                                            <label class="form-label text-muted">Reason</label>
                                            <div><?php echo h($sale['cancel_reason'] ?? '-'); ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Sale Items</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-bordered table-striped align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Item</th>
                                                <th>Category</th>
                                                <th>Qty</th>
                                                <th>Net Wt</th>
                                                <th>Rate</th>
                                                <th>Making</th>
                                                <th>Stone</th>
                                                <th>Discount</th>
                                                <th>GST</th>
                                                <th>Total</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($saleItems)): ?>
                                                <?php foreach ($saleItems as $index => $item): ?>
                                                    <tr>
                                                        <td><?php echo $index + 1; ?></td>
                                                        <td>
                                                            <strong><?php echo h($item['item_name'] ?? '-'); ?></strong>
                                                            <?php if (!empty($item['product_code'])): ?>
                                                                <br><small class="text-muted"><?php echo h($item['product_code']); ?></small>
                                                            <?php endif; ?>
                                                            <?php if (!empty($item['barcode'])): ?>
                                                                <br><small class="text-muted"><?php echo h($item['barcode']); ?></small>
                                                            <?php endif; ?>
                                                            <?php if (!empty($item['purity'])): ?>
                                                                <br><small class="text-muted">Purity: <?php echo h($item['purity']); ?></small>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><?php echo h($item['category_name'] ?? '-'); ?></td>
                                                        <td><?php echo showQty($item['qty'] ?? 0); ?></td>
                                                        <td><?php echo showQty($item['net_weight'] ?? 0); ?></td>
                                                        <td><?php echo showMoney($item['rate_per_gram'] ?? 0); ?></td>
                                                        <td><?php echo showMoney($item['making_charge'] ?? 0); ?></td>
                                                        <td><?php echo showMoney($item['stone_charge'] ?? 0); ?></td>
                                                        <td><?php echo showMoney($item['discount_amount'] ?? 0); ?></td>
                                                        <td>
                                                            <?php
                                                            echo showMoney($item['gst_amount'] ?? 0);
                                                            if (isset($item['gst_percent'])) {
                                                                echo '<br><small class="text-muted">' . h($item['gst_percent']) . '%</small>';
                                                            }
                                                            ?>
                                                        </td>
                                                        <td><strong><?php echo showMoney($item['total_amount'] ?? 0); ?></strong></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="11" class="text-center text-muted">No sale items found.</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Amount Summary</h5>
                            </div>
                            <div class="card-body">
                                <table class="table table-bordered mb-0">
                                    <tr>
                                        <th>Subtotal</th>
                                        <td class="text-end"><?php echo showMoney($sale['subtotal'] ?? 0); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Discount</th>
                                        <td class="text-end"><?php echo showMoney($sale['discount_amount'] ?? 0); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Taxable</th>
                                        <td class="text-end"><?php echo showMoney($sale['taxable_amount'] ?? 0); ?></td>
                                    </tr>
                                    <tr>
                                        <th>CGST</th>
                                        <td class="text-end"><?php echo showMoney($sale['cgst_amount'] ?? 0); ?></td>
                                    </tr>
                                    <tr>
                                        <th>SGST</th>
                                        <td class="text-end"><?php echo showMoney($sale['sgst_amount'] ?? 0); ?></td>
                                    </tr>
                                    <tr>
                                        <th>IGST</th>
                                        <td class="text-end"><?php echo showMoney($sale['igst_amount'] ?? 0); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Round Off</th>
                                        <td class="text-end"><?php echo showMoney($sale['round_off'] ?? 0); ?></td>
                                    </tr>
                                    <tr class="table-light">
                                        <th>Grand Total</th>
                                        <td class="text-end"><strong><?php echo showMoney($sale['grand_total'] ?? 0); ?></strong></td>
                                    </tr>
                                    <tr>
                                        <th>Paid Amount</th>
                                        <td class="text-end"><?php echo showMoney($sale['paid_amount'] ?? 0); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Balance Amount</th>
                                        <td class="text-end"><?php echo showMoney($sale['balance_amount'] ?? 0); ?></td>
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