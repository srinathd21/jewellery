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

if (empty($_SESSION['pawn_auction_csrf'])) {
    $_SESSION['pawn_auction_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = (string)$_SESSION['pawn_auction_csrf'];

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

$pageTitle = 'Pawn Auction';
$businessName = (string)($_SESSION['business_name'] ?? 'Jewellery ERP');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($businessName) ?> - Pawn Auction</title>

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

        .auction-summary{
            padding:16px;
            border:1px solid color-mix(in srgb,var(--primary) 40%,var(--line));
            border-radius:12px;
            background:var(--primary-soft);
        }

        .summary-row{
            display:flex;
            justify-content:space-between;
            gap:12px;
            padding:8px 0;
            border-bottom:1px dashed color-mix(in srgb,var(--primary-dark) 25%,transparent);
            font-size:11px;
        }

        .summary-row:last-child{
            border-bottom:0;
            font-size:15px;
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

        .status-overdue{background:#fdecec;color:#bd2d2d}
        .status-warning{background:#fff4d8;color:#9a6700}
        .status-active{background:#eaf2ff;color:#2457a7}
        .status-surplus{background:#eaf8f0;color:#168449}
        .status-deficit{background:#fdecec;color:#bd2d2d}

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
                    <div class="page-title">Pawn Auction</div>
                    <div class="small text-muted" id="pageSubtitle">
                        Select an overdue pawn and record auction details.
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
                <div class="section-title">Overdue Pawns</div>
            </div>

            <div class="card-body-x">
                <div class="row g-2">
                    <div class="col-md-8">
                        <label class="form-label">Select Pawn</label>
                        <select id="pawnSelect" class="form-select">
                            <option value="">Loading overdue pawns...</option>
                        </select>
                    </div>

                    <div class="col-md-4 d-flex align-items-end">
                        <button type="button" id="openPawnBtn" class="btn-theme w-100">
                            <i class="fa-solid fa-gavel me-1"></i>
                            Open Auction
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div id="loadingBox" class="page-card loading" style="display:none">
            <i class="fa-solid fa-spinner fa-spin me-2"></i>
            Loading pawn auction details...
        </div>

        <div id="auctionContent" style="display:none">
            <div class="stat-grid mb-3">
                <div class="stat-card">
                    <div class="stat-label">Outstanding Principal</div>
                    <div class="stat-value" id="statOutstanding">₹0.00</div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Days Overdue</div>
                    <div class="stat-value" id="statDays">0</div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Estimated Value</div>
                    <div class="stat-value" id="statEstimated">₹0.00</div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Total Net Weight</div>
                    <div class="stat-value" id="statWeight">0.000 g</div>
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

            <div class="page-card mb-3">
                <div class="page-head">
                    <div class="section-title">Pawned Items</div>
                    <span class="small text-muted" id="itemCount">0 items</span>
                </div>

                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Description</th>
                                <th>Metal</th>
                                <th>Purity</th>
                                <th class="text-end">Quantity</th>
                                <th class="text-end">Gross Weight</th>
                                <th class="text-end">Stone Weight</th>
                                <th class="text-end">Net Weight</th>
                                <th class="text-end">Estimated Value</th>
                            </tr>
                        </thead>
                        <tbody id="itemsBody"></tbody>
                    </table>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-xl-8">
                    <div class="page-card">
                        <div class="page-head">
                            <div class="section-title">Auction Details</div>
                        </div>

                        <div class="card-body-x">
                            <form id="auctionForm">
                                <input type="hidden" name="action" value="save">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="pawn_id" id="pawnIdInput" value="<?= $pawnId ?>">
                                <input type="hidden" name="net_amount" id="netAmount" value="0">
                                <input type="hidden" name="surplus_amount" id="surplusAmount" value="0">
                                <input type="hidden" name="deficit_amount" id="deficitAmount" value="0">

                                <div class="row g-2">
                                    <div class="col-md-4">
                                        <label class="form-label">Auction Date</label>
                                        <input type="date"
                                               name="auction_date"
                                               id="auctionDate"
                                               class="form-control"
                                               value="<?= date('Y-m-d') ?>"
                                               required>
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label">Buyer Name</label>
                                        <input type="text"
                                               name="buyer_name"
                                               class="form-control">
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label">Buyer Contact</label>
                                        <input type="text"
                                               name="buyer_contact"
                                               class="form-control">
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label">Auction Amount</label>
                                        <input type="number"
                                               step="0.01"
                                               min="0.01"
                                               name="auction_amount"
                                               id="auctionAmount"
                                               class="form-control"
                                               required>
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label">Auction Expenses</label>
                                        <input type="number"
                                               step="0.01"
                                               min="0"
                                               name="expenses_amount"
                                               id="expensesAmount"
                                               class="form-control"
                                               value="0">
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label">Outstanding Due</label>
                                        <input type="number"
                                               step="0.01"
                                               id="outstandingDue"
                                               class="form-control"
                                               readonly>
                                    </div>

                                    <div class="col-12">
                                        <label class="form-label">Auction Remarks</label>
                                        <textarea name="auction_remarks"
                                                  class="form-control"
                                                  rows="3"></textarea>
                                    </div>

                                    <div class="col-12 d-flex justify-content-end gap-2 mt-2">
                                        <button type="button"
                                                id="resetAuctionBtn"
                                                class="btn-soft">
                                            Reset Estimated Value
                                        </button>

                                        <button type="submit"
                                                id="auctionBtn"
                                                class="btn-theme">
                                            <i class="fa-solid fa-gavel me-1"></i>
                                            Confirm Auction
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
                            <div class="section-title">Auction Summary</div>
                        </div>

                        <div class="card-body-x">
                            <div class="auction-summary">
                                <div class="summary-row">
                                    <span>Auction Amount</span>
                                    <strong id="sumAuction">₹0.00</strong>
                                </div>

                                <div class="summary-row">
                                    <span>Expenses</span>
                                    <strong id="sumExpenses">₹0.00</strong>
                                </div>

                                <div class="summary-row">
                                    <span>Net Amount</span>
                                    <strong id="sumNet">₹0.00</strong>
                                </div>

                                <div class="summary-row">
                                    <span>Outstanding</span>
                                    <strong id="sumOutstanding">₹0.00</strong>
                                </div>

                                <div class="summary-row">
                                    <span id="resultLabel">Surplus</span>
                                    <strong id="sumResult">₹0.00</strong>
                                </div>
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

    const apiUrl = 'api/pawn-auction.php';
    const csrfToken = <?= json_encode($csrfToken) ?>;
    const initialPawnId = <?= $pawnId ?>;

    let currentPawn = null;
    let estimatedAuctionValue = 0;
    let outstandingBalance = 0;

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

    function weight(value) {
        return Number(value || 0).toLocaleString('en-IN', {
            minimumFractionDigits: 3,
            maximumFractionDigits: 3
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
                'Pawn Auction API did not return JSON. HTTP ' +
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

    function calculateAuction() {
        const auction = Number(
            document.getElementById('auctionAmount').value || 0
        );
        const expenses = Number(
            document.getElementById('expensesAmount').value || 0
        );

        const net = Math.max(0, auction - expenses);
        const difference = net - outstandingBalance;
        const surplus = Math.max(0, difference);
        const deficit = Math.max(0, -difference);

        document.getElementById('netAmount').value = net.toFixed(2);
        document.getElementById('surplusAmount').value = surplus.toFixed(2);
        document.getElementById('deficitAmount').value = deficit.toFixed(2);

        document.getElementById('sumAuction').textContent =
            '₹' + money(auction);
        document.getElementById('sumExpenses').textContent =
            '₹' + money(expenses);
        document.getElementById('sumNet').textContent =
            '₹' + money(net);
        document.getElementById('sumOutstanding').textContent =
            '₹' + money(outstandingBalance);

        const resultLabel = document.getElementById('resultLabel');
        const resultValue = document.getElementById('sumResult');

        if (difference >= 0) {
            resultLabel.textContent = 'Surplus';
            resultValue.textContent = '₹' + money(surplus);
            resultValue.className = 'badge-soft status-surplus';
        } else {
            resultLabel.textContent = 'Deficit';
            resultValue.textContent = '₹' + money(deficit);
            resultValue.className = 'badge-soft status-deficit';
        }
    }

    function renderItems(items) {
        document.getElementById('itemCount').textContent =
            items.length + ' items';

        document.getElementById('itemsBody').innerHTML = items.length
            ? items.map(item => `
                <tr>
                    <td>${esc(item.item_description || '-')}</td>
                    <td>${esc(item.metal_name || '-')}</td>
                    <td>${esc(item.purity || '-')}</td>
                    <td class="text-end">${Number(item.quantity || 0)}</td>
                    <td class="text-end">${weight(item.gross_weight)} g</td>
                    <td class="text-end">${weight(item.stone_weight)} g</td>
                    <td class="text-end"><strong>${weight(item.net_weight)} g</strong></td>
                    <td class="text-end">₹${money(item.estimated_value)}</td>
                </tr>
            `).join('')
            : '<tr><td colspan="8" class="text-center text-muted py-4">No pawn items found.</td></tr>';
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
                '<option value="">Select Overdue Pawn</option>' +
                data.pawns.map(pawn => `
                    <option value="${pawn.id}">
                        ${esc(pawn.pawn_no)} -
                        ${esc(pawn.customer_name || 'Unknown Customer')} -
                        ${pawn.days_overdue} days overdue -
                        ₹${money(pawn.balance_principal)}
                    </option>
                `).join('');

            if (!data.pawns.length) {
                selector.innerHTML =
                    '<option value="">No overdue pawns found</option>';
            }

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
            document.getElementById('auctionContent').style.display = 'none';
            return;
        }

        document.getElementById('loadingBox').style.display = '';
        document.getElementById('auctionContent').style.display = 'none';

        try {
            const data = await request({
                action: 'load',
                pawn_id: pawnId
            });

            currentPawn = data.pawn;
            estimatedAuctionValue = Number(
                data.valuation.estimated_auction_value || 0
            );
            outstandingBalance = Number(
                currentPawn.balance_principal || 0
            );

            document.getElementById('pawnIdInput').value = currentPawn.id;
            document.getElementById('pawnSelect').value = String(currentPawn.id);
            document.getElementById('viewPawnBtn').href =
                'pawn-view.php?id=' + currentPawn.id;
            document.getElementById('viewPawnBtn').style.display = '';

            document.getElementById('pageSubtitle').textContent =
                currentPawn.pawn_no + ' - ' + currentPawn.customer_name;

            document.getElementById('statOutstanding').textContent =
                '₹' + money(outstandingBalance);
            document.getElementById('statDays').textContent =
                currentPawn.days_overdue;
            document.getElementById('statEstimated').textContent =
                '₹' + money(estimatedAuctionValue);
            document.getElementById('statWeight').textContent =
                weight(data.valuation.total_net_weight) + ' g';

            document.getElementById('pawnInformation').innerHTML =
                infoRow('Pawn Number', esc(currentPawn.pawn_no)) +
                infoRow('Pawn Date', esc(currentPawn.pawn_date_display)) +
                infoRow('Due Date', esc(currentPawn.due_date_display)) +
                infoRow(
                    'Overdue',
                    `<span class="badge-soft ${
                        currentPawn.days_overdue >= 30
                            ? 'status-overdue'
                            : 'status-warning'
                    }">${currentPawn.days_overdue} days</span>`
                ) +
                infoRow('Category', esc(currentPawn.category_name || '-')) +
                infoRow('Principal Amount', '₹' + money(currentPawn.principal_amount)) +
                infoRow('Outstanding', '₹' + money(currentPawn.balance_principal));

            document.getElementById('customerInformation').innerHTML =
                infoRow('Customer', esc(currentPawn.customer_name || 'Unknown Customer')) +
                infoRow('Customer Code', esc(currentPawn.customer_code || '-')) +
                infoRow('Mobile', esc(currentPawn.mobile || '-')) +
                infoRow('Email', esc(currentPawn.email || '-')) +
                infoRow(
                    'Address',
                    esc(
                        [
                            currentPawn.address_line1,
                            currentPawn.address_line2,
                            currentPawn.city,
                            currentPawn.state,
                            currentPawn.pincode
                        ].filter(Boolean).join(', ')
                    )
                );

            renderItems(data.items);
            renderHistory(data.history);

            document.getElementById('auctionAmount').value =
                estimatedAuctionValue.toFixed(2);
            document.getElementById('expensesAmount').value = '0';
            document.getElementById('outstandingDue').value =
                outstandingBalance.toFixed(2);

            calculateAuction();

            document.getElementById('loadingBox').style.display = 'none';
            document.getElementById('auctionContent').style.display = '';
        } catch (error) {
            document.getElementById('loadingBox').innerHTML =
                '<div class="text-danger fw-bold">Unable to load pawn auction</div>' +
                '<div class="small mt-2">' + esc(error.message) + '</div>';

            toast('error', error.message);
        }
    }

    function openSelectedPawn() {
        const pawnId = document.getElementById('pawnSelect').value;

        if (!pawnId) {
            toast('error', 'Select an overdue pawn.');
            return;
        }

        window.history.replaceState(
            {},
            '',
            'pawn-auction.php?pawn_id=' + encodeURIComponent(pawnId)
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
        'auctionAmount',
        'expensesAmount'
    ].forEach(id => {
        document.getElementById(id).addEventListener(
            'input',
            calculateAuction
        );
    });

    document.getElementById('resetAuctionBtn').addEventListener(
        'click',
        () => {
            document.getElementById('auctionAmount').value =
                estimatedAuctionValue.toFixed(2);
            document.getElementById('expensesAmount').value = '0';
            calculateAuction();
        }
    );

    document.getElementById('auctionForm').addEventListener(
        'submit',
        async event => {
            event.preventDefault();

            if (!currentPawn) {
                toast('error', 'Select an overdue pawn.');
                return;
            }

            const auctionAmount = Number(
                document.getElementById('auctionAmount').value || 0
            );
            const expenses = Number(
                document.getElementById('expensesAmount').value || 0
            );
            const net = Number(
                document.getElementById('netAmount').value || 0
            );
            const surplus = Number(
                document.getElementById('surplusAmount').value || 0
            );
            const deficit = Number(
                document.getElementById('deficitAmount').value || 0
            );

            if (auctionAmount <= 0) {
                toast('error', 'Auction amount must be greater than zero.');
                return;
            }

            const confirmed = confirm(
                'Confirm pawn auction?\n\n' +
                'Pawn: ' + currentPawn.pawn_no + '\n' +
                'Customer: ' + currentPawn.customer_name + '\n' +
                'Auction Amount: ₹' + money(auctionAmount) + '\n' +
                'Expenses: ₹' + money(expenses) + '\n' +
                'Net Amount: ₹' + money(net) + '\n' +
                (surplus > 0
                    ? 'Surplus: ₹' + money(surplus)
                    : 'Deficit: ₹' + money(deficit)
                ) +
                '\n\nThis will mark the pawn as Auctioned.'
            );

            if (!confirmed) {
                return;
            }

            const button = document.getElementById('auctionBtn');
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
                        'Pawn Auction API did not return JSON. HTTP ' +
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
                        result.message || 'Unable to save pawn auction.'
                    );
                }

                toast(
                    'success',
                    result.message + ' Auction No: ' + result.auction_no
                );

                setTimeout(() => {
                    window.location.href =
                        'pawn-view.php?id=' +
                        encodeURIComponent(currentPawn.id) +
                        '&auctioned=1';
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
