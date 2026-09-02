<?php
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
require __DIR__ . '/_common.php';
$presetPawnId = max(0, (int) ($_GET['pawn_id'] ?? 0));
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($businessName) ?> - Pawn Interest & Payment</title>
    <?php include('includes/links.php');
    require __DIR__ . '/_style.php'; ?>
    <style>
        .op-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap
        }

        .op-tabs {
            display: flex;
            gap: 6px;
            padding: 4px;
            background: var(--page-bg, #f4f3f0);
            border: 1px solid var(--line);
            border-radius: 11px;
            width: max-content
        }

        .op-tab {
            border: 0;
            background: transparent;
            padding: 8px 14px;
            border-radius: 8px;
            font-size: 11px;
            font-weight: 700;
            color: var(--muted);
            cursor: pointer
        }

        .op-tab.active {
            background: var(--primary);
            color: #fff;
            box-shadow: 0 2px 7px rgba(0, 0, 0, .08)
        }

        .tab-panel {
            display: none
        }

        .tab-panel.active {
            display: block
        }

        .stat-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 10px;
            margin-bottom: 12px
        }

        .stat-card {
            min-height: 92px;
            padding: 14px 16px;
            border: 1px solid var(--line);
            border-radius: 16px;
            background: var(--card-bg);
            display: flex;
            align-items: center;
            gap: 13px;
            min-width: 0
        }

        .stat-icon {
            width: 54px;
            height: 54px;
            flex: 0 0 54px;
            border-radius: 13px;
            background: #fff3dc;
            display: grid;
            place-items: center;
            color: #cf7d00;
            font-size: 20px
        }

        .stat-copy {
            min-width: 0
        }

        .stat-label {
            font-size: 10px;
            color: #71809a;
            margin-bottom: 2px
        }

        .stat-value {
            font-size: 22px;
            font-weight: 800;
            line-height: 1.05;
            color: var(--text);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis
        }

        .stat-note {
            font-size: 9px;
            color: var(--muted);
            margin-top: 4px
        }

        .work-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.35fr) minmax(300px, .65fr);
            gap: 12px;
            align-items: start
        }

        .compact-head {
            padding: 11px 13px !important
        }

        .compact-body {
            padding: 12px 13px !important
        }

        .pawn-picker {
            display: grid;
            grid-template-columns: minmax(260px, 1fr) 170px;
            gap: 8px
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 7px;
            margin-top: 9px
        }

        .info-box {
            padding: 9px 10px;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: var(--card-bg);
            min-width: 0
        }

        .info-box span {
            display: block;
            font-size: 8px;
            text-transform: uppercase;
            color: var(--muted);
            margin-bottom: 2px
        }

        .info-box strong {
            font-size: 11px;
            display: block;
            overflow: hidden;
            text-overflow: ellipsis
        }

        .rate-alert {
            margin-top: 9px;
            padding: 9px 11px;
            border-radius: 10px;
            border: 1px solid #f0d9ae;
            background: #fff8ea;
            font-size: 10px;
            line-height: 1.45
        }

        .rate-alert.danger {
            border-color: #f2c8c3;
            background: #fff1ef;
            color: #9b2c22
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 8px
        }

        .form-grid .span2 {
            grid-column: span 2
        }

        .form-grid .span4 {
            grid-column: 1/-1
        }

        .field-label {
            font-size: 9px;
            font-weight: 700;
            color: var(--muted);
            margin-bottom: 4px;
            text-transform: uppercase
        }

        .readonly-money {
            background: var(--page-bg, #f7f6f3) !important;
            font-weight: 700
        }

        .summary-line {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            padding: 7px 0;
            border-bottom: 1px dashed var(--line);
            font-size: 10px
        }

        .summary-line:last-child {
            border-bottom: 0
        }

        .summary-line.total {
            font-size: 13px;
            font-weight: 800;
            color: var(--primary-dark)
        }

        .badge-soft {
            display: inline-flex;
            align-items: center;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 9px;
            font-weight: 800;
            background: #edf3ff;
            color: #355a9d
        }

        .badge-good {
            background: #eaf8f0;
            color: #168449
        }

        .badge-warn {
            background: #fff5df;
            color: #a56700
        }

        .badge-bad {
            background: #fdecec;
            color: #b42318
        }

        .closure-box {
            display: none;
            margin-top: 9px;
            padding: 10px;
            border: 1px solid #efd3a2;
            border-radius: 10px;
            background: #fff8e9
        }

        .closure-box.show {
            display: block
        }

        .toast-x {
            position: fixed;
            right: 18px;
            top: 78px;
            z-index: 20000;
            padding: 10px 13px;
            border-radius: 9px;
            color: #fff;
            font-size: 11px;
            font-weight: 700
        }

        .toast-x.ok {
            background: #168449
        }

        .toast-x.bad {
            background: #c0392b
        }

        @media(max-width:1100px) {
            .stat-grid {
                grid-template-columns: repeat(2, 1fr)
            }

            .work-grid {
                grid-template-columns: 1fr
            }

            .info-grid {
                grid-template-columns: repeat(2, 1fr)
            }
        }

        @media(max-width:767px) {
            .stat-grid {
                grid-template-columns: 1fr 1fr
            }

            .stat-card {
                padding: 11px;
                min-height: 78px
            }

            .stat-icon {
                width: 44px;
                height: 44px;
                flex-basis: 44px
            }

            .stat-value {
                font-size: 18px
            }

            .pawn-picker,
            .form-grid {
                grid-template-columns: 1fr 1fr
            }

            .form-grid .span2,
            .form-grid .span4 {
                grid-column: 1/-1
            }
        }

        @media(max-width:480px) {
            .stat-grid {
                grid-template-columns: 1fr
            }

            .pawn-picker,
            .form-grid,
            .info-grid {
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
                <div class="page-head compact-head op-head">
                    <div>
                        <div class="page-title">Pawn Interest Collection</div>
                        <div class="small text-muted">Collect scheduled interest and handle principal payment / pawn
                            closure from one page.</div>
                    </div>
                    <div class="d-flex gap-2"><a href="pawn-manage.php" class="btn-soft"><i
                                class="fa-solid fa-arrow-left"></i> Manage</a></div>
                </div>
            </div>

            <div class="stat-grid">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fa-solid fa-calendar-check"></i></div>
                    <div class="stat-copy">
                        <div class="stat-label">Interest Due</div>
                        <div class="stat-value" id="stDue">0</div>
                        <div class="stat-note">Schedules due today or earlier</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
                    <div class="stat-copy">
                        <div class="stat-label">Overdue</div>
                        <div class="stat-value" id="stOverdue">0</div>
                        <div class="stat-note">Past grace date</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fa-solid fa-arrow-trend-up"></i></div>
                    <div class="stat-copy">
                        <div class="stat-label">Escalated Pawns</div>
                        <div class="stat-value" id="stEscalated">0</div>
                        <div class="stat-note">Higher rate locked</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fa-solid fa-indian-rupee-sign"></i></div>
                    <div class="stat-copy">
                        <div class="stat-label">Interest Due Amount</div>
                        <div class="stat-value">₹<span id="stInterestOutstanding">0.00</span></div>
                        <div class="stat-note">Only amounts already due</div>
                    </div>
                </div>
            </div>

            <div class="page-card mb-3">
                <div class="card-body-x compact-body">
                    <div class="op-tabs">
                        <button type="button" class="op-tab active" data-tab="interest"><i
                                class="fa-solid fa-indian-rupee-sign me-1"></i>Interest Collection</button>
                        <button type="button" class="op-tab" data-tab="payment"><i
                                class="fa-solid fa-wallet me-1"></i>Payment / Closure</button>
                    </div>
                </div>
            </div>

            <div class="page-card mb-3">
                <div class="page-head compact-head">
                    <div class="section-title">Select Pawn</div>
                </div>
                <div class="card-body-x compact-body">
                    <div class="pawn-picker">
                        <div><label class="field-label">Active Pawn</label><select id="pawnSelect" class="form-select">
                                <option value="">Loading...</option>
                            </select></div>
                        <div><label class="field-label">As of Date</label><input type="date" id="asOfDate"
                                class="form-control" value="<?= date('Y-m-d') ?>"></div>
                    </div>
                    <div class="info-grid">
                        <div class="info-box"><span>Customer</span><strong id="infoCustomer">—</strong></div>
                        <div class="info-box"><span>Principal</span><strong>₹<span
                                    id="infoPrincipal">0.00</span></strong></div>
                        <div class="info-box"><span>Balance Principal</span><strong>₹<span
                                    id="infoBalance">0.00</span></strong></div>
                        <div class="info-box"><span>Current Rate</span><strong id="infoRate">—</strong></div>
                        <div class="info-box"><span>Interest Scheme</span><strong id="infoScheme">—</strong></div>
                        <div class="info-box"><span>Next Due</span><strong id="infoDue">—</strong></div>
                        <div class="info-box"><span>Grace Until</span><strong id="infoGrace">—</strong></div>
                        <div class="info-box"><span>Status</span><strong id="infoStatus">—</strong></div>
                    </div>
                    <div id="rateAlert" class="rate-alert d-none"></div>
                    <div id="nextScheduleAlert" class="rate-alert d-none"></div>
                </div>
            </div>

            <section id="panelInterest" class="tab-panel active">
                <div class="work-grid">
                    <div class="page-card">
                        <div class="page-head compact-head">
                            <div class="section-title">Collect Interest</div><button type="button" id="interestCalcBtn"
                                class="btn-soft btn-sm"><i class="fa-solid fa-calculator"></i> Calculate</button>
                        </div>
                        <div class="card-body-x compact-body">
                            <form id="interestForm">
                                <input type="hidden" name="pawn_id" id="interestPawnId">
                                <div class="form-grid">
                                    <div><label class="field-label">Collection Date</label><input type="date"
                                            name="collection_date" id="collectionDate" class="form-control"
                                            value="<?= date('Y-m-d') ?>" required></div>
                                    <div><label class="field-label">From</label><input type="date" id="interestFrom"
                                            class="form-control" readonly></div>
                                    <div><label class="field-label">To / Due</label><input type="date" id="interestTo"
                                            class="form-control" readonly></div>
                                    <div><label class="field-label">Due Schedule Rate</label><input type="number"
                                            min="0.001" max="100" step="0.001" name="interest_rate" id="quoteRate"
                                            class="form-control" required></div>
                                    <div><label class="field-label">Pending Interest Due</label><input
                                            id="interestAmount" class="form-control readonly-money" readonly></div>
                                    <div><label class="field-label">Penalty</label><input id="penaltyAmount"
                                            class="form-control readonly-money" readonly></div>
                                    <div><label class="field-label">Other Charges</label><input type="number" min="0"
                                            step="0.01" name="other_charges" id="interestOther" class="form-control"
                                            value="0"></div>
                                    <div><label class="field-label">Pay Now</label><input type="number" min="0.01"
                                            step="0.01" name="paid_amount" id="interestPaid" class="form-control"
                                            value="0" required></div>
                                    <div><label class="field-label">Payment Method</label><select
                                            name="payment_method_id" id="interestMethod" class="form-select" required>
                                            <option value="">Select Method</option>
                                        </select></div>
                                    <div><label class="field-label">Reference</label><input name="reference_no"
                                            class="form-control" placeholder="UPI / bank / cheque ref"></div>
                                    <div class="span2"><label class="field-label">Remarks</label><input name="remarks"
                                            class="form-control" placeholder="Optional remarks"></div>
                                </div>
                                <div class="mt-3 d-flex justify-content-end"><button type="submit" class="btn-theme"
                                        id="interestSaveBtn"><i class="fa-solid fa-check me-1"></i>Collect
                                        Interest</button></div>
                            </form>
                        </div>
                    </div>
                    <aside class="page-card">
                        <div class="page-head compact-head">
                            <div class="section-title">Interest Summary</div>
                        </div>
                        <div class="card-body-x compact-body">
                            <div class="summary-line"><span>Schedule</span><strong id="sumSchedule">—</strong></div>
                            <div class="summary-line"><span>Due Date</span><strong id="sumDue">—</strong></div>
                            <div class="summary-line"><span>Grace Until</span><strong id="sumGrace">—</strong></div>
                            <div class="summary-line"><span>Interest</span><strong>₹<span
                                        id="sumInterest">0.00</span></strong></div>
                            <div class="summary-line"><span>Penalty</span><strong>₹<span
                                        id="sumPenalty">0.00</span></strong></div>
                            <div class="summary-line"><span>Other</span><strong>₹<span
                                        id="sumOther">0.00</span></strong></div>
                            <div class="summary-line total"><span>Total Due</span><strong>₹<span
                                        id="sumInterestTotal">0.00</span></strong></div>
                            <div class="mt-2 small text-muted" id="interestRuleNote">Select a pawn and calculate.</div>
                        </div>
                    </aside>
                </div>
            </section>

            <section id="panelPayment" class="tab-panel">
                <div class="work-grid">
                    <div class="page-card">
                        <div class="page-head compact-head">
                            <div class="section-title">Principal Payment / Closure</div><button type="button"
                                id="paymentCalcBtn" class="btn-soft btn-sm"><i class="fa-solid fa-calculator"></i>
                                Refresh Due</button>
                        </div>
                        <div class="card-body-x compact-body">
                            <form id="paymentForm">
                                <input type="hidden" name="pawn_id" id="paymentPawnId">
                                <div class="form-grid">
                                    <div><label class="field-label">Payment Date</label><input type="date"
                                            name="payment_date" id="paymentDate" class="form-control"
                                            value="<?= date('Y-m-d') ?>" required></div>
                                    <div><label class="field-label">Principal Payment</label><input type="number"
                                            min="0" step="0.01" name="principal_amount" id="principalPayment"
                                            class="form-control" value="0"></div>
                                    <div><label class="field-label">Interest Still Due</label><input
                                            id="paymentInterestDue" class="form-control readonly-money" readonly></div>
                                    <div><label class="field-label">Balance After</label><input id="paymentBalanceAfter"
                                            class="form-control readonly-money" readonly></div>
                                    <div><label class="field-label">Payment Method</label><select
                                            name="payment_method_id" id="paymentMethod" class="form-select" required>
                                            <option value="">Select Method</option>
                                        </select></div>
                                    <div><label class="field-label">Reference</label><input name="reference_no"
                                            class="form-control" placeholder="Reference number"></div>
                                    <div class="span2"><label class="field-label">Remarks</label><input name="remarks"
                                            class="form-control" placeholder="Optional remarks"></div>
                                    <div class="span4"><label class="d-flex align-items-center gap-2"
                                            style="font-size:11px;font-weight:700"><input type="checkbox"
                                                name="is_closure" id="isClosure" value="1"> Full Closure /
                                            Settlement</label></div>
                                </div>
                                <div class="closure-box" id="closureBox">
                                    <div class="form-grid">
                                        <div class="span2"><label class="field-label">Released To</label><input
                                                name="released_to" id="releasedTo" class="form-control"
                                                placeholder="Customer / authorised person"></div>
                                        <div><label class="field-label">Relation</label><input
                                                name="released_to_relation" class="form-control"
                                                placeholder="Self / spouse / etc"></div>
                                        <div><label class="field-label">ID Proof Number</label><input
                                                name="identity_document_no" class="form-control"></div>
                                    </div>
                                    <div id="closureWarning" class="small mt-2"></div>
                                </div>
                                <div class="mt-3 d-flex justify-content-end"><button type="submit" class="btn-theme"
                                        id="paymentSaveBtn"><i class="fa-solid fa-check me-1"></i>Save Payment</button>
                                </div>
                            </form>
                        </div>
                    </div>
                    <aside class="page-card">
                        <div class="page-head compact-head">
                            <div class="section-title">Settlement Summary</div>
                        </div>
                        <div class="card-body-x compact-body">
                            <div class="summary-line"><span>Principal Balance</span><strong>₹<span
                                        id="sumPrincipalBalance">0.00</span></strong></div>
                            <div class="summary-line"><span>Interest Outstanding</span><strong>₹<span
                                        id="sumPaymentInterest">0.00</span></strong></div>
                            <div class="summary-line"><span>Principal Paying</span><strong>₹<span
                                        id="sumPrincipalPaying">0.00</span></strong></div>
                            <div class="summary-line total"><span>Payment Total</span><strong>₹<span
                                        id="sumPaymentTotal">0.00</span></strong></div>
                            <div class="mt-2 small text-muted">For full closure, all pending interest must be collected
                                first. Gold still pledged with a bank cannot be released.</div>
                        </div>
                    </aside>
                </div>
            </section>

            <?php include('includes/footer.php'); ?>
        </div>
    </main>
    <?php include('includes/script.php'); ?>
    <script src="assets/js/script.js"></script>
    <script>
        (function () {
            'use strict';
            const api = 'api/pawn-interest.php', csrf = <?= json_encode($csrfToken) ?>, presetPawn = <?= (int) $presetPawnId ?>;
            const $ = id => document.getElementById(id); let data = { pawns: [], payment_methods: [] }, interestQuote = null, paymentQuote = null;
            function money(v) { return Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }
            function esc(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c])) }
            function toast(ok, msg) { const x = document.createElement('div'); x.className = 'toast-x ' + (ok ? 'ok' : 'bad'); x.textContent = msg; document.body.appendChild(x); setTimeout(() => x.remove(), 3200) }
            function setBtnLoading(btn, on, label) { if (!btn) return; if (on) { if (!btn.dataset.oldHtml) btn.dataset.oldHtml = btn.innerHTML; btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>' + (label || 'Processing...'); } else { if (btn.dataset.oldHtml) { btn.innerHTML = btn.dataset.oldHtml; delete btn.dataset.oldHtml; } btn.disabled = false; } }
            async function req(o) { const f = new FormData(); Object.keys(o).forEach(k => f.append(k, o[k])); f.append('csrf_token', csrf); const r = await fetch(api, { method: 'POST', body: f, credentials: 'same-origin', headers: { Accept: 'application/json' } }); const raw = await r.text(); let j; try { j = JSON.parse(raw) } catch (e) { throw new Error(raw.replace(/<[^>]*>/g, ' ').trim().slice(0, 260) || 'Invalid server response') }; if (!r.ok || !j.success) throw new Error(j.message || 'Request failed'); return j }
            function pawn() { return data.pawns.find(x => String(x.id) === String($('pawnSelect').value)) }
            function methodsHtml() { return '<option value="">Select Method</option>' + data.payment_methods.map(m => `<option value="${m.id}">${esc(m.method_name)}</option>`).join('') }
            function renderPawnInfo(p) { $('interestPawnId').value = p ? p.id : ''; $('paymentPawnId').value = p ? p.id : ''; $('infoCustomer').textContent = p ? (p.customer_name + ' · ' + (p.mobile || '')) : '—'; $('infoPrincipal').textContent = money(p ? p.principal_amount : 0); $('infoBalance').textContent = money(p ? p.balance_principal : 0); $('infoRate').textContent = p ? (Number(p.current_interest_percent || p.interest_percent || 0).toFixed(3) + '% · Level ' + Number(p.current_rate_level || 1)) : '—'; $('infoScheme').textContent = p ? (p.interest_scheme_name || p.interest_scheme_code || 'Legacy rule') : '—'; $('infoDue').textContent = p && p.next_interest_due_date ? p.next_interest_due_date : '—'; $('infoGrace').textContent = p && p.grace_until ? p.grace_until : '—'; $('infoStatus').textContent = p ? p.status : '—'; }
            function showPawn() {
                const p = pawn(); renderPawnInfo(p);
                const alert = $('rateAlert'); const nextAlert = $('nextScheduleAlert'); nextAlert.classList.add('d-none'); nextAlert.textContent = ''; if (!p) { alert.classList.add('d-none'); alert.textContent = ''; return }
                if (Number(p.rate_escalation_count || 0) > 0) { alert.className = 'rate-alert danger'; alert.textContent = 'Rate escalated permanently to ' + Number(p.current_interest_percent || 0).toFixed(3) + '%. It will remain at this level until this pawn is closed and re-registered.' }
                else if (Number(p.next_interest_percent || 0) > 0) { alert.className = 'rate-alert'; alert.textContent = 'Current rate ' + Number(p.current_interest_percent || 0).toFixed(3) + '%. If the due remains unpaid after grace, the next-cycle rate becomes ' + Number(p.next_interest_percent).toFixed(3) + '% and remains locked until closure.' }
                else { alert.classList.add('d-none'); alert.textContent = '' }
                clearQuotes(); calculateInterest(); calculatePayment();
            }
            function clearQuotes() { interestQuote = null; paymentQuote = null;['interestFrom', 'interestTo', 'quoteRate', 'interestAmount', 'penaltyAmount'].forEach(id => $(id).value = ''); $('interestPaid').value = '0'; $('interestOther').value = '0';['sumInterest', 'sumPenalty', 'sumOther', 'sumInterestTotal'].forEach(id => $(id).textContent = '0.00'); $('sumSchedule').textContent = '—'; $('sumDue').textContent = '—'; $('sumGrace').textContent = '—' }
            function updateInterestSummary() { const i = Number(interestQuote ? interestQuote.interest_due : 0), pen = Number(interestQuote ? interestQuote.penalty_due : 0), o = Number($('interestOther').value || 0), t = i + pen + o; $('sumInterest').textContent = money(i); $('sumPenalty').textContent = money(pen); $('sumOther').textContent = money(o); $('sumInterestTotal').textContent = money(t); if ($('interestPaid').value === '0' || Number($('interestPaid').value) > t) $('interestPaid').value = t > 0 ? t.toFixed(2) : '0' }
            async function calculateInterest(useEditedRate = false) { const id = $('pawnSelect').value; if (!id) return; const btn = $('interestCalcBtn'); setBtnLoading(btn, true, 'Calculating...'); try { const payload = { action: 'interest_quote', pawn_id: id, as_of_date: $('asOfDate').value }; if (useEditedRate && Number($('quoteRate').value) > 0) payload.override_rate = $('quoteRate').value; interestQuote = await req(payload); $('interestFrom').value = interestQuote.from_date || ''; $('interestTo').value = interestQuote.to_date || ''; $('quoteRate').value = Number(interestQuote.interest_percent || 0).toFixed(3); $('interestAmount').value = Number(interestQuote.interest_due || 0).toFixed(2); $('penaltyAmount').value = Number(interestQuote.penalty_due || 0).toFixed(2); $('sumSchedule').textContent = interestQuote.schedule_no ? '#' + interestQuote.schedule_no : (interestQuote.source || 'Current'); $('sumDue').textContent = interestQuote.due_date || '—'; $('sumGrace').textContent = interestQuote.grace_until || '—'; $('interestRuleNote').textContent = interestQuote.note || ''; const ns = interestQuote.next_schedule, na = $('nextScheduleAlert'); if (ns) { na.className = 'rate-alert'; na.textContent = 'Next schedule #' + ns.schedule_no + ': ' + Number(ns.interest_percent || 0).toFixed(3) + '% · ' + (ns.from_date || '') + ' to ' + (ns.to_date || '') + ' · due ' + (ns.due_date || '—') + ' · scheduled interest ₹' + money(ns.interest_amount || 0) + '.' } else { na.classList.add('d-none'); na.textContent = '' } updateInterestSummary(); if (interestQuote.pawn) { refreshPawnFromQuote(interestQuote.pawn) } } catch (e) { toast(false, e.message) } finally { setBtnLoading(btn, false); } }
            function refreshPawnFromQuote(p) { const idx = data.pawns.findIndex(x => String(x.id) === String(p.id)); if (idx >= 0) data.pawns[idx] = Object.assign({}, data.pawns[idx], p); renderPawnInfo(idx >= 0 ? data.pawns[idx] : p); }
            async function calculatePayment() { const id = $('pawnSelect').value; if (!id) return; const btn = $('paymentCalcBtn'); setBtnLoading(btn, true, 'Calculating...'); try { paymentQuote = await req({ action: 'payment_quote', pawn_id: id, as_of_date: $('paymentDate').value }); $('paymentInterestDue').value = Number(paymentQuote.interest_outstanding || 0).toFixed(2); $('sumPrincipalBalance').textContent = money(paymentQuote.principal_balance); $('sumPaymentInterest').textContent = money(paymentQuote.interest_outstanding); updatePaymentSummary(); updateClosureState() } catch (e) { toast(false, e.message) } finally { setBtnLoading(btn, false); } }
            function updatePaymentSummary() { const p = pawn(); let amt = Math.max(0, Number($('principalPayment').value || 0)); const bal = Number(paymentQuote ? paymentQuote.principal_balance : (p ? p.balance_principal : 0)); if (amt > bal) { amt = bal; $('principalPayment').value = bal.toFixed(2) } const after = Math.max(0, bal - amt); const closureInterest = $('isClosure').checked ? Number(paymentQuote ? paymentQuote.interest_outstanding || 0 : 0) : 0; $('paymentBalanceAfter').value = after.toFixed(2); $('sumPrincipalPaying').textContent = money(amt); $('sumPaymentTotal').textContent = money(amt + closureInterest) }
            function updateClosureState() { const on = $('isClosure').checked; $('closureBox').classList.toggle('show', on); $('releasedTo').required = on; const q = paymentQuote || {}; if (!on) { $('paymentSaveBtn').disabled = false; updatePaymentSummary(); return } $('principalPayment').value = Number(q.principal_balance || 0).toFixed(2); updatePaymentSummary(); let msg = '', block = false; if (q.bank_release_blocked) { msg = 'Closure blocked: this pawn gold is still actively pledged with a bank.'; block = true } else { msg = 'Full closure will collect principal ₹' + money(q.principal_balance || 0) + ' + interest calculated up to ' + ($('paymentDate').value || 'selected date') + ' ₹' + money(q.interest_outstanding || 0) + '. Total ₹' + money(Number(q.principal_balance || 0) + Number(q.interest_outstanding || 0)) + '.' } $('closureWarning').textContent = msg; $('closureWarning').className = 'small mt-2 ' + (block ? 'text-danger' : 'text-success'); $('paymentSaveBtn').disabled = block }
            async function init() { try { const j = await req({ action: 'init' }); data.pawns = j.pawns || []; data.payment_methods = j.payment_methods || []; const st = j.stats || {}; $('stDue').textContent = Number(st.interest_due_count || 0); $('stOverdue').textContent = Number(st.overdue_count || 0); $('stEscalated').textContent = Number(st.escalated_count || 0); $('stInterestOutstanding').textContent = money(st.interest_outstanding || 0); $('pawnSelect').innerHTML = '<option value="">Select active pawn</option>' + data.pawns.map(p => `<option value="${p.id}">${esc(p.pawn_no)} · ${esc(p.customer_name)} · ₹${money(p.balance_principal)}</option>`).join(''); $('interestMethod').innerHTML = methodsHtml(); $('paymentMethod').innerHTML = methodsHtml(); if (presetPawn && data.pawns.some(p => Number(p.id) === presetPawn)) $('pawnSelect').value = String(presetPawn); showPawn() } catch (e) { toast(false, e.message) } }
            document.querySelectorAll('.op-tab').forEach(b => b.onclick = () => { document.querySelectorAll('.op-tab').forEach(x => x.classList.remove('active')); b.classList.add('active'); document.querySelectorAll('.tab-panel').forEach(x => x.classList.remove('active')); $(b.dataset.tab === 'interest' ? 'panelInterest' : 'panelPayment').classList.add('active') });
            $('pawnSelect').onchange = showPawn; $('asOfDate').onchange = calculateInterest; $('paymentDate').onchange = calculatePayment; $('interestCalcBtn').onclick = () => calculateInterest(true); $('paymentCalcBtn').onclick = calculatePayment; $('quoteRate').onchange = () => calculateInterest(true); $('interestOther').oninput = updateInterestSummary; $('principalPayment').oninput = updatePaymentSummary; $('isClosure').onchange = updateClosureState;
            $('interestForm').onsubmit = async e => { e.preventDefault(); if (!interestQuote) return toast(false, 'Calculate interest first.'); const f = new FormData(e.target); f.append('action', 'interest_collect'); f.append('csrf_token', csrf); f.append('accrual_id', String(interestQuote.accrual_id || 0)); const b = $('interestSaveBtn'); setBtnLoading(b, true, 'Collecting...'); try { const r = await fetch(api, { method: 'POST', body: f, credentials: 'same-origin', headers: { Accept: 'application/json' } }), raw = await r.text(); let j; try { j = JSON.parse(raw) } catch (x) { throw new Error(raw.replace(/<[^>]*>/g, ' ').slice(0, 260)) } if (!r.ok || !j.success) throw new Error(j.message || 'Unable to collect interest'); toast(true, j.message + ' Receipt: ' + j.receipt_no); await init() } catch (x) { toast(false, x.message) } finally { setBtnLoading(b, false); } };
            $('paymentForm').onsubmit = async e => { e.preventDefault(); if (!paymentQuote) return toast(false, 'Refresh payment due first.'); const f = new FormData(e.target); f.append('action', 'payment_collect'); f.append('csrf_token', csrf); const b = $('paymentSaveBtn'); setBtnLoading(b, true, $('isClosure').checked ? 'Closing Pawn...' : 'Saving...'); try { const r = await fetch(api, { method: 'POST', body: f, credentials: 'same-origin', headers: { Accept: 'application/json' } }), raw = await r.text(); let j; try { j = JSON.parse(raw) } catch (x) { throw new Error(raw.replace(/<[^>]*>/g, ' ').slice(0, 260)) } if (!r.ok || !j.success) throw new Error(j.message || 'Unable to save payment'); toast(true, j.message + ' Receipt: ' + j.receipt_no + (j.release_no ? ' · Release: ' + j.release_no : '')); await init() } catch (x) { toast(false, x.message) } finally { setBtnLoading(b, false); updateClosureState() } };
            init();
        })();
    </script>
</body>

</html>