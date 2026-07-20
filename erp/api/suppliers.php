<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));
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

function respond(bool $success, string $message, array $extra = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode(
        array_merge(['success'=>$success,'message'=>$message],$extra),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function prepareOrFail(mysqli $conn, string $sql, string $label): mysqli_stmt
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($label . ': ' . $conn->error);
    }
    return $stmt;
}

function tableExists(mysqli $conn, string $table): bool
{
    $safe = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $result && $result->num_rows > 0;
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
    empty($_SESSION['suppliers_csrf']) ||
    !hash_equals(
        (string)$_SESSION['suppliers_csrf'],
        (string)($_POST['csrf_token'] ?? '')
    )
) {
    respond(false, 'Invalid or expired security token.', [], 419);
}

$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? ($_SESSION['default_branch_id'] ?? 0));
$userId = (int)($_SESSION['user_id'] ?? 0);

if ($businessId <= 0) {
    respond(false, 'A valid business must be selected.', [], 403);
}

if (!tableExists($conn, 'suppliers')) {
    respond(false, 'Suppliers table is missing. Run suppliers-migration.sql first.', [], 500);
}

$action = (string)($_POST['action'] ?? '');

try {
    if ($action === 'list') {
        $page = max(1, (int)($_POST['page'] ?? 1));
        $perPage = max(5, min(100, (int)($_POST['per_page'] ?? 10)));
        $offset = ($page - 1) * $perPage;

        $status = trim((string)($_POST['status'] ?? ''));
        $supplierType = trim((string)($_POST['supplier_type'] ?? ''));
        $search = trim((string)($_POST['search'] ?? ''));

        $where = ' WHERE business_id=? ';
        $types = 'i';
        $params = [$businessId];

        if ($status !== '') {
            $where .= ' AND is_active=? ';
            $types .= 'i';
            $params[] = (int)$status;
        }

        if ($supplierType !== '') {
            $where .= ' AND supplier_type=? ';
            $types .= 's';
            $params[] = $supplierType;
        }

        if ($search !== '') {
            $like = '%' . $search . '%';
            $where .= ' AND (
                supplier_name LIKE ?
                OR supplier_code LIKE ?
                OR mobile LIKE ?
                OR alternate_mobile LIKE ?
                OR email LIKE ?
                OR gstin LIKE ?
                OR contact_person LIKE ?
            ) ';
            $types .= 'sssssss';
            array_push($params, $like, $like, $like, $like, $like, $like, $like);
        }

        $countStmt = prepareOrFail(
            $conn,
            "SELECT COUNT(*) total FROM suppliers {$where}",
            'Unable to prepare supplier count'
        );

        $bind = [$types];
        foreach ($params as $index => $value) {
            $bind[] =& $params[$index];
        }
        call_user_func_array([$countStmt, 'bind_param'], $bind);

        $countStmt->execute();
        $total = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
        $countStmt->close();

        $listTypes = $types . 'ii';
        $listParams = $params;
        $listParams[] = $perPage;
        $listParams[] = $offset;

        $listStmt = prepareOrFail(
            $conn,
            "SELECT *
             FROM suppliers
             {$where}
             ORDER BY supplier_name ASC,id DESC
             LIMIT ? OFFSET ?",
            'Unable to prepare supplier list'
        );

        $bind = [$listTypes];
        foreach ($listParams as $index => $value) {
            $bind[] =& $listParams[$index];
        }
        call_user_func_array([$listStmt, 'bind_param'], $bind);

        $listStmt->execute();
        $result = $listStmt->get_result();
        $suppliers = [];

        while ($row = $result->fetch_assoc()) {
            $suppliers[] = $row;
        }
        $listStmt->close();

        $statsStmt = prepareOrFail(
            $conn,
            "SELECT
                COUNT(*) total_suppliers,
                SUM(CASE WHEN is_active=1 THEN 1 ELSE 0 END) active_suppliers,
                COALESCE(SUM(opening_balance),0) opening_balance,
                COALESCE(SUM(credit_limit),0) credit_limit
             FROM suppliers
             WHERE business_id=?",
            'Unable to prepare supplier stats'
        );
        $statsStmt->bind_param('i', $businessId);
        $statsStmt->execute();
        $stats = $statsStmt->get_result()->fetch_assoc() ?: [];
        $statsStmt->close();

        $totalPages = max(1, (int)ceil($total / $perPage));

        respond(true, 'Suppliers loaded.', [
            'suppliers' => $suppliers,
            'stats' => [
                'total_suppliers' => (int)($stats['total_suppliers'] ?? 0),
                'active_suppliers' => (int)($stats['active_suppliers'] ?? 0),
                'opening_balance' => (float)($stats['opening_balance'] ?? 0),
                'credit_limit' => (float)($stats['credit_limit'] ?? 0)
            ],
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
                'from' => $total > 0 ? $offset + 1 : 0,
                'to' => min($offset + $perPage, $total)
            ]
        ]);
    }

    if ($action === 'view') {
        $supplierId = (int)($_POST['supplier_id'] ?? 0);

        $stmt = prepareOrFail(
            $conn,
            'SELECT * FROM suppliers WHERE id=? AND business_id=? LIMIT 1',
            'Unable to prepare supplier view'
        );
        $stmt->bind_param('ii', $supplierId, $businessId);
        $stmt->execute();
        $supplier = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$supplier) {
            throw new RuntimeException('Supplier not found.');
        }

        respond(true, 'Supplier loaded.', ['supplier'=>$supplier]);
    }

    if ($action === 'save') {
        $supplierId = (int)($_POST['supplier_id'] ?? 0);
        $supplierCode = strtoupper(trim((string)($_POST['supplier_code'] ?? '')));
        $supplierName = trim((string)($_POST['supplier_name'] ?? ''));
        $supplierType = trim((string)($_POST['supplier_type'] ?? 'General'));
        $contactPerson = trim((string)($_POST['contact_person'] ?? ''));
        $mobile = trim((string)($_POST['mobile'] ?? ''));
        $alternateMobile = trim((string)($_POST['alternate_mobile'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $gstin = strtoupper(trim((string)($_POST['gstin'] ?? '')));
        $panNo = strtoupper(trim((string)($_POST['pan_no'] ?? '')));
        $openingBalance = round((float)($_POST['opening_balance'] ?? 0), 2);
        $creditLimit = max(0, round((float)($_POST['credit_limit'] ?? 0), 2));
        $creditDays = max(0, (int)($_POST['credit_days'] ?? 0));
        $addressLine1 = trim((string)($_POST['address_line1'] ?? ''));
        $city = trim((string)($_POST['city'] ?? ''));
        $state = trim((string)($_POST['state'] ?? ''));
        $pincode = trim((string)($_POST['pincode'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));
        $isActive = (int)($_POST['is_active'] ?? 1) === 1 ? 1 : 0;

        if ($supplierName === '') {
            throw new RuntimeException('Supplier name is required.');
        }

        if ($mobile === '') {
            throw new RuntimeException('Supplier mobile number is required.');
        }

        $validTypes = ['Gold','Silver','Diamond','Stone','Packaging','Service','General'];
        if (!in_array($supplierType, $validTypes, true)) {
            $supplierType = 'General';
        }

        $duplicate = prepareOrFail(
            $conn,
            'SELECT id FROM suppliers
             WHERE business_id=?
               AND mobile=?
               AND id<>?
             LIMIT 1',
            'Unable to validate supplier'
        );
        $duplicate->bind_param('isi', $businessId, $mobile, $supplierId);
        $duplicate->execute();
        $duplicateRow = $duplicate->get_result()->fetch_assoc();
        $duplicate->close();

        if ($duplicateRow) {
            throw new RuntimeException('Another supplier already uses this mobile number.');
        }

        if ($supplierCode === '') {
            $prefix = 'SUP' . date('ym');

            $codeStmt = prepareOrFail(
                $conn,
                "SELECT supplier_code
                 FROM suppliers
                 WHERE business_id=?
                   AND supplier_code LIKE ?
                 ORDER BY id DESC
                 LIMIT 1",
                'Unable to generate supplier code'
            );
            $like = $prefix . '%';
            $codeStmt->bind_param('is', $businessId, $like);
            $codeStmt->execute();
            $last = $codeStmt->get_result()->fetch_assoc();
            $codeStmt->close();

            $sequence = 1;
            if ($last && preg_match('/(\d{4})$/', (string)$last['supplier_code'], $match)) {
                $sequence = (int)$match[1] + 1;
            }

            $supplierCode = $prefix . str_pad((string)$sequence, 4, '0', STR_PAD_LEFT);
        }

        if ($supplierId > 0) {
            $stmt = prepareOrFail(
                $conn,
                "UPDATE suppliers SET
                    supplier_code=?,
                    supplier_name=?,
                    supplier_type=?,
                    contact_person=?,
                    mobile=?,
                    alternate_mobile=?,
                    email=?,
                    gstin=?,
                    pan_no=?,
                    opening_balance=?,
                    credit_limit=?,
                    credit_days=?,
                    address_line1=?,
                    city=?,
                    state=?,
                    pincode=?,
                    notes=?,
                    is_active=?,
                    updated_by=?,
                    updated_at=CURRENT_TIMESTAMP
                 WHERE id=?
                   AND business_id=?
                 LIMIT 1",
                'Unable to prepare supplier update'
            );

            $stmt->bind_param(
                'sssssssssddisssssiiii',
                $supplierCode,
                $supplierName,
                $supplierType,
                $contactPerson,
                $mobile,
                $alternateMobile,
                $email,
                $gstin,
                $panNo,
                $openingBalance,
                $creditLimit,
                $creditDays,
                $addressLine1,
                $city,
                $state,
                $pincode,
                $notes,
                $isActive,
                $userId,
                $supplierId,
                $businessId
            );

            if (!$stmt->execute()) {
                throw new RuntimeException('Unable to update supplier: ' . $stmt->error);
            }

            $stmt->close();
            respond(true, 'Supplier updated successfully.', ['supplier_id'=>$supplierId]);
        }

        $stmt = prepareOrFail(
            $conn,
            "INSERT INTO suppliers
                (business_id,branch_id,supplier_code,supplier_name,supplier_type,
                 contact_person,mobile,alternate_mobile,email,gstin,pan_no,
                 opening_balance,credit_limit,credit_days,address_line1,city,state,
                 pincode,notes,is_active,created_by,updated_by)
             VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
            'Unable to prepare supplier insert'
        );

        $stmt->bind_param(
            'iisssssssssddisssssiii',
            $businessId,
            $branchId,
            $supplierCode,
            $supplierName,
            $supplierType,
            $contactPerson,
            $mobile,
            $alternateMobile,
            $email,
            $gstin,
            $panNo,
            $openingBalance,
            $creditLimit,
            $creditDays,
            $addressLine1,
            $city,
            $state,
            $pincode,
            $notes,
            $isActive,
            $userId,
            $userId
        );

        if (!$stmt->execute()) {
            throw new RuntimeException('Unable to create supplier: ' . $stmt->error);
        }

        $newId = (int)$stmt->insert_id;
        $stmt->close();

        respond(true, 'Supplier created successfully.', ['supplier_id'=>$newId]);
    }

    if ($action === 'toggle') {
        $supplierId = (int)($_POST['supplier_id'] ?? 0);

        $stmt = prepareOrFail(
            $conn,
            'UPDATE suppliers
             SET is_active=IF(is_active=1,0,1),
                 updated_by=?,
                 updated_at=CURRENT_TIMESTAMP
             WHERE id=?
               AND business_id=?
             LIMIT 1',
            'Unable to prepare supplier status update'
        );
        $stmt->bind_param('iii', $userId, $supplierId, $businessId);

        if (!$stmt->execute()) {
            throw new RuntimeException('Unable to update supplier status: ' . $stmt->error);
        }

        $stmt->close();
        respond(true, 'Supplier status changed successfully.');
    }

    if ($action === 'delete') {
        $supplierId = (int)($_POST['supplier_id'] ?? 0);

        foreach ([
            ['purchase_invoices','supplier_id'],
            ['purchases','supplier_id'],
            ['supplier_payments','supplier_id']
        ] as $relation) {
            [$table, $column] = $relation;

            if (!tableExists($conn, $table)) {
                continue;
            }

            $stmt = prepareOrFail(
                $conn,
                "SELECT COUNT(*) total FROM `{$table}` WHERE `{$column}`=? LIMIT 1",
                'Unable to validate supplier usage'
            );
            $stmt->bind_param('i', $supplierId);
            $stmt->execute();
            $used = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
            $stmt->close();

            if ($used > 0) {
                throw new RuntimeException(
                    'This supplier is already used in transactions. Deactivate it instead.'
                );
            }
        }

        $stmt = prepareOrFail(
            $conn,
            'DELETE FROM suppliers WHERE id=? AND business_id=? LIMIT 1',
            'Unable to prepare supplier deletion'
        );
        $stmt->bind_param('ii', $supplierId, $businessId);

        if (!$stmt->execute()) {
            throw new RuntimeException('Unable to delete supplier: ' . $stmt->error);
        }

        $stmt->close();
        respond(true, 'Supplier deleted successfully.');
    }

    respond(false, 'Invalid action.', [], 400);
} catch (Throwable $error) {
    respond(false, $error->getMessage(), [], 422);
}
