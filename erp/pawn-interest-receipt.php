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

$collectionId = max(0, (int)($_GET['collection_id'] ?? 0));
$receiptNo = trim((string)($_GET['receipt'] ?? ''));

if ($collectionId <= 0 || $receiptNo === '') {
    http_response_code(400);
    die('Invalid receipt link.');
}

$sql = "SELECT
            pic.*,
            pe.pawn_no,
            pe.pawn_date,
            pe.principal_amount,
            pe.balance_principal,
            pe.interest_percent,
            pe.interest_period,
            pe.interest_method,
            pe.interest_collection_cycle,
            pe.status AS pawn_status,
            c.customer_name,
            c.customer_code,
            c.mobile,
            c.email,
            c.address_line1,
            c.address_line2,
            c.city,
            c.state,
            c.pincode,
            pc.category_name,
            pm.method_name
        FROM pawn_interest_collections pic
        INNER JOIN pawn_entries pe
            ON pe.id = pic.pawn_entry_id
           AND pe.business_id = pic.business_id
        INNER JOIN customers c
            ON c.id = pe.customer_id
           AND c.business_id = pe.business_id
        LEFT JOIN pawn_categories pc
            ON pc.id = pe.pawn_category_id
        LEFT JOIN payment_methods pm
            ON pm.id = pic.payment_method_id
        WHERE pic.id = ?
          AND pic.receipt_no = ?
        LIMIT 1";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    die('Unable to load receipt: ' . h($conn->error));
}
$stmt->bind_param('is', $collectionId, $receiptNo);
$stmt->execute();
$r = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$r) {
    http_response_code(404);
    die('Interest receipt not found.');
}

$businessName = 'Jewellery ERP';
if (!empty($r['business_id'])) {
    $biz = $conn->prepare("SELECT * FROM businesses WHERE id=? LIMIT 1");
    if ($biz) {
        $businessId = (int)$r['business_id'];
        $biz->bind_param('i', $businessId);
        $biz->execute();
        $b = $biz->get_result()->fetch_assoc() ?: [];
        $biz->close();
        $businessName = (string)($b['business_name'] ?? $b['name'] ?? $businessName);
    }
}

$address = implode(', ', array_filter([
    $r['address_line1'] ?? '',
    $r['address_line2'] ?? '',
    $r['city'] ?? '',
    $r['state'] ?? '',
    $r['pincode'] ?? ''
]));

$total = (float)($r['total_amount'] ?? 0);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($businessName) ?> - Interest Receipt <?= h($r['receipt_no']) ?></title>
<style>
*{box-sizing:border-box}
body{margin:0;padding:24px;background:#f4f3f0;color:#1b1b1b;font-family:Arial,Helvetica,sans-serif}
.receipt{max-width:820px;margin:0 auto;background:#fff;border:1px solid #e5ded3;border-radius:16px;overflow:hidden;box-shadow:0 12px 36px rgba(0,0,0,.08)}
.top{background:linear-gradient(135deg,#d89416,#b86a0b);color:#fff;padding:26px 30px;display:flex;justify-content:space-between;gap:20px}
.top h1{margin:0;font-size:24px}.top p{margin:5px 0 0;font-size:13px;opacity:.92}.receipt-no{text-align:right}.receipt-no strong{font-size:18px}.body{padding:26px 30px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:18px}
.box{border:1px solid #ece5da;border-radius:12px;padding:15px}.box-title{text-transform:uppercase;font-size:10px;font-weight:800;color:#8b8175;margin-bottom:9px;letter-spacing:.04em}
.row{display:flex;justify-content:space-between;gap:12px;padding:7px 0;border-bottom:1px dashed #ece5da;font-size:13px}.row:last-child{border-bottom:0}.row span:first-child{color:#746d65}.row strong{text-align:right}
.total{max-width:390px;margin:20px 0 0 auto}.grand{font-size:18px;font-weight:800;border-top:2px solid #d89416!important;padding-top:11px!important}
.footer{text-align:center;color:#81776e;font-size:11px;padding:18px 25px 24px}
.actions{max-width:820px;margin:12px auto 0;display:flex;justify-content:flex-end}.print{border:0;background:#222;color:#fff;border-radius:9px;padding:10px 15px;font-weight:700;cursor:pointer}
@media(max-width:650px){body{padding:10px}.top{padding:20px;flex-direction:column}.receipt-no{text-align:left}.body{padding:18px}.grid{grid-template-columns:1fr}}
@media print{body{background:#fff;padding:0}.receipt{max-width:none;border:0;box-shadow:none}.actions{display:none}}
</style>
</head>
<body>
<div class="receipt">
    <div class="top">
        <div>
            <h1><?= h($businessName) ?></h1>
            <p>Pawn Interest Collection Receipt</p>
        </div>
        <div class="receipt-no">
            <strong><?= h($r['receipt_no']) ?></strong>
            <p>Collection Date: <?= h(dateOut($r['collection_date'])) ?></p>
        </div>
    </div>

    <div class="body">
        <div class="grid">
            <div class="box">
                <div class="box-title">Customer Details</div>
                <div class="row"><span>Name</span><strong><?= h($r['customer_name'] ?: '—') ?></strong></div>
                <div class="row"><span>Customer Code</span><strong><?= h($r['customer_code'] ?: '—') ?></strong></div>
                <div class="row"><span>Mobile</span><strong><?= h($r['mobile'] ?: '—') ?></strong></div>
                <div class="row"><span>Address</span><strong><?= h($address ?: '—') ?></strong></div>
            </div>

            <div class="box">
                <div class="box-title">Pawn Details</div>
                <div class="row"><span>Pawn No</span><strong><?= h($r['pawn_no']) ?></strong></div>
                <div class="row"><span>Category</span><strong><?= h($r['category_name'] ?: '—') ?></strong></div>
                <div class="row"><span>Principal Balance</span><strong>₹<?= money($r['principal_balance'] ?? $r['balance_principal']) ?></strong></div>
                <div class="row"><span>Interest Rule</span><strong><?= number_format((float)$r['interest_percent'],3) ?>% <?= h($r['interest_period']) ?></strong></div>
            </div>
        </div>

        <div class="box">
            <div class="box-title">Interest Period</div>
            <div class="row"><span>From</span><strong><?= h(dateOut($r['from_date'])) ?></strong></div>
            <div class="row"><span>To</span><strong><?= h(dateOut($r['to_date'])) ?></strong></div>
            <div class="row"><span>Calculation Days</span><strong><?= h($r['calculation_days'] ?? 0) ?> day(s)</strong></div>
            <div class="row"><span>Calculation Months</span><strong><?= h($r['calculation_months'] ?? 0) ?></strong></div>
            <div class="row"><span>Collection Type</span><strong><?= h($r['collection_type'] ?? '—') ?></strong></div>
        </div>

        <div class="total">
            <div class="row"><span>Interest</span><strong>₹<?= money($r['interest_amount']) ?></strong></div>
            <div class="row"><span>Overdue / Penalty</span><strong>₹<?= money($r['penalty_amount']) ?></strong></div>
            <div class="row"><span>Other Charges</span><strong>₹<?= money($r['other_charges']) ?></strong></div>
            <div class="row"><span>Payment Method</span><strong><?= h($r['method_name'] ?: '—') ?></strong></div>
            <?php if (!empty($r['reference_no'])): ?>
                <div class="row"><span>Reference No</span><strong><?= h($r['reference_no']) ?></strong></div>
            <?php endif; ?>
            <div class="row grand"><span>Total Paid</span><strong>₹<?= money($total) ?></strong></div>
        </div>

        <?php if (!empty($r['remarks'])): ?>
            <div class="box" style="margin-top:18px">
                <div class="box-title">Remarks</div>
                <div style="font-size:13px;line-height:1.5"><?= nl2br(h($r['remarks'])) ?></div>
            </div>
        <?php endif; ?>
    </div>

    <div class="footer">
        This is a system-generated pawn interest receipt.
    </div>
</div>

<div class="actions">
    <button type="button" class="print" onclick="window.print()">Print / Save PDF</button>
</div>
</body>
</html>
