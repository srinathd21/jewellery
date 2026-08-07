<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_OFF);
ini_set('display_errors','0');

function respond($ok,$message='',$extra=array(),$code=200){
    http_response_code($code);
    echo json_encode(array_merge(array('success'=>(bool)$ok,'message'=>(string)$message),$extra),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

foreach(array(
    dirname(__DIR__).'/config/config.php',
    dirname(__DIR__).'/config.php',
    dirname(__DIR__).'/includes/config.php',
    dirname(__DIR__).'/super-admin/includes/config.php'
) as $f){
    if(is_file($f)){ require_once $f; break; }
}

if(!isset($conn)||!($conn instanceof mysqli)) respond(false,'Database configuration is not available.',array(),500);
$conn->set_charset('utf8mb4');

if(empty($_SESSION['user_id'])) respond(false,'Session expired.',array(),401);
if($_SERVER['REQUEST_METHOD']!=='POST') respond(false,'Invalid request method.',array(),405);
if(!hash_equals((string)($_SESSION['pawn_csrf']??''),(string)($_POST['csrf_token']??''))) respond(false,'Invalid request token. Refresh the page.',array(),419);

$businessId=(int)($_SESSION['business_id']??0);
$branchId=(int)($_SESSION['branch_id']??($_SESSION['default_branch_id']??0));
$action=trim((string)($_POST['action']??''));

if($businessId<=0||$branchId<=0) respond(false,'Select a valid business and branch.',array(),403);

function tableExists($c,$t){
    $t=$c->real_escape_string($t);
    $r=$c->query("SHOW TABLES LIKE '{$t}'");
    return $r&&$r->num_rows>0;
}

function hasColumn($c,$t,$col){
    $t=$c->real_escape_string($t);
    $col=$c->real_escape_string($col);
    $r=$c->query("SHOW COLUMNS FROM `{$t}` LIKE '{$col}'");
    return $r&&$r->num_rows>0;
}

function getPawn($c,$businessId,$branchId,$pawnId){
    $sql="SELECT pe.*,c.customer_name,c.customer_code,c.mobile,c.email,
                 pc.category_name,b.branch_name
          FROM pawn_entries pe
          INNER JOIN customers c
             ON c.id=pe.customer_id AND c.business_id=pe.business_id
          LEFT JOIN pawn_categories pc
             ON pc.id=pe.pawn_category_id
          LEFT JOIN branches b
             ON b.id=pe.branch_id
          WHERE pe.id=? AND pe.business_id=? AND pe.branch_id=?
          LIMIT 1";
    $s=$c->prepare($sql);
    if(!$s) throw new RuntimeException('Unable to load pawn: '.$c->error);
    $s->bind_param('iii',$pawnId,$businessId,$branchId);
    $s->execute();
    $p=$s->get_result()->fetch_assoc();
    $s->close();
    if(!$p) throw new RuntimeException('Pawn entry not found.');
    return $p;
}

if($action==='options'){
    $sql="SELECT pe.id,pe.pawn_no,pe.pawn_date,pe.balance_principal,pe.status,
                 c.customer_name,c.customer_code,c.mobile
          FROM pawn_entries pe
          INNER JOIN customers c
             ON c.id=pe.customer_id AND c.business_id=pe.business_id
          WHERE pe.business_id=? AND pe.branch_id=?
          ORDER BY pe.id DESC";
    $s=$conn->prepare($sql);
    if(!$s) respond(false,'Unable to load pawn list: '.$conn->error,array(),500);
    $s->bind_param('ii',$businessId,$branchId);
    $s->execute();
    $r=$s->get_result();
    $rows=array();
    while($x=$r->fetch_assoc()) $rows[]=$x;
    $s->close();
    respond(true,'',array('pawns'=>$rows));
}

if($action==='ledger'){
    $pawnId=(int)($_POST['pawn_id']??0);
    $from=trim((string)($_POST['from_date']??''));
    $to=trim((string)($_POST['to_date']??''));

    if($pawnId<=0) respond(false,'Select a pawn.',array(),422);

    try{
        $pawn=getPawn($conn,$businessId,$branchId,$pawnId);
    }catch(Throwable $e){
        respond(false,$e->getMessage(),array(),404);
    }

    $rows=array();

    // Opening row
    $rows[]=array(
        'sort_key'=>(string)$pawn['pawn_date'].'-000000',
        'record_date'=>$pawn['pawn_date'],
        'type'=>'Opening',
        'record_id'=>0,
        'reference'=>$pawn['pawn_no'],
        'description'=>'Pawn opened / principal disbursed',
        'principal_amount'=>(float)$pawn['principal_amount'],
        'interest_amount'=>0,
        'penalty_amount'=>0,
        'other_charges'=>0,
        'total_amount'=>(float)$pawn['principal_amount'],
        'balance_after'=>(float)$pawn['principal_amount'],
        'payment_method'=>'—',
        'payment_reference'=>''
    );

    if(tableExists($conn,'pawn_interest_collections')){
        $sql="SELECT pic.*,pm.method_name
              FROM pawn_interest_collections pic
              LEFT JOIN payment_methods pm ON pm.id=pic.payment_method_id
              WHERE pic.pawn_entry_id=? AND pic.business_id=?";
        $params=array($pawnId,$businessId);
        $types='ii';

        if($from!==''){ $sql.=" AND pic.collection_date>=?"; $types.='s'; $params[]=$from; }
        if($to!==''){ $sql.=" AND pic.collection_date<=?"; $types.='s'; $params[]=$to; }

        $sql.=" ORDER BY pic.collection_date,pic.id";
        $s=$conn->prepare($sql);
        if($s){
            $args=array($types);
            foreach($params as $k=>$v) $args[]=&$params[$k];
            call_user_func_array(array($s,'bind_param'),$args);
            $s->execute();
            $r=$s->get_result();
            while($x=$r->fetch_assoc()){
                if(isset($x['is_reversed'])&&(int)$x['is_reversed']===1) continue;
                $rows[]=array(
                    'sort_key'=>(string)$x['collection_date'].'-I-'.str_pad((string)$x['id'],10,'0',STR_PAD_LEFT),
                    'record_date'=>$x['collection_date'],
                    'type'=>'Interest',
                    'record_id'=>(int)$x['id'],
                    'reference'=>$x['receipt_no']??'',
                    'description'=>'Interest collection '.(($x['from_date']??'')&&($x['to_date']??'')?'('.$x['from_date'].' to '.$x['to_date'].')':''),
                    'principal_amount'=>0,
                    'interest_amount'=>(float)($x['interest_amount']??0),
                    'penalty_amount'=>(float)($x['penalty_amount']??0),
                    'other_charges'=>(float)($x['other_charges']??0),
                    'total_amount'=>(float)($x['total_amount']??0),
                    'balance_after'=>0,
                    'payment_method'=>$x['method_name']??'',
                    'payment_reference'=>$x['reference_no']??''
                );
            }
            $s->close();
        }
    }

    if(tableExists($conn,'pawn_payments')){
        $sql="SELECT pp.*,pm.method_name
              FROM pawn_payments pp
              LEFT JOIN payment_methods pm ON pm.id=pp.payment_method_id
              WHERE pp.pawn_entry_id=? AND pp.business_id=?";
        $params=array($pawnId,$businessId);
        $types='ii';

        if($from!==''){ $sql.=" AND pp.payment_date>=?"; $types.='s'; $params[]=$from; }
        if($to!==''){ $sql.=" AND pp.payment_date<=?"; $types.='s'; $params[]=$to; }

        $sql.=" ORDER BY pp.payment_date,pp.id";
        $s=$conn->prepare($sql);
        if($s){
            $args=array($types);
            foreach($params as $k=>$v) $args[]=&$params[$k];
            call_user_func_array(array($s,'bind_param'),$args);
            $s->execute();
            $r=$s->get_result();
            while($x=$r->fetch_assoc()){
                if(isset($x['is_reversed'])&&(int)$x['is_reversed']===1) continue;
                $isClosure=!empty($x['is_closure'])||((string)($x['payment_type']??'')==='Full Settlement');
                $rows[]=array(
                    'sort_key'=>(string)$x['payment_date'].'-P-'.str_pad((string)$x['id'],10,'0',STR_PAD_LEFT),
                    'record_date'=>$x['payment_date'],
                    'type'=>$isClosure?'Closure':'Payment',
                    'record_id'=>(int)$x['id'],
                    'reference'=>$x['receipt_no']??'',
                    'description'=>$x['payment_type']??($isClosure?'Full Settlement':'Pawn Payment'),
                    'principal_amount'=>(float)($x['principal_amount']??0),
                    'interest_amount'=>(float)($x['interest_amount']??0),
                    'penalty_amount'=>(float)($x['penalty_amount']??0),
                    'other_charges'=>(float)($x['other_charges']??0),
                    'total_amount'=>(float)($x['total_amount']??0),
                    'balance_after'=>0,
                    'payment_method'=>$x['method_name']??'',
                    'payment_reference'=>$x['reference_no']??''
                );
            }
            $s->close();
        }
    }

    if(tableExists($conn,'pawn_releases')){
        $sql="SELECT * FROM pawn_releases WHERE pawn_entry_id=? AND business_id=?";
        $params=array($pawnId,$businessId);
        $types='ii';

        if($from!==''){ $sql.=" AND release_date>=?"; $types.='s'; $params[]=$from; }
        if($to!==''){ $sql.=" AND release_date<=?"; $types.='s'; $params[]=$to; }

        $sql.=" ORDER BY release_date,id";
        $s=$conn->prepare($sql);
        if($s){
            $args=array($types);
            foreach($params as $k=>$v) $args[]=&$params[$k];
            call_user_func_array(array($s,'bind_param'),$args);
            $s->execute();
            $r=$s->get_result();
            while($x=$r->fetch_assoc()){
                $rows[]=array(
                    'sort_key'=>(string)$x['release_date'].'-R-'.str_pad((string)$x['id'],10,'0',STR_PAD_LEFT),
                    'record_date'=>$x['release_date'],
                    'type'=>'Release',
                    'record_id'=>(int)$x['id'],
                    'reference'=>$x['release_no']??'',
                    'description'=>'Items released to '.($x['released_to']??'customer').' · '.($x['item_handover_status']??''),
                    'principal_amount'=>0,
                    'interest_amount'=>0,
                    'penalty_amount'=>0,
                    'other_charges'=>0,
                    'total_amount'=>0,
                    'balance_after'=>0,
                    'payment_method'=>'—',
                    'payment_reference'=>''
                );
            }
            $s->close();
        }
    }

    usort($rows,function($a,$b){ return strcmp($a['sort_key'],$b['sort_key']); });

    // Running principal balance
    $running=(float)$pawn['principal_amount'];
    foreach($rows as &$row){
        if($row['type']==='Payment'||$row['type']==='Closure'){
            $running=max(0,$running-(float)$row['principal_amount']);
        }
        if($row['type']==='Opening'){
            $running=(float)$pawn['principal_amount'];
        }
        $row['balance_after']=round($running,2);
        unset($row['sort_key']);
    }
    unset($row);

    $principalPaid=(float)($pawn['total_principal_paid']??0);
    $interestCollected=(float)($pawn['total_interest_collected']??0);
    $penaltyCollected=(float)($pawn['total_penalty_collected']??0);
    $otherCollected=(float)($pawn['total_other_charges_collected']??0);

    // Fallback totals from transaction tables when summary columns don't exist / are zero.
    $calcPrincipal=0;$calcInterest=0;$calcPenalty=0;$calcOther=0;
    foreach($rows as $x){
        if($x['type']==='Payment'||$x['type']==='Closure'){
            $calcPrincipal+=(float)$x['principal_amount'];
            $calcInterest+=(float)$x['interest_amount'];
            $calcPenalty+=(float)$x['penalty_amount'];
            $calcOther+=(float)$x['other_charges'];
        }elseif($x['type']==='Interest'){
            $calcInterest+=(float)$x['interest_amount'];
            $calcPenalty+=(float)$x['penalty_amount'];
            $calcOther+=(float)$x['other_charges'];
        }
    }

    if($principalPaid<=0&&$calcPrincipal>0) $principalPaid=$calcPrincipal;
    if($interestCollected<=0&&$calcInterest>0) $interestCollected=$calcInterest;
    if($penaltyCollected<=0&&$calcPenalty>0) $penaltyCollected=$calcPenalty;
    if($otherCollected<=0&&$calcOther>0) $otherCollected=$calcOther;

    $summary=array(
        'original_principal'=>(float)$pawn['principal_amount'],
        'principal_paid'=>$principalPaid,
        'balance_principal'=>(float)$pawn['balance_principal'],
        'interest_collected'=>$interestCollected,
        'penalty_collected'=>$penaltyCollected,
        'other_charges_collected'=>$otherCollected,
        'total_collected'=>$principalPaid+$interestCollected+$penaltyCollected+$otherCollected
    );

    respond(true,'',array(
        'pawn'=>$pawn,
        'rows'=>$rows,
        'summary'=>$summary
    ));
}

respond(false,'Invalid action.',array(),400);
