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
        (string)($_SESSION['pawn_auction_csrf'] ?? ''),
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

function pawnItemForeignKey(mysqli $conn): string
{
    return hasColumn($conn, 'pawn_items', 'pawn_entry_id')
        ? 'pawn_entry_id'
        : 'pawn_id';
}

function pawnPaymentForeignKey(mysqli $conn): string
{
    return hasColumn($conn, 'pawn_payments', 'pawn_entry_id')
        ? 'pawn_entry_id'
        : 'pawn_id';
}

function pawnInterestForeignKey(mysqli $conn): string
{
    return hasColumn($conn, 'pawn_interest_collections', 'pawn_entry_id')
        ? 'pawn_entry_id'
        : 'pawn_id';
}

function pawnAuctionForeignKey(mysqli $conn): string
{
    return hasColumn($conn, 'pawn_auctions', 'pawn_entry_id')
        ? 'pawn_entry_id'
        : 'pawn_id';
}

function generateAuctionNo(
    mysqli $conn,
    int $businessId
): string {
    $prefix = 'AUC' . date('Ym');
    $like = $prefix . '%';
    $next = 1;

    $stmt = $conn->prepare(
        "SELECT auction_no
         FROM pawn_auctions
         WHERE business_id = ?
           AND auction_no LIKE ?
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
                preg_replace('/\D/', '', (string)$row['auction_no']),
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

function loadItems(
    mysqli $conn,
    int $businessId,
    int $pawnId
): array {
    if (!tableExists($conn, 'pawn_items')) {
        return [];
    }

    $foreignKey = pawnItemForeignKey($conn);
    $metalJoin = '';
    $metalSelect = "'' AS metal_name";

    if (
        tableExists($conn, 'metals') &&
        hasColumn($conn, 'pawn_items', 'metal_id')
    ) {
        $metalJoin = ' LEFT JOIN metals m ON m.id = pi.metal_id';
        $metalSelect = "COALESCE(m.metal_name,'') AS metal_name";
    }

    $stmt = $conn->prepare(
        "SELECT
            pi.*,
            {$metalSelect}
         FROM pawn_items pi
         {$metalJoin}
         WHERE pi.business_id = ?
           AND pi.`{$foreignKey}` = ?
         ORDER BY pi.id"
    );

    if (!$stmt) {
        throw new RuntimeException(
            'Unable to prepare pawn items: ' . $conn->error
        );
    }

    $stmt->bind_param('ii', $businessId, $pawnId);
    $stmt->execute();
    $result = $stmt->get_result();

    $items = [];

    while ($row = $result->fetch_assoc()) {
        $items[] = $row;
    }

    $stmt->close();

    return $items;
}

function calculateValuation(
    array $pawn,
    array $items
): array {
    $totalGross = 0.0;
    $totalNet = 0.0;
    $totalEstimated = 0.0;

    foreach ($items as $item) {
        $totalGross += (float)($item['gross_weight'] ?? 0);
        $totalNet += (float)($item['net_weight'] ?? 0);
        $totalEstimated += (float)($item['estimated_value'] ?? 0);
    }

    if ($totalEstimated > 0) {
        $estimatedAuction = $totalEstimated * 0.70;
    } else {
        $estimatedAuction = max(
            0,
            (float)($pawn['balance_principal'] ?? 0)
        );
    }

    return [
        'total_gross_weight' => round($totalGross, 3),
        'total_net_weight' => round($totalNet, 3),
        'total_estimated_value' => round($totalEstimated, 2),
        'estimated_auction_value' => round($estimatedAuction, 2),
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
        $foreignKey = pawnInterestForeignKey($conn);

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
    string $auctionNo
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
            'pawn.auction',
            'Create',
            'pawn_auctions',
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

    $description = 'Auctioned pawn using auction number ' . $auctionNo;
    $json = json_encode(
        ['auction_no' => $auctionNo],
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
    !tableExists($conn, 'pawn_auctions')
) {
    respond(false, 'Required pawn auction tables were not found.', [], 500);
}

if ($action === 'list_pawns') {
    $stmt = $conn->prepare(
        "SELECT
            pe.id,
            pe.pawn_no,
            pe.pawn_date,
            pe.due_date,
            pe.principal_amount,
            pe.balance_principal,
            pe.status,
            DATEDIFF(CURDATE(),pe.due_date) AS days_overdue,
            COALESCE(c.customer_name,'Unknown Customer') AS customer_name,
            c.mobile
         FROM pawn_entries pe
         LEFT JOIN customers c
            ON c.id = pe.customer_id
         WHERE pe.business_id = ?
           AND pe.status IN ('Active','Partially Paid')
           AND pe.balance_principal > 0
           AND pe.due_date IS NOT NULL
           AND pe.due_date < CURDATE()
         ORDER BY pe.due_date ASC, pe.id DESC"
    );

    if (!$stmt) {
        respond(
            false,
            'Unable to prepare overdue pawn list: ' . $conn->error,
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
        'Overdue pawns loaded.',
        ['pawns' => $pawns]
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
                'Only active or partially paid pawns can be auctioned.'
            );
        }

        if (empty($pawn['due_date'])) {
            respond(false, 'This pawn does not have a due date.');
        }

        $today = new DateTimeImmutable(date('Y-m-d'));
        $dueDate = new DateTimeImmutable((string)$pawn['due_date']);

        if ($dueDate >= $today) {
            respond(
                false,
                'This pawn is not overdue and cannot be auctioned.'
            );
        }

        $daysOverdue = (int)$dueDate->diff($today)->days;

        $items = loadItems(
            $conn,
            $businessId,
            $pawnId
        );

        $valuation = calculateValuation(
            $pawn,
            $items
        );

        $pawn['pawn_date_display'] =
            !empty($pawn['pawn_date'])
                ? date('d-m-Y', strtotime($pawn['pawn_date']))
                : '';

        $pawn['due_date_display'] =
            date('d-m-Y', strtotime($pawn['due_date']));

        $pawn['days_overdue'] = $daysOverdue;

        $history = loadCombinedHistory(
            $conn,
            $businessId,
            $pawnId
        );

        respond(
            true,
            'Pawn auction details loaded.',
            [
                'pawn' => $pawn,
                'items' => $items,
                'valuation' => $valuation,
                'history' => $history,
                'warning' => $daysOverdue < 30
                    ? 'This pawn is overdue by less than 30 days.'
                    : '',
            ]
        );
    } catch (Throwable $error) {
        respond(false, $error->getMessage(), [], 500);
    }
}

if ($action === 'save') {
    $pawnId = (int)($_POST['pawn_id'] ?? 0);
    $auctionDate = trim((string)($_POST['auction_date'] ?? ''));
    $buyerName = trim((string)($_POST['buyer_name'] ?? ''));
    $buyerContact = trim((string)($_POST['buyer_contact'] ?? ''));
    $auctionAmount = max(0, (float)($_POST['auction_amount'] ?? 0));
    $expensesAmount = max(0, (float)($_POST['expenses_amount'] ?? 0));
    $netAmount = max(0, (float)($_POST['net_amount'] ?? 0));
    $surplusAmount = max(0, (float)($_POST['surplus_amount'] ?? 0));
    $deficitAmount = max(0, (float)($_POST['deficit_amount'] ?? 0));
    $remarks = trim((string)($_POST['auction_remarks'] ?? ''));

    if ($pawnId <= 0 || $auctionDate === '') {
        respond(false, 'Pawn and auction date are required.');
    }

    if ($auctionAmount <= 0) {
        respond(false, 'Auction amount must be greater than zero.');
    }

    $expectedNet = max(0, $auctionAmount - $expensesAmount);

    if (abs($expectedNet - $netAmount) > 0.02) {
        respond(false, 'The net auction amount is incorrect.');
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
                'Only active or partially paid pawns can be auctioned.'
            );
        }

        if (empty($pawn['due_date'])) {
            throw new RuntimeException(
                'This pawn does not have a due date.'
            );
        }

        $today = new DateTimeImmutable($auctionDate);
        $dueDate = new DateTimeImmutable((string)$pawn['due_date']);

        if ($dueDate >= $today) {
            throw new RuntimeException(
                'Auction date must be after the pawn due date.'
            );
        }

        $outstanding = max(
            0,
            (float)($pawn['balance_principal'] ?? 0)
        );

        $serverSurplus = max(0, $netAmount - $outstanding);
        $serverDeficit = max(0, $outstanding - $netAmount);

        if (abs($serverSurplus - $surplusAmount) > 0.02) {
            throw new RuntimeException(
                'The surplus amount is incorrect.'
            );
        }

        if (abs($serverDeficit - $deficitAmount) > 0.02) {
            throw new RuntimeException(
                'The deficit amount is incorrect.'
            );
        }

        $auctionNo = generateAuctionNo(
            $conn,
            $businessId
        );

        $foreignKey = pawnAuctionForeignKey($conn);

        $columns = [
            'business_id',
            $foreignKey,
            'auction_no',
            'auction_date',
            'auction_amount',
        ];
        $placeholders = ['?', '?', '?', '?', '?'];
        $types = 'iissd';
        $values = [
            $businessId,
            $pawnId,
            $auctionNo,
            $auctionDate,
            $auctionAmount,
        ];

        $optionalFields = [
            'branch_id' => ['i', $branchId],
            'expenses_amount' => ['d', $expensesAmount],
            'net_amount' => ['d', $netAmount],
            'surplus_amount' => ['d', $serverSurplus],
            'deficit_amount' => ['d', $serverDeficit],
            'buyer_name' => ['s', $buyerName],
            'buyer_contact' => ['s', $buyerContact],
            'remarks' => ['s', $remarks],
            'created_by' => ['i', $userId],
        ];

        foreach ($optionalFields as $column => [$type, $value]) {
            if (hasColumn($conn, 'pawn_auctions', $column)) {
                $columns[] = $column;
                $placeholders[] = '?';
                $types .= $type;
                $values[] = $value;
            }
        }

        if (hasColumn($conn, 'pawn_auctions', 'created_at')) {
            $columns[] = 'created_at';
            $placeholders[] = 'NOW()';
        }

        $stmt = $conn->prepare(
            'INSERT INTO pawn_auctions (' .
            implode(', ', $columns) .
            ') VALUES (' .
            implode(', ', $placeholders) .
            ')'
        );

        if (!$stmt) {
            throw new RuntimeException(
                'Unable to prepare pawn auction: ' . $conn->error
            );
        }

        bindDynamic(
            $stmt,
            $types,
            $values
        );

        if (!$stmt->execute()) {
            throw new RuntimeException(
                'Unable to save pawn auction: ' . $stmt->error
            );
        }

        $auctionId = (int)$stmt->insert_id;
        $stmt->close();

        $updateParts = [
            "status = 'Auctioned'",
        ];

        $updateTypes = '';
        $updateValues = [];

        if (hasColumn($conn, 'pawn_entries', 'auctioned_at')) {
            $updateParts[] = 'auctioned_at = NOW()';
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
                'Auctioned on ' .
                $auctionDate .
                '. Auction No: ' .
                $auctionNo .
                '. Amount: ₹' .
                number_format($auctionAmount, 2) .
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
                'Unable to prepare pawn status update: ' . $conn->error
            );
        }

        bindDynamic(
            $stmt,
            $updateTypes,
            $updateValues
        );

        if (!$stmt->execute()) {
            throw new RuntimeException(
                'Unable to update pawn status: ' . $stmt->error
            );
        }

        $stmt->close();

        addAudit(
            $conn,
            $businessId,
            $branchId,
            $userId,
            $auctionId,
            $auctionNo
        );

        $conn->commit();

        respond(
            true,
            'Pawn auction saved successfully.',
            [
                'auction_id' => $auctionId,
                'auction_no' => $auctionNo,
                'status' => 'Auctioned',
                'surplus_amount' => $serverSurplus,
                'deficit_amount' => $serverDeficit,
            ]
        );
    } catch (Throwable $error) {
        $conn->rollback();
        respond(false, $error->getMessage(), [], 500);
    }
}

respond(false, 'Invalid action.', [], 400);
