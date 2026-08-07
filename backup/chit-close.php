<?php
session_start();
date_default_timezone_set('Asia/Kolkata');
require_once 'includes/config.php';
if (!isset($conn) || !($conn instanceof mysqli)) { die('Database connection not available. Check includes/config.php'); }
mysqli_report(MYSQLI_REPORT_OFF);
$conn->set_charset('utf8mb4');
if (!function_exists('h')) { function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('money')) { function money($v): string { return number_format((float)$v, 2); } }
if (!function_exists('tableExists')) { function tableExists(mysqli $conn, string $t): bool { $t=$conn->real_escape_string($t); $r=$conn->query("SHOW TABLES LIKE '{$t}'"); return $r && $r->num_rows>0; } }
if (!function_exists('nextNo')) { function nextNo(mysqli $conn, string $table, string $col, string $prefix, int $businessId): string { $like=$prefix.'%'; $sql="SELECT {$col} FROM {$table} WHERE business_id=? AND {$col} LIKE ? ORDER BY id DESC LIMIT 1"; $st=$conn->prepare($sql); if(!$st) return $prefix.'0001'; $st->bind_param('is',$businessId,$like); $st->execute(); $row=$st->get_result()->fetch_assoc(); $st->close(); $n=1; if($row){ $last=(string)$row[$col]; if(preg_match('/(\d+)$/',$last,$m)) $n=((int)$m[1])+1; } return $prefix.str_pad((string)$n,4,'0',STR_PAD_LEFT); } }
$userId=(int)($_SESSION['user_id']??0); $businessId=(int)($_SESSION['business_id']??1); if($userId<=0){ header('Location: ../login.php'); exit; }
$roleName = function_exists('currentRoleName') ? currentRoleName() : ($_SESSION['role_name'] ?? 'Admin');
if (!in_array($roleName, ['Super Admin','Admin','Manager','Billing'], true)) { die('Access denied.'); }

?>
<?php $currentPage='chit-close'; if($_SERVER['REQUEST_METHOD']==='POST'){ $gid=(int)$_POST['group_id']; $remarks=trim($_POST['remarks']??''); if($gid>0){ $st=$conn->prepare("UPDATE chit_groups SET status='Closed', notes=CONCAT(COALESCE(notes,''),'\nClosed: ',?) WHERE id=? AND business_id=?"); $st->bind_param('sii',$remarks,$gid,$businessId); $st->execute(); $st->close(); $conn->query("UPDATE chit_members SET status='Closed' WHERE group_id=".$gid." AND business_id=".$businessId); $_SESSION['success']='Chit closed successfully.'; header('Location: chit-groups.php'); exit; }} $groups=[]; $res=$conn->query("SELECT id,group_no,group_name FROM chit_groups WHERE business_id={$businessId} AND status='Active' ORDER BY group_name"); if($res)while($r=$res->fetch_assoc())$groups[]=$r; ?>

<!doctype html><html lang="en"><?php include('includes/head.php'); ?><body data-sidebar="dark"><?php include('includes/pre-loader.php'); ?><div id="layout-wrapper"><?php include('includes/topbar.php'); ?><div class="vertical-menu"><div data-simplebar class="h-100"><?php include('includes/sidebar.php'); ?></div></div><div class="main-content"><div class="page-content"><div class="container-fluid">
<?php if(isset($_SESSION['success'])): ?><div class="alert alert-success alert-dismissible fade show"><?php echo h($_SESSION['success']); unset($_SESSION['success']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<?php if(isset($_SESSION['error'])): ?><div class="alert alert-danger alert-dismissible fade show"><?php echo h($_SESSION['error']); unset($_SESSION['error']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

<div class="card"><div class="card-body"><h4>Close Chit</h4><form method="post" class="row g-3"><div class="col-md-6"><label>Active Chit Group</label><select name="group_id" class="form-select" required><option value="">Select</option><?php foreach($groups as $g): ?><option value="<?php echo (int)$g['id']; ?>"><?php echo h($g['group_no'].' - '.$g['group_name']); ?></option><?php endforeach; ?></select></div><div class="col-md-12"><label>Close Remarks</label><textarea name="remarks" class="form-control"></textarea></div><div><button class="btn btn-danger" onclick="return confirm('Close this chit?')">Close Chit</button></div></form></div></div>

</div></div><?php include('includes/footer.php'); ?></div></div><?php include('includes/rightbar.php'); ?><?php include('includes/scripts.php'); ?></body></html>
