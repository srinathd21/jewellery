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

function hasColumn(mysqli $conn, string $table, string $column): bool
{
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    return $result && $result->num_rows > 0;
}

function hasPermission(mysqli $conn, string $action): bool
{
    $map = [
        'open' => 'can_open',
        'view' => 'can_view',
        'update' => 'can_update',
        'approve' => 'can_approve',
    ];

    $field = $map[$action] ?? '';

    if ($field === '') {
        return false;
    }

    $adminRoles = ['platform admin','super admin','admin','manager','billing','super_admin'];

    foreach ([
        strtolower(trim((string)($_SESSION['user_type'] ?? ''))),
        strtolower(trim((string)($_SESSION['role_name'] ?? ''))),
        strtolower(trim((string)($_SESSION['role_code'] ?? ''))),
    ] as $roleValue) {
        if (in_array($roleValue, $adminRoles, true)) {
            return true;
        }
    }

    $codes = ['perm.chit-close', 'perm.chit'];
    $sessionPermissions = $_SESSION['permissions'] ?? [];

    foreach ($codes as $code) {
        if (isset($sessionPermissions[$code][$field])) {
            return (int)$sessionPermissions[$code][$field] === 1;
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

    $placeholders = implode(',', array_fill(0, count($codes), '?'));

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

    $types = 'ii' . str_repeat('s', count($codes));
    $params = array_merge([$businessId, $roleId], $codes);

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row['allowed'] ?? 0) === 1;
}

function auditClosure(
    mysqli $conn,
    int $businessId,
    int $branchId,
    int $userId,
    int $groupId,
    string $groupNo,
    string $remarks
): void {
    if (!tableExists($conn, 'audit_logs') || !hasColumn($conn, 'audit_logs', 'module_code')) {
        return;
    }

    $description = 'Closed chit group ' . $groupNo;
    $newValues = json_encode([
        'status' => 'Closed',
        'remarks' => $remarks,
        'members_status' => 'Completed',
        'installments_status' => 'Closed',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $ipAddress = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $userAgent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

    $stmt = $conn->prepare(
        "INSERT INTO audit_logs (
            business_id,
            branch_id,
            user_id,
            module_code,
            action_type,
            reference_table,
            reference_id,
            description,
            new_values_json,
            ip_address,
            user_agent
        ) VALUES (
            ?, ?, ?, 'chit.close', 'Update', 'chit_groups', ?, ?, ?, ?, ?
        )"
    );

    if ($stmt) {
        $stmt->bind_param(
            'iiiissss',
            $businessId,
            $branchId,
            $userId,
            $groupId,
            $description,
            $newValues,
            $ipAddress,
            $userAgent
        );
        $stmt->execute();
        $stmt->close();
    }
}

if (empty($_SESSION['user_id'])) {
    respond(false, 'Your session has expired. Please log in again.', [], 401);
}

$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? $_SESSION['default_branch_id'] ?? 0);
$userId = (int)($_SESSION['user_id'] ?? 0);

if ($businessId <= 0 || $branchId <= 0) {
    respond(false, 'A valid business and branch must be selected.', [], 403);
}

foreach (['chit_groups', 'chit_members', 'chit_installments'] as $requiredTable) {
    if (!tableExists($conn, $requiredTable)) {
        respond(false, "Required table `{$requiredTable}` was not found.", [], 500);
    }
}

$action = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? trim((string)($_POST['action'] ?? ''))
    : trim((string)($_GET['action'] ?? 'bootstrap'));

if ($action === 'bootstrap') {
    if (!hasPermission($conn, 'view') && !hasPermission($conn, 'open')) {
        respond(false, 'You do not have permission to view active chit groups.', [], 403);
    }

    $stmt = $conn->prepare(
        "SELECT
            g.id,
            g.group_no,
            g.group_name,
            g.total_members,
            COUNT(DISTINCT CASE WHEN m.status = 'Active' THEN m.id END) AS active_members,
            COUNT(DISTINCT CASE WHEN ci.status = 'Open' THEN ci.id END) AS open_installments
         FROM chit_groups g
         LEFT JOIN chit_members m
            ON m.chit_group_id = g.id
           AND m.business_id = g.business_id
         LEFT JOIN chit_installments ci
            ON ci.chit_group_id = g.id
           AND ci.business_id = g.business_id
         WHERE g.business_id = ?
           AND g.branch_id = ?
           AND g.status = 'Active'
         GROUP BY g.id, g.group_no, g.group_name, g.total_members
         ORDER BY g.group_name"
    );

    if (!$stmt) {
        respond(false, 'Unable to prepare active chit group query: ' . $conn->error, [], 500);
    }

    $stmt->bind_param('ii', $businessId, $branchId);
    $stmt->execute();
    $result = $stmt->get_result();
    $groups = [];

    while ($result && $row = $result->fetch_assoc()) {
        $row['id'] = (int)$row['id'];
        $row['total_members'] = (int)$row['total_members'];
        $row['active_members'] = (int)$row['active_members'];
        $row['open_installments'] = (int)$row['open_installments'];
        $groups[] = $row;
    }

    $stmt->close();

    respond(true, 'Active chit groups loaded successfully.', [
        'groups' => $groups,
    ]);
}

if ($action !== 'close') {
    respond(false, 'Invalid action.', [], 400);
}

if (!hasPermission($conn, 'update') && !hasPermission($conn, 'approve')) {
    respond(false, 'You do not have permission to close chit groups.', [], 403);
}

if (!hash_equals(
    (string)($_SESSION['chit_close_csrf'] ?? ''),
    (string)($_POST['csrf_token'] ?? '')
)) {
    respond(false, 'Invalid or expired request token. Refresh the page and try again.', [], 419);
}

$groupId = (int)($_POST['chit_group_id'] ?? 0);
$remarks = trim((string)($_POST['remarks'] ?? ''));

if ($groupId <= 0) {
    respond(false, 'Please select an active chit group.');
}

if (mb_strlen($remarks) > 2000) {
    respond(false, 'Closure remarks must not exceed 2,000 characters.');
}

$stmt = $conn->prepare(
    "SELECT id, group_no, group_name, notes
     FROM chit_groups
     WHERE id = ?
       AND business_id = ?
       AND branch_id = ?
       AND status = 'Active'
     LIMIT 1"
);

$stmt->bind_param('iii', $groupId, $businessId, $branchId);
$stmt->execute();
$group = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$group) {
    respond(false, 'Selected chit group was not found or is already closed.', [], 404);
}

$existingNotes = trim((string)($group['notes'] ?? ''));
$closureLine = 'Closed on ' . date('d-m-Y H:i') . ' by user #' . $userId;

if ($remarks !== '') {
    $closureLine .= ' - ' . $remarks;
}

$updatedNotes = $existingNotes !== ''
    ? $existingNotes . PHP_EOL . $closureLine
    : $closureLine;

$conn->begin_transaction();

try {
    $stmt = $conn->prepare(
        "UPDATE chit_groups
         SET status = 'Closed',
             notes = ?
         WHERE id = ?
           AND business_id = ?
           AND branch_id = ?
           AND status = 'Active'"
    );

    if (!$stmt) {
        throw new RuntimeException('Unable to prepare chit group closure: ' . $conn->error);
    }

    $stmt->bind_param('siii', $updatedNotes, $groupId, $businessId, $branchId);

    if (!$stmt->execute()) {
        throw new RuntimeException('Failed to close chit group: ' . $stmt->error);
    }

    if ($stmt->affected_rows !== 1) {
        throw new RuntimeException('The chit group was not closed because its status changed.');
    }

    $stmt->close();

    $stmt = $conn->prepare(
        "UPDATE chit_members
         SET status = 'Completed'
         WHERE chit_group_id = ?
           AND business_id = ?
           AND status = 'Active'"
    );

    if (!$stmt) {
        throw new RuntimeException('Unable to prepare member completion update: ' . $conn->error);
    }

    $stmt->bind_param('ii', $groupId, $businessId);

    if (!$stmt->execute()) {
        throw new RuntimeException('Failed to complete chit members: ' . $stmt->error);
    }

    $completedMembers = $stmt->affected_rows;
    $stmt->close();

    $stmt = $conn->prepare(
        "UPDATE chit_installments
         SET status = 'Closed'
         WHERE chit_group_id = ?
           AND business_id = ?
           AND status = 'Open'"
    );

    if (!$stmt) {
        throw new RuntimeException('Unable to prepare installment closure update: ' . $conn->error);
    }

    $stmt->bind_param('ii', $groupId, $businessId);

    if (!$stmt->execute()) {
        throw new RuntimeException('Failed to close open installments: ' . $stmt->error);
    }

    $closedInstallments = $stmt->affected_rows;
    $stmt->close();

    auditClosure(
        $conn,
        $businessId,
        $branchId,
        $userId,
        $groupId,
        (string)$group['group_no'],
        $remarks
    );

    $conn->commit();

    respond(true, 'Chit closed successfully.', [
        'group_id' => $groupId,
        'completed_members' => $completedMembers,
        'closed_installments' => $closedInstallments,
    ]);
} catch (Throwable $exception) {
    $conn->rollback();
    respond(false, $exception->getMessage(), [], 500);
}
