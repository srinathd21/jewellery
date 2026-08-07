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
        (string)($_SESSION['pawn_release_csrf'] ?? ''),
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
            COALESCE(c.customer_name,'Unknown Customer') AS customer_name,
            c.customer_code,
            c.mobile,
            c.email,
            c.address_line1,
            c.address_line2,
            c.city,
            c.state,
            c.pincode,
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

function interestForeignKey(mysqli $conn): string
{
    return hasColumn($conn, 'pawn_interest_collections', 'pawn_entry_id')
        ? 'pawn_entry_id'
        : 'pawn_id';
}

function generateReceiptNo(
    mysqli $conn,
    int $businessId
): string {
    $prefix = 'REL' . date('Ym');
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

function lastInterestTo(
    mysqli $conn,
    int $businessId,
    int $pawnId
): ?string {
    if (
        !tableExists($conn, 'pawn_interest_collections') ||
        !hasColumn($conn, 'pawn_interest_collections', 'interest_to')
    ) {
        return null;
    }

    $foreignKey = interestForeignKey($conn);

    $stmt = $conn->prepare(
        "SELECT MAX(interest_to) AS last_interest_to
         FROM pawn_interest_collections
         WHERE business_id = ?
           AND `{$foreignKey}` = ?"
    );

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('ii', $businessId, $pawnId);
    $stmt->execute();
    $date = (string)(
        $stmt->get_result()->fetch_assoc()['last_interest_to'] ?? ''
    );
    $stmt->close();

    return $date !== '' ? $date : null;
}

function calculateRelease(
    array $pawn,
    string $fromDate,
    string $toDate
): array {
    try {
        $from = new DateTimeImmutable($fromDate);
        $to = new DateTimeImmutable($toDate);
    } catch (Throwable $error) {
        throw new RuntimeException('Invalid release calculation date.');
    }

    if ($from > $to) {
        $from = $to;
    }

    $days = (int)$from->diff($to)->days + 1;
    $principal = max(0, (float)($pawn['balance_principal'] ?? 0));
    $rate = max(0, (float)($pawn['interest_percent'] ?? 0));
    $period = (string)($pawn['interest_period'] ?? 'Monthly');

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
        'principal_amount' => round($principal, 2),
        'interest_amount' => round($interest, 2),
        'penalty_amount' => round($penalty, 2),
        'total_amount' => round($principal + $interest + $penalty, 2),
        'days_count' => $days,
        'interest_from' => $from->format('Y-m-d'),
        'interest_to' => $to->format('Y-m-d'),
    ];
}

function loadCombinedHistory(
    mysqli $conn,
    int $businessId,
    int $pawnId
): array {
    $rows = [];

    if (tableExists($conn, 'pawn_payments')) {
        $foreignKey = pawnPaymentForeignKey($conn);

        $selects = [
            'payment_date AS record_date',
            hasColumn($conn, 'pawn_payments', 'payment_type')
                ? 'payment_type AS record_type'
                : "'Payment' AS record_type",
            'receipt_no',
            'principal_amount',
            hasColumn($conn, 'pawn_payments', 'interest_amount')
                ? 'interest_amount'
                : '0 AS interest_amount',
            hasColumn($conn, 'pawn_payments', 'penalty_amount')
                ? 'penalty_amount'
                : '0 AS penalty_amount',
            hasColumn($conn, 'pawn_payments', 'total_amount')
                ? 'total_amount'
                : 'principal_amount AS total_amount',
        ];

        $stmt = $conn->prepare(
            'SELECT ' .
            implode(', ', $selects) .
            " FROM pawn_payments
              WHERE business_id = ?
                AND `{$foreignKey}` = ?"
        );

        if ($stmt) {
            $stmt->bind_param('ii', $businessId, $pawnId);
            $stmt->execute();
            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }

            $stmt->close();
        }
    }

    if (tableExists($conn, 'pawn_interest_collections')) {
        $foreignKey = interestForeignKey($conn);

        $totalColumn = hasColumn(
            $conn,
            'pawn_interest_collections',
            'total_amount'
        ) ? 'total_amount' : (
            hasColumn(
                $conn,
                'pawn_interest_collections',
                'net_amount'
            ) ? 'net_amount' : 'interest_amount'
        );

        $stmt = $conn->prepare(
            "SELECT
                collection_date AS record_date,
                'Interest Collection' AS record_type,
                receipt_no,
                0 AS principal_amount,
                interest_amount,
                " .
                (
                    hasColumn(
                        $conn,
                        'pawn_interest_collections',
                        'penalty_amount'
                    ) ? 'penalty_amount' : '0 AS penalty_amount'
                ) .
                ",
                {$totalColumn} AS total_amount
             FROM pawn_interest_collections
             WHERE business_id = ?
               AND `{$foreignKey}` = ?"
        );

        if ($stmt) {
            $stmt->bind_param('ii', $businessId, $pawnId);
            $stmt->execute();
            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }

            $stmt->close();
        }
    }

    usort(
        $rows,
        static fn(array $a, array $b): int =>
            strcmp(
                (string)$b['record_date'],
                (string)$a['record_date']
            )
    );

    foreach ($rows as &$row) {
        $row['date_display'] =
            !empty($row['record_date'])
                ? date('d-m-Y', strtotime($row['record_date']))
                : '';
    }

    unset($row);

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
            'pawn.release',
            'Release',
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

    $description = 'Released pawn using receipt ' . $receiptNo;
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
    respond(false, 'Required pawn release tables were not found.', [], 500);
}

if ($action === 'list_pawns') {
    $stmt = $conn->prepare(
        "SELECT
            pe.id,
            pe.pawn_no,
            pe.pawn_date,
            pe.principal_amount,
            pe.balance_principal,
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
        ['pawns' => $pawns]
    );
}

if ($action === 'load') {
    $pawnId = (int)($_POST['pawn_id'] ?? 0);
    $releaseDate = trim((string)($_POST['release_date'] ?? date('Y-m-d')));

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
                'Only active or partially paid pawns can be released.'
            );
        }

        if ((float)$pawn['balance_principal'] <= 0) {
            respond(
                false,
                'This pawn has no outstanding principal balance.'
            );
        }

        $lastInterestDate = lastInterestTo(
            $conn,
            $businessId,
            $pawnId
        );

        $interestFrom = $lastInterestDate
            ? (new DateTimeImmutable($lastInterestDate))
                ->modify('+1 day')
                ->format('Y-m-d')
            : (string)$pawn['pawn_date'];

        $release = calculateRelease(
            $pawn,
            $interestFrom,
            $releaseDate !== '' ? $releaseDate : date('Y-m-d')
        );

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
            strtotime($pawn['due_date']) <
            strtotime($releaseDate ?: date('Y-m-d'));

        $history = loadCombinedHistory(
            $conn,
            $businessId,
            $pawnId
        );

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
            'Pawn release details loaded.',
            [
                'pawn' => $pawn,
                'release' => $release,
                'history' => $history,
                'payment_methods' => $paymentMethods,
            ]
        );
    } catch (Throwable $error) {
        respond(false, $error->getMessage(), [], 500);
    }
}

if ($action === 'save') {
    $pawnId = (int)($_POST['pawn_id'] ?? 0);
    $releaseDate = trim((string)($_POST['release_date'] ?? ''));
    $principalAmount = max(0, (float)($_POST['principal_amount'] ?? 0));
    $interestAmount = max(0, (float)($_POST['interest_amount'] ?? 0));
    $penaltyAmount = max(0, (float)($_POST['penalty_amount'] ?? 0));
    $discountAmount = max(0, (float)($_POST['discount_amount'] ?? 0));
    $totalAmount = max(0, (float)($_POST['total_amount'] ?? 0));
    $paymentMethodId = (int)($_POST['payment_method_id'] ?? 0);
    $referenceNo = trim((string)($_POST['reference_no'] ?? ''));
    $releaseRemarks = trim((string)($_POST['release_remarks'] ?? ''));
    $waiveInterest = isset($_POST['waive_interest']) ? 1 : 0;
    $waivePenalty = isset($_POST['waive_penalty']) ? 1 : 0;

    if ($pawnId <= 0 || $releaseDate === '') {
        respond(false, 'Pawn and release date are required.');
    }

    if ($principalAmount <= 0) {
        respond(false, 'Outstanding principal must be paid in full.');
    }

    $expectedTotal = max(
        0,
        $principalAmount +
        $interestAmount +
        $penaltyAmount -
        $discountAmount
    );

    if ($totalAmount <= 0 || abs($expectedTotal - $totalAmount) > 0.02) {
        respond(false, 'The final release amount is invalid.');
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
                'Only active or partially paid pawns can be released.'
            );
        }

        $currentBalance = max(
            0,
            (float)($pawn['balance_principal'] ?? 0)
        );

        if (abs($principalAmount - $currentBalance) > 0.01) {
            throw new RuntimeException(
                'Principal amount must equal the complete outstanding balance.'
            );
        }

        $lastInterestDate = lastInterestTo(
            $conn,
            $businessId,
            $pawnId
        );

        $interestFrom = $lastInterestDate
            ? (new DateTimeImmutable($lastInterestDate))
                ->modify('+1 day')
                ->format('Y-m-d')
            : (string)$pawn['pawn_date'];

        $calculated = calculateRelease(
            $pawn,
            $interestFrom,
            $releaseDate
        );

        if (!$waiveInterest) {
            if (
                abs(
                    $interestAmount -
                    (float)$calculated['interest_amount']
                ) > 1.00
            ) {
                throw new RuntimeException(
                    'Interest amount differs from the calculated pending interest. Use the waiver option to waive it.'
                );
            }
        } elseif ($interestAmount > 0.01) {
            throw new RuntimeException(
                'Interest amount must be zero when interest is waived.'
            );
        }

        if (!$waivePenalty) {
            if (
                abs(
                    $penaltyAmount -
                    (float)$calculated['penalty_amount']
                ) > 1.00
            ) {
                throw new RuntimeException(
                    'Penalty amount differs from the calculated penalty. Use the waiver option to waive it.'
                );
            }
        } elseif ($penaltyAmount > 0.01) {
            throw new RuntimeException(
                'Penalty amount must be zero when penalty is waived.'
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

        $remarksParts = [];

        if ($releaseRemarks !== '') {
            $remarksParts[] = $releaseRemarks;
        }

        if ($waiveInterest) {
            $remarksParts[] = 'Pending interest waived';
        }

        if ($waivePenalty) {
            $remarksParts[] = 'Penalty waived';
        }

        $remarks = implode(' | ', $remarksParts);

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
            $releaseDate,
            $principalAmount,
        ];

        $optionalFields = [
            'branch_id' => ['i', $branchId],
            'payment_type' => ['s', 'Release'],
            'interest_amount' => ['d', $interestAmount],
            'penalty_amount' => ['d', $penaltyAmount],
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
                'Unable to prepare release payment: ' . $conn->error
            );
        }

        bindDynamic(
            $stmt,
            $types,
            $values
        );

        if (!$stmt->execute()) {
            throw new RuntimeException(
                'Unable to save release payment: ' . $stmt->error
            );
        }

        $paymentId = (int)$stmt->insert_id;
        $stmt->close();

        $updateParts = [
            'balance_principal = 0',
            "status = 'Closed'",
        ];

        $updateTypes = '';
        $updateValues = [];

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

        if (hasColumn($conn, 'pawn_entries', 'released_at')) {
            $updateParts[] = 'released_at = NOW()';
        }

        if (hasColumn($conn, 'pawn_entries', 'closed_at')) {
            $updateParts[] = 'closed_at = NOW()';
        }

        if (hasColumn($conn, 'pawn_entries', 'remarks')) {
            $updateParts[] =
                "remarks = CONCAT(
                    IFNULL(remarks,''),
                    CASE
                        WHEN IFNULL(remarks,'') = '' THEN ''
                        ELSE '\n'
                    END,
                    ?
                )";

            $updateTypes .= 's';
            $updateValues[] =
                'Released on ' .
                $releaseDate .
                ($remarks !== '' ? '. ' . $remarks : '');
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
                'Unable to prepare pawn release update: ' . $conn->error
            );
        }

        bindDynamic(
            $stmt,
            $updateTypes,
            $updateValues
        );

        if (!$stmt->execute()) {
            throw new RuntimeException(
                'Unable to update pawn release status: ' . $stmt->error
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
            'Pawn released and loan closed successfully.',
            [
                'payment_id' => $paymentId,
                'receipt_no' => $receiptNo,
                'status' => 'Closed',
            ]
        );
    } catch (Throwable $error) {
        $conn->rollback();
        respond(false, $error->getMessage(), [], 500);
    }
}

respond(false, 'Invalid action.', [], 400);
