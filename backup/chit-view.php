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
<?php $currentPage='chit-groups'; $id=(int)($_GET['id']??0); $st=$conn->prepare("SELECT * FROM chit_groups WHERE id=? AND business_id=?"); $st->bind_param('ii',$id,$businessId); $st->execute(); $group=$st->get_result()->fetch_assoc(); $st->close(); if(!$group) die('Group not found');
$members=[]; $st=$conn->prepare("SELECT * FROM chit_members WHERE group_id=? AND business_id=? ORDER BY id DESC"); $st->bind_param('ii',$id,$businessId); $st->execute(); $rs=$st->get_result(); while($r=$rs->fetch_assoc())$members[]=$r; $st->close();
$inst=[]; $st=$conn->prepare("SELECT * FROM chit_installments WHERE group_id=? AND business_id=? ORDER BY installment_no"); $st->bind_param('ii',$id,$businessId); $st->execute(); $rs=$st->get_result(); while($r=$rs->fetch_assoc())$inst[]=$r; $st->close();
?>

<!doctype html><html lang="en"><?php include('includes/head.php'); ?><body data-sidebar="dark"><?php include('includes/pre-loader.php'); ?><div id="layout-wrapper"><?php include('includes/topbar.php'); ?><div class="vertical-menu"><div data-simplebar class="h-100"><?php include('includes/sidebar.php'); ?></div></div><div class="main-content"><div class="page-content"><div class="container-fluid">
<?php if(isset($_SESSION['success'])): ?><div class="alert alert-success alert-dismissible fade show"><?php echo h($_SESSION['success']); unset($_SESSION['success']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<?php if(isset($_SESSION['error'])): ?><div class="alert alert-danger alert-dismissible fade show"><?php echo h($_SESSION['error']); unset($_SESSION['error']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

<div class="card mb-3"><div class="card-body"><div class="d-flex justify-content-between"><h4><?php echo h($group['group_name']); ?></h4><a href="chit-groups.php" class="btn btn-secondary btn-sm">Back</a></div><div class="row"><div class="col-md-3">Group No: <b><?php echo h($group['group_no']); ?></b></div><div class="col-md-3">Value: <b>₹<?php echo money($group['chit_value']); ?></b></div><div class="col-md-3">Installment: <b>₹<?php echo money($group['installment_amount']); ?></b></div><div class="col-md-3">Status: <b><?php echo h($group['status']); ?></b></div></div></div></div>
<div class="row"><div class="col-lg-7"><div class="card"><div class="card-body"><h5>Members</h5><table class="table table-bordered table-sm"><thead><tr><th>No</th><th>Name</th><th>Mobile</th><th>Status</th></tr></thead><tbody><?php foreach($members as $m): ?><tr><td><?php echo h($m['member_no']); ?></td><td><?php echo h($m['subscriber_name']); ?></td><td><?php echo h($m['subscriber_mobile']); ?></td><td><?php echo h($m['status']); ?></td></tr><?php endforeach; ?></tbody></table></div></div></div><div class="col-lg-5"><div class="card"><div class="card-body"><h5>Installments</h5><table class="table table-bordered table-sm"><thead><tr><th>No</th><th>Due Date</th><th>Status</th></tr></thead><tbody><?php foreach($inst as $in): ?><tr><td><?php echo (int)$in['installment_no']; ?></td><td><?php echo h(date('d-m-Y',strtotime($in['due_date']))); ?></td><td><?php echo h($in['status']); ?></td></tr><?php endforeach; ?></tbody></table></div></div></div></div>

</div></div><?php include('includes/footer.php'); ?></div></div><?php include('includes/rightbar.php'); ?><?php include('includes/scripts.php'); ?></body></html>
