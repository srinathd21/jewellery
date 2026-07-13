<?php
if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));
foreach ([dirname(__DIR__).'/config/config.php',dirname(__DIR__).'/config.php',dirname(__DIR__).'/includes/config.php',dirname(dirname(__DIR__)).'/includes/config.php'] as $f) { if (is_file($f)) { require_once $f; break; } }
if (!isset($conn) || !($conn instanceof mysqli)) die('Database configuration is not available.');
$conn->set_charset('utf8mb4');
if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }
if (!function_exists('pawn_e')) { function pawn_e($v){ return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('pawn_money')) { function pawn_money($v){ return number_format((float)$v,2); } }
function pawn_permission(mysqli $conn,string $action):bool {
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin') return true;
    $map=['open'=>'can_open','view'=>'can_view','value'=>'can_view_value','create'=>'can_create','update'=>'can_update','approve'=>'can_approve','delete'=>'can_delete'];
    $field=$map[$action]??''; if(!$field)return false;
    foreach(['perm.pawn.entries','perm.pawn.collections','perm.pawn'] as $code){ if(isset($_SESSION['permissions'][$code][$field])) return (int)$_SESSION['permissions'][$code][$field]===1; }
    $bid=(int)($_SESSION['business_id']??0); $rid=(int)($_SESSION['role_id']??0); if($bid<=0||$rid<=0)return false;
    $sql="SELECT rp.`$field` FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.business_id=? AND rp.role_id=? AND p.is_active=1 AND p.permission_code IN ('perm.pawn.entries','perm.pawn.collections','perm.pawn') ORDER BY FIELD(p.permission_code,'perm.pawn.entries','perm.pawn.collections','perm.pawn') LIMIT 1";
    $st=$conn->prepare($sql); if(!$st)return false; $st->bind_param('ii',$bid,$rid); $st->execute(); $row=$st->get_result()->fetch_assoc(); $st->close(); return (int)($row[$field]??0)===1;
}
$businessId=(int)($_SESSION['business_id']??0); $branchId=(int)($_SESSION['branch_id']??$_SESSION['default_branch_id']??0); $userId=(int)$_SESSION['user_id'];
if($businessId<=0||$branchId<=0){http_response_code(403);die('Valid business and branch are required.');}
if(empty($_SESSION['pawn_csrf'])) $_SESSION['pawn_csrf']=bin2hex(random_bytes(32));
$pawnCsrf=(string)$_SESSION['pawn_csrf'];
function pawn_theme(mysqli $conn,int $bid):array{
 $t=['primary_color'=>'#d89416','primary_dark_color'=>'#b86a0b','primary_soft_color'=>'#fff6e5','page_background'=>'#f4f3f0','card_background'=>'#fff','text_color'=>'#171717','muted_text_color'=>'#7d8794','border_color'=>'#e8e8e8','font_family'=>'Inter','heading_font_family'=>'Playfair Display','border_radius_px'=>12,'sidebar_width_px'=>230,'sidebar_gradient_1'=>'#171c21','sidebar_gradient_2'=>'#20272d','sidebar_gradient_3'=>'#101419'];
 $st=$conn->prepare('SELECT * FROM business_theme_settings WHERE business_id=? LIMIT 1'); if($st){$st->bind_param('i',$bid);$st->execute();$r=$st->get_result()->fetch_assoc()?:[];$st->close();foreach($t as $k=>$v)if(isset($r[$k])&&$r[$k]!=='')$t[$k]=$r[$k];} return $t;
}
function pawn_next_no(mysqli $conn,int $bid,int $branch,string $type,string $prefix):string{
 $period=date('Ym'); $conn->begin_transaction(); try{$st=$conn->prepare('SELECT id,current_number FROM number_sequences WHERE business_id=? AND branch_id=? AND document_type=? AND period_key=? FOR UPDATE');$st->bind_param('iiss',$bid,$branch,$type,$period);$st->execute();$r=$st->get_result()->fetch_assoc();$st->close(); if($r){$n=(int)$r['current_number']+1;$st=$conn->prepare('UPDATE number_sequences SET current_number=? WHERE id=?');$st->bind_param('ii',$n,$r['id']);$st->execute();$st->close();}else{$n=1;$st=$conn->prepare('INSERT INTO number_sequences(business_id,branch_id,document_type,period_key,current_number) VALUES(?,?,?,?,?)');$st->bind_param('iissi',$bid,$branch,$type,$period,$n);$st->execute();$st->close();}$conn->commit();return $prefix.$period.str_pad((string)$n,4,'0',STR_PAD_LEFT);}catch(Throwable $e){$conn->rollback();throw $e;}
}
function pawn_json($ok,$message,$data=[]){header('Content-Type: application/json; charset=utf-8');echo json_encode(array_merge(['success'=>(bool)$ok,'message'=>$message],$data),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function pawn_require_csrf(){if(!hash_equals((string)($_SESSION['pawn_csrf']??''),(string)($_POST['csrf_token']??''))) pawn_json(false,'Invalid or expired security token.');}
function pawn_audit(mysqli $conn,int $bid,int $branch,int $uid,string $action,string $table,int $id,string $desc,array $new=[]){$json=$new?json_encode($new,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null;$module='pawn';$st=$conn->prepare('INSERT INTO audit_logs(business_id,branch_id,user_id,module_code,action_type,reference_table,reference_id,description,new_values_json,ip_address,user_agent) VALUES(?,?,?,?,?,?,?,?,?,?,?)');if($st){$ip=$_SERVER['REMOTE_ADDR']??null;$ua=$_SERVER['HTTP_USER_AGENT']??null;$st->bind_param('iiisssissss',$bid,$branch,$uid,$module,$action,$table,$id,$desc,$json,$ip,$ua);$st->execute();$st->close();}}
