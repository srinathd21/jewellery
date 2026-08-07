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
<?php $currentPage='chit-groups'; $pageTitle='Chit Groups';
$res=$conn->query("SELECT g.*, COUNT(m.id) member_count FROM chit_groups g LEFT JOIN chit_members m ON m.group_id=g.id WHERE g.business_id={$businessId} GROUP BY g.id ORDER BY g.id DESC"); $rows=[]; if($res)while($r=$res->fetch_assoc())$rows[]=$r;
?>

<!doctype html><html lang="en"><?php include('includes/head.php'); ?><body data-sidebar="dark"><?php include('includes/pre-loader.php'); ?><div id="layout-wrapper"><?php include('includes/topbar.php'); ?><div class="vertical-menu"><div data-simplebar class="h-100"><?php include('includes/sidebar.php'); ?></div></div><div class="main-content"><div class="page-content"><div class="container-fluid">
<?php if(isset($_SESSION['success'])): ?><div class="alert alert-success alert-dismissible fade show"><?php echo h($_SESSION['success']); unset($_SESSION['success']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<?php if(isset($_SESSION['error'])): ?><div class="alert alert-danger alert-dismissible fade show"><?php echo h($_SESSION['error']); unset($_SESSION['error']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

<div class="card"><div class="card-body"><div class="d-flex justify-content-between mb-3"><h4>Chit Groups</h4><a href="chit-create.php" class="btn btn-primary btn-sm">+ Create Chit</a></div><div class="table-responsive"><table class="table table-bordered table-sm align-middle"><thead><tr><th>#</th><th>Group No</th><th>Name</th><th>Type</th><th>Members</th><th>Months</th><th>Installment</th><th>Value</th><th>Status</th><th>Action</th></tr></thead><tbody><?php if(!$rows): ?><tr><td colspan="10" class="text-center">No groups found</td></tr><?php else: foreach($rows as $i=>$r): ?><tr><td><?php echo $i+1; ?></td><td><?php echo h($r['group_no']); ?></td><td><?php echo h($r['group_name']); ?></td><td><?php echo h($r['chit_type']); ?></td><td><?php echo (int)$r['member_count'].' / '.(int)$r['total_members']; ?></td><td><?php echo (int)$r['total_months']; ?></td><td class="text-end">₹<?php echo money($r['installment_amount']); ?></td><td class="text-end">₹<?php echo money($r['chit_value']); ?></td><td><span class="badge bg-success"><?php echo h($r['status']); ?></span></td><td><a class="btn btn-info btn-sm" href="chit-view.php?id=<?php echo (int)$r['id']; ?>">View</a> <a class="btn btn-success btn-sm" href="chit-member-add.php?group_id=<?php echo (int)$r['id']; ?>">Add Member</a></td></tr><?php endforeach; endif; ?></tbody></table></div></div></div>

</div></div><?php include('includes/footer.php'); ?></div></div><?php include('includes/rightbar.php'); ?><?php include('includes/scripts.php'); ?></body></html>
