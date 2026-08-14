<?php
require dirname(__DIR__) . '/_common.php';

function psTableExists($conn, $table)
{
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
    $r = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
    return $r && $r->num_rows > 0;
}

function psJson($ok, $message, $data = array(), $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(array('success' => (bool)$ok, 'message' => (string)$message), $data));
    exit;
}

function psAudit($conn, $businessId, $branchId, $userId, $action, $table, $id, $description, $old = null, $new = null)
{
    if (!psTableExists($conn, 'audit_logs')) return;
    $module = 'pawn.settings';
    $oldJson = $old === null ? null : json_encode($old);
    $newJson = $new === null ? null : json_encode($new);
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 500) : '';
    $s = $conn->prepare("INSERT INTO audit_logs (business_id,branch_id,user_id,module_code,action_type,reference_table,reference_id,description,old_values_json,new_values_json,ip_address,user_agent,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())");
    if (!$s) return;
    $s->bind_param('iiisssisssss', $businessId, $branchId, $userId, $module, $action, $table, $id, $description, $oldJson, $newJson, $ip, $ua);
    $s->execute();
    $s->close();
}

function psRequireCsrf($csrfToken)
{
    $posted = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
    if ($posted === '' || !hash_equals((string)$csrfToken, $posted)) {
        psJson(false, 'Invalid CSRF token. Refresh the page and try again.', array(), 403);
    }
}

function psSettingSave($conn, $businessId, $key, $value, $type)
{
    $s = $conn->prepare("INSERT INTO business_settings (business_id,setting_key,setting_value,value_type,is_public) VALUES (?,?,?,?,0) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),value_type=VALUES(value_type),is_public=0");
    if (!$s) throw new RuntimeException($conn->error);
    $s->bind_param('isss', $businessId, $key, $value, $type);
    if (!$s->execute()) throw new RuntimeException($s->error);
    $s->close();
}

$hasSchemes = psTableExists($conn, 'pawn_interest_schemes');
$hasSteps = psTableExists($conn, 'pawn_interest_rate_steps');
$hasBanks = psTableExists($conn, 'pawn_banks');
$hasBusinessSettings = psTableExists($conn, 'business_settings');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    psJson(false, 'Method not allowed.', array(), 405);
}

if (!isset($_POST['action'])) {
    psJson(false, 'Action is required.', array(), 400);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    psRequireCsrf($csrfToken);
    $action = trim((string)$_POST['action']);

    try {
        if ($action === 'save_category') {
            $id = max(0, (int)($_POST['id'] ?? 0));
            $code = strtoupper(trim((string)($_POST['category_code'] ?? '')));
            $name = trim((string)($_POST['category_name'] ?? ''));
            $categoryType = trim((string)($_POST['category_type'] ?? 'Ornament'));
            $metalType = trim((string)($_POST['metal_type'] ?? ''));
            $purity = trim((string)($_POST['purity_standard'] ?? ''));
            $minPurity = trim((string)($_POST['min_purity_percent'] ?? ''));
            $maxPurity = trim((string)($_POST['max_purity_percent'] ?? ''));
            $maxLoan = max(0, min(100, (float)($_POST['max_loan_percent'] ?? 70)));
            $storageFee = max(0, (float)($_POST['storage_fee_percent'] ?? 0));
            $valuation = trim((string)($_POST['valuation_method'] ?? 'Weight'));
            $requiresCertificate = !empty($_POST['requires_certificate']) ? 1 : 0;
            $requiresValuation = !empty($_POST['requires_valuation']) ? 1 : 0;
            $description = trim((string)($_POST['description'] ?? ''));
            $active = !empty($_POST['is_active']) ? 1 : 0;

            if ($code === '' || $name === '') throw new RuntimeException('Category code and category name are required.');
            if (!in_array($categoryType, array('Ornament','Metal','Document','Other'), true)) $categoryType = 'Ornament';
            if ($metalType !== '' && !in_array($metalType, array('Gold','Silver','Platinum','Other'), true)) $metalType = '';
            if (!in_array($valuation, array('Weight','Piece','Stone','Combined'), true)) $valuation = 'Weight';
            $minPurityVal = $minPurity === '' ? null : (float)$minPurity;
            $maxPurityVal = $maxPurity === '' ? null : (float)$maxPurity;

            if ($id > 0) {
                $old = null;
                $q = $conn->prepare('SELECT * FROM pawn_categories WHERE id=? AND business_id=? LIMIT 1');
                $q->bind_param('ii', $id, $businessId); $q->execute(); $old = $q->get_result()->fetch_assoc(); $q->close();
                if (!$old) throw new RuntimeException('Category not found.');
                $s = $conn->prepare('UPDATE pawn_categories SET category_code=?,category_name=?,category_type=?,metal_type=NULLIF(?,\'\'),purity_standard=?,min_purity_percent=?,max_purity_percent=?,max_loan_percent=?,storage_fee_percent=?,valuation_method=?,requires_certificate=?,requires_valuation=?,description=?,is_active=? WHERE id=? AND business_id=?');
                $s->bind_param('sssssddddsiisiii', $code,$name,$categoryType,$metalType,$purity,$minPurityVal,$maxPurityVal,$maxLoan,$storageFee,$valuation,$requiresCertificate,$requiresValuation,$description,$active,$id,$businessId);
                if (!$s->execute()) throw new RuntimeException($s->error); $s->close();
                psAudit($conn,$businessId,$branchId,$userId,'Update','pawn_categories',$id,'Updated pawn category '.$code,$old,array('category_code'=>$code,'category_name'=>$name));
            } else {
                $s = $conn->prepare('INSERT INTO pawn_categories (business_id,category_code,category_name,category_type,metal_type,purity_standard,min_purity_percent,max_purity_percent,default_interest_percent,max_loan_percent,storage_fee_percent,valuation_method,requires_certificate,requires_valuation,description,is_active,created_by) VALUES (?,?,?,?,NULLIF(?,\'\'),?,?,?,0,?,?,?,?,?,?,?,?)');
                $s->bind_param('isssssdddsiisii', $businessId,$code,$name,$categoryType,$metalType,$purity,$minPurityVal,$maxPurityVal,$maxLoan,$storageFee,$valuation,$requiresCertificate,$requiresValuation,$description,$active,$userId);
                if (!$s->execute()) throw new RuntimeException($s->error); $id=(int)$s->insert_id; $s->close();
                psAudit($conn,$businessId,$branchId,$userId,'Create','pawn_categories',$id,'Created pawn category '.$code,null,array('category_code'=>$code,'category_name'=>$name));
            }
            psJson(true, 'Pawn category saved successfully.');
        }

        if ($action === 'save_scheme') {
            if (!$hasSchemes) throw new RuntimeException('Pawn V2 interest tables are not installed. Run the migration first.');
            $id=max(0,(int)($_POST['id']??0));
            $code=strtoupper(trim((string)($_POST['scheme_code']??'')));
            $name=trim((string)($_POST['scheme_name']??''));
            $tenureType=trim((string)($_POST['tenure_type']??'Fixed Months'));
            $tenureMonths=$tenureType==='At Closure'?null:max(1,(int)($_POST['tenure_months']??12));
            $method=trim((string)($_POST['interest_method']??'Simple'));
            $rounding=trim((string)($_POST['interest_rounding_method']??'Nearest Rupee'));
            $locked=!empty($_POST['permanent_escalation_until_closure'])?1:0;
            $description=trim((string)($_POST['description']??''));
            $active=!empty($_POST['is_active'])?1:0;
            if($code===''||$name==='') throw new RuntimeException('Scheme code and scheme name are required.');
            if(!in_array($tenureType,array('Fixed Months','At Closure'),true)) $tenureType='Fixed Months';
            if(!in_array($method,array('Simple','Reducing Balance','Flat'),true)) $method='Simple';
            if(!in_array($rounding,array('None','Nearest Rupee','Ceil Rupee','Floor Rupee'),true)) $rounding='Nearest Rupee';
            if($id>0){
                $old=null;$q=$conn->prepare('SELECT * FROM pawn_interest_schemes WHERE id=? AND business_id=? LIMIT 1');$q->bind_param('ii',$id,$businessId);$q->execute();$old=$q->get_result()->fetch_assoc();$q->close();if(!$old)throw new RuntimeException('Scheme not found.');
                $s=$conn->prepare('UPDATE pawn_interest_schemes SET scheme_code=?,scheme_name=?,tenure_type=?,tenure_months=?,interest_method=?,interest_rounding_method=?,permanent_escalation_until_closure=?,description=?,is_active=? WHERE id=? AND business_id=?');
                $s->bind_param('sssissisiii',$code,$name,$tenureType,$tenureMonths,$method,$rounding,$locked,$description,$active,$id,$businessId);
                if(!$s->execute())throw new RuntimeException($s->error);$s->close();
                psAudit($conn,$businessId,$branchId,$userId,'Update','pawn_interest_schemes',$id,'Updated pawn interest scheme '.$code,$old,array('scheme_code'=>$code,'scheme_name'=>$name));
            }else{
                $s=$conn->prepare('INSERT INTO pawn_interest_schemes (business_id,scheme_code,scheme_name,tenure_type,tenure_months,interest_method,interest_rounding_method,permanent_escalation_until_closure,description,is_active,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
                $s->bind_param('isssissisii',$businessId,$code,$name,$tenureType,$tenureMonths,$method,$rounding,$locked,$description,$active,$userId);
                if(!$s->execute())throw new RuntimeException($s->error);$id=(int)$s->insert_id;$s->close();
                psAudit($conn,$businessId,$branchId,$userId,'Create','pawn_interest_schemes',$id,'Created pawn interest scheme '.$code,null,array('scheme_code'=>$code,'scheme_name'=>$name));
            }
            psJson(true,'Interest scheme saved successfully.');
        }

        if ($action === 'save_step') {
            if (!$hasSteps) throw new RuntimeException('Pawn V2 rate-step table is not installed.');
            $id=max(0,(int)($_POST['id']??0));
            $schemeId=max(1,(int)($_POST['scheme_id']??0));
            $level=max(1,(int)($_POST['level_no']??1));
            $rate=max(0,(float)($_POST['rate_percent']??0));
            $cycleType=trim((string)($_POST['interest_cycle_type']??'Calendar Month'));
            $cycleValue=max(1,(int)($_POST['interest_cycle_value']??1));
            $grace=max(0,(int)($_POST['grace_days']??0));
            $miss=max(1,(int)($_POST['missed_cycles_to_escalate']??1));
            $nextRaw=trim((string)($_POST['next_level_no']??''));
            $next=$nextRaw===''?null:max(1,(int)$nextRaw);
            $effective=trim((string)($_POST['escalation_effective']??'Next Cycle'));
            $active=!empty($_POST['is_active'])?1:0;
            if(!in_array($cycleType,array('Calendar Month','Days','Months'),true))$cycleType='Calendar Month';
            if(!in_array($effective,array('Immediately','Next Cycle'),true))$effective='Next Cycle';
            $q=$conn->prepare('SELECT id FROM pawn_interest_schemes WHERE id=? AND business_id=? LIMIT 1');$q->bind_param('ii',$schemeId,$businessId);$q->execute();$ok=$q->get_result()->fetch_assoc();$q->close();if(!$ok)throw new RuntimeException('Invalid interest scheme.');
            if($id>0){
                $old=null;$q=$conn->prepare('SELECT * FROM pawn_interest_rate_steps WHERE id=? AND business_id=? LIMIT 1');$q->bind_param('ii',$id,$businessId);$q->execute();$old=$q->get_result()->fetch_assoc();$q->close();if(!$old)throw new RuntimeException('Rate step not found.');
                $s=$conn->prepare('UPDATE pawn_interest_rate_steps SET scheme_id=?,level_no=?,rate_percent=?,interest_cycle_type=?,interest_cycle_value=?,grace_days=?,missed_cycles_to_escalate=?,next_level_no=?,escalation_effective=?,is_active=? WHERE id=? AND business_id=?');
                $s->bind_param('iidsiiiisiii',$schemeId,$level,$rate,$cycleType,$cycleValue,$grace,$miss,$next,$effective,$active,$id,$businessId);
                if(!$s->execute())throw new RuntimeException($s->error);$s->close();
                psAudit($conn,$businessId,$branchId,$userId,'Update','pawn_interest_rate_steps',$id,'Updated pawn interest rate level '.$level,$old,array('rate_percent'=>$rate,'level_no'=>$level));
            }else{
                $s=$conn->prepare('INSERT INTO pawn_interest_rate_steps (business_id,scheme_id,level_no,rate_percent,interest_cycle_type,interest_cycle_value,grace_days,missed_cycles_to_escalate,next_level_no,escalation_effective,is_active,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
                $s->bind_param('iiidsiiiisii',$businessId,$schemeId,$level,$rate,$cycleType,$cycleValue,$grace,$miss,$next,$effective,$active,$userId);
                if(!$s->execute())throw new RuntimeException($s->error);$id=(int)$s->insert_id;$s->close();
                psAudit($conn,$businessId,$branchId,$userId,'Create','pawn_interest_rate_steps',$id,'Created pawn interest rate level '.$level,null,array('rate_percent'=>$rate,'level_no'=>$level));
            }
            psJson(true,'Interest rate level saved successfully.');
        }

        if ($action === 'save_bank') {
            if(!$hasBanks) throw new RuntimeException('Pawn bank table is not installed. Run the Pawn V2 migration first.');
            $id=max(0,(int)($_POST['id']??0));
            $code=strtoupper(trim((string)($_POST['bank_code']??'')));
            $name=trim((string)($_POST['bank_name']??''));
            $branch=trim((string)($_POST['branch_name']??''));
            $address=trim((string)($_POST['branch_address']??''));
            $contact=trim((string)($_POST['contact_person']??''));
            $mobile=trim((string)($_POST['mobile']??''));
            $account=trim((string)($_POST['account_number_masked']??''));
            $notes=trim((string)($_POST['notes']??''));
            $active=!empty($_POST['is_active'])?1:0;
            if($code===''||$name==='') throw new RuntimeException('Bank code and bank name are required.');
            if($id>0){
                $old=null;$q=$conn->prepare('SELECT * FROM pawn_banks WHERE id=? AND business_id=? LIMIT 1');$q->bind_param('ii',$id,$businessId);$q->execute();$old=$q->get_result()->fetch_assoc();$q->close();if(!$old)throw new RuntimeException('Bank not found.');
                $s=$conn->prepare('UPDATE pawn_banks SET bank_code=?,bank_name=?,branch_name=?,branch_address=?,contact_person=?,mobile=?,account_number_masked=?,notes=?,is_active=? WHERE id=? AND business_id=?');
                $s->bind_param('ssssssssiii',$code,$name,$branch,$address,$contact,$mobile,$account,$notes,$active,$id,$businessId);
                if(!$s->execute())throw new RuntimeException($s->error);$s->close();
                psAudit($conn,$businessId,$branchId,$userId,'Update','pawn_banks',$id,'Updated pawn bank '.$code,$old,array('bank_code'=>$code,'bank_name'=>$name));
            }else{
                $s=$conn->prepare('INSERT INTO pawn_banks (business_id,bank_code,bank_name,branch_name,branch_address,contact_person,mobile,account_number_masked,notes,is_active,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
                $s->bind_param('issssssssii',$businessId,$code,$name,$branch,$address,$contact,$mobile,$account,$notes,$active,$userId);
                if(!$s->execute())throw new RuntimeException($s->error);$id=(int)$s->insert_id;$s->close();
                psAudit($conn,$businessId,$branchId,$userId,'Create','pawn_banks',$id,'Created pawn bank '.$code,null,array('bank_code'=>$code,'bank_name'=>$name));
            }
            psJson(true,'Bank saved successfully.');
        }

        if ($action === 'toggle_active') {
            $target=trim((string)($_POST['target']??''));
            $id=max(1,(int)($_POST['id']??0));
            $active=!empty($_POST['active'])?1:0;
            $allowed=array('pawn_categories','pawn_interest_schemes','pawn_interest_rate_steps','pawn_banks');
            if(!in_array($target,$allowed,true) || !psTableExists($conn,$target)) throw new RuntimeException('Invalid setting record.');
            $s=$conn->prepare("UPDATE `{$target}` SET is_active=? WHERE id=? AND business_id=?");
            $s->bind_param('iii',$active,$id,$businessId);if(!$s->execute())throw new RuntimeException($s->error);$s->close();
            psAudit($conn,$businessId,$branchId,$userId,'Update',$target,$id,($active?'Activated ':'Deactivated ').$target,null,array('is_active'=>$active));
            psJson(true,$active?'Activated successfully.':'Deactivated successfully.');
        }

        if ($action === 'save_general') {
            if(!$hasBusinessSettings) throw new RuntimeException('business_settings table is not available.');
            $settings=array(
                'pawn_auto_escalate_interest'=>array(!empty($_POST['pawn_auto_escalate_interest'])?'1':'0','boolean'),
                'pawn_block_release_if_bank_pledged'=>array(!empty($_POST['pawn_block_release_if_bank_pledged'])?'1':'0','boolean'),
                'pawn_allow_partial_principal'=>array(!empty($_POST['pawn_allow_partial_principal'])?'1':'0','boolean'),
                'pawn_require_id_proof'=>array(!empty($_POST['pawn_require_id_proof'])?'1':'0','boolean'),
                'pawn_require_item_photo'=>array(!empty($_POST['pawn_require_item_photo'])?'1':'0','boolean'),
                'pawn_interest_due_reminder_days'=>array((string)max(0,(int)($_POST['pawn_interest_due_reminder_days']??3)),'number'),
                'pawn_bank_interest_reminder_days'=>array((string)max(0,(int)($_POST['pawn_bank_interest_reminder_days']??7)),'number'),
                'pawn_default_document_charge'=>array(number_format(max(0,(float)($_POST['pawn_default_document_charge']??0)),2,'.',''),'number'),
                'pawn_default_other_charge'=>array(number_format(max(0,(float)($_POST['pawn_default_other_charge']??0)),2,'.',''),'number')
            );
            foreach($settings as $k=>$v) psSettingSave($conn,$businessId,$k,$v[0],$v[1]);
            psAudit($conn,$businessId,$branchId,$userId,'Update','business_settings',0,'Updated Pawn Broking general settings',null,array_keys($settings));
            psJson(true,'General pawn settings saved successfully.');
        }

        psJson(false,'Unknown action.',array(),400);
    } catch (Throwable $e) {
        psJson(false,$e->getMessage(),array(),500);
    }
}