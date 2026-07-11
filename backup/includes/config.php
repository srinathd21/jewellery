<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Kolkata');

/* -------------------------------------------------------
   DATABASE CONFIG
------------------------------------------------------- */
$DB_HOST = 'localhost';
$DB_NAME = 'u966043993_vannamayil';
$DB_USER = 'u966043993_vannamayil';
$DB_PASS = 'Vannamayile@29'; // put your original DB password here

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
    $conn->set_charset('utf8mb4');
} catch (mysqli_sql_exception $e) {
    die('Database connection failed: ' . $e->getMessage());
}

/* -------------------------------------------------------
   COMMON HELPERS
------------------------------------------------------- */
if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('tableExists')) {
    function tableExists(mysqli $conn, string $tableName): bool
    {
        try {
            $safe = $conn->real_escape_string($tableName);
            $sql = "SHOW TABLES LIKE '{$safe}'";
            $res = $conn->query($sql);
            return $res && $res->num_rows > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('recordExistsById')) {
    function recordExistsById(mysqli $conn, string $tableName, int $id): bool
    {
        if ($id <= 0 || !tableExists($conn, $tableName)) {
            return false;
        }

        try {
            $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
            $sql = "SELECT id FROM `{$safeTable}` WHERE id = ? LIMIT 1";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $res = $stmt->get_result();
            $exists = $res && $res->num_rows > 0;
            $stmt->close();

            return $exists;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('getRoleName')) {
    function getRoleName(mysqli $conn, int $userId): string
    {
        try {
            $sql = "SELECT r.role_name
                    FROM users u
                    INNER JOIN roles r ON r.id = u.role_id
                    WHERE u.id = ?
                    LIMIT 1";

            $stmt = $conn->prepare($sql);
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $stmt->close();

            return (string)($row['role_name'] ?? '');
        } catch (Throwable $e) {
            return '';
        }
    }
}

if (!function_exists('isLoggedIn')) {
    function isLoggedIn(): bool
    {
        return isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0;
    }
}

if (!function_exists('requireLogin')) {
    function requireLogin(string $redirect = '../index.php'): void
    {
        if (!isLoggedIn()) {
            header('Location: ' . $redirect);
            exit;
        }
    }
}

if (!function_exists('isSuperAdmin')) {
    function isSuperAdmin(): bool
    {
        $role = strtolower(trim((string)($_SESSION['role_name'] ?? '')));

        return in_array($role, ['super admin', 'super_admin', 'superadmin'], true);
    }
}

if (!function_exists('requireSuperAdmin')) {
    function requireSuperAdmin(string $redirect = '../index.php'): void
    {
        requireLogin($redirect);

        if (!isSuperAdmin()) {
            die('Access denied. Super Admin only.');
        }
    }
}

if (!function_exists('currentUserId')) {
    function currentUserId(): int
    {
        return (int)($_SESSION['user_id'] ?? 0);
    }
}

if (!function_exists('currentBusinessId')) {
    function currentBusinessId(): ?int
    {
        if (!isset($_SESSION['business_id']) || $_SESSION['business_id'] === '') {
            return null;
        }

        $businessId = (int)$_SESSION['business_id'];

        return $businessId > 0 ? $businessId : null;
    }
}

if (!function_exists('currentRoleName')) {
    function currentRoleName(): string
    {
        return (string)($_SESSION['role_name'] ?? '');
    }
}

if (!function_exists('refreshSessionUser')) {
    function refreshSessionUser(mysqli $conn, int $userId): void
    {
        try {
            $sql = "SELECT 
                        u.id,
                        u.business_id,
                        u.full_name,
                        u.username,
                        u.email,
                        u.mobile,
                        u.role_id,
                        r.role_name,
                        b.business_name
                    FROM users u
                    INNER JOIN roles r ON r.id = u.role_id
                    LEFT JOIN businesses b ON b.id = u.business_id
                    WHERE u.id = ?
                    LIMIT 1";

            $stmt = $conn->prepare($sql);
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $stmt->close();

            if ($row) {
                $_SESSION['user_id']       = (int)$row['id'];
                $_SESSION['business_id']   = isset($row['business_id']) && (int)$row['business_id'] > 0
                    ? (int)$row['business_id']
                    : null;
                $_SESSION['role_id']       = (int)($row['role_id'] ?? 0);
                $_SESSION['full_name']     = (string)($row['full_name'] ?? '');
                $_SESSION['username']      = (string)($row['username'] ?? '');
                $_SESSION['email']         = (string)($row['email'] ?? '');
                $_SESSION['mobile']        = (string)($row['mobile'] ?? '');
                $_SESSION['role_name']     = (string)($row['role_name'] ?? '');
                $_SESSION['business_name'] = (string)($row['business_name'] ?? '');
            }
        } catch (Throwable $e) {
            // Do not break page because of session refresh issue.
        }
    }
}

/* -------------------------------------------------------
   SAFE AUDIT LOG HELPER
------------------------------------------------------- */
if (!function_exists('addAuditLog')) {
    function addAuditLog(
        mysqli $conn,
        ?int $businessId,
        ?int $userId,
        string $moduleName,
        string $actionType,
        ?int $referenceId = null,
        string $description = ''
    ): void {
        if (!tableExists($conn, 'audit_logs')) {
            return;
        }

        /*
         * FIX:
         * audit_logs.business_id has foreign key with businesses.id.
         * If the session business_id is wrong/deleted/not available,
         * use NULL to avoid foreign key error.
         */
        if ($businessId !== null && $businessId > 0) {
            if (!recordExistsById($conn, 'businesses', $businessId)) {
                $businessId = null;
            }
        } else {
            $businessId = null;
        }

        /*
         * Safety for user_id also.
         * If the user record does not exist, insert NULL.
         */
        if ($userId !== null && $userId > 0) {
            if (!recordExistsById($conn, 'users', $userId)) {
                $userId = null;
            }
        } else {
            $userId = null;
        }

        if ($referenceId !== null && $referenceId <= 0) {
            $referenceId = null;
        }

        $moduleName  = trim($moduleName);
        $actionType  = trim($actionType);
        $description = trim($description);

        if ($moduleName === '') {
            $moduleName = 'System';
        }

        if ($actionType === '') {
            $actionType = 'Activity';
        }

        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        try {
            $sql = "INSERT INTO audit_logs
                    (
                        business_id,
                        user_id,
                        module_name,
                        action_type,
                        reference_id,
                        description,
                        ip_address,
                        user_agent
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

            $stmt = $conn->prepare($sql);

            $stmt->bind_param(
                'iississs',
                $businessId,
                $userId,
                $moduleName,
                $actionType,
                $referenceId,
                $description,
                $ipAddress,
                $userAgent
            );

            $stmt->execute();
            $stmt->close();
        } catch (Throwable $e) {
            /*
             * Very important:
             * Audit log should not stop login/logout/page loading.
             */
            return;
        }
    }
}
?>