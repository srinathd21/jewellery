<?php
session_start();
date_default_timezone_set('Asia/Kolkata');

$configCandidates = [
    __DIR__ . '/config/config.php',
    __DIR__ . '/config.php',
    __DIR__ . '/super-admin/includes/config.php',
];

$configLoaded = false;
foreach ($configCandidates as $configFile) {
    if (is_file($configFile)) {
        require_once $configFile;
        $configLoaded = true;
        break;
    }
}

if (!$configLoaded) {
    die('Database configuration file not found.');
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not available. Check your common config file.');
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn->set_charset('utf8mb4');

function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function clientIp(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

function redirectLoggedInUser(): void
{
    if (empty($_SESSION['user_id'])) {
        return;
    }

    // All users enter the same application. The database permissions decide
    // which menus, pages, buttons and values are available to each user.
    header('Location: index.php');
    exit;
}

function writeLoginAudit(mysqli $conn, array $user, ?int $branchId): void
{
    $sql = "INSERT INTO audit_logs
            (business_id, branch_id, user_id, module_code, action_type,
             reference_table, reference_id, description, ip_address, user_agent)
            VALUES (?, ?, ?, 'auth.login', 'Login', 'users', ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return;
    }

    $businessId = isset($user['business_id']) ? (int)$user['business_id'] : null;
    $userId = (int)$user['id'];
    $description = 'User logged in successfully';
    $ip = clientIp();
    $agent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

    $stmt->bind_param(
        'iiiisss',
        $businessId,
        $branchId,
        $userId,
        $userId,
        $description,
        $ip,
        $agent
    );
    $stmt->execute();
    $stmt->close();
}

redirectLoggedInUser();

$error = '';
$success = '';
$loginId = trim((string)($_POST['login_id'] ?? ''));
$remember = isset($_POST['remember']);

if (empty($_SESSION['login_csrf'])) {
    $_SESSION['login_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['login_csrf'];

if (!empty($_SESSION['register_success'])) {
    $success = (string)$_SESSION['register_success'];
    unset($_SESSION['register_success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string)($_POST['csrf_token'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if (!hash_equals($csrfToken, $postedToken)) {
        $error = 'Your login session expired. Please refresh and try again.';
    } elseif ($loginId === '' || $password === '') {
        $error = 'Enter your username, email or mobile number and password.';
    } else {
        $sql = "SELECT
                    u.id,
                    u.business_id,
                    u.default_branch_id,
                    u.employee_code,
                    u.full_name,
                    u.username,
                    u.email,
                    u.mobile,
                    u.password_hash,
                    u.profile_photo_path,
                    u.user_type,
                    u.must_change_password,
                    u.is_active,
                    u.last_login_at,
                    b.business_code,
                    b.business_name,
                    b.status AS business_status,
                    b.currency_code,
                    b.currency_symbol,
                    b.timezone,
                    br.branch_name AS default_branch_name,
                    ur.role_id,
                    r.role_code,
                    r.role_name
                FROM users u
                LEFT JOIN businesses b ON b.id = u.business_id
                LEFT JOIN branches br ON br.id = u.default_branch_id
                LEFT JOIN user_roles ur ON ur.user_id = u.id
                    AND ur.is_primary = 1
                    AND (u.business_id IS NULL OR ur.business_id = u.business_id)
                LEFT JOIN roles r ON r.id = ur.role_id
                WHERE (u.username = ? OR u.email = ? OR u.mobile = ?)
                ORDER BY u.is_active DESC, u.id ASC
                LIMIT 1";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $error = 'Unable to prepare the login request.';
        } else {
            $stmt->bind_param('sss', $loginId, $loginId, $loginId);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result ? $result->fetch_assoc() : null;
            $stmt->close();

            if (!$user) {
                $error = 'Invalid username, email, mobile number or password.';
            } elseif ((int)$user['is_active'] !== 1) {
                $error = 'Your user account is inactive. Contact your administrator.';
            } elseif ($user['user_type'] === 'Business User' && $user['business_status'] !== 'Active' && $user['business_status'] !== 'Trial') {
                $error = 'This business account is not active. Contact the platform administrator.';
            } else {
                $dbHash = (string)$user['password_hash'];
                $passwordOk = $dbHash !== '' && password_verify($password, $dbHash);

                // Temporary compatibility with old demo/plain-text passwords.
                if (!$passwordOk && $dbHash !== '' && hash_equals($dbHash, $password)) {
                    $passwordOk = true;
                }

                if (!$passwordOk) {
                    $error = 'Invalid username, email, mobile number or password.';
                } else {
                    $userId = (int)$user['id'];
                    $businessId = $user['business_id'] !== null ? (int)$user['business_id'] : null;
                    $branchId = $user['default_branch_id'] !== null ? (int)$user['default_branch_id'] : null;

                    if ($user['user_type'] === 'Business User') {
                        $branchSql = "SELECT uba.branch_id, uba.can_switch_branch, br.branch_name, br.branch_code
                                      FROM user_branch_access uba
                                      INNER JOIN branches br ON br.id = uba.branch_id
                                      WHERE uba.business_id = ? AND uba.user_id = ? AND br.is_active = 1
                                      ORDER BY uba.is_default DESC, br.is_default DESC, br.branch_name ASC";
                        $branchStmt = $conn->prepare($branchSql);
                        $allowedBranches = [];

                        if ($branchStmt) {
                            $branchStmt->bind_param('ii', $businessId, $userId);
                            $branchStmt->execute();
                            $branchResult = $branchStmt->get_result();
                            while ($branchRow = $branchResult->fetch_assoc()) {
                                $allowedBranches[] = $branchRow;
                            }
                            $branchStmt->close();
                        }

                        if (!$allowedBranches) {
                            $error = 'No active branch access is assigned to this user.';
                        } else {
                            $allowedIds = array_map(static fn($row) => (int)$row['branch_id'], $allowedBranches);
                            if (!$branchId || !in_array($branchId, $allowedIds, true)) {
                                $branchId = (int)$allowedBranches[0]['branch_id'];
                                $user['default_branch_name'] = $allowedBranches[0]['branch_name'];
                            }
                        }
                    } else {
                        $allowedBranches = [];
                    }

                    if ($error === '') {
                        session_regenerate_id(true);

                        $_SESSION['user_id'] = $userId;
                        $_SESSION['business_id'] = $businessId;
                        $_SESSION['branch_id'] = $branchId;
                        $_SESSION['default_branch_id'] = $branchId;
                        $_SESSION['role_id'] = $user['role_id'] !== null ? (int)$user['role_id'] : null;
                        $_SESSION['role_code'] = (string)($user['role_code'] ?? '');
                        $_SESSION['role_name'] = (string)($user['role_name'] ?? '');
                        $_SESSION['user_type'] = (string)$user['user_type'];
                        $_SESSION['full_name'] = (string)$user['full_name'];
                        $_SESSION['username'] = (string)$user['username'];
                        $_SESSION['email'] = (string)($user['email'] ?? '');
                        $_SESSION['mobile'] = (string)($user['mobile'] ?? '');
                        $_SESSION['profile_photo_path'] = (string)($user['profile_photo_path'] ?? '');
                        $_SESSION['business_code'] = (string)($user['business_code'] ?? '');
                        $_SESSION['business_name'] = (string)($user['business_name'] ?? '');
                        $_SESSION['branch_name'] = (string)($user['default_branch_name'] ?? '');
                        $_SESSION['currency_code'] = (string)($user['currency_code'] ?? 'INR');
                        $_SESSION['currency_symbol'] = (string)($user['currency_symbol'] ?? '₹');
                        $_SESSION['timezone'] = (string)($user['timezone'] ?? 'Asia/Kolkata');
                        $_SESSION['must_change_password'] = (int)$user['must_change_password'];
                        $_SESSION['allowed_branches'] = $allowedBranches;
                        $_SESSION['can_switch_branch'] = !empty(array_filter(
                            $allowedBranches,
                            static fn($row) => (int)$row['can_switch_branch'] === 1
                        ));

                        // Load effective permissions for the common application shell.
                        $_SESSION['permissions'] = [];
                        if (!empty($user['role_id']) && $businessId) {
                            $permissionSql = "SELECT
                                                p.permission_code,
                                                mi.menu_code,
                                                mi.route_url,
                                                mi.parent_id,
                                                mi.sort_order,
                                                rp.can_open,
                                                rp.can_view_value,
                                                rp.can_view,
                                                rp.can_create,
                                                rp.can_update,
                                                rp.can_approve,
                                                rp.can_delete
                                              FROM role_permissions rp
                                              INNER JOIN permissions p ON p.id = rp.permission_id
                                              INNER JOIN menu_items mi ON mi.id = p.menu_item_id
                                              WHERE rp.business_id = ?
                                                AND rp.role_id = ?
                                                AND p.is_active = 1
                                                AND mi.is_active = 1
                                              ORDER BY mi.sort_order ASC, mi.id ASC";
                            $permissionStmt = $conn->prepare($permissionSql);
                            if ($permissionStmt) {
                                $roleId = (int)$user['role_id'];
                                $permissionStmt->bind_param('ii', $businessId, $roleId);
                                $permissionStmt->execute();
                                $permissionResult = $permissionStmt->get_result();
                                while ($permissionRow = $permissionResult->fetch_assoc()) {
                                    $_SESSION['permissions'][(string)$permissionRow['permission_code']] = $permissionRow;
                                }
                                $permissionStmt->close();
                            }
                        }

                        $updateStmt = $conn->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?');
                        if ($updateStmt) {
                            $updateStmt->bind_param('i', $userId);
                            $updateStmt->execute();
                            $updateStmt->close();
                        }

                        writeLoginAudit($conn, $user, $branchId);

                        if ($remember) {
                            setcookie('jewellery_login_id', $loginId, [
                                'expires' => time() + (86400 * 30),
                                'path' => '/',
                                'secure' => !empty($_SERVER['HTTPS']),
                                'httponly' => true,
                                'samesite' => 'Lax',
                            ]);
                        }

                        $_SESSION['login_csrf'] = bin2hex(random_bytes(32));

                        // Common entry point for every user. Access is controlled by
                        // role_permissions and the dynamic menu/permission checks.
                        header('Location: index.php');
                        exit;
                    }
                }
            }
        }
    }
}

if ($loginId === '' && !empty($_COOKIE['jewellery_login_id'])) {
    $loginId = trim((string)$_COOKIE['jewellery_login_id']);
}

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
    'logo_path' => '',
    'login_background_path' => '',
    'font_family' => 'Inter',
    'heading_font_family' => 'Playfair Display',
    'border_radius_px' => 14,
];
$displayBusinessName = 'Jewellery ERP';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>Login | <?php echo h($displayBusinessName); ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <style>
        :root {
            --primary: <?php echo h($theme['primary_color']); ?>;
            --primary-dark: <?php echo h($theme['primary_dark_color']); ?>;
            --primary-soft: <?php echo h($theme['primary_soft_color']); ?>;
            --sidebar-1: <?php echo h($theme['sidebar_gradient_1']); ?>;
            --sidebar-2: <?php echo h($theme['sidebar_gradient_2']); ?>;
            --sidebar-3: <?php echo h($theme['sidebar_gradient_3']); ?>;
            --page-bg: <?php echo h($theme['page_background']); ?>;
            --card-bg: <?php echo h($theme['card_background']); ?>;
            --text: <?php echo h($theme['text_color']); ?>;
            --muted: <?php echo h($theme['muted_text_color']); ?>;
            --line: <?php echo h($theme['border_color']); ?>;
            --radius: <?php echo (int)$theme['border_radius_px']; ?>px;
            --ease-premium: cubic-bezier(.22, 1, .36, 1);
        }

        * { box-sizing: border-box; }
        html, body { min-height: 100%; }
        body {
            margin: 0;
            overflow-x: hidden;
            font-family: "Inter", sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at 8% 12%, color-mix(in srgb, var(--primary) 13%, transparent), transparent 30%),
                radial-gradient(circle at 92% 88%, color-mix(in srgb, var(--primary-dark) 12%, transparent), transparent 32%),
                var(--page-bg);
        }

        .login-shell {
            min-height: 100vh;
            display: grid;
            grid-template-columns: minmax(440px, 1.08fr) minmax(420px, .92fr);
            opacity: 0;
            transform: scale(.985);
            transition: opacity .7s var(--ease-premium), transform .8s var(--ease-premium);
        }
        body.page-ready .login-shell { opacity: 1; transform: scale(1); }

        .brand-panel {
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: clamp(32px, 5vw, 72px);
            color: #fff;
            background:
                linear-gradient(145deg, rgba(0,0,0,.08), rgba(0,0,0,.36)),
                linear-gradient(145deg, var(--sidebar-1), var(--sidebar-2) 52%, var(--sidebar-3));
        }
        .brand-panel::before,
        .brand-panel::after {
            content: "";
            position: absolute;
            border: 1px solid rgba(255,255,255,.08);
            border-radius: 50%;
        }
        .brand-panel::before { width: 520px; height: 520px; right: -220px; top: -220px; }
        .brand-panel::after { width: 360px; height: 360px; left: -160px; bottom: -180px; }

        .ambient-gem {
            position: absolute;
            width: 8px;
            height: 8px;
            border-radius: 2px;
            background: linear-gradient(135deg, #fff6c5, var(--primary));
            box-shadow: 0 0 20px color-mix(in srgb, var(--primary) 72%, transparent);
            transform: rotate(45deg);
            opacity: .55;
            animation: floatGem 6s ease-in-out infinite;
        }
        .ambient-gem.g1 { left: 12%; top: 18%; }
        .ambient-gem.g2 { right: 15%; top: 28%; animation-delay: -2s; }
        .ambient-gem.g3 { left: 30%; bottom: 18%; animation-delay: -4s; }
        @keyframes floatGem {
            0%,100% { transform: translateY(0) rotate(45deg) scale(1); }
            50% { transform: translateY(-18px) rotate(90deg) scale(1.2); }
        }

        .brand-content, .brand-footer { position: relative; z-index: 2; }
        .brand-mark {
            width: 56px;
            height: 56px;
            display: grid;
            place-items: center;
            border-radius: 17px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            box-shadow: 0 16px 40px color-mix(in srgb, var(--primary) 30%, transparent);
            color: #fff;
            font-size: 24px;
        }
        .brand-logo { max-width: 180px; max-height: 72px; object-fit: contain; object-position: left center; }
        .brand-title {
            max-width: 720px;
            margin: 68px 0 18px;
            font-family: "Playfair Display", serif;
            font-size: clamp(38px, 5vw, 68px);
            line-height: 1.05;
            font-weight: 700;
        }
        .brand-copy { max-width: 650px; color: rgba(255,255,255,.72); font-size: 15px; line-height: 1.8; }
        .feature-grid { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 12px; margin-top: 34px; max-width: 680px; }
        .feature-item { display: flex; align-items: center; gap: 11px; padding: 13px 14px; border: 1px solid rgba(255,255,255,.1); background: rgba(255,255,255,.055); border-radius: 13px; backdrop-filter: blur(8px); font-size: 12px; transition: .25s ease; }
        .feature-item:hover { transform: translateY(-2px); border-color: rgba(255,255,255,.2); }
        .feature-item i { color: color-mix(in srgb, var(--primary) 82%, white); }
        .brand-footer { color: rgba(255,255,255,.55); font-size: 11px; }

        .form-panel {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 30px;
            perspective: 1400px;
        }
        .form-panel::before {
            content: "";
            position: absolute;
            width: 360px;
            height: 360px;
            border-radius: 50%;
            background: color-mix(in srgb, var(--primary) 11%, transparent);
            filter: blur(5px);
        }

        .login-card {
            position: relative;
            z-index: 2;
            width: min(100%, 470px);
            background: color-mix(in srgb, var(--card-bg) 95%, transparent);
            border: 1px solid var(--line);
            border-radius: calc(var(--radius) + 8px);
            box-shadow: 0 24px 70px rgba(24,31,40,.12);
            padding: clamp(28px, 4vw, 44px);
            backdrop-filter: blur(18px);
            opacity: 0;
            transform: translateY(34px) rotateX(-9deg) scale(.94);
            transform-origin: top center;
            transition: opacity .75s .23s var(--ease-premium), transform .9s .23s var(--ease-premium), box-shadow .3s ease;
        }
        body.welcome-ready .login-card {
            opacity: 1;
            transform: translateY(0) rotateX(0) scale(1);
        }
        .login-card:hover { box-shadow: 0 30px 85px rgba(24,31,40,.15); }

        .welcome-line {
            position: absolute;
            top: 0;
            left: 12%;
            right: 12%;
            height: 3px;
            border-radius: 0 0 8px 8px;
            background: linear-gradient(90deg, transparent, var(--primary), transparent);
            transform: scaleX(0);
            transition: transform .75s .6s var(--ease-premium);
        }
        body.welcome-ready .welcome-line { transform: scaleX(1); }

        .login-eyebrow { display: inline-flex; align-items: center; gap: 7px; padding: 6px 10px; border-radius: 999px; background: var(--primary-soft); color: var(--primary-dark); font-size: 10px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
        .login-title { font-family: "Playfair Display", serif; font-size: 31px; font-weight: 700; margin: 18px 0 7px; }
        .login-subtitle { margin: 0 0 28px; color: var(--muted); font-size: 13px; line-height: 1.65; }
        .form-label { font-size: 11px; font-weight: 700; color: #4b5563; margin-bottom: 7px; }
        .input-group-modern { position: relative; }
        .input-group-modern > i { position: absolute; z-index: 4; left: 14px; top: 50%; transform: translateY(-50%); color: #8b95a2; transition: .2s ease; }
        .input-group-modern .form-control { height: 48px; border: 1px solid var(--line); border-radius: 12px; padding-left: 42px; padding-right: 42px; background: var(--card-bg); color: var(--text); font-size: 13px; box-shadow: none; transition: .22s ease; }
        .input-group-modern:focus-within > i { color: var(--primary-dark); }
        .input-group-modern .form-control:focus { border-color: var(--primary); box-shadow: 0 0 0 .22rem color-mix(in srgb, var(--primary) 13%, transparent); transform: translateY(-1px); }
        .password-toggle { position: absolute; z-index: 5; right: 8px; top: 50%; transform: translateY(-50%); width: 34px; height: 34px; border: 0; background: transparent; color: #8b95a2; border-radius: 9px; }
        .form-check-label { color: var(--muted); font-size: 11px; }
        .form-check-input:checked { background-color: var(--primary); border-color: var(--primary); }
        .login-btn { position: relative; overflow: hidden; height: 48px; border: 0; border-radius: 12px; background: linear-gradient(135deg, var(--primary), var(--primary-dark)); color: #fff; font-weight: 800; font-size: 13px; box-shadow: 0 13px 28px color-mix(in srgb, var(--primary) 25%, transparent); transition: transform .2s ease, filter .2s ease; }
        .login-btn::after { content: ""; position: absolute; inset: 0; transform: translateX(-110%) skewX(-20deg); background: linear-gradient(90deg, transparent, rgba(255,255,255,.25), transparent); transition: transform .65s ease; }
        .login-btn:hover { color: #fff; transform: translateY(-2px); filter: brightness(1.03); }
        .login-btn:hover::after { transform: translateX(110%) skewX(-20deg); }
        .alert { border: 0; border-radius: 11px; font-size: 11px; }
        .security-note { display: flex; align-items: center; justify-content: center; gap: 7px; margin-top: 22px; color: var(--muted); font-size: 10px; }

        /* Opening jewellery-card animation */
        .intro-overlay {
            position: fixed;
            inset: 0;
            z-index: 9999;
            display: grid;
            place-items: center;
            padding: 20px;
            background:
                radial-gradient(circle at 50% 44%, rgba(216,148,22,.15), transparent 28%),
                linear-gradient(145deg, #101419, #20272d 54%, #0d1013);
            transition: opacity .55s var(--ease-premium), visibility .55s;
        }
        .intro-overlay.hide { opacity: 0; visibility: hidden; pointer-events: none; }
        .intro-stage { position: relative; width: 300px; height: 255px; perspective: 1100px; }
        .spark {
            position: absolute;
            width: 5px;
            height: 5px;
            border-radius: 50%;
            background: #ffe8a0;
            box-shadow: 0 0 14px #ffc85b;
            opacity: 0;
            animation: sparkle 1.25s ease-out forwards;
        }
        .spark.s1 { left: 22px; top: 78px; animation-delay: .28s; }
        .spark.s2 { right: 27px; top: 64px; animation-delay: .43s; }
        .spark.s3 { left: 58px; bottom: 32px; animation-delay: .6s; }
        .spark.s4 { right: 52px; bottom: 42px; animation-delay: .72s; }
        @keyframes sparkle {
            0% { opacity: 0; transform: scale(.2) translateY(8px); }
            35% { opacity: 1; transform: scale(1.4) translateY(0); }
            100% { opacity: 0; transform: scale(.3) translateY(-20px); }
        }

        .jewel-box {
            position: absolute;
            left: 50%;
            top: 52%;
            width: 220px;
            height: 132px;
            transform: translate(-50%, -50%);
            transform-style: preserve-3d;
            animation: boxArrive .55s var(--ease-premium) both;
        }
        @keyframes boxArrive {
            from { opacity: 0; transform: translate(-50%, -42%) scale(.72); }
            to { opacity: 1; transform: translate(-50%, -50%) scale(1); }
        }
        .box-base {
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0;
            height: 88px;
            border-radius: 18px 18px 26px 26px;
            background: linear-gradient(145deg, #d89416, #9d5607 72%);
            box-shadow: 0 25px 45px rgba(0,0,0,.38), inset 0 2px 0 rgba(255,255,255,.24);
        }
        .box-inner {
            position: absolute;
            inset: 10px 13px 15px;
            border-radius: 12px 12px 18px 18px;
            background: linear-gradient(145deg, #17191c, #292d31);
            display: grid;
            place-items: center;
            overflow: hidden;
        }
        .box-inner::after {
            content: "";
            position: absolute;
            width: 115px;
            height: 115px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(255,216,121,.34), transparent 62%);
            animation: glowPulse 1.4s ease-in-out infinite;
        }
        @keyframes glowPulse { 50% { transform: scale(1.18); opacity: .65; } }
        .intro-gem {
            position: relative;
            z-index: 2;
            font-size: 34px;
            color: #ffe4a1;
            filter: drop-shadow(0 0 15px rgba(255,205,90,.75));
            transform: translateY(12px) scale(.65);
            opacity: 0;
            animation: gemReveal .55s .62s var(--ease-premium) forwards;
        }
        @keyframes gemReveal { to { transform: translateY(0) scale(1); opacity: 1; } }
        .box-lid {
            position: absolute;
            left: 0;
            right: 0;
            top: 0;
            height: 64px;
            border-radius: 24px 24px 12px 12px;
            background: linear-gradient(145deg, #edaa32, #b7650a 75%);
            transform-origin: center bottom;
            transform: rotateX(0deg);
            box-shadow: inset 0 2px 0 rgba(255,255,255,.28), 0 9px 18px rgba(0,0,0,.2);
            animation: lidOpen .72s .32s var(--ease-premium) forwards;
            backface-visibility: hidden;
        }
        .box-lid::before {
            content: "";
            position: absolute;
            inset: 10px 13px;
            border-radius: 14px 14px 8px 8px;
            border: 1px solid rgba(255,255,255,.24);
            background: linear-gradient(145deg, rgba(255,255,255,.09), rgba(0,0,0,.1));
        }
        @keyframes lidOpen { to { transform: rotateX(-112deg); } }
        .box-clasp {
            position: absolute;
            z-index: 3;
            left: 50%;
            top: 58px;
            width: 32px;
            height: 21px;
            border-radius: 5px 5px 9px 9px;
            transform: translateX(-50%);
            background: #f2c45d;
            box-shadow: inset 0 0 0 3px rgba(126,70,5,.25);
            animation: claspDrop .35s .38s ease forwards;
        }
        @keyframes claspDrop { to { transform: translateX(-50%) translateY(8px); opacity: 0; } }
        .intro-copy {
            position: absolute;
            left: 50%;
            bottom: 0;
            width: 100%;
            text-align: center;
            transform: translateX(-50%);
            color: rgba(255,255,255,.8);
            opacity: 0;
            animation: copyReveal .55s .85s var(--ease-premium) forwards;
        }
        .intro-copy strong { display: block; font-family: "Playfair Display", serif; color: #fff; font-size: 22px; letter-spacing: .02em; }
        .intro-copy span { display: block; margin-top: 6px; font-size: 10px; letter-spacing: .18em; text-transform: uppercase; color: #d9b564; }
        @keyframes copyReveal { to { opacity: 1; transform: translateX(-50%) translateY(-5px); } }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation-duration: .01ms !important; animation-iteration-count: 1 !important; transition-duration: .01ms !important; scroll-behavior: auto !important; }
        }

        @media (max-width: 991.98px) {
            .login-shell { grid-template-columns: 1fr; }
            .brand-panel { min-height: 290px; padding: 30px; }
            .brand-title { margin-top: 34px; font-size: 40px; }
            .feature-grid { display: none; }
            .brand-footer { display: none; }
            .form-panel { padding: 24px 16px 34px; margin-top: -44px; position: relative; z-index: 5; }
        }
        @media (max-width: 575.98px) {
            .brand-panel { min-height: 240px; }
            .brand-title { font-size: 31px; }
            .brand-copy { font-size: 12px; }
            .login-card { padding: 25px 20px; }
            .login-title { font-size: 27px; }
            .intro-stage { transform: scale(.87); }
        }
    </style>
</head>
<body>
<div class="intro-overlay" id="introOverlay" aria-hidden="true">
    <div class="intro-stage">
        <span class="spark s1"></span><span class="spark s2"></span><span class="spark s3"></span><span class="spark s4"></span>
        <div class="jewel-box">
            <div class="box-base"><div class="box-inner"><i class="fa-solid fa-gem intro-gem"></i></div></div>
            <div class="box-lid"></div>
            <div class="box-clasp"></div>
        </div>
        <div class="intro-copy"><strong>Welcome</strong><span>Jewellery ERP</span></div>
    </div>
</div>

<div class="login-shell">
    <section class="brand-panel">
        <span class="ambient-gem g1"></span><span class="ambient-gem g2"></span><span class="ambient-gem g3"></span>
        <div class="brand-content">
            <?php if (!empty($theme['logo_path'])): ?>
                <img class="brand-logo" src="<?php echo h($theme['logo_path']); ?>" alt="<?php echo h($displayBusinessName); ?>">
            <?php else: ?>
                <div class="brand-mark"><i class="fa-solid fa-gem"></i></div>
            <?php endif; ?>

            <h1 class="brand-title">Jewellery business control, built around every branch.</h1>
            <p class="brand-copy">Manage billing, stock, customers, purchases, karigar work, pawn, chit collections and reports from one secure multi-business platform.</p>

            <div class="feature-grid">
                <div class="feature-item"><i class="fa-solid fa-building"></i><span>Unified user access</span></div>
                <div class="feature-item"><i class="fa-solid fa-code-branch"></i><span>Branch-wise operations</span></div>
                <div class="feature-item"><i class="fa-solid fa-user-shield"></i><span>Role-based permissions</span></div>
                <div class="feature-item"><i class="fa-solid fa-clock-rotate-left"></i><span>Complete audit trail</span></div>
            </div>
        </div>
        <div class="brand-footer">© <?php echo date('Y'); ?> Jewellery ERP. Secure business access.</div>
    </section>

    <section class="form-panel">
        <div class="login-card">
            <div class="welcome-line"></div>
            <div class="login-eyebrow"><i class="fa-solid fa-shield-halved"></i> Secure login</div>
            <h2 class="login-title">Welcome back</h2>
            <p class="login-subtitle">Sign in once. Your role and branch permissions will control the application automatically.</p>

            <?php if ($error !== ''): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fa-solid fa-circle-exclamation me-2"></i><?php echo h($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if ($success !== ''): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fa-solid fa-circle-check me-2"></i><?php echo h($success); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <form method="post" action="" autocomplete="on" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">

                <div class="mb-3">
                    <label for="login_id" class="form-label">Username, Email or Mobile</label>
                    <div class="input-group-modern">
                        <i class="fa-regular fa-user"></i>
                        <input type="text" class="form-control" id="login_id" name="login_id" value="<?php echo h($loginId); ?>" placeholder="Enter your login ID" autocomplete="username" required autofocus>
                    </div>
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <div class="input-group-modern">
                        <i class="fa-solid fa-lock"></i>
                        <input type="password" class="form-control" id="password" name="password" placeholder="Enter your password" autocomplete="current-password" required>
                        <button class="password-toggle" type="button" id="passwordToggle" aria-label="Show password"><i class="fa-regular fa-eye"></i></button>
                    </div>
                </div>

                <div class="d-flex align-items-center justify-content-between mb-4">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" value="1" id="remember" name="remember" <?php echo $remember ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="remember">Remember login</label>
                    </div>
                    <a href="forgot-password.php" class="text-decoration-none small" style="color:var(--primary-dark)">Forgot password?</a>
                </div>

                <button class="btn login-btn w-100" type="submit">
                    Sign In <i class="fa-solid fa-arrow-right ms-2"></i>
                </button>
            </form>

            <div class="security-note"><i class="fa-solid fa-lock"></i><span>Your session and business data are securely protected.</span></div>
        </div>
    </section>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const introOverlay = document.getElementById('introOverlay');
        const passwordInput = document.getElementById('password');
        const passwordToggle = document.getElementById('passwordToggle');
        const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        document.body.classList.add('page-ready');

        const revealWelcome = () => {
            document.body.classList.add('welcome-ready');
            introOverlay.classList.add('hide');
            window.setTimeout(() => introOverlay.remove(), 650);
        };

        window.setTimeout(revealWelcome, prefersReducedMotion ? 80 : 1650);

        passwordToggle.addEventListener('click', function () {
            const showing = passwordInput.type === 'text';
            passwordInput.type = showing ? 'password' : 'text';
            this.querySelector('i').className = showing ? 'fa-regular fa-eye' : 'fa-regular fa-eye-slash';
            this.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
        });

        document.querySelector('form').addEventListener('submit', function () {
            const button = this.querySelector('.login-btn');
            button.disabled = true;
            button.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin me-2"></i> Signing In...';
        });
    });
</script>
</body>
</html>