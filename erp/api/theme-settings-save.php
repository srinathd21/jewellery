<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

function respond(bool $success, string $message, int $status = 200, array $extra = []): never
{
    http_response_code($status);
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
    ], $extra), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Invalid request method.', 405);
}

if (empty($_SESSION['user_id'])) {
    respond(false, 'Your login session has expired. Please log in again.', 401);
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
    respond(false, 'Database configuration is not available.', 500);
}

$conn->set_charset('utf8mb4');

function tableExists(mysqli $conn, string $table): bool
{
    $safe = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $result && $result->num_rows > 0;
}

function tableColumns(mysqli $conn, string $table): array
{
    $columns = [];
    $result = $conn->query("SHOW COLUMNS FROM `{$table}`");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $columns[(string)$row['Field']] = true;
        }
    }
    return $columns;
}

function hasThemeUpdatePermission(mysqli $conn): bool
{
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin') {
        return true;
    }

    $sessionPermissions = $_SESSION['permissions'] ?? [];
    foreach (['perm.theme_settings', 'perm.business_settings', 'perm.settings'] as $key) {
        if (isset($sessionPermissions[$key]['can_update'])) {
            return (int)$sessionPermissions[$key]['can_update'] === 1;
        }
    }

    $businessId = (int)($_SESSION['business_id'] ?? 0);
    $roleId = (int)($_SESSION['role_id'] ?? 0);
    if ($businessId <= 0 || $roleId <= 0) {
        return false;
    }

    $sql = "SELECT rp.can_update
            FROM role_permissions rp
            INNER JOIN permissions p ON p.id = rp.permission_id
            WHERE rp.business_id = ?
              AND rp.role_id = ?
              AND p.is_active = 1
              AND p.permission_key IN ('perm.theme_settings','perm.business_settings','perm.settings')
            ORDER BY FIELD(p.permission_key,'perm.theme_settings','perm.business_settings','perm.settings')
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ii', $businessId, $roleId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row['can_update'] ?? 0) === 1;
}

if (!hasThemeUpdatePermission($conn)) {
    respond(false, 'You do not have permission to update theme settings.', 403);
}

$businessId = (int)($_SESSION['business_id'] ?? 0);
if ($businessId <= 0) {
    respond(false, 'A valid business must be selected.', 403);
}

$sessionToken = (string)($_SESSION['theme_settings_csrf'] ?? '');
$postedToken = (string)($_POST['csrf_token'] ?? '');
if ($sessionToken === '' || $postedToken === '' || !hash_equals($sessionToken, $postedToken)) {
    respond(false, 'Invalid or expired request token. Refresh the page and try again.', 419);
}

if (!tableExists($conn, 'business_theme_settings')) {
    respond(false, 'Required table business_theme_settings does not exist.', 500);
}

$availableColumns = tableColumns($conn, 'business_theme_settings');

$defaults = [
    'primary_color' => '#d89416',
    'primary_dark_color' => '#b86a0b',
    'primary_soft_color' => '#fff6e5',
    'sidebar_gradient_1' => '#171c21',
    'sidebar_gradient_2' => '#20272d',
    'sidebar_gradient_3' => '#101419',
    'page_background' => '#f4f3f0',
    'card_background' => '#ffffff',
    'text_color' => '#171717',
    'muted_text_color' => '#7d8794',
    'border_color' => '#e8e8e8',
    'font_family' => 'Inter',
    'heading_font_family' => 'Playfair Display',
    'border_radius_px' => 12,
    'sidebar_width_px' => 205,
];

$themeData = [];
foreach ($defaults as $field => $default) {
    $themeData[$field] = $_POST[$field] ?? $default;
}

$hexFields = [
    'primary_color', 'primary_dark_color', 'primary_soft_color',
    'sidebar_gradient_1', 'sidebar_gradient_2', 'sidebar_gradient_3',
    'page_background', 'card_background', 'text_color',
    'muted_text_color', 'border_color',
];

foreach ($hexFields as $field) {
    $value = strtolower(trim((string)$themeData[$field]));
    if (!preg_match('/^#[0-9a-f]{6}$/', $value)) {
        respond(false, 'Enter a valid 6-digit HEX color for ' . str_replace('_', ' ', $field) . '.', 422);
    }
    $themeData[$field] = $value;
}

$themeData['font_family'] = trim((string)$themeData['font_family']);
$themeData['heading_font_family'] = trim((string)$themeData['heading_font_family']);
if ($themeData['font_family'] === '' || mb_strlen($themeData['font_family']) > 100) {
    respond(false, 'Enter a valid body font family.', 422);
}
if ($themeData['heading_font_family'] === '' || mb_strlen($themeData['heading_font_family']) > 100) {
    respond(false, 'Enter a valid heading font family.', 422);
}

$themeData['border_radius_px'] = max(0, min(40, (int)$themeData['border_radius_px']));
$themeData['sidebar_width_px'] = max(180, min(340, (int)$themeData['sidebar_width_px']));

$currentLogo = trim((string)($_POST['existing_logo_path'] ?? ''));
$logoPath = $currentLogo;
$newUploadedAbsolutePath = '';

if (isset($_POST['remove_logo']) && (string)$_POST['remove_logo'] === '1') {
    $logoPath = '';
}

if (!empty($_FILES['logo_file']['name'])) {
    $file = $_FILES['logo_file'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        respond(false, 'Unable to upload the logo.', 422);
    }

    if ((int)($file['size'] ?? 0) > 2 * 1024 * 1024) {
        respond(false, 'Logo file size must not exceed 2 MB.', 422);
    }

    $allowedMime = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'image/svg+xml' => 'svg',
    ];

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file((string)$file['tmp_name']);
    if (!isset($allowedMime[$mime])) {
        respond(false, 'Logo must be PNG, JPG, WEBP or SVG.', 422);
    }

    $projectRoot = dirname(__DIR__);
    $uploadDirectory = $projectRoot . '/uploads/theme';
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
        respond(false, 'Unable to create the logo upload directory.', 500);
    }

    $fileName = 'business-' . $businessId . '-logo-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $allowedMime[$mime];
    $destination = $uploadDirectory . '/' . $fileName;
    if (!move_uploaded_file((string)$file['tmp_name'], $destination)) {
        respond(false, 'Unable to save the uploaded logo.', 500);
    }

    $newUploadedAbsolutePath = $destination;
    $logoPath = 'uploads/theme/' . $fileName;
}

$saveData = $themeData;
$saveData['logo_path'] = $logoPath;
$filtered = [];
foreach ($saveData as $column => $value) {
    if (isset($availableColumns[$column])) {
        $filtered[$column] = $value;
    }
}

if (!$filtered) {
    if ($newUploadedAbsolutePath !== '' && is_file($newUploadedAbsolutePath)) {
        @unlink($newUploadedAbsolutePath);
    }
    respond(false, 'No supported theme columns were found.', 500);
}

$conn->begin_transaction();

try {
    $existsStmt = $conn->prepare('SELECT business_id FROM business_theme_settings WHERE business_id = ? LIMIT 1');
    if (!$existsStmt) {
        throw new RuntimeException($conn->error);
    }
    $existsStmt->bind_param('i', $businessId);
    $existsStmt->execute();
    $exists = $existsStmt->get_result()->num_rows > 0;
    $existsStmt->close();

    if ($exists) {
        $setParts = [];
        $values = [];
        $types = '';
        foreach ($filtered as $column => $value) {
            $setParts[] = "`{$column}` = ?";
            $types .= is_int($value) ? 'i' : 's';
            $values[] = $value;
        }
        if (isset($availableColumns['updated_at'])) {
            $setParts[] = '`updated_at` = NOW()';
        }
        $sql = 'UPDATE business_theme_settings SET ' . implode(', ', $setParts) . ' WHERE business_id = ?';
        $types .= 'i';
        $values[] = $businessId;
    } else {
        $columns = ['`business_id`'];
        $placeholders = ['?'];
        $values = [$businessId];
        $types = 'i';
        foreach ($filtered as $column => $value) {
            $columns[] = "`{$column}`";
            $placeholders[] = '?';
            $types .= is_int($value) ? 'i' : 's';
            $values[] = $value;
        }
        if (isset($availableColumns['created_at'])) {
            $columns[] = '`created_at`';
            $placeholders[] = 'NOW()';
        }
        if (isset($availableColumns['updated_at'])) {
            $columns[] = '`updated_at`';
            $placeholders[] = 'NOW()';
        }
        $sql = 'INSERT INTO business_theme_settings (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($conn->error);
    }
    $stmt->bind_param($types, ...$values);
    if (!$stmt->execute()) {
        throw new RuntimeException($stmt->error);
    }
    $stmt->close();

    $conn->commit();

    $_SESSION['theme_primary_color'] = $themeData['primary_color'];
    $_SESSION['theme_logo_path'] = $logoPath;

    if ($currentLogo !== '' && $currentLogo !== $logoPath && str_starts_with($currentLogo, 'uploads/theme/')) {
        $oldAbsolutePath = dirname(__DIR__) . '/' . $currentLogo;
        if (is_file($oldAbsolutePath)) {
            @unlink($oldAbsolutePath);
        }
    }

    respond(true, $exists ? 'Theme settings updated successfully.' : 'Theme settings created successfully.', 200, [
        'operation' => $exists ? 'update' : 'insert',
        'logo_path' => $logoPath,
    ]);
} catch (Throwable $exception) {
    $conn->rollback();
    if ($newUploadedAbsolutePath !== '' && is_file($newUploadedAbsolutePath)) {
        @unlink($newUploadedAbsolutePath);
    }
    respond(false, 'Unable to save theme settings: ' . $exception->getMessage(), 500);
}
