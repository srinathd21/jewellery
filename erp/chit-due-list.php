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

if (!function_exists('chitDuePermission')) {
    function chitDuePermission(string $action): bool
    {
        $fieldMap = [
            'open' => 'can_open',
            'view' => 'can_view',
            'value' => 'can_view_value',
            'create' => 'can_create',
        ];

        $field = $fieldMap[$action] ?? '';
        if ($field === '') {
            return false;
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

        foreach (['perm.chit.collections', 'perm.chit.members', 'perm.chit.groups', 'perm.chit'] as $permissionCode) {
            if (isset($_SESSION['permissions'][$permissionCode][$field])) {
                return (int)$_SESSION['permissions'][$permissionCode][$field] === 1;
            }
        }

        return false;
    }
}

$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? $_SESSION['default_branch_id'] ?? 0);

if ($businessId <= 0 || $branchId <= 0) {
    http_response_code(403);
    die('A valid business and branch must be selected.');
}

if (!chitDuePermission('open')) {
    http_response_code(403);
    die('Access denied. You do not have permission to open the chit due list.');
}

foreach (['chit_groups', 'chit_members', 'customers', 'chit_installments', 'chit_collections'] as $requiredTable) {
    if (!tableExists($conn, $requiredTable)) {
        die("Required table `{$requiredTable}` was not found.");
    }
}

$canView = chitDuePermission('view') || chitDuePermission('open');
$canViewValue = chitDuePermission('value') || $canView;
$canCollect = chitDuePermission('create');

$groupId = max(0, (int)($_GET['group_id'] ?? 0));
$memberId = max(0, (int)($_GET['member_id'] ?? 0));
$status = strtolower(trim((string)($_GET['status'] ?? 'all')));
$search = trim((string)($_GET['search'] ?? ''));
$dueFrom = trim((string)($_GET['due_from'] ?? ''));
$dueTo = trim((string)($_GET['due_to'] ?? ''));
$sort = strtolower(trim((string)($_GET['sort'] ?? 'due_asc')));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['per_page'] ?? 20);

$allowedStatuses = ['all', 'overdue', 'upcoming', 'today'];
if (!in_array($status, $allowedStatuses, true)) {
    $status = 'all';
}

$allowedSorts = ['due_asc', 'due_desc', 'customer_asc', 'amount_desc'];
if (!in_array($sort, $allowedSorts, true)) {
    $sort = 'due_asc';
}

if (!in_array($perPage, [10, 20, 50, 100], true)) {
    $perPage = 20;
}

$groups = [];
$stmt = $conn->prepare(
    "SELECT id, group_no, group_name, total_months, installment_amount, status
     FROM chit_groups
     WHERE business_id = ? AND branch_id = ?
     ORDER BY start_date DESC, id DESC"
);
if ($stmt) {
    $stmt->bind_param('ii', $businessId, $branchId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $groups[] = $row;
    }
    $stmt->close();
}

$members = [];
$memberSql =
    "SELECT cm.id, cm.chit_group_id, cm.ticket_no, c.customer_name
     FROM chit_members cm
     INNER JOIN customers c ON c.id = cm.customer_id
     INNER JOIN chit_groups cg ON cg.id = cm.chit_group_id
     WHERE cm.business_id = ?
       AND cg.business_id = ?
       AND cg.branch_id = ?
       AND cm.status = 'Active'";
$memberTypes = 'iii';
$memberParams = [$businessId, $businessId, $branchId];
if ($groupId > 0) {
    $memberSql .= ' AND cm.chit_group_id = ?';
    $memberTypes .= 'i';
    $memberParams[] = $groupId;
}
$memberSql .= ' ORDER BY c.customer_name, cm.ticket_no';
$stmt = $conn->prepare($memberSql);
if ($stmt) {
    bindDynamic($stmt, $memberTypes, $memberParams);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $members[] = $row;
    }
    $stmt->close();
}

$where = [
    'cg.business_id = ?',
    'cg.branch_id = ?',
    "cm.status = 'Active'",
    "cg.status NOT IN ('Closed','Cancelled')",
    'cc.id IS NULL',
];
$types = 'ii';
$params = [$businessId, $branchId];

if ($groupId > 0) {
    $where[] = 'cg.id = ?';
    $types .= 'i';
    $params[] = $groupId;
}

if ($memberId > 0) {
    $where[] = 'cm.id = ?';
    $types .= 'i';
    $params[] = $memberId;
}

if ($search !== '') {
    $like = '%' . $search . '%';
    $where[] = '(cg.group_no LIKE ? OR cg.group_name LIKE ? OR cm.ticket_no LIKE ? OR c.customer_code LIKE ? OR c.customer_name LIKE ? OR c.mobile LIKE ? OR c.alternate_mobile LIKE ?)';
    $types .= 'sssssss';
    for ($i = 0; $i < 7; $i++) {
        $params[] = $like;
    }
}

if ($dueFrom !== '') {
    $where[] = 'ci.due_date >= ?';
    $types .= 's';
    $params[] = $dueFrom;
}

if ($dueTo !== '') {
    $where[] = 'ci.due_date <= ?';
    $types .= 's';
    $params[] = $dueTo;
}

if ($status === 'overdue') {
    $where[] = 'ci.due_date < CURDATE()';
} elseif ($status === 'today') {
    $where[] = 'ci.due_date = CURDATE()';
} elseif ($status === 'upcoming') {
    $where[] = 'ci.due_date > CURDATE()';
}

$whereSql = implode(' AND ', $where);

$baseFrom =
    " FROM chit_members cm
      INNER JOIN chit_groups cg ON cg.id = cm.chit_group_id
      INNER JOIN customers c ON c.id = cm.customer_id
      INNER JOIN chit_installments ci ON ci.chit_group_id = cg.id
      LEFT JOIN chit_collections cc
        ON cc.chit_group_id = cg.id
       AND cc.chit_member_id = cm.id
       AND cc.chit_installment_id = ci.id
      WHERE {$whereSql}";

$statsSql =
    "SELECT
        COUNT(*) AS total_entries,
        COUNT(DISTINCT cm.id) AS total_customers,
        SUM(CASE WHEN ci.due_date < CURDATE() THEN 1 ELSE 0 END) AS overdue_entries,
        SUM(CASE WHEN ci.due_date = CURDATE() THEN 1 ELSE 0 END) AS due_today_entries,
        SUM(CASE WHEN ci.due_date > CURDATE() THEN 1 ELSE 0 END) AS upcoming_entries,
        COALESCE(SUM(cg.installment_amount), 0) AS total_due_amount,
        COALESCE(SUM(CASE WHEN ci.due_date < CURDATE() THEN cg.installment_amount ELSE 0 END), 0) AS overdue_amount,
        COALESCE(SUM(CASE WHEN ci.due_date = CURDATE() THEN cg.installment_amount ELSE 0 END), 0) AS due_today_amount
     {$baseFrom}";

$stats = [
    'total_entries' => 0,
    'total_customers' => 0,
    'overdue_entries' => 0,
    'due_today_entries' => 0,
    'upcoming_entries' => 0,
    'total_due_amount' => 0,
    'overdue_amount' => 0,
    'due_today_amount' => 0,
];
$stmt = $conn->prepare($statsSql);
if ($stmt) {
    $statsParams = $params;
    bindDynamic($stmt, $types, $statsParams);
    $stmt->execute();
    $stats = array_merge($stats, $stmt->get_result()->fetch_assoc() ?: []);
    $stmt->close();
}

$totalRows = (int)$stats['total_entries'];
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$orderByMap = [
    'due_asc' => 'ci.due_date ASC, c.customer_name ASC, cm.ticket_no ASC',
    'due_desc' => 'ci.due_date DESC, c.customer_name ASC, cm.ticket_no ASC',
    'customer_asc' => 'c.customer_name ASC, ci.due_date ASC',
    'amount_desc' => 'cg.installment_amount DESC, ci.due_date ASC',
];
$orderBy = $orderByMap[$sort];

$listSql =
    "SELECT
        cg.id AS group_id,
        cg.group_no,
        cg.group_name,
        cg.installment_amount,
        cg.total_months,
        cg.status AS group_status,
        cm.id AS member_id,
        cm.ticket_no,
        cm.join_date,
        cm.nominee_name,
        cm.nominee_relation,
        c.customer_code,
        c.customer_name,
        c.mobile,
        c.alternate_mobile,
        c.email,
        c.address_line1,
        c.address_line2,
        c.city,
        c.state,
        c.pincode,
        ci.id AS installment_id,
        ci.installment_no,
        ci.due_date,
        DATEDIFF(CURDATE(), ci.due_date) AS overdue_days,
        CASE
            WHEN ci.due_date < CURDATE() THEN 'Overdue'
            WHEN ci.due_date = CURDATE() THEN 'Due Today'
            ELSE 'Upcoming'
        END AS due_status
     {$baseFrom}
     ORDER BY {$orderBy}
     LIMIT ? OFFSET ?";

$listTypes = $types . 'ii';
$listParams = $params;
$listParams[] = $perPage;
$listParams[] = $offset;

$dueRows = [];
$stmt = $conn->prepare($listSql);
if (!$stmt) {
    die('Unable to prepare chit due list: ' . h($conn->error));
}
bindDynamic($stmt, $listTypes, $listParams);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $dueRows[] = $row;
}
$stmt->close();

$theme = [
    'primary_color' => '#d89416',
    'primary_dark_color' => '#b86a0b',
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

function buildDueUrl(array $changes = []): string
{
    $query = $_GET;
    foreach ($changes as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }
    return 'chit-due-list.php' . ($query ? '?' . http_build_query($query) : '');
}

function normalizePhone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone);
    if ($digits === '') {
        return '';
    }
    if (strlen($digits) === 10) {
        return '91' . $digits;
    }
    if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
        return '91' . substr($digits, 1);
    }
    return $digits;
}

$pageTitle = 'Chit Due List';
$page_title = 'Chit Due List';
$currentPage = 'chit-due-list';
$businessName = (string)($_SESSION['business_name'] ?? 'Jewellery ERP');
$currency = (string)($_SESSION['currency_symbol'] ?? '₹');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo h($businessName); ?> - Chit Due List</title>
<?php include 'includes/links.php'; ?>
<style>
:root{--primary:<?php echo h($theme['primary_color']); ?>;--primary-dark:<?php echo h($theme['primary_dark_color']); ?>;--page-bg:<?php echo h($theme['page_background']); ?>;--card-bg:<?php echo h($theme['card_background']); ?>;--text:<?php echo h($theme['text_color']); ?>;--muted:<?php echo h($theme['muted_text_color']); ?>;--line:<?php echo h($theme['border_color']); ?>;--radius:<?php echo (int)$theme['border_radius_px']; ?>px}
body{background:var(--page-bg);color:var(--text);font-family:<?php echo json_encode($theme['font_family']); ?>,sans-serif}.page-heading{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:10px}.page-title{font-family:<?php echo json_encode($theme['heading_font_family']); ?>,serif;font-size:18px;font-weight:800;margin:0}.page-subtitle{font-size:9px;color:var(--muted);margin-top:2px}.summary-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px;margin-bottom:10px}.summary-card,.panel{background:var(--card-bg);border:1px solid var(--line);border-radius:var(--radius)}.summary-card{padding:12px}.summary-label{font-size:8px;text-transform:uppercase;font-weight:700;color:var(--muted)}.summary-value{font-size:16px;font-weight:800;margin-top:4px}.summary-note{font-size:8px;color:var(--muted);margin-top:2px}.panel{overflow:hidden;margin-bottom:10px}.filter-bar{padding:12px;border-bottom:1px solid var(--line)}.filter-grid{display:grid;grid-template-columns:2fr repeat(6,minmax(120px,1fr)) auto;gap:8px;align-items:end}.field-label{display:block;font-size:8px;text-transform:uppercase;font-weight:700;color:var(--muted);margin-bottom:4px}.form-control,.form-select{min-height:36px;font-size:10px;border-radius:9px;border-color:var(--line);background:var(--card-bg);color:var(--text)}.btn-theme{background:linear-gradient(135deg,var(--primary),var(--primary-dark));border:0;color:#fff}.btn-custom{min-height:36px;border-radius:9px;font-size:10px;font-weight:700;padding:8px 11px}.table{font-size:9px;margin:0}.table th{font-size:8px;text-transform:uppercase;letter-spacing:.03em;color:var(--muted);white-space:nowrap}.table td,.table th{padding:9px 10px;border-color:var(--line);background:var(--card-bg)!important;color:var(--text);vertical-align:middle}.main-text{font-size:10px;font-weight:800}.sub-text{font-size:8px;color:var(--muted);margin-top:2px}.contact-stack{display:flex;flex-direction:column;gap:3px}.contact-link{color:var(--text);text-decoration:none;font-size:9px}.contact-link:hover{color:var(--primary)}.status-badge{display:inline-flex;padding:4px 8px;border-radius:999px;font-size:8px;font-weight:800}.status-overdue{background:#fdecec;color:#bd2d2d}.status-today{background:#fff3cd;color:#8a6500}.status-upcoming{background:#eaf4ff;color:#1769aa}.days-overdue{color:#bd2d2d;font-weight:800}.amount-due{font-weight:800;color:#bd2d2d}.address{max-width:220px;line-height:1.35}.action-btn{white-space:nowrap}.empty-state{padding:45px 20px;text-align:center;color:var(--muted)}.pagination-wrap{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px;border-top:1px solid var(--line);flex-wrap:wrap}.pagination{margin:0}.page-link{font-size:9px;color:var(--text);background:var(--card-bg);border-color:var(--line)}.page-item.active .page-link{background:var(--primary);border-color:var(--primary);color:#fff}.theme-toast{position:fixed;right:18px;top:78px;z-index:20000;padding:11px 14px;border-radius:10px;color:#fff;font-size:11px;font-weight:600;opacity:0;transform:translateY(-10px);transition:.22s}.theme-toast.show{opacity:1;transform:none}.theme-toast-success{background:#168449}body.dark-mode,body[data-theme="dark"],html.dark-mode body,html[data-theme="dark"] body{--page-bg:#0f151b;--card-bg:#182129;--text:#f3f6f8;--muted:#9aa7b3;--line:#2c3944;color-scheme:dark}
@media(max-width:1450px){.summary-grid{grid-template-columns:repeat(3,1fr)}.filter-grid{grid-template-columns:repeat(4,1fr)}}
@media(max-width:900px){.summary-grid{grid-template-columns:repeat(2,1fr)}.filter-grid{grid-template-columns:repeat(2,1fr)}.responsive-table thead{display:none}.responsive-table tbody{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;padding:10px}.responsive-table tr{display:grid;grid-template-columns:1fr 1fr;border:1px solid var(--line);border-radius:var(--radius);padding:12px;background:var(--card-bg)}.responsive-table td{display:flex;justify-content:space-between;gap:10px;padding:8px 0;border:0;border-bottom:1px dashed var(--line);text-align:right}.responsive-table td:before{content:attr(data-label);font-size:8px;color:var(--muted);font-weight:700;text-transform:uppercase;text-align:left}.responsive-table td.main-column{grid-column:1/-1;display:block;text-align:left}.responsive-table td.main-column:before{display:none}.responsive-table td.action-column{grid-column:1/-1;border-bottom:0}.address{max-width:none;text-align:right}}
@media(max-width:600px){.page-heading{align-items:flex-start;flex-direction:column}.summary-grid,.filter-grid,.responsive-table tbody{grid-template-columns:1fr}.responsive-table tr{grid-template-columns:1fr}.responsive-table td{grid-column:1/-1}.theme-toast{left:12px;right:12px;top:70px}}
@media print{.sidebar,.navbar-header,.page-heading .btn,.filter-bar,.action-column,.pagination-wrap,.footer{display:none!important}.app-main{margin-left:0!important;width:100%!important}.content-wrap{padding:0!important}.panel,.summary-card{border:1px solid #ddd!important}.table{font-size:8px}}
</style>
</head>
<body>
<?php include 'includes/sidebar.php'; ?>
<main class="app-main">
<?php include 'includes/nav.php'; ?>
<div class="content-wrap">
    <div class="page-heading">
        <div>
            <h1 class="page-title">Chit Due List</h1>
            <div class="page-subtitle"><?php echo h($businessName); ?> · Unpaid members with installment, due and contact details</div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <button type="button" class="btn btn-light btn-custom" onclick="window.print()"><i class="fa-solid fa-print me-1"></i>Print</button>
            <a href="chit-ledger.php" class="btn btn-light btn-custom">Received Payments</a>
            <a href="chit-groups.php" class="btn btn-light btn-custom">Chit Groups</a>
        </div>
    </div>

    <div class="summary-grid">
        <div class="summary-card"><div class="summary-label">Unpaid Entries</div><div class="summary-value"><?php echo number_format((int)$stats['total_entries']); ?></div><div class="summary-note">Filtered installments</div></div>
        <div class="summary-card"><div class="summary-label">Customers</div><div class="summary-value"><?php echo number_format((int)$stats['total_customers']); ?></div><div class="summary-note">Unique unpaid members</div></div>
        <div class="summary-card"><div class="summary-label">Total Due</div><div class="summary-value"><?php echo $canViewValue ? h($currency) . number_format((float)$stats['total_due_amount'], 2) : '••••'; ?></div><div class="summary-note">Filtered amount</div></div>
        <div class="summary-card"><div class="summary-label">Overdue</div><div class="summary-value"><?php echo number_format((int)$stats['overdue_entries']); ?></div><div class="summary-note"><?php echo $canViewValue ? h($currency) . number_format((float)$stats['overdue_amount'], 2) : '••••'; ?></div></div>
        <div class="summary-card"><div class="summary-label">Due Today</div><div class="summary-value"><?php echo number_format((int)$stats['due_today_entries']); ?></div><div class="summary-note"><?php echo $canViewValue ? h($currency) . number_format((float)$stats['due_today_amount'], 2) : '••••'; ?></div></div>
        <div class="summary-card"><div class="summary-label">Upcoming</div><div class="summary-value"><?php echo number_format((int)$stats['upcoming_entries']); ?></div><div class="summary-note">Future unpaid installments</div></div>
    </div>

    <div class="panel">
        <form method="get" class="filter-bar" action="chit-due-list.php">
            <div class="filter-grid">
                <div><label class="field-label">Search</label><input type="search" name="search" class="form-control" value="<?php echo h($search); ?>" placeholder="Customer, mobile, ticket or group"></div>
                <div><label class="field-label">Chit Group</label><select name="group_id" id="groupFilter" class="form-select"><option value="0">All Groups</option><?php foreach ($groups as $group): ?><option value="<?php echo (int)$group['id']; ?>" <?php echo $groupId === (int)$group['id'] ? 'selected' : ''; ?>><?php echo h($group['group_no'] . ' - ' . $group['group_name']); ?></option><?php endforeach; ?></select></div>
                <div><label class="field-label">Member</label><select name="member_id" id="memberFilter" class="form-select"><option value="0">All Members</option><?php foreach ($members as $member): ?><option value="<?php echo (int)$member['id']; ?>" data-group="<?php echo (int)$member['chit_group_id']; ?>" <?php echo $memberId === (int)$member['id'] ? 'selected' : ''; ?>><?php echo h($member['ticket_no'] . ' - ' . $member['customer_name']); ?></option><?php endforeach; ?></select></div>
                <div><label class="field-label">Due Status</label><select name="status" class="form-select"><option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All Unpaid</option><option value="overdue" <?php echo $status === 'overdue' ? 'selected' : ''; ?>>Overdue</option><option value="today" <?php echo $status === 'today' ? 'selected' : ''; ?>>Due Today</option><option value="upcoming" <?php echo $status === 'upcoming' ? 'selected' : ''; ?>>Upcoming</option></select></div>
                <div><label class="field-label">Due From</label><input type="date" name="due_from" class="form-control" value="<?php echo h($dueFrom); ?>"></div>
                <div><label class="field-label">Due To</label><input type="date" name="due_to" class="form-control" value="<?php echo h($dueTo); ?>"></div>
                <div><label class="field-label">Sort By</label><select name="sort" class="form-select"><option value="due_asc" <?php echo $sort === 'due_asc' ? 'selected' : ''; ?>>Due Date: Oldest</option><option value="due_desc" <?php echo $sort === 'due_desc' ? 'selected' : ''; ?>>Due Date: Newest</option><option value="customer_asc" <?php echo $sort === 'customer_asc' ? 'selected' : ''; ?>>Customer Name</option><option value="amount_desc" <?php echo $sort === 'amount_desc' ? 'selected' : ''; ?>>Amount: High to Low</option></select></div>
                <div><label class="field-label">Rows</label><select name="per_page" class="form-select"><?php foreach ([10,20,50,100] as $size): ?><option value="<?php echo $size; ?>" <?php echo $perPage === $size ? 'selected' : ''; ?>><?php echo $size; ?></option><?php endforeach; ?></select></div>
                <div class="d-flex gap-2"><button class="btn btn-theme btn-custom" type="submit"><i class="fa-solid fa-filter me-1"></i>Apply</button><a href="chit-due-list.php" class="btn btn-light btn-custom">Reset</a></div>
            </div>
        </form>

        <?php if (!$canView): ?>
            <div class="empty-state">You do not have permission to view chit due details.</div>
        <?php elseif (!$dueRows): ?>
            <div class="empty-state"><i class="fa-solid fa-circle-check fa-2x mb-2"></i><div>No unpaid chit installments found for the selected filters.</div></div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table responsive-table">
                    <thead><tr><th>#</th><th>Customer / Member</th><th>Contact</th><th>Address</th><th>Chit Group</th><th>Installment</th><th>Due Date</th><th>Days</th><th>Amount</th><th>Status</th><th class="text-end">Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($dueRows as $index => $row): ?>
                        <?php
                        $phone = trim((string)$row['mobile']);
                        $alternate = trim((string)$row['alternate_mobile']);
                        $whatsappPhone = normalizePhone($phone !== '' ? $phone : $alternate);
                        $addressParts = array_filter([
                            trim((string)$row['address_line1']),
                            trim((string)$row['address_line2']),
                            trim((string)$row['city']),
                            trim((string)$row['state']),
                            trim((string)$row['pincode']),
                        ], static fn($value) => $value !== '');
                        $address = $addressParts ? implode(', ', $addressParts) : '—';
                        $badgeClass = $row['due_status'] === 'Overdue' ? 'status-overdue' : ($row['due_status'] === 'Due Today' ? 'status-today' : 'status-upcoming');
                        $serial = $offset + $index + 1;
                        ?>
                        <tr>
                            <td data-label="#"><?php echo $serial; ?></td>
                            <td class="main-column" data-label="Customer / Member"><div class="main-text"><?php echo h($row['customer_name']); ?></div><div class="sub-text"><?php echo h($row['customer_code'] . ' · Ticket ' . $row['ticket_no']); ?></div><?php if (!empty($row['nominee_name'])): ?><div class="sub-text">Nominee: <?php echo h($row['nominee_name'] . ($row['nominee_relation'] ? ' (' . $row['nominee_relation'] . ')' : '')); ?></div><?php endif; ?></td>
                            <td data-label="Contact"><div class="contact-stack"><?php if ($phone !== ''): ?><a class="contact-link" href="tel:<?php echo h($phone); ?>"><i class="fa-solid fa-phone me-1"></i><?php echo h($phone); ?></a><?php endif; ?><?php if ($alternate !== ''): ?><a class="contact-link" href="tel:<?php echo h($alternate); ?>"><i class="fa-solid fa-phone-volume me-1"></i><?php echo h($alternate); ?></a><?php endif; ?><?php if (!empty($row['email'])): ?><a class="contact-link" href="mailto:<?php echo h($row['email']); ?>"><i class="fa-solid fa-envelope me-1"></i><?php echo h($row['email']); ?></a><?php endif; ?><?php if ($phone === '' && $alternate === '' && empty($row['email'])): ?>—<?php endif; ?></div></td>
                            <td data-label="Address"><div class="address"><?php echo h($address); ?></div></td>
                            <td data-label="Chit Group"><div class="main-text"><?php echo h($row['group_name']); ?></div><div class="sub-text"><?php echo h($row['group_no']); ?></div></td>
                            <td data-label="Installment"><?php echo (int)$row['installment_no']; ?> / <?php echo (int)$row['total_months']; ?></td>
                            <td data-label="Due Date"><?php echo h(date('d-m-Y', strtotime($row['due_date']))); ?></td>
                            <td data-label="Days" class="<?php echo (int)$row['overdue_days'] > 0 ? 'days-overdue' : ''; ?>"><?php echo (int)$row['overdue_days'] > 0 ? (int)$row['overdue_days'] . ' overdue' : ((int)$row['overdue_days'] === 0 ? 'Today' : abs((int)$row['overdue_days']) . ' days left'); ?></td>
                            <td data-label="Amount" class="amount-due"><?php echo $canViewValue ? h($currency) . number_format((float)$row['installment_amount'], 2) : '••••'; ?></td>
                            <td data-label="Status"><span class="status-badge <?php echo $badgeClass; ?>"><?php echo h($row['due_status']); ?></span></td>
                            <td data-label="Action" class="text-end action-column"><div class="d-flex gap-1 justify-content-end flex-wrap"><a href="chit-member-view.php?id=<?php echo (int)$row['member_id']; ?>" class="btn btn-light btn-custom action-btn"><i class="fa-solid fa-eye me-1"></i>View</a><?php if ($phone !== ''): ?><a href="tel:<?php echo h($phone); ?>" class="btn btn-light btn-custom action-btn"><i class="fa-solid fa-phone me-1"></i>Call</a><?php endif; ?><?php if ($whatsappPhone !== ''): ?><a href="https://wa.me/<?php echo h($whatsappPhone); ?>?text=<?php echo rawurlencode('Dear ' . $row['customer_name'] . ', your chit installment ' . $row['installment_no'] . ' for ' . $row['group_name'] . ' is due on ' . date('d-m-Y', strtotime($row['due_date'])) . '. Amount: ' . $currency . number_format((float)$row['installment_amount'], 2) . '.'); ?>" target="_blank" rel="noopener" class="btn btn-light btn-custom action-btn"><i class="fa-brands fa-whatsapp me-1"></i>WhatsApp</a><?php endif; ?><?php if ($canCollect): ?><a href="chit-collection-add.php?member_id=<?php echo (int)$row['member_id']; ?>&installment_id=<?php echo (int)$row['installment_id']; ?>" class="btn btn-theme btn-custom action-btn"><i class="fa-solid fa-indian-rupee-sign me-1"></i>Collect</a><?php endif; ?></div></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="pagination-wrap">
                <div class="sub-text">Showing <?php echo $totalRows > 0 ? $offset + 1 : 0; ?> to <?php echo min($offset + $perPage, $totalRows); ?> of <?php echo number_format($totalRows); ?> unpaid entries</div>
                <?php if ($totalPages > 1): ?>
                <nav aria-label="Due list pagination">
                    <ul class="pagination pagination-sm">
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo h(buildDueUrl(['page' => max(1, $page - 1)])); ?>">Previous</a></li>
                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                        if ($startPage > 1): ?>
                            <li class="page-item"><a class="page-link" href="<?php echo h(buildDueUrl(['page' => 1])); ?>">1</a></li>
                            <?php if ($startPage > 2): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
                        <?php endif; ?>
                        <?php for ($pageNo = $startPage; $pageNo <= $endPage; $pageNo++): ?>
                            <li class="page-item <?php echo $pageNo === $page ? 'active' : ''; ?>"><a class="page-link" href="<?php echo h(buildDueUrl(['page' => $pageNo])); ?>"><?php echo $pageNo; ?></a></li>
                        <?php endfor; ?>
                        <?php if ($endPage < $totalPages): ?>
                            <?php if ($endPage < $totalPages - 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
                            <li class="page-item"><a class="page-link" href="<?php echo h(buildDueUrl(['page' => $totalPages])); ?>"><?php echo $totalPages; ?></a></li>
                        <?php endif; ?>
                        <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo h(buildDueUrl(['page' => min($totalPages, $page + 1)])); ?>">Next</a></li>
                    </ul>
                </nav>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php include 'includes/footer.php'; ?>
</div>
</main>
<?php include 'includes/script.php'; ?>
<script src="assets/js/script.js"></script>
<script>
(function(){
    'use strict';
    const group = document.getElementById('groupFilter');
    const member = document.getElementById('memberFilter');
    if (group && member) {
        const updateMembers = function () {
            const selectedGroup = Number(group.value || 0);
            Array.prototype.forEach.call(member.options, function (option) {
                if (!option.value || option.value === '0') {
                    option.hidden = false;
                    return;
                }
                const optionGroup = Number(option.getAttribute('data-group') || 0);
                option.hidden = selectedGroup > 0 && optionGroup !== selectedGroup;
            });
            if (member.selectedOptions.length && member.selectedOptions[0].hidden) {
                member.value = '0';
            }
        };
        group.addEventListener('change', updateMembers);
        updateMembers();
    }

    const params = new URLSearchParams(window.location.search);
    if (params.get('msg') === 'payment_collected') {
        const toast = document.createElement('div');
        toast.className = 'theme-toast theme-toast-success';
        toast.textContent = 'Chit payment collected successfully.';
        document.body.appendChild(toast);
        requestAnimationFrame(function(){ toast.classList.add('show'); });
        setTimeout(function(){ toast.classList.remove('show'); setTimeout(function(){ toast.remove(); }, 250); }, 3500);
        params.delete('msg');
        const clean = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
        window.history.replaceState({}, '', clean);
    }
})();
</script>
</body>
</html>