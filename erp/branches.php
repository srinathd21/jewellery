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

if (!function_exists('branchPermission')) {
    function branchPermission(mysqli $conn, string $action): bool
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
        if ($field === '') {
            return false;
        }

        $sessionPermissions = $_SESSION['permissions'] ?? [];
        if (isset($sessionPermissions['perm.settings.branches'][$field])) {
            return (int)$sessionPermissions['perm.settings.branches'][$field] === 1;
        }

        $businessId = (int)($_SESSION['business_id'] ?? 0);
        $roleId = (int)($_SESSION['role_id'] ?? 0);
        if ($businessId <= 0 || $roleId <= 0) {
            return false;
        }

        $sql = "SELECT rp.`{$field}`
                FROM role_permissions rp
                INNER JOIN permissions p ON p.id = rp.permission_id
                WHERE rp.business_id = ?
                  AND rp.role_id = ?
                  AND p.permission_code = 'perm.settings.branches'
                  AND p.is_active = 1
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
}

if (!branchPermission($conn, 'open')) {
    http_response_code(403);
    die('Access denied. You do not have permission to open branches.');
}

$canView = branchPermission($conn, 'view') || branchPermission($conn, 'open');
$canCreate = branchPermission($conn, 'create');
$canUpdate = branchPermission($conn, 'update');
$canDelete = branchPermission($conn, 'delete');
$businessId = (int)($_SESSION['business_id'] ?? 0);

if ($businessId <= 0 && ($_SESSION['user_type'] ?? '') !== 'Platform Admin') {
    http_response_code(403);
    die('A valid business must be selected.');
}

if (empty($_SESSION['branches_csrf'])) {
    $_SESSION['branches_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['branches_csrf'];

$branches = [];
if ($canView) {
    $stmt = $conn->prepare("SELECT id, business_id, branch_code, branch_name, branch_type,
                                  contact_person, mobile, email, address_line1, address_line2,
                                  city, district, state, pincode, country, gstin,
                                  is_default, is_active, created_at, updated_at
                           FROM branches
                           WHERE business_id = ?
                           ORDER BY is_default DESC, branch_name ASC");
    $stmt->bind_param('i', $businessId);
    $stmt->execute();
    $branches = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$businessName = (string)($_SESSION['business_name'] ?? 'Jewellery ERP');
$pageTitle = 'Branches';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo e($businessName); ?> - Branches</title>
    <?php include('includes/links.php'); ?>
    <style>
        .branch-panel{background:var(--card,#fff);border:1px solid var(--line,#e8e8e8);border-radius:14px;box-shadow:var(--shadow,0 5px 18px rgba(24,31,40,.08));overflow:hidden}
        .branch-panel-head{padding:13px 15px;border-bottom:1px solid var(--line,#e8e8e8);display:flex;align-items:center;justify-content:space-between;gap:10px}
        .branch-panel-title{font-size:14px;font-weight:700;margin:0}
        .branch-panel-body{padding:14px}
        .branch-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
        .branch-card{border:1px solid var(--line,#e8e8e8);border-radius:12px;padding:13px;background:var(--card,#fff);position:relative}
        .branch-card h3{font-size:13px;margin:0 0 3px;font-weight:700}
        .branch-code{font-size:9px;color:var(--muted,#7d8794);text-transform:uppercase;letter-spacing:.08em}
        .branch-meta{font-size:10px;color:var(--muted,#7d8794);margin-top:8px;line-height:1.55}
        .branch-actions{display:flex;gap:6px;margin-top:12px}
        .badge-soft{display:inline-flex;align-items:center;padding:4px 7px;border-radius:999px;font-size:9px;font-weight:700}
        .badge-default{background:#fff6e5;color:#9a5d00}.badge-active{background:#e9f8ef;color:#167445}.badge-inactive{background:#fdecec;color:#aa2e25}
        .form-label{font-size:10px;font-weight:600;margin-bottom:5px}.form-control,.form-select{font-size:11px;min-height:36px;border-radius:9px}
        .btn-sm-custom{font-size:10px;padding:7px 10px;border-radius:8px}
        .branch-toast{position:fixed;right:18px;top:78px;z-index:20000;display:flex;align-items:center;gap:9px;min-width:260px;max-width:420px;padding:11px 14px;border-radius:10px;color:#fff;font-size:11px;font-weight:600;box-shadow:0 14px 35px rgba(0,0,0,.22);opacity:0;transform:translateY(-10px);transition:.22s}
        .branch-toast.show{opacity:1;transform:translateY(0)}.branch-toast-success{background:#168449}.branch-toast-error{background:#c0392b}
        @media(max-width:1100px){.branch-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:700px){.branch-grid{grid-template-columns:1fr}}
    </style>
</head>
<body>
<?php include('includes/sidebar.php'); ?>
<main class="app-main">
    <?php include('includes/nav.php'); ?>
    <div class="content-wrap">
        <section class="branch-panel">
            <div class="branch-panel-head">
                <div>
                    <h2 class="branch-panel-title">Business Branches</h2>
                    <div class="small text-muted">Manage showrooms, offices, warehouses and head office locations.</div>
                </div>
                <?php if ($canCreate): ?>
                    <button class="btn btn-warning btn-sm-custom" type="button" id="addBranchBtn"><i class="fa-solid fa-plus me-1"></i>Add Branch</button>
                <?php endif; ?>
            </div>
            <div class="branch-panel-body">
                <?php if (!$canView): ?>
                    <div class="alert alert-warning mb-0">You do not have permission to view branches.</div>
                <?php elseif (!$branches): ?>
                    <div class="text-center py-5 text-muted"><i class="fa-solid fa-code-branch fa-2x mb-2"></i><div>No branches found.</div></div>
                <?php else: ?>
                    <div class="branch-grid" id="branchGrid">
                        <?php foreach ($branches as $branch): ?>
                            <article class="branch-card" data-id="<?php echo (int)$branch['id']; ?>">
                                <div class="d-flex justify-content-between gap-2 align-items-start">
                                    <div>
                                        <div class="branch-code"><?php echo e($branch['branch_code']); ?></div>
                                        <h3><?php echo e($branch['branch_name']); ?></h3>
                                    </div>
                                    <div class="d-flex gap-1 flex-wrap justify-content-end">
                                        <?php if ((int)$branch['is_default'] === 1): ?><span class="badge-soft badge-default">Default</span><?php endif; ?>
                                        <span class="badge-soft <?php echo (int)$branch['is_active'] === 1 ? 'badge-active' : 'badge-inactive'; ?>"><?php echo (int)$branch['is_active'] === 1 ? 'Active' : 'Inactive'; ?></span>
                                    </div>
                                </div>
                                <div class="branch-meta">
                                    <div><i class="fa-solid fa-building me-1"></i><?php echo e($branch['branch_type']); ?></div>
                                    <?php if ($branch['contact_person']): ?><div><i class="fa-regular fa-user me-1"></i><?php echo e($branch['contact_person']); ?></div><?php endif; ?>
                                    <?php if ($branch['mobile']): ?><div><i class="fa-solid fa-phone me-1"></i><?php echo e($branch['mobile']); ?></div><?php endif; ?>
                                    <?php if ($branch['email']): ?><div><i class="fa-regular fa-envelope me-1"></i><?php echo e($branch['email']); ?></div><?php endif; ?>
                                    <div><i class="fa-solid fa-location-dot me-1"></i><?php echo e(trim(implode(', ', array_filter([$branch['city'],$branch['district'],$branch['state'],$branch['pincode']])))); ?></div>
                                    <?php if ($branch['gstin']): ?><div><strong>GSTIN:</strong> <?php echo e($branch['gstin']); ?></div><?php endif; ?>
                                </div>
                                <div class="branch-actions">
                                    <?php if ($canUpdate): ?><button type="button" class="btn btn-light btn-sm-custom editBranchBtn" data-branch='<?php echo e(json_encode($branch, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); ?>'><i class="fa-regular fa-pen-to-square me-1"></i>Edit</button><?php endif; ?>
                                    <?php if ($canDelete && (int)$branch['is_default'] !== 1): ?><button type="button" class="btn btn-outline-danger btn-sm-custom deleteBranchBtn" data-id="<?php echo (int)$branch['id']; ?>" data-name="<?php echo e($branch['branch_name']); ?>"><i class="fa-regular fa-trash-can me-1"></i>Delete</button><?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>
        <?php include('includes/footer.php'); ?>
    </div>
</main>

<div class="modal fade" id="branchModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <form id="branchForm">
                <div class="modal-header"><h5 class="modal-title" id="branchModalTitle">Add Branch</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="branch_id" id="branch_id" value="0">
                    <div class="row g-3">
                        <div class="col-md-4"><label class="form-label">Branch Code *</label><input class="form-control" name="branch_code" id="branch_code" maxlength="50" required></div>
                        <div class="col-md-8"><label class="form-label">Branch Name *</label><input class="form-control" name="branch_name" id="branch_name" maxlength="150" required></div>
                        <div class="col-md-4"><label class="form-label">Branch Type *</label><select class="form-select" name="branch_type" id="branch_type" required><option>Head Office</option><option selected>Showroom</option><option>Warehouse</option><option>Office</option><option>Other</option></select></div>
                        <div class="col-md-4"><label class="form-label">Contact Person</label><input class="form-control" name="contact_person" id="contact_person" maxlength="150"></div>
                        <div class="col-md-4"><label class="form-label">Mobile</label><input class="form-control" name="mobile" id="mobile" maxlength="20"></div>
                        <div class="col-md-6"><label class="form-label">Email</label><input class="form-control" type="email" name="email" id="email" maxlength="150"></div>
                        <div class="col-md-6"><label class="form-label">GSTIN</label><input class="form-control text-uppercase" name="gstin" id="gstin" maxlength="30"></div>
                        <div class="col-md-6"><label class="form-label">Address Line 1</label><input class="form-control" name="address_line1" id="address_line1" maxlength="255"></div>
                        <div class="col-md-6"><label class="form-label">Address Line 2</label><input class="form-control" name="address_line2" id="address_line2" maxlength="255"></div>
                        <div class="col-md-4"><label class="form-label">City</label><input class="form-control" name="city" id="city" maxlength="100"></div>
                        <div class="col-md-4"><label class="form-label">District</label><input class="form-control" name="district" id="district" maxlength="100"></div>
                        <div class="col-md-4"><label class="form-label">State</label><input class="form-control" name="state" id="state" maxlength="100" value="Tamil Nadu"></div>
                        <div class="col-md-4"><label class="form-label">Pincode</label><input class="form-control" name="pincode" id="pincode" maxlength="20"></div>
                        <div class="col-md-4"><label class="form-label">Country *</label><input class="form-control" name="country" id="country" maxlength="100" value="India" required></div>
                        <div class="col-md-4 d-flex align-items-end gap-4">
                            <div class="form-check"><input class="form-check-input" type="checkbox" name="is_default" id="is_default" value="1"><label class="form-check-label" for="is_default">Default branch</label></div>
                            <div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" id="is_active" value="1" checked><label class="form-check-label" for="is_active">Active</label></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-warning btn-sm" id="saveBranchBtn"><i class="fa-solid fa-floppy-disk me-1"></i>Save Branch</button></div>
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
    const modalEl=document.getElementById('branchModal');
    const modal=modalEl?new bootstrap.Modal(modalEl):null;
    const form=document.getElementById('branchForm');
    const saveBtn=document.getElementById('saveBranchBtn');
    function toast(type,message){const el=document.createElement('div');el.className='branch-toast branch-toast-'+type;el.innerHTML='<i class="fa-solid '+(type==='success'?'fa-circle-check':'fa-circle-exclamation')+'"></i><span></span>';el.querySelector('span').textContent=message;document.body.appendChild(el);requestAnimationFrame(()=>el.classList.add('show'));setTimeout(()=>{el.classList.remove('show');setTimeout(()=>el.remove(),250)},3200)}
    function resetForm(){form.reset();document.getElementById('branch_id').value='0';document.getElementById('branch_type').value='Showroom';document.getElementById('state').value='Tamil Nadu';document.getElementById('country').value='India';document.getElementById('is_active').checked=true;document.getElementById('branchModalTitle').textContent='Add Branch'}
    document.getElementById('addBranchBtn')?.addEventListener('click',()=>{resetForm();modal.show()});
    document.querySelectorAll('.editBranchBtn').forEach(btn=>btn.addEventListener('click',()=>{resetForm();const b=JSON.parse(btn.dataset.branch);Object.keys(b).forEach(k=>{const el=document.getElementById(k);if(!el)return;if(el.type==='checkbox')el.checked=Number(b[k])===1;else el.value=b[k]??''});document.getElementById('branch_id').value=b.id;document.getElementById('branchModalTitle').textContent='Edit Branch';modal.show()}));
    form?.addEventListener('submit',async e=>{e.preventDefault();const old=saveBtn.innerHTML;saveBtn.disabled=true;saveBtn.innerHTML='<i class="fa-solid fa-spinner fa-spin me-1"></i>Saving...';try{const r=await fetch('api/branches-save.php',{method:'POST',body:new FormData(form),credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}});const j=await r.json().catch(()=>({success:false,message:'Invalid server response.'}));if(!r.ok||!j.success)throw new Error(j.message||'Unable to save branch.');toast('success',j.message||'Branch saved successfully.');setTimeout(()=>window.location.reload(),650)}catch(err){toast('error',err.message||'Unable to save branch.')}finally{saveBtn.disabled=false;saveBtn.innerHTML=old}});
    document.querySelectorAll('.deleteBranchBtn').forEach(btn=>btn.addEventListener('click',async()=>{if(!confirm('Delete '+btn.dataset.name+'?'))return;const fd=new FormData();fd.append('csrf_token','<?php echo e($csrfToken); ?>');fd.append('action','delete');fd.append('branch_id',btn.dataset.id);try{const r=await fetch('api/branches-save.php',{method:'POST',body:fd,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}});const j=await r.json().catch(()=>({success:false,message:'Invalid server response.'}));if(!r.ok||!j.success)throw new Error(j.message||'Unable to delete branch.');btn.closest('.branch-card')?.remove();toast('success',j.message||'Branch deleted successfully.')}catch(err){toast('error',err.message||'Unable to delete branch.')}}));
})();
</script>
</body>
</html>
