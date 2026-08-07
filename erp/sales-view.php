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
function e($v): string
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
}
function qAll(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $s = $conn->prepare($sql);
    if (!$s)
        throw new RuntimeException($conn->error);
    if ($types !== '') {
        $refs = [$types];
        foreach ($params as $k => $v) {
            $refs[] =& $params[$k];
        }
        call_user_func_array([$s, 'bind_param'], $refs);
    }
    if (!$s->execute())
        throw new RuntimeException($s->error);
    $r = $s->get_result();
    $out = [];
    while ($x = $r->fetch_assoc())
        $out[] = $x;
    $s->close();
    return $out;
}
$businessId = (int) ($_SESSION['business_id'] ?? 0);
$branchId = (int) ($_SESSION['branch_id'] ?? ($_SESSION['default_branch_id'] ?? 0));
$saleId = (int) ($_GET['id'] ?? 0);
if ($businessId <= 0 || $saleId <= 0)
    die('Invalid sale.');
if (empty($_SESSION['sales_payment_csrf']))
    $_SESSION['sales_payment_csrf'] = bin2hex(random_bytes(32));
$csrf = (string) $_SESSION['sales_payment_csrf'];
try {
    $sales = qAll($conn, "SELECT s.*,c.customer_code,c.email,c.address_line1,c.city,c.state,c.pincode,b.branch_name,b.gstin branch_gstin,b.mobile branch_mobile,b.address_line1 branch_address,b.city branch_city,b.state branch_state FROM sales s LEFT JOIN customers c ON c.id=s.customer_id AND c.business_id=s.business_id LEFT JOIN branches b ON b.id=s.branch_id WHERE s.id=? AND s.business_id=? LIMIT 1", 'ii', [$saleId, $businessId]);
    if (!$sales)
        die('Sale not found.');
    $sale = $sales[0];
    $items = qAll($conn, "SELECT si.*,p.product_code,p.barcode FROM sale_items si LEFT JOIN products p ON p.id=si.product_id WHERE si.sale_id=? AND si.business_id=? ORDER BY si.sort_order,si.id", 'ii', [$saleId, $businessId]);
    $payments = qAll($conn, "SELECT sp.*,pm.method_name,pm.method_type FROM sale_payments sp LEFT JOIN payment_methods pm ON pm.id=sp.payment_method_id WHERE sp.sale_id=? AND sp.business_id=? ORDER BY sp.id", 'ii', [$saleId, $businessId]);
    $claims = qAll($conn, "SELECT sc.*,cg.group_no,cg.group_name,cm.ticket_no,p.product_name FROM sales_chit_claims sc LEFT JOIN chit_groups cg ON cg.id=sc.chit_group_id LEFT JOIN chit_members cm ON cm.id=sc.chit_member_id LEFT JOIN products p ON p.id=sc.product_id WHERE sc.sale_id=? AND sc.business_id=? ORDER BY sc.id", 'ii', [$saleId, $businessId]);
    $exchange = qAll($conn, "SELECT * FROM sale_exchange_items WHERE sale_id=? AND business_id=? ORDER BY id", 'ii', [$saleId, $businessId]);
    $methods = qAll($conn, "SELECT id,method_name,method_type FROM payment_methods WHERE business_id=? AND is_active=1 AND method_type<>'Credit' ORDER BY method_name", 'i', [$businessId]);
} catch (Throwable $x) {
    die('Unable to load sale: ' . e($x->getMessage()));
}
$theme = ['primary_color' => '#d89416', 'primary_dark_color' => '#b86a0b', 'primary_soft_color' => '#fff6e5', 'page_background' => '#f4f3f0', 'card_background' => '#fff', 'text_color' => '#171717', 'muted_text_color' => '#7d8794', 'border_color' => '#e8e8e8', 'font_family' => 'Inter', 'heading_font_family' => 'Playfair Display', 'border_radius_px' => 12];
$t = $conn->prepare('SELECT * FROM business_theme_settings WHERE business_id=? LIMIT 1');
if ($t) {
    $t->bind_param('i', $businessId);
    $t->execute();
    $r = $t->get_result()->fetch_assoc() ?: [];
    $t->close();
    foreach ($theme as $k => $v)
        if (isset($r[$k]) && $r[$k] !== '')
            $theme[$k] = $r[$k];
}
function money($v)
{
    return number_format((float) $v, 2);
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($sale['invoice_no']) ?> - Sale Details</title><?php include('includes/links.php'); ?>
    <style>
        :root {
            --p: <?= e($theme['primary_color']) ?>;
            --pd: <?= e($theme['primary_dark_color']) ?>;
            --ps: <?= e($theme['primary_soft_color']) ?>;
            --bg: <?= e($theme['page_background']) ?>;
            --card: <?= e($theme['card_background']) ?>;
            --text: <?= e($theme['text_color']) ?>;
            --muted: <?= e($theme['muted_text_color']) ?>;
            --line: <?= e($theme['border_color']) ?>;
            --r: <?= (int) $theme['border_radius_px'] ?>px
        }

        body {
            background: var(--bg);
            color: var(--text);
            font-family: <?= json_encode($theme['font_family']) ?>, sans-serif
        }

        .page-card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: var(--r);
            margin-bottom: 10px
        }

        .head {
            padding: 14px 16px;
            border-bottom: 1px solid var(--line);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px
        }

        .title {
            font: 700 21px
                <?= json_encode($theme['heading_font_family']) ?>
                , serif
        }

        .body {
            padding: 14px
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 9px
        }

        .box {
            padding: 10px;
            border: 1px solid var(--line);
            border-radius: 9px
        }

        .lbl {
            font-size: 9px;
            color: var(--muted);
            text-transform: uppercase
        }

        .val {
            font-size: 12px;
            font-weight: 800
        }

        .section {
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            color: var(--pd)
        }

        .btn-theme {
            border: 0;
            border-radius: 8px;
            padding: 8px 12px;
            background: linear-gradient(135deg, var(--p), var(--pd));
            color: #fff;
            font-size: 11px;
            font-weight: 800
        }

        .btn-soft {
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 8px 12px;
            background: var(--card);
            color: var(--text);
            font-size: 11px
        }

        .table {
            font-size: 10px
        }

        .table th {
            font-size: 9px;
            text-transform: uppercase;
            color: var(--muted);
            white-space: nowrap
        }

        .summary {
            max-width: 440px;
            margin-left: auto
        }

        .sumrow {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            border-bottom: 1px dashed var(--line);
            font-size: 11px
        }

        .sumrow.total {
            font-size: 15px;
            font-weight: 900;
            color: var(--pd)
        }

        .badge {
            font-size: 9px
        }

        .print-modal .modal-dialog {
            max-width: 980px
        }

        .print-modal .modal-content {
            height: 88vh
        }

        .print-modal .modal-header {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            align-items: center;
            gap: 16px;
            padding: 14px 18px
        }

        .print-modal-title {
            min-width: 0
        }

        .print-modal-actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 10px
        }

        .print-modal-actions .btn-theme {
            height: 38px;
            min-height: 38px;
            padding: 7px 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            margin: 0
        }

        .print-modal-actions .btn-close {
            width: 38px;
            height: 38px;
            min-width: 38px;
            padding: 0;
            margin: 0;
            border: 1px solid var(--line);
            border-radius: 8px;
            background-color: var(--card);
            background-size: 13px;
            box-sizing: border-box;
            opacity: .75
        }

        .print-modal-actions .btn-close:hover {
            opacity: 1;
            background-color: var(--ps)
        }

        .print-modal .modal-body {
            padding: 0
        }

        .print-frame {
            width: 100%;
            height: 100%;
            border: 0
        }

        .theme-toast {
            position: fixed;
            right: 18px;
            top: 18px;
            z-index: 20000;
            padding: 11px 14px;
            border-radius: 9px;
            color: #fff;
            opacity: 0;
            transform: translateY(-8px);
            transition: .2s
        }

        .theme-toast.show {
            opacity: 1;
            transform: none
        }

        .theme-toast-success {
            background: #168449
        }

        .theme-toast-error {
            background: #c0392b
        }

        @media(max-width:900px) {
            .grid {
                grid-template-columns: 1fr 1fr
            }
        }

        @media(max-width:600px) {
            .grid {
                grid-template-columns: 1fr
            }

            .head {
                align-items: flex-start;
                flex-direction: column
            }

            .print-modal .modal-header {
                grid-template-columns: 1fr;
                gap: 10px
            }

            .print-modal-actions {
                justify-content: flex-start
            }

            .print-modal-actions .btn-theme {
                flex: 1
            }
        }
    </style>
</head>

<body><?php include('includes/sidebar.php'); ?>
    <main class="app-main"><?php include('includes/nav.php'); ?>
        <div class="content-wrap">
            <div class="page-card">
                <div class="head">
                    <div>
                        <div class="title">Sale <?= e($sale['invoice_no']) ?></div>
                        <div class="small text-muted"><?= e(date('d-m-Y', strtotime($sale['invoice_date']))) ?> ·
                            <?= e(date('h:i A', strtotime($sale['invoice_time']))) ?></div>
                    </div>
                    <div class="d-flex flex-wrap gap-2"><a href="sales-list.php"
                            class="btn-soft text-decoration-none"><i
                                class="fa-solid fa-arrow-left me-1"></i>Sales</a><button class="btn-soft"
                            id="previewPrint"><i
                                class="fa-solid fa-print me-1"></i>Print</button><?php if ((float) $sale['balance_amount'] > 0 && $sale['workflow_status'] !== 'Cancelled'): ?><a href="sale-make-payment.php?id=<?= $saleId ?>" class="btn-theme text-decoration-none"><i
                                    class="fa-solid fa-indian-rupee-sign me-1"></i>Make Payment</a><?php endif; ?></div>
                </div>
                <div class="body">
                    <div class="grid">
                        <div class="box">
                            <div class="lbl">Customer</div>
                            <div class="val"><?= e($sale['customer_name'] ?: 'Walk-in Customer') ?></div>
                            <div class="small text-muted"><?= e($sale['customer_mobile']) ?></div>
                        </div>
                        <div class="box">
                            <div class="lbl">Bill Type</div>
                            <div class="val"><?= e($sale['bill_type']) ?></div>
                        </div>
                        <div class="box">
                            <div class="lbl">Payment Status</div>
                            <div class="val"><?= e($sale['payment_status']) ?></div>
                        </div>
                        <div class="box">
                            <div class="lbl">Workflow</div>
                            <div class="val"><?= e($sale['workflow_status']) ?></div>
                        </div>
                        <div class="box">
                            <div class="lbl">Subtotal</div>
                            <div class="val">₹<?= money($sale['subtotal']) ?></div>
                        </div>
                        <div class="box">
                            <div class="lbl">Discount</div>
                            <div class="val">₹<?= money($sale['discount_amount']) ?></div>
                        </div>
                        <div class="box">
                            <div class="lbl">Exchange</div>
                            <div class="val">₹<?= money($sale['exchange_amount']) ?></div>
                        </div>
                        <div class="box">
                            <div class="lbl">Gold Claim</div>
                            <div class="val">₹<?= money($sale['chit_claim_amount']) ?></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="page-card">
                <div class="head">
                    <div class="section">Bill Items</div>
                </div>
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Qty</th>
                                <th>Gross</th>
                                <th>Stone</th>
                                <th>Net</th>
                                <th>Rate</th>
                                <th>Wastage</th>
                                <th>Making</th>
                                <th>Discount</th>
                                <th>GST</th>
                                <th class="text-end">Total</th>
                            </tr>
                        </thead>
                        <tbody><?php foreach ($items as $i): ?>
                                <tr>
                                    <td><strong><?= e($i['item_name']) ?></strong>
                                        <div class="small text-muted"><?= e($i['product_code'] ?? '') ?>
                                            <?= e($i['hsn_code'] ?? '') ?></div>
                                    </td>
                                    <td><?= e($i['quantity']) ?></td>
                                    <td><?= e($i['gross_weight']) ?> g</td>
                                    <td><?= e($i['stone_weight']) ?> g</td>
                                    <td><?= e($i['net_weight']) ?> g</td>
                                    <td>₹<?= money($i['metal_rate']) ?></td>
                                    <td><?= e($i['wastage_percent']) ?>% / ₹<?= money($i['wastage_amount']) ?></td>
                                    <td>₹<?= money($i['making_charge']) ?></td>
                                    <td>₹<?= money($i['discount_amount']) ?></td>
                                    <td><?= e($i['tax_percent']) ?>% / ₹<?= money($i['tax_amount']) ?></td>
                                    <td class="text-end"><strong>₹<?= money($i['line_total']) ?></strong></td>
                                </tr><?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php if ($exchange): ?>
                <div class="page-card">
                    <div class="head">
                        <div class="section">Exchange Details</div>
                    </div>
                    <div class="table-responsive">
                        <table class="table mb-0">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th>Gross</th>
                                    <th>Wastage</th>
                                    <th>Eligible</th>
                                    <th>Rate</th>
                                    <th class="text-end">Value</th>
                                </tr>
                            </thead>
                            <tbody><?php foreach ($exchange as $x): ?>
                                    <tr>
                                        <td><?= e($x['item_name']) ?></td>
                                        <td><?= e($x['gross_weight']) ?> g</td>
                                        <td><?= e($x['wastage_percent']) ?>%</td>
                                        <td><?= e($x['eligible_weight']) ?> g</td>
                                        <td>₹<?= money($x['rate_per_gram']) ?></td>
                                        <td class="text-end">₹<?= money($x['exchange_value']) ?></td>
                                    </tr><?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div><?php endif; ?>
            <?php if ($claims): ?>
                <div class="page-card">
                    <div class="head">
                        <div class="section">Gold Gram Claim Details</div>
                    </div>
                    <div class="table-responsive">
                        <table class="table mb-0">
                            <thead>
                                <tr>
                                    <th>Chit</th>
                                    <th>Ticket</th>
                                    <th>Product</th>
                                    <th>Claim Grams</th>
                                    <th>Rate</th>
                                    <th class="text-end">Claim Value</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody><?php foreach ($claims as $c): ?>
                                    <tr>
                                        <td><?= e($c['group_name']) ?>
                                            <div class="small text-muted"><?= e($c['group_no']) ?></div>
                                        </td>
                                        <td><?= e($c['ticket_no']) ?></td>
                                        <td><?= e($c['product_name']) ?></td>
                                        <td><strong><?= number_format((float) $c['claim_grams'], 6) ?> g</strong></td>
                                        <td>₹<?= money($c['rate_per_gram']) ?></td>
                                        <td class="text-end">₹<?= money($c['claim_amount']) ?></td>
                                        <td><?= e($c['status']) ?></td>
                                    </tr><?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div><?php endif; ?>
            <div class="page-card" id="payment">
                <div class="head">
                    <div class="section">Payment Details</div>
                </div>
                <div class="body">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Method</th>
                                    <th>Reference</th>
                                    <th class="text-end">Amount</th>
                                </tr>
                            </thead>
                            <tbody><?php if ($payments):
                                foreach ($payments as $p): ?>
                                        <tr>
                                            <td><?= e(date('d-m-Y h:i A', strtotime($p['payment_date']))) ?></td>
                                            <td><?= e($p['method_name']) ?></td>
                                            <td><?= e($p['reference_no'] ?: '-') ?></td>
                                            <td class="text-end">₹<?= money($p['amount']) ?></td>
                                        </tr><?php endforeach; else: ?>
                                    <tr>
                                        <td colspan="4" class="text-center text-muted">No payments recorded.</td>
                                    </tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="summary">
                        <div class="sumrow"><span>Grand Total</span><strong>₹<?= money($sale['grand_total']) ?></strong>
                        </div>
                        <div class="sumrow"><span>Exchange Deduction</span><strong>-
                                ₹<?= money($sale['exchange_amount']) ?></strong></div>
                        <div class="sumrow"><span>Gold Claim Deduction</span><strong>-
                                ₹<?= money($sale['chit_claim_amount']) ?></strong></div>
                        <div class="sumrow"><span>Net
                                Payable</span><strong>₹<?= money($sale['net_payable_amount']) ?></strong></div>
                        <div class="sumrow"><span>Paid</span><strong>₹<?= money($sale['paid_amount']) ?></strong></div>
                        <div class="sumrow total">
                            <span>Balance</span><strong>₹<?= money($sale['balance_amount']) ?></strong></div>
                    </div>
                </div>
            </div>
            <?php include('includes/footer.php'); ?>
        </div>
    </main>
    <div class="modal fade print-modal" id="printModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <div class="print-modal-title">
                        <h5 class="modal-title mb-0">Invoice Preview</h5>
                    </div>
                    <div class="print-modal-actions"><button type="button" class="btn-theme" id="doPrint"><i
                                class="fa-solid fa-print"></i><span>Print</span></button><button type="button"
                            class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                </div>
                <div class="modal-body"><iframe class="print-frame" id="printFrame"></iframe></div>
            </div>
        </div>
    </div>
    <?php include('includes/script.php'); ?>
    <script src="assets/js/script.js"></script>
    <script>
        (() => { 'use strict'; function toast(t, m) { const x = document.createElement('div'); x.className = 'theme-toast theme-toast-' + t; x.textContent = m; document.body.appendChild(x); requestAnimationFrame(() => x.classList.add('show')); setTimeout(() => { x.classList.remove('show'); setTimeout(() => x.remove(), 250) }, 3200) } document.getElementById('previewPrint').addEventListener('click', () => { document.getElementById('printFrame').src = 'sale-invoice-pdf.php?sale_id=<?= $saleId ?>&inline=1'; bootstrap.Modal.getOrCreateInstance(document.getElementById('printModal')).show() }); document.getElementById('doPrint').addEventListener('click', () => { const f = document.getElementById('printFrame'); try { f.contentWindow.focus(); f.contentWindow.print() } catch (e) { toast('error', 'Use the PDF viewer print button.') } }); })();
    </script>
</body>

</html>