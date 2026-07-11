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
<?php $currentPage='chit-customers'; $rows=[]; $sql="SELECT cc.*, c.customer_code,c.customer_name,c.mobile,c.address_line1 FROM chit_customers cc INNER JOIN customers c ON c.id=cc.customer_id WHERE cc.business_id={$businessId} ORDER BY cc.id DESC"; $res=$conn->query($sql); if($res)while($r=$res->fetch_assoc())$rows[]=$r; ?>

<!doctype html><html lang="en"><?php include('includes/head.php'); ?><body data-sidebar="dark"><?php include('includes/pre-loader.php'); ?><div id="layout-wrapper"><?php include('includes/topbar.php'); ?><div class="vertical-menu"><div data-simplebar class="h-100"><?php include('includes/sidebar.php'); ?></div></div><div class="main-content"><div class="page-content"><div class="container-fluid">
<?php if(isset($_SESSION['success'])): ?><div class="alert alert-success alert-dismissible fade show"><?php echo h($_SESSION['success']); unset($_SESSION['success']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<?php if(isset($_SESSION['error'])): ?><div class="alert alert-danger alert-dismissible fade show"><?php echo h($_SESSION['error']); unset($_SESSION['error']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

<div class="card"><div class="card-body"><div class="d-flex justify-content-between mb-3"><h4>Chit Customers</h4><a href="chit-customer-add.php" class="btn btn-primary btn-sm">+ Add Customer</a></div><table class="table table-bordered table-sm"><thead><tr><th>#</th><th>Code</th><th>Name</th><th>Mobile</th><th>Nominee</th><th>Active Chits</th><th>Paid</th><th>Due</th></tr></thead><tbody><?php if(!$rows): ?><tr><td colspan="8" class="text-center">No customers</td></tr><?php else: foreach($rows as $i=>$r): ?><tr><td><?php echo $i+1; ?></td><td><?php echo h($r['customer_code']); ?></td><td><?php echo h($r['customer_name']); ?></td><td><a href="tel:<?php echo h($r['mobile']); ?>"><?php echo h($r['mobile']); ?></a></td><td><?php echo h($r['nominee_name']); ?></td><td><?php echo (int)$r['active_chits']; ?></td><td class="text-end">₹<?php echo money($r['total_paid_amount']); ?></td><td class="text-end">₹<?php echo money($r['total_due_amount']); ?></td></tr><?php endforeach; endif; ?></tbody></table></div></div>

</div></div><?php include('includes/footer.php'); ?></div></div><?php include('includes/rightbar.php'); ?><?php include('includes/scripts.php'); ?></body></html>
