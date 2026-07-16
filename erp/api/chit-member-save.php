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
    ], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$rootDir = dirname(__DIR__);
$configCandidates = [
    $rootDir . '/config/config.php',
    $rootDir . '/config.php',
    $rootDir . '/includes/config.php',
    $rootDir . '/super-admin/includes/config.php',
];

foreach ($configCandidates as $configFile) {
    if (is_file($configFile)) {
        require_once $configFile;
        break;
    }
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    respond(false, 'Database configuration is not available.', [], 500);
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn->set_charset('utf8mb4');

function tableExists(mysqli $conn, string $table): bool
{
    $safe = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $result && $result->num_rows > 0;
}

function hasColumn(mysqli $conn, string $table, string $column): bool
{
    $safeTable = $conn->real_escape_string($table);
    $safeColumn = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    return $result && $result->num_rows > 0;
}

function memberPermission(string $action): bool
{
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin') {
        return true;
    }

    $map = ['open' => 'can_open', 'create' => 'can_create'];
    $field = $map[$action] ?? '';
    if ($field === '') {
        return false;
    }

    foreach (['perm.chit.members', 'perm.chit.groups', 'perm.chit'] as $permissionCode) {
        if (isset($_SESSION['permissions'][$permissionCode][$field])) {
            return (int)$_SESSION['permissions'][$permissionCode][$field] === 1;
        }
    }

    $admins = ['platform admin', 'super admin', 'admin', 'business admin', 'manager', 'billing', 'super_admin', 'business_admin'];
    foreach ([
        strtolower(trim((string)($_SESSION['user_type'] ?? ''))),
        strtolower(trim((string)($_SESSION['role_name'] ?? ''))),
        strtolower(trim((string)($_SESSION['role_code'] ?? ''))),
    ] as $role) {
        if (in_array($role, $admins, true)) {
            return true;
        }
    }

    return false;
}

if (empty($_SESSION['user_id'])) {
    respond(false, 'Your session has expired. Please log in again.', [], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Invalid request method.', [], 405);
}

if (!memberPermission('open') || !memberPermission('create')) {
    respond(false, 'You do not have permission to add chit members.', [], 403);
}

if (!hash_equals(
    (string)($_SESSION['chit_member_csrf'] ?? ''),
    (string)($_POST['csrf_token'] ?? '')
)) {
    respond(false, 'Invalid or expired request token. Refresh the page and try again.', [], 419);
}

$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? $_SESSION['default_branch_id'] ?? 0);
$userId = (int)($_SESSION['user_id'] ?? 0);

if ($businessId <= 0 || $branchId <= 0) {
    respond(false, 'A valid business and branch must be selected.', [], 403);
}

foreach (['chit_groups', 'chit_members', 'customers'] as $table) {
    if (!tableExists($conn, $table)) {
        respond(false, "Required table `{$table}` was not found.", [], 500);
    }
}

$groupId = (int)($_POST['group_id'] ?? $_POST['chit_group_id'] ?? $_POST['id'] ?? 0);
$customerId = (int)($_POST['customer_id'] ?? 0);
$ticketNo = trim((string)($_POST['ticket_no'] ?? ''));
$joinDate = trim((string)($_POST['join_date'] ?? ''));
$nomineeName = trim((string)($_POST['nominee_name'] ?? ''));
$nomineeRelation = trim((string)($_POST['nominee_relation'] ?? ''));
$status = trim((string)($_POST['status'] ?? 'Active'));

$errors = [];
if ($groupId <= 0) $errors[] = 'Invalid chit group.';
if ($customerId <= 0) $errors[] = 'Select a customer.';
if ($ticketNo === '') $errors[] = 'Ticket number is required.';
if (!preg_match('/^[A-Za-z0-9\/_-]{1,50}$/', $ticketNo)) $errors[] = 'Ticket number contains invalid characters.';

$dateObject = DateTime::createFromFormat('Y-m-d', $joinDate);
if (!$dateObject || $dateObject->format('Y-m-d') !== $joinDate) {
    $errors[] = 'A valid join date is required.';
}

if (!in_array($status, ['Active', 'Completed', 'Defaulted', 'Cancelled'], true)) {
    $errors[] = 'Invalid member status.';
}

if ($errors) {
    respond(false, implode(' ', $errors), [], 422);
}

$conn->begin_transaction();

try {
    $stmt = $conn->prepare(
        "SELECT cg.*,
                (SELECT COUNT(*) FROM chit_members cm WHERE cm.chit_group_id = cg.id) AS member_count
         FROM chit_groups cg
         WHERE cg.id = ?
           AND cg.business_id = ?
         LIMIT 1
         FOR UPDATE"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare chit lookup: ' . $conn->error);
    }
    $stmt->bind_param('ii', $groupId, $businessId);
    $stmt->execute();
    $group = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$group) {
        throw new RuntimeException('Chit group not found.');
    }

    if (in_array((string)$group['status'], ['Closed', 'Cancelled'], true)) {
        throw new RuntimeException('Members cannot be added to a closed or cancelled chit group.');
    }

    if ((int)$group['member_count'] >= (int)$group['total_members']) {
        throw new RuntimeException('This chit group has reached its maximum member capacity.');
    }

    $stmt = $conn->prepare(
        "SELECT id, customer_name
         FROM customers
         WHERE id = ?
           AND business_id = ?
           AND is_active = 1
         LIMIT 1"
    );
    $stmt->bind_param('ii', $customerId, $businessId);
    $stmt->execute();
    $customer = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$customer) {
        throw new RuntimeException('Selected customer was not found or is inactive.');
    }

    if (tableExists($conn, 'customer_services')) {
        $stmt = $conn->prepare(
            "SELECT id
             FROM customer_services
             WHERE business_id = ?
               AND customer_id = ?
               AND service_type = 'Chit'
               AND is_active = 1
             LIMIT 1"
        );
        $stmt->bind_param('ii', $businessId, $customerId);
        $stmt->execute();
        $service = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$service) {
            throw new RuntimeException('Selected customer is not enabled for the Chit service.');
        }
    }

    $stmt = $conn->prepare(
        "SELECT id
         FROM chit_members
         WHERE chit_group_id = ?
           AND customer_id = ?
         LIMIT 1"
    );
    $stmt->bind_param('ii', $groupId, $customerId);
    $stmt->execute();
    $duplicateCustomer = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($duplicateCustomer) {
        throw new RuntimeException('This customer is already a member of the selected chit group.');
    }

    $stmt = $conn->prepare(
        "SELECT id
         FROM chit_members
         WHERE chit_group_id = ?
           AND ticket_no = ?
         LIMIT 1"
    );
    $stmt->bind_param('is', $groupId, $ticketNo);
    $stmt->execute();
    $duplicateTicket = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($duplicateTicket) {
        throw new RuntimeException('This ticket number is already used in the selected chit group.');
    }

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
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare member insert: ' . $conn->error);
    }

    $stmt->bind_param(
        'iiisssss',
        $businessId,
        $groupId,
        $customerId,
        $ticketNo,
        $joinDate,
        $nomineeName,
        $nomineeRelation,
        $status
    );

    if (!$stmt->execute()) {
        throw new RuntimeException('Unable to add chit member: ' . $stmt->error);
    }

    $memberId = (int)$stmt->insert_id;
    $stmt->close();

    if (tableExists($conn, 'audit_logs') && hasColumn($conn, 'audit_logs', 'module_code')) {
        $description = 'Added member ' . (string)$customer['customer_name'] . ' to chit group ' . (string)$group['group_no'];
        $newValues = json_encode([
            'chit_group_id' => $groupId,
            'customer_id' => $customerId,
            'ticket_no' => $ticketNo,
            'join_date' => $joinDate,
            'nominee_name' => $nomineeName,
            'nominee_relation' => $nomineeRelation,
            'status' => $status,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ipAddress = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $userAgent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

        $auditStmt = $conn->prepare(
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
             ) VALUES (?, ?, ?, 'chit.members', 'Create', 'chit_members', ?, ?, ?, ?, ?)"
        );
        if ($auditStmt) {
            $auditStmt->bind_param(
                'iiiissss',
                $businessId,
                $branchId,
                $userId,
                $memberId,
                $description,
                $newValues,
                $ipAddress,
                $userAgent
            );
            $auditStmt->execute();
            $auditStmt->close();
        }
    }

    $conn->commit();

    respond(true, 'Chit member added successfully.', [
        'member_id' => $memberId,
        'group_id' => $groupId,
        'ticket_no' => $ticketNo,
    ]);
} catch (Throwable $exception) {
    $conn->rollback();
    respond(false, $exception->getMessage(), [], 500);
}