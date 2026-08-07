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
foreach ([__DIR__ . '/vendor/autoload.php', __DIR__ . '/fpdf/fpdf.php', __DIR__ . '/includes/fpdf/fpdf.php', __DIR__ . '/libs/fpdf/fpdf.php'] as $f) {
    if (is_file($f)) {
        require_once $f;
        break;
    }
}
if (!class_exists('FPDF'))
    die('FPDF library not found.');
function rows(mysqli $c, string $sql, string $types = '', array $params = []): array
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
    $v = str_replace(['₹', '–', '—'], ['Rs. ', '-', '-'], (string) ($v ?? ''));
    $x = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $v);
    return $x !== false ? $x : $v;
}
$businessId = (int) ($_SESSION['business_id'] ?? 0);
$estimateId = (int) ($_GET['id'] ?? 0);
if ($businessId <= 0 || $estimateId <= 0)
    die('Invalid estimate.');
try {
    $er = rows($conn, "SELECT e.*,c.customer_code,c.email,c.gstin customer_gstin,c.address_line1,c.address_line2,c.city,c.state,c.pincode,b.business_name,b.legal_name,b.mobile business_mobile,b.email business_email,b.website,b.gstin business_gstin,br.branch_name,br.mobile branch_mobile,br.email branch_email,br.address_line1 branch_address1,br.address_line2 branch_address2,br.city branch_city,br.state branch_state,br.pincode branch_pincode,br.gstin branch_gstin FROM estimates e LEFT JOIN customers c ON c.id=e.customer_id LEFT JOIN businesses b ON b.id=e.business_id LEFT JOIN branches br ON br.id=e.branch_id WHERE e.id=? AND e.business_id=? LIMIT 1", 'ii', [$estimateId, $businessId]);
    if (!$er)
        die('Estimate not found.');
    $e = $er[0];
    $items = rows($conn, 'SELECT * FROM estimate_items WHERE estimate_id=? AND business_id=? ORDER BY sort_order,id', 'ii', [$estimateId, $businessId]);
    $pays = rows($conn, 'SELECT ep.*,pm.method_name FROM estimate_payments ep LEFT JOIN payment_methods pm ON pm.id=ep.payment_method_id WHERE ep.estimate_id=? AND ep.business_id=? ORDER BY ep.id', 'ii', [$estimateId, $businessId]);
    $claims = rows($conn, "SELECT ec.*,cg.group_name,cg.group_no,cm.ticket_no FROM estimate_chit_claims ec LEFT JOIN chit_groups cg ON cg.id=ec.chit_group_id LEFT JOIN chit_members cm ON cm.id=ec.chit_member_id WHERE ec.estimate_id=? AND ec.business_id=?", 'ii', [$estimateId, $businessId]);
    $ex = rows($conn, 'SELECT * FROM estimate_exchange_items WHERE estimate_id=? AND business_id=? ORDER BY id', 'ii', [$estimateId, $businessId]);
    $settings = rows($conn, "SELECT * FROM invoice_settings WHERE business_id=? AND (branch_id=? OR branch_id IS NULL) AND document_type='Estimate' AND is_active=1 ORDER BY (branch_id=?) DESC,is_default DESC,id DESC LIMIT 1", 'iii', [$businessId, (int) $e['branch_id'], (int) $e['branch_id']]);
    if (!$settings)
        $settings = rows($conn, "SELECT * FROM invoice_settings WHERE business_id=? AND (branch_id=? OR branch_id IS NULL) AND document_type='Invoice' AND is_active=1 ORDER BY (branch_id=?) DESC,is_default DESC,id DESC LIMIT 1", 'iii', [$businessId, (int) $e['branch_id'], (int) $e['branch_id']]);
    $set = $settings ? $settings[0] : [];
} catch (Throwable $x) {
    die('Unable to build estimate: ' . htmlspecialchars($x->getMessage()));
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
class EstimatePDF extends FPDF
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
$pdf = new EstimatePDF($orientation, 'mm', $size);
$pdf->footerText = (string) ($set['footer_text'] ?? '');
$pdf->SetMargins(8, 8, 8);
$pdf->SetAutoPageBreak(true, 16);
$pdf->AddPage();
$w = $pdf->GetPageWidth() - 16;
$thermal = is_array($size) && $size[0] <= 80;
$logo = (string) ($set['invoice_logo_path'] ?? '');
if (!empty($set['show_business_logo']) && $logo !== '' && is_file(__DIR__ . '/' . $logo)) {
    $pdf->Image(__DIR__ . '/' . $logo, 8, 8, $thermal ? 14 : 22);
}
$pdf->SetFont('Arial', 'B', $thermal ? 12 : 16);
$pdf->Cell(0, 7, txt($e['business_name'] ?? 'Business'), 0, 1, 'C');
$pdf->SetFont('Arial', '', 7);
$address = trim(implode(', ', array_filter([$e['branch_address1'], $e['branch_address2'], $e['branch_city'], $e['branch_state'], $e['branch_pincode']])));
if ($address !== '')
    $pdf->MultiCell(0, 4, txt($address), 0, 'C');
if (!empty($set['show_gstin']))
    $pdf->Cell(0, 4, txt('GSTIN: ' . ($e['branch_gstin'] ?: $e['business_gstin'])), 0, 1, 'C');
$pdf->Ln(2);
$pdf->SetDrawColor(180);
$pdf->Line(8, $pdf->GetY(), $pdf->GetPageWidth() - 8, $pdf->GetY());
$pdf->Ln(3);
$pdf->SetFont('Arial', 'B', $thermal ? 10 : 13);
$pdf->Cell(0, 6, txt($set['header_text'] ?? 'ESTIMATE'), 0, 1, 'C');
$pdf->SetFont('Arial', '', 8);
$pdf->Cell($w / 2, 5, txt('Estimate: ' . $e['estimate_no']), 0, 0);
$pdf->Cell($w / 2, 5, txt('Date: ' . date('d-m-Y', strtotime($e['estimate_date']))), 0, 1, 'R');
$pdf->Cell($w / 2, 5, txt('Customer: ' . ($e['customer_name'] ?: 'Walk-in Customer')), 0, 0);
$pdf->Cell($w / 2, 5, txt('Mobile: ' . ($e['customer_mobile'] ?: '-')), 0, 1, 'R');
$pdf->Ln(2);
$cols = $thermal ? [.46, .12, .18, .24] : [.30, .08, .10, .10, .10, .10, .10, .12];
$heads = $thermal ? ['Item', 'Qty', 'Rate', 'Total'] : ['Item', 'Qty', 'Net g', 'Rate', 'Waste', 'Making', 'Tax', 'Total'];
$pdf->SetFont('Arial', 'B', 7);
$pdf->SetFillColor(240);
foreach ($heads as $i => $h)
    $pdf->Cell($w * $cols[$i], 6, txt($h), 1, 0, 'C', true);
$pdf->Ln();
$pdf->SetFont('Arial', '', 7);
foreach ($items as $i) {
    $vals = $thermal ? [$i['item_name'], $i['quantity'], number_format((float) $i['metal_rate'], 2), number_format((float) $i['line_total'], 2)] : [$i['item_name'], $i['quantity'], $i['net_weight'], number_format((float) $i['metal_rate'], 2), number_format((float) $i['wastage_amount'], 2), number_format((float) $i['making_charge'], 2), number_format((float) $i['tax_amount'], 2), number_format((float) $i['line_total'], 2)];
    foreach ($vals as $j => $v)
        $pdf->Cell($w * $cols[$j], 6, txt($v), 1, 0, $j === 0 ? 'L' : 'R');
    $pdf->Ln();
}
if ($ex) {
    $pdf->Ln(2);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(0, 5, txt('Proposed Exchange Items'), 0, 1);
    $pdf->SetFont('Arial', '', 7);
    foreach ($ex as $x)
        $pdf->Cell(0, 4, txt($x['item_name'] . ' | ' . $x['eligible_weight'] . ' g x Rs. ' . number_format((float) $x['rate_per_gram'], 2) . ' = Rs. ' . number_format((float) $x['exchange_value'], 2)), 0, 1);
}
if ($claims) {
    $pdf->Ln(2);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(0, 5, txt('Proposed Gold Gram Claims'), 0, 1);
    $pdf->SetFont('Arial', '', 7);
    foreach ($claims as $c)
        $pdf->Cell(0, 4, txt(($c['group_name'] ?: 'Chit') . ' ' . $c['ticket_no'] . ' | ' . number_format((float) $c['claim_grams'], 6) . ' g x Rs. ' . number_format((float) $c['rate_per_gram'], 2) . ' = Rs. ' . number_format((float) $c['claim_amount'], 2)), 0, 1);
}
$pdf->Ln(2);
$labelW = $thermal ? $w * .58 : $w * .72;
$valueW = $w - $labelW;
$summary = [['Subtotal', $e['subtotal']], ['Discount', -$e['discount_amount']], ['CGST', $e['cgst_amount']], ['SGST', $e['sgst_amount']], ['Exchange', -$e['exchange_amount']], ['Gold Claim', -$e['chit_claim_amount']], ['Net Estimate', $e['net_estimate_amount']], ['Proposed Paid', $e['proposed_paid_amount']], ['Proposed Balance', $e['proposed_balance_amount']]];
foreach ($summary as $idx => $r) {
    $pdf->SetFont('Arial', $idx >= 6 ? 'B' : '', 8);
    $pdf->Cell($labelW, 5, txt($r[0]), 0, 0, 'R');
    $pdf->Cell($valueW, 5, txt('Rs. ' . number_format((float) $r[1], 2)), 0, 1, 'R');
}
if ($pays) {
    $pdf->Ln(2);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(0, 5, txt('Proposed Payments'), 0, 1);
    $pdf->SetFont('Arial', '', 7);
    foreach ($pays as $p)
        $pdf->Cell(0, 4, txt(($p['method_name'] ?: 'Payment') . ' - Rs. ' . number_format((float) $p['amount'], 2) . ($p['reference_no'] ? ' (' . $p['reference_no'] . ')' : '')), 0, 1);
}
if (!empty($e['notes'])) {
    $pdf->Ln(2);
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->Cell(0, 4, txt('Notes'), 0, 1);
    $pdf->SetFont('Arial', '', 7);
    $pdf->MultiCell(0, 4, txt($e['notes']));
}
if (!empty($set['terms_conditions'])) {
    $pdf->Ln(3);
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->Cell(0, 4, txt('Terms & Conditions'), 0, 1);
    $pdf->SetFont('Arial', '', 6);
    $pdf->MultiCell(0, 3, txt($set['terms_conditions']));
}
$disp = (isset($_GET['inline']) && $_GET['inline'] === '1') ? 'I' : 'D';
$pdf->Output($disp, 'estimate-' . $e['estimate_no'] . '.pdf');