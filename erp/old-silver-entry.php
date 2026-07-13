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

$pageTitle = 'Old Silver Entry';
$page_title = 'Old Silver Entry';
$currentPage = 'old-silver-entry';

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
   ROLE / PERMISSION CHECK
------------------------------------------------------- */
$roleName = strtolower(trim((string)($_SESSION['role_name'] ?? '')));
$roleCode = strtolower(trim((string)($_SESSION['role_code'] ?? '')));
$userType = (string)($_SESSION['user_type'] ?? '');
$sessionPermissions = $_SESSION['permissions'] ?? [];

$allowedRoles = ['admin', 'manager', 'staff', 'sales'];

$accessAllowed = (
    $userType === 'Platform Admin'
    || in_array($roleName, $allowedRoles, true)
    || in_array($roleCode, $allowedRoles, true)
);

foreach (['perm.stock', 'perm.inventory', 'perm.old_silver'] as $permissionCode) {
    if (
        isset($sessionPermissions[$permissionCode])
        && (
            (int)($sessionPermissions[$permissionCode]['can_open'] ?? 0) === 1
            || (int)($sessionPermissions[$permissionCode]['can_view'] ?? 0) === 1
            || (int)($sessionPermissions[$permissionCode]['can_create'] ?? 0) === 1
            || (int)($sessionPermissions[$permissionCode]['can_edit'] ?? 0) === 1
        )
    ) {
        $accessAllowed = true;
        break;
    }
}

if (!$accessAllowed) {
    http_response_code(403);
    die('Access denied.');
}

/* -------------------------------------------------------
   REQUIRED TABLES
------------------------------------------------------- */
if (!tableExists($conn, 'old_silver_entries')) {
    die('Required table `old_silver_entries` not found. Please import the SQL first.');
}

if (!tableExists($conn, 'old_silver_items')) {
    die('Required table `old_silver_items` not found. Please import the SQL first.');
}

/* -------------------------------------------------------
   FLASH
------------------------------------------------------- */
$success = '';
$error = '';

if (!empty($_SESSION['flash_success'])) {
    $success = (string)$_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

if (!empty($_SESSION['flash_error'])) {
    $error = (string)$_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

/* -------------------------------------------------------
   HELPERS
------------------------------------------------------- */
function generateOldSilverEntryNo(mysqli $conn, int $businessId): string
{
    $prefix = 'OS' . date('ym');

    $stmt = $conn->prepare("
        SELECT entry_no
        FROM old_silver_entries
        WHERE business_id = ?
          AND entry_no LIKE CONCAT(?, '%')
        ORDER BY id DESC
        LIMIT 1
    ");

    $lastNo = 0;

    if ($stmt) {
        $stmt->bind_param('is', $businessId, $prefix);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res && ($row = $res->fetch_assoc())) {
            $lastNo = (int)substr((string)$row['entry_no'], -4);
        }

        $stmt->close();
    }

    return $prefix . str_pad((string)($lastNo + 1), 4, '0', STR_PAD_LEFT);
}

function addAuditLogSafe(mysqli $conn, int $businessId, int $userId, string $module, string $action, int $refId, string $desc): void
{
    if (!tableExists($conn, 'audit_logs')) {
        return;
    }

    $columns = [];
    $placeholders = [];
    $types = '';
    $values = [];

    if (hasColumn($conn, 'audit_logs', 'business_id')) {
        $columns[] = 'business_id';
        $placeholders[] = '?';
        $types .= 'i';
        $values[] = $businessId;
    }

    if (hasColumn($conn, 'audit_logs', 'user_id')) {
        $columns[] = 'user_id';
        $placeholders[] = '?';
        $types .= 'i';
        $values[] = $userId;
    }

    if (hasColumn($conn, 'audit_logs', 'module_name')) {
        $columns[] = 'module_name';
        $placeholders[] = '?';
        $types .= 's';
        $values[] = $module;
    }

    if (hasColumn($conn, 'audit_logs', 'action_type')) {
        $columns[] = 'action_type';
        $placeholders[] = '?';
        $types .= 's';
        $values[] = $action;
    }

    if (hasColumn($conn, 'audit_logs', 'reference_id')) {
        $columns[] = 'reference_id';
        $placeholders[] = '?';
        $types .= 'i';
        $values[] = $refId;
    }

    if (hasColumn($conn, 'audit_logs', 'description')) {
        $columns[] = 'description';
        $placeholders[] = '?';
        $types .= 's';
        $values[] = $desc;
    }

    if (hasColumn($conn, 'audit_logs', 'ip_address')) {
        $columns[] = 'ip_address';
        $placeholders[] = '?';
        $types .= 's';
        $values[] = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    }

    if (hasColumn($conn, 'audit_logs', 'user_agent')) {
        $columns[] = 'user_agent';
        $placeholders[] = '?';
        $types .= 's';
        $values[] = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    }

    if (empty($columns)) {
        return;
    }

    $sql = "INSERT INTO audit_logs (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return;
    }

    if ($types !== '') {
        $bindValues = [];
        $bindValues[] = $types;

        foreach ($values as $k => $v) {
            $bindValues[] = &$values[$k];
        }

        call_user_func_array([$stmt, 'bind_param'], $bindValues);
    }

    $stmt->execute();
    $stmt->close();
}

function latestSilverRate(mysqli $conn, int $businessId): float
{
    if (tableExists($conn, 'metal_rates')) {
        $stmt = $conn->prepare("
            SELECT rate_per_gram
            FROM metal_rates
            WHERE business_id = ?
              AND metal_type = 'Silver'
            ORDER BY effective_date DESC, id DESC
            LIMIT 1
        ");

        if ($stmt) {
            $stmt->bind_param('i', $businessId);
            $stmt->execute();
            $res = $stmt->get_result();

            if ($res && ($row = $res->fetch_assoc())) {
                $stmt->close();
                return (float)$row['rate_per_gram'];
            }

            $stmt->close();
        }
    }

    if (tableExists($conn, 'silver_rate_history')) {
        $stmt = $conn->prepare("
            SELECT rate_per_gram
            FROM silver_rate_history
            WHERE business_id = ?
            ORDER BY rate_date DESC, id DESC
            LIMIT 1
        ");

        if ($stmt) {
            $stmt->bind_param('i', $businessId);
            $stmt->execute();
            $res = $stmt->get_result();

            if ($res && ($row = $res->fetch_assoc())) {
                $stmt->close();
                return (float)$row['rate_per_gram'];
            }

            $stmt->close();
        }
    }

    return 0.00;
}

/* -------------------------------------------------------
   CUSTOMERS
------------------------------------------------------- */
$customers = [];

if (tableExists($conn, 'customers')) {
    $stmt = $conn->prepare("
        SELECT id, customer_name, mobile
        FROM customers
        WHERE business_id = ?
          AND is_active = 1
        ORDER BY customer_name ASC
        LIMIT 500
    ");

    if ($stmt) {
        $stmt->bind_param('i', $businessId);
        $stmt->execute();
        $res = $stmt->get_result();

        while ($res && ($row = $res->fetch_assoc())) {
            $customers[] = $row;
        }

        $stmt->close();
    }
}

$defaultRate = latestSilverRate($conn, $businessId);

/* -------------------------------------------------------
   FORM DEFAULTS
------------------------------------------------------- */
$entryNo = generateOldSilverEntryNo($conn, $businessId);
$entryDate = date('Y-m-d');
$customerId = 0;
$customerName = '';
$customerMobile = '';
$idProofType = '';
$idProofNumber = '';
$ratePerGram = $defaultRate;
$deductionPercent = '0';
$adjustmentType = 'Exchange';
$linkedSaleId = '';
$notes = '';

/* -------------------------------------------------------
   SAVE ENTRY - POST REDIRECT GET
------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_old_silver'])) {
    $entryNo = trim((string)($_POST['entry_no'] ?? generateOldSilverEntryNo($conn, $businessId)));
    $entryDate = trim((string)($_POST['entry_date'] ?? date('Y-m-d')));
    $customerId = (int)($_POST['customer_id'] ?? 0);
    $customerName = trim((string)($_POST['customer_name'] ?? ''));
    $customerMobile = trim((string)($_POST['customer_mobile'] ?? ''));
    $idProofType = trim((string)($_POST['id_proof_type'] ?? ''));
    $idProofNumber = trim((string)($_POST['id_proof_number'] ?? ''));
    $ratePerGram = (float)($_POST['rate_per_gram'] ?? 0);
    $deductionPercent = (float)($_POST['deduction_percent'] ?? 0);
    $adjustmentType = trim((string)($_POST['adjustment_type'] ?? 'Exchange'));
    $linkedSaleId = (int)($_POST['linked_sale_id'] ?? 0);
    $notes = trim((string)($_POST['notes'] ?? ''));

    $itemNames = $_POST['item_name'] ?? [];
    $purities = $_POST['purity'] ?? [];
    $grossWeights = $_POST['gross_weight'] ?? [];
    $lessWeights = $_POST['less_weight'] ?? [];
    $remarksList = $_POST['item_remarks'] ?? [];

    $errors = [];

    if ($entryNo === '') {
        $errors[] = 'Entry number is required.';
    }

    if ($entryDate === '') {
        $errors[] = 'Entry date is required.';
    }

    if ($customerName === '') {
        $errors[] = 'Customer name is required.';
    }

    if ($ratePerGram <= 0) {
        $errors[] = 'Rate per gram must be greater than zero.';
    }

    if ($deductionPercent < 0) {
        $errors[] = 'Deduction percent cannot be negative.';
    }

    if (!in_array($adjustmentType, ['Cash', 'Exchange', 'Pending'], true)) {
        $adjustmentType = 'Exchange';
    }

    $items = [];
    $totalGrossWeight = 0.000;
    $totalLessWeight = 0.000;
    $totalNetWeight = 0.000;

    for ($i = 0; $i < count($itemNames); $i++) {
        $itemName = trim((string)($itemNames[$i] ?? ''));
        $purity = trim((string)($purities[$i] ?? ''));
        $gross = is_numeric($grossWeights[$i] ?? null) ? (float)$grossWeights[$i] : 0.000;
        $less = is_numeric($lessWeights[$i] ?? null) ? (float)$lessWeights[$i] : 0.000;
        $itemRemarks = trim((string)($remarksList[$i] ?? ''));

        if ($itemName === '' && $gross <= 0 && $less <= 0) {
            continue;
        }

        if ($itemName === '') {
            $errors[] = 'Item name is required in row ' . ($i + 1) . '.';
            continue;
        }

        if ($gross <= 0) {
            $errors[] = 'Gross weight must be greater than zero in row ' . ($i + 1) . '.';
            continue;
        }

        if ($less < 0) {
            $errors[] = 'Less weight cannot be negative in row ' . ($i + 1) . '.';
            continue;
        }

        if ($less > $gross) {
            $errors[] = 'Less weight cannot be greater than gross weight in row ' . ($i + 1) . '.';
            continue;
        }

        $net = $gross - $less;

        $items[] = [
            'item_name' => $itemName,
            'purity' => $purity,
            'gross_weight' => $gross,
            'less_weight' => $less,
            'net_weight' => $net,
            'remarks' => $itemRemarks
        ];

        $totalGrossWeight += $gross;
        $totalLessWeight += $less;
        $totalNetWeight += $net;
    }

    if (empty($items)) {
        $errors[] = 'Please add at least one old silver item.';
    }

    $grossAmount = $totalNetWeight * $ratePerGram;
    $deductionAmount = ($grossAmount * $deductionPercent) / 100;
    $finalAmount = $grossAmount - $deductionAmount;

    if ($finalAmount < 0) {
        $finalAmount = 0;
    }

    if (empty($errors)) {
        $stmt = $conn->prepare("
            SELECT id
            FROM old_silver_entries
            WHERE business_id = ?
              AND entry_no = ?
            LIMIT 1
        ");

        if ($stmt) {
            $stmt->bind_param('is', $businessId, $entryNo);
            $stmt->execute();
            $res = $stmt->get_result();

            if ($res && $res->num_rows > 0) {
                $errors[] = 'Entry number already exists.';
            }

            $stmt->close();
        }
    }

    if (!empty($errors)) {
        $_SESSION['flash_error'] = implode('<br>', array_map('h', $errors));
        header('Location: old-silver-entry.php');
        exit;
    }

    $customerIdDb = $customerId > 0 ? $customerId : null;
    $linkedSaleIdDb = $linkedSaleId > 0 ? $linkedSaleId : null;

    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare("
            INSERT INTO old_silver_entries
            (
                business_id,
                entry_no,
                entry_date,
                customer_id,
                customer_name,
                customer_mobile,
                id_proof_type,
                id_proof_number,
                total_gross_weight,
                total_less_weight,
                total_net_weight,
                rate_per_gram,
                deduction_percent,
                deduction_amount,
                final_amount,
                adjustment_type,
                linked_sale_id,
                notes,
                created_by
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        if (!$stmt) {
            throw new Exception('Failed to prepare old silver entry insert: ' . $conn->error);
        }

        /*
            IMPORTANT FIX:
            Old wrong type string:
            ississssddddddsisii

            Correct type string:
            ississssdddddddsisi

            Because:
            final_amount = double
            adjustment_type = string
            linked_sale_id = integer
            notes = string
            created_by = integer
        */
        $stmt->bind_param(
            'ississssdddddddsisi',
            $businessId,
            $entryNo,
            $entryDate,
            $customerIdDb,
            $customerName,
            $customerMobile,
            $idProofType,
            $idProofNumber,
            $totalGrossWeight,
            $totalLessWeight,
            $totalNetWeight,
            $ratePerGram,
            $deductionPercent,
            $deductionAmount,
            $finalAmount,
            $adjustmentType,
            $linkedSaleIdDb,
            $notes,
            $userId
        );

        if (!$stmt->execute()) {
            $stmtError = $stmt->error;
            $stmt->close();
            throw new Exception('Failed to save old silver entry: ' . $stmtError);
        }

        $oldSilverEntryId = (int)$stmt->insert_id;
        $stmt->close();

        $itemStmt = $conn->prepare("
            INSERT INTO old_silver_items
            (
                business_id,
                old_silver_entry_id,
                item_name,
                purity,
                gross_weight,
                less_weight,
                net_weight,
                remarks
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        if (!$itemStmt) {
            throw new Exception('Failed to prepare old silver item insert: ' . $conn->error);
        }

        foreach ($items as $item) {
            $itemStmt->bind_param(
                'iissddds',
                $businessId,
                $oldSilverEntryId,
                $item['item_name'],
                $item['purity'],
                $item['gross_weight'],
                $item['less_weight'],
                $item['net_weight'],
                $item['remarks']
            );

            if (!$itemStmt->execute()) {
                $stmtError = $itemStmt->error;
                $itemStmt->close();
                throw new Exception('Failed to save old silver item: ' . $stmtError);
            }
        }

        $itemStmt->close();

        addAuditLogSafe(
            $conn,
            $businessId,
            $userId,
            'Old Silver',
            'Create',
            $oldSilverEntryId,
            'Created old silver entry ' . $entryNo . ' for ' . $customerName
        );

        $conn->commit();

        $_SESSION['flash_success'] = 'Old silver entry saved successfully. Entry No: ' . $entryNo;
        header('Location: old-silver-entry.php?entry_id=' . $oldSilverEntryId);
        exit;
    } catch (Throwable $e) {
        $conn->rollback();

        $_SESSION['flash_error'] = $e->getMessage();
        header('Location: old-silver-entry.php');
        exit;
    }
}

/* -------------------------------------------------------
   RECENT ENTRIES
------------------------------------------------------- */
$recentEntries = [];

$stmt = $conn->prepare("
    SELECT
        id,
        entry_no,
        entry_date,
        customer_name,
        customer_mobile,
        total_net_weight,
        rate_per_gram,
        deduction_amount,
        final_amount,
        adjustment_type,
        created_at
    FROM old_silver_entries
    WHERE business_id = ?
    ORDER BY id DESC
    LIMIT 10
");

if ($stmt) {
    $stmt->bind_param('i', $businessId);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($res && ($row = $res->fetch_assoc())) {
        $recentEntries[] = $row;
    }

    $stmt->close();
}




$theme = [
    'primary_color' => '#d89416',
    'primary_dark_color' => '#b86a0b',
    'primary_soft_color' => '#fff6e5',
    'sidebar_gradient_1' => '#171c21',
    'sidebar_gradient_2' => '#20272d',
    'sidebar_gradient_3' => '#101419',
    'page_background' => '#f5f5f3',
    'card_background' => '#ffffff',
    'text_color' => '#111827',
    'muted_text_color' => '#7b8497',
    'border_color' => '#e5e7eb',
    'font_family' => 'Inter',
    'heading_font_family' => 'Playfair Display',
    'border_radius_px' => 14,
];

if (function_exists('tableExists') && tableExists($conn, 'business_theme_settings')) {
    $themeStmt = $conn->prepare(
        'SELECT * FROM business_theme_settings WHERE business_id = ? LIMIT 1'
    );

    if ($themeStmt) {
        $themeStmt->bind_param('i', $businessId);
        $themeStmt->execute();
        $themeRow = $themeStmt->get_result()->fetch_assoc() ?: [];
        $themeStmt->close();

        foreach ($theme as $themeKey => $themeDefault) {
            if (isset($themeRow[$themeKey]) && $themeRow[$themeKey] !== '') {
                $theme[$themeKey] = $themeRow[$themeKey];
            }
        }
    }
}

$businessName = (string)($_SESSION['business_name'] ?? 'Jewellery ERP');

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo h($businessName); ?> - Old Silver Entry</title>

    <?php include('includes/links.php'); ?>

    
<style>
:root {
    --brand: <?php echo h($theme['primary_color']); ?>;
    --brand-dark: <?php echo h($theme['primary_dark_color']); ?>;
    --brand-soft: <?php echo h($theme['primary_soft_color']); ?>;
    --page-bg: <?php echo h($theme['page_background']); ?>;
    --card-bg: <?php echo h($theme['card_background']); ?>;
    --text: <?php echo h($theme['text_color']); ?>;
    --muted: <?php echo h($theme['muted_text_color']); ?>;
    --line: <?php echo h($theme['border_color']); ?>;
    --radius: <?php echo (int)$theme['border_radius_px']; ?>px;
}

body {
    background: var(--page-bg);
    color: var(--text);
    font-family: <?php echo json_encode((string)$theme['font_family']); ?>, sans-serif;
}

.sidebar {
    background: linear-gradient(
        180deg,
        <?php echo h($theme['sidebar_gradient_1']); ?>,
        <?php echo h($theme['sidebar_gradient_2']); ?>,
        <?php echo h($theme['sidebar_gradient_3']); ?>
    ) !important;
}

.content-wrap {
    padding-top: 16px;
}

.page-new-header {
    margin-bottom: 14px;
}

.page-new-title {
    margin: 0;
    font-family: <?php echo json_encode((string)$theme['heading_font_family']); ?>, serif;
    font-size: 24px;
    line-height: 1.1;
    font-weight: 800;
}

.page-new-subtitle {
    margin-top: 4px;
    color: var(--muted);
    font-size: 11px;
}

.card,
.report-card,
.invoice-header-box {
    background: var(--card-bg) !important;
    border: 1px solid var(--line) !important;
    border-radius: var(--radius) !important;
    box-shadow: none !important;
}

.card-header,
.report-card .card-header {
    background: #f7f7f8 !important;
    border-bottom: 1px solid var(--line) !important;
    color: var(--text);
    border-radius: var(--radius) var(--radius) 0 0 !important;
}

.card-body,
.report-card .card-body {
    padding: 14px !important;
}

h1, h2, h3, h4, h5, h6,
.card-title {
    color: var(--text);
}

.form-label {
    margin-bottom: 5px;
    color: var(--text);
    font-size: 10px;
    font-weight: 700;
}

.form-control,
.form-select {
    min-height: 40px;
    border: 1px solid var(--line);
    border-radius: 10px;
    background: var(--card-bg);
    color: var(--text);
    font-size: 11px;
    box-shadow: none;
}

.form-control:focus,
.form-select:focus {
    border-color: var(--brand);
    box-shadow: 0 0 0 .18rem rgba(216, 148, 22, .12);
}

.btn {
    min-height: 38px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 700;
}

.btn-primary,
.btn-info {
    border-color: transparent !important;
    background: linear-gradient(135deg, var(--brand), var(--brand-dark)) !important;
    color: #fff !important;
}

.btn-secondary,
.btn-light {
    border: 1px solid var(--line) !important;
    background: #fff !important;
    color: var(--text) !important;
}

.table-responsive {
    border-radius: 12px;
}

.table {
    margin-bottom: 0;
    color: var(--text);
    font-size: 10px;
}

.table thead th {
    padding: 12px 13px;
    border-color: var(--line);
    background: #f7f7f8;
    color: #738096;
    font-size: 9px;
    font-weight: 800;
    letter-spacing: .035em;
    text-transform: uppercase;
    white-space: nowrap;
}

.table tbody td {
    padding: 11px 13px;
    border-color: var(--line);
    background: var(--card-bg) !important;
    color: var(--text);
    vertical-align: middle;
}

.badge {
    border-radius: 999px;
    padding: 5px 9px;
    font-size: 9px;
    font-weight: 800;
}

.alert {
    border: 0;
    border-radius: 10px;
    font-size: 11px;
}

.text-muted {
    color: var(--muted) !important;
}

.row > [class*="col-"] > .card {
    height: calc(100% - 12px);
    margin-bottom: 12px;
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
}

@media (max-width: 767px) {
    .content-wrap {
        padding-left: 10px;
        padding-right: 10px;
    }
}

@media print {
    .sidebar,
    .app-nav,
    .footer,
    .no-print {
        display: none !important;
    }

    .app-main {
        margin-left: 0 !important;
    }

    .content-wrap {
        padding: 0 !important;
    }

    .table-responsive {
        overflow: visible !important;
    }
}
</style>

    
</head>

<body>
<?php include('includes/sidebar.php'); ?>

<main class="app-main">
    <?php include('includes/nav.php'); ?>

    <div class="content-wrap">
        <div class="page-new-header">
            <h1 class="page-new-title">Old Silver Entry</h1>
            <div class="page-new-subtitle">
                <?php echo h($businessName); ?> &nbsp;•&nbsp; Inventory
            </div>
        </div>

        

                <div class="row">
                    <div class="col-12">
                        <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                            <h4 class="mb-sm-0">Old Silver Entry</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                    <li class="breadcrumb-item active">Old Silver Entry</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($success !== ''): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle"></i> <?php echo h($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <form method="post" action="old-silver-entry.php" id="oldSilverForm">
                    <div class="row">
                        <div class="col-xl-8">

                            <div class="card">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">
                                        <i class="fas fa-user"></i> Customer Details
                                    </h5>
                                </div>

                                <div class="card-body">
                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <label class="form-label">Entry No <span class="text-danger">*</span></label>
                                            <input type="text" name="entry_no" class="form-control" value="<?php echo h($entryNo); ?>" required>
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label">Entry Date <span class="text-danger">*</span></label>
                                            <input type="date" name="entry_date" class="form-control" value="<?php echo h($entryDate); ?>" required>
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label">Adjustment Type</label>
                                            <select name="adjustment_type" class="form-select">
                                                <option value="Cash">Cash</option>
                                                <option value="Exchange" selected>Exchange</option>
                                                <option value="Pending">Pending</option>
                                            </select>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Existing Customer</label>
                                            <select name="customer_id" id="customer_id" class="form-select">
                                                <option value="">Walk-in / New Customer</option>
                                                <?php foreach ($customers as $cust): ?>
                                                    <option
                                                        value="<?php echo (int)$cust['id']; ?>"
                                                        data-name="<?php echo h($cust['customer_name'] ?? ''); ?>"
                                                        data-mobile="<?php echo h($cust['mobile'] ?? ''); ?>"
                                                    >
                                                        <?php echo h(($cust['customer_name'] ?? '') . (!empty($cust['mobile']) ? ' - ' . $cust['mobile'] : '')); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Customer Name <span class="text-danger">*</span></label>
                                            <input type="text" name="customer_name" id="customer_name" class="form-control" required>
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label">Mobile</label>
                                            <input type="text" name="customer_mobile" id="customer_mobile" class="form-control">
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label">ID Proof Type</label>
                                            <select name="id_proof_type" class="form-select">
                                                <option value="">Select</option>
                                                <option value="Aadhaar">Aadhaar</option>
                                                <option value="PAN">PAN</option>
                                                <option value="Voter ID">Voter ID</option>
                                                <option value="Driving License">Driving License</option>
                                                <option value="Other">Other</option>
                                            </select>
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label">ID Proof Number</label>
                                            <input type="text" name="id_proof_number" class="form-control">
                                        </div>

                                        <div class="col-md-12">
                                            <label class="form-label">Notes</label>
                                            <textarea name="notes" class="form-control" rows="2"></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="card">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h5 class="card-title mb-0">
                                        <i class="fas fa-ring"></i> Old Silver Items
                                    </h5>
                                    <button type="button" class="btn btn-sm btn-primary" onclick="addItemRow()">
                                        <i class="fas fa-plus"></i> Add Item
                                    </button>
                                </div>

                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-bordered align-middle mb-0" id="itemsTable">
                                            <thead>
                                                <tr>
                                                    <th style="min-width: 180px;">Item Name</th>
                                                    <th style="min-width: 100px;">Purity</th>
                                                    <th style="min-width: 120px;">Gross Wt</th>
                                                    <th style="min-width: 120px;">Less Wt</th>
                                                    <th style="min-width: 120px;">Net Wt</th>
                                                    <th style="min-width: 160px;">Remarks</th>
                                                    <th width="60">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody id="itemsBody">
                                                <tr>
                                                    <td>
                                                        <input type="text" name="item_name[]" class="form-control" placeholder="Item name" required>
                                                    </td>
                                                    <td>
                                                        <input type="text" name="purity[]" class="form-control" value="925">
                                                    </td>
                                                    <td>
                                                        <input type="number" step="0.001" min="0" name="gross_weight[]" class="form-control gross-weight" value="0" oninput="calculateTotals()" required>
                                                    </td>
                                                    <td>
                                                        <input type="number" step="0.001" min="0" name="less_weight[]" class="form-control less-weight" value="0" oninput="calculateTotals()">
                                                    </td>
                                                    <td>
                                                        <input type="number" step="0.001" name="net_weight_display[]" class="form-control net-weight" value="0.000" readonly>
                                                    </td>
                                                    <td>
                                                        <input type="text" name="item_remarks[]" class="form-control" placeholder="Remarks">
                                                    </td>
                                                    <td class="text-center">
                                                        <button type="button" class="btn btn-sm btn-danger" onclick="removeItemRow(this)">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>

                                    <small class="text-muted d-block mt-2">
                                        Net weight is calculated automatically as Gross Weight - Less Weight.
                                    </small>
                                </div>
                            </div>

                        </div>

                        <div class="col-xl-4">

                            <div class="card">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">
                                        <i class="fas fa-calculator"></i> Calculation
                                    </h5>
                                </div>

                                <div class="card-body">
                                    <div class="mb-3">
                                        <label class="form-label">Rate Per Gram ₹ <span class="text-danger">*</span></label>
                                        <input type="number" step="0.01" min="0" name="rate_per_gram" id="rate_per_gram" class="form-control" value="<?php echo h(number_format((float)$ratePerGram, 2, '.', '')); ?>" oninput="calculateTotals()" required>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Deduction %</label>
                                        <input type="number" step="0.01" min="0" name="deduction_percent" id="deduction_percent" class="form-control" value="0" oninput="calculateTotals()">
                                    </div>

                                    <div class="table-responsive">
                                        <table class="table table-bordered mb-0">
                                            <tr>
                                                <th>Total Gross Weight</th>
                                                <td class="text-end"><span id="total_gross">0.000</span> g</td>
                                            </tr>
                                            <tr>
                                                <th>Total Less Weight</th>
                                                <td class="text-end"><span id="total_less">0.000</span> g</td>
                                            </tr>
                                            <tr>
                                                <th>Total Net Weight</th>
                                                <td class="text-end"><strong><span id="total_net">0.000</span> g</strong></td>
                                            </tr>
                                            <tr>
                                                <th>Gross Amount</th>
                                                <td class="text-end">₹<span id="gross_amount">0.00</span></td>
                                            </tr>
                                            <tr>
                                                <th>Deduction Amount</th>
                                                <td class="text-end text-danger">₹<span id="deduction_amount">0.00</span></td>
                                            </tr>
                                            <tr>
                                                <th>Final Amount</th>
                                                <td class="text-end text-success">
                                                    <strong>₹<span id="final_amount">0.00</span></strong>
                                                </td>
                                            </tr>
                                        </table>
                                    </div>

                                    <div class="d-grid gap-2 mt-3">
                                        <button type="submit" name="save_old_silver" value="1" class="btn btn-primary">
                                            <i class="fas fa-save"></i> Save Old Silver Entry
                                        </button>

                                        <a href="old-silver-entry.php" class="btn btn-secondary">
                                            <i class="fas fa-sync-alt"></i> Reset
                                        </a>
                                    </div>
                                </div>
                            </div>

                            <div class="card">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">
                                        <i class="fas fa-info-circle"></i> Notes
                                    </h5>
                                </div>

                                <div class="card-body">
                                    <ul class="mb-0 ps-3">
                                        <li>Entry number is auto-generated but editable.</li>
                                        <li>Final amount = Net Weight × Rate - Deduction.</li>
                                        <li>Use Exchange if the value is adjusted against a sale bill.</li>
                                        <li>Use Cash if old silver amount is paid directly.</li>
                                    </ul>
                                </div>
                            </div>

                        </div>
                    </div>
                </form>

                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-history"></i> Recent Old Silver Entries
                        </h5>
                    </div>

                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Entry No</th>
                                        <th>Date</th>
                                        <th>Customer</th>
                                        <th>Net Weight</th>
                                        <th>Rate</th>
                                        <th>Deduction</th>
                                        <th>Final Amount</th>
                                        <th>Type</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($recentEntries)): ?>
                                        <?php foreach ($recentEntries as $index => $row): ?>
                                            <tr>
                                                <td><?php echo $index + 1; ?></td>
                                                <td><strong><?php echo h($row['entry_no']); ?></strong></td>
                                                <td><?php echo !empty($row['entry_date']) ? date('d-m-Y', strtotime($row['entry_date'])) : '-'; ?></td>
                                                <td>
                                                    <?php echo h($row['customer_name']); ?>
                                                    <?php if (!empty($row['customer_mobile'])): ?>
                                                        <br><small class="text-muted"><?php echo h($row['customer_mobile']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo number_format((float)$row['total_net_weight'], 3); ?> g</td>
                                                <td>₹<?php echo number_format((float)$row['rate_per_gram'], 2); ?></td>
                                                <td>₹<?php echo number_format((float)$row['deduction_amount'], 2); ?></td>
                                                <td><strong>₹<?php echo number_format((float)$row['final_amount'], 2); ?></strong></td>
                                                <td>
                                                    <?php
                                                    $type = trim((string)($row['adjustment_type'] ?? ''));

                                                    /*
                                                       For old records already saved with blank type
                                                       because of the wrong bind_param, show Exchange instead of empty.
                                                    */
                                                    if ($type === '') {
                                                        $type = 'Exchange';
                                                    }

                                                    $badge = 'secondary';

                                                    if ($type === 'Cash') {
                                                        $badge = 'success';
                                                    } elseif ($type === 'Exchange') {
                                                        $badge = 'primary';
                                                    } elseif ($type === 'Pending') {
                                                        $badge = 'warning';
                                                    }
                                                    ?>
                                                    <span class="badge bg-<?php echo h($badge); ?>"><?php echo h($type); ?></span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="9" class="text-center text-muted">No old silver entries found.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

        <?php include('includes/footer.php'); ?>
    </div>
</main>

<?php include('includes/script.php'); ?>
<script src="assets/js/script.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const customerSelect = document.getElementById('customer_id');

    if (customerSelect) {
        customerSelect.addEventListener('change', function () {
            const selected = this.options[this.selectedIndex];

            if (selected && selected.value) {
                document.getElementById('customer_name').value = selected.getAttribute('data-name') || '';
                document.getElementById('customer_mobile').value = selected.getAttribute('data-mobile') || '';
            }
        });
    }

    calculateTotals();
});

function parseNumber(value) {
    const n = parseFloat(value);
    return isNaN(n) ? 0 : n;
}

function addItemRow() {
    const tbody = document.getElementById('itemsBody');

    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td>
            <input type="text" name="item_name[]" class="form-control" placeholder="Item name" required>
        </td>
        <td>
            <input type="text" name="purity[]" class="form-control" value="925">
        </td>
        <td>
            <input type="number" step="0.001" min="0" name="gross_weight[]" class="form-control gross-weight" value="0" oninput="calculateTotals()" required>
        </td>
        <td>
            <input type="number" step="0.001" min="0" name="less_weight[]" class="form-control less-weight" value="0" oninput="calculateTotals()">
        </td>
        <td>
            <input type="number" step="0.001" name="net_weight_display[]" class="form-control net-weight" value="0.000" readonly>
        </td>
        <td>
            <input type="text" name="item_remarks[]" class="form-control" placeholder="Remarks">
        </td>
        <td class="text-center">
            <button type="button" class="btn btn-sm btn-danger" onclick="removeItemRow(this)">
                <i class="fas fa-trash"></i>
            </button>
        </td>
    `;

    tbody.appendChild(tr);
    calculateTotals();
}

function removeItemRow(button) {
    const tbody = document.getElementById('itemsBody');

    if (tbody.rows.length <= 1) {
        alert('At least one item row is required.');
        return;
    }

    button.closest('tr').remove();
    calculateTotals();
}

function calculateTotals() {
    let totalGross = 0;
    let totalLess = 0;
    let totalNet = 0;

    const rows = document.querySelectorAll('#itemsBody tr');

    rows.forEach(function (row) {
        const grossInput = row.querySelector('.gross-weight');
        const lessInput = row.querySelector('.less-weight');
        const netInput = row.querySelector('.net-weight');

        const gross = parseNumber(grossInput ? grossInput.value : 0);
        const less = parseNumber(lessInput ? lessInput.value : 0);
        const net = Math.max(gross - less, 0);

        if (netInput) {
            netInput.value = net.toFixed(3);
        }

        totalGross += gross;
        totalLess += less;
        totalNet += net;
    });

    const rate = parseNumber(document.getElementById('rate_per_gram').value);
    const deductionPercent = parseNumber(document.getElementById('deduction_percent').value);

    const grossAmount = totalNet * rate;
    const deductionAmount = grossAmount * deductionPercent / 100;
    const finalAmount = Math.max(grossAmount - deductionAmount, 0);

    document.getElementById('total_gross').innerText = totalGross.toFixed(3);
    document.getElementById('total_less').innerText = totalLess.toFixed(3);
    document.getElementById('total_net').innerText = totalNet.toFixed(3);
    document.getElementById('gross_amount').innerText = grossAmount.toFixed(2);
    document.getElementById('deduction_amount').innerText = deductionAmount.toFixed(2);
    document.getElementById('final_amount').innerText = finalAmount.toFixed(2);
}
</script>
</body>
</html>
