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
] as $f){if(is_file($f)){require_once $f;break;}}

if(!isset($conn)||!($conn instanceof mysqli))respond(false,'Database configuration is not available.',[],500);
$conn->set_charset('utf8mb4');
if(empty($_SESSION['user_id']))respond(false,'Your session has expired. Please log in again.',[],401);
if($_SERVER['REQUEST_METHOD']!=='POST')respond(false,'Invalid request method.',[],405);
if(!hash_equals((string)($_SESSION['customer_payment_csrf']??''),(string)($_POST['csrf_token']??'')))respond(false,'Invalid or expired request token. Refresh the page.',[],419);

function permission(mysqli $conn,string $action):bool{
 if(($_SESSION['user_type']??'')==='Platform Admin')return true;
 $map=['open'=>'can_open','view'=>'can_view','create'=>'can_create'];$field=$map[$action]??'';
 if($field==='')return false;
 foreach(['perm.customer.payments','perm.customer.payment','perm.sales','perm.billing'] as $code){
  if(isset($_SESSION['permissions'][$code][$field]))return(int)$_SESSION['permissions'][$code][$field]===1;
 }
 $b=(int)($_SESSION['business_id']??0);$r=(int)($_SESSION['role_id']??0);if($b<=0||$r<=0)return false;
 $sql="SELECT rp.`{$field}` FROM role_permissions rp INNER JOIN permissions p ON p.id=rp.permission_id WHERE rp.business_id=? AND rp.role_id=? AND p.is_active=1 AND p.permission_code IN ('perm.customer.payments','perm.customer.payment','perm.sales','perm.billing') ORDER BY FIELD(p.permission_code,'perm.customer.payments','perm.customer.payment','perm.sales','perm.billing') LIMIT 1";
 $stmt=$conn->prepare($sql);if(!$stmt)return false;$stmt->bind_param('ii',$b,$r);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();return(int)($row[$field]??0)===1;
}

function bindDynamic(mysqli_stmt $stmt,string $types,array &$params):void{
 if($types==='')return;
 if(strlen($types)!==count($params))throw new RuntimeException('Bind parameter mismatch. Types: '.strlen($types).', Values: '.count($params));
 $bind=[$types];foreach($params as $k=>$v)$bind[]=&$params[$k];call_user_func_array([$stmt,'bind_param'],$bind);
}

function tableExists(mysqli $conn,string $table):bool{
 $safe=$conn->real_escape_string($table);$r=$conn->query("SHOW TABLES LIKE '{$safe}'");return$r&&$r->num_rows>0;
}

function generateReceiptNo(mysqli $conn,int $businessId):string{
 $stmt=$conn->prepare('SELECT COUNT(*) AS cnt FROM customer_payments WHERE business_id=?');
 if(!$stmt)return'RCPT'.date('ymd').str_pad((string)random_int(1,9999),4,'0',STR_PAD_LEFT);
 $stmt->bind_param('i',$businessId);$stmt->execute();$n=(int)($stmt->get_result()->fetch_assoc()['cnt']??0)+1;$stmt->close();
 return'RCPT'.date('ymd').str_pad((string)$n,4,'0',STR_PAD_LEFT);
}

function audit(mysqli $conn,int $b,int $br,int $u,int $id,string $receiptNo):void{
 if(!tableExists($conn,'audit_logs'))return;
 $stmt=$conn->prepare("INSERT INTO audit_logs (business_id,branch_id,user_id,module_code,action_type,reference_table,reference_id,description,new_values_json,ip_address,user_agent) VALUES (?,?,?,'customer.payments','Create','customer_payments',?,?,?,?,?,?)");
 if(!$stmt)return;
 $desc='Created customer payment '.$receiptNo;$json=json_encode(['receipt_no'=>$receiptNo],JSON_UNESCAPED_UNICODE);
 $ip=(string)($_SERVER['REMOTE_ADDR']??'');$ua=(string)($_SERVER['HTTP_USER_AGENT']??'');
 $stmt->bind_param('iiiissss',$b,$br,$u,$id,$desc,$json,$ip,$ua);$stmt->execute();$stmt->close();
}

$action=(string)($_POST['action']??'');
$b=(int)($_SESSION['business_id']??0);
$br=(int)($_SESSION['branch_id']??($_SESSION['default_branch_id']??0));
$u=(int)($_SESSION['user_id']??0);
if($b<=0||$br<=0)respond(false,'A valid business and branch must be selected.',[],403);

if($action==='options'){
 if(!permission($conn,'view')&&!permission($conn,'open')&&!permission($conn,'create'))respond(false,'You do not have permission to load customer payments.',[],403);
 $customers=[];$stmt=$conn->prepare("SELECT id,customer_code,customer_name,mobile FROM customers WHERE business_id=? AND is_active=1 ORDER BY customer_name");
 if($stmt){$stmt->bind_param('i',$b);$stmt->execute();$r=$stmt->get_result();while($row=$r->fetch_assoc())$customers[]=$row;$stmt->close();}
 $methods=[];$stmt=$conn->prepare("SELECT id,method_name FROM payment_methods WHERE business_id=? AND is_active=1 ORDER BY method_name");
 if($stmt){$stmt->bind_param('i',$b);$stmt->execute();$r=$stmt->get_result();while($row=$r->fetch_assoc())$methods[]=$row;$stmt->close();}
 respond(true,'Options loaded.',['customers'=>$customers,'payment_methods'=>$methods]);
}

if($action==='customer_sales'){
 if(!permission($conn,'view')&&!permission($conn,'open')&&!permission($conn,'create'))respond(false,'You do not have permission to view customer sales.',[],403);
 $customerId=(int)($_POST['customer_id']??0);if($customerId<=0)respond(false,'Invalid customer selected.');
 $stmt=$conn->prepare("SELECT id,invoice_no,invoice_date,grand_total,paid_amount,balance_amount FROM sales WHERE business_id=? AND customer_id=? AND workflow_status='Posted' AND balance_amount>0 ORDER BY invoice_date DESC,id DESC");
 if(!$stmt)respond(false,'Unable to prepare sales list: '.$conn->error,[],500);
 $stmt->bind_param('ii',$b,$customerId);$stmt->execute();$r=$stmt->get_result();$sales=[];
 while($row=$r->fetch_assoc()){$row['invoice_date_display']=date('d-m-Y',strtotime($row['invoice_date']));$sales[]=$row;}
 $stmt->close();respond(true,'Customer sales loaded.',['sales'=>$sales]);
}

if($action==='save'){
 if(!permission($conn,'create'))respond(false,'You do not have permission to create customer payments.',[],403);
 $date=trim((string)($_POST['receipt_date']??''));$customerId=(int)($_POST['customer_id']??0);$saleId=(int)($_POST['sale_id']??0);
 $methodId=(int)($_POST['payment_method_id']??0);$reference=trim((string)($_POST['reference_no']??''));$amount=(float)($_POST['amount']??0);$remarks=trim((string)($_POST['remarks']??''));
 if($date===''||$customerId<=0||$methodId<=0||$amount<=0)respond(false,'Receipt date, customer, payment method and valid amount are required.');

 $stmt=$conn->prepare('SELECT id,customer_name FROM customers WHERE id=? AND business_id=? AND is_active=1 LIMIT 1');
 $stmt->bind_param('ii',$customerId,$b);$stmt->execute();$customer=$stmt->get_result()->fetch_assoc();$stmt->close();
 if(!$customer)respond(false,'Selected customer not found.');

 $stmt=$conn->prepare('SELECT id FROM payment_methods WHERE id=? AND business_id=? AND is_active=1 LIMIT 1');
 $stmt->bind_param('ii',$methodId,$b);$stmt->execute();$method=$stmt->get_result()->fetch_assoc();$stmt->close();
 if(!$method)respond(false,'Invalid payment method.');

 $sale=null;
 if($saleId>0){
  $stmt=$conn->prepare("SELECT id,branch_id,customer_id,invoice_no,grand_total,paid_amount,balance_amount,workflow_status FROM sales WHERE id=? AND business_id=? LIMIT 1 FOR UPDATE");
  $stmt->bind_param('ii',$saleId,$b);$stmt->execute();$sale=$stmt->get_result()->fetch_assoc();$stmt->close();
  if(!$sale)respond(false,'Linked sale not found.');
  if((int)$sale['customer_id']!==$customerId)respond(false,'Linked sale does not belong to the selected customer.');
  if($sale['workflow_status']==='Cancelled')respond(false,'Cannot receive payment against a cancelled sale.');
  if($amount>(float)$sale['balance_amount']+0.01)respond(false,'Payment amount cannot exceed the linked sale balance.');
 }

 $receiptNo=generateReceiptNo($conn,$b);
 $conn->begin_transaction();
 try{
  $saleIdParam=$saleId>0?$saleId:null;
  $stmt=$conn->prepare("INSERT INTO customer_payments (business_id,branch_id,customer_id,sale_id,receipt_no,receipt_date,payment_method_id,amount,reference_no,remarks,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
  if(!$stmt)throw new Exception('Customer payment insert prepare failed: '.$conn->error);
  $stmt->bind_param('iiiissidssi',$b,$br,$customerId,$saleIdParam,$receiptNo,$date,$methodId,$amount,$reference,$remarks,$u);
  if(!$stmt->execute())throw new Exception('Unable to save customer payment: '.$stmt->error);
  $paymentId=(int)$stmt->insert_id;$stmt->close();

  if($saleId>0){
   $stmt=$conn->prepare("INSERT INTO sale_payments (business_id,branch_id,sale_id,payment_method_id,amount,reference_no,payment_date,created_by) VALUES (?,?,?,?,?,?,NOW(),?)");
   if(!$stmt)throw new Exception('Sale payment insert prepare failed: '.$conn->error);
   $saleBranch=(int)$sale['branch_id'];
   $stmt->bind_param('iiiidsi',$b,$saleBranch,$saleId,$methodId,$amount,$reference,$u);
   if(!$stmt->execute())throw new Exception('Unable to save linked sale payment: '.$stmt->error);
   $stmt->close();

   $stmt=$conn->prepare("UPDATE sales SET paid_amount=LEAST(grand_total,paid_amount+?),balance_amount=GREATEST(grand_total-(paid_amount+?),0),payment_status=CASE WHEN (paid_amount+?)>=grand_total THEN 'Paid' WHEN (paid_amount+?)>0 THEN 'Partial' ELSE 'Unpaid' END WHERE id=? AND business_id=?");
   if(!$stmt)throw new Exception('Sale balance update prepare failed: '.$conn->error);
   $stmt->bind_param('ddddii',$amount,$amount,$amount,$amount,$saleId,$b);
   if(!$stmt->execute())throw new Exception('Unable to update linked sale balance: '.$stmt->error);
   $stmt->close();
  }

  audit($conn,$b,$br,$u,$paymentId,$receiptNo);
  $conn->commit();
  respond(true,'Customer payment saved successfully.',['payment_id'=>$paymentId,'receipt_no'=>$receiptNo]);
 }catch(Throwable $e){$conn->rollback();respond(false,$e->getMessage(),[],500);}
}

if($action==='list'){
 if(!permission($conn,'view')&&!permission($conn,'open'))respond(false,'You do not have permission to view customer payments.',[],403);
 $search=trim((string)($_POST['search']??''));$customerId=(int)($_POST['customer_id']??0);$from=trim((string)($_POST['date_from']??''));$to=trim((string)($_POST['date_to']??''));
 $page=max(1,(int)($_POST['page']??1));$perPage=max(5,min(100,(int)($_POST['per_page']??10)));
 $where=' WHERE cp.business_id=?';$types='i';$params=[$b];
 if($search!==''){$like='%'.$search.'%';$where.=" AND (cp.receipt_no LIKE ? OR c.customer_name LIKE ? OR c.customer_code LIKE ? OR c.mobile LIKE ? OR COALESCE(cp.reference_no,'') LIKE ? OR COALESCE(s.invoice_no,'') LIKE ?)";$types.='ssssss';array_push($params,$like,$like,$like,$like,$like,$like);}
 if($customerId>0){$where.=' AND cp.customer_id=?';$types.='i';$params[]=$customerId;}
 if($from!==''){$where.=' AND cp.receipt_date>=?';$types.='s';$params[]=$from;}
 if($to!==''){$where.=' AND cp.receipt_date<=?';$types.='s';$params[]=$to;}

 $stmt=$conn->prepare('SELECT COUNT(*) AS total FROM customer_payments cp INNER JOIN customers c ON c.id=cp.customer_id LEFT JOIN sales s ON s.id=cp.sale_id'.$where);
 if(!$stmt)respond(false,'Unable to prepare payment count: '.$conn->error,[],500);
 bindDynamic($stmt,$types,$params);$stmt->execute();$total=(int)($stmt->get_result()->fetch_assoc()['total']??0);$stmt->close();
 $totalPages=max(1,(int)ceil($total/$perPage));if($page>$totalPages)$page=$totalPages;$offset=($page-1)*$perPage;

 $sql="SELECT cp.*,c.customer_name,c.customer_code,c.mobile,pm.method_name,s.invoice_no,s.invoice_date,s.balance_amount
       FROM customer_payments cp
       INNER JOIN customers c ON c.id=cp.customer_id
       LEFT JOIN payment_methods pm ON pm.id=cp.payment_method_id
       LEFT JOIN sales s ON s.id=cp.sale_id
       {$where}
       ORDER BY cp.receipt_date DESC,cp.id DESC
       LIMIT ? OFFSET ?";
 $listParams=$params;$listParams[]=$perPage;$listParams[]=$offset;$listTypes=$types.'ii';
 $stmt=$conn->prepare($sql);if(!$stmt)respond(false,'Unable to prepare payment list: '.$conn->error,[],500);
 bindDynamic($stmt,$listTypes,$listParams);$stmt->execute();$r=$stmt->get_result();$payments=[];
 while($row=$r->fetch_assoc()){
  $row['receipt_date_display']=date('d-m-Y',strtotime($row['receipt_date']));
  $row['invoice_date_display']=!empty($row['invoice_date'])?date('d-m-Y',strtotime($row['invoice_date'])):'';
  $row['created_at_display']=!empty($row['created_at'])?date('d-m-Y h:i A',strtotime($row['created_at'])):'';
  $payments[]=$row;
 }
 $stmt->close();

 $stmt=$conn->prepare('SELECT COUNT(*) AS total_payments,COALESCE(SUM(amount),0) AS total_amount FROM customer_payments WHERE business_id=?');
 $stmt->bind_param('i',$b);$stmt->execute();$stats=$stmt->get_result()->fetch_assoc()?:[];$stmt->close();

 respond(true,'Payments loaded.',['payments'=>$payments,'stats'=>$stats,'meta'=>['page'=>$page,'per_page'=>$perPage,'total'=>$total,'total_pages'=>$totalPages,'from'=>$total>0?$offset+1:0,'to'=>$total>0?min($offset+$perPage,$total):0]]);
}

respond(false,'Invalid action.',[],400);
