<?php
if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set($_SESSION['timezone'] ?? 'Asia/Kolkata');
foreach ([__DIR__.'/config/config.php',__DIR__.'/config.php',__DIR__.'/includes/config.php',__DIR__.'/super-admin/includes/config.php'] as $f) { if (is_file($f)) { require_once $f; break; } }
if (!isset($conn) || !($conn instanceof mysqli)) die('Database configuration is not available.');
$conn->set_charset('utf8mb4');
foreach ([__DIR__.'/vendor/autoload.php',__DIR__.'/fpdf/fpdf.php',__DIR__.'/includes/fpdf/fpdf.php',__DIR__.'/libs/fpdf/fpdf.php'] as $f) { if (is_file($f)) { require_once $f; break; } }
if (!class_exists('FPDF')) die('FPDF library not found.');

function allRows(mysqli $c,string $sql,string $types='',array $params=[]):array{
    $s=$c->prepare($sql); if(!$s) throw new RuntimeException($c->error);
    if($types!==''){ $a=[$types]; foreach($params as $k=>$v) $a[]=&$params[$k]; call_user_func_array([$s,'bind_param'],$a); }
    if(!$s->execute()) throw new RuntimeException($s->error);
    $r=$s->get_result(); $o=[]; while($x=$r->fetch_assoc()) $o[]=$x; $s->close(); return $o;
}

function tableExists(mysqli $c,string $table):bool{
    $safe=$c->real_escape_string($table);
    $r=$c->query("SHOW TABLES LIKE '{$safe}'");
    return $r && $r->num_rows>0;
}

function getBusinessSetting(mysqli $c,int $businessId,string $key):string{
    if(!tableExists($c,'business_settings')) return '';
    $rows=allRows($c,'SELECT setting_value FROM business_settings WHERE business_id=? AND setting_key=? LIMIT 1','is',[$businessId,$key]);
    return $rows ? trim((string)($rows[0]['setting_value']??'')) : '';
}

function ensureInvoiceShareSecret(mysqli $c,int $businessId):string{
    $key='invoice_share_secret';
    $secret=getBusinessSetting($c,$businessId,$key);
    if($secret!=='') return $secret;

    if(!tableExists($c,'business_settings')){
        throw new RuntimeException('business_settings table is required for public invoice sharing.');
    }

    $secret=bin2hex(random_bytes(32));
    $stmt=$c->prepare("INSERT INTO business_settings (business_id,setting_key,setting_value,value_type,is_public) VALUES (?,?,?,'string',0)");
    if($stmt){
        $stmt->bind_param('iss',$businessId,$key,$secret);
        if($stmt->execute()){
            $stmt->close();
            return $secret;
        }
        $stmt->close();
    }

    // Another request may have created it at the same time.
    $secret=getBusinessSetting($c,$businessId,$key);
    if($secret==='') throw new RuntimeException('Unable to create invoice sharing secret.');
    return $secret;
}

function invoiceShareToken(string $secret,int $businessId,int $saleId):string{
    return hash_hmac('sha256',$businessId.'|'.$saleId,$secret);
}

function currentAbsoluteScriptUrl():string{
    $https=!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS'])!=='off';
    if(!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])){
        $https=strtolower(trim(explode(',',(string)$_SERVER['HTTP_X_FORWARDED_PROTO'])[0]))==='https';
    }
    $scheme=$https?'https':'http';
    $host=(string)($_SERVER['HTTP_HOST']??'localhost');
    $script=(string)($_SERVER['SCRIPT_NAME']??'/sale-invoice-pdf.php');
    return $scheme.'://'.$host.$script;
}

function publicInvoiceBaseUrl(mysqli $c,int $businessId):string{
    foreach(['invoice_public_base_url','public_base_url'] as $key){
        $value=rtrim(getBusinessSetting($c,$businessId,$key),'/');
        if($value!==''){
            // Setting may be a site root or the full PHP endpoint.
            if(preg_match('/\\.php$/i',$value)) return $value;
            return $value.'/sale-invoice-pdf.php';
        }
    }
    if(defined('APP_URL') && trim((string)APP_URL)!=='') return rtrim((string)APP_URL,'/').'/sale-invoice-pdf.php';
    if(defined('BASE_URL') && trim((string)BASE_URL)!=='') return rtrim((string)BASE_URL,'/').'/sale-invoice-pdf.php';
    return currentAbsoluteScriptUrl();
}

function normalizeWhatsappNumber(string $value):string{
    $digits=preg_replace('/\\D+/','',$value);
    if(strlen($digits)===10) return '91'.$digits;
    if(strlen($digits)===11 && substr($digits,0,1)==='0') return '91'.substr($digits,1);
    return $digits;
}
function txt($v):string{ $v=str_replace(['₹','–','—','•'],['Rs. ','-','-','-'],(string)($v??'')); $x=@iconv('UTF-8','windows-1252//TRANSLIT//IGNORE',$v); return $x!==false?$x:$v; }
function amountWords(int $n):string{
    if($n<=0) return 'Rupees Zero Only';
    $o=['','One','Two','Three','Four','Five','Six','Seven','Eight','Nine','Ten','Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen','Seventeen','Eighteen','Nineteen'];
    $t=['','','Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];
    $small=function($v)use($o,$t){$w='';if($v>=100){$w=$o[intdiv($v,100)].' Hundred';$v%=100;if($v)$w.=' ';}if($v)$w.=$v<20?$o[$v]:$t[intdiv($v,10)].($v%10?' '.$o[$v%10]:'');return trim($w);};
    $p=[]; foreach([[10000000,'Crore'],[100000,'Lakh'],[1000,'Thousand']] as [$d,$l]){$q=intdiv($n,$d);if($q){$p[]=$small($q).' '.$l;$n%=$d;}} if($n)$p[]=$small($n); return 'Rupees '.implode(' ',$p).' Only';
}

$saleId=(int)($_GET['sale_id']??0);
$shareBusinessId=(int)($_GET['business_id']??0);
$shareToken=trim((string)($_GET['token']??''));
$isPublicShare=$shareBusinessId>0 && $saleId>0 && $shareToken!=='';
$isLoggedIn=!empty($_SESSION['user_id']);

if(!$isPublicShare && !$isLoggedIn){
    http_response_code(401);
    die('Session expired.');
}

$businessId=$isPublicShare ? $shareBusinessId : (int)($_SESSION['business_id']??0);
if($businessId<=0||$saleId<=0) die('Invalid sale.');

if($isPublicShare){
    try{
        $secret=getBusinessSetting($conn,$businessId,'invoice_share_secret');
        $expected=$secret!=='' ? invoiceShareToken($secret,$businessId,$saleId) : '';
        if($expected==='' || !hash_equals($expected,$shareToken)){
            http_response_code(403);
            die('Invalid or expired invoice share link.');
        }
    }catch(Throwable $e){
        http_response_code(403);
        die('Unable to verify invoice share link.');
    }
}

if(isset($_GET['action']) && $_GET['action']==='share_link'){
    header('Content-Type: application/json; charset=utf-8');
    if(!$isLoggedIn){
        http_response_code(401);
        echo json_encode(['success'=>false,'message'=>'Session expired.']);
        exit;
    }

    try{
        $saleRows=allRows($conn,"SELECT s.id,s.business_id,s.invoice_no,s.customer_mobile,c.mobile customer_master_mobile FROM sales s LEFT JOIN customers c ON c.id=s.customer_id WHERE s.id=? AND s.business_id=? LIMIT 1",'ii',[$saleId,$businessId]);
        if(!$saleRows) throw new RuntimeException('Sale not found.');
        $sale=$saleRows[0];
        $secret=ensureInvoiceShareSecret($conn,$businessId);
        $token=invoiceShareToken($secret,$businessId,$saleId);
        $base=publicInvoiceBaseUrl($conn,$businessId);
        $publicUrl=$base.(strpos($base,'?')===false?'?':'&').'sale_id='.rawurlencode((string)$saleId).'&business_id='.rawurlencode((string)$businessId).'&token='.rawurlencode($token).'&inline=1';
        $mobile=normalizeWhatsappNumber((string)($sale['customer_mobile']?:$sale['customer_master_mobile']?:''));
        $message='Invoice: '.(string)$sale['invoice_no']."\nView Invoice: ".$publicUrl;
        $whatsappUrl=$mobile!=='' ? 'https://wa.me/'.rawurlencode($mobile).'?text='.rawurlencode($message) : '';
        $host=(string)(parse_url($publicUrl,PHP_URL_HOST)??'');
        $isLocal=in_array(strtolower($host),['localhost','127.0.0.1','::1'],true);
        echo json_encode([
            'success'=>true,
            'invoice_no'=>(string)$sale['invoice_no'],
            'mobile'=>$mobile,
            'public_url'=>$publicUrl,
            'whatsapp_url'=>$whatsappUrl,
            'is_local_url'=>$isLocal,
            'message'=>$isLocal ? 'Share link generated, but localhost links cannot be opened from another phone. Configure business setting invoice_public_base_url with your public/LAN URL.' : 'Share link generated.'
        ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    }catch(Throwable $e){
        http_response_code(400);
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    exit;
}

try{
    $saleRows=allRows($conn,"SELECT s.*,c.customer_code,c.mobile customer_master_mobile,c.email,c.gstin customer_gstin,c.address_line1,c.address_line2,c.city,c.state,c.pincode,b.business_name,b.legal_name,b.mobile business_mobile,b.email business_email,b.website,b.gstin business_gstin,b.pan_no,br.branch_name,br.mobile branch_mobile,br.email branch_email,br.address_line1 branch_address1,br.address_line2 branch_address2,br.city branch_city,br.state branch_state,br.pincode branch_pincode,br.gstin branch_gstin FROM sales s LEFT JOIN customers c ON c.id=s.customer_id LEFT JOIN businesses b ON b.id=s.business_id LEFT JOIN branches br ON br.id=s.branch_id WHERE s.id=? AND s.business_id=? LIMIT 1",'ii',[$saleId,$businessId]);
    if(!$saleRows) die('Sale not found.'); $s=$saleRows[0];
    $items=allRows($conn,"SELECT si.*,p.product_code,p.hsn_code product_hsn,p.metal_id,
               COALESCE(p.dynamic_stock,0) AS dynamic_stock,
               COALESCE(p.net_weight,0) AS product_net_weight
        FROM sale_items si
        LEFT JOIN products p ON p.id=si.product_id
        WHERE si.sale_id=? AND si.business_id=?
        ORDER BY si.sort_order,si.id",'ii',[$saleId,$businessId]);

    // Show only today's/current Gold and Silver rates on the invoice.
    $todayMetalRates=[];
    if(tableExists($conn,'metal_rates') && tableExists($conn,'metals')){
        $rateRows=allRows($conn,"SELECT mr.id,mr.metal_id,mr.rate_per_gram,mr.branch_id,mr.effective_from,m.metal_name
            FROM metal_rates mr
            INNER JOIN metals m ON m.id=mr.metal_id AND m.business_id=mr.business_id
            WHERE mr.business_id=?
              AND mr.is_current=1
              AND (mr.branch_id=? OR mr.branch_id IS NULL)
              AND (LOWER(m.metal_name) LIKE '%gold%' OR LOWER(m.metal_name) LIKE '%silver%')
            ORDER BY
              CASE WHEN LOWER(m.metal_name) LIKE '%gold%' THEN 0 ELSE 1 END,
              CASE WHEN mr.branch_id=? THEN 0 ELSE 1 END,
              mr.effective_from DESC,
              mr.id DESC",'iii',[$businessId,(int)$s['branch_id'],(int)$s['branch_id']]);

        // Keep only one current Gold rate and one current Silver rate.
        // Gold is intentionally inserted first, then Silver.
        $groupedRates=['gold'=>null,'silver'=>null];
        foreach($rateRows as $rateRow){
            $metalName=strtolower(trim((string)($rateRow['metal_name']??'')));
            $group=strpos($metalName,'gold')!==false ? 'gold' : (strpos($metalName,'silver')!==false ? 'silver' : '');
            if($group!=='' && $groupedRates[$group]===null){
                $groupedRates[$group]=$rateRow;
            }
        }
        foreach(['gold','silver'] as $group){
            if($groupedRates[$group]!==null){
                $todayMetalRates[]=$groupedRates[$group];
            }
        }
    }
    $pays=allRows($conn,"SELECT sp.*,pm.method_name FROM sale_payments sp LEFT JOIN payment_methods pm ON pm.id=sp.payment_method_id WHERE sp.sale_id=? AND sp.business_id=? ORDER BY sp.id",'ii',[$saleId,$businessId]);
    $claims=allRows($conn,"SELECT sc.*,cg.group_name,cg.group_no,cm.ticket_no FROM sales_chit_claims sc LEFT JOIN chit_groups cg ON cg.id=sc.chit_group_id LEFT JOIN chit_members cm ON cm.id=sc.chit_member_id WHERE sc.sale_id=? AND sc.business_id=? AND sc.status='Posted'",'ii',[$saleId,$businessId]);
    $ex=allRows($conn,"SELECT * FROM sale_exchange_items WHERE sale_id=? AND business_id=? ORDER BY id",'ii',[$saleId,$businessId]);
    $exchangePayouts=[];
    if(tableExists($conn,'sale_exchange_payouts')){
        $exchangePayouts=allRows($conn,"SELECT sep.*,pm.method_name
            FROM sale_exchange_payouts sep
            LEFT JOIN payment_methods pm ON pm.id=sep.payment_method_id AND pm.business_id=sep.business_id
            WHERE sep.sale_id=? AND sep.business_id=?
            ORDER BY sep.id",'ii',[$saleId,$businessId]);
    }

    /* Advance booking values actually applied to this invoice. */
    $advanceBookingUsage=[];
    if(tableExists($conn,'advance_booking_usage') && tableExists($conn,'advance_bookings')){
        $advanceBookingUsage=allRows($conn,"SELECT abu.id,abu.advance_booking_id,abu.used_amount,abu.used_grams,
                   abu.used_rate_per_gram,abu.usage_date,abu.invoice_no,
                   ab.booking_no,ab.booking_date,ab.product_name,ab.purity,
                   ab.advance_amount,ab.booked_grams,ab.status,
                   m.metal_name
            FROM advance_booking_usage abu
            INNER JOIN advance_bookings ab
                ON ab.id=abu.advance_booking_id
               AND ab.business_id=abu.business_id
               AND ab.branch_id=abu.branch_id
            LEFT JOIN metals m
                ON m.id=ab.metal_id
               AND m.business_id=ab.business_id
            WHERE abu.sale_id=?
              AND abu.business_id=?
              AND abu.branch_id=?
              AND COALESCE(abu.used_amount,0)>0
            ORDER BY abu.usage_date,abu.id",'iii',[$saleId,$businessId,(int)$s['branch_id']]);
    }

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
$invoiceStatus=(string)($s['workflow_status']??$s['status']??'');
$isCancelledInvoice=strcasecmp(trim($invoiceStatus),'Cancelled')===0;
$pdf->SetXY(153,8);$pdf->SetTextColor(...$P);$pdf->SetFont('Arial','B',11);$pdf->Cell(49,7,txt($set['header_text']??'TAX INVOICE'),0,1,'R');
$pdf->SetX(153);
if($isCancelledInvoice){
    $pdf->SetFillColor(255,235,235);
    $pdf->SetDrawColor(220,53,69);
    $pdf->SetTextColor(220,53,69);
    $pdf->SetFont('Arial','B',8);
    $pdf->Cell(49,6,txt('CANCELLED'),1,1,'C',true);
}else{
    $pdf->SetFillColor(...$GS);
    $pdf->SetDrawColor(...$G);
    $pdf->SetTextColor(...$P);
    $pdf->SetFont('Arial','B',6);
    $pdf->Cell(49,6,txt('ORIGINAL FOR RECIPIENT'),1,1,'C',true);
}

$pdf->SetDrawColor(...$P);$pdf->SetLineWidth(.8);$pdf->Line(8,34,202,34);$pdf->SetLineWidth(.2);

$boxY=38;$boxW=97;$boxH=34;$pdf->SetDrawColor(...$B);$pdf->Rect(8,$boxY,$boxW,$boxH);$pdf->Rect(105,$boxY,$boxW,$boxH);$pdf->section(8,$boxY,$boxW,'CUSTOMER DETAILS');$pdf->section(105,$boxY,$boxW,'INVOICE DETAILS');
$cAddr=trim(implode(', ',array_filter([$s['address_line1'],$s['address_line2'],$s['city'],$s['state'],$s['pincode']])));
$customerDisplayMobile=(string)($s['customer_mobile']?:$s['customer_master_mobile']?:'-');
$y=$boxY+8;$y=$pdf->info(10,$y,93,'Customer Name',$s['customer_name']?:'Walk-in Customer');$y=$pdf->info(10,$y,93,'Mobile Number',$customerDisplayMobile);$y=$pdf->info(10,$y,93,'Address',$cAddr?:'-');$y=$pdf->info(10,$y,93,'Customer GSTIN',$s['customer_gstin']?:'Not Applicable');
$y=$boxY+8;$y=$pdf->info(107,$y,93,'Invoice Number',(string)$s['invoice_no']);$y=$pdf->info(107,$y,93,'Invoice Date',date('d-m-Y',strtotime($s['invoice_date'])));$y=$pdf->info(107,$y,93,'Payment Status',(string)($s['payment_status']??'-'));$y=$pdf->info(107,$y,93,'Sales Person',(string)($s['sales_person_name']??'-'));$y=$pdf->info(107,$y,93,'Place of Supply',(string)($s['state']?:$s['branch_state']));

$pdf->SetY(76);
$heads=['S.No','Description','HSN / Purity','Gross g','Stone g','Net g','Making','Other','Taxable'];
$ws=[9,47,24,16,16,16,20,20,26];
$drawHead=function()use($pdf,$heads,$ws,$P,$D){$pdf->SetFillColor(...$P);$pdf->SetDrawColor(...$D);$pdf->SetTextColor(255);$pdf->SetFont('Arial','B',6.8);foreach($heads as $i=>$h)$pdf->Cell($ws[$i],10,txt($h),1,0,'C',true);$pdf->Ln();$pdf->SetTextColor(36);};
$drawHead();$pdf->SetFont('Arial','',7.2);$pdf->SetDrawColor(...$B);
foreach($items as $n=>$i){
    if($pdf->GetY()>238){$pdf->AddPage();$pdf->SetY(14);$drawHead();}
    $gross=(float)($i['gross_weight']??0);
    $stone=(float)($i['stone_weight']??$i['less_weight']??0);
    $isDynamic=((int)($i['dynamic_stock']??0)===1);
    $net=$isDynamic ? null : (float)($i['net_weight']??$i['product_net_weight']??max(0,$gross-$stone));
    $rate=(float)($i['metal_rate']??$i['rate_per_gram']??0);
    $metal=(float)($i['metal_value']??($gross>0?$gross*$rate:(($net!==null?$net:0)*$rate)));
    $making=(float)($i['making_charge']??0);
    $wastageAmount=(float)($i['wastage_amount']??0);
    $stoneAmount=(float)($i['stone_amount']??0);
    $other=(float)($i['other_charge']??$i['other_charges']??0);
    $discount=(float)($i['discount_amount']??0);

    /*
     * Saved billing formula:
     * Taxable = Metal + Making + Wastage + Stone + Other - Item Discount
     * Example:
     * 348,750 + 50 + 6,976 = 355,776
     */
    $taxable=(float)($i['taxable_amount']
        ?? max(0,$metal+$making+$wastageAmount+$stoneAmount+$other-$discount));

    $hsn=trim((string)($i['hsn_code']??$i['product_hsn']??'').' / '.(string)($i['purity']??''),' /');
    $netDisplay=$isDynamic ? '-' : number_format((float)$net,3);
    $description=(string)($i['item_name']??'-');
    if($isDynamic) $description.=' (Dynamic Weight)';
    $vals=[$n+1,$description,$hsn?:'-',number_format($gross,3),number_format($stone,3),$netDisplay,number_format($making,2),number_format($other,2),number_format($taxable,2)];
    foreach($vals as $c=>$v)$pdf->Cell($ws[$c],9,txt($v),1,0,$c===1?'L':($c<3?'C':'R'));
    $pdf->Ln();
}
$pdf->Ln(3);

if($ex){$pdf->need(14+count($ex)*7);$pdf->SetFillColor(...$GS);$pdf->SetTextColor(...$P);$pdf->SetFont('Arial','B',8.5);$pdf->Cell($W,7,txt('EXCHANGE DETAILS'),1,1,'L',true);$pdf->SetTextColor(36);$pdf->SetFont('Arial','',8);foreach($ex as $x){$line=($x['item_name']??'Exchange Item').' | '.number_format((float)$x['eligible_weight'],3).' g = Rs. '.number_format((float)$x['exchange_value'],2);$pdf->MultiCell($W,6,txt($line),1,'L');}$pdf->Ln(2);}
if($exchangePayouts){
    $pdf->need(18+count($exchangePayouts)*7);
    $pdf->SetFillColor(...$GS);$pdf->SetTextColor(...$P);$pdf->SetFont('Arial','B',8.5);
    $pdf->Cell($W,7,txt('PAID TO CUSTOMER - EXCHANGE EXTRA VALUE'),1,1,'L',true);
    $pdf->SetTextColor(75);$pdf->SetFont('Arial','',6.5);
    $pdf->MultiCell($W,5,txt('Amount returned to the customer when old-gold / exchange value is higher than the bill value.'),1,'L');
    $pdf->SetTextColor(36);$pdf->SetFont('Arial','',7.2);
    foreach($exchangePayouts as $payout){
        $method=trim((string)($payout['method_name']??''));
        if($method==='') $method='Payment';
        $reference=trim((string)($payout['reference_no']??''));
        $dateValue=(string)($payout['payout_date']??'');
        $dateText=$dateValue!=='' ? date('d-m-Y h:i A',strtotime($dateValue)) : '-';
        $line=$dateText.' | '.$method;
        if($reference!=='') $line.=' | Ref: '.$reference;
        $line.=' | Rs. '.number_format((float)($payout['amount']??0),2);
        $pdf->MultiCell($W,6,txt($line),1,'L');
    }
    $pdf->Ln(2);
}
if($claims){$pdf->need(14+count($claims)*7);$pdf->SetFillColor(...$GS);$pdf->SetTextColor(...$P);$pdf->SetFont('Arial','B',8.5);$pdf->Cell($W,7,txt('GOLD GRAM CLAIMS'),1,1,'L',true);$pdf->SetTextColor(36);$pdf->SetFont('Arial','',8);foreach($claims as $c){$line=($c['group_name']?:'Chit').' / Ticket '.($c['ticket_no']?:'-').' | '.number_format((float)$c['claim_grams'],6).' g x Rs. '.number_format((float)$c['rate_per_gram'],2).' = Rs. '.number_format((float)$c['claim_amount'],2);$pdf->MultiCell($W,6,txt($line),1,'L');}$pdf->Ln(2);}

/* Show Advance Booking details only when an advance amount was actually applied. */
if($advanceBookingUsage){
    $pdf->need(18+count($advanceBookingUsage)*12);
    $pdf->SetFillColor(...$GS);$pdf->SetTextColor(...$P);$pdf->SetFont('Arial','B',8.5);
    $pdf->Cell($W,7,txt('ADVANCE BOOKING APPLIED'),1,1,'L',true);
    $pdf->SetTextColor(36);$pdf->SetFont('Arial','',7.2);
    foreach($advanceBookingUsage as $abu){
        $bookingNo=trim((string)($abu['booking_no']??''));
        $productName=trim((string)($abu['product_name']??''));
        $metal=trim((string)($abu['metal_name']??''));
        $purity=trim((string)($abu['purity']??''));
        $usedAmount=(float)($abu['used_amount']??0);
        $usedGrams=(float)($abu['used_grams']??0);
        $usedRate=(float)($abu['used_rate_per_gram']??0);
        $usageDate=trim((string)($abu['usage_date']??''));
        $dateText=$usageDate!=='' ? date('d-m-Y',strtotime($usageDate)) : '-';
        $metalPurity=trim($metal.($purity!=='' ? ' '.$purity : ''));
        $line1='Booking: '.($bookingNo!==''?$bookingNo:'-').' | Product: '.($productName!==''?$productName:'-');
        if($metalPurity!=='') $line1.=' | '.$metalPurity;
        $line2='Applied: Rs. '.number_format($usedAmount,2).' | Grams: '.number_format($usedGrams,6).' g | Locked Rate: Rs. '.number_format($usedRate,2).'/g | Used On: '.$dateText;
        $pdf->MultiCell($W,5.2,txt($line1),1,'L');
        $pdf->MultiCell($W,5.2,txt($line2),1,'L');
    }
    $pdf->Ln(2);
}

$notesW=112;
$terms=trim((string)($set['terms_conditions']??''));
if($terms===''){
    $terms="1. Jewellery once sold will be exchanged according to prevailing policy.\n2. Gold rate, making and stone charges are shown separately.\n3. Verify weight, purity and item details before leaving the showroom.\n4. Preserve this invoice for exchange, service or warranty claims.";
}

// Keep the summary block together where possible. The left side contains
// Terms & Conditions followed by today's Gold and Silver rates; totals remain right.
$termLineEstimate=max(1,substr_count($terms,"\n")+1);
$rateHeight=$todayMetalRates ? (14+(count($todayMetalRates)*6)) : 0;
$leftHeight=6+($termLineEstimate*4)+2+$rateHeight;
$pdf->need(max(68,$leftHeight+3));
$summaryY=$pdf->GetY();
$summaryPage=$pdf->PageNo();

/* ---------------- RIGHT SIDE: TOTALS ---------------- */
// Summary Taxable Amount must show the value before discount and round off.
// sales.taxable_amount is stored after discounts, so add the saved discount back.
$summaryTaxableBeforeDiscount =
    (float)($s['taxable_amount'] ?? $s['subtotal'] ?? 0)
    + (float)($s['discount_amount'] ?? 0);

$exchangePayoutTotal=0.0;
foreach($exchangePayouts as $payoutRow){
    $exchangePayoutTotal+=(float)($payoutRow['amount']??0);
}
$advanceBookingAppliedTotal=0.0;
foreach($advanceBookingUsage as $advanceRow){
    $advanceBookingAppliedTotal+=(float)($advanceRow['used_amount']??0);
}
$totals=[
    ['Taxable Amount',$summaryTaxableBeforeDiscount],
    ['CGST',(float)($s['cgst_amount']??0)],
    ['SGST',(float)($s['sgst_amount']??0)],
    ['IGST',(float)($s['igst_amount']??0)],
    ['Discount',-(float)($s['discount_amount']??0)],
    ['Exchange',-(float)($s['exchange_amount']??0)],
    ['Gold Claim',-(float)($s['chit_claim_amount']??0)]
];
if($advanceBookingAppliedTotal>0.005){
    $totals[]=['Advance Booking',-$advanceBookingAppliedTotal];
}
$totals[]=['Round Off',(float)($s['round_off']??0)];
$totals[]=['Grand Total',(float)($s['grand_total']??$s['net_payable_amount']??0)];
$totals[]=['Paid Amount',(float)($s['paid_amount']??0)];
if($exchangePayoutTotal>0.005){
    $totals[]=['Paid to Customer',$exchangePayoutTotal];
}
$totals[]=['Balance',(float)($s['balance_amount']??0)];
$pdf->SetXY(124,$summaryY);
foreach($totals as $r){
    $grand=$r[0]==='Grand Total';
    if($grand){
        $pdf->SetFillColor(...$P);$pdf->SetTextColor(255);$pdf->SetFont('Arial','B',8);
    }else{
        $pdf->SetTextColor(36);$pdf->SetFont('Arial',in_array($r[0],['Paid Amount','Balance'],true)?'B':'',6.7);
    }
    $pdf->Cell(44,5.4,txt($r[0]),1,0,'L',$grand);
    $pdf->Cell(34,5.4,txt('Rs. '.number_format((float)$r[1],2)),1,1,'R',$grand);
    $pdf->SetX(124);
}
$totalsBottom=$pdf->GetY();

/* ---------------- LEFT SIDE: TERMS ---------------- */
$pdf->SetXY(8,$summaryY);
$pdf->SetFillColor(...$GS);$pdf->SetTextColor(...$P);$pdf->SetFont('Arial','B',7);
$pdf->Cell($notesW,6,txt('TERMS AND CONDITIONS'),1,1,'L',true);
$pdf->SetX(8);$pdf->SetTextColor(36);$pdf->SetFont('Arial','',6);
$pdf->MultiCell($notesW,4,txt($terms),1,'L');
$notesBottom=$pdf->GetY();

/* ---------------- LEFT SIDE: TODAY GOLD & SILVER RATES ---------------- */
$rateBottom=$notesBottom;
if($todayMetalRates){
    $rateWs=[12,60,40]; // total = 112 mm
    $pdf->SetY($notesBottom+2);
    $pdf->SetX(8);
    $pdf->SetFillColor(...$GS);$pdf->SetDrawColor(...$D);$pdf->SetTextColor(...$P);$pdf->SetFont('Arial','B',7);
    $pdf->Cell($notesW,6,txt("TODAY'S GOLD & SILVER RATES"),1,1,'L',true);

    $pdf->SetX(8);
    $pdf->SetFillColor(...$P);$pdf->SetTextColor(255);$pdf->SetFont('Arial','B',6.1);
    foreach(['S.No','Metal',"Rate / g"] as $idx=>$head){
        $pdf->Cell($rateWs[$idx],7,txt($head),1,0,'C',true);
    }
    $pdf->Ln();

    $pdf->SetTextColor(36);$pdf->SetDrawColor(...$D);$pdf->SetFont('Arial','',6.6);
    foreach($todayMetalRates as $rateIndex=>$rateRow){
        if($pdf->GetY()+6>$pdf->GetPageHeight()-17){
            $pdf->AddPage();
            $pdf->SetY(14);
            $pdf->SetX(8);
            $pdf->SetFillColor(...$P);$pdf->SetTextColor(255);$pdf->SetFont('Arial','B',6.1);
            foreach(['S.No','Metal',"Today's Rate / g"] as $idx=>$head){
                $pdf->Cell($rateWs[$idx],7,txt($head),1,0,'C',true);
            }
            $pdf->Ln();
            $pdf->SetTextColor(36);$pdf->SetDrawColor(...$D);$pdf->SetFont('Arial','',6.6);
        }

        $metalName=trim((string)($rateRow['metal_name']??'-'));
        $todayRate=(float)($rateRow['rate_per_gram']??0);
        $values=[
            $rateIndex+1,
            $metalName!==''?$metalName:'-',
            $todayRate>0?'Rs. '.number_format($todayRate,2):'-'
        ];
        $pdf->SetX(8);
        foreach($values as $col=>$value){
            $align=$col===0?'C':($col===1?'L':'R');
            $pdf->Cell($rateWs[$col],6,txt($value),1,0,$align);
        }
        $pdf->Ln();
    }
    $rateBottom=$pdf->GetY();
}

// Continue below whichever side is lower when both are on the same page.
// If Rate Details moved to a new page, continue below that section there.
if($pdf->PageNo()===$summaryPage){
    $pdf->SetY(max($rateBottom,$totalsBottom)+3);
}else{
    $pdf->SetY($rateBottom+3);
}

$grandTotal=(float)($s['grand_total']??$s['net_payable_amount']??0);
$pdf->need(14);
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