<?php
if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));
foreach ([__DIR__.'/config/config.php',__DIR__.'/config.php',__DIR__.'/includes/config.php',__DIR__.'/super-admin/includes/config.php'] as $f) {
    if (is_file($f)) { require_once $f; break; }
}
if (!isset($conn) || !($conn instanceof mysqli)) die('Database configuration is not available.');
$conn->set_charset('utf8mb4');
if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }
function e($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
$businessId=(int)($_SESSION['business_id']??0);
$branchId=(int)($_SESSION['branch_id']??($_SESSION['default_branch_id']??0));
if($businessId<=0||$branchId<=0) die('A valid business and branch must be selected.');
if(empty($_SESSION['pawn_customer_csrf'])) $_SESSION['pawn_customer_csrf']=bin2hex(random_bytes(32));
$csrfToken=(string)$_SESSION['pawn_customer_csrf'];
$theme=['primary_color'=>'#d89416','primary_dark_color'=>'#b86a0b','primary_soft_color'=>'#fff6e5','page_background'=>'#f4f3f0','card_background'=>'#fff','text_color'=>'#171717','muted_text_color'=>'#7d8794','border_color'=>'#e8e8e8','font_family'=>'Inter','heading_font_family'=>'Playfair Display','border_radius_px'=>12,'sidebar_width_px'=>230,'sidebar_gradient_1'=>'#171c21','sidebar_gradient_2'=>'#20272d','sidebar_gradient_3'=>'#101419'];
$stmt=$conn->prepare('SELECT * FROM business_theme_settings WHERE business_id=? LIMIT 1');
if($stmt){$stmt->bind_param('i',$businessId);$stmt->execute();$r=$stmt->get_result()->fetch_assoc()?:[];$stmt->close();foreach($theme as $k=>$v)if(isset($r[$k])&&$r[$k]!=='')$theme[$k]=$r[$k];}
$businessName=(string)($_SESSION['business_name']??'Jewellery ERP');
?><?php $editId=(int)($_GET['id']??0); ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($businessName)?> - Pawn Customer</title><?php include('includes/links.php'); ?><style>
:root{--primary:<?=e($theme['primary_color'])?>;--primary-dark:<?=e($theme['primary_dark_color'])?>;--primary-soft:<?=e($theme['primary_soft_color'])?>;--page-bg:<?=e($theme['page_background'])?>;--card-bg:<?=e($theme['card_background'])?>;--text:<?=e($theme['text_color'])?>;--muted:<?=e($theme['muted_text_color'])?>;--line:<?=e($theme['border_color'])?>;--radius:<?=(int)$theme['border_radius_px']?>px;--sidebar-width:<?=(int)$theme['sidebar_width_px']?>px;--sidebar-gradient-1:<?=e($theme['sidebar_gradient_1'])?>;--sidebar-gradient-2:<?=e($theme['sidebar_gradient_2'])?>;--sidebar-gradient-3:<?=e($theme['sidebar_gradient_3'])?>}
body{background:var(--page-bg);color:var(--text);font-family:<?=json_encode($theme['font_family'])?>,sans-serif}.sidebar{background:linear-gradient(180deg,var(--sidebar-gradient-1),var(--sidebar-gradient-2),var(--sidebar-gradient-3))!important}.page-card,.stat-card{background:var(--card-bg);border:1px solid var(--line);border-radius:var(--radius)}.page-head{padding:15px 17px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;gap:12px}.page-title{font:700 20px <?=json_encode($theme['heading_font_family'])?>,serif}.section-title{font-size:10px;font-weight:800;text-transform:uppercase;color:var(--primary-dark)}.card-body-x{padding:15px}.form-control,.form-select{min-height:39px;border:1px solid var(--line);border-radius:9px;background:var(--card-bg);color:var(--text);font-size:11px}.btn-theme{border:0;border-radius:9px;padding:9px 15px;color:#fff;background:linear-gradient(135deg,var(--primary),var(--primary-dark));font-size:11px;font-weight:700;text-decoration:none}.btn-soft{border:1px solid var(--line);border-radius:9px;padding:9px 15px;background:var(--card-bg);color:var(--text);font-size:11px;text-decoration:none}.stat-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.stat-card{padding:14px}.stat-label{font-size:10px;color:var(--muted)}.stat-value{font-size:20px;font-weight:800}.table{font-size:10px}.table th{font-size:9px;text-transform:uppercase;color:var(--muted);background:color-mix(in srgb,var(--muted) 6%,transparent);white-space:nowrap}.table td,.table th{border-color:var(--line);vertical-align:middle}.badge-soft{padding:4px 8px;border-radius:999px;font-size:9px;font-weight:700}.risk-low{background:#eaf8f0;color:#168449}.risk-medium{background:#fff4d8;color:#9a6700}.risk-high{background:#fdecec;color:#bd2d2d}.kyc-ok{background:#eaf2ff;color:#2457a7}.kyc-pending{background:#edf0f2;color:#5f6b74}.loading,.empty{display:none;padding:40px;text-align:center;color:var(--muted)}.loading.show,.empty.show{display:block}.theme-toast{position:fixed;right:18px;top:78px;z-index:20000;padding:11px 14px;border-radius:10px;color:#fff;font-size:11px;font-weight:600;opacity:0;transform:translateY(-10px);transition:.22s}.theme-toast.show{opacity:1;transform:none}.theme-toast-success{background:#168449}.theme-toast-error{background:#c0392b}.preview{width:92px;height:92px;object-fit:cover;border-radius:10px;border:1px solid var(--line)}.doc-link{font-size:10px}.info-row{display:flex;gap:12px;padding:8px 0;border-bottom:1px dashed var(--line)}.info-label{width:145px;color:var(--muted);font-size:10px}.info-value{font-size:11px;font-weight:600;flex:1}body.dark-mode,body[data-theme=dark]{--page-bg:#0f151b;--card-bg:#182129;--text:#f3f6f8;--muted:#9aa7b3;--line:#2c3944}@media(max-width:991px){.stat-grid{grid-template-columns:1fr 1fr}}@media(max-width:767px){.stat-grid{grid-template-columns:1fr}}
</style></head><body>
<?php include('includes/sidebar.php'); ?><main class="app-main"><?php include('includes/nav.php'); ?><div class="content-wrap">
<div class="page-card mb-3"><div class="page-head"><div><div class="page-title"><?=$editId>0?'Edit':'Add'?> Pawn Customer</div><div class="small text-muted">Manage customer, KYC, risk and credit details.</div></div><div class="d-flex gap-2"><?php if($editId>0):?><a class="btn-soft" href="pawn-customer-view.php?id=<?=$editId?>">View</a><?php endif;?><a class="btn-soft" href="pawn-customers.php">Customer List</a></div></div></div>
<form id="customerForm" enctype="multipart/form-data">
<input type="hidden" name="action" value="save"><input type="hidden" name="csrf_token" value="<?=e($csrfToken)?>"><input type="hidden" name="edit_id" value="<?=$editId?>">
<input type="hidden" name="existing_photo" id="existingPhoto"><input type="hidden" name="existing_signature" id="existingSignature"><input type="hidden" name="existing_kyc" id="existingKyc">
<div class="row g-3">
<div class="col-xl-8">
<div class="page-card mb-3"><div class="page-head"><div class="section-title">Customer Information</div></div><div class="card-body-x">
<div class="row g-2">
<div class="col-12"><label class="small fw-bold">Existing Customer</label><select name="customer_id" id="customerSelect" class="form-select"><option value="">Loading customers...</option></select><div class="small text-muted mt-1">Select an existing customer or enter a new customer below.</div></div>
<div class="col-md-4"><label class="small fw-bold">New Customer Name</label><input name="customer_name_new" id="newName" class="form-control"></div>
<div class="col-md-4"><label class="small fw-bold">Mobile</label><input name="customer_mobile_new" class="form-control"></div>
<div class="col-md-4"><label class="small fw-bold">Email</label><input type="email" name="customer_email_new" class="form-control"></div>
<div class="col-md-4"><label class="small fw-bold">Date of Birth</label><input type="date" name="date_of_birth_new" class="form-control"></div>
<div class="col-md-4"><label class="small fw-bold">Anniversary Date</label><input type="date" name="anniversary_date_new" class="form-control"></div>
<div class="col-md-4"><label class="small fw-bold">Pincode</label><input name="customer_pincode_new" class="form-control"></div>
<div class="col-12"><label class="small fw-bold">Address</label><textarea name="customer_address_new" class="form-control" rows="2"></textarea></div>
<div class="col-md-6"><label class="small fw-bold">City</label><input name="customer_city_new" class="form-control"></div>
<div class="col-md-6"><label class="small fw-bold">State</label><input name="customer_state_new" class="form-control"></div>
</div></div></div>

<div class="page-card mb-3"><div class="page-head"><div class="section-title">Pawn Profile</div></div><div class="card-body-x"><div class="row g-2">
<div class="col-md-4"><label class="small fw-bold">Guardian Name</label><input name="guardian_name" class="form-control"></div>
<div class="col-md-4"><label class="small fw-bold">Occupation</label><input name="occupation" class="form-control"></div>
<div class="col-md-4"><label class="small fw-bold">Annual Income</label><input type="number" min="0" step="0.01" name="annual_income" class="form-control"></div>
<div class="col-md-4"><label class="small fw-bold">Reference Name</label><input name="reference_name" class="form-control"></div>
<div class="col-md-4"><label class="small fw-bold">Reference Mobile</label><input name="reference_mobile" class="form-control"></div>
<div class="col-md-4"><label class="small fw-bold">Credit Limit</label><input type="number" min="0" step="0.01" name="credit_limit" class="form-control"></div>
<div class="col-md-4"><label class="small fw-bold">Risk Category</label><select name="risk_category" class="form-select"><option>Low</option><option>Medium</option><option>High</option></select></div>
<div class="col-md-4 d-flex align-items-end"><label class="d-flex gap-2 align-items-center"><input type="checkbox" name="kyc_verified" value="1"> KYC Verified</label></div>
<div class="col-12"><label class="small fw-bold">Notes</label><textarea name="notes" class="form-control" rows="3"></textarea></div>
</div></div></div>
</div>

<div class="col-xl-4">
<div class="page-card"><div class="page-head"><div class="section-title">Documents</div></div><div class="card-body-x">
<div class="mb-3"><label class="small fw-bold">Customer Photo</label><input type="file" name="photo" class="form-control" accept=".jpg,.jpeg,.png,.webp"><div id="photoPreview" class="mt-2"></div></div>
<div class="mb-3"><label class="small fw-bold">Signature</label><input type="file" name="signature" class="form-control" accept=".jpg,.jpeg,.png,.webp"><div id="signaturePreview" class="mt-2"></div></div>
<div class="mb-3"><label class="small fw-bold">KYC Document</label><input type="file" name="kyc_document" class="form-control" accept=".jpg,.jpeg,.png,.webp,.pdf"><div id="kycPreview" class="mt-2"></div></div>
<button type="submit" id="saveBtn" class="btn-theme w-100">Save Pawn Customer</button>
</div></div>
</div>
</div>
</form>
<?php include('includes/footer.php'); ?></div></main><?php include('includes/script.php'); ?><script src="assets/js/script.js"></script>
<script>
(()=>{'use strict';const api='api/pawn-customers.php',csrf=<?=json_encode($csrfToken)?>,editId=<?=$editId?>;
function esc(v){return String(v??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]))}
function toast(t,m){const x=document.createElement('div');x.className='theme-toast theme-toast-'+t;x.textContent=m;document.body.appendChild(x);requestAnimationFrame(()=>x.classList.add('show'));setTimeout(()=>{x.classList.remove('show');setTimeout(()=>x.remove(),200)},3200)}
async function req(d){const f=new FormData();Object.entries(d).forEach(([k,v])=>f.append(k,v));f.append('csrf_token',csrf);const r=await fetch(api,{method:'POST',body:f,credentials:'same-origin',headers:{Accept:'application/json'}}),raw=await r.text();let j;try{j=JSON.parse(raw)}catch(e){throw new Error('Pawn customer API did not return JSON. HTTP '+r.status+': '+raw.replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').slice(0,300))}if(!r.ok||!j.success)throw new Error(j.message||'Request failed');return j}
function fileView(path,type){if(!path)return'';if(type==='pdf'||path.toLowerCase().endsWith('.pdf'))return `<a class="doc-link" target="_blank" href="${esc(path)}">Open document</a>`;return `<img class="preview" src="${esc(path)}">`}
async function init(){try{const d=await req({action:'options',edit_id:editId});customerSelect.innerHTML='<option value="">Select existing customer</option>'+d.customers.map(c=>`<option value="${c.id}">${esc(c.customer_name)} - ${esc(c.customer_code)} - ${esc(c.mobile||'')}</option>`).join('');if(d.customer){const c=d.customer;customerSelect.value=c.customer_id;for(const [n,v] of Object.entries(c)){const el=document.querySelector(`[name="${n}"]`);if(!el)continue;if(el.type==='checkbox')el.checked=Number(v)===1;else el.value=v??''}existingPhoto.value=c.photo_path||'';existingSignature.value=c.signature_path||'';existingKyc.value=c.kyc_document_path||'';photoPreview.innerHTML=fileView(c.photo_path,'image');signaturePreview.innerHTML=fileView(c.signature_path,'image');kycPreview.innerHTML=fileView(c.kyc_document_path,'document')}}catch(e){toast('error',e.message)}}
customerForm.addEventListener('submit',async e=>{e.preventDefault();const btn=saveBtn,old=btn.innerHTML;btn.disabled=true;btn.innerHTML='Saving...';try{const r=await fetch(api,{method:'POST',body:new FormData(e.target),credentials:'same-origin',headers:{Accept:'application/json'}}),raw=await r.text();let d;try{d=JSON.parse(raw)}catch(x){throw new Error(raw.slice(0,300))}if(!r.ok||!d.success)throw new Error(d.message||'Unable to save');toast('success',d.message);setTimeout(()=>location.href='pawn-customer-view.php?id='+d.pawn_customer_id,600)}catch(err){toast('error',err.message)}finally{btn.disabled=false;btn.innerHTML=old}});
init();
})();
</script></body></html>