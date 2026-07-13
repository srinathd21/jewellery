<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));
header('Content-Type: application/json; charset=utf-8');

function respond(bool $success, string $message, array $extra = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
    ], $extra));
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

if (!function_exists('tableExists')) {
    function tableExists(mysqli $conn, string $table): bool
    {
        $table = $conn->real_escape_string($table);
        $result = $conn->query("SHOW TABLES LIKE '{$table}'");
        return $result && $result->num_rows > 0;
    }
}

if (!function_exists('hasChitMemberPermission')) {
    function hasChitMemberPermission(mysqli $conn, string $action): bool
    {
        $fieldMap = [
            'open' => 'can_open',
            'view' => 'can_view',
        ];

        $field = $fieldMap[$action] ?? '';

        if ($field === '') {
            return false;
        }

        $userType = strtolower(trim((string)($_SESSION['user_type'] ?? '')));
        $roleName = strtolower(trim((string)($_SESSION['role_name'] ?? '')));
        $roleCode = strtolower(trim((string)($_SESSION['role_code'] ?? '')));

        $adminRoles = [
            'platform admin',
            'super admin',
            'admin',
            'manager',
            'billing',
            'super_admin',
        ];

        if (
            in_array($userType, $adminRoles, true) ||
            in_array($roleName, $adminRoles, true) ||
            in_array($roleCode, $adminRoles, true)
        ) {
            return true;
        }

        $permissionCodes = [
            'perm.chit-members',
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

        if ($businessId <= 0 || $roleId <= 0) {
            return false;
        }

        if (!tableExists($conn, 'permissions') || !tableExists($conn, 'role_permissions')) {
            return false;
        }

        $placeholders = implode(',', array_fill(0, count($permissionCodes), '?'));

        $sql = "SELECT MAX(COALESCE(rp.`{$field}`, 0)) AS allowed
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
}

if (empty($_SESSION['user_id'])) {
    respond(false, 'Your session has expired. Please log in again.', [], 401);
}

$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? $_SESSION['default_branch_id'] ?? 0);

if ($businessId <= 0) {
    respond(false, 'A valid business must be selected.', [], 403);
}

if ($branchId <= 0) {
    respond(false, 'A valid branch must be selected.', [], 403);
}

if (!hasChitMemberPermission($conn, 'view') && !hasChitMemberPermission($conn, 'open')) {
    respond(false, 'You do not have permission to view chit members.', [], 403);
}

foreach (['chit_members', 'chit_groups', 'customers'] as $requiredTable) {
    if (!tableExists($conn, $requiredTable)) {
        respond(false, "Required table `{$requiredTable}` was not found.", [], 500);
    }
}

$stmt = $conn->prepare(
    "SELECT
        m.id,
        m.ticket_no,
        m.join_date,
        m.nominee_name,
        m.nominee_relation,
        m.status,
        g.id AS group_id,
        g.group_no,
        g.group_name,
        c.id AS customer_id,
        c.customer_code,
        c.customer_name,
        c.mobile
     FROM chit_members m
     INNER JOIN chit_groups g
        ON g.id = m.chit_group_id
       AND g.business_id = m.business_id
     INNER JOIN customers c
        ON c.id = m.customer_id
       AND c.business_id = m.business_id
     WHERE m.business_id = ?
       AND g.branch_id = ?
     ORDER BY m.id DESC"
);

if (!$stmt) {
    respond(false, 'Unable to prepare chit member query: ' . $conn->error, [], 500);
}

$stmt->bind_param('ii', $businessId, $branchId);
$stmt->execute();
$result = $stmt->get_result();

$rows = [];
$totalMembers = 0;
$activeMembers = 0;
$joinedThisMonth = 0;
$groupIds = [];

while ($result && $row = $result->fetch_assoc()) {
    $row['id'] = (int)$row['id'];
    $row['group_id'] = (int)$row['group_id'];
    $row['customer_id'] = (int)$row['customer_id'];

    $row['join_date_display'] = !empty($row['join_date'])
        ? date('d M Y', strtotime($row['join_date']))
        : '—';

    $rows[] = $row;
    $totalMembers++;

    if (($row['status'] ?? '') === 'Active') {
        $activeMembers++;
    }

    if (
        !empty($row['join_date']) &&
        date('Y-m', strtotime($row['join_date'])) === date('Y-m')
    ) {
        $joinedThisMonth++;
    }

    $groupIds[$row['group_id']] = true;
}

$stmt->close();

respond(true, 'Chit members loaded successfully.', [
    'rows' => $rows,
    'summary' => [
        'total_members' => $totalMembers,
        'active_members' => $activeMembers,
        'groups_covered' => count($groupIds),
        'joined_this_month' => $joinedThisMonth,
    ],
]);
