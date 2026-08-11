<?php
if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

foreach ([
    __DIR__ . '/config/config.php',
    __DIR__ . '/config.php',
    __DIR__ . '/includes/config.php',
    __DIR__ . '/super-admin/includes/config.php',
] as $configFile) {
    if (is_file($configFile)) {
        require_once $configFile;
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

    if (!$s->execute()) throw new RuntimeException($s->error);

    $r = $s->get_result();
    $o = [];
    while ($x = $r->fetch_assoc()) $o[] = $x;
    $s->close();
    return $o;
}

function tableExists(mysqli $conn, string $table): bool
{
    static $cache = [];
    $key = strtolower($table);
    if (array_key_exists($key, $cache)) return $cache[$key];

    $safe = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safe}'");
    $cache[$key] = (bool)($result && $result->num_rows > 0);
    return $cache[$key];
}

function columnExists(mysqli $conn, string $table, string $column): bool
{
    static $cache = [];
    $key = strtolower($table . '.' . $column);
    if (array_key_exists($key, $cache)) return $cache[$key];

    $safeTable = $conn->real_escape_string($table);
    $safeColumn = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    $cache[$key] = (bool)($result && $result->num_rows > 0);
    return $cache[$key];
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

function dateOut($value): string
{
    if (empty($value)) return '-';
    $time = strtotime((string)$value);
    return $time ? date('d-m-Y', $time) : (string)$value;
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

function isMobileOrTablet(): bool
{
    $ua = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));

    if ($ua === '') return false;

    $patterns = [
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

    foreach ($patterns as $pattern) {
        if (strpos($ua, $pattern) !== false) return true;
    }

    return false;
}

$pawnId = max(0, (int)($_GET['id'] ?? 0));
$ref = trim((string)($_GET['ref'] ?? ''));

if ($pawnId <= 0 || $ref === '') {
    die('Invalid receipt link.');
}

$sql = "SELECT
            p.*,
            COALESCE(c.customer_name, '') AS customer_name,
            COALESCE(c.customer_code, '') AS customer_code,
            COALESCE(c.mobile, '') AS mobile,
            COALESCE(c.alternate_mobile, '') AS alternate_mobile,
            COALESCE(c.email, '') AS email,
            COALESCE(c.gstin, '') AS customer_gstin,
            COALESCE(c.address_line1, '') AS address_line1,
            COALESCE(c.address_line2, '') AS address_line2,
            COALESCE(c.city, '') AS city,
            COALESCE(c.state, '') AS state,
            COALESCE(c.pincode, '') AS pincode,
            COALESCE(pc.category_name, '') AS category_name,
            COALESCE(pc.category_code, '') AS category_code,
            COALESCE(br.branch_name, '') AS branch_name,
            COALESCE(pm.method_name, '') AS disbursement_method,
            COALESCE(b.business_name, '') AS business_name,
            COALESCE(b.legal_name, '') AS legal_name,
            COALESCE(b.mobile, '') AS business_mobile,
            COALESCE(b.email, '') AS business_email,
            COALESCE(b.website, '') AS website,
            COALESCE(b.gstin, '') AS business_gstin,
            COALESCE(br.mobile, '') AS branch_mobile,
            COALESCE(br.email, '') AS branch_email,
            COALESCE(br.address_line1, '') AS branch_address1,
            COALESCE(br.address_line2, '') AS branch_address2,
            COALESCE(br.city, '') AS branch_city,
            COALESCE(br.state, '') AS branch_state,
            COALESCE(br.pincode, '') AS branch_pincode,
            COALESCE(br.gstin, '') AS branch_gstin
        FROM pawn_entries p
        LEFT JOIN customers c
            ON c.id = p.customer_id
           AND c.business_id = p.business_id
        LEFT JOIN pawn_categories pc
            ON pc.id = p.pawn_category_id
           AND pc.business_id = p.business_id
        LEFT JOIN branches br
            ON br.id = p.branch_id
           AND br.business_id = p.business_id
        LEFT JOIN payment_methods pm
            ON pm.id = p.disbursement_payment_method_id
           AND pm.business_id = p.business_id
        LEFT JOIN businesses b
            ON b.id = p.business_id
        WHERE p.id = ?
          AND p.pawn_no = ?
        LIMIT 1";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die('Unable to load receipt: ' . $conn->error);
}
$stmt->bind_param('is', $pawnId, $ref);
$stmt->execute();
$pawn = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$pawn) die('Receipt not found.');

$businessId = (int)($pawn['business_id'] ?? 0);
$branchId = (int)($pawn['branch_id'] ?? 0);

$items = [];
$itemStmt = $conn->prepare(
    "SELECT pi.*, COALESCE(m.metal_name, '') AS metal_name
     FROM pawn_items pi
     LEFT JOIN metals m
       ON m.id = pi.metal_id
      AND m.business_id = pi.business_id
     WHERE pi.pawn_entry_id = ?
       AND pi.business_id = ?
     ORDER BY pi.id"
);
if ($itemStmt) {
    $itemStmt->bind_param('ii', $pawnId, $businessId);
    $itemStmt->execute();
    $result = $itemStmt->get_result();
    while ($row = $result->fetch_assoc()) $items[] = $row;
    $itemStmt->close();
}

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

$principal = (float)($pawn['principal_amount'] ?? 0);
$documentCharge = (float)($pawn['document_charge'] ?? 0);
$otherCharge = (float)($pawn['other_charge'] ?? 0);
$disbursement = isset($pawn['disbursement_amount'])
    ? (float)$pawn['disbursement_amount']
    : max(0, $principal - $documentCharge - $otherCharge);

$paid = (float)($pawn['total_principal_paid'] ?? 0);
$balance = (float)($pawn['balance_principal'] ?? max(0, $principal - $paid));
$interestRate = (float)($pawn['interest_percent'] ?? 0);
$interestPeriod = (string)($pawn['interest_period'] ?? 'Monthly');
$interestMethod = (string)($pawn['interest_method'] ?? 'Simple');

$baseForInterest = strtolower($interestMethod) === 'flat' ? $principal : $balance;
$cycle = (string)($pawn['interest_collection_cycle'] ?? 'Monthly');
$cycleMonths = max(1, (int)($pawn['interest_cycle_months'] ?? 1));
$monthsMap = [
    'Monthly' => 1,
    'Quarterly' => 3,
    'Half-Yearly' => 6,
    'Yearly' => 12,
    'Custom' => $cycleMonths
];
$months = $monthsMap[$cycle] ?? 1;

if ($cycle === 'At Closure') {
    $multiplier = 1;
} elseif ($interestPeriod === 'Daily') {
    $multiplier = $months * 30;
} elseif ($interestPeriod === 'Yearly') {
    $multiplier = $months / 12;
} else {
    $multiplier = $months;
}

$estimatedInterest = $baseForInterest * ($interestRate / 100) * $multiplier;

class PawnReceiptPDF extends FPDF
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

    function info($x, $y, $w, $label, $value, $lw = 30)
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

$pdf = new PawnReceiptPDF('P', 'mm', 'A4');
$pdf->SetMargins(8, 8, 8);
$pdf->SetAutoPageBreak(true, 17);
$pdf->footerText = (string)($set['footer_text'] ?? 'This is a system-generated pawn receipt.');
$pdf->watermark = strtoupper((string)($pawn['business_name'] ?? 'JEWELLERY'));
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
        preg_replace('/[^A-Za-z]/', '', (string)($pawn['business_name'] ?? 'JW')),
        0,
        3
    ));

    $pdf->Cell(23, 23, txt($initials ?: 'JW'), 1, 0, 'C', true);
}

$name = $pawn['business_name'] ?: $pawn['legal_name'] ?: 'Jewellery Business';
$address = trim(implode(', ', array_filter([
    $pawn['branch_address1'] ?? '',
    $pawn['branch_address2'] ?? '',
    $pawn['branch_city'] ?? '',
    $pawn['branch_state'] ?? '',
    $pawn['branch_pincode'] ?? ''
])));
$contact = trim(implode(' | ', array_filter([
    ($pawn['branch_mobile'] ?? '') ?: ($pawn['business_mobile'] ?? ''),
    ($pawn['branch_email'] ?? '') ?: ($pawn['business_email'] ?? ''),
    $pawn['website'] ?? ''
])));
$gst = ($pawn['branch_gstin'] ?? '') ?: ($pawn['business_gstin'] ?? '');

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
$pdf->Cell(49, 7, txt('PAWN LOAN RECEIPT'), 0, 1, 'R');

$pdf->SetX(153);
$pdf->SetFillColor(...$GS);
$pdf->SetDrawColor(...$G);
$pdf->SetTextColor(...$P);
$pdf->SetFont('Arial', 'B', 6);
$pdf->Cell(49, 6, txt('ORIGINAL FOR CUSTOMER'), 1, 1, 'C', true);

$pdf->SetDrawColor(...$P);
$pdf->SetLineWidth(.8);
$pdf->Line(8, 34, 202, 34);
$pdf->SetLineWidth(.2);

$boxY = 38;
$boxW = 97;
$boxH = 50;
$pdf->SetDrawColor(...$B);
$pdf->Rect(8, $boxY, $boxW, $boxH);
$pdf->Rect(105, $boxY, $boxW, $boxH);

$pdf->section(8, $boxY, $boxW, 'CUSTOMER DETAILS');
$pdf->section(105, $boxY, $boxW, 'PAWN DETAILS');

$customerAddress = trim(implode(', ', array_filter([
    $pawn['address_line1'] ?? '',
    $pawn['address_line2'] ?? '',
    $pawn['city'] ?? '',
    $pawn['state'] ?? '',
    $pawn['pincode'] ?? ''
])));

$y = $boxY + 8;
$y = $pdf->info(10, $y, 93, 'Customer Name', $pawn['customer_name'] ?: '-');
$y = $pdf->info(10, $y, 93, 'Customer Code', $pawn['customer_code'] ?: '-');
$y = $pdf->info(10, $y, 93, 'Mobile Number', $pawn['mobile'] ?: '-');

if (!empty($pawn['alternate_mobile'])) {
    $y = $pdf->info(10, $y, 93, 'Alternate Mobile', (string)$pawn['alternate_mobile']);
}

if (!empty($pawn['email'])) {
    $y = $pdf->info(10, $y, 93, 'Email', (string)$pawn['email']);
}

if (!empty($pawn['id_proof_type'])) {
    $y = $pdf->info(10, $y, 93, 'ID Proof', (string)$pawn['id_proof_type']);
}

if (!empty($pawn['id_proof_number'])) {
    $y = $pdf->info(10, $y, 93, 'ID Proof Number', (string)$pawn['id_proof_number']);
}

$y = $pdf->info(10, $y, 93, 'Address', $customerAddress ?: '-');

$y = $boxY + 8;
$y = $pdf->info(107, $y, 93, 'Pawn Number', (string)$pawn['pawn_no']);
$y = $pdf->info(107, $y, 93, 'Pawn Date', dateOut($pawn['pawn_date'] ?? ''));
$y = $pdf->info(107, $y, 93, 'Status', (string)($pawn['status'] ?? '-'));
$y = $pdf->info(107, $y, 93, 'Category', (string)($pawn['category_name'] ?: '-'));
$y = $pdf->info(107, $y, 93, 'Branch', (string)($pawn['branch_name'] ?: '-'));
$y = $pdf->info(107, $y, 93, 'Loan Type', (string)(($pawn['loan_type'] ?? '') ?: 'General'));
$y = $pdf->info(
    107,
    $y,
    93,
    'Due Date',
    !empty($pawn['due_date']) ? dateOut($pawn['due_date']) : 'At Closure'
);

$pdf->SetY(92);

$pdf->SetFillColor(...$GS);
$pdf->SetTextColor(...$P);
$pdf->SetFont('Arial', 'B', 8);
$pdf->Cell($W, 7, txt('LOAN & DISBURSEMENT'), 1, 1, 'L', true);

$pdf->SetTextColor(36);
$pdf->SetFont('Arial', '', 7);

$pdf->Cell(49, 6, txt('Principal Amount'), 1, 0, 'L');
$pdf->Cell(48, 6, txt('Rs. ' . number_format($principal, 2)), 1, 0, 'R');
$pdf->Cell(49, 6, txt('Document Charge'), 1, 0, 'L');
$pdf->Cell(48, 6, txt('Rs. ' . number_format($documentCharge, 2)), 1, 1, 'R');

$pdf->Cell(49, 6, txt('Other Charge'), 1, 0, 'L');
$pdf->Cell(48, 6, txt('Rs. ' . number_format($otherCharge, 2)), 1, 0, 'R');
$pdf->Cell(49, 6, txt('Amount Given'), 1, 0, 'L');
$pdf->Cell(48, 6, txt('Rs. ' . number_format($disbursement, 2)), 1, 1, 'R');

$pdf->Cell(49, 6, txt('Disbursement Method'), 1, 0, 'L');
$pdf->Cell(48, 6, txt($pawn['disbursement_method'] ?: '-'), 1, 0, 'L');
$pdf->Cell(49, 6, txt('Payment Reference'), 1, 0, 'L');
$pdf->Cell(48, 6, txt($pawn['payment_reference'] ?: '-'), 1, 1, 'L');

$pdf->Ln(3);

$pdf->SetFillColor(...$GS);
$pdf->SetTextColor(...$P);
$pdf->SetFont('Arial', 'B', 8);
$pdf->Cell($W, 7, txt('INTEREST DETAILS'), 1, 1, 'L', true);

$pdf->SetTextColor(36);
$pdf->SetFont('Arial', '', 7);

$interestText = number_format($interestRate, 3) . '% ' . $interestPeriod;
$pdf->Cell(48, 6, txt('Interest Rate'), 1, 0, 'L');
$pdf->Cell(49, 6, txt($interestText), 1, 0, 'L');
$pdf->Cell(48, 6, txt('Interest Method'), 1, 0, 'L');
$pdf->Cell(49, 6, txt($interestMethod), 1, 1, 'L');

$pdf->Cell(48, 6, txt('Collection Cycle'), 1, 0, 'L');
$pdf->Cell(49, 6, txt($cycle), 1, 0, 'L');
$pdf->Cell(48, 6, txt('Est. Cycle Interest'), 1, 0, 'L');
$pdf->Cell(49, 6, txt('Rs. ' . number_format($estimatedInterest, 2)), 1, 1, 'R');

$pdf->Ln(4);

$heads = ['S.No', 'Description', 'Metal', 'Qty', 'Purity', 'Gross g', 'Stone g', 'Net g', 'Rate/g', 'Estimated'];
$ws = [9, 38, 20, 12, 15, 19, 18, 18, 21, 24];

$drawHead = function () use ($pdf, $heads, $ws, $P, $D) {
    $pdf->SetFillColor(...$P);
    $pdf->SetDrawColor(...$D);
    $pdf->SetTextColor(255);
    $pdf->SetFont('Arial', 'B', 6.1);

    foreach ($heads as $i => $h) {
        $pdf->Cell($ws[$i], 8, txt($h), 1, 0, 'C', true);
    }

    $pdf->Ln();
    $pdf->SetTextColor(36);
};

$drawHead();
$pdf->SetFont('Arial', '', 6.4);
$pdf->SetDrawColor(...$B);

if (!$items) {
    $pdf->Cell($W, 9, txt('No pawn items found.'), 1, 1, 'C');
} else {
    foreach ($items as $index => $item) {
        if ($pdf->GetY() > 235) {
            $pdf->AddPage();
            $pdf->SetY(14);
            $drawHead();
            $pdf->SetFont('Arial', '', 6.4);
        }

        $vals = [
            $index + 1,
            (string)($item['item_description'] ?? ''),
            (string)($item['metal_name'] ?? ''),
            (string)($item['quantity'] ?? ''),
            (string)($item['purity'] ?? ''),
            number_format((float)($item['gross_weight'] ?? 0), 3),
            number_format((float)($item['stone_weight'] ?? 0), 3),
            number_format((float)($item['net_weight'] ?? 0), 3),
            number_format((float)($item['rate_per_gram'] ?? 0), 2),
            number_format((float)($item['estimated_value'] ?? 0), 2)
        ];

        foreach ($vals as $i => $v) {
            $align = $i >= 5 ? 'R' : ($i === 1 ? 'L' : 'C');
            $pdf->Cell($ws[$i], 8, txt($v), 1, 0, $align);
        }
        $pdf->Ln();
    }
}

$pdf->Ln(4);
$pdf->need(55);

$summaryY = $pdf->GetY();
$notesW = 112;

$pdf->SetXY(8, $summaryY);
$pdf->SetFillColor(...$GS);
$pdf->SetTextColor(...$P);
$pdf->SetFont('Arial', 'B', 7);
$pdf->Cell($notesW, 6, txt('PAWN / REMARKS'), 1, 1, 'L', true);

$pdf->SetX(8);
$pdf->SetTextColor(36);
$pdf->SetFont('Arial', '', 6.5);

$remarks = trim((string)($pawn['remarks'] ?? ''));
$notes = [
    'Loan Type: ' . (($pawn['loan_type'] ?? '') ?: 'General'),
    'Category: ' . (($pawn['category_name'] ?? '') ?: '-') .
        (!empty($pawn['category_code']) ? ' (' . $pawn['category_code'] . ')' : ''),
    'Interest: ' . number_format($interestRate, 3) . '% ' . $interestPeriod,
    'Interest Method: ' . $interestMethod,
    'Cycle: ' . $cycle,
    'Due Date: ' . (!empty($pawn['due_date']) ? dateOut($pawn['due_date']) : 'At Closure')
];

if (!empty($pawn['id_proof_type']) || !empty($pawn['id_proof_number'])) {
    $notes[] = 'ID Proof: ' .
        (($pawn['id_proof_type'] ?? '') ?: '-') .
        (!empty($pawn['id_proof_number']) ? ' / ' . $pawn['id_proof_number'] : '');
}


$pdf->MultiCell($notesW, 4.4, txt(implode("\n", $notes)), 1, 'L');
$notesBottom = $pdf->GetY();

$totals = [
    ['Principal Amount', $principal],
    ['Principal Paid', $paid],
    ['Balance Principal', $balance]
];

$pdf->SetXY(124, $summaryY);
foreach ($totals as $r) {
    $grand = $r[0] === 'Balance Principal';

    if ($grand) {
        $pdf->SetFillColor(...$P);
        $pdf->SetTextColor(255);
        $pdf->SetFont('Arial', 'B', 8);
    } else {
        $pdf->SetTextColor(36);
        $pdf->SetFont('Arial', $r[0] === 'Principal Paid' ? 'B' : '', 6.7);
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
$pdf->MultiCell($W, 5, txt(amountWords((int)round($disbursement))), 1, 'L');

$pdf->Ln(16);
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

/*
 * Delivery behavior:
 * - Desktop/laptop => inline browser preview
 * - Mobile/tablet => force file download
 * - ?download=1 => always download
 * - ?inline=1 => always preview
 */
$forceDownload = isset($_GET['download']) && $_GET['download'] === '1';
$forceInline = isset($_GET['inline']) && $_GET['inline'] === '1';

if ($forceDownload) {
    $disp = 'D';
} elseif ($forceInline) {
    $disp = 'I';
} else {
    $disp = isMobileOrTablet() ? 'D' : 'I';
}

$file = 'pawn-receipt-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)$pawn['pawn_no']) . '.pdf';
$pdf->Output($disp, $file);
