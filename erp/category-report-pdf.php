<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));

foreach ([
    __DIR__ . '/config/config.php',
    __DIR__ . '/config.php',
    __DIR__ . '/includes/config.php',
    __DIR__ . '/super-admin/includes/config.php'
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

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    die('Session expired.');
}

foreach ([
    __DIR__ . '/vendor/autoload.php',
    __DIR__ . '/fpdf/fpdf.php',
    __DIR__ . '/includes/fpdf/fpdf.php',
    __DIR__ . '/libs/fpdf/fpdf.php'
] as $fpdfFile) {
    if (is_file($fpdfFile)) {
        require_once $fpdfFile;
        if (class_exists('FPDF')) {
            break;
        }
    }
}

if (!class_exists('FPDF')) {
    die('FPDF library not found.');
}

function categoryReportPermission(mysqli $conn, string $action): bool
{
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin') {
        return true;
    }

    $fieldMap = [
        'open' => 'can_open',
        'view' => 'can_view'
    ];
    $field = $fieldMap[$action] ?? '';
    if ($field === '') {
        return false;
    }

    $sessionPermissions = $_SESSION['permissions'] ?? [];
    foreach (['perm.products.categories', 'perm.products'] as $key) {
        if (isset($sessionPermissions[$key][$field])) {
            return (int)$sessionPermissions[$key][$field] === 1;
        }
    }

    $businessId = (int)($_SESSION['business_id'] ?? 0);
    $roleId = (int)($_SESSION['role_id'] ?? 0);
    if ($businessId <= 0 || $roleId <= 0) {
        return false;
    }

    $sql = "SELECT rp.`{$field}`
            FROM role_permissions rp
            INNER JOIN permissions p ON p.id = rp.permission_id
            WHERE rp.business_id = ?
              AND rp.role_id = ?
              AND p.is_active = 1
              AND p.permission_code IN ('perm.products.categories','perm.products')
            ORDER BY FIELD(p.permission_code,'perm.products.categories','perm.products')
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ii', $businessId, $roleId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row[$field] ?? 0) === 1;
}

function pdfText($value): string
{
    $value = str_replace(['₹', '–', '—', '•'], ['Rs. ', '-', '-', '-'], (string)($value ?? ''));
    $converted = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $value);
    return $converted !== false ? $converted : $value;
}

function tableExists(mysqli $conn, string $table): bool
{
    $safe = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $result && $result->num_rows > 0;
}

if (!categoryReportPermission($conn, 'open') || !categoryReportPermission($conn, 'view')) {
    http_response_code(403);
    die('Access denied. You do not have permission to print categories.');
}

$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? 0);
if ($businessId <= 0) {
    die('A valid business must be selected.');
}

$businessName = (string)($_SESSION['business_name'] ?? 'Jewellery ERP');
$branchName = (string)($_SESSION['branch_name'] ?? '');
$businessAddress = '';
$businessMobile = '';
$businessGstin = '';

if (tableExists($conn, 'businesses')) {
    $stmt = $conn->prepare('SELECT business_name, legal_name, mobile, gstin FROM businesses WHERE id=? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('i', $businessId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $businessName = (string)($row['business_name'] ?: $row['legal_name'] ?: $businessName);
            $businessMobile = (string)($row['mobile'] ?? '');
            $businessGstin = (string)($row['gstin'] ?? '');
        }
    }
}

if ($branchId > 0 && tableExists($conn, 'branches')) {
    $stmt = $conn->prepare('SELECT branch_name, address_line1, address_line2, city, state, pincode, mobile, gstin FROM branches WHERE id=? AND business_id=? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('ii', $branchId, $businessId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $branchName = (string)($row['branch_name'] ?? $branchName);
            $businessAddress = trim(implode(', ', array_filter([
                $row['address_line1'] ?? '',
                $row['address_line2'] ?? '',
                $row['city'] ?? '',
                $row['state'] ?? '',
                $row['pincode'] ?? ''
            ])));
            if (!empty($row['mobile'])) {
                $businessMobile = (string)$row['mobile'];
            }
            if (!empty($row['gstin'])) {
                $businessGstin = (string)$row['gstin'];
            }
        }
    }
}

$categories = [];
$sql = "SELECT c.id, c.category_code, c.category_name, c.description, c.sort_order, c.is_active,
               p.category_name AS parent_name,
               COUNT(pr.id) AS product_count,
               COALESCE(SUM(pr.gross_weight), 0) AS total_gross_weight,
               COALESCE(SUM(pr.net_weight), 0) AS total_net_weight
        FROM product_categories c
        LEFT JOIN product_categories p
               ON p.id = c.parent_id
              AND p.business_id = c.business_id
        LEFT JOIN products pr
               ON pr.category_id = c.id
              AND pr.business_id = c.business_id
        WHERE c.business_id = ?
        GROUP BY c.id, c.category_code, c.category_name, c.description, c.sort_order, c.is_active, p.category_name
        ORDER BY c.sort_order ASC, c.category_name ASC";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    die('Unable to prepare category report.');
}
$stmt->bind_param('i', $businessId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $categories[] = $row;
}
$stmt->close();

class CategoryReportPDF extends FPDF
{
    public $businessName = '';
    public $branchName = '';
    public $businessAddress = '';
    public $businessMobile = '';
    public $businessGstin = '';

    public function Header()
    {
        $this->SetTextColor(35, 35, 35);
        $this->SetFont('Arial', 'B', 15);
        $this->Cell(0, 7, pdfText(strtoupper($this->businessName)), 0, 1, 'C');

        $sub = trim($this->branchName);
        if ($sub !== '') {
            $this->SetFont('Arial', 'B', 8);
            $this->Cell(0, 4.5, pdfText($sub), 0, 1, 'C');
        }

        $contactParts = [];
        if ($this->businessAddress !== '') {
            $contactParts[] = $this->businessAddress;
        }
        if ($this->businessMobile !== '') {
            $contactParts[] = 'Mobile: ' . $this->businessMobile;
        }
        if ($this->businessGstin !== '') {
            $contactParts[] = 'GSTIN: ' . $this->businessGstin;
        }
        if ($contactParts) {
            $this->SetFont('Arial', '', 7);
            $this->MultiCell(0, 3.8, pdfText(implode(' | ', $contactParts)), 0, 'C');
        }

        $this->Ln(1.5);
        $this->SetFillColor(123, 31, 58);
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Arial', 'B', 11);
        $this->Cell(0, 8, 'CATEGORY REPORT', 0, 1, 'C', true);

        $this->SetTextColor(70, 70, 70);
        $this->SetFont('Arial', '', 7);
        $this->Cell(0, 5, 'Generated: ' . date('d-m-Y h:i A'), 0, 1, 'R');
        $this->Ln(1);
    }

    public function Footer()
    {
        $this->SetY(-10);
        $this->SetFont('Arial', '', 7);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 5, 'Page ' . $this->PageNo(), 0, 0, 'C');
    }
}

$pdf = new CategoryReportPDF('L', 'mm', 'A4');
$pdf->businessName = $businessName;
$pdf->branchName = $branchName;
$pdf->businessAddress = $businessAddress;
$pdf->businessMobile = $businessMobile;
$pdf->businessGstin = $businessGstin;
$pdf->SetMargins(8, 8, 8);
$pdf->SetAutoPageBreak(true, 14);
$pdf->AddPage();

$headers = ['S.No', 'Category', 'Code', 'Parent', 'Products', 'Gross Wt.', 'Net Wt.', 'Sort', 'Status'];
// Match the full printable width used by the report title/navigation bar.
// A4 landscape = 297 mm; with 8 mm left/right margins the usable width is 281 mm.
$tableWidth = 281;
$tableX = 8;
$widths = [10, 56, 30, 44, 22, 30, 30, 20, 39]; // total = 281 mm

$drawHeader = function () use ($pdf, $headers, $widths, $tableX) {
    $pdf->SetX($tableX);
    $pdf->SetFillColor(123, 31, 58);
    $pdf->SetDrawColor(190, 160, 120);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 6.8);
    foreach ($headers as $index => $header) {
        $pdf->Cell($widths[$index], 8, pdfText($header), 1, 0, 'C', true);
    }
    $pdf->Ln();
};

$drawHeader();
$pdf->SetFont('Arial', '', 6.8);
$pdf->SetTextColor(35, 35, 35);
$pdf->SetDrawColor(220, 205, 180);

$totalProducts = 0;
$totalGross = 0.0;
$totalNet = 0.0;

foreach ($categories as $index => $category) {
    if ($pdf->GetY() > 188) {
        $pdf->AddPage();
        $drawHeader();
        $pdf->SetFont('Arial', '', 6.8);
        $pdf->SetTextColor(35, 35, 35);
        $pdf->SetDrawColor(220, 205, 180);
    }

    $products = (int)($category['product_count'] ?? 0);
    $gross = (float)($category['total_gross_weight'] ?? 0);
    $net = (float)($category['total_net_weight'] ?? 0);
    $totalProducts += $products;
    $totalGross += $gross;
    $totalNet += $net;

    $values = [
        (string)($index + 1),
        (string)($category['category_name'] ?? '-'),
        !empty($category['category_code']) ? (string)$category['category_code'] : '-',
        !empty($category['parent_name']) ? (string)$category['parent_name'] : 'Main Category',
        (string)$products,
        number_format($gross, 3) . ' g',
        number_format($net, 3) . ' g',
        (string)((int)($category['sort_order'] ?? 0)),
        (int)($category['is_active'] ?? 0) === 1 ? 'Active' : 'Inactive'
    ];

    $pdf->SetX($tableX);
    foreach ($values as $col => $value) {
        $align = in_array($col, [0, 4, 5, 6, 7], true) ? 'C' : 'L';
        $pdf->Cell($widths[$col], 7, pdfText($value), 1, 0, $align);
    }
    $pdf->Ln();
}

if (!$categories) {
    $pdf->SetFont('Arial', 'I', 8);
    $pdf->SetX($tableX);
    $pdf->Cell($tableWidth, 12, 'No category data available.', 1, 1, 'C');
}

$pdf->Ln(3);
$pdf->SetFillColor(248, 236, 208);
$pdf->SetTextColor(80, 17, 38);
$pdf->SetFont('Arial', 'B', 7.5);
// Summary uses exactly the same 281 mm width as the table and report title bar.
$summaryWidths = [40, 24, 40, 24, 36, 43, 32, 42]; // total = 281 mm
$pdf->SetX($tableX);
$pdf->Cell($summaryWidths[0], 6.5, 'Total Categories', 1, 0, 'L', true);
$pdf->Cell($summaryWidths[1], 6.5, (string)count($categories), 1, 0, 'C', true);
$pdf->Cell($summaryWidths[2], 6.5, 'Linked Products', 1, 0, 'L', true);
$pdf->Cell($summaryWidths[3], 6.5, (string)$totalProducts, 1, 0, 'C', true);
$pdf->Cell($summaryWidths[4], 6.5, 'Gross Weight', 1, 0, 'L', true);
$pdf->Cell($summaryWidths[5], 6.5, number_format($totalGross, 3) . ' g', 1, 0, 'R', true);
$pdf->Cell($summaryWidths[6], 6.5, 'Net Weight', 1, 0, 'L', true);
$pdf->Cell($summaryWidths[7], 6.5, number_format($totalNet, 3) . ' g', 1, 1, 'R', true);

$inline = isset($_GET['inline']) && $_GET['inline'] === '1';
$mode = $inline ? 'I' : 'D';
$pdf->Output($mode, 'category-report-' . date('Ymd-His') . '.pdf');