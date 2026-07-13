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

if (!function_exists('hasChitGroupPermission')) {
    function hasChitGroupPermission(mysqli $conn, string $action): bool
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

        if ($businessId <= 0 || $roleId <= 0) {
            return false;
        }

        if (!tableExists($conn, 'permissions') || !tableExists($conn, 'role_permissions')) {
            return false;
        }

        $placeholders = implode(',', array_fill(0, count($permissionCodes), '?'));

        $sql = "SELECT MAX(COALESCE(rp.`{$field}`,0)) AS allowed
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
$groupId = (int)($_GET['id'] ?? 0);

if ($userId <= 0) {
    header('Location: login.php');
    exit;
}

if ($businessId <= 0 || $branchId <= 0) {
    die('A valid business and branch must be selected.');
}

if ($groupId <= 0) {
    die('A valid chit group is required.');
}

if (!hasChitGroupPermission($conn, 'open')) {
    http_response_code(403);
    die('Access denied. You do not have permission to open chit group details.');
}

$canView = hasChitGroupPermission($conn, 'view') || hasChitGroupPermission($conn, 'open');
$canViewValue = hasChitGroupPermission($conn, 'value') || $canView;
$canCreate = hasChitGroupPermission($conn, 'create');

foreach (['chit_groups', 'chit_members', 'chit_installments', 'customers'] as $requiredTable) {
    if (!tableExists($conn, $requiredTable)) {
        die("Required table `{$requiredTable}` was not found.");
    }
}

$pageTitle = 'Chit Group Details';
$page_title = 'Chit Group Details';
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
<title><?php echo h($businessName); ?> - Chit Group Details</title>
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
.summary-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin-bottom:10px}
.summary-card,.panel{background:var(--card-bg);border:1px solid var(--border-color);border-radius:var(--radius)}
.summary-card{padding:12px 14px}
.summary-label{font-size:9px;color:var(--muted-color)}.summary-value{font-size:18px;font-weight:800;margin-top:3px}
.panel{overflow:hidden}
.panel-head{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:11px 13px;border-bottom:1px solid var(--border-color)}
.panel-title{font-size:12px;font-weight:800}.panel-subtitle{font-size:9px;color:var(--muted-color);margin-top:2px}
.content-grid{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(320px,.8fr);gap:10px}
.btn-theme{background:linear-gradient(135deg,var(--primary),var(--primary-dark));border:0;color:#fff;border-radius:9px;font-size:10px;font-weight:700;min-height:36px;padding:8px 13px}
.btn-light-custom{border:1px solid var(--border-color);background:var(--card-bg);color:var(--text-color);border-radius:9px;font-size:10px;font-weight:700;min-height:36px;padding:8px 12px}
.compact-table{margin:0;font-size:10px}.compact-table th{font-size:9px;text-transform:uppercase;color:var(--muted-color);background:color-mix(in srgb,var(--muted-color) 6%,transparent);padding:10px 11px;white-space:nowrap;border-color:var(--border-color)}.compact-table td{padding:10px 11px;vertical-align:middle;background:var(--card-bg)!important;color:var(--text-color);border-color:var(--border-color)}
.main-text{font-weight:800}.subtext{font-size:8px;color:var(--muted-color);margin-top:2px}
.status-badge{display:inline-flex;border-radius:999px;padding:4px 8px;font-size:8px;font-weight:800}.status-active{background:#eaf8f0;color:#168449}.status-paid{background:#e9f2ff;color:#2765b3}.status-open{background:#fff3d7;color:#a66800}.status-closed{background:#edf1f5;color:#536170}.status-other{background:#f4ecff;color:#7540a8}
.progress-track{height:7px;border-radius:999px;background:var(--border-color);overflow:hidden;margin-top:6px}.progress-fill{height:100%;background:linear-gradient(90deg,var(--primary),var(--primary-dark));border-radius:999px}
.theme-toast{position:fixed;top:78px;right:18px;z-index:20000;display:flex;align-items:center;gap:9px;min-width:260px;max-width:420px;padding:11px 14px;border-radius:10px;color:#fff;font-size:11px;font-weight:600;box-shadow:0 14px 35px rgba(0,0,0,.22);opacity:0;transform:translateY(-10px);transition:.22s}.theme-toast.show{opacity:1;transform:translateY(0)}.theme-toast-error{background:#c0392b}
.empty-state{padding:42px 20px;text-align:center;color:var(--muted-color)}
body.dark-mode,body[data-theme="dark"],html.dark-mode body,html[data-theme="dark"] body{--page-bg:#0f151b;--card-bg:#182129;--text-color:#f3f6f8;--muted-color:#9aa7b3;--border-color:#2c3944}
@media(max-width:991px){.summary-grid{grid-template-columns:repeat(2,1fr)}.content-grid{grid-template-columns:1fr}.responsive-table thead{display:none}.responsive-table tbody{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;padding:10px}.responsive-table tbody tr{display:grid;grid-template-columns:1fr 1fr;border:1px solid var(--border-color);border-radius:var(--radius);padding:12px}.responsive-table tbody td{display:flex;justify-content:space-between;gap:10px;padding:8px 0;border:0;border-bottom:1px dashed var(--border-color);text-align:right}.responsive-table tbody td::before{content:attr(data-label);font-size:8px;font-weight:700;text-transform:uppercase;color:var(--muted-color)}.responsive-table tbody td.main-column{grid-column:1/-1;display:block;text-align:left}.responsive-table tbody td.main-column::before{display:none}}
@media(max-width:767px){.summary-grid{grid-template-columns:1fr}.page-heading,.panel-head{align-items:flex-start;flex-direction:column}.responsive-table tbody{grid-template-columns:1fr}.responsive-table tbody tr{grid-template-columns:1fr}.responsive-table tbody td{grid-column:1/-1}}
</style>
</head>
<body>
<?php include 'includes/sidebar.php'; ?>
<main class="app-main">
<?php include 'includes/nav.php'; ?>

<div class="content-wrap">
    <div class="page-heading">
        <div>
            <h1 class="page-title" id="groupTitle">Chit Group Details</h1>
            <div class="page-subtitle" id="groupSubtitle"><?php echo h($businessName); ?> · Loading group details...</div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <?php if ($canCreate): ?>
                <a href="chit-member-add.php?group_id=<?php echo $groupId; ?>" class="btn btn-theme">
                    <i class="fa-solid fa-user-plus me-1"></i>Add Member
                </a>
            <?php endif; ?>
            <a href="chit-groups.php" class="btn btn-light-custom">Back to Groups</a>
        </div>
    </div>

    <?php if (!$canView): ?>
        <div class="panel"><div class="empty-state">You do not have permission to view this chit group.</div></div>
    <?php else: ?>
        <div class="summary-grid">
            <div class="summary-card"><div class="summary-label">Group Number</div><div class="summary-value" id="summaryGroupNo">—</div></div>
            <div class="summary-card"><div class="summary-label">Chit Value</div><div class="summary-value" id="summaryValue"><?php echo $canViewValue ? '₹0.00' : '••••'; ?></div></div>
            <div class="summary-card"><div class="summary-label">Installment</div><div class="summary-value" id="summaryInstallment"><?php echo $canViewValue ? '₹0.00' : '••••'; ?></div></div>
            <div class="summary-card"><div class="summary-label">Status</div><div class="summary-value" id="summaryStatus">—</div></div>
        </div>

        <div class="summary-grid">
            <div class="summary-card">
                <div class="summary-label">Members</div>
                <div class="summary-value" id="summaryMembers">0 / 0</div>
                <div class="progress-track"><div class="progress-fill" id="memberProgress" style="width:0%"></div></div>
            </div>
            <div class="summary-card"><div class="summary-label">Total Months</div><div class="summary-value" id="summaryMonths">0</div></div>
            <div class="summary-card"><div class="summary-label">Start Date</div><div class="summary-value" id="summaryStart">—</div></div>
            <div class="summary-card"><div class="summary-label">End Date</div><div class="summary-value" id="summaryEnd">—</div></div>
        </div>

        <div class="content-grid">
            <div class="panel">
                <div class="panel-head">
                    <div><div class="panel-title">Members</div><div class="panel-subtitle">Customers enrolled in this chit group.</div></div>
                </div>

                <div id="memberLoading" class="empty-state"><i class="fa-solid fa-spinner fa-spin mb-2"></i><div>Loading members...</div></div>
                <div id="memberTableWrap" class="table-responsive d-none">
                    <table class="table compact-table responsive-table">
                        <thead><tr><th>#</th><th>Ticket</th><th>Customer</th><th>Mobile</th><th>Join Date</th><th>Status</th><th>Action</th></tr></thead>
                        <tbody id="memberTableBody"></tbody>
                    </table>
                </div>
                <div id="memberEmpty" class="empty-state d-none">No members found in this group.</div>
            </div>

            <div class="panel">
                <div class="panel-head">
                    <div><div class="panel-title">Installments</div><div class="panel-subtitle">Installment schedule for this chit group.</div></div>
                </div>

                <div id="installmentLoading" class="empty-state"><i class="fa-solid fa-spinner fa-spin mb-2"></i><div>Loading installments...</div></div>
                <div id="installmentTableWrap" class="table-responsive d-none">
                    <table class="table compact-table responsive-table">
                        <thead><tr><th>No</th><th>Due Date</th><th>Auction Date</th><th>Status</th></tr></thead>
                        <tbody id="installmentTableBody"></tbody>
                    </table>
                </div>
                <div id="installmentEmpty" class="empty-state d-none">No installments found.</div>
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

    const groupId=<?php echo $groupId; ?>;
    const canViewValue=<?php echo $canViewValue ? 'true' : 'false'; ?>;

    function toast(message){
        const element=document.createElement('div');
        element.className='theme-toast theme-toast-error';
        element.innerHTML='<i class="fa-solid fa-circle-exclamation"></i><span></span>';
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

    function money(value){
        return Number(value||0).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2});
    }

    function statusClass(status){
        if(status==='Active')return 'status-active';
        if(status==='Paid')return 'status-paid';
        if(status==='Open')return 'status-open';
        if(status==='Closed')return 'status-closed';
        return 'status-other';
    }

    function renderGroup(group,summary){
        document.getElementById('groupTitle').textContent=group.group_name;
        document.getElementById('groupSubtitle').textContent=group.group_no+' · '+group.chit_type;
        document.getElementById('summaryGroupNo').textContent=group.group_no;
        document.getElementById('summaryStatus').innerHTML=`<span class="status-badge ${statusClass(group.status)}">${escapeHtml(group.status)}</span>`;
        document.getElementById('summaryMembers').textContent=`${summary.member_count} / ${group.total_members}`;
        document.getElementById('summaryMonths').textContent=group.total_months;
        document.getElementById('summaryStart').textContent=group.start_date_display;
        document.getElementById('summaryEnd').textContent=group.end_date_display;

        const percent=group.total_members>0?Math.min(100,(summary.member_count/group.total_members)*100):0;
        document.getElementById('memberProgress').style.width=percent.toFixed(1)+'%';

        if(canViewValue){
            document.getElementById('summaryValue').textContent='₹'+money(group.chit_value);
            document.getElementById('summaryInstallment').textContent='₹'+money(group.installment_amount);
        }
    }

    function renderMembers(rows){
        const loading=document.getElementById('memberLoading');
        const wrap=document.getElementById('memberTableWrap');
        const empty=document.getElementById('memberEmpty');
        const body=document.getElementById('memberTableBody');

        loading.classList.add('d-none');

        if(!rows.length){
            wrap.classList.add('d-none');
            empty.classList.remove('d-none');
            return;
        }

        empty.classList.add('d-none');
        wrap.classList.remove('d-none');

        body.innerHTML=rows.map((row,index)=>`<tr>
            <td data-label="#">${index+1}</td>
            <td data-label="Ticket"><strong>${escapeHtml(row.ticket_no)}</strong></td>
            <td class="main-column" data-label="Customer"><div class="main-text">${escapeHtml(row.customer_name)}</div><div class="subtext">${escapeHtml(row.customer_code)}</div></td>
            <td data-label="Mobile">${row.mobile?`<a href="tel:${escapeHtml(row.mobile)}">${escapeHtml(row.mobile)}</a>`:'—'}</td>
            <td data-label="Join Date">${escapeHtml(row.join_date_display)}</td>
            <td data-label="Status"><span class="status-badge ${statusClass(row.status)}">${escapeHtml(row.status)}</span></td>
            <td data-label="Action"><a class="btn btn-theme" style="min-height:auto;padding:6px 9px" href="chit-collection.php?member_id=${Number(row.id)}">Collect</a></td>
        </tr>`).join('');
    }

    function renderInstallments(rows){
        const loading=document.getElementById('installmentLoading');
        const wrap=document.getElementById('installmentTableWrap');
        const empty=document.getElementById('installmentEmpty');
        const body=document.getElementById('installmentTableBody');

        loading.classList.add('d-none');

        if(!rows.length){
            wrap.classList.add('d-none');
            empty.classList.remove('d-none');
            return;
        }

        empty.classList.add('d-none');
        wrap.classList.remove('d-none');

        body.innerHTML=rows.map(row=>`<tr>
            <td data-label="No">#${Number(row.installment_no)}</td>
            <td data-label="Due Date">${escapeHtml(row.due_date_display)}</td>
            <td data-label="Auction Date">${escapeHtml(row.auction_date_display||'—')}</td>
            <td data-label="Status"><span class="status-badge ${statusClass(row.status)}">${escapeHtml(row.status)}</span></td>
        </tr>`).join('');
    }

    async function loadGroup(){
        try{
            const response=await fetch('api/chit-view.php?id='+encodeURIComponent(groupId),{credentials:'same-origin'});
            const result=await response.json().catch(()=>({success:false,message:'Invalid JSON response from API.'}));

            if(!response.ok||!result.success){
                throw new Error(result.message||'Unable to load chit group details.');
            }

            renderGroup(result.group,result.summary);
            renderMembers(result.members||[]);
            renderInstallments(result.installments||[]);
        }catch(error){
            document.getElementById('memberLoading').classList.add('d-none');
            document.getElementById('installmentLoading').classList.add('d-none');
            document.getElementById('memberEmpty').classList.remove('d-none');
            document.getElementById('installmentEmpty').classList.remove('d-none');
            document.getElementById('memberEmpty').textContent=error.message;
            document.getElementById('installmentEmpty').textContent=error.message;
            toast(error.message);
        }
    }

    loadGroup();
})();
</script>
</body>
</html>
