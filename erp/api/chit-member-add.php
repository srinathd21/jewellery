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
            'create' => 'can_create',
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

if (!function_exists('nextTicketNo')) {
    function nextTicketNo(mysqli $conn, int $businessId): string
    {
        $prefix = 'MEM' . date('Ym');
        $like = $prefix . '%';

        $stmt = $conn->prepare(
            "SELECT ticket_no
             FROM chit_members
             WHERE business_id = ?
               AND ticket_no LIKE ?
             ORDER BY id DESC
             LIMIT 1"
        );

        if (!$stmt) {
            return $prefix . '0001';
        }

        $stmt->bind_param('is', $businessId, $like);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $next = 1;

        if ($row && preg_match('/(\d+)$/', (string)($row['ticket_no'] ?? ''), $match)) {
            $next = (int)$match[1] + 1;
        }

        return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
    }
}

if (empty($_SESSION['user_id'])) {
    respond(false, 'Your session has expired. Please log in again.', [], 401);
}

$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? $_SESSION['default_branch_id'] ?? 0);
$userId = (int)($_SESSION['user_id'] ?? 0);

if ($businessId <= 0) {
    respond(false, 'A valid business must be selected.', [], 403);
}

if ($branchId <= 0) {
    respond(false, 'A valid branch must be selected.', [], 403);
}

foreach (['chit_groups', 'chit_members', 'customers'] as $requiredTable) {
    if (!tableExists($conn, $requiredTable)) {
        respond(false, "Required table `{$requiredTable}` was not found.", [], 500);
    }
}

$action = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? trim((string)($_POST['action'] ?? ''))
    : trim((string)($_GET['action'] ?? 'bootstrap'));

if ($action === 'bootstrap') {
    if (!hasChitMemberPermission($conn, 'view') && !hasChitMemberPermission($conn, 'open')) {
        respond(false, 'You do not have permission to view chit member data.', [], 403);
    }

    $stmt = $conn->prepare(
        "SELECT id, group_no, group_name
         FROM chit_groups
         WHERE business_id = ?
           AND branch_id = ?
           AND status = 'Active'
         ORDER BY group_name"
    );

    $stmt->bind_param('ii', $businessId, $branchId);
    $stmt->execute();
    $result = $stmt->get_result();

    $groups = [];

    while ($result && $row = $result->fetch_assoc()) {
        $row['id'] = (int)$row['id'];
        $groups[] = $row;
    }

    $stmt->close();

    $stmt = $conn->prepare(
        "SELECT id, customer_code, customer_name, mobile
         FROM customers
         WHERE business_id = ?
           AND is_active = 1
           AND customer_type IN ('Chit','All')
           AND (home_branch_id = ? OR home_branch_id IS NULL)
         ORDER BY customer_name"
    );

    $stmt->bind_param('ii', $businessId, $branchId);
    $stmt->execute();
    $result = $stmt->get_result();

    $customers = [];

    while ($result && $row = $result->fetch_assoc()) {
        $row['id'] = (int)$row['id'];
        $customers[] = $row;
    }

    $stmt->close();

    respond(true, 'Member options loaded successfully.', [
        'groups' => $groups,
        'customers' => $customers,
    ]);
}

if ($action !== 'create') {
    respond(false, 'Invalid action.', [], 400);
}

if (!hasChitMemberPermission($conn, 'create')) {
    respond(false, 'You do not have permission to add chit members.', [], 403);
}

if (!hash_equals(
    (string)($_SESSION['chit_member_csrf'] ?? ''),
    (string)($_POST['csrf_token'] ?? '')
)) {
    respond(false, 'Invalid or expired request token. Refresh the page and try again.', [], 419);
}

$groupId = (int)($_POST['chit_group_id'] ?? 0);
$customerId = (int)($_POST['customer_id'] ?? 0);
$joinDate = trim((string)($_POST['join_date'] ?? ''));
$ticketNo = trim((string)($_POST['ticket_no'] ?? ''));
$nomineeName = trim((string)($_POST['nominee_name'] ?? ''));
$nomineeRelation = trim((string)($_POST['nominee_relation'] ?? ''));

if ($groupId <= 0) {
    respond(false, 'Please select a chit group.');
}

if ($customerId <= 0) {
    respond(false, 'Please select a customer.');
}

$dateObject = DateTime::createFromFormat('Y-m-d', $joinDate);

if (!$dateObject || $dateObject->format('Y-m-d') !== $joinDate) {
    respond(false, 'A valid join date is required.');
}

if ($ticketNo === '') {
    $ticketNo = nextTicketNo($conn, $businessId);
}

if (mb_strlen($ticketNo) > 50) {
    respond(false, 'Ticket number must not exceed 50 characters.');
}

if (mb_strlen($nomineeName) > 150) {
    respond(false, 'Nominee name must not exceed 150 characters.');
}

if (mb_strlen($nomineeRelation) > 100) {
    respond(false, 'Nominee relation must not exceed 100 characters.');
}

$stmt = $conn->prepare(
    "SELECT
        g.id,
        g.total_members,
        COUNT(CASE WHEN m.status = 'Active' THEN 1 END) AS active_members
     FROM chit_groups g
     LEFT JOIN chit_members m
        ON m.chit_group_id = g.id
       AND m.business_id = g.business_id
     WHERE g.id = ?
       AND g.business_id = ?
       AND g.branch_id = ?
       AND g.status = 'Active'
     GROUP BY g.id, g.total_members
     LIMIT 1"
);

$stmt->bind_param('iii', $groupId, $businessId, $branchId);
$stmt->execute();
$group = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$group) {
    respond(false, 'Selected chit group was not found or is inactive.', [], 404);
}

if ((int)$group['active_members'] >= (int)$group['total_members']) {
    respond(false, 'The selected chit group has reached its member limit.');
}

$stmt = $conn->prepare(
    "SELECT id
     FROM customers
     WHERE id = ?
       AND business_id = ?
       AND is_active = 1
       AND customer_type IN ('Chit','All')
       AND (home_branch_id = ? OR home_branch_id IS NULL)
     LIMIT 1"
);

$stmt->bind_param('iii', $customerId, $businessId, $branchId);
$stmt->execute();
$customer = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$customer) {
    respond(false, 'Selected customer was not found or is inactive.', [], 404);
}

$stmt = $conn->prepare(
    "SELECT id
     FROM chit_members
     WHERE business_id = ?
       AND chit_group_id = ?
       AND customer_id = ?
       AND status = 'Active'
     LIMIT 1"
);

$stmt->bind_param('iii', $businessId, $groupId, $customerId);
$stmt->execute();
$duplicateMember = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($duplicateMember) {
    respond(false, 'This customer is already an active member of the selected chit group.', [], 409);
}

$stmt = $conn->prepare(
    "SELECT id
     FROM chit_members
     WHERE business_id = ?
       AND ticket_no = ?
     LIMIT 1"
);

$stmt->bind_param('is', $businessId, $ticketNo);
$stmt->execute();
$duplicateTicket = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($duplicateTicket) {
    respond(false, 'This ticket number is already in use.', [], 409);
}

$conn->begin_transaction();

try {
    $stmt = $conn->prepare(
        "INSERT INTO chit_members (
            business_id,
            chit_group_id,
            customer_id,
            ticket_no,
            join_date,
            nominee_name,
            nominee_relation,
            status
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, 'Active'
        )"
    );

    if (!$stmt) {
        throw new RuntimeException('Unable to prepare chit member insert: ' . $conn->error);
    }

    $stmt->bind_param(
        'iiissss',
        $businessId,
        $groupId,
        $customerId,
        $ticketNo,
        $joinDate,
        $nomineeName,
        $nomineeRelation
    );

    if (!$stmt->execute()) {
        throw new RuntimeException('Failed to save chit member: ' . $stmt->error);
    }

    $memberId = (int)$stmt->insert_id;
    $stmt->close();

    $conn->commit();

    respond(true, 'Chit member added successfully.', [
        'member_id' => $memberId,
        'ticket_no' => $ticketNo,
    ]);
} catch (Throwable $exception) {
    $conn->rollback();
    respond(false, $exception->getMessage(), [], 500);
}
