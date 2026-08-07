<?php
session_start();
date_default_timezone_set('Asia/Kolkata');

$configCandidates = [
    __DIR__ . '/config/config.php',
    __DIR__ . '/config.php',
    __DIR__ . '/includes/config.php',
    __DIR__ . '/super-admin/includes/config.php',
];

$configLoaded = false;
foreach ($configCandidates as $configFile) {
    if (is_file($configFile)) {
        require_once $configFile;
        $configLoaded = true;
        break;
    }
}

if (!$configLoaded) {
    die('Database configuration file not found.');
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection is not available. Check the common config file.');
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn->set_charset('utf8mb4');

function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function tableExists(mysqli $conn, string $tableName): bool
{
    $safe = $conn->real_escape_string($tableName);
    $res = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $res && $res->num_rows > 0;
}

function hasColumn(mysqli $conn, string $table, string $column): bool
{
    $safeTable = $conn->real_escape_string($table);
    $safeColumn = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    return $res && $res->num_rows > 0;
}

function money($value): string
{
    return number_format((float)$value, 2);
}

function qty($value): string
{
    return number_format((float)$value, 3);
}

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? ($_SESSION['default_branch_id'] ?? 0));
$purchaseId = (int)($_GET['id'] ?? 0);

if ($businessId <= 0 || $branchId <= 0) {
    die('Business or branch session not found. Please login again.');
}

if ($purchaseId <= 0) {
    die('Invalid purchase ID.');
}

if (!tableExists($conn, 'purchases') || !tableExists($conn, 'purchase_items')) {
    die('Required purchase tables are missing.');
}

$purchaseSql = "SELECT p.*";
if (tableExists($conn, 'suppliers')) {
    $purchaseSql .= ", s.supplier_name, s.supplier_code, s.mobile AS supplier_mobile, s.email AS supplier_email,
        s.gstin AS supplier_gstin, s.address_line1 AS supplier_address, s.city AS supplier_city,
        s.state AS supplier_state, s.pincode AS supplier_pincode";
}
$purchaseSql .= " FROM purchases p";
if (tableExists($conn, 'suppliers')) {
    $purchaseSql .= " LEFT JOIN suppliers s ON s.id = p.supplier_id";
}
$purchaseSql .= " WHERE p.id = ? AND p.business_id = ? AND p.branch_id = ? LIMIT 1";

$stmt = $conn->prepare($purchaseSql);
if (!$stmt) {
    die('Unable to prepare purchase query: ' . h($conn->error));
}
$stmt->bind_param('iii', $purchaseId, $businessId, $branchId);
$stmt->execute();
$purchase = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$purchase) {
    http_response_code(404);
    die('Purchase not found.');
}

$itemSql = "SELECT pi.*";
if (tableExists($conn, 'products')) {
    $itemSql .= ", p.product_code, p.product_name, p.hsn_code, p.purity, p.stone_weight AS product_stone_weight";
}
$itemSql .= " FROM purchase_items pi";
if (tableExists($conn, 'products')) {
    $itemSql .= " LEFT JOIN products p ON p.id = pi.product_id";
}
$itemSql .= " WHERE pi.purchase_id = ? AND pi.business_id = ? AND pi.branch_id = ? ORDER BY pi.id ASC";

$stmt = $conn->prepare($itemSql);
if (!$stmt) {
    die('Unable to prepare purchase items query: ' . h($conn->error));
}
$stmt->bind_param('iii', $purchaseId, $businessId, $branchId);
$stmt->execute();
$res = $stmt->get_result();
$items = [];
while ($row = $res->fetch_assoc()) {
    $items[] = $row;
}
$stmt->close();

$theme = [
    'primary_color' => '#d89416',
    'primary_dark_color' => '#b86a0b',
    'primary_soft_color' => '#fff6e5',
    'sidebar_gradient_1' => '#171c21',
    'sidebar_gradient_2' => '#20272d',
    'sidebar_gradient_3' => '#101419',
    'page_background' => '#f4f3f0',
    'card_background' => '#ffffff',
    'text_color' => '#171717',
    'muted_text_color' => '#7d8794',
    'border_color' => '#e8e8e8',
    'font_family' => 'Inter',
    'heading_font_family' => 'Playfair Display',
    'border_radius_px' => 12,
];

if (tableExists($conn, 'business_theme_settings')) {
    $themeStmt = $conn->prepare('SELECT * FROM business_theme_settings WHERE business_id=? LIMIT 1');
    if ($themeStmt) {
        $themeStmt->bind_param('i', $businessId);
        $themeStmt->execute();
        $themeRow = $themeStmt->get_result()->fetch_assoc() ?: [];
        $themeStmt->close();
        foreach ($theme as $key => $value) {
            if (isset($themeRow[$key]) && $themeRow[$key] !== '') {
                $theme[$key] = $themeRow[$key];
            }
        }
    }
}

$businessName = (string)($_SESSION['business_name'] ?? 'Jewellery ERP');
$currencySymbol = (string)($_SESSION['currency_symbol'] ?? '₹');
$pageTitle = 'View Purchase';

$statusClass = strtolower(str_replace(' ', '-', (string)($purchase['workflow_status'] ?? 'Posted')));
$paymentClass = strtolower((string)($purchase['payment_status'] ?? 'Unpaid'));

$totalQty = 0.0;
$totalGross = 0.0;
$totalNet = 0.0;
foreach ($items as $item) {
    $totalQty += (float)($item['quantity'] ?? 0);
    $totalGross += (float)($item['gross_weight'] ?? 0);
    $totalNet += (float)($item['net_weight'] ?? 0);
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= h($businessName) ?> - Purchase <?= h($purchase['purchase_no'] ?? '') ?></title>
    <?php include('includes/links.php'); ?>
    <style>
        :root{
            --primary:<?= h($theme['primary_color']) ?>;
            --primary-dark:<?= h($theme['primary_dark_color']) ?>;
            --primary-soft:<?= h($theme['primary_soft_color']) ?>;
            --page-bg:<?= h($theme['page_background']) ?>;
            --card-bg:<?= h($theme['card_background']) ?>;
            --text:<?= h($theme['text_color']) ?>;
            --muted:<?= h($theme['muted_text_color']) ?>;
            --line:<?= h($theme['border_color']) ?>;
            --radius:<?= (int)$theme['border_radius_px'] ?>px;
        }
        body{background:var(--page-bg);color:var(--text);font-family:<?= json_encode($theme['font_family']) ?>,sans-serif}
        .sidebar{background:linear-gradient(180deg,<?= h($theme['sidebar_gradient_1']) ?>,<?= h($theme['sidebar_gradient_2']) ?>,<?= h($theme['sidebar_gradient_3']) ?>)!important}
        .view-card{background:var(--card-bg);border:1px solid var(--line);border-radius:var(--radius);overflow:hidden;margin-bottom:12px}
        .page-head{padding:14px 16px;display:flex;align-items:center;justify-content:space-between;gap:12px}
        .page-title{font:800 21px <?= json_encode($theme['heading_font_family']) ?>,serif}
        .page-subtitle{font-size:10px;color:var(--muted);margin-top:2px}
        .action-wrap{display:flex;gap:7px;flex-wrap:wrap}
        .btn-theme,.btn-soft{min-height:36px;border-radius:9px;padding:8px 12px;font-size:10px;font-weight:800}
        .btn-theme{border:0;background:linear-gradient(135deg,var(--primary),var(--primary-dark));color:#fff}
        .btn-soft{border:1px solid var(--line);background:var(--card-bg);color:var(--text)}
        .hero{padding:16px;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:16px;background:linear-gradient(135deg,var(--primary-soft),var(--card-bg));border-bottom:1px solid var(--line)}
        .purchase-number{font-size:23px;font-weight:900;color:var(--primary-dark)}
        .meta{font-size:10px;color:var(--muted);margin-top:5px}
        .total-box{text-align:right}.total-box .label{font-size:9px;color:var(--muted);text-transform:uppercase}.total-box .value{font-size:27px;font-weight:900;color:var(--primary-dark)}
        .badge-pill{display:inline-flex;padding:5px 9px;border-radius:999px;font-size:9px;font-weight:800;margin-right:5px}
        .status-posted,.status-approved{background:#e9f8ef;color:#168449}.status-draft{background:#eef2f7;color:#526170}.status-cancelled,.status-rejected{background:#fdecec;color:#bd2d2d}.status-pending-approval{background:#fff4dc;color:#9a6200}
        .payment-paid{background:#e9f8ef;color:#168449}.payment-partial{background:#fff4dc;color:#9a6200}.payment-unpaid{background:#fdecec;color:#bd2d2d}
        .info-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:9px;padding:14px}
        .info-box{border:1px solid var(--line);border-radius:10px;padding:10px;background:var(--card-bg)}
        .info-label{font-size:8px;text-transform:uppercase;color:var(--muted);font-weight:800}.info-value{font-size:11px;font-weight:800;margin-top:4px;word-break:break-word}
        .section-head{padding:12px 14px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center}
        .section-title{font:800 15px <?= json_encode($theme['heading_font_family']) ?>,serif}
        .section-note{font-size:9px;color:var(--muted)}
        .items-table{margin:0;font-size:10px}.items-table th{background:color-mix(in srgb,var(--muted) 6%,var(--card-bg));color:var(--muted);font-size:8px;text-transform:uppercase;white-space:nowrap}.items-table th,.items-table td{padding:9px 10px;border-color:var(--line);vertical-align:middle}.product-main{font-size:11px;font-weight:900}.product-sub{font-size:8px;color:var(--muted);margin-top:2px}
        .summary-layout{display:grid;grid-template-columns:minmax(0,1fr) 360px;gap:12px;padding:14px}
        .notes-box{border:1px solid var(--line);border-radius:10px;padding:12px;min-height:100px;font-size:11px;white-space:pre-wrap}
        .summary-panel{border:1px solid var(--line);border-radius:10px;padding:12px}.sum-row{display:flex;justify-content:space-between;gap:12px;padding:8px 0;border-bottom:1px dashed var(--line);font-size:11px}.sum-row:last-child{border-bottom:0}.sum-label{color:var(--muted)}.sum-value{font-weight:900}.sum-grand{font-size:17px;color:var(--primary-dark)}
        .weight-strip{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;padding:0 14px 14px}.weight-box{background:var(--primary-soft);border-radius:10px;padding:10px;text-align:center}.weight-box .num{font-size:16px;font-weight:900;color:var(--primary-dark)}.weight-box .lbl{font-size:8px;color:var(--muted);text-transform:uppercase}
        @media(max-width:991px){.info-grid{grid-template-columns:repeat(2,1fr)}.summary-layout{grid-template-columns:1fr}}
        @media(max-width:767px){.page-head,.hero{grid-template-columns:1fr;display:grid}.total-box{text-align:left}.info-grid,.weight-strip{grid-template-columns:1fr}.items-table{min-width:1050px}}
        @media print{
            .sidebar,.topbar,.navbar,.app-nav,.action-wrap,.no-print,footer{display:none!important}
            .app-main{margin:0!important;width:100%!important}.content-wrap{padding:0!important}.view-card{box-shadow:none!important;break-inside:avoid}.hero{background:#fff!important}.page-head{padding-left:0;padding-right:0}
        }
    </style>
</head>
<body>
<?php include('includes/sidebar.php'); ?>
<main class="app-main">
    <?php include('includes/nav.php'); ?>
    <div class="content-wrap">
        <section class="view-card no-print">
            <div class="page-head">
                <div>
                    <div class="page-title">Purchase Details</div>
                    <div class="page-subtitle">Review supplier, items, weights, totals and payment status.</div>
                </div>
                <div class="action-wrap">
                    <a href="purchases.php" class="btn-soft text-decoration-none"><i class="fa-solid fa-arrow-left me-1"></i>Back</a>
                    <a href="purchase-edit.php?id=<?= $purchaseId ?>" class="btn-soft text-decoration-none"><i class="fa-regular fa-pen-to-square me-1"></i>Edit</a>
                    <button type="button" class="btn-theme" onclick="window.print()"><i class="fa-solid fa-print me-1"></i>Print</button>
                </div>
            </div>
        </section>

        <section class="view-card">
            <div class="hero">
                <div>
                    <div class="purchase-number"><?= h($purchase['purchase_no'] ?? '') ?></div>
                    <div class="meta">
                        Purchase date: <?= !empty($purchase['purchase_date']) ? h(date('d-m-Y', strtotime($purchase['purchase_date']))) : '-' ?>
                        <?php if (!empty($purchase['supplier_invoice_no'])): ?>
                            &nbsp;•&nbsp; Supplier invoice: <?= h($purchase['supplier_invoice_no']) ?>
                        <?php endif; ?>
                    </div>
                    <div class="mt-2">
                        <span class="badge-pill status-<?= h($statusClass) ?>"><?= h($purchase['workflow_status'] ?? 'Posted') ?></span>
                        <span class="badge-pill payment-<?= h($paymentClass) ?>"><?= h($purchase['payment_status'] ?? 'Unpaid') ?></span>
                    </div>
                </div>
                <div class="total-box">
                    <div class="label">Grand Total</div>
                    <div class="value"><?= h($currencySymbol) ?><?= money($purchase['grand_total'] ?? 0) ?></div>
                    <div class="meta">Balance: <?= h($currencySymbol) ?><?= money($purchase['balance_amount'] ?? 0) ?></div>
                </div>
            </div>

            <div class="info-grid">
                <div class="info-box"><div class="info-label">Supplier</div><div class="info-value"><?= h($purchase['supplier_name'] ?? ('Supplier #' . ($purchase['supplier_id'] ?? ''))) ?></div><div class="product-sub"><?= h($purchase['supplier_code'] ?? '') ?></div></div>
                <div class="info-box"><div class="info-label">Mobile</div><div class="info-value"><?= h($purchase['supplier_mobile'] ?? '-') ?></div></div>
                <div class="info-box"><div class="info-label">Email</div><div class="info-value"><?= h($purchase['supplier_email'] ?? '-') ?></div></div>
                <div class="info-box"><div class="info-label">GSTIN</div><div class="info-value"><?= h($purchase['supplier_gstin'] ?? '-') ?></div></div>
                <div class="info-box" style="grid-column:span 2"><div class="info-label">Supplier Address</div><div class="info-value"><?= h(implode(', ', array_filter([$purchase['supplier_address'] ?? '', $purchase['supplier_city'] ?? '', $purchase['supplier_state'] ?? '', $purchase['supplier_pincode'] ?? ''])) ?: '-') ?></div></div>
                <div class="info-box"><div class="info-label">Created By</div><div class="info-value"><?= h($purchase['created_by'] ?? '-') ?></div></div>
                <div class="info-box"><div class="info-label">Created At</div><div class="info-value"><?= !empty($purchase['created_at']) ? h(date('d-m-Y h:i A', strtotime($purchase['created_at']))) : '-' ?></div></div>
            </div>
        </section>

        <section class="view-card">
            <div class="section-head">
                <div><div class="section-title">Purchase Items</div><div class="section-note"><?= count($items) ?> item row(s)</div></div>
            </div>
            <div class="table-responsive">
                <table class="table items-table">
                    <thead><tr><th>#</th><th>Product</th><th>HSN</th><th>Purity</th><th class="text-end">Qty</th><th class="text-end">Gram/Qty</th><th class="text-end">Gross Wt</th><th class="text-end">Net Wt</th><th class="text-end">Rate/Gm</th><th class="text-end">GST %</th><th class="text-end">GST Amt</th><th class="text-end">Line Total</th></tr></thead>
                    <tbody>
                    <?php if (!$items): ?>
                        <tr><td colspan="12" class="text-center text-muted py-4">No purchase items found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($items as $index => $item):
                            $itemQty = (float)($item['quantity'] ?? 0);
                            $gross = (float)($item['gross_weight'] ?? 0);
                            $gramPerQty = $itemQty > 0 ? $gross / $itemQty : 0;
                        ?>
                        <tr>
                            <td><?= $index + 1 ?></td>
                            <td><div class="product-main"><?= h($item['item_name'] ?? $item['product_name'] ?? '-') ?></div><div class="product-sub"><?= h($item['product_code'] ?? '') ?></div></td>
                            <td><?= h($item['hsn_code'] ?? '-') ?></td>
                            <td><?= h($item['purity'] ?? '-') ?></td>
                            <td class="text-end"><?= qty($itemQty) ?></td>
                            <td class="text-end"><?= qty($gramPerQty) ?> g</td>
                            <td class="text-end"><?= qty($gross) ?> g</td>
                            <td class="text-end"><?= qty($item['net_weight'] ?? 0) ?> g</td>
                            <td class="text-end"><?= h($currencySymbol) ?><?= money($item['rate'] ?? 0) ?></td>
                            <td class="text-end"><?= qty($item['tax_percent'] ?? 0) ?></td>
                            <td class="text-end"><?= h($currencySymbol) ?><?= money($item['tax_amount'] ?? 0) ?></td>
                            <td class="text-end"><strong><?= h($currencySymbol) ?><?= money($item['line_total'] ?? 0) ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="weight-strip">
                <div class="weight-box"><div class="num"><?= qty($totalQty) ?></div><div class="lbl">Total Quantity</div></div>
                <div class="weight-box"><div class="num"><?= qty($totalGross) ?> g</div><div class="lbl">Total Gross Weight</div></div>
                <div class="weight-box"><div class="num"><?= qty($totalNet) ?> g</div><div class="lbl">Total Net Weight</div></div>
            </div>
        </section>

        <section class="view-card">
            <div class="section-head"><div><div class="section-title">Summary & Notes</div><div class="section-note">Purchase amount and outstanding balance.</div></div></div>
            <div class="summary-layout">
                <div>
                    <div class="info-label mb-2">Notes</div>
                    <div class="notes-box"><?= h($purchase['notes'] ?? '') !== '' ? nl2br(h($purchase['notes'])) : '<span class="text-muted">No notes added.</span>' ?></div>
                </div>
                <div class="summary-panel">
                    <div class="sum-row"><span class="sum-label">Subtotal</span><span class="sum-value"><?= h($currencySymbol) ?><?= money($purchase['subtotal'] ?? 0) ?></span></div>
                    <div class="sum-row"><span class="sum-label">Discount</span><span class="sum-value"><?= h($currencySymbol) ?><?= money($purchase['discount_amount'] ?? 0) ?></span></div>
                    <div class="sum-row"><span class="sum-label">Taxable Amount</span><span class="sum-value"><?= h($currencySymbol) ?><?= money($purchase['taxable_amount'] ?? 0) ?></span></div>
                    <div class="sum-row"><span class="sum-label">CGST</span><span class="sum-value"><?= h($currencySymbol) ?><?= money($purchase['cgst_amount'] ?? 0) ?></span></div>
                    <div class="sum-row"><span class="sum-label">SGST</span><span class="sum-value"><?= h($currencySymbol) ?><?= money($purchase['sgst_amount'] ?? 0) ?></span></div>
                    <div class="sum-row"><span class="sum-label">IGST</span><span class="sum-value"><?= h($currencySymbol) ?><?= money($purchase['igst_amount'] ?? 0) ?></span></div>
                    <div class="sum-row"><span class="sum-label">Paid Amount</span><span class="sum-value"><?= h($currencySymbol) ?><?= money($purchase['paid_amount'] ?? 0) ?></span></div>
                    <div class="sum-row"><span class="sum-label">Balance Amount</span><span class="sum-value"><?= h($currencySymbol) ?><?= money($purchase['balance_amount'] ?? 0) ?></span></div>
                    <div class="sum-row"><span class="sum-label"><strong>Grand Total</strong></span><span class="sum-value sum-grand"><?= h($currencySymbol) ?><?= money($purchase['grand_total'] ?? 0) ?></span></div>
                </div>
            </div>
        </section>

        <?php include('includes/footer.php'); ?>
    </div>
</main>
<?php include('includes/script.php'); ?>
<script src="assets/js/script.js"></script>
</body>
</html>