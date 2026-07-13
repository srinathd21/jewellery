<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));
header('Content-Type: application/json; charset=utf-8');

$configCandidates = [
    dirname(__DIR__) . '/config/config.php',
    dirname(__DIR__) . '/config.php',
    dirname(__DIR__) . '/includes/config.php',
    dirname(__DIR__) . '/super-admin/includes/config.php',
];

$configLoaded = false;

foreach ($configCandidates as $configFile) {
    if (is_file($configFile)) {
        require_once $configFile;
        $configLoaded = true;
        break;
    }
}

function apiResponse(bool $success, string $message, array $data = [], int $status = 200): never
{
    http_response_code($status);
    echo json_encode(
        [
            'success' => $success,
            'message' => $message,
            'data' => $data,
        ],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

if (!$configLoaded || !isset($conn) || !($conn instanceof mysqli)) {
    apiResponse(false, 'Database configuration is not available.', [], 500);
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn->set_charset('utf8mb4');

function tableExists(mysqli $conn, string $table): bool
{
    $safe = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $result && $result->num_rows > 0;
}

function hasColumn(mysqli $conn, string $table, string $column): bool
{
    $safeTable = $conn->real_escape_string($table);
    $safeColumn = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    return $result && $result->num_rows > 0;
}

function requireLogin(): array
{
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $businessId = (int)($_SESSION['business_id'] ?? 0);
    $branchId = (int)($_SESSION['branch_id'] ?? 0);

    if ($userId <= 0) {
        apiResponse(false, 'Login session expired.', [], 401);
    }

    if ($businessId <= 0) {
        apiResponse(false, 'Business session not found.', [], 401);
    }

    return [$userId, $businessId, $branchId];
}

function hasStockPermission(): bool
{
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin') {
        return true;
    }

    $roleName = strtolower(trim((string)($_SESSION['role_name'] ?? '')));
    $roleCode = strtolower(trim((string)($_SESSION['role_code'] ?? '')));

    if (
        in_array($roleName, ['admin', 'business admin', 'manager', 'stock'], true) ||
        in_array($roleCode, ['admin', 'business_admin', 'manager', 'stock'], true)
    ) {
        return true;
    }

    $permissions = $_SESSION['permissions'] ?? [];

    foreach (['perm.stock', 'perm.inventory'] as $code) {
        if (
            isset($permissions[$code]) &&
            (
                (int)($permissions[$code]['can_open'] ?? 0) === 1 ||
                (int)($permissions[$code]['can_view'] ?? 0) === 1
            )
        ) {
            return true;
        }
    }

    return false;
}

function bindDynamic(mysqli_stmt $stmt, string $types, array &$values): void
{
    if ($types === '' || empty($values)) {
        return;
    }

    $bind = [$types];

    foreach ($values as $index => $value) {
        $bind[] = &$values[$index];
    }

    call_user_func_array([$stmt, 'bind_param'], $bind);
}
