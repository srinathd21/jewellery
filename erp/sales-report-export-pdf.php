<?php
if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));
mysqli_report(MYSQLI_REPORT_OFF);

foreach ([__DIR__.'/config/config.php',__DIR__.'/config.php',__DIR__.'/includes/config.php',__DIR__.'/super-admin/includes/config.php'] as $f) {
    if (is_file($f)) { require_once $f; break; }
}
if (!isset($conn) || !($conn instanceof mysqli)) die('Database configuration is not available.');
$conn->set_charset('utf8mb4');
if (empty($_SESSION['user_id'])) die('Session expired.');

foreach ([__DIR__.'/vendor/autoload.php',__DIR__.'/fpdf/fpdf.php',__DIR__.'/includes/fpdf/fpdf.php',__DIR__.'/libs/fpdf/fpdf.php'] as $f) {
    if (is_file($f)) { require_once $f; break; }
}
if (!class_exists('FPDF')) die('FPDF library not found.');

function srTableExists(mysqli $conn, $table){
    $safe=$conn->real_escape_string((string)$table);
    $r=$conn->query("SHOW TABLES LIKE '{$safe}'");
    return $r && $r->num_rows>0;
}
function srBind(mysqli_stmt $stmt,$types,array &$params){
    if($types==='') return;
    $a=[$types]; foreach($params as $k=>$v) $a[]=&$params[$k];
    call_user_func_array([$stmt,'bind_param'],$a);
}
function srValidDate($value){
    $d=DateTime::createFromFormat('Y-m-d',(string)$value);
    return $d && $d->format('Y-m-d')===$value;
}
function srTxt($v){
    $v=str_replace(['₹','–','—'],['Rs. ','-','-'],(string)($v??''));
    $x=@iconv('UTF-8','windows-1252//TRANSLIT//IGNORE',$v);
    return $x!==false?$x:$v;
}
function srMoney($v){ return number_format((float)$v,2,'.',','); }

$businessId=(int)($_SESSION['business_id']??0);
$branchId=(int)($_SESSION['branch_id']??($_SESSION['default_branch_id']??0));
if($businessId<=0) die('A valid business must be selected.');
if(!srTableExists($conn,'sales')) die('Required sales table was not found.');

$fromDate=trim((string)($_GET['from_date']??date('Y-m-01')));
$toDate=trim((string)($_GET['to_date']??date('Y-m-d')));
$customerId=(int)($_GET['customer_id']??0);
$billType=trim((string)($_GET['bill_type']??''));
$paymentStatus=trim((string)($_GET['payment_status']??''));
$search=trim((string)($_GET['search']??''));
if(!srValidDate($fromDate)) $fromDate=date('Y-m-01');
if(!srValidDate($toDate)) $toDate=date('Y-m-d');
if($fromDate>$toDate){$t=$fromDate;$fromDate=$toDate;$toDate=$t;}

$where=" WHERE s.business_id=? AND COALESCE(s.workflow_status,'Posted') <> 'Cancelled'";
$types='i'; $params=[$businessId];
if($branchId>0){$where.=' AND s.branch_id=?';$types.='i';$params[]=$branchId;}
$where.=' AND s.invoice_date>=? AND s.invoice_date<=?';$types.='ss';$params[]=$fromDate;$params[]=$toDate;
if($customerId>0){$where.=' AND s.customer_id=?';$types.='i';$params[]=$customerId;}
if($billType!==''){$where.=' AND s.bill_type=?';$types.='s';$params[]=$billType;}
if(in_array($paymentStatus,['Unpaid','Partial','Paid'],true)){$where.=' AND s.payment_status=?';$types.='s';$params[]=$paymentStatus;}
if($search!==''){
    $like='%'.$search.'%';
    $where.=" AND (s.invoice_no LIKE ? OR COALESCE(s.customer_name,'') LIKE ? OR COALESCE(s.customer_mobile,'') LIKE ?)";
    $types.='sss';$params[]=$like;$params[]=$like;$params[]=$like;
}

$methodSelect="'' AS method_name";
if(srTableExists($conn,'sale_payments') && srTableExists($conn,'payment_methods')){
    $methodSelect="COALESCE((SELECT GROUP_CONCAT(DISTINCT pm.method_name ORDER BY sp.id SEPARATOR ', ') FROM sale_payments sp INNER JOIN payment_methods pm ON pm.id=sp.payment_method_id AND pm.business_id=sp.business_id WHERE sp.sale_id=s.id AND sp.business_id=s.business_id),'') AS method_name";
}
$sql="SELECT s.id,s.invoice_no AS bill_no,s.invoice_date AS bill_date,s.invoice_time AS bill_time,s.customer_name,s.customer_mobile,s.bill_type,s.subtotal,s.discount_amount,s.taxable_amount,s.cgst_amount,s.sgst_amount,s.igst_amount,s.round_off,s.grand_total,s.paid_amount,s.balance_amount,s.payment_status,{$methodSelect} FROM sales s {$where} ORDER BY s.invoice_date DESC,s.invoice_time DESC,s.id DESC";
$stmt=$conn->prepare($sql); if(!$stmt) die('Unable to prepare sales report: '.$conn->error);
$p=$params; srBind($stmt,$types,$p); $stmt->execute(); $r=$stmt->get_result(); $rows=[];
while($x=$r->fetch_assoc()) $rows[]=$x; $stmt->close();

$businessName=(string)($_SESSION['business_name']??'Sales Report');
$branchName=(string)($_SESSION['branch_name']??'');

class SalesReportPDF extends FPDF{
    public $titleText='Sales Report'; public $business=''; public $branch=''; public $period='';
    function Header(){
        $this->SetFont('Arial','B',14); $this->SetTextColor(45,45,45); $this->Cell(0,7,srTxt($this->business),0,1,'C');
        if($this->branch!==''){ $this->SetFont('Arial','B',7); $this->Cell(0,4,srTxt($this->branch),0,1,'C'); }
        $this->Ln(1); $this->SetFillColor(123,31,58); $this->SetTextColor(255); $this->SetFont('Arial','B',10); $this->Cell(0,7,srTxt($this->titleText),0,1,'C',true);
        $this->SetTextColor(60); $this->SetFont('Arial','',6.5); $this->Cell(0,5,srTxt($this->period.'   Generated: '.date('d-m-Y h:i A')),0,1,'R');
        $this->Ln(1);
    }
    function Footer(){ $this->SetY(-8); $this->SetFont('Arial','',6); $this->SetTextColor(100); $this->Cell(0,4,'Page '.$this->PageNo(),0,0,'C'); }
}

$pdf=new SalesReportPDF('L','mm','A4');
$pdf->SetMargins(6,6,6); $pdf->SetAutoPageBreak(true,12);
$pdf->business=$businessName; $pdf->branch=$branchName; $pdf->period='Period: '.date('d-m-Y',strtotime($fromDate)).' to '.date('d-m-Y',strtotime($toDate));
$pdf->AddPage();

$heads=['#','Bill No','Date','Customer','Mobile','Type','Method','Subtotal','Disc.','Taxable','CGST','SGST','IGST','Round','Grand','Paid','Balance','Status'];
$ws=[7,20,15,27,19,14,20,17,14,17,12,12,12,12,18,17,18,13]; // total 285 mm
function srTableX($pdf,$ws){
    $tableWidth=array_sum($ws);
    return ($pdf->GetPageWidth()-$tableWidth)/2;
}
function srDrawHead($pdf,$heads,$ws){
    $pdf->SetX(srTableX($pdf,$ws));
    $pdf->SetFillColor(123,31,58);$pdf->SetTextColor(255);$pdf->SetDrawColor(210,185,145);$pdf->SetFont('Arial','B',5.3);
    foreach($heads as $i=>$h)$pdf->Cell($ws[$i],7,srTxt($h),1,0,'C',true);
    $pdf->Ln();$pdf->SetTextColor(35);$pdf->SetFont('Arial','',5.1);
}
srDrawHead($pdf,$heads,$ws);
$tot=['subtotal'=>0,'discount_amount'=>0,'taxable_amount'=>0,'cgst_amount'=>0,'sgst_amount'=>0,'igst_amount'=>0,'round_off'=>0,'grand_total'=>0,'paid_amount'=>0,'balance_amount'=>0];
foreach($rows as $i=>$row){
    if($pdf->GetY()>190){$pdf->AddPage();srDrawHead($pdf,$heads,$ws);}
    foreach($tot as $k=>$v)$tot[$k]+=(float)($row[$k]??0);
    $date=!empty($row['bill_date'])?date('d-m-Y',strtotime($row['bill_date'])):'';
    $vals=[
        $i+1,$row['bill_no']??'', $date, $row['customer_name']?:'Walk-in Customer',$row['customer_mobile']??'', $row['bill_type']??'', $row['method_name']??'',
        srMoney($row['subtotal']??0),srMoney($row['discount_amount']??0),srMoney($row['taxable_amount']??0),srMoney($row['cgst_amount']??0),srMoney($row['sgst_amount']??0),srMoney($row['igst_amount']??0),srMoney($row['round_off']??0),srMoney($row['grand_total']??0),srMoney($row['paid_amount']??0),srMoney($row['balance_amount']??0),$row['payment_status']??''
    ];
    $pdf->SetX(srTableX($pdf,$ws));
    foreach($vals as $c=>$v){
        $align=in_array($c,[0,2,17],true)?'C':(($c>=7&&$c<=16)?'R':'L');
        $text=(string)$v;
        if(strlen($text)>30 && in_array($c,[3,6],true)) $text=substr($text,0,27).'...';
        $pdf->Cell($ws[$c],6,srTxt($text),1,0,$align);
    }
    $pdf->Ln();
}

$pdf->Ln(2);
$summaryTotalWidth=285;
$pdf->SetX(($pdf->GetPageWidth()-$summaryTotalWidth)/2);
$pdf->SetFillColor(248,236,208);$pdf->SetTextColor(80,17,38);$pdf->SetFont('Arial','B',6.2);$pdf->SetDrawColor(210,185,145);
$summary=[['Bills',count($rows)],['Grand','Rs. '.srMoney($tot['grand_total'])],['Paid','Rs. '.srMoney($tot['paid_amount'])],['Balance','Rs. '.srMoney($tot['balance_amount'])],['GST','Rs. '.srMoney($tot['cgst_amount']+$tot['sgst_amount']+$tot['igst_amount'])]];
$sw=[20,37,20,37,20,37,20,37,20,37]; // total 285 mm, same as table/header width
$si=0;
foreach($summary as $pair){$pdf->Cell($sw[$si++],7,srTxt($pair[0]),1,0,'L',true);$pdf->Cell($sw[$si++],7,srTxt($pair[1]),1,0,'R',true);} $pdf->Ln();

$file='sales-report-'.date('Ymd-His').'.pdf';
$pdf->Output(isset($_GET['download'])&&$_GET['download']==='1'?'D':'I',$file);