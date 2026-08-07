<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string) ($_SESSION['timezone'] ?? 'Asia/Kolkata'));

foreach ([
    __DIR__ . '/config/config.php',
    __DIR__ . '/config.php',
    __DIR__ . '/includes/config.php',
    __DIR__ . '/super-admin/includes/config.php'
] as $configFile) {
    if (is_file($configFile)) {
        require_once $configFile;
        break;
    }
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database configuration is not available.');
}

$conn->set_charset('utf8mb4');

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

function e($value)
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function tableExists(mysqli $conn, $table)
{
    $safe = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $result && $result->num_rows > 0;
}

function fetchOne(mysqli $conn, $sql, $types = '', array $params = [])
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($conn->error);
    }

    if ($types !== '') {
        $bind = [$types];
        foreach ($params as $index => $value) {
            $bind[] =& $params[$index];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind);
    }

    if (!$stmt->execute()) {
        throw new RuntimeException($stmt->error);
    }

    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return $row;
}

function fetchAll(mysqli $conn, $sql, $types = '', array $params = [])
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($conn->error);
    }

    if ($types !== '') {
        $bind = [$types];
        foreach ($params as $index => $value) {
            $bind[] =& $params[$index];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind);
    }

    if (!$stmt->execute()) {
        throw new RuntimeException($stmt->error);
    }

    $rows = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    $stmt->close();
    return $rows;
}

function jsonResponse($success, $message, array $extra = [], $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge([
        'success' => (bool) $success,
        'message' => (string) $message
    ], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function profilePhotoUrl($path)
{
    $path = trim((string) $path);
    if ($path === '') {
        return '';
    }

    if (preg_match('~^https?://~i', $path)) {
        return $path;
    }

    return ltrim($path, '/');
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$businessId = (int) ($_SESSION['business_id'] ?? 0);
$branchId = (int) ($_SESSION['branch_id'] ?? $_SESSION['default_branch_id'] ?? 0);

if ($userId <= 0) {
    header('Location: login.php');
    exit;
}

if (!tableExists($conn, 'users')) {
    die('Required table users does not exist.');
}

if (empty($_SESSION['profile_csrf'])) {
    $_SESSION['profile_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['profile_csrf'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrfToken, $postedToken)) {
        jsonResponse(false, 'Session expired. Refresh the page and try again.', [], 419);
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'update_profile') {
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $username = trim((string) ($_POST['username'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $mobile = trim((string) ($_POST['mobile'] ?? ''));

        if ($fullName === '') {
            jsonResponse(false, 'Full name is required.', [], 422);
        }

        if ($username === '') {
            jsonResponse(false, 'Username is required.', [], 422);
        }

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            jsonResponse(false, 'Enter a valid email address.', [], 422);
        }

        if ($mobile !== '' && !preg_match('/^[0-9+()\-\s]{6,20}$/', $mobile)) {
            jsonResponse(false, 'Enter a valid mobile number.', [], 422);
        }

        $duplicate = fetchOne(
            $conn,
            "SELECT id
             FROM users
             WHERE id <> ?
               AND (
                    LOWER(TRIM(username)) = LOWER(TRIM(?))
                    OR (? <> '' AND LOWER(TRIM(email)) = LOWER(TRIM(?)))
               )
             LIMIT 1",
            'isss',
            [$userId, $username, $email, $email]
        );

        if ($duplicate) {
            jsonResponse(false, 'Username or email is already used by another user.', [], 422);
        }

        $current = fetchOne(
            $conn,
            'SELECT profile_photo_path FROM users WHERE id=? LIMIT 1',
            'i',
            [$userId]
        );

        $photoPath = (string) ($current['profile_photo_path'] ?? '');

        if (isset($_FILES['profile_photo']) && (int) $_FILES['profile_photo']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ((int) $_FILES['profile_photo']['error'] !== UPLOAD_ERR_OK) {
                jsonResponse(false, 'Unable to upload the profile photo.', [], 422);
            }

            if ((int) $_FILES['profile_photo']['size'] > 3 * 1024 * 1024) {
                jsonResponse(false, 'Profile photo must be 3 MB or smaller.', [], 422);
            }

            $tmpName = (string) $_FILES['profile_photo']['tmp_name'];
            $mime = '';

            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo) {
                    $mime = (string) finfo_file($finfo, $tmpName);
                    finfo_close($finfo);
                }
            }

            $allowed = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp'
            ];

            if (!isset($allowed[$mime])) {
                jsonResponse(false, 'Only JPG, PNG and WEBP images are allowed.', [], 422);
            }

            $uploadDir = __DIR__ . '/uploads/users';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
                jsonResponse(false, 'Unable to create profile upload directory.', [], 500);
            }

            $filename = 'profile_' . $userId . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
            $absolutePath = $uploadDir . '/' . $filename;
            $relativePath = 'uploads/users/' . $filename;

            if (!move_uploaded_file($tmpName, $absolutePath)) {
                jsonResponse(false, 'Unable to save the profile photo.', [], 500);
            }

            if ($photoPath !== '' && strpos($photoPath, 'uploads/users/') === 0) {
                $oldFile = __DIR__ . '/' . $photoPath;
                if (is_file($oldFile)) {
                    @unlink($oldFile);
                }
            }

            $photoPath = $relativePath;
        }

        $stmt = $conn->prepare(
            "UPDATE users
             SET full_name=?, username=?, email=?, mobile=?, profile_photo_path=?, updated_at=NOW()
             WHERE id=?"
        );

        if (!$stmt) {
            jsonResponse(false, 'Unable to prepare profile update: ' . $conn->error, [], 500);
        }

        $stmt->bind_param('sssssi', $fullName, $username, $email, $mobile, $photoPath, $userId);

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            jsonResponse(false, 'Unable to update profile: ' . $error, [], 422);
        }

        $stmt->close();

        $_SESSION['full_name'] = $fullName;
        $_SESSION['username'] = $username;
        $_SESSION['email'] = $email;
        $_SESSION['mobile'] = $mobile;
        $_SESSION['profile_photo_path'] = $photoPath;

        jsonResponse(true, 'Profile updated successfully.', [
            'profile_photo_url' => profilePhotoUrl($photoPath),
            'full_name' => $fullName
        ]);
    }

    if ($action === 'change_password') {
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            jsonResponse(false, 'Complete all password fields.', [], 422);
        }

        if (strlen($newPassword) < 8) {
            jsonResponse(false, 'New password must contain at least 8 characters.', [], 422);
        }

        if ($newPassword !== $confirmPassword) {
            jsonResponse(false, 'New password and confirmation do not match.', [], 422);
        }

        $userPassword = fetchOne(
            $conn,
            'SELECT password_hash FROM users WHERE id=? LIMIT 1',
            'i',
            [$userId]
        );

        if (!$userPassword || !password_verify($currentPassword, (string) $userPassword['password_hash'])) {
            jsonResponse(false, 'Current password is incorrect.', [], 422);
        }

        if (password_verify($newPassword, (string) $userPassword['password_hash'])) {
            jsonResponse(false, 'New password must be different from the current password.', [], 422);
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $conn->prepare(
            'UPDATE users SET password_hash=?, must_change_password=0, updated_at=NOW() WHERE id=?'
        );

        if (!$stmt) {
            jsonResponse(false, 'Unable to prepare password update.', [], 500);
        }

        $stmt->bind_param('si', $newHash, $userId);

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            jsonResponse(false, 'Unable to change password: ' . $error, [], 500);
        }

        $stmt->close();
        $_SESSION['must_change_password'] = 0;

        jsonResponse(true, 'Password changed successfully.');
    }

    jsonResponse(false, 'Invalid request.', [], 400);
}

$user = fetchOne(
    $conn,
    "SELECT
        u.*,
        b.business_name,
        b.business_code,
        br.branch_name,
        br.branch_code
     FROM users u
     LEFT JOIN businesses b ON b.id=u.business_id
     LEFT JOIN branches br ON br.id=u.default_branch_id
     WHERE u.id=?
     LIMIT 1",
    'i',
    [$userId]
);

if (!$user) {
    die('User profile was not found.');
}

$roles = [];
if (tableExists($conn, 'user_roles') && tableExists($conn, 'roles')) {
    $roles = fetchAll(
        $conn,
        "SELECT r.role_name, r.role_code
         FROM user_roles ur
         INNER JOIN roles r ON r.id=ur.role_id
         WHERE ur.user_id=?
         ORDER BY r.role_name",
        'i',
        [$userId]
    );
}

$branches = [];
if (tableExists($conn, 'user_branches') && tableExists($conn, 'branches')) {
    $branches = fetchAll(
        $conn,
        "SELECT br.branch_name, br.branch_code
         FROM user_branches ub
         INNER JOIN branches br ON br.id=ub.branch_id
         WHERE ub.user_id=?
         ORDER BY br.branch_name",
        'i',
        [$userId]
    );
}

$theme = [
    'primary_color' => '#d89416',
    'primary_dark_color' => '#b86a0b',
    'primary_soft_color' => '#fff6e5',
    'page_background' => '#f4f3f0',
    'card_background' => '#ffffff',
    'text_color' => '#171717',
    'muted_text_color' => '#7d8794',
    'border_color' => '#e8e8e8',
    'font_family' => 'Inter',
    'heading_font_family' => 'Playfair Display',
    'border_radius_px' => 12
];

if ($businessId > 0 && tableExists($conn, 'business_theme_settings')) {
    $themeRow = fetchOne(
        $conn,
        'SELECT * FROM business_theme_settings WHERE business_id=? LIMIT 1',
        'i',
        [$businessId]
    );

    foreach ($theme as $key => $defaultValue) {
        if (isset($themeRow[$key]) && $themeRow[$key] !== '') {
            $theme[$key] = $themeRow[$key];
        }
    }
}

$fullName = trim((string) ($user['full_name'] ?? ''));
$initials = '';
foreach (preg_split('/\s+/', $fullName) as $part) {
    if ($part !== '') {
        $initials .= strtoupper(substr($part, 0, 1));
    }
    if (strlen($initials) >= 2) {
        break;
    }
}
if ($initials === '') {
    $initials = 'U';
}

$roleNames = [];
foreach ($roles as $role) {
    $roleNames[] = (string) $role['role_name'];
}

$businessName = (string) ($_SESSION['business_name'] ?? $user['business_name'] ?? 'Jewellery ERP');
$pageTitle = 'My Profile';
$photoUrl = profilePhotoUrl($user['profile_photo_path'] ?? '');
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($businessName) ?> - Profile</title>
    <?php include('includes/links.php'); ?>
    <style>
        :root {
            --primary: <?= e($theme['primary_color']) ?>;
            --primary-dark: <?= e($theme['primary_dark_color']) ?>;
            --primary-soft: <?= e($theme['primary_soft_color']) ?>;
            --page-bg: <?= e($theme['page_background']) ?>;
            --card-bg: <?= e($theme['card_background']) ?>;
            --text: <?= e($theme['text_color']) ?>;
            --muted: <?= e($theme['muted_text_color']) ?>;
            --line: <?= e($theme['border_color']) ?>;
            --radius: <?= (int) $theme['border_radius_px'] ?>px;
        }

        body {
            background: var(--page-bg);
            color: var(--text);
            font-family: <?= json_encode($theme['font_family']) ?>, sans-serif
        }

        .profile-grid {
            display: grid;
            grid-template-columns: 300px minmax(0, 1fr);
            gap: 14px;
            align-items: start
        }

        .profile-card {
            background: var(--card-bg);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            overflow: hidden
        }

        .profile-cover {
            height: 108px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            position: relative
        }

        .profile-cover:after {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at 85% 15%, rgba(255, 255, 255, .2), transparent 35%)
        }

        .profile-summary {
            padding: 0 18px 18px;
            text-align: center
        }

        .avatar-wrap {
            width: 94px;
            height: 94px;
            margin: -47px auto 10px;
            position: relative;
            z-index: 2
        }

        .avatar1,
        .avatar1-fallback {
            width: 94px;
            height: 94px;
            border-radius: 50%;
            border: 5px solid var(--card-bg)
        }

        .avatar1 {
            object-fit: cover;
            background: #fff
        }

        .avatar1-fallback {
            display: grid;
            place-items: center;
            background: var(--primary-soft);
            color: var(--primary-dark);
            font-size: 27px;
            font-weight: 900
        }

        .profile-name {
            font-family: <?= json_encode($theme['heading_font_family']) ?>, serif;
            font-size: 21px;
            font-weight: 800;
            margin: 0
        }

        .profile-type {
            font-size: 10px;
            color: var(--muted);
            margin-top: 3px
        }

        .status-pill,
        .role-pill {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 5px 9px;
            font-size: 8px;
            font-weight: 800
        }

        .status-pill {
            margin-top: 9px;
            background: #eaf8f0;
            color: #168449
        }

        .role-pill {
            background: var(--primary-soft);
            color: var(--primary-dark);
            margin: 2px
        }

        .profile-meta {
            padding: 14px 18px;
            border-top: 1px solid var(--line);
            display: grid;
            gap: 10px
        }

        .meta-row {
            display: flex;
            align-items: flex-start;
            gap: 9px;
            font-size: 10px
        }

        .meta-row i {
            width: 17px;
            color: var(--primary-dark);
            margin-top: 2px
        }

        .meta-label {
            font-size: 8px;
            color: var(--muted);
            text-transform: uppercase
        }

        .meta-value {
            font-weight: 700;
            margin-top: 1px;
            word-break: break-word
        }

        .panel-head {
            padding: 14px 16px;
            border-bottom: 1px solid var(--line);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px
        }

        .panel-title {
            font-size: 13px;
            font-weight: 800;
            margin: 0
        }

        .panel-subtitle {
            font-size: 9px;
            color: var(--muted);
            margin-top: 2px
        }

        .panel-body {
            padding: 16px
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px
        }

        .field-full {
            grid-column: 1/-1
        }

        .field-label {
            display: block;
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            color: var(--muted);
            margin-bottom: 5px
        }

        .form-control {
            min-height: 38px;
            border: 1px solid var(--line);
            border-radius: 9px;
            background: var(--card-bg);
            color: var(--text);
            font-size: 10px
        }

        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 .2rem color-mix(in srgb, var(--primary) 12%, transparent)
        }

        .readonly-field {
            background: color-mix(in srgb, var(--muted) 5%, var(--card-bg)) !important
        }

        .btn-theme,
        .btn-soft {
            min-height: 38px;
            border-radius: 9px;
            padding: 8px 13px;
            font-size: 10px;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px
        }

        .btn-theme {
            border: 0;
            color: #fff;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark))
        }

        .btn-soft {
            border: 1px solid var(--line);
            color: var(--text);
            background: var(--card-bg)
        }

        .photo-drop {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px;
            border: 1px dashed var(--line);
            border-radius: 10px;
            background: color-mix(in srgb, var(--primary-soft) 45%, transparent)
        }

        .photo-preview,
        .photo-preview-fallback {
            width: 58px;
            height: 58px;
            border-radius: 50%;
            flex: 0 0 58px
        }

        .photo-preview {
            object-fit: cover
        }

        .photo-preview-fallback {
            display: grid;
            place-items: center;
            background: var(--primary-soft);
            color: var(--primary-dark);
            font-weight: 900
        }

        .photo-note {
            font-size: 9px;
            color: var(--muted);
            margin-top: 3px
        }

        .password-wrap {
            position: relative
        }

        .password-wrap .form-control {
            padding-right: 38px
        }

        .toggle-password {
            position: absolute;
            right: 9px;
            top: 50%;
            transform: translateY(-50%);
            border: 0;
            background: transparent;
            color: var(--muted)
        }

        .security-note {
            padding: 10px 12px;
            border-radius: 9px;
            background: var(--primary-soft);
            color: var(--primary-dark);
            font-size: 9px;
            margin-bottom: 12px
        }

        .theme-toast {
            position: fixed;
            right: 18px;
            top: 78px;
            z-index: 25000;
            min-width: 260px;
            max-width: 420px;
            padding: 11px 14px;
            border-radius: 10px;
            color: #fff;
            font-size: 11px;
            font-weight: 700;
            opacity: 0;
            transform: translateY(-10px);
            transition: .22s;
            box-shadow: 0 14px 35px rgba(0, 0, 0, .22)
        }

        .theme-toast.show {
            opacity: 1;
            transform: translateY(0)
        }

        .theme-toast-success {
            background: #168449
        }

        .theme-toast-error {
            background: #c0392b
        }

        body.dark-mode,
        body[data-theme=dark] {
            --page-bg: #0f151b;
            --card-bg: #182129;
            --text: #f3f6f8;
            --muted: #9aa7b3;
            --line: #2c3944
        }

        @media(max-width:991px) {
            .profile-grid {
                grid-template-columns: 1fr
            }

            .profile-card:first-child {
                max-width: none
            }

            .form-grid {
                grid-template-columns: 1fr
            }

            .field-full {
                grid-column: auto
            }
        }
    </style>
</head>

<body>
    <?php include('includes/sidebar.php'); ?>
    <main class="app-main">
        <?php include('includes/nav.php'); ?>
        <div class="content-wrap">
            <div class="profile-grid">
                <aside class="profile-card">
                    <div class="profile-cover"></div>
                    <div class="profile-summary">
                        <div class="avatar-wrap">
                            <?php if ($photoUrl !== ''): ?>
                                <img src="<?= e($photoUrl) ?>" alt="Profile" class="avatar1" id="summaryAvatar">
                            <?php else: ?>
                                <div class="avatar1-fallback" id="summaryAvatarFallback"><?= e($initials) ?></div>
                            <?php endif; ?>
                        </div>
                        <h1 class="profile-name" id="summaryName"><?= e($fullName) ?></h1>
                        <div class="profile-type"><?= e($user['user_type'] ?? 'Business User') ?></div>
                        <span class="status-pill"><i
                                class="fa-solid fa-circle-check me-1"></i><?= !empty($user['is_active']) ? 'Active Account' : 'Inactive Account' ?></span>
                        <?php if ($roleNames): ?>
                            <div class="mt-2">
                                <?php foreach ($roleNames as $roleName): ?>
                                    <span class="role-pill"><?= e($roleName) ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="profile-meta">
                        <div class="meta-row"><i class="fa-solid fa-building"></i>
                            <div>
                                <div class="meta-label">Business</div>
                                <div class="meta-value"><?= e($user['business_name'] ?? 'Platform') ?></div>
                            </div>
                        </div>
                        <div class="meta-row"><i class="fa-solid fa-code-branch"></i>
                            <div>
                                <div class="meta-label">Default Branch</div>
                                <div class="meta-value"><?= e($user['branch_name'] ?? 'Not Assigned') ?></div>
                            </div>
                        </div>
                        <div class="meta-row"><i class="fa-solid fa-id-card"></i>
                            <div>
                                <div class="meta-label">Employee Code</div>
                                <div class="meta-value"><?= e($user['employee_code'] ?: 'Not Assigned') ?></div>
                            </div>
                        </div>
                        <div class="meta-row"><i class="fa-solid fa-clock"></i>
                            <div>
                                <div class="meta-label">Last Login</div>
                                <div class="meta-value">
                                    <?= !empty($user['last_login_at']) ? e(date('d M Y, h:i A', strtotime($user['last_login_at']))) : 'No login recorded' ?>
                                </div>
                            </div>
                        </div>
                        <div class="meta-row"><i class="fa-solid fa-calendar"></i>
                            <div>
                                <div class="meta-label">Account Created</div>
                                <div class="meta-value">
                                    <?= !empty($user['created_at']) ? e(date('d M Y', strtotime($user['created_at']))) : '—' ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </aside>

                <div>
                    <section class="profile-card mb-3">
                        <div class="panel-head">
                            <div>
                                <h2 class="panel-title">Personal Information</h2>
                                <div class="panel-subtitle">Update your profile information and profile photo.</div>
                            </div>
                        </div>
                        <div class="panel-body">
                            <form id="profileForm" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="update_profile">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                <div class="form-grid">
                                    <div class="field-full">
                                        <label class="field-label">Profile Photo</label>
                                        <div class="photo-drop">
                                            <?php if ($photoUrl !== ''): ?>
                                                <img src="<?= e($photoUrl) ?>" class="photo-preview" id="photoPreview"
                                                    alt="Profile preview">
                                            <?php else: ?>
                                                <div class="photo-preview-fallback" id="photoPreviewFallback">
                                                    <?= e($initials) ?></div>
                                                <img src="" class="photo-preview d-none" id="photoPreview"
                                                    alt="Profile preview">
                                            <?php endif; ?>
                                            <div class="flex-grow-1">
                                                <input type="file" name="profile_photo" id="profilePhoto"
                                                    class="form-control" accept="image/jpeg,image/png,image/webp">
                                                <div class="photo-note">JPG, PNG or WEBP. Maximum file size: 3 MB.</div>
                                            </div>
                                        </div>
                                    </div>
                                    <div><label class="field-label">Full Name</label><input type="text"
                                            class="form-control" name="full_name" id="fullName"
                                            value="<?= e($user['full_name']) ?>" maxlength="150" required></div>
                                    <div><label class="field-label">Username</label><input type="text"
                                            class="form-control" name="username" value="<?= e($user['username']) ?>"
                                            maxlength="100" required></div>
                                    <div><label class="field-label">Email Address</label><input type="email"
                                            class="form-control" name="email" value="<?= e($user['email']) ?>"
                                            maxlength="150"></div>
                                    <div><label class="field-label">Mobile Number</label><input type="text"
                                            class="form-control" name="mobile" value="<?= e($user['mobile']) ?>"
                                            maxlength="20"></div>
                                    <div><label class="field-label">Employee Code</label><input type="text"
                                            class="form-control readonly-field"
                                            value="<?= e($user['employee_code'] ?: 'Not Assigned') ?>" readonly></div>
                                    <div><label class="field-label">Default Branch</label><input type="text"
                                            class="form-control readonly-field"
                                            value="<?= e($user['branch_name'] ?: 'Not Assigned') ?>" readonly></div>
                                    <div class="field-full d-flex justify-content-end"><button type="submit"
                                            class="btn-theme" id="saveProfile"><i
                                                class="fa-solid fa-floppy-disk"></i>Save Profile</button></div>
                                </div>
                            </form>
                        </div>
                    </section>

                    <section class="profile-card mb-3">
                        <div class="panel-head">
                            <div>
                                <h2 class="panel-title">Assigned Access</h2>
                                <div class="panel-subtitle">Your assigned roles and branch access.</div>
                            </div>
                        </div>
                        <div class="panel-body">
                            <div class="form-grid">
                                <div><label class="field-label">Roles</label>
                                    <div><?php if ($roles):
                                        foreach ($roles as $role): ?><span
                                                    class="role-pill"><?= e($role['role_name']) ?></span><?php endforeach; else: ?><span
                                                class="text-muted small">No role assigned.</span><?php endif; ?></div>
                                </div>
                                <div><label class="field-label">Branches</label>
                                    <div><?php if ($branches): foreach ($branches as $branch): ?><span
                                                    class="role-pill"><?= e($branch['branch_name']) ?></span><?php endforeach; elseif (!empty($user['branch_name'])): ?><span
                                                class="role-pill"><?= e($user['branch_name']) ?></span><?php else: ?><span
                                                class="text-muted small">No branch assigned.</span><?php endif; ?></div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="profile-card">
                        <div class="panel-head">
                            <div>
                                <h2 class="panel-title">Change Password</h2>
                                <div class="panel-subtitle">Use a strong password that you do not use elsewhere.</div>
                            </div>
                        </div>
                        <div class="panel-body">
                            <div class="security-note"><i class="fa-solid fa-shield-halved me-1"></i>Your password must
                                have at least 8 characters.</div>
                            <form id="passwordForm">
                                <input type="hidden" name="action" value="change_password">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                <div class="form-grid">
                                    <div class="field-full"><label class="field-label">Current Password</label>
                                        <div class="password-wrap"><input type="password" class="form-control"
                                                name="current_password" required><button type="button"
                                                class="toggle-password"><i class="fa-solid fa-eye"></i></button></div>
                                    </div>
                                    <div><label class="field-label">New Password</label>
                                        <div class="password-wrap"><input type="password" class="form-control"
                                                name="new_password" minlength="8" required><button type="button"
                                                class="toggle-password"><i class="fa-solid fa-eye"></i></button></div>
                                    </div>
                                    <div><label class="field-label">Confirm New Password</label>
                                        <div class="password-wrap"><input type="password" class="form-control"
                                                name="confirm_password" minlength="8" required><button type="button"
                                                class="toggle-password"><i class="fa-solid fa-eye"></i></button></div>
                                    </div>
                                    <div class="field-full d-flex justify-content-end"><button type="submit"
                                            class="btn-theme" id="changePassword"><i class="fa-solid fa-key"></i>Change
                                            Password</button></div>
                                </div>
                            </form>
                        </div>
                    </section>
                </div>
            </div>
            <?php include('includes/footer.php'); ?>
        </div>
    </main>
    <?php include('includes/script.php'); ?>
    <script src="assets/js/script.js"></script>
    <script>
        (function () {
            'use strict';

            function toast(type, message) {
                var box = document.createElement('div');
                box.className = 'theme-toast theme-toast-' + type;
                box.textContent = message;
                document.body.appendChild(box);
                requestAnimationFrame(function () { box.classList.add('show'); });
                setTimeout(function () { box.classList.remove('show'); setTimeout(function () { box.remove(); }, 250); }, 3200);
            }

            async function submitForm(form, button) {
                var original = button.innerHTML;
                button.disabled = true;
                button.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>Saving...';
                try {
                    var response = await fetch(window.location.pathname, { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                    var result = await response.json();
                    if (!response.ok || !result.success) throw new Error(result.message || 'Request failed.');
                    toast('success', result.message);
                    return result;
                } catch (error) {
                    toast('error', error.message);
                    throw error;
                } finally {
                    button.disabled = false;
                    button.innerHTML = original;
                }
            }

            var photoInput = document.getElementById('profilePhoto');
            photoInput.addEventListener('change', function () {
                var file = this.files && this.files[0];
                if (!file) return;
                if (file.size > 3 * 1024 * 1024) { toast('error', 'Profile photo must be 3 MB or smaller.'); this.value = ''; return; }
                var reader = new FileReader();
                reader.onload = function (event) {
                    var preview = document.getElementById('photoPreview');
                    var fallback = document.getElementById('photoPreviewFallback');
                    preview.src = event.target.result;
                    preview.classList.remove('d-none');
                    if (fallback) fallback.classList.add('d-none');
                };
                reader.readAsDataURL(file);
            });

            document.getElementById('profileForm').addEventListener('submit', async function (event) {
                event.preventDefault();
                try {
                    var result = await submitForm(this, document.getElementById('saveProfile'));
                    document.getElementById('summaryName').textContent = result.full_name || document.getElementById('fullName').value;
                    if (result.profile_photo_url) {
                        var avatar = document.getElementById('summaryAvatar');
                        var fallback = document.getElementById('summaryAvatarFallback');
                        if (!avatar) {
                            avatar = document.createElement('img');
                            avatar.id = 'summaryAvatar';
                            avatar.className = 'avatar';
                            avatar.alt = 'Profile';
                            document.querySelector('.avatar-wrap').appendChild(avatar);
                        }
                        avatar.src = result.profile_photo_url + '?v=' + Date.now();
                        if (fallback) fallback.remove();
                    }
                } catch (error) { }
            });

            document.getElementById('passwordForm').addEventListener('submit', async function (event) {
                event.preventDefault();
                try {
                    await submitForm(this, document.getElementById('changePassword'));
                    this.reset();
                } catch (error) { }
            });

            document.querySelectorAll('.toggle-password').forEach(function (button) {
                button.addEventListener('click', function () {
                    var input = this.parentElement.querySelector('input');
                    var icon = this.querySelector('i');
                    input.type = input.type === 'password' ? 'text' : 'password';
                    icon.className = input.type === 'password' ? 'fa-solid fa-eye' : 'fa-solid fa-eye-slash';
                });
            });
        })();
    </script>
</body>

</html>