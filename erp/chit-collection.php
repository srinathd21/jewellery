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

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('tableExists')) {
    function tableExists(mysqli $conn, string $table): bool
    {
        $table = $conn->real_escape_string($table);
        $res = $conn->query("SHOW TABLES LIKE '{$table}'");
        return $res && $res->num_rows > 0;
    }
}

$pageTitle = 'Chit Collection';
$page_title = 'Chit Collection';
$currentPage = 'chit-collection';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? $_SESSION['default_branch_id'] ?? 0);

if ($businessId <= 0 || $branchId <= 0) {
    http_response_code(403);
    die('A valid business and branch must be selected before collecting chit payments.');
}

function chitCollectionPermission(mysqli $conn, string $action): bool
{
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin') {
        return true;
    }

    $map = [
        'open' => 'can_open',
        'view' => 'can_view',
        'value' => 'can_view_value',
        'create' => 'can_create',
        'update' => 'can_update',
        'approve' => 'can_approve',
        'delete' => 'can_delete',
    ];

    $field = $map[$action] ?? '';
    if ($field === '') {
        return false;
    }

    $permissions = $_SESSION['permissions'] ?? [];
    foreach (['perm.chit-collection', 'perm.chit'] as $key) {
        if (isset($permissions[$key][$field])) {
            return (int)$permissions[$key][$field] === 1;
        }
    }

    $roleName = strtolower(trim((string)($_SESSION['role_name'] ?? '')));
    $roleCode = strtolower(trim((string)($_SESSION['role_code'] ?? '')));

    return in_array($roleName, ['super admin', 'admin', 'manager', 'billing'], true)
        || in_array($roleCode, ['super_admin', 'admin', 'manager', 'billing'], true);
}

if (!chitCollectionPermission($conn, 'open')) {
    http_response_code(403);
    die('Access denied. You do not have permission to open chit collection.');
}

$canCreate = chitCollectionPermission($conn, 'create');

foreach (['chit_members', 'chit_groups', 'chit_installments', 'chit_collections', 'customers', 'payment_methods'] as $requiredTable) {
    if (!tableExists($conn, $requiredTable)) {
        die("Required table `{$requiredTable}` was not found.");
    }
}

if (empty($_SESSION['chit_collection_csrf'])) {
    $_SESSION['chit_collection_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['chit_collection_csrf'];

$selectedMemberId = (int)($_GET['member_id'] ?? 0);
$selectedInstallmentId = (int)($_GET['installment_id'] ?? 0);

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
    'sidebar_width_px' => 230,
];

if (tableExists($conn, 'business_theme_settings')) {
    $stmt = $conn->prepare('SELECT * FROM business_theme_settings WHERE business_id = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('i', $businessId);
        $stmt->execute();
        $themeRow = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();

        foreach ($theme as $key => $default) {
            if (isset($themeRow[$key]) && $themeRow[$key] !== '') {
                $theme[$key] = $themeRow[$key];
            }
        }
    }
}

$businessName = (string)($_SESSION['business_name'] ?? 'Jewellery ERP');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo h($businessName); ?> - Chit Collection</title>
<?php include('includes/links.php'); ?>
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
    --sidebar-width:<?php echo (int)$theme['sidebar_width_px']; ?>px;
    --radius:<?php echo (int)$theme['border_radius_px']; ?>px;
}
body{background:var(--page-bg);color:var(--text-color);font-family:<?php echo json_encode((string)$theme['font_family']); ?>,sans-serif}
.sidebar{background:linear-gradient(180deg,<?php echo h($theme['sidebar_gradient_1']); ?>,<?php echo h($theme['sidebar_gradient_2']); ?>,<?php echo h($theme['sidebar_gradient_3']); ?>)!important}
.page-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:10px}
.page-title{font-family:<?php echo json_encode((string)$theme['heading_font_family']); ?>,serif;font-size:18px;font-weight:800;margin:0}
.page-subtitle{font-size:10px;color:var(--muted-color);margin-top:2px}
.panel{background:var(--card-bg);border:1px solid var(--border-color);border-radius:var(--radius);overflow:hidden;margin-bottom:10px}
.panel-head{padding:11px 13px;border-bottom:1px solid var(--border-color)}
.panel-title{font-size:12px;font-weight:800}.panel-subtitle{font-size:9px;color:var(--muted-color);margin-top:2px}.panel-body{padding:14px}
.form-grid{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:10px}.span-2{grid-column:span 2}.span-3{grid-column:span 3}.span-4{grid-column:span 4}.span-6{grid-column:span 6}.span-12{grid-column:span 12}
.field-label{display:block;font-size:9px;font-weight:700;margin-bottom:4px;color:var(--muted-color);text-transform:uppercase}
.form-control,.form-select{font-size:10px;min-height:36px;border-radius:9px;border-color:var(--border-color);background:var(--card-bg);color:var(--text-color)}
textarea.form-control{min-height:85px}
.btn-theme{background:linear-gradient(135deg,var(--primary),var(--primary-dark));border:0;color:#fff;border-radius:9px;font-size:10px;font-weight:700;min-height:36px;padding:8px 13px}
.btn-light-custom{border:1px solid var(--border-color);background:var(--card-bg);color:var(--text-color);border-radius:9px;font-size:10px;font-weight:700;min-height:36px;padding:8px 12px}
.theme-toast{position:fixed;right:18px;top:78px;z-index:20000;display:flex;align-items:center;gap:9px;min-width:260px;max-width:420px;padding:11px 14px;border-radius:10px;color:#fff;font-size:11px;font-weight:600;box-shadow:0 14px 35px rgba(0,0,0,.22);opacity:0;transform:translateY(-10px);transition:.22s}.theme-toast.show{opacity:1;transform:translateY(0)}.theme-toast-success{background:#168449}.theme-toast-error{background:#c0392b}
body.dark-mode,body[data-theme="dark"],html.dark-mode body,html[data-theme="dark"] body{--page-bg:#0f151b;--card-bg:#182129;--text-color:#f3f6f8;--muted-color:#9aa7b3;--border-color:#2c3944}
@media(max-width:991.98px){.form-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.span-2,.span-3,.span-4,.span-6,.span-12{grid-column:span 1}.span-full{grid-column:1/-1}}
@media(max-width:767.98px){.content-wrap{padding-left:10px;padding-right:10px}.form-grid{grid-template-columns:1fr}.span-2,.span-3,.span-4,.span-6,.span-12,.span-full{grid-column:1}.page-heading{align-items:flex-start;flex-direction:column}}
</style>
</head>
<body>
<?php include('includes/sidebar.php'); ?>
<main class="app-main">
<?php include('includes/nav.php'); ?>
<div class="content-wrap">
    <div class="page-heading">
        <div>
            <h1 class="page-title">Chit Collection</h1>
            <div class="page-subtitle"><?php echo h($businessName); ?> · Collect member installment payments</div>
        </div>
        <a href="chit-members.php" class="btn btn-light-custom">Back to Members</a>
    </div>

    <form id="collectionForm" class="panel">
        <div class="panel-head">
            <div class="panel-title">Collection Details</div>
            <div class="panel-subtitle">Choose a member and one of that member's open installments.</div>
        </div>

        <div class="panel-body">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">

            <div class="form-grid">
                <div class="span-4">
                    <label class="field-label">Member</label>
                    <select name="chit_member_id" id="chit_member_id" class="form-select" required>
                        <option value="">Select Member</option>
                    </select>
                </div>

                <div class="span-4">
                    <label class="field-label">Installment</label>
                    <select name="chit_installment_id" id="chit_installment_id" class="form-select" required disabled>
                        <option value="">Select Member First</option>
                    </select>
                </div>

                <div class="span-2">
                    <label class="field-label">Collection Date</label>
                    <input type="date" name="collection_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                </div>

                <div class="span-2">
                    <label class="field-label">Payment Method</label>
                    <select name="payment_method_id" id="payment_method_id" class="form-select" required>
                        <option value="">Select Method</option>
                    </select>
                </div>

                <div class="span-2">
                    <label class="field-label">Due Amount</label>
                    <input type="number" step="0.01" min="0" name="due_amount" id="due_amount" class="form-control" value="0.00" readonly>
                </div>

                <div class="span-2">
                    <label class="field-label">Paid Amount</label>
                    <input type="number" step="0.01" min="0.01" name="paid_amount" id="paid_amount" class="form-control" required>
                </div>

                <div class="span-2">
                    <label class="field-label">Discount</label>
                    <input type="number" step="0.01" min="0" name="discount_amount" id="discount_amount" class="form-control" value="0.00">
                </div>

                <div class="span-2">
                    <label class="field-label">Penalty</label>
                    <input type="number" step="0.01" min="0" name="penalty_amount" id="penalty_amount" class="form-control" value="0.00">
                </div>

                <div class="span-4">
                    <label class="field-label">Reference Number</label>
                    <input type="text" name="reference_no" class="form-control" maxlength="100">
                </div>

                <div class="span-12 span-full">
                    <label class="field-label">Remarks</label>
                    <textarea name="remarks" class="form-control" maxlength="1000"></textarea>
                </div>
            </div>

            <div class="d-flex flex-wrap gap-2 mt-3">
                <?php if ($canCreate): ?>
                    <button type="submit" class="btn btn-theme" id="saveCollectionButton">
                        <i class="fa-solid fa-floppy-disk me-1"></i>Save Collection
                    </button>
                <?php endif; ?>
                <button type="reset" class="btn btn-light-custom" id="resetCollectionButton">Reset</button>
            </div>
        </div>
    </form>

    <?php include('includes/footer.php'); ?>
</div>
</main>
<?php include('includes/script.php'); ?>
<script src="assets/js/script.js"></script>
<script>
(function(){
    'use strict';

    const preselectedMember=<?php echo (int)$selectedMemberId; ?>;
    const preselectedInstallment=<?php echo (int)$selectedInstallmentId; ?>;

    const memberSelect=document.getElementById('chit_member_id');
    const installmentSelect=document.getElementById('chit_installment_id');
    const paymentMethodSelect=document.getElementById('payment_method_id');
    const dueInput=document.getElementById('due_amount');
    const paidInput=document.getElementById('paid_amount');
    const discountInput=document.getElementById('discount_amount');
    const penaltyInput=document.getElementById('penalty_amount');

    let members=[];
    let installments=[];

    function toast(type,message){
        const element=document.createElement('div');
        element.className='theme-toast theme-toast-'+type;
        element.innerHTML='<i class="fa-solid '+(type==='success'?'fa-circle-check':'fa-circle-exclamation')+'"></i><span></span>';
        element.querySelector('span').textContent=message;
        document.body.appendChild(element);
        requestAnimationFrame(()=>element.classList.add('show'));
        setTimeout(()=>{element.classList.remove('show');setTimeout(()=>element.remove(),250)},3200);
    }

    function escapeHtml(value){
        const div=document.createElement('div');
        div.textContent=value??'';
        return div.innerHTML;
    }

    function money(value){
        return Number(value||0).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2});
    }

    function populateMembers(){
        memberSelect.innerHTML='<option value="">Select Member</option>'+members.map(row=>
            `<option value="${Number(row.id)}">${escapeHtml(row.ticket_no+' - '+row.customer_name+' - '+row.group_name)}</option>`
        ).join('');

        if(preselectedMember>0){
            memberSelect.value=String(preselectedMember);
            memberSelect.dispatchEvent(new Event('change'));
        }
    }

    function populatePaymentMethods(methods){
        const normalized=(Array.isArray(methods)?methods:[]).map(row=>({
            id:Number(
                row.id ??
                row.payment_method_id ??
                row.method_id ??
                0
            ),
            name:String(
                row.method_name ??
                row.payment_method_name ??
                row.name ??
                ''
            ).trim()
        })).filter(row=>row.id>0 && row.name!=='');

        paymentMethodSelect.innerHTML=
            '<option value="">Select Method</option>'+
            normalized.map(row=>
                `<option value="${row.id}">${escapeHtml(row.name)}</option>`
            ).join('');

        paymentMethodSelect.disabled=false;

        if(!normalized.length){
            paymentMethodSelect.innerHTML=
                '<option value="">No active payment methods found</option>';
        }
    }

    async function requestJson(url, options={}){
        const response=await fetch(url,{
            credentials:'same-origin',
            headers:{
                'Accept':'application/json',
                'X-Requested-With':'XMLHttpRequest',
                ...(options.headers||{})
            },
            ...options
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

    async function loadInitialData(){
        memberSelect.disabled=true;
        memberSelect.innerHTML='<option value="">Loading members...</option>';
        paymentMethodSelect.disabled=true;
        paymentMethodSelect.innerHTML='<option value="">Loading methods...</option>';

        try{
            const result=await requestJson('api/chit-collection.php?action=bootstrap');

            members=Array.isArray(result.members)?result.members:[];
            populateMembers();

            const paymentMethods=
                Array.isArray(result.payment_methods) ? result.payment_methods :
                Array.isArray(result.methods) ? result.methods :
                Array.isArray(result.rows) ? result.rows : [];

            populatePaymentMethods(paymentMethods);

            memberSelect.disabled=false;
            paymentMethodSelect.disabled=false;

            if(!members.length){
                memberSelect.innerHTML='<option value="">No active chit members found</option>';
                toast('error','No active chit members were found for the selected business and branch.');
            }
        }catch(error){
            memberSelect.innerHTML='<option value="">Unable to load members</option>';
            paymentMethodSelect.innerHTML='<option value="">Unable to load methods</option>';
            toast('error',error.message);
        }
    }

    async function loadInstallments(memberId){
        installmentSelect.disabled=true;
        installmentSelect.innerHTML='<option value="">Loading...</option>';
        dueInput.value='0.00';

        const member=members.find(row=>Number(row.id)===Number(memberId));

        if(!memberId){
            installmentSelect.innerHTML='<option value="">Select Member First</option>';
            return;
        }

        try{
            const result=await requestJson(
                'api/chit-collection.php?action=installments&member_id='+encodeURIComponent(memberId)
            );

            installments=result.installments||[];
            installmentSelect.innerHTML='<option value="">Select Installment</option>'+installments.map(row=>
                `<option value="${Number(row.id)}">${escapeHtml('#'+row.installment_no+' - '+row.due_date_display+' - Pending ₹'+money(row.pending_amount))}</option>`
            ).join('');
            installmentSelect.disabled=false;

            if(preselectedInstallment>0){
                installmentSelect.value=String(preselectedInstallment);
                installmentSelect.dispatchEvent(new Event('change'));
            }
        }catch(error){
            installmentSelect.innerHTML='<option value="">Unable to load</option>';
            toast('error',error.message);
        }
    }

    memberSelect.addEventListener('change',()=>loadInstallments(memberSelect.value));

    installmentSelect.addEventListener('change',function(){
        const row=installments.find(item=>Number(item.id)===Number(this.value));
        dueInput.value=row?Number(row.pending_amount).toFixed(2):'0.00';
        if(row&&!paidInput.value)paidInput.value=Number(row.pending_amount).toFixed(2);
    });

    const form=document.getElementById('collectionForm');
    form.addEventListener('submit',async function(event){
        event.preventDefault();

        const button=document.getElementById('saveCollectionButton');
        if(!button)return;

        const old=button.innerHTML;
        button.disabled=true;
        button.innerHTML='<i class="fa-solid fa-spinner fa-spin me-1"></i>Saving...';

        try{
            const result=await requestJson('api/chit-collection.php',{
                method:'POST',
                body:new FormData(form)
            });

            toast('success',result.message);
            setTimeout(()=>location.href='chit-ledger.php?member_id='+encodeURIComponent(result.member_id),700);
        }catch(error){
            toast('error',error.message);
        }finally{
            button.disabled=false;
            button.innerHTML=old;
        }
    });

    document.getElementById('resetCollectionButton').addEventListener('click',function(){
        setTimeout(()=>{
            installments=[];
            installmentSelect.disabled=true;
            installmentSelect.innerHTML='<option value="">Select Member First</option>';
                },0);
    });

    loadInitialData();
})();
</script>
</body>
</html>
