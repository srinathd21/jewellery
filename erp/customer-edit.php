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
function tableExists(mysqli $conn, string $table): bool
{
    $safe = $conn->real_escape_string($table);
    $r = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $r && $r->num_rows > 0;
}
function customerPermission(mysqli $conn, string $action): bool
{
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin')
        return true;
    $map = ['open' => 'can_open', 'create' => 'can_create', 'update' => 'can_update'];
    $field = $map[$action] ?? '';
    if ($field === '')
        return false;
    foreach (['perm.customer.add', 'perm.customers', 'perm.customer'] as $code) {
        if (isset($_SESSION['permissions'][$code][$field]))
            return (int) $_SESSION['permissions'][$code][$field] === 1;
    }
    $businessId = (int) ($_SESSION['business_id'] ?? 0);
    $roleId = (int) ($_SESSION['role_id'] ?? 0);
    if ($businessId <= 0 || $roleId <= 0)
        return false;
    $sql = "SELECT rp.`{$field}` FROM role_permissions rp INNER JOIN permissions p ON p.id=rp.permission_id WHERE rp.business_id=? AND rp.role_id=? AND p.is_active=1 AND p.permission_code IN ('perm.customer.add','perm.customers','perm.customer') ORDER BY FIELD(p.permission_code,'perm.customer.add','perm.customers','perm.customer') LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt)
        return false;
    $stmt->bind_param('ii', $businessId, $roleId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row[$field] ?? 0) === 1;
}
if (!customerPermission($conn, 'open') || !customerPermission($conn, 'update')) {
    http_response_code(403);
    die('You do not have permission to edit customers.');
}

$businessId = (int) ($_SESSION['business_id'] ?? 0);
$branchId = (int) ($_SESSION['branch_id'] ?? ($_SESSION['default_branch_id'] ?? 0));
if ($businessId <= 0 || $branchId <= 0)
    die('A valid business and branch must be selected.');
if (!tableExists($conn, 'customer_services'))
    die('customer_services table is missing. Import the supplied SQL migration first.');
if (empty($_SESSION['customers_csrf']))
    $_SESSION['customers_csrf'] = bin2hex(random_bytes(32));
$csrfToken = (string) $_SESSION['customers_csrf'];

$customerId = (int) ($_GET['id'] ?? $_POST['customer_id'] ?? 0);
if ($customerId <= 0) {
    http_response_code(400);
    die('Invalid customer ID.');
}

$flash = $_SESSION['customer_form_flash'] ?? [];
unset($_SESSION['customer_form_flash']);
$formOld = $_SESSION['customer_form_old'] ?? [];
unset($_SESSION['customer_form_old']);

$stmt = $conn->prepare('SELECT * FROM customers WHERE id=? AND business_id=? LIMIT 1');
if (!$stmt)
    die('Unable to load customer.');
$stmt->bind_param('ii', $customerId, $businessId);
$stmt->execute();
$customer = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$customer) {
    http_response_code(404);
    die('Customer not found or access denied.');
}

$selectedServices = [];
$stmt = $conn->prepare('SELECT service_type FROM customer_services WHERE business_id=? AND customer_id=? AND is_active=1');
if ($stmt) {
    $stmt->bind_param('ii', $businessId, $customerId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc())
        $selectedServices[] = (string) $row['service_type'];
    $stmt->close();
}
if (!$selectedServices)
    $selectedServices = ['Billing'];

$pawn = [];
if (tableExists($conn, 'pawn_customers')) {
    $stmt = $conn->prepare('SELECT * FROM pawn_customers WHERE business_id=? AND customer_id=? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('ii', $businessId, $customerId);
        $stmt->execute();
        $pawn = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
    }
}

$paymentTerms = 'Cash';
if (preg_match('/(?:^|\R)Payment Terms:\s*([^\r\n]+)/i', (string) ($customer['notes'] ?? ''), $m))
    $paymentTerms = trim($m[1]);

$old = array_merge($customer, [
    'services' => $selectedServices,
    'payment_terms' => $paymentTerms,
    'guardian_name' => $pawn['guardian_name'] ?? '',
    'occupation' => $pawn['occupation'] ?? '',
    'annual_income' => $pawn['annual_income'] ?? '0',
    'reference_name' => $pawn['reference_name'] ?? '',
    'reference_mobile' => $pawn['reference_mobile'] ?? '',
    'risk_category' => $pawn['risk_category'] ?? 'Low',
    'pawn_credit_limit' => $pawn['credit_limit'] ?? '0',
    'kyc_verified' => $pawn['kyc_verified'] ?? 0,
]);
if ($formOld)
    $old = array_merge($old, $formOld);
$selectedServices = $old['services'] ?? $selectedServices;
if (!is_array($selectedServices))
    $selectedServices = [$selectedServices];

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
    'sidebar_width_px' => 230
];
$stmt = $conn->prepare('SELECT * FROM business_theme_settings WHERE business_id=? LIMIT 1');
if ($stmt) {
    $stmt->bind_param('i', $businessId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    foreach ($theme as $k => $v)
        if (isset($row[$k]) && $row[$k] !== '')
            $theme[$k] = $row[$k];
}
$pageTitle = 'Edit Customer';
$businessName = (string) ($_SESSION['business_name'] ?? 'Jewellery ERP');
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($businessName) ?> - Edit Customer</title>
    <?php include('includes/links.php'); ?>
    <style>
        :root {
            --primary: <?= e($theme['primary_color']) ?>;
            --primary-dark: <?= e($theme['primary_dark_color']) ?>;
            --primary-soft: <?= e($theme['primary_soft_color']) ?>;
            --sidebar-gradient-1: <?= e($theme['sidebar_gradient_1']) ?>;
            --sidebar-gradient-2: <?= e($theme['sidebar_gradient_2']) ?>;
            --sidebar-gradient-3: <?= e($theme['sidebar_gradient_3']) ?>;
            --page-bg: <?= e($theme['page_background']) ?>;
            --card-bg: <?= e($theme['card_background']) ?>;
            --text: <?= e($theme['text_color']) ?>;
            --muted: <?= e($theme['muted_text_color']) ?>;
            --line: <?= e($theme['border_color']) ?>;
            --radius: <?= (int) $theme['border_radius_px'] ?>px;
            --sidebar-width: <?= (int) $theme['sidebar_width_px'] ?>px
        }

        body {
            background: var(--page-bg);
            color: var(--text);
            font-family: <?= json_encode($theme['font_family']) ?>, sans-serif
        }

        .sidebar {
            background: linear-gradient(180deg, var(--sidebar-gradient-1), var(--sidebar-gradient-2), var(--sidebar-gradient-3)) !important
        }

        .page-head,
        .form-card,
        .service-card {
            background: var(--card-bg);
            border: 1px solid var(--line);
            border-radius: var(--radius)
        }

        .page-head {
            padding: 13px 15px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px
        }

        .page-title {
            font: 800 18px
                <?= json_encode($theme['heading_font_family']) ?>
                , serif
        }

        .page-sub {
            font-size: 10px;
            color: var(--muted);
            margin-top: 2px
        }

        .form-card {
            overflow: hidden;
            margin-bottom: 10px
        }

        .form-card-head {
            padding: 11px 14px;
            border-bottom: 1px solid var(--line);
            font-size: 12px;
            font-weight: 800;
            font-family: <?= json_encode($theme['heading_font_family']) ?>, serif
        }

        .form-card-body {
            padding: 14px
        }

        .field-label {
            display: block;
            font-size: 10px;
            font-weight: 700;
            margin-bottom: 5px
        }

        .form-control,
        .form-select {
            min-height: 38px;
            border-color: var(--line);
            border-radius: 9px;
            background: var(--card-bg);
            color: var(--text);
            font-size: 11px;
            box-shadow: none
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 .2rem color-mix(in srgb, var(--primary) 12%, transparent)
        }

        .btn-theme {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border: 0;
            color: #fff;
            border-radius: 9px;
            font-size: 11px;
            font-weight: 700;
            padding: 9px 15px
        }

        .btn-soft {
            background: var(--primary-soft);
            border: 1px solid color-mix(in srgb, var(--primary) 30%, var(--line));
            color: var(--primary-dark);
            border-radius: 9px;
            font-size: 11px;
            font-weight: 700;
            padding: 9px 15px
        }

        .service-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px
        }

        .service-card {
            position: relative;
            padding: 13px;
            cursor: pointer;
            transition: .18s
        }

        .service-card:hover {
            border-color: var(--primary)
        }

        .service-card.selected {
            background: var(--primary-soft);
            border-color: var(--primary)
        }

        .service-card input {
            position: absolute;
            right: 12px;
            top: 12px
        }

        .service-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            display: grid;
            place-items: center;
            background: var(--primary-soft);
            color: var(--primary-dark);
            margin-bottom: 9px
        }

        .service-title {
            font-size: 12px;
            font-weight: 800
        }

        .service-text {
            font-size: 9px;
            color: var(--muted);
            margin-top: 3px
        }

        .conditional-section {
            display: none
        }

        .conditional-section.show {
            display: block
        }

        .theme-toast {
            position: fixed;
            right: 18px;
            top: 78px;
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

        body.dark-mode,
        body[data-theme="dark"],
        html.dark-mode body,
        html[data-theme="dark"] body {
            --page-bg: #0f151b;
            --card-bg: #182129;
            --text: #f3f6f8;
            --muted: #9aa7b3;
            --line: #2c3944;
            --primary-soft: #2b2414
        }

        body.dark-mode .page-head,
        body.dark-mode .form-card,
        body.dark-mode .service-card,
        body[data-theme="dark"] .page-head,
        body[data-theme="dark"] .form-card,
        body[data-theme="dark"] .service-card,
        html.dark-mode body .page-head,
        html.dark-mode body .form-card,
        html.dark-mode body .service-card,
        html[data-theme="dark"] body .page-head,
        html[data-theme="dark"] body .form-card,
        html[data-theme="dark"] body .service-card {
            background: #182129 !important;
            border-color: #2c3944 !important;
            color: #f3f6f8 !important
        }

        body.dark-mode .service-card.selected,
        body[data-theme="dark"] .service-card.selected,
        html.dark-mode body .service-card.selected,
        html[data-theme="dark"] body .service-card.selected {
            background: linear-gradient(135deg, rgba(216, 148, 22, .20), rgba(184, 106, 11, .10)) !important;
            border-color: var(--primary) !important;
            box-shadow: inset 0 0 0 1px rgba(216, 148, 22, .16)
        }

        body.dark-mode .service-card.selected .service-title,
        body[data-theme="dark"] .service-card.selected .service-title,
        html.dark-mode body .service-card.selected .service-title,
        html[data-theme="dark"] body .service-card.selected .service-title {
            color: #fff !important
        }

        body.dark-mode .service-icon,
        body[data-theme="dark"] .service-icon,
        html.dark-mode body .service-icon,
        html[data-theme="dark"] body .service-icon {
            background: #2b2414 !important;
            color: var(--primary) !important
        }

        body.dark-mode .service-text,
        body.dark-mode .page-sub,
        body.dark-mode .text-muted,
        body[data-theme="dark"] .service-text,
        body[data-theme="dark"] .page-sub,
        body[data-theme="dark"] .text-muted,
        html.dark-mode body .service-text,
        html.dark-mode body .page-sub,
        html.dark-mode body .text-muted,
        html[data-theme="dark"] body .service-text,
        html[data-theme="dark"] body .page-sub,
        html[data-theme="dark"] body .text-muted {
            color: #9aa7b3 !important
        }

        body.dark-mode .form-control,
        body.dark-mode .form-select,
        body[data-theme="dark"] .form-control,
        body[data-theme="dark"] .form-select,
        html.dark-mode body .form-control,
        html.dark-mode body .form-select,
        html[data-theme="dark"] body .form-control,
        html[data-theme="dark"] body .form-select {
            background: #131c24 !important;
            color: #f3f6f8 !important;
            border-color: #32414d !important;
            color-scheme: dark
        }

        body.dark-mode .form-control::placeholder,
        body[data-theme="dark"] .form-control::placeholder,
        html.dark-mode body .form-control::placeholder,
        html[data-theme="dark"] body .form-control::placeholder {
            color: #71808d !important;
            opacity: 1
        }

        body.dark-mode .form-control:focus,
        body.dark-mode .form-select:focus,
        body[data-theme="dark"] .form-control:focus,
        body[data-theme="dark"] .form-select:focus,
        html.dark-mode body .form-control:focus,
        html.dark-mode body .form-select:focus,
        html[data-theme="dark"] body .form-control:focus,
        html[data-theme="dark"] body .form-select:focus {
            background: #131c24 !important;
            color: #fff !important;
            border-color: var(--primary) !important;
            box-shadow: 0 0 0 .2rem rgba(216, 148, 22, .14) !important
        }

        body.dark-mode .btn-soft,
        body[data-theme="dark"] .btn-soft,
        html.dark-mode body .btn-soft,
        html[data-theme="dark"] body .btn-soft {
            background: #2b2414 !important;
            color: #f0b94b !important;
            border-color: #5d4921 !important
        }

        body.dark-mode .form-check-input,
        body[data-theme="dark"] .form-check-input,
        html.dark-mode body .form-check-input,
        html[data-theme="dark"] body .form-check-input {
            background-color: #111820;
            border-color: #60707c
        }

        body.dark-mode .form-check-input:checked,
        body[data-theme="dark"] .form-check-input:checked,
        html.dark-mode body .form-check-input:checked,
        html[data-theme="dark"] body .form-check-input:checked {
            background-color: #0d6efd;
            border-color: #0d6efd
        }

        @media(max-width:767px) {
            .content-wrap {
                padding-left: 10px;
                padding-right: 10px
            }

            .service-grid {
                grid-template-columns: 1fr
            }

            .page-head {
                align-items: stretch;
                flex-direction: column
            }

            .page-head .btn-soft {
                width: 100%
            }
        }
    </style>
</head>

<body>
    <?php include('includes/sidebar.php'); ?>
    <main class="app-main"><?php include('includes/nav.php'); ?>
        <div class="content-wrap">
            <?php if (!empty($flash['message'])): ?>
                <div class="alert alert-<?= e($flash['type'] ?? 'danger') ?>"><?= e($flash['message']) ?></div><?php endif; ?>
            <div class="page-head">
                <div>
                    <div class="page-title">Edit Customer</div>
                    <div class="page-sub">Update the customer profile and manage Billing, Pawn and Chit services independently.</div>
                </div><a href="customers.php" class="btn-soft text-decoration-none"><i
                        class="fa-solid fa-arrow-left me-1"></i>Customer List</a>
            </div>
            <form method="post" action="api/customers-update.php" id="customerForm" autocomplete="off">
                <input type="hidden" name="action" value="update"><input type="hidden" name="customer_id" value="<?= (int) $customerId ?>"><input type="hidden" name="csrf_token"
                    value="<?= e($csrfToken) ?>">
                <div class="form-card">
                    <div class="form-card-head"><i class="fa-solid fa-layer-group me-2"></i>Customer Services</div>
                    <div class="form-card-body">
                        <div class="service-grid">
                            <?php $serviceInfo = ['Billing' => ['fa-receipt', 'Billing Customer', 'Sales invoices, customer payments and credit balance.'], 'Pawn' => ['fa-hand-holding-dollar', 'Pawn Customer', 'Pawn entries, KYC, valuation, interest and release.'], 'Chit' => ['fa-people-group', 'Chit Customer', 'Eligible to join one or more chit groups.']];
                            foreach ($serviceInfo as $service => $info): ?>
                                <label
                                    class="service-card <?= in_array($service, $selectedServices, true) ? 'selected' : '' ?>"><input
                                        class="form-check-input service-check" type="checkbox" name="services[]"
                                        value="<?= e($service) ?>"
                                        <?= in_array($service, $selectedServices, true) ? 'checked' : '' ?>>
                                    <div class="service-icon"><i class="fa-solid <?= e($info[0]) ?>"></i></div>
                                    <div class="service-title"><?= e($info[1]) ?></div>
                                    <div class="service-text"><?= e($info[2]) ?></div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div class="small text-muted mt-2">Select at least one service. The same customer may use all
                            three services.</div>
                    </div>
                </div>
                <div class="form-card">
                    <div class="form-card-head"><i class="fa-solid fa-user me-2"></i>Customer Information</div>
                    <div class="form-card-body">
                        <div class="row g-3">
                            <div class="col-md-4"><label class="field-label">Customer Name *</label><input
                                    name="customer_name" class="form-control" maxlength="150"
                                    value="<?= e($old['customer_name'] ?? '') ?>" required></div>
                            <div class="col-md-4"><label class="field-label">Mobile *</label><input name="mobile"
                                    class="form-control" maxlength="20"
                                    value="<?= e($old['mobile'] ?? $old['customer_contact'] ?? '') ?>" required></div>
                            <div class="col-md-4"><label class="field-label">Alternate Mobile</label><input
                                    name="alternate_mobile" class="form-control" maxlength="20"
                                    value="<?= e($old['alternate_mobile'] ?? $old['alternate_contact'] ?? '') ?>"></div>
                            <div class="col-md-4"><label class="field-label">Email</label><input type="email"
                                    name="email" class="form-control" maxlength="150"
                                    value="<?= e($old['email'] ?? '') ?>"></div>
                            <div class="col-md-4"><label class="field-label">GSTIN</label><input name="gstin"
                                    class="form-control text-uppercase" maxlength="30"
                                    value="<?= e($old['gstin'] ?? $old['gst_number'] ?? '') ?>"></div>
                            <div class="col-md-4"><label class="field-label">PAN Number</label><input name="pan_no"
                                    class="form-control text-uppercase" maxlength="20"
                                    value="<?= e($old['pan_no'] ?? '') ?>"></div>
                            <div class="col-md-4"><label class="field-label">Date of Birth</label><input type="date"
                                    name="date_of_birth" class="form-control"
                                    value="<?= e($old['date_of_birth'] ?? '') ?>"></div>
                            <div class="col-md-4"><label class="field-label">Anniversary Date</label><input type="date"
                                    name="anniversary_date" class="form-control"
                                    value="<?= e($old['anniversary_date'] ?? '') ?>"></div>
                            <div class="col-md-4"><label class="field-label">Status</label><select name="is_active"
                                    class="form-select">
                                    <option value="1" <?= !isset($old['is_active']) || (int) $old['is_active'] === 1 ? 'selected' : '' ?>>Active</option>
                                    <option value="0" <?= isset($old['is_active']) && (int) $old['is_active'] === 0 ? 'selected' : '' ?>>Inactive</option>
                                </select></div>
                        </div>
                    </div>
                </div>
                <div class="form-card">
                    <div class="form-card-head"><i class="fa-solid fa-location-dot me-2"></i>Address</div>
                    <div class="form-card-body">
                        <div class="row g-3">
                            <div class="col-md-6"><label class="field-label">Address Line 1</label><input
                                    name="address_line1" class="form-control" maxlength="255"
                                    value="<?= e($old['address_line1'] ?? $old['shop_location'] ?? '') ?>"></div>
                            <div class="col-md-6"><label class="field-label">Address Line 2</label><input
                                    name="address_line2" class="form-control" maxlength="255"
                                    value="<?= e($old['address_line2'] ?? '') ?>"></div>
                            <div class="col-md-4"><label class="field-label">City</label><input name="city"
                                    class="form-control" maxlength="100" value="<?= e($old['city'] ?? '') ?>"></div>
                            <div class="col-md-4"><label class="field-label">State</label><input name="state"
                                    class="form-control" maxlength="100" value="<?= e($old['state'] ?? 'Tamil Nadu') ?>">
                            </div>
                            <div class="col-md-4"><label class="field-label">Pincode</label><input name="pincode"
                                    class="form-control" maxlength="20" value="<?= e($old['pincode'] ?? '') ?>"></div>
                        </div>
                    </div>
                </div>
                <div class="form-card conditional-section" id="billingSection">
                    <div class="form-card-head"><i class="fa-solid fa-receipt me-2"></i>Billing Profile</div>
                    <div class="form-card-body">
                        <div class="row g-3">
                            <div class="col-md-4"><label class="field-label">Credit Limit</label><input type="number"
                                    min="0" step="0.01" name="credit_limit" class="form-control"
                                    value="<?= e($old['credit_limit'] ?? '0') ?>"></div>
                            <div class="col-md-4"><label class="field-label">Opening Balance</label><input type="number"
                                    step="0.01" name="opening_balance" class="form-control"
                                    value="<?= e($old['opening_balance'] ?? '0') ?>"></div>
                            <div class="col-md-4"><label class="field-label">Payment Terms</label><select
                                    name="payment_terms" class="form-select">
                                    <option value="Cash" <?= ($old['payment_terms'] ?? 'Cash') === 'Cash' ? 'selected' : '' ?>>Cash</option>
                                    <option value="7 Days" <?= ($old['payment_terms'] ?? '') === '7 Days' ? 'selected' : '' ?>>7 Days</option>
                                    <option value="15 Days" <?= ($old['payment_terms'] ?? '') === '15 Days' ? 'selected' : '' ?>>15 Days</option>
                                    <option value="30 Days" <?= ($old['payment_terms'] ?? '') === '30 Days' ? 'selected' : '' ?>>30 Days</option>
                                    <option value="Monthly" <?= ($old['payment_terms'] ?? '') === 'Monthly' ? 'selected' : '' ?>>Monthly</option>
                                </select></div>
                        </div>
                    </div>
                </div>
                <div class="form-card conditional-section" id="pawnSection">
                    <div class="form-card-head"><i class="fa-solid fa-hand-holding-dollar me-2"></i>Pawn Profile</div>
                    <div class="form-card-body">
                        <div class="row g-3">
                            <div class="col-md-4"><label class="field-label">Guardian Name</label><input
                                    name="guardian_name" class="form-control" maxlength="150"
                                    value="<?= e($old['guardian_name'] ?? '') ?>"></div>
                            <div class="col-md-4"><label class="field-label">Occupation</label><input name="occupation"
                                    class="form-control" maxlength="100" value="<?= e($old['occupation'] ?? '') ?>"></div>
                            <div class="col-md-4"><label class="field-label">Annual Income</label><input type="number"
                                    min="0" step="0.01" name="annual_income" class="form-control"
                                    value="<?= e($old['annual_income'] ?? '0') ?>"></div>
                            <div class="col-md-4"><label class="field-label">Reference Name</label><input
                                    name="reference_name" class="form-control" maxlength="150"
                                    value="<?= e($old['reference_name'] ?? '') ?>"></div>
                            <div class="col-md-4"><label class="field-label">Reference Mobile</label><input
                                    name="reference_mobile" class="form-control" maxlength="20"
                                    value="<?= e($old['reference_mobile'] ?? '') ?>"></div>
                            <div class="col-md-4"><label class="field-label">Risk Category</label><select
                                    name="risk_category" class="form-select">
                                    <option value="Low" <?= ($old['risk_category'] ?? 'Low') === 'Low' ? 'selected' : '' ?>>Low</option>
                                    <option value="Medium" <?= ($old['risk_category'] ?? '') === 'Medium' ? 'selected' : '' ?>>Medium</option>
                                    <option value="High" <?= ($old['risk_category'] ?? '') === 'High' ? 'selected' : '' ?>>High</option>
                                </select></div>
                            <div class="col-md-4"><label class="field-label">Pawn Credit Limit</label><input
                                    type="number" min="0" step="0.01" name="pawn_credit_limit" class="form-control"
                                    value="<?= e($old['pawn_credit_limit'] ?? '0') ?>"></div>
                            <div class="col-md-4 d-flex align-items-end">
                                <div class="form-check mb-2"><input class="form-check-input" type="checkbox"
                                        name="kyc_verified" value="1" id="kycVerified"
                                        <?= !empty($old['kyc_verified']) ? 'checked' : '' ?>><label class="form-check-label"
                                        for="kycVerified">KYC Verified</label></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="form-card conditional-section" id="chitSection">
                    <div class="form-card-head"><i class="fa-solid fa-people-group me-2"></i>Chit Eligibility</div>
                    <div class="form-card-body">
                        <div class="alert alert-info mb-0">This marks the customer as eligible for Chit. Actual
                            membership, ticket number, nominee and join date will be created when assigning the customer
                            to a chit group.</div>
                    </div>
                </div>
                <div class="form-card">
                    <div class="form-card-head"><i class="fa-solid fa-note-sticky me-2"></i>Notes</div>
                    <div class="form-card-body"><textarea name="notes" class="form-control"
                            rows="3"><?= e($old['notes'] ?? '') ?></textarea></div>
                </div>
                <div class="d-flex justify-content-end gap-2 mb-4"><button type="reset"
                        class="btn-soft">Reset</button><button type="submit" class="btn-theme" id="saveCustomer"><i
                            class="fa-solid fa-floppy-disk me-2"></i>Update Customer</button></div>
            </form>
            <?php include('includes/footer.php'); ?>
        </div>
    </main>
    <?php include('includes/script.php'); ?>
    <script src="assets/js/script.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const form = document.getElementById('customerForm'), checks = [...document.querySelectorAll('.service-check')];
            function refresh() { const selected = checks.filter(x => x.checked).map(x => x.value); document.getElementById('billingSection').classList.toggle('show', selected.includes('Billing')); document.getElementById('pawnSection').classList.toggle('show', selected.includes('Pawn')); document.getElementById('chitSection').classList.toggle('show', selected.includes('Chit')); checks.forEach(x => x.closest('.service-card').classList.toggle('selected', x.checked)); }
            checks.forEach(x => x.addEventListener('change', refresh)); refresh();
            form.addEventListener('submit', function (e) { if (!checks.some(x => x.checked)) { e.preventDefault(); alert('Select at least one service: Billing, Pawn or Chit.'); return } const btn = document.getElementById('saveCustomer'); btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Updating...'; });
        });
    </script>
</body>

</html>