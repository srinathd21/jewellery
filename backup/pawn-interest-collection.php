<?php
session_start();
date_default_timezone_set('Asia/Kolkata');
require_once 'includes/config.php';
if (!isset($conn) || !($conn instanceof mysqli)) { die('Database connection not available.'); }
mysqli_report(MYSQLI_REPORT_OFF);
$conn->set_charset('utf8mb4');
if (!function_exists('h')) { function h($v){ return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('money')) { function money($v){ return number_format((float)$v, 2); } }
if (!function_exists('nextNo')) {
function nextNo(mysqli $conn, string $table, string $col, string $prefix, int $businessId): string {
    $like = $prefix . date('Ym') . '%';
    $sql = "SELECT `$col` FROM `$table` WHERE `$col` LIKE ? AND business_id=? ORDER BY id DESC LIMIT 1";
    $st = $conn->prepare($sql);
    if (!$st) return $prefix.date('Ym').'0001';
    $st->bind_param('si', $like, $businessId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    $n = 1;
    if ($row && !empty($row[$col])) $n = ((int)substr(preg_replace('/\D/','',$row[$col]), -4)) + 1;
    return $prefix.date('Ym').str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}}
if (!isset($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
$userId = (int)$_SESSION['user_id'];
$businessId = (int)($_SESSION['business_id'] ?? 1);
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);
?><?php
$currentPage='pawn-interest-collection';
$pawnId=(int)($_GET['pawn_id']??$_POST['pawn_id']??0);
$active=$conn->query("SELECT id,pawn_no,customer_name,principal_balance FROM pawn_entries WHERE business_id={$businessId} AND status IN ('Active','Partially Paid') ORDER BY id DESC");
if($_SERVER['REQUEST_METHOD']==='POST'){
    $pawnId=(int)$_POST['pawn_id']; $amount=(float)($_POST['amount']??0); $remarks=trim($_POST['remarks']??''); $date=$_POST['entry_date']??date('Y-m-d'); $no=nextNo($conn,'pawn_interest_collections','receipt_no','PIN',$businessId);
    $st=$conn->prepare("INSERT INTO pawn_interest_collections (business_id,pawn_id,receipt_no,collection_date,interest_amount,net_amount,remarks,created_by) VALUES (?,?,?,?,?,?,?,?)"); $st->bind_param('iissddsi',$businessId,$pawnId,$no,$date,$amount,$amount,$remarks,$userId);
    $ok=$st && $st->execute(); if($st)$st->close();
    
    $_SESSION[$ok?'success':'error']=$ok?'Saved successfully. No: '.$no:'Save failed.';
    header('Location: pawn-interest-collection.php'); exit;
}
?><!doctype html><html lang="en"><?php include('includes/head.php'); ?><body data-sidebar="dark">
<?php include('includes/pre-loader.php'); ?><div id="layout-wrapper"><?php include('includes/topbar.php'); ?>
<div class="vertical-menu"><div data-simplebar class="h-100"><?php include('includes/sidebar.php'); ?></div></div>
<div class="main-content"><div class="page-content"><div class="container-fluid">
<?php if($success): ?><div class="alert alert-success"><?php echo h($success); ?></div><?php endif; ?>
<?php if($error): ?><div class="alert alert-danger"><?php echo h($error); ?></div><?php endif; ?>
<h4 class="page-title mb-3">Interest Collection</h4><div class="card"><div class="card-body">
<form method="post" class="row g-3">
<div class="col-md-6"><label>Pawn Entry</label><select name="pawn_id" class="form-select" required><option value="">-- Select Pawn --</option><?php if($active) while($p=$active->fetch_assoc()): ?><option value="<?php echo (int)$p['id']; ?>" <?php echo $pawnId==(int)$p['id']?'selected':''; ?>><?php echo h($p['pawn_no'].' - '.$p['customer_name'].' - Balance ₹'.money($p['principal_balance'])); ?></option><?php endwhile; ?></select></div>
<div class="col-md-3"><label>Date</label><input type="date" name="entry_date" class="form-control" value="<?php echo date('Y-m-d'); ?>"></div>
<div class="col-md-3"><label>Amount</label><input type="number" step="0.01" name="amount" class="form-control" value="0"></div>
<div class="col-md-12"><label>Remarks</label><input name="remarks" class="form-control"></div><div class="col-md-12"><button class="btn btn-primary">Save</button></div>
</form></div></div></div></div><?php include('includes/footer.php'); ?></div></div>
<?php include('includes/rightbar.php'); ?><?php include('includes/scripts.php'); ?>
<style>.table td,.table th{vertical-align:middle}.page-title{font-weight:700}.card{border-radius:8px}</style></body></html>