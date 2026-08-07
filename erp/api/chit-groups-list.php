<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));
header('Content-Type: application/json; charset=utf-8');

function respond(bool $success, string $message, array $extra = [], int $status = 200): void
{
    http_response_code($status);
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
        'perm.chit.groups',
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

if (empty($_SESSION['user_id'])) {
    respond(false, 'Your session has expired. Please log in again.', [], 401);
}

$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? $_SESSION['default_branch_id'] ?? 0);

if ($businessId <= 0 || $branchId <= 0) {
    respond(false, 'A valid business and branch must be selected.', [], 403);
}

if (!hasPermission($conn, 'view') && !hasPermission($conn, 'open')) {
    respond(false, 'You do not have permission to view chit groups.', [], 403);
}

foreach (['chit_groups', 'chit_members'] as $requiredTable) {
    if (!tableExists($conn, $requiredTable)) {
        respond(false, "Required table `{$requiredTable}` was not found.", [], 500);
    }
}

$sql = "SELECT
            g.id,
            g.group_no,
            g.group_name,
            g.chit_type,
            g.start_date,
            g.end_date,
            g.total_members,
            g.total_months,
            g.installment_amount,
            g.chit_value,
            g.status,
            COUNT(DISTINCT m.id) AS member_count
        FROM chit_groups g
        LEFT JOIN chit_members m
            ON m.chit_group_id = g.id
           AND m.business_id = g.business_id
           AND m.status <> 'Cancelled'
        WHERE g.business_id = ?
          AND g.branch_id = ?
        GROUP BY
            g.id,
            g.group_no,
            g.group_name,
            g.chit_type,
            g.start_date,
            g.end_date,
            g.total_members,
            g.total_months,
            g.installment_amount,
            g.chit_value,
            g.status
        ORDER BY g.id DESC";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    respond(false, 'Unable to prepare chit-group query: ' . $conn->error, [], 500);
}

$stmt->bind_param('ii', $businessId, $branchId);

if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    respond(false, 'Unable to load chit groups: ' . $error, [], 500);
}

$result = $stmt->get_result();
$rows = [];

while ($result && $row = $result->fetch_assoc()) {
    $row['id'] = (int)$row['id'];
    $row['total_members'] = (int)$row['total_members'];
    $row['total_months'] = (int)$row['total_months'];
    $row['member_count'] = (int)$row['member_count'];
    $row['installment_amount'] = (float)$row['installment_amount'];
    $row['chit_value'] = (float)$row['chit_value'];
    $rows[] = $row;
}

$stmt->close();

$canViewValue = hasPermission($conn, 'value') || hasPermission($conn, 'view');

if (!$canViewValue) {
    foreach ($rows as &$row) {
        $row['installment_amount'] = null;
        $row['chit_value'] = null;
    }
    unset($row);
}

respond(true, 'Chit groups loaded successfully.', [
    'rows' => $rows,
]);
