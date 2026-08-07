<?php
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

foreach ([
    __DIR__ . '/config/config.php',
    __DIR__ . '/config.php',
    __DIR__ . '/includes/config.php',
    __DIR__ . '/super-admin/includes/config.php'
] as $configFile) {
    if (is_file($configFile)) {
        require_once $configFile;
        break;
    }
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    die('Database configuration is not available.');
}

$conn->set_charset('utf8mb4');

function h($v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function money($v): string
{
    return number_format((float)($v ?? 0), 2, '.', ',');
}

function dateOut($v): string
{
    if (empty($v)) return '—';
    $t = strtotime((string)$v);
    return $t ? date('d-m-Y', $t) : (string)$v;
}

function tableExists(mysqli $conn, string $table): bool
{
    $safe = $conn->real_escape_string($table);
    $r = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $r && $r->num_rows > 0;
}

$pawnId = max(0, (int)($_GET['id'] ?? 0));
$ref = trim((string)($_GET['ref'] ?? ''));

if ($pawnId <= 0 || $ref === '') {
    http_response_code(400);
    die('Invalid closed pawn receipt link.');
}

$sql = "SELECT
            pe.*,
            c.customer_name,
            c.customer_code,
            c.mobile,
            c.email,
            c.address_line1,
            c.address_line2,
            c.city,
            c.state,
            c.pincode,
            pc.category_name
        FROM pawn_entries pe
        INNER JOIN customers c
            ON c.id = pe.customer_id
           AND c.business_id = pe.business_id
        LEFT JOIN pawn_categories pc
            ON pc.id = pe.pawn_category_id
        WHERE pe.id = ?
          AND pe.pawn_no = ?
        LIMIT 1";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    die('Unable to load closed pawn receipt: ' . h($conn->error));
}

$stmt->bind_param('is', $pawnId, $ref);
$stmt->execute();
$pawn = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$pawn) {
    http_response_code(404);
    die('Closed pawn receipt not found.');
}

if ((string)($pawn['status'] ?? '') !== 'Closed') {
    http_response_code(409);
    die('This pawn is not closed.');
}

$finalPayment = null;
if (tableExists($conn, 'pawn_payments')) {
    $stmt = $conn->prepare(
        "SELECT pp.*, pm.method_name
         FROM pawn_payments pp
         LEFT JOIN payment_methods pm ON pm.id = pp.payment_method_id
         WHERE pp.pawn_entry_id = ?
           AND pp.business_id = ?
           AND pp.is_closure = 1
         ORDER BY pp.id DESC
         LIMIT 1"
    );
    if ($stmt) {
        $businessId = (int)($pawn['business_id'] ?? 0);
        $stmt->bind_param('ii', $pawnId, $businessId);
        $stmt->execute();
        $finalPayment = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
    }
}

$release = null;
if ($finalPayment && tableExists($conn, 'pawn_releases')) {
    $stmt = $conn->prepare(
        "SELECT *
         FROM pawn_releases
         WHERE pawn_payment_id = ?
         ORDER BY id DESC
         LIMIT 1"
    );
    if ($stmt) {
        $paymentId = (int)$finalPayment['id'];
        $stmt->bind_param('i', $paymentId);
        $stmt->execute();
        $release = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
    }
}

$businessName = 'Jewellery ERP';
if (tableExists($conn, 'businesses')) {
    $stmt = $conn->prepare("SELECT * FROM businesses WHERE id=? LIMIT 1");
    if ($stmt) {
        $businessId = (int)($pawn['business_id'] ?? 0);
        $stmt->bind_param('i', $businessId);
        $stmt->execute();
        $biz = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        $businessName = (string)($biz['business_name'] ?? $biz['name'] ?? $businessName);
    }
}

$address = implode(', ', array_filter([
    $pawn['address_line1'] ?? '',
    $pawn['address_line2'] ?? '',
    $pawn['city'] ?? '',
    $pawn['state'] ?? '',
    $pawn['pincode'] ?? ''
]));

$principal = (float)($pawn['principal_amount'] ?? 0);
$totalPrincipalPaid = (float)($pawn['total_principal_paid'] ?? 0);
$totalInterest = (float)($pawn['total_interest_collected'] ?? 0);
$totalPenalty = (float)($pawn['total_penalty_collected'] ?? 0);
$totalOther = (float)($pawn['total_other_charges_collected'] ?? 0);
$totalCollected = $totalPrincipalPaid + $totalInterest + $totalPenalty + $totalOther;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($businessName) ?> - Closed Pawn Receipt <?= h($pawn['pawn_no']) ?></title>
<style>
*{box-sizing:border-box}
body{margin:0;padding:24px;background:#f4f3f0;color:#1b1b1b;font-family:Arial,Helvetica,sans-serif}
.receipt{max-width:860px;margin:0 auto;background:#fff;border:1px solid #dce8df;border-radius:16px;overflow:hidden;box-shadow:0 12px 36px rgba(0,0,0,.08)}
.top{background:linear-gradient(135deg,#168449,#0d6f3c);color:#fff;padding:26px 30px;display:flex;justify-content:space-between;gap:20px}
.top h1{margin:0;font-size:24px}.top p{margin:5px 0 0;font-size:13px;opacity:.92}.receipt-no{text-align:right}.receipt-no strong{font-size:18px}
.closed-banner{margin:22px 30px 0;padding:14px 16px;border-radius:11px;background:#eaf8f0;border:1px solid #bfe6cf;color:#126b3d;font-weight:800;text-align:center}
.body{padding:24px 30px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:18px}
.box{border:1px solid #e5e8e6;border-radius:12px;padding:15px}.box-title{text-transform:uppercase;font-size:10px;font-weight:800;color:#738078;margin-bottom:9px;letter-spacing:.04em}
.row{display:flex;justify-content:space-between;gap:12px;padding:7px 0;border-bottom:1px dashed #e5e8e6;font-size:13px}.row:last-child{border-bottom:0}.row span:first-child{color:#6d766f}.row strong{text-align:right}
.total{max-width:410px;margin:20px 0 0 auto}.grand{font-size:18px;font-weight:800;border-top:2px solid #168449!important;padding-top:11px!important;color:#126b3d}
.release{margin-top:18px;padding:15px;border-radius:12px;background:#f7fbf8;border:1px solid #cfe5d6}
.footer{text-align:center;color:#7b837e;font-size:11px;padding:18px 25px 24px}
.actions{max-width:860px;margin:12px auto 0;display:flex;justify-content:flex-end}.print{border:0;background:#222;color:#fff;border-radius:9px;padding:10px 15px;font-weight:700;cursor:pointer}
@media(max-width:650px){body{padding:10px}.top{padding:20px;flex-direction:column}.receipt-no{text-align:left}.body{padding:18px}.closed-banner{margin:16px 18px 0}.grid{grid-template-columns:1fr}}
@media print{body{background:#fff;padding:0}.receipt{max-width:none;border:0;box-shadow:none}.actions{display:none}}
</style>
</head>
<body>
<div class="receipt">
    <div class="top">
        <div>
            <h1><?= h($businessName) ?></h1>
            <p>Closed Pawn / Final Settlement Receipt</p>
        </div>
        <div class="receipt-no">
            <strong><?= h($pawn['pawn_no']) ?></strong>
            <p>Pawn Date: <?= h(dateOut($pawn['pawn_date'])) ?></p>
            <p>Closure Date: <?= h(dateOut($pawn['closure_date'] ?? '')) ?></p>
        </div>
    </div>

    <div class="closed-banner">
        PAWN CLOSED SUCCESSFULLY
    </div>

    <div class="body">
        <div class="grid">
            <div class="box">
                <div class="box-title">Customer Details</div>
                <div class="row"><span>Name</span><strong><?= h($pawn['customer_name'] ?: '—') ?></strong></div>
                <div class="row"><span>Customer Code</span><strong><?= h($pawn['customer_code'] ?: '—') ?></strong></div>
                <div class="row"><span>Mobile</span><strong><?= h($pawn['mobile'] ?: '—') ?></strong></div>
                <div class="row"><span>Address</span><strong><?= h($address ?: '—') ?></strong></div>
            </div>

            <div class="box">
                <div class="box-title">Pawn Details</div>
                <div class="row"><span>Pawn No</span><strong><?= h($pawn['pawn_no']) ?></strong></div>
                <div class="row"><span>Category</span><strong><?= h($pawn['category_name'] ?: '—') ?></strong></div>
                <div class="row"><span>Original Principal</span><strong>₹<?= money($principal) ?></strong></div>
                <div class="row"><span>Status</span><strong>Closed</strong></div>
            </div>
        </div>

        <div class="box">
            <div class="box-title">Final Settlement Summary</div>
            <div class="row"><span>Total Principal Paid</span><strong>₹<?= money($totalPrincipalPaid) ?></strong></div>
            <div class="row"><span>Total Interest Collected</span><strong>₹<?= money($totalInterest) ?></strong></div>
            <div class="row"><span>Total Penalty Collected</span><strong>₹<?= money($totalPenalty) ?></strong></div>
            <div class="row"><span>Total Other Charges</span><strong>₹<?= money($totalOther) ?></strong></div>
            <div class="row"><span>Balance Principal</span><strong>₹<?= money($pawn['balance_principal'] ?? 0) ?></strong></div>
        </div>

        <?php if ($finalPayment): ?>
            <div class="box" style="margin-top:14px">
                <div class="box-title">Final Payment</div>
                <div class="row"><span>Receipt No</span><strong><?= h($finalPayment['receipt_no'] ?? '—') ?></strong></div>
                <div class="row"><span>Payment Date</span><strong><?= h(dateOut($finalPayment['payment_date'] ?? '')) ?></strong></div>
                <div class="row"><span>Principal</span><strong>₹<?= money($finalPayment['principal_amount'] ?? 0) ?></strong></div>
                <div class="row"><span>Interest</span><strong>₹<?= money($finalPayment['interest_amount'] ?? 0) ?></strong></div>
                <div class="row"><span>Penalty</span><strong>₹<?= money($finalPayment['penalty_amount'] ?? 0) ?></strong></div>
                <div class="row"><span>Other Charges</span><strong>₹<?= money($finalPayment['other_charges'] ?? 0) ?></strong></div>
                <div class="row"><span>Payment Method</span><strong><?= h($finalPayment['method_name'] ?? '—') ?></strong></div>
                <div class="row grand"><span>Final Payment Total</span><strong>₹<?= money($finalPayment['total_amount'] ?? 0) ?></strong></div>
            </div>
        <?php endif; ?>

        <?php if ($release): ?>
            <div class="release">
                <div class="box-title">Release / Handover Details</div>
                <div class="row"><span>Release No</span><strong><?= h($release['release_no'] ?? '—') ?></strong></div>
                <div class="row"><span>Release Date</span><strong><?= h(dateOut($release['release_date'] ?? '')) ?></strong></div>
                <div class="row"><span>Released To</span><strong><?= h($release['released_to'] ?? '—') ?></strong></div>
                <div class="row"><span>Handover Status</span><strong><?= h($release['item_handover_status'] ?? 'Pending') ?></strong></div>
            </div>
        <?php endif; ?>

        <div class="total">
            <div class="row grand">
                <span>Total Collected</span>
                <strong>₹<?= money($totalCollected) ?></strong>
            </div>
        </div>
    </div>

    <div class="footer">
        This is a system-generated closed pawn receipt confirming final settlement.
    </div>
</div>

<div class="actions">
    <button type="button" class="print" onclick="window.print()">Print / Save PDF</button>
</div>
</body>
</html>
