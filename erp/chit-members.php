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
mysqli_report(MYSQLI_REPORT_OFF);
$conn->set_charset('utf8mb4');
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
function h($v): string
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
}
function tableExists(mysqli $c, string $t): bool
{
    $t = $c->real_escape_string($t);
    $r = $c->query("SHOW TABLES LIKE '{$t}'");
    return $r && $r->num_rows > 0;
}
function perm(string $action): bool
{
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin')
        return true;
    $map = ['open' => 'can_open', 'view' => 'can_view', 'value' => 'can_view_value', 'create' => 'can_create', 'update' => 'can_update'];
    $field = $map[$action] ?? '';
    foreach (['perm.chit.collections', 'perm.chit.members', 'perm.chit.groups', 'perm.chit'] as $p)
        if ($field && isset($_SESSION['permissions'][$p][$field]))
            return (int) $_SESSION['permissions'][$p][$field] === 1;
    $admins = ['platform admin', 'super admin', 'admin', 'business admin', 'manager', 'billing', 'super_admin', 'business_admin'];
    foreach (['user_type', 'role_name', 'role_code'] as $k)
        if (in_array(strtolower(trim((string) ($_SESSION[$k] ?? ''))), $admins, true))
            return true;
    return false;
}
$businessId = (int) ($_SESSION['business_id'] ?? 0);
$branchId = (int) ($_SESSION['branch_id'] ?? $_SESSION['default_branch_id'] ?? 0);
$groupId = (int) ($_GET['group_id'] ?? $_GET['id'] ?? 0);
if ($businessId <= 0 || $branchId <= 0 || $groupId <= 0 || !perm('open')) {
    http_response_code(403);
    die('Invalid chit group or access denied.');
}
foreach (['chit_groups', 'chit_members', 'customers', 'chit_installments', 'chit_collections', 'chit_prizes'] as $t)
    if (!tableExists($conn, $t))
        die("Required table `{$t}` was not found.");
$stmt = $conn->prepare("SELECT cg.*, (SELECT COUNT(*) FROM chit_members cm WHERE cm.chit_group_id=cg.id) member_count FROM chit_groups cg WHERE cg.id=? AND cg.business_id=? LIMIT 1");
$stmt->bind_param('ii', $groupId, $businessId);
$stmt->execute();
$group = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$group) {
    http_response_code(404);
    die('Chit group not found.');
}
$groupId = (int) $group['id'];
$canCreate = perm('create');
$canValue = perm('value') || perm('view');
$sql = "SELECT cm.*,c.customer_code,c.customer_name,c.mobile,c.email,
            COUNT(DISTINCT cc.id) paid_installments,
            COALESCE(SUM(cc.paid_amount),0) total_paid,
            COALESCE(SUM(cc.discount_amount),0) total_discount,
            COALESCE(SUM(cc.penalty_amount),0) total_penalty,
            COALESCE(SUM(cc.net_amount),0) total_received,
            MAX(cc.collection_date) last_payment_date,
            CASE WHEN EXISTS (
                SELECT 1 FROM chit_prizes cp
                WHERE cp.chit_member_id=cm.id
                  AND cp.chit_group_id=cm.chit_group_id
                  AND cp.business_id=cm.business_id
                  AND cp.status <> 'Cancelled'
            ) THEN 1 ELSE 0 END AS is_claimed
      FROM chit_members cm
      INNER JOIN customers c ON c.id=cm.customer_id
      LEFT JOIN chit_collections cc ON cc.chit_member_id=cm.id AND cc.chit_group_id=cm.chit_group_id
      WHERE cm.chit_group_id=? AND cm.business_id=?
      GROUP BY cm.id,c.id
      ORDER BY cm.ticket_no,cm.id";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ii', $groupId, $businessId);
$stmt->execute();
$members = [];
$r = $stmt->get_result();
while ($row = $r->fetch_assoc())
    $members[] = $row;
$stmt->close();
$theme = ['primary_color' => '#d89416', 'primary_dark_color' => '#b86a0b', 'page_background' => '#f4f3f0', 'card_background' => '#fff', 'text_color' => '#171717', 'muted_text_color' => '#7d8794', 'border_color' => '#e8e8e8', 'font_family' => 'Inter', 'heading_font_family' => 'Playfair Display', 'border_radius_px' => 12];
if (tableExists($conn, 'business_theme_settings')) {
    $s = $conn->prepare('SELECT * FROM business_theme_settings WHERE business_id=? LIMIT 1');
    if ($s) {
        $s->bind_param('i', $businessId);
        $s->execute();
        $tr = $s->get_result()->fetch_assoc() ?: [];
        $s->close();
        foreach ($theme as $k => $v)
            if (isset($tr[$k]) && $tr[$k] !== '')
                $theme[$k] = $tr[$k];
    }
}
$pageTitle = 'Chit Members';
$page_title = 'Chit Members';
$currentPage = 'chit-members';
$businessName = (string) ($_SESSION['business_name'] ?? 'Jewellery ERP');
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= h($businessName) ?> - Chit Members</title><?php include 'includes/links.php'; ?>
    <style>
        :root {
            --primary: <?= h($theme['primary_color']) ?>;
            --primary-dark: <?= h($theme['primary_dark_color']) ?>;
            --page-bg: <?= h($theme['page_background']) ?>;
            --card-bg: <?= h($theme['card_background']) ?>;
            --text: <?= h($theme['text_color']) ?>;
            --muted: <?= h($theme['muted_text_color']) ?>;
            --line: <?= h($theme['border_color']) ?>;
            --radius: <?= (int) $theme['border_radius_px'] ?>px
        }

        body {
            background: var(--page-bg);
            color: var(--text);
            font-family: <?= json_encode($theme['font_family']) ?>, sans-serif
        }

        .heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px
        }

        .heading h1 {
            font-family: <?= json_encode($theme['heading_font_family']) ?>, serif;
            font-size: 18px;
            margin: 0
        }

        .sub {
            font-size: 9px;
            color: var(--muted)
        }

        .summary {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            margin-bottom: 10px
        }

        .cardx,
        .panel {
            background: var(--card-bg);
            border: 1px solid var(--line);
            border-radius: var(--radius)
        }

        .cardx {
            padding: 12px
        }

        .lbl {
            font-size: 8px;
            text-transform: uppercase;
            color: var(--muted);
            font-weight: 700
        }

        .val {
            font-size: 16px;
            font-weight: 800;
            margin-top: 3px
        }

        .panel {
            overflow: hidden
        }

        .toolbar {
            padding: 11px 13px;
            border-bottom: 1px solid var(--line);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px
        }

        .form-control {
            font-size: 10px;
            min-height: 36px;
            border-radius: 9px;
            background: var(--card-bg);
            color: var(--text);
            border-color: var(--line)
        }

        .table {
            font-size: 10px;
            margin: 0
        }

        .table th {
            font-size: 8px;
            text-transform: uppercase;
            color: var(--muted)
        }

        .table td,
        .table th {
            padding: 9px 10px;
            border-color: var(--line);
            background: var(--card-bg) !important;
            color: var(--text)
        }

        .btn-theme {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border: 0;
            color: #fff
        }

        .btn-custom {
            font-size: 10px;
            border-radius: 9px;
            padding: 7px 10px
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

        .progress {
            height: 6px;
            background: var(--line)
        }

        .progress-bar {
            background: linear-gradient(90deg, var(--primary), var(--primary-dark))
        }

        .theme-toast {
            position: fixed;
            right: 18px;
            top: 78px;
            z-index: 20000;
            padding: 11px 14px;
            border-radius: 10px;
            color: #fff;
            font-size: 11px;
            font-weight: 600;
            opacity: 0;
            transform: translateY(-10px);
            transition: .22s
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

        body.dark-mode,
        body[data-theme=dark],
        html.dark-mode body,
        html[data-theme=dark] body {
            --page-bg: #0f151b;
            --card-bg: #182129;
            --text: #f3f6f8;
            --muted: #9aa7b3;
            --line: #2c3944;
            color-scheme: dark
        }

        @media(max-width:900px) {
            .summary {
                grid-template-columns: repeat(2, 1fr)
            }

            .responsive thead {
                display: none
            }

            .responsive tbody {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
                padding: 10px
            }

            .responsive tr {
                display: grid;
                grid-template-columns: 1fr 1fr;
                border: 1px solid var(--line);
                border-radius: var(--radius);
                padding: 12px
            }

            .responsive td {
                display: flex;
                justify-content: space-between;
                gap: 10px;
                border: 0;
                border-bottom: 1px dashed var(--line)
            }

            .responsive td:before {
                content: attr(data-label);
                font-size: 8px;
                color: var(--muted);
                text-transform: uppercase;
                font-weight: 700
            }

            .responsive td.main {
                grid-column: 1/-1;
                display: block
            }

            .responsive td.main:before {
                display: none
            }
        }

        @media(max-width:600px) {

            .heading,
            .toolbar {
                align-items: flex-start;
                flex-direction: column
            }

            .summary,
            .responsive tbody {
                grid-template-columns: 1fr
            }

            .responsive tr {
                grid-template-columns: 1fr
            }

            .responsive td {
                grid-column: 1/-1
            }

            .theme-toast {
                left: 12px;
                right: 12px;
                top: 70px
            }
        }
    </style>
</head>

<body><?php include 'includes/sidebar.php'; ?>
    <main class="app-main"><?php include 'includes/nav.php'; ?>
        <div class="content-wrap">
            <div class="heading">
                <div>
                    <h1><?= h($group['group_name']) ?> Members</h1>
                    <div class="sub"><?= h($group['group_no']) ?> · View members and collect installments</div>
                </div>
                <div class="d-flex gap-2"><a href="chit-groups.php"
                        class="btn btn-light btn-custom">Back</a><?php if ($canCreate && !in_array($group['status'], ['Closed', 'Cancelled'], true)): ?><a
                            href="chit-member-add.php?group_id=<?= $groupId ?>" class="btn btn-theme btn-custom"><i
                                class="fa-solid fa-user-plus me-1"></i>Create Member</a><?php endif; ?></div>
            </div>
            <div class="summary">
                <div class="cardx">
                    <div class="lbl">Members</div>
                    <div class="val"><?= count($members) ?> / <?= (int) $group['total_members'] ?></div>
                </div>
                <div class="cardx">
                    <div class="lbl">Months</div>
                    <div class="val"><?= (int) $group['total_months'] ?></div>
                </div>
                <div class="cardx">
                    <div class="lbl">Installment</div>
                    <div class="val"><?= $canValue ? '₹' . number_format((float) $group['installment_amount'], 2) : '••••' ?>
                    </div>
                </div>
                <div class="cardx">
                    <div class="lbl">Status</div>
                    <div class="val"><?= h($group['status']) ?></div>
                </div>
            </div>
            <div class="panel">
                <div class="toolbar">
                    <div><strong>Member Register</strong>
                        <div class="sub">Payment progress is calculated from chit collections.</div>
                    </div><input id="memberSearch" class="form-control" style="max-width:280px"
                        placeholder="Search ticket, customer or mobile">
                </div>
                <div class="table-responsive">
                    <table class="table responsive">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Member</th>
                                <th>Contact</th>
                                <th>Joined</th>
                                <th>Paid</th>
                                <th>Progress</th>
                                <th>Status</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody id="memberRows">
                            <?php foreach ($members as $i => $m):
                                $paid = (int) $m['paid_installments'];
                                $months = (int) $group['total_months'];
                                $pct = $months > 0 ? min(100, $paid / $months * 100) : 0; ?>
                                <tr
                                    data-search="<?= h(strtolower($m['ticket_no'] . ' ' . $m['customer_name'] . ' ' . $m['mobile'] . ' ' . $m['customer_code'])) ?>">
                                    <td data-label="#"><?= $i + 1 ?></td>
                                    <td class="main" data-label="Member"><strong><?= h($m['customer_name']) ?></strong>
                                        <div class="sub"><?= h($m['ticket_no']) ?> · <?= h($m['customer_code']) ?></div>
                                    </td>
                                    <td data-label="Contact"><?= h($m['mobile'] ?: '—') ?></td>
                                    <td data-label="Joined"><?= h(date('d-m-Y', strtotime($m['join_date']))) ?></td>
                                    <td data-label="Paid"><?= $paid ?> / <?= $months ?>
                                        <div class="sub">
                                            <?= $canValue ? '₹' . number_format((float) $m['total_received'], 2) : '••••' ?></div>
                                    </td>
                                    <td data-label="Progress">
                                        <div class="progress">
                                            <div class="progress-bar" style="width:<?= number_format($pct, 1, '.', '') ?>%">
                                            </div>
                                        </div>
                                        <div class="sub mt-1"><?= number_format($pct, 0) ?>%</div>
                                    </td>
                                    <?php
                                    $displayStatus = (!empty($m['is_claimed']) || ($months > 0 && $paid >= $months))
                                        ? 'Claimed'
                                        : (string) $m['status'];
                                    ?>
                                    <td data-label="Status"><span class="badge-soft"><?= h($displayStatus) ?></span></td>
                                    <td data-label="Action" class="text-end">
                                        <div class="d-flex gap-1 justify-content-end flex-wrap"><a
                                                class="btn btn-light btn-custom"
                                                href="chit-member-view.php?id=<?= (int) $m['id'] ?>"><i
                                                    class="fa-solid fa-eye me-1"></i>View</a><?php if ($canCreate && $m['status'] === 'Active' && !in_array($group['status'], ['Closed', 'Cancelled'], true)): ?><a
                                                    class="btn btn-theme btn-custom"
                                                    href="chit-collection-add.php?member_id=<?= (int) $m['id'] ?>"><i
                                                        class="fa-solid fa-indian-rupee-sign me-1"></i>Collect
                                                    Payment</a><?php endif; ?></div>
                                    </td>
                                </tr>
                            <?php endforeach; ?><?php if (!$members): ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-4">No members have joined this chit
                                        group.</td>
                                </tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php include 'includes/footer.php'; ?>
        </div>
    </main><?php include 'includes/script.php'; ?>
    <script src="assets/js/script.js"></script>
    <script>
        (() => { const q = document.getElementById('memberSearch'); q?.addEventListener('input', () => { const t = q.value.trim().toLowerCase(); document.querySelectorAll('#memberRows tr[data-search]').forEach(r => r.style.display = r.dataset.search.includes(t) ? '' : 'none') }); const p = new URLSearchParams(location.search); const code = p.get('msg'); const map = { member_added: 'Chit member added successfully.', payment_collected: 'Payment collected successfully.' }; if (code && map[code]) { const e = document.createElement('div'); e.className = 'theme-toast theme-toast-success'; e.textContent = map[code]; document.body.appendChild(e); requestAnimationFrame(() => e.classList.add('show')); setTimeout(() => { e.classList.remove('show'); setTimeout(() => e.remove(), 250) }, 3500); p.delete('msg'); history.replaceState({}, '', location.pathname + '?' + p.toString()) } })();
    </script>
</body>

</html>