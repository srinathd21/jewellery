<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');

function respond(bool $success, string $message, array $extra = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode(array_merge(['success'=>$success,'message'=>$message],$extra), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
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
if (!isset($conn) || !($conn instanceof mysqli)) respond(false,'Database configuration is not available.',[],500);
$conn->set_charset('utf8mb4');
if (empty($_SESSION['user_id'])) respond(false,'Your session has expired. Please login again.',[],401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(false,'Invalid request method.',[],405);
if (!hash_equals((string)($_SESSION['branches_csrf'] ?? ''),(string)($_POST['csrf_token'] ?? ''))) respond(false,'Invalid or expired request token.',[],419);

function allowed(mysqli $conn, string $action): bool
{
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin') return true;
    $map=['create'=>'can_create','update'=>'can_update','delete'=>'can_delete'];
    $field=$map[$action]??'';
    if($field==='') return false;
    $sp=$_SESSION['permissions']['perm.settings.branches'][$field]??null;
    if($sp!==null) return (int)$sp===1;
    $businessId=(int)($_SESSION['business_id']??0);$roleId=(int)($_SESSION['role_id']??0);
    $sql="SELECT rp.`{$field}` FROM role_permissions rp INNER JOIN permissions p ON p.id=rp.permission_id WHERE rp.business_id=? AND rp.role_id=? AND p.permission_code='perm.settings.branches' AND p.is_active=1 LIMIT 1";
    $stmt=$conn->prepare($sql);if(!$stmt)return false;$stmt->bind_param('ii',$businessId,$roleId);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();return (int)($row[$field]??0)===1;
}

$businessId=(int)($_SESSION['business_id']??0);
$userId=(int)($_SESSION['user_id']??0);
$action=(string)($_POST['action']??'save');
$branchId=(int)($_POST['branch_id']??0);

if($action==='delete'){
    if(!allowed($conn,'delete')) respond(false,'You do not have permission to delete branches.',[],403);
    if($branchId<=0) respond(false,'Invalid branch selected.',[],422);
    $stmt=$conn->prepare('SELECT is_default FROM branches WHERE id=? AND business_id=? LIMIT 1');$stmt->bind_param('ii',$branchId,$businessId);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();
    if(!$row) respond(false,'Branch not found.',[],404);
    if((int)$row['is_default']===1) respond(false,'The default branch cannot be deleted.',[],422);
    $stmt=$conn->prepare('DELETE FROM branches WHERE id=? AND business_id=?');$stmt->bind_param('ii',$branchId,$businessId);
    if(!$stmt->execute()){ $msg=str_contains($stmt->error,'foreign key')?'This branch is already used in transactions and cannot be deleted.':'Unable to delete branch: '.$stmt->error;$stmt->close();respond(false,$msg,[],422);} $stmt->close();respond(true,'Branch deleted successfully.');
}

if($branchId>0){if(!allowed($conn,'update')) respond(false,'You do not have permission to update branches.',[],403);}else{if(!allowed($conn,'create')) respond(false,'You do not have permission to create branches.',[],403);}

$branchCode=strtoupper(trim((string)($_POST['branch_code']??'')));
$branchName=trim((string)($_POST['branch_name']??''));
$branchType=(string)($_POST['branch_type']??'Showroom');
$contactPerson=trim((string)($_POST['contact_person']??''));
$mobile=trim((string)($_POST['mobile']??''));
$email=trim((string)($_POST['email']??''));
$address1=trim((string)($_POST['address_line1']??''));
$address2=trim((string)($_POST['address_line2']??''));
$city=trim((string)($_POST['city']??''));
$district=trim((string)($_POST['district']??''));
$state=trim((string)($_POST['state']??''));
$pincode=trim((string)($_POST['pincode']??''));
$country=trim((string)($_POST['country']??'India'));
$gstin=strtoupper(trim((string)($_POST['gstin']??'')));
$isDefault=isset($_POST['is_default'])?1:0;
$isActive=isset($_POST['is_active'])?1:0;
$types=['Head Office','Showroom','Warehouse','Office','Other'];

if($branchCode===''||$branchName==='') respond(false,'Branch code and branch name are required.',[],422);
if(!in_array($branchType,$types,true)) respond(false,'Invalid branch type.',[],422);
if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL)) respond(false,'Enter a valid email address.',[],422);
if($gstin!==''&&!preg_match('/^[0-9A-Z]{15}$/',$gstin)) respond(false,'GSTIN must contain exactly 15 letters and numbers.',[],422);

$stmt=$conn->prepare('SELECT id FROM branches WHERE business_id=? AND branch_code=? AND id<>? LIMIT 1');$stmt->bind_param('isi',$businessId,$branchCode,$branchId);$stmt->execute();$exists=$stmt->get_result()->fetch_assoc();$stmt->close();if($exists)respond(false,'Branch code already exists.',[],422);

$conn->begin_transaction();
try{
    if($isDefault===1){$stmt=$conn->prepare('UPDATE branches SET is_default=0 WHERE business_id=?');$stmt->bind_param('i',$businessId);$stmt->execute();$stmt->close();}
    if($branchId>0){
        $sql='UPDATE branches SET branch_code=?,branch_name=?,branch_type=?,contact_person=?,mobile=?,email=?,address_line1=?,address_line2=?,city=?,district=?,state=?,pincode=?,country=?,gstin=?,is_default=?,is_active=? WHERE id=? AND business_id=?';
        $stmt=$conn->prepare($sql);$stmt->bind_param('ssssssssssssssiiii',$branchCode,$branchName,$branchType,$contactPerson,$mobile,$email,$address1,$address2,$city,$district,$state,$pincode,$country,$gstin,$isDefault,$isActive,$branchId,$businessId);
        if(!$stmt->execute()) throw new RuntimeException($stmt->error);$stmt->close();$message='Branch updated successfully.';
    }else{
        $sql='INSERT INTO branches (business_id,branch_code,branch_name,branch_type,contact_person,mobile,email,address_line1,address_line2,city,district,state,pincode,country,gstin,is_default,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
        $stmt=$conn->prepare($sql);$stmt->bind_param('issssssssssssssii',$businessId,$branchCode,$branchName,$branchType,$contactPerson,$mobile,$email,$address1,$address2,$city,$district,$state,$pincode,$country,$gstin,$isDefault,$isActive);
        if(!$stmt->execute()) throw new RuntimeException($stmt->error);$branchId=$stmt->insert_id;$stmt->close();$message='Branch created successfully.';
    }
    if($isDefault===1){$_SESSION['branch_id']=$branchId;$_SESSION['branch_name']=$branchName;}
    $conn->commit();respond(true,$message,['branch_id'=>$branchId]);
}catch(Throwable $e){$conn->rollback();respond(false,'Unable to save branch: '.$e->getMessage(),[],500);}
