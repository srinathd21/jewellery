<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');

function respond(bool $success, string $message, array $extra = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

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
    respond(false, 'Database configuration is not available.', [], 500);
}
$conn->set_charset('utf8mb4');

if (empty($_SESSION['user_id'])) respond(false, 'Your session has expired. Please log in again.', [], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(false, 'Invalid request method.', [], 405);
if (!hash_equals((string)($_SESSION['suppliers_csrf'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) {
    respond(false, 'Invalid or expired request token. Refresh the page and try again.', [], 419);
}

function hasPermission(mysqli $conn, string $action): bool
{
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin') return true;

    $map = ['open'=>'can_open','view'=>'can_view','create'=>'can_create','update'=>'can_update','delete'=>'can_delete'];
    $field = $map[$action] ?? '';
    if ($field === '') return false;

    $keys = ['perm.suppliers','perm.purchases.suppliers','perm.purchases'];
    $permissions = $_SESSION['permissions'] ?? [];
    foreach ($keys as $key) {
        if (isset($permissions[$key][$field])) return (int)$permissions[$key][$field] === 1;
    }

    $businessId = (int)($_SESSION['business_id'] ?? 0);
    $roleId = (int)($_SESSION['role_id'] ?? 0);
    if ($businessId <= 0 || $roleId <= 0) return false;

    $sql = "SELECT rp.`{$field}`
            FROM role_permissions rp
            INNER JOIN permissions p ON p.id = rp.permission_id
            WHERE rp.business_id = ? AND rp.role_id = ? AND p.is_active = 1
              AND p.permission_code IN ('perm.suppliers','perm.purchases.suppliers','perm.purchases')
            ORDER BY FIELD(p.permission_code,'perm.suppliers','perm.purchases.suppliers','perm.purchases')
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;
    $stmt->bind_param('ii', $businessId, $roleId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row[$field] ?? 0) === 1;
}

function tableExists(mysqli $conn, string $table): bool
{
    $safe = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $result && $result->num_rows > 0;
}

function audit(mysqli $conn, int $businessId, int $branchId, int $userId, string $action, ?int $referenceId, string $description, $oldValues = null, $newValues = null): void
{
    if (!tableExists($conn, 'audit_logs')) return;
    $oldJson = $oldValues === null ? null : json_encode($oldValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $newJson = $newValues === null ? null : json_encode($newValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $agent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $stmt = $conn->prepare("INSERT INTO audit_logs
        (business_id, branch_id, user_id, module_code, action_type, reference_table, reference_id, description, old_values_json, new_values_json, ip_address, user_agent)
        VALUES (?, ?, ?, 'purchases.suppliers', ?, 'suppliers', ?, ?, ?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param('iiisssssss', $businessId, $branchId, $userId, $action, $referenceId, $description, $oldJson, $newJson, $ip, $agent);
        $stmt->execute();
        $stmt->close();
    }
}

function nullable(string $value): ?string
{
    $value = trim($value);
    return $value === '' ? null : $value;
}

$action = (string)($_POST['action'] ?? '');
$businessId = (int)($_SESSION['business_id'] ?? 0);
$userId = (int)($_SESSION['user_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? 0);
if ($businessId <= 0) respond(false, 'A valid business must be selected.', [], 403);

if ($action === 'get') {
    if (!hasPermission($conn, 'view') && !hasPermission($conn, 'open')) {
        respond(false, 'You do not have permission to view suppliers.', [], 403);
    }
    $supplierId = (int)($_POST['supplier_id'] ?? 0);
    $stmt = $conn->prepare('SELECT id, supplier_code, supplier_name, contact_person, mobile, email, gstin, address, opening_balance, current_balance, is_active FROM suppliers WHERE id = ? AND business_id = ? LIMIT 1');
    if (!$stmt) respond(false, 'Unable to prepare supplier query: ' . $conn->error, [], 500);
    $stmt->bind_param('ii', $supplierId, $businessId);
    $stmt->execute();
    $supplier = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$supplier) respond(false, 'Supplier not found.', [], 404);
    respond(true, 'Supplier loaded.', ['supplier' => $supplier]);
}

if ($action === 'save') {
    $supplierId = (int)($_POST['supplier_id'] ?? 0);
    $isNew = $supplierId <= 0;

    if ($isNew && !hasPermission($conn, 'create')) respond(false, 'You do not have permission to create suppliers.', [], 403);
    if (!$isNew && !hasPermission($conn, 'update')) respond(false, 'You do not have permission to update suppliers.', [], 403);

    $supplierName = trim((string)($_POST['supplier_name'] ?? ''));
    $supplierCode = strtoupper(trim((string)($_POST['supplier_code'] ?? '')));
    $contactPerson = trim((string)($_POST['contact_person'] ?? ''));
    $mobile = trim((string)($_POST['mobile'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $gstin = strtoupper(trim((string)($_POST['gstin'] ?? '')));
    $address = trim((string)($_POST['address'] ?? ''));
    $openingBalance = max(0, (float)($_POST['opening_balance'] ?? 0));
    $isActive = (int)($_POST['is_active'] ?? 1) === 1 ? 1 : 0;

    if ($supplierName === '') respond(false, 'Supplier name is required.');
    if (mb_strlen($supplierName) > 150) respond(false, 'Supplier name must not exceed 150 characters.');
    if ($supplierCode !== '' && !preg_match('/^[A-Z0-9_-]{2,50}$/', $supplierCode)) respond(false, 'Supplier code may contain only letters, numbers, underscore and hyphen.');
    if ($mobile !== '' && !preg_match('/^[0-9+()\-\s]{7,20}$/', $mobile)) respond(false, 'Enter a valid mobile number.');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) respond(false, 'Enter a valid email address.');
    if ($gstin !== '' && !preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][A-Z0-9]Z[A-Z0-9]$/', $gstin)) respond(false, 'Enter a valid 15-character GSTIN.');

    if ($supplierCode === '') {
        $stmt = $conn->prepare("SELECT COALESCE(MAX(id), 0) + 1 AS next_id FROM suppliers WHERE business_id = ?");
        if (!$stmt) respond(false, 'Unable to generate supplier code: ' . $conn->error, [], 500);
        $stmt->bind_param('i', $businessId);
        $stmt->execute();
        $nextId = (int)($stmt->get_result()->fetch_assoc()['next_id'] ?? 1);
        $stmt->close();
        $supplierCode = 'SUP-' . str_pad((string)$nextId, 3, '0', STR_PAD_LEFT);
    }

    $stmt = $conn->prepare('SELECT id FROM suppliers WHERE business_id = ? AND supplier_code = ? AND id <> ? LIMIT 1');
    if (!$stmt) respond(false, 'Unable to validate supplier code: ' . $conn->error, [], 500);
    $stmt->bind_param('isi', $businessId, $supplierCode, $supplierId);
    $stmt->execute();
    $duplicate = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($duplicate) respond(false, 'This supplier code is already used.');

    $stmt = $conn->prepare('SELECT id FROM suppliers WHERE business_id = ? AND supplier_name = ? AND COALESCE(mobile, "") = ? AND id <> ? LIMIT 1');
    if (!$stmt) respond(false, 'Unable to validate supplier: ' . $conn->error, [], 500);
    $stmt->bind_param('issi', $businessId, $supplierName, $mobile, $supplierId);
    $stmt->execute();
    $duplicate = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($duplicate) respond(false, 'A supplier with the same name and mobile number already exists.');

    $old = null;
    if (!$isNew) {
        $stmt = $conn->prepare('SELECT * FROM suppliers WHERE id = ? AND business_id = ? LIMIT 1');
        if (!$stmt) respond(false, 'Unable to load supplier: ' . $conn->error, [], 500);
        $stmt->bind_param('ii', $supplierId, $businessId);
        $stmt->execute();
        $old = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$old) respond(false, 'Supplier not found.', [], 404);
    }

    $contactPersonV = nullable($contactPerson);
    $mobileV = nullable($mobile);
    $emailV = nullable($email);
    $gstinV = nullable($gstin);
    $addressV = nullable($address);

    $conn->begin_transaction();
    try {
        if ($isNew) {
            $currentBalance = $openingBalance;
            $stmt = $conn->prepare('INSERT INTO suppliers (business_id, supplier_code, supplier_name, contact_person, mobile, email, gstin, address, opening_balance, current_balance, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            if (!$stmt) throw new RuntimeException($conn->error);
            $stmt->bind_param('isssssssddi', $businessId, $supplierCode, $supplierName, $contactPersonV, $mobileV, $emailV, $gstinV, $addressV, $openingBalance, $currentBalance, $isActive);
            if (!$stmt->execute()) throw new RuntimeException($stmt->error);
            $supplierId = (int)$stmt->insert_id;
            $stmt->close();
        } else {
            $stmt = $conn->prepare('UPDATE suppliers SET supplier_code = ?, supplier_name = ?, contact_person = ?, mobile = ?, email = ?, gstin = ?, address = ?, opening_balance = ?, is_active = ? WHERE id = ? AND business_id = ?');
            if (!$stmt) throw new RuntimeException($conn->error);
            $stmt->bind_param('sssssssdiii', $supplierCode, $supplierName, $contactPersonV, $mobileV, $emailV, $gstinV, $addressV, $openingBalance, $isActive, $supplierId, $businessId);
            if (!$stmt->execute()) throw new RuntimeException($stmt->error);
            $stmt->close();
        }

        $new = [
            'supplier_code' => $supplierCode,
            'supplier_name' => $supplierName,
            'contact_person' => $contactPersonV,
            'mobile' => $mobileV,
            'email' => $emailV,
            'gstin' => $gstinV,
            'address' => $addressV,
            'opening_balance' => $openingBalance,
            'is_active' => $isActive,
        ];
        audit($conn, $businessId, $branchId, $userId, $isNew ? 'Create' : 'Update', $supplierId, ($isNew ? 'Created' : 'Updated') . ' supplier ' . $supplierName, $old, $new);
        $conn->commit();
        respond(true, $isNew ? 'Supplier created successfully.' : 'Supplier updated successfully.', ['supplier_id' => $supplierId]);
    } catch (Throwable $error) {
        $conn->rollback();
        respond(false, 'Unable to save supplier: ' . $error->getMessage(), [], 500);
    }
}

if ($action === 'toggle') {
    if (!hasPermission($conn, 'update')) respond(false, 'You do not have permission to update suppliers.', [], 403);
    $supplierId = (int)($_POST['supplier_id'] ?? 0);
    $isActive = (int)($_POST['is_active'] ?? 0) === 1 ? 1 : 0;

    $stmt = $conn->prepare('SELECT supplier_name, is_active FROM suppliers WHERE id = ? AND business_id = ? LIMIT 1');
    if (!$stmt) respond(false, 'Unable to load supplier: ' . $conn->error, [], 500);
    $stmt->bind_param('ii', $supplierId, $businessId);
    $stmt->execute();
    $old = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$old) respond(false, 'Supplier not found.', [], 404);

    $stmt = $conn->prepare('UPDATE suppliers SET is_active = ? WHERE id = ? AND business_id = ?');
    if (!$stmt) respond(false, 'Unable to update supplier: ' . $conn->error, [], 500);
    $stmt->bind_param('iii', $isActive, $supplierId, $businessId);
    if (!$stmt->execute()) respond(false, 'Unable to update supplier: ' . $stmt->error, [], 500);
    $stmt->close();

    audit($conn, $businessId, $branchId, $userId, 'Update', $supplierId, $isActive ? 'Activated supplier' : 'Deactivated supplier', $old, ['is_active' => $isActive]);
    respond(true, $isActive ? 'Supplier activated successfully.' : 'Supplier deactivated successfully.');
}

if ($action === 'delete') {
    if (!hasPermission($conn, 'delete')) respond(false, 'You do not have permission to delete suppliers.', [], 403);
    $supplierId = (int)($_POST['supplier_id'] ?? 0);

    $stmt = $conn->prepare('SELECT * FROM suppliers WHERE id = ? AND business_id = ? LIMIT 1');
    if (!$stmt) respond(false, 'Unable to load supplier: ' . $conn->error, [], 500);
    $stmt->bind_param('ii', $supplierId, $businessId);
    $stmt->execute();
    $old = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$old) respond(false, 'Supplier not found.', [], 404);

    foreach (['purchases','supplier_payments','purchase_returns'] as $table) {
        if (!tableExists($conn, $table)) continue;
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM `{$table}` WHERE supplier_id = ? AND business_id = ?");
        if ($stmt) {
            $stmt->bind_param('ii', $supplierId, $businessId);
            $stmt->execute();
            $count = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
            $stmt->close();
            if ($count > 0) respond(false, 'This supplier is already used in transactions. Deactivate it instead of deleting.');
        }
    }

    $stmt = $conn->prepare('DELETE FROM suppliers WHERE id = ? AND business_id = ? LIMIT 1');
    if (!$stmt) respond(false, 'Unable to delete supplier: ' . $conn->error, [], 500);
    $stmt->bind_param('ii', $supplierId, $businessId);
    if (!$stmt->execute()) respond(false, 'Unable to delete supplier: ' . $stmt->error, [], 500);
    $stmt->close();

    audit($conn, $businessId, $branchId, $userId, 'Delete', $supplierId, 'Deleted supplier ' . (string)$old['supplier_name'], $old, null);
    respond(true, 'Supplier deleted successfully.');
}

respond(false, 'Invalid action.', [], 400);
