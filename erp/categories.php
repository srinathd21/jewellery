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

if (!function_exists('categoryPermission')) {
    function categoryPermission(mysqli $conn, string $action): bool
    {
        if (($_SESSION['user_type'] ?? '') === 'Platform Admin') {
            return true;
        }

        $fieldMap = [
            'open' => 'can_open',
            'view' => 'can_view',
            'value' => 'can_view_value',
            'create' => 'can_create',
            'update' => 'can_update',
            'approve' => 'can_approve',
            'delete' => 'can_delete',
        ];

        $field = $fieldMap[$action] ?? '';
        if ($field === '') {
            return false;
        }

        $sessionPermissions = $_SESSION['permissions'] ?? [];
        foreach (['perm.products.categories', 'perm.products'] as $key) {
            if (isset($sessionPermissions[$key][$field])) {
                return (int)$sessionPermissions[$key][$field] === 1;
            }
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
                  AND p.is_active = 1
                  AND p.permission_code IN ('perm.products.categories','perm.products')
                ORDER BY FIELD(p.permission_code,'perm.products.categories','perm.products')
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

if (!categoryPermission($conn, 'open')) {
    http_response_code(403);
    die('Access denied. You do not have permission to open categories.');
}

$canView = categoryPermission($conn, 'view') || categoryPermission($conn, 'open');
$canCreate = categoryPermission($conn, 'create');
$canUpdate = categoryPermission($conn, 'update');
$canDelete = categoryPermission($conn, 'delete');
$businessId = (int)($_SESSION['business_id'] ?? 0);

if ($businessId <= 0) {
    http_response_code(403);
    die('A valid business must be selected before managing categories.');
}

if (empty($_SESSION['categories_csrf'])) {
    $_SESSION['categories_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['categories_csrf'];

$categories = [];
$sql = "SELECT c.id, c.parent_id, c.category_code, c.category_name, c.description, c.sort_order, c.is_active,
               p.category_name AS parent_name,
               COUNT(pr.id) AS product_count
        FROM product_categories c
        LEFT JOIN product_categories p
               ON p.id = c.parent_id
              AND p.business_id = c.business_id
        LEFT JOIN products pr
               ON pr.category_id = c.id
              AND pr.business_id = c.business_id
        WHERE c.business_id = ?
        GROUP BY c.id, c.parent_id, c.category_code, c.category_name, c.description, c.sort_order, c.is_active, p.category_name
        ORDER BY c.sort_order ASC, c.category_name ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $businessId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $categories[] = $row;
}
$stmt->close();

$nextSortOrder = 1;
if ($categories) {
    $maxSortOrder = max(array_map(static function (array $category): int {
        return (int)($category['sort_order'] ?? 0);
    }, $categories));
    $nextSortOrder = $maxSortOrder + 1;
}

$stats = ['total' => count($categories), 'active' => 0, 'inactive' => 0, 'products' => 0];
foreach ($categories as $category) {
    if ((int)$category['is_active'] === 1) {
        $stats['active']++;
    } else {
        $stats['inactive']++;
    }
    $stats['products'] += (int)$category['product_count'];
}

$parentCategories = array_values(array_filter($categories, static function (array $category): bool {
    return (int)$category['is_active'] === 1;
}));

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

$themeStmt = $conn->prepare('SELECT * FROM business_theme_settings WHERE business_id = ? LIMIT 1');
if ($themeStmt) {
    $themeStmt->bind_param('i', $businessId);
    $themeStmt->execute();
    $themeRow = $themeStmt->get_result()->fetch_assoc() ?: [];
    $themeStmt->close();
    foreach ($theme as $key => $defaultValue) {
        if (isset($themeRow[$key]) && $themeRow[$key] !== '') {
            $theme[$key] = $themeRow[$key];
        }
    }
}

$pageTitle = 'Categories';
$businessName = (string)($_SESSION['business_name'] ?? 'Jewellery ERP');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo e($businessName); ?> - Categories</title>
    <?php include('includes/links.php'); ?>
    <style>
        :root{
            --primary:<?php echo e($theme['primary_color']); ?>;
            --primary-dark:<?php echo e($theme['primary_dark_color']); ?>;
            --primary-soft:<?php echo e($theme['primary_soft_color']); ?>;
            --sidebar-gradient-1:<?php echo e($theme['sidebar_gradient_1']); ?>;
            --sidebar-gradient-2:<?php echo e($theme['sidebar_gradient_2']); ?>;
            --sidebar-gradient-3:<?php echo e($theme['sidebar_gradient_3']); ?>;
            --page-bg:<?php echo e($theme['page_background']); ?>;
            --card-bg:<?php echo e($theme['card_background']); ?>;
            --text-color:<?php echo e($theme['text_color']); ?>;
            --muted-color:<?php echo e($theme['muted_text_color']); ?>;
            --border-color:<?php echo e($theme['border_color']); ?>;
            --sidebar-width:<?php echo (int)$theme['sidebar_width_px']; ?>px;
            --radius:<?php echo (int)$theme['border_radius_px']; ?>px;
        }
        body{background:var(--page-bg);color:var(--text-color);font-family:<?php echo json_encode((string)$theme['font_family']); ?>,sans-serif;}
        .sidebar{background:linear-gradient(180deg,var(--sidebar-gradient-1),var(--sidebar-gradient-2),var(--sidebar-gradient-3))!important;}
        .stat-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin-bottom:10px;}
        .stat-card{background:var(--card-bg);border:1px solid var(--border-color);border-radius:var(--radius);padding:12px 14px;min-height:82px;display:flex;align-items:center;gap:12px;}
        .stat-icon{width:42px;height:42px;flex:0 0 42px;display:flex;align-items:center;justify-content:center;border-radius:calc(var(--radius)*.75);background:var(--primary-soft);color:var(--primary-dark);font-size:16px;}
        .stat-label{font-size:10px;color:var(--muted-color);}
        .stat-value{font-size:22px;line-height:1.1;font-weight:800;margin-top:4px;}
        .categories-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;background:var(--card-bg);border:1px solid var(--border-color);border-radius:var(--radius);padding:10px 12px;margin-bottom:10px;}
        .categories-toolbar-left{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
        .categories-search{position:relative;min-width:280px;}
        .categories-search i{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--muted-color);font-size:11px;}
        .categories-search input{padding-left:32px;}
        .form-control,.form-select{font-size:11px;min-height:36px;border-radius:9px;border-color:var(--border-color);background:var(--card-bg);color:var(--text-color);}
        .btn-theme{background:linear-gradient(135deg,var(--primary),var(--primary-dark));border:0;color:#fff;border-radius:calc(var(--radius)*.65);font-size:11px;font-weight:700;padding:9px 14px;}
        .categories-card{background:var(--card-bg);border:1px solid var(--border-color);border-radius:var(--radius);overflow:hidden;}
        .categories-table{margin:0;font-size:11px;}
        .categories-table th{font-size:10px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted-color);background:color-mix(in srgb,var(--muted-color) 6%,transparent);white-space:nowrap;padding:10px 12px;border-color:var(--border-color);}
        .categories-table td{padding:10px 12px;vertical-align:middle;color:var(--text-color);background:var(--card-bg)!important;border-color:var(--border-color);}
        .category-name{font-size:11px;font-weight:800;}
        .category-sub{font-size:9px;color:var(--muted-color);margin-top:2px;}
        .code-badge,.parent-badge,.status-badge{display:inline-flex;align-items:center;border-radius:999px;padding:4px 8px;font-size:9px;font-weight:700;}
        .code-badge{background:var(--primary-soft);color:var(--primary-dark);}
        .parent-badge{background:#eff6ff;color:#1d4ed8;}
        .status-active{background:#eaf8f0;color:#168449;}
        .status-inactive{background:#fdecec;color:#bd2d2d;}
        .action-btn{width:30px;height:30px;border:1px solid var(--border-color);border-radius:8px;background:var(--card-bg);display:inline-flex;align-items:center;justify-content:center;font-size:10px;color:var(--text-color);}
        .action-btn:hover{background:var(--primary-soft);color:var(--primary-dark);}
        .action-btn.danger:hover{background:#fdecec;color:#bd2d2d;}
        .action-btn:disabled{opacity:.45;cursor:not-allowed;}
        .modal-content{background:var(--card-bg);color:var(--text-color);border:0;border-radius:var(--radius);overflow:hidden;}
        .modal-header,.modal-footer{border-color:var(--border-color);}
        .modal-title{font-family:<?php echo json_encode((string)$theme['heading_font_family']); ?>,serif;font-size:15px;font-weight:800;}
        .field-label{display:block;font-size:10px;font-weight:700;margin-bottom:5px;}
        .theme-toast{position:fixed;right:18px;top:78px;z-index:20000;display:flex;align-items:center;gap:9px;min-width:260px;max-width:420px;padding:11px 14px;border-radius:10px;color:#fff;font-size:11px;font-weight:600;box-shadow:0 14px 35px rgba(0,0,0,.22);opacity:0;transform:translateY(-10px);transition:.22s;}
        .theme-toast.show{opacity:1;transform:translateY(0);}
        .theme-toast-success{background:#168449;}.theme-toast-error{background:#c0392b;}
        .empty-state{padding:50px 20px;text-align:center;color:var(--muted-color);}.empty-state i{font-size:34px;margin-bottom:10px;}
        body.dark-mode,body[data-theme="dark"],html.dark-mode body,html[data-theme="dark"] body{--page-bg:#0f151b;--card-bg:#182129;--text-color:#f3f6f8;--muted-color:#9aa7b3;--border-color:#2c3944;}
        @media(max-width:991.98px){
            .stat-grid{grid-template-columns:repeat(2,minmax(0,1fr));}
            .categories-toolbar{align-items:stretch;flex-direction:column;}
            .categories-toolbar-left{display:grid;grid-template-columns:minmax(0,1fr) 150px;}
            .categories-search{min-width:0;width:100%;}
            #addCategoryButton{width:100%;}
            .categories-card{background:transparent;border:0;overflow:visible;}
            .table-responsive{overflow:visible;}
            .categories-table{display:block;background:transparent;}
            .categories-table thead{display:none;}
            .categories-table tbody{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;}
            .categories-table tbody tr{display:grid;grid-template-columns:1fr 1fr;background:var(--card-bg);border:1px solid var(--border-color);border-radius:var(--radius);padding:14px;}
            .categories-table tbody td{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;min-width:0;padding:9px 0;border:0;border-bottom:1px dashed var(--border-color);text-align:right!important;}
            .categories-table tbody td::before{content:attr(data-label);font-size:9px;font-weight:700;text-transform:uppercase;color:var(--muted-color);text-align:left;}
            .categories-table tbody td.category-column{grid-column:1/-1;display:block;padding:0 0 12px;text-align:left!important;}
            .categories-table tbody td.category-column::before{display:none;}
            .categories-table tbody td.actions-column{grid-column:1/-1;border-bottom:0;padding:12px 0 0;align-items:center;}
        }
        @media(max-width:767.98px){
            .content-wrap{padding-left:10px;padding-right:10px;}
            .categories-toolbar-left{grid-template-columns:1fr;}
            .categories-table tbody{grid-template-columns:1fr;}
            .categories-table tbody tr{grid-template-columns:1fr;}
            .categories-table tbody td{grid-column:1/-1;}
            .theme-toast{left:12px;right:12px;top:70px;min-width:0;max-width:none;}
            .modal-dialog{margin:8px;}
        }
    </style>
</head>
<body>
<?php include('includes/sidebar.php'); ?>
<main class="app-main">
    <?php include('includes/nav.php'); ?>
    <div class="content-wrap">
        <?php if (!$canView): ?>
            <div class="categories-card"><div class="empty-state"><i class="fa-solid fa-lock"></i><div>You do not have permission to view categories.</div></div></div>
        <?php else: ?>
            <div class="stat-grid">
                <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-tags"></i></div><div><div class="stat-label">Total Categories</div><div class="stat-value"><?php echo $stats['total']; ?></div></div></div>
                <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-circle-check"></i></div><div><div class="stat-label">Active Categories</div><div class="stat-value"><?php echo $stats['active']; ?></div></div></div>
                <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-circle-xmark"></i></div><div><div class="stat-label">Inactive Categories</div><div class="stat-value"><?php echo $stats['inactive']; ?></div></div></div>
                <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-box"></i></div><div><div class="stat-label">Linked Products</div><div class="stat-value"><?php echo $stats['products']; ?></div></div></div>
            </div>

            <div class="categories-toolbar">
                <div class="categories-toolbar-left">
                    <div class="categories-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" class="form-control" id="categorySearch" placeholder="Search name, code, description..."></div>
                    <select class="form-select" id="statusFilter" style="width:150px">
                        <option value="">All status</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                <?php if ($canCreate): ?><button type="button" class="btn btn-theme btn-sm" id="addCategoryButton"><i class="fa-solid fa-plus me-2"></i>Add Category</button><?php endif; ?>
            </div>

            <div class="categories-card">
                <div class="table-responsive">
                    <table class="table categories-table align-middle" id="categoriesTable">
                        <thead><tr><th>Category</th><th>Code</th><th>Parent</th><th>Products</th><th>Sort Order</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                        <tbody>
                        <?php foreach ($categories as $category): ?>
                            <tr data-search="<?php echo e(strtolower($category['category_name'] . ' ' . ($category['category_code'] ?? '') . ' ' . ($category['description'] ?? '') . ' ' . ($category['parent_name'] ?? ''))); ?>" data-status="<?php echo (int)$category['is_active'] === 1 ? 'active' : 'inactive'; ?>">
                                <td class="category-column" data-label="Category"><div class="category-name"><?php echo e($category['category_name']); ?></div><?php if (!empty($category['description'])): ?><div class="category-sub"><?php echo e($category['description']); ?></div><?php else: ?><div class="category-sub">ID: <?php echo (int)$category['id']; ?></div><?php endif; ?></td>
                                <td data-label="Code"><span class="code-badge"><?php echo e(!empty($category['category_code']) ? $category['category_code'] : '—'); ?></span></td>
                                <td data-label="Parent"><?php if ($category['parent_name']): ?><span class="parent-badge"><?php echo e($category['parent_name']); ?></span><?php else: ?><span class="category-sub">Main Category</span><?php endif; ?></td>
                                <td data-label="Products"><?php echo (int)$category['product_count']; ?></td>
                                <td data-label="Sort Order"><?php echo (int)$category['sort_order']; ?></td>
                                <td data-label="Status"><span class="status-badge <?php echo (int)$category['is_active'] === 1 ? 'status-active' : 'status-inactive'; ?>"><?php echo (int)$category['is_active'] === 1 ? 'Active' : 'Inactive'; ?></span></td>
                                <td class="text-end actions-column" data-label="Actions"><div class="d-inline-flex gap-1">
                                    <?php if ($canUpdate): ?>
                                        <button class="action-btn edit-category" type="button" title="Edit" data-id="<?php echo (int)$category['id']; ?>"><i class="fa-solid fa-pen"></i></button>
                                        <button class="action-btn toggle-category" type="button" title="<?php echo (int)$category['is_active'] === 1 ? 'Deactivate' : 'Activate'; ?>" data-id="<?php echo (int)$category['id']; ?>" data-active="<?php echo (int)$category['is_active']; ?>"><i class="fa-solid <?php echo (int)$category['is_active'] === 1 ? 'fa-ban' : 'fa-circle-check'; ?>"></i></button>
                                    <?php endif; ?>
                                    <?php if ($canDelete): ?>
                                        <button class="action-btn danger delete-category" type="button" title="Delete" data-id="<?php echo (int)$category['id']; ?>" data-name="<?php echo e($category['category_name']); ?>" <?php echo (int)$category['product_count'] > 0 ? 'disabled' : ''; ?>><i class="fa-solid fa-trash"></i></button>
                                    <?php endif; ?>
                                </div></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (!$categories): ?><div class="empty-state"><i class="fa-regular fa-folder-open"></i><div>No categories found.</div></div><?php endif; ?>
            </div>
        <?php endif; ?>
        <?php include('includes/footer.php'); ?>
    </div>
</main>

<div class="modal fade" id="categoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form class="modal-content" id="categoryForm">
            <div class="modal-header"><h5 class="modal-title" id="categoryModalTitle">Add Category</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="category_id" id="category_id" value="0">
                <div class="row g-3">
                    <div class="col-md-6"><label class="field-label">Category name <span class="text-danger">*</span></label><input class="form-control" type="text" name="category_name" id="category_name" maxlength="120" required></div>
                    <div class="col-md-6"><label class="field-label">Category code <span class="text-muted">(Optional)</span></label><input class="form-control" type="text" name="category_code" id="category_code" maxlength="50" placeholder="Example: RING"></div>
                    <div class="col-12"><label class="field-label">Description</label><textarea class="form-control" name="description" id="description" rows="3" maxlength="1000" placeholder="Optional category description"></textarea></div>
                    <div class="col-md-6"><label class="field-label">Parent category</label><select class="form-select" name="parent_id" id="parent_id"><option value="0">Main Category</option><?php foreach ($parentCategories as $parent): ?><option value="<?php echo (int)$parent['id']; ?>"><?php echo e($parent['category_name']); ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-3"><label class="field-label">Sort order</label><input class="form-control" type="number" name="sort_order" id="sort_order" min="0" max="999999" value="<?php echo (int)$nextSortOrder; ?>"></div>
                    <div class="col-md-3"><label class="field-label">Status</label><select class="form-select" name="is_active" id="is_active"><option value="1">Active</option><option value="0">Inactive</option></select></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-theme btn-sm" id="saveCategoryButton"><i class="fa-solid fa-floppy-disk me-2"></i>Save Category</button></div>
        </form>
    </div>
</div>

<div class="modal fade" id="confirmActionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="confirmActionTitle">Confirm Action</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex align-items-start gap-3">
                    <div class="stat-icon" style="width:38px;height:38px;flex-basis:38px;" id="confirmActionIcon">
                        <i class="fa-solid fa-circle-exclamation"></i>
                    </div>
                    <div>
                        <div class="fw-bold mb-1" id="confirmActionMessage">Are you sure?</div>
                        <div class="category-sub" id="confirmActionNote"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-theme btn-sm" id="confirmActionButton">Confirm</button>
            </div>
        </div>
    </div>
</div>

<?php include('includes/script.php'); ?>
<script src="assets/js/script.js"></script>
<script>
(function(){
    'use strict';
    const csrfToken = <?php echo json_encode($csrfToken); ?>;
    const modalElement = document.getElementById('categoryModal');
    const categoryModal = modalElement ? new bootstrap.Modal(modalElement) : null;
    const categoryForm = document.getElementById('categoryForm');
    const confirmModalElement = document.getElementById('confirmActionModal');
    const confirmActionModal = confirmModalElement ? new bootstrap.Modal(confirmModalElement) : null;

    function modalConfirm(options){
        return new Promise(function(resolve){
            const title=document.getElementById('confirmActionTitle');
            const message=document.getElementById('confirmActionMessage');
            const note=document.getElementById('confirmActionNote');
            const button=document.getElementById('confirmActionButton');
            const icon=document.getElementById('confirmActionIcon');
            let settled=false;
            title.textContent=options.title||'Confirm Action';
            message.textContent=options.message||'Are you sure?';
            note.textContent=options.note||'';
            button.textContent=options.confirmText||'Confirm';
            button.className='btn btn-sm '+(options.danger?'btn-danger':'btn-theme');
            icon.style.background=options.danger?'#fdecec':'var(--primary-soft)';
            icon.style.color=options.danger?'#bd2d2d':'var(--primary-dark)';
            const finish=function(value){if(settled)return;settled=true;button.removeEventListener('click',onConfirm);confirmModalElement.removeEventListener('hidden.bs.modal',onHidden);resolve(value);};
            const onConfirm=function(){finish(true);confirmActionModal.hide();};
            const onHidden=function(){finish(false);};
            button.addEventListener('click',onConfirm);
            confirmModalElement.addEventListener('hidden.bs.modal',onHidden,{once:true});
            confirmActionModal.show();
        });
    }

    function showToast(type,message){const t=document.createElement('div');t.className='theme-toast theme-toast-'+type;t.innerHTML='<i class="fa-solid '+(type==='success'?'fa-circle-check':'fa-circle-exclamation')+'"></i><span></span>';t.querySelector('span').textContent=message;document.body.appendChild(t);requestAnimationFrame(()=>t.classList.add('show'));setTimeout(()=>{t.classList.remove('show');setTimeout(()=>t.remove(),250)},3200)}
    async function api(formData){const response=await fetch('api/categories-save.php',{method:'POST',body:formData,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}});const result=await response.json().catch(()=>({success:false,message:'Invalid response received from the server.'}));if(!response.ok||!result.success)throw new Error(result.message||'Request failed.');return result}
    const nextSortOrder = <?php echo (int)$nextSortOrder; ?>;
    function resetForm(){categoryForm.reset();document.getElementById('category_id').value='0';document.getElementById('categoryModalTitle').textContent='Add Category';document.getElementById('sort_order').value=String(nextSortOrder);document.getElementById('is_active').value='1';document.getElementById('parent_id').value='0';}

    const search=document.getElementById('categorySearch');
    const status=document.getElementById('statusFilter');
    function filterRows(){const q=(search?search.value:'').trim().toLowerCase();const s=status?status.value:'';document.querySelectorAll('#categoriesTable tbody tr').forEach(row=>{row.style.display=(!q||row.dataset.search.includes(q))&&(!s||row.dataset.status===s)?'':'none';});}
    [search,status].forEach(el=>{if(el)el.addEventListener('input',filterRows)});

    const addButton=document.getElementById('addCategoryButton');
    if(addButton)addButton.addEventListener('click',()=>{resetForm();categoryModal.show();});

    document.addEventListener('click',async function(event){
        const edit=event.target.closest('.edit-category');
        if(edit){try{const fd=new FormData();fd.append('action','get');fd.append('csrf_token',csrfToken);fd.append('category_id',edit.dataset.id);const result=await api(fd);resetForm();const c=result.category;document.getElementById('categoryModalTitle').textContent='Edit Category';document.getElementById('category_id').value=c.id;document.getElementById('category_name').value=c.category_name||'';document.getElementById('category_code').value=c.category_code||'';document.getElementById('description').value=c.description||'';document.getElementById('parent_id').value=String(c.parent_id||0);document.getElementById('sort_order').value=String(c.sort_order||0);document.getElementById('is_active').value=String(c.is_active);const own=document.querySelector('#parent_id option[value="'+c.id+'"]');if(own)own.disabled=true;categoryModal.show();}catch(err){showToast('error',err.message)}}

        const toggle=event.target.closest('.toggle-category');
        if(toggle){
            const next=Number(toggle.dataset.active)===1?0:1;
            const accepted=await modalConfirm({
                title:(next===1?'Activate':'Deactivate')+' Category',
                message:'Do you want to '+(next===1?'activate':'deactivate')+' this category?',
                note:next===1?'The category will become available for use.':'The category will remain in reports but cannot be selected for new products.',
                confirmText:next===1?'Activate':'Deactivate',
                danger:next===0
            });
            if(!accepted)return;
            const fd=new FormData();fd.append('action','toggle');fd.append('csrf_token',csrfToken);fd.append('category_id',toggle.dataset.id);fd.append('is_active',String(next));
            try{const result=await api(fd);showToast('success',result.message);setTimeout(()=>location.reload(),500)}catch(err){showToast('error',err.message)}
        }

        const del=event.target.closest('.delete-category');
        if(del&&!del.disabled){
            const accepted=await modalConfirm({
                title:'Delete Category',
                message:'Delete '+del.dataset.name+'?',
                note:'This action cannot be undone.',
                confirmText:'Delete',
                danger:true
            });
            if(!accepted)return;
            const fd=new FormData();fd.append('action','delete');fd.append('csrf_token',csrfToken);fd.append('category_id',del.dataset.id);
            try{const result=await api(fd);showToast('success',result.message);del.closest('tr').remove()}catch(err){showToast('error',err.message)}
        }
    });

    modalElement.addEventListener('hidden.bs.modal',()=>{document.querySelectorAll('#parent_id option').forEach(option=>option.disabled=false);});

    if(categoryForm)categoryForm.addEventListener('submit',async function(event){event.preventDefault();const btn=document.getElementById('saveCategoryButton');const old=btn.innerHTML;btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin me-2"></i>Saving...';try{const result=await api(new FormData(categoryForm));showToast('success',result.message);categoryModal.hide();setTimeout(()=>location.reload(),500)}catch(err){showToast('error',err.message)}finally{btn.disabled=false;btn.innerHTML=old}});
})();
</script>
</body>
</html>
