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
function tableExists(mysqli $c, string $t): bool
{
    $t = $c->real_escape_string($t);
    $r = $c->query("SHOW TABLES LIKE '{$t}'");
    return $r && $r->num_rows > 0;
}
function perm(mysqli $c, string $a): bool
{
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin')
        return true;
    $m = ['open' => 'can_open', 'view' => 'can_view', 'value' => 'can_view_value', 'update' => 'can_update'];
    $f = $m[$a] ?? '';
    if (!$f)
        return false;
    foreach (['perm.chit.groups', 'perm.chit'] as $p) {
        if (isset($_SESSION['permissions'][$p][$f]))
            return (int) $_SESSION['permissions'][$p][$f] === 1;
    }
    return in_array(strtolower((string) ($_SESSION['role_name'] ?? '')), ['admin', 'business admin', 'manager', 'billing'], true);
}
$businessId = (int) ($_SESSION['business_id'] ?? 0);
$branchId = (int) ($_SESSION['branch_id'] ?? $_SESSION['default_branch_id'] ?? 0);
$requestedChitGroupId = (int) ($_GET['id'] ?? 0);
if ($requestedChitGroupId <= 0 || !perm($conn, 'open')) {
    http_response_code(403);
    die('Invalid chit group or access denied.');
}
$stmt = $conn->prepare("SELECT cg.*,u.full_name AS created_by_name,(SELECT COUNT(*) FROM chit_members cm WHERE cm.chit_group_id=cg.id) member_count FROM chit_groups cg LEFT JOIN users u ON u.id=cg.created_by WHERE cg.id=? AND cg.business_id=? AND cg.branch_id=? LIMIT 1");
$stmt->bind_param('iii', $requestedChitGroupId, $businessId, $branchId);
$stmt->execute();
$group = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$group) {
    http_response_code(404);
    die('Chit group not found.');
}

$chitGroupId = (int) $group['id'];
if (!defined('PAGE_CHIT_GROUP_ID')) {
    define('PAGE_CHIT_GROUP_ID', $chitGroupId);
}
$members = [];
$s = $conn->prepare("SELECT cm.*,c.customer_name,c.mobile FROM chit_members cm INNER JOIN customers c ON c.id=cm.customer_id WHERE cm.chit_group_id=? ORDER BY cm.ticket_no");
if ($s) {
    $s->bind_param('i', $chitGroupId);
    $s->execute();
    $r = $s->get_result();
    while ($x = $r->fetch_assoc())
        $members[] = $x;
    $s->close();
}
$installments = [];
$s = $conn->prepare("SELECT * FROM chit_installments WHERE chit_group_id=? ORDER BY installment_no");
if ($s) {
    $s->bind_param('i', $chitGroupId);
    $s->execute();
    $r = $s->get_result();
    while ($x = $r->fetch_assoc())
        $installments[] = $x;
    $s->close();
}
$theme = ['primary_color' => '#d89416', 'primary_dark_color' => '#b86a0b', 'page_background' => '#f4f3f0', 'card_background' => '#fff', 'text_color' => '#171717', 'muted_text_color' => '#7d8794', 'border_color' => '#e8e8e8', 'font_family' => 'Inter', 'heading_font_family' => 'Playfair Display', 'border_radius_px' => 12];
if (tableExists($conn, 'business_theme_settings')) {
    $s = $conn->prepare('SELECT * FROM business_theme_settings WHERE business_id=? LIMIT 1');
    $s->bind_param('i', $businessId);
    $s->execute();
    $tr = $s->get_result()->fetch_assoc() ?: [];
    $s->close();
    foreach ($theme as $k => $v)
        if (isset($tr[$k]) && $tr[$k] !== '')
            $theme[$k] = $tr[$k];
}
$pageTitle = 'View Chit';
$businessName = (string) ($_SESSION['business_name'] ?? 'Jewellery ERP');
$canValue = perm($conn, 'value') || perm($conn, 'view');
$canUpdate = perm($conn, 'update');
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= h($businessName) ?> - View Chit</title><?php include 'includes/links.php'; ?>
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
            gap: 10px;
            align-items: center;
            margin-bottom: 10px
        }

        .heading h1 {
            font-family: <?= json_encode($theme['heading_font_family']) ?>, serif;
            font-size: 18px;
            margin: 0
        }

        .panel {
            background: var(--card-bg);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            overflow: hidden;
            margin-bottom: 10px
        }

        .panel-head {
            padding: 11px 13px;
            border-bottom: 1px solid var(--line);
            font-size: 12px;
            font-weight: 800
        }

        .panel-body {
            padding: 13px
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px
        }

        .info {
            border: 1px solid var(--line);
            border-radius: 9px;
            padding: 10px
        }

        .label {
            font-size: 8px;
            text-transform: uppercase;
            color: var(--muted);
            font-weight: 700
        }

        .value {
            font-size: 11px;
            font-weight: 700;
            margin-top: 3px
        }

        .table {
            font-size: 10px;
            margin: 0
        }

        .table th {
            font-size: 8px;
            text-transform: uppercase;
            color: var(--muted);
            background: color-mix(in srgb, var(--muted) 6%, transparent)
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
            color: #fff;
            border: 0
        }

        .btn-custom {
            font-size: 10px;
            border-radius: 9px;
            padding: 8px 11px
        }

        .status {
            display: inline-flex;
            padding: 4px 8px;
            border-radius: 999px;
            background: #eaf8f0;
            color: #168449;
            font-size: 8px;
            font-weight: 800
        }

        .theme-toast {
            position: fixed;
            top: 78px;
            right: 18px;
            z-index: 20000;
            min-width: 260px;
            max-width: 420px;
            display: flex;
            align-items: center;
            gap: 9px;
            padding: 11px 14px;
            border-radius: 10px;
            color: #fff;
            font-size: 11px;
            font-weight: 600;
            box-shadow: 0 14px 35px rgba(0, 0, 0, .22);
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
            transform: translateY(-10px);
            transition: opacity .22s ease, transform .22s ease, visibility .22s ease;
        }

        .theme-toast.show {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .theme-toast-success { background: #168449; }
        .theme-toast-error { background: #c0392b; }

        body.dark-mode,
        body[data-theme=dark],
        html.dark-mode body,
        html[data-theme=dark] body {
            --page-bg: #0f151b;
            --card-bg: #182129;
            --text: #f3f6f8;
            --muted: #9aa7b3;
            --line: #2c3944
        }

        @media(max-width:900px) {
            .info-grid {
                grid-template-columns: repeat(2, 1fr)
            }
        }

        @media(max-width:600px) {
            .theme-toast { top: 70px; left: 12px; right: 12px; min-width: 0; max-width: none; }
            .heading {
                align-items: flex-start;
                flex-direction: column
            }

            .info-grid {
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
                    <h1><?= h($group['group_name']) ?></h1>
                    <div class="small text-muted"><?= h($group['group_no']) ?> · Chit Group Details</div>
                </div>
                <div class="d-flex gap-2"><a href="chit-groups.php"
                        class="btn btn-light btn-custom">Back</a><?php if ($canUpdate): ?><a
                            href="chit-edit.php?id=<?= PAGE_CHIT_GROUP_ID ?>" class="btn btn-theme btn-custom">Edit</a><?php endif; ?></div>
            </div>
            <div class="panel">
                <div class="panel-head">Group Information</div>
                <div class="panel-body">
                    <div class="info-grid">
                        <?php $items = ['Group Number' => $group['group_no'], 'Type' => $group['chit_type'], 'Start Date' => date('d-m-Y', strtotime($group['start_date'])), 'End Date' => $group['end_date'] ? date('d-m-Y', strtotime($group['end_date'])) : '—', 'Members' => $group['member_count'] . ' / ' . $group['total_members'], 'Months' => $group['total_months'], 'Installment' => $canValue ? '₹' . number_format((float) $group['installment_amount'], 2) : '••••', 'Chit Value' => $canValue ? '₹' . number_format((float) $group['chit_value'], 2) : '••••', 'Auction Type' => $group['auction_type'], 'Auction Day' => $group['auction_day'] ?: '—', 'Grace Days' => $group['grace_days'], 'Late Fee' => $canValue ? '₹' . number_format((float) $group['late_fee_amount'], 2) : '••••', 'Status' => $group['status'], 'Created By' => $group['created_by_name'] ?: '—'];
                        foreach ($items as $l => $v): ?>
                            <div class="info">
                                <div class="label"><?= h($l) ?></div>
                                <div class="value"><?= h($v) ?></div>
                            </div><?php endforeach; ?>
                    </div><?php if (trim((string) $group['notes']) !== ''): ?>
                        <div class="info mt-3">
                            <div class="label">Notes</div>
                            <div class="value"><?= nl2br(h($group['notes'])) ?></div>
                        </div><?php endif; ?>
                </div>
            </div>
            <div class="panel">
                <div class="panel-head">Members (<?= count($members) ?>)</div>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Ticket</th>
                                <th>Customer</th>
                                <th>Mobile</th>
                                <th>Join Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody><?php foreach ($members as $m): ?>
                                <tr>
                                    <td><?= h($m['ticket_no']) ?></td>
                                    <td><?= h($m['customer_name']) ?></td>
                                    <td><?= h($m['mobile']) ?></td>
                                    <td><?= h($m['join_date'] ?? '—') ?></td>
                                    <td><span class="status"><?= h($m['status'] ?? 'Active') ?></span></td>
                                </tr><?php endforeach; ?><?php if (!$members): ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">No members joined.</td>
                                </tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="panel">
                <div class="panel-head">Installment Schedule (<?= count($installments) ?>)</div>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>No.</th>
                                <th>Due Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody><?php foreach ($installments as $i): ?>
                                <tr>
                                    <td><?= h($i['installment_no']) ?></td>
                                    <td><?= h(date('d-m-Y', strtotime($i['due_date']))) ?></td>
                                    <td><span class="status"><?= h($i['status']) ?></span></td>
                                </tr><?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div><?php include 'includes/footer.php'; ?>
        </div>
    </main><?php include 'includes/script.php'; ?>
    <script src="assets/js/script.js"></script>

    <div class="theme-toast" id="themeToast" role="status" aria-live="polite">
        <i class="fa-solid fa-circle-check"></i>
        <span></span>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        'use strict';
        const toast = document.getElementById('themeToast');
        let toastTimer = null;

        function showToast(message, success = true) {
            if (!toast || !message) return;
            if (toastTimer) window.clearTimeout(toastTimer);

            toast.className = 'theme-toast ' +
                (success ? 'theme-toast-success' : 'theme-toast-error');

            const icon = toast.querySelector('i');
            const text = toast.querySelector('span');
            if (icon) {
                icon.className = 'fa-solid ' +
                    (success ? 'fa-circle-check' : 'fa-circle-exclamation');
            }
            if (text) text.textContent = message;

            requestAnimationFrame(function () {
                toast.classList.add('show');
            });

            toastTimer = window.setTimeout(function () {
                toast.classList.remove('show');
            }, 3500);
        }

        const params = new URLSearchParams(window.location.search);
        const messageCode = params.get('msg');
        const errorMessage = params.get('error');
        const messages = {
            created: 'Chit group created successfully.',
            updated: 'Chit group updated successfully.',
            member_added: 'Chit member added successfully.',
            member_updated: 'Chit member updated successfully.',
            deleted: 'Chit group deleted successfully.'
        };

        if (errorMessage) {
            showToast(errorMessage, false);
            params.delete('error');
        } else if (messageCode && messages[messageCode]) {
            showToast(messages[messageCode], true);
            params.delete('msg');
        }

        if (errorMessage || messageCode) {
            const cleanQuery = params.toString();
            const cleanUrl = window.location.pathname +
                (cleanQuery ? '?' + cleanQuery : '');
            window.history.replaceState({}, document.title, cleanUrl);
        }

        window.showChitToast = showToast;
    });
    </script>

</body>

</html>