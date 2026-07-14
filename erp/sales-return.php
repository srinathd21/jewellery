<?php
if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));

foreach ([
    __DIR__.'/config/config.php',
    __DIR__.'/config.php',
    __DIR__.'/includes/config.php',
    __DIR__.'/super-admin/includes/config.php'
] as $f) {
    if (is_file($f)) {
        require_once $f;
        break;
    }
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database configuration is not available.');
}

$conn->set_charset('utf8mb4');

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

function e($v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function returnPermission(mysqli $conn, string $action): bool
{
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin') return true;

    $map = [
        'open' => 'can_open',
        'view' => 'can_view',
        'create' => 'can_create',
        'value' => 'can_view_value'
    ];

    $field = $map[$action] ?? '';
    if ($field === '') return false;

    foreach (['perm.sales.return','perm.sales.returns','perm.sales','perm.billing'] as $code) {
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
              AND p.permission_code IN ('perm.sales.return','perm.sales.returns','perm.sales','perm.billing')
            ORDER BY FIELD(p.permission_code,'perm.sales.return','perm.sales.returns','perm.sales','perm.billing')
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;
    $stmt->bind_param('ii', $businessId, $roleId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row[$field] ?? 0) === 1;
}

if (!returnPermission($conn, 'open') && !returnPermission($conn, 'create')) {
    http_response_code(403);
    die('You do not have permission to create sales returns.');
}

$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? ($_SESSION['default_branch_id'] ?? 0));

if ($businessId <= 0 || $branchId <= 0) {
    die('A valid business and branch must be selected.');
}

if (empty($_SESSION['sales_return_csrf'])) {
    $_SESSION['sales_return_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['sales_return_csrf'];

$theme = [
    'primary_color'=>'#d89416',
    'primary_dark_color'=>'#b86a0b',
    'primary_soft_color'=>'#fff6e5',
    'page_background'=>'#f4f3f0',
    'card_background'=>'#fff',
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

$pageTitle = 'Sales Return';
$businessName = (string)($_SESSION['business_name'] ?? 'Jewellery ERP');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=e($businessName)?> - Sales Return</title>
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
.return-card{background:var(--card-bg);border:1px solid var(--line);border-radius:var(--radius);overflow:hidden;margin-bottom:12px}
.return-head{padding:14px 16px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;gap:10px}
.return-title{font:700 20px <?=json_encode($theme['heading_font_family'])?>,serif}
.section-title{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--primary-dark)}
.return-body{padding:16px}.field-label{font-size:10px;font-weight:700;margin-bottom:5px}
.form-control,.form-select{font-size:11px;min-height:38px;border-color:var(--line);border-radius:9px;background:var(--card-bg);color:var(--text)}
.btn-theme{background:linear-gradient(135deg,var(--primary),var(--primary-dark));border:0;color:#fff;font-size:11px;font-weight:700;border-radius:9px;padding:9px 15px}
.btn-soft{background:var(--card-bg);border:1px solid var(--line);color:var(--text);font-size:11px;font-weight:700;border-radius:9px;padding:9px 15px}
.sale-result{padding:10px;border:1px solid var(--line);border-radius:9px;cursor:pointer;margin-bottom:7px}
.sale-result:hover,.sale-result.active{border-color:var(--primary);background:var(--primary-soft)}
.table{font-size:10px}.table th{font-size:9px;text-transform:uppercase;color:var(--muted);white-space:nowrap;background:color-mix(in srgb,var(--muted) 6%,transparent)}
.table td,.table th{border-color:var(--line);vertical-align:middle}
.summary-card{position:sticky;top:76px}
.summary-row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px dashed var(--line);font-size:11px}
.summary-total{font-size:17px;font-weight:800;color:var(--primary-dark)}
.theme-toast{position:fixed;right:18px;top:78px;z-index:20000;padding:11px 14px;border-radius:10px;color:#fff;font-size:11px;font-weight:600;opacity:0;transform:translateY(-10px);transition:.22s}
.theme-toast.show{opacity:1;transform:none}.theme-toast-success{background:#168449}.theme-toast-error{background:#c0392b}
.loading-box{display:none;padding:18px;text-align:center;color:var(--muted)}.loading-box.show{display:block}
body.dark-mode,body[data-theme=dark]{--page-bg:#0f151b;--card-bg:#182129;--text:#f3f6f8;--muted:#9aa7b3;--line:#2c3944}
@media(max-width:991px){.summary-card{position:static}}
</style>
</head>
<body>
<?php include('includes/sidebar.php'); ?>
<main class="app-main">
<?php include('includes/nav.php'); ?>
<div class="content-wrap">

<div class="return-card">
<div class="return-head">
<div><div class="return-title">Sales Return</div><div class="small text-muted">Create a return against an existing posted invoice.</div></div>
<a href="sales-list.php" class="btn-soft text-decoration-none"><i class="fa-solid fa-arrow-left me-2"></i>Back to Sales</a>
</div>
</div>

<div class="return-card">
<div class="return-head"><div class="section-title">Find Sale</div></div>
<div class="return-body">
<div class="row g-2">
<div class="col-lg-9"><input type="search" id="saleSearch" class="form-control" placeholder="Invoice number, customer name or mobile"></div>
<div class="col-lg-3"><button type="button" class="btn-theme w-100" id="searchSaleBtn"><i class="fa-solid fa-magnifying-glass me-2"></i>Search Sale</button></div>
</div>
<div class="loading-box" id="saleSearchLoading"><i class="fa-solid fa-spinner fa-spin me-2"></i>Searching sales...</div>
<div id="saleResults" class="mt-3"></div>
</div>
</div>

<form id="returnForm" autocomplete="off" style="display:none">
<input type="hidden" name="csrf_token" value="<?=e($csrfToken)?>">
<input type="hidden" name="action" value="save">
<input type="hidden" name="sale_id" id="saleId">

<div class="row g-3">
<div class="col-xl-9">

<div class="return-card">
<div class="return-head"><div class="section-title">Sale Details</div></div>
<div class="return-body">
<div class="row g-3">
<div class="col-md-3"><label class="field-label">Invoice No</label><input id="saleInvoiceNo" class="form-control" readonly></div>
<div class="col-md-3"><label class="field-label">Invoice Date</label><input id="saleInvoiceDate" class="form-control" readonly></div>
<div class="col-md-3"><label class="field-label">Customer</label><input id="saleCustomer" class="form-control" readonly></div>
<div class="col-md-3"><label class="field-label">Mobile</label><input id="saleMobile" class="form-control" readonly></div>
</div>
</div>
</div>

<div class="return-card">
<div class="return-head"><div class="section-title">Return Items</div></div>
<div class="table-responsive">
<table class="table mb-0">
<thead><tr><th>Item</th><th>Sold Qty</th><th>Returned</th><th>Returnable</th><th>Net Weight</th><th>Rate</th><th>Total</th><th style="min-width:130px">Return Qty</th><th>Return Total</th></tr></thead>
<tbody id="returnItemsBody"></tbody>
</table>
</div>
</div>

</div>

<div class="col-xl-3">
<div class="return-card summary-card">
<div class="return-head"><div class="section-title">Return Details</div></div>
<div class="return-body">
<div class="mb-3"><label class="field-label">Return Date *</label><input type="date" name="return_date" class="form-control" value="<?=date('Y-m-d')?>" required></div>
<div class="mb-3"><label class="field-label">Refund Method</label><select name="refund_method_id" id="refundMethod" class="form-select"><option value="">Select method</option></select></div>
<div class="mb-3"><label class="field-label">Reason *</label><input name="reason" class="form-control" placeholder="Reason for return" required></div>
<div class="mb-3"><label class="field-label">Notes</label><textarea name="notes" class="form-control" rows="3" placeholder="Additional notes"></textarea></div>
<div class="summary-row"><span>Return Items</span><strong id="returnItemCount">0</strong></div>
<div class="summary-row"><span>Return Quantity</span><strong id="returnQtyTotal">0.000</strong></div>
<div class="summary-row"><span>Refund Amount</span><strong class="summary-total">₹<span id="refundTotal">0.00</span></strong></div>
<button type="submit" class="btn-theme w-100 mt-3" id="saveReturnBtn"><i class="fa-solid fa-rotate-left me-2"></i>Save Return</button>
</div>
</div>
</div>
</div>
</form>

<?php include('includes/footer.php'); ?>
</div>
</main>

<?php include('includes/script.php'); ?>
<script src="assets/js/script.js"></script>
<script>
(()=>{'use strict';
const apiUrl='api/sales-return.php';
const csrf=<?=json_encode($csrfToken)?>;
const form=document.getElementById('returnForm');
const results=document.getElementById('saleResults');
const itemsBody=document.getElementById('returnItemsBody');
let currentItems=[];

function esc(v){return String(v??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]))}
function money(v){return Number(v||0).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2})}
function toast(t,m){const x=document.createElement('div');x.className='theme-toast theme-toast-'+t;x.textContent=m;document.body.appendChild(x);requestAnimationFrame(()=>x.classList.add('show'));setTimeout(()=>{x.classList.remove('show');setTimeout(()=>x.remove(),250)},3500)}
async function request(data){
 const fd=new FormData();Object.entries(data).forEach(([k,v])=>fd.append(k,v));fd.append('csrf_token',csrf);
 const res=await fetch(apiUrl,{method:'POST',body:fd,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}});
 const raw=await res.text();let json;
 try{json=JSON.parse(raw)}catch(e){throw new Error('Sales Return API did not return JSON. HTTP '+res.status+': '+raw.replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim().substring(0,300))}
 if(!res.ok||!json.success)throw new Error(json.message||'Request failed.');return json;
}
async function searchSales(){
 const q=document.getElementById('saleSearch').value.trim(),load=document.getElementById('saleSearchLoading');
 load.classList.add('show');results.innerHTML='';
 try{
  const d=await request({action:'search_sales',search:q});
  results.innerHTML=d.sales.length?d.sales.map(s=>`<div class="sale-result" data-id="${s.id}"><div class="d-flex justify-content-between gap-2"><div><strong>${esc(s.invoice_no)}</strong><div class="small text-muted">${esc(s.customer_name||'Walk-in')} ${s.customer_mobile?' - '+esc(s.customer_mobile):''}</div></div><div class="text-end"><strong>₹${money(s.grand_total)}</strong><div class="small text-muted">${esc(s.invoice_date_display)}</div></div></div></div>`).join(''):'<div class="small text-muted">No eligible posted sales found.</div>';
 }catch(e){toast('error',e.message)}finally{load.classList.remove('show')}
}
async function loadSale(id){
 try{
  const d=await request({action:'load_sale',sale_id:id});
  document.getElementById('saleId').value=d.sale.id;
  document.getElementById('saleInvoiceNo').value=d.sale.invoice_no;
  document.getElementById('saleInvoiceDate').value=d.sale.invoice_date_display;
  document.getElementById('saleCustomer').value=d.sale.customer_name||'Walk-in Customer';
  document.getElementById('saleMobile').value=d.sale.customer_mobile||'';
  document.getElementById('refundMethod').innerHTML='<option value="">Select method</option>'+d.payment_methods.map(p=>`<option value="${p.id}">${esc(p.method_name)}</option>`).join('');
  currentItems=d.items;
  itemsBody.innerHTML=d.items.length?d.items.map(i=>`<tr>
   <td><strong>${esc(i.item_name)}</strong></td>
   <td>${Number(i.quantity).toFixed(3)}</td>
   <td>${Number(i.returned_quantity).toFixed(3)}</td>
   <td>${Number(i.returnable_quantity).toFixed(3)}</td>
   <td>${Number(i.net_weight).toFixed(3)}</td>
   <td>₹${money(i.metal_rate)}</td>
   <td>₹${money(i.line_total)}</td>
   <td><input type="number" min="0" max="${Number(i.returnable_quantity).toFixed(3)}" step="0.001" name="return_qty[${i.id}]" class="form-control return-qty" data-id="${i.id}" value="0" ${Number(i.returnable_quantity)<=0?'disabled':''}></td>
   <td>₹<span class="return-line-total" data-id="${i.id}">0.00</span></td>
  </tr>`).join(''):'<tr><td colspan="9" class="text-center text-muted py-4">No returnable items.</td></tr>';
  form.style.display='';
  calculate();
  window.scrollTo({top:form.offsetTop-80,behavior:'smooth'});
 }catch(e){toast('error',e.message)}
}
function calculate(){
 let amount=0,qty=0,count=0;
 document.querySelectorAll('.return-qty').forEach(el=>{
  const item=currentItems.find(x=>String(x.id)===String(el.dataset.id));
  if(!item)return;
  let q=Number(el.value||0),max=Number(item.returnable_quantity||0);
  if(q<0)q=0;if(q>max){q=max;el.value=max.toFixed(3)}
  const per=Number(item.quantity)>0?Number(item.line_total)/Number(item.quantity):0;
  const line=per*q;
  document.querySelector('.return-line-total[data-id="'+el.dataset.id+'"]').textContent=line.toFixed(2);
  if(q>0){count++;qty+=q;amount+=line}
 });
 document.getElementById('returnItemCount').textContent=count;
 document.getElementById('returnQtyTotal').textContent=qty.toFixed(3);
 document.getElementById('refundTotal').textContent=amount.toFixed(2);
}
document.getElementById('searchSaleBtn').onclick=searchSales;
document.getElementById('saleSearch').addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();searchSales()}});
results.addEventListener('click',e=>{const row=e.target.closest('.sale-result');if(row)loadSale(row.dataset.id)});
document.addEventListener('input',e=>{if(e.target.classList.contains('return-qty'))calculate()});
form.addEventListener('submit',async e=>{
 e.preventDefault();
 const btn=document.getElementById('saveReturnBtn'),old=btn.innerHTML;
 btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin me-2"></i>Saving...';
 try{
  const fd=new FormData(form),res=await fetch(apiUrl,{method:'POST',body:fd,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}});
  const raw=await res.text();let d;
  try{d=JSON.parse(raw)}catch(x){throw new Error('Sales Return API did not return JSON. HTTP '+res.status+': '+raw.replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim().substring(0,300))}
  if(!res.ok||!d.success)throw new Error(d.message||'Unable to save return.');
  toast('success',d.message);
  setTimeout(()=>location.href='sales-list.php?msg=return_created&sales_return_id='+encodeURIComponent(d.sales_return_id),700);
 }catch(err){toast('error',err.message)}finally{btn.disabled=false;btn.innerHTML=old}
});
})();
</script>
</body>
</html>
