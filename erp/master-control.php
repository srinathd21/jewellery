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
$businessId = (int) ($_SESSION['business_id'] ?? 0);
$branchId = (int) ($_SESSION['branch_id'] ?? ($_SESSION['default_branch_id'] ?? 0));
if ($businessId <= 0 || $branchId <= 0)
    die('Select a valid business and branch.');
if (empty($_SESSION['master_control_csrf']))
    $_SESSION['master_control_csrf'] = bin2hex(random_bytes(32));
$csrfToken = $_SESSION['master_control_csrf'];
$theme = ['primary_color' => '#d89416', 'primary_dark_color' => '#b86a0b', 'primary_soft_color' => '#fff6e5', 'sidebar_gradient_1' => '#171c21', 'sidebar_gradient_2' => '#20272d', 'sidebar_gradient_3' => '#101419', 'page_background' => '#f4f3f0', 'card_background' => '#fff', 'text_color' => '#171717', 'muted_text_color' => '#7d8794', 'border_color' => '#e8e8e8', 'font_family' => 'Inter', 'heading_font_family' => 'Playfair Display', 'border_radius_px' => 12, 'sidebar_width_px' => 230];
$stmt = $conn->prepare('SELECT * FROM business_theme_settings WHERE business_id=? LIMIT 1');
if ($stmt) {
    $stmt->bind_param('i', $businessId);
    $stmt->execute();
    $x = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    foreach ($theme as $k => $v)
        if (isset($x[$k]) && $x[$k] !== '')
            $theme[$k] = $x[$k];
}
$settings = [];
$stmt = $conn->prepare('SELECT * FROM document_number_settings WHERE business_id=? AND (branch_id=? OR branch_id IS NULL) ORDER BY FIELD(document_key,"invoice","purchase","pawn","chit"),(branch_id=?) DESC,id DESC');
if ($stmt) {
    $stmt->bind_param('iii', $businessId, $branchId, $branchId);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($x = $r->fetch_assoc())
        if (!isset($settings[$x['document_key']]))
            $settings[$x['document_key']] = $x;
    $stmt->close();
}
$defaults = ['invoice' => ['prefix' => 'INV', 'center_format' => '{FY_SHORT}', 'suffix' => '', 'divider' => '/', 'sequence_digits' => 3, 'sequence_start' => 1, 'reset_frequency' => 'Financial Year'], 'purchase' => ['prefix' => 'PUR', 'center_format' => '{YYYY}{MM}{DD}', 'suffix' => '', 'divider' => '', 'sequence_digits' => 4, 'sequence_start' => 1, 'reset_frequency' => 'Daily'], 'pawn' => ['prefix' => 'PN', 'center_format' => '{YYYY}{MM}', 'suffix' => '', 'divider' => '', 'sequence_digits' => 4, 'sequence_start' => 1, 'reset_frequency' => 'Monthly'], 'chit' => ['prefix' => 'CH', 'center_format' => '{YYYY}{MM}', 'suffix' => '', 'divider' => '', 'sequence_digits' => 4, 'sequence_start' => 1, 'reset_frequency' => 'Financial Year']];
foreach ($defaults as $k => $d) {
    $settings[$k] = array_merge($d, ['document_key' => $k, 'format_template' => '{PREFIX}{DIVIDER}{CENTER}{DIVIDER}{SEQ}{SUFFIX}', 'sample_output' => ''], $settings[$k] ?? []);
}
$metals = [];
$stmt = $conn->prepare('SELECT * FROM metals WHERE business_id=? ORDER BY is_active DESC,metal_name');
$stmt->bind_param('i', $businessId);
$stmt->execute();
$r = $stmt->get_result();
while ($x = $r->fetch_assoc())
    $metals[] = $x;
$stmt->close();
$pageTitle = 'Master Control';
$businessName = (string) ($_SESSION['business_name'] ?? 'Jewellery ERP');
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($businessName) ?> - Master Control</title><?php include('includes/links.php'); ?>
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

        .sidebar {
            background: linear-gradient(180deg, <?= e($theme['sidebar_gradient_1']) ?>, <?= e($theme['sidebar_gradient_2']) ?>, <?= e($theme['sidebar_gradient_3']) ?>) !important
        }

        .page-card {
            background: var(--card-bg);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            overflow: hidden;
            margin-bottom: 12px
        }

        .card-head {
            padding: 12px 14px;
            border-bottom: 1px solid var(--line);
            display: flex;
            align-items: center;
            justify-content: space-between
        }

        .card-title {
            font-family: <?= json_encode($theme['heading_font_family']) ?>, serif;
            font-size: 15px;
            font-weight: 800
        }

        .card-body {
            padding: 14px
        }

        .number-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px
        }

        .number-card {
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 13px;
            background: var(--card-bg)
        }

        .number-card h6 {
            font-size: 12px;
            font-weight: 800;
            margin: 0 0 10px;
            text-transform: capitalize
        }

        .field-label {
            font-size: 9px;
            font-weight: 700;
            margin-bottom: 4px
        }

        .form-control,
        .form-select {
            font-size: 11px;
            min-height: 36px;
            border-color: var(--line);
            border-radius: 8px;
            background: var(--card-bg);
            color: var(--text)
        }

        .preview-box {
            padding: 9px 10px;
            border-radius: 8px;
            background: var(--primary-soft);
            color: var(--primary-dark);
            font-size: 11px;
            font-weight: 800;
            word-break: break-all
        }

        .btn-theme {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border: 0;
            color: #fff;
            font-size: 10px;
            font-weight: 700;
            border-radius: 8px;
            padding: 8px 12px
        }

        .table {
            font-size: 10px
        }

        .table th {
            font-size: 9px;
            text-transform: uppercase;
            color: var(--muted);
            background: color-mix(in srgb, var(--muted) 6%, transparent)
        }

        .table td,
        .table th {
            border-color: var(--line);
            vertical-align: middle
        }

        .badge-soft {
            display: inline-flex;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 9px;
            font-weight: 700
        }

        .active-badge {
            background: #eaf8f0;
            color: #168449
        }

        .inactive-badge {
            background: #fdecec;
            color: #bd2d2d
        }

        .action-btn {
            width: 29px;
            height: 29px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: var(--card-bg);
            color: var(--text);
            display: inline-flex;
            align-items: center;
            justify-content: center;
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
            font-weight: 700;
            opacity: 0;
            transform: translateY(-10px);
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

        body.dark-mode,
        body[data-theme=dark],
        html.dark-mode body,
        html[data-theme=dark] body {
            --page-bg: #0f151b;
            --card-bg: #182129;
            --text: #f3f6f8;
            --muted: #9aa7b3;
            --line: #2c3944;
            --primary-soft: #2b2414;
            color-scheme: dark
        }

        .app-main {
            min-height: 100vh;
            background: var(--page-bg)
        }

        .content-wrap {
            padding: 18px;
            padding-top: 82px
        }

        .modal-content {
            background: var(--card-bg);
            color: var(--text);
            border: 1px solid var(--line)
        }

        .modal-header,
        .modal-footer {
            border-color: var(--line)
        }

        .modal-title {
            color: var(--text)
        }

        .btn-close {
            filter: none
        }

        .table-responsive {
            background: var(--card-bg)
        }

        .table {
            --bs-table-bg: var(--card-bg);
            --bs-table-color: var(--text);
            --bs-table-border-color: var(--line);
            margin-bottom: 0
        }

        .table>thead>tr>th {
            background: color-mix(in srgb, var(--muted) 8%, var(--card-bg)) !important;
            color: var(--muted) !important;
            border-color: var(--line) !important
        }

        .table>tbody>tr>td {
            background: var(--card-bg) !important;
            color: var(--text) !important;
            border-color: var(--line) !important
        }

        .table>tbody>tr:hover>td {
            background: color-mix(in srgb, var(--primary) 7%, var(--card-bg)) !important
        }

        .text-muted {
            color: var(--muted) !important
        }

        .action-btn:hover {
            background: var(--primary-soft);
            color: var(--primary)
        }

        body.dark-mode .page-card,
        body.dark-mode .number-card,
        body.dark-mode .table-responsive,
        body[data-theme=dark] .page-card,
        body[data-theme=dark] .number-card,
        body[data-theme=dark] .table-responsive,
        html.dark-mode body .page-card,
        html.dark-mode body .number-card,
        html.dark-mode body .table-responsive,
        html[data-theme=dark] body .page-card,
        html[data-theme=dark] body .number-card,
        html[data-theme=dark] body .table-responsive {
            background: var(--card-bg) !important;
            border-color: var(--line) !important;
            color: var(--text) !important
        }

        body.dark-mode .table,
        body[data-theme=dark] .table,
        html.dark-mode body .table,
        html[data-theme=dark] body .table {
            --bs-table-bg: var(--card-bg);
            --bs-table-color: var(--text);
            --bs-table-striped-bg: var(--card-bg);
            --bs-table-hover-bg: #202b34;
            --bs-table-border-color: var(--line)
        }

        body.dark-mode .table>thead>tr>th,
        body[data-theme=dark] .table>thead>tr>th,
        html.dark-mode body .table>thead>tr>th,
        html[data-theme=dark] body .table>thead>tr>th {
            background: #202a33 !important;
            color: #9fb0bf !important;
            border-color: var(--line) !important
        }

        body.dark-mode .table>tbody>tr>td,
        body[data-theme=dark] .table>tbody>tr>td,
        html.dark-mode body .table>tbody>tr>td,
        html[data-theme=dark] body .table>tbody>tr>td {
            background: #182129 !important;
            color: #f3f6f8 !important;
            border-color: #33414d !important
        }

        body.dark-mode .table>tbody>tr:hover>td,
        body[data-theme=dark] .table>tbody>tr:hover>td,
        html.dark-mode body .table>tbody>tr:hover>td,
        html[data-theme=dark] body .table>tbody>tr:hover>td {
            background: #202b34 !important
        }

        body.dark-mode .form-control,
        body.dark-mode .form-select,
        body[data-theme=dark] .form-control,
        body[data-theme=dark] .form-select,
        html.dark-mode body .form-control,
        html.dark-mode body .form-select,
        html[data-theme=dark] body .form-control,
        html[data-theme=dark] body .form-select {
            background: #111a22 !important;
            color: #f3f6f8 !important;
            border-color: #33414d !important
        }

        body.dark-mode .form-control::placeholder,
        body[data-theme=dark] .form-control::placeholder,
        html.dark-mode body .form-control::placeholder,
        html[data-theme=dark] body .form-control::placeholder {
            color: #71808d !important
        }

        body.dark-mode .preview-box,
        body[data-theme=dark] .preview-box,
        html.dark-mode body .preview-box,
        html[data-theme=dark] body .preview-box {
            background: #33270d !important;
            color: #f2a900 !important;
            border: 1px solid #59420e
        }

        body.dark-mode .action-btn,
        body[data-theme=dark] .action-btn,
        html.dark-mode body .action-btn,
        html[data-theme=dark] body .action-btn {
            background: #111a22 !important;
            color: #f3f6f8 !important;
            border-color: #33414d !important
        }

        body.dark-mode .action-btn:hover,
        body[data-theme=dark] .action-btn:hover,
        html.dark-mode body .action-btn:hover,
        html[data-theme=dark] body .action-btn:hover {
            background: #2b2414 !important;
            color: var(--primary) !important
        }

        body.dark-mode .modal-content,
        body[data-theme=dark] .modal-content,
        html.dark-mode body .modal-content,
        html[data-theme=dark] body .modal-content {
            background: #182129 !important;
            color: #f3f6f8 !important;
            border-color: #33414d !important
        }

        body.dark-mode .btn-close,
        body[data-theme=dark] .btn-close,
        html.dark-mode body .btn-close,
        html[data-theme=dark] body .btn-close {
            filter: invert(1) grayscale(100%) brightness(200%)
        }

        body.dark-mode .active-badge,
        body[data-theme=dark] .active-badge,
        html.dark-mode body .active-badge,
        html[data-theme=dark] body .active-badge {
            background: #173b2b;
            color: #67d59a
        }

        body.dark-mode .inactive-badge,
        body[data-theme=dark] .inactive-badge,
        html.dark-mode body .inactive-badge,
        html[data-theme=dark] body .inactive-badge {
            background: #482328;
            color: #ff9292
        }

        @media(max-width:991px) {
            .content-wrap {
                padding: 78px 12px 12px
            }

            .number-grid {
                grid-template-columns: 1fr
            }
        }

        @media(max-width:767px) {
            .content-wrap {
                padding: 74px 10px 10px
            }

            .number-card {
                padding: 11px
            }

            .table {
                min-width: 700px
            }
        }
    </style>
</head>

<body><?php include('includes/sidebar.php'); ?>
    <main class="app-main"><?php include('includes/nav.php'); ?>
        <div class="content-wrap">
            <div class="page-card">
                <div class="card-head">
                    <div>
                        <div class="card-title">Numbering & Metal Master</div>
                        <div class="small text-muted">Control numbering formats and metal types from one page.</div>
                    </div>
                </div>
            </div>
            <div class="page-card">
                <div class="card-head">
                    <div class="card-title">Document Numbering</div>
                </div>
                <div class="card-body">
                    <div class="number-grid">
                        <?php foreach ($settings as $key => $s): ?>
                            <form class="number-card number-form" data-key="<?= e($key) ?>"><input type="hidden" name="action"
                                    value="save_number"><input type="hidden" name="csrf_token"
                                    value="<?= e($csrfToken) ?>"><input type="hidden" name="document_key"
                                    value="<?= e($key) ?>">
                                <h6><i
                                        class="fa-solid <?= $key === 'invoice' ? 'fa-file-invoice' : ($key === 'purchase' ? 'fa-cart-shopping' : ($key === 'pawn' ? 'fa-hand-holding-dollar' : 'fa-people-group')) ?> me-2"></i><?= e($key) ?>
                                    Number</h6>
                                <div class="row g-2">
                                    <div class="col-md-3"><label class="field-label">Prefix</label><input
                                            class="form-control preview-input" name="prefix" value="<?= e($s['prefix']) ?>">
                                    </div>
                                    <div class="col-md-4"><label class="field-label">Center</label><input
                                            class="form-control preview-input" name="center_format"
                                            value="<?= e($s['center_format']) ?>" placeholder="{FY_SHORT}"></div>
                                    <div class="col-md-2"><label class="field-label">Divider</label><input
                                            class="form-control preview-input" name="divider" value="<?= e($s['divider']) ?>"
                                            maxlength="5"></div>
                                    <div class="col-md-3"><label class="field-label">End / Suffix</label><input
                                            class="form-control preview-input" name="suffix" value="<?= e($s['suffix']) ?>">
                                    </div>
                                    <div class="col-md-3"><label class="field-label">End Digits</label><input type="number"
                                            class="form-control preview-input" name="sequence_digits" min="1" max="12"
                                            value="<?= (int) $s['sequence_digits'] ?>"></div>
                                    <div class="col-md-3"><label class="field-label">Start From</label><input type="number"
                                            class="form-control preview-input" name="sequence_start" min="1"
                                            value="<?= (int) $s['sequence_start'] ?>"></div>
                                    <div class="col-md-3"><label class="field-label">Reset</label><select
                                            class="form-select preview-input"
                                            name="reset_frequency"><?php foreach (['Never', 'Financial Year', 'Calendar Year', 'Monthly', 'Daily'] as $r): ?>
                                                <option <?= $s['reset_frequency'] === $r ? 'selected' : '' ?>><?= e($r) ?></option>
                                            <?php endforeach ?>
                                        </select></div>
                                    <div class="col-md-3"><label class="field-label">Format Template</label><input
                                            class="form-control preview-input" name="format_template"
                                            value="<?= e($s['format_template']) ?>"></div>
                                    <div class="col-12">
                                        <div class="d-flex justify-content-between align-items-center gap-2">
                                            <div class="preview-box flex-grow-1" data-preview>
                                                <?= e($s['sample_output'] ?: 'Preview') ?></div><button class="btn-theme"
                                                type="submit"><i class="fa-solid fa-floppy-disk me-1"></i>Save</button>
                                        </div>
                                    </div>
                                </div>
                            </form><?php endforeach ?>
                    </div>
                    <div class="small text-muted mt-3">Center tokens: {FY_SHORT}, {FY_2DIGIT}, {YYYY}, {YY}, {MM}, {DD}.
                        Template tokens: {PREFIX}, {DIVIDER}, {CENTER}, {SEQ}, {SUFFIX}.</div>
                </div>
            </div>
            <div class="page-card">
                <div class="card-head">
                    <div class="card-title">Metal Types</div><button class="btn-theme" id="addMetal"><i
                            class="fa-solid fa-plus me-1"></i>Add Metal</button>
                </div>
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Metal Type</th>
                                <th>Default Purity %</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="metalBody"><?php foreach ($metals as $m): ?>
                                <tr data-metal='<?= e(json_encode($m, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'>
                                    <td><strong><?= e($m['metal_code']) ?></strong></td>
                                    <td><?= e($m['metal_name']) ?></td>
                                    <td><?= number_format((float) $m['default_purity'], 4) ?></td>
                                    <td><span
                                            class="badge-soft <?= (int) $m['is_active'] === 1 ? 'active-badge' : 'inactive-badge' ?>"><?= (int) $m['is_active'] === 1 ? 'Active' : 'Inactive' ?></span>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-inline-flex gap-1"><button class="action-btn edit-metal"
                                                type="button"><i class="fa-solid fa-pen"></i></button><button
                                                class="action-btn toggle-metal" type="button"><i
                                                    class="fa-solid fa-power-off"></i></button><button
                                                class="action-btn delete-metal" type="button"><i
                                                    class="fa-solid fa-trash"></i></button></div>
                                    </td>
                                </tr><?php endforeach ?>
                        </tbody>
                    </table>
                </div>
            </div><?php include('includes/footer.php'); ?>
        </div>
    </main>
    <div class="modal fade" id="metalModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" id="metalForm">
                <div class="modal-header">
                    <h5 class="modal-title">Metal Type</h5><button type="button" class="btn-close"
                        data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body"><input type="hidden" name="action" value="save_metal"><input type="hidden"
                        name="csrf_token" value="<?= e($csrfToken) ?>"><input type="hidden" name="metal_id" id="metal_id"
                        value="0">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="field-label">Metal Code *</label><input class="form-control"
                                name="metal_code" id="metal_code" required></div>
                        <div class="col-md-6"><label class="field-label">Metal Type *</label><input class="form-control"
                                name="metal_name" id="metal_name" required></div>
                        <div class="col-md-6"><label class="field-label">Default Purity %</label><input type="number"
                                min="0" max="100" step="0.0001" class="form-control" name="default_purity"
                                id="default_purity" value="0"></div>
                        <div class="col-md-6"><label class="field-label">Status</label><select class="form-select"
                                name="is_active" id="metal_active">
                                <option value="1">Active</option>
                                <option value="0">Inactive</option>
                            </select></div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light btn-sm"
                        data-bs-dismiss="modal">Cancel</button><button class="btn-theme" type="submit">Save
                        Metal</button></div>
            </form>
        </div>
    </div>
    <div class="theme-toast" id="toast"></div><?php include('includes/script.php'); ?>
    <script src="assets/js/script.js"></script>
    <script>
        (() => { 'use strict'; const csrf = <?= json_encode($csrfToken) ?>, toast = document.getElementById('toast'); function notify(ok, msg) { toast.className = 'theme-toast ' + (ok ? 'theme-toast-success' : 'theme-toast-error'); toast.textContent = msg; toast.classList.add('show'); setTimeout(() => toast.classList.remove('show'), 3000) } async function api(fd) { const r = await fetch('api/master-control-save.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } }); const d = await r.json().catch(() => ({ success: false, message: 'Invalid server response.' })); if (!r.ok || !d.success) throw new Error(d.message || 'Request failed.'); return d } function field(form, name) { return form.querySelector('[name="' + name + '"]') } function replaceToken(value, token, replacement) { return String(value).split(token).join(replacement) } function preview(form) { const prefix = field(form, 'prefix')?.value || '', centerFormat = field(form, 'center_format')?.value || '', suffix = field(form, 'suffix')?.value || '', divider = field(form, 'divider')?.value || '', digits = Math.max(1, Number(field(form, 'sequence_digits')?.value) || 1), start = Math.max(1, Number(field(form, 'sequence_start')?.value) || 1), template = field(form, 'format_template')?.value || '{PREFIX}{DIVIDER}{CENTER}{DIVIDER}{SEQ}{SUFFIX}'; const now = new Date(), year = now.getFullYear(), month = now.getMonth() + 1, fyStart = month >= 4 ? year : year - 1; let center = centerFormat; center = replaceToken(center, '{FY_SHORT}', String(fyStart).slice(-2) + '-' + String(fyStart + 1).slice(-2)); center = replaceToken(center, '{FY_2DIGIT}', String(fyStart).slice(-2) + String(fyStart + 1).slice(-2)); center = replaceToken(center, '{YYYY}', String(year)); center = replaceToken(center, '{YY}', String(year).slice(-2)); center = replaceToken(center, '{MM}', String(month).padStart(2, '0')); center = replaceToken(center, '{DD}', String(now.getDate()).padStart(2, '0')); const seq = String(start).padStart(digits, '0'); let output = template; output = replaceToken(output, '{PREFIX}', prefix); output = replaceToken(output, '{DIVIDER}', divider); output = replaceToken(output, '{CENTER}', center); output = replaceToken(output, '{SEQ}', seq); output = replaceToken(output, '{SUFFIX}', suffix); const box = form.querySelector('[data-preview]'); if (box) box.textContent = output || 'Preview' } document.querySelectorAll('.number-form').forEach(form => { form.querySelectorAll('.preview-input').forEach(control => { control.addEventListener('input', () => preview(form)); control.addEventListener('change', () => preview(form)); control.addEventListener('keyup', () => preview(form)) }); preview(form); form.addEventListener('submit', async event => { event.preventDefault(); const button = form.querySelector('button[type=submit]'), old = button.innerHTML; button.disabled = true; button.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Saving'; try { const data = await api(new FormData(form)); const box = form.querySelector('[data-preview]'); if (box) box.textContent = data.sample_output || ''; notify(true, data.message); setTimeout(() => location.reload(), 500) } catch (error) { notify(false, error.message) } finally { button.disabled = false; button.innerHTML = old } }) }); const modalEl = document.getElementById('metalModal'), modal = new bootstrap.Modal(modalEl), form = document.getElementById('metalForm'); document.getElementById('addMetal').onclick = () => { form.reset(); metal_id.value = '0'; metal_active.value = '1'; modal.show() }; document.addEventListener('click', async e => { const row = e.target.closest('tr[data-metal]'); if (!row) return; const m = JSON.parse(row.dataset.metal); if (e.target.closest('.edit-metal')) { metal_id.value = m.id; metal_code.value = m.metal_code; metal_name.value = m.metal_name; default_purity.value = m.default_purity; metal_active.value = String(m.is_active); modal.show() } if (e.target.closest('.toggle-metal')) { const fd = new FormData(); fd.append('action', 'toggle_metal'); fd.append('csrf_token', csrf); fd.append('metal_id', m.id); try { const d = await api(fd); notify(true, d.message); setTimeout(() => location.reload(), 400) } catch (err) { notify(false, err.message) } } if (e.target.closest('.delete-metal')) { if (!confirm('Delete ' + m.metal_name + '?')) return; const fd = new FormData(); fd.append('action', 'delete_metal'); fd.append('csrf_token', csrf); fd.append('metal_id', m.id); try { const d = await api(fd); row.remove(); notify(true, d.message) } catch (err) { notify(false, err.message) } } }); form.addEventListener('submit', async e => { e.preventDefault(); const b = form.querySelector('button[type=submit]'); b.disabled = true; try { const d = await api(new FormData(form)); notify(true, d.message); modal.hide(); setTimeout(() => location.reload(), 400) } catch (err) { notify(false, err.message) } finally { b.disabled = false } }) })();
    </script>
</body>

</html>