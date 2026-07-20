<?php
if (session_status() === PHP_SESSION_NONE)
    session_start();
date_default_timezone_set((string) ($_SESSION['timezone'] ?? 'Asia/Kolkata'));
foreach ([__DIR__ . '/config/config.php', __DIR__ . '/config.php', __DIR__ . '/includes/config.php', __DIR__ . '/super-admin/includes/config.php'] as $f) {
    if (is_file($f)) {
        require_once $f;
        break;
    }
}
if (!isset($conn) || !($conn instanceof mysqli))
    die('Database configuration is not available.');
$conn->set_charset('utf8mb4');
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
function e($v): string
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
}
function billingPermission(mysqli $conn, string $action): bool
{
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin')
        return true;
    $map = ['open' => 'can_open', 'create' => 'can_create', 'value' => 'can_view_value'];
    $field = $map[$action] ?? '';
    if ($field === '')
        return false;
    foreach (['perm.billing.create', 'perm.billing'] as $code) {
        if (isset($_SESSION['permissions'][$code][$field]))
            return (int) $_SESSION['permissions'][$code][$field] === 1;
    }
    $businessId = (int) ($_SESSION['business_id'] ?? 0);
    $roleId = (int) ($_SESSION['role_id'] ?? 0);
    if ($businessId <= 0 || $roleId <= 0)
        return false;
    $sql = "SELECT rp.`{$field}` FROM role_permissions rp INNER JOIN permissions p ON p.id=rp.permission_id WHERE rp.business_id=? AND rp.role_id=? AND p.is_active=1 AND p.permission_code IN ('perm.billing.create','perm.billing') ORDER BY FIELD(p.permission_code,'perm.billing.create','perm.billing') LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt)
        return false;
    $stmt->bind_param('ii', $businessId, $roleId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row[$field] ?? 0) === 1;
}
if (!billingPermission($conn, 'open') && !billingPermission($conn, 'create')) {
    http_response_code(403);
    die('You do not have permission to create bills.');
}
$businessId = (int) ($_SESSION['business_id'] ?? 0);
$branchId = (int) ($_SESSION['branch_id'] ?? ($_SESSION['default_branch_id'] ?? 0));
if ($businessId <= 0 || $branchId <= 0)
    die('A valid business and branch must be selected.');
if (empty($_SESSION['billing_csrf']))
    $_SESSION['billing_csrf'] = bin2hex(random_bytes(32));
$csrfToken = (string) $_SESSION['billing_csrf'];
$theme = ['primary_color' => '#d89416', 'primary_dark_color' => '#b86a0b', 'primary_soft_color' => '#fff6e5', 'page_background' => '#f4f3f0', 'card_background' => '#fff', 'text_color' => '#171717', 'muted_text_color' => '#7d8794', 'border_color' => '#e8e8e8', 'font_family' => 'Inter', 'heading_font_family' => 'Playfair Display', 'border_radius_px' => 12];
$stmt = $conn->prepare('SELECT * FROM business_theme_settings WHERE business_id=? LIMIT 1');
if ($stmt) {
    $stmt->bind_param('i', $businessId);
    $stmt->execute();
    $x = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    foreach ($theme as $k => $v)
        if (isset($x[$k]) && $x[$k] !== '')
            $theme[$k] = $x[$k];
}
$customers = [];
$stmt = $conn->prepare("SELECT id,customer_code,customer_name,mobile,gstin FROM customers WHERE business_id=? AND is_active=1 ORDER BY customer_name");
if ($stmt) {
    $stmt->bind_param('i', $businessId);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($x = $r->fetch_assoc())
        $customers[] = $x;
    $stmt->close();
}
$products = [];
$stmt = $conn->prepare("SELECT p.id,p.product_code,p.barcode,p.product_name,p.hsn_code,p.metal_id,p.purity,p.gross_weight,p.stone_weight,p.net_weight,p.wastage_percent,p.making_charge_type,p.making_charge,p.purchase_rate,p.sale_rate,p.tax_percent,p.track_stock,COALESCE(ps.quantity,0) stock_qty,COALESCE(ps.gross_weight,0) stock_gross_weight,COALESCE(ps.net_weight,0) stock_net_weight,m.metal_name,COALESCE(mr.rate_per_gram,p.sale_rate,0) AS live_metal_rate,mr.effective_from AS live_rate_effective_from,mr.branch_id AS live_rate_branch_id FROM products p LEFT JOIN product_stock ps ON ps.product_id=p.id AND ps.business_id=p.business_id AND ps.branch_id=? LEFT JOIN metals m ON m.id=p.metal_id LEFT JOIN metal_rates mr ON mr.id=(SELECT mr2.id FROM metal_rates mr2 WHERE mr2.business_id=p.business_id AND mr2.metal_id=p.metal_id AND mr2.is_current=1 AND (mr2.branch_id=? OR mr2.branch_id IS NULL) ORDER BY (mr2.branch_id=?) DESC,mr2.effective_from DESC,mr2.id DESC LIMIT 1) WHERE p.business_id=? AND p.is_active=1 ORDER BY p.product_name");
if ($stmt) {
    $stmt->bind_param('iiii', $branchId, $branchId, $branchId, $businessId);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($x = $r->fetch_assoc())
        $products[] = $x;
    $stmt->close();
}
$paymentMethods = [];
$stmt = $conn->prepare('SELECT id,method_name FROM payment_methods WHERE business_id=? AND is_active=1 ORDER BY method_name');
if ($stmt) {
    $stmt->bind_param('i', $businessId);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($x = $r->fetch_assoc())
        $paymentMethods[] = $x;
    $stmt->close();
}
function billingPeriodKey(string $reset, string $date): string
{
    $ts = strtotime($date);
    switch ($reset) {
        case 'Monthly': return date('Ym', $ts);
        case 'Daily': return date('Ymd', $ts);
        case 'Calendar Year': return date('Y', $ts);
        case 'Financial Year':
            $year = (int)date('Y', $ts);
            $month = (int)date('n', $ts);
            $start = $month >= 4 ? $year : $year - 1;
            return $start . '-' . ($start + 1);
        default: return 'ALL';
    }
}

function renderBillingNumber(array $setting, int $sequence, string $date): string
{
    $ts = strtotime($date);
    $year = (int) date('Y', $ts);
    $month = (int) date('n', $ts);
    $fyStart = $month >= 4 ? $year : $year - 1;
    $fyShort = substr((string) $fyStart, -2) . '-' . substr((string) ($fyStart + 1), -2);

    $center = (string) ($setting['center_format'] ?? '{FY_SHORT}');
    $center = strtr($center, [
        '{FY_SHORT}' => $fyShort,
        '{FY_2DIGIT}' => str_replace('-', '', $fyShort),
        '{YYYY}' => date('Y', $ts),
        '{YY}' => date('y', $ts),
        '{MM}' => date('m', $ts),
        '{DD}' => date('d', $ts)
    ]);

    return strtr(
        (string) ($setting['format_template'] ?? '{PREFIX}{DIVIDER}{CENTER}{DIVIDER}{SEQ}{SUFFIX}'),
        [
            '{PREFIX}' => (string) ($setting['prefix'] ?? ''),
            '{DIVIDER}' => (string) ($setting['divider'] ?? '/'),
            '{CENTER}' => $center,
            '{SEQ}' => str_pad(
                (string) $sequence,
                max(1, (int) ($setting['sequence_digits'] ?? 3)),
                '0',
                STR_PAD_LEFT
            ),
            '{SUFFIX}' => (string) ($setting['suffix'] ?? '')
        ]
    );
}

function previewNextBillingNumber(mysqli $conn, int $businessId, int $branchId, string $documentType, string $date): string
{
    $documentKey = strtolower($documentType);
    $stmt = $conn->prepare(
        "SELECT * FROM document_number_settings
         WHERE business_id=?
           AND (branch_id=? OR branch_id IS NULL)
           AND document_key=?
           AND is_active=1
         ORDER BY (branch_id=?) DESC,id DESC
         LIMIT 1"
    );
    if (!$stmt) return $documentType === 'Estimate' ? 'EST-NOT-CONFIGURED' : 'INV-NOT-CONFIGURED';
    $stmt->bind_param('iisi', $businessId, $branchId, $documentKey, $branchId);
    $stmt->execute();
    $setting = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$setting) return $documentType === 'Estimate' ? 'EST-NOT-CONFIGURED' : 'INV-NOT-CONFIGURED';

    $periodKey = billingPeriodKey((string)$setting['reset_frequency'], $date);
    $seqStmt = $conn->prepare('SELECT current_number FROM number_sequences WHERE business_id=? AND branch_id=? AND document_type=? AND period_key=? LIMIT 1');
    if (!$seqStmt) return renderBillingNumber($setting, max(1, (int)($setting['sequence_start'] ?? 1)), $date);
    $seqStmt->bind_param('iiss', $businessId, $branchId, $documentType, $periodKey);
    $seqStmt->execute();
    $sequenceRow = $seqStmt->get_result()->fetch_assoc();
    $seqStmt->close();
    $next = $sequenceRow ? ((int)$sequenceRow['current_number'] + 1) : max(1, (int)($setting['sequence_start'] ?? 1));
    return renderBillingNumber($setting, $next, $date);
}

$pageTitle = 'Billing';
$businessName = (string) ($_SESSION['business_name'] ?? 'Jewellery ERP');
$defaultBillNo = previewNextBillingNumber($conn, $businessId, $branchId, 'Invoice', date('Y-m-d'));
// The API locks and increments the same sequence during save.
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($businessName) ?> - Billing</title><?php include('includes/links.php'); ?>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <style>
        :root {
            --primary:
                <?= e($theme['primary_color']) ?>
            ;
            --primary-dark:
                <?= e($theme['primary_dark_color']) ?>
            ;
            --primary-soft:
                <?= e($theme['primary_soft_color']) ?>
            ;
            --page-bg:
                <?= e($theme['page_background']) ?>
            ;
            --card-bg:
                <?= e($theme['card_background']) ?>
            ;
            --text:
                <?= e($theme['text_color']) ?>
            ;
            --muted:
                <?= e($theme['muted_text_color']) ?>
            ;
            --line:
                <?= e($theme['border_color']) ?>
            ;
            --radius:
                <?= (int) $theme['border_radius_px'] ?>
                px
        }

        html,
        body {
            margin: 0 !important;
            padding: 0 !important;
            min-height: 100%;
        }

        body {
            background: var(--page-bg);
            color: var(--text);
            font-family:
                <?= json_encode($theme['font_family']) ?>
                , sans-serif
        }

        .standalone-wrap {
            width: 100%;
            max-width: none;
            margin: 0;
            padding: 8px
        }

        .bill-card {
            box-shadow: 0 2px 8px rgba(0, 0, 0, .025)
        }

        .bill-card {
            background: var(--card-bg);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            overflow: hidden;
            margin-bottom: 8px
        }

        .bill-head {
            padding: 9px 12px;
            border-bottom: 1px solid var(--line);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px
        }

        .bill-title {
            font: 700 20px
                <?= json_encode($theme['heading_font_family']) ?>
                , serif
        }

        .section-title {
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: var(--primary-dark)
        }

        .bill-body {
            padding: 10px
        }

        .field-label {
            display: block;
            font-size: 10px;
            font-weight: 700;
            margin-bottom: 3px
        }

        .form-control,
        .form-select {
            font-size: 11px;
            min-height: 34px;
            border-color: var(--line);
            border-radius: 7px;
            background: var(--card-bg);
            color: var(--text)
        }

        .btn-theme {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border: 0;
            color: #fff;
            font-size: 11px;
            font-weight: 700;
            border-radius: 7px;
            padding: 7px 10px
        }

        .btn-soft {
            background: var(--primary-soft);
            color: var(--primary-dark);
            border: 1px solid color-mix(in srgb, var(--primary) 30%, var(--line));
            font-size: 11px;
            font-weight: 700;
            border-radius: 7px;
            padding: 7px 10px
        }

        .table {
            font-size: 10px
        }

        .table th {
            font-size: 9px;
            text-transform: uppercase;
            color: var(--muted);
            background: color-mix(in srgb, var(--muted) 6%, transparent);
            white-space: nowrap
        }

        .table td,
        .table th {
            border-color: var(--line);
            vertical-align: middle
        }

        .new-customer-panel {
            display: none;
            margin-top: 12px;
            padding: 13px;
            border: 1px dashed var(--primary);
            border-radius: 10px;
            background: var(--primary-soft)
        }

        .new-customer-panel.show {
            display: block
        }

        .summary-card {
            position: sticky;
            top: 12px
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            border-bottom: 1px dashed var(--line);
            font-size: 10px
        }

        .summary-total {
            font-size: 15px;
            font-weight: 800;
            color: var(--primary-dark)
        }

        .scan-wrap {
            display: grid;
            grid-template-columns: minmax(250px, 1fr) auto;
            gap: 8px;
            align-items: end
        }

        .scan-input {
            font-size: 14px;
            font-weight: 700;
            letter-spacing: .05em
        }

        .customer-select-wrap {
            position: relative
        }

        .customer-select-wrap .new-customer-btn {
            position: absolute;
            right: 5px;
            top: 5px;
            z-index: 8;
            height: 24px;
            border: 1px solid color-mix(in srgb, var(--primary) 45%, var(--line));
            background: var(--primary-soft);
            color: var(--primary-dark);
            border-radius: 6px;
            padding: 3px 8px;
            font-size: 9px;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            white-space: nowrap
        }

        .customer-select-wrap .new-customer-btn:hover {
            background: var(--primary);
            color: #fff
        }

        .customer-select-wrap>select {
            padding-right: 108px
        }

        .customer-select-wrap .select2-container .select2-selection__rendered {
            padding-right: 112px !important
        }

        .top-bill-head {
            display: grid;
            grid-template-columns: minmax(170px, auto) minmax(340px, 680px) auto;
            align-items: center;
            gap: 18px
        }

        .top-barcode-wrap {
            display: grid;
            grid-template-columns: minmax(220px, 1fr) auto;
            gap: 7px;
            align-items: center;
            width: 100%;
            justify-self: center
        }

        .top-barcode-wrap .scan-input {
            min-height: 38px
        }

        .top-back-wrap {
            justify-self: end
        }

        .barcode-help {
            grid-column: 1/-1;
            font-size: 9px;
            color: var(--muted);
            margin-top: -2px
        }

        @media(max-width:900px) {
            .top-bill-head {
                grid-template-columns: 1fr auto
            }

            .top-barcode-wrap {
                grid-column: 1/-1;
                grid-row: 2
            }

            .top-back-wrap {
                grid-column: 2;
                grid-row: 1
            }
        }

        .bill-detail-grid {
            display: grid;
            grid-template-columns: repeat(12, minmax(0, 1fr));
            gap: 8px
        }

        .bill-detail-grid .c3 {
            grid-column: span 3
        }

        .bill-detail-grid .c5 {
            grid-column: span 5
        }

        .bill-detail-grid .c7 {
            grid-column: span 7
        }

        .bill-detail-grid .c12 {
            grid-column: 1 / -1
        }

        .customer-block {
            min-width: 0;
        }

        .customer-block .customer-select-wrap {
            width: 100%;
        }

        .product-weight-box {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 4px;
            margin-top: 4px;
            font-size: 8px;
            color: var(--muted)
        }

        .product-weight-box b {
            color: var(--text)
        }

        .adjustment-card {
            border: 1px solid var(--line);
            border-radius: 9px;
            padding: 10px;
            background: color-mix(in srgb, var(--primary) 5%, var(--card-bg));
            margin-bottom: 8px
        }

        .adjustment-grid {
            display: grid;
            grid-template-columns: 1.4fr .8fr .7fr .8fr .8fr .7fr auto;
            gap: 7px;
            align-items: end
        }

        .adjustment-grid .form-control,
        .adjustment-grid .form-select {
            min-height: 34px
        }

        .gram-badge {
            display: inline-flex;
            padding: 4px 8px;
            border-radius: 999px;
            background: #fff3cd;
            color: #8a5a00;
            font-size: 9px;
            font-weight: 800
        }

        .chit-panel {
            display: none;
            width: 100%;
            max-width: 100%;
            margin-top: 6px;
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 6px 7px;
            background: color-mix(in srgb, var(--primary) 3%, var(--card-bg));
            align-items: center;
            gap: 7px;
            box-sizing: border-box;
        }

        .chit-panel.show {
            display: grid;
            grid-template-columns: max-content minmax(0, 1fr) max-content;
            width: 100%;
        }

        .chit-summary {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            min-width: 0;
            white-space: nowrap;
            font-size: 9px;
        }

        .chit-summary strong {
            font-size: 10px;
            color: var(--text);
        }

        .chit-summary-text {
            color: var(--muted);
            font-size: 9px;
        }

        .chit-quick-list {
            display: flex;
            align-items: center;
            gap: 5px;
            min-width: 0;
            overflow-x: auto;
            scrollbar-width: thin;
            padding-bottom: 1px;
        }

        .chit-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            flex: 0 0 auto;
            padding: 5px 7px;
            background: var(--primary-soft);
            border: 1px solid color-mix(in srgb, var(--primary) 18%, var(--line));
            border-radius: 6px;
            font-size: 8px;
            white-space: nowrap;
        }

        .chit-chip strong {
            font-size: 8px;
        }

        .chit-panel .btn-soft {
            padding: 5px 8px;
            min-height: 28px;
            font-size: 9px;
            white-space: nowrap;
        }

        .chit-panel .gram-badge {
            padding: 3px 6px;
            font-size: 8px;
        }

        @media(max-width: 900px) {
            .chit-panel.show {
                grid-template-columns: 1fr auto;
            }

            .chit-quick-list {
                grid-column: 1 / -1;
                grid-row: 2;
            }
        }

        .claim-badge {
            display: inline-flex;
            padding: 4px 8px;
            border-radius: 999px;
            background: #eaf8f0;
            color: #168449;
            font-size: 9px;
            font-weight: 700
        }

        #chitClaimModal .table {
            min-width: 1180px;
        }

        #chitClaimModal .table th,
        #chitClaimModal .table td {
            padding: 8px 9px;
            vertical-align: middle;
        }

        #chitClaimModal .claim-rate,
        #chitClaimModal .claim-value {
            font-weight: 800;
            white-space: nowrap;
        }

        #chitClaimModal .claim-grams {
            min-width: 125px;
        }

        #chitClaimModal .claim-product {
            min-width: 180px;
        }

        .select2-container {
            width: 100% !important
        }

        .select2-container .select2-selection--single {
            height: 34px;
            border-color: var(--line);
            border-radius: 7px;
            background: var(--card-bg)
        }

        .select2-container .select2-selection--single .select2-selection__rendered {
            line-height: 32px;
            font-size: 11px;
            color: var(--text)
        }

        .select2-container .select2-selection--single .select2-selection__arrow {
            height: 32px
        }

        .select2-dropdown {
            border-color: var(--line);
            font-size: 11px
        }

        .theme-toast {
            position: fixed;
            right: 18px;
            top: 18px;
            z-index: 20000;
            padding: 11px 14px;
            border-radius: 10px;
            color: #fff;
            font-size: 11px;
            font-weight: 600;
            opacity: 0;
            transform: translateY(-10px);
            transition: .22s
        }

        .theme-toast.show {
            opacity: 1;
            transform: none
        }

        .theme-toast-success {
            background: #168449
        }

        .theme-toast-error {
            background: #c0392b
        }

        .modal-content {
            background: var(--card-bg);
            color: var(--text);
            border-color: var(--line)
        }

        .modal-header,
        .modal-footer {
            border-color: var(--line)
        }

        .billing-items-table {
            table-layout: fixed;
            min-width: 1120px;
            width: 100%
        }

        .billing-items-table .col-product {
            width: 28%
        }

        .billing-items-table .col-qty {
            width: 7%
        }

        .billing-items-table .col-rate {
            width: 10%
        }

        .billing-items-table .col-wastage {
            width: 7%
        }

        .billing-items-table .col-gst {
            width: 7%
        }

        .billing-items-table .col-making {
            width: 9%
        }

        .billing-items-table .col-money {
            width: 8%
        }

        .billing-items-table .col-total {
            width: 10%
        }

        .billing-items-table .col-action {
            width: 4%
        }

        .billing-items-table th,
        .billing-items-table td,
        .payment-table th,
        .payment-table td {
            padding: 7px 6px
        }

        .billing-items-table .form-control,
        .billing-items-table .form-select,
        .payment-table .form-control,
        .payment-table .form-select {
            width: 100%;
            min-width: 0;
            padding: 6px 8px;
            font-size: 11px
        }

        .billing-items-table .line-total {
            font-weight: 800;
            text-align: right;
            background: var(--primary-soft)
        }

        .billing-items-table .stock-info {
            display: block;
            margin-top: 3px;
            font-size: 8px;
            line-height: 1.25;
            white-space: normal
        }

        .product-detail-strip {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            margin-top: 5px;
        }

        .product-detail-pill {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            padding: 3px 6px;
            border-radius: 6px;
            font-size: 8px;
            font-weight: 800;
            border: 1px solid transparent;
        }

        .detail-barcode {
            color: #374151;
            background: #f3f4f6;
            border-color: #d1d5db;
        }

        .detail-stock {
            color: #0f766e;
            background: #ccfbf1;
            border-color: #99f6e4;
        }

        .detail-gross {
            color: #92400e;
            background: #fef3c7;
            border-color: #fde68a;
        }

        .detail-stone {
            color: #5b21b6;
            background: #ede9fe;
            border-color: #ddd6fe;
        }

        .detail-net {
            color: #166534;
            background: #dcfce7;
            border-color: #bbf7d0;
        }

        .detail-rate {
            color: #9f1239;
            background: #ffe4e6;
            border-color: #fecdd3;
        }

        .detail-date {
            color: #1d4ed8;
            background: #dbeafe;
            border-color: #bfdbfe;
        }

        body.dark-mode .product-detail-pill,
        body[data-theme=dark] .product-detail-pill,
        html.dark-mode body .product-detail-pill,
        html[data-theme=dark] body .product-detail-pill {
            background: rgba(255,255,255,.08);
            border-color: rgba(255,255,255,.16);
            color: #f3f6f8;
        }

        .payment-table {
            table-layout: fixed;
            min-width: 700px;
            width: 100%
        }

        .summary-card .bill-body {
            padding: 10px 12px
        }

        .summary-row {
            padding: 5px 0;
            font-size: 10px;
            gap: 10px
        }

        .summary-row strong {
            white-space: nowrap;
            text-align: right
        }

        .summary-card .form-control {
            min-height: 34px;
            padding: 6px 9px
        }

        .table-responsive {
            overflow-x: auto
        }

        @media(max-width:1199px) {
            .summary-card {
                position: static
            }
        }

        @media(max-width:767px) {
            .bill-detail-grid {
                grid-template-columns: 1fr
            }

            .bill-detail-grid .c3,
            .bill-detail-grid .c5,
            .bill-detail-grid .c7,
            .bill-detail-grid .c12 {
                grid-column: 1
            }

            .adjustment-grid {
                grid-template-columns: 1fr 1fr
            }

            .adjustment-grid .wide {
                grid-column: 1/-1
            }

            .standalone-wrap {
                padding: 8px
            }

            .scan-wrap {
                grid-template-columns: 1fr
            }

            .table {
                min-width: 1100px
            }
        }
    </style>
</head>

<body>
    <div class="standalone-wrap">
        <form id="billingForm" autocomplete="off"><input type="hidden" name="csrf_token"
                value="<?= e($csrfToken) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="document_mode" id="documentMode" value="Invoice"><input type="hidden"
                name="chit_claims_json" id="chitClaimsJson" value="[]"><input type="hidden" name="exchange_items_json"
                id="exchangeItemsJson" value="[]">
            <div class="bill-card">
                <div class="bill-head top-bill-head">
                    <div class="bill-title">Create Bill</div>
                    <div class="top-barcode-wrap">
                        <input type="text" id="barcodeScan" class="form-control scan-input"
                            placeholder="Scan barcode and press Enter" autocomplete="off">
                        <button type="button" class="btn-theme" id="addScannedProduct">
                            <i class="fa-solid fa-barcode me-1"></i>Add Product
                        </button>
                        
                    </div>
                    <div class="top-back-wrap">
                        <a href="sales-list.php" class="btn btn-light btn-sm">
                            <i class="fa-solid fa-arrow-left me-2"></i>Back
                        </a>
                    </div>
                </div>
            </div>
            <div class="row g-2">
                <div class="col-xl-9">
                    <div class="bill-card">
                        <div class="bill-head">
                            <div class="section-title">Bill Details</div>
                        </div>
                        <div class="bill-body">
                            <div class="bill-detail-grid">
                                <div class="c3"><label class="field-label" id="documentNumberLabel">Bill No</label><input class="form-control"
                                        id="documentNumberPreview" value="<?= e($defaultBillNo) ?>" readonly></div>
                                <div class="c3"><label class="field-label">Bill Date *</label><input type="date"
                                        name="invoice_date" id="invoiceDate" class="form-control" value="<?= date('Y-m-d') ?>" required>
                                </div>
                                <div class="c3"><label class="field-label">Bill Time *</label><input type="time"
                                        name="invoice_time" class="form-control" value="<?= date('H:i') ?>" required>
                                </div>
                                <div class="c3"><label class="field-label">Bill Type</label><select name="bill_type" id="billType"
                                        class="form-select select2-static">
                                        <option>Retail</option>
                                        <option>GST</option>
                                        <option>Estimate</option>
                                        <option>Exchange</option>
                                    </select></div>
                                <div class="c12 customer-block"><label class="field-label">Customer *</label>
                                    <div class="customer-select-wrap"><select name="customer_id" id="customerId"
                                            class="form-select select2-customer" required>
                                            <option value="">Select customer</option>
                                            <?php foreach ($customers as $c): ?>
                                                <option value="<?= (int) $c['id'] ?>">
                                                    <?= e($c['customer_name'] . (!empty($c['customer_code']) ? ' - ' . $c['customer_code'] : '') . (!empty($c['mobile']) ? ' - ' . $c['mobile'] : '')) ?>
                                                </option><?php endforeach ?>
                                        </select><button type="button" class="new-customer-btn"
                                            id="openCustomerModal"><i class="fa-solid fa-user-plus"></i>New
                                            Customer</button></div>
                                    <div class="chit-panel" id="customerChitPanel">
                                        <div class="chit-summary">
                                            <strong><i class="fa-solid fa-coins me-1"></i>Gold Savings</strong>
                                            <span class="chit-summary-text" id="chitPanelText"></span>
                                        </div>
                                        <div id="chitQuickList" class="chit-quick-list"></div>
                                        <button type="button" class="btn-soft" id="openClaimModal">
                                            <i class="fa-solid fa-hand-holding-dollar me-1"></i>Claim Grams
                                        </button>
                                    </div>
                                </div>
                                <div class="c12"><label class="field-label">Notes</label><input name="notes"
                                        class="form-control" placeholder="Bill notes"></div>
                            </div>
                        </div>
                    </div>
                    <div class="bill-card">
                        <div class="bill-head">
                            <div class="section-title">Bill Items</div><button type="button" class="btn-theme btn-sm"
                                id="addItem"><i class="fa-solid fa-plus me-1"></i>Add Item</button>
                        </div>
                        <div class="table-responsive">
                            <table class="table mb-0 billing-items-table">
                                <colgroup>
                                    <col class="col-product">
                                    <col class="col-qty">
                                    <col class="col-rate">
                                    <col class="col-wastage">
                                    <col class="col-gst">
                                    <col class="col-making">
                                    <col class="col-money">
                                    <col class="col-money">
                                    <col class="col-money">
                                    <col class="col-total">
                                    <col class="col-action">
                                </colgroup>
                                <thead>
                                    <tr>
                                        <th>Product / Weight</th>
                                        <th>Qty</th>
                                        <th>Rate</th>
                                        <th>Wastage %</th>
                                        <th>GST %</th>
                                        <th>Making</th>
                                        <th>Stone</th>
                                        <th>Other</th>
                                        <th>Discount</th>
                                        <th>Total</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody id="itemsBody"></tbody>
                            </table>
                        </div>
                    </div>
                    <div class="bill-card">
                        <div class="bill-head">
                            <div class="section-title">Old Gold / Exchange Items</div><button type="button"
                                class="btn-theme btn-sm" id="addExchange"><i class="fa-solid fa-plus me-1"></i>Add
                                Exchange</button>
                        </div>
                        <div class="bill-body">
                            <div id="exchangeItems"></div>
                            <div class="text-end"><strong>Exchange Total: ₹<span id="exchangeTotal">0.00</span></strong>
                            </div>
                        </div>
                    </div>
                    <div class="bill-card">
                        <div class="bill-head">
                            <div class="section-title">Split Payments</div><button type="button"
                                class="btn-theme btn-sm" id="addPayment"><i class="fa-solid fa-plus me-1"></i>Add
                                Payment</button>
                        </div>
                        <div class="table-responsive">
                            <table class="table mb-0 payment-table">
                                <colgroup>
                                    <col style="width:28%">
                                    <col style="width:22%">
                                    <col style="width:44%">
                                    <col style="width:6%">
                                </colgroup>
                                <thead>
                                    <tr>
                                        <th>Method</th>
                                        <th>Amount</th>
                                        <th>Reference</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody id="paymentsBody"></tbody>
                            </table>
                        </div>
                        <div class="bill-body text-end"><strong>Split Total: ₹<span id="splitTotal">0.00</span></strong>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3">
                    <div class="bill-card summary-card">
                        <div class="bill-head">
                            <div class="section-title">Bill Summary</div>
                        </div>
                        <div class="bill-body"><label class="field-label">Overall Discount</label><input type="number"
                                min="0" step="0.01" name="overall_discount" id="overallDiscount"
                                class="form-control mb-2" value="0"><label class="field-label">Round Off</label><input
                                type="number" step="0.01" name="round_off" id="roundOff" class="form-control mb-2"
                                value="0"><label class="field-label">Paid Amount</label><input type="number" min="0"
                                step="0.01" name="paid_amount" id="paidAmount" class="form-control mb-3" value="0"
                                readonly>
                            <div class="summary-row"><span>Subtotal</span><strong>₹<span
                                        id="sumSubtotal">0.00</span></strong></div>
                            <div class="summary-row"><span>Discount</span><strong>₹<span
                                        id="sumDiscount">0.00</span></strong></div>
                            <div class="summary-row"><span>Taxable</span><strong>₹<span
                                        id="sumTaxable">0.00</span></strong></div>
                            <div class="summary-row"><span>CGST</span><strong>₹<span id="sumCgst">0.00</span></strong>
                            </div>
                            <div class="summary-row"><span>SGST</span><strong>₹<span id="sumSgst">0.00</span></strong>
                            </div>
                            <div class="summary-row"><span>Exchange Value</span><strong class="text-success">- ₹<span
                                        id="sumExchange">0.00</span></strong></div>
                            <div class="summary-row"><span>Gold Gram Claim</span><strong class="text-success">- ₹<span
                                        id="sumChitClaim">0.00</span></strong></div>
                            <div class="summary-row"><span>Grand Total</span><strong class="summary-total">₹<span
                                        id="sumGrand">0.00</span></strong></div>
                            <div class="summary-row"><span>Balance</span><strong>₹<span
                                        id="sumBalance">0.00</span></strong></div><button
                                class="btn btn-theme w-100 mt-3" id="saveBtn"><i class="fa-solid fa-floppy-disk me-2"></i><span id="saveButtonText">Save Bill</span></button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
    <div class="modal fade" id="newCustomerModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title">Add Billing Customer</h5>
                        <div class="small text-muted">The customer will be created with Billing service enabled.</div>
                    </div><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="newCustomerForm">
                    <div class="modal-body"><input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>"><input
                            type="hidden" name="action" value="create_customer">
                        <div class="row g-2">
                            <div class="col-md-6"><label class="field-label">Customer Name *</label><input
                                    name="customer_name" class="form-control" required></div>
                            <div class="col-md-6"><label class="field-label">Mobile *</label><input name="mobile"
                                    class="form-control" maxlength="20" required></div>
                            <div class="col-md-6"><label class="field-label">Email</label><input type="email"
                                    name="email" class="form-control"></div>
                            <div class="col-md-6"><label class="field-label">GSTIN</label><input name="gstin"
                                    class="form-control text-uppercase" maxlength="30"></div>
                            <div class="col-md-8"><label class="field-label">Address</label><input name="address_line1"
                                    class="form-control"></div>
                            <div class="col-md-4"><label class="field-label">Pincode</label><input name="pincode"
                                    class="form-control" maxlength="20"></div>
                        </div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-light"
                            data-bs-dismiss="modal">Cancel</button><button class="btn-theme" id="saveCustomerBtn">Create
                            Customer</button></div>
                </form>
            </div>
        </div>
    </div>
    <div class="modal fade" id="chitClaimModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title">Claim Saved Gold Grams</h5>
                        <div class="small text-muted">Enter the grams to claim. Partial claims keep the remaining grams available for future bills.</div>
                    </div><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Chit</th>
                                    <th>Ticket</th>
                                    <th>Status</th>
                                    <th>Total Saved</th>
                                    <th>Claimed</th>
                                    <th>Balance</th>
                                    <th style="min-width:180px">Apply Against Product</th>
                                    <th>Rate / Gram</th>
                                    <th style="min-width:140px">Claim Now</th>
                                    <th>Claim Value</th>
                                </tr>
                            </thead>
                            <tbody id="claimTableBody"></tbody>
                        </table>
                    </div>
                    <div id="claimEmpty" class="text-center text-muted py-4 d-none">No claimable chits found for this
                        customer.</div>
                </div>
                <div class="modal-footer">
                    <div class="me-auto"><strong>Total Gram Claim: <span id="modalClaimGrams">0.000000</span> g · ₹<span
                                id="modalClaimTotal">0.00</span></strong></div>
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button
                        type="button" class="btn-theme" id="applyChitClaims">Apply Claim</button>
                </div>
            </div>
        </div>
    </div>
    <?php include('includes/script.php'); ?>
    <script src="assets/js/script.js"></script>
    <script>
        (function () {
            'use strict';

            const products = <?= json_encode($products, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
            const payments = <?= json_encode($paymentMethods, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
            const csrfToken = <?= json_encode($csrfToken) ?>;
            const billTypeSelect = document.getElementById('billType');
            const invoiceDateInput = document.getElementById('invoiceDate');
            const documentNumberPreview = document.getElementById('documentNumberPreview');
            const documentMode = document.getElementById('documentMode');
            const saveButtonText = document.getElementById('saveButtonText');
            const documentNumberLabel = document.getElementById('documentNumberLabel');
            let documentNumberRequestId = 0;

            async function refreshDocumentNumber() {
                const selectedBillType = String(billTypeSelect ? billTypeSelect.value : 'Retail').trim();
                const isEstimate = selectedBillType.toLowerCase() === 'estimate';
                const type = isEstimate ? 'Estimate' : 'Invoice';

                if (documentMode) documentMode.value = type;
                if (documentNumberLabel) documentNumberLabel.textContent = isEstimate ? 'Estimate No' : 'Bill No';
                if (saveButtonText) saveButtonText.textContent = isEstimate ? 'Save Estimate' : 'Save Bill';

                const saveButton = document.getElementById('saveBtn');
                if (saveButton) {
                    saveButton.setAttribute('aria-label', isEstimate ? 'Save Estimate' : 'Save Bill');
                    saveButton.title = isEstimate ? 'Save Estimate' : 'Save Bill';
                }

                if (!documentNumberPreview || !invoiceDateInput) return;
                const requestId = ++documentNumberRequestId;
                documentNumberPreview.value = 'Loading...';
                try {
                    const fd = new FormData();
                    fd.append('action', 'preview_number');
                    fd.append('csrf_token', csrfToken);
                    fd.append('document_type', type);
                    fd.append('document_date', invoiceDateInput.value || new Date().toISOString().slice(0, 10));
                    const response = await fetch('api/billing-save.php', {
                        method: 'POST', body: fd, credentials: 'same-origin',
                        headers: {'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}
                    });
                    const result = await response.json();
                    if (!response.ok || !result.success) throw new Error(result.message || 'Unable to load next number.');
                    if (requestId !== documentNumberRequestId) return;
                    documentNumberPreview.value = result.document_no;
                } catch (error) {
                    if (requestId !== documentNumberRequestId) return;
                    documentNumberPreview.value = type === 'Estimate' ? 'EST-NOT-CONFIGURED' : 'INV-NOT-CONFIGURED';
                    toast('error', error.message);
                }
            }

            function bindDocumentModeControls() {
                if (billTypeSelect && billTypeSelect.dataset.documentModeBound !== '1') {
                    billTypeSelect.dataset.documentModeBound = '1';
                    billTypeSelect.addEventListener('change', refreshDocumentNumber);
                    billTypeSelect.addEventListener('input', refreshDocumentNumber);
                }

                if (invoiceDateInput && invoiceDateInput.dataset.documentModeBound !== '1') {
                    invoiceDateInput.dataset.documentModeBound = '1';
                    invoiceDateInput.addEventListener('change', refreshDocumentNumber);
                    invoiceDateInput.addEventListener('input', refreshDocumentNumber);
                }

                if (window.jQuery && billTypeSelect) {
                    window.jQuery(billTypeSelect)
                        .off('.documentMode')
                        .on('select2:select.documentMode select2:clear.documentMode change.documentMode', refreshDocumentNumber);
                }

                refreshDocumentNumber();
            }

            bindDocumentModeControls();

            function loadScript(src) {
                return new Promise((resolve, reject) => {
                    const existing = [...document.scripts].find(s => s.src === src);
                    if (existing) {
                        if (existing.dataset.loaded === '1') return resolve();
                        existing.addEventListener('load', resolve, { once: true });
                        existing.addEventListener('error', reject, { once: true });
                        return;
                    }
                    const script = document.createElement('script');
                    script.src = src;
                    script.onload = () => { script.dataset.loaded = '1'; resolve(); };
                    script.onerror = reject;
                    document.head.appendChild(script);
                });
            }

            async function ensureSelect2() {
                try {
                    if (!window.jQuery) {
                        await loadScript('https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js');
                    }
                    if (!window.jQuery || !window.jQuery.fn) return false;
                    if (!window.jQuery.fn.select2) {
                        await loadScript('https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js');
                    }
                    return !!window.jQuery.fn.select2;
                } catch (error) {
                    console.warn('Select2 could not be loaded. Native selects will be used.', error);
                    return false;
                }
            }

            function startBillingPage() {
                const form = document.getElementById('billingForm');
                const items = document.getElementById('itemsBody');
                const pays = document.getElementById('paymentsBody');
                const customer = document.getElementById('customerId');
                const newCustomerModalElement = document.getElementById('newCustomerModal');
                const newCustomerForm = document.getElementById('newCustomerForm');
                const scan = document.getElementById('barcodeScan');
                const claimModalElement = document.getElementById('chitClaimModal');
                let newCustomerModal = null;

                if (!form || !items || !pays || !customer || !scan) {
                    console.error('Billing page elements are missing.');
                    return;
                }

                const byBarcode = new Map(
                    products
                        .filter(p => String(p.barcode || '').trim())
                        .map(p => [String(p.barcode).trim().toLowerCase(), p])
                );

                let customerChits = [];
                let appliedClaims = [];
                let exchangeItems = [];
                let select2Ready = false;
                let claimModal = null;

                if (window.bootstrap && window.bootstrap.Modal && claimModalElement) { claimModal = new window.bootstrap.Modal(claimModalElement); }
                if (window.bootstrap && window.bootstrap.Modal && newCustomerModalElement) { newCustomerModal = new window.bootstrap.Modal(newCustomerModalElement); }

                function showClaimModal() {
                    if (claimModal) {
                        claimModal.show();
                        return;
                    }
                    claimModalElement.classList.add('show');
                    claimModalElement.style.display = 'block';
                    claimModalElement.removeAttribute('aria-hidden');
                    claimModalElement.setAttribute('aria-modal', 'true');
                    document.body.classList.add('modal-open');
                }

                function hideClaimModal() {
                    if (claimModal) {
                        claimModal.hide();
                        return;
                    }
                    claimModalElement.classList.remove('show');
                    claimModalElement.style.display = 'none';
                    claimModalElement.setAttribute('aria-hidden', 'true');
                    claimModalElement.removeAttribute('aria-modal');
                    document.body.classList.remove('modal-open');
                }

                function esc(v) {
                    return String(v ?? '').replace(/[&<>'"]/g, c => ({
                        '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;'
                    }[c]));
                }

                function toast(type, message) {
                    const x = document.createElement('div');
                    x.className = 'theme-toast theme-toast-' + type;
                    x.textContent = message;
                    document.body.appendChild(x);
                    requestAnimationFrame(() => x.classList.add('show'));
                    setTimeout(() => {
                        x.classList.remove('show');
                        setTimeout(() => x.remove(), 250);
                    }, 3200);
                }

                function money(v) {
                    return Number(v || 0).toFixed(2);
                }

                function initSelect2(target, placeholder) {
                    if (!select2Ready || !window.jQuery || !window.jQuery.fn.select2) return;
                    const $elements = window.jQuery(target);
                    $elements.each(function () {
                        const $el = window.jQuery(this);
                        if ($el.data('select2')) return;
                        $el.select2({ width: '100%', placeholder: placeholder || 'Select', allowClear: true });
                        if ($el.hasClass('product-select')) {
                            $el.on('select2:select select2:clear change', function () {
                                const row = this.closest('tr'); const product = products.find(x => String(x.id) === String(this.value));
                                if (product) fillProduct(row, product); else calc();
                            });
                        }
                    });
                }

                function productOptions() {
                    return '<option value="">Select product</option>' + products.map(p =>
                        `<option value="${Number(p.id)}">${esc(p.product_name + ' - ' + p.product_code + ' (Stock: ' + Number(p.stock_qty).toFixed(3) + ')')}</option>`
                    ).join('');
                }

                function paymentOptions() {
                    return '<option value="">Select method</option>' + payments.map(p =>
                        `<option value="${Number(p.id)}">${esc(p.method_name)}</option>`
                    ).join('');
                }

                function enhanceRow(row) {
                    const productSelect = row.querySelector('.product-select');
                    const paymentSelect = row.querySelector('.payment-method');
                    if (productSelect) initSelect2(productSelect, 'Select product');
                    if (paymentSelect) initSelect2(paymentSelect, 'Select method');
                }

                function fillProduct(row, product) {
                    row.querySelector('.rate').value = product.live_metal_rate || product.sale_rate || 0;
                    row.querySelector('.wastage').value = product.wastage_percent || 0;
                    row.querySelector('.gst').value = product.tax_percent || 0;
                    row.querySelector('.making').value = product.making_charge || 0;

                    const rate = product.live_metal_rate || product.sale_rate || 0;
                    const effectiveDate = product.live_rate_effective_from || 'Product rate';

                    row.querySelector('.stock-info').innerHTML =
                        '<div class="product-detail-strip">' +
                            '<span class="product-detail-pill detail-barcode"><i class="fa-solid fa-barcode"></i>Barcode: <b>' + esc(product.barcode || '—') + '</b></span>' +
                            '<span class="product-detail-pill detail-stock"><i class="fa-solid fa-boxes-stacked"></i>Stock: <b>' + Number(product.stock_qty || 0).toFixed(3) + '</b></span>' +
                            '<span class="product-detail-pill detail-gross">Gross: <b>' + Number(product.gross_weight || 0).toFixed(3) + ' g</b></span>' +
                            '<span class="product-detail-pill detail-stone">Stone: <b>' + Number(product.stone_weight || 0).toFixed(3) + ' g</b></span>' +
                            '<span class="product-detail-pill detail-net">Net: <b>' + Number(product.net_weight || 0).toFixed(3) + ' g</b></span>' +
                            '<span class="product-detail-pill detail-rate">Rate: <b>₹' + money(rate) + '/g</b></span>' +
                            '<span class="product-detail-pill detail-date"><i class="fa-regular fa-calendar"></i><b>' + esc(effectiveDate) + '</b></span>' +
                        '</div>';
                    calc();
                }

                function addItem(product = null, qty = 1) {
                    items.insertAdjacentHTML('beforeend', `
                    <tr class="item-row">
                        <td>
                            <select name="product_id[]" class="form-select product-select">${productOptions()}</select>
                            <small class="text-muted stock-info"></small>
                        </td>
                        <td><input name="quantity[]" type="number" min="0.001" step="0.001" value="${qty}" class="form-control qty"></td>
                        <td><input name="metal_rate[]" type="number" min="0" step="0.01" value="0" class="form-control rate"></td>
                        <td><input name="wastage_percent[]" type="number" min="0" step="0.001" value="0" class="form-control wastage"></td>
                        <td><input name="tax_percent[]" type="number" min="0" max="100" step="0.01" value="0" class="form-control gst"></td>
                        <td><input name="making_charge[]" type="number" min="0" step="0.01" value="0" class="form-control making"></td>
                        <td><input name="stone_amount[]" type="number" min="0" step="0.01" value="0" class="form-control stone"></td>
                        <td><input name="other_charge[]" type="number" min="0" step="0.01" value="0" class="form-control other"></td>
                        <td><input name="item_discount[]" type="number" min="0" step="0.01" value="0" class="form-control discount"></td>
                        <td><input class="form-control line-total" value="0.00" readonly></td>
                        <td><button type="button" class="btn btn-sm btn-outline-danger remove-row"><i class="fa-solid fa-xmark"></i></button></td>
                    </tr>`);

                    const row = items.lastElementChild;
                    enhanceRow(row);

                    if (product) {
                        const select = row.querySelector('.product-select');
                        select.value = String(product.id);
                        if (select2Ready && window.jQuery) {
                            window.jQuery(select).val(String(product.id)).trigger('change.select2');
                        }
                        fillProduct(row, product);
                    }
                    calc();
                }

                function addPay() {
                    pays.insertAdjacentHTML('beforeend', `
                    <tr class="payment-row">
                        <td><select name="payment_method_id[]" class="form-select payment-method">${paymentOptions()}</select></td>
                        <td><input name="payment_amount[]" type="number" min="0" step="0.01" value="0" class="form-control pay-amount"></td>
                        <td><input name="payment_reference[]" class="form-control" placeholder="UPI / Txn / Ref"></td>
                        <td><button type="button" class="btn btn-sm btn-outline-danger remove-row"><i class="fa-solid fa-xmark"></i></button></td>
                    </tr>`);
                    enhanceRow(pays.lastElementChild);
                    calc();
                }

                function addByBarcode() {
                    const originalCode = scan.value.trim();
                    const code = originalCode.toLowerCase();
                    if (!code) {
                        scan.focus();
                        return;
                    }
                    const product = byBarcode.get(code);
                    if (!product) {
                        toast('error', 'No active product found for barcode ' + originalCode);
                        scan.select();
                        return;
                    }
                    const existing = [...items.querySelectorAll('.item-row')].find(row =>
                        row.querySelector('.product-select').value === String(product.id)
                    );
                    if (existing) {
                        const qty = existing.querySelector('.qty');
                        qty.value = (Number(qty.value) || 0) + 1;
                        calc();
                    } else {
                        addItem(product, 1);
                    }
                    scan.value = '';
                    scan.focus();
                    toast('success', product.product_name + ' added.');
                }

                function claimTotal() { return appliedClaims.reduce((sum, x) => sum + Number(x.claim_amount || 0), 0) }
                function exchangeTotalValue() { return exchangeItems.reduce((sum, x) => sum + Number(x.exchange_value || 0), 0) }
                function selectedBillProductOptions(selected = '') { return [...items.querySelectorAll('.item-row')].map((row, index) => { const p = products.find(x => String(x.id) === row.querySelector('.product-select').value); return p ? `<option value="${Number(p.id)}" ${String(p.id) === String(selected) ? 'selected' : ''}>${esc(p.product_name)} · ${Number(p.net_weight || 0).toFixed(3)}g</option>` : '' }).join('') }

                function calc() {
                    let subtotal = 0;
                    let itemDisc = 0;
                    let taxable = 0;
                    let cgst = 0;
                    let sgst = 0;

                    items.querySelectorAll('.item-row').forEach(row => {
                        const product = products.find(x => String(x.id) === row.querySelector('.product-select').value);
                        const qty = Number(row.querySelector('.qty').value) || 0;
                        const rate = Number(row.querySelector('.rate').value) || 0;
                        const wastagePercent = Number(row.querySelector('.wastage').value) || 0;
                        const gstPercent = Number(row.querySelector('.gst').value) || 0;
                        const making = Number(row.querySelector('.making').value) || 0;
                        const stone = Number(row.querySelector('.stone').value) || 0;
                        const other = Number(row.querySelector('.other').value) || 0;
                        const discount = Number(row.querySelector('.discount').value) || 0;

                        if (!product) {
                            row.querySelector('.line-total').value = '0.00';
                            return;
                        }

                        const netWeight = (Number(product.net_weight) || 0) * qty;
                        const metal = netWeight > 0 ? netWeight * rate : qty * rate;
                        const wastage = metal * wastagePercent / 100;
                        const rowSubtotal = metal + making + wastage + stone + other;
                        const rowTaxable = Math.max(0, rowSubtotal - discount);
                        const tax = rowTaxable * gstPercent / 100;
                        const total = rowTaxable + tax;

                        row.querySelector('.line-total').value = money(total);
                        subtotal += rowSubtotal;
                        itemDisc += discount;
                        taxable += rowTaxable;
                        cgst += tax / 2;
                        sgst += tax / 2;
                    });

                    const overall = Number(document.getElementById('overallDiscount').value) || 0;
                    const round = Number(document.getElementById('roundOff').value) || 0;
                    taxable = Math.max(0, taxable - overall);

                    const beforeAdjustments = taxable + cgst + sgst + round;
                    const exchangeValue = Math.min(exchangeTotalValue(), Math.max(0, beforeAdjustments));
                    const afterExchange = Math.max(0, beforeAdjustments - exchangeValue);
                    const appliedClaim = Math.min(claimTotal(), afterExchange);
                    const grand = Math.max(0, afterExchange - appliedClaim);
                    const paid = Math.min([...pays.querySelectorAll('.pay-amount')].reduce((a, x) => a + (Number(x.value) || 0), 0), grand);

                    document.getElementById('sumSubtotal').textContent = money(subtotal);
                    document.getElementById('sumDiscount').textContent = money(itemDisc + overall);
                    document.getElementById('sumTaxable').textContent = money(taxable);
                    document.getElementById('sumCgst').textContent = money(cgst);
                    document.getElementById('sumSgst').textContent = money(sgst);
                    document.getElementById('sumExchange').textContent = money(exchangeValue);
                    document.getElementById('sumChitClaim').textContent = money(appliedClaim);
                    document.getElementById('sumGrand').textContent = money(grand);
                    document.getElementById('sumBalance').textContent = money(Math.max(0, grand - paid));

                    let split = 0;
                    pays.querySelectorAll('.pay-amount').forEach(x => split += Number(x.value) || 0);
                    document.getElementById('splitTotal').textContent = money(split);
                    document.getElementById('paidAmount').value = money(split);
                    document.getElementById('sumBalance').textContent = money(Math.max(0, grand - Math.min(split, grand)));
                    document.getElementById('chitClaimsJson').value = JSON.stringify(appliedClaims);
                    document.getElementById('exchangeItemsJson').value = JSON.stringify(exchangeItems);
                }

                async function loadCustomerChits() {
                    customerChits = [];
                    appliedClaims = [];
                    calc();

                    const id = Number(customer.value || 0);
                    const wrap = document.getElementById('customerChitPanel');
                    const list = document.getElementById('chitQuickList');
                    wrap.classList.remove('show');
                    list.innerHTML = '';
                    document.getElementById('openClaimModal').disabled = false;
                    if (!id) return;

                    try {
                        const fd = new FormData();
                        fd.append('action', 'list_customer_chits');
                        fd.append('csrf_token', csrfToken);
                        fd.append('customer_id', String(id));
                        const res = await fetch('api/sale-chit-claims.php', {
                            method: 'POST', body: fd, credentials: 'same-origin',
                            headers: { 'X-Requested-With': 'XMLHttpRequest' }
                        });
                        const data = await res.json();
                        if (!res.ok || !data.success) throw new Error(data.message || 'Unable to load chits.');
                        customerChits = (data.chits || []).map(chit => ({
                            ...chit,
                            saved_grams: Math.max(0, Number(chit.saved_grams || 0)),
                            claimed_grams: Math.max(0, Number(chit.claimed_grams || 0)),
                            available_grams: Math.max(0, Number(chit.available_grams || 0))
                        }));

                        const claimableChits = customerChits.filter(
                            chit => Number(chit.available_grams || 0) > 0.0000005
                        );

                        if (customerChits.length) {
                            wrap.classList.add('show');

                            const balanceGrams = claimableChits.reduce(
                                (sum, chit) => sum + Number(chit.available_grams || 0),
                                0
                            );

                            document.getElementById('chitPanelText').textContent =
                                claimableChits.length + ' claimable chit(s) · ' +
                                balanceGrams.toFixed(6) + ' g balance';

                            list.innerHTML = claimableChits.length
                                ? claimableChits.map(chit =>
                                    `<div class="chit-chip">
                                        <span><strong>${esc(chit.group_name)}</strong> · ${esc(chit.ticket_no)}</span>
                                        <span class="gram-badge">${Number(chit.available_grams || 0).toFixed(6)} g balance</span>
                                    </div>`
                                ).join('')
                                : '<div class="chit-chip"><span><strong>No balance grams available</strong></span></div>';

                            document.getElementById('openClaimModal').disabled =
                                claimableChits.length === 0;
                        }
                    } catch (error) {
                        toast('error', error.message);
                    }
                }

                function renderClaimModal() {
                    const body = document.getElementById('claimTableBody');
                    const empty = document.getElementById('claimEmpty');

                    body.innerHTML = customerChits.map(chit => {
                        const current = appliedClaims.find(
                            claim => Number(claim.chit_member_id) === Number(chit.chit_member_id)
                        );

                        const saved = Number(chit.saved_grams || 0);
                        const claimed = Number(chit.claimed_grams || 0);
                        const available = Math.max(0, Number(chit.available_grams || 0));
                        const currentGrams = current
                            ? Math.min(Number(current.claim_grams || 0), available)
                            : 0;

                        return `
                            <tr data-member="${Number(chit.chit_member_id)}">
                                <td>
                                    <strong>${esc(chit.group_name)}</strong>
                                    <div class="small text-muted">
                                        ${esc(chit.group_no)} · ${esc(chit.chit_type)}
                                    </div>
                                </td>
                                <td>${esc(chit.ticket_no)}</td>
                                <td>${esc(chit.member_status)}</td>
                                <td><strong>${saved.toFixed(6)} g</strong></td>
                                <td>${claimed.toFixed(6)} g</td>
                                <td>
                                    <strong class="text-success">${available.toFixed(6)} g</strong>
                                    <div class="small text-muted">Remaining claimable grams</div>
                                </td>
                                <td>
                                    <select class="form-select claim-product" ${available <= 0.0000005 ? 'disabled' : ''}>
                                        <option value="">Select product</option>
                                        ${selectedBillProductOptions(current ? current.product_id : '')}
                                    </select>
                                </td>
                                <td class="claim-rate">₹0.00</td>
                                <td>
                                    <input
                                        type="number"
                                        inputmode="decimal"
                                        class="form-control claim-grams"
                                        min="0"
                                        max="${available.toFixed(6)}"
                                        step="0.000001"
                                        value="${currentGrams > 0 ? currentGrams.toFixed(6) : ''}"
                                        placeholder="0.000000"
                                        ${available <= 0.0000005 ? 'disabled' : ''}>
                                </td>
                                <td class="claim-value">₹0.00</td>
                            </tr>`;
                    }).join('');

                    empty.classList.toggle('d-none', customerChits.length > 0);
                    updateModalClaimTotal();
                }

                function updateModalClaimTotal() { let grams = 0, total = 0; document.querySelectorAll('#claimTableBody tr[data-member]').forEach(row => { const p = products.find(x => String(x.id) === row.querySelector('.claim-product').value); const g = Number(row.querySelector('.claim-grams').value) || 0; const rate = Number(p?.live_metal_rate || p?.sale_rate || 0); const value = g * rate; row.querySelector('.claim-rate').textContent = '₹' + money(rate); row.querySelector('.claim-value').textContent = '₹' + money(value); grams += g; total += value }); document.getElementById('modalClaimGrams').textContent = grams.toFixed(6); document.getElementById('modalClaimTotal').textContent = money(total) }

                customer.addEventListener('change', loadCustomerChits);

                bindDocumentModeControls();

                document.getElementById('openCustomerModal').addEventListener('click', () => {
                    if (newCustomerModal) newCustomerModal.show();
                    else { newCustomerModalElement.classList.add('show'); newCustomerModalElement.style.display = 'block'; }
                    setTimeout(() => newCustomerForm.querySelector('[name=customer_name]').focus(), 150);
                });
                newCustomerForm.addEventListener('submit', async event => {
                    event.preventDefault();
                    const button = document.getElementById('saveCustomerBtn'); const old = button.innerHTML;
                    button.disabled = true; button.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Saving...';
                    try {
                        const res = await fetch('api/billing-customer-save.php', { method: 'POST', body: new FormData(newCustomerForm), credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } });
                        const raw = await res.text(); let data; try { data = JSON.parse(raw); } catch (_) { throw new Error('Customer API returned an invalid response.'); }
                        if (!res.ok || !data.success) throw new Error(data.message || 'Unable to create customer.');
                        const option = new Option(data.customer.customer_name + ' - ' + data.customer.customer_code + ' - ' + data.customer.mobile, data.customer.id, true, true);
                        customer.add(option);
                        if (select2Ready && window.jQuery) window.jQuery(customer).trigger('change'); else customer.dispatchEvent(new Event('change', { bubbles: true }));
                        newCustomerForm.reset(); if (newCustomerModal) newCustomerModal.hide();
                        toast('success', 'Billing customer created.'); scan.focus();
                    } catch (error) { toast('error', error.message); } finally { button.disabled = false; button.innerHTML = old; }
                });

                function renderExchangeItems() { const wrap = document.getElementById('exchangeItems'); wrap.innerHTML = exchangeItems.map((x, i) => `<div class="adjustment-card" data-index="${i}"><div class="adjustment-grid"><div class="wide"><label class="field-label">Old Item Name</label><input class="form-control ex-name" value="${esc(x.item_name || '')}"></div><div><label class="field-label">Gross Gram</label><input type="number" min="0" step="0.001" class="form-control ex-gross" value="${Number(x.gross_weight || 0)}"></div><div><label class="field-label">Wastage %</label><input type="number" min="0" max="100" step="0.001" class="form-control ex-waste" value="${Number(x.wastage_percent || 0)}"></div><div><label class="field-label">Eligible Gram</label><input class="form-control ex-net" readonly value="${Number(x.eligible_weight || 0).toFixed(3)}"></div><div><label class="field-label">Current Rate</label><input type="number" min="0" step="0.01" class="form-control ex-rate" value="${Number(x.rate_per_gram || 0)}"></div><div><label class="field-label">Value</label><input class="form-control ex-value" readonly value="${money(x.exchange_value || 0)}"></div><button type="button" class="btn btn-outline-danger remove-exchange"><i class="fa-solid fa-trash"></i></button></div></div>`).join(''); document.getElementById('exchangeTotal').textContent = money(exchangeTotalValue()); calc() }
                function syncExchange() { exchangeItems = []; document.querySelectorAll('#exchangeItems .adjustment-card').forEach(card => { const gross = Number(card.querySelector('.ex-gross').value) || 0, waste = Number(card.querySelector('.ex-waste').value) || 0, rate = Number(card.querySelector('.ex-rate').value) || 0, eligible = Math.max(0, gross * (1 - waste / 100)), value = eligible * rate; card.querySelector('.ex-net').value = eligible.toFixed(3); card.querySelector('.ex-value').value = money(value); exchangeItems.push({ item_name: card.querySelector('.ex-name').value.trim(), gross_weight: gross, wastage_percent: waste, eligible_weight: eligible, rate_per_gram: rate, exchange_value: value }) }); document.getElementById('exchangeTotal').textContent = money(exchangeTotalValue()); calc() }
                document.getElementById('addExchange').addEventListener('click', () => { const defaultRate = products.find(p => Number(p.live_metal_rate || p.sale_rate || 0) > 0); exchangeItems.push({ item_name: 'Old Gold', gross_weight: 0, wastage_percent: 0, eligible_weight: 0, rate_per_gram: Number(defaultRate?.live_metal_rate || defaultRate?.sale_rate || 0), exchange_value: 0 }); renderExchangeItems() });
                document.getElementById('exchangeItems').addEventListener('input', syncExchange); document.getElementById('exchangeItems').addEventListener('click', e => { const b = e.target.closest('.remove-exchange'); if (!b) return; exchangeItems.splice(Number(b.closest('.adjustment-card').dataset.index), 1); renderExchangeItems() });

                document.getElementById('addItem').addEventListener('click', () => addItem());
                document.getElementById('addPayment').addEventListener('click', addPay);
                document.getElementById('addScannedProduct').addEventListener('click', addByBarcode);

                scan.addEventListener('keydown', event => {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        addByBarcode();
                    }
                });

                document.getElementById('openClaimModal').addEventListener('click', () => {
                    renderClaimModal();
                    showClaimModal();
                });

                document.getElementById('claimTableBody').addEventListener('input', event => {
                    if (!event.target.classList.contains('claim-grams')) return;

                    const input = event.target;
                    const max = Number(input.max) || 0;

                    if (input.value === '') {
                        updateModalClaimTotal();
                        return;
                    }

                    let value = Number(input.value);

                    if (!Number.isFinite(value)) {
                        return;
                    }

                    if (value < 0) {
                        input.value = '0';
                    } else if (value > max) {
                        input.value = String(max);
                        toast('error', 'Claim grams cannot exceed the balance grams.');
                    }

                    updateModalClaimTotal();
                });

                document.getElementById('claimTableBody').addEventListener('change', event => {
                    if (event.target.classList.contains('claim-grams')) {
                        const input = event.target;
                        const max = Number(input.max) || 0;
                        let value = Number(input.value || 0);

                        value = Math.max(0, Math.min(value, max));
                        input.value = value > 0 ? value.toFixed(6) : '';
                        updateModalClaimTotal();
                        return;
                    }

                    if (event.target.classList.contains('claim-product')) {
                        updateModalClaimTotal();
                    }
                });

                document.getElementById('applyChitClaims').addEventListener('click', () => {
                    const nextClaims = [];
                    let validationError = '';

                    document.querySelectorAll('#claimTableBody tr[data-member]').forEach(row => {
                        if (validationError) return;

                        const memberId = Number(row.dataset.member);
                        const grams = Number(row.querySelector('.claim-grams').value) || 0;
                        const productId = Number(row.querySelector('.claim-product').value) || 0;
                        const chit = customerChits.find(
                            x => Number(x.chit_member_id) === memberId
                        );

                        if (grams <= 0) return;

                        if (!chit) {
                            validationError = 'Unable to find the selected chit member.';
                            return;
                        }

                        const available = Number(chit.available_grams || 0);

                        if (grams > available + 0.000001) {
                            validationError =
                                'Claim exceeds available grams for ' + chit.group_name + '.';
                            return;
                        }

                        if (!(productId > 0)) {
                            validationError =
                                'Select the bill product against which the grams should be claimed.';
                            return;
                        }

                        const product = products.find(x => Number(x.id) === productId);

                        if (!product) {
                            validationError = 'Selected claim product is invalid.';
                            return;
                        }

                        const rate = Number(
                            product.live_metal_rate || product.sale_rate || 0
                        );

                        if (!(rate > 0)) {
                            validationError =
                                'Selected claim product does not have a valid metal rate.';
                            return;
                        }

                        nextClaims.push({
                            chit_member_id: memberId,
                            chit_group_id: Number(chit.chit_group_id),
                            product_id: productId,
                            claim_grams: Number(grams.toFixed(6)),
                            rate_per_gram: Number(rate.toFixed(2)),
                            claim_amount: Number((grams * rate).toFixed(2))
                        });
                    });

                    if (validationError) {
                        toast('error', validationError);
                        return;
                    }

                    appliedClaims = nextClaims;
                    calc();
                    hideClaimModal();

                    const totalGrams = appliedClaims.reduce(
                        (sum, claim) => sum + Number(claim.claim_grams || 0),
                        0
                    );

                    toast(
                        'success',
                        totalGrams.toFixed(6) +
                        ' g applied. Unclaimed grams remain available for future bills.'
                    );
                });

                claimModalElement.querySelectorAll('[data-bs-dismiss="modal"]').forEach(button => {
                    button.addEventListener('click', hideClaimModal);
                });

                document.addEventListener('click', event => {
                    const button = event.target.closest('.remove-row');
                    if (!button) return;
                    const row = button.closest('tr');
                    if (select2Ready && window.jQuery) {
                        window.jQuery(row).find('select').each(function () {
                            const $select = window.jQuery(this);
                            if ($select.data('select2')) $select.select2('destroy');
                        });
                    }
                    row.remove();
                    calc();
                });

                document.addEventListener('change', event => {
                    if (!event.target.matches('.product-select')) return;
                    const row = event.target.closest('tr');
                    const product = products.find(x => String(x.id) === event.target.value);
                    if (product) fillProduct(row, product); else calc();
                });

                document.addEventListener('input', event => {
                    if (event.target.closest('#billingForm')) calc();
                });

                form.addEventListener('submit', async event => {
                    event.preventDefault();
                    if (!customer.value) {
                        toast('error', 'Please select a customer.');
                        return;
                    }
                    if (!items.querySelector('.item-row')) {
                        toast('error', 'Add at least one product.');
                        return;
                    }

                    const button = document.getElementById('saveBtn');
                    const old = button.innerHTML;
                    button.disabled = true;
                    button.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Saving...';
                    try {
                        const res = await fetch('api/billing-save.php', {
                            method: 'POST', body: new FormData(form), credentials: 'same-origin',
                            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                        });
                        const raw = await res.text();
                        let data;
                        try { data = JSON.parse(raw); }
                        catch (_) { throw new Error('Billing API returned an invalid response.'); }
                        if (!res.ok || !data.success) throw new Error(data.message || 'Unable to save bill.');
                        toast('success', data.message);
                        setTimeout(() => {
                            if (data.document_type === 'Estimate') {
                                location.href = 'estimates-list.php?msg=created&estimate_id=' + encodeURIComponent(data.estimate_id);
                            } else {
                                location.href = 'sales-list.php?msg=created&sale_id=' + encodeURIComponent(data.sale_id);
                            }
                        }, 700);
                    } catch (error) {
                        toast('error', error.message);
                    } finally {
                        button.disabled = false;
                        button.innerHTML = old;
                    }
                });

                addItem();
                addPay();
                calc();
                refreshDocumentNumber();
                scan.focus();

                ensureSelect2().then(ready => {
                    select2Ready = ready;
                    if (!ready) {
                        toast('error', 'Select2 could not load. Native dropdowns are active and all buttons will still work.');
                        return;
                    }
                    initSelect2('.select2-customer', 'Select customer');
                    initSelect2('.select2-static', 'Select');
                    bindDocumentModeControls();
                    items.querySelectorAll('.item-row').forEach(enhanceRow);
                    pays.querySelectorAll('.payment-row').forEach(enhanceRow);
                    window.jQuery(customer).on('select2:select select2:clear', function () {
                        customer.dispatchEvent(new Event('change', { bubbles: true }));
                    });
                });
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', startBillingPage, { once: true });
            } else {
                startBillingPage();
            }
        })();
    </script>
</body>

</html>