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

$paymentId = max(0, (int)($_GET['payment_id'] ?? 0));
$receiptNo = trim((string)($_GET['receipt'] ?? ''));

if ($paymentId <= 0 || $receiptNo === '') {
    http_response_code(400);
    die('Invalid receipt link.');
}

$sql = "SELECT
            pp.*,
            pe.pawn_no,
            pe.pawn_date,
            pe.principal_amount AS original_principal,
            pe.balance_principal,
            pe.status AS pawn_status,
            pe.closure_date,
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
        FROM pawn_payments pp
        INNER JOIN pawn_entries pe
            ON pe.id = pp.pawn_entry_id
           AND pe.business_id = pp.business_id
        INNER JOIN customers c
            ON c.id = pe.customer_id
           AND c.business_id = pe.business_id
        LEFT JOIN pawn_categories pc
            ON pc.id = pe.pawn_category_id
        LEFT JOIN payment_methods pm
            ON pm.id = pp.payment_method_id
        WHERE pp.id = ?
          AND pp.receipt_no = ?
        LIMIT 1";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    die('Unable to load receipt: ' . h($conn->error));
}
$stmt->bind_param('is', $paymentId, $receiptNo);
$stmt->execute();
$r = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$r) {
    http_response_code(404);
    die('Pawn payment receipt not found.');
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

$release = null;
if (!empty($r['is_closure'])) {
    $rs = $conn->prepare("SELECT * FROM pawn_releases WHERE pawn_payment_id=? ORDER BY id DESC LIMIT 1");
    if ($rs) {
        $rs->bind_param('i', $paymentId);
        $rs->execute();
        $release = $rs->get_result()->fetch_assoc() ?: null;
        $rs->close();
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
<title><?= h($businessName) ?> - Pawn Payment Receipt <?= h($r['receipt_no']) ?></title>
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
.closed{margin-top:18px;padding:14px;border-radius:10px;background:#eaf8f0;border:1px solid #bfe6cf;color:#126b3d;font-size:12px}
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
            <p>Pawn Payment / Settlement Receipt</p>
        </div>
        <div class="receipt-no">
            <strong><?= h($r['receipt_no']) ?></strong>
            <p>Payment Date: <?= h(dateOut($r['payment_date'])) ?></p>
            <p><?= h($r['payment_type'] ?? '') ?></p>
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
                <div class="row"><span>Original Principal</span><strong>₹<?= money($r['original_principal']) ?></strong></div>
                <div class="row"><span>Current Status</span><strong><?= h($r['pawn_status'] ?: '—') ?></strong></div>
            </div>
        </div>

        <div class="total">
            <div class="row"><span>Principal Paid</span><strong>₹<?= money($r['principal_amount']) ?></strong></div>
            <div class="row"><span>Interest</span><strong>₹<?= money($r['interest_amount']) ?></strong></div>
            <div class="row"><span>Overdue / Penalty</span><strong>₹<?= money($r['penalty_amount']) ?></strong></div>
            <div class="row"><span>Other Charges</span><strong>₹<?= money($r['other_charges']) ?></strong></div>
            <div class="row"><span>Payment Method</span><strong><?= h($r['method_name'] ?: '—') ?></strong></div>
            <?php if (!empty($r['reference_no'])): ?>
                <div class="row"><span>Reference No</span><strong><?= h($r['reference_no']) ?></strong></div>
            <?php endif; ?>
            <div class="row"><span>Balance Principal</span><strong>₹<?= money($r['balance_principal']) ?></strong></div>
            <div class="row grand"><span>Total Paid</span><strong>₹<?= money($total) ?></strong></div>
        </div>

        <?php if (!empty($r['is_closure'])): ?>
            <div class="closed">
                <strong>Pawn fully settled.</strong>
                <?php if ($release): ?>
                    <div style="margin-top:6px">
                        Release No: <?= h($release['release_no'] ?? '—') ?><br>
                        Released To: <?= h($release['released_to'] ?? '—') ?><br>
                        Handover Status: <?= h($release['item_handover_status'] ?? 'Pending') ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($r['remarks'])): ?>
            <div class="box" style="margin-top:18px">
                <div class="box-title">Remarks</div>
                <div style="font-size:13px;line-height:1.5"><?= nl2br(h($r['remarks'])) ?></div>
            </div>
        <?php endif; ?>
    </div>

    <div class="footer">
        This is a system-generated pawn payment receipt.
    </div>
</div>

<div class="actions">
    <button type="button" class="print" onclick="window.print()">Print / Save PDF</button>
</div>
</body>
</html>
