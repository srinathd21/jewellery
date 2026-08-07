<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));

foreach ([
    __DIR__ . '/config/config.php',
    __DIR__ . '/config.php',
    __DIR__ . '/includes/config.php',
    __DIR__ . '/super-admin/includes/config.php',
] as $configFile) {
    if (is_file($configFile)) {
        require_once $configFile;
        break;
    }
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database configuration is not available.');
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn->set_charset('utf8mb4');

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('tableExists')) {
    function tableExists(mysqli $conn, string $table): bool
    {
        $safe = $conn->real_escape_string($table);
        $result = $conn->query("SHOW TABLES LIKE '{$safe}'");
        return $result && $result->num_rows > 0;
    }
}

if (!function_exists('bindDynamic')) {
    function bindDynamic(mysqli_stmt $stmt, string $types, array &$params): void
    {
        if ($types === '' || !$params) {
            return;
        }

        $bind = [$types];
        foreach ($params as $index => $value) {
            $bind[] = &$params[$index];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind);
    }
}

if (!function_exists('metalRateHistoryPermission')) {
    function metalRateHistoryPermission(mysqli $conn, string $action): bool
    {
        if (($_SESSION['user_type'] ?? '') === 'Platform Admin') {
            return true;
        }

        $fieldMap = [
            'open' => 'can_open',
            'view' => 'can_view',
            'value' => 'can_view_value',
        ];

        $field = $fieldMap[$action] ?? '';
        if ($field === '') {
            return false;
        }

        $codes = [
            'perm.inventory.metal_rates',
            'perm.rates.metal',
            'perm.metal_rates',
            'perm.inventory',
        ];

        $permissions = $_SESSION['permissions'] ?? [];
        foreach ($codes as $code) {
            if (isset($permissions[$code]) && array_key_exists($field, $permissions[$code])) {
                return (int)$permissions[$code][$field] === 1;
            }
        }

        $adminRoles = [
            'platform admin', 'super admin', 'admin', 'business admin',
            'manager', 'billing', 'super_admin', 'business_admin',
        ];

        foreach (['user_type', 'role_name', 'role_code'] as $sessionKey) {
            $role = strtolower(trim((string)($_SESSION[$sessionKey] ?? '')));
            if (in_array($role, $adminRoles, true)) {
                return true;
            }
        }

        $businessId = (int)($_SESSION['business_id'] ?? 0);
        $roleId = (int)($_SESSION['role_id'] ?? 0);
        if ($businessId <= 0 || $roleId <= 0 || !tableExists($conn, 'permissions') || !tableExists($conn, 'role_permissions')) {
            return false;
        }

        $marks = implode(',', array_fill(0, count($codes), '?'));
        $sql = "SELECT MAX(COALESCE(rp.`{$field}`,0)) AS allowed
                FROM role_permissions rp
                INNER JOIN permissions p ON p.id = rp.permission_id
                WHERE rp.business_id = ?
                  AND rp.role_id = ?
                  AND p.is_active = 1
                  AND p.permission_code IN ({$marks})";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return false;
        }

        $types = 'ii' . str_repeat('s', count($codes));
        $params = array_merge([$businessId, $roleId], $codes);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int)($row['allowed'] ?? 0) === 1;
    }
}

$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? $_SESSION['default_branch_id'] ?? 0);

if ($businessId <= 0 || $branchId <= 0) {
    http_response_code(403);
    die('A valid business and branch must be selected.');
}

if (!metalRateHistoryPermission($conn, 'open')) {
    http_response_code(403);
    die('Access denied. You do not have permission to open metal rate history.');
}

foreach (['metal_rate_history', 'metals'] as $requiredTable) {
    if (!tableExists($conn, $requiredTable)) {
        die("Required table `{$requiredTable}` was not found. Run the metal rate history SQL migration first.");
    }
}

$canView = metalRateHistoryPermission($conn, 'view') || metalRateHistoryPermission($conn, 'open');
$canViewValue = metalRateHistoryPermission($conn, 'value') || $canView;

$search = trim((string)($_GET['search'] ?? ''));
$metalId = max(0, (int)($_GET['metal_id'] ?? 0));
$purity = trim((string)($_GET['purity'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$changeType = trim((string)($_GET['change_type'] ?? 'all'));
$perPage = (int)($_GET['per_page'] ?? 20);
$page = max(1, (int)($_GET['page'] ?? 1));

if (!in_array($perPage, [10, 20, 50, 100], true)) {
    $perPage = 20;
}
if (!in_array($changeType, ['all', 'increase', 'decrease', 'same'], true)) {
    $changeType = 'all';
}
if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = '';
}
if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = '';
}
if ($purity !== '' && !is_numeric($purity)) {
    $purity = '';
}

$metals = [];
$stmt = $conn->prepare('SELECT id, metal_name, default_purity FROM metals WHERE business_id = ? AND is_active = 1 ORDER BY metal_name');
if ($stmt) {
    $stmt->bind_param('i', $businessId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $metals[] = $row;
    }
    $stmt->close();
}

$branches = [];
if (tableExists($conn, 'branches')) {
    $stmt = $conn->prepare('SELECT id, branch_name FROM branches WHERE business_id = ? ORDER BY branch_name');
    if ($stmt) {
        $stmt->bind_param('i', $businessId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $branches[(int)$row['id']] = $row['branch_name'];
        }
        $stmt->close();
    }
}

$where = [
    'h.business_id = ?',
    'h.branch_id = ?',
];
$types = 'ii';
$params = [$businessId, $branchId];

if ($metalId > 0) {
    $where[] = 'h.metal_id = ?';
    $types .= 'i';
    $params[] = $metalId;
}

if ($purity !== '') {
    $where[] = 'h.purity = ?';
    $types .= 'd';
    $params[] = (float)$purity;
}

if ($dateFrom !== '') {
    $where[] = 'DATE(h.effective_from) >= ?';
    $types .= 's';
    $params[] = $dateFrom;
}

if ($dateTo !== '') {
    $where[] = 'DATE(h.effective_from) <= ?';
    $types .= 's';
    $params[] = $dateTo;
}

if ($search !== '') {
    $like = '%' . $search . '%';
    $where[] = '(m.metal_name LIKE ? OR CAST(h.purity AS CHAR) LIKE ? OR CAST(h.rate_per_gram AS CHAR) LIKE ? OR COALESCE(u.full_name, u.username, \'\') LIKE ?)';
    $types .= 'ssss';
    array_push($params, $like, $like, $like, $like);
}

$baseSql = "FROM metal_rate_history h
            INNER JOIN metals m ON m.id = h.metal_id AND m.business_id = h.business_id
            LEFT JOIN users u ON u.id = h.updated_by
            LEFT JOIN metal_rates current_rate
              ON current_rate.business_id = h.business_id
             AND current_rate.branch_id = h.branch_id
             AND current_rate.metal_id = h.metal_id
             AND current_rate.purity = h.purity
             AND current_rate.is_current = 1
            WHERE " . implode(' AND ', $where);

$changeExpression = '(COALESCE(current_rate.rate_per_gram, h.rate_per_gram) - h.rate_per_gram)';
if ($changeType === 'increase') {
    $baseSql .= " AND {$changeExpression} > 0";
} elseif ($changeType === 'decrease') {
    $baseSql .= " AND {$changeExpression} < 0";
} elseif ($changeType === 'same') {
    $baseSql .= " AND {$changeExpression} = 0";
}

$countSql = 'SELECT COUNT(*) AS total ' . $baseSql;
$stmt = $conn->prepare($countSql);
if (!$stmt) {
    die('Unable to prepare history count query: ' . h($conn->error));
}
bindDynamic($stmt, $types, $params);
$stmt->execute();
$totalRows = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
$stmt->close();

$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$statsSql = "SELECT
                COUNT(*) AS history_count,
                COUNT(DISTINCT h.metal_id) AS metal_count,
                COUNT(DISTINCT h.purity) AS purity_count,
                COALESCE(AVG(h.rate_per_gram),0) AS average_old_rate,
                COALESCE(MIN(h.rate_per_gram),0) AS minimum_old_rate,
                COALESCE(MAX(h.rate_per_gram),0) AS maximum_old_rate,
                SUM(CASE WHEN {$changeExpression} > 0 THEN 1 ELSE 0 END) AS increase_count,
                SUM(CASE WHEN {$changeExpression} < 0 THEN 1 ELSE 0 END) AS decrease_count
             {$baseSql}";
$stmt = $conn->prepare($statsSql);
if (!$stmt) {
    die('Unable to prepare history statistics query: ' . h($conn->error));
}
bindDynamic($stmt, $types, $params);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();

$listSql = "SELECT
                h.id,
                h.business_id,
                h.branch_id,
                h.metal_id,
                m.metal_name,
                h.purity,
                h.rate_per_gram AS old_rate,
                h.effective_from AS old_effective_from,
                h.updated_by,
                COALESCE(u.full_name, u.username, 'System') AS updated_by_name,
                current_rate.id AS current_rate_id,
                current_rate.rate_per_gram AS current_rate,
                current_rate.effective_from AS current_effective_from,
                {$changeExpression} AS rate_difference,
                CASE
                    WHEN {$changeExpression} > 0 THEN 'Increase'
                    WHEN {$changeExpression} < 0 THEN 'Decrease'
                    ELSE 'No Change'
                END AS change_type,
                CASE
                    WHEN h.rate_per_gram > 0
                    THEN ({$changeExpression} / h.rate_per_gram) * 100
                    ELSE 0
                END AS change_percent
             {$baseSql}
             ORDER BY h.effective_from DESC, h.id DESC
             LIMIT ? OFFSET ?";

$listParams = $params;
$listParams[] = $perPage;
$listParams[] = $offset;
$listTypes = $types . 'ii';
$stmt = $conn->prepare($listSql);
if (!$stmt) {
    die('Unable to prepare history list query: ' . h($conn->error));
}
bindDynamic($stmt, $listTypes, $listParams);
$stmt->execute();
$historyRows = [];
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $historyRows[] = $row;
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
    $stmt = $conn->prepare('SELECT * FROM business_theme_settings WHERE business_id = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('i', $businessId);
        $stmt->execute();
        $themeRow = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        foreach ($theme as $key => $defaultValue) {
            if (isset($themeRow[$key]) && $themeRow[$key] !== '') {
                $theme[$key] = $themeRow[$key];
            }
        }
    }
}

function historyUrl(array $overrides = []): string
{
    $params = $_GET;
    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }
    return 'metal-rate-history.php' . ($params ? '?' . http_build_query($params) : '');
}

$pageTitle = 'Metal Rate History';
$page_title = 'Metal Rate History';
$currentPage = 'metal-rate-history';
$businessName = (string)($_SESSION['business_name'] ?? 'Jewellery ERP');
$currency = (string)($_SESSION['currency_symbol'] ?? '₹');
$fromRow = $totalRows > 0 ? $offset + 1 : 0;
$toRow = min($offset + $perPage, $totalRows);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo h($businessName); ?> - Metal Rate History</title>
<?php include 'includes/links.php'; ?>
<style>
:root{--primary:<?php echo h($theme['primary_color']); ?>;--primary-dark:<?php echo h($theme['primary_dark_color']); ?>;--primary-soft:<?php echo h($theme['primary_soft_color']); ?>;--page-bg:<?php echo h($theme['page_background']); ?>;--card-bg:<?php echo h($theme['card_background']); ?>;--text:<?php echo h($theme['text_color']); ?>;--muted:<?php echo h($theme['muted_text_color']); ?>;--line:<?php echo h($theme['border_color']); ?>;--radius:<?php echo (int)$theme['border_radius_px']; ?>px}
body{background:var(--page-bg);color:var(--text);font-family:<?php echo json_encode($theme['font_family']); ?>,sans-serif}.sidebar{background:linear-gradient(180deg,<?php echo h($theme['sidebar_gradient_1']); ?>,<?php echo h($theme['sidebar_gradient_2']); ?>,<?php echo h($theme['sidebar_gradient_3']); ?>)!important}.page-heading{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:10px}.page-title{font-family:<?php echo json_encode($theme['heading_font_family']); ?>,serif;font-size:18px;font-weight:800;margin:0}.page-subtitle{font-size:9px;color:var(--muted);margin-top:2px}.stat-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:9px;margin-bottom:10px}.stat-card,.panel{background:var(--card-bg);border:1px solid var(--line);border-radius:var(--radius)}.stat-card{padding:12px;display:flex;gap:10px;align-items:center;min-height:78px}.stat-icon{width:38px;height:38px;display:grid;place-items:center;border-radius:10px;background:var(--primary-soft);color:var(--primary-dark);flex:0 0 auto}.stat-label{font-size:8px;text-transform:uppercase;font-weight:700;color:var(--muted)}.stat-value{font-size:16px;font-weight:800;margin-top:3px}.panel{overflow:hidden;margin-bottom:10px}.filter-bar{padding:12px;border-bottom:1px solid var(--line)}.filter-grid{display:grid;grid-template-columns:1.7fr repeat(5,minmax(120px,1fr)) auto;gap:8px;align-items:end}.field-label{display:block;font-size:8px;text-transform:uppercase;font-weight:700;color:var(--muted);margin-bottom:4px}.form-control,.form-select{min-height:36px;font-size:10px;border-radius:9px;border-color:var(--line);background:var(--card-bg);color:var(--text)}.btn-theme{background:linear-gradient(135deg,var(--primary),var(--primary-dark));border:0;color:#fff}.btn-custom{min-height:36px;border-radius:9px;font-size:10px;font-weight:700;padding:8px 11px}.history-table{font-size:9px;margin:0}.history-table th{font-size:8px;text-transform:uppercase;letter-spacing:.03em;color:var(--muted);white-space:nowrap}.history-table td,.history-table th{padding:9px 10px;border-color:var(--line);background:var(--card-bg)!important;color:var(--text);vertical-align:middle}.main-text{font-size:10px;font-weight:800}.sub-text{font-size:8px;color:var(--muted);margin-top:2px}.metal-badge,.change-badge{display:inline-flex;align-items:center;padding:4px 8px;border-radius:999px;font-size:8px;font-weight:800}.metal-badge{background:var(--primary-soft);color:var(--primary-dark)}.change-increase{background:#eaf8f0;color:#168449}.change-decrease{background:#fdecec;color:#bd2d2d}.change-same{background:#edf1f5;color:#536170}.rate-old{font-weight:800}.rate-new{font-weight:800;color:#168449}.difference-up{color:#168449;font-weight:800}.difference-down{color:#bd2d2d;font-weight:800}.empty-state{padding:48px 20px;text-align:center;color:var(--muted)}.pagination-wrap{padding:10px 12px;border-top:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;gap:10px}.pagination-summary{font-size:9px;color:var(--muted)}.pagination{margin:0;gap:4px}.page-link{min-width:32px;height:32px;border-radius:8px!important;font-size:10px;display:grid;place-items:center;color:var(--text);background:var(--card-bg);border-color:var(--line)}.page-item.active .page-link{background:linear-gradient(135deg,var(--primary),var(--primary-dark));border-color:var(--primary);color:#fff}body.dark-mode,body[data-theme="dark"],html.dark-mode body,html[data-theme="dark"] body{--page-bg:#0f151b;--card-bg:#182129;--text:#f3f6f8;--muted:#9aa7b3;--line:#2c3944;color-scheme:dark}
@media(max-width:1300px){.stat-grid{grid-template-columns:repeat(3,1fr)}.filter-grid{grid-template-columns:repeat(4,1fr)}}
@media(max-width:991.98px){.stat-grid{grid-template-columns:repeat(2,1fr)}.filter-grid{grid-template-columns:repeat(2,1fr)}.responsive-table thead{display:none}.responsive-table tbody{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;padding:10px}.responsive-table tr{display:grid;grid-template-columns:1fr 1fr;border:1px solid var(--line);border-radius:var(--radius);padding:12px;background:var(--card-bg)}.responsive-table td{display:flex;justify-content:space-between;gap:10px;padding:8px 0;border:0;border-bottom:1px dashed var(--line);text-align:right}.responsive-table td:before{content:attr(data-label);font-size:8px;color:var(--muted);font-weight:700;text-transform:uppercase;text-align:left}.responsive-table td.main-column{grid-column:1/-1;display:block;text-align:left}.responsive-table td.main-column:before{display:none}}
@media(max-width:600px){.page-heading{align-items:flex-start;flex-direction:column}.stat-grid,.filter-grid,.responsive-table tbody{grid-template-columns:1fr}.responsive-table tr{grid-template-columns:1fr}.responsive-table td{grid-column:1/-1}.pagination-wrap{flex-direction:column;align-items:flex-start}}
@media print{.sidebar,.navbar-header,.filter-bar,.page-heading .btn,.pagination-wrap,.footer{display:none!important}.app-main{margin-left:0!important;width:100%!important}.content-wrap{padding:0!important}.stat-card,.panel{border:1px solid #ddd!important}.history-table{font-size:8px}}
</style>
</head>
<body>
<?php include 'includes/sidebar.php'; ?>
<main class="app-main">
<?php include 'includes/nav.php'; ?>
<div class="content-wrap">
    <div class="page-heading">
        <div>
            <h1 class="page-title">Metal Rate History</h1>
            <div class="page-subtitle"><?php echo h($businessName); ?> · Previous metal rates archived whenever rates are changed</div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <button type="button" class="btn btn-light btn-custom" onclick="window.print()"><i class="fa-solid fa-print me-1"></i>Print</button>
            <a href="metal-rates.php" class="btn btn-theme btn-custom"><i class="fa-solid fa-coins me-1"></i>Metal Rates</a>
        </div>
    </div>

    <?php if (!$canView): ?>
        <div class="panel"><div class="empty-state">You do not have permission to view metal rate history.</div></div>
    <?php else: ?>
        <div class="stat-grid">
            <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-clock-rotate-left"></i></div><div><div class="stat-label">History Records</div><div class="stat-value"><?php echo (int)($stats['history_count'] ?? 0); ?></div></div></div>
            <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-layer-group"></i></div><div><div class="stat-label">Metal Types</div><div class="stat-value"><?php echo (int)($stats['metal_count'] ?? 0); ?></div></div></div>
            <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-arrow-trend-up"></i></div><div><div class="stat-label">Rate Increases</div><div class="stat-value"><?php echo (int)($stats['increase_count'] ?? 0); ?></div></div></div>
            <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-arrow-trend-down"></i></div><div><div class="stat-label">Rate Decreases</div><div class="stat-value"><?php echo (int)($stats['decrease_count'] ?? 0); ?></div></div></div>
            <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-indian-rupee-sign"></i></div><div><div class="stat-label">Average Old Rate</div><div class="stat-value"><?php echo $canViewValue ? h($currency) . number_format((float)($stats['average_old_rate'] ?? 0), 2) : '••••'; ?></div></div></div>
        </div>

        <div class="panel">
            <form method="get" action="metal-rate-history.php" class="filter-bar">
                <div class="filter-grid">
                    <div><label class="field-label">Search</label><input type="search" name="search" class="form-control" value="<?php echo h($search); ?>" placeholder="Metal, purity, rate or updated user"></div>
                    <div><label class="field-label">Metal</label><select name="metal_id" class="form-select"><option value="0">All Metals</option><?php foreach ($metals as $metal): ?><option value="<?php echo (int)$metal['id']; ?>" <?php echo $metalId === (int)$metal['id'] ? 'selected' : ''; ?>><?php echo h($metal['metal_name']); ?></option><?php endforeach; ?></select></div>
                    <div><label class="field-label">Purity</label><input type="number" step="0.0001" name="purity" class="form-control" value="<?php echo h($purity); ?>" placeholder="All purities"></div>
                    <div><label class="field-label">Change</label><select name="change_type" class="form-select"><option value="all" <?php echo $changeType === 'all' ? 'selected' : ''; ?>>All Changes</option><option value="increase" <?php echo $changeType === 'increase' ? 'selected' : ''; ?>>Increase</option><option value="decrease" <?php echo $changeType === 'decrease' ? 'selected' : ''; ?>>Decrease</option><option value="same" <?php echo $changeType === 'same' ? 'selected' : ''; ?>>No Change</option></select></div>
                    <div><label class="field-label">From Date</label><input type="date" name="date_from" class="form-control" value="<?php echo h($dateFrom); ?>"></div>
                    <div><label class="field-label">To Date</label><input type="date" name="date_to" class="form-control" value="<?php echo h($dateTo); ?>"></div>
                    <div class="d-flex gap-2"><button type="submit" class="btn btn-theme btn-custom"><i class="fa-solid fa-filter me-1"></i>Apply</button><a href="metal-rate-history.php" class="btn btn-light btn-custom">Reset</a></div>
                </div>
                <input type="hidden" name="per_page" value="<?php echo $perPage; ?>">
            </form>

            <?php if (!$historyRows): ?>
                <div class="empty-state"><i class="fa-regular fa-folder-open fa-2x mb-2"></i><div>No metal rate history found for the selected filters.</div></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table history-table responsive-table">
                        <thead><tr><th>#</th><th>Metal / Purity</th><th>Old Rate</th><th>Current Rate</th><th>Difference</th><th>Change</th><th>Old Effective From</th><th>Current Effective From</th><th>Updated By</th><th>Branch</th></tr></thead>
                        <tbody>
                        <?php foreach ($historyRows as $index => $row): ?>
                            <?php
                            $difference = (float)$row['rate_difference'];
                            $changeClass = $row['change_type'] === 'Increase' ? 'change-increase' : ($row['change_type'] === 'Decrease' ? 'change-decrease' : 'change-same');
                            $differenceClass = $difference > 0 ? 'difference-up' : ($difference < 0 ? 'difference-down' : '');
                            ?>
                            <tr>
                                <td data-label="#"><?php echo $offset + $index + 1; ?></td>
                                <td class="main-column" data-label="Metal / Purity"><div class="main-text"><span class="metal-badge"><?php echo h($row['metal_name']); ?></span></div><div class="sub-text">Purity: <?php echo h(number_format((float)$row['purity'], 4, '.', '')); ?></div></td>
                                <td data-label="Old Rate"><div class="rate-old"><?php echo $canViewValue ? h($currency) . number_format((float)$row['old_rate'], 2) : '••••'; ?></div><div class="sub-text">8g: <?php echo $canViewValue ? h($currency) . number_format((float)$row['old_rate'] * 8, 2) : '••••'; ?></div></td>
                                <td data-label="Current Rate"><div class="rate-new"><?php echo $canViewValue ? h($currency) . number_format((float)($row['current_rate'] ?? $row['old_rate']), 2) : '••••'; ?></div><div class="sub-text">8g: <?php echo $canViewValue ? h($currency) . number_format((float)($row['current_rate'] ?? $row['old_rate']) * 8, 2) : '••••'; ?></div></td>
                                <td data-label="Difference" class="<?php echo $differenceClass; ?>"><?php echo $canViewValue ? (($difference > 0 ? '+' : '') . h($currency) . number_format($difference, 2)) : '••••'; ?><div class="sub-text"><?php echo number_format((float)$row['change_percent'], 2); ?>%</div></td>
                                <td data-label="Change"><span class="change-badge <?php echo $changeClass; ?>"><?php echo h($row['change_type']); ?></span></td>
                                <td data-label="Old Effective From"><div class="main-text"><?php echo h(date('d-m-Y', strtotime($row['old_effective_from']))); ?></div><div class="sub-text"><?php echo h(date('h:i A', strtotime($row['old_effective_from']))); ?></div></td>
                                <td data-label="Current Effective From"><?php if (!empty($row['current_effective_from'])): ?><div class="main-text"><?php echo h(date('d-m-Y', strtotime($row['current_effective_from']))); ?></div><div class="sub-text"><?php echo h(date('h:i A', strtotime($row['current_effective_from']))); ?></div><?php else: ?>—<?php endif; ?></td>
                                <td data-label="Updated By"><div class="main-text"><?php echo h($row['updated_by_name']); ?></div><div class="sub-text">User ID: <?php echo (int)$row['updated_by']; ?></div></td>
                                <td data-label="Branch"><?php echo h($branches[(int)$row['branch_id']] ?? ('Branch #' . (int)$row['branch_id'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="pagination-wrap">
                    <div class="pagination-summary">Showing <?php echo $fromRow; ?>–<?php echo $toRow; ?> of <?php echo $totalRows; ?> history records</div>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <form method="get" action="metal-rate-history.php" class="d-flex align-items-center gap-2">
                            <?php foreach ($_GET as $key => $value): if (in_array($key, ['per_page', 'page'], true)) continue; ?><input type="hidden" name="<?php echo h($key); ?>" value="<?php echo h($value); ?>"><?php endforeach; ?>
                            <select name="per_page" class="form-select" style="width:auto;min-height:32px" onchange="this.form.submit()">
                                <?php foreach ([10,20,50,100] as $size): ?><option value="<?php echo $size; ?>" <?php echo $perPage === $size ? 'selected' : ''; ?>><?php echo $size; ?> rows</option><?php endforeach; ?>
                            </select>
                        </form>
                        <ul class="pagination pagination-sm">
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo h(historyUrl(['page' => max(1, $page - 1)])); ?>"><i class="fa-solid fa-angle-left"></i></a></li>
                            <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
                                <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>"><a class="page-link" href="<?php echo h(historyUrl(['page' => $p])); ?>"><?php echo $p; ?></a></li>
                            <?php endfor; ?>
                            <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo h(historyUrl(['page' => min($totalPages, $page + 1)])); ?>"><i class="fa-solid fa-angle-right"></i></a></li>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php include 'includes/footer.php'; ?>
</div>
</main>
<?php include 'includes/script.php'; ?>
<script src="assets/js/script.js"></script>
</body>
</html>