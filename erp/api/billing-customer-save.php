<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function jsonResponse(bool $success, string $message, array $extra = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode(
        array_merge(['success' => $success, 'message' => $message], $extra),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

try {
    $configLoaded = false;
    $baseDir = dirname(__DIR__);
    $configCandidates = [
        $baseDir . '/config/config.php',
        $baseDir . '/config.php',
        $baseDir . '/includes/config.php',
        $baseDir . '/super-admin/includes/config.php',
    ];

    foreach ($configCandidates as $configFile) {
        if (is_file($configFile)) {
            require_once $configFile;
            $configLoaded = true;
            break;
        }
    }

    if (!$configLoaded || !isset($conn) || !($conn instanceof mysqli)) {
        jsonResponse(false, 'Database configuration is not available.', [], 500);
    }

    mysqli_report(MYSQLI_REPORT_OFF);
    $conn->set_charset('utf8mb4');

    if (empty($_SESSION['user_id'])) {
        jsonResponse(false, 'Your session has expired. Please log in again.', [], 401);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        jsonResponse(false, 'Invalid request method.', [], 405);
    }

    $sessionToken = (string)($_SESSION['billing_csrf'] ?? '');
    $requestToken = (string)($_POST['csrf_token'] ?? '');
    if ($sessionToken === '' || $requestToken === '' || !hash_equals($sessionToken, $requestToken)) {
        jsonResponse(false, 'Invalid or expired request token. Refresh the billing page.', [], 419);
    }

    $businessId = (int)($_SESSION['business_id'] ?? 0);
    $branchId = (int)($_SESSION['branch_id'] ?? ($_SESSION['default_branch_id'] ?? 0));
    $userId = (int)($_SESSION['user_id'] ?? 0);

    if ($businessId <= 0 || $branchId <= 0) {
        jsonResponse(false, 'A valid business and branch must be selected.', [], 403);
    }

    function tableExistsBillingCustomer(mysqli $conn, string $table): bool
    {
        $escaped = $conn->real_escape_string($table);
        $result = $conn->query("SHOW TABLES LIKE '{$escaped}'");
        return $result && $result->num_rows > 0;
    }

    function canCreateBillingCustomer(mysqli $conn): bool
    {
        if (($_SESSION['user_type'] ?? '') === 'Platform Admin') {
            return true;
        }

        foreach (['perm.billing.create', 'perm.billing', 'perm.customers'] as $permissionCode) {
            if (isset($_SESSION['permissions'][$permissionCode]['can_create'])) {
                return (int)$_SESSION['permissions'][$permissionCode]['can_create'] === 1;
            }
        }

        $businessId = (int)($_SESSION['business_id'] ?? 0);
        $roleId = (int)($_SESSION['role_id'] ?? 0);
        if ($businessId <= 0 || $roleId <= 0) {
            return false;
        }

        $sql = "SELECT MAX(rp.can_create) AS allowed
                FROM role_permissions rp
                INNER JOIN permissions p ON p.id = rp.permission_id
                WHERE rp.business_id = ?
                  AND rp.role_id = ?
                  AND p.is_active = 1
                  AND p.permission_code IN ('perm.billing.create','perm.billing','perm.customers')";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ii', $businessId, $roleId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($row['allowed'] ?? 0) === 1;
    }

    if (!canCreateBillingCustomer($conn)) {
        jsonResponse(false, 'You do not have permission to create customers.', [], 403);
    }

    $name = trim((string)($_POST['customer_name'] ?? ''));
    $mobile = preg_replace('/\s+/', '', trim((string)($_POST['mobile'] ?? '')));
    $email = trim((string)($_POST['email'] ?? ''));
    $gstin = strtoupper(trim((string)($_POST['gstin'] ?? '')));
    $address = trim((string)($_POST['address_line1'] ?? ''));
    $pincode = trim((string)($_POST['pincode'] ?? ''));

    if ($name === '') {
        jsonResponse(false, 'Customer name is required.', [], 422);
    }
    if ($mobile === '') {
        jsonResponse(false, 'Mobile number is required.', [], 422);
    }
    if (!preg_match('/^[0-9+\-]{7,20}$/', $mobile)) {
        jsonResponse(false, 'Enter a valid mobile number.', [], 422);
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(false, 'Enter a valid email address.', [], 422);
    }

    $duplicate = $conn->prepare('SELECT id, customer_code, customer_name, mobile FROM customers WHERE business_id = ? AND mobile = ? LIMIT 1');
    if (!$duplicate) {
        jsonResponse(false, 'Unable to validate customer details.', [], 500);
    }
    $duplicate->bind_param('is', $businessId, $mobile);
    $duplicate->execute();
    $existing = $duplicate->get_result()->fetch_assoc();
    $duplicate->close();

    if ($existing) {
        jsonResponse(false, 'A customer with this mobile number already exists.', [
            'existing_customer' => [
                'id' => (int)$existing['id'],
                'customer_code' => (string)$existing['customer_code'],
                'customer_name' => (string)$existing['customer_name'],
                'mobile' => (string)$existing['mobile'],
            ],
        ], 409);
    }

    $conn->begin_transaction();

    $sequenceStmt = $conn->prepare('SELECT COALESCE(MAX(id), 0) + 1 AS next_no FROM customers WHERE business_id = ? FOR UPDATE');
    if (!$sequenceStmt) {
        throw new RuntimeException('Unable to generate customer number.');
    }
    $sequenceStmt->bind_param('i', $businessId);
    $sequenceStmt->execute();
    $sequenceRow = $sequenceStmt->get_result()->fetch_assoc();
    $sequenceStmt->close();

    $nextNumber = max(1, (int)($sequenceRow['next_no'] ?? 1));
    $customerCode = 'CUS' . date('ym') . str_pad((string)$nextNumber, 4, '0', STR_PAD_LEFT);
    $notes = 'Customer Category: billing';

    $insert = $conn->prepare(
        'INSERT INTO customers
        (business_id, home_branch_id, customer_code, customer_name, mobile, email, gstin, address_line1, pincode, notes, is_active)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
    );
    if (!$insert) {
        throw new RuntimeException('Unable to prepare customer creation.');
    }
    $insert->bind_param(
        'iissssssss',
        $businessId,
        $branchId,
        $customerCode,
        $name,
        $mobile,
        $email,
        $gstin,
        $address,
        $pincode,
        $notes
    );
    if (!$insert->execute()) {
        throw new RuntimeException($insert->error ?: 'Unable to create customer.');
    }
    $customerId = (int)$insert->insert_id;
    $insert->close();

    if (tableExistsBillingCustomer($conn, 'customer_services')) {
        $service = $conn->prepare(
            "INSERT INTO customer_services
             (business_id, customer_id, service_type, joined_at, is_active, created_by)
             VALUES (?, ?, 'Billing', NOW(), 1, ?)"
        );
        if (!$service) {
            throw new RuntimeException('Unable to prepare Billing service assignment.');
        }
        $service->bind_param('iii', $businessId, $customerId, $userId);
        if (!$service->execute()) {
            throw new RuntimeException($service->error ?: 'Unable to assign Billing service.');
        }
        $service->close();
    }

    if (tableExistsBillingCustomer($conn, 'audit_logs')) {
        $moduleCode = 'customers';
        $actionType = 'Create';
        $referenceTable = 'customers';
        $description = 'Created billing customer ' . $name;
        $newValues = json_encode([
            'customer_code' => $customerCode,
            'customer_name' => $name,
            'mobile' => $mobile,
            'service_type' => 'Billing',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ipAddress = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $userAgent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

        $audit = $conn->prepare(
            'INSERT INTO audit_logs
             (business_id, branch_id, user_id, module_code, action_type, reference_table, reference_id, description, new_values_json, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if ($audit) {
            $audit->bind_param(
                'iiisssissss',
                $businessId,
                $branchId,
                $userId,
                $moduleCode,
                $actionType,
                $referenceTable,
                $customerId,
                $description,
                $newValues,
                $ipAddress,
                $userAgent
            );
            $audit->execute();
            $audit->close();
        }
    }

    $conn->commit();

    jsonResponse(true, 'Billing customer created successfully.', [
        'customer' => [
            'id' => $customerId,
            'customer_code' => $customerCode,
            'customer_name' => $name,
            'mobile' => $mobile,
            'email' => $email,
            'gstin' => $gstin,
            'service_type' => 'Billing',
        ],
    ]);
} catch (Throwable $exception) {
    if (isset($conn) && $conn instanceof mysqli) {
        try {
            $conn->rollback();
        } catch (Throwable $ignored) {
        }
    }
    jsonResponse(false, 'Unable to create customer: ' . $exception->getMessage(), [], 500);
}