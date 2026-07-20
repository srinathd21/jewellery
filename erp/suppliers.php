<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));

foreach ([
    __DIR__ . '/config/config.php',
    __DIR__ . '/config.php',
    __DIR__ . '/includes/config.php',
    __DIR__ . '/super-admin/includes/config.php'
] as $file) {
    if (is_file($file)) {
        require_once $file;
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

function supplierPermission(mysqli $conn, string $action): bool
{
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin') {
        return true;
    }

    $fields = [
        'open' => 'can_open',
        'view' => 'can_view',
        'create' => 'can_create',
        'update' => 'can_update',
        'delete' => 'can_delete',
        'value' => 'can_view_value'
    ];

    $field = $fields[$action] ?? '';
    if ($field === '') {
        return false;
    }

    foreach (['perm.suppliers', 'perm.purchases.suppliers', 'perm.purchases'] as $code) {
        if (isset($_SESSION['permissions'][$code][$field])) {
            return (int)$_SESSION['permissions'][$code][$field] === 1;
        }
    }

    return true;
}

if (!supplierPermission($conn, 'open') && !supplierPermission($conn, 'view')) {
    http_response_code(403);
    die('You do not have permission to open suppliers.');
}

$canCreate = supplierPermission($conn, 'create');
$canUpdate = supplierPermission($conn, 'update');
$canDelete = supplierPermission($conn, 'delete');
$canValue = supplierPermission($conn, 'value') || supplierPermission($conn, 'view');

$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? ($_SESSION['default_branch_id'] ?? 0));

if ($businessId <= 0) {
    die('A valid business must be selected.');
}

if (empty($_SESSION['suppliers_csrf'])) {
    $_SESSION['suppliers_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['suppliers_csrf'];

$theme = [
    'primary_color' => '#d89416',
    'primary_dark_color' => '#b86a0b',
    'primary_soft_color' => '#fff6e5',
    'sidebar_gradient_1' => '#171c21',
    'sidebar_gradient_2' => '#20272d',
    'sidebar_gradient_3' => '#101419',
    'page_background' => '#f4f3f0',
    'card_background' => '#ffffff',
    'text_color' => '#171717',
    'muted_text_color' => '#7d8794',
    'border_color' => '#e8e8e8',
    'font_family' => 'Inter',
    'heading_font_family' => 'Playfair Display',
    'border_radius_px' => 12
];

$stmt = $conn->prepare('SELECT * FROM business_theme_settings WHERE business_id=? LIMIT 1');
if ($stmt) {
    $stmt->bind_param('i', $businessId);
    $stmt->execute();
    $themeRow = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    foreach ($theme as $key => $defaultValue) {
        if (isset($themeRow[$key]) && $themeRow[$key] !== '') {
            $theme[$key] = $themeRow[$key];
        }
    }
}

$pageTitle = 'Suppliers';
$businessName = (string)($_SESSION['business_name'] ?? 'Jewellery ERP');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($businessName) ?> - Suppliers</title>
    <?php include('includes/links.php'); ?>
    <style>
        :root{
            --primary:<?= e($theme['primary_color']) ?>;
            --primary-dark:<?= e($theme['primary_dark_color']) ?>;
            --primary-soft:<?= e($theme['primary_soft_color']) ?>;
            --page-bg:<?= e($theme['page_background']) ?>;
            --card-bg:<?= e($theme['card_background']) ?>;
            --text:<?= e($theme['text_color']) ?>;
            --muted:<?= e($theme['muted_text_color']) ?>;
            --line:<?= e($theme['border_color']) ?>;
            --radius:<?= (int)$theme['border_radius_px'] ?>px;
        }

        body{
            background:var(--page-bg);
            color:var(--text);
            font-family:<?= json_encode($theme['font_family']) ?>,sans-serif;
        }

        .sidebar{
            background:linear-gradient(
                180deg,
                <?= e($theme['sidebar_gradient_1']) ?>,
                <?= e($theme['sidebar_gradient_2']) ?>,
                <?= e($theme['sidebar_gradient_3']) ?>
            )!important;
        }

        .page-card,.stat-card{
            background:var(--card-bg);
            border:1px solid var(--line);
            border-radius:var(--radius);
        }

        .page-head{
            padding:14px 16px;
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
        }

        .page-title{
            font:700 21px <?= json_encode($theme['heading_font_family']) ?>,serif;
        }

        .page-subtitle{
            color:var(--muted);
            font-size:10px;
            margin-top:2px;
        }

        .btn-theme{
            border:0;
            min-height:38px;
            border-radius:9px;
            padding:8px 13px;
            color:#fff;
            background:linear-gradient(135deg,var(--primary),var(--primary-dark));
            font-size:11px;
            font-weight:800;
        }

        .btn-soft{
            border:1px solid var(--line);
            min-height:38px;
            border-radius:9px;
            padding:8px 13px;
            color:var(--text);
            background:var(--card-bg);
            font-size:11px;
            font-weight:700;
        }

        .stat-grid{
            display:grid;
            grid-template-columns:repeat(4,minmax(0,1fr));
            gap:10px;
            margin-bottom:12px;
        }

        .stat-card{
            padding:13px;
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
            color:var(--muted);
            font-size:9px;
            text-transform:uppercase;
        }

        .stat-value{
            font-size:20px;
            font-weight:900;
            margin-top:2px;
        }

        .filter-panel{
            padding:12px;
            margin-bottom:12px;
        }

        .filter-grid{
            display:grid;
            grid-template-columns:1fr 1fr 1.6fr auto auto;
            gap:8px;
        }

        .form-control,.form-select{
            min-height:38px;
            border:1px solid var(--line);
            border-radius:9px;
            background:var(--card-bg);
            color:var(--text);
            font-size:11px;
        }

        .supplier-table{
            margin:0;
            font-size:10px;
        }

        .supplier-table th{
            font-size:9px;
            text-transform:uppercase;
            color:var(--muted);
            background:color-mix(in srgb,var(--muted) 6%,transparent);
            white-space:nowrap;
        }

        .supplier-table th,.supplier-table td{
            padding:10px 11px;
            border-color:var(--line);
            vertical-align:middle;
        }

        .supplier-avatar{
            width:36px;
            height:36px;
            border-radius:10px;
            display:grid;
            place-items:center;
            background:var(--primary-soft);
            color:var(--primary-dark);
            font-weight:900;
        }

        .supplier-name{
            font-weight:900;
            font-size:11px;
        }

        .supplier-code{
            color:var(--muted);
            font-size:8px;
            margin-top:2px;
        }

        .badge-soft{
            display:inline-flex;
            align-items:center;
            border-radius:999px;
            padding:4px 8px;
            font-size:9px;
            font-weight:800;
        }

        .badge-active{background:#eaf8f0;color:#168449}
        .badge-inactive{background:#fdecec;color:#bd2d2d}

        .action-group{
            display:inline-flex;
            align-items:center;
            justify-content:flex-end;
            gap:4px;
        }

        .action-btn{
            width:31px;
            height:31px;
            border:1px solid var(--line);
            border-radius:8px;
            background:var(--card-bg);
            color:var(--text);
            display:inline-grid;
            place-items:center;
        }

        .action-btn:hover{
            background:var(--primary-soft);
            color:var(--primary-dark);
        }

        .pagination-wrap{
            padding:10px 12px;
            border-top:1px solid var(--line);
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:10px;
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

        .empty-state,.loading-state{
            padding:42px 20px;
            text-align:center;
            color:var(--muted);
        }

        .modal-content{
            background:var(--card-bg);
            color:var(--text);
            border-color:var(--line);
            border-radius:var(--radius);
        }

        .modal-header,.modal-footer{
            border-color:var(--line);
        }

        #supplierFormModal .modal-dialog{
            height:calc(100vh - 32px);
            max-height:calc(100vh - 32px);
            margin-top:16px;
            margin-bottom:16px;
        }

        #supplierFormModal .modal-content{
            height:100%;
            max-height:100%;
            overflow:hidden;
        }

        #supplierForm{
            display:flex;
            flex-direction:column;
            min-height:0;
            height:100%;
        }

        #supplierFormModal .modal-header,
        #supplierFormModal .modal-footer{
            flex:0 0 auto;
            background:var(--card-bg);
            position:relative;
            z-index:2;
        }

        #supplierFormModal .modal-body{
            flex:1 1 auto;
            min-height:0;
            overflow-y:auto;
            overflow-x:hidden;
            overscroll-behavior:contain;
            scrollbar-gutter:stable;
            padding-bottom:20px;
        }

        #supplierFormModal .modal-footer{
            box-shadow:0 -8px 20px rgba(0,0,0,.05);
        }

        .form-label{
            font-size:9px;
            font-weight:800;
            color:var(--muted);
            text-transform:uppercase;
            margin-bottom:4px;
        }

        .detail-grid{
            display:grid;
            grid-template-columns:repeat(3,minmax(0,1fr));
            gap:9px;
        }

        .detail-box{
            border:1px solid var(--line);
            border-radius:9px;
            padding:10px;
        }

        .detail-label{
            font-size:8px;
            color:var(--muted);
            text-transform:uppercase;
        }

        .detail-value{
            font-size:11px;
            font-weight:800;
            margin-top:3px;
            word-break:break-word;
        }

        .theme-toast{
            position:fixed;
            right:18px;
            top:78px;
            z-index:20000;
            min-width:260px;
            max-width:420px;
            padding:11px 14px;
            border-radius:10px;
            color:#fff;
            font-size:11px;
            font-weight:700;
            opacity:0;
            transform:translateY(-10px);
            transition:.22s;
        }

        .theme-toast.show{opacity:1;transform:none}
        .theme-toast-success{background:#168449}
        .theme-toast-error{background:#c0392b}

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
            #supplierFormModal .modal-dialog{
                height:calc(100vh - 12px);
                max-height:calc(100vh - 12px);
                margin:6px;
            }
            #supplierFormModal .modal-footer{
                display:grid;
                grid-template-columns:1fr 1fr;
            }
            #supplierFormModal .modal-footer .btn-soft,
            #supplierFormModal .modal-footer .btn-theme{
                width:100%;
            }
            .page-head{align-items:flex-start;flex-direction:column}
            .stat-grid,.filter-grid,.detail-grid{grid-template-columns:1fr}
            .filter-grid .search{grid-column:auto}
            .supplier-table thead{display:none}
            .supplier-table tbody{display:grid;gap:10px;padding:10px}
            .supplier-table tbody tr{display:grid;grid-template-columns:1fr 1fr;border:1px solid var(--line);border-radius:var(--radius);padding:10px}
            .supplier-table tbody td{display:flex;justify-content:space-between;gap:10px;border:0;border-bottom:1px dashed var(--line);padding:8px 0}
            .supplier-table tbody td::before{content:attr(data-label);font-size:8px;text-transform:uppercase;color:var(--muted);font-weight:800}
            .supplier-table tbody td:first-child,.supplier-table tbody td:last-child{grid-column:1/-1}
            .pagination-wrap{align-items:flex-start;flex-direction:column}
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
                    <div class="page-title">Suppliers</div>
                    <div class="page-subtitle">Manage jewellery, gold, stone, packaging and service suppliers.</div>
                </div>

                <?php if ($canCreate): ?>
                    <button type="button" class="btn-theme" id="addSupplier">
                        <i class="fa-solid fa-plus me-1"></i>Add Supplier
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <div class="stat-grid">
            <div class="stat-card">
                <div class="stat-icon"><i class="fa-solid fa-truck-field"></i></div>
                <div>
                    <div class="stat-label">Total Suppliers</div>
                    <div class="stat-value" id="statTotal">0</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fa-solid fa-circle-check"></i></div>
                <div>
                    <div class="stat-label">Active</div>
                    <div class="stat-value" id="statActive">0</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fa-solid fa-scale-balanced"></i></div>
                <div>
                    <div class="stat-label">Opening Balance</div>
                    <div class="stat-value" id="statOpening">₹0.00</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fa-solid fa-credit-card"></i></div>
                <div>
                    <div class="stat-label">Credit Limit</div>
                    <div class="stat-value" id="statCredit">₹0.00</div>
                </div>
            </div>
        </div>

        <div class="page-card filter-panel">
            <form id="filterForm" class="filter-grid">
                <select class="form-select" id="statusFilter">
                    <option value="">All Statuses</option>
                    <option value="1">Active</option>
                    <option value="0">Inactive</option>
                </select>

                <select class="form-select" id="typeFilter">
                    <option value="">All Supplier Types</option>
                    <option value="Gold">Gold</option>
                    <option value="Silver">Silver</option>
                    <option value="Diamond">Diamond</option>
                    <option value="Stone">Stone</option>
                    <option value="Packaging">Packaging</option>
                    <option value="Service">Service</option>
                    <option value="General">General</option>
                </select>

                <input type="search" class="form-control search" id="searchInput"
                       placeholder="Supplier name, code, mobile, GSTIN...">

                <button type="submit" class="btn-theme">
                    <i class="fa-solid fa-magnifying-glass me-1"></i>Search
                </button>

                <button type="button" class="btn-soft" id="resetFilter">
                    <i class="fa-solid fa-rotate-left"></i>
                </button>
            </form>
        </div>

        <section class="page-card">
            <div class="loading-state" id="loadingState">
                <i class="fa-solid fa-spinner fa-spin me-2"></i>Loading suppliers...
            </div>

            <div class="table-responsive d-none" id="tableWrap">
                <table class="table supplier-table">
                    <thead>
                    <tr>
                        <th>Supplier</th>
                        <th>Type</th>
                        <th>Contact</th>
                        <th>GSTIN</th>
                        <?php if ($canValue): ?>
                            <th class="text-end">Opening Balance</th>
                            <th class="text-end">Credit Limit</th>
                        <?php endif; ?>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                    </thead>
                    <tbody id="supplierBody"></tbody>
                </table>
            </div>

            <div class="empty-state d-none" id="emptyState">
                <i class="fa-regular fa-folder-open fa-2x mb-2"></i>
                <div>No suppliers found.</div>
            </div>

            <div class="pagination-wrap">
                <div class="small text-muted" id="pageSummary">Showing 0 records</div>
                <ul class="pagination pagination-sm" id="pagination"></ul>
            </div>
        </section>

        <?php include('includes/footer.php'); ?>
    </div>
</main>

<div class="modal fade" id="supplierFormModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <form id="supplierForm">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title mb-0" id="supplierFormTitle">Add Supplier</h5>
                        <div class="small text-muted">Enter supplier business and contact details.</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="supplier_id" id="supplierId" value="0">

                    <div class="row g-2">
                        <div class="col-md-4">
                            <label class="form-label">Supplier Code</label>
                            <input name="supplier_code" id="supplierCode" class="form-control"
                                   placeholder="Auto generated if empty">
                        </div>

                        <div class="col-md-8">
                            <label class="form-label">Supplier Name *</label>
                            <input name="supplier_name" id="supplierName" class="form-control" required>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Supplier Type</label>
                            <select name="supplier_type" id="supplierType" class="form-select">
                                <option value="General">General</option>
                                <option value="Gold">Gold</option>
                                <option value="Silver">Silver</option>
                                <option value="Diamond">Diamond</option>
                                <option value="Stone">Stone</option>
                                <option value="Packaging">Packaging</option>
                                <option value="Service">Service</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Contact Person</label>
                            <input name="contact_person" id="contactPerson" class="form-control">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Mobile *</label>
                            <input name="mobile" id="mobile" class="form-control" maxlength="20" required>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Alternate Mobile</label>
                            <input name="alternate_mobile" id="alternateMobile" class="form-control" maxlength="20">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" id="email" class="form-control">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">GSTIN</label>
                            <input name="gstin" id="gstin" class="form-control" maxlength="20">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">PAN</label>
                            <input name="pan_no" id="panNo" class="form-control" maxlength="20">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Opening Balance</label>
                            <input type="number" name="opening_balance" id="openingBalance"
                                   class="form-control" step="0.01" value="0">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Credit Limit</label>
                            <input type="number" name="credit_limit" id="creditLimit"
                                   class="form-control" min="0" step="0.01" value="0">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Credit Days</label>
                            <input type="number" name="credit_days" id="creditDays"
                                   class="form-control" min="0" value="0">
                        </div>

                        <div class="col-md-12">
                            <label class="form-label">Address</label>
                            <input name="address_line1" id="addressLine1" class="form-control">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">City</label>
                            <input name="city" id="city" class="form-control">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">State</label>
                            <input name="state" id="state" class="form-control">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Pincode</label>
                            <input name="pincode" id="pincode" class="form-control" maxlength="10">
                        </div>

                        <div class="col-md-12">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" id="notes" class="form-control" rows="2"></textarea>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Status</label>
                            <select name="is_active" id="isActive" class="form-select">
                                <option value="1">Active</option>
                                <option value="0">Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-soft" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-theme" id="saveSupplier">
                        <i class="fa-solid fa-floppy-disk me-1"></i>Save Supplier
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="supplierViewModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title mb-0">Supplier Details</h5>
                    <div class="small text-muted" id="viewSupplierCode"></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body" id="supplierViewBody"></div>

            <div class="modal-footer">
                <button type="button" class="btn-soft" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<?php include('includes/script.php'); ?>
<script src="assets/js/script.js"></script>
<script>
(() => {
    'use strict';

    const apiUrl = 'api/suppliers.php';
    const csrfToken = <?= json_encode($csrfToken) ?>;
    const canUpdate = <?= $canUpdate ? 'true' : 'false' ?>;
    const canDelete = <?= $canDelete ? 'true' : 'false' ?>;
    const canValue = <?= $canValue ? 'true' : 'false' ?>;

    const body = document.getElementById('supplierBody');
    const loading = document.getElementById('loadingState');
    const tableWrap = document.getElementById('tableWrap');
    const empty = document.getElementById('emptyState');
    const pagination = document.getElementById('pagination');
    const formModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('supplierFormModal'));
    const viewModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('supplierViewModal'));

    let currentPage = 1;

    function esc(value) {
        return String(value ?? '').replace(/[&<>'"]/g, character => ({
            '&':'&amp;',
            '<':'&lt;',
            '>':'&gt;',
            "'":'&#039;',
            '"':'&quot;'
        }[character]));
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
        }, 3200);
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
                'Supplier API returned invalid output' +
                (clean ? ': ' + clean.substring(0, 240) : '.')
            );
        }

        if (!response.ok || !result.success) {
            throw new Error(result.message || 'Request failed.');
        }

        return result;
    }

    function initials(name) {
        return String(name || 'S')
            .split(/\s+/)
            .filter(Boolean)
            .slice(0, 2)
            .map(part => part.charAt(0).toUpperCase())
            .join('');
    }

    async function loadSuppliers(page = 1) {
        currentPage = page;
        loading.classList.remove('d-none');
        tableWrap.classList.add('d-none');
        empty.classList.add('d-none');

        try {
            const result = await request({
                action:'list',
                page,
                per_page:10,
                status:document.getElementById('statusFilter').value,
                supplier_type:document.getElementById('typeFilter').value,
                search:document.getElementById('searchInput').value.trim()
            });

            body.innerHTML = result.suppliers.map(row => `
                <tr>
                    <td data-label="Supplier">
                        <div class="d-flex align-items-center gap-2">
                            <div class="supplier-avatar">${esc(initials(row.supplier_name))}</div>
                            <div>
                                <div class="supplier-name">${esc(row.supplier_name)}</div>
                                <div class="supplier-code">${esc(row.supplier_code)}</div>
                            </div>
                        </div>
                    </td>
                    <td data-label="Type">${esc(row.supplier_type || 'General')}</td>
                    <td data-label="Contact">
                        <strong>${esc(row.mobile || '-')}</strong>
                        <div class="supplier-code">${esc(row.contact_person || row.email || '')}</div>
                    </td>
                    <td data-label="GSTIN">${esc(row.gstin || '-')}</td>
                    ${canValue ? `
                        <td data-label="Opening Balance" class="text-end">₹${money(row.opening_balance)}</td>
                        <td data-label="Credit Limit" class="text-end">₹${money(row.credit_limit)}</td>
                    ` : ''}
                    <td data-label="Status">
                        <span class="badge-soft ${Number(row.is_active) === 1 ? 'badge-active' : 'badge-inactive'}">
                            ${Number(row.is_active) === 1 ? 'Active' : 'Inactive'}
                        </span>
                    </td>
                    <td data-label="Actions" class="text-end">
                        <div class="action-group">
                            <button type="button" class="action-btn view-supplier" data-id="${row.id}" title="View">
                                <i class="fa-regular fa-eye"></i>
                            </button>
                            ${canUpdate ? `
                                <button type="button" class="action-btn edit-supplier" data-id="${row.id}" title="Edit">
                                    <i class="fa-regular fa-pen-to-square"></i>
                                </button>
                                <button type="button" class="action-btn toggle-supplier" data-id="${row.id}" title="Change status">
                                    <i class="fa-solid fa-power-off"></i>
                                </button>
                            ` : ''}
                            ${canDelete ? `
                                <button type="button" class="action-btn delete-supplier" data-id="${row.id}" data-name="${esc(row.supplier_name)}" title="Delete">
                                    <i class="fa-regular fa-trash-can"></i>
                                </button>
                            ` : ''}
                        </div>
                    </td>
                </tr>
            `).join('');

            document.getElementById('statTotal').textContent = result.stats.total_suppliers;
            document.getElementById('statActive').textContent = result.stats.active_suppliers;
            document.getElementById('statOpening').textContent = '₹' + money(result.stats.opening_balance);
            document.getElementById('statCredit').textContent = '₹' + money(result.stats.credit_limit);

            document.getElementById('pageSummary').textContent =
                `Showing ${result.meta.from}-${result.meta.to} of ${result.meta.total}`;

            pagination.innerHTML = '';

            for (let pageNo = 1; pageNo <= result.meta.total_pages; pageNo++) {
                pagination.insertAdjacentHTML(
                    'beforeend',
                    `<li class="page-item ${pageNo === result.meta.page ? 'active' : ''}">
                        <button type="button" class="page-link page-go" data-page="${pageNo}">${pageNo}</button>
                    </li>`
                );
            }

            tableWrap.classList.toggle('d-none', result.suppliers.length === 0);
            empty.classList.toggle('d-none', result.suppliers.length !== 0);
        } catch (error) {
            toast('error', error.message);
            empty.classList.remove('d-none');
        } finally {
            loading.classList.add('d-none');
        }
    }

    function resetSupplierForm() {
        const form = document.getElementById('supplierForm');
        form.reset();

        document.getElementById('supplierId').value = '0';
        document.getElementById('supplierFormTitle').textContent = 'Add Supplier';
        document.getElementById('supplierType').value = 'General';
        document.getElementById('openingBalance').value = '0';
        document.getElementById('creditLimit').value = '0';
        document.getElementById('creditDays').value = '0';
        document.getElementById('isActive').value = '1';
    }

    function fillSupplierForm(row) {
        document.getElementById('supplierId').value = row.id || 0;
        document.getElementById('supplierCode').value = row.supplier_code || '';
        document.getElementById('supplierName').value = row.supplier_name || '';
        document.getElementById('supplierType').value = row.supplier_type || 'General';
        document.getElementById('contactPerson').value = row.contact_person || '';
        document.getElementById('mobile').value = row.mobile || '';
        document.getElementById('alternateMobile').value = row.alternate_mobile || '';
        document.getElementById('email').value = row.email || '';
        document.getElementById('gstin').value = row.gstin || '';
        document.getElementById('panNo').value = row.pan_no || '';
        document.getElementById('openingBalance').value = row.opening_balance || 0;
        document.getElementById('creditLimit').value = row.credit_limit || 0;
        document.getElementById('creditDays').value = row.credit_days || 0;
        document.getElementById('addressLine1').value = row.address_line1 || '';
        document.getElementById('city').value = row.city || '';
        document.getElementById('state').value = row.state || '';
        document.getElementById('pincode').value = row.pincode || '';
        document.getElementById('notes').value = row.notes || '';
        document.getElementById('isActive').value = Number(row.is_active) === 1 ? '1' : '0';
        document.getElementById('supplierFormTitle').textContent = 'Edit Supplier';
    }

    async function viewSupplier(id) {
        try {
            const result = await request({action:'view', supplier_id:id});
            const row = result.supplier;

            document.getElementById('viewSupplierCode').textContent = row.supplier_code || '';

            document.getElementById('supplierViewBody').innerHTML = `
                <div class="detail-grid">
                    <div class="detail-box">
                        <div class="detail-label">Supplier Name</div>
                        <div class="detail-value">${esc(row.supplier_name)}</div>
                    </div>
                    <div class="detail-box">
                        <div class="detail-label">Supplier Type</div>
                        <div class="detail-value">${esc(row.supplier_type || 'General')}</div>
                    </div>
                    <div class="detail-box">
                        <div class="detail-label">Status</div>
                        <div class="detail-value">${Number(row.is_active) === 1 ? 'Active' : 'Inactive'}</div>
                    </div>
                    <div class="detail-box">
                        <div class="detail-label">Contact Person</div>
                        <div class="detail-value">${esc(row.contact_person || '-')}</div>
                    </div>
                    <div class="detail-box">
                        <div class="detail-label">Mobile</div>
                        <div class="detail-value">${esc(row.mobile || '-')}</div>
                    </div>
                    <div class="detail-box">
                        <div class="detail-label">Alternate Mobile</div>
                        <div class="detail-value">${esc(row.alternate_mobile || '-')}</div>
                    </div>
                    <div class="detail-box">
                        <div class="detail-label">Email</div>
                        <div class="detail-value">${esc(row.email || '-')}</div>
                    </div>
                    <div class="detail-box">
                        <div class="detail-label">GSTIN</div>
                        <div class="detail-value">${esc(row.gstin || '-')}</div>
                    </div>
                    <div class="detail-box">
                        <div class="detail-label">PAN</div>
                        <div class="detail-value">${esc(row.pan_no || '-')}</div>
                    </div>
                    <div class="detail-box">
                        <div class="detail-label">Opening Balance</div>
                        <div class="detail-value">₹${money(row.opening_balance)}</div>
                    </div>
                    <div class="detail-box">
                        <div class="detail-label">Credit Limit</div>
                        <div class="detail-value">₹${money(row.credit_limit)}</div>
                    </div>
                    <div class="detail-box">
                        <div class="detail-label">Credit Days</div>
                        <div class="detail-value">${esc(row.credit_days || 0)} day(s)</div>
                    </div>
                    <div class="detail-box" style="grid-column:1/-1">
                        <div class="detail-label">Address</div>
                        <div class="detail-value">
                            ${esc([row.address_line1,row.city,row.state,row.pincode].filter(Boolean).join(', ') || '-')}
                        </div>
                    </div>
                    <div class="detail-box" style="grid-column:1/-1">
                        <div class="detail-label">Notes</div>
                        <div class="detail-value">${esc(row.notes || '-')}</div>
                    </div>
                </div>
            `;

            viewModal.show();
        } catch (error) {
            toast('error', error.message);
        }
    }

    async function editSupplier(id) {
        try {
            const result = await request({action:'view', supplier_id:id});
            resetSupplierForm();
            fillSupplierForm(result.supplier);
            formModal.show();
        } catch (error) {
            toast('error', error.message);
        }
    }

    async function toggleSupplier(id) {
        try {
            const result = await request({action:'toggle', supplier_id:id});
            toast('success', result.message);
            loadSuppliers(currentPage);
        } catch (error) {
            toast('error', error.message);
        }
    }

    async function deleteSupplier(id, name) {
        if (!confirm('Delete supplier "' + name + '"?')) {
            return;
        }

        try {
            const result = await request({action:'delete', supplier_id:id});
            toast('success', result.message);
            loadSuppliers(currentPage);
        } catch (error) {
            toast('error', error.message);
        }
    }

    document.getElementById('addSupplier')?.addEventListener('click', () => {
        resetSupplierForm();
        formModal.show();
    });

    document.getElementById('supplierForm').addEventListener('submit', async event => {
        event.preventDefault();

        const button = document.getElementById('saveSupplier');
        const oldHtml = button.innerHTML;

        button.disabled = true;
        button.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Saving...';

        try {
            const response = await fetch(apiUrl, {
                method:'POST',
                body:new FormData(event.currentTarget),
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
                throw new Error('Supplier API returned an invalid response.');
            }

            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Unable to save supplier.');
            }

            formModal.hide();
            toast('success', result.message);
            loadSuppliers(currentPage);
        } catch (error) {
            toast('error', error.message);
        } finally {
            button.disabled = false;
            button.innerHTML = oldHtml;
        }
    });

    document.getElementById('filterForm').addEventListener('submit', event => {
        event.preventDefault();
        loadSuppliers(1);
    });

    document.getElementById('resetFilter').addEventListener('click', () => {
        document.getElementById('statusFilter').value = '';
        document.getElementById('typeFilter').value = '';
        document.getElementById('searchInput').value = '';
        loadSuppliers(1);
    });

    document.addEventListener('click', event => {
        const pageButton = event.target.closest('.page-go');
        if (pageButton) {
            loadSuppliers(Number(pageButton.dataset.page));
            return;
        }

        const viewButton = event.target.closest('.view-supplier');
        if (viewButton) {
            viewSupplier(Number(viewButton.dataset.id));
            return;
        }

        const editButton = event.target.closest('.edit-supplier');
        if (editButton) {
            editSupplier(Number(editButton.dataset.id));
            return;
        }

        const toggleButton = event.target.closest('.toggle-supplier');
        if (toggleButton) {
            toggleSupplier(Number(toggleButton.dataset.id));
            return;
        }

        const deleteButton = event.target.closest('.delete-supplier');
        if (deleteButton) {
            deleteSupplier(Number(deleteButton.dataset.id), deleteButton.dataset.name || 'Supplier');
        }
    });

    loadSuppliers();
})();
</script>
</body>
</html>