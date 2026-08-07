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
if (!function_exists('e')) {
    function e($v): string
    {
        return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}
function metalRatePermission(mysqli $conn, string $action): bool
{
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin')
        return true;
    $map = ['open' => 'can_open', 'view' => 'can_view', 'value' => 'can_view_value', 'create' => 'can_create', 'update' => 'can_update', 'delete' => 'can_delete'];
    $field = $map[$action] ?? '';
    if ($field === '')
        return false;
    $codes = ['perm.inventory.metal_rates', 'perm.rates.metal', 'perm.metal_rates', 'perm.inventory'];
    $permissions = $_SESSION['permissions'] ?? [];
    foreach ($codes as $code) {
        if (isset($permissions[$code]) && array_key_exists($field, $permissions[$code]))
            return (int) $permissions[$code][$field] === 1;
    }
    $bid = (int) ($_SESSION['business_id'] ?? 0);
    $rid = (int) ($_SESSION['role_id'] ?? 0);
    if ($bid <= 0 || $rid <= 0)
        return false;
    $marks = implode(',', array_fill(0, count($codes), '?'));
    $stmt = $conn->prepare("SELECT rp.`{$field}` FROM role_permissions rp INNER JOIN permissions p ON p.id=rp.permission_id WHERE rp.business_id=? AND rp.role_id=? AND p.is_active=1 AND p.permission_code IN ($marks) LIMIT 1");
    if (!$stmt)
        return false;
    $types = 'ii' . str_repeat('s', count($codes));
    $vals = array_merge([$bid, $rid], $codes);
    $stmt->bind_param($types, ...$vals);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row[$field] ?? 0) === 1;
}
if (!metalRatePermission($conn, 'open')) {
    http_response_code(403);
    die('Access denied. You do not have permission to open metal rates.');
}
$canView = metalRatePermission($conn, 'view') || metalRatePermission($conn, 'open');
$canValue = metalRatePermission($conn, 'value') || $canView;
$canCreate = metalRatePermission($conn, 'create');
$canUpdate = metalRatePermission($conn, 'update');
$canDelete = metalRatePermission($conn, 'delete');
$businessId = (int) ($_SESSION['business_id'] ?? 0);
if ($businessId <= 0)
    die('A valid business must be selected.');
if (empty($_SESSION['metal_rates_csrf']))
    $_SESSION['metal_rates_csrf'] = bin2hex(random_bytes(32));
$csrfToken = $_SESSION['metal_rates_csrf'];
$metals = [];
$stmt = $conn->prepare('SELECT id,metal_name,default_purity FROM metals WHERE business_id=? AND is_active=1 ORDER BY metal_name');
if ($stmt) {
    $stmt->bind_param('i', $businessId);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($x = $r->fetch_assoc())
        $metals[] = $x;
    $stmt->close();
}
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
$pageTitle = 'Metal Rates';
$businessName = (string) ($_SESSION['business_name'] ?? 'Jewellery ERP');
$initialSearch = trim((string) ($_GET['search'] ?? ''));
$initialMetal = max(0, (int) ($_GET['metal_id'] ?? 0));
$initialDate = trim((string) ($_GET['effective_date'] ?? ''));
$initialPerPage = (int) ($_GET['per_page'] ?? 10);
if (!in_array($initialPerPage, [10, 25, 50, 100], true))
    $initialPerPage = 10;
$initialPage = max(1, (int) ($_GET['page'] ?? 1));
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($businessName) ?> - Metal Rates</title><?php include('includes/links.php'); ?>
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
            --radius: <?= (int) $theme['border_radius_px'] ?>px;
            --sidebar-width: <?= (int) $theme['sidebar_width_px'] ?>px;
            --sidebar-gradient-1: <?= e($theme['sidebar_gradient_1']) ?>;
            --sidebar-gradient-2: <?= e($theme['sidebar_gradient_2']) ?>;
            --sidebar-gradient-3: <?= e($theme['sidebar_gradient_3']) ?>
        }

        body {
            background: var(--page-bg);
            color: var(--text);
            font-family: <?= json_encode($theme['font_family']) ?>, sans-serif
        }

        .sidebar {
            background: linear-gradient(180deg, var(--sidebar-gradient-1), var(--sidebar-gradient-2), var(--sidebar-gradient-3)) !important
        }

        .stat-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 8px;
            margin-bottom: 10px
        }

        .stat-card,
        .toolbar,
        .rates-card {
            background: var(--card-bg);
            border: 1px solid var(--line);
            border-radius: var(--radius)
        }

        .stat-card {
            min-height: 82px;
            padding: 12px 14px;
            display: flex;
            align-items: center;
            gap: 12px
        }

        .stat-icon {
            width: 42px;
            height: 42px;
            display: grid;
            place-items: center;
            border-radius: 10px;
            background: var(--primary-soft);
            color: var(--primary-dark)
        }

        .stat-label {
            font-size: 10px;
            color: var(--muted)
        }

        .stat-value {
            font-size: 22px;
            font-weight: 800
        }

        .toolbar {
            padding: 10px 12px;
            margin-bottom: 10px
        }

        .filter-grid {
            display: grid;
            grid-template-columns: minmax(220px, 1.5fr) 160px 150px 105px auto auto;
            gap: 8px;
            align-items: center
        }

        .field {
            position: relative
        }

        .field i {
            position: absolute;
            left: 11px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 11px;
            color: var(--muted);
            pointer-events: none
        }

        .field.has-icon .form-control,
        .field.has-icon .form-select {
            padding-left: 32px
        }

        .form-control,
        .form-select {
            height: 39px;
            font-size: 11px;
            border: 1px solid var(--line);
            border-radius: 9px;
            background: var(--card-bg);
            color: var(--text)
        }

        .btn-theme,
        .btn-reset {
            height: 39px;
            border-radius: 9px;
            font-size: 11px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            padding: 0 14px;
            white-space: nowrap
        }

        .btn-theme {
            border: 0;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: #fff
        }

        .btn-reset {
            border: 1px solid var(--line);
            background: var(--card-bg);
            color: var(--text)
        }

        .rates-card {
            overflow: hidden;
            position: relative
        }

        .rates-table {
            margin: 0;
            font-size: 11px
        }

        .rates-table th {
            font-size: 9px;
            text-transform: uppercase;
            color: var(--muted);
            background: color-mix(in srgb, var(--muted) 6%, transparent);
            padding: 10px 12px;
            white-space: nowrap
        }

        .rates-table td {
            padding: 10px 12px;
            vertical-align: middle;
            background: var(--card-bg) !important;
            color: var(--text);
            border-color: var(--line)
        }

        .metal-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 8px;
            border-radius: 999px;
            background: var(--primary-soft);
            color: var(--primary-dark);
            font-size: 9px;
            font-weight: 700
        }

        .rate-value {
            font-size: 12px;
            font-weight: 800
        }

        .action-btn {
            width: 30px;
            height: 30px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: var(--card-bg);
            color: var(--text);
            display: inline-grid;
            place-items: center
        }

        .action-btn:hover {
            background: var(--primary-soft);
            color: var(--primary-dark)
        }

        .action-btn.danger:hover {
            background: #fdecec;
            color: #bd2d2d
        }

        .pagination-wrap {
            padding: 10px 12px;
            border-top: 1px solid var(--line);
            display: flex;
            justify-content: space-between;
            align-items: center
        }

        .pagination-summary {
            font-size: 10px;
            color: var(--muted)
        }

        .pagination {
            margin: 0;
            gap: 4px
        }

        .page-link {
            min-width: 32px;
            height: 32px;
            border-radius: 8px !important;
            font-size: 10px;
            display: grid;
            place-items: center;
            color: var(--text);
            background: var(--card-bg);
            border-color: var(--line)
        }

        .page-item.active .page-link {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border-color: var(--primary);
            color: #fff
        }

        .empty-state {
            padding: 55px 20px;
            text-align: center;
            color: var(--muted)
        }

        .loading {
            position: absolute;
            inset: 0;
            display: none;
            place-items: center;
            background: color-mix(in srgb, var(--card-bg) 85%, transparent);
            z-index: 5
        }

        .loading.show {
            display: grid
        }

        .modal-content {
            background: var(--card-bg);
            color: var(--text);
            border: 0;
            border-radius: var(--radius)
        }

        .modal-header,
        .modal-footer {
            border-color: var(--line)
        }

        .field-label {
            font-size: 10px;
            font-weight: 700;
            margin-bottom: 5px
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
        body[data-theme=dark] {
            --page-bg: #0f151b;
            --card-bg: #182129;
            --text: #f3f6f8;
            --muted: #9aa7b3;
            --line: #2c3944
        }

        @media(max-width:991.98px) {
            .stat-grid {
                grid-template-columns: repeat(2, 1fr)
            }

            .filter-grid {
                grid-template-columns: 1fr 1fr
            }

            .filter-grid .search-field {
                grid-column: 1/-1
            }

            .rates-card {
                border: 0;
                background: transparent
            }

            .rates-table thead {
                display: none
            }

            .rates-table,
            .rates-table tbody {
                display: block
            }

            .rates-table tbody {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 12px
            }

            .rates-table tr {
                display: grid;
                grid-template-columns: 1fr 1fr;
                padding: 14px;
                border: 1px solid var(--line);
                border-radius: var(--radius);
                background: var(--card-bg)
            }

            .rates-table td {
                display: flex;
                justify-content: space-between;
                padding: 8px 0;
                border: 0;
                border-bottom: 1px dashed var(--line);
                text-align: right !important
            }

            .rates-table td:before {
                content: attr(data-label);
                font-size: 9px;
                font-weight: 700;
                color: var(--muted);
                text-transform: uppercase
            }

            .rates-table td.actions {
                grid-column: 1/-1;
                border: 0
            }

            .pagination-wrap {
                border: 1px solid var(--line);
                border-radius: var(--radius);
                margin-top: 10px;
                background: var(--card-bg)
            }
        }

        @media(max-width:767.98px) {
            .filter-grid {
                grid-template-columns: 1fr
            }

            .filter-grid .search-field {
                grid-column: auto
            }

            .rates-table tbody {
                grid-template-columns: 1fr
            }

            .rates-table tr {
                grid-template-columns: 1fr
            }

            .rates-table td {
                grid-column: 1/-1
            }

            .pagination-wrap {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px
            }
        }
    </style>
</head>

<body><?php include('includes/sidebar.php'); ?>
    <main class="app-main"><?php include('includes/nav.php'); ?>
        <div class="content-wrap"><?php if (!$canView): ?>
                <div class="rates-card">
                    <div class="empty-state"><i class="fa-solid fa-lock fa-2x mb-2"></i>
                        <div>You do not have permission to view metal rates.</div>
                    </div>
                </div><?php else: ?>
                <div class="stat-grid">
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fa-solid fa-coins"></i></div>
                        <div>
                            <div class="stat-label">Total Rates</div>
                            <div class="stat-value" id="statTotal">0</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fa-solid fa-layer-group"></i></div>
                        <div>
                            <div class="stat-label">Metal Types</div>
                            <div class="stat-value" id="statMetals">0</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fa-solid fa-gem"></i></div>
                        <div>
                            <div class="stat-label">Purities</div>
                            <div class="stat-value" id="statPurities">0</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fa-solid fa-indian-rupee-sign"></i></div>
                        <div>
                            <div class="stat-label">Highest Rate / Gram</div>
                            <div class="stat-value" id="statHighest">0.00</div>
                        </div>
                    </div>
                </div>
                <div class="toolbar">
                    <form id="filterForm" class="filter-grid">
                        <div class="field has-icon search-field"><i class="fa-solid fa-magnifying-glass"></i><input
                                class="form-control" id="search" value="<?= e($initialSearch) ?>"
                                placeholder="Search metal, purity or date..."></div>
                        <div class="field"><select class="form-select" id="metalType">
                                <option value="0">All metals</option><?php foreach ($metals as $m): ?>
                                    <option value="<?= (int) $m['id'] ?>" <?= $initialMetal === (int) $m['id'] ? 'selected' : '' ?>>
                                        <?= e($m['metal_name']) ?></option><?php endforeach ?>
                            </select></div>
                        <div class="field"><input type="date" class="form-control" id="effectiveDate"
                                value="<?= e($initialDate) ?>"></div>
                        <div class="field"><select class="form-select" id="perPage"><?php foreach ([10, 25, 50, 100] as $n): ?>
                                    <option value="<?= $n ?>" <?= $initialPerPage === $n ? 'selected' : '' ?>><?= $n ?> rows</option>
                                <?php endforeach ?>
                            </select></div><button type="button" class="btn-reset" id="reset"><i
                                class="fa-solid fa-rotate-left"></i>Reset</button><?php if ($canCreate || $canUpdate): ?><button
                                type="button" class="btn-theme" id="addRate"><i class="fa-solid fa-plus"></i>Add
                                Rate</button><?php endif ?>
                    </form>
                </div>
                <section class="rates-card" id="listSection">
                    <div class="loading" id="loading">
                        <div><i class="fa-solid fa-spinner fa-spin me-2"></i>Loading...</div>
                    </div>
                    <div class="table-responsive">
                        <table class="table rates-table">
                            <thead>
                                <tr>
                                    <th>Metal</th>
                                    <th>Purity</th><?php if ($canValue): ?>
                                        <th>Rate / Gram</th>
                                        <th>Rate / 8 Gram</th><?php endif ?>
                                    <th>Effective From</th>
                                    <th>Current</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="tableBody"></tbody>
                        </table>
                    </div>
                    <div class="empty-state d-none" id="empty"><i class="fa-regular fa-folder-open fa-2x mb-2"></i>
                        <div>No metal rates found.</div>
                    </div>
                    <div class="pagination-wrap">
                        <div class="pagination-summary" id="summary">Showing 0 rates</div>
                        <ul class="pagination pagination-sm" id="pagination"></ul>
                    </div>
                </section><?php endif ?><?php include('includes/footer.php'); ?>
        </div>
    </main>
    <div class="modal fade" id="rateModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" id="rateForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Add Metal Rate</h5><button type="button" class="btn-close"
                        data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body"><input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>"><input
                        type="hidden" name="action" value="save"><input type="hidden" name="rate_id" id="rateId"
                        value="0">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="field-label">Metal type *</label><select class="form-select"
                                name="metal_id" id="rateMetal" required>
                                <option value="">Select metal</option><?php foreach ($metals as $m): ?>
                                    <option value="<?= (int) $m['id'] ?>" data-purity="<?= e($m['default_purity']) ?>">
                                        <?= e($m['metal_name']) ?></option><?php endforeach ?>
                            </select></div>
                        <div class="col-md-6"><label class="field-label">Purity *</label><input type="number"
                                step="0.0001" min="0.0001" max="100" class="form-control" name="purity" id="ratePurity"
                                placeholder="Example: 91.6000" required></div>
                        <div class="col-md-6"><label class="field-label">Rate per gram *</label><input type="number"
                                step="0.01" min="0.01" class="form-control" name="rate_per_gram" id="ratePerGram"
                                required></div>
                        <div class="col-md-6"><label class="field-label">Effective date *</label><input
                                type="datetime-local" class="form-control" name="effective_from" id="rateDate"
                                value="<?= date('Y-m-d\TH:i') ?>" required></div>
                        <div class="col-12">
                            <div class="small text-muted">Rate per 8 gram: <strong id="eightGramPreview">0.00</strong>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light btn-sm"
                        data-bs-dismiss="modal">Cancel</button><button class="btn-theme" id="saveBtn"><i
                            class="fa-solid fa-floppy-disk"></i>Save Rate</button></div>
            </form>
        </div>
    </div>
    <?php include('includes/script.php'); ?>
    <script src="assets/js/script.js"></script><?php if ($canView): ?>
        <script>
            (() => { 'use strict'; const csrf = <?= json_encode($csrfToken) ?>, canUpdate = <?= $canUpdate ? 'true' : 'false' ?>, canDelete = <?= $canDelete ? 'true' : 'false' ?>, currency = <?= json_encode((string) ($_SESSION['currency_symbol'] ?? '₹')) ?>; const el = id => document.getElementById(id), search = el('search'), metal = el('metalType'), date = el('effectiveDate'), perPage = el('perPage'), body = el('tableBody'), empty = el('empty'), loading = el('loading'), pagination = el('pagination'), summary = el('summary'); let page = <?= $initialPage ?>, timer, controller; const modal = new bootstrap.Modal(el('rateModal')); function esc(v) { return String(v ?? '').replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m])) } function toast(t, m) { const x = document.createElement('div'); x.className = 'theme-toast theme-toast-' + t; x.textContent = m; document.body.appendChild(x); requestAnimationFrame(() => x.classList.add('show')); setTimeout(() => { x.classList.remove('show'); setTimeout(() => x.remove(), 250) }, 3000) } function state(p = page) { return { search: search.value.trim(), metal_id: metal.value, effective_date: date.value, per_page: perPage.value, page: p } } function updateUrl(s, replace = false) { const u = new URL(location.href);['search', 'metal_id', 'effective_date', 'per_page', 'page'].forEach(k => u.searchParams.delete(k)); Object.entries(s).forEach(([k, v]) => { if (v && !(k === 'per_page' && v === '10') && !(k === 'page' && Number(v) === 1)) u.searchParams.set(k, v) }); history[replace ? 'replaceState' : 'pushState'](s, '', u) } function row(r) { let a = ''; if (canUpdate) a += '<button class="action-btn edit" data-id="' + r.id + '" title="Edit"><i class="fa-solid fa-pen"></i></button>'; if (canDelete) a += '<button class="action-btn danger delete" data-id="' + r.id + '" data-name="' + esc(r.metal_type) + ' ' + esc(r.purity) + '" title="Delete"><i class="fa-solid fa-trash"></i></button>'; return '<tr><td data-label="Metal"><span class="metal-badge">' + esc(r.metal_type) + '</span></td><td data-label="Purity">' + esc(r.purity) + '</td>' + <?= $canValue ? "'<td data-label=\"Rate / Gram\"><span class=\"rate-value\">'+esc(currency)+Number(r.rate_per_gram).toFixed(2)+'</span></td><td data-label=\"Rate / 8 Gram\">'+esc(currency)+(Number(r.rate_per_gram)*8).toLocaleString(undefined,{minimumFractionDigits:2})+'</td>'" : "''" ?> + '<td data-label="Effective From">' + esc(r.effective_date_display) + '</td><td data-label="Current"><span class="metal-badge">' + (Number(r.is_current) === 1 ? 'Current' : 'History') + '</span></td><td data-label="Actions" class="actions text-end"><div class="d-inline-flex gap-1">' + a + '</div></td></tr>' } function pageBtn(label, p, disabled = false, active = false) { return '<li class="page-item ' + (disabled ? 'disabled ' : '') + (active ? 'active' : '') + '"><button type="button" class="page-link" data-page="' + p + '">' + label + '</button></li>' } function renderPager(m) { let out = pageBtn('<i class="fa-solid fa-angle-left"></i>', m.page - 1, m.page <= 1); for (let i = Math.max(1, m.page - 2); i <= Math.min(m.total_pages, m.page + 2); i++)out += pageBtn(i, i, false, i === m.page); out += pageBtn('<i class="fa-solid fa-angle-right"></i>', m.page + 1, m.page >= m.total_pages); pagination.innerHTML = out; summary.textContent = m.total ? 'Showing ' + m.from + '–' + m.to + ' of ' + m.total + ' rates' : 'Showing 0 rates' } async function api(fd) { const r = await fetch('api/metal-rates-save.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } }), j = await r.json().catch(() => ({ success: false, message: 'Invalid server response.' })); if (!r.ok || !j.success) throw new Error(j.message || 'Request failed.'); return j } async function load(opt = {}) { page = opt.page || page; if (controller) controller.abort(); controller = new AbortController(); loading.classList.add('show'); const fd = new FormData(); fd.append('action', 'list'); fd.append('csrf_token', csrf); Object.entries(state()).forEach(([k, v]) => fd.append(k, v)); try { const r = await fetch('api/metal-rates-save.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }, signal: controller.signal }); const raw = await r.text(); let j; try { j = JSON.parse(raw) } catch (_) { throw new Error('Invalid server response: ' + raw.replace(/<[^>]*>/g, ' ').trim().slice(0, 220)) } if (!r.ok || !j.success) throw new Error(j.message); page = j.meta.page; body.innerHTML = j.rates.map(row).join(''); empty.classList.toggle('d-none', j.rates.length > 0); body.closest('table').classList.toggle('d-none', j.rates.length === 0); renderPager(j.meta); el('statTotal').textContent = j.stats.total; el('statMetals').textContent = j.stats.metals; el('statPurities').textContent = j.stats.purities; el('statHighest').textContent = currency + Number(j.stats.highest).toFixed(2); updateUrl(state(), opt.replace === true); if (opt.scroll) el('listSection').scrollIntoView({ behavior: 'smooth', block: 'start' }) } catch (e) { if (e.name !== 'AbortError') toast('error', e.message) } finally { loading.classList.remove('show') } } function resetForm() { el('rateForm').reset(); el('rateId').value = '0'; const now = new Date(), local = new Date(now.getTime() - now.getTimezoneOffset() * 60000); el('rateDate').value = local.toISOString().slice(0, 16); el('modalTitle').textContent = 'Add Metal Rate'; el('eightGramPreview').textContent = '0.00' } el('addRate')?.addEventListener('click', () => { resetForm(); modal.show() }); el('ratePerGram').addEventListener('input', () => el('eightGramPreview').textContent = ((parseFloat(el('ratePerGram').value) || 0) * 8).toFixed(2)); el('rateMetal').addEventListener('change', () => { const o = el('rateMetal').selectedOptions[0]; if (o && o.dataset.purity && !el('ratePurity').value) el('ratePurity').value = o.dataset.purity; }); search.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => { page = 1; load({ page: 1 }) }, 350) });[metal, date, perPage].forEach(x => x.addEventListener('change', () => { page = 1; load({ page: 1 }) })); el('reset').addEventListener('click', () => { search.value = ''; metal.value = '0'; date.value = ''; perPage.value = '10'; page = 1; load({ page: 1 }) }); pagination.addEventListener('click', e => { const b = e.target.closest('[data-page]'); if (!b || b.closest('.disabled') || b.closest('.active')) return; page = Number(b.dataset.page); load({ page, scroll: true }) }); document.addEventListener('click', async e => { const ed = e.target.closest('.edit'); if (ed) { const fd = new FormData(); fd.append('action', 'get'); fd.append('csrf_token', csrf); fd.append('rate_id', ed.dataset.id); try { const j = await api(fd), r = j.rate; resetForm(); el('rateId').value = r.id; el('rateMetal').value = r.metal_id; el('ratePurity').value = r.purity; el('ratePerGram').value = r.rate_per_gram; el('rateDate').value = r.effective_from_input; el('eightGramPreview').textContent = (Number(r.rate_per_gram) * 8).toFixed(2); el('modalTitle').textContent = 'Edit Metal Rate'; modal.show() } catch (x) { toast('error', x.message) } } const del = e.target.closest('.delete'); if (del) { if (!confirm('Delete ' + del.dataset.name + ' rate?')) return; const fd = new FormData(); fd.append('action', 'delete'); fd.append('csrf_token', csrf); fd.append('rate_id', del.dataset.id); try { const j = await api(fd); toast('success', j.message); load({ page }) } catch (x) { toast('error', x.message) } } }); el('rateForm').addEventListener('submit', async e => { e.preventDefault(); const b = el('saveBtn'), old = b.innerHTML; b.disabled = true; b.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>Saving...'; try { const j = await api(new FormData(e.currentTarget)); toast('success', j.message); modal.hide(); load({ page: 1 }) } catch (x) { toast('error', x.message) } finally { b.disabled = false; b.innerHTML = old } }); load({ replace: true }) })();
        </script><?php endif ?>
</body>

</html>