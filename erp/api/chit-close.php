<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));
header('Content-Type: application/json; charset=utf-8');

function respond(
    bool $success,
    string $message,
    array $extra = [],
    int $status = 200
): void {
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

function hasPermission(mysqli $conn, string $action): bool
{
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin') {
        return true;
    }

    $fieldMap = [
        'open' => 'can_open',
        'view' => 'can_view',
        'update' => 'can_update',
        'approve' => 'can_approve',
    ];

    $field = $fieldMap[$action] ?? '';
    if ($field === '') {
        return false;
    }

    $permissions = $_SESSION['permissions'] ?? [];

    foreach (['perm.chit-close', 'perm.chit.groups', 'perm.chit'] as $permissionCode) {
        if (isset($permissions[$permissionCode][$field])) {
            return (int)$permissions[$permissionCode][$field] === 1;
        }
    }

    $roleName = strtolower(trim((string)($_SESSION['role_name'] ?? '')));
    $roleCode = strtolower(trim((string)($_SESSION['role_code'] ?? '')));

    return in_array($roleName, ['super admin', 'admin', 'manager', 'billing'], true)
        || in_array($roleCode, ['super_admin', 'admin', 'manager', 'billing'], true);
}

if (empty($_SESSION['user_id'])) {
    respond(false, 'Your session has expired. Please log in again.', [], 401);
}

$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? $_SESSION['default_branch_id'] ?? 0);
$userId = (int)$_SESSION['user_id'];

if ($businessId <= 0 || $branchId <= 0) {
    respond(false, 'A valid business and branch must be selected.', [], 403);
}

if (!hasPermission($conn, 'open')) {
    respond(false, 'You do not have permission to open Close Chit.', [], 403);
}

$action = strtolower(trim((string)($_REQUEST['action'] ?? 'groups')));

if ($action === 'groups') {
    if (!hasPermission($conn, 'view') && !hasPermission($conn, 'open')) {
        respond(false, 'You do not have permission to view active chit groups.', [], 403);
    }

    $stmt = $conn->prepare(
        "SELECT
            id,
            group_no,
            group_name,
            chit_type,
            total_members,
            total_months,
            status
         FROM chit_groups
         WHERE business_id = ?
           AND branch_id = ?
           AND status = 'Active'
         ORDER BY group_name, group_no"
    );

    if (!$stmt) {
        respond(false, 'Unable to prepare active-group query: ' . $conn->error, [], 500);
    }

    $stmt->bind_param('ii', $businessId, $branchId);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        respond(false, 'Unable to load active chit groups: ' . $error, [], 500);
    }

    $result = $stmt->get_result();
    $groups = [];

    while ($result && $row = $result->fetch_assoc()) {
        $row['id'] = (int)$row['id'];
        $row['total_members'] = (int)$row['total_members'];
        $row['total_months'] = (int)$row['total_months'];
        $groups[] = $row;
    }

    $stmt->close();

    respond(true, 'Active chit groups loaded successfully.', [
        'groups' => $groups,
    ]);
}

if ($action !== 'close' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Invalid action.', [], 400);
}

if (!hasPermission($conn, 'update') && !hasPermission($conn, 'approve')) {
    respond(false, 'You do not have permission to close chit groups.', [], 403);
}

$csrfToken = (string)($_POST['csrf_token'] ?? '');
$sessionToken = (string)($_SESSION['chit_close_csrf'] ?? '');

if (
    $csrfToken === '' ||
    $sessionToken === '' ||
    !hash_equals($sessionToken, $csrfToken)
) {
    respond(false, 'Invalid security token. Refresh the page and try again.', [], 419);
}

$groupId = (int)($_POST['group_id'] ?? 0);
$remarks = trim((string)($_POST['remarks'] ?? ''));

if ($groupId <= 0) {
    respond(false, 'Please select a valid active chit group.', [], 422);
}

if (mb_strlen($remarks) > 1000) {
    respond(false, 'Close remarks cannot exceed 1000 characters.', [], 422);
}

$stmt = $conn->prepare(
    "SELECT id, group_no, group_name, status
     FROM chit_groups
     WHERE id = ?
       AND business_id = ?
       AND branch_id = ?
     LIMIT 1"
);

if (!$stmt) {
    respond(false, 'Unable to validate the selected chit group: ' . $conn->error, [], 500);
}

$stmt->bind_param('iii', $groupId, $businessId, $branchId);
$stmt->execute();
$group = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$group) {
    respond(false, 'The selected chit group was not found in this branch.', [], 404);
}

if (($group['status'] ?? '') !== 'Active') {
    respond(false, 'The selected chit group is not active.', [], 422);
}

$closeNote = trim(
    'Closed on ' .
    date('d-m-Y H:i:s') .
    ' by user #' .
    $userId .
    ($remarks !== '' ? '. Remarks: ' . $remarks : '')
);

$conn->begin_transaction();

try {
    $stmt = $conn->prepare(
        "UPDATE chit_groups
         SET
            status = 'Closed',
            notes = CONCAT(
                COALESCE(notes, ''),
                CASE
                    WHEN COALESCE(notes, '') = '' THEN ''
                    ELSE '\n'
                END,
                ?
            )
         WHERE id = ?
           AND business_id = ?
           AND branch_id = ?
           AND status = 'Active'"
    );

    if (!$stmt) {
        throw new RuntimeException(
            'Unable to prepare chit-group update: ' . $conn->error
        );
    }

    $stmt->bind_param(
        'siii',
        $closeNote,
        $groupId,
        $businessId,
        $branchId
    );

    if (!$stmt->execute()) {
        throw new RuntimeException(
            'Unable to close the chit group: ' . $stmt->error
        );
    }

    if ($stmt->affected_rows !== 1) {
        throw new RuntimeException(
            'The chit group could not be closed because its status changed.'
        );
    }

    $stmt->close();

    /*
     * Current schema uses chit_members.chit_group_id.
     * The old page used group_id, which does not match the current module.
     */
    $stmt = $conn->prepare(
        "UPDATE chit_members
         SET status = 'Closed'
         WHERE chit_group_id = ?
           AND business_id = ?
           AND status <> 'Closed'"
    );

    if (!$stmt) {
        throw new RuntimeException(
            'Unable to prepare member update: ' . $conn->error
        );
    }

    $stmt->bind_param('ii', $groupId, $businessId);

    if (!$stmt->execute()) {
        throw new RuntimeException(
            'Unable to close chit members: ' . $stmt->error
        );
    }

    $closedMembers = $stmt->affected_rows;
    $stmt->close();

    $conn->commit();

    respond(true, 'Chit closed successfully.', [
        'group_id' => $groupId,
        'group_no' => $group['group_no'],
        'group_name' => $group['group_name'],
        'closed_members' => $closedMembers,
    ]);
} catch (Throwable $exception) {
    $conn->rollback();
    respond(false, $exception->getMessage(), [], 500);
}
