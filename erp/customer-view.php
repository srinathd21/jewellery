<?php
if (session_status() === PHP_SESSION_NONE)
    session_start();
date_default_timezone_set((string) ($_SESSION['timezone'] ?? 'Asia/Kolkata'));
foreach ([__DIR__ . '/config/config.php', __DIR__ . '/config.php', __DIR__ . '/includes/config.php', __DIR__ . '/super-admin/includes/config.php'] as $f) {
    if (is_file($f)) {
        require_once $f;
        break;
    }
}
if (!isset($conn) || !($conn instanceof mysqli))
    die('Database configuration is not available.');
$conn->set_charset('utf8mb4');
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
function h($v): string
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
}
function te(mysqli $c, string $t): bool
{
    $t = $c->real_escape_string($t);
    $r = $c->query("SHOW TABLES LIKE '{$t}'");
    return $r && $r->num_rows > 0;
}
$bid = (int) ($_SESSION['business_id'] ?? 0);
$customerId = (int) ($_GET['id'] ?? 0);
if ($bid <= 0 || $customerId <= 0) {
    http_response_code(422);
    die('Invalid customer.');
}
$s = $conn->prepare('SELECT * FROM customers WHERE id=? AND business_id=? LIMIT 1');
$s->bind_param('ii', $customerId, $bid);
$s->execute();
$customer = $s->get_result()->fetch_assoc();
$s->close();
if (!$customer) {
    http_response_code(404);
    die('Customer not found.');
}
$services = [];
if (te($conn, 'customer_services')) {
    $s = $conn->prepare('SELECT service_type,joined_at,is_active FROM customer_services WHERE customer_id=? AND business_id=? ORDER BY service_type');
    $s->bind_param('ii', $customerId, $bid);
    $s->execute();
    $r = $s->get_result();
    while ($x = $r->fetch_assoc())
        $services[] = $x;
    $s->close();
}
$invoices = [];
if (te($conn, 'sales')) {
    $s = $conn->prepare("SELECT s.*,COALESCE((SELECT SUM(si.quantity) FROM sale_items si WHERE si.sale_id=s.id),0) item_count FROM sales s WHERE s.customer_id=? AND s.business_id=? ORDER BY s.invoice_date DESC,s.id DESC");
    $s->bind_param('ii', $customerId, $bid);
    $s->execute();
    $r = $s->get_result();
    while ($x = $r->fetch_assoc())
        $invoices[] = $x;
    $s->close();
}
$chits = [];
if (te($conn, 'chit_members') && te($conn, 'chit_groups')) {
    $sql = "SELECT cm.id member_id,cm.ticket_no,cm.join_date,cm.status member_status,cm.nominee_name,cm.nominee_relation,cg.id group_id,cg.group_no,cg.group_name,cg.chit_type,cg.start_date,cg.end_date,cg.total_months,cg.installment_amount,cg.chit_value,cg.status group_status,(SELECT COUNT(*) FROM chit_collections cc WHERE cc.chit_member_id=cm.id) paid_installments,(SELECT COALESCE(SUM(cc.net_amount),0) FROM chit_collections cc WHERE cc.chit_member_id=cm.id) received_amount FROM chit_members cm INNER JOIN chit_groups cg ON cg.id=cm.chit_group_id WHERE cm.customer_id=? AND cm.business_id=? ORDER BY cm.id DESC";
    $s = $conn->prepare($sql);
    $s->bind_param('ii', $customerId, $bid);
    $s->execute();
    $r = $s->get_result();
    while ($x = $r->fetch_assoc())
        $chits[] = $x;
    $s->close();
}
$theme = ['primary_color' => '#d89416', 'primary_dark_color' => '#b86a0b', 'page_background' => '#f4f3f0', 'card_background' => '#fff', 'text_color' => '#171717', 'muted_text_color' => '#7d8794', 'border_color' => '#e8e8e8', 'font_family' => 'Inter', 'heading_font_family' => 'Playfair Display', 'border_radius_px' => 12];
if (te($conn, 'business_theme_settings')) {
    $s = $conn->prepare('SELECT * FROM business_theme_settings WHERE business_id=? LIMIT 1');
    $s->bind_param('i', $bid);
    $s->execute();
    $t = $s->get_result()->fetch_assoc() ?: [];
    $s->close();
    foreach ($theme as $k => $v)
        if (isset($t[$k]) && $t[$k] !== '')
            $theme[$k] = $t[$k];
}
$currency = (string) ($_SESSION['currency_symbol'] ?? '₹');
$businessName = (string) ($_SESSION['business_name'] ?? 'Jewellery ERP');
$pageTitle = 'Customer Details';
$totalInvoice = array_sum(array_map(fn($x) => (float) $x['grand_total'], $invoices));
$totalPaid = array_sum(array_map(fn($x) => (float) $x['paid_amount'], $invoices));
$totalBalance = array_sum(array_map(fn($x) => (float) $x['balance_amount'], $invoices));
?><!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= h($businessName) ?> - Customer Details</title><?php include 'includes/links.php'; ?>
    <style>
        :root {
            --p: <?= h($theme['primary_color']) ?>;
            --pd: <?= h($theme['primary_dark_color']) ?>;
            --bg: <?= h($theme['page_background']) ?>;
            --card: <?= h($theme['card_background']) ?>;
            --text: <?= h($theme['text_color']) ?>;
            --muted: <?= h($theme['muted_text_color']) ?>;
            --line: <?= h($theme['border_color']) ?>;
            --r: <?= (int) $theme['border_radius_px'] ?>px
        }

        body {
            background: var(--bg);
            color: var(--text);
            font-family: <?= json_encode($theme['font_family']) ?>, sans-serif
        }

        .page-head {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            align-items: center;
            margin-bottom: 10px
        }

        .page-head h1 {
            font-family: <?= json_encode($theme['heading_font_family']) ?>, serif;
            font-size: 22px;
            margin: 0
        }

        .sub {
            font-size: 9px;
            color: var(--muted)
        }

        .grid4 {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            margin-bottom: 10px
        }

        .cardx,
        .panel {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: var(--r)
        }

        .cardx {
            padding: 13px
        }

        .label {
            font-size: 8px;
            text-transform: uppercase;
            color: var(--muted);
            font-weight: 700
        }

        .value {
            font-size: 17px;
            font-weight: 800;
            margin-top: 4px
        }

        .panel {
            overflow: hidden;
            margin-bottom: 10px
        }

        .panel-h {
            padding: 11px 13px;
            border-bottom: 1px solid var(--line);
            font-size: 12px;
            font-weight: 800
        }

        .panel-b {
            padding: 13px
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 9px
        }

        .info {
            border: 1px solid var(--line);
            border-radius: 9px;
            padding: 10px
        }

        .info .value {
            font-size: 11px
        }

        .table {
            font-size: 10px;
            margin: 0
        }

        .table th {
            font-size: 8px;
            text-transform: uppercase;
            color: var(--muted);
            white-space: nowrap
        }

        .table td,
        .table th {
            padding: 9px 10px;
            border-color: var(--line);
            background: var(--card) !important;
            color: var(--text)
        }

        .badge-soft {
            display: inline-flex;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 8px;
            font-weight: 800;
            background: #eaf8f0;
            color: #168449
        }

        .btn-theme {
            background: linear-gradient(135deg, var(--p), var(--pd));
            border: 0;
            color: #fff
        }

        .btn-c {
            font-size: 10px;
            border-radius: 8px;
            padding: 7px 10px
        }

        body.dark-mode,
        body[data-theme=dark],
        html.dark-mode body,
        html[data-theme=dark] body {
            --bg: #0f151b;
            --card: #182129;
            --text: #f3f6f8;
            --muted: #9aa7b3;
            --line: #2c3944
        }

        @media(max-width:900px) {

            .grid4,
            .info-grid {
                grid-template-columns: repeat(2, 1fr)
            }
        }

        @media(max-width:600px) {
            .page-head {
                align-items: flex-start;
                flex-direction: column
            }

            .grid4,
            .info-grid {
                grid-template-columns: 1fr
            }
        }
    </style>
</head>

<body><?php include 'includes/sidebar.php'; ?>
    <main class="app-main"><?php include 'includes/nav.php'; ?>
        <div class="content-wrap">
            <div class="page-head">
                <div>
                    <h1><?= h($customer['customer_name']) ?></h1>
                    <div class="sub"><?= h($customer['customer_code'] ?? '') ?> · Complete customer profile</div>
                </div>
                <div class="d-flex gap-2"><a href="customers.php" class="btn btn-light btn-c">Back</a><a
                        href="customer-edit.php?id=<?= $customerId ?>" class="btn btn-theme btn-c">Edit Customer</a></div>
            </div>
            <div class="grid4">
                <div class="cardx">
                    <div class="label">Invoices</div>
                    <div class="value"><?= count($invoices) ?></div>
                </div>
                <div class="cardx">
                    <div class="label">Invoice Value</div>
                    <div class="value"><?= h($currency) . number_format($totalInvoice, 2) ?></div>
                </div>
                <div class="cardx">
                    <div class="label">Paid</div>
                    <div class="value"><?= h($currency) . number_format($totalPaid, 2) ?></div>
                </div>
                <div class="cardx">
                    <div class="label">Balance</div>
                    <div class="value"><?= h($currency) . number_format($totalBalance, 2) ?></div>
                </div>
            </div>
            <div class="panel">
                <div class="panel-h">Customer Information</div>
                <div class="panel-b">
                    <div class="info-grid">
                        <?php $items = ['Customer Code' => $customer['customer_code'] ?? '—', 'Mobile' => $customer['mobile'] ?? '—', 'Alternate Mobile' => $customer['alternate_mobile'] ?? '—', 'Email' => $customer['email'] ?? '—', 'GSTIN' => $customer['gstin'] ?? '—', 'PAN' => $customer['pan_no'] ?? '—', 'Date of Birth' => $customer['date_of_birth'] ?? '—', 'Anniversary' => $customer['anniversary_date'] ?? '—', 'City' => $customer['city'] ?? '—', 'State' => $customer['state'] ?? '—', 'Pincode' => $customer['pincode'] ?? '—', 'Status' => ((int) ($customer['is_active'] ?? 1) === 1 ? 'Active' : 'Inactive')];
                        foreach ($items as $l => $v): ?>
                            <div class="info">
                                <div class="label"><?= h($l) ?></div>
                                <div class="value"><?= h($v ?: '—') ?></div>
                            </div><?php endforeach; ?>
                    </div>
                    <?php $address = trim(implode(', ', array_filter([$customer['address_line1'] ?? '', $customer['address_line2'] ?? '', $customer['city'] ?? '', $customer['state'] ?? '', $customer['pincode'] ?? ''])));
                    if ($address): ?>
                        <div class="info mt-3">
                            <div class="label">Address</div>
                            <div class="value"><?= h($address) ?></div>
                        </div><?php endif; ?>
                </div>
            </div>
            <div class="panel">
                <div class="panel-h">Services</div>
                <div class="panel-b d-flex gap-2 flex-wrap"><?php if ($services):
                    foreach ($services as $sv): ?><span
                                class="badge-soft"><?= h($sv['service_type']) ?> ·
                                <?= ((int) $sv['is_active'] === 1 ? 'Active' : 'Inactive') ?></span><?php endforeach; else: ?><span
                            class="sub">No services linked.</span><?php endif; ?></div>
            </div>
            <div class="panel">
                <div class="panel-h">Created Invoices (<?= count($invoices) ?>)</div>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Invoice</th>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Items</th>
                                <th>Total</th>
                                <th>Paid</th>
                                <th>Balance</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody><?php foreach ($invoices as $inv): ?>
                                <tr>
                                    <td><strong><?= h($inv['invoice_no']) ?></strong></td>
                                    <td><?= h(date('d-m-Y', strtotime($inv['invoice_date']))) ?></td>
                                    <td><?= h($inv['bill_type']) ?></td>
                                    <td><?= h($inv['item_count']) ?></td>
                                    <td><?= h($currency) . number_format((float) $inv['grand_total'], 2) ?></td>
                                    <td><?= h($currency) . number_format((float) $inv['paid_amount'], 2) ?></td>
                                    <td><?= h($currency) . number_format((float) $inv['balance_amount'], 2) ?></td>
                                    <td><span class="badge-soft"><?= h($inv['payment_status']) ?></span></td>
                                    <td><a class="btn btn-light btn-c"
                                            href="invoice-view.php?id=<?= (int) $inv['id'] ?>">View</a></td>
                                </tr><?php endforeach; ?><?php if (!$invoices): ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-4">No invoices created for this
                                        customer.</td>
                                </tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="panel">
                <div class="panel-h">Chit Details (<?= count($chits) ?>)</div>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Group</th>
                                <th>Ticket</th>
                                <th>Type</th>
                                <th>Join Date</th>
                                <th>Installment</th>
                                <th>Paid Months</th>
                                <th>Received</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody><?php foreach ($chits as $ch): ?>
                                <tr>
                                    <td><strong><?= h($ch['group_name']) ?></strong>
                                        <div class="sub"><?= h($ch['group_no']) ?></div>
                                    </td>
                                    <td><?= h($ch['ticket_no']) ?></td>
                                    <td><?= h($ch['chit_type']) ?></td>
                                    <td><?= h(date('d-m-Y', strtotime($ch['join_date']))) ?></td>
                                    <td><?= h($currency) . number_format((float) $ch['installment_amount'], 2) ?></td>
                                    <td><?= (int) $ch['paid_installments'] ?> / <?= (int) $ch['total_months'] ?></td>
                                    <td><?= h($currency) . number_format((float) $ch['received_amount'], 2) ?></td>
                                    <td><span class="badge-soft"><?= h($ch['member_status']) ?></span></td>
                                    <td><a class="btn btn-light btn-c"
                                            href="chit-member-view.php?id=<?= (int) $ch['member_id'] ?>">View</a></td>
                                </tr><?php endforeach; ?><?php if (!$chits): ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-4">No chit membership found.</td>
                                </tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php include 'includes/footer.php'; ?>
        </div>
    </main><?php include 'includes/script.php'; ?>
    <script src="assets/js/script.js"></script>
</body>

</html>