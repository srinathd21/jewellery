<?php
if (session_status() === PHP_SESSION_NONE)
    session_start();
header('Content-Type: application/json; charset=utf-8');
function respond(bool $success, string $message, int $status = 200, array $extra = []): never
{
    http_response_code($status);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST')
    respond(false, 'Invalid request method.', 405);
if (empty($_SESSION['user_id']))
    respond(false, 'Your login session has expired.', 401);
foreach ([dirname(__DIR__) . '/config/config.php', dirname(__DIR__) . '/config.php', dirname(__DIR__) . '/super-admin/includes/config.php'] as $f) {
    if (is_file($f)) {
        require_once $f;
        break;
    }
}
if (!isset($conn) || !($conn instanceof mysqli))
    respond(false, 'Database configuration is unavailable.', 500);
$conn->set_charset('utf8mb4');
$token = (string) ($_POST['csrf_token'] ?? '');
if (empty($_SESSION['business_settings_csrf']) || !hash_equals($_SESSION['business_settings_csrf'], $token))
    respond(false, 'Invalid or expired request token.', 419);
$businessId = (int) ($_SESSION['business_id'] ?? 0);
$roleId = (int) ($_SESSION['role_id'] ?? 0);
if ($businessId <= 0)
    respond(false, 'A valid business must be selected.', 422);
$allowed = (($_SESSION['user_type'] ?? '') === 'Platform Admin');
if (!$allowed) {
    foreach (['perm.settings.business', 'perm.settings'] as $k) {
        if (isset($_SESSION['permissions'][$k]['can_update'])) {
            $allowed = (int) $_SESSION['permissions'][$k]['can_update'] === 1;
            break;
        }
    }
}
if (!$allowed && $roleId > 0) {
    $stmt = $conn->prepare("SELECT rp.can_update FROM role_permissions rp INNER JOIN permissions p ON p.id=rp.permission_id WHERE rp.business_id=? AND rp.role_id=? AND p.is_active=1 AND p.permission_code IN ('perm.settings.business','perm.settings') ORDER BY FIELD(p.permission_code,'perm.settings.business','perm.settings') LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('ii', $businessId, $roleId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $allowed = (int) ($row['can_update'] ?? 0) === 1;
    }
}
if (!$allowed)
    respond(false, 'You do not have permission to update business settings.', 403);

$fields = ['business_code', 'business_name', 'legal_name', 'business_type', 'owner_name', 'mobile', 'whatsapp', 'email', 'website', 'gstin', 'pan_no', 'cin_no', 'currency_code', 'currency_symbol', 'timezone', 'financial_year_start_month', 'status', 'trial_ends_at'];
$data = [];
foreach ($fields as $f)
    $data[$f] = trim((string) ($_POST[$f] ?? ''));
if ($data['business_code'] === '' || $data['business_name'] === '' || $data['business_type'] === '')
    respond(false, 'Business code, business name and business type are required.', 422);
if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL))
    respond(false, 'Please enter a valid email address.', 422);
if ($data['website'] !== '' && !filter_var($data['website'], FILTER_VALIDATE_URL))
    respond(false, 'Please enter a valid website URL including http:// or https://.', 422);
$data['gstin'] = strtoupper($data['gstin']);
$data['pan_no'] = strtoupper($data['pan_no']);
$data['cin_no'] = strtoupper($data['cin_no']);
if ($data['gstin'] !== '' && !preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][A-Z0-9]Z[A-Z0-9]$/', $data['gstin']))
    respond(false, 'Please enter a valid 15-character GSTIN.', 422);
if ($data['pan_no'] !== '' && !preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $data['pan_no']))
    respond(false, 'Please enter a valid PAN number.', 422);
if (!in_array($data['status'], ['Trial', 'Active', 'Suspended', 'Closed'], true))
    respond(false, 'Invalid business status.', 422);
$data['financial_year_start_month'] = (string) max(1, min(12, (int) $data['financial_year_start_month']));
if ($data['trial_ends_at'] === '')
    $data['trial_ends_at'] = null;

$custom = json_decode((string) ($_POST['custom_settings_json'] ?? '[]'), true);
if (!is_array($custom))
    respond(false, 'Invalid custom settings data.', 422);
$allowedTypes = ['string', 'number', 'boolean', 'json', 'date', 'datetime'];
$clean = [];
foreach ($custom as $row) {
    $key = trim((string) ($row['setting_key'] ?? ''));
    if ($key === '')
        continue;
    if (!preg_match('/^[a-zA-Z0-9_.-]{1,120}$/', $key))
        respond(false, 'Custom setting keys may only contain letters, numbers, dot, underscore and hyphen.', 422);
    $type = (string) ($row['value_type'] ?? 'string');
    if (!in_array($type, $allowedTypes, true))
        $type = 'string';
    $value = (string) ($row['setting_value'] ?? '');
    if ($type === 'json') {
        json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE)
            respond(false, 'Invalid JSON value for setting ' . $key . '.', 422);
    }
    $clean[$key] = ['value' => $value, 'type' => $type, 'public' => !empty($row['is_public']) ? 1 : 0];
}

$conn->begin_transaction();
try {
    $sql = "UPDATE businesses SET business_code=?,business_name=?,legal_name=?,business_type=?,owner_name=?,mobile=?,whatsapp=?,email=?,website=?,gstin=?,pan_no=?,cin_no=?,currency_code=?,currency_symbol=?,timezone=?,financial_year_start_month=?,status=?,trial_ends_at=?,updated_at=NOW() WHERE id=?";
    $stmt = $conn->prepare($sql);
    if (!$stmt)
        throw new RuntimeException($conn->error);
    $fy = (int) $data['financial_year_start_month'];
    $stmt->bind_param('sssssssssssssssissi', $data['business_code'], $data['business_name'], $data['legal_name'], $data['business_type'], $data['owner_name'], $data['mobile'], $data['whatsapp'], $data['email'], $data['website'], $data['gstin'], $data['pan_no'], $data['cin_no'], $data['currency_code'], $data['currency_symbol'], $data['timezone'], $fy, $data['status'], $data['trial_ends_at'], $businessId);
    if (!$stmt->execute())
        throw new RuntimeException($stmt->error);
    $stmt->close();

    $check = $conn->query("SHOW TABLES LIKE 'business_settings'");
    if ($check && $check->num_rows > 0) {
        $stmt = $conn->prepare('DELETE FROM business_settings WHERE business_id=?');
        $stmt->bind_param('i', $businessId);
        if (!$stmt->execute())
            throw new RuntimeException($stmt->error);
        $stmt->close();
        if ($clean) {
            $stmt = $conn->prepare('INSERT INTO business_settings (business_id,setting_key,setting_value,value_type,is_public) VALUES (?,?,?,?,?)');
            if (!$stmt)
                throw new RuntimeException($conn->error);
            foreach ($clean as $key => $row) {
                $val = $row['value'];
                $type = $row['type'];
                $pub = $row['public'];
                $stmt->bind_param('isssi', $businessId, $key, $val, $type, $pub);
                if (!$stmt->execute())
                    throw new RuntimeException($stmt->error);
            }
            $stmt->close();
        }
    }

    $auditCheck = $conn->query("SHOW TABLES LIKE 'audit_logs'");
    if ($auditCheck && $auditCheck->num_rows > 0) {
        $uid = (int) $_SESSION['user_id'];
        $branch = (int) ($_SESSION['branch_id'] ?? 0);
        $desc = 'Updated business settings';
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $stmt = $conn->prepare("INSERT INTO audit_logs (business_id,branch_id,user_id,module_code,action_type,reference_table,reference_id,description,new_values_json,ip_address,user_agent) VALUES (?,?,?,'settings.business','Update','businesses',?,?,?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param('iiiissss', $businessId, $branch, $uid, $businessId, $desc, $json, $ip, $ua);
            $stmt->execute();
            $stmt->close();
        }
    }
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    respond(false, 'Unable to save business settings: ' . $e->getMessage(), 500);
}

$_SESSION['business_name'] = $data['business_name'];
$_SESSION['currency_symbol'] = $data['currency_symbol'];
$_SESSION['currency_code'] = $data['currency_code'];
$_SESSION['timezone'] = $data['timezone'];
respond(true, 'Business settings saved successfully.', 200, ['business_name' => $data['business_name']]);
