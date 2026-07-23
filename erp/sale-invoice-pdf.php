<?php
if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set($_SESSION['timezone'] ?? 'Asia/Kolkata');
foreach ([__DIR__.'/config/config.php',__DIR__.'/config.php',__DIR__.'/includes/config.php',__DIR__.'/super-admin/includes/config.php'] as $f) { if (is_file($f)) { require_once $f; break; } }
if (!isset($conn) || !($conn instanceof mysqli)) die('Database configuration is not available.');
$conn->set_charset('utf8mb4');
if (empty($_SESSION['user_id'])) die('Session expired.');
foreach ([__DIR__.'/vendor/autoload.php',__DIR__.'/fpdf/fpdf.php',__DIR__.'/includes/fpdf/fpdf.php',__DIR__.'/libs/fpdf/fpdf.php'] as $f) { if (is_file($f)) { require_once $f; break; } }
if (!class_exists('FPDF')) die('FPDF library not found.');

function allRows(mysqli $c,string $sql,string $types='',array $params=[]):array{
    $s=$c->prepare($sql); if(!$s) throw new RuntimeException($c->error);
    if($types!==''){ $a=[$types]; foreach($params as $k=>$v) $a[]=&$params[$k]; call_user_func_array([$s,'bind_param'],$a); }
    if(!$s->execute()) throw new RuntimeException($s->error);
    $r=$s->get_result(); $o=[]; while($x=$r->fetch_assoc()) $o[]=$x; $s->close(); return $o;
}
function txt($v):string{ $v=str_replace(['₹','–','—','•'],['Rs. ','-','-','-'],(string)($v??'')); $x=@iconv('UTF-8','windows-1252//TRANSLIT//IGNORE',$v); return $x!==false?$x:$v; }
function amountWords(int $n):string{
    if($n<=0) return 'Rupees Zero Only';
    $o=['','One','Two','Three','Four','Five','Six','Seven','Eight','Nine','Ten','Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen','Seventeen','Eighteen','Nineteen'];
    $t=['','','Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];
    $small=function($v)use($o,$t){$w='';if($v>=100){$w=$o[intdiv($v,100)].' Hundred';$v%=100;if($v)$w.=' ';}if($v)$w.=$v<20?$o[$v]:$t[intdiv($v,10)].($v%10?' '.$o[$v%10]:'');return trim($w);};
    $p=[]; foreach([[10000000,'Crore'],[100000,'Lakh'],[1000,'Thousand']] as [$d,$l]){$q=intdiv($n,$d);if($q){$p[]=$small($q).' '.$l;$n%=$d;}} if($n)$p[]=$small($n); return 'Rupees '.implode(' ',$p).' Only';
}

$businessId=(int)($_SESSION['business_id']??0); $saleId=(int)($_GET['sale_id']??0);
if($businessId<=0||$saleId<=0) die('Invalid sale.');
try{
    $saleRows=allRows($conn,"SELECT s.*,c.customer_code,c.email,c.gstin customer_gstin,c.address_line1,c.address_line2,c.city,c.state,c.pincode,b.business_name,b.legal_name,b.mobile business_mobile,b.email business_email,b.website,b.gstin business_gstin,b.pan_no,br.branch_name,br.mobile branch_mobile,br.email branch_email,br.address_line1 branch_address1,br.address_line2 branch_address2,br.city branch_city,br.state branch_state,br.pincode branch_pincode,br.gstin branch_gstin FROM sales s LEFT JOIN customers c ON c.id=s.customer_id LEFT JOIN businesses b ON b.id=s.business_id LEFT JOIN branches br ON br.id=s.branch_id WHERE s.id=? AND s.business_id=? LIMIT 1",'ii',[$saleId,$businessId]);
    if(!$saleRows) die('Sale not found.'); $s=$saleRows[0];
    $items=allRows($conn,"SELECT si.*,p.product_code,p.hsn_code product_hsn FROM sale_items si LEFT JOIN products p ON p.id=si.product_id WHERE si.sale_id=? AND si.business_id=? ORDER BY si.sort_order,si.id",'ii',[$saleId,$businessId]);
    $pays=allRows($conn,"SELECT sp.*,pm.method_name FROM sale_payments sp LEFT JOIN payment_methods pm ON pm.id=sp.payment_method_id WHERE sp.sale_id=? AND sp.business_id=? ORDER BY sp.id",'ii',[$saleId,$businessId]);
    $claims=allRows($conn,"SELECT sc.*,cg.group_name,cg.group_no,cm.ticket_no FROM sales_chit_claims sc LEFT JOIN chit_groups cg ON cg.id=sc.chit_group_id LEFT JOIN chit_members cm ON cm.id=sc.chit_member_id WHERE sc.sale_id=? AND sc.business_id=? AND sc.status='Posted'",'ii',[$saleId,$businessId]);
    $ex=allRows($conn,"SELECT * FROM sale_exchange_items WHERE sale_id=? AND business_id=? ORDER BY id",'ii',[$saleId,$businessId]);
    $settings=allRows($conn,"SELECT * FROM invoice_settings WHERE business_id=? AND (branch_id=? OR branch_id IS NULL) AND document_type='Invoice' AND is_active=1 ORDER BY (branch_id=?) DESC,is_default DESC,id DESC LIMIT 1",'iii',[$businessId,(int)$s['branch_id'],(int)$s['branch_id']]);
    $set=$settings[0]??[];
}catch(Throwable $e){ die('Unable to build invoice: '.htmlspecialchars($e->getMessage())); }

class InvoicePDF extends FPDF{
    public $footerText='';
    public $watermark='';
    public $watermarkLogo='';
    protected $extgstates=[];

    function SetAlpha($alpha,$blendMode='Normal'){
        $alpha=max(0,min(1,(float)$alpha));
        $this->extgstates[]=[
            'ca'=>$alpha,
            'CA'=>$alpha,
            'BM'=>'/'.$blendMode
        ];
        $this->SetExtGState(count($this->extgstates));
    }

    function SetExtGState($stateNumber){
        $this->_out(sprintf('/GS%d gs',$stateNumber));
    }

    function _enddoc(){
        if(!empty($this->extgstates) && version_compare($this->PDFVersion,'1.4','<')){
            $this->PDFVersion='1.4';
        }
        parent::_enddoc();
    }

    function _putextgstates(){
        foreach($this->extgstates as $index=>$state){
            $this->_newobj();
            $this->extgstates[$index]['n']=$this->n;
            $this->_put('<</Type /ExtGState');
            $this->_put(sprintf('/ca %.3F',$state['ca']));
            $this->_put(sprintf('/CA %.3F',$state['CA']));
            $this->_put('/BM '.$state['BM']);
            $this->_put('>>');
            $this->_put('endobj');
        }
    }

    function _putresourcedict(){
        parent::_putresourcedict();
        if(!empty($this->extgstates)){
            $this->_put('/ExtGState <<');
            foreach($this->extgstates as $index=>$state){
                $this->_put('/GS'.($index+1).' '.$state['n'].' 0 R');
            }
            $this->_put('>>');
        }
    }

    function _putresources(){
        $this->_putextgstates();
        parent::_putresources();
    }

    function Header(){
        if($this->watermarkLogo!=='' && is_file($this->watermarkLogo)){
            $maxWidth=82;
            $maxHeight=82;
            $imageWidth=$maxWidth;
            $imageHeight=$maxHeight;
            $imageInfo=@getimagesize($this->watermarkLogo);

            if(is_array($imageInfo) && !empty($imageInfo[0]) && !empty($imageInfo[1])){
                $ratio=$imageInfo[0]/$imageInfo[1];
                if($ratio>=1){
                    $imageWidth=$maxWidth;
                    $imageHeight=$maxWidth/$ratio;
                }else{
                    $imageHeight=$maxHeight;
                    $imageWidth=$maxHeight*$ratio;
                }
            }

            $x=($this->GetPageWidth()-$imageWidth)/2;
            $y=($this->GetPageHeight()-$imageHeight)/2;

            $this->SetAlpha(0.075);
            $this->Image($this->watermarkLogo,$x,$y,$imageWidth,$imageHeight);
            $this->SetAlpha(1);
        }elseif($this->watermark!==''){
            $this->SetFont('Arial','B',28);
            $this->SetTextColor(248,239,242);
            $this->SetXY(16,122);
            $this->Cell(178,15,txt($this->watermark),0,0,'C');
        }
    }

    function Footer(){
        $this->SetY(-11);
        $this->SetFont('Arial','',6);
        $this->SetTextColor(105);
        if($this->footerText!==''){
            $this->MultiCell(0,3,txt($this->footerText),0,'C');
        }
        $this->SetY(-5);
        $this->Cell(0,3,'Page '.$this->PageNo(),0,0,'C');
    }

    function section($x,$y,$w,$title){
        $this->SetXY($x,$y);
        $this->SetFillColor(123,31,58);
        $this->SetTextColor(255);
        $this->SetFont('Arial','B',7.5);
        $this->Cell($w,6,txt($title),1,1,'L',true);
        $this->SetTextColor(36);
    }

    function info($x,$y,$w,$label,$value,$lw=29){
        $this->SetXY($x,$y);
        $this->SetFont('Arial','B',7);
        $this->Cell($lw,4.3,txt($label),0,0);
        $this->SetFont('Arial','',7);
        $this->MultiCell($w-$lw,4.3,txt(': '.$value),0,'L');
        return max($y+4.3,$this->GetY());
    }

    function need($h){
        if($this->GetY()+$h>$this->GetPageHeight()-17){
            $this->AddPage();
            $this->SetY(14);
        }
    }
}

$logo=(string)($set['invoice_logo_path']??'');
$logoFile=$logo!==''?__DIR__.'/'.ltrim($logo,'/'):'';

$pdf=new InvoicePDF('P','mm','A4');
$pdf->SetMargins(8,8,8);
$pdf->SetAutoPageBreak(true,17);
$pdf->footerText=(string)($set['footer_text']??'This is a computer-generated invoice.');
$pdf->watermark=strtoupper((string)($s['business_name']??'JEWELLERY'));
$pdf->watermarkLogo=is_file($logoFile)?$logoFile:'';
$pdf->AddPage();
$P=[123,31,58];$D=[80,17,38];$G=[200,148,36];$GS=[248,236,208];$B=[216,201,172];$W=194;

if(!empty($set['show_business_logo'])&&is_file($logoFile)){$pdf->Image($logoFile,8,8,23,23);}else{$pdf->SetXY(8,8);$pdf->SetFillColor(...$GS);$pdf->SetDrawColor(...$G);$pdf->SetTextColor(...$P);$pdf->SetFont('Arial','B',12);$initials=strtoupper(substr(preg_replace('/[^A-Za-z]/','',(string)($s['business_name']??'JW')),0,3));$pdf->Cell(23,23,txt($initials?:'JW'),1,0,'C',true);}
$name=$s['business_name']?:$s['legal_name']?:'Jewellery Business';
$address=trim(implode(', ',array_filter([$s['branch_address1'],$s['branch_address2'],$s['branch_city'],$s['branch_state'],$s['branch_pincode']])));
$contact=trim(implode(' | ',array_filter([$s['branch_mobile']?:$s['business_mobile'],$s['branch_email']?:$s['business_email'],$s['website']])));
$gst=$s['branch_gstin']?:$s['business_gstin'];
$pdf->SetXY(34,8);$pdf->SetTextColor(...$P);$pdf->SetFont('Arial','B',16);$pdf->Cell(116,7,txt(strtoupper($name)),0,1,'C');
$pdf->SetX(34);$pdf->SetTextColor(...$G);$pdf->SetFont('Arial','B',7);$pdf->Cell(116,4,txt('GOLD - SILVER - DIAMOND - PRECIOUS JEWELLERY'),0,1,'C');
$pdf->SetX(34);$pdf->SetTextColor(68);$pdf->SetFont('Arial','',6.5);if($address!=='')$pdf->MultiCell(116,3.4,txt($address),0,'C');$pdf->SetX(34);if($contact!=='')$pdf->MultiCell(116,3.4,txt($contact),0,'C');$pdf->SetX(34);if(!empty($set['show_gstin'])&&$gst)$pdf->Cell(116,3.4,txt('GSTIN: '.$gst),0,1,'C');
$pdf->SetXY(153,8);$pdf->SetTextColor(...$P);$pdf->SetFont('Arial','B',11);$pdf->Cell(49,7,txt($set['header_text']??'TAX INVOICE'),0,1,'R');$pdf->SetX(153);$pdf->SetFillColor(...$GS);$pdf->SetDrawColor(...$G);$pdf->SetFont('Arial','B',6);$pdf->Cell(49,6,txt('ORIGINAL FOR RECIPIENT'),1,1,'C',true);
$pdf->SetDrawColor(...$P);$pdf->SetLineWidth(.8);$pdf->Line(8,34,202,34);$pdf->SetLineWidth(.2);

$boxY=38;$boxW=97;$boxH=34;$pdf->SetDrawColor(...$B);$pdf->Rect(8,$boxY,$boxW,$boxH);$pdf->Rect(105,$boxY,$boxW,$boxH);$pdf->section(8,$boxY,$boxW,'CUSTOMER DETAILS');$pdf->section(105,$boxY,$boxW,'INVOICE DETAILS');
$cAddr=trim(implode(', ',array_filter([$s['address_line1'],$s['address_line2'],$s['city'],$s['state'],$s['pincode']])));
$y=$boxY+8;$y=$pdf->info(10,$y,93,'Customer Name',$s['customer_name']?:'Walk-in Customer');$y=$pdf->info(10,$y,93,'Mobile Number',$s['customer_mobile']?:'-');$y=$pdf->info(10,$y,93,'Address',$cAddr?:'-');$y=$pdf->info(10,$y,93,'Customer GSTIN',$s['customer_gstin']?:'Not Applicable');
$y=$boxY+8;$y=$pdf->info(107,$y,93,'Invoice Number',(string)$s['invoice_no']);$y=$pdf->info(107,$y,93,'Invoice Date',date('d-m-Y',strtotime($s['invoice_date'])));$y=$pdf->info(107,$y,93,'Payment Status',(string)($s['payment_status']??'-'));$y=$pdf->info(107,$y,93,'Sales Person',(string)($s['sales_person_name']??'-'));$y=$pdf->info(107,$y,93,'Place of Supply',(string)($s['state']?:$s['branch_state']));

$pdf->SetY(76);
$heads=['S.No','Description','HSN / Purity','Gross g','Stone g','Net g','Rate/g','Metal Value','Making','Other','Taxable'];
$ws=[8,35,19,14,14,14,17,19,17,15,22];
$drawHead=function()use($pdf,$heads,$ws,$P,$D){$pdf->SetFillColor(...$P);$pdf->SetDrawColor(...$D);$pdf->SetTextColor(255);$pdf->SetFont('Arial','B',6.8);foreach($heads as $i=>$h)$pdf->Cell($ws[$i],10,txt($h),1,0,'C',true);$pdf->Ln();$pdf->SetTextColor(36);};
$drawHead();$pdf->SetFont('Arial','',7.2);$pdf->SetDrawColor(...$B);
foreach($items as $n=>$i){
    if($pdf->GetY()>238){$pdf->AddPage();$pdf->SetY(14);$drawHead();}
    $gross=(float)($i['gross_weight']??0);$stone=(float)($i['stone_weight']??$i['less_weight']??0);$net=(float)($i['net_weight']??max(0,$gross-$stone));$rate=(float)($i['metal_rate']??$i['rate_per_gram']??0);$metal=(float)($i['metal_value']??$net*$rate);$making=(float)($i['making_charge']??0);$other=(float)($i['other_charge']??$i['other_charges']??0);$taxable=(float)($i['taxable_amount']??$metal+$making+$other);$hsn=trim((string)($i['hsn_code']??$i['product_hsn']??'').' / '.(string)($i['purity']??''),' /');
    $vals=[$n+1,$i['item_name']??'-',$hsn?:'-',number_format($gross,3),number_format($stone,3),number_format($net,3),number_format($rate,2),number_format($metal,2),number_format($making,2),number_format($other,2),number_format($taxable,2)];
    foreach($vals as $c=>$v)$pdf->Cell($ws[$c],9,txt($v),1,0,$c===1?'L':($c<3?'C':'R'));
    $pdf->Ln();
}
$pdf->Ln(3);

if($ex){$pdf->need(14+count($ex)*7);$pdf->SetFillColor(...$GS);$pdf->SetTextColor(...$P);$pdf->SetFont('Arial','B',8.5);$pdf->Cell($W,7,txt('EXCHANGE DETAILS'),1,1,'L',true);$pdf->SetTextColor(36);$pdf->SetFont('Arial','',8);foreach($ex as $x){$line=($x['item_name']??'Exchange Item').' | '.number_format((float)$x['eligible_weight'],3).' g x Rs. '.number_format((float)$x['rate_per_gram'],2).' = Rs. '.number_format((float)$x['exchange_value'],2);$pdf->MultiCell($W,6,txt($line),1,'L');}$pdf->Ln(2);}
if($claims){$pdf->need(14+count($claims)*7);$pdf->SetFillColor(...$GS);$pdf->SetTextColor(...$P);$pdf->SetFont('Arial','B',8.5);$pdf->Cell($W,7,txt('GOLD GRAM CLAIMS'),1,1,'L',true);$pdf->SetTextColor(36);$pdf->SetFont('Arial','',8);foreach($claims as $c){$line=($c['group_name']?:'Chit').' / Ticket '.($c['ticket_no']?:'-').' | '.number_format((float)$c['claim_grams'],6).' g x Rs. '.number_format((float)$c['rate_per_gram'],2).' = Rs. '.number_format((float)$c['claim_amount'],2);$pdf->MultiCell($W,6,txt($line),1,'L');}$pdf->Ln(2);}

$pdf->need(68);$summaryY=$pdf->GetY();$notesW=112;
$pdf->SetXY(8,$summaryY);$pdf->SetFillColor(...$GS);$pdf->SetTextColor(...$P);$pdf->SetFont('Arial','B',7);$pdf->Cell($notesW,6,txt('TERMS AND CONDITIONS'),1,1,'L',true);$pdf->SetX(8);$pdf->SetTextColor(36);$pdf->SetFont('Arial','',6);
$terms=trim((string)($set['terms_conditions']??''));if($terms==='')$terms="1. Jewellery once sold will be exchanged according to prevailing policy.\n2. Gold rate, making and stone charges are shown separately.\n3. Verify weight, purity and item details before leaving the showroom.\n4. Preserve this invoice for exchange, service or warranty claims.";
$pdf->MultiCell($notesW,4,txt($terms),1,'L');$notesBottom=$pdf->GetY();

$totals=[['Taxable Amount',(float)($s['taxable_amount']??$s['subtotal']??0)],['CGST',(float)($s['cgst_amount']??0)],['SGST',(float)($s['sgst_amount']??0)],['IGST',(float)($s['igst_amount']??0)],['Discount',-(float)($s['discount_amount']??0)],['Exchange',-(float)($s['exchange_amount']??0)],['Gold Claim',-(float)($s['chit_claim_amount']??0)],['Round Off',(float)($s['round_off']??0)],['Grand Total',(float)($s['grand_total']??$s['net_payable_amount']??0)],['Paid Amount',(float)($s['paid_amount']??0)],['Balance',(float)($s['balance_amount']??0)]];
$pdf->SetXY(124,$summaryY);foreach($totals as $r){$grand=$r[0]==='Grand Total';if($grand){$pdf->SetFillColor(...$P);$pdf->SetTextColor(255);$pdf->SetFont('Arial','B',8);}else{$pdf->SetTextColor(36);$pdf->SetFont('Arial',in_array($r[0],['Paid Amount','Balance'],true)?'B':'',6.7);}$pdf->Cell(44,5.4,txt($r[0]),1,0,'L',$grand);$pdf->Cell(34,5.4,txt('Rs. '.number_format((float)$r[1],2)),1,1,'R',$grand);$pdf->SetX(124);} $totalsBottom=$pdf->GetY();

$pdf->SetY(max($notesBottom,$totalsBottom)+3);
$grandTotal=(float)($s['grand_total']??$s['net_payable_amount']??0);
$pdf->SetFillColor(255,250,240);$pdf->SetTextColor(...$D);$pdf->SetFont('Arial','B',7);$pdf->Cell($W,6,txt('AMOUNT IN WORDS'),1,1,'L',true);$pdf->SetFont('Arial','',7);$pdf->MultiCell($W,5,txt(amountWords((int)round($grandTotal))),1,'L');

$pdf->Ln(3);$pdf->need(42);$payY=$pdf->GetY();$half=95;
$pdf->SetXY(8,$payY);$pdf->SetFillColor(...$GS);$pdf->SetTextColor(...$P);$pdf->SetFont('Arial','B',7);$pdf->Cell($half,6,txt('PAYMENT DETAILS'),1,1,'L',true);$pdf->SetTextColor(36);$pdf->SetFont('Arial','',6.3);
if($pays){foreach($pays as $p){$line=($p['method_name']?:'Payment').' - Rs. '.number_format((float)$p['amount'],2).($p['reference_no']?' | Ref: '.$p['reference_no']:'');$pdf->SetX(8);$pdf->MultiCell($half,4.4,txt($line),1,'L');}}else{$pdf->SetX(8);$pdf->Cell($half,8,txt('No payment details available.'),1,1);} $payBottom=$pdf->GetY();

$pdf->SetXY(107,$payY);$pdf->SetFillColor(...$GS);$pdf->SetTextColor(...$P);$pdf->SetFont('Arial','B',7);$pdf->Cell($half,6,txt('BANK / UPI DETAILS'),1,1,'L',true);$pdf->SetTextColor(36);$pdf->SetFont('Arial','',6.3);
$bank=[];foreach(['bank_name'=>'Bank Name','account_name'=>'Account Name','account_number'=>'Account No','ifsc_code'=>'IFSC','upi_id'=>'UPI'] as $k=>$label){if(!empty($set[$k]))$bank[]=$label.': '.$set[$k];}if(!$bank)$bank[]='Bank details are not configured.';foreach($bank as $line){$pdf->SetX(107);$pdf->MultiCell($half,4.4,txt($line),1,'L');}$bankBottom=$pdf->GetY();

$pdf->SetY(max($payBottom,$bankBottom)+16);$pdf->need(22);$sigY=$pdf->GetY();$sw=$W/3;$pdf->SetDrawColor(90);$pdf->Line(12,$sigY,8+$sw-4,$sigY);$pdf->Line(8+$sw+4,$sigY,8+$sw*2-4,$sigY);$pdf->Line(8+$sw*2+4,$sigY,198,$sigY);$pdf->SetY($sigY+2);$pdf->SetFont('Arial','B',6.5);$pdf->Cell($sw,5,txt('Customer Signature'),0,0,'C');$pdf->Cell($sw,5,txt('Checked By'),0,0,'C');$pdf->Cell($sw,5,txt('For '.$name),0,1,'C');

$disp=(isset($_GET['inline'])&&$_GET['inline']=='1')?'I':'D';
$file='invoice-'.preg_replace('/[^A-Za-z0-9_-]+/','-',(string)$s['invoice_no']).'.pdf';
$pdf->Output($disp,$file);