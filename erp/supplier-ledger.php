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
] as $file) {
    if (is_file($file)) {
        require_once $file;
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

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function tableExistsLedger(mysqli $conn, string $table): bool
{
    $safe = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $result && $result->num_rows > 0;
}

function columnExistsLedger(mysqli $conn, string $table, string $column): bool
{
    $safeTable = $conn->real_escape_string($table);
    $safeColumn = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    return $result && $result->num_rows > 0;
}

function bindDynamicLedger(mysqli_stmt $stmt, string $types, array &$params): void
{
    if ($types === '') {
        return;
    }

    $bind = [$types];
    foreach ($params as $key => $value) {
        $bind[] = &$params[$key];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind);
}

$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? ($_SESSION['default_branch_id'] ?? 0));

if ($businessId <= 0) {
    die('A valid business must be selected.');
}

$supplierId = max(0, (int)($_GET['supplier_id'] ?? 0));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));

if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = '';
}
if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = '';
}

$suppliers = [];
$stmt = $conn->prepare(
    "SELECT id,supplier_code,supplier_name,mobile,opening_balance,current_balance
     FROM suppliers
     WHERE business_id=?
     ORDER BY supplier_name"
);
if ($stmt) {
    $stmt->bind_param('i', $businessId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $suppliers[] = $row;
    }
    $stmt->close();
}

$selectedSupplier = null;
if ($supplierId > 0) {
    $stmt = $conn->prepare(
        "SELECT id,supplier_code,supplier_name,mobile,opening_balance,current_balance,
                address_line1,city,state,pincode
         FROM suppliers
         WHERE id=? AND business_id=?
         LIMIT 1"
    );
    if ($stmt) {
        $stmt->bind_param('ii', $supplierId, $businessId);
        $stmt->execute();
        $selectedSupplier = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

$openingBalance = (float)($selectedSupplier['opening_balance'] ?? 0);
$totalPurchases = 0.00;
$totalPayments = 0.00;
$pendingAmount = 0.00;
$entries = [];

if ($supplierId > 0 && $selectedSupplier) {
    if (tableExistsLedger($conn, 'purchases')) {
        $sql = "SELECT id,purchase_no,purchase_date,grand_total,paid_amount,balance_amount,
                       supplier_invoice_no,payment_status
                FROM purchases
                WHERE business_id=? AND supplier_id=?";
        $types = 'ii';
        $params = [$businessId, $supplierId];

        if ($branchId > 0 && columnExistsLedger($conn, 'purchases', 'branch_id')) {
            $sql .= " AND branch_id=?";
            $types .= 'i';
            $params[] = $branchId;
        }

        if ($dateFrom !== '') {
            $sql .= " AND purchase_date>=?";
            $types .= 's';
            $params[] = $dateFrom;
        }

        if ($dateTo !== '') {
            $sql .= " AND purchase_date<=?";
            $types .= 's';
            $params[] = $dateTo;
        }

        if (columnExistsLedger($conn, 'purchases', 'workflow_status')) {
            $sql .= " AND COALESCE(workflow_status,'Posted')<>'Cancelled'";
        }

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            bindDynamicLedger($stmt, $types, $params);
            $stmt->execute();
            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                $amount = (float)$row['grand_total'];
                $totalPurchases += $amount;
                $pendingAmount += (float)$row['balance_amount'];

                $entries[] = [
                    'date' => (string)$row['purchase_date'],
                    'sort_id' => (int)$row['id'],
                    'type' => 'Purchase',
                    'reference' => (string)$row['purchase_no'],
                    'details' => $row['supplier_invoice_no']
                        ? 'Supplier Invoice: ' . $row['supplier_invoice_no']
                        : 'Purchase bill',
                    'debit' => $amount,
                    'credit' => 0.00,
                    'status' => (string)$row['payment_status'],
                ];
            }
            $stmt->close();
        }
    }

    if (tableExistsLedger($conn, 'supplier_payments')) {
        $amountColumn = columnExistsLedger($conn, 'supplier_payments', 'amount')
            ? 'amount'
            : (columnExistsLedger($conn, 'supplier_payments', 'total_amount')
                ? 'total_amount'
                : (columnExistsLedger($conn, 'supplier_payments', 'payment_amount')
                    ? 'payment_amount'
                    : ''));

        $remarksColumn = columnExistsLedger($conn, 'supplier_payments', 'remarks')
            ? 'remarks'
            : (columnExistsLedger($conn, 'supplier_payments', 'notes') ? 'notes' : '');

        if ($amountColumn !== '') {
            $remarksSelect = $remarksColumn !== '' ? "sp.`{$remarksColumn}`" : "''";

            $sql = "SELECT sp.id,sp.payment_no,sp.payment_date,
                           sp.`{$amountColumn}` payment_amount,
                           sp.reference_no,
                           {$remarksSelect} payment_remarks,
                           pm.method_name
                    FROM supplier_payments sp
                    LEFT JOIN payment_methods pm ON pm.id=sp.payment_method_id
                    WHERE sp.business_id=? AND sp.supplier_id=?";
            $types = 'ii';
            $params = [$businessId, $supplierId];

            if ($branchId > 0 && columnExistsLedger($conn, 'supplier_payments', 'branch_id')) {
                $sql .= " AND sp.branch_id=?";
                $types .= 'i';
                $params[] = $branchId;
            }

            if ($dateFrom !== '') {
                $sql .= " AND sp.payment_date>=?";
                $types .= 's';
                $params[] = $dateFrom;
            }

            if ($dateTo !== '') {
                $sql .= " AND sp.payment_date<=?";
                $types .= 's';
                $params[] = $dateTo;
            }

            $stmt = $conn->prepare($sql);
            if ($stmt) {
                bindDynamicLedger($stmt, $types, $params);
                $stmt->execute();
                $result = $stmt->get_result();

                while ($row = $result->fetch_assoc()) {
                    $amount = (float)$row['payment_amount'];
                    $totalPayments += $amount;

                    $detailParts = [];
                    if (!empty($row['method_name'])) {
                        $detailParts[] = $row['method_name'];
                    }
                    if (!empty($row['reference_no'])) {
                        $detailParts[] = 'Ref: ' . $row['reference_no'];
                    }
                    if (!empty($row['payment_remarks'])) {
                        $detailParts[] = $row['payment_remarks'];
                    }

                    $entries[] = [
                        'date' => (string)$row['payment_date'],
                        'sort_id' => (int)$row['id'],
                        'type' => 'Payment',
                        'reference' => (string)$row['payment_no'],
                        'details' => implode(' · ', $detailParts) ?: 'Supplier payment',
                        'debit' => 0.00,
                        'credit' => $amount,
                        'status' => 'Paid',
                    ];
                }
                $stmt->close();
            }
        }
    }

    usort($entries, static function (array $a, array $b): int {
        $dateCompare = strcmp($a['date'], $b['date']);
        if ($dateCompare !== 0) {
            return $dateCompare;
        }

        if ($a['type'] !== $b['type']) {
            return $a['type'] === 'Purchase' ? -1 : 1;
        }

        return $a['sort_id'] <=> $b['sort_id'];
    });
}

$runningBalance = $openingBalance;
foreach ($entries as $index => $entry) {
    $runningBalance += (float)$entry['debit'];
    $runningBalance -= (float)$entry['credit'];
    $entries[$index]['running_balance'] = $runningBalance;
}

$closingBalance = $runningBalance;

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
    'border_radius_px' => 12
];

$stmt = $conn->prepare('SELECT * FROM business_theme_settings WHERE business_id=? LIMIT 1');
if ($stmt) {
    $stmt->bind_param('i', $businessId);
    $stmt->execute();
    $themeRow = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    foreach ($theme as $key => $value) {
        if (isset($themeRow[$key]) && $themeRow[$key] !== '') {
            $theme[$key] = $themeRow[$key];
        }
    }
}

$pageTitle = 'Supplier Ledger';
$businessName = (string)($_SESSION['business_name'] ?? 'Jewellery ERP');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($businessName) ?> - Supplier Ledger</title>
<?php include('includes/links.php'); ?>
<style>
:root{
    --primary:<?= e($theme['primary_color']) ?>;
    --primary-dark:<?= e($theme['primary_dark_color']) ?>;
    --primary-soft:<?= e($theme['primary_soft_color']) ?>;
    --page-bg:<?= e($theme['page_background']) ?>;
    --card-bg:<?= e($theme['card_background']) ?>;
    --text:<?= e($theme['text_color']) ?>;
    --muted:<?= e($theme['muted_text_color']) ?>;
    --line:<?= e($theme['border_color']) ?>;
    --radius:<?= (int)$theme['border_radius_px'] ?>px;
}
body{background:var(--page-bg);color:var(--text);font-family:<?= json_encode($theme['font_family']) ?>,sans-serif}
.sidebar{background:linear-gradient(180deg,<?= e($theme['sidebar_gradient_1']) ?>,<?= e($theme['sidebar_gradient_2']) ?>,<?= e($theme['sidebar_gradient_3']) ?>)!important}
.page-card,.stat-card{background:var(--card-bg);border:1px solid var(--line);border-radius:var(--radius)}
.page-head{padding:14px 16px;display:flex;justify-content:space-between;align-items:center;gap:12px}
.page-title{font:700 21px <?= json_encode($theme['heading_font_family']) ?>,serif}
.page-subtitle{color:var(--muted);font-size:10px;margin-top:2px}
.btn-theme,.btn-soft{min-height:38px;border-radius:9px;padding:8px 13px;font-size:11px;font-weight:800;text-decoration:none;display:inline-flex;align-items:center}
.btn-theme{border:0;color:#fff;background:linear-gradient(135deg,var(--primary),var(--primary-dark))}
.btn-soft{border:1px solid var(--line);color:var(--text);background:var(--card-bg)}
.filter-panel{padding:12px;margin-bottom:12px}
.filter-grid{display:grid;grid-template-columns:minmax(220px,1.3fr) repeat(2,minmax(145px,.7fr)) auto auto;gap:8px}
.form-control,.form-select{min-height:38px;border:1px solid var(--line);border-radius:9px;background:var(--card-bg);color:var(--text);font-size:11px}
.stat-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:12px}
.stat-card{padding:13px}
.stat-label{color:var(--muted);font-size:9px;text-transform:uppercase}
.stat-value{font-size:19px;font-weight:900;margin-top:3px}
.ledger-table{margin:0;font-size:10px}
.ledger-table th{font-size:9px;text-transform:uppercase;color:var(--muted);background:color-mix(in srgb,var(--muted) 6%,transparent);white-space:nowrap}
.ledger-table th,.ledger-table td{padding:10px;border-color:var(--line);vertical-align:middle}
.debit{color:#b42318;font-weight:800}.credit{color:#168449;font-weight:800}.balance{font-weight:900}
.type-badge{display:inline-flex;padding:4px 8px;border-radius:999px;font-size:9px;font-weight:800}
.type-purchase{background:#fff3df;color:#9a6200}.type-payment{background:#eaf8f0;color:#168449}
.supplier-box{padding:12px 14px;border-bottom:1px solid var(--line)}
.empty-state{padding:46px 20px;text-align:center;color:var(--muted)}
@media(max-width:991px){.stat-grid{grid-template-columns:1fr 1fr}.filter-grid{grid-template-columns:1fr 1fr}.filter-grid select:first-child{grid-column:1/-1}}
@media(max-width:767px){.page-head{align-items:flex-start;flex-direction:column}.stat-grid,.filter-grid{grid-template-columns:1fr}.filter-grid select:first-child{grid-column:auto}.ledger-table thead{display:none}.ledger-table tbody{display:grid;gap:10px;padding:10px}.ledger-table tr{display:grid;border:1px solid var(--line);border-radius:var(--radius);padding:10px}.ledger-table td{display:flex;justify-content:space-between;border:0;border-bottom:1px dashed var(--line)}.ledger-table td::before{content:attr(data-label);font-size:8px;text-transform:uppercase;color:var(--muted);font-weight:800}}
@media print{.sidebar,.app-topbar,.filter-panel,.no-print,footer{display:none!important}.app-main{margin:0!important}.content-wrap{padding:0!important}.page-card,.stat-card{box-shadow:none!important}}
body.dark-mode,body[data-theme=dark]{--page-bg:#0f151b;--card-bg:#182129;--text:#f3f6f8;--muted:#9aa7b3;--line:#2c3944}
</style>
</head>
<body>
<?php include('includes/sidebar.php'); ?>
<main class="app-main">
<?php include('includes/nav.php'); ?>

<div class="content-wrap">
    <div class="page-card mb-3">
        <div class="page-head">
            <div>
                <div class="page-title">Supplier Ledger</div>
                <div class="page-subtitle">Purchase bills, supplier payments and running outstanding balance.</div>
            </div>
            <div class="d-flex gap-2 flex-wrap no-print">
                <button type="button" class="btn-soft" onclick="window.print()">
                    <i class="fa-solid fa-print me-1"></i>Print
                </button>
                <a href="suppliers.php" class="btn-soft">
                    <i class="fa-solid fa-arrow-left me-1"></i>Suppliers
                </a>
                <?php if ($supplierId > 0): ?>
                    <a href="supplier-payment.php?supplier_id=<?= $supplierId ?>" class="btn-theme">
                        <i class="fa-solid fa-indian-rupee-sign me-1"></i>Pay Supplier
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="page-card filter-panel no-print">
        <form method="get" class="filter-grid">
            <select name="supplier_id" class="form-select" required>
                <option value="">Select Supplier</option>
                <?php foreach ($suppliers as $supplier): ?>
                    <option value="<?= (int)$supplier['id'] ?>" <?= $supplierId === (int)$supplier['id'] ? 'selected' : '' ?>>
                        <?= e($supplier['supplier_name']) ?>
                        <?= $supplier['supplier_code'] ? ' - ' . e($supplier['supplier_code']) : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="date_from" class="form-control" value="<?= e($dateFrom) ?>">
            <input type="date" name="date_to" class="form-control" value="<?= e($dateTo) ?>">
            <button type="submit" class="btn-theme"><i class="fa-solid fa-filter me-1"></i>Apply</button>
            <a href="supplier-ledger.php" class="btn-soft"><i class="fa-solid fa-rotate-left"></i></a>
        </form>
    </div>

    <?php if (!$selectedSupplier): ?>
        <div class="page-card empty-state">
            <i class="fa-solid fa-book-open fa-2x mb-2"></i>
            <div>Select a supplier to view the ledger.</div>
        </div>
    <?php else: ?>
        <div class="page-card mb-3">
            <div class="supplier-box">
                <strong><?= e($selectedSupplier['supplier_name']) ?></strong>
                <div class="small text-muted">
                    <?= e($selectedSupplier['supplier_code'] ?? '') ?>
                    <?= !empty($selectedSupplier['mobile']) ? ' · ' . e($selectedSupplier['mobile']) : '' ?>
                    <?php
                    $address = array_filter([
                        $selectedSupplier['address_line1'] ?? '',
                        $selectedSupplier['city'] ?? '',
                        $selectedSupplier['state'] ?? '',
                        $selectedSupplier['pincode'] ?? ''
                    ]);
                    ?>
                    <?= $address ? ' · ' . e(implode(', ', $address)) : '' ?>
                </div>
            </div>
        </div>

        <div class="stat-grid">
            <div class="stat-card">
                <div class="stat-label">Opening Balance</div>
                <div class="stat-value">₹<?= number_format($openingBalance,2) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Purchases</div>
                <div class="stat-value">₹<?= number_format($totalPurchases,2) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Payments</div>
                <div class="stat-value">₹<?= number_format($totalPayments,2) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Closing / Pending</div>
                <div class="stat-value">₹<?= number_format($closingBalance,2) ?></div>
            </div>
        </div>

        <section class="page-card">
            <?php if (!$entries): ?>
                <div class="empty-state">No ledger transactions found for the selected period.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table ledger-table">
                        <thead>
                        <tr>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Reference</th>
                            <th>Details</th>
                            <th class="text-end">Purchase / Debit</th>
                            <th class="text-end">Payment / Credit</th>
                            <th class="text-end">Running Balance</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($entries as $entry): ?>
                            <tr>
                                <td data-label="Date"><?= e(date('d-m-Y', strtotime($entry['date']))) ?></td>
                                <td data-label="Type">
                                    <span class="type-badge <?= $entry['type'] === 'Purchase' ? 'type-purchase' : 'type-payment' ?>">
                                        <?= e($entry['type']) ?>
                                    </span>
                                </td>
                                <td data-label="Reference"><strong><?= e($entry['reference']) ?></strong></td>
                                <td data-label="Details"><?= e($entry['details']) ?></td>
                                <td data-label="Debit" class="text-end debit">
                                    <?= $entry['debit'] > 0 ? '₹' . number_format($entry['debit'],2) : '—' ?>
                                </td>
                                <td data-label="Credit" class="text-end credit">
                                    <?= $entry['credit'] > 0 ? '₹' . number_format($entry['credit'],2) : '—' ?>
                                </td>
                                <td data-label="Balance" class="text-end balance">
                                    ₹<?= number_format($entry['running_balance'],2) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php include('includes/footer.php'); ?>
</div>
</main>
<?php include('includes/script.php'); ?>
<script src="assets/js/script.js"></script>
</body>
</html>