<?php
session_start();
require_once '../includes/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['business_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$businessId = (int)$_SESSION['business_id'];

$rates = [];
$query = "SELECT metal_type, purity, rate_per_gram FROM metal_rates 
          WHERE business_id = ? AND effective_date <= CURDATE() 
          ORDER BY effective_date DESC, id DESC";
$stmt = $conn->prepare($query);
$stmt->bind_param('i', $businessId);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $key = $row['metal_type'] . '_' . $row['purity'];
    if (!isset($rates[$key])) {
        $rates[$key] = $row['rate_per_gram'];
    }
}
$stmt->close();

echo json_encode(['success' => true, 'rates' => $rates]);
?>