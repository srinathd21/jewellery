<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));

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

if (!function_exists('supplierPaymentHasColumn')) {
    function supplierPaymentHasColumn(mysqli $conn, string $table, string $column): bool
    {
        $safeTable = str_replace('`', '``', $table);
        $safeColumn = $conn->real_escape_string($column);
        $result = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
        return $result && $result->num_rows > 0;
    }
}

if (!function_exists('supplierTableColumn')) {
    function supplierTableColumn(mysqli $conn, string $table, array $candidates): string
    {
        foreach ($candidates as $candidate) {
            if (supplierPaymentHasColumn($conn, $table, $candidate)) {
                return $candidate;
            }
        }
        return '';
    }
}

if (!function_exists('supplierPaymentColumn')) {
    function supplierPaymentColumn(mysqli $conn, array $candidates): string
    {
        return supplierTableColumn($conn, 'supplier_payments', $candidates);
    }
}

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? 0);
$userId = (int)($_SESSION['user_id'] ?? 0);

if ($businessId <= 0 || $branchId <= 0) {
    die('A valid business and branch must be selected.');
}

if (empty($_SESSION['supplier_payment_csrf'])) {
    $_SESSION['supplier_payment_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['supplier_payment_csrf'];

$selectedSupplierId = max(0, (int)($_GET['supplier_id'] ?? 0));
$selectedPurchaseId = max(0, (int)($_GET['purchase_id'] ?? 0));

if ($selectedPurchaseId > 0) {
    $stmt = $conn->prepare(
        "SELECT supplier_id
         FROM purchases
         WHERE id=? AND business_id=? AND branch_id=?
         LIMIT 1"
    );
    if ($stmt) {
        $stmt->bind_param('iii', $selectedPurchaseId, $businessId, $branchId);
        $stmt->execute();
        $purchaseRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($purchaseRow) {
            $selectedSupplierId = (int)$purchaseRow['supplier_id'];
        }
    }
}

$suppliers = [];
$stmt = $conn->prepare(
    "SELECT s.id, s.supplier_code, s.supplier_name, s.mobile,
            COALESCE(SUM(p.balance_amount),0) outstanding_amount
     FROM suppliers s
     LEFT JOIN purchases p
       ON p.supplier_id=s.id
      AND p.business_id=s.business_id
      AND p.branch_id=?
      AND p.balance_amount>0
     WHERE s.business_id=? AND s.is_active=1
     GROUP BY s.id,s.supplier_code,s.supplier_name,s.mobile
     ORDER BY s.supplier_name"
);
if ($stmt) {
    $stmt->bind_param('ii', $branchId, $businessId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $suppliers[] = $row;
    }
    $stmt->close();
}

$outstandingPurchases = [];
if ($selectedSupplierId > 0) {
    $stmt = $conn->prepare(
        "SELECT id,purchase_no,supplier_invoice_no,purchase_date,grand_total,
                paid_amount,balance_amount,payment_status
         FROM purchases
         WHERE business_id=? AND branch_id=? AND supplier_id=?
           AND balance_amount>0
         ORDER BY purchase_date ASC,id ASC"
    );
    if ($stmt) {
        $stmt->bind_param('iii', $businessId, $branchId, $selectedSupplierId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $outstandingPurchases[] = $row;
        }
        $stmt->close();
    }
}


$paymentMethods = [];
$paymentMethodTable = $conn->query("SHOW TABLES LIKE 'payment_methods'");
if ($paymentMethodTable && $paymentMethodTable->num_rows > 0) {
    $idColumn = supplierTableColumn($conn, 'payment_methods', ['id','payment_method_id','method_id']);
    $nameColumn = supplierTableColumn($conn, 'payment_methods', ['method_name','payment_method_name','name']);
    $statusColumn = supplierTableColumn($conn, 'payment_methods', ['is_active','status','active']);
    $hasBusinessColumn = supplierPaymentHasColumn($conn, 'payment_methods', 'business_id');

    if ($idColumn !== '' && $nameColumn !== '') {
        $methodSql = "SELECT `{$idColumn}` AS method_id, `{$nameColumn}` AS method_name
                      FROM payment_methods WHERE 1=1";
        $methodTypes = '';
        $methodParams = [];

        if ($hasBusinessColumn) {
            $methodSql .= " AND (business_id=? OR business_id IS NULL)";
            $methodTypes .= 'i';
            $methodParams[] = $businessId;
        }

        if ($statusColumn !== '') {
            $methodSql .= " AND COALESCE(`{$statusColumn}`,1)=1";
        }

        $methodSql .= " ORDER BY `{$nameColumn}`";

        $stmt = $conn->prepare($methodSql);
        if ($stmt) {
            if ($methodTypes !== '') {
                $stmt->bind_param($methodTypes, ...$methodParams);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $paymentMethods[] = $row;
            }
            $stmt->close();
        }
    }
}

$recentPayments = [];
$recentPaymentsError = '';
$supplierPaymentAmountColumn = '';
$tableCheck = $conn->query("SHOW TABLES LIKE 'supplier_payments'");
if ($tableCheck && $tableCheck->num_rows > 0) {
    $supplierPaymentAmountColumn = supplierPaymentColumn($conn, ['total_amount','amount','payment_amount']);
    $supplierPaymentRemarksColumn = supplierPaymentColumn($conn, ['notes','remarks','description']);

    $amountSelect = $supplierPaymentAmountColumn !== ''
        ? "sp.`{$supplierPaymentAmountColumn}` AS total_amount"
        : "0 AS total_amount";
    $remarksSelect = $supplierPaymentRemarksColumn !== ''
        ? "sp.`{$supplierPaymentRemarksColumn}` AS notes"
        : "'' AS notes";

    $userNameSelect = supplierPaymentHasColumn($conn, 'users', 'full_name')
        ? "COALESCE(u.full_name,u.username,CONCAT('User #',sp.created_by))"
        : "COALESCE(u.username,CONCAT('User #',sp.created_by))";

    $sql = "SELECT sp.id,sp.payment_no,sp.payment_date,{$amountSelect},{$remarksSelect},sp.created_at,
                   s.supplier_name,
                   {$userNameSelect} created_by_name,
                   GROUP_CONCAT(DISTINCT CONCAT(COALESCE(sps.payment_method,'Payment'),': ',FORMAT(sps.amount,2)) ORDER BY sps.id SEPARATOR ' | ') split_summary,
                   COUNT(DISTINCT spa.purchase_id) allocated_bills
            FROM supplier_payments sp
            INNER JOIN suppliers s ON s.id=sp.supplier_id AND s.business_id=sp.business_id
            LEFT JOIN users u ON u.id=sp.created_by
            LEFT JOIN supplier_payment_splits sps ON sps.payment_id=sp.id
            LEFT JOIN supplier_payment_allocations spa ON spa.payment_id=sp.id
            WHERE sp.business_id=? AND sp.branch_id=?";
    $params = [$businessId, $branchId];
    $types = 'ii';

    if ($selectedSupplierId > 0) {
        $sql .= " AND sp.supplier_id=?";
        $params[] = $selectedSupplierId;
        $types .= 'i';
    }

    $sql .= " GROUP BY sp.id,sp.payment_no,sp.payment_date,sp.created_at,
                      s.supplier_name,u.username,sp.created_by";

    if (supplierPaymentHasColumn($conn, 'users', 'full_name')) {
        $sql .= ",u.full_name";
    }

    if ($supplierPaymentAmountColumn !== '') {
        $sql .= ",sp.`{$supplierPaymentAmountColumn}`";
    }
    if ($supplierPaymentRemarksColumn !== '') {
        $sql .= ",sp.`{$supplierPaymentRemarksColumn}`";
    }

    $sql .= " ORDER BY sp.payment_date DESC,sp.id DESC LIMIT 50";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $bind = [$types];
        foreach ($params as $k => $value) {
            $bind[] = &$params[$k];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind);

        if ($stmt->execute()) {
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $recentPayments[] = $row;
            }
        } else {
            $recentPaymentsError = $stmt->error;
        }
        $stmt->close();
    } else {
        $recentPaymentsError = $conn->error;
    }
}

$theme = [
    'primary_color'=>'#d89416','primary_dark_color'=>'#b86a0b','primary_soft_color'=>'#fff6e5',
    'page_background'=>'#f4f3f0','card_background'=>'#ffffff','text_color'=>'#171717',
    'muted_text_color'=>'#7d8794','border_color'=>'#e8e8e8','font_family'=>'Inter',
    'heading_font_family'=>'Playfair Display','border_radius_px'=>12,'sidebar_width_px'=>230,
    'sidebar_gradient_1'=>'#171c21','sidebar_gradient_2'=>'#20272d','sidebar_gradient_3'=>'#101419'
];
$stmt = $conn->prepare('SELECT * FROM business_theme_settings WHERE business_id=? LIMIT 1');
if ($stmt) {
    $stmt->bind_param('i', $businessId);
    $stmt->execute();
    $themeRow = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    foreach ($theme as $key => $default) {
        if (isset($themeRow[$key]) && $themeRow[$key] !== '') {
            $theme[$key] = $themeRow[$key];
        }
    }
}

$pageTitle = 'Supplier Payment';
$businessName = (string)($_SESSION['business_name'] ?? 'Jewellery ERP');
$currency = (string)($_SESSION['currency_symbol'] ?? '₹');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($businessName) ?> - Supplier Payment</title>
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
.page-card{background:var(--card-bg);border:1px solid var(--line);border-radius:var(--radius);margin-bottom:10px;overflow:hidden}
.card-head{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:12px 14px;border-bottom:1px solid var(--line)}
.card-title{font-size:14px;font-weight:800}
.card-body{padding:14px}
.form-label{font-size:10px;font-weight:700;margin-bottom:5px}
.form-control,.form-select{min-height:38px;font-size:11px;border-radius:9px;border-color:var(--line);background:var(--card-bg);color:var(--text)}
.btn-theme{border:0;border-radius:9px;background:linear-gradient(135deg,var(--primary),var(--primary-dark));color:#fff;font-size:11px;font-weight:700;padding:9px 14px}
.btn-soft{border:1px solid var(--line);border-radius:9px;background:var(--card-bg);color:var(--text);font-size:11px;font-weight:700;padding:8px 13px;text-decoration:none}
.summary-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:8px}
.summary-box{border:1px solid var(--line);border-radius:10px;padding:10px;background:color-mix(in srgb,var(--primary-soft) 28%,var(--card-bg))}
.summary-box span{display:block;font-size:9px;color:var(--muted);text-transform:uppercase}
.summary-box b{display:block;font-size:15px;margin-top:4px}
.table{margin:0;font-size:10px}
.table th{font-size:9px;text-transform:uppercase;color:var(--muted);background:color-mix(in srgb,var(--muted) 6%,transparent);white-space:nowrap}
.table td,.table th{padding:9px 10px;vertical-align:middle;border-color:var(--line)}
.allocation-input,.split-amount{min-width:120px}
.split-row{display:grid;grid-template-columns:1.1fr .8fr 1fr 1fr 42px;gap:8px;margin-bottom:8px}
.remove-split{width:38px;height:38px;border:1px solid var(--line);border-radius:9px;background:var(--card-bg);color:#bd2d2d}
.status-ok{color:#168449}.status-error{color:#bd2d2d}
.theme-toast{position:fixed;right:18px;top:78px;z-index:20000;min-width:260px;max-width:420px;padding:11px 14px;border-radius:10px;color:#fff;font-size:11px;font-weight:700;opacity:0;transform:translateY(-10px);transition:.2s}
.theme-toast.show{opacity:1;transform:none}.theme-toast-success{background:#168449}.theme-toast-error{background:#c0392b}
.ledger-note{max-width:260px;white-space:normal}
body.dark-mode,body[data-theme=dark]{--page-bg:#0f151b;--card-bg:#182129;--text:#f3f6f8;--muted:#9aa7b3;--line:#2c3944}
@media(max-width:991px){.summary-grid{grid-template-columns:1fr 1fr}.split-row{grid-template-columns:1fr 1fr}.split-row .remove-split{width:100%}}
@media(max-width:767px){.summary-grid{grid-template-columns:1fr}.split-row{grid-template-columns:1fr}.content-wrap{padding-left:10px;padding-right:10px}}
</style>
</head>
<body>
<?php include('includes/sidebar.php'); ?>
<main class="app-main">
<?php include('includes/nav.php'); ?>
<div class="content-wrap">
<div class="page-card">
    <div class="card-head">
        <div>
            <div class="card-title">Supplier Payment</div>
            <div class="small text-muted">Allocate one payment across multiple purchase bills and split the amount across multiple payment methods.</div>
        </div>
        <a href="purchases.php" class="btn-soft"><i class="fa-solid fa-arrow-left me-2"></i>Purchases</a>
    </div>
</div>

<form id="supplierPaymentForm">
<input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
<input type="hidden" name="action" value="save">

<div class="page-card">
    <div class="card-head"><div class="card-title">Payment Details</div></div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-5">
                <label class="form-label">Supplier *</label>
                <select class="form-select" id="supplierId" name="supplier_id" required>
                    <option value="">Select supplier</option>
                    <?php foreach ($suppliers as $supplier): ?>
                        <option value="<?= (int)$supplier['id'] ?>" <?= $selectedSupplierId === (int)$supplier['id'] ? 'selected' : '' ?>>
                            <?= e($supplier['supplier_name']) ?>
                            <?= $supplier['supplier_code'] ? ' - ' . e($supplier['supplier_code']) : '' ?>
                            (Due <?= e($currency) ?> <?= number_format((float)$supplier['outstanding_amount'],2) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Payment Date *</label>
                <input type="date" class="form-control" name="payment_date" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Notes</label>
                <input class="form-control" name="notes" maxlength="500" placeholder="Optional payment note">
            </div>
        </div>
    </div>
</div>

<div class="page-card">
    <div class="card-head">
        <div>
            <div class="card-title">Purchase Allocation</div>
            <div class="small text-muted">Enter how much of this payment should be applied to each outstanding purchase.</div>
        </div>
        <?php if ($selectedSupplierId > 0 && $outstandingPurchases): ?>
            <button type="button" class="btn-soft" id="allocateAll">Allocate All Outstanding</button>
        <?php endif; ?>
    </div>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead>
                <tr>
                    <th>Use</th><th>Purchase</th><th>Date</th><th>Supplier Invoice</th>
                    <th>Total</th><th>Paid</th><th>Balance</th><th>Allocate</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$selectedSupplierId): ?>
                <tr><td colspan="8" class="text-center py-4 text-muted">Select a supplier to load outstanding purchases.</td></tr>
            <?php elseif (!$outstandingPurchases): ?>
                <tr><td colspan="8" class="text-center py-4 text-muted">No outstanding purchases found for this supplier.</td></tr>
            <?php else: ?>
                <?php foreach ($outstandingPurchases as $purchase): ?>
                    <tr>
                        <td>
                            <input class="form-check-input allocation-check" type="checkbox"
                                   data-purchase-id="<?= (int)$purchase['id'] ?>"
                                   <?= $selectedPurchaseId === (int)$purchase['id'] ? 'checked' : '' ?>>
                        </td>
                        <td><b><?= e($purchase['purchase_no']) ?></b></td>
                        <td><?= e(date('d-m-Y',strtotime($purchase['purchase_date']))) ?></td>
                        <td><?= e($purchase['supplier_invoice_no'] ?: '—') ?></td>
                        <td><?= e($currency) ?> <?= number_format((float)$purchase['grand_total'],2) ?></td>
                        <td><?= e($currency) ?> <?= number_format((float)$purchase['paid_amount'],2) ?></td>
                        <td><b><?= e($currency) ?> <?= number_format((float)$purchase['balance_amount'],2) ?></b></td>
                        <td>
                            <input type="number" step="0.01" min="0"
                                   max="<?= e((string)$purchase['balance_amount']) ?>"
                                   class="form-control allocation-input"
                                   name="allocation_amount[<?= (int)$purchase['id'] ?>]"
                                   data-balance="<?= e((string)$purchase['balance_amount']) ?>"
                                   value="<?= $selectedPurchaseId === (int)$purchase['id'] ? e((string)$purchase['balance_amount']) : '0.00' ?>">
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="page-card">
    <div class="card-head">
        <div>
            <div class="card-title">Split Payment Methods</div>
            <div class="small text-muted">Example: Cash + UPI + Bank Transfer in one supplier payment.</div>
        </div>
        <button type="button" class="btn-soft" id="addSplit"><i class="fa-solid fa-plus me-2"></i>Add Method</button>
    </div>
    <div class="card-body">
        <?php if (!$paymentMethods): ?>
            <div class="alert alert-danger">No active payment methods found. Create or activate a payment method first.</div>
        <?php endif; ?>
        <div id="splitRows">
            <div class="split-row">
                <select class="form-select split-method" name="split_method_id[]" required>
                    <option value="">Select method</option>
                    <?php foreach ($paymentMethods as $method): ?>
                        <option value="<?= (int)$method['method_id'] ?>"><?= e($method['method_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="number" class="form-control split-amount" name="split_amount[]" min="0.01" step="0.01" placeholder="Amount" required>
                <input class="form-control" name="split_reference[]" maxlength="100" placeholder="Reference / UTR / Cheque No.">
                <input class="form-control" name="split_remarks[]" maxlength="200" placeholder="Remarks">
                <button type="button" class="remove-split" title="Remove"><i class="fa-solid fa-trash"></i></button>
            </div>
        </div>
    </div>
</div>

<div class="page-card">
    <div class="card-body">
        <div class="summary-grid">
            <div class="summary-box"><span>Allocation Total</span><b id="allocationTotal"><?= e($currency) ?> 0.00</b></div>
            <div class="summary-box"><span>Split Total</span><b id="splitTotal"><?= e($currency) ?> 0.00</b></div>
            <div class="summary-box"><span>Difference</span><b id="differenceTotal"><?= e($currency) ?> 0.00</b></div>
            <div class="summary-box"><span>Validation</span><b id="validationText" class="status-error">Enter payment</b></div>
        </div>
        <div class="text-end mt-3">
            <button class="btn-theme" id="savePaymentButton" type="submit">
                <i class="fa-solid fa-floppy-disk me-2"></i>Save Supplier Payment
            </button>
        </div>
    </div>
</div>
</form>

<div class="page-card">
    <div class="card-head">
        <div>
            <div class="card-title">Supplier Payment Ledger</div>
            <div class="small text-muted">Recent supplier payments with split methods and purchase allocation count.</div>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead><tr><th>Payment No.</th><th>Date</th><th>Supplier</th><th>Amount</th><th>Split Methods</th><th>Bills</th><th>Created By</th><th>Notes</th></tr></thead>
            <tbody>
            <?php if (!$recentPayments): ?>
                <tr><td colspan="8" class="text-center py-4 text-muted">
                    <?= $recentPaymentsError !== '' ? 'Unable to load supplier payments: ' . e($recentPaymentsError) : 'No supplier payments recorded yet.' ?>
                </td></tr>
            <?php else: ?>
                <?php foreach ($recentPayments as $payment): ?>
                    <tr>
                        <td><b><?= e($payment['payment_no']) ?></b><div class="small text-muted"><?= e(date('d-m-Y h:i A',strtotime($payment['created_at']))) ?></div></td>
                        <td><?= e(date('d-m-Y',strtotime($payment['payment_date']))) ?></td>
                        <td><?= e($payment['supplier_name']) ?></td>
                        <td><b><?= e($currency) ?> <?= number_format((float)$payment['total_amount'],2) ?></b></td>
                        <td><?= e($payment['split_summary'] ?: '—') ?></td>
                        <td><?= (int)$payment['allocated_bills'] ?></td>
                        <td><?= e($payment['created_by_name']) ?></td>
                        <td class="ledger-note"><?= e($payment['notes'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include('includes/footer.php'); ?>
</div>
</main>

<div class="theme-toast" id="paymentToast"></div>
<?php include('includes/script.php'); ?>
<script src="assets/js/script.js"></script>
<script>
(() => {
    'use strict';

    const currency = <?= json_encode($currency) ?>;
    const supplier = document.getElementById('supplierId');
    const form = document.getElementById('supplierPaymentForm');
    const splitRows = document.getElementById('splitRows');
    const addSplit = document.getElementById('addSplit');
    const saveButton = document.getElementById('savePaymentButton');

    function money(value) {
        return currency + ' ' + Number(value || 0).toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function toast(ok, message) {
        const el = document.getElementById('paymentToast');
        el.className = 'theme-toast ' + (ok ? 'theme-toast-success' : 'theme-toast-error');
        el.textContent = message;
        el.classList.add('show');
        setTimeout(() => el.classList.remove('show'), 3500);
    }

    function calculate() {
        let allocation = 0;
        document.querySelectorAll('.allocation-input').forEach(input => {
            const check = input.closest('tr')?.querySelector('.allocation-check');
            if (check?.checked) {
                allocation += Number(input.value || 0);
            }
        });

        let split = 0;
        document.querySelectorAll('.split-amount').forEach(input => {
            split += Number(input.value || 0);
        });

        const difference = split - allocation;
        document.getElementById('allocationTotal').textContent = money(allocation);
        document.getElementById('splitTotal').textContent = money(split);
        document.getElementById('differenceTotal').textContent = money(difference);

        const validation = document.getElementById('validationText');
        const valid = allocation > 0 && split > 0 && Math.abs(difference) < 0.01;
        validation.textContent = valid ? 'Ready to save' : 'Totals must match';
        validation.className = valid ? 'status-ok' : 'status-error';
        return {allocation, split, valid};
    }

    supplier?.addEventListener('change', () => {
        const value = supplier.value;
        location.href = 'supplier-payment.php' + (value ? '?supplier_id=' + encodeURIComponent(value) : '');
    });

    document.addEventListener('input', event => {
        if (event.target.matches('.allocation-input,.split-amount')) calculate();
    });

    document.addEventListener('change', event => {
        if (event.target.matches('.allocation-check')) {
            const input = event.target.closest('tr')?.querySelector('.allocation-input');
            if (input && event.target.checked && Number(input.value || 0) <= 0) {
                input.value = input.dataset.balance || '0.00';
            }
            if (input && !event.target.checked) {
                input.value = '0.00';
            }
            calculate();
        }
    });

    document.getElementById('allocateAll')?.addEventListener('click', () => {
        document.querySelectorAll('.allocation-check').forEach(check => {
            check.checked = true;
            const input = check.closest('tr')?.querySelector('.allocation-input');
            if (input) input.value = input.dataset.balance || '0.00';
        });
        calculate();
    });

    addSplit?.addEventListener('click', () => {
        const row = document.createElement('div');
        row.className = 'split-row';
        row.innerHTML = `
            <select class="form-select split-method" name="split_method_id[]" required>
                <option value="">Select method</option>
                <?php foreach ($paymentMethods as $method): ?>
                    <option value="<?= (int)$method['method_id'] ?>"><?= e($method['method_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="number" class="form-control split-amount" name="split_amount[]" min="0.01" step="0.01" placeholder="Amount" required>
            <input class="form-control" name="split_reference[]" maxlength="100" placeholder="Reference / UTR / Cheque No.">
            <input class="form-control" name="split_remarks[]" maxlength="200" placeholder="Remarks">
            <button type="button" class="remove-split" title="Remove"><i class="fa-solid fa-trash"></i></button>
        `;
        splitRows.appendChild(row);
    });

    document.addEventListener('click', event => {
        const remove = event.target.closest('.remove-split');
        if (!remove) return;
        if (document.querySelectorAll('.split-row').length <= 1) {
            toast(false, 'At least one payment method is required.');
            return;
        }
        remove.closest('.split-row')?.remove();
        calculate();
    });

    form?.addEventListener('submit', async event => {
        event.preventDefault();

        const totals = calculate();
        if (!totals.valid) {
            toast(false, 'Allocation total and split payment total must match.');
            return;
        }

        const data = new FormData(form);
        document.querySelectorAll('.allocation-check').forEach(check => {
            if (check.checked) {
                data.append('purchase_id[]', check.dataset.purchaseId);
            }
        });

        const old = saveButton.innerHTML;
        saveButton.disabled = true;
        saveButton.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Saving...';

        try {
            const response = await fetch('api/supplier-payment-save.php', {
                method: 'POST',
                body: data,
                credentials: 'same-origin',
                headers: {'X-Requested-With':'XMLHttpRequest'}
            });

            const result = await response.json().catch(() => ({
                success:false,
                message:'Invalid server response.'
            }));

            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Unable to save supplier payment.');
            }

            toast(true, result.message);
            setTimeout(() => {
                location.href = 'supplier-payment.php?supplier_id=' + encodeURIComponent(supplier.value);
            }, 600);
        } catch (error) {
            toast(false, error.message);
        } finally {
            saveButton.disabled = false;
            saveButton.innerHTML = old;
        }
    });

    calculate();
})();
</script>
</body>
</html>