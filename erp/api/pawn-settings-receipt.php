<?php
require dirname(__DIR__) . '/_common.php';

// Resolve the current session context explicitly for this API.
// _common.php provides the DB/CSRF context, but user_id is required here for audit_logs.
$businessId = (int)($businessId ?? ($_SESSION['business_id'] ?? 0));
$branchId   = (int)($branchId ?? ($_SESSION['branch_id'] ?? ($_SESSION['default_branch_id'] ?? 0)));
$userId     = (int)($userId ?? ($_SESSION['user_id'] ?? 0));

if ($businessId <= 0) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(403);
    echo json_encode(array('success' => false, 'message' => 'A valid business must be selected.'));
    exit;
}

if ($userId <= 0) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(401);
    echo json_encode(array('success' => false, 'message' => 'Your session has expired. Please log in again.'));
    exit;
}

function psrJson($ok, $message, $extra = array(), $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(array(
        'success' => (bool)$ok,
        'message' => (string)$message
    ), $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function psrTableExists($conn, $table)
{
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
    $r = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
    return $r && $r->num_rows > 0;
}

function psrRequireCsrf($csrfToken)
{
    $posted = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
    if ($posted === '' || !hash_equals((string)$csrfToken, $posted)) {
        psrJson(false, 'Invalid CSRF token. Refresh the page and try again.', array(), 403);
    }
}

function psrSettingGet($conn, $businessId, $key, $default = '')
{
    $s = $conn->prepare('SELECT setting_value FROM business_settings WHERE business_id=? AND setting_key=? LIMIT 1');
    if (!$s) return $default;
    $s->bind_param('is', $businessId, $key);
    if (!$s->execute()) { $s->close(); return $default; }
    $r = $s->get_result()->fetch_assoc();
    $s->close();
    return $r ? (string)($r['setting_value'] ?? $default) : $default;
}

function psrSettingSave($conn, $businessId, $key, $value, $type)
{
    $sql = "INSERT INTO business_settings (business_id,setting_key,setting_value,value_type,is_public)"
         . " VALUES (?,?,?,?,0)"
         . " ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),value_type=VALUES(value_type),is_public=0";
    $s = $conn->prepare($sql);
    if (!$s) throw new RuntimeException('Unable to prepare setting save: ' . $conn->error);
    $s->bind_param('isss', $businessId, $key, $value, $type);
    if (!$s->execute()) {
        $err = $s->error;
        $s->close();
        throw new RuntimeException('Unable to save receipt setting: ' . $err);
    }
    $s->close();
}

function psrUpload($field, $businessId)
{
    if (empty($_FILES[$field]) || !is_array($_FILES[$field])) return null;

    $error = (int)($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) return null;
    if ($error !== UPLOAD_ERR_OK) throw new RuntimeException('Unable to upload ' . str_replace('_', ' ', $field) . '.');

    $size = (int)($_FILES[$field]['size'] ?? 0);
    if ($size <= 0 || $size > 4 * 1024 * 1024) {
        throw new RuntimeException('Pawn receipt images must be smaller than 4 MB each.');
    }

    $tmp = (string)($_FILES[$field]['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) throw new RuntimeException('Invalid uploaded image.');

    $allowed = array('image/png'=>'png', 'image/jpeg'=>'jpg', 'image/webp'=>'webp');
    $mime = '';
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) {
            $mime = (string)finfo_file($fi, $tmp);
            finfo_close($fi);
        }
    }

    if (!isset($allowed[$mime])) throw new RuntimeException('Pawn receipt images must be PNG, JPG or WEBP.');

    $relativeDir = 'uploads/business/' . (int)$businessId . '/pawn-receipt';
    $absoluteDir = dirname(__DIR__) . '/' . $relativeDir;
    if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) {
        throw new RuntimeException('Unable to create pawn receipt upload directory.');
    }

    $filename = $field . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(5)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($tmp, $absoluteDir . '/' . $filename)) {
        throw new RuntimeException('Unable to save pawn receipt image.');
    }

    return $relativeDir . '/' . $filename;
}

function psrDeleteFile($path, $businessId)
{
    $path = trim((string)$path);
    if ($path === '') return;

    $requiredPrefix = 'uploads/business/' . (int)$businessId . '/pawn-receipt/';
    if (strpos($path, $requiredPrefix) !== 0) return;

    $full = dirname(__DIR__) . '/' . ltrim($path, '/');
    if (is_file($full)) @unlink($full);
}

function psrAudit($conn, $businessId, $branchId, $userId, $keys)
{
    if (!psrTableExists($conn, 'audit_logs')) return;

    $module = 'pawn.receipt.settings';
    $action = 'Update';
    $table = 'business_settings';
    $referenceId = 0;
    $description = 'Updated separate Pawn Receipt settings';
    $oldJson = null;
    $newJson = json_encode(array('keys' => $keys), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);

    $s = $conn->prepare('INSERT INTO audit_logs (business_id,branch_id,user_id,module_code,action_type,reference_table,reference_id,description,old_values_json,new_values_json,ip_address,user_agent,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
    if (!$s) return;
    $s->bind_param('iiisssisssss', $businessId, $branchId, $userId, $module, $action, $table, $referenceId, $description, $oldJson, $newJson, $ip, $ua);
    $s->execute();
    $s->close();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    psrJson(false, 'Method not allowed.', array(), 405);
}

psrRequireCsrf($csrfToken);

$action = trim((string)($_POST['action'] ?? ''));
if ($action !== 'save_receipt') {
    psrJson(false, 'Invalid action.', array(), 400);
}

if (!psrTableExists($conn, 'business_settings')) {
    psrJson(false, 'business_settings table is not available.', array(), 500);
}

$textKeys = array(
    'pawn_receipt_business_name',
    'pawn_receipt_tagline',
    'pawn_receipt_title',
    'pawn_receipt_copy_label',
    'pawn_receipt_address',
    'pawn_receipt_mobile',
    'pawn_receipt_email',
    'pawn_receipt_website',
    'pawn_receipt_gstin',
    'pawn_receipt_footer_text',
    'pawn_receipt_terms_conditions',
    'pawn_receipt_upi_id',
    'pawn_receipt_watermark_text'
);

$boolKeys = array(
    'pawn_receipt_show_logo',
    'pawn_receipt_show_address',
    'pawn_receipt_show_mobile',
    'pawn_receipt_show_email',
    'pawn_receipt_show_website',
    'pawn_receipt_show_gstin',
    'pawn_receipt_show_watermark',
    'pawn_receipt_show_terms',
    'pawn_receipt_show_signature',
    'pawn_receipt_show_stamp',
    'pawn_receipt_show_upi',
    'pawn_receipt_show_qr'
);

$assetMap = array(
    'pawn_receipt_logo' => array('pawn_receipt_logo_path', 'remove_receipt_logo'),
    'pawn_receipt_signature' => array('pawn_receipt_signature_path', 'remove_receipt_signature'),
    'pawn_receipt_stamp' => array('pawn_receipt_stamp_path', 'remove_receipt_stamp'),
    'pawn_receipt_qr' => array('pawn_receipt_qr_path', 'remove_receipt_qr')
);

// Basic server-side validation.
$email = trim((string)($_POST['pawn_receipt_email'] ?? ''));
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    psrJson(false, 'Enter a valid receipt email address.', array(), 422);
}

$mobile = trim((string)($_POST['pawn_receipt_mobile'] ?? ''));
if ($mobile !== '' && !preg_match('/^[0-9+()\-\s]{6,20}$/', $mobile)) {
    psrJson(false, 'Enter a valid receipt mobile number.', array(), 422);
}

$title = trim((string)($_POST['pawn_receipt_title'] ?? ''));
if (strlen($title) > 100) psrJson(false, 'Receipt title is too long.', array(), 422);

$uploadedNow = array();
$deleteAfterCommit = array();

try {
    $conn->begin_transaction();

    foreach ($textKeys as $key) {
        $value = trim((string)($_POST[$key] ?? ''));
        psrSettingSave($conn, $businessId, $key, $value, 'string');
    }

    foreach ($boolKeys as $key) {
        psrSettingSave($conn, $businessId, $key, !empty($_POST[$key]) ? '1' : '0', 'boolean');
    }

    foreach ($assetMap as $field => $cfg) {
        $settingKey = $cfg[0];
        $removeKey = $cfg[1];
        $oldPath = psrSettingGet($conn, $businessId, $settingKey, '');
        $newPath = psrUpload($field, $businessId);

        if ($newPath !== null) {
            $uploadedNow[] = $newPath;
            psrSettingSave($conn, $businessId, $settingKey, $newPath, 'string');
            if ($oldPath !== '' && $oldPath !== $newPath) $deleteAfterCommit[] = $oldPath;
        } elseif (!empty($_POST[$removeKey])) {
            psrSettingSave($conn, $businessId, $settingKey, '', 'string');
            if ($oldPath !== '') $deleteAfterCommit[] = $oldPath;
        }
    }

    psrAudit($conn, $businessId, (int)$branchId, (int)$userId, array_merge(
        $textKeys,
        $boolKeys,
        array('pawn_receipt_logo_path','pawn_receipt_signature_path','pawn_receipt_stamp_path','pawn_receipt_qr_path')
    ));

    $conn->commit();

    foreach (array_unique($deleteAfterCommit) as $path) {
        psrDeleteFile($path, $businessId);
    }

    psrJson(true, 'Pawn receipt settings saved successfully.');
} catch (Throwable $e) {
    $conn->rollback();
    foreach (array_unique($uploadedNow) as $path) {
        psrDeleteFile($path, $businessId);
    }
    psrJson(false, $e->getMessage() ?: 'Unable to save pawn receipt settings.', array(), 500);
}
    