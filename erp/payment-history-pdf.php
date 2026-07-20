<?php
if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));

foreach ([__DIR__.'/config/config.php',__DIR__.'/config.php',__DIR__.'/includes/config.php',__DIR__.'/super-admin/includes/config.php'] as $file) {
    if (is_file($file)) { require_once $file; break; }
}
if (!isset($conn) || !($conn instanceof mysqli)) die('Database configuration is not available.');
if (empty($_SESSION['user_id'])) die('Session expired.');

$fpdfLoaded=false;
foreach ([__DIR__.'/vendor/autoload.php',__DIR__.'/fpdf/fpdf.php',__DIR__.'/includes/fpdf/fpdf.php',__DIR__.'/libs/fpdf/fpdf.php'] as $file) {
    if (is_file($file)) { require_once $file; $fpdfLoaded=class_exists('FPDF'); if($fpdfLoaded) break; }
}
if(!$fpdfLoaded) die('FPDF library is not available.');

function qAll(mysqli $conn,string $sql,string $types='',array $params=[]):array{
    $stmt=$conn->prepare($sql); if(!$stmt) throw new RuntimeException($conn->error);
    if($types!==''){ $bind=[$types]; foreach($params as $i=>$v)$bind[]=&$params[$i]; call_user_func_array([$stmt,'bind_param'],$bind); }
    $stmt->execute(); $result=$stmt->get_result(); $rows=[]; while($r=$result->fetch_assoc())$rows[]=$r; $stmt->close(); return $rows;
}
function cleanText($value):string{
    $text=(string)$value;
    $converted=@iconv('UTF-8','windows-1252//TRANSLIT//IGNORE',$text);
    return $converted!==false?$converted:preg_replace('/[^\x20-\x7E]/','',$text);
}

$businessId=(int)($_SESSION['business_id']??0);
$branchId=(int)($_SESSION['branch_id']??($_SESSION['default_branch_id']??0));
$customerId=(int)($_GET['customer_id']??0);
$saleId=(int)($_GET['sale_id']??0);
$range=(string)($_GET['date_range']??'today');
$today=new DateTime('today');
if($range==='yesterday'){$from=(clone $today)->modify('-1 day');$to=clone $from;}
elseif($range==='week'){$from=(clone $today)->modify('monday this week');$to=clone $today;}
elseif($range==='month'){$from=new DateTime(date('Y-m-01'));$to=clone $today;}
elseif($range==='custom'){$from=DateTime::createFromFormat('Y-m-d',(string)($_GET['from_date']??''));$to=DateTime::createFromFormat('Y-m-d',(string)($_GET['to_date']??''));}
else{$from=clone $today;$to=clone $today;}
if(!$from||!$to)die('Invalid period.');

$sql="SELECT sp.id,sp.sale_id,sp.payment_date,sp.amount,sp.reference_no,s.invoice_no,s.customer_id,s.customer_name,s.customer_mobile,pm.method_name
      FROM sale_payments sp INNER JOIN sales s ON s.id=sp.sale_id AND s.business_id=sp.business_id
      LEFT JOIN payment_methods pm ON pm.id=sp.payment_method_id
      WHERE sp.business_id=? AND DATE(sp.payment_date) BETWEEN ? AND ?";
$types='iss';$params=[$businessId,$from->format('Y-m-d'),$to->format('Y-m-d')];
if($branchId>0){$sql.=" AND sp.branch_id=?";$types.='i';$params[]=$branchId;}
if($customerId>0){$sql.=" AND s.customer_id=?";$types.='i';$params[]=$customerId;}
if($saleId>0){$sql.=" AND s.id=?";$types.='i';$params[]=$saleId;}
$sql.=" ORDER BY sp.payment_date,sp.id";
$rows=qAll($conn,$sql,$types,$params);

$businessName=(string)($_SESSION['business_name']??'Business');
$party='All Customers';
if($saleId>0){$x=qAll($conn,"SELECT invoice_no,customer_name FROM sales WHERE id=? AND business_id=? LIMIT 1",'ii',[$saleId,$businessId]);if($x)$party=$x[0]['customer_name'].' - '.$x[0]['invoice_no'];}
elseif($customerId>0){$x=qAll($conn,"SELECT customer_name FROM customers WHERE id=? AND business_id=? LIMIT 1",'ii',[$customerId,$businessId]);if($x)$party=$x[0]['customer_name'];}

$pdf=new FPDF('L','mm','A4');
$pdf->SetMargins(10,10,10);
$pdf->AddPage();
$pdf->SetFont('Arial','B',16);
$pdf->Cell(0,8,cleanText($businessName),0,1,'C');
$pdf->SetFont('Arial','B',13);
$pdf->Cell(0,7,'PAYMENT STATEMENT',0,1,'C');
$pdf->SetFont('Arial','',9);
$pdf->Cell(0,6,cleanText($party.' | '.$from->format('d-m-Y').' to '.$to->format('d-m-Y')),0,1,'C');
$pdf->Ln(3);

$widths=[28,38,48,35,55,25,25];
$headers=['Date','Invoice','Customer','Method','Reference','Credit','Balance'];
$pdf->SetFont('Arial','B',8);
foreach($headers as $i=>$h)$pdf->Cell($widths[$i],7,$h,1,0,'C');
$pdf->Ln();

$pdf->SetFont('Arial','',8);
$running=0.0;$total=0.0;
foreach($rows as $r){
    $running+=(float)$r['amount'];$total+=(float)$r['amount'];
    $values=[
        date('d-m-Y',strtotime($r['payment_date'])),
        $r['invoice_no'],
        $r['customer_name'],
        $r['method_name']?:'Unknown',
        $r['reference_no']?:'-',
        number_format((float)$r['amount'],2),
        number_format($running,2)
    ];
    foreach($values as $i=>$v)$pdf->Cell($widths[$i],7,cleanText($v),1,0,$i>=5?'R':'L');
    $pdf->Ln();
}
$pdf->SetFont('Arial','B',9);
$pdf->Cell(array_sum(array_slice($widths,0,5)),8,'Total Received',1,0,'R');
$pdf->Cell($widths[5],8,number_format($total,2),1,0,'R');
$pdf->Cell($widths[6],8,number_format($running,2),1,1,'R');

$inline=(int)($_GET['inline']??0)===1;
$pdf->Output($inline?'I':'D','payment-statement-'.date('Ymd-His').'.pdf');
