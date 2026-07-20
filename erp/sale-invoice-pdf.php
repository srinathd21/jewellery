<?php
if (session_status() === PHP_SESSION_NONE)
    session_start();
date_default_timezone_set((string) ($_SESSION['timezone'] ?? 'Asia/Kolkata'));
foreach ([__DIR__ . '/config/config.php', __DIR__ . '/config.php', __DIR__ . '/includes/config.php', __DIR__ . '/super-admin/includes/config.php'] as $f) {
    if (is_file($f)) {
        require_once $f;
        break;
    }
}
if (!isset($conn) || !($conn instanceof mysqli))
    die('Database configuration is not available.');
$conn->set_charset('utf8mb4');
if (empty($_SESSION['user_id']))
    die('Session expired.');
$fpdfCandidates = [__DIR__ . '/vendor/autoload.php', __DIR__ . '/fpdf/fpdf.php', __DIR__ . '/includes/fpdf/fpdf.php', __DIR__ . '/libs/fpdf/fpdf.php'];
foreach ($fpdfCandidates as $f) {
    if (is_file($f)) {
        require_once $f;
        break;
    }
}
if (!class_exists('FPDF'))
    die('FPDF library not found. Install FPDF in vendor/, fpdf/, includes/fpdf/, or lib/fpdf/.');
function allRows(mysqli $c, string $sql, string $types = '', array $params = []): array
{
    $s = $c->prepare($sql);
    if (!$s)
        throw new RuntimeException($c->error);
    if ($types !== '') {
        $a = [$types];
        foreach ($params as $k => $v)
            $a[] =& $params[$k];
        call_user_func_array([$s, 'bind_param'], $a);
    }
    if (!$s->execute())
        throw new RuntimeException($s->error);
    $r = $s->get_result();
    $o = [];
    while ($x = $r->fetch_assoc())
        $o[] = $x;
    $s->close();
    return $o;
}
function txt($v)
{
    $v = (string) ($v ?? '');
    $v = str_replace(['₹', '–', '—'], ['Rs. ', '-', '-'], $v);
    return iconv('UTF-8', 'windows-1252//TRANSLIT', $v);
}
$businessId = (int) ($_SESSION['business_id'] ?? 0);
$saleId = (int) ($_GET['sale_id'] ?? 0);
if ($businessId <= 0 || $saleId <= 0)
    die('Invalid sale.');
try {
    $saleRows = allRows($conn, "SELECT s.*,c.customer_code,c.email,c.gstin customer_gstin,c.address_line1,c.address_line2,c.city,c.state,c.pincode,b.business_name,b.legal_name,b.mobile business_mobile,b.email business_email,b.website,b.gstin business_gstin,b.pan_no,br.branch_name,br.mobile branch_mobile,br.email branch_email,br.address_line1 branch_address1,br.address_line2 branch_address2,br.city branch_city,br.state branch_state,br.pincode branch_pincode,br.gstin branch_gstin FROM sales s LEFT JOIN customers c ON c.id=s.customer_id LEFT JOIN businesses b ON b.id=s.business_id LEFT JOIN branches br ON br.id=s.branch_id WHERE s.id=? AND s.business_id=? LIMIT 1", 'ii', [$saleId, $businessId]);
    if (!$saleRows)
        die('Sale not found.');
    $s = $saleRows[0];
    $items = allRows($conn, "SELECT si.*,p.product_code FROM sale_items si LEFT JOIN products p ON p.id=si.product_id WHERE si.sale_id=? AND si.business_id=? ORDER BY si.sort_order,si.id", 'ii', [$saleId, $businessId]);
    $pays = allRows($conn, "SELECT sp.*,pm.method_name FROM sale_payments sp LEFT JOIN payment_methods pm ON pm.id=sp.payment_method_id WHERE sp.sale_id=? AND sp.business_id=? ORDER BY sp.id", 'ii', [$saleId, $businessId]);
    $claims = allRows($conn, "SELECT sc.*,cg.group_name,cg.group_no,cm.ticket_no FROM sales_chit_claims sc LEFT JOIN chit_groups cg ON cg.id=sc.chit_group_id LEFT JOIN chit_members cm ON cm.id=sc.chit_member_id WHERE sc.sale_id=? AND sc.business_id=? AND sc.status='Posted'", 'ii', [$saleId, $businessId]);
    $ex = allRows($conn, "SELECT * FROM sale_exchange_items WHERE sale_id=? AND business_id=? ORDER BY id", 'ii', [$saleId, $businessId]);
    $settings = allRows($conn, "SELECT * FROM invoice_settings WHERE business_id=? AND (branch_id=? OR branch_id IS NULL) AND document_type='Invoice' AND is_active=1 ORDER BY (branch_id=?) DESC,is_default DESC,id DESC LIMIT 1", 'iii', [$businessId, (int) $s['branch_id'], (int) $s['branch_id']]);
    $set = $settings ? $settings[0] : [];
} catch (Throwable $e) {
    die('Unable to build invoice: ' . htmlspecialchars($e->getMessage()));
}
$paper = $set['paper_size'] ?? 'A4';
$orientation = ($set['orientation'] ?? 'Portrait') === 'Landscape' ? 'L' : 'P';
if ($paper === '80mm') {
    $size = [80, 220];
    $orientation = 'P';
} elseif ($paper === '58mm') {
    $size = [58, 220];
    $orientation = 'P';
} elseif ($paper === 'Custom' && !empty($set['custom_width_mm']) && !empty($set['custom_height_mm'])) {
    $size = [(float) $set['custom_width_mm'], (float) $set['custom_height_mm']];
} else {
    $size = $paper;
}
class InvoicePDF extends FPDF
{
    public $footerText = '';
    function Footer()
    {
        if ($this->footerText !== '') {
            $this->SetY(-12);
            $this->SetFont('Arial', '', 7);
            $this->SetTextColor(100);
            $this->MultiCell(0, 4, txt($this->footerText), 0, 'C');
        }
    }
}
$pdf = new InvoicePDF($orientation, 'mm', $size);
$pdf->footerText = (string) ($set['footer_text'] ?? '');
$pdf->SetMargins(8, 8, 8);
$pdf->SetAutoPageBreak(true, 16);
$pdf->AddPage();
$w = $pdf->GetPageWidth() - 16;
$thermal = is_array($size) && $size[0] <= 80;
$logo = (string) ($set['invoice_logo_path'] ?? '');
if (!empty($set['show_business_logo']) && $logo !== '' && is_file(__DIR__ . '/' . $logo)) {
    $pdf->Image(__DIR__ . '/' . $logo, 8, 8, $thermal ? 14 : 22);
    $pdf->SetX($thermal ? 24 : 34);
}
$pdf->SetFont('Arial', 'B', $thermal ? 12 : 16);
$pdf->Cell(0, 7, txt($s['business_name'] ?? 'Business'), 0, 1, 'C');
$pdf->SetFont('Arial', '', 7);
$address = trim(implode(', ', array_filter([$s['branch_address1'], $s['branch_address2'], $s['branch_city'], $s['branch_state'], $s['branch_pincode']])));
if ($address !== '')
    $pdf->MultiCell(0, 4, txt($address), 0, 'C');
if (!empty($set['show_gstin']))
    $pdf->Cell(0, 4, txt('GSTIN: ' . ($s['branch_gstin'] ?: $s['business_gstin'])), 0, 1, 'C');
$pdf->Ln(2);
$pdf->SetDrawColor(180);
$pdf->Line(8, $pdf->GetY(), $pdf->GetPageWidth() - 8, $pdf->GetY());
$pdf->Ln(3);
$pdf->SetFont('Arial', 'B', $thermal ? 10 : 13);
$pdf->Cell(0, 6, txt($set['header_text'] ?? 'TAX INVOICE'), 0, 1, 'C');
$pdf->SetFont('Arial', '', 8);
$pdf->Cell($w / 2, 5, txt('Invoice: ' . $s['invoice_no']), 0, 0);
$pdf->Cell($w / 2, 5, txt('Date: ' . date('d-m-Y', strtotime($s['invoice_date']))), '', 1, 'R');
$pdf->Cell($w / 2, 5, txt('Customer: ' . ($s['customer_name'] ?: 'Walk-in Customer')), 0, 0);
$pdf->Cell($w / 2, 5, txt('Mobile: ' . ($s['customer_mobile'] ?: '-')), 0, 1, 'R');
$pdf->Ln(2);
$cols = $thermal ? [0.46, 0.12, 0.18, 0.24] : [0.30, 0.08, 0.10, 0.10, 0.10, 0.10, 0.10, 0.12];
$heads = $thermal ? ['Item', 'Qty', 'Rate', 'Total'] : ['Item', 'Qty', 'Net g', 'Rate', 'Waste', 'Making', 'Tax', 'Total'];
$pdf->SetFont('Arial', 'B', 7);
$pdf->SetFillColor(240);
foreach ($heads as $i => $h)
    $pdf->Cell($w * $cols[$i], 6, txt($h), 1, 0, 'C', true);
$pdf->Ln();
$pdf->SetFont('Arial', '', 7);
foreach ($items as $i) {
    $vals = $thermal ? [txt($i['item_name']), $i['quantity'], number_format((float) $i['metal_rate'], 2), number_format((float) $i['line_total'], 2)] : [txt($i['item_name']), $i['quantity'], $i['net_weight'], number_format((float) $i['metal_rate'], 2), number_format((float) $i['wastage_amount'], 2), number_format((float) $i['making_charge'], 2), number_format((float) $i['tax_amount'], 2), number_format((float) $i['line_total'], 2)];
    foreach ($vals as $j => $v)
        $pdf->Cell($w * $cols[$j], 6, txt($v), 1, 0, $j === 0 ? 'L' : 'R');
    $pdf->Ln();
}
$pdf->Ln(2);
if ($ex) {
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(0, 5, txt('Exchange Details'), 0, 1);
    $pdf->SetFont('Arial', '', 7);
    foreach ($ex as $x)
        $pdf->Cell(0, 4, txt($x['item_name'] . ' | ' . $x['eligible_weight'] . ' g x Rs. ' . number_format((float) $x['rate_per_gram'], 2) . ' = Rs. ' . number_format((float) $x['exchange_value'], 2)), 0, 1);
}
if ($claims) {
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(0, 5, txt('Gold Gram Claims'), 0, 1);
    $pdf->SetFont('Arial', '', 7);
    foreach ($claims as $c)
        $pdf->Cell(0, 4, txt(($c['group_name'] ?: 'Chit') . ' ' . $c['ticket_no'] . ' | ' . number_format((float) $c['claim_grams'], 6) . ' g x Rs. ' . number_format((float) $c['rate_per_gram'], 2) . ' = Rs. ' . number_format((float) $c['claim_amount'], 2)), 0, 1);
}
$pdf->Ln(2);
$labelW = $thermal ? $w * .58 : $w * .72;
$valueW = $w - $labelW;
$rows = [['Subtotal', $s['subtotal']], ['Discount', -$s['discount_amount']], ['CGST', $s['cgst_amount']], ['SGST', $s['sgst_amount']], ['Exchange', -$s['exchange_amount']], ['Gold Claim', -$s['chit_claim_amount']], ['Net Payable', $s['net_payable_amount']], ['Paid', $s['paid_amount']], ['Balance', $s['balance_amount']]];
foreach ($rows as $idx => $r) {
    $pdf->SetFont('Arial', $idx >= 6 ? 'B' : '', 8);
    $pdf->Cell($labelW, 5, txt($r[0]), 0, 0, 'R');
    $pdf->Cell($valueW, 5, txt('Rs. ' . number_format((float) $r[1], 2)), 0, 1, 'R');
}
if ($pays) {
    $pdf->Ln(2);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(0, 5, txt('Payments'), 0, 1);
    $pdf->SetFont('Arial', '', 7);
    foreach ($pays as $p)
        $pdf->Cell(0, 4, txt($p['method_name'] . ' - Rs. ' . number_format((float) $p['amount'], 2) . ($p['reference_no'] ? ' (' . $p['reference_no'] . ')' : '')), 0, 1);
}
if (!empty($set['terms_conditions'])) {
    $pdf->Ln(3);
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->Cell(0, 4, txt('Terms & Conditions'), 0, 1);
    $pdf->SetFont('Arial', '', 6);
    $pdf->MultiCell(0, 3, txt($set['terms_conditions']));
}
if (!empty($set['upi_id'])) {
    $pdf->Ln(2);
    $pdf->SetFont('Arial', '', 7);
    $pdf->Cell(0, 4, txt('UPI: ' . $set['upi_id']), 0, 1);
}
$disp = (isset($_GET['inline']) && $_GET['inline'] == '1') ? 'I' : 'D';
$pdf->Output($disp, 'invoice-' . $s['invoice_no'] . '.pdf');