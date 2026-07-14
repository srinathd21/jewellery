<?php
header('Content-Type: application/json; charset=utf-8');
if(session_status()===PHP_SESSION_NONE)session_start();
date_default_timezone_set((string)($_SESSION['timezone']??'Asia/Kolkata'));
function respond(bool $success,string $message,array $extra=[],int $status=200):void{http_response_code($status);echo json_encode(array_merge(['success'=>$success,'message'=>$message],$extra),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
$root=dirname(__DIR__);foreach([$root.'/config/config.php',$root.'/config.php',$root.'/includes/config.php',$root.'/super-admin/includes/config.php'] as $file){if(is_file($file)){require_once $file;break;}}
if(!isset($conn)||!($conn instanceof mysqli))respond(false,'Database connection is not available.',[],500);
mysqli_report(MYSQLI_REPORT_OFF);$conn->set_charset('utf8mb4');
if(empty($_SESSION['user_id']))respond(false,'Your session has expired. Please log in again.',[],401);
$userId=(int)$_SESSION['user_id'];$businessId=(int)($_SESSION['business_id']??0);$branchId=(int)($_SESSION['branch_id']??$_SESSION['default_branch_id']??0);
if($businessId<=0||$branchId<=0)respond(false,'Business or branch session is missing.',[],403);
if($_SERVER['REQUEST_METHOD']!=='POST')respond(false,'Invalid request method.',[],405);
$sessionToken=(string)($_SESSION['stock_adjustment_csrf']??'');$postToken=(string)($_POST['csrf_token']??'');
if($sessionToken===''||$postToken===''||!hash_equals($sessionToken,$postToken))respond(false,'Invalid or expired request token. Refresh the page and try again.',[],419);
if(($_POST['action']??'')!=='save')respond(false,'Invalid action.',[],400);
$productId=(int)($_POST['product_id']??0);$mode=strtolower(trim((string)($_POST['adjustment_mode']??'add')));$qty=(float)($_POST['adjustment_qty']??0);$weight=(float)($_POST['adjustment_weight']??0);$remarks=trim((string)($_POST['remarks']??''));$movementInput=trim((string)($_POST['movement_date']??''));
if($productId<=0)respond(false,'Please select a product.',[],422);
if(!in_array($mode,['add','subtract','set'],true))respond(false,'Invalid adjustment mode.',[],422);
if($qty<0||$weight<0)respond(false,'Quantity and weight cannot be negative.',[],422);
if($qty==0.0&&$weight==0.0)respond(false,'Enter quantity or weight for adjustment.',[],422);
$movementDate=date('Y-m-d H:i:s');if($movementInput!==''&&strtotime($movementInput)!==false)$movementDate=date('Y-m-d H:i:s',strtotime($movementInput));
/*
 * Validate the product using the same schema-aware rules as the page.
 * Some installations do not use is_active, or store active status as text.
 */
function hasColumn(mysqli $conn,string $table,string $column): bool
{
    $safeTable=$conn->real_escape_string($table);
    $safeColumn=$conn->real_escape_string($column);
    $result=$conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    return $result&&$result->num_rows>0;
}

$productSql="SELECT id,product_name";
$productSql.=hasColumn($conn,'products','product_code')
    ? ",product_code"
    : ",'' AS product_code";
$productSql.=" FROM products WHERE id=?";

$productTypes='i';
$productParams=[$productId];

if(hasColumn($conn,'products','business_id')){
    $productSql.=" AND business_id=?";
    $productTypes.='i';
    $productParams[]=$businessId;
}

if(hasColumn($conn,'products','is_active')){
    $productSql.=" AND COALESCE(is_active,1)=1";
}elseif(hasColumn($conn,'products','status')){
    $productSql.=" AND (status=1 OR LOWER(TRIM(status))='active')";
}

$productSql.=" LIMIT 1";
$stmt=$conn->prepare($productSql);

if(!$stmt){
    respond(false,'Unable to prepare product validation: '.$conn->error,[],500);
}

$bind=[$productTypes];
foreach($productParams as $key=>$value){
    $bind[]=&$productParams[$key];
}
call_user_func_array([$stmt,'bind_param'],$bind);

if(!$stmt->execute()){
    $error=$stmt->error;
    $stmt->close();
    respond(false,'Unable to validate selected product: '.$error,[],500);
}

$product=$stmt->get_result()->fetch_assoc();
$stmt->close();

if(!$product){
    /*
     * A second ID-only lookup distinguishes a stale/invalid ID from a
     * business-session mismatch and gives a useful error instead of a false
     * generic "not found" result.
     */
    $stmt=$conn->prepare("SELECT id FROM products WHERE id=? LIMIT 1");
    $exists=null;

    if($stmt){
        $stmt->bind_param('i',$productId);
        $stmt->execute();
        $exists=$stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    if($exists){
        respond(
            false,
            'The selected product belongs to another business or is inactive. Refresh the product list and select it again.',
            [],
            409
        );
    }

    respond(
        false,
        'The selected product ID does not exist. Refresh the page and select a valid product.',
        [],
        404
    );
}
$conn->begin_transaction();
try{
$stmt=$conn->prepare("SELECT id,quantity,gross_weight,net_weight FROM product_stock WHERE business_id=? AND branch_id=? AND product_id=? LIMIT 1 FOR UPDATE");
if(!$stmt)throw new RuntimeException('Unable to prepare stock lookup: '.$conn->error);
$stmt->bind_param('iii',$businessId,$branchId,$productId);$stmt->execute();$stock=$stmt->get_result()->fetch_assoc();$stmt->close();
if(!$stock){$stmt=$conn->prepare("INSERT INTO product_stock (business_id,branch_id,product_id,quantity,gross_weight,net_weight,average_cost,stock_value) VALUES (?,?,?,0,0,0,0,0)");if(!$stmt)throw new RuntimeException('Unable to prepare stock row: '.$conn->error);$stmt->bind_param('iii',$businessId,$branchId,$productId);if(!$stmt->execute()){ $err=$stmt->error;$stmt->close();throw new RuntimeException('Unable to create stock row: '.$err);} $stmt->close();$stock=['quantity'=>0,'gross_weight'=>0,'net_weight'=>0];}
$currentQty=(float)$stock['quantity'];$currentGross=(float)$stock['gross_weight'];$currentNet=(float)$stock['net_weight'];$newQty=$currentQty;$newGross=$currentGross;$newNet=$currentNet;$qtyIn=0.0;$qtyOut=0.0;$weightIn=0.0;$weightOut=0.0;
if($mode==='add'){$newQty+=$qty;$newGross+=$weight;$newNet+=$weight;$qtyIn=$qty;$weightIn=$weight;$movementType='Adjustment In';}
elseif($mode==='subtract'){if($currentQty<=0&&$currentNet<=0)throw new DomainException('No stock is available to subtract. Use Add Stock or Set Exact Stock first.');if($qty>$currentQty)throw new DomainException('Only '.number_format($currentQty,3,'.','').' quantity is available.');if($weight>$currentNet)throw new DomainException('Only '.number_format($currentNet,3,'.','').' net weight is available.');$newQty-=$qty;$newGross=max(0,$newGross-$weight);$newNet-=$weight;$qtyOut=$qty;$weightOut=$weight;$movementType='Adjustment Out';}
else{$newQty=$qty;$newGross=$weight;$newNet=$weight;if($newQty>=$currentQty)$qtyIn=$newQty-$currentQty;else $qtyOut=$currentQty-$newQty;if($newNet>=$currentNet)$weightIn=$newNet-$currentNet;else $weightOut=$currentNet-$newNet;$movementType=($qtyIn>0||$weightIn>0)?'Adjustment In':'Adjustment Out';}
$stmt=$conn->prepare("UPDATE product_stock SET quantity=?,gross_weight=?,net_weight=?,updated_at=NOW() WHERE business_id=? AND branch_id=? AND product_id=? LIMIT 1");if(!$stmt)throw new RuntimeException('Unable to prepare stock update: '.$conn->error);$stmt->bind_param('dddiii',$newQty,$newGross,$newNet,$businessId,$branchId,$productId);if(!$stmt->execute()){ $err=$stmt->error;$stmt->close();throw new RuntimeException('Unable to update stock: '.$err);} $stmt->close();
$referenceTable='stock_adjustment';$referenceId=null;$remarksText=$remarks!==''?$remarks:'Manual stock adjustment';
$stmt=$conn->prepare("INSERT INTO stock_movements (business_id,branch_id,product_id,movement_date,movement_type,reference_table,reference_id,quantity_in,quantity_out,weight_in,weight_out,rate,value_amount,remarks,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,0,0,?,?)");if(!$stmt)throw new RuntimeException('Unable to prepare stock movement: '.$conn->error);
$stmt->bind_param('iiisssiddddsi',$businessId,$branchId,$productId,$movementDate,$movementType,$referenceTable,$referenceId,$qtyIn,$qtyOut,$weightIn,$weightOut,$remarksText,$userId);
if(!$stmt->execute()){ $err=$stmt->error;$stmt->close();throw new RuntimeException('Unable to create stock movement: '.$err);} $movementId=(int)$stmt->insert_id;$stmt->close();
$conn->commit();respond(true,'Stock adjustment saved successfully.',['product_id'=>$productId,'movement_id'=>$movementId,'current_stock'=>['quantity'=>$newQty,'gross_weight'=>$newGross,'net_weight'=>$newNet]]);
}catch(Throwable $e){$conn->rollback();$status=$e instanceof DomainException?409:500;$message=$e instanceof DomainException?$e->getMessage():'Unable to save stock adjustment: '.$e->getMessage();respond(false,$message,[],$status);}
