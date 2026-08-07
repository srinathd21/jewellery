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
<?php
$currentPage='chit-create'; $pageTitle='Create Chit';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $group_no=trim($_POST['group_no']??''); if($group_no==='') $group_no=nextNo($conn,'chit_groups','group_no','CH'.date('Ym'),$businessId);
    $group_name=trim($_POST['group_name']??''); $chit_type=$_POST['chit_type']??'Money'; $start_date=$_POST['start_date']??date('Y-m-d');
    $total_members=(int)($_POST['total_members']??0); $total_months=(int)($_POST['total_months']??0); $installment_amount=(float)($_POST['installment_amount']??0); $chit_value=(float)($_POST['chit_value']??0);
    $auction_type=$_POST['auction_type']??'Auction'; $auction_day=($_POST['auction_day']!=='')?(int)$_POST['auction_day']:null; $grace_days=(int)($_POST['grace_days']??0); $late_fee_amount=(float)($_POST['late_fee_amount']??0); $notes=trim($_POST['notes']??'');
    if($group_name===''||$total_members<=0||$total_months<=0){ $_SESSION['error']='Enter group name, members and months.'; }
    else { $end_date=date('Y-m-d', strtotime('+'.($total_months-1).' months', strtotime($start_date)));
        $st=$conn->prepare("INSERT INTO chit_groups (business_id,group_no,group_name,chit_type,start_date,end_date,total_members,total_months,installment_amount,chit_value,auction_type,auction_day,grace_days,late_fee_amount,status,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,'Active',?,?)");
        $st->bind_param('isssssiiddsiidsi',$businessId,$group_no,$group_name,$chit_type,$start_date,$end_date,$total_members,$total_months,$installment_amount,$chit_value,$auction_type,$auction_day,$grace_days,$late_fee_amount,$notes,$userId);
        if($st->execute()){ $gid=$st->insert_id; $st->close();
            $ins=$conn->prepare("INSERT INTO chit_installments (business_id,group_id,installment_no,due_date,status) VALUES (?,?,?,?, 'Open')");
            for($i=1;$i<=$total_months;$i++){ $due=date('Y-m-d', strtotime('+'.($i-1).' months', strtotime($start_date))); $ins->bind_param('iiis',$businessId,$gid,$i,$due); $ins->execute(); } $ins->close();
            $_SESSION['success']='Chit group created successfully.'; header('Location: chit-groups.php'); exit;
        } else { $_SESSION['error']='Failed: '.$st->error; $st->close(); }
    }
}
?>

<!doctype html><html lang="en"><?php include('includes/head.php'); ?><body data-sidebar="dark"><?php include('includes/pre-loader.php'); ?><div id="layout-wrapper"><?php include('includes/topbar.php'); ?><div class="vertical-menu"><div data-simplebar class="h-100"><?php include('includes/sidebar.php'); ?></div></div><div class="main-content"><div class="page-content"><div class="container-fluid">
<?php if(isset($_SESSION['success'])): ?><div class="alert alert-success alert-dismissible fade show"><?php echo h($_SESSION['success']); unset($_SESSION['success']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<?php if(isset($_SESSION['error'])): ?><div class="alert alert-danger alert-dismissible fade show"><?php echo h($_SESSION['error']); unset($_SESSION['error']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

<div class="card"><div class="card-body"><div class="d-flex justify-content-between mb-3"><h4>Create Chit</h4><a href="chit-groups.php" class="btn btn-secondary btn-sm">Back</a></div>
<form method="post"><div class="row g-3">
<div class="col-md-3"><label>Group No</label><input name="group_no" class="form-control" placeholder="Auto"></div>
<div class="col-md-5"><label>Group Name *</label><input name="group_name" class="form-control" required></div>
<div class="col-md-2"><label>Chit Type</label><select name="chit_type" class="form-select"><option>Money</option><option>Silver</option><option>Gold</option></select></div>
<div class="col-md-2"><label>Start Date</label><input type="date" name="start_date" class="form-control" value="<?php echo date('Y-m-d'); ?>"></div>
<div class="col-md-2"><label>Total Members *</label><input type="number" name="total_members" class="form-control" required></div>
<div class="col-md-2"><label>Total Months *</label><input type="number" name="total_months" class="form-control" required></div>
<div class="col-md-2"><label>Installment</label><input type="number" step="0.01" name="installment_amount" class="form-control"></div>
<div class="col-md-2"><label>Chit Value</label><input type="number" step="0.01" name="chit_value" class="form-control"></div>
<div class="col-md-2"><label>Auction Type</label><select name="auction_type" class="form-select"><option>Auction</option><option>Lucky Draw</option><option>Manual</option></select></div>
<div class="col-md-2"><label>Auction Day</label><input type="number" min="1" max="31" name="auction_day" class="form-control"></div>
<div class="col-md-2"><label>Grace Days</label><input type="number" name="grace_days" class="form-control" value="0"></div>
<div class="col-md-2"><label>Late Fee</label><input type="number" step="0.01" name="late_fee_amount" class="form-control" value="0"></div>
<div class="col-md-12"><label>Notes</label><textarea name="notes" class="form-control"></textarea></div>
</div><div class="mt-3"><button class="btn btn-primary">Save Chit</button></div></form></div></div>

</div></div><?php include('includes/footer.php'); ?></div></div><?php include('includes/rightbar.php'); ?><?php include('includes/scripts.php'); ?></body></html>
