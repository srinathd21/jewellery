<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_OFF);
ini_set('display_errors', '0');

function respond(bool $ok, string $message = '', array $extra = [], int $code = 200): void
{
    http_response_code($code);
    echo json_encode(
        array_merge(['success' => $ok, 'message' => $message], $extra),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

register_shutdown_function(static function (): void {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        respond(false, 'Fatal API error: ' . $e['message'], [], 500);
    }
});

foreach ([
    dirname(__DIR__) . '/config/config.php',
    dirname(__DIR__) . '/config.php',
    dirname(__DIR__) . '/includes/config.php',
    dirname(__DIR__) . '/super-admin/includes/config.php'
] as $f) {
    if (is_file($f)) {
        require_once $f;
        break;
    }
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    respond(false, 'Database configuration is not available.', [], 500);
}

$conn->set_charset('utf8mb4');

if (empty($_SESSION['user_id'])) {
    respond(false, 'Session expired.', [], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Invalid request method.', [], 405);
}

if (!hash_equals(
    (string)($_SESSION['pawn_csrf'] ?? ''),
    (string)($_POST['csrf_token'] ?? '')
)) {
    respond(false, 'Invalid request token. Refresh the page.', [], 419);
}

$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? ($_SESSION['default_branch_id'] ?? 0));
$userId = (int)($_SESSION['user_id'] ?? 0);
$action = trim((string)($_POST['action'] ?? ''));

if ($businessId <= 0 || $branchId <= 0) {
    respond(false, 'Select a valid business and branch.', [], 403);
}

function tableExists(mysqli $conn, string $table): bool
{
    $table = $conn->real_escape_string($table);
    $r = $conn->query("SHOW TABLES LIKE '{$table}'");
    return $r && $r->num_rows > 0;
}

function hasColumn(mysqli $conn, string $table, string $column): bool
{
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $r = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    return $r && $r->num_rows > 0;
}

function dateValid(string $date): bool
{
    if ($date === '') {
        return false;
    }
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

function moneyRound(float $value, string $mode): float
{
    if ($mode === 'Nearest Rupee') return round($value);
    if ($mode === 'Ceil Rupee') return ceil($value);
    if ($mode === 'Floor Rupee') return floor($value);
    return round($value, 2);
}

function calculateInterest(array $pawn, string $fromDate, string $toDate): array
{
    $principal = max(0, (float)($pawn['balance_principal'] ?? 0));
    $percent = max(0, (float)($pawn['interest_percent'] ?? 0));
    $period = (string)($pawn['interest_period'] ?? 'Monthly');
    $method = (string)($pawn['interest_method'] ?? 'Simple');
    $minDays = max(0, (int)($pawn['minimum_interest_days'] ?? 0));

    $a = new DateTime($fromDate);
    $b = new DateTime($toDate);
    $days = max(0, (int)$a->diff($b)->format('%a'));

    if ($days < $minDays) {
        $days = $minDays;
    }

    $months = $days / 30.0;

    if ($period === 'Daily') {
        $interest = $principal * ($percent / 100) * $days;
    } elseif ($period === 'Yearly') {
        $interest = $principal * ($percent / 100) * ($days / 365.0);
    } else {
        $interest = $principal * ($percent / 100) * $months;
    }

    if ($method === 'Flat') {
        $base = max(0, (float)($pawn['principal_amount'] ?? 0));

        if ($period === 'Daily') {
            $interest = $base * ($percent / 100) * $days;
        } elseif ($period === 'Yearly') {
            $interest = $base * ($percent / 100) * ($days / 365.0);
        } else {
            $interest = $base * ($percent / 100) * $months;
        }
    }

    $interest = moneyRound(
        $interest,
        (string)($pawn['interest_rounding_method'] ?? 'Nearest Rupee')
    );

    return [
        'days' => $days,
        'months' => round($months, 4),
        'interest' => max(0, $interest),
    ];
}

function calculatePenalty(array $pawn, string $asOf): array
{
    $dueDate = (string)($pawn['due_date'] ?? '');
    $type = (string)($pawn['overdue_charge_type'] ?? 'None');
    $value = max(0, (float)($pawn['overdue_charge_value'] ?? 0));

    if ($dueDate === '' || $type === 'None' || $value <= 0) {
        return ['days' => 0, 'amount' => 0.0];
    }

    $start = new DateTime($dueDate);
    $start->modify('+' . max(0, (int)($pawn['grace_days'] ?? 0)) . ' days');

    $end = new DateTime($asOf);

    if ($end <= $start) {
        return ['days' => 0, 'amount' => 0.0];
    }

    $days = (int)$start->diff($end)->format('%a');

    if ($type === 'Fixed') {
        $amount = $value;
    } elseif ($type === 'Daily Fixed') {
        $amount = $value * $days;
    } elseif ($type === 'Monthly Fixed') {
        $amount = $value * (int)ceil($days / 30);
    } else {
        $amount = max(0, (float)($pawn['balance_principal'] ?? 0)) * ($value / 100);
    }

    $max = $pawn['maximum_overdue_charge'] ?? null;
    if ($max !== null && $max !== '' && (float)$max > 0) {
        $amount = min($amount, (float)$max);
    }

    return [
        'days' => $days,
        'amount' => round($amount, 2),
    ];
}

function getPawn(
    mysqli $conn,
    int $businessId,
    int $branchId,
    int $pawnId
): array {
    $sql = "SELECT
                pe.*,
                c.customer_name,
                c.customer_code,
                c.mobile,
                pc.category_name
            FROM pawn_entries pe
            INNER JOIN customers c
                ON c.id = pe.customer_id
               AND c.business_id = pe.business_id
            LEFT JOIN pawn_categories pc
                ON pc.id = pe.pawn_category_id
            WHERE pe.id = ?
              AND pe.business_id = ?
              AND pe.branch_id = ?
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to load pawn entry: ' . $conn->error);
    }

    $stmt->bind_param('iii', $pawnId, $businessId, $branchId);
    $stmt->execute();
    $pawn = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$pawn) {
        throw new RuntimeException('Pawn entry was not found.');
    }

    return $pawn;
}

function financialYearParts(string $date): array
{
    $ts = strtotime($date);
    $year = (int)date('Y', $ts);
    $month = (int)date('n', $ts);
    $start = $month >= 4 ? $year : $year - 1;
    return [$start, $start + 1];
}

function documentNumber(
    mysqli $conn,
    int $businessId,
    int $branchId,
    string $key,
    string $date,
    bool $consume
): string {
    if (!tableExists($conn, 'document_number_settings')) {
        throw new RuntimeException('Document number settings table is missing.');
    }

    $stmt = $conn->prepare(
        "SELECT *
         FROM document_number_settings
         WHERE business_id=?
           AND document_key=?
           AND is_active=1
           AND (branch_id=? OR branch_id IS NULL)
         ORDER BY (branch_id=?) DESC,id DESC
         LIMIT 1"
    );

    if (!$stmt) {
        throw new RuntimeException('Unable to read document number settings: ' . $conn->error);
    }

    $stmt->bind_param('isii', $businessId, $key, $branchId, $branchId);
    $stmt->execute();
    $set = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$set) {
        throw new RuntimeException('Configure ' . $key . ' in Document Number Settings.');
    }

    $ts = strtotime($date);
    [$fy1, $fy2] = financialYearParts($date);

    $reset = (string)$set['reset_frequency'];

    if ($reset === 'Monthly') {
        $period = date('Ym', $ts);
    } elseif ($reset === 'Daily') {
        $period = date('Ymd', $ts);
    } elseif ($reset === 'Calendar Year') {
        $period = date('Y', $ts);
    } elseif ($reset === 'Never') {
        $period = 'ALL';
    } else {
        $period = $fy1 . '-' . $fy2;
    }

    $current = 0;

    $sql = 'SELECT current_number
            FROM number_sequences
            WHERE business_id=?
              AND branch_id=?
              AND document_type=?
              AND period_key=?
            LIMIT 1';

    if ($consume) {
        $sql .= ' FOR UPDATE';
    }

    $q = $conn->prepare($sql);

    if ($q) {
        $q->bind_param('iiss', $businessId, $branchId, $key, $period);
        $q->execute();
        $row = $q->get_result()->fetch_assoc();
        $q->close();

        if ($row) {
            $current = (int)$row['current_number'];
        }
    }

    $next = max((int)$set['sequence_start'], $current + 1);

    if ($consume) {
        $q = $conn->prepare(
            'INSERT INTO number_sequences
                (business_id,branch_id,document_type,period_key,current_number)
             VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE current_number=VALUES(current_number)'
        );

        if (!$q) {
            throw new RuntimeException('Unable to update document sequence: ' . $conn->error);
        }

        $q->bind_param('iissi', $businessId, $branchId, $key, $period, $next);

        if (!$q->execute()) {
            $err = $q->error;
            $q->close();
            throw new RuntimeException('Unable to update document sequence: ' . $err);
        }

        $q->close();
    }

    $seq = str_pad(
        (string)$next,
        max(1, (int)$set['sequence_digits']),
        '0',
        STR_PAD_LEFT
    );

    $center = strtr(
        (string)$set['center_format'],
        [
            '{YYYY}' => date('Y', $ts),
            '{YY}' => date('y', $ts),
            '{MM}' => date('m', $ts),
            '{DD}' => date('d', $ts),
            '{FY_SHORT}' => substr((string)$fy1, 2) . '-' . substr((string)$fy2, 2),
            '{FY}' => $fy1 . '-' . $fy2,
        ]
    );

    return strtr(
        (string)$set['format_template'],
        [
            '{PREFIX}' => (string)$set['prefix'],
            '{DIVIDER}' => (string)$set['divider'],
            '{CENTER}' => $center,
            '{FY_SHORT}' => substr((string)$fy1, 2) . '-' . substr((string)$fy2, 2),
            '{SEQ}' => $seq,
            '{SUFFIX}' => (string)$set['suffix'],
        ]
    );
}

function actionLog(
    mysqli $conn,
    int $businessId,
    int $branchId,
    int $pawnId,
    string $actionType,
    string $table,
    int $referenceId,
    string $description,
    int $userId
): void {
    if (!tableExists($conn, 'pawn_action_history')) {
        return;
    }

    $stmt = $conn->prepare(
        'INSERT INTO pawn_action_history
            (business_id,branch_id,pawn_entry_id,action_type,reference_table,reference_id,description,action_by)
         VALUES (?,?,?,?,?,?,?,?)'
    );

    if (!$stmt) {
        return;
    }

    $stmt->bind_param(
        'iiissisi',
        $businessId,
        $branchId,
        $pawnId,
        $actionType,
        $table,
        $referenceId,
        $description,
        $userId
    );

    $stmt->execute();
    $stmt->close();
}

/* ---------------------------------------------------------
 * OPTIONS
 * ------------------------------------------------------ */
if ($action === 'pawn_options') {
    $statusFilter = trim((string)($_POST['status'] ?? 'open'));

    $where = "pe.business_id=? AND pe.branch_id=?";

    if ($statusFilter === 'open') {
        $where .= " AND pe.status IN ('Active','Partially Paid')";
    }

    $sql = "SELECT
                pe.id,
                pe.pawn_no,
                pe.pawn_date,
                pe.due_date,
                pe.customer_id,
                pe.principal_amount,
                pe.balance_principal,
                pe.interest_percent,
                pe.interest_period,
                pe.interest_method,
                pe.interest_collection_cycle,
                pe.interest_cycle_months,
                pe.last_interest_paid_upto,
                pe.next_interest_due_date,
                pe.minimum_interest_days,
                pe.interest_rounding_method,
                pe.grace_days,
                pe.overdue_charge_type,
                pe.overdue_charge_value,
                pe.maximum_overdue_charge,
                pe.status,
                c.customer_name,
                c.customer_code,
                c.mobile,
                pc.category_name
            FROM pawn_entries pe
            INNER JOIN customers c
                ON c.id=pe.customer_id
               AND c.business_id=pe.business_id
            LEFT JOIN pawn_categories pc
                ON pc.id=pe.pawn_category_id
            WHERE {$where}
            ORDER BY pe.id DESC";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        respond(false, 'Unable to load pawn options: ' . $conn->error, [], 500);
    }

    $stmt->bind_param('ii', $businessId, $branchId);
    $stmt->execute();

    $result = $stmt->get_result();
    $pawns = [];

    while ($row = $result->fetch_assoc()) {
        $pawns[] = $row;
    }

    $stmt->close();

    $methods = [];

    $stmt = $conn->prepare(
        'SELECT id,method_name,method_type
         FROM payment_methods
         WHERE business_id=?
           AND is_active=1
         ORDER BY method_name'
    );

    if ($stmt) {
        $stmt->bind_param('i', $businessId);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $methods[] = $row;
        }

        $stmt->close();
    }

    respond(true, '', [
        'pawns' => $pawns,
        'payment_methods' => $methods,
    ]);
}

/* ---------------------------------------------------------
 * PAYMENT QUOTE
 * ------------------------------------------------------ */
if ($action === 'payment_quote') {
    $pawnId = (int)($_POST['pawn_id'] ?? 0);
    $asOf = trim((string)($_POST['as_of_date'] ?? date('Y-m-d')));

    if (!dateValid($asOf)) {
        respond(false, 'Enter a valid calculation date.', [], 422);
    }

    try {
        $pawn = getPawn($conn, $businessId, $branchId, $pawnId);
    } catch (Throwable $e) {
        respond(false, $e->getMessage(), [], 404);
    }

    $from = (string)(($pawn['last_interest_paid_upto'] ?? '') ?: $pawn['pawn_date']);

    if ($asOf < $from) {
        respond(false, 'Calculation date cannot be before the last paid date.', [], 422);
    }

    $calc = calculateInterest($pawn, $from, $asOf);
    $penalty = calculatePenalty($pawn, $asOf);

    $interestTotal = $calc['interest'] + $penalty['amount'];

    respond(true, '', [
        'pawn' => $pawn,
        'from_date' => $from,
        'to_date' => $asOf,
        'calculation_days' => $calc['days'],
        'calculation_months' => $calc['months'],
        'interest_amount' => $calc['interest'],
        'penalty_amount' => $penalty['amount'],
        'overdue_days' => $penalty['days'],
        'total_interest_due' => $interestTotal,
        'total_closure_due' => $interestTotal + (float)$pawn['balance_principal'],
    ]);
}

/* ---------------------------------------------------------
 * PAYMENT COLLECTION / SETTLEMENT
 * ------------------------------------------------------ */
if ($action === 'payment_collect') {
    $pawnId = (int)($_POST['pawn_id'] ?? 0);
    $date = trim((string)($_POST['payment_date'] ?? date('Y-m-d')));

    $principal = max(0, (float)($_POST['principal_amount'] ?? 0));
    $interest = max(0, (float)($_POST['interest_amount'] ?? 0));
    $penalty = max(0, (float)($_POST['penalty_amount'] ?? 0));
    $other = max(0, (float)($_POST['other_charges'] ?? 0));

    $method = (int)($_POST['payment_method_id'] ?? 0);
    $reference = trim((string)($_POST['reference_no'] ?? ''));
    $remarks = trim((string)($_POST['remarks'] ?? ''));

    $isClosure = !empty($_POST['is_closure']);
    $releasedTo = trim((string)($_POST['released_to'] ?? ''));

    if (!dateValid($date)) {
        respond(false, 'Enter a valid payment date.', [], 422);
    }

    if ($method <= 0) {
        respond(false, 'Select a payment method.', [], 422);
    }

    if (($principal + $interest + $penalty + $other) <= 0) {
        respond(false, 'Enter at least one payment amount.', [], 422);
    }

    try {
        $pawn = getPawn($conn, $businessId, $branchId, $pawnId);
    } catch (Throwable $e) {
        respond(false, $e->getMessage(), [], 404);
    }

    if (!in_array((string)$pawn['status'], ['Active', 'Partially Paid'], true)) {
        respond(false, 'This pawn is not open for payment.', [], 422);
    }

    $currentBalance = max(0, (float)$pawn['balance_principal']);

    if ($principal > $currentBalance + 0.01) {
        respond(false, 'Principal payment exceeds the outstanding principal.', [], 422);
    }

    if ($isClosure && abs($principal - $currentBalance) > 0.01) {
        respond(false, 'Full settlement must include the complete outstanding principal.', [], 422);
    }

    if ($isClosure && $releasedTo === '') {
        respond(false, 'Enter the name of the person receiving the pawn items.', [], 422);
    }

    $total = round($principal + $interest + $penalty + $other, 2);

    if ($isClosure) {
        $paymentType = 'Full Settlement';
    } elseif ($principal > 0 && ($interest + $penalty + $other) > 0) {
        $paymentType = 'Part Payment';
    } elseif ($principal > 0) {
        $paymentType = 'Principal Only';
    } else {
        $paymentType = 'Interest Only';
    }

    $conn->begin_transaction();

    try {
        $receipt = documentNumber(
            $conn,
            $businessId,
            $branchId,
            'pawn_payment_receipt',
            $date,
            true
        );

        /*
         * IMPORTANT:
         * 16 placeholders below => exactly 16 bind variables.
         *
         * Types:
         * i  business_id
         * i  branch_id
         * i  pawn_entry_id
         * s  receipt_no
         * s  payment_date
         * d  principal_amount
         * d  interest_amount
         * d  penalty_amount
         * d  other_charges
         * d  total_amount
         * s  payment_type
         * i  payment_method_id
         * s  reference_no
         * s  remarks
         * i  is_closure
         * i  created_by
         *
         * Type string: iiissdddddsissii
         */
        $stmt = $conn->prepare(
            'INSERT INTO pawn_payments
                (
                    business_id,
                    branch_id,
                    pawn_entry_id,
                    receipt_no,
                    payment_date,
                    principal_amount,
                    interest_amount,
                    penalty_amount,
                    other_charges,
                    total_amount,
                    payment_type,
                    payment_method_id,
                    reference_no,
                    remarks,
                    is_closure,
                    created_by,
                    created_at
                )
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())'
        );

        if (!$stmt) {
            throw new RuntimeException('Unable to prepare pawn payment: ' . $conn->error);
        }

        $closureFlag = $isClosure ? 1 : 0;

        $stmt->bind_param(
            'iiissdddddsissii',
            $businessId,
            $branchId,
            $pawnId,
            $receipt,
            $date,
            $principal,
            $interest,
            $penalty,
            $other,
            $total,
            $paymentType,
            $method,
            $reference,
            $remarks,
            $closureFlag,
            $userId
        );

        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Unable to save pawn payment: ' . $err);
        }

        $paymentId = (int)$stmt->insert_id;
        $stmt->close();

        if (tableExists($conn, 'pawn_payment_splits')) {
            $stmt = $conn->prepare(
                'INSERT INTO pawn_payment_splits
                    (pawn_payment_id,payment_method_id,amount,reference_no)
                 VALUES (?,?,?,?)'
            );

            if ($stmt) {
                $stmt->bind_param(
                    'iids',
                    $paymentId,
                    $method,
                    $total,
                    $reference
                );

                if (!$stmt->execute()) {
                    $err = $stmt->error;
                    $stmt->close();
                    throw new RuntimeException('Unable to save payment split: ' . $err);
                }

                $stmt->close();
            }
        }

        $newBalance = max(0, $currentBalance - $principal);

        if ($isClosure) {
            $newStatus = 'Closed';
            $closureDate = $date;
        } else {
            $newStatus = $newBalance < (float)$pawn['principal_amount']
                ? 'Partially Paid'
                : 'Active';
            $closureDate = null;
        }

        $updateColumns = [
            'total_principal_paid=COALESCE(total_principal_paid,0)+?',
            'total_interest_collected=COALESCE(total_interest_collected,0)+?',
            'balance_principal=?',
            'status=?',
        ];

        $updateTypes = 'ddds';
        $updateValues = [
            $principal,
            $interest,
            $newBalance,
            $newStatus,
        ];

        if (hasColumn($conn, 'pawn_entries', 'total_penalty_collected')) {
            $updateColumns[] = 'total_penalty_collected=COALESCE(total_penalty_collected,0)+?';
            $updateTypes .= 'd';
            $updateValues[] = $penalty;
        }

        if (hasColumn($conn, 'pawn_entries', 'total_other_charges_collected')) {
            $updateColumns[] = 'total_other_charges_collected=COALESCE(total_other_charges_collected,0)+?';
            $updateTypes .= 'd';
            $updateValues[] = $other;
        }

        if (hasColumn($conn, 'pawn_entries', 'closure_date')) {
            $updateColumns[] = 'closure_date=?';
            $updateTypes .= 's';
            $updateValues[] = $closureDate;
        }

        $updateSql = 'UPDATE pawn_entries
                      SET ' . implode(',', $updateColumns) . '
                      WHERE id=? AND business_id=?';

        $updateTypes .= 'ii';
        $updateValues[] = $pawnId;
        $updateValues[] = $businessId;

        $stmt = $conn->prepare($updateSql);

        if (!$stmt) {
            throw new RuntimeException('Unable to prepare pawn balance update: ' . $conn->error);
        }

        $bindArgs = [$updateTypes];
        foreach ($updateValues as $k => $v) {
            $bindArgs[] =& $updateValues[$k];
        }
        call_user_func_array([$stmt, 'bind_param'], $bindArgs);

        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Unable to update pawn balance: ' . $err);
        }

        $stmt->close();

        $releaseNo = '';

        if ($isClosure && tableExists($conn, 'pawn_releases')) {
            $releaseNo = documentNumber(
                $conn,
                $businessId,
                $branchId,
                'pawn_release',
                $date,
                true
            );

            /*
             * Build release INSERT dynamically so it works even if some
             * optional columns differ between installations.
             */
            $releaseCols = [
                'business_id',
                'branch_id',
                'pawn_entry_id',
                'pawn_payment_id',
                'release_no',
                'release_date',
                'principal_paid',
                'interest_paid',
                'penalty_paid',
                'other_charges',
                'total_paid',
                'released_to',
                'released_by',
            ];

            $releaseVals = [
                $businessId,
                $branchId,
                $pawnId,
                $paymentId,
                $releaseNo,
                $date,
                $principal,
                $interest,
                $penalty,
                $other,
                $total,
                $releasedTo,
                $userId,
            ];

            $releaseTypes = 'iiiissddddds i';
            $releaseTypes = str_replace(' ', '', $releaseTypes);

            if (hasColumn($conn, 'pawn_releases', 'identity_verified')) {
                $releaseCols[] = 'identity_verified';
                $releaseVals[] = 0;
                $releaseTypes .= 'i';
            }

            if (hasColumn($conn, 'pawn_releases', 'item_handover_status')) {
                $releaseCols[] = 'item_handover_status';
                $releaseVals[] = 'Pending';
                $releaseTypes .= 's';
            }

            if (hasColumn($conn, 'pawn_releases', 'remarks')) {
                $releaseCols[] = 'remarks';
                $releaseVals[] = $remarks;
                $releaseTypes .= 's';
            }

            $placeholders = array_fill(0, count($releaseCols), '?');

            if (hasColumn($conn, 'pawn_releases', 'created_at')) {
                $releaseCols[] = 'created_at';
                $placeholders[] = 'NOW()';
            }

            $stmt = $conn->prepare(
                'INSERT INTO pawn_releases (' . implode(',', $releaseCols) . ')
                 VALUES (' . implode(',', $placeholders) . ')'
            );

            if (!$stmt) {
                throw new RuntimeException('Unable to prepare release record: ' . $conn->error);
            }

            if (strlen($releaseTypes) !== count($releaseVals)) {
                throw new RuntimeException(
                    'Release bind mismatch: ' . strlen($releaseTypes)
                    . ' types for ' . count($releaseVals) . ' values.'
                );
            }

            $bindArgs = [$releaseTypes];
            foreach ($releaseVals as $k => $v) {
                $bindArgs[] =& $releaseVals[$k];
            }
            call_user_func_array([$stmt, 'bind_param'], $bindArgs);

            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new RuntimeException('Unable to create release record: ' . $err);
            }

            $releaseId = (int)$stmt->insert_id;
            $stmt->close();

            actionLog(
                $conn,
                $businessId,
                $branchId,
                $pawnId,
                'Released',
                'pawn_releases',
                $releaseId,
                'Created pending item handover ' . $releaseNo,
                $userId
            );
        }

        actionLog(
            $conn,
            $businessId,
            $branchId,
            $pawnId,
            $isClosure ? 'Closed' : 'Part Payment',
            'pawn_payments',
            $paymentId,
            'Saved pawn payment ' . $receipt,
            $userId
        );

        $conn->commit();

        respond(
            true,
            $isClosure
                ? 'Pawn fully settled. Release record created.'
                : 'Pawn payment saved successfully.',
            [
                'receipt_no' => $receipt,
                'release_no' => $releaseNo,
                'payment_id' => $paymentId,
                'pawn_id' => $pawnId,
                'pawn_no' => (string)($pawn['pawn_no'] ?? ''),
                'customer_name' => (string)($pawn['customer_name'] ?? ''),
                'mobile' => (string)($pawn['mobile'] ?? ''),
                'payment_date' => $date,
                'principal_amount' => $principal,
                'interest_amount' => $interest,
                'penalty_amount' => $penalty,
                'other_charges' => $other,
                'total_amount' => $total,
                'balance_principal' => $newBalance,
                'payment_type' => $paymentType,
                'is_closure' => $closureFlag,
                'reference_no' => $reference,
            ]
        );
    } catch (Throwable $e) {
        $conn->rollback();
        respond(false, $e->getMessage(), [], 500);
    }
}

respond(false, 'Invalid action.', [], 400);
