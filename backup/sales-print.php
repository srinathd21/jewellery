<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Kolkata');
require_once __DIR__ . '/includes/config.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not available. Check includes/config.php');
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn->set_charset('utf8mb4');

$fpdfPath = __DIR__ . '/libs/fpdf.php';
if (!file_exists($fpdfPath)) {
    $fpdfPath = __DIR__ . '/libs/fpdf/fpdf.php';
}
if (!file_exists($fpdfPath)) {
    die('FPDF file not found. Expected libs/fpdf.php');
}
require_once $fpdfPath;

if (!function_exists('tableExists')) {
    function tableExists(mysqli $conn, string $tableName): bool
    {
        $safe = $conn->real_escape_string($tableName);
        $res = $conn->query("SHOW TABLES LIKE '{$safe}'");
        return $res && $res->num_rows > 0;
    }
}

if (!function_exists('hasColumn')) {
    function hasColumn(mysqli $conn, string $table, string $column): bool
    {
        $table = $conn->real_escape_string($table);
        $column = $conn->real_escape_string($column);
        $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
        return $res && $res->num_rows > 0;
    }
}

function cleanPdfText($text): string
{
    $text = (string)($text ?? '');
    $text = str_replace(["\r", "\n", "₹"], [' ', ' ', 'Rs.'], $text);
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $text);
        if ($converted !== false) {
            return $converted;
        }
    }
    return $text;
}

function moneyPdf($amount): string
{
    return number_format((float)$amount, 2, '.', ',');
}

if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    die('Session expired. Please login again.');
}

$businessId = (int)($_SESSION['business_id'] ?? 0);
if ($businessId <= 0) {
    die('Business session not found. Please login again.');
}

$saleId = (int)($_GET['id'] ?? 0);
if ($saleId <= 0) {
    die('Invalid sale id.');
}

if (!tableExists($conn, 'sales') || !tableExists($conn, 'sale_items')) {
    die('Required sales tables not found.');
}

$sale = null;
$stmt = $conn->prepare("SELECT s.*, pm.method_name FROM sales s LEFT JOIN payment_methods pm ON pm.id = s.payment_method_id WHERE s.id = ? AND s.business_id = ? LIMIT 1");
if ($stmt) {
    $stmt->bind_param('ii', $saleId, $businessId);
    $stmt->execute();
    $res = $stmt->get_result();
    $sale = $res ? $res->fetch_assoc() : null;
    $stmt->close();
}
if (!$sale) {
    die('Sale not found.');
}

$items = [];
$stmt = $conn->prepare("SELECT * FROM sale_items WHERE sale_id = ? AND business_id = ? ORDER BY id ASC");
if ($stmt) {
    $stmt->bind_param('ii', $saleId, $businessId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && $row = $res->fetch_assoc()) {
        $items[] = $row;
    }
    $stmt->close();
}

$payments = [];
if (tableExists($conn, 'sale_payments') && tableExists($conn, 'payment_methods')) {
    $stmt = $conn->prepare("SELECT sp.*, pm.method_name FROM sale_payments sp LEFT JOIN payment_methods pm ON pm.id = sp.payment_method_id WHERE sp.sale_id = ? ORDER BY sp.id ASC");
    if ($stmt) {
        $stmt->bind_param('i', $saleId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && $row = $res->fetch_assoc()) {
            $payments[] = $row;
        }
        $stmt->close();
    }
}

$company = [
    'company_name' => 'Company Name',
    'owner_name' => '',
    'mobile' => '',
    'email' => '',
    'address_line1' => '',
    'address_line2' => '',
    'city' => '',
    'state' => '',
    'pincode' => '',
    'gstin' => '',
    'pan_no' => '',
    'bill_footer' => 'Thank you for your business.',
    'terms_conditions' => ''
];
if (tableExists($conn, 'company_settings')) {
    $stmt = $conn->prepare("SELECT * FROM company_settings WHERE business_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $businessId);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && ($row = $res->fetch_assoc())) {
            $company = array_merge($company, $row);
        }
        $stmt->close();
    }
}

class SalesInvoicePDF extends FPDF
{
    public array $company = [];
    public array $sale = [];
    public bool $cancelled = false;

    public function Header(): void
    {
        $this->SetDrawColor(50, 50, 50);
        $this->SetLineWidth(0.2);
        $this->Rect(8, 8, 194, 281);

        $this->SetFont('Arial', 'B', 15);
        $this->Cell(0, 7, cleanPdfText($this->company['company_name'] ?? 'Company Name'), 0, 1, 'C');
        $this->SetFont('Arial', '', 8.5);

        $addressParts = array_filter([
            $this->company['address_line1'] ?? '',
            $this->company['address_line2'] ?? '',
            $this->company['city'] ?? '',
            $this->company['state'] ?? '',
            $this->company['pincode'] ?? ''
        ]);
        if (!empty($addressParts)) {
            $this->Cell(0, 5, cleanPdfText(implode(', ', $addressParts)), 0, 1, 'C');
        }
        $contact = trim('Mobile: ' . ($this->company['mobile'] ?? '') . '  Email: ' . ($this->company['email'] ?? ''));
        $this->Cell(0, 5, cleanPdfText($contact), 0, 1, 'C');

        $tax = [];
        if (!empty($this->company['gstin'])) $tax[] = 'GSTIN: ' . $this->company['gstin'];
        if (!empty($this->company['pan_no'])) $tax[] = 'PAN: ' . $this->company['pan_no'];
        if (!empty($tax)) {
            $this->Cell(0, 5, cleanPdfText(implode('   ', $tax)), 0, 1, 'C');
        }

        $this->Ln(1);
        $this->SetFont('Arial', 'B', 11);
        $this->Cell(0, 7, $this->cancelled ? 'SALES INVOICE - CANCELLED' : 'SALES INVOICE', 'TB', 1, 'C');
    }

    public function Footer(): void
    {
        $this->SetY(-18);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 5, cleanPdfText($this->company['bill_footer'] ?? 'Thank you for your business.'), 0, 1, 'C');
        $this->Cell(0, 5, 'Page ' . $this->PageNo(), 0, 0, 'C');
    }

    public function rowCell(float $w, float $h, string $txt, string $border = '1', int $ln = 0, string $align = 'L', bool $fill = false): void
    {
        $this->Cell($w, $h, cleanPdfText($txt), $border, $ln, $align, $fill);
    }
}

$pdf = new SalesInvoicePDF('P', 'mm', 'A4');
$pdf->company = $company;
$pdf->sale = $sale;
$pdf->cancelled = ((string)($sale['status'] ?? '') === 'Cancelled');
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 22);
$pdf->AddPage();

if ($pdf->cancelled) {
    $pdf->SetTextColor(180, 0, 0);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 8, 'CANCELLED BILL', 0, 1, 'C');
    $pdf->SetTextColor(0, 0, 0);
}

$pdf->SetFont('Arial', '', 9);
$billDate = !empty($sale['bill_date']) ? date('d-m-Y', strtotime($sale['bill_date'])) : '';
$billTime = substr((string)($sale['bill_time'] ?? ''), 0, 5);

$pdf->Cell(96, 8, cleanPdfText('Bill No: ' . ($sale['bill_no'] ?? '')), 1, 0, 'L');
$pdf->Cell(96, 8, cleanPdfText('Date: ' . $billDate . ' ' . $billTime), 1, 1, 'L');
$pdf->Cell(96, 8, cleanPdfText('Bill Type: ' . ($sale['bill_type'] ?? '')), 1, 0, 'L');
$pdf->Cell(96, 8, cleanPdfText('Payment Status: ' . ($sale['payment_status'] ?? '')), 1, 1, 'L');

$pdf->Ln(3);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(0, 6, 'Customer Details', 0, 1, 'L');
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(96, 8, cleanPdfText('Name: ' . (($sale['customer_name'] ?? '') ?: 'Walk-in Customer')), 1, 0, 'L');
$pdf->Cell(96, 8, cleanPdfText('Mobile: ' . ($sale['customer_mobile'] ?? '')), 1, 1, 'L');

$pdf->Ln(3);
$pdf->SetFont('Arial', 'B', 8);
$pdf->SetFillColor(240, 240, 240);
$pdf->Cell(8, 8, '#', 1, 0, 'C', true);
$pdf->Cell(48, 8, 'Item', 1, 0, 'L', true);
$pdf->Cell(15, 8, 'Purity', 1, 0, 'C', true);
$pdf->Cell(15, 8, 'Qty', 1, 0, 'R', true);
$pdf->Cell(18, 8, 'Net Wt', 1, 0, 'R', true);
$pdf->Cell(22, 8, 'Rate', 1, 0, 'R', true);
$pdf->Cell(22, 8, 'Taxable', 1, 0, 'R', true);
$pdf->Cell(20, 8, 'GST', 1, 0, 'R', true);
$pdf->Cell(24, 8, 'Total', 1, 1, 'R', true);

$pdf->SetFont('Arial', '', 8);
foreach ($items as $i => $item) {
    if ($pdf->GetY() > 260) {
        $pdf->AddPage();
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetFillColor(240, 240, 240);
        $pdf->Cell(8, 8, '#', 1, 0, 'C', true);
        $pdf->Cell(48, 8, 'Item', 1, 0, 'L', true);
        $pdf->Cell(15, 8, 'Purity', 1, 0, 'C', true);
        $pdf->Cell(15, 8, 'Qty', 1, 0, 'R', true);
        $pdf->Cell(18, 8, 'Net Wt', 1, 0, 'R', true);
        $pdf->Cell(22, 8, 'Rate', 1, 0, 'R', true);
        $pdf->Cell(22, 8, 'Taxable', 1, 0, 'R', true);
        $pdf->Cell(20, 8, 'GST', 1, 0, 'R', true);
        $pdf->Cell(24, 8, 'Total', 1, 1, 'R', true);
        $pdf->SetFont('Arial', '', 8);
    }

    $itemName = (string)($item['item_name'] ?? '');
    if (strlen($itemName) > 28) {
        $itemName = substr($itemName, 0, 25) . '...';
    }

    $pdf->Cell(8, 8, (string)($i + 1), 1, 0, 'C');
    $pdf->Cell(48, 8, cleanPdfText($itemName), 1, 0, 'L');
    $pdf->Cell(15, 8, cleanPdfText((string)($item['purity'] ?? '')), 1, 0, 'C');
    $pdf->Cell(15, 8, moneyPdf($item['qty'] ?? 0), 1, 0, 'R');
    $pdf->Cell(18, 8, moneyPdf($item['net_weight'] ?? 0), 1, 0, 'R');
    $pdf->Cell(22, 8, moneyPdf($item['rate_per_gram'] ?? 0), 1, 0, 'R');
    $pdf->Cell(22, 8, moneyPdf($item['taxable_amount'] ?? 0), 1, 0, 'R');
    $pdf->Cell(20, 8, moneyPdf($item['gst_amount'] ?? 0), 1, 0, 'R');
    $pdf->Cell(24, 8, moneyPdf($item['total_amount'] ?? 0), 1, 1, 'R');
}

$pdf->Ln(3);
$startY = $pdf->GetY();

$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(95, 6, 'Payment Details', 0, 1, 'L');
$pdf->SetFont('Arial', '', 8);
if (!empty($payments)) {
    $pdf->Cell(40, 7, 'Method', 1, 0, 'L');
    $pdf->Cell(35, 7, 'Reference', 1, 0, 'L');
    $pdf->Cell(20, 7, 'Amount', 1, 1, 'R');
    foreach ($payments as $pay) {
        $pdf->Cell(40, 7, cleanPdfText($pay['method_name'] ?? ''), 1, 0, 'L');
        $pdf->Cell(35, 7, cleanPdfText($pay['reference_no'] ?? ''), 1, 0, 'L');
        $pdf->Cell(20, 7, moneyPdf($pay['amount'] ?? 0), 1, 1, 'R');
    }
} else {
    $pdf->Cell(95, 7, cleanPdfText(($sale['method_name'] ?? 'Payment') . ' ' . ($sale['payment_reference'] ?? '')), 1, 1, 'L');
}

if (!empty($sale['notes'])) {
    $pdf->Ln(2);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(95, 6, 'Notes', 0, 1, 'L');
    $pdf->SetFont('Arial', '', 8);
    $pdf->MultiCell(95, 5, cleanPdfText($sale['notes']), 1, 'L');
}

$pdf->SetY($startY);
$pdf->SetX(115);
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(42, 7, 'Subtotal', 1, 0, 'L');
$pdf->Cell(35, 7, moneyPdf($sale['subtotal'] ?? 0), 1, 1, 'R');
$pdf->SetX(115);
$pdf->Cell(42, 7, 'Discount', 1, 0, 'L');
$pdf->Cell(35, 7, moneyPdf($sale['discount_amount'] ?? 0), 1, 1, 'R');
$pdf->SetX(115);
$pdf->Cell(42, 7, 'Taxable Amount', 1, 0, 'L');
$pdf->Cell(35, 7, moneyPdf($sale['taxable_amount'] ?? 0), 1, 1, 'R');
$pdf->SetX(115);
$pdf->Cell(42, 7, 'CGST', 1, 0, 'L');
$pdf->Cell(35, 7, moneyPdf($sale['cgst_amount'] ?? 0), 1, 1, 'R');
$pdf->SetX(115);
$pdf->Cell(42, 7, 'SGST', 1, 0, 'L');
$pdf->Cell(35, 7, moneyPdf($sale['sgst_amount'] ?? 0), 1, 1, 'R');
$pdf->SetX(115);
$pdf->Cell(42, 7, 'Round Off', 1, 0, 'L');
$pdf->Cell(35, 7, moneyPdf($sale['round_off'] ?? 0), 1, 1, 'R');
$pdf->SetX(115);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(42, 8, 'Grand Total', 1, 0, 'L');
$pdf->Cell(35, 8, moneyPdf($sale['grand_total'] ?? 0), 1, 1, 'R');
$pdf->SetX(115);
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(42, 7, 'Paid Amount', 1, 0, 'L');
$pdf->Cell(35, 7, moneyPdf($sale['paid_amount'] ?? 0), 1, 1, 'R');
$pdf->SetX(115);
$pdf->Cell(42, 7, 'Balance', 1, 0, 'L');
$pdf->Cell(35, 7, moneyPdf($sale['balance_amount'] ?? 0), 1, 1, 'R');

$pdf->Ln(10);
if (!empty($company['terms_conditions'])) {
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(0, 5, 'Terms & Conditions', 0, 1, 'L');
    $pdf->SetFont('Arial', '', 8);
    $pdf->MultiCell(0, 5, cleanPdfText($company['terms_conditions']), 0, 'L');
}

$pdf->SetY(-42);
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(96, 8, 'Customer Signature', 0, 0, 'L');
$pdf->Cell(96, 8, 'Authorised Signature', 0, 1, 'R');

$fileName = 'Sales-Invoice-' . preg_replace('/[^A-Za-z0-9_-]/', '-', (string)($sale['bill_no'] ?? $saleId)) . '.pdf';
$pdf->Output('I', $fileName);
exit;
