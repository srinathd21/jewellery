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
        (string)($_SESSION['pawn_interest_csrf'] ?? ''),
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
            c.customer_name,
            c.mobile,
            pc.category_name
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

function calculateInterestValues(
    array $pawn,
    string $fromDate,
    string $toDate
): array {
    try {
        $from = new DateTimeImmutable($fromDate);
        $to = new DateTimeImmutable($toDate);
    } catch (Throwable $error) {
        throw new RuntimeException('Invalid interest date selected.');
    }

    if ($from > $to) {
        throw new RuntimeException(
            'Interest from date cannot be after interest to date.'
        );
    }

    $days = (int)$from->diff($to)->days + 1;
    $principal = max(0, (float)($pawn['balance_principal'] ?? 0));
    $rate = max(0, (float)($pawn['interest_percent'] ?? 0));
    $period = (string)($pawn['interest_period'] ?? 'Monthly');

    if ($principal <= 0) {
        throw new RuntimeException(
            'The pawn does not have an outstanding principal balance.'
        );
    }

    if ($period === 'Daily') {
        $interest = ($principal * $rate * $days) / 100;
    } elseif ($period === 'Yearly') {
        $interest = ($principal * $rate * $days) / (100 * 365);
    } else {
        $months = $days / 30;
        $interest = ($principal * $rate * $months) / 100;
    }

    $penalty = 0.0;
    $dueDate = trim((string)($pawn['due_date'] ?? ''));

    if ($dueDate !== '') {
        $due = new DateTimeImmutable($dueDate);

        if ($to > $due) {
            $penalty = $interest * 0.10;
        }
    }

    return [
        'days_count' => $days,
        'interest_amount' => round($interest, 2),
        'penalty_amount' => round($penalty, 2),
        'interest_from_display' => $from->format('d-m-Y'),
        'interest_to_display' => $to->format('d-m-Y'),
    ];
}

function generateReceiptNo(
    mysqli $conn,
    int $businessId
): string {
    $next = 1;

    $stmt = $conn->prepare(
        "SELECT receipt_no
         FROM pawn_interest_collections
         WHERE business_id = ?
           AND receipt_no LIKE 'INT%'
         ORDER BY id DESC
         LIMIT 1"
    );

    if ($stmt) {
        $stmt->bind_param('i', $businessId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            $number = (int)preg_replace(
                '/\D/',
                '',
                (string)$row['receipt_no']
            );

            $next = $number + 1;
        }
    }

    return 'INT' . str_pad(
        (string)$next,
        6,
        '0',
        STR_PAD_LEFT
    );
}

function loadHistory(
    mysqli $conn,
    int $businessId,
    int $pawnId
): array {
    $paymentJoin = '';
    $paymentSelect = "'' AS method_name";

    if (
        tableExists($conn, 'payment_methods') &&
        hasColumn($conn, 'pawn_interest_collections', 'payment_method_id')
    ) {
        $paymentJoin =
            ' LEFT JOIN payment_methods pm ON pm.id = pic.payment_method_id';

        $paymentSelect = "COALESCE(pm.method_name,'') AS method_name";
    }

    $pawnColumn = hasColumn(
        $conn,
        'pawn_interest_collections',
        'pawn_entry_id'
    ) ? 'pawn_entry_id' : 'pawn_id';

    $selects = [
        'pic.id',
        'pic.receipt_no',
        'pic.collection_date',
        hasColumn($conn, 'pawn_interest_collections', 'interest_from')
            ? 'pic.interest_from'
            : 'NULL AS interest_from',
        hasColumn($conn, 'pawn_interest_collections', 'interest_to')
            ? 'pic.interest_to'
            : 'NULL AS interest_to',
        hasColumn($conn, 'pawn_interest_collections', 'days_count')
            ? 'pic.days_count'
            : '0 AS days_count',
        'pic.interest_amount',
        hasColumn($conn, 'pawn_interest_collections', 'penalty_amount')
            ? 'pic.penalty_amount'
            : '0 AS penalty_amount',
        hasColumn($conn, 'pawn_interest_collections', 'discount_amount')
            ? 'pic.discount_amount'
            : '0 AS discount_amount',
        hasColumn($conn, 'pawn_interest_collections', 'total_amount')
            ? 'pic.total_amount'
            : (
                hasColumn($conn, 'pawn_interest_collections', 'net_amount')
                    ? 'pic.net_amount AS total_amount'
                    : 'pic.interest_amount AS total_amount'
            ),
        hasColumn($conn, 'pawn_interest_collections', 'reference_no')
            ? 'pic.reference_no'
            : "'' AS reference_no",
        $paymentSelect,
    ];

    $sql =
        'SELECT ' .
        implode(', ', $selects) .
        ' FROM pawn_interest_collections pic' .
        $paymentJoin .
        " WHERE pic.business_id = ?
            AND pic.`{$pawnColumn}` = ?
          ORDER BY pic.collection_date DESC, pic.id DESC";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException(
            'Unable to prepare interest history: ' . $conn->error
        );
    }

    $stmt->bind_param('ii', $businessId, $pawnId);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $row['collection_date_display'] =
            !empty($row['collection_date'])
                ? date('d-m-Y', strtotime($row['collection_date']))
                : '';

        $row['interest_from_display'] =
            !empty($row['interest_from'])
                ? date('d-m-Y', strtotime($row['interest_from']))
                : '';

        $row['interest_to_display'] =
            !empty($row['interest_to'])
                ? date('d-m-Y', strtotime($row['interest_to']))
                : '';

        $rows[] = $row;
    }

    $stmt->close();

    return $rows;
}

function totalInterestCollected(
    mysqli $conn,
    int $businessId,
    int $pawnId
): float {
    $pawnColumn = hasColumn(
        $conn,
        'pawn_interest_collections',
        'pawn_entry_id'
    ) ? 'pawn_entry_id' : 'pawn_id';

    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(interest_amount),0) AS total
         FROM pawn_interest_collections
         WHERE business_id = ?
           AND `{$pawnColumn}` = ?"
    );

    if (!$stmt) {
        return 0.0;
    }

    $stmt->bind_param('ii', $businessId, $pawnId);
    $stmt->execute();
    $total = (float)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();

    return $total;
}

function defaultInterestFrom(
    mysqli $conn,
    int $businessId,
    int $pawnId,
    string $pawnDate
): string {
    if (!hasColumn($conn, 'pawn_interest_collections', 'interest_to')) {
        return $pawnDate;
    }

    $pawnColumn = hasColumn(
        $conn,
        'pawn_interest_collections',
        'pawn_entry_id'
    ) ? 'pawn_entry_id' : 'pawn_id';

    $stmt = $conn->prepare(
        "SELECT MAX(interest_to) AS last_interest_to
         FROM pawn_interest_collections
         WHERE business_id = ?
           AND `{$pawnColumn}` = ?"
    );

    if (!$stmt) {
        return $pawnDate;
    }

    $stmt->bind_param('ii', $businessId, $pawnId);
    $stmt->execute();
    $lastDate = (string)(
        $stmt->get_result()->fetch_assoc()['last_interest_to'] ?? ''
    );
    $stmt->close();

    if ($lastDate === '') {
        return $pawnDate;
    }

    try {
        return (new DateTimeImmutable($lastDate))
            ->modify('+1 day')
            ->format('Y-m-d');
    } catch (Throwable $error) {
        return $pawnDate;
    }
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
    !tableExists($conn, 'pawn_interest_collections')
) {
    respond(
        false,
        'Required pawn tables were not found.',
        [],
        500
    );
}


if ($action === 'list_pawns') {
    $stmt = $conn->prepare(
        "SELECT
            pe.id,
            pe.pawn_no,
            pe.pawn_date,
            pe.balance_principal,
            pe.interest_percent,
            pe.interest_period,
            pe.status,
            COALESCE(c.customer_name,'Unknown Customer') AS customer_name,
            c.mobile,
            COALESCE(pc.category_name,'Unassigned') AS category_name
         FROM pawn_entries pe
         LEFT JOIN customers c
            ON c.id = pe.customer_id
         LEFT JOIN pawn_categories pc
            ON pc.id = pe.pawn_category_id
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
        $row['pawn_date_display'] =
            !empty($row['pawn_date'])
                ? date('d-m-Y', strtotime($row['pawn_date']))
                : '';

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
                'Interest can only be collected for active or partially paid pawns.'
            );
        }

        $pawn['pawn_date_display'] =
            !empty($pawn['pawn_date'])
                ? date('d-m-Y', strtotime($pawn['pawn_date']))
                : '';

        $pawn['due_date_display'] =
            !empty($pawn['due_date'])
                ? date('d-m-Y', strtotime($pawn['due_date']))
                : '';

        $pawn['is_overdue'] =
            !empty($pawn['due_date']) &&
            strtotime($pawn['due_date']) < strtotime(date('Y-m-d'));

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

        $history = loadHistory(
            $conn,
            $businessId,
            $pawnId
        );

        $totalCollected = totalInterestCollected(
            $conn,
            $businessId,
            $pawnId
        );

        $defaultFrom = defaultInterestFrom(
            $conn,
            $businessId,
            $pawnId,
            (string)$pawn['pawn_date']
        );

        respond(
            true,
            'Pawn interest details loaded.',
            [
                'pawn' => $pawn,
                'payment_methods' => $paymentMethods,
                'history' => $history,
                'total_interest_collected' => $totalCollected,
                'default_interest_from' => $defaultFrom,
                'default_interest_to' => date('Y-m-d'),
            ]
        );
    } catch (Throwable $error) {
        respond(false, $error->getMessage(), [], 500);
    }
}

if ($action === 'calculate') {
    $pawnId = (int)($_POST['pawn_id'] ?? 0);
    $fromDate = trim((string)($_POST['interest_from'] ?? ''));
    $toDate = trim((string)($_POST['interest_to'] ?? ''));

    if ($pawnId <= 0 || $fromDate === '' || $toDate === '') {
        respond(false, 'Pawn and interest dates are required.');
    }

    try {
        $pawn = getPawn($conn, $businessId, $pawnId);

        if (!$pawn) {
            respond(false, 'Pawn entry not found.', [], 404);
        }

        $calculation = calculateInterestValues(
            $pawn,
            $fromDate,
            $toDate
        );

        respond(
            true,
            'Interest calculated.',
            $calculation
        );
    } catch (Throwable $error) {
        respond(false, $error->getMessage());
    }
}

if ($action === 'save') {
    $pawnId = (int)($_POST['pawn_id'] ?? 0);
    $collectionDate = trim((string)($_POST['collection_date'] ?? ''));
    $interestFrom = trim((string)($_POST['interest_from'] ?? ''));
    $interestTo = trim((string)($_POST['interest_to'] ?? ''));
    $daysCount = max(0, (int)($_POST['days_count'] ?? 0));
    $interestAmount = max(0, (float)($_POST['interest_amount'] ?? 0));
    $penaltyAmount = max(0, (float)($_POST['penalty_amount'] ?? 0));
    $discountAmount = max(0, (float)($_POST['discount_amount'] ?? 0));
    $totalAmount = max(0, (float)($_POST['total_amount'] ?? 0));
    $paymentMethodId = (int)($_POST['payment_method_id'] ?? 0);
    $referenceNo = trim((string)($_POST['reference_no'] ?? ''));
    $remarks = trim((string)($_POST['remarks'] ?? ''));

    if (
        $pawnId <= 0 ||
        $collectionDate === '' ||
        $interestFrom === '' ||
        $interestTo === ''
    ) {
        respond(false, 'Pawn and collection dates are required.');
    }

    if ($interestAmount <= 0 || $totalAmount <= 0) {
        respond(false, 'Interest and total amount must be greater than zero.');
    }

    $expectedTotal = max(
        0,
        $interestAmount + $penaltyAmount - $discountAmount
    );

    if (abs($expectedTotal - $totalAmount) > 0.02) {
        respond(false, 'The total collection amount is incorrect.');
    }

    $conn->begin_transaction();

    try {
        $pawn = getPawn(
            $conn,
            $businessId,
            $pawnId,
            true
        );

        if (!$pawn) {
            throw new RuntimeException('Pawn entry not found.');
        }

        if (!in_array($pawn['status'], ['Active', 'Partially Paid'], true)) {
            throw new RuntimeException(
                'Interest can only be collected for active or partially paid pawns.'
            );
        }

        $calculation = calculateInterestValues(
            $pawn,
            $interestFrom,
            $interestTo
        );

        if ($daysCount <= 0) {
            $daysCount = (int)$calculation['days_count'];
        }

        $receiptNo = generateReceiptNo(
            $conn,
            $businessId
        );

        $columns = [
            'business_id',
            'pawn_entry_id',
            'receipt_no',
            'collection_date',
            'interest_amount',
        ];
        $placeholders = ['?', '?', '?', '?', '?'];
        $types = 'iissd';
        $values = [
            $businessId,
            $pawnId,
            $receiptNo,
            $collectionDate,
            $interestAmount,
        ];

        if (!hasColumn(
            $conn,
            'pawn_interest_collections',
            'pawn_entry_id'
        )) {
            $columns[1] = 'pawn_id';
        }

        $optionalFields = [
            'branch_id' => ['i', $branchId],
            'interest_from' => ['s', $interestFrom],
            'interest_to' => ['s', $interestTo],
            'days_count' => ['i', $daysCount],
            'penalty_amount' => ['d', $penaltyAmount],
            'discount_amount' => ['d', $discountAmount],
            'total_amount' => ['d', $totalAmount],
            'net_amount' => ['d', $totalAmount],
            'payment_method_id' => ['i', $paymentMethodId > 0 ? $paymentMethodId : null],
            'reference_no' => ['s', $referenceNo],
            'remarks' => ['s', $remarks],
            'created_by' => ['i', $userId],
        ];

        foreach ($optionalFields as $column => [$type, $value]) {
            if (hasColumn(
                $conn,
                'pawn_interest_collections',
                $column
            )) {
                $columns[] = $column;
                $placeholders[] = '?';
                $types .= $type;
                $values[] = $value;
            }
        }

        if (hasColumn(
            $conn,
            'pawn_interest_collections',
            'created_at'
        )) {
            $columns[] = 'created_at';
            $placeholders[] = 'NOW()';
        }

        $sql =
            'INSERT INTO pawn_interest_collections (' .
            implode(', ', $columns) .
            ') VALUES (' .
            implode(', ', $placeholders) .
            ')';

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            throw new RuntimeException(
                'Unable to prepare interest collection: ' . $conn->error
            );
        }

        bindDynamic($stmt, $types, $values);

        if (!$stmt->execute()) {
            throw new RuntimeException(
                'Unable to save interest collection: ' . $stmt->error
            );
        }

        $collectionId = (int)$stmt->insert_id;
        $stmt->close();

        if (hasColumn(
            $conn,
            'pawn_entries',
            'total_interest_collected'
        )) {
            $stmt = $conn->prepare(
                "UPDATE pawn_entries
                 SET total_interest_collected =
                     IFNULL(total_interest_collected,0) + ?
                 WHERE id = ?
                   AND business_id = ?
                 LIMIT 1"
            );

            if (!$stmt) {
                throw new RuntimeException(
                    'Unable to prepare pawn interest total update.'
                );
            }

            $stmt->bind_param(
                'dii',
                $interestAmount,
                $pawnId,
                $businessId
            );

            if (!$stmt->execute()) {
                throw new RuntimeException(
                    'Unable to update pawn interest total: ' . $stmt->error
                );
            }

            $stmt->close();
        }

        $conn->commit();

        respond(
            true,
            'Interest collection saved successfully.',
            [
                'collection_id' => $collectionId,
                'receipt_no' => $receiptNo,
            ]
        );
    } catch (Throwable $error) {
        $conn->rollback();
        respond(false, $error->getMessage(), [], 500);
    }
}

respond(false, 'Invalid action.', [], 400);
