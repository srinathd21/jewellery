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
    $m = ['open' => 'can_open', 'update' => 'can_update'];
    $f = $m[$a] ?? '';
    if (!$f)
        return false;
    foreach (['perm.chit.groups', 'perm.chit'] as $p)
        if (isset($_SESSION['permissions'][$p][$f]))
            return (int) $_SESSION['permissions'][$p][$f] === 1;
    return in_array(strtolower((string) ($_SESSION['role_name'] ?? '')), ['admin', 'business admin', 'manager', 'billing'], true);
}
$businessId = (int) ($_SESSION['business_id'] ?? 0);
$branchId = (int) ($_SESSION['branch_id'] ?? $_SESSION['default_branch_id'] ?? 0);
$requestedChitGroupId = (int) ($_GET['id'] ?? 0);
if ($requestedChitGroupId <= 0 || !perm($conn, 'update')) {
    http_response_code(403);
    die('Invalid chit group or update permission denied.');
}
$s = $conn->prepare('SELECT * FROM chit_groups WHERE id=? AND business_id=? LIMIT 1');
$s->bind_param('ii', $requestedChitGroupId, $businessId);
$s->execute();
$g = $s->get_result()->fetch_assoc();
$s->close();
if (!$g) {
    http_response_code(404);
    die('Chit group not found.');
}
if (!defined('PAGE_CHIT_GROUP_ID')) {
    define('PAGE_CHIT_GROUP_ID', (int) $g['id']);
}
if (empty($_SESSION['chit_groups_csrf']))
    $_SESSION['chit_groups_csrf'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['chit_groups_csrf'];
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
$pageTitle = 'Edit Chit';
$businessName = (string) ($_SESSION['business_name'] ?? 'Jewellery ERP');
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= h($businessName) ?> - Edit Chit</title><?php include 'includes/links.php'; ?>
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
            overflow: hidden
        }

        .head {
            padding: 12px 14px;
            border-bottom: 1px solid var(--line);
            font-size: 12px;
            font-weight: 800
        }

        .body {
            padding: 14px
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 10px
        }

        .s2 {
            grid-column: span 2
        }

        .s3 {
            grid-column: span 3
        }

        .s4 {
            grid-column: span 4
        }

        .s6 {
            grid-column: span 6
        }

        .s12 {
            grid-column: span 12
        }

        .label {
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            color: var(--muted);
            margin-bottom: 4px
        }

        .form-control,
        .form-select {
            font-size: 10px;
            min-height: 36px;
            border-radius: 9px;
            background: var(--card-bg);
            color: var(--text);
            border-color: var(--line)
        }

        .btn-theme {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: #fff;
            border: 0
        }

        .btn-custom {
            font-size: 10px;
            border-radius: 9px;
            padding: 8px 12px
        }

        .toastx {
            position: fixed;
            right: 18px;
            top: 78px;
            z-index: 9999;
            color: #fff;
            padding: 11px 14px;
            border-radius: 10px;
            background: #168449;
            display: none
        }

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
            .grid {
                grid-template-columns: repeat(2, 1fr)
            }

            .s2,
            .s3,
            .s4,
            .s6,
            .s12 {
                grid-column: span 1
            }
        }

        @media(max-width:600px) {
            .grid {
                grid-template-columns: 1fr
            }

            .s2,
            .s3,
            .s4,
            .s6,
            .s12 {
                grid-column: 1
            }
        }
    </style>
</head>

<body><?php include 'includes/sidebar.php'; ?>
    <main class="app-main"><?php include 'includes/nav.php'; ?>
        <div class="content-wrap">
            <div class="heading">
                <div>
                    <h1>Edit Chit Group</h1>
                    <div class="small text-muted"><?= h($g['group_no']) ?></div>
                </div><a href="chit-view.php?id=<?= PAGE_CHIT_GROUP_ID ?>" class="btn btn-light btn-custom">Cancel</a>
            </div>
            <form id="editForm" class="panel">
                <div class="head">Group Details</div>
                <div class="body"><input type="hidden" name="action" value="update"><input type="hidden" name="group_id"
                        id="group_id" value="<?= PAGE_CHIT_GROUP_ID ?>"><input type="hidden" name="chit_group_id"
                        value="<?= PAGE_CHIT_GROUP_ID ?>"><input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <div class="grid">
                        <div class="s6">
                            <div class="label">Group Name</div><input class="form-control" name="group_name"
                                value="<?= h($g['group_name']) ?>" required>
                        </div>
                        <div class="s3">
                            <div class="label">Chit Type</div><select class="form-select"
                                name="chit_type"><?php foreach (['Money', 'Silver', 'Gold'] as $v): ?>
                                    <option <?= $g['chit_type'] === $v ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="s3">
                            <div class="label">Status</div><select class="form-select"
                                name="status"><?php foreach (['Draft', 'Active', 'Closed', 'Cancelled'] as $v): ?>
                                    <option <?= $g['status'] === $v ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="s3">
                            <div class="label">Start Date</div><input type="date" class="form-control" name="start_date"
                                value="<?= h($g['start_date']) ?>" required>
                        </div>
                        <div class="s3">
                            <div class="label">Total Members</div><input type="number" min="1" class="form-control"
                                name="total_members" value="<?= h($g['total_members']) ?>" required>
                        </div>
                        <div class="s3">
                            <div class="label">Total Months</div><input type="number" min="1" class="form-control"
                                name="total_months" value="<?= h($g['total_months']) ?>" required>
                        </div>
                        <div class="s3">
                            <div class="label">Installment Amount</div><input type="number" step="0.01" min="0"
                                class="form-control" name="installment_amount" value="<?= h($g['installment_amount']) ?>"
                                required>
                        </div>
                        <div class="s3">
                            <div class="label">Chit Value</div><input type="number" step="0.01" min="0"
                                class="form-control" name="chit_value" value="<?= h($g['chit_value']) ?>" required>
                        </div>
                        <div class="s3">
                            <div class="label">Auction Type</div><select class="form-select"
                                name="auction_type"><?php foreach (['Auction', 'Lucky Draw', 'Manual'] as $v): ?>
                                    <option <?= $g['auction_type'] === $v ? 'selected' : '' ?>><?= h($v) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="s2">
                            <div class="label">Auction Day</div><input type="number" min="1" max="31"
                                class="form-control" name="auction_day" value="<?= h($g['auction_day']) ?>">
                        </div>
                        <div class="s2">
                            <div class="label">Grace Days</div><input type="number" min="0" class="form-control"
                                name="grace_days" value="<?= h($g['grace_days']) ?>">
                        </div>
                        <div class="s2">
                            <div class="label">Late Fee</div><input type="number" step="0.01" min="0"
                                class="form-control" name="late_fee_amount" value="<?= h($g['late_fee_amount']) ?>">
                        </div>
                        <div class="s12">
                            <div class="label">Notes</div><textarea class="form-control" rows="3"
                                name="notes"><?= h($g['notes']) ?></textarea>
                        </div>
                    </div>
                    <div class="mt-3"><button class="btn btn-theme btn-custom" id="saveBtn">Save Changes</button></div>
                </div>
            </form><?php include 'includes/footer.php'; ?>
        </div>
    </main>
    <div class="toastx" id="toastx"></div><?php include 'includes/script.php'; ?>
    <script src="assets/js/script.js"></script>
    <script>
        (function () {
            'use strict';
            const fixedGroupId = <?= PAGE_CHIT_GROUP_ID ?>;
            const form = document.getElementById('editForm');
            const button = document.getElementById('saveBtn');

            form.addEventListener('submit', async function (event) {
                event.preventDefault();
                const original = button.innerHTML;
                button.disabled = true;
                button.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Saving...';

                try {
                    const formData = new FormData(form);
                    // Always force the ID loaded by this page. This prevents another field,
                    // browser autofill, or duplicated input from changing the record ID.
                    formData.set('group_id', String(fixedGroupId));
                    formData.set('chit_group_id', String(fixedGroupId));
                    formData.set('action', 'update');

                    const response = await fetch('api/chit-group-actions.php', {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    });

                    const text = await response.text();
                    let result;
                    try { result = JSON.parse(text); }
                    catch (_) { throw new Error(text || 'Invalid response from server.'); }

                    if (!response.ok || !result.success) {
                        throw new Error(result.message || 'Unable to update chit group.');
                    }

                    location.href = 'chit-view.php?id=' + fixedGroupId + '&msg=updated';
                } catch (error) {
                    alert(error.message || 'Unable to update chit group.');
                } finally {
                    button.disabled = false;
                    button.innerHTML = original;
                }
            });
        })();
    </script>
</body>

</html>