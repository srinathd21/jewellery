<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string) ($_SESSION['timezone'] ?? 'Asia/Kolkata'));

$configCandidates = [
    __DIR__ . '/config/config.php',
    __DIR__ . '/config.php',
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
$conn->set_charset('utf8mb4');

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if (!function_exists('e')) {
    function e($value)
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

function auditPermission(mysqli $conn, $action)
{
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin') {
        return true;
    }

    $fieldMap = [
        'open' => 'can_open',
        'view' => 'can_view',
        'value' => 'can_view_value',
        'create' => 'can_create',
        'update' => 'can_update',
        'approve' => 'can_approve',
        'delete' => 'can_delete',
    ];
    $field = isset($fieldMap[$action]) ? $fieldMap[$action] : '';
    if ($field === '') {
        return false;
    }

    $sessionPermissions = isset($_SESSION['permissions']) ? $_SESSION['permissions'] : [];
    foreach (['perm.settings.audit', 'perm.settings'] as $code) {
        if (isset($sessionPermissions[$code]) && isset($sessionPermissions[$code][$field])) {
            return (int) $sessionPermissions[$code][$field] === 1;
        }
    }

    $businessId = (int) ($_SESSION['business_id'] ?? 0);
    $roleId = (int) ($_SESSION['role_id'] ?? 0);
    if ($businessId <= 0 || $roleId <= 0) {
        return false;
    }

    $sql = "SELECT rp.`{$field}`
            FROM role_permissions rp
            INNER JOIN permissions p ON p.id = rp.permission_id
            WHERE rp.business_id = ?
              AND rp.role_id = ?
              AND p.is_active = 1
              AND p.permission_code IN ('perm.settings.audit','perm.settings')
            ORDER BY FIELD(p.permission_code,'perm.settings.audit','perm.settings')
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ii', $businessId, $roleId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row[$field] ?? 0) === 1;
}

if (!auditPermission($conn, 'open')) {
    http_response_code(403);
    die('Access denied. You do not have permission to open the audit trail.');
}

$canView = auditPermission($conn, 'view') || auditPermission($conn, 'open');
$canViewValue = auditPermission($conn, 'value');
$businessId = (int) ($_SESSION['business_id'] ?? 0);
$isPlatformAdmin = (($_SESSION['user_type'] ?? '') === 'Platform Admin');
if (!$isPlatformAdmin && $businessId <= 0) {
    http_response_code(403);
    die('A valid business must be selected before viewing audit logs.');
}

function auditDbOne(mysqli $conn, $sql)
{
    $result = $conn->query($sql);
    return $result ? ($result->fetch_assoc() ?: []) : [];
}

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
if ($businessId > 0) {
    $themeStmt = $conn->prepare('SELECT * FROM business_theme_settings WHERE business_id = ? LIMIT 1');
    if ($themeStmt) {
        $themeStmt->bind_param('i', $businessId);
        $themeStmt->execute();
        $themeRow = $themeStmt->get_result()->fetch_assoc();
        $themeStmt->close();
        if ($themeRow) {
            foreach ($theme as $key => $default) {
                if (isset($themeRow[$key]) && $themeRow[$key] !== '') {
                    $theme[$key] = $themeRow[$key];
                }
            }
        }
    }
}

$search = trim((string) ($_GET['search'] ?? ''));
$module = trim((string) ($_GET['module'] ?? ''));
$action = trim((string) ($_GET['action_type'] ?? ''));
$userIdFilter = (int) ($_GET['user_id'] ?? 0);
$branchIdFilter = (int) ($_GET['branch_id'] ?? 0);
$dateFrom = trim((string) ($_GET['date_from'] ?? date('Y-m-01')));
$dateTo = trim((string) ($_GET['date_to'] ?? date('Y-m-d')));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];
$types = '';
if (!$isPlatformAdmin) {
    $where[] = 'al.business_id = ?';
    $params[] = $businessId;
    $types .= 'i';
} elseif ($businessId > 0) {
    $where[] = 'al.business_id = ?';
    $params[] = $businessId;
    $types .= 'i';
}
if ($search !== '') {
    $where[] = '(al.description LIKE ? OR al.module_code LIKE ? OR al.action_type LIKE ? OR al.reference_table LIKE ? OR u.full_name LIKE ? OR u.username LIKE ? OR b.branch_name LIKE ?)';
    $like = '%' . $search . '%';
    for ($i = 0; $i < 7; $i++) {
        $params[] = $like;
        $types .= 's';
    }
}
if ($module !== '') {
    $where[] = 'al.module_code = ?';
    $params[] = $module;
    $types .= 's';
}
if ($action !== '') {
    $where[] = 'al.action_type = ?';
    $params[] = $action;
    $types .= 's';
}
if ($userIdFilter > 0) {
    $where[] = 'al.user_id = ?';
    $params[] = $userIdFilter;
    $types .= 'i';
}
if ($branchIdFilter > 0) {
    $where[] = 'al.branch_id = ?';
    $params[] = $branchIdFilter;
    $types .= 'i';
}
if ($dateFrom !== '') {
    $where[] = 'DATE(al.created_at) >= ?';
    $params[] = $dateFrom;
    $types .= 's';
}
if ($dateTo !== '') {
    $where[] = 'DATE(al.created_at) <= ?';
    $params[] = $dateTo;
    $types .= 's';
}
$whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

$baseJoin = ' FROM audit_logs al
              LEFT JOIN users u ON u.id = al.user_id
              LEFT JOIN branches b ON b.id = al.branch_id
              LEFT JOIN businesses bs ON bs.id = al.business_id ';

$countSql = 'SELECT COUNT(*) AS total ' . $baseJoin . $whereSql;
$countStmt = $conn->prepare($countSql);
$totalRows = 0;
if ($countStmt) {
    if ($types !== '') {
        $countStmt->bind_param($types, ...$params);
    }
    $countStmt->execute();
    $countRow = $countStmt->get_result()->fetch_assoc();
    $totalRows = (int) ($countRow['total'] ?? 0);
    $countStmt->close();
}
$totalPages = max(1, (int) ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$listSql = 'SELECT al.*, u.full_name, u.username, b.branch_name, bs.business_name '
         . $baseJoin . $whereSql . ' ORDER BY al.created_at DESC, al.id DESC LIMIT ? OFFSET ?';
$listParams = $params;
$listTypes = $types . 'ii';
$listParams[] = $perPage;
$listParams[] = $offset;
$listStmt = $conn->prepare($listSql);
$logs = [];
if ($listStmt) {
    $listStmt->bind_param($listTypes, ...$listParams);
    $listStmt->execute();
    $result = $listStmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $logs[] = $row;
    }
    $listStmt->close();
}

$scope = $businessId > 0 ? ' WHERE business_id = ' . $businessId : '';
$stats = auditDbOne($conn, "SELECT COUNT(*) AS total,
    SUM(CASE WHEN DATE(created_at)=CURDATE() THEN 1 ELSE 0 END) AS today_count,
    COUNT(DISTINCT user_id) AS users_count,
    COUNT(DISTINCT module_code) AS modules_count
    FROM audit_logs" . $scope);

$users = [];
$branches = [];
$modules = [];
$actions = [];
if ($businessId > 0) {
    $stmt = $conn->prepare('SELECT id, full_name, username FROM users WHERE business_id = ? ORDER BY full_name');
    if ($stmt) {
        $stmt->bind_param('i', $businessId);
        $stmt->execute();
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) $users[] = $row;
        $stmt->close();
    }
    $stmt = $conn->prepare('SELECT id, branch_name FROM branches WHERE business_id = ? ORDER BY branch_name');
    if ($stmt) {
        $stmt->bind_param('i', $businessId);
        $stmt->execute();
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) $branches[] = $row;
        $stmt->close();
    }
    $stmt = $conn->prepare('SELECT DISTINCT module_code FROM audit_logs WHERE business_id = ? ORDER BY module_code');
    if ($stmt) {
        $stmt->bind_param('i', $businessId);
        $stmt->execute();
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) $modules[] = $row['module_code'];
        $stmt->close();
    }
    $stmt = $conn->prepare('SELECT DISTINCT action_type FROM audit_logs WHERE business_id = ? ORDER BY action_type');
    if ($stmt) {
        $stmt->bind_param('i', $businessId);
        $stmt->execute();
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) $actions[] = $row['action_type'];
        $stmt->close();
    }
}

function auditBadgeClass($actionType)
{
    $a = strtolower((string) $actionType);
    if (strpos($a, 'create') !== false || strpos($a, 'login') !== false || strpos($a, 'approve') !== false) return 'badge-success';
    if (strpos($a, 'delete') !== false || strpos($a, 'reject') !== false || strpos($a, 'cancel') !== false) return 'badge-danger';
    if (strpos($a, 'update') !== false || strpos($a, 'edit') !== false) return 'badge-warning';
    return 'badge-info';
}

function queryUrl($overrides = [])
{
    $query = $_GET;
    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') unset($query[$key]); else $query[$key] = $value;
    }
    return '?' . http_build_query($query);
}

$pageTitle = 'Audit Trail';
$businessName = (string) ($_SESSION['business_name'] ?? 'Jewellery ERP');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo e($businessName); ?> - Audit Trail</title>
    <?php include('includes/links.php'); ?>
    <style>
        :root{
            --primary:<?php echo e($theme['primary_color']); ?>;
            --primary-dark:<?php echo e($theme['primary_dark_color']); ?>;
            --primary-soft:<?php echo e($theme['primary_soft_color']); ?>;
            --sidebar-gradient-1:<?php echo e($theme['sidebar_gradient_1']); ?>;
            --sidebar-gradient-2:<?php echo e($theme['sidebar_gradient_2']); ?>;
            --sidebar-gradient-3:<?php echo e($theme['sidebar_gradient_3']); ?>;
            --page-bg:<?php echo e($theme['page_background']); ?>;
            --card-bg:<?php echo e($theme['card_background']); ?>;
            --text-color:<?php echo e($theme['text_color']); ?>;
            --muted-color:<?php echo e($theme['muted_text_color']); ?>;
            --border-color:<?php echo e($theme['border_color']); ?>;
            --sidebar-width:<?php echo (int)$theme['sidebar_width_px']; ?>px;
            --radius:<?php echo (int)$theme['border_radius_px']; ?>px;
        }
        body{background:var(--page-bg);color:var(--text-color);font-family:<?php echo json_encode((string)$theme['font_family']); ?>,sans-serif}
        .sidebar{background:linear-gradient(180deg,var(--sidebar-gradient-1),var(--sidebar-gradient-2),var(--sidebar-gradient-3))!important}
        .card-panel,.stat-card,.filters-card,.logs-card,.modal-content,.form-control,.form-select,.btn,.action-btn{border-radius:var(--radius)!important}
        .card-panel,.stat-card,.filters-card,.logs-card{background:var(--card-bg);border:1px solid var(--border-color)}
        .stat-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:12px}
        .stat-card{padding:12px 14px;display:flex;align-items:center;gap:12px;min-height:82px}
        .stat-icon{width:42px;height:42px;display:flex;align-items:center;justify-content:center;background:var(--primary-soft);color:var(--primary-dark);border-radius:calc(var(--radius)*.75)!important;font-size:17px;flex:0 0 42px}
        .stat-label{font-size:9px;color:var(--muted-color);margin-bottom:3px}.stat-value{font-size:21px;font-weight:800;line-height:1.1}
        .filters-card{padding:12px;margin-bottom:12px}.filter-grid{display:grid;grid-template-columns:minmax(230px,1.5fr) repeat(6,minmax(125px,1fr)) auto;gap:8px;align-items:end}
        .filter-label{font-size:9px;font-weight:700;color:var(--muted-color);margin-bottom:4px;display:block}.form-control,.form-select{min-height:36px;font-size:10px;border-color:var(--border-color);background:var(--card-bg);color:var(--text-color)}
        .filter-actions{display:flex;gap:7px}.btn-theme{background:var(--primary);border-color:var(--primary);color:#fff}.btn-theme:hover{background:var(--primary-dark);border-color:var(--primary-dark);color:#fff}
        .logs-card{overflow:hidden}.audit-table{font-size:10px;margin:0;color:var(--text-color)}.audit-table th{padding:10px 11px;font-size:9px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted-color);background:color-mix(in srgb,var(--muted-color) 6%,transparent);white-space:nowrap;border-color:var(--border-color)}.audit-table td{padding:10px 11px;vertical-align:middle;border-color:var(--border-color)}
        .log-main{display:flex;gap:9px;align-items:flex-start;min-width:250px}.log-icon{width:34px;height:34px;display:flex;align-items:center;justify-content:center;background:var(--primary-soft);color:var(--primary-dark);border-radius:calc(var(--radius)*.65)!important;flex:0 0 34px}.log-description{font-weight:700;line-height:1.35}.log-sub{font-size:9px;color:var(--muted-color);margin-top:3px}
        .audit-badge{display:inline-flex;align-items:center;border-radius:999px;padding:4px 8px;font-size:9px;font-weight:700}.badge-success{background:#eaf8f0;color:#168449}.badge-danger{background:#fdecec;color:#bd2d2d}.badge-warning{background:#fff5df;color:#b56b00}.badge-info{background:#eff6ff;color:#1d4ed8}
        .action-btn{width:30px;height:30px;display:inline-flex;align-items:center;justify-content:center;background:var(--card-bg);border:1px solid var(--border-color);color:var(--text-color);font-size:10px}.action-btn:hover{background:var(--primary-soft);color:var(--primary-dark)}
        .pagination-wrap{display:flex;justify-content:space-between;align-items:center;padding:10px 12px;border-top:1px solid var(--border-color);font-size:10px}.pagination{margin:0;gap:4px}.page-link{font-size:10px;color:var(--text-color);background:var(--card-bg);border-color:var(--border-color);border-radius:calc(var(--radius)*.55)!important}.page-item.active .page-link{background:var(--primary);border-color:var(--primary)}
        .json-box{background:color-mix(in srgb,var(--muted-color) 8%,transparent);border:1px solid var(--border-color);border-radius:var(--radius);padding:10px;max-height:260px;overflow:auto;font-family:monospace;font-size:10px;white-space:pre-wrap;word-break:break-word}.detail-label{font-size:9px;color:var(--muted-color);text-transform:uppercase;font-weight:700}.detail-value{font-size:11px;font-weight:600;margin-top:3px;word-break:break-word}.empty-state{padding:45px 18px;text-align:center;color:var(--muted-color)}
        body.dark-mode,body[data-theme="dark"],html.dark-mode body,html[data-theme="dark"] body{--page-bg:#0f151b;--card-bg:#182129;--text-color:#f3f6f8;--muted-color:#9aa7b3;--border-color:#2c3944}
        body.dark-mode .audit-table,body.dark-mode .audit-table td,body.dark-mode .audit-table th,body[data-theme="dark"] .audit-table,body[data-theme="dark"] .audit-table td,body[data-theme="dark"] .audit-table th{background:transparent!important;color:var(--text-color)!important;border-color:var(--border-color)!important}
        body.dark-mode .form-control,body.dark-mode .form-select,body[data-theme="dark"] .form-control,body[data-theme="dark"] .form-select{background:#121a21;color:var(--text-color);border-color:var(--border-color)}
        @media(max-width:1399.98px){.filter-grid{grid-template-columns:repeat(4,minmax(0,1fr))}.filter-actions{grid-column:span 4;justify-content:flex-end}.search-field{grid-column:span 2}}
        @media(max-width:991.98px){.stat-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.filter-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.search-field{grid-column:span 2}.filter-actions{grid-column:span 2}.logs-card{background:transparent;border:0;overflow:visible}.table-responsive{overflow:visible}.audit-table,.audit-table tbody{display:block}.audit-table thead{display:none}.audit-table tbody{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.audit-table tbody tr{display:grid;grid-template-columns:1fr 1fr;background:var(--card-bg);border:1px solid var(--border-color);border-radius:var(--radius);padding:13px;box-shadow:0 5px 18px rgba(24,31,40,.08)}.audit-table tbody td{display:flex;justify-content:space-between;gap:12px;padding:8px 0;border:0;border-bottom:1px dashed var(--border-color);text-align:right!important;min-width:0}.audit-table tbody td:before{content:attr(data-label);font-size:9px;font-weight:700;color:var(--muted-color);text-transform:uppercase;text-align:left}.audit-table tbody td.log-column{grid-column:1/-1;display:block;text-align:left!important;padding:0 0 11px;margin-bottom:2px;border-bottom:1px solid var(--border-color)}.audit-table tbody td.log-column:before{display:none}.audit-table tbody td.actions-column{grid-column:1/-1;border-bottom:0;padding-top:11px}.pagination-wrap{background:var(--card-bg);border:1px solid var(--border-color);border-radius:var(--radius);margin-top:12px}}
        @media(max-width:767.98px){.content-wrap{padding-left:10px;padding-right:10px}.stat-card{padding:10px}.stat-icon{width:36px;height:36px;flex-basis:36px}.stat-value{font-size:18px}.filter-grid{grid-template-columns:1fr}.search-field,.filter-actions{grid-column:auto}.filter-actions{display:grid;grid-template-columns:1fr 1fr}.audit-table tbody{grid-template-columns:1fr}.audit-table tbody tr{grid-template-columns:1fr}.audit-table tbody td{grid-column:1/-1}.pagination-wrap{flex-direction:column;gap:10px;align-items:stretch}.pagination{justify-content:center}}
    </style>
</head>
<body>
<?php include('includes/sidebar.php'); ?>
<main class="app-main">
    <?php include('includes/nav.php'); ?>
    <div class="content-wrap">
        <?php if (!$canView): ?>
            <div class="card-panel empty-state"><i class="fa-solid fa-lock fs-2 mb-2"></i><div>You do not have permission to view audit logs.</div></div>
        <?php else: ?>
            <section class="stat-grid">
                <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-list-check"></i></div><div><div class="stat-label">Total Logs</div><div class="stat-value"><?php echo (int)($stats['total'] ?? 0); ?></div></div></div>
                <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-calendar-day"></i></div><div><div class="stat-label">Today's Activity</div><div class="stat-value"><?php echo (int)($stats['today_count'] ?? 0); ?></div></div></div>
                <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-users"></i></div><div><div class="stat-label">Active Users Logged</div><div class="stat-value"><?php echo (int)($stats['users_count'] ?? 0); ?></div></div></div>
                <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-cubes"></i></div><div><div class="stat-label">Modules Tracked</div><div class="stat-value"><?php echo (int)($stats['modules_count'] ?? 0); ?></div></div></div>
            </section>

            <form class="filters-card" method="get" id="auditFilterForm">
                <div class="filter-grid">
                    <div class="search-field"><label class="filter-label">Search</label><input class="form-control" type="search" name="search" value="<?php echo e($search); ?>" placeholder="Description, user, module, table..."></div>
                    <div><label class="filter-label">Module</label><select class="form-select" name="module"><option value="">All modules</option><?php foreach($modules as $item): ?><option value="<?php echo e($item); ?>" <?php echo $module===$item?'selected':''; ?>><?php echo e($item); ?></option><?php endforeach; ?></select></div>
                    <div><label class="filter-label">Action</label><select class="form-select" name="action_type"><option value="">All actions</option><?php foreach($actions as $item): ?><option value="<?php echo e($item); ?>" <?php echo $action===$item?'selected':''; ?>><?php echo e($item); ?></option><?php endforeach; ?></select></div>
                    <div><label class="filter-label">User</label><select class="form-select" name="user_id"><option value="0">All users</option><?php foreach($users as $item): ?><option value="<?php echo (int)$item['id']; ?>" <?php echo $userIdFilter===(int)$item['id']?'selected':''; ?>><?php echo e($item['full_name']); ?></option><?php endforeach; ?></select></div>
                    <div><label class="filter-label">Branch</label><select class="form-select" name="branch_id"><option value="0">All branches</option><?php foreach($branches as $item): ?><option value="<?php echo (int)$item['id']; ?>" <?php echo $branchIdFilter===(int)$item['id']?'selected':''; ?>><?php echo e($item['branch_name']); ?></option><?php endforeach; ?></select></div>
                    <div><label class="filter-label">From</label><input class="form-control" type="date" name="date_from" value="<?php echo e($dateFrom); ?>"></div>
                    <div><label class="filter-label">To</label><input class="form-control" type="date" name="date_to" value="<?php echo e($dateTo); ?>"></div>
                    <div class="filter-actions"><button class="btn btn-theme btn-sm" type="submit"><i class="fa-solid fa-filter me-1"></i>Apply</button><a class="btn btn-light btn-sm" href="audit-logs.php"><i class="fa-solid fa-rotate-left me-1"></i>Reset</a></div>
                </div>
            </form>

            <div class="logs-card">
                <div class="table-responsive">
                    <table class="table audit-table align-middle" id="auditTable">
                        <thead><tr><th>Activity</th><th>User</th><th>Module</th><th>Action</th><th>Reference</th><th>Branch</th><th>Date & Time</th><th class="text-end">View</th></tr></thead>
                        <tbody>
                        <?php foreach($logs as $log):
                            $detailData = [
                                'id' => (int)$log['id'], 'description' => $log['description'], 'user' => $log['full_name'] ?: 'System',
                                'username' => $log['username'], 'module' => $log['module_code'], 'action' => $log['action_type'],
                                'reference_table' => $log['reference_table'], 'reference_id' => $log['reference_id'], 'branch' => $log['branch_name'],
                                'business' => $log['business_name'], 'ip_address' => $log['ip_address'], 'user_agent' => $log['user_agent'],
                                'old_values_json' => $log['old_values_json'], 'new_values_json' => $log['new_values_json'], 'created_at' => $log['created_at']
                            ];
                        ?>
                            <tr>
                                <td class="log-column" data-label="Activity"><div class="log-main"><div class="log-icon"><i class="fa-solid fa-clock-rotate-left"></i></div><div><div class="log-description"><?php echo e($log['description'] ?: ($log['action_type'].' action')); ?></div><div class="log-sub">Log #<?php echo (int)$log['id']; ?></div></div></div></td>
                                <td data-label="User"><div class="fw-semibold"><?php echo e($log['full_name'] ?: 'System'); ?></div><div class="log-sub"><?php echo e($log['username'] ? '@'.$log['username'] : 'Automated'); ?></div></td>
                                <td data-label="Module"><span class="audit-badge badge-info"><?php echo e($log['module_code']); ?></span></td>
                                <td data-label="Action"><span class="audit-badge <?php echo e(auditBadgeClass($log['action_type'])); ?>"><?php echo e($log['action_type']); ?></span></td>
                                <td data-label="Reference"><div><?php echo e($log['reference_table'] ?: '—'); ?></div><div class="log-sub"><?php echo $log['reference_id'] ? '#'.(int)$log['reference_id'] : 'No reference'; ?></div></td>
                                <td data-label="Branch"><?php echo e($log['branch_name'] ?: '—'); ?></td>
                                <td data-label="Date & Time"><div><?php echo e(date('d M Y', strtotime($log['created_at']))); ?></div><div class="log-sub"><?php echo e(date('h:i:s A', strtotime($log['created_at']))); ?></div></td>
                                <td class="text-end actions-column" data-label="View"><button class="action-btn view-log" type="button" title="View details" data-log="<?php echo e(json_encode($detailData)); ?>"><i class="fa-solid fa-eye"></i></button></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (!$logs): ?><div class="empty-state"><i class="fa-regular fa-folder-open fs-2 mb-2"></i><div>No audit logs found for the selected filters.</div></div><?php endif; ?>
                <?php if ($totalRows > 0): ?>
                    <div class="pagination-wrap"><div>Showing <?php echo $offset + 1; ?>–<?php echo min($offset + $perPage, $totalRows); ?> of <?php echo $totalRows; ?> logs</div><nav><ul class="pagination pagination-sm">
                        <li class="page-item <?php echo $page<=1?'disabled':''; ?>"><a class="page-link" href="<?php echo e(queryUrl(['page'=>max(1,$page-1)])); ?>">Previous</a></li>
                        <?php $start=max(1,$page-2);$end=min($totalPages,$page+2);for($p=$start;$p<=$end;$p++): ?><li class="page-item <?php echo $p===$page?'active':''; ?>"><a class="page-link" href="<?php echo e(queryUrl(['page'=>$p])); ?>"><?php echo $p; ?></a></li><?php endfor; ?>
                        <li class="page-item <?php echo $page>=$totalPages?'disabled':''; ?>"><a class="page-link" href="<?php echo e(queryUrl(['page'=>min($totalPages,$page+1)])); ?>">Next</a></li>
                    </ul></nav></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php include('includes/footer.php'); ?>
    </div>
</main>

<div class="modal fade" id="auditDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Audit Log Details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="row g-3 mb-3" id="auditDetailGrid"></div>
            <div class="mb-3"><div class="detail-label mb-1">Description</div><div class="json-box" id="detailDescription"></div></div>
            <div class="row g-3">
                <div class="col-md-6"><div class="detail-label mb-1">Old Values</div><div class="json-box" id="detailOldValues">No old values recorded.</div></div>
                <div class="col-md-6"><div class="detail-label mb-1">New Values</div><div class="json-box" id="detailNewValues">No new values recorded.</div></div>
            </div>
            <div class="mt-3"><div class="detail-label mb-1">User Agent</div><div class="json-box" id="detailUserAgent"></div></div>
        </div>
        <div class="modal-footer"><button class="btn btn-light btn-sm" type="button" data-bs-dismiss="modal">Close</button></div>
    </div></div>
</div>

<div class="offcanvas-backdrop fade" id="mobileBackdrop" style="display:none"></div>
<?php include('includes/script.php'); ?>
<script src="assets/js/script.js"></script>
<script>
(function(){
    'use strict';
    var modalEl=document.getElementById('auditDetailModal');
    var detailModal=modalEl?new bootstrap.Modal(modalEl):null;
    function prettyJson(value){
        if(!value) return 'No values recorded.';
        try{return JSON.stringify(JSON.parse(value),null,2);}catch(e){return value;}
    }
    document.addEventListener('click',function(event){
        var button=event.target.closest('.view-log');
        if(!button||!detailModal) return;
        var data={};
        try{data=JSON.parse(button.getAttribute('data-log')||'{}');}catch(e){return;}
        var fields=[['Log ID','#'+(data.id||'')],['User',data.user||'System'],['Module',data.module||'—'],['Action',data.action||'—'],['Reference',(data.reference_table||'—')+(data.reference_id?' #'+data.reference_id:'')],['Branch',data.branch||'—'],['IP Address',data.ip_address||'—'],['Created At',data.created_at||'—']];
        document.getElementById('auditDetailGrid').innerHTML=fields.map(function(item){return '<div class="col-6 col-md-3"><div class="detail-label">'+item[0]+'</div><div class="detail-value"></div></div>';}).join('');
        document.querySelectorAll('#auditDetailGrid .detail-value').forEach(function(el,index){el.textContent=fields[index][1];});
        document.getElementById('detailDescription').textContent=data.description||'No description.';
        document.getElementById('detailOldValues').textContent=prettyJson(data.old_values_json);
        document.getElementById('detailNewValues').textContent=prettyJson(data.new_values_json);
        document.getElementById('detailUserAgent').textContent=data.user_agent||'Not recorded.';
        detailModal.show();
    });
})();
</script>
</body>
</html>
