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
$estimateId = (int)($_GET['id'] ?? 0);

if ($businessId <= 0 || $branchId <= 0) {
    die('A valid business and branch must be selected.');
}

if ($estimateId <= 0) {
    die('Invalid estimate ID.');
}

if (empty($_SESSION['estimate_print_csrf'])) {
    $_SESSION['estimate_print_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = (string)$_SESSION['estimate_print_csrf'];

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

$stmt = $conn->prepare('SELECT * FROM business_theme_settings WHERE business_id = ? LIMIT 1');
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

$pageTitle = 'Estimate Print';
$businessName = (string)($_SESSION['business_name'] ?? 'Jewellery ERP');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($businessName) ?> - Estimate Print</title>
    <?php include('includes/links.php'); ?>

    <style>
        :root{
            --primary:<?=e($theme['primary_color'])?>;
            --primary-dark:<?=e($theme['primary_dark_color'])?>;
            --primary-soft:<?=e($theme['primary_soft_color'])?>;
            --page-bg:<?=e($theme['page_background'])?>;
            --card-bg:<?=e($theme['card_background'])?>;
            --text:<?=e($theme['text_color'])?>;
            --muted:<?=e($theme['muted_text_color'])?>;
            --line:<?=e($theme['border_color'])?>;
            --radius:<?=(int)$theme['border_radius_px']?>px;
            --sidebar-width:<?=(int)$theme['sidebar_width_px']?>px;
            --sidebar-gradient-1:<?=e($theme['sidebar_gradient_1'])?>;
            --sidebar-gradient-2:<?=e($theme['sidebar_gradient_2'])?>;
            --sidebar-gradient-3:<?=e($theme['sidebar_gradient_3'])?>;
        }

        body{
            background:var(--page-bg);
            color:var(--text);
            font-family:<?=json_encode($theme['font_family'])?>,sans-serif;
        }

        .sidebar{
            background:linear-gradient(
                180deg,
                var(--sidebar-gradient-1),
                var(--sidebar-gradient-2),
                var(--sidebar-gradient-3)
            )!important;
        }

        .page-card{
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
            font:700 20px <?=json_encode($theme['heading_font_family'])?>,serif;
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
            font-weight:700;
            text-decoration:none;
        }

        .estimate-sheet{
            width:100%;
            max-width:900px;
            margin:0 auto;
            background:#fff;
            color:#171717;
            border:1px solid #dedede;
            border-radius:12px;
            padding:28px;
            box-shadow:0 8px 30px rgba(0,0,0,.05);
        }

        .company-name{
            font:700 24px <?=json_encode($theme['heading_font_family'])?>,serif;
            text-align:center;
        }

        .company-sub{
            text-align:center;
            font-size:11px;
            color:#666;
            margin-top:4px;
        }

        .estimate-label{
            margin:18px 0;
            padding:8px;
            text-align:center;
            font-size:14px;
            font-weight:800;
            letter-spacing:.12em;
            border-top:2px solid #222;
            border-bottom:2px solid #222;
        }

        .cancelled-label{
            color:#b42318;
            border-color:#b42318;
        }

        .info-grid{
            display:grid;
            grid-template-columns:1fr 1fr;
            gap:0;
            border:1px solid #dcdcdc;
            margin-bottom:16px;
        }

        .info-cell{
            padding:9px 11px;
            border-bottom:1px solid #dcdcdc;
            font-size:11px;
        }

        .info-cell:nth-child(odd){
            border-right:1px solid #dcdcdc;
        }

        .info-label{
            color:#777;
            font-size:9px;
            text-transform:uppercase;
            margin-bottom:2px;
        }

        .info-value{
            font-weight:700;
        }

        .print-table{
            width:100%;
            border-collapse:collapse;
            font-size:10px;
        }

        .print-table th,
        .print-table td{
            border:1px solid #dcdcdc;
            padding:7px;
            vertical-align:middle;
        }

        .print-table th{
            background:#f3f3f3;
            text-transform:uppercase;
            font-size:9px;
        }

        .summary-wrap{
            display:grid;
            grid-template-columns:1fr 330px;
            gap:18px;
            margin-top:16px;
        }

        .summary-table{
            width:100%;
            border-collapse:collapse;
            font-size:10px;
        }

        .summary-table td{
            border:1px solid #dcdcdc;
            padding:7px 9px;
        }

        .summary-table td:last-child{
            text-align:right;
            font-weight:700;
        }

        .grand-row td{
            font-size:12px;
            font-weight:800;
            background:#f5f5f5;
        }

        .section-title{
            font-size:10px;
            font-weight:800;
            text-transform:uppercase;
            margin-bottom:7px;
        }

        .payment-row{
            display:flex;
            justify-content:space-between;
            gap:10px;
            border-bottom:1px dashed #ccc;
            padding:7px 0;
            font-size:10px;
        }

        .signature-row{
            display:flex;
            justify-content:space-between;
            margin-top:55px;
            font-size:10px;
        }

        .loading-box{
            padding:60px;
            text-align:center;
            color:var(--muted);
        }

        .error-box{
            padding:25px;
            text-align:center;
            color:#b42318;
        }

        body.dark-mode,body[data-theme=dark]{
            --page-bg:#0f151b;
            --card-bg:#182129;
            --text:#f3f6f8;
            --muted:#9aa7b3;
            --line:#2c3944;
        }

        @media(max-width:767px){
            .summary-wrap{grid-template-columns:1fr}
            .estimate-sheet{padding:14px}
            .info-grid{grid-template-columns:1fr}
            .info-cell:nth-child(odd){border-right:0}
        }

        @media print{
            body{
                background:#fff!important;
            }

            .sidebar,
            .app-main > nav,
            .print-toolbar,
            footer{
                display:none!important;
            }

            .app-main,
            .content-wrap{
                margin:0!important;
                padding:0!important;
                width:100%!important;
            }

            .page-card{
                border:0!important;
            }

            .estimate-sheet{
                max-width:none;
                width:100%;
                margin:0;
                border:0;
                border-radius:0;
                box-shadow:none;
                padding:8mm;
            }

            @page{
                size:A4;
                margin:8mm;
            }
        }
    </style>
</head>
<body>
<?php include('includes/sidebar.php'); ?>

<main class="app-main">
    <?php include('includes/nav.php'); ?>

    <div class="content-wrap">
        <div class="page-card mb-3 print-toolbar">
            <div class="page-head">
                <div>
                    <div class="page-title">Estimate Print</div>
                    <div class="small text-muted">Preview and print estimate.</div>
                </div>

                <div class="d-flex gap-2">
                    <a href="estimates.php" class="btn-soft">
                        <i class="fa-solid fa-arrow-left me-2"></i>Back
                    </a>

                    <button type="button" class="btn-theme" onclick="window.print()">
                        <i class="fa-solid fa-print me-2"></i>Print
                    </button>
                </div>
            </div>
        </div>

        <div class="page-card p-3">
            <div id="loadingBox" class="loading-box">
                <i class="fa-solid fa-spinner fa-spin me-2"></i>Loading estimate...
            </div>

            <div id="errorBox" class="error-box" style="display:none"></div>
            <div id="estimateSheet" class="estimate-sheet" style="display:none"></div>
        </div>

        <?php include('includes/footer.php'); ?>
    </div>
</main>

<?php include('includes/script.php'); ?>
<script src="assets/js/script.js"></script>

<script>
(() => {
    'use strict';

    const apiUrl = 'api/estimate-print.php';
    const estimateId = <?= (int)$estimateId ?>;
    const csrfToken = <?= json_encode($csrfToken) ?>;

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
        return Number(value || 0).toLocaleString('en-IN', {
            minimumFractionDigits:2,
            maximumFractionDigits:2
        });
    }

    async function loadEstimate() {
        const form = new FormData();
        form.append('action', 'load');
        form.append('estimate_id', estimateId);
        form.append('csrf_token', csrfToken);

        try {
            const response = await fetch(apiUrl, {
                method:'POST',
                body:form,
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
                const clean = raw.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();

                throw new Error(
                    'Estimate Print API did not return JSON. HTTP ' +
                    response.status +
                    (clean ? ': ' + clean.substring(0, 300) : '')
                );
            }

            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Unable to load estimate.');
            }

            renderEstimate(result);
        } catch (error) {
            document.getElementById('loadingBox').style.display = 'none';
            document.getElementById('errorBox').style.display = '';
            document.getElementById('errorBox').textContent = error.message;
        }
    }

    function renderEstimate(result) {
        const estimate = result.estimate;
        const company = result.company;
        const items = result.items;
        const payments = result.payments;

        const companyAddress = [
            company.address_line1,
            company.address_line2,
            company.city,
            company.state,
            company.pincode
        ].filter(Boolean).join(', ');

        const companyContact = [
            company.mobile ? 'Mobile: ' + company.mobile : '',
            company.email ? 'Email: ' + company.email : ''
        ].filter(Boolean).join(' | ');

        const taxLine = [
            company.gstin ? 'GSTIN: ' + company.gstin : '',
            company.pan_no ? 'PAN: ' + company.pan_no : ''
        ].filter(Boolean).join(' | ');

        const cancelled = estimate.workflow_status === 'Cancelled';

        document.getElementById('estimateSheet').innerHTML = `
            <div class="company-name">${esc(company.company_name || 'Company Name')}</div>
            ${companyAddress ? `<div class="company-sub">${esc(companyAddress)}</div>` : ''}
            ${companyContact ? `<div class="company-sub">${esc(companyContact)}</div>` : ''}
            ${taxLine ? `<div class="company-sub">${esc(taxLine)}</div>` : ''}

            <div class="estimate-label ${cancelled ? 'cancelled-label' : ''}">
                ${cancelled ? 'ESTIMATE - CANCELLED' : 'ESTIMATE'}
            </div>

            <div class="info-grid">
                <div class="info-cell">
                    <div class="info-label">Estimate Number</div>
                    <div class="info-value">${esc(estimate.invoice_no)}</div>
                </div>

                <div class="info-cell">
                    <div class="info-label">Date and Time</div>
                    <div class="info-value">${esc(estimate.invoice_date_display)} ${esc(estimate.invoice_time_display)}</div>
                </div>

                <div class="info-cell">
                    <div class="info-label">Customer</div>
                    <div class="info-value">${esc(estimate.customer_name || 'Walk-in Customer')}</div>
                </div>

                <div class="info-cell">
                    <div class="info-label">Mobile</div>
                    <div class="info-value">${esc(estimate.customer_mobile || '-')}</div>
                </div>

                <div class="info-cell">
                    <div class="info-label">Payment Status</div>
                    <div class="info-value">${esc(estimate.payment_status)}</div>
                </div>

                <div class="info-cell">
                    <div class="info-label">Workflow Status</div>
                    <div class="info-value">${esc(estimate.workflow_status)}</div>
                </div>
            </div>

            <table class="print-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Item</th>
                        <th>HSN</th>
                        <th>Qty</th>
                        <th>Net Wt</th>
                        <th>Rate</th>
                        <th>Taxable</th>
                        <th>Tax</th>
                        <th>Total</th>
                    </tr>
                </thead>

                <tbody>
                    ${items.map((item, index) => `
                        <tr>
                            <td>${index + 1}</td>
                            <td>
                                <strong>${esc(item.item_name)}</strong>
                                ${item.product_code ? `<div>${esc(item.product_code)}</div>` : ''}
                            </td>
                            <td>${esc(item.hsn_code || '')}</td>
                            <td style="text-align:right">${Number(item.quantity).toFixed(3)}</td>
                            <td style="text-align:right">${Number(item.net_weight).toFixed(3)}</td>
                            <td style="text-align:right">₹${money(item.metal_rate)}</td>
                            <td style="text-align:right">₹${money(item.taxable_amount)}</td>
                            <td style="text-align:right">₹${money(item.tax_amount)}</td>
                            <td style="text-align:right"><strong>₹${money(item.line_total)}</strong></td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>

            <div class="summary-wrap">
                <div>
                    <div class="section-title">Payment Details</div>

                    ${payments.length
                        ? payments.map(payment => `
                            <div class="payment-row">
                                <span>
                                    ${esc(payment.method_name)}
                                    ${payment.reference_no ? ' - ' + esc(payment.reference_no) : ''}
                                </span>
                                <strong>₹${money(payment.amount)}</strong>
                            </div>
                        `).join('')
                        : '<div class="small text-muted">No payment details.</div>'
                    }

                    ${estimate.notes
                        ? `
                            <div class="section-title mt-3">Notes</div>
                            <div style="font-size:10px">${esc(estimate.notes)}</div>
                        `
                        : ''
                    }

                    ${company.terms_conditions
                        ? `
                            <div class="section-title mt-3">Terms & Conditions</div>
                            <div style="font-size:9px;white-space:pre-line">${esc(company.terms_conditions)}</div>
                        `
                        : ''
                    }
                </div>

                <table class="summary-table">
                    <tr><td>Subtotal</td><td>₹${money(estimate.subtotal)}</td></tr>
                    <tr><td>Discount</td><td>₹${money(estimate.discount_amount)}</td></tr>
                    <tr><td>Taxable Amount</td><td>₹${money(estimate.taxable_amount)}</td></tr>
                    <tr><td>CGST</td><td>₹${money(estimate.cgst_amount)}</td></tr>
                    <tr><td>SGST</td><td>₹${money(estimate.sgst_amount)}</td></tr>
                    <tr><td>IGST</td><td>₹${money(estimate.igst_amount)}</td></tr>
                    <tr><td>Round Off</td><td>₹${money(estimate.round_off)}</td></tr>
                    <tr class="grand-row"><td>Grand Total</td><td>₹${money(estimate.grand_total)}</td></tr>
                    <tr><td>Paid Amount</td><td>₹${money(estimate.paid_amount)}</td></tr>
                    <tr><td>Balance Amount</td><td>₹${money(estimate.balance_amount)}</td></tr>
                </table>
            </div>

            <div class="signature-row">
                <div>Customer Signature</div>
                <div>Authorised Signature</div>
            </div>

            ${company.bill_footer
                ? `<div class="company-sub" style="margin-top:24px">${esc(company.bill_footer)}</div>`
                : ''
            }
        `;

        document.getElementById('loadingBox').style.display = 'none';
        document.getElementById('estimateSheet').style.display = '';
    }

    loadEstimate();
})();
</script>
</body>
</html>
