<?php
session_start();

require_once 'includes/config.php';

$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
$businessId = isset($_SESSION['business_id']) && $_SESSION['business_id'] !== '' ? (int)$_SESSION['business_id'] : null;

if (isset($conn) && $conn instanceof mysqli && function_exists('addAuditLog') && $userId) {
    addAuditLog(
        $conn,
        $businessId,
        $userId,
        'Logout',
        'Logout',
        $userId,
        'Business Admin user logged out successfully'
    );
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

session_destroy();

header('Location: ../index.php');
exit;