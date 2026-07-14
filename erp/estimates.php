<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));

foreach ([
    __DIR__ . '/config/config.php',
    __DIR__ . '/config.php',
    __DIR__ . '/includes/config.php',
    __DIR__ . '/super-admin/includes/config.php',
] as $configFile) {
    if (is_file($configFile)) {
        require_once $configFile;
        break;
    }
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database configuration is not available.');
}

$conn->set_charset('utf8mb4');

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function estimatePermission(mysqli $conn, string $action): bool
{
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin') {
        return true;
    }

    $map = [
        'open' => 'can_open',
        'view' => 'can_view',
        'create' => 'can_create',
        'update' => 'can_update',
        'delete' => 'can_delete',
        'value' => 'can_view_value',
    ];

    $field = $map[$action] ?? '';
    if ($field === '') {
        return false;
    }

    foreach (['perm.estimates', 'perm.sales.estimate', 'perm.sales', 'perm.billing'] as $code) {
        if (isset($_SESSION['permissions'][$code][$field])) {
            return (int)$_SESSION['permissions'][$code][$field] === 1;
        }
    }

    $businessId = (int)($_SESSION['business_id'] ?? 0);
    $roleId = (int)($_SESSION['role_id'] ?? 0);
    if ($businessId <= 0 || $roleId <= 0) {
        return false;
    }

    $sql = "SELECT rp.`{$field}`
            FROM role_permissions rp
            INNER JOIN permissions p ON p.id = rp.permission_id
            WHERE rp.business_id = ?
              AND rp.role_id = ?
              AND p.is_active = 1
              AND p.permission_code IN ('perm.estimates','perm.sales.estimate','perm.sales','perm.billing')
            ORDER BY FIELD(p.permission_code,'perm.estimates','perm.sales.estimate','perm.sales','perm.billing')
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ii', $businessId, $roleId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row[$field] ?? 0) === 1;
}

if (!estimatePermission($conn, 'open') && !estimatePermission($conn, 'view')) {
    http_response_code(403);
    die('You do not have permission to open estimates.');
}

$canCreate = estimatePermission($conn, 'create');
$canUpdate = estimatePermission($conn, 'update');
$canCancel = estimatePermission($conn, 'delete') || $canUpdate;
$canValue = estimatePermission($conn, 'value') || estimatePermission($conn, 'view');

$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? ($_SESSION['default_branch_id'] ?? 0));

if ($businessId <= 0 || $branchId <= 0) {
    die('A valid business and branch must be selected.');
}

if (empty($_SESSION['estimate_list_csrf'])) {
    $_SESSION['estimate_list_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['estimate_list_csrf'];

$theme = [
    'primary_color' => '#d89416',
    'primary_dark_color' => '#b86a0b',
    'primary_soft_color' => '#fff6e5',
    'page_background' => '#f4f3f0',
    'card_background' => '#ffffff',
    'text_color' => '#171717',
    'muted_text_color' => '#7d8794',
    'border_color' => '#e8e8e8',
    'font_family' => 'Inter',
    'heading_font_family' => 'Playfair Display',
    'border_radius_px' => 12,
    'sidebar_width_px' => 230,
    'sidebar_gradient_1' => '#171c21',
    'sidebar_gradient_2' => '#20272d',
    'sidebar_gradient_3' => '#101419',
];

$stmt = $conn->prepare('SELECT * FROM business_theme_settings WHERE business_id = ? LIMIT 1');
if ($stmt) {
    $stmt->bind_param('i', $businessId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    foreach ($theme as $key => $defaultValue) {
        if (isset($row[$key]) && $row[$key] !== '') {
            $theme[$key] = $row[$key];
        }
    }
}

$pageTitle = 'Estimates';
$businessName = (string)($_SESSION['business_name'] ?? 'Jewellery ERP');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($businessName) ?> - Estimates</title>
    <?php include('includes/links.php'); ?>
    <style>
        :root{
            --primary:<?=e($theme['primary_color'])?>;
            --primary-dark:<?=e($theme['primary_dark_color'])?>;
            --primary-soft:<?=e($theme['primary_soft_color'])?>;
            --page-bg:<?=e($theme['page_background'])?>;
            --card-bg:<?=e($theme['card_background'])?>;
            --text:<?=e($theme['text_color'])?>;
            --muted:<?=e($theme['muted_text_color'])?>;
            --line:<?=e($theme['border_color'])?>;
            --radius:<?=(int)$theme['border_radius_px']?>px;
            --sidebar-width:<?=(int)$theme['sidebar_width_px']?>px;
            --sidebar-gradient-1:<?=e($theme['sidebar_gradient_1'])?>;
            --sidebar-gradient-2:<?=e($theme['sidebar_gradient_2'])?>;
            --sidebar-gradient-3:<?=e($theme['sidebar_gradient_3'])?>;
        }

        body{
            background:var(--page-bg);
            color:var(--text);
            font-family:<?=json_encode($theme['font_family'])?>,sans-serif;
        }

        .sidebar{
            background:linear-gradient(
                180deg,
                var(--sidebar-gradient-1),
                var(--sidebar-gradient-2),
                var(--sidebar-gradient-3)
            )!important;
        }

        .page-card,.stat-card{
            background:var(--card-bg);
            border:1px solid var(--line);
            border-radius:var(--radius);
        }

        .page-head{
            padding:15px 17px;
            border-bottom:1px solid var(--line);
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:12px;
        }

        .page-title{
            font:700 20px <?=json_encode($theme['heading_font_family'])?>,serif;
        }

        .stat-grid{
            display:grid;
            grid-template-columns:repeat(4,minmax(0,1fr));
            gap:10px;
            margin-bottom:12px;
        }

        .stat-card{
            padding:14px;
            display:flex;
            align-items:center;
            gap:11px;
        }

        .stat-icon{
            width:42px;
            height:42px;
            border-radius:10px;
            display:grid;
            place-items:center;
            background:var(--primary-soft);
            color:var(--primary-dark);
        }

        .stat-label{
            font-size:10px;
            color:var(--muted);
        }

        .stat-value{
            font-size:21px;
            font-weight:800;
        }

        .toolbar{
            padding:12px;
            margin-bottom:12px;
        }

        .filter-grid{
            display:grid;
            grid-template-columns:1fr 1fr 1.6fr .9fr auto auto;
            gap:8px;
        }

        .form-control,.form-select{
            min-height:39px;
            border:1px solid var(--line);
            border-radius:9px;
            background:var(--card-bg);
            color:var(--text);
            font-size:11px;
        }

        .btn-theme{
            min-height:39px;
            border:0;
            border-radius:9px;
            padding:8px 14px;
            color:#fff;
            background:linear-gradient(135deg,var(--primary),var(--primary-dark));
            font-size:11px;
            font-weight:700;
        }

        .btn-soft{
            min-height:39px;
            border:1px solid var(--line);
            border-radius:9px;
            padding:8px 14px;
            background:var(--card-bg);
            color:var(--text);
            font-size:11px;
        }

        .estimate-table{
            margin:0;
            font-size:10px;
        }

        .estimate-table th{
            font-size:9px;
            text-transform:uppercase;
            color:var(--muted);
            background:color-mix(in srgb,var(--muted) 6%,transparent);
            white-space:nowrap;
        }

        .estimate-table td,.estimate-table th{
            padding:10px 12px;
            border-color:var(--line);
            vertical-align:middle;
        }

        .badge-soft{
            display:inline-flex;
            border-radius:999px;
            padding:4px 8px;
            font-size:9px;
            font-weight:700;
        }

        .paid{background:#eaf8f0;color:#168449}
        .partial{background:#fff4d8;color:#9a6700}
        .unpaid{background:#fdecec;color:#bd2d2d}
        .cancelled{background:#edf0f2;color:#5f6b74}

        .action-btn{
            width:30px;
            height:30px;
            border:1px solid var(--line);
            border-radius:8px;
            background:var(--card-bg);
            color:var(--text);
            display:inline-grid;
            place-items:center;
            text-decoration:none;
        }

        .action-btn:hover{
            background:var(--primary-soft);
            color:var(--primary-dark);
        }

        .loading{
            display:none;
            padding:45px;
            text-align:center;
            color:var(--muted);
        }

        .loading.show{display:block}

        .empty{
            display:none;
            padding:45px;
            text-align:center;
            color:var(--muted);
        }

        .empty.show{display:block}

        .pagination-wrap{
            padding:11px 12px;
            border-top:1px solid var(--line);
            display:flex;
            justify-content:space-between;
            align-items:center;
        }

        .pagination{
            margin:0;
            gap:4px;
        }

        .page-link{
            border-radius:8px!important;
            font-size:10px;
            color:var(--text);
            background:var(--card-bg);
            border-color:var(--line);
        }

        .page-item.active .page-link{
            background:linear-gradient(135deg,var(--primary),var(--primary-dark));
            border-color:var(--primary);
            color:#fff;
        }

        .theme-toast{
            position:fixed;
            right:18px;
            top:78px;
            z-index:20000;
            padding:11px 14px;
            border-radius:10px;
            color:#fff;
            font-size:11px;
            font-weight:600;
            opacity:0;
            transform:translateY(-10px);
            transition:.22s;
        }

        .theme-toast.show{opacity:1;transform:none}
        .theme-toast-success{background:#168449}
        .theme-toast-error{background:#c0392b}

        .modal-content{
            background:var(--card-bg);
            color:var(--text);
            border-color:var(--line);
            border-radius:var(--radius);
        }

        .modal-header,.modal-footer{border-color:var(--line)}

        .detail-grid{
            display:grid;
            grid-template-columns:repeat(3,minmax(0,1fr));
            gap:10px;
        }

        .detail-box{
            padding:10px;
            border:1px solid var(--line);
            border-radius:9px;
        }

        .detail-label{
            font-size:9px;
            color:var(--muted);
        }

        .detail-value{
            font-size:11px;
            font-weight:700;
        }

        body.dark-mode,body[data-theme=dark]{
            --page-bg:#0f151b;
            --card-bg:#182129;
            --text:#f3f6f8;
            --muted:#9aa7b3;
            --line:#2c3944;
        }

        @media(max-width:991px){
            .stat-grid{grid-template-columns:1fr 1fr}
            .filter-grid{grid-template-columns:1fr 1fr}
            .filter-grid .search{grid-column:1/-1}
        }

        @media(max-width:767px){
            .stat-grid,.filter-grid,.detail-grid{grid-template-columns:1fr}
            .filter-grid .search{grid-column:auto}
        }
    </style>
</head>
<body>
<?php include('includes/sidebar.php'); ?>

<main class="app-main">
    <?php include('includes/nav.php'); ?>

    <div class="content-wrap">
        <div class="page-card mb-3">
            <div class="page-head">
                <div>
                    <div class="page-title">Estimates</div>
                    <div class="small text-muted">View, filter, print, edit and cancel estimate bills.</div>
                </div>

                <?php if ($canCreate): ?>
                    <a href="estimate.php" class="btn-theme text-decoration-none">
                        <i class="fa-solid fa-plus me-2"></i>New Estimate
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <div class="stat-grid">
            <div class="stat-card">
                <div class="stat-icon"><i class="fa-solid fa-file-lines"></i></div>
                <div>
                    <div class="stat-label">Total Estimates</div>
                    <div class="stat-value" id="statCount">0</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon"><i class="fa-solid fa-indian-rupee-sign"></i></div>
                <div>
                    <div class="stat-label">Estimate Total</div>
                    <div class="stat-value" id="statTotal">₹0.00</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon"><i class="fa-solid fa-circle-check"></i></div>
                <div>
                    <div class="stat-label">Paid Amount</div>
                    <div class="stat-value" id="statPaid">₹0.00</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon"><i class="fa-solid fa-clock"></i></div>
                <div>
                    <div class="stat-label">Balance Amount</div>
                    <div class="stat-value" id="statBalance">₹0.00</div>
                </div>
            </div>
        </div>

        <div class="page-card toolbar">
            <form id="filterForm" class="filter-grid">
                <input type="date" id="fromDate" class="form-control" value="<?=date('Y-m-01')?>">
                <input type="date" id="toDate" class="form-control" value="<?=date('Y-m-d')?>">
                <input type="search" id="searchInput" class="form-control search" placeholder="Estimate no, customer or mobile">
                <select id="statusFilter" class="form-select">
                    <option value="">All status</option>
                    <option value="Posted">Posted</option>
                    <option value="Cancelled">Cancelled</option>
                </select>
                <button type="submit" class="btn-theme">
                    <i class="fa-solid fa-magnifying-glass me-1"></i>Search
                </button>
                <button type="button" id="resetFilter" class="btn-soft">
                    <i class="fa-solid fa-rotate-left"></i>
                </button>
            </form>
        </div>

        <section class="page-card">
            <div class="loading" id="loading">
                <i class="fa-solid fa-spinner fa-spin me-2"></i>Loading estimates...
            </div>

            <div class="table-responsive" id="tableWrap">
                <table class="table estimate-table">
                    <thead>
                        <tr>
                            <th>Estimate</th>
                            <th>Date</th>
                            <th>Customer</th>
                            <?php if ($canValue): ?>
                                <th class="text-end">Grand Total</th>
                                <th class="text-end">Paid</th>
                                <th class="text-end">Balance</th>
                            <?php endif; ?>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="estimateBody"></tbody>
                </table>
            </div>

            <div class="empty" id="emptyState">
                <i class="fa-regular fa-folder-open fa-2x mb-2"></i>
                <div>No estimates found.</div>
            </div>

            <div class="pagination-wrap">
                <div class="small text-muted" id="pageSummary">Showing 0 records</div>
                <ul class="pagination pagination-sm" id="pagination"></ul>
            </div>
        </section>

        <?php include('includes/footer.php'); ?>
    </div>
</main>

<div class="modal fade" id="estimateModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title mb-0">Estimate Details</h5>
                    <div class="small text-muted" id="modalEstimateNo"></div>
                </div>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body" id="modalBody"></div>

            <div class="modal-footer">
                <a href="#" target="_blank" id="modalPrintBtn" class="btn-soft text-decoration-none">
                    <i class="fa-solid fa-print me-2"></i>Print
                </a>
                <?php if ($canUpdate): ?>
                    <a href="#" id="modalEditBtn" class="btn-theme text-decoration-none">
                        <i class="fa-solid fa-pen me-2"></i>Edit
                    </a>
                <?php endif; ?>
                <button class="btn-soft" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<?php include('includes/script.php'); ?>
<script src="assets/js/script.js"></script>

<script>
(() => {
    'use strict';

    const csrfToken = <?=json_encode($csrfToken)?>;
    const canCancel = <?=$canCancel ? 'true' : 'false'?>;
    const canUpdate = <?=$canUpdate ? 'true' : 'false'?>;
    const canValue = <?=$canValue ? 'true' : 'false'?>;
    const apiUrl = 'api/estimates.php';

    const body = document.getElementById('estimateBody');
    const loading = document.getElementById('loading');
    const tableWrap = document.getElementById('tableWrap');
    const empty = document.getElementById('emptyState');
    const pagination = document.getElementById('pagination');

    let currentPage = 1;

    function esc(value) {
        return String(value ?? '').replace(/[&<>'"]/g, c => ({
            '&':'&amp;',
            '<':'&lt;',
            '>':'&gt;',
            "'":'&#039;',
            '"':'&quot;'
        }[c]));
    }

    function money(value) {
        return Number(value || 0).toLocaleString('en-IN', {
            minimumFractionDigits:2,
            maximumFractionDigits:2
        });
    }

    function toast(type, message) {
        const element = document.createElement('div');
        element.className = 'theme-toast theme-toast-' + type;
        element.textContent = message;
        document.body.appendChild(element);

        requestAnimationFrame(() => element.classList.add('show'));

        setTimeout(() => {
            element.classList.remove('show');
            setTimeout(() => element.remove(), 250);
        }, 3400);
    }

    async function request(data) {
        const form = new FormData();

        Object.entries(data).forEach(([key, value]) => {
            form.append(key, value);
        });

        form.append('csrf_token', csrfToken);

        const response = await fetch(apiUrl, {
            method:'POST',
            body:form,
            credentials:'same-origin',
            headers:{
                'X-Requested-With':'XMLHttpRequest',
                'Accept':'application/json'
            }
        });

        const raw = await response.text();
        let result;

        try {
            result = JSON.parse(raw);
        } catch (error) {
            const clean = raw.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
            throw new Error(
                'Estimates API did not return JSON. HTTP ' +
                response.status +
                (clean ? ': ' + clean.substring(0, 300) : '')
            );
        }

        if (!response.ok || !result.success) {
            throw new Error(result.message || 'Request failed.');
        }

        return result;
    }

    function statusBadge(row) {
        if (row.workflow_status === 'Cancelled') {
            return '<span class="badge-soft cancelled">Cancelled</span>';
        }

        const paymentClass =
            row.payment_status === 'Paid'
                ? 'paid'
                : (row.payment_status === 'Partial' ? 'partial' : 'unpaid');

        return '<span class="badge-soft ' + paymentClass + '">' +
            esc(row.payment_status) +
            '</span>';
    }

    async function loadEstimates(page = 1) {
        currentPage = page;
        loading.classList.add('show');
        tableWrap.style.display = 'none';
        empty.classList.remove('show');

        try {
            const result = await request({
                action:'list',
                page:page,
                per_page:10,
                from_date:document.getElementById('fromDate').value,
                to_date:document.getElementById('toDate').value,
                status:document.getElementById('statusFilter').value,
                search:document.getElementById('searchInput').value.trim()
            });

            body.innerHTML = result.estimates.map(row => `
                <tr>
                    <td>
                        <strong>${esc(row.invoice_no)}</strong>
                        <div class="small text-muted">${esc(row.primary_payment_method || '-')}</div>
                    </td>
                    <td>
                        ${esc(row.invoice_date_display)}
                        <div class="small text-muted">${esc(row.invoice_time_display)}</div>
                    </td>
                    <td>
                        <strong>${esc(row.customer_name || 'Walk-in Customer')}</strong>
                        <div class="small text-muted">${esc(row.customer_mobile || '')}</div>
                    </td>

                    ${canValue ? `
                        <td class="text-end">₹${money(row.grand_total)}</td>
                        <td class="text-end">₹${money(row.paid_amount)}</td>
                        <td class="text-end">₹${money(row.balance_amount)}</td>
                    ` : ''}

                    <td>${statusBadge(row)}</td>

                    <td class="text-end">
                        <button type="button" class="action-btn view-estimate" data-id="${row.id}" title="View">
                            <i class="fa-regular fa-eye"></i>
                        </button>

                        <a href="estimate-print.php?id=${row.id}" target="_blank" class="action-btn" title="Print">
                            <i class="fa-solid fa-print"></i>
                        </a>

                        ${canUpdate && row.workflow_status !== 'Cancelled' ? `
                            <a href="estimate.php?edit_id=${row.id}" class="action-btn" title="Edit">
                                <i class="fa-solid fa-pen"></i>
                            </a>
                        ` : ''}

                        ${canCancel && row.workflow_status !== 'Cancelled' ? `
                            <button type="button" class="action-btn cancel-estimate"
                                    data-id="${row.id}"
                                    data-no="${esc(row.invoice_no)}"
                                    title="Cancel">
                                <i class="fa-solid fa-ban"></i>
                            </button>
                        ` : ''}
                    </td>
                </tr>
            `).join('');

            document.getElementById('statCount').textContent = result.stats.total_estimates;
            document.getElementById('statTotal').textContent = '₹' + money(result.stats.estimate_total);
            document.getElementById('statPaid').textContent = '₹' + money(result.stats.paid_total);
            document.getElementById('statBalance').textContent = '₹' + money(result.stats.balance_total);

            document.getElementById('pageSummary').textContent =
                `Showing ${result.meta.from}-${result.meta.to} of ${result.meta.total}`;

            pagination.innerHTML = '';

            for (let pageNo = 1; pageNo <= result.meta.total_pages; pageNo++) {
                pagination.insertAdjacentHTML(
                    'beforeend',
                    `<li class="page-item ${pageNo === result.meta.page ? 'active' : ''}">
                        <button class="page-link page-go" data-page="${pageNo}">${pageNo}</button>
                    </li>`
                );
            }

            tableWrap.style.display = result.estimates.length ? '' : 'none';
            empty.classList.toggle('show', !result.estimates.length);
        } catch (error) {
            toast('error', error.message);
            empty.classList.add('show');
        } finally {
            loading.classList.remove('show');
        }
    }

    async function viewEstimate(id) {
        try {
            const result = await request({
                action:'view',
                sale_id:id
            });

            const sale = result.estimate;

            document.getElementById('modalEstimateNo').textContent = sale.invoice_no;
            document.getElementById('modalPrintBtn').href = 'estimate-print.php?id=' + sale.id;

            if (canUpdate) {
                document.getElementById('modalEditBtn').href = 'estimate.php?edit_id=' + sale.id;
                document.getElementById('modalEditBtn').style.display =
                    sale.workflow_status === 'Cancelled' ? 'none' : '';
            }

            document.getElementById('modalBody').innerHTML = `
                <div class="detail-grid mb-3">
                    <div class="detail-box">
                        <div class="detail-label">Estimate Date</div>
                        <div class="detail-value">${esc(sale.invoice_date_display)} ${esc(sale.invoice_time_display)}</div>
                    </div>

                    <div class="detail-box">
                        <div class="detail-label">Customer</div>
                        <div class="detail-value">${esc(sale.customer_name || 'Walk-in Customer')} ${esc(sale.customer_mobile || '')}</div>
                    </div>

                    <div class="detail-box">
                        <div class="detail-label">Status</div>
                        <div class="detail-value">${esc(sale.workflow_status)} / ${esc(sale.payment_status)}</div>
                    </div>

                    <div class="detail-box">
                        <div class="detail-label">Grand Total</div>
                        <div class="detail-value">₹${money(sale.grand_total)}</div>
                    </div>

                    <div class="detail-box">
                        <div class="detail-label">Paid Amount</div>
                        <div class="detail-value">₹${money(sale.paid_amount)}</div>
                    </div>

                    <div class="detail-box">
                        <div class="detail-label">Balance Amount</div>
                        <div class="detail-value">₹${money(sale.balance_amount)}</div>
                    </div>
                </div>

                <div class="table-responsive mb-3">
                    <table class="table estimate-table">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Qty</th>
                                <th>Gross</th>
                                <th>Net</th>
                                <th>Rate</th>
                                <th>Tax</th>
                                <th class="text-end">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${result.items.map(item => `
                                <tr>
                                    <td>${esc(item.item_name)}</td>
                                    <td>${Number(item.quantity).toFixed(3)}</td>
                                    <td>${Number(item.gross_weight).toFixed(3)}</td>
                                    <td>${Number(item.net_weight).toFixed(3)}</td>
                                    <td>₹${money(item.metal_rate)}</td>
                                    <td>₹${money(item.tax_amount)}</td>
                                    <td class="text-end">₹${money(item.line_total)}</td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>

                <div class="page-card p-3 mb-3">
                    <div class="fw-bold mb-2">Payment Split</div>

                    ${result.payments.length
                        ? result.payments.map(payment => `
                            <div class="d-flex justify-content-between border-bottom py-2">
                                <span>
                                    ${esc(payment.method_name)}
                                    ${payment.reference_no ? ' - ' + esc(payment.reference_no) : ''}
                                </span>
                                <strong>₹${money(payment.amount)}</strong>
                            </div>
                        `).join('')
                        : '<div class="small text-muted">No payment rows.</div>'
                    }
                </div>

                ${sale.notes
                    ? `<div class="page-card p-3">
                        <div class="small text-muted mb-1">Notes</div>
                        <div>${esc(sale.notes)}</div>
                    </div>`
                    : ''
                }
            `;

            bootstrap.Modal
                .getOrCreateInstance(document.getElementById('estimateModal'))
                .show();
        } catch (error) {
            toast('error', error.message);
        }
    }

    async function cancelEstimate(id, invoiceNo) {
        const reason = prompt(
            'Enter cancellation reason for ' + invoiceNo + ':',
            'Cancelled from estimates list'
        );

        if (reason === null) {
            return;
        }

        if (!reason.trim()) {
            toast('error', 'Cancellation reason is required.');
            return;
        }

        try {
            const result = await request({
                action:'cancel',
                sale_id:id,
                cancel_reason:reason.trim()
            });

            toast('success', result.message);
            loadEstimates(currentPage);
        } catch (error) {
            toast('error', error.message);
        }
    }

    document.getElementById('filterForm').addEventListener('submit', event => {
        event.preventDefault();
        loadEstimates(1);
    });

    document.getElementById('resetFilter').addEventListener('click', () => {
        document.getElementById('fromDate').value = <?=json_encode(date('Y-m-01'))?>;
        document.getElementById('toDate').value = <?=json_encode(date('Y-m-d'))?>;
        document.getElementById('searchInput').value = '';
        document.getElementById('statusFilter').value = '';
        loadEstimates(1);
    });

    document.addEventListener('click', event => {
        const pageButton = event.target.closest('.page-go');
        if (pageButton) {
            loadEstimates(Number(pageButton.dataset.page));
        }

        const viewButton = event.target.closest('.view-estimate');
        if (viewButton) {
            viewEstimate(viewButton.dataset.id);
        }

        const cancelButton = event.target.closest('.cancel-estimate');
        if (cancelButton) {
            cancelEstimate(cancelButton.dataset.id, cancelButton.dataset.no);
        }
    });

    loadEstimates();
})();
</script>
</body>
</html>
