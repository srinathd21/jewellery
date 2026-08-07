<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_OFF);
ini_set('display_errors','0');

function respond($ok,$message='',$extra=[],$status=200){
 http_response_code($status);
 echo json_encode(array_merge(['success'=>(bool)$ok,'message'=>(string)$message],$extra),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
 exit;
}
register_shutdown_function(function(){
 $e=error_get_last();
 if($e&&in_array($e['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR],true)){
  respond(false,'Fatal API error: '.$e['message'],[],500);
 }
});
foreach([
 dirname(__DIR__).'/config/config.php',
 dirname(__DIR__).'/config.php',
 dirname(__DIR__).'/includes/config.php',
 dirname(__DIR__).'/super-admin/includes/config.php'
] as $f){if(is_file($f)){require_once $f;break;}}
if(!isset($conn)||!($conn instanceof mysqli))respond(false,'Database configuration is not available.',[],500);
$conn->set_charset('utf8mb4');
if(empty($_SESSION['user_id']))respond(false,'Session expired.',[],401);
if($_SERVER['REQUEST_METHOD']!=='POST')respond(false,'Invalid request method.',[],405);
if(!hash_equals((string)($_SESSION['pawn_csrf']??''),(string)($_POST['csrf_token']??'')))respond(false,'Invalid request token. Refresh the page.',[],419);

$businessId=(int)($_SESSION['business_id']??0);
$action=trim((string)($_POST['action']??''));
if($businessId<=0)respond(false,'Select a valid business.',[],403);

function tableExists(mysqli $c,string $t):bool{
 $x=$c->real_escape_string($t);$r=$c->query("SHOW TABLES LIKE '{$x}'");return $r&&$r->num_rows>0;
}
function hasColumn(mysqli $c,string $t,string $col):bool{
 $t=$c->real_escape_string($t);$col=$c->real_escape_string($col);
 $r=$c->query("SHOW COLUMNS FROM `{$t}` LIKE '{$col}'");return $r&&$r->num_rows>0;
}
function bindDynamic(mysqli_stmt $s,string $types,array &$vals):void{
 if(strlen($types)!==count($vals))throw new RuntimeException('Prepared statement bind mismatch.');
 $args=[$types];foreach($vals as $k=>$v)$args[]=&$vals[$k];
 call_user_func_array([$s,'bind_param'],$args);
}
if(!tableExists($conn,'pawn_interest_collections'))respond(false,'Required table pawn_interest_collections was not found.',[],500);

$hasOther=hasColumn($conn,'pawn_interest_collections','other_charges');
$hasReference=hasColumn($conn,'pawn_interest_collections','reference_no');
$hasType=hasColumn($conn,'pawn_interest_collections','collection_type');
$hasReversed=hasColumn($conn,'pawn_interest_collections','is_reversed');
$hasReversedAt=hasColumn($conn,'pawn_interest_collections','reversed_at');
$hasRemarks=hasColumn($conn,'pawn_interest_collections','remarks');

if($action==='options'){
 $customers=[];$s=$conn->prepare("SELECT DISTINCT c.id,c.customer_code,c.customer_name,c.mobile
 FROM pawn_interest_collections pic
 INNER JOIN pawn_entries p ON p.id=pic.pawn_entry_id AND p.business_id=pic.business_id
 INNER JOIN customers c ON c.id=p.customer_id AND c.business_id=p.business_id
 WHERE pic.business_id=? ORDER BY c.customer_name");
 if($s){$s->bind_param('i',$businessId);$s->execute();$r=$s->get_result();while($x=$r->fetch_assoc())$customers[]=$x;$s->close();}
 $pawns=[];$s=$conn->prepare("SELECT DISTINCT p.id,p.pawn_no,c.customer_name,c.id customer_id
 FROM pawn_interest_collections pic
 INNER JOIN pawn_entries p ON p.id=pic.pawn_entry_id AND p.business_id=pic.business_id
 LEFT JOIN customers c ON c.id=p.customer_id AND c.business_id=p.business_id
 WHERE pic.business_id=? ORDER BY p.pawn_no");
 if($s){$s->bind_param('i',$businessId);$s->execute();$r=$s->get_result();while($x=$r->fetch_assoc())$pawns[]=$x;$s->close();}
 $branches=[];$s=$conn->prepare("SELECT DISTINCT b.id,b.branch_name FROM pawn_interest_collections pic INNER JOIN branches b ON b.id=pic.branch_id AND b.business_id=pic.business_id WHERE pic.business_id=? ORDER BY b.branch_name");
 if($s){$s->bind_param('i',$businessId);$s->execute();$r=$s->get_result();while($x=$r->fetch_assoc())$branches[]=$x;$s->close();}
 $methods=[];if(tableExists($conn,'payment_methods')){$s=$conn->prepare("SELECT DISTINCT pm.id,pm.method_name FROM pawn_interest_collections pic INNER JOIN payment_methods pm ON pm.id=pic.payment_method_id AND pm.business_id=pic.business_id WHERE pic.business_id=? ORDER BY pm.method_name");if($s){$s->bind_param('i',$businessId);$s->execute();$r=$s->get_result();while($x=$r->fetch_assoc())$methods[]=$x;$s->close();}}
 $collectors=[];if(tableExists($conn,'users')){$s=$conn->prepare("SELECT DISTINCT u.id,u.full_name,u.username FROM pawn_interest_collections pic INNER JOIN users u ON u.id=pic.created_by WHERE pic.business_id=? ORDER BY u.full_name");if($s){$s->bind_param('i',$businessId);$s->execute();$r=$s->get_result();while($x=$r->fetch_assoc())$collectors[]=$x;$s->close();}}
 $types=[];if($hasType){$r=$conn->query("SELECT DISTINCT collection_type FROM pawn_interest_collections WHERE business_id=".(int)$businessId." AND collection_type IS NOT NULL AND collection_type<>'' ORDER BY collection_type");while($r&&$x=$r->fetch_assoc())$types[]=$x['collection_type'];}
 respond(true,'',['customers'=>$customers,'pawns'=>$pawns,'branches'=>$branches,'payment_methods'=>$methods,'collectors'=>$collectors,'collection_types'=>$types,'features'=>['reversal'=>$hasReversed,'reference'=>$hasReference,'other_charges'=>$hasOther,'collection_type'=>$hasType]]);
}

if($action==='list'){
 $search=trim((string)($_POST['search']??''));
 $customerId=(int)($_POST['customer_id']??0);
 $pawnId=(int)($_POST['pawn_id']??0);
 $from=trim((string)($_POST['from_date']??''));
 $to=trim((string)($_POST['to_date']??''));
 $branchId=(int)($_POST['branch_id']??0);
 $methodId=(int)($_POST['payment_method_id']??0);
 $collectorId=(int)($_POST['collector_id']??0);
 $collectionType=trim((string)($_POST['collection_type']??''));
 $minAmount=trim((string)($_POST['min_amount']??''));
 $maxAmount=trim((string)($_POST['max_amount']??''));
 $recordStatus=trim((string)($_POST['record_status']??''));
 $pawnStatus=trim((string)($_POST['pawn_status']??''));
 $sortBy=trim((string)($_POST['sort_by']??'latest'));

 $where=['pic.business_id=?'];$types='i';$params=[$businessId];
 if($customerId>0){$where[]='p.customer_id=?';$types.='i';$params[]=$customerId;}
 if($pawnId>0){$where[]='pic.pawn_entry_id=?';$types.='i';$params[]=$pawnId;}
 if($from!==''){$where[]='pic.collection_date>=?';$types.='s';$params[]=$from;}
 if($to!==''){$where[]='pic.collection_date<=?';$types.='s';$params[]=$to;}
 if($branchId>0){$where[]='pic.branch_id=?';$types.='i';$params[]=$branchId;}
 if($methodId>0){$where[]='pic.payment_method_id=?';$types.='i';$params[]=$methodId;}
 if($collectorId>0){$where[]='pic.created_by=?';$types.='i';$params[]=$collectorId;}
 if($hasType&&$collectionType!==''){$where[]='pic.collection_type=?';$types.='s';$params[]=$collectionType;}
 if($minAmount!==''&&is_numeric($minAmount)){$where[]='pic.total_amount>=?';$types.='d';$params[]=(float)$minAmount;}
 if($maxAmount!==''&&is_numeric($maxAmount)){$where[]='pic.total_amount<=?';$types.='d';$params[]=(float)$maxAmount;}
 if($hasReversed&&$recordStatus==='active'){$where[]='COALESCE(pic.is_reversed,0)=0';}
 if($hasReversed&&$recordStatus==='reversed'){$where[]='COALESCE(pic.is_reversed,0)=1';}
 if($pawnStatus!==''){$where[]='p.status=?';$types.='s';$params[]=$pawnStatus;}
 if($search!==''){
  $like='%'.$search.'%';
  $parts=['pic.receipt_no LIKE ?','p.pawn_no LIKE ?','c.customer_name LIKE ?','c.customer_code LIKE ?','c.mobile LIKE ?'];
  $types.='sssss';array_push($params,$like,$like,$like,$like,$like);
  if($hasReference){$parts[]='pic.reference_no LIKE ?';$types.='s';$params[]=$like;}
  $where[]='('.implode(' OR ',$parts).')';
 }
 $otherSelect=$hasOther?'pic.other_charges':'0';
 $referenceSelect=$hasReference?'pic.reference_no':"''";
 $typeSelect=$hasType?'pic.collection_type':"'Interest'";
 $reversedSelect=$hasReversed?'pic.is_reversed':'0';
 $reversedAtSelect=$hasReversedAt?'pic.reversed_at':'NULL';
 $remarksSelect=$hasRemarks?'pic.remarks':"''";
 $order=[
  'oldest'=>'pic.collection_date ASC,pic.id ASC',
  'amount_desc'=>'pic.total_amount DESC,pic.id DESC',
  'amount_asc'=>'pic.total_amount ASC,pic.id ASC',
  'latest'=>'pic.collection_date DESC,pic.id DESC'
 ][$sortBy]??'pic.collection_date DESC,pic.id DESC';

 $sql="SELECT pic.id,pic.business_id,pic.branch_id,pic.pawn_entry_id,pic.receipt_no,pic.collection_date,
 pic.from_date,pic.to_date,pic.interest_amount,pic.penalty_amount,pic.total_amount,pic.payment_method_id,pic.created_by,
 {$otherSelect} AS other_charges,{$referenceSelect} AS reference_no,{$typeSelect} AS collection_type,
 {$reversedSelect} AS is_reversed,{$reversedAtSelect} AS reversed_at,{$remarksSelect} AS remarks,
 p.pawn_no,p.pawn_date,p.status AS pawn_status,p.customer_id,p.interest_percent,p.interest_period,
 c.customer_code,c.customer_name,c.mobile,
 b.branch_name,pm.method_name,u.full_name AS collector_name
 FROM pawn_interest_collections pic
 INNER JOIN pawn_entries p ON p.id=pic.pawn_entry_id AND p.business_id=pic.business_id
 LEFT JOIN customers c ON c.id=p.customer_id AND c.business_id=p.business_id
 LEFT JOIN branches b ON b.id=pic.branch_id AND b.business_id=pic.business_id
 LEFT JOIN payment_methods pm ON pm.id=pic.payment_method_id AND pm.business_id=pic.business_id
 LEFT JOIN users u ON u.id=pic.created_by
 WHERE ".implode(' AND ',$where)." ORDER BY {$order} LIMIT 5000";
 $s=$conn->prepare($sql);if(!$s)respond(false,'Unable to load interest collections: '.$conn->error,[],500);
 bindDynamic($s,$types,$params);$s->execute();$r=$s->get_result();$rows=[];while($x=$r->fetch_assoc())$rows[]=$x;$s->close();

 $sumSql="SELECT COUNT(*) collection_count,COALESCE(SUM(pic.interest_amount),0) interest_amount,
 COALESCE(SUM(pic.penalty_amount),0) penalty_amount,COALESCE(SUM({$otherSelect}),0) other_charges,
 COALESCE(SUM(pic.total_amount),0) total_amount
 FROM pawn_interest_collections pic
 INNER JOIN pawn_entries p ON p.id=pic.pawn_entry_id AND p.business_id=pic.business_id
 LEFT JOIN customers c ON c.id=p.customer_id AND c.business_id=p.business_id
 WHERE ".implode(' AND ',$where);
 $s=$conn->prepare($sumSql);if(!$s)respond(false,'Unable to calculate summary: '.$conn->error,[],500);
 bindDynamic($s,$types,$params);$s->execute();$summary=$s->get_result()->fetch_assoc()?:[];$s->close();
 respond(true,'',['rows'=>$rows,'summary'=>$summary]);
}
respond(false,'Invalid action.',[],400);