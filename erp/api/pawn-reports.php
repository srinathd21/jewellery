<?php
if(session_status()===PHP_SESSION_NONE)session_start();
header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_OFF);
ini_set('display_errors','0');

function respond($ok,$message='',$extra=array(),$code=200){
 http_response_code($code);
 echo json_encode(array_merge(array('success'=>(bool)$ok,'message'=>(string)$message),$extra),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
 exit;
}

foreach(array(
 dirname(__DIR__).'/config/config.php',
 dirname(__DIR__).'/config.php',
 dirname(__DIR__).'/includes/config.php',
 dirname(__DIR__).'/super-admin/includes/config.php'
) as $f){
 if(is_file($f)){require_once $f;break;}
}

if(!isset($conn)||!($conn instanceof mysqli))respond(false,'Database configuration is not available.',array(),500);
$conn->set_charset('utf8mb4');
if(empty($_SESSION['user_id']))respond(false,'Session expired.',array(),401);
if($_SERVER['REQUEST_METHOD']!=='POST')respond(false,'Invalid request method.',array(),405);
if(!hash_equals((string)($_SESSION['pawn_csrf']??''),(string)($_POST['csrf_token']??'')))respond(false,'Invalid request token. Refresh the page.',array(),419);

$businessId=(int)($_SESSION['business_id']??0);
$branchId=(int)($_SESSION['branch_id']??($_SESSION['default_branch_id']??0));
$action=trim((string)($_POST['action']??''));

if($businessId<=0||$branchId<=0)respond(false,'Select a valid business and branch.',array(),403);

function hasColumn($c,$t,$col){
 $t=$c->real_escape_string($t);$col=$c->real_escape_string($col);
 $r=$c->query("SHOW COLUMNS FROM `{$t}` LIKE '{$col}'");
 return $r&&$r->num_rows>0;
}

function bindDynamic($stmt,$types,&$values){
 if(strlen($types)!==count($values))throw new RuntimeException('Prepared statement bind mismatch.');
 $args=array($types);
 foreach($values as $k=>$v)$args[]=&$values[$k];
 call_user_func_array(array($stmt,'bind_param'),$args);
}

if($action==='options'){
 $categories=array();
 $s=$conn->prepare('SELECT id,category_name FROM pawn_categories WHERE business_id=? ORDER BY category_name');
 if($s){
  $s->bind_param('i',$businessId);$s->execute();$r=$s->get_result();
  while($x=$r->fetch_assoc())$categories[]=$x;
  $s->close();
 }

 $customers=array();
 $s=$conn->prepare('SELECT id,customer_name,customer_code,mobile FROM customers WHERE business_id=? ORDER BY customer_name');
 if($s){
  $s->bind_param('i',$businessId);$s->execute();$r=$s->get_result();
  while($x=$r->fetch_assoc())$customers[]=$x;
  $s->close();
 }

 respond(true,'',array('categories'=>$categories,'customers'=>$customers));
}

if($action==='report'){
 $search=trim((string)($_POST['search']??''));
 $status=trim((string)($_POST['status']??''));
 $categoryId=(int)($_POST['category_id']??0);
 $customerId=(int)($_POST['customer_id']??0);
 $from=trim((string)($_POST['from_date']??''));
 $to=trim((string)($_POST['to_date']??''));

 $penaltySelect=hasColumn($conn,'pawn_entries','total_penalty_collected')?'COALESCE(pe.total_penalty_collected,0)':'0';
 $otherSelect=hasColumn($conn,'pawn_entries','total_other_charges_collected')?'COALESCE(pe.total_other_charges_collected,0)':'0';

 $sql="SELECT
          pe.id,pe.pawn_no,pe.pawn_date,pe.due_date,pe.closure_date,
          pe.principal_amount,pe.total_principal_paid,pe.balance_principal,
          pe.total_interest_collected,
          {$penaltySelect} AS total_penalty_collected,
          {$otherSelect} AS total_other_charges_collected,
          pe.status,pe.interest_percent,pe.interest_period,pe.interest_method,
          c.customer_name,c.customer_code,c.mobile,
          pc.category_name,b.branch_name
       FROM pawn_entries pe
       INNER JOIN customers c ON c.id=pe.customer_id AND c.business_id=pe.business_id
       LEFT JOIN pawn_categories pc ON pc.id=pe.pawn_category_id
       LEFT JOIN branches b ON b.id=pe.branch_id
       WHERE pe.business_id=? AND pe.branch_id=?";

 $types='ii';
 $params=array($businessId,$branchId);

 if($search!==''){
  $sql.=" AND (pe.pawn_no LIKE ? OR c.customer_name LIKE ? OR c.customer_code LIKE ? OR c.mobile LIKE ?)";
  $like='%'.$search.'%';$types.='ssss';
  $params[]=$like;$params[]=$like;$params[]=$like;$params[]=$like;
 }
 if($status!==''){$sql.=" AND pe.status=?";$types.='s';$params[]=$status;}
 if($categoryId>0){$sql.=" AND pe.pawn_category_id=?";$types.='i';$params[]=$categoryId;}
 if($customerId>0){$sql.=" AND pe.customer_id=?";$types.='i';$params[]=$customerId;}
 if($from!==''){$sql.=" AND pe.pawn_date>=?";$types.='s';$params[]=$from;}
 if($to!==''){$sql.=" AND pe.pawn_date<=?";$types.='s';$params[]=$to;}

 $sql.=" ORDER BY pe.pawn_date DESC,pe.id DESC";

 $s=$conn->prepare($sql);
 if(!$s)respond(false,'Unable to prepare pawn report: '.$conn->error,array(),500);
 bindDynamic($s,$types,$params);
 $s->execute();$r=$s->get_result();

 $rows=array();
 $summary=array(
  'total_pawns'=>0,'original_principal'=>0,'principal_paid'=>0,'outstanding'=>0,
  'interest_collected'=>0,'penalty_collected'=>0,'other_collected'=>0,'total_collected'=>0,
  'closed_count'=>0,'closed_principal'=>0,'open_count'=>0,'open_outstanding'=>0
 );
 $statusMap=array();
 $categoryMap=array();

 while($x=$r->fetch_assoc()){
  $rows[]=$x;
  $principal=(float)$x['principal_amount'];
  $paid=(float)$x['total_principal_paid'];
  $balance=(float)$x['balance_principal'];
  $interest=(float)$x['total_interest_collected'];
  $penalty=(float)$x['total_penalty_collected'];
  $other=(float)$x['total_other_charges_collected'];

  $summary['total_pawns']++;
  $summary['original_principal']+=$principal;
  $summary['principal_paid']+=$paid;
  $summary['outstanding']+=$balance;
  $summary['interest_collected']+=$interest;
  $summary['penalty_collected']+=$penalty;
  $summary['other_collected']+=$other;
  $summary['total_collected']+=($paid+$interest+$penalty+$other);

  if((string)$x['status']==='Closed'){
   $summary['closed_count']++;
   $summary['closed_principal']+=$principal;
  }
  if(in_array((string)$x['status'],array('Active','Partially Paid'),true)){
   $summary['open_count']++;
   $summary['open_outstanding']+=$balance;
  }

  $st=(string)($x['status']??'Unknown');
  if(!isset($statusMap[$st]))$statusMap[$st]=array('status'=>$st,'count'=>0,'principal'=>0,'outstanding'=>0);
  $statusMap[$st]['count']++;
  $statusMap[$st]['principal']+=$principal;
  $statusMap[$st]['outstanding']+=$balance;

  $cat=(string)($x['category_name']??'Uncategorized');
  if($cat==='')$cat='Uncategorized';
  if(!isset($categoryMap[$cat]))$categoryMap[$cat]=array('category_name'=>$cat,'count'=>0,'principal'=>0,'outstanding'=>0);
  $categoryMap[$cat]['count']++;
  $categoryMap[$cat]['principal']+=$principal;
  $categoryMap[$cat]['outstanding']+=$balance;
 }
 $s->close();

 usort($statusMap,function($a,$b){return $b['count']<=>$a['count'];});
 usort($categoryMap,function($a,$b){return $b['principal']<=>$a['principal'];});

 respond(true,'',array(
  'rows'=>$rows,
  'summary'=>$summary,
  'status_summary'=>array_values($statusMap),
  'category_summary'=>array_values($categoryMap)
 ));
}

respond(false,'Invalid action.',array(),400);