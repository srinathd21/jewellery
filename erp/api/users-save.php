<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');

function respond(bool $success, string $message, array $extra = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

$configCandidates = [
    dirname(__DIR__) . '/config/config.php',
    dirname(__DIR__) . '/config.php',
    dirname(__DIR__) . '/super-admin/includes/config.php',
];
foreach ($configCandidates as $configFile) {
    if (is_file($configFile)) {
        require_once $configFile;
        break;
    }
}
if (!isset($conn) || !($conn instanceof mysqli)) {
    respond(false, 'Database configuration is not available.', [], 500);
}
$conn->set_charset('utf8mb4');
if (empty($_SESSION['user_id'])) {
    respond(false, 'Your session has expired. Please log in again.', [], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Invalid request method.', [], 405);
}
if (!hash_equals((string)($_SESSION['users_csrf'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) {
    respond(false, 'Invalid or expired request token. Refresh the page and try again.', [], 419);
}

function hasPermission(mysqli $conn, string $action): bool
{
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin') {
        return true;
    }
    $map = ['open'=>'can_open','view'=>'can_view','create'=>'can_create','update'=>'can_update','delete'=>'can_delete'];
    $field = $map[$action] ?? '';
    if ($field === '') return false;
    $permissions = $_SESSION['permissions'] ?? [];
    foreach (['perm.staff.users','perm.staff'] as $key) {
        if (isset($permissions[$key][$field])) return (int)$permissions[$key][$field] === 1;
    }
    $businessId=(int)($_SESSION['business_id']??0);$roleId=(int)($_SESSION['role_id']??0);
    if($businessId<=0||$roleId<=0)return false;
    $sql="SELECT rp.`{$field}` FROM role_permissions rp INNER JOIN permissions p ON p.id=rp.permission_id WHERE rp.business_id=? AND rp.role_id=? AND p.is_active=1 AND p.permission_code IN ('perm.staff.users','perm.staff') ORDER BY FIELD(p.permission_code,'perm.staff.users','perm.staff') LIMIT 1";
    $stmt=$conn->prepare($sql);if(!$stmt)return false;$stmt->bind_param('ii',$businessId,$roleId);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();return (int)($row[$field]??0)===1;
}

function audit(mysqli $conn, int $businessId, int $branchId, int $userId, string $action, ?int $referenceId, string $description, $oldValues = null, $newValues = null): void
{
    $check=$conn->query("SHOW TABLES LIKE 'audit_logs'");if(!$check||$check->num_rows===0)return;
    $oldJson=$oldValues===null?null:json_encode($oldValues,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $newJson=$newValues===null?null:json_encode($newValues,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $ip=(string)($_SERVER['REMOTE_ADDR']??'');$agent=(string)($_SERVER['HTTP_USER_AGENT']??'');
    $stmt=$conn->prepare("INSERT INTO audit_logs (business_id,branch_id,user_id,module_code,action_type,reference_table,reference_id,description,old_values_json,new_values_json,ip_address,user_agent) VALUES (?,?,?,'staff.users',?,'users',?,?,?,?,?,?)");
    if($stmt){$stmt->bind_param('iiississss',$businessId,$branchId,$userId,$action,$referenceId,$description,$oldJson,$newJson,$ip,$agent);$stmt->execute();$stmt->close();}
}

$action=(string)($_POST['action']??'');
$businessId=(int)($_SESSION['business_id']??0);
$currentUserId=(int)($_SESSION['user_id']??0);
$currentBranchId=(int)($_SESSION['branch_id']??0);
$isPlatformAdmin=(($_SESSION['user_type']??'')==='Platform Admin');
if($businessId<=0)respond(false,'A valid business must be selected.',[],403);

if($action==='get'){
    if(!hasPermission($conn,'view')&&!hasPermission($conn,'open'))respond(false,'You do not have permission to view users.',[],403);
    $userId=(int)($_POST['user_id']??0);
    $stmt=$conn->prepare("SELECT id,default_branch_id,employee_code,full_name,username,email,mobile,profile_photo_path,user_type,must_change_password,is_active FROM users WHERE id=? AND business_id=? LIMIT 1");$stmt->bind_param('ii',$userId,$businessId);$stmt->execute();$user=$stmt->get_result()->fetch_assoc();$stmt->close();if(!$user)respond(false,'User not found.',[],404);
    $roleIds=[];$primaryRoleId=0;$stmt=$conn->prepare("SELECT role_id,is_primary FROM user_roles WHERE business_id=? AND user_id=?");$stmt->bind_param('ii',$businessId,$userId);$stmt->execute();$res=$stmt->get_result();while($r=$res->fetch_assoc()){$roleIds[]=(int)$r['role_id'];if((int)$r['is_primary']===1)$primaryRoleId=(int)$r['role_id'];}$stmt->close();
    $branchIds=[];$canSwitch=0;$stmt=$conn->prepare("SELECT branch_id,can_switch_branch FROM user_branch_access WHERE business_id=? AND user_id=?");$stmt->bind_param('ii',$businessId,$userId);$stmt->execute();$res=$stmt->get_result();while($r=$res->fetch_assoc()){$branchIds[]=(int)$r['branch_id'];if((int)$r['can_switch_branch']===1)$canSwitch=1;}$stmt->close();
    respond(true,'User loaded.',['user'=>$user,'role_ids'=>$roleIds,'primary_role_id'=>$primaryRoleId,'branch_ids'=>$branchIds,'can_switch_branch'=>$canSwitch]);
}

if($action==='save'){
    $userId=(int)($_POST['user_id']??0);$isNew=$userId<=0;
    if($isNew&&!hasPermission($conn,'create'))respond(false,'You do not have permission to create users.',[],403);
    if(!$isNew&&!hasPermission($conn,'update'))respond(false,'You do not have permission to update users.',[],403);

    $fullName=trim((string)($_POST['full_name']??''));$username=trim((string)($_POST['username']??''));$employeeCode=trim((string)($_POST['employee_code']??''));$email=trim((string)($_POST['email']??''));$mobile=trim((string)($_POST['mobile']??''));$password=(string)($_POST['password']??'');$userType=(string)($_POST['user_type']??'Business User');$isActive=(int)($_POST['is_active']??1)===1?1:0;$mustChange=isset($_POST['must_change_password'])?1:0;$defaultBranchId=(int)($_POST['default_branch_id']??0);$canSwitch=isset($_POST['can_switch_branch'])?1:0;
    $roleIds=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['role_ids']??[])))));$branchIds=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['branch_ids']??[])))));$primaryRoleId=(int)($_POST['primary_role_id']??0);
    if($fullName===''||$username==='')respond(false,'Full name and username are required.');
    if(!preg_match('/^[A-Za-z0-9._-]{3,100}$/',$username))respond(false,'Username must be 3 to 100 characters and may contain letters, numbers, dot, underscore and hyphen.');
    if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))respond(false,'Enter a valid email address.');
    if($mobile!==''&&!preg_match('/^[0-9+()\-\s]{7,20}$/',$mobile))respond(false,'Enter a valid mobile number.');
    if($isNew&&strlen($password)<8)respond(false,'Password must contain at least 8 characters.');
    if(!$isNew&&$password!==''&&strlen($password)<8)respond(false,'Password must contain at least 8 characters.');
    if(!$roleIds)respond(false,'Select at least one role.');if(!$branchIds)respond(false,'Select at least one branch.');if(!in_array($primaryRoleId,$roleIds,true))$primaryRoleId=$roleIds[0];if(!in_array($defaultBranchId,$branchIds,true))$defaultBranchId=$branchIds[0];
    if(!$isPlatformAdmin)$userType='Business User';

    $marks=implode(',',array_fill(0,count($roleIds),'?'));$types='i'.str_repeat('i',count($roleIds));$values=array_merge([$businessId],$roleIds);$stmt=$conn->prepare("SELECT COUNT(*) AS c FROM roles WHERE (business_id=? OR business_id IS NULL) AND is_active=1 AND id IN ($marks)");$stmt->bind_param($types,...$values);$stmt->execute();$count=(int)$stmt->get_result()->fetch_assoc()['c'];$stmt->close();if($count!==count($roleIds))respond(false,'One or more selected roles are invalid.');
    $marks=implode(',',array_fill(0,count($branchIds),'?'));$types='i'.str_repeat('i',count($branchIds));$values=array_merge([$businessId],$branchIds);$stmt=$conn->prepare("SELECT COUNT(*) AS c FROM branches WHERE business_id=? AND is_active=1 AND id IN ($marks)");$stmt->bind_param($types,...$values);$stmt->execute();$count=(int)$stmt->get_result()->fetch_assoc()['c'];$stmt->close();if($count!==count($branchIds))respond(false,'One or more selected branches are invalid.');

    $stmt=$conn->prepare("SELECT id FROM users WHERE business_id=? AND username=? AND id<>? LIMIT 1");$stmt->bind_param('isi',$businessId,$username,$userId);$stmt->execute();$duplicate=$stmt->get_result()->fetch_assoc();$stmt->close();if($duplicate)respond(false,'This username is already used in the selected business.');
    if($employeeCode!==''){$stmt=$conn->prepare("SELECT id FROM users WHERE business_id=? AND employee_code=? AND id<>? LIMIT 1");$stmt->bind_param('isi',$businessId,$employeeCode,$userId);$stmt->execute();$duplicate=$stmt->get_result()->fetch_assoc();$stmt->close();if($duplicate)respond(false,'This employee code is already used.');}

    $old=null;$existingPhoto=trim((string)($_POST['existing_photo_path']??''));$photoPath=$existingPhoto;
    if(!$isNew){$stmt=$conn->prepare("SELECT * FROM users WHERE id=? AND business_id=? LIMIT 1");$stmt->bind_param('ii',$userId,$businessId);$stmt->execute();$old=$stmt->get_result()->fetch_assoc();$stmt->close();if(!$old)respond(false,'User not found.',[],404);$photoPath=(string)($old['profile_photo_path']??'');if($userId===$currentUserId&&$isActive===0)respond(false,'You cannot deactivate your own account.');}
    if(isset($_POST['remove_photo']))$photoPath='';
    if(!empty($_FILES['profile_photo']['name'])){$file=$_FILES['profile_photo'];if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)respond(false,'Unable to upload profile photo.');if((int)$file['size']>2*1024*1024)respond(false,'Profile photo must not exceed 2 MB.');$mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);$allowed=['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'];if(!isset($allowed[$mime]))respond(false,'Profile photo must be PNG, JPG or WEBP.');$dir=dirname(__DIR__).'/uploads/users';if(!is_dir($dir)&&!mkdir($dir,0755,true)&&!is_dir($dir))respond(false,'Unable to create user upload directory.');$name='business-'.$businessId.'-user-'.time().'-'.bin2hex(random_bytes(3)).'.'.$allowed[$mime];if(!move_uploaded_file($file['tmp_name'],$dir.'/'.$name))respond(false,'Unable to save profile photo.');$photoPath='uploads/users/'.$name;}

    $conn->begin_transaction();
    try{
        if($isNew){$hash=password_hash($password,PASSWORD_DEFAULT);$stmt=$conn->prepare("INSERT INTO users (business_id,default_branch_id,employee_code,full_name,username,email,mobile,password_hash,profile_photo_path,user_type,must_change_password,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");$emp=$employeeCode!==''?$employeeCode:null;$mail=$email!==''?$email:null;$mob=$mobile!==''?$mobile:null;$photo=$photoPath!==''?$photoPath:null;$stmt->bind_param('iissssssssii',$businessId,$defaultBranchId,$emp,$fullName,$username,$mail,$mob,$hash,$photo,$userType,$mustChange,$isActive);if(!$stmt->execute())throw new Exception($stmt->error);$userId=(int)$stmt->insert_id;$stmt->close();}
        else{if($password!==''){$hash=password_hash($password,PASSWORD_DEFAULT);$stmt=$conn->prepare("UPDATE users SET default_branch_id=?,employee_code=?,full_name=?,username=?,email=?,mobile=?,password_hash=?,profile_photo_path=?,user_type=?,must_change_password=?,is_active=? WHERE id=? AND business_id=?");$emp=$employeeCode!==''?$employeeCode:null;$mail=$email!==''?$email:null;$mob=$mobile!==''?$mobile:null;$photo=$photoPath!==''?$photoPath:null;$stmt->bind_param('issssssssiiii',$defaultBranchId,$emp,$fullName,$username,$mail,$mob,$hash,$photo,$userType,$mustChange,$isActive,$userId,$businessId);}else{$stmt=$conn->prepare("UPDATE users SET default_branch_id=?,employee_code=?,full_name=?,username=?,email=?,mobile=?,profile_photo_path=?,user_type=?,must_change_password=?,is_active=? WHERE id=? AND business_id=?");$emp=$employeeCode!==''?$employeeCode:null;$mail=$email!==''?$email:null;$mob=$mobile!==''?$mobile:null;$photo=$photoPath!==''?$photoPath:null;$stmt->bind_param('isssssssiiii',$defaultBranchId,$emp,$fullName,$username,$mail,$mob,$photo,$userType,$mustChange,$isActive,$userId,$businessId);}if(!$stmt->execute())throw new Exception($stmt->error);$stmt->close();}
        $stmt=$conn->prepare("DELETE FROM user_roles WHERE business_id=? AND user_id=?");$stmt->bind_param('ii',$businessId,$userId);$stmt->execute();$stmt->close();$stmt=$conn->prepare("INSERT INTO user_roles (business_id,user_id,role_id,is_primary) VALUES (?,?,?,?)");foreach($roleIds as $roleId){$primary=$roleId===$primaryRoleId?1:0;$stmt->bind_param('iiii',$businessId,$userId,$roleId,$primary);if(!$stmt->execute())throw new Exception($stmt->error);}$stmt->close();
        $stmt=$conn->prepare("DELETE FROM user_branch_access WHERE business_id=? AND user_id=?");$stmt->bind_param('ii',$businessId,$userId);$stmt->execute();$stmt->close();$stmt=$conn->prepare("INSERT INTO user_branch_access (business_id,user_id,branch_id,is_default,can_switch_branch) VALUES (?,?,?,?,?)");foreach($branchIds as $branchId){$default=$branchId===$defaultBranchId?1:0;$stmt->bind_param('iiiii',$businessId,$userId,$branchId,$default,$canSwitch);if(!$stmt->execute())throw new Exception($stmt->error);}$stmt->close();
        $conn->commit();
    }catch(Throwable $e){$conn->rollback();respond(false,'Unable to save user: '.$e->getMessage(),[],500);}
    $new=['full_name'=>$fullName,'username'=>$username,'employee_code'=>$employeeCode,'email'=>$email,'mobile'=>$mobile,'user_type'=>$userType,'is_active'=>$isActive,'must_change_password'=>$mustChange,'default_branch_id'=>$defaultBranchId,'role_ids'=>$roleIds,'branch_ids'=>$branchIds];audit($conn,$businessId,$currentBranchId,$currentUserId,$isNew?'Create':'Update',$userId,($isNew?'Created':'Updated').' user '.$fullName,$old,$new);respond(true,$isNew?'User created successfully.':'User updated successfully.',['user_id'=>$userId]);
}

if($action==='toggle'){
    if(!hasPermission($conn,'update'))respond(false,'You do not have permission to update users.',[],403);$userId=(int)($_POST['user_id']??0);$active=(int)($_POST['is_active']??0)===1?1:0;if($userId===$currentUserId&&$active===0)respond(false,'You cannot deactivate your own account.');$stmt=$conn->prepare("UPDATE users SET is_active=? WHERE id=? AND business_id=?");$stmt->bind_param('iii',$active,$userId,$businessId);$stmt->execute();$affected=$stmt->affected_rows;$stmt->close();if($affected<1)respond(false,'User not found or status was unchanged.');audit($conn,$businessId,$currentBranchId,$currentUserId,'Update',$userId,$active?'Activated user':'Deactivated user',null,['is_active'=>$active]);respond(true,$active?'User activated successfully.':'User deactivated successfully.');
}

if($action==='reset_password'){
    if(!hasPermission($conn,'update'))respond(false,'You do not have permission to reset passwords.',[],403);$userId=(int)($_POST['user_id']??0);$password=(string)($_POST['new_password']??'');$mustChange=isset($_POST['must_change_password'])?1:0;if(strlen($password)<8)respond(false,'Password must contain at least 8 characters.');$hash=password_hash($password,PASSWORD_DEFAULT);$stmt=$conn->prepare("UPDATE users SET password_hash=?,must_change_password=? WHERE id=? AND business_id=?");$stmt->bind_param('siii',$hash,$mustChange,$userId,$businessId);$stmt->execute();$affected=$stmt->affected_rows;$stmt->close();if($affected<1)respond(false,'User not found or password was unchanged.');audit($conn,$businessId,$currentBranchId,$currentUserId,'Update',$userId,'Reset user password',null,['must_change_password'=>$mustChange]);respond(true,'Password reset successfully.');
}

if($action==='delete'){
    if(!hasPermission($conn,'delete'))respond(false,'You do not have permission to delete users.',[],403);$userId=(int)($_POST['user_id']??0);if($userId===$currentUserId)respond(false,'You cannot delete your own account.');$stmt=$conn->prepare("SELECT full_name FROM users WHERE id=? AND business_id=? LIMIT 1");$stmt->bind_param('ii',$userId,$businessId);$stmt->execute();$user=$stmt->get_result()->fetch_assoc();$stmt->close();if(!$user)respond(false,'User not found.',[],404);try{$stmt=$conn->prepare("DELETE FROM users WHERE id=? AND business_id=?");$stmt->bind_param('ii',$userId,$businessId);$stmt->execute();$stmt->close();}catch(Throwable $e){respond(false,'This user is linked to existing transactions and cannot be deleted. Deactivate the user instead.',[],409);}audit($conn,$businessId,$currentBranchId,$currentUserId,'Delete',$userId,'Deleted user '.$user['full_name'],$user,null);respond(true,'User deleted successfully.');
}

respond(false,'Invalid action.',[],400);
