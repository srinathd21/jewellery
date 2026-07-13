<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));

foreach ([
    dirname(__DIR__) . '/config/config.php',
    dirname(__DIR__) . '/config.php',
    dirname(__DIR__) . '/includes/config.php',
    dirname(__DIR__) . '/super-admin/includes/config.php',
] as $configFile) {
    if (is_file($configFile)) {
        require_once $configFile;
        break;
    }
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    die('Database configuration is not available.');
}
$conn->set_charset('utf8mb4');

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if (!function_exists('pawn_e')) {
    function pawn_e(mixed $value): string {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('pawn_money')) {
    function pawn_money(mixed $value): string {
        return number_format((float)$value, 2);
    }
}
if (!function_exists('pawn_permission')) {
    function pawn_permission(mysqli $conn, string $action, array $codes = ['perm.pawn', 'perm.pawn.entries']): bool {
        if (($_SESSION['user_type'] ?? '') === 'Platform Admin') {
            return true;
        }

        $map = [
            'open' => 'can_open',
            'view' => 'can_view',
            'value' => 'can_view_value',
            'create' => 'can_create',
            'update' => 'can_update',
            'delete' => 'can_delete',
            'approve' => 'can_approve',
        ];
        $field = $map[$action] ?? '';
        if ($field === '') return false;

        $sessionPermissions = $_SESSION['permissions'] ?? [];
        foreach ($codes as $code) {
            if (isset($sessionPermissions[$code][$field])) {
                return (int)$sessionPermissions[$code][$field] === 1;
            }
        }

        $businessId = (int)($_SESSION['business_id'] ?? 0);
        $roleId = (int)($_SESSION['role_id'] ?? 0);
        if ($businessId <= 0 || $roleId <= 0) return false;

        $placeholders = implode(',', array_fill(0, count($codes), '?'));
        $types = 'ii' . str_repeat('s', count($codes));
        $sql = "SELECT MAX(rp.`{$field}`) allowed
                FROM role_permissions rp
                INNER JOIN permissions p ON p.id = rp.permission_id
                WHERE rp.business_id = ?
                  AND rp.role_id = ?
                  AND p.is_active = 1
                  AND p.permission_code IN ({$placeholders})";
        $stmt = $conn->prepare($sql);
        if (!$stmt) return false;
        $params = [$businessId, $roleId, ...$codes];
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($row['allowed'] ?? 0) === 1;
    }
}
if (!function_exists('pawn_csrf_token')) {
    function pawn_csrf_token(): string {
        if (empty($_SESSION['pawn_master_csrf'])) {
            $_SESSION['pawn_master_csrf'] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION['pawn_master_csrf'];
    }
}
if (!function_exists('pawn_verify_csrf')) {
    function pawn_verify_csrf(string $token): void {
        if (!hash_equals((string)($_SESSION['pawn_master_csrf'] ?? ''), $token)) {
            http_response_code(419);
            throw new RuntimeException('The form session expired. Refresh the page and try again.');
        }
    }
}
if (!function_exists('pawn_theme')) {
    function pawn_theme(mysqli $conn, int $businessId): array {
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
        $stmt = $conn->prepare('SELECT * FROM business_theme_settings WHERE business_id = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('i', $businessId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc() ?: [];
            $stmt->close();
            foreach ($theme as $key => $default) {
                if (isset($row[$key]) && $row[$key] !== '') $theme[$key] = $row[$key];
            }
        }
        return $theme;
    }
}
if (!function_exists('pawn_audit')) {
    function pawn_audit(mysqli $conn, int $businessId, ?int $branchId, int $userId, string $module, string $action, string $table, int $referenceId, string $description, ?array $old = null, ?array $new = null): void {
        $oldJson = $old ? json_encode($old, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        $newJson = $new ? json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 50);
        $agent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 1000);
        $stmt = $conn->prepare("INSERT INTO audit_logs
            (business_id, branch_id, user_id, module_code, action_type, reference_table, reference_id, description, old_values_json, new_values_json, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) return;
        $stmt->bind_param('iiisssisssss', $businessId, $branchId, $userId, $module, $action, $table, $referenceId, $description, $oldJson, $newJson, $ip, $agent);
        @$stmt->execute();
        $stmt->close();
    }
}

$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? $_SESSION['default_branch_id'] ?? 0);
$userId = (int)($_SESSION['user_id'] ?? 0);
if ($businessId <= 0) {
    http_response_code(403);
    die('A valid business must be selected.');
}
$theme = pawn_theme($conn, $businessId);
$csrfToken = pawn_csrf_token();
$businessName = (string)($_SESSION['business_name'] ?? 'Jewellery ERP');
$currencySymbol = (string)($_SESSION['currency_symbol'] ?? '₹');
