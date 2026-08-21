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
    header('Location: login.php');
    exit;
}

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function queryAll(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($conn->error);
    }

    if ($types !== '') {
        $bind = [$types];
        foreach ($params as $index => $value) {
            $bind[] =& $params[$index];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind);
    }

    if (!$stmt->execute()) {
        throw new RuntimeException($stmt->error);
    }

    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();

    return $rows;
}

function paymentPermission(mysqli $conn): bool
{
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin') {
        return true;
    }

    foreach (['perm.payments', 'perm.sales.list', 'perm.sales', 'perm.billing'] as $code) {
        if (!empty($_SESSION['permissions'][$code]['can_create']) || !empty($_SESSION['permissions'][$code]['can_update'])) {
            return true;
        }
    }

    $businessId = (int)($_SESSION['business_id'] ?? 0);
    $roleId = (int)($_SESSION['role_id'] ?? 0);
    if ($businessId <= 0 || $roleId <= 0) {
        return false;
    }

    $stmt = $conn->prepare("SELECT rp.can_create,rp.can_update
        FROM role_permissions rp
        INNER JOIN permissions p ON p.id=rp.permission_id
        WHERE rp.business_id=? AND rp.role_id=? AND p.is_active=1
          AND p.permission_code IN ('perm.payments','perm.sales.list','perm.sales','perm.billing')");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ii', $businessId, $roleId);
    $stmt->execute();
    $result = $stmt->get_result();
    $allowed = false;
    while ($row = $result->fetch_assoc()) {
        if ((int)($row['can_create'] ?? 0) === 1 || (int)($row['can_update'] ?? 0) === 1) {
            $allowed = true;
            break;
        }
    }
    $stmt->close();
    return $allowed;
}

$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? ($_SESSION['default_branch_id'] ?? 0));
$saleId = (int)($_GET['id'] ?? 0);

if ($businessId <= 0 || $branchId <= 0 || $saleId <= 0) {
    die('Invalid sale.');
}

if (!paymentPermission($conn)) {
    http_response_code(403);
    die('You do not have permission to receive or adjust sale payments.');
}

if (empty($_SESSION['sales_payment_csrf'])) {
    $_SESSION['sales_payment_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['sales_payment_csrf'];

try {
    $saleRows = queryAll(
        $conn,
        "SELECT s.*, c.customer_code, c.email
         FROM sales s
         LEFT JOIN customers c
           ON c.id=s.customer_id
          AND c.business_id=s.business_id
         WHERE s.id=?
           AND s.business_id=?
           AND s.branch_id=?
         LIMIT 1",
        'iii',
        [$saleId, $businessId, $branchId]
    );

    if (!$saleRows) {
        die('Sale not found.');
    }

    $sale = $saleRows[0];

    $paymentMethods = queryAll(
        $conn,
        "SELECT id,method_name,method_type
         FROM payment_methods
         WHERE business_id=?
           AND is_active=1
           AND LOWER(COALESCE(method_type,'')) <> 'credit'
           AND LOWER(method_name) NOT LIKE '%credit%'
           AND LOWER(method_name) NOT LIKE '%due%'
           AND LOWER(method_name) NOT LIKE '%pay later%'
         ORDER BY method_name",
        'i',
        [$businessId]
    );

    $existingPayments = queryAll(
        $conn,
        "SELECT sp.*,pm.method_name
         FROM sale_payments sp
         LEFT JOIN payment_methods pm ON pm.id=sp.payment_method_id
         WHERE sp.sale_id=?
           AND sp.business_id=?
         ORDER BY sp.id DESC",
        'ii',
        [$saleId, $businessId]
    );
} catch (Throwable $error) {
    die('Unable to load payment page: ' . e($error->getMessage()));
}

if ($sale['workflow_status'] === 'Cancelled') {
    die('Payment cannot be added to a cancelled invoice.');
}

$balanceAmount = max(0, (float)$sale['balance_amount']);
$existingDiscount = max(0, (float)$sale['discount_amount']);

$theme = [
    'primary_color' => '#d89416',
    'primary_dark_color' => '#b86a0b',
    'primary_soft_color' => '#fff6e5',
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

    foreach ($theme as $key => $defaultValue) {
        if (isset($themeRow[$key]) && $themeRow[$key] !== '') {
            $theme[$key] = $themeRow[$key];
        }
    }
}

function money($value): string
{
    return number_format((float)$value, 2);
}

$pageTitle = 'Make Payment';
$businessName = (string)($_SESSION['business_name'] ?? 'Jewellery ERP');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($businessName) ?> - Make Payment</title>
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

        body{
            background:var(--page-bg);
            color:var(--text);
            font-family:<?= json_encode($theme['font_family']) ?>,sans-serif;
        }

        .page-card{
            background:var(--card-bg);
            border:1px solid var(--line);
            border-radius:var(--radius);
            margin-bottom:12px;
            overflow:hidden;
        }

        .page-head{
            padding:14px 16px;
            border-bottom:1px solid var(--line);
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
        }

        .page-title{
            font:700 21px <?= json_encode($theme['heading_font_family']) ?>,serif;
        }

        .page-body{
            padding:15px;
        }

        .summary-grid{
            display:grid;
            grid-template-columns:repeat(5,minmax(0,1fr));
            gap:10px;
        }

        .summary-box{
            border:1px solid var(--line);
            border-radius:10px;
            padding:12px;
            background:color-mix(in srgb,var(--primary) 3%,var(--card-bg));
        }

        .summary-label{
            color:var(--muted);
            font-size:9px;
            text-transform:uppercase;
            font-weight:700;
        }

        .summary-value{
            font-size:16px;
            font-weight:900;
        }

        .summary-box.balance{
            background:var(--primary-soft);
            border-color:color-mix(in srgb,var(--primary) 35%,var(--line));
        }

        .split-table{
            min-width:760px;
            margin:0;
            font-size:10px;
        }

        .split-table th{
            font-size:9px;
            text-transform:uppercase;
            color:var(--muted);
            background:color-mix(in srgb,var(--muted) 6%,transparent);
        }

        .split-table td,.split-table th{
            padding:9px;
            border-color:var(--line);
            vertical-align:middle;
        }

        .form-control,.form-select{
            min-height:38px;
            font-size:11px;
            border-color:var(--line);
            background:var(--card-bg);
            color:var(--text);
            border-radius:8px;
        }

        .btn-theme{
            border:0;
            border-radius:8px;
            padding:9px 13px;
            min-height:38px;
            background:linear-gradient(135deg,var(--primary),var(--primary-dark));
            color:#fff;
            font-size:11px;
            font-weight:800;
        }

        .btn-soft{
            border:1px solid var(--line);
            border-radius:8px;
            padding:9px 13px;
            min-height:38px;
            background:var(--card-bg);
            color:var(--text);
            font-size:11px;
        }

        .discount-panel{
            margin:14px 15px 0;
            padding:14px;
            border:1px solid var(--line);
            border-radius:10px;
            background:color-mix(in srgb,var(--primary) 4%,var(--card-bg));
        }
        .discount-grid{
            display:grid;
            grid-template-columns:minmax(180px,.7fr) minmax(260px,1.3fr) repeat(2,minmax(150px,.7fr));
            gap:10px;
            align-items:end;
        }
        .discount-stat{
            border:1px solid var(--line);
            border-radius:9px;
            padding:10px;
            background:var(--card-bg);
        }
        .discount-stat-label{font-size:9px;color:var(--muted);text-transform:uppercase;font-weight:700}
        .discount-stat-value{font-size:16px;font-weight:900}

        .payment-total-bar{
            display:flex;
            align-items:center;
            justify-content:flex-end;
            gap:24px;
            flex-wrap:wrap;
            padding:13px 15px;
            border-top:1px solid var(--line);
            background:color-mix(in srgb,var(--primary) 2%,var(--card-bg));
        }

        .total-item{
            min-width:150px;
            text-align:right;
        }

        .total-label{
            color:var(--muted);
            font-size:9px;
            text-transform:uppercase;
        }

        .total-value{
            font-size:18px;
            font-weight:900;
        }

        .remaining-value{
            color:var(--primary-dark);
        }

        .theme-toast{
            position:fixed;
            right:18px;
            top:78px;
            z-index:20000;
            padding:11px 14px;
            border-radius:10px;
            color:#fff;
            font-size:11px;
            opacity:0;
            transform:translateY(-10px);
            transition:.2s;
        }

        .theme-toast.show{opacity:1;transform:none}
        .theme-toast-success{background:#168449}
        .theme-toast-error{background:#c0392b}

        @media(max-width:900px){
            .summary-grid{grid-template-columns:1fr 1fr}
            .discount-grid{grid-template-columns:1fr 1fr}
        }

        @media(max-width:600px){
            .summary-grid,.discount-grid{grid-template-columns:1fr}
            .page-head{align-items:flex-start;flex-direction:column}
            .payment-total-bar{justify-content:stretch}
            .total-item{text-align:left}
        }
    </style>
</head>
<body>
<?php include('includes/sidebar.php'); ?>
<main class="app-main">
    <?php include('includes/nav.php'); ?>

    <div class="content-wrap">
        <div class="page-card">
            <div class="page-head">
                <div>
                    <div class="page-title">Make Payment</div>
                    <div class="small text-muted">
                        Invoice <?= e($sale['invoice_no']) ?> · <?= e($sale['customer_name'] ?: 'Walk-in Customer') ?>
                    </div>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <a href="sales-view.php?id=<?= $saleId ?>" class="btn-soft text-decoration-none">
                        <i class="fa-solid fa-arrow-left me-1"></i>Sale Details
                    </a>
                    <a href="sales-list.php" class="btn-soft text-decoration-none">
                        <i class="fa-solid fa-list me-1"></i>Sales List
                    </a>
                </div>
            </div>

            <div class="page-body">
                <div class="summary-grid">
                    <div class="summary-box">
                        <div class="summary-label">Current Invoice Total</div>
                        <div class="summary-value">₹<?= money($sale['net_payable_amount']) ?></div>
                    </div>
                    <div class="summary-box">
                        <div class="summary-label">Total Discount Given</div>
                        <div class="summary-value">₹<?= money($existingDiscount) ?></div>
                    </div>
                    <div class="summary-box">
                        <div class="summary-label">Already Paid</div>
                        <div class="summary-value">₹<?= money($sale['paid_amount']) ?></div>
                    </div>
                    <div class="summary-box balance">
                        <div class="summary-label">Current Balance</div>
                        <div class="summary-value">₹<?= money($balanceAmount) ?></div>
                    </div>
                    <div class="summary-box">
                        <div class="summary-label">Payment Status</div>
                        <div class="summary-value"><?= e($sale['payment_status']) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($balanceAmount <= 0.009): ?>
            <div class="page-card">
                <div class="page-body text-center py-5">
                    <i class="fa-solid fa-circle-check fa-3x text-success mb-3"></i>
                    <h5>This invoice is fully paid.</h5>
                    <a href="sales-view.php?id=<?= $saleId ?>" class="btn-theme text-decoration-none mt-2">
                        View Sale
                    </a>
                </div>
            </div>
        <?php else: ?>
            <form id="multiPaymentForm" class="page-card">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="sale_id" value="<?= $saleId ?>">

                <div class="discount-panel">
                    <div class="fw-bold mb-2">Additional Discount</div>
                    <div class="small text-muted mb-3">
                        This is added to the discount already given in Billing. Example: old discount ₹10 + additional ₹10 = total discount ₹20.
                    </div>
                    <div class="discount-grid">
                        <div>
                            <label class="form-label small fw-bold">Additional Discount</label>
                            <input type="number" name="additional_discount" id="additionalDiscount"
                                   class="form-control" min="0" step="0.01" value="0.00"
                                   max="<?= e(number_format($balanceAmount, 2, '.', '')) ?>">
                        </div>
                        <div>
                            <label class="form-label small fw-bold">Discount Reason</label>
                            <input type="text" name="discount_reason" id="discountReason"
                                   class="form-control" maxlength="255"
                                   placeholder="Optional reason for additional discount">
                        </div>
                        <div class="discount-stat">
                            <div class="discount-stat-label">Total Discount After This</div>
                            <div class="discount-stat-value">₹<span id="totalDiscountAfter"><?= money($existingDiscount) ?></span></div>
                        </div>
                        <div class="discount-stat">
                            <div class="discount-stat-label">Balance After Discount</div>
                            <div class="discount-stat-value">₹<span id="balanceAfterDiscount"><?= money($balanceAmount) ?></span></div>
                        </div>
                    </div>
                </div>

                <div class="page-head">
                    <div>
                        <div class="fw-bold">Split Payment</div>
                        <div class="small text-muted">
                            Add multiple payment methods, for example UPI ₹1,000 and Cash ₹2,000.
                        </div>
                    </div>
                    <button type="button" class="btn-theme" id="addPaymentRow">
                        <i class="fa-solid fa-plus me-1"></i>Add Payment
                    </button>
                </div>

                <div class="table-responsive">
                    <table class="table split-table">
                        <thead>
                            <tr>
                                <th style="width:30%">Payment Method</th>
                                <th style="width:22%">Amount</th>
                                <th>Reference / Transaction ID</th>
                                <th style="width:54px"></th>
                            </tr>
                        </thead>
                        <tbody id="paymentRows"></tbody>
                    </table>
                </div>

                <div class="page-body">
                    <label class="form-label small fw-bold">Payment Remarks</label>
                    <textarea name="remarks" class="form-control" rows="2"
                              placeholder="Optional remarks for this payment transaction"></textarea>
                </div>

                <div class="payment-total-bar">
                    <div class="total-item">
                        <div class="total-label">Current Balance</div>
                        <div class="total-value">₹<?= money($balanceAmount) ?></div>
                    </div>
                    <div class="total-item">
                        <div class="total-label">Additional Discount</div>
                        <div class="total-value">- ₹<span id="discountTotalBar">0.00</span></div>
                    </div>
                    <div class="total-item">
                        <div class="total-label">Payment Total</div>
                        <div class="total-value">- ₹<span id="paymentTotal">0.00</span></div>
                    </div>
                    <div class="total-item">
                        <div class="total-label">Final Balance</div>
                        <div class="total-value remaining-value">
                            ₹<span id="remainingBalance"><?= money($balanceAmount) ?></span>
                        </div>
                    </div>
                    <button type="submit" class="btn-theme" id="savePayments">
                        <i class="fa-solid fa-floppy-disk me-1"></i>Save Payment / Discount
                    </button>
                </div>
            </form>
        <?php endif; ?>

        <div class="page-card">
            <div class="page-head">
                <div class="fw-bold">Previous Payments</div>
            </div>
            <div class="table-responsive">
                <table class="table split-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Method</th>
                            <th>Reference</th>
                            <th class="text-end">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($existingPayments): ?>
                        <?php foreach ($existingPayments as $payment): ?>
                            <tr>
                                <td><?= e(date('d-m-Y h:i A', strtotime($payment['payment_date']))) ?></td>
                                <td><?= e($payment['method_name'] ?: 'Unknown') ?></td>
                                <td><?= e($payment['reference_no'] ?: '-') ?></td>
                                <td class="text-end"><strong>₹<?= money($payment['amount']) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" class="text-center text-muted py-4">No payments recorded.</td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php include('includes/footer.php'); ?>
    </div>
</main>

<?php include('includes/script.php'); ?>
<script src="assets/js/script.js"></script>
<script>
(() => {
    'use strict';

    const methods = <?= json_encode($paymentMethods, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const outstanding = <?= json_encode($balanceAmount) ?>;
    const existingDiscount = <?= json_encode($existingDiscount) ?>;
    const discountInput = document.getElementById('additionalDiscount');
    const rows = document.getElementById('paymentRows');

    if (!rows) {
        return;
    }

    function esc(value) {
        return String(value ?? '').replace(/[&<>'"]/g, character => ({
            '&':'&amp;',
            '<':'&lt;',
            '>':'&gt;',
            "'":'&#039;',
            '"':'&quot;'
        }[character]));
    }

    function money(value) {
        return Number(value || 0).toFixed(2);
    }

    function toast(type, message) {
        const element = document.createElement('div');
        element.className = 'theme-toast theme-toast-' + type;
        element.textContent = message;
        document.body.appendChild(element);
        requestAnimationFrame(() => element.classList.add('show'));
        setTimeout(() => {
            element.classList.remove('show');
            setTimeout(() => element.remove(), 250);
        }, 3200);
    }

    function methodOptions() {
        return '<option value="">Select method</option>' + methods.map(method =>
            '<option value="' + Number(method.id) + '">' + esc(method.method_name) + '</option>'
        ).join('');
    }

    function addRow(defaultAmount = '') {
        rows.insertAdjacentHTML('beforeend', `
            <tr class="payment-row">
                <td>
                    <select name="payment_method_id[]" class="form-select payment-method">
                        ${methodOptions()}
                    </select>
                </td>
                <td>
                    <input type="number" name="payment_amount[]" min="0.01" step="0.01"
                           class="form-control payment-amount" value="${esc(defaultAmount)}"
                           placeholder="0.00">
                </td>
                <td>
                    <input name="payment_reference[]" class="form-control"
                           placeholder="UPI / Bank / Transaction reference">
                </td>
                <td class="text-end">
                    <button type="button" class="btn btn-outline-danger btn-sm remove-payment">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </td>
            </tr>
        `);

        calculate();
    }

    function calculate() {
        let total = 0;

        rows.querySelectorAll('.payment-amount').forEach(input => {
            total += Math.max(0, Number(input.value) || 0);
        });

        const additionalDiscount = Math.max(0, Number(discountInput?.value) || 0);
        const afterDiscount = Math.max(0, outstanding - additionalDiscount);
        const remaining = Math.max(0, afterDiscount - total);

        document.getElementById('paymentTotal').textContent = money(total);
        document.getElementById('discountTotalBar').textContent = money(additionalDiscount);
        document.getElementById('totalDiscountAfter').textContent = money(existingDiscount + additionalDiscount);
        document.getElementById('balanceAfterDiscount').textContent = money(afterDiscount);
        document.getElementById('remainingBalance').textContent = money(remaining);

        const totalElement = document.getElementById('paymentTotal');
        totalElement.classList.toggle('text-danger', total > afterDiscount + 0.009);
        if (discountInput) {
            discountInput.classList.toggle('is-invalid', additionalDiscount > outstanding + 0.009);
        }
    }

    document.getElementById('addPaymentRow').addEventListener('click', () => addRow());
    if (discountInput) discountInput.addEventListener('input', calculate);

    rows.addEventListener('input', event => {
        if (event.target.classList.contains('payment-amount')) {
            calculate();
        }
    });

    rows.addEventListener('click', event => {
        const button = event.target.closest('.remove-payment');
        if (!button) return;

        const currentRows = rows.querySelectorAll('.payment-row');
        if (currentRows.length <= 1) {
            toast('error', 'At least one payment row is required.');
            return;
        }

        button.closest('.payment-row').remove();
        calculate();
    });

    document.getElementById('multiPaymentForm').addEventListener('submit', async event => {
        event.preventDefault();

        let total = 0;
        let validRows = 0;
        let invalidPartialRow = false;

        rows.querySelectorAll('.payment-row').forEach(row => {
            const method = Number(row.querySelector('.payment-method').value || 0);
            const amount = Number(row.querySelector('.payment-amount').value || 0);

            if ((method > 0 && amount <= 0) || (method <= 0 && amount > 0)) {
                invalidPartialRow = true;
            }
            if (method > 0 && amount > 0) {
                validRows++;
                total += amount;
            }
        });

        const additionalDiscount = Math.max(0, Number(discountInput?.value) || 0);
        const balanceAfterDiscount = Math.max(0, outstanding - additionalDiscount);

        if (additionalDiscount > outstanding + 0.009) {
            toast('error', 'Additional discount cannot exceed the current balance.');
            return;
        }

        if (invalidPartialRow) {
            toast('error', 'Each used payment row must have both method and amount.');
            return;
        }

        if (validRows === 0 && additionalDiscount <= 0.009) {
            toast('error', 'Enter an additional discount or add at least one payment.');
            return;
        }

        if (total > balanceAfterDiscount + 0.009) {
            toast('error', 'Payment total cannot exceed the balance after discount.');
            return;
        }

        const button = document.getElementById('savePayments');
        const oldHtml = button.innerHTML;
        button.disabled = true;
        button.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Saving...';

        try {
            const response = await fetch('api/sale-multi-payment.php', {
                method:'POST',
                body:new FormData(event.currentTarget),
                credentials:'same-origin',
                headers:{
                    'X-Requested-With':'XMLHttpRequest',
                    'Accept':'application/json'
                }
            });

            const raw = await response.text();
            let result;

            try {
                result = JSON.parse(raw);
            } catch (error) {
                throw new Error('Payment API returned an invalid response.');
            }

            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Unable to save payments.');
            }

            toast('success', result.message);

            setTimeout(() => {
                location.href = 'sales-view.php?id=' + encodeURIComponent(result.sale_id) + '&msg=payment_saved';
            }, 700);
        } catch (error) {
            toast('error', error.message);
        } finally {
            button.disabled = false;
            button.innerHTML = oldHtml;
        }
    });

    addRow('');
    calculate();
})();
</script>
</body>
</html>
