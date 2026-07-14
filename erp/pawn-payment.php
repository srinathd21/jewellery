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

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? ($_SESSION['default_branch_id'] ?? 0));
$pawnId = (int)($_GET['pawn_id'] ?? ($_GET['id'] ?? 0));

if ($businessId <= 0 || $branchId <= 0) {
    die('A valid business and branch must be selected.');
}

if (empty($_SESSION['pawn_payment_csrf'])) {
    $_SESSION['pawn_payment_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = (string)$_SESSION['pawn_payment_csrf'];

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
    'border_radius_px' => 12,
    'sidebar_width_px' => 230,
    'sidebar_gradient_1' => '#171c21',
    'sidebar_gradient_2' => '#20272d',
    'sidebar_gradient_3' => '#101419',
];

$stmt = $conn->prepare(
    'SELECT * FROM business_theme_settings WHERE business_id = ? LIMIT 1'
);

if ($stmt) {
    $stmt->bind_param('i', $businessId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    foreach ($theme as $key => $defaultValue) {
        if (isset($row[$key]) && $row[$key] !== '') {
            $theme[$key] = $row[$key];
        }
    }
}

$pageTitle = 'Pawn Payment';
$businessName = (string)($_SESSION['business_name'] ?? 'Jewellery ERP');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($businessName) ?> - Pawn Payment</title>

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
            --sidebar-width:<?= (int)$theme['sidebar_width_px'] ?>px;
            --sidebar-gradient-1:<?= e($theme['sidebar_gradient_1']) ?>;
            --sidebar-gradient-2:<?= e($theme['sidebar_gradient_2']) ?>;
            --sidebar-gradient-3:<?= e($theme['sidebar_gradient_3']) ?>;
        }

        body{
            background:var(--page-bg);
            color:var(--text);
            font-family:<?= json_encode($theme['font_family']) ?>,sans-serif;
        }

        .sidebar{
            background:linear-gradient(
                180deg,
                var(--sidebar-gradient-1),
                var(--sidebar-gradient-2),
                var(--sidebar-gradient-3)
            )!important;
        }

        .page-card,.stat-card{
            background:var(--card-bg);
            border:1px solid var(--line);
            border-radius:var(--radius);
        }

        .page-head{
            padding:15px 17px;
            border-bottom:1px solid var(--line);
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:12px;
        }

        .page-title{
            font:700 20px <?= json_encode($theme['heading_font_family']) ?>,serif;
        }

        .section-title{
            font-size:10px;
            font-weight:800;
            text-transform:uppercase;
            color:var(--primary-dark);
        }

        .card-body-x{padding:15px}

        .stat-grid{
            display:grid;
            grid-template-columns:repeat(4,minmax(0,1fr));
            gap:10px;
        }

        .stat-card{padding:14px}
        .stat-label{font-size:10px;color:var(--muted)}
        .stat-value{font-size:20px;font-weight:800}

        .form-control,.form-select{
            min-height:39px;
            border:1px solid var(--line);
            border-radius:9px;
            background:var(--card-bg);
            color:var(--text);
            font-size:11px;
        }

        .btn-theme{
            border:0;
            border-radius:9px;
            padding:9px 15px;
            color:#fff;
            background:linear-gradient(135deg,var(--primary),var(--primary-dark));
            font-size:11px;
            font-weight:700;
            text-decoration:none;
        }

        .btn-soft{
            border:1px solid var(--line);
            border-radius:9px;
            padding:9px 15px;
            background:var(--card-bg);
            color:var(--text);
            font-size:11px;
            text-decoration:none;
        }

        .info-row{
            display:flex;
            gap:12px;
            padding:8px 0;
            border-bottom:1px dashed var(--line);
        }

        .info-label{
            width:145px;
            flex:0 0 145px;
            color:var(--muted);
            font-size:10px;
        }

        .info-value{
            flex:1;
            font-size:11px;
            font-weight:700;
        }

        .amount-summary{
            padding:15px;
            border:1px solid var(--line);
            border-radius:10px;
            background:var(--primary-soft);
        }

        .amount-row{
            display:flex;
            justify-content:space-between;
            gap:10px;
            padding:7px 0;
            border-bottom:1px dashed color-mix(in srgb,var(--primary-dark) 25%,transparent);
            font-size:11px;
        }

        .amount-row:last-child{
            border-bottom:0;
            font-size:15px;
            font-weight:800;
        }

        .quick-wrap{
            display:flex;
            flex-wrap:wrap;
            gap:6px;
            margin-top:7px;
        }

        .quick-btn{
            border:1px solid var(--line);
            border-radius:7px;
            padding:4px 8px;
            background:var(--card-bg);
            color:var(--text);
            font-size:10px;
        }

        .table{
            margin:0;
            font-size:10px;
        }

        .table th{
            font-size:9px;
            text-transform:uppercase;
            white-space:nowrap;
            color:var(--muted);
            background:color-mix(in srgb,var(--muted) 6%,transparent);
        }

        .table td,.table th{
            padding:10px 12px;
            border-color:var(--line);
            vertical-align:middle;
        }

        .badge-soft{
            padding:4px 8px;
            border-radius:999px;
            font-size:9px;
            font-weight:700;
        }

        .status-active{background:#eaf2ff;color:#2457a7}
        .status-partial{background:#fff4d8;color:#9a6700}
        .status-closed{background:#eaf8f0;color:#168449}

        .loading{
            padding:48px;
            text-align:center;
            color:var(--muted);
        }

        .theme-toast{
            position:fixed;
            top:78px;
            right:18px;
            z-index:20000;
            padding:11px 14px;
            border-radius:10px;
            color:#fff;
            font-size:11px;
            font-weight:600;
            opacity:0;
            transform:translateY(-10px);
            transition:.22s;
        }

        .theme-toast.show{opacity:1;transform:none}
        .theme-toast-success{background:#168449}
        .theme-toast-error{background:#c0392b}

        body.dark-mode,body[data-theme=dark]{
            --page-bg:#0f151b;
            --card-bg:#182129;
            --text:#f3f6f8;
            --muted:#9aa7b3;
            --line:#2c3944;
        }

        @media(max-width:991px){
            .stat-grid{grid-template-columns:1fr 1fr}
        }

        @media(max-width:767px){
            .stat-grid{grid-template-columns:1fr}
            .info-row{display:block}
            .info-label{width:auto;margin-bottom:3px}
        }
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
                    <div class="page-title">Pawn Payment</div>
                    <div class="small text-muted" id="pageSubtitle">
                        Select a pawn and record principal or interest payment.
                    </div>
                </div>

                <div class="d-flex gap-2">
                    <a href="pawn-list.php" class="btn-soft">
                        <i class="fa-solid fa-arrow-left me-1"></i> Pawn List
                    </a>

                    <a href="pawn-view.php?id=<?= $pawnId ?>"
                       id="viewPawnBtn"
                       class="btn-theme"
                       style="<?= $pawnId > 0 ? '' : 'display:none' ?>">
                        <i class="fa-regular fa-eye me-1"></i> View Pawn
                    </a>
                </div>
            </div>
        </div>

        <div class="page-card mb-3">
            <div class="page-head">
                <div class="section-title">Select Pawn Entry</div>
            </div>

            <div class="card-body-x">
                <div class="row g-2">
                    <div class="col-md-8">
                        <label class="form-label">Pawn</label>
                        <select id="pawnSelect" class="form-select">
                            <option value="">Loading pawn entries...</option>
                        </select>
                    </div>

                    <div class="col-md-4 d-flex align-items-end">
                        <button type="button" id="openPawnBtn" class="btn-theme w-100">
                            <i class="fa-solid fa-arrow-right me-1"></i>
                            Open Payment
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div id="loadingBox" class="page-card loading" style="display:none">
            <i class="fa-solid fa-spinner fa-spin me-2"></i>
            Loading pawn payment details...
        </div>

        <div id="paymentContent" style="display:none">
            <div class="stat-grid mb-3">
                <div class="stat-card">
                    <div class="stat-label">Principal Amount</div>
                    <div class="stat-value" id="statPrincipal">₹0.00</div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Outstanding Balance</div>
                    <div class="stat-value" id="statBalance">₹0.00</div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Principal Paid</div>
                    <div class="stat-value" id="statPaid">₹0.00</div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Interest Paid</div>
                    <div class="stat-value" id="statInterestPaid">₹0.00</div>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-lg-6">
                    <div class="page-card h-100">
                        <div class="page-head">
                            <div class="section-title">Pawn Information</div>
                        </div>
                        <div class="card-body-x" id="pawnInformation"></div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="page-card h-100">
                        <div class="page-head">
                            <div class="section-title">Customer Information</div>
                        </div>
                        <div class="card-body-x" id="customerInformation"></div>
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-xl-8">
                    <div class="page-card">
                        <div class="page-head">
                            <div class="section-title">Payment Entry</div>
                        </div>

                        <div class="card-body-x">
                            <form id="paymentForm">
                                <input type="hidden" name="action" value="save">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="pawn_id" id="pawnIdInput" value="<?= $pawnId ?>">

                                <div class="row g-2">
                                    <div class="col-md-4">
                                        <label class="form-label">Payment Date</label>
                                        <input type="date"
                                               name="payment_date"
                                               class="form-control"
                                               value="<?= date('Y-m-d') ?>"
                                               required>
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label">Payment Type</label>
                                        <select name="payment_type"
                                                id="paymentType"
                                                class="form-select"
                                                required>
                                            <option value="Part Payment">Part Payment</option>
                                            <option value="Interest Only">Interest Only</option>
                                            <option value="Settlement">Settlement</option>
                                            <option value="Release">Full Release</option>
                                        </select>
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label">Payment Method</label>
                                        <select name="payment_method_id"
                                                id="paymentMethod"
                                                class="form-select">
                                            <option value="">Select Method</option>
                                        </select>
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label">Principal Amount</label>
                                        <input type="number"
                                               step="0.01"
                                               min="0"
                                               name="principal_amount"
                                               id="principalAmount"
                                               class="form-control"
                                               value="0">

                                        <div class="quick-wrap">
                                            <button type="button" class="quick-btn" data-amount="500">+₹500</button>
                                            <button type="button" class="quick-btn" data-amount="1000">+₹1,000</button>
                                            <button type="button" class="quick-btn" data-amount="5000">+₹5,000</button>
                                            <button type="button" class="quick-btn" data-amount="10000">+₹10,000</button>
                                            <button type="button" class="quick-btn" id="fullSettlementBtn">Full Balance</button>
                                        </div>
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label">Interest Amount</label>
                                        <input type="number"
                                               step="0.01"
                                               min="0"
                                               name="interest_amount"
                                               id="interestAmount"
                                               class="form-control"
                                               value="0">
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label">Penalty Amount</label>
                                        <input type="number"
                                               step="0.01"
                                               min="0"
                                               name="penalty_amount"
                                               id="penaltyAmount"
                                               class="form-control"
                                               value="0">
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label">Charges Amount</label>
                                        <input type="number"
                                               step="0.01"
                                               min="0"
                                               name="charges_amount"
                                               id="chargesAmount"
                                               class="form-control"
                                               value="0">
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label">Discount Amount</label>
                                        <input type="number"
                                               step="0.01"
                                               min="0"
                                               name="discount_amount"
                                               id="discountAmount"
                                               class="form-control"
                                               value="0">
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label">Reference Number</label>
                                        <input type="text"
                                               name="reference_no"
                                               class="form-control"
                                               placeholder="Cheque / UTR / Transaction">
                                    </div>

                                    <div class="col-12">
                                        <label class="form-label">Remarks</label>
                                        <textarea name="remarks"
                                                  class="form-control"
                                                  rows="3"></textarea>
                                    </div>

                                    <div class="col-12 d-flex justify-content-end gap-2 mt-2">
                                        <button type="reset" class="btn-soft" id="resetPaymentBtn">
                                            Reset
                                        </button>

                                        <button type="submit" id="saveBtn" class="btn-theme">
                                            <i class="fa-solid fa-floppy-disk me-1"></i>
                                            Record Payment
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-xl-4">
                    <div class="page-card h-100">
                        <div class="page-head">
                            <div class="section-title">Payment Summary</div>
                        </div>

                        <div class="card-body-x">
                            <div class="amount-summary">
                                <div class="amount-row">
                                    <span>Principal</span>
                                    <strong id="sumPrincipal">₹0.00</strong>
                                </div>

                                <div class="amount-row">
                                    <span>Interest</span>
                                    <strong id="sumInterest">₹0.00</strong>
                                </div>

                                <div class="amount-row">
                                    <span>Penalty</span>
                                    <strong id="sumPenalty">₹0.00</strong>
                                </div>

                                <div class="amount-row">
                                    <span>Charges</span>
                                    <strong id="sumCharges">₹0.00</strong>
                                </div>

                                <div class="amount-row">
                                    <span>Discount</span>
                                    <strong id="sumDiscount">-₹0.00</strong>
                                </div>

                                <div class="amount-row">
                                    <span>Total Amount</span>
                                    <strong id="sumTotal">₹0.00</strong>
                                </div>
                            </div>

                            <input type="hidden"
                                   form="paymentForm"
                                   name="total_amount"
                                   id="totalAmount"
                                   value="0">
                        </div>
                    </div>
                </div>
            </div>

            <div class="page-card">
                <div class="page-head">
                    <div class="section-title">Payment History</div>
                    <span class="small text-muted" id="historyCount">0 records</span>
                </div>

                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Receipt</th>
                                <th>Date</th>
                                <th>Type</th>
                                <th class="text-end">Principal</th>
                                <th class="text-end">Interest</th>
                                <th class="text-end">Penalty</th>
                                <th class="text-end">Charges</th>
                                <th class="text-end">Discount</th>
                                <th class="text-end">Total</th>
                                <th>Method</th>
                                <th>Reference</th>
                            </tr>
                        </thead>
                        <tbody id="historyBody"></tbody>
                    </table>
                </div>
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

    const apiUrl = 'api/pawn-payment.php';
    const csrfToken = <?= json_encode($csrfToken) ?>;
    const initialPawnId = <?= $pawnId ?>;

    let currentPawn = null;
    let currentBalance = 0;

    function esc(value) {
        return String(value ?? '').replace(/[&<>'"]/g, character => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            "'": '&#039;',
            '"': '&quot;'
        }[character]));
    }

    function money(value) {
        return Number(value || 0).toLocaleString('en-IN', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
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
        }, 3400);
    }

    async function request(data) {
        const formData = new FormData();

        Object.entries(data).forEach(([key, value]) => {
            formData.append(key, value);
        });

        formData.append('csrf_token', csrfToken);

        const response = await fetch(apiUrl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        });

        const raw = await response.text();
        let result;

        try {
            result = JSON.parse(raw);
        } catch (error) {
            const clean = raw
                .replace(/<[^>]*>/g, ' ')
                .replace(/\s+/g, ' ')
                .trim();

            throw new Error(
                'Pawn Payment API did not return JSON. HTTP ' +
                response.status +
                (clean ? ': ' + clean.substring(0, 300) : '')
            );
        }

        if (!response.ok || !result.success) {
            throw new Error(result.message || 'Request failed.');
        }

        return result;
    }

    function infoRow(label, value) {
        return `
            <div class="info-row">
                <div class="info-label">${esc(label)}</div>
                <div class="info-value">${value || '-'}</div>
            </div>
        `;
    }

    function calculateTotal() {
        const principal = Number(
            document.getElementById('principalAmount').value || 0
        );
        const interest = Number(
            document.getElementById('interestAmount').value || 0
        );
        const penalty = Number(
            document.getElementById('penaltyAmount').value || 0
        );
        const charges = Number(
            document.getElementById('chargesAmount').value || 0
        );
        const discount = Number(
            document.getElementById('discountAmount').value || 0
        );

        const total = Math.max(
            0,
            principal + interest + penalty + charges - discount
        );

        document.getElementById('sumPrincipal').textContent =
            '₹' + money(principal);
        document.getElementById('sumInterest').textContent =
            '₹' + money(interest);
        document.getElementById('sumPenalty').textContent =
            '₹' + money(penalty);
        document.getElementById('sumCharges').textContent =
            '₹' + money(charges);
        document.getElementById('sumDiscount').textContent =
            '-₹' + money(discount);
        document.getElementById('sumTotal').textContent =
            '₹' + money(total);
        document.getElementById('totalAmount').value =
            total.toFixed(2);

        const principalField = document.getElementById('principalAmount');

        if (principal > currentBalance + 0.01) {
            principalField.classList.add('is-invalid');
        } else {
            principalField.classList.remove('is-invalid');
        }
    }

    function renderHistory(history) {
        document.getElementById('historyCount').textContent =
            history.length + ' records';

        document.getElementById('historyBody').innerHTML = history.length
            ? history.map(payment => `
                <tr>
                    <td><strong>${esc(payment.receipt_no)}</strong></td>
                    <td>${esc(payment.payment_date_display)}</td>
                    <td>${esc(payment.payment_type)}</td>
                    <td class="text-end">₹${money(payment.principal_amount)}</td>
                    <td class="text-end">₹${money(payment.interest_amount)}</td>
                    <td class="text-end">₹${money(payment.penalty_amount)}</td>
                    <td class="text-end">₹${money(payment.charges_amount)}</td>
                    <td class="text-end">₹${money(payment.discount_amount)}</td>
                    <td class="text-end"><strong>₹${money(payment.total_amount)}</strong></td>
                    <td>${esc(payment.method_name || '-')}</td>
                    <td>${esc(payment.reference_no || '-')}</td>
                </tr>
            `).join('')
            : '<tr><td colspan="11" class="text-center text-muted py-4">No pawn payments found.</td></tr>';
    }

    async function loadPawnOptions() {
        try {
            const data = await request({ action: 'list_pawns' });
            const selector = document.getElementById('pawnSelect');

            selector.innerHTML =
                '<option value="">Select Pawn Entry</option>' +
                data.pawns.map(pawn => `
                    <option value="${pawn.id}">
                        ${esc(pawn.pawn_no)} -
                        ${esc(pawn.customer_name || 'Unknown Customer')} -
                        Balance ₹${money(pawn.balance_principal)}
                    </option>
                `).join('');

            if (initialPawnId > 0) {
                selector.value = String(initialPawnId);
                await loadPawn(initialPawnId);
            }
        } catch (error) {
            toast('error', error.message);
        }
    }

    async function loadPawn(pawnId) {
        if (!pawnId) {
            document.getElementById('paymentContent').style.display = 'none';
            return;
        }

        document.getElementById('loadingBox').style.display = '';
        document.getElementById('paymentContent').style.display = 'none';

        try {
            const data = await request({
                action: 'load',
                pawn_id: pawnId
            });

            currentPawn = data.pawn;
            currentBalance = Number(currentPawn.balance_principal || 0);

            document.getElementById('pawnIdInput').value = currentPawn.id;
            document.getElementById('pawnSelect').value = String(currentPawn.id);
            document.getElementById('viewPawnBtn').href =
                'pawn-view.php?id=' + currentPawn.id;
            document.getElementById('viewPawnBtn').style.display = '';

            document.getElementById('pageSubtitle').textContent =
                currentPawn.pawn_no + ' - ' + currentPawn.customer_name;

            document.getElementById('statPrincipal').textContent =
                '₹' + money(currentPawn.principal_amount);
            document.getElementById('statBalance').textContent =
                '₹' + money(currentPawn.balance_principal);
            document.getElementById('statPaid').textContent =
                '₹' + money(data.total_principal_paid);
            document.getElementById('statInterestPaid').textContent =
                '₹' + money(data.total_interest_paid);

            let statusClass = 'status-active';

            if (currentPawn.status === 'Partially Paid') {
                statusClass = 'status-partial';
            }

            if (currentPawn.status === 'Closed') {
                statusClass = 'status-closed';
            }

            document.getElementById('pawnInformation').innerHTML =
                infoRow('Pawn Number', esc(currentPawn.pawn_no)) +
                infoRow('Pawn Date', esc(currentPawn.pawn_date_display)) +
                infoRow('Category', esc(currentPawn.category_name || '-')) +
                infoRow(
                    'Interest',
                    Number(currentPawn.interest_percent || 0).toFixed(3) +
                    '% ' +
                    esc(currentPawn.interest_period)
                ) +
                infoRow(
                    'Status',
                    `<span class="badge-soft ${statusClass}">${esc(currentPawn.status)}</span>`
                );

            document.getElementById('customerInformation').innerHTML =
                infoRow('Customer', esc(currentPawn.customer_name || 'Unknown Customer')) +
                infoRow('Mobile', esc(currentPawn.mobile || '-')) +
                infoRow('Customer Code', esc(currentPawn.customer_code || '-')) +
                infoRow('Principal', '₹' + money(currentPawn.principal_amount)) +
                infoRow('Outstanding', '₹' + money(currentPawn.balance_principal));

            document.getElementById('paymentMethod').innerHTML =
                '<option value="">Select Method</option>' +
                data.payment_methods.map(method => `
                    <option value="${method.id}">
                        ${esc(method.method_name)}
                    </option>
                `).join('');

            renderHistory(data.history);

            document.getElementById('principalAmount').value = '0';
            document.getElementById('interestAmount').value = '0';
            document.getElementById('penaltyAmount').value = '0';
            document.getElementById('chargesAmount').value = '0';
            document.getElementById('discountAmount').value = '0';
            calculateTotal();

            document.getElementById('loadingBox').style.display = 'none';
            document.getElementById('paymentContent').style.display = '';
        } catch (error) {
            document.getElementById('loadingBox').innerHTML =
                '<div class="text-danger fw-bold">Unable to load pawn payment</div>' +
                '<div class="small mt-2">' + esc(error.message) + '</div>';

            toast('error', error.message);
        }
    }

    function openSelectedPawn() {
        const pawnId = document.getElementById('pawnSelect').value;

        if (!pawnId) {
            toast('error', 'Select a pawn entry.');
            return;
        }

        window.history.replaceState(
            {},
            '',
            'pawn-payment.php?pawn_id=' + encodeURIComponent(pawnId)
        );

        loadPawn(Number(pawnId));
    }

    document.getElementById('openPawnBtn').addEventListener(
        'click',
        openSelectedPawn
    );

    document.getElementById('pawnSelect').addEventListener(
        'change',
        event => {
            if (event.target.value) {
                openSelectedPawn();
            }
        }
    );

    [
        'principalAmount',
        'interestAmount',
        'penaltyAmount',
        'chargesAmount',
        'discountAmount'
    ].forEach(id => {
        document.getElementById(id).addEventListener('input', calculateTotal);
    });

    document.querySelectorAll('.quick-btn[data-amount]').forEach(button => {
        button.addEventListener('click', () => {
            const field = document.getElementById('principalAmount');
            const next = Math.min(
                currentBalance,
                Number(field.value || 0) + Number(button.dataset.amount || 0)
            );

            field.value = next.toFixed(2);
            calculateTotal();
        });
    });

    document.getElementById('fullSettlementBtn').addEventListener(
        'click',
        () => {
            document.getElementById('principalAmount').value =
                currentBalance.toFixed(2);
            document.getElementById('paymentType').value = 'Release';
            calculateTotal();
        }
    );

    document.getElementById('paymentType').addEventListener(
        'change',
        event => {
            if (event.target.value === 'Release') {
                document.getElementById('principalAmount').value =
                    currentBalance.toFixed(2);
            }

            if (event.target.value === 'Interest Only') {
                document.getElementById('principalAmount').value = '0';
            }

            calculateTotal();
        }
    );

    document.getElementById('resetPaymentBtn').addEventListener(
        'click',
        () => {
            setTimeout(calculateTotal, 0);
        }
    );

    document.getElementById('paymentForm').addEventListener(
        'submit',
        async event => {
            event.preventDefault();

            const principal = Number(
                document.getElementById('principalAmount').value || 0
            );
            const total = Number(
                document.getElementById('totalAmount').value || 0
            );

            if (!currentPawn) {
                toast('error', 'Select a pawn entry.');
                return;
            }

            if (principal > currentBalance + 0.01) {
                toast(
                    'error',
                    'Principal payment cannot exceed the outstanding balance.'
                );
                return;
            }

            if (total <= 0) {
                toast('error', 'Total payment must be greater than zero.');
                return;
            }

            const confirmed = confirm(
                'Record this pawn payment?\n\n' +
                'Principal: ₹' + money(principal) + '\n' +
                'Interest: ₹' + money(
                    document.getElementById('interestAmount').value
                ) + '\n' +
                'Total: ₹' + money(total)
            );

            if (!confirmed) {
                return;
            }

            const button = document.getElementById('saveBtn');
            const oldText = button.innerHTML;

            button.disabled = true;
            button.innerHTML =
                '<i class="fa-solid fa-spinner fa-spin me-1"></i> Saving';

            try {
                const response = await fetch(apiUrl, {
                    method: 'POST',
                    body: new FormData(event.target),
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                const raw = await response.text();
                let result;

                try {
                    result = JSON.parse(raw);
                } catch (error) {
                    throw new Error(
                        'Pawn Payment API did not return JSON. HTTP ' +
                        response.status +
                        ': ' +
                        raw.replace(/<[^>]*>/g, ' ')
                           .replace(/\s+/g, ' ')
                           .trim()
                           .substring(0, 300)
                    );
                }

                if (!response.ok || !result.success) {
                    throw new Error(
                        result.message || 'Unable to save pawn payment.'
                    );
                }

                toast(
                    'success',
                    result.message + ' Receipt: ' + result.receipt_no
                );

                await loadPawn(currentPawn.id);
            } catch (error) {
                toast('error', error.message);
            } finally {
                button.disabled = false;
                button.innerHTML = oldText;
            }
        }
    );

    loadPawnOptions();
})();
</script>
</body>
</html>
