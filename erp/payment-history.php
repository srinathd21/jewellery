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
        $safe = $conn->real_escape_string($table);
        $result = $conn->query("SHOW TABLES LIKE '{$safe}'");
        return $result && $result->num_rows > 0;
    }
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$businessId = (int)($_SESSION['business_id'] ?? 0);

if ($userId <= 0) {
    header('Location: login.php');
    exit;
}

if ($businessId <= 0) {
    http_response_code(403);
    die('A valid business must be selected.');
}

$pageTitle = 'Payment History';
$page_title = 'Payment History';
$currentPage = 'payment-history';

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
<title><?php echo h($businessName); ?> - Payment History</title>
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
.page-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:10px}
.page-title{font-family:<?php echo json_encode((string)$theme['heading_font_family']); ?>,serif;font-size:18px;font-weight:800;margin:0}
.page-subtitle{font-size:10px;color:var(--muted-color);margin-top:2px}
.stat-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-bottom:10px}
.stat-card{background:var(--card-bg);border:1px solid var(--border-color);border-radius:var(--radius);padding:12px 14px;display:flex;align-items:center;gap:12px;min-height:82px}
.stat-icon{width:42px;height:42px;display:grid;place-items:center;border-radius:10px;background:var(--primary-soft);color:var(--primary-dark);font-size:16px}
.stat-label{font-size:10px;color:var(--muted-color)}.stat-value{font-size:21px;font-weight:800;margin-top:4px}
.panel{background:var(--card-bg);border:1px solid var(--border-color);border-radius:var(--radius);overflow:hidden;margin-bottom:10px}
.panel-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:11px 13px;border-bottom:1px solid var(--border-color)}
.panel-title{font-size:12px;font-weight:800}.panel-subtitle{font-size:9px;color:var(--muted-color);margin-top:2px}.panel-body{padding:13px}
.filter-grid{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:10px}.span-2{grid-column:span 2}.span-3{grid-column:span 3}.span-4{grid-column:span 4}.span-6{grid-column:span 6}.span-12{grid-column:span 12}
.field-label{display:block;font-size:9px;font-weight:700;text-transform:uppercase;color:var(--muted-color);margin-bottom:4px}
.form-control,.form-select{min-height:36px;font-size:10px;border-radius:9px;border-color:var(--border-color);background:var(--card-bg);color:var(--text-color)}
.btn-theme{background:linear-gradient(135deg,var(--primary),var(--primary-dark));border:0;color:#fff;border-radius:9px;font-size:10px;font-weight:700;min-height:36px;padding:8px 13px}
.btn-light-custom{border:1px solid var(--border-color);background:var(--card-bg);color:var(--text-color);border-radius:9px;font-size:10px;font-weight:700;min-height:36px;padding:8px 12px}
.tab-strip{display:flex;gap:7px;overflow-x:auto;padding:10px 12px;border-bottom:1px solid var(--border-color)}
.tab-button{border:1px solid var(--border-color);background:var(--card-bg);color:var(--muted-color);font-size:9px;font-weight:800;padding:7px 10px;border-radius:999px;white-space:nowrap}
.tab-button.active{background:var(--primary);border-color:var(--primary);color:#fff}
.compact-table{margin:0;font-size:10px}.compact-table th{font-size:9px;text-transform:uppercase;color:var(--muted-color);background:color-mix(in srgb,var(--muted-color) 6%,transparent);padding:10px 11px;white-space:nowrap;border-color:var(--border-color)}.compact-table td{padding:10px 11px;vertical-align:middle;background:var(--card-bg)!important;color:var(--text-color);border-color:var(--border-color)}
.amount-in{color:#168449;font-weight:800}.amount-out{color:#c0392b;font-weight:800}.record-no{font-weight:800}.subtext{font-size:8px;color:var(--muted-color);margin-top:2px}
.empty-state{padding:42px 20px;text-align:center;color:var(--muted-color)}
.section-total{font-size:10px;font-weight:800;color:var(--primary-dark)}
.theme-toast{position:fixed;right:18px;top:78px;z-index:20000;display:flex;align-items:center;gap:9px;min-width:260px;max-width:420px;padding:11px 14px;border-radius:10px;color:#fff;font-size:11px;font-weight:600;box-shadow:0 14px 35px rgba(0,0,0,.22);opacity:0;transform:translateY(-10px);transition:.22s}.theme-toast.show{opacity:1;transform:translateY(0)}.theme-toast-success{background:#168449}.theme-toast-error{background:#c0392b}
body.dark-mode,body[data-theme="dark"],html.dark-mode body,html[data-theme="dark"] body{--page-bg:#0f151b;--card-bg:#182129;--text-color:#f3f6f8;--muted-color:#9aa7b3;--border-color:#2c3944}
@media(max-width:1199px){.filter-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.span-2,.span-3,.span-4,.span-6,.span-12{grid-column:span 1}}
@media(max-width:991px){.stat-grid{grid-template-columns:1fr}.responsive-table thead{display:none}.responsive-table tbody{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;padding:10px}.responsive-table tbody tr{display:grid;grid-template-columns:1fr 1fr;border:1px solid var(--border-color);border-radius:var(--radius);padding:12px}.responsive-table tbody td{display:flex;justify-content:space-between;gap:10px;padding:8px 0;border:0;border-bottom:1px dashed var(--border-color);text-align:right}.responsive-table tbody td::before{content:attr(data-label);font-size:8px;font-weight:700;text-transform:uppercase;color:var(--muted-color)}}
@media(max-width:767px){.page-heading,.panel-head{align-items:flex-start;flex-direction:column}.filter-grid{grid-template-columns:1fr}.responsive-table tbody{grid-template-columns:1fr}.responsive-table tbody tr{grid-template-columns:1fr}.responsive-table tbody td{grid-column:1/-1}}
</style>
</head>
<body>
<?php include 'includes/sidebar.php'; ?>
<main class="app-main">
<?php include 'includes/nav.php'; ?>
<div class="content-wrap">
    <div class="page-heading">
        <div>
            <h1 class="page-title">Payment History</h1>
            <div class="page-subtitle"><?php echo h($businessName); ?> · Track incoming and outgoing payments</div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-light-custom" id="exportCsv">
                <i class="fa-solid fa-file-csv me-1"></i>Export CSV
            </button>
            <button type="button" class="btn btn-light-custom" onclick="window.print()">
                <i class="fa-solid fa-print me-1"></i>Print
            </button>
        </div>
    </div>

    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-icon"><i class="fa-solid fa-arrow-down"></i></div>
            <div><div class="stat-label">Total Incoming</div><div class="stat-value" id="totalIncoming">₹0.00</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fa-solid fa-arrow-up"></i></div>
            <div><div class="stat-label">Total Outgoing</div><div class="stat-value" id="totalOutgoing">₹0.00</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fa-solid fa-scale-balanced"></i></div>
            <div><div class="stat-label">Net Balance</div><div class="stat-value" id="netBalance">₹0.00</div></div>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head">
            <div>
                <div class="panel-title">Payment Filters</div>
                <div class="panel-subtitle">Filter by period, customer, supplier, payment type, or reference.</div>
            </div>
            <button type="button" class="btn btn-light-custom" id="refreshData"><i class="fa-solid fa-rotate"></i></button>
        </div>
        <div class="panel-body">
            <form id="filterForm" class="filter-grid">
                <div class="span-2">
                    <label class="field-label">Date Range</label>
                    <select name="date_range" id="dateRange" class="form-select">
                        <option value="today">Today</option>
                        <option value="yesterday">Yesterday</option>
                        <option value="week">This Week</option>
                        <option value="month">This Month</option>
                        <option value="custom">Custom Range</option>
                    </select>
                </div>
                <div class="span-2 custom-date d-none">
                    <label class="field-label">From Date</label>
                    <input type="date" name="from_date" class="form-control">
                </div>
                <div class="span-2 custom-date d-none">
                    <label class="field-label">To Date</label>
                    <input type="date" name="to_date" class="form-control">
                </div>
                <div class="span-2">
                    <label class="field-label">Customer</label>
                    <select name="customer_id" id="customerFilter" class="form-select">
                        <option value="">All Customers</option>
                    </select>
                </div>
                <div class="span-2">
                    <label class="field-label">Supplier</label>
                    <select name="supplier_id" id="supplierFilter" class="form-select">
                        <option value="">All Suppliers</option>
                    </select>
                </div>
                <div class="span-2">
                    <label class="field-label">Search</label>
                    <input type="search" name="search" class="form-control" placeholder="Receipt, payment no, name...">
                </div>
                <div class="span-12 d-flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-theme"><i class="fa-solid fa-magnifying-glass me-1"></i>Apply Filters</button>
                    <button type="button" class="btn btn-light-custom" id="resetFilters">Reset</button>
                    <span class="ms-auto align-self-center page-subtitle" id="periodLabel"></span>
                </div>
            </form>
        </div>
    </div>

    <div class="panel">
        <div class="tab-strip" id="paymentTabs">
            <button type="button" class="tab-button active" data-tab="all">All Payments</button>
            <button type="button" class="tab-button" data-tab="customer">Customer Payments</button>
            <button type="button" class="tab-button" data-tab="pawn">Pawn Payments</button>
            <button type="button" class="tab-button" data-tab="chit">Chit Collections</button>
            <button type="button" class="tab-button" data-tab="supplier">Supplier Payments</button>
        </div>
        <div id="loadingState" class="empty-state">
            <i class="fa-solid fa-spinner fa-spin mb-2"></i>
            <div>Loading payment history...</div>
        </div>
        <div id="paymentSections"></div>
        <div id="emptyState" class="empty-state d-none">No payment records found.</div>
    </div>

    <?php include 'includes/footer.php'; ?>
</div>
</main>
<?php include 'includes/script.php'; ?>
<script src="assets/js/script.js"></script>
<script>
(function(){
    'use strict';

    const filterForm=document.getElementById('filterForm');
    const dateRange=document.getElementById('dateRange');
    const customerFilter=document.getElementById('customerFilter');
    const supplierFilter=document.getElementById('supplierFilter');
    const loadingState=document.getElementById('loadingState');
    const emptyState=document.getElementById('emptyState');
    const sections=document.getElementById('paymentSections');
    const periodLabel=document.getElementById('periodLabel');
    let activeTab='all';
    let lastResult=null;

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

    async function requestJson(url){
        const response=await fetch(url,{
            credentials:'same-origin',
            headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}
        });
        const raw=await response.text();
        let result;
        try{result=JSON.parse(raw)}catch(error){
            throw new Error(raw?'Server returned invalid output: '+raw.substring(0,180):'Server returned an empty response.');
        }
        if(!response.ok||!result.success)throw new Error(result.message||'Request failed.');
        return result;
    }

    function toggleCustomDate(){
        const show=dateRange.value==='custom';
        document.querySelectorAll('.custom-date').forEach(element=>element.classList.toggle('d-none',!show));
    }

    function renderBootstrap(result){
        customerFilter.innerHTML='<option value="">All Customers</option>'+
            (result.customers||[]).map(row=>`<option value="${Number(row.id)}">${escapeHtml(row.name)}${row.code?' ('+escapeHtml(row.code)+')':''}</option>`).join('');
        supplierFilter.innerHTML='<option value="">All Suppliers</option>'+
            (result.suppliers||[]).map(row=>`<option value="${Number(row.id)}">${escapeHtml(row.name)}${row.code?' ('+escapeHtml(row.code)+')':''}</option>`).join('');
    }

    function rowTable(title,total,rows,columns,type){
        if(!rows.length)return '';
        const head=columns.map(column=>`<th>${escapeHtml(column.label)}</th>`).join('');
        const body=rows.map(row=>`<tr>${columns.map(column=>{
            const raw=row[column.key]??'';
            const value=column.money?'₹'+money(raw):escapeHtml(raw||'—');
            return `<td data-label="${escapeHtml(column.label)}" class="${column.money?(type==='out'?'amount-out':'amount-in'):''}">${column.strong?'<strong>'+value+'</strong>':value}</td>`;
        }).join('')}</tr>`).join('');
        return `<div class="panel mb-0 border-0 rounded-0 payment-section" data-section="${escapeHtml(type)}">
            <div class="panel-head">
                <div><div class="panel-title">${escapeHtml(title)}</div><div class="panel-subtitle">${rows.length} record(s)</div></div>
                <div class="section-total">₹${money(total)}</div>
            </div>
            <div class="table-responsive">
                <table class="table compact-table responsive-table">
                    <thead><tr>${head}</tr></thead>
                    <tbody>${body}</tbody>
                </table>
            </div>
        </div>`;
    }

    function renderResult(result){
        lastResult=result;
        loadingState.classList.add('d-none');
        document.getElementById('totalIncoming').textContent='₹'+money(result.summary.total_incoming);
        document.getElementById('totalOutgoing').textContent='₹'+money(result.summary.total_outgoing);
        document.getElementById('netBalance').textContent='₹'+money(result.summary.net_balance);
        periodLabel.textContent='Period: '+result.period.from_display+' to '+result.period.to_display;

        const customerColumns=[
            {key:'date_display',label:'Date'},
            {key:'number',label:'Receipt No',strong:true},
            {key:'party_name',label:'Customer'},
            {key:'party_code',label:'Code'},
            {key:'mobile',label:'Mobile'},
            {key:'amount',label:'Amount',money:true},
            {key:'method',label:'Method'},
            {key:'reference',label:'Reference'},
            {key:'notes',label:'Notes'}
        ];
        const pawnColumns=[
            {key:'date_display',label:'Date'},
            {key:'number',label:'Receipt No',strong:true},
            {key:'reference_entity',label:'Pawn No'},
            {key:'party_name',label:'Customer'},
            {key:'payment_type',label:'Type'},
            {key:'amount',label:'Amount',money:true},
            {key:'method',label:'Method'}
        ];
        const chitColumns=[
            {key:'date_display',label:'Date'},
            {key:'number',label:'Receipt No',strong:true},
            {key:'reference_entity',label:'Group'},
            {key:'party_code',label:'Member No'},
            {key:'party_name',label:'Member'},
            {key:'amount',label:'Amount',money:true},
            {key:'method',label:'Method'}
        ];
        const supplierColumns=[
            {key:'date_display',label:'Date'},
            {key:'number',label:'Payment No',strong:true},
            {key:'party_name',label:'Supplier'},
            {key:'party_code',label:'Code'},
            {key:'amount',label:'Amount',money:true},
            {key:'method',label:'Method'},
            {key:'reference',label:'Reference'},
            {key:'notes',label:'Notes'}
        ];

        let html='';
        if(activeTab==='all'||activeTab==='customer'){
            html+=rowTable('Customer Payments (Incoming)',result.totals.customer,result.customer_payments,customerColumns,'customer');
        }
        if(activeTab==='all'||activeTab==='pawn'){
            html+=rowTable('Pawn Payments (Incoming)',result.totals.pawn,result.pawn_payments,pawnColumns,'pawn');
            html+=rowTable('Pawn Interest Collections (Incoming)',result.totals.pawn_interest,result.pawn_interest,pawnColumns,'pawn');
        }
        if(activeTab==='all'||activeTab==='chit'){
            html+=rowTable('Chit Collections (Incoming)',result.totals.chit,result.chit_collections,chitColumns,'chit');
        }
        if(activeTab==='all'||activeTab==='supplier'){
            html+=rowTable('Supplier Payments (Outgoing)',result.totals.supplier,result.supplier_payments,supplierColumns,'out');
        }

        sections.innerHTML=html;
        emptyState.classList.toggle('d-none',html!=='');
    }

    async function loadBootstrap(){
        try{
            const result=await requestJson('api/payment-history.php?action=bootstrap');
            renderBootstrap(result);
        }catch(error){
            toast('error',error.message);
        }
    }

    async function loadHistory(){
        loadingState.classList.remove('d-none');
        sections.innerHTML='';
        emptyState.classList.add('d-none');

        try{
            const params=new URLSearchParams(new FormData(filterForm));
            params.set('action','list');
            const result=await requestJson('api/payment-history.php?'+params.toString());
            renderResult(result);
        }catch(error){
            loadingState.classList.add('d-none');
            emptyState.classList.remove('d-none');
            emptyState.textContent=error.message;
            toast('error',error.message);
        }
    }

    filterForm.addEventListener('submit',event=>{event.preventDefault();loadHistory();});
    dateRange.addEventListener('change',toggleCustomDate);
    document.getElementById('refreshData').addEventListener('click',loadHistory);
    document.getElementById('resetFilters').addEventListener('click',()=>{
        filterForm.reset();
        activeTab='all';
        document.querySelectorAll('.tab-button').forEach(button=>button.classList.toggle('active',button.dataset.tab==='all'));
        toggleCustomDate();
        loadHistory();
    });

    document.getElementById('paymentTabs').addEventListener('click',event=>{
        const button=event.target.closest('[data-tab]');
        if(!button)return;
        activeTab=button.dataset.tab;
        document.querySelectorAll('.tab-button').forEach(item=>item.classList.toggle('active',item===button));
        if(lastResult)renderResult(lastResult);
    });

    document.getElementById('exportCsv').addEventListener('click',()=>{
        const params=new URLSearchParams(new FormData(filterForm));
        params.set('action','export');
        params.set('format','csv');
        window.location.href='api/payment-history.php?'+params.toString();
    });

    toggleCustomDate();
    loadBootstrap();
    loadHistory();
})();
</script>
</body>
</html>
