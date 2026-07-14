<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_OFF);
ini_set('display_errors', '0');

function respond(bool $success, string $message, array $extra = [], int $status = 200): void
{
    http_response_code($status);

    echo json_encode(
        array_merge(
            ['success' => $success, 'message' => $message],
            $extra
        ),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

register_shutdown_function(static function (): void {
    $error = error_get_last();

    if (
        !$error ||
        !in_array(
            $error['type'],
            [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR],
            true
        )
    ) {
        return;
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }

    echo json_encode(
        [
            'success' => false,
            'message' => 'Fatal API error: ' . $error['message'],
        ],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
});

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
    respond(false, 'Database configuration is not available.', [], 500);
}

$conn->set_charset('utf8mb4');

if (empty($_SESSION['user_id'])) {
    respond(false, 'Your session has expired. Please log in again.', [], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Invalid request method.', [], 405);
}

if (
    !hash_equals(
        (string)($_SESSION['estimate_print_csrf'] ?? ''),
        (string)($_POST['csrf_token'] ?? '')
    )
) {
    respond(false, 'Invalid or expired request token. Refresh the page.', [], 419);
}

function tableExists(mysqli $conn, string $table): bool
{
    $safe = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safe}'");

    return $result && $result->num_rows > 0;
}

$action = (string)($_POST['action'] ?? '');
$businessId = (int)($_SESSION['business_id'] ?? 0);
$estimateId = (int)($_POST['estimate_id'] ?? 0);

if ($businessId <= 0) {
    respond(false, 'A valid business must be selected.', [], 403);
}

if ($action !== 'load') {
    respond(false, 'Invalid action.', [], 400);
}

if ($estimateId <= 0) {
    respond(false, 'Invalid estimate selected.');
}

$stmt = $conn->prepare(
    "SELECT *
     FROM sales
     WHERE id = ?
       AND business_id = ?
       AND bill_type = 'Estimate'
     LIMIT 1"
);

if (!$stmt) {
    respond(false, 'Unable to prepare estimate query: ' . $conn->error, [], 500);
}

$stmt->bind_param('ii', $estimateId, $businessId);
$stmt->execute();
$estimate = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$estimate) {
    respond(false, 'Estimate not found.', [], 404);
}

$estimate['invoice_date_display'] =
    !empty($estimate['invoice_date'])
        ? date('d-m-Y', strtotime($estimate['invoice_date']))
        : '';

$estimate['invoice_time_display'] =
    !empty($estimate['invoice_time'])
        ? date('h:i A', strtotime($estimate['invoice_time']))
        : '';

$items = [];

$stmt = $conn->prepare(
    "SELECT *
     FROM sale_items
     WHERE sale_id = ?
       AND business_id = ?
     ORDER BY sort_order ASC, id ASC"
);

if (!$stmt) {
    respond(false, 'Unable to prepare estimate items: ' . $conn->error, [], 500);
}

$stmt->bind_param('ii', $estimateId, $businessId);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $items[] = $row;
}

$stmt->close();

$payments = [];

if (
    tableExists($conn, 'sale_payments') &&
    tableExists($conn, 'payment_methods')
) {
    $stmt = $conn->prepare(
        "SELECT
            sp.*,
            pm.method_name
         FROM sale_payments sp
         INNER JOIN payment_methods pm
            ON pm.id = sp.payment_method_id
         WHERE sp.sale_id = ?
           AND sp.business_id = ?
         ORDER BY sp.id ASC"
    );

    if ($stmt) {
        $stmt->bind_param('ii', $estimateId, $businessId);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $payments[] = $row;
        }

        $stmt->close();
    }
}

$company = [
    'company_name' => (string)($_SESSION['business_name'] ?? 'Company Name'),
    'owner_name' => '',
    'mobile' => '',
    'email' => '',
    'address_line1' => '',
    'address_line2' => '',
    'city' => '',
    'state' => '',
    'pincode' => '',
    'gstin' => '',
    'pan_no' => '',
    'bill_footer' => 'Thank you for your business.',
    'terms_conditions' => '',
];

if (tableExists($conn, 'company_settings')) {
    $stmt = $conn->prepare(
        'SELECT * FROM company_settings WHERE business_id = ? LIMIT 1'
    );

    if ($stmt) {
        $stmt->bind_param('i', $businessId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if ($row) {
            $company = array_merge($company, $row);
        }

        $stmt->close();
    }
}

respond(
    true,
    'Estimate loaded.',
    [
        'estimate' => $estimate,
        'items' => $items,
        'payments' => $payments,
        'company' => $company,
    ]
);
