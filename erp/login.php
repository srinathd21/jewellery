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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
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
        }

        * { box-sizing: border-box; }
        html, body { min-height: 100%; }
        body {
            margin: 0;
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
        }

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

        .brand-content, .brand-footer { position: relative; z-index: 2; }
        .brand-mark {
            width: 54px;
            height: 54px;
            display: grid;
            place-items: center;
            border-radius: 16px;
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
        .feature-item { display: flex; align-items: center; gap: 11px; padding: 13px 14px; border: 1px solid rgba(255,255,255,.1); background: rgba(255,255,255,.055); border-radius: 13px; backdrop-filter: blur(8px); font-size: 12px; }
        .feature-item i { color: color-mix(in srgb, var(--primary) 82%, white); }
        .brand-footer { color: rgba(255,255,255,.55); font-size: 11px; }

        .form-panel { display: flex; align-items: center; justify-content: center; padding: 30px; }
        .login-card { width: min(100%, 470px); background: color-mix(in srgb, var(--card-bg) 94%, transparent); border: 1px solid var(--line); border-radius: calc(var(--radius) + 6px); box-shadow: 0 24px 70px rgba(24,31,40,.12); padding: clamp(28px, 4vw, 44px); backdrop-filter: blur(18px); }
        .login-eyebrow { display: inline-flex; align-items: center; gap: 7px; padding: 6px 10px; border-radius: 999px; background: var(--primary-soft); color: var(--primary-dark); font-size: 10px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
        .login-title { font-family: "Playfair Display", serif; font-size: 31px; font-weight: 700; margin: 18px 0 7px; }
        .login-subtitle { margin: 0 0 28px; color: var(--muted); font-size: 13px; }
        .form-label { font-size: 11px; font-weight: 700; color: #4b5563; margin-bottom: 7px; }
        .input-group-modern { position: relative; }
        .input-group-modern > i { position: absolute; z-index: 4; left: 14px; top: 50%; transform: translateY(-50%); color: #8b95a2; }
        .input-group-modern .form-control { height: 48px; border: 1px solid var(--line); border-radius: 12px; padding-left: 42px; padding-right: 42px; background: var(--card-bg); color: var(--text); font-size: 13px; box-shadow: none; }
        .input-group-modern .form-control:focus { border-color: var(--primary); box-shadow: 0 0 0 .22rem color-mix(in srgb, var(--primary) 13%, transparent); }
        .password-toggle { position: absolute; z-index: 5; right: 8px; top: 50%; transform: translateY(-50%); width: 34px; height: 34px; border: 0; background: transparent; color: #8b95a2; border-radius: 9px; }
        .form-text { color: var(--muted); font-size: 10px; }
        .form-check-label { color: var(--muted); font-size: 11px; }
        .form-check-input:checked { background-color: var(--primary); border-color: var(--primary); }
        .login-btn { height: 48px; border: 0; border-radius: 12px; background: linear-gradient(135deg, var(--primary), var(--primary-dark)); color: #fff; font-weight: 700; font-size: 13px; box-shadow: 0 13px 28px color-mix(in srgb, var(--primary) 25%, transparent); }
        .login-btn:hover { color: #fff; transform: translateY(-1px); filter: brightness(1.02); }
        .alert { border: 0; border-radius: 11px; font-size: 11px; }
        .security-note { display: flex; align-items: center; justify-content: center; gap: 7px; margin-top: 22px; color: var(--muted); font-size: 10px; }

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
        }
    </style>
</head>
<body>
<div class="login-shell">
    <section class="brand-panel">
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
    const passwordInput = document.getElementById('password');
    const passwordToggle = document.getElementById('passwordToggle');

    passwordToggle.addEventListener('click', function () {
        const showing = passwordInput.type === 'text';
        passwordInput.type = showing ? 'password' : 'text';
        this.querySelector('i').className = showing ? 'fa-regular fa-eye' : 'fa-regular fa-eye-slash';
        this.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
    });
</script>
</body>
</html>
