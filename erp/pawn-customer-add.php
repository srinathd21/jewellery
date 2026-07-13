<?php
require_once __DIR__ . '/includes/pawn_master_bootstrap.php';
$editId = max(0, (int)($_GET['id'] ?? $_POST['edit_id'] ?? 0));
$canSave = $editId > 0 ? pawn_permission($conn, 'update') : pawn_permission($conn, 'create');
if (!$canSave) { http_response_code(403); die('You do not have permission to save pawn customers.'); }

$form = [
 'customer_id'=>0,'customer_name'=>'','mobile'=>'','email'=>'','alternate_mobile'=>'','address_line1'=>'','address_line2'=>'','city'=>'','state'=>'','pincode'=>'',
 'date_of_birth'=>'','anniversary_date'=>'','guardian_name'=>'','occupation'=>'','annual_income'=>'0','reference_name'=>'','reference_mobile'=>'',
 'credit_limit'=>'0','risk_category'=>'Low','notes'=>'','kyc_verified'=>0,'photo_path'=>'','signature_path'=>'','kyc_document_path'=>''
];
$oldRow = null;
if ($editId > 0) {
    $stmt=$conn->prepare("SELECT pc.*,c.customer_name,c.mobile,c.email,c.alternate_mobile,c.address_line1,c.address_line2,c.city,c.state,c.pincode,c.date_of_birth,c.anniversary_date
      FROM pawn_customers pc INNER JOIN customers c ON c.id=pc.customer_id WHERE pc.id=? AND pc.business_id=? LIMIT 1");
    $stmt->bind_param('ii',$editId,$businessId);$stmt->execute();$oldRow=$stmt->get_result()->fetch_assoc();$stmt->close();
    if(!$oldRow){http_response_code(404);die('Pawn customer not found.');}
    $form=array_merge($form,$oldRow);
}
$customers=[];
$stmt=$conn->prepare("SELECT c.id,c.customer_code,c.customer_name,c.mobile FROM customers c
 LEFT JOIN pawn_customers pc ON pc.business_id=c.business_id AND pc.customer_id=c.id
 WHERE c.business_id=? AND c.is_active=1 AND (pc.id IS NULL OR pc.id=?) ORDER BY c.customer_name");
$stmt->bind_param('ii',$businessId,$editId);$stmt->execute();$customers=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();

$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  pawn_verify_csrf((string)($_POST['csrf_token']??''));
  foreach($form as $k=>$v){ if(isset($_POST[$k])) $form[$k]=is_string($_POST[$k])?trim($_POST[$k]):$_POST[$k]; }
  $existingCustomerId=(int)($_POST['customer_id']??0);
  $name=trim((string)($_POST['customer_name']??''));
  if($existingCustomerId<=0 && $name==='') throw new RuntimeException('Select an existing customer or enter a new customer name.');
  $risk=in_array((string)($_POST['risk_category']??'Low'),['Low','Medium','High'],true)?(string)$_POST['risk_category']:'Low';
  $conn->begin_transaction();

  if($editId>0){
    $customerId=(int)$oldRow['customer_id'];
  } elseif($existingCustomerId>0){
    $customerId=$existingCustomerId;
    $check=$conn->prepare("SELECT id FROM customers WHERE id=? AND business_id=? AND is_active=1");$check->bind_param('ii',$customerId,$businessId);$check->execute();
    if(!$check->get_result()->fetch_assoc()) throw new RuntimeException('Selected customer is invalid.');$check->close();
  } else {
    $code='CUS-'.date('ym').'-'.str_pad((string)random_int(1,99999),5,'0',STR_PAD_LEFT);
    $type='Pawn';
    $stmt=$conn->prepare("INSERT INTO customers (business_id,home_branch_id,customer_code,customer_type,customer_name,mobile,alternate_mobile,email,address_line1,address_line2,city,state,pincode,date_of_birth,anniversary_date,notes,is_active)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)");
    $homeBranch=$branchId>0?$branchId:null;
    $mobile=trim((string)($_POST['mobile']??''));$alt=trim((string)($_POST['alternate_mobile']??''));$email=trim((string)($_POST['email']??''));
    $a1=trim((string)($_POST['address_line1']??''));$a2=trim((string)($_POST['address_line2']??''));$city=trim((string)($_POST['city']??''));$state=trim((string)($_POST['state']??''));$pin=trim((string)($_POST['pincode']??''));
    $dob=$_POST['date_of_birth']!==''?$_POST['date_of_birth']:null;$ann=$_POST['anniversary_date']!==''?$_POST['anniversary_date']:null;$customerNotes=trim((string)($_POST['notes']??''));
    $stmt->bind_param('iissssssssssssss',$businessId,$homeBranch,$code,$type,$name,$mobile,$alt,$email,$a1,$a2,$city,$state,$pin,$dob,$ann,$customerNotes);
    $stmt->execute();$customerId=$stmt->insert_id;$stmt->close();
  }

  $uploadDir=__DIR__.'/uploads/pawn_customers/';
  if(!is_dir($uploadDir) && !mkdir($uploadDir,0755,true) && !is_dir($uploadDir)) throw new RuntimeException('Unable to create upload directory.');
  $paths=['photo_path'=>(string)($oldRow['photo_path']??''),'signature_path'=>(string)($oldRow['signature_path']??''),'kyc_document_path'=>(string)($oldRow['kyc_document_path']??'')];
  $rules=['photo'=>['photo_path',['image/jpeg','image/png','image/webp'],3*1024*1024],'signature'=>['signature_path',['image/jpeg','image/png','image/webp'],2*1024*1024],'kyc_document'=>['kyc_document_path',['image/jpeg','image/png','image/webp','application/pdf'],5*1024*1024]];
  $finfo=new finfo(FILEINFO_MIME_TYPE);
  foreach($rules as $input=>$rule){
    if(isset($_FILES[$input]) && $_FILES[$input]['error']!==UPLOAD_ERR_NO_FILE){
      if($_FILES[$input]['error']!==UPLOAD_ERR_OK) throw new RuntimeException("Upload failed for {$input}.");
      if((int)$_FILES[$input]['size']>$rule[2]) throw new RuntimeException("{$input} file is too large.");
      $mime=$finfo->file($_FILES[$input]['tmp_name']);
      if(!in_array($mime,$rule[1],true)) throw new RuntimeException("Invalid {$input} file type.");
      $ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','application/pdf'=>'pdf'][$mime];
      $filename=$input.'_'.bin2hex(random_bytes(10)).'.'.$ext;
      if(!move_uploaded_file($_FILES[$input]['tmp_name'],$uploadDir.$filename)) throw new RuntimeException("Unable to save {$input}.");
      $paths[$rule[0]]='uploads/pawn_customers/'.$filename;
    }
  }

  $guardian=trim((string)($_POST['guardian_name']??''));$occupation=trim((string)($_POST['occupation']??''));$income=max(0,(float)($_POST['annual_income']??0));
  $refName=trim((string)($_POST['reference_name']??''));$refMobile=trim((string)($_POST['reference_mobile']??''));$credit=max(0,(float)($_POST['credit_limit']??0));
  $notes=trim((string)($_POST['notes']??''));$kyc=isset($_POST['kyc_verified'])?1:0;
  if($editId>0){
    $stmt=$conn->prepare("UPDATE pawn_customers SET guardian_name=?,occupation=?,annual_income=?,reference_name=?,reference_mobile=?,photo_path=?,signature_path=?,kyc_document_path=?,kyc_verified=?,credit_limit=?,risk_category=?,notes=? WHERE id=? AND business_id=?");
    $stmt->bind_param('ssdsssssidssii',$guardian,$occupation,$income,$refName,$refMobile,$paths['photo_path'],$paths['signature_path'],$paths['kyc_document_path'],$kyc,$credit,$risk,$notes,$editId,$businessId);
  } else {
    $stmt=$conn->prepare("INSERT INTO pawn_customers (business_id,customer_id,guardian_name,occupation,annual_income,reference_name,reference_mobile,photo_path,signature_path,kyc_document_path,kyc_verified,credit_limit,risk_category,notes,created_by)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->bind_param('iissdsssssidssi',$businessId,$customerId,$guardian,$occupation,$income,$refName,$refMobile,$paths['photo_path'],$paths['signature_path'],$paths['kyc_document_path'],$kyc,$credit,$risk,$notes,$userId);
  }
  if(!$stmt->execute()) throw new RuntimeException($stmt->errno===1062?'This customer is already registered for pawn broking.':'Unable to save pawn customer: '.$stmt->error);
  $savedId=$editId>0?$editId:$stmt->insert_id;$stmt->close();
  pawn_audit($conn,$businessId,$branchId?:null,$userId,'pawn.customers',$editId>0?'Update':'Create','pawn_customers',$savedId,($editId>0?'Updated':'Created').' pawn customer',$oldRow,['customer_id'=>$customerId,'risk_category'=>$risk,'kyc_verified'=>$kyc,'credit_limit'=>$credit]);
  $conn->commit();
  $_SESSION['pawn_flash']='Pawn customer saved successfully.';
  header('Location: pawn-customer-view.php?id='.$savedId);exit;
 }catch(Throwable $e){
  if($conn->errno || $conn->connect_errno===0){try{$conn->rollback();}catch(Throwable $ignore){}}
  $error=$e->getMessage();
 }
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=pawn_e($businessName)?> - Pawn Customer</title>
<?php include('includes/links.php'); ?>
<?php include('includes/pawn_master_styles.php'); ?>
</head>
<body>
<?php include('includes/sidebar.php'); ?>
<main class="app-main">
<?php include('includes/nav.php'); ?>
<div class="content-wrap">

<div class="page-head"><div><h1 class="page-title"><?=$editId>0?'Edit':'Add'?> Pawn Customer</h1><p class="page-subtitle">Customer master, KYC documents and risk settings.</p></div><a href="pawn-customers.php" class="btn btn-light"><i class="fa-solid fa-arrow-left me-2"></i>Back</a></div>
<?php if($error): ?><div class="alert-box alert-danger-box"><?=pawn_e($error)?></div><?php endif; ?>
<form method="post" enctype="multipart/form-data">
<input type="hidden" name="csrf_token" value="<?=pawn_e($csrfToken)?>"><input type="hidden" name="edit_id" value="<?=$editId?>">
<section class="ui-card"><div class="ui-card-head"><div class="ui-card-title">Customer Information</div></div><div class="ui-card-body">
<div class="form-grid">
<?php if($editId===0): ?><div class="col-6"><label class="form-label">Link Existing Customer</label><select name="customer_id" id="customer_id" class="form-select"><option value="0">Create a new customer</option><?php foreach($customers as $c):?><option value="<?=(int)$c['id']?>"><?=pawn_e($c['customer_name'].' · '.$c['customer_code'].' · '.$c['mobile'])?></option><?php endforeach;?></select><div class="help">Choose an existing customer, or leave this as “Create a new customer”.</div></div><?php endif;?>
<div class="col-6 new-customer"><label class="form-label required">Customer Name</label><input name="customer_name" class="form-control" value="<?=pawn_e($form['customer_name'])?>"></div>
<div class="col-4 new-customer"><label class="form-label">Mobile</label><input name="mobile" class="form-control" maxlength="20" value="<?=pawn_e($form['mobile'])?>"></div>
<div class="col-4 new-customer"><label class="form-label">Alternate Mobile</label><input name="alternate_mobile" class="form-control" maxlength="20" value="<?=pawn_e($form['alternate_mobile'])?>"></div>
<div class="col-4 new-customer"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?=pawn_e($form['email'])?>"></div>
<div class="col-6 new-customer"><label class="form-label">Address Line 1</label><input name="address_line1" class="form-control" value="<?=pawn_e($form['address_line1'])?>"></div>
<div class="col-6 new-customer"><label class="form-label">Address Line 2</label><input name="address_line2" class="form-control" value="<?=pawn_e($form['address_line2'])?>"></div>
<div class="col-4 new-customer"><label class="form-label">City</label><input name="city" class="form-control" value="<?=pawn_e($form['city'])?>"></div>
<div class="col-4 new-customer"><label class="form-label">State</label><input name="state" class="form-control" value="<?=pawn_e($form['state'])?>"></div>
<div class="col-4 new-customer"><label class="form-label">Pincode</label><input name="pincode" class="form-control" value="<?=pawn_e($form['pincode'])?>"></div>
<div class="col-6 new-customer"><label class="form-label">Date of Birth</label><input type="date" name="date_of_birth" class="form-control" value="<?=pawn_e($form['date_of_birth'])?>"></div>
<div class="col-6 new-customer"><label class="form-label">Anniversary Date</label><input type="date" name="anniversary_date" class="form-control" value="<?=pawn_e($form['anniversary_date'])?>"></div>
</div></div></section>
<section class="ui-card"><div class="ui-card-head"><div class="ui-card-title">Pawn Profile</div></div><div class="ui-card-body"><div class="form-grid">
<div class="col-4"><label class="form-label">Guardian / Father / Spouse</label><input name="guardian_name" class="form-control" value="<?=pawn_e($form['guardian_name'])?>"></div>
<div class="col-4"><label class="form-label">Occupation</label><select name="occupation" class="form-select"><option value="">Select</option><?php foreach(['Business','Salaried','Self Employed','Professional','Retired','Housewife','Student','Other'] as $v):?><option value="<?=$v?>" <?=$form['occupation']===$v?'selected':''?>><?=$v?></option><?php endforeach;?></select></div>
<div class="col-4"><label class="form-label">Annual Income</label><input type="number" min="0" step=".01" name="annual_income" class="form-control" value="<?=pawn_e($form['annual_income'])?>"></div>
<div class="col-6"><label class="form-label">Reference Name</label><input name="reference_name" class="form-control" value="<?=pawn_e($form['reference_name'])?>"></div>
<div class="col-6"><label class="form-label">Reference Mobile</label><input name="reference_mobile" class="form-control" value="<?=pawn_e($form['reference_mobile'])?>"></div>
<div class="col-4"><label class="form-label">Credit Limit</label><input type="number" min="0" step=".01" name="credit_limit" class="form-control" value="<?=pawn_e($form['credit_limit'])?>"></div>
<div class="col-4"><label class="form-label">Risk Category</label><select name="risk_category" class="form-select"><?php foreach(['Low','Medium','High'] as $v):?><option value="<?=$v?>" <?=$form['risk_category']===$v?'selected':''?>><?=$v?></option><?php endforeach;?></select></div>
<div class="col-4 d-flex align-items-end"><div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="kyc_verified" id="kyc_verified" <?=$form['kyc_verified']?'checked':''?>><label class="form-check-label" for="kyc_verified">KYC Verified</label></div></div>
<div class="col-12"><label class="form-label">Notes</label><textarea name="notes" class="form-control"><?=pawn_e($form['notes'])?></textarea></div>
</div></div></section>
<section class="ui-card"><div class="ui-card-head"><div class="ui-card-title">Documents</div></div><div class="ui-card-body"><div class="form-grid">
<div class="col-4"><label class="form-label">Customer Photo</label><input type="file" name="photo" class="form-control" accept=".jpg,.jpeg,.png,.webp"><?php if($form['photo_path']):?><img class="doc-preview mt-2" src="<?=pawn_e($form['photo_path'])?>"><?php endif;?></div>
<div class="col-4"><label class="form-label">Signature</label><input type="file" name="signature" class="form-control" accept=".jpg,.jpeg,.png,.webp"><?php if($form['signature_path']):?><img class="doc-preview mt-2" src="<?=pawn_e($form['signature_path'])?>"><?php endif;?></div>
<div class="col-4"><label class="form-label">KYC Document</label><input type="file" name="kyc_document" class="form-control" accept=".jpg,.jpeg,.png,.webp,.pdf"><?php if($form['kyc_document_path']):?><div class="mt-2"><a target="_blank" href="<?=pawn_e($form['kyc_document_path'])?>">View current document</a></div><?php endif;?></div>
</div></div></section>
<div class="text-end mb-4"><a href="pawn-customers.php" class="btn btn-light me-2">Cancel</a><button class="btn btn-theme"><i class="fa-solid fa-floppy-disk me-2"></i>Save Customer</button></div>
</form>
<script>
document.addEventListener('DOMContentLoaded',()=>{
 const select=document.getElementById('customer_id');
 if(!select)return;
 const toggle=()=>document.querySelectorAll('.new-customer').forEach(el=>el.style.display=select.value==='0'?'block':'none');
 select.addEventListener('change',toggle);toggle();
});
</script>
<?php include('includes/footer.php'); ?>
</div>
</main>
<?php include('includes/script.php'); ?>
<script src="assets/js/script.js"></script>
</body>
</html>
