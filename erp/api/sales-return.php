<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_OFF);
ini_set('display_errors','0');

function respond(bool $success,string $message,array $extra=[],int $status=200):void{
 http_response_code($status);
 echo json_encode(array_merge(['success'=>$success,'message'=>$message],$extra),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
 exit;
}

register_shutdown_function(static function():void{
 $e=error_get_last();
 if(!$e||!in_array($e['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR],true))return;
 if(!headers_sent()){http_response_code(500);header('Content-Type: application/json; charset=utf-8');}
 echo json_encode(['success'=>false,'message'=>'Fatal API error: '.$e['message']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
});

foreach([
 dirname(__DIR__).'/config/config.php',
 dirname(__DIR__).'/config.php',
 dirname(__DIR__).'/includes/config.php',
 dirname(__DIR__).'/super-admin/includes/config.php'
] as $f){
 if(is_file($f)){require_once $f;break;}
}

if(!isset($conn)||!($conn instanceof mysqli))respond(false,'Database configuration is not available.',[],500);
$conn->set_charset('utf8mb4');
if(empty($_SESSION['user_id']))respond(false,'Your session has expired. Please log in again.',[],401);
if($_SERVER['REQUEST_METHOD']!=='POST')respond(false,'Invalid request method.',[],405);
if(!hash_equals((string)($_SESSION['sales_return_csrf']??''),(string)($_POST['csrf_token']??'')))respond(false,'Invalid or expired request token. Refresh the page.',[],419);

function permission(mysqli $conn,string $action):bool{
 if(($_SESSION['user_type']??'')==='Platform Admin')return true;
 $map=['open'=>'can_open','view'=>'can_view','create'=>'can_create'];$field=$map[$action]??'';
 if($field==='')return false;
 foreach(['perm.sales.return','perm.sales.returns','perm.sales','perm.billing'] as $code){
  if(isset($_SESSION['permissions'][$code][$field]))return(int)$_SESSION['permissions'][$code][$field]===1;
 }
 $b=(int)($_SESSION['business_id']??0);$r=(int)($_SESSION['role_id']??0);
 if($b<=0||$r<=0)return false;
 $sql="SELECT rp.`{$field}` FROM role_permissions rp INNER JOIN permissions p ON p.id=rp.permission_id WHERE rp.business_id=? AND rp.role_id=? AND p.is_active=1 AND p.permission_code IN ('perm.sales.return','perm.sales.returns','perm.sales','perm.billing') ORDER BY FIELD(p.permission_code,'perm.sales.return','perm.sales.returns','perm.sales','perm.billing') LIMIT 1";
 $stmt=$conn->prepare($sql);if(!$stmt)return false;
 $stmt->bind_param('ii',$b,$r);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();
 return(int)($row[$field]??0)===1;
}

function tableExists(mysqli $conn,string $table):bool{
 $safe=$conn->real_escape_string($table);
 $r=$conn->query("SHOW TABLES LIKE '{$safe}'");
 return$r&&$r->num_rows>0;
}

function generateReturnNo(mysqli $conn,int $businessId):string{
 $stmt=$conn->prepare('SELECT COUNT(*) AS cnt FROM sales_returns WHERE business_id=?');
 if(!$stmt)return'SR'.date('ymd').str_pad((string)random_int(1,9999),4,'0',STR_PAD_LEFT);
 $stmt->bind_param('i',$businessId);$stmt->execute();$n=(int)($stmt->get_result()->fetch_assoc()['cnt']??0)+1;$stmt->close();
 return'SR'.date('ymd').str_pad((string)$n,4,'0',STR_PAD_LEFT);
}

function audit(mysqli $conn,int $b,int $br,int $u,int $id,string $no):void{
 if(!tableExists($conn,'audit_logs'))return;
 $stmt=$conn->prepare("INSERT INTO audit_logs (business_id,branch_id,user_id,module_code,action_type,reference_table,reference_id,description,new_values_json,ip_address,user_agent) VALUES (?,?,?,'sales.return','Create','sales_returns',?,?,?,?,?,?)");
 if(!$stmt)return;
 $desc='Created sales return '.$no;$json=json_encode(['return_no'=>$no],JSON_UNESCAPED_UNICODE);
 $ip=(string)($_SERVER['REMOTE_ADDR']??'');$ua=(string)($_SERVER['HTTP_USER_AGENT']??'');
 $stmt->bind_param('iiiissss',$b,$br,$u,$id,$desc,$json,$ip,$ua);$stmt->execute();$stmt->close();
}

$action=(string)($_POST['action']??'');
$b=(int)($_SESSION['business_id']??0);
$sessionBranch=(int)($_SESSION['branch_id']??($_SESSION['default_branch_id']??0));
$u=(int)($_SESSION['user_id']??0);
if($b<=0||$sessionBranch<=0)respond(false,'A valid business and branch must be selected.',[],403);

if($action==='search_sales'){
 if(!permission($conn,'view')&&!permission($conn,'open')&&!permission($conn,'create'))respond(false,'You do not have permission to view sales.',[],403);
 $search=trim((string)($_POST['search']??''));
 $like='%'.$search.'%';
 $sql="SELECT s.id,s.invoice_no,s.invoice_date,s.customer_name,s.customer_mobile,s.grand_total,s.branch_id
       FROM sales s
       WHERE s.business_id=? AND s.workflow_status='Posted'
       AND EXISTS(SELECT 1 FROM sale_items si WHERE si.sale_id=s.id AND si.business_id=s.business_id)
       AND (?='' OR s.invoice_no LIKE ? OR COALESCE(s.customer_name,'') LIKE ? OR COALESCE(s.customer_mobile,'') LIKE ?)
       ORDER BY s.invoice_date DESC,s.invoice_time DESC,s.id DESC LIMIT 50";
 $stmt=$conn->prepare($sql);
 if(!$stmt)respond(false,'Unable to prepare sale search: '.$conn->error,[],500);
 $stmt->bind_param('issss',$b,$search,$like,$like,$like);$stmt->execute();$r=$stmt->get_result();$sales=[];
 while($row=$r->fetch_assoc()){$row['invoice_date_display']=date('d-m-Y',strtotime($row['invoice_date']));$sales[]=$row;}
 $stmt->close();respond(true,'Sales loaded.',['sales'=>$sales]);
}

if($action==='load_sale'){
 if(!permission($conn,'view')&&!permission($conn,'open')&&!permission($conn,'create'))respond(false,'You do not have permission to view sales.',[],403);
 $saleId=(int)($_POST['sale_id']??0);if($saleId<=0)respond(false,'Invalid sale selected.');
 $stmt=$conn->prepare("SELECT * FROM sales WHERE id=? AND business_id=? AND workflow_status='Posted' LIMIT 1");
 $stmt->bind_param('ii',$saleId,$b);$stmt->execute();$sale=$stmt->get_result()->fetch_assoc();$stmt->close();
 if(!$sale)respond(false,'Sale not found or it is not posted.',[],404);
 $sale['invoice_date_display']=date('d-m-Y',strtotime($sale['invoice_date']));
 $sql="SELECT si.*,
       COALESCE((SELECT SUM(sri.quantity) FROM sales_return_items sri INNER JOIN sales_returns sr ON sr.id=sri.sales_return_id WHERE sri.sale_item_id=si.id AND sr.workflow_status<>'Cancelled'),0) AS returned_quantity
       FROM sale_items si WHERE si.sale_id=? AND si.business_id=? ORDER BY si.sort_order ASC,si.id ASC";
 $stmt=$conn->prepare($sql);if(!$stmt)respond(false,'Unable to prepare sale items: '.$conn->error,[],500);
 $stmt->bind_param('ii',$saleId,$b);$stmt->execute();$r=$stmt->get_result();$items=[];
 while($row=$r->fetch_assoc()){
  $row['returnable_quantity']=max(0,(float)$row['quantity']-(float)$row['returned_quantity']);
  $items[]=$row;
 }
 $stmt->close();
 $methods=[];$stmt=$conn->prepare('SELECT id,method_name FROM payment_methods WHERE business_id=? AND is_active=1 ORDER BY FIELD(method_code,\'CASH\',\'CARD\',\'UPI\'),method_name');
 if($stmt){$stmt->bind_param('i',$b);$stmt->execute();$r=$stmt->get_result();while($row=$r->fetch_assoc())$methods[]=$row;$stmt->close();}
 respond(true,'Sale loaded.',['sale'=>$sale,'items'=>$items,'payment_methods'=>$methods]);
}

if($action==='save'){
 if(!permission($conn,'create'))respond(false,'You do not have permission to create sales returns.',[],403);
 $saleId=(int)($_POST['sale_id']??0);$date=trim((string)($_POST['return_date']??''));
 $reason=trim((string)($_POST['reason']??''));$notes=trim((string)($_POST['notes']??''));
 $refundMethodId=(int)($_POST['refund_method_id']??0);$qtyInput=$_POST['return_qty']??[];
 if($saleId<=0||$date===''||$reason==='')respond(false,'Sale, return date and reason are required.');
 if(!is_array($qtyInput))respond(false,'Invalid return item data.');

 $stmt=$conn->prepare("SELECT * FROM sales WHERE id=? AND business_id=? AND workflow_status='Posted' LIMIT 1 FOR UPDATE");
 if(!$stmt)respond(false,'Unable to prepare sale check: '.$conn->error,[],500);
 $stmt->bind_param('ii',$saleId,$b);$stmt->execute();$sale=$stmt->get_result()->fetch_assoc();$stmt->close();
 if(!$sale)respond(false,'Selected sale not found or it is not posted.');
 $saleBranch=(int)$sale['branch_id'];

 $methodName='';
 if($refundMethodId>0){
  $stmt=$conn->prepare('SELECT method_name FROM payment_methods WHERE id=? AND business_id=? AND is_active=1 LIMIT 1');
  $stmt->bind_param('ii',$refundMethodId,$b);$stmt->execute();$m=$stmt->get_result()->fetch_assoc();$stmt->close();
  if(!$m)respond(false,'Invalid refund method.');$methodName=(string)$m['method_name'];
 }

 $sql="SELECT si.*,
       COALESCE((SELECT SUM(sri.quantity) FROM sales_return_items sri INNER JOIN sales_returns sr ON sr.id=sri.sales_return_id WHERE sri.sale_item_id=si.id AND sr.workflow_status<>'Cancelled'),0) AS returned_quantity,
       COALESCE(p.track_stock,0) AS track_stock
       FROM sale_items si LEFT JOIN products p ON p.id=si.product_id
       WHERE si.sale_id=? AND si.business_id=? ORDER BY si.id";
 $stmt=$conn->prepare($sql);if(!$stmt)respond(false,'Unable to prepare return items: '.$conn->error,[],500);
 $stmt->bind_param('ii',$saleId,$b);$stmt->execute();$r=$stmt->get_result();$items=[];$total=0.0;
 while($si=$r->fetch_assoc()){
  $id=(int)$si['id'];$qty=max(0,(float)($qtyInput[$id]??0));if($qty<=0)continue;
  $available=max(0,(float)$si['quantity']-(float)$si['returned_quantity']);
  if($qty>$available+0.0001){$stmt->close();respond(false,'Return quantity exceeds returnable quantity for '.$si['item_name'].'.');}
  $ratio=(float)$si['quantity']>0?$qty/(float)$si['quantity']:0;
  $net=round((float)$si['net_weight']*$ratio,3);
  $gross=round((float)$si['gross_weight']*$ratio,3);
  $amount=round((float)$si['line_total']*$ratio,2);
  $items[]=['row'=>$si,'qty'=>$qty,'net'=>$net,'gross'=>$gross,'amount'=>$amount];
  $total+=$amount;
 }
 $stmt->close();
 if(!$items)respond(false,'Please enter at least one valid return quantity.');

 $storedReason=$reason;
 if($methodName!=='')$storedReason.=' | Refund Method: '.$methodName;
 if($notes!=='')$storedReason.=' | Notes: '.$notes;

 $conn->begin_transaction();
 try{
  $returnNo=generateReturnNo($conn,$b);
  $customerId=(int)($sale['customer_id']??0);
  $stmt=$conn->prepare("INSERT INTO sales_returns (business_id,branch_id,sale_id,return_no,return_date,customer_id,total_amount,refund_amount,reason,workflow_status,created_by) VALUES (?,?,?,?,?,?,?,?,?,'Posted',?)");
  if(!$stmt)throw new Exception('Sales return insert prepare failed: '.$conn->error);
  $stmt->bind_param('iiissiddsi',$b,$saleBranch,$saleId,$returnNo,$date,$customerId,$total,$total,$storedReason,$u);
  if(!$stmt->execute())throw new Exception('Unable to save sales return: '.$stmt->error);
  $returnId=(int)$stmt->insert_id;$stmt->close();

  foreach($items as $it){
   $si=$it['row'];$productId=(int)($si['product_id']??0);
   $stmt=$conn->prepare('INSERT INTO sales_return_items (business_id,branch_id,sales_return_id,sale_item_id,product_id,quantity,net_weight,return_amount) VALUES (?,?,?,?,?,?,?,?)');
   if(!$stmt)throw new Exception('Return item insert prepare failed: '.$conn->error);
   $stmt->bind_param('iiiiiddd',$b,$saleBranch,$returnId,$si['id'],$productId,$it['qty'],$it['net'],$it['amount']);
   if(!$stmt->execute())throw new Exception('Unable to save return item: '.$stmt->error);
   $stmt->close();

   if($productId>0&&(int)$si['track_stock']===1){
    $stmt=$conn->prepare("INSERT INTO product_stock (business_id,branch_id,product_id,quantity,gross_weight,net_weight,average_cost,stock_value)
      VALUES (?,?,?,?,?,?,0,0)
      ON DUPLICATE KEY UPDATE quantity=quantity+VALUES(quantity),gross_weight=gross_weight+VALUES(gross_weight),net_weight=net_weight+VALUES(net_weight)");
    if(!$stmt)throw new Exception('Stock restore prepare failed: '.$conn->error);
    $stmt->bind_param('iiiddd',$b,$saleBranch,$productId,$it['qty'],$it['gross'],$it['net']);
    if(!$stmt->execute())throw new Exception('Unable to restore stock: '.$stmt->error);
    $stmt->close();

    $remarks='Sales return '.$returnNo;
    $rate=(float)$si['metal_rate'];
    $stmt=$conn->prepare("INSERT INTO stock_movements (business_id,branch_id,product_id,movement_type,reference_table,reference_id,quantity_in,quantity_out,weight_in,weight_out,rate,value_amount,remarks,created_by) VALUES (?,?,?,'Sales Return','sales_returns',?,?,0,?,0,?,?,?,?)");
    if(!$stmt)throw new Exception('Stock movement prepare failed: '.$conn->error);
    $stmt->bind_param('iiiiddddsi',$b,$saleBranch,$productId,$returnId,$it['qty'],$it['net'],$rate,$it['amount'],$remarks,$u);
    if(!$stmt->execute())throw new Exception('Unable to add stock movement: '.$stmt->error);
    $stmt->close();
   }
  }

  audit($conn,$b,$saleBranch,$u,$returnId,$returnNo);
  $conn->commit();
  respond(true,'Sales return created successfully.',['sales_return_id'=>$returnId,'return_no'=>$returnNo,'refund_amount'=>$total]);
 }catch(Throwable $e){
  $conn->rollback();respond(false,$e->getMessage(),[],500);
 }
}

respond(false,'Invalid action.',[],400);
