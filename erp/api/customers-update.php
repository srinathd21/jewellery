<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string) ($_SESSION['timezone'] ?? 'Asia/Kolkata'));

foreach ([
    dirname(__DIR__) . '/config/config.php',
    dirname(__DIR__) . '/config.php',
    dirname(__DIR__) . '/includes/config.php',
    dirname(__DIR__) . '/super-admin/includes/config.php'
] as $file) {
    if (is_file($file)) {
        require_once $file;
        break;
    }
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit('Database configuration is not available.');
}

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

function redirectEdit(int $customerId, string $message, array $old = []): void
{
    $_SESSION['customer_form_flash'] = [
        'message' => $message,
        'type' => 'danger'
    ];
    $_SESSION['customer_form_old'] = $old;
    header('Location: ../customer-edit.php?id=' . $customerId);
    exit;
}

function customerPermission(mysqli $conn, string $action): bool
{
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin') {
        return true;
    }

    $map = [
        'update' => 'can_update'
    ];
    $field = $map[$action] ?? '';
    if ($field === '') {
        return false;
    }

    foreach (['perm.customer.add', 'perm.customers', 'perm.customer'] as $code) {
        if (isset($_SESSION['permissions'][$code][$field])) {
            return (int) $_SESSION['permissions'][$code][$field] === 1;
        }
    }

    $businessId = (int) ($_SESSION['business_id'] ?? 0);
    $roleId = (int) ($_SESSION['role_id'] ?? 0);
    if ($businessId <= 0 || $roleId <= 0) {
        return false;
    }

    $sql = "SELECT rp.`{$field}`
            FROM role_permissions rp
            INNER JOIN permissions p ON p.id = rp.permission_id
            WHERE rp.business_id = ?
              AND rp.role_id = ?
              AND p.is_active = 1
              AND p.permission_code IN ('perm.customer.add','perm.customers','perm.customer')
            ORDER BY FIELD(p.permission_code,'perm.customer.add','perm.customers','perm.customer')
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ii', $businessId, $roleId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int) ($row[$field] ?? 0) === 1;
}

function normalisePaymentTermsInNotes(string $notes, string $paymentTerms, bool $billingSelected): string
{
    $notes = preg_replace('/(?:^|\R)Payment Terms:\s*[^\r\n]*/i', '', $notes) ?? $notes;
    $notes = trim(preg_replace('/\R{3,}/', "\n\n", $notes) ?? $notes);

    if ($billingSelected && $paymentTerms !== '') {
        $notes = trim($notes . "\nPayment Terms: " . $paymentTerms);
    }

    return $notes;
}

function writeAudit(
    mysqli $conn,
    int $businessId,
    int $branchId,
    int $userId,
    int $customerId,
    string $description,
    array $oldValues,
    array $newValues
): void {
    if (!tableExists($conn, 'audit_logs')) {
        return;
    }

    $oldJson = json_encode($oldValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $newJson = json_encode($newValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');

    if (hasColumn($conn, 'audit_logs', 'module_code')) {
        $stmt = $conn->prepare(
            "INSERT INTO audit_logs
            (business_id, branch_id, user_id, module_code, action_type, reference_table,
             reference_id, description, old_values_json, new_values_json, ip_address, user_agent)
             VALUES (?, ?, ?, 'customers', 'Update', 'customers', ?, ?, ?, ?, ?, ?)"
        );

        if ($stmt) {
            $stmt->bind_param(
                'iiiisssss',
                $businessId,
                $branchId,
                $userId,
                $customerId,
                $description,
                $oldJson,
                $newJson,
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
    exit('Session expired.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Invalid request method.');
}

$customerId = (int) ($_POST['customer_id'] ?? 0);
if ($customerId <= 0) {
    http_response_code(400);
    exit('Invalid customer ID.');
}

if (!hash_equals(
    (string) ($_SESSION['customers_csrf'] ?? ''),
    (string) ($_POST['csrf_token'] ?? '')
)) {
    http_response_code(419);
    exit('Invalid or expired request token.');
}

if (($_POST['action'] ?? '') !== 'update') {
    redirectEdit($customerId, 'Invalid action.', $_POST);
}

if (!customerPermission($conn, 'update')) {
    redirectEdit($customerId, 'You do not have permission to update customers.', $_POST);
}

if (!tableExists($conn, 'customer_services')) {
    redirectEdit($customerId, 'customer_services table is missing. Import the SQL migration first.', $_POST);
}

$businessId = (int) ($_SESSION['business_id'] ?? 0);
$branchId = (int) ($_SESSION['branch_id'] ?? ($_SESSION['default_branch_id'] ?? 0));
$userId = (int) $_SESSION['user_id'];

if ($businessId <= 0 || $branchId <= 0) {
    redirectEdit($customerId, 'A valid business and branch must be selected.', $_POST);
}

$name = trim((string) ($_POST['customer_name'] ?? ''));
$mobile = trim((string) ($_POST['mobile'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));

$servicesInput = $_POST['services'] ?? [];
$allowedServices = ['Billing', 'Pawn', 'Chit'];
$services = array_values(array_unique(array_intersect(
    $allowedServices,
    is_array($servicesInput) ? $servicesInput : [$servicesInput]
)));

$errors = [];
if ($name === '') {
    $errors[] = 'Customer name is required.';
}
if ($mobile === '') {
    $errors[] = 'Mobile number is required.';
} elseif (!preg_match('/^[0-9+\-\s]{6,20}$/', $mobile)) {
    $errors[] = 'Enter a valid mobile number.';
}
if (!$services) {
    $errors[] = 'Select at least one service.';
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Enter a valid email address.';
}

$stmt = $conn->prepare(
    'SELECT id FROM customers
     WHERE business_id = ? AND mobile = ? AND is_active = 1 AND id <> ?
     LIMIT 1'
);
if ($stmt) {
    $stmt->bind_param('isi', $businessId, $mobile, $customerId);
    $stmt->execute();
    if ($stmt->get_result()->fetch_assoc()) {
        $errors[] = 'Another active customer with this mobile number already exists.';
    }
    $stmt->close();
}

if ($errors) {
    redirectEdit($customerId, implode(' ', $errors), $_POST);
}

$alternateMobile = trim((string) ($_POST['alternate_mobile'] ?? ''));
$gstin = strtoupper(trim((string) ($_POST['gstin'] ?? '')));
$panNo = strtoupper(trim((string) ($_POST['pan_no'] ?? '')));
$dateOfBirth = trim((string) ($_POST['date_of_birth'] ?? '')) ?: null;
$anniversaryDate = trim((string) ($_POST['anniversary_date'] ?? '')) ?: null;
$addressLine1 = trim((string) ($_POST['address_line1'] ?? ''));
$addressLine2 = trim((string) ($_POST['address_line2'] ?? ''));
$city = trim((string) ($_POST['city'] ?? ''));
$state = trim((string) ($_POST['state'] ?? ''));
$pincode = trim((string) ($_POST['pincode'] ?? ''));
$creditLimit = max(0, (float) ($_POST['credit_limit'] ?? 0));
$openingBalance = (float) ($_POST['opening_balance'] ?? 0);
$isActive = (int) ($_POST['is_active'] ?? 1) === 1 ? 1 : 0;
$paymentTerms = trim((string) ($_POST['payment_terms'] ?? ''));
$notes = trim((string) ($_POST['notes'] ?? ''));
$notes = normalisePaymentTermsInNotes(
    $notes,
    $paymentTerms,
    in_array('Billing', $services, true)
);

$conn->begin_transaction();

try {
    $stmt = $conn->prepare(
        'SELECT * FROM customers WHERE id = ? AND business_id = ? LIMIT 1 FOR UPDATE'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare customer lookup: ' . $conn->error);
    }

    $stmt->bind_param('ii', $customerId, $businessId);
    $stmt->execute();
    $existingCustomer = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$existingCustomer) {
        throw new RuntimeException('Customer not found or access denied.');
    }

    $oldOpeningBalance = (float) ($existingCustomer['opening_balance'] ?? 0);
    $oldCurrentBalance = (float) ($existingCustomer['current_balance'] ?? 0);
    $adjustedCurrentBalance = $oldCurrentBalance + ($openingBalance - $oldOpeningBalance);

    $updateStmt = $conn->prepare(
        'UPDATE customers SET
            home_branch_id = ?,
            customer_name = ?,
            mobile = ?,
            alternate_mobile = ?,
            email = ?,
            gstin = ?,
            pan_no = ?,
            date_of_birth = ?,
            anniversary_date = ?,
            address_line1 = ?,
            address_line2 = ?,
            city = ?,
            state = ?,
            pincode = ?,
            credit_limit = ?,
            opening_balance = ?,
            current_balance = ?,
            notes = ?,
            is_active = ?
         WHERE id = ? AND business_id = ?'
    );

    if (!$updateStmt) {
        throw new RuntimeException('Unable to prepare customer update: ' . $conn->error);
    }

    $updateStmt->bind_param(
        'isssssssssssssdddsiii',
        $branchId,
        $name,
        $mobile,
        $alternateMobile,
        $email,
        $gstin,
        $panNo,
        $dateOfBirth,
        $anniversaryDate,
        $addressLine1,
        $addressLine2,
        $city,
        $state,
        $pincode,
        $creditLimit,
        $openingBalance,
        $adjustedCurrentBalance,
        $notes,
        $isActive,
        $customerId,
        $businessId
    );

    if (!$updateStmt->execute()) {
        throw new RuntimeException('Unable to update customer: ' . $updateStmt->error);
    }
    $updateStmt->close();

    $existingServices = [];
    $serviceRead = $conn->prepare(
        'SELECT id, service_type, is_active
         FROM customer_services
         WHERE business_id = ? AND customer_id = ?'
    );
    if (!$serviceRead) {
        throw new RuntimeException('Unable to read customer services: ' . $conn->error);
    }

    $serviceRead->bind_param('ii', $businessId, $customerId);
    $serviceRead->execute();
    $serviceResult = $serviceRead->get_result();
    while ($serviceRow = $serviceResult->fetch_assoc()) {
        $existingServices[(string) $serviceRow['service_type']] = $serviceRow;
    }
    $serviceRead->close();

    $activateStmt = $conn->prepare(
        'UPDATE customer_services
         SET is_active = 1, updated_at = CURRENT_TIMESTAMP
         WHERE business_id = ? AND customer_id = ? AND service_type = ?'
    );
    $insertServiceStmt = $conn->prepare(
        'INSERT INTO customer_services
         (business_id, customer_id, service_type, is_active, created_by)
         VALUES (?, ?, ?, 1, ?)'
    );
    $deactivateStmt = $conn->prepare(
        'UPDATE customer_services
         SET is_active = 0, updated_at = CURRENT_TIMESTAMP
         WHERE business_id = ? AND customer_id = ? AND service_type = ?'
    );

    if (!$activateStmt || !$insertServiceStmt || !$deactivateStmt) {
        throw new RuntimeException('Unable to prepare customer service update: ' . $conn->error);
    }

    foreach ($allowedServices as $service) {
        $shouldBeActive = in_array($service, $services, true);
        $exists = isset($existingServices[$service]);

        if ($shouldBeActive && $exists) {
            $activateStmt->bind_param('iis', $businessId, $customerId, $service);
            if (!$activateStmt->execute()) {
                throw new RuntimeException('Unable to activate ' . $service . ' service: ' . $activateStmt->error);
            }
        } elseif ($shouldBeActive && !$exists) {
            $insertServiceStmt->bind_param('iisi', $businessId, $customerId, $service, $userId);
            if (!$insertServiceStmt->execute()) {
                throw new RuntimeException('Unable to add ' . $service . ' service: ' . $insertServiceStmt->error);
            }
        } elseif (!$shouldBeActive && $exists) {
            $deactivateStmt->bind_param('iis', $businessId, $customerId, $service);
            if (!$deactivateStmt->execute()) {
                throw new RuntimeException('Unable to deactivate ' . $service . ' service: ' . $deactivateStmt->error);
            }
        }
    }

    $activateStmt->close();
    $insertServiceStmt->close();
    $deactivateStmt->close();

    if (in_array('Pawn', $services, true)) {
        if (!tableExists($conn, 'pawn_customers')) {
            throw new RuntimeException('pawn_customers table is missing.');
        }

        $guardianName = trim((string) ($_POST['guardian_name'] ?? ''));
        $occupation = trim((string) ($_POST['occupation'] ?? ''));
        $annualIncome = max(0, (float) ($_POST['annual_income'] ?? 0));
        $referenceName = trim((string) ($_POST['reference_name'] ?? ''));
        $referenceMobile = trim((string) ($_POST['reference_mobile'] ?? ''));
        $kycVerified = !empty($_POST['kyc_verified']) ? 1 : 0;
        $pawnCreditLimit = max(0, (float) ($_POST['pawn_credit_limit'] ?? 0));
        $riskCategory = (string) ($_POST['risk_category'] ?? 'Low');
        if (!in_array($riskCategory, ['Low', 'Medium', 'High'], true)) {
            $riskCategory = 'Low';
        }

        $pawnLookup = $conn->prepare(
            'SELECT id FROM pawn_customers WHERE business_id = ? AND customer_id = ? LIMIT 1'
        );
        if (!$pawnLookup) {
            throw new RuntimeException('Unable to prepare pawn profile lookup: ' . $conn->error);
        }

        $pawnLookup->bind_param('ii', $businessId, $customerId);
        $pawnLookup->execute();
        $pawnProfile = $pawnLookup->get_result()->fetch_assoc();
        $pawnLookup->close();

        if ($pawnProfile) {
            $pawnUpdate = $conn->prepare(
                'UPDATE pawn_customers SET
                    guardian_name = ?,
                    occupation = ?,
                    annual_income = ?,
                    reference_name = ?,
                    reference_mobile = ?,
                    kyc_verified = ?,
                    credit_limit = ?,
                    risk_category = ?,
                    updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND business_id = ? AND customer_id = ?'
            );
            if (!$pawnUpdate) {
                throw new RuntimeException('Unable to prepare pawn profile update: ' . $conn->error);
            }

            $pawnId = (int) $pawnProfile['id'];
            $pawnUpdate->bind_param(
                'ssdssidsiii',
                $guardianName,
                $occupation,
                $annualIncome,
                $referenceName,
                $referenceMobile,
                $kycVerified,
                $pawnCreditLimit,
                $riskCategory,
                $pawnId,
                $businessId,
                $customerId
            );

            if (!$pawnUpdate->execute()) {
                throw new RuntimeException('Unable to update pawn profile: ' . $pawnUpdate->error);
            }
            $pawnUpdate->close();
        } else {
            $pawnNotes = '';
            $pawnInsert = $conn->prepare(
                'INSERT INTO pawn_customers
                 (business_id, customer_id, guardian_name, occupation, annual_income,
                  reference_name, reference_mobile, kyc_verified, credit_limit,
                  risk_category, notes, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if (!$pawnInsert) {
                throw new RuntimeException('Unable to prepare pawn profile insert: ' . $conn->error);
            }

            $pawnInsert->bind_param(
                'iissdssidssi',
                $businessId,
                $customerId,
                $guardianName,
                $occupation,
                $annualIncome,
                $referenceName,
                $referenceMobile,
                $kycVerified,
                $pawnCreditLimit,
                $riskCategory,
                $pawnNotes,
                $userId
            );

            if (!$pawnInsert->execute()) {
                throw new RuntimeException('Unable to create pawn profile: ' . $pawnInsert->error);
            }
            $pawnInsert->close();
        }
    }

    $oldAudit = [
        'customer_name' => $existingCustomer['customer_name'] ?? null,
        'mobile' => $existingCustomer['mobile'] ?? null,
        'email' => $existingCustomer['email'] ?? null,
        'opening_balance' => $existingCustomer['opening_balance'] ?? null,
        'current_balance' => $existingCustomer['current_balance'] ?? null,
        'is_active' => $existingCustomer['is_active'] ?? null,
        'services' => array_keys(array_filter(
            $existingServices,
            static fn(array $row): bool => (int) ($row['is_active'] ?? 0) === 1
        ))
    ];

    $newAudit = [
        'customer_name' => $name,
        'mobile' => $mobile,
        'email' => $email,
        'opening_balance' => $openingBalance,
        'current_balance' => $adjustedCurrentBalance,
        'is_active' => $isActive,
        'services' => $services
    ];

    writeAudit(
        $conn,
        $businessId,
        $branchId,
        $userId,
        $customerId,
        'Updated customer profile ' . $name,
        $oldAudit,
        $newAudit
    );

    $conn->commit();

    unset($_SESSION['customer_form_old']);
    $_SESSION['customers_flash'] = [
        'message' => 'Customer updated successfully.',
        'type' => 'success'
    ];

    header('Location: ../customers.php');
    exit;
} catch (Throwable $e) {
    $conn->rollback();
    redirectEdit($customerId, $e->getMessage(), $_POST);
}