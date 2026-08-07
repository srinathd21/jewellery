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
function rows(mysqli $c, string $sql, string $types = '', array $params = []): array
{
    $s = $c->prepare($sql);
    if (!$s)
        throw new RuntimeException($c->error);
    if ($types !== '') {
        $a = [$types];
        foreach ($params as $k => $v)
            $a[] =& $params[$k];
        call_user_func_array([$s, 'bind_param'], $a);
    }
    if (!$s->execute())
        throw new RuntimeException($s->error);
    $r = $s->get_result();
    $o = [];
    while ($x = $r->fetch_assoc())
        $o[] = $x;
    $s->close();
    return $o;
}
$businessId = (int) ($_SESSION['business_id'] ?? 0);
$branchId = (int) ($_SESSION['branch_id'] ?? ($_SESSION['default_branch_id'] ?? 0));
$estimateId = (int) ($_GET['id'] ?? 0);
if ($businessId <= 0 || $branchId <= 0 || $estimateId <= 0)
    die('Invalid estimate.');
try {
    $er = rows($conn, "SELECT e.*,c.customer_code,c.email,c.gstin customer_gstin,c.address_line1,c.address_line2,c.city,c.state,c.pincode,COALESCE(u.full_name,u.username,'User') created_by_name FROM estimates e LEFT JOIN customers c ON c.id=e.customer_id AND c.business_id=e.business_id LEFT JOIN users u ON u.id=e.created_by WHERE e.id=? AND e.business_id=? AND e.branch_id=? LIMIT 1", 'iii', [$estimateId, $businessId, $branchId]);
    if (!$er)
        die('Estimate not found.');
    $estimate = $er[0];
    $items = rows($conn, 'SELECT * FROM estimate_items WHERE estimate_id=? AND business_id=? ORDER BY sort_order,id', 'ii', [$estimateId, $businessId]);
    $payments = rows($conn, 'SELECT ep.*,pm.method_name FROM estimate_payments ep LEFT JOIN payment_methods pm ON pm.id=ep.payment_method_id WHERE ep.estimate_id=? AND ep.business_id=? ORDER BY ep.id', 'ii', [$estimateId, $businessId]);
    $exchange = rows($conn, 'SELECT * FROM estimate_exchange_items WHERE estimate_id=? AND business_id=? ORDER BY id', 'ii', [$estimateId, $businessId]);
    $claims = rows($conn, "SELECT ec.*,cg.group_no,cg.group_name,cm.ticket_no,p.product_name FROM estimate_chit_claims ec LEFT JOIN chit_groups cg ON cg.id=ec.chit_group_id LEFT JOIN chit_members cm ON cm.id=ec.chit_member_id LEFT JOIN products p ON p.id=ec.product_id WHERE ec.estimate_id=? AND ec.business_id=? ORDER BY ec.id", 'ii', [$estimateId, $businessId]);
} catch (Throwable $x) {
    die('Unable to load estimate: ' . e($x->getMessage()));
}
$theme = ['primary_color' => '#d89416', 'primary_dark_color' => '#b86a0b', 'primary_soft_color' => '#fff6e5', 'page_background' => '#f4f3f0', 'card_background' => '#fff', 'text_color' => '#171717', 'muted_text_color' => '#7d8794', 'border_color' => '#e8e8e8', 'font_family' => 'Inter', 'heading_font_family' => 'Playfair Display', 'border_radius_px' => 12];
$s = $conn->prepare('SELECT * FROM business_theme_settings WHERE business_id=? LIMIT 1');
if ($s) {
    $s->bind_param('i', $businessId);
    $s->execute();
    $x = $s->get_result()->fetch_assoc() ?: [];
    $s->close();
    foreach ($theme as $k => $v)
        if (isset($x[$k]) && $x[$k] !== '')
            $theme[$k] = $x[$k];
}
$pageTitle = 'Estimate View';
function money($v): string
{
    return '₹' . number_format((float) $v, 2);
} ?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($estimate['estimate_no']) ?> - Estimate</title><?php include('includes/links.php'); ?>
    <style>
        :root {
            --primary: <?= e($theme['primary_color']) ?>;
            --primary-dark: <?= e($theme['primary_dark_color']) ?>;
            --primary-soft: <?= e($theme['primary_soft_color']) ?>;
            --page-bg: <?= e($theme['page_background']) ?>;
            --card-bg: <?= e($theme['card_background']) ?>;
            --text: <?= e($theme['text_color']) ?>;
            --muted: <?= e($theme['muted_text_color']) ?>;
            --line: <?= e($theme['border_color']) ?>;
            --radius: <?= (int) $theme['border_radius_px'] ?>px
        }

        body {
            background: var(--page-bg);
            color: var(--text);
            font-family: <?= json_encode($theme['font_family']) ?>, sans-serif
        }

        .app-main {
            min-height: 100vh;
            background: var(--page-bg)
        }

        .content-wrap {
            padding: 18px
        }

        .cardx {
            background: var(--card-bg);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            margin-bottom: 12px;
            overflow: hidden
        }

        .head {
            padding: 14px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px
        }

        .title {
            font: 700 20px
                <?= json_encode($theme['heading_font_family']) ?>
                , serif
        }

        .sub,
        .label {
            font-size: 9px;
            color: var(--muted)
        }

        .actions {
            display: flex;
            gap: 7px
        }

        .btn-theme,
        .btn-soft {
            border-radius: 9px;
            padding: 9px 13px;
            font-size: 11px;
            font-weight: 800;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px
        }

        .btn-theme {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: #fff;
            border: 0
        }

        .btn-soft {
            background: var(--card-bg);
            color: var(--text);
            border: 1px solid var(--line)
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            padding: 14px
        }

        .box {
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 11px
        }

        .value {
            font-size: 13px;
            font-weight: 800;
            margin-top: 3px
        }

        .section-head {
            padding: 11px 14px;
            border-bottom: 1px solid var(--line);
            font-size: 11px;
            font-weight: 900;
            text-transform: uppercase;
            color: var(--primary-dark)
        }

        .table {
            margin: 0;
            font-size: 10px;
            --bs-table-bg: var(--card-bg);
            --bs-table-color: var(--text);
            --bs-table-border-color: var(--line)
        }

        .table th {
            font-size: 9px;
            text-transform: uppercase;
            color: var(--muted);
            background: color-mix(in srgb, var(--muted) 7%, var(--card-bg));
            white-space: nowrap
        }

        .table td,
        .table th {
            padding: 9px;
            vertical-align: middle
        }

        .totals {
            max-width: 420px;
            margin-left: auto;
            padding: 14px
        }

        .line {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            border-bottom: 1px dashed var(--line);
            font-size: 10px
        }

        .line.total {
            font-size: 14px;
            font-weight: 900;
            color: var(--primary-dark)
        }

        .notes {
            padding: 14px;
            font-size: 11px
        }

        @media(max-width:900px) {
            .summary-grid {
                grid-template-columns: repeat(2, 1fr)
            }

            .head {
                align-items: flex-start;
                flex-direction: column
            }
        }

        @media(max-width:600px) {
            .summary-grid {
                grid-template-columns: 1fr
            }

            .content-wrap {
                padding: 74px 10px 10px
            }
        }
    </style>
</head>

<body><?php include('includes/sidebar.php'); ?>
    <main class="app-main"><?php include('includes/nav.php'); ?>
        <div class="content-wrap">
            <div class="cardx">
                <div class="head">
                    <div>
                        <div class="title">Estimate <?= e($estimate['estimate_no']) ?></div>
                        <div class="sub"><?= e(date('d-m-Y', strtotime($estimate['estimate_date']))) ?> ·
                            <?= e(date('h:i A', strtotime($estimate['estimate_time']))) ?> · <?= e($estimate['status']) ?>
                        </div>
                    </div>
                    <div class="actions"><a href="estimates-list.php" class="btn-soft"><i
                                class="fa-solid fa-arrow-left"></i>Estimates</a><a
                            href="estimate-print.php?id=<?= $estimateId ?>&inline=1" target="_blank" class="btn-theme"><i
                                class="fa-solid fa-print"></i>Print</a></div>
                </div>
            </div>
            <div class="cardx">
                <div class="summary-grid">
                    <div class="box">
                        <div class="label">Customer</div>
                        <div class="value"><?= e($estimate['customer_name'] ?: 'Walk-in Customer') ?></div>
                        <div class="sub">
                            <?= e(trim(($estimate['customer_code'] ?? '') . ' ' . ($estimate['customer_mobile'] ?? ''))) ?></div>
                    </div>
                    <div class="box">
                        <div class="label">Net Estimate</div>
                        <div class="value"><?= money($estimate['net_estimate_amount']) ?></div>
                    </div>
                    <div class="box">
                        <div class="label">Proposed Paid</div>
                        <div class="value"><?= money($estimate['proposed_paid_amount']) ?></div>
                    </div>
                    <div class="box">
                        <div class="label">Proposed Balance</div>
                        <div class="value"><?= money($estimate['proposed_balance_amount']) ?></div>
                    </div>
                    <div class="box">
                        <div class="label">Created By</div>
                        <div class="value"><?= e($estimate['created_by_name']) ?></div>
                    </div>
                    <div class="box">
                        <div class="label">Created At</div>
                        <div class="value"><?= e($estimate['created_at']) ?></div>
                    </div>
                    <div class="box">
                        <div class="label">Exchange</div>
                        <div class="value"><?= money($estimate['exchange_amount']) ?></div>
                    </div>
                    <div class="box">
                        <div class="label">Gold Claim</div>
                        <div class="value"><?= money($estimate['chit_claim_amount']) ?></div>
                    </div>
                </div>
            </div>
            <div class="cardx">
                <div class="section-head">Estimate Items</div>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th class="text-end">Qty</th>
                                <th class="text-end">Gross g</th>
                                <th class="text-end">Net g</th>
                                <th class="text-end">Rate</th>
                                <th class="text-end">Wastage</th>
                                <th class="text-end">Making</th>
                                <th class="text-end">Stone</th>
                                <th class="text-end">Other</th>
                                <th class="text-end">Discount</th>
                                <th class="text-end">GST</th>
                                <th class="text-end">Total</th>
                            </tr>
                        </thead>
                        <tbody><?php if (!$items): ?>
                                <tr>
                                    <td colspan="12" class="text-center text-muted py-4">No items.</td>
                                </tr><?php else:
                            foreach ($items as $r): ?>
                                    <tr>
                                        <td><strong><?= e($r['item_name']) ?></strong>
                                            <div class="sub">HSN: <?= e($r['hsn_code'] ?: '—') ?></div>
                                        </td>
                                        <td class="text-end"><?= number_format((float) $r['quantity'], 3) ?></td>
                                        <td class="text-end"><?= number_format((float) $r['gross_weight'], 3) ?></td>
                                        <td class="text-end"><?= number_format((float) $r['net_weight'], 3) ?></td>
                                        <td class="text-end"><?= money($r['metal_rate']) ?></td>
                                        <td class="text-end"><?= number_format((float) $r['wastage_percent'], 3) ?>%<div
                                                class="sub"><?= money($r['wastage_amount']) ?></div>
                                        </td>
                                        <td class="text-end"><?= money($r['making_charge']) ?></td>
                                        <td class="text-end"><?= money($r['stone_amount']) ?></td>
                                        <td class="text-end"><?= money($r['other_charge']) ?></td>
                                        <td class="text-end"><?= money($r['discount_amount']) ?></td>
                                        <td class="text-end"><?= number_format((float) $r['tax_percent'], 3) ?>%<div class="sub">
                                                <?= money($r['tax_amount']) ?></div>
                                        </td>
                                        <td class="text-end"><strong><?= money($r['line_total']) ?></strong></td>
                                    </tr><?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php if ($exchange): ?>
                <div class="cardx">
                    <div class="section-head">Proposed Exchange Items</div>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th class="text-end">Gross g</th>
                                    <th class="text-end">Wastage %</th>
                                    <th class="text-end">Eligible g</th>
                                    <th class="text-end">Rate / g</th>
                                    <th class="text-end">Value</th>
                                </tr>
                            </thead>
                            <tbody><?php foreach ($exchange as $r): ?>
                                    <tr>
                                        <td><?= e($r['item_name']) ?></td>
                                        <td class="text-end"><?= number_format((float) $r['gross_weight'], 3) ?></td>
                                        <td class="text-end"><?= number_format((float) $r['wastage_percent'], 3) ?></td>
                                        <td class="text-end"><?= number_format((float) $r['eligible_weight'], 3) ?></td>
                                        <td class="text-end"><?= money($r['rate_per_gram']) ?></td>
                                        <td class="text-end"><strong><?= money($r['exchange_value']) ?></strong></td>
                                    </tr><?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div><?php endif; ?>
            <?php if ($claims): ?>
                <div class="cardx">
                    <div class="section-head">Proposed Gold Gram Claims</div>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Chit</th>
                                    <th>Ticket</th>
                                    <th>Product</th>
                                    <th class="text-end">Claim g</th>
                                    <th class="text-end">Rate / g</th>
                                    <th class="text-end">Value</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody><?php foreach ($claims as $r): ?>
                                    <tr>
                                        <td><strong><?= e($r['group_name']) ?></strong>
                                            <div class="sub"><?= e($r['group_no']) ?></div>
                                        </td>
                                        <td><?= e($r['ticket_no']) ?></td>
                                        <td><?= e($r['product_name'] ?: '—') ?></td>
                                        <td class="text-end"><?= number_format((float) $r['claim_grams'], 6) ?> g</td>
                                        <td class="text-end"><?= money($r['rate_per_gram']) ?></td>
                                        <td class="text-end"><?= money($r['claim_amount']) ?></td>
                                        <td><?= e($r['status']) ?></td>
                                    </tr><?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div><?php endif; ?>
            <?php if ($payments): ?>
                <div class="cardx">
                    <div class="section-head">Proposed Payments</div>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Method</th>
                                    <th>Reference</th>
                                    <th class="text-end">Amount</th>
                                </tr>
                            </thead>
                            <tbody><?php foreach ($payments as $r): ?>
                                    <tr>
                                        <td><?= e($r['method_name'] ?: '—') ?></td>
                                        <td><?= e($r['reference_no'] ?: '—') ?></td>
                                        <td class="text-end"><strong><?= money($r['amount']) ?></strong></td>
                                    </tr><?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div><?php endif; ?>
            <div class="cardx">
                <div class="section-head">Estimate Summary</div>
                <div class="totals">
                    <div class="line"><span>Subtotal</span><strong><?= money($estimate['subtotal']) ?></strong></div>
                    <div class="line"><span>Discount</span><strong>- <?= money($estimate['discount_amount']) ?></strong>
                    </div>
                    <div class="line"><span>Taxable</span><strong><?= money($estimate['taxable_amount']) ?></strong></div>
                    <div class="line"><span>CGST</span><strong><?= money($estimate['cgst_amount']) ?></strong></div>
                    <div class="line"><span>SGST</span><strong><?= money($estimate['sgst_amount']) ?></strong></div>
                    <div class="line"><span>Exchange</span><strong>- <?= money($estimate['exchange_amount']) ?></strong>
                    </div>
                    <div class="line"><span>Gold Claim</span><strong>-
                            <?= money($estimate['chit_claim_amount']) ?></strong></div>
                    <div class="line total"><span>Net
                            Estimate</span><strong><?= money($estimate['net_estimate_amount']) ?></strong></div>
                </div><?php if ($estimate['notes']): ?>
                    <div class="notes"><strong>Notes:</strong> <?= nl2br(e($estimate['notes'])) ?></div><?php endif; ?>
            </div><?php include('includes/footer.php'); ?>
        </div>
    </main>
</body>

</html>