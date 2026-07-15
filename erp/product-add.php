<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));
foreach ([__DIR__.'/config/config.php',__DIR__.'/config.php',__DIR__.'/includes/config.php',__DIR__.'/super-admin/includes/config.php'] as $f){if(is_file($f)){require_once $f;break;}}
if(!isset($conn)||!($conn instanceof mysqli))die('Database configuration is not available.');$conn->set_charset('utf8mb4');if(empty($_SESSION['user_id'])){header('Location: login.php');exit;}
function e($v):string{return htmlspecialchars((string)($v??''),ENT_QUOTES,'UTF-8');}
function canProduct(mysqli $conn,string $action):bool{if(($_SESSION['user_type']??'')==='Platform Admin')return true;$map=['open'=>'can_open','create'=>'can_create','update'=>'can_update'];$field=$map[$action]??'';foreach(['perm.products','perm.products.list'] as $k){if(isset($_SESSION['permissions'][$k][$field]))return(int)$_SESSION['permissions'][$k][$field]===1;}return false;}
if(!canProduct($conn,'create')){http_response_code(403);die('You do not have permission to create products.');}$businessId=(int)($_SESSION['business_id']??0);if($businessId<=0)die('A valid business must be selected.');if(empty($_SESSION['products_csrf']))$_SESSION['products_csrf']=bin2hex(random_bytes(32));$csrfToken=$_SESSION['products_csrf'];
$categories=[];$metals=[];foreach([['SELECT id,category_name name FROM product_categories WHERE business_id=? AND is_active=1 ORDER BY category_name','categories'],['SELECT id,metal_name name FROM metals WHERE business_id=? AND is_active=1 ORDER BY metal_name','metals']] as $q){$stmt=$conn->prepare($q[0]);if($stmt){$stmt->bind_param('i',$businessId);$stmt->execute();$r=$stmt->get_result();while($x=$r->fetch_assoc())${$q[1]}[]=$x;$stmt->close();}}
$theme=['primary_color'=>'#d89416','primary_dark_color'=>'#b86a0b','primary_soft_color'=>'#fff6e5','page_background'=>'#f4f3f0','card_background'=>'#fff','text_color'=>'#171717','muted_text_color'=>'#7d8794','border_color'=>'#e8e8e8','font_family'=>'Inter','heading_font_family'=>'Playfair Display','border_radius_px'=>12,'sidebar_width_px'=>230,'sidebar_gradient_1'=>'#171c21','sidebar_gradient_2'=>'#20272d','sidebar_gradient_3'=>'#101419'];$stmt=$conn->prepare('SELECT * FROM business_theme_settings WHERE business_id=? LIMIT 1');if($stmt){$stmt->bind_param('i',$businessId);$stmt->execute();$x=$stmt->get_result()->fetch_assoc()?:[];$stmt->close();foreach($theme as $k=>$v)if(isset($x[$k])&&$x[$k]!=='')$theme[$k]=$x[$k];}
$pageTitle='Add Product';$businessName=(string)($_SESSION['business_name']??'Jewellery ERP');
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($businessName)?> - Add Product</title><?php include('includes/links.php'); ?><style>
:root{--primary:<?=e($theme['primary_color'])?>;--primary-dark:<?=e($theme['primary_dark_color'])?>;--primary-soft:<?=e($theme['primary_soft_color'])?>;--page-bg:<?=e($theme['page_background'])?>;--card-bg:<?=e($theme['card_background'])?>;--text:<?=e($theme['text_color'])?>;--muted:<?=e($theme['muted_text_color'])?>;--line:<?=e($theme['border_color'])?>;--radius:<?=(int)$theme['border_radius_px']?>px;--sidebar-width:<?=(int)$theme['sidebar_width_px']?>px;--sidebar-gradient-1:<?=e($theme['sidebar_gradient_1'])?>;--sidebar-gradient-2:<?=e($theme['sidebar_gradient_2'])?>;--sidebar-gradient-3:<?=e($theme['sidebar_gradient_3'])?>}body{background:var(--page-bg);color:var(--text);font-family:<?=json_encode($theme['font_family'])?>,sans-serif}.sidebar{background:linear-gradient(180deg,var(--sidebar-gradient-1),var(--sidebar-gradient-2),var(--sidebar-gradient-3))!important}.form-card{background:var(--card-bg);border:1px solid var(--line);border-radius:var(--radius);overflow:hidden}.form-head{padding:16px 18px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center}.form-title{font:700 20px <?=json_encode($theme['heading_font_family'])?>,serif}.section{padding:18px;border-bottom:1px solid var(--line)}.section:last-child{border-bottom:0}.section-title{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;margin-bottom:14px;color:var(--primary-dark)}.field-label{font-size:10px;font-weight:700;margin-bottom:5px}.form-control,.form-select{font-size:11px;min-height:38px;border-color:var(--line);border-radius:9px;background:var(--card-bg);color:var(--text)}.btn-theme{background:linear-gradient(135deg,var(--primary),var(--primary-dark));border:0;color:#fff;font-size:11px;font-weight:700;border-radius:9px;padding:9px 15px}.preview{width:140px;height:140px;border:1px dashed var(--line);border-radius:12px;display:grid;place-items:center;overflow:hidden;background:var(--primary-soft);color:var(--primary-dark)}.preview img{width:100%;height:100%;object-fit:cover}.theme-toast{position:fixed;right:18px;top:78px;z-index:20000;padding:11px 14px;border-radius:10px;color:#fff;font-size:11px;font-weight:600;opacity:0;transform:translateY(-10px);transition:.22s}.theme-toast.show{opacity:1;transform:none}.theme-toast-success{background:#168449}.theme-toast-error{background:#c0392b}body.dark-mode,body[data-theme=dark]{--page-bg:#0f151b;--card-bg:#182129;--text:#f3f6f8;--muted:#9aa7b3;--line:#2c3944}
</style></head><body><?php include('includes/sidebar.php'); ?><main class="app-main"><?php include('includes/nav.php'); ?><div class="content-wrap"><form class="form-card" id="productForm" enctype="multipart/form-data"><div class="form-head"><div><div class="form-title">Add Product</div><div class="small text-muted">Create a jewellery product master record.</div></div><a href="products.php" class="btn btn-light btn-sm"><i class="fa-solid fa-arrow-left me-2"></i>Back</a></div><input type="hidden" name="csrf_token" value="<?=e($csrfToken)?>"><input type="hidden" name="action" value="save"><input type="hidden" name="product_id" value="0">
<div class="section"><div class="section-title">Basic Information</div><div class="row g-3"><div class="col-md-4"><label class="field-label">Category *</label><select class="form-select" name="category_id" required><option value="">Select category</option><?php foreach($categories as $x):?><option value="<?=(int)$x['id']?>"><?=e($x['name'])?></option><?php endforeach?></select></div><div class="col-md-4"><label class="field-label">Product code</label><input class="form-control" name="product_code" placeholder="Auto generated when empty"></div><div class="col-md-4"><label class="field-label">Barcode</label><input class="form-control" name="barcode"></div><div class="col-md-6"><label class="field-label">Product name *</label><input class="form-control" name="product_name" maxlength="180" required></div><div class="col-md-3"><label class="field-label">HSN code</label><input class="form-control" name="hsn_code" maxlength="20"></div><div class="col-md-3"><label class="field-label">Purity %</label><input class="form-control" type="number" step="0.0001" name="purity" value="91.6000"></div></div></div>
<div class="section">
    <div class="section-title">Metal, Unit & Weight</div>
    <div class="row g-3">
        <div class="col-md-3">
            <label class="field-label">Metal</label>
            <select class="form-select" name="metal_id">
                <option value="0">Select metal</option>
                <?php foreach($metals as $x):?>
                    <option value="<?=(int)$x['id']?>"><?=e($x['name'])?></option>
                <?php endforeach?>
            </select>
        </div>

        <div class="col-md-3">
            <label class="field-label">Unit</label>
            <input class="form-control"
                   type="text"
                   name="unit_name"
                   maxlength="50"
                   placeholder="Example: Gram, Piece, Pair">
            <div class="small text-muted mt-1">
                
            </div>
        </div>

        <div class="col-md-2">
            <label class="field-label">Gross weight</label>
            <input class="form-control weight"
                   type="number"
                   step="0.001"
                   min="0"
                   name="gross_weight"
                   value="0">
        </div>

        <div class="col-md-2">
            <label class="field-label">Stone weight</label>
            <input class="form-control weight"
                   type="number"
                   step="0.001"
                   min="0"
                   name="stone_weight"
                   value="0">
        </div>

        <div class="col-md-2">
            <label class="field-label">Net weight</label>
            <input class="form-control"
                   type="number"
                   step="0.001"
                   min="0"
                   name="net_weight"
                   value="0"
                   readonly>
        </div>
    </div>
</div>
<div class="section"><div class="section-title">Charges & Rates</div><div class="row g-3"><div class="col-md-3"><label class="field-label">Making charge type</label><select class="form-select" name="making_charge_type"><option>Per Gram</option><option>Fixed</option><option>Percentage</option></select></div><div class="col-md-3"><label class="field-label">Making charge</label><input class="form-control" type="number" step="0.01" name="making_charge" value="0"></div><div class="col-md-3"><label class="field-label">Wastage %</label><input class="form-control" type="number" step="0.001" name="wastage_percent" value="0"></div><div class="col-md-3"><label class="field-label">Tax %</label><input class="form-control" type="number" step="0.001" name="tax_percent" value="3"></div><div class="col-md-4"><label class="field-label">Purchase rate</label><input class="form-control" type="number" step="0.01" name="purchase_rate" value="0"></div><div class="col-md-4"><label class="field-label">Sale rate</label><input class="form-control" type="number" step="0.01" name="sale_rate" value="0"></div><div class="col-md-4"><label class="field-label">Minimum stock qty</label><input class="form-control" type="number" step="0.001" name="minimum_stock_qty" value="0"></div></div></div>
<div class="section"><div class="section-title">Other Information</div><div class="row g-3"><div class="col-md-8"><label class="field-label">Description</label><textarea class="form-control" rows="5" name="description"></textarea><div class="row g-3 mt-1">
    <div class="col-md-6">
        <div class="form-check">
            <input class="form-check-input"
                   type="checkbox"
                   name="track_stock"
                   value="1"
                   id="track_stock"
                   checked>
            <label class="form-check-label fw-semibold" for="track_stock">
                Track stock
            </label>
        </div>
        <div class="small text-muted ms-4 mt-1">
            Enable this when purchase and sales transactions should increase or reduce this product's stock quantity.
        </div>
    </div>

    <div class="col-md-6">
        <div class="form-check">
            <input class="form-check-input"
                   type="checkbox"
                   name="is_active"
                   value="1"
                   id="is_active"
                   checked>
            <label class="form-check-label fw-semibold" for="is_active">
                Active
            </label>
        </div>
        <div class="small text-muted ms-4 mt-1">
            Active products appear in billing, purchase and inventory screens. Disable it to hide the product without deleting it.
        </div>
    </div>
</div></div><div class="col-md-4"><label class="field-label">Product image</label><div class="preview mb-2" id="preview"><i class="fa-solid fa-image fa-2x"></i></div><input class="form-control" type="file" name="image" id="image" accept="image/jpeg,image/png,image/webp,image/gif"></div></div></div>
<div class="section text-end"><a href="products.php" class="btn btn-light btn-sm me-2">Cancel</a><button class="btn btn-theme" id="saveBtn"><i class="fa-solid fa-floppy-disk me-2"></i>Save Product</button></div></form><?php include('includes/footer.php'); ?></div></main><?php include('includes/script.php'); ?><script src="assets/js/script.js"></script><script>
(()=>{const f=document.getElementById('productForm'),b=document.getElementById('saveBtn');function toast(t,m){const x=document.createElement('div');x.className='theme-toast theme-toast-'+t;x.textContent=m;document.body.appendChild(x);requestAnimationFrame(()=>x.classList.add('show'));setTimeout(()=>{x.classList.remove('show');setTimeout(()=>x.remove(),250)},3000)}document.querySelectorAll('.weight').forEach(x=>x.addEventListener('input',()=>{const g=parseFloat(f.gross_weight.value)||0,s=parseFloat(f.stone_weight.value)||0;f.net_weight.value=Math.max(0,g-s).toFixed(3)}));document.getElementById('image').addEventListener('change',e=>{const file=e.target.files[0];if(!file)return;const img=document.createElement('img');img.src=URL.createObjectURL(file);document.getElementById('preview').replaceChildren(img)});f.addEventListener('submit',async e=>{e.preventDefault();const unit=(f.unit_name?.value||'').trim();if(!unit){toast('error','Enter the product unit.');f.unit_name?.focus();return;}const old=b.innerHTML;b.disabled=true;b.innerHTML='<i class="fa-solid fa-spinner fa-spin me-2"></i>Saving...';try{const r=await fetch('api/products-save.php',{method:'POST',body:new FormData(f),credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}}),j=await r.json().catch(()=>({success:false,message:'Invalid server response.'}));if(!r.ok||!j.success)throw new Error(j.message||'Unable to save product.');toast('success',j.message);setTimeout(()=>location.href='products.php',600)}catch(x){toast('error',x.message)}finally{b.disabled=false;b.innerHTML=old}})})();
</script></body></html>
