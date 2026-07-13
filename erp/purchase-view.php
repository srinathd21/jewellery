<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));

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

if (!$configLoaded || !isset($conn) || !($conn instanceof mysqli)) {
    die('Database configuration is not available.');
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
        $result = $conn->query("SHOW TABLES LIKE '{$safe}'");
        return $result && $result->num_rows > 0;
    }
}

if (!function_exists('money')) {
    function money($amount): string
    {
        return number_format((float)$amount, 2);
    }
}

if (!function_exists('qty')) {
    function qty($amount): string
    {
        return number_format((float)$amount, 3);
    }
}

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? 0);

if ($businessId <= 0) {
    die('Business session not found. Please login again.');
}

/*
|--------------------------------------------------------------------------
| Permission check
|--------------------------------------------------------------------------
*/
$canViewPurchase = false;

if (($_SESSION['user_type'] ?? '') === 'Platform Admin') {
    $canViewPurchase = true;
}

$sessionPermissions = $_SESSION['permissions'] ?? [];
foreach (['perm.purchases', 'perm.purchase'] as $permissionCode) {
    if (
        isset($sessionPermissions[$permissionCode]) &&
        (
            (int)($sessionPermissions[$permissionCode]['can_open'] ?? 0) === 1 ||
            (int)($sessionPermissions[$permissionCode]['can_view'] ?? 0) === 1
        )
    ) {
        $canViewPurchase = true;
        break;
    }
}

$roleName = strtolower(trim((string)($_SESSION['role_name'] ?? '')));
$roleCode = strtolower(trim((string)($_SESSION['role_code'] ?? '')));

if (
    in_array($roleName, ['admin', 'business admin', 'manager', 'stock'], true) ||
    in_array($roleCode, ['admin', 'business_admin', 'manager', 'stock'], true)
) {
    $canViewPurchase = true;
}

if (!$canViewPurchase) {
    http_response_code(403);
    die('Access denied. You do not have permission to view purchases.');
}

if (
    !tableExists($conn, 'purchases') ||
    !tableExists($conn, 'purchase_items') ||
    !tableExists($conn, 'suppliers')
) {
    die('Required purchase tables are not available.');
}

$purchaseId = (int)($_GET['id'] ?? $_GET['purchase_id'] ?? 0);
if ($purchaseId <= 0) {
    header('Location: purchases.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Load purchase
|--------------------------------------------------------------------------
*/
$sql = "
    SELECT
        p.id,
        p.business_id,
        p.branch_id,
        p.purchase_no,
        p.supplier_invoice_no,
        p.purchase_date,
        p.supplier_id,
        p.subtotal,
        p.discount_amount,
        p.taxable_amount,
        p.cgst_amount,
        p.sgst_amount,
        p.igst_amount,
        p.grand_total,
        p.paid_amount,
        p.balance_amount,
        p.payment_status,
        p.workflow_status,
        p.notes,
        p.created_by,
        p.approved_by,
        p.created_at,
        s.supplier_code,
        s.supplier_name,
        s.contact_person,
        s.mobile AS supplier_mobile,
        s.email AS supplier_email,
        s.gstin AS supplier_gstin,
        s.address AS supplier_address
    FROM purchases p
    INNER JOIN suppliers s
        ON s.id = p.supplier_id
       AND s.business_id = p.business_id
    WHERE p.id = ?
      AND p.business_id = ?
";

$params = [$purchaseId, $businessId];
$types = 'ii';

if ($branchId > 0) {
    $sql .= " AND p.branch_id = ?";
    $params[] = $branchId;
    $types .= 'i';
}

$sql .= " LIMIT 1";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die('Unable to prepare purchase query: ' . h($conn->error));
}

$bind = [$types];
foreach ($params as $index => $value) {
    $bind[] = &$params[$index];
}
call_user_func_array([$stmt, 'bind_param'], $bind);

if (!$stmt->execute()) {
    die('Unable to load purchase: ' . h($stmt->error));
}

$purchase = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$purchase) {
    http_response_code(404);
    die('Purchase not found for the selected business and branch.');
}

/*
|--------------------------------------------------------------------------
| Load purchase items
|--------------------------------------------------------------------------
*/
$items = [];

$stmt = $conn->prepare("
    SELECT
        pi.id,
        pi.product_id,
        pi.item_name,
        pi.quantity,
        pi.gross_weight,
        pi.net_weight,
        pi.rate,
        pi.tax_percent,
        pi.tax_amount,
        pi.line_total,
        p.product_code,
        p.barcode,
        p.purity,
        p.hsn_code
    FROM purchase_items pi
    LEFT JOIN products p
        ON p.id = pi.product_id
       AND p.business_id = pi.business_id
    WHERE pi.purchase_id = ?
      AND pi.business_id = ?
      AND pi.branch_id = ?
    ORDER BY pi.id ASC
");

if (!$stmt) {
    die('Unable to prepare purchase-items query: ' . h($conn->error));
}

$itemBranchId = (int)$purchase['branch_id'];
$stmt->bind_param('iii', $purchaseId, $businessId, $itemBranchId);

if (!$stmt->execute()) {
    die('Unable to load purchase items: ' . h($stmt->error));
}

$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $items[] = $row;
}
$stmt->close();

/*
|--------------------------------------------------------------------------
| Theme
|--------------------------------------------------------------------------
*/
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
    'sidebar_width_px' => 230,
];

if (tableExists($conn, 'business_theme_settings')) {
    $themeStmt = $conn->prepare(
        'SELECT * FROM business_theme_settings WHERE business_id = ? LIMIT 1'
    );

    if ($themeStmt) {
        $themeStmt->bind_param('i', $businessId);
        $themeStmt->execute();
        $themeRow = $themeStmt->get_result()->fetch_assoc() ?: [];
        $themeStmt->close();

        foreach ($theme as $key => $defaultValue) {
            if (isset($themeRow[$key]) && $themeRow[$key] !== '') {
                $theme[$key] = $themeRow[$key];
            }
        }
    }
}

$pageTitle = 'View Purchase';
$businessName = (string)($_SESSION['business_name'] ?? 'Jewellery ERP');
$currencySymbol = (string)($_SESSION['currency_symbol'] ?? '₹');

$paymentStatus = (string)($purchase['payment_status'] ?? 'Unpaid');
$paymentBadge = 'status-unpaid';

if ($paymentStatus === 'Paid') {
    $paymentBadge = 'status-paid';
} elseif ($paymentStatus === 'Partial') {
    $paymentBadge = 'status-partial';
}

$workflowStatus = (string)($purchase['workflow_status'] ?? 'Posted');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo h($businessName); ?> - View Purchase</title>

    <?php include('includes/links.php'); ?>

    <style>
        :root {
            --primary: <?php echo h($theme['primary_color']); ?>;
            --primary-dark: <?php echo h($theme['primary_dark_color']); ?>;
            --primary-soft: <?php echo h($theme['primary_soft_color']); ?>;
            --sidebar-gradient-1: <?php echo h($theme['sidebar_gradient_1']); ?>;
            --sidebar-gradient-2: <?php echo h($theme['sidebar_gradient_2']); ?>;
            --sidebar-gradient-3: <?php echo h($theme['sidebar_gradient_3']); ?>;
            --page-bg: <?php echo h($theme['page_background']); ?>;
            --card-bg: <?php echo h($theme['card_background']); ?>;
            --text-color: <?php echo h($theme['text_color']); ?>;
            --muted-color: <?php echo h($theme['muted_text_color']); ?>;
            --border-color: <?php echo h($theme['border_color']); ?>;
            --sidebar-width: <?php echo (int)$theme['sidebar_width_px']; ?>px;
            --radius: <?php echo (int)$theme['border_radius_px']; ?>px;
        }

        body {
            background: var(--page-bg);
            color: var(--text-color);
            font-family: <?php echo json_encode((string)$theme['font_family']); ?>, sans-serif;
        }

        .sidebar {
            background: linear-gradient(
                180deg,
                var(--sidebar-gradient-1),
                var(--sidebar-gradient-2),
                var(--sidebar-gradient-3)
            ) !important;
        }

        .page-toolbar,
        .view-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
        }

        .page-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 13px 15px;
            margin-bottom: 12px;
        }

        .page-title {
            margin: 0;
            font-family: <?php echo json_encode((string)$theme['heading_font_family']); ?>, serif;
            font-size: 19px;
            font-weight: 800;
        }

        .page-subtitle {
            margin-top: 2px;
            color: var(--muted-color);
            font-size: 10px;
        }

        .btn {
            border-radius: 9px;
            font-size: 11px;
            font-weight: 700;
        }

        .btn-primary {
            border-color: transparent;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
        }

        .btn-soft {
            border: 1px solid color-mix(in srgb, var(--primary) 25%, var(--border-color));
            background: var(--primary-soft);
            color: var(--primary-dark);
        }

        .view-card {
            overflow: hidden;
            margin-bottom: 12px;
        }

        .view-card-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 13px 15px;
            border-bottom: 1px solid var(--border-color);
        }

        .view-card-title {
            margin: 0;
            font-family: <?php echo json_encode((string)$theme['heading_font_family']); ?>, serif;
            font-size: 15px;
            font-weight: 800;
        }

        .view-card-body {
            padding: 15px;
        }

        .invoice-number {
            color: var(--primary-dark);
            font-size: 14px;
            font-weight: 800;
        }

        .meta-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 10px;
        }

        .meta-box {
            min-height: 72px;
            padding: 11px;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            background: color-mix(in srgb, var(--muted-color) 3%, var(--card-bg));
        }

        .meta-label {
            margin-bottom: 5px;
            color: var(--muted-color);
            font-size: 9px;
            font-weight: 700;
            letter-spacing: .04em;
            text-transform: uppercase;
        }

        .meta-value {
            overflow-wrap: anywhere;
            font-size: 11px;
            font-weight: 700;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 9px;
            font-weight: 800;
        }

        .status-paid {
            background: #eaf8f0;
            color: #168449;
        }

        .status-partial {
            background: #fff4dc;
            color: #9a6200;
        }

        .status-unpaid {
            background: #fdecec;
            color: #bd2d2d;
        }

        .workflow-badge {
            background: var(--primary-soft);
            color: var(--primary-dark);
        }

        .items-wrap {
            overflow-x: auto;
        }

        .items-table {
            min-width: 1150px;
            margin: 0;
            color: var(--text-color);
            font-size: 10px;
        }

        .items-table th {
            padding: 9px 8px;
            border-color: var(--border-color);
            background: color-mix(in srgb, var(--muted-color) 6%, var(--card-bg));
            color: var(--muted-color);
            font-size: 9px;
            letter-spacing: .04em;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .items-table td {
            padding: 9px 8px;
            border-color: var(--border-color);
            background: var(--card-bg) !important;
            vertical-align: middle;
        }

        .summary-layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 330px;
            gap: 15px;
            align-items: start;
        }

        .notes-box {
            min-height: 120px;
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            color: var(--text-color);
            font-size: 11px;
            white-space: pre-wrap;
        }

        .summary-box {
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 12px;
        }

        .summary-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 8px 0;
            border-bottom: 1px dashed var(--border-color);
            font-size: 11px;
        }

        .summary-row:last-child {
            border-bottom: 0;
        }

        .summary-label {
            color: var(--muted-color);
        }

        .summary-value {
            font-weight: 800;
            text-align: right;
        }

        .grand-total {
            margin-top: 5px;
            padding-top: 12px;
            border-top: 1px solid var(--border-color);
        }

        .grand-total .summary-label {
            color: var(--text-color);
            font-weight: 800;
        }

        .grand-total .summary-value {
            color: var(--primary-dark);
            font-size: 19px;
        }

        body.dark-mode,
        body[data-theme="dark"],
        html.dark-mode body,
        html[data-theme="dark"] body {
            --page-bg: #0f151b;
            --card-bg: #182129;
            --text-color: #f3f6f8;
            --muted-color: #9aa7b3;
            --border-color: #2c3944;
        }

        @media (max-width: 991.98px) {
            .meta-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .summary-layout {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 575.98px) {
            .content-wrap {
                padding-left: 10px;
                padding-right: 10px;
            }

            .page-toolbar {
                align-items: flex-start;
                flex-direction: column;
            }

            .page-toolbar .toolbar-actions {
                display: grid !important;
                grid-template-columns: repeat(3, 1fr);
                width: 100%;
            }

            .meta-grid {
                grid-template-columns: 1fr;
            }
        }

        @media print {
            .sidebar,
            .app-nav,
            .toolbar-actions,
            .footer {
                display: none !important;
            }

            .app-main {
                margin-left: 0 !important;
            }

            .content-wrap {
                padding: 0 !important;
            }

            body {
                background: #fff !important;
                color: #000 !important;
            }

            .page-toolbar,
            .view-card {
                border: 1px solid #ddd !important;
                break-inside: avoid;
            }

            .items-wrap {
                overflow: visible !important;
            }

            .items-table {
                min-width: 0 !important;
                font-size: 8px;
            }
        }
    </style>
</head>

<body>
<?php include('includes/sidebar.php'); ?>

<main class="app-main">
    <?php include('includes/nav.php'); ?>

    <div class="content-wrap">
        <div class="page-toolbar">
            <div>
                <h1 class="page-title">View Purchase</h1>
                <div class="page-subtitle">
                    Purchase invoice, supplier details, item values and payment summary.
                </div>
            </div>

            <div class="toolbar-actions d-flex gap-2">
                <button type="button" class="btn btn-soft" onclick="window.print()">
                    <i class="fa-solid fa-print me-1"></i>Print
                </button>

                <a
                    href="purchase-edit.php?id=<?php echo (int)$purchaseId; ?>"
                    class="btn btn-primary"
                >
                    <i class="fa-solid fa-pen me-1"></i>Edit
                </a>

                <a href="purchases.php" class="btn btn-light">
                    <i class="fa-solid fa-arrow-left me-1"></i>Back
                </a>
            </div>
        </div>

        <section class="view-card">
            <div class="view-card-head">
                <div>
                    <h2 class="view-card-title">Purchase Information</h2>
                    <div class="invoice-number">
                        <?php echo h($purchase['purchase_no']); ?>
                    </div>
                </div>

                <div class="d-flex gap-2 flex-wrap justify-content-end">
                    <span class="status-badge <?php echo h($paymentBadge); ?>">
                        <?php echo h($paymentStatus); ?>
                    </span>

                    <span class="status-badge workflow-badge">
                        <?php echo h($workflowStatus); ?>
                    </span>
                </div>
            </div>

            <div class="view-card-body">
                <div class="meta-grid">
                    <div class="meta-box">
                        <div class="meta-label">Purchase Date</div>
                        <div class="meta-value">
                            <?php echo date('d-m-Y', strtotime((string)$purchase['purchase_date'])); ?>
                        </div>
                    </div>

                    <div class="meta-box">
                        <div class="meta-label">Supplier Invoice No</div>
                        <div class="meta-value">
                            <?php echo h($purchase['supplier_invoice_no'] ?: '-'); ?>
                        </div>
                    </div>

                    <div class="meta-box">
                        <div class="meta-label">Created On</div>
                        <div class="meta-value">
                            <?php
                            echo !empty($purchase['created_at'])
                                ? date('d-m-Y h:i A', strtotime((string)$purchase['created_at']))
                                : '-';
                            ?>
                        </div>
                    </div>

                    <div class="meta-box">
                        <div class="meta-label">Branch ID</div>
                        <div class="meta-value"><?php echo (int)$purchase['branch_id']; ?></div>
                    </div>
                </div>
            </div>
        </section>

        <section class="view-card">
            <div class="view-card-head">
                <h2 class="view-card-title">Supplier Details</h2>
            </div>

            <div class="view-card-body">
                <div class="meta-grid">
                    <div class="meta-box">
                        <div class="meta-label">Supplier</div>
                        <div class="meta-value">
                            <?php echo h($purchase['supplier_name']); ?>
                            <?php if (!empty($purchase['supplier_code'])): ?>
                                <br><small><?php echo h($purchase['supplier_code']); ?></small>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="meta-box">
                        <div class="meta-label">Contact Person</div>
                        <div class="meta-value">
                            <?php echo h($purchase['contact_person'] ?: '-'); ?>
                        </div>
                    </div>

                    <div class="meta-box">
                        <div class="meta-label">Mobile / Email</div>
                        <div class="meta-value">
                            <?php echo h($purchase['supplier_mobile'] ?: '-'); ?>
                            <?php if (!empty($purchase['supplier_email'])): ?>
                                <br><small><?php echo h($purchase['supplier_email']); ?></small>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="meta-box">
                        <div class="meta-label">GSTIN</div>
                        <div class="meta-value">
                            <?php echo h($purchase['supplier_gstin'] ?: '-'); ?>
                        </div>
                    </div>
                </div>

                <?php if (!empty($purchase['supplier_address'])): ?>
                    <div class="meta-box mt-2">
                        <div class="meta-label">Address</div>
                        <div class="meta-value">
                            <?php echo nl2br(h($purchase['supplier_address'])); ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="view-card">
            <div class="view-card-head">
                <h2 class="view-card-title">Purchase Items</h2>
                <span class="status-badge workflow-badge">
                    <?php echo count($items); ?> item<?php echo count($items) === 1 ? '' : 's'; ?>
                </span>
            </div>

            <div class="items-wrap">
                <table class="table items-table align-middle">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Product</th>
                            <th>Item Name</th>
                            <th>Purity</th>
                            <th>HSN</th>
                            <th>Quantity</th>
                            <th>Gross Wt.</th>
                            <th>Net Wt.</th>
                            <th>Rate</th>
                            <th>Tax %</th>
                            <th>Tax</th>
                            <th>Line Total</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php if ($items): ?>
                        <?php foreach ($items as $index => $item): ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>

                                <td>
                                    <?php echo h($item['product_code'] ?: '-'); ?>
                                    <?php if (!empty($item['barcode'])): ?>
                                        <br><small><?php echo h($item['barcode']); ?></small>
                                    <?php endif; ?>
                                </td>

                                <td><strong><?php echo h($item['item_name']); ?></strong></td>
                                <td><?php echo h($item['purity'] ?: '-'); ?></td>
                                <td><?php echo h($item['hsn_code'] ?: '-'); ?></td>
                                <td><?php echo qty($item['quantity']); ?></td>
                                <td><?php echo qty($item['gross_weight']); ?></td>
                                <td><?php echo qty($item['net_weight']); ?></td>
                                <td><?php echo h($currencySymbol); ?> <?php echo money($item['rate']); ?></td>
                                <td><?php echo qty($item['tax_percent']); ?>%</td>
                                <td><?php echo h($currencySymbol); ?> <?php echo money($item['tax_amount']); ?></td>
                                <td><strong><?php echo h($currencySymbol); ?> <?php echo money($item['line_total']); ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="12" class="text-center py-4">
                                No purchase items found.
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="view-card">
            <div class="view-card-head">
                <h2 class="view-card-title">Payment Summary</h2>
            </div>

            <div class="view-card-body">
                <div class="summary-layout">
                    <div>
                        <div class="meta-label">Notes</div>
                        <div class="notes-box">
                            <?php echo h($purchase['notes'] ?: 'No notes entered.'); ?>
                        </div>
                    </div>

                    <div class="summary-box">
                        <div class="summary-row">
                            <span class="summary-label">Subtotal</span>
                            <span class="summary-value">
                                <?php echo h($currencySymbol); ?> <?php echo money($purchase['subtotal']); ?>
                            </span>
                        </div>

                        <div class="summary-row">
                            <span class="summary-label">Discount</span>
                            <span class="summary-value">
                                <?php echo h($currencySymbol); ?> <?php echo money($purchase['discount_amount']); ?>
                            </span>
                        </div>

                        <div class="summary-row">
                            <span class="summary-label">Taxable Amount</span>
                            <span class="summary-value">
                                <?php echo h($currencySymbol); ?> <?php echo money($purchase['taxable_amount']); ?>
                            </span>
                        </div>

                        <div class="summary-row">
                            <span class="summary-label">CGST</span>
                            <span class="summary-value">
                                <?php echo h($currencySymbol); ?> <?php echo money($purchase['cgst_amount']); ?>
                            </span>
                        </div>

                        <div class="summary-row">
                            <span class="summary-label">SGST</span>
                            <span class="summary-value">
                                <?php echo h($currencySymbol); ?> <?php echo money($purchase['sgst_amount']); ?>
                            </span>
                        </div>

                        <div class="summary-row">
                            <span class="summary-label">IGST</span>
                            <span class="summary-value">
                                <?php echo h($currencySymbol); ?> <?php echo money($purchase['igst_amount']); ?>
                            </span>
                        </div>

                        <div class="summary-row grand-total">
                            <span class="summary-label">Grand Total</span>
                            <span class="summary-value">
                                <?php echo h($currencySymbol); ?> <?php echo money($purchase['grand_total']); ?>
                            </span>
                        </div>

                        <div class="summary-row">
                            <span class="summary-label">Paid Amount</span>
                            <span class="summary-value">
                                <?php echo h($currencySymbol); ?> <?php echo money($purchase['paid_amount']); ?>
                            </span>
                        </div>

                        <div class="summary-row">
                            <span class="summary-label">Balance Amount</span>
                            <span class="summary-value">
                                <?php echo h($currencySymbol); ?> <?php echo money($purchase['balance_amount']); ?>
                            </span>
                        </div>
                    </div>
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
