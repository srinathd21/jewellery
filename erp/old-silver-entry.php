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
        $result = $conn->query("SHOW TABLES LIKE '{$table}'");
        return $result && $result->num_rows > 0;
    }
}

if (!function_exists('hasColumn')) {
    function hasColumn(mysqli $conn, string $table, string $column): bool
    {
        $table = $conn->real_escape_string($table);
        $column = $conn->real_escape_string($column);
        $result = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
        return $result && $result->num_rows > 0;
    }
}

if (!function_exists('bindDynamic')) {
    function bindDynamic(mysqli_stmt $stmt, string $types, array &$values): void
    {
        $params = [$types];
        foreach ($values as $key => &$value) {
            $params[] = &$value;
        }
        call_user_func_array([$stmt, 'bind_param'], $params);
    }
}

if (!function_exists('addAuditLogSafe')) {
    function addAuditLogSafe(
        mysqli $conn,
        int $businessId,
        int $branchId,
        int $userId,
        int $referenceId,
        string $entryNo
    ): void {
        if (!tableExists($conn, 'audit_logs')) {
            return;
        }

        $columns = [];
        $placeholders = [];
        $types = '';
        $values = [];

        $available = [
            'business_id' => ['i', $businessId],
            'branch_id' => ['i', $branchId],
            'user_id' => ['i', $userId],
            'module_code' => ['s', 'inventory.old-metal'],
            'action_type' => ['s', 'Create'],
            'reference_table' => ['s', 'old_metal_entries'],
            'reference_id' => ['i', $referenceId],
            'description' => ['s', 'Created old silver entry ' . $entryNo],
            'ip_address' => ['s', (string)($_SERVER['REMOTE_ADDR'] ?? '')],
            'user_agent' => ['s', (string)($_SERVER['HTTP_USER_AGENT'] ?? '')],
        ];

        foreach ($available as $column => [$type, $value]) {
            if (hasColumn($conn, 'audit_logs', $column)) {
                $columns[] = "`{$column}`";
                $placeholders[] = '?';
                $types .= $type;
                $values[] = $value;
            }
        }

        if (!$columns) {
            return;
        }

        $stmt = $conn->prepare(
            'INSERT INTO audit_logs (' . implode(',', $columns) . ')
             VALUES (' . implode(',', $placeholders) . ')'
        );

        if (!$stmt) {
            return;
        }

        bindDynamic($stmt, $types, $values);
        $stmt->execute();
        $stmt->close();
    }
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? $_SESSION['default_branch_id'] ?? 0);

if ($userId <= 0) {
    header('Location: login.php');
    exit;
}

if ($businessId <= 0 || $branchId <= 0) {
    die('A valid business and branch must be selected.');
}

$roleName = strtolower(trim((string)($_SESSION['role_name'] ?? '')));
$roleCode = strtolower(trim((string)($_SESSION['role_code'] ?? '')));
$userType = strtolower(trim((string)($_SESSION['user_type'] ?? '')));
$permissions = $_SESSION['permissions'] ?? [];

$allowedRoles = [
    'platform admin',
    'super admin',
    'admin',
    'manager',
    'staff',
    'sales',
    'billing',
];

$accessAllowed = in_array($roleName, $allowedRoles, true)
    || in_array($roleCode, $allowedRoles, true)
    || in_array($userType, $allowedRoles, true);

foreach (['perm.stock', 'perm.inventory', 'perm.old_metal', 'perm.old-metal'] as $permissionCode) {
    $permission = $permissions[$permissionCode] ?? [];
    if (
        (int)($permission['can_open'] ?? 0) === 1
        || (int)($permission['can_view'] ?? 0) === 1
        || (int)($permission['can_create'] ?? 0) === 1
    ) {
        $accessAllowed = true;
        break;
    }
}

if (!$accessAllowed) {
    http_response_code(403);
    die('Access denied.');
}

foreach (['old_metal_entries', 'old_metal_items', 'metals'] as $requiredTable) {
    if (!tableExists($conn, $requiredTable)) {
        die("Required table `{$requiredTable}` was not found in the current database.");
    }
}

function generateOldMetalEntryNo(mysqli $conn, int $businessId, int $branchId): string
{
    $prefix = 'OS' . date('ym');
    $like = $prefix . '%';

    $stmt = $conn->prepare(
        "SELECT entry_no
         FROM old_metal_entries
         WHERE business_id = ?
           AND branch_id = ?
           AND entry_no LIKE ?
         ORDER BY id DESC
         LIMIT 1"
    );

    if (!$stmt) {
        return $prefix . '0001';
    }

    $stmt->bind_param('iis', $businessId, $branchId, $like);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $next = 1;
    if ($row && preg_match('/(\d+)$/', (string)$row['entry_no'], $match)) {
        $next = (int)$match[1] + 1;
    }

    return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

function getSilverMetal(mysqli $conn, int $businessId): ?array
{
    $stmt = $conn->prepare(
        "SELECT id, metal_code, metal_name, default_purity
         FROM metals
         WHERE business_id = ?
           AND is_active = 1
           AND (
                UPPER(metal_code) LIKE '%SILVER%'
                OR UPPER(metal_name) LIKE '%SILVER%'
           )
         ORDER BY
            CASE
                WHEN UPPER(metal_code) IN ('SILVER','SILVER925') THEN 0
                ELSE 1
            END,
            id
         LIMIT 1"
    );

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $businessId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function getLatestSilverRate(
    mysqli $conn,
    int $businessId,
    int $branchId,
    int $metalId,
    float $defaultPurity
): float {
    if (!tableExists($conn, 'metal_rates')) {
        return 0.00;
    }

    $stmt = $conn->prepare(
        "SELECT rate_per_gram
         FROM metal_rates
         WHERE business_id = ?
           AND metal_id = ?
           AND (branch_id = ? OR branch_id IS NULL)
           AND is_current = 1
         ORDER BY
            CASE WHEN branch_id = ? THEN 0 ELSE 1 END,
            ABS(purity - ?),
            effective_from DESC,
            id DESC
         LIMIT 1"
    );

    if (!$stmt) {
        return 0.00;
    }

    $stmt->bind_param('iiiid', $businessId, $metalId, $branchId, $branchId, $defaultPurity);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (float)($row['rate_per_gram'] ?? 0);
}

$silverMetal = getSilverMetal($conn, $businessId);

if (!$silverMetal) {
    die('Silver metal master was not found. Add an active Silver metal in the Metals master.');
}

$silverMetalId = (int)$silverMetal['id'];
$defaultPurity = (float)($silverMetal['default_purity'] ?? 92.5);
$defaultRate = getLatestSilverRate(
    $conn,
    $businessId,
    $branchId,
    $silverMetalId,
    $defaultPurity
);

$customers = [];
if (tableExists($conn, 'customers')) {
    $stmt = $conn->prepare(
        "SELECT id, customer_code, customer_name, mobile
         FROM customers
         WHERE business_id = ?
           AND is_active = 1
           AND (home_branch_id = ? OR home_branch_id IS NULL)
         ORDER BY customer_name
         LIMIT 500"
    );

    if ($stmt) {
        $stmt->bind_param('ii', $businessId, $branchId);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($result && $row = $result->fetch_assoc()) {
            $customers[] = $row;
        }

        $stmt->close();
    }
}

$success = (string)($_SESSION['flash_success'] ?? '');
$error = (string)($_SESSION['flash_error'] ?? '');
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$entryNo = generateOldMetalEntryNo($conn, $businessId, $branchId);
$entryDate = date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_old_silver'])) {
    $entryNo = trim((string)($_POST['entry_no'] ?? ''));
    $entryDate = trim((string)($_POST['entry_date'] ?? ''));
    $customerId = (int)($_POST['customer_id'] ?? 0);
    $customerName = trim((string)($_POST['customer_name'] ?? ''));
    $remarks = trim((string)($_POST['remarks'] ?? ''));

    $descriptions = $_POST['description'] ?? [];
    $purities = $_POST['purity'] ?? [];
    $grossWeights = $_POST['gross_weight'] ?? [];
    $stoneWeights = $_POST['stone_weight'] ?? [];
    $deductionWeights = $_POST['deduction_weight'] ?? [];
    $rates = $_POST['rate_per_gram'] ?? [];

    $errors = [];
    $items = [];
    $totalGrossWeight = 0.0;
    $totalNetWeight = 0.0;
    $totalValue = 0.0;

    if ($entryNo === '') {
        $errors[] = 'Entry number is required.';
    }

    if ($entryDate === '') {
        $errors[] = 'Entry date is required.';
    }

    if ($customerName === '') {
        $errors[] = 'Customer name is required.';
    }

    $rowCount = max(
        count($descriptions),
        count($purities),
        count($grossWeights),
        count($stoneWeights),
        count($deductionWeights),
        count($rates)
    );

    for ($index = 0; $index < $rowCount; $index++) {
        $description = trim((string)($descriptions[$index] ?? 'Old Silver'));
        $purity = is_numeric($purities[$index] ?? null)
            ? (float)$purities[$index]
            : $defaultPurity;
        $grossWeight = is_numeric($grossWeights[$index] ?? null)
            ? (float)$grossWeights[$index]
            : 0.0;
        $stoneWeight = is_numeric($stoneWeights[$index] ?? null)
            ? (float)$stoneWeights[$index]
            : 0.0;
        $deductionWeight = is_numeric($deductionWeights[$index] ?? null)
            ? (float)$deductionWeights[$index]
            : 0.0;
        $ratePerGram = is_numeric($rates[$index] ?? null)
            ? (float)$rates[$index]
            : 0.0;

        if ($description === '' && $grossWeight <= 0) {
            continue;
        }

        if ($description === '') {
            $errors[] = 'Description is required in item row ' . ($index + 1) . '.';
            continue;
        }

        if ($purity <= 0 || $purity > 100) {
            $errors[] = 'Purity must be between 0 and 100 in item row ' . ($index + 1) . '.';
            continue;
        }

        if ($grossWeight <= 0) {
            $errors[] = 'Gross weight must be greater than zero in item row ' . ($index + 1) . '.';
            continue;
        }

        if ($stoneWeight < 0 || $deductionWeight < 0) {
            $errors[] = 'Stone and deduction weights cannot be negative in item row ' . ($index + 1) . '.';
            continue;
        }

        $netWeight = $grossWeight - $stoneWeight - $deductionWeight;

        if ($netWeight <= 0) {
            $errors[] = 'Net weight must be greater than zero in item row ' . ($index + 1) . '.';
            continue;
        }

        if ($ratePerGram <= 0) {
            $errors[] = 'Rate per gram must be greater than zero in item row ' . ($index + 1) . '.';
            continue;
        }

        $valueAmount = $netWeight * $ratePerGram;

        $items[] = [
            'description' => $description,
            'purity' => $purity,
            'gross_weight' => $grossWeight,
            'stone_weight' => $stoneWeight,
            'deduction_weight' => $deductionWeight,
            'net_weight' => $netWeight,
            'rate_per_gram' => $ratePerGram,
            'value_amount' => $valueAmount,
        ];

        $totalGrossWeight += $grossWeight;
        $totalNetWeight += $netWeight;
        $totalValue += $valueAmount;
    }

    if (!$items && !$errors) {
        $errors[] = 'Please add at least one valid old silver item.';
    }

    if (!$errors) {
        $stmt = $conn->prepare(
            "SELECT id
             FROM old_metal_entries
             WHERE business_id = ?
               AND entry_no = ?
             LIMIT 1"
        );

        if (!$stmt) {
            $errors[] = 'Unable to validate entry number: ' . $conn->error;
        } else {
            $stmt->bind_param('is', $businessId, $entryNo);
            $stmt->execute();

            if ($stmt->get_result()->num_rows > 0) {
                $errors[] = 'Entry number already exists.';
            }

            $stmt->close();
        }
    }

    if ($errors) {
        $_SESSION['flash_error'] = implode('<br>', array_map('h', $errors));
        header('Location: old-silver-entry.php');
        exit;
    }

    $customerIdDb = $customerId > 0 ? $customerId : null;

    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare(
            "INSERT INTO old_metal_entries (
                business_id,
                branch_id,
                entry_no,
                entry_date,
                customer_id,
                customer_name,
                total_gross_weight,
                total_net_weight,
                total_value,
                linked_sale_id,
                workflow_status,
                remarks,
                created_by
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, 'Posted', ?, ?)"
        );

        if (!$stmt) {
            throw new RuntimeException('Unable to prepare old metal entry insert: ' . $conn->error);
        }

        $stmt->bind_param(
            'iissisdddsi',
            $businessId,
            $branchId,
            $entryNo,
            $entryDate,
            $customerIdDb,
            $customerName,
            $totalGrossWeight,
            $totalNetWeight,
            $totalValue,
            $remarks,
            $userId
        );

        if (!$stmt->execute()) {
            throw new RuntimeException('Failed to save old silver entry: ' . $stmt->error);
        }

        $entryId = (int)$stmt->insert_id;
        $stmt->close();

        $itemStmt = $conn->prepare(
            "INSERT INTO old_metal_items (
                business_id,
                old_metal_entry_id,
                metal_id,
                purity,
                gross_weight,
                stone_weight,
                deduction_weight,
                net_weight,
                rate_per_gram,
                value_amount,
                description
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        if (!$itemStmt) {
            throw new RuntimeException('Unable to prepare old metal item insert: ' . $conn->error);
        }

        foreach ($items as $item) {
            $itemStmt->bind_param(
                'iiiddddddds',
                $businessId,
                $entryId,
                $silverMetalId,
                $item['purity'],
                $item['gross_weight'],
                $item['stone_weight'],
                $item['deduction_weight'],
                $item['net_weight'],
                $item['rate_per_gram'],
                $item['value_amount'],
                $item['description']
            );

            if (!$itemStmt->execute()) {
                throw new RuntimeException('Failed to save old silver item: ' . $itemStmt->error);
            }
        }

        $itemStmt->close();

        addAuditLogSafe(
            $conn,
            $businessId,
            $branchId,
            $userId,
            $entryId,
            $entryNo
        );

        $conn->commit();

        $_SESSION['flash_success'] = 'Old silver entry saved successfully. Entry No: ' . $entryNo;
        header('Location: old-silver-entry.php?entry_id=' . $entryId);
        exit;
    } catch (Throwable $exception) {
        $conn->rollback();
        $_SESSION['flash_error'] = $exception->getMessage();
        header('Location: old-silver-entry.php');
        exit;
    }
}

$recentEntries = [];
$stmt = $conn->prepare(
    "SELECT
        id,
        entry_no,
        entry_date,
        customer_name,
        total_gross_weight,
        total_net_weight,
        total_value,
        workflow_status,
        created_at
     FROM old_metal_entries
     WHERE business_id = ?
       AND branch_id = ?
     ORDER BY id DESC
     LIMIT 10"
);

if ($stmt) {
    $stmt->bind_param('ii', $businessId, $branchId);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($result && $row = $result->fetch_assoc()) {
        $recentEntries[] = $row;
    }

    $stmt->close();
}

$pageTitle = 'Old Silver Entry';
$page_title = 'Old Silver Entry';
$currentPage = 'old-silver-entry';

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

$businessName = (string)($_SESSION['business_name'] ?? 'Jewellery ERP');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo h($businessName); ?> - Old Silver Entry</title>
<?php include 'includes/links.php'; ?>
<style>
:root{
    --brand:<?php echo h($theme['primary_color']); ?>;
    --brand-dark:<?php echo h($theme['primary_dark_color']); ?>;
    --page-bg:<?php echo h($theme['page_background']); ?>;
    --card-bg:<?php echo h($theme['card_background']); ?>;
    --text:<?php echo h($theme['text_color']); ?>;
    --muted:<?php echo h($theme['muted_text_color']); ?>;
    --line:<?php echo h($theme['border_color']); ?>;
    --radius:<?php echo (int)$theme['border_radius_px']; ?>px;
}
body{background:var(--page-bg);color:var(--text);font-family:<?php echo json_encode((string)$theme['font_family']); ?>,sans-serif}
.sidebar{background:linear-gradient(180deg,<?php echo h($theme['sidebar_gradient_1']); ?>,<?php echo h($theme['sidebar_gradient_2']); ?>,<?php echo h($theme['sidebar_gradient_3']); ?>)!important}
.page-header{margin-bottom:14px}.page-title{margin:0;font-family:<?php echo json_encode((string)$theme['heading_font_family']); ?>,serif;font-size:22px;font-weight:800}.page-subtitle{margin-top:4px;color:var(--muted);font-size:10px}
.card{background:var(--card-bg)!important;border:1px solid var(--line)!important;border-radius:var(--radius)!important;box-shadow:none!important}
.card-header{background:color-mix(in srgb,var(--muted) 6%,var(--card-bg))!important;border-bottom:1px solid var(--line)!important}.card-body{padding:14px!important}
.form-label{margin-bottom:5px;font-size:10px;font-weight:700}.form-control,.form-select{min-height:39px;border:1px solid var(--line);border-radius:10px;background:var(--card-bg);color:var(--text);font-size:10px}.form-control:focus,.form-select:focus{border-color:var(--brand);box-shadow:0 0 0 .18rem rgba(216,148,22,.12)}
.btn{min-height:37px;border-radius:10px;font-size:10px;font-weight:700}.btn-primary{border-color:transparent!important;background:linear-gradient(135deg,var(--brand),var(--brand-dark))!important}.table{margin-bottom:0;color:var(--text);font-size:10px}.table thead th{padding:10px 11px;background:color-mix(in srgb,var(--muted) 6%,var(--card-bg));color:var(--muted);font-size:9px;text-transform:uppercase;white-space:nowrap}.table tbody td{padding:10px 11px;background:var(--card-bg)!important;color:var(--text);vertical-align:middle}
.alert{border:0;border-radius:10px;font-size:10px}.text-muted{color:var(--muted)!important}
.field-invalid{border-color:#dc3545!important;box-shadow:0 0 0 .18rem rgba(220,53,69,.12)!important}
.validation-message{margin-top:6px;color:#dc3545;font-size:9px;font-weight:700}
.weight-hint{display:block;margin-top:4px;color:var(--muted);font-size:8px}.input-unit-wrap{position:relative}.input-unit-wrap .form-control{padding-right:28px}.input-unit{position:absolute;right:10px;top:50%;transform:translateY(-50%);font-size:9px;color:var(--muted);pointer-events:none}
body.dark-mode,body[data-theme="dark"],html.dark-mode body,html[data-theme="dark"] body{--page-bg:#0f151b;--card-bg:#182129;--text:#f3f6f8;--muted:#9aa7b3;--line:#2c3944}
@media(max-width:767px){.content-wrap{padding-left:10px;padding-right:10px}}
</style>
</head>
<body>
<?php include 'includes/sidebar.php'; ?>

<main class="app-main">
<?php include 'includes/nav.php'; ?>

<div class="content-wrap">
    <div class="page-header">
        <h1 class="page-title">Old Silver Entry</h1>
        <div class="page-subtitle"><?php echo h($businessName); ?> · Inventory</div>
    </div>

    <?php if ($success !== ''): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?php echo h($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?php echo $error; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <form method="post" id="oldSilverForm">
        <div class="row g-3">
            <div class="col-xl-8">
                <div class="card mb-3">
                    <div class="card-header"><strong>Customer Details</strong></div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Entry No *</label>
                                <input type="text" name="entry_no" class="form-control" value="<?php echo h($entryNo); ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Entry Date *</label>
                                <input type="date" name="entry_date" class="form-control" value="<?php echo h($entryDate); ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Silver Metal</label>
                                <input type="text" class="form-control" value="<?php echo h($silverMetal['metal_name']); ?>" readonly>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Existing Customer</label>
                                <select name="customer_id" id="customer_id" class="form-select">
                                    <option value="">Walk-in / New Customer</option>
                                    <?php foreach ($customers as $customer): ?>
                                        <option
                                            value="<?php echo (int)$customer['id']; ?>"
                                            data-name="<?php echo h($customer['customer_name']); ?>"
                                        >
                                            <?php echo h(
                                                ($customer['customer_code'] ?? '') . ' - ' .
                                                $customer['customer_name'] .
                                                (!empty($customer['mobile']) ? ' - ' . $customer['mobile'] : '')
                                            ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Customer Name *</label>
                                <input type="text" name="customer_name" id="customer_name" class="form-control" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Remarks</label>
                                <textarea name="remarks" class="form-control" rows="2"></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <strong>Old Silver Items</strong>
                        <button type="button" class="btn btn-sm btn-primary" onclick="addItemRow()">Add Item</button>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info mb-3">
                            Enter every weight in grams. Example: Gross 100.000, Stone 2.000, Deduction 3.000.
                            Net Weight will be calculated as 95.000 g.
                        </div>
                        <div class="table-responsive">
                            <table class="table table-bordered align-middle" id="itemsTable">
                                <thead>
                                <tr>
                                    <th>Description</th>
                                    <th>Purity %</th>
                                    <th>Gross Wt (g)</th>
                                    <th>Stone Wt (g)</th>
                                    <th>Deduction Wt (g)</th>
                                    <th>Net Wt (g)</th>
                                    <th>Rate/g</th>
                                    <th>Value</th>
                                    <th></th>
                                </tr>
                                </thead>
                                <tbody id="itemsBody">
                                <tr>
                                    <td><input type="text" name="description[]" class="form-control" value="Old Silver" required></td>
                                    <td><input type="number" step="0.0001" min="0.0001" max="100" name="purity[]" class="form-control purity" value="<?php echo h(number_format($defaultPurity, 4, '.', '')); ?>" required></td>
                                    <td><input type="number" step="0.001" min="0" name="gross_weight[]" class="form-control gross-weight" value="" placeholder="e.g. 100.000" inputmode="decimal" required></td>
                                    <td><input type="number" step="0.001" min="0" name="stone_weight[]" class="form-control stone-weight" value="0.000" placeholder="e.g. 2.000" inputmode="decimal" placeholder="e.g. 2.000" inputmode="decimal"></td>
                                    <td><input type="number" step="0.001" min="0" name="deduction_weight[]" class="form-control deduction-weight" value="0.000" placeholder="e.g. 3.000" inputmode="decimal" placeholder="e.g. 3.000" inputmode="decimal"></td>
                                    <td><input type="text" class="form-control net-weight" value="0.000" readonly></td>
                                    <td><input type="number" step="0.01" min="0" name="rate_per_gram[]" class="form-control rate" value="<?php echo h(number_format($defaultRate, 2, '.', '')); ?>" placeholder="e.g. 105.00" inputmode="decimal" required></td>
                                    <td><input type="text" class="form-control value-amount" value="0.00" readonly></td>
                                    <td><button type="button" class="btn btn-sm btn-danger" onclick="removeItemRow(this)">×</button></td>
                                </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-4">
                <div class="card mb-3">
                    <div class="card-header"><strong>Calculation</strong></div>
                    <div class="card-body">
                        <table class="table table-bordered">
                            <tr><th>Total Gross Weight</th><td class="text-end"><span id="totalGross">0.000</span> g</td></tr>
                            <tr><th>Total Net Weight</th><td class="text-end"><strong><span id="totalNet">0.000</span> g</strong></td></tr>
                            <tr><th>Total Value</th><td class="text-end"><strong>₹<span id="totalValue">0.00</span></strong></td></tr>
                        </table>

                        <div class="d-grid gap-2 mt-3">
                            <button type="submit" name="save_old_silver" value="1" class="btn btn-primary">
                                Save Old Silver Entry
                            </button>
                            <a href="old-silver-entry.php" class="btn btn-secondary">Reset</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <div class="card">
        <div class="card-header"><strong>Recent Old Silver Entries</strong></div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Entry No</th>
                        <th>Date</th>
                        <th>Customer</th>
                        <th>Gross Weight</th>
                        <th>Net Weight</th>
                        <th>Total Value</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($recentEntries): ?>
                        <?php foreach ($recentEntries as $index => $row): ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td><strong><?php echo h($row['entry_no']); ?></strong></td>
                                <td><?php echo h(date('d-m-Y', strtotime($row['entry_date']))); ?></td>
                                <td><?php echo h($row['customer_name']); ?></td>
                                <td><?php echo number_format((float)$row['total_gross_weight'], 3); ?> g</td>
                                <td><?php echo number_format((float)$row['total_net_weight'], 3); ?> g</td>
                                <td>₹<?php echo number_format((float)$row['total_value'], 2); ?></td>
                                <td><?php echo h($row['workflow_status']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="8" class="text-center text-muted">No old silver entries found.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php include 'includes/footer.php'; ?>
</div>
</main>

<?php include 'includes/script.php'; ?>
<script src="assets/js/script.js"></script>
<script>
(function(){
    'use strict';

    const defaultPurity=<?php echo json_encode($defaultPurity); ?>;
    const defaultRate=<?php echo json_encode($defaultRate); ?>;

    function number(value){
        const parsed=parseFloat(value);
        return Number.isFinite(parsed)?parsed:0;
    }

    function calculateTotals(){
        let totalGross=0;
        let totalNet=0;
        let totalValue=0;

        document.querySelectorAll('#itemsBody tr').forEach(function(row){
            const gross=number(row.querySelector('.gross-weight')?.value);
            const stone=number(row.querySelector('.stone-weight')?.value);
            const deduction=number(row.querySelector('.deduction-weight')?.value);
            const rate=number(row.querySelector('.rate')?.value);
            const stoneField=row.querySelector('.stone-weight');
            const deductionField=row.querySelector('.deduction-weight');
            const netField=row.querySelector('.net-weight');
            const valueField=row.querySelector('.value-amount');

            if(stoneField){
                stoneField.max=Math.max(gross,0).toFixed(3);
            }

            if(deductionField){
                deductionField.max=Math.max(gross-stone,0).toFixed(3);
            }

            const net=gross-stone-deduction;
            const validNet=gross>0&&stone>=0&&deduction>=0&&net>0;
            const safeNet=validNet?net:0;
            const value=safeNet*rate;

            netField.value=safeNet.toFixed(3);
            valueField.value=value.toFixed(2);

            netField.classList.toggle('field-invalid',gross>0&&!validNet);

            totalGross+=gross;
            totalNet+=net;
            totalValue+=value;
        });

        document.getElementById('totalGross').textContent=totalGross.toFixed(3);
        document.getElementById('totalNet').textContent=totalNet.toFixed(3);
        document.getElementById('totalValue').textContent=totalValue.toFixed(2);
    }

    window.addItemRow=function(){
        const row=document.createElement('tr');
        row.innerHTML=`
            <td><input type="text" name="description[]" class="form-control" value="Old Silver" required></td>
            <td><input type="number" step="0.0001" min="0.0001" max="100" name="purity[]" class="form-control purity" value="${Number(defaultPurity).toFixed(4)}" required></td>
            <td><input type="number" step="0.001" min="0" name="gross_weight[]" class="form-control gross-weight" value="" placeholder="e.g. 100.000" inputmode="decimal" required></td>
            <td><input type="number" step="0.001" min="0" name="stone_weight[]" class="form-control stone-weight" value="0.000" placeholder="e.g. 2.000" inputmode="decimal" placeholder="e.g. 2.000" inputmode="decimal"></td>
            <td><input type="number" step="0.001" min="0" name="deduction_weight[]" class="form-control deduction-weight" value="0.000" placeholder="e.g. 3.000" inputmode="decimal" placeholder="e.g. 3.000" inputmode="decimal"></td>
            <td><input type="text" class="form-control net-weight" value="0.000" readonly></td>
            <td><input type="number" step="0.01" min="0" name="rate_per_gram[]" class="form-control rate" value="${Number(defaultRate).toFixed(2)}" required></td>
            <td><input type="text" class="form-control value-amount" value="0.00" readonly></td>
            <td><button type="button" class="btn btn-sm btn-danger" onclick="removeItemRow(this)">×</button></td>
        `;
        document.getElementById('itemsBody').appendChild(row);
        calculateTotals();
    };

    window.removeItemRow=function(button){
        const body=document.getElementById('itemsBody');
        if(body.rows.length<=1){
            alert('At least one item row is required.');
            return;
        }
        button.closest('tr').remove();
        calculateTotals();
    };

    document.getElementById('itemsBody').addEventListener('input',calculateTotals);

    const customerSelect=document.getElementById('customer_id');
    customerSelect.addEventListener('change',function(){
        const option=this.options[this.selectedIndex];
        if(option&&option.value){
            document.getElementById('customer_name').value=option.dataset.name||'';
        }
    });

    const oldSilverForm=document.getElementById('oldSilverForm');

    oldSilverForm.addEventListener('submit',function(event){
        document.querySelectorAll('.field-invalid').forEach(function(field){
            field.classList.remove('field-invalid');
        });

        document.querySelectorAll('.validation-message').forEach(function(message){
            message.remove();
        });

        const rows=[...document.querySelectorAll('#itemsBody tr')];

        for(let index=0;index<rows.length;index++){
            const row=rows[index];
            const grossField=row.querySelector('.gross-weight');
            const gross=number(grossField?.value);
            const stone=number(row.querySelector('.stone-weight')?.value);
            const deduction=number(row.querySelector('.deduction-weight')?.value);
            const rateField=row.querySelector('.rate');
            const rate=number(rateField?.value);

            if(gross<=0){
                event.preventDefault();
                grossField.classList.add('field-invalid');
                const message=document.createElement('div');
                message.className='validation-message';
                message.textContent='Gross weight must be greater than zero.';
                grossField.parentElement.appendChild(message);
                grossField.focus();
                grossField.scrollIntoView({behavior:'smooth',block:'center'});
                return;
            }

            if(stone+deduction>=gross){
                event.preventDefault();
                grossField.classList.add('field-invalid');
                const message=document.createElement('div');
                message.className='validation-message';
                message.textContent='Stone Weight + Deduction Weight must be less than Gross Weight. Enter all weights in grams, for example 2.000 and 3.000—not 2000 and 3000.';
                grossField.parentElement.appendChild(message);
                grossField.focus();
                grossField.scrollIntoView({behavior:'smooth',block:'center'});
                return;
            }

            if(rate<=0){
                event.preventDefault();
                rateField.classList.add('field-invalid');
                const message=document.createElement('div');
                message.className='validation-message';
                message.textContent='Rate per gram must be greater than zero.';
                rateField.parentElement.appendChild(message);
                rateField.focus();
                rateField.scrollIntoView({behavior:'smooth',block:'center'});
                return;
            }
        }
    });

    document.getElementById('itemsBody').addEventListener('input',function(event){
        event.target.classList.remove('field-invalid');
        const message=event.target.parentElement?.querySelector('.validation-message');
        if(message)message.remove();
    });

    calculateTotals();
})();
</script>
</body>
</html>
