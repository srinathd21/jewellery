<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string) ($_SESSION['timezone'] ?? 'Asia/Kolkata'));
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Method not allowed.', [], 405);
}
if (empty($_SESSION['user_id'])) {
    respond(false, 'Your session has expired. Please log in again.', [], 401);
}
if (!hash_equals((string) ($_SESSION['invoice_settings_csrf'] ?? ''), (string) ($_POST['csrf_token'] ?? ''))) {
    respond(false, 'Invalid security token. Refresh the page and try again.', [], 419);
}

function prepareStmt(mysqli $conn, string $sql)
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Database prepare failed: ' . $conn->error);
    }
    return $stmt;
}

function executeStmt($stmt, string $message = 'Database operation failed.'): void
{
    if (!$stmt->execute()) {
        $error = $stmt->error;
        throw new RuntimeException($message . ($error !== '' ? ' ' . $error : ''));
    }
}

function hasPermission(mysqli $conn, string $action): bool
{
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin') {
        return true;
    }

    $map = [
        'open' => 'can_open',
        'view' => 'can_view',
        'create' => 'can_create',
        'update' => 'can_update',
        'delete' => 'can_delete',
    ];
    $field = $map[$action] ?? '';
    if ($field === '') {
        return false;
    }

    foreach (['perm.settings.invoice', 'perm.settings'] as $key) {
        if (isset($_SESSION['permissions'][$key][$field])) {
            return (int) $_SESSION['permissions'][$key][$field] === 1;
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
              AND p.permission_code IN ('perm.settings.invoice','perm.settings')
            ORDER BY FIELD(p.permission_code,'perm.settings.invoice','perm.settings')
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ii', $businessId, $roleId);
    if (!$stmt->execute()) {
        $stmt->close();
        return false;
    }
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row[$field] ?? 0) === 1;
}

function textLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function buildSampleOutput(string $prefix, string $middleFormat, string $suffix, string $splitter, int $sequenceDigits, int $sequenceStart, string $formatTemplate): string
{
    $now = new DateTime('now');
    $year = (int) $now->format('Y');
    $month = (int) $now->format('n');
    $fyStart = $month >= 4 ? $year : $year - 1;
    $fyEnd = $fyStart + 1;

    $dateTokens = [
        '{YYYY}' => (string) $year,
        '{YY}' => substr((string) $year, -2),
        '{FY_SHORT}' => substr((string) $fyStart, -2) . '-' . substr((string) $fyEnd, -2),
        '{FY_2DIGIT}' => substr((string) $fyStart, -2) . substr((string) $fyEnd, -2),
        '{MM}' => $now->format('m'),
        '{DD}' => $now->format('d'),
        '{SPLITTER}' => $splitter,
    ];

    $resolvedMiddle = strtr($middleFormat, $dateTokens);
    $sequence = str_pad((string) $sequenceStart, max(1, $sequenceDigits), '0', STR_PAD_LEFT);

    $tokens = $dateTokens + [
        '{PREFIX}' => $prefix,
        '{MIDDLE}' => $resolvedMiddle,
        '{SEQ}' => $sequence,
        '{SUFFIX}' => $suffix,
    ];

    return strtr($formatTemplate, $tokens);
}

function uploadImage(string $field, int $businessId, string $folder): ?string
{
    if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ((int) $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Unable to upload ' . str_replace('_', ' ', $field) . '.');
    }
    if ((int) $_FILES[$field]['size'] <= 0 || (int) $_FILES[$field]['size'] > 4 * 1024 * 1024) {
        throw new RuntimeException('Each image must be smaller than 4 MB.');
    }

    $tmp = (string) $_FILES[$field]['tmp_name'];
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Invalid uploaded file.');
    }

    $mime = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = (string) finfo_file($finfo, $tmp);
            finfo_close($finfo);
        }
    }
    if ($mime === '' && function_exists('mime_content_type')) {
        $mime = (string) mime_content_type($tmp);
    }

    $allowed = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Only PNG, JPG and WEBP images are allowed.');
    }

    $relativeDir = 'uploads/business/' . $businessId . '/' . $folder;
    $absoluteDir = dirname(__DIR__) . '/' . $relativeDir;
    if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) {
        throw new RuntimeException('Unable to create upload directory.');
    }

    $filename = $field . '-' . bin2hex(random_bytes(6)) . '.' . $allowed[$mime];
    $absolutePath = $absoluteDir . '/' . $filename;
    if (!move_uploaded_file($tmp, $absolutePath)) {
        throw new RuntimeException('Unable to save uploaded image.');
    }
    return $relativeDir . '/' . $filename;
}

function removeUploadedFile(string $relativePath, int $businessId): void
{
    $relativePath = str_replace('\\', '/', trim($relativePath));
    if ($relativePath === '') {
        return;
    }

    $allowedPrefix = 'uploads/business/' . $businessId . '/invoice/';
    if (strpos($relativePath, $allowedPrefix) !== 0) {
        return;
    }

    $root = realpath(dirname(__DIR__));
    if ($root === false) {
        return;
    }
    $absolutePath = $root . '/' . $relativePath;
    if (is_file($absolutePath)) {
        @unlink($absolutePath);
    }
}

function filePathUsedElsewhere(mysqli $conn, int $businessId, string $path, int $excludeId = 0): bool
{
    if ($path === '') {
        return false;
    }
    $stmt = $conn->prepare('SELECT id FROM invoice_settings WHERE business_id=? AND id<>? AND (invoice_logo_path=? OR signature_path=? OR stamp_path=?) LIMIT 1');
    if (!$stmt) {
        return true;
    }
    $stmt->bind_param('iisss', $businessId, $excludeId, $path, $path, $path);
    if (!$stmt->execute()) {
        $stmt->close();
        return true;
    }
    $used = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();
    return $used;
}

function removeStoredFileIfUnused(mysqli $conn, int $businessId, string $path, int $excludeId = 0): void
{
    if ($path === '' || filePathUsedElsewhere($conn, $businessId, $path, $excludeId)) {
        return;
    }
    removeUploadedFile($path, $businessId);
}

function audit(mysqli $conn, int $businessId, $branchId, int $userId, string $action, int $referenceId, string $description, $oldValues = null, $newValues = null): void
{
    $stmt = $conn->prepare('INSERT INTO audit_logs (business_id, branch_id, user_id, module_code, action_type, reference_table, reference_id, description, old_values_json, new_values_json, ip_address, user_agent) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
    if (!$stmt) {
        return;
    }

    $module = 'settings.invoice';
    $table = 'invoice_settings';
    $oldJson = $oldValues === null ? null : json_encode($oldValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $newJson = $newValues === null ? null : json_encode($newValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : null;
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 500) : null;

    $stmt->bind_param('iiisssisssss', $businessId, $branchId, $userId, $module, $action, $table, $referenceId, $description, $oldJson, $newJson, $ip, $ua);
    $stmt->execute();
    $stmt->close();
}

function findDefaultId(mysqli $conn, int $businessId, ?int $branchId, string $documentType, int $excludeId = 0): int
{
    if ($branchId === null) {
        $stmt = prepareStmt($conn, 'SELECT id FROM invoice_settings WHERE business_id=? AND branch_id IS NULL AND document_type=? AND is_default=1 AND is_active=1 AND id<>? ORDER BY id LIMIT 1');
        $stmt->bind_param('isi', $businessId, $documentType, $excludeId);
    } else {
        $stmt = prepareStmt($conn, 'SELECT id FROM invoice_settings WHERE business_id=? AND branch_id=? AND document_type=? AND is_default=1 AND is_active=1 AND id<>? ORDER BY id LIMIT 1');
        $stmt->bind_param('iisi', $businessId, $branchId, $documentType, $excludeId);
    }
    executeStmt($stmt, 'Unable to check default invoice setting.');
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row['id'] ?? 0);
}

$businessId = (int) ($_SESSION['business_id'] ?? 0);
$branchIdSessionRaw = (int) ($_SESSION['branch_id'] ?? 0);
$auditBranchId = $branchIdSessionRaw > 0 ? $branchIdSessionRaw : null;
$userId = (int) ($_SESSION['user_id'] ?? 0);
$action = trim((string) ($_POST['action'] ?? ''));

if ($businessId <= 0) {
    respond(false, 'A valid business must be selected.', [], 403);
}

if ($action === 'get') {
    if (!hasPermission($conn, 'view') && !hasPermission($conn, 'open')) {
        respond(false, 'You do not have permission to view this setting.', [], 403);
    }
    $id = max(0, (int) ($_POST['setting_id'] ?? 0));
    if ($id <= 0) {
        respond(false, 'Invalid invoice setting.', [], 422);
    }

    try {
        $stmt = prepareStmt($conn, 'SELECT * FROM invoice_settings WHERE id=? AND business_id=? LIMIT 1');
        $stmt->bind_param('ii', $id, $businessId);
        executeStmt($stmt, 'Unable to load invoice setting.');
        $setting = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$setting) {
            respond(false, 'Invoice setting not found.', [], 404);
        }
        respond(true, 'Invoice setting loaded.', ['setting' => $setting]);
    } catch (Throwable $e) {
        respond(false, $e->getMessage(), [], 500);
    }
}

if ($action === 'delete') {
    if (!hasPermission($conn, 'delete')) {
        respond(false, 'You do not have permission to delete invoice settings.', [], 403);
    }
    $id = max(0, (int) ($_POST['setting_id'] ?? 0));
    if ($id <= 0) {
        respond(false, 'Invalid invoice setting.', [], 422);
    }

    $transactionStarted = false;
    try {
        $stmt = prepareStmt($conn, 'SELECT * FROM invoice_settings WHERE id=? AND business_id=? LIMIT 1');
        $stmt->bind_param('ii', $id, $businessId);
        executeStmt($stmt, 'Unable to load invoice setting.');
        $old = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$old) {
            respond(false, 'Invoice setting not found.', [], 404);
        }
        if ((int) $old['is_default'] === 1) {
            respond(false, 'The default invoice setting cannot be deleted. Set another active setting as default first.', [], 422);
        }

        $conn->begin_transaction();
        $transactionStarted = true;

        $stmt = prepareStmt($conn, 'DELETE FROM invoice_settings WHERE id=? AND business_id=?');
        $stmt->bind_param('ii', $id, $businessId);
        executeStmt($stmt, 'Unable to delete invoice setting.');
        $stmt->close();

        audit($conn, $businessId, $auditBranchId, $userId, 'Delete', $id, 'Deleted invoice setting', $old, null);
        $conn->commit();
        $transactionStarted = false;

        foreach (['invoice_logo_path', 'signature_path', 'stamp_path'] as $field) {
            removeStoredFileIfUnused($conn, $businessId, (string) ($old[$field] ?? ''), $id);
        }

        respond(true, 'Invoice setting deleted successfully.');
    } catch (Throwable $e) {
        if ($transactionStarted) {
            $conn->rollback();
        }
        respond(false, $e->getMessage() ?: 'Unable to delete invoice setting.', [], 500);
    }
}

if ($action !== 'save') {
    respond(false, 'Invalid action.', [], 400);
}

$id = max(0, (int) ($_POST['setting_id'] ?? 0));
if ($id > 0 && !hasPermission($conn, 'update')) {
    respond(false, 'You do not have permission to update invoice settings.', [], 403);
}
if ($id <= 0 && !hasPermission($conn, 'create')) {
    respond(false, 'You do not have permission to create invoice settings.', [], 403);
}

$documentTypes = ['Invoice', 'Estimate', 'Sales Return', 'Purchase', 'Purchase Return', 'Receipt', 'Pawn Receipt', 'Chit Receipt'];
$paperSizes = ['A4', 'A5', '80mm', '58mm', 'Custom'];
$orientations = ['Portrait', 'Landscape'];
$resetFrequencies = ['Never', 'Financial Year', 'Calendar Year', 'Monthly', 'Daily'];

$branchId = ($_POST['branch_id'] ?? '') === '' ? null : max(0, (int) $_POST['branch_id']);
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
$formatTemplate = trim((string) ($_POST['format_template'] ?? '{PREFIX}{SPLITTER}{MIDDLE}{SPLITTER}{SEQ}{SUFFIX}'));
$isDefault = isset($_POST['is_default']) ? 1 : 0;
$isActive = isset($_POST['is_active']) ? 1 : 0;
$showBusinessLogo = isset($_POST['show_business_logo']) ? 1 : 0;
$showGstin = isset($_POST['show_gstin']) ? 1 : 0;
$showHsn = isset($_POST['show_hsn']) ? 1 : 0;
$showTaxBreakup = isset($_POST['show_tax_breakup']) ? 1 : 0;
$showCustomerBalance = isset($_POST['show_customer_balance']) ? 1 : 0;
$showQrCode = isset($_POST['show_qr_code']) ? 1 : 0;
$removeInvoiceLogo = !empty($_POST['remove_invoice_logo']);
$removeSignature = !empty($_POST['remove_signature']);
$removeStamp = !empty($_POST['remove_stamp']);

if ($settingName === '') respond(false, 'Setting name is required.', [], 422);
if (textLength($settingName) > 100) respond(false, 'Setting name cannot exceed 100 characters.', [], 422);
if (!in_array($documentType, $documentTypes, true)) respond(false, 'Invalid document type.', [], 422);
if (!in_array($paperSize, $paperSizes, true)) respond(false, 'Invalid paper size.', [], 422);
if (!in_array($orientation, $orientations, true)) respond(false, 'Invalid orientation.', [], 422);
if (!in_array($resetFrequency, $resetFrequencies, true)) respond(false, 'Invalid reset frequency.', [], 422);
if ($sequenceDigits < 1 || $sequenceDigits > 10) respond(false, 'Sequence digits must be between 1 and 10.', [], 422);
if ($sequenceStart < 1) respond(false, 'Sequence start must be at least 1.', [], 422);
if (textLength($prefix) > 30) respond(false, 'Prefix cannot exceed 30 characters.', [], 422);
if (textLength($middleFormat) > 80) respond(false, 'Middle format cannot exceed 80 characters.', [], 422);
if (textLength($suffix) > 30) respond(false, 'Suffix cannot exceed 30 characters.', [], 422);
if (textLength($splitter) > 5) respond(false, 'Splitter cannot exceed 5 characters.', [], 422);
if (textLength($formatTemplate) > 150) respond(false, 'Format template cannot exceed 150 characters.', [], 422);
if (strpos($formatTemplate, '{SEQ}') === false) respond(false, 'Format template must contain the {SEQ} token.', [], 422);
if (textLength($upiId) > 120) respond(false, 'UPI ID cannot exceed 120 characters.', [], 422);
if ($paperSize === 'Custom' && (($customWidth ?? 0) <= 0 || ($customHeight ?? 0) <= 0)) {
    respond(false, 'Custom width and height are required for custom paper size.', [], 422);
}
if ($paperSize !== 'Custom') {
    $customWidth = null;
    $customHeight = null;
}
if ($branchId !== null && $branchId <= 0) {
    respond(false, 'Invalid branch selected.', [], 422);
}

$sampleOutput = buildSampleOutput($prefix, $middleFormat, $suffix, $splitter, $sequenceDigits, $sequenceStart, $formatTemplate);
if (textLength($sampleOutput) > 150) {
    respond(false, 'Generated sample output exceeds 150 characters. Shorten the numbering format.', [], 422);
}

$old = null;
$invoiceLogoPath = null;
$signaturePath = null;
$stampPath = null;

try {
    if ($branchId !== null) {
        $stmt = prepareStmt($conn, 'SELECT id FROM branches WHERE id=? AND business_id=? AND is_active=1 LIMIT 1');
        $stmt->bind_param('ii', $branchId, $businessId);
        executeStmt($stmt, 'Unable to validate branch.');
        $validBranch = (bool) $stmt->get_result()->fetch_row();
        $stmt->close();
        if (!$validBranch) {
            respond(false, 'Invalid or inactive branch selected.', [], 422);
        }
    }

    if ($id > 0) {
        $stmt = prepareStmt($conn, 'SELECT * FROM invoice_settings WHERE id=? AND business_id=? LIMIT 1');
        $stmt->bind_param('ii', $id, $businessId);
        executeStmt($stmt, 'Unable to load invoice setting for update.');
        $old = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$old) {
            respond(false, 'Invoice setting not found.', [], 404);
        }
        $invoiceLogoPath = $old['invoice_logo_path'] ?? null;
        $signaturePath = $old['signature_path'] ?? null;
        $stampPath = $old['stamp_path'] ?? null;
    }

    if ($branchId === null) {
        $stmt = prepareStmt($conn, 'SELECT id FROM invoice_settings WHERE business_id=? AND document_type=? AND setting_name=? AND branch_id IS NULL AND id<>? LIMIT 1');
        $stmt->bind_param('issi', $businessId, $documentType, $settingName, $id);
    } else {
        $stmt = prepareStmt($conn, 'SELECT id FROM invoice_settings WHERE business_id=? AND document_type=? AND setting_name=? AND branch_id=? AND id<>? LIMIT 1');
        $stmt->bind_param('issii', $businessId, $documentType, $settingName, $branchId, $id);
    }
    executeStmt($stmt, 'Unable to check duplicate invoice settings.');
    $duplicate = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();
    if ($duplicate) {
        respond(false, 'A setting with the same document type, name and branch already exists.', [], 422);
    }

    $otherDefaultId = findDefaultId($conn, $businessId, $branchId, $documentType, $id);
    if ($isDefault === 1) {
        $isActive = 1;
    } elseif ($id <= 0 && $isActive === 1 && $otherDefaultId <= 0) {
        $isDefault = 1;
    } elseif ($id > 0 && $old && (int) ($old['is_default'] ?? 0) === 1) {
        respond(false, 'This is the current default setting. Set another active setting as default before removing its default status.', [], 422);
    }
} catch (Throwable $e) {
    respond(false, $e->getMessage(), [], 500);
}

$uploadedPaths = [];
$transactionStarted = false;
$newLogo = null;
$newSignature = null;
$newStamp = null;

try {
    $newLogo = uploadImage('invoice_logo', $businessId, 'invoice');
    if ($newLogo !== null) {
        $uploadedPaths[] = $newLogo;
        $invoiceLogoPath = $newLogo;
    } elseif ($removeInvoiceLogo) {
        $invoiceLogoPath = null;
    }

    $newSignature = uploadImage('signature', $businessId, 'invoice');
    if ($newSignature !== null) {
        $uploadedPaths[] = $newSignature;
        $signaturePath = $newSignature;
    } elseif ($removeSignature) {
        $signaturePath = null;
    }

    $newStamp = uploadImage('stamp', $businessId, 'invoice');
    if ($newStamp !== null) {
        $uploadedPaths[] = $newStamp;
        $stampPath = $newStamp;
    } elseif ($removeStamp) {
        $stampPath = null;
    }

    $conn->begin_transaction();
    $transactionStarted = true;

    if ($isDefault === 1) {
        if ($branchId === null) {
            $stmt = prepareStmt($conn, 'UPDATE invoice_settings SET is_default=0 WHERE business_id=? AND document_type=? AND branch_id IS NULL AND id<>?');
            $stmt->bind_param('isi', $businessId, $documentType, $id);
        } else {
            $stmt = prepareStmt($conn, 'UPDATE invoice_settings SET is_default=0 WHERE business_id=? AND document_type=? AND branch_id=? AND id<>?');
            $stmt->bind_param('isii', $businessId, $documentType, $branchId, $id);
        }
        executeStmt($stmt, 'Unable to update default invoice setting.');
        $stmt->close();
    }

    if ($id > 0) {
        $sql = 'UPDATE invoice_settings SET branch_id=?, document_type=?, setting_name=?, paper_size=?, orientation=?, custom_width_mm=?, custom_height_mm=?, invoice_logo_path=?, signature_path=?, stamp_path=?, show_business_logo=?, show_gstin=?, show_hsn=?, show_tax_breakup=?, show_customer_balance=?, show_qr_code=?, upi_id=?, header_text=?, footer_text=?, terms_conditions=?, prefix=?, middle_format=?, suffix=?, splitter_symbol=?, sequence_digits=?, sequence_start=?, reset_frequency=?, format_template=?, sample_output=?, is_default=?, is_active=? WHERE id=? AND business_id=?';
        $stmt = prepareStmt($conn, $sql);
        $stmt->bind_param('issssddsssiiiiiissssssssiisssiiii', $branchId, $documentType, $settingName, $paperSize, $orientation, $customWidth, $customHeight, $invoiceLogoPath, $signaturePath, $stampPath, $showBusinessLogo, $showGstin, $showHsn, $showTaxBreakup, $showCustomerBalance, $showQrCode, $upiId, $headerText, $footerText, $terms, $prefix, $middleFormat, $suffix, $splitter, $sequenceDigits, $sequenceStart, $resetFrequency, $formatTemplate, $sampleOutput, $isDefault, $isActive, $id, $businessId);
        executeStmt($stmt, 'Unable to update invoice setting.');
        $stmt->close();
        $referenceId = $id;
        $actionType = 'Update';
        $message = 'Invoice setting updated successfully.';
    } else {
        $sql = 'INSERT INTO invoice_settings (business_id, branch_id, document_type, setting_name, paper_size, orientation, custom_width_mm, custom_height_mm, invoice_logo_path, signature_path, stamp_path, show_business_logo, show_gstin, show_hsn, show_tax_breakup, show_customer_balance, show_qr_code, upi_id, header_text, footer_text, terms_conditions, prefix, middle_format, suffix, splitter_symbol, sequence_digits, sequence_start, reset_frequency, format_template, sample_output, is_default, is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
        $stmt = prepareStmt($conn, $sql);
        $stmt->bind_param('iissssddsssiiiiiissssssssiisssii', $businessId, $branchId, $documentType, $settingName, $paperSize, $orientation, $customWidth, $customHeight, $invoiceLogoPath, $signaturePath, $stampPath, $showBusinessLogo, $showGstin, $showHsn, $showTaxBreakup, $showCustomerBalance, $showQrCode, $upiId, $headerText, $footerText, $terms, $prefix, $middleFormat, $suffix, $splitter, $sequenceDigits, $sequenceStart, $resetFrequency, $formatTemplate, $sampleOutput, $isDefault, $isActive);
        executeStmt($stmt, 'Unable to create invoice setting.');
        $referenceId = (int) $conn->insert_id;
        $stmt->close();
        $actionType = 'Create';
        $message = 'Invoice setting created successfully.';
    }

    $newValues = [
        'branch_id' => $branchId,
        'document_type' => $documentType,
        'setting_name' => $settingName,
        'paper_size' => $paperSize,
        'orientation' => $orientation,
        'custom_width_mm' => $customWidth,
        'custom_height_mm' => $customHeight,
        'invoice_logo_path' => $invoiceLogoPath,
        'signature_path' => $signaturePath,
        'stamp_path' => $stampPath,
        'show_business_logo' => $showBusinessLogo,
        'show_gstin' => $showGstin,
        'show_hsn' => $showHsn,
        'show_tax_breakup' => $showTaxBreakup,
        'show_customer_balance' => $showCustomerBalance,
        'show_qr_code' => $showQrCode,
        'upi_id' => $upiId,
        'header_text' => $headerText,
        'footer_text' => $footerText,
        'terms_conditions' => $terms,
        'prefix' => $prefix,
        'middle_format' => $middleFormat,
        'suffix' => $suffix,
        'splitter_symbol' => $splitter,
        'sequence_digits' => $sequenceDigits,
        'sequence_start' => $sequenceStart,
        'reset_frequency' => $resetFrequency,
        'format_template' => $formatTemplate,
        'sample_output' => $sampleOutput,
        'is_default' => $isDefault,
        'is_active' => $isActive,
    ];

    audit($conn, $businessId, $auditBranchId, $userId, $actionType, $referenceId, $message, $old, $newValues);
    $conn->commit();
    $transactionStarted = false;

    if ($old) {
        $oldLogo = (string) ($old['invoice_logo_path'] ?? '');
        $oldSignature = (string) ($old['signature_path'] ?? '');
        $oldStamp = (string) ($old['stamp_path'] ?? '');

        if ($oldLogo !== '' && $oldLogo !== (string) $invoiceLogoPath) {
            removeStoredFileIfUnused($conn, $businessId, $oldLogo, $referenceId);
        }
        if ($oldSignature !== '' && $oldSignature !== (string) $signaturePath) {
            removeStoredFileIfUnused($conn, $businessId, $oldSignature, $referenceId);
        }
        if ($oldStamp !== '' && $oldStamp !== (string) $stampPath) {
            removeStoredFileIfUnused($conn, $businessId, $oldStamp, $referenceId);
        }
    }

    respond(true, $message, [
        'setting_id' => $referenceId,
        'sample_output' => $sampleOutput,
        'is_default' => $isDefault,
        'is_active' => $isActive,
    ]);
} catch (Throwable $e) {
    if ($transactionStarted) {
        $conn->rollback();
    }
    foreach ($uploadedPaths as $path) {
        removeUploadedFile((string) $path, $businessId);
    }
    respond(false, $e->getMessage() ?: 'Unable to save invoice setting.', [], 500);
}
