<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

function respond(bool $success, string $message, array $extra = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode(array_merge(['success'=>$success,'message'=>$message],$extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$configCandidates = [
    dirname(__DIR__) . '/config/config.php',
    dirname(__DIR__) . '/config.php',
    dirname(__DIR__) . '/super-admin/includes/config.php',
];
foreach ($configCandidates as $configFile) {
    if (is_file($configFile)) { require_once $configFile; break; }
}
if (!isset($conn) || !($conn instanceof mysqli)) respond(false,'Database configuration is not available.',[],500);
$conn->set_charset('utf8mb4');
if (empty($_SESSION['user_id'])) respond(false,'Your session has expired. Please log in again.',[],401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(false,'Invalid request method.',[],405);

function canSidebarMaster(mysqli $conn, string $action): bool
{
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin') return true;
    $map=['create'=>'can_create','update'=>'can_update','delete'=>'can_delete'];
    $field=$map[$action]??'';
    if($field==='') return false;
    $sessionPermissions=$_SESSION['permissions']??[];
    foreach(['perm.settings','perm.staff.roles'] as $key){
        if(isset($sessionPermissions[$key][$field])) return (int)$sessionPermissions[$key][$field]===1;
    }
    $businessId=(int)($_SESSION['business_id']??0);$roleId=(int)($_SESSION['role_id']??0);
    if($businessId<=0||$roleId<=0) return false;
    $sql="SELECT rp.`{$field}` FROM role_permissions rp INNER JOIN permissions p ON p.id=rp.permission_id WHERE rp.business_id=? AND rp.role_id=? AND p.permission_code IN ('perm.settings','perm.staff.roles') AND p.is_active=1 ORDER BY FIELD(p.permission_code,'perm.settings','perm.staff.roles') LIMIT 1";
    $stmt=$conn->prepare($sql);if(!$stmt)return false;$stmt->bind_param('ii',$businessId,$roleId);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();return (int)($row[$field]??0)===1;
}


function syncMenuPermission(mysqli $conn, int $menuId, ?int $menuBusinessId, string $menuCode, string $menuTitle, int $currentBusinessId, int $currentRoleId): int
{
    $permissionCode = 'perm.' . ltrim($menuCode, '.');
    $permissionName = $menuTitle . ' Permission';

    $stmt = $conn->prepare('SELECT id FROM permissions WHERE menu_item_id = ? LIMIT 1');
    if (!$stmt) throw new RuntimeException('Unable to inspect menu permission.');
    $stmt->bind_param('i', $menuId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        $permissionId = (int) $row['id'];
        if ($menuBusinessId === null) {
            $stmt = $conn->prepare('UPDATE permissions SET business_id = NULL, permission_code = ?, permission_name = ?, is_active = 1 WHERE id = ?');
            if (!$stmt) throw new RuntimeException('Unable to update menu permission.');
            $stmt->bind_param('ssi', $permissionCode, $permissionName, $permissionId);
        } else {
            $stmt = $conn->prepare('UPDATE permissions SET business_id = ?, permission_code = ?, permission_name = ?, is_active = 1 WHERE id = ?');
            if (!$stmt) throw new RuntimeException('Unable to update menu permission.');
            $stmt->bind_param('issi', $menuBusinessId, $permissionCode, $permissionName, $permissionId);
        }
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Unable to update menu permission: ' . $error);
        }
        $stmt->close();
    } else {
        if ($menuBusinessId === null) {
            $stmt = $conn->prepare('INSERT INTO permissions (business_id, menu_item_id, permission_code, permission_name, description, is_active) VALUES (NULL, ?, ?, ?, NULL, 1)');
            if (!$stmt) throw new RuntimeException('Unable to create menu permission.');
            $stmt->bind_param('iss', $menuId, $permissionCode, $permissionName);
        } else {
            $stmt = $conn->prepare('INSERT INTO permissions (business_id, menu_item_id, permission_code, permission_name, description, is_active) VALUES (?, ?, ?, ?, NULL, 1)');
            if (!$stmt) throw new RuntimeException('Unable to create menu permission.');
            $stmt->bind_param('iiss', $menuBusinessId, $menuId, $permissionCode, $permissionName);
        }
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Unable to create menu permission: ' . $error);
        }
        $permissionId = (int) $stmt->insert_id;
        $stmt->close();
    }

    $roleIds = [];
    if ($currentRoleId > 0) $roleIds[$currentRoleId] = $currentRoleId;

    if ($currentBusinessId > 0) {
        $stmt = $conn->prepare("SELECT id FROM roles WHERE business_id = ? AND role_code = 'BUSINESS_ADMIN' AND is_active = 1");
        if ($stmt) {
            $stmt->bind_param('i', $currentBusinessId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($role = $result->fetch_assoc()) $roleIds[(int) $role['id']] = (int) $role['id'];
            $stmt->close();
        }
    }

    foreach ($roleIds as $roleId) {
        $stmt = $conn->prepare('INSERT INTO role_permissions (business_id, role_id, permission_id, can_open, can_view_value, can_view, can_create, can_update, can_approve, can_delete) VALUES (?, ?, ?, 1, 1, 1, 1, 1, 1, 1) ON DUPLICATE KEY UPDATE can_open = 1');
        if (!$stmt) throw new RuntimeException('Unable to assign menu permission.');
        $stmt->bind_param('iii', $currentBusinessId, $roleId, $permissionId);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Unable to assign menu permission: ' . $error);
        }
        $stmt->close();
    }

    return $permissionId;
}

$token=(string)($_POST['csrf_token']??'');
if(empty($_SESSION['sidebar_master_csrf'])||!hash_equals($_SESSION['sidebar_master_csrf'],$token)) respond(false,'Invalid or expired request token.',[],419);

$action=(string)($_POST['action']??'');
if(!in_array($action,['create','update','delete'],true)) respond(false,'Invalid action.',[],422);
if(!canSidebarMaster($conn,$action)) respond(false,'You do not have permission to perform this action.',[],403);

$isPlatformAdmin=(($_SESSION['user_type']??'')==='Platform Admin');
$currentBusinessId=(int)($_SESSION['business_id']??0);
$currentRoleId=(int)($_SESSION['role_id']??0);
$id=(int)($_POST['id']??0);

if($action==='delete'){
    if($id<=0) respond(false,'Invalid menu item.',[],422);
    $scopeSql=$isPlatformAdmin?'SELECT id FROM menu_items WHERE id=? LIMIT 1':'SELECT id FROM menu_items WHERE id=? AND (business_id IS NULL OR business_id=?) LIMIT 1';
    $stmt=$conn->prepare($scopeSql);
    if($isPlatformAdmin){$stmt->bind_param('i',$id);}else{$stmt->bind_param('ii',$id,$currentBusinessId);} $stmt->execute();$exists=$stmt->get_result()->num_rows>0;$stmt->close();
    if(!$exists) respond(false,'Menu item not found or access denied.',[],404);
    $stmt=$conn->prepare('SELECT COUNT(*) AS c FROM menu_items WHERE parent_id=?');$stmt->bind_param('i',$id);$stmt->execute();$childCount=(int)($stmt->get_result()->fetch_assoc()['c']??0);$stmt->close();
    if($childCount>0) respond(false,'Remove or move child menu items before deleting this item.',[],409);
    $stmt=$conn->prepare('SELECT COUNT(*) AS c FROM permissions WHERE menu_item_id=?');$stmt->bind_param('i',$id);$stmt->execute();$permCount=(int)($stmt->get_result()->fetch_assoc()['c']??0);$stmt->close();
    if($permCount>0) respond(false,'This menu item is linked to permissions and cannot be deleted.',[],409);
    $stmt=$conn->prepare('DELETE FROM menu_items WHERE id=?');$stmt->bind_param('i',$id);$ok=$stmt->execute();$stmt->close();
    if(!$ok) respond(false,'Unable to delete menu item: '.$conn->error,[],500);
    respond(true,'Menu item deleted successfully.');
}

$businessId=null;
if($isPlatformAdmin){
    $postedBusiness=trim((string)($_POST['business_id']??''));
    $businessId=$postedBusiness===''?null:(int)$postedBusiness;
}else{
    $businessId=$currentBusinessId;
}
$menuCode=trim((string)($_POST['menu_code']??''));
$menuTitle=trim((string)($_POST['menu_title']??''));
$routeUrl=trim((string)($_POST['route_url']??''));
$iconClass=trim((string)($_POST['icon_class']??''));
$menuType=(string)($_POST['menu_type']??'Menu');
$parentRaw=trim((string)($_POST['parent_id']??''));
$parentId=$parentRaw===''?null:(int)$parentRaw;
$sortOrder=max(0,(int)($_POST['sort_order']??0));
$openNew=(int)(($_POST['open_in_new_tab']??0)==1);
$isVisible=isset($_POST['is_visible'])?1:0;
$isActive=isset($_POST['is_active'])?1:0;

if($menuCode===''||$menuTitle==='') respond(false,'Menu code and menu title are required.',[],422);
if(!preg_match('/^[a-zA-Z0-9._-]+$/',$menuCode)) respond(false,'Menu code may contain only letters, numbers, dots, hyphens and underscores.',[],422);
if(!in_array($menuType,['Menu','Group','Divider'],true)) respond(false,'Invalid menu type.',[],422);
if($menuType==='Divider'){$routeUrl='';$iconClass='';}
if($menuType==='Group'){$routeUrl='';}
if($id>0&&$parentId===$id) respond(false,'A menu item cannot be its own parent.',[],422);

if($parentId!==null){
    $stmt=$conn->prepare('SELECT id,business_id FROM menu_items WHERE id=? LIMIT 1');$stmt->bind_param('i',$parentId);$stmt->execute();$parent=$stmt->get_result()->fetch_assoc();$stmt->close();
    if(!$parent) respond(false,'Selected parent menu was not found.',[],422);
    if(!$isPlatformAdmin && $parent['business_id']!==null && (int)$parent['business_id']!==$currentBusinessId) respond(false,'Invalid parent menu selection.',[],403);
}

$dupSql='SELECT id FROM menu_items WHERE menu_code=? AND ((business_id IS NULL AND ? IS NULL) OR business_id=?)';
if($action==='update') $dupSql.=' AND id<>?';
$dupSql.=' LIMIT 1';
$stmt=$conn->prepare($dupSql);
$businessBind=$businessId;
if($action==='update'){$stmt->bind_param('siii',$menuCode,$businessBind,$businessBind,$id);}else{$stmt->bind_param('sii',$menuCode,$businessBind,$businessBind);} $stmt->execute();$duplicate=$stmt->get_result()->num_rows>0;$stmt->close();
if($duplicate) respond(false,'This menu code already exists for the selected scope.',[],409);

if($action==='create'){
    $sql='INSERT INTO menu_items (business_id,parent_id,menu_code,menu_title,route_url,icon_class,menu_type,sort_order,open_in_new_tab,is_visible,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?)';
    $stmt=$conn->prepare($sql);
    $stmt->bind_param('iisssssiiii',$businessId,$parentId,$menuCode,$menuTitle,$routeUrl,$iconClass,$menuType,$sortOrder,$openNew,$isVisible,$isActive);
    if(!$stmt->execute()){ $err=$stmt->error;$stmt->close();respond(false,'Unable to create menu item: '.$err,[],500);} $newId=$stmt->insert_id;$stmt->close();
    try {
        syncMenuPermission($conn, (int)$newId, $businessId, $menuCode, $menuTitle, $currentBusinessId, $currentRoleId);
    } catch (Throwable $e) {
        respond(false, 'Menu was created, but permission synchronization failed: ' . $e->getMessage(), ['id'=>$newId], 500);
    }
    respond(true,'Menu item and permission created successfully.',['id'=>$newId]);
}

if($id<=0) respond(false,'Invalid menu item.',[],422);
$scopeSql=$isPlatformAdmin?'SELECT id FROM menu_items WHERE id=? LIMIT 1':'SELECT id FROM menu_items WHERE id=? AND (business_id IS NULL OR business_id=?) LIMIT 1';
$stmt=$conn->prepare($scopeSql);if($isPlatformAdmin){$stmt->bind_param('i',$id);}else{$stmt->bind_param('ii',$id,$currentBusinessId);} $stmt->execute();$exists=$stmt->get_result()->num_rows>0;$stmt->close();
if(!$exists) respond(false,'Menu item not found or access denied.',[],404);

$sql='UPDATE menu_items SET business_id=?,parent_id=?,menu_code=?,menu_title=?,route_url=?,icon_class=?,menu_type=?,sort_order=?,open_in_new_tab=?,is_visible=?,is_active=? WHERE id=?';
$stmt=$conn->prepare($sql);
$stmt->bind_param('iisssssiiiii',$businessId,$parentId,$menuCode,$menuTitle,$routeUrl,$iconClass,$menuType,$sortOrder,$openNew,$isVisible,$isActive,$id);
if(!$stmt->execute()){ $err=$stmt->error;$stmt->close();respond(false,'Unable to update menu item: '.$err,[],500);} $stmt->close();
try {
    syncMenuPermission($conn, $id, $businessId, $menuCode, $menuTitle, $currentBusinessId, $currentRoleId);
} catch (Throwable $e) {
    respond(false, 'Menu was updated, but permission synchronization failed: ' . $e->getMessage(), [], 500);
}
respond(true,'Menu item and permission updated successfully.');
