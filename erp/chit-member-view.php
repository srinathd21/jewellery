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
$businessId = (int) ($_SESSION['business_id'] ?? 0);
$memberId = (int) ($_GET['id'] ?? $_GET['member_id'] ?? 0);
if ($memberId <= 0)
    die('Invalid chit member.');
$stmt = $conn->prepare("SELECT cm.*,c.customer_code,c.customer_name,c.mobile,c.email,c.address_line1,c.city,c.state,c.pincode,cg.group_no,cg.group_name,cg.chit_type,cg.total_months,cg.installment_amount,cg.chit_value,cg.status group_status FROM chit_members cm INNER JOIN customers c ON c.id=cm.customer_id INNER JOIN chit_groups cg ON cg.id=cm.chit_group_id WHERE cm.id=? AND cm.business_id=? LIMIT 1");
$stmt->bind_param('ii', $memberId, $businessId);
$stmt->execute();
$m = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$m)
    die('Chit member not found.');
$memberId = (int) $m['id'];
$groupId = (int) $m['chit_group_id'];
$stmt = $conn->prepare("SELECT cc.*,ci.installment_no,ci.due_date,pm.method_name,
       m.metal_name,m.metal_code
FROM chit_collections cc
INNER JOIN chit_installments ci ON ci.id=cc.chit_installment_id
LEFT JOIN payment_methods pm ON pm.id=cc.payment_method_id
LEFT JOIN metals m ON m.id=cc.gold_metal_id AND m.business_id=cc.business_id
WHERE cc.chit_member_id=?
ORDER BY ci.installment_no");
$stmt->bind_param('i', $memberId);
$stmt->execute();
$collections = [];
$r = $stmt->get_result();
while ($x = $r->fetch_assoc())
    $collections[] = $x;
$stmt->close();

$paidInstallments = count(array_unique(array_map(
    static fn(array $row): int => (int) ($row['chit_installment_id'] ?? 0),
    $collections
)));
$totalMonths = (int) ($m['total_months'] ?? 0);
$totalGoldGrams = array_sum(array_map(
    static fn(array $row): float => (float)($row['gold_weight_grams'] ?? 0),
    $collections
));
$isClaimed = $totalMonths > 0 && $paidInstallments >= $totalMonths;

if (te($conn, 'chit_prizes')) {
    $claimStmt = $conn->prepare(
        "SELECT 1 FROM chit_prizes
         WHERE business_id=?
           AND chit_group_id=?
           AND chit_member_id=?
           AND status <> 'Cancelled'
         LIMIT 1"
    );
    if ($claimStmt) {
        $claimStmt->bind_param('iii', $businessId, $groupId, $memberId);
        $claimStmt->execute();
        if ($claimStmt->get_result()->fetch_row()) {
            $isClaimed = true;
        }
        $claimStmt->close();
    }
}

$displayStatus = $isClaimed ? 'Claimed' : (string) $m['status'];

$theme = ['primary_color' => '#d89416', 'primary_dark_color' => '#b86a0b', 'page_background' => '#f4f3f0', 'card_background' => '#fff', 'text_color' => '#171717', 'muted_text_color' => '#7d8794', 'border_color' => '#e8e8e8', 'font_family' => 'Inter', 'heading_font_family' => 'Playfair Display', 'border_radius_px' => 12];
if (te($conn, 'business_theme_settings')) {
    $s = $conn->prepare('SELECT * FROM business_theme_settings WHERE business_id=? LIMIT 1');
    $s->bind_param('i', $businessId);
    $s->execute();
    $tr = $s->get_result()->fetch_assoc() ?: [];
    $s->close();
    foreach ($theme as $k => $v)
        if (isset($tr[$k]) && $tr[$k] !== '')
            $theme[$k] = $tr[$k];
}
$pageTitle = 'View Chit Member';
$businessName = (string) ($_SESSION['business_name'] ?? 'Jewellery ERP');
?><!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= h($businessName) ?> - View Member</title><?php include 'includes/links.php'; ?>
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

        .heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px
        }

        .heading h1 {
            font-family: <?= json_encode($theme['heading_font_family']) ?>, serif;
            font-size: 18px;
            margin: 0
        }

        .panel {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: var(--r);
            overflow: hidden;
            margin-bottom: 10px
        }

        .head {
            padding: 11px 13px;
            border-bottom: 1px solid var(--line);
            font-size: 12px;
            font-weight: 800
        }

        .body {
            padding: 13px
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px
        }

        .info {
            border: 1px solid var(--line);
            border-radius: 9px;
            padding: 10px
        }

        .lbl {
            font-size: 8px;
            text-transform: uppercase;
            color: var(--muted);
            font-weight: 700
        }

        .val {
            font-size: 11px;
            font-weight: 700;
            margin-top: 3px
        }

        .status-claimed {
            display: inline-flex;
            align-items: center;
            padding: 4px 9px;
            border-radius: 999px;
            background: #e8f7ee;
            color: #168449;
            border: 1px solid #bfe8cf;
            font-size: 10px;
            font-weight: 800;
        }

        body.dark-mode .status-claimed,
        body[data-theme=dark] .status-claimed,
        html.dark-mode body .status-claimed,
        html[data-theme=dark] body .status-claimed {
            background: rgba(22, 132, 73, .18);
            color: #66d493;
            border-color: rgba(102, 212, 147, .30);
        }

        .table {
            font-size: 10px;
            margin: 0
        }

        .table td,
        .table th {
            background: var(--card) !important;
            color: var(--text);
            border-color: var(--line)
        }

        .btn-theme {
            background: linear-gradient(135deg, var(--p), var(--pd));
            color: #fff;
            border: 0
        }

        .btn-c {
            font-size: 10px;
            border-radius: 9px;
            padding: 8px 11px
        }

        body.dark-mode,
        body[data-theme=dark],
        html.dark-mode body,
        html[data-theme=dark] body {
            --bg: #0f151b;
            --card: #182129;
            --text: #f3f6f8;
            --muted: #9aa7b3;
            --line: #2c3944;
            color-scheme: dark
        }

        @media(max-width:800px) {
            .grid {
                grid-template-columns: repeat(2, 1fr)
            }
        }

        @media(max-width:550px) {
            .heading {
                align-items: flex-start;
                flex-direction: column;
                gap: 8px
            }

            .grid {
                grid-template-columns: 1fr
            }
        }
    </style>
</head>

<body><?php include 'includes/sidebar.php'; ?>
    <main class="app-main"><?php include 'includes/nav.php'; ?>
        <div class="content-wrap">
            <div class="heading">
                <div>
                    <h1><?= h($m['customer_name']) ?></h1>
                    <div class="small text-muted"><?= h($m['ticket_no']) ?> · <?= h($m['group_name']) ?></div>
                </div>
                <div class="d-flex gap-2"><a class="btn btn-light btn-c"
                        href="chit-members.php?group_id=<?= $groupId ?>">Back</a><?php if (!$isClaimed && $m['status'] === 'Active' && !in_array($m['group_status'], ['Closed', 'Cancelled'], true)): ?><a
                            class="btn btn-theme btn-c" href="chit-collection-add.php?member_id=<?= $memberId ?>">Collect
                            Payment</a><?php endif; ?></div>
            </div>
            <div class="panel">
                <div class="head">Member Information</div>
                <div class="body">
                    <div class="grid">
                        <?php
                        $items = [
                            'Customer Code' => $m['customer_code'],
                            'Mobile' => $m['mobile'] ?: '—',
                            'Email' => $m['email'] ?: '—',
                            'Ticket Number' => $m['ticket_no'],
                            'Join Date' => date('d-m-Y', strtotime($m['join_date'])),
                            'Nominee' => $m['nominee_name'] ?: '—',
                            'Relation' => $m['nominee_relation'] ?: '—',
                            'Status' => $displayStatus,
                            'Group' => $m['group_no'],
                            'Installment' => '₹' . number_format((float) $m['installment_amount'], 2),
                            'Total Months' => $m['total_months'],
                            'Chit Value' => '₹' . number_format((float) $m['chit_value'], 2)
                        ];

                        if (($m['chit_type'] ?? '') === 'Gold') {
                            $items['Total Gold Saved'] = number_format($totalGoldGrams, 6) . ' g';
                        }
                        foreach ($items as $l => $v): ?>
                            <div class="info">
                                <div class="lbl"><?= h($l) ?></div>
                                <div class="val">
                                    <?php if ($l === 'Status' && $isClaimed): ?>
                                        <span class="status-claimed">Claimed</span>
                                    <?php else: ?>
                                        <?= h($v) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="panel">
                <div class="head">Payment History (<?= count($collections) ?>)</div>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Installment</th>
                                <th>Due Date</th>
                                <th>Collection Date</th>
                                <th>Receipt</th>
                                <th>Due</th>
                                <th>Paid</th>
                                <th>Discount</th>
                                <th>Penalty</th>
                                <th>Net</th>
                                <?php if (($m['chit_type'] ?? '') === 'Gold'): ?>
                                    <th>Gold Rate</th>
                                    <th>Gold Grams</th>
                                <?php endif; ?>
                                <th>Method</th>
                            </tr>
                        </thead>
                        <tbody><?php foreach ($collections as $c): ?>
                                <tr>
                                    <td><?= h($c['installment_no']) ?></td>
                                    <td><?= h(date('d-m-Y', strtotime($c['due_date']))) ?></td>
                                    <td><?= h(date('d-m-Y', strtotime($c['collection_date']))) ?></td>
                                    <td><?= h($c['receipt_no']) ?></td>
                                    <td>₹<?= number_format((float) $c['due_amount'], 2) ?></td>
                                    <td>₹<?= number_format((float) $c['paid_amount'], 2) ?></td>
                                    <td>₹<?= number_format((float) $c['discount_amount'], 2) ?></td>
                                    <td>₹<?= number_format((float) $c['penalty_amount'], 2) ?></td>
                                    <td>₹<?= number_format((float) $c['net_amount'], 2) ?></td>
                                    <?php if (($m['chit_type'] ?? '') === 'Gold'): ?>
                                        <td>
                                            <?php if ((float)($c['gold_rate_per_gram'] ?? 0) > 0): ?>
                                                ₹<?= number_format((float)$c['gold_rate_per_gram'], 2) ?>/g
                                                <div class="small text-muted">
                                                    <?= h($c['metal_name'] ?: $c['metal_code'] ?: 'Gold') ?>
                                                    <?php if (!empty($c['gold_purity'])): ?>
                                                        · <?= number_format((float)$c['gold_purity'], 4) ?>%
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong><?= number_format((float)($c['gold_weight_grams'] ?? 0), 6) ?> g</strong>
                                            <?php if (!empty($c['gold_rate_effective_from'])): ?>
                                                <div class="small text-muted">
                                                    Rate date:
                                                    <?= h(date('d-m-Y', strtotime($c['gold_rate_effective_from']))) ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                    <td><?= h($c['method_name'] ?: '—') ?></td>
                                </tr><?php endforeach; ?><?php if (!$collections): ?>
                                <tr>
                                    <td colspan="<?= (($m['chit_type'] ?? '') === 'Gold') ? 12 : 10 ?>" class="text-center text-muted py-4">No payments collected.</td>
                                </tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div><?php include 'includes/footer.php'; ?>
        </div>
    </main><?php include 'includes/script.php'; ?>
    <script src="assets/js/script.js"></script>
</body>

</html>