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

if (!function_exists('chitLedgerPermission')) {
    function chitLedgerPermission(mysqli $conn, string $action): bool
    {
        $fieldMap = [
            'open' => 'can_open',
            'view' => 'can_view',
            'value' => 'can_view_value',
            'export' => 'can_export',
        ];

        $field = $fieldMap[$action] ?? '';
        if ($field === '') {
            return false;
        }

        $adminRoles = [
            'platform admin',
            'super admin',
            'admin',
            'manager',
            'billing',
            'super_admin',
        ];

        foreach ([
            strtolower(trim((string)($_SESSION['user_type'] ?? ''))),
            strtolower(trim((string)($_SESSION['role_name'] ?? ''))),
            strtolower(trim((string)($_SESSION['role_code'] ?? ''))),
        ] as $roleValue) {
            if (in_array($roleValue, $adminRoles, true)) {
                return true;
            }
        }

        $permissionCodes = [
            'perm.chit-ledger',
            'perm.chit-collection',
            'perm.chit',
        ];

        $sessionPermissions = $_SESSION['permissions'] ?? [];

        foreach ($permissionCodes as $permissionCode) {
            if (isset($sessionPermissions[$permissionCode][$field])) {
                return (int)$sessionPermissions[$permissionCode][$field] === 1;
            }
        }

        $businessId = (int)($_SESSION['business_id'] ?? 0);
        $roleId = (int)($_SESSION['role_id'] ?? 0);

        if (
            $businessId <= 0 ||
            $roleId <= 0 ||
            !tableExists($conn, 'permissions') ||
            !tableExists($conn, 'role_permissions')
        ) {
            return false;
        }

        $placeholders = implode(',', array_fill(0, count($permissionCodes), '?'));

        $sql = "SELECT MAX(COALESCE(rp.`{$field}`,0)) AS allowed
                FROM role_permissions rp
                INNER JOIN permissions p ON p.id = rp.permission_id
                WHERE rp.business_id = ?
                  AND rp.role_id = ?
                  AND p.is_active = 1
                  AND p.permission_code IN ({$placeholders})";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return false;
        }

        $types = 'ii' . str_repeat('s', count($permissionCodes));
        $params = array_merge([$businessId, $roleId], $permissionCodes);

        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int)($row['allowed'] ?? 0) === 1;
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
    die('A valid business and branch must be selected.');
}

if (!chitLedgerPermission($conn, 'open')) {
    http_response_code(403);
    die('Access denied. You do not have permission to open the chit ledger.');
}

$canView = chitLedgerPermission($conn, 'view') || chitLedgerPermission($conn, 'open');
$canViewValue = chitLedgerPermission($conn, 'value') || $canView;
$canExport = chitLedgerPermission($conn, 'export') || $canView;

foreach (
    ['chit_collections', 'chit_members', 'chit_groups', 'chit_installments', 'customers', 'payment_methods']
    as $requiredTable
) {
    if (!tableExists($conn, $requiredTable)) {
        die("Required table `{$requiredTable}` was not found.");
    }
}

$pageTitle = 'Chit Collection Ledger';
$page_title = 'Chit Collection Ledger';
$currentPage = 'chit-ledger';

$theme = [
    'primary_color' => '#d89416',
    'primary_dark_color' => '#b86a0b',
    'page_background' => '#f4f3f0',
    'card_background' => '#ffffff',
    'text_color' => '#171717',
    'muted_text_color' => '#7d8794',
    'border_color' => '#e8e8e8',
    'font_family' => 'Inter',
    'heading_font_family' => 'Playfair Display',
    'border_radius_px' => 12,
    'sidebar_gradient_1' => '#171c21',
    'sidebar_gradient_2' => '#20272d',
    'sidebar_gradient_3' => '#101419',
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

$businessName = (string)($_SESSION['business_name'] ?? 'Jewellery ERP');
$preselectedMember = (int)($_GET['member_id'] ?? 0);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo h($businessName); ?> - Chit Collection Ledger</title>
<?php include 'includes/links.php'; ?>
<style>
:root{
    --primary:<?php echo h($theme['primary_color']); ?>;
    --primary-dark:<?php echo h($theme['primary_dark_color']); ?>;
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
.filter-grid{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:10px}.span-2{grid-column:span 2}.span-3{grid-column:span 3}
.field-label{display:block;font-size:9px;font-weight:700;text-transform:uppercase;color:var(--muted-color);margin-bottom:4px}
.form-control,.form-select{min-height:36px;font-size:10px;border-radius:9px;border-color:var(--border-color);background:var(--card-bg);color:var(--text-color)}
.btn-theme{background:linear-gradient(135deg,var(--primary),var(--primary-dark));border:0;color:#fff;border-radius:9px;font-size:10px;font-weight:700;min-height:36px;padding:8px 13px}
.btn-light-custom{border:1px solid var(--border-color);background:var(--card-bg);color:var(--text-color);border-radius:9px;font-size:10px;font-weight:700;min-height:36px;padding:8px 12px}
.compact-table{margin:0;font-size:10px}.compact-table th{font-size:9px;text-transform:uppercase;color:var(--muted-color);background:color-mix(in srgb,var(--muted-color) 6%,transparent);padding:10px 11px;white-space:nowrap;border-color:var(--border-color)}.compact-table td{padding:10px 11px;vertical-align:middle;background:var(--card-bg)!important;color:var(--text-color);border-color:var(--border-color)}
.total-row td{font-weight:800;background:color-mix(in srgb,var(--primary) 8%,var(--card-bg))!important}
.empty-state{padding:42px 20px!important;text-align:center!important;color:var(--muted-color)!important}.ledger-message-row td{border-bottom:0!important}
.theme-toast{position:fixed;top:78px;right:18px;z-index:20000;display:flex;align-items:center;gap:9px;min-width:260px;max-width:420px;padding:11px 14px;border-radius:10px;color:#fff;font-size:11px;font-weight:600;box-shadow:0 14px 35px rgba(0,0,0,.22);opacity:0;transform:translateY(-10px);transition:.22s}.theme-toast.show{opacity:1;transform:translateY(0)}.theme-toast-error{background:#c0392b}
body.dark-mode,body[data-theme="dark"],html.dark-mode body,html[data-theme="dark"] body{--page-bg:#0f151b;--card-bg:#182129;--text-color:#f3f6f8;--muted-color:#9aa7b3;--border-color:#2c3944}
@media print{.no-print,.sidebar,.app-nav{display:none!important}.app-main{margin:0!important}.content-wrap{padding:0!important}.panel{border:0!important}}
@media(max-width:991px){.filter-grid{grid-template-columns:repeat(2,1fr)}.span-2,.span-3{grid-column:span 1}.responsive-table thead{display:none}.responsive-table tbody{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;padding:10px}.responsive-table tbody tr{display:grid;grid-template-columns:1fr 1fr;border:1px solid var(--border-color);border-radius:var(--radius);padding:12px}.responsive-table tbody td{display:flex;justify-content:space-between;gap:10px;padding:8px 0;border:0;border-bottom:1px dashed var(--border-color);text-align:right}.responsive-table tbody td::before{content:attr(data-label);font-size:8px;font-weight:700;text-transform:uppercase;color:var(--muted-color)}}
@media(max-width:767px){.filter-grid{grid-template-columns:1fr}.span-2,.span-3{grid-column:1}.page-heading,.panel-head{align-items:flex-start;flex-direction:column}.responsive-table tbody{grid-template-columns:1fr}.responsive-table tbody tr{grid-template-columns:1fr}.responsive-table tbody td{grid-column:1/-1}}
</style>
</head>
<body>
<?php include 'includes/sidebar.php'; ?>

<main class="app-main">
<?php include 'includes/nav.php'; ?>

<div class="content-wrap">
    <div class="page-heading">
        <div>
            <h1 class="page-title">Chit Collection Ledger</h1>
            <div class="page-subtitle"><?php echo h($businessName); ?> · Member-wise collection history</div>
        </div>

        <div class="d-flex flex-wrap gap-2 no-print">
            <?php if ($canExport): ?>
                <button type="button" class="btn btn-light-custom" id="excelExport">
                    <i class="fa-solid fa-file-excel me-1"></i>Excel
                </button>
                <button type="button" class="btn btn-light-custom" id="pdfExport">
                    <i class="fa-solid fa-file-pdf me-1"></i>PDF
                </button>
            <?php endif; ?>
            <button type="button" class="btn btn-light-custom" onclick="window.print()">
                <i class="fa-solid fa-print me-1"></i>Print
            </button>
        </div>
    </div>

    <?php if (!$canView): ?>
        <div class="panel">
            <div class="empty-state">You do not have permission to view the chit ledger.</div>
        </div>
    <?php else: ?>
        <div class="panel no-print">
            <div class="panel-head">
                <div>
                    <div class="panel-title">Ledger Filters</div>
                    <div class="panel-subtitle">Select member, date period, and group.</div>
                </div>
                <button type="button" class="btn btn-light-custom" id="clearFilters">Clear</button>
            </div>

            <div class="panel-body">
                <form id="filterForm" class="filter-grid">
                    <div class="span-3">
                        <label class="field-label">Member</label>
                        <select name="member_id" id="member_id" class="form-select" required>
                            <option value="">Select Member</option>
                        </select>
                    </div>

                    <div class="span-2">
                        <label class="field-label">Filter Type</label>
                        <select name="filter_type" id="filter_type" class="form-select">
                            <option value="all">All Records</option>
                            <option value="today">Today</option>
                            <option value="week">This Week</option>
                            <option value="month">This Month</option>
                            <option value="range">Date Range</option>
                        </select>
                    </div>

                    <div class="span-2 range-field d-none">
                        <label class="field-label">From Date</label>
                        <input type="date" name="start_date" class="form-control">
                    </div>

                    <div class="span-2 range-field d-none">
                        <label class="field-label">To Date</label>
                        <input type="date" name="end_date" class="form-control">
                    </div>

                    <div class="span-2">
                        <label class="field-label">Group</label>
                        <select name="group_filter" id="group_filter" class="form-select">
                            <option value="0">All Groups</option>
                        </select>
                    </div>

                    <div class="span-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-theme w-100">View Ledger</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="panel">
            <div class="panel-head">
                <div>
                    <div class="panel-title">Collection Register</div>
                    <div class="panel-subtitle" id="ledgerSubtitle">Select a member to view collections.</div>
                </div>
                <button type="button" class="btn btn-light-custom no-print" id="refreshLedger">
                    <i class="fa-solid fa-rotate"></i>
                </button>
            </div>

            <div id="loadingState" class="empty-state d-none">
                <i class="fa-solid fa-spinner fa-spin mb-2"></i>
                <div>Loading ledger...</div>
            </div>

            <div id="tableWrap" class="table-responsive">
                <table class="table compact-table responsive-table">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Receipt No</th>
                        <th>Date</th>
                        <th>Group</th>
                        <th>Installment</th>
                        <th>Due</th>
                        <th>Paid</th>
                        <th>Discount</th>
                        <th>Penalty</th>
                        <th>Net</th>
                        <th>Method</th>
                    </tr>
                    </thead>
                    <tbody id="ledgerBody">
                    <tr class="ledger-message-row">
                        <td colspan="11" class="empty-state">
                            Select a member and click View Ledger.
                        </td>
                    </tr>
                    </tbody>
                    <tfoot id="ledgerFoot"></tfoot>
                </table>
            </div>

            <div id="emptyState" class="d-none"></div>
        </div>
    <?php endif; ?>

    <?php include 'includes/footer.php'; ?>
</div>
</main>

<?php include 'includes/script.php'; ?>
<script src="assets/js/script.js"></script>
<script>
(function(){
    'use strict';

    const preselectedMember=<?php echo (int)$preselectedMember; ?>;
    const canViewValue=<?php echo $canViewValue ? 'true' : 'false'; ?>;

    const form=document.getElementById('filterForm');
    const memberSelect=document.getElementById('member_id');
    const typeSelect=document.getElementById('filter_type');
    const groupSelect=document.getElementById('group_filter');
    const body=document.getElementById('ledgerBody');
    const foot=document.getElementById('ledgerFoot');
    const wrap=document.getElementById('tableWrap');
    const loading=document.getElementById('loadingState');
    const empty=document.getElementById('emptyState');
    const subtitle=document.getElementById('ledgerSubtitle');

    if(!form||!memberSelect||!typeSelect||!groupSelect||!body||!foot||!wrap||!loading||!empty){
        return;
    }

    function toast(message){
        const element=document.createElement('div');
        element.className='theme-toast theme-toast-error';
        element.innerHTML='<i class="fa-solid fa-circle-exclamation"></i><span></span>';
        element.querySelector('span').textContent=message;
        document.body.appendChild(element);
        requestAnimationFrame(()=>element.classList.add('show'));
        setTimeout(()=>{
            element.classList.remove('show');
            setTimeout(()=>element.remove(),250);
        },3500);
    }

    function escapeHtml(value){
        const div=document.createElement('div');
        div.textContent=value??'';
        return div.innerHTML;
    }

    function money(value){
        return Number(value||0).toLocaleString('en-IN',{
            minimumFractionDigits:2,
            maximumFractionDigits:2
        });
    }

    function currentParams(){
        return new URLSearchParams(new FormData(form));
    }

    function showTableMessage(message){
        loading.classList.add('d-none');
        wrap.classList.remove('d-none');
        empty.classList.add('d-none');
        foot.innerHTML='';
        body.innerHTML=`<tr class="ledger-message-row">
            <td colspan="11" class="empty-state">${escapeHtml(message)}</td>
        </tr>`;
    }

    function toggleRange(){
        document.querySelectorAll('.range-field').forEach(element=>{
            element.classList.toggle('d-none',typeSelect.value!=='range');
        });
    }

    async function requestJson(url){
        const response=await fetch(url,{
            credentials:'same-origin',
            headers:{
                'Accept':'application/json',
                'X-Requested-With':'XMLHttpRequest'
            }
        });

        const raw=await response.text();
        let result;

        try{
            result=JSON.parse(raw);
        }catch(error){
            throw new Error(
                raw
                    ? 'API returned invalid output: '+raw.substring(0,180)
                    : 'API returned an empty response.'
            );
        }

        if(!response.ok||!result.success){
            throw new Error(result.message||'Request failed.');
        }

        return result;
    }

    function populateBootstrap(result){
        memberSelect.innerHTML=
            '<option value="">Select Member</option>'+
            (result.members||[]).map(row=>
                `<option value="${Number(row.id)}">${escapeHtml(row.ticket_no+' - '+row.customer_name+' - '+row.group_name)}</option>`
            ).join('');

        groupSelect.innerHTML=
            '<option value="0">All Groups</option>'+
            (result.groups||[]).map(row=>
                `<option value="${Number(row.id)}">${escapeHtml(row.group_no+' - '+row.group_name)}</option>`
            ).join('');

        if(preselectedMember>0){
            memberSelect.value=String(preselectedMember);
            loadLedger();
        }
    }

    function render(result){
        const rows=Array.isArray(result.rows)?result.rows:[];

        loading.classList.add('d-none');

        if(!rows.length){
            showTableMessage('No collections found for the selected filters.');
            subtitle.textContent=result.member
                ? result.member.customer_name+' · '+result.member.ticket_no
                : 'Select a member to view collections.';
            return;
        }

        empty.classList.add('d-none');
        wrap.classList.remove('d-none');

        subtitle.textContent=
            (result.member?.customer_name||'Member')+
            ' · '+
            (result.member?.ticket_no||'');

        body.innerHTML=rows.map((row,index)=>`<tr>
            <td data-label="#">${index+1}</td>
            <td data-label="Receipt">${escapeHtml(row.receipt_no)}</td>
            <td data-label="Date">${escapeHtml(row.collection_date_display)}</td>
            <td data-label="Group">${escapeHtml(row.group_name)}</td>
            <td data-label="Installment">#${Number(row.installment_no)}</td>
            <td data-label="Due">${canViewValue?'₹'+money(row.due_amount):'••••'}</td>
            <td data-label="Paid">${canViewValue?'₹'+money(row.paid_amount):'••••'}</td>
            <td data-label="Discount">${canViewValue?'₹'+money(row.discount_amount):'••••'}</td>
            <td data-label="Penalty">${canViewValue?'₹'+money(row.penalty_amount):'••••'}</td>
            <td data-label="Net"><strong>${canViewValue?'₹'+money(row.net_amount):'••••'}</strong></td>
            <td data-label="Method">${escapeHtml(row.method_name||'—')}</td>
        </tr>`).join('');

        foot.innerHTML=`<tr class="total-row">
            <td colspan="6" class="text-end">Total Paid Amount</td>
            <td>${canViewValue?'₹'+money(result.total_paid):'••••'}</td>
            <td colspan="4"></td>
        </tr>`;
    }

    async function loadBootstrap(){
        try{
            const result=await requestJson('api/chit-ledger.php?action=bootstrap');
            populateBootstrap(result);
        }catch(error){
            empty.textContent=error.message;
            toast(error.message);
        }
    }

    async function loadLedger(){
        if(!memberSelect.value){
            showTableMessage('Select a member and click View Ledger.');
            subtitle.textContent='Select a member to view collections.';
            return;
        }

        loading.classList.remove('d-none');
        wrap.classList.add('d-none');
        empty.classList.add('d-none');

        try{
            const params=currentParams();
            params.set('action','list');

            const result=await requestJson(
                'api/chit-ledger.php?'+params.toString()
            );

            render(result);
        }catch(error){
            showTableMessage(error.message);
            toast(error.message);
        }
    }

    function exportLedger(type){
        if(!memberSelect.value){
            toast('Please select a member first.');
            return;
        }

        const params=currentParams();
        params.set('action',type);

        window.location.href=
            'api/chit-ledger.php?'+params.toString();
    }

    typeSelect.addEventListener('change',toggleRange);

    form.addEventListener('submit',event=>{
        event.preventDefault();
        loadLedger();
    });

    document.getElementById('refreshLedger')?.addEventListener('click',loadLedger);
    document.getElementById('excelExport')?.addEventListener('click',()=>exportLedger('excel'));
    document.getElementById('pdfExport')?.addEventListener('click',()=>exportLedger('pdf'));

    document.getElementById('clearFilters')?.addEventListener('click',()=>{
        form.reset();
        toggleRange();
        showTableMessage('Select a member and click View Ledger.');
        subtitle.textContent='Select a member to view collections.';
    });

    toggleRange();
    loadBootstrap();
})();
</script>
</body>
</html>
