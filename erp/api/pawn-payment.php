<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_OFF);
ini_set('display_errors', '0');

function respond(
    bool $success,
    string $message,
    array $extra = [],
    int $status = 200
): void {
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
        (string)($_SESSION['pawn_payment_csrf'] ?? ''),
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

function hasColumn(
    mysqli $conn,
    string $table,
    string $column
): bool {
    $tableSafe = $conn->real_escape_string($table);
    $columnSafe = $conn->real_escape_string($column);

    $result = $conn->query(
        "SHOW COLUMNS FROM `{$tableSafe}` LIKE '{$columnSafe}'"
    );

    return $result && $result->num_rows > 0;
}

function bindDynamic(
    mysqli_stmt $stmt,
    string $types,
    array &$values
): void {
    if ($types === '') {
        return;
    }

    if (strlen($types) !== count($values)) {
        throw new RuntimeException(
            'Bind parameter mismatch. Types: ' .
            strlen($types) .
            ', Values: ' .
            count($values)
        );
    }

    $bind = [$types];

    foreach ($values as $key => $value) {
        $bind[] = &$values[$key];
    }

    call_user_func_array([$stmt, 'bind_param'], $bind);
}

function generateReceiptNo(
    mysqli $conn,
    int $businessId
): string {
    $prefix = 'PAY' . date('Ym');
    $like = $prefix . '%';
    $next = 1;

    $stmt = $conn->prepare(
        "SELECT receipt_no
         FROM pawn_payments
         WHERE business_id = ?
           AND receipt_no LIKE ?
         ORDER BY id DESC
         LIMIT 1"
    );

    if ($stmt) {
        $stmt->bind_param('is', $businessId, $like);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            $number = (int)substr(
                preg_replace('/\D/', '', (string)$row['receipt_no']),
                -4
            );

            $next = $number + 1;
        }
    }

    return $prefix . str_pad(
        (string)$next,
        4,
        '0',
        STR_PAD_LEFT
    );
}

function getPawn(
    mysqli $conn,
    int $businessId,
    int $pawnId,
    bool $lock = false
): ?array {
    $lockSql = $lock ? ' FOR UPDATE' : '';

    $stmt = $conn->prepare(
        "SELECT
            pe.*,
            COALESCE(c.customer_name,'Unknown Customer') AS customer_name,
            c.customer_code,
            c.mobile,
            c.email,
            COALESCE(pc.category_name,'Unassigned') AS category_name
         FROM pawn_entries pe
         LEFT JOIN customers c
            ON c.id = pe.customer_id
         LEFT JOIN pawn_categories pc
            ON pc.id = pe.pawn_category_id
         WHERE pe.id = ?
           AND pe.business_id = ?
         LIMIT 1{$lockSql}"
    );

    if (!$stmt) {
        throw new RuntimeException(
            'Unable to prepare pawn query: ' . $conn->error
        );
    }

    $stmt->bind_param('ii', $pawnId, $businessId);
    $stmt->execute();
    $pawn = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $pawn ?: null;
}

function pawnPaymentForeignKey(mysqli $conn): string
{
    return hasColumn($conn, 'pawn_payments', 'pawn_entry_id')
        ? 'pawn_entry_id'
        : 'pawn_id';
}

function loadHistory(
    mysqli $conn,
    int $businessId,
    int $pawnId
): array {
    $foreignKey = pawnPaymentForeignKey($conn);

    $paymentJoin = '';
    $methodSelect = "'' AS method_name";

    if (
        tableExists($conn, 'payment_methods') &&
        hasColumn($conn, 'pawn_payments', 'payment_method_id')
    ) {
        $paymentJoin =
            ' LEFT JOIN payment_methods pm ON pm.id = pp.payment_method_id';

        $methodSelect = "COALESCE(pm.method_name,'') AS method_name";
    }

    $selects = [
        'pp.id',
        'pp.receipt_no',
        'pp.payment_date',
        hasColumn($conn, 'pawn_payments', 'payment_type')
            ? 'pp.payment_type'
            : "'Part Payment' AS payment_type",
        'pp.principal_amount',
        hasColumn($conn, 'pawn_payments', 'interest_amount')
            ? 'pp.interest_amount'
            : '0 AS interest_amount',
        hasColumn($conn, 'pawn_payments', 'penalty_amount')
            ? 'pp.penalty_amount'
            : '0 AS penalty_amount',
        hasColumn($conn, 'pawn_payments', 'charges_amount')
            ? 'pp.charges_amount'
            : '0 AS charges_amount',
        hasColumn($conn, 'pawn_payments', 'discount_amount')
            ? 'pp.discount_amount'
            : '0 AS discount_amount',
        hasColumn($conn, 'pawn_payments', 'total_amount')
            ? 'pp.total_amount'
            : 'pp.principal_amount AS total_amount',
        hasColumn($conn, 'pawn_payments', 'reference_no')
            ? 'pp.reference_no'
            : "'' AS reference_no",
        $methodSelect,
    ];

    $stmt = $conn->prepare(
        'SELECT ' .
        implode(', ', $selects) .
        ' FROM pawn_payments pp' .
        $paymentJoin .
        " WHERE pp.business_id = ?
            AND pp.`{$foreignKey}` = ?
          ORDER BY pp.payment_date DESC, pp.id DESC
          LIMIT 50"
    );

    if (!$stmt) {
        throw new RuntimeException(
            'Unable to prepare payment history: ' . $conn->error
        );
    }

    $stmt->bind_param('ii', $businessId, $pawnId);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $row['payment_date_display'] =
            !empty($row['payment_date'])
                ? date('d-m-Y', strtotime($row['payment_date']))
                : '';

        $rows[] = $row;
    }

    $stmt->close();

    return $rows;
}

function addAudit(
    mysqli $conn,
    int $businessId,
    int $branchId,
    int $userId,
    int $referenceId,
    string $receiptNo
): void {
    if (!tableExists($conn, 'audit_logs')) {
        return;
    }

    $stmt = $conn->prepare(
        "INSERT INTO audit_logs
        (
            business_id,
            branch_id,
            user_id,
            module_code,
            action_type,
            reference_table,
            reference_id,
            description,
            new_values_json,
            ip_address,
            user_agent
        )
        VALUES
        (
            ?,
            ?,
            ?,
            'pawn.payments',
            'Create',
            'pawn_payments',
            ?,
            ?,
            ?,
            ?,
            ?
        )"
    );

    if (!$stmt) {
        return;
    }

    $description = 'Created pawn payment ' . $receiptNo;
    $json = json_encode(
        ['receipt_no' => $receiptNo],
        JSON_UNESCAPED_UNICODE
    );
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $userAgent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

    $stmt->bind_param(
        'iiiissss',
        $businessId,
        $branchId,
        $userId,
        $referenceId,
        $description,
        $json,
        $ip,
        $userAgent
    );

    $stmt->execute();
    $stmt->close();
}

$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? ($_SESSION['default_branch_id'] ?? 0));
$userId = (int)($_SESSION['user_id'] ?? 0);
$action = (string)($_POST['action'] ?? '');

if ($businessId <= 0 || $branchId <= 0) {
    respond(false, 'A valid business and branch must be selected.', [], 403);
}

if (
    !tableExists($conn, 'pawn_entries') ||
    !tableExists($conn, 'pawn_payments')
) {
    respond(false, 'Required pawn payment tables were not found.', [], 500);
}

if ($action === 'list_pawns') {
    $stmt = $conn->prepare(
        "SELECT
            pe.id,
            pe.pawn_no,
            pe.pawn_date,
            pe.principal_amount,
            pe.balance_principal,
            pe.interest_percent,
            pe.interest_period,
            pe.status,
            COALESCE(c.customer_name,'Unknown Customer') AS customer_name,
            c.mobile
         FROM pawn_entries pe
         LEFT JOIN customers c
            ON c.id = pe.customer_id
         WHERE pe.business_id = ?
           AND pe.status IN ('Active','Partially Paid')
           AND pe.balance_principal > 0
         ORDER BY pe.pawn_date DESC, pe.id DESC"
    );

    if (!$stmt) {
        respond(
            false,
            'Unable to prepare pawn selection list: ' . $conn->error,
            [],
            500
        );
    }

    $stmt->bind_param('i', $businessId);
    $stmt->execute();
    $result = $stmt->get_result();

    $pawns = [];

    while ($row = $result->fetch_assoc()) {
        $pawns[] = $row;
    }

    $stmt->close();

    respond(
        true,
        'Pawn entries loaded.',
        [
            'pawns' => $pawns,
        ]
    );
}

if ($action === 'load') {
    $pawnId = (int)($_POST['pawn_id'] ?? 0);

    if ($pawnId <= 0) {
        respond(false, 'Invalid pawn selected.');
    }

    try {
        $pawn = getPawn($conn, $businessId, $pawnId);

        if (!$pawn) {
            respond(false, 'Pawn entry not found.', [], 404);
        }

        if (!in_array($pawn['status'], ['Active', 'Partially Paid'], true)) {
            respond(
                false,
                'Payments can only be recorded for active or partially paid pawns.'
            );
        }

        $pawn['pawn_date_display'] =
            !empty($pawn['pawn_date'])
                ? date('d-m-Y', strtotime($pawn['pawn_date']))
                : '';

        $history = loadHistory($conn, $businessId, $pawnId);

        $totalPrincipalPaid = 0.0;
        $totalInterestPaid = 0.0;

        foreach ($history as $row) {
            $totalPrincipalPaid += (float)$row['principal_amount'];
            $totalInterestPaid += (float)$row['interest_amount'];
        }

        $paymentMethods = [];

        if (tableExists($conn, 'payment_methods')) {
            $stmt = $conn->prepare(
                "SELECT id, method_name
                 FROM payment_methods
                 WHERE business_id = ?
                   AND is_active = 1
                 ORDER BY method_name"
            );

            if ($stmt) {
                $stmt->bind_param('i', $businessId);
                $stmt->execute();
                $result = $stmt->get_result();

                while ($row = $result->fetch_assoc()) {
                    $paymentMethods[] = $row;
                }

                $stmt->close();
            }
        }

        respond(
            true,
            'Pawn payment details loaded.',
            [
                'pawn' => $pawn,
                'history' => $history,
                'payment_methods' => $paymentMethods,
                'total_principal_paid' => $totalPrincipalPaid,
                'total_interest_paid' => $totalInterestPaid,
            ]
        );
    } catch (Throwable $error) {
        respond(false, $error->getMessage(), [], 500);
    }
}

if ($action === 'save') {
    $pawnId = (int)($_POST['pawn_id'] ?? 0);
    $paymentDate = trim((string)($_POST['payment_date'] ?? ''));
    $paymentType = trim((string)($_POST['payment_type'] ?? 'Part Payment'));
    $principalAmount = max(0, (float)($_POST['principal_amount'] ?? 0));
    $interestAmount = max(0, (float)($_POST['interest_amount'] ?? 0));
    $penaltyAmount = max(0, (float)($_POST['penalty_amount'] ?? 0));
    $chargesAmount = max(0, (float)($_POST['charges_amount'] ?? 0));
    $discountAmount = max(0, (float)($_POST['discount_amount'] ?? 0));
    $totalAmount = max(0, (float)($_POST['total_amount'] ?? 0));
    $paymentMethodId = (int)($_POST['payment_method_id'] ?? 0);
    $referenceNo = trim((string)($_POST['reference_no'] ?? ''));
    $remarks = trim((string)($_POST['remarks'] ?? ''));

    $allowedTypes = [
        'Part Payment',
        'Interest Only',
        'Settlement',
        'Release',
    ];

    if ($pawnId <= 0 || $paymentDate === '') {
        respond(false, 'Pawn and payment date are required.');
    }

    if (!in_array($paymentType, $allowedTypes, true)) {
        respond(false, 'Invalid payment type selected.');
    }

    if (
        $principalAmount <= 0 &&
        $interestAmount <= 0 &&
        $penaltyAmount <= 0 &&
        $chargesAmount <= 0
    ) {
        respond(false, 'Enter at least one payment amount.');
    }

    $expectedTotal = max(
        0,
        $principalAmount +
        $interestAmount +
        $penaltyAmount +
        $chargesAmount -
        $discountAmount
    );

    if ($totalAmount <= 0 || abs($expectedTotal - $totalAmount) > 0.02) {
        respond(false, 'The total payment amount is invalid.');
    }

    $conn->begin_transaction();

    try {
        $pawn = getPawn($conn, $businessId, $pawnId, true);

        if (!$pawn) {
            throw new RuntimeException('Pawn entry not found.');
        }

        if (!in_array($pawn['status'], ['Active', 'Partially Paid'], true)) {
            throw new RuntimeException(
                'Payments can only be recorded for active or partially paid pawns.'
            );
        }

        $currentBalance = max(
            0,
            (float)($pawn['balance_principal'] ?? 0)
        );

        if ($principalAmount > $currentBalance + 0.01) {
            throw new RuntimeException(
                'Principal payment cannot exceed the outstanding balance.'
            );
        }

        if (
            in_array($paymentType, ['Settlement', 'Release'], true) &&
            abs($principalAmount - $currentBalance) > 0.01
        ) {
            throw new RuntimeException(
                'Settlement or release must pay the full outstanding principal.'
            );
        }

        if (
            $paymentMethodId > 0 &&
            tableExists($conn, 'payment_methods')
        ) {
            $stmt = $conn->prepare(
                "SELECT id
                 FROM payment_methods
                 WHERE id = ?
                   AND business_id = ?
                   AND is_active = 1
                 LIMIT 1"
            );

            if (!$stmt) {
                throw new RuntimeException(
                    'Unable to validate payment method.'
                );
            }

            $stmt->bind_param(
                'ii',
                $paymentMethodId,
                $businessId
            );
            $stmt->execute();
            $method = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$method) {
                throw new RuntimeException(
                    'Invalid payment method selected.'
                );
            }
        }

        $receiptNo = generateReceiptNo(
            $conn,
            $businessId
        );

        $foreignKey = pawnPaymentForeignKey($conn);

        $columns = [
            'business_id',
            $foreignKey,
            'receipt_no',
            'payment_date',
            'principal_amount',
        ];
        $placeholders = ['?', '?', '?', '?', '?'];
        $types = 'iissd';
        $values = [
            $businessId,
            $pawnId,
            $receiptNo,
            $paymentDate,
            $principalAmount,
        ];

        $optionalFields = [
            'branch_id' => ['i', $branchId],
            'payment_type' => ['s', $paymentType],
            'interest_amount' => ['d', $interestAmount],
            'penalty_amount' => ['d', $penaltyAmount],
            'charges_amount' => ['d', $chargesAmount],
            'discount_amount' => ['d', $discountAmount],
            'total_amount' => ['d', $totalAmount],
            'payment_method_id' => [
                'i',
                $paymentMethodId > 0 ? $paymentMethodId : null,
            ],
            'reference_no' => ['s', $referenceNo],
            'remarks' => ['s', $remarks],
            'created_by' => ['i', $userId],
        ];

        foreach ($optionalFields as $column => [$type, $value]) {
            if (hasColumn($conn, 'pawn_payments', $column)) {
                $columns[] = $column;
                $placeholders[] = '?';
                $types .= $type;
                $values[] = $value;
            }
        }

        if (hasColumn($conn, 'pawn_payments', 'created_at')) {
            $columns[] = 'created_at';
            $placeholders[] = 'NOW()';
        }

        $stmt = $conn->prepare(
            'INSERT INTO pawn_payments (' .
            implode(', ', $columns) .
            ') VALUES (' .
            implode(', ', $placeholders) .
            ')'
        );

        if (!$stmt) {
            throw new RuntimeException(
                'Unable to prepare pawn payment: ' . $conn->error
            );
        }

        bindDynamic($stmt, $types, $values);

        if (!$stmt->execute()) {
            throw new RuntimeException(
                'Unable to save pawn payment: ' . $stmt->error
            );
        }

        $paymentId = (int)$stmt->insert_id;
        $stmt->close();

        $newBalance = max(
            0,
            $currentBalance - $principalAmount
        );

        $newStatus = $newBalance <= 0
            ? 'Closed'
            : (
                $principalAmount > 0
                    ? 'Partially Paid'
                    : (string)$pawn['status']
            );

        $updateParts = [
            'balance_principal = ?',
            'status = ?',
        ];
        $updateTypes = 'ds';
        $updateValues = [
            $newBalance,
            $newStatus,
        ];

        if (hasColumn($conn, 'pawn_entries', 'total_principal_paid')) {
            $updateParts[] =
                'total_principal_paid = IFNULL(total_principal_paid,0) + ?';

            $updateTypes .= 'd';
            $updateValues[] = $principalAmount;
        }

        if (hasColumn($conn, 'pawn_entries', 'total_interest_collected')) {
            $updateParts[] =
                'total_interest_collected = IFNULL(total_interest_collected,0) + ?';

            $updateTypes .= 'd';
            $updateValues[] = $interestAmount;
        }

        if (
            $newBalance <= 0 &&
            hasColumn($conn, 'pawn_entries', 'closed_at')
        ) {
            $updateParts[] = 'closed_at = NOW()';
        }

        if (
            $newBalance <= 0 &&
            hasColumn($conn, 'pawn_entries', 'released_at')
        ) {
            $updateParts[] = 'released_at = NOW()';
        }

        if (hasColumn($conn, 'pawn_entries', 'updated_at')) {
            $updateParts[] = 'updated_at = NOW()';
        }

        $updateTypes .= 'ii';
        $updateValues[] = $pawnId;
        $updateValues[] = $businessId;

        $stmt = $conn->prepare(
            'UPDATE pawn_entries SET ' .
            implode(', ', $updateParts) .
            ' WHERE id = ? AND business_id = ? LIMIT 1'
        );

        if (!$stmt) {
            throw new RuntimeException(
                'Unable to prepare pawn balance update: ' . $conn->error
            );
        }

        bindDynamic(
            $stmt,
            $updateTypes,
            $updateValues
        );

        if (!$stmt->execute()) {
            throw new RuntimeException(
                'Unable to update pawn balance: ' . $stmt->error
            );
        }

        $stmt->close();

        addAudit(
            $conn,
            $businessId,
            $branchId,
            $userId,
            $paymentId,
            $receiptNo
        );

        $conn->commit();

        respond(
            true,
            $newBalance <= 0
                ? 'Pawn payment saved and loan closed successfully.'
                : 'Pawn payment saved successfully.',
            [
                'payment_id' => $paymentId,
                'receipt_no' => $receiptNo,
                'balance_principal' => $newBalance,
                'status' => $newStatus,
            ]
        );
    } catch (Throwable $error) {
        $conn->rollback();
        respond(false, $error->getMessage(), [], 500);
    }
}

respond(false, 'Invalid action.', [], 400);
