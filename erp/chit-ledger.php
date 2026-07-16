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

if (!function_exists('chitLedgerPermission')) {
    function chitLedgerPermission(string $action): bool
    {
        if (($_SESSION['user_type'] ?? '') === 'Platform Admin') {
            return true;
        }

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

        foreach (['perm.chit.collections', 'perm.chit.members', 'perm.chit.groups', 'perm.chit'] as $permissionCode) {
            if (isset($_SESSION['permissions'][$permissionCode][$field])) {
                return (int)$_SESSION['permissions'][$permissionCode][$field] === 1;
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

        return false;
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

if (!function_exists('validDateInput')) {
    function validDateInput(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $date = DateTime::createFromFormat('Y-m-d', $value);
        return ($date && $date->format('Y-m-d') === $value) ? $value : '';
    }
}

if (!function_exists('ledgerUrl')) {
    function ledgerUrl(array $changes = []): string
    {
        $query = $_GET;
        foreach ($changes as $key => $value) {
            if ($value === null || $value === '' || $value === 0 || $value === '0') {
                unset($query[$key]);
            } else {
                $query[$key] = $value;
            }
        }
        return 'chit-ledger.php' . ($query ? '?' . http_build_query($query) : '');
    }
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? $_SESSION['default_branch_id'] ?? 0);

if ($businessId <= 0 || $branchId <= 0) {
    http_response_code(403);
    die('A valid business and branch must be selected.');
}

if (!chitLedgerPermission('open')) {
    http_response_code(403);
    die('Access denied. You do not have permission to open the chit ledger.');
}

foreach (['chit_groups', 'chit_members', 'customers', 'chit_installments', 'chit_collections'] as $requiredTable) {
    if (!tableExists($conn, $requiredTable)) {
        die("Required table `{$requiredTable}` was not found.");
    }
}

$canView = chitLedgerPermission('view') || chitLedgerPermission('open');
$canViewValue = chitLedgerPermission('value') || $canView;

$groupId = max(0, (int)($_GET['group_id'] ?? 0));
$memberId = max(0, (int)($_GET['member_id'] ?? 0));
$receiptStatus = trim((string)($_GET['status'] ?? 'all'));
$search = trim((string)($_GET['search'] ?? ''));
$dateFrom = validDateInput(trim((string)($_GET['date_from'] ?? '')));
$dateTo = validDateInput(trim((string)($_GET['date_to'] ?? '')));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['per_page'] ?? 20);
$allowedPerPage = [10, 20, 50, 100];
if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 20;
}

$allowedStatuses = ['all', 'full', 'partial', 'discount', 'penalty'];
if (!in_array($receiptStatus, $allowedStatuses, true)) {
    $receiptStatus = 'all';
}

$groups = [];
$stmt = $conn->prepare(
    "SELECT DISTINCT cg.id, cg.group_no, cg.group_name
     FROM chit_groups cg
     INNER JOIN chit_collections cc ON cc.chit_group_id = cg.id
     WHERE cg.business_id = ? AND cg.branch_id = ?
     ORDER BY cg.group_name, cg.group_no"
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
    "SELECT DISTINCT cm.id, cm.chit_group_id, cm.ticket_no, c.customer_name
     FROM chit_members cm
     INNER JOIN customers c ON c.id = cm.customer_id
     INNER JOIN chit_groups cg ON cg.id = cm.chit_group_id
     INNER JOIN chit_collections cc ON cc.chit_member_id = cm.id
     WHERE cm.business_id = ? AND cg.business_id = ? AND cg.branch_id = ?";
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
    'cc.business_id = ?',
    'cc.branch_id = ?',
    'cg.business_id = ?',
    'cg.branch_id = ?',
];
$types = 'iiii';
$params = [$businessId, $branchId, $businessId, $branchId];

if ($groupId > 0) {
    $where[] = 'cc.chit_group_id = ?';
    $types .= 'i';
    $params[] = $groupId;
}
if ($memberId > 0) {
    $where[] = 'cc.chit_member_id = ?';
    $types .= 'i';
    $params[] = $memberId;
}
if ($search !== '') {
    $like = '%' . $search . '%';
    $where[] = '(cc.receipt_no LIKE ? OR cc.reference_no LIKE ? OR cg.group_no LIKE ? OR cg.group_name LIKE ? OR cm.ticket_no LIKE ? OR c.customer_code LIKE ? OR c.customer_name LIKE ? OR c.mobile LIKE ?)';
    $types .= 'ssssssss';
    for ($index = 0; $index < 8; $index++) {
        $params[] = $like;
    }
}
if ($dateFrom !== '') {
    $where[] = 'cc.collection_date >= ?';
    $types .= 's';
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $where[] = 'cc.collection_date <= ?';
    $types .= 's';
    $params[] = $dateTo;
}

if ($receiptStatus === 'full') {
    $where[] = '(cc.paid_amount + cc.discount_amount) >= (cc.due_amount + cc.penalty_amount)';
} elseif ($receiptStatus === 'partial') {
    $where[] = '(cc.paid_amount + cc.discount_amount) < (cc.due_amount + cc.penalty_amount)';
} elseif ($receiptStatus === 'discount') {
    $where[] = 'cc.discount_amount > 0';
} elseif ($receiptStatus === 'penalty') {
    $where[] = 'cc.penalty_amount > 0';
}

$fromSql =
    " FROM chit_collections cc
      INNER JOIN chit_groups cg ON cg.id = cc.chit_group_id
      INNER JOIN chit_members cm ON cm.id = cc.chit_member_id
      INNER JOIN customers c ON c.id = cm.customer_id
      INNER JOIN chit_installments ci ON ci.id = cc.chit_installment_id
      LEFT JOIN payment_methods pm ON pm.id = cc.payment_method_id
      WHERE " . implode(' AND ', $where);

$summarySql =
    "SELECT
        COUNT(*) AS receipt_count,
        COUNT(DISTINCT cc.chit_member_id) AS member_count,
        COUNT(DISTINCT cc.chit_group_id) AS group_count,
        COALESCE(SUM(cc.due_amount),0) AS total_due,
        COALESCE(SUM(cc.paid_amount),0) AS total_paid,
        COALESCE(SUM(cc.discount_amount),0) AS total_discount,
        COALESCE(SUM(cc.penalty_amount),0) AS total_penalty,
        COALESCE(SUM(cc.net_amount),0) AS total_received
     " . $fromSql;

$summary = [
    'receipt_count' => 0,
    'member_count' => 0,
    'group_count' => 0,
    'total_due' => 0,
    'total_paid' => 0,
    'total_discount' => 0,
    'total_penalty' => 0,
    'total_received' => 0,
];
$stmt = $conn->prepare($summarySql);
if (!$stmt) {
    die('Unable to prepare ledger summary: ' . h($conn->error));
}
bindDynamic($stmt, $types, $params);
$stmt->execute();
$summaryRow = $stmt->get_result()->fetch_assoc();
if ($summaryRow) {
    $summary = array_merge($summary, $summaryRow);
}
$stmt->close();

$totalRows = (int)$summary['receipt_count'];
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$ledgerSql =
    "SELECT
        cc.id AS collection_id,
        cc.receipt_no,
        cc.collection_date,
        cc.due_amount,
        cc.paid_amount,
        cc.discount_amount,
        cc.penalty_amount,
        cc.net_amount,
        cc.reference_no,
        cc.remarks,
        cc.collection_receiver_name,
        cg.id AS group_id,
        cg.group_no,
        cg.group_name,
        cm.id AS member_id,
        cm.ticket_no,
        c.customer_code,
        c.customer_name,
        c.mobile,
        ci.id AS installment_id,
        ci.installment_no,
        ci.due_date,
        COALESCE(pm.method_name, pm.payment_method_name, pm.name, '') AS payment_method,
        CASE
            WHEN (cc.paid_amount + cc.discount_amount) >= (cc.due_amount + cc.penalty_amount) THEN 'Full'
            ELSE 'Partial'
        END AS receipt_status
     " . $fromSql .
    " ORDER BY cc.collection_date DESC, cc.id DESC
      LIMIT ? OFFSET ?";

$ledgerTypes = $types . 'ii';
$ledgerParams = $params;
$ledgerParams[] = $perPage;
$ledgerParams[] = $offset;

$ledgerRows = [];
$stmt = $conn->prepare($ledgerSql);
if (!$stmt) {
    /* Some installations use only one payment method name column. */
    $ledgerSql = str_replace("COALESCE(pm.method_name, pm.payment_method_name, pm.name, '') AS payment_method", "'' AS payment_method", $ledgerSql);
    $stmt = $conn->prepare($ledgerSql);
}
if (!$stmt) {
    die('Unable to prepare received-payment ledger: ' . h($conn->error));
}
bindDynamic($stmt, $ledgerTypes, $ledgerParams);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $ledgerRows[] = $row;
}
$stmt->close();

$pageTotals = [
    'due' => 0.0,
    'paid' => 0.0,
    'discount' => 0.0,
    'penalty' => 0.0,
    'received' => 0.0,
];
foreach ($ledgerRows as $row) {
    $pageTotals['due'] += (float)$row['due_amount'];
    $pageTotals['paid'] += (float)$row['paid_amount'];
    $pageTotals['discount'] += (float)$row['discount_amount'];
    $pageTotals['penalty'] += (float)$row['penalty_amount'];
    $pageTotals['received'] += (float)$row['net_amount'];
}

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

$pageTitle = 'Chit Payment Ledger';
$page_title = 'Chit Payment Ledger';
$currentPage = 'chit-ledger';
$businessName = (string)($_SESSION['business_name'] ?? 'Jewellery ERP');
$currency = (string)($_SESSION['currency_symbol'] ?? '₹');
$fromRecord = $totalRows > 0 ? $offset + 1 : 0;
$toRecord = min($offset + $perPage, $totalRows);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo h($businessName); ?> - Chit Payment Ledger</title>
<?php include 'includes/links.php'; ?>
<style>
:root{--primary:<?php echo h($theme['primary_color']); ?>;--primary-dark:<?php echo h($theme['primary_dark_color']); ?>;--page-bg:<?php echo h($theme['page_background']); ?>;--card-bg:<?php echo h($theme['card_background']); ?>;--text:<?php echo h($theme['text_color']); ?>;--muted:<?php echo h($theme['muted_text_color']); ?>;--line:<?php echo h($theme['border_color']); ?>;--radius:<?php echo (int)$theme['border_radius_px']; ?>px}
body{background:var(--page-bg);color:var(--text);font-family:<?php echo json_encode($theme['font_family']); ?>,sans-serif}.page-heading{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:10px}.page-title{font-family:<?php echo json_encode($theme['heading_font_family']); ?>,serif;font-size:18px;font-weight:800;margin:0}.page-subtitle{font-size:9px;color:var(--muted);margin-top:2px}.summary-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px;margin-bottom:10px}.summary-card,.panel{background:var(--card-bg);border:1px solid var(--line);border-radius:var(--radius)}.summary-card{padding:12px}.summary-label{font-size:8px;text-transform:uppercase;font-weight:700;color:var(--muted)}.summary-value{font-size:16px;font-weight:800;margin-top:4px}.summary-note{font-size:8px;color:var(--muted);margin-top:2px}.panel{overflow:hidden;margin-bottom:10px}.filter-bar{padding:12px;border-bottom:1px solid var(--line)}.filter-grid{display:grid;grid-template-columns:2fr repeat(5,minmax(120px,1fr)) auto;gap:8px;align-items:end}.field-label{display:block;font-size:8px;text-transform:uppercase;font-weight:700;color:var(--muted);margin-bottom:4px}.form-control,.form-select{min-height:36px;font-size:10px;border-radius:9px;border-color:var(--line);background:var(--card-bg);color:var(--text)}.btn-theme{background:linear-gradient(135deg,var(--primary),var(--primary-dark));border:0;color:#fff}.btn-custom{min-height:36px;border-radius:9px;font-size:10px;font-weight:700;padding:8px 11px}.table{font-size:9px;margin:0}.table th{font-size:8px;text-transform:uppercase;letter-spacing:.03em;color:var(--muted);white-space:nowrap}.table td,.table th{padding:9px 10px;border-color:var(--line);background:var(--card-bg)!important;color:var(--text);vertical-align:middle}.main-text{font-size:10px;font-weight:800}.sub-text{font-size:8px;color:var(--muted);margin-top:2px}.status-badge{display:inline-flex;padding:4px 8px;border-radius:999px;font-size:8px;font-weight:800}.status-full{background:#eaf8f0;color:#168449}.status-partial{background:#fff6df;color:#a66800}.amount-positive{color:#168449;font-weight:800}.amount-negative{color:#bd2d2d;font-weight:800}.empty-state{padding:45px 20px;text-align:center;color:var(--muted)}.pagination-wrap{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:11px 13px;border-top:1px solid var(--line);font-size:9px;color:var(--muted)}.pagination{margin:0;gap:3px;flex-wrap:wrap}.page-link{font-size:9px;min-width:30px;text-align:center;border-radius:7px!important;background:var(--card-bg);color:var(--text);border-color:var(--line)}.page-item.active .page-link{background:var(--primary);border-color:var(--primary);color:#fff}.page-item.disabled .page-link{background:var(--card-bg);color:var(--muted)}.theme-toast{position:fixed;right:18px;top:78px;z-index:20000;padding:11px 14px;border-radius:10px;color:#fff;font-size:11px;font-weight:600;opacity:0;transform:translateY(-10px);transition:.22s}.theme-toast.show{opacity:1;transform:none}.theme-toast-success{background:#168449}body.dark-mode,body[data-theme="dark"],html.dark-mode body,html[data-theme="dark"] body{--page-bg:#0f151b;--card-bg:#182129;--text:#f3f6f8;--muted:#9aa7b3;--line:#2c3944;color-scheme:dark}
@media(max-width:1300px){.summary-grid{grid-template-columns:repeat(3,1fr)}.filter-grid{grid-template-columns:repeat(4,1fr)}}
@media(max-width:900px){.summary-grid{grid-template-columns:repeat(2,1fr)}.filter-grid{grid-template-columns:repeat(2,1fr)}.responsive-table thead{display:none}.responsive-table tbody{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;padding:10px}.responsive-table tr{display:grid;grid-template-columns:1fr 1fr;border:1px solid var(--line);border-radius:var(--radius);padding:12px;background:var(--card-bg)}.responsive-table td{display:flex;justify-content:space-between;gap:10px;padding:8px 0;border:0;border-bottom:1px dashed var(--line);text-align:right}.responsive-table td:before{content:attr(data-label);font-size:8px;color:var(--muted);font-weight:700;text-transform:uppercase;text-align:left}.responsive-table td.main-column{grid-column:1/-1;display:block;text-align:left}.responsive-table td.main-column:before{display:none}.pagination-wrap{align-items:flex-start;flex-direction:column}}
@media(max-width:600px){.page-heading{align-items:flex-start;flex-direction:column}.summary-grid,.filter-grid,.responsive-table tbody{grid-template-columns:1fr}.responsive-table tr{grid-template-columns:1fr}.responsive-table td{grid-column:1/-1}.theme-toast{left:12px;right:12px;top:70px}}
@media print{.sidebar,.navbar-header,.page-heading .btn,.filter-bar,.pagination-wrap,.footer{display:none!important}.app-main{margin-left:0!important;width:100%!important}.content-wrap{padding:0!important}.panel,.summary-card{border:1px solid #ddd!important}.table{font-size:8px}}
</style>
</head>
<body>
<?php include 'includes/sidebar.php'; ?>
<main class="app-main">
<?php include 'includes/nav.php'; ?>
<div class="content-wrap">
    <div class="page-heading">
        <div>
            <h1 class="page-title">Chit Payment Ledger</h1>
            <div class="page-subtitle"><?php echo h($businessName); ?> · Received chit-payment transactions only</div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <button type="button" class="btn btn-light btn-custom" onclick="window.print()"><i class="fa-solid fa-print me-1"></i>Print</button>
            <a href="chit-groups.php" class="btn btn-light btn-custom">Chit Groups</a>
        </div>
    </div>

    <div class="summary-grid">
        <div class="summary-card"><div class="summary-label">Receipts</div><div class="summary-value"><?php echo number_format((int)$summary['receipt_count']); ?></div><div class="summary-note">Filtered received payments</div></div>
        <div class="summary-card"><div class="summary-label">Members Paid</div><div class="summary-value"><?php echo number_format((int)$summary['member_count']); ?></div><div class="summary-note"><?php echo number_format((int)$summary['group_count']); ?> chit groups</div></div>
        <div class="summary-card"><div class="summary-label">Due Amount</div><div class="summary-value"><?php echo $canViewValue ? h($currency) . number_format((float)$summary['total_due'], 2) : '••••'; ?></div></div>
        <div class="summary-card"><div class="summary-label">Paid Amount</div><div class="summary-value"><?php echo $canViewValue ? h($currency) . number_format((float)$summary['total_paid'], 2) : '••••'; ?></div></div>
        <div class="summary-card"><div class="summary-label">Discount / Penalty</div><div class="summary-value"><?php echo $canViewValue ? h($currency) . number_format((float)$summary['total_discount'], 2) . ' / ' . h($currency) . number_format((float)$summary['total_penalty'], 2) : '••••'; ?></div></div>
        <div class="summary-card"><div class="summary-label">Net Received</div><div class="summary-value amount-positive"><?php echo $canViewValue ? h($currency) . number_format((float)$summary['total_received'], 2) : '••••'; ?></div></div>
    </div>

    <div class="panel">
        <form method="get" class="filter-bar" action="chit-ledger.php">
            <div class="filter-grid">
                <div><label class="field-label">Search</label><input type="search" name="search" class="form-control" value="<?php echo h($search); ?>" placeholder="Receipt, reference, group, ticket, customer or mobile"></div>
                <div><label class="field-label">Chit Group</label><select name="group_id" id="groupFilter" class="form-select"><option value="0">All Groups</option><?php foreach ($groups as $group): ?><option value="<?php echo (int)$group['id']; ?>" <?php echo $groupId === (int)$group['id'] ? 'selected' : ''; ?>><?php echo h($group['group_no'] . ' - ' . $group['group_name']); ?></option><?php endforeach; ?></select></div>
                <div><label class="field-label">Member</label><select name="member_id" id="memberFilter" class="form-select"><option value="0">All Members</option><?php foreach ($members as $member): ?><option value="<?php echo (int)$member['id']; ?>" data-group="<?php echo (int)$member['chit_group_id']; ?>" <?php echo $memberId === (int)$member['id'] ? 'selected' : ''; ?>><?php echo h($member['ticket_no'] . ' - ' . $member['customer_name']); ?></option><?php endforeach; ?></select></div>
                <div><label class="field-label">Receipt Status</label><select name="status" class="form-select"><option value="all" <?php echo $receiptStatus === 'all' ? 'selected' : ''; ?>>All Received</option><option value="full" <?php echo $receiptStatus === 'full' ? 'selected' : ''; ?>>Full Payment</option><option value="partial" <?php echo $receiptStatus === 'partial' ? 'selected' : ''; ?>>Partial Payment</option><option value="discount" <?php echo $receiptStatus === 'discount' ? 'selected' : ''; ?>>With Discount</option><option value="penalty" <?php echo $receiptStatus === 'penalty' ? 'selected' : ''; ?>>With Penalty</option></select></div>
                <div><label class="field-label">Collected From</label><input type="date" name="date_from" class="form-control" value="<?php echo h($dateFrom); ?>"></div>
                <div><label class="field-label">Collected To</label><input type="date" name="date_to" class="form-control" value="<?php echo h($dateTo); ?>"></div>
                <div class="d-flex gap-2"><input type="hidden" name="per_page" value="<?php echo $perPage; ?>"><button class="btn btn-theme btn-custom" type="submit"><i class="fa-solid fa-filter me-1"></i>Apply</button><a href="chit-ledger.php" class="btn btn-light btn-custom">Reset</a></div>
            </div>
        </form>

        <?php if (!$canView): ?>
            <div class="empty-state">You do not have permission to view received chit payments.</div>
        <?php elseif (!$ledgerRows): ?>
            <div class="empty-state"><i class="fa-regular fa-folder-open fa-2x mb-2"></i><div>No received payments found for the selected filters.</div></div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table responsive-table">
                    <thead><tr><th>#</th><th>Receipt</th><th>Group / Member</th><th>Installment</th><th>Collected</th><th>Due</th><th>Paid</th><th>Discount</th><th>Penalty</th><th>Net Received</th><th>Status</th><th>Reference</th></tr></thead>
                    <tbody>
                    <?php foreach ($ledgerRows as $index => $row): ?>
                        <?php $badgeClass = $row['receipt_status'] === 'Full' ? 'status-full' : 'status-partial'; ?>
                        <tr>
                            <td data-label="#"><?php echo $offset + $index + 1; ?></td>
                            <td data-label="Receipt"><div class="main-text"><?php echo h($row['receipt_no']); ?></div><div class="sub-text"><?php echo h($row['payment_method'] ?: 'Payment received'); ?></div></td>
                            <td class="main-column" data-label="Group / Member"><div class="main-text"><?php echo h($row['customer_name']); ?></div><div class="sub-text"><?php echo h($row['ticket_no'] . ' · ' . $row['group_no'] . ' · ' . $row['group_name']); ?></div></td>
                            <td data-label="Installment"><div class="main-text"><?php echo (int)$row['installment_no']; ?></div><div class="sub-text">Due <?php echo h(date('d-m-Y', strtotime($row['due_date']))); ?></div></td>
                            <td data-label="Collected"><?php echo h(date('d-m-Y', strtotime($row['collection_date']))); ?></td>
                            <td data-label="Due"><?php echo $canViewValue ? h($currency) . number_format((float)$row['due_amount'], 2) : '••••'; ?></td>
                            <td data-label="Paid" class="amount-positive"><?php echo $canViewValue ? h($currency) . number_format((float)$row['paid_amount'], 2) : '••••'; ?></td>
                            <td data-label="Discount"><?php echo $canViewValue ? h($currency) . number_format((float)$row['discount_amount'], 2) : '••••'; ?></td>
                            <td data-label="Penalty" class="<?php echo (float)$row['penalty_amount'] > 0 ? 'amount-negative' : ''; ?>"><?php echo $canViewValue ? h($currency) . number_format((float)$row['penalty_amount'], 2) : '••••'; ?></td>
                            <td data-label="Net Received" class="amount-positive"><?php echo $canViewValue ? h($currency) . number_format((float)$row['net_amount'], 2) : '••••'; ?></td>
                            <td data-label="Status"><span class="status-badge <?php echo $badgeClass; ?>"><?php echo h($row['receipt_status']); ?></span></td>
                            <td data-label="Reference"><div class="main-text"><?php echo h($row['reference_no'] ?: '—'); ?></div><?php if (!empty($row['collection_receiver_name'])): ?><div class="sub-text">By <?php echo h($row['collection_receiver_name']); ?></div><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <?php if ($canViewValue): ?>
                    <tfoot><tr><th colspan="5" class="text-end">This Page</th><th><?php echo h($currency) . number_format($pageTotals['due'], 2); ?></th><th><?php echo h($currency) . number_format($pageTotals['paid'], 2); ?></th><th><?php echo h($currency) . number_format($pageTotals['discount'], 2); ?></th><th><?php echo h($currency) . number_format($pageTotals['penalty'], 2); ?></th><th><?php echo h($currency) . number_format($pageTotals['received'], 2); ?></th><th colspan="2"></th></tr></tfoot>
                    <?php endif; ?>
                </table>
            </div>
        <?php endif; ?>

        <div class="pagination-wrap">
            <div>Showing <?php echo number_format($fromRecord); ?>–<?php echo number_format($toRecord); ?> of <?php echo number_format($totalRows); ?> received payments</div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <form method="get" action="chit-ledger.php" class="d-flex align-items-center gap-2">
                    <?php foreach ($_GET as $key => $value): if (in_array($key, ['page', 'per_page'], true) || is_array($value)) continue; ?>
                        <input type="hidden" name="<?php echo h($key); ?>" value="<?php echo h($value); ?>">
                    <?php endforeach; ?>
                    <label class="text-nowrap">Rows</label>
                    <select name="per_page" class="form-select" style="min-height:30px;width:72px" onchange="this.form.submit()">
                        <?php foreach ($allowedPerPage as $size): ?><option value="<?php echo $size; ?>" <?php echo $perPage === $size ? 'selected' : ''; ?>><?php echo $size; ?></option><?php endforeach; ?>
                    </select>
                </form>
                <?php if ($totalPages > 1): ?>
                <nav aria-label="Payment ledger pages">
                    <ul class="pagination pagination-sm">
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo h(ledgerUrl(['page' => max(1, $page - 1)])); ?>">‹</a></li>
                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                        if ($startPage > 1): ?>
                            <li class="page-item"><a class="page-link" href="<?php echo h(ledgerUrl(['page' => 1])); ?>">1</a></li>
                            <?php if ($startPage > 2): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
                        <?php endif; ?>
                        <?php for ($pageNo = $startPage; $pageNo <= $endPage; $pageNo++): ?>
                            <li class="page-item <?php echo $pageNo === $page ? 'active' : ''; ?>"><a class="page-link" href="<?php echo h(ledgerUrl(['page' => $pageNo])); ?>"><?php echo $pageNo; ?></a></li>
                        <?php endfor; ?>
                        <?php if ($endPage < $totalPages): ?>
                            <?php if ($endPage < $totalPages - 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
                            <li class="page-item"><a class="page-link" href="<?php echo h(ledgerUrl(['page' => $totalPages])); ?>"><?php echo $totalPages; ?></a></li>
                        <?php endif; ?>
                        <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo h(ledgerUrl(['page' => min($totalPages, $page + 1)])); ?>">›</a></li>
                    </ul>
                </nav>
                <?php endif; ?>
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
    const messageCode = params.get('msg');
    if (messageCode === 'payment_collected') {
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