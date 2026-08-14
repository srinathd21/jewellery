<?php
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/_common.php';

$pawnEntryId = max(0, (int) ($_GET['id'] ?? 0));
$businessId = (int) ($_SESSION['business_id'] ?? 0);

if ($pawnEntryId <= 0 || $businessId <= 0) {
    header('Location: pawn-manage.php');
    exit;
}

function pawnViewTableExists(mysqli $conn, string $table): bool
{
    $safeTable = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safeTable}'");
    return $result && $result->num_rows > 0;
}

function pawnViewColumnExists(mysqli $conn, string $table, string $column): bool
{
    $safeTable = $conn->real_escape_string($table);
    $safeColumn = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    return $result && $result->num_rows > 0;
}

function pawnViewMoney($value): string
{
    return number_format((float) ($value ?? 0), 2, '.', ',');
}

function pawnViewDate($value): string
{
    if (empty($value)) {
        return '—';
    }
    $time = strtotime((string) $value);
    return $time ? date('d-m-Y', $time) : (string) $value;
}

function pawnViewWhatsAppNumber($mobile): string
{
    $number = preg_replace('/\D+/', '', (string) $mobile);

    if (strlen($number) === 10) {
        $number = '91' . $number;
    } elseif (strlen($number) === 11 && substr($number, 0, 1) === '0') {
        $number = '91' . substr($number, 1);
    }

    return $number;
}

function pawnViewAbsoluteUrl(string $path): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

    $scheme = $https ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $scriptDir = rtrim(
        str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))),
        '/'
    );

    return $scheme . '://' . $host
        . ($scriptDir ? $scriptDir : '')
        . '/' . ltrim($path, '/');
}

function pawnViewRoundInterest(float $amount, string $method): float
{
    switch ($method) {
        case 'Nearest Rupee':
            return round($amount);
        case 'Ceil Rupee':
            return ceil($amount);
        case 'Floor Rupee':
            return floor($amount);
        default:
            return round($amount, 2);
    }
}

function pawnViewInterestMultiplier(array $pawn): float
{
    $period = (string) ($pawn['interest_period'] ?? 'Monthly');
    $cycle = (string) ($pawn['interest_collection_cycle'] ?? 'Monthly');
    $customMonths = max(1, (int) ($pawn['interest_cycle_months'] ?? 1));

    if ($cycle === 'At Closure') {
        return 1.0;
    }

    $months = 1;
    switch ($cycle) {
        case 'Quarterly':
            $months = 3;
            break;
        case 'Half-Yearly':
            $months = 6;
            break;
        case 'Yearly':
            $months = 12;
            break;
        case 'Custom':
            $months = $customMonths;
            break;
    }

    if ($period === 'Daily') {
        return $months * 30;
    }
    if ($period === 'Yearly') {
        return $months / 12;
    }
    return (float) $months;
}

function pawnViewCalculatedInterest(array $pawn): float
{
    $method = (string) ($pawn['interest_method'] ?? 'Simple');
    $base = $method === 'Flat'
        ? (float) ($pawn['principal_amount'] ?? 0)
        : (float) ($pawn['balance_principal'] ?? 0);

    $rate = max(0, (float) ($pawn['interest_percent'] ?? 0));
    $amount = $base * ($rate / 100) * pawnViewInterestMultiplier($pawn);

    return pawnViewRoundInterest(
        $amount,
        (string) ($pawn['interest_rounding_method'] ?? 'Nearest Rupee')
    );
}

$pawnProofSelect = "'' AS uploaded_id_proof_image";

foreach (['id_proof_image', 'id_proof_image_path', 'proof_image', 'proof_image_path'] as $proofColumn) {
    if (pawnViewColumnExists($conn, 'pawn_entries', $proofColumn)) {
        $pawnProofSelect = "COALESCE(p.`{$proofColumn}`, '') AS uploaded_id_proof_image";
        break;
    }
}

if ($pawnProofSelect === "'' AS uploaded_id_proof_image") {
    foreach (['id_proof_image', 'id_proof_image_path', 'proof_image', 'proof_image_path'] as $proofColumn) {
        if (pawnViewColumnExists($conn, 'customers', $proofColumn)) {
            $pawnProofSelect = "COALESCE(c.`{$proofColumn}`, '') AS uploaded_id_proof_image";
            break;
        }
    }
}

$pawnSql = "SELECT
                p.*,
                {$pawnProofSelect},
                COALESCE(c.customer_name, '') AS customer_name,
                COALESCE(c.customer_code, '') AS customer_code,
                COALESCE(c.mobile, '') AS mobile,
                COALESCE(c.alternate_mobile, '') AS alternate_mobile,
                COALESCE(c.email, '') AS email,
                COALESCE(c.address_line1, '') AS address_line1,
                COALESCE(c.address_line2, '') AS address_line2,
                COALESCE(c.city, '') AS city,
                COALESCE(c.state, '') AS state,
                COALESCE(c.pincode, '') AS pincode,
                COALESCE(pc.category_name, '') AS category_name,
                COALESCE(pc.category_code, '') AS category_code,
                COALESCE(b.branch_name, '') AS branch_name,
                COALESCE(pm.method_name, '') AS disbursement_method
            FROM pawn_entries p
            LEFT JOIN customers c
                ON c.id = p.customer_id
               AND c.business_id = p.business_id
            LEFT JOIN pawn_categories pc
                ON pc.id = p.pawn_category_id
               AND pc.business_id = p.business_id
            LEFT JOIN branches b
                ON b.id = p.branch_id
               AND b.business_id = p.business_id
            LEFT JOIN payment_methods pm
                ON pm.id = p.disbursement_payment_method_id
               AND pm.business_id = p.business_id
            WHERE p.id = ?
              AND p.business_id = ?
            LIMIT 1";

$stmt = $conn->prepare($pawnSql);
if (!$stmt) {
    die('Unable to load pawn entry: ' . e($conn->error));
}
$stmt->bind_param('ii', $pawnEntryId, $businessId);
$stmt->execute();
$pawn = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$pawn) {
    http_response_code(404);
    die('Pawn entry not found for Pawn ID ' . e($pawnEntryId) . ' and Business ID ' . e($businessId) . '.');
}

$items = [];
$itemStmt = $conn->prepare(
    "SELECT pi.*, COALESCE(m.metal_name, '') AS metal_name
     FROM pawn_items pi
     LEFT JOIN metals m
       ON m.id = pi.metal_id
      AND m.business_id = pi.business_id
     WHERE pi.pawn_entry_id = ?
       AND pi.business_id = ?
     ORDER BY pi.id"
);
if ($itemStmt) {
    $itemStmt->bind_param('ii', $pawnEntryId, $businessId);
    $itemStmt->execute();
    $result = $itemStmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $items[] = $row;
    }
    $itemStmt->close();
}

$interestCollections = [];
if (pawnViewTableExists($conn, 'pawn_interest_collections')) {
    $interestStmt = $conn->prepare(
        "SELECT pic.*, COALESCE(pm.method_name, '') AS method_name
         FROM pawn_interest_collections pic
         LEFT JOIN payment_methods pm
           ON pm.id = pic.payment_method_id
          AND pm.business_id = pic.business_id
         WHERE pic.pawn_entry_id = ?
           AND pic.business_id = ?
         ORDER BY pic.collection_date DESC, pic.id DESC"
    );
    if ($interestStmt) {
        $interestStmt->bind_param('ii', $pawnEntryId, $businessId);
        $interestStmt->execute();
        $result = $interestStmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $interestCollections[] = $row;
        }
        $interestStmt->close();
    }
}

$pawnPayments = [];
if (pawnViewTableExists($conn, 'pawn_payments')) {
    $paymentStmt = $conn->prepare(
        "SELECT pp.*, COALESCE(pm.method_name, '') AS method_name
         FROM pawn_payments pp
         LEFT JOIN payment_methods pm
           ON pm.id = pp.payment_method_id
          AND pm.business_id = pp.business_id
         WHERE pp.pawn_entry_id = ?
           AND pp.business_id = ?
         ORDER BY pp.payment_date DESC, pp.id DESC"
    );
    if ($paymentStmt) {
        $paymentStmt->bind_param('ii', $pawnEntryId, $businessId);
        $paymentStmt->execute();
        $result = $paymentStmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $pawnPayments[] = $row;
        }
        $paymentStmt->close();
    }
}

$address = implode(', ', array_filter([
    $pawn['address_line1'] ?? '',
    $pawn['address_line2'] ?? '',
    $pawn['city'] ?? '',
    $pawn['state'] ?? '',
    $pawn['pincode'] ?? '',
]));

$uploadedProof = trim((string) ($pawn['uploaded_id_proof_image'] ?? ''));

/*
 * Fallback for records where the proof path was stored only in remarks.
 * Example:
 * ID Proof File: uploads/pawn-proofs/2026/08/proof_xxx.png
 */
if ($uploadedProof === '' && !empty($pawn['remarks'])) {
    $remarksText = (string) $pawn['remarks'];

    if (preg_match(
        '~(?:^|\||\r?\n)\s*ID Proof File:\s*([^|\r\n]+)~i',
        $remarksText,
        $proofMatch
    )) {
        $uploadedProof = trim((string) ($proofMatch[1] ?? ''));
    }
}
$proofUrl = '';
$proofIsPdf = false;

if ($uploadedProof !== '') {
    $proofIsPdf = strtolower((string) pathinfo(parse_url($uploadedProof, PHP_URL_PATH) ?: $uploadedProof, PATHINFO_EXTENSION)) === 'pdf';

    if (preg_match('~^https?://~i', $uploadedProof) || substr($uploadedProof, 0, 5) === 'data:') {
        $proofUrl = $uploadedProof;
    } else {
        $cleanProofPath = ltrim(str_replace('\\', '/', $uploadedProof), '/');

        $scriptDir = rtrim(
            str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))),
            '/'
        );

        $proofUrl = ($scriptDir ? $scriptDir : '') . '/' . $cleanProofPath;
    }
}

$currentInterestPercent = isset($pawn['current_interest_percent']) && $pawn['current_interest_percent'] !== null
    ? (float) $pawn['current_interest_percent']
    : (float) ($pawn['interest_percent'] ?? 0);
$initialInterestPercent = isset($pawn['initial_interest_percent']) && $pawn['initial_interest_percent'] !== null
    ? (float) $pawn['initial_interest_percent']
    : (float) ($pawn['interest_percent'] ?? 0);
$interestCycleType = (string) ($pawn['interest_due_cycle_type'] ?? '');
$interestCycleValue = max(1, (int) ($pawn['interest_due_cycle_value'] ?? 1));
$interestGraceDays = isset($pawn['interest_grace_days']) ? (int) $pawn['interest_grace_days'] : (int) ($pawn['grace_days'] ?? 0);
$nextDueDate = (string) ($pawn['next_interest_due_date'] ?? '');
$graceUntil = '';
if ($nextDueDate !== '') {
    try {
        $graceDate = new DateTime($nextDueDate);
        if ($interestGraceDays > 0) $graceDate->modify('+' . $interestGraceDays . ' days');
        $graceUntil = $graceDate->format('Y-m-d');
    } catch (Throwable $ignore) {
        $graceUntil = $nextDueDate;
    }
}
$cycleLabel = '—';
if ($interestCycleType === 'Calendar Month') $cycleLabel = $interestCycleValue === 1 ? 'Calendar month' : $interestCycleValue . ' calendar months';
elseif ($interestCycleType === 'Days') $cycleLabel = $interestCycleValue . ' day' . ($interestCycleValue === 1 ? '' : 's');
elseif ($interestCycleType === 'Months') $cycleLabel = $interestCycleValue . ' month' . ($interestCycleValue === 1 ? '' : 's');
elseif (!empty($pawn['interest_collection_cycle'])) $cycleLabel = (string) $pawn['interest_collection_cycle'];

$calculatedInterest = pawnViewRoundInterest(
    (float) ($pawn['balance_principal'] ?? 0) * ($currentInterestPercent / 100),
    (string) ($pawn['interest_rounding_method'] ?? 'Nearest Rupee')
);
$interestLabel = $cycleLabel . ' estimate';

$schemeName = '';
$currentRateLevel = '';
$nextInterestPercent = null;
if (pawnViewTableExists($conn, 'pawn_interest_schemes') && !empty($pawn['interest_scheme_id'])) {
    $s = $conn->prepare('SELECT scheme_name FROM pawn_interest_schemes WHERE id=? AND business_id=? LIMIT 1');
    if ($s) { $sid=(int)$pawn['interest_scheme_id']; $s->bind_param('ii',$sid,$businessId); $s->execute(); $r=$s->get_result()->fetch_assoc(); $schemeName=(string)($r['scheme_name']??''); $s->close(); }
}
if (pawnViewTableExists($conn, 'pawn_interest_rate_steps') && !empty($pawn['current_rate_step_id'])) {
    $s = $conn->prepare('SELECT level_no,next_level_no FROM pawn_interest_rate_steps WHERE id=? AND business_id=? LIMIT 1');
    if ($s) { $rid=(int)$pawn['current_rate_step_id']; $s->bind_param('ii',$rid,$businessId); $s->execute(); $r=$s->get_result()->fetch_assoc(); if($r){$currentRateLevel=(string)$r['level_no']; if($r['next_level_no']!==null && !empty($pawn['interest_scheme_id'])){ $n=$conn->prepare('SELECT rate_percent FROM pawn_interest_rate_steps WHERE scheme_id=? AND level_no=? AND business_id=? LIMIT 1'); if($n){$sid=(int)$pawn['interest_scheme_id']; $nl=(int)$r['next_level_no']; $n->bind_param('iii',$sid,$nl,$businessId); $n->execute(); $nr=$n->get_result()->fetch_assoc(); if($nr)$nextInterestPercent=(float)$nr['rate_percent']; $n->close(); } } } $s->close(); }
}

$bankCollateral = array();
if (pawnViewTableExists($conn, 'pawn_bank_loan_items') && pawnViewTableExists($conn, 'pawn_bank_loans') && pawnViewTableExists($conn, 'pawn_banks')) {
    $bs = $conn->prepare("SELECT pbli.*, pbl.bank_loan_no, pbl.principal_amount AS bank_principal_amount, pbl.balance_principal AS bank_balance_principal, pbl.bank_interest_percent, pbl.interest_payment_cycle, pbl.status AS bank_loan_status, pb.bank_name, pb.branch_name AS bank_branch_name FROM pawn_bank_loan_items pbli INNER JOIN pawn_bank_loans pbl ON pbl.id=pbli.bank_loan_id INNER JOIN pawn_banks pb ON pb.id=pbl.bank_id WHERE pbli.pawn_entry_id=? AND pbli.business_id=? ORDER BY pbli.id DESC");
    if($bs){$bs->bind_param('ii',$pawnEntryId,$businessId);$bs->execute();$br=$bs->get_result();while($rr=$br->fetch_assoc())$bankCollateral[]=$rr;$bs->close();}
}

$receiptRelativeUrl = 'pawn-receipt.php?id=' . $pawnEntryId
    . '&ref=' . rawurlencode((string) ($pawn['pawn_no'] ?? ''));
$receiptUrl = pawnViewAbsoluteUrl($receiptRelativeUrl);

$whatsappNumber = pawnViewWhatsAppNumber($pawn['mobile'] ?? '');
$whatsappMessage = "Dear " . (string) ($pawn['customer_name'] ?? 'Customer') . ",\n\n"
    . "Your pawn receipt is ready.\n"
    . "Pawn No: " . (string) ($pawn['pawn_no'] ?? '') . "\n"
    . "Principal Amount: ₹" . pawnViewMoney($pawn['principal_amount'] ?? 0) . "\n"
    . "Principal Paid: ₹" . pawnViewMoney($pawn['total_principal_paid'] ?? 0) . "\n"
    . "Balance Principal: ₹" . pawnViewMoney($pawn['balance_principal'] ?? 0) . "\n"
    . "Status: " . (string) ($pawn['status'] ?? '') . "\n\n"
    . "View / download your pawn receipt:\n"
    . $receiptUrl . "\n\n"
    . "Thank you,\n"
    . (string) $businessName;

$whatsappUrl = $whatsappNumber !== ''
    ? 'https://wa.me/' . $whatsappNumber . '?text=' . rawurlencode($whatsappMessage)
    : '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($businessName) ?> - <?= e($pawn['pawn_no']) ?></title>
    <?php include('includes/links.php'); require __DIR__ . '/_style.php'; ?>
    <style>
        .detail-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:6px}
        .detail-box{padding:8px 9px;border:1px solid var(--line);border-radius:9px;background:var(--card-bg);min-height:58px}
        .detail-box span{display:block;font-size:9px;color:var(--muted);text-transform:uppercase}
        .detail-box strong{display:block;margin-top:2px;font-size:11px;word-break:break-word}
        .detail-box.highlight{background:var(--primary-soft);border-color:color-mix(in srgb,var(--primary-dark) 22%,var(--line))}
        .detail-box.highlight strong{font-size:15px;color:var(--primary-dark)}
        .status-pill{display:inline-flex;padding:5px 9px;border-radius:999px;background:var(--primary-soft);color:var(--primary-dark);font-size:10px;font-weight:800}
        .compact-table{font-size:9px;margin:0}
        .compact-table th{font-size:8px;text-transform:uppercase;color:var(--muted);white-space:nowrap;padding:7px 8px}.compact-table td{padding:7px 8px}
        .empty-row{text-align:center;color:var(--muted);padding:24px!important}
        .btn-receipt{display:inline-flex;align-items:center;gap:6px}
        .btn-whatsapp{display:inline-flex;align-items:center;gap:6px;background:#25D366!important;color:#fff!important;border-color:#25D366!important}
        .btn-whatsapp:hover{background:#1fb85a!important;color:#fff!important}

        .proof-card{grid-column:1/-1;padding:12px;border:1px solid var(--line);border-radius:10px;background:var(--card-bg)}
        .proof-card .proof-label{display:block;font-size:9px;color:var(--muted);text-transform:uppercase;margin-bottom:8px}
        .proof-preview{display:flex;align-items:flex-start;gap:12px;flex-wrap:wrap}
        .proof-preview img{width:min(100%,280px);max-height:180px;object-fit:contain;border:1px solid var(--line);border-radius:10px;background:#fff;padding:4px}
        .proof-file-link{display:inline-flex;align-items:center;gap:6px;text-decoration:none;padding:8px 11px;border-radius:8px;border:1px solid var(--line);background:var(--primary-soft);color:var(--primary-dark);font-size:10px;font-weight:800}
        .proof-empty{font-size:11px;color:var(--muted)}
        @media(max-width:1000px){.detail-grid{grid-template-columns:repeat(2,1fr)}}
        @media(max-width:575px){.detail-grid{grid-template-columns:1fr}}
    </style>
</head>
<body>
<?php include('includes/sidebar.php'); ?>
<main class="app-main">
<?php include('includes/nav.php'); ?>
<div class="content-wrap">
    <div class="page-card mb-3">
        <div class="page-head">
            <div>
                <div class="page-title">Pawn Details - <?= e($pawn['pawn_no']) ?></div>
                <div class="small text-muted"><?= e($pawn['customer_name'] ?: 'Customer unavailable') ?> · <?= e($pawn['mobile'] ?: 'No mobile') ?> · <?= e($pawn['branch_name'] ?: 'Branch unavailable') ?></div>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <a href="pawn-manage.php" class="btn-soft">Back</a>
                <a href="<?= e($receiptRelativeUrl) ?>" class="btn-soft btn-receipt" target="_blank" rel="noopener">
                    <i class="fa-solid fa-receipt"></i> Receipt
                </a>
                <?php if ($whatsappUrl !== ''): ?>
                    <a href="<?= e($whatsappUrl) ?>" class="btn-soft btn-whatsapp" target="_blank" rel="noopener">
                        <i class="fa-brands fa-whatsapp"></i> WhatsApp
                    </a>
                <?php endif; ?>
                <a href="pawn-interest.php?pawn_id=<?= $pawnEntryId ?>" class="btn-soft">Collect Interest</a>
               
                <a href="pawn-edit.php?id=<?= $pawnEntryId ?>" class="btn-theme"><i class="fa-solid fa-pen"></i> Edit</a>
            </div>
        </div>
    </div>

    <div class="page-card mb-3">
        <div class="page-head"><div class="section-title">Pawn Summary</div></div>
        <div class="card-body-x">
            <div class="detail-grid">
                <div class="detail-box"><span>Status</span><strong><span class="status-pill"><?= e($pawn['status']) ?></span></strong></div>
                <div class="detail-box"><span>Pawn Date</span><strong><?= e(pawnViewDate($pawn['pawn_date'])) ?></strong></div>
                <div class="detail-box"><span>Due Date</span><strong><?= e($pawn['due_date'] ? pawnViewDate($pawn['due_date']) : 'At Closure') ?></strong></div>
                <div class="detail-box"><span>Category</span><strong><?= e($pawn['category_name'] ?: '—') ?></strong></div>
                <div class="detail-box highlight"><span>Principal Amount</span><strong>₹<?= pawnViewMoney($pawn['principal_amount']) ?></strong></div>
                <div class="detail-box"><span>Principal Paid</span><strong>₹<?= pawnViewMoney($pawn['total_principal_paid']) ?></strong></div>
                <div class="detail-box highlight"><span>Balance Principal</span><strong>₹<?= pawnViewMoney($pawn['balance_principal']) ?></strong></div>
                <div class="detail-box"><span>Interest Collected</span><strong>₹<?= pawnViewMoney($pawn['total_interest_collected']) ?></strong></div>
                <div class="detail-box"><span>Current Interest</span><strong><?= number_format($currentInterestPercent, 3) ?>%</strong><small class="text-muted"><?= e($cycleLabel) ?></small></div>
                <div class="detail-box highlight"><span>Cycle Interest Estimate</span><strong>₹<?= pawnViewMoney($calculatedInterest) ?></strong><small class="text-muted"><?= e($interestLabel) ?></small></div>
                <div class="detail-box"><span>Interest Scheme</span><strong><?= e($schemeName ?: 'Legacy / Manual') ?></strong></div>
                <div class="detail-box"><span>Rate Level</span><strong><?= e($currentRateLevel !== '' ? 'Level ' . $currentRateLevel : '—') ?></strong><small class="text-muted"><?= $nextInterestPercent !== null ? 'Next: ' . number_format($nextInterestPercent,3) . '%' : 'Final level' ?></small></div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-xl-8">
            <div class="page-card h-100">
                <div class="page-head"><div class="section-title">Pawn & Customer Information</div></div>
                <div class="card-body-x">
                    <div class="detail-grid">
                        <div class="detail-box"><span>Pawn Number</span><strong><?= e($pawn['pawn_no']) ?></strong></div>
                        <div class="detail-box"><span>Loan Type</span><strong><?= e($pawn['loan_type'] ?: 'General') ?></strong></div>
                        <div class="detail-box"><span>Branch</span><strong><?= e($pawn['branch_name'] ?: '—') ?></strong></div>
                        <div class="detail-box"><span>Category Code</span><strong><?= e($pawn['category_code'] ?: '—') ?></strong></div>
                        <div class="detail-box"><span>Customer</span><strong><?= e($pawn['customer_name'] ?: '—') ?></strong></div>
                        <div class="detail-box"><span>Customer Code</span><strong><?= e($pawn['customer_code'] ?: '—') ?></strong></div>
                        <div class="detail-box"><span>Mobile</span><strong><?= e($pawn['mobile'] ?: '—') ?></strong></div>
                        <div class="detail-box"><span>Alternate Mobile</span><strong><?= e($pawn['alternate_mobile'] ?: '—') ?></strong></div>
                        <div class="detail-box"><span>Email</span><strong><?= e($pawn['email'] ?: '—') ?></strong></div>
                        <div class="detail-box"><span>ID Proof Type</span><strong><?= e($pawn['id_proof_type'] ?: '—') ?></strong></div>
                        <div class="detail-box"><span>ID Proof Number</span><strong><?= e($pawn['id_proof_number'] ?: '—') ?></strong></div>
                        <div class="detail-box"><span>Address</span><strong><?= e($address ?: '—') ?></strong></div>

                        <div class="proof-card">
                            <span class="proof-label">Uploaded ID Proof</span>
                            <?php if ($proofUrl !== ''): ?>
                                <div class="proof-preview">
                                    <?php if ($proofIsPdf): ?>
                                        <a href="<?= e($proofUrl) ?>" class="proof-file-link" target="_blank" rel="noopener">
                                            <i class="fa-solid fa-file-pdf"></i> Open ID Proof PDF
                                        </a>
                                    <?php else: ?>
                                        <a href="<?= e($proofUrl) ?>" target="_blank" rel="noopener" title="Open full image">
                                            <img src="<?= e($proofUrl) ?>" alt="Uploaded ID proof">
                                        </a>
                                        <a href="<?= e($proofUrl) ?>" class="proof-file-link" target="_blank" rel="noopener">
                                            <i class="fa-solid fa-up-right-from-square"></i> Open Full Image
                                        </a>
                                    <?php endif; ?>
                                </div>
                                <div class="small text-muted mt-2">
                                    File: <?= e($uploadedProof) ?>
                                </div>
                            <?php else: ?>
                                <div class="proof-empty">No uploaded ID proof image is available for this pawn/customer.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="page-card h-100">
                <div class="page-head"><div class="section-title">Charges & Disbursement</div></div>
                <div class="card-body-x">
                    <div class="detail-grid" style="grid-template-columns:1fr 1fr">
                        <div class="detail-box"><span>Document Charge</span><strong>₹<?= pawnViewMoney($pawn['document_charge']) ?></strong></div>
                        <div class="detail-box"><span>Other Charge</span><strong>₹<?= pawnViewMoney($pawn['other_charge']) ?></strong></div>
                        <div class="detail-box"><span>Amount Given</span><strong>₹<?= pawnViewMoney(
                            isset($pawn['disbursement_amount'])
                                ? $pawn['disbursement_amount']
                                : max(0, (float)($pawn['principal_amount'] ?? 0) - (float)($pawn['document_charge'] ?? 0) - (float)($pawn['other_charge'] ?? 0))
                        ) ?></strong></div>
                        <div class="detail-box"><span>Disbursement Method</span><strong><?= e($pawn['disbursement_method'] ?: '—') ?></strong></div>
                        <div class="detail-box"><span>Payment Reference</span><strong><?= e($pawn['payment_reference'] ?: '—') ?></strong></div>
                    </div>
                    <?php if (!empty($pawn['remarks'])): ?>
                    <div class="detail-box mt-2"><span>Remarks</span><strong><?= nl2br(e($pawn['remarks'])) ?></strong></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="page-card mb-2">
        <div class="page-head"><div class="section-title">Interest Rule & Escalation</div></div>
        <div class="card-body-x">
            <div class="detail-grid">
                <div class="detail-box"><span>Initial Rate</span><strong><?= number_format($initialInterestPercent, 3) ?>%</strong></div>
                <div class="detail-box highlight"><span>Current Rate</span><strong><?= number_format($currentInterestPercent, 3) ?>%</strong></div>
                <div class="detail-box"><span>Due Cycle</span><strong><?= e($cycleLabel) ?></strong></div>
                <div class="detail-box"><span>Grace Days</span><strong><?= e($interestGraceDays) ?></strong></div>
                <div class="detail-box"><span>Last Paid Upto</span><strong><?= e(pawnViewDate($pawn['last_interest_paid_upto'] ?? '')) ?></strong></div>
                <div class="detail-box"><span>Next Interest Due</span><strong><?= e(pawnViewDate($nextDueDate)) ?></strong></div>
                <div class="detail-box"><span>Grace Until</span><strong><?= e(pawnViewDate($graceUntil)) ?></strong></div>
                <div class="detail-box"><span>Missed Cycles</span><strong><?= e($pawn['missed_interest_cycles'] ?? 0) ?></strong></div>
                <div class="detail-box"><span>Escalations</span><strong><?= e($pawn['rate_escalation_count'] ?? 0) ?></strong></div>
                <div class="detail-box"><span>Escalated At</span><strong><?= e(pawnViewDate($pawn['rate_escalated_at'] ?? '')) ?></strong></div>
                <div class="detail-box"><span>Next Rate</span><strong><?= $nextInterestPercent !== null ? number_format($nextInterestPercent,3).'%' : 'Final level' ?></strong></div>
                <div class="detail-box"><span>Bank Pledge</span><strong><?= e($pawn['bank_pledge_status'] ?? 'Not Pledged') ?></strong></div>
            </div>
        </div>
    </div>

    <?php if (!empty($bankCollateral)): ?>
    <div class="page-card mb-2">
        <div class="page-head"><div class="section-title">Bank Pledge Tracking</div></div>
        <div class="table-responsive">
            <table class="table compact-table align-middle">
                <thead><tr><th>Bank</th><th>Loan No</th><th>Collateral</th><th>Allocated Principal</th><th>Bank Principal</th><th>Bank Balance</th><th>Bank Interest</th><th>Pay Cycle</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($bankCollateral as $bc): ?>
                    <tr>
                        <td><strong><?= e($bc['bank_name'] ?? '—') ?></strong><div class="text-muted"><?= e($bc['bank_branch_name'] ?? '') ?></div></td>
                        <td><?= e($bc['bank_loan_no'] ?? '—') ?></td>
                        <td><?= number_format((float)($bc['pledged_net_weight'] ?? 0),3) ?> g</td>
                        <td>₹<?= pawnViewMoney($bc['allocated_bank_principal'] ?? 0) ?></td>
                        <td>₹<?= pawnViewMoney($bc['bank_principal_amount'] ?? 0) ?></td>
                        <td>₹<?= pawnViewMoney($bc['bank_balance_principal'] ?? 0) ?></td>
                        <td><?= number_format((float)($bc['bank_interest_percent'] ?? 0),3) ?>%</td>
                        <td><?= e($bc['interest_payment_cycle'] ?? '—') ?></td>
                        <td><?= e($bc['status'] ?? $bc['bank_loan_status'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <div class="page-card mb-3">
        <div class="page-head"><div class="section-title">Pawn Items</div></div>
        <div class="table-responsive">
            <table class="table compact-table align-middle">
                <thead><tr><th>Description</th><th>Metal</th><th>Qty</th><th>Purity</th><th>Gross</th><th>Stone/Less</th><th>Net</th><th>Rate / Gram</th><th>Estimated</th></tr></thead>
                <tbody>
                <?php if (!$items): ?>
                    <tr><td colspan="9" class="empty-row">No pawn items found.</td></tr>
                <?php else: foreach ($items as $item): ?>
                    <tr>
                        <td><?= e($item['item_description']) ?></td>
                        <td><?= e($item['metal_name']) ?></td>
                        <td><?= e($item['quantity']) ?></td>
                        <td><?= e($item['purity']) ?></td>
                        <td><?= number_format((float) $item['gross_weight'], 3) ?> g</td>
                        <td><?= number_format((float) $item['stone_weight'], 3) ?> g</td>
                        <td><?= number_format((float) $item['net_weight'], 3) ?> g</td>
                        <td>₹<?= pawnViewMoney($item['rate_per_gram'] ?? 0) ?></td>
                        <td>₹<?= pawnViewMoney($item['estimated_value']) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="page-card h-100">
                <div class="page-head"><div class="section-title">Interest Collections</div></div>
                <div class="table-responsive">
                    <table class="table compact-table align-middle">
                        <thead><tr><th>Receipt</th><th>Date</th><th>Period</th><th>Interest</th><th>Penalty</th><th>Total</th><th>Method</th></tr></thead>
                        <tbody>
                        <?php if (!$interestCollections): ?>
                            <tr><td colspan="7" class="empty-row">No interest collections.</td></tr>
                        <?php else: foreach ($interestCollections as $row): ?>
                            <tr>
                                <td><?= e($row['receipt_no'] ?? '') ?></td>
                                <td><?= e(pawnViewDate($row['collection_date'] ?? '')) ?></td>
                                <td><?= e(pawnViewDate($row['from_date'] ?? '')) ?> to <?= e(pawnViewDate($row['to_date'] ?? '')) ?></td>
                                <td>₹<?= pawnViewMoney($row['interest_amount'] ?? 0) ?></td>
                                <td>₹<?= pawnViewMoney($row['penalty_amount'] ?? 0) ?></td>
                                <td>₹<?= pawnViewMoney($row['total_amount'] ?? 0) ?></td>
                                <td><?= e($row['method_name'] ?? '—') ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="page-card h-100">
                <div class="page-head"><div class="section-title">Pawn Payments</div></div>
                <div class="table-responsive">
                    <table class="table compact-table align-middle">
                        <thead><tr><th>Receipt</th><th>Date</th><th>Type</th><th>Principal</th><th>Interest</th><th>Total</th><th>Method</th></tr></thead>
                        <tbody>
                        <?php if (!$pawnPayments): ?>
                            <tr><td colspan="7" class="empty-row">No pawn payments.</td></tr>
                        <?php else: foreach ($pawnPayments as $row): ?>
                            <tr>
                                <td><?= e($row['receipt_no'] ?? '') ?></td>
                                <td><?= e(pawnViewDate($row['payment_date'] ?? '')) ?></td>
                                <td><?= e($row['payment_type'] ?? (!empty($row['is_closure']) ? 'Full Settlement' : 'Part Payment')) ?></td>
                                <td>₹<?= pawnViewMoney($row['principal_amount'] ?? 0) ?></td>
                                <td>₹<?= pawnViewMoney($row['interest_amount'] ?? 0) ?></td>
                                <td>₹<?= pawnViewMoney($row['total_amount'] ?? 0) ?></td>
                                <td><?= e($row['method_name'] ?? '—') ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <?php include('includes/footer.php'); ?>
</div>
</main>
<?php include('includes/script.php'); ?>
<script src="assets/js/script.js"></script>
</body>
</html>