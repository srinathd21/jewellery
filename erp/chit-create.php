<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Kolkata');
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
    die(
        'Database config file not found. Checked: ' .
        implode(', ', $configCandidates)
    );
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not available. Check includes/config.php');
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn->set_charset('utf8mb4');

$numberHelperCandidates = [
    __DIR__ . '/includes/document-number-helper.php',
    __DIR__ . '/document-number-helper.php',
];
foreach ($numberHelperCandidates as $numberHelperFile) {
    if (is_file($numberHelperFile)) {
        require_once $numberHelperFile;
        break;
    }
}

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

if (!function_exists('nextNo')) {
    function nextNo(mysqli $conn, string $table, string $column, string $prefix, int $businessId): string
    {
        $like = $prefix . '%';

        $sql = "SELECT `{$column}`
                FROM `{$table}`
                WHERE business_id = ?
                  AND `{$column}` LIKE ?
                ORDER BY id DESC
                LIMIT 1";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            return $prefix . '0001';
        }

        $stmt->bind_param('is', $businessId, $like);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $next = 1;

        if ($row && preg_match('/(\d+)$/', (string)($row[$column] ?? ''), $match)) {
            $next = (int)$match[1] + 1;
        }

        return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('hasChitCreatePermission')) {
    function hasChitCreatePermission(mysqli $conn, string $action): bool
    {
        $fieldMap = [
            'open'   => 'can_open',
            'view'   => 'can_view',
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
            'perm.chit.create',
            'perm.chit.groups',
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

        $sql = "SELECT
                    MAX(COALESCE(rp.`{$field}`, 0)) AS allowed
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

if (!hasChitCreatePermission($conn, 'open')) {
    http_response_code(403);
    die('Access denied. You do not have permission to open chit creation.');
}

$canCreate = hasChitCreatePermission($conn, 'create');

if (!$canCreate) {
    http_response_code(403);
    die('Access denied. You do not have permission to create chit groups.');
}

foreach (['chit_groups', 'chit_installments'] as $requiredTable) {
    if (!tableExists($conn, $requiredTable)) {
        die("Required table `{$requiredTable}` was not found.");
    }
}

if (empty($_SESSION['chit_create_csrf'])) {
    $_SESSION['chit_create_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['chit_create_csrf'];
$today = date('Y-m-d');
try {
    $groupNo = function_exists('generateDocumentNumber') && tableExists($conn, 'document_number_settings')
        ? generateDocumentNumber($conn, $businessId, $branchId, 'chit', $today)
        : nextNo($conn, 'chit_groups', 'group_no', 'CH' . date('Ym'), $businessId);
} catch (Throwable $numberError) {
    $groupNo = nextNo($conn, 'chit_groups', 'group_no', 'CH' . date('Ym'), $businessId);
}

$pageTitle = 'Create Chit';
$page_title = 'Create Chit';
$currentPage = 'chit-create';

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
<title><?php echo h($businessName); ?> - Create Chit</title>
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
.panel{background:var(--card-bg);border:1px solid var(--border-color);border-radius:var(--radius)}
.panel{overflow:hidden}.panel-head{padding:12px 14px;border-bottom:1px solid var(--border-color)}.panel-title{font-size:12px;font-weight:800}.panel-subtitle{font-size:9px;color:var(--muted-color);margin-top:2px}.panel-body{padding:14px}
.form-grid{display:grid;grid-template-columns:repeat(12,1fr);gap:10px}
.span-2{grid-column:span 2}.span-3{grid-column:span 3}.span-5{grid-column:span 5}.span-12{grid-column:span 12}
.field-label{display:block;font-size:9px;font-weight:700;text-transform:uppercase;color:var(--muted-color);margin-bottom:4px}
.field-help{font-size:8px;color:var(--muted-color);margin-top:4px}
.form-control,.form-select{min-height:36px;font-size:10px;border-radius:9px;border-color:var(--border-color);background:var(--card-bg);color:var(--text-color)}
textarea.form-control{min-height:85px}
.btn-theme{background:linear-gradient(135deg,var(--primary),var(--primary-dark));border:0;color:#fff;border-radius:9px;font-size:10px;font-weight:700;min-height:36px;padding:8px 13px}
.btn-light-custom{border:1px solid var(--border-color);background:var(--card-bg);color:var(--text-color);border-radius:9px;font-size:10px;font-weight:700;min-height:36px;padding:8px 12px}
.theme-toast{position:fixed;top:78px;right:18px;z-index:20000;display:flex;gap:9px;align-items:center;min-width:260px;max-width:420px;padding:11px 14px;border-radius:10px;color:#fff;font-size:11px;font-weight:600;box-shadow:0 14px 35px rgba(0,0,0,.22);opacity:0;transform:translateY(-10px);transition:.22s}.theme-toast.show{opacity:1;transform:translateY(0)}.theme-toast-success{background:#168449}.theme-toast-error{background:#c0392b}
body.dark-mode,body[data-theme="dark"],html.dark-mode body,html[data-theme="dark"] body{--page-bg:#0f151b;--card-bg:#182129;--text-color:#f3f6f8;--muted-color:#9aa7b3;--border-color:#2c3944}
@media(max-width:991px){.form-grid{grid-template-columns:repeat(2,1fr)}.span-2,.span-3,.span-5,.span-12{grid-column:span 1}.span-full{grid-column:1/-1}}
@media(max-width:767px){.form-grid{grid-template-columns:1fr}.span-2,.span-3,.span-5,.span-12,.span-full{grid-column:1}.page-heading{align-items:flex-start;flex-direction:column}}
</style>
</head>
<body>
<?php include 'includes/sidebar.php'; ?>
<main class="app-main">
<?php include 'includes/nav.php'; ?>

<div class="content-wrap">
    <div class="page-heading">
        <div>
            <h1 class="page-title">Create Chit</h1>
            <div class="page-subtitle"><?php echo h($businessName); ?> · Create the group and monthly installments</div>
        </div>
        <a href="chit-groups.php" class="btn btn-light-custom">Back to Groups</a>
    </div>

    <form id="chitCreateForm" class="panel">
        <div class="panel-head">
            <div class="panel-title">Chit Group Details</div>
            <div class="panel-subtitle">All fields are validated again in the API.</div>
        </div>

        <div class="panel-body">
            <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">

            <div class="form-grid">
                <div class="span-3">
                    <label class="field-label">Group Number</label>
                    <input type="text" name="group_no" id="group_no" class="form-control" value="<?php echo h($groupNo); ?>" maxlength="100" readonly>
                    <div class="field-help">Generated from Master Control based on the start date.</div>
                </div>

                <div class="span-5">
                    <label class="field-label">Group Name</label>
                    <input type="text" name="group_name" class="form-control" maxlength="150" required>
                </div>

                <div class="span-2">
                    <label class="field-label">Chit Type</label>
                    <select name="chit_type" class="form-select">
                        <option value="Money">Money</option>
                        <option value="Silver">Silver</option>
                        <option value="Gold">Gold</option>
                    </select>
                </div>

                <div class="span-2">
                    <label class="field-label">Start Date</label>
                    <input type="date" name="start_date" id="start_date" class="form-control" value="<?php echo h($today); ?>" required>
                </div>

                <div class="span-2">
                    <label class="field-label">Total Members</label>
                    <input type="number" name="total_members" class="form-control" min="1" max="10000" required>
                </div>

                <div class="span-2">
                    <label class="field-label">Total Months</label>
                    <input type="number" name="total_months" class="form-control" min="1" max="600" required>
                </div>

                <div class="span-2">
                    <label class="field-label">Installment Amount</label>
                    <input type="number" step="0.01" min="0" name="installment_amount" id="installment_amount" class="form-control" value="0.00" required>
                </div>

                <div class="span-2">
                    <label class="field-label">Chit Value</label>
                    <input type="number" step="0.01" min="0" name="chit_value" id="chit_value" class="form-control" value="0.00" required>
                </div>

                <div class="span-2">
                    <label class="field-label">Auction Type</label>
                    <select name="auction_type" class="form-select">
                        <option value="Auction">Auction</option>
                        <option value="Lucky Draw">Lucky Draw</option>
                        <option value="Manual">Manual</option>
                    </select>
                </div>

                <div class="span-2">
                    <label class="field-label">Auction Day</label>
                    <input type="number" min="1" max="31" name="auction_day" class="form-control">
                </div>

                <div class="span-2">
                    <label class="field-label">Grace Days</label>
                    <input type="number" min="0" max="365" name="grace_days" class="form-control" value="0">
                </div>

                <div class="span-2">
                    <label class="field-label">Late Fee</label>
                    <input type="number" step="0.01" min="0" name="late_fee_amount" class="form-control" value="0.00">
                </div>

                <div class="span-12 span-full">
                    <label class="field-label">Notes</label>
                    <textarea name="notes" class="form-control" maxlength="2000"></textarea>
                </div>
            </div>

            <div class="d-flex gap-2 mt-3">
                <button type="submit" class="btn btn-theme" id="saveChitButton">
                    <i class="fa-solid fa-floppy-disk me-1"></i>Save Chit
                </button>
                <a href="chit-groups.php" class="btn btn-light-custom">Cancel</a>
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

    function toast(type,message){
        const element=document.createElement('div');
        element.className='theme-toast theme-toast-'+type;
        element.innerHTML='<i class="fa-solid '+(type==='success'?'fa-circle-check':'fa-circle-exclamation')+'"></i><span></span>';
        element.querySelector('span').textContent=message;
        document.body.appendChild(element);
        requestAnimationFrame(()=>element.classList.add('show'));
        setTimeout(()=>{element.classList.remove('show');setTimeout(()=>element.remove(),250)},3500);
    }

    const members=document.querySelector('[name="total_members"]');
    const months=document.querySelector('[name="total_months"]');
    const installment=document.getElementById('installment_amount');
    const chitValue=document.getElementById('chit_value');

    function calculateChitValue(){
        const totalMonths=Number(months.value||0);
        const amount=Number(installment.value||0);

        // Chit value = monthly installment × total months.
        // Total members controls how many subscribers can join the group,
        // but it must not be used to calculate the chit value.
        if(totalMonths>0&&amount>=0&&!chitValue.dataset.manual){
            chitValue.value=(totalMonths*amount).toFixed(2);
        }
    }

    months.addEventListener('input',calculateChitValue);
    installment.addEventListener('input',calculateChitValue);
    members.addEventListener('input',calculateChitValue);
    chitValue.addEventListener('input',()=>chitValue.dataset.manual='1');

    const groupNoInput=document.getElementById('group_no');
    const startDateInput=document.getElementById('start_date');
    let previewTimer=null;

    async function refreshGroupNumber(){
        if(!groupNoInput||!startDateInput||!startDateInput.value)return;
        clearTimeout(previewTimer);
        previewTimer=setTimeout(async()=>{
            const data=new FormData();
            data.append('action','preview_number');
            data.append('csrf_token',<?php echo json_encode($csrfToken); ?>);
            data.append('start_date',startDateInput.value);
            try{
                const response=await fetch('api/chit-create-save.php',{
                    method:'POST',body:data,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}
                });
                const result=await response.json();
                if(response.ok&&result.success&&result.group_no){
                    groupNoInput.value=result.group_no;
                }
            }catch(error){
                console.warn('Unable to refresh chit number preview.',error);
            }
        },180);
    }
    startDateInput?.addEventListener('change',refreshGroupNumber);
    startDateInput?.addEventListener('input',refreshGroupNumber);

    const form=document.getElementById('chitCreateForm');

    form.addEventListener('submit',async function(event){
        event.preventDefault();

        const button=document.getElementById('saveChitButton');
        const original=button.innerHTML;

        button.disabled=true;
        button.innerHTML='<i class="fa-solid fa-spinner fa-spin me-1"></i>Saving...';

        try{
            const response=await fetch('api/chit-create-save.php',{
                method:'POST',
                body:new FormData(form),
                credentials:'same-origin',
                headers:{'X-Requested-With':'XMLHttpRequest'}
            });

            const result=await response.json().catch(()=>({
                success:false,
                message:'Invalid JSON response from the API.'
            }));

            if(!response.ok||!result.success){
                throw new Error(result.message||'Unable to create chit group.');
            }

            toast('success',result.message);
            setTimeout(()=>location.href='chit-groups.php?msg=created',700);
        }catch(error){
            toast('error',error.message);
        }finally{
            button.disabled=false;
            button.innerHTML=original;
        }
    });
})();
</script>
</body>
</html>