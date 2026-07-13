<?php
require_once __DIR__ . '/includes/pawn_master_bootstrap.php';
$canView=pawn_permission($conn,'view')||pawn_permission($conn,'open');
$canCreate=pawn_permission($conn,'create');$canUpdate=pawn_permission($conn,'update');$canDelete=pawn_permission($conn,'delete');
if(!$canView){http_response_code(403);die('You do not have permission to view pawn categories.');}
$error='';$success=(string)($_SESSION['pawn_flash']??'');unset($_SESSION['pawn_flash']);
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  pawn_verify_csrf((string)($_POST['csrf_token']??''));
  $action=(string)($_POST['action']??'');
  $id=(int)($_POST['id']??0);
  if($action==='toggle'){
   if(!$canUpdate)throw new RuntimeException('Update permission is required.');
   $stmt=$conn->prepare("UPDATE pawn_categories SET is_active=IF(is_active=1,0,1) WHERE id=? AND business_id=?");$stmt->bind_param('ii',$id,$businessId);$stmt->execute();$stmt->close();
   pawn_audit($conn,$businessId,$branchId?:null,$userId,'pawn.categories','Update','pawn_categories',$id,'Changed pawn category status');
  }elseif($action==='delete'){
   if(!$canDelete)throw new RuntimeException('Delete permission is required.');
   $check=$conn->prepare("SELECT COUNT(*) used_count FROM pawn_entries WHERE pawn_category_id=? AND business_id=?");$check->bind_param('ii',$id,$businessId);$check->execute();$used=(int)$check->get_result()->fetch_assoc()['used_count'];$check->close();
   if($used>0)throw new RuntimeException('This category is already used in pawn entries. Deactivate it instead.');
   $stmt=$conn->prepare("DELETE FROM pawn_categories WHERE id=? AND business_id=?");$stmt->bind_param('ii',$id,$businessId);$stmt->execute();$stmt->close();
   pawn_audit($conn,$businessId,$branchId?:null,$userId,'pawn.categories','Delete','pawn_categories',$id,'Deleted pawn category');
  }
  $_SESSION['pawn_flash']='Category updated successfully.';header('Location: pawn-categories.php');exit;
 }catch(Throwable $e){$error=$e->getMessage();}
}
$stmt=$conn->prepare("SELECT pc.*,(SELECT COUNT(*) FROM pawn_entries pe WHERE pe.business_id=pc.business_id AND pe.pawn_category_id=pc.id) usage_count FROM pawn_categories pc WHERE pc.business_id=? ORDER BY pc.category_name");
$stmt->bind_param('i',$businessId);$stmt->execute();$rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=pawn_e($businessName)?> - Pawn Categories</title>
<?php include('includes/links.php'); ?>
<?php include('includes/pawn_master_styles.php'); ?>
</head>
<body>
<?php include('includes/sidebar.php'); ?>
<main class="app-main">
<?php include('includes/nav.php'); ?>
<div class="content-wrap">

<div class="page-head"><div><h1 class="page-title">Pawn Categories</h1><p class="page-subtitle">Valuation, purity, interest and loan percentage defaults.</p></div><?php if($canCreate):?><a href="pawn-category-add.php" class="btn btn-primary-theme"><i class="fa-solid fa-plus me-2"></i>Add Category</a><?php endif;?></div>
<?php if($success):?><div class="alert-box alert-success-box"><?=pawn_e($success)?></div><?php endif;?><?php if($error):?><div class="alert-box alert-danger-box"><?=pawn_e($error)?></div><?php endif;?>
<section class="ui-card table-card"><div class="ui-card-head"><div class="ui-card-title">Category List</div><div class="small text-muted"><?=count($rows)?> category(s)</div></div><div class="table-responsive"><table class="ui-table mobile-cards">
<thead><tr><th>Code</th><th>Category</th><th>Type</th><th>Metal / Purity</th><th>Default Interest</th><th>Max Loan</th><th>Valuation</th><th>Usage</th><th>Status</th><th>Actions</th></tr></thead><tbody>
<?php if(!$rows):?><tr><td colspan="10"><div class="empty-state">No pawn categories found.</div></td></tr><?php else:foreach($rows as $row):?><tr>
<td class="main-cell" data-label="Code"><strong><?=pawn_e($row['category_code'])?></strong></td><td data-label="Category"><?=pawn_e($row['category_name'])?><div class="small text-muted"><?=pawn_e($row['description']??'')?></div></td><td data-label="Type"><?=pawn_e($row['category_type']??'Ornament')?></td><td data-label="Metal / Purity"><?=pawn_e(trim(($row['metal_type']??'').' '.($row['purity_standard']??''))?:'-')?></td><td data-label="Interest"><?=pawn_e($row['default_interest_percent'])?>%</td><td data-label="Max Loan"><?=pawn_e($row['max_loan_percent']??70)?>%</td><td data-label="Valuation"><?=pawn_e($row['valuation_method']??'Weight')?></td><td data-label="Usage"><?=(int)$row['usage_count']?></td><td data-label="Status"><span class="badge-soft <?=$row['is_active']?'badge-success':'badge-muted'?>"><?=$row['is_active']?'Active':'Inactive'?></span></td>
<td class="actions-cell" data-label="Actions"><div class="actions"><?php if($canUpdate):?><a href="pawn-category-add.php?id=<?=(int)$row['id']?>" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-pen"></i></a><form method="post"><input type="hidden" name="csrf_token" value="<?=pawn_e($csrfToken)?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=(int)$row['id']?>"><button class="btn btn-sm btn-outline-secondary" title="Toggle status"><i class="fa-solid fa-power-off"></i></button></form><?php endif;?><?php if($canDelete):?><form method="post" onsubmit="return confirm('Delete this unused category?')"><input type="hidden" name="csrf_token" value="<?=pawn_e($csrfToken)?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=(int)$row['id']?>"><button class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-trash"></i></button></form><?php endif;?></div></td>
</tr><?php endforeach;endif;?></tbody></table></div></section>
<?php include('includes/footer.php'); ?>
</div>
</main>
<?php include('includes/script.php'); ?>
<script src="assets/js/script.js"></script>
</body>
</html>
