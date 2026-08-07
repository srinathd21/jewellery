<?php
if (session_status() === PHP_SESSION_NONE)
    session_start();
header('Content-Type: application/json; charset=utf-8');

function respond($success, $message, $extra = array(), $status = 200)
{
    http_response_code($status);
    echo json_encode(array_merge(array('success' => (bool) $success, 'message' => (string) $message), $extra));
    exit;
}

$configCandidates = array(
    dirname(__DIR__) . '/config/config.php',
    dirname(__DIR__) . '/config.php',
    dirname(__DIR__) . '/super-admin/includes/config.php',
);
foreach ($configCandidates as $configFile) {
    if (is_file($configFile)) {
        require_once $configFile;
        break;
    }
}
if (!isset($conn) || !($conn instanceof mysqli))
    respond(false, 'Database configuration is not available.', array(), 500);
$conn->set_charset('utf8mb4');
if (empty($_SESSION['user_id']))
    respond(false, 'Your session has expired. Please log in again.', array(), 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST')
    respond(false, 'Invalid request method.', array(), 405);
if (!hash_equals((string) ($_SESSION['sidebar_master_csrf'] ?? ''), (string) ($_POST['csrf_token'] ?? '')))
    respond(false, 'Invalid or expired request token.', array(), 419);

function hasPermission($conn, $action)
{
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin')
        return true;
    $map = array('open' => 'can_open', 'view' => 'can_view', 'create' => 'can_create', 'update' => 'can_update', 'delete' => 'can_delete');
    $field = isset($map[$action]) ? $map[$action] : '';
    if ($field === '')
        return false;
    $permissions = $_SESSION['permissions'] ?? array();
    foreach (array('perm.settings.sidebar', 'perm.settings', 'perm.staff.roles') as $key) {
        if (isset($permissions[$key][$field]))
            return (int) $permissions[$key][$field] === 1;
    }
    $businessId = (int) ($_SESSION['business_id'] ?? 0);
    $roleId = (int) ($_SESSION['role_id'] ?? 0);
    if ($businessId <= 0 || $roleId <= 0)
        return false;
    $sql = "SELECT rp.`{$field}` FROM role_permissions rp INNER JOIN permissions p ON p.id=rp.permission_id WHERE rp.business_id=? AND rp.role_id=? AND p.is_active=1 AND p.permission_code IN ('perm.settings.sidebar','perm.settings','perm.staff.roles') ORDER BY FIELD(p.permission_code,'perm.settings.sidebar','perm.settings','perm.staff.roles') LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt)
        return false;
    $stmt->bind_param('ii', $businessId, $roleId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row[$field] ?? 0) === 1;
}

function nullableScopeCondition($businessId)
{
    return $businessId === null ? 'business_id IS NULL' : 'business_id = ' . (int) $businessId;
}

function normalizeGroup($conn, $businessId, $parentId)
{
    $scopeSql = nullableScopeCondition($businessId);
    $parentSql = $parentId === null ? 'parent_id IS NULL' : 'parent_id = ' . (int) $parentId;
    $res = $conn->query("SELECT id FROM menu_items WHERE {$scopeSql} AND {$parentSql} ORDER BY sort_order,id");
    if (!$res)
        return;
    $order = 1;
    $stmt = $conn->prepare('UPDATE menu_items SET sort_order=? WHERE id=?');
    while ($row = $res->fetch_assoc()) {
        $id = (int) $row['id'];
        $stmt->bind_param('ii', $order, $id);
        $stmt->execute();
        $order++;
    }
    $stmt->close();
}

function shiftForInsert($conn, $businessId, $parentId, $position, $excludeId)
{
    $scopeSql = nullableScopeCondition($businessId);
    $parentSql = $parentId === null ? 'parent_id IS NULL' : 'parent_id = ' . (int) $parentId;
    $sql = "UPDATE menu_items SET sort_order=sort_order+1 WHERE {$scopeSql} AND {$parentSql} AND sort_order>=?";
    if ($excludeId > 0)
        $sql .= ' AND id<>' . (int) $excludeId;
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $position);
    $stmt->execute();
    $stmt->close();
}

function validPhpRoute($route)
{
    return preg_match('/^[A-Za-z0-9_\/.\-]+\.php$/i', $route) && strpos($route, '../') === false && strpos($route, '://') === false;
}

function createPhpPage($route, $title)
{
    if (!validPhpRoute($route))
        return array(false, 'Invalid PHP route.');
    $root = realpath(dirname(__DIR__));
    $target = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $route);
    $dir = dirname($target);
    if (!is_dir($dir) && !mkdir($dir, 0755, true))
        return array(false, 'Unable to create page directory.');
    if (is_file($target))
        return array(true, 'The linked PHP file already exists and was not overwritten.');
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $code = "<?php\nif (session_status() === PHP_SESSION_NONE) session_start();\n\$pageTitle = '" . addslashes($title) . "';\n?>\n<!doctype html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n<title>{$safeTitle}</title>\n<?php include('includes/links.php'); ?>\n</head>\n<body>\n<?php include('includes/sidebar.php'); ?>\n<main class=\"app-main\">\n<?php include('includes/nav.php'); ?>\n<div class=\"content-wrap\">\n<div class=\"card-panel p-4\"><h1 class=\"h5 mb-2\">{$safeTitle}</h1><p class=\"text-muted mb-0\">Page content will be added here.</p></div>\n<?php include('includes/footer.php'); ?>\n</div>\n</main>\n<?php include('includes/script.php'); ?>\n<script src=\"assets/js/script.js\"></script>\n</body>\n</html>\n";
    if (file_put_contents($target, $code) === false)
        return array(false, 'Unable to create linked PHP file.');
    return array(true, 'PHP file created successfully.');
}

function deletePhpPage($route)
{
    if (!validPhpRoute($route))
        return false;
    $root = realpath(dirname(__DIR__));
    $target = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $route));
    if (!$target || strpos($target, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($target))
        return false;
    return unlink($target);
}


function grantCreatedMenuPermission($conn, $menuId, $menuCode, $menuTitle, $currentBusinessId, $currentRoleId, $isGlobal)
{
    if ($menuId <= 0 || $currentBusinessId <= 0 || $currentRoleId <= 0)
        return;

    $permissionCode = strpos($menuCode, 'perm.') === 0 ? $menuCode : 'perm.' . $menuCode;
    $permissionBusinessId = $isGlobal ? null : $currentBusinessId;
    $description = 'Access permission for ' . $menuTitle;

    $scopeSql = $permissionBusinessId === null ? 'business_id IS NULL' : 'business_id = ' . (int) $permissionBusinessId;
    $stmt = $conn->prepare("SELECT id FROM permissions WHERE menu_item_id=? AND {$scopeSql} LIMIT 1");
    if (!$stmt)
        throw new Exception('Unable to prepare permission lookup: ' . $conn->error);
    $stmt->bind_param('i', $menuId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        $permissionId = (int) $row['id'];
        $stmt = $conn->prepare('UPDATE permissions SET permission_code=?, permission_name=?, description=?, is_active=1 WHERE id=?');
        if (!$stmt)
            throw new Exception('Unable to prepare permission update: ' . $conn->error);
        $stmt->bind_param('sssi', $permissionCode, $menuTitle, $description, $permissionId);
        if (!$stmt->execute())
            throw new Exception($stmt->error);
        $stmt->close();
    } else {
        $stmt = $conn->prepare('INSERT INTO permissions (business_id,menu_item_id,permission_code,permission_name,description,is_active) VALUES (?,?,?,?,?,1)');
        if (!$stmt)
            throw new Exception('Unable to prepare permission insert: ' . $conn->error);
        $stmt->bind_param('iisss', $permissionBusinessId, $menuId, $permissionCode, $menuTitle, $description);
        if (!$stmt->execute())
            throw new Exception($stmt->error);
        $permissionId = (int) $stmt->insert_id;
        $stmt->close();
    }

    $stmt = $conn->prepare('SELECT id FROM role_permissions WHERE business_id=? AND role_id=? AND permission_id=? LIMIT 1');
    if (!$stmt)
        throw new Exception('Unable to prepare role permission lookup: ' . $conn->error);
    $stmt->bind_param('iii', $currentBusinessId, $currentRoleId, $permissionId);
    $stmt->execute();
    $rolePermission = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($rolePermission) {
        $stmt = $conn->prepare('UPDATE role_permissions SET can_open=1,can_view_value=1,can_view=1,can_create=1,can_update=1,can_approve=1,can_delete=1 WHERE id=?');
        if (!$stmt)
            throw new Exception('Unable to prepare role permission update: ' . $conn->error);
        $rolePermissionId = (int) $rolePermission['id'];
        $stmt->bind_param('i', $rolePermissionId);
        if (!$stmt->execute())
            throw new Exception($stmt->error);
        $stmt->close();
    } else {
        $stmt = $conn->prepare('INSERT INTO role_permissions (business_id,role_id,permission_id,can_open,can_view_value,can_view,can_create,can_update,can_approve,can_delete) VALUES (?,?,?,1,1,1,1,1,1,1)');
        if (!$stmt)
            throw new Exception('Unable to prepare role permission insert: ' . $conn->error);
        $stmt->bind_param('iii', $currentBusinessId, $currentRoleId, $permissionId);
        if (!$stmt->execute())
            throw new Exception($stmt->error);
        $stmt->close();
    }
}

$action = (string) ($_POST['action'] ?? '');
$currentBusinessId = (int) ($_SESSION['business_id'] ?? 0);
$isPlatformAdmin = (($_SESSION['user_type'] ?? '') === 'Platform Admin');
$currentRoleId = (int) ($_SESSION['role_id'] ?? 0);

if ($action === 'next_order') {
    if (!hasPermission($conn, 'open'))
        respond(false, 'Permission denied.', array(), 403);
    $scopeType = (string) ($_POST['scope_type'] ?? 'business');
    $businessId = $scopeType === 'global' ? null : (int) ($_POST['business_id'] ?? $currentBusinessId);
    if ($businessId !== null && $businessId <= 0)
        respond(false, 'Select a valid business.');
    if (!$isPlatformAdmin && $businessId !== null)
        $businessId = $currentBusinessId;
    $parentId = (int) ($_POST['parent_id'] ?? 0);
    $parentId = $parentId > 0 ? $parentId : null;
    $scopeSql = nullableScopeCondition($businessId);
    $parentSql = $parentId === null ? 'parent_id IS NULL' : 'parent_id=' . (int) $parentId;
    $row = $conn->query("SELECT COALESCE(MAX(sort_order),0)+1 AS next_order FROM menu_items WHERE {$scopeSql} AND {$parentSql}")->fetch_assoc();
    respond(true, 'Next order loaded.', array('next_order' => (int) ($row['next_order'] ?? 1)));
}

if ($action === 'create' || $action === 'update') {
    if (!hasPermission($conn, $action === 'create' ? 'create' : 'update'))
        respond(false, 'Permission denied.', array(), 403);
    $id = (int) ($_POST['id'] ?? 0);
    $scopeType = (string) ($_POST['scope_type'] ?? 'business');
    if (!in_array($scopeType, array('global', 'business'), true))
        $scopeType = 'business';
    $businessId = $scopeType === 'global' ? null : (int) ($_POST['business_id'] ?? $currentBusinessId);
    if ($businessId !== null && $businessId <= 0)
        respond(false, 'Select a valid business.');
    if (!$isPlatformAdmin && $businessId !== null)
        $businessId = $currentBusinessId;
    $menuType = (string) ($_POST['menu_type'] ?? 'Menu');
    $menuCode = trim((string) ($_POST['menu_code'] ?? ''));
    $menuTitle = trim((string) ($_POST['menu_title'] ?? ''));
    $parentId = (int) ($_POST['parent_id'] ?? 0);
    $parentId = $parentId > 0 ? $parentId : null;
    $routeUrl = trim((string) ($_POST['route_url'] ?? ''));
    $iconClass = trim((string) ($_POST['icon_class'] ?? ''));
    $sortOrder = max(1, (int) ($_POST['sort_order'] ?? 1));
    $openNew = (int) ($_POST['open_in_new_tab'] ?? 0) === 1 ? 1 : 0;
    $visible = isset($_POST['is_visible']) ? 1 : 0;
    $active = isset($_POST['is_active']) ? 1 : 0;
    if (!in_array($menuType, array('Menu', 'Group', 'Divider'), true))
        respond(false, 'Invalid menu type.');
    if ($menuCode === '' || $menuTitle === '')
        respond(false, 'Menu code and title are required.');
    if (!preg_match('/^[A-Za-z0-9._-]{2,80}$/', $menuCode))
        respond(false, 'Enter a valid menu code.');
    if ($menuType === 'Menu' && $routeUrl === '')
        $routeUrl = strtolower(str_replace(array('.', '_'), '-', $menuCode)) . '.php';
    if ($menuType !== 'Menu' && $routeUrl === '')
        $routeUrl = null;

    if ($parentId !== null) {
        $stmt = $conn->prepare('SELECT id,business_id FROM menu_items WHERE id=? LIMIT 1');
        $stmt->bind_param('i', $parentId);
        $stmt->execute();
        $parent = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$parent)
            respond(false, 'Selected parent menu was not found.');
        $parentBusiness = $parent['business_id'] === null ? null : (int) $parent['business_id'];
        if ($businessId === null && $parentBusiness !== null)
            respond(false, 'A global menu can only use a global parent.');
        if ($businessId !== null && $parentBusiness !== null && $parentBusiness !== $businessId)
            respond(false, 'Parent menu belongs to another business.');
        if ($id > 0 && $parentId === $id)
            respond(false, 'A menu cannot be its own parent.');
    }

    $scopeCheck = $businessId === null ? 'business_id IS NULL' : 'business_id=' . (int) $businessId;
    $dupSql = "SELECT id FROM menu_items WHERE {$scopeCheck} AND menu_code=? AND id<>? LIMIT 1";
    $stmt = $conn->prepare($dupSql);
    $stmt->bind_param('si', $menuCode, $id);
    $stmt->execute();
    $dup = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($dup)
        respond(false, 'This menu code already exists in the selected scope.');

    $old = null;
    $oldBusiness = null;
    $oldParent = null;
    if ($action === 'update') {
        $stmt = $conn->prepare('SELECT * FROM menu_items WHERE id=? LIMIT 1');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $old = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$old)
            respond(false, 'Menu item not found.', array(), 404);
        $oldBusiness = $old['business_id'] === null ? null : (int) $old['business_id'];
        $oldParent = (int) ($old['parent_id'] ?? 0);
        $oldParent = $oldParent > 0 ? $oldParent : null;
    }

    $conn->begin_transaction();
    try {
        if ($action === 'create') {
            shiftForInsert($conn, $businessId, $parentId, $sortOrder, 0);
            $stmt = $conn->prepare('INSERT INTO menu_items (business_id,parent_id,menu_code,menu_title,route_url,icon_class,menu_type,sort_order,open_in_new_tab,is_visible,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->bind_param('iisssssiiii', $businessId, $parentId, $menuCode, $menuTitle, $routeUrl, $iconClass, $menuType, $sortOrder, $openNew, $visible, $active);
            if (!$stmt->execute())
                throw new Exception($stmt->error);
            $id = (int) $stmt->insert_id;
            $stmt->close();

            // Automatically create the permission and grant full access to the
            // currently logged-in business and role. For a Global menu, the
            // permission itself remains Global while role access is business-specific.
            grantCreatedMenuPermission(
                $conn,
                $id,
                $menuCode,
                $menuTitle,
                $currentBusinessId,
                $currentRoleId,
                $businessId === null
            );
        } else {
            if ($oldBusiness !== $businessId || $oldParent !== $parentId || (int) $old['sort_order'] !== $sortOrder)
                shiftForInsert($conn, $businessId, $parentId, $sortOrder, $id);
            $stmt = $conn->prepare('UPDATE menu_items SET business_id=?,parent_id=?,menu_code=?,menu_title=?,route_url=?,icon_class=?,menu_type=?,sort_order=?,open_in_new_tab=?,is_visible=?,is_active=? WHERE id=?');
            $stmt->bind_param('iisssssiiiii', $businessId, $parentId, $menuCode, $menuTitle, $routeUrl, $iconClass, $menuType, $sortOrder, $openNew, $visible, $active, $id);
            if (!$stmt->execute())
                throw new Exception($stmt->error);
            $stmt->close();
            normalizeGroup($conn, $oldBusiness, $oldParent);
        }
        normalizeGroup($conn, $businessId, $parentId);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        respond(false, 'Unable to save menu item: ' . $e->getMessage(), array(), 500);
    }
    $fileMessage = '';
    if ($action === 'create' && $menuType === 'Menu' && $routeUrl) {
        list($ok, $fileMessage) = createPhpPage($routeUrl, $menuTitle);
        if (!$ok)
            $fileMessage = 'Menu saved, but ' . $fileMessage;
    }
    respond(true, $action === 'create' ? 'Menu item created successfully.' : 'Menu item updated successfully.', array('id' => $id, 'route_url' => $routeUrl, 'file_message' => $fileMessage));
}

if ($action === 'delete') {
    if (!hasPermission($conn, 'delete'))
        respond(false, 'Permission denied.', array(), 403);
    $id = (int) ($_POST['id'] ?? 0);
    $stmt = $conn->prepare('SELECT * FROM menu_items WHERE id=? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $item = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$item)
        respond(false, 'Menu item not found.', array(), 404);
    $stmt = $conn->prepare('SELECT COUNT(*) c FROM menu_items WHERE parent_id=?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $children = (int) $stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();
    if ($children > 0)
        respond(false, 'Remove or move child menu items before deleting this parent.');
    $scope = $item['business_id'] === null ? null : (int) $item['business_id'];
    $parent = (int) ($item['parent_id'] ?? 0);
    $parent = $parent > 0 ? $parent : null;
    $stmt = $conn->prepare('DELETE FROM menu_items WHERE id=?');
    $stmt->bind_param('i', $id);
    if (!$stmt->execute())
        respond(false, 'Unable to delete menu item.', array(), 500);
    $stmt->close();
    normalizeGroup($conn, $scope, $parent);
    $fileDeleted = false;
    if (isset($_POST['delete_file']) && !empty($item['route_url']))
        $fileDeleted = deletePhpPage($item['route_url']);
    respond(true, 'Menu item deleted successfully.', array('file_deleted' => $fileDeleted));
}

if ($action === 'bulk_reorder') {
    if (!hasPermission($conn, 'update'))
        respond(false, 'Permission denied.', array(), 403);
    $items = json_decode((string) ($_POST['items'] ?? ''), true);
    if (!is_array($items))
        respond(false, 'Invalid reorder data.');
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('UPDATE menu_items SET sort_order=? WHERE id=?');
        foreach ($items as $entry) {
            $id = (int) ($entry['id'] ?? 0);
            $order = max(1, (int) ($entry['sort_order'] ?? 1));
            if ($id <= 0)
                continue;
            $stmt->bind_param('ii', $order, $id);
            if (!$stmt->execute())
                throw new Exception($stmt->error);
        }
        $stmt->close();
        $groups = $conn->query('SELECT DISTINCT business_id,parent_id FROM menu_items');
        while ($g = $groups->fetch_assoc()) {
            normalizeGroup($conn, $g['business_id'] === null ? null : (int) $g['business_id'], $g['parent_id'] === null ? null : (int) $g['parent_id']);
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        respond(false, 'Unable to reorder menus: ' . $e->getMessage(), array(), 500);
    }
    respond(true, 'Menu order updated successfully.');
}

respond(false, 'Invalid action.', array(), 400);