<?php
require_once __DIR__ . '/includes/pawn_master_bootstrap.php';
$id=max(0,(int)($_GET['id']??$_POST['id']??0));
$canSave=$id>0?pawn_permission($conn,'update'):pawn_permission($conn,'create');
if(!$canSave){http_response_code(403);die('You do not have permission to save pawn categories.');}
$form=['category_code'=>'','category_name'=>'','category_type'=>'Ornament','metal_type'=>'Gold','purity_standard'=>'22K (916)','min_purity_percent'=>'91.60','max_purity_percent'=>'91.60','default_interest_percent'=>'1.00','max_loan_percent'=>'70.00','storage_fee_percent'=>'0.00','valuation_method'=>'Weight','requires_certificate'=>0,'requires_valuation'=>1,'description'=>'','is_active'=>1];
$old=null;
if($id>0){$stmt=$conn->prepare("SELECT * FROM pawn_categories WHERE id=? AND business_id=?");$stmt->bind_param('ii',$id,$businessId);$stmt->execute();$old=$stmt->get_result()->fetch_assoc();$stmt->close();if(!$old){http_response_code(404);die('Category not found.');}$form=array_merge($form,$old);}
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  pawn_verify_csrf((string)($_POST['csrf_token']??''));
  $name=trim((string)($_POST['category_name']??''));if(strlen($name)<2)throw new RuntimeException('Category name is required.');
  $type=in_array($_POST['category_type']??'', ['Ornament','Metal','Document','Other'],true)?$_POST['category_type']:'Other';
  $metal=in_array($_POST['metal_type']??'', ['Gold','Silver','Platinum','Other'],true)?$_POST['metal_type']:null;
  if(!in_array($type,['Ornament','Metal'],true))$metal=null;
  $purity=trim((string)($_POST['purity_standard']??''));$min=$_POST['min_purity_percent']!==''?(float)$_POST['min_purity_percent']:null;$max=$_POST['max_purity_percent']!==''?(float)$_POST['max_purity_percent']:null;
  $interest=max(0,(float)($_POST['default_interest_percent']??0));$loan=(float)($_POST['max_loan_percent']??70);$storage=max(0,(float)($_POST['storage_fee_percent']??0));
  if($loan<0||$loan>100)throw new RuntimeException('Maximum loan percentage must be between 0 and 100.');
  if($min!==null&&$max!==null&&($min>$max||$min<0||$max>100))throw new RuntimeException('Purity range is invalid.');
  $valuation=in_array($_POST['valuation_method']??'', ['Weight','Piece','Stone','Combined'],true)?$_POST['valuation_method']:'Weight';
  $cert=isset($_POST['requires_certificate'])?1:0;$valuationRequired=isset($_POST['requires_valuation'])?1:0;$active=isset($_POST['is_active'])?1:0;$description=trim((string)($_POST['description']??''));
  if($id===0){
   $prefix=['Ornament'=>'ORN','Metal'=>'MTL','Document'=>'DOC','Other'=>'CAT'][$type];
   $stmt=$conn->prepare("SELECT category_code FROM pawn_categories WHERE business_id=? AND category_code LIKE CONCAT(?,'%') ORDER BY id DESC LIMIT 1");$stmt->bind_param('is',$businessId,$prefix);$stmt->execute();$last=$stmt->get_result()->fetch_assoc()['category_code']??'';$stmt->close();
   $number=$last?((int)substr($last,strlen($prefix))+1):1;$code=$prefix.str_pad((string)$number,4,'0',STR_PAD_LEFT);
   $stmt=$conn->prepare("INSERT INTO pawn_categories (business_id,category_code,category_name,category_type,metal_type,purity_standard,min_purity_percent,max_purity_percent,default_interest_percent,max_loan_percent,storage_fee_percent,valuation_method,requires_certificate,requires_valuation,description,is_active,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
   $stmt->bind_param('isssssdddddsiisii',$businessId,$code,$name,$type,$metal,$purity,$min,$max,$interest,$loan,$storage,$valuation,$cert,$valuationRequired,$description,$active,$userId);
  }else{
   $stmt=$conn->prepare("UPDATE pawn_categories SET category_name=?,category_type=?,metal_type=?,purity_standard=?,min_purity_percent=?,max_purity_percent=?,default_interest_percent=?,max_loan_percent=?,storage_fee_percent=?,valuation_method=?,requires_certificate=?,requires_valuation=?,description=?,is_active=? WHERE id=? AND business_id=?");
   $stmt->bind_param('ssssdddddsiisiii',$name,$type,$metal,$purity,$min,$max,$interest,$loan,$storage,$valuation,$cert,$valuationRequired,$description,$active,$id,$businessId);
  }
  if(!$stmt->execute())throw new RuntimeException($stmt->errno===1062?'Category name or code already exists.':'Unable to save category: '.$stmt->error);
  $saved=$id?:$stmt->insert_id;$stmt->close();
  pawn_audit($conn,$businessId,$branchId?:null,$userId,'pawn.categories',$id?'Update':'Create','pawn_categories',$saved,($id?'Updated':'Created').' pawn category',$old,['category_name'=>$name,'interest'=>$interest,'max_loan_percent'=>$loan]);
  $_SESSION['pawn_flash']='Pawn category saved successfully.';header('Location: pawn-categories.php');exit;
 }catch(Throwable $e){$error=$e->getMessage();$form=array_merge($form,$_POST);}
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=pawn_e($businessName)?> - Pawn Category</title>
<?php include('includes/links.php'); ?>
<?php include('includes/pawn_master_styles.php'); ?>
</head>
<body>
<?php include('includes/sidebar.php'); ?>
<main class="app-main">
<?php include('includes/nav.php'); ?>
<div class="content-wrap">

<div class="page-head"><div><h1 class="page-title"><?=$id?'Edit':'Add'?> Pawn Category</h1><p class="page-subtitle">Configure default valuation and loan rules.</p></div><a href="pawn-categories.php" class="btn btn-light"><i class="fa-solid fa-arrow-left me-2"></i>Back</a></div>
<?php if($error):?><div class="alert-box alert-danger-box"><?=pawn_e($error)?></div><?php endif;?>
<form method="post"><input type="hidden" name="csrf_token" value="<?=pawn_e($csrfToken)?>"><input type="hidden" name="id" value="<?=$id?>">
<section class="ui-card"><div class="ui-card-head"><div class="ui-card-title">Basic Information</div></div><div class="ui-card-body"><div class="form-grid">
<div class="col-6"><label class="form-label required">Category Name</label><input name="category_name" class="form-control" required value="<?=pawn_e($form['category_name'])?>" placeholder="Gold Chain"></div>
<div class="col-3"><label class="form-label">Category Type</label><select name="category_type" id="category_type" class="form-select"><?php foreach(['Ornament','Metal','Document','Other'] as $v):?><option value="<?=$v?>" <?=$form['category_type']===$v?'selected':''?>><?=$v?></option><?php endforeach;?></select></div>
<div class="col-3"><label class="form-label">Status</label><div class="form-check form-switch mt-2"><input class="form-check-input" type="checkbox" name="is_active" id="is_active" <?=$form['is_active']?'checked':''?>><label class="form-check-label" for="is_active">Active</label></div></div>
<div class="col-4 metal-field"><label class="form-label">Metal Type</label><select name="metal_type" class="form-select"><?php foreach(['Gold','Silver','Platinum','Other'] as $v):?><option value="<?=$v?>" <?=$form['metal_type']===$v?'selected':''?>><?=$v?></option><?php endforeach;?></select></div>
<div class="col-4 metal-field"><label class="form-label">Purity Standard</label><input name="purity_standard" class="form-control" value="<?=pawn_e($form['purity_standard'])?>" placeholder="22K (916)"></div>
<div class="col-2 metal-field"><label class="form-label">Min Purity %</label><input type="number" min="0" max="100" step=".01" name="min_purity_percent" class="form-control" value="<?=pawn_e($form['min_purity_percent'])?>"></div>
<div class="col-2 metal-field"><label class="form-label">Max Purity %</label><input type="number" min="0" max="100" step=".01" name="max_purity_percent" class="form-control" value="<?=pawn_e($form['max_purity_percent'])?>"></div>
</div></div></section>
<section class="ui-card"><div class="ui-card-head"><div class="ui-card-title">Loan & Valuation Defaults</div></div><div class="ui-card-body"><div class="form-grid">
<div class="col-3"><label class="form-label required">Default Interest %</label><input type="number" min="0" step=".001" name="default_interest_percent" class="form-control" value="<?=pawn_e($form['default_interest_percent'])?>"></div>
<div class="col-3"><label class="form-label required">Maximum Loan %</label><input type="number" min="0" max="100" step=".01" name="max_loan_percent" class="form-control" value="<?=pawn_e($form['max_loan_percent'])?>"></div>
<div class="col-3"><label class="form-label">Storage Fee %</label><input type="number" min="0" step=".01" name="storage_fee_percent" class="form-control" value="<?=pawn_e($form['storage_fee_percent'])?>"></div>
<div class="col-3"><label class="form-label">Valuation Method</label><select name="valuation_method" class="form-select"><?php foreach(['Weight','Piece','Stone','Combined'] as $v):?><option value="<?=$v?>" <?=$form['valuation_method']===$v?'selected':''?>><?=$v?></option><?php endforeach;?></select></div>
<div class="col-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="requires_certificate" id="requires_certificate" <?=$form['requires_certificate']?'checked':''?>><label class="form-check-label" for="requires_certificate">Requires certificate</label></div></div>
<div class="col-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="requires_valuation" id="requires_valuation" <?=$form['requires_valuation']?'checked':''?>><label class="form-check-label" for="requires_valuation">Requires professional valuation</label></div></div>
<div class="col-12"><label class="form-label">Description</label><textarea name="description" class="form-control"><?=pawn_e($form['description'])?></textarea></div>
</div></div></section>
<div class="text-end mb-4"><a href="pawn-categories.php" class="btn btn-light me-2">Cancel</a><button class="btn btn-theme"><i class="fa-solid fa-floppy-disk me-2"></i>Save Category</button></div>
</form>
<script>
document.addEventListener('DOMContentLoaded',()=>{const type=document.getElementById('category_type');const toggle=()=>document.querySelectorAll('.metal-field').forEach(el=>el.style.display=['Ornament','Metal'].includes(type.value)?'block':'none');type.addEventListener('change',toggle);toggle();});
</script>
<?php include('includes/footer.php'); ?>
</div>
</main>
<?php include('includes/script.php'); ?>
<script src="assets/js/script.js"></script>
</body>
</html>
