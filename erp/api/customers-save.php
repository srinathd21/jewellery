<?php
if (session_status() === PHP_SESSION_NONE)
    session_start();
date_default_timezone_set((string) ($_SESSION['timezone'] ?? 'Asia/Kolkata'));
foreach ([dirname(__DIR__) . '/config/config.php', dirname(__DIR__) . '/config.php', dirname(__DIR__) . '/includes/config.php', dirname(__DIR__) . '/super-admin/includes/config.php'] as $f) {
    if (is_file($f)) {
        require_once $f;
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
    $s = $conn->real_escape_string($table);
    $r = $conn->query("SHOW TABLES LIKE '{$s}'");
    return $r && $r->num_rows > 0;
}
function hasColumn(mysqli $conn, string $table, string $column): bool
{
    $t = $conn->real_escape_string($table);
    $c = $conn->real_escape_string($column);
    $r = $conn->query("SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
    return $r && $r->num_rows > 0;
}
function redirectForm(string $message, array $old = []): void
{
    $_SESSION['customer_form_flash'] = ['message' => $message, 'type' => 'danger'];
    $_SESSION['customer_form_old'] = $old;
    header('Location: ../customer-add.php');
    exit;
}
function generateCode(mysqli $conn, int $businessId): string
{
    $prefix = 'CUS' . date('ym');
    $like = $prefix . '%';
    $last = 0;
    $stmt = $conn->prepare('SELECT customer_code FROM customers WHERE business_id=? AND customer_code LIKE ? ORDER BY id DESC LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('is', $businessId, $like);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row && preg_match('/(\d{4})$/', (string) $row['customer_code'], $m))
            $last = (int) $m[1];
    }
    return $prefix . str_pad((string) ($last + 1), 4, '0', STR_PAD_LEFT);
}
function permission(mysqli $conn, string $action): bool
{
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin')
        return true;
    $map = ['create' => 'can_create', 'update' => 'can_update'];
    $field = $map[$action] ?? '';
    if ($field === '')
        return false;
    foreach (['perm.customer.add', 'perm.customers', 'perm.customer'] as $code) {
        if (isset($_SESSION['permissions'][$code][$field]))
            return (int) $_SESSION['permissions'][$code][$field] === 1;
    }
    $b = (int) ($_SESSION['business_id'] ?? 0);
    $r = (int) ($_SESSION['role_id'] ?? 0);
    if (!$b || !$r)
        return false;
    $stmt = $conn->prepare("SELECT rp.`{$field}` FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.business_id=? AND rp.role_id=? AND p.is_active=1 AND p.permission_code IN ('perm.customer.add','perm.customers','perm.customer') ORDER BY FIELD(p.permission_code,'perm.customer.add','perm.customers','perm.customer') LIMIT 1");
    if (!$stmt)
        return false;
    $stmt->bind_param('ii', $b, $r);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row[$field] ?? 0) === 1;
}
function audit(mysqli $conn, int $b, int $br, int $u, int $id, string $desc, array $new): void
{
    if (!tableExists($conn, 'audit_logs'))
        return;
    $json = json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (hasColumn($conn, 'audit_logs', 'module_code')) {
        $stmt = $conn->prepare("INSERT INTO audit_logs (business_id,branch_id,user_id,module_code,action_type,reference_table,reference_id,description,new_values_json,ip_address,user_agent) VALUES (?,?,?,'customers','Create','customers',?,?,?,?,?)");
        if ($stmt) {
            $stmt->bind_param('iiiissss', $b, $br, $u, $id, $desc, $json, $ip, $ua);
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
if (!hash_equals((string) ($_SESSION['customers_csrf'] ?? ''), (string) ($_POST['csrf_token'] ?? ''))) {
    http_response_code(419);
    exit('Invalid or expired request token.');
}
if (($_POST['action'] ?? '') !== 'create')
    redirectForm('Invalid action.', $_POST);
if (!permission($conn, 'create'))
    redirectForm('You do not have permission to create customers.', $_POST);
if (!tableExists($conn, 'customer_services'))
    redirectForm('customer_services table is missing. Import the SQL migration first.', $_POST);

$b = (int) ($_SESSION['business_id'] ?? 0);
$br = (int) ($_SESSION['branch_id'] ?? ($_SESSION['default_branch_id'] ?? 0));
$u = (int) $_SESSION['user_id'];
$name = trim((string) ($_POST['customer_name'] ?? ''));
$mobile = trim((string) ($_POST['mobile'] ?? ''));
$services = $_POST['services'] ?? [];
$allowed = ['Billing', 'Pawn', 'Chit'];
$services = array_values(array_unique(array_intersect($allowed, is_array($services) ? $services : [$services])));
$errors = [];
if ($name === '')
    $errors[] = 'Customer name is required.';
if ($mobile === '')
    $errors[] = 'Mobile number is required.';
elseif (!preg_match('/^[0-9+\-\s]{6,20}$/', $mobile))
    $errors[] = 'Enter a valid mobile number.';
if (!$services)
    $errors[] = 'Select at least one service.';
$email = trim((string) ($_POST['email'] ?? ''));
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))
    $errors[] = 'Enter a valid email address.';
$stmt = $conn->prepare('SELECT id FROM customers WHERE business_id=? AND mobile=? AND is_active=1 LIMIT 1');
if ($stmt) {
    $stmt->bind_param('is', $b, $mobile);
    $stmt->execute();
    if ($stmt->get_result()->fetch_assoc())
        $errors[] = 'An active customer with this mobile number already exists.';
    $stmt->close();
}
if ($errors)
    redirectForm(implode(' ', $errors), $_POST);

$alternate = trim((string) ($_POST['alternate_mobile'] ?? ''));
$gstin = strtoupper(trim((string) ($_POST['gstin'] ?? '')));
$pan = strtoupper(trim((string) ($_POST['pan_no'] ?? '')));
$dob = trim((string) ($_POST['date_of_birth'] ?? '')) ?: null;
$ann = trim((string) ($_POST['anniversary_date'] ?? '')) ?: null;
$a1 = trim((string) ($_POST['address_line1'] ?? ''));
$a2 = trim((string) ($_POST['address_line2'] ?? ''));
$city = trim((string) ($_POST['city'] ?? ''));
$state = trim((string) ($_POST['state'] ?? ''));
$pincode = trim((string) ($_POST['pincode'] ?? ''));
$credit = max(0, (float) ($_POST['credit_limit'] ?? 0));
$opening = (float) ($_POST['opening_balance'] ?? 0);
$notes = trim((string) ($_POST['notes'] ?? ''));
$isActive = (int) ($_POST['is_active'] ?? 1) === 1 ? 1 : 0;
$paymentTerms = trim((string) ($_POST['payment_terms'] ?? ''));
if ($paymentTerms !== '')
    $notes = trim($notes . "\nPayment Terms: " . $paymentTerms);

$conn->begin_transaction();
try {
    $code = generateCode($conn, $b);
    $stmt = $conn->prepare('INSERT INTO customers (business_id,home_branch_id,customer_code,customer_name,mobile,alternate_mobile,email,gstin,pan_no,date_of_birth,anniversary_date,address_line1,address_line2,city,state,pincode,credit_limit,opening_balance,current_balance,notes,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    if (!$stmt)
        throw new RuntimeException('Unable to prepare customer insert: ' . $conn->error);
    $current = $opening;
    $stmt->bind_param('iissssssssssssssdddsi', $b, $br, $code, $name, $mobile, $alternate, $email, $gstin, $pan, $dob, $ann, $a1, $a2, $city, $state, $pincode, $credit, $opening, $current, $notes, $isActive);
    if (!$stmt->execute())
        throw new RuntimeException('Unable to create customer: ' . $stmt->error);
    $customerId = (int) $stmt->insert_id;
    $stmt->close();

    $serviceStmt = $conn->prepare('INSERT INTO customer_services (business_id,customer_id,service_type,is_active,created_by) VALUES (?,?,?,1,?)');
    if (!$serviceStmt)
        throw new RuntimeException('Unable to prepare customer services: ' . $conn->error);
    foreach ($services as $service) {
        $serviceStmt->bind_param('iisi', $b, $customerId, $service, $u);
        if (!$serviceStmt->execute())
            throw new RuntimeException('Unable to save ' . $service . ' service: ' . $serviceStmt->error);
    }
    $serviceStmt->close();

    if (in_array('Pawn', $services, true)) {
        $guardian = trim((string) ($_POST['guardian_name'] ?? ''));
        $occupation = trim((string) ($_POST['occupation'] ?? ''));
        $income = max(0, (float) ($_POST['annual_income'] ?? 0));
        $refName = trim((string) ($_POST['reference_name'] ?? ''));
        $refMobile = trim((string) ($_POST['reference_mobile'] ?? ''));
        $kyc = !empty($_POST['kyc_verified']) ? 1 : 0;
        $pawnCredit = max(0, (float) ($_POST['pawn_credit_limit'] ?? 0));
        $risk = (string) ($_POST['risk_category'] ?? 'Low');
        if (!in_array($risk, ['Low', 'Medium', 'High'], true))
            $risk = 'Low';
        $pawnNotes = '';
        $stmt = $conn->prepare('INSERT INTO pawn_customers (business_id,customer_id,guardian_name,occupation,annual_income,reference_name,reference_mobile,kyc_verified,credit_limit,risk_category,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        if (!$stmt)
            throw new RuntimeException('Unable to prepare pawn profile: ' . $conn->error);
        $stmt->bind_param('iissdssidssi', $b, $customerId, $guardian, $occupation, $income, $refName, $refMobile, $kyc, $pawnCredit, $risk, $pawnNotes, $u);
        if (!$stmt->execute())
            throw new RuntimeException('Unable to save pawn profile: ' . $stmt->error);
        $stmt->close();
    }


    audit($conn, $b, $br, $u, $customerId, 'Created multi-service customer ' . $name, ['customer_code' => $code, 'services' => $services, 'mobile' => $mobile]);
    $conn->commit();
    unset($_SESSION['customer_form_old']);
    $_SESSION['customers_flash'] = ['message' => 'Customer added successfully. Customer Code: ' . $code, 'type' => 'success'];
    header('Location: ../customers.php');
    exit;
} catch (Throwable $e) {
    $conn->rollback();
    redirectForm($e->getMessage(), $_POST);
}