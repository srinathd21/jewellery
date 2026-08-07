<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_OFF);
ini_set('display_errors', '0');

function respond($success, $message = '', $extra = array(), $status = 200)
{
    http_response_code($status);
    echo json_encode(array_merge(array(
        'success' => (bool) $success,
        'message' => (string) $message,
    ), $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
        respond(false, 'Fatal API error: ' . $error['message'], array(), 500);
    }
});

foreach (array(
    dirname(__DIR__) . '/config/config.php',
    dirname(__DIR__) . '/config.php',
    dirname(__DIR__) . '/includes/config.php',
    dirname(__DIR__) . '/super-admin/includes/config.php',
) as $configFile) {
    if (is_file($configFile)) {
        require_once $configFile;
        break;
    }
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    respond(false, 'Database configuration is not available.', array(), 500);
}
$conn->set_charset('utf8mb4');

if (empty($_SESSION['user_id'])) {
    respond(false, 'Session expired.', array(), 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Invalid request method.', array(), 405);
}
if (!hash_equals((string) ($_SESSION['pawn_csrf'] ?? ''), (string) ($_POST['csrf_token'] ?? ''))) {
    respond(false, 'Invalid request token. Refresh the page.', array(), 419);
}

$businessId = (int) ($_SESSION['business_id'] ?? 0);
$userId = (int) ($_SESSION['user_id'] ?? 0);
$action = trim((string) ($_POST['action'] ?? ''));

if ($businessId <= 0) {
    respond(false, 'Select a valid business.', array(), 403);
}

function bindDynamic(mysqli_stmt $stmt, string $types, array &$values): void
{
    if (strlen($types) !== count($values)) {
        throw new RuntimeException('Prepared statement bind mismatch.');
    }
    $args = array($types);
    foreach ($values as $key => $value) {
        $args[] =& $values[$key];
    }
    call_user_func_array(array($stmt, 'bind_param'), $args);
}

function nextCategoryCode(mysqli $conn, int $businessId): string
{
    $stmt = $conn->prepare("SELECT category_code FROM pawn_categories WHERE business_id=? AND category_code REGEXP '^PCAT[0-9]+$' ORDER BY CAST(SUBSTRING(category_code,5) AS UNSIGNED) DESC LIMIT 1");
    if (!$stmt) {
        throw new RuntimeException('Unable to generate category code.');
    }
    $stmt->bind_param('i', $businessId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $next = 1;
    if ($row && preg_match('/^PCAT([0-9]+)$/', (string) $row['category_code'], $match)) {
        $next = ((int) $match[1]) + 1;
    }
    return 'PCAT' . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
}

function auditCategory(mysqli $conn, int $businessId, int $userId, int $categoryId, string $actionType, string $description, array $values = array()): void
{
    $check = $conn->query("SHOW TABLES LIKE 'audit_logs'");
    if (!$check || $check->num_rows === 0) {
        return;
    }
    $stmt = $conn->prepare("INSERT INTO audit_logs (business_id,user_id,module_code,action_type,reference_table,reference_id,description,new_values_json,ip_address,user_agent) VALUES (?,?,'pawn.categories',?,'pawn_categories',?,?,?,?,?)");
    if (!$stmt) {
        return;
    }
    $json = json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $stmt->bind_param('iisissss', $businessId, $userId, $actionType, $categoryId, $description, $json, $ip, $ua);
    $stmt->execute();
    $stmt->close();
}

if ($action === 'next_code') {
    try {
        respond(true, '', array('next_code' => nextCategoryCode($conn, $businessId)));
    } catch (Throwable $error) {
        respond(false, $error->getMessage(), array(), 500);
    }
}

if ($action === 'category_list') {
    $search = trim((string) ($_POST['search'] ?? ''));
    $where = 'business_id=?';
    $types = 'i';
    $params = array($businessId);

    if ($search !== '') {
        $where .= ' AND (category_code LIKE ? OR category_name LIKE ? OR category_type LIKE ? OR metal_type LIKE ? OR purity_standard LIKE ?)';
        $like = '%' . $search . '%';
        $types .= 'sssss';
        array_push($params, $like, $like, $like, $like, $like);
    }

    $stmt = $conn->prepare("SELECT * FROM pawn_categories WHERE {$where} ORDER BY is_active DESC, category_name ASC, id DESC");
    if (!$stmt) {
        respond(false, 'Unable to load pawn categories: ' . $conn->error, array(), 500);
    }
    bindDynamic($stmt, $types, $params);
    $stmt->execute();
    $result = $stmt->get_result();
    $categories = array();
    while ($row = $result->fetch_assoc()) {
        $categories[] = $row;
    }
    $stmt->close();
    respond(true, '', array('categories' => $categories));
}

if ($action === 'category_save') {
    $id = (int) ($_POST['id'] ?? 0);
    $name = trim((string) ($_POST['category_name'] ?? ''));
    $categoryType = trim((string) ($_POST['category_type'] ?? 'Ornament'));
    $metalType = trim((string) ($_POST['metal_type'] ?? ''));
    $purityStandard = trim((string) ($_POST['purity_standard'] ?? ''));
    $minRaw = trim((string) ($_POST['min_purity_percent'] ?? ''));
    $maxRaw = trim((string) ($_POST['max_purity_percent'] ?? ''));
    $minPurity = $minRaw === '' ? null : (float) $minRaw;
    $maxPurity = $maxRaw === '' ? null : (float) $maxRaw;
    $interest = max(0, (float) ($_POST['default_interest_percent'] ?? 0));
    $maxLoan = max(0, min(100, (float) ($_POST['max_loan_percent'] ?? 70)));
    $storageFee = max(0, (float) ($_POST['storage_fee_percent'] ?? 0));
    $valuationMethod = trim((string) ($_POST['valuation_method'] ?? 'Weight'));
    $requiresCertificate = !empty($_POST['requires_certificate']) ? 1 : 0;
    $requiresValuation = !empty($_POST['requires_valuation']) ? 1 : 0;
    $description = trim((string) ($_POST['description'] ?? ''));
    $isActive = !empty($_POST['is_active']) ? 1 : 0;

    if ($name === '') {
        respond(false, 'Category name is required.', array(), 422);
    }
    if (!in_array($categoryType, array('Ornament', 'Metal', 'Document', 'Other'), true)) {
        respond(false, 'Invalid category type.', array(), 422);
    }
    if ($metalType !== '' && !in_array($metalType, array('Gold', 'Silver', 'Platinum', 'Other'), true)) {
        respond(false, 'Invalid metal type.', array(), 422);
    }
    if (!in_array($valuationMethod, array('Weight', 'Piece', 'Stone', 'Combined'), true)) {
        respond(false, 'Invalid valuation method.', array(), 422);
    }
    if ($minPurity !== null && ($minPurity < 0 || $minPurity > 100)) {
        respond(false, 'Minimum purity must be between 0 and 100.', array(), 422);
    }
    if ($maxPurity !== null && ($maxPurity < 0 || $maxPurity > 100)) {
        respond(false, 'Maximum purity must be between 0 and 100.', array(), 422);
    }
    if ($minPurity !== null && $maxPurity !== null && $minPurity > $maxPurity) {
        respond(false, 'Minimum purity cannot exceed maximum purity.', array(), 422);
    }

    $duplicate = $conn->prepare('SELECT id FROM pawn_categories WHERE business_id=? AND LOWER(category_name)=LOWER(?) AND id<>? LIMIT 1');
    if (!$duplicate) {
        respond(false, 'Unable to validate category.', array(), 500);
    }
    $duplicate->bind_param('isi', $businessId, $name, $id);
    $duplicate->execute();
    $exists = $duplicate->get_result()->fetch_assoc();
    $duplicate->close();
    if ($exists) {
        respond(false, 'A pawn category with this name already exists.', array(), 422);
    }

    if ($id > 0) {
        $stmt = $conn->prepare('UPDATE pawn_categories SET category_name=?,category_type=?,metal_type=?,purity_standard=?,min_purity_percent=?,max_purity_percent=?,default_interest_percent=?,max_loan_percent=?,storage_fee_percent=?,valuation_method=?,requires_certificate=?,requires_valuation=?,description=?,is_active=? WHERE id=? AND business_id=?');
        if (!$stmt) {
            respond(false, 'Unable to prepare category update: ' . $conn->error, array(), 500);
        }
        $stmt->bind_param('ssssdddddsiisiii', $name, $categoryType, $metalType, $purityStandard, $minPurity, $maxPurity, $interest, $maxLoan, $storageFee, $valuationMethod, $requiresCertificate, $requiresValuation, $description, $isActive, $id, $businessId);
        if (!$stmt->execute()) {
            $message = $stmt->errno === 1062 ? 'Category name or code already exists.' : 'Unable to update category: ' . $stmt->error;
            $stmt->close();
            respond(false, $message, array(), 422);
        }
        $stmt->close();
        auditCategory($conn, $businessId, $userId, $id, 'Update', 'Updated pawn category ' . $name, array('category_name' => $name));
        respond(true, 'Pawn category updated successfully.', array('category_id' => $id));
    }

    $conn->begin_transaction();
    try {
        $createdId = 0;
        $createdCode = '';
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $createdCode = nextCategoryCode($conn, $businessId);
            $stmt = $conn->prepare('INSERT INTO pawn_categories (business_id,category_code,category_name,category_type,metal_type,purity_standard,min_purity_percent,max_purity_percent,default_interest_percent,max_loan_percent,storage_fee_percent,valuation_method,requires_certificate,requires_valuation,description,is_active,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            if (!$stmt) {
                throw new RuntimeException('Unable to prepare category creation: ' . $conn->error);
            }
            $stmt->bind_param(
    'isssssdddddsiisii',
    $businessId,
    $createdCode,
    $name,
    $categoryType,
    $metalType,
    $purityStandard,
    $minPurity,
    $maxPurity,
    $interest,
    $maxLoan,
    $storageFee,
    $valuationMethod,
    $requiresCertificate,
    $requiresValuation,
    $description,
    $isActive,
    $userId
);            if ($stmt->execute()) {
                $createdId = (int) $stmt->insert_id;
                $stmt->close();
                break;
            }
            $errno = $stmt->errno;
            $errorText = $stmt->error;
            $stmt->close();
            if ($errno !== 1062) {
                throw new RuntimeException('Unable to create category: ' . $errorText);
            }
        }
        if ($createdId <= 0) {
            throw new RuntimeException('Unable to generate a unique pawn category code.');
        }
        auditCategory($conn, $businessId, $userId, $createdId, 'Create', 'Created pawn category ' . $createdCode, array('category_code' => $createdCode, 'category_name' => $name));
        $conn->commit();
        respond(true, 'Pawn category created successfully. Code: ' . $createdCode, array('category_id' => $createdId, 'category_code' => $createdCode, 'next_code' => nextCategoryCode($conn, $businessId)));
    } catch (Throwable $error) {
        $conn->rollback();
        respond(false, $error->getMessage(), array(), 500);
    }
}

if ($action === 'category_toggle') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        respond(false, 'Invalid category.', array(), 422);
    }
    $stmt = $conn->prepare('UPDATE pawn_categories SET is_active=IF(is_active=1,0,1) WHERE id=? AND business_id=?');
    if (!$stmt) {
        respond(false, 'Unable to update category status.', array(), 500);
    }
    $stmt->bind_param('ii', $id, $businessId);
    if (!$stmt->execute() || $stmt->affected_rows === 0) {
        $stmt->close();
        respond(false, 'Pawn category was not found.', array(), 404);
    }
    $stmt->close();
    auditCategory($conn, $businessId, $userId, $id, 'Update', 'Changed pawn category status');
    respond(true, 'Category status updated successfully.');
}

respond(false, 'Invalid action.', array(), 400);