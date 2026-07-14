<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));

header('X-Content-Type-Options: nosniff');

$configCandidates = [
    dirname(__DIR__) . '/config/config.php',
    dirname(__DIR__) . '/config.php',
    dirname(__DIR__) . '/includes/config.php',
    dirname(__DIR__) . '/super-admin/includes/config.php',
];

foreach ($configCandidates as $configFile) {
    if (is_file($configFile)) {
        require_once $configFile;
        break;
    }
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit('Database configuration is not available.');
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn->set_charset('utf8mb4');

function customerApiTableExists(mysqli $conn, string $table): bool
{
    $safe = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $result && $result->num_rows > 0;
}

function customerApiHasColumn(mysqli $conn, string $table, string $column): bool
{
    $safeTable = $conn->real_escape_string($table);
    $safeColumn = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    return $result && $result->num_rows > 0;
}

function customerApiNormaliseType(string $type): string
{
    return in_array($type, ['billing', 'pawn', 'chit'], true) ? $type : 'billing';
}

function customerApiDatabaseType(string $type): string
{
    if ($type === 'pawn') {
        return 'Pawn';
    }
    if ($type === 'chit') {
        return 'Chit';
    }
    return 'Billing';
}

function customerApiListUrl(string $type, array $extra = []): string
{
    $query = array_merge(['type' => customerApiNormaliseType($type)], $extra);
    return '../customers.php?' . http_build_query($query);
}

function customerApiFormUrl(string $page, string $type, int $customerId = 0): string
{
    $query = ['type' => customerApiNormaliseType($type)];
    if ($customerId > 0) {
        $query['id'] = $customerId;
    }
    return '../' . $page . '?' . http_build_query($query);
}

function customerApiRedirectForm(
    string $page,
    string $type,
    string $message,
    string $messageType = 'danger',
    array $old = [],
    int $customerId = 0
): void {
    $_SESSION['customer_form_flash'] = [
        'message' => $message,
        'type' => $messageType,
    ];
    $_SESSION['customer_form_old'] = $old;

    header('Location: ' . customerApiFormUrl($page, $type, $customerId));
    exit;
}

function customerApiRedirectList(string $type, string $message, string $messageType = 'danger', string $msgCode = ''): void
{
    $_SESSION['customers_flash'] = [
        'message' => $message,
        'type' => $messageType,
    ];

    $extra = [];
    if ($msgCode !== '') {
        $extra['msg'] = $msgCode;
    }

    header('Location: ' . customerApiListUrl($type, $extra));
    exit;
}

function customerApiPermission(mysqli $conn, string $action): bool
{
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin') {
        return true;
    }

    $fieldMap = [
        'create' => 'can_create',
        'update' => 'can_update',
        'delete' => 'can_delete',
    ];

    $field = $fieldMap[$action] ?? '';
    if ($field === '') {
        return false;
    }

    $sessionPermissions = $_SESSION['permissions'] ?? [];
    foreach (['perm.customers.list', 'perm.customers', 'perm.customer'] as $permissionCode) {
        if (isset($sessionPermissions[$permissionCode][$field])) {
            return (int)$sessionPermissions[$permissionCode][$field] === 1;
        }
    }

    $roleName = strtolower(trim((string)($_SESSION['role_name'] ?? '')));
    $roleCode = strtolower(trim((string)($_SESSION['role_code'] ?? '')));
    if (
        in_array($roleName, ['admin', 'business admin', 'manager', 'billing'], true) ||
        in_array($roleCode, ['admin', 'business_admin', 'manager', 'billing'], true)
    ) {
        return true;
    }

    if (!customerApiTableExists($conn, 'role_permissions') || !customerApiTableExists($conn, 'permissions')) {
        return false;
    }

    $businessId = (int)($_SESSION['business_id'] ?? 0);
    $roleId = (int)($_SESSION['role_id'] ?? 0);
    if ($businessId <= 0 || $roleId <= 0) {
        return false;
    }

    $sql = "SELECT rp.`{$field}`
            FROM role_permissions rp
            INNER JOIN permissions p ON p.id = rp.permission_id
            WHERE rp.business_id = ?
              AND rp.role_id = ?
              AND p.is_active = 1
              AND p.permission_code IN ('perm.customers.list','perm.customers','perm.customer')
            ORDER BY FIELD(p.permission_code,'perm.customers.list','perm.customers','perm.customer')
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ii', $businessId, $roleId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row[$field] ?? 0) === 1;
}

function customerApiGenerateCode(mysqli $conn, int $businessId, string $type): string
{
    $prefix = $type === 'pawn' ? 'PWN' : ($type === 'chit' ? 'CHC' : 'CUST');
    $yearMonth = date('ym');
    $like = $prefix . $yearMonth . '%';
    $lastNumber = 0;

    $stmt = $conn->prepare(
        'SELECT customer_code
         FROM customers
         WHERE business_id = ? AND customer_code LIKE ?
         ORDER BY id DESC
         LIMIT 1'
    );

    if ($stmt) {
        $stmt->bind_param('is', $businessId, $like);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row && preg_match('/(\d{4})$/', (string)$row['customer_code'], $match)) {
            $lastNumber = (int)$match[1];
        }
    }

    return $prefix . $yearMonth . str_pad((string)($lastNumber + 1), 4, '0', STR_PAD_LEFT);
}

function customerApiAudit(
    mysqli $conn,
    int $businessId,
    int $branchId,
    int $userId,
    string $action,
    int $referenceId,
    string $description,
    ?array $oldValues = null,
    ?array $newValues = null
): void {
    if (!customerApiTableExists($conn, 'audit_logs')) {
        return;
    }

    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $userAgent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

    if (customerApiHasColumn($conn, 'audit_logs', 'module_code')) {
        $stmt = $conn->prepare(
            "INSERT INTO audit_logs
             (business_id, branch_id, user_id, module_code, action_type, reference_table,
              reference_id, description, old_values_json, new_values_json, ip_address, user_agent)
             VALUES (?, ?, ?, 'customers', ?, 'customers', ?, ?, ?, ?, ?, ?)"
        );

        if (!$stmt) {
            return;
        }

        $oldJson = $oldValues === null ? null : json_encode($oldValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $newJson = $newValues === null ? null : json_encode($newValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $stmt->bind_param(
            'iiisisssss',
            $businessId,
            $branchId,
            $userId,
            $action,
            $referenceId,
            $description,
            $oldJson,
            $newJson,
            $ip,
            $userAgent
        );
        $stmt->execute();
        $stmt->close();
        return;
    }

    if (customerApiHasColumn($conn, 'audit_logs', 'module_name')) {
        $stmt = $conn->prepare(
            "INSERT INTO audit_logs
             (business_id, user_id, module_name, action_type, reference_id, description,
              ip_address, user_agent, created_at)
             VALUES (?, ?, 'Customers', ?, ?, ?, ?, ?, NOW())"
        );

        if ($stmt) {
            $stmt->bind_param(
                'iisisss',
                $businessId,
                $userId,
                $action,
                $referenceId,
                $description,
                $ip,
                $userAgent
            );
            $stmt->execute();
            $stmt->close();
        }
    }
}

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    exit('Your session has expired. Please log in again.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Invalid request method.');
}

if (!hash_equals(
    (string)($_SESSION['customers_csrf'] ?? ''),
    (string)($_POST['csrf_token'] ?? '')
)) {
    http_response_code(419);
    exit('Invalid or expired request token. Refresh the page and try again.');
}

$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? $_SESSION['default_branch_id'] ?? 0);
$userId = (int)$_SESSION['user_id'];

if ($businessId <= 0) {
    http_response_code(403);
    exit('A valid business must be selected.');
}

if (!customerApiTableExists($conn, 'customers')) {
    http_response_code(500);
    exit('Customers table is not available.');
}

$action = trim((string)($_POST['action'] ?? ''));
$actionAliases = [
    'add_customer' => 'create',
    'create_customer' => 'create',
    'update_customer' => 'update',
    'status' => 'toggle_status',
    'toggle' => 'toggle_status',
];
$action = $actionAliases[$action] ?? $action;

$type = customerApiNormaliseType((string)($_POST['page_customer_type'] ?? $_POST['type'] ?? 'billing'));

if (in_array($action, ['create', 'update'], true)) {
    if (!customerApiPermission($conn, $action)) {
        customerApiRedirectForm(
            $action === 'create' ? 'customer-add.php' : 'customer-edit.php',
            $type,
            'You do not have permission to ' . $action . ' customers.',
            'danger',
            $_POST,
            (int)($_POST['customer_id'] ?? 0)
        );
    }

    $customerId = (int)($_POST['customer_id'] ?? 0);
    $customerName = trim((string)($_POST['customer_name'] ?? ''));
    $mobile = trim((string)($_POST['customer_contact'] ?? $_POST['mobile'] ?? ''));
    $alternateMobile = trim((string)($_POST['alternate_contact'] ?? $_POST['alternate_mobile'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $gstin = trim((string)($_POST['gst_number'] ?? $_POST['gstin'] ?? ''));
    $address = trim((string)($_POST['shop_location'] ?? $_POST['address_line1'] ?? ''));

    $errors = [];
    if ($customerName === '') {
        $errors[] = 'Customer name is required.';
    }
    if ($mobile === '') {
        $errors[] = 'Contact number is required.';
    } elseif (!preg_match('/^[0-9+\-\s]{6,20}$/', $mobile)) {
        $errors[] = 'Enter a valid contact number.';
    }
    if ($address === '') {
        $errors[] = 'Shop location/address is required.';
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    }
    if ($action === 'update' && $customerId <= 0) {
        $errors[] = 'Invalid customer ID.';
    }

    if (!$errors) {
        $duplicateSql = 'SELECT id FROM customers WHERE business_id = ? AND mobile = ?';
        if (customerApiHasColumn($conn, 'customers', 'is_active')) {
            $duplicateSql .= ' AND is_active = 1';
        }
        if ($action === 'update') {
            $duplicateSql .= ' AND id <> ?';
        }
        $duplicateSql .= ' LIMIT 1';

        $stmt = $conn->prepare($duplicateSql);
        if ($stmt) {
            if ($action === 'update') {
                $stmt->bind_param('isi', $businessId, $mobile, $customerId);
            } else {
                $stmt->bind_param('is', $businessId, $mobile);
            }
            $stmt->execute();
            $duplicate = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($duplicate) {
                $errors[] = $action === 'update'
                    ? 'Another customer with this contact number already exists.'
                    : 'Customer with this contact number already exists.';
            }
        }
    }

    if ($errors) {
        customerApiRedirectForm(
            $action === 'create' ? 'customer-add.php' : 'customer-edit.php',
            $type,
            implode(' ', $errors),
            'danger',
            $_POST,
            $customerId
        );
    }

    $extraNotes = [];
    $noteFields = [
        'shop_name' => 'Shop/Business Name',
        'customer_category' => 'Customer Category',
        'zone_id' => 'Zone ID',
        'assigned_area' => 'Assigned Area',
        'assigned_lineman_id' => 'Assigned Lineman ID',
        'payment_terms' => 'Payment Terms',
    ];

    foreach ($noteFields as $field => $label) {
        $value = trim((string)($_POST[$field] ?? ''));
        if ($value !== '' && $value !== '0') {
            $extraNotes[] = $label . ': ' . $value;
        }
    }

    $creditLimit = trim((string)($_POST['credit_limit'] ?? ''));
    if ($creditLimit !== '') {
        $extraNotes[] = 'Credit Limit: ₹' . $creditLimit;
    }

    $plainNotes = trim((string)($_POST['notes'] ?? ''));
    if ($plainNotes !== '') {
        $extraNotes[] = 'Notes: ' . $plainNotes;
    }

    $notes = implode("\n", $extraNotes);
    $databaseType = customerApiDatabaseType($type);

    $conn->begin_transaction();

    try {
        if ($action === 'create') {
            $customerCode = customerApiGenerateCode($conn, $businessId, $type);

            $creditLimitValue = max(0, (float)($_POST['credit_limit'] ?? 0));

            $stmt = $conn->prepare(
                "INSERT INTO customers
                 (business_id, home_branch_id, customer_code, customer_type, customer_name, mobile,
                  alternate_mobile, email, gstin, address_line1, credit_limit,
                  opening_balance, current_balance, notes, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0.00, 0.00, ?, 1)"
            );

            if (!$stmt) {
                throw new RuntimeException('Unable to prepare customer insert: ' . $conn->error);
            }

            $stmt->bind_param(
                'iissssssssds',
                $businessId,
                $branchId,
                $customerCode,
                $databaseType,
                $customerName,
                $mobile,
                $alternateMobile,
                $email,
                $gstin,
                $address,
                $creditLimitValue,
                $notes
            );

            if (!$stmt->execute()) {
                throw new RuntimeException('Unable to create customer: ' . $stmt->error);
            }

            $newCustomerId = (int)$stmt->insert_id;
            $stmt->close();

            customerApiAudit(
                $conn,
                $businessId,
                $branchId,
                $userId,
                'Create',
                $newCustomerId,
                'Created customer ' . $customerName . ' with code ' . $customerCode,
                null,
                [
                    'customer_code' => $customerCode,
                    'customer_type' => $databaseType,
                    'customer_name' => $customerName,
                    'mobile' => $mobile,
                ]
            );

            $conn->commit();
            unset($_SESSION['customer_form_old']);
            customerApiRedirectList(
                $type,
                'Customer added successfully. Customer Code: ' . $customerCode,
                'success',
                'created'
            );
        }

        $stmt = $conn->prepare('SELECT * FROM customers WHERE id = ? AND business_id = ? LIMIT 1 FOR UPDATE');
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare customer lookup: ' . $conn->error);
        }

        $stmt->bind_param('ii', $customerId, $businessId);
        $stmt->execute();
        $oldCustomer = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$oldCustomer) {
            throw new RuntimeException('Customer not found.');
        }

        $stmt = $conn->prepare(
            'UPDATE customers
             SET customer_type = ?, customer_name = ?, mobile = ?, alternate_mobile = ?,
                 email = ?, gstin = ?, address_line1 = ?, notes = ?
             WHERE id = ? AND business_id = ?
             LIMIT 1'
        );

        if (!$stmt) {
            throw new RuntimeException('Unable to prepare customer update: ' . $conn->error);
        }

        $stmt->bind_param(
            'ssssssssii',
            $databaseType,
            $customerName,
            $mobile,
            $alternateMobile,
            $email,
            $gstin,
            $address,
            $notes,
            $customerId,
            $businessId
        );

        if (!$stmt->execute()) {
            throw new RuntimeException('Unable to update customer: ' . $stmt->error);
        }
        $stmt->close();

        customerApiAudit(
            $conn,
            $businessId,
            $branchId,
            $userId,
            'Update',
            $customerId,
            'Updated customer ' . $customerName,
            $oldCustomer,
            [
                'customer_type' => $databaseType,
                'customer_name' => $customerName,
                'mobile' => $mobile,
                'alternate_mobile' => $alternateMobile,
                'email' => $email,
                'gstin' => $gstin,
                'address_line1' => $address,
                'notes' => $notes,
            ]
        );

        $conn->commit();
        unset($_SESSION['customer_form_old']);
        customerApiRedirectList($type, 'Customer updated successfully.', 'success', 'updated');
    } catch (Throwable $exception) {
        $conn->rollback();
        customerApiRedirectForm(
            $action === 'create' ? 'customer-add.php' : 'customer-edit.php',
            $type,
            $exception->getMessage(),
            'danger',
            $_POST,
            $customerId
        );
    }
}

$customerId = (int)($_POST['customer_id'] ?? 0);
if ($customerId <= 0) {
    customerApiRedirectList($type, 'Invalid customer.');
}

if ($action === 'delete') {
    if (!customerApiPermission($conn, 'delete')) {
        customerApiRedirectList($type, 'You do not have permission to delete customers.');
    }

    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare('SELECT * FROM customers WHERE id = ? AND business_id = ? LIMIT 1 FOR UPDATE');
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare customer lookup: ' . $conn->error);
        }

        $stmt->bind_param('ii', $customerId, $businessId);
        $stmt->execute();
        $customer = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$customer) {
            throw new RuntimeException('Customer not found.');
        }

        $stmt = $conn->prepare('DELETE FROM customers WHERE id = ? AND business_id = ? LIMIT 1');
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare customer delete: ' . $conn->error);
        }

        $stmt->bind_param('ii', $customerId, $businessId);
        if (!$stmt->execute()) {
            throw new RuntimeException('Unable to delete customer: ' . $stmt->error);
        }
        if ($stmt->affected_rows < 1) {
            throw new RuntimeException('Customer was not deleted.');
        }
        $stmt->close();

        customerApiAudit(
            $conn,
            $businessId,
            $branchId,
            $userId,
            'Delete',
            $customerId,
            'Deleted customer ' . (string)($customer['customer_name'] ?? ''),
            $customer,
            null
        );

        $conn->commit();
        customerApiRedirectList($type, 'Customer deleted successfully.', 'success', 'deleted');
    } catch (Throwable $exception) {
        $conn->rollback();
        customerApiRedirectList($type, $exception->getMessage());
    }
}

if ($action === 'toggle_status') {
    if (!customerApiPermission($conn, 'update')) {
        customerApiRedirectList($type, 'You do not have permission to change customer status.');
    }

    if (!customerApiHasColumn($conn, 'customers', 'is_active')) {
        customerApiRedirectList($type, 'Customer status column is not available.');
    }

    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare(
            'SELECT id, customer_name, is_active
             FROM customers
             WHERE id = ? AND business_id = ?
             LIMIT 1
             FOR UPDATE'
        );

        if (!$stmt) {
            throw new RuntimeException('Unable to prepare customer status lookup: ' . $conn->error);
        }

        $stmt->bind_param('ii', $customerId, $businessId);
        $stmt->execute();
        $customer = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$customer) {
            throw new RuntimeException('Customer not found.');
        }

        $oldStatus = (int)$customer['is_active'];
        $newStatus = $oldStatus === 1 ? 0 : 1;

        $updateSql = 'UPDATE customers SET is_active = ?';
        if (customerApiHasColumn($conn, 'customers', 'updated_at')) {
            $updateSql .= ', updated_at = NOW()';
        }
        $updateSql .= ' WHERE id = ? AND business_id = ? LIMIT 1';

        $stmt = $conn->prepare($updateSql);
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare customer status update: ' . $conn->error);
        }

        $stmt->bind_param('iii', $newStatus, $customerId, $businessId);
        if (!$stmt->execute()) {
            throw new RuntimeException('Unable to change customer status: ' . $stmt->error);
        }
        $stmt->close();

        customerApiAudit(
            $conn,
            $businessId,
            $branchId,
            $userId,
            'Update',
            $customerId,
            'Changed customer status for ' . (string)$customer['customer_name'],
            ['is_active' => $oldStatus],
            ['is_active' => $newStatus]
        );

        $conn->commit();
        customerApiRedirectList($type, 'Customer status changed successfully.', 'success', 'status_changed');
    } catch (Throwable $exception) {
        $conn->rollback();
        customerApiRedirectList($type, $exception->getMessage());
    }
}

customerApiRedirectList($type, 'Invalid action.');
