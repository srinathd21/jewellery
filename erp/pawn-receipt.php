<?php
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

foreach ([
    __DIR__ . '/config/config.php',
    __DIR__ . '/config.php',
    __DIR__ . '/includes/config.php',
    __DIR__ . '/super-admin/includes/config.php',
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

function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function money($value): string
{
    return number_format((float)($value ?? 0), 2, '.', ',');
}

function dateOut($value): string
{
    if (empty($value)) return '—';
    $time = strtotime((string)$value);
    return $time ? date('d-m-Y', $time) : (string)$value;
}

function tableExists(mysqli $conn, string $table): bool
{
    $safe = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $result && $result->num_rows > 0;
}

$pawnId = max(0, (int)($_GET['id'] ?? 0));
$ref = trim((string)($_GET['ref'] ?? ''));

if ($pawnId <= 0 || $ref === '') {
    http_response_code(400);
    die('Invalid receipt link.');
}

$sql = "SELECT
            p.*,
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
          AND p.pawn_no = ?
        LIMIT 1";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    die('Unable to load receipt: ' . h($conn->error));
}
$stmt->bind_param('is', $pawnId, $ref);
$stmt->execute();
$pawn = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$pawn) {
    http_response_code(404);
    die('Receipt not found.');
}

$businessName = 'Jewellery ERP';
if (tableExists($conn, 'businesses')) {
    $biz = $conn->prepare("SELECT * FROM businesses WHERE id = ? LIMIT 1");
    if ($biz) {
        $businessId = (int)($pawn['business_id'] ?? 0);
        $biz->bind_param('i', $businessId);
        $biz->execute();
        $business = $biz->get_result()->fetch_assoc() ?: [];
        $biz->close();
        $businessName = (string)($business['business_name'] ?? $business['name'] ?? $businessName);
    }
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
    $businessId = (int)($pawn['business_id'] ?? 0);
    $itemStmt->bind_param('ii', $pawnId, $businessId);
    $itemStmt->execute();
    $result = $itemStmt->get_result();
    while ($row = $result->fetch_assoc()) $items[] = $row;
    $itemStmt->close();
}

$address = implode(', ', array_filter([
    $pawn['address_line1'] ?? '',
    $pawn['address_line2'] ?? '',
    $pawn['city'] ?? '',
    $pawn['state'] ?? '',
    $pawn['pincode'] ?? '',
]));

$principal = (float)($pawn['principal_amount'] ?? 0);
$documentCharge = (float)($pawn['document_charge'] ?? 0);
$otherCharge = (float)($pawn['other_charge'] ?? 0);
$disbursement = isset($pawn['disbursement_amount'])
    ? (float)$pawn['disbursement_amount']
    : max(0, $principal - $documentCharge - $otherCharge);

$paid = (float)($pawn['total_principal_paid'] ?? 0);
$balance = (float)($pawn['balance_principal'] ?? max(0, $principal - $paid));
$interestRate = (float)($pawn['interest_percent'] ?? 0);
$interestPeriod = (string)($pawn['interest_period'] ?? 'Monthly');
$interestMethod = (string)($pawn['interest_method'] ?? 'Simple');

$baseForInterest = strtolower($interestMethod) === 'flat' ? $principal : $balance;
$cycle = (string)($pawn['interest_collection_cycle'] ?? 'Monthly');
$cycleMonths = max(1, (int)($pawn['interest_cycle_months'] ?? 1));
$months = ['Monthly'=>1,'Quarterly'=>3,'Half-Yearly'=>6,'Yearly'=>12,'Custom'=>$cycleMonths][$cycle] ?? 1;

if ($cycle === 'At Closure') $multiplier = 1;
elseif ($interestPeriod === 'Daily') $multiplier = $months * 30;
elseif ($interestPeriod === 'Yearly') $multiplier = $months / 12;
else $multiplier = $months;

$estimatedInterest = $baseForInterest * ($interestRate / 100) * $multiplier;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($businessName) ?> - Pawn Receipt <?= h($pawn['pawn_no']) ?></title>
<style>
*{box-sizing:border-box}
body{margin:0;padding:24px;background:#f4f3f0;color:#1b1b1b;font-family:Arial,Helvetica,sans-serif}
.receipt{max-width:900px;margin:0 auto;background:#fff;border:1px solid #e5ded3;border-radius:16px;overflow:hidden;box-shadow:0 12px 36px rgba(0,0,0,.08)}
.top{background:linear-gradient(135deg,#d89416,#b86a0b);color:#fff;padding:26px 30px;display:flex;justify-content:space-between;gap:20px}
.top h1{margin:0;font-size:24px}.top p{margin:5px 0 0;font-size:13px;opacity:.92}.receipt-no{text-align:right}.receipt-no strong{font-size:18px}.body{padding:26px 30px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:18px}
.box{border:1px solid #ece5da;border-radius:12px;padding:15px}.box-title{text-transform:uppercase;font-size:10px;font-weight:800;color:#8b8175;margin-bottom:9px;letter-spacing:.04em}
.row{display:flex;justify-content:space-between;gap:12px;padding:6px 0;border-bottom:1px dashed #ece5da;font-size:13px}.row:last-child{border-bottom:0}.row span:first-child{color:#746d65}.row strong{text-align:right}
table{width:100%;border-collapse:collapse;margin-top:8px}th,td{padding:10px;border-bottom:1px solid #ece5da;font-size:12px;text-align:left}th{background:#faf8f4;text-transform:uppercase;font-size:10px;color:#756d63}.num{text-align:right}
.total{max-width:380px;margin:20px 0 0 auto}.grand{font-size:17px;font-weight:800;border-top:2px solid #d89416!important;padding-top:11px!important}
.note{margin-top:18px;padding:13px 15px;border-radius:10px;background:#fff8e8;border:1px solid #efd8a0;font-size:12px;color:#725414}
.footer{text-align:center;color:#81776e;font-size:11px;padding:18px 25px 24px}
.actions{max-width:900px;margin:12px auto 0;display:flex;justify-content:flex-end}.print{border:0;background:#222;color:#fff;border-radius:9px;padding:10px 15px;font-weight:700;cursor:pointer}
@media(max-width:650px){body{padding:10px}.top{padding:20px;flex-direction:column}.receipt-no{text-align:left}.body{padding:18px}.grid{grid-template-columns:1fr}}
@media print{body{background:#fff;padding:0}.receipt{max-width:none;border:0;box-shadow:none}.actions{display:none}}
</style>
</head>
<body>
<div class="receipt">
    <div class="top">
        <div>
            <h1><?= h($businessName) ?></h1>
            <p>Pawn Loan Receipt</p>
        </div>
        <div class="receipt-no">
            <strong><?= h($pawn['pawn_no']) ?></strong>
            <p>Pawn Date: <?= h(dateOut($pawn['pawn_date'])) ?></p>
            <p>Status: <?= h($pawn['status'] ?? '') ?></p>
        </div>
    </div>

    <div class="body">
        <div class="grid">
            <div class="box">
                <div class="box-title">Customer Details</div>
                <div class="row"><span>Name</span><strong><?= h($pawn['customer_name'] ?: '—') ?></strong></div>
                <div class="row"><span>Customer Code</span><strong><?= h($pawn['customer_code'] ?: '—') ?></strong></div>
                <div class="row"><span>Mobile</span><strong><?= h($pawn['mobile'] ?: '—') ?></strong></div>
                <?php if (!empty($pawn['id_proof_type'])): ?>
                    <div class="row"><span>ID Proof</span><strong><?= h($pawn['id_proof_type']) ?></strong></div>
                <?php endif; ?>
                <?php if (!empty($pawn['id_proof_number'])): ?>
                    <div class="row"><span>ID Proof Number</span><strong><?= h($pawn['id_proof_number']) ?></strong></div>
                <?php endif; ?>
                <div class="row"><span>Address</span><strong><?= h($address ?: '—') ?></strong></div>
            </div>

            <div class="box">
                <div class="box-title">Pawn Details</div>
                <div class="row"><span>Category</span><strong><?= h($pawn['category_name'] ?: '—') ?></strong></div>
                <div class="row"><span>Branch</span><strong><?= h($pawn['branch_name'] ?: '—') ?></strong></div>
                <div class="row"><span>Loan Type</span><strong><?= h($pawn['loan_type'] ?: 'General') ?></strong></div>
                <div class="row"><span>Due Date</span><strong><?= h($pawn['due_date'] ? dateOut($pawn['due_date']) : 'At Closure') ?></strong></div>
            </div>
        </div>

        <div class="box">
            <div class="box-title">Loan & Disbursement</div>
            <div class="row"><span>Principal Amount</span><strong>₹<?= money($principal) ?></strong></div>
            <div class="row"><span>Document Charge</span><strong>₹<?= money($documentCharge) ?></strong></div>
            <div class="row"><span>Other Charge</span><strong>₹<?= money($otherCharge) ?></strong></div>
            <div class="row"><span>Amount Given to Customer</span><strong>₹<?= money($disbursement) ?></strong></div>
            <div class="row"><span>Disbursement Method</span><strong><?= h($pawn['disbursement_method'] ?: '—') ?></strong></div>
            <div class="row"><span>Payment Reference</span><strong><?= h($pawn['payment_reference'] ?: '—') ?></strong></div>
        </div>

        <div class="box" style="margin-top:14px">
            <div class="box-title">Interest Details</div>
            <div class="row"><span>Interest Rate</span><strong><?= number_format($interestRate, 3) ?>% <?= h($interestPeriod) ?></strong></div>
            <div class="row"><span>Interest Method</span><strong><?= h($interestMethod) ?></strong></div>
            <div class="row"><span>Collection Cycle</span><strong><?= h($cycle) ?></strong></div>
            <div class="row"><span>Estimated Current Cycle Interest</span><strong>₹<?= money($estimatedInterest) ?></strong></div>
        </div>

        <div style="margin-top:18px">
            <div class="box-title">Pawn Items</div>
            <table>
                <thead>
                    <tr>
                        <th>Description</th>
                        <th>Metal</th>
                        <th>Qty</th>
                        <th>Purity</th>
                        <th class="num">Gross</th>
                        <th class="num">Stone/Less</th>
                        <th class="num">Net</th>
                        <th class="num">Rate/g</th>
                        <th class="num">Estimated</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$items): ?>
                    <tr><td colspan="9" style="text-align:center;color:#888">No pawn items found.</td></tr>
                <?php else: foreach ($items as $item): ?>
                    <tr>
                        <td><?= h($item['item_description'] ?? '') ?></td>
                        <td><?= h($item['metal_name'] ?? '') ?></td>
                        <td><?= h($item['quantity'] ?? '') ?></td>
                        <td><?= h($item['purity'] ?? '') ?></td>
                        <td class="num"><?= number_format((float)($item['gross_weight'] ?? 0),3) ?> g</td>
                        <td class="num"><?= number_format((float)($item['stone_weight'] ?? 0),3) ?> g</td>
                        <td class="num"><?= number_format((float)($item['net_weight'] ?? 0),3) ?> g</td>
                        <td class="num">₹<?= money($item['rate_per_gram'] ?? 0) ?></td>
                        <td class="num">₹<?= money($item['estimated_value'] ?? 0) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <div class="total">
            <div class="row"><span>Principal Paid</span><strong>₹<?= money($paid) ?></strong></div>
            <div class="row grand"><span>Balance Principal</span><strong>₹<?= money($balance) ?></strong></div>
        </div>

        <?php if (!empty($pawn['remarks'])): ?>
            <div class="note"><strong>Remarks:</strong><br><?= nl2br(h($pawn['remarks'])) ?></div>
        <?php endif; ?>
    </div>

    <div class="footer">
        This is a system-generated pawn receipt. Please contact the business for corrections or account questions.
    </div>
</div>

<div class="actions">
    <button type="button" class="print" onclick="window.print()">Print / Save PDF</button>
</div>
</body>
</html>