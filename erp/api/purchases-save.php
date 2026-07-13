<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

function respond(bool $success, string $message, array $extra = [], int $status = 200): void {
    http_response_code($status);
    echo json_encode(array_merge(['success'=>$success,'message'=>$message],$extra), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

$configCandidates=[dirname(__DIR__).'/config/config.php',dirname(__DIR__).'/config.php',dirname(__DIR__).'/includes/config.php',dirname(__DIR__).'/super-admin/includes/config.php'];
foreach($configCandidates as $f){if(is_file($f)){require_once $f;break;}}
if(!isset($conn)||!($conn instanceof mysqli)) respond(false,'Database configuration is not available.',[],500);
$conn->set_charset('utf8mb4');
if(empty($_SESSION['user_id'])) respond(false,'Your session has expired. Please log in again.',[],401);
if($_SERVER['REQUEST_METHOD']!=='POST') respond(false,'Invalid request method.',[],405);
if(!hash_equals((string)($_SESSION['purchases_csrf']??''),(string)($_POST['csrf_token']??''))) respond(false,'Invalid or expired request token. Refresh and try again.',[],419);

function hasPermission(mysqli $conn,string $action):bool{
    if(($_SESSION['user_type']??'')==='Platform Admin') return true;
    $map=['open'=>'can_open','view'=>'can_view','create'=>'can_create','update'=>'can_update','delete'=>'can_delete'];
    $field=$map[$action]??''; if($field==='') return false;
    $permissions=$_SESSION['permissions']??[];
    foreach(['perm.purchases.list','perm.purchases'] as $key){if(isset($permissions[$key][$field])) return (int)$permissions[$key][$field]===1;}
    $businessId=(int)($_SESSION['business_id']??0);$roleId=(int)($_SESSION['role_id']??0);if($businessId<=0||$roleId<=0)return false;
    $sql="SELECT rp.`{$field}` FROM role_permissions rp INNER JOIN permissions p ON p.id=rp.permission_id WHERE rp.business_id=? AND rp.role_id=? AND p.is_active=1 AND p.permission_code IN ('perm.purchases.list','perm.purchases') ORDER BY FIELD(p.permission_code,'perm.purchases.list','perm.purchases') LIMIT 1";
    $stmt=$conn->prepare($sql);if(!$stmt)return false;$stmt->bind_param('ii',$businessId,$roleId);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();return (int)($row[$field]??0)===1;
}
function audit(mysqli $conn,int $businessId,int $branchId,int $userId,string $action,int $referenceId,string $description,$old=null,$new=null):void{
    $stmt=$conn->prepare("INSERT INTO audit_logs (business_id,branch_id,user_id,module_code,action_type,reference_table,reference_id,description,old_values_json,new_values_json,ip_address,user_agent) VALUES (?,?,?,'purchases',?,'purchases',?,?,?,?,?,?,?)");
    if(!$stmt)return;$oldJson=$old===null?null:json_encode($old,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$newJson=$new===null?null:json_encode($new,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$ip=(string)($_SERVER['REMOTE_ADDR']??'');$ua=(string)($_SERVER['HTTP_USER_AGENT']??'');$stmt->bind_param('iiisssssss',$businessId,$branchId,$userId,$action,$referenceId,$description,$oldJson,$newJson,$ip,$ua);$stmt->execute();$stmt->close();
}

$action=(string)($_POST['action']??'');$businessId=(int)($_SESSION['business_id']??0);$branchId=(int)($_SESSION['branch_id']??0);$userId=(int)($_SESSION['user_id']??0);
if($businessId<=0||$branchId<=0) respond(false,'A valid business and branch must be selected.',[],403);
if($action!=='delete') respond(false,'Invalid action.',[],400);
if(!hasPermission($conn,'delete')) respond(false,'You do not have permission to delete purchases.',[],403);
$purchaseId=(int)($_POST['purchase_id']??0);if($purchaseId<=0)respond(false,'Invalid purchase.',[],422);

$conn->begin_transaction();
try{
    $stmt=$conn->prepare('SELECT * FROM purchases WHERE id=? AND business_id=? AND branch_id=? FOR UPDATE');
    if(!$stmt) throw new RuntimeException('Unable to prepare purchase lookup: '.$conn->error);
    $stmt->bind_param('iii',$purchaseId,$businessId,$branchId);$stmt->execute();$purchase=$stmt->get_result()->fetch_assoc();$stmt->close();
    if(!$purchase) throw new RuntimeException('Purchase not found.');

    $stmt=$conn->prepare('SELECT COUNT(*) cnt FROM purchase_returns WHERE purchase_id=? AND business_id=? AND branch_id=?');
    if($stmt){$stmt->bind_param('iii',$purchaseId,$businessId,$branchId);$stmt->execute();$cnt=(int)($stmt->get_result()->fetch_assoc()['cnt']??0);$stmt->close();if($cnt>0)throw new RuntimeException('This purchase has return entries and cannot be deleted.');}

    $items=[];$stmt=$conn->prepare('SELECT * FROM purchase_items WHERE purchase_id=? AND business_id=? AND branch_id=?');
    if(!$stmt) throw new RuntimeException('Unable to prepare purchase items: '.$conn->error);
    $stmt->bind_param('iii',$purchaseId,$businessId,$branchId);$stmt->execute();$res=$stmt->get_result();while($r=$res->fetch_assoc())$items[]=$r;$stmt->close();

    if((string)$purchase['workflow_status']==='Posted'){
        foreach($items as $item){$productId=(int)($item['product_id']??0);if($productId<=0)continue;$qty=(float)$item['quantity'];$gross=(float)$item['gross_weight'];$net=(float)$item['net_weight'];$value=(float)$item['line_total'];
            $stmt=$conn->prepare('UPDATE product_stock SET quantity=GREATEST(quantity-?,0),gross_weight=GREATEST(gross_weight-?,0),net_weight=GREATEST(net_weight-?,0),stock_value=GREATEST(stock_value-?,0) WHERE business_id=? AND branch_id=? AND product_id=?');
            if(!$stmt)throw new RuntimeException('Unable to reverse product stock: '.$conn->error);$stmt->bind_param('ddddiii',$qty,$gross,$net,$value,$businessId,$branchId,$productId);$stmt->execute();$stmt->close();
        }
        $stmt=$conn->prepare("DELETE FROM stock_movements WHERE business_id=? AND branch_id=? AND reference_table='purchases' AND reference_id=?");
        if(!$stmt)throw new RuntimeException('Unable to remove stock movements: '.$conn->error);$stmt->bind_param('iii',$businessId,$branchId,$purchaseId);$stmt->execute();$stmt->close();
    }

    $balance=(float)($purchase['balance_amount']??0);$supplierId=(int)$purchase['supplier_id'];
    if($supplierId>0&&$balance>0){$stmt=$conn->prepare('UPDATE suppliers SET current_balance=GREATEST(current_balance-?,0) WHERE id=? AND business_id=?');if($stmt){$stmt->bind_param('dii',$balance,$supplierId,$businessId);$stmt->execute();$stmt->close();}}

    $stmt=$conn->prepare('DELETE FROM purchase_items WHERE purchase_id=? AND business_id=? AND branch_id=?');if(!$stmt)throw new RuntimeException('Unable to delete purchase items: '.$conn->error);$stmt->bind_param('iii',$purchaseId,$businessId,$branchId);$stmt->execute();$stmt->close();
    $stmt=$conn->prepare('DELETE FROM purchases WHERE id=? AND business_id=? AND branch_id=? LIMIT 1');if(!$stmt)throw new RuntimeException('Unable to delete purchase: '.$conn->error);$stmt->bind_param('iii',$purchaseId,$businessId,$branchId);$stmt->execute();if($stmt->affected_rows<1)throw new RuntimeException('Purchase was not deleted.');$stmt->close();

    audit($conn,$businessId,$branchId,$userId,'Delete',$purchaseId,'Deleted purchase '.(string)$purchase['purchase_no'],$purchase,null);
    $conn->commit();respond(true,'Purchase deleted successfully.');
}catch(Throwable $e){$conn->rollback();respond(false,$e->getMessage(),[],422);}
