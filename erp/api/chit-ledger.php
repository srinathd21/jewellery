<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));

function respond(bool $success, string $message, array $extra = [], int $status = 200): void
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
    respond(false, 'Database configuration is not available.', [], 500);
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
        'export' => 'can_export',
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
        'perm.chit-ledger',
        'perm.chit-collection',
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
            INNER JOIN permissions p ON p.id = rp.permission_id
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
    $object = DateTime::createFromFormat('Y-m-d', $date);
    return $object && $object->format('Y-m-d') === $date;
}

if (empty($_SESSION['user_id'])) {
    respond(false, 'Your session has expired. Please log in again.', [], 401);
}

$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? $_SESSION['default_branch_id'] ?? 0);

if ($businessId <= 0 || $branchId <= 0) {
    respond(false, 'A valid business and branch must be selected.', [], 403);
}

if (!hasPermission($conn, 'view') && !hasPermission($conn, 'open')) {
    respond(false, 'You do not have permission to view the chit ledger.', [], 403);
}

foreach (
    ['chit_collections', 'chit_members', 'chit_groups', 'chit_installments', 'customers', 'payment_methods']
    as $requiredTable
) {
    if (!tableExists($conn, $requiredTable)) {
        respond(false, "Required table `{$requiredTable}` was not found.", [], 500);
    }
}

$action = strtolower(trim((string)($_GET['action'] ?? 'bootstrap')));

if ($action === 'bootstrap') {
    $members = [];

    $stmt = $conn->prepare(
        "SELECT
            m.id,
            m.ticket_no,
            c.customer_name,
            g.group_no,
            g.group_name
         FROM chit_members m
         INNER JOIN customers c
            ON c.id = m.customer_id
           AND c.business_id = m.business_id
         INNER JOIN chit_groups g
            ON g.id = m.chit_group_id
           AND g.business_id = m.business_id
         WHERE m.business_id = ?
           AND g.branch_id = ?
         ORDER BY c.customer_name, g.group_name, m.ticket_no"
    );

    if (!$stmt) {
        respond(false, 'Unable to prepare member query: ' . $conn->error, [], 500);
    }

    $stmt->bind_param('ii', $businessId, $branchId);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($result && $row = $result->fetch_assoc()) {
        $row['id'] = (int)$row['id'];
        $members[] = $row;
    }

    $stmt->close();

    $groups = [];

    $stmt = $conn->prepare(
        "SELECT id, group_no, group_name
         FROM chit_groups
         WHERE business_id = ?
           AND branch_id = ?
         ORDER BY group_name"
    );

    if (!$stmt) {
        respond(false, 'Unable to prepare group query: ' . $conn->error, [], 500);
    }

    $stmt->bind_param('ii', $businessId, $branchId);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($result && $row = $result->fetch_assoc()) {
        $row['id'] = (int)$row['id'];
        $groups[] = $row;
    }

    $stmt->close();

    respond(true, 'Ledger filters loaded successfully.', [
        'members' => $members,
        'groups' => $groups,
    ]);
}

$memberId = (int)($_GET['member_id'] ?? 0);
$filterType = strtolower(trim((string)($_GET['filter_type'] ?? 'all')));
$startDate = trim((string)($_GET['start_date'] ?? ''));
$endDate = trim((string)($_GET['end_date'] ?? ''));
$groupId = (int)($_GET['group_filter'] ?? 0);

if ($memberId <= 0) {
    respond(false, 'Please select a member.', [], 422);
}

if (!in_array($filterType, ['all', 'today', 'week', 'month', 'range'], true)) {
    $filterType = 'all';
}

$where = [
    'cc.business_id = ?',
    'cc.branch_id = ?',
    'cc.chit_member_id = ?',
];

$types = 'iii';
$params = [$businessId, $branchId, $memberId];

if ($filterType === 'today') {
    $where[] = 'cc.collection_date = CURDATE()';
} elseif ($filterType === 'week') {
    $where[] = 'YEARWEEK(cc.collection_date,1) = YEARWEEK(CURDATE(),1)';
} elseif ($filterType === 'month') {
    $where[] = 'YEAR(cc.collection_date) = YEAR(CURDATE())';
    $where[] = 'MONTH(cc.collection_date) = MONTH(CURDATE())';
} elseif ($filterType === 'range') {
    if (!validDate($startDate) || !validDate($endDate)) {
        respond(false, 'Please select valid from and to dates.', [], 422);
    }

    if ($startDate > $endDate) {
        respond(false, 'From date cannot be after to date.', [], 422);
    }

    $where[] = 'cc.collection_date BETWEEN ? AND ?';
    $types .= 'ss';
    $params[] = $startDate;
    $params[] = $endDate;
}

if ($groupId > 0) {
    $where[] = 'cc.chit_group_id = ?';
    $types .= 'i';
    $params[] = $groupId;
}

$sql = "SELECT
            cc.id,
            cc.receipt_no,
            cc.collection_date,
            cc.due_amount,
            cc.paid_amount,
            cc.discount_amount,
            cc.penalty_amount,
            cc.net_amount,
            g.group_no,
            g.group_name,
            i.installment_no,
            pm.method_name,
            m.ticket_no,
            c.customer_name
        FROM chit_collections cc
        INNER JOIN chit_members m
            ON m.id = cc.chit_member_id
           AND m.business_id = cc.business_id
        INNER JOIN customers c
            ON c.id = m.customer_id
           AND c.business_id = m.business_id
        INNER JOIN chit_groups g
            ON g.id = cc.chit_group_id
           AND g.business_id = cc.business_id
           AND g.branch_id = cc.branch_id
        INNER JOIN chit_installments i
            ON i.id = cc.chit_installment_id
           AND i.business_id = cc.business_id
        LEFT JOIN payment_methods pm
            ON pm.id = cc.payment_method_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY cc.collection_date DESC, cc.id DESC";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    respond(false, 'Unable to prepare ledger query: ' . $conn->error, [], 500);
}

$stmt->bind_param($types, ...$params);

if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    respond(false, 'Unable to load ledger: ' . $error, [], 500);
}

$result = $stmt->get_result();
$rows = [];
$totalPaid = 0.0;
$member = null;

while ($result && $row = $result->fetch_assoc()) {
    $row['id'] = (int)$row['id'];
    $row['installment_no'] = (int)$row['installment_no'];

    foreach (
        ['due_amount', 'paid_amount', 'discount_amount', 'penalty_amount', 'net_amount']
        as $amountField
    ) {
        $row[$amountField] = (float)$row[$amountField];
    }

    $row['collection_date_display'] = date(
        'd-m-Y',
        strtotime((string)$row['collection_date'])
    );

    $totalPaid += (float)$row['paid_amount'];

    if ($member === null) {
        $member = [
            'ticket_no' => $row['ticket_no'],
            'customer_name' => $row['customer_name'],
        ];
    }

    $rows[] = $row;
}

$stmt->close();

if ($member === null) {
    $stmt = $conn->prepare(
        "SELECT
            m.ticket_no,
            c.customer_name
         FROM chit_members m
         INNER JOIN customers c
            ON c.id = m.customer_id
           AND c.business_id = m.business_id
         INNER JOIN chit_groups g
            ON g.id = m.chit_group_id
           AND g.business_id = m.business_id
         WHERE m.id = ?
           AND m.business_id = ?
           AND g.branch_id = ?
         LIMIT 1"
    );

    if ($stmt) {
        $stmt->bind_param('iii', $memberId, $businessId, $branchId);
        $stmt->execute();
        $member = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
    }
}

$canViewValue = hasPermission($conn, 'value') || hasPermission($conn, 'view');

if (!$canViewValue) {
    foreach ($rows as &$row) {
        foreach (
            ['due_amount', 'paid_amount', 'discount_amount', 'penalty_amount', 'net_amount']
            as $amountField
        ) {
            $row[$amountField] = null;
        }
    }
    unset($row);

    $totalPaid = null;
}

if ($action === 'excel') {
    if (!hasPermission($conn, 'export') && !hasPermission($conn, 'view')) {
        respond(false, 'You do not have permission to export.', [], 403);
    }

    header('Content-Type: text/csv; charset=utf-8');
    header(
        'Content-Disposition: attachment; filename="chit-ledger-' .
        date('Ymd-His') .
        '.csv"'
    );

    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");

    fputcsv($output, [
        '#',
        'Receipt No',
        'Collection Date',
        'Group',
        'Installment',
        'Due Amount',
        'Paid Amount',
        'Discount',
        'Penalty',
        'Net Amount',
        'Payment Method',
    ]);

    foreach ($rows as $index => $row) {
        fputcsv($output, [
            $index + 1,
            $row['receipt_no'],
            $row['collection_date_display'],
            $row['group_no'] . ' - ' . $row['group_name'],
            $row['installment_no'],
            $canViewValue ? $row['due_amount'] : '',
            $canViewValue ? $row['paid_amount'] : '',
            $canViewValue ? $row['discount_amount'] : '',
            $canViewValue ? $row['penalty_amount'] : '',
            $canViewValue ? $row['net_amount'] : '',
            $row['method_name'],
        ]);
    }

    fclose($output);
    exit;
}

if ($action === 'pdf') {
    if (!hasPermission($conn, 'export') && !hasPermission($conn, 'view')) {
        respond(false, 'You do not have permission to export.', [], 403);
    }

    header('Content-Type: text/html; charset=utf-8');

    echo '<!doctype html><html><head><meta charset="utf-8">';
    echo '<title>Chit Collection Ledger</title>';
    echo '<style>';
    echo 'body{font-family:Arial,sans-serif;font-size:11px;padding:20px}';
    echo 'table{width:100%;border-collapse:collapse}';
    echo 'th,td{border:1px solid #bbb;padding:6px}';
    echo 'th{background:#eee}';
    echo '@media print{button{display:none}}';
    echo '</style></head><body>';

    echo '<button onclick="window.print()">Print / Save as PDF</button>';
    echo '<h2>Chit Collection Ledger</h2>';

    if ($member) {
        echo '<p><strong>Member:</strong> ' .
            htmlspecialchars(
                $member['ticket_no'] . ' - ' . $member['customer_name']
            ) .
            '</p>';
    }

    echo '<table><thead><tr>';
    echo '<th>#</th><th>Receipt</th><th>Date</th><th>Group</th>';
    echo '<th>Inst.</th><th>Due</th><th>Paid</th><th>Discount</th>';
    echo '<th>Penalty</th><th>Net</th><th>Method</th>';
    echo '</tr></thead><tbody>';

    foreach ($rows as $index => $row) {
        echo '<tr>';
        echo '<td>' . ($index + 1) . '</td>';
        echo '<td>' . htmlspecialchars((string)$row['receipt_no']) . '</td>';
        echo '<td>' . htmlspecialchars((string)$row['collection_date_display']) . '</td>';
        echo '<td>' . htmlspecialchars((string)$row['group_name']) . '</td>';
        echo '<td>#' . (int)$row['installment_no'] . '</td>';

        foreach (
            ['due_amount', 'paid_amount', 'discount_amount', 'penalty_amount', 'net_amount']
            as $amountField
        ) {
            echo '<td>' .
                ($canViewValue
                    ? number_format((float)$row[$amountField], 2)
                    : '****') .
                '</td>';
        }

        echo '<td>' . htmlspecialchars((string)$row['method_name']) . '</td>';
        echo '</tr>';
    }

    if (!$rows) {
        echo '<tr><td colspan="11" style="text-align:center">';
        echo 'No collections found.';
        echo '</td></tr>';
    }

    echo '</tbody></table></body></html>';
    exit;
}

if ($action !== 'list') {
    respond(false, 'Invalid action.', [], 400);
}

respond(true, 'Ledger loaded successfully.', [
    'rows' => $rows,
    'member' => $member,
    'total_paid' => $totalPaid,
]);
