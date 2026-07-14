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

$hasSelectedPawn = $pawnId > 0;

if (empty($_SESSION['pawn_interest_csrf'])) {
    $_SESSION['pawn_interest_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = (string)$_SESSION['pawn_interest_csrf'];

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
    $themeRow = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    foreach ($theme as $key => $defaultValue) {
        if (isset($themeRow[$key]) && $themeRow[$key] !== '') {
            $theme[$key] = $themeRow[$key];
        }
    }
}

$pageTitle = 'Pawn Interest';
$businessName = (string)($_SESSION['business_name'] ?? 'Jewellery ERP');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($businessName) ?> - Pawn Interest</title>

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

        .calculation-box{
            padding:14px;
            border:1px solid var(--line);
            border-radius:10px;
            background:var(--primary-soft);
        }

        .calculation-row{
            display:flex;
            justify-content:space-between;
            gap:12px;
            padding:7px 0;
            border-bottom:1px dashed color-mix(in srgb,var(--primary-dark) 25%,transparent);
            font-size:11px;
        }

        .calculation-row:last-child{
            border-bottom:0;
            font-size:14px;
            font-weight:800;
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
        .overdue{background:#fdecec;color:#bd2d2d}

        .loading{
            padding:50px;
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
                    <div class="page-title">Interest Collection</div>
                    <div class="small text-muted" id="pageSubTitle">
                        <?= $hasSelectedPawn
                            ? 'Loading pawn information...'
                            : 'Select a pawn entry to calculate and collect interest.' ?>
                    </div>
                </div>

                <div class="d-flex gap-2">
                    <a href="pawn-list.php" class="btn-soft">
                        <i class="fa-solid fa-arrow-left me-1"></i> Pawn List
                    </a>

                    <?php if ($hasSelectedPawn): ?>
                        <a href="pawn-view.php?id=<?= $pawnId ?>" class="btn-theme">
                            <i class="fa-regular fa-eye me-1"></i> View Pawn
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>


        <?php if (!$hasSelectedPawn): ?>
            <div class="page-card mb-3">
                <div class="page-head">
                    <div>
                        <div class="section-title">Select Pawn</div>
                        <div class="small text-muted">
                            Choose an active or partially paid pawn to collect interest.
                        </div>
                    </div>
                </div>

                <div class="card-body-x">
                    <div class="row g-2">
                        <div class="col-md-8">
                            <label class="form-label">Pawn Entry</label>
                            <select id="pawnSelector" class="form-select">
                                <option value="">Loading pawn entries...</option>
                            </select>
                        </div>

                        <div class="col-md-4 d-flex align-items-end">
                            <button type="button"
                                    id="openPawnBtn"
                                    class="btn-theme w-100">
                                <i class="fa-solid fa-arrow-right me-1"></i>
                                Open Interest Page
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div id="loadingBox"
             class="page-card loading"
             style="<?= $hasSelectedPawn ? '' : 'display:none' ?>">
            <i class="fa-solid fa-spinner fa-spin me-2"></i>
            Loading pawn interest details...
        </div>

        <div id="pageContent" style="display:none">
            <div class="stat-grid mb-3">
                <div class="stat-card">
                    <div class="stat-label">Principal Balance</div>
                    <div class="stat-value" id="statPrincipal">₹0.00</div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Interest Rate</div>
                    <div class="stat-value" id="statInterest">0%</div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Interest Collected</div>
                    <div class="stat-value" id="statCollected">₹0.00</div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Due Date</div>
                    <div class="stat-value" id="statDueDate">-</div>
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
                            <div class="section-title">Loan Summary</div>
                        </div>
                        <div class="card-body-x" id="loanSummary"></div>
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-xl-8">
                    <div class="page-card">
                        <div class="page-head">
                            <div class="section-title">Collect Interest</div>
                        </div>

                        <div class="card-body-x">
                            <form id="interestForm">
                                <input type="hidden" name="action" value="save">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="pawn_id" value="<?= $pawnId ?>">

                                <div class="row g-2">
                                    <div class="col-md-4">
                                        <label class="form-label">Collection Date</label>
                                        <input type="date"
                                               name="collection_date"
                                               id="collectionDate"
                                               class="form-control"
                                               value="<?= date('Y-m-d') ?>"
                                               required>
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label">Interest From</label>
                                        <input type="date"
                                               name="interest_from"
                                               id="interestFrom"
                                               class="form-control"
                                               required>
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label">Interest To</label>
                                        <input type="date"
                                               name="interest_to"
                                               id="interestTo"
                                               class="form-control"
                                               value="<?= date('Y-m-d') ?>"
                                               required>
                                    </div>

                                    <div class="col-md-3">
                                        <label class="form-label">Days</label>
                                        <input type="number"
                                               name="days_count"
                                               id="daysCount"
                                               class="form-control"
                                               readonly>
                                    </div>

                                    <div class="col-md-3">
                                        <label class="form-label">Interest Amount</label>
                                        <input type="number"
                                               step="0.01"
                                               min="0"
                                               name="interest_amount"
                                               id="interestAmount"
                                               class="form-control"
                                               required>
                                    </div>

                                    <div class="col-md-3">
                                        <label class="form-label">Penalty Amount</label>
                                        <input type="number"
                                               step="0.01"
                                               min="0"
                                               name="penalty_amount"
                                               id="penaltyAmount"
                                               class="form-control"
                                               value="0">
                                    </div>

                                    <div class="col-md-3">
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
                                        <label class="form-label">Total Amount</label>
                                        <input type="number"
                                               step="0.01"
                                               min="0"
                                               name="total_amount"
                                               id="totalAmount"
                                               class="form-control"
                                               readonly>
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
                                        <label class="form-label">Reference Number</label>
                                        <input type="text"
                                               name="reference_no"
                                               class="form-control"
                                               placeholder="UPI / Txn / Cheque">
                                    </div>

                                    <div class="col-12">
                                        <label class="form-label">Remarks</label>
                                        <textarea name="remarks"
                                                  class="form-control"
                                                  rows="3"></textarea>
                                    </div>

                                    <div class="col-12 d-flex justify-content-end gap-2 mt-2">
                                        <button type="button"
                                                id="calculateBtn"
                                                class="btn-soft">
                                            <i class="fa-solid fa-calculator me-1"></i>
                                            Calculate
                                        </button>

                                        <button type="submit"
                                                id="saveBtn"
                                                class="btn-theme">
                                            <i class="fa-solid fa-floppy-disk me-1"></i>
                                            Collect Interest
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
                            <div class="section-title">Calculation Preview</div>
                        </div>

                        <div class="card-body-x">
                            <div class="calculation-box">
                                <div class="calculation-row">
                                    <span>Principal</span>
                                    <strong id="previewPrincipal">₹0.00</strong>
                                </div>

                                <div class="calculation-row">
                                    <span>Rate</span>
                                    <strong id="previewRate">0%</strong>
                                </div>

                                <div class="calculation-row">
                                    <span>Period</span>
                                    <strong id="previewPeriod">-</strong>
                                </div>

                                <div class="calculation-row">
                                    <span>Days</span>
                                    <strong id="previewDays">0</strong>
                                </div>

                                <div class="calculation-row">
                                    <span>Interest</span>
                                    <strong id="previewInterest">₹0.00</strong>
                                </div>

                                <div class="calculation-row">
                                    <span>Penalty</span>
                                    <strong id="previewPenalty">₹0.00</strong>
                                </div>

                                <div class="calculation-row">
                                    <span>Net Collection</span>
                                    <strong id="previewTotal">₹0.00</strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="page-card">
                <div class="page-head">
                    <div class="section-title">Interest Collection History</div>
                    <span class="small text-muted" id="historyCount">0 records</span>
                </div>

                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Receipt</th>
                                <th>Collection Date</th>
                                <th>Interest Period</th>
                                <th>Days</th>
                                <th class="text-end">Interest</th>
                                <th class="text-end">Penalty</th>
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

    const apiUrl = 'api/pawn-interest.php';
    const csrfToken = <?= json_encode($csrfToken) ?>;
    const pawnId = <?= $pawnId ?>;
    const hasSelectedPawn = <?= $hasSelectedPawn ? 'true' : 'false' ?>;

    let pawnData = null;

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
                'Pawn Interest API did not return JSON. HTTP ' +
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

    function updateTotal() {
        const interest = Number(
            document.getElementById('interestAmount').value || 0
        );
        const penalty = Number(
            document.getElementById('penaltyAmount').value || 0
        );
        const discount = Number(
            document.getElementById('discountAmount').value || 0
        );

        const total = Math.max(0, interest + penalty - discount);

        document.getElementById('totalAmount').value = total.toFixed(2);
        document.getElementById('previewInterest').textContent =
            '₹' + money(interest);
        document.getElementById('previewPenalty').textContent =
            '₹' + money(penalty);
        document.getElementById('previewTotal').textContent =
            '₹' + money(total);
    }

    function renderHistory(rows) {
        document.getElementById('historyCount').textContent =
            rows.length + ' records';

        document.getElementById('historyBody').innerHTML = rows.length
            ? rows.map(row => `
                <tr>
                    <td><strong>${esc(row.receipt_no)}</strong></td>
                    <td>${esc(row.collection_date_display)}</td>
                    <td>
                        ${esc(row.interest_from_display || '-')}
                        ${row.interest_to_display
                            ? ' to ' + esc(row.interest_to_display)
                            : ''
                        }
                    </td>
                    <td>${Number(row.days_count || 0)}</td>
                    <td class="text-end">₹${money(row.interest_amount)}</td>
                    <td class="text-end">₹${money(row.penalty_amount)}</td>
                    <td class="text-end">₹${money(row.discount_amount)}</td>
                    <td class="text-end"><strong>₹${money(row.total_amount)}</strong></td>
                    <td>${esc(row.method_name || '-')}</td>
                    <td>${esc(row.reference_no || '-')}</td>
                </tr>
            `).join('')
            : '<tr><td colspan="10" class="text-center text-muted py-4">No interest collections found.</td></tr>';
    }


    async function loadPawnSelector() {
        const selector = document.getElementById('pawnSelector');

        if (!selector) {
            return;
        }

        try {
            const data = await request({
                action: 'list_pawns'
            });

            selector.innerHTML =
                '<option value="">Select Pawn</option>' +
                data.pawns.map(pawn => `
                    <option value="${pawn.id}">
                        ${esc(pawn.pawn_no)} -
                        ${esc(pawn.customer_name || 'Unknown Customer')} -
                        Balance ₹${money(pawn.balance_principal)}
                    </option>
                `).join('');

            if (!data.pawns.length) {
                selector.innerHTML =
                    '<option value="">No active pawn entries found</option>';
            }
        } catch (error) {
            selector.innerHTML =
                '<option value="">Unable to load pawn entries</option>';

            toast('error', error.message);
        }
    }

    function openSelectedPawn() {
        const selector = document.getElementById('pawnSelector');

        if (!selector || !selector.value) {
            toast('error', 'Select a pawn entry.');
            return;
        }

        window.location.href =
            'pawn-interest.php?pawn_id=' +
            encodeURIComponent(selector.value);
    }

    async function loadPage() {
        document.getElementById('loadingBox').style.display = '';
        document.getElementById('pageContent').style.display = 'none';

        try {
            const data = await request({
                action: 'load',
                pawn_id: pawnId
            });

            pawnData = data.pawn;

            document.getElementById('pageSubTitle').textContent =
                pawnData.pawn_no + ' - ' + pawnData.customer_name;

            document.getElementById('statPrincipal').textContent =
                '₹' + money(pawnData.balance_principal);

            document.getElementById('statInterest').textContent =
                Number(pawnData.interest_percent || 0).toFixed(3) +
                '% ' +
                pawnData.interest_period;

            document.getElementById('statCollected').textContent =
                '₹' + money(data.total_interest_collected);

            document.getElementById('statDueDate').textContent =
                pawnData.due_date_display || '-';

            document.getElementById('pawnInformation').innerHTML =
                infoRow('Pawn Number', esc(pawnData.pawn_no)) +
                infoRow('Pawn Date', esc(pawnData.pawn_date_display)) +
                infoRow('Customer', esc(pawnData.customer_name)) +
                infoRow('Mobile', esc(pawnData.mobile || '-')) +
                infoRow('Category', esc(pawnData.category_name || '-')) +
                infoRow(
                    'Status',
                    `<span class="badge-soft ${
                        pawnData.status === 'Active'
                            ? 'status-active'
                            : 'status-partial'
                    }">${esc(pawnData.status)}</span>`
                );

            document.getElementById('loanSummary').innerHTML =
                infoRow('Principal Amount', '₹' + money(pawnData.principal_amount)) +
                infoRow('Principal Balance', '₹' + money(pawnData.balance_principal)) +
                infoRow(
                    'Interest',
                    Number(pawnData.interest_percent || 0).toFixed(3) +
                    '% ' +
                    esc(pawnData.interest_period)
                ) +
                infoRow('Due Date', esc(pawnData.due_date_display || '-')) +
                infoRow(
                    'Due Status',
                    pawnData.is_overdue
                        ? '<span class="badge-soft overdue">Overdue</span>'
                        : 'Not overdue'
                ) +
                infoRow('Interest Collected', '₹' + money(data.total_interest_collected));

            document.getElementById('interestFrom').value =
                data.default_interest_from;

            document.getElementById('interestTo').value =
                data.default_interest_to;

            document.getElementById('paymentMethod').innerHTML =
                '<option value="">Select Method</option>' +
                data.payment_methods.map(method => `
                    <option value="${method.id}">
                        ${esc(method.method_name)}
                    </option>
                `).join('');

            document.getElementById('previewPrincipal').textContent =
                '₹' + money(pawnData.balance_principal);

            document.getElementById('previewRate').textContent =
                Number(pawnData.interest_percent || 0).toFixed(3) +
                '% ' +
                pawnData.interest_period;

            renderHistory(data.history);

            document.getElementById('loadingBox').style.display = 'none';
            document.getElementById('pageContent').style.display = '';
        } catch (error) {
            document.getElementById('loadingBox').innerHTML =
                '<div class="text-danger fw-bold">Unable to load pawn interest</div>' +
                '<div class="small mt-2">' + esc(error.message) + '</div>';

            toast('error', error.message);
        }
    }

    async function calculateInterest() {
        const fromDate = document.getElementById('interestFrom').value;
        const toDate = document.getElementById('interestTo').value;
        const button = document.getElementById('calculateBtn');
        const originalText = button.innerHTML;

        if (!fromDate || !toDate) {
            toast('error', 'Select interest from and to dates.');
            return;
        }

        button.disabled = true;
        button.innerHTML =
            '<i class="fa-solid fa-spinner fa-spin me-1"></i> Calculating';

        try {
            const data = await request({
                action: 'calculate',
                pawn_id: pawnId,
                interest_from: fromDate,
                interest_to: toDate
            });

            document.getElementById('daysCount').value = data.days_count;
            document.getElementById('interestAmount').value =
                Number(data.interest_amount).toFixed(2);
            document.getElementById('penaltyAmount').value =
                Number(data.penalty_amount).toFixed(2);

            document.getElementById('previewPeriod').textContent =
                data.interest_from_display +
                ' to ' +
                data.interest_to_display;

            document.getElementById('previewDays').textContent =
                data.days_count;

            updateTotal();
        } catch (error) {
            toast('error', error.message);
        } finally {
            button.disabled = false;
            button.innerHTML = originalText;
        }
    }

    const calculateButton = document.getElementById('calculateBtn');

    if (calculateButton) {
        calculateButton.addEventListener('click', calculateInterest);
    }

    ['interestAmount', 'penaltyAmount', 'discountAmount'].forEach(id => {
        const element = document.getElementById(id);

        if (element) {
            element.addEventListener('input', updateTotal);
        }
    });

    const interestForm = document.getElementById('interestForm');

    if (interestForm) {
        interestForm.addEventListener(
            'submit',
            async event => {
            event.preventDefault();

            const button = document.getElementById('saveBtn');
            const originalText = button.innerHTML;

            if (Number(document.getElementById('totalAmount').value || 0) <= 0) {
                toast('error', 'Total collection amount must be greater than zero.');
                return;
            }

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
                        'Pawn Interest API did not return JSON. HTTP ' +
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
                        result.message || 'Unable to save interest collection.'
                    );
                }

                toast(
                    'success',
                    result.message + ' Receipt: ' + result.receipt_no
                );

                document.getElementById('interestAmount').value = '0';
                document.getElementById('penaltyAmount').value = '0';
                document.getElementById('discountAmount').value = '0';
                document.getElementById('totalAmount').value = '0';

                await loadPage();
            } catch (error) {
                toast('error', error.message);
            } finally {
                button.disabled = false;
                button.innerHTML = originalText;
            }
            }
        );
    }

    const openPawnButton = document.getElementById('openPawnBtn');

    if (openPawnButton) {
        openPawnButton.addEventListener('click', openSelectedPawn);
    }

    const pawnSelector = document.getElementById('pawnSelector');

    if (pawnSelector) {
        pawnSelector.addEventListener('change', event => {
            if (event.target.value) {
                openSelectedPawn();
            }
        });
    }

    if (hasSelectedPawn) {
        loadPage();
    } else {
        loadPawnSelector();
    }
})();
</script>
</body>
</html>
