<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));

$configCandidates = [
    __DIR__ . '/config/config.php',
    __DIR__ . '/config.php',
    __DIR__ . '/includes/config.php',
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
        $safe = $conn->real_escape_string($table);
        $result = $conn->query("SHOW TABLES LIKE '{$safe}'");
        return $result && $result->num_rows > 0;
    }
}

if (!function_exists('hasColumn')) {
    function hasColumn(mysqli $conn, string $table, string $column): bool
    {
        $safeTable = $conn->real_escape_string($table);
        $safeColumn = $conn->real_escape_string($column);
        $result = $conn->query(
            "SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'"
        );
        return $result && $result->num_rows > 0;
    }
}



if (!function_exists('canAccessPaymentMethods')) {
    function canAccessPaymentMethods(mysqli $conn, string $action = 'open'): bool
    {
        if (($_SESSION['user_type'] ?? '') === 'Platform Admin') {
            return true;
        }

        $fieldMap = [
            'open' => 'can_open',
            'view' => 'can_view',
            'create' => 'can_create',
            'edit' => 'can_edit',
            'delete' => 'can_delete',
        ];

        $field = $fieldMap[$action] ?? 'can_open';

        $permissionCodes = [
            'perm.payment_methods',
            'perm.settings.payment_methods',
            'perm.accounts.payment_methods',
            'perm.accounts',
            'perm.settings',
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
            'business admin',
            'business_admin',
            'branch admin',
            'branch_admin',
            'manager',
            'branch manager',
            'branch_manager',
            'accounts',
            'accountant',
            'billing',
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

        if (
            $userId > 0 &&
            tableExists($conn, 'users') &&
            tableExists($conn, 'roles')
        ) {
            $roleIdColumn = 'id';
            $userRoleColumn = 'role_id';
            $userIdColumn = 'id';

            $stmt = $conn->prepare(
                "SELECT
                    LOWER(TRIM(COALESCE(r.role_name, r.role_code, ''))) AS resolved_role
                 FROM users u
                 INNER JOIN roles r ON r.`{$roleIdColumn}` = u.`{$userRoleColumn}`
                 WHERE u.`{$userIdColumn}` = ?
                 LIMIT 1"
            );

            if ($stmt) {
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                $resolvedRole = strtolower(trim((string)($row['resolved_role'] ?? '')));

                if (in_array($resolvedRole, $allowedRoles, true)) {
                    return true;
                }
            }
        }

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
                      AND p.permission_code IN ({$placeholders})";

            if (hasColumn($conn, 'permissions', 'is_active')) {
                $sql .= " AND p.is_active = 1";
            }

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

                if ((int)($row['allowed'] ?? 0) === 1) {
                    return true;
                }
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

if ($businessId <= 0) {
    http_response_code(403);
    die('A valid business must be selected.');
}

if (!tableExists($conn, 'payment_methods')) {
    die('Required table `payment_methods` was not found.');
}

if (!canAccessPaymentMethods($conn, 'open')) {
    http_response_code(403);
    die('Access denied. You do not have permission to manage Payment Methods.');
}

if (empty($_SESSION['payment_methods_csrf'])) {
    $_SESSION['payment_methods_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['payment_methods_csrf'];

$pageTitle = 'Payment Methods';
$page_title = 'Payment Methods';
$currentPage = 'payment-methods';

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

$businessName = (string)($_SESSION['business_name'] ?? 'Footwear ERP');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo h($businessName); ?> - Payment Methods</title>
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
.panel{background:var(--card-bg);border:1px solid var(--border-color);border-radius:var(--radius);overflow:hidden;margin-bottom:10px}
.panel-head{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:11px 13px;border-bottom:1px solid var(--border-color)}
.panel-title{font-size:12px;font-weight:800}.panel-subtitle{font-size:9px;color:var(--muted-color);margin-top:2px}.panel-body{padding:13px}
.form-grid{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:10px}.span-2{grid-column:span 2}.span-3{grid-column:span 3}.span-4{grid-column:span 4}.span-6{grid-column:span 6}
.field-label{display:block;font-size:9px;font-weight:700;text-transform:uppercase;color:var(--muted-color);margin-bottom:4px}
.form-control,.form-select{min-height:36px;font-size:10px;border-radius:9px;border-color:var(--border-color);background:var(--card-bg);color:var(--text-color)}
.form-check-label{font-size:10px}
.btn-theme{background:linear-gradient(135deg,var(--primary),var(--primary-dark));border:0;color:#fff;border-radius:9px;font-size:10px;font-weight:700;min-height:36px;padding:8px 13px}
.btn-light-custom{border:1px solid var(--border-color);background:var(--card-bg);color:var(--text-color);border-radius:9px;font-size:10px;font-weight:700;min-height:36px;padding:8px 12px}
.btn-danger-custom{border:0;background:#c0392b;color:#fff;border-radius:8px;font-size:9px;font-weight:700;padding:6px 9px}
.compact-table{margin:0;font-size:10px}.compact-table th{font-size:9px;text-transform:uppercase;color:var(--muted-color);background:color-mix(in srgb,var(--muted-color) 6%,transparent);padding:10px 11px;white-space:nowrap;border-color:var(--border-color)}.compact-table td{padding:10px 11px;vertical-align:middle;background:var(--card-bg)!important;color:var(--text-color);border-color:var(--border-color)}
.status-badge{display:inline-flex;border-radius:999px;padding:4px 8px;font-size:8px;font-weight:800}.status-active{background:#eaf8f0;color:#168449}.status-inactive{background:#fdecec;color:#c0392b}
.action-wrap{display:flex;gap:6px;justify-content:flex-end;flex-wrap:wrap}
.empty-state{padding:42px 20px;text-align:center;color:var(--muted-color)}
.theme-toast{position:fixed;right:18px;top:78px;z-index:20000;display:flex;align-items:center;gap:9px;min-width:260px;max-width:420px;padding:11px 14px;border-radius:10px;color:#fff;font-size:11px;font-weight:600;box-shadow:0 14px 35px rgba(0,0,0,.22);opacity:0;transform:translateY(-10px);transition:.22s}.theme-toast.show{opacity:1;transform:translateY(0)}.theme-toast-success{background:#168449}.theme-toast-error{background:#c0392b}
body.dark-mode,body[data-theme="dark"],html.dark-mode body,html[data-theme="dark"] body{--page-bg:#0f151b;--card-bg:#182129;--text-color:#f3f6f8;--muted-color:#9aa7b3;--border-color:#2c3944}
@media(max-width:991px){.form-grid{grid-template-columns:repeat(2,1fr)}.span-2,.span-3,.span-4,.span-6{grid-column:span 1}.responsive-table thead{display:none}.responsive-table tbody{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;padding:10px}.responsive-table tbody tr{display:grid;grid-template-columns:1fr 1fr;border:1px solid var(--border-color);border-radius:var(--radius);padding:12px}.responsive-table tbody td{display:flex;justify-content:space-between;gap:10px;padding:8px 0;border:0;border-bottom:1px dashed var(--border-color);text-align:right}.responsive-table tbody td::before{content:attr(data-label);font-size:8px;font-weight:700;text-transform:uppercase;color:var(--muted-color)}}
@media(max-width:767px){.form-grid{grid-template-columns:1fr}.span-2,.span-3,.span-4,.span-6{grid-column:1}.page-heading,.panel-head{align-items:flex-start;flex-direction:column}.responsive-table tbody{grid-template-columns:1fr}.responsive-table tbody tr{grid-template-columns:1fr}.responsive-table tbody td{grid-column:1/-1}.action-wrap{justify-content:flex-start}}
</style>
</head>
<body>
<?php include 'includes/sidebar.php'; ?>
<main class="app-main">
<?php include 'includes/nav.php'; ?>

<div class="content-wrap">
    <div class="page-heading">
        <div>
            <h1 class="page-title">Payment Methods</h1>
            <div class="page-subtitle"><?php echo h($businessName); ?> · Manage accepted payment modes</div>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head">
            <div>
                <div class="panel-title" id="formTitle">Add Payment Method</div>
                <div class="panel-subtitle">Create or update a payment method.</div>
            </div>
        </div>

        <div class="panel-body">
            <form id="methodForm" class="form-grid">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="payment_method_id" id="payment_method_id" value="0">
                <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">

                <div class="span-4">
                    <label class="field-label">Method Name</label>
                    <input
                        type="text"
                        name="payment_method_name"
                        id="payment_method_name"
                        class="form-control"
                        maxlength="100"
                        placeholder="Cash, UPI, Card..."
                        required
                    >
                </div>

                <div class="span-3">
                    <label class="field-label">Method Type</label>
                    <select name="method_type" id="method_type" class="form-select" required>
                        <option value="cash">Cash</option>
                        <option value="upi">UPI</option>
                        <option value="card">Card</option>
                        <option value="cheque">Cheque</option>
                        <option value="credit">Credit</option>
                        <option value="split">Split</option>
                        <option value="other">Other</option>
                    </select>
                </div>

                <div class="span-2 d-flex align-items-end">
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="status" id="status" value="1" checked>
                        <label class="form-check-label" for="status">Active Method</label>
                    </div>
                </div>

                <div class="span-3 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-theme" id="saveButton">
                        <i class="fa-solid fa-floppy-disk me-1"></i>Save
                    </button>
                    <button type="button" class="btn btn-light-custom d-none" id="cancelEdit">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head">
            <div>
                <div class="panel-title">Payment Method List</div>
                <div class="panel-subtitle">Search and maintain available methods.</div>
            </div>
            <button type="button" class="btn btn-light-custom" id="refreshList">
                <i class="fa-solid fa-rotate"></i>
            </button>
        </div>

        <div class="panel-body">
            <form id="filterForm" class="form-grid">
                <div class="span-6">
                    <label class="field-label">Search</label>
                    <input type="search" name="search" id="search" class="form-control" placeholder="Search method name">
                </div>
                <div class="span-3">
                    <label class="field-label">Status</label>
                    <select name="status_filter" id="status_filter" class="form-select">
                        <option value="all">All</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                <div class="span-3 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-theme">Search</button>
                    <button type="button" class="btn btn-light-custom" id="resetFilter">Reset</button>
                </div>
            </form>
        </div>

        <div id="loadingState" class="empty-state">
            <i class="fa-solid fa-spinner fa-spin mb-2"></i>
            <div>Loading payment methods...</div>
        </div>

        <div id="tableWrap" class="table-responsive d-none">
            <table class="table compact-table responsive-table">
                <thead>
                <tr>
                    <th>#</th>
                    <th>Method Name</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
                </thead>
                <tbody id="methodTableBody"></tbody>
            </table>
        </div>

        <div id="emptyState" class="empty-state d-none">No payment methods found.</div>
    </div>

    <?php include 'includes/footer.php'; ?>
</div>
</main>

<?php include 'includes/script.php'; ?>
<script src="assets/js/script.js"></script>
<script>
(function(){
    'use strict';

    const methodForm=document.getElementById('methodForm');
    const filterForm=document.getElementById('filterForm');
    const idInput=document.getElementById('payment_method_id');
    const nameInput=document.getElementById('payment_method_name');
    const typeInput=document.getElementById('method_type');
    const statusInput=document.getElementById('status');
    const saveButton=document.getElementById('saveButton');
    const cancelEdit=document.getElementById('cancelEdit');
    const formTitle=document.getElementById('formTitle');
    const tableBody=document.getElementById('methodTableBody');
    const tableWrap=document.getElementById('tableWrap');
    const loading=document.getElementById('loadingState');
    const empty=document.getElementById('emptyState');

    let methods=[];

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

    function normalizeMethodType(value,methodName=''){
        const raw=String(value||'').trim().toLowerCase();
        const name=String(methodName||'').trim().toLowerCase();

        const aliases={
            'cash':'cash',
            'cash payment':'cash',
            'upi':'upi',
            'gpay':'upi',
            'google pay':'upi',
            'phonepe':'upi',
            'paytm':'upi',
            'qr':'upi',
            'card':'card',
            'credit card':'card',
            'debit card':'card',
            'pos':'card',
            'cheque':'cheque',
            'check':'cheque',
            'credit':'credit',
            'credit sale':'credit',
            'split':'split',
            'split payment':'split',
            'mixed':'split',
            'other':'other'
        };

        if(aliases[raw]){
            return aliases[raw];
        }

        for(const [needle,normalized] of Object.entries(aliases)){
            if(raw && raw.includes(needle)){
                return normalized;
            }
        }

        for(const [needle,normalized] of Object.entries(aliases)){
            if(name && name.includes(needle)){
                return normalized;
            }
        }

        return 'other';
    }

    function methodTypeLabel(value){
        const labels={
            cash:'Cash',
            upi:'UPI',
            card:'Card',
            cheque:'Cheque',
            credit:'Credit',
            split:'Split',
            other:'Other'
        };

        return labels[normalizeMethodType(value)]||'Other';
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

    function resetEditor(){
        methodForm.reset();
        idInput.value='0';
        statusInput.checked=true;
        typeInput.value='cash';
        formTitle.textContent='Add Payment Method';
        saveButton.innerHTML='<i class="fa-solid fa-floppy-disk me-1"></i>Save';
        cancelEdit.classList.add('d-none');
    }

    function editMethod(id){
        const row=methods.find(item=>Number(item.payment_method_id)===Number(id));
        if(!row)return;

        idInput.value=String(row.payment_method_id);
        nameInput.value=row.payment_method_name||'';
        const normalizedType=normalizeMethodType(
            row.method_type,
            row.payment_method_name
        );

        typeInput.value=normalizedType;

        if(typeInput.value!==normalizedType){
            typeInput.value='other';
        }

        statusInput.checked=Number(row.status)===1;
        formTitle.textContent='Edit Payment Method';
        saveButton.innerHTML='<i class="fa-solid fa-pen me-1"></i>Update';
        cancelEdit.classList.remove('d-none');
        nameInput.focus();
        window.scrollTo({top:0,behavior:'smooth'});
    }

    function render(rows){
        loading.classList.add('d-none');

        if(!rows.length){
            tableWrap.classList.add('d-none');
            empty.classList.remove('d-none');
            return;
        }

        empty.classList.add('d-none');
        tableWrap.classList.remove('d-none');

        tableBody.innerHTML=rows.map((row,index)=>`<tr>
            <td data-label="#">${index+1}</td>
            <td data-label="Method Name"><strong>${escapeHtml(row.payment_method_name)}</strong></td>
            <td data-label="Type">${escapeHtml(methodTypeLabel(
                normalizeMethodType(
                    row.method_type,
                    row.payment_method_name
                )
            ))}</td>
            <td data-label="Status">
                <span class="status-badge ${Number(row.status)===1?'status-active':'status-inactive'}">
                    ${Number(row.status)===1?'Active':'Inactive'}
                </span>
            </td>
            <td data-label="Actions">
                <div class="action-wrap">
                    <button type="button" class="btn btn-light-custom" style="min-height:auto;padding:6px 9px" data-edit="${Number(row.payment_method_id)}">Edit</button>
                    <button type="button" class="btn btn-light-custom" style="min-height:auto;padding:6px 9px" data-toggle="${Number(row.payment_method_id)}">
                        ${Number(row.status)===1?'Deactivate':'Activate'}
                    </button>
                    <button type="button" class="btn btn-danger-custom" data-delete="${Number(row.payment_method_id)}">Delete</button>
                </div>
            </td>
        </tr>`).join('');
    }

    async function loadMethods(){
        loading.classList.remove('d-none');
        tableWrap.classList.add('d-none');
        empty.classList.add('d-none');

        try{
            const params=new URLSearchParams(new FormData(filterForm));
            params.set('action','list');
            const result=await requestJson('api/payment-methods.php?'+params.toString());
            methods=(Array.isArray(result.rows)?result.rows:[]).map(row=>({
                ...row,
                method_type:normalizeMethodType(
                    row.method_type,
                    row.payment_method_name
                )
            }));
            render(methods);
        }catch(error){
            loading.classList.add('d-none');
            empty.classList.remove('d-none');
            empty.textContent=error.message;
            toast('error',error.message);
        }
    }

    methodForm.addEventListener('submit',async event=>{
        event.preventDefault();

        const original=saveButton.innerHTML;
        saveButton.disabled=true;
        saveButton.innerHTML='<i class="fa-solid fa-spinner fa-spin me-1"></i>Saving...';

        try{
            const result=await requestJson('api/payment-methods.php',{
                method:'POST',
                body:new FormData(methodForm)
            });
            toast('success',result.message);
            resetEditor();
            loadMethods();
        }catch(error){
            toast('error',error.message);
        }finally{
            saveButton.disabled=false;
            if(idInput.value==='0'){
                saveButton.innerHTML='<i class="fa-solid fa-floppy-disk me-1"></i>Save';
            }else{
                saveButton.innerHTML=original;
            }
        }
    });

    tableBody.addEventListener('click',async event=>{
        const editButton=event.target.closest('[data-edit]');
        const toggleButton=event.target.closest('[data-toggle]');
        const deleteButton=event.target.closest('[data-delete]');

        if(editButton){
            editMethod(editButton.dataset.edit);
            return;
        }

        if(toggleButton){
            const id=Number(toggleButton.dataset.toggle);
            const row=methods.find(item=>Number(item.payment_method_id)===id);
            if(!row)return;

            const label=Number(row.status)===1?'deactivate':'activate';
            if(!confirm('Are you sure you want to '+label+' this payment method?'))return;

            const data=new FormData();
            data.set('action','toggle');
            data.set('payment_method_id',String(id));
            data.set('csrf_token','<?php echo h($csrfToken); ?>');

            try{
                const result=await requestJson('api/payment-methods.php',{method:'POST',body:data});
                toast('success',result.message);
                loadMethods();
            }catch(error){
                toast('error',error.message);
            }
            return;
        }

        if(deleteButton){
            const id=Number(deleteButton.dataset.delete);
            if(!confirm('Are you sure you want to delete this payment method?'))return;

            const data=new FormData();
            data.set('action','delete');
            data.set('payment_method_id',String(id));
            data.set('csrf_token','<?php echo h($csrfToken); ?>');

            try{
                const result=await requestJson('api/payment-methods.php',{method:'POST',body:data});
                toast('success',result.message);
                loadMethods();
            }catch(error){
                toast('error',error.message);
            }
        }
    });

    filterForm.addEventListener('submit',event=>{event.preventDefault();loadMethods();});
    document.getElementById('refreshList')?.addEventListener('click',loadMethods);
    document.getElementById('resetFilter')?.addEventListener('click',()=>{
        filterForm.reset();
        loadMethods();
    });
    cancelEdit.addEventListener('click',resetEditor);

    loadMethods();
})();
</script>
</body>
</html>
