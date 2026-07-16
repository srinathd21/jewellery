<?php
/* customers1.php - overflow and URL pagination corrected */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string) ($_SESSION['timezone'] ?? 'Asia/Kolkata'));

$configCandidates = [
    __DIR__ . '/config/config.php',
    __DIR__ . '/config.php',
    __DIR__ . '/includes/config.php',
    __DIR__ . '/super-admin/includes/config.php',
];
foreach ($configCandidates as $configFile) {
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

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
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

if (!function_exists('hasColumn')) {
    function hasColumn(mysqli $conn, string $table, string $column): bool
    {
        $safeTable = $conn->real_escape_string($table);
        $safeColumn = $conn->real_escape_string($column);
        $result = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
        return $result && $result->num_rows > 0;
    }
}

function customerPermission(mysqli $conn, string $action): bool
{
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin') {
        return true;
    }

    $map = [
        'open' => 'can_open',
        'view' => 'can_view',
        'value' => 'can_view_value',
        'create' => 'can_create',
        'update' => 'can_update',
        'delete' => 'can_delete',
    ];
    $field = $map[$action] ?? '';
    if ($field === '') {
        return false;
    }

    $sessionPermissions = $_SESSION['permissions'] ?? [];
    foreach (['perm.customers.list', 'perm.customers', 'perm.billing.customers'] as $permissionCode) {
        if (isset($sessionPermissions[$permissionCode][$field])) {
            return (int) $sessionPermissions[$permissionCode][$field] === 1;
        }
    }

    $businessId = (int) ($_SESSION['business_id'] ?? 0);
    $roleId = (int) ($_SESSION['role_id'] ?? 0);
    if ($businessId > 0 && $roleId > 0 && tableExists($conn, 'permissions') && tableExists($conn, 'role_permissions')) {
        $sql = "SELECT rp.`{$field}`
                FROM role_permissions rp
                INNER JOIN permissions p ON p.id = rp.permission_id
                WHERE rp.business_id = ?
                  AND rp.role_id = ?
                  AND p.is_active = 1
                  AND p.permission_code IN ('perm.customers.list','perm.customers','perm.billing.customers')
                ORDER BY FIELD(p.permission_code,'perm.customers.list','perm.customers','perm.billing.customers')
                LIMIT 1";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('ii', $businessId, $roleId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                return (int) ($row[$field] ?? 0) === 1;
            }
        }
    }

    $roleName = strtolower(trim((string) ($_SESSION['role_name'] ?? '')));
    $roleCode = strtolower(trim((string) ($_SESSION['role_code'] ?? '')));
    return in_array($roleName, ['admin', 'business admin', 'manager', 'billing'], true)
        || in_array($roleCode, ['admin', 'business_admin', 'manager', 'billing'], true);
}

if (!customerPermission($conn, 'open')) {
    http_response_code(403);
    die('Access denied. You do not have permission to open customers.');
}

$canView = customerPermission($conn, 'view') || customerPermission($conn, 'open');
$canViewValue = customerPermission($conn, 'value') || $canView;
$canCreate = customerPermission($conn, 'create');
$canUpdate = customerPermission($conn, 'update');
$canDelete = customerPermission($conn, 'delete');

$businessId = (int) ($_SESSION['business_id'] ?? 0);
if ($businessId <= 0) {
    http_response_code(403);
    die('A valid business must be selected.');
}
if (!tableExists($conn, 'customers')) {
    die('Customers table was not found.');
}

if (empty($_SESSION['customers_csrf'])) {
    $_SESSION['customers_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['customers_csrf'];

$service = strtolower(trim((string) ($_GET['service'] ?? $_GET['type'] ?? 'all')));
if (!in_array($service, ['all', 'billing', 'pawn', 'chit'], true)) {
    $service = 'all';
}
$serviceMap = [
    'all' => ['db' => '', 'label' => 'All Customers'],
    'billing' => ['db' => 'Billing', 'label' => 'Billing Customers'],
    'pawn' => ['db' => 'Pawn', 'label' => 'Pawn Broking Customers'],
    'chit' => ['db' => 'Chit', 'label' => 'Chit Customers'],
];
$pageLabel = $serviceMap[$service]['label'];
$dbService = $serviceMap[$service]['db'];

$hasServicesTable = tableExists($conn, 'customer_services');
$hasCode = hasColumn($conn, 'customers', 'customer_code');
$hasMobile = hasColumn($conn, 'customers', 'mobile');
$hasAlternate = hasColumn($conn, 'customers', 'alternate_mobile');
$hasEmail = hasColumn($conn, 'customers', 'email');
$hasGstin = hasColumn($conn, 'customers', 'gstin');
$hasCity = hasColumn($conn, 'customers', 'city');
$hasState = hasColumn($conn, 'customers', 'state');
$hasOpening = hasColumn($conn, 'customers', 'opening_balance');
$hasBalanceType = hasColumn($conn, 'customers', 'balance_type');
$hasActive = hasColumn($conn, 'customers', 'is_active');
$hasCreatedAt = hasColumn($conn, 'customers', 'created_at');

function bindAndExecute(mysqli_stmt $stmt, string $types, array $params): void
{
    if ($types !== '') {
        $bind = [$types];
        foreach ($params as $index => $value) {
            $bind[] = &$params[$index];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind);
    }
    $stmt->execute();
}

$serviceCondition = '';
$serviceTypes = 'i';
$serviceParams = [$businessId];
if ($service !== 'all') {
    if ($hasServicesTable) {
        $serviceCondition = " AND EXISTS (SELECT 1 FROM customer_services csf WHERE csf.customer_id=c.id AND csf.business_id=c.business_id AND csf.service_type=? AND csf.is_active=1)";
        $serviceTypes .= 's';
        $serviceParams[] = $dbService;
    } else {
        $serviceCondition = ' AND c.customer_type IN (?,\'All\')';
        $serviceTypes .= 's';
        $serviceParams[] = $dbService;
    }
}

function customerAggregate(mysqli $conn, string $expression, string $serviceCondition, string $types, array $params)
{
    $sql = "SELECT {$expression} AS value FROM customers c WHERE c.business_id=?{$serviceCondition}";
    $stmt = $conn->prepare($sql);
    if (!$stmt)
        return 0;
    bindAndExecute($stmt, $types, $params);
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return $row['value'] ?? 0;
}

$totalCustomers = (int) customerAggregate($conn, 'COUNT(*)', $serviceCondition, $serviceTypes, $serviceParams);
$activeCustomers = $hasActive ? (int) customerAggregate($conn, 'SUM(c.is_active=1)', $serviceCondition, $serviceTypes, $serviceParams) : $totalCustomers;
$inactiveCustomers = $hasActive ? (int) customerAggregate($conn, 'SUM(c.is_active=0)', $serviceCondition, $serviceTypes, $serviceParams) : 0;
$totalOpeningBalance = $hasOpening ? (float) customerAggregate($conn, 'COALESCE(SUM(c.opening_balance),0)', $serviceCondition, $serviceTypes, $serviceParams) : 0.0;

$search = trim((string) ($_GET['search'] ?? ''));
$status = strtolower(trim((string) ($_GET['status'] ?? 'all')));
$cityFilter = trim((string) ($_GET['city'] ?? ''));
$stateFilter = trim((string) ($_GET['state'] ?? ''));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$perPage = (int) ($_GET['per_page'] ?? 10);
if (!in_array($perPage, [10, 25, 50, 100], true)) {
    $perPage = 10;
}
$page = max(1, (int) ($_GET['page'] ?? 1));

$where = ' WHERE c.business_id=?' . $serviceCondition;
$types = $serviceTypes;
$params = $serviceParams;

if ($search !== '') {
    $parts = ['c.customer_name LIKE ?'];
    if ($hasCode)
        $parts[] = 'c.customer_code LIKE ?';
    if ($hasMobile)
        $parts[] = 'c.mobile LIKE ?';
    if ($hasAlternate)
        $parts[] = 'c.alternate_mobile LIKE ?';
    if ($hasEmail)
        $parts[] = 'c.email LIKE ?';
    if ($hasGstin)
        $parts[] = 'c.gstin LIKE ?';
    if ($hasCity)
        $parts[] = 'c.city LIKE ?';
    if ($hasState)
        $parts[] = 'c.state LIKE ?';
    $like = '%' . $search . '%';
    foreach ($parts as $_) {
        $types .= 's';
        $params[] = $like;
    }
    $where .= ' AND (' . implode(' OR ', $parts) . ')';
}
if ($hasActive && $status === 'active')
    $where .= ' AND c.is_active=1';
if ($hasActive && $status === 'inactive')
    $where .= ' AND c.is_active=0';
if ($hasCity && $cityFilter !== '') {
    $where .= ' AND c.city=?';
    $types .= 's';
    $params[] = $cityFilter;
}
if ($hasState && $stateFilter !== '') {
    $where .= ' AND c.state=?';
    $types .= 's';
    $params[] = $stateFilter;
}
if ($hasCreatedAt && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $where .= ' AND DATE(c.created_at)>=?';
    $types .= 's';
    $params[] = $dateFrom;
}
if ($hasCreatedAt && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $where .= ' AND DATE(c.created_at)<=?';
    $types .= 's';
    $params[] = $dateTo;
}

$cities = [];
if ($hasCity) {
    $stmt = $conn->prepare("SELECT DISTINCT city FROM customers WHERE business_id=? AND city IS NOT NULL AND city<>'' ORDER BY city");
    if ($stmt) {
        $stmt->bind_param('i', $businessId);
        $stmt->execute();
        $r = $stmt->get_result();
        while ($x = $r->fetch_assoc())
            $cities[] = $x['city'];
        $stmt->close();
    }
}
$states = [];
if ($hasState) {
    $stmt = $conn->prepare("SELECT DISTINCT state FROM customers WHERE business_id=? AND state IS NOT NULL AND state<>'' ORDER BY state");
    if ($stmt) {
        $stmt->bind_param('i', $businessId);
        $stmt->execute();
        $r = $stmt->get_result();
        while ($x = $r->fetch_assoc())
            $states[] = $x['state'];
        $stmt->close();
    }
}

$select = ['c.id', 'c.customer_name'];
foreach ([
    'customer_code' => $hasCode,
    'mobile' => $hasMobile,
    'alternate_mobile' => $hasAlternate,
    'email' => $hasEmail,
    'gstin' => $hasGstin,
    'city' => $hasCity,
    'state' => $hasState,
    'opening_balance' => $hasOpening,
    'balance_type' => $hasBalanceType,
    'is_active' => $hasActive,
    'created_at' => $hasCreatedAt
] as $field => $enabled)
    if ($enabled)
        $select[] = 'c.' . $field;
if ($hasServicesTable) {
    $select[] = "GROUP_CONCAT(DISTINCT CASE WHEN cs.is_active=1 THEN cs.service_type END ORDER BY FIELD(cs.service_type,'Billing','Pawn','Chit') SEPARATOR ',') AS services";
    $join = ' LEFT JOIN customer_services cs ON cs.customer_id=c.id AND cs.business_id=c.business_id';
} else {
    $select[] = "c.customer_type AS services";
    $join = '';
}
$countSql = 'SELECT COUNT(DISTINCT c.id) AS total FROM customers c' . $join . $where;
$countStmt = $conn->prepare($countSql);
if (!$countStmt) {
    die('Unable to prepare customer count: ' . e($conn->error));
}
bindAndExecute($countStmt, $types, $params);
$totalFilteredCustomers = (int) (($countStmt->get_result()->fetch_assoc()['total'] ?? 0));
$countStmt->close();

$totalPages = max(1, (int) ceil($totalFilteredCustomers / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$sql = 'SELECT ' . implode(', ', $select) . ' FROM customers c' . $join . $where . ' GROUP BY c.id ORDER BY c.id DESC LIMIT ? OFFSET ?';
$stmt = $conn->prepare($sql);
if (!$stmt) {
    die('Unable to prepare customer list: ' . e($conn->error));
}
$listTypes = $types . 'ii';
$listParams = $params;
$listParams[] = $perPage;
$listParams[] = $offset;
bindAndExecute($stmt, $listTypes, $listParams);
$customers = [];
$result = $stmt->get_result();
while ($result && $row = $result->fetch_assoc()) {
    $customers[] = $row;
}
$stmt->close();

function customerPageUrl(int $targetPage): string
{
    $query = $_GET;
    $query['page'] = max(1, $targetPage);
    if ((int) ($query['page'] ?? 1) === 1) {
        unset($query['page']);
    }
    return 'customers1.php' . ($query ? '?' . http_build_query($query) : '');
}

$paginationFrom = $totalFilteredCustomers > 0 ? $offset + 1 : 0;
$paginationTo = min($offset + $perPage, $totalFilteredCustomers);
$filterActive = $search !== '' || $status !== 'all' || $service !== 'all' || $cityFilter !== '' || $stateFilter !== '' || $dateFrom !== '' || $dateTo !== '' || $perPage !== 10;

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
    $stmt = $conn->prepare('SELECT * FROM business_theme_settings WHERE business_id = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('i', $businessId);
        $stmt->execute();
        $themeRow = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        foreach ($theme as $key => $default) {
            if (isset($themeRow[$key]) && $themeRow[$key] !== '') {
                $theme[$key] = $themeRow[$key];
            }
        }
    }
}

$pageTitle = $pageLabel;
$businessName = (string) ($_SESSION['business_name'] ?? 'Jewellery ERP');
$currency = (string) ($_SESSION['currency_symbol'] ?? '₹');
$flashMessage = [
    'created' => 'Customer created successfully.',
    'updated' => 'Customer updated successfully.',
    'deleted' => 'Customer deleted successfully.',
    'status_changed' => 'Customer status changed successfully.',
][(string) ($_GET['msg'] ?? '')] ?? '';
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo e($businessName . ' - ' . $pageLabel); ?></title>
    <?php include('includes/links.php'); ?>
    <style>
        :root {
            --primary: <?php echo e($theme['primary_color']); ?>;
            --primary-dark: <?php echo e($theme['primary_dark_color']); ?>;
            --primary-soft: <?php echo e($theme['primary_soft_color']); ?>;
            --sidebar-gradient-1: <?php echo e($theme['sidebar_gradient_1']); ?>;
            --sidebar-gradient-2: <?php echo e($theme['sidebar_gradient_2']); ?>;
            --sidebar-gradient-3: <?php echo e($theme['sidebar_gradient_3']); ?>;
            --page-bg: <?php echo e($theme['page_background']); ?>;
            --card-bg: <?php echo e($theme['card_background']); ?>;
            --text-color: <?php echo e($theme['text_color']); ?>;
            --muted-color: <?php echo e($theme['muted_text_color']); ?>;
            --border-color: <?php echo e($theme['border_color']); ?>;
            --radius: <?php echo (int) $theme['border_radius_px']; ?>px
        }

        body {
            background: var(--page-bg);
            color: var(--text-color);
            font-family: <?php echo json_encode((string) $theme['font_family']); ?>, sans-serif
        }

        .sidebar {
            background: linear-gradient(180deg, var(--sidebar-gradient-1), var(--sidebar-gradient-2), var(--sidebar-gradient-3)) !important
        }

        .page-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 10px
        }

        .page-heading h1 {
            font-family: <?php echo json_encode((string) $theme['heading_font_family']); ?>, serif;
            font-size: 25px;
            margin: 0;
            font-weight: 800
        }

        .page-heading p {
            font-size: 10px;
            color: var(--muted-color);
            margin: 3px 0 0
        }

        .stat-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 10px;
            margin-bottom: 10px
        }

        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 14px 16px;
            min-height: 84px;
            display: flex;
            align-items: center;
            gap: 13px
        }

        .stat-icon {
            width: 44px;
            height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: calc(var(--radius)*.75);
            background: var(--primary-soft);
            color: var(--primary-dark);
            font-size: 16px
        }

        .stat-label {
            font-size: 10px;
            color: var(--muted-color)
        }

        .stat-value {
            font-size: 22px;
            line-height: 1.1;
            font-weight: 800;
            margin-top: 4px
        }

        .toolbar {
            display: block;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 11px 13px;
            margin-bottom: 10px;
            overflow: hidden
        }

        .customer-filter-grid {
            display: grid;
            grid-template-columns: minmax(240px, 1.8fr) repeat(3, minmax(125px, 1fr));
            gap: 8px;
            align-items: center;
            width: 100%
        }

        .customer-filter-grid .search-box {
            min-width: 0
        }

        .customer-filter-grid .form-control,
        .customer-filter-grid .form-select {
            width: 100%;
            height: 38px;
            margin: 0
        }

        .customer-filter-grid .btn {
            height: 38px;
            min-width: 82px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            white-space: nowrap;
            margin: 0
        }


        .filter-actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 8px;
            flex-wrap: nowrap;
            min-width: 0
        }

        .filter-actions-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid var(--border-color)
        }

        .filter-result-note {
            min-width: 0;
            font-size: 10px;
            color: var(--muted-color)
        }

        .filter-actions .btn {
            flex: 0 0 auto
        }

        .pagination-wrap {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 11px 13px;
            border-top: 1px solid var(--border-color)
        }

        .pagination-summary {
            font-size: 10px;
            color: var(--muted-color)
        }

        .pagination {
            margin: 0;
            gap: 4px
        }

        .page-link {
            min-width: 32px;
            height: 32px;
            padding: 0 9px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px !important;
            border-color: var(--border-color);
            background: var(--card-bg);
            color: var(--text-color);
            font-size: 10px
        }

        .page-item.active .page-link {
            color: #fff;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border-color: var(--primary)
        }

        .search-box {
            position: relative;
            min-width: 300px
        }

        .search-box i {
            position: absolute;
            left: 11px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--muted-color);
            font-size: 11px
        }

        .search-box input {
            padding-left: 32px
        }

        .form-control,
        .form-select {
            font-size: 11px;
            min-height: 36px;
            border-radius: 9px;
            border-color: var(--border-color);
            background: var(--card-bg);
            color: var(--text-color)
        }

        .btn-theme {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border: 0;
            color: #fff;
            border-radius: calc(var(--radius)*.65);
            font-size: 11px;
            font-weight: 700;
            padding: 9px 14px
        }

        .btn-theme:hover {
            color: #fff;
            filter: brightness(.97)
        }

        .table-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            overflow: hidden
        }

        .customer-table {
            margin: 0;
            font-size: 11px
        }

        .customer-table th {
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: var(--muted-color);
            background: color-mix(in srgb, var(--muted-color) 6%, transparent);
            white-space: nowrap;
            padding: 10px 11px;
            border-color: var(--border-color)
        }

        .customer-table td {
            padding: 10px 11px;
            vertical-align: middle;
            color: var(--text-color);
            background: var(--card-bg) !important;
            border-color: var(--border-color)
        }

        .main-text {
            font-size: 11px;
            font-weight: 800
        }

        .sub-text {
            font-size: 9px;
            color: var(--muted-color);
            margin-top: 2px
        }

        .badge-soft {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 4px 8px;
            font-size: 9px;
            font-weight: 700
        }

        .service-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 4px
        }

        .service-billing {
            background: #eaf2ff;
            color: #2563eb
        }

        .service-pawn {
            background: #fff3e6;
            color: #c26500
        }

        .service-chit {
            background: #eaf8f0;
            color: #168449
        }

        .active-badge {
            background: #eaf8f0;
            color: #168449
        }

        .inactive-badge {
            background: #fdecec;
            color: #bd2d2d
        }

        .action-btn {
            width: 30px;
            height: 30px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--card-bg);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            color: var(--text-color)
        }

        .action-btn:hover {
            background: var(--primary-soft);
            color: var(--primary-dark)
        }

        .action-btn.danger:hover {
            background: #fdecec;
            color: #bd2d2d
        }

        .empty-state {
            padding: 50px 20px;
            text-align: center;
            color: var(--muted-color)
        }

        .theme-toast {
            position: fixed;
            right: 18px;
            top: 78px;
            z-index: 20000;
            display: flex;
            align-items: center;
            gap: 9px;
            min-width: 260px;
            max-width: 420px;
            padding: 11px 14px;
            border-radius: 10px;
            color: #fff;
            font-size: 11px;
            font-weight: 600;
            box-shadow: 0 14px 35px rgba(0, 0, 0, .22);
            opacity: 0;
            transform: translateY(-10px);
            transition: .22s
        }

        .theme-toast.show {
            opacity: 1;
            transform: translateY(0)
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
            --text-color: #f3f6f8;
            --muted-color: #9aa7b3;
            --border-color: #2c3944
        }

        @media(max-width:1350px) {
            .customer-filter-grid {
                grid-template-columns: minmax(240px, 1fr) repeat(3, minmax(130px, .7fr));
            }

            .customer-filter-grid .btn {
                width: auto
            }
        }

        @media(max-width:1100px) {
            .stat-grid {
                grid-template-columns: repeat(2, 1fr)
            }
        }

        @media(max-width:991.98px) {
            .customer-filter-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr))
            }

            .customer-filter-grid .search-box {
                grid-column: 1 / -1
            }

            .filter-actions-row {
                align-items: flex-start
            }

            .filter-actions {
                justify-content: flex-end;
                flex-wrap: wrap
            }

            .search-box {
                min-width: 0
            }

            .table-card {
                background: transparent;
                border: 0;
                overflow: visible
            }

            .table-responsive {
                overflow: visible
            }

            .customer-table {
                display: block
            }

            .customer-table thead {
                display: none
            }

            .customer-table tbody {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 12px
            }

            .customer-table tbody tr {
                display: grid;
                grid-template-columns: 1fr 1fr;
                background: var(--card-bg);
                border: 1px solid var(--border-color);
                border-radius: var(--radius);
                padding: 14px
            }

            .customer-table tbody td {
                display: flex;
                align-items: flex-start;
                justify-content: space-between;
                gap: 12px;
                padding: 9px 0;
                border: 0;
                border-bottom: 1px dashed var(--border-color);
                text-align: right !important
            }

            .customer-table tbody td::before {
                content: attr(data-label);
                font-size: 9px;
                font-weight: 700;
                text-transform: uppercase;
                color: var(--muted-color);
                text-align: left
            }

            .customer-table tbody td.customer-column {
                grid-column: 1/-1;
                display: block;
                text-align: left !important
            }

            .customer-table tbody td.customer-column::before {
                display: none
            }

            .customer-table tbody td.actions-column {
                grid-column: 1/-1;
                border-bottom: 0;
                padding-top: 12px
            }
        }

        @media(max-width:767.98px) {
            .stat-grid {
                grid-template-columns: 1fr 1fr
            }

            .customer-filter-grid {
                grid-template-columns: 1fr
            }

            .customer-filter-grid .search-box {
                grid-column: auto
            }

            .filter-actions-row {
                flex-direction: column;
                align-items: stretch
            }

            .filter-result-note {
                display: none
            }

            .filter-actions {
                display: grid;
                grid-template-columns: 1fr;
                width: 100%
            }

            .filter-actions .btn {
                width: 100%
            }

            .pagination-wrap {
                align-items: flex-start;
                flex-direction: column
            }

            .customer-table tbody {
                grid-template-columns: 1fr
            }

            .customer-table tbody tr {
                grid-template-columns: 1fr
            }

            .customer-table tbody td {
                grid-column: 1/-1
            }

            .page-heading {
                align-items: flex-start;
                flex-direction: column
            }

            .theme-toast {
                left: 12px;
                right: 12px;
                top: 70px;
                min-width: 0;
                max-width: none
            }
        }
    </style>
</head>

<body>
    <?php include('includes/sidebar.php'); ?>
    <main class="app-main">
        <?php include('includes/nav.php'); ?>
        <div class="content-wrap">


            <div class="stat-grid">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fa-solid fa-users"></i></div>
                    <div>
                        <div class="stat-label">Total Customers</div>
                        <div class="stat-value"><?php echo $totalCustomers; ?></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fa-solid fa-circle-check"></i></div>
                    <div>
                        <div class="stat-label">Active Customers</div>
                        <div class="stat-value"><?php echo $activeCustomers; ?></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fa-solid fa-circle-xmark"></i></div>
                    <div>
                        <div class="stat-label">Inactive Customers</div>
                        <div class="stat-value"><?php echo $inactiveCustomers; ?></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fa-solid fa-wallet"></i></div>
                    <div>
                        <div class="stat-label">Opening Balance</div>
                        <div class="stat-value">
                            <?php echo $canViewValue ? e($currency) . number_format($totalOpeningBalance, 2) : '••••'; ?>
                        </div>
                    </div>
                </div>
            </div>

            <form class="toolbar" method="get" action="customers1.php">
                <div class="customer-filter-grid">
                    <div class="search-box"><i class="fa-solid fa-magnifying-glass"></i><input type="search"
                            name="search" class="form-control" placeholder="Search name, code, mobile, GSTIN, city..."
                            value="<?php echo e($search); ?>"></div>
                    <select name="service" class="form-select">
                        <option value="all" <?php echo $service === 'all' ? 'selected' : ''; ?>>All services</option>
                        <option value="billing" <?php echo $service === 'billing' ? 'selected' : ''; ?>>Billing</option>
                        <option value="pawn" <?php echo $service === 'pawn' ? 'selected' : ''; ?>>Pawn</option>
                        <option value="chit" <?php echo $service === 'chit' ? 'selected' : ''; ?>>Chit</option>
                    </select>
                    <select name="status" class="form-select">
                        <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All status</option>
                        <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                    <select name="city" class="form-select">
                        <option value="">All cities</option><?php foreach ($cities as $city): ?>
                            <option value="<?php echo e($city); ?>" <?php echo $cityFilter === $city ? 'selected' : ''; ?>>
                                <?php echo e($city); ?></option><?php endforeach; ?>
                    </select>
                    <select name="state" class="form-select">
                        <option value="">All states</option><?php foreach ($states as $state): ?>
                            <option value="<?php echo e($state); ?>" <?php echo $stateFilter === $state ? 'selected' : ''; ?>>
                                <?php echo e($state); ?></option><?php endforeach; ?>
                    </select>
                    <input type="date" name="date_from" class="form-control" value="<?php echo e($dateFrom); ?>"
                        title="Created from">
                    <input type="date" name="date_to" class="form-control" value="<?php echo e($dateTo); ?>"
                        title="Created to">
                    <select name="per_page" class="form-select" title="Rows per page">
                        <?php foreach ([10, 25, 50, 100] as $rowsPerPage): ?>
                            <option value="<?php echo $rowsPerPage; ?>" <?php echo $perPage === $rowsPerPage ? 'selected' : ''; ?>><?php echo $rowsPerPage; ?> rows</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-actions-row">
                    <div class="filter-result-note">Use filters and click Apply to update the customer list.</div>
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-light btn-sm"><i class="fa-solid fa-filter me-1"></i>Apply</button>
                        <?php if ($filterActive): ?><a class="btn btn-light btn-sm" href="customers1.php"><i class="fa-solid fa-rotate-left me-1"></i>Reset</a><?php endif; ?>
                        <?php if ($canCreate): ?><a href="customer-add.php" class="btn btn-theme btn-sm"><i class="fa-solid fa-plus me-1"></i>Add Customer</a><?php endif; ?>
                    </div>
                </div>
            </form>

            <?php if (!$canView): ?>
                <div class="table-card">
                    <div class="empty-state"><i class="fa-solid fa-lock fa-2x mb-2"></i>
                        <div>You do not have permission to view customers.</div>
                    </div>
                </div>
            <?php else: ?>
                <div class="table-card">
                    <div class="table-responsive">
                        <table class="table customer-table align-middle">
                            <thead>
                                <tr>
                                    <th>Customer</th>
                                    <th>Services</th>
                                    <th>Contact</th>
                                    <th>GSTIN</th>
                                    <th>City</th>
                                    <th>Opening Balance</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($customers as $customer): ?>
                                    <tr>
                                        <td class="customer-column" data-label="Customer">
                                            <div class="main-text"><?php echo e($customer['customer_name']); ?></div>
                                            <div class="sub-text"><?php echo e($customer['customer_code'] ?? ''); ?></div>
                                        </td>
                                        <td data-label="Services">
                                            <div class="service-badges">
                                                <?php $serviceList = array_filter(explode(',', (string) ($customer['services'] ?? '')));
                                                foreach ($serviceList as $sv): ?><span
                                                        class="badge-soft service-<?php echo e(strtolower($sv)); ?>"><?php echo e($sv); ?></span><?php endforeach; ?><?php if (!$serviceList): ?><span
                                                        class="sub-text">No service</span><?php endif; ?></div>
                                        </td>
                                        <td data-label="Contact">
                                            <div><?php echo e($customer['mobile'] ?? '—'); ?></div>
                                            <?php if (!empty($customer['alternate_mobile'])): ?>
                                                <div class="sub-text"><?php echo e($customer['alternate_mobile']); ?></div>
                                            <?php endif; ?>        <?php if (!empty($customer['email'])): ?>
                                                <div class="sub-text"><?php echo e($customer['email']); ?></div><?php endif; ?>
                                        </td>
                                        <td data-label="GSTIN"><?php echo e($customer['gstin'] ?? '—'); ?></td>
                                        <td data-label="City">
                                            <?php echo e(trim((string) ($customer['city'] ?? '') . (!empty($customer['city']) && !empty($customer['state']) ? ', ' : '') . (string) ($customer['state'] ?? '')) ?: '—'); ?>
                                        </td>
                                        <td data-label="Opening Balance">
                                            <?php echo $canViewValue ? e($currency) . number_format((float) ($customer['opening_balance'] ?? 0), 2) . (!empty($customer['balance_type']) ? ' (' . e($customer['balance_type']) . ')' : '') : '••••'; ?>
                                        </td>
                                        <td data-label="Status"><span
                                                class="badge-soft <?php echo (int) ($customer['is_active'] ?? 1) === 1 ? 'active-badge' : 'inactive-badge'; ?>"><?php echo (int) ($customer['is_active'] ?? 1) === 1 ? 'Active' : 'Inactive'; ?></span>
                                        </td>
                                        <td data-label="Created">
                                            <?php echo !empty($customer['created_at']) ? e(date('d-m-Y h:i A', strtotime($customer['created_at']))) : '—'; ?>
                                        </td>
                                        <td class="text-end actions-column" data-label="Actions">
                                            <div class="d-inline-flex gap-1">
                                                <?php if ($canView): ?><a class="action-btn" href="customer-view.php?id=<?php echo (int) $customer['id']; ?>" title="View"><i class="fa-solid fa-eye"></i></a><?php endif; ?>
                                                <?php if ($canUpdate): ?><a class="action-btn"
                                                        href="customer-edit.php?id=<?php echo (int) $customer['id']; ?>"
                                                        title="Edit"><i class="fa-solid fa-pen"></i></a><?php endif; ?>
                                                <?php if ($canUpdate && $hasActive): ?><button
                                                        class="action-btn toggle-customer" type="button"
                                                        data-id="<?php echo (int) $customer['id']; ?>"
                                                        data-name="<?php echo e($customer['customer_name']); ?>"
                                                        title="<?php echo (int) ($customer['is_active'] ?? 1) === 1 ? 'Deactivate' : 'Activate'; ?>"><i
                                                            class="fa-solid <?php echo (int) ($customer['is_active'] ?? 1) === 1 ? 'fa-ban' : 'fa-circle-check'; ?>"></i></button><?php endif; ?>
                                                <?php if ($canDelete): ?><button class="action-btn danger delete-customer"
                                                        type="button" data-id="<?php echo (int) $customer['id']; ?>"
                                                        data-name="<?php echo e($customer['customer_name']); ?>" title="Delete"><i
                                                            class="fa-solid fa-trash"></i></button><?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div><?php if (!$customers): ?>
                        <div class="empty-state"><i class="fa-regular fa-folder-open fa-2x mb-2"></i>
                            <div>No <?php echo e(strtolower($pageLabel)); ?> found.</div>
                        </div><?php endif; ?>
                    <?php if ($totalFilteredCustomers > 0): ?>
                        <div class="pagination-wrap">
                            <div class="pagination-summary">Showing <?php echo $paginationFrom; ?>–<?php echo $paginationTo; ?> of <?php echo $totalFilteredCustomers; ?> customers</div>
                            <?php if ($totalPages > 1): ?>
                                <nav aria-label="Customer pagination">
                                    <ul class="pagination pagination-sm">
                                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="<?php echo e(customerPageUrl($page - 1)); ?>" aria-label="Previous"><i class="fa-solid fa-angle-left"></i></a>
                                        </li>
                                        <?php
                                        $startPage = max(1, $page - 2);
                                        $endPage = min($totalPages, $page + 2);
                                        for ($pageNumber = $startPage; $pageNumber <= $endPage; $pageNumber++):
                                        ?>
                                            <li class="page-item <?php echo $pageNumber === $page ? 'active' : ''; ?>">
                                                <a class="page-link" href="<?php echo e(customerPageUrl($pageNumber)); ?>"><?php echo $pageNumber; ?></a>
                                            </li>
                                        <?php endfor; ?>
                                        <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="<?php echo e(customerPageUrl($page + 1)); ?>" aria-label="Next"><i class="fa-solid fa-angle-right"></i></a>
                                        </li>
                                    </ul>
                                </nav>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php include('includes/footer.php'); ?>
        </div>
    </main>

    <div class="modal fade" id="customerActionModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="customerActionTitle">Confirm Action</h5><button type="button"
                        class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-1" id="customerActionText"></p><small class="text-muted"
                        id="customerActionHint"></small>
                </div>
                <div class="modal-footer"><button class="btn btn-light btn-sm"
                        data-bs-dismiss="modal">Cancel</button><button class="btn btn-danger btn-sm"
                        id="confirmCustomerAction"><i class="fa-solid fa-check me-2"></i>Confirm</button></div>
            </div>
        </div>
    </div>
    <div class="theme-toast" id="themeToast"><i class="fa-solid fa-circle-info"></i><span></span></div>

    <?php include('includes/script.php'); ?>
    <script src="assets/js/script.js"></script>
    <script>
        (() => {
            const toast = document.getElementById('themeToast');
            function showToast(message, success) {
                if (!toast) return;
                toast.className = 'theme-toast ' + (success ? 'theme-toast-success' : 'theme-toast-error');
                toast.querySelector('span').textContent = message;
                toast.classList.add('show');
                setTimeout(() => toast.classList.remove('show'), 3200);
            }

    <?php if ($flashMessage !== ''): ?>showToast(<?php echo json_encode($flashMessage); ?>, true); <?php endif; ?>

            let pending = null;
            const modalElement = document.getElementById('customerActionModal');
            const modal = modalElement ? new bootstrap.Modal(modalElement) : null;
            const title = document.getElementById('customerActionTitle');
            const text = document.getElementById('customerActionText');
            const hint = document.getElementById('customerActionHint');
            const confirmButton = document.getElementById('confirmCustomerAction');

            document.querySelectorAll('.toggle-customer').forEach(button => button.addEventListener('click', () => {
                pending = { action: 'toggle_status', id: Number(button.dataset.id || 0), row: button.closest('tr') };
                title.textContent = 'Change Customer Status';
                text.textContent = 'Change status for ' + (button.dataset.name || 'this customer') + '?';
                hint.textContent = 'The customer record will remain available.';
                confirmButton.className = 'btn btn-warning btn-sm';
                modal?.show();
            }));

            document.querySelectorAll('.delete-customer').forEach(button => button.addEventListener('click', () => {
                pending = { action: 'delete', id: Number(button.dataset.id || 0), row: button.closest('tr') };
                title.textContent = 'Delete Customer';
                text.textContent = 'Delete ' + (button.dataset.name || 'this customer') + '?';
                hint.textContent = 'This action cannot be undone.';
                confirmButton.className = 'btn btn-danger btn-sm';
                modal?.show();
            }));

            confirmButton?.addEventListener('click', async function () {
                if (!pending || pending.id <= 0) return;
                this.disabled = true;
                const formData = new FormData();
                formData.append('action', pending.action);
                formData.append('customer_id', String(pending.id));
                formData.append('service', <?php echo json_encode($service); ?>);
                formData.append('csrf_token', <?php echo json_encode($csrfToken); ?>);
                try {
                    const endpoint = pending.action === 'delete' ? 'api/customer-delete.php' : 'api/customers-save.php';
                    const response = await fetch(endpoint, { method: 'POST', body: formData, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } });
                    const raw = await response.text();
                    let data;
                    try { data = JSON.parse(raw); } catch (parseError) { throw new Error(raw ? 'Invalid server response: ' + raw.replace(/<[^>]*>/g, ' ').trim().slice(0, 180) : 'Empty server response.'); }
                    if (!response.ok || !data.success) throw new Error(data.message || 'Unable to update customer.');
                    modal?.hide();
                    if (pending.action === 'delete') {
                        pending.row?.remove();
                        showToast(data.message, true);
                    } else {
                        window.location.reload();
                    }
                } catch (error) {
                    showToast(error.message || 'Unable to update customer.', false);
                } finally {
                    this.disabled = false;
                    pending = null;
                }
            });
        })();
    </script>
</body>

</html>