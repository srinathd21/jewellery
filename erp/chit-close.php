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

if (!$configLoaded) {
    die('Database config file not found. Checked: ' . implode(', ', $configCandidates));
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection is not available.');
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

if (!function_exists('hasChitClosePermission')) {
    function hasChitClosePermission(mysqli $conn, string $action): bool
    {
        $map = [
            'open' => 'can_open',
            'view' => 'can_view',
            'update' => 'can_update',
            'approve' => 'can_approve',
        ];

        $field = $map[$action] ?? '';

        if ($field === '') {
            return false;
        }

        $adminRoles = [
            'platform admin',
            'super admin',
            'admin',
            'manager',
            'billing',
            'super_admin',
        ];

        foreach ([
            strtolower(trim((string)($_SESSION['user_type'] ?? ''))),
            strtolower(trim((string)($_SESSION['role_name'] ?? ''))),
            strtolower(trim((string)($_SESSION['role_code'] ?? ''))),
        ] as $roleValue) {
            if (in_array($roleValue, $adminRoles, true)) {
                return true;
            }
        }

        $permissionCodes = [
            'perm.chit-close',
            'perm.chit',
        ];

        $sessionPermissions = $_SESSION['permissions'] ?? [];

        foreach ($permissionCodes as $permissionCode) {
            if (isset($sessionPermissions[$permissionCode][$field])) {
                return (int)$sessionPermissions[$permissionCode][$field] === 1;
            }
        }

        $businessId = (int)($_SESSION['business_id'] ?? 0);
        $roleId = (int)($_SESSION['role_id'] ?? 0);

        if (
            $businessId <= 0 ||
            $roleId <= 0 ||
            !tableExists($conn, 'permissions') ||
            !tableExists($conn, 'role_permissions')
        ) {
            return false;
        }

        $placeholders = implode(',', array_fill(0, count($permissionCodes), '?'));

        $sql = "SELECT MAX(COALESCE(rp.`{$field}`,0)) AS allowed
                FROM role_permissions rp
                INNER JOIN permissions p
                    ON p.id = rp.permission_id
                WHERE rp.business_id = ?
                  AND rp.role_id = ?
                  AND p.is_active = 1
                  AND p.permission_code IN ({$placeholders})";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            return false;
        }

        $types = 'ii' . str_repeat('s', count($permissionCodes));
        $params = array_merge([$businessId, $roleId], $permissionCodes);

        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int)($row['allowed'] ?? 0) === 1;
    }
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? $_SESSION['default_branch_id'] ?? 0);
$selectedGroupId = (int)($_GET['group_id'] ?? 0);

if ($userId <= 0) {
    header('Location: login.php');
    exit;
}

if ($businessId <= 0 || $branchId <= 0) {
    die('A valid business and branch must be selected.');
}

if (!hasChitClosePermission($conn, 'open')) {
    http_response_code(403);
    die('Access denied. You do not have permission to open chit closure.');
}

$canView = hasChitClosePermission($conn, 'view') || hasChitClosePermission($conn, 'open');
$canClose = hasChitClosePermission($conn, 'update') || hasChitClosePermission($conn, 'approve');

foreach (['chit_groups', 'chit_members', 'chit_installments'] as $requiredTable) {
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
];

if (tableExists($conn, 'business_theme_settings')) {
    $stmt = $conn->prepare("SELECT * FROM business_theme_settings WHERE business_id = ? LIMIT 1");

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
body{background:var(--page-bg);color:var(--text-color);font-family:<?php echo json_encode((string)$theme['font_family']); ?>,sans-serif}
.sidebar{background:linear-gradient(180deg,<?php echo h($theme['sidebar_gradient_1']); ?>,<?php echo h($theme['sidebar_gradient_2']); ?>,<?php echo h($theme['sidebar_gradient_3']); ?>)!important}
.page-heading{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:10px}
.page-title{font-family:<?php echo json_encode((string)$theme['heading_font_family']); ?>,serif;font-size:18px;font-weight:800;margin:0}
.page-subtitle{font-size:10px;color:var(--muted-color);margin-top:2px}
.panel,.info-card{background:var(--card-bg);border:1px solid var(--border-color);border-radius:var(--radius)}
.panel{overflow:hidden}.panel-head{padding:12px 14px;border-bottom:1px solid var(--border-color)}.panel-title{font-size:12px;font-weight:800}.panel-subtitle{font-size:9px;color:var(--muted-color);margin-top:2px}.panel-body{padding:14px}
.form-grid{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:10px}.span-6{grid-column:span 6}.span-12{grid-column:span 12}
.field-label{display:block;font-size:9px;font-weight:700;text-transform:uppercase;color:var(--muted-color);margin-bottom:4px}
.form-control,.form-select{min-height:36px;font-size:10px;border-radius:9px;border-color:var(--border-color);background:var(--card-bg);color:var(--text-color)}
textarea.form-control{min-height:100px}
.btn-danger-custom{background:linear-gradient(135deg,#d64545,#a92323);border:0;color:#fff;border-radius:9px;font-size:10px;font-weight:700;min-height:36px;padding:8px 13px}
.btn-light-custom{border:1px solid var(--border-color);background:var(--card-bg);color:var(--text-color);border-radius:9px;font-size:10px;font-weight:700;min-height:36px;padding:8px 12px}
.info-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin-bottom:10px}
.info-card{padding:12px 14px}.info-label{font-size:9px;color:var(--muted-color)}.info-value{font-size:16px;font-weight:800;margin-top:3px}
.warning-box{border:1px solid #f0c9c9;background:#fff4f4;color:#9e2b2b;border-radius:10px;padding:12px 14px;font-size:10px;margin-bottom:12px}
.theme-toast{position:fixed;top:78px;right:18px;z-index:20000;display:flex;align-items:center;gap:9px;min-width:260px;max-width:420px;padding:11px 14px;border-radius:10px;color:#fff;font-size:11px;font-weight:600;box-shadow:0 14px 35px rgba(0,0,0,.22);opacity:0;transform:translateY(-10px);transition:.22s}.theme-toast.show{opacity:1;transform:translateY(0)}.theme-toast-success{background:#168449}.theme-toast-error{background:#c0392b}
body.dark-mode,body[data-theme="dark"],html.dark-mode body,html[data-theme="dark"] body{--page-bg:#0f151b;--card-bg:#182129;--text-color:#f3f6f8;--muted-color:#9aa7b3;--border-color:#2c3944}
@media(max-width:991px){.info-grid{grid-template-columns:repeat(2,1fr)}.form-grid{grid-template-columns:1fr}.span-6,.span-12{grid-column:1}}
@media(max-width:767px){.info-grid{grid-template-columns:1fr}.page-heading{align-items:flex-start;flex-direction:column}}
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
            <div class="page-subtitle"><?php echo h($businessName); ?> · Complete an active chit group</div>
        </div>
        <a href="chit-groups.php" class="btn btn-light-custom">Back to Groups</a>
    </div>

    <?php if (!$canView): ?>
        <div class="panel"><div class="panel-body">You do not have permission to view active chit groups.</div></div>
    <?php else: ?>
        <div class="info-grid d-none" id="groupInfo">
            <div class="info-card"><div class="info-label">Group</div><div class="info-value" id="infoGroup">—</div></div>
            <div class="info-card"><div class="info-label">Active Members</div><div class="info-value" id="infoMembers">0</div></div>
            <div class="info-card"><div class="info-label">Open Installments</div><div class="info-value" id="infoInstallments">0</div></div>
            <div class="info-card"><div class="info-label">Status</div><div class="info-value">Active</div></div>
        </div>

        <form id="closeForm" class="panel">
            <div class="panel-head">
                <div class="panel-title">Chit Closure</div>
                <div class="panel-subtitle">Select an active chit group and enter the closure remarks.</div>
            </div>

            <div class="panel-body">
                <div class="warning-box">
                    <i class="fa-solid fa-triangle-exclamation me-1"></i>
                    Closing a chit changes the group status to <strong>Closed</strong>, active members to <strong>Completed</strong>, and open installments to <strong>Closed</strong>.
                </div>

                <input type="hidden" name="action" value="close">
                <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">

                <div class="form-grid">
                    <div class="span-6">
                        <label class="field-label">Active Chit Group</label>
                        <select name="chit_group_id" id="chit_group_id" class="form-select" required>
                            <option value="">Select Group</option>
                        </select>
                    </div>

                    <div class="span-12">
                        <label class="field-label">Closure Remarks</label>
                        <textarea name="remarks" class="form-control" maxlength="2000" placeholder="Enter the reason or final closure note"></textarea>
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-2 mt-3">
                    <?php if ($canClose): ?>
                        <button type="submit" class="btn btn-danger-custom" id="closeButton">
                            <i class="fa-solid fa-lock me-1"></i>Close Chit
                        </button>
                    <?php endif; ?>
                    <a href="chit-groups.php" class="btn btn-light-custom">Cancel</a>
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

    const preselectedGroup=<?php echo $selectedGroupId; ?>;
    const groupSelect=document.getElementById('chit_group_id');
    let groups=[];

    function toast(type,message){
        const element=document.createElement('div');
        element.className='theme-toast theme-toast-'+type;
        element.innerHTML='<i class="fa-solid '+(type==='success'?'fa-circle-check':'fa-circle-exclamation')+'"></i><span></span>';
        element.querySelector('span').textContent=message;
        document.body.appendChild(element);
        requestAnimationFrame(()=>element.classList.add('show'));
        setTimeout(()=>{element.classList.remove('show');setTimeout(()=>element.remove(),250)},3500);
    }

    function escapeHtml(value){
        const div=document.createElement('div');
        div.textContent=value??'';
        return div.innerHTML;
    }

    function showGroupInfo(group){
        const info=document.getElementById('groupInfo');

        if(!group){
            info.classList.add('d-none');
            return;
        }

        info.classList.remove('d-none');
        document.getElementById('infoGroup').textContent=group.group_no+' - '+group.group_name;
        document.getElementById('infoMembers').textContent=group.active_members;
        document.getElementById('infoInstallments').textContent=group.open_installments;
    }

    async function loadGroups(){
        try{
            const response=await fetch('api/chit-close.php?action=bootstrap',{credentials:'same-origin'});
            const result=await response.json().catch(()=>({success:false,message:'Invalid JSON response from API.'}));

            if(!response.ok||!result.success){
                throw new Error(result.message||'Unable to load active chit groups.');
            }

            groups=result.groups||[];
            groupSelect.innerHTML='<option value="">Select Group</option>'+groups.map(group=>
                `<option value="${Number(group.id)}">${escapeHtml(group.group_no+' - '+group.group_name)}</option>`
            ).join('');

            if(preselectedGroup>0){
                groupSelect.value=String(preselectedGroup);
                groupSelect.dispatchEvent(new Event('change'));
            }
        }catch(error){
            toast('error',error.message);
        }
    }

    groupSelect.addEventListener('change',function(){
        const group=groups.find(row=>Number(row.id)===Number(this.value));
        showGroupInfo(group||null);
    });

    document.getElementById('closeForm').addEventListener('submit',async function(event){
        event.preventDefault();

        const selectedGroup=groups.find(row=>Number(row.id)===Number(groupSelect.value));
        const message=selectedGroup
            ? `Close "${selectedGroup.group_name}"? This action completes its active members and closes open installments.`
            : 'Close this chit group?';

        if(!window.confirm(message)){
            return;
        }

        const button=document.getElementById('closeButton');
        if(!button)return;

        const original=button.innerHTML;
        button.disabled=true;
        button.innerHTML='<i class="fa-solid fa-spinner fa-spin me-1"></i>Closing...';

        try{
            const response=await fetch('api/chit-close.php',{
                method:'POST',
                body:new FormData(this),
                credentials:'same-origin',
                headers:{'X-Requested-With':'XMLHttpRequest'}
            });

            const result=await response.json().catch(()=>({success:false,message:'Invalid JSON response from API.'}));

            if(!response.ok||!result.success){
                throw new Error(result.message||'Unable to close chit.');
            }

            toast('success',result.message);
            setTimeout(()=>location.href='chit-groups.php?msg=closed',700);
        }catch(error){
            toast('error',error.message);
        }finally{
            button.disabled=false;
            button.innerHTML=original;
        }
    });

    loadGroups();
})();
</script>
</body>
</html>
