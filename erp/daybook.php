
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

foreach ($configCandidates as $configFile) {
    if (is_file($configFile)) {
        require_once $configFile;
        break;
    }
}

if (!isset($conn) || !($conn instanceof mysqli)) {
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
        $result = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
        return $result && $result->num_rows > 0;
    }
}

if (!function_exists('canAccessDayBook')) {
    function canAccessDayBook(mysqli $conn, string $action = 'open'): bool
    {
        if (($_SESSION['user_type'] ?? '') === 'Platform Admin') {
            return true;
        }

        $fieldMap = [
            'open' => 'can_open',
            'view' => 'can_view',
            'export' => 'can_export',
        ];

        $field = $fieldMap[$action] ?? 'can_open';
        $permissionCodes = [
            'perm.reports.daybook',
            'perm.accounts.daybook',
            'perm.reports',
            'perm.accounts',
        ];

        $sessionPermissions = $_SESSION['permissions'] ?? [];

        foreach ($permissionCodes as $code) {
            if (isset($sessionPermissions[$code][$field])) {
                return (int)$sessionPermissions[$code][$field] === 1;
            }
        }

        $allowedRoles = [
            'super admin', 'super_admin', 'admin',
            'business admin', 'business_admin',
            'branch admin', 'branch_admin',
            'manager', 'billing', 'accounts', 'accountant'
        ];

        $roleName = strtolower(trim((string)($_SESSION['role_name'] ?? '')));
        $roleCode = strtolower(trim((string)($_SESSION['role_code'] ?? '')));

        return in_array($roleName, $allowedRoles, true) ||
            in_array($roleCode, $allowedRoles, true);
    }
}

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? $_SESSION['default_branch_id'] ?? 0);

if ($businessId <= 0) {
    http_response_code(403);
    die('A valid business must be selected.');
}

if (!canAccessDayBook($conn, 'open')) {
    http_response_code(403);
    die('Access denied. You do not have permission to open Day Book.');
}

$pageTitle = 'Day Book';
$page_title = 'Day Book';
$currentPage = 'daybook';

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
    $stmt = $conn->prepare('SELECT * FROM business_theme_settings WHERE business_id = ? LIMIT 1');

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
$canExport = canAccessDayBook($conn, 'export') || canAccessDayBook($conn, 'view');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo h($businessName); ?> - Day Book</title>
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
.stat-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin-bottom:10px}
.stat-card{background:var(--card-bg);border:1px solid var(--border-color);border-radius:var(--radius);padding:12px 14px;display:flex;align-items:center;gap:12px;min-height:82px}
.stat-icon{width:42px;height:42px;display:grid;place-items:center;flex:0 0 42px;border-radius:10px;background:var(--primary-soft);color:var(--primary-dark);font-size:16px}
.stat-label{font-size:10px;color:var(--muted-color)}
.stat-value{font-size:21px;font-weight:800;line-height:1.1;margin-top:4px}
.panel{background:var(--card-bg);border:1px solid var(--border-color);border-radius:var(--radius);overflow:hidden;margin-bottom:10px}
.panel-head{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:11px 13px;border-bottom:1px solid var(--border-color)}
.panel-title{font-size:12px;font-weight:800}.panel-subtitle{font-size:9px;color:var(--muted-color);margin-top:2px}.panel-body{padding:12px}
.form-grid{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:10px}.span-2{grid-column:span 2}.span-3{grid-column:span 3}.span-4{grid-column:span 4}.span-6{grid-column:span 6}.span-12{grid-column:span 12}
.field-label{display:block;font-size:9px;font-weight:700;text-transform:uppercase;color:var(--muted-color);margin-bottom:4px}
.form-control,.form-select{min-height:36px;font-size:10px;border-radius:9px;border-color:var(--border-color);background:var(--card-bg);color:var(--text-color)}
.btn-theme{background:linear-gradient(135deg,var(--primary),var(--primary-dark));border:0;color:#fff;border-radius:9px;font-size:10px;font-weight:700;min-height:36px;padding:8px 13px}
.btn-light-custom{border:1px solid var(--border-color);background:var(--card-bg);color:var(--text-color);border-radius:9px;font-size:10px;font-weight:700;min-height:36px;padding:8px 12px}
.cash-in{color:#168449!important;font-weight:800}.cash-out{color:#c0392b!important;font-weight:800}.balance-positive{color:#2764c5!important;font-weight:800}.balance-negative{color:#c0392b!important;font-weight:800}
.type-badge{display:inline-flex;border-radius:999px;padding:4px 8px;font-size:8px;font-weight:800;background:var(--primary-soft);color:var(--primary-dark)}
.compact-table{margin:0;font-size:10px;min-width:1100px}.compact-table th{font-size:9px;text-transform:uppercase;color:var(--muted-color);background:color-mix(in srgb,var(--muted-color) 6%,transparent);padding:10px 11px;white-space:nowrap;border-color:var(--border-color)}.compact-table td{padding:10px 11px;vertical-align:middle;background:var(--card-bg)!important;color:var(--text-color);border-color:var(--border-color)}
.loading-state,.empty-state{padding:42px 20px;text-align:center;color:var(--muted-color)}
.theme-toast{position:fixed;right:18px;top:78px;z-index:20000;display:flex;align-items:center;gap:9px;min-width:260px;max-width:420px;padding:11px 14px;border-radius:10px;color:#fff;font-size:11px;font-weight:600;box-shadow:0 14px 35px rgba(0,0,0,.22);opacity:0;transform:translateY(-10px);transition:.22s}.theme-toast.show{opacity:1;transform:translateY(0)}.theme-toast-error{background:#c0392b}
body.dark-mode,body[data-theme="dark"],html.dark-mode body,html[data-theme="dark"] body{--page-bg:#0f151b;--card-bg:#182129;--text-color:#f3f6f8;--muted-color:#9aa7b3;--border-color:#2c3944}
@media(max-width:1199.98px){.stat-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.form-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.span-2,.span-3,.span-4,.span-6,.span-12{grid-column:span 1}}
@media(max-width:991.98px){.responsive-table{min-width:0}.responsive-table thead,.responsive-table tfoot{display:none}.responsive-table tbody{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;padding:10px}.responsive-table tbody tr{display:grid;grid-template-columns:1fr 1fr;border:1px solid var(--border-color);border-radius:var(--radius);padding:12px;background:var(--card-bg)}.responsive-table tbody td{display:flex;justify-content:space-between;gap:10px;padding:8px 0;border:0;border-bottom:1px dashed var(--border-color);text-align:right}.responsive-table tbody td::before{content:attr(data-label);font-size:8px;font-weight:700;text-transform:uppercase;color:var(--muted-color)}.responsive-table tbody td.main-column{grid-column:1/-1;display:block;text-align:left}.responsive-table tbody td.main-column::before{display:none}}
@media(max-width:767.98px){.stat-grid,.form-grid{grid-template-columns:1fr}.responsive-table tbody{grid-template-columns:1fr}.responsive-table tbody tr{grid-template-columns:1fr}.responsive-table tbody td{grid-column:1/-1}.panel-head{align-items:flex-start;flex-direction:column}.export-wrap{width:100%;display:grid!important;grid-template-columns:1fr 1fr}.export-wrap .btn{width:100%}}
@media print{.sidebar,.app-main>nav,.app-main>header,.panel:first-of-type,.export-wrap,#refreshReport,footer{display:none!important}.app-main{margin:0!important}.content-wrap{padding:0!important}.panel{border:0!important}.compact-table{min-width:100%!important;font-size:8px}}
</style>
</head>
<body>
<?php include 'includes/sidebar.php'; ?>
<main class="app-main">
<?php include 'includes/nav.php'; ?>

<div class="content-wrap">
    <div class="stat-grid">
        <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-arrow-down"></i></div><div><div class="stat-label">Total Cash In</div><div class="stat-value" id="totalCashIn">₹0.00</div></div></div>
        <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-arrow-up"></i></div><div><div class="stat-label">Total Cash Out</div><div class="stat-value" id="totalCashOut">₹0.00</div></div></div>
        <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-wallet"></i></div><div><div class="stat-label">Net Cash Balance</div><div class="stat-value" id="netCashBalance">₹0.00</div></div></div>
        <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-list"></i></div><div><div class="stat-label">Total Entries</div><div class="stat-value" id="totalEntries">0</div></div></div>
    </div>

    <div class="panel">
        <div class="panel-head">
            <div><div class="panel-title">Day Book Filters</div><div class="panel-subtitle">View cash transactions for the selected period.</div></div>
            <?php if ($canExport): ?>
            <div class="d-flex gap-2 export-wrap">
                <button type="button" class="btn btn-light-custom" id="printReport"><i class="fa-solid fa-print me-1"></i>Print</button>
                <button type="button" class="btn btn-light-custom" id="exportExcel"><i class="fa-solid fa-file-excel me-1"></i>Excel</button>
            </div>
            <?php endif; ?>
        </div>
        <div class="panel-body">
            <form id="filterForm" class="form-grid">
                <div class="span-3"><label class="field-label">From Date</label><input type="date" name="from_date" class="form-control" required></div>
                <div class="span-3"><label class="field-label">To Date</label><input type="date" name="to_date" class="form-control" required></div>
                <div class="span-3"><label class="field-label">Transaction Type</label><select name="entry_type" class="form-select"><option value="">All Types</option><option value="Sale">Sale</option><option value="Customer Collection">Customer Collection</option><option value="Purchase">Purchase</option><option value="Supplier Payment">Supplier Payment</option><option value="Expense">Expense</option></select></div>
                <div class="span-2 d-flex align-items-end"><button type="submit" class="btn btn-theme w-100"><i class="fa-solid fa-filter me-1"></i>Apply</button></div>
                <div class="span-1 d-flex align-items-end"><button type="button" class="btn btn-light-custom w-100" id="resetFilters">Reset</button></div>
            </form>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head">
            <div><div class="panel-title">Day Book Entries</div><div class="panel-subtitle" id="periodText">Loading report period...</div></div>
            <button type="button" class="btn btn-light-custom" id="refreshReport"><i class="fa-solid fa-rotate"></i></button>
        </div>

        <div id="loadingState" class="loading-state"><i class="fa-solid fa-spinner fa-spin mb-2"></i><div>Loading day book...</div></div>

        <div id="tableWrap" class="table-responsive d-none">
            <table class="table compact-table responsive-table">
                <thead><tr><th>#</th><th>Date</th><th>Type</th><th>Reference No</th><th>Party / Category</th><th>Notes</th><th>Cash In</th><th>Cash Out</th><th>Running Balance</th></tr></thead>
                <tbody id="reportTableBody"></tbody>
                <tfoot id="reportTableFoot"></tfoot>
            </table>
        </div>

        <div id="emptyState" class="empty-state d-none">No day book entries found.</div>
    </div>

    <?php include 'includes/footer.php'; ?>
</div>
</main>

<?php include 'includes/script.php'; ?>
<script src="assets/js/script.js"></script>
<script>
(function(){
    'use strict';

    const API_URL='api/daybook.php';
    const filterForm=document.getElementById('filterForm');
    const tableBody=document.getElementById('reportTableBody');
    const tableFoot=document.getElementById('reportTableFoot');
    const tableWrap=document.getElementById('tableWrap');
    const loading=document.getElementById('loadingState');
    const empty=document.getElementById('emptyState');

    function toast(message){
        const element=document.createElement('div');
        element.className='theme-toast theme-toast-error';
        element.innerHTML='<i class="fa-solid fa-circle-exclamation"></i><span></span>';
        element.querySelector('span').textContent=message;
        document.body.appendChild(element);
        requestAnimationFrame(()=>element.classList.add('show'));
        setTimeout(()=>{element.classList.remove('show');setTimeout(()=>element.remove(),250)},3300);
    }

    function escapeHtml(value){const div=document.createElement('div');div.textContent=value??'';return div.innerHTML;}
    function money(value){return Number(value||0).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2});}

    async function requestJson(url){
        const response=await fetch(url,{credentials:'same-origin',headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}});
        const raw=await response.text();
        let result;
        try{result=JSON.parse(raw);}catch(error){throw new Error(raw?'Server returned invalid output: '+raw.substring(0,180):'Server returned an empty response.');}
        if(!response.ok||!result.success)throw new Error(result.message||'Request failed.');
        return result;
    }

    function renderSummary(summary){
        document.getElementById('totalCashIn').textContent='₹'+money(summary.total_cash_in);
        document.getElementById('totalCashOut').textContent='₹'+money(summary.total_cash_out);
        document.getElementById('netCashBalance').textContent='₹'+money(summary.net_balance);
        document.getElementById('totalEntries').textContent=Number(summary.total_entries||0);
    }

    function renderRows(rows,summary){
        loading.classList.add('d-none');

        if(!rows.length){
            tableWrap.classList.add('d-none');
            empty.classList.remove('d-none');
            tableBody.innerHTML='';
            tableFoot.innerHTML='';
            return;
        }

        empty.classList.add('d-none');
        tableWrap.classList.remove('d-none');

        tableBody.innerHTML=rows.map((row,index)=>`<tr>
            <td data-label="#">${index+1}</td>
            <td data-label="Date">${escapeHtml(row.entry_date_display||'—')}</td>
            <td data-label="Type"><span class="type-badge">${escapeHtml(row.entry_type||'')}</span></td>
            <td class="main-column" data-label="Reference"><strong>${escapeHtml(row.ref_no||'—')}</strong></td>
            <td data-label="Party / Category">${escapeHtml(row.party_name||'—')}</td>
            <td data-label="Notes">${escapeHtml(row.notes||'—')}</td>
            <td data-label="Cash In" class="cash-in">${row.cash_flow==='in'?'₹'+money(row.amount):'—'}</td>
            <td data-label="Cash Out" class="cash-out">${row.cash_flow==='out'?'₹'+money(row.amount):'—'}</td>
            <td data-label="Running Balance" class="${Number(row.running_balance)>=0?'balance-positive':'balance-negative'}">₹${money(row.running_balance)}</td>
        </tr>`).join('');

        tableFoot.innerHTML=`<tr><td colspan="6" class="text-end"><strong>Totals</strong></td><td class="cash-in"><strong>₹${money(summary.total_cash_in)}</strong></td><td class="cash-out"><strong>₹${money(summary.total_cash_out)}</strong></td><td class="${Number(summary.net_balance)>=0?'balance-positive':'balance-negative'}"><strong>₹${money(summary.net_balance)}</strong></td></tr>`;
    }

    async function loadBootstrap(){
        const result=await requestJson(API_URL+'?action=bootstrap');
        filterForm.querySelector('[name="from_date"]').value=result.defaults.from_date;
        filterForm.querySelector('[name="to_date"]').value=result.defaults.to_date;
    }

    async function loadReport(){
        loading.classList.remove('d-none');
        tableWrap.classList.add('d-none');
        empty.classList.add('d-none');
        try{
            const params=new URLSearchParams(new FormData(filterForm));
            params.set('action','list');
            const result=await requestJson(API_URL+'?'+params.toString());
            renderSummary(result.summary||{});
            renderRows(Array.isArray(result.rows)?result.rows:[],result.summary||{});
            document.getElementById('periodText').textContent='Period: '+result.period.from_display+' to '+result.period.to_display;
        }catch(error){
            loading.classList.add('d-none');
            empty.classList.remove('d-none');
            empty.textContent=error.message;
            toast(error.message);
        }
    }

    filterForm.addEventListener('submit',event=>{event.preventDefault();loadReport();});
    document.getElementById('resetFilters').addEventListener('click',()=>{filterForm.reset();loadBootstrap().then(loadReport).catch(error=>toast(error.message));});
    document.getElementById('refreshReport').addEventListener('click',loadReport);
    document.getElementById('printReport')?.addEventListener('click',()=>window.print());
    document.getElementById('exportExcel')?.addEventListener('click',()=>{
        const params=new URLSearchParams(new FormData(filterForm));
        params.set('action','export');
        params.set('format','excel');
        window.location.href=API_URL+'?'+params.toString();
    });

    loadBootstrap().then(loadReport).catch(error=>toast(error.message));
})();
</script>
</body>
</html>
