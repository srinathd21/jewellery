<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));

foreach ([
    __DIR__ . '/config/config.php',
    __DIR__ . '/config.php',
    __DIR__ . '/includes/config.php',
    __DIR__ . '/super-admin/includes/config.php'
] as $file) {
    if (is_file($file)) {
        require_once $file;
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

function h($value)
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function stockAdjustmentPermissionBulk(mysqli $conn, $action)
{
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin') {
        return true;
    }
    $map = ['open' => 'can_open', 'view' => 'can_view', 'create' => 'can_create', 'update' => 'can_update'];
    $field = $map[$action] ?? '';
    if ($field === '') {
        return false;
    }
    foreach (['perm.inventory.stock_adjustment', 'perm.inventory.stock', 'perm.inventory'] as $key) {
        if (isset($_SESSION['permissions'][$key][$field])) {
            return (int)$_SESSION['permissions'][$key][$field] === 1;
        }
    }
    $businessId = (int)($_SESSION['business_id'] ?? 0);
    $roleId = (int)($_SESSION['role_id'] ?? 0);
    if ($businessId <= 0 || $roleId <= 0) {
        return false;
    }
    $sql = "SELECT rp.`{$field}`
            FROM role_permissions rp
            INNER JOIN permissions p ON p.id=rp.permission_id
            WHERE rp.business_id=? AND rp.role_id=? AND p.is_active=1
              AND p.permission_code IN ('perm.inventory.stock_adjustment','perm.inventory.stock','perm.inventory')
            ORDER BY FIELD(p.permission_code,'perm.inventory.stock_adjustment','perm.inventory.stock','perm.inventory')
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ii', $businessId, $roleId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row[$field] ?? 0) === 1;
}

if (!stockAdjustmentPermissionBulk($conn, 'open')) {
    http_response_code(403);
    die('Access denied.');
}
$canCreate = stockAdjustmentPermissionBulk($conn, 'create') || stockAdjustmentPermissionBulk($conn, 'update');
if (!$canCreate) {
    http_response_code(403);
    die('You do not have permission to add stock.');
}

$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? ($_SESSION['default_branch_id'] ?? 0));
if ($businessId <= 0 || $branchId <= 0) {
    die('Business or branch session not found. Please login again.');
}

if (empty($_SESSION['stock_adjustment_csrf'])) {
    $_SESSION['stock_adjustment_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['stock_adjustment_csrf'];
$businessName = (string)($_SESSION['business_name'] ?? 'Jewellery ERP');
$movementDate = date('Y-m-d\TH:i');

$bulkProducts = [];
$bulkProductSql = "SELECT p.id,
                          p.product_name,
                          p.product_code,
                          p.barcode,
                          COALESCE(p.net_weight,0) AS unit_net_weight,
                          COALESCE(ps.quantity,0) AS current_qty
                   FROM products p
                   LEFT JOIN product_stock ps
                          ON ps.product_id = p.id
                         AND ps.business_id = p.business_id
                         AND ps.branch_id = ?
                   WHERE p.business_id = ?
                     AND p.is_active = 1
                     AND COALESCE(p.track_stock,1) = 1
                   ORDER BY p.product_name ASC, p.id ASC";

$bulkStmt = $conn->prepare($bulkProductSql);
if ($bulkStmt) {
    $bulkStmt->bind_param('ii', $branchId, $businessId);
    $bulkStmt->execute();
    $bulkRes = $bulkStmt->get_result();

    while ($bulkRow = $bulkRes->fetch_assoc()) {
        $label = (string)$bulkRow['product_name'];

        if (!empty($bulkRow['product_code'])) {
            $label .= ' - ' . $bulkRow['product_code'];
        }

        if (!empty($bulkRow['barcode'])) {
            $label .= ' [' . $bulkRow['barcode'] . ']';
        }

        $bulkProducts[] = [
            'id' => (int)$bulkRow['id'],
            'text' => $label,
            'product_name' => (string)$bulkRow['product_name'],
            'product_code' => (string)($bulkRow['product_code'] ?? ''),
            'barcode' => (string)($bulkRow['barcode'] ?? ''),
            'current_qty' => (float)($bulkRow['current_qty'] ?? 0),
            'unit_net_weight' => (float)($bulkRow['unit_net_weight'] ?? 0)
        ];
    }

    $bulkStmt->close();
}

$bulkProductsJson = json_encode(
    $bulkProducts,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);

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
    'border_radius_px' => 12,
    'sidebar_width_px' => 230
];
$stmt = $conn->prepare('SELECT * FROM business_theme_settings WHERE business_id=? LIMIT 1');
if ($stmt) {
    $stmt->bind_param('i', $businessId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    foreach ($theme as $k => $v) {
        if (isset($row[$k]) && $row[$k] !== '') {
            $theme[$k] = $row[$k];
        }
    }
}

$pageTitle = 'Bulk Add Stock';
$page_title = 'Bulk Add Stock';
$currentPage = 'stock-adjustment';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?php echo h($businessName); ?> - Bulk Add Stock</title>
    <?php include('includes/links.php'); ?>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
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
        body{background:var(--page-bg);color:var(--text-color);font-family:<?php echo json_encode($theme['font_family']); ?>,sans-serif;}
        .sidebar{background:linear-gradient(180deg,<?php echo h($theme['sidebar_gradient_1']); ?>,<?php echo h($theme['sidebar_gradient_2']); ?>,<?php echo h($theme['sidebar_gradient_3']); ?>)!important;}
        .panel{background:var(--card-bg);border:1px solid var(--border-color);border-radius:var(--radius);padding:14px;margin-bottom:10px;}
        .panel-title{font-size:13px;font-weight:800;margin:0;}
        .muted{font-size:10px;color:var(--muted-color);}
        .form-control,.form-select{font-size:11px;min-height:36px;border-radius:9px;border-color:var(--border-color);}
        .btn-theme{background:linear-gradient(135deg,var(--primary),var(--primary-dark));color:#fff;border:0;border-radius:9px;font-size:11px;font-weight:700;padding:9px 14px;}
        .btn-soft{background:var(--primary-soft);color:var(--primary-dark);border:1px solid color-mix(in srgb,var(--primary) 20%,var(--border-color));border-radius:9px;font-size:11px;font-weight:700;padding:9px 14px;}
        .select2-container{width:100%!important;}
        .select2-container .select2-selection--single{height:38px;border:1px solid var(--border-color);border-radius:9px;background:var(--card-bg);}
        .select2-container .select2-selection--single .select2-selection__rendered{line-height:36px;font-size:11px;padding-left:12px;color:var(--text-color);}
        .select2-container .select2-selection--single .select2-selection__arrow{height:36px;}
        .selected-product-box{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-top:10px;}
        .metric{border:1px solid var(--border-color);border-radius:9px;padding:10px;background:var(--card-bg);}
        .metric span{display:block;font-size:9px;color:var(--muted-color);text-transform:uppercase;}
        .metric b{display:block;font-size:13px;margin-top:3px;}
        .cart-table{font-size:10px;margin:0;}
        .cart-table th{font-size:9px;text-transform:uppercase;color:var(--muted-color);white-space:nowrap;}
        .cart-table td,.cart-table th{vertical-align:middle;padding:8px 9px;}
        .qty-input{max-width:120px;min-width:90px;}
        .remove-btn{width:30px;height:30px;border:1px solid var(--border-color);background:var(--card-bg);border-radius:8px;color:#bd2d2d;}
        .empty-cart{text-align:center;padding:30px 15px;color:var(--muted-color);font-size:11px;}
        .summary-strip{display:flex;gap:8px;flex-wrap:wrap;}
        .summary-pill{padding:6px 9px;border-radius:999px;background:var(--primary-soft);color:var(--primary-dark);font-size:10px;font-weight:700;}
        .theme-toast{position:fixed;right:18px;top:78px;z-index:20000;padding:11px 14px;border-radius:10px;color:#fff;font-size:11px;font-weight:600;opacity:0;transform:translateY(-10px);transition:.2s;}
        .theme-toast.show{opacity:1;transform:none}.theme-toast-success{background:#168449}.theme-toast-error{background:#c0392b}
        @media(max-width:767px){.selected-product-box{grid-template-columns:1fr}.content-wrap{padding-left:10px;padding-right:10px}.cart-table{min-width:760px}}
    </style>
</head>
<body>
<?php include('includes/sidebar.php'); ?>
<main class="app-main">
    <?php include('includes/nav.php'); ?>
    <div class="content-wrap">
        <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-2">
            <div>
                <div style="font-size:18px;font-weight:800">Bulk Add Stock</div>
                <div class="muted">Select a product, enter quantity, add it to the cart, then continue with the next product.</div>
            </div>
            <a href="stock-adjustment.php" class="btn btn-light btn-sm"><i class="fa-solid fa-arrow-left me-2"></i>Back to Stock Adjustment</a>
        </div>

        <div class="panel">
            <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-3">
                <div class="panel-title">Add Product to Cart</div>
                <div class="summary-strip">
                    <span class="summary-pill" id="cartCountPill">0 Products</span>
                    <span class="summary-pill" id="cartQtyPill">0.000 Qty</span>
                </div>
            </div>
            <div class="row g-3 align-items-end">
                <div class="col-lg-7">
                    <label class="form-label">Product *</label>
                    <select id="bulkProduct" class="form-select">
                        <option value=""></option>
                        <?php foreach ($bulkProducts as $bulkProduct): ?>
                            <option
                                value="<?php echo (int)$bulkProduct['id']; ?>"
                                data-product-name="<?php echo h($bulkProduct['product_name']); ?>"
                                data-product-code="<?php echo h($bulkProduct['product_code']); ?>"
                                data-barcode="<?php echo h($bulkProduct['barcode']); ?>"
                                data-current-qty="<?php echo h(number_format((float)$bulkProduct['current_qty'], 3, '.', '')); ?>"
                                data-unit-net-weight="<?php echo h(number_format((float)$bulkProduct['unit_net_weight'], 3, '.', '')); ?>">
                                <?php echo h($bulkProduct['text']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="muted mt-1">Search by product name, product code or barcode. Only active products are shown.</div>
                </div>
                <div class="col-lg-3">
                    <label class="form-label">Add Quantity *</label>
                    <input type="number" id="bulkQty" class="form-control" step="0.001" min="0.001" placeholder="0.000">
                </div>
                <div class="col-lg-2 d-grid">
                    <button type="button" class="btn btn-theme" id="addToCartBtn"><i class="fa-solid fa-cart-plus me-2"></i>Add</button>
                </div>
            </div>
            <div class="selected-product-box d-none" id="selectedInfo">
                <div class="metric"><span>Current Quantity</span><b id="selectedQty">0.000</b></div>
                <div class="metric"><span>Net Weight / Qty</span><b id="selectedNet">0.000 g</b></div>
                <div class="metric"><span>After Add</span><b id="selectedAfter">0.000</b></div>
            </div>
        </div>

        <div class="panel">
            <div class="panel-title mb-3">Bulk Stock Cart</div>
            <div class="table-responsive">
                <table class="table cart-table align-middle">
                    <thead>
                    <tr>
                        <th>#</th><th>Product</th><th>Code / Barcode</th><th class="text-end">Current Qty</th><th class="text-end">Net / Qty</th><th>Add Qty</th><th class="text-end">New Qty</th><th class="text-end">Action</th>
                    </tr>
                    </thead>
                    <tbody id="cartBody"></tbody>
                </table>
            </div>
            <div class="empty-cart" id="emptyCart"><i class="fa-solid fa-cart-shopping d-block mb-2" style="font-size:24px"></i>No products added yet.</div>
        </div>

        <form id="bulkSaveForm" class="panel">
            <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
            <input type="hidden" name="items_json" id="itemsJson" value="[]">
            <div class="panel-title mb-3">Save Bulk Adjustment</div>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Reason Type *</label>
                    <select class="form-select" name="reason_type" required>
                        <option value="Opening Stock">Opening Stock</option>
                        <option value="Physical Count Correction">Physical Count Correction</option>
                        <option value="Excess Found">Excess Found</option>
                        <option value="Data Correction">Data Correction</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Movement Date & Time *</label>
                    <input class="form-control" type="datetime-local" name="movement_date" value="<?php echo h($movementDate); ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Adjusted By</label>
                    <input class="form-control" value="<?php echo h((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Current User')); ?>" readonly>
                </div>
                <div class="col-12">
                    <label class="form-label">Remarks <span class="muted">(Optional)</span></label>
                    <textarea class="form-control" name="remarks" rows="2" maxlength="500" placeholder="Optional remarks for this bulk stock addition"></textarea>
                </div>
                <div class="col-12 d-flex justify-content-end gap-2 flex-wrap">
                    <button type="button" class="btn btn-light btn-sm" id="clearCartBtn">Clear Cart</button>
                    <button type="submit" class="btn btn-theme" id="saveBulkBtn"><i class="fa-solid fa-floppy-disk me-2"></i>Save All Stock</button>
                </div>
            </div>
        </form>
        <?php include('includes/footer.php'); ?>
    </div>
</main>
<?php include('includes/script.php'); ?>
<script>
if (typeof window.jQuery === 'undefined') {
    document.write('<script src="https://code.jquery.com/jquery-3.7.1.min.js"><\\/script>');
}
</script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="assets/js/script.js"></script>
<script>
(function(){
    'use strict';
    const cart = new Map();
    const productEl = document.getElementById('bulkProduct');
    const qtyEl = document.getElementById('bulkQty');
    const infoEl = document.getElementById('selectedInfo');
    const cartBody = document.getElementById('cartBody');
    const emptyCart = document.getElementById('emptyCart');
    const itemsJson = document.getElementById('itemsJson');
    const saveBtn = document.getElementById('saveBulkBtn');
    let selectedProduct = null;

    function toast(type,msg){
        const t=document.createElement('div');
        t.className='theme-toast theme-toast-'+type;
        t.textContent=msg;
        document.body.appendChild(t);
        requestAnimationFrame(()=>t.classList.add('show'));
        setTimeout(()=>{t.classList.remove('show');setTimeout(()=>t.remove(),250)},3200);
    }
    function n(value){const x=Number(value||0);return Number.isFinite(x)?x:0;}
    function f3(value){return n(value).toFixed(3);}
    function esc(v){return String(v==null?'':v).replace(/[&<>"']/g,function(ch){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch];});}

    function syncSelectedPreview(){
        if(!selectedProduct){infoEl.classList.add('d-none');return;}
        const addQty=Math.max(0,n(qtyEl.value));
        document.getElementById('selectedQty').textContent=f3(selectedProduct.current_qty);
        document.getElementById('selectedNet').textContent=f3(selectedProduct.unit_net_weight)+' g';
        document.getElementById('selectedAfter').textContent=f3(n(selectedProduct.current_qty)+addQty);
        infoEl.classList.remove('d-none');
    }

    function renderCart(){
        const rows=Array.from(cart.values());
        cartBody.innerHTML=rows.map(function(row,index){
            return '<tr data-id="'+Number(row.product_id)+'">'+
                '<td>'+(index+1)+'</td>'+
                '<td><b>'+esc(row.product_name)+'</b></td>'+
                '<td><div>'+esc(row.product_code||'-')+'</div><div class="muted">'+esc(row.barcode||'')+'</div></td>'+
                '<td class="text-end">'+f3(row.current_qty)+'</td>'+
                '<td class="text-end">'+f3(row.unit_net_weight)+' g</td>'+
                '<td><input class="form-control qty-input cart-qty" type="number" min="0.001" step="0.001" value="'+f3(row.qty)+'"></td>'+
                '<td class="text-end new-qty">'+f3(n(row.current_qty)+n(row.qty))+'</td>'+
                '<td class="text-end"><button type="button" class="remove-btn remove-row" title="Remove"><i class="fa-solid fa-trash"></i></button></td>'+
            '</tr>';
        }).join('');
        emptyCart.classList.toggle('d-none',rows.length>0);
        const payload=rows.map(r=>({product_id:Number(r.product_id),qty:n(r.qty)}));
        itemsJson.value=JSON.stringify(payload);
        document.getElementById('cartCountPill').textContent=rows.length+' Product'+(rows.length===1?'':'s');
        const totalQty=rows.reduce((sum,r)=>sum+n(r.qty),0);
        document.getElementById('cartQtyPill').textContent=f3(totalQty)+' Qty';
    }

    const bulkProducts = <?php echo $bulkProductsJson ?: '[]'; ?>;

    function getSelectedProductFromOption(){
        if(!productEl || !productEl.value){
            return null;
        }

        const option = productEl.options[productEl.selectedIndex];
        if(!option){
            return null;
        }

        return {
            product_id:Number(option.value||0),
            product_name:option.dataset.productName||'',
            product_code:option.dataset.productCode||'',
            barcode:option.dataset.barcode||'',
            current_qty:n(option.dataset.currentQty),
            unit_net_weight:n(option.dataset.unitNetWeight)
        };
    }

    if(window.jQuery && window.jQuery.fn && window.jQuery.fn.select2){
        const $=window.jQuery;

        $('#bulkProduct').select2({
            width:'100%',
            placeholder:'Search / Select Product',
            allowClear:true,
            minimumResultsForSearch:0
        }).on('select2:select change',function(){
            selectedProduct=getSelectedProductFromOption();

            if(!selectedProduct || !selectedProduct.product_id){
                selectedProduct=null;
                syncSelectedPreview();
                return;
            }

            qtyEl.value='';
            syncSelectedPreview();
            qtyEl.focus();
        }).on('select2:clear',function(){
            selectedProduct=null;
            syncSelectedPreview();
        });
    } else {
        toast('error','Select2 library could not be loaded.');
    }

    qtyEl.addEventListener('input',syncSelectedPreview);

    document.getElementById('addToCartBtn').addEventListener('click',function(){
        if(!selectedProduct||!selectedProduct.product_id){toast('error','Please select a product.');return;}
        const qty=n(qtyEl.value);
        if(qty<=0){toast('error','Enter add quantity greater than zero.');qtyEl.focus();return;}
        const id=selectedProduct.product_id;
        if(cart.has(id)){
            const existing=cart.get(id);
            existing.qty=n(existing.qty)+qty;
            cart.set(id,existing);
        }else{
            cart.set(id,Object.assign({},selectedProduct,{qty:qty}));
        }
        renderCart();
        toast('success',selectedProduct.product_name+' added to cart.');
        selectedProduct=null;
        qtyEl.value='';
        infoEl.classList.add('d-none');
        if(window.jQuery){window.jQuery('#bulkProduct').val(null).trigger('change');window.jQuery('#bulkProduct').select2('open');}
    });

    cartBody.addEventListener('input',function(e){
        const input=e.target.closest('.cart-qty');
        if(!input)return;
        const tr=input.closest('tr');
        const id=Number(tr.dataset.id||0);
        const row=cart.get(id);
        if(!row)return;
        row.qty=Math.max(0.001,n(input.value));
        cart.set(id,row);
        tr.querySelector('.new-qty').textContent=f3(n(row.current_qty)+n(row.qty));
        renderCart();
    });

    cartBody.addEventListener('click',function(e){
        const btn=e.target.closest('.remove-row');
        if(!btn)return;
        const tr=btn.closest('tr');
        cart.delete(Number(tr.dataset.id||0));
        renderCart();
    });

    document.getElementById('clearCartBtn').addEventListener('click',function(){cart.clear();renderCart();});

    document.getElementById('bulkSaveForm').addEventListener('submit',async function(e){
        e.preventDefault();
        if(cart.size===0){toast('error','Add at least one product to the cart.');return;}
        renderCart();
        const old=saveBtn.innerHTML;
        saveBtn.disabled=true;
        saveBtn.innerHTML='<i class="fa-solid fa-spinner fa-spin me-2"></i>Saving...';
        try{
            const r=await fetch('api/bulk-stock-adjustment-save.php',{method:'POST',body:new FormData(this),credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}});
            const raw=await r.text();
            let j;
            try{j=JSON.parse(raw);}catch(err){throw new Error(raw?'Invalid server response: '+raw.substring(0,180):'Empty server response.');}
            if(!r.ok||!j.success)throw new Error(j.message||'Unable to save bulk stock.');
            toast('success',j.message);
            cart.clear();
            renderCart();
            this.querySelector('textarea[name="remarks"]').value='';
        }catch(err){toast('error',err.message);}finally{saveBtn.disabled=false;saveBtn.innerHTML=old;}
    });

    renderCart();
})();
</script>
</body>
</html>