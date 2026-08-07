<?php require __DIR__ . '/_common.php'; ?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($businessName) ?> - Pawn Entry</title>
    <?php include('includes/links.php');
    require __DIR__ . '/_style.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0/dist/css/select2.min.css" rel="stylesheet">
    <style>
        .item-box {
            position: relative;
            padding: 14px;
            margin-bottom: 12px;
            border: 1px solid var(--line);
            border-radius: var(--radius);
            background: color-mix(in srgb, var(--muted) 4%, transparent)
        }

        .item-title {
            font-size: 10px;
            font-weight: 800;
            color: var(--primary-dark);
            text-transform: uppercase;
            margin-bottom: 10px
        }


        .summary-line {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            padding: 7px 0;
            border-bottom: 1px dashed var(--line);
            font-size: 11px
        }

        .loan-value {
            padding: 14px;
            border-radius: 10px;
            background: var(--primary-soft);
            text-align: center
        }

        .loan-value strong {
            display: block;
            font-size: 25px;
            color: var(--primary-dark)
        }

        .theme-toast {
            position: fixed;
            right: 18px;
            top: 78px;
            z-index: 20000;
            padding: 11px 14px;
            border-radius: 10px;
            color: #fff;
            font-size: 11px;
            font-weight: 600
        }

        .theme-toast-success {
            background: #168449
        }

        .theme-toast-error {
            background: #c0392b
        }

        .info-strip {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 8px
        }

        .info-chip {
            padding: 10px;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: color-mix(in srgb, var(--muted) 4%, transparent)
        }

        .info-chip span {
            display: block;
            font-size: 9px;
            color: var(--muted);
            text-transform: uppercase
        }

        .info-chip strong {
            display: block;
            margin-top: 3px;
            font-size: 12px
        }

        .kyc-ok {
            color: #168449
        }

        .kyc-bad {
            color: #c0392b
        }

        .closure-only-note {
            display: none;
            padding: 10px;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: var(--primary-soft);
            font-size: 10px;
            color: var(--primary-dark)
        }

        .first-interest-box {
            padding: 12px;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: color-mix(in srgb, var(--primary) 5%, transparent)
        }

        .interest-preview {
            padding: 10px;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: color-mix(in srgb, var(--primary) 5%, transparent)
        }

        .required-star {
            color: #dc3545;
            font-weight: 900;
            margin-left: 2px;
        }

        .select2-container {
            width: 100% !important;
        }

        .select2-container .select2-selection--single {
            height: 38px;
            border: 1px solid var(--line);
            border-radius: 9px;
            background: var(--card-bg);
        }

        .select2-container .select2-selection--single .select2-selection__rendered {
            line-height: 36px;
            color: var(--text);
            padding-left: 12px;
        }

        .select2-container .select2-selection--single .select2-selection__arrow {
            height: 36px;
        }

        .select2-dropdown,
        .select2-search__field {
            background: var(--card-bg);
            color: var(--text);
            border-color: var(--line) !important;
        }

        .select2-results__option {
            font-size: 11px;
        }

        .proof-preview-wrap {
            min-height: 96px;
            border: 1px dashed var(--line);
            border-radius: 10px;
            padding: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: color-mix(in srgb, var(--muted) 3%, transparent);
        }

        .proof-preview-wrap img {
            display: none;
            width: 100%;
            max-width: 220px;
            max-height: 140px;
            object-fit: contain;
            border-radius: 8px;
        }

        .proof-preview-empty {
            color: var(--muted);
            font-size: 10px;
            text-align: center;
        }

        .rate-note {
            font-size: 8px;
            color: var(--muted);
            margin-top: 3px;
        }

        @media(max-width:767px) {
            .info-strip {
                grid-template-columns: 1fr 1fr
            }
        }
    </style>
</head>

<body>
    <?php include('includes/sidebar.php'); ?>
    <main class="app-main"><?php include('includes/nav.php'); ?>
        <div class="content-wrap">
            <div class="page-card mb-3">
                <div class="page-head">
                    <div>
                        <div class="page-title">New Pawn Entry</div>
                        <div class="small text-muted">Create a pawn loan with customer, item, rate and tenure
                            calculations.</div>
                    </div>
                    <div class="d-flex gap-2 flex-wrap"><a href="customers.php" class="btn-soft">Register Customer</a><a
                            href="metal-rates.php" class="btn-soft">Metal Rates</a><a href="pawn-list.php"
                            class="btn-soft">Pawn List</a></div>
                </div>
            </div>
            <form id="pawnForm" enctype="multipart/form-data"><input type="hidden" name="action" value="create"><input type="hidden" name="csrf_token"
                    value="<?= e($csrfToken) ?>"><input type="hidden" name="pawn_no" id="pawnNoHidden"><input
                    type="hidden" name="total_gross_weight" id="totalGrossInput"><input type="hidden"
                    name="total_stone_weight" id="totalStoneInput"><input type="hidden" name="total_net_weight"
                    id="totalNetInput"><input type="hidden" name="total_estimated_value" id="totalEstimatedInput"><input
                    type="hidden" name="last_interest_paid_upto" id="lastInterestPaidUpto"><input type="hidden"
                    name="next_interest_due_date" id="nextInterestDueDate"><input type="hidden"
                    name="auction_eligible_date" id="auctionEligibleDate"><input type="hidden"
                    name="disbursement_amount" id="disbursementAmount" value="0.00">
                <div class="row g-3">
                    <div class="col-xl-9">
                        <div class="page-card mb-3">
                            <div class="page-head">
                                <div class="section-title">Basic Information</div>
                            </div>
                            <div class="card-body-x">
                                <div class="row g-2">
                                    <div class="col-md-3"><label class="small fw-bold">Pawn No</label><input id="pawnNo"
                                            class="form-control" readonly></div>
                                    <div class="col-md-3"><label class="small fw-bold">Pawn Date <span class="required-star">*</span></label><input
                                            type="date" name="pawn_date" id="pawnDate" class="form-control"
                                            value="<?= date('Y-m-d') ?>" required></div>
                                    <div class="col-md-3"><label class="small fw-bold">Category <span class="required-star">*</span></label><select
                                            name="pawn_category_id" id="categorySelect" class="form-select" required>
                                            <option value="">Loading...</option>
                                        </select></div>
                                    <div class="col-md-3"><label class="small fw-bold">Primary Metal <span class="required-star">*</span></label><select
                                            name="primary_metal_id" id="primaryMetal" class="form-select" required>
                                            <option value="">Select metal</option>
                                        </select></div>
                                    <div class="col-md-3"><label class="small fw-bold">Loan Type</label><input
                                            name="loan_type" class="form-control" value="General"></div>
                                    <div class="col-md-3 closure-dependent" id="tenureField"><label
                                            class="small fw-bold">Tenure Months</label><input type="number" min="1"
                                            name="tenure_months" id="tenureMonths" class="form-control" value="12"
                                            required></div>
                                    <div class="col-md-3 closure-dependent" id="dueDateField"><label
                                            class="small fw-bold">Due Date</label><input type="date" name="due_date"
                                            id="dueDate" class="form-control"></div>
                                    <div class="col-md-3"><label class="small fw-bold">Interest Method</label><select
                                            name="interest_method" id="interestMethod" class="form-select">
                                            <option value="Simple">Simple</option>
                                            <option value="Reducing Balance">Reducing Balance</option>
                                            <option value="Flat">Flat</option>
                                        </select></div>
                                    <div class="col-md-3"><label class="small fw-bold">Interest Collection
                                            Cycle</label><select name="interest_collection_cycle" id="interestCycle"
                                            class="form-select">
                                            <option value="Monthly">Monthly</option>
                                            <option value="Quarterly">Quarterly</option>
                                            <option value="Half-Yearly">Half-Yearly</option>
                                            <option value="Yearly">Yearly</option>
                                            <option value="At Closure">At Closure</option>
                                            <option value="Custom">Custom</option>
                                        </select></div>
                                    <div class="col-md-3 closure-dependent" id="cycleMonthsField"><label
                                            class="small fw-bold">Cycle Months</label><input type="number" min="1"
                                            max="120" name="interest_cycle_months" id="interestCycleMonths"
                                            class="form-control" value="1"></div>
                                    <div class="col-md-3 closure-dependent" id="nextDueField"><label
                                            class="small fw-bold">Next Interest Due</label><input type="date"
                                            id="nextInterestDueDisplay" class="form-control" readonly></div>
                                    <div class="col-12">
                                        <div class="closure-only-note" id="closureNote"><i
                                                class="fa-solid fa-circle-info me-1"></i>At Closure mode collects
                                            interest when the customer closes the pawn. Tenure, due date, grace, overdue
                                            and auction settings are not applicable.</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="page-card mb-3">
                            <div class="page-head">
                                <div class="section-title">Customer Information</div>
                            </div>
                            <div class="card-body-x">
                                <div class="row g-2">
                                    <div class="col-12">
                                        <label class="small fw-bold">Customer <span class="required-star">*</span></label>
                                        <select name="customer_id" id="customerSelect" class="form-select" required>
                                            <option value="">Search by name, customer code or mobile</option>
                                        </select>
                                        <input type="hidden" name="customer_code" id="customerCode">
                                        <input type="hidden" name="customer_id_proof_existing" id="customerIdProofExisting">
                                        <div class="small text-muted mt-1">Type customer name, code or mobile number to search.</div>
                                    </div>
                                    <div class="col-md-4"><label class="small fw-bold">Name</label><input
                                            name="customer_name" id="customerName" class="form-control" readonly></div>
                                    <div class="col-md-4"><label class="small fw-bold">Mobile</label><input
                                            name="customer_mobile" id="customerMobile" class="form-control" readonly></div>
                                    <div class="col-md-4"><label class="small fw-bold">Email</label><input
                                            name="customer_email" id="customerEmail" class="form-control" readonly></div>
                                    <div class="col-md-4">
                                        <label class="small fw-bold">ID Proof Type <span class="required-star">*</span></label>
                                        <select name="id_proof_type" id="idProofType" class="form-select" required>
                                            <option value="">Select ID proof</option>
                                            <option value="Aadhar">Aadhaar Card</option>
                                            <option value="PAN">PAN Card</option>
                                            <option value="Voter">Voter ID</option>
                                            <option value="Driving">Driving Licence</option>
                                            <option value="Passport">Passport</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="small fw-bold">ID Proof Number <span class="required-star">*</span></label>
                                        <input name="id_proof_number" id="idProofNumber" class="form-control"
                                            maxlength="50" autocomplete="off" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="small fw-bold">ID Proof Image <span class="required-star">*</span></label>
                                        <input type="file" name="id_proof_image" id="idProofImage"
                                            class="form-control" accept="image/jpeg,image/png,image/webp,application/pdf">
                                        <input type="hidden" name="existing_id_proof_image" id="existingIdProofImage">
                                        <div class="small text-muted mt-1">JPG, PNG, WEBP or PDF. Existing customer proof is reused unless replaced.</div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="proof-preview-wrap">
                                            <img id="idProofPreview" alt="ID proof preview">
                                            <div id="idProofPreviewEmpty" class="proof-preview-empty">No proof image selected</div>
                                        </div>
                                    </div>
                                    <div class="col-md-8"><label class="small fw-bold">Address</label><textarea
                                            name="customer_address" id="customerAddress" class="form-control" rows="3" readonly></textarea>
                                    </div>
                                    <div class="col-12">
                                        <div class="info-strip mt-2">
                                            <div class="info-chip"><span>Pawn Service</span><strong
                                                    id="pawnServiceStatus">Not checked</strong></div>
                                            <div class="info-chip"><span>KYC Status</span><strong id="kycStatus">Not
                                                    checked</strong></div>
                                            <div class="info-chip"><span>Risk Category</span><strong
                                                    id="riskCategory">—</strong></div>
                                            <div class="info-chip"><span>Pawn Credit Limit</span><strong>₹<span
                                                        id="pawnCreditLimit">0.00</span></strong></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="page-card mb-3">
                            <div class="page-head">
                                <div class="section-title">Pawn Items</div><button type="button" class="btn-theme"
                                    id="addItemBtn">Add Item</button>
                            </div>
                            <div class="card-body-x" id="itemsWrap"></div>
                        </div>
                        <div class="page-card mb-3">
                            <div class="page-head">
                                <div class="section-title">Disbursement Details</div>
                            </div>
                            <div class="card-body-x">
                                <div class="row g-2">
                                    <div class="col-md-3"><label class="small fw-bold">Document Charge</label><input
                                            type="number" step="0.01" min="0" name="document_charge" id="documentCharge"
                                            class="form-control" value="0"></div>
                                    <div class="col-md-3"><label class="small fw-bold">Other Charge</label><input
                                            type="number" step="0.01" min="0" name="other_charge" id="otherCharge" class="form-control"
                                            value="0"></div>
                                    <div class="col-md-3"><label class="small fw-bold">Payment Method <span class="required-star">*</span></label><select
                                            name="payment_method_id" id="paymentMethod" class="form-select" required>
                                            <option value="">Select Method</option>
                                        </select></div>
                                    <div class="col-md-3"><label class="small fw-bold">Payment Reference</label><input
                                            name="payment_reference" class="form-control"></div>
                                    <div class="col-md-3 closure-dependent" id="minimumInterestField"><label
                                            class="small fw-bold">Minimum Interest
                                            Days</label><input type="number" min="0" name="minimum_interest_days"
                                            id="minimumInterestDays" class="form-control" value="0"></div>
                                    <div class="col-md-3"><label class="small fw-bold">Interest Rounding</label><select
                                            name="interest_rounding_method" id="interestRounding" class="form-select">
                                            <option value="None">No rounding (keep paise)</option>
                                            <option value="Nearest Rupee" selected>Round to nearest ₹1</option>
                                            <option value="Ceil Rupee">Always round up to next ₹1</option>
                                            <option value="Floor Rupee">Always round down to previous ₹1</option>
                                        </select></div>
                                    <div class="col-md-3 closure-dependent" id="graceDaysField"><label
                                            class="small fw-bold">Grace Days</label><input type="number" min="0"
                                            name="grace_days" id="graceDays" class="form-control" value="0"></div>
                                    <div class="col-md-3 closure-dependent" id="overdueTypeField"><label
                                            class="small fw-bold">Overdue Charge
                                            Type</label><select name="overdue_charge_type" id="overdueChargeType"
                                            class="form-select">
                                            <option>None</option>
                                            <option>Fixed</option>
                                            <option>Daily Fixed</option>
                                            <option>Monthly Fixed</option>
                                            <option>Percentage</option>
                                        </select></div>
                                    <div class="col-md-3 closure-dependent" id="overdueValueField"><label
                                            class="small fw-bold">Overdue Charge
                                            Value</label><input type="number" step="0.0001" min="0"
                                            name="overdue_charge_value" id="overdueChargeValue" class="form-control"
                                            value="0"></div>
                                    <div class="col-md-3 closure-dependent" id="maximumOverdueField"><label
                                            class="small fw-bold">Maximum Overdue
                                            Charge</label><input type="number" step="0.01" min="0"
                                            name="maximum_overdue_charge" id="maximumOverdueCharge" class="form-control"
                                            placeholder="No limit"></div>
                                    <div class="col-md-3 closure-dependent" id="auctionEligibleField"><label
                                            class="small fw-bold">Auction Eligible
                                            Date</label><input type="date" id="auctionEligibleDateDisplay"
                                            class="form-control" readonly></div>
                                    <div class="col-12" id="firstInterestSection">
                                        <div class="first-interest-box mt-2">
                                            <div class="form-check mb-2">
                                                <input class="form-check-input" type="checkbox"
                                                    name="collect_first_interest" value="1" id="collectFirstInterest">
                                                <label class="form-check-label fw-bold"
                                                    for="collectFirstInterest">Collect first interest on
                                                    pawn/disbursement date</label>
                                            </div>
                                            <div class="small text-muted mb-2">Enable this when the customer pays the
                                                first interest immediately while receiving the loan.</div>
                                            <div class="row g-2" id="firstInterestFields" style="display:none">
                                                <div class="col-md-3"><label class="small fw-bold">First Interest
                                                        Amount</label><input type="number" step="0.01" min="0.01"
                                                        name="first_interest_amount" id="firstInterestAmount"
                                                        class="form-control"></div>
                                                <div class="col-md-3"><label class="small fw-bold">Paid
                                                        Through</label><select name="first_interest_payment_method_id"
                                                        id="firstInterestPaymentMethod" class="form-select">
                                                        <option value="">Select Method</option>
                                                    </select></div>
                                                <div class="col-md-3"><label class="small fw-bold">Payment
                                                        Reference</label><input name="first_interest_reference"
                                                        id="firstInterestReference" class="form-control"></div>
                                                <div class="col-md-3"><label class="small fw-bold">Interest Paid
                                                        Upto</label><input type="date" name="first_interest_paid_upto"
                                                        id="firstInterestPaidUptoDisplay" class="form-control" readonly>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3">
                        <div class="page-card sticky-top" style="top:82px">
                            <div class="page-head">
                                <div class="section-title">Loan Details</div>
                            </div>
                            <div class="card-body-x">
                                <div class="loan-value mb-3"><span class="small text-muted">Eligible Loan
                                        Value</span><strong>₹<span id="calculatedLoan">0.00</span></strong><small
                                        id="loanPercentText" class="text-muted">Based on category limit</small></div>
                                <div class="mb-2"><label class="small fw-bold">Principal Amount <span class="required-star">*</span></label><input
                                        type="number" step="0.01" min="0.01" name="principal_amount"
                                        id="principalAmount" class="form-control" required></div>
                                <div class="mb-2">
                                    <label class="small fw-bold">Amount Given to Customer</label>
                                    <input type="text" id="disbursementAmountDisplay" class="form-control" value="₹0.00" readonly>
                                    <div class="small text-muted mt-1">Principal minus document charge and other charge.</div>
                                </div>
                                <div class="mb-2"><label class="small fw-bold">Interest % <span class="required-star">*</span></label><input type="number"
                                        step="0.001" min="0" name="interest_percent" id="interestPercent"
                                        class="form-control" required></div>
                                <div class="mb-2"><label class="small fw-bold">Interest Period</label><select
                                        name="interest_period" class="form-select">
                                        <option>Monthly</option>
                                        <option>Daily</option>
                                        <option>Yearly</option>
                                    </select></div>
                                <div class="interest-preview mb-2">
                                    <div class="summary-line"><span>First Cycle Interest</span><strong>₹<span
                                                id="cycleInterest">0.00</span></strong></div>
                                    <div class="summary-line"><span>Approx. Tenure Interest</span><strong>₹<span
                                                id="tenureInterest">0.00</span></strong></div>
                                </div>
                                <div class="mb-2"><label class="small fw-bold">Remarks</label><textarea name="remarks"
                                        class="form-control" rows="4"></textarea></div>
                                <hr>
                                <div class="summary-line"><span>Items</span><strong id="itemCount">0</strong></div>
                                <div class="summary-line"><span>Total Gross</span><strong id="totalGross">0.000
                                        g</strong></div>
                                <div class="summary-line"><span>Total Stone/Less</span><strong id="totalStone">0.000
                                        g</strong></div>
                                <div class="summary-line"><span>Total Net</span><strong id="totalNet">0.000 g</strong>
                                </div>
                                <div class="summary-line"><span>Estimated Value</span><strong>₹<span
                                            id="totalEstimated">0.00</span></strong></div>
                                <button type="submit" class="btn-theme w-100 mt-3" id="saveBtn">Save Pawn Entry</button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
            <?php include('includes/footer.php'); ?>
        </div>
    </main><?php include('includes/script.php'); ?>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0/dist/js/select2.min.js"></script>
    <script src="assets/js/script.js"></script>
    <script>
        (() => {
            'use strict'; const api = 'api/pawn-entry.php', csrf = <?= json_encode($csrfToken) ?>; let data = { customers: [], categories: [], metals: [], metal_rates: [], payment_methods: [] }; let currentMaxLoanPercent = 100;
            const $ = id => document.getElementById(id); function esc(v) { return String(v ?? '').replace(/[&<>'"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[c])) } function money(v) { return Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) } function note(t, m) { const x = document.createElement('div'); x.className = 'theme-toast theme-toast-' + (t === 'ok' ? 'success' : 'error'); x.textContent = m; document.body.appendChild(x); setTimeout(() => x.remove(), 3200) } async function req(o) { const f = new FormData(); Object.entries(o).forEach(([k, v]) => f.append(k, v)); f.append('csrf_token', csrf); const r = await fetch(api, { method: 'POST', body: f, credentials: 'same-origin', headers: { Accept: 'application/json' } }), raw = await r.text(); let j; try { j = JSON.parse(raw) } catch (e) { throw new Error('Pawn Entry API did not return JSON. HTTP ' + r.status + ': ' + raw.replace(/<[^>]*>/g, ' ').slice(0, 260)) } if (!r.ok || !j.success) throw new Error(j.message || 'Request failed'); return j }
            function metalOpts(sel = '') { return data.metals.map(m => `<option value="${m.id}" ${String(sel) === String(m.id) ? 'selected' : ''}>${esc(m.metal_name)}</option>`).join('') } function itemRow() {
                const pm = $('primaryMetal').value || '';
                return `<div class="item-box">
                    <div class="item-title">Pawn Item</div>
                    <div class="row g-2">
                        <div class="col-md-3">
                            <label class="small fw-bold">Metal <span class="required-star">*</span></label>
                            <select name="metal_id[]" class="form-select item-metal" required>
                                <option value="">Select metal</option>${metalOpts(pm)}
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="small fw-bold">Description <span class="required-star">*</span></label>
                            <input name="item_description[]" class="form-control" required>
                        </div>
                        <div class="col-md-2">
                            <label class="small fw-bold">Qty <span class="required-star">*</span></label>
                            <input type="number" min="1" name="quantity[]" class="form-control" value="1" required>
                        </div>
                        <div class="col-md-3">
                            <label class="small fw-bold">Purity</label>
                            <input name="purity[]" class="form-control item-purity" placeholder="916 / 22K">
                        </div>
                        <div class="col-md-2">
                            <label class="small fw-bold">Gross Weight (g) <span class="required-star">*</span></label>
                            <input type="number" step="0.001" min="0.001" name="gross_weight[]" class="form-control calc gross" required>
                        </div>
                        <div class="col-md-2">
                            <label class="small fw-bold">Stone / Less (g)</label>
                            <input type="number" step="0.001" min="0" name="stone_weight[]" class="form-control calc stone" value="0">
                        </div>
                        <div class="col-md-2">
                            <label class="small fw-bold">Net Weight (g)</label>
                            <input type="number" step="0.001" name="net_weight[]" class="form-control net" readonly>
                        </div>
                        <div class="col-md-2">
                            <label class="small fw-bold">Rate / Gram <span class="required-star">*</span></label>
                            <input type="number" step="0.01" min="0.01" name="rate_per_gram[]" class="form-control rate" readonly required>
                            <div class="rate-note">Automatically refreshed whenever metal or purity changes.</div>
                        </div>
                        <div class="col-md-2">
                            <label class="small fw-bold">Estimated Value</label>
                            <input type="number" step="0.01" name="estimated_value[]" class="form-control est" readonly>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="button" class="btn-soft remove-item w-100">Remove</button>
                        </div>
                        <div class="col-12">
                            <label class="small fw-bold">Item Remarks</label>
                            <input name="item_remarks[]" class="form-control">
                        </div>
                    </div>
                </div>`;
            }
            function rateFor(mid, p) {
                const metalId = String(mid || '');
                const purity = String(p || '').trim().toLowerCase();

                const rows = data.metal_rates
                    .filter(r => String(r.metal_id ?? r.metalId ?? '') === metalId)
                    .sort((a, b) => {
                        const ad = new Date(a.effective_date || a.rate_date || a.updated_at || a.created_at || 0).getTime();
                        const bd = new Date(b.effective_date || b.rate_date || b.updated_at || b.created_at || 0).getTime();
                        return bd - ad;
                    });

                const exact = rows.find(r =>
                    String(r.purity ?? r.purity_name ?? r.purity_code ?? '').trim().toLowerCase() === purity
                );

                const chosen = exact || rows[0];
                return Number(
                    chosen?.rate_per_gram ??
                    chosen?.rate ??
                    chosen?.selling_rate ??
                    chosen?.sale_rate ??
                    chosen?.current_rate ??
                    0
                );
            }

            function updateRate(box) {
                if (!box) return;

                const rateInput = box.querySelector('.rate');
                const metalSelect = box.querySelector('.item-metal');
                const purityInput = box.querySelector('.item-purity');

                const metalId = metalSelect?.value || '';
                const purity = purityInput?.value || '';
                const rate = rateFor(metalId, purity);

                // Always replace the old rate when metal/purity changes.
                rateInput.value = rate > 0 ? rate.toFixed(2) : '';
                rateInput.dataset.metalId = metalId;
                rateInput.dataset.purity = purity;

                totals();
            } function roundInterest(v) { const m = $('interestRounding')?.value || 'None'; if (m === 'Nearest Rupee') return Math.round(v); if (m === 'Ceil Rupee') return Math.ceil(v); if (m === 'Floor Rupee') return Math.floor(v); return v }
            function updateInterestPreview() { const principal = Number($('principalAmount').value || 0), percent = Number($('interestPercent').value || 0), period = document.querySelector('[name="interest_period"]').value, cycleMonths = Number($('interestCycleMonths').value || 1), tenure = Number($('tenureMonths').value || 0); let cycle = 0, total = 0; if (principal > 0 && percent > 0) { if (period === 'Daily') { cycle = principal * percent / 100 * 30 * cycleMonths; total = principal * percent / 100 * 30 * tenure } else if (period === 'Yearly') { cycle = principal * percent / 100 * (cycleMonths / 12); total = principal * percent / 100 * (tenure / 12) } else { cycle = principal * percent / 100 * cycleMonths; total = principal * percent / 100 * tenure } if ($('interestMethod').value === 'Flat') { cycle = principal * percent / 100 * cycleMonths; total = principal * percent / 100 * tenure } } $('cycleInterest').textContent = money(roundInterest(cycle)); $('tenureInterest').textContent = money(roundInterest(total)); if (!$('firstInterestAmount').dataset.manual) $('firstInterestAmount').value = roundInterest(cycle) > 0 ? Number(roundInterest(cycle)).toFixed(2) : '' }
            function updateDisbursement() {
                const principal = Math.max(0, Number($('principalAmount')?.value || 0));
                const documentCharge = Math.max(0, Number($('documentCharge')?.value || 0));
                const otherCharge = Math.max(0, Number($('otherCharge')?.value || 0));
                const amount = Math.max(0, principal - documentCharge - otherCharge);

                const hiddenAmount = $('disbursementAmount');
                const displayAmount = $('disbursementAmountDisplay');
                if (hiddenAmount) hiddenAmount.value = amount.toFixed(2);
                if (displayAmount) displayAmount.value = '₹' + money(amount);
                return amount;
            }

            function totals() { let g = 0, sn = 0, n = 0, v = 0, c = 0; document.querySelectorAll('.item-box').forEach((b, i) => { b.querySelector('.item-title').textContent = 'Pawn Item ' + (i + 1); const gross = Number(b.querySelector('.gross').value || 0), stone = Number(b.querySelector('.stone').value || 0), net = Math.max(0, gross - stone), rate = Number(b.querySelector('.rate').value || 0), est = net * rate; b.querySelector('.net').value = net.toFixed(3); b.querySelector('.est').value = est.toFixed(2); g += gross; sn += stone; n += net; v += est; c++ }); const eligible = v * (currentMaxLoanPercent / 100); $('itemCount').textContent = c; $('totalGross').textContent = g.toFixed(3) + ' g'; $('totalStone').textContent = sn.toFixed(3) + ' g'; $('totalNet').textContent = n.toFixed(3) + ' g'; $('totalEstimated').textContent = money(v); $('calculatedLoan').textContent = money(eligible); $('loanPercentText').textContent = 'Maximum ' + Number(currentMaxLoanPercent || 0).toFixed(2) + '% of estimated value'; $('totalGrossInput').value = g.toFixed(3); $('totalStoneInput').value = sn.toFixed(3); $('totalNetInput').value = n.toFixed(3); $('totalEstimatedInput').value = v.toFixed(2); if (!$('principalAmount').dataset.manual) $('principalAmount').value = eligible > 0 ? eligible.toFixed(2) : ''; updateDisbursement(); updateInterestPreview() }
            function fmtDate(x) { return x.getFullYear() + '-' + String(x.getMonth() + 1).padStart(2, '0') + '-' + String(x.getDate()).padStart(2, '0') }
            function isAtClosure() { return $('interestCycle').value === 'At Closure'; }
            function toggleClosureMode() {
                const closure = isAtClosure();
                document.querySelectorAll('.closure-dependent').forEach(el => el.style.display = closure ? 'none' : '');
                $('closureNote').style.display = closure ? 'block' : 'none';
                $('firstInterestSection').style.display = closure ? 'none' : '';
                if (closure) {
                    $('tenureMonths').required = false;
                    $('tenureMonths').disabled = true;
                    $('dueDate').disabled = true;
                    $('interestCycleMonths').disabled = true;
                    $('minimumInterestDays').disabled = true;
                    $('graceDays').disabled = true;
                    $('overdueChargeType').disabled = true;
                    $('overdueChargeValue').disabled = true;
                    $('maximumOverdueCharge').disabled = true;
                    $('collectFirstInterest').checked = false;
                    $('firstInterestFields').style.display = 'none';
                    $('dueDate').value = ''; $('nextInterestDueDate').value = ''; $('nextInterestDueDisplay').value = '';
                    $('auctionEligibleDate').value = ''; $('auctionEligibleDateDisplay').value = '';
                    $('lastInterestPaidUpto').value = '';
                } else {
                    $('tenureMonths').required = true;
                    ['tenureMonths', 'dueDate', 'interestCycleMonths', 'minimumInterestDays', 'graceDays', 'overdueChargeType', 'overdueChargeValue', 'maximumOverdueCharge'].forEach(id => $(id).disabled = false);
                }
            }
            function updateCycleFields() { const cycle = $('interestCycle').value; const map = { 'Monthly': 1, 'Quarterly': 3, 'Half-Yearly': 6, 'Yearly': 12 }; if (map[cycle]) { $('interestCycleMonths').value = map[cycle]; $('interestCycleMonths').readOnly = true } else { $('interestCycleMonths').readOnly = false; if (cycle === 'Custom' && Number($('interestCycleMonths').value || 0) < 1) $('interestCycleMonths').value = 1 } toggleClosureMode(); due(); updateInterestPreview() }
            function due() {
                const d = $('pawnDate').value;
                if (!d) { return; }
                if (isAtClosure()) { toggleClosureMode(); updateInterestPreview(); return; }
                const t = Number($('tenureMonths').value || 0); if (t <= 0) return;
                const x = new Date(d + 'T00:00:00'); x.setMonth(x.getMonth() + t); $('dueDate').value = fmtDate(x);
                const m = Math.max(1, Number($('interestCycleMonths').value || 1));
                const firstEnd = new Date(d + 'T00:00:00'); firstEnd.setMonth(firstEnd.getMonth() + m);
                $('firstInterestPaidUptoDisplay').value = fmtDate(firstEnd);
                const collected = $('collectFirstInterest').checked;
                const next = new Date(d + 'T00:00:00'); next.setMonth(next.getMonth() + (collected ? m * 2 : m));
                $('lastInterestPaidUpto').value = collected ? fmtDate(firstEnd) : d;
                $('nextInterestDueDate').value = fmtDate(next); $('nextInterestDueDisplay').value = fmtDate(next);
                const grace = Math.max(0, Number($('graceDays').value || 0)); const ax = new Date($('dueDate').value + 'T00:00:00'); ax.setDate(ax.getDate() + grace);
                $('auctionEligibleDate').value = fmtDate(ax); $('auctionEligibleDateDisplay').value = fmtDate(ax); updateInterestPreview();
            }
            function proofImageUrl(c) {
                return c?.id_proof_image || c?.id_proof_image_url || c?.proof_image || c?.proof_image_url || '';
            }

            function setProofPreview(url) {
                const img = $('idProofPreview');
                const empty = $('idProofPreviewEmpty');
                if (url && !String(url).toLowerCase().endsWith('.pdf')) {
                    img.src = url;
                    img.style.display = 'block';
                    empty.style.display = 'none';
                } else {
                    img.removeAttribute('src');
                    img.style.display = 'none';
                    empty.style.display = 'block';
                    empty.textContent = url ? 'Existing proof is a PDF file' : 'No proof image selected';
                }
            }

            function loadCustomer(id) {
                const c = data.customers.find(x => String(x.id) === String(id));
                $('customerName').value = c?.customer_name || ''; $('customerCode').value = c?.customer_code || '';
                $('customerMobile').value = c?.mobile || '';
                $('customerEmail').value = c?.email || '';
                $('customerAddress').value = [c?.address_line1, c?.address_line2, c?.city, c?.state, c?.pincode].filter(Boolean).join(', ');
                $('idProofType').value = c?.id_proof_type || '';
                $('idProofNumber').value = c?.id_proof_number || '';

                const existingProof = proofImageUrl(c);
                $('existingIdProofImage').value = existingProof; $('customerIdProofExisting').value = existingProof;
                $('idProofImage').value = '';
                setProofPreview(existingProof);

                const pawnActive = Number(c?.pawn_service_active ?? c?.has_pawn_service ?? 0) === 1;
                $('pawnServiceStatus').textContent = pawnActive ? 'Enabled' : 'Not Enabled';
                $('pawnServiceStatus').className = pawnActive ? 'kyc-ok' : 'kyc-bad';

                const kyc = Number(c?.kyc_verified || 0) === 1;
                $('kycStatus').textContent = kyc ? 'Verified' : 'Not Verified';
                $('kycStatus').className = kyc ? 'kyc-ok' : 'kyc-bad';
                $('riskCategory').textContent = c?.risk_category || '—';
                $('pawnCreditLimit').textContent = money(c?.pawn_credit_limit ?? c?.credit_limit ?? 0);
            }

            function initCustomerSelect2() {
                if (!window.jQuery || !jQuery.fn || !jQuery.fn.select2) return;
                const select = jQuery('#customerSelect');
                if (select.hasClass('select2-hidden-accessible')) {
                    select.select2('destroy');
                }
                select.select2({
                    width: '100%',
                    placeholder: 'Search by name, customer code or mobile',
                    allowClear: true,
                    matcher: function(params, option) {
                        const term = String(params.term || '').trim().toLowerCase();
                        if (!term) return option;
                        const text = String(option.text || '').toLowerCase();
                        const search = String(jQuery(option.element).data('search') || '').toLowerCase();
                        return (text.includes(term) || search.includes(term)) ? option : null;
                    }
                }).on('change', function() {
                    loadCustomer(this.value);
                });
            }

            async function init() { try { data = await req({ action: 'options' }); $('pawnNo').value = data.next_pawn_no; $('pawnNoHidden').value = data.next_pawn_no; $('categorySelect').innerHTML = '<option value="">Select category</option>' + data.categories.map(c => `<option value="${c.id}" data-interest="${c.default_interest_percent || 0}" data-metal="${esc(c.metal_type || '')}" data-purity="${esc(c.purity_standard || '')}" data-maxloan="${Number(c.max_loan_percent || 100)}">${esc(c.category_name)}</option>`).join(''); $('customerSelect').innerHTML = '<option value=""></option>' + data.customers.map(c => {
                    const searchText = [c.customer_name, c.customer_code, c.mobile, c.alternate_mobile].filter(Boolean).join(' ');
                    return `<option value="${c.id}" data-search="${esc(searchText)}">${esc(c.customer_name)} · ${esc(c.customer_code)} · ${esc(c.mobile || '')}</option>`;
                }).join('');
                initCustomerSelect2(); $('primaryMetal').innerHTML = '<option value="">Select metal</option>' + metalOpts(); $('paymentMethod').innerHTML = '<option value="">Select Method</option>' + data.payment_methods.map(m => `<option value="${m.id}">${esc(m.method_name)}</option>`).join(''); $('firstInterestPaymentMethod').innerHTML = '<option value="">Select Method</option>' + data.payment_methods.map(m => `<option value="${m.id}">${esc(m.method_name)}</option>`).join(''); $('itemsWrap').innerHTML = itemRow(); updateCycleFields(); document.querySelectorAll('.item-box').forEach(updateRate); totals(); updateDisbursement() } catch (e) { note('bad', e.message) } }
            $('addItemBtn').onclick = () => { $('itemsWrap').insertAdjacentHTML('beforeend', itemRow()); const box = $('itemsWrap').lastElementChild; if (box) updateRate(box); totals(); }; document.addEventListener('input', e => {
                if (e.target.classList.contains('calc')) totals();
                if (e.target.classList.contains('item-purity')) updateRate(e.target.closest('.item-box'));
            });
            document.addEventListener('change', e => {
                if (e.target.classList.contains('item-metal') || e.target.classList.contains('item-purity')) {
                    updateRate(e.target.closest('.item-box'));
                }
            }); document.addEventListener('click', e => {
                const rm = e.target.closest('.remove-item');
                if (rm) {
                    if (document.querySelectorAll('.item-box').length === 1) return note('bad', 'At least one item is required.');
                    rm.closest('.item-box').remove();
                    totals();
                }
            }); $('pawnDate').onchange = due; $('tenureMonths').oninput = due; $('interestCycle').onchange = updateCycleFields; $('interestCycleMonths').oninput = due; $('graceDays').oninput = due; $('collectFirstInterest').onchange = () => { $('firstInterestFields').style.display = $('collectFirstInterest').checked ? 'flex' : 'none'; due(); }; $('firstInterestAmount').oninput = e => e.target.dataset.manual = '1'; $('interestPercent').oninput = updateInterestPreview; $('interestMethod').onchange = updateInterestPreview; document.querySelector('[name="interest_period"]').onchange = updateInterestPreview; $('interestRounding').onchange = updateInterestPreview; $('principalAmount').oninput = e => { e.target.dataset.manual = '1'; updateDisbursement(); updateInterestPreview(); }; $('documentCharge').oninput = updateDisbursement; $('otherCharge').oninput = updateDisbursement; $('primaryMetal').onchange = e => {
                document.querySelectorAll('.item-metal').forEach(s => {
                    s.value = e.target.value;
                    updateRate(s.closest('.item-box'));
                });
                totals();
            }; $('categorySelect').onchange = e => {
                const o = e.target.options[e.target.selectedIndex];
                currentMaxLoanPercent = Number(o?.dataset.maxloan || 100);
                $('interestPercent').value = o?.dataset.interest || '';

                const m = data.metals.find(x =>
                    String(x.metal_name || '').trim().toLowerCase() ===
                    String(o?.dataset.metal || '').trim().toLowerCase()
                );

                if (m) {
                    $('primaryMetal').value = m.id;
                    document.querySelectorAll('.item-metal').forEach(s => {
                        s.value = m.id;
                    });
                }

                if (o?.dataset.purity) {
                    document.querySelectorAll('.item-purity').forEach(i => {
                        i.value = o.dataset.purity;
                    });
                }

                document.querySelectorAll('.item-box').forEach(updateRate);
                updateInterestPreview();
            };
            $('idProofImage').addEventListener('change', function() {
                const file = this.files && this.files[0];
                if (!file) {
                    setProofPreview($('existingIdProofImage').value);
                    return;
                }
                if (file.type === 'application/pdf') {
                    setProofPreview('proof.pdf');
                    return;
                }
                const reader = new FileReader();
                reader.onload = ev => setProofPreview(ev.target.result);
                reader.readAsDataURL(file);
            });

            $('pawnForm').onsubmit = async e => { e.preventDefault(); totals();
                if (!e.target.reportValidity()) return;
                if (!$('customerSelect').value) return note('bad', 'Select a customer.');
                if (!$('idProofType').value) return note('bad', 'Select the ID proof type.');
                if (!$('idProofNumber').value.trim()) return note('bad', 'Enter the ID proof number.');
                if (!$('existingIdProofImage').value && !($('idProofImage').files && $('idProofImage').files.length)) {
                    return note('bad', 'Upload the customer ID proof image or PDF.');
                } if (Number($('totalNetInput').value || 0) <= 0) return note('bad', 'Total net weight must be greater than zero.'); if (Number($('principalAmount').value || 0) <= 0) return note('bad', 'Principal amount must be greater than zero.'); if (updateDisbursement() <= 0) return note('bad', 'Amount given to customer must be greater than zero after deducting document and other charges.'); if (!$('paymentMethod').value) return note('bad', 'Select the disbursement payment method.'); if (!isAtClosure() && Number($('tenureMonths').value || 0) <= 0) return note('bad', 'Tenure months must be greater than zero.'); if ($('collectFirstInterest').checked) { if (Number($('firstInterestAmount').value || 0) <= 0) return note('bad', 'Enter the first interest amount.'); if (!$('firstInterestPaymentMethod').value) return note('bad', 'Select the first interest payment method.'); } const b = $('saveBtn'), old = b.innerHTML; b.disabled = true; b.innerHTML = 'Saving...'; try {
                    updateDisbursement();
                    document.querySelectorAll('.item-box').forEach(updateRate);
                    totals();

                    const formData = new FormData(e.target);
                    formData.set('document_charge', Number($('documentCharge').value || 0).toFixed(2));
                    formData.set('other_charge', Number($('otherCharge').value || 0).toFixed(2));
                    formData.set('principal_amount', Number($('principalAmount').value || 0).toFixed(2));
                    formData.set('disbursement_amount', Number($('disbursementAmount').value || 0).toFixed(2));
                    formData.set('total_gross_weight', Number($('totalGrossInput').value || 0).toFixed(3));
                    formData.set('total_stone_weight', Number($('totalStoneInput').value || 0).toFixed(3));
                    formData.set('total_net_weight', Number($('totalNetInput').value || 0).toFixed(3));
                    formData.set('total_estimated_value', Number($('totalEstimatedInput').value || 0).toFixed(2));

                    const r = await fetch(api, { method: 'POST', body: formData, credentials: 'same-origin', headers: { Accept: 'application/json' } }), raw = await r.text(); let j; try { j = JSON.parse(raw) } catch (x) { throw new Error(raw.slice(0, 300)) } if (!r.ok || !j.success) throw new Error(j.message || 'Unable to save'); note('ok', j.message); setTimeout(() => location.href = 'pawn-view.php?id=' + j.pawn_id, 600) } catch (err) { note('bad', err.message) } finally { b.disabled = false; b.innerHTML = old } }; init()
        })();
    </script>
</body>

</html>