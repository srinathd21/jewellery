<?php
/**
 * Common database configuration for the Jewellery ERP.
 *
 * Update DB_USER and DB_PASS with the credentials created in your hosting panel.
 * Keep this file outside the public directory whenever your hosting setup allows it.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Kolkata');

mysqli_report(MYSQLI_REPORT_OFF);

// Database connection settings.
define('DB_HOST', getenv('DB_HOST') ?: 'srv1740.hstgr.io');
define('DB_PORT', (int)(getenv('DB_PORT') ?: 3306));
define('DB_NAME', getenv('DB_NAME') ?: 'u966043993_jewellery');
define('DB_USER', getenv('DB_USER') ?: 'u966043993_jewellery');
define('DB_PASS', getenv('DB_PASS') ?: '+X>@QR1yL');

// Application settings.
define('APP_NAME', 'Jewellery ERP');
define('APP_TIMEZONE', 'Asia/Kolkata');
define('APP_ENV', getenv('APP_ENV') ?: 'production');
define('APP_DEBUG', filter_var(getenv('APP_DEBUG') ?: false, FILTER_VALIDATE_BOOLEAN));

date_default_timezone_set(APP_TIMEZONE);

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);

if ($conn->connect_errno) {
    if (APP_DEBUG) {
        die('Database connection failed: ' . htmlspecialchars($conn->connect_error, ENT_QUOTES, 'UTF-8'));
    }

    error_log('Jewellery ERP database connection failed: ' . $conn->connect_error);
    http_response_code(500);
    die('Unable to connect to the database. Please contact the administrator.');
}

if (!$conn->set_charset('utf8mb4')) {
    error_log('Unable to set database charset: ' . $conn->error);
}

// Keep MySQL session timestamps aligned with India time.
$conn->query("SET time_zone = '+05:30'");

/**
 * Escape output safely for HTML.
 */
if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Return the logged-in business ID, or null for platform-level users.
 */
if (!function_exists('currentBusinessId')) {
    function currentBusinessId(): ?int
    {
        return isset($_SESSION['business_id']) && $_SESSION['business_id'] !== null
            ? (int)$_SESSION['business_id']
            : null;
    }
}

/**
 * Return the currently selected branch ID.
 */
if (!function_exists('currentBranchId')) {
    function currentBranchId(): ?int
    {
        return isset($_SESSION['branch_id']) && $_SESSION['branch_id'] !== null
            ? (int)$_SESSION['branch_id']
            : null;
    }
}

/**
 * Check whether a user is logged in.
 */
if (!function_exists('isLoggedIn')) {
    function isLoggedIn(): bool
    {
        return !empty($_SESSION['user_id']);
    }
}

/**
 * Redirect unauthenticated users to the common login page.
 */
if (!function_exists('requireLogin')) {
    function requireLogin(string $loginPage = 'login.php'): void
    {
        if (!isLoggedIn()) {
            header('Location: ' . $loginPage);
            exit;
        }
    }
}

/**
 * Read a permission flag from the permission array saved during login.
 *
 * Example:
 * hasPermission('perm.billing.sales', 'can_view')
 */
if (!function_exists('hasPermission')) {
    function hasPermission(string $permissionCode, string $action = 'can_open'): bool
    {
        if (($_SESSION['user_type'] ?? '') === 'Platform Admin') {
            return true;
        }

        $allowedActions = [
            'can_open',
            'can_view_value',
            'can_view',
            'can_create',
            'can_update',
            'can_approve',
            'can_delete',
        ];

        if (!in_array($action, $allowedActions, true)) {
            return false;
        }

        $permissions = $_SESSION['permissions'] ?? [];

        return isset($permissions[$permissionCode])
            && !empty($permissions[$permissionCode][$action]);
    }
}

/**
 * Stop the request when the logged-in user lacks permission.
 */
if (!function_exists('requirePermission')) {
    function requirePermission(string $permissionCode, string $action = 'can_open'): void
    {
        requireLogin();

        if (!hasPermission($permissionCode, $action)) {
            http_response_code(403);
            die('Access denied. You do not have permission to perform this action.');
        }
    }
}
