<?php
if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));

foreach ([
    __DIR__.'/config/config.php',
    __DIR__.'/config.php',
    __DIR__.'/includes/config.php',
    __DIR__.'/super-admin/includes/config.php'
] as $f) {
    if (is_file($f)) { require_once $f; break; }
}

if (!isset($conn) || !($conn instanceof mysqli)) die('Database configuration is not available.');
$conn->set_charset('utf8mb4');

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

function e($v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function customerPaymentPermission(mysqli $conn, string $action): bool {
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin') return true;

    $map = [
        'open'=>'can_open',
        'view'=>'can_view',
        'create'=>'can_create',
        'value'=>'can_view_value'
    ];

    $field = $map[$action] ?? '';
    if ($field === '') return false;

    foreach (['perm.customer.payments','perm.customer.payment','perm.sales','perm.billing'] as $code) {
        if (isset($_SESSION['permissions'][$code][$field])) {
            return (int)$_SESSION['permissions'][$code][$field] === 1;
        }
    }

    $businessId = (int)($_SESSION['business_id'] ?? 0);
    $roleId = (int)($_SESSION['role_id'] ?? 0);
    if ($businessId <= 0 || $roleId <= 0) return false;

    $sql = "SELECT rp.`{$field}`
            FROM role_permissions rp
            INNER JOIN permissions p ON p.id = rp.permission_id
            WHERE rp.business_id = ?
              AND rp.role_id = ?
              AND p.is_active = 1
              AND p.permission_code IN ('perm.customer.payments','perm.customer.payment','perm.sales','perm.billing')
            ORDER BY FIELD(p.permission_code,'perm.customer.payments','perm.customer.payment','perm.sales','perm.billing')
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;

    $stmt->bind_param('ii', $businessId, $roleId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row[$field] ?? 0) === 1;
}

if (!customerPaymentPermission($conn, 'open') && !customerPaymentPermission($conn, 'view')) {
    http_response_code(403);
    die('You do not have permission to open customer payments.');
}

$canCreate = customerPaymentPermission($conn, 'create');
$canValue = customerPaymentPermission($conn, 'value') || customerPaymentPermission($conn, 'view');

$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? ($_SESSION['default_branch_id'] ?? 0));

if ($businessId <= 0 || $branchId <= 0) die('A valid business and branch must be selected.');

if (empty($_SESSION['customer_payment_csrf'])) {
    $_SESSION['customer_payment_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['customer_payment_csrf'];

$theme = [
    'primary_color'=>'#d89416',
    'primary_dark_color'=>'#b86a0b',
    'primary_soft_color'=>'#fff6e5',
    'page_background'=>'#f4f3f0',
    'card_background'=>'#ffffff',
    'text_color'=>'#171717',
    'muted_text_color'=>'#7d8794',
    'border_color'=>'#e8e8e8',
    'font_family'=>'Inter',
    'heading_font_family'=>'Playfair Display',
    'border_radius_px'=>12,
    'sidebar_width_px'=>230,
    'sidebar_gradient_1'=>'#171c21',
    'sidebar_gradient_2'=>'#20272d',
    'sidebar_gradient_3'=>'#101419'
];

$stmt = $conn->prepare('SELECT * FROM business_theme_settings WHERE business_id=? LIMIT 1');
if ($stmt) {
    $stmt->bind_param('i', $businessId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    foreach ($theme as $k=>$v) {
        if (isset($row[$k]) && $row[$k] !== '') $theme[$k] = $row[$k];
    }
}

$pageTitle = 'Customer Payments';
$businessName = (string)($_SESSION['business_name'] ?? 'Jewellery ERP');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=e($businessName)?> - Customer Payments</title>
<?php include('includes/links.php'); ?>
<style>
:root{
--primary:<?=e($theme['primary_color'])?>;--primary-dark:<?=e($theme['primary_dark_color'])?>;
--primary-soft:<?=e($theme['primary_soft_color'])?>;--page-bg:<?=e($theme['page_background'])?>;
--card-bg:<?=e($theme['card_background'])?>;--text:<?=e($theme['text_color'])?>;
--muted:<?=e($theme['muted_text_color'])?>;--line:<?=e($theme['border_color'])?>;
--radius:<?=(int)$theme['border_radius_px']?>px;--sidebar-width:<?=(int)$theme['sidebar_width_px']?>px;
--sidebar-gradient-1:<?=e($theme['sidebar_gradient_1'])?>;--sidebar-gradient-2:<?=e($theme['sidebar_gradient_2'])?>;
--sidebar-gradient-3:<?=e($theme['sidebar_gradient_3'])?>}
body{background:var(--page-bg);color:var(--text);font-family:<?=json_encode($theme['font_family'])?>,sans-serif}
.sidebar{background:linear-gradient(180deg,var(--sidebar-gradient-1),var(--sidebar-gradient-2),var(--sidebar-gradient-3))!important}
.page-card,.stat-card{background:var(--card-bg);border:1px solid var(--line);border-radius:var(--radius)}
.page-head{padding:15px 17px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;gap:12px}
.page-title{font:700 20px <?=json_encode($theme['heading_font_family'])?>,serif}
.stat-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-bottom:12px}
.stat-card{padding:14px;display:flex;align-items:center;gap:11px}
.stat-icon{width:42px;height:42px;border-radius:10px;display:grid;place-items:center;background:var(--primary-soft);color:var(--primary-dark)}
.stat-label{font-size:10px;color:var(--muted)}.stat-value{font-size:21px;font-weight:800}
.form-control,.form-select{min-height:39px;border:1px solid var(--line);border-radius:9px;background:var(--card-bg);color:var(--text);font-size:11px}
.btn-theme{min-height:39px;border:0;border-radius:9px;padding:8px 14px;color:#fff;background:linear-gradient(135deg,var(--primary),var(--primary-dark));font-size:11px;font-weight:700}
.btn-soft{min-height:39px;border:1px solid var(--line);border-radius:9px;padding:8px 14px;background:var(--card-bg);color:var(--text);font-size:11px}
.payment-table{margin:0;font-size:10px}.payment-table th{font-size:9px;text-transform:uppercase;color:var(--muted);background:color-mix(in srgb,var(--muted) 6%,transparent);white-space:nowrap}
.payment-table td,.payment-table th{padding:10px 12px;border-color:var(--line);vertical-align:middle}
.filter-grid{display:grid;grid-template-columns:1.5fr 1fr 1fr 1fr auto auto;gap:8px}
.loading,.empty{display:none;padding:45px;text-align:center;color:var(--muted)}.loading.show,.empty.show{display:block}
.pagination-wrap{padding:11px 12px;border-top:1px solid var(--line);display:flex;justify-content:space-between;align-items:center}
.pagination{margin:0;gap:4px}.page-link{border-radius:8px!important;font-size:10px;color:var(--text);background:var(--card-bg);border-color:var(--line)}
.page-item.active .page-link{background:linear-gradient(135deg,var(--primary),var(--primary-dark));border-color:var(--primary);color:#fff}
.theme-toast{position:fixed;right:18px;top:78px;z-index:20000;padding:11px 14px;border-radius:10px;color:#fff;font-size:11px;font-weight:600;opacity:0;transform:translateY(-10px);transition:.22s}
.theme-toast.show{opacity:1;transform:none}.theme-toast-success{background:#168449}.theme-toast-error{background:#c0392b}
body.dark-mode,body[data-theme=dark]{--page-bg:#0f151b;--card-bg:#182129;--text:#f3f6f8;--muted:#9aa7b3;--line:#2c3944}
@media(max-width:991px){.filter-grid{grid-template-columns:1fr 1fr}.filter-grid .search{grid-column:1/-1}}
@media(max-width:767px){.stat-grid,.filter-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<?php include('includes/sidebar.php'); ?>
<main class="app-main">
<?php include('includes/nav.php'); ?>
<div class="content-wrap">

<div class="page-card mb-3">
<div class="page-head">
<div><div class="page-title">Customer Payments</div><div class="small text-muted">Receive and manage customer payments.</div></div>
</div>
</div>

<div class="stat-grid">
<div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-receipt"></i></div><div><div class="stat-label">Total Payments</div><div class="stat-value" id="statCount">0</div></div></div>
<div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-indian-rupee-sign"></i></div><div><div class="stat-label">Total Received</div><div class="stat-value" id="statAmount">₹0.00</div></div></div>
</div>

<div class="row g-3 mb-3">
<?php if ($canCreate): ?>
<div class="col-xl-4">
<div class="page-card h-100">
<div class="page-head"><div class="fw-bold">Add Customer Payment</div></div>
<div class="p-3">
<form id="paymentForm">
<input type="hidden" name="action" value="save">
<input type="hidden" name="csrf_token" value="<?=e($csrfToken)?>">
<div class="row g-2">
<div class="col-md-6"><label class="small fw-bold mb-1">Receipt Date</label><input type="date" name="receipt_date" class="form-control" value="<?=date('Y-m-d')?>" required></div>
<div class="col-md-6"><label class="small fw-bold mb-1">Amount</label><input type="number" step="0.01" min="0.01" name="amount" id="paymentAmount" class="form-control" required></div>
<div class="col-12"><label class="small fw-bold mb-1">Customer</label><select name="customer_id" id="customerSelect" class="form-select" required><option value="">Loading customers...</option></select></div>
<div class="col-12"><label class="small fw-bold mb-1">Linked Sale</label><select name="sale_id" id="saleSelect" class="form-select"><option value="0">Select customer first</option></select></div>
<div class="col-md-6"><label class="small fw-bold mb-1">Payment Method</label><select name="payment_method_id" id="methodSelect" class="form-select" required><option value="">Loading methods...</option></select></div>
<div class="col-md-6"><label class="small fw-bold mb-1">Reference No</label><input type="text" name="reference_no" class="form-control" placeholder="UPI / Txn / Cheque"></div>
<div class="col-12"><label class="small fw-bold mb-1">Remarks</label><textarea name="remarks" class="form-control" rows="3" placeholder="Payment remarks"></textarea></div>
<div class="col-12 d-flex gap-2 mt-2"><button type="submit" class="btn-theme flex-grow-1" id="saveBtn">Save Payment</button><button type="reset" class="btn-soft">Reset</button></div>
</div>
</form>
</div>
</div>
</div>
<?php endif; ?>

<div class="<?=$canCreate ? 'col-xl-8' : 'col-12'?>">
<div class="page-card h-100">
<div class="page-head"><div class="fw-bold">Filter Payments</div></div>
<div class="p-3">
<form id="filterForm" class="filter-grid">
<input type="search" id="searchInput" class="form-control search" placeholder="Receipt, customer, invoice or reference">
<select id="customerFilter" class="form-select"><option value="">All customers</option></select>
<input type="date" id="dateFrom" class="form-control" value="<?=date('Y-m-01')?>">
<input type="date" id="dateTo" class="form-control" value="<?=date('Y-m-d')?>">
<button type="submit" class="btn-theme"><i class="fa-solid fa-search me-1"></i>Search</button>
<button type="button" id="resetFilter" class="btn-soft"><i class="fa-solid fa-rotate-left"></i></button>
</form>
</div>
</div>
</div>
</div>

<section class="page-card">
<div class="loading" id="loading"><i class="fa-solid fa-spinner fa-spin me-2"></i>Loading payments...</div>
<div class="table-responsive" id="tableWrap">
<table class="table payment-table">
<thead><tr><th>Receipt</th><th>Date</th><th>Customer</th><th>Linked Sale</th><th>Method</th><th>Reference</th><th class="text-end">Amount</th><th>Remarks</th><th>Created</th></tr></thead>
<tbody id="paymentBody"></tbody>
</table>
</div>
<div class="empty" id="emptyState"><i class="fa-regular fa-folder-open fa-2x mb-2"></i><div>No customer payments found.</div></div>
<div class="pagination-wrap"><div class="small text-muted" id="pageSummary">Showing 0 records</div><ul class="pagination pagination-sm" id="pagination"></ul></div>
</section>

<?php include('includes/footer.php'); ?>
</div>
</main>

<?php include('includes/script.php'); ?>
<script src="assets/js/script.js"></script>
<script>
(()=>{'use strict';
const apiUrl='api/customer-payments.php';
const csrf=<?=json_encode($csrfToken)?>;
const paymentBody=document.getElementById('paymentBody');
const loading=document.getElementById('loading');
const tableWrap=document.getElementById('tableWrap');
const empty=document.getElementById('emptyState');
const pagination=document.getElementById('pagination');
let currentPage=1;

function esc(v){return String(v??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]))}
function money(v){return Number(v||0).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2})}
function toast(t,m){const x=document.createElement('div');x.className='theme-toast theme-toast-'+t;x.textContent=m;document.body.appendChild(x);requestAnimationFrame(()=>x.classList.add('show'));setTimeout(()=>{x.classList.remove('show');setTimeout(()=>x.remove(),250)},3400)}
async function request(data){
 const fd=new FormData();Object.entries(data).forEach(([k,v])=>fd.append(k,v));fd.append('csrf_token',csrf);
 const res=await fetch(apiUrl,{method:'POST',body:fd,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}});
 const raw=await res.text();let json;
 try{json=JSON.parse(raw)}catch(e){throw new Error('Customer Payments API did not return JSON. HTTP '+res.status+': '+raw.replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim().substring(0,300))}
 if(!res.ok||!json.success)throw new Error(json.message||'Request failed.');return json;
}

async function loadOptions(){
 try{
  const d=await request({action:'options'});
  const customerOptions='<option value="">Select Customer</option>'+d.customers.map(c=>`<option value="${c.id}">${esc(c.customer_name)}${c.customer_code?' - '+esc(c.customer_code):''}${c.mobile?' - '+esc(c.mobile):''}</option>`).join('');
  <?php if ($canCreate): ?>
  document.getElementById('customerSelect').innerHTML=customerOptions;
  document.getElementById('methodSelect').innerHTML='<option value="">Select Method</option>'+d.payment_methods.map(m=>`<option value="${m.id}">${esc(m.method_name)}</option>`).join('');
  <?php endif; ?>
  document.getElementById('customerFilter').innerHTML='<option value="">All customers</option>'+d.customers.map(c=>`<option value="${c.id}">${esc(c.customer_name)}</option>`).join('');
 }catch(e){toast('error',e.message)}
}

async function loadSales(customerId){
 <?php if ($canCreate): ?>
 const sale=document.getElementById('saleSelect');
 sale.innerHTML='<option value="0">Loading sales...</option>';
 if(!customerId){sale.innerHTML='<option value="0">Select customer first</option>';return}
 try{
  const d=await request({action:'customer_sales',customer_id:customerId});
  sale.innerHTML='<option value="0">No linked sale</option>'+d.sales.map(s=>`<option value="${s.id}" data-balance="${s.balance_amount}">${esc(s.invoice_no)} - ${esc(s.invoice_date_display)} - Balance ₹${money(s.balance_amount)}</option>`).join('');
 }catch(e){toast('error',e.message);sale.innerHTML='<option value="0">Unable to load sales</option>'}
 <?php endif; ?>
}

async function loadPayments(page=1){
 currentPage=page;loading.classList.add('show');tableWrap.style.display='none';empty.classList.remove('show');
 try{
  const d=await request({
   action:'list',page,per_page:10,
   search:document.getElementById('searchInput').value.trim(),
   customer_id:document.getElementById('customerFilter').value,
   date_from:document.getElementById('dateFrom').value,
   date_to:document.getElementById('dateTo').value
  });
  paymentBody.innerHTML=d.payments.map(p=>`<tr>
   <td><strong>${esc(p.receipt_no)}</strong></td>
   <td>${esc(p.receipt_date_display)}</td>
   <td><strong>${esc(p.customer_name)}</strong><div class="small text-muted">${esc(p.customer_code||'')} ${esc(p.mobile||'')}</div></td>
   <td>${p.invoice_no?`<strong>${esc(p.invoice_no)}</strong><div class="small text-muted">${esc(p.invoice_date_display||'')} | Bal ₹${money(p.balance_amount)}</div>`:'-'}</td>
   <td>${esc(p.method_name||'-')}</td><td>${esc(p.reference_no||'-')}</td>
   <td class="text-end"><strong>₹${money(p.amount)}</strong></td>
   <td>${esc(p.remarks||'')}</td><td>${esc(p.created_at_display||'')}</td>
  </tr>`).join('');
  document.getElementById('statCount').textContent=d.stats.total_payments;
  document.getElementById('statAmount').textContent='₹'+money(d.stats.total_amount);
  document.getElementById('pageSummary').textContent=`Showing ${d.meta.from}-${d.meta.to} of ${d.meta.total}`;
  pagination.innerHTML='';
  for(let p=1;p<=d.meta.total_pages;p++)pagination.insertAdjacentHTML('beforeend',`<li class="page-item ${p===d.meta.page?'active':''}"><button class="page-link page-go" data-page="${p}">${p}</button></li>`);
  tableWrap.style.display=d.payments.length?'':'none';empty.classList.toggle('show',!d.payments.length);
 }catch(e){toast('error',e.message);empty.classList.add('show')}finally{loading.classList.remove('show')}
}

<?php if ($canCreate): ?>
document.getElementById('customerSelect').addEventListener('change',e=>loadSales(e.target.value));
document.getElementById('saleSelect').addEventListener('change',e=>{
 const opt=e.target.options[e.target.selectedIndex],bal=Number(opt?.dataset.balance||0),amount=document.getElementById('paymentAmount');
 if(bal>0)amount.value=bal.toFixed(2);
});
document.getElementById('paymentForm').addEventListener('submit',async e=>{
 e.preventDefault();const btn=document.getElementById('saveBtn'),old=btn.innerHTML;btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin me-2"></i>Saving...';
 try{
  const fd=new FormData(e.target),res=await fetch(apiUrl,{method:'POST',body:fd,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}});
  const raw=await res.text();let d;try{d=JSON.parse(raw)}catch(x){throw new Error('Customer Payments API did not return JSON. HTTP '+res.status+': '+raw.replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim().substring(0,300))}
  if(!res.ok||!d.success)throw new Error(d.message||'Unable to save payment.');
  toast('success',d.message);e.target.reset();e.target.querySelector('[name="receipt_date"]').value=<?=json_encode(date('Y-m-d'))?>;document.getElementById('saleSelect').innerHTML='<option value="0">Select customer first</option>';loadPayments(1);
 }catch(err){toast('error',err.message)}finally{btn.disabled=false;btn.innerHTML=old}
});
<?php endif; ?>

document.getElementById('filterForm').addEventListener('submit',e=>{e.preventDefault();loadPayments(1)});
document.getElementById('resetFilter').addEventListener('click',()=>{
 document.getElementById('searchInput').value='';document.getElementById('customerFilter').value='';
 document.getElementById('dateFrom').value=<?=json_encode(date('Y-m-01'))?>;document.getElementById('dateTo').value=<?=json_encode(date('Y-m-d'))?>;loadPayments(1);
});
document.addEventListener('click',e=>{const b=e.target.closest('.page-go');if(b)loadPayments(Number(b.dataset.page))});
loadOptions();loadPayments();
})();
</script>
</body>
</html>
