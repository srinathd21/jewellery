<?php
require_once __DIR__ . '/includes/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$business_id = (int)($_SESSION['business_id'] ?? 1);
$customer_id = (int)($_GET['customer_id'] ?? 0);

$response = ['success' => false, 'data' => []];

if ($customer_id > 0) {
    $sql = "SELECT pc.* FROM pawn_customers pc 
            WHERE pc.customer_id = $customer_id AND pc.business_id = $business_id";
    $result = $conn->query($sql);
    
    if ($result && $result->num_rows > 0) {
        $response['success'] = true;
        $response['data'] = $result->fetch_assoc();
    }
}

header('Content-Type: application/json');
echo json_encode($response);
?>