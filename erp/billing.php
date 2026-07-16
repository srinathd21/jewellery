<?php
if (session_status() === PHP_SESSION_NONE)
    session_start();
date_default_timezone_set((string) ($_SESSION['timezone'] ?? 'Asia/Kolkata'));
foreach ([__DIR__ . '/config/config.php', __DIR__ . '/config.php', __DIR__ . '/includes/config.php', __DIR__ . '/super-admin/includes/config.php'] as $f) {
    if (is_file($f)) {
        require_once $f;
        break;
    }
}
if (!isset($conn) || !($conn instanceof mysqli))
    die('Database configuration is not available.');
$conn->set_charset('utf8mb4');
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
function e($v): string
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
}
function billingPermission(mysqli $conn, string $action): bool
{
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin')
        return true;
    $map = ['open' => 'can_open', 'create' => 'can_create', 'value' => 'can_view_value'];
    $field = $map[$action] ?? '';
    if ($field === '')
        return false;
    foreach (['perm.billing.create', 'perm.billing'] as $code) {
        if (isset($_SESSION['permissions'][$code][$field]))
            return (int) $_SESSION['permissions'][$code][$field] === 1;
    }
    $businessId = (int) ($_SESSION['business_id'] ?? 0);
    $roleId = (int) ($_SESSION['role_id'] ?? 0);
    if ($businessId <= 0 || $roleId <= 0)
        return false;
    $sql = "SELECT rp.`{$field}` FROM role_permissions rp INNER JOIN permissions p ON p.id=rp.permission_id WHERE rp.business_id=? AND rp.role_id=? AND p.is_active=1 AND p.permission_code IN ('perm.billing.create','perm.billing') ORDER BY FIELD(p.permission_code,'perm.billing.create','perm.billing') LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt)
        return false;
    $stmt->bind_param('ii', $businessId, $roleId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row[$field] ?? 0) === 1;
}
if (!billingPermission($conn, 'open') && !billingPermission($conn, 'create')) {
    http_response_code(403);
    die('You do not have permission to create bills.');
}
$businessId = (int) ($_SESSION['business_id'] ?? 0);
$branchId = (int) ($_SESSION['branch_id'] ?? ($_SESSION['default_branch_id'] ?? 0));
if ($businessId <= 0 || $branchId <= 0)
    die('A valid business and branch must be selected.');
if (empty($_SESSION['billing_csrf']))
    $_SESSION['billing_csrf'] = bin2hex(random_bytes(32));
$csrfToken = (string) $_SESSION['billing_csrf'];
$theme = ['primary_color' => '#d89416', 'primary_dark_color' => '#b86a0b', 'primary_soft_color' => '#fff6e5', 'page_background' => '#f4f3f0', 'card_background' => '#fff', 'text_color' => '#171717', 'muted_text_color' => '#7d8794', 'border_color' => '#e8e8e8', 'font_family' => 'Inter', 'heading_font_family' => 'Playfair Display', 'border_radius_px' => 12];
$stmt = $conn->prepare('SELECT * FROM business_theme_settings WHERE business_id=? LIMIT 1');
if ($stmt) {
    $stmt->bind_param('i', $businessId);
    $stmt->execute();
    $x = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    foreach ($theme as $k => $v)
        if (isset($x[$k]) && $x[$k] !== '')
            $theme[$k] = $x[$k];
}
$customers = [];
$stmt = $conn->prepare("SELECT id,customer_code,customer_name,mobile,gstin FROM customers WHERE business_id=? AND is_active=1 ORDER BY customer_name");
if ($stmt) {
    $stmt->bind_param('i', $businessId);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($x = $r->fetch_assoc())
        $customers[] = $x;
    $stmt->close();
}
$products = [];
$stmt = $conn->prepare("SELECT p.id,p.product_code,p.barcode,p.product_name,p.hsn_code,p.metal_id,p.purity,p.gross_weight,p.stone_weight,p.net_weight,p.wastage_percent,p.making_charge_type,p.making_charge,p.purchase_rate,p.sale_rate,p.tax_percent,p.track_stock,COALESCE(ps.quantity,0) stock_qty,COALESCE(mr.rate_per_gram,p.sale_rate,0) AS live_metal_rate,mr.effective_from AS live_rate_effective_from,mr.branch_id AS live_rate_branch_id FROM products p LEFT JOIN product_stock ps ON ps.product_id=p.id AND ps.business_id=p.business_id AND ps.branch_id=? LEFT JOIN metal_rates mr ON mr.id=(SELECT mr2.id FROM metal_rates mr2 WHERE mr2.business_id=p.business_id AND mr2.metal_id=p.metal_id AND mr2.is_current=1 AND (mr2.branch_id=? OR mr2.branch_id IS NULL) ORDER BY (mr2.branch_id=?) DESC,mr2.effective_from DESC,mr2.id DESC LIMIT 1) WHERE p.business_id=? AND p.is_active=1 ORDER BY p.product_name");
if ($stmt) {
    $stmt->bind_param('iiii', $branchId, $branchId, $branchId, $businessId);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($x = $r->fetch_assoc())
        $products[] = $x;
    $stmt->close();
}
$paymentMethods = [];
$stmt = $conn->prepare('SELECT id,method_name FROM payment_methods WHERE business_id=? AND is_active=1 ORDER BY method_name');
if ($stmt) {
    $stmt->bind_param('i', $businessId);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($x = $r->fetch_assoc())
        $paymentMethods[] = $x;
    $stmt->close();
}
$invoiceSetting = null;
$stmt = $conn->prepare("SELECT * FROM invoice_settings WHERE business_id=? AND (branch_id=? OR branch_id IS NULL) AND document_type='Invoice' AND is_active=1 ORDER BY (branch_id=? ) DESC,is_default DESC,id DESC LIMIT 1");
if ($stmt) {
    $stmt->bind_param('iii', $businessId, $branchId, $branchId);
    $stmt->execute();
    $invoiceSetting = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
function previewInvoiceNo(?array $s): string
{
    if (!$s)
        return 'INV' . date('ymd') . '0001';
    $prefix = $s['prefix'] ?? 'INV';
    $split = $s['splitter_symbol'] ?? '/';
    $digits = max(1, (int) ($s['sequence_digits'] ?? 3));
    $start = max(1, (int) ($s['sequence_start'] ?? 1));
    $fy = (int) date('n') >= 4 ? date('y') . '-' . date('y', strtotime('+1 year')) : date('y', strtotime('-1 year')) . '-' . date('y');
    $map = ['{PREFIX}' => $prefix, '{SPLITTER}' => $split, '{FY_SHORT}' => $fy, '{FY_2DIGIT}' => str_replace('-', '', $fy), '{YYYY}' => date('Y'), '{MM}' => date('m'), '{DD}' => date('d'), '{SEQ}' => str_pad((string) $start, $digits, '0', STR_PAD_LEFT)];
    return strtr($s['format_template'] ?? '{PREFIX}{SPLITTER}{FY_SHORT}{SPLITTER}{SEQ}', $map);
}
$pageTitle = 'Billing';
$businessName = (string) ($_SESSION['business_name'] ?? 'Jewellery ERP');
$defaultBillNo = previewInvoiceNo($invoiceSetting);
// Actual invoice number is generated and sequence-locked by api/billing-save1.php.
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($businessName) ?> - Billing</title><?php include('includes/links.php'); ?>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <style>
        :root {
            --primary:
                <?= e($theme['primary_color']) ?>
            ;
            --primary-dark:
                <?= e($theme['primary_dark_color']) ?>
            ;
            --primary-soft:
                <?= e($theme['primary_soft_color']) ?>
            ;
            --page-bg:
                <?= e($theme['page_background']) ?>
            ;
            --card-bg:
                <?= e($theme['card_background']) ?>
            ;
            --text:
                <?= e($theme['text_color']) ?>
            ;
            --muted:
                <?= e($theme['muted_text_color']) ?>
            ;
            --line:
                <?= e($theme['border_color']) ?>
            ;
            --radius:
                <?= (int) $theme['border_radius_px'] ?>px
        }

        html,body{margin:0!important;padding:0!important;min-height:100%;}
        body {
            background: var(--page-bg);
            color: var(--text);
            font-family:
                <?= json_encode($theme['font_family']) ?>
                , sans-serif
        }

        .standalone-wrap{width:100%;max-width:none;margin:0;padding:8px}.bill-card{box-shadow:0 2px 8px rgba(0,0,0,.025)}

        .bill-card {
            background: var(--card-bg);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            overflow: hidden;
            margin-bottom: 8px
        }

        .bill-head {
            padding: 9px 12px;
            border-bottom: 1px solid var(--line);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px
        }

        .bill-title {
            font: 700 20px
                <?= json_encode($theme['heading_font_family']) ?>
                , serif
        }

        .section-title {
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: var(--primary-dark)
        }

        .bill-body {
            padding: 10px
        }

        .field-label {
            display: block;
            font-size: 10px;
            font-weight: 700;
            margin-bottom: 3px
        }

        .form-control,
        .form-select {
            font-size: 11px;
            min-height: 34px;
            border-color: var(--line);
            border-radius: 7px;
            background: var(--card-bg);
            color: var(--text)
        }

        .btn-theme {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border: 0;
            color: #fff;
            font-size: 11px;
            font-weight: 700;
            border-radius: 7px;
            padding: 7px 10px
        }

        .btn-soft {
            background: var(--primary-soft);
            color: var(--primary-dark);
            border: 1px solid color-mix(in srgb, var(--primary) 30%, var(--line));
            font-size: 11px;
            font-weight: 700;
            border-radius: 7px;
            padding: 7px 10px
        }

        .table {
            font-size: 10px
        }

        .table th {
            font-size: 9px;
            text-transform: uppercase;
            color: var(--muted);
            background: color-mix(in srgb, var(--muted) 6%, transparent);
            white-space: nowrap
        }

        .table td,
        .table th {
            border-color: var(--line);
            vertical-align: middle
        }

        .new-customer-panel {
            display: none;
            margin-top: 12px;
            padding: 13px;
            border: 1px dashed var(--primary);
            border-radius: 10px;
            background: var(--primary-soft)
        }

        .new-customer-panel.show {
            display: block
        }

        .summary-card {
            position: sticky;
            top: 12px
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            border-bottom: 1px dashed var(--line);
            font-size: 10px
        }

        .summary-total {
            font-size: 15px;
            font-weight: 800;
            color: var(--primary-dark)
        }

        .scan-wrap {
            display: grid;
            grid-template-columns: minmax(250px, 1fr) auto;
            gap: 8px;
            align-items: end
        }

        .scan-input {
            font-size: 14px;
            font-weight: 700;
            letter-spacing: .05em
        }

        .chit-panel {
            display: none;
            margin-top: 12px;
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 12px
        }

        .chit-panel.show {
            display: block
        }

        .chit-chip {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 9px 10px;
            background: var(--primary-soft);
            border-radius: 7px;
            font-size: 10px
        }

        .claim-badge {
            display: inline-flex;
            padding: 4px 8px;
            border-radius: 999px;
            background: #eaf8f0;
            color: #168449;
            font-size: 9px;
            font-weight: 700
        }

        .select2-container {
            width: 100% !important
        }

        .select2-container .select2-selection--single {
            height: 34px;
            border-color: var(--line);
            border-radius: 7px;
            background: var(--card-bg)
        }

        .select2-container .select2-selection--single .select2-selection__rendered {
            line-height: 32px;
            font-size: 11px;
            color: var(--text)
        }

        .select2-container .select2-selection--single .select2-selection__arrow {
            height: 32px
        }

        .select2-dropdown {
            border-color: var(--line);
            font-size: 11px
        }

        .theme-toast {
            position: fixed;
            right: 18px;
            top: 18px;
            z-index: 20000;
            padding: 11px 14px;
            border-radius: 10px;
            color: #fff;
            font-size: 11px;
            font-weight: 600;
            opacity: 0;
            transform: translateY(-10px);
            transition: .22s
        }

        .theme-toast.show {
            opacity: 1;
            transform: none
        }

        .theme-toast-success {
            background: #168449
        }

        .theme-toast-error {
            background: #c0392b
        }

        .modal-content {
            background: var(--card-bg);
            color: var(--text);
            border-color: var(--line)
        }

        .modal-header,
        .modal-footer {
            border-color: var(--line)
        }

        .billing-items-table{table-layout:fixed;min-width:1120px;width:100%}
        .billing-items-table .col-product{width:32%}
        .billing-items-table .col-qty{width:7%}
        .billing-items-table .col-rate{width:10%}
        .billing-items-table .col-wastage{width:8%}
        .billing-items-table .col-making{width:9%}
        .billing-items-table .col-money{width:8%}
        .billing-items-table .col-total{width:10%}
        .billing-items-table .col-action{width:4%}
        .billing-items-table th,.billing-items-table td,.payment-table th,.payment-table td{padding:7px 6px}
        .billing-items-table .form-control,.billing-items-table .form-select,.payment-table .form-control,.payment-table .form-select{width:100%;min-width:0;padding:6px 8px;font-size:11px}
        .billing-items-table .line-total{font-weight:800;text-align:right;background:var(--primary-soft)}
        .billing-items-table .stock-info{display:block;margin-top:3px;font-size:8px;line-height:1.25;white-space:normal}
        .payment-table{table-layout:fixed;min-width:700px;width:100%}
        .summary-card .bill-body{padding:10px 12px}
        .summary-row{padding:5px 0;font-size:10px;gap:10px}
        .summary-row strong{white-space:nowrap;text-align:right}
        .summary-card .form-control{min-height:34px;padding:6px 9px}
        .table-responsive{overflow-x:auto}

        @media(max-width:1199px) {
            .summary-card {
                position: static
            }
        }

        @media(max-width:767px) {
            .standalone-wrap {
                padding: 8px
            }

            .scan-wrap {
                grid-template-columns: 1fr
            }

            .table {
                min-width: 1100px
            }
        }
    </style>
</head>

<body>
    <div class="standalone-wrap">
        <form id="billingForm" autocomplete="off"><input type="hidden" name="csrf_token"
                value="<?= e($csrfToken) ?>"><input type="hidden" name="action" value="save"><input type="hidden"
                name="chit_claims_json" id="chitClaimsJson" value="[]">
            <div class="bill-card">
                <div class="bill-head">
                    <div>
                        <div class="bill-title">Create Bill</div>
                        <div class="small text-muted">Jewellery billing with barcode scan and chit claim.</div>
                    </div><a href="sales-list.php" class="btn btn-light btn-sm"><i
                            class="fa-solid fa-arrow-left me-2"></i>Back</a>
                </div>
            </div>
            <div class="row g-2">
                <div class="col-xl-9">
                    <div class="bill-card">
                        <div class="bill-head">
                            <div class="section-title">Bill Details</div>
                        </div>
                        <div class="bill-body">
                            <div class="row g-2">
                                <div class="col-md-3"><label class="field-label">Bill No</label><input
                                        class="form-control" value="<?= e($defaultBillNo) ?>" readonly></div>
                                <div class="col-md-3"><label class="field-label">Bill Date *</label><input type="date"
                                        name="invoice_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                                </div>
                                <div class="col-md-3"><label class="field-label">Bill Time *</label><input type="time"
                                        name="invoice_time" class="form-control" value="<?= date('H:i') ?>" required>
                                </div>
                                <div class="col-md-3"><label class="field-label">Bill Type</label><select
                                        name="bill_type" class="form-select select2-static">
                                        <option>Retail</option>
                                        <option>GST</option>
                                        <option>Estimate</option>
                                        <option>Exchange</option>
                                    </select></div>
                                <div class="col-md-7"><div class="d-flex justify-content-between align-items-center"><label class="field-label mb-1">Customer *</label><button type="button" class="btn btn-link btn-sm p-0" id="openCustomerModal">+ New Customer</button></div><select
                                        name="customer_id" id="customerId" class="form-select select2-customer"
                                        required>
                                        <option value="">Select customer</option><?php foreach ($customers as $c): ?>
                                            <option value="<?= (int) $c['id'] ?>">
                                                <?= e($c['customer_name'] . (!empty($c['customer_code']) ? ' - ' . $c['customer_code'] : '') . (!empty($c['mobile']) ? ' - ' . $c['mobile'] : '')) ?>
                                            </option><?php endforeach ?>
                                        
                                    </select>
                                                                        <div class="chit-panel" id="customerChitPanel">
                                        <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
                                            <div><strong class="small">Customer Chits</strong>
                                                <div class="small text-muted" id="chitPanelText"></div>
                                            </div><button type="button" class="btn-soft" id="openClaimModal"><i
                                                    class="fa-solid fa-hand-holding-dollar me-1"></i>Claim Chit
                                                Amount</button>
                                        </div>
                                        <div id="chitQuickList" class="d-grid gap-2"></div>
                                    </div>
                                </div>
                                <div class="col-md-5"><label class="field-label">Notes</label><input name="notes"
                                        class="form-control" placeholder="Bill notes"></div>
                            </div>
                        </div>
                    </div>
                    <div class="bill-card">
                        <div class="bill-head">
                            <div class="section-title">Scan Barcode</div>
                        </div>
                        <div class="bill-body">
                            <div class="scan-wrap">
                                <div><label class="field-label">Scan or enter product barcode</label><input type="text"
                                        id="barcodeScan" class="form-control scan-input"
                                        placeholder="Scan barcode and press Enter" autocomplete="off"></div><button
                                    type="button" class="btn-theme" id="addScannedProduct"><i
                                        class="fa-solid fa-barcode me-1"></i>Add Product</button>
                            </div>
                            <div class="small text-muted mt-2">A matching product is added automatically. Scanning the
                                same barcode again increases quantity.</div>
                        </div>
                    </div>
                    <div class="bill-card">
                        <div class="bill-head">
                            <div class="section-title">Bill Items</div><button type="button" class="btn-theme btn-sm"
                                id="addItem"><i class="fa-solid fa-plus me-1"></i>Add Item</button>
                        </div>
                        <div class="table-responsive">
                            <table class="table mb-0 billing-items-table">
                                <colgroup><col class="col-product"><col class="col-qty"><col class="col-rate"><col class="col-wastage"><col class="col-making"><col class="col-money"><col class="col-money"><col class="col-money"><col class="col-total"><col class="col-action"></colgroup>
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th>Qty</th>
                                        <th>Rate</th>
                                        <th>Wastage %</th>
                                        <th>Making</th>
                                        <th>Stone</th>
                                        <th>Other</th>
                                        <th>Discount</th>
                                        <th>Total</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody id="itemsBody"></tbody>
                            </table>
                        </div>
                    </div>
                    <div class="bill-card">
                        <div class="bill-head">
                            <div class="section-title">Split Payments</div><button type="button"
                                class="btn-theme btn-sm" id="addPayment"><i class="fa-solid fa-plus me-1"></i>Add
                                Payment</button>
                        </div>
                        <div class="table-responsive">
                            <table class="table mb-0 payment-table">
                                <colgroup><col style="width:28%"><col style="width:22%"><col style="width:44%"><col style="width:6%"></colgroup>
                                <thead>
                                    <tr>
                                        <th>Method</th>
                                        <th>Amount</th>
                                        <th>Reference</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody id="paymentsBody"></tbody>
                            </table>
                        </div>
                        <div class="bill-body text-end"><strong>Split Total: ₹<span id="splitTotal">0.00</span></strong>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3">
                    <div class="bill-card summary-card">
                        <div class="bill-head">
                            <div class="section-title">Bill Summary</div>
                        </div>
                        <div class="bill-body"><label class="field-label">Overall Discount</label><input type="number"
                                min="0" step="0.01" name="overall_discount" id="overallDiscount"
                                class="form-control mb-2" value="0"><label class="field-label">Round Off</label><input
                                type="number" step="0.01" name="round_off" id="roundOff" class="form-control mb-2"
                                value="0"><label class="field-label">Paid Amount</label><input type="number" min="0"
                                step="0.01" name="paid_amount" id="paidAmount" class="form-control mb-3" value="0" readonly>
                            <div class="summary-row"><span>Subtotal</span><strong>₹<span
                                        id="sumSubtotal">0.00</span></strong></div>
                            <div class="summary-row"><span>Discount</span><strong>₹<span
                                        id="sumDiscount">0.00</span></strong></div>
                            <div class="summary-row"><span>Taxable</span><strong>₹<span
                                        id="sumTaxable">0.00</span></strong></div>
                            <div class="summary-row"><span>CGST</span><strong>₹<span id="sumCgst">0.00</span></strong>
                            </div>
                            <div class="summary-row"><span>SGST</span><strong>₹<span id="sumSgst">0.00</span></strong>
                            </div>
                            <div class="summary-row"><span>Chit Claim</span><strong class="text-success">- ₹<span
                                        id="sumChitClaim">0.00</span></strong></div>
                            <div class="summary-row"><span>Grand Total</span><strong class="summary-total">₹<span
                                        id="sumGrand">0.00</span></strong></div>
                            <div class="summary-row"><span>Balance</span><strong>₹<span
                                        id="sumBalance">0.00</span></strong></div><button
                                class="btn btn-theme w-100 mt-3" id="saveBtn"><i
                                    class="fa-solid fa-floppy-disk me-2"></i>Save Bill</button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
    <div class="modal fade" id="newCustomerModal" tabindex="-1">
      <div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
        <div class="modal-header"><div><h5 class="modal-title">Add Billing Customer</h5><div class="small text-muted">The customer will be created with Billing service enabled.</div></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <form id="newCustomerForm"><div class="modal-body"><input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>"><input type="hidden" name="action" value="create_customer">
          <div class="row g-2"><div class="col-md-6"><label class="field-label">Customer Name *</label><input name="customer_name" class="form-control" required></div><div class="col-md-6"><label class="field-label">Mobile *</label><input name="mobile" class="form-control" maxlength="20" required></div><div class="col-md-6"><label class="field-label">Email</label><input type="email" name="email" class="form-control"></div><div class="col-md-6"><label class="field-label">GSTIN</label><input name="gstin" class="form-control text-uppercase" maxlength="30"></div><div class="col-md-8"><label class="field-label">Address</label><input name="address_line1" class="form-control"></div><div class="col-md-4"><label class="field-label">Pincode</label><input name="pincode" class="form-control" maxlength="20"></div></div>
        </div><div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn-theme" id="saveCustomerBtn">Create Customer</button></div></form>
      </div></div>
    </div>
    <div class="modal fade" id="chitClaimModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title">Claim Chit Amount</h5>
                        <div class="small text-muted">Enter how much of each chit should be applied to this bill.</div>
                    </div><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Chit</th>
                                    <th>Ticket</th>
                                    <th>Status</th>
                                    <th>Chit Value</th>
                                    <th>Paid Collections</th>
                                    <th>Already Claimed</th>
                                    <th>Available</th>
                                    <th style="min-width:150px">Claim Now</th>
                                </tr>
                            </thead>
                            <tbody id="claimTableBody"></tbody>
                        </table>
                    </div>
                    <div id="claimEmpty" class="text-center text-muted py-4 d-none">No claimable chits found for this
                        customer.</div>
                </div>
                <div class="modal-footer">
                    <div class="me-auto"><strong>Total Claim: ₹<span id="modalClaimTotal">0.00</span></strong></div>
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button
                        type="button" class="btn-theme" id="applyChitClaims">Apply Claim</button>
                </div>
            </div>
        </div>
    </div>
    <?php include('includes/script.php'); ?>
    <script src="assets/js/script.js"></script>
    <script>
        (function () {
            'use strict';

            const products = <?= json_encode($products, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
            const payments = <?= json_encode($paymentMethods, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
            const csrfToken = <?= json_encode($csrfToken) ?>;

            function loadScript(src) {
                return new Promise((resolve, reject) => {
                    const existing = [...document.scripts].find(s => s.src === src);
                    if (existing) {
                        if (existing.dataset.loaded === '1') return resolve();
                        existing.addEventListener('load', resolve, { once: true });
                        existing.addEventListener('error', reject, { once: true });
                        return;
                    }
                    const script = document.createElement('script');
                    script.src = src;
                    script.onload = () => { script.dataset.loaded = '1'; resolve(); };
                    script.onerror = reject;
                    document.head.appendChild(script);
                });
            }

            async function ensureSelect2() {
                try {
                    if (!window.jQuery) {
                        await loadScript('https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js');
                    }
                    if (!window.jQuery || !window.jQuery.fn) return false;
                    if (!window.jQuery.fn.select2) {
                        await loadScript('https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js');
                    }
                    return !!window.jQuery.fn.select2;
                } catch (error) {
                    console.warn('Select2 could not be loaded. Native selects will be used.', error);
                    return false;
                }
            }

            function startBillingPage() {
                const form = document.getElementById('billingForm');
                const items = document.getElementById('itemsBody');
                const pays = document.getElementById('paymentsBody');
                const customer = document.getElementById('customerId');
                const newCustomerModalElement = document.getElementById('newCustomerModal');
                const newCustomerForm = document.getElementById('newCustomerForm');
                const scan = document.getElementById('barcodeScan');
                const claimModalElement = document.getElementById('chitClaimModal');
                let newCustomerModal = null;

                if (!form || !items || !pays || !customer || !scan) {
                    console.error('Billing page elements are missing.');
                    return;
                }

                const byBarcode = new Map(
                    products
                        .filter(p => String(p.barcode || '').trim())
                        .map(p => [String(p.barcode).trim().toLowerCase(), p])
                );

                let customerChits = [];
                let appliedClaims = [];
                let select2Ready = false;
                let claimModal = null;

                if (window.bootstrap && window.bootstrap.Modal && claimModalElement) { claimModal = new window.bootstrap.Modal(claimModalElement); }
                if (window.bootstrap && window.bootstrap.Modal && newCustomerModalElement) { newCustomerModal = new window.bootstrap.Modal(newCustomerModalElement); }

                function showClaimModal() {
                    if (claimModal) {
                        claimModal.show();
                        return;
                    }
                    claimModalElement.classList.add('show');
                    claimModalElement.style.display = 'block';
                    claimModalElement.removeAttribute('aria-hidden');
                    claimModalElement.setAttribute('aria-modal', 'true');
                    document.body.classList.add('modal-open');
                }

                function hideClaimModal() {
                    if (claimModal) {
                        claimModal.hide();
                        return;
                    }
                    claimModalElement.classList.remove('show');
                    claimModalElement.style.display = 'none';
                    claimModalElement.setAttribute('aria-hidden', 'true');
                    claimModalElement.removeAttribute('aria-modal');
                    document.body.classList.remove('modal-open');
                }

                function esc(v) {
                    return String(v ?? '').replace(/[&<>'"]/g, c => ({
                        '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;'
                    }[c]));
                }

                function toast(type, message) {
                    const x = document.createElement('div');
                    x.className = 'theme-toast theme-toast-' + type;
                    x.textContent = message;
                    document.body.appendChild(x);
                    requestAnimationFrame(() => x.classList.add('show'));
                    setTimeout(() => {
                        x.classList.remove('show');
                        setTimeout(() => x.remove(), 250);
                    }, 3200);
                }

                function money(v) {
                    return Number(v || 0).toFixed(2);
                }

                function initSelect2(target, placeholder) {
                    if (!select2Ready || !window.jQuery || !window.jQuery.fn.select2) return;
                    const $elements = window.jQuery(target);
                    $elements.each(function () {
                        const $el = window.jQuery(this);
                        if ($el.data('select2')) return;
                        $el.select2({
                            width: '100%',
                            placeholder: placeholder || 'Select',
                            allowClear: true
                        });
                    });
                }

                function productOptions() {
                    return '<option value="">Select product</option>' + products.map(p =>
                        `<option value="${Number(p.id)}">${esc(p.product_name + ' - ' + p.product_code + ' (Stock: ' + Number(p.stock_qty).toFixed(3) + ')')}</option>`
                    ).join('');
                }

                function paymentOptions() {
                    return '<option value="">Select method</option>' + payments.map(p =>
                        `<option value="${Number(p.id)}">${esc(p.method_name)}</option>`
                    ).join('');
                }

                function enhanceRow(row) {
                    const productSelect = row.querySelector('.product-select');
                    const paymentSelect = row.querySelector('.payment-method');
                    if (productSelect) initSelect2(productSelect, 'Select product');
                    if (paymentSelect) initSelect2(paymentSelect, 'Select method');
                }

                function fillProduct(row, product) {
                    row.querySelector('.rate').value = product.live_metal_rate || product.sale_rate || 0;
                    row.querySelector('.wastage').value = product.wastage_percent || 0;
                    row.querySelector('.making').value = product.making_charge || 0;
                    row.querySelector('.stock-info').textContent =
                        'Barcode: ' + (product.barcode || '—') +
                        ' · Available: ' + Number(product.stock_qty || 0).toFixed(3) +
                        ' · Live rate: ₹' + money(product.live_metal_rate || product.sale_rate || 0) +
                        (product.live_rate_effective_from ? ' · Effective: ' + product.live_rate_effective_from : ' · Product fallback rate');
                    calc();
                }

                function addItem(product = null, qty = 1) {
                    items.insertAdjacentHTML('beforeend', `
                    <tr class="item-row">
                        <td>
                            <select name="product_id[]" class="form-select product-select">${productOptions()}</select>
                            <small class="text-muted stock-info"></small>
                        </td>
                        <td><input name="quantity[]" type="number" min="0.001" step="0.001" value="${qty}" class="form-control qty"></td>
                        <td><input name="metal_rate[]" type="number" min="0" step="0.01" value="0" class="form-control rate"></td>
                        <td><input name="wastage_percent[]" type="number" min="0" step="0.001" value="0" class="form-control wastage"></td>
                        <td><input name="making_charge[]" type="number" min="0" step="0.01" value="0" class="form-control making"></td>
                        <td><input name="stone_amount[]" type="number" min="0" step="0.01" value="0" class="form-control stone"></td>
                        <td><input name="other_charge[]" type="number" min="0" step="0.01" value="0" class="form-control other"></td>
                        <td><input name="item_discount[]" type="number" min="0" step="0.01" value="0" class="form-control discount"></td>
                        <td><input class="form-control line-total" value="0.00" readonly></td>
                        <td><button type="button" class="btn btn-sm btn-outline-danger remove-row"><i class="fa-solid fa-xmark"></i></button></td>
                    </tr>`);

                    const row = items.lastElementChild;
                    enhanceRow(row);

                    if (product) {
                        const select = row.querySelector('.product-select');
                        select.value = String(product.id);
                        if (select2Ready && window.jQuery) {
                            window.jQuery(select).val(String(product.id)).trigger('change.select2');
                        }
                        fillProduct(row, product);
                    }
                    calc();
                }

                function addPay() {
                    pays.insertAdjacentHTML('beforeend', `
                    <tr class="payment-row">
                        <td><select name="payment_method_id[]" class="form-select payment-method">${paymentOptions()}</select></td>
                        <td><input name="payment_amount[]" type="number" min="0" step="0.01" value="0" class="form-control pay-amount"></td>
                        <td><input name="payment_reference[]" class="form-control" placeholder="UPI / Txn / Ref"></td>
                        <td><button type="button" class="btn btn-sm btn-outline-danger remove-row"><i class="fa-solid fa-xmark"></i></button></td>
                    </tr>`);
                    enhanceRow(pays.lastElementChild);
                    calc();
                }

                function addByBarcode() {
                    const originalCode = scan.value.trim();
                    const code = originalCode.toLowerCase();
                    if (!code) {
                        scan.focus();
                        return;
                    }
                    const product = byBarcode.get(code);
                    if (!product) {
                        toast('error', 'No active product found for barcode ' + originalCode);
                        scan.select();
                        return;
                    }
                    const existing = [...items.querySelectorAll('.item-row')].find(row =>
                        row.querySelector('.product-select').value === String(product.id)
                    );
                    if (existing) {
                        const qty = existing.querySelector('.qty');
                        qty.value = (Number(qty.value) || 0) + 1;
                        calc();
                    } else {
                        addItem(product, 1);
                    }
                    scan.value = '';
                    scan.focus();
                    toast('success', product.product_name + ' added.');
                }

                function claimTotal() {
                    return appliedClaims.reduce((sum, x) => sum + Number(x.claim_amount || 0), 0);
                }

                function calc() {
                    let subtotal = 0;
                    let itemDisc = 0;
                    let taxable = 0;
                    let cgst = 0;
                    let sgst = 0;

                    items.querySelectorAll('.item-row').forEach(row => {
                        const product = products.find(x => String(x.id) === row.querySelector('.product-select').value);
                        const qty = Number(row.querySelector('.qty').value) || 0;
                        const rate = Number(row.querySelector('.rate').value) || 0;
                        const wastagePercent = Number(row.querySelector('.wastage').value) || 0;
                        const making = Number(row.querySelector('.making').value) || 0;
                        const stone = Number(row.querySelector('.stone').value) || 0;
                        const other = Number(row.querySelector('.other').value) || 0;
                        const discount = Number(row.querySelector('.discount').value) || 0;

                        if (!product) {
                            row.querySelector('.line-total').value = '0.00';
                            return;
                        }

                        const netWeight = (Number(product.net_weight) || 0) * qty;
                        const metal = netWeight > 0 ? netWeight * rate : qty * rate;
                        const wastage = metal * wastagePercent / 100;
                        const rowSubtotal = metal + making + wastage + stone + other;
                        const rowTaxable = Math.max(0, rowSubtotal - discount);
                        const tax = rowTaxable * (Number(product.tax_percent) || 0) / 100;
                        const total = rowTaxable + tax;

                        row.querySelector('.line-total').value = money(total);
                        subtotal += rowSubtotal;
                        itemDisc += discount;
                        taxable += rowTaxable;
                        cgst += tax / 2;
                        sgst += tax / 2;
                    });

                    const overall = Number(document.getElementById('overallDiscount').value) || 0;
                    const round = Number(document.getElementById('roundOff').value) || 0;
                    taxable = Math.max(0, taxable - overall);

                    const beforeClaim = taxable + cgst + sgst + round;
                    const appliedClaim = Math.min(claimTotal(), Math.max(0, beforeClaim));
                    const grand = Math.max(0, beforeClaim - appliedClaim);
                    const paid = Math.min([...pays.querySelectorAll('.pay-amount')].reduce((a,x)=>a+(Number(x.value)||0),0), grand);

                    document.getElementById('sumSubtotal').textContent = money(subtotal);
                    document.getElementById('sumDiscount').textContent = money(itemDisc + overall);
                    document.getElementById('sumTaxable').textContent = money(taxable);
                    document.getElementById('sumCgst').textContent = money(cgst);
                    document.getElementById('sumSgst').textContent = money(sgst);
                    document.getElementById('sumChitClaim').textContent = money(appliedClaim);
                    document.getElementById('sumGrand').textContent = money(grand);
                    document.getElementById('sumBalance').textContent = money(Math.max(0, grand - paid));

                    let split = 0;
                    pays.querySelectorAll('.pay-amount').forEach(x => split += Number(x.value) || 0);
                    document.getElementById('splitTotal').textContent = money(split);
                    document.getElementById('paidAmount').value = money(split);
                    document.getElementById('sumBalance').textContent = money(Math.max(0, grand - Math.min(split, grand))); 
                    document.getElementById('chitClaimsJson').value = JSON.stringify(appliedClaims);
                }

                async function loadCustomerChits() {
                    customerChits = [];
                    appliedClaims = [];
                    calc();

                    const id = Number(customer.value || 0);
                    const wrap = document.getElementById('customerChitPanel');
                    const list = document.getElementById('chitQuickList');
                    wrap.classList.remove('show');
                    list.innerHTML = '';
                    if (!id) return;

                    try {
                        const fd = new FormData();
                        fd.append('action', 'list_customer_chits');
                        fd.append('csrf_token', csrfToken);
                        fd.append('customer_id', String(id));
                        const res = await fetch('api/sale-chit-claims.php', {
                            method: 'POST', body: fd, credentials: 'same-origin',
                            headers: { 'X-Requested-With': 'XMLHttpRequest' }
                        });
                        const data = await res.json();
                        if (!res.ok || !data.success) throw new Error(data.message || 'Unable to load chits.');
                        customerChits = data.chits || [];
                        if (customerChits.length) {
                            wrap.classList.add('show');
                            document.getElementById('chitPanelText').textContent =
                                customerChits.length + ' chit(s) found. Available claim ₹' +
                                money(customerChits.reduce((sum, x) => sum + Number(x.available_amount || 0), 0));
                            list.innerHTML = customerChits.slice(0, 3).map(x =>
                                `<div class="chit-chip"><span><strong>${esc(x.group_name)}</strong> · ${esc(x.group_no)} · Ticket ${esc(x.ticket_no)}</span><span class="claim-badge">₹${money(x.available_amount)} available</span></div>`
                            ).join('');
                        }
                    } catch (error) {
                        toast('error', error.message);
                    }
                }

                function renderClaimModal() {
                    const body = document.getElementById('claimTableBody');
                    const empty = document.getElementById('claimEmpty');
                    body.innerHTML = customerChits.map(x => {
                        const current = appliedClaims.find(c => Number(c.chit_member_id) === Number(x.chit_member_id));
                        return `<tr data-member="${Number(x.chit_member_id)}">
                        <td><strong>${esc(x.group_name)}</strong><div class="small text-muted">${esc(x.group_no)} · ${esc(x.chit_type)}</div></td>
                        <td>${esc(x.ticket_no)}</td><td>${esc(x.member_status)}</td>
                        <td>₹${money(x.chit_value)}</td><td>₹${money(x.paid_amount)}</td>
                        <td>₹${money(x.claimed_amount)}</td><td><strong>₹${money(x.available_amount)}</strong></td>
                        <td><input type="number" class="form-control claim-now" min="0" max="${Number(x.available_amount)}" step="0.01" value="${money(current ? current.claim_amount : 0)}"></td>
                    </tr>`;
                    }).join('');
                    empty.classList.toggle('d-none', customerChits.length > 0);
                    updateModalClaimTotal();
                }

                function updateModalClaimTotal() {
                    let total = 0;
                    document.querySelectorAll('.claim-now').forEach(x => total += Number(x.value) || 0);
                    document.getElementById('modalClaimTotal').textContent = money(total);
                }

                customer.addEventListener('change', loadCustomerChits);

                document.getElementById('openCustomerModal').addEventListener('click', () => {
                    if (newCustomerModal) newCustomerModal.show();
                    else { newCustomerModalElement.classList.add('show'); newCustomerModalElement.style.display='block'; }
                    setTimeout(() => newCustomerForm.querySelector('[name=customer_name]').focus(), 150);
                });
                newCustomerForm.addEventListener('submit', async event => {
                    event.preventDefault();
                    const button=document.getElementById('saveCustomerBtn'); const old=button.innerHTML;
                    button.disabled=true; button.innerHTML='<i class="fa-solid fa-spinner fa-spin me-1"></i>Saving...';
                    try {
                        const res=await fetch('api/billing-customer-save.php',{method:'POST',body:new FormData(newCustomerForm),credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}});
                        const raw=await res.text(); let data; try{data=JSON.parse(raw);}catch(_){throw new Error('Customer API returned an invalid response.');}
                        if(!res.ok||!data.success) throw new Error(data.message||'Unable to create customer.');
                        const option=new Option(data.customer.customer_name+' - '+data.customer.customer_code+' - '+data.customer.mobile,data.customer.id,true,true);
                        customer.add(option);
                        if(select2Ready&&window.jQuery) window.jQuery(customer).trigger('change'); else customer.dispatchEvent(new Event('change',{bubbles:true}));
                        newCustomerForm.reset(); if(newCustomerModal)newCustomerModal.hide();
                        toast('success','Billing customer created.'); scan.focus();
                    } catch(error){toast('error',error.message);} finally {button.disabled=false;button.innerHTML=old;}
                });

                document.getElementById('addItem').addEventListener('click', () => addItem());
                document.getElementById('addPayment').addEventListener('click', addPay);
                document.getElementById('addScannedProduct').addEventListener('click', addByBarcode);

                scan.addEventListener('keydown', event => {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        addByBarcode();
                    }
                });

                document.getElementById('openClaimModal').addEventListener('click', () => {
                    renderClaimModal();
                    showClaimModal();
                });

                document.getElementById('claimTableBody').addEventListener('input', event => {
                    if (!event.target.classList.contains('claim-now')) return;
                    const max = Number(event.target.max) || 0;
                    if (Number(event.target.value) > max) event.target.value = money(max);
                    updateModalClaimTotal();
                });

                document.getElementById('applyChitClaims').addEventListener('click', () => {
                    appliedClaims = [];
                    document.querySelectorAll('#claimTableBody tr[data-member]').forEach(row => {
                        const amount = Number(row.querySelector('.claim-now').value) || 0;
                        if (amount <= 0) return;
                        const chit = customerChits.find(x => Number(x.chit_member_id) === Number(row.dataset.member));
                        if (!chit) return;
                        appliedClaims.push({
                            chit_member_id: Number(row.dataset.member),
                            chit_group_id: Number(chit.chit_group_id),
                            claim_amount: amount
                        });
                    });
                    calc();
                    hideClaimModal();
                    toast('success', 'Chit claim applied to bill.');
                });

                claimModalElement.querySelectorAll('[data-bs-dismiss="modal"]').forEach(button => {
                    button.addEventListener('click', hideClaimModal);
                });

                document.addEventListener('click', event => {
                    const button = event.target.closest('.remove-row');
                    if (!button) return;
                    const row = button.closest('tr');
                    if (select2Ready && window.jQuery) {
                        window.jQuery(row).find('select').each(function () {
                            const $select = window.jQuery(this);
                            if ($select.data('select2')) $select.select2('destroy');
                        });
                    }
                    row.remove();
                    calc();
                });

                document.addEventListener('change', event => {
                    if (!event.target.matches('.product-select')) return;
                    const row = event.target.closest('tr');
                    const product = products.find(x => String(x.id) === event.target.value);
                    if (product) fillProduct(row, product); else calc();
                });

                document.addEventListener('input', event => {
                    if (event.target.closest('#billingForm')) calc();
                });

                form.addEventListener('submit', async event => {
                    event.preventDefault();
                    if (!customer.value) {
                        toast('error', 'Please select a customer.');
                        return;
                    }
                                        if (!items.querySelector('.item-row')) {
                        toast('error', 'Add at least one product.');
                        return;
                    }

                    const button = document.getElementById('saveBtn');
                    const old = button.innerHTML;
                    button.disabled = true;
                    button.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Saving...';
                    try {
                        const res = await fetch('api/billing-save.php', {
                            method: 'POST', body: new FormData(form), credentials: 'same-origin',
                            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                        });
                        const raw = await res.text();
                        let data;
                        try { data = JSON.parse(raw); }
                        catch (_) { throw new Error('Billing API returned an invalid response.'); }
                        if (!res.ok || !data.success) throw new Error(data.message || 'Unable to save bill.');
                        toast('success', data.message);
                        setTimeout(() => {
                            location.href = 'sales-list.php?msg=created&sale_id=' + encodeURIComponent(data.sale_id);
                        }, 700);
                    } catch (error) {
                        toast('error', error.message);
                    } finally {
                        button.disabled = false;
                        button.innerHTML = old;
                    }
                });

                addItem();
                addPay();
                calc();
                scan.focus();

                ensureSelect2().then(ready => {
                    select2Ready = ready;
                    if (!ready) {
                        toast('error', 'Select2 could not load. Native dropdowns are active and all buttons will still work.');
                        return;
                    }
                    initSelect2('.select2-customer', 'Select customer');
                    initSelect2('.select2-static', 'Select');
                    items.querySelectorAll('.item-row').forEach(enhanceRow);
                    pays.querySelectorAll('.payment-row').forEach(enhanceRow);
                    window.jQuery(customer).on('select2:select select2:clear', function () {
                        customer.dispatchEvent(new Event('change', { bubbles: true }));
                    });
                });
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', startBillingPage, { once: true });
            } else {
                startBillingPage();
            }
        })();
    </script>
</body>

</html>