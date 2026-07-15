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
        $table = $conn->real_escape_string($table);
        $result = $conn->query("SHOW TABLES LIKE '{$table}'");
        return $result && $result->num_rows > 0;
    }
}

if (!function_exists('chitClosePermission')) {
    function chitClosePermission(mysqli $conn, string $action): bool
    {
        if (($_SESSION['user_type'] ?? '') === 'Platform Admin') {
            return true;
        }

        $fieldMap = [
            'open' => 'can_open',
            'view' => 'can_view',
            'update' => 'can_update',
            'approve' => 'can_approve',
        ];

        $field = $fieldMap[$action] ?? '';
        if ($field === '') {
            return false;
        }

        $permissions = $_SESSION['permissions'] ?? [];

        foreach (['perm.chit-close', 'perm.chit.groups', 'perm.chit'] as $permissionCode) {
            if (isset($permissions[$permissionCode][$field])) {
                return (int)$permissions[$permissionCode][$field] === 1;
            }
        }

        $roleName = strtolower(trim((string)($_SESSION['role_name'] ?? '')));
        $roleCode = strtolower(trim((string)($_SESSION['role_code'] ?? '')));

        return in_array($roleName, ['super admin', 'admin', 'manager', 'billing'], true)
            || in_array($roleCode, ['super_admin', 'admin', 'manager', 'billing'], true);
    }
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? $_SESSION['default_branch_id'] ?? 0);

if ($userId <= 0) {
    header('Location: login.php');
    exit;
}

if ($businessId <= 0 || $branchId <= 0) {
    http_response_code(403);
    die('A valid business and branch must be selected before closing a chit.');
}

if (!chitClosePermission($conn, 'open')) {
    http_response_code(403);
    die('Access denied. You do not have permission to open Close Chit.');
}

$canView = chitClosePermission($conn, 'view') || chitClosePermission($conn, 'open');
$canClose = chitClosePermission($conn, 'update') || chitClosePermission($conn, 'approve');

foreach (['chit_groups', 'chit_members'] as $requiredTable) {
    if (!tableExists($conn, $requiredTable)) {
        die("Required table `{$requiredTable}` was not found.");
    }
}

if (empty($_SESSION['chit_close_csrf'])) {
    $_SESSION['chit_close_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['chit_close_csrf'];

$pageTitle = 'Close Chit';
$page_title = 'Close Chit';
$currentPage = 'chit-close';

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
    $stmt = $conn->prepare(
        'SELECT * FROM business_theme_settings WHERE business_id = ? LIMIT 1'
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

$businessName = (string)($_SESSION['business_name'] ?? 'Jewellery ERP');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo h($businessName); ?> - Close Chit</title>
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
body{
    background:var(--page-bg);
    color:var(--text-color);
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
.page-heading{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    margin-bottom:10px;
}
.page-title{
    font-family:<?php echo json_encode((string)$theme['heading_font_family']); ?>,serif;
    font-size:18px;
    font-weight:800;
    margin:0;
}
.page-subtitle{
    font-size:10px;
    color:var(--muted-color);
    margin-top:2px;
}
.panel{
    background:var(--card-bg);
    border:1px solid var(--border-color);
    border-radius:var(--radius);
    overflow:hidden;
    margin-bottom:10px;
}
.panel-head{
    padding:11px 13px;
    border-bottom:1px solid var(--border-color);
}
.panel-title{
    font-size:12px;
    font-weight:800;
}
.panel-subtitle{
    font-size:9px;
    color:var(--muted-color);
    margin-top:2px;
}
.panel-body{
    padding:14px;
}
.form-grid{
    display:grid;
    grid-template-columns:repeat(12,minmax(0,1fr));
    gap:10px;
}
.span-6{grid-column:span 6}
.span-12{grid-column:span 12}
.field-label{
    display:block;
    font-size:9px;
    font-weight:700;
    margin-bottom:4px;
    color:var(--muted-color);
    text-transform:uppercase;
}
.form-control,.form-select{
    font-size:10px;
    min-height:36px;
    border-radius:9px;
    border-color:var(--border-color);
    background:var(--card-bg);
    color:var(--text-color);
}
textarea.form-control{
    min-height:90px;
    resize:vertical;
}
.btn-danger-custom{
    background:linear-gradient(135deg,#e74c3c,#b92b1f);
    border:0;
    color:#fff;
    border-radius:9px;
    font-size:10px;
    font-weight:700;
    min-height:36px;
    padding:8px 13px;
}
.btn-light-custom{
    border:1px solid var(--border-color);
    background:var(--card-bg);
    color:var(--text-color);
    border-radius:9px;
    font-size:10px;
    font-weight:700;
    min-height:36px;
    padding:8px 12px;
}
.theme-toast{
    position:fixed;
    right:18px;
    top:78px;
    z-index:20000;
    display:flex;
    align-items:center;
    gap:9px;
    min-width:260px;
    max-width:420px;
    padding:11px 14px;
    border-radius:10px;
    color:#fff;
    font-size:11px;
    font-weight:600;
    box-shadow:0 14px 35px rgba(0,0,0,.22);
    opacity:0;
    transform:translateY(-10px);
    transition:.22s;
}
.theme-toast.show{opacity:1;transform:translateY(0)}
.theme-toast-success{background:#168449}
.theme-toast-error{background:#c0392b}
.empty-state{
    padding:42px 20px;
    text-align:center;
    color:var(--muted-color);
}
body.dark-mode,
body[data-theme="dark"],
html.dark-mode body,
html[data-theme="dark"] body{
    --page-bg:#0f151b;
    --card-bg:#182129;
    --text-color:#f3f6f8;
    --muted-color:#9aa7b3;
    --border-color:#2c3944;
}
@media(max-width:991.98px){
    .form-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
    .span-6,.span-12{grid-column:span 1}
    .span-full{grid-column:1/-1}
}
@media(max-width:767.98px){
    .content-wrap{padding-left:10px;padding-right:10px}
    .form-grid{grid-template-columns:1fr}
    .span-6,.span-12,.span-full{grid-column:1}
    .page-heading{align-items:flex-start;flex-direction:column}
}
</style>
</head>
<body>
<?php include 'includes/sidebar.php'; ?>

<main class="app-main">
<?php include 'includes/nav.php'; ?>

<div class="content-wrap">
    <div class="page-heading">
        <div>
            <h1 class="page-title">Close Chit</h1>
            <div class="page-subtitle">
                <?php echo h($businessName); ?> · Close an active chit group
            </div>
        </div>

        <a href="chit-groups.php" class="btn btn-light-custom">
            <i class="fa-solid fa-arrow-left me-1"></i>Back to Groups
        </a>
    </div>

    <?php if (!$canView): ?>
        <div class="panel">
            <div class="empty-state">
                You do not have permission to view active chit groups.
            </div>
        </div>
    <?php else: ?>
        <form id="closeChitForm" class="panel">
            <div class="panel-head">
                <div class="panel-title">Close Chit Details</div>
                <div class="panel-subtitle">
                    Select an active group and enter the closing remarks.
                </div>
            </div>

            <div class="panel-body">
                <input type="hidden" name="action" value="close">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?php echo h($csrfToken); ?>"
                >

                <div class="form-grid">
                    <div class="span-6 span-full">
                        <label class="field-label">Active Chit Group</label>
                        <select
                            name="group_id"
                            id="group_id"
                            class="form-select"
                            required
                        >
                            <option value="">Loading active groups...</option>
                        </select>
                    </div>

                    <div class="span-12 span-full">
                        <div class="panel" id="groupDetailsPanel" style="display:none;">
                            <div class="panel-head">
                                <div class="panel-title">Selected Chit Group Details</div>
                                <div class="panel-subtitle">Live database details</div>
                            </div>
                            <div class="panel-body" id="groupDetailsContent"></div>
                        </div>
                    </div>

                    <div class="span-12 span-full">
                        <label class="field-label">Close Remarks</label>
                        <textarea
                            name="remarks"
                            id="remarks"
                            class="form-control"
                            maxlength="1000"
                            placeholder="Enter the reason or closing note"
                        ></textarea>
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-2 mt-3">
                    <?php if ($canClose): ?>
                        <button
                            type="submit"
                            class="btn btn-danger-custom"
                            id="closeChitButton"
                        >
                            <i class="fa-solid fa-circle-xmark me-1"></i>
                            Close Chit
                        </button>
                    <?php endif; ?>

                    <button
                        type="reset"
                        class="btn btn-light-custom"
                        id="resetButton"
                    >
                        Reset
                    </button>
                </div>
            </div>
        </form>
    <?php endif; ?>

    <?php include 'includes/footer.php'; ?>
</div>
</main>

<?php include 'includes/script.php'; ?>
<script src="assets/js/script.js"></script>
<script>
(function(){
    'use strict';

    const form=document.getElementById('closeChitForm');
    const groupSelect=document.getElementById('group_id');
    const closeButton=document.getElementById('closeChitButton');
    const groupDetailsPanel=document.getElementById('groupDetailsPanel');
    const groupDetailsContent=document.getElementById('groupDetailsContent');

    if(!form||!groupSelect){
        return;
    }

    function toast(type,message){
        const element=document.createElement('div');
        element.className='theme-toast theme-toast-'+type;
        element.innerHTML=
            '<i class="fa-solid '+
            (type==='success'?'fa-circle-check':'fa-circle-exclamation')+
            '"></i><span></span>';

        element.querySelector('span').textContent=message;
        document.body.appendChild(element);

        requestAnimationFrame(()=>element.classList.add('show'));

        setTimeout(()=>{
            element.classList.remove('show');
            setTimeout(()=>element.remove(),250);
        },3500);
    }

    function escapeHtml(value){
        const div=document.createElement('div');
        div.textContent=value??'';
        return div.innerHTML;
    }

    async function requestJson(url,options={}){
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

    async function loadGroups(){
        groupSelect.disabled=true;
        groupSelect.innerHTML='<option value="">Loading active groups...</option>';

        try{
            const result=await requestJson('api/chit-close.php?action=groups');
            const groups=Array.isArray(result.groups)?result.groups:[];

            groupSelect.innerHTML=
                '<option value="">Select Active Chit Group</option>'+
                groups.map(group=>
                    `<option value="${Number(group.id)}">${escapeHtml(group.group_no+' - '+group.group_name)}</option>`
                ).join('');

            groupSelect.disabled=false;

            if(!groups.length){
                groupSelect.innerHTML='<option value="">No active chit groups found</option>';
                groupSelect.disabled=true;
            }
        }catch(error){
            groupSelect.innerHTML='<option value="">Unable to load groups</option>';
            toast('error',error.message);
        }
    }

    
async function loadGroupDetails(){
    if(!groupSelect.value){
        if(groupDetailsPanel) groupDetailsPanel.style.display='none';
        return;
    }

    try{
        const result=await requestJson(
            'api/chit-close.php?action=details&group_id='+groupSelect.value
        );

        const group=result.group || {};
        const members=result.members || [];

        groupDetailsContent.innerHTML =
        `<div class="row">
            <div class="col-md-3"><b>Group No</b><br>${escapeHtml(group.group_no||'')}</div>
            <div class="col-md-3"><b>Name</b><br>${escapeHtml(group.group_name||'')}</div>
            <div class="col-md-3"><b>Type</b><br>${escapeHtml(group.chit_type||'')}</div>
            <div class="col-md-3"><b>Status</b><br>${escapeHtml(group.status||'')}</div>
        </div>
        <hr>
        <b>Members (${members.length})</b>
        <table class="table mt-2">
        <thead><tr><th>Name</th><th>Mobile</th><th>Status</th></tr></thead>
        <tbody>${members.map(m=>`<tr><td>${escapeHtml(m.member_name||'')}</td><td>${escapeHtml(m.mobile||'')}</td><td>${escapeHtml(m.status||'')}</td></tr>`).join('')}</tbody>
        </table>`;

        groupDetailsPanel.style.display='block';

    }catch(error){
        toast('error',error.message);
    }
}

groupSelect.addEventListener('change',loadGroupDetails);

form.addEventListener('submit',async function(event){
        event.preventDefault();

        if(!groupSelect.value){
            toast('error','Please select an active chit group.');
            return;
        }

        const selectedText=
            groupSelect.options[groupSelect.selectedIndex]?.textContent||
            'this chit';

        if(!window.confirm('Close '+selectedText+'? This action will also close its members.')){
            return;
        }

        if(!closeButton){
            return;
        }

        const originalHtml=closeButton.innerHTML;
        closeButton.disabled=true;
        closeButton.innerHTML=
            '<i class="fa-solid fa-spinner fa-spin me-1"></i>Closing...';

        try{
            const result=await requestJson('api/chit-close.php',{
                method:'POST',
                body:new FormData(form)
            });

            toast('success',result.message);

            setTimeout(()=>{
                window.location.href='chit-groups.php?msg=closed';
            },700);
        }catch(error){
            toast('error',error.message);
        }finally{
            closeButton.disabled=false;
            closeButton.innerHTML=originalHtml;
        }
    });

    document.getElementById('resetButton')?.addEventListener('click',()=>{
        setTimeout(()=>{
            groupSelect.value='';
        },0);
    });

    loadGroups();
})();
</script>
</body>
</html>
