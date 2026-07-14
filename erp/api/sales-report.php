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

$root=dirname(__DIR__);
$configCandidates=[
    $root.'/config/config.php',
    $root.'/config.php',
    $root.'/includes/config.php',
    $root.'/super-admin/includes/config.php',
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
    $safeTable=$conn->real_escape_string($table);
    $safeColumn=$conn->real_escape_string($column);
    $result=$conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    return $result&&$result->num_rows>0;
}

function firstColumn(mysqli $conn,string $table,array $columns): string
{
    foreach($columns as $column){
        if(hasColumn($conn,$table,$column))return $column;
    }
    return '';
}

function bindDynamic(mysqli_stmt $stmt,string $types,array &$params): void
{
    if(!$params)return;
    $bind=[$types];
    foreach($params as $key=>$value)$bind[]=&$params[$key];
    call_user_func_array([$stmt,'bind_param'],$bind);
}

function validDate(string $date): bool
{
    $object=DateTime::createFromFormat('Y-m-d',$date);
    return $object&&$object->format('Y-m-d')===$date;
}

if(empty($_SESSION['user_id'])){
    respond(false,'Your session has expired. Please log in again.',[],401);
}

$businessId=(int)($_SESSION['business_id']??0);
$branchId=(int)($_SESSION['branch_id']??$_SESSION['default_branch_id']??0);

if($businessId<=0){
    respond(false,'A valid business must be selected.',[],403);
}

if(!tableExists($conn,'sales')){
    respond(false,'Required table `sales` was not found.',[],500);
}

$action=strtolower(trim((string)($_GET['action']??'list')));

$customerIdColumn=tableExists($conn,'customers')?firstColumn($conn,'customers',['id','customer_id']):'';
$customerNameColumn=tableExists($conn,'customers')?firstColumn($conn,'customers',['customer_name','name']):'';
$customerCodeColumn=tableExists($conn,'customers')?firstColumn($conn,'customers',['customer_code','code']):'';
$customerMobileColumn=tableExists($conn,'customers')?firstColumn($conn,'customers',['mobile','phone']):'';

$paymentMethodIdColumn=tableExists($conn,'payment_methods')?firstColumn($conn,'payment_methods',['payment_method_id','id','method_id']):'';
$paymentMethodNameColumn=tableExists($conn,'payment_methods')?firstColumn($conn,'payment_methods',['payment_method_name','method_name','name']):'';

if($action==='bootstrap'){
    $customers=[];

    if($customerIdColumn!==''&&$customerNameColumn!==''){
        $sql="SELECT `{$customerIdColumn}` AS id,`{$customerNameColumn}` AS customer_name,".
            ($customerCodeColumn!==''?"`{$customerCodeColumn}`":"''")." AS customer_code
              FROM customers
              WHERE business_id=?";

        if(hasColumn($conn,'customers','is_active'))$sql.=" AND is_active=1";
        elseif(hasColumn($conn,'customers','status'))$sql.=" AND (status=1 OR status='Active')";

        $sql.=" ORDER BY `{$customerNameColumn}` ASC";
        $stmt=$conn->prepare($sql);

        if($stmt){
            $stmt->bind_param('i',$businessId);
            $stmt->execute();
            $result=$stmt->get_result();

            while($result&&$row=$result->fetch_assoc()){
                $customers[]=[
                    'id'=>(int)$row['id'],
                    'customer_name'=>(string)$row['customer_name'],
                    'customer_code'=>(string)$row['customer_code'],
                ];
            }

            $stmt->close();
        }
    }

    $billTypes=['Retail','GST','Estimate','Exchange'];
    if(hasColumn($conn,'sales','bill_type')){
        $sql="SELECT DISTINCT bill_type FROM sales WHERE business_id=? AND bill_type IS NOT NULL AND bill_type<>'' ORDER BY bill_type";
        $stmt=$conn->prepare($sql);
        if($stmt){
            $stmt->bind_param('i',$businessId);
            $stmt->execute();
            $result=$stmt->get_result();
            $dynamic=[];
            while($result&&$row=$result->fetch_assoc())$dynamic[]=(string)$row['bill_type'];
            $stmt->close();
            if($dynamic)$billTypes=$dynamic;
        }
    }

    $paymentStatuses=['Paid','Partial','Unpaid'];
    if(hasColumn($conn,'sales','payment_status')){
        $sql="SELECT DISTINCT payment_status FROM sales WHERE business_id=? AND payment_status IS NOT NULL AND payment_status<>'' ORDER BY payment_status";
        $stmt=$conn->prepare($sql);
        if($stmt){
            $stmt->bind_param('i',$businessId);
            $stmt->execute();
            $result=$stmt->get_result();
            $dynamic=[];
            while($result&&$row=$result->fetch_assoc())$dynamic[]=(string)$row['payment_status'];
            $stmt->close();
            if($dynamic)$paymentStatuses=$dynamic;
        }
    }

    respond(true,'Sales report filters loaded.',[
        'customers'=>$customers,
        'bill_types'=>$billTypes,
        'payment_statuses'=>$paymentStatuses,
        'defaults'=>[
            'from_date'=>date('Y-m-01'),
            'to_date'=>date('Y-m-d'),
        ],
    ]);
}

$fromDate=trim((string)($_GET['from_date']??date('Y-m-01')));
$toDate=trim((string)($_GET['to_date']??date('Y-m-d')));
$customerId=(int)($_GET['customer_id']??0);
$billType=trim((string)($_GET['bill_type']??''));
$paymentStatus=trim((string)($_GET['payment_status']??''));
$search=trim((string)($_GET['search']??''));

if(!validDate($fromDate))$fromDate=date('Y-m-01');
if(!validDate($toDate))$toDate=date('Y-m-d');
if($fromDate>$toDate)[$fromDate,$toDate]=[$toDate,$fromDate];

$saleIdColumn=firstColumn($conn,'sales',['id','sale_id']);
$billNoColumn=firstColumn($conn,'sales',['bill_no','invoice_no','sale_no']);
$billDateColumn=firstColumn($conn,'sales',['bill_date','sale_date','invoice_date','created_at']);
$billTimeColumn=firstColumn($conn,'sales',['bill_time','sale_time']);
$saleCustomerIdColumn=firstColumn($conn,'sales',['customer_id']);
$saleCustomerNameColumn=firstColumn($conn,'sales',['customer_name']);
$saleCustomerMobileColumn=firstColumn($conn,'sales',['customer_mobile','mobile']);
$salePaymentMethodColumn=firstColumn($conn,'sales',['payment_method_id','method_id']);

if($saleIdColumn===''||$billDateColumn===''){
    respond(false,'The sales table has no supported ID or bill-date column.',[],500);
}

$joins=[];
$customerJoin='';

if(
    $saleCustomerIdColumn!==''&&
    $customerIdColumn!==''&&
    $customerNameColumn!==''&&
    tableExists($conn,'customers')
){
    $customerJoin="LEFT JOIN customers c ON c.`{$customerIdColumn}`=s.`{$saleCustomerIdColumn}`";
    $joins[]=$customerJoin;
}

$methodJoin='';
if(
    $salePaymentMethodColumn!==''&&
    $paymentMethodIdColumn!==''&&
    $paymentMethodNameColumn!==''&&
    tableExists($conn,'payment_methods')
){
    $methodJoin="LEFT JOIN payment_methods pm ON pm.`{$paymentMethodIdColumn}`=s.`{$salePaymentMethodColumn}`";
    $joins[]=$methodJoin;
}

$where=['s.business_id=?',"DATE(s.`{$billDateColumn}`) BETWEEN ? AND ?"];
$types='iss';
$params=[$businessId,$fromDate,$toDate];

if(hasColumn($conn,'sales','branch_id')&&$branchId>0){
    $where[]='s.branch_id=?';
    $types.='i';
    $params[]=$branchId;
}

if(hasColumn($conn,'sales','status')){
    $where[]="(s.status='Active' OR s.status=1)";
}

if($customerId>0&&$saleCustomerIdColumn!==''){
    $where[]="s.`{$saleCustomerIdColumn}`=?";
    $types.='i';
    $params[]=$customerId;
}

if($billType!==''&&hasColumn($conn,'sales','bill_type')){
    $where[]='s.bill_type=?';
    $types.='s';
    $params[]=$billType;
}

if($paymentStatus!==''&&hasColumn($conn,'sales','payment_status')){
    $where[]='s.payment_status=?';
    $types.='s';
    $params[]=$paymentStatus;
}

if($search!==''){
    $parts=[];
    $like='%'.$search.'%';

    if($billNoColumn!=='')$parts[]="s.`{$billNoColumn}` LIKE ?";
    if($saleCustomerNameColumn!=='')$parts[]="s.`{$saleCustomerNameColumn}` LIKE ?";
    if($saleCustomerMobileColumn!=='')$parts[]="s.`{$saleCustomerMobileColumn}` LIKE ?";
    if($customerJoin!==''&&$customerNameColumn!=='')$parts[]="c.`{$customerNameColumn}` LIKE ?";
    if($customerJoin!==''&&$customerCodeColumn!=='')$parts[]="c.`{$customerCodeColumn}` LIKE ?";
    if($customerJoin!==''&&$customerMobileColumn!=='')$parts[]="c.`{$customerMobileColumn}` LIKE ?";

    if($parts){
        $where[]='('.implode(' OR ',$parts).')';
        foreach($parts as $_){
            $types.='s';
            $params[]=$like;
        }
    }
}

$whereSql=implode(' AND ',$where);

$sumColumns=[
    'subtotal','discount_amount','taxable_amount','cgst_amount','sgst_amount',
    'igst_amount','round_off','grand_total','paid_amount','balance_amount'
];

$summarySelect=["COUNT(*) AS total_bills"];
foreach($sumColumns as $column){
    $summarySelect[]=hasColumn($conn,'sales',$column)
        ?"COALESCE(SUM(s.`{$column}`),0) AS `{$column}`"
        :"0 AS `{$column}`";
}

$summarySql="SELECT ".implode(',',$summarySelect)."
             FROM sales s
             ".implode(' ',$joins)."
             WHERE {$whereSql}";

$stmt=$conn->prepare($summarySql);
if(!$stmt)respond(false,'Unable to prepare sales summary: '.$conn->error,[],500);
bindDynamic($stmt,$types,$params);
$stmt->execute();
$summary=$stmt->get_result()->fetch_assoc()?:[];
$stmt->close();

$select=[
    "s.`{$saleIdColumn}` AS id",
    $billNoColumn!==''?"s.`{$billNoColumn}` AS bill_no":"'' AS bill_no",
    "s.`{$billDateColumn}` AS bill_date",
    $billTimeColumn!==''?"s.`{$billTimeColumn}` AS bill_time":"NULL AS bill_time",
    $saleCustomerNameColumn!==''?"s.`{$saleCustomerNameColumn}` AS direct_customer_name":"'' AS direct_customer_name",
    $saleCustomerMobileColumn!==''?"s.`{$saleCustomerMobileColumn}` AS customer_mobile":"'' AS customer_mobile",
    $customerJoin!==''&&$customerNameColumn!==''?"c.`{$customerNameColumn}` AS master_customer_name":"'' AS master_customer_name",
    hasColumn($conn,'sales','bill_type')?"s.bill_type":"'—' AS bill_type",
    $methodJoin!==''?"pm.`{$paymentMethodNameColumn}` AS method_name":"'' AS method_name",
    hasColumn($conn,'sales','payment_status')?"s.payment_status":"'Unpaid' AS payment_status",
];

foreach($sumColumns as $column){
    $select[]=hasColumn($conn,'sales',$column)?"s.`{$column}`":"0 AS `{$column}`";
}

$listSql="SELECT ".implode(',',$select)."
          FROM sales s
          ".implode(' ',$joins)."
          WHERE {$whereSql}
          ORDER BY s.`{$billDateColumn}` DESC,s.`{$saleIdColumn}` DESC";

$stmt=$conn->prepare($listSql);
if(!$stmt)respond(false,'Unable to prepare sales list: '.$conn->error,[],500);
bindDynamic($stmt,$types,$params);
$stmt->execute();
$result=$stmt->get_result();
$rows=[];

while($result&&$row=$result->fetch_assoc()){
    $row['id']=(int)$row['id'];
    foreach($sumColumns as $column)$row[$column]=(float)($row[$column]??0);
    $row['customer_name']=(string)($row['master_customer_name']?:$row['direct_customer_name']?:'Walk-in Customer');
    $row['bill_date_display']=!empty($row['bill_date'])?date('d-m-Y',strtotime($row['bill_date'])):'—';
    $row['bill_time_display']=!empty($row['bill_time'])?date('h:i A',strtotime($row['bill_time'])):'';
    unset($row['master_customer_name'],$row['direct_customer_name']);
    $rows[]=$row;
}

$stmt->close();

if($action==='export'){
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sales_report_'.date('Y-m-d').'.csv"');
    $output=fopen('php://output','w');

    fputcsv($output,['Sales Report']);
    fputcsv($output,['Period',date('d-m-Y',strtotime($fromDate)).' to '.date('d-m-Y',strtotime($toDate))]);
    fputcsv($output,[]);
    fputcsv($output,[
        '#','Bill No','Date','Customer','Mobile','Bill Type','Method','Subtotal','Discount','Taxable',
        'CGST','SGST','IGST','Round Off','Grand Total','Paid','Balance','Status'
    ]);

    foreach($rows as $index=>$row){
        fputcsv($output,[
            $index+1,$row['bill_no'],$row['bill_date_display'],$row['customer_name'],$row['customer_mobile'],
            $row['bill_type'],$row['method_name'],$row['subtotal'],$row['discount_amount'],$row['taxable_amount'],
            $row['cgst_amount'],$row['sgst_amount'],$row['igst_amount'],$row['round_off'],$row['grand_total'],
            $row['paid_amount'],$row['balance_amount'],$row['payment_status']
        ]);
    }

    fputcsv($output,[]);
    fputcsv($output,['Total Bills',$summary['total_bills']??0]);
    fputcsv($output,['Grand Total',$summary['grand_total']??0]);
    fputcsv($output,['Paid Amount',$summary['paid_amount']??0]);
    fputcsv($output,['Balance Amount',$summary['balance_amount']??0]);

    fclose($output);
    exit;
}

respond(true,'Sales report loaded successfully.',[
    'period'=>[
        'from'=>$fromDate,
        'to'=>$toDate,
        'from_display'=>date('d-m-Y',strtotime($fromDate)),
        'to_display'=>date('d-m-Y',strtotime($toDate)),
    ],
    'summary'=>$summary,
    'rows'=>$rows,
]);
