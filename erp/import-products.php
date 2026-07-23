<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));

foreach ([
    __DIR__ . '/config/config.php',
    __DIR__ . '/config.php',
    __DIR__ . '/includes/config.php',
    __DIR__ . '/super-admin/includes/config.php',
] as $configFile) {
    if (is_file($configFile)) {
        require_once $configFile;
        break;
    }
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database configuration is not available.');
}
$conn->set_charset('utf8mb4');

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
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

function bindDynamic(mysqli_stmt $stmt, string $types, array &$values): void
{
    if ($types === '' || !$values) {
        return;
    }

    $bind = [$types];
    foreach ($values as $key => $value) {
        $bind[] = &$values[$key];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind);
}

function fetchRows(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($conn->error);
    }

    bindDynamic($stmt, $types, $params);

    if (!$stmt->execute()) {
        throw new RuntimeException($stmt->error);
    }

    $result = $stmt->get_result();
    $rows = [];
    while ($result && $row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

function productPermission(mysqli $conn, string $action): bool
{
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin') {
        return true;
    }

    $map = [
        'open' => 'can_open',
        'view' => 'can_view',
        'create' => 'can_create',
        'update' => 'can_update',
    ];

    $field = $map[$action] ?? '';
    if ($field === '') {
        return false;
    }

    $permissions = $_SESSION['permissions'] ?? [];
    foreach (['perm.products.import', 'perm.products.list', 'perm.products'] as $code) {
        if (isset($permissions[$code]) && array_key_exists($field, $permissions[$code])) {
            return (int)$permissions[$code][$field] === 1;
        }
    }

    $businessId = (int)($_SESSION['business_id'] ?? 0);
    $roleId = (int)($_SESSION['role_id'] ?? 0);
    if ($businessId <= 0 || $roleId <= 0 || !tableExists($conn, 'permissions') || !tableExists($conn, 'role_permissions')) {
        return false;
    }

    $sql = "SELECT MAX(COALESCE(rp.`{$field}`,0)) AS allowed
            FROM role_permissions rp
            INNER JOIN permissions p ON p.id=rp.permission_id
            WHERE rp.business_id=? AND rp.role_id=?
              AND p.permission_code IN ('perm.products.import','perm.products.list','perm.products')";

    if (hasColumn($conn, 'permissions', 'is_active')) {
        $sql .= ' AND p.is_active=1';
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ii', $businessId, $roleId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['allowed'] ?? 0) === 1;
}

function cleanHeader(string $value): string
{
    $value = preg_replace('/^\xEF\xBB\xBF/', '', trim($value));
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '_', $value);
    return trim((string)$value, '_');
}

function valueFromRow(array $row, array $names, $default = '')
{
    foreach ($names as $name) {
        if (array_key_exists($name, $row) && trim((string)$row[$name]) !== '') {
            return trim((string)$row[$name]);
        }
    }
    return $default;
}

function decimalValue($value, float $default = 0.0): float
{
    $value = str_replace([',', 'Rs.', 'rs.', 'INR', 'inr'], '', trim((string)$value));
    if ($value === '') {
        return $default;
    }
    if (preg_match('/-?[0-9]+(?:\.[0-9]+)?/', $value, $match)) {
        return (float)$match[0];
    }
    return $default;
}

function booleanValue($value, int $default = 1): int
{
    $value = strtolower(trim((string)$value));
    if ($value === '') {
        return $default;
    }
    if (in_array($value, ['1', 'yes', 'y', 'true', 'active', 'enabled', 'on'], true)) {
        return 1;
    }
    if (in_array($value, ['0', 'no', 'n', 'false', 'inactive', 'disabled', 'off'], true)) {
        return 0;
    }
    return $default;
}

function safeText($value, int $length): string
{
    $value = trim((string)$value);
    return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
}

function resolveMasterId(array $lookup, $raw): int
{
    $value = strtolower(trim((string)$raw));
    if ($value === '') {
        return 0;
    }
    if (isset($lookup['id'][$value])) {
        return (int)$lookup['id'][$value];
    }
    if (isset($lookup['name'][$value])) {
        return (int)$lookup['name'][$value];
    }
    if (isset($lookup['code'][$value])) {
        return (int)$lookup['code'][$value];
    }
    return 0;
}

function buildMasterLookup(mysqli $conn, string $table, string $nameColumn, int $businessId, array $codeCandidates): array
{
    $lookup = ['id' => [], 'name' => [], 'code' => [], 'rows' => []];
    if (!tableExists($conn, $table)) {
        return $lookup;
    }

    $idColumn = hasColumn($conn, $table, 'id') ? 'id' : '';
    if ($idColumn === '' || !hasColumn($conn, $table, $nameColumn)) {
        return $lookup;
    }

    $codeColumn = '';
    foreach ($codeCandidates as $candidate) {
        if (hasColumn($conn, $table, $candidate)) {
            $codeColumn = $candidate;
            break;
        }
    }

    $selectCode = $codeColumn !== '' ? ", `{$codeColumn}` AS master_code" : ", '' AS master_code";
    $sql = "SELECT `{$idColumn}` AS id, `{$nameColumn}` AS master_name {$selectCode} FROM `{$table}` WHERE 1=1";
    $types = '';
    $params = [];

    if (hasColumn($conn, $table, 'business_id')) {
        $sql .= ' AND business_id=?';
        $types .= 'i';
        $params[] = $businessId;
    }
    if (hasColumn($conn, $table, 'is_active')) {
        $sql .= ' AND is_active=1';
    }
    $sql .= " ORDER BY `{$nameColumn}`";

    $rows = fetchRows($conn, $sql, $types, $params);
    foreach ($rows as $row) {
        $id = (int)$row['id'];
        $name = strtolower(trim((string)$row['master_name']));
        $code = strtolower(trim((string)$row['master_code']));
        $lookup['id'][(string)$id] = $id;
        if ($name !== '') {
            $lookup['name'][$name] = $id;
        }
        if ($code !== '') {
            $lookup['code'][$code] = $id;
        }
        $lookup['rows'][] = $row;
    }

    return $lookup;
}

function readImportRows(string $filePath, string $extension): array
{
    $extension = strtolower($extension);

    if ($extension === 'csv') {
        $handle = fopen($filePath, 'rb');
        if (!$handle) {
            throw new RuntimeException('Unable to read the uploaded CSV file.');
        }

        $firstLine = fgets($handle);
        if ($firstLine === false) {
            fclose($handle);
            throw new RuntimeException('The uploaded CSV file is empty.');
        }

        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
        rewind($handle);

        $headers = fgetcsv($handle, 0, $delimiter);
        if (!$headers) {
            fclose($handle);
            throw new RuntimeException('CSV headers could not be read.');
        }
        $headers = array_map('cleanHeader', $headers);

        $rows = [];
        while (($values = fgetcsv($handle, 0, $delimiter)) !== false) {
            $nonEmpty = false;
            foreach ($values as $value) {
                if (trim((string)$value) !== '') {
                    $nonEmpty = true;
                    break;
                }
            }
            if (!$nonEmpty) {
                continue;
            }
            $values = array_pad($values, count($headers), '');
            $rows[] = array_combine($headers, array_slice($values, 0, count($headers)));
            if (count($rows) > 2000) {
                fclose($handle);
                throw new RuntimeException('A maximum of 2,000 product rows can be imported at one time.');
            }
        }
        fclose($handle);
        return $rows;
    }

    foreach ([__DIR__ . '/vendor/autoload.php', __DIR__ . '/includes/vendor/autoload.php'] as $autoload) {
        if (is_file($autoload)) {
            require_once $autoload;
            break;
        }
    }

    if (!class_exists('PhpOffice\\PhpSpreadsheet\\IOFactory')) {
        throw new RuntimeException('Excel import needs PhpSpreadsheet. Upload a CSV file, or install phpoffice/phpspreadsheet.');
    }

    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
    $sheet = $spreadsheet->getActiveSheet();
    $data = $sheet->toArray('', true, true, false);
    if (!$data) {
        throw new RuntimeException('The uploaded Excel file is empty.');
    }

    $headers = array_map('cleanHeader', array_shift($data));
    $rows = [];
    foreach ($data as $values) {
        $nonEmpty = false;
        foreach ($values as $value) {
            if (trim((string)$value) !== '') {
                $nonEmpty = true;
                break;
            }
        }
        if (!$nonEmpty) {
            continue;
        }
        $values = array_pad($values, count($headers), '');
        $rows[] = array_combine($headers, array_slice($values, 0, count($headers)));
        if (count($rows) > 2000) {
            throw new RuntimeException('A maximum of 2,000 product rows can be imported at one time.');
        }
    }
    return $rows;
}

function nextProductCode(mysqli $conn, int $businessId, int &$sequence): string
{
    do {
        $code = 'PRD' . str_pad((string)$sequence, 6, '0', STR_PAD_LEFT);
        $sequence++;
        $stmt = $conn->prepare('SELECT id FROM products WHERE business_id=? AND product_code=? LIMIT 1');
        if (!$stmt) {
            throw new RuntimeException('Unable to generate product code.');
        }
        $stmt->bind_param('is', $businessId, $code);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } while ($exists);

    return $code;
}

function addAuditSafe(mysqli $conn, int $businessId, int $branchId, int $userId, int $productId, string $description): void
{
    if (!tableExists($conn, 'audit_logs')) {
        return;
    }

    $columns = [];
    $placeholders = [];
    $types = '';
    $values = [];
    $fieldValues = [
        'business_id' => [$businessId, 'i'],
        'branch_id' => [$branchId, 'i'],
        'user_id' => [$userId, 'i'],
        'module_code' => ['products.import', 's'],
        'module_name' => ['Product Import', 's'],
        'action_type' => ['Import', 's'],
        'reference_table' => ['products', 's'],
        'reference_id' => [$productId, 'i'],
        'description' => [$description, 's'],
        'ip_address' => [(string)($_SERVER['REMOTE_ADDR'] ?? ''), 's'],
        'user_agent' => [(string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 's'],
    ];

    foreach ($fieldValues as $column => $pair) {
        if (hasColumn($conn, 'audit_logs', $column)) {
            $columns[] = "`{$column}`";
            $placeholders[] = '?';
            $values[] = $pair[0];
            $types .= $pair[1];
        }
    }

    if (!$columns) {
        return;
    }

    $sql = 'INSERT INTO audit_logs (' . implode(',', $columns) . ') VALUES (' . implode(',', $placeholders) . ')';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return;
    }
    bindDynamic($stmt, $types, $values);
    $stmt->execute();
    $stmt->close();
}

function updateOpeningStock(
    mysqli $conn,
    int $businessId,
    int $branchId,
    int $userId,
    int $productId,
    float $quantity,
    float $grossWeight,
    float $netWeight,
    float $purchaseRate,
    string $stockMode
): bool {
    if ($stockMode === 'ignore' || !tableExists($conn, 'product_stock') || $branchId <= 0) {
        return false;
    }

    if ($quantity == 0.0 && $grossWeight == 0.0 && $netWeight == 0.0) {
        return false;
    }

    $required = ['business_id', 'branch_id', 'product_id'];
    foreach ($required as $column) {
        if (!hasColumn($conn, 'product_stock', $column)) {
            return false;
        }
    }

    $selectColumns = ['id'];
    foreach (['quantity', 'gross_weight', 'net_weight', 'average_cost', 'stock_value'] as $column) {
        if (hasColumn($conn, 'product_stock', $column)) {
            $selectColumns[] = $column;
        }
    }

    $stmt = $conn->prepare(
        'SELECT ' . implode(',', $selectColumns) .
        ' FROM product_stock WHERE business_id=? AND branch_id=? AND product_id=? LIMIT 1 FOR UPDATE'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare opening stock lookup.');
    }
    $stmt->bind_param('iii', $businessId, $branchId, $productId);
    $stmt->execute();
    $current = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $oldQty = (float)($current['quantity'] ?? 0);
    $oldGross = (float)($current['gross_weight'] ?? 0);
    $oldNet = (float)($current['net_weight'] ?? 0);

    if ($stockMode === 'replace') {
        $newQty = $quantity;
        $newGross = $grossWeight;
        $newNet = $netWeight;
    } else {
        $newQty = $oldQty + $quantity;
        $newGross = $oldGross + $grossWeight;
        $newNet = $oldNet + $netWeight;
    }

    $stockValue = $purchaseRate * ($newNet > 0 ? $newNet : $newQty);

    $available = [];
    $valuesByColumn = [
        'quantity' => $newQty,
        'gross_weight' => $newGross,
        'net_weight' => $newNet,
        'average_cost' => $purchaseRate,
        'stock_value' => $stockValue,
    ];

    foreach ($valuesByColumn as $column => $value) {
        if (hasColumn($conn, 'product_stock', $column)) {
            $available[$column] = $value;
        }
    }

    if ($current) {
        $sets = [];
        $types = '';
        $params = [];
        foreach ($available as $column => $value) {
            $sets[] = "`{$column}`=?";
            $types .= 'd';
            $params[] = $value;
        }
        if (hasColumn($conn, 'product_stock', 'updated_at')) {
            $sets[] = 'updated_at=NOW()';
        }
        if ($sets) {
            $types .= 'i';
            $params[] = (int)$current['id'];
            $stmt = $conn->prepare('UPDATE product_stock SET ' . implode(',', $sets) . ' WHERE id=?');
            if (!$stmt) {
                throw new RuntimeException('Unable to prepare opening stock update.');
            }
            bindDynamic($stmt, $types, $params);
            if (!$stmt->execute()) {
                throw new RuntimeException($stmt->error);
            }
            $stmt->close();
        }
    } else {
        $columns = ['business_id', 'branch_id', 'product_id'];
        $placeholders = ['?', '?', '?'];
        $types = 'iii';
        $params = [$businessId, $branchId, $productId];

        foreach ($available as $column => $value) {
            $columns[] = "`{$column}`";
            $placeholders[] = '?';
            $types .= 'd';
            $params[] = $value;
        }
        if (hasColumn($conn, 'product_stock', 'created_at')) {
            $columns[] = 'created_at';
            $placeholders[] = 'NOW()';
        }
        if (hasColumn($conn, 'product_stock', 'updated_at')) {
            $columns[] = 'updated_at';
            $placeholders[] = 'NOW()';
        }

        $sql = 'INSERT INTO product_stock (' . implode(',', $columns) . ') VALUES (' . implode(',', $placeholders) . ')';
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare opening stock insert: ' . $conn->error);
        }
        bindDynamic($stmt, $types, $params);
        if (!$stmt->execute()) {
            throw new RuntimeException($stmt->error);
        }
        $stmt->close();
    }

    if (tableExists($conn, 'stock_movements')) {
        $deltaQty = $newQty - $oldQty;
        $deltaWeight = $newNet - $oldNet;
        $fieldValues = [
            'business_id' => [$businessId, 'i'],
            'branch_id' => [$branchId, 'i'],
            'product_id' => [$productId, 'i'],
            'movement_type' => [$stockMode === 'replace' ? 'Import Adjustment' : 'Opening Stock', 's'],
            'reference_table' => ['products', 's'],
            'reference_id' => [$productId, 'i'],
            'quantity_in' => [max(0, $deltaQty), 'd'],
            'quantity_out' => [max(0, -$deltaQty), 'd'],
            'weight_in' => [max(0, $deltaWeight), 'd'],
            'weight_out' => [max(0, -$deltaWeight), 'd'],
            'rate' => [$purchaseRate, 'd'],
            'value_amount' => [abs($purchaseRate * ($deltaWeight != 0.0 ? $deltaWeight : $deltaQty)), 'd'],
            'remarks' => ['Product import opening stock', 's'],
            'created_by' => [$userId, 'i'],
        ];

        $columns = [];
        $placeholders = [];
        $types = '';
        $params = [];

        foreach ($fieldValues as $column => $pair) {
            if (hasColumn($conn, 'stock_movements', $column)) {
                $columns[] = "`{$column}`";
                $placeholders[] = '?';
                $types .= $pair[1];
                $params[] = $pair[0];
            }
        }
        if (hasColumn($conn, 'stock_movements', 'movement_date')) {
            $columns[] = 'movement_date';
            $placeholders[] = 'NOW()';
        }
        if (hasColumn($conn, 'stock_movements', 'created_at')) {
            $columns[] = 'created_at';
            $placeholders[] = 'NOW()';
        }

        if ($columns && ($deltaQty != 0.0 || $deltaWeight != 0.0)) {
            $sql = 'INSERT INTO stock_movements (' . implode(',', $columns) . ') VALUES (' . implode(',', $placeholders) . ')';
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                bindDynamic($stmt, $types, $params);
                $stmt->execute();
                $stmt->close();
            }
        }
    }

    return true;
}

$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? $_SESSION['default_branch_id'] ?? 0);
$userId = (int)($_SESSION['user_id'] ?? 0);
$businessName = (string)($_SESSION['business_name'] ?? 'Jewellery ERP');

if ($businessId <= 0) {
    http_response_code(403);
    die('A valid business must be selected.');
}

if (!tableExists($conn, 'products') || !tableExists($conn, 'product_categories') || !tableExists($conn, 'units')) {
    die('Required product master tables are missing.');
}

if (!productPermission($conn, 'open')) {
    http_response_code(403);
    die('Access denied. You do not have permission to import products.');
}

$canCreate = productPermission($conn, 'create');
$canUpdate = productPermission($conn, 'update');
if (!$canCreate && !$canUpdate) {
    http_response_code(403);
    die('You do not have permission to create or update products.');
}

if (empty($_SESSION['products_import_csrf'])) {
    $_SESSION['products_import_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['products_import_csrf'];

if (isset($_GET['download_sample'])) {
    $headers = [
        'product_code', 'product_name', 'barcode', 'category', 'metal', 'unit',
        'hsn_code', 'purity', 'gross_weight', 'stone_weight', 'net_weight',
        'wastage_percent', 'making_charge_type', 'making_charge', 'purchase_rate',
        'sale_rate', 'tax_percent', 'minimum_stock_qty', 'track_stock',
        'opening_quantity', 'opening_gross_weight', 'opening_net_weight',
        'description', 'is_active'
    ];
    $example = [
        'PRD000001', 'Gold Chain', '890000000001', 'Chain', 'Gold', 'Piece',
        '7113', '22', '12.000', '0.000', '12.000', '5', 'Per Gram', '250',
        '7200', '7600', '3', '1', '1', '1', '12.000', '12.000',
        'Imported sample product', '1'
    ];

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="product-import-sample.csv"');
    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'wb');
    fputcsv($output, $headers);
    fputcsv($output, $example);
    fclose($output);
    exit;
}

if (isset($_GET['download_errors'])) {
    $errors = is_array($_SESSION['product_import_errors'] ?? null) ? $_SESSION['product_import_errors'] : [];
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="product-import-errors.csv"');
    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'wb');
    fputcsv($output, ['row', 'product_code', 'product_name', 'error']);
    foreach ($errors as $error) {
        fputcsv($output, [
            $error['row'] ?? '',
            $error['product_code'] ?? '',
            $error['product_name'] ?? '',
            $error['error'] ?? '',
        ]);
    }
    fclose($output);
    unset($_SESSION['product_import_errors']);
    exit;
}

$categoryLookup = buildMasterLookup($conn, 'product_categories', 'category_name', $businessId, ['category_code', 'code']);
$metalLookup = buildMasterLookup($conn, 'metals', 'metal_name', $businessId, ['metal_code', 'code']);
$unitLookup = buildMasterLookup($conn, 'units', 'unit_name', $businessId, ['unit_code', 'code']);


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['preview_import'])) {
    if (!hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        echo json_encode([
            'success' => false,
            'message' => 'Session expired.'
        ]);
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');

    try {
        if (empty($_FILES['product_file'])) {
            throw new RuntimeException('Select a product file.');
        }

        $file = $_FILES['product_file'];
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        $previewRows = readImportRows($file['tmp_name'], $extension);

        $preview = [];
$errorCount = 0;

foreach (array_slice($previewRows, 0, 20) as $index => $row) {

            $name = safeText(
                valueFromRow($row, ['product_name','item_name','name']),
                200
            );

            $category = valueFromRow($row, ['category','category_name','category_code','category_id']);
            $metal = valueFromRow($row, ['metal','metal_name','metal_code','metal_id']);
            $unit = valueFromRow($row, ['unit','unit_name','unit_code','unit_id']);

            $rowError = [];

            if ($name === '') {
                $rowError[] = 'Product name missing';
            }

            if (resolveMasterId($categoryLookup, $category) <= 0) {
                $rowError[] = 'Invalid category';
            }

            if (resolveMasterId($unitLookup, $unit) <= 0) {
                $rowError[] = 'Invalid unit';
            }

            if ($metal !== '' && resolveMasterId($metalLookup, $metal) <= 0) {
                $rowError[] = 'Invalid metal';
            }

            $preview[] = [
                'row' => $index + 2,
                'product_name' => $name,
                'category' => $category,
                'metal' => $metal,
                'unit' => $unit,
                'status' => $rowError ? 'Error' : 'Ready',
                'errors' => implode(', ', $rowError)
            ];

            if ($rowError) {
    $errorCount++;
}
        }

        echo json_encode([
            'success' => true,
            'total' => count($previewRows),
            'preview' => $preview,
            'errors' => $errorCount,
'can_import' => $errorCount === 0
        ]);

    } catch (Throwable $e) {

        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }

    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        $_SESSION['product_import_result'] = [
            'success' => false,
            'message' => 'Session expired. Refresh the page and try again.',
            'errors' => [],
        ];
        header('Location: import-products.php');
        exit;
    }

    $duplicateMode = in_array((string)($_POST['duplicate_mode'] ?? 'skip'), ['skip', 'update'], true)
        ? (string)$_POST['duplicate_mode']
        : 'skip';
    $stockMode = in_array((string)($_POST['stock_mode'] ?? 'add'), ['add', 'replace', 'ignore'], true)
        ? (string)$_POST['stock_mode']
        : 'add';

    $result = [
        'success' => false,
        'message' => '',
        'total' => 0,
        'created' => 0,
        'updated' => 0,
        'skipped' => 0,
        'failed' => 0,
        'stock_updated' => 0,
        'errors' => [],
    ];

    try {
        if (empty($_FILES['product_file'])) {
            throw new RuntimeException('Select a CSV or Excel product file.');
        }

        $file = $_FILES['product_file'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Product file upload failed.');
        }
        if (($file['size'] ?? 0) > 8 * 1024 * 1024) {
            throw new RuntimeException('The import file must be below 8 MB.');
        }

        $extension = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, ['csv', 'xlsx', 'xls'], true)) {
            throw new RuntimeException('Only CSV, XLSX and XLS files are supported.');
        }

        $importRows = readImportRows((string)$file['tmp_name'], $extension);
        if (!$importRows) {
            throw new RuntimeException('The import file contains no product rows.');
        }
        $result['total'] = count($importRows);

        $maxRow = fetchRows($conn, 'SELECT COALESCE(MAX(id),0)+1 AS next_id FROM products WHERE business_id=?', 'i', [$businessId]);
        $sequence = (int)($maxRow[0]['next_id'] ?? 1);

        foreach ($importRows as $index => $row) {
            $rowNumber = $index + 2;
            $productName = safeText(valueFromRow($row, ['product_name', 'item_name', 'name']), 200);
            $productCode = strtoupper(safeText(valueFromRow($row, ['product_code', 'sku_code', 'sku']), 80));
            $barcode = safeText(valueFromRow($row, ['barcode', 'bar_code']), 100);

            try {
                if ($productName === '') {
                    throw new RuntimeException('Product name is required.');
                }

                $categoryRaw = valueFromRow($row, ['category', 'category_name', 'category_code', 'category_id']);
                $unitRaw = valueFromRow($row, ['unit', 'unit_name', 'unit_code', 'unit_id']);
                $metalRaw = valueFromRow($row, ['metal', 'metal_name', 'metal_code', 'metal_id']);

                $categoryId = resolveMasterId($categoryLookup, $categoryRaw);
                $unitId = resolveMasterId($unitLookup, $unitRaw);
                $metalId = resolveMasterId($metalLookup, $metalRaw);

                if ($categoryId <= 0) {
                    throw new RuntimeException('Category not found: ' . ($categoryRaw !== '' ? $categoryRaw : '(blank)'));
                }
                if ($unitId <= 0) {
                    throw new RuntimeException('Unit not found: ' . ($unitRaw !== '' ? $unitRaw : '(blank)'));
                }
                if ($metalRaw !== '' && $metalId <= 0) {
                    throw new RuntimeException('Metal not found: ' . $metalRaw);
                }

                if ($productCode === '') {
                    $productCode = nextProductCode($conn, $businessId, $sequence);
                }

                $existing = null;
                $stmt = $conn->prepare('SELECT * FROM products WHERE business_id=? AND product_code=? LIMIT 1');
                if (!$stmt) {
                    throw new RuntimeException('Unable to check product code.');
                }
                $stmt->bind_param('is', $businessId, $productCode);
                $stmt->execute();
                $existing = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$existing && $barcode !== '') {
                    $stmt = $conn->prepare('SELECT * FROM products WHERE business_id=? AND barcode=? LIMIT 1');
                    if (!$stmt) {
                        throw new RuntimeException('Unable to check barcode.');
                    }
                    $stmt->bind_param('is', $businessId, $barcode);
                    $stmt->execute();
                    $existing = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                }

                if ($existing && $duplicateMode === 'skip') {
                    $result['skipped']++;
                    continue;
                }
                if ($existing && !$canUpdate) {
                    throw new RuntimeException('An existing product was found, but you do not have update permission.');
                }
                if (!$existing && !$canCreate) {
                    throw new RuntimeException('You do not have permission to create products.');
                }

                $grossWeight = max(0, decimalValue(valueFromRow($row, ['gross_weight', 'gross_wt'])));
                $stoneWeight = max(0, decimalValue(valueFromRow($row, ['stone_weight', 'stone_wt', 'less_weight'])));
                $netRaw = valueFromRow($row, ['net_weight', 'net_wt']);
                $netWeight = $netRaw === '' ? max(0, $grossWeight - $stoneWeight) : max(0, decimalValue($netRaw));
                $purity = max(0, decimalValue(valueFromRow($row, ['purity', 'karat', 'carat'])));
                $wastage = max(0, decimalValue(valueFromRow($row, ['wastage_percent', 'wastage', 'waste_percent'])));
                $makingType = valueFromRow($row, ['making_charge_type', 'making_type'], 'Per Gram');
                if (!in_array($makingType, ['Per Gram', 'Fixed', 'Percentage'], true)) {
                    $makingType = 'Per Gram';
                }

                $data = [
                    'category_id' => $categoryId,
                    'metal_id' => $metalId,
                    'unit_id' => $unitId,
                    'product_code' => $productCode,
                    'barcode' => $barcode !== '' ? $barcode : null,
                    'product_name' => $productName,
                    'hsn_code' => safeText(valueFromRow($row, ['hsn_code', 'hsn']), 30),
                    'purity' => $purity,
                    'gross_weight' => $grossWeight,
                    'stone_weight' => $stoneWeight,
                    'net_weight' => $netWeight,
                    'wastage_percent' => $wastage,
                    'making_charge_type' => $makingType,
                    'making_charge' => max(0, decimalValue(valueFromRow($row, ['making_charge', 'making']))),
                    'purchase_rate' => max(0, decimalValue(valueFromRow($row, ['purchase_rate', 'purchase_price', 'cost_rate']))),
                    'sale_rate' => max(0, decimalValue(valueFromRow($row, ['sale_rate', 'selling_rate', 'sale_price']))),
                    'tax_percent' => max(0, decimalValue(valueFromRow($row, ['tax_percent', 'gst_percent', 'gst']), 3)),
                    'minimum_stock_qty' => max(0, decimalValue(valueFromRow($row, ['minimum_stock_qty', 'minimum_stock', 'reorder_level']))),
                    'track_stock' => booleanValue(valueFromRow($row, ['track_stock', 'is_stock_item']), 1),
                    'description' => safeText(valueFromRow($row, ['description', 'notes']), 1000),
                    'is_active' => booleanValue(valueFromRow($row, ['is_active', 'status']), 1),
                ];

                $metalParam = $data['metal_id'] > 0 ? $data['metal_id'] : null;
                $conn->begin_transaction();
                $wasUpdate = (bool)$existing;

                if ($existing) {
                    $productId = (int)$existing['id'];
                    $stmt = $conn->prepare(
                        'UPDATE products SET category_id=?,metal_id=?,unit_id=?,product_code=?,barcode=?,product_name=?,hsn_code=?,purity=?,gross_weight=?,stone_weight=?,net_weight=?,wastage_percent=?,making_charge_type=?,making_charge=?,purchase_rate=?,sale_rate=?,tax_percent=?,minimum_stock_qty=?,track_stock=?,description=?,is_active=? WHERE id=? AND business_id=?'
                    );
                    if (!$stmt) {
                        throw new RuntimeException('Unable to prepare product update: ' . $conn->error);
                    }
                    $hsn = $data['hsn_code'] !== '' ? $data['hsn_code'] : null;
                    $description = $data['description'] !== '' ? $data['description'] : null;
                    $stmt->bind_param(
                        'iiissssdddddsdddddisiii',
                        $data['category_id'], $metalParam, $data['unit_id'],
                        $data['product_code'], $data['barcode'], $data['product_name'], $hsn,
                        $data['purity'], $data['gross_weight'], $data['stone_weight'], $data['net_weight'],
                        $data['wastage_percent'], $data['making_charge_type'], $data['making_charge'],
                        $data['purchase_rate'], $data['sale_rate'], $data['tax_percent'],
                        $data['minimum_stock_qty'], $data['track_stock'], $description, $data['is_active'],
                        $productId, $businessId
                    );
                    if (!$stmt->execute()) {
                        throw new RuntimeException('Unable to update product: ' . $stmt->error);
                    }
                    $stmt->close();
                    $actionText = 'Updated imported product ' . $productName;
                } else {
                    $stmt = $conn->prepare(
                        'INSERT INTO products (business_id,category_id,metal_id,unit_id,product_code,barcode,product_name,hsn_code,purity,gross_weight,stone_weight,net_weight,wastage_percent,making_charge_type,making_charge,purchase_rate,sale_rate,tax_percent,minimum_stock_qty,track_stock,description,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                    );
                    if (!$stmt) {
                        throw new RuntimeException('Unable to prepare product insert: ' . $conn->error);
                    }
                    $hsn = $data['hsn_code'] !== '' ? $data['hsn_code'] : null;
                    $description = $data['description'] !== '' ? $data['description'] : null;
                    $stmt->bind_param(
                        'iiiissssdddddsdddddisi',
                        $businessId, $data['category_id'], $metalParam, $data['unit_id'],
                        $data['product_code'], $data['barcode'], $data['product_name'], $hsn,
                        $data['purity'], $data['gross_weight'], $data['stone_weight'], $data['net_weight'],
                        $data['wastage_percent'], $data['making_charge_type'], $data['making_charge'],
                        $data['purchase_rate'], $data['sale_rate'], $data['tax_percent'],
                        $data['minimum_stock_qty'], $data['track_stock'], $description, $data['is_active']
                    );
                    if (!$stmt->execute()) {
                        throw new RuntimeException('Unable to create product: ' . $stmt->error);
                    }
                    $productId = (int)$stmt->insert_id;
                    $stmt->close();
                    $actionText = 'Created imported product ' . $productName;
                }

                $openingQty = max(0, decimalValue(valueFromRow($row, ['opening_quantity', 'opening_qty', 'stock_qty'])));
                $openingGross = max(0, decimalValue(valueFromRow($row, ['opening_gross_weight', 'opening_gross', 'stock_gross_weight'])));
                $openingNet = max(0, decimalValue(valueFromRow($row, ['opening_net_weight', 'opening_net', 'stock_net_weight'])));

                if ($data['track_stock'] === 1 && updateOpeningStock(
                    $conn, $businessId, $branchId, $userId, $productId,
                    $openingQty, $openingGross, $openingNet, $data['purchase_rate'], $stockMode
                )) {
                    $result['stock_updated']++;
                }

                addAuditSafe($conn, $businessId, $branchId, $userId, $productId, $actionText);
                $conn->commit();
                if ($wasUpdate) {
                    $result['updated']++;
                } else {
                    $result['created']++;
                }
            } catch (Throwable $rowError) {
                try {
                    $conn->rollback();
                } catch (Throwable $ignored) {
                }
                $result['failed']++;
                if (count($result['errors']) < 250) {
                    $result['errors'][] = [
                        'row' => $rowNumber,
                        'product_code' => $productCode,
                        'product_name' => $productName,
                        'error' => $rowError->getMessage(),
                    ];
                }
            }
        }

        $result['success'] = $result['created'] > 0 || $result['updated'] > 0 || $result['skipped'] > 0;
        $result['message'] = 'Import completed: ' . $result['created'] . ' created, ' . $result['updated'] . ' updated, ' . $result['skipped'] . ' skipped and ' . $result['failed'] . ' failed.';
    } catch (Throwable $error) {
        $result['success'] = false;
        $result['message'] = $error->getMessage();
    }

    $_SESSION['product_import_result'] = $result;
    $_SESSION['product_import_errors'] = $result['errors'];
    header('Location: import-products.php');
    exit;
}

$importResult = $_SESSION['product_import_result'] ?? null;
unset($_SESSION['product_import_result']);

$stats = ['products' => 0, 'categories' => count($categoryLookup['rows']), 'metals' => count($metalLookup['rows']), 'units' => count($unitLookup['rows'])];
$stmt = $conn->prepare('SELECT COUNT(*) AS total FROM products WHERE business_id=?');
if ($stmt) {
    $stmt->bind_param('i', $businessId);
    $stmt->execute();
    $stats['products'] = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
}

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

if (tableExists($conn, 'business_theme_settings')) {
    $stmt = $conn->prepare('SELECT * FROM business_theme_settings WHERE business_id=? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('i', $businessId);
        $stmt->execute();
        $themeRow = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        foreach ($theme as $key => $defaultValue) {
            if (isset($themeRow[$key]) && $themeRow[$key] !== '') {
                $theme[$key] = $themeRow[$key];
            }
        }
    }
}

$pageTitle = 'Import Products';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo e($businessName); ?> - Import Products</title>
    <?php include('includes/links.php'); ?>
    <style>
        :root{
            --primary:<?php echo e($theme['primary_color']); ?>;
            --primary-dark:<?php echo e($theme['primary_dark_color']); ?>;
            --primary-soft:<?php echo e($theme['primary_soft_color']); ?>;
            --sidebar-gradient-1:<?php echo e($theme['sidebar_gradient_1']); ?>;
            --sidebar-gradient-2:<?php echo e($theme['sidebar_gradient_2']); ?>;
            --sidebar-gradient-3:<?php echo e($theme['sidebar_gradient_3']); ?>;
            --page-bg:<?php echo e($theme['page_background']); ?>;
            --card-bg:<?php echo e($theme['card_background']); ?>;
            --text-color:<?php echo e($theme['text_color']); ?>;
            --muted-color:<?php echo e($theme['muted_text_color']); ?>;
            --border-color:<?php echo e($theme['border_color']); ?>;
            --sidebar-width:<?php echo (int)$theme['sidebar_width_px']; ?>px;
            --radius:<?php echo (int)$theme['border_radius_px']; ?>px;
        }
        body{background:var(--page-bg);color:var(--text-color);font-family:<?php echo json_encode((string)$theme['font_family']); ?>,sans-serif}
        .sidebar{background:linear-gradient(180deg,var(--sidebar-gradient-1),var(--sidebar-gradient-2),var(--sidebar-gradient-3))!important}
        .stat-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:12px}
        .stat-card,.panel-card{background:var(--card-bg);border:1px solid var(--border-color);border-radius:var(--radius)}
        .stat-card{min-height:84px;padding:13px 15px;display:flex;align-items:center;gap:12px}
        .stat-icon{width:44px;height:44px;flex:0 0 44px;display:grid;place-items:center;border-radius:calc(var(--radius)*.8);background:var(--primary-soft);color:var(--primary-dark)}
        .stat-label{font-size:10px;color:var(--muted-color)}
        .stat-value{margin-top:2px;font-size:23px;line-height:1.1;font-weight:800}
        .page-head{padding:14px 16px;display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px}
        .page-title{margin:0;font-family:<?php echo json_encode((string)$theme['heading_font_family']); ?>,serif;font-size:20px;font-weight:800}
        .page-sub{margin-top:3px;color:var(--muted-color);font-size:10px}
        .head-actions{display:flex;flex-wrap:wrap;gap:8px}
        .btn-theme,.btn-soft{min-height:39px;border-radius:10px;font-size:11px;font-weight:700;white-space:nowrap;display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:9px 14px;text-decoration:none}
        .btn-theme{border:0;color:#fff;background:linear-gradient(135deg,var(--primary),var(--primary-dark))}
        .btn-theme:hover{color:#fff;filter:brightness(1.03)}
        .btn-soft{border:1px solid var(--border-color);background:var(--card-bg);color:var(--text-color)}
        .btn-soft:hover{color:var(--primary-dark);background:var(--primary-soft)}
        .import-layout{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(300px,.65fr);gap:12px;align-items:start}
        .panel-head{padding:12px 14px;border-bottom:1px solid var(--border-color);display:flex;align-items:center;justify-content:space-between;gap:10px}
        .panel-title{font-size:12px;font-weight:800}.panel-sub{margin-top:2px;color:var(--muted-color);font-size:9px}.panel-body{padding:14px}
        .drop-zone{position:relative;min-height:190px;border:2px dashed color-mix(in srgb,var(--primary) 38%,var(--border-color));border-radius:var(--radius);background:color-mix(in srgb,var(--primary-soft) 45%,var(--card-bg));display:flex;align-items:center;justify-content:center;text-align:center;padding:24px;transition:.2s}
        .drop-zone.dragover{border-color:var(--primary);background:var(--primary-soft);transform:translateY(-1px)}
        .drop-zone input{position:absolute;inset:0;width:100%;height:100%;opacity:0;cursor:pointer}
        .drop-icon{width:56px;height:56px;margin:0 auto 10px;border-radius:16px;display:grid;place-items:center;background:var(--card-bg);color:var(--primary-dark);font-size:22px;box-shadow:0 8px 24px rgba(0,0,0,.08)}
        .drop-title{font-size:13px;font-weight:800}.drop-text{margin-top:5px;color:var(--muted-color);font-size:10px}.file-name{margin-top:10px;color:var(--primary-dark);font-size:10px;font-weight:800;word-break:break-all}
        .option-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:12px}
        .field-label{display:block;margin-bottom:5px;color:var(--muted-color);font-size:9px;font-weight:700;text-transform:uppercase}
        .form-select,.form-control{min-height:39px;border:1px solid var(--border-color);border-radius:10px;background:var(--card-bg);color:var(--text-color);font-size:11px;box-shadow:none}
        .import-note{margin-top:12px;padding:10px 12px;border-radius:10px;background:var(--primary-soft);color:var(--primary-dark);font-size:9px;line-height:1.55}
        .submit-row{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:14px}
        .submit-help{font-size:9px;color:var(--muted-color)}
        .guide-list{display:grid;gap:8px}
        .guide-item{display:flex;gap:10px;padding:10px;border:1px solid var(--border-color);border-radius:10px}
        .guide-no{width:26px;height:26px;flex:0 0 26px;border-radius:8px;display:grid;place-items:center;background:var(--primary-soft);color:var(--primary-dark);font-size:10px;font-weight:800}
        .guide-title{font-size:10px;font-weight:800}.guide-text{margin-top:2px;font-size:9px;line-height:1.5;color:var(--muted-color)}
        .result-card{margin-bottom:12px;overflow:hidden}
        .result-head{padding:12px 14px;display:flex;align-items:center;justify-content:space-between;gap:10px;border-bottom:1px solid var(--border-color)}
        .result-success{border-left:4px solid #168449}.result-error{border-left:4px solid #c0392b}
        .result-message{font-size:11px;font-weight:800}.result-stats{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:8px;padding:12px}
        .result-stat{padding:9px;border:1px solid var(--border-color);border-radius:9px;text-align:center}.result-stat span{display:block;color:var(--muted-color);font-size:8px;text-transform:uppercase}.result-stat strong{display:block;margin-top:2px;font-size:16px}
        .error-table{margin:0;font-size:9px}.error-table th{padding:8px 10px;background:color-mix(in srgb,var(--muted-color) 6%,transparent);color:var(--muted-color);font-size:8px;text-transform:uppercase;border-color:var(--border-color)}.error-table td{padding:8px 10px;background:var(--card-bg)!important;color:var(--text-color);border-color:var(--border-color);vertical-align:top}
        .column-table{margin:0;font-size:9px}.column-table th{padding:8px 10px;color:var(--muted-color);font-size:8px;text-transform:uppercase;background:color-mix(in srgb,var(--muted-color) 6%,transparent);border-color:var(--border-color)}.column-table td{padding:8px 10px;border-color:var(--border-color);background:var(--card-bg)!important;color:var(--text-color)}
        .required-badge,.optional-badge{display:inline-flex;padding:3px 6px;border-radius:999px;font-size:8px;font-weight:700}.required-badge{background:#fdecec;color:#bd2d2d}.optional-badge{background:#eaf0ff;color:#3155a6}
        .loading-overlay{position:fixed;inset:0;z-index:30000;display:none;align-items:center;justify-content:center;background:rgba(15,21,27,.55);backdrop-filter:blur(3px)}.loading-overlay.show{display:flex}.loading-box{min-width:270px;padding:22px;border-radius:14px;background:var(--card-bg);text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.28)}.loading-box i{font-size:28px;color:var(--primary);margin-bottom:10px}.loading-title{font-size:13px;font-weight:800}.loading-text{margin-top:4px;color:var(--muted-color);font-size:9px}
        body.dark-mode,body[data-theme="dark"],html.dark-mode body,html[data-theme="dark"] body{--page-bg:#0f151b;--card-bg:#182129;--text-color:#f3f6f8;--muted-color:#9aa7b3;--border-color:#2c3944}
        @media(max-width:1100px){.import-layout{grid-template-columns:1fr}.result-stats{grid-template-columns:repeat(3,1fr)}}
        @media(max-width:767px){.content-wrap{padding-left:10px;padding-right:10px}.stat-grid{grid-template-columns:1fr 1fr}.page-head{align-items:flex-start;flex-direction:column}.option-grid{grid-template-columns:1fr}.submit-row{align-items:stretch;flex-direction:column}.submit-row .btn-theme{width:100%}.result-stats{grid-template-columns:1fr 1fr}.head-actions{width:100%}.head-actions .btn-soft{flex:1}.drop-zone{min-height:165px}}
    </style>
</head>
<body>
<?php include('includes/sidebar.php'); ?>
<main class="app-main">
    <?php include('includes/nav.php'); ?>
    <div class="content-wrap">
        <section class="panel-card page-head">
            <div>
                <h1 class="page-title">Import Products</h1>
                <div class="page-sub">Bulk-create jewellery products from CSV or Excel with category, metal, unit and opening stock validation.</div>
            </div>
            <div class="head-actions">
                <a href="import-products.php?download_sample=1" class="btn-soft"><i class="fa-solid fa-file-arrow-down"></i>Sample CSV</a>
                <a href="products.php" class="btn-soft"><i class="fa-solid fa-gem"></i>Product List</a>
            </div>
        </section>

        <div class="stat-grid">
            <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-gem"></i></div><div><div class="stat-label">Current Products</div><div class="stat-value"><?php echo number_format($stats['products']); ?></div></div></div>
            <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-tags"></i></div><div><div class="stat-label">Active Categories</div><div class="stat-value"><?php echo number_format($stats['categories']); ?></div></div></div>
            <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-coins"></i></div><div><div class="stat-label">Active Metals</div><div class="stat-value"><?php echo number_format($stats['metals']); ?></div></div></div>
            <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-scale-balanced"></i></div><div><div class="stat-label">Active Units</div><div class="stat-value"><?php echo number_format($stats['units']); ?></div></div></div>
        </div>

        <?php if (is_array($importResult)): ?>
            <section class="panel-card result-card <?php echo !empty($importResult['success']) ? 'result-success' : 'result-error'; ?>">
                <div class="result-head">
                    <div class="result-message">
                        <i class="fa-solid <?php echo !empty($importResult['success']) ? 'fa-circle-check' : 'fa-circle-exclamation'; ?> me-1"></i>
                        <?php echo e($importResult['message'] ?? 'Import completed.'); ?>
                    </div>
                    <?php if (!empty($importResult['errors'])): ?>
                        <a href="import-products.php?download_errors=1" class="btn-soft"><i class="fa-solid fa-download"></i>Error CSV</a>
                    <?php endif; ?>
                </div>
                <div class="result-stats">
                    <?php foreach ([
                        'total' => 'Total Rows',
                        'created' => 'Created',
                        'updated' => 'Updated',
                        'skipped' => 'Skipped',
                        'failed' => 'Failed',
                        'stock_updated' => 'Stock Updated'
                    ] as $key => $label): ?>
                        <div class="result-stat"><span><?php echo e($label); ?></span><strong><?php echo number_format((int)($importResult[$key] ?? 0)); ?></strong></div>
                    <?php endforeach; ?>
                </div>
                <?php if (!empty($importResult['errors'])): ?>
                    <div class="table-responsive">
                        <table class="table error-table">
                            <thead><tr><th>Row</th><th>Product Code</th><th>Product Name</th><th>Error</th></tr></thead>
                            <tbody>
                            <?php foreach (array_slice($importResult['errors'], 0, 20) as $error): ?>
                                <tr>
                                    <td><?php echo e($error['row'] ?? ''); ?></td>
                                    <td><?php echo e($error['product_code'] ?? ''); ?></td>
                                    <td><?php echo e($error['product_name'] ?? ''); ?></td>
                                    <td><?php echo e($error['error'] ?? ''); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <div class="import-layout">
            <section class="panel-card">
                <div class="panel-head">
                    <div><div class="panel-title">Upload Product File</div><div class="panel-sub">CSV works immediately. XLSX/XLS requires PhpSpreadsheet.</div></div>
                </div>
                <div class="panel-body">
                    <form method="post" enctype="multipart/form-data" id="importForm" autocomplete="off">
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                        <label class="drop-zone" id="dropZone">
                            <input type="file" name="product_file" id="productFile" accept=".csv,.xlsx,.xls,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel" required>
                            <div>
                                <div class="drop-icon"><i class="fa-solid fa-cloud-arrow-up"></i></div>
                                <div class="drop-title">Drop the product file here</div>
                                <div class="drop-text">or click to select CSV, XLSX or XLS. Maximum file size: 8 MB.</div>
                                <div class="file-name" id="fileName">No file selected</div>
                            </div>
                        </label>

                        <div class="option-grid">
                            <div>
                                <label class="field-label" for="duplicateMode">Existing Products</label>
                                <select class="form-select" name="duplicate_mode" id="duplicateMode">
                                    <option value="skip">Skip existing products</option>
                                    <?php if ($canUpdate): ?><option value="update">Update existing products</option><?php endif; ?>
                                </select>
                            </div>
                            <div>
                                <label class="field-label" for="stockMode">Opening Stock</label>
                                <select class="form-select" name="stock_mode" id="stockMode">
                                    <option value="add">Add to current stock</option>
                                    <option value="replace">Replace current stock</option>
                                    <option value="ignore">Do not import stock</option>
                                </select>
                            </div>
                        </div>

                        <div class="import-note">
                            <strong>Master matching:</strong> Category, metal and unit values may use their ID, name or code. Category and unit are required and must already exist. Product code is generated automatically when blank. Existing products are detected using product code first and barcode second.
                        </div>

                        <div class="submit-row">
                            <div class="submit-help">The page imports up to 2,000 rows and reports invalid rows separately.</div>
                            <button type="button" class="btn-soft" id="previewButton"><i class="fa-solid fa-eye"></i>Preview & Validate</button><button type="submit" class="btn-theme" id="importButton" disabled><i class="fa-solid fa-file-import"></i>Import Products</button>
                        </div>
                    </form>
                </div>
            </section>

            <aside class="panel-card">
                <div class="panel-head"><div><div class="panel-title">Import Checklist</div><div class="panel-sub">Complete these before importing.</div></div></div>
                <div class="panel-body">
                    <div class="guide-list">
                        <div class="guide-item"><div class="guide-no">1</div><div><div class="guide-title">Download the sample</div><div class="guide-text">Use the provided column names to avoid mapping errors.</div></div></div>
                        <div class="guide-item"><div class="guide-no">2</div><div><div class="guide-title">Verify master names</div><div class="guide-text">Category, metal and unit names or codes must match the active masters.</div></div></div>
                        <div class="guide-item"><div class="guide-no">3</div><div><div class="guide-title">Choose duplicate handling</div><div class="guide-text">Skip keeps existing data unchanged. Update overwrites the supported product fields.</div></div></div>
                        <div class="guide-item"><div class="guide-no">4</div><div><div class="guide-title">Review import results</div><div class="guide-text">Download the error CSV, correct the rejected rows and import them again.</div></div></div>
                    </div>
                </div>
            </aside>
        </div>

        <section class="panel-card mt-3">
            <div class="panel-head"><div><div class="panel-title">Supported Columns</div><div class="panel-sub">Alternative headers such as item_name, sku_code and gst_percent are also recognized.</div></div></div>
            <div class="table-responsive">
                <table class="table column-table">
                    <thead><tr><th>Column</th><th>Required</th><th>Description</th><th>Example</th></tr></thead>
                    <tbody>
                    <?php
                    $columnGuide = [
                        ['product_name', true, 'Product or jewellery item name.', 'Gold Chain'],
                        ['product_code', false, 'Unique product code. Generated when blank.', 'PRD000001'],
                        ['barcode', false, 'Unique barcode for the business.', '890000000001'],
                        ['category', true, 'Existing category ID, name or code.', 'Chain'],
                        ['metal', false, 'Existing metal ID, name or code.', 'Gold'],
                        ['unit', true, 'Existing unit ID, name or code.', 'Piece'],
                        ['hsn_code / purity', false, 'HSN code and numeric purity or karat.', '7113 / 22'],
                        ['gross_weight / stone_weight / net_weight', false, 'Jewellery weight values. Net weight is calculated when blank.', '12 / 0 / 12'],
                        ['making_charge_type / making_charge', false, 'Per Gram, Fixed or Percentage and amount.', 'Per Gram / 250'],
                        ['purchase_rate / sale_rate / tax_percent', false, 'Product rates and GST percentage.', '7200 / 7600 / 3'],
                        ['opening_quantity / opening_gross_weight / opening_net_weight', false, 'Opening stock for the logged-in branch.', '1 / 12 / 12'],
                        ['track_stock / is_active', false, 'Use 1/0, yes/no or active/inactive.', '1 / 1'],
                    ];
                    foreach ($columnGuide as $guide): ?>
                        <tr>
                            <td><code><?php echo e($guide[0]); ?></code></td>
                            <td><span class="<?php echo $guide[1] ? 'required-badge' : 'optional-badge'; ?>"><?php echo $guide[1] ? 'Required' : 'Optional'; ?></span></td>
                            <td><?php echo e($guide[2]); ?></td>
                            <td><?php echo e($guide[3]); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <?php include('includes/footer.php'); ?>
    </div>
</main>


<div class="modal fade" id="importPreviewModal" tabindex="-1">
<div class="modal-dialog modal-xl modal-dialog-centered">
<div class="modal-content">
<div class="modal-header">
<h5 class="modal-title">Import Preview & Validation</h5>
<button class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<div id="previewSummary" class="mb-3"></div>
<div class="table-responsive">
<table class="table">
<thead>
<tr>
<th>Row</th>
<th>Product</th>
<th>Category</th>
<th>Metal</th>
<th>Unit</th>
<th>Status</th>
<th>Error</th>
</tr>
</thead>
<tbody id="previewBody"></tbody>
</table>
</div>
</div>
<div class="modal-footer">
<button class="btn-soft" data-bs-dismiss="modal">Cancel</button>
<button class="btn-theme" id="allowImport" disabled>Allow Import</button>
</div>
</div>
</div>
</div>

<div class="loading-overlay" id="loadingOverlay">
    <div class="loading-box">
        <i class="fa-solid fa-spinner fa-spin"></i>
        <div class="loading-title">Importing products...</div>
        <div class="loading-text">Keep this page open until the import is complete.</div>
    </div>
</div>

<?php include('includes/script.php'); ?>
<script src="assets/js/script.js"></script>
<script>
(function(){
    'use strict';
    const form=document.getElementById('importForm');
    const fileInput=document.getElementById('productFile');
    const fileName=document.getElementById('fileName');
    const dropZone=document.getElementById('dropZone');
    const overlay=document.getElementById('loadingOverlay');
    const button=document.getElementById('importButton');

    function updateFileName(){
        const file=fileInput.files&&fileInput.files[0];
        fileName.textContent=file?file.name+' ('+(file.size/1024/1024).toFixed(2)+' MB)':'No file selected';
    }

    fileInput.addEventListener('change',updateFileName);
    ['dragenter','dragover'].forEach(function(name){
        dropZone.addEventListener(name,function(event){event.preventDefault();dropZone.classList.add('dragover');});
    });
    ['dragleave','drop'].forEach(function(name){
        dropZone.addEventListener(name,function(event){event.preventDefault();dropZone.classList.remove('dragover');});
    });
    dropZone.addEventListener('drop',function(event){
        if(event.dataTransfer&&event.dataTransfer.files.length){
            fileInput.files=event.dataTransfer.files;
            updateFileName();
        }
    });


    const previewButton=document.getElementById('previewButton');
    const allowImport=document.getElementById('allowImport');
    const previewModal=bootstrap.Modal.getOrCreateInstance(document.getElementById('importPreviewModal'));
    const previewBody=document.getElementById('previewBody');
    const previewSummary=document.getElementById('previewSummary');

    previewButton.addEventListener('click',async function(){

        if(!fileInput.files.length){
            alert('Select a product file first.');
            return;
        }

        const data=new FormData();
        data.append('preview_import','1');
        data.append('csrf_token',document.querySelector('[name=csrf_token]').value);
        data.append('product_file',fileInput.files[0]);

        previewButton.disabled=true;
        previewButton.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i>Checking...';

        try{
            const response=await fetch(location.href,{
                method:'POST',
                body:data
            });

            const result=await response.json();

            if(!result.success){
                throw new Error(result.message);
            }

            previewBody.innerHTML='';

            result.preview.forEach(row=>{
                previewBody.innerHTML += `
                <tr>
                    <td>${row.row}</td>
                    <td>${row.product_name}</td>
                    <td>${row.category}</td>
                    <td>${row.metal}</td>
                    <td>${row.unit}</td>
                    <td>${row.status}</td>
                    <td>${row.errors}</td>
                </tr>`;
            });

            previewSummary.innerHTML =
                '<strong>Total Rows:</strong> '+result.total+
                ' | <strong>Validation Errors:</strong> '+result.errors;

            allowImport.disabled=!result.can_import;
            previewModal.show();

        }catch(error){
            alert(error.message);
        }finally{
            previewButton.disabled=false;
            previewButton.innerHTML='<i class="fa-solid fa-eye"></i>Preview & Validate';
        }
    });

    allowImport.addEventListener('click',function(){
        document.getElementById('importButton').disabled=false;
        previewModal.hide();
    });

    form.addEventListener('submit',function(event){
        if(!fileInput.files||!fileInput.files.length){
            event.preventDefault();
            alert('Select a product import file.');
            return;
        }
        const file=fileInput.files[0];
        if(file.size>8*1024*1024){
            event.preventDefault();
            alert('The import file must be below 8 MB.');
            return;
        }
        overlay.classList.add('show');
        button.disabled=true;
        button.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i>Importing...';
    });
})();
</script>
</body>
</html>