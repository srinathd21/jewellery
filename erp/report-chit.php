<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));

foreach ([
    __DIR__ . '/config/config.php',
    __DIR__ . '/config.php',
    __DIR__ . '/includes/config.php',
] as $configFile) {
    if (is_file($configFile)) {
        require_once $configFile;
        break;
    }
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database configuration is not available.');
}

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

$currentPage = 'report-chit';
$pageTitle = 'Chit Report';
$page_title = 'Chit Report';

$businessId = (int)($_SESSION['business_id'] ?? 0);
$businessName = (string)($_SESSION['business_name'] ?? 'Jewellery ERP');

$theme = [
    'primary_color' => '#d89416',
    'primary_dark_color' => '#b86a0b',
    'primary_soft_color' => '#fff6e5',
    'sidebar_gradient_1' => '#171c21',
    'sidebar_gradient_2' => '#20272d',
    'sidebar_gradient_3' => '#101419',
    'page_background' => '#f5f5f3',
    'card_background' => '#ffffff',
    'text_color' => '#111827',
    'muted_text_color' => '#7b8497',
    'border_color' => '#e5e7eb',
    'font_family' => 'Inter',
    'heading_font_family' => 'Playfair Display',
    'border_radius_px' => 14,
];

if ($businessId > 0) {
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
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo h($businessName); ?> - Chit Report</title>

<?php include 'includes/links.php'; ?>

<style>
:root{
    --brand:<?php echo h($theme['primary_color']); ?>;
    --brand-dark:<?php echo h($theme['primary_dark_color']); ?>;
    --brand-soft:<?php echo h($theme['primary_soft_color']); ?>;
    --page:<?php echo h($theme['page_background']); ?>;
    --card:<?php echo h($theme['card_background']); ?>;
    --text:<?php echo h($theme['text_color']); ?>;
    --muted:<?php echo h($theme['muted_text_color']); ?>;
    --line:<?php echo h($theme['border_color']); ?>;
    --radius:<?php echo (int)$theme['border_radius_px']; ?>px;
}
body{background:var(--page);color:var(--text);font-family:<?php echo json_encode((string)$theme['font_family']); ?>,sans-serif}
.sidebar{background:linear-gradient(180deg,<?php echo h($theme['sidebar_gradient_1']); ?>,<?php echo h($theme['sidebar_gradient_2']); ?>,<?php echo h($theme['sidebar_gradient_3']); ?>)!important}
.content-wrap{padding-top:16px}
.tab-row{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px}
.tab-btn{border:1px solid var(--line);background:var(--card);color:var(--text);border-radius:10px;min-height:38px;padding:8px 13px;font-size:11px;font-weight:700}
.tab-btn.active{background:linear-gradient(135deg,var(--brand),var(--brand-dark));color:#fff;border-color:transparent}
.summary-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:12px}
.summary-card,.mini-card,.panel{background:var(--card);border:1px solid var(--line);border-radius:var(--radius)}
.summary-card{min-height:92px;padding:16px;display:flex;align-items:center;gap:13px}
.summary-icon{width:46px;height:46px;display:grid;place-items:center;border-radius:12px;background:var(--brand-soft);color:var(--brand-dark);font-size:18px}
.summary-label{font-size:10px;color:var(--muted)} .summary-value{font-size:22px;font-weight:800;margin-top:4px}
.mini-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin-bottom:12px}
.mini-card{padding:13px}.mini-label{font-size:9px;color:var(--muted)}.mini-value{font-size:16px;font-weight:800;margin-top:4px}
.panel{overflow:hidden;margin-bottom:12px}.panel-head{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:13px 14px;border-bottom:1px solid var(--line)}.panel-title{font-size:13px;font-weight:800}.panel-subtitle{font-size:10px;color:var(--muted);margin-top:3px}.panel-body{padding:14px}
.filter-grid{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:10px;align-items:end}
.span-2{grid-column:span 2}.span-3{grid-column:span 3}.span-4{grid-column:span 4}.span-12{grid-column:span 12}
.form-label{display:block;margin-bottom:5px;color:var(--muted);font-size:9px;font-weight:800;text-transform:uppercase}
.form-control,.form-select{min-height:40px;border:1px solid var(--line);border-radius:10px;background:#fff;color:var(--text);font-size:11px}
.btn{min-height:38px;border-radius:10px;font-size:11px;font-weight:700}.btn-brand{border:0;background:linear-gradient(135deg,var(--brand),var(--brand-dark));color:#fff}.btn-soft{border:1px solid var(--line);background:#fff;color:var(--text)}
.report-table{min-width:1450px;margin:0;font-size:10px}.report-table thead th{padding:12px 13px;background:#f7f7f8;color:#738096;border-color:var(--line);font-size:9px;font-weight:800;text-transform:uppercase;white-space:nowrap}.report-table td{padding:11px 13px;background:var(--card)!important;color:var(--text);border-color:var(--line);vertical-align:middle;white-space:nowrap}
.status-pill{display:inline-flex;padding:5px 10px;border-radius:999px;font-size:9px;font-weight:800}.status-active{background:#eaf7ef;color:#11864b}.status-closed{background:#eef0f3;color:#596273}.status-draft{background:#fff4df;color:#a06100}
.money-pill{display:inline-flex;padding:5px 9px;border-radius:999px;background:#edf5ff;color:#1557d6;font-size:9px;font-weight:800}
.empty{padding:36px!important;text-align:center;color:var(--muted)!important}
.toast-box{position:fixed;top:76px;right:18px;z-index:20000;width:min(360px,calc(100vw - 24px))}.app-toast{padding:11px 13px;margin-bottom:8px;border-radius:10px;color:#fff;background:#c0392b;box-shadow:0 14px 35px rgba(0,0,0,.2);font-size:11px;font-weight:700}
@media(max-width:1200px){.summary-grid{grid-template-columns:repeat(2,1fr)}.mini-grid{grid-template-columns:repeat(3,1fr)}.filter-grid{grid-template-columns:repeat(2,1fr)}.span-2,.span-3,.span-4,.span-12{grid-column:span 1}}
@media(max-width:767px){.content-wrap{padding-left:10px;padding-right:10px}.summary-grid,.mini-grid,.filter-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<?php include 'includes/sidebar.php'; ?>

<main class="app-main">
<?php include 'includes/nav.php'; ?>

<div class="content-wrap">

<div class="tab-row">
    <button type="button" class="tab-btn active" data-report-type="groups"><i class="fa-solid fa-layer-group me-1"></i>Groups</button>
    <button type="button" class="tab-btn" data-report-type="collections"><i class="fa-solid fa-indian-rupee-sign me-1"></i>Collections</button>
    <button type="button" class="tab-btn" data-report-type="members"><i class="fa-solid fa-users me-1"></i>Members</button>
</div>

<div class="summary-grid">
    <div class="summary-card"><div class="summary-icon"><i class="fa-solid fa-layer-group"></i></div><div><div class="summary-label" id="label1">Total Groups</div><div class="summary-value" id="value1">0</div></div></div>
    <div class="summary-card"><div class="summary-icon"><i class="fa-solid fa-circle-check"></i></div><div><div class="summary-label" id="label2">Active Groups</div><div class="summary-value" id="value2">0</div></div></div>
    <div class="summary-card"><div class="summary-icon"><i class="fa-solid fa-indian-rupee-sign"></i></div><div><div class="summary-label" id="label3">Total Collected</div><div class="summary-value" id="value3">₹0.00</div></div></div>
    <div class="summary-card"><div class="summary-icon"><i class="fa-solid fa-wallet"></i></div><div><div class="summary-label" id="label4">Total Chit Value</div><div class="summary-value" id="value4">₹0.00</div></div></div>
</div>

<div class="mini-grid" id="miniGrid">
    <div class="mini-card"><div class="mini-label" id="miniLabel1">Closed Groups</div><div class="mini-value" id="miniValue1">0</div></div>
    <div class="mini-card"><div class="mini-label" id="miniLabel2">Monthly Installment</div><div class="mini-value" id="miniValue2">₹0.00</div></div>
    <div class="mini-card"><div class="mini-label" id="miniLabel3">Total Collections</div><div class="mini-value" id="miniValue3">0</div></div>
    <div class="mini-card"><div class="mini-label" id="miniLabel4">Total Due</div><div class="mini-value" id="miniValue4">₹0.00</div></div>
    <div class="mini-card"><div class="mini-label" id="miniLabel5">Total Penalty</div><div class="mini-value" id="miniValue5">₹0.00</div></div>
</div>

<div class="panel">
    <div class="panel-head">
        <div>
            <div class="panel-title">Chit Report Filters</div>
            <div class="panel-subtitle">Filter chit groups, collections, and members.</div>
        </div>
        <button type="button" class="btn btn-soft" id="refreshReport"><i class="fa-solid fa-rotate"></i></button>
    </div>
    <div class="panel-body">
        <form id="filterForm" class="filter-grid">
            <input type="hidden" name="report_type" id="reportType" value="groups">

            <div class="span-2">
                <label class="form-label">Date Range</label>
                <select name="date_range" id="dateRange" class="form-select">
                    <option value="all">All Time</option>
                    <option value="today">Today</option>
                    <option value="yesterday">Yesterday</option>
                    <option value="week">This Week</option>
                    <option value="month">This Month</option>
                    <option value="custom">Custom Range</option>
                </select>
            </div>

            <div class="span-2" id="fromWrap" hidden>
                <label class="form-label">From Date</label>
                <input type="date" name="from_date" class="form-control">
            </div>

            <div class="span-2" id="toWrap" hidden>
                <label class="form-label">To Date</label>
                <input type="date" name="to_date" class="form-control">
            </div>

            <div class="span-2" id="statusWrap">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="all">All Status</option>
                    <option value="Active">Active</option>
                    <option value="Closed">Closed</option>
                    <option value="Draft">Draft</option>
                </select>
            </div>

            <div class="span-2" id="chitTypeWrap">
                <label class="form-label">Chit Type</label>
                <select name="chit_type" class="form-select">
                    <option value="all">All Types</option>
                    <option value="Money">Money</option>
                    <option value="Silver">Silver</option>
                    <option value="Gold">Gold</option>
                </select>
            </div>

            <div class="span-3" id="groupWrap" hidden>
                <label class="form-label">Chit Group</label>
                <select name="group_id" id="groupFilter" class="form-select">
                    <option value="0">All Groups</option>
                </select>
            </div>

            <div class="span-12 d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-brand"><i class="fa-solid fa-magnifying-glass me-1"></i>Show Report</button>
                <button type="button" class="btn btn-soft" id="resetFilters">Reset</button>
                <button type="button" class="btn btn-soft ms-auto" data-export="excel"><i class="fa-solid fa-file-excel me-1"></i>Excel</button>
                <button type="button" class="btn btn-soft" data-export="pdf"><i class="fa-solid fa-file-pdf me-1"></i>PDF</button>
            </div>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <div>
            <div class="panel-title" id="tableTitle">Chit Groups List</div>
            <div class="panel-subtitle" id="tableSubtitle">All matching chit groups.</div>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table report-table align-middle">
            <thead id="tableHead"></thead>
            <tbody id="tableBody"><tr><td class="empty">Loading report...</td></tr></tbody>
            <tfoot id="tableFoot"></tfoot>
        </table>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
</div>
</main>

<div class="toast-box" id="toastBox"></div>

<?php include 'includes/script.php'; ?>
<script src="assets/js/script.js"></script>

<script>
(function(){
    'use strict';

    const apiUrl='api/report-chit.php';
    const form=document.getElementById('filterForm');
    const reportType=document.getElementById('reportType');
    const dateRange=document.getElementById('dateRange');
    const fromWrap=document.getElementById('fromWrap');
    const toWrap=document.getElementById('toWrap');
    const groupWrap=document.getElementById('groupWrap');
    const statusWrap=document.getElementById('statusWrap');
    const chitTypeWrap=document.getElementById('chitTypeWrap');
    const groupFilter=document.getElementById('groupFilter');
    const tableHead=document.getElementById('tableHead');
    const tableBody=document.getElementById('tableBody');
    const tableFoot=document.getElementById('tableFoot');

    const esc=value=>String(value??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
    const money=value=>Number(value||0).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2});

    function toast(message){
        const el=document.createElement('div');
        el.className='app-toast';
        el.textContent=message;
        document.getElementById('toastBox').appendChild(el);
        setTimeout(()=>el.remove(),3500);
    }

    function statusClass(status){
        if(status==='Active') return 'status-active';
        if(status==='Closed') return 'status-closed';
        return 'status-draft';
    }

    function toggleCustomDates(){
        const custom=dateRange.value==='custom';
        fromWrap.hidden=!custom;
        toWrap.hidden=!custom;
    }

    function configureFilters(){
        const type=reportType.value;
        statusWrap.hidden=type!=='groups';
        chitTypeWrap.hidden=type!=='groups';
        groupWrap.hidden=type==='groups';
    }

    function renderGroups(groups){
        groupFilter.innerHTML='<option value="0">All Groups</option>'+groups.map(g=>
            `<option value="${Number(g.id)}">${esc(g.group_no)} - ${esc(g.group_name)}</option>`
        ).join('');
    }

    function renderSummary(result){
        const s=result.summary||{};
        const type=result.report_type;

        if(type==='groups'){
            label1.textContent='Total Groups'; value1.textContent=s.total_groups||0;
            label2.textContent='Active Groups'; value2.textContent=s.active_groups||0;
            label3.textContent='Total Collected'; value3.textContent='₹'+money(s.total_collected);
            label4.textContent='Total Chit Value'; value4.textContent='₹'+money(s.total_chit_value);

            miniLabel1.textContent='Closed Groups'; miniValue1.textContent=s.closed_groups||0;
            miniLabel2.textContent='Monthly Installment'; miniValue2.textContent='₹'+money(s.total_installment_amount);
            miniLabel3.textContent='Total Collections'; miniValue3.textContent=s.total_collections||0;
            miniLabel4.textContent='Total Members'; miniValue4.textContent=s.total_members||0;
            miniLabel5.textContent='Draft Groups'; miniValue5.textContent=s.draft_groups||0;
        }else if(type==='collections'){
            label1.textContent='Total Collections'; value1.textContent=s.total_collections||0;
            label2.textContent='Total Paid'; value2.textContent='₹'+money(s.total_paid);
            label3.textContent='Net Amount'; value3.textContent='₹'+money(s.total_net);
            label4.textContent='Total Due'; value4.textContent='₹'+money(s.total_due);

            miniLabel1.textContent='Discount'; miniValue1.textContent='₹'+money(s.total_discount);
            miniLabel2.textContent='Penalty'; miniValue2.textContent='₹'+money(s.total_penalty);
            miniLabel3.textContent='Groups'; miniValue3.textContent=s.group_count||0;
            miniLabel4.textContent='Members'; miniValue4.textContent=s.member_count||0;
            miniLabel5.textContent='Average Collection'; miniValue5.textContent='₹'+money(s.average_collection);
        }else{
            label1.textContent='Total Members'; value1.textContent=s.total_members||0;
            label2.textContent='Active Members'; value2.textContent=s.active_members||0;
            label3.textContent='Security Deposit'; value3.textContent='₹'+money(s.security_deposit);
            label4.textContent='Opening Due'; value4.textContent='₹'+money(s.opening_due);

            miniLabel1.textContent='Inactive Members'; miniValue1.textContent=s.inactive_members||0;
            miniLabel2.textContent='Total Groups'; miniValue2.textContent=s.group_count||0;
            miniLabel3.textContent='Collected'; miniValue3.textContent='₹'+money(s.total_collected);
            miniLabel4.textContent='Pending Due'; miniValue4.textContent='₹'+money(s.pending_due);
            miniLabel5.textContent='Average Deposit'; miniValue5.textContent='₹'+money(s.average_deposit);
        }
    }

    function renderTable(result){
        const type=result.report_type;
        const rows=result.rows||[];

        if(type==='groups'){
            tableTitle.textContent='Chit Groups List';
            tableSubtitle.textContent='All matching chit groups.';
            tableHead.innerHTML='<tr><th>Group No</th><th>Group Name</th><th>Type</th><th>Start Date</th><th>End Date</th><th>Members</th><th>Active</th><th>Installment</th><th>Chit Value</th><th>Collections</th><th>Collected</th><th>Status</th><th>Action</th></tr>';
            tableBody.innerHTML=rows.length?rows.map(r=>`<tr><td><strong>${esc(r.group_no)}</strong></td><td>${esc(r.group_name)}</td><td>${esc(r.chit_type)}</td><td>${esc(r.start_date_display)}</td><td>${esc(r.end_date_display)}</td><td>${Number(r.total_members||0)}</td><td>${Number(r.active_members||0)}</td><td>₹${money(r.installment_amount)}</td><td><span class="money-pill">₹${money(r.chit_value)}</span></td><td>${Number(r.total_collections||0)}</td><td>₹${money(r.total_collected)}</td><td><span class="status-pill ${statusClass(r.status)}">${esc(r.status)}</span></td><td><a class="btn btn-sm btn-soft" href="chit-view.php?id=${Number(r.id)}"><i class="fa-solid fa-eye"></i></a></td></tr>`).join(''):'<tr><td colspan="13" class="empty">No chit groups found.</td></tr>';
        }else if(type==='collections'){
            tableTitle.textContent='Collection Details';
            tableSubtitle.textContent='All matching chit collection records.';
            tableHead.innerHTML='<tr><th>Receipt No</th><th>Date</th><th>Group</th><th>Member No</th><th>Member Name</th><th>Due</th><th>Paid</th><th>Discount</th><th>Penalty</th><th>Net</th><th>Method</th><th>Reference</th></tr>';
            tableBody.innerHTML=rows.length?rows.map(r=>`<tr><td><strong>${esc(r.receipt_no)}</strong></td><td>${esc(r.collection_date_display)}</td><td>${esc(r.group_no)} - ${esc(r.group_name)}</td><td>${esc(r.member_no)}</td><td>${esc(r.subscriber_name)}</td><td>₹${money(r.due_amount)}</td><td>₹${money(r.paid_amount)}</td><td>₹${money(r.discount_amount)}</td><td>₹${money(r.penalty_amount)}</td><td><span class="money-pill">₹${money(r.net_amount)}</span></td><td>${esc(r.payment_method_name||'-')}</td><td>${esc(r.reference_no||'-')}</td></tr>`).join(''):'<tr><td colspan="12" class="empty">No collections found.</td></tr>';
        }else{
            tableTitle.textContent='Member List';
            tableSubtitle.textContent='Members for the selected group.';
            tableHead.innerHTML='<tr><th>Member No</th><th>Subscriber Name</th><th>Mobile</th><th>Nominee</th><th>Nominee Mobile</th><th>Join Date</th><th>Security Deposit</th><th>Opening Due</th><th>Status</th><th>Action</th></tr>';
            tableBody.innerHTML=rows.length?rows.map(r=>`<tr><td><strong>${esc(r.member_no)}</strong></td><td>${esc(r.subscriber_name)}</td><td>${esc(r.subscriber_mobile||'-')}</td><td>${esc(r.nominee_name||'-')}</td><td>${esc(r.nominee_mobile||'-')}</td><td>${esc(r.join_date_display)}</td><td>₹${money(r.security_deposit)}</td><td>₹${money(r.opening_due)}</td><td><span class="status-pill ${statusClass(r.status)}">${esc(r.status)}</span></td><td><a class="btn btn-sm btn-soft" href="chit-member-view.php?id=${Number(r.id)}"><i class="fa-solid fa-eye"></i></a></td></tr>`).join(''):'<tr><td colspan="10" class="empty">Select a group or no members found.</td></tr>';
        }

        tableFoot.innerHTML='';
    }

    async function loadReport(){
        tableBody.innerHTML='<tr><td class="empty">Loading report...</td></tr>';

        try{
            const params=new URLSearchParams(new FormData(form));
            params.set('action','list');

            const response=await fetch(apiUrl+'?'+params.toString(),{headers:{Accept:'application/json'}});
            const result=await response.json();

            if(!response.ok||!result.success){
                throw new Error(result.message||'Unable to load report.');
            }

            renderGroups(result.groups||[]);
            renderSummary(result);
            renderTable(result);
        }catch(error){
            tableBody.innerHTML='<tr><td class="empty">Unable to load report.</td></tr>';
            toast(error.message||'Unable to load report.');
        }
    }

    document.querySelectorAll('[data-report-type]').forEach(button=>{
        button.addEventListener('click',()=>{
            document.querySelectorAll('[data-report-type]').forEach(btn=>btn.classList.remove('active'));
            button.classList.add('active');
            reportType.value=button.dataset.reportType;
            configureFilters();
            loadReport();
        });
    });

    dateRange.addEventListener('change',toggleCustomDates);
    form.addEventListener('submit',event=>{event.preventDefault();loadReport()});
    refreshReport.addEventListener('click',loadReport);
    resetFilters.addEventListener('click',()=>{form.reset();dateRange.value='all';toggleCustomDates();configureFilters();loadReport()});

    document.querySelectorAll('[data-export]').forEach(button=>{
        button.addEventListener('click',()=>{
            const params=new URLSearchParams(new FormData(form));
            params.set('action',button.dataset.export==='excel'?'export_excel':'export_pdf');
            window.location.href=apiUrl+'?'+params.toString();
        });
    });

    toggleCustomDates();
    configureFilters();
    loadReport();
})();
</script>
</body>
</html>
