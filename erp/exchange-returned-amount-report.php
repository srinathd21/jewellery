<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string) ($_SESSION['timezone'] ?? 'Asia/Kolkata'));

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
    header('Location: login.php');
    exit;
}

function erEscape($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function erTableExists(mysqli $conn, string $table): bool
{
    $safe = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $result && $result->num_rows > 0;
}

function erColumnExists(mysqli $conn, string $table, string $column): bool
{
    $safeTable = $conn->real_escape_string($table);
    $safeColumn = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    return $result && $result->num_rows > 0;
}

function erBindDynamic(mysqli_stmt $stmt, string $types, array &$values): void
{
    $params = [$types];
    foreach ($values as $index => $value) {
        $params[] =& $values[$index];
    }
    call_user_func_array([$stmt, 'bind_param'], $params);
}

$businessId = (int) ($_SESSION['business_id'] ?? 0);
$branchId = (int) ($_SESSION['branch_id'] ?? ($_SESSION['default_branch_id'] ?? 0));

if ($businessId <= 0 || $branchId <= 0) {
    die('A valid business and branch must be selected.');
}

if (!erTableExists($conn, 'sale_exchange_payouts')) {
    die('sale_exchange_payouts table is not available. Create at least one excess exchange payout from Billing first.');
}

$theme = [
    'primary_color' => '#d89416',
    'primary_dark_color' => '#b86a0b',
    'primary_soft_color' => '#fff6e5',
    'sidebar_gradient_1' => '#171c21',
    'sidebar_gradient_2' => '#20272d',
    'sidebar_gradient_3' => '#101419',
    'page_background' => '#f4f3f0',
    'card_background' => '#ffffff',
    'text_color' => '#171717',
    'muted_text_color' => '#7d8794',
    'border_color' => '#e8e8e8',
    'font_family' => 'Inter',
    'heading_font_family' => 'Playfair Display',
    'border_radius_px' => 12,
    'sidebar_width_px' => 230
];

if (erTableExists($conn, 'business_theme_settings')) {
    $themeStmt = $conn->prepare('SELECT * FROM business_theme_settings WHERE business_id=? LIMIT 1');
    if ($themeStmt) {
        $themeStmt->bind_param('i', $businessId);
        $themeStmt->execute();
        $themeRow = $themeStmt->get_result()->fetch_assoc() ?: [];
        $themeStmt->close();
        foreach ($theme as $key => $defaultValue) {
            if (isset($themeRow[$key]) && $themeRow[$key] !== '') {
                $theme[$key] = $themeRow[$key];
            }
        }
    }
}

$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$fromDate = trim((string) ($_GET['from_date'] ?? $monthStart));
$toDate = trim((string) ($_GET['to_date'] ?? $today));
$customerId = max(0, (int) ($_GET['customer_id'] ?? 0));
$paymentMethodId = max(0, (int) ($_GET['payment_method_id'] ?? 0));
$search = trim((string) ($_GET['search'] ?? ''));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) {
    $fromDate = $monthStart;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
    $toDate = $today;
}

$customers = [];
$customerStmt = $conn->prepare(
    'SELECT DISTINCT c.id,c.customer_code,c.customer_name,c.mobile
     FROM sale_exchange_payouts sep
     INNER JOIN customers c ON c.id=sep.customer_id AND c.business_id=sep.business_id
     WHERE sep.business_id=? AND sep.branch_id=?
     ORDER BY c.customer_name'
);
if ($customerStmt) {
    $customerStmt->bind_param('ii', $businessId, $branchId);
    $customerStmt->execute();
    $result = $customerStmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $customers[] = $row;
    }
    $customerStmt->close();
}

$paymentMethods = [];
$paymentStmt = $conn->prepare(
    'SELECT DISTINCT pm.id,pm.method_name
     FROM sale_exchange_payouts sep
     INNER JOIN payment_methods pm ON pm.id=sep.payment_method_id AND pm.business_id=sep.business_id
     WHERE sep.business_id=? AND sep.branch_id=?
     ORDER BY pm.method_name'
);
if ($paymentStmt) {
    $paymentStmt->bind_param('ii', $businessId, $branchId);
    $paymentStmt->execute();
    $result = $paymentStmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $paymentMethods[] = $row;
    }
    $paymentStmt->close();
}

$where = [
    'sep.business_id=?',
    'sep.branch_id=?',
    'DATE(sep.payout_date) BETWEEN ? AND ?',
    'sep.amount > 0'
];
$types = 'iiss';
$params = [$businessId, $branchId, $fromDate, $toDate];

if ($customerId > 0) {
    $where[] = 'sep.customer_id=?';
    $types .= 'i';
    $params[] = $customerId;
}

if ($paymentMethodId > 0) {
    $where[] = 'sep.payment_method_id=?';
    $types .= 'i';
    $params[] = $paymentMethodId;
}

if ($search !== '') {
    $where[] = '(s.invoice_no LIKE ? OR c.customer_name LIKE ? OR c.mobile LIKE ? OR sep.reference_no LIKE ?)';
    $types .= 'ssss';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$createdBySelect = "'' AS created_by_name";
$createdByJoin = '';
if (erTableExists($conn, 'users') && erColumnExists($conn, 'sale_exchange_payouts', 'created_by')) {
    $userNameColumn = erColumnExists($conn, 'users', 'user_name') ? 'u.user_name' : (erColumnExists($conn, 'users', 'username') ? 'u.username' : "CONCAT('User #',u.id)");
    $createdBySelect = "COALESCE({$userNameColumn}, CONCAT('User #',sep.created_by)) AS created_by_name";
    $createdByJoin = ' LEFT JOIN users u ON u.id=sep.created_by ';
}

$sql = "SELECT
            sep.id,
            sep.sale_id,
            sep.customer_id,
            sep.payment_method_id,
            sep.amount AS returned_amount,
            sep.reference_no,
            sep.payout_date,
            sep.created_at,
            s.invoice_no,
            s.invoice_date,
            s.customer_name,
            s.customer_mobile,
            COALESCE(s.exchange_amount,0) AS exchange_amount,
            COALESCE(s.grand_total,0) AS bill_amount,
            COALESCE(s.net_payable_amount,0) AS net_payable_amount,
            c.customer_code,
            pm.method_name,
            {$createdBySelect}
        FROM sale_exchange_payouts sep
        INNER JOIN sales s
            ON s.id=sep.sale_id
           AND s.business_id=sep.business_id
           AND s.branch_id=sep.branch_id
        LEFT JOIN customers c
            ON c.id=sep.customer_id
           AND c.business_id=sep.business_id
        LEFT JOIN payment_methods pm
            ON pm.id=sep.payment_method_id
           AND pm.business_id=sep.business_id
        {$createdByJoin}
        WHERE " . implode(' AND ', $where) . "
        ORDER BY sep.payout_date DESC, sep.id DESC";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die('Unable to prepare returned amount report: ' . erEscape($conn->error));
}
erBindDynamic($stmt, $types, $params);
$stmt->execute();
$result = $stmt->get_result();
$rows = [];
while ($row = $result->fetch_assoc()) {
    $rows[] = $row;
}
$stmt->close();

$totalReturned = 0.0;
$totalExchange = 0.0;
$totalBill = 0.0;
foreach ($rows as $row) {
    $totalReturned += (float) $row['returned_amount'];
    $totalExchange += (float) $row['exchange_amount'];
    $totalBill += (float) $row['bill_amount'];
}
$recordCount = count($rows);
$averageReturned = $recordCount > 0 ? $totalReturned / $recordCount : 0;

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $filename = 'exchange-returned-amount-' . date('Ymd-His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'S/No', 'Invoice No', 'Invoice Date', 'Customer ID', 'Customer Name', 'Mobile',
        'Exchange Value', 'Bill Value', 'Returned Amount', 'Payment Method',
        'Reference', 'Payout Date & Time', 'Created By'
    ]);
    foreach ($rows as $index => $row) {
        fputcsv($out, [
            $index + 1,
            $row['invoice_no'],
            $row['invoice_date'],
            $row['customer_code'],
            $row['customer_name'],
            $row['customer_mobile'],
            number_format((float) $row['exchange_amount'], 2, '.', ''),
            number_format((float) $row['bill_amount'], 2, '.', ''),
            number_format((float) $row['returned_amount'], 2, '.', ''),
            $row['method_name'],
            $row['reference_no'],
            $row['payout_date'],
            $row['created_by_name']
        ]);
    }
    fclose($out);
    exit;
}

$pageTitle = 'Exchange Returned Amount Report';
$businessName = (string) ($_SESSION['business_name'] ?? 'Jewellery ERP');
$queryString = http_build_query([
    'from_date' => $fromDate,
    'to_date' => $toDate,
    'customer_id' => $customerId,
    'payment_method_id' => $paymentMethodId,
    'search' => $search,
    'export' => 'csv'
]);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= erEscape($businessName) ?> - <?= erEscape($pageTitle) ?></title>
    <?php if (is_file(__DIR__ . '/includes/links.php')) include __DIR__ . '/includes/links.php'; ?>
    <style>
        :root {
            --primary: <?= erEscape($theme['primary_color']) ?>;
            --primary-dark: <?= erEscape($theme['primary_dark_color']) ?>;
            --primary-soft: <?= erEscape($theme['primary_soft_color']) ?>;
            --sidebar-gradient-1: <?= erEscape($theme['sidebar_gradient_1']) ?>;
            --sidebar-gradient-2: <?= erEscape($theme['sidebar_gradient_2']) ?>;
            --sidebar-gradient-3: <?= erEscape($theme['sidebar_gradient_3']) ?>;
            --sidebar-width: <?= (int) $theme['sidebar_width_px'] ?>px;
            --page-bg: <?= erEscape($theme['page_background']) ?>;
            --card-bg: <?= erEscape($theme['card_background']) ?>;
            --text: <?= erEscape($theme['text_color']) ?>;
            --muted: <?= erEscape($theme['muted_text_color']) ?>;
            --line: <?= erEscape($theme['border_color']) ?>;
            --radius: <?= (int) $theme['border_radius_px'] ?>px;
        }
        body { background:var(--page-bg); color:var(--text); font-family:<?= json_encode($theme['font_family']) ?>,sans-serif; }
        .sidebar { background:linear-gradient(180deg,var(--sidebar-gradient-1),var(--sidebar-gradient-2),var(--sidebar-gradient-3)) !important; }
        .report-wrap { padding:12px 0 0; }
        .report-card { background:var(--card-bg); border:1px solid var(--line); border-radius:var(--radius); margin-bottom:10px; overflow:hidden; }
        .report-head { padding:12px 14px; display:flex; justify-content:space-between; align-items:center; gap:10px; border-bottom:1px solid var(--line); }
        .report-title { font:700 20px <?= json_encode($theme['heading_font_family']) ?>,serif; }
        .muted { color:var(--muted); }
        .btn-theme { background:linear-gradient(135deg,var(--primary),var(--primary-dark)); color:#fff; border:0; border-radius:8px; padding:8px 12px; font-size:11px; font-weight:700; text-decoration:none; }
        .btn-light-custom { background:#fff; border:1px solid var(--line); color:var(--text); border-radius:8px; padding:8px 12px; font-size:11px; text-decoration:none; }
        .filters { display:grid; grid-template-columns:repeat(6,minmax(0,1fr)); gap:8px; padding:12px; }
        .field-label { display:block; font-size:10px; font-weight:700; margin-bottom:4px; }
        .form-control,.form-select { min-height:36px; font-size:11px; border-color:var(--line); border-radius:8px; }
        .summary-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:10px; padding:10px 12px; }
        .stat-card { border:1px solid var(--line); background:var(--card-bg); border-radius:14px; padding:14px; }
        .stat-label { color:var(--muted); font-size:10px; margin-bottom:6px; }
        .stat-value { font-size:20px; line-height:1.1; font-weight:700; }
        .stat-icon { width:34px; height:34px; border-radius:9px; display:flex; align-items:center; justify-content:center; background:var(--primary-soft); color:var(--primary-dark); margin-bottom:8px; }
        .table { margin:0; font-size:10px; }
        .table thead th { font-size:9px; text-transform:uppercase; color:var(--muted); background:#fafafa; white-space:nowrap; border-color:var(--line); }
        .table td,.table th { padding:8px 9px; vertical-align:middle; border-color:var(--line); }
        .amount-return { font-weight:800; color:#b42318; white-space:nowrap; }
        .amount-exchange { color:#168449; white-space:nowrap; }
        .amount-bill { white-space:nowrap; }
        .customer-main { font-weight:700; }
        .invoice-link { color:var(--primary-dark); text-decoration:none; font-weight:700; }
        .empty-state { padding:42px 20px; text-align:center; color:var(--muted); }
        .table-responsive { overflow:auto; }
        @media(max-width:1100px) { .filters{grid-template-columns:repeat(3,1fr)} .summary-grid{grid-template-columns:repeat(2,1fr)} }
        @media(max-width:650px) { .filters{grid-template-columns:1fr} .summary-grid{grid-template-columns:1fr} .report-head{align-items:flex-start;flex-direction:column} }
        @media print {
            .no-print { display:none !important; }
            body { background:#fff; }
            .report-wrap { padding:0; }
            .report-card { border:0; }
        }
    </style>
</head>
<body>
<?php include('includes/sidebar.php'); ?>
<main class="app-main">
    <?php include('includes/nav.php'); ?>
    <div class="content-wrap">
<div class="report-wrap">
    <div class="report-card">
        <div class="report-head">
            <div>
                <div class="report-title">Exchange Returned Amount Report</div>
                <div class="muted" style="font-size:10px;margin-top:3px;">Paid to Customer - Exchange Extra Value</div>
            </div>
            <div class="d-flex gap-2 no-print">
                <a class="btn-light-custom" href="sales-list.php"><i class="fa-solid fa-arrow-left me-1"></i>Back</a>
                <button type="button" class="btn-light-custom" onclick="window.print()"><i class="fa-solid fa-print me-1"></i>Print</button>
                <a class="btn-theme" href="?<?= erEscape($queryString) ?>"><i class="fa-solid fa-file-excel me-1"></i>Excel</a>
            </div>
        </div>

        <form method="get" class="filters no-print">
            <div>
                <label class="field-label">From Date</label>
                <input type="date" class="form-control" name="from_date" value="<?= erEscape($fromDate) ?>">
            </div>
            <div>
                <label class="field-label">To Date</label>
                <input type="date" class="form-control" name="to_date" value="<?= erEscape($toDate) ?>">
            </div>
            <div>
                <label class="field-label">Customer</label>
                <select class="form-select" name="customer_id">
                    <option value="0">All Customers</option>
                    <?php foreach ($customers as $customerRow): ?>
                        <option value="<?= (int) $customerRow['id'] ?>" <?= $customerId === (int) $customerRow['id'] ? 'selected' : '' ?>>
                            <?= erEscape($customerRow['customer_name'] . (!empty($customerRow['customer_code']) ? ' - ' . $customerRow['customer_code'] : '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="field-label">Payment Method</label>
                <select class="form-select" name="payment_method_id">
                    <option value="0">All Methods</option>
                    <?php foreach ($paymentMethods as $method): ?>
                        <option value="<?= (int) $method['id'] ?>" <?= $paymentMethodId === (int) $method['id'] ? 'selected' : '' ?>><?= erEscape($method['method_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="field-label">Search</label>
                <input class="form-control" name="search" value="<?= erEscape($search) ?>" placeholder="Invoice / Customer / Mobile / Ref">
            </div>
            <div style="display:flex;gap:6px;align-items:end;">
                <button class="btn-theme" type="submit" style="flex:1"><i class="fa-solid fa-filter me-1"></i>Apply</button>
                <a class="btn-light-custom" href="exchange-returned-amount-report.php">Reset</a>
            </div>
        </form>
    </div>

    <div class="summary-grid">
        <div class="stat-card">
            <div class="stat-icon"><i class="fa-solid fa-indian-rupee-sign"></i></div>
            <div class="stat-label">Total Returned Amount</div>
            <div class="stat-value">₹<?= number_format($totalReturned, 2) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fa-solid fa-receipt"></i></div>
            <div class="stat-label">Return Transactions</div>
            <div class="stat-value"><?= number_format($recordCount) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fa-solid fa-scale-balanced"></i></div>
            <div class="stat-label">Total Exchange Value</div>
            <div class="stat-value">₹<?= number_format($totalExchange, 2) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fa-solid fa-chart-line"></i></div>
            <div class="stat-label">Average Returned Amount</div>
            <div class="stat-value">₹<?= number_format($averageReturned, 2) ?></div>
        </div>
    </div>

    <div class="report-card">
        <div class="report-head">
            <div>
                <div class="report-title" style="font-size:15px;">Returned Amount Details</div>
                <div class="muted" style="font-size:10px;margin-top:3px;">Amount returned when old-gold / exchange value is higher than the bill value.</div>
            </div>
            <div class="muted" style="font-size:10px;">Period: <?= erEscape(date('d-m-Y', strtotime($fromDate))) ?> to <?= erEscape(date('d-m-Y', strtotime($toDate))) ?></div>
        </div>
        <div class="table-responsive">
            <?php if (!$rows): ?>
                <div class="empty-state">
                    <i class="fa-solid fa-circle-info" style="font-size:24px;margin-bottom:10px;"></i>
                    <div>No returned exchange amount found for the selected filters.</div>
                </div>
            <?php else: ?>
                <table class="table table-hover align-middle">
                    <thead>
                    <tr>
                        <th>S/No</th>
                        <th>Invoice</th>
                        <th>Customer</th>
                        <th class="text-end">Exchange Value</th>
                        <th class="text-end">Bill Value</th>
                        <th class="text-end">Returned Amount</th>
                        <th>Payment Method</th>
                        <th>Reference</th>
                        <th>Payout Date & Time</th>
                        <th>Created By</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $index => $row): ?>
                        <tr>
                            <td><?= $index + 1 ?></td>
                            <td>
                                <a class="invoice-link" href="sale-details.php?id=<?= (int) $row['sale_id'] ?>"><?= erEscape($row['invoice_no']) ?></a>
                                <div class="muted"><?= erEscape(date('d-m-Y', strtotime((string) $row['invoice_date']))) ?></div>
                            </td>
                            <td>
                                <div class="customer-main"><?= erEscape($row['customer_name']) ?></div>
                                <div class="muted"><?= erEscape(($row['customer_code'] ?: '') . (($row['customer_code'] && $row['customer_mobile']) ? ' - ' : '') . ($row['customer_mobile'] ?: '')) ?></div>
                            </td>
                            <td class="text-end amount-exchange">₹<?= number_format((float) $row['exchange_amount'], 2) ?></td>
                            <td class="text-end amount-bill">₹<?= number_format((float) $row['bill_amount'], 2) ?></td>
                            <td class="text-end amount-return">₹<?= number_format((float) $row['returned_amount'], 2) ?></td>
                            <td><?= erEscape($row['method_name'] ?: '-') ?></td>
                            <td><?= erEscape($row['reference_no'] ?: '-') ?></td>
                            <td><?= erEscape(date('d-m-Y h:i A', strtotime((string) $row['payout_date']))) ?></td>
                            <td><?= erEscape($row['created_by_name'] ?: '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                    <tr>
                        <th colspan="3" class="text-end">Total</th>
                        <th class="text-end">₹<?= number_format($totalExchange, 2) ?></th>
                        <th class="text-end">₹<?= number_format($totalBill, 2) ?></th>
                        <th class="text-end amount-return">₹<?= number_format($totalReturned, 2) ?></th>
                        <th colspan="4"></th>
                    </tr>
                    </tfoot>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>
        <?php include('includes/footer.php'); ?>
    </div>
</main>
<?php if (is_file(__DIR__ . '/includes/script.php')) include __DIR__ . '/includes/script.php'; ?>
</body>
</html>
