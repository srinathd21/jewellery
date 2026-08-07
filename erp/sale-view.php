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

if (!function_exists('canAccessSaleView')) {
    function canAccessSaleView(mysqli $conn): bool
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

        $permissions = $_SESSION['permissions'] ?? [];

        foreach ([
            'perm.sales',
            'perm.sales_report',
            'perm.reports.sales',
            'perm.reports',
        ] as $code) {
            if (
                isset($permissions[$code]['can_view']) &&
                (int)$permissions[$code]['can_view'] === 1
            ) {
                return true;
            }

            if (
                isset($permissions[$code]['can_open']) &&
                (int)$permissions[$code]['can_open'] === 1
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
$saleId = (int)($_GET['id'] ?? 0);

if ($userId <= 0) {
    header('Location: login.php');
    exit;
}

if ($businessId <= 0) {
    http_response_code(403);
    die('A valid business must be selected.');
}

if ($saleId <= 0) {
    http_response_code(422);
    die('Invalid sale selected.');
}

if (!canAccessSaleView($conn)) {
    http_response_code(403);
    die('Access denied. You do not have permission to view this sale.');
}

if (!tableExists($conn, 'sales') || !tableExists($conn, 'sale_items')) {
    die('Required sales tables were not found.');
}

$pageTitle = 'Sales View';
$page_title = 'Sales View';
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

$backUrl = 'sales-report.php';
$printUrl = 'invoice.php?id=' . $saleId;
$editUrl = 'billing.php?edit_id=' . $saleId;
$estimatePrintUrl = 'estimate-print.php?id=' . $saleId;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo h($businessName); ?> - Sales View</title>
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
.stat-label{font-size:10px;color:var(--muted-color)}.stat-value{font-size:19px;font-weight:800;margin-top:4px;word-break:break-word}
.panel{background:var(--card-bg);border:1px solid var(--border-color);border-radius:var(--radius);overflow:hidden;margin-bottom:10px}
.panel-head{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:11px 13px;border-bottom:1px solid var(--border-color)}
.panel-title{font-size:12px;font-weight:800}.panel-subtitle{font-size:9px;color:var(--muted-color);margin-top:2px}.panel-body{padding:13px}
.btn-theme{background:linear-gradient(135deg,var(--primary),var(--primary-dark));border:0;color:#fff;border-radius:9px;font-size:10px;font-weight:700;min-height:36px;padding:8px 13px}
.btn-light-custom{border:1px solid var(--border-color);background:var(--card-bg);color:var(--text-color);border-radius:9px;font-size:10px;font-weight:700;min-height:36px;padding:8px 12px;text-decoration:none}
.info-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
.info-card{border:1px solid var(--border-color);border-radius:var(--radius);padding:13px;background:var(--card-bg)}
.info-title{font-size:11px;font-weight:800;margin-bottom:10px}.info-row{display:grid;grid-template-columns:110px minmax(0,1fr);gap:10px;padding:6px 0;border-bottom:1px dashed var(--border-color)}.info-row:last-child{border-bottom:0}.info-label{font-size:9px;color:var(--muted-color)}.info-value{font-size:10px;font-weight:700;word-break:break-word}
.logo-box{display:flex;gap:12px;align-items:flex-start}.company-logo{max-width:82px;max-height:65px;object-fit:contain}
.status-badge,.type-badge{display:inline-flex;border-radius:999px;padding:4px 8px;font-size:8px;font-weight:800}.status-paid{background:#eaf8f0;color:#168449}.status-partial{background:#fff5d9;color:#a96b00}.status-unpaid{background:#fdecec;color:#c0392b}.type-badge{background:var(--primary-soft);color:var(--primary-dark)}
.compact-table{margin:0;font-size:10px}.compact-table th{font-size:9px;text-transform:uppercase;color:var(--muted-color);background:color-mix(in srgb,var(--muted-color) 6%,transparent);padding:10px 11px;white-space:nowrap;border-color:var(--border-color)}.compact-table td{padding:10px 11px;vertical-align:middle;background:var(--card-bg)!important;color:var(--text-color);border-color:var(--border-color);white-space:nowrap}
.content-grid{display:grid;grid-template-columns:minmax(0,2fr) minmax(280px,1fr);gap:10px}
.summary-table{width:100%;font-size:10px}.summary-table th,.summary-table td{padding:9px 10px;border-bottom:1px solid var(--border-color)}.summary-table th{text-align:left;color:var(--muted-color)}.summary-table td{text-align:right;font-weight:700}.summary-table .grand-total th,.summary-table .grand-total td{font-size:13px;background:var(--primary-soft);color:var(--primary-dark);font-weight:800}
.quick-actions{display:grid;gap:8px}.empty-state{padding:38px 18px;text-align:center;color:var(--muted-color)}
.theme-toast{position:fixed;right:18px;top:78px;z-index:20000;display:flex;align-items:center;gap:9px;min-width:260px;max-width:420px;padding:11px 14px;border-radius:10px;color:#fff;font-size:11px;font-weight:600;box-shadow:0 14px 35px rgba(0,0,0,.22);opacity:0;transform:translateY(-10px);transition:.22s}.theme-toast.show{opacity:1;transform:translateY(0)}.theme-toast-error{background:#c0392b}
body.dark-mode,body[data-theme="dark"],html.dark-mode body,html[data-theme="dark"] body{--page-bg:#0f151b;--card-bg:#182129;--text-color:#f3f6f8;--muted-color:#9aa7b3;--border-color:#2c3944}
@media(max-width:1199px){.stat-grid{grid-template-columns:repeat(2,1fr)}.info-grid{grid-template-columns:1fr}.content-grid{grid-template-columns:1fr}}
@media(max-width:767px){.stat-grid{grid-template-columns:1fr}.page-heading,.panel-head{align-items:flex-start;flex-direction:column}.info-row{grid-template-columns:1fr}.page-heading .d-flex{width:100%}.page-heading .btn{flex:1}}
@media print{.sidebar,.topbar,.page-heading,.quick-actions-panel,footer{display:none!important}.app-main{margin-left:0!important}.content-wrap{padding:0!important}.panel,.stat-card,.info-card{box-shadow:none!important}}
</style>
</head>
<body>
<?php include 'includes/sidebar.php'; ?>
<main class="app-main">
<?php include 'includes/nav.php'; ?>
<div class="content-wrap">
    <div class="page-heading">
        <div>
            <h1 class="page-title">Sales View</h1>
            <div class="page-subtitle" id="pageSubtitle">Loading sale details...</div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="<?php echo h($backUrl); ?>" class="btn btn-light-custom"><i class="fa-solid fa-arrow-left me-1"></i>Back</a>
            <a href="<?php echo h($printUrl); ?>" target="_blank" class="btn btn-light-custom"><i class="fa-solid fa-print me-1"></i>Print</a>
            <a href="<?php echo h($editUrl); ?>" class="btn btn-theme" id="editBillButton"><i class="fa-solid fa-pen me-1"></i>Edit Bill</a>
        </div>
    </div>

    <div id="loadingState" class="panel"><div class="empty-state"><i class="fa-solid fa-spinner fa-spin mb-2"></i><div>Loading sale details...</div></div></div>
    <div id="saleContent" class="d-none">
        <div class="stat-grid">
            <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-file-invoice"></i></div><div><div class="stat-label">Bill No</div><div class="stat-value" id="billNo">—</div></div></div>
            <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-indian-rupee-sign"></i></div><div><div class="stat-label">Grand Total</div><div class="stat-value" id="grandTotal">₹0.00</div></div></div>
            <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-circle-check"></i></div><div><div class="stat-label">Paid Amount</div><div class="stat-value" id="paidAmount">₹0.00</div></div></div>
            <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-clock"></i></div><div><div class="stat-label">Balance Amount</div><div class="stat-value" id="balanceAmount">₹0.00</div></div></div>
        </div>

        <div class="panel">
            <div class="panel-head">
                <div><div class="panel-title">Invoice Details</div><div class="panel-subtitle">Company, customer, bill and payment information.</div></div>
                <div class="d-flex flex-wrap gap-2">
                    <span class="type-badge" id="billTypeBadge">—</span>
                    <span class="status-badge status-unpaid" id="paymentStatusBadge">Unpaid</span>
                    <span class="type-badge" id="workflowStatusBadge">—</span>
                </div>
            </div>
            <div class="panel-body">
                <div class="info-grid">
                    <div class="info-card">
                        <div class="info-title">Company Details</div>
                        <div class="logo-box">
                            <img id="companyLogo" class="company-logo d-none" alt="Company Logo">
                            <div class="flex-grow-1">
                                <div class="info-value mb-2" id="companyName">Company</div>
                                <div class="page-subtitle mb-2" id="businessType"></div>
                                <div class="page-subtitle" id="companyAddress"></div>
                                <div class="page-subtitle mt-2" id="companyMobile"></div>
                                <div class="page-subtitle" id="companyGstin"></div>
                            </div>
                        </div>
                    </div>

                    <div class="info-card">
                        <div class="info-title">Customer Details</div>
                        <div class="info-row"><div class="info-label">Customer</div><div class="info-value" id="customerName">Walk-in Customer</div></div>
                        <div class="info-row"><div class="info-label">Mobile</div><div class="info-value" id="customerMobile">—</div></div>
                        <div class="info-row"><div class="info-label">Code</div><div class="info-value" id="customerCode">—</div></div>
                        <div class="info-row"><div class="info-label">GSTIN</div><div class="info-value" id="customerGstin">—</div></div>
                        <div class="info-row"><div class="info-label">Address</div><div class="info-value" id="customerAddress">—</div></div>
                    </div>

                    <div class="info-card">
                        <div class="info-title">Bill Details</div>
                        <div class="info-row"><div class="info-label">Bill No</div><div class="info-value" id="billNoDetail">—</div></div>
                        <div class="info-row"><div class="info-label">Bill Date</div><div class="info-value" id="billDate">—</div></div>
                        <div class="info-row"><div class="info-label">Bill Time</div><div class="info-value" id="billTime">—</div></div>
                        <div class="info-row"><div class="info-label">Bill Type</div><div class="info-value" id="billType">—</div></div>
                        <div class="info-row"><div class="info-label">Payment Method</div><div class="info-value" id="paymentMethod">—</div></div>
                        <div class="info-row"><div class="info-label">Reference</div><div class="info-value" id="paymentReference">—</div></div>
                    </div>
                </div>
                <div id="notesWrap" class="info-card mt-3 d-none"><div class="info-title">Notes</div><div class="page-subtitle" id="notesText"></div></div>
            </div>
        </div>

        <div class="stat-grid">
            <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-box"></i></div><div><div class="stat-label">Total Items</div><div class="stat-value" id="itemCount">0</div></div></div>
            <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-layer-group"></i></div><div><div class="stat-label">Total Qty</div><div class="stat-value" id="totalQty">0.00</div></div></div>
            <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-weight-scale"></i></div><div><div class="stat-label">Net Weight</div><div class="stat-value" id="netWeight">0.00</div></div></div>
            <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-coins"></i></div><div><div class="stat-label">Items Total</div><div class="stat-value" id="itemsTotal">₹0.00</div></div></div>
        </div>

        <div class="content-grid">
            <div>
                <div class="panel">
                    <div class="panel-head"><div><div class="panel-title">Item Details</div><div class="panel-subtitle">Products, weights, charges, tax and totals.</div></div></div>
                    <div class="table-responsive">
                        <table class="table compact-table">
                            <thead><tr>
                                <th>#</th><th>Item</th><th>Purity</th><th>Qty</th><th>Gross Wt</th><th>Less Wt</th><th>Net Wt</th>
                                <th>Rate</th><th>Making</th><th>Taxable</th><th>GST</th><th>Total</th>
                            </tr></thead>
                            <tbody id="itemTableBody"></tbody>
                        </table>
                    </div>
                    <div id="itemEmpty" class="empty-state d-none">No item records found.</div>
                </div>

                <div class="panel d-none" id="paymentPanel">
                    <div class="panel-head"><div><div class="panel-title">Payment Split</div><div class="panel-subtitle">Payment methods and references.</div></div></div>
                    <div class="table-responsive">
                        <table class="table compact-table">
                            <thead><tr><th>Method</th><th>Reference</th><th>Date</th><th>Amount</th></tr></thead>
                            <tbody id="paymentTableBody"></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div>
                <div class="panel">
                    <div class="panel-head"><div><div class="panel-title">Bill Summary</div><div class="panel-subtitle">Final calculation details.</div></div></div>
                    <div class="panel-body p-0">
                        <table class="summary-table">
                            <tr><th>Subtotal</th><td id="sumSubtotal">₹0.00</td></tr>
                            <tr><th>Discount</th><td id="sumDiscount">₹0.00</td></tr>
                            <tr><th>Taxable</th><td id="sumTaxable">₹0.00</td></tr>
                            <tr><th>CGST</th><td id="sumCgst">₹0.00</td></tr>
                            <tr><th>SGST</th><td id="sumSgst">₹0.00</td></tr>
                            <tr><th>IGST</th><td id="sumIgst">₹0.00</td></tr>
                            <tr><th>Round Off</th><td id="sumRoundOff">₹0.00</td></tr>
                            <tr class="grand-total"><th>Grand Total</th><td id="sumGrandTotal">₹0.00</td></tr>
                            <tr><th>Paid</th><td id="sumPaid">₹0.00</td></tr>
                            <tr><th>Balance</th><td id="sumBalance">₹0.00</td></tr>
                            <tr><th>Payment Status</th><td><span class="status-badge status-unpaid" id="summaryStatus">Unpaid</span></td></tr>
                        </table>
                    </div>
                </div>

                <div class="panel quick-actions-panel">
                    <div class="panel-head"><div><div class="panel-title">Quick Actions</div><div class="panel-subtitle">Print, edit or return to report.</div></div></div>
                    <div class="panel-body quick-actions">
                        <a href="<?php echo h($printUrl); ?>" target="_blank" class="btn btn-light-custom"><i class="fa-solid fa-print me-1"></i>Print Invoice</a>
                        <a href="<?php echo h($estimatePrintUrl); ?>" target="_blank" class="btn btn-light-custom d-none" id="estimatePrintButton"><i class="fa-solid fa-file-lines me-1"></i>Print Estimate</a>
                        <a href="<?php echo h($editUrl); ?>" class="btn btn-theme" id="quickEditButton"><i class="fa-solid fa-pen me-1"></i>Edit Bill</a>
                        <a href="<?php echo h($backUrl); ?>" class="btn btn-light-custom"><i class="fa-solid fa-arrow-left me-1"></i>Back to Report</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php include 'includes/footer.php'; ?>
</div>
</main>
<?php include 'includes/script.php'; ?>
<script src="assets/js/script.js"></script>
<script>
(function(){
    'use strict';

    const saleId=<?php echo (int)$saleId; ?>;

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

    function setText(id,value){
        const element=document.getElementById(id);
        if(element)element.textContent=value??'—';
    }

    function statusClass(status){
        const value=String(status||'').toLowerCase();
        if(value==='paid')return 'status-paid';
        if(value==='partial')return 'status-partial';
        return 'status-unpaid';
    }

    function applyStatus(element,status){
        if(!element)return;
        element.className='status-badge '+statusClass(status);
        element.textContent=status||'Unpaid';
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
        },3300);
    }

    async function requestJson(url){
        const response=await fetch(url,{
            credentials:'same-origin',
            headers:{
                Accept:'application/json',
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
                    ? 'Server returned invalid output: '+raw.substring(0,180)
                    : 'Server returned an empty response.'
            );
        }

        if(!response.ok||!result.success){
            throw new Error(result.message||'Request failed.');
        }

        return result;
    }

    function renderCompany(company){
        setText('companyName',company.company_name||'Company');
        setText('businessType',company.business_type||'');

        const address=[
            company.address_line1,
            company.address_line2,
            company.city,
            company.state,
            company.pincode,
            company.country
        ].filter(Boolean).join(', ');

        setText('companyAddress',address||'—');
        setText('companyMobile',company.mobile?'Mobile: '+company.mobile:'');
        setText('companyGstin',company.gstin?'GSTIN: '+company.gstin:'');

        const logo=document.getElementById('companyLogo');

        if(company.logo_path){
            logo.src=company.logo_path;
            logo.classList.remove('d-none');
        }else{
            logo.classList.add('d-none');
        }
    }

    function renderItems(items,totals){
        const body=document.getElementById('itemTableBody');
        const empty=document.getElementById('itemEmpty');

        if(!items.length){
            body.innerHTML='';
            empty.classList.remove('d-none');
            return;
        }

        empty.classList.add('d-none');

        body.innerHTML=items.map((item,index)=>`<tr>
            <td>${index+1}</td>
            <td>
                <strong>${escapeHtml(item.item_name||'')}</strong>
                <div class="page-subtitle">
                    ${escapeHtml([
                        item.product_code,
                        item.barcode,
                        item.category_name
                    ].filter(Boolean).join(' | '))}
                </div>
            </td>
            <td>${escapeHtml(item.purity||'—')}</td>
            <td>${money(item.qty)}</td>
            <td>${money(item.gross_weight)}</td>
            <td>${money(item.less_weight)}</td>
            <td>${money(item.net_weight)}</td>
            <td>₹${money(item.rate_per_gram)}</td>
            <td>₹${money(item.making_charge)}</td>
            <td>₹${money(item.taxable_amount)}</td>
            <td>₹${money(item.gst_amount)}<div class="page-subtitle">${money(item.gst_percent)}%</div></td>
            <td><strong>₹${money(item.total_amount)}</strong></td>
        </tr>`).join('');

        setText('itemCount',Number(totals.item_count||0));
        setText('totalQty',money(totals.total_qty));
        setText('netWeight',money(totals.total_net_weight));
        setText('itemsTotal','₹'+money(totals.total_item_amount));
    }

    function renderPayments(payments){
        const panel=document.getElementById('paymentPanel');
        const body=document.getElementById('paymentTableBody');

        if(!payments.length){
            panel.classList.add('d-none');
            body.innerHTML='';
            return;
        }

        panel.classList.remove('d-none');

        body.innerHTML=payments.map(payment=>`<tr>
            <td><strong>${escapeHtml(payment.method_name||'—')}</strong></td>
            <td>${escapeHtml(payment.reference_no||'—')}</td>
            <td>${escapeHtml(payment.created_at_display||'—')}</td>
            <td><strong>₹${money(payment.amount)}</strong></td>
        </tr>`).join('');
    }

    function renderSale(result){
        const sale=result.sale;
        const company=result.company;
        const totals=result.totals;

        renderCompany(company);
        renderItems(result.items||[],totals);
        renderPayments(result.payments||[]);

        setText('pageSubtitle','Detailed view for invoice '+(sale.bill_no||''));
        setText('billNo',sale.bill_no||'—');
        setText('billNoDetail',sale.bill_no||'—');
        setText('grandTotal','₹'+money(sale.grand_total));
        setText('paidAmount','₹'+money(sale.paid_amount));
        setText('balanceAmount','₹'+money(sale.balance_amount));
        setText('billDate',sale.bill_date_display||'—');
        setText('billTime',sale.bill_time_display||'—');
        setText('billType',sale.bill_type||'—');
        setText('paymentMethod',sale.method_name||'—');
        setText('paymentReference',sale.payment_reference||'—');

        setText('customerName',sale.customer_name||'Walk-in Customer');
        setText('customerMobile',sale.customer_mobile||'—');
        setText('customerCode',sale.customer_code||'—');
        setText('customerGstin',sale.customer_gstin||'—');
        setText('customerAddress',sale.customer_address||'—');

        setText('billTypeBadge',sale.bill_type||'—');
        setText('workflowStatusBadge',sale.status||'—');
        applyStatus(document.getElementById('paymentStatusBadge'),sale.payment_status);
        applyStatus(document.getElementById('summaryStatus'),sale.payment_status);

        setText('sumSubtotal','₹'+money(sale.subtotal));
        setText('sumDiscount','₹'+money(sale.discount_amount));
        setText('sumTaxable','₹'+money(sale.taxable_amount));
        setText('sumCgst','₹'+money(sale.cgst_amount));
        setText('sumSgst','₹'+money(sale.sgst_amount));
        setText('sumIgst','₹'+money(sale.igst_amount));
        setText('sumRoundOff','₹'+money(sale.round_off));
        setText('sumGrandTotal','₹'+money(sale.grand_total));
        setText('sumPaid','₹'+money(sale.paid_amount));
        setText('sumBalance','₹'+money(sale.balance_amount));

        const notesWrap=document.getElementById('notesWrap');

        if(sale.notes){
            setText('notesText',sale.notes);
            notesWrap.classList.remove('d-none');
        }else{
            notesWrap.classList.add('d-none');
        }

        const isEstimate=String(sale.bill_type||'').toLowerCase()==='estimate';
        document.getElementById('estimatePrintButton').classList.toggle('d-none',!isEstimate);
        document.getElementById('editBillButton').classList.toggle('d-none',isEstimate);
        document.getElementById('quickEditButton').classList.toggle('d-none',isEstimate);

        document.getElementById('loadingState').classList.add('d-none');
        document.getElementById('saleContent').classList.remove('d-none');
    }

    async function loadSale(){
        try{
            const result=await requestJson(
                'api/sale-view.php?action=view&id='+encodeURIComponent(saleId)
            );

            renderSale(result);
        }catch(error){
            document.getElementById('loadingState').innerHTML=
                '<div class="empty-state">'+escapeHtml(error.message)+'</div>';
            toast(error.message);
        }
    }

    loadSale();
})();
</script>
</body>
</html>
