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
        array_merge(['success' => $success, 'message' => $message], $extra),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

$rootDir = dirname(__DIR__);
$configCandidates = [
    $rootDir . '/config/config.php',
    $rootDir . '/config.php',
    $rootDir . '/includes/config.php',
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
    $safe = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $result && $result->num_rows > 0;
}

function hasColumn(mysqli $conn, string $table, string $column): bool
{
    $safeTable = $conn->real_escape_string($table);
    $safeColumn = $conn->real_escape_string($column);
    $result = $conn->query(
        "SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'"
    );
    return $result && $result->num_rows > 0;
}


function addActivityLog(
    mysqli $conn,
    int $businessId,
    int $branchId,
    int $userId,
    string $action,
    ?int $recordId,
    $oldValue,
    $newValue
): void {
    if (!tableExists($conn, 'activity_logs')) {
        return;
    }

    $roleId = (int)($_SESSION['role_id'] ?? 0);
    $oldJson = $oldValue === null ? null : json_encode($oldValue, JSON_UNESCAPED_UNICODE);
    $newJson = $newValue === null ? null : json_encode($newValue, JSON_UNESCAPED_UNICODE);
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $device = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

    $stmt = $conn->prepare(
        "INSERT INTO activity_logs
        (business_id, branch_id, user_id, role_id, module_name, action_type, record_id, old_value, new_value, ip_address, device_details)
        VALUES (?, ?, ?, ?, 'Payment Methods', ?, ?, ?, ?, ?, ?)"
    );

    if ($stmt) {
        $stmt->bind_param(
            'iiiisissss',
            $businessId,
            $branchId,
            $userId,
            $roleId,
            $action,
            $recordId,
            $oldJson,
            $newJson,
            $ip,
            $device
        );
        $stmt->execute();
        $stmt->close();
    }
}

if (empty($_SESSION['user_id'])) {
    respond(false, 'Your session has expired. Please log in again.', [], 401);
}


if (!function_exists('canAccessPaymentMethods')) {
    function canAccessPaymentMethods(mysqli $conn, string $action = 'open'): bool
    {
        if (($_SESSION['user_type'] ?? '') === 'Platform Admin') {
            return true;
        }

        $fieldMap = [
            'open' => 'can_open',
            'view' => 'can_view',
            'create' => 'can_create',
            'edit' => 'can_edit',
            'delete' => 'can_delete',
        ];

        $field = $fieldMap[$action] ?? 'can_open';

        $permissionCodes = [
            'perm.payment_methods',
            'perm.settings.payment_methods',
            'perm.accounts.payment_methods',
            'perm.accounts',
            'perm.settings',
        ];

        $sessionPermissions = $_SESSION['permissions'] ?? [];

        foreach ($permissionCodes as $code) {
            if (isset($sessionPermissions[$code][$field])) {
                return (int)$sessionPermissions[$code][$field] === 1;
            }
        }

        $allowedRoles = [
            'super admin',
            'super_admin',
            'admin',
            'business admin',
            'business_admin',
            'branch admin',
            'branch_admin',
            'manager',
            'branch manager',
            'branch_manager',
            'accounts',
            'accountant',
            'billing',
        ];

        $roleName = strtolower(trim((string)($_SESSION['role_name'] ?? '')));
        $roleCode = strtolower(trim((string)($_SESSION['role_code'] ?? '')));

        if (
            in_array($roleName, $allowedRoles, true) ||
            in_array($roleCode, $allowedRoles, true)
        ) {
            return true;
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);
        $businessId = (int)($_SESSION['business_id'] ?? 0);
        $roleId = (int)($_SESSION['role_id'] ?? 0);

        if (
            $userId > 0 &&
            tableExists($conn, 'users') &&
            tableExists($conn, 'roles')
        ) {
            $roleIdColumn = 'id';
            $userRoleColumn = 'role_id';
            $userIdColumn = 'id';

            $stmt = $conn->prepare(
                "SELECT
                    LOWER(TRIM(COALESCE(r.role_name, r.role_code, ''))) AS resolved_role
                 FROM users u
                 INNER JOIN roles r ON r.`{$roleIdColumn}` = u.`{$userRoleColumn}`
                 WHERE u.`{$userIdColumn}` = ?
                 LIMIT 1"
            );

            if ($stmt) {
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                $resolvedRole = strtolower(trim((string)($row['resolved_role'] ?? '')));

                if (in_array($resolvedRole, $allowedRoles, true)) {
                    return true;
                }
            }
        }

        if (
            $businessId > 0 &&
            $roleId > 0 &&
            tableExists($conn, 'permissions') &&
            tableExists($conn, 'role_permissions')
        ) {
            $placeholders = implode(
                ',',
                array_fill(0, count($permissionCodes), '?')
            );

            $sql = "SELECT MAX(COALESCE(rp.`{$field}`,0)) AS allowed
                    FROM role_permissions rp
                    INNER JOIN permissions p ON p.id = rp.permission_id
                    WHERE rp.business_id = ?
                      AND rp.role_id = ?
                      AND p.permission_code IN ({$placeholders})";

            if (hasColumn($conn, 'permissions', 'is_active')) {
                $sql .= " AND p.is_active = 1";
            }

            $stmt = $conn->prepare($sql);

            if ($stmt) {
                $types = 'ii' . str_repeat('s', count($permissionCodes));
                $params = array_merge(
                    [$businessId, $roleId],
                    $permissionCodes
                );

                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ((int)($row['allowed'] ?? 0) === 1) {
                    return true;
                }
            }
        }

        return false;
    }
}

$userId = (int)$_SESSION['user_id'];
$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? $_SESSION['default_branch_id'] ?? 0);

if ($businessId <= 0) {
    respond(false, 'A valid business must be selected.', [], 403);
}

if (!canAccessPaymentMethods($conn, 'open')) {
    respond(
        false,
        'Access denied. You do not have permission to manage Payment Methods.',
        [],
        403
    );
}

if (!tableExists($conn, 'payment_methods')) {
    respond(false, 'Required table `payment_methods` was not found.', [], 500);
}

function firstExistingColumn(
    mysqli $conn,
    string $table,
    array $candidates
): string {
    foreach ($candidates as $candidate) {
        if (hasColumn($conn, $table, $candidate)) {
            return $candidate;
        }
    }

    return '';
}

function bindDynamic(
    mysqli_stmt $stmt,
    string $types,
    array &$values
): void {
    if ($types === '' || !$values) {
        return;
    }

    $bind = [$types];

    foreach ($values as $key => $value) {
        $bind[] = &$values[$key];
    }

    call_user_func_array([$stmt, 'bind_param'], $bind);
}

function normalizePaymentMethodType(
    ?string $type,
    ?string $methodName = ''
): string {
    $value = strtolower(trim((string)$type));
    $name = strtolower(trim((string)$methodName));

    $aliases = [
        'cash' => 'cash',
        'cash payment' => 'cash',
        'upi' => 'upi',
        'gpay' => 'upi',
        'google pay' => 'upi',
        'phonepe' => 'upi',
        'paytm' => 'upi',
        'qr' => 'upi',
        'card' => 'card',
        'credit card' => 'card',
        'debit card' => 'card',
        'pos' => 'card',
        'cheque' => 'cheque',
        'check' => 'cheque',
        'credit' => 'credit',
        'credit sale' => 'credit',
        'split' => 'split',
        'split payment' => 'split',
        'mixed' => 'split',
        'other' => 'other',
    ];

    if (isset($aliases[$value])) {
        return $aliases[$value];
    }

    foreach ($aliases as $needle => $normalized) {
        if (
            $value !== '' &&
            str_contains($value, $needle)
        ) {
            return $normalized;
        }
    }

    foreach ($aliases as $needle => $normalized) {
        if (
            $name !== '' &&
            str_contains($name, $needle)
        ) {
            return $normalized;
        }
    }

    return 'other';
}

$idColumn = firstExistingColumn(
    $conn,
    'payment_methods',
    ['payment_method_id', 'id', 'method_id']
);

$nameColumn = firstExistingColumn(
    $conn,
    'payment_methods',
    ['payment_method_name', 'method_name', 'name']
);

$typeColumn = firstExistingColumn(
    $conn,
    'payment_methods',
    ['method_type', 'payment_type', 'type']
);

$statusColumn = firstExistingColumn(
    $conn,
    'payment_methods',
    ['status', 'is_active', 'active']
);

$businessColumn = hasColumn(
    $conn,
    'payment_methods',
    'business_id'
) ? 'business_id' : '';

$createdAtColumn = firstExistingColumn(
    $conn,
    'payment_methods',
    ['created_at', 'date_created']
);

if ($idColumn === '' || $nameColumn === '') {
    respond(
        false,
        'The payment_methods table has no supported ID or name column.',
        [],
        500
    );
}

$action = strtolower(
    trim((string)($_REQUEST['action'] ?? 'list'))
);

if ($action === 'list') {
    $search = trim((string)($_GET['search'] ?? ''));
    $statusFilter = strtolower(
        trim((string)($_GET['status_filter'] ?? 'all'))
    );

    $selectParts = [
        "`{$idColumn}` AS payment_method_id",
        "`{$nameColumn}` AS payment_method_name",
        $typeColumn !== ''
            ? "`{$typeColumn}` AS method_type"
            : "'other' AS method_type",
        $statusColumn !== ''
            ? "`{$statusColumn}` AS status"
            : "1 AS status",
        $businessColumn !== ''
            ? "`{$businessColumn}` AS business_id"
            : "{$businessId} AS business_id",
        $createdAtColumn !== ''
            ? "`{$createdAtColumn}` AS created_at"
            : "NULL AS created_at",
    ];

    $sql = "SELECT " . implode(', ', $selectParts) .
        " FROM payment_methods WHERE 1=1";

    $types = '';
    $params = [];

    if ($businessColumn !== '') {
        $sql .= " AND (`{$businessColumn}` = ? OR `{$businessColumn}` IS NULL)";
        $types .= 'i';
        $params[] = $businessId;
    }

    if ($search !== '') {
        $sql .= " AND `{$nameColumn}` LIKE ?";
        $types .= 's';
        $params[] = '%' . $search . '%';
    }

    if ($statusColumn !== '') {
        if ($statusFilter === 'active') {
            $sql .= " AND COALESCE(`{$statusColumn}`,1) = 1";
        } elseif ($statusFilter === 'inactive') {
            $sql .= " AND COALESCE(`{$statusColumn}`,1) = 0";
        }
    }

    $sql .= " ORDER BY `{$idColumn}` ASC";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        respond(
            false,
            'Unable to prepare payment-method list: ' . $conn->error,
            [],
            500
        );
    }

    bindDynamic($stmt, $types, $params);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();

        respond(
            false,
            'Unable to load payment methods: ' . $error,
            [],
            500
        );
    }

    $result = $stmt->get_result();
    $rows = [];

    while ($result && $row = $result->fetch_assoc()) {
        $row['payment_method_id'] =
            (int)$row['payment_method_id'];

        $row['business_id'] =
            (int)($row['business_id'] ?? $businessId);

        $row['payment_method_name'] =
            (string)($row['payment_method_name'] ?? '');

        $row['method_type'] =
            normalizePaymentMethodType(
                (string)($row['method_type'] ?? ''),
                (string)($row['payment_method_name'] ?? '')
            );

        $row['status'] =
            (int)($row['status'] ?? 1);

        $rows[] = $row;
    }

    $stmt->close();

    respond(
        true,
        'Payment methods loaded successfully.',
        ['rows' => $rows]
    );
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Invalid request method.', [], 405);
}

$csrfToken = (string)($_POST['csrf_token'] ?? '');
$sessionToken = (string)(
    $_SESSION['payment_methods_csrf'] ?? ''
);

if (
    $csrfToken === '' ||
    $sessionToken === '' ||
    !hash_equals($sessionToken, $csrfToken)
) {
    respond(
        false,
        'Invalid security token. Refresh the page and try again.',
        [],
        419
    );
}

if ($action === 'save') {
    $paymentMethodId =
        (int)($_POST['payment_method_id'] ?? 0);

    $permissionAction =
        $paymentMethodId > 0 ? 'edit' : 'create';

    if (
        !canAccessPaymentMethods(
            $conn,
            $permissionAction
        )
    ) {
        respond(
            false,
            $paymentMethodId > 0
                ? 'You do not have permission to edit payment methods.'
                : 'You do not have permission to create payment methods.',
            [],
            403
        );
    }

    $methodName = trim(
        (string)($_POST['payment_method_name'] ?? '')
    );

    $methodType = normalizePaymentMethodType(
        (string)($_POST['method_type'] ?? ''),
        $methodName
    );

    $status = isset($_POST['status']) ? 1 : 0;

    $allowedTypes = [
        'cash',
        'upi',
        'card',
        'cheque',
        'credit',
        'split',
        'other',
    ];

    if ($methodName === '') {
        respond(
            false,
            'Payment method name is required.',
            [],
            422
        );
    }

    if (mb_strlen($methodName) > 100) {
        respond(
            false,
            'Payment method name cannot exceed 100 characters.',
            [],
            422
        );
    }

    if (!in_array($methodType, $allowedTypes, true)) {
        $methodType = 'other';
    }

    $duplicateSql =
        "SELECT `{$idColumn}` AS id
         FROM payment_methods
         WHERE LOWER(TRIM(`{$nameColumn}`)) =
               LOWER(TRIM(?))
           AND `{$idColumn}` <> ?";

    $duplicateTypes = 'si';
    $duplicateParams = [
        $methodName,
        $paymentMethodId,
    ];

    if ($businessColumn !== '') {
        $duplicateSql .=
            " AND (`{$businessColumn}` = ? OR `{$businessColumn}` IS NULL)";

        $duplicateTypes .= 'i';
        $duplicateParams[] = $businessId;
    }

    $duplicateSql .= ' LIMIT 1';

    $stmt = $conn->prepare($duplicateSql);

    if (!$stmt) {
        respond(
            false,
            'Unable to validate payment method: ' . $conn->error,
            [],
            500
        );
    }

    bindDynamic(
        $stmt,
        $duplicateTypes,
        $duplicateParams
    );

    $stmt->execute();
    $duplicate =
        $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($duplicate) {
        respond(
            false,
            'Payment method already exists.',
            [],
            422
        );
    }

    if ($paymentMethodId > 0) {
        $oldSelect = [
            "`{$idColumn}` AS payment_method_id",
            "`{$nameColumn}` AS payment_method_name",
            $typeColumn !== ''
                ? "`{$typeColumn}` AS method_type"
                : "'other' AS method_type",
            $statusColumn !== ''
                ? "`{$statusColumn}` AS status"
                : "1 AS status",
        ];

        $oldSql =
            "SELECT " . implode(', ', $oldSelect) .
            " FROM payment_methods
              WHERE `{$idColumn}` = ?";

        $oldTypes = 'i';
        $oldParams = [$paymentMethodId];

        if ($businessColumn !== '') {
            $oldSql .=
                " AND (`{$businessColumn}` = ? OR `{$businessColumn}` IS NULL)";

            $oldTypes .= 'i';
            $oldParams[] = $businessId;
        }

        $oldSql .= ' LIMIT 1';

        $stmt = $conn->prepare($oldSql);

        if (!$stmt) {
            respond(
                false,
                'Unable to load payment method: ' . $conn->error,
                [],
                500
            );
        }

        bindDynamic($stmt, $oldTypes, $oldParams);
        $stmt->execute();
        $oldRow =
            $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$oldRow) {
            respond(
                false,
                'Payment method was not found.',
                [],
                404
            );
        }

        $oldRow['method_type'] =
            normalizePaymentMethodType(
                (string)($oldRow['method_type'] ?? ''),
                (string)($oldRow['payment_method_name'] ?? '')
            );

        $sets = ["`{$nameColumn}` = ?"];
        $updateTypes = 's';
        $updateParams = [$methodName];

        if ($typeColumn !== '') {
            $sets[] = "`{$typeColumn}` = ?";
            $updateTypes .= 's';
            $updateParams[] = $methodType;
        }

        if ($statusColumn !== '') {
            $sets[] = "`{$statusColumn}` = ?";
            $updateTypes .= 'i';
            $updateParams[] = $status;
        }

        $updateSql =
            "UPDATE payment_methods SET " .
            implode(', ', $sets) .
            " WHERE `{$idColumn}` = ?";

        $updateTypes .= 'i';
        $updateParams[] = $paymentMethodId;

        if ($businessColumn !== '') {
            $updateSql .=
                " AND (`{$businessColumn}` = ? OR `{$businessColumn}` IS NULL)";

            $updateTypes .= 'i';
            $updateParams[] = $businessId;
        }

        $stmt = $conn->prepare($updateSql);

        if (!$stmt) {
            respond(
                false,
                'Unable to prepare payment method update: ' .
                    $conn->error,
                [],
                500
            );
        }

        bindDynamic(
            $stmt,
            $updateTypes,
            $updateParams
        );

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();

            respond(
                false,
                'Unable to update payment method: ' . $error,
                [],
                500
            );
        }

        $stmt->close();

        addActivityLog(
            $conn,
            $businessId,
            $branchId,
            $userId,
            'Update',
            $paymentMethodId,
            $oldRow,
            [
                'payment_method_name' => $methodName,
                'method_type' => $methodType,
                'status' => $status,
            ]
        );

        respond(
            true,
            'Payment method updated successfully.',
            ['payment_method_id' => $paymentMethodId]
        );
    }

    $fields = [];
    $placeholders = [];
    $insertTypes = '';
    $insertParams = [];

    if ($businessColumn !== '') {
        $fields[] = "`{$businessColumn}`";
        $placeholders[] = '?';
        $insertTypes .= 'i';
        $insertParams[] = $businessId;
    }

    $fields[] = "`{$nameColumn}`";
    $placeholders[] = '?';
    $insertTypes .= 's';
    $insertParams[] = $methodName;

    if ($typeColumn !== '') {
        $fields[] = "`{$typeColumn}`";
        $placeholders[] = '?';
        $insertTypes .= 's';
        $insertParams[] = $methodType;
    }

    if ($statusColumn !== '') {
        $fields[] = "`{$statusColumn}`";
        $placeholders[] = '?';
        $insertTypes .= 'i';
        $insertParams[] = $status;
    }

    if ($createdAtColumn !== '') {
        $fields[] = "`{$createdAtColumn}`";
        $placeholders[] = 'NOW()';
    }

    $insertSql =
        "INSERT INTO payment_methods (" .
        implode(', ', $fields) .
        ') VALUES (' .
        implode(', ', $placeholders) .
        ')';

    $stmt = $conn->prepare($insertSql);

    if (!$stmt) {
        respond(
            false,
            'Unable to prepare payment method insert: ' .
                $conn->error,
            [],
            500
        );
    }

    bindDynamic(
        $stmt,
        $insertTypes,
        $insertParams
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();

        respond(
            false,
            'Unable to create payment method: ' . $error,
            [],
            500
        );
    }

    $newId = (int)$stmt->insert_id;
    $stmt->close();

    addActivityLog(
        $conn,
        $businessId,
        $branchId,
        $userId,
        'Create',
        $newId,
        null,
        [
            'payment_method_name' => $methodName,
            'method_type' => $methodType,
            'status' => $status,
        ]
    );

    respond(
        true,
        'Payment method created successfully.',
        ['payment_method_id' => $newId]
    );
}

$paymentMethodId =
    (int)($_POST['payment_method_id'] ?? 0);

if ($paymentMethodId <= 0) {
    respond(
        false,
        'Invalid payment method.',
        [],
        422
    );
}

$validateSelect = [
    "`{$idColumn}` AS payment_method_id",
    "`{$nameColumn}` AS payment_method_name",
    $typeColumn !== ''
        ? "`{$typeColumn}` AS method_type"
        : "'other' AS method_type",
    $statusColumn !== ''
        ? "`{$statusColumn}` AS status"
        : "1 AS status",
];

$validateSql =
    "SELECT " . implode(', ', $validateSelect) .
    " FROM payment_methods
      WHERE `{$idColumn}` = ?";

$validateTypes = 'i';
$validateParams = [$paymentMethodId];

if ($businessColumn !== '') {
    $validateSql .=
        " AND (`{$businessColumn}` = ? OR `{$businessColumn}` IS NULL)";

    $validateTypes .= 'i';
    $validateParams[] = $businessId;
}

$validateSql .= ' LIMIT 1';

$stmt = $conn->prepare($validateSql);

if (!$stmt) {
    respond(
        false,
        'Unable to validate payment method: ' . $conn->error,
        [],
        500
    );
}

bindDynamic(
    $stmt,
    $validateTypes,
    $validateParams
);

$stmt->execute();
$method =
    $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$method) {
    respond(
        false,
        'Payment method was not found.',
        [],
        404
    );
}

$method['method_type'] =
    normalizePaymentMethodType(
        (string)($method['method_type'] ?? ''),
        (string)($method['payment_method_name'] ?? '')
    );

if ($action === 'toggle') {
    if (!canAccessPaymentMethods($conn, 'edit')) {
        respond(
            false,
            'You do not have permission to change payment method status.',
            [],
            403
        );
    }

    if ($statusColumn === '') {
        respond(
            false,
            'This payment-method table has no active/status column.',
            [],
            422
        );
    }

    $newStatus =
        (int)($method['status'] ?? 1) === 1
            ? 0
            : 1;

    $updateSql =
        "UPDATE payment_methods
         SET `{$statusColumn}` = ?
         WHERE `{$idColumn}` = ?";

    $updateTypes = 'ii';
    $updateParams = [
        $newStatus,
        $paymentMethodId,
    ];

    if ($businessColumn !== '') {
        $updateSql .=
            " AND (`{$businessColumn}` = ? OR `{$businessColumn}` IS NULL)";

        $updateTypes .= 'i';
        $updateParams[] = $businessId;
    }

    $stmt = $conn->prepare($updateSql);

    if (!$stmt) {
        respond(
            false,
            'Unable to prepare status update: ' .
                $conn->error,
            [],
            500
        );
    }

    bindDynamic(
        $stmt,
        $updateTypes,
        $updateParams
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();

        respond(
            false,
            'Unable to change payment method status: ' .
                $error,
            [],
            500
        );
    }

    $stmt->close();

    addActivityLog(
        $conn,
        $businessId,
        $branchId,
        $userId,
        'Status Change',
        $paymentMethodId,
        ['status' => (int)$method['status']],
        ['status' => $newStatus]
    );

    respond(
        true,
        'Payment method status changed successfully.'
    );
}

if ($action === 'delete') {
    if (!canAccessPaymentMethods($conn, 'delete')) {
        respond(
            false,
            'You do not have permission to delete payment methods.',
            [],
            403
        );
    }

    $deleteSql =
        "DELETE FROM payment_methods
         WHERE `{$idColumn}` = ?";

    $deleteTypes = 'i';
    $deleteParams = [$paymentMethodId];

    if ($businessColumn !== '') {
        $deleteSql .=
            " AND (`{$businessColumn}` = ? OR `{$businessColumn}` IS NULL)";

        $deleteTypes .= 'i';
        $deleteParams[] = $businessId;
    }

    $stmt = $conn->prepare($deleteSql);

    if (!$stmt) {
        respond(
            false,
            'Unable to prepare payment method deletion: ' .
                $conn->error,
            [],
            500
        );
    }

    bindDynamic(
        $stmt,
        $deleteTypes,
        $deleteParams
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $errno = $stmt->errno;
        $stmt->close();

        if (
            $errno === 1451 ||
            str_contains(
                strtolower($error),
                'foreign key'
            )
        ) {
            respond(
                false,
                'This payment method is already used in transactions and cannot be deleted. Deactivate it instead.',
                [],
                409
            );
        }

        respond(
            false,
            'Unable to delete payment method: ' .
                $error,
            [],
            500
        );
    }

    $stmt->close();

    addActivityLog(
        $conn,
        $businessId,
        $branchId,
        $userId,
        'Delete',
        $paymentMethodId,
        $method,
        null
    );

    respond(
        true,
        'Payment method deleted successfully.'
    );
}

respond(false, 'Invalid action.', [], 400);
