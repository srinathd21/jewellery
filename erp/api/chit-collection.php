<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));
header('Content-Type: application/json; charset=utf-8');

function respond(bool $success, string $message, array $extra = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode(
        array_merge(['success' => $success, 'message' => $message], $extra),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

$rootDir = dirname(__DIR__);
$configCandidates = [
    $rootDir . '/config/config.php',
    $rootDir . '/config.php',
    $rootDir . '/includes/config.php',
    $rootDir . '/super-admin/includes/config.php',
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
    respond(false, 'Database configuration is not available.', [], 500);
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn->set_charset('utf8mb4');

function tableExists(mysqli $conn, string $table): bool
{
    $table = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$table}'");
    return $result && $result->num_rows > 0;
}

function hasColumn(mysqli $conn, string $table, string $column): bool
{
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    return $result && $result->num_rows > 0;
}

function ensureReceiverColumn(mysqli $conn): void
{
    if (hasColumn($conn, 'chit_collections', 'collection_receiver_name')) {
        return;
    }

    $sql = "ALTER TABLE chit_collections
            ADD COLUMN collection_receiver_name VARCHAR(150) NULL
            AFTER remarks";

    if (!$conn->query($sql)) {
        throw new RuntimeException(
            'Unable to add collection receiver name column: ' . $conn->error
        );
    }
}

function hasPermission(mysqli $conn, string $action): bool
{
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin') {
        return true;
    }

    $map = [
        'open' => 'can_open',
        'view' => 'can_view',
        'create' => 'can_create',
    ];

    $field = $map[$action] ?? '';
    if ($field === '') {
        return false;
    }

    $permissions = $_SESSION['permissions'] ?? [];
    foreach (['perm.chit-collection', 'perm.chit'] as $key) {
        if (isset($permissions[$key][$field])) {
            return (int)$permissions[$key][$field] === 1;
        }
    }

    $roleName = strtolower(trim((string)($_SESSION['role_name'] ?? '')));
    $roleCode = strtolower(trim((string)($_SESSION['role_code'] ?? '')));

    return in_array($roleName, ['super admin', 'admin', 'manager', 'billing'], true)
        || in_array($roleCode, ['super_admin', 'admin', 'manager', 'billing'], true);
}

function validDate(string $date): bool
{
    $parsed = DateTime::createFromFormat('Y-m-d', $date);
    return $parsed && $parsed->format('Y-m-d') === $date;
}

function nextReceiptNo(mysqli $conn, int $businessId, int $branchId): string
{
    $prefix = 'CR' . date('Ym');
    $like = $prefix . '%';

    $stmt = $conn->prepare(
        "SELECT receipt_no
         FROM chit_collections
         WHERE business_id = ?
           AND branch_id = ?
           AND receipt_no LIKE ?
         ORDER BY id DESC
         LIMIT 1"
    );

    if (!$stmt) {
        return $prefix . '0001';
    }

    $stmt->bind_param('iis', $businessId, $branchId, $like);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $next = 1;
    if ($row && preg_match('/(\d+)$/', (string)$row['receipt_no'], $match)) {
        $next = (int)$match[1] + 1;
    }

    return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

if (empty($_SESSION['user_id'])) {
    respond(false, 'Your session has expired. Please log in again.', [], 401);
}

$userId = (int)$_SESSION['user_id'];
$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? $_SESSION['default_branch_id'] ?? 0);

if ($businessId <= 0 || $branchId <= 0) {
    respond(false, 'A valid business and branch must be selected.', [], 403);
}

if (!hasPermission($conn, 'open')) {
    respond(false, 'You do not have permission to open chit collection.', [], 403);
}

foreach (
    ['chit_members', 'chit_groups', 'chit_installments', 'chit_collections', 'customers', 'payment_methods']
    as $requiredTable
) {
    if (!tableExists($conn, $requiredTable)) {
        respond(false, "Required table `{$requiredTable}` was not found.", [], 500);
    }
}

$action = strtolower(trim((string)($_REQUEST['action'] ?? 'bootstrap')));

if ($action === 'bootstrap') {
    $members = [];

    /*
     * Important:
     * chit_members has no branch_id. Branch belongs to chit_groups.
     * Therefore members must be filtered through g.branch_id, not m.branch_id.
     */
    $stmt = $conn->prepare(
        "SELECT
            m.id,
            m.ticket_no,
            m.status,
            m.chit_group_id,
            m.customer_id,
            c.customer_code,
            c.customer_name,
            c.mobile,
            g.group_no,
            g.group_name,
            g.installment_amount
         FROM chit_members m
         INNER JOIN chit_groups g
            ON g.id = m.chit_group_id
           AND g.business_id = m.business_id
         INNER JOIN customers c
            ON c.id = m.customer_id
           AND c.business_id = m.business_id
         WHERE m.business_id = ?
           AND g.branch_id = ?
           AND m.status = 'Active'
           AND g.status = 'Active'
         ORDER BY c.customer_name, g.group_name, m.ticket_no"
    );

    if (!$stmt) {
        respond(false, 'Unable to prepare member query: ' . $conn->error, [], 500);
    }

    $stmt->bind_param('ii', $businessId, $branchId);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        respond(false, 'Unable to load chit members: ' . $error, [], 500);
    }

    $result = $stmt->get_result();
    while ($result && $row = $result->fetch_assoc()) {
        $row['id'] = (int)$row['id'];
        $row['chit_group_id'] = (int)$row['chit_group_id'];
        $row['customer_id'] = (int)$row['customer_id'];
        $row['installment_amount'] = (float)$row['installment_amount'];
        $members[] = $row;
    }
    $stmt->close();

    $paymentMethods = [];

    /*
     * Payment Methods compatibility:
     *
     * Jewellery schema:
     *   id, method_name, is_active
     *
     * New Payment Methods schema:
     *   payment_method_id, payment_method_name, status
     */
    $paymentIdColumn = hasColumn($conn, 'payment_methods', 'payment_method_id')
        ? 'payment_method_id'
        : (hasColumn($conn, 'payment_methods', 'id') ? 'id' : '');

    $paymentNameColumn = hasColumn($conn, 'payment_methods', 'payment_method_name')
        ? 'payment_method_name'
        : (hasColumn($conn, 'payment_methods', 'method_name') ? 'method_name' : '');

    $paymentStatusColumn = hasColumn($conn, 'payment_methods', 'status')
        ? 'status'
        : (hasColumn($conn, 'payment_methods', 'is_active') ? 'is_active' : '');

    $hasPaymentBusinessId = hasColumn(
        $conn,
        'payment_methods',
        'business_id'
    );

    if ($paymentIdColumn === '' || $paymentNameColumn === '') {
        respond(
            false,
            'The payment_methods table does not contain a supported ID or name column.',
            [],
            500
        );
    }

    $basePaymentSql = "SELECT
            `{$paymentIdColumn}` AS id,
            `{$paymentIdColumn}` AS payment_method_id,
            `{$paymentNameColumn}` AS method_name,
            `{$paymentNameColumn}` AS payment_method_name
        FROM payment_methods
        WHERE 1 = 1";

    if ($paymentStatusColumn !== '') {
        $basePaymentSql .= " AND COALESCE(`{$paymentStatusColumn}`, 1) = 1";
    }

    /*
     * First preference: methods belonging to the logged-in business.
     * Compatibility fallback: when that business has no methods, use all
     * active methods already available in the shared payment_methods table.
     */
    if ($hasPaymentBusinessId) {
        $businessPaymentSql =
            $basePaymentSql .
            " AND business_id = ? ORDER BY `{$paymentNameColumn}` ASC";

        $stmt = $conn->prepare($businessPaymentSql);

        if (!$stmt) {
            respond(
                false,
                'Unable to prepare payment-method query: ' . $conn->error,
                [],
                500
            );
        }

        $stmt->bind_param('i', $businessId);

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();

            respond(
                false,
                'Unable to load payment methods: ' . $error,
                [],
                500
            );
        }

        $result = $stmt->get_result();

        while ($result && $row = $result->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $row['payment_method_id'] = (int)$row['payment_method_id'];
            $paymentMethods[] = $row;
        }

        $stmt->close();
    }

    if (!$paymentMethods) {
        $allPaymentSql =
            $basePaymentSql .
            " ORDER BY `{$paymentNameColumn}` ASC";

        $stmt = $conn->prepare($allPaymentSql);

        if (!$stmt) {
            respond(
                false,
                'Unable to prepare fallback payment-method query: ' .
                $conn->error,
                [],
                500
            );
        }

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();

            respond(
                false,
                'Unable to load fallback payment methods: ' . $error,
                [],
                500
            );
        }

        $result = $stmt->get_result();

        while ($result && $row = $result->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $row['payment_method_id'] =
                (int)$row['payment_method_id'];
            $paymentMethods[] = $row;
        }

        $stmt->close();
    }

    respond(true, 'Collection data loaded successfully.', [
        'members' => $members,
        'payment_methods' => $paymentMethods,
        'methods' => $paymentMethods,
    ]);
}

if ($action === 'installments') {
    $memberId = (int)($_GET['member_id'] ?? 0);

    if ($memberId <= 0) {
        respond(false, 'Please select a valid chit member.', [], 422);
    }

    $stmt = $conn->prepare(
        "SELECT
            m.id,
            m.chit_group_id,
            g.installment_amount
         FROM chit_members m
         INNER JOIN chit_groups g
            ON g.id = m.chit_group_id
           AND g.business_id = m.business_id
         WHERE m.id = ?
           AND m.business_id = ?
           AND g.branch_id = ?
           AND m.status = 'Active'
           AND g.status = 'Active'
         LIMIT 1"
    );

    if (!$stmt) {
        respond(false, 'Unable to validate selected member: ' . $conn->error, [], 500);
    }

    $stmt->bind_param('iii', $memberId, $businessId, $branchId);
    $stmt->execute();
    $member = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$member) {
        respond(false, 'The selected member is not active in this branch.', [], 404);
    }

    $stmt = $conn->prepare(
        "SELECT
            i.id,
            i.installment_no,
            i.due_date,
            g.installment_amount,
            COALESCE(SUM(
                CASE
                    WHEN cc.id IS NOT NULL
                    THEN cc.paid_amount + cc.discount_amount
                    ELSE 0
                END
            ), 0) AS settled_amount
         FROM chit_installments i
         INNER JOIN chit_groups g
            ON g.id = i.chit_group_id
           AND g.business_id = i.business_id
         LEFT JOIN chit_collections cc
            ON cc.chit_installment_id = i.id
           AND cc.chit_member_id = ?
           AND cc.business_id = i.business_id
           AND cc.branch_id = ?
         WHERE i.business_id = ?
           AND i.chit_group_id = ?
           AND i.status = 'Open'
         GROUP BY
            i.id,
            i.installment_no,
            i.due_date,
            g.installment_amount
         HAVING (g.installment_amount - settled_amount) > 0
         ORDER BY i.installment_no"
    );

    if (!$stmt) {
        respond(false, 'Unable to prepare installment query: ' . $conn->error, [], 500);
    }

    $groupId = (int)$member['chit_group_id'];
    $stmt->bind_param('iiii', $memberId, $branchId, $businessId, $groupId);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        respond(false, 'Unable to load installments: ' . $error, [], 500);
    }

    $result = $stmt->get_result();
    $installments = [];

    while ($result && $row = $result->fetch_assoc()) {
        $row['id'] = (int)$row['id'];
        $row['installment_no'] = (int)$row['installment_no'];
        $row['installment_amount'] = (float)$row['installment_amount'];
        $row['settled_amount'] = (float)$row['settled_amount'];
        $row['pending_amount'] = max(
            0,
            (float)$row['installment_amount'] - (float)$row['settled_amount']
        );
        $row['due_date_display'] = date('d-m-Y', strtotime((string)$row['due_date']));
        $installments[] = $row;
    }

    $stmt->close();

    respond(true, 'Installments loaded successfully.', [
        'installments' => $installments,
    ]);
}

if ($action !== 'create' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Invalid action.', [], 400);
}

if (!hasPermission($conn, 'create')) {
    respond(false, 'You do not have permission to create chit collections.', [], 403);
}

$csrfToken = (string)($_POST['csrf_token'] ?? '');
$sessionToken = (string)($_SESSION['chit_collection_csrf'] ?? '');

if ($sessionToken === '' || $csrfToken === '' || !hash_equals($sessionToken, $csrfToken)) {
    respond(false, 'Invalid security token. Refresh the page and try again.', [], 419);
}

$memberId = (int)($_POST['chit_member_id'] ?? 0);
$installmentId = (int)($_POST['chit_installment_id'] ?? 0);
$collectionDate = trim((string)($_POST['collection_date'] ?? ''));
$paymentMethodId = (int)($_POST['payment_method_id'] ?? 0);
$paidAmount = (float)($_POST['paid_amount'] ?? 0);
$discountAmount = max(0, (float)($_POST['discount_amount'] ?? 0));
$penaltyAmount = max(0, (float)($_POST['penalty_amount'] ?? 0));
$referenceNo = trim((string)($_POST['reference_no'] ?? ''));
$remarks = trim((string)($_POST['remarks'] ?? ''));
$collectionReceiverName = trim(
    (string)($_POST['collection_receiver_name'] ?? '')
);

if ($collectionReceiverName === '') {
    respond(false, 'Please enter the collection receiver name.', [], 422);
}

if (mb_strlen($collectionReceiverName) > 150) {
    respond(false, 'Collection receiver name must not exceed 150 characters.', [], 422);
}

if ($memberId <= 0 || $installmentId <= 0) {
    respond(false, 'Please select a member and installment.', [], 422);
}

if (!validDate($collectionDate)) {
    respond(false, 'Please select a valid collection date.', [], 422);
}

if ($paymentMethodId <= 0) {
    respond(false, 'Please select a payment method.', [], 422);
}

if ($paidAmount <= 0) {
    respond(false, 'Paid amount must be greater than zero.', [], 422);
}

$stmt = $conn->prepare(
    "SELECT
        m.id AS member_id,
        m.chit_group_id,
        i.id AS installment_id,
        g.installment_amount,
        COALESCE(SUM(cc.paid_amount + cc.discount_amount), 0) AS settled_amount
     FROM chit_members m
     INNER JOIN chit_groups g
        ON g.id = m.chit_group_id
       AND g.business_id = m.business_id
     INNER JOIN chit_installments i
        ON i.chit_group_id = g.id
       AND i.business_id = g.business_id
     LEFT JOIN chit_collections cc
        ON cc.chit_member_id = m.id
       AND cc.chit_installment_id = i.id
       AND cc.business_id = m.business_id
       AND cc.branch_id = g.branch_id
     WHERE m.id = ?
       AND i.id = ?
       AND m.business_id = ?
       AND g.branch_id = ?
       AND m.status = 'Active'
       AND g.status = 'Active'
       AND i.status = 'Open'
     GROUP BY
        m.id,
        m.chit_group_id,
        i.id,
        g.installment_amount
     LIMIT 1"
);

if (!$stmt) {
    respond(false, 'Unable to validate collection: ' . $conn->error, [], 500);
}

$stmt->bind_param('iiii', $memberId, $installmentId, $businessId, $branchId);
$stmt->execute();
$collection = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$collection) {
    respond(false, 'The selected member or installment is not valid for this branch.', [], 422);
}

$pendingAmount = max(
    0,
    (float)$collection['installment_amount'] - (float)$collection['settled_amount']
);

if ($pendingAmount <= 0) {
    respond(false, 'This installment is already fully settled.', [], 422);
}

if (($paidAmount + $discountAmount) > ($pendingAmount + 0.009)) {
    respond(false, 'Paid amount plus discount cannot exceed the pending amount.', [], 422);
}

$paymentIdColumn = hasColumn($conn, 'payment_methods', 'payment_method_id')
    ? 'payment_method_id'
    : (hasColumn($conn, 'payment_methods', 'id') ? 'id' : '');

$paymentStatusColumn = hasColumn($conn, 'payment_methods', 'status')
    ? 'status'
    : (hasColumn($conn, 'payment_methods', 'is_active') ? 'is_active' : '');

if ($paymentIdColumn === '') {
    respond(false, 'Payment method table primary key is not supported.', [], 500);
}

/*
 * Use the same compatibility rule as the dropdown:
 * validate the selected active method by its primary key.
 *
 * The dropdown first tries the current business and then falls back to
 * active methods already available in the shared payment_methods table.
 * Therefore save validation must not reject a method only because its
 * business_id differs from the current session business.
 */
$paymentValidationSql = "SELECT `{$paymentIdColumn}` AS id
     FROM payment_methods
     WHERE `{$paymentIdColumn}` = ?";

if ($paymentStatusColumn !== '') {
    $paymentValidationSql .= " AND COALESCE(`{$paymentStatusColumn}`,1) = 1";
}

$paymentValidationSql .= ' LIMIT 1';
$stmt = $conn->prepare($paymentValidationSql);

if (!$stmt) {
    respond(false, 'Unable to validate payment method: ' . $conn->error, [], 500);
}

$stmt->bind_param('i', $paymentMethodId);

if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();

    respond(
        false,
        'Unable to validate the selected payment method: ' . $error,
        [],
        500
    );
}

$validPaymentMethod = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$validPaymentMethod) {
    respond(false, 'The selected payment method is invalid or inactive.', [], 422);
}

$receiptNo = nextReceiptNo($conn, $businessId, $branchId);
$dueAmount = $pendingAmount;
$netAmount = $paidAmount + $penaltyAmount - $discountAmount;
$groupId = (int)$collection['chit_group_id'];

try {
    ensureReceiverColumn($conn);
} catch (Throwable $e) {
    respond(false, $e->getMessage(), [], 500);
}

$conn->begin_transaction();

try {
    $stmt = $conn->prepare(
        "INSERT INTO chit_collections (
            business_id,
            branch_id,
            chit_group_id,
            chit_member_id,
            chit_installment_id,
            receipt_no,
            collection_date,
            due_amount,
            paid_amount,
            discount_amount,
            penalty_amount,
            net_amount,
            payment_method_id,
            reference_no,
            remarks,
            collection_receiver_name,
            created_by
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    if (!$stmt) {
        throw new RuntimeException('Unable to prepare collection insert: ' . $conn->error);
    }

    $stmt->bind_param(
        'iiiiissdddddisssi',
        $businessId,
        $branchId,
        $groupId,
        $memberId,
        $installmentId,
        $receiptNo,
        $collectionDate,
        $dueAmount,
        $paidAmount,
        $discountAmount,
        $penaltyAmount,
        $netAmount,
        $paymentMethodId,
        $referenceNo,
        $remarks,
        $collectionReceiverName,
        $userId
    );
} catch (Throwable $e) {
    $conn->rollback();
    respond(false, $e->getMessage(), [], 500);
}

try {
    if (!$stmt->execute()) {
        throw new RuntimeException('Unable to save collection: ' . $stmt->error);
    }

    $collectionId = (int)$stmt->insert_id;
    $stmt->close();

    $remaining = max(0, $pendingAmount - $paidAmount - $discountAmount);

    if ($remaining <= 0.009) {
        $stmt = $conn->prepare(
            "UPDATE chit_installments
             SET status = 'Closed'
             WHERE id = ?
               AND business_id = ?"
        );

        if ($stmt) {
            $stmt->bind_param('ii', $installmentId, $businessId);
            $stmt->execute();
            $stmt->close();
        }
    }

    $conn->commit();

    respond(true, 'Chit collection saved successfully.', [
        'collection_id' => $collectionId,
        'receipt_no' => $receiptNo,
        'member_id' => $memberId,
        'collection_receiver_name' => $collectionReceiverName,
    ]);
} catch (Throwable $e) {
    $conn->rollback();
    respond(false, $e->getMessage(), [], 500);
}
