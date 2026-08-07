<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));

function jsonResponse(bool $success, string $message, array $extra = [], int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(
        array_merge(
            ['success' => $success, 'message' => $message],
            $extra
        ),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

$rootDir = dirname(__DIR__);

$configCandidates = [
    $rootDir . '/config/config.php',
    $rootDir . '/config.php',
    $rootDir . '/includes/config.php',
    $rootDir . '/super-admin/includes/config.php',
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
    jsonResponse(false, 'Database configuration is not available.', [], 500);
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn->set_charset('utf8mb4');

function tableExists(mysqli $conn, string $table): bool
{
    $table = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$table}'");
    return $result && $result->num_rows > 0;
}

function hasPermission(mysqli $conn, string $action): bool
{
    $fieldMap = [
        'open' => 'can_open',
        'view' => 'can_view',
        'value' => 'can_view_value',
    ];

    $field = $fieldMap[$action] ?? '';

    if ($field === '') {
        return false;
    }

    $adminRoles = [
        'platform admin',
        'super admin',
        'admin',
        'manager',
        'billing',
        'super_admin',
    ];

    foreach ([
        strtolower(trim((string)($_SESSION['user_type'] ?? ''))),
        strtolower(trim((string)($_SESSION['role_name'] ?? ''))),
        strtolower(trim((string)($_SESSION['role_code'] ?? ''))),
    ] as $roleValue) {
        if (in_array($roleValue, $adminRoles, true)) {
            return true;
        }
    }

    $permissionCodes = [
        'perm.chit-due-list',
        'perm.chit',
    ];

    $sessionPermissions = $_SESSION['permissions'] ?? [];

    foreach ($permissionCodes as $permissionCode) {
        if (isset($sessionPermissions[$permissionCode][$field])) {
            return (int)$sessionPermissions[$permissionCode][$field] === 1;
        }
    }

    $businessId = (int)($_SESSION['business_id'] ?? 0);
    $roleId = (int)($_SESSION['role_id'] ?? 0);

    if (
        $businessId <= 0 ||
        $roleId <= 0 ||
        !tableExists($conn, 'permissions') ||
        !tableExists($conn, 'role_permissions')
    ) {
        return false;
    }

    $placeholders = implode(',', array_fill(0, count($permissionCodes), '?'));

    $sql = "SELECT MAX(COALESCE(rp.`{$field}`,0)) AS allowed
            FROM role_permissions rp
            INNER JOIN permissions p
                ON p.id = rp.permission_id
            WHERE rp.business_id = ?
              AND rp.role_id = ?
              AND p.is_active = 1
              AND p.permission_code IN ({$placeholders})";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return false;
    }

    $types = 'ii' . str_repeat('s', count($permissionCodes));
    $params = array_merge([$businessId, $roleId], $permissionCodes);

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row['allowed'] ?? 0) === 1;
}

function validDate(string $date): bool
{
    if ($date === '') {
        return false;
    }

    $object = DateTime::createFromFormat('Y-m-d', $date);
    return $object && $object->format('Y-m-d') === $date;
}

if (empty($_SESSION['user_id'])) {
    jsonResponse(false, 'Your session has expired. Please log in again.', [], 401);
}

$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? $_SESSION['default_branch_id'] ?? 0);

if ($businessId <= 0 || $branchId <= 0) {
    jsonResponse(false, 'A valid business and branch must be selected.', [], 403);
}

if (!hasPermission($conn, 'view') && !hasPermission($conn, 'open')) {
    jsonResponse(false, 'You do not have permission to view the chit due list.', [], 403);
}

foreach (
    ['chit_groups', 'chit_members', 'chit_installments', 'chit_collections', 'customers']
    as $requiredTable
) {
    if (!tableExists($conn, $requiredTable)) {
        jsonResponse(false, "Required table `{$requiredTable}` was not found.", [], 500);
    }
}

$action = strtolower(trim((string)($_GET['action'] ?? 'list')));
$filterType = strtolower(trim((string)($_GET['filter_type'] ?? 'all')));
$startDate = trim((string)($_GET['start_date'] ?? ''));
$endDate = trim((string)($_GET['end_date'] ?? ''));
$groupId = (int)($_GET['group_filter'] ?? 0);
$searchMember = trim((string)($_GET['search_member'] ?? ''));

$allowedFilters = ['all', 'overdue', 'today', 'week', 'month', 'range'];

if (!in_array($filterType, $allowedFilters, true)) {
    $filterType = 'all';
}

$where = [
    'g.business_id = ?',
    'g.branch_id = ?',
    "g.status = 'Active'",
    "m.status = 'Active'",
    "i.status = 'Open'",
];

$types = 'ii';
$params = [$businessId, $branchId];

$today = date('Y-m-d');

switch ($filterType) {
    case 'overdue':
        $where[] = 'i.due_date < ?';
        $types .= 's';
        $params[] = $today;
        break;

    case 'today':
        $where[] = 'i.due_date = ?';
        $types .= 's';
        $params[] = $today;
        break;

    case 'week':
        $weekStart = date('Y-m-d', strtotime('monday this week'));
        $weekEnd = date('Y-m-d', strtotime('sunday this week'));
        $where[] = 'i.due_date BETWEEN ? AND ?';
        $types .= 'ss';
        $params[] = $weekStart;
        $params[] = $weekEnd;
        break;

    case 'month':
        $monthStart = date('Y-m-01');
        $monthEnd = date('Y-m-t');
        $where[] = 'i.due_date BETWEEN ? AND ?';
        $types .= 'ss';
        $params[] = $monthStart;
        $params[] = $monthEnd;
        break;

    case 'range':
        if (!validDate($startDate) || !validDate($endDate)) {
            jsonResponse(false, 'Please select a valid from date and to date.', [], 422);
        }

        if ($startDate > $endDate) {
            jsonResponse(false, 'From date cannot be after to date.', [], 422);
        }

        $where[] = 'i.due_date BETWEEN ? AND ?';
        $types .= 'ss';
        $params[] = $startDate;
        $params[] = $endDate;
        break;
}

if ($groupId > 0) {
    $where[] = 'g.id = ?';
    $types .= 'i';
    $params[] = $groupId;
}

if ($searchMember !== '') {
    $search = '%' . $searchMember . '%';
    $where[] = '(m.ticket_no LIKE ? OR c.customer_name LIKE ? OR c.mobile LIKE ?)';
    $types .= 'sss';
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

$sql = "SELECT
            i.id AS installment_id,
            i.installment_no,
            i.due_date,
            m.id AS member_id,
            m.ticket_no,
            g.id AS group_id,
            g.group_no,
            g.group_name,
            g.installment_amount,
            c.customer_name,
            c.mobile,
            COALESCE(collections.settled_amount,0) AS settled_amount,
            GREATEST(
                g.installment_amount - COALESCE(collections.settled_amount,0),
                0
            ) AS pending_amount,
            CASE
                WHEN i.due_date < CURDATE() THEN 'Overdue'
                WHEN i.due_date = CURDATE() THEN 'Today'
                ELSE 'Upcoming'
            END AS due_status
        FROM chit_installments i
        INNER JOIN chit_groups g
            ON g.id = i.chit_group_id
           AND g.business_id = i.business_id
        INNER JOIN chit_members m
            ON m.chit_group_id = g.id
           AND m.business_id = g.business_id
        INNER JOIN customers c
            ON c.id = m.customer_id
           AND c.business_id = m.business_id
        LEFT JOIN (
            SELECT
                chit_member_id,
                chit_installment_id,
                SUM(
                    COALESCE(paid_amount,0)
                    + COALESCE(discount_amount,0)
                ) AS settled_amount
            FROM chit_collections
            WHERE business_id = ?
              AND branch_id = ?
            GROUP BY chit_member_id, chit_installment_id
        ) collections
            ON collections.chit_member_id = m.id
           AND collections.chit_installment_id = i.id
        WHERE " . implode(' AND ', $where) . "
        HAVING pending_amount > 0
        ORDER BY i.due_date ASC, g.group_no ASC, m.ticket_no ASC";

$finalTypes = 'ii' . $types;
$finalParams = array_merge([$businessId, $branchId], $params);

$stmt = $conn->prepare($sql);

if (!$stmt) {
    jsonResponse(false, 'Unable to prepare due-list query: ' . $conn->error, [], 500);
}

$stmt->bind_param($finalTypes, ...$finalParams);

if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    jsonResponse(false, 'Unable to load due records: ' . $error, [], 500);
}

$result = $stmt->get_result();
$rows = [];

while ($result && $row = $result->fetch_assoc()) {
    $row['installment_id'] = (int)$row['installment_id'];
    $row['installment_no'] = (int)$row['installment_no'];
    $row['member_id'] = (int)$row['member_id'];
    $row['group_id'] = (int)$row['group_id'];
    $row['installment_amount'] = (float)$row['installment_amount'];
    $row['settled_amount'] = (float)$row['settled_amount'];
    $row['pending_amount'] = (float)$row['pending_amount'];
    $row['due_date_display'] = date('d-m-Y', strtotime((string)$row['due_date']));
    $rows[] = $row;
}

$stmt->close();

$groups = [];

$groupStmt = $conn->prepare(
    "SELECT id, group_no, group_name
     FROM chit_groups
     WHERE business_id = ?
       AND branch_id = ?
       AND status = 'Active'
     ORDER BY group_name"
);

if ($groupStmt) {
    $groupStmt->bind_param('ii', $businessId, $branchId);
    $groupStmt->execute();
    $groupResult = $groupStmt->get_result();

    while ($groupResult && $group = $groupResult->fetch_assoc()) {
        $group['id'] = (int)$group['id'];
        $groups[] = $group;
    }

    $groupStmt->close();
}

$canViewValue = hasPermission($conn, 'value') || hasPermission($conn, 'view');

if (!$canViewValue) {
    foreach ($rows as &$row) {
        $row['installment_amount'] = null;
        $row['settled_amount'] = null;
        $row['pending_amount'] = null;
    }
    unset($row);
}

if ($action === 'excel') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chit-due-list-' . date('Ymd-His') . '.csv"');

    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");

    fputcsv($output, [
        '#',
        'Due Date',
        'Group No',
        'Group Name',
        'Ticket No',
        'Customer',
        'Mobile',
        'Installment No',
        'Due Amount',
        'Paid Amount',
        'Pending Amount',
        'Status',
    ]);

    foreach ($rows as $index => $row) {
        fputcsv($output, [
            $index + 1,
            $row['due_date_display'],
            $row['group_no'],
            $row['group_name'],
            $row['ticket_no'],
            $row['customer_name'],
            $row['mobile'],
            $row['installment_no'],
            $canViewValue ? $row['installment_amount'] : '',
            $canViewValue ? $row['settled_amount'] : '',
            $canViewValue ? $row['pending_amount'] : '',
            $row['due_status'],
        ]);
    }

    fclose($output);
    exit;
}

if ($action === 'pdf') {
    header('Content-Type: text/html; charset=utf-8');

    echo '<!doctype html><html><head><meta charset="utf-8"><title>Chit Due List</title>';
    echo '<style>body{font-family:Arial,sans-serif;font-size:12px;padding:20px}';
    echo 'h2{margin:0 0 15px}table{width:100%;border-collapse:collapse}';
    echo 'th,td{border:1px solid #bbb;padding:7px;text-align:left}th{background:#eee}';
    echo '@media print{button{display:none}}</style></head><body>';
    echo '<button onclick="window.print()">Print / Save as PDF</button>';
    echo '<h2>Chit Due List</h2>';
    echo '<table><thead><tr>';
    echo '<th>#</th><th>Due Date</th><th>Group</th><th>Member</th>';
    echo '<th>Installment</th><th>Due</th><th>Paid</th><th>Pending</th><th>Status</th>';
    echo '</tr></thead><tbody>';

    foreach ($rows as $index => $row) {
        echo '<tr>';
        echo '<td>' . ($index + 1) . '</td>';
        echo '<td>' . htmlspecialchars($row['due_date_display']) . '</td>';
        echo '<td>' . htmlspecialchars($row['group_no'] . ' - ' . $row['group_name']) . '</td>';
        echo '<td>' . htmlspecialchars($row['ticket_no'] . ' - ' . $row['customer_name']) . '</td>';
        echo '<td>#' . (int)$row['installment_no'] . '</td>';
        echo '<td>' . ($canViewValue ? number_format((float)$row['installment_amount'], 2) : '****') . '</td>';
        echo '<td>' . ($canViewValue ? number_format((float)$row['settled_amount'], 2) : '****') . '</td>';
        echo '<td>' . ($canViewValue ? number_format((float)$row['pending_amount'], 2) : '****') . '</td>';
        echo '<td>' . htmlspecialchars($row['due_status']) . '</td>';
        echo '</tr>';
    }

    if (!$rows) {
        echo '<tr><td colspan="9" style="text-align:center">No due records found.</td></tr>';
    }

    echo '</tbody></table></body></html>';
    exit;
}

if ($action !== 'list') {
    jsonResponse(false, 'Invalid action.', [], 400);
}

jsonResponse(true, 'Due records loaded successfully.', [
    'rows' => $rows,
    'groups' => $groups,
]);
