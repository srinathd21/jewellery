<?php
require __DIR__ . '/_common.php';
$pawnEntryId = max(0, (int) ($_GET['id'] ?? 0));
if ($pawnEntryId <= 0) {
    header('Location: pawn-manage.php');
    exit;
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($businessName) ?> - Edit Pawn</title>
    <?php include('includes/links.php');
    require __DIR__ . '/_style.php'; ?>
    <style>
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 9px
        }

        .summary-box {
            padding: 11px;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: var(--card-bg)
        }

        .summary-box span {
            display: block;
            font-size: 9px;
            color: var(--muted);
            text-transform: uppercase
        }

        .summary-box strong {
            display: block;
            margin-top: 4px;
            font-size: 13px
        }

        .money-strong {
            font-size: 18px !important;
            color: var(--primary-dark)
        }

        .item-table,
        .history-table {
            font-size: 10px;
            margin: 0
        }

        .item-table th,
        .history-table th {
            font-size: 9px;
            text-transform: uppercase;
            color: var(--muted);
            white-space: nowrap
        }

        .closure-note {
            padding: 10px;
            border: 1px solid #f2d7a0;
            background: #fff6e5;
            color: #a56700;
            border-radius: 10px;
            font-size: 10px
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
            font-weight: 700
        }

        .theme-toast-success {
            background: #168449
        }

        .theme-toast-error {
            background: #c0392b
        }

        .loading-box {
            padding: 60px;
            text-align: center;
            color: var(--muted)
        }

        .interest-preview {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 8px;
            padding: 12px;
            border: 1px solid #f2d7a0;
            background: #fff8eb;
            border-radius: 10px
        }

        .interest-preview-box span {
            display: block;
            font-size: 9px;
            color: #8a650d;
            text-transform: uppercase
        }

        .interest-preview-box strong {
            display: block;
            margin-top: 3px;
            font-size: 15px;
            color: #9a5b00
        }

        .interest-preview-note {
            grid-column: 1/-1;
            font-size: 9px;
            color: #8a650d
        }

        @media(max-width:767px) {
            .interest-preview {
                grid-template-columns: repeat(2, 1fr)
            }
        }

        @media(max-width:991px) {
            .summary-grid {
                grid-template-columns: repeat(2, 1fr)
            }
        }

        @media(max-width:575px) {
            .summary-grid {
                grid-template-columns: 1fr
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
                        <div class="page-title" id="pageTitle">Edit Pawn</div>
                        <div class="small text-muted" id="pageSub">Loading pawn details...</div>
                    </div>
                    <div class="d-flex gap-2 flex-wrap"><a href="pawn-manage.php" class="btn-soft">Back</a><a
                            id="viewLink" href="#" class="btn-soft">View</a><a id="interestLink" href="#"
                            class="btn-soft">Collect Interest</a><a id="paymentLink" href="#"
                            class="btn-theme">Payment</a></div>
                </div>
            </div>
            <div id="loading" class="page-card loading-box">Loading pawn entry...</div>
            <form id="editForm" class="d-none">
                <input type="hidden" name="action" value="update"><input type="hidden" name="id"
                    value="<?= $pawnEntryId ?>">
                <div class="page-card mb-3">
                    <div class="page-head">
                        <div class="section-title">Saved Pawn Summary</div>
                    </div>
                    <div class="card-body-x">
                        <div class="summary-grid" id="summaryGrid"></div>
                    </div>
                </div>
                <div class="row g-3">
                    <div class="col-xl-8">
                        <div class="page-card mb-3">
                            <div class="page-head">
                                <div class="section-title">Pawn Information</div>
                            </div>
                            <div class="card-body-x">
                                <div class="row g-2">
                                    <div class="col-md-4"><label class="small fw-bold">Pawn No</label><input id="pawnNo"
                                            class="form-control" readonly></div>
                                    <div class="col-md-4"><label class="small fw-bold">Pawn Date</label><input
                                            type="date" name="pawn_date" id="pawnDate" class="form-control" required>
                                    </div>
                                    <div class="col-md-4"><label class="small fw-bold">Status</label><select
                                            name="status" id="status" class="form-select">
                                            <option>Draft</option>
                                            <option>Active</option>
                                            <option>Partially Paid</option>
                                            <option>Closed</option>
                                            <option>Auctioned</option>
                                            <option>Cancelled</option>
                                        </select></div>
                                    <div class="col-md-4"><label class="small fw-bold">Customer</label><input
                                            id="customerName" class="form-control" readonly></div>
                                    <div class="col-md-4"><label class="small fw-bold">Customer Code</label><input
                                            id="customerCode" class="form-control" readonly></div>
                                    <div class="col-md-4"><label class="small fw-bold">Mobile</label><input id="mobile"
                                            class="form-control" readonly></div>
                                    <div class="col-md-4"><label class="small fw-bold">Category</label><input
                                            id="category" class="form-control" readonly></div>
                                    <div class="col-md-4"><label class="small fw-bold">Branch</label><input id="branch"
                                            class="form-control" readonly></div>
                                    <div class="col-md-4"><label class="small fw-bold">Loan Type</label><input
                                            name="loan_type" id="loanType" class="form-control"></div>
                                    <div class="col-md-4"><label class="small fw-bold">ID Proof Type</label><input
                                            name="id_proof_type" id="idProofType" class="form-control"></div>
                                    <div class="col-md-4"><label class="small fw-bold">ID Proof Number</label><input
                                            name="id_proof_number" id="idProofNumber" class="form-control"></div>
                                    <div class="col-12"><label class="small fw-bold">Customer Address</label><textarea
                                            id="address" class="form-control" rows="2" readonly></textarea></div>
                                </div>
                            </div>
                        </div>
                        <div class="page-card mb-3">
                            <div class="page-head">
                                <div>
                                    <div class="section-title">Interest Configuration</div>
                                    <div class="small text-muted">Exact values saved for this pawn.</div>
                                </div>
                            </div>
                            <div class="card-body-x">
                                <div class="row g-2">
                                    <div class="col-md-4"><label class="small fw-bold">Interest %</label><input
                                            type="number" step="0.001" min="0" name="interest_percent"
                                            id="interestPercent" class="form-control"></div>
                                    <div class="col-md-4"><label class="small fw-bold">Interest Period</label><select
                                            name="interest_period" id="interestPeriod" class="form-select">
                                            <option>Daily</option>
                                            <option>Monthly</option>
                                            <option>Yearly</option>
                                        </select></div>
                                    <div class="col-md-4"><label class="small fw-bold">Interest Method</label><select
                                            name="interest_method" id="interestMethod" class="form-select">
                                            <option>Simple</option>
                                            <option>Reducing Balance</option>
                                            <option>Flat</option>
                                        </select></div>
                                    <div class="col-md-4"><label class="small fw-bold">Collection Cycle</label><select
                                            name="interest_collection_cycle" id="interestCycle" class="form-select">
                                            <option>Monthly</option>
                                            <option>Quarterly</option>
                                            <option>Half-Yearly</option>
                                            <option>Yearly</option>
                                            <option>At Closure</option>
                                            <option>Custom</option>
                                        </select></div>
                                    <div class="col-md-4 cycle-field"><label class="small fw-bold">Cycle
                                            Months</label><input type="number" min="1" name="interest_cycle_months"
                                            id="cycleMonths" class="form-control"></div>
                                    <div class="col-md-4"><label class="small fw-bold">Minimum Interest
                                            Days</label><input type="number" min="0" name="minimum_interest_days"
                                            id="minimumDays" class="form-control"></div>
                                    <div class="col-md-4"><label class="small fw-bold">Interest Rounding</label><select
                                            name="interest_rounding_method" id="roundingMethod" class="form-select">
                                            <option>None</option>
                                            <option>Nearest Rupee</option>
                                            <option>Ceil Rupee</option>
                                            <option>Floor Rupee</option>
                                        </select></div>
                                    <div class="col-md-4"><label class="small fw-bold">Last Interest Paid
                                            Upto</label><input type="date" name="last_interest_paid_upto" id="lastPaid"
                                            class="form-control"></div>
                                    <div class="col-md-4 tenure-field"><label class="small fw-bold">Next Interest
                                            Due</label><input type="date" name="next_interest_due_date" id="nextDue"
                                            class="form-control"></div>
                                    <div class="col-md-4 tenure-field"><label class="small fw-bold">Tenure
                                            Months</label><input type="number" min="0" name="tenure_months"
                                            id="tenureMonths" class="form-control"></div>
                                    <div class="col-md-4 tenure-field"><label class="small fw-bold">Due
                                            Date</label><input type="date" name="due_date" id="dueDate"
                                            class="form-control"></div>
                                    <div class="col-md-4 tenure-field"><label class="small fw-bold">Grace
                                            Days</label><input type="number" min="0" name="grace_days" id="graceDays"
                                            class="form-control"></div>
                                    <div class="col-md-4 tenure-field"><label class="small fw-bold">Overdue
                                            Type</label><select name="overdue_charge_type" id="overdueType"
                                            class="form-select">
                                            <option>None</option>
                                            <option>Fixed</option>
                                            <option>Daily Fixed</option>
                                            <option>Monthly Fixed</option>
                                            <option>Percentage</option>
                                        </select></div>
                                    <div class="col-md-4 tenure-field"><label class="small fw-bold">Overdue
                                            Value</label><input type="number" step="0.0001" min="0"
                                            name="overdue_charge_value" id="overdueValue" class="form-control"></div>
                                    <div class="col-md-4 tenure-field"><label class="small fw-bold">Maximum
                                            Overdue</label><input type="number" step="0.01" min="0"
                                            name="maximum_overdue_charge" id="maximumOverdue" class="form-control">
                                    </div>
                                    <div class="col-md-4 tenure-field"><label class="small fw-bold">Auction Eligible
                                            Date</label><input type="date" name="auction_eligible_date" id="auctionDate"
                                            class="form-control"></div>
                                    <div class="col-12">
                                        <div class="interest-preview">
                                            <div class="interest-preview-box"><span>Calculation Principal</span><strong
                                                    id="calcPrincipal">₹0.00</strong></div>
                                            <div class="interest-preview-box"><span>Entered Interest Rate</span><strong
                                                    id="calcRate">0.000%</strong></div>
                                            <div class="interest-preview-box"><span>Interest Per Period</span><strong
                                                    id="calcPeriodInterest">₹0.00</strong></div>
                                            <div class="interest-preview-box"><span>Collection Cycle
                                                    Interest</span><strong id="calcCycleInterest">₹0.00</strong></div>
                                            <div class="interest-preview-note" id="calcInterestNote">Enter an interest
                                                percentage to calculate the interest amount.</div>
                                        </div>
                                    </div>
                                    <div class="col-12 d-none" id="closureNote">
                                        <div class="closure-note"><i class="fa-solid fa-circle-info me-1"></i>At Closure
                                            mode does not use tenure, due date, grace, overdue, or auction scheduling.
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="page-card mb-3">
                            <div class="page-head">
                                <div class="section-title">Pawn Items</div>
                            </div>
                            <div class="table-responsive">
                                <table class="table item-table align-middle">
                                    <thead>
                                        <tr>
                                            <th>Description</th>
                                            <th>Metal</th>
                                            <th>Qty</th>
                                            <th>Purity</th>
                                            <th>Gross</th>
                                            <th>Stone/Less</th>
                                            <th>Net</th>
                                            <th>Rate/g</th>
                                            <th>Estimated</th>
                                        </tr>
                                    </thead>
                                    <tbody id="itemsBody"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-4">
                        <div class="page-card mb-3">
                            <div class="page-head">
                                <div class="section-title">Charges & Disbursement</div>
                            </div>
                            <div class="card-body-x">
                                <div class="row g-2">
                                    <div class="col-6"><label class="small fw-bold">Document Charge</label><input
                                            type="number" step="0.01" min="0" name="document_charge" id="documentCharge"
                                            class="form-control"></div>
                                    <div class="col-6"><label class="small fw-bold">Other Charge</label><input
                                            type="number" step="0.01" min="0" name="other_charge" id="otherCharge"
                                            class="form-control"></div>
                                    <div class="col-12"><label class="small fw-bold">Disbursement Method</label><select
                                            name="disbursement_payment_method_id" id="paymentMethod"
                                            class="form-select">
                                            <option value="">Select method</option>
                                        </select></div>
                                    <div class="col-12"><label class="small fw-bold">Payment Reference</label><input
                                            name="payment_reference" id="paymentReference" class="form-control"></div>
                                    <div class="col-12"><label class="small fw-bold">Remarks</label><textarea
                                            name="remarks" id="remarks" class="form-control" rows="6"></textarea></div>
                                </div>
                            </div>
                        </div>
                        <div class="page-card sticky-top" style="top:82px">
                            <div class="card-body-x"><button type="submit" class="btn-theme w-100" id="saveBtn"><i
                                        class="fa-solid fa-floppy-disk"></i> Update Pawn</button><a
                                    href="pawn-manage.php" class="btn btn-soft w-100 mt-2">Cancel</a></div>
                        </div>
                    </div>
                </div>
            </form>
            <div id="historyWrap" class="d-none">
                <div class="page-card mb-3">
                    <div class="page-head">
                        <div class="section-title">Interest Collection History</div>
                    </div>
                    <div class="table-responsive">
                        <table class="table history-table">
                            <thead>
                                <tr>
                                    <th>Receipt</th>
                                    <th>Date</th>
                                    <th>Period</th>
                                    <th>Interest</th>
                                    <th>Penalty</th>
                                    <th>Other</th>
                                    <th>Total</th>
                                    <th>Method</th>
                                </tr>
                            </thead>
                            <tbody id="interestBody"></tbody>
                        </table>
                    </div>
                </div>
                <div class="page-card mb-3">
                    <div class="page-head">
                        <div class="section-title">Principal / Settlement History</div>
                    </div>
                    <div class="table-responsive">
                        <table class="table history-table">
                            <thead>
                                <tr>
                                    <th>Receipt</th>
                                    <th>Date</th>
                                    <th>Type</th>
                                    <th>Principal</th>
                                    <th>Interest</th>
                                    <th>Penalty</th>
                                    <th>Other</th>
                                    <th>Total</th>
                                    <th>Method</th>
                                </tr>
                            </thead>
                            <tbody id="paymentBody"></tbody>
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
        (() => { 'use strict'; const api = 'api/pawn-edit.php', csrf = <?= json_encode($csrfToken) ?>, pawnId = <?= $pawnEntryId ?>, $ = id => document.getElementById(id); function esc(v) { return String(v ?? '').replace(/[&<>'"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[c])) } function money(v) { return Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) } function note(t, m) { const x = document.createElement('div'); x.className = 'theme-toast theme-toast-' + (t === 'ok' ? 'success' : 'error'); x.textContent = m; document.body.appendChild(x); setTimeout(() => x.remove(), 3500) } async function request(data) { const f = new FormData(); Object.entries(data).forEach(([k, v]) => f.append(k, v ?? '')); f.append('csrf_token', csrf); const r = await fetch(api, { method: 'POST', body: f, credentials: 'same-origin', headers: { Accept: 'application/json' } }); const raw = await r.text(); let j; try { j = JSON.parse(raw) } catch (e) { throw new Error(raw.replace(/<[^>]*>/g, ' ').slice(0, 300)) } if (!r.ok || !j.success) throw new Error(j.message || 'Request failed'); return j } let currentPawn = null; function set(id, v) { $(id).value = v ?? '' } function applyRounding(value) { const method = $('roundingMethod').value; switch (method) { case 'Nearest Rupee': return Math.round(value); case 'Ceil Rupee': return Math.ceil(value); case 'Floor Rupee': return Math.floor(value); default: return Math.round(value * 100) / 100 } } function calculateInterest() { if (!currentPawn) return; const rate = Math.max(0, Number($('interestPercent').value || 0)); const method = $('interestMethod').value; const period = $('interestPeriod').value; const cycle = $('interestCycle').value; const cycleMonths = Math.max(1, Number($('cycleMonths').value || 1)); const originalPrincipal = Math.max(0, Number(currentPawn.principal_amount || 0)); const balancePrincipal = Math.max(0, Number(currentPawn.balance_principal || originalPrincipal)); const calculationPrincipal = method === 'Flat' ? originalPrincipal : balancePrincipal; let periodInterest = calculationPrincipal * (rate / 100); periodInterest = applyRounding(periodInterest); let multiplier = 1; let cycleLabel = period; switch (cycle) { case 'Quarterly': multiplier = period === 'Monthly' ? 3 : 1; cycleLabel = 'Quarterly'; break; case 'Half-Yearly': multiplier = period === 'Monthly' ? 6 : period === 'Daily' ? 182.5 : 0.5; cycleLabel = 'Half-Yearly'; break; case 'Yearly': multiplier = period === 'Monthly' ? 12 : period === 'Daily' ? 365 : 1; cycleLabel = 'Yearly'; break; case 'Custom': multiplier = period === 'Monthly' ? cycleMonths : 1; cycleLabel = cycleMonths + ' Month Cycle'; break; case 'At Closure': multiplier = 1; cycleLabel = 'At Closure (' + period + ' estimate)'; break; default: multiplier = 1; cycleLabel = period; }const cycleInterest = applyRounding(periodInterest * multiplier); $('calcPrincipal').textContent = '₹' + money(calculationPrincipal); $('calcRate').textContent = rate.toFixed(3) + '%'; $('calcPeriodInterest').textContent = '₹' + money(periodInterest); $('calcCycleInterest').textContent = '₹' + money(cycleInterest); const baseText = method === 'Flat' ? 'original principal' : 'outstanding principal'; $('calcInterestNote').textContent = rate > 0 ? ('Calculated on ' + baseText + '. ₹' + money(calculationPrincipal) + ' × ' + rate.toFixed(3) + '% = ₹' + money(periodInterest) + ' per ' + period.toLowerCase() + '. ' + cycleLabel + ' amount: ₹' + money(cycleInterest) + '.') : 'Enter an interest percentage to calculate the interest amount.' } function sync() { const close = $('interestCycle').value === 'At Closure'; document.querySelectorAll('.tenure-field,.cycle-field').forEach(x => x.classList.toggle('d-none', close)); $('closureNote').classList.toggle('d-none', !close) } function rowEmpty(cols, msg) { return `<tr><td colspan="${cols}" class="text-center text-muted p-4">${msg}</td></tr>` } async function load() { try { const j = await request({ action: 'load', id: pawnId }), p = j.pawn; currentPawn = p; document.title = 'Edit ' + p.pawn_no; $('pageTitle').textContent = 'Edit Pawn - ' + p.pawn_no; $('pageSub').textContent = [p.customer_name, p.mobile, p.branch_name].filter(Boolean).join(' · '); $('viewLink').href = 'pawn-view.php?id=' + pawnId; $('interestLink').href = 'pawn-interest.php?pawn_id=' + pawnId; $('paymentLink').href = 'pawn-payment.php?pawn_id=' + pawnId; set('pawnNo', p.pawn_no); set('pawnDate', p.pawn_date); set('status', p.status); set('customerName', p.customer_name); set('customerCode', p.customer_code); set('mobile', p.mobile); set('category', p.category_name); set('branch', p.branch_name); set('loanType', p.loan_type); set('idProofType', p.id_proof_type); set('idProofNumber', p.id_proof_number); set('address', p.customer_address); set('interestPercent', p.interest_percent); set('interestPeriod', p.interest_period || 'Monthly'); set('interestMethod', p.interest_method || 'Simple'); set('interestCycle', p.interest_collection_cycle || 'Monthly'); set('cycleMonths', p.interest_cycle_months || 1); set('minimumDays', p.minimum_interest_days || 0); set('roundingMethod', p.interest_rounding_method || 'Nearest Rupee'); set('lastPaid', p.last_interest_paid_upto); set('nextDue', p.next_interest_due_date); set('tenureMonths', p.tenure_months || 0); set('dueDate', p.due_date); set('graceDays', p.grace_days || 0); set('overdueType', p.overdue_charge_type || 'None'); set('overdueValue', p.overdue_charge_value || 0); set('maximumOverdue', p.maximum_overdue_charge); set('auctionDate', p.auction_eligible_date); set('documentCharge', p.document_charge || 0); set('otherCharge', p.other_charge || 0); set('paymentReference', p.payment_reference); set('remarks', p.remarks); $('paymentMethod').innerHTML = '<option value="">Select method</option>' + (j.payment_methods || []).map(m => `<option value="${m.id}">${esc(m.method_name)}</option>`).join(''); set('paymentMethod', p.disbursement_payment_method_id); $('summaryGrid').innerHTML = [['Principal Amount', '₹' + money(p.principal_amount), 'money-strong'], ['Principal Paid', '₹' + money(p.total_principal_paid), ''], ['Principal Balance', '₹' + money(p.balance_principal), 'money-strong'], ['Interest Collected', '₹' + money(p.total_interest_collected), ''], ['Estimated Value', '₹' + money(p.total_estimated_value), ''], ['Gross Weight', Number(p.total_gross_weight || 0).toFixed(3) + ' g', ''], ['Stone/Less', Number(p.total_stone_weight || 0).toFixed(3) + ' g', ''], ['Net Weight', Number(p.total_net_weight || 0).toFixed(3) + ' g', '']].map(x => `<div class="summary-box"><span>${x[0]}</span><strong class="${x[2]}">${x[1]}</strong></div>`).join(''); $('itemsBody').innerHTML = (j.items || []).length ? (j.items || []).map(i => `<tr><td>${esc(i.item_description)}</td><td>${esc(i.metal_name)}</td><td>${esc(i.quantity)}</td><td>${esc(i.purity)}</td><td>${Number(i.gross_weight || 0).toFixed(3)} g</td><td>${Number(i.stone_weight || 0).toFixed(3)} g</td><td>${Number(i.net_weight || 0).toFixed(3)} g</td><td>₹${money(i.rate_per_gram)}</td><td>₹${money(i.estimated_value)}</td></tr>`).join('') : rowEmpty(9, 'No pawn items found.'); $('interestBody').innerHTML = (j.interest_collections || []).length ? j.interest_collections.map(r => `<tr><td>${esc(r.receipt_no)}</td><td>${esc(r.collection_date)}</td><td>${esc(r.from_date || '')} - ${esc(r.to_date || '')}</td><td>₹${money(r.interest_amount)}</td><td>₹${money(r.penalty_amount)}</td><td>₹${money(r.other_charges)}</td><td>₹${money(r.total_amount)}</td><td>${esc(r.method_name)}</td></tr>`).join('') : rowEmpty(8, 'No interest collections found.'); $('paymentBody').innerHTML = (j.payments || []).length ? j.payments.map(r => `<tr><td>${esc(r.receipt_no)}</td><td>${esc(r.payment_date)}</td><td>${esc(r.payment_type)}</td><td>₹${money(r.principal_amount)}</td><td>₹${money(r.interest_amount)}</td><td>₹${money(r.penalty_amount)}</td><td>₹${money(r.other_charges)}</td><td>₹${money(r.total_amount)}</td><td>${esc(r.method_name)}</td></tr>`).join('') : rowEmpty(9, 'No pawn payments found.'); $('loading').classList.add('d-none'); $('editForm').classList.remove('d-none'); $('historyWrap').classList.remove('d-none'); sync(); calculateInterest() } catch (e) { $('loading').textContent = e.message; note('bad', e.message) } } $('interestCycle').addEventListener('change', () => { sync(); calculateInterest() });['interestPercent', 'interestPeriod', 'interestMethod', 'cycleMonths', 'roundingMethod'].forEach(id => $(id).addEventListener('input', calculateInterest)); $('editForm').addEventListener('submit', async e => { e.preventDefault(); const b = $('saveBtn'), old = b.innerHTML; b.disabled = true; b.innerHTML = 'Updating...'; try { const f = new FormData(e.target); f.append('csrf_token', csrf); const r = await fetch(api, { method: 'POST', body: f, credentials: 'same-origin', headers: { Accept: 'application/json' } }); const raw = await r.text(); let j; try { j = JSON.parse(raw) } catch (x) { throw new Error(raw.replace(/<[^>]*>/g, ' ').slice(0, 300)) } if (!r.ok || !j.success) throw new Error(j.message || 'Update failed'); note('ok', j.message); setTimeout(load, 400) } catch (err) { note('bad', err.message) } finally { b.disabled = false; b.innerHTML = old } }); load() })();
    </script>
</body>

</html>