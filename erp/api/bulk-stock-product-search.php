<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');

foreach ([
    dirname(__DIR__) . '/config/config.php',
    dirname(__DIR__) . '/config.php',
    dirname(__DIR__) . '/includes/config.php',
    dirname(__DIR__) . '/super-admin/includes/config.php'
] as $file) {
    if (is_file($file)) {
        require_once $file;
        break;
    }
}

function outJson($data, $code = 200)
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    outJson(['results' => [], 'message' => 'Database configuration is not available.'], 500);
}
$conn->set_charset('utf8mb4');

if (empty($_SESSION['user_id'])) {
    outJson(['results' => [], 'message' => 'Session expired.'], 401);
}

$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? ($_SESSION['default_branch_id'] ?? 0));
if ($businessId <= 0 || $branchId <= 0) {
    outJson(['results' => [], 'message' => 'Business or branch session is missing.'], 403);
}

$q = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;
$like = '%' . $q . '%';

$sql = "SELECT p.id,
               p.product_name,
               p.product_code,
               p.barcode,
               COALESCE(p.net_weight,0) AS unit_net_weight,
               COALESCE(ps.quantity,0) AS current_qty
        FROM products p
        LEFT JOIN product_stock ps
               ON ps.product_id = p.id
              AND ps.business_id = p.business_id
              AND ps.branch_id = ?
        WHERE p.business_id = ?
          AND p.is_active = 1
          AND COALESCE(p.track_stock,1) = 1
          AND (p.product_name LIKE ? OR COALESCE(p.product_code,'') LIKE ? OR COALESCE(p.barcode,'') LIKE ?)
        ORDER BY p.product_name ASC, p.id ASC
        LIMIT ? OFFSET ?";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    outJson(['results' => [], 'message' => 'Unable to prepare product search.'], 500);
}
$stmt->bind_param('iisssii', $branchId, $businessId, $like, $like, $like, $limit, $offset);
$stmt->execute();
$res = $stmt->get_result();
$results = [];
while ($row = $res->fetch_assoc()) {
    $label = (string)$row['product_name'];
    if (!empty($row['product_code'])) {
        $label .= ' - ' . $row['product_code'];
    }
    if (!empty($row['barcode'])) {
        $label .= ' [' . $row['barcode'] . ']';
    }
    $results[] = [
        'id' => (int)$row['id'],
        'text' => $label,
        'product_name' => (string)$row['product_name'],
        'product_code' => (string)($row['product_code'] ?? ''),
        'barcode' => (string)($row['barcode'] ?? ''),
        'current_qty' => (float)$row['current_qty'],
        'unit_net_weight' => (float)$row['unit_net_weight']
    ];
}
$stmt->close();

// Ask for one extra record to determine if Select2 should request another page.
$countSql = "SELECT COUNT(*) AS total
             FROM products p
             WHERE p.business_id = ?
               AND p.is_active = 1
               AND COALESCE(p.track_stock,1) = 1
               AND (p.product_name LIKE ? OR COALESCE(p.product_code,'') LIKE ? OR COALESCE(p.barcode,'') LIKE ?)";
$countStmt = $conn->prepare($countSql);
$total = 0;
if ($countStmt) {
    $countStmt->bind_param('isss', $businessId, $like, $like, $like);
    $countStmt->execute();
    $total = (int)(($countStmt->get_result()->fetch_assoc()['total'] ?? 0));
    $countStmt->close();
}

outJson([
    'results' => $results,
    'pagination' => ['more' => ($offset + $limit) < $total]
]);
