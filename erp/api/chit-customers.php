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
        'perm.chitchit-customers',
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

if (empty($_SESSION['user_id'])) {
    respond(false, 'Your session has expired. Please log in again.', [], 401);
}

$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? $_SESSION['default_branch_id'] ?? 0);
$action = trim((string)($_GET['action'] ?? 'list'));

if ($businessId <= 0 || $branchId <= 0) {
    respond(false, 'A valid business and branch must be selected.', [], 403);
}

if (!hasPermission($conn, 'view') && !hasPermission($conn, 'open')) {
    respond(false, 'You do not have permission to view chit customers.', [], 403);
}

if ($action !== 'list') {
    respond(false, 'Invalid action.', [], 400);
}

foreach (['customers', 'chit_members', 'chit_groups', 'chit_installments', 'chit_collections'] as $requiredTable) {
    if (!tableExists($conn, $requiredTable)) {
        respond(false, "Required table `{$requiredTable}` was not found.", [], 500);
    }
}

$sql = "SELECT
            c.id,
            c.customer_code,
            c.customer_name,
            c.mobile,
            c.address_line1,
            MAX(CASE WHEN m.status = 'Active' THEN m.nominee_name ELSE NULL END) AS nominee_name,
            COUNT(DISTINCT CASE WHEN m.status = 'Active' THEN m.chit_group_id END) AS active_chits,
            COALESCE(SUM(collections.total_paid),0) AS total_paid_amount,
            COALESCE(SUM(dues.total_due),0) AS total_due_amount
        FROM customers c
        INNER JOIN chit_members m
            ON m.customer_id = c.id
           AND m.business_id = c.business_id
        INNER JOIN chit_groups g
            ON g.id = m.chit_group_id
           AND g.business_id = m.business_id
           AND g.branch_id = ?
        LEFT JOIN (
            SELECT
                cc.chit_member_id,
                SUM(cc.paid_amount) AS total_paid
            FROM chit_collections cc
            WHERE cc.business_id = ?
              AND cc.branch_id = ?
            GROUP BY cc.chit_member_id
        ) collections
            ON collections.chit_member_id = m.id
        LEFT JOIN (
            SELECT
                m2.id AS chit_member_id,
                SUM(
                    GREATEST(
                        g2.installment_amount
                        - COALESCE(col2.settled_amount,0),
                        0
                    )
                ) AS total_due
            FROM chit_members m2
            INNER JOIN chit_groups g2
                ON g2.id = m2.chit_group_id
               AND g2.business_id = m2.business_id
               AND g2.branch_id = ?
            INNER JOIN chit_installments ci2
                ON ci2.chit_group_id = g2.id
               AND ci2.business_id = g2.business_id
               AND ci2.status = 'Open'
            LEFT JOIN (
                SELECT
                    chit_member_id,
                    chit_installment_id,
                    SUM(paid_amount + discount_amount) AS settled_amount
                FROM chit_collections
                WHERE business_id = ?
                  AND branch_id = ?
                GROUP BY chit_member_id, chit_installment_id
            ) col2
                ON col2.chit_member_id = m2.id
               AND col2.chit_installment_id = ci2.id
            WHERE m2.business_id = ?
              AND m2.status = 'Active'
            GROUP BY m2.id
        ) dues
            ON dues.chit_member_id = m.id
        WHERE c.business_id = ?
          AND c.is_active = 1
          AND c.customer_type IN ('Chit','All')
        GROUP BY
            c.id,
            c.customer_code,
            c.customer_name,
            c.mobile,
            c.address_line1
        ORDER BY c.customer_name";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    respond(false, 'Unable to prepare chit customer query: ' . $conn->error, [], 500);
}

$stmt->bind_param(
    'iiiiiiii',
    $branchId,
    $businessId,
    $branchId,
    $branchId,
    $businessId,
    $branchId,
    $businessId,
    $businessId
);

$stmt->execute();
$result = $stmt->get_result();
$rows = [];

while ($result && $row = $result->fetch_assoc()) {
    $row['id'] = (int)$row['id'];
    $row['active_chits'] = (int)$row['active_chits'];
    $row['total_paid_amount'] = (float)$row['total_paid_amount'];
    $row['total_due_amount'] = (float)$row['total_due_amount'];
    $rows[] = $row;
}

$stmt->close();

$canViewValue = hasPermission($conn, 'value') || hasPermission($conn, 'view');

if (!$canViewValue) {
    foreach ($rows as &$row) {
        $row['total_paid_amount'] = null;
        $row['total_due_amount'] = null;
    }
    unset($row);
}

respond(true, 'Chit customers loaded successfully.', [
    'rows' => $rows,
]);
