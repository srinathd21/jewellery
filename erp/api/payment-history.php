<?php
if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));
header('Content-Type: application/json; charset=utf-8');

foreach ([
    dirname(__DIR__) . '/config/config.php',
    dirname(__DIR__) . '/config.php',
    dirname(__DIR__) . '/includes/config.php',
    dirname(__DIR__) . '/super-admin/includes/config.php'
] as $file) {
    if (is_file($file)) {
        require_once $file;
        break;
    }
}

function out(bool $success, string $message, array $extra = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode(array_merge(['success'=>$success,'message'=>$message],$extra), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

function tableExists(mysqli $conn, string $table): bool
{
    $safe=$conn->real_escape_string($table);
    $result=$conn->query("SHOW TABLES LIKE '{$safe}'");
    return $result && $result->num_rows>0;
}

function queryAll(mysqli $conn, string $sql, string $types='', array $params=[]): array
{
    $stmt=$conn->prepare($sql);
    if(!$stmt) throw new RuntimeException($conn->error);
    if($types!==''){
        $bind=[$types];
        foreach($params as $i=>$value) $bind[]=&$params[$i];
        call_user_func_array([$stmt,'bind_param'],$bind);
    }
    if(!$stmt->execute()) throw new RuntimeException($stmt->error);
    $result=$stmt->get_result();
    $rows=[];
    while($row=$result->fetch_assoc()) $rows[]=$row;
    $stmt->close();
    return $rows;
}

if (!isset($conn) || !($conn instanceof mysqli)) out(false,'Database configuration is not available.',[],500);
$conn->set_charset('utf8mb4');
if (empty($_SESSION['user_id'])) out(false,'Session expired.',[],401);

$businessId=(int)($_SESSION['business_id']??0);
$branchId=(int)($_SESSION['branch_id']??($_SESSION['default_branch_id']??0));
if($businessId<=0) out(false,'A valid business must be selected.',[],403);

$action=(string)($_GET['action']??'list');

function resolvePeriod(): array
{
    $range=(string)($_GET['date_range']??'today');
    $today=new DateTime('today');
    if($range==='yesterday'){
        $from=(clone $today)->modify('-1 day');
        $to=clone $from;
    }elseif($range==='week'){
        $from=(clone $today)->modify('monday this week');
        $to=clone $today;
    }elseif($range==='month'){
        $from=new DateTime(date('Y-m-01'));
        $to=clone $today;
    }elseif($range==='custom'){
        $from=DateTime::createFromFormat('Y-m-d',(string)($_GET['from_date']??''));
        $to=DateTime::createFromFormat('Y-m-d',(string)($_GET['to_date']??''));
        if(!$from||!$to) throw new RuntimeException('Valid custom dates are required.');
    }else{
        $from=clone $today;
        $to=clone $today;
    }
    if($from>$to) throw new RuntimeException('From date cannot be after To date.');
    return [
        'from'=>$from->format('Y-m-d'),
        'to'=>$to->format('Y-m-d'),
        'from_display'=>$from->format('d-m-Y'),
        'to_display'=>$to->format('d-m-Y')
    ];
}

function salePaymentRows(mysqli $conn,int $businessId,int $branchId,array $period,int $customerId,int $saleId,string $search): array
{
    if(!tableExists($conn,'sale_payments')) return [];
    $sql="SELECT sp.id,sp.sale_id,sp.payment_date,sp.amount,sp.reference_no,
                 s.invoice_no,s.customer_id,s.customer_name,s.customer_mobile,
                 pm.method_name
          FROM sale_payments sp
          INNER JOIN sales s ON s.id=sp.sale_id AND s.business_id=sp.business_id
          LEFT JOIN payment_methods pm ON pm.id=sp.payment_method_id
          WHERE sp.business_id=?
            AND DATE(sp.payment_date) BETWEEN ? AND ?";
    $types='iss';
    $params=[$businessId,$period['from'],$period['to']];
    if($branchId>0){$sql.=" AND sp.branch_id=?";$types.='i';$params[]=$branchId;}
    if($customerId>0){$sql.=" AND s.customer_id=?";$types.='i';$params[]=$customerId;}
    if($saleId>0){$sql.=" AND s.id=?";$types.='i';$params[]=$saleId;}
    if($search!==''){
        $like='%'.$search.'%';
        $sql.=" AND (s.invoice_no LIKE ? OR s.customer_name LIKE ? OR s.customer_mobile LIKE ? OR sp.reference_no LIKE ? OR pm.method_name LIKE ?)";
        $types.='sssss';
        array_push($params,$like,$like,$like,$like,$like);
    }
    $sql.=" ORDER BY sp.payment_date,sp.id";
    $rows=queryAll($conn,$sql,$types,$params);
    return array_map(function($row){
        return [
            'date'=>substr((string)$row['payment_date'],0,10),
            'date_display'=>date('d-m-Y h:i A',strtotime($row['payment_date'])),
            'number'=>'PAY-'.$row['id'],
            'document_no'=>$row['invoice_no'],
            'sale_id'=>(int)$row['sale_id'],
            'party_name'=>$row['customer_name'],
            'party_code'=>'',
            'mobile'=>$row['customer_mobile'],
            'amount'=>(float)$row['amount'],
            'method'=>$row['method_name']?:'Unknown',
            'reference'=>$row['reference_no']?:'',
            'notes'=>'Invoice payment',
            'transaction_type'=>'Receipt'
        ];
    },$rows);
}

try{
    if($action==='bootstrap'){
        $customers=queryAll($conn,"SELECT id,customer_name name,customer_code code FROM customers WHERE business_id=? AND is_active=1 ORDER BY customer_name",'i',[$businessId]);
        $suppliers=tableExists($conn,'suppliers')?queryAll($conn,"SELECT id,supplier_name name,supplier_code code FROM suppliers WHERE business_id=? AND is_active=1 ORDER BY supplier_name",'i',[$businessId]):[];
        $invoices=queryAll($conn,"SELECT id,invoice_no,customer_name,net_payable_amount FROM sales WHERE business_id=? AND workflow_status<>'Cancelled' ORDER BY id DESC LIMIT 500",'i',[$businessId]);
        out(true,'Filters loaded.',['customers'=>$customers,'suppliers'=>$suppliers,'invoices'=>$invoices]);
    }

    if($action==='invoices'){
        $customerId=(int)($_GET['customer_id']??0);
        $sql="SELECT id,invoice_no,customer_name,net_payable_amount FROM sales WHERE business_id=? AND workflow_status<>'Cancelled'";
        $types='i';$params=[$businessId];
        if($customerId>0){$sql.=" AND customer_id=?";$types.='i';$params[]=$customerId;}
        $sql.=" ORDER BY id DESC LIMIT 500";
        out(true,'Invoices loaded.',['invoices'=>queryAll($conn,$sql,$types,$params)]);
    }

    $period=resolvePeriod();
    $customerId=(int)($_GET['customer_id']??0);
    $saleId=(int)($_GET['sale_id']??0);
    $supplierId=(int)($_GET['supplier_id']??0);
    $search=trim((string)($_GET['search']??''));

    $customerPayments=salePaymentRows($conn,$businessId,$branchId,$period,$customerId,$saleId,$search);

    $pawnPayments=[];
    $pawnInterest=[];
    $chitCollections=[];
    $supplierPayments=[];

    if(tableExists($conn,'chit_collections')){
        $sql="SELECT cc.id,cc.receipt_no,cc.collection_date,cc.net_amount,
                     cc.reference_no,cc.remarks,pm.method_name,
                     cg.group_no,cg.group_name,cm.ticket_no,
                     c.customer_name,c.customer_code,c.mobile
              FROM chit_collections cc
              INNER JOIN chit_members cm ON cm.id=cc.chit_member_id
              INNER JOIN customers c ON c.id=cm.customer_id
              INNER JOIN chit_groups cg ON cg.id=cc.chit_group_id
              LEFT JOIN payment_methods pm ON pm.id=cc.payment_method_id
              WHERE cc.business_id=? AND cc.collection_date BETWEEN ? AND ?";
        $types='iss';$params=[$businessId,$period['from'],$period['to']];
        if($customerId>0){$sql.=" AND c.id=?";$types.='i';$params[]=$customerId;}
        if($search!==''){$like='%'.$search.'%';$sql.=" AND (cc.receipt_no LIKE ? OR c.customer_name LIKE ? OR c.mobile LIKE ? OR cg.group_no LIKE ?)";$types.='ssss';array_push($params,$like,$like,$like,$like);}
        $sql.=" ORDER BY cc.collection_date,cc.id";
        foreach(queryAll($conn,$sql,$types,$params) as $r){
            $chitCollections[]=[
                'date_display'=>date('d-m-Y',strtotime($r['collection_date'])),
                'number'=>$r['receipt_no'],'reference_entity'=>$r['group_no'].' '.$r['group_name'],
                'party_code'=>$r['ticket_no'],'party_name'=>$r['customer_name'],
                'amount'=>(float)$r['net_amount'],'method'=>$r['method_name']?:'Unknown',
                'reference'=>$r['reference_no']?:'','notes'=>$r['remarks']?:''
            ];
        }
    }

    if(tableExists($conn,'supplier_payments')){
        $sql="SELECT sp.*,s.supplier_name,s.supplier_code,pm.method_name
              FROM supplier_payments sp
              INNER JOIN suppliers s ON s.id=sp.supplier_id
              LEFT JOIN payment_methods pm ON pm.id=sp.payment_method_id
              WHERE sp.business_id=? AND DATE(sp.payment_date) BETWEEN ? AND ?";
        $types='iss';$params=[$businessId,$period['from'],$period['to']];
        if($supplierId>0){$sql.=" AND s.id=?";$types.='i';$params[]=$supplierId;}
        if($search!==''){$like='%'.$search.'%';$sql.=" AND (sp.payment_no LIKE ? OR s.supplier_name LIKE ? OR sp.reference_no LIKE ?)";$types.='sss';array_push($params,$like,$like,$like);}
        $sql.=" ORDER BY sp.payment_date,sp.id";
        foreach(queryAll($conn,$sql,$types,$params) as $r){
            $supplierPayments[]=[
                'date_display'=>date('d-m-Y h:i A',strtotime($r['payment_date'])),
                'number'=>$r['payment_no']??('SP-'.$r['id']),'party_name'=>$r['supplier_name'],
                'party_code'=>$r['supplier_code'],'amount'=>(float)$r['amount'],
                'method'=>$r['method_name']?:'Unknown','reference'=>$r['reference_no']?:'',
                'notes'=>$r['remarks']??''
            ];
        }
    }

    $totalCustomer=array_sum(array_column($customerPayments,'amount'));
    $totalPawn=array_sum(array_column($pawnPayments,'amount'));
    $totalPawnInterest=array_sum(array_column($pawnInterest,'amount'));
    $totalChit=array_sum(array_column($chitCollections,'amount'));
    $totalSupplier=array_sum(array_column($supplierPayments,'amount'));
    $totalIncoming=$totalCustomer+$totalPawn+$totalPawnInterest+$totalChit;
    $totalOutgoing=$totalSupplier;
    $net=$totalIncoming-$totalOutgoing;

    $running=0.0;
    $statementRows=[];
    foreach($customerPayments as $r){
        $running+=(float)$r['amount'];
        $statementRows[]=[
            'date'=>$r['date'],'date_display'=>$r['date_display'],'sale_id'=>$r['sale_id'],
            'document_no'=>$r['document_no'],'transaction_type'=>'Customer Receipt',
            'party_name'=>$r['party_name'],'mobile'=>$r['mobile'],'method'=>$r['method'],
            'reference'=>$r['reference'],'debit'=>0,'credit'=>$r['amount'],'running_balance'=>$running
        ];
    }

    $partyLabel='All Customers';
    if($saleId>0){
        $rows=queryAll($conn,"SELECT invoice_no,customer_name FROM sales WHERE id=? AND business_id=? LIMIT 1",'ii',[$saleId,$businessId]);
        if($rows)$partyLabel=$rows[0]['customer_name'].' · '.$rows[0]['invoice_no'];
    }elseif($customerId>0){
        $rows=queryAll($conn,"SELECT customer_name FROM customers WHERE id=? AND business_id=? LIMIT 1",'ii',[$customerId,$businessId]);
        if($rows)$partyLabel=$rows[0]['customer_name'];
    }

    $result=[
        'period'=>$period,
        'summary'=>['total_incoming'=>$totalIncoming,'total_outgoing'=>$totalOutgoing,'net_balance'=>$net],
        'totals'=>['customer'=>$totalCustomer,'pawn'=>$totalPawn,'pawn_interest'=>$totalPawnInterest,'chit'=>$totalChit,'supplier'=>$totalSupplier],
        'customer_payments'=>$customerPayments,
        'pawn_payments'=>$pawnPayments,
        'pawn_interest'=>$pawnInterest,
        'chit_collections'=>$chitCollections,
        'supplier_payments'=>$supplierPayments,
        'statement_rows'=>$statementRows,
        'statement'=>['party_label'=>$partyLabel,'opening_balance'=>0,'closing_balance'=>$running]
    ];

    if($action==='export' && (string)($_GET['format']??'')==='excel'){
        header_remove('Content-Type');
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="payment-statement-'.date('Ymd-His').'.xls"');
        echo "<html><head><meta charset=\"utf-8\"></head><body>";
        echo "<h2>Payment Statement</h2>";
        echo "<p>".htmlspecialchars($partyLabel)." | ".$period['from_display']." to ".$period['to_display']."</p>";
        echo "<table border=\"1\"><tr><th>Date</th><th>Document</th><th>Customer</th><th>Method</th><th>Reference</th><th>Debit</th><th>Credit</th><th>Balance</th></tr>";
        foreach($statementRows as $r){
            echo "<tr><td>".htmlspecialchars($r['date_display'])."</td><td>".htmlspecialchars($r['document_no'])."</td><td>".htmlspecialchars($r['party_name'])."</td><td>".htmlspecialchars($r['method'])."</td><td>".htmlspecialchars($r['reference'])."</td><td>".number_format($r['debit'],2,'.','')."</td><td>".number_format($r['credit'],2,'.','')."</td><td>".number_format($r['running_balance'],2,'.','')."</td></tr>";
        }
        echo "</table></body></html>";
        exit;
    }

    out(true,'Payment history loaded.',$result);
}catch(Throwable $e){
    out(false,$e->getMessage(),[],422);
}
