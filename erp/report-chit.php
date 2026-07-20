<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));

foreach ([
    __DIR__ . '/config/config.php',
    __DIR__ . '/config.php',
    __DIR__ . '/includes/config.php',
    __DIR__ . '/super-admin/includes/config.php'
] as $file) {
    if (is_file($file)) {
        require_once $file;
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

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function money($value): string
{
    return '₹ ' . number_format((float)$value, 2);
}

function tableExists(mysqli $conn, string $table): bool
{
    $safe = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $result && $result->num_rows > 0;
}

function fetchAllRows(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($conn->error);
    }

    if ($types !== '') {
        $bind = [$types];
        foreach ($params as $index => $value) {
            $bind[] =& $params[$index];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind);
    }

    if (!$stmt->execute()) {
        throw new RuntimeException($stmt->error);
    }

    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    $stmt->close();
    return $rows;
}

function fetchOneRow(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $rows = fetchAllRows($conn, $sql, $types, $params);
    return $rows[0] ?? [];
}

function hasReportPermission(): bool
{
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin') {
        return true;
    }

    $permissions = $_SESSION['permissions'] ?? [];
    foreach (['perm.Report.chit', 'perm.reports', 'perm.chit'] as $code) {
        if (isset($permissions[$code])) {
            $row = $permissions[$code];
            if ((int)($row['can_open'] ?? 0) === 1 || (int)($row['can_view'] ?? 0) === 1) {
                return true;
            }
        }
    }

    return true;
}

if (!hasReportPermission()) {
    http_response_code(403);
    die('Access denied. You do not have permission to view chit reports.');
}

$requiredTables = ['chit_groups', 'chit_members', 'chit_installments', 'chit_collections', 'customers'];
foreach ($requiredTables as $table) {
    if (!tableExists($conn, $table)) {
        die("Required table '{$table}' is missing.");
    }
}

$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? ($_SESSION['default_branch_id'] ?? 0));
$currency = (string)($_SESSION['currency_symbol'] ?? '₹');

if ($businessId <= 0 || $branchId <= 0) {
    die('A valid business and branch must be selected.');
}

$today = date('Y-m-d');
$monthStart = date('Y-m-01');

$dateFrom = trim((string)($_GET['date_from'] ?? $monthStart));
$dateTo = trim((string)($_GET['date_to'] ?? $today));
$groupId = (int)($_GET['group_id'] ?? 0);
$customerId = (int)($_GET['customer_id'] ?? 0);
$groupStatus = trim((string)($_GET['group_status'] ?? ''));
$collectionStatus = trim((string)($_GET['collection_status'] ?? ''));
$search = trim((string)($_GET['search'] ?? ''));
$export = (string)($_GET['export'] ?? '');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = $monthStart;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = $today;
}

$groups = fetchAllRows(
    $conn,
    "SELECT id, group_no, group_name, status
     FROM chit_groups
     WHERE business_id=? AND branch_id=?
     ORDER BY start_date DESC, id DESC",
    'ii',
    [$businessId, $branchId]
);

$customers = fetchAllRows(
    $conn,
    "SELECT DISTINCT c.id, c.customer_name, c.mobile
     FROM customers c
     INNER JOIN chit_members cm
        ON cm.customer_id=c.id
       AND cm.business_id=c.business_id
     INNER JOIN chit_groups cg
        ON cg.id=cm.chit_group_id
       AND cg.business_id=cm.business_id
     WHERE c.business_id=? AND cg.branch_id=?
     ORDER BY c.customer_name",
    'ii',
    [$businessId, $branchId]
);

$where = " WHERE cg.business_id=? AND cg.branch_id=? ";
$types = 'ii';
$params = [$businessId, $branchId];

if ($groupId > 0) {
    $where .= " AND cg.id=? ";
    $types .= 'i';
    $params[] = $groupId;
}
if ($customerId > 0) {
    $where .= " AND c.id=? ";
    $types .= 'i';
    $params[] = $customerId;
}
if ($groupStatus !== '') {
    $where .= " AND cg.status=? ";
    $types .= 's';
    $params[] = $groupStatus;
}
if ($search !== '') {
    $like = '%' . $search . '%';
    $where .= " AND (
        cg.group_no LIKE ?
        OR cg.group_name LIKE ?
        OR c.customer_name LIKE ?
        OR c.mobile LIKE ?
        OR cm.ticket_no LIKE ?
    ) ";
    $types .= 'sssss';
    array_push($params, $like, $like, $like, $like, $like);
}

$summarySql = "
    SELECT
        COUNT(DISTINCT cg.id) AS total_groups,
        COUNT(DISTINCT cm.id) AS total_members,
        COALESCE(SUM(DISTINCT cg.chit_value),0) AS total_chit_value,
        COALESCE(SUM(DISTINCT cg.installment_amount),0) AS total_monthly_installment
    FROM chit_groups cg
    LEFT JOIN chit_members cm
      ON cm.chit_group_id=cg.id
     AND cm.business_id=cg.business_id
    LEFT JOIN customers c
      ON c.id=cm.customer_id
     AND c.business_id=cm.business_id
    {$where}
";
$summary = fetchOneRow($conn, $summarySql, $types, $params);

$collectionWhere = $where . " AND cc.collection_date BETWEEN ? AND ? ";
$collectionTypes = $types . 'ss';
$collectionParams = array_merge($params, [$dateFrom, $dateTo]);

if ($collectionStatus === 'paid') {
    $collectionWhere .= " AND cc.paid_amount > 0 ";
} elseif ($collectionStatus === 'discount') {
    $collectionWhere .= " AND cc.discount_amount > 0 ";
} elseif ($collectionStatus === 'penalty') {
    $collectionWhere .= " AND cc.penalty_amount > 0 ";
}

$collectionSummary = fetchOneRow(
    $conn,
    "
    SELECT
        COUNT(cc.id) AS receipt_count,
        COALESCE(SUM(cc.due_amount),0) AS total_due,
        COALESCE(SUM(cc.paid_amount),0) AS total_paid,
        COALESCE(SUM(cc.discount_amount),0) AS total_discount,
        COALESCE(SUM(cc.penalty_amount),0) AS total_penalty,
        COALESCE(SUM(cc.net_amount),0) AS total_collection
    FROM chit_groups cg
    INNER JOIN chit_members cm
       ON cm.chit_group_id=cg.id
      AND cm.business_id=cg.business_id
    INNER JOIN customers c
       ON c.id=cm.customer_id
      AND c.business_id=cm.business_id
    INNER JOIN chit_collections cc
       ON cc.chit_group_id=cg.id
      AND cc.chit_member_id=cm.id
      AND cc.business_id=cg.business_id
      AND cc.branch_id=cg.branch_id
    {$collectionWhere}
    ",
    $collectionTypes,
    $collectionParams
);

$collectionRows = fetchAllRows(
    $conn,
    "
    SELECT
        cc.id,
        cc.receipt_no,
        cc.collection_date,
        cc.due_amount,
        cc.paid_amount,
        cc.discount_amount,
        cc.penalty_amount,
        cc.net_amount,
        cc.reference_no,
        cc.remarks,
        pm.method_name,
        cg.group_no,
        cg.group_name,
        cm.ticket_no,
        c.customer_name,
        c.mobile,
        ci.installment_no,
        ci.due_date
    FROM chit_groups cg
    INNER JOIN chit_members cm
       ON cm.chit_group_id=cg.id
      AND cm.business_id=cg.business_id
    INNER JOIN customers c
       ON c.id=cm.customer_id
      AND c.business_id=cm.business_id
    INNER JOIN chit_collections cc
       ON cc.chit_group_id=cg.id
      AND cc.chit_member_id=cm.id
      AND cc.business_id=cg.business_id
      AND cc.branch_id=cg.branch_id
    LEFT JOIN chit_installments ci
       ON ci.id=cc.chit_installment_id
      AND ci.business_id=cc.business_id
    LEFT JOIN payment_methods pm
       ON pm.id=cc.payment_method_id
    {$collectionWhere}
    ORDER BY cc.collection_date DESC, cc.id DESC
    ",
    $collectionTypes,
    $collectionParams
);

$memberRows = fetchAllRows(
    $conn,
    "
    SELECT
        cg.id AS group_id,
        cg.group_no,
        cg.group_name,
        cg.chit_type,
        cg.start_date,
        cg.end_date,
        cg.total_months,
        cg.installment_amount,
        cg.chit_value,
        cg.status AS group_status,
        cm.id AS member_id,
        cm.ticket_no,
        cm.join_date,
        cm.status AS member_status,
        c.id AS customer_id,
        c.customer_name,
        c.mobile,
        COUNT(DISTINCT cc.id) AS paid_installments,
        COALESCE(SUM(cc.net_amount),0) AS collected_amount
    FROM chit_groups cg
    INNER JOIN chit_members cm
       ON cm.chit_group_id=cg.id
      AND cm.business_id=cg.business_id
    INNER JOIN customers c
       ON c.id=cm.customer_id
      AND c.business_id=cm.business_id
    LEFT JOIN chit_collections cc
       ON cc.chit_member_id=cm.id
      AND cc.chit_group_id=cg.id
      AND cc.business_id=cg.business_id
      AND cc.branch_id=cg.branch_id
    {$where}
    GROUP BY
        cg.id,cg.group_no,cg.group_name,cg.chit_type,cg.start_date,cg.end_date,
        cg.total_months,cg.installment_amount,cg.chit_value,cg.status,
        cm.id,cm.ticket_no,cm.join_date,cm.status,
        c.id,c.customer_name,c.mobile
    ORDER BY cg.start_date DESC, cg.id DESC, cm.ticket_no
    ",
    $types,
    $params
);

$dueRows = fetchAllRows(
    $conn,
    "
    SELECT
        cg.group_no,
        cg.group_name,
        ci.installment_no,
        ci.due_date,
        ci.status AS installment_status,
        COUNT(cm.id) AS member_count,
        COUNT(cc.id) AS paid_count,
        COALESCE(SUM(cc.net_amount),0) AS collected_amount,
        (COUNT(cm.id) * cg.installment_amount) AS expected_amount
    FROM chit_groups cg
    INNER JOIN chit_installments ci
       ON ci.chit_group_id=cg.id
      AND ci.business_id=cg.business_id
    LEFT JOIN chit_members cm
       ON cm.chit_group_id=cg.id
      AND cm.business_id=cg.business_id
      AND cm.status='Active'
    LEFT JOIN chit_collections cc
       ON cc.chit_installment_id=ci.id
      AND cc.chit_member_id=cm.id
      AND cc.business_id=cg.business_id
      AND cc.branch_id=cg.branch_id
    WHERE cg.business_id=? AND cg.branch_id=?
      AND ci.due_date BETWEEN ? AND ?
      " . ($groupId > 0 ? " AND cg.id=? " : "") . "
    GROUP BY
        cg.id,cg.group_no,cg.group_name,cg.installment_amount,
        ci.id,ci.installment_no,ci.due_date,ci.status
    ORDER BY ci.due_date DESC, cg.group_no
    ",
    $groupId > 0 ? 'iissi' : 'iiss',
    $groupId > 0
        ? [$businessId, $branchId, $dateFrom, $dateTo, $groupId]
        : [$businessId, $branchId, $dateFrom, $dateTo]
);

if ($export === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="chit-report-' . date('Ymd-His') . '.xls"');

    echo '<html><head><meta charset="utf-8"></head><body>';
    echo '<h2>Chit Collection Report</h2>';
    echo '<p>Period: ' . e(date('d-m-Y', strtotime($dateFrom))) . ' to ' . e(date('d-m-Y', strtotime($dateTo))) . '</p>';
    echo '<table border="1">';
    echo '<tr><th>Date</th><th>Receipt</th><th>Group</th><th>Ticket</th><th>Customer</th><th>Installment</th><th>Due</th><th>Paid</th><th>Discount</th><th>Penalty</th><th>Net</th><th>Method</th><th>Reference</th></tr>';

    foreach ($collectionRows as $row) {
        echo '<tr>';
        echo '<td>' . e(date('d-m-Y', strtotime($row['collection_date']))) . '</td>';
        echo '<td>' . e($row['receipt_no']) . '</td>';
        echo '<td>' . e($row['group_no'] . ' - ' . $row['group_name']) . '</td>';
        echo '<td>' . e($row['ticket_no']) . '</td>';
        echo '<td>' . e($row['customer_name']) . '</td>';
        echo '<td>' . e($row['installment_no']) . '</td>';
        echo '<td>' . number_format((float)$row['due_amount'], 2, '.', '') . '</td>';
        echo '<td>' . number_format((float)$row['paid_amount'], 2, '.', '') . '</td>';
        echo '<td>' . number_format((float)$row['discount_amount'], 2, '.', '') . '</td>';
        echo '<td>' . number_format((float)$row['penalty_amount'], 2, '.', '') . '</td>';
        echo '<td>' . number_format((float)$row['net_amount'], 2, '.', '') . '</td>';
        echo '<td>' . e($row['method_name'] ?: '—') . '</td>';
        echo '<td>' . e($row['reference_no'] ?: '—') . '</td>';
        echo '</tr>';
    }

    echo '</table></body></html>';
    exit;
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
];

if (tableExists($conn, 'business_theme_settings')) {
    $row = fetchOneRow(
        $conn,
        'SELECT * FROM business_theme_settings WHERE business_id=? LIMIT 1',
        'i',
        [$businessId]
    );
    foreach ($theme as $key => $value) {
        if (isset($row[$key]) && $row[$key] !== '') {
            $theme[$key] = $row[$key];
        }
    }
}

$businessName = (string)($_SESSION['business_name'] ?? 'Jewellery ERP');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($businessName) ?> - Chit Report</title>
    <?php include('includes/links.php'); ?>
    <style>
        :root{
            --primary:<?= e($theme['primary_color']) ?>;
            --primary-dark:<?= e($theme['primary_dark_color']) ?>;
            --primary-soft:<?= e($theme['primary_soft_color']) ?>;
            --page-bg:<?= e($theme['page_background']) ?>;
            --card-bg:<?= e($theme['card_background']) ?>;
            --text:<?= e($theme['text_color']) ?>;
            --muted:<?= e($theme['muted_text_color']) ?>;
            --line:<?= e($theme['border_color']) ?>;
            --radius:<?= (int)$theme['border_radius_px'] ?>px;
        }

        body{
            background:var(--page-bg);
            color:var(--text);
            font-family:<?= json_encode($theme['font_family']) ?>,sans-serif;
        }

        .sidebar{
            background:linear-gradient(
                180deg,
                <?= e($theme['sidebar_gradient_1']) ?>,
                <?= e($theme['sidebar_gradient_2']) ?>,
                <?= e($theme['sidebar_gradient_3']) ?>
            )!important;
        }

        .report-card,.summary-card{
            background:var(--card-bg);
            border:1px solid var(--line);
            border-radius:var(--radius);
        }

        .report-head{
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:12px;
            padding:14px 16px;
        }

        .report-title{
            margin:0;
            font:700 21px <?= json_encode($theme['heading_font_family']) ?>,serif;
        }

        .report-subtitle{
            color:var(--muted);
            font-size:10px;
            margin-top:2px;
        }

        .action-group{
            display:flex;
            flex-wrap:wrap;
            gap:7px;
        }

        .btn-theme,.btn-soft{
            min-height:37px;
            border-radius:9px;
            padding:8px 12px;
            font-size:10px;
            font-weight:800;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:6px;
            text-decoration:none;
        }

        .btn-theme{
            border:0;
            color:#fff;
            background:linear-gradient(135deg,var(--primary),var(--primary-dark));
        }

        .btn-soft{
            border:1px solid var(--line);
            color:var(--text);
            background:var(--card-bg);
        }

        .filter-card{
            padding:12px;
            margin-bottom:12px;
        }

        .filter-grid{
            display:grid;
            grid-template-columns:repeat(7,minmax(0,1fr));
            gap:8px;
        }

        .form-control,.form-select{
            min-height:38px;
            border:1px solid var(--line);
            border-radius:9px;
            background:var(--card-bg);
            color:var(--text);
            font-size:10px;
        }

        .summary-grid{
            display:grid;
            grid-template-columns:repeat(6,minmax(0,1fr));
            gap:10px;
            margin-bottom:12px;
        }

        .summary-card{
            padding:13px;
            display:flex;
            align-items:center;
            gap:10px;
        }

        .summary-icon{
            width:40px;
            height:40px;
            border-radius:10px;
            background:var(--primary-soft);
            color:var(--primary-dark);
            display:grid;
            place-items:center;
            flex:0 0 auto;
        }

        .summary-label{
            color:var(--muted);
            font-size:8px;
            text-transform:uppercase;
        }

        .summary-value{
            font-size:17px;
            font-weight:900;
            margin-top:2px;
        }

        .nav-tabs{
            border-bottom:1px solid var(--line);
            padding:0 12px;
        }

        .nav-tabs .nav-link{
            border:0;
            color:var(--muted);
            font-size:10px;
            font-weight:800;
            padding:11px 13px;
        }

        .nav-tabs .nav-link.active{
            color:var(--primary-dark);
            border-bottom:2px solid var(--primary);
            background:transparent;
        }

        .table{
            margin:0;
            font-size:9px;
        }

        .table th{
            color:var(--muted);
            font-size:8px;
            text-transform:uppercase;
            background:color-mix(in srgb,var(--muted) 6%,transparent);
            white-space:nowrap;
        }

        .table th,.table td{
            padding:9px 10px;
            border-color:var(--line);
            vertical-align:middle;
        }

        .text-money{
            text-align:right;
            white-space:nowrap;
            font-weight:800;
        }

        .badge-soft{
            display:inline-flex;
            border-radius:999px;
            padding:4px 7px;
            font-size:8px;
            font-weight:800;
        }

        .badge-active,.badge-paid{background:#eaf8f0;color:#168449}
        .badge-open{background:#fff4dc;color:#946000}
        .badge-closed{background:#eaf0ff;color:#3155a6}
        .badge-cancelled{background:#fdecec;color:#bd2d2d}

        .member-progress{
            min-width:120px;
        }

        .progress{
            height:6px;
            background:color-mix(in srgb,var(--muted) 14%,transparent);
        }

        .progress-bar{
            background:linear-gradient(90deg,var(--primary),var(--primary-dark));
        }

        .empty-state{
            text-align:center;
            padding:34px 15px;
            color:var(--muted);
        }

        .print-header{
            display:none;
        }

        body.dark-mode,body[data-theme=dark]{
            --page-bg:#0f151b;
            --card-bg:#182129;
            --text:#f3f6f8;
            --muted:#9aa7b3;
            --line:#2c3944;
        }

        @media(max-width:1199px){
            .filter-grid{grid-template-columns:repeat(3,minmax(0,1fr))}
            .summary-grid{grid-template-columns:repeat(3,minmax(0,1fr))}
        }

        @media(max-width:767px){
            .report-head{align-items:flex-start;flex-direction:column}
            .filter-grid,.summary-grid{grid-template-columns:1fr}
            .table-responsive{overflow-x:auto}
        }

        @media print{
            .sidebar,.app-nav,.nav,.filter-card,.action-group,.nav-tabs,.footer,
            .content-wrap > .report-card:first-child{display:none!important}
            .app-main{margin:0!important}
            .content-wrap{padding:0!important}
            body{background:#fff!important;color:#000!important}
            .print-header{display:block;text-align:center;margin-bottom:14px}
            .report-card,.summary-card{box-shadow:none!important;border:1px solid #ccc!important}
            .tab-pane{display:block!important;opacity:1!important}
            .tab-pane:not(:first-child){page-break-before:always}
            .table{font-size:8px}
        }
    </style>
</head>
<body>
<?php include('includes/sidebar.php'); ?>

<main class="app-main">
    <?php include('includes/nav.php'); ?>

    <div class="content-wrap">
        <div class="print-header">
            <h2><?= e($businessName) ?></h2>
            <h3>Chit Report</h3>
            <div><?= e(date('d-m-Y', strtotime($dateFrom))) ?> to <?= e(date('d-m-Y', strtotime($dateTo))) ?></div>
        </div>

        <section class="report-card mb-3">
            <div class="report-head">
                <div>
                    <h1 class="report-title">Chit Report</h1>
                    <div class="report-subtitle">
                        Group, member, installment, collection and outstanding report.
                    </div>
                </div>

                <div class="action-group">
                    <a class="btn-soft" href="chit-groups.php">
                        <i class="fa-solid fa-layer-group"></i>Chit Groups
                    </a>
                    <a class="btn-soft" href="chit-ledger.php">
                        <i class="fa-solid fa-book"></i>Chit Ledger
                    </a>
                    <a class="btn-soft" href="chit-due-list.php">
                        <i class="fa-solid fa-calendar-xmark"></i>Due List
                    </a>
                    <button type="button" class="btn-soft" onclick="window.print()">
                        <i class="fa-solid fa-print"></i>Print
                    </button>
                    <a class="btn-theme"
                       href="?<?= e(http_build_query(array_merge($_GET, ['export'=>'excel']))) ?>">
                        <i class="fa-solid fa-file-excel"></i>Excel
                    </a>
                </div>
            </div>
        </section>

        <form method="get" class="report-card filter-card">
            <div class="filter-grid">
                <input type="date" name="date_from" class="form-control" value="<?= e($dateFrom) ?>">
                <input type="date" name="date_to" class="form-control" value="<?= e($dateTo) ?>">

                <select name="group_id" class="form-select">
                    <option value="">All Chit Groups</option>
                    <?php foreach ($groups as $group): ?>
                        <option value="<?= (int)$group['id'] ?>" <?= $groupId === (int)$group['id'] ? 'selected' : '' ?>>
                            <?= e($group['group_no'] . ' - ' . $group['group_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="customer_id" class="form-select">
                    <option value="">All Customers</option>
                    <?php foreach ($customers as $customer): ?>
                        <option value="<?= (int)$customer['id'] ?>" <?= $customerId === (int)$customer['id'] ? 'selected' : '' ?>>
                            <?= e($customer['customer_name'] . ($customer['mobile'] ? ' - ' . $customer['mobile'] : '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="group_status" class="form-select">
                    <option value="">All Group Status</option>
                    <?php foreach (['Draft','Active','Closed','Cancelled'] as $status): ?>
                        <option value="<?= e($status) ?>" <?= $groupStatus === $status ? 'selected' : '' ?>>
                            <?= e($status) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <input type="search" name="search" class="form-control"
                       placeholder="Group, ticket, customer, mobile..."
                       value="<?= e($search) ?>">

                <div class="d-flex gap-2">
                    <button type="submit" class="btn-theme flex-grow-1">
                        <i class="fa-solid fa-filter"></i>Apply
                    </button>
                    <a href="report-chit.php" class="btn-soft">
                        <i class="fa-solid fa-rotate-left"></i>
                    </a>
                </div>
            </div>
        </form>

        <section class="summary-grid">
            <div class="summary-card">
                <div class="summary-icon"><i class="fa-solid fa-layer-group"></i></div>
                <div><div class="summary-label">Chit Groups</div><div class="summary-value"><?= (int)($summary['total_groups'] ?? 0) ?></div></div>
            </div>
            <div class="summary-card">
                <div class="summary-icon"><i class="fa-solid fa-users"></i></div>
                <div><div class="summary-label">Members</div><div class="summary-value"><?= (int)($summary['total_members'] ?? 0) ?></div></div>
            </div>
            <div class="summary-card">
                <div class="summary-icon"><i class="fa-solid fa-sack-dollar"></i></div>
                <div><div class="summary-label">Chit Value</div><div class="summary-value"><?= e(money($summary['total_chit_value'] ?? 0)) ?></div></div>
            </div>
            <div class="summary-card">
                <div class="summary-icon"><i class="fa-solid fa-receipt"></i></div>
                <div><div class="summary-label">Receipts</div><div class="summary-value"><?= (int)($collectionSummary['receipt_count'] ?? 0) ?></div></div>
            </div>
            <div class="summary-card">
                <div class="summary-icon"><i class="fa-solid fa-indian-rupee-sign"></i></div>
                <div><div class="summary-label">Collected</div><div class="summary-value"><?= e(money($collectionSummary['total_collection'] ?? 0)) ?></div></div>
            </div>
            <div class="summary-card">
                <div class="summary-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
                <div>
                    <div class="summary-label">Difference</div>
                    <div class="summary-value">
                        <?= e(money(
                            (float)($collectionSummary['total_due'] ?? 0)
                            + (float)($collectionSummary['total_penalty'] ?? 0)
                            - (float)($collectionSummary['total_paid'] ?? 0)
                            - (float)($collectionSummary['total_discount'] ?? 0)
                        )) ?>
                    </div>
                </div>
            </div>
        </section>

        <section class="report-card">
            <ul class="nav nav-tabs" role="tablist">
                <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#collections" type="button">Collections</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#members" type="button">Group & Members</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#installments" type="button">Installments & Due</button></li>
            </ul>

            <div class="tab-content">
                <div class="tab-pane fade show active" id="collections">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                            <tr>
                                <th>Date</th>
                                <th>Receipt</th>
                                <th>Group</th>
                                <th>Ticket</th>
                                <th>Customer</th>
                                <th>Installment</th>
                                <th class="text-end">Due</th>
                                <th class="text-end">Paid</th>
                                <th class="text-end">Discount</th>
                                <th class="text-end">Penalty</th>
                                <th class="text-end">Net</th>
                                <th>Method</th>
                                <th>Reference</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if (!$collectionRows): ?>
                                <tr><td colspan="13"><div class="empty-state">No chit collections found for the selected filters.</div></td></tr>
                            <?php else: ?>
                                <?php foreach ($collectionRows as $row): ?>
                                    <tr>
                                        <td><?= e(date('d-m-Y', strtotime($row['collection_date']))) ?></td>
                                        <td><strong><?= e($row['receipt_no']) ?></strong></td>
                                        <td><?= e($row['group_no']) ?><div class="text-muted"><?= e($row['group_name']) ?></div></td>
                                        <td><?= e($row['ticket_no']) ?></td>
                                        <td><?= e($row['customer_name']) ?><div class="text-muted"><?= e($row['mobile']) ?></div></td>
                                        <td>#<?= e($row['installment_no'] ?: '—') ?><div class="text-muted"><?= $row['due_date'] ? e(date('d-m-Y', strtotime($row['due_date']))) : '—' ?></div></td>
                                        <td class="text-money"><?= e(money($row['due_amount'])) ?></td>
                                        <td class="text-money"><?= e(money($row['paid_amount'])) ?></td>
                                        <td class="text-money"><?= e(money($row['discount_amount'])) ?></td>
                                        <td class="text-money"><?= e(money($row['penalty_amount'])) ?></td>
                                        <td class="text-money"><?= e(money($row['net_amount'])) ?></td>
                                        <td><?= e($row['method_name'] ?: '—') ?></td>
                                        <td><?= e($row['reference_no'] ?: '—') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="tab-pane fade" id="members">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                            <tr>
                                <th>Group</th>
                                <th>Type</th>
                                <th>Customer</th>
                                <th>Ticket</th>
                                <th>Join Date</th>
                                <th>Period</th>
                                <th class="text-end">Installment</th>
                                <th class="text-end">Chit Value</th>
                                <th>Progress</th>
                                <th class="text-end">Collected</th>
                                <th>Status</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if (!$memberRows): ?>
                                <tr><td colspan="11"><div class="empty-state">No chit members found.</div></td></tr>
                            <?php else: ?>
                                <?php foreach ($memberRows as $row):
                                    $months = max(1, (int)$row['total_months']);
                                    $paidInstallments = (int)$row['paid_installments'];
                                    $progress = min(100, ($paidInstallments / $months) * 100);
                                ?>
                                    <tr>
                                        <td><strong><?= e($row['group_no']) ?></strong><div class="text-muted"><?= e($row['group_name']) ?></div></td>
                                        <td><?= e($row['chit_type']) ?></td>
                                        <td><?= e($row['customer_name']) ?><div class="text-muted"><?= e($row['mobile']) ?></div></td>
                                        <td><?= e($row['ticket_no']) ?></td>
                                        <td><?= e(date('d-m-Y', strtotime($row['join_date']))) ?></td>
                                        <td><?= e(date('d-m-Y', strtotime($row['start_date']))) ?><div class="text-muted">to <?= $row['end_date'] ? e(date('d-m-Y', strtotime($row['end_date']))) : '—' ?></div></td>
                                        <td class="text-money"><?= e(money($row['installment_amount'])) ?></td>
                                        <td class="text-money"><?= e(money($row['chit_value'])) ?></td>
                                        <td>
                                            <div class="member-progress">
                                                <div class="d-flex justify-content-between mb-1">
                                                    <span><?= $paidInstallments ?>/<?= $months ?></span>
                                                    <span><?= number_format($progress, 0) ?>%</span>
                                                </div>
                                                <div class="progress"><div class="progress-bar" style="width:<?= $progress ?>%"></div></div>
                                            </div>
                                        </td>
                                        <td class="text-money"><?= e(money($row['collected_amount'])) ?></td>
                                        <td>
                                            <span class="badge-soft <?= strtolower($row['member_status']) === 'active' ? 'badge-active' : 'badge-closed' ?>">
                                                <?= e($row['member_status']) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="tab-pane fade" id="installments">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                            <tr>
                                <th>Group</th>
                                <th>Installment</th>
                                <th>Due Date</th>
                                <th>Members</th>
                                <th>Paid Members</th>
                                <th>Pending Members</th>
                                <th class="text-end">Expected</th>
                                <th class="text-end">Collected</th>
                                <th class="text-end">Balance</th>
                                <th>Status</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if (!$dueRows): ?>
                                <tr><td colspan="10"><div class="empty-state">No installments found in this date range.</div></td></tr>
                            <?php else: ?>
                                <?php foreach ($dueRows as $row):
                                    $pendingMembers = max(0, (int)$row['member_count'] - (int)$row['paid_count']);
                                    $balance = max(0, (float)$row['expected_amount'] - (float)$row['collected_amount']);
                                    $statusClass = strtolower((string)$row['installment_status']) === 'closed' ? 'badge-closed' : 'badge-open';
                                ?>
                                    <tr>
                                        <td><strong><?= e($row['group_no']) ?></strong><div class="text-muted"><?= e($row['group_name']) ?></div></td>
                                        <td>#<?= (int)$row['installment_no'] ?></td>
                                        <td><?= e(date('d-m-Y', strtotime($row['due_date']))) ?></td>
                                        <td><?= (int)$row['member_count'] ?></td>
                                        <td><?= (int)$row['paid_count'] ?></td>
                                        <td><?= $pendingMembers ?></td>
                                        <td class="text-money"><?= e(money($row['expected_amount'])) ?></td>
                                        <td class="text-money"><?= e(money($row['collected_amount'])) ?></td>
                                        <td class="text-money"><?= e(money($balance)) ?></td>
                                        <td><span class="badge-soft <?= $statusClass ?>"><?= e($row['installment_status']) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>

        <?php include('includes/footer.php'); ?>
    </div>
</main>

<?php include('includes/script.php'); ?>
<script src="assets/js/script.js"></script>
</body>
</html>