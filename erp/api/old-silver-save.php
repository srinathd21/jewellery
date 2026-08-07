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

if (!$configLoaded || !isset($conn) || !($conn instanceof mysqli)) {
    apiResponse(false, 'Database configuration is not available.', [], 500);
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn->set_charset('utf8mb4');

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

function inputData(): array
{
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));

    if (str_contains($contentType, 'application/json')) {
        $decoded = json_decode((string)file_get_contents('php://input'), true);
        return is_array($decoded) ? $decoded : [];
    }

    return $_POST;
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

function hasAnyPermission(array $permissionCodes, array $actions): bool
{
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin') {
        return true;
    }

    $roleName = strtolower(trim((string)($_SESSION['role_name'] ?? '')));
    $roleCode = strtolower(trim((string)($_SESSION['role_code'] ?? '')));

    if (
        in_array($roleName, ['admin', 'business admin', 'manager', 'stock', 'staff', 'sales'], true) ||
        in_array($roleCode, ['admin', 'business_admin', 'manager', 'stock', 'staff', 'sales'], true)
    ) {
        return true;
    }

    $permissions = $_SESSION['permissions'] ?? [];

    foreach ($permissionCodes as $code) {
        if (!isset($permissions[$code])) {
            continue;
        }

        foreach ($actions as $action) {
            if ((int)($permissions[$code][$action] ?? 0) === 1) {
                return true;
            }
        }
    }

    return false;
}

function requirePermission(array $permissionCodes, array $actions): void
{
    if (!hasAnyPermission($permissionCodes, $actions)) {
        apiResponse(false, 'Access denied.', [], 403);
    }
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

function addAuditLogSafe(
    mysqli $conn,
    int $businessId,
    int $userId,
    string $module,
    string $action,
    int $referenceId,
    string $description
): void {
    if (!tableExists($conn, 'audit_logs')) {
        return;
    }

    $fields = [];
    $placeholders = [];
    $types = '';
    $values = [];

    $map = [
        'business_id' => [$businessId, 'i'],
        'user_id' => [$userId, 'i'],
        'module_name' => [$module, 's'],
        'action_type' => [$action, 's'],
        'reference_id' => [$referenceId, 'i'],
        'description' => [$description, 's'],
        'ip_address' => [(string)($_SERVER['REMOTE_ADDR'] ?? ''), 's'],
        'user_agent' => [(string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 's'],
    ];

    foreach ($map as $field => [$value, $type]) {
        if (!hasColumn($conn, 'audit_logs', $field)) {
            continue;
        }

        $fields[] = $field;
        $placeholders[] = '?';
        $types .= $type;
        $values[] = $value;
    }

    if (empty($fields)) {
        return;
    }

    $sql = 'INSERT INTO audit_logs (' . implode(', ', $fields) . ')
            VALUES (' . implode(', ', $placeholders) . ')';

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return;
    }

    bindDynamic($stmt, $types, $values);
    $stmt->execute();
    $stmt->close();
}

function generateReferenceNo(
    mysqli $conn,
    string $table,
    string $column,
    int $businessId,
    string $prefix
): string {
    $like = $prefix . '%';
    $sql = "SELECT `{$column}`
            FROM `{$table}`
            WHERE business_id = ?
              AND `{$column}` LIKE ?
            ORDER BY id DESC
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    $last = '';

    if ($stmt) {
        $stmt->bind_param('is', $businessId, $like);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $last = (string)($row[$column] ?? '');
        $stmt->close();
    }

    $running = 1;

    if ($last !== '' && preg_match('/(\d{4})$/', $last, $match)) {
        $running = ((int)$match[1]) + 1;
    }

    return $prefix . str_pad((string)$running, 4, '0', STR_PAD_LEFT);
}

[$userId, $businessId, $branchId] = requireLogin();
requirePermission(['perm.old_silver', 'perm.stock'], ['can_open', 'can_create']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiResponse(false, 'POST method required.', [], 405);
}

if (
    !tableExists($conn, 'old_silver_entries') ||
    !tableExists($conn, 'old_silver_items')
) {
    apiResponse(false, 'Required old silver tables are missing.', [], 500);
}

$data = inputData();

$entryNo = trim((string)($data['entry_no'] ?? ''));
$entryDate = trim((string)($data['entry_date'] ?? date('Y-m-d')));
$customerId = (int)($data['customer_id'] ?? 0);
$customerName = trim((string)($data['customer_name'] ?? ''));
$customerMobile = trim((string)($data['customer_mobile'] ?? ''));
$idProofType = trim((string)($data['id_proof_type'] ?? ''));
$idProofNumber = trim((string)($data['id_proof_number'] ?? ''));
$ratePerGram = (float)($data['rate_per_gram'] ?? 0);
$deductionPercent = (float)($data['deduction_percent'] ?? 0);
$adjustmentType = trim((string)($data['adjustment_type'] ?? 'Exchange'));
$linkedSaleId = (int)($data['linked_sale_id'] ?? 0);
$notes = trim((string)($data['notes'] ?? ''));

if ($entryNo === '') {
    $entryNo = generateReferenceNo(
        $conn,
        'old_silver_entries',
        'entry_no',
        $businessId,
        'OS' . date('ym')
    );
}

if ($entryDate === '') {
    apiResponse(false, 'Entry date is required.', [], 422);
}

if ($customerName === '') {
    apiResponse(false, 'Customer name is required.', [], 422);
}

if ($ratePerGram <= 0) {
    apiResponse(false, 'Rate per gram must be greater than zero.', [], 422);
}

if ($deductionPercent < 0) {
    apiResponse(false, 'Deduction percentage cannot be negative.', [], 422);
}

if (!in_array($adjustmentType, ['Cash', 'Exchange', 'Pending'], true)) {
    apiResponse(false, 'Invalid adjustment type.', [], 422);
}

$items = $data['items'] ?? [];

if (!is_array($items) || empty($items)) {
    $names = $data['item_name'] ?? [];
    $purities = $data['purity'] ?? [];
    $grossWeights = $data['gross_weight'] ?? [];
    $lessWeights = $data['less_weight'] ?? [];
    $remarks = $data['item_remarks'] ?? [];

    $items = [];

    foreach ($names as $index => $name) {
        $items[] = [
            'item_name' => $name,
            'purity' => $purities[$index] ?? '',
            'gross_weight' => $grossWeights[$index] ?? 0,
            'less_weight' => $lessWeights[$index] ?? 0,
            'remarks' => $remarks[$index] ?? '',
        ];
    }
}

$cleanItems = [];
$totalGrossWeight = 0.0;
$totalLessWeight = 0.0;
$totalNetWeight = 0.0;

foreach ($items as $index => $item) {
    $itemName = trim((string)($item['item_name'] ?? ''));
    $purity = trim((string)($item['purity'] ?? ''));
    $grossWeight = (float)($item['gross_weight'] ?? 0);
    $lessWeight = (float)($item['less_weight'] ?? 0);
    $itemRemarks = trim((string)($item['remarks'] ?? $item['item_remarks'] ?? ''));

    if ($itemName === '' && $grossWeight <= 0 && $lessWeight <= 0) {
        continue;
    }

    if ($itemName === '') {
        apiResponse(false, 'Item name is required in row ' . ($index + 1) . '.', [], 422);
    }

    if ($grossWeight <= 0) {
        apiResponse(false, 'Gross weight must be greater than zero in row ' . ($index + 1) . '.', [], 422);
    }

    if ($lessWeight < 0 || $lessWeight > $grossWeight) {
        apiResponse(false, 'Invalid less weight in row ' . ($index + 1) . '.', [], 422);
    }

    $netWeight = $grossWeight - $lessWeight;

    $cleanItems[] = [
        'item_name' => $itemName,
        'purity' => $purity,
        'gross_weight' => $grossWeight,
        'less_weight' => $lessWeight,
        'net_weight' => $netWeight,
        'remarks' => $itemRemarks,
    ];

    $totalGrossWeight += $grossWeight;
    $totalLessWeight += $lessWeight;
    $totalNetWeight += $netWeight;
}

if (empty($cleanItems)) {
    apiResponse(false, 'Please add at least one old silver item.', [], 422);
}

$grossAmount = $totalNetWeight * $ratePerGram;
$deductionAmount = ($grossAmount * $deductionPercent) / 100;
$finalAmount = max(0, $grossAmount - $deductionAmount);

$stmt = $conn->prepare(
    'SELECT id
     FROM old_silver_entries
     WHERE business_id = ? AND entry_no = ?
     LIMIT 1'
);

if (!$stmt) {
    apiResponse(false, 'Unable to validate entry number: ' . $conn->error, [], 500);
}

$stmt->bind_param('is', $businessId, $entryNo);
$stmt->execute();
$duplicate = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($duplicate) {
    apiResponse(false, 'Entry number already exists.', [], 409);
}

$customerIdDb = $customerId > 0 ? $customerId : null;
$linkedSaleIdDb = $linkedSaleId > 0 ? $linkedSaleId : null;

$conn->begin_transaction();

try {
    $stmt = $conn->prepare(
        'INSERT INTO old_silver_entries
         (
            business_id, entry_no, entry_date, customer_id,
            customer_name, customer_mobile, id_proof_type,
            id_proof_number, total_gross_weight, total_less_weight,
            total_net_weight, rate_per_gram, deduction_percent,
            deduction_amount, final_amount, adjustment_type,
            linked_sale_id, notes, created_by
         )
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    if (!$stmt) {
        throw new Exception('Unable to prepare old silver entry insert: ' . $conn->error);
    }

    $stmt->bind_param(
        'ississssdddddddsisi',
        $businessId,
        $entryNo,
        $entryDate,
        $customerIdDb,
        $customerName,
        $customerMobile,
        $idProofType,
        $idProofNumber,
        $totalGrossWeight,
        $totalLessWeight,
        $totalNetWeight,
        $ratePerGram,
        $deductionPercent,
        $deductionAmount,
        $finalAmount,
        $adjustmentType,
        $linkedSaleIdDb,
        $notes,
        $userId
    );

    if (!$stmt->execute()) {
        throw new Exception('Unable to save old silver entry: ' . $stmt->error);
    }

    $entryId = (int)$stmt->insert_id;
    $stmt->close();

    $itemStmt = $conn->prepare(
        'INSERT INTO old_silver_items
         (
            business_id, old_silver_entry_id, item_name,
            purity, gross_weight, less_weight, net_weight, remarks
         )
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );

    if (!$itemStmt) {
        throw new Exception('Unable to prepare old silver item insert: ' . $conn->error);
    }

    foreach ($cleanItems as $item) {
        $itemStmt->bind_param(
            'iissddds',
            $businessId,
            $entryId,
            $item['item_name'],
            $item['purity'],
            $item['gross_weight'],
            $item['less_weight'],
            $item['net_weight'],
            $item['remarks']
        );

        if (!$itemStmt->execute()) {
            throw new Exception('Unable to save old silver item: ' . $itemStmt->error);
        }
    }

    $itemStmt->close();

    addAuditLogSafe(
        $conn,
        $businessId,
        $userId,
        'Old Silver',
        'Create',
        $entryId,
        'Created old silver entry ' . $entryNo
    );

    $conn->commit();

    apiResponse(
        true,
        'Old silver entry saved successfully.',
        [
            'entry_id' => $entryId,
            'entry_no' => $entryNo,
            'total_gross_weight' => $totalGrossWeight,
            'total_less_weight' => $totalLessWeight,
            'total_net_weight' => $totalNetWeight,
            'deduction_amount' => $deductionAmount,
            'final_amount' => $finalAmount,
        ]
    );
} catch (Throwable $e) {
    $conn->rollback();
    apiResponse(false, $e->getMessage(), [], 500);
}
