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

$currentPage = 'report-old-silver';
$pageTitle = 'Old Silver Report';
$page_title = 'Old Silver Report';

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
    $stmt = $conn->prepare(
        'SELECT * FROM business_theme_settings WHERE business_id = ? LIMIT 1'
    );

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
<title><?php echo h($businessName); ?> - Old Silver Report</title>

<?php include('includes/links.php'); ?>

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

body{
    background:var(--page);
    color:var(--text);
    font-family:<?php echo json_encode((string)$theme['font_family']); ?>,sans-serif;
}

.sidebar{
    background:linear-gradient(
        180deg,
        <?php echo h($theme['sidebar_gradient_1']); ?>,
        <?php echo h($theme['sidebar_gradient_2']); ?>,
        <?php echo h($theme['sidebar_gradient_3']); ?>
    )!important;
}

.content-wrap{padding-top:16px}

.summary-grid{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:10px;
    margin-bottom:12px;
}

.summary-card,
.mini-card,
.panel{
    background:var(--card);
    border:1px solid var(--line);
    border-radius:var(--radius);
}

.summary-card{
    min-height:92px;
    padding:16px;
    display:flex;
    align-items:center;
    gap:13px;
}

.summary-icon{
    width:46px;
    height:46px;
    flex:0 0 46px;
    display:grid;
    place-items:center;
    border-radius:12px;
    background:var(--brand-soft);
    color:var(--brand-dark);
    font-size:18px;
}

.summary-label{font-size:10px;color:var(--muted)}
.summary-value{font-size:22px;font-weight:800;margin-top:4px}

.mini-grid{
    display:grid;
    grid-template-columns:repeat(6,minmax(0,1fr));
    gap:10px;
    margin-bottom:12px;
}

.mini-card{padding:13px}
.mini-label{font-size:9px;color:var(--muted)}
.mini-value{font-size:16px;font-weight:800;margin-top:4px}

.panel{overflow:hidden;margin-bottom:12px}

.panel-head{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    padding:13px 14px;
    border-bottom:1px solid var(--line);
}

.panel-title{font-size:13px;font-weight:800}
.panel-subtitle{font-size:10px;color:var(--muted);margin-top:3px}
.panel-body{padding:14px}

.filter-grid{
    display:grid;
    grid-template-columns:repeat(12,minmax(0,1fr));
    gap:10px;
    align-items:end;
}

.span-2{grid-column:span 2}
.span-3{grid-column:span 3}
.span-4{grid-column:span 4}
.span-12{grid-column:span 12}

.form-label{
    display:block;
    margin-bottom:5px;
    color:var(--muted);
    font-size:9px;
    font-weight:800;
    text-transform:uppercase;
}

.form-control,
.form-select{
    min-height:40px;
    border:1px solid var(--line);
    border-radius:10px;
    background:#fff;
    color:var(--text);
    font-size:11px;
    box-shadow:none;
}

.form-control:focus,
.form-select:focus{
    border-color:var(--brand);
    box-shadow:0 0 0 .18rem rgba(216,148,22,.12);
}

.btn{
    min-height:38px;
    border-radius:10px;
    font-size:11px;
    font-weight:700;
}

.btn-brand{
    border:0;
    background:linear-gradient(135deg,var(--brand),var(--brand-dark));
    color:#fff;
}

.btn-soft{
    border:1px solid var(--line);
    background:#fff;
    color:var(--text);
}

.report-table{
    min-width:1700px;
    margin:0;
    font-size:10px;
}

.report-table thead th{
    padding:12px 13px;
    background:#f7f7f8;
    color:#738096;
    border-color:var(--line);
    font-size:9px;
    font-weight:800;
    text-transform:uppercase;
    white-space:nowrap;
}

.report-table tbody td,
.report-table tfoot td{
    padding:11px 13px;
    background:var(--card)!important;
    color:var(--text);
    border-color:var(--line);
    vertical-align:middle;
    white-space:nowrap;
}

.status-pill{
    display:inline-flex;
    padding:5px 10px;
    border-radius:999px;
    font-size:9px;
    font-weight:800;
}

.status-cash{background:#eaf7ef;color:#11864b}
.status-exchange{background:#edf5ff;color:#1557d6}
.status-pending{background:#fff4df;color:#a06100}

.item-pill{
    display:inline-flex;
    padding:5px 9px;
    border-radius:999px;
    background:#f4f5f7;
    color:#4f5967;
    font-size:9px;
    font-weight:800;
}

.money-pill{
    display:inline-flex;
    padding:5px 9px;
    border-radius:999px;
    background:#edf5ff;
    color:#1557d6;
    font-size:9px;
    font-weight:800;
}

.empty-state{
    padding:36px!important;
    text-align:center;
    color:var(--muted)!important;
}

.toast-box{
    position:fixed;
    top:76px;
    right:18px;
    z-index:20000;
    width:min(360px,calc(100vw - 24px));
}

.app-toast{
    padding:11px 13px;
    margin-bottom:8px;
    border-radius:10px;
    color:#fff;
    background:#c0392b;
    box-shadow:0 14px 35px rgba(0,0,0,.2);
    font-size:11px;
    font-weight:700;
}

@media(max-width:1200px){
    .summary-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
    .mini-grid{grid-template-columns:repeat(3,minmax(0,1fr))}
    .filter-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
    .span-2,.span-3,.span-4,.span-12{grid-column:span 1}
}

@media(max-width:767px){
    .content-wrap{padding-left:10px;padding-right:10px}
    .summary-grid,.mini-grid,.filter-grid{grid-template-columns:1fr}
}

@media print{
    .sidebar,.app-nav,.filter-panel,.footer{display:none!important}
    .app-main{margin-left:0!important}
    .content-wrap{padding:0!important}
    .table-responsive{overflow:visible!important}
    .report-table{min-width:0!important;font-size:8px}
}
</style>
</head>

<body>
<?php include('includes/sidebar.php'); ?>

<main class="app-main">
<?php include('includes/nav.php'); ?>

<div class="content-wrap">

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-icon"><i class="fa-solid fa-recycle"></i></div>
            <div>
                <div class="summary-label">Total Entries</div>
                <div class="summary-value" id="totalEntries">0</div>
            </div>
        </div>

        <div class="summary-card">
            <div class="summary-icon"><i class="fa-solid fa-weight-scale"></i></div>
            <div>
                <div class="summary-label">Total Net Weight</div>
                <div class="summary-value" id="totalNetWeight">0.000 g</div>
            </div>
        </div>

        <div class="summary-card">
            <div class="summary-icon"><i class="fa-solid fa-indian-rupee-sign"></i></div>
            <div>
                <div class="summary-label">Total Amount</div>
                <div class="summary-value" id="totalAmount">₹0.00</div>
            </div>
        </div>

        <div class="summary-card">
            <div class="summary-icon"><i class="fa-solid fa-percent"></i></div>
            <div>
                <div class="summary-label">Total Deduction</div>
                <div class="summary-value" id="totalDeduction">₹0.00</div>
            </div>
        </div>
    </div>

    <div class="mini-grid">
        <div class="mini-card">
            <div class="mini-label">Cash</div>
            <div class="mini-value text-success" id="cashTotal">₹0.00</div>
        </div>

        <div class="mini-card">
            <div class="mini-label">Exchange</div>
            <div class="mini-value text-primary" id="exchangeTotal">₹0.00</div>
        </div>

        <div class="mini-card">
            <div class="mini-label">Pending</div>
            <div class="mini-value text-warning" id="pendingTotal">₹0.00</div>
        </div>

        <div class="mini-card">
            <div class="mini-label">Gross Weight</div>
            <div class="mini-value" id="grossWeight">0.000 g</div>
        </div>

        <div class="mini-card">
            <div class="mini-label">Less Weight</div>
            <div class="mini-value" id="lessWeight">0.000 g</div>
        </div>

        <div class="mini-card">
            <div class="mini-label">Average Rate/g</div>
            <div class="mini-value" id="averageRate">₹0.00</div>
        </div>
    </div>

    <div class="panel filter-panel">
        <div class="panel-head">
            <div>
                <div class="panel-title">Old Silver Report Filters</div>
                <div class="panel-subtitle">Filter by date, customer, adjustment type, and search text.</div>
            </div>

            <button type="button" class="btn btn-soft" id="refreshReport">
                <i class="fa-solid fa-rotate"></i>
            </button>
        </div>

        <div class="panel-body">
            <form id="filterForm" class="filter-grid">
                <div class="span-2">
                    <label class="form-label">Date Range</label>
                    <select name="date_range" id="dateRange" class="form-select">
                        <option value="today">Today</option>
                        <option value="yesterday">Yesterday</option>
                        <option value="week">This Week</option>
                        <option value="month">This Month</option>
                        <option value="custom">Custom Range</option>
                    </select>
                </div>

                <div class="span-2" id="fromDateWrap" hidden>
                    <label class="form-label">From Date</label>
                    <input type="date" name="from_date" class="form-control">
                </div>

                <div class="span-2" id="toDateWrap" hidden>
                    <label class="form-label">To Date</label>
                    <input type="date" name="to_date" class="form-control">
                </div>

                <div class="span-3">
                    <label class="form-label">Customer</label>
                    <select name="customer_id" id="customerFilter" class="form-select">
                        <option value="0">All Customers</option>
                    </select>
                </div>

                <div class="span-2">
                    <label class="form-label">Adjustment Type</label>
                    <select name="adjustment_type" class="form-select">
                        <option value="all">All Types</option>
                        <option value="Cash">Cash Payment</option>
                        <option value="Exchange">Exchange</option>
                        <option value="Pending">Pending</option>
                    </select>
                </div>

                <div class="span-3">
                    <label class="form-label">Search</label>
                    <input
                        type="search"
                        name="search"
                        class="form-control"
                        placeholder="Entry no, customer, mobile..."
                    >
                </div>

                <div class="span-12 d-flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-brand">
                        <i class="fa-solid fa-magnifying-glass me-1"></i>Show Report
                    </button>

                    <button type="button" class="btn btn-soft" id="resetFilters">
                        Reset
                    </button>

                    <button type="button" class="btn btn-soft ms-auto" data-export="excel">
                        <i class="fa-solid fa-file-excel me-1"></i>Excel
                    </button>

                    <button type="button" class="btn btn-soft" data-export="pdf">
                        <i class="fa-solid fa-file-pdf me-1"></i>PDF
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head">
            <div>
                <div class="panel-title">Old Silver Purchase Details</div>
                <div class="panel-subtitle" id="reportPeriod">Loading report period...</div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table report-table align-middle">
                <thead>
                    <tr>
                        <th>Entry No</th>
                        <th>Date</th>
                        <th>Customer</th>
                        <th>Mobile</th>
                        <th>Items</th>
                        <th>Gross Wt</th>
                        <th>Less Wt</th>
                        <th>Net Wt</th>
                        <th>Rate/g</th>
                        <th>Deduction %</th>
                        <th>Deduction Amt</th>
                        <th>Final Amount</th>
                        <th>Adjustment</th>
                        <th>Linked Sale</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody id="entryRows">
                    <tr>
                        <td colspan="15" class="empty-state">Loading report...</td>
                    </tr>
                </tbody>

                <tfoot id="entryTotals"></tfoot>
            </table>
        </div>
    </div>

    <div class="panel" id="itemSummaryPanel">
        <div class="panel-head">
            <div>
                <div class="panel-title">Items Summary</div>
                <div class="panel-subtitle">Top 10 old silver items by net weight.</div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table report-table align-middle" style="min-width:900px">
                <thead>
                    <tr>
                        <th>Item Name</th>
                        <th>Purity</th>
                        <th>Gross Weight</th>
                        <th>Less Weight</th>
                        <th>Net Weight</th>
                        <th>Appearances</th>
                    </tr>
                </thead>
                <tbody id="itemSummaryRows"></tbody>
            </table>
        </div>
    </div>

    <?php include('includes/footer.php'); ?>
</div>
</main>

<div class="toast-box" id="toastBox"></div>

<?php include('includes/script.php'); ?>
<script src="assets/js/script.js"></script>

<script>
(function(){
    'use strict';

    const apiUrl='api/report-old-silver.php';
    const form=document.getElementById('filterForm');
    const dateRange=document.getElementById('dateRange');
    const fromWrap=document.getElementById('fromDateWrap');
    const toWrap=document.getElementById('toDateWrap');
    const customerFilter=document.getElementById('customerFilter');
    const entryRows=document.getElementById('entryRows');
    const entryTotals=document.getElementById('entryTotals');
    const itemRows=document.getElementById('itemSummaryRows');
    const itemPanel=document.getElementById('itemSummaryPanel');

    function escapeHtml(value){
        return String(value??'')
            .replaceAll('&','&amp;')
            .replaceAll('<','&lt;')
            .replaceAll('>','&gt;')
            .replaceAll('"','&quot;')
            .replaceAll("'",'&#039;');
    }

    function money(value){
        return Number(value||0).toLocaleString('en-IN',{
            minimumFractionDigits:2,
            maximumFractionDigits:2
        });
    }

    function weight(value){
        return Number(value||0).toLocaleString('en-IN',{
            minimumFractionDigits:3,
            maximumFractionDigits:3
        });
    }

    function toast(message){
        const element=document.createElement('div');
        element.className='app-toast';
        element.textContent=message;
        document.getElementById('toastBox').appendChild(element);
        setTimeout(()=>element.remove(),3500);
    }

    function toggleCustomDates(){
        const custom=dateRange.value==='custom';
        fromWrap.hidden=!custom;
        toWrap.hidden=!custom;
    }

    function statusClass(type){
        if(type==='Cash') return 'status-cash';
        if(type==='Exchange') return 'status-exchange';
        return 'status-pending';
    }

    function setSummary(summary){
        document.getElementById('totalEntries').textContent=
            Number(summary.total_entries||0);

        document.getElementById('totalNetWeight').textContent=
            weight(summary.total_net_weight)+' g';

        document.getElementById('totalAmount').textContent=
            '₹'+money(summary.total_final_amount);

        document.getElementById('totalDeduction').textContent=
            '₹'+money(summary.total_deduction);

        document.getElementById('cashTotal').textContent=
            '₹'+money(summary.cash_total);

        document.getElementById('exchangeTotal').textContent=
            '₹'+money(summary.exchange_total);

        document.getElementById('pendingTotal').textContent=
            '₹'+money(summary.pending_total);

        document.getElementById('grossWeight').textContent=
            weight(summary.total_gross_weight)+' g';

        document.getElementById('lessWeight').textContent=
            weight(summary.total_less_weight)+' g';

        document.getElementById('averageRate').textContent=
            '₹'+money(summary.average_rate);
    }

    function renderCustomers(rows){
        const current=customerFilter.value;

        customerFilter.innerHTML=
            '<option value="0">All Customers</option>'+
            rows.map(row=>`
                <option value="${Number(row.id)}">
                    ${escapeHtml(row.customer_name)}
                    ${row.customer_code?' ('+escapeHtml(row.customer_code)+')':''}
                </option>
            `).join('');

        customerFilter.value=current||'0';
    }

    function renderEntries(rows){
        if(!rows.length){
            entryRows.innerHTML=
                '<tr><td colspan="15" class="empty-state">No old silver entries found.</td></tr>';
            return;
        }

        entryRows.innerHTML=rows.map(row=>`
            <tr>
                <td><strong>${escapeHtml(row.entry_no||'')}</strong></td>
                <td>${escapeHtml(row.entry_date_display||'-')}</td>
                <td>
                    <strong>${escapeHtml(row.customer_name||'-')}</strong>
                    <div class="text-muted">${escapeHtml(row.customer_code||'')}</div>
                </td>
                <td>${escapeHtml(row.customer_mobile_display||'-')}</td>
                <td>
                    <span class="item-pill">${Number(row.item_count||0)} items</span>
                    ${row.item_names?'<div class="text-muted mt-1">'+escapeHtml(row.item_names)+'</div>':''}
                </td>
                <td>${weight(row.total_gross_weight)}</td>
                <td>${weight(row.total_less_weight)}</td>
                <td><strong>${weight(row.total_net_weight)}</strong></td>
                <td>₹${money(row.rate_per_gram)}</td>
                <td>${money(row.deduction_percent)}%</td>
                <td class="text-danger">₹${money(row.deduction_amount)}</td>
                <td><span class="money-pill">₹${money(row.final_amount)}</span></td>
                <td>
                    <span class="status-pill ${statusClass(row.adjustment_type)}">
                        ${escapeHtml(row.adjustment_type||'Pending')}
                    </span>
                </td>
                <td>${Number(row.linked_sale_id||0)>0?'Linked':'No'}</td>
                <td>
                    <div class="d-flex gap-1">
                        <a class="btn btn-sm btn-soft" href="old-silver-view.php?id=${Number(row.id)}">
                            <i class="fa-solid fa-eye"></i>
                        </a>
                        ${row.adjustment_type==='Pending'
                            ? `<a class="btn btn-sm btn-soft" href="old-silver-settle.php?id=${Number(row.id)}"><i class="fa-solid fa-check"></i></a>`
                            : ''}
                    </div>
                </td>
            </tr>
        `).join('');
    }

    function renderTotals(summary){
        if(!Number(summary.total_entries||0)){
            entryTotals.innerHTML='';
            return;
        }

        entryTotals.innerHTML=`
            <tr>
                <td colspan="5" class="text-end"><strong>Totals:</strong></td>
                <td><strong>${weight(summary.total_gross_weight)}</strong></td>
                <td><strong>${weight(summary.total_less_weight)}</strong></td>
                <td><strong>${weight(summary.total_net_weight)}</strong></td>
                <td colspan="3"></td>
                <td><strong>₹${money(summary.total_final_amount)}</strong></td>
                <td colspan="3"></td>
            </tr>
        `;
    }

    function renderItems(rows){
        itemPanel.classList.toggle('d-none',!rows.length);

        if(!rows.length){
            itemRows.innerHTML='';
            return;
        }

        itemRows.innerHTML=rows.map(row=>`
            <tr>
                <td><strong>${escapeHtml(row.item_name||'-')}</strong></td>
                <td>${escapeHtml(row.purity||'N/A')}</td>
                <td>${weight(row.gross_weight)}</td>
                <td>${weight(row.less_weight)}</td>
                <td><strong>${weight(row.net_weight)}</strong></td>
                <td>${Number(row.appearances||0)} times</td>
            </tr>
        `).join('');
    }

    async function loadReport(){
        entryRows.innerHTML=
            '<tr><td colspan="15" class="empty-state">Loading report...</td></tr>';

        try{
            const params=new URLSearchParams(new FormData(form));
            params.set('action','list');

            const response=await fetch(apiUrl+'?'+params.toString(),{
                headers:{Accept:'application/json'}
            });

            const result=await response.json();

            if(!response.ok||!result.success){
                throw new Error(result.message||'Unable to load report.');
            }

            renderCustomers(result.customers||[]);
            setSummary(result.summary||{});
            renderEntries(result.rows||[]);
            renderTotals(result.summary||{});
            renderItems(result.item_summary||[]);

            document.getElementById('reportPeriod').textContent=
                'Period: '+result.period.start_display+' to '+result.period.end_display;
        }catch(error){
            entryRows.innerHTML=
                '<tr><td colspan="15" class="empty-state">Unable to load report.</td></tr>';
            toast(error.message||'Unable to load report.');
        }
    }

    dateRange.addEventListener('change',toggleCustomDates);

    form.addEventListener('submit',event=>{
        event.preventDefault();
        loadReport();
    });

    document.getElementById('refreshReport')
        .addEventListener('click',loadReport);

    document.getElementById('resetFilters')
        .addEventListener('click',()=>{
            form.reset();
            dateRange.value='today';
            toggleCustomDates();
            loadReport();
        });

    document.querySelectorAll('[data-export]').forEach(button=>{
        button.addEventListener('click',()=>{
            const params=new URLSearchParams(new FormData(form));
            params.set(
                'action',
                button.dataset.export==='excel'
                    ? 'export_excel'
                    : 'export_pdf'
            );

            window.location.href=apiUrl+'?'+params.toString();
        });
    });

    toggleCustomDates();
    loadReport();
})();
</script>
</body>
</html>
