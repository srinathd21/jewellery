<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

function respondToggle(bool $success, string $message, array $extra = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode(array_merge(['success'=>$success,'message'=>$message], $extra), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

if (empty($_SESSION['user_id'])) {
    respondToggle(false, 'Your session has expired. Please log in again.', [], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondToggle(false, 'Invalid request method.', [], 405);
}
if (empty($_SESSION['billing_csrf']) || !hash_equals((string)$_SESSION['billing_csrf'], (string)($_POST['csrf_token'] ?? ''))) {
    respondToggle(false, 'Invalid or expired request token. Refresh the page.', [], 419);
}

$_SESSION['non_gst_mode'] = ((int)($_SESSION['non_gst_mode'] ?? 0) === 1) ? 0 : 1;
respondToggle(true, $_SESSION['non_gst_mode'] ? 'Non-GST mode enabled.' : 'GST mode enabled.', [
    'non_gst_mode' => (int)$_SESSION['non_gst_mode']
]);