<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_OFF);
ini_set('display_errors', '0');

function respond($ok, $message = '', $extra = array(), $code = 200)
{
    http_response_code($code);
    echo json_encode(array_merge(array('success' => (bool)$ok, 'message' => (string)$message), $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
        respond(false, 'Fatal API error: ' . $e['message'], array(), 500);
    }
});

foreach (array(dirname(__DIR__) . '/config/config.php', dirname(__DIR__) . '/config.php', dirname(__DIR__) . '/includes/config.php', dirname(__DIR__) . '/super-admin/includes/config.php') as $f) {
    if (is_file($f)) { require_once $f; break; }
}
if (!isset($conn) || !($conn instanceof mysqli)) respond(false, 'Database configuration is not available.', array(), 500);
$conn->set_charset('utf8mb4');
if (empty($_SESSION['user_id'])) respond(false, 'Session expired.', array(), 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(false, 'Invalid request method.', array(), 405);
if (!hash_equals((string)($_SESSION['pawn_csrf'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) respond(false, 'Invalid request token. Refresh the page.', array(), 419);

$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? ($_SESSION['default_branch_id'] ?? 0));
$userId = (int)($_SESSION['user_id'] ?? 0);
$action = trim((string)($_POST['action'] ?? ''));
if ($businessId <= 0 || $branchId <= 0) respond(false, 'Select a valid business and branch.', array(), 403);

function tableExists($c, $t)
{
    $t = $c->real_escape_string($t);
    $r = $c->query("SHOW TABLES LIKE '{$t}'");
    return $r && $r->num_rows > 0;
}
function hasColumn($c, $t, $col)
{
    $t = $c->real_escape_string($t); $col = $c->real_escape_string($col);
    $r = $c->query("SHOW COLUMNS FROM `{$t}` LIKE '{$col}'");
    return $r && $r->num_rows > 0;
}
function bindDynamic($stmt, $types, &$values)
{
    if (strlen($types) !== count($values)) throw new RuntimeException('Prepared statement bind mismatch.');
    $args = array($types);
    foreach ($values as $k => $v) $args[] =& $values[$k];
    call_user_func_array(array($stmt, 'bind_param'), $args);
}
function dateValid($date)
{
    if ($date === '') return false;
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}
function moneyRound($value, $mode)
{
    if ($mode === 'Nearest Rupee') return round($value);
    if ($mode === 'Ceil Rupee') return ceil($value);
    if ($mode === 'Floor Rupee') return floor($value);
    return round($value, 2);
}
function monthsBetween($from, $to)
{
    $a = new DateTime($from); $b = new DateTime($to);
    $days = max(0, (int)$a->diff($b)->format('%a'));
    return $days / 30.0;
}
function calculateInterest($pawn, $fromDate, $toDate)
{
    $principal = max(0, (float)$pawn['balance_principal']);
    $percent = max(0, (float)$pawn['interest_percent']);
    $period = (string)$pawn['interest_period'];
    $method = (string)($pawn['interest_method'] ?? 'Simple');
    $minDays = max(0, (int)($pawn['minimum_interest_days'] ?? 0));
    $a = new DateTime($fromDate); $b = new DateTime($toDate);
    $days = max(0, (int)$a->diff($b)->format('%a'));
    if ($days < $minDays) $days = $minDays;
    $months = $days / 30.0;
    if ($period === 'Daily') $interest = $principal * ($percent / 100) * $days;
    elseif ($period === 'Yearly') $interest = $principal * ($percent / 100) * ($days / 365.0);
    else $interest = $principal * ($percent / 100) * $months;
    if ($method === 'Flat') {
        $base = max(0, (float)$pawn['principal_amount']);
        if ($period === 'Daily') $interest = $base * ($percent / 100) * $days;
        elseif ($period === 'Yearly') $interest = $base * ($percent / 100) * ($days / 365.0);
        else $interest = $base * ($percent / 100) * $months;
    }
    $interest = moneyRound($interest, (string)($pawn['interest_rounding_method'] ?? 'Nearest Rupee'));
    return array('days' => $days, 'months' => round($months, 4), 'interest' => max(0, $interest));
}
function calculatePenalty($pawn, $asOf)
{
    $dueDate = (string)($pawn['due_date'] ?? '');
    $type = (string)($pawn['overdue_charge_type'] ?? 'None');
    $value = max(0, (float)($pawn['overdue_charge_value'] ?? 0));
    if ($dueDate === '' || $type === 'None' || $value <= 0) return array('days' => 0, 'amount' => 0.0);
    $start = new DateTime($dueDate);
    $start->modify('+' . max(0, (int)($pawn['grace_days'] ?? 0)) . ' days');
    $end = new DateTime($asOf);
    if ($end <= $start) return array('days' => 0, 'amount' => 0.0);
    $days = (int)$start->diff($end)->format('%a');
    if ($type === 'Fixed') $amount = $value;
    elseif ($type === 'Daily Fixed') $amount = $value * $days;
    elseif ($type === 'Monthly Fixed') $amount = $value * (int)ceil($days / 30);
    else $amount = max(0, (float)$pawn['balance_principal']) * ($value / 100);
    $max = $pawn['maximum_overdue_charge'];
    if ($max !== null && $max !== '' && (float)$max > 0) $amount = min($amount, (float)$max);
    return array('days' => $days, 'amount' => round($amount, 2));
}
function getPawn($c, $businessId, $branchId, $pawnId)
{
    $sql = "SELECT pe.*, c.customer_name, c.customer_code, c.mobile,
                   pc.category_name
            FROM pawn_entries pe
            INNER JOIN customers c ON c.id=pe.customer_id AND c.business_id=pe.business_id
            LEFT JOIN pawn_categories pc ON pc.id=pe.pawn_category_id
            WHERE pe.id=? AND pe.business_id=? AND pe.branch_id=? LIMIT 1";
    $s = $c->prepare($sql);
    if (!$s) throw new RuntimeException('Unable to load pawn entry: ' . $c->error);
    $s->bind_param('iii', $pawnId, $businessId, $branchId); $s->execute();
    $row = $s->get_result()->fetch_assoc(); $s->close();
    if (!$row) throw new RuntimeException('Pawn entry was not found.');
    return $row;
}
function financialYearParts($date)
{
    $ts = strtotime($date); $year = (int)date('Y', $ts); $month = (int)date('n', $ts);
    $start = $month >= 4 ? $year : $year - 1;
    return array($start, $start + 1);
}
function documentNumber($c, $businessId, $branchId, $key, $date, $consume)
{
    $s = $c->prepare("SELECT * FROM document_number_settings WHERE business_id=? AND document_key=? AND is_active=1 AND (branch_id=? OR branch_id IS NULL) ORDER BY (branch_id=?) DESC,id DESC LIMIT 1");
    if (!$s) throw new RuntimeException('Unable to read document number settings.');
    $s->bind_param('isii', $businessId, $key, $branchId, $branchId); $s->execute();
    $set = $s->get_result()->fetch_assoc(); $s->close();
    if (!$set) throw new RuntimeException('Configure ' . $key . ' in Document Number Settings.');
    $ts = strtotime($date); list($fy1, $fy2) = financialYearParts($date);
    $reset = (string)$set['reset_frequency'];
    if ($reset === 'Monthly') $period = date('Ym', $ts);
    elseif ($reset === 'Daily') $period = date('Ymd', $ts);
    elseif ($reset === 'Calendar Year') $period = date('Y', $ts);
    elseif ($reset === 'Never') $period = 'ALL';
    else $period = $fy1 . '-' . $fy2;
    $current = 0;
    $q = $c->prepare('SELECT current_number FROM number_sequences WHERE business_id=? AND branch_id=? AND document_type=? AND period_key=? LIMIT 1' . ($consume ? ' FOR UPDATE' : ''));
    if ($q) { $q->bind_param('iiss', $businessId, $branchId, $key, $period); $q->execute(); $r=$q->get_result()->fetch_assoc(); $q->close(); if ($r) $current=(int)$r['current_number']; }
    $next = max((int)$set['sequence_start'], $current + 1);
    if ($consume) {
        $q = $c->prepare('INSERT INTO number_sequences (business_id,branch_id,document_type,period_key,current_number) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE current_number=VALUES(current_number)');
        if (!$q) throw new RuntimeException('Unable to update document sequence.');
        $q->bind_param('iissi', $businessId, $branchId, $key, $period, $next); $q->execute(); $q->close();
    }
    $seq = str_pad((string)$next, max(1,(int)$set['sequence_digits']), '0', STR_PAD_LEFT);
    $center = strtr((string)$set['center_format'], array('{YYYY}'=>date('Y',$ts),'{YY}'=>date('y',$ts),'{MM}'=>date('m',$ts),'{DD}'=>date('d',$ts),'{FY_SHORT}'=>substr((string)$fy1,2).'-'.substr((string)$fy2,2),'{FY}'=>$fy1.'-'.$fy2));
    return strtr((string)$set['format_template'], array('{PREFIX}'=>(string)$set['prefix'],'{DIVIDER}'=>(string)$set['divider'],'{CENTER}'=>$center,'{FY_SHORT}'=>substr((string)$fy1,2).'-'.substr((string)$fy2,2),'{SEQ}'=>$seq,'{SUFFIX}'=>(string)$set['suffix']));
}
function actionLog($c, $businessId, $branchId, $pawnId, $actionType, $table, $referenceId, $description, $userId)
{
    if (!tableExists($c, 'pawn_action_history')) return;
    $s=$c->prepare('INSERT INTO pawn_action_history (business_id,branch_id,pawn_entry_id,action_type,reference_table,reference_id,description,action_by) VALUES (?,?,?,?,?,?,?,?)');
    if (!$s) return;
    $s->bind_param('iiissisi',$businessId,$branchId,$pawnId,$actionType,$table,$referenceId,$description,$userId); $s->execute(); $s->close();
}

if ($action === 'category_list') {
    $search = trim((string)($_POST['search'] ?? ''));
    $where = 'business_id=?'; $types='i'; $params=array($businessId);
    if ($search !== '') { $where .= ' AND (category_code LIKE ? OR category_name LIKE ? OR metal_type LIKE ?)'; $like='%'.$search.'%'; $types.='sss'; $params[]=$like; $params[]=$like; $params[]=$like; }
    $s=$conn->prepare("SELECT * FROM pawn_categories WHERE {$where} ORDER BY is_active DESC, category_name ASC");
    bindDynamic($s,$types,$params); $s->execute(); $r=$s->get_result(); $rows=array(); while($x=$r->fetch_assoc())$rows[]=$x; $s->close();
    respond(true,'',array('categories'=>$rows));
}
if ($action === 'category_save') {
    $id=(int)($_POST['id']??0); $code=strtoupper(trim((string)($_POST['category_code']??''))); $name=trim((string)($_POST['category_name']??''));
    $categoryType=(string)($_POST['category_type']??'Ornament'); $metal=(string)($_POST['metal_type']??''); $purity=trim((string)($_POST['purity_standard']??''));
    $minPurity=trim((string)($_POST['min_purity_percent']??'')); $maxPurity=trim((string)($_POST['max_purity_percent']??''));
    $interest=max(0,(float)($_POST['default_interest_percent']??0)); $loan=max(0,min(100,(float)($_POST['max_loan_percent']??70)));
    $storage=max(0,(float)($_POST['storage_fee_percent']??0)); $valuation=(string)($_POST['valuation_method']??'Weight');
    $certificate=!empty($_POST['requires_certificate'])?1:0; $requiresValuation=!empty($_POST['requires_valuation'])?1:0; $description=trim((string)($_POST['description']??'')); $active=!empty($_POST['is_active'])?1:0;
    if($code===''||$name==='')respond(false,'Category code and name are required.',array(),422);
    if(!in_array($categoryType,array('Ornament','Metal','Document','Other'),true))respond(false,'Invalid category type.',array(),422);
    if($metal!==''&&!in_array($metal,array('Gold','Silver','Platinum','Other'),true))respond(false,'Invalid metal type.',array(),422);
    if(!in_array($valuation,array('Weight','Piece','Stone','Combined'),true))respond(false,'Invalid valuation method.',array(),422);
    $minVal=$minPurity===''?null:(float)$minPurity; $maxVal=$maxPurity===''?null:(float)$maxPurity;
    if($minVal!==null&&$maxVal!==null&&$minVal>$maxVal)respond(false,'Minimum purity cannot exceed maximum purity.',array(),422);
    if($id>0){
        $s=$conn->prepare('UPDATE pawn_categories SET category_code=?,category_name=?,category_type=?,metal_type=?,purity_standard=?,min_purity_percent=?,max_purity_percent=?,default_interest_percent=?,max_loan_percent=?,storage_fee_percent=?,valuation_method=?,requires_certificate=?,requires_valuation=?,description=?,is_active=? WHERE id=? AND business_id=?');
        $s->bind_param('sssssdddddsiiisii',$code,$name,$categoryType,$metal,$purity,$minVal,$maxVal,$interest,$loan,$storage,$valuation,$certificate,$requiresValuation,$description,$active,$id,$businessId);
        if(!$s->execute())respond(false,$s->errno===1062?'Category code or name already exists.':'Unable to update category: '.$s->error,array(),422); $s->close();
        respond(true,'Pawn category updated successfully.');
    }
    $s=$conn->prepare('INSERT INTO pawn_categories (business_id,category_code,category_name,category_type,metal_type,purity_standard,min_purity_percent,max_purity_percent,default_interest_percent,max_loan_percent,storage_fee_percent,valuation_method,requires_certificate,requires_valuation,description,is_active,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $s->bind_param('isssssdddddsiiisii',$businessId,$code,$name,$categoryType,$metal,$purity,$minVal,$maxVal,$interest,$loan,$storage,$valuation,$certificate,$requiresValuation,$description,$active,$userId);
    if(!$s->execute())respond(false,$s->errno===1062?'Category code or name already exists.':'Unable to create category: '.$s->error,array(),422); $s->close();
    respond(true,'Pawn category created successfully.');
}
if ($action === 'category_toggle') {
    $id=(int)($_POST['id']??0); $s=$conn->prepare('UPDATE pawn_categories SET is_active=IF(is_active=1,0,1) WHERE id=? AND business_id=?'); $s->bind_param('ii',$id,$businessId); $s->execute(); $s->close(); respond(true,'Category status updated.');
}

if ($action === 'pawn_options') {
    $statusFilter = trim((string)($_POST['status'] ?? 'open'));
    $where = "pe.business_id=? AND pe.branch_id=?";
    if ($statusFilter === 'open') $where .= " AND pe.status IN ('Active','Partially Paid')";
    $sql="SELECT pe.id,pe.pawn_no,pe.pawn_date,pe.due_date,pe.customer_id,pe.principal_amount,pe.balance_principal,pe.interest_percent,pe.interest_period,pe.interest_method,pe.interest_collection_cycle,pe.interest_cycle_months,pe.last_interest_paid_upto,pe.next_interest_due_date,pe.minimum_interest_days,pe.interest_rounding_method,pe.grace_days,pe.overdue_charge_type,pe.overdue_charge_value,pe.maximum_overdue_charge,pe.status,c.customer_name,c.customer_code,c.mobile,pc.category_name FROM pawn_entries pe INNER JOIN customers c ON c.id=pe.customer_id LEFT JOIN pawn_categories pc ON pc.id=pe.pawn_category_id WHERE {$where} ORDER BY pe.id DESC";
    $s=$conn->prepare($sql); $s->bind_param('ii',$businessId,$branchId); $s->execute(); $r=$s->get_result(); $pawns=array(); while($x=$r->fetch_assoc())$pawns[]=$x; $s->close();
    $methods=array(); $s=$conn->prepare('SELECT id,method_name,method_type FROM payment_methods WHERE business_id=? AND is_active=1 ORDER BY method_name'); $s->bind_param('i',$businessId);$s->execute();$r=$s->get_result();while($x=$r->fetch_assoc())$methods[]=$x;$s->close();
    respond(true,'',array('pawns'=>$pawns,'payment_methods'=>$methods));
}
if ($action === 'interest_quote' || $action === 'payment_quote') {
    $pawnId=(int)($_POST['pawn_id']??0); $asOf=trim((string)($_POST['as_of_date']??date('Y-m-d'))); if(!dateValid($asOf))respond(false,'Enter a valid calculation date.',array(),422);
    try{$pawn=getPawn($conn,$businessId,$branchId,$pawnId);}catch(Throwable $e){respond(false,$e->getMessage(),array(),404);}
    $from=(string)($pawn['last_interest_paid_upto'] ?: $pawn['pawn_date']);
    if($asOf<$from)respond(false,'Calculation date cannot be before the last paid date.',array(),422);
    $calc=calculateInterest($pawn,$from,$asOf); $pen=calculatePenalty($pawn,$asOf);
    $total=$calc['interest']+$pen['amount'];
    respond(true,'',array('pawn'=>$pawn,'from_date'=>$from,'to_date'=>$asOf,'calculation_days'=>$calc['days'],'calculation_months'=>$calc['months'],'interest_amount'=>$calc['interest'],'penalty_amount'=>$pen['amount'],'overdue_days'=>$pen['days'],'total_interest_due'=>$total,'total_closure_due'=>$total+(float)$pawn['balance_principal']));
}
if ($action === 'interest_collect') {
    $pawnId=(int)($_POST['pawn_id']??0); $date=trim((string)($_POST['collection_date']??date('Y-m-d'))); $from=trim((string)($_POST['from_date']??'')); $to=trim((string)($_POST['to_date']??''));
    $interest=max(0,(float)($_POST['interest_amount']??0)); $penalty=max(0,(float)($_POST['penalty_amount']??0)); $other=max(0,(float)($_POST['other_charges']??0)); $paid=max(0,(float)($_POST['paid_amount']??0)); $method=(int)($_POST['payment_method_id']??0); $ref=trim((string)($_POST['reference_no']??'')); $remarks=trim((string)($_POST['remarks']??''));
    if(!dateValid($date)||!dateValid($from)||!dateValid($to))respond(false,'Collection and interest period dates are required.',array(),422); if($paid<=0||$method<=0)respond(false,'Enter paid amount and payment method.',array(),422);
    try{$pawn=getPawn($conn,$businessId,$branchId,$pawnId);}catch(Throwable $e){respond(false,$e->getMessage(),array(),404);} if(!in_array($pawn['status'],array('Active','Partially Paid'),true))respond(false,'This pawn is not open for collection.',array(),422);
    $total=$interest+$penalty+$other; if($paid>$total+0.01)respond(false,'Paid amount cannot exceed the calculated total.',array(),422);
    $conn->begin_transaction();
    try{
        $receipt=documentNumber($conn,$businessId,$branchId,'pawn_interest_receipt',$date,true);
        $calcDays=(int)($_POST['calculation_days']??0); $calcMonths=(float)($_POST['calculation_months']??0); $principal=(float)$pawn['balance_principal']; $percent=(float)$pawn['interest_percent'];
        $collectionType=(string)$pawn['interest_collection_cycle']; if(!in_array($collectionType,array('Monthly','Quarterly','Half-Yearly','Yearly','Custom','Closure'),true))$collectionType='Custom';
        $s=$conn->prepare('INSERT INTO pawn_interest_collections (business_id,branch_id,pawn_entry_id,receipt_no,collection_date,from_date,to_date,principal_balance,calculation_days,calculation_months,interest_percent,interest_amount,penalty_amount,other_charges,total_amount,collection_type,payment_method_id,created_by,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
        $s->bind_param('iiissssdiddddddsii',$businessId,$branchId,$pawnId,$receipt,$date,$from,$to,$principal,$calcDays,$calcMonths,$percent,$interest,$penalty,$other,$paid,$collectionType,$method,$userId);
        if(!$s->execute())throw new RuntimeException('Unable to save interest collection: '.$s->error); $collectionId=(int)$s->insert_id;$s->close();
        if(tableExists($conn,'pawn_interest_payment_splits')){$s=$conn->prepare('INSERT INTO pawn_interest_payment_splits (interest_collection_id,payment_method_id,amount,reference_no) VALUES (?,?,?,?)');$s->bind_param('iids',$collectionId,$method,$paid,$ref);$s->execute();$s->close();}
        $next=''; if($pawn['interest_collection_cycle']!=='At Closure'){ $d=new DateTime($to); $d->modify('+'.max(1,(int)$pawn['interest_cycle_months']).' months'); $next=$d->format('Y-m-d'); }
        $s=$conn->prepare('UPDATE pawn_entries SET total_interest_collected=total_interest_collected+?,total_penalty_collected=total_penalty_collected+?,total_other_charges_collected=total_other_charges_collected+?,last_interest_paid_upto=?,next_interest_due_date=? WHERE id=? AND business_id=?');
        $s->bind_param('dddssii',$interest,$penalty,$other,$to,$next,$pawnId,$businessId);$s->execute();$s->close();
        actionLog($conn,$businessId,$branchId,$pawnId,'Interest Collected','pawn_interest_collections',$collectionId,'Collected interest receipt '.$receipt,$userId);
        $conn->commit(); respond(true,'Interest collected successfully.',array(
            'receipt_no'=>$receipt,
            'collection_id'=>$collectionId,
            'pawn_id'=>$pawnId,
            'pawn_no'=>$pawn['pawn_no'] ?? '',
            'customer_name'=>$pawn['customer_name'] ?? '',
            'mobile'=>$pawn['mobile'] ?? '',
            'from_date'=>$from,
            'to_date'=>$to,
            'interest_amount'=>$interest,
            'penalty_amount'=>$penalty,
            'other_charges'=>$other,
            'paid_amount'=>$paid,
            'payment_method_id'=>$method,
            'reference_no'=>$ref
        ));
    }catch(Throwable $e){$conn->rollback();respond(false,$e->getMessage(),array(),500);}
}
if ($action === 'payment_collect') {
    $pawnId=(int)($_POST['pawn_id']??0); $date=trim((string)($_POST['payment_date']??date('Y-m-d'))); $principal=max(0,(float)($_POST['principal_amount']??0)); $interest=max(0,(float)($_POST['interest_amount']??0)); $penalty=max(0,(float)($_POST['penalty_amount']??0)); $other=max(0,(float)($_POST['other_charges']??0)); $method=(int)($_POST['payment_method_id']??0); $ref=trim((string)($_POST['reference_no']??'')); $remarks=trim((string)($_POST['remarks']??'')); $full=!empty($_POST['is_closure']); $releasedTo=trim((string)($_POST['released_to']??''));
    if(!dateValid($date)||$method<=0)respond(false,'Payment date and method are required.',array(),422); if($principal+$interest+$penalty+$other<=0)respond(false,'Enter at least one payment amount.',array(),422);
    try{$pawn=getPawn($conn,$businessId,$branchId,$pawnId);}catch(Throwable $e){respond(false,$e->getMessage(),array(),404);} if(!in_array($pawn['status'],array('Active','Partially Paid'),true))respond(false,'This pawn is not open for payment.',array(),422);
    if($principal>(float)$pawn['balance_principal']+0.01)respond(false,'Principal payment exceeds the outstanding principal.',array(),422); if($full&&abs($principal-(float)$pawn['balance_principal'])>0.01)respond(false,'Full settlement must include the complete outstanding principal.',array(),422); if($full&&$releasedTo==='')respond(false,'Enter the name of the person receiving the pawn items.',array(),422);
    $total=$principal+$interest+$penalty+$other; $type=$full?'Full Settlement':($principal>0&&($interest+$penalty+$other)>0?'Part Payment':($principal>0?'Principal Only':'Interest Only'));
    $conn->begin_transaction();
    try{
        $receipt=documentNumber($conn,$businessId,$branchId,'pawn_payment_receipt',$date,true);
        $s=$conn->prepare('INSERT INTO pawn_payments (business_id,branch_id,pawn_entry_id,receipt_no,payment_date,principal_amount,interest_amount,penalty_amount,other_charges,total_amount,payment_type,payment_method_id,reference_no,remarks,is_closure,created_by,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
        $closure=$full?1:0; $s->bind_param('iiissdddddsissii',$businessId,$branchId,$pawnId,$receipt,$date,$principal,$interest,$penalty,$other,$total,$type,$method,$ref,$remarks,$closure,$userId);
        if(!$s->execute())throw new RuntimeException('Unable to save pawn payment: '.$s->error); $paymentId=(int)$s->insert_id;$s->close();
        if(tableExists($conn,'pawn_payment_splits')){$s=$conn->prepare('INSERT INTO pawn_payment_splits (pawn_payment_id,payment_method_id,amount,reference_no) VALUES (?,?,?,?)');$s->bind_param('iids',$paymentId,$method,$total,$ref);$s->execute();$s->close();}
        $newBalance=max(0,(float)$pawn['balance_principal']-$principal); $status=$full?'Closed':($newBalance<(float)$pawn['principal_amount']?'Partially Paid':'Active'); $closureDate=$full?$date:null;
        $s=$conn->prepare('UPDATE pawn_entries SET total_principal_paid=total_principal_paid+?,total_interest_collected=total_interest_collected+?,total_penalty_collected=total_penalty_collected+?,total_other_charges_collected=total_other_charges_collected+?,balance_principal=?,status=?,closure_date=? WHERE id=? AND business_id=?');
        $s->bind_param('dddddssii',$principal,$interest,$penalty,$other,$newBalance,$status,$closureDate,$pawnId,$businessId);$s->execute();$s->close();
        $releaseNo='';
        if($full&&tableExists($conn,'pawn_releases')){
            $releaseNo=documentNumber($conn,$businessId,$branchId,'pawn_release',$date,true);
            $s=$conn->prepare("INSERT INTO pawn_releases (business_id,branch_id,pawn_entry_id,pawn_payment_id,release_no,release_date,principal_paid,interest_paid,penalty_paid,other_charges,total_paid,released_to,identity_verified,item_handover_status,remarks,released_by,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0,'Pending',?,?,NOW())");
            $s->bind_param('iiiissdddddsisi',$businessId,$branchId,$pawnId,$paymentId,$releaseNo,$date,$principal,$interest,$penalty,$other,$total,$releasedTo,$remarks,$userId);
            if(!$s->execute())throw new RuntimeException('Unable to create release record: '.$s->error);$releaseId=(int)$s->insert_id;$s->close();
            actionLog($conn,$businessId,$branchId,$pawnId,'Released','pawn_releases',$releaseId,'Created pending item handover '.$releaseNo,$userId);
        }
        actionLog($conn,$businessId,$branchId,$pawnId,$full?'Closed':'Part Payment','pawn_payments',$paymentId,'Saved pawn payment '.$receipt,$userId);
        $conn->commit(); respond(true,$full?'Pawn fully settled. Release record created.':'Pawn payment saved successfully.',array('receipt_no'=>$receipt,'release_no'=>$releaseNo,'payment_id'=>$paymentId));
    }catch(Throwable $e){$conn->rollback();respond(false,$e->getMessage(),array(),500);}
}
if ($action === 'collections_list') {
    $search=trim((string)($_POST['search']??'')); $from=trim((string)($_POST['from_date']??date('Y-m-01'))); $to=trim((string)($_POST['to_date']??date('Y-m-d'))); $type=trim((string)($_POST['collection_type']??'all'));
    $rows=array();
    if($type==='all'||$type==='interest'){
        $sql="SELECT 'Interest' record_type,pic.id,pic.receipt_no,pic.collection_date record_date,pic.pawn_entry_id,pe.pawn_no,c.customer_name,c.mobile,pic.interest_amount,pic.penalty_amount,pic.other_charges,0 principal_amount,pic.total_amount,pm.method_name,pic.reference_no,pic.collection_type detail_type,pic.is_reversed FROM pawn_interest_collections pic INNER JOIN pawn_entries pe ON pe.id=pic.pawn_entry_id INNER JOIN customers c ON c.id=pe.customer_id LEFT JOIN payment_methods pm ON pm.id=pic.payment_method_id WHERE pic.business_id=? AND pic.branch_id=? AND pic.collection_date BETWEEN ? AND ?";
        $params=array($businessId,$branchId,$from,$to);$types='iiss'; if($search!==''){$sql.=' AND (pic.receipt_no LIKE ? OR pe.pawn_no LIKE ? OR c.customer_name LIKE ? OR c.mobile LIKE ?)';$like='%'.$search.'%';$types.='ssss';$params[]=$like;$params[]=$like;$params[]=$like;$params[]=$like;}
        $s=$conn->prepare($sql);bindDynamic($s,$types,$params);$s->execute();$r=$s->get_result();while($x=$r->fetch_assoc())$rows[]=$x;$s->close();
    }
    if($type==='all'||$type==='payment'){
        $sql="SELECT 'Payment' record_type,pp.id,pp.receipt_no,pp.payment_date record_date,pp.pawn_entry_id,pe.pawn_no,c.customer_name,c.mobile,pp.interest_amount,pp.penalty_amount,pp.other_charges,pp.principal_amount,pp.total_amount,pm.method_name,pp.reference_no,pp.payment_type detail_type,pp.is_reversed FROM pawn_payments pp INNER JOIN pawn_entries pe ON pe.id=pp.pawn_entry_id INNER JOIN customers c ON c.id=pe.customer_id LEFT JOIN payment_methods pm ON pm.id=pp.payment_method_id WHERE pp.business_id=? AND pp.branch_id=? AND pp.payment_date BETWEEN ? AND ?";
        $params=array($businessId,$branchId,$from,$to);$types='iiss'; if($search!==''){$sql.=' AND (pp.receipt_no LIKE ? OR pe.pawn_no LIKE ? OR c.customer_name LIKE ? OR c.mobile LIKE ?)';$like='%'.$search.'%';$types.='ssss';$params[]=$like;$params[]=$like;$params[]=$like;$params[]=$like;}
        $s=$conn->prepare($sql);bindDynamic($s,$types,$params);$s->execute();$r=$s->get_result();while($x=$r->fetch_assoc())$rows[]=$x;$s->close();
    }
    usort($rows,function($a,$b){return strcmp($b['record_date'].'-'.$b['id'],$a['record_date'].'-'.$a['id']);});
    $summary=array('count'=>count($rows),'principal'=>0,'interest'=>0,'penalty'=>0,'other'=>0,'total'=>0); foreach($rows as $x){if((int)$x['is_reversed']===1)continue;$summary['principal']+=(float)$x['principal_amount'];$summary['interest']+=(float)$x['interest_amount'];$summary['penalty']+=(float)$x['penalty_amount'];$summary['other']+=(float)$x['other_charges'];$summary['total']+=(float)$x['total_amount'];}
    respond(true,'',array('rows'=>$rows,'summary'=>$summary));
}
respond(false,'Invalid action.',array(),400);