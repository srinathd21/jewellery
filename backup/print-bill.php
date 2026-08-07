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

function money($amount, $symbol = '₹'): string
{
    return $symbol . number_format((float)$amount, 2);
}

function qty3($value): string
{
    return number_format((float)$value, 3);
}

$pageTitle = 'Print Bill';

/* -------------------------------------------------------
   AUTH CHECK
------------------------------------------------------- */
if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    header('Location: ../login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$sessionBusinessId = (int)($_SESSION['business_id'] ?? 0);

if ($sessionBusinessId <= 0) {
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
    die('Required sales tables not found.');
}

/* -------------------------------------------------------
   SALE ID
------------------------------------------------------- */
$saleId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$autoPrint = isset($_GET['auto_print']) ? (int)$_GET['auto_print'] : 0;

if ($saleId <= 0) {
    die('Invalid bill ID.');
}

/* -------------------------------------------------------
   COLUMN CHECKS
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
$salesHasCgst            = hasColumn($conn, 'sales', 'cgst_amount');
$salesHasSgst            = hasColumn($conn, 'sales', 'sgst_amount');
$salesHasIgst            = hasColumn($conn, 'sales', 'igst_amount');
$salesHasRoundOff        = hasColumn($conn, 'sales', 'round_off');
$salesHasGrandTotal      = hasColumn($conn, 'sales', 'grand_total');
$salesHasPaidAmount      = hasColumn($conn, 'sales', 'paid_amount');
$salesHasBalanceAmount   = hasColumn($conn, 'sales', 'balance_amount');
$salesHasPaymentStatus   = hasColumn($conn, 'sales', 'payment_status');
$salesHasNotes           = hasColumn($conn, 'sales', 'notes');
$salesHasStatus          = hasColumn($conn, 'sales', 'status');

$saleItemsHasBusinessId   = hasColumn($conn, 'sale_items', 'business_id');
$saleItemsHasSaleId       = hasColumn($conn, 'sale_items', 'sale_id');
$saleItemsHasProductCode  = hasColumn($conn, 'sale_items', 'product_code');
$saleItemsHasBarcode      = hasColumn($conn, 'sale_items', 'barcode');
$saleItemsHasItemName     = hasColumn($conn, 'sale_items', 'item_name');
$saleItemsHasCategoryName = hasColumn($conn, 'sale_items', 'category_name');
$saleItemsHasPurity       = hasColumn($conn, 'sale_items', 'purity');
$saleItemsHasHsnCode      = hasColumn($conn, 'sale_items', 'hsn_code');
$saleItemsHasQty          = hasColumn($conn, 'sale_items', 'qty');
$saleItemsHasGrossWeight  = hasColumn($conn, 'sale_items', 'gross_weight');
$saleItemsHasLessWeight   = hasColumn($conn, 'sale_items', 'less_weight');
$saleItemsHasNetWeight    = hasColumn($conn, 'sale_items', 'net_weight');
$saleItemsHasRate         = hasColumn($conn, 'sale_items', 'rate_per_gram');
$saleItemsHasMetalValue   = hasColumn($conn, 'sale_items', 'metal_value');
$saleItemsHasMakingType   = hasColumn($conn, 'sale_items', 'making_charge_type');
$saleItemsHasMaking       = hasColumn($conn, 'sale_items', 'making_charge');
$saleItemsHasWastagePct   = hasColumn($conn, 'sale_items', 'wastage_percent');
$saleItemsHasWastageAmt   = hasColumn($conn, 'sale_items', 'wastage_amount');
$saleItemsHasStoneCharge  = hasColumn($conn, 'sale_items', 'stone_charge');
$saleItemsHasOtherCharge  = hasColumn($conn, 'sale_items', 'other_charge');
$saleItemsHasDiscount     = hasColumn($conn, 'sale_items', 'discount_amount');
$saleItemsHasTaxable      = hasColumn($conn, 'sale_items', 'taxable_amount');
$saleItemsHasGstPct       = hasColumn($conn, 'sale_items', 'gst_percent');
$saleItemsHasGstAmt       = hasColumn($conn, 'sale_items', 'gst_amount');
$saleItemsHasTotal        = hasColumn($conn, 'sale_items', 'total_amount');

$paymentMethodsExists = tableExists($conn, 'payment_methods');
$companySettingsExists = tableExists($conn, 'company_settings');

/* -------------------------------------------------------
   LOAD COMPANY SETTINGS
------------------------------------------------------- */
$company = [
    'company_name' => 'Business',
    'owner_name' => '',
    'mobile' => '',
    'whatsapp' => '',
    'email' => '',
    'address_line1' => '',
    'address_line2' => '',
    'city' => '',
    'state' => '',
    'pincode' => '',
    'country' => 'India',
    'gstin' => '',
    'pan_no' => '',
    'currency_symbol' => '₹',
    'logo_path' => '',
    'bill_footer' => '',
    'terms_conditions' => ''
];

if ($companySettingsExists) {
    $stmt = $conn->prepare("
        SELECT *
        FROM company_settings
        WHERE business_id = ?
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param('i', $sessionBusinessId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if ($row) {
            $company = array_merge($company, $row);
        }
    }
}

/* -------------------------------------------------------
   LOAD SALE
------------------------------------------------------- */
$sql = "SELECT s.*";
if ($paymentMethodsExists && $salesHasPaymentMethodId) {
    $sql .= ", pm.method_name AS payment_method_name";
}
$sql .= " FROM sales s";
if ($paymentMethodsExists && $salesHasPaymentMethodId) {
    $sql .= " LEFT JOIN payment_methods pm ON pm.id = s.payment_method_id";
}
$sql .= " WHERE s.id = ?";
if ($salesHasBusinessId) {
    $sql .= " AND s.business_id = ?";
}
$sql .= " LIMIT 1";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die('Failed to prepare bill query.');
}

if ($salesHasBusinessId) {
    $stmt->bind_param('ii', $saleId, $sessionBusinessId);
} else {
    $stmt->bind_param('i', $saleId);
}

$stmt->execute();
$res = $stmt->get_result();
$sale = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$sale) {
    die('Bill not found.');
}

/* -------------------------------------------------------
   LOAD SALE ITEMS
------------------------------------------------------- */
$sql = "SELECT * FROM sale_items WHERE sale_id = ?";
if ($saleItemsHasBusinessId) {
    $sql .= " AND business_id = ?";
}
$sql .= " ORDER BY id ASC";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die('Failed to prepare sale items query.');
}

if ($saleItemsHasBusinessId) {
    $stmt->bind_param('ii', $saleId, $sessionBusinessId);
} else {
    $stmt->bind_param('i', $saleId);
}

$stmt->execute();
$res = $stmt->get_result();
$items = [];
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $items[] = $row;
    }
}
$stmt->close();

$currency = (string)($company['currency_symbol'] ?? '₹');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?php echo h($sale['bill_no'] ?? 'Print Bill'); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <style>
        :root {
            --text: #111827;
            --muted: #6b7280;
            --border: #d1d5db;
            --light: #f9fafb;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 20px;
            font-family: Arial, Helvetica, sans-serif;
            color: var(--text);
            background: #f3f4f6;
        }

        .print-toolbar {
            max-width: 1000px;
            margin: 0 auto 16px auto;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .print-toolbar a,
        .print-toolbar button {
            border: 0;
            background: #111827;
            color: #fff;
            padding: 10px 14px;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            font-size: 14px;
        }

        .print-toolbar a.secondary {
            background: #6b7280;
        }

        .bill-wrap {
            max-width: 1000px;
            margin: 0 auto;
            background: #fff;
            border: 1px solid var(--border);
            padding: 24px;
        }

        .bill-header {
            display: flex;
            justify-content: space-between;
            gap: 20px;
            border-bottom: 2px solid #111827;
            padding-bottom: 16px;
            margin-bottom: 16px;
        }

        .company-block {
            flex: 1;
        }

        .company-name {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .company-meta,
        .bill-meta,
        .customer-meta {
            font-size: 14px;
            line-height: 1.6;
        }

        .muted {
            color: var(--muted);
        }

        .title-box {
            min-width: 260px;
            text-align: right;
        }

        .title-box h1 {
            margin: 0 0 10px 0;
            font-size: 30px;
            letter-spacing: 1px;
        }

        .bill-section {
            margin-bottom: 18px;
        }

        .section-title {
            font-size: 14px;
            font-weight: 700;
            margin-bottom: 8px;
            text-transform: uppercase;
        }

        .two-col {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        .items-table th,
        .items-table td,
        .summary-table th,
        .summary-table td {
            border: 1px solid var(--border);
            padding: 8px 10px;
            font-size: 13px;
            vertical-align: top;
        }

        .items-table th,
        .summary-table th {
            background: var(--light);
            text-align: left;
        }

        .text-end {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        .summary-wrap {
            display: flex;
            justify-content: flex-end;
            margin-top: 16px;
        }

        .summary-table {
            width: 360px;
        }

        .summary-table .grand-row th,
        .summary-table .grand-row td {
            font-size: 15px;
            font-weight: 700;
        }

        .footer-note {
            margin-top: 24px;
            padding-top: 12px;
            border-top: 1px dashed var(--border);
            font-size: 13px;
            line-height: 1.7;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
        }

        .status-active,
        .status-paid {
            background: #dcfce7;
            color: #166534;
        }

        .status-partial {
            background: #fef3c7;
            color: #92400e;
        }

        .status-unpaid,
        .status-cancelled {
            background: #fee2e2;
            color: #991b1b;
        }

        @media print {
            body {
                background: #fff;
                padding: 0;
            }

            .print-toolbar {
                display: none !important;
            }

            .bill-wrap {
                max-width: 100%;
                margin: 0;
                border: 0;
                padding: 0;
            }

            @page {
                size: A4;
                margin: 12mm;
            }
        }

        @media (max-width: 768px) {
            .bill-header {
                flex-direction: column;
            }

            .title-box {
                text-align: left;
                min-width: auto;
            }

            .two-col {
                grid-template-columns: 1fr;
            }

            .summary-wrap {
                justify-content: stretch;
            }

            .summary-table {
                width: 100%;
            }
        }
    </style>
</head>
<body>

<div class="print-toolbar">
    <a href="sales.php" class="secondary">Back</a>
    <a href="sale-view.php?id=<?php echo (int)$saleId; ?>" class="secondary">View Sale</a>
    <button type="button" onclick="window.print()">Print</button>
</div>

<div class="bill-wrap">
    <div class="bill-header">
        <div class="company-block">
            <div class="company-name"><?php echo h($company['company_name'] ?? 'Business'); ?></div>

            <div class="company-meta">
                <?php if (!empty($company['address_line1'])): ?>
                    <?php echo h($company['address_line1']); ?><br>
                <?php endif; ?>
                <?php if (!empty($company['address_line2'])): ?>
                    <?php echo h($company['address_line2']); ?><br>
                <?php endif; ?>

                <?php
                $cityLine = trim(
                    (string)($company['city'] ?? '')
                    . (!empty($company['state']) ? ', ' . $company['state'] : '')
                    . (!empty($company['pincode']) ? ' - ' . $company['pincode'] : '')
                );
                ?>
                <?php if ($cityLine !== ''): ?>
                    <?php echo h($cityLine); ?><br>
                <?php endif; ?>

                <?php if (!empty($company['country'])): ?>
                    <?php echo h($company['country']); ?><br>
                <?php endif; ?>

                <?php if (!empty($company['mobile'])): ?>
                    <strong>Mobile:</strong> <?php echo h($company['mobile']); ?><br>
                <?php endif; ?>
                <?php if (!empty($company['email'])): ?>
                    <strong>Email:</strong> <?php echo h($company['email']); ?><br>
                <?php endif; ?>
                <?php if (!empty($company['gstin'])): ?>
                    <strong>GSTIN:</strong> <?php echo h($company['gstin']); ?><br>
                <?php endif; ?>
                <?php if (!empty($company['pan_no'])): ?>
                    <strong>PAN:</strong> <?php echo h($company['pan_no']); ?><br>
                <?php endif; ?>
            </div>
        </div>

        <div class="title-box">
            <h1>
                <?php
                $billType = (string)($sale['bill_type'] ?? 'Retail');
                echo h(strtoupper($billType === 'Estimate' ? 'Estimate' : 'Tax Invoice'));
                ?>
            </h1>

            <div class="bill-meta">
                <strong>Bill No:</strong> <?php echo h($sale['bill_no'] ?? ('SALE-' . $sale['id'])); ?><br>
                <strong>Date:</strong>
                <?php echo !empty($sale['bill_date']) ? h(date('d-m-Y', strtotime($sale['bill_date']))) : '-'; ?><br>
                <strong>Time:</strong>
                <?php echo !empty($sale['bill_time']) ? h(date('h:i A', strtotime($sale['bill_time']))) : '-'; ?><br>
                <strong>Bill Type:</strong> <?php echo h($sale['bill_type'] ?? 'Retail'); ?><br>

                <?php if ($salesHasStatus): ?>
                    <strong>Status:</strong>
                    <?php
                    $saleStatus = (string)($sale['status'] ?? 'Active');
                    $saleStatusClass = $saleStatus === 'Cancelled' ? 'status-cancelled' : 'status-active';
                    ?>
                    <span class="status-badge <?php echo $saleStatusClass; ?>"><?php echo h($saleStatus); ?></span><br>
                <?php endif; ?>

                <?php if ($salesHasPaymentStatus): ?>
                    <strong>Payment:</strong>
                    <?php
                    $paymentStatus = (string)($sale['payment_status'] ?? 'Paid');
                    $paymentClass = 'status-paid';
                    if ($paymentStatus === 'Partial') {
                        $paymentClass = 'status-partial';
                    } elseif ($paymentStatus === 'Unpaid') {
                        $paymentClass = 'status-unpaid';
                    }
                    ?>
                    <span class="status-badge <?php echo $paymentClass; ?>"><?php echo h($paymentStatus); ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="bill-section two-col">
        <div>
            <div class="section-title">Bill To</div>
            <div class="customer-meta">
                <strong><?php echo h($sale['customer_name'] ?? 'Walk-in Customer'); ?></strong><br>
                <?php if (!empty($sale['customer_mobile'])): ?>
                    Mobile: <?php echo h($sale['customer_mobile']); ?><br>
                <?php endif; ?>
            </div>
        </div>

        <div>
            <div class="section-title">Payment Info</div>
            <div class="customer-meta">
                <strong>Method:</strong> <?php echo h($sale['payment_method_name'] ?? '-'); ?><br>
                <strong>Reference:</strong> <?php echo h($sale['payment_reference'] ?? '-'); ?>
            </div>
        </div>
    </div>

    <div class="bill-section">
        <div class="section-title">Items</div>

        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 50px;">#</th>
                    <th>Item</th>
                    <th style="width: 90px;">Qty</th>
                    <th style="width: 100px;">Net Wt</th>
                    <th style="width: 100px;">Rate</th>
                    <th style="width: 100px;">Making</th>
                    <th style="width: 100px;">GST</th>
                    <th style="width: 120px;" class="text-end">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($items)): ?>
                    <?php foreach ($items as $index => $item): ?>
                        <tr>
                            <td class="text-center"><?php echo $index + 1; ?></td>
                            <td>
                                <strong><?php echo h($item['item_name'] ?? '-'); ?></strong>
                                <?php if (!empty($item['product_code'])): ?>
                                    <br><span class="muted">Code: <?php echo h($item['product_code']); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($item['barcode'])): ?>
                                    <br><span class="muted">Barcode: <?php echo h($item['barcode']); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($item['category_name'])): ?>
                                    <br><span class="muted">Category: <?php echo h($item['category_name']); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($item['purity'])): ?>
                                    <br><span class="muted">Purity: <?php echo h($item['purity']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center"><?php echo qty3($item['qty'] ?? 0); ?></td>
                            <td class="text-center"><?php echo qty3($item['net_weight'] ?? 0); ?></td>
                            <td class="text-end"><?php echo money($item['rate_per_gram'] ?? 0, $currency); ?></td>
                            <td class="text-end"><?php echo money($item['making_charge'] ?? 0, $currency); ?></td>
                            <td class="text-end">
                                <?php echo money($item['gst_amount'] ?? 0, $currency); ?>
                                <?php if (isset($item['gst_percent'])): ?>
                                    <br><span class="muted"><?php echo h((string)$item['gst_percent']); ?>%</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end"><strong><?php echo money($item['total_amount'] ?? 0, $currency); ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8" class="text-center muted">No items found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="summary-wrap">
        <table class="summary-table">
            <tr>
                <th>Subtotal</th>
                <td class="text-end"><?php echo money($sale['subtotal'] ?? 0, $currency); ?></td>
            </tr>
            <tr>
                <th>Discount</th>
                <td class="text-end"><?php echo money($sale['discount_amount'] ?? 0, $currency); ?></td>
            </tr>
            <tr>
                <th>Taxable Amount</th>
                <td class="text-end"><?php echo money($sale['taxable_amount'] ?? 0, $currency); ?></td>
            </tr>
            <tr>
                <th>CGST</th>
                <td class="text-end"><?php echo money($sale['cgst_amount'] ?? 0, $currency); ?></td>
            </tr>
            <tr>
                <th>SGST</th>
                <td class="text-end"><?php echo money($sale['sgst_amount'] ?? 0, $currency); ?></td>
            </tr>
            <tr>
                <th>IGST</th>
                <td class="text-end"><?php echo money($sale['igst_amount'] ?? 0, $currency); ?></td>
            </tr>
            <tr>
                <th>Round Off</th>
                <td class="text-end"><?php echo money($sale['round_off'] ?? 0, $currency); ?></td>
            </tr>
            <tr class="grand-row">
                <th>Grand Total</th>
                <td class="text-end"><?php echo money($sale['grand_total'] ?? 0, $currency); ?></td>
            </tr>
            <tr>
                <th>Paid Amount</th>
                <td class="text-end"><?php echo money($sale['paid_amount'] ?? 0, $currency); ?></td>
            </tr>
            <tr>
                <th>Balance Amount</th>
                <td class="text-end"><?php echo money($sale['balance_amount'] ?? 0, $currency); ?></td>
            </tr>
        </table>
    </div>

    <?php if (!empty($sale['notes'])): ?>
        <div class="footer-note">
            <strong>Notes:</strong><br>
            <?php echo nl2br(h($sale['notes'])); ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($company['terms_conditions'])): ?>
        <div class="footer-note">
            <strong>Terms & Conditions:</strong><br>
            <?php echo nl2br(h($company['terms_conditions'])); ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($company['bill_footer'])): ?>
        <div class="footer-note">
            <?php echo nl2br(h($company['bill_footer'])); ?>
        </div>
    <?php endif; ?>
</div>

<?php if ($autoPrint === 1): ?>
<script>
window.addEventListener('load', function () {
    window.print();
});
</script>
<?php endif; ?>

</body>
</html>