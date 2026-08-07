<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
    exit;
}

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Your login session has expired.'
    ]);
    exit;
}

$sessionToken = (string)($_SESSION['non_gst_toggle_csrf'] ?? '');
$postedToken = (string)($_POST['csrf_token'] ?? '');

if (
    $sessionToken === ''
    || $postedToken === ''
    || !hash_equals($sessionToken, $postedToken)
) {
    http_response_code(419);
    echo json_encode([
        'success' => false,
        'message' => 'Session expired. Refresh the page and try again.'
    ]);
    exit;
}

$current = (int)($_SESSION['non_gst_mode'] ?? 0);
$_SESSION['non_gst_mode'] = $current === 1 ? 0 : 1;

$active = (int)$_SESSION['non_gst_mode'] === 1;

echo json_encode([
    'success' => true,
    'active' => $active,
    'message' => $active
        ? 'Non-GST mode enabled.'
        : 'Non-GST mode disabled.'
]);
exit;