<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));

function respond(bool $success,string $message,array $extra=[],int $status=200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        array_merge(['success'=>$success,'message'=>$message],$extra),
        JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES
    );
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
    $result=$conn->query(
        "SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'"
    );

    return $result&&$result->num_rows>0;
}

function firstColumn(mysqli $conn,string $table,array $columns): string
{
    foreach($columns as $column){
        if(hasColumn($conn,$table,$column)){
            return $column;
        }
    }

    return '';
}

if(empty($_SESSION['user_id'])){
    respond(false,'Your session has expired. Please log in again.',[],401);
}

$businessId=(int)($_SESSION['business_id']??0);
$branchId=(int)($_SESSION['branch_id']??$_SESSION['default_branch_id']??0);
$saleId=(int)($_GET['id']??0);

if($businessId<=0){
    respond(false,'A valid business must be selected.',[],403);
}

if($saleId<=0){
    respond(false,'Invalid sale selected.',[],422);
}

if(!tableExists($conn,'sales')||!tableExists($conn,'sale_items')){
    respond(false,'Required sales tables were not found.',[],500);
}

$company=[
    'company_name'=>'',
    'business_type'=>'',
    'owner_name'=>'',
    'mobile'=>'',
    'email'=>'',
    'address_line1'=>'',
    'address_line2'=>'',
    'city'=>'',
    'state'=>'',
    'pincode'=>'',
    'country'=>'India',
    'gstin'=>'',
    'pan_no'=>'',
    'logo_path'=>'',
];

foreach(['company_settings','businesses','business_details'] as $companyTable){
    if(!tableExists($conn,$companyTable)){
        continue;
    }

    $sql="SELECT * FROM `{$companyTable}` WHERE business_id=? LIMIT 1";
    $stmt=$conn->prepare($sql);

    if(!$stmt){
        continue;
    }

    $stmt->bind_param('i',$businessId);
    $stmt->execute();
    $row=$stmt->get_result()->fetch_assoc();
    $stmt->close();

    if($row){
        foreach($company as $key=>$default){
            if(array_key_exists($key,$row)){
                $company[$key]=(string)($row[$key]??'');
            }
        }

        break;
    }
}

$saleIdColumn=firstColumn($conn,'sales',['id','sale_id']);
$saleBusinessColumn=firstColumn($conn,'sales',['business_id']);
$saleBranchColumn=firstColumn($conn,'sales',['branch_id']);
$saleCustomerColumn=firstColumn($conn,'sales',['customer_id']);
$salePaymentMethodColumn=firstColumn($conn,'sales',['payment_method_id','method_id']);

if($saleIdColumn===''||$saleBusinessColumn===''){
    respond(false,'The sales table has no supported ID or business column.',[],500);
}

$customerTable=tableExists($conn,'customers');
$customerIdColumn=$customerTable?firstColumn($conn,'customers',['id','customer_id']):'';
$paymentTable=tableExists($conn,'payment_methods');
$paymentMethodIdColumn=$paymentTable?firstColumn($conn,'payment_methods',['payment_method_id','id','method_id']):'';
$paymentMethodNameColumn=$paymentTable?firstColumn($conn,'payment_methods',['payment_method_name','method_name','name']):'';

$joins=[];
$select=['s.*'];

if($customerTable&&$saleCustomerColumn!==''&&$customerIdColumn!==''){
    $joins[]="LEFT JOIN customers c
              ON c.`{$customerIdColumn}`=s.`{$saleCustomerColumn}`";

    foreach([
        'customer_code'=>['customer_code','code'],
        'customer_gstin'=>['gstin','gst_no'],
        'customer_email'=>['email'],
        'customer_address1'=>['address_line1','address1','address'],
        'customer_address2'=>['address_line2','address2'],
        'customer_city'=>['city'],
        'customer_state'=>['state'],
        'customer_pincode'=>['pincode','postal_code'],
        'master_customer_name'=>['customer_name','name'],
        'master_customer_mobile'=>['mobile','phone'],
    ] as $alias=>$columns){
        $column=firstColumn($conn,'customers',$columns);
        $select[]=$column!==''?"c.`{$column}` AS `{$alias}`":"'' AS `{$alias}`";
    }
}else{
    foreach([
        'customer_code','customer_gstin','customer_email',
        'customer_address1','customer_address2','customer_city',
        'customer_state','customer_pincode','master_customer_name',
        'master_customer_mobile'
    ] as $alias){
        $select[]="'' AS `{$alias}`";
    }
}

if(
    $paymentTable&&
    $salePaymentMethodColumn!==''&&
    $paymentMethodIdColumn!==''&&
    $paymentMethodNameColumn!==''
){
    $joins[]="LEFT JOIN payment_methods pm
              ON pm.`{$paymentMethodIdColumn}`=s.`{$salePaymentMethodColumn}`";

    $select[]="pm.`{$paymentMethodNameColumn}` AS method_name";
}else{
    $select[]="'' AS method_name";
}

$where=[
    "s.`{$saleIdColumn}`=?",
    "s.`{$saleBusinessColumn}`=?",
];

$types='ii';
$params=[$saleId,$businessId];

if($saleBranchColumn!==''&&$branchId>0){
    $where[]="s.`{$saleBranchColumn}`=?";
    $types.='i';
    $params[]=$branchId;
}

$sql="SELECT ".implode(',',$select)."
      FROM sales s
      ".implode(' ',$joins)."
      WHERE ".implode(' AND ',$where)."
      LIMIT 1";

$stmt=$conn->prepare($sql);

if(!$stmt){
    respond(false,'Unable to prepare sale query: '.$conn->error,[],500);
}

$stmt->bind_param($types,...$params);

if(!$stmt->execute()){
    $error=$stmt->error;
    $stmt->close();
    respond(false,'Unable to load sale: '.$error,[],500);
}

$sale=$stmt->get_result()->fetch_assoc();
$stmt->close();

if(!$sale){
    respond(false,'Sale was not found.',[],404);
}

$itemSaleColumn=firstColumn($conn,'sale_items',['sale_id']);
$itemBusinessColumn=firstColumn($conn,'sale_items',['business_id']);
$itemBranchColumn=firstColumn($conn,'sale_items',['branch_id']);
$itemIdColumn=firstColumn($conn,'sale_items',['id','sale_item_id']);

$items=[];

if($itemSaleColumn!==''&&$itemIdColumn!==''){
    $itemWhere=["`{$itemSaleColumn}`=?"];
    $itemTypes='i';
    $itemParams=[$saleId];

    if($itemBusinessColumn!==''){
        $itemWhere[]="`{$itemBusinessColumn}`=?";
        $itemTypes.='i';
        $itemParams[]=$businessId;
    }

    if($itemBranchColumn!==''&&$branchId>0){
        $itemWhere[]="`{$itemBranchColumn}`=?";
        $itemTypes.='i';
        $itemParams[]=$branchId;
    }

    $itemSql="SELECT *
              FROM sale_items
              WHERE ".implode(' AND ',$itemWhere)."
              ORDER BY `{$itemIdColumn}` ASC";

    $stmt=$conn->prepare($itemSql);

    if($stmt){
        $stmt->bind_param($itemTypes,...$itemParams);
        $stmt->execute();
        $result=$stmt->get_result();

        while($result&&$row=$result->fetch_assoc()){
            $items[]=$row;
        }

        $stmt->close();
    }
}

$payments=[];

if(tableExists($conn,'sale_payments')){
    $paymentSaleColumn=firstColumn($conn,'sale_payments',['sale_id']);
    $paymentId=firstColumn($conn,'sale_payments',['id','sale_payment_id']);
    $paymentMethodColumn=firstColumn($conn,'sale_payments',['payment_method_id','method_id']);
    $paymentBusinessColumn=firstColumn($conn,'sale_payments',['business_id']);
    $paymentBranchColumn=firstColumn($conn,'sale_payments',['branch_id']);

    if($paymentSaleColumn!==''&&$paymentId!==''){
        $paySelect=['sp.*'];
        $payJoins=[];

        if(
            $paymentTable&&
            $paymentMethodColumn!==''&&
            $paymentMethodIdColumn!==''&&
            $paymentMethodNameColumn!==''
        ){
            $payJoins[]="LEFT JOIN payment_methods pm
                         ON pm.`{$paymentMethodIdColumn}`=sp.`{$paymentMethodColumn}`";

            $paySelect[]="pm.`{$paymentMethodNameColumn}` AS method_name";
        }else{
            $paySelect[]="'' AS method_name";
        }

        $payWhere=["sp.`{$paymentSaleColumn}`=?"];
        $payTypes='i';
        $payParams=[$saleId];

        if($paymentBusinessColumn!==''){
            $payWhere[]="sp.`{$paymentBusinessColumn}`=?";
            $payTypes.='i';
            $payParams[]=$businessId;
        }

        if($paymentBranchColumn!==''&&$branchId>0){
            $payWhere[]="sp.`{$paymentBranchColumn}`=?";
            $payTypes.='i';
            $payParams[]=$branchId;
        }

        $paySql="SELECT ".implode(',',$paySelect)."
                 FROM sale_payments sp
                 ".implode(' ',$payJoins)."
                 WHERE ".implode(' AND ',$payWhere)."
                 ORDER BY sp.`{$paymentId}` ASC";

        $stmt=$conn->prepare($paySql);

        if($stmt){
            $stmt->bind_param($payTypes,...$payParams);
            $stmt->execute();
            $result=$stmt->get_result();

            while($result&&$row=$result->fetch_assoc()){
                $row['amount']=(float)($row['amount']??0);
                $row['created_at_display']=!empty($row['created_at'])
                    ? date('d-m-Y h:i A',strtotime($row['created_at']))
                    : '—';

                $payments[]=$row;
            }

            $stmt->close();
        }
    }
}

$itemCount=count($items);
$totalQty=0.0;
$totalGrossWeight=0.0;
$totalLessWeight=0.0;
$totalNetWeight=0.0;
$totalItemAmount=0.0;

foreach($items as &$item){
    foreach([
        'qty','gross_weight','less_weight','net_weight','rate_per_gram',
        'making_charge','taxable_amount','gst_amount','gst_percent','total_amount'
    ] as $column){
        $item[$column]=(float)($item[$column]??0);
    }

    $totalQty+=$item['qty'];
    $totalGrossWeight+=$item['gross_weight'];
    $totalLessWeight+=$item['less_weight'];
    $totalNetWeight+=$item['net_weight'];
    $totalItemAmount+=$item['total_amount'];
}
unset($item);

$customerName=(string)(
    $sale['master_customer_name']
    ?:($sale['customer_name']??'')
    ?: 'Walk-in Customer'
);

$customerMobile=(string)(
    $sale['master_customer_mobile']
    ?:($sale['customer_mobile']??'')
    ?:($sale['mobile']??'')
);

$customerAddress=trim(implode(' ',array_filter([
    (string)($sale['customer_address1']??''),
    (string)($sale['customer_address2']??''),
    (string)($sale['customer_city']??''),
    (string)($sale['customer_state']??''),
    (string)($sale['customer_pincode']??''),
])));

foreach([
    'subtotal','discount_amount','taxable_amount','cgst_amount','sgst_amount',
    'igst_amount','round_off','grand_total','paid_amount','balance_amount'
] as $column){
    $sale[$column]=(float)($sale[$column]??0);
}

$sale['customer_name']=$customerName;
$sale['customer_mobile']=$customerMobile;
$sale['customer_address']=$customerAddress;
$sale['bill_no']=(string)($sale['bill_no']??'');
$sale['bill_type']=(string)($sale['bill_type']??'');
$sale['payment_status']=(string)($sale['payment_status']??'Unpaid');
$sale['status']=(string)($sale['status']??'');
$sale['method_name']=(string)($sale['method_name']??'');
$sale['payment_reference']=(string)($sale['payment_reference']??'');
$sale['notes']=(string)($sale['notes']??'');
$sale['bill_date_display']=!empty($sale['bill_date'])
    ? date('d-m-Y',strtotime($sale['bill_date']))
    : '—';
$sale['bill_time_display']=!empty($sale['bill_time'])
    ? date('h:i A',strtotime($sale['bill_time']))
    : '—';

respond(true,'Sale loaded successfully.',[
    'company'=>$company,
    'sale'=>$sale,
    'items'=>$items,
    'payments'=>$payments,
    'totals'=>[
        'item_count'=>$itemCount,
        'total_qty'=>$totalQty,
        'total_gross_weight'=>$totalGrossWeight,
        'total_less_weight'=>$totalLessWeight,
        'total_net_weight'=>$totalNetWeight,
        'total_item_amount'=>$totalItemAmount,
        'total_paid'=>array_sum(array_column($payments,'amount')),
    ],
]);
