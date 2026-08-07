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

if (!function_exists('chitGroupPermission')) {
    function chitGroupPermission(mysqli $conn, string $action): bool
    {
        $fieldMap = [
            'open' => 'can_open',
            'view' => 'can_view',
            'value' => 'can_view_value',
            'create' => 'can_create',
            'update' => 'can_update',
            'delete' => 'can_delete',
        ];

        $field = $fieldMap[$action] ?? '';

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

if ($userId <= 0) {
    header('Location: login.php');
    exit;
}

if ($businessId <= 0 || $branchId <= 0) {
    http_response_code(403);
    die('A valid business and branch must be selected before viewing chit groups.');
}

if (!chitGroupPermission($conn, 'open')) {
    http_response_code(403);
    die('Access denied. You do not have permission to open chit groups.');
}

$canView = chitGroupPermission($conn, 'view') || chitGroupPermission($conn, 'open');
$canCreate = chitGroupPermission($conn, 'create');
$canUpdate = chitGroupPermission($conn, 'update');
$canDelete = chitGroupPermission($conn, 'delete');
$canViewValue = chitGroupPermission($conn, 'value') || $canView;

foreach (['chit_groups', 'chit_members'] as $requiredTable) {
    if (!tableExists($conn, $requiredTable)) {
        die("Required table `{$requiredTable}` was not found.");
    }
}

$pageTitle = 'Chit Groups';
$page_title = 'Chit Groups';
$currentPage = 'chit-groups';

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
if (empty($_SESSION['chit_groups_csrf'])) { $_SESSION['chit_groups_csrf'] = bin2hex(random_bytes(32)); }
$csrfToken = (string)$_SESSION['chit_groups_csrf'];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo h($businessName); ?> - Chit Groups</title>
<?php include 'includes/links.php'; ?>
<style>
:root{
    --primary:<?php echo h($theme['primary_color']); ?>;
    --primary-dark:<?php echo h($theme['primary_dark_color']); ?>;
    --page-bg:<?php echo h($theme['page_background']); ?>;
    --card-bg:<?php echo h($theme['card_background']); ?>;
    --text-color:<?php echo h($theme['text_color']); ?>;
    --muted-color:<?php echo h($theme['muted_text_color']); ?>;
    --border-color:<?php echo h($theme['border_color']); ?>;
    --radius:<?php echo (int)$theme['border_radius_px']; ?>px;
}
body{background:var(--page-bg);color:var(--text-color);font-family:<?php echo json_encode((string)$theme['font_family']); ?>,sans-serif}
.sidebar{background:linear-gradient(180deg,<?php echo h($theme['sidebar_gradient_1']); ?>,<?php echo h($theme['sidebar_gradient_2']); ?>,<?php echo h($theme['sidebar_gradient_3']); ?>)!important}
.page-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:10px}
.page-title{font-family:<?php echo json_encode((string)$theme['heading_font_family']); ?>,serif;font-size:18px;font-weight:800;margin:0}
.page-subtitle{font-size:10px;color:var(--muted-color);margin-top:2px}
.panel{background:var(--card-bg);border:1px solid var(--border-color);border-radius:var(--radius);overflow:hidden}
.panel-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:11px 13px;border-bottom:1px solid var(--border-color)}
.panel-title{font-size:12px;font-weight:800}
.panel-subtitle{font-size:9px;color:var(--muted-color);margin-top:2px}
.search-wrap{display:flex;gap:8px;align-items:center}
.search-wrap input{width:240px}
.form-control{font-size:10px;min-height:36px;border-radius:9px;border-color:var(--border-color);background:var(--card-bg);color:var(--text-color)}
.btn-theme{background:linear-gradient(135deg,var(--primary),var(--primary-dark));border:0;color:#fff;border-radius:9px;font-size:10px;font-weight:700;min-height:36px;padding:8px 13px}
.btn-light-custom{border:1px solid var(--border-color);background:var(--card-bg);color:var(--text-color);border-radius:9px;font-size:10px;font-weight:700;min-height:36px;padding:8px 12px}.action-icon{width:30px;height:30px;padding:0!important;display:inline-flex;align-items:center;justify-content:center}.action-danger{color:#c0392b}.action-danger:hover{background:#fdecec!important;color:#a61f1f!important}
.compact-table{margin:0;font-size:10px}
.compact-table th{font-size:9px;text-transform:uppercase;color:var(--muted-color);background:color-mix(in srgb,var(--muted-color) 6%,transparent);padding:10px 11px;white-space:nowrap;border-color:var(--border-color)}
.compact-table td{padding:10px 11px;vertical-align:middle;background:var(--card-bg)!important;color:var(--text-color);border-color:var(--border-color)}
.main-text{font-weight:800}
.subtext{font-size:8px;color:var(--muted-color);margin-top:2px}
.status-badge{display:inline-flex;border-radius:999px;padding:4px 8px;font-size:8px;font-weight:800}
.status-active{background:#eaf8f0;color:#168449}
.status-closed{background:#edf1f5;color:#536170}
.status-other{background:#fff6df;color:#a66800}
.progress-track{width:100%;height:6px;border-radius:999px;background:var(--border-color);overflow:hidden;margin-top:5px}
.progress-bar-custom{height:100%;background:linear-gradient(90deg,var(--primary),var(--primary-dark));border-radius:999px}
.theme-toast{position:fixed;right:18px;top:78px;z-index:20000;display:flex;align-items:center;gap:9px;min-width:260px;max-width:420px;padding:11px 14px;border-radius:10px;color:#fff;font-size:11px;font-weight:600;box-shadow:0 14px 35px rgba(0,0,0,.22);opacity:0;transform:translateY(-10px);transition:.22s}
.theme-toast.show{opacity:1;transform:translateY(0)}
.theme-toast-success{background:#168449}
.theme-toast-error{background:#c0392b}
.empty-state{padding:42px 20px;text-align:center;color:var(--muted-color)}
body.dark-mode,body[data-theme="dark"],html.dark-mode body,html[data-theme="dark"] body{--page-bg:#0f151b;--card-bg:#182129;--text-color:#f3f6f8;--muted-color:#9aa7b3;--border-color:#2c3944}
@media(max-width:991.98px){
    .responsive-table thead{display:none}
    .responsive-table tbody{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;padding:10px}
    .responsive-table tbody tr{display:grid;grid-template-columns:1fr 1fr;border:1px solid var(--border-color);border-radius:var(--radius);padding:12px;background:var(--card-bg)}
    .responsive-table tbody td{display:flex;justify-content:space-between;gap:10px;padding:8px 0;border:0;border-bottom:1px dashed var(--border-color);text-align:right}
    .responsive-table tbody td::before{content:attr(data-label);font-size:8px;font-weight:700;text-transform:uppercase;color:var(--muted-color)}
    .responsive-table tbody td.main-column{grid-column:1/-1;display:block;text-align:left}
    .responsive-table tbody td.main-column::before{display:none}
}
@media(max-width:767.98px){
    .content-wrap{padding-left:10px;padding-right:10px}
    .page-heading{align-items:flex-start;flex-direction:column}
    .search-wrap{width:100%;flex-wrap:wrap}
    .search-wrap input{width:100%}
    .responsive-table tbody{grid-template-columns:1fr}
    .responsive-table tbody tr{grid-template-columns:1fr}
    .responsive-table tbody td{grid-column:1/-1}
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
            <h1 class="page-title">Chit Groups</h1>
            <div class="page-subtitle"><?php echo h($businessName); ?> · Manage chit groups, members and installment plans</div>
        </div>

        <?php if ($canCreate): ?>
            <a href="chit-create.php" class="btn btn-theme">
                <i class="fa-solid fa-plus me-1"></i>Create Chit
            </a>
        <?php endif; ?>
    </div>

    <?php if (!$canView): ?>
        <div class="panel">
            <div class="empty-state">You do not have permission to view chit groups.</div>
        </div>
    <?php else: ?>
        <div class="panel">
            <div class="panel-head">
                <div>
                    <div class="panel-title">Group Register</div>
                    <div class="panel-subtitle">All chit groups for the selected business and branch.</div>
                </div>

                <div class="search-wrap">
                    <input
                        type="search"
                        id="groupSearch"
                        class="form-control"
                        placeholder="Search group no, name or type"
                    >
                    <button
                        type="button"
                        class="btn btn-light-custom"
                        id="refreshGroups"
                        aria-label="Refresh groups"
                    >
                        <i class="fa-solid fa-rotate"></i>
                    </button>
                </div>
            </div>

            <div id="groupsLoading" class="empty-state">
                <i class="fa-solid fa-spinner fa-spin mb-2"></i>
                <div>Loading chit groups...</div>
            </div>

            <div id="groupsTableWrap" class="table-responsive d-none">
                <table class="table compact-table responsive-table">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Group</th>
                        <th>Type</th>
                        <th>Members</th>
                        <th>Months</th>
                        <th>Installment</th>
                        <th>Value</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                    </thead>
                    <tbody id="groupsTableBody"></tbody>
                </table>
            </div>

            <div id="groupsEmpty" class="empty-state d-none">
                No chit groups found.
            </div>
        </div>
    <?php endif; ?>

    <?php include 'includes/footer.php'; ?>
</div>
</main>

<?php include 'includes/script.php'; ?>
<script src="assets/js/script.js"></script>
<script>
(function(){
    'use strict';

    const canViewValue=<?php echo $canViewValue ? 'true' : 'false'; ?>;
    const canUpdate=<?php echo $canUpdate ? 'true' : 'false'; ?>;
    const canDelete=<?php echo $canDelete ? 'true' : 'false'; ?>;
    const csrfToken=<?php echo json_encode($csrfToken); ?>;
    const tableBody=document.getElementById('groupsTableBody');
    const tableWrap=document.getElementById('groupsTableWrap');
    const loading=document.getElementById('groupsLoading');
    const empty=document.getElementById('groupsEmpty');
    const search=document.getElementById('groupSearch');
    const refresh=document.getElementById('refreshGroups');

    if(!tableBody||!tableWrap||!loading||!empty){
        return;
    }

    let rows=[];

    function toast(type,message){
        const element=document.createElement('div');
        element.className='theme-toast theme-toast-'+type;
        element.innerHTML='<i class="fa-solid '+(type==='success'?'fa-circle-check':'fa-circle-exclamation')+'"></i><span></span>';
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

    function money(value){
        return Number(value||0).toLocaleString('en-IN',{
            minimumFractionDigits:2,
            maximumFractionDigits:2
        });
    }

    function statusClass(status){
        if(status==='Active')return 'status-active';
        if(status==='Closed')return 'status-closed';
        return 'status-other';
    }

    function render(){
        const term=(search?.value||'').trim().toLowerCase();

        const filtered=rows.filter(row=>{
            const haystack=[
                row.group_no,
                row.group_name,
                row.chit_type,
                row.status
            ].join(' ').toLowerCase();

            return haystack.includes(term);
        });

        loading.classList.add('d-none');

        if(!filtered.length){
            tableWrap.classList.add('d-none');
            empty.classList.remove('d-none');
            empty.textContent=rows.length
                ? 'No matching chit groups found.'
                : 'No chit groups found.';
            return;
        }

        empty.classList.add('d-none');
        tableWrap.classList.remove('d-none');

        tableBody.innerHTML=filtered.map((row,index)=>{
            const memberCount=Number(row.member_count||0);
            const totalMembers=Number(row.total_members||0);
            const percentage=totalMembers>0
                ? Math.min(100,(memberCount/totalMembers)*100)
                : 0;

            return `<tr>
                <td data-label="#">${index+1}</td>
                <td class="main-column" data-label="Group">
                    <div class="main-text">${escapeHtml(row.group_name)}</div>
                    <div class="subtext">${escapeHtml(row.group_no)}</div>
                </td>
                <td data-label="Type">${escapeHtml(row.chit_type)}</td>
                <td data-label="Members">
                    <div><strong>${memberCount}</strong> / ${totalMembers}</div>
                    <div class="progress-track">
                        <div class="progress-bar-custom" style="width:${percentage.toFixed(1)}%"></div>
                    </div>
                </td>
                <td data-label="Months">${Number(row.total_months||0)}</td>
                <td data-label="Installment">${canViewValue?'₹'+money(row.installment_amount):'••••'}</td>
                <td data-label="Value">${canViewValue?'₹'+money(row.chit_value):'••••'}</td>
                <td data-label="Status">
                    <span class="status-badge ${statusClass(row.status)}">
                        ${escapeHtml(row.status)}
                    </span>
                </td>
                <td data-label="Action">
                    <div class="d-flex gap-1 justify-content-end flex-wrap">
                        <a href="chit-view.php?id=${Number(row.id)}" class="btn btn-light-custom action-icon" title="View"><i class="fa-solid fa-eye"></i></a>
                        ${canUpdate?`<a href="chit-edit.php?id=${Number(row.id)}" class="btn btn-light-custom action-icon" title="Edit"><i class="fa-solid fa-pen"></i></a>`:''}
                        ${canDelete?`<button type="button" class="btn btn-light-custom action-icon action-danger delete-group" data-id="${Number(row.id)}" data-name="${escapeHtml(row.group_name)}" title="Delete"><i class="fa-solid fa-trash"></i></button>`:''}
                        <a href="chit-members.php?group_id=${Number(row.id)}" class="btn btn-light-custom" style="min-height:auto;padding:6px 9px" title="View Members"><i class="fa-solid fa-users me-1"></i>Members</a>
                        <a href="chit-member-add.php?group_id=${Number(row.id)}" class="btn btn-theme" style="min-height:auto;padding:6px 9px" title="Create Member"><i class="fa-solid fa-user-plus me-1"></i>Create Member</a>
                    </div>
                </td>
            </tr>`;
        }).join('');
    }

    async function loadGroups(){
        loading.classList.remove('d-none');
        loading.innerHTML='<i class="fa-solid fa-spinner fa-spin mb-2"></i><div>Loading chit groups...</div>';
        tableWrap.classList.add('d-none');
        empty.classList.add('d-none');

        try{
            const response=await fetch('api/chit-groups-list.php',{
                method:'GET',
                credentials:'same-origin',
                headers:{
                    'Accept':'application/json',
                    'X-Requested-With':'XMLHttpRequest'
                }
            });

            const responseText=await response.text();
            let result;

            try{
                result=JSON.parse(responseText);
            }catch(parseError){
                throw new Error(
                    responseText
                        ? 'API returned invalid output: '+responseText.substring(0,180)
                        : 'API returned an empty response.'
                );
            }

            if(!response.ok||!result.success){
                throw new Error(result.message||'Unable to load chit groups.');
            }

            rows=Array.isArray(result.rows)?result.rows:[];
            render();
        }catch(error){
            rows=[];
            loading.classList.add('d-none');
            tableWrap.classList.add('d-none');
            empty.classList.remove('d-none');
            empty.textContent=error.message;
            toast('error',error.message);
        }
    }

    search?.addEventListener('input',render);
    refresh?.addEventListener('click',loadGroups);

    tableBody.addEventListener('click',async function(event){
        const button=event.target.closest('.delete-group');
        if(!button)return;
        const id=Number(button.dataset.id||0);
        if(id<=0)return;
        if(!confirm('Delete '+(button.dataset.name||'this chit group')+'? This is allowed only when no members, collections, prizes or payouts are linked.'))return;
        button.disabled=true;
        try{
            const body=new FormData();
            body.append('action','delete'); body.append('group_id',String(id)); body.append('csrf_token',csrfToken);
            const response=await fetch('api/chit-group-actions.php',{method:'POST',body,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}});
            const result=await response.json().catch(()=>({success:false,message:'Invalid response from delete API.'}));
            if(!response.ok||!result.success)throw new Error(result.message||'Unable to delete chit group.');
            rows=rows.filter(row=>Number(row.id)!==id); render(); toast('success',result.message);
        }catch(error){toast('error',error.message)}finally{button.disabled=false}
    });

    const params=new URLSearchParams(location.search);
    if(params.get('msg')==='created')toast('success','Chit group created successfully.');
    if(params.get('msg')==='updated')toast('success','Chit group updated successfully.');

    loadGroups();
})();
</script>
</body>
</html>
