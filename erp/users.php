<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string) ($_SESSION['timezone'] ?? 'Asia/Kolkata'));

$configCandidates = [
    __DIR__ . '/config/config.php',
    __DIR__ . '/config.php',
    __DIR__ . '/super-admin/includes/config.php',
];
foreach ($configCandidates as $configFile) {
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

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('usersPermission')) {
    function usersPermission(mysqli $conn, string $action): bool
    {
        if (($_SESSION['user_type'] ?? '') === 'Platform Admin') {
            return true;
        }

        $fieldMap = [
            'open' => 'can_open',
            'view' => 'can_view',
            'value' => 'can_view_value',
            'create' => 'can_create',
            'update' => 'can_update',
            'approve' => 'can_approve',
            'delete' => 'can_delete',
        ];
        $field = $fieldMap[$action] ?? '';
        if ($field === '') {
            return false;
        }

        $sessionPermissions = $_SESSION['permissions'] ?? [];
        foreach (['perm.staff.users', 'perm.staff'] as $key) {
            if (isset($sessionPermissions[$key][$field])) {
                return (int) $sessionPermissions[$key][$field] === 1;
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
                  AND p.permission_code IN ('perm.staff.users','perm.staff')
                ORDER BY FIELD(p.permission_code,'perm.staff.users','perm.staff')
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
}

if (!usersPermission($conn, 'open')) {
    http_response_code(403);
    die('Access denied. You do not have permission to open users.');
}

$canView = usersPermission($conn, 'view') || usersPermission($conn, 'open');
$canCreate = usersPermission($conn, 'create');
$canUpdate = usersPermission($conn, 'update');
$canDelete = usersPermission($conn, 'delete');
$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
$businessId = (int) ($_SESSION['business_id'] ?? 0);
$isPlatformAdmin = (($_SESSION['user_type'] ?? '') === 'Platform Admin');

if ($businessId <= 0) {
    http_response_code(403);
    die('A valid business must be selected before managing users.');
}

if (empty($_SESSION['users_csrf'])) {
    $_SESSION['users_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['users_csrf'];

$roles = [];
$stmt = $conn->prepare("SELECT id, role_code, role_name, description
                        FROM roles
                        WHERE is_active = 1 AND (business_id = ? OR business_id IS NULL)
                        ORDER BY is_system DESC, role_name ASC");
$stmt->bind_param('i', $businessId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    if (!$isPlatformAdmin && $row['role_code'] === 'PLATFORM_ADMIN') {
        continue;
    }
    $roles[] = $row;
}
$stmt->close();

$branches = [];
$stmt = $conn->prepare("SELECT id, branch_code, branch_name, branch_type, is_default
                        FROM branches
                        WHERE business_id = ? AND is_active = 1
                        ORDER BY is_default DESC, branch_name ASC");
$stmt->bind_param('i', $businessId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $branches[] = $row;
}
$stmt->close();

$users = [];
$sql = "SELECT u.id, u.business_id, u.default_branch_id, u.employee_code, u.full_name,
               u.username, u.email, u.mobile, u.profile_photo_path, u.user_type,
               u.must_change_password, u.is_active, u.last_login_at, u.created_at, u.updated_at,
               b.branch_name AS default_branch_name,
               GROUP_CONCAT(DISTINCT r.role_name ORDER BY ur.is_primary DESC, r.role_name SEPARATOR ', ') AS role_names,
               GROUP_CONCAT(DISTINCT br.branch_name ORDER BY uba.is_default DESC, br.branch_name SEPARATOR ', ') AS branch_names
        FROM users u
        LEFT JOIN branches b ON b.id = u.default_branch_id AND b.business_id = u.business_id
        LEFT JOIN user_roles ur ON ur.user_id = u.id AND ur.business_id = u.business_id
        LEFT JOIN roles r ON r.id = ur.role_id
        LEFT JOIN user_branch_access uba ON uba.user_id = u.id AND uba.business_id = u.business_id
        LEFT JOIN branches br ON br.id = uba.branch_id
        WHERE u.business_id = ?
        GROUP BY u.id
        ORDER BY u.is_active DESC, u.full_name ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $businessId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $users[] = $row;
}
$stmt->close();

$stats = ['total' => count($users), 'active' => 0, 'inactive' => 0, 'must_change' => 0];
foreach ($users as $user) {
    if ((int) $user['is_active'] === 1) {
        $stats['active']++;
    } else {
        $stats['inactive']++;
    }
    if ((int) $user['must_change_password'] === 1) {
        $stats['must_change']++;
    }
}



// Load the same business theme used by index.php.
$theme = [
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
    'sidebar_width_px' => 230,
];

$themeStmt = $conn->prepare('SELECT * FROM business_theme_settings WHERE business_id = ? LIMIT 1');
if ($themeStmt) {
    $themeStmt->bind_param('i', $businessId);
    $themeStmt->execute();
    $themeRow = $themeStmt->get_result()->fetch_assoc() ?: [];
    $themeStmt->close();
    foreach ($theme as $key => $defaultValue) {
        if (isset($themeRow[$key]) && $themeRow[$key] !== '') {
            $theme[$key] = $themeRow[$key];
        }
    }
}

$pageTitle = 'Users';
$businessName = (string) ($_SESSION['business_name'] ?? 'Jewellery ERP');
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo e($businessName); ?> - Users</title>
    <?php include('includes/links.php'); ?>
    <style>
        :root{
            --primary:<?php echo e($theme['primary_color']); ?>;
            --primary-dark:<?php echo e($theme['primary_dark_color']); ?>;
            --primary-soft:<?php echo e($theme['primary_soft_color']); ?>;
            --sidebar-gradient-1:<?php echo e($theme['sidebar_gradient_1']); ?>;
            --sidebar-gradient-2:<?php echo e($theme['sidebar_gradient_2']); ?>;
            --sidebar-gradient-3:<?php echo e($theme['sidebar_gradient_3']); ?>;
            --page-bg:<?php echo e($theme['page_background']); ?>;
            --card-bg:<?php echo e($theme['card_background']); ?>;
            --text-color:<?php echo e($theme['text_color']); ?>;
            --muted-color:<?php echo e($theme['muted_text_color']); ?>;
            --border-color:<?php echo e($theme['border_color']); ?>;
            --sidebar-width:<?php echo (int)$theme['sidebar_width_px']; ?>px;
            --radius:<?php echo (int)$theme['border_radius_px']; ?>px;
            --card:var(--card-bg);
            --line:var(--border-color);
            --text:var(--text-color);
            --muted:var(--muted-color);
            --gold:var(--primary);
            --gold-dark:var(--primary-dark);
            --gold-soft:var(--primary-soft);
            --shadow:0 5px 18px rgba(24,31,40,.08);
        }
        body{background:var(--page-bg);color:var(--text-color);font-family:<?php echo json_encode((string)$theme['font_family']); ?>,sans-serif;}
        .sidebar{background:linear-gradient(180deg,var(--sidebar-gradient-1),var(--sidebar-gradient-2),var(--sidebar-gradient-3))!important;}
        .users-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 14px
        }

        .users-toolbar-left {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap
        }

        .users-search {
            position: relative;
            min-width: 260px
        }

        .users-search i {
            position: absolute;
            left: 11px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--muted, #7d8794);
            font-size: 11px
        }

        .users-search input {
            padding-left: 32px
        }

        .users-card {
            background: var(--card, #fff);
            border: 1px solid var(--line, #e8e8e8);
            border-radius: 14px;
            box-shadow: var(--shadow, 0 5px 18px rgba(24, 31, 40, .08));
            overflow: hidden
        }

        .users-table {
            margin: 0;
            font-size: 11px
        }

        .users-table th {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: var(--muted, #7d8794);
            background: rgba(125, 135, 148, .05);
            white-space: nowrap;
            padding: 10px 12px
        }

        .users-table td {
            padding: 10px 12px;
            vertical-align: middle
        }

        .user-cell {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 180px
        }

        .user-avatar {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            object-fit: cover;
            flex: 0 0 34px
        }

        .user-avatar-initials {
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--gold, #d89416), var(--gold-dark, #b86a0b));
            color: #fff;
            font-size: 11px;
            font-weight: 800
        }

        .user-name {
            font-size: 11px;
            font-weight: 700;
            color: var(--text, #171717)
        }

        .user-sub {
            font-size: 9px;
            color: var(--muted, #7d8794);
            margin-top: 2px
        }

        .role-badge,
        .branch-badge,
        .status-badge {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 4px 8px;
            font-size: 9px;
            font-weight: 700
        }

        .role-badge {
            background: var(--gold-soft, #fff6e5);
            color: var(--gold-dark, #b86a0b)
        }

        .branch-badge {
            background: #eff6ff;
            color: #1d4ed8
        }

        .status-active {
            background: #eaf8f0;
            color: #168449
        }

        .status-inactive {
            background: #fdecec;
            color: #bd2d2d
        }

        .stat-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 10px;
            margin-bottom: 14px
        }

        .stat-card {
            background: var(--card, #fff);
            border: 1px solid var(--line, #e8e8e8);
            border-radius: 12px;
            padding: 12px 14px
        }

        .stat-label {
            font-size: 9px;
            color: var(--muted, #7d8794)
        }

        .stat-value {
            font-size: 20px;
            font-weight: 800;
            margin-top: 4px
        }

        .action-btn {
            width: 30px;
            height: 30px;
            border: 1px solid var(--line, #e8e8e8);
            border-radius: 8px;
            background: var(--card, #fff);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            color: var(--text, #171717)
        }

        .action-btn:hover {
            background: var(--gold-soft, #fff6e5);
            color: var(--gold-dark, #b86a0b)
        }

        .action-btn.danger:hover {
            background: #fdecec;
            color: #bd2d2d
        }

        .modal-content {
            border: 0;
            border-radius: 15px;
            overflow: hidden
        }

        .modal-header {
            padding: 13px 16px
        }

        .modal-title {
            font-size: 14px;
            font-weight: 800
        }

        .modal-body {
            padding: 16px
        }

        .field-label {
            display: block;
            font-size: 10px;
            font-weight: 700;
            margin-bottom: 5px
        }

        .form-control,
        .form-select {
            font-size: 11px;
            min-height: 36px;
            border-radius: 9px
        }

        .form-check-label {
            font-size: 10px
        }

        .section-title {
            font-size: 11px;
            font-weight: 800;
            margin: 2px 0 10px;
            padding-bottom: 7px;
            border-bottom: 1px solid var(--line, #e8e8e8)
        }

        .selection-box {
            max-height: 180px;
            overflow: auto;
            border: 1px solid var(--line, #e8e8e8);
            border-radius: 10px;
            padding: 7px
        }

        .selection-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 7px;
            border-radius: 7px
        }

        .selection-item:hover {
            background: rgba(125, 135, 148, .06)
        }

        .selection-item label {
            font-size: 10px;
            flex: 1
        }

        .theme-toast {
            position: fixed;
            right: 18px;
            top: 78px;
            z-index: 20000;
            display: flex;
            align-items: center;
            gap: 9px;
            min-width: 260px;
            max-width: 420px;
            padding: 11px 14px;
            border-radius: 10px;
            color: #fff;
            font-size: 11px;
            font-weight: 600;
            box-shadow: 0 14px 35px rgba(0, 0, 0, .22);
            opacity: 0;
            transform: translateY(-10px);
            transition: .22s
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

        .empty-state {
            padding: 50px 20px;
            text-align: center;
            color: var(--muted, #7d8794)
        }

        .empty-state i {
            font-size: 34px;
            margin-bottom: 10px
        }

        .password-wrap {
            position: relative
        }

        .password-wrap .form-control {
            padding-right: 76px
        }

        .password-actions {
            position: absolute;
            right: 5px;
            top: 5px;
            display: flex;
            gap: 3px
        }

        .password-actions button {
            width: 28px;
            height: 26px;
            border: 0;
            background: transparent;
            border-radius: 6px;
            font-size: 10px
        }

        .password-actions button:hover {
            background: rgba(125, 135, 148, .1)
        }

        body.dark-mode .users-card,
        body.dark-mode .stat-card,
        body.dark-mode .action-btn {
            background: var(--card);
            border-color: var(--line)
        }

        body.dark-mode .form-control,
        body.dark-mode .form-select {
            background: #171e26;
            color: #eef2f7;
            border-color: #303740
        }

        body.dark-mode .user-name {
            color: #eef2f7
        }

        @media(max-width:991.98px) {
            .stat-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr))
            }

            .users-toolbar {
                align-items: stretch;
                flex-direction: column
            }

            .users-toolbar-left {
                width: 100%;
                display: grid;
                grid-template-columns: minmax(0, 1fr) 150px 180px
            }

            .users-search {
                min-width: 0;
                width: 100%
            }

            .users-toolbar-left .form-select {
                width: 100% !important
            }

            #addUserButton {
                width: 100%;
                min-height: 38px
            }

            .users-card {
                background: transparent;
                border: 0;
                box-shadow: none;
                overflow: visible
            }

            .table-responsive {
                overflow: visible
            }

            .users-table {
                display: block;
                background: transparent
            }

            .users-table thead {
                display: none
            }

            .users-table tbody {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 12px
            }

            .users-table tbody tr {
                display: grid;
                grid-template-columns: 1fr 1fr;
                background: var(--card, #fff);
                border: 1px solid var(--line, #e8e8e8);
                border-radius: var(--radius, 14px);
                box-shadow: var(--shadow, 0 5px 18px rgba(24, 31, 40, .08));
                padding: 14px;
                overflow: hidden
            }

            .users-table tbody td {
                display: flex;
                align-items: flex-start;
                justify-content: space-between;
                gap: 12px;
                min-width: 0;
                padding: 9px 0;
                border: 0;
                border-bottom: 1px dashed var(--line, #e8e8e8);
                text-align: right !important
            }

            .users-table tbody td::before {
                content: attr(data-label);
                flex: 0 0 auto;
                font-size: 9px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: .04em;
                color: var(--muted, #7d8794);
                text-align: left
            }

            .users-table tbody td.user-column {
                grid-column: 1/-1;
                display: block;
                padding: 0 0 12px;
                margin-bottom: 2px;
                border-bottom: 1px solid var(--line, #e8e8e8);
                text-align: left !important
            }

            .users-table tbody td.user-column::before {
                display: none
            }

            .users-table tbody td.actions-column {
                grid-column: 1/-1;
                border-bottom: 0;
                padding: 12px 0 0;
                align-items: center
            }

            .users-table tbody td.actions-column>div {
                display: flex !important;
                gap: 7px !important
            }

            .user-cell {
                min-width: 0
            }

            .user-name {
                font-size: 12px
            }

            .user-sub {
                overflow-wrap: anywhere
            }

            .role-badge,
            .branch-badge {
                white-space: normal;
                text-align: right;
                justify-content: flex-end;
                max-width: 170px
            }

            .action-btn {
                width: 34px;
                height: 34px
            }

            body.dark-mode .users-table tbody tr {
                background: var(--card);
                border-color: var(--line)
            }
        }

        @media(max-width:767.98px) {
            .content-wrap {
                padding-left: 10px;
                padding-right: 10px
            }

            .stat-grid {
                gap: 8px
            }

            .stat-card {
                padding: 11px 12px
            }

            .stat-value {
                font-size: 18px
            }

            .users-toolbar-left {
                grid-template-columns: 1fr
            }

            .users-table tbody {
                grid-template-columns: 1fr
            }

            .users-table tbody tr {
                grid-template-columns: 1fr;
                padding: 13px
            }

            .users-table tbody td {
                grid-column: 1/-1
            }

            .role-badge,
            .branch-badge {
                max-width: 62%
            }

            .theme-toast {
                left: 12px;
                right: 12px;
                top: 70px;
                min-width: 0;
                max-width: none
            }

            .modal-dialog {
                margin: 8px
            }

            .modal-body {
                padding: 14px
            }
        }

        @media(max-width:420px) {
            .stat-grid {
                grid-template-columns: 1fr 1fr
            }

            .stat-label {
                font-size: 8px
            }

            .stat-value {
                font-size: 17px
            }

            .users-table tbody td {
                gap: 8px
            }

            .role-badge,
            .branch-badge {
                max-width: 58%
            }
        }

        /* Index.php-aligned users page */
        .content-wrap{background:var(--page-bg);}
        .stat-grid{gap:8px;margin-bottom:10px;}
        .stat-card{background:var(--card-bg);border:1px solid var(--border-color);border-radius:var(--radius);box-shadow:none;padding:12px 14px;min-height:82px;display:flex;align-items:center;gap:12px;}
        .stat-icon{width:42px;height:42px;flex:0 0 42px;display:flex;align-items:center;justify-content:center;border-radius:calc(var(--radius)*.75);background:var(--primary-soft);color:var(--primary-dark);font-size:16px;}
        .stat-content{min-width:0;flex:1;}
        .stat-label{font-size:10px;color:var(--muted-color);}
        .stat-value{font-size:22px;line-height:1.1;color:var(--text-color);}
        .users-toolbar{background:var(--card-bg);border:1px solid var(--border-color);border-radius:var(--radius);padding:10px 12px;margin-bottom:10px;box-shadow:none;}
        .users-search input,.users-toolbar .form-select{border-color:var(--border-color);background:var(--card-bg);color:var(--text-color);}
        .btn-theme{background:linear-gradient(135deg,var(--primary),var(--primary-dark));border:0;color:#fff;border-radius:calc(var(--radius)*.65);font-size:11px;font-weight:700;padding:9px 14px;}
        .users-card{background:var(--card-bg);border:1px solid var(--border-color);border-radius:var(--radius);box-shadow:none;}
        .users-table th{background:color-mix(in srgb,var(--muted-color) 6%,transparent);color:var(--muted-color);border-color:var(--border-color);padding:10px 12px;}
        .users-table td{border-color:var(--border-color);color:var(--text-color);background:var(--card-bg)!important;}
        .users-table tbody tr{background:var(--card-bg)!important;}
        .users-table>tbody>tr>*{--bs-table-bg:var(--card-bg);--bs-table-color:var(--text-color);background-color:var(--card-bg)!important;color:var(--text-color)!important;}
        .user-avatar{border-radius:calc(var(--radius)*.7);}
        .user-avatar-initials{background:linear-gradient(135deg,var(--primary),var(--primary-dark));}
        .role-badge{background:var(--primary-soft);color:var(--primary-dark);}
        .action-btn{border-color:var(--border-color);background:var(--card-bg);color:var(--text-color);border-radius:calc(var(--radius)*.65);}
        .action-btn:hover{background:var(--primary-soft);color:var(--primary-dark);}
        .modal-content{background:var(--card-bg);color:var(--text-color);border-radius:var(--radius);}
        .modal-header,.modal-footer{border-color:var(--border-color);}
        .section-title{font-family:<?php echo json_encode((string)$theme['heading_font_family']); ?>,serif;font-size:14px;border-color:var(--border-color);}
        .selection-box{border-color:var(--border-color);}
        .form-control,.form-select{border-color:var(--border-color);background:var(--card-bg);color:var(--text-color);}
        @media(max-width:991.98px){
            .users-card{background:transparent;border:0;box-shadow:none;}
            .users-table tbody tr{background:var(--card-bg);border:1px solid var(--border-color);border-radius:var(--radius);box-shadow:none;}
            .users-table tbody td{border-bottom-color:var(--border-color);}
        }
        body.dark-mode,body[data-theme="dark"],html.dark-mode body,html[data-theme="dark"] body{--page-bg:#0f151b;--card-bg:#182129;--text-color:#f3f6f8;--muted-color:#9aa7b3;--border-color:#2c3944;}
        body.dark-mode .stat-card,body.dark-mode .users-toolbar,body.dark-mode .users-card,body.dark-mode .users-table tbody tr,body[data-theme="dark"] .stat-card,body[data-theme="dark"] .users-toolbar,body[data-theme="dark"] .users-card,body[data-theme="dark"] .users-table tbody tr{background:var(--card-bg)!important;border-color:var(--border-color)!important;color:var(--text-color)!important;}
        body.dark-mode .users-table td,body.dark-mode .users-table>tbody>tr>*,body[data-theme="dark"] .users-table td,body[data-theme="dark"] .users-table>tbody>tr>*,html.dark-mode body .users-table td,html.dark-mode body .users-table>tbody>tr>*,html[data-theme="dark"] body .users-table td,html[data-theme="dark"] body .users-table>tbody>tr>*{background:var(--card-bg)!important;background-color:var(--card-bg)!important;color:var(--text-color)!important;border-color:var(--border-color)!important;--bs-table-bg:var(--card-bg);--bs-table-color:var(--text-color);}
        body.dark-mode .user-name,body.dark-mode .user-sub,body.dark-mode .users-table td,body[data-theme="dark"] .user-name,body[data-theme="dark"] .user-sub,body[data-theme="dark"] .users-table td{color:var(--text-color)!important;}
        body.dark-mode .user-sub,body[data-theme="dark"] .user-sub{color:var(--muted-color)!important;}
        body.dark-mode .stat-icon,body[data-theme="dark"] .stat-icon{background:#2b2414;color:var(--primary);}

    </style>
</head>

<body>
    <?php include('includes/sidebar.php'); ?>
    <main class="app-main">
        <?php include('includes/nav.php'); ?>
        <div class="content-wrap">
            <?php if (!$canView): ?>
                <div class="users-card">
                    <div class="empty-state"><i class="fa-solid fa-user-lock"></i>
                        <div>You do not have permission to view users.</div>
                    </div>
                </div>
            <?php else: ?>
                <div class="stat-grid">
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fa-solid fa-users"></i></div>
                        <div class="stat-content"><div class="stat-label">Total Users</div><div class="stat-value" id="statTotal"><?php echo $stats['total']; ?></div></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fa-solid fa-user-check"></i></div>
                        <div class="stat-content"><div class="stat-label">Active Users</div><div class="stat-value" id="statActive"><?php echo $stats['active']; ?></div></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fa-solid fa-user-slash"></i></div>
                        <div class="stat-content"><div class="stat-label">Inactive Users</div><div class="stat-value" id="statInactive"><?php echo $stats['inactive']; ?></div></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fa-solid fa-key"></i></div>
                        <div class="stat-content"><div class="stat-label">Password Change Pending</div><div class="stat-value" id="statMustChange"><?php echo $stats['must_change']; ?></div></div>
                    </div>
                </div>

                <div class="users-toolbar">
                    <div class="users-toolbar-left">
                        <div class="users-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search"
                                class="form-control" id="userSearch" placeholder="Search name, username, email, mobile...">
                        </div>
                        <select class="form-select" id="statusFilter" style="width:140px">
                            <option value="">All status</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                        <select class="form-select" id="roleFilter" style="width:170px">
                            <option value="">All roles</option><?php foreach ($roles as $role): ?>
                                <option value="<?php echo e(strtolower($role['role_name'])); ?>">
                                    <?php echo e($role['role_name']); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <?php if ($canCreate): ?><button type="button" class="btn btn-theme btn-sm" id="addUserButton"><i
                                class="fa-solid fa-user-plus me-2"></i>Add User</button><?php endif; ?>
                </div>

                <div class="users-card card-panel">
                    <div class="table-responsive module-card-table">
                        <table class="table users-table align-middle" id="usersTable">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Contact</th>
                                    <th>Role</th>
                                    <th>Branches</th>
                                    <th>Status</th>
                                    <th>Last Login</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                    <?php $initials = strtoupper(substr((string) $user['full_name'], 0, 1)); ?>
                                    <tr data-user-id="<?php echo (int) $user['id']; ?>"
                                        data-search="<?php echo e(strtolower(implode(' ', [$user['full_name'], $user['username'], $user['email'], $user['mobile'], $user['employee_code'], $user['role_names'], $user['branch_names']]))); ?>"
                                        data-status="<?php echo (int) $user['is_active'] === 1 ? 'active' : 'inactive'; ?>"
                                        data-role="<?php echo e(strtolower((string) $user['role_names'])); ?>">
                                        <td class="user-column" data-label="User">
                                            <div class="user-cell"><?php if (!empty($user['profile_photo_path'])): ?><img
                                                        class="user-avatar" src="<?php echo e($user['profile_photo_path']); ?>"
                                                        alt=""><?php else: ?><span
                                                        class="user-avatar user-avatar-initials"><?php echo e($initials ?: 'U'); ?></span><?php endif; ?>
                                                <div>
                                                    <div class="user-name"><?php echo e($user['full_name']); ?></div>
                                                    <div class="user-sub">
                                                        @<?php echo e($user['username']); ?><?php if ($user['employee_code']): ?>
                                                            · <?php echo e($user['employee_code']); ?><?php endif; ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td data-label="Contact">
                                            <div><?php echo e($user['mobile'] ?: '—'); ?></div>
                                            <div class="user-sub"><?php echo e($user['email'] ?: 'No email'); ?></div>
                                        </td>
                                        <td data-label="Role"><span
                                                class="role-badge"><?php echo e($user['role_names'] ?: 'No role'); ?></span>
                                        </td>
                                        <td data-label="Branches"><span
                                                class="branch-badge"><?php echo e($user['branch_names'] ?: $user['default_branch_name'] ?: 'No branch'); ?></span>
                                        </td>
                                        <td data-label="Status"><span
                                                class="status-badge <?php echo (int) $user['is_active'] === 1 ? 'status-active' : 'status-inactive'; ?>"><?php echo (int) $user['is_active'] === 1 ? 'Active' : 'Inactive'; ?></span><?php if ((int) $user['must_change_password'] === 1): ?>
                                                <div class="user-sub">Password change required</div><?php endif; ?>
                                        </td>
                                        <td data-label="Last Login">
                                            <?php echo $user['last_login_at'] ? e(date('d M Y, h:i A', strtotime($user['last_login_at']))) : 'Never'; ?>
                                        </td>
                                        <td class="text-end actions-column" data-label="Actions">
                                            <div class="d-inline-flex gap-1">
                                                <?php if ($canUpdate): ?><button class="action-btn edit-user" type="button"
                                                        title="Edit" data-id="<?php echo (int) $user['id']; ?>"><i
                                                            class="fa-solid fa-pen"></i></button><button
                                                        class="action-btn toggle-user" type="button"
                                                        title="<?php echo (int) $user['is_active'] === 1 ? 'Deactivate' : 'Activate'; ?>"
                                                        data-id="<?php echo (int) $user['id']; ?>"
                                                        data-active="<?php echo (int) $user['is_active']; ?>"><i
                                                            class="fa-solid <?php echo (int) $user['is_active'] === 1 ? 'fa-user-slash' : 'fa-user-check'; ?>"></i></button><button
                                                        class="action-btn reset-password" type="button" title="Reset password"
                                                        data-id="<?php echo (int) $user['id']; ?>"
                                                        data-name="<?php echo e($user['full_name']); ?>"><i
                                                            class="fa-solid fa-key"></i></button><?php endif; ?>
                                                <?php if ($canDelete && (int) $user['id'] !== $currentUserId): ?><button
                                                        class="action-btn danger delete-user" type="button" title="Delete"
                                                        data-id="<?php echo (int) $user['id']; ?>"
                                                        data-name="<?php echo e($user['full_name']); ?>"><i
                                                            class="fa-solid fa-trash"></i></button><?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if (!$users): ?>
                        <div class="empty-state"><i class="fa-regular fa-user"></i>
                            <div>No users found.</div>
                        </div><?php endif; ?>
                </div>
            <?php endif; ?>
            <?php include('includes/footer.php'); ?>
        </div>
    </main>

    <div class="modal fade" id="userModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <form class="modal-content" id="userForm" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title" id="userModalTitle">Add User</h5><button type="button" class="btn-close"
                        data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="user_id" id="user_id" value="0">
                    <input type="hidden" name="existing_photo_path" id="existing_photo_path" value="">
                    <div class="section-title">User Information</div>
                    <div class="row g-3">
                        <div class="col-md-4"><label class="field-label">Full name <span
                                    class="text-danger">*</span></label><input class="form-control" type="text"
                                name="full_name" id="full_name" maxlength="150" required></div>
                        <div class="col-md-4"><label class="field-label">Username <span
                                    class="text-danger">*</span></label><input class="form-control" type="text"
                                name="username" id="username" maxlength="100" autocomplete="off" required></div>
                        <div class="col-md-4"><label class="field-label">Employee code</label><input
                                class="form-control" type="text" name="employee_code" id="employee_code" maxlength="50">
                        </div>
                        <div class="col-md-4"><label class="field-label">Email</label><input class="form-control"
                                type="email" name="email" id="email" maxlength="150"></div>
                        <div class="col-md-4"><label class="field-label">Mobile</label><input class="form-control"
                                type="text" name="mobile" id="mobile" maxlength="20"></div>
                        <div class="col-md-4"><label class="field-label">Profile photo</label><input
                                class="form-control" type="file" name="profile_photo" id="profile_photo"
                                accept=".png,.jpg,.jpeg,.webp">
                            <div class="form-check mt-2"><input class="form-check-input" type="checkbox"
                                    name="remove_photo" value="1" id="remove_photo"><label class="form-check-label"
                                    for="remove_photo">Remove current photo</label></div>
                        </div>
                        <?php if ($isPlatformAdmin): ?>
                            <div class="col-md-4"><label class="field-label">User type</label><select class="form-select"
                                    name="user_type" id="user_type">
                                    <option value="Business User">Business User</option>
                                    <option value="Platform Admin">Platform Admin</option>
                                </select></div><?php else: ?><input type="hidden" name="user_type" id="user_type"
                                value="Business User"><?php endif; ?>
                        <div class="col-md-4"><label class="field-label">Status</label><select class="form-select"
                                name="is_active" id="is_active">
                                <option value="1">Active</option>
                                <option value="0">Inactive</option>
                            </select></div>
                        <div class="col-md-4"><label class="field-label">Password <span id="passwordRequired"
                                    class="text-danger">*</span></label>
                            <div class="password-wrap"><input class="form-control" type="password" name="password"
                                    id="password" minlength="8" autocomplete="new-password">
                                <div class="password-actions"><button type="button" id="generatePassword"
                                        title="Generate"><i class="fa-solid fa-wand-magic-sparkles"></i></button><button
                                        type="button" id="togglePassword" title="Show"><i
                                            class="fa-regular fa-eye"></i></button></div>
                            </div>
                            <div class="user-sub">Leave blank while editing to keep the current password.</div>
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <div class="form-check mb-2"><input class="form-check-input" type="checkbox"
                                    name="must_change_password" value="1" id="must_change_password" checked><label
                                    class="form-check-label" for="must_change_password">Require password change at next
                                    login</label></div>
                        </div>
                    </div>

                    <div class="row g-4 mt-1">
                        <div class="col-lg-6">
                            <div class="section-title">Roles</div>
                            <div class="selection-box" id="rolesBox"><?php foreach ($roles as $role): ?>
                                    <div class="selection-item"><input class="form-check-input role-check" type="checkbox"
                                            name="role_ids[]" value="<?php echo (int) $role['id']; ?>"
                                            id="role_<?php echo (int) $role['id']; ?>"><label
                                            for="role_<?php echo (int) $role['id']; ?>"><strong><?php echo e($role['role_name']); ?></strong><?php if ($role['description']): ?>
                                                <div class="user-sub"><?php echo e($role['description']); ?></div>
                                            <?php endif; ?>
                                        </label><input class="form-check-input primary-role" type="radio"
                                            name="primary_role_id" value="<?php echo (int) $role['id']; ?>"
                                            title="Primary role"></div><?php endforeach; ?>
                            </div>
                            <div class="user-sub mt-1">Select one or more roles and mark one as primary.</div>
                        </div>
                        <div class="col-lg-6">
                            <div class="section-title">Branch Access</div>
                            <div class="selection-box" id="branchesBox"><?php foreach ($branches as $branch): ?>
                                    <div class="selection-item"><input class="form-check-input branch-check" type="checkbox"
                                            name="branch_ids[]" value="<?php echo (int) $branch['id']; ?>"
                                            id="branch_<?php echo (int) $branch['id']; ?>"><label
                                            for="branch_<?php echo (int) $branch['id']; ?>"><strong><?php echo e($branch['branch_name']); ?></strong>
                                            <div class="user-sub">
                                                <?php echo e($branch['branch_code'] . ' · ' . $branch['branch_type']); ?>
                                            </div>
                                        </label><input class="form-check-input default-branch" type="radio"
                                            name="default_branch_id" value="<?php echo (int) $branch['id']; ?>"
                                            title="Default branch"></div><?php endforeach; ?>
                            </div>
                            <div class="form-check mt-2"><input class="form-check-input" type="checkbox"
                                    name="can_switch_branch" value="1" id="can_switch_branch"><label
                                    class="form-check-label" for="can_switch_branch">Allow switching between assigned
                                    branches</label></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light btn-sm"
                        data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-theme btn-sm"
                        id="saveUserButton"><i class="fa-solid fa-floppy-disk me-2"></i>Save User</button></div>
            </form>
        </div>
    </div>

    <div class="modal fade" id="passwordModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" id="passwordForm">
                <div class="modal-header">
                    <h5 class="modal-title">Reset Password</h5><button type="button" class="btn-close"
                        data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body"><input type="hidden" name="csrf_token"
                        value="<?php echo e($csrfToken); ?>"><input type="hidden" name="action"
                        value="reset_password"><input type="hidden" name="user_id" id="password_user_id">
                    <div class="mb-3">Set a new password for <strong id="password_user_name"></strong>.</div><label
                        class="field-label">New password</label>
                    <div class="password-wrap"><input class="form-control" type="password" name="new_password"
                            id="new_password" minlength="8" required>
                        <div class="password-actions"><button type="button" id="generateResetPassword"><i
                                    class="fa-solid fa-wand-magic-sparkles"></i></button><button type="button"
                                id="toggleResetPassword"><i class="fa-regular fa-eye"></i></button></div>
                    </div>
                    <div class="form-check mt-3"><input class="form-check-input" type="checkbox"
                            name="must_change_password" value="1" id="reset_must_change" checked><label
                            class="form-check-label" for="reset_must_change">Require password change at next
                            login</label></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light btn-sm"
                        data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-theme btn-sm">Reset
                        Password</button></div>
            </form>
        </div>
    </div>

    <div class="offcanvas-backdrop fade" id="mobileBackdrop" style="display:none"></div>
    <?php include('includes/script.php'); ?>
    <script src="assets/js/script.js"></script>
    <script>
        (function () {
            'use strict';
            const csrfToken = <?php echo json_encode($csrfToken); ?>;
            const currentUserId = <?php echo $currentUserId; ?>;
            const userModalEl = document.getElementById('userModal');
            const userModal = userModalEl ? new bootstrap.Modal(userModalEl) : null;
            const passwordModalEl = document.getElementById('passwordModal');
            const passwordModal = passwordModalEl ? new bootstrap.Modal(passwordModalEl) : null;
            const userForm = document.getElementById('userForm');
            const passwordForm = document.getElementById('passwordForm');

            function showToast(type, message) { const t = document.createElement('div'); t.className = 'theme-toast theme-toast-' + type; t.innerHTML = '<i class="fa-solid ' + (type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation') + '"></i><span></span>'; t.querySelector('span').textContent = message; document.body.appendChild(t); requestAnimationFrame(function () { t.classList.add('show') }); setTimeout(function () { t.classList.remove('show'); setTimeout(function () { t.remove() }, 250) }, 3200) }
            async function api(formData) { const response = await fetch('api/users-save.php', { method: 'POST', body: formData, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } }); const result = await response.json().catch(function () { return { success: false, message: 'Invalid response received from the server.' } }); if (!response.ok || !result.success) throw new Error(result.message || 'Request failed.'); return result }
            function generatePassword() { const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789@#$!'; let out = ''; for (let i = 0; i < 12; i++)out += chars.charAt(Math.floor(Math.random() * chars.length)); return out }
            function togglePassword(input, button) { if (!input || !button) return; input.type = input.type === 'password' ? 'text' : 'password'; button.innerHTML = '<i class="fa-regular ' + (input.type === 'password' ? 'fa-eye' : 'fa-eye-slash') + '"></i>' }

            const search = document.getElementById('userSearch'), status = document.getElementById('statusFilter'), role = document.getElementById('roleFilter');
            function filterRows() { const q = (search ? search.value : '').trim().toLowerCase(), s = status ? status.value : '', r = role ? role.value : ''; document.querySelectorAll('#usersTable tbody tr').forEach(function (row) { const visible = (!q || row.dataset.search.indexOf(q) !== -1) && (!s || row.dataset.status === s) && (!r || row.dataset.role.indexOf(r) !== -1); row.style.display = visible ? '' : 'none' }) }
            [search, status, role].forEach(function (el) { if (el) el.addEventListener('input', filterRows) });

            function resetForm() { userForm.reset(); document.getElementById('user_id').value = '0'; document.getElementById('existing_photo_path').value = ''; document.getElementById('userModalTitle').textContent = 'Add User'; document.getElementById('passwordRequired').style.display = 'inline'; document.getElementById('password').required = true; document.getElementById('must_change_password').checked = true; document.querySelectorAll('.role-check,.branch-check,.primary-role,.default-branch').forEach(function (el) { el.checked = false }); }
            const addButton = document.getElementById('addUserButton'); if (addButton) addButton.addEventListener('click', function () { resetForm(); userModal.show() });

            document.querySelectorAll('.role-check').forEach(function (check) { check.addEventListener('change', function () { const radio = document.querySelector('.primary-role[value="' + check.value + '"]'); if (!check.checked && radio) radio.checked = false; if (check.checked && !document.querySelector('.primary-role:checked') && radio) radio.checked = true }) });
            document.querySelectorAll('.primary-role').forEach(function (radio) { radio.addEventListener('change', function () { const check = document.querySelector('.role-check[value="' + radio.value + '"]'); if (check) check.checked = true }) });
            document.querySelectorAll('.branch-check').forEach(function (check) { check.addEventListener('change', function () { const radio = document.querySelector('.default-branch[value="' + check.value + '"]'); if (!check.checked && radio) radio.checked = false; if (check.checked && !document.querySelector('.default-branch:checked') && radio) radio.checked = true }) });
            document.querySelectorAll('.default-branch').forEach(function (radio) { radio.addEventListener('change', function () { const check = document.querySelector('.branch-check[value="' + radio.value + '"]'); if (check) check.checked = true }) });

            document.addEventListener('click', async function (event) {
                const edit = event.target.closest('.edit-user');
                if (edit) { try { const fd = new FormData(); fd.append('action', 'get'); fd.append('csrf_token', csrfToken); fd.append('user_id', edit.dataset.id); const result = await api(fd); resetForm(); const u = result.user; document.getElementById('userModalTitle').textContent = 'Edit User'; document.getElementById('user_id').value = u.id; document.getElementById('full_name').value = u.full_name || ''; document.getElementById('username').value = u.username || ''; document.getElementById('employee_code').value = u.employee_code || ''; document.getElementById('email').value = u.email || ''; document.getElementById('mobile').value = u.mobile || ''; document.getElementById('existing_photo_path').value = u.profile_photo_path || ''; document.getElementById('is_active').value = String(u.is_active); document.getElementById('user_type').value = u.user_type || 'Business User'; document.getElementById('must_change_password').checked = Number(u.must_change_password) === 1; document.getElementById('can_switch_branch').checked = Number(u.can_switch_branch) === 1; document.getElementById('password').required = false; document.getElementById('passwordRequired').style.display = 'none'; (result.role_ids || []).forEach(function (id) { const el = document.querySelector('.role-check[value="' + id + '"]'); if (el) el.checked = true }); if (result.primary_role_id) { const el = document.querySelector('.primary-role[value="' + result.primary_role_id + '"]'); if (el) el.checked = true } (result.branch_ids || []).forEach(function (id) { const el = document.querySelector('.branch-check[value="' + id + '"]'); if (el) el.checked = true }); if (u.default_branch_id) { const el = document.querySelector('.default-branch[value="' + u.default_branch_id + '"]'); if (el) el.checked = true } userModal.show() } catch (err) { showToast('error', err.message) } }
                const toggle = event.target.closest('.toggle-user');
                if (toggle) { const next = Number(toggle.dataset.active) === 1 ? 0 : 1; if (Number(toggle.dataset.id) === currentUserId && next === 0) { showToast('error', 'You cannot deactivate your own account.'); return } if (!confirm((next === 1 ? 'Activate' : 'Deactivate') + ' this user?')) return; const fd = new FormData(); fd.append('action', 'toggle'); fd.append('csrf_token', csrfToken); fd.append('user_id', toggle.dataset.id); fd.append('is_active', String(next)); try { const result = await api(fd); showToast('success', result.message); setTimeout(function () { location.reload() }, 500) } catch (err) { showToast('error', err.message) } }
                const del = event.target.closest('.delete-user');
                if (del) { if (!confirm('Delete ' + del.dataset.name + '? This cannot be undone.')) return; const fd = new FormData(); fd.append('action', 'delete'); fd.append('csrf_token', csrfToken); fd.append('user_id', del.dataset.id); try { const result = await api(fd); showToast('success', result.message); del.closest('tr').remove() } catch (err) { showToast('error', err.message) } }
                const reset = event.target.closest('.reset-password');
                if (reset) { document.getElementById('password_user_id').value = reset.dataset.id; document.getElementById('password_user_name').textContent = reset.dataset.name; document.getElementById('new_password').value = ''; document.getElementById('reset_must_change').checked = true; passwordModal.show() }
            });

            document.getElementById('generatePassword').addEventListener('click', function () { const el = document.getElementById('password'); el.value = generatePassword(); el.type = 'text' }); document.getElementById('togglePassword').addEventListener('click', function () { togglePassword(document.getElementById('password'), this) }); document.getElementById('generateResetPassword').addEventListener('click', function () { const el = document.getElementById('new_password'); el.value = generatePassword(); el.type = 'text' }); document.getElementById('toggleResetPassword').addEventListener('click', function () { togglePassword(document.getElementById('new_password'), this) });

            if (userForm) userForm.addEventListener('submit', async function (event) { event.preventDefault(); if (!document.querySelector('.role-check:checked')) { showToast('error', 'Select at least one role.'); return } if (!document.querySelector('.branch-check:checked')) { showToast('error', 'Select at least one branch.'); return } const btn = document.getElementById('saveUserButton'), old = btn.innerHTML; btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Saving...'; try { const result = await api(new FormData(userForm)); showToast('success', result.message); userModal.hide(); setTimeout(function () { location.reload() }, 500) } catch (err) { showToast('error', err.message) } finally { btn.disabled = false; btn.innerHTML = old } });
            if (passwordForm) passwordForm.addEventListener('submit', async function (event) { event.preventDefault(); const btn = passwordForm.querySelector('button[type="submit"]'), old = btn.innerHTML; btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Saving...'; try { const result = await api(new FormData(passwordForm)); showToast('success', result.message); passwordModal.hide() } catch (err) { showToast('error', err.message) } finally { btn.disabled = false; btn.innerHTML = old } });
        })();
    </script>
</body>

</html>