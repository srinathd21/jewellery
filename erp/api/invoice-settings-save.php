<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');

function respond(bool $success, string $message, array $extra = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

$configCandidates = [
    dirname(__DIR__) . '/config/config.php',
    dirname(__DIR__) . '/config.php',
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

if (empty($_SESSION['user_id'])) {
    respond(false, 'Your session has expired. Please log in again.', [], 401);
}
if (!hash_equals((string) ($_SESSION['invoice_settings_csrf'] ?? ''), (string) ($_POST['csrf_token'] ?? ''))) {
    respond(false, 'Invalid security token. Refresh the page and try again.', [], 419);
}

function hasPermission(mysqli $conn, string $action): bool
{
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin') return true;
    $map = ['open'=>'can_open','view'=>'can_view','create'=>'can_create','update'=>'can_update','delete'=>'can_delete'];
    $field = $map[$action] ?? '';
    if ($field === '') return false;
    foreach (['perm.settings.invoice','perm.settings'] as $key) {
        if (isset($_SESSION['permissions'][$key][$field])) return (int) $_SESSION['permissions'][$key][$field] === 1;
    }
    $businessId = (int) ($_SESSION['business_id'] ?? 0);
    $roleId = (int) ($_SESSION['role_id'] ?? 0);
    if ($businessId <= 0 || $roleId <= 0) return false;
    $sql = "SELECT rp.`{$field}` FROM role_permissions rp INNER JOIN permissions p ON p.id=rp.permission_id WHERE rp.business_id=? AND rp.role_id=? AND p.permission_code IN ('perm.settings.invoice','perm.settings') ORDER BY FIELD(p.permission_code,'perm.settings.invoice','perm.settings') LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;
    $stmt->bind_param('ii', $businessId, $roleId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row[$field] ?? 0) === 1;
}

function uploadImage(string $field, int $businessId, string $folder): ?string
{
    if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Unable to upload ' . str_replace('_',' ',$field) . '.');
    if ((int) $_FILES[$field]['size'] > 4 * 1024 * 1024) throw new RuntimeException('Each image must be smaller than 4 MB.');
    $allowed = ['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES[$field]['tmp_name']);
    if (!isset($allowed[$mime])) throw new RuntimeException('Only PNG, JPG and WEBP images are allowed.');
    $relativeDir = 'uploads/business/' . $businessId . '/' . $folder;
    $absoluteDir = dirname(__DIR__) . '/' . $relativeDir;
    if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) throw new RuntimeException('Unable to create upload directory.');
    $filename = $field . '-' . bin2hex(random_bytes(6)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $absoluteDir . '/' . $filename)) throw new RuntimeException('Unable to save uploaded image.');
    return $relativeDir . '/' . $filename;
}

function audit(mysqli $conn, int $businessId, int $branchId, int $userId, string $action, int $referenceId, string $description, $oldValues = null, $newValues = null): void
{
    $stmt = $conn->prepare('INSERT INTO audit_logs (business_id, branch_id, user_id, module_code, action_type, reference_table, reference_id, description, old_values_json, new_values_json, ip_address, user_agent) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
    if (!$stmt) return;
    $module = 'settings.invoice';
    $table = 'invoice_settings';
    $oldJson = $oldValues === null ? null : json_encode($oldValues, JSON_UNESCAPED_UNICODE);
    $newJson = $newValues === null ? null : json_encode($newValues, JSON_UNESCAPED_UNICODE);
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $stmt->bind_param('iiisssisssss', $businessId, $branchId, $userId, $module, $action, $table, $referenceId, $description, $oldJson, $newJson, $ip, $ua);
    $stmt->execute();
    $stmt->close();
}

$businessId = (int) ($_SESSION['business_id'] ?? 0);
$branchIdSession = (int) ($_SESSION['branch_id'] ?? 0);
$userId = (int) ($_SESSION['user_id'] ?? 0);
$action = (string) ($_POST['action'] ?? '');

if ($businessId <= 0) respond(false, 'A valid business must be selected.', [], 403);

if ($action === 'get') {
    if (!hasPermission($conn, 'view') && !hasPermission($conn, 'open')) respond(false, 'You do not have permission to view this setting.', [], 403);
    $id = (int) ($_POST['setting_id'] ?? 0);
    $stmt = $conn->prepare('SELECT * FROM invoice_settings WHERE id = ? AND business_id = ? LIMIT 1');
    $stmt->bind_param('ii', $id, $businessId);
    $stmt->execute();
    $setting = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$setting) respond(false, 'Invoice setting not found.', [], 404);
    respond(true, 'Invoice setting loaded.', ['setting' => $setting]);
}

if ($action === 'delete') {
    if (!hasPermission($conn, 'delete')) respond(false, 'You do not have permission to delete invoice settings.', [], 403);
    $id = (int) ($_POST['setting_id'] ?? 0);
    $stmt = $conn->prepare('SELECT * FROM invoice_settings WHERE id = ? AND business_id = ? LIMIT 1');
    $stmt->bind_param('ii', $id, $businessId);
    $stmt->execute();
    $old = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$old) respond(false, 'Invoice setting not found.', [], 404);
    if ((int) $old['is_default'] === 1) respond(false, 'The default invoice setting cannot be deleted. Set another setting as default first.');
    $stmt = $conn->prepare('DELETE FROM invoice_settings WHERE id = ? AND business_id = ?');
    $stmt->bind_param('ii', $id, $businessId);
    $stmt->execute();
    $stmt->close();
    audit($conn, $businessId, $branchIdSession, $userId, 'Delete', $id, 'Deleted invoice setting', $old, null);
    respond(true, 'Invoice setting deleted successfully.');
}

if ($action !== 'save') respond(false, 'Invalid action.', [], 400);

$id = (int) ($_POST['setting_id'] ?? 0);
if ($id > 0 && !hasPermission($conn, 'update')) respond(false, 'You do not have permission to update invoice settings.', [], 403);
if ($id <= 0 && !hasPermission($conn, 'create')) respond(false, 'You do not have permission to create invoice settings.', [], 403);

$documentTypes = ['Invoice','Estimate','Sales Return','Purchase','Purchase Return','Receipt','Pawn Receipt','Chit Receipt'];
$paperSizes = ['A4','A5','80mm','58mm','Custom'];
$orientations = ['Portrait','Landscape'];
$resetFrequencies = ['Never','Financial Year','Calendar Year','Monthly','Daily'];

$branchId = ($_POST['branch_id'] ?? '') === '' ? null : (int) $_POST['branch_id'];
$documentType = trim((string) ($_POST['document_type'] ?? 'Invoice'));
$settingName = trim((string) ($_POST['setting_name'] ?? ''));
$paperSize = trim((string) ($_POST['paper_size'] ?? 'A4'));
$orientation = trim((string) ($_POST['orientation'] ?? 'Portrait'));
$customWidth = ($_POST['custom_width_mm'] ?? '') === '' ? null : (float) $_POST['custom_width_mm'];
$customHeight = ($_POST['custom_height_mm'] ?? '') === '' ? null : (float) $_POST['custom_height_mm'];
$upiId = trim((string) ($_POST['upi_id'] ?? ''));
$headerText = trim((string) ($_POST['header_text'] ?? ''));
$footerText = trim((string) ($_POST['footer_text'] ?? ''));
$terms = trim((string) ($_POST['terms_conditions'] ?? ''));
$prefix = trim((string) ($_POST['prefix'] ?? 'INV'));
$middleFormat = trim((string) ($_POST['middle_format'] ?? '{FY_SHORT}'));
$suffix = trim((string) ($_POST['suffix'] ?? ''));
$splitter = trim((string) ($_POST['splitter_symbol'] ?? '/'));
$sequenceDigits = (int) ($_POST['sequence_digits'] ?? 3);
$sequenceStart = (int) ($_POST['sequence_start'] ?? 1);
$resetFrequency = trim((string) ($_POST['reset_frequency'] ?? 'Financial Year'));
$formatTemplate = trim((string) ($_POST['format_template'] ?? '{PREFIX}{SPLITTER}{FY_SHORT}{SPLITTER}{SEQ}'));
$sampleOutput = trim((string) ($_POST['sample_output'] ?? ''));
$isDefault = isset($_POST['is_default']) ? 1 : 0;
$isActive = isset($_POST['is_active']) ? 1 : 0;
$showBusinessLogo = isset($_POST['show_business_logo']) ? 1 : 0;
$showGstin = isset($_POST['show_gstin']) ? 1 : 0;
$showHsn = isset($_POST['show_hsn']) ? 1 : 0;
$showTaxBreakup = isset($_POST['show_tax_breakup']) ? 1 : 0;
$showCustomerBalance = isset($_POST['show_customer_balance']) ? 1 : 0;
$showQrCode = isset($_POST['show_qr_code']) ? 1 : 0;

if ($settingName === '') respond(false, 'Setting name is required.');
if (!in_array($documentType, $documentTypes, true)) respond(false, 'Invalid document type.');
if (!in_array($paperSize, $paperSizes, true)) respond(false, 'Invalid paper size.');
if (!in_array($orientation, $orientations, true)) respond(false, 'Invalid orientation.');
if (!in_array($resetFrequency, $resetFrequencies, true)) respond(false, 'Invalid reset frequency.');
if ($sequenceDigits < 1 || $sequenceDigits > 10) respond(false, 'Sequence digits must be between 1 and 10.');
if ($sequenceStart < 1) respond(false, 'Sequence start must be at least 1.');
if ($paperSize === 'Custom' && (($customWidth ?? 0) <= 0 || ($customHeight ?? 0) <= 0)) respond(false, 'Custom width and height are required for custom paper size.');
if ($branchId !== null) {
    $stmt = $conn->prepare('SELECT id FROM branches WHERE id = ? AND business_id = ? LIMIT 1');
    $stmt->bind_param('ii', $branchId, $businessId);
    $stmt->execute();
    $valid = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();
    if (!$valid) respond(false, 'Invalid branch selected.');
}

$old = null;
$invoiceLogoPath = null;
$signaturePath = null;
$stampPath = null;
if ($id > 0) {
    $stmt = $conn->prepare('SELECT * FROM invoice_settings WHERE id = ? AND business_id = ? LIMIT 1');
    $stmt->bind_param('ii', $id, $businessId);
    $stmt->execute();
    $old = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$old) respond(false, 'Invoice setting not found.', [], 404);
    $invoiceLogoPath = $old['invoice_logo_path'];
    $signaturePath = $old['signature_path'];
    $stampPath = $old['stamp_path'];
}

// Duplicate names are checked within the same business, document type and branch.
// The current record is excluded while editing.
if ($branchId === null) {
    $stmt = $conn->prepare('SELECT id FROM invoice_settings WHERE business_id = ? AND document_type = ? AND setting_name = ? AND branch_id IS NULL AND id <> ? LIMIT 1');
    $stmt->bind_param('issi', $businessId, $documentType, $settingName, $id);
} else {
    $stmt = $conn->prepare('SELECT id FROM invoice_settings WHERE business_id = ? AND document_type = ? AND setting_name = ? AND branch_id = ? AND id <> ? LIMIT 1');
    $stmt->bind_param('issii', $businessId, $documentType, $settingName, $branchId, $id);
}
$stmt->execute();
$duplicate = (bool) $stmt->get_result()->fetch_row();
$stmt->close();
if ($duplicate) respond(false, 'A setting with the same document type, name and branch already exists.');

try {
    $newLogo = uploadImage('invoice_logo', $businessId, 'invoice');
    $newSignature = uploadImage('signature', $businessId, 'invoice');
    $newStamp = uploadImage('stamp', $businessId, 'invoice');
    if ($newLogo !== null) $invoiceLogoPath = $newLogo;
    if ($newSignature !== null) $signaturePath = $newSignature;
    if ($newStamp !== null) $stampPath = $newStamp;

    $conn->begin_transaction();
    if ($isDefault === 1) {
        if ($branchId === null) {
            $stmt = $conn->prepare('UPDATE invoice_settings SET is_default = 0 WHERE business_id = ? AND document_type = ? AND branch_id IS NULL AND id <> ?');
            $stmt->bind_param('isi', $businessId, $documentType, $id);
        } else {
            $stmt = $conn->prepare('UPDATE invoice_settings SET is_default = 0 WHERE business_id = ? AND document_type = ? AND branch_id = ? AND id <> ?');
            $stmt->bind_param('isii', $businessId, $documentType, $branchId, $id);
        }
        $stmt->execute();
        $stmt->close();
    }

    if ($id > 0) {
        $sql = 'UPDATE invoice_settings SET branch_id=?, document_type=?, setting_name=?, paper_size=?, orientation=?, custom_width_mm=?, custom_height_mm=?, invoice_logo_path=?, signature_path=?, stamp_path=?, show_business_logo=?, show_gstin=?, show_hsn=?, show_tax_breakup=?, show_customer_balance=?, show_qr_code=?, upi_id=?, header_text=?, footer_text=?, terms_conditions=?, prefix=?, middle_format=?, suffix=?, splitter_symbol=?, sequence_digits=?, sequence_start=?, reset_frequency=?, format_template=?, sample_output=?, is_default=?, is_active=? WHERE id=? AND business_id=?';
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('issssddsssiiiiiissssssssiisssiiii', $branchId, $documentType, $settingName, $paperSize, $orientation, $customWidth, $customHeight, $invoiceLogoPath, $signaturePath, $stampPath, $showBusinessLogo, $showGstin, $showHsn, $showTaxBreakup, $showCustomerBalance, $showQrCode, $upiId, $headerText, $footerText, $terms, $prefix, $middleFormat, $suffix, $splitter, $sequenceDigits, $sequenceStart, $resetFrequency, $formatTemplate, $sampleOutput, $isDefault, $isActive, $id, $businessId);
        $stmt->execute();
        $stmt->close();
        $referenceId = $id;
        $actionType = 'Update';
        $message = 'Invoice setting updated successfully.';
    } else {
        $sql = 'INSERT INTO invoice_settings (business_id, branch_id, document_type, setting_name, paper_size, orientation, custom_width_mm, custom_height_mm, invoice_logo_path, signature_path, stamp_path, show_business_logo, show_gstin, show_hsn, show_tax_breakup, show_customer_balance, show_qr_code, upi_id, header_text, footer_text, terms_conditions, prefix, middle_format, suffix, splitter_symbol, sequence_digits, sequence_start, reset_frequency, format_template, sample_output, is_default, is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('iissssddsssiiiiiissssssssiisssii', $businessId, $branchId, $documentType, $settingName, $paperSize, $orientation, $customWidth, $customHeight, $invoiceLogoPath, $signaturePath, $stampPath, $showBusinessLogo, $showGstin, $showHsn, $showTaxBreakup, $showCustomerBalance, $showQrCode, $upiId, $headerText, $footerText, $terms, $prefix, $middleFormat, $suffix, $splitter, $sequenceDigits, $sequenceStart, $resetFrequency, $formatTemplate, $sampleOutput, $isDefault, $isActive);
        $stmt->execute();
        $referenceId = (int) $conn->insert_id;
        $stmt->close();
        $actionType = 'Create';
        $message = 'Invoice setting created successfully.';
    }

    $newValues = [
        'branch_id'=>$branchId,'document_type'=>$documentType,'setting_name'=>$settingName,'paper_size'=>$paperSize,'orientation'=>$orientation,
        'custom_width_mm'=>$customWidth,'custom_height_mm'=>$customHeight,'invoice_logo_path'=>$invoiceLogoPath,'signature_path'=>$signaturePath,
        'stamp_path'=>$stampPath,'show_business_logo'=>$showBusinessLogo,'show_gstin'=>$showGstin,'show_hsn'=>$showHsn,'show_tax_breakup'=>$showTaxBreakup,
        'show_customer_balance'=>$showCustomerBalance,'show_qr_code'=>$showQrCode,'upi_id'=>$upiId,'header_text'=>$headerText,'footer_text'=>$footerText,
        'terms_conditions'=>$terms,'prefix'=>$prefix,'middle_format'=>$middleFormat,'suffix'=>$suffix,'splitter_symbol'=>$splitter,
        'sequence_digits'=>$sequenceDigits,'sequence_start'=>$sequenceStart,'reset_frequency'=>$resetFrequency,'format_template'=>$formatTemplate,
        'sample_output'=>$sampleOutput,'is_default'=>$isDefault,'is_active'=>$isActive
    ];
    audit($conn, $businessId, $branchIdSession, $userId, $actionType, $referenceId, $message, $old, $newValues);
    $conn->commit();
    respond(true, $message, ['setting_id'=>$referenceId]);
} catch (Throwable $e) {
    if ($conn->errno || $conn->sqlstate) {
        $conn->rollback();
    }
    respond(false, $e->getMessage() ?: 'Unable to save invoice setting.', [], 500);
}
