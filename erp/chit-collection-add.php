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
$memberId = (int) ($_GET['member_id'] ?? $_GET['id'] ?? 0);
if ($memberId <= 0)
    die('Invalid member.');
$stmt = $conn->prepare("SELECT cm.*,c.customer_name,c.mobile,cg.group_no,cg.group_name,cg.installment_amount,cg.status group_status,cg.branch_id FROM chit_members cm INNER JOIN customers c ON c.id=cm.customer_id INNER JOIN chit_groups cg ON cg.id=cm.chit_group_id WHERE cm.id=? AND cm.business_id=? LIMIT 1");
$stmt->bind_param('ii', $memberId, $businessId);
$stmt->execute();
$m = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$m)
    die('Chit member not found.');
if ($m['status'] !== 'Active' || in_array($m['group_status'], ['Closed', 'Cancelled'], true))
    die('Payment cannot be collected for this member/group.');
$memberId = (int) $m['id'];
$groupId = (int) $m['chit_group_id'];
$stmt = $conn->prepare("SELECT ci.id,ci.installment_no,ci.due_date,ci.status FROM chit_installments ci LEFT JOIN chit_collections cc ON cc.chit_installment_id=ci.id AND cc.chit_member_id=? WHERE ci.chit_group_id=? AND cc.id IS NULL ORDER BY ci.installment_no");
$stmt->bind_param('ii', $memberId, $groupId);
$stmt->execute();
$installments = [];
$r = $stmt->get_result();
while ($x = $r->fetch_assoc())
    $installments[] = $x;
$stmt->close();
$methods = [];
if (te($conn, 'payment_methods')) {
    $s = $conn->prepare("SELECT id,method_name FROM payment_methods WHERE business_id=? AND is_active=1 ORDER BY method_name");
    $s->bind_param('i', $businessId);
    $s->execute();
    $r = $s->get_result();
    while ($x = $r->fetch_assoc())
        $methods[] = $x;
    $s->close();
}
if (empty($_SESSION['chit_collection_csrf']))
    $_SESSION['chit_collection_csrf'] = bin2hex(random_bytes(32));
$csrf = (string) $_SESSION['chit_collection_csrf'];
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
$pageTitle = 'Collect Chit Payment';
$businessName = (string) ($_SESSION['business_name'] ?? 'Jewellery ERP');
?><!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= h($businessName) ?> - Collect Payment</title><?php include 'includes/links.php'; ?>
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

        .lbl {
            display: block;
            font-size: 8px;
            text-transform: uppercase;
            color: var(--muted);
            font-weight: 700;
            margin-bottom: 4px
        }

        .form-control,
        .form-select {
            font-size: 10px;
            min-height: 38px;
            border-radius: 9px;
            background: var(--card);
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
            padding: 8px 12px
        }

        .summary {
            padding: 10px 12px;
            border: 1px solid var(--line);
            border-radius: 9px;
            background: color-mix(in srgb, var(--p) 8%, var(--card));
            font-size: 10px
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

            .s3,
            .s4,
            .s6,
            .s12 {
                grid-column: span 1
            }

            .s12 {
                grid-column: 1/-1
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

            .s3,
            .s4,
            .s6,
            .s12 {
                grid-column: 1
            }

            .theme-toast {
                left: 12px;
                right: 12px
            }
        }
    </style>
</head>

<body><?php include 'includes/sidebar.php'; ?>
    <main class="app-main"><?php include 'includes/nav.php'; ?>
        <div class="content-wrap">
            <div class="heading">
                <div>
                    <h1>Collect Chit Payment</h1>
                    <div class="small text-muted"><?= h($m['customer_name']) ?> · <?= h($m['group_name']) ?> ·
                        <?= h($m['ticket_no']) ?></div>
                </div><a class="btn btn-light btn-c" href="chit-members.php?group_id=<?= $groupId ?>">Cancel</a>
            </div>
            <form id="collectForm" class="panel">
                <div class="head">Collection Details</div>
                <div class="body"><input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden"
                        name="member_id" value="<?= $memberId ?>"><input type="hidden" name="group_id"
                        value="<?= $groupId ?>">
                    <div class="summary mb-3">Standard installment:
                        <strong>₹<?= number_format((float) $m['installment_amount'], 2) ?></strong></div>
                    <?php if (!$installments): ?>
                        <div class="alert alert-info mb-0">All installments are already collected for this member.</div>
                    <?php else: ?>
                        <div class="grid">
                            <div class="s4"><label class="lbl">Installment</label><select name="installment_id"
                                    id="installment_id" class="form-select" required>
                                    <option value="">Select installment</option><?php foreach ($installments as $i): ?>
                                        <option value="<?= (int) $i['id'] ?>" data-due="<?= h($i['due_date']) ?>">Installment
                                            <?= $i['installment_no'] ?> · Due <?= h(date('d-m-Y', strtotime($i['due_date']))) ?>
                                        </option><?php endforeach; ?>
                                </select></div>
                            <div class="s4"><label class="lbl">Collection Date</label><input type="date"
                                    name="collection_date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
                            <div class="s4"><label class="lbl">Due Amount</label><input type="number" step="0.01" min="0"
                                    name="due_amount" id="due_amount" class="form-control"
                                    value="<?= number_format((float) $m['installment_amount'], 2, '.', '') ?>" required></div>
                            <div class="s3"><label class="lbl">Paid Amount</label><input type="number" step="0.01" min="0"
                                    name="paid_amount" id="paid_amount" class="form-control"
                                    value="<?= number_format((float) $m['installment_amount'], 2, '.', '') ?>" required></div>
                            <div class="s3"><label class="lbl">Discount</label><input type="number" step="0.01" min="0"
                                    name="discount_amount" id="discount_amount" class="form-control" value="0.00"></div>
                            <div class="s3"><label class="lbl">Penalty</label><input type="number" step="0.01" min="0"
                                    name="penalty_amount" id="penalty_amount" class="form-control" value="0.00"></div>
                            <div class="s3"><label class="lbl">Net Received</label><input type="number" step="0.01" min="0"
                                    name="net_amount" id="net_amount" class="form-control" readonly></div>
                            <div class="s4"><label class="lbl">Payment Method</label><select name="payment_method_id"
                                    class="form-select">
                                    <option value="">Select method</option><?php foreach ($methods as $pm): ?>
                                        <option value="<?= (int) $pm['id'] ?>"><?= h($pm['method_name']) ?></option>
                                    <?php endforeach; ?>
                                </select></div>
                            <div class="s4"><label class="lbl">Reference Number</label><input name="reference_no"
                                    class="form-control" maxlength="120"></div>
                            <div class="s4"><label class="lbl">Receiver Name</label><input name="collection_receiver_name"
                                    class="form-control" maxlength="150"
                                    value="<?= h($_SESSION['full_name'] ?? $_SESSION['user_name'] ?? '') ?>"></div>
                            <div class="s12"><label class="lbl">Remarks</label><textarea name="remarks" class="form-control"
                                    rows="3"></textarea></div>
                        </div>
                        <div class="d-flex gap-2 mt-3"><button class="btn btn-theme btn-c" id="saveBtn"><i
                                    class="fa-solid fa-floppy-disk me-1"></i>Save Collection</button><a
                                class="btn btn-light btn-c" href="chit-members.php?group_id=<?= $groupId ?>">Cancel</a></div>
                    <?php endif; ?>
                </div>
            </form><?php include 'includes/footer.php'; ?>
        </div>
    </main><?php include 'includes/script.php'; ?>
    <script src="assets/js/script.js"></script>
    <script>(() => { const f = document.getElementById('collectForm'), p = document.getElementById('paid_amount'), d = document.getElementById('discount_amount'), pen = document.getElementById('penalty_amount'), n = document.getElementById('net_amount'); function calc() { n.value = Math.max(0, Number(p?.value || 0) + Number(pen?.value || 0) - Number(d?.value || 0)).toFixed(2) } [p, d, pen].forEach(x => x?.addEventListener('input', calc)); calc(); f?.addEventListener('submit', async e => { e.preventDefault(); const b = document.getElementById('saveBtn'), old = b.innerHTML; b.disabled = true; b.innerHTML = 'Saving...'; try { const r = await fetch('api/chit-collection-save.php', { method: 'POST', body: new FormData(f), credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } }); const t = await r.text(); let j; try { j = JSON.parse(t) } catch (_) { throw new Error(t || 'Invalid API response.') } if (!r.ok || !j.success) throw new Error(j.message || 'Unable to save collection.'); location.href = 'chit-members.php?group_id=' + <?= $groupId ?> + '&msg=payment_collected' } catch (err) { const x = document.createElement('div'); x.className = 'theme-toast theme-toast-error'; x.textContent = err.message; document.body.appendChild(x); requestAnimationFrame(() => x.classList.add('show')); setTimeout(() => x.remove(), 3500) } finally { b.disabled = false; b.innerHTML = old } }) })();</script>
</body>

</html>