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
<?php $currentPage='chit-customers'; if($_SERVER['REQUEST_METHOD']==='POST'){ $name=trim($_POST['customer_name']??''); $mobile=trim($_POST['mobile']??''); $addr=trim($_POST['address_line1']??''); $nom=trim($_POST['nominee_name']??''); $rel=trim($_POST['nominee_relation']??''); $nmobile=trim($_POST['nominee_mobile']??''); if($name===''){$_SESSION['error']='Customer name required.';} else { $code=nextNo($conn,'customers','customer_code','CHC'.date('Ym'),$businessId); $st=$conn->prepare("INSERT INTO customers (business_id,customer_code,customer_type,customer_name,mobile,address_line1,is_active) VALUES (?,?,'PawnChits',?,?,?,1)"); $st->bind_param('issss',$businessId,$code,$name,$mobile,$addr); if($st->execute()){ $cid=$st->insert_id; $st->close(); $st=$conn->prepare("INSERT INTO chit_customers (business_id,customer_id,nominee_name,nominee_relation,nominee_mobile) VALUES (?,?,?,?,?)"); $st->bind_param('iisss',$businessId,$cid,$nom,$rel,$nmobile); $st->execute(); $st->close(); $_SESSION['success']='Chit customer added.'; header('Location: chit-customers.php'); exit; } else { $_SESSION['error']=$st->error; } } } ?>

<!doctype html><html lang="en"><?php include('includes/head.php'); ?><body data-sidebar="dark"><?php include('includes/pre-loader.php'); ?><div id="layout-wrapper"><?php include('includes/topbar.php'); ?><div class="vertical-menu"><div data-simplebar class="h-100"><?php include('includes/sidebar.php'); ?></div></div><div class="main-content"><div class="page-content"><div class="container-fluid">
<?php if(isset($_SESSION['success'])): ?><div class="alert alert-success alert-dismissible fade show"><?php echo h($_SESSION['success']); unset($_SESSION['success']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<?php if(isset($_SESSION['error'])): ?><div class="alert alert-danger alert-dismissible fade show"><?php echo h($_SESSION['error']); unset($_SESSION['error']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

<div class="card"><div class="card-body"><h4>Add Chit Customer</h4><form method="post" class="row g-3"><div class="col-md-4"><label>Name *</label><input name="customer_name" class="form-control" required></div><div class="col-md-4"><label>Mobile</label><input name="mobile" class="form-control"></div><div class="col-md-4"><label>Nominee</label><input name="nominee_name" class="form-control"></div><div class="col-md-4"><label>Relation</label><input name="nominee_relation" class="form-control"></div><div class="col-md-4"><label>Nominee Mobile</label><input name="nominee_mobile" class="form-control"></div><div class="col-md-12"><label>Address</label><textarea name="address_line1" class="form-control"></textarea></div><div><button class="btn btn-primary">Save</button> <a href="chit-customers.php" class="btn btn-secondary">Back</a></div></form></div></div>

</div></div><?php include('includes/footer.php'); ?></div></div><?php include('includes/rightbar.php'); ?><?php include('includes/scripts.php'); ?></body></html>
