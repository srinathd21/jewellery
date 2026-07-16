<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Kolkata');
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
    respond(
        false,
        'Database config file not found. Checked: ' . implode(', ', $configCandidates),
        [],
        500
    );
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    respond(false, 'Database connection not available.', [], 500);
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn->set_charset('utf8mb4');

$numberHelperCandidates = [
    $rootDir . '/includes/document-number-helper.php',
    $rootDir . '/document-number-helper.php',
];
foreach ($numberHelperCandidates as $numberHelperFile) {
    if (is_file($numberHelperFile)) {
        require_once $numberHelperFile;
        break;
    }
}

if (!function_exists('tableExists')) {
    function tableExists(mysqli $conn, string $table): bool
    {
        $table = $conn->real_escape_string($table);
        $result = $conn->query("SHOW TABLES LIKE '{$table}'");
        return $result && $result->num_rows > 0;
    }
}

if (!function_exists('hasColumn')) {
    function hasColumn(mysqli $conn, string $table, string $column): bool
    {
        $table = $conn->real_escape_string($table);
        $column = $conn->real_escape_string($column);
        $result = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
        return $result && $result->num_rows > 0;
    }
}

if (!function_exists('nextNo')) {
    function nextNo(mysqli $conn, string $table, string $column, string $prefix, int $businessId): string
    {
        $like = $prefix . '%';

        $stmt = $conn->prepare(
            "SELECT `{$column}`
             FROM `{$table}`
             WHERE business_id = ?
               AND `{$column}` LIKE ?
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

        if ($row && preg_match('/(\d+)$/', (string)($row[$column] ?? ''), $match)) {
            $next = (int)$match[1] + 1;
        }

        return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('hasChitCreatePermission')) {
    function hasChitCreatePermission(mysqli $conn, string $action): bool
    {
        $fieldMap = [
            'open'   => 'can_open',
            'view'   => 'can_view',
            'create' => 'can_create',
            'update' => 'can_update',
            'delete' => 'can_delete',
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
            'perm.chit.create',
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

        if ($businessId <= 0 || $roleId <= 0) {
            return false;
        }

        if (!tableExists($conn, 'permissions') || !tableExists($conn, 'role_permissions')) {
            return false;
        }

        $placeholders = implode(',', array_fill(0, count($permissionCodes), '?'));

        $sql = "SELECT
                    MAX(COALESCE(rp.`{$field}`, 0)) AS allowed
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
}

if (empty($_SESSION['user_id'])) {
    respond(false, 'Your session has expired. Please log in again.', [], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Invalid request method.', [], 405);
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

if (!hasChitCreatePermission($conn, 'create')) {
    respond(false, 'You do not have permission to create chit groups.', [
        'debug_role_name' => (string)($_SESSION['role_name'] ?? ''),
        'debug_role_code' => (string)($_SESSION['role_code'] ?? ''),
        'debug_user_type' => (string)($_SESSION['user_type'] ?? ''),
    ], 403);
}

if (!hash_equals(
    (string)($_SESSION['chit_create_csrf'] ?? ''),
    (string)($_POST['csrf_token'] ?? '')
)) {
    respond(false, 'Invalid or expired request token. Refresh the page and try again.', [], 419);
}

foreach (['chit_groups', 'chit_installments'] as $requiredTable) {
    if (!tableExists($conn, $requiredTable)) {
        respond(false, "Required table `{$requiredTable}` was not found.", [], 500);
    }
}

$action = trim((string)($_POST['action'] ?? 'create'));

if ($action === 'preview_number') {
    $previewDate = trim((string)($_POST['start_date'] ?? date('Y-m-d')));
    $previewDateObject = DateTime::createFromFormat('Y-m-d', $previewDate);
    if (!$previewDateObject || $previewDateObject->format('Y-m-d') !== $previewDate) {
        respond(false, 'A valid start date is required.', [], 422);
    }
    try {
        $previewNo = function_exists('generateDocumentNumber') && tableExists($conn, 'document_number_settings')
            ? generateDocumentNumber($conn, $businessId, $branchId, 'chit', $previewDate)
            : nextNo($conn, 'chit_groups', 'group_no', 'CH' . date('Ym', strtotime($previewDate)), $businessId);
        respond(true, 'Chit number preview generated.', ['group_no' => $previewNo]);
    } catch (Throwable $numberError) {
        respond(false, $numberError->getMessage(), [], 500);
    }
}

$groupNo = '';
$groupName = trim((string)($_POST['group_name'] ?? ''));
$chitType = trim((string)($_POST['chit_type'] ?? 'Money'));
$startDate = trim((string)($_POST['start_date'] ?? ''));
$totalMembers = (int)($_POST['total_members'] ?? 0);
$totalMonths = (int)($_POST['total_months'] ?? 0);
$installmentAmount = (float)($_POST['installment_amount'] ?? 0);
$chitValue = (float)($_POST['chit_value'] ?? 0);
$auctionType = trim((string)($_POST['auction_type'] ?? 'Auction'));
$auctionDayText = trim((string)($_POST['auction_day'] ?? ''));
$auctionDay = $auctionDayText === '' ? null : (int)$auctionDayText;
$graceDays = (int)($_POST['grace_days'] ?? 0);
$lateFeeAmount = (float)($_POST['late_fee_amount'] ?? 0);
$notes = trim((string)($_POST['notes'] ?? ''));

if ($groupName === '') {
    respond(false, 'Group name is required.');
}

if (!in_array($chitType, ['Money', 'Silver', 'Gold'], true)) {
    respond(false, 'Invalid chit type.');
}

$dateObject = DateTime::createFromFormat('Y-m-d', $startDate);

if (!$dateObject || $dateObject->format('Y-m-d') !== $startDate) {
    respond(false, 'A valid start date is required.');
}

if ($totalMembers <= 0 || $totalMembers > 10000) {
    respond(false, 'Total members must be between 1 and 10,000.');
}

if ($totalMonths <= 0 || $totalMonths > 600) {
    respond(false, 'Total months must be between 1 and 600.');
}

if ($installmentAmount < 0 || $chitValue < 0 || $lateFeeAmount < 0) {
    respond(false, 'Amounts cannot be negative.');
}

if (!in_array($auctionType, ['Auction', 'Lucky Draw', 'Manual'], true)) {
    respond(false, 'Invalid auction type.');
}

if ($auctionDay !== null && ($auctionDay < 1 || $auctionDay > 31)) {
    respond(false, 'Auction day must be between 1 and 31.');
}

if ($graceDays < 0 || $graceDays > 365) {
    respond(false, 'Grace days must be between 0 and 365.');
}

$endDate = (clone $dateObject)
    ->modify('+' . ($totalMonths - 1) . ' months')
    ->format('Y-m-d');

$conn->begin_transaction();

try {
    $groupNo = function_exists('generateDocumentNumber') && tableExists($conn, 'document_number_settings')
        ? generateDocumentNumber($conn, $businessId, $branchId, 'chit', $startDate)
        : nextNo($conn, 'chit_groups', 'group_no', 'CH' . date('Ym', strtotime($startDate)), $businessId);

    $duplicateStmt = $conn->prepare(
        "SELECT id FROM chit_groups WHERE business_id = ? AND group_no = ? LIMIT 1 FOR UPDATE"
    );
    if (!$duplicateStmt) {
        throw new RuntimeException('Unable to verify chit number: ' . $conn->error);
    }
    $duplicateStmt->bind_param('is', $businessId, $groupNo);
    $duplicateStmt->execute();
    $duplicate = $duplicateStmt->get_result()->fetch_assoc();
    $duplicateStmt->close();
    if ($duplicate) {
        throw new RuntimeException('Generated chit number already exists. Check Master Control sequence settings.');
    }

    $stmt = $conn->prepare(
        "INSERT INTO chit_groups (
            business_id,
            branch_id,
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
            notes,
            created_by
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', ?, ?
        )"
    );

    if (!$stmt) {
        throw new RuntimeException('Unable to prepare chit group insert: ' . $conn->error);
    }

    $stmt->bind_param(
        'iisssssiiddsiidsi',
        $businessId,
        $branchId,
        $groupNo,
        $groupName,
        $chitType,
        $startDate,
        $endDate,
        $totalMembers,
        $totalMonths,
        $installmentAmount,
        $chitValue,
        $auctionType,
        $auctionDay,
        $graceDays,
        $lateFeeAmount,
        $notes,
        $userId
    );

    if (!$stmt->execute()) {
        throw new RuntimeException('Failed to create chit group: ' . $stmt->error);
    }

    $groupId = (int)$stmt->insert_id;
    $stmt->close();

    $installmentStmt = $conn->prepare(
        "INSERT INTO chit_installments (
            business_id,
            chit_group_id,
            installment_no,
            due_date,
            status
        ) VALUES (?, ?, ?, ?, 'Open')"
    );

    if (!$installmentStmt) {
        throw new RuntimeException('Unable to prepare installment insert: ' . $conn->error);
    }

    for ($installmentNo = 1; $installmentNo <= $totalMonths; $installmentNo++) {
        $dueDate = (clone $dateObject)
            ->modify('+' . ($installmentNo - 1) . ' months')
            ->format('Y-m-d');

        $installmentStmt->bind_param(
            'iiis',
            $businessId,
            $groupId,
            $installmentNo,
            $dueDate
        );

        if (!$installmentStmt->execute()) {
            throw new RuntimeException(
                'Failed to generate installment ' . $installmentNo . ': ' . $installmentStmt->error
            );
        }
    }

    $installmentStmt->close();

    if (
        tableExists($conn, 'audit_logs') &&
        hasColumn($conn, 'audit_logs', 'module_code')
    ) {
        $description = 'Created chit group ' . $groupNo;
        $newValues = json_encode([
            'group_no' => $groupNo,
            'group_name' => $groupName,
            'chit_type' => $chitType,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'total_members' => $totalMembers,
            'total_months' => $totalMonths,
            'installment_amount' => $installmentAmount,
            'chit_value' => $chitValue,
            'auction_type' => $auctionType,
            'auction_day' => $auctionDay,
            'grace_days' => $graceDays,
            'late_fee_amount' => $lateFeeAmount,
            'notes' => $notes,
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
            ) VALUES (
                ?, ?, ?, 'chit.create', 'Create', 'chit_groups', ?, ?, ?, ?, ?
            )"
        );

        if ($auditStmt) {
            $auditStmt->bind_param(
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

            $auditStmt->execute();
            $auditStmt->close();
        }
    }

    $conn->commit();

    respond(true, 'Chit group created successfully.', [
        'group_id' => $groupId,
        'group_no' => $groupNo,
    ]);
} catch (Throwable $exception) {
    $conn->rollback();

    respond(false, $exception->getMessage(), [], 500);
}
