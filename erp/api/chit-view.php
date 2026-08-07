<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));
header('Content-Type: application/json; charset=utf-8');

function respond(bool $success, string $message, array $extra = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
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

if (!$configLoaded) {
    respond(false, 'Database config file not found. Checked: ' . implode(', ', $configCandidates), [], 500);
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    respond(false, 'Database connection is not available.', [], 500);
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

    $permissionCodes = ['perm.chit.groups', 'perm.chit'];
    $sessionPermissions = $_SESSION['permissions'] ?? [];

    foreach ($permissionCodes as $permissionCode) {
        if (isset($sessionPermissions[$permissionCode][$field])) {
            return (int)$sessionPermissions[$permissionCode][$field] === 1;
        }
    }

    $businessId = (int)($_SESSION['business_id'] ?? 0);
    $roleId = (int)($_SESSION['role_id'] ?? 0);

    if ($businessId <= 0 || $roleId <= 0) {
        return false;
    }

    if (!tableExists($conn, 'permissions') || !tableExists($conn, 'role_permissions')) {
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

if (empty($_SESSION['user_id'])) {
    respond(false, 'Your session has expired. Please log in again.', [], 401);
}

$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? $_SESSION['default_branch_id'] ?? 0);
$groupId = (int)($_GET['id'] ?? 0);

if ($businessId <= 0 || $branchId <= 0) {
    respond(false, 'A valid business and branch must be selected.', [], 403);
}

if ($groupId <= 0) {
    respond(false, 'A valid chit group is required.');
}

if (!hasPermission($conn, 'view') && !hasPermission($conn, 'open')) {
    respond(false, 'You do not have permission to view chit group details.', [], 403);
}

foreach (['chit_groups', 'chit_members', 'chit_installments', 'customers'] as $requiredTable) {
    if (!tableExists($conn, $requiredTable)) {
        respond(false, "Required table `{$requiredTable}` was not found.", [], 500);
    }
}

$stmt = $conn->prepare(
    "SELECT
        id,
        group_no,
        group_name,
        chit_type,
        start_date,
        end_date,
        total_members,
        total_months,
        installment_amount,
        chit_value,
        auction_type,
        auction_day,
        grace_days,
        late_fee_amount,
        status,
        notes
     FROM chit_groups
     WHERE id = ?
       AND business_id = ?
       AND branch_id = ?
     LIMIT 1"
);

if (!$stmt) {
    respond(false, 'Unable to prepare group query: ' . $conn->error, [], 500);
}

$stmt->bind_param('iii', $groupId, $businessId, $branchId);
$stmt->execute();
$group = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$group) {
    respond(false, 'Chit group was not found.', [], 404);
}

$group['id'] = (int)$group['id'];
$group['total_members'] = (int)$group['total_members'];
$group['total_months'] = (int)$group['total_months'];
$group['installment_amount'] = (float)$group['installment_amount'];
$group['chit_value'] = (float)$group['chit_value'];
$group['auction_day'] = $group['auction_day'] !== null ? (int)$group['auction_day'] : null;
$group['grace_days'] = (int)$group['grace_days'];
$group['late_fee_amount'] = (float)$group['late_fee_amount'];
$group['start_date_display'] = !empty($group['start_date'])
    ? date('d M Y', strtotime($group['start_date']))
    : '—';
$group['end_date_display'] = !empty($group['end_date'])
    ? date('d M Y', strtotime($group['end_date']))
    : '—';

$stmt = $conn->prepare(
    "SELECT
        m.id,
        m.ticket_no,
        m.join_date,
        m.nominee_name,
        m.nominee_relation,
        m.status,
        c.customer_code,
        c.customer_name,
        c.mobile
     FROM chit_members m
     INNER JOIN customers c
        ON c.id = m.customer_id
       AND c.business_id = m.business_id
     WHERE m.chit_group_id = ?
       AND m.business_id = ?
     ORDER BY m.id DESC"
);

$stmt->bind_param('ii', $groupId, $businessId);
$stmt->execute();
$result = $stmt->get_result();
$members = [];

while ($result && $row = $result->fetch_assoc()) {
    $row['id'] = (int)$row['id'];
    $row['join_date_display'] = !empty($row['join_date'])
        ? date('d M Y', strtotime($row['join_date']))
        : '—';
    $members[] = $row;
}

$stmt->close();

$stmt = $conn->prepare(
    "SELECT
        id,
        installment_no,
        due_date,
        auction_date,
        status
     FROM chit_installments
     WHERE chit_group_id = ?
       AND business_id = ?
     ORDER BY installment_no"
);

$stmt->bind_param('ii', $groupId, $businessId);
$stmt->execute();
$result = $stmt->get_result();
$installments = [];

while ($result && $row = $result->fetch_assoc()) {
    $row['id'] = (int)$row['id'];
    $row['installment_no'] = (int)$row['installment_no'];
    $row['due_date_display'] = !empty($row['due_date'])
        ? date('d M Y', strtotime($row['due_date']))
        : '—';
    $row['auction_date_display'] = !empty($row['auction_date'])
        ? date('d M Y', strtotime($row['auction_date']))
        : null;
    $installments[] = $row;
}

$stmt->close();

$canViewValue = hasPermission($conn, 'value') || hasPermission($conn, 'view');

if (!$canViewValue) {
    $group['installment_amount'] = null;
    $group['chit_value'] = null;
    $group['late_fee_amount'] = null;
}

respond(true, 'Chit group details loaded successfully.', [
    'group' => $group,
    'members' => $members,
    'installments' => $installments,
    'summary' => [
        'member_count' => count($members),
        'installment_count' => count($installments),
    ],
]);
