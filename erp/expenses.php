<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));

$configCandidates = [
    __DIR__ . '/config/config.php',
    __DIR__ . '/config.php',
    __DIR__ . '/includes/config.php',
    __DIR__ . '/super-admin/includes/config.php',
];

$configLoaded = false;
foreach ($configCandidates as $configFile) {
    if (is_file($configFile)) {
        require_once $configFile;
        $configLoaded = true;
        break;
    }
}

if (!$configLoaded || !isset($conn) || !($conn instanceof mysqli)) {
    die('Database configuration is not available.');
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn->set_charset('utf8mb4');

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('tableExists')) {
    function tableExists(mysqli $conn, string $table): bool
    {
        $table = $conn->real_escape_string($table);
        $result = $conn->query("SHOW TABLES LIKE '{$table}'");
        return $result && $result->num_rows > 0;
    }
}

if (!function_exists('canAccessExpenses')) {
    function canAccessExpenses(mysqli $conn, string $action = 'open'): bool
    {
        if (($_SESSION['user_type'] ?? '') === 'Platform Admin') {
            return true;
        }

        $fieldMap = [
            'open' => 'can_open',
            'view' => 'can_view',
            'create' => 'can_create',
            'delete' => 'can_delete',
        ];

        $field = $fieldMap[$action] ?? 'can_open';

        $permissionCodes = [
            'perm.expenses',
            'perm.accounts.expenses',
            'perm.accounts',
        ];

        $sessionPermissions = $_SESSION['permissions'] ?? [];

        foreach ($permissionCodes as $code) {
            if (isset($sessionPermissions[$code][$field])) {
                return (int)$sessionPermissions[$code][$field] === 1;
            }
        }

        $allowedRoles = [
            'super admin',
            'super_admin',
            'admin',
            'manager',
            'billing',
            'accounts',
            'accountant',
        ];

        $roleName = strtolower(trim((string)($_SESSION['role_name'] ?? '')));
        $roleCode = strtolower(trim((string)($_SESSION['role_code'] ?? '')));

        if (
            in_array($roleName, $allowedRoles, true) ||
            in_array($roleCode, $allowedRoles, true)
        ) {
            return true;
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);
        $businessId = (int)($_SESSION['business_id'] ?? 0);
        $roleId = (int)($_SESSION['role_id'] ?? 0);

        /*
         * Fallback 1: resolve role directly from users and roles.
         * This fixes sessions where role_name/role_code were not stored.
         */
        if (
            $userId > 0 &&
            tableExists($conn, 'users') &&
            tableExists($conn, 'roles')
        ) {
            $stmt = $conn->prepare(
                "SELECT LOWER(TRIM(r.role_name)) AS role_name
                 FROM users u
                 INNER JOIN roles r ON r.id = u.role_id
                 WHERE u.id = ?
                 LIMIT 1"
            );

            if ($stmt) {
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (
                    isset($row['role_name']) &&
                    in_array((string)$row['role_name'], $allowedRoles, true)
                ) {
                    return true;
                }
            }
        }

        /*
         * Fallback 2: check role_permissions when available.
         */
        if (
            $businessId > 0 &&
            $roleId > 0 &&
            tableExists($conn, 'permissions') &&
            tableExists($conn, 'role_permissions')
        ) {
            $placeholders = implode(
                ',',
                array_fill(0, count($permissionCodes), '?')
            );

            $sql = "SELECT MAX(COALESCE(rp.`{$field}`,0)) AS allowed
                    FROM role_permissions rp
                    INNER JOIN permissions p ON p.id = rp.permission_id
                    WHERE rp.business_id = ?
                      AND rp.role_id = ?
                      AND p.is_active = 1
                      AND p.permission_code IN ({$placeholders})";

            $stmt = $conn->prepare($sql);

            if ($stmt) {
                $types = 'ii' . str_repeat('s', count($permissionCodes));
                $params = array_merge(
                    [$businessId, $roleId],
                    $permissionCodes
                );

                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                return (int)($row['allowed'] ?? 0) === 1;
            }
        }

        return false;
    }
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? $_SESSION['default_branch_id'] ?? 0);

if ($userId <= 0) {
    header('Location: login.php');
    exit;
}

if ($businessId <= 0 || $branchId <= 0) {
    http_response_code(403);
    die('A valid business and branch must be selected.');
}

if (!canAccessExpenses($conn, 'open')) {
    http_response_code(403);
    die('Access denied. You do not have permission to open Expenses.');
}

if (!tableExists($conn, 'expenses')) {
    die('Required table `expenses` was not found.');
}

if (empty($_SESSION['expenses_csrf'])) {
    $_SESSION['expenses_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['expenses_csrf'];

$pageTitle = 'Expenses';
$page_title = 'Expenses';
$currentPage = 'expenses';

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
    'border_radius_px' => 12,
];

if (tableExists($conn, 'business_theme_settings')) {
    $stmt = $conn->prepare(
        'SELECT * FROM business_theme_settings WHERE business_id = ? LIMIT 1'
    );

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
}

$businessName = (string)($_SESSION['business_name'] ?? 'ERP');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo h($businessName); ?> - Expenses</title>
<?php include 'includes/links.php'; ?>
<style>
:root{
    --primary:<?php echo h($theme['primary_color']); ?>;
    --primary-dark:<?php echo h($theme['primary_dark_color']); ?>;
    --primary-soft:<?php echo h($theme['primary_soft_color']); ?>;
    --page-bg:<?php echo h($theme['page_background']); ?>;
    --card-bg:<?php echo h($theme['card_background']); ?>;
    --text-color:<?php echo h($theme['text_color']); ?>;
    --muted-color:<?php echo h($theme['muted_text_color']); ?>;
    --border-color:<?php echo h($theme['border_color']); ?>;
    --radius:<?php echo (int)$theme['border_radius_px']; ?>px;
}
body{background:var(--page-bg);color:var(--text-color);font-family:<?php echo json_encode((string)$theme['font_family']); ?>,sans-serif}
.sidebar{background:linear-gradient(180deg,<?php echo h($theme['sidebar_gradient_1']); ?>,<?php echo h($theme['sidebar_gradient_2']); ?>,<?php echo h($theme['sidebar_gradient_3']); ?>)!important}
.page-heading{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:10px}
.page-title{font-family:<?php echo json_encode((string)$theme['heading_font_family']); ?>,serif;font-size:18px;font-weight:800;margin:0}
.page-subtitle{font-size:10px;color:var(--muted-color);margin-top:2px}
.stat-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin-bottom:10px}
.stat-card{background:var(--card-bg);border:1px solid var(--border-color);border-radius:var(--radius);padding:12px 14px;display:flex;align-items:center;gap:12px;min-height:82px}
.stat-icon{width:42px;height:42px;display:grid;place-items:center;border-radius:10px;background:var(--primary-soft);color:var(--primary-dark);font-size:16px}
.stat-label{font-size:10px;color:var(--muted-color)}.stat-value{font-size:21px;font-weight:800;margin-top:4px}
.panel{background:var(--card-bg);border:1px solid var(--border-color);border-radius:var(--radius);overflow:hidden;margin-bottom:10px}
.panel-head{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:11px 13px;border-bottom:1px solid var(--border-color)}
.panel-title{font-size:12px;font-weight:800}.panel-subtitle{font-size:9px;color:var(--muted-color);margin-top:2px}.panel-body{padding:13px}
.form-grid{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:10px}.span-2{grid-column:span 2}.span-3{grid-column:span 3}.span-4{grid-column:span 4}.span-6{grid-column:span 6}.span-12{grid-column:span 12}
.field-label{display:block;font-size:9px;font-weight:700;text-transform:uppercase;color:var(--muted-color);margin-bottom:4px}
.form-control,.form-select{min-height:36px;font-size:10px;border-radius:9px;border-color:var(--border-color);background:var(--card-bg);color:var(--text-color)}
.category-combobox{position:relative}
.category-combobox-input{padding-right:38px}
.category-combobox-toggle{position:absolute;right:5px;top:5px;width:27px;height:27px;border:0;border-radius:7px;background:var(--primary-soft);color:var(--primary-dark);display:grid;place-items:center;font-size:10px;cursor:pointer}
.category-dropdown{position:absolute;left:0;right:0;top:calc(100% + 5px);z-index:1050;background:var(--card-bg);border:1px solid var(--border-color);border-radius:10px;box-shadow:0 12px 30px rgba(0,0,0,.14);max-height:220px;overflow:auto;display:none}
.category-dropdown.show{display:block}
.category-option{width:100%;border:0;background:transparent;color:var(--text-color);text-align:left;padding:9px 11px;font-size:10px;display:flex;justify-content:space-between;gap:8px}
.category-option:hover,.category-option.active{background:var(--primary-soft);color:var(--primary-dark)}
.category-add-option{border-top:1px solid var(--border-color);font-weight:700;color:var(--primary-dark)}
.category-help{font-size:8px;color:var(--muted-color);margin-top:4px}

.btn-theme{background:linear-gradient(135deg,var(--primary),var(--primary-dark));border:0;color:#fff;border-radius:9px;font-size:10px;font-weight:700;min-height:36px;padding:8px 13px}
.btn-light-custom{border:1px solid var(--border-color);background:var(--card-bg);color:var(--text-color);border-radius:9px;font-size:10px;font-weight:700;min-height:36px;padding:8px 12px}
.btn-danger-custom{border:0;background:#c0392b;color:#fff;border-radius:8px;font-size:9px;font-weight:700;padding:6px 9px}
.compact-table{margin:0;font-size:10px}.compact-table th{font-size:9px;text-transform:uppercase;color:var(--muted-color);background:color-mix(in srgb,var(--muted-color) 6%,transparent);padding:10px 11px;white-space:nowrap;border-color:var(--border-color)}.compact-table td{padding:10px 11px;vertical-align:middle;background:var(--card-bg)!important;color:var(--text-color);border-color:var(--border-color)}
.empty-state{padding:42px 20px;text-align:center;color:var(--muted-color)}
.theme-toast{position:fixed;right:18px;top:78px;z-index:20000;display:flex;align-items:center;gap:9px;min-width:260px;max-width:420px;padding:11px 14px;border-radius:10px;color:#fff;font-size:11px;font-weight:600;box-shadow:0 14px 35px rgba(0,0,0,.22);opacity:0;transform:translateY(-10px);transition:.22s}.theme-toast.show{opacity:1;transform:translateY(0)}.theme-toast-success{background:#168449}.theme-toast-error{background:#c0392b}
body.dark-mode,body[data-theme="dark"],html.dark-mode body,html[data-theme="dark"] body{--page-bg:#0f151b;--card-bg:#182129;--text-color:#f3f6f8;--muted-color:#9aa7b3;--border-color:#2c3944}
@media(max-width:991px){.form-grid{grid-template-columns:repeat(2,1fr)}.span-2,.span-3,.span-4,.span-6,.span-12{grid-column:span 1}.responsive-table thead{display:none}.responsive-table tbody{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;padding:10px}.responsive-table tbody tr{display:grid;grid-template-columns:1fr 1fr;border:1px solid var(--border-color);border-radius:var(--radius);padding:12px}.responsive-table tbody td{display:flex;justify-content:space-between;gap:10px;padding:8px 0;border:0;border-bottom:1px dashed var(--border-color);text-align:right}.responsive-table tbody td::before{content:attr(data-label);font-size:8px;font-weight:700;text-transform:uppercase;color:var(--muted-color)}}
@media(max-width:767px){.stat-grid,.form-grid{grid-template-columns:1fr}.span-2,.span-3,.span-4,.span-6,.span-12{grid-column:1}.page-heading,.panel-head{align-items:flex-start;flex-direction:column}.responsive-table tbody{grid-template-columns:1fr}.responsive-table tbody tr{grid-template-columns:1fr}.responsive-table tbody td{grid-column:1/-1}}
</style>
</head>
<body>
<?php include 'includes/sidebar.php'; ?>
<main class="app-main">
<?php include 'includes/nav.php'; ?>
<div class="content-wrap">
    <div class="page-heading">
        <div>
            <h1 class="page-title">Expenses</h1>
            <div class="page-subtitle"><?php echo h($businessName); ?> · Record and manage business expenses</div>
        </div>
    </div>

    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-icon"><i class="fa-solid fa-receipt"></i></div>
            <div><div class="stat-label">Total Expense Entries</div><div class="stat-value" id="totalExpenseCount">0</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fa-solid fa-indian-rupee-sign"></i></div>
            <div><div class="stat-label">Total Expense Amount</div><div class="stat-value" id="totalExpenseAmount">₹0.00</div></div>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head">
            <div>
                <div class="panel-title">Add Expense</div>
                <div class="panel-subtitle">Enter the expense details and save.</div>
            </div>
        </div>
        <div class="panel-body">
            <form id="expenseForm" class="form-grid">
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
                <div class="span-2">
                    <label class="field-label">Expense Date</label>
                    <input type="date" name="expense_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="span-3">
                    <label class="field-label">Category</label>
                    <div class="category-combobox" id="expenseCategoryCombobox">
                        <input type="hidden" name="expense_category_id" id="expense_category_id" value="">
                        <input
                            type="text"
                            name="expense_category_name"
                            id="expense_category_name"
                            class="form-control category-combobox-input"
                            placeholder="Type or select category"
                            autocomplete="off"
                            required
                        >
                        <button type="button" class="category-combobox-toggle" id="categoryToggle" aria-label="Show categories">
                            <i class="fa-solid fa-chevron-down"></i>
                        </button>
                        <div class="category-dropdown" id="categoryDropdown"></div>
                    </div>
                    <div class="category-help">Type an existing category or add a new category.</div>
                </div>
                <div class="span-3">
                    <label class="field-label">Description</label>
                    <input type="text" name="description" class="form-control" maxlength="500" required>
                </div>
                <div class="span-2">
                    <label class="field-label">Amount</label>
                    <input type="number" name="amount" class="form-control" min="0.01" step="0.01" required>
                </div>
                <div class="span-2">
                    <label class="field-label">Payment Method</label>
                    <select name="payment_method_id" id="payment_method_id" class="form-select">
                        <option value="">Loading methods...</option>
                    </select>
                </div>
                <div class="span-3">
                    <label class="field-label">Reference No</label>
                    <input type="text" name="reference_no" class="form-control" maxlength="150">
                </div>
                <div class="span-3 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-theme" id="saveExpenseButton">
                        <i class="fa-solid fa-floppy-disk me-1"></i>Save Expense
                    </button>
                    <button type="reset" class="btn btn-light-custom">Reset</button>
                </div>
            </form>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head">
            <div>
                <div class="panel-title">Expense Filters</div>
                <div class="panel-subtitle">Search by category, description, reference, or payment method.</div>
            </div>
            <button type="button" class="btn btn-light-custom" id="refreshList"><i class="fa-solid fa-rotate"></i></button>
        </div>
        <div class="panel-body">
            <form id="filterForm" class="form-grid">
                <div class="span-4">
                    <label class="field-label">Search</label>
                    <input type="search" name="search" class="form-control" placeholder="Category, description, reference...">
                </div>
                <div class="span-3">
                    <label class="field-label">Category</label>
                    <select name="category" id="categoryFilter" class="form-select">
                        <option value="">All Categories</option>
                    </select>
                </div>
                <div class="span-2">
                    <label class="field-label">From Date</label>
                    <input type="date" name="from_date" class="form-control">
                </div>
                <div class="span-2">
                    <label class="field-label">To Date</label>
                    <input type="date" name="to_date" class="form-control">
                </div>
                <div class="span-1 d-flex align-items-end">
                    <button type="submit" class="btn btn-theme w-100">Search</button>
                </div>
                <div class="span-12">
                    <button type="button" class="btn btn-light-custom" id="resetFilters">Reset Filters</button>
                </div>
            </form>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head">
            <div>
                <div class="panel-title">Expense List</div>
                <div class="panel-subtitle">All matching expense records.</div>
            </div>
        </div>
        <div id="loadingState" class="empty-state">
            <i class="fa-solid fa-spinner fa-spin mb-2"></i>
            <div>Loading expenses...</div>
        </div>
        <div id="tableWrap" class="table-responsive d-none">
            <table class="table compact-table responsive-table">
                <thead>
                    <tr>
                        <th>#</th><th>Date</th><th>Category</th><th>Description</th>
                        <th>Method</th><th>Reference</th><th>Amount</th><th>Created</th><th>Action</th>
                    </tr>
                </thead>
                <tbody id="expenseTableBody"></tbody>
            </table>
        </div>
        <div id="emptyState" class="empty-state d-none">No expenses found.</div>
    </div>

    <?php include 'includes/footer.php'; ?>
</div>
</main>

<?php include 'includes/script.php'; ?>
<script src="assets/js/script.js"></script>
<script>
(function(){
    'use strict';

    const expenseForm=document.getElementById('expenseForm');
    const filterForm=document.getElementById('filterForm');
    const paymentMethodSelect=document.getElementById('payment_method_id');
    const expenseCategoryId=document.getElementById('expense_category_id');
    const expenseCategoryName=document.getElementById('expense_category_name');
    const categoryDropdown=document.getElementById('categoryDropdown');
    const categoryToggle=document.getElementById('categoryToggle');
    const categoryFilter=document.getElementById('categoryFilter');
    let expenseCategories=[];
    const tableBody=document.getElementById('expenseTableBody');
    const tableWrap=document.getElementById('tableWrap');
    const loading=document.getElementById('loadingState');
    const empty=document.getElementById('emptyState');
    const saveButton=document.getElementById('saveExpenseButton');

    function toast(type,message){
        const element=document.createElement('div');
        element.className='theme-toast theme-toast-'+type;
        element.innerHTML='<i class="fa-solid '+(type==='success'?'fa-circle-check':'fa-circle-exclamation')+'"></i><span></span>';
        element.querySelector('span').textContent=message;
        document.body.appendChild(element);
        requestAnimationFrame(()=>element.classList.add('show'));
        setTimeout(()=>{element.classList.remove('show');setTimeout(()=>element.remove(),250)},3300);
    }

    function escapeHtml(value){
        const div=document.createElement('div');
        div.textContent=value??'';
        return div.innerHTML;
    }

    function money(value){
        return Number(value||0).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2});
    }

    async function requestJson(url,options={}){
        const response=await fetch(url,{
            credentials:'same-origin',
            headers:{
                'Accept':'application/json',
                'X-Requested-With':'XMLHttpRequest',
                ...(options.headers||{})
            },
            ...options
        });

        const raw=await response.text();
        let result;

        try{
            result=JSON.parse(raw);
        }catch(error){
            throw new Error(raw?'Server returned invalid output: '+raw.substring(0,180):'Server returned an empty response.');
        }

        if(!response.ok||!result.success){
            throw new Error(result.message||'Request failed.');
        }

        return result;
    }

    function renderBootstrap(result){
        const methods=Array.isArray(result.payment_methods)?result.payment_methods:[];
        paymentMethodSelect.innerHTML='<option value="">Select Method</option>'+methods.map(row=>
            `<option value="${Number(row.id)}">${escapeHtml(row.method_name)}</option>`
        ).join('');

        expenseCategories=Array.isArray(result.categories)?result.categories:[];

        categoryFilter.innerHTML=
            '<option value="">All Categories</option>'+
            expenseCategories.map(category=>
                `<option value="${Number(category.id)}">${escapeHtml(category.category_name)}</option>`
            ).join('');

        categoryDropdown.innerHTML='';
        closeCategoryDropdown();
        saveButton.disabled=false;
    }


    function normalizeText(value){
        return String(value||'').trim().toLowerCase();
    }

    function closeCategoryDropdown(){
        categoryDropdown.classList.remove('show');
    }

    function selectExpenseCategory(category){
        expenseCategoryId.value=String(category.id);
        expenseCategoryName.value=String(category.category_name||'');
        categoryDropdown.innerHTML='';
        closeCategoryDropdown();
        expenseCategoryName.blur();
    }

    function renderCategoryDropdown(query){
        const cleanQuery=String(query||'').trim();
        const normalized=normalizeText(cleanQuery);

        const matches=expenseCategories.filter(category=>
            normalizeText(category.category_name).includes(normalized) ||
            normalizeText(category.category_code).includes(normalized)
        );

        let html=matches.map(category=>`
            <button type="button" class="category-option" data-category-id="${Number(category.id)}">
                <span>${escapeHtml(category.category_name)}</span>
                <small>${escapeHtml(category.category_code||'')}</small>
            </button>
        `).join('');

        const exact=expenseCategories.some(category=>
            normalizeText(category.category_name)===normalized
        );

        if(normalized && !exact){
            html+=`
                <button type="button" class="category-option category-add-option" data-add-category="${escapeHtml(cleanQuery)}">
                    <span><i class="fa-solid fa-plus me-1"></i>Add “${escapeHtml(cleanQuery)}”</span>
                </button>
            `;
        }

        if(!html){
            html=normalized
                ? `<button type="button" class="category-option category-add-option" data-add-category="${escapeHtml(cleanQuery)}">
                       <span><i class="fa-solid fa-plus me-1"></i>Create “${escapeHtml(cleanQuery)}”</span>
                   </button>`
                : '<div class="category-option">Type a category name to create it.</div>';
        }

        categoryDropdown.innerHTML=html;
        categoryDropdown.classList.add('show');
    }

    async function addExpenseCategory(categoryName){
        const clean=String(categoryName||'').trim();

        if(!clean){
            toast('error','Enter a category name.');
            return;
        }

        const data=new FormData();
        data.set('action','create_category');
        data.set('csrf_token','<?php echo h($csrfToken); ?>');
        data.set('category_name',clean);

        try{
            const result=await requestJson('api/expenses.php',{
                method:'POST',
                body:data
            });

            const category=result.category;

            const existingIndex=expenseCategories.findIndex(item=>
                Number(item.id)===Number(category.id)
            );

            if(existingIndex>=0){
                expenseCategories[existingIndex]=category;
            }else{
                expenseCategories.push(category);
            }

            expenseCategories.sort((a,b)=>
                a.category_name.localeCompare(b.category_name)
            );

            selectExpenseCategory(category);

            categoryFilter.innerHTML=
                '<option value="">All Categories</option>'+
                expenseCategories.map(item=>
                    `<option value="${Number(item.id)}">${escapeHtml(item.category_name)}</option>`
                ).join('');

            toast('success',result.message);
        }catch(error){
            toast('error',error.message);
        }
    }

    expenseCategoryName.addEventListener('input',()=>{
        const typed=expenseCategoryName.value.trim();

        const exact=expenseCategories.find(category=>
            normalizeText(category.category_name)===normalizeText(typed)
        );

        /*
         * Never retain the ID of a previously selected category after the
         * visible text has changed.
         */
        expenseCategoryId.value=exact?String(exact.id):'';
        renderCategoryDropdown(typed);
    });

    expenseCategoryName.addEventListener('focus',()=>{
        renderCategoryDropdown(expenseCategoryName.value);
    });

    categoryToggle.addEventListener('click',()=>{
        if(categoryDropdown.classList.contains('show')){
            closeCategoryDropdown();
        }else{
            renderCategoryDropdown(expenseCategoryName.value);
            expenseCategoryName.focus();
        }
    });

    categoryDropdown.addEventListener('click',event=>{
        const option=event.target.closest('[data-category-id]');
        if(option){
            const category=expenseCategories.find(item=>Number(item.id)===Number(option.dataset.categoryId));
            if(category)selectExpenseCategory(category);
            return;
        }

        const addButton=event.target.closest('[data-add-category]');
        if(addButton){
            addExpenseCategory(addButton.dataset.addCategory);
        }
    });

    document.addEventListener('click',event=>{
        if(!document.getElementById('expenseCategoryCombobox').contains(event.target)){
            closeCategoryDropdown();
        }
    });

    expenseForm.addEventListener('reset',()=>{
        setTimeout(()=>{
            expenseCategoryId.value='';
            expenseCategoryName.value='';
            categoryDropdown.innerHTML='';
            closeCategoryDropdown();
        },0);
    });

    function renderList(result){
        loading.classList.add('d-none');

        document.getElementById('totalExpenseCount').textContent=Number(result.total_count||0);
        document.getElementById('totalExpenseAmount').textContent='₹'+money(result.total_amount);

        const rows=Array.isArray(result.rows)?result.rows:[];

        if(!rows.length){
            tableWrap.classList.add('d-none');
            empty.classList.remove('d-none');
            tableBody.innerHTML='';
            return;
        }

        empty.classList.add('d-none');
        tableWrap.classList.remove('d-none');

        tableBody.innerHTML=rows.map((row,index)=>`<tr>
            <td data-label="#">${index+1}</td>
            <td data-label="Date">${escapeHtml(row.expense_date_display)}</td>
            <td data-label="Category"><strong>${escapeHtml(row.expense_category)}</strong></td>
            <td data-label="Description">${escapeHtml(row.description)}</td>
            <td data-label="Method">${escapeHtml(row.method_name||'—')}</td>
            <td data-label="Reference">${escapeHtml(row.reference_no||'—')}</td>
            <td data-label="Amount"><strong>₹${money(row.amount)}</strong></td>
            <td data-label="Created">${escapeHtml(row.created_at_display||'—')}</td>
            <td data-label="Action">
                <button type="button" class="btn btn-danger-custom" data-delete="${Number(row.id)}">Delete</button>
            </td>
        </tr>`).join('');
    }

    async function loadBootstrap(){
        try{
            const result=await requestJson('api/expenses.php?action=bootstrap');
            renderBootstrap(result);
        }catch(error){
            toast('error',error.message);
        }
    }

    async function loadExpenses(){
        loading.classList.remove('d-none');
        tableWrap.classList.add('d-none');
        empty.classList.add('d-none');

        try{
            const params=new URLSearchParams(new FormData(filterForm));
            params.set('action','list');
            const result=await requestJson('api/expenses.php?'+params.toString());
            renderList(result);
        }catch(error){
            loading.classList.add('d-none');
            empty.classList.remove('d-none');
            empty.textContent=error.message;
            toast('error',error.message);
        }
    }

    expenseForm.addEventListener('submit',async event=>{
        event.preventDefault();

        const typedCategory=expenseCategoryName.value.trim();

        if(!expenseCategoryId.value && !typedCategory){
            toast('error','Please type or select an expense category.');
            expenseCategoryName.focus();
            return;
        }

        /*
         * When the typed category does not yet exist, the API creates it
         * first and then saves the expense with the returned category ID.
         */

        const original=saveButton.innerHTML;
        saveButton.disabled=true;
        saveButton.innerHTML='<i class="fa-solid fa-spinner fa-spin me-1"></i>Saving...';

        try{
            const result=await requestJson('api/expenses.php',{
                method:'POST',
                body:new FormData(expenseForm)
            });
            toast('success',result.message);

            if(result.category&&Number(result.category.id)>0){
                const savedCategory={
                    id:Number(result.category.id),
                    category_name:String(
                        result.category.category_name||''
                    ),
                    category_code:String(
                        result.category.category_code||''
                    )
                };

                const existingIndex=expenseCategories.findIndex(item=>
                    Number(item.id)===savedCategory.id
                );

                if(existingIndex>=0){
                    expenseCategories[existingIndex]={
                        ...expenseCategories[existingIndex],
                        ...savedCategory
                    };
                }else{
                    expenseCategories.push(savedCategory);
                }

                expenseCategories.sort((a,b)=>
                    a.category_name.localeCompare(b.category_name)
                );
            }

            expenseForm.reset();
            expenseCategoryId.value='';
            expenseCategoryName.value='';
            categoryDropdown.innerHTML='';
            closeCategoryDropdown();
            expenseForm.querySelector('[name="expense_date"]').value='<?php echo date('Y-m-d'); ?>';

            await loadBootstrap();
            await loadExpenses();
        }catch(error){
            toast('error',error.message);
        }finally{
            saveButton.disabled=false;
            saveButton.innerHTML=original;
        }
    });

    tableBody.addEventListener('click',async event=>{
        const button=event.target.closest('[data-delete]');
        if(!button)return;

        if(!confirm('Are you sure you want to delete this expense?'))return;

        const data=new FormData();
        data.set('action','delete');
        data.set('expense_id',button.dataset.delete);
        data.set('csrf_token','<?php echo h($csrfToken); ?>');

        try{
            const result=await requestJson('api/expenses.php',{method:'POST',body:data});
            toast('success',result.message);
            loadBootstrap();
            loadExpenses();
        }catch(error){
            toast('error',error.message);
        }
    });

    filterForm.addEventListener('submit',event=>{event.preventDefault();loadExpenses();});
    document.getElementById('refreshList')?.addEventListener('click',loadExpenses);
    document.getElementById('resetFilters')?.addEventListener('click',()=>{
        filterForm.reset();
        loadExpenses();
    });

    loadBootstrap();
    loadExpenses();
})();
</script>
</body>
</html>
