<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));

$configCandidates = [
    __DIR__ . '/config/config.php',
    __DIR__ . '/config.php',
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
$conn->set_charset('utf8mb4');

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('sidebarMasterPermission')) {
    function sidebarMasterPermission(mysqli $conn, string $action): bool
    {
        if (($_SESSION['user_type'] ?? '') === 'Platform Admin') {
            return true;
        }

        $fieldMap = [
            'open' => 'can_open',
            'view' => 'can_view',
            'create' => 'can_create',
            'update' => 'can_update',
            'delete' => 'can_delete',
        ];
        $field = $fieldMap[$action] ?? '';
        if ($field === '') return false;

        $sessionPermissions = $_SESSION['permissions'] ?? [];
        foreach (['perm.settings', 'perm.staff.roles'] as $key) {
            if (isset($sessionPermissions[$key][$field])) {
                return (int)$sessionPermissions[$key][$field] === 1;
            }
        }

        $businessId = (int)($_SESSION['business_id'] ?? 0);
        $roleId = (int)($_SESSION['role_id'] ?? 0);
        if ($businessId <= 0 || $roleId <= 0) return false;

        $sql = "SELECT rp.`{$field}`
                FROM role_permissions rp
                INNER JOIN permissions p ON p.id = rp.permission_id
                WHERE rp.business_id = ?
                  AND rp.role_id = ?
                  AND p.permission_code IN ('perm.settings','perm.staff.roles')
                  AND p.is_active = 1
                ORDER BY FIELD(p.permission_code,'perm.settings','perm.staff.roles')
                LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) return false;
        $stmt->bind_param('ii', $businessId, $roleId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($row[$field] ?? 0) === 1;
    }
}

if (!sidebarMasterPermission($conn, 'open')) {
    http_response_code(403);
    die('Access denied. You do not have permission to open Sidebar Master.');
}

$canView = sidebarMasterPermission($conn, 'view') || sidebarMasterPermission($conn, 'open');
$canCreate = sidebarMasterPermission($conn, 'create');
$canUpdate = sidebarMasterPermission($conn, 'update');
$canDelete = sidebarMasterPermission($conn, 'delete');
$businessId = (int)($_SESSION['business_id'] ?? 0);
$isPlatformAdmin = (($_SESSION['user_type'] ?? '') === 'Platform Admin');

if (empty($_SESSION['sidebar_master_csrf'])) {
    $_SESSION['sidebar_master_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['sidebar_master_csrf'];

$items = [];
$parents = [];
if ($canView) {
    if ($isPlatformAdmin) {
        $sql = "SELECT mi.*, b.business_name
                FROM menu_items mi
                LEFT JOIN businesses b ON b.id = mi.business_id
                ORDER BY COALESCE(mi.business_id,0), mi.sort_order, mi.id";
        $result = $conn->query($sql);
    } else {
        $stmt = $conn->prepare("SELECT mi.*, NULL AS business_name
                                FROM menu_items mi
                                WHERE mi.business_id IS NULL OR mi.business_id = ?
                                ORDER BY CASE WHEN mi.business_id IS NULL THEN 0 ELSE 1 END,
                                         mi.sort_order, mi.id");
        $stmt->bind_param('i', $businessId);
        $stmt->execute();
        $result = $stmt->get_result();
    }

    while ($result && $row = $result->fetch_assoc()) {
        $items[] = $row;
        if (in_array($row['menu_type'], ['Menu', 'Group'], true)) {
            $parents[] = $row;
        }
    }
    if (isset($stmt) && $stmt instanceof mysqli_stmt) $stmt->close();
}

$itemMap = [];
foreach ($items as $item) {
    $itemMap[(int)$item['id']] = $item;
}

function menuLevel(array $item, array $itemMap): int
{
    $level = 0;
    $seen = [];
    $parentId = (int)($item['parent_id'] ?? 0);
    while ($parentId > 0 && isset($itemMap[$parentId]) && !isset($seen[$parentId]) && $level < 10) {
        $seen[$parentId] = true;
        $level++;
        $parentId = (int)($itemMap[$parentId]['parent_id'] ?? 0);
    }
    return $level;
}

$businessName = (string)($_SESSION['business_name'] ?? 'Jewellery ERP');
$pageTitle = 'Sidebar Master';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo e($businessName); ?> - Sidebar Master</title>
    <?php include('includes/links.php'); ?>
    <style>
        .sm-card{background:var(--card,#fff);border:1px solid var(--line,#e8e8e8);border-radius:12px;box-shadow:var(--shadow,0 5px 18px rgba(24,31,40,.07));overflow:hidden}
        .sm-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 14px;border-bottom:1px solid var(--line,#e8e8e8)}
        .sm-title{font-size:14px;font-weight:700;margin:0}.sm-subtitle{font-size:10px;color:var(--muted,#7d8794)}
        .sm-toolbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:10px 14px;border-bottom:1px solid var(--line,#e8e8e8)}
        .sm-search{max-width:260px;font-size:11px;min-height:34px}.sm-filter{font-size:11px;min-height:34px;max-width:170px}
        .btn-primary-sm{border:0;border-radius:8px;padding:8px 12px;background:linear-gradient(135deg,var(--gold,#d89416),var(--gold-dark,#b86a0b));color:#fff;font-size:11px;font-weight:700}
        .table-smaster{margin:0;font-size:11px}.table-smaster th{font-size:9px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted,#7d8794);background:rgba(127,127,127,.03);white-space:nowrap}.table-smaster td{vertical-align:middle}
        .menu-name{display:flex;align-items:center;gap:8px;font-weight:600}.menu-icon{width:28px;height:28px;display:inline-flex;align-items:center;justify-content:center;border-radius:8px;background:var(--gold-soft,#fff6e5);color:var(--gold,#d89416)}
        .indent-1{padding-left:22px}.indent-2{padding-left:44px}.indent-3{padding-left:66px}.indent-4{padding-left:88px}
        .badge-soft{display:inline-flex;align-items:center;padding:3px 7px;border-radius:999px;font-size:9px;font-weight:700}.badge-menu{background:#eef4ff;color:#315b9f}.badge-group{background:#f4edff;color:#6e3fa5}.badge-divider{background:#f1f1f1;color:#666}.badge-global{background:#edf8f1;color:#25734a}.badge-business{background:#fff6e5;color:#9a6400}
        .action-btn{width:30px;height:30px;border:1px solid var(--line,#e8e8e8);border-radius:8px;background:var(--card,#fff);display:inline-flex;align-items:center;justify-content:center;font-size:11px;color:var(--text,#171717)}.action-btn:hover{background:rgba(127,127,127,.06)}
        .drag-handle{cursor:grab;color:var(--muted,#7d8794)}
        .empty-state{padding:44px 16px;text-align:center;color:var(--muted,#7d8794);font-size:11px}
        .form-label{font-size:10px;font-weight:600}.form-control,.form-select{font-size:11px;min-height:36px;border-radius:8px}.form-check-label{font-size:11px}
        .sm-toast{position:fixed;right:18px;top:78px;z-index:20000;display:flex;align-items:center;gap:8px;min-width:260px;max-width:420px;padding:11px 14px;border-radius:10px;color:#fff;font-size:11px;font-weight:600;box-shadow:0 14px 35px rgba(0,0,0,.22);opacity:0;transform:translateY(-8px);transition:.2s}.sm-toast.show{opacity:1;transform:translateY(0)}.sm-toast-success{background:#168449}.sm-toast-error{background:#c0392b}
        @media(max-width:767px){.sm-head{align-items:flex-start;flex-direction:column}.sm-toolbar{align-items:stretch}.sm-search,.sm-filter{max-width:none;width:100%}}
    </style>
</head>
<body>
<?php include('includes/sidebar.php'); ?>
<main class="app-main">
    <?php include('includes/nav.php'); ?>
    <div class="content-wrap">
        <section class="sm-card">
            <div class="sm-head">
                <div>
                    <h2 class="sm-title">Sidebar Master</h2>
                    <div class="sm-subtitle">Manage sidebar groups, menu links, hierarchy, visibility and ordering.</div>
                </div>
                <?php if ($canCreate): ?>
                    <button class="btn-primary-sm" type="button" id="addMenuBtn"><i class="fa-solid fa-plus me-2"></i>Add Menu Item</button>
                <?php endif; ?>
            </div>

            <div class="sm-toolbar">
                <input class="form-control sm-search" type="search" id="menuSearch" placeholder="Search title, code or route...">
                <select class="form-select sm-filter" id="typeFilter">
                    <option value="">All types</option>
                    <option value="Menu">Menu</option>
                    <option value="Group">Group</option>
                    <option value="Divider">Divider</option>
                </select>
                <select class="form-select sm-filter" id="statusFilter">
                    <option value="">All status</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                    <option value="visible">Visible</option>
                    <option value="hidden">Hidden</option>
                </select>
            </div>

            <?php if (!$canView): ?>
                <div class="empty-state">You do not have permission to view sidebar items.</div>
            <?php elseif (!$items): ?>
                <div class="empty-state"><i class="fa-solid fa-bars-staggered fa-2x mb-2"></i><br>No sidebar items found.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover table-smaster align-middle">
                        <thead>
                        <tr>
                            <th style="width:34px"></th>
                            <th>Menu</th>
                            <th>Code</th>
                            <th>Route</th>
                            <th>Type</th>
                            <th>Scope</th>
                            <th>Order</th>
                            <th>Visible</th>
                            <th>Active</th>
                            <th class="text-end">Actions</th>
                        </tr>
                        </thead>
                        <tbody id="menuTableBody">
                        <?php foreach ($items as $item):
                            $level = min(menuLevel($item, $itemMap), 4);
                            $searchText = strtolower(implode(' ', [
                                $item['menu_title'] ?? '', $item['menu_code'] ?? '', $item['route_url'] ?? '', $item['menu_type'] ?? ''
                            ]));
                        ?>
                        <tr data-id="<?php echo (int)$item['id']; ?>"
                            data-type="<?php echo e($item['menu_type']); ?>"
                            data-active="<?php echo (int)$item['is_active']; ?>"
                            data-visible="<?php echo (int)$item['is_visible']; ?>"
                            data-search="<?php echo e($searchText); ?>">
                            <td class="text-center"><i class="fa-solid fa-grip-vertical drag-handle"></i></td>
                            <td>
                                <div class="menu-name indent-<?php echo $level; ?>">
                                    <span class="menu-icon"><i class="<?php echo e($item['icon_class'] ?: 'fa-regular fa-circle'); ?>"></i></span>
                                    <span><?php echo e($item['menu_title']); ?></span>
                                </div>
                            </td>
                            <td><code><?php echo e($item['menu_code']); ?></code></td>
                            <td><?php echo $item['route_url'] ? e($item['route_url']) : '<span class="text-muted">—</span>'; ?></td>
                            <td><span class="badge-soft badge-<?php echo strtolower(e($item['menu_type'])); ?>"><?php echo e($item['menu_type']); ?></span></td>
                            <td>
                                <?php if ($item['business_id'] === null): ?>
                                    <span class="badge-soft badge-global">Global</span>
                                <?php else: ?>
                                    <span class="badge-soft badge-business"><?php echo e($item['business_name'] ?: 'Business'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo (int)$item['sort_order']; ?></td>
                            <td><span class="badge <?php echo (int)$item['is_visible'] === 1 ? 'text-bg-success' : 'text-bg-secondary'; ?>"><?php echo (int)$item['is_visible'] === 1 ? 'Yes' : 'No'; ?></span></td>
                            <td><span class="badge <?php echo (int)$item['is_active'] === 1 ? 'text-bg-success' : 'text-bg-secondary'; ?>"><?php echo (int)$item['is_active'] === 1 ? 'Active' : 'Inactive'; ?></span></td>
                            <td class="text-end text-nowrap">
                                <?php if ($canUpdate): ?>
                                    <button type="button" class="action-btn edit-menu" title="Edit" data-item='<?php echo e(json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?>'><i class="fa-regular fa-pen-to-square"></i></button>
                                <?php endif; ?>
                                <?php if ($canDelete): ?>
                                    <button type="button" class="action-btn text-danger delete-menu" title="Delete" data-id="<?php echo (int)$item['id']; ?>" data-title="<?php echo e($item['menu_title']); ?>"><i class="fa-regular fa-trash-can"></i></button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <?php include('includes/footer.php'); ?>
    </div>
</main>

<div class="modal fade" id="menuModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <form id="menuForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="menuModalTitle">Add Menu Item</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="action" id="formAction" value="create">
                    <input type="hidden" name="id" id="menuId" value="0">
                    <div class="row g-3">
                        <?php if ($isPlatformAdmin): ?>
                        <div class="col-md-6">
                            <label class="form-label">Scope</label>
                            <select class="form-select" name="business_id" id="business_id">
                                <option value="">Global menu</option>
                                <?php
                                $businesses = $conn->query("SELECT id,business_name FROM businesses ORDER BY business_name");
                                while ($b = $businesses->fetch_assoc()): ?>
                                    <option value="<?php echo (int)$b['id']; ?>"><?php echo e($b['business_name']); ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div class="col-md-6">
                            <label class="form-label">Menu type <span class="text-danger">*</span></label>
                            <select class="form-select" name="menu_type" id="menu_type" required>
                                <option value="Menu">Menu</option>
                                <option value="Group">Group</option>
                                <option value="Divider">Divider</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Menu code <span class="text-danger">*</span></label>
                            <input class="form-control" type="text" name="menu_code" id="menu_code" maxlength="80" required placeholder="e.g. settings.business">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Menu title <span class="text-danger">*</span></label>
                            <input class="form-control" type="text" name="menu_title" id="menu_title" maxlength="120" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Parent menu</label>
                            <select class="form-select" name="parent_id" id="parent_id">
                                <option value="">No parent</option>
                                <?php foreach ($parents as $parent): ?>
                                    <option value="<?php echo (int)$parent['id']; ?>"><?php echo e($parent['menu_title']); ?> (<?php echo e($parent['menu_type']); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Route URL</label>
                            <input class="form-control" type="text" name="route_url" id="route_url" maxlength="255" placeholder="example.php">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Font Awesome icon class</label>
                            <input class="form-control" type="text" name="icon_class" id="icon_class" maxlength="120" placeholder="fa-solid fa-gear">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Sort order</label>
                            <input class="form-control" type="number" name="sort_order" id="sort_order" min="0" max="99999" value="0">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Open link</label>
                            <select class="form-select" name="open_in_new_tab" id="open_in_new_tab">
                                <option value="0">Same tab</option>
                                <option value="1">New tab</option>
                            </select>
                        </div>
                        <div class="col-md-6 d-flex align-items-end gap-4">
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="is_visible" id="is_visible" value="1" checked>
                                <label class="form-check-label" for="is_visible">Visible</label>
                            </div>
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="is_active" id="is_active" value="1" checked>
                                <label class="form-check-label" for="is_active">Active</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-primary-sm" id="saveMenuBtn"><i class="fa-solid fa-floppy-disk me-2"></i>Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="offcanvas-backdrop fade" id="mobileBackdrop" style="display:none"></div>
<?php include('includes/script.php'); ?>
<script src="assets/js/script.js"></script>
<script>
(function(){
    'use strict';
    const modalEl=document.getElementById('menuModal');
    const modal=modalEl?new bootstrap.Modal(modalEl):null;
    const form=document.getElementById('menuForm');
    const saveBtn=document.getElementById('saveMenuBtn');

    function toast(type,message){
        const el=document.createElement('div');
        el.className='sm-toast sm-toast-'+type;
        el.innerHTML='<i class="fa-solid '+(type==='success'?'fa-circle-check':'fa-circle-exclamation')+'"></i><span></span>';
        el.querySelector('span').textContent=message;
        document.body.appendChild(el);
        requestAnimationFrame(()=>el.classList.add('show'));
        setTimeout(()=>{el.classList.remove('show');setTimeout(()=>el.remove(),250)},3200);
    }

    function clearForm(){
        form.reset();
        document.getElementById('formAction').value='create';
        document.getElementById('menuId').value='0';
        document.getElementById('menuModalTitle').textContent='Add Menu Item';
        document.getElementById('is_visible').checked=true;
        document.getElementById('is_active').checked=true;
        document.getElementById('sort_order').value='0';
        document.getElementById('menu_type').value='Menu';
    }

    document.getElementById('addMenuBtn')?.addEventListener('click',()=>{clearForm();modal?.show();});

    document.querySelectorAll('.edit-menu').forEach(btn=>btn.addEventListener('click',()=>{
        clearForm();
        const item=JSON.parse(btn.dataset.item||'{}');
        document.getElementById('formAction').value='update';
        document.getElementById('menuId').value=item.id||0;
        document.getElementById('menuModalTitle').textContent='Edit Menu Item';
        ['menu_code','menu_title','parent_id','route_url','icon_class','menu_type','sort_order','open_in_new_tab','business_id'].forEach(id=>{
            const el=document.getElementById(id); if(el) el.value=item[id]??'';
        });
        document.getElementById('is_visible').checked=Number(item.is_visible)===1;
        document.getElementById('is_active').checked=Number(item.is_active)===1;
        modal?.show();
    }));

    form?.addEventListener('submit',async e=>{
        e.preventDefault();
        const old=saveBtn.innerHTML;
        saveBtn.disabled=true;
        saveBtn.innerHTML='<i class="fa-solid fa-spinner fa-spin me-2"></i>Saving...';
        try{
            const res=await fetch('api/sidebar-master-save.php',{method:'POST',body:new FormData(form),credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}});
            const data=await res.json().catch(()=>({success:false,message:'Invalid server response.'}));
            if(!res.ok||!data.success) throw new Error(data.message||'Unable to save menu item.');
            toast('success',data.message||'Menu item saved successfully.');
            modal?.hide();
            setTimeout(()=>window.location.reload(),600);
        }catch(err){toast('error',err.message||'Unable to save menu item.');}
        finally{saveBtn.disabled=false;saveBtn.innerHTML=old;}
    });

    document.querySelectorAll('.delete-menu').forEach(btn=>btn.addEventListener('click',async()=>{
        if(!confirm('Delete "'+btn.dataset.title+'"? Child items must be removed first.')) return;
        const fd=new FormData();
        fd.append('action','delete');
        fd.append('id',btn.dataset.id);
        fd.append('csrf_token','<?php echo e($csrfToken); ?>');
        try{
            const res=await fetch('api/sidebar-master-save.php',{method:'POST',body:fd,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}});
            const data=await res.json().catch(()=>({success:false,message:'Invalid server response.'}));
            if(!res.ok||!data.success) throw new Error(data.message||'Unable to delete menu item.');
            toast('success',data.message||'Menu item deleted.');
            btn.closest('tr')?.remove();
        }catch(err){toast('error',err.message||'Unable to delete menu item.');}
    }));

    const search=document.getElementById('menuSearch'),type=document.getElementById('typeFilter'),status=document.getElementById('statusFilter');
    function filter(){
        const q=(search?.value||'').toLowerCase().trim(),t=type?.value||'',s=status?.value||'';
        document.querySelectorAll('#menuTableBody tr').forEach(row=>{
            let ok=!q||(row.dataset.search||'').includes(q);
            if(t) ok=ok&&row.dataset.type===t;
            if(s==='active') ok=ok&&row.dataset.active==='1';
            if(s==='inactive') ok=ok&&row.dataset.active==='0';
            if(s==='visible') ok=ok&&row.dataset.visible==='1';
            if(s==='hidden') ok=ok&&row.dataset.visible==='0';
            row.style.display=ok?'':'none';
        });
    }
    [search,type,status].forEach(el=>el?.addEventListener('input',filter));
})();
</script>
</body>
</html>
