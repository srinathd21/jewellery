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

if (empty($_SESSION['pawn_release_csrf'])) {
    $_SESSION['pawn_release_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = (string)$_SESSION['pawn_release_csrf'];

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

$pageTitle = 'Pawn Release';
$businessName = (string)($_SESSION['business_name'] ?? 'Jewellery ERP');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($businessName) ?> - Pawn Release</title>

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
            width:150px;
            flex:0 0 150px;
            color:var(--muted);
            font-size:10px;
        }

        .info-value{
            flex:1;
            font-size:11px;
            font-weight:700;
        }

        .release-total-box{
            padding:18px;
            border:1px solid color-mix(in srgb,var(--primary) 35%,var(--line));
            border-radius:12px;
            background:var(--primary-soft);
            text-align:center;
        }

        .release-total-label{
            font-size:10px;
            color:var(--muted);
            text-transform:uppercase;
        }

        .release-total-value{
            margin-top:4px;
            font-size:32px;
            font-weight:900;
            color:var(--primary-dark);
        }

        .amount-row{
            display:flex;
            justify-content:space-between;
            gap:12px;
            padding:8px 0;
            border-bottom:1px dashed var(--line);
            font-size:11px;
        }

        .amount-row:last-child{
            border-bottom:0;
            font-size:14px;
            font-weight:800;
        }

        .waive-box{
            display:flex;
            align-items:center;
            gap:8px;
            min-height:39px;
            padding:8px 10px;
            border:1px solid var(--line);
            border-radius:9px;
            background:color-mix(in srgb,var(--muted) 4%,transparent);
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
        .status-overdue{background:#fdecec;color:#bd2d2d}

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
                    <div class="page-title">Pawn Release</div>
                    <div class="small text-muted" id="pageSubtitle">
                        Select a pawn and complete full settlement.
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
                <div class="section-title">Select Pawn for Release</div>
            </div>

            <div class="card-body-x">
                <div class="row g-2">
                    <div class="col-md-8">
                        <label class="form-label">Pawn Entry</label>
                        <select id="pawnSelect" class="form-select">
                            <option value="">Loading pawn entries...</option>
                        </select>
                    </div>

                    <div class="col-md-4 d-flex align-items-end">
                        <button type="button" id="openPawnBtn" class="btn-theme w-100">
                            <i class="fa-solid fa-arrow-right me-1"></i>
                            Open Release
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div id="loadingBox" class="page-card loading" style="display:none">
            <i class="fa-solid fa-spinner fa-spin me-2"></i>
            Loading pawn release details...
        </div>

        <div id="releaseContent" style="display:none">
            <div class="stat-grid mb-3">
                <div class="stat-card">
                    <div class="stat-label">Principal Balance</div>
                    <div class="stat-value" id="statPrincipal">₹0.00</div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Pending Interest</div>
                    <div class="stat-value" id="statInterest">₹0.00</div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Penalty</div>
                    <div class="stat-value" id="statPenalty">₹0.00</div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Release Total</div>
                    <div class="stat-value" id="statTotal">₹0.00</div>
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
                            <div class="section-title">Release Confirmation</div>
                        </div>

                        <div class="card-body-x">
                            <form id="releaseForm">
                                <input type="hidden" name="action" value="save">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="pawn_id" id="pawnIdInput" value="<?= $pawnId ?>">
                                <input type="hidden" name="total_amount" id="totalAmount" value="0">

                                <div class="row g-2">
                                    <div class="col-md-4">
                                        <label class="form-label">Release Date</label>
                                        <input type="date"
                                               name="release_date"
                                               id="releaseDate"
                                               class="form-control"
                                               value="<?= date('Y-m-d') ?>"
                                               required>
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
                                               placeholder="Cheque / UTR / Transaction">
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label">Principal Amount</label>
                                        <input type="number"
                                               step="0.01"
                                               name="principal_amount"
                                               id="principalAmount"
                                               class="form-control"
                                               readonly>
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label">Interest Amount</label>
                                        <input type="number"
                                               step="0.01"
                                               min="0"
                                               name="interest_amount"
                                               id="interestAmount"
                                               class="form-control">
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label">Penalty Amount</label>
                                        <input type="number"
                                               step="0.01"
                                               min="0"
                                               name="penalty_amount"
                                               id="penaltyAmount"
                                               class="form-control">
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
                                        <label class="form-label">Interest Waiver</label>
                                        <label class="waive-box">
                                            <input type="checkbox"
                                                   name="waive_interest"
                                                   id="waiveInterest"
                                                   value="1">
                                            Waive full pending interest
                                        </label>
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label">Penalty Waiver</label>
                                        <label class="waive-box">
                                            <input type="checkbox"
                                                   name="waive_penalty"
                                                   id="waivePenalty"
                                                   value="1">
                                            Waive full penalty
                                        </label>
                                    </div>

                                    <div class="col-12">
                                        <label class="form-label">Release Remarks</label>
                                        <textarea name="release_remarks"
                                                  class="form-control"
                                                  rows="3"
                                                  placeholder="Notes about pawn release"></textarea>
                                    </div>

                                    <div class="col-12 d-flex justify-content-end gap-2 mt-2">
                                        <button type="button"
                                                id="resetCalculatedBtn"
                                                class="btn-soft">
                                            Reset Calculated
                                        </button>

                                        <button type="submit"
                                                id="releaseBtn"
                                                class="btn-theme">
                                            <i class="fa-solid fa-lock-open me-1"></i>
                                            Confirm Release
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
                            <div class="section-title">Release Summary</div>
                        </div>

                        <div class="card-body-x">
                            <div class="release-total-box mb-3">
                                <div class="release-total-label">Final Release Amount</div>
                                <div class="release-total-value" id="releaseTotalDisplay">
                                    ₹0.00
                                </div>
                            </div>

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
                                <span>Discount</span>
                                <strong id="sumDiscount">-₹0.00</strong>
                            </div>

                            <div class="amount-row">
                                <span>Total</span>
                                <strong id="sumTotal">₹0.00</strong>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="page-card">
                <div class="page-head">
                    <div class="section-title">Payment and Interest History</div>
                    <span class="small text-muted" id="historyCount">0 records</span>
                </div>

                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Receipt</th>
                                <th class="text-end">Principal</th>
                                <th class="text-end">Interest</th>
                                <th class="text-end">Penalty</th>
                                <th class="text-end">Total</th>
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

    const apiUrl = 'api/pawn-release.php';
    const csrfToken = <?= json_encode($csrfToken) ?>;
    const initialPawnId = <?= $pawnId ?>;

    let currentPawn = null;
    let originalInterest = 0;
    let originalPenalty = 0;
    let originalPrincipal = 0;

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
                'Pawn Release API did not return JSON. HTTP ' +
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
        const discount = Number(
            document.getElementById('discountAmount').value || 0
        );

        const total = Math.max(
            0,
            principal + interest + penalty - discount
        );

        document.getElementById('sumPrincipal').textContent =
            '₹' + money(principal);
        document.getElementById('sumInterest').textContent =
            '₹' + money(interest);
        document.getElementById('sumPenalty').textContent =
            '₹' + money(penalty);
        document.getElementById('sumDiscount').textContent =
            '-₹' + money(discount);
        document.getElementById('sumTotal').textContent =
            '₹' + money(total);
        document.getElementById('releaseTotalDisplay').textContent =
            '₹' + money(total);
        document.getElementById('totalAmount').value =
            total.toFixed(2);
    }

    function renderHistory(rows) {
        document.getElementById('historyCount').textContent =
            rows.length + ' records';

        document.getElementById('historyBody').innerHTML = rows.length
            ? rows.map(row => `
                <tr>
                    <td>${esc(row.date_display)}</td>
                    <td>${esc(row.record_type)}</td>
                    <td><strong>${esc(row.receipt_no)}</strong></td>
                    <td class="text-end">₹${money(row.principal_amount)}</td>
                    <td class="text-end">₹${money(row.interest_amount)}</td>
                    <td class="text-end">₹${money(row.penalty_amount)}</td>
                    <td class="text-end"><strong>₹${money(row.total_amount)}</strong></td>
                </tr>
            `).join('')
            : '<tr><td colspan="7" class="text-center text-muted py-4">No payment or interest history found.</td></tr>';
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
            document.getElementById('releaseContent').style.display = 'none';
            return;
        }

        document.getElementById('loadingBox').style.display = '';
        document.getElementById('releaseContent').style.display = 'none';

        try {
            const data = await request({
                action: 'load',
                pawn_id: pawnId,
                release_date: document.getElementById('releaseDate')?.value || ''
            });

            currentPawn = data.pawn;
            originalPrincipal = Number(data.release.principal_amount || 0);
            originalInterest = Number(data.release.interest_amount || 0);
            originalPenalty = Number(data.release.penalty_amount || 0);

            document.getElementById('pawnIdInput').value = currentPawn.id;
            document.getElementById('pawnSelect').value = String(currentPawn.id);
            document.getElementById('viewPawnBtn').href =
                'pawn-view.php?id=' + currentPawn.id;
            document.getElementById('viewPawnBtn').style.display = '';

            document.getElementById('pageSubtitle').textContent =
                currentPawn.pawn_no + ' - ' + currentPawn.customer_name;

            document.getElementById('statPrincipal').textContent =
                '₹' + money(originalPrincipal);
            document.getElementById('statInterest').textContent =
                '₹' + money(originalInterest);
            document.getElementById('statPenalty').textContent =
                '₹' + money(originalPenalty);
            document.getElementById('statTotal').textContent =
                '₹' + money(data.release.total_amount);

            let statusClass = currentPawn.status === 'Partially Paid'
                ? 'status-partial'
                : 'status-active';

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
                infoRow('Due Date', esc(currentPawn.due_date_display || '-')) +
                infoRow(
                    'Status',
                    `<span class="badge-soft ${statusClass}">${esc(currentPawn.status)}</span>`
                ) +
                infoRow(
                    'Due Status',
                    currentPawn.is_overdue
                        ? '<span class="badge-soft status-overdue">Overdue</span>'
                        : 'Not overdue'
                );

            document.getElementById('customerInformation').innerHTML =
                infoRow('Customer', esc(currentPawn.customer_name || 'Unknown Customer')) +
                infoRow('Mobile', esc(currentPawn.mobile || '-')) +
                infoRow('Customer Code', esc(currentPawn.customer_code || '-')) +
                infoRow('Principal Amount', '₹' + money(currentPawn.principal_amount)) +
                infoRow('Principal Paid', '₹' + money(currentPawn.total_principal_paid || 0)) +
                infoRow('Outstanding', '₹' + money(currentPawn.balance_principal));

            document.getElementById('principalAmount').value =
                originalPrincipal.toFixed(2);
            document.getElementById('interestAmount').value =
                originalInterest.toFixed(2);
            document.getElementById('penaltyAmount').value =
                originalPenalty.toFixed(2);
            document.getElementById('discountAmount').value = '0';
            document.getElementById('waiveInterest').checked = false;
            document.getElementById('waivePenalty').checked = false;
            document.getElementById('interestAmount').readOnly = false;
            document.getElementById('penaltyAmount').readOnly = false;

            document.getElementById('paymentMethod').innerHTML =
                '<option value="">Select Method</option>' +
                data.payment_methods.map(method => `
                    <option value="${method.id}">
                        ${esc(method.method_name)}
                    </option>
                `).join('');

            renderHistory(data.history);
            calculateTotal();

            document.getElementById('loadingBox').style.display = 'none';
            document.getElementById('releaseContent').style.display = '';
        } catch (error) {
            document.getElementById('loadingBox').innerHTML =
                '<div class="text-danger fw-bold">Unable to load pawn release</div>' +
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
            'pawn-release.php?pawn_id=' + encodeURIComponent(pawnId)
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
        'interestAmount',
        'penaltyAmount',
        'discountAmount'
    ].forEach(id => {
        document.getElementById(id).addEventListener(
            'input',
            calculateTotal
        );
    });

    document.getElementById('releaseDate').addEventListener(
        'change',
        () => {
            if (currentPawn) {
                loadPawn(currentPawn.id);
            }
        }
    );

    document.getElementById('waiveInterest').addEventListener(
        'change',
        event => {
            const field = document.getElementById('interestAmount');

            if (event.target.checked) {
                field.value = '0.00';
                field.readOnly = true;
            } else {
                field.value = originalInterest.toFixed(2);
                field.readOnly = false;
            }

            calculateTotal();
        }
    );

    document.getElementById('waivePenalty').addEventListener(
        'change',
        event => {
            const field = document.getElementById('penaltyAmount');

            if (event.target.checked) {
                field.value = '0.00';
                field.readOnly = true;
            } else {
                field.value = originalPenalty.toFixed(2);
                field.readOnly = false;
            }

            calculateTotal();
        }
    );

    document.getElementById('resetCalculatedBtn').addEventListener(
        'click',
        () => {
            document.getElementById('interestAmount').value =
                originalInterest.toFixed(2);
            document.getElementById('penaltyAmount').value =
                originalPenalty.toFixed(2);
            document.getElementById('discountAmount').value = '0';
            document.getElementById('waiveInterest').checked = false;
            document.getElementById('waivePenalty').checked = false;
            document.getElementById('interestAmount').readOnly = false;
            document.getElementById('penaltyAmount').readOnly = false;
            calculateTotal();
        }
    );

    document.getElementById('releaseForm').addEventListener(
        'submit',
        async event => {
            event.preventDefault();

            if (!currentPawn) {
                toast('error', 'Select a pawn entry.');
                return;
            }

            const principal = Number(
                document.getElementById('principalAmount').value || 0
            );
            const total = Number(
                document.getElementById('totalAmount').value || 0
            );

            if (Math.abs(principal - originalPrincipal) > 0.01) {
                toast(
                    'error',
                    'Principal amount must equal the full outstanding balance.'
                );
                return;
            }

            if (total <= 0) {
                toast('error', 'Release amount must be greater than zero.');
                return;
            }

            const confirmed = confirm(
                'Confirm pawn release?\n\n' +
                'Pawn: ' + currentPawn.pawn_no + '\n' +
                'Customer: ' + currentPawn.customer_name + '\n' +
                'Release Amount: ₹' + money(total) + '\n\n' +
                'This will close the pawn loan.'
            );

            if (!confirmed) {
                return;
            }

            const button = document.getElementById('releaseBtn');
            const oldText = button.innerHTML;

            button.disabled = true;
            button.innerHTML =
                '<i class="fa-solid fa-spinner fa-spin me-1"></i> Releasing';

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
                        'Pawn Release API did not return JSON. HTTP ' +
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
                        result.message || 'Unable to release pawn.'
                    );
                }

                toast(
                    'success',
                    result.message + ' Receipt: ' + result.receipt_no
                );

                setTimeout(() => {
                    window.location.href =
                        'pawn-view.php?id=' +
                        encodeURIComponent(currentPawn.id) +
                        '&released=1';
                }, 700);
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
