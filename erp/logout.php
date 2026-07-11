<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Preserve values needed for the audit log before clearing the session.
$userId = (int) ($_SESSION['user_id'] ?? 0);
$businessId = (int) ($_SESSION['business_id'] ?? 0);
$branchId = (int) ($_SESSION['branch_id'] ?? 0);

// Try to write a logout entry without blocking logout if the database is unavailable.
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

if (isset($conn) && $conn instanceof mysqli && $userId > 0) {
    try {
        $conn->set_charset('utf8mb4');

        $moduleCode = 'auth.logout';
        $actionType = 'Logout';
        $referenceTable = 'users';
        $description = 'User logged out successfully';
        $ipAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');

        $stmt = $conn->prepare(
            'INSERT INTO audit_logs
                (business_id, branch_id, user_id, module_code, action_type,
                 reference_table, reference_id, description, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        if ($stmt) {
            $nullableBusinessId = $businessId > 0 ? $businessId : null;
            $nullableBranchId = $branchId > 0 ? $branchId : null;

            $stmt->bind_param(
                'iiisssisss',
                $nullableBusinessId,
                $nullableBranchId,
                $userId,
                $moduleCode,
                $actionType,
                $referenceTable,
                $userId,
                $description,
                $ipAddress,
                $userAgent
            );
            $stmt->execute();
            $stmt->close();
        }
    } catch (Throwable $e) {
        // Logout must still continue even when audit logging fails.
    }
}

// Remove every session value.
$_SESSION = [];

// Remove the PHP session cookie.
if (ini_get('session.use_cookies')) {
    $cookieParams = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $cookieParams['path'],
        $cookieParams['domain'],
        (bool) $cookieParams['secure'],
        (bool) $cookieParams['httponly']
    );
}

// Remove common remember-me cookies when present.
$rememberCookies = [
    'remember_token',
    'remember_me',
    'auth_token',
    'user_token',
];

foreach ($rememberCookies as $cookieName) {
    if (isset($_COOKIE[$cookieName])) {
        setcookie($cookieName, '', time() - 42000, '/');
        unset($_COOKIE[$cookieName]);
    }
}

session_destroy();

// Prevent the browser from caching authenticated pages.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');

header('Location: login.php?logout=1');
exit;
