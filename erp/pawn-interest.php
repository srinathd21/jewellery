<?php require __DIR__ . '/_common.php'; ?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($businessName) ?> - Pawn Interest Collection</title>
    <?php include('includes/links.php');
    require __DIR__ . '/_style.php'; ?>
    <style>
        .layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 340px;
            gap: 14px;
            align-items: start
        }

        .sticky {
            position: sticky;
            top: 82px
        }

        .summary-line {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            padding: 8px 0;
            border-bottom: 1px dashed var(--line);
            font-size: 11px
        }

        .value-card {
            padding: 14px;
            border-radius: 11px;
            background: var(--primary-soft);
            text-align: center
        }

        .value-card strong {
            display: block;
            font-size: 26px;
            color: var(--primary-dark)
        }

        .pawn-info {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 8px
        }

        .chip {
            padding: 10px;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: color-mix(in srgb, var(--muted) 4%, transparent)
        }

        .chip span {
            display: block;
            font-size: 9px;
            color: var(--muted);
            text-transform: uppercase
        }

        .chip strong {
            font-size: 12px
        }

        .toast-x {
            position: fixed;
            right: 18px;
            top: 78px;
            z-index: 20000;
            padding: 11px 14px;
            border-radius: 10px;
            color: #fff;
            font-size: 11px
        }

        .ok {
            background: #168449
        }

        .bad {
            background: #c0392b
        }

        .receipt-actions {
            display: none;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 12px;
        }

        .receipt-actions.show {
            display: flex;
        }

        .btn-whatsapp {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #25D366 !important;
            color: #fff !important;
            border-color: #25D366 !important;
        }

        .btn-whatsapp:hover {
            background: #1fb85a !important;
            color: #fff !important;
        }

        @media(max-width:991px) {
            .layout {
                grid-template-columns: 1fr
            }

            .sticky {
                position: static
            }

            .pawn-info {
                grid-template-columns: 1fr 1fr
            }
        }
    </style>
</head>

<body><?php include('includes/sidebar.php'); ?>
    <main class="app-main"><?php include('includes/nav.php'); ?>
        <div class="content-wrap">
            <div class="page-card mb-3">
                <div class="page-head">
                    <div>
                        <div class="page-title">Pawn Interest Collection</div>
                        <div class="small text-muted">Calculate pending interest, overdue charge and collect it for an
                            active pawn.</div>
                    </div>
                    <div class="d-flex gap-2"><a href="pawn-payment.php" class="btn-soft">Principal / Closure</a><a
                            href="pawn-collections.php" class="btn-soft">Collections</a></div>
                </div>
            </div>
            <div class="layout">
                <section>
                    <div class="page-card mb-3">
                        <div class="page-head">
                            <div class="section-title">Select Pawn</div>
                        </div>
                        <div class="card-body-x">
                            <div class="row g-2">
                                <div class="col-md-8"><label class="small fw-bold">Pawn Entry</label><select
                                        id="pawnSelect" class="form-select">
                                        <option value="">Loading...</option>
                                    </select></div>
                                <div class="col-md-4"><label class="small fw-bold">Calculate Up To</label><input
                                        type="date" id="asOfDate" class="form-control" value="<?= date('Y-m-d') ?>">
                                </div>
                            </div>
                            <div class="pawn-info mt-3" id="pawnInfo">
                                <div class="chip"><span>Customer</span><strong id="infoCustomer">—</strong></div>
                                <div class="chip"><span>Principal Balance</span><strong>₹<span
                                            id="infoBalance">0.00</span></strong></div>
                                <div class="chip"><span>Interest Rule</span><strong id="infoInterest">—</strong></div>
                                <div class="chip"><span>Cycle</span><strong id="infoCycle">—</strong></div>
                            </div>
                        </div>
                    </div>
                    <div class="page-card">
                        <div class="page-head">
                            <div class="section-title">Collection Details</div><button type="button" id="calculateBtn"
                                class="btn-soft">Calculate Interest</button>
                        </div>
                        <div class="card-body-x">
                            <form id="collectForm"><input type="hidden" name="pawn_id" id="pawnId"><input type="hidden"
                                    name="from_date" id="fromDate"><input type="hidden" name="to_date"
                                    id="toDate"><input type="hidden" name="calculation_days" id="calcDays"><input
                                    type="hidden" name="calculation_months" id="calcMonths">
                                <div class="row g-2">
                                    <div class="col-md-3"><label class="small fw-bold">Collection Date</label><input
                                            type="date" name="collection_date" id="collectionDate" class="form-control"
                                            value="<?= date('Y-m-d') ?>" required></div>
                                    <div class="col-md-3"><label class="small fw-bold">Interest From</label><input
                                            id="fromDisplay" class="form-control" readonly></div>
                                    <div class="col-md-3"><label class="small fw-bold">Interest To</label><input
                                            id="toDisplay" class="form-control" readonly></div>
                                    <div class="col-md-3"><label class="small fw-bold">Calculated Days</label><input
                                            id="daysDisplay" class="form-control" readonly></div>
                                    <div class="col-md-3"><label class="small fw-bold">Interest Amount</label><input
                                            type="number" step="0.01" min="0" name="interest_amount" id="interestAmount"
                                            class="form-control" required></div>
                                    <div class="col-md-3"><label class="small fw-bold">Overdue Charge</label><input
                                            type="number" step="0.01" min="0" name="penalty_amount" id="penaltyAmount"
                                            class="form-control" value="0"></div>
                                    <div class="col-md-3"><label class="small fw-bold">Other Charges</label><input
                                            type="number" step="0.01" min="0" name="other_charges" id="otherCharges"
                                            class="form-control" value="0"></div>
                                    <div class="col-md-3"><label class="small fw-bold">Paid Amount</label><input
                                            type="number" step="0.01" min="0.01" name="paid_amount" id="paidAmount"
                                            class="form-control" required></div>
                                    <div class="col-md-4"><label class="small fw-bold">Payment Method</label><select
                                            name="payment_method_id" id="paymentMethod" class="form-select" required>
                                            <option value="">Select Method</option>
                                        </select></div>
                                    <div class="col-md-4"><label class="small fw-bold">Reference No</label><input
                                            name="reference_no" class="form-control"></div>
                                    <div class="col-md-4"><label class="small fw-bold">Remarks</label><input
                                            name="remarks" class="form-control"></div>
                                </div>
                                <button class="btn-theme mt-3" id="saveBtn" type="submit">Collect Interest</button>

                                <div class="receipt-actions" id="receiptActions">
                                    <a href="#" class="btn-soft" id="receiptBtn" target="_blank" rel="noopener">
                                        <i class="fa-solid fa-receipt"></i> Receipt
                                    </a>
                                    <a href="#" class="btn-whatsapp btn-soft" id="whatsappBtn" target="_blank" rel="noopener">
                                        <i class="fa-brands fa-whatsapp"></i> WhatsApp
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>
                </section>
                <aside class="page-card sticky">
                    <div class="page-head">
                        <div class="section-title">Interest Summary</div>
                    </div>
                    <div class="card-body-x">
                        <div class="value-card mb-3"><span class="small text-muted">Amount to
                                Collect</span><strong>₹<span id="totalDue">0.00</span></strong></div>
                        <div class="summary-line"><span>Interest</span><strong>₹<span
                                    id="sumInterest">0.00</span></strong></div>
                        <div class="summary-line"><span>Overdue</span><strong>₹<span
                                    id="sumPenalty">0.00</span></strong></div>
                        <div class="summary-line"><span>Other Charges</span><strong>₹<span
                                    id="sumOther">0.00</span></strong></div>
                        <div class="summary-line"><span>Overdue Days</span><strong id="overdueDays">0</strong></div>
                        <div class="small text-muted mt-3" id="ruleNote">Select a pawn and calculate interest.</div>
                    </div>
                </aside>
            </div>
            <?php include('includes/footer.php'); ?>
        </div>
    </main><?php include('includes/script.php'); ?>
    <script src="assets/js/script.js"></script>
    <script>
        (() => {
            'use strict'; const api = 'api/pawn-operations.php', csrf = <?= json_encode($csrfToken) ?>, $ = id => document.getElementById(id); let data = { pawns: [], payment_methods: [] }, quote = null; function money(v) { return Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) } function esc(v) { return String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c])) } function toast(ok, msg) { const x = document.createElement('div'); x.className = 'toast-x ' + (ok ? 'ok' : 'bad'); x.textContent = msg; document.body.appendChild(x); setTimeout(() => x.remove(), 3000) } async function req(o) { const f = new FormData(); Object.entries(o).forEach(([k, v]) => f.append(k, v)); f.append('csrf_token', csrf); const r = await fetch(api, { method: 'POST', body: f, credentials: 'same-origin' }), j = await r.json().catch(() => ({ success: false, message: 'Invalid server response.' })); if (!r.ok || !j.success) throw new Error(j.message || 'Request failed'); return j }
            function selected() { return data.pawns.find(x => String(x.id) === String($('pawnSelect').value)) } function showPawn() { const p = selected(); $('pawnId').value = p?.id || ''; $('infoCustomer').textContent = p ? p.customer_name + ' · ' + (p.mobile || '') : '—'; $('infoBalance').textContent = money(p?.balance_principal || 0); $('infoInterest').textContent = p ? Number(p.interest_percent || 0).toFixed(3) + '% ' + p.interest_period : '—'; $('infoCycle').textContent = p?.interest_collection_cycle || '—'; $('ruleNote').textContent = p?.interest_collection_cycle === 'At Closure' ? 'This pawn is configured for interest at closure. You can still collect an interim interest payment manually.' : 'Interest starts after the last paid-up-to date.'; quote = null; clearQuote() } function clearQuote() { ['fromDisplay', 'toDisplay', 'daysDisplay'].forEach(id => $(id).value = '');['interestAmount', 'penaltyAmount', 'paidAmount'].forEach(id => $(id).value = ''); $('otherCharges').value = '0'; $('receiptActions')?.classList.remove('show'); updateSummary() }
            function updateSummary() { const i = Number($('interestAmount').value || 0), p = Number($('penaltyAmount').value || 0), o = Number($('otherCharges').value || 0), t = i + p + o; $('sumInterest').textContent = money(i); $('sumPenalty').textContent = money(p); $('sumOther').textContent = money(o); $('totalDue').textContent = money(t); if (!$('paidAmount').dataset.manual) $('paidAmount').value = t > 0 ? t.toFixed(2) : '' }
            async function calculate() { const pawnId = $('pawnSelect').value; if (!pawnId) return toast(false, 'Select a pawn entry.'); try { quote = await req({ action: 'interest_quote', pawn_id: pawnId, as_of_date: $('asOfDate').value }); $('fromDate').value = quote.from_date; $('toDate').value = quote.to_date; $('calcDays').value = quote.calculation_days; $('calcMonths').value = quote.calculation_months; $('fromDisplay').value = quote.from_date; $('toDisplay').value = quote.to_date; $('daysDisplay').value = quote.calculation_days + ' day(s)'; $('interestAmount').value = Number(quote.interest_amount).toFixed(2); $('penaltyAmount').value = Number(quote.penalty_amount).toFixed(2); $('overdueDays').textContent = quote.overdue_days || 0; $('paidAmount').dataset.manual = ''; updateSummary() } catch (e) { toast(false, e.message) } }
            function whatsappNumber(value) {
                let number = String(value || '').replace(/\D+/g, '');
                if (number.length === 10) number = '91' + number;
                else if (number.length === 11 && number.startsWith('0')) number = '91' + number.slice(1);
                return number;
            }

            function absoluteUrl(path) {
                const current = new URL(window.location.href);
                const dir = current.pathname.substring(0, current.pathname.lastIndexOf('/') + 1);
                return current.origin + dir + path.replace(/^\/+/, '');
            }

            function showReceiptActions(result) {
                if (!result || !result.collection_id || !result.receipt_no) return;

                const p = selected();
                const receiptRelative = 'pawn-interest-receipt.php?collection_id='
                    + encodeURIComponent(result.collection_id)
                    + '&receipt=' + encodeURIComponent(result.receipt_no);

                const receiptUrl = absoluteUrl(receiptRelative);
                $('receiptBtn').href = receiptRelative;

                const mobile = whatsappNumber(result.mobile || p?.mobile || '');
                const customerName = result.customer_name || p?.customer_name || 'Customer';

                if (mobile) {
                    const message =
                        'Dear ' + customerName + ',\n\n'
                        + 'Your pawn interest payment receipt is ready.\n'
                        + 'Pawn No: ' + (result.pawn_no || p?.pawn_no || '') + '\n'
                        + 'Receipt: ' + result.receipt_no + '\n'
                        + 'Interest: ₹' + money(result.interest_amount || 0) + '\n'
                        + 'Penalty: ₹' + money(result.penalty_amount || 0) + '\n'
                        + 'Other Charges: ₹' + money(result.other_charges || 0) + '\n'
                        + 'Paid Amount: ₹' + money(result.paid_amount || 0) + '\n\n'
                        + 'View receipt:\n' + receiptUrl + '\n\n'
                        + 'Thank you.';

                    $('whatsappBtn').href = 'https://wa.me/' + mobile + '?text=' + encodeURIComponent(message);
                    $('whatsappBtn').style.display = '';
                } else {
                    $('whatsappBtn').style.display = 'none';
                }

                $('receiptActions').classList.add('show');
            }

            async function init() { try { data = await req({ action: 'pawn_options', status: 'open' }); $('pawnSelect').innerHTML = '<option value="">Select active pawn</option>' + data.pawns.map(p => `<option value="${p.id}">${esc(p.pawn_no)} · ${esc(p.customer_name)} · ₹${money(p.balance_principal)}</option>`).join(''); $('paymentMethod').innerHTML = '<option value="">Select Method</option>' + data.payment_methods.map(m => `<option value="${m.id}">${esc(m.method_name)}</option>`).join('') } catch (e) { toast(false, e.message) } }
            $('pawnSelect').onchange = showPawn; $('calculateBtn').onclick = calculate; $('asOfDate').onchange = calculate;['interestAmount', 'penaltyAmount', 'otherCharges'].forEach(id => $(id).oninput = updateSummary); $('paidAmount').oninput = e => e.target.dataset.manual = '1'; $('collectForm').onsubmit = async e => { e.preventDefault(); if (!$('pawnId').value) return toast(false, 'Select a pawn entry.'); const f = new FormData(e.target); f.append('action', 'interest_collect'); f.append('csrf_token', csrf); const b = $('saveBtn'); b.disabled = true; try { const r = await fetch(api, { method: 'POST', body: f, credentials: 'same-origin' }), j = await r.json(); if (!r.ok || !j.success) throw new Error(j.message || 'Unable to collect interest'); toast(true, j.message + ' Receipt: ' + j.receipt_no); showReceiptActions(j); e.target.reset(); $('collectionDate').value = '<?= date('Y-m-d') ?>'; ['fromDisplay','toDisplay','daysDisplay'].forEach(id => $(id).value=''); ['interestAmount','penaltyAmount','paidAmount'].forEach(id => $(id).value=''); $('otherCharges').value='0'; updateSummary(); await init() } catch (err) { toast(false, err.message) } finally { b.disabled = false } }; init();
        })();
    </script>
</body>

</html>