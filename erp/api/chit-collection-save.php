<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));
header('Content-Type: application/json; charset=utf-8');

function out(bool $success, string $message, array $extra = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode(
        array_merge(['success' => $success, 'message' => $message], $extra),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

$root = dirname(__DIR__);

foreach ([
    $root . '/config/config.php',
    $root . '/config.php',
    $root . '/includes/config.php',
    $root . '/super-admin/includes/config.php'
] as $file) {
    if (is_file($file)) {
        require_once $file;
        break;
    }
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    out(false, 'Database configuration is not available.', [], 500);
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn->set_charset('utf8mb4');

if (empty($_SESSION['user_id'])) {
    out(false, 'Session expired.', [], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    out(false, 'Invalid request method.', [], 405);
}

if (!hash_equals(
    (string)($_SESSION['chit_collection_csrf'] ?? ''),
    (string)($_POST['csrf_token'] ?? '')
)) {
    out(false, 'Invalid or expired request token.', [], 419);
}

$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? ($_SESSION['default_branch_id'] ?? 0));
$userId = (int)($_SESSION['user_id'] ?? 0);
$action = trim((string)($_POST['action'] ?? 'save'));

if ($businessId <= 0 || $branchId <= 0) {
    out(false, 'A valid business and branch are required.', [], 403);
}

function validDate(string $date): bool
{
    $parsed = DateTime::createFromFormat('Y-m-d', $date);
    return $parsed && $parsed->format('Y-m-d') === $date;
}

function columnExists(mysqli $conn, string $table, string $column): bool
{
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    return $result && $result->num_rows > 0;
}

function loadGoldRate(
    mysqli $conn,
    int $businessId,
    int $branchId,
    int $metalId,
    string $collectionDate
): array {
    $endOfDay = $collectionDate . ' 23:59:59';

    $stmt = $conn->prepare(
        "SELECT mr.id,mr.metal_id,mr.purity,mr.rate_per_gram,mr.effective_from,
                m.metal_code,m.metal_name
         FROM metal_rates mr
         INNER JOIN metals m
            ON m.id=mr.metal_id
           AND m.business_id=mr.business_id
         WHERE mr.business_id=?
           AND mr.metal_id=?
           AND m.is_active=1
           AND (UPPER(m.metal_code) LIKE '%GOLD%' OR UPPER(m.metal_name) LIKE '%GOLD%')
           AND mr.effective_from<=?
           AND (mr.branch_id=? OR mr.branch_id IS NULL)
         ORDER BY
            CASE WHEN mr.branch_id=? THEN 0 ELSE 1 END,
            mr.effective_from DESC,
            mr.id DESC
         LIMIT 1"
    );

    if (!$stmt) {
        throw new RuntimeException('Unable to prepare gold rate query: ' . $conn->error);
    }

    $stmt->bind_param(
        'iisii',
        $businessId,
        $metalId,
        $endOfDay,
        $branchId,
        $branchId
    );

    if (!$stmt->execute()) {
        throw new RuntimeException('Unable to load gold rate: ' . $stmt->error);
    }

    $rate = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$rate || (float)$rate['rate_per_gram'] <= 0) {
        throw new RuntimeException(
            'No gold rate is available for the selected metal on ' .
            date('d-m-Y', strtotime($collectionDate)) . '.'
        );
    }

    return $rate;
}

$memberId = (int)($_POST['member_id'] ?? 0);
$groupId = (int)($_POST['group_id'] ?? 0);
$date = trim((string)($_POST['collection_date'] ?? ''));

if ($action === 'gold_rate_preview') {
    $goldMetalId = (int)($_POST['gold_metal_id'] ?? 0);

    if ($memberId <= 0 || $groupId <= 0 || $goldMetalId <= 0) {
        out(false, 'Member, group and gold metal are required.', [], 422);
    }

    if (!validDate($date)) {
        out(false, 'Valid collection date is required.', [], 422);
    }

    $stmt = $conn->prepare(
        "SELECT cg.chit_type
         FROM chit_members cm
         INNER JOIN chit_groups cg ON cg.id=cm.chit_group_id
         WHERE cm.id=? AND cm.chit_group_id=? AND cm.business_id=?
         LIMIT 1"
    );

    if (!$stmt) {
        out(false, 'Unable to validate chit group.', [], 500);
    }

    $stmt->bind_param('iii', $memberId, $groupId, $businessId);
    $stmt->execute();
    $group = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$group) {
        out(false, 'Chit member or group was not found.', [], 404);
    }

    if (($group['chit_type'] ?? '') !== 'Gold') {
        out(false, 'Gold-rate calculation applies only to Gold chit groups.', [], 422);
    }

    try {
        $rate = loadGoldRate($conn, $businessId, $branchId, $goldMetalId, $date);

        out(true, 'Gold rate loaded.', [
            'metal_id' => (int)$rate['metal_id'],
            'metal_name' => (string)$rate['metal_name'],
            'purity' => (float)$rate['purity'],
            'rate_per_gram' => (float)$rate['rate_per_gram'],
            'effective_from' => (string)$rate['effective_from'],
            'effective_from_display' => date(
                'd-m-Y h:i A',
                strtotime((string)$rate['effective_from'])
            )
        ]);
    } catch (Throwable $error) {
        out(false, $error->getMessage(), [], 422);
    }
}

$installmentId = (int)($_POST['installment_id'] ?? 0);
$due = max(0, (float)($_POST['due_amount'] ?? 0));
$paid = max(0, (float)($_POST['paid_amount'] ?? 0));
$discount = max(0, (float)($_POST['discount_amount'] ?? 0));
$penalty = max(0, (float)($_POST['penalty_amount'] ?? 0));
$net = max(0, (float)($_POST['net_amount'] ?? 0));
$paymentMethodId = (int)($_POST['payment_method_id'] ?? 0);
$paymentMethodId = $paymentMethodId > 0 ? $paymentMethodId : null;
$referenceNo = trim((string)($_POST['reference_no'] ?? ''));
$remarks = trim((string)($_POST['remarks'] ?? ''));
$receiver = trim((string)($_POST['collection_receiver_name'] ?? ''));

if ($memberId <= 0 || $groupId <= 0 || $installmentId <= 0) {
    out(false, 'Required collection details are missing.', [], 422);
}

if (!validDate($date)) {
    out(false, 'Valid collection date is required.', [], 422);
}

$expectedNet = $paid + $penalty - $discount;
if (abs($expectedNet - $net) > 0.01) {
    $net = max(0, $expectedNet);
}

if ($paid <= 0) {
    out(false, 'Paid amount must be greater than zero.', [], 422);
}

if ($net <= 0) {
    out(false, 'Net received amount must be greater than zero.', [], 422);
}

$conn->begin_transaction();

try {
    $stmt = $conn->prepare(
        "SELECT cm.id,cm.customer_id,cm.status AS member_status,
                cg.id AS group_id,cg.status AS group_status,
                cg.chit_type,cg.installment_amount
         FROM chit_members cm
         INNER JOIN chit_groups cg ON cg.id=cm.chit_group_id
         WHERE cm.id=?
           AND cm.chit_group_id=?
           AND cm.business_id=?
         LIMIT 1
         FOR UPDATE"
    );

    if (!$stmt) {
        throw new RuntimeException('Unable to prepare member query: ' . $conn->error);
    }

    $stmt->bind_param('iii', $memberId, $groupId, $businessId);

    if (!$stmt->execute()) {
        throw new RuntimeException('Unable to validate chit member: ' . $stmt->error);
    }

    $member = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$member) {
        throw new RuntimeException('Chit member not found.');
    }

    if (
        $member['member_status'] !== 'Active' ||
        in_array($member['group_status'], ['Closed', 'Cancelled'], true)
    ) {
        throw new RuntimeException('Collection is not allowed for this member or group.');
    }

    $stmt = $conn->prepare(
        "SELECT id,installment_no,due_date
         FROM chit_installments
         WHERE id=?
           AND chit_group_id=?
           AND business_id=?
         LIMIT 1
         FOR UPDATE"
    );

    if (!$stmt) {
        throw new RuntimeException('Unable to prepare installment query: ' . $conn->error);
    }

    $stmt->bind_param('iii', $installmentId, $groupId, $businessId);
    $stmt->execute();
    $installment = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$installment) {
        throw new RuntimeException('Installment not found.');
    }

    $stmt = $conn->prepare(
        "SELECT id
         FROM chit_collections
         WHERE chit_member_id=?
           AND chit_installment_id=?
         LIMIT 1"
    );

    $stmt->bind_param('ii', $memberId, $installmentId);
    $stmt->execute();
    $duplicate = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($duplicate) {
        throw new RuntimeException('Payment for this installment is already collected.');
    }

    $goldMetalId = null;
    $goldPurity = null;
    $goldRatePerGram = null;
    $goldWeightGrams = null;
    $goldRateEffectiveFrom = null;

    if (($member['chit_type'] ?? '') === 'Gold') {
        foreach ([
            'gold_metal_id',
            'gold_purity',
            'gold_rate_per_gram',
            'gold_weight_grams',
            'gold_rate_effective_from'
        ] as $requiredColumn) {
            if (!columnExists($conn, 'chit_collections', $requiredColumn)) {
                throw new RuntimeException(
                    'Gold-credit database columns are missing. Run the supplied migration SQL first.'
                );
            }
        }

        $postedGoldMetalId = (int)($_POST['gold_metal_id'] ?? 0);

        if ($postedGoldMetalId <= 0) {
            throw new RuntimeException('Select the gold metal and purity.');
        }

        $rate = loadGoldRate(
            $conn,
            $businessId,
            $branchId,
            $postedGoldMetalId,
            $date
        );

        $goldMetalId = (int)$rate['metal_id'];
        $goldPurity = round((float)$rate['purity'], 4);
        $goldRatePerGram = round((float)$rate['rate_per_gram'], 2);

        /*
         * Only the paid installment amount earns gold.
         * Penalty is excluded and discount does not increase the gold credit.
         */
        $goldWeightGrams = round($paid / $goldRatePerGram, 6);
        $goldRateEffectiveFrom = (string)$rate['effective_from'];

        if ($goldWeightGrams <= 0) {
            throw new RuntimeException('Calculated gold weight must be greater than zero.');
        }
    }

    $prefix = 'CHR' . date('Ym', strtotime($date));
    $like = $prefix . '%';

    $stmt = $conn->prepare(
        "SELECT receipt_no
         FROM chit_collections
         WHERE business_id=?
           AND receipt_no LIKE ?
         ORDER BY id DESC
         LIMIT 1
         FOR UPDATE"
    );

    $stmt->bind_param('is', $businessId, $like);
    $stmt->execute();
    $last = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $sequence = 1;

    if ($last && preg_match('/(\d{4})$/', (string)$last['receipt_no'], $match)) {
        $sequence = (int)$match[1] + 1;
    }

    $receiptNo = $prefix . str_pad((string)$sequence, 4, '0', STR_PAD_LEFT);

    if (($member['chit_type'] ?? '') === 'Gold') {
        $stmt = $conn->prepare(
            "INSERT INTO chit_collections
                (business_id,branch_id,chit_group_id,chit_member_id,
                 chit_installment_id,receipt_no,collection_date,
                 due_amount,paid_amount,discount_amount,penalty_amount,net_amount,
                 payment_method_id,reference_no,remarks,collection_receiver_name,
                 gold_metal_id,gold_purity,gold_rate_per_gram,gold_weight_grams,
                 gold_rate_effective_from,created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );

        if (!$stmt) {
            throw new RuntimeException('Unable to prepare gold collection insert: ' . $conn->error);
        }

        $stmt->bind_param(
            'iiiiissdddddisssiddssi',
            $businessId,
            $branchId,
            $groupId,
            $memberId,
            $installmentId,
            $receiptNo,
            $date,
            $due,
            $paid,
            $discount,
            $penalty,
            $net,
            $paymentMethodId,
            $referenceNo,
            $remarks,
            $receiver,
            $goldMetalId,
            $goldPurity,
            $goldRatePerGram,
            $goldWeightGrams,
            $goldRateEffectiveFrom,
            $userId
        );
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO chit_collections
                (business_id,branch_id,chit_group_id,chit_member_id,
                 chit_installment_id,receipt_no,collection_date,
                 due_amount,paid_amount,discount_amount,penalty_amount,net_amount,
                 payment_method_id,reference_no,remarks,collection_receiver_name,
                 created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
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
            $date,
            $due,
            $paid,
            $discount,
            $penalty,
            $net,
            $paymentMethodId,
            $referenceNo,
            $remarks,
            $receiver,
            $userId
        );
    }

    if (!$stmt->execute()) {
        throw new RuntimeException('Unable to save collection: ' . $stmt->error);
    }

    $collectionId = (int)$stmt->insert_id;
    $stmt->close();

    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM chit_members
         WHERE chit_group_id=?
           AND business_id=?
           AND status='Active'"
    );

    $stmt->bind_param('ii', $groupId, $businessId);
    $stmt->execute();
    $activeMembers = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();

    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM chit_collections
         WHERE chit_group_id=?
           AND chit_installment_id=?
           AND business_id=?"
    );

    $stmt->bind_param('iii', $groupId, $installmentId, $businessId);
    $stmt->execute();
    $paidMembers = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();

    if ($activeMembers > 0 && $paidMembers >= $activeMembers) {
        $stmt = $conn->prepare(
            "UPDATE chit_installments
             SET status='Closed'
             WHERE id=?
               AND chit_group_id=?
               AND business_id=?"
        );

        $stmt->bind_param('iii', $installmentId, $groupId, $businessId);
        $stmt->execute();
        $stmt->close();
    }

    $conn->commit();

    $result = [
        'collection_id' => $collectionId,
        'receipt_no' => $receiptNo
    ];

    if ($goldWeightGrams !== null) {
        $result['gold_metal_id'] = $goldMetalId;
        $result['gold_purity'] = $goldPurity;
        $result['gold_rate_per_gram'] = $goldRatePerGram;
        $result['gold_weight_grams'] = $goldWeightGrams;
        $result['gold_rate_effective_from'] = $goldRateEffectiveFrom;
    }

    out(true, 'Payment collected successfully.', $result);
} catch (Throwable $error) {
    $conn->rollback();
    out(false, $error->getMessage(), [], 500);
}
