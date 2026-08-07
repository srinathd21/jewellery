<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string) ($_SESSION['timezone'] ?? 'Asia/Kolkata'));

foreach ([
    __DIR__ . '/config/config.php',
    __DIR__ . '/config.php',
    __DIR__ . '/includes/config.php',
    __DIR__ . '/super-admin/includes/config.php'
] as $f) {
    if (is_file($f)) {
        require_once $f;
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
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function ce(mysqli $c, string $table, string $column): bool
{
    $table = $c->real_escape_string($table);
    $column = $c->real_escape_string($column);
    $r = $c->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    return $r && $r->num_rows > 0;
}

$collectionId = (int) ($_GET['collection_id'] ?? 0);
$receipt = trim((string) ($_GET['receipt'] ?? ''));

if ($collectionId <= 0 || $receipt === '') {
    http_response_code(400);
    die('Invalid invoice link.');
}

$collectionPk = ce($conn, 'chit_collections', 'id') ? 'id'
    : (ce($conn, 'chit_collections', 'collection_id') ? 'collection_id' : 'id');

$sql = "SELECT
            cc.*,
            ci.installment_no,
            ci.due_date,
            cm.ticket_no,
            cm.join_date,
            cm.status AS member_status,
            c.customer_code,
            c.customer_name,
            c.mobile,
            c.email,
            c.address_line1,
            c.city,
            c.state,
            c.pincode,
            cg.group_no,
            cg.group_name,
            cg.chit_type,
            cg.installment_amount,
            cg.chit_value,
            cg.total_months,
            pm.method_name,
            m.metal_name,
            m.metal_code
        FROM chit_collections cc
        INNER JOIN chit_installments ci ON ci.id = cc.chit_installment_id
        INNER JOIN chit_members cm ON cm.id = cc.chit_member_id
        INNER JOIN customers c ON c.id = cm.customer_id
        INNER JOIN chit_groups cg ON cg.id = cm.chit_group_id
        LEFT JOIN payment_methods pm ON pm.id = cc.payment_method_id
        LEFT JOIN metals m ON m.id = cc.gold_metal_id AND m.business_id = cc.business_id
        WHERE cc.`{$collectionPk}` = ?
          AND cc.receipt_no = ?
        LIMIT 1";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    die('Unable to prepare invoice.');
}
$stmt->bind_param('is', $collectionId, $receipt);
$stmt->execute();
$invoice = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$invoice) {
    http_response_code(404);
    die('Invoice not found.');
}

$businessName = (string) ($_SESSION['business_name'] ?? 'Jewellery ERP');
if (!empty($invoice['business_id'])) {
    // If your businesses table contains a display name, you can replace the
    // session fallback above with that value here.
}

$customerAddress = array_filter([
    trim((string) ($invoice['address_line1'] ?? '')),
    trim((string) ($invoice['city'] ?? '')),
    trim((string) ($invoice['state'] ?? '')),
    trim((string) ($invoice['pincode'] ?? '')),
]);
$customerAddress = implode(', ', $customerAddress);

$netAmount = (float) ($invoice['net_amount'] ?? $invoice['paid_amount'] ?? 0);
$isGold = (($invoice['chit_type'] ?? '') === 'Gold');
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= h($businessName) ?> - Chit Invoice <?= h($invoice['receipt_no']) ?></title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 24px;
            background: #f5f3ef;
            color: #1e1e1e;
            font-family: Arial, Helvetica, sans-serif;
        }
        .invoice {
            width: 100%;
            max-width: 820px;
            margin: 0 auto;
            background: #fff;
            border: 1px solid #e4ded4;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 12px 35px rgba(0,0,0,.08);
        }
        .top {
            padding: 26px 30px;
            background: linear-gradient(135deg, #d89416, #b86a0b);
            color: #fff;
            display: flex;
            justify-content: space-between;
            gap: 20px;
            align-items: flex-start;
        }
        .brand h1 {
            margin: 0 0 5px;
            font-size: 24px;
        }
        .brand p, .invoice-no p {
            margin: 3px 0;
            opacity: .92;
            font-size: 13px;
        }
        .invoice-no {
            text-align: right;
        }
        .invoice-no strong {
            display: block;
            font-size: 18px;
            margin-bottom: 4px;
        }
        .content {
            padding: 28px 30px;
        }
        .two-col {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 24px;
        }
        .box {
            border: 1px solid #ece6dd;
            border-radius: 12px;
            padding: 16px;
        }
        .box-title {
            font-size: 11px;
            text-transform: uppercase;
            color: #8a8177;
            font-weight: 700;
            margin-bottom: 10px;
            letter-spacing: .5px;
        }
        .box strong {
            display: block;
            margin-bottom: 4px;
            font-size: 15px;
        }
        .box p {
            margin: 4px 0;
            color: #555;
            font-size: 13px;
            line-height: 1.45;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
        }
        th, td {
            padding: 11px 10px;
            border-bottom: 1px solid #ece6dd;
            text-align: left;
            font-size: 13px;
        }
        th {
            background: #faf8f4;
            color: #756c62;
            font-size: 11px;
            text-transform: uppercase;
        }
        td.amount, th.amount {
            text-align: right;
        }
        .total {
            margin-left: auto;
            margin-top: 20px;
            width: min(360px, 100%);
        }
        .total-row {
            display: flex;
            justify-content: space-between;
            padding: 7px 0;
            font-size: 13px;
        }
        .total-row.grand {
            margin-top: 6px;
            padding-top: 12px;
            border-top: 2px solid #d89416;
            font-size: 18px;
            font-weight: 800;
        }
        .gold {
            margin-top: 20px;
            padding: 14px 16px;
            border-radius: 12px;
            background: #fff8e7;
            border: 1px solid #f0d99b;
        }
        .gold strong {
            color: #9a6500;
        }
        .footer {
            text-align: center;
            padding: 18px 30px 26px;
            color: #81776d;
            font-size: 12px;
        }
        .actions {
            max-width: 820px;
            margin: 14px auto 0;
            display: flex;
            justify-content: flex-end;
        }
        .print-btn {
            border: 0;
            border-radius: 9px;
            padding: 10px 16px;
            background: #242424;
            color: #fff;
            font-weight: 700;
            cursor: pointer;
        }
        @media (max-width: 620px) {
            body { padding: 10px; }
            .top { padding: 20px; flex-direction: column; }
            .invoice-no { text-align: left; }
            .content { padding: 20px; }
            .two-col { grid-template-columns: 1fr; }
        }
        @media print {
            body { background: #fff; padding: 0; }
            .invoice { box-shadow: none; border: 0; max-width: none; }
            .actions { display: none; }
        }
    </style>
</head>
<body>
    <div class="invoice">
        <div class="top">
            <div class="brand">
                <h1><?= h($businessName) ?></h1>
                <p>Chit Payment Invoice</p>
            </div>
            <div class="invoice-no">
                <strong><?= h($invoice['receipt_no']) ?></strong>
                <p>Collection Date:
                    <?= !empty($invoice['collection_date']) ? h(date('d-m-Y', strtotime($invoice['collection_date']))) : '—' ?>
                </p>
            </div>
        </div>

        <div class="content">
            <div class="two-col">
                <div class="box">
                    <div class="box-title">Customer</div>
                    <strong><?= h($invoice['customer_name']) ?></strong>
                    <p>Customer Code: <?= h($invoice['customer_code']) ?></p>
                    <p>Mobile: <?= h($invoice['mobile'] ?: '—') ?></p>
                    <?php if (!empty($invoice['email'])): ?>
                        <p>Email: <?= h($invoice['email']) ?></p>
                    <?php endif; ?>
                    <?php if ($customerAddress !== ''): ?>
                        <p><?= h($customerAddress) ?></p>
                    <?php endif; ?>
                </div>

                <div class="box">
                    <div class="box-title">Chit Details</div>
                    <strong><?= h($invoice['group_name']) ?></strong>
                    <p>Group: <?= h($invoice['group_no']) ?></p>
                    <p>Ticket: <?= h($invoice['ticket_no']) ?></p>
                    <p>Chit Type: <?= h($invoice['chit_type']) ?></p>
                    <p>Chit Value: ₹<?= number_format((float) $invoice['chit_value'], 2) ?></p>
                </div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Installment</th>
                        <th>Due Date</th>
                        <th>Payment Method</th>
                        <th class="amount">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>#<?= h($invoice['installment_no']) ?></td>
                        <td><?= !empty($invoice['due_date']) ? h(date('d-m-Y', strtotime($invoice['due_date']))) : '—' ?></td>
                        <td><?= h($invoice['method_name'] ?: '—') ?></td>
                        <td class="amount">₹<?= number_format((float) ($invoice['paid_amount'] ?? 0), 2) ?></td>
                    </tr>
                </tbody>
            </table>

            <div class="total">
                <div class="total-row">
                    <span>Due Amount</span>
                    <strong>₹<?= number_format((float) ($invoice['due_amount'] ?? 0), 2) ?></strong>
                </div>
                <div class="total-row">
                    <span>Paid Amount</span>
                    <strong>₹<?= number_format((float) ($invoice['paid_amount'] ?? 0), 2) ?></strong>
                </div>
                <div class="total-row">
                    <span>Discount</span>
                    <strong>₹<?= number_format((float) ($invoice['discount_amount'] ?? 0), 2) ?></strong>
                </div>
                <div class="total-row">
                    <span>Penalty</span>
                    <strong>₹<?= number_format((float) ($invoice['penalty_amount'] ?? 0), 2) ?></strong>
                </div>
                <div class="total-row grand">
                    <span>Net Paid</span>
                    <span>₹<?= number_format($netAmount, 2) ?></span>
                </div>
            </div>

            <?php if ($isGold): ?>
                <div class="gold">
                    <strong>Gold Savings</strong>
                    <div style="margin-top:7px;font-size:13px;">
                        Gold Rate:
                        <?php if ((float) ($invoice['gold_rate_per_gram'] ?? 0) > 0): ?>
                            ₹<?= number_format((float) $invoice['gold_rate_per_gram'], 2) ?>/g
                        <?php else: ?>
                            —
                        <?php endif; ?>
                        &nbsp; | &nbsp;
                        Gold Weight: <?= number_format((float) ($invoice['gold_weight_grams'] ?? 0), 6) ?> g
                        <?php if (!empty($invoice['metal_name']) || !empty($invoice['metal_code'])): ?>
                            &nbsp; | &nbsp;
                            <?= h($invoice['metal_name'] ?: $invoice['metal_code']) ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="footer">
            This is a system-generated chit payment invoice.
        </div>
    </div>

    <div class="actions">
        <button type="button" class="print-btn" onclick="window.print()">Print / Save PDF</button>
    </div>
</body>
</html>
