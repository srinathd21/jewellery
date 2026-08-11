<?php
if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));

foreach ([
    __DIR__ . '/config/config.php',
    __DIR__ . '/config.php',
    __DIR__ . '/includes/config.php',
    __DIR__ . '/super-admin/includes/config.php'
] as $f) {
    if (is_file($f)) {
        require_once $f;
        break;
    }
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database configuration is not available.');
}
$conn->set_charset('utf8mb4');

foreach ([
    __DIR__ . '/vendor/autoload.php',
    __DIR__ . '/fpdf/fpdf.php',
    __DIR__ . '/includes/fpdf/fpdf.php',
    __DIR__ . '/libs/fpdf/fpdf.php'
] as $f) {
    if (is_file($f)) {
        require_once $f;
        break;
    }
}
if (!class_exists('FPDF')) die('FPDF library not found.');

function allRows(mysqli $c, string $sql, string $types = '', array $params = []): array
{
    $s = $c->prepare($sql);
    if (!$s) throw new RuntimeException($c->error);

    if ($types !== '') {
        $a = [$types];
        foreach ($params as $k => $v) {
            $a[] = &$params[$k];
        }
        call_user_func_array([$s, 'bind_param'], $a);
    }

    if (!$s->execute()) {
        throw new RuntimeException($s->error);
    }

    $r = $s->get_result();
    $o = [];
    while ($x = $r->fetch_assoc()) {
        $o[] = $x;
    }
    $s->close();
    return $o;
}

function tableExists(mysqli $c, string $table): bool
{
    $safe = $c->real_escape_string($table);
    $r = $c->query("SHOW TABLES LIKE '{$safe}'");
    return $r && $r->num_rows > 0;
}

function columnExists(mysqli $c, string $table, string $column): bool
{
    $safeTable = $c->real_escape_string($table);
    $safeColumn = $c->real_escape_string($column);
    $r = $c->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    return $r && $r->num_rows > 0;
}

function txt($v): string
{
    $v = str_replace(
        ['₹', '–', '—', '•'],
        ['Rs. ', '-', '-', '-'],
        (string)($v ?? '')
    );
    $x = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $v);
    return $x !== false ? $x : $v;
}

function amountWords(int $n): string
{
    if ($n <= 0) return 'Rupees Zero Only';

    $o = [
        '', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight',
        'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen',
        'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'
    ];
    $t = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    $small = function ($v) use ($o, $t) {
        $w = '';
        if ($v >= 100) {
            $w = $o[intdiv($v, 100)] . ' Hundred';
            $v %= 100;
            if ($v) $w .= ' ';
        }
        if ($v) {
            $w .= $v < 20
                ? $o[$v]
                : $t[intdiv($v, 10)] . ($v % 10 ? ' ' . $o[$v % 10] : '');
        }
        return trim($w);
    };

    $p = [];
    foreach ([[10000000, 'Crore'], [100000, 'Lakh'], [1000, 'Thousand']] as $part) {
        $d = $part[0];
        $l = $part[1];
        $q = intdiv($n, $d);
        if ($q) {
            $p[] = $small($q) . ' ' . $l;
            $n %= $d;
        }
    }
    if ($n) $p[] = $small($n);

    return 'Rupees ' . implode(' ', $p) . ' Only';
}

$collectionId = (int)($_GET['collection_id'] ?? 0);
$receipt = trim((string)($_GET['receipt'] ?? ''));

if ($collectionId <= 0 || $receipt === '') {
    die('Invalid invoice link.');
}

$collectionPk = columnExists($conn, 'chit_collections', 'id')
    ? 'id'
    : (columnExists($conn, 'chit_collections', 'collection_id') ? 'collection_id' : 'id');

$sql = "SELECT
            cc.*,
            ci.installment_no,
            ci.due_date,
            cm.ticket_no,
            cm.join_date,
            cm.status AS member_status,
            c.customer_code,
            c.customer_name,
            c.mobile,
            c.email,
            c.gstin AS customer_gstin,
            c.address_line1,
            c.address_line2,
            c.city,
            c.state,
            c.pincode,
            cg.group_no,
            cg.group_name,
            cg.chit_type,
            cg.installment_amount,
            cg.chit_value,
            cg.total_months,
            pm.method_name,
            m.metal_name,
            m.metal_code,
            b.business_name,
            b.legal_name,
            b.mobile AS business_mobile,
            b.email AS business_email,
            b.website,
            b.gstin AS business_gstin,
            br.branch_name,
            br.mobile AS branch_mobile,
            br.email AS branch_email,
            br.address_line1 AS branch_address1,
            br.address_line2 AS branch_address2,
            br.city AS branch_city,
            br.state AS branch_state,
            br.pincode AS branch_pincode,
            br.gstin AS branch_gstin
        FROM chit_collections cc
        INNER JOIN chit_installments ci ON ci.id = cc.chit_installment_id
        INNER JOIN chit_members cm ON cm.id = cc.chit_member_id
        INNER JOIN customers c ON c.id = cm.customer_id
        INNER JOIN chit_groups cg ON cg.id = cm.chit_group_id
        LEFT JOIN payment_methods pm ON pm.id = cc.payment_method_id
        LEFT JOIN metals m ON m.id = cc.gold_metal_id AND m.business_id = cc.business_id
        LEFT JOIN businesses b ON b.id = cc.business_id
        LEFT JOIN branches br ON br.id = cc.branch_id
        WHERE cc.`{$collectionPk}` = ?
          AND cc.receipt_no = ?
        LIMIT 1";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die('Unable to prepare invoice.');
}
$stmt->bind_param('is', $collectionId, $receipt);
$stmt->execute();
$invoice = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$invoice) {
    die('Invoice not found.');
}

$businessId = (int)($invoice['business_id'] ?? $_SESSION['business_id'] ?? 0);
$branchId = (int)($invoice['branch_id'] ?? $_SESSION['branch_id'] ?? 0);

$settings = [];
if (tableExists($conn, 'invoice_settings')) {
    try {
        $settings = allRows(
            $conn,
            "SELECT *
             FROM invoice_settings
             WHERE business_id=?
               AND (branch_id=? OR branch_id IS NULL)
               AND document_type='Invoice'
               AND is_active=1
             ORDER BY (branch_id=?) DESC, is_default DESC, id DESC
             LIMIT 1",
            'iii',
            [$businessId, $branchId, $branchId]
        );
    } catch (Throwable $e) {
        $settings = [];
    }
}
$set = $settings[0] ?? [];

class ChitInvoicePDF extends FPDF
{
    public $footerText = '';
    public $watermark = '';
    public $watermarkLogo = '';
    protected $extgstates = [];

    function SetAlpha($alpha, $blendMode = 'Normal')
    {
        $alpha = max(0, min(1, (float)$alpha));
        $this->extgstates[] = [
            'ca' => $alpha,
            'CA' => $alpha,
            'BM' => '/' . $blendMode
        ];
        $this->SetExtGState(count($this->extgstates));
    }

    function SetExtGState($stateNumber)
    {
        $this->_out(sprintf('/GS%d gs', $stateNumber));
    }

    function _enddoc()
    {
        if (!empty($this->extgstates) && version_compare($this->PDFVersion, '1.4', '<')) {
            $this->PDFVersion = '1.4';
        }
        parent::_enddoc();
    }

    function _putextgstates()
    {
        foreach ($this->extgstates as $index => $state) {
            $this->_newobj();
            $this->extgstates[$index]['n'] = $this->n;
            $this->_put('<</Type /ExtGState');
            $this->_put(sprintf('/ca %.3F', $state['ca']));
            $this->_put(sprintf('/CA %.3F', $state['CA']));
            $this->_put('/BM ' . $state['BM']);
            $this->_put('>>');
            $this->_put('endobj');
        }
    }

    function _putresourcedict()
    {
        parent::_putresourcedict();
        if (!empty($this->extgstates)) {
            $this->_put('/ExtGState <<');
            foreach ($this->extgstates as $index => $state) {
                $this->_put('/GS' . ($index + 1) . ' ' . $state['n'] . ' 0 R');
            }
            $this->_put('>>');
        }
    }

    function _putresources()
    {
        $this->_putextgstates();
        parent::_putresources();
    }

    function Header()
    {
        if ($this->watermarkLogo !== '' && is_file($this->watermarkLogo)) {
            $maxWidth = 82;
            $maxHeight = 82;
            $imageWidth = $maxWidth;
            $imageHeight = $maxHeight;
            $imageInfo = @getimagesize($this->watermarkLogo);

            if (is_array($imageInfo) && !empty($imageInfo[0]) && !empty($imageInfo[1])) {
                $ratio = $imageInfo[0] / $imageInfo[1];
                if ($ratio >= 1) {
                    $imageWidth = $maxWidth;
                    $imageHeight = $maxWidth / $ratio;
                } else {
                    $imageHeight = $maxHeight;
                    $imageWidth = $maxHeight * $ratio;
                }
            }

            $x = ($this->GetPageWidth() - $imageWidth) / 2;
            $y = ($this->GetPageHeight() - $imageHeight) / 2;

            $this->SetAlpha(0.075);
            $this->Image($this->watermarkLogo, $x, $y, $imageWidth, $imageHeight);
            $this->SetAlpha(1);
        } elseif ($this->watermark !== '') {
            $this->SetFont('Arial', 'B', 28);
            $this->SetTextColor(248, 239, 242);
            $this->SetXY(16, 122);
            $this->Cell(178, 15, txt($this->watermark), 0, 0, 'C');
        }
    }

    function Footer()
    {
        $this->SetY(-11);
        $this->SetFont('Arial', '', 6);
        $this->SetTextColor(105);

        if ($this->footerText !== '') {
            $this->MultiCell(0, 3, txt($this->footerText), 0, 'C');
        }

        $this->SetY(-5);
        $this->Cell(0, 3, 'Page ' . $this->PageNo(), 0, 0, 'C');
    }

    function section($x, $y, $w, $title)
    {
        $this->SetXY($x, $y);
        $this->SetFillColor(123, 31, 58);
        $this->SetTextColor(255);
        $this->SetFont('Arial', 'B', 7.5);
        $this->Cell($w, 6, txt($title), 1, 1, 'L', true);
        $this->SetTextColor(36);
    }

    function info($x, $y, $w, $label, $value, $lw = 29)
    {
        $this->SetXY($x, $y);
        $this->SetFont('Arial', 'B', 7);
        $this->Cell($lw, 4.3, txt($label), 0, 0);
        $this->SetFont('Arial', '', 7);
        $this->MultiCell($w - $lw, 4.3, txt(': ' . $value), 0, 'L');
        return max($y + 4.3, $this->GetY());
    }

    function need($h)
    {
        if ($this->GetY() + $h > $this->GetPageHeight() - 17) {
            $this->AddPage();
            $this->SetY(14);
        }
    }
}

$logo = (string)($set['invoice_logo_path'] ?? '');
$logoFile = $logo !== '' ? __DIR__ . '/' . ltrim($logo, '/') : '';

$pdf = new ChitInvoicePDF('P', 'mm', 'A4');
$pdf->SetMargins(8, 8, 8);
$pdf->SetAutoPageBreak(true, 17);
$pdf->footerText = (string)($set['footer_text'] ?? 'This is a system-generated chit payment invoice.');
$pdf->watermark = strtoupper((string)($invoice['business_name'] ?? 'JEWELLERY'));
$pdf->watermarkLogo = is_file($logoFile) ? $logoFile : '';
$pdf->AddPage();

$P = [123, 31, 58];
$D = [80, 17, 38];
$G = [200, 148, 36];
$GS = [248, 236, 208];
$B = [216, 201, 172];
$W = 194;

if (!empty($set['show_business_logo']) && is_file($logoFile)) {
    $pdf->Image($logoFile, 8, 8, 23, 23);
} else {
    $pdf->SetXY(8, 8);
    $pdf->SetFillColor(...$GS);
    $pdf->SetDrawColor(...$G);
    $pdf->SetTextColor(...$P);
    $pdf->SetFont('Arial', 'B', 12);
    $initials = strtoupper(substr(
        preg_replace('/[^A-Za-z]/', '', (string)($invoice['business_name'] ?? 'JW')),
        0,
        3
    ));
    $pdf->Cell(23, 23, txt($initials ?: 'JW'), 1, 0, 'C', true);
}

$name = $invoice['business_name'] ?: $invoice['legal_name'] ?: 'Jewellery Business';
$address = trim(implode(', ', array_filter([
    $invoice['branch_address1'] ?? '',
    $invoice['branch_address2'] ?? '',
    $invoice['branch_city'] ?? '',
    $invoice['branch_state'] ?? '',
    $invoice['branch_pincode'] ?? ''
])));
$contact = trim(implode(' | ', array_filter([
    ($invoice['branch_mobile'] ?? '') ?: ($invoice['business_mobile'] ?? ''),
    ($invoice['branch_email'] ?? '') ?: ($invoice['business_email'] ?? ''),
    $invoice['website'] ?? ''
])));
$gst = ($invoice['branch_gstin'] ?? '') ?: ($invoice['business_gstin'] ?? '');

$pdf->SetXY(34, 8);
$pdf->SetTextColor(...$P);
$pdf->SetFont('Arial', 'B', 16);
$pdf->Cell(116, 7, txt(strtoupper($name)), 0, 1, 'C');

$pdf->SetX(34);
$pdf->SetTextColor(...$G);
$pdf->SetFont('Arial', 'B', 7);
$pdf->Cell(116, 4, txt('GOLD - SILVER - DIAMOND - PRECIOUS JEWELLERY'), 0, 1, 'C');

$pdf->SetX(34);
$pdf->SetTextColor(68);
$pdf->SetFont('Arial', '', 6.5);
if ($address !== '') $pdf->MultiCell(116, 3.4, txt($address), 0, 'C');
$pdf->SetX(34);
if ($contact !== '') $pdf->MultiCell(116, 3.4, txt($contact), 0, 'C');
$pdf->SetX(34);
if (!empty($set['show_gstin']) && $gst) {
    $pdf->Cell(116, 3.4, txt('GSTIN: ' . $gst), 0, 1, 'C');
}

$pdf->SetXY(153, 8);
$pdf->SetTextColor(...$P);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(49, 7, txt('CHIT PAYMENT INVOICE'), 0, 1, 'R');
$pdf->SetX(153);
$pdf->SetFillColor(...$GS);
$pdf->SetDrawColor(...$G);
$pdf->SetTextColor(...$P);
$pdf->SetFont('Arial', 'B', 6);
$pdf->Cell(49, 6, txt('ORIGINAL FOR RECIPIENT'), 1, 1, 'C', true);

$pdf->SetDrawColor(...$P);
$pdf->SetLineWidth(.8);
$pdf->Line(8, 34, 202, 34);
$pdf->SetLineWidth(.2);

$boxY = 38;
$boxW = 97;
$boxH = 37;
$pdf->SetDrawColor(...$B);
$pdf->Rect(8, $boxY, $boxW, $boxH);
$pdf->Rect(105, $boxY, $boxW, $boxH);

$pdf->section(8, $boxY, $boxW, 'CUSTOMER DETAILS');
$pdf->section(105, $boxY, $boxW, 'CHIT DETAILS');

$customerAddress = trim(implode(', ', array_filter([
    $invoice['address_line1'] ?? '',
    $invoice['address_line2'] ?? '',
    $invoice['city'] ?? '',
    $invoice['state'] ?? '',
    $invoice['pincode'] ?? ''
])));

$y = $boxY + 8;
$y = $pdf->info(10, $y, 93, 'Customer Name', $invoice['customer_name'] ?: '-');
$y = $pdf->info(10, $y, 93, 'Customer Code', $invoice['customer_code'] ?: '-');
$y = $pdf->info(10, $y, 93, 'Mobile Number', $invoice['mobile'] ?: '-');
$y = $pdf->info(10, $y, 93, 'Address', $customerAddress ?: '-');
$y = $pdf->info(10, $y, 93, 'GSTIN', $invoice['customer_gstin'] ?: 'Not Applicable');

$collectionDate = !empty($invoice['collection_date'])
    ? date('d-m-Y', strtotime($invoice['collection_date']))
    : '-';

$y = $boxY + 8;
$y = $pdf->info(107, $y, 93, 'Receipt Number', (string)$invoice['receipt_no']);
$y = $pdf->info(107, $y, 93, 'Collection Date', $collectionDate);
$y = $pdf->info(
    107,
    $y,
    93,
    'Group',
    trim((string)($invoice['group_name'] ?? '')) . ' / ' . trim((string)($invoice['group_no'] ?? ''))
);
$y = $pdf->info(107, $y, 93, 'Ticket Number', (string)($invoice['ticket_no'] ?? '-'));
$y = $pdf->info(107, $y, 93, 'Chit Type', (string)($invoice['chit_type'] ?? '-'));
$y = $pdf->info(107, $y, 93, 'Chit Value', 'Rs. ' . number_format((float)($invoice['chit_value'] ?? 0), 2));

$pdf->SetY(79);

$heads = ['S.No', 'Installment', 'Due Date', 'Payment Method', 'Receipt No', 'Amount'];
$ws = [12, 25, 29, 47, 45, 36];

$drawHead = function () use ($pdf, $heads, $ws, $P, $D) {
    $pdf->SetFillColor(...$P);
    $pdf->SetDrawColor(...$D);
    $pdf->SetTextColor(255);
    $pdf->SetFont('Arial', 'B', 6.8);

    foreach ($heads as $i => $h) {
        $pdf->Cell($ws[$i], 9, txt($h), 1, 0, 'C', true);
    }
    $pdf->Ln();
    $pdf->SetTextColor(36);
};

$drawHead();

$dueDate = !empty($invoice['due_date'])
    ? date('d-m-Y', strtotime($invoice['due_date']))
    : '-';
$paidAmount = (float)($invoice['paid_amount'] ?? 0);

$vals = [
    '1',
    '#' . (string)($invoice['installment_no'] ?? '-'),
    $dueDate,
    (string)($invoice['method_name'] ?: '-'),
    (string)$invoice['receipt_no'],
    'Rs. ' . number_format($paidAmount, 2)
];

$pdf->SetFont('Arial', '', 7.2);
$pdf->SetDrawColor(...$B);
foreach ($vals as $i => $v) {
    $align = $i === 5 ? 'R' : 'C';
    $pdf->Cell($ws[$i], 9, txt($v), 1, 0, $align);
}
$pdf->Ln(12);

$isGold = strcasecmp((string)($invoice['chit_type'] ?? ''), 'Gold') === 0;
if ($isGold) {
    $pdf->need(24);
    $pdf->SetFillColor(...$GS);
    $pdf->SetTextColor(...$P);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell($W, 7, txt('GOLD SAVINGS DETAILS'), 1, 1, 'L', true);

    $pdf->SetTextColor(36);
    $pdf->SetFont('Arial', '', 7.2);

    $goldRate = (float)($invoice['gold_rate_per_gram'] ?? 0);
    $goldWeight = (float)($invoice['gold_weight_grams'] ?? 0);
    $metal = (string)(($invoice['metal_name'] ?? '') ?: ($invoice['metal_code'] ?? '-'));

    $pdf->Cell(65, 7, txt('Gold Rate: ' . ($goldRate > 0 ? 'Rs. ' . number_format($goldRate, 2) . '/g' : '-')), 1, 0, 'L');
    $pdf->Cell(65, 7, txt('Gold Weight: ' . number_format($goldWeight, 6) . ' g'), 1, 0, 'L');
    $pdf->Cell(64, 7, txt('Metal: ' . $metal), 1, 1, 'L');

    $pdf->Ln(3);
}

$pdf->need(67);
$summaryY = $pdf->GetY();
$notesW = 112;

$pdf->SetXY(8, $summaryY);
$pdf->SetFillColor(...$GS);
$pdf->SetTextColor(...$P);
$pdf->SetFont('Arial', 'B', 7);
$pdf->Cell($notesW, 6, txt('PAYMENT / SCHEME DETAILS'), 1, 1, 'L', true);

$pdf->SetX(8);
$pdf->SetTextColor(36);
$pdf->SetFont('Arial', '', 6.5);

$schemeLines = [
    'Group: ' . (($invoice['group_name'] ?? '') ?: '-') . ' (' . (($invoice['group_no'] ?? '') ?: '-') . ')',
    'Ticket: ' . (($invoice['ticket_no'] ?? '') ?: '-'),
    'Installment: #' . (($invoice['installment_no'] ?? '') ?: '-') . ' of ' . (($invoice['total_months'] ?? '') ?: '-'),
    'Installment Amount: Rs. ' . number_format((float)($invoice['installment_amount'] ?? 0), 2),
    'Member Status: ' . (($invoice['member_status'] ?? '') ?: '-')
];

$pdf->MultiCell($notesW, 4.4, txt(implode("\n", $schemeLines)), 1, 'L');
$notesBottom = $pdf->GetY();

$dueAmount = (float)($invoice['due_amount'] ?? 0);
$discountAmount = (float)($invoice['discount_amount'] ?? 0);
$penaltyAmount = (float)($invoice['penalty_amount'] ?? 0);
$netAmount = (float)($invoice['net_amount'] ?? $invoice['paid_amount'] ?? 0);

$totals = [
    ['Due Amount', $dueAmount],
    ['Paid Amount', $paidAmount],
    ['Discount', $discountAmount],
    ['Penalty', $penaltyAmount],
    ['Net Paid', $netAmount]
];

$pdf->SetXY(124, $summaryY);
foreach ($totals as $r) {
    $grand = $r[0] === 'Net Paid';

    if ($grand) {
        $pdf->SetFillColor(...$P);
        $pdf->SetTextColor(255);
        $pdf->SetFont('Arial', 'B', 8);
    } else {
        $pdf->SetTextColor(36);
        $pdf->SetFont('Arial', in_array($r[0], ['Paid Amount'], true) ? 'B' : '', 6.7);
    }

    $pdf->Cell(44, 6, txt($r[0]), 1, 0, 'L', $grand);
    $pdf->Cell(34, 6, txt('Rs. ' . number_format((float)$r[1], 2)), 1, 1, 'R', $grand);
    $pdf->SetX(124);
}
$totalsBottom = $pdf->GetY();

$pdf->SetY(max($notesBottom, $totalsBottom) + 3);
$pdf->SetFillColor(255, 250, 240);
$pdf->SetTextColor(...$D);
$pdf->SetFont('Arial', 'B', 7);
$pdf->Cell($W, 6, txt('AMOUNT IN WORDS'), 1, 1, 'L', true);
$pdf->SetFont('Arial', '', 7);
$pdf->MultiCell($W, 5, txt(amountWords((int)round($netAmount))), 1, 'L');

$pdf->Ln(3);
$pdf->need(42);
$payY = $pdf->GetY();
$half = 95;

$pdf->SetXY(8, $payY);
$pdf->SetFillColor(...$GS);
$pdf->SetTextColor(...$P);
$pdf->SetFont('Arial', 'B', 7);
$pdf->Cell($half, 6, txt('PAYMENT DETAILS'), 1, 1, 'L', true);
$pdf->SetTextColor(36);
$pdf->SetFont('Arial', '', 6.5);

$paymentLines = [
    'Payment Method: ' . (($invoice['method_name'] ?? '') ?: '-'),
    'Receipt No: ' . (string)$invoice['receipt_no'],
    'Collection Date: ' . $collectionDate,
    'Net Paid: Rs. ' . number_format($netAmount, 2)
];

$pdf->SetX(8);
$pdf->MultiCell($half, 4.5, txt(implode("\n", $paymentLines)), 1, 'L');
$payBottom = $pdf->GetY();

$pdf->SetXY(107, $payY);
$pdf->SetFillColor(...$GS);
$pdf->SetTextColor(...$P);
$pdf->SetFont('Arial', 'B', 7);
$pdf->Cell($half, 6, txt('SCHEME DETAILS'), 1, 1, 'L', true);
$pdf->SetTextColor(36);
$pdf->SetFont('Arial', '', 6.5);

$schemeRight = [
    'Chit Type: ' . (($invoice['chit_type'] ?? '') ?: '-'),
    'Group / Ticket: ' . (($invoice['group_no'] ?? '') ?: '-') . ' / ' . (($invoice['ticket_no'] ?? '') ?: '-'),
    'Chit Value: Rs. ' . number_format((float)($invoice['chit_value'] ?? 0), 2),
    'Member Status: ' . (($invoice['member_status'] ?? '') ?: '-')
];

$pdf->SetX(107);
$pdf->MultiCell($half, 4.5, txt(implode("\n", $schemeRight)), 1, 'L');
$schemeBottom = $pdf->GetY();

$pdf->SetY(max($payBottom, $schemeBottom) + 16);
$pdf->need(22);
$sigY = $pdf->GetY();
$sw = $W / 3;

$pdf->SetDrawColor(90);
$pdf->Line(12, $sigY, 8 + $sw - 4, $sigY);
$pdf->Line(8 + $sw + 4, $sigY, 8 + $sw * 2 - 4, $sigY);
$pdf->Line(8 + $sw * 2 + 4, $sigY, 198, $sigY);

$pdf->SetY($sigY + 2);
$pdf->SetFont('Arial', 'B', 6.5);
$pdf->Cell($sw, 5, txt('Customer Signature'), 0, 0, 'C');
$pdf->Cell($sw, 5, txt('Checked By'), 0, 0, 'C');
$pdf->Cell($sw, 5, txt('For ' . $name), 0, 1, 'C');

function isMobileOrTablet(): bool
{
    $ua = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));

    if ($ua === '') {
        return false;
    }

    $mobileTokens = [
        'android',
        'iphone',
        'ipad',
        'ipod',
        'mobile',
        'tablet',
        'kindle',
        'silk/',
        'playbook',
        'windows phone',
        'opera mini',
        'opera mobi'
    ];

    foreach ($mobileTokens as $token) {
        if (strpos($ua, $token) !== false) {
            return true;
        }
    }

    return false;
}

$file = 'chit-invoice-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)$invoice['receipt_no']) . '.pdf';

/*
 * Desktop / laptop: show PDF inline in browser.
 * Mobile / tablet: download PDF directly.
 * ?download=1 always forces download.
 * ?inline=1 always forces inline preview.
 */
if (isset($_GET['download']) && $_GET['download'] === '1') {
    $disp = 'D';
} elseif (isset($_GET['inline']) && $_GET['inline'] === '1') {
    $disp = 'I';
} else {
    $disp = isMobileOrTablet() ? 'D' : 'I';
}

$pdf->Output($disp, $file);
