<?php
if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));
header('Content-Type: application/json; charset=utf-8');

foreach ([dirname(__DIR__).'/config/config.php',dirname(__DIR__).'/config.php',dirname(__DIR__).'/includes/config.php',dirname(__DIR__).'/super-admin/includes/config.php'] as $f) {
    if (is_file($f)) { require_once $f; break; }
}
function out(bool $ok,string $message,array $extra=[],int $status=200):void{
    http_response_code($status);
    echo json_encode(array_merge(['success'=>$ok,'message'=>$message],$extra),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
if (!isset($conn) || !($conn instanceof mysqli)) out(false,'Database configuration is not available.',[],500);
$conn->set_charset('utf8mb4');
if (empty($_SESSION['user_id'])) out(false,'Session expired.',[],401);
if ($_SERVER['REQUEST_METHOD']!=='POST') out(false,'Invalid request method.',[],405);
if (!hash_equals((string)($_SESSION['billing_csrf']??''),(string)($_POST['csrf_token']??''))) out(false,'Invalid request token.',[],419);
$businessId=(int)($_SESSION['business_id']??0);
$customerId=(int)($_POST['customer_id']??0);
$action=(string)($_POST['action']??'');
if ($action!=='list_customer_chits') out(false,'Invalid action.',[],400);
if ($businessId<=0 || $customerId<=0) out(false,'Select a valid customer.',[],422);

$sql="SELECT cm.id chit_member_id,cm.chit_group_id,cm.ticket_no,cm.status member_status,
             cg.group_no,cg.group_name,cg.chit_type,cg.chit_value,
             COALESCE((SELECT SUM(cc.paid_amount) FROM chit_collections cc WHERE cc.business_id=cm.business_id AND cc.chit_member_id=cm.id),0) paid_amount,
             COALESCE((SELECT SUM(cc.gold_weight_grams) FROM chit_collections cc WHERE cc.business_id=cm.business_id AND cc.chit_member_id=cm.id),0) saved_grams,
             COALESCE((SELECT SUM(scc.claim_grams) FROM sales_chit_claims scc WHERE scc.business_id=cm.business_id AND scc.chit_member_id=cm.id AND scc.status='Posted'),0) claimed_grams
      FROM chit_members cm
      INNER JOIN chit_groups cg ON cg.id=cm.chit_group_id AND cg.business_id=cm.business_id
      WHERE cm.business_id=? AND cm.customer_id=? AND cm.status='Active' AND cg.chit_type='Gold' AND cg.status NOT IN ('Closed','Cancelled')
      ORDER BY cg.id,cm.id";
$stmt=$conn->prepare($sql);
if(!$stmt) out(false,'Unable to prepare customer gold savings query: '.$conn->error,[],500);
$stmt->bind_param('ii',$businessId,$customerId);
if(!$stmt->execute()) out(false,'Unable to load customer gold savings: '.$stmt->error,[],500);
$result=$stmt->get_result();$chits=[];
while($row=$result->fetch_assoc()){
    $row['saved_grams']=(float)$row['saved_grams'];
    $row['claimed_grams']=(float)$row['claimed_grams'];
    $row['available_grams']=max(0,$row['saved_grams']-$row['claimed_grams']);
    $row['available_amount']=0;
    $row['claimed_amount']=0;
    $chits[]=$row;
}
$stmt->close();
out(true,'Customer gold savings loaded.',['chits'=>$chits]);
