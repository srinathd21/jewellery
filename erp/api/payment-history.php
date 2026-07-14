<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));

function respond(bool $success,string $message,array $extra=[],int $status=200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['success'=>$success,'message'=>$message],$extra),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

$rootDir=dirname(__DIR__);
$configCandidates=[
    $rootDir.'/config/config.php',
    $rootDir.'/config.php',
    $rootDir.'/includes/config.php',
    $rootDir.'/super-admin/includes/config.php',
];

$configLoaded=false;
foreach($configCandidates as $configFile){
    if(is_file($configFile)){
        require_once $configFile;
        $configLoaded=true;
        break;
    }
}

if(!$configLoaded||!isset($conn)||!($conn instanceof mysqli)){
    respond(false,'Database configuration is not available.',[],500);
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn->set_charset('utf8mb4');

function tableExists(mysqli $conn,string $table): bool
{
    $safe=$conn->real_escape_string($table);
    $result=$conn->query("SHOW TABLES LIKE '{$safe}'");
    return $result&&$result->num_rows>0;
}

function hasColumn(mysqli $conn,string $table,string $column): bool
{
    $table=$conn->real_escape_string($table);
    $column=$conn->real_escape_string($column);
    $result=$conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    return $result&&$result->num_rows>0;
}

function firstColumn(mysqli $conn,string $table,array $candidates): string
{
    foreach($candidates as $column){
        if(hasColumn($conn,$table,$column)){
            return $column;
        }
    }
    return '';
}

function bindDynamic(mysqli_stmt $stmt,string $types,array &$params): void
{
    if(!$params)return;
    $bind=[$types];
    foreach($params as $key=>$value){
        $bind[]=&$params[$key];
    }
    call_user_func_array([$stmt,'bind_param'],$bind);
}

function validDate(string $date): bool
{
    $object=DateTime::createFromFormat('Y-m-d',$date);
    return $object&&$object->format('Y-m-d')===$date;
}

function resolvePeriod(string $range,string $from,string $to): array
{
    $today=date('Y-m-d');

    switch($range){
        case 'yesterday':
            $start=date('Y-m-d',strtotime('-1 day'));
            $end=$start;
            break;
        case 'week':
            $start=date('Y-m-d',strtotime('monday this week'));
            $end=$today;
            break;
        case 'month':
            $start=date('Y-m-d',strtotime('first day of this month'));
            $end=$today;
            break;
        case 'custom':
            $start=validDate($from)?$from:date('Y-m-d',strtotime('-30 days'));
            $end=validDate($to)?$to:$today;
            break;
        case 'today':
        default:
            $start=$today;
            $end=$today;
            break;
    }

    if(validDate($from)&&validDate($to)){
        $start=$from;
        $end=$to;
    }

    if($start>$end){
        [$start,$end]=[$end,$start];
    }

    return [$start,$end];
}

function methodMeta(mysqli $conn): array
{
    if(!tableExists($conn,'payment_methods')){
        return ['exists'=>false,'id'=>'','name'=>''];
    }

    return [
        'exists'=>true,
        'id'=>firstColumn($conn,'payment_methods',['payment_method_id','id']),
        'name'=>firstColumn($conn,'payment_methods',['payment_method_name','method_name','name']),
    ];
}

function normalizedRows(
    mysqli $conn,
    string $table,
    int $businessId,
    int $branchId,
    string $startDate,
    string $endDate,
    string $search,
    array $options
): array {
    if(!tableExists($conn,$table)){
        return [];
    }

    $alias=$options['alias'];
    $dateColumn=firstColumn($conn,$table,$options['date_columns']);
    $amountColumn=firstColumn($conn,$table,$options['amount_columns']);

    if($dateColumn===''||$amountColumn===''){
        return [];
    }

    $numberColumn=firstColumn($conn,$table,$options['number_columns']);
    $referenceColumn=firstColumn($conn,$table,['reference_no','transaction_no','cheque_no']);
    $notesColumn=firstColumn($conn,$table,['notes','remarks','description']);
    $paymentTypeColumn=firstColumn($conn,$table,['payment_type','collection_type','type']);
    $methodIdColumn=firstColumn($conn,$table,['payment_method_id','method_id']);

    $select=[
        "{$alias}.id AS id",
        "{$alias}.`{$dateColumn}` AS txn_date",
        "{$alias}.`{$amountColumn}` AS amount",
        $numberColumn!==''?"{$alias}.`{$numberColumn}` AS number":"'' AS number",
        $referenceColumn!==''?"{$alias}.`{$referenceColumn}` AS reference":"'' AS reference",
        $notesColumn!==''?"{$alias}.`{$notesColumn}` AS notes":"'' AS notes",
        $paymentTypeColumn!==''?"{$alias}.`{$paymentTypeColumn}` AS payment_type":"'' AS payment_type",
        "'' AS party_name",
        "'' AS party_code",
        "'' AS mobile",
        "'' AS reference_entity",
        "'' AS method",
    ];

    $joins=[];
    $searchParts=[];

    if(!empty($options['party'])){
        $party=$options['party'];
        $partyTable=$party['table'];
        $partyAlias=$party['alias'];
        $fkColumn=firstColumn($conn,$table,$party['fk_columns']);
        $partyIdColumn=firstColumn($conn,$partyTable,['id']);

        if($fkColumn!==''&&$partyIdColumn!==''&&tableExists($conn,$partyTable)){
            $partyNameColumn=firstColumn($conn,$partyTable,$party['name_columns']);
            $partyCodeColumn=firstColumn($conn,$partyTable,$party['code_columns']);
            $partyMobileColumn=firstColumn($conn,$partyTable,$party['mobile_columns']);

            $joins[]="LEFT JOIN `{$partyTable}` {$partyAlias} ON {$partyAlias}.`{$partyIdColumn}`={$alias}.`{$fkColumn}`";

            $select[7]=$partyNameColumn!==''?"{$partyAlias}.`{$partyNameColumn}` AS party_name":"'' AS party_name";
            $select[8]=$partyCodeColumn!==''?"{$partyAlias}.`{$partyCodeColumn}` AS party_code":"'' AS party_code";
            $select[9]=$partyMobileColumn!==''?"{$partyAlias}.`{$partyMobileColumn}` AS mobile":"'' AS mobile";

            if($partyNameColumn!=='')$searchParts[]="{$partyAlias}.`{$partyNameColumn}` LIKE ?";
            if($partyCodeColumn!=='')$searchParts[]="{$partyAlias}.`{$partyCodeColumn}` LIKE ?";
            if($partyMobileColumn!=='')$searchParts[]="{$partyAlias}.`{$partyMobileColumn}` LIKE ?";
        }
    }

    if(!empty($options['reference_entity'])){
        $entity=$options['reference_entity'];
        $entityTable=$entity['table'];
        $entityAlias=$entity['alias'];
        $fkColumn=firstColumn($conn,$table,$entity['fk_columns']);

        if($fkColumn!==''&&tableExists($conn,$entityTable)){
            $entityNameColumn=firstColumn($conn,$entityTable,$entity['name_columns']);
            if($entityNameColumn!==''){
                $joins[]="LEFT JOIN `{$entityTable}` {$entityAlias} ON {$entityAlias}.id={$alias}.`{$fkColumn}`";
                $select[10]="{$entityAlias}.`{$entityNameColumn}` AS reference_entity";
                $searchParts[]="{$entityAlias}.`{$entityNameColumn}` LIKE ?";
            }
        }
    }

    $method=methodMeta($conn);
    if($method['exists']&&$method['id']!==''&&$method['name']!==''&&$methodIdColumn!==''){
        $joins[]="LEFT JOIN payment_methods pm ON pm.`{$method['id']}`={$alias}.`{$methodIdColumn}`";
        $select[11]="pm.`{$method['name']}` AS method";
        $searchParts[]="pm.`{$method['name']}` LIKE ?";
    }

    $where=["{$alias}.business_id=?","DATE({$alias}.`{$dateColumn}`) BETWEEN ? AND ?"];
    $types='iss';
    $params=[$businessId,$startDate,$endDate];

    if(hasColumn($conn,$table,'branch_id')&&$branchId>0){
        $where[]="{$alias}.branch_id=?";
        $types.='i';
        $params[]=$branchId;
    }

    if(!empty($options['filter_id'])&&!empty($options['filter_value'])){
        $filterColumn=firstColumn($conn,$table,$options['filter_id']);
        if($filterColumn!==''){
            $where[]="{$alias}.`{$filterColumn}`=?";
            $types.='i';
            $params[]=(int)$options['filter_value'];
        }
    }

    if($search!==''){
        $like='%'.$search.'%';
        if($numberColumn!=='')$searchParts[]="{$alias}.`{$numberColumn}` LIKE ?";
        if($referenceColumn!=='')$searchParts[]="{$alias}.`{$referenceColumn}` LIKE ?";

        if($searchParts){
            $where[]='('.implode(' OR ',$searchParts).')';
            foreach($searchParts as $_){
                $types.='s';
                $params[]=$like;
            }
        }
    }

    $sql="SELECT ".implode(',',$select)."
          FROM `{$table}` {$alias}
          ".implode(' ',$joins)."
          WHERE ".implode(' AND ',$where)."
          ORDER BY {$alias}.`{$dateColumn}` DESC,{$alias}.id DESC";

    $stmt=$conn->prepare($sql);

    if(!$stmt){
        return [];
    }

    bindDynamic($stmt,$types,$params);
    $stmt->execute();
    $result=$stmt->get_result();
    $rows=[];

    while($result&&$row=$result->fetch_assoc()){
        $row['id']=(int)$row['id'];
        $row['amount']=(float)$row['amount'];
        $row['date_display']=!empty($row['txn_date'])?date('d-m-Y',strtotime($row['txn_date'])):'—';
        $rows[]=$row;
    }

    $stmt->close();
    return $rows;
}

if(empty($_SESSION['user_id'])){
    respond(false,'Your session has expired. Please log in again.',[],401);
}

$businessId=(int)($_SESSION['business_id']??0);
$branchId=(int)($_SESSION['branch_id']??$_SESSION['default_branch_id']??0);

if($businessId<=0){
    respond(false,'A valid business must be selected.',[],403);
}

$action=strtolower(trim((string)($_GET['action']??'list')));

if($action==='bootstrap'){
    $customers=[];
    if(tableExists($conn,'customers')){
        $nameColumn=firstColumn($conn,'customers',['customer_name','name']);
        $codeColumn=firstColumn($conn,'customers',['customer_code','code']);
        if($nameColumn!==''){
            $sql="SELECT id,`{$nameColumn}` AS name,".($codeColumn!==''?"`{$codeColumn}`":"''")." AS code
                  FROM customers WHERE business_id=?";
            if(hasColumn($conn,'customers','is_active'))$sql.=" AND is_active=1";
            elseif(hasColumn($conn,'customers','status'))$sql.=" AND (status=1 OR status='Active')";
            $sql.=" ORDER BY `{$nameColumn}` ASC";
            $stmt=$conn->prepare($sql);
            if($stmt){
                $stmt->bind_param('i',$businessId);
                $stmt->execute();
                $result=$stmt->get_result();
                while($result&&$row=$result->fetch_assoc()){
                    $customers[]=['id'=>(int)$row['id'],'name'=>(string)$row['name'],'code'=>(string)$row['code']];
                }
                $stmt->close();
            }
        }
    }

    $suppliers=[];
    if(tableExists($conn,'suppliers')){
        $nameColumn=firstColumn($conn,'suppliers',['supplier_name','name']);
        $codeColumn=firstColumn($conn,'suppliers',['supplier_code','code']);
        if($nameColumn!==''){
            $sql="SELECT id,`{$nameColumn}` AS name,".($codeColumn!==''?"`{$codeColumn}`":"''")." AS code
                  FROM suppliers WHERE business_id=?";
            if(hasColumn($conn,'suppliers','is_active'))$sql.=" AND is_active=1";
            elseif(hasColumn($conn,'suppliers','status'))$sql.=" AND (status=1 OR status='Active')";
            $sql.=" ORDER BY `{$nameColumn}` ASC";
            $stmt=$conn->prepare($sql);
            if($stmt){
                $stmt->bind_param('i',$businessId);
                $stmt->execute();
                $result=$stmt->get_result();
                while($result&&$row=$result->fetch_assoc()){
                    $suppliers[]=['id'=>(int)$row['id'],'name'=>(string)$row['name'],'code'=>(string)$row['code']];
                }
                $stmt->close();
            }
        }
    }

    respond(true,'Filter data loaded.',['customers'=>$customers,'suppliers'=>$suppliers]);
}

$dateRange=trim((string)($_GET['date_range']??'today'));
$fromDate=trim((string)($_GET['from_date']??''));
$toDate=trim((string)($_GET['to_date']??''));
$customerId=(int)($_GET['customer_id']??0);
$supplierId=(int)($_GET['supplier_id']??0);
$search=trim((string)($_GET['search']??''));

[$startDate,$endDate]=resolvePeriod($dateRange,$fromDate,$toDate);

$customerPayments=normalizedRows($conn,'customer_payments',$businessId,$branchId,$startDate,$endDate,$search,[
    'alias'=>'cp',
    'date_columns'=>['receipt_date','payment_date','created_at'],
    'amount_columns'=>['amount','paid_amount','net_amount'],
    'number_columns'=>['receipt_no','payment_no','transaction_no'],
    'filter_id'=>['customer_id'],
    'filter_value'=>$customerId,
    'party'=>[
        'table'=>'customers','alias'=>'c','fk_columns'=>['customer_id'],
        'name_columns'=>['customer_name','name'],'code_columns'=>['customer_code','code'],'mobile_columns'=>['mobile','phone']
    ],
]);

$supplierPayments=normalizedRows($conn,'supplier_payments',$businessId,$branchId,$startDate,$endDate,$search,[
    'alias'=>'sp',
    'date_columns'=>['payment_date','created_at'],
    'amount_columns'=>['amount','paid_amount','net_amount'],
    'number_columns'=>['payment_no','receipt_no','transaction_no'],
    'filter_id'=>['supplier_id'],
    'filter_value'=>$supplierId,
    'party'=>[
        'table'=>'suppliers','alias'=>'s','fk_columns'=>['supplier_id'],
        'name_columns'=>['supplier_name','name'],'code_columns'=>['supplier_code','code'],'mobile_columns'=>['mobile','phone']
    ],
]);

$pawnPayments=normalizedRows($conn,'pawn_payments',$businessId,$branchId,$startDate,$endDate,$search,[
    'alias'=>'pp',
    'date_columns'=>['payment_date','receipt_date','created_at'],
    'amount_columns'=>['total_amount','amount','paid_amount'],
    'number_columns'=>['receipt_no','payment_no'],
    'party'=>[
        'table'=>'pawn_entries','alias'=>'pe','fk_columns'=>['pawn_id','pawn_entry_id'],
        'name_columns'=>['customer_name'],'code_columns'=>['pawn_no'],'mobile_columns'=>['customer_mobile','mobile']
    ],
    'reference_entity'=>[
        'table'=>'pawn_entries','alias'=>'pe2','fk_columns'=>['pawn_id','pawn_entry_id'],'name_columns'=>['pawn_no']
    ],
]);

$pawnInterest=normalizedRows($conn,'pawn_interest_collections',$businessId,$branchId,$startDate,$endDate,$search,[
    'alias'=>'pic',
    'date_columns'=>['collection_date','payment_date','created_at'],
    'amount_columns'=>['net_amount','amount','interest_amount'],
    'number_columns'=>['receipt_no','payment_no'],
    'party'=>[
        'table'=>'pawn_entries','alias'=>'pe','fk_columns'=>['pawn_id','pawn_entry_id'],
        'name_columns'=>['customer_name'],'code_columns'=>['pawn_no'],'mobile_columns'=>['customer_mobile','mobile']
    ],
    'reference_entity'=>[
        'table'=>'pawn_entries','alias'=>'pe2','fk_columns'=>['pawn_id','pawn_entry_id'],'name_columns'=>['pawn_no']
    ],
]);

$chitCollections=normalizedRows($conn,'chit_collections',$businessId,$branchId,$startDate,$endDate,$search,[
    'alias'=>'cc',
    'date_columns'=>['collection_date','payment_date','created_at'],
    'amount_columns'=>['net_amount','paid_amount','amount'],
    'number_columns'=>['receipt_no','payment_no'],
    'party'=>[
        'table'=>'chit_members','alias'=>'cm','fk_columns'=>['member_id'],
        'name_columns'=>['subscriber_name','member_name','name'],'code_columns'=>['member_no','code'],'mobile_columns'=>['mobile','phone']
    ],
    'reference_entity'=>[
        'table'=>'chit_groups','alias'=>'cg','fk_columns'=>['group_id'],'name_columns'=>['group_no','group_name']
    ],
]);

$totalCustomer=array_sum(array_column($customerPayments,'amount'));
$totalSupplier=array_sum(array_column($supplierPayments,'amount'));
$totalPawn=array_sum(array_column($pawnPayments,'amount'));
$totalPawnInterest=array_sum(array_column($pawnInterest,'amount'));
$totalChit=array_sum(array_column($chitCollections,'amount'));

$totalIncoming=$totalCustomer+$totalPawn+$totalPawnInterest+$totalChit;
$totalOutgoing=$totalSupplier;
$netBalance=$totalIncoming-$totalOutgoing;

if($action==='export'){
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="payment_history_'.date('Y-m-d').'.csv"');
    $output=fopen('php://output','w');
    fputcsv($output,['Payment History']);
    fputcsv($output,['Period',date('d-m-Y',strtotime($startDate)).' to '.date('d-m-Y',strtotime($endDate))]);
    fputcsv($output,[]);
    fputcsv($output,['Type','Date','Number','Party','Code','Amount','Method','Reference','Notes']);

    $writeRows=function(string $type,array $rows) use($output): void {
        foreach($rows as $row){
            fputcsv($output,[
                $type,$row['date_display'],$row['number'],$row['party_name'],$row['party_code'],
                number_format((float)$row['amount'],2,'.',''),$row['method'],$row['reference'],$row['notes']
            ]);
        }
    };

    $writeRows('Customer Payment',$customerPayments);
    $writeRows('Pawn Payment',$pawnPayments);
    $writeRows('Pawn Interest',$pawnInterest);
    $writeRows('Chit Collection',$chitCollections);
    $writeRows('Supplier Payment',$supplierPayments);
    fputcsv($output,[]);
    fputcsv($output,['Total Incoming',number_format($totalIncoming,2,'.','')]);
    fputcsv($output,['Total Outgoing',number_format($totalOutgoing,2,'.','')]);
    fputcsv($output,['Net Balance',number_format($netBalance,2,'.','')]);
    fclose($output);
    exit;
}

respond(true,'Payment history loaded successfully.',[
    'period'=>[
        'from'=>$startDate,'to'=>$endDate,
        'from_display'=>date('d-m-Y',strtotime($startDate)),
        'to_display'=>date('d-m-Y',strtotime($endDate)),
    ],
    'summary'=>[
        'total_incoming'=>$totalIncoming,
        'total_outgoing'=>$totalOutgoing,
        'net_balance'=>$netBalance,
    ],
    'totals'=>[
        'customer'=>$totalCustomer,
        'supplier'=>$totalSupplier,
        'pawn'=>$totalPawn,
        'pawn_interest'=>$totalPawnInterest,
        'chit'=>$totalChit,
    ],
    'customer_payments'=>$customerPayments,
    'supplier_payments'=>$supplierPayments,
    'pawn_payments'=>$pawnPayments,
    'pawn_interest'=>$pawnInterest,
    'chit_collections'=>$chitCollections,
]);
