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

$pageTitle = 'Add Customer';
$page_title = 'Add Customer';
$currentPage = 'customer-add';

$userId = (int)($_SESSION['user_id'] ?? 0);
$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? $_SESSION['default_branch_id'] ?? 0);

if ($userId <= 0) {
    header('Location: login.php');
    exit;
}

if ($businessId <= 0) {
    die('Business session not found. Please login again.');
}

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

if (!function_exists('getClientIp')) {
    function getClientIp(): string {
        return $_SERVER['HTTP_CLIENT_IP']
            ?? $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['REMOTE_ADDR']
            ?? '';
    }
}

if (!function_exists('logAudit')) {
    function logAudit(
        mysqli $conn,
        int $businessId,
        int $userId,
        string $moduleName,
        string $actionType,
        int $referenceId,
        string $description,
        ?string $customerType = null
    ): void {
        if (!tableExists($conn, 'audit_logs')) {
            return;
        }

        $ipAddress = getClientIp();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $sql = "INSERT INTO audit_logs
                (business_id, user_id, module_name, action_type, reference_id, customer_type, description, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param(
                'iississss',
                $businessId,
                $userId,
                $moduleName,
                $actionType,
                $referenceId,
                $customerType,
                $description,
                $ipAddress,
                $userAgent
            );
            $stmt->execute();
            $stmt->close();
        }
    }
}

/*
|--------------------------------------------------------------------------
| Customer Type From URL
|--------------------------------------------------------------------------
| URL type:
| billing => Billing
| pawn    => PawnBroking
| chit    => PawnChits
*/
$customerType = $_GET['type'] ?? ($_POST['page_customer_type'] ?? 'billing');
$validTypes = ['billing', 'pawn', 'chit'];

if (!in_array($customerType, $validTypes, true)) {
    $customerType = 'billing';
}

function mapCustomerTypeToDb(string $type): string {
    switch ($type) {
        case 'pawn':
            return 'PawnBroking';
        case 'chit':
            return 'PawnChits';
        case 'billing':
        default:
            return 'Billing';
    }
}

function getCustomerTypeLabel(string $type): string {
    switch ($type) {
        case 'pawn':
            return 'Pawn';
        case 'chit':
            return 'Chit';
        case 'billing':
        default:
            return 'Billing';
    }
}

function generateCustomerCode(mysqli $conn, int $businessId, string $type): string {
    switch ($type) {
        case 'pawn':
            $prefix = 'PWN';
            break;
        case 'chit':
            $prefix = 'CHC';
            break;
        case 'billing':
        default:
            $prefix = 'CUST';
            break;
    }

    $yearMonth = date('ym');
    $like = $prefix . $yearMonth . '%';

    $sql = "SELECT customer_code 
            FROM customers 
            WHERE business_id = ? 
              AND customer_code LIKE ? 
            ORDER BY id DESC 
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    $lastNumber = 0;

    if ($stmt) {
        $stmt->bind_param('is', $businessId, $like);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res && ($row = $res->fetch_assoc())) {
            $lastNumber = (int)substr((string)$row['customer_code'], -4);
        }

        $stmt->close();
    }

    return $prefix . $yearMonth . str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
}

/*
|--------------------------------------------------------------------------
| Optional Dropdown Data
|--------------------------------------------------------------------------
| These tables may not exist in your uploaded DB.
| So safely load them only if available.
*/
$zones = [];
if (tableExists($conn, 'zones')) {
    $zoneQuery = "SELECT id, zone_name FROM zones WHERE status = 'active' ORDER BY zone_name";
    $zoneRes = $conn->query($zoneQuery);
    if ($zoneRes) {
        while ($row = $zoneRes->fetch_assoc()) {
            $zones[] = $row;
        }
    }
}

$linemen = [];
if (tableExists($conn, 'linemen')) {
    $linemanQuery = "SELECT id, full_name, assigned_area FROM linemen WHERE status = 'active' ORDER BY full_name";
    $linemanRes = $conn->query($linemanQuery);
    if ($linemanRes) {
        while ($row = $linemanRes->fetch_assoc()) {
            $linemen[] = $row;
        }
    }
}

/*
|--------------------------------------------------------------------------
| Form Submit
|--------------------------------------------------------------------------
*/
$message = '';
$messageType = '';

$flash = $_SESSION['customer_form_flash'] ?? [];
unset($_SESSION['customer_form_flash']);
if (!empty($flash)) {
    $message = (string)($flash['message'] ?? '');
    $messageType = (string)($flash['type'] ?? 'danger');
}
$oldInput = $_SESSION['customer_form_old'] ?? [];
unset($_SESSION['customer_form_old']);

if (empty($_SESSION['customers_csrf'])) {
    $_SESSION['customers_csrf'] = bin2hex(random_bytes(32));
}
$customersCsrf = $_SESSION['customers_csrf'];

/*
|--------------------------------------------------------------------------
| Previous Form Values
|--------------------------------------------------------------------------
*/
$postShopName          = $oldInput['shop_name'] ?? '';
$postCustomerName      = $oldInput['customer_name'] ?? '';
$postCustomerContact   = $oldInput['customer_contact'] ?? '';
$postAlternateContact  = $oldInput['alternate_contact'] ?? '';
$postShopLocation      = $oldInput['shop_location'] ?? '';
$postGstNumber         = $oldInput['gst_number'] ?? '';
$postEmail             = $oldInput['email'] ?? '';
$postCustomerCategory  = $oldInput['customer_category'] ?? 'retail';
$postAssignedLinemanId = (int)($oldInput['assigned_lineman_id'] ?? 0);
$postAssignedArea      = $oldInput['assigned_area'] ?? '';
$postPaymentTerms      = $oldInput['payment_terms'] ?? 'cash';
$postCreditLimit       = $oldInput['credit_limit'] ?? '';
$postNotes             = $oldInput['notes'] ?? '';
$postZoneId            = (int)($oldInput['zone_id'] ?? 0);

$theme = [
    'primary_color' => '#d89416',
    'primary_dark_color' => '#b86a0b',
    'primary_soft_color' => '#fff6e5',
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
    $themeStmt = $conn->prepare('SELECT * FROM business_theme_settings WHERE business_id = ? LIMIT 1');
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
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo h($businessName); ?> - Add Customer</title>
    <?php include('includes/links.php'); ?>

<style>
    html, body { min-height: 100%; }

    .form-section {
        background: #fff;
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 25px;
        border: 1px solid #eef0f4;
    }
    .form-section h5 {
        margin-bottom: 18px;
        padding-bottom: 10px;
        border-bottom: 2px solid #eef0f4;
        font-size: 16px;
        font-weight: 600;
    }
    .type-badge {
        display: inline-block;
        padding: 8px 16px;
        border-radius: 8px;
        margin-bottom: 20px;
    }
    .type-billing { background: #e3f2fd; color: #1976d2; }
    .type-pawn { background: #fff3e0; color: #f57c00; }
    .type-chit { background: #e8f5e9; color: #388e3c; }

    @media (max-width: 767.98px) {
        .form-section {
            padding: 16px;
            margin-bottom: 18px;
        }
        .page-content {
            padding-left: 0;
            padding-right: 0;
        }
        .btn {
            width: 100%;
            margin-bottom: 8px;
        }
        .text-end .btn {
            width: 100%;
        }
    }
</style>


<style id="purchase-ui-theme">
:root{
    --primary:<?php echo h($theme['primary_color']); ?>;
    --primary-dark:<?php echo h($theme['primary_dark_color']); ?>;
    --primary-soft:<?php echo h($theme['primary_soft_color']); ?>;
    --page-bg:<?php echo h($theme['page_background']); ?>;
    --card-bg:<?php echo h($theme['card_background']); ?>;
    --text-color:<?php echo h($theme['text_color']); ?>;
    --muted-color:<?php echo h($theme['muted_text_color']); ?>;
    --border-color:<?php echo h($theme['border_color']); ?>;
    --radius:<?php echo max(0, (int)$theme['border_radius_px']); ?>px;
    --sidebar-width:<?php echo max(180, (int)($theme['sidebar_width_px'] ?? 230)); ?>px;
}
html,body{min-height:100%}
body{margin:0;background:var(--page-bg)!important;color:var(--text-color);font-family:<?php echo h($theme['font_family']); ?>,system-ui,-apple-system,"Segoe UI",sans-serif}
.app-main{min-height:100vh;margin-left:var(--sidebar-width)!important;width:calc(100% - var(--sidebar-width));background:var(--page-bg);transition:margin-left .2s ease,width .2s ease;position:relative}
.content-wrap{padding:18px}
body.sidebar-collapsed .app-main{margin-left:72px!important;width:calc(100% - 72px)}
@media(max-width:991px){.app-main{margin-left:0!important;width:100%}.content-wrap{padding:12px}}
.container-fluid{max-width:100%;padding-left:18px;padding-right:18px}

/* Purchase-style page heading */
.row.mb-3>.col-12,
.row.mb-3>.col-12.d-flex,
.container-fluid>.row.mb-3:first-child>.col-12{
    background:var(--card-bg);border:1px solid var(--border-color);border-radius:var(--radius);padding:12px 14px!important;
}
h4,.card-title,.card-header h5,.form-section h5{font-family:<?php echo h($theme['heading_font_family']); ?>,Georgia,serif;font-weight:800;color:var(--text-color)}
h4{font-size:18px!important;margin:0}.text-muted{color:var(--muted-color)!important;font-size:10px}

/* Cards and sections */
.card,.form-section,.type-badge{
    background:var(--card-bg)!important;border:1px solid var(--border-color)!important;border-radius:var(--radius)!important;box-shadow:none!important;
}
.card{overflow:hidden;margin-bottom:12px}.card-header{background:var(--card-bg)!important;border-bottom:1px solid var(--border-color)!important;padding:12px 14px}.card-body{padding:14px}
.form-section{padding:0!important;margin-bottom:12px!important;overflow:hidden}
.form-section>h5{font-size:14px!important;margin:0!important;padding:12px 14px;border-bottom:1px solid var(--border-color);background:var(--card-bg)}
.form-section>.row,.form-section>div:not(h5){padding:14px}
.type-badge{display:inline-flex!important;align-items:center;gap:5px;padding:7px 10px!important;margin-bottom:12px!important;background:var(--primary-soft)!important;color:var(--primary-dark)!important;font-size:10px!important;border-color:color-mix(in srgb,var(--primary) 25%,var(--border-color))!important}

/* Forms */
.form-label{margin-bottom:5px;font-size:10px;font-weight:700;color:var(--text-color)}
.form-control,.form-select,.input-group-text{
    min-height:38px;border-color:var(--border-color)!important;border-radius:9px;background:var(--card-bg)!important;color:var(--text-color)!important;font-size:11px;box-shadow:none!important;
}
textarea.form-control{min-height:auto}.form-control:focus,.form-select:focus{border-color:var(--primary)!important;box-shadow:0 0 0 .2rem rgba(216,148,22,.13)!important}
.input-group>.form-control:not(:last-child),.input-group>.form-select:not(:last-child){border-top-right-radius:0;border-bottom-right-radius:0}.input-group-text{border-radius:9px}

/* Buttons */
.btn{border-radius:9px!important;font-size:11px!important;font-weight:700!important;min-height:36px;padding:8px 13px}
.btn-primary,.btn-success{background:linear-gradient(135deg,var(--primary),var(--primary-dark))!important;border-color:transparent!important;color:#fff!important}
.btn-info{background:var(--primary-soft)!important;color:var(--primary-dark)!important;border:1px solid color-mix(in srgb,var(--primary) 25%,var(--border-color))!important}
.btn-secondary,.btn-outline-secondary,.btn-light{background:var(--card-bg)!important;color:var(--text-color)!important;border:1px solid var(--border-color)!important}
.btn-danger,.btn-outline-danger{border-radius:8px!important}
.btn-sm{min-height:30px;padding:6px 9px;font-size:9px!important}

/* Statistics */
.row>.col-md-3>.card.text-center,.row>.col-md-4>.card.text-center,.row>.col-md-6>.card.text-center,
.row>.col-xl-4>.card.text-center,.row>.col-xl-6>.card.text-center{
    min-height:82px;text-align:left!important
}
.card.text-center .card-body{position:relative;padding:13px 14px 13px 66px!important;display:flex;flex-direction:column;justify-content:center;min-height:82px}
.card.text-center .card-body:before{content:"";position:absolute;left:14px;top:50%;transform:translateY(-50%);width:42px;height:42px;border-radius:9px;background:var(--primary-soft)}
.card.text-center h3,.card.text-center h5{font-family:Inter,sans-serif!important;font-size:20px!important;line-height:1.1;font-weight:800!important;color:var(--text-color)!important;margin:0 0 4px!important}
.card.text-center p{font-size:10px!important;color:var(--muted-color)!important}

/* Tables */
.table-responsive{border-radius:var(--radius);overflow:auto}.table{margin:0!important;font-size:10px;color:var(--text-color)}
.table thead th,.table th{padding:10px 11px!important;background:#f8f8f8!important;color:var(--muted-color)!important;border-color:var(--border-color)!important;font-size:9px!important;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap}
.table td{padding:10px 11px!important;vertical-align:middle!important;background:var(--card-bg)!important;color:var(--text-color)!important;border-color:var(--border-color)!important}
.table-striped>tbody>tr:nth-of-type(odd)>*{--bs-table-accent-bg:transparent!important}.table-hover tbody tr:hover td{background:var(--primary-soft)!important}

/* Alerts, badges, pagination */
.alert{border:0!important;border-radius:10px!important;font-size:11px}.badge{font-size:9px!important;border-radius:999px!important;padding:5px 8px!important}.pagination .page-link{border-color:var(--border-color);color:var(--text-color);font-size:10px}.pagination .active .page-link{background:var(--primary);border-color:var(--primary)}

/* Filter cards resemble purchase toolbar */
.card .row.g-2.align-items-end,.card .row.g-3{row-gap:10px!important}

@media(max-width:991px){.page-content{padding-top:76px}.container-fluid{padding-left:12px;padding-right:12px}}
@media(max-width:767px){.card.text-center .card-body{padding-left:60px!important}.row.mb-3>.col-12{padding:11px 12px!important}.form-section>.row{padding:12px}.btn{width:auto}.table{min-width:780px}}
@media print{.vertical-menu,.navbar-header,.footer,.btn,.page-title-box,.no-print{display:none!important}.main-content{margin-left:0!important}.page-content{padding:0!important}.card,.form-section{border:1px solid #ddd!important}}
</style>

</head>
<body>
<?php include('includes/sidebar.php'); ?>
<main class="app-main">
    <?php include('includes/nav.php'); ?>
    <div class="content-wrap">
        <div class="container-fluid">

                

                <?php if ($message): ?>
                    <div class="alert alert-<?php echo h($messageType); ?> alert-dismissible fade show" role="alert">
                        <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <div class="type-badge type-<?php echo h($customerType); ?>">
                    <strong>Customer Type:</strong> <?php echo h(getCustomerTypeLabel($customerType)); ?> Customer
                </div>

                <form method="POST" action="api/customers-save.php" id="customerForm">
                    <input type="hidden" name="action" value="create">
                    <input type="hidden" name="csrf_token" value="<?php echo h($customersCsrf); ?>">
                    <input type="hidden" name="page_customer_type" value="<?php echo h($customerType); ?>">

                    <!-- Basic Information -->
                    <div class="form-section">
                        <h5><i class="fas fa-user-circle me-2"></i> Basic Information</h5>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Customer Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="customer_name" value="<?php echo h($postCustomerName); ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Shop/Business Name</label>
                                <input type="text" class="form-control" name="shop_name" value="<?php echo h($postShopName); ?>">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Contact Number <span class="text-danger">*</span></label>
                                <input type="tel" class="form-control" name="customer_contact" value="<?php echo h($postCustomerContact); ?>" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Alternate Number</label>
                                <input type="tel" class="form-control" name="alternate_contact" value="<?php echo h($postAlternateContact); ?>">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Email Address</label>
                                <input type="email" class="form-control" name="email" value="<?php echo h($postEmail); ?>">
                            </div>
                            <div class="col-12 mb-3">
                                <label class="form-label">Address/Location <span class="text-danger">*</span></label>
                                <textarea class="form-control" name="shop_location" rows="2" required><?php echo h($postShopLocation); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Business Details -->
                    <div class="form-section">
                        <h5><i class="fas fa-building me-2"></i> Business Details</h5>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">GST Number</label>
                                <input type="text" class="form-control" name="gst_number" value="<?php echo h($postGstNumber); ?>" placeholder="22AAAAA0000A1Z">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Customer Type</label>
                                <select class="form-select" name="customer_category">
                                    <option value="retail" <?php echo $postCustomerCategory === 'retail' ? 'selected' : ''; ?>>Retail</option>
                                    <option value="wholesale" <?php echo $postCustomerCategory === 'wholesale' ? 'selected' : ''; ?>>Wholesale</option>
                                    <option value="hotel" <?php echo $postCustomerCategory === 'hotel' ? 'selected' : ''; ?>>Hotel/Restaurant</option>
                                    <option value="office" <?php echo $postCustomerCategory === 'office' ? 'selected' : ''; ?>>Office/Corporate</option>
                                    <option value="residential" <?php echo $postCustomerCategory === 'residential' ? 'selected' : ''; ?>>Residential</option>
                                    <option value="other" <?php echo $postCustomerCategory === 'other' ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Zone & Assignment -->
                    <div class="form-section">
                        <h5><i class="fas fa-map-marker-alt me-2"></i> Zone & Assignment</h5>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Zone</label>
                                <select class="form-select" name="zone_id">
                                    <option value="">-- Select Zone --</option>
                                    <?php foreach ($zones as $zone): ?>
                                        <option value="<?php echo (int)$zone['id']; ?>" <?php echo $postZoneId === (int)$zone['id'] ? 'selected' : ''; ?>>
                                            <?php echo h($zone['zone_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Assigned Area</label>
                                <input type="text" class="form-control" name="assigned_area" value="<?php echo h($postAssignedArea); ?>" placeholder="e.g., Central Zone, North Area">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Assigned Lineman</label>
                                <select class="form-select" name="assigned_lineman_id">
                                    <option value="">-- None --</option>
                                    <?php foreach ($linemen as $lineman): ?>
                                        <option value="<?php echo (int)$lineman['id']; ?>" <?php echo $postAssignedLinemanId === (int)$lineman['id'] ? 'selected' : ''; ?>>
                                            <?php
                                            echo h($lineman['full_name']);
                                            if (!empty($lineman['assigned_area'])) {
                                                echo ' (' . h($lineman['assigned_area']) . ')';
                                            }
                                            ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Payment & Credit Terms -->
                    <div class="form-section">
                        <h5><i class="fas fa-credit-card me-2"></i> Payment & Credit Terms</h5>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Payment Terms</label>
                                <select class="form-select" name="payment_terms">
                                    <option value="cash" <?php echo $postPaymentTerms === 'cash' ? 'selected' : ''; ?>>Cash (No Credit)</option>
                                    <option value="credit_7" <?php echo $postPaymentTerms === 'credit_7' ? 'selected' : ''; ?>>7 Days Credit</option>
                                    <option value="credit_15" <?php echo $postPaymentTerms === 'credit_15' ? 'selected' : ''; ?>>15 Days Credit</option>
                                    <option value="credit_30" <?php echo $postPaymentTerms === 'credit_30' ? 'selected' : ''; ?>>30 Days Credit</option>
                                    <option value="prepaid" <?php echo $postPaymentTerms === 'prepaid' ? 'selected' : ''; ?>>Prepaid</option>
                                    <option value="weekly" <?php echo $postPaymentTerms === 'weekly' ? 'selected' : ''; ?>>Weekly Payment</option>
                                    <option value="monthly" <?php echo $postPaymentTerms === 'monthly' ? 'selected' : ''; ?>>Monthly Payment</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Credit Limit (₹)</label>
                                <input type="number" step="0.01" class="form-control" name="credit_limit" value="<?php echo h($postCreditLimit); ?>" placeholder="0.00">
                                <small class="text-muted">Leave 0 for no credit limit</small>
                            </div>
                        </div>
                    </div>

                    <!-- Additional Information -->
                    <div class="form-section">
                        <h5><i class="fas fa-sticky-note me-2"></i> Additional Information</h5>
                        <div class="row">
                            <div class="col-12 mb-3">
                                <label class="form-label">Notes/Comments</label>
                                <textarea class="form-control" name="notes" rows="3"><?php echo h($postNotes); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Submit Button -->
                    <div class="text-end mb-4">
                        <button type="reset" class="btn btn-outline-secondary me-2">Reset</button>
                        <button type="submit" class="btn btn-primary">Add Customer</button>
                    </div>
                </form>

        </div>
        <?php include('includes/footer.php'); ?>
    </div>
</main>

<?php include('includes/script.php'); ?>
<script src="assets/js/script.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const zoneSelect = document.querySelector('select[name="zone_id"]');
    const areaInput = document.querySelector('input[name="assigned_area"]');

    if (zoneSelect && areaInput) {
        zoneSelect.addEventListener('change', function() {
            if (this.value && !areaInput.value) {
                const selectedOption = this.options[this.selectedIndex];
                areaInput.value = selectedOption.text;
            }
        });
    }

    const form = document.getElementById('customerForm');

    if (form) {
        form.addEventListener('submit', function(e) {
            const customerName = document.querySelector('input[name="customer_name"]').value.trim();
            const contact = document.querySelector('input[name="customer_contact"]').value.trim();
            const address = document.querySelector('textarea[name="shop_location"]').value.trim();

            if (!customerName) {
                e.preventDefault();
                alert('Please enter customer name');
                return false;
            }

            if (!contact) {
                e.preventDefault();
                alert('Please enter contact number');
                return false;
            }

            if (!address) {
                e.preventDefault();
                alert('Please enter address/location');
                return false;
            }

            return true;
        });
    }
});
</script>

</body>
</html>