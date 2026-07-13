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

if (!function_exists('hasChitMemberPermission')) {
    function hasChitMemberPermission(mysqli $conn, string $action): bool
    {
        $fieldMap = [
            'open' => 'can_open',
            'view' => 'can_view',
            'create' => 'can_create',
            'update' => 'can_update',
            'delete' => 'can_delete',
        ];

        $field = $fieldMap[$action] ?? '';

        if ($field === '') {
            return false;
        }

        $userType = strtolower(trim((string)($_SESSION['user_type'] ?? '')));
        $roleName = strtolower(trim((string)($_SESSION['role_name'] ?? '')));
        $roleCode = strtolower(trim((string)($_SESSION['role_code'] ?? '')));

        $adminRoles = [
            'platform admin',
            'super admin',
            'admin',
            'manager',
            'billing',
            'super_admin',
        ];

        if (
            in_array($userType, $adminRoles, true) ||
            in_array($roleName, $adminRoles, true) ||
            in_array($roleCode, $adminRoles, true)
        ) {
            return true;
        }

        $permissionCodes = [
            'perm.chit-members',
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

        if ($businessId <= 0 || $roleId <= 0) {
            return false;
        }

        if (!tableExists($conn, 'permissions') || !tableExists($conn, 'role_permissions')) {
            return false;
        }

        $placeholders = implode(',', array_fill(0, count($permissionCodes), '?'));

        $sql = "SELECT MAX(COALESCE(rp.`{$field}`, 0)) AS allowed
                FROM role_permissions rp
                INNER JOIN permissions p ON p.id = rp.permission_id
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

if ($userId <= 0) {
    header('Location: login.php');
    exit;
}

if ($businessId <= 0) {
    die('A valid business must be selected.');
}

if ($branchId <= 0) {
    die('A valid branch must be selected.');
}

if (!hasChitMemberPermission($conn, 'open')) {
    http_response_code(403);
    die('Access denied. You do not have permission to open chit members.');
}

$canCreate = hasChitMemberPermission($conn, 'create');

foreach (['chit_groups', 'chit_members', 'customers'] as $requiredTable) {
    if (!tableExists($conn, $requiredTable)) {
        die("Required table `{$requiredTable}` was not found.");
    }
}

if (empty($_SESSION['chit_member_csrf'])) {
    $_SESSION['chit_member_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['chit_member_csrf'];
$selectedGroupId = (int)($_GET['group_id'] ?? 0);
$selectedCustomerId = (int)($_GET['customer_id'] ?? 0);

$pageTitle = 'Add Chit Member';
$page_title = 'Add Chit Member';
$currentPage = 'chit-members';

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
<title><?php echo h($businessName); ?> - Add Chit Member</title>
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
.panel{background:var(--card-bg);border:1px solid var(--border-color);border-radius:var(--radius);overflow:hidden}
.panel-head{padding:12px 14px;border-bottom:1px solid var(--border-color)}
.panel-title{font-size:12px;font-weight:800}.panel-subtitle{font-size:9px;color:var(--muted-color);margin-top:2px}.panel-body{padding:14px}
.form-grid{display:grid;grid-template-columns:repeat(12,1fr);gap:10px}
.span-3{grid-column:span 3}.span-4{grid-column:span 4}.span-6{grid-column:span 6}.span-12{grid-column:span 12}
.field-label{display:block;font-size:9px;font-weight:700;text-transform:uppercase;color:var(--muted-color);margin-bottom:4px}
.form-control,.form-select{min-height:36px;font-size:10px;border-radius:9px;border-color:var(--border-color);background:var(--card-bg);color:var(--text-color)}
.btn-theme{background:linear-gradient(135deg,var(--primary),var(--primary-dark));border:0;color:#fff;border-radius:9px;font-size:10px;font-weight:700;min-height:36px;padding:8px 13px}
.btn-light-custom{border:1px solid var(--border-color);background:var(--card-bg);color:var(--text-color);border-radius:9px;font-size:10px;font-weight:700;min-height:36px;padding:8px 12px}
.info-strip{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:10px}
.info-card{background:var(--card-bg);border:1px solid var(--border-color);border-radius:var(--radius);padding:11px 13px}
.info-label{font-size:9px;color:var(--muted-color)}.info-value{font-size:13px;font-weight:800;margin-top:3px}
.theme-toast{position:fixed;top:78px;right:18px;z-index:20000;display:flex;align-items:center;gap:9px;min-width:260px;max-width:420px;padding:11px 14px;border-radius:10px;color:#fff;font-size:11px;font-weight:600;box-shadow:0 14px 35px rgba(0,0,0,.22);opacity:0;transform:translateY(-10px);transition:.22s}.theme-toast.show{opacity:1;transform:translateY(0)}.theme-toast-success{background:#168449}.theme-toast-error{background:#c0392b}
body.dark-mode,body[data-theme="dark"],html.dark-mode body,html[data-theme="dark"] body{--page-bg:#0f151b;--card-bg:#182129;--text-color:#f3f6f8;--muted-color:#9aa7b3;--border-color:#2c3944}
@media(max-width:991px){.form-grid{grid-template-columns:repeat(2,1fr)}.span-3,.span-4,.span-6,.span-12{grid-column:span 1}.span-full{grid-column:1/-1}.info-strip{grid-template-columns:1fr}}
@media(max-width:767px){.form-grid{grid-template-columns:1fr}.span-3,.span-4,.span-6,.span-12,.span-full{grid-column:1}.page-heading{align-items:flex-start;flex-direction:column}}
</style>
</head>
<body>
<?php include 'includes/sidebar.php'; ?>
<main class="app-main">
<?php include 'includes/nav.php'; ?>

<div class="content-wrap">
    <div class="page-heading">
        <div>
            <h1 class="page-title">Add Chit Member</h1>
            <div class="page-subtitle"><?php echo h($businessName); ?> · Add an existing customer to an active chit group</div>
        </div>
        <a href="chit-members.php" class="btn btn-light-custom">Back to Members</a>
    </div>

    <div class="info-strip">
        <div class="info-card"><div class="info-label">Customer Source</div><div class="info-value">Customers Table</div></div>
        <div class="info-card"><div class="info-label">Member Number</div><div class="info-value">Ticket Number</div></div>
        <div class="info-card"><div class="info-label">Status</div><div class="info-value">Active</div></div>
    </div>

    <form id="memberForm" class="panel">
        <div class="panel-head">
            <div class="panel-title">Member Details</div>
            <div class="panel-subtitle">The customer and group are validated again before saving.</div>
        </div>

        <div class="panel-body">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">

            <div class="form-grid">
                <div class="span-4">
                    <label class="field-label">Chit Group</label>
                    <select name="chit_group_id" id="chit_group_id" class="form-select" required>
                        <option value="">Select Group</option>
                    </select>
                </div>

                <div class="span-4">
                    <label class="field-label">Customer</label>
                    <select name="customer_id" id="customer_id" class="form-select" required>
                        <option value="">Select Customer</option>
                    </select>
                </div>

                <div class="span-4">
                    <label class="field-label">Join Date</label>
                    <input type="date" name="join_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                </div>

                <div class="span-4">
                    <label class="field-label">Ticket Number</label>
                    <input type="text" name="ticket_no" class="form-control" maxlength="50" placeholder="Auto-generated if empty">
                </div>

                <div class="span-4">
                    <label class="field-label">Nominee Name</label>
                    <input type="text" name="nominee_name" class="form-control" maxlength="150">
                </div>

                <div class="span-4">
                    <label class="field-label">Nominee Relation</label>
                    <input type="text" name="nominee_relation" class="form-control" maxlength="100">
                </div>
            </div>

            <div class="d-flex gap-2 mt-3">
                <?php if ($canCreate): ?>
                    <button type="submit" class="btn btn-theme" id="saveMemberButton">
                        <i class="fa-solid fa-floppy-disk me-1"></i>Save Member
                    </button>
                <?php endif; ?>
                <a href="chit-members.php" class="btn btn-light-custom">Cancel</a>
            </div>
        </div>
    </form>

    <?php include 'includes/footer.php'; ?>
</div>
</main>

<?php include 'includes/script.php'; ?>
<script src="assets/js/script.js"></script>
<script>
(function(){
    'use strict';

    const selectedGroupId=<?php echo $selectedGroupId; ?>;
    const selectedCustomerId=<?php echo $selectedCustomerId; ?>;
    const groupSelect=document.getElementById('chit_group_id');
    const customerSelect=document.getElementById('customer_id');

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

    async function loadOptions(){
        try{
            const response=await fetch('api/chit-member-add.php?action=bootstrap',{credentials:'same-origin'});
            const result=await response.json().catch(()=>({
                success:false,
                message:'Invalid JSON response from API.'
            }));

            if(!response.ok||!result.success){
                throw new Error(result.message||'Unable to load groups and customers.');
            }

            groupSelect.innerHTML='<option value="">Select Group</option>'+result.groups.map(row=>
                `<option value="${Number(row.id)}">${escapeHtml(row.group_no+' - '+row.group_name)}</option>`
            ).join('');

            customerSelect.innerHTML='<option value="">Select Customer</option>'+result.customers.map(row=>
                `<option value="${Number(row.id)}">${escapeHtml(row.customer_code+' - '+row.customer_name+(row.mobile?' - '+row.mobile:''))}</option>`
            ).join('');

            if(selectedGroupId>0){
                groupSelect.value=String(selectedGroupId);
            }

            if(selectedCustomerId>0){
                customerSelect.value=String(selectedCustomerId);
            }
        }catch(error){
            toast('error',error.message);
        }
    }

    document.getElementById('memberForm').addEventListener('submit',async function(event){
        event.preventDefault();

        const button=document.getElementById('saveMemberButton');
        if(!button)return;

        const original=button.innerHTML;
        button.disabled=true;
        button.innerHTML='<i class="fa-solid fa-spinner fa-spin me-1"></i>Saving...';

        try{
            const response=await fetch('api/chit-member-add.php',{
                method:'POST',
                body:new FormData(this),
                credentials:'same-origin',
                headers:{'X-Requested-With':'XMLHttpRequest'}
            });

            const result=await response.json().catch(()=>({
                success:false,
                message:'Invalid JSON response from API.'
            }));

            if(!response.ok||!result.success){
                throw new Error(result.message||'Unable to save chit member.');
            }

            toast('success',result.message);
            setTimeout(()=>location.href='chit-members.php?msg=created',700);
        }catch(error){
            toast('error',error.message);
        }finally{
            button.disabled=false;
            button.innerHTML=original;
        }
    });

    loadOptions();
})();
</script>
</body>
</html>
