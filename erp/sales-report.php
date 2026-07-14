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

if (!function_exists('canAccessSalesReport')) {
    function canAccessSalesReport(mysqli $conn): bool
    {
        if (($_SESSION['user_type'] ?? '') === 'Platform Admin') {
            return true;
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

        $sessionPermissions = $_SESSION['permissions'] ?? [];
        foreach ([
            'perm.sales_report',
            'perm.reports.sales',
            'perm.sales',
            'perm.reports',
        ] as $code) {
            if (
                isset($sessionPermissions[$code]['can_open']) &&
                (int)$sessionPermissions[$code]['can_open'] === 1
            ) {
                return true;
            }
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);

        if (
            $userId > 0 &&
            tableExists($conn, 'users') &&
            tableExists($conn, 'roles')
        ) {
            $stmt = $conn->prepare(
                "SELECT LOWER(TRIM(COALESCE(r.role_name,r.role_code,''))) AS resolved_role
                 FROM users u
                 INNER JOIN roles r ON r.id=u.role_id
                 WHERE u.id=?
                 LIMIT 1"
            );

            if ($stmt) {
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (
                    in_array(
                        strtolower(trim((string)($row['resolved_role'] ?? ''))),
                        $allowedRoles,
                        true
                    )
                ) {
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

if (!canAccessSalesReport($conn)) {
    http_response_code(403);
    die('Access denied. You do not have permission to open Sales Report.');
}

if (!tableExists($conn, 'sales')) {
    die('Required table `sales` was not found.');
}

$pageTitle = 'Sales Report';
$page_title = 'Sales Report';
$currentPage = 'sales-report';

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
        'SELECT * FROM business_theme_settings WHERE business_id=? LIMIT 1'
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

/*
 * Change only these two paths when your existing bill pages use
 * different filenames.
 */
$saleViewPage = 'sale-view.php';
$salePrintPage = 'sale-print.php';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo h($businessName); ?> - Sales Report</title>
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
.stat-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin-bottom:10px}
.stat-card{background:var(--card-bg);border:1px solid var(--border-color);border-radius:var(--radius);padding:12px 14px;display:flex;align-items:center;gap:12px;min-height:82px}
.stat-icon{width:42px;height:42px;display:grid;place-items:center;border-radius:10px;background:var(--primary-soft);color:var(--primary-dark);font-size:16px}
.stat-label{font-size:10px;color:var(--muted-color)}.stat-value{font-size:20px;font-weight:800;margin-top:4px}
.mini-stat-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:8px;margin-bottom:10px}
.mini-stat{background:var(--card-bg);border:1px solid var(--border-color);border-radius:var(--radius);padding:10px 12px}
.mini-stat-label{font-size:9px;color:var(--muted-color)}.mini-stat-value{font-size:13px;font-weight:800;margin-top:4px}
.panel{background:var(--card-bg);border:1px solid var(--border-color);border-radius:var(--radius);overflow:hidden;margin-bottom:10px}
.panel-head{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:11px 13px;border-bottom:1px solid var(--border-color)}
.panel-title{font-size:12px;font-weight:800}.panel-subtitle{font-size:9px;color:var(--muted-color);margin-top:2px}.panel-body{padding:13px}
.filter-grid{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:10px}.span-2{grid-column:span 2}.span-3{grid-column:span 3}.span-4{grid-column:span 4}.span-6{grid-column:span 6}.span-12{grid-column:span 12}
.field-label{display:block;font-size:9px;font-weight:700;text-transform:uppercase;color:var(--muted-color);margin-bottom:4px}
.form-control,.form-select{min-height:36px;font-size:10px;border-radius:9px;border-color:var(--border-color);background:var(--card-bg);color:var(--text-color)}
.btn-theme{background:linear-gradient(135deg,var(--primary),var(--primary-dark));border:0;color:#fff;border-radius:9px;font-size:10px;font-weight:700;min-height:36px;padding:8px 13px}
.btn-light-custom{border:1px solid var(--border-color);background:var(--card-bg);color:var(--text-color);border-radius:9px;font-size:10px;font-weight:700;min-height:36px;padding:8px 12px}
.compact-table{margin:0;font-size:10px}.compact-table th{font-size:9px;text-transform:uppercase;color:var(--muted-color);background:color-mix(in srgb,var(--muted-color) 6%,transparent);padding:10px 11px;white-space:nowrap;border-color:var(--border-color)}.compact-table td{padding:10px 11px;vertical-align:middle;background:var(--card-bg)!important;color:var(--text-color);border-color:var(--border-color);white-space:nowrap}
.status-badge{display:inline-flex;border-radius:999px;padding:4px 8px;font-size:8px;font-weight:800}.status-paid{background:#eaf8f0;color:#168449}.status-partial{background:#fff5d9;color:#a96b00}.status-unpaid{background:#fdecec;color:#c0392b}
.amount-positive{font-weight:800;color:#168449}.amount-negative{font-weight:800;color:#c0392b}
.action-buttons{display:flex;align-items:center;justify-content:flex-end;gap:6px}
.action-icon-btn{width:30px;height:30px;padding:0;display:grid;place-items:center;border:1px solid var(--border-color);border-radius:8px;background:var(--card-bg);color:var(--text-color);font-size:10px;text-decoration:none;transition:.18s}
.action-icon-btn:hover{background:var(--primary-soft);border-color:var(--primary);color:var(--primary-dark)}
.action-icon-btn.view-action{color:#078ab3}
.action-icon-btn.print-action{color:var(--text-color)}
.action-column{position:sticky;right:0;z-index:2;background:var(--card-bg)!important;box-shadow:-5px 0 9px rgba(0,0,0,.035)}
.compact-table thead .action-column{z-index:3}
.empty-state{padding:42px 20px;text-align:center;color:var(--muted-color)}
.theme-toast{position:fixed;right:18px;top:78px;z-index:20000;display:flex;align-items:center;gap:9px;min-width:260px;max-width:420px;padding:11px 14px;border-radius:10px;color:#fff;font-size:11px;font-weight:600;box-shadow:0 14px 35px rgba(0,0,0,.22);opacity:0;transform:translateY(-10px);transition:.22s}.theme-toast.show{opacity:1;transform:translateY(0)}.theme-toast-error{background:#c0392b}
body.dark-mode,body[data-theme="dark"],html.dark-mode body,html[data-theme="dark"] body{--page-bg:#0f151b;--card-bg:#182129;--text-color:#f3f6f8;--muted-color:#9aa7b3;--border-color:#2c3944}
@media(max-width:1199px){.stat-grid{grid-template-columns:repeat(2,1fr)}.mini-stat-grid{grid-template-columns:repeat(3,1fr)}.filter-grid{grid-template-columns:repeat(2,1fr)}.span-2,.span-3,.span-4,.span-6,.span-12{grid-column:span 1}}
@media(max-width:767px){.stat-grid,.mini-stat-grid,.filter-grid{grid-template-columns:1fr}.page-heading,.panel-head{align-items:flex-start;flex-direction:column}}
@media print{.sidebar,.topbar,.page-heading .d-flex,.panel.filters-panel,footer,.action-column{display:none!important}.app-main{margin-left:0!important}.content-wrap{padding:0!important}.panel,.stat-card,.mini-stat{box-shadow:none!important}}
</style>
</head>
<body>
<?php include 'includes/sidebar.php'; ?>
<main class="app-main">
<?php include 'includes/nav.php'; ?>
<div class="content-wrap">
    <div class="page-heading">
        <div>
            <h1 class="page-title">Sales Report</h1>
            <div class="page-subtitle"><?php echo h($businessName); ?> · Sales, tax, collection and balance analysis</div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-light-custom" id="exportCsv"><i class="fa-solid fa-file-csv me-1"></i>Export CSV</button>
            <button type="button" class="btn btn-light-custom" onclick="window.print()"><i class="fa-solid fa-print me-1"></i>Print</button>
        </div>
    </div>

    <div class="stat-grid">
        <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-file-invoice"></i></div><div><div class="stat-label">Total Bills</div><div class="stat-value" id="totalBills">0</div></div></div>
        <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-indian-rupee-sign"></i></div><div><div class="stat-label">Grand Total</div><div class="stat-value" id="grandTotal">₹0.00</div></div></div>
        <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-circle-check"></i></div><div><div class="stat-label">Paid Amount</div><div class="stat-value" id="paidAmount">₹0.00</div></div></div>
        <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-clock"></i></div><div><div class="stat-label">Balance Amount</div><div class="stat-value" id="balanceAmount">₹0.00</div></div></div>
    </div>

    <div class="mini-stat-grid">
        <div class="mini-stat"><div class="mini-stat-label">Subtotal</div><div class="mini-stat-value" id="subtotal">₹0.00</div></div>
        <div class="mini-stat"><div class="mini-stat-label">Discount</div><div class="mini-stat-value" id="discount">₹0.00</div></div>
        <div class="mini-stat"><div class="mini-stat-label">Taxable</div><div class="mini-stat-value" id="taxable">₹0.00</div></div>
        <div class="mini-stat"><div class="mini-stat-label">Total GST</div><div class="mini-stat-value" id="totalGst">₹0.00</div></div>
        <div class="mini-stat"><div class="mini-stat-label">CGST</div><div class="mini-stat-value" id="cgst">₹0.00</div></div>
        <div class="mini-stat"><div class="mini-stat-label">SGST / IGST</div><div class="mini-stat-value" id="sgstIgst">₹0.00</div></div>
    </div>

    <div class="panel filters-panel">
        <div class="panel-head">
            <div><div class="panel-title">Sales Report Filters</div><div class="panel-subtitle">Filter sales by date, customer, bill type, payment status, or search text.</div></div>
            <button type="button" class="btn btn-light-custom" id="refreshReport"><i class="fa-solid fa-rotate"></i></button>
        </div>
        <div class="panel-body">
            <form id="filterForm" class="filter-grid">
                <div class="span-2"><label class="field-label">From Date</label><input type="date" name="from_date" class="form-control"></div>
                <div class="span-2"><label class="field-label">To Date</label><input type="date" name="to_date" class="form-control"></div>
                <div class="span-2"><label class="field-label">Customer</label><select name="customer_id" id="customerFilter" class="form-select"><option value="0">All Customers</option></select></div>
                <div class="span-2"><label class="field-label">Bill Type</label><select name="bill_type" id="billTypeFilter" class="form-select"><option value="">All Types</option></select></div>
                <div class="span-2"><label class="field-label">Payment Status</label><select name="payment_status" id="paymentStatusFilter" class="form-select"><option value="">All Status</option></select></div>
                <div class="span-2"><label class="field-label">Search</label><input type="search" name="search" class="form-control" placeholder="Bill no, customer, mobile..."></div>
                <div class="span-12 d-flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-theme"><i class="fa-solid fa-magnifying-glass me-1"></i>Show Report</button>
                    <button type="button" class="btn btn-light-custom" id="resetFilters">Reset</button>
                    <span class="ms-auto align-self-center page-subtitle" id="periodLabel"></span>
                </div>
            </form>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head"><div><div class="panel-title">Sales Report</div><div class="panel-subtitle">All matching bills and payment details.</div></div></div>
        <div id="loadingState" class="empty-state"><i class="fa-solid fa-spinner fa-spin mb-2"></i><div>Loading sales report...</div></div>
        <div id="tableWrap" class="table-responsive d-none">
            <table class="table compact-table">
                <thead><tr>
                    <th>#</th><th>Bill No</th><th>Date</th><th>Customer</th><th>Mobile</th><th>Bill Type</th><th>Method</th>
                    <th>Subtotal</th><th>Discount</th><th>Taxable</th><th>CGST</th><th>SGST</th><th>IGST</th><th>Round Off</th>
                    <th>Grand Total</th><th>Paid</th><th>Balance</th><th>Status</th><th class="text-end action-column">Action</th>
                </tr></thead>
                <tbody id="salesTableBody"></tbody>
            </table>
        </div>
        <div id="emptyState" class="empty-state d-none">No sales found for the selected filters.</div>
    </div>

    <?php include 'includes/footer.php'; ?>
</div>
</main>
<?php include 'includes/script.php'; ?>
<script src="assets/js/script.js"></script>
<script>
(function(){
    'use strict';

    const saleViewPage=<?php echo json_encode($saleViewPage); ?>;
    const salePrintPage=<?php echo json_encode($salePrintPage); ?>;

    const filterForm=document.getElementById('filterForm');
    const customerFilter=document.getElementById('customerFilter');
    const billTypeFilter=document.getElementById('billTypeFilter');
    const paymentStatusFilter=document.getElementById('paymentStatusFilter');
    const tableBody=document.getElementById('salesTableBody');
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

    function escapeHtml(value){
        const div=document.createElement('div');
        div.textContent=value??'';
        return div.innerHTML;
    }

    function money(value){
        return Number(value||0).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2});
    }

    function saleActionUrl(basePage,row){
        const params=new URLSearchParams();
        params.set('id',String(Number(row.id)||0));

        if(row.bill_no){
            params.set('bill_no',String(row.bill_no));
        }

        return basePage+'?'+params.toString();
    }

    async function requestJson(url){
        const response=await fetch(url,{credentials:'same-origin',headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'}});
        const raw=await response.text();
        let result;
        try{result=JSON.parse(raw)}catch(error){throw new Error(raw?'Server returned invalid output: '+raw.substring(0,180):'Server returned an empty response.')}
        if(!response.ok||!result.success)throw new Error(result.message||'Request failed.');
        return result;
    }

    function renderBootstrap(result){
        customerFilter.innerHTML='<option value="0">All Customers</option>'+
            (result.customers||[]).map(row=>`<option value="${Number(row.id)}">${escapeHtml(row.customer_name)}${row.customer_code?' ('+escapeHtml(row.customer_code)+')':''}</option>`).join('');

        billTypeFilter.innerHTML='<option value="">All Types</option>'+
            (result.bill_types||[]).map(value=>`<option value="${escapeHtml(value)}">${escapeHtml(value)}</option>`).join('');

        paymentStatusFilter.innerHTML='<option value="">All Status</option>'+
            (result.payment_statuses||[]).map(value=>`<option value="${escapeHtml(value)}">${escapeHtml(value)}</option>`).join('');

        filterForm.from_date.value=result.defaults.from_date||'';
        filterForm.to_date.value=result.defaults.to_date||'';
    }

    function statusClass(status){
        const value=String(status||'').toLowerCase();
        if(value==='paid')return 'status-paid';
        if(value==='partial')return 'status-partial';
        return 'status-unpaid';
    }

    function renderReport(result){
        const s=result.summary||{};
        document.getElementById('totalBills').textContent=Number(s.total_bills||0);
        document.getElementById('grandTotal').textContent='₹'+money(s.grand_total);
        document.getElementById('paidAmount').textContent='₹'+money(s.paid_amount);
        document.getElementById('balanceAmount').textContent='₹'+money(s.balance_amount);
        document.getElementById('subtotal').textContent='₹'+money(s.subtotal);
        document.getElementById('discount').textContent='₹'+money(s.discount_amount);
        document.getElementById('taxable').textContent='₹'+money(s.taxable_amount);
        document.getElementById('totalGst').textContent='₹'+money(Number(s.cgst_amount||0)+Number(s.sgst_amount||0)+Number(s.igst_amount||0));
        document.getElementById('cgst').textContent='₹'+money(s.cgst_amount);
        document.getElementById('sgstIgst').textContent='₹'+money(Number(s.sgst_amount||0)+Number(s.igst_amount||0));
        document.getElementById('periodLabel').textContent='Period: '+result.period.from_display+' to '+result.period.to_display;

        loading.classList.add('d-none');
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
            <td>${index+1}</td>
            <td><strong>${escapeHtml(row.bill_no||'')}</strong></td>
            <td>${escapeHtml(row.bill_date_display||'—')}${row.bill_time_display?'<div class="page-subtitle">'+escapeHtml(row.bill_time_display)+'</div>':''}</td>
            <td>${escapeHtml(row.customer_name||'Walk-in Customer')}</td>
            <td>${escapeHtml(row.customer_mobile||'—')}</td>
            <td>${escapeHtml(row.bill_type||'—')}</td>
            <td>${escapeHtml(row.method_name||'—')}</td>
            <td>₹${money(row.subtotal)}</td>
            <td>₹${money(row.discount_amount)}</td>
            <td>₹${money(row.taxable_amount)}</td>
            <td>₹${money(row.cgst_amount)}</td>
            <td>₹${money(row.sgst_amount)}</td>
            <td>₹${money(row.igst_amount)}</td>
            <td>₹${money(row.round_off)}</td>
            <td><strong>₹${money(row.grand_total)}</strong></td>
            <td class="amount-positive">₹${money(row.paid_amount)}</td>
            <td class="amount-negative">₹${money(row.balance_amount)}</td>
            <td><span class="status-badge ${statusClass(row.payment_status)}">${escapeHtml(row.payment_status||'Unpaid')}</span></td>
            <td class="action-column">
                <div class="action-buttons">
                    <a
                        href="${escapeHtml(saleActionUrl(saleViewPage,row))}"
                        class="action-icon-btn view-action"
                        title="View Bill"
                        aria-label="View Bill"
                    >
                        <i class="fa-solid fa-eye"></i>
                    </a>
                    <a
                        href="${escapeHtml(saleActionUrl(salePrintPage,row))}"
                        class="action-icon-btn print-action"
                        title="Print Bill"
                        aria-label="Print Bill"
                        target="_blank"
                        rel="noopener"
                    >
                        <i class="fa-solid fa-print"></i>
                    </a>
                </div>
            </td>
        </tr>`).join('');
    }

    async function loadBootstrap(){
        try{
            const result=await requestJson('api/sales-report.php?action=bootstrap');
            renderBootstrap(result);
        }catch(error){toast(error.message);}
    }

    async function loadReport(){
        loading.classList.remove('d-none');
        tableWrap.classList.add('d-none');
        empty.classList.add('d-none');

        try{
            const params=new URLSearchParams(new FormData(filterForm));
            params.set('action','list');
            const result=await requestJson('api/sales-report.php?'+params.toString());
            renderReport(result);
        }catch(error){
            loading.classList.add('d-none');
            empty.classList.remove('d-none');
            empty.textContent=error.message;
            toast(error.message);
        }
    }

    filterForm.addEventListener('submit',event=>{event.preventDefault();loadReport();});
    document.getElementById('refreshReport').addEventListener('click',loadReport);
    document.getElementById('resetFilters').addEventListener('click',async()=>{
        filterForm.reset();
        await loadBootstrap();
        await loadReport();
    });
    document.getElementById('exportCsv').addEventListener('click',()=>{
        const params=new URLSearchParams(new FormData(filterForm));
        params.set('action','export');
        window.location.href='api/sales-report.php?'+params.toString();
    });

    (async()=>{await loadBootstrap();await loadReport();})();
})();
</script>
</body>
</html>
