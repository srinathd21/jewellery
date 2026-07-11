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
                ORDER BY COALESCE(mi.business_id,0), COALESCE(mi.parent_id,0), mi.sort_order, mi.id";
        $result = $conn->query($sql);
    } else {
        $stmt = $conn->prepare("SELECT mi.*, NULL AS business_name
                                FROM menu_items mi
                                WHERE mi.business_id IS NULL OR mi.business_id = ?
                                ORDER BY CASE WHEN mi.business_id IS NULL THEN 0 ELSE 1 END,
                                         COALESCE(mi.parent_id,0), mi.sort_order, mi.id");
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

// Display each main menu first, followed only by its own child menus.
$childrenByParent = [];
foreach ($items as $item) {
    $parentKey = (int)($item['parent_id'] ?? 0);
    $childrenByParent[$parentKey][] = $item;
}
foreach ($childrenByParent as &$groupItems) {
    usort($groupItems, function (array $a, array $b): int {
        $scopeA = $a['business_id'] === null ? 0 : (int)$a['business_id'];
        $scopeB = $b['business_id'] === null ? 0 : (int)$b['business_id'];
        if ($scopeA !== $scopeB) return $scopeA <=> $scopeB;
        $orderCompare = (int)$a['sort_order'] <=> (int)$b['sort_order'];
        return $orderCompare !== 0 ? $orderCompare : ((int)$a['id'] <=> (int)$b['id']);
    });
}
unset($groupItems);

$orderedItems = [];
$visitedItems = [];
$appendMenuTree = function (int $parentId) use (&$appendMenuTree, &$childrenByParent, &$orderedItems, &$visitedItems): void {
    foreach ($childrenByParent[$parentId] ?? [] as $child) {
        $childId = (int)$child['id'];
        if (isset($visitedItems[$childId])) continue;
        $visitedItems[$childId] = true;
        $orderedItems[] = $child;
        $appendMenuTree($childId);
    }
};
$appendMenuTree(0);
// Keep orphaned records visible after the proper hierarchy.
foreach ($items as $item) {
    if (!isset($visitedItems[(int)$item['id']])) $orderedItems[] = $item;
}
$items = $orderedItems;

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

$businessesList = [];
if ($isPlatformAdmin) {
    $businessResult = $conn->query("SELECT id,business_name FROM businesses WHERE status <> 'Closed' ORDER BY business_name");
    while ($businessResult && $businessRow = $businessResult->fetch_assoc()) $businessesList[] = $businessRow;
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
        .scope-business-wrap{transition:.18s}.scope-note{font-size:9px;color:var(--muted,#7d8794);margin-top:4px}.badge-soft{display:inline-flex;align-items:center;padding:3px 7px;border-radius:999px;font-size:9px;font-weight:700}.badge-menu{background:#eef4ff;color:#315b9f}.badge-group{background:#f4edff;color:#6e3fa5}.badge-divider{background:#f1f1f1;color:#666}.badge-global{background:#edf8f1;color:#25734a}.badge-business{background:#fff6e5;color:#9a6400}
        .action-btn{width:30px;height:30px;border:1px solid var(--line,#e8e8e8);border-radius:8px;background:var(--card,#fff);display:inline-flex;align-items:center;justify-content:center;font-size:11px;color:var(--text,#171717)}.action-btn:hover{background:rgba(127,127,127,.06)}
        .drag-handle{cursor:grab;color:var(--muted,#7d8794)}
        .reorder-list{display:flex;flex-direction:column;gap:7px}.reorder-item{display:flex;align-items:center;gap:10px;padding:9px 10px;border:1px solid var(--line,#e8e8e8);border-radius:9px;background:var(--card,#fff)}.reorder-item .drag-handle{font-size:13px}.reorder-item-title{font-size:11px;font-weight:700;flex:1}.reorder-item-order{width:64px;text-align:center;font-size:11px}.reorder-parent{font-size:10px;font-weight:800;margin:12px 0 6px;color:var(--muted,#7d8794);text-transform:uppercase;letter-spacing:.04em}
        .empty-state{padding:44px 16px;text-align:center;color:var(--muted,#7d8794);font-size:11px}
        .form-label{font-size:10px;font-weight:600}.form-control,.form-select{font-size:11px;min-height:36px;border-radius:8px}.form-check-label{font-size:11px}
        .sm-toast{position:fixed;right:18px;top:78px;z-index:20000;display:flex;align-items:center;gap:8px;min-width:260px;max-width:420px;padding:11px 14px;border-radius:10px;color:#fff;font-size:11px;font-weight:600;box-shadow:0 14px 35px rgba(0,0,0,.22);opacity:0;transform:translateY(-8px);transition:.2s}.sm-toast.show{opacity:1;transform:translateY(0)}.sm-toast-success{background:#168449}.sm-toast-error{background:#c0392b}
        #menuModal .modal-dialog{max-width:1040px;margin:18px auto;align-items:flex-start;min-height:0}
        #menuModal .modal-content{max-height:calc(100vh - 36px);overflow:hidden}
        #menuModal .modal-header{flex:0 0 auto;padding:14px 18px}
        #menuModal .modal-body{overflow-y:auto;padding:16px 18px 22px;min-height:0}
        #menuModal .modal-footer{flex:0 0 auto;position:sticky;bottom:0;z-index:3;background:var(--card,#fff);border-top:1px solid var(--line,#e8e8e8);padding:12px 18px}
        #menuModal .row.g-3{--bs-gutter-y:12px}
        #menuModal .scope-note,#menuModal .sm-subtitle{line-height:1.35}
        @media(max-width:767px){.sm-head{align-items:flex-start;flex-direction:column}.sm-toolbar{align-items:stretch}.sm-search,.sm-filter{max-width:none;width:100%}#menuModal .modal-dialog{margin:8px;max-width:none}#menuModal .modal-content{max-height:calc(100vh - 16px)}#menuModal .modal-body{padding:14px}#menuModal .modal-footer{padding:10px 14px}}
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
                <div class="d-flex gap-2 flex-wrap">
                    <?php if ($canUpdate): ?>
                        <button class="action-btn px-3" style="width:auto" type="button" id="bulkReorderBtn"><i class="fa-solid fa-arrow-down-1-9 me-2"></i>Bulk Reorder</button>
                    <?php endif; ?>
                    <?php if ($canCreate): ?>
                        <button class="btn-primary-sm" type="button" id="addMenuBtn"><i class="fa-solid fa-plus me-2"></i>Add Menu Item</button>
                    <?php endif; ?>
                </div>
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
                            data-search="<?php echo e($searchText); ?>"
                            data-parent="<?php echo (int)($item['parent_id'] ?? 0); ?>"
                            data-order="<?php echo (int)$item['sort_order']; ?>">
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
                                    <button type="button" class="action-btn text-danger delete-menu" title="Delete" data-id="<?php echo (int)$item['id']; ?>" data-title="<?php echo e($item['menu_title']); ?>" data-route="<?php echo e($item['route_url'] ?? ''); ?>"><i class="fa-regular fa-trash-can"></i></button>
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
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <form id="menuForm" class="d-flex flex-column h-100">
                <div class="modal-header">
                    <h5 class="modal-title" id="menuModalTitle">Add Menu Item</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="action" id="formAction" value="create">
                    <input type="hidden" name="id" id="menuId" value="0">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Menu scope <span class="text-danger">*</span></label>
                            <select class="form-select" name="scope_type" id="scope_type" required>
                                <option value="global">Global</option>
                                <option value="business">Business</option>
                            </select>
                            <div class="scope-note">Choose Global to display this menu for every business, or Business to display it only for the selected business.</div>
                        </div>
                        <div class="col-md-6 scope-business-wrap" id="businessScopeWrap">
                            <label class="form-label">Business <span class="text-danger">*</span></label>
                            <?php if ($isPlatformAdmin): ?>
                                <select class="form-select" name="business_id" id="business_id">
                                    <option value="">Select business</option>
                                    <?php foreach ($businessesList as $b): ?>
                                        <option value="<?php echo (int)$b['id']; ?>"><?php echo e($b['business_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <input type="hidden" name="business_id" id="business_id" value="<?php echo (int)$businessId; ?>">
                                <input class="form-control" value="<?php echo e($businessName); ?>" disabled>
                            <?php endif; ?>
                        </div>
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
                                    <option value="<?php echo (int)$parent['id']; ?>" data-business="<?php echo $parent['business_id'] === null ? 'global' : (int)$parent['business_id']; ?>"><?php echo e($parent['menu_title']); ?> (<?php echo e($parent['menu_type']); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Route URL</label>
                            <input class="form-control" type="text" name="route_url" id="route_url" maxlength="255" placeholder="example.php"><div class="sm-subtitle mt-1">For a new Menu, a PHP file is created automatically. Leave blank to generate it from the menu code.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Font Awesome icon class</label>
                            <input class="form-control" type="text" name="icon_class" id="icon_class" maxlength="120" placeholder="fa-solid fa-gear">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Sort order</label>
                            <input class="form-control" type="number" name="sort_order" id="sort_order" min="1" max="99999" value="1">
                            <div class="sm-subtitle mt-1">Position starts from 1. Existing items will shift automatically.</div>
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


<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title" id="confirmTitle">Confirm</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body"><div id="confirmMessage"></div><div class="form-check mt-3 d-none" id="deleteFileWrap"><input class="form-check-input" type="checkbox" value="1" id="deleteFileCheckbox"><label class="form-check-label" for="deleteFileCheckbox">Delete the linked PHP file also</label><div class="sm-subtitle mt-1" id="deleteFileRoute"></div></div></div>
            <div class="modal-footer"><button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-danger btn-sm" id="confirmActionBtn">Confirm</button></div>
        </div>
    </div>
</div>

<div class="modal fade" id="reorderModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header"><div><h5 class="modal-title">Bulk Reorder Menus</h5><div class="sm-subtitle">Set positions for main menus and each submenu group. Values will be normalized from 1.</div></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body" id="reorderBody"></div>
            <div class="modal-footer"><button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn-primary-sm" id="saveReorderBtn"><i class="fa-solid fa-floppy-disk me-2"></i>Save Order</button></div>
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
    const confirmModalEl=document.getElementById('confirmModal');
    const confirmModal=confirmModalEl?new bootstrap.Modal(confirmModalEl):null;
    const reorderModalEl=document.getElementById('reorderModal');
    const reorderModal=reorderModalEl?new bootstrap.Modal(reorderModalEl):null;
    let confirmCallback=null;

    function showConfirm(title,message,buttonText,callback,danger=false){
        document.getElementById('confirmTitle').textContent=title;
        document.getElementById('confirmMessage').textContent=message;
        const btn=document.getElementById('confirmActionBtn');
        btn.textContent=buttonText||'Confirm';
        btn.className='btn btn-sm '+(danger?'btn-danger':'btn-primary');
        const fileWrap=document.getElementById('deleteFileWrap');
        const fileCheck=document.getElementById('deleteFileCheckbox');
        if(fileWrap) fileWrap.classList.add('d-none');
        if(fileCheck) fileCheck.checked=false;
        confirmCallback=callback;
        confirmModal?.show();
    }
    document.getElementById('confirmActionBtn')?.addEventListener('click',async()=>{const cb=confirmCallback;confirmCallback=null;confirmModal?.hide();if(cb)await cb();});

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
        document.getElementById('sort_order').value='1';
        document.getElementById('menu_type').value='Menu';
        const scope=document.getElementById('scope_type'); if(scope) scope.value='business';
        const business=document.getElementById('business_id'); if(business&&business.tagName==='SELECT') business.value='';
        updateScopeUI();
    }

    function updateParentOptions(){
        const parent=document.getElementById('parent_id'); if(!parent) return;
        const scope=document.getElementById('scope_type')?.value||'business';
        const businessValue=document.getElementById('business_id')?.value||'';
        [...parent.options].forEach((option,index)=>{
            if(index===0){option.hidden=false;return;}
            const optionScope=option.dataset.business||'global';
            option.hidden=scope==='global' ? optionScope!=='global' : !(optionScope==='global'||optionScope===businessValue);
        });
        if(parent.selectedOptions[0]?.hidden) parent.value='';
    }

    function updateScopeUI(){
        const scope=document.getElementById('scope_type')?.value||'business';
        const wrap=document.getElementById('businessScopeWrap');
        const business=document.getElementById('business_id');
        if(wrap) wrap.classList.toggle('d-none',scope==='global');
        if(business&&business.tagName==='SELECT'){
            business.required=scope==='business';
            if(scope==='global') business.value='';
        }
        updateParentOptions();
    }

    document.getElementById('scope_type')?.addEventListener('change',()=>{updateScopeUI();if(document.getElementById('formAction').value==='create')loadNextOrder();});
    document.getElementById('business_id')?.addEventListener('change',()=>{updateParentOptions();if(document.getElementById('formAction').value==='create')loadNextOrder();});

    async function apiRequest(fd){
        const res=await fetch('api/sidebar-master-save.php',{method:'POST',body:fd,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}});
        const data=await res.json().catch(()=>({success:false,message:'Invalid server response.'}));
        if(!res.ok||!data.success) throw new Error(data.message||'Request failed.');
        return data;
    }

    async function loadNextOrder(){
        const fd=new FormData();
        fd.append('action','next_order');
        fd.append('csrf_token','<?php echo e($csrfToken); ?>');
        fd.append('parent_id',document.getElementById('parent_id')?.value||'');
        fd.append('scope_type',document.getElementById('scope_type')?.value||'business');
        const business=document.getElementById('business_id');
        if(business) fd.append('business_id',business.value||'');
        try{const data=await apiRequest(fd);document.getElementById('sort_order').value=String(data.next_order||1);}catch(err){toast('error',err.message);}
    }

    document.getElementById('parent_id')?.addEventListener('change',()=>{if(document.getElementById('formAction').value==='create')loadNextOrder();});

    document.getElementById('addMenuBtn')?.addEventListener('click',()=>{clearForm();modal?.show();loadNextOrder();});

    document.querySelectorAll('.edit-menu').forEach(btn=>btn.addEventListener('click',()=>{
        clearForm();
        const item=JSON.parse(btn.dataset.item||'{}');
        document.getElementById('formAction').value='update';
        document.getElementById('menuId').value=item.id||0;
        document.getElementById('menuModalTitle').textContent='Edit Menu Item';
        ['menu_code','menu_title','parent_id','route_url','icon_class','menu_type','sort_order','open_in_new_tab','business_id'].forEach(id=>{
            const el=document.getElementById(id); if(el) el.value=item[id]??'';
        });
        const scope=document.getElementById('scope_type'); if(scope) scope.value=item.business_id===null?'global':'business';
        updateScopeUI();
        if(item.parent_id) document.getElementById('parent_id').value=String(item.parent_id);
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
            const data=await apiRequest(new FormData(form));
            toast('success',data.message||'Menu item saved successfully.');
            modal?.hide();
            setTimeout(()=>window.location.reload(),600);
        }catch(err){toast('error',err.message||'Unable to save menu item.');}
        finally{saveBtn.disabled=false;saveBtn.innerHTML=old;}
    });

    document.querySelectorAll('.delete-menu').forEach(btn=>btn.addEventListener('click',()=>{
        showConfirm('Delete Menu Item','Delete "'+btn.dataset.title+'"? Child items must be removed first.','Delete',async()=>{
            const fd=new FormData();
            fd.append('action','delete');
            fd.append('id',btn.dataset.id);
            fd.append('csrf_token','<?php echo e($csrfToken); ?>');
            if(document.getElementById('deleteFileCheckbox')?.checked) fd.append('delete_file','1');
            try{const data=await apiRequest(fd);toast('success',data.message||'Menu item deleted.');setTimeout(()=>location.reload(),500);}catch(err){toast('error',err.message||'Unable to delete menu item.');}
        },true);
        const route=(btn.dataset.route||'').trim();
        const isPhp=/^[A-Za-z0-9_./-]+\.php$/i.test(route)&&!route.includes('../');
        const wrap=document.getElementById('deleteFileWrap');
        const check=document.getElementById('deleteFileCheckbox');
        if(check) check.checked=false;
        if(wrap) wrap.classList.toggle('d-none',!isPhp);
        const routeText=document.getElementById('deleteFileRoute');
        if(routeText) routeText.textContent=isPhp?'File: '+route:'';
    }));


    document.getElementById('bulkReorderBtn')?.addEventListener('click',()=>{
        const rows=[...document.querySelectorAll('#menuTableBody tr')];
        const groups={};
        rows.forEach(row=>{const parent=String(row.dataset.parent||'0');(groups[parent]||(groups[parent]=[])).push(row);});
        const body=document.getElementById('reorderBody');body.innerHTML='';
        Object.keys(groups).sort((a,b)=>Number(a)-Number(b)).forEach(parent=>{
            const title=document.createElement('div');title.className='reorder-parent';
            title.textContent=parent==='0'?'Main Menus':'Submenus of '+(document.querySelector('#menuTableBody tr[data-id="'+parent+'"] .menu-name span:last-child')?.textContent||('Menu #'+parent));
            body.appendChild(title);
            const list=document.createElement('div');list.className='reorder-list';
            groups[parent].sort((a,b)=>Number(a.dataset.order)-Number(b.dataset.order)).forEach((row,index)=>{
                const item=document.createElement('div');item.className='reorder-item';item.dataset.id=row.dataset.id;item.dataset.parent=parent;
                item.innerHTML='<i class="fa-solid fa-grip-vertical drag-handle"></i><div class="reorder-item-title"></div><input class="form-control reorder-item-order" type="number" min="1" value="'+(index+1)+'">';
                item.querySelector('.reorder-item-title').textContent=row.querySelector('.menu-name span:last-child')?.textContent||('Menu #'+row.dataset.id);
                list.appendChild(item);
            });
            body.appendChild(list);
        });
        reorderModal?.show();
    });

    document.getElementById('saveReorderBtn')?.addEventListener('click',async()=>{
        const btn=document.getElementById('saveReorderBtn'),old=btn.innerHTML;btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin me-2"></i>Saving...';
        const groups={};document.querySelectorAll('#reorderBody .reorder-item').forEach(item=>{const p=item.dataset.parent;(groups[p]||(groups[p]=[])).push({id:Number(item.dataset.id),position:Number(item.querySelector('input').value||1)});});
        const normalized=[];Object.keys(groups).forEach(p=>{groups[p].sort((a,b)=>a.position-b.position||a.id-b.id).forEach((x,i)=>normalized.push({id:x.id,sort_order:i+1}));});
        const fd=new FormData();fd.append('action','bulk_reorder');fd.append('csrf_token','<?php echo e($csrfToken); ?>');fd.append('items',JSON.stringify(normalized));
        try{const data=await apiRequest(fd);toast('success',data.message);reorderModal?.hide();setTimeout(()=>location.reload(),500);}catch(err){toast('error',err.message);}finally{btn.disabled=false;btn.innerHTML=old;}
    });

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
    updateScopeUI();
})();
</script>
</body>
</html>
