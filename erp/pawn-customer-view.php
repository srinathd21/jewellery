<?php
require_once __DIR__ . '/includes/pawn_master_bootstrap.php';
if (!(pawn_permission($conn,'view') || pawn_permission($conn,'open'))) { http_response_code(403); die('You do not have permission to view pawn customers.'); }
$canUpdate=pawn_permission($conn,'update');
$id=max(0,(int)($_GET['id']??0));
$stmt=$conn->prepare("SELECT pc.*,c.customer_code,c.customer_name,c.mobile,c.alternate_mobile,c.email,c.address_line1,c.address_line2,c.city,c.state,c.pincode,c.date_of_birth,c.anniversary_date
 FROM pawn_customers pc INNER JOIN customers c ON c.id=pc.customer_id WHERE pc.id=? AND pc.business_id=? LIMIT 1");
$stmt->bind_param('ii',$id,$businessId);$stmt->execute();$customer=$stmt->get_result()->fetch_assoc();$stmt->close();
if(!$customer){http_response_code(404);die('Pawn customer not found.');}
$stmt=$conn->prepare("SELECT COUNT(*) total_loans,SUM(status IN ('Active','Partially Paid')) active_loans,COALESCE(SUM(principal_amount),0) total_principal,COALESCE(SUM(balance_principal),0) outstanding,COALESCE(SUM(total_interest_collected),0) interest_collected FROM pawn_entries WHERE business_id=? AND customer_id=?");
$stmt->bind_param('ii',$businessId,$customer['customer_id']);$stmt->execute();$summary=$stmt->get_result()->fetch_assoc()?:[];$stmt->close();
$stmt=$conn->prepare("SELECT id,pawn_no,pawn_date,principal_amount,balance_principal,interest_percent,interest_period,due_date,status FROM pawn_entries WHERE business_id=? AND customer_id=? ORDER BY id DESC LIMIT 10");
$stmt->bind_param('ii',$businessId,$customer['customer_id']);$stmt->execute();$loans=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
$flash=(string)($_SESSION['pawn_flash']??'');unset($_SESSION['pawn_flash']);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=pawn_e($businessName)?> - Pawn Customer Details</title>
<?php include('includes/links.php'); ?>
<?php include('includes/pawn_master_styles.php'); ?>
</head>
<body>
<?php include('includes/sidebar.php'); ?>
<main class="app-main">
<?php include('includes/nav.php'); ?>
<div class="content-wrap">

<div class="page-head"><div><h1 class="page-title">Pawn Customer Details</h1><p class="page-subtitle">Profile, KYC and recent pawn activity.</p></div><div><?php if($canUpdate):?><a href="pawn-customer-add.php?id=<?=$id?>" class="btn btn-primary-theme me-2"><i class="fa-solid fa-pen me-2"></i>Edit</a><?php endif;?><a href="pawn-customers.php" class="btn btn-light">Back</a></div></div>
<?php if($flash):?><div class="alert-box alert-success-box"><?=pawn_e($flash)?></div><?php endif;?>
<div class="stat-grid">
<div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-file-contract"></i></div><div><div class="stat-label">Total Loans</div><div class="stat-value"><?=(int)($summary['total_loans']??0)?></div></div></div>
<div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-circle-play"></i></div><div><div class="stat-label">Active Loans</div><div class="stat-value"><?=(int)($summary['active_loans']??0)?></div></div></div>
<div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-indian-rupee-sign"></i></div><div><div class="stat-label">Outstanding</div><div class="stat-value"><?=pawn_e($currencySymbol)?><?=pawn_money($summary['outstanding']??0)?></div></div></div>
<div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-percent"></i></div><div><div class="stat-label">Interest Collected</div><div class="stat-value"><?=pawn_e($currencySymbol)?><?=pawn_money($summary['interest_collected']??0)?></div></div></div>
</div>
<section class="ui-card"><div class="ui-card-body"><div class="profile-grid">
<div class="text-center"><?php if($customer['photo_path']):?><img src="<?=pawn_e($customer['photo_path'])?>" class="doc-preview" style="width:140px;height:140px;border-radius:50%"><?php else:?><div class="avatar mx-auto" style="width:120px;height:120px;font-size:34px"><?=pawn_e(strtoupper(substr($customer['customer_name'],0,2)))?></div><?php endif;?><h3 class="mt-3 mb-1"><?=pawn_e($customer['customer_name'])?></h3><div class="text-muted"><?=pawn_e($customer['customer_code'])?></div><div class="mt-2"><span class="badge-soft <?=$customer['kyc_verified']?'badge-success':'badge-warning'?>"><?=$customer['kyc_verified']?'KYC Verified':'KYC Pending'?></span></div></div>
<div class="info-list">
<?php $info=['Mobile'=>$customer['mobile'],'Alternate Mobile'=>$customer['alternate_mobile'],'Email'=>$customer['email'],'Guardian'=>$customer['guardian_name'],'Occupation'=>$customer['occupation'],'Annual Income'=>$currencySymbol.pawn_money($customer['annual_income']),'Credit Limit'=>$currencySymbol.pawn_money($customer['credit_limit']),'Risk'=>$customer['risk_category'],'Date of Birth'=>$customer['date_of_birth'],'Address'=>trim($customer['address_line1'].' '.$customer['address_line2'].' '.$customer['city'].' '.$customer['state'].' '.$customer['pincode']),'Reference'=>trim($customer['reference_name'].' '.$customer['reference_mobile'])]; foreach($info as $k=>$v):?>
<div class="info-item"><div class="info-key"><?=pawn_e($k)?></div><div class="info-value"><?=pawn_e($v?:'-')?></div></div><?php endforeach;?>
</div></div></div></section>
<section class="ui-card table-card"><div class="ui-card-head"><div class="ui-card-title">Recent Loans</div><a href="pawn-list.php?customer_id=<?=(int)$customer['customer_id']?>" class="btn btn-sm btn-outline-primary">View all</a></div><div class="table-responsive"><table class="ui-table mobile-cards"><thead><tr><th>Pawn No</th><th>Date</th><th>Principal</th><th>Balance</th><th>Interest</th><th>Due Date</th><th>Status</th><th></th></tr></thead><tbody>
<?php if(!$loans):?><tr><td colspan="8"><div class="empty-state">No pawn loans found.</div></td></tr><?php else:foreach($loans as $loan):?><tr><td class="main-cell" data-label="Pawn No"><strong><?=pawn_e($loan['pawn_no'])?></strong></td><td data-label="Date"><?=pawn_e(date('d-m-Y',strtotime($loan['pawn_date'])))?></td><td data-label="Principal"><?=pawn_e($currencySymbol)?><?=pawn_money($loan['principal_amount'])?></td><td data-label="Balance"><?=pawn_e($currencySymbol)?><?=pawn_money($loan['balance_principal'])?></td><td data-label="Interest"><?=pawn_e($loan['interest_percent'])?>% <?=pawn_e($loan['interest_period'])?></td><td data-label="Due Date"><?=pawn_e($loan['due_date']?date('d-m-Y',strtotime($loan['due_date'])):'-')?></td><td data-label="Status"><span class="badge-soft <?=$loan['status']==='Closed'?'badge-success':($loan['status']==='Auctioned'?'badge-danger':'badge-info')?>"><?=pawn_e($loan['status'])?></span></td><td class="actions-cell" data-label="Actions"><a href="pawn-view.php?id=<?=(int)$loan['id']?>" class="btn btn-sm btn-outline-info"><i class="fa-solid fa-eye"></i></a></td></tr><?php endforeach;endif;?>
</tbody></table></div></section>
<?php include('includes/footer.php'); ?>
</div>
</main>
<?php include('includes/script.php'); ?>
<script src="assets/js/script.js"></script>
</body>
</html>
