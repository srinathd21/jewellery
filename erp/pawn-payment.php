<?php require __DIR__ . '/_common.php'; ?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($businessName) ?> - Pawn Payment</title>
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

        .closure-box {
            padding: 12px;
            border: 1px solid color-mix(in srgb, var(--primary) 35%, var(--line));
            border-radius: 10px;
            background: var(--primary-soft)
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
                        <div class="page-title">Pawn Payment & Settlement</div>
                        <div class="small text-muted">Collect principal, interest, overdue charges or complete the pawn
                            settlement.</div>
                    </div>
                    <div class="d-flex gap-2"><a href="pawn-interest.php" class="btn-soft">Interest Collection</a><a
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
                                <div class="col-md-4"><label class="small fw-bold">Settlement Date</label><input
                                        type="date" id="asOfDate" class="form-control" value="<?= date('Y-m-d') ?>">
                                </div>
                            </div>
                            <div class="pawn-info mt-3">
                                <div class="chip"><span>Customer</span><strong id="infoCustomer">—</strong></div>
                                <div class="chip"><span>Original Principal</span><strong>₹<span
                                            id="infoPrincipal">0.00</span></strong></div>
                                <div class="chip"><span>Balance Principal</span><strong>₹<span
                                            id="infoBalance">0.00</span></strong></div>
                                <div class="chip"><span>Status</span><strong id="infoStatus">—</strong></div>
                            </div>
                        </div>
                    </div>
                    <div class="page-card">
                        <div class="page-head">
                            <div class="section-title">Payment Details</div><button type="button" id="calculateBtn"
                                class="btn-soft">Calculate Dues</button>
                        </div>
                        <div class="card-body-x">
                            <form id="paymentForm"><input type="hidden" name="pawn_id" id="pawnId">
                                <div class="row g-2">
                                    <div class="col-md-3"><label class="small fw-bold">Payment Date</label><input
                                            type="date" name="payment_date" id="paymentDate" class="form-control"
                                            value="<?= date('Y-m-d') ?>" required></div>
                                    <div class="col-md-3"><label class="small fw-bold">Principal Payment</label><input
                                            type="number" step="0.01" min="0" name="principal_amount"
                                            id="principalAmount" class="form-control" value="0"></div>
                                    <div class="col-md-3"><label class="small fw-bold">Interest</label><input
                                            type="number" step="0.01" min="0" name="interest_amount" id="interestAmount"
                                            class="form-control" value="0"></div>
                                    <div class="col-md-3"><label class="small fw-bold">Overdue Charge</label><input
                                            type="number" step="0.01" min="0" name="penalty_amount" id="penaltyAmount"
                                            class="form-control" value="0"></div>
                                    <div class="col-md-3"><label class="small fw-bold">Other Charges</label><input
                                            type="number" step="0.01" min="0" name="other_charges" id="otherCharges"
                                            class="form-control" value="0"></div>
                                    <div class="col-md-3"><label class="small fw-bold">Payment Method</label><select
                                            name="payment_method_id" id="paymentMethod" class="form-select" required>
                                            <option value="">Select Method</option>
                                        </select></div>
                                    <div class="col-md-3"><label class="small fw-bold">Reference No</label><input
                                            name="reference_no" class="form-control"></div>
                                    <div class="col-md-3"><label class="small fw-bold">Remarks</label><input
                                            name="remarks" class="form-control"></div>
                                    <div class="col-12">
                                        <div class="closure-box"><label class="fw-bold small"><input type="checkbox"
                                                    name="is_closure" id="isClosure" value="1"> Full settlement and
                                                create release record</label>
                                            <div class="row g-2 mt-1 d-none" id="closureFields">
                                                <div class="col-md-6"><label class="small fw-bold">Items Released
                                                        To</label><input name="released_to" id="releasedTo"
                                                        class="form-control" placeholder="Customer / authorized person">
                                                </div>
                                                <div class="col-md-6"><label class="small fw-bold">Handover
                                                        Status</label><input class="form-control"
                                                        value="Pending verification and handover" readonly></div>
                                            </div>
                                        </div>
                                    </div>
                                </div><button class="btn-theme mt-3" id="saveBtn" type="submit">Save Pawn
                                    Payment</button>
                            </form>
                        </div>
                    </div>
                </section>
                <aside class="page-card sticky">
                    <div class="page-head">
                        <div class="section-title">Settlement Summary</div>
                    </div>
                    <div class="card-body-x">
                        <div class="value-card mb-3"><span class="small text-muted">Total Payment</span><strong>₹<span
                                    id="totalPayment">0.00</span></strong></div>
                        <div class="summary-line"><span>Principal</span><strong>₹<span
                                    id="sumPrincipal">0.00</span></strong></div>
                        <div class="summary-line"><span>Interest</span><strong>₹<span
                                    id="sumInterest">0.00</span></strong></div>
                        <div class="summary-line"><span>Overdue</span><strong>₹<span
                                    id="sumPenalty">0.00</span></strong></div>
                        <div class="summary-line"><span>Other Charges</span><strong>₹<span
                                    id="sumOther">0.00</span></strong></div>
                        <div class="summary-line"><span>Balance After Payment</span><strong>₹<span
                                    id="balanceAfter">0.00</span></strong></div>
                        <div class="small text-muted mt-3" id="settlementNote">Select a pawn and calculate its dues.
                        </div>
                    </div>
                </aside>
            </div>
            <?php include('includes/footer.php'); ?>
        </div>
    </main><?php include('includes/script.php'); ?>
    <script src="assets/js/script.js"></script>
    <script>
        (() => {
            'use strict'; const api = 'api/pawn-payments.php', csrf = <?= json_encode($csrfToken) ?>, $ = id => document.getElementById(id); let data = { pawns: [], payment_methods: [] }, quote = null; function money(v) { return Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) } function esc(v) { return String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c])) } function toast(ok, msg) { const x = document.createElement('div'); x.className = 'toast-x ' + (ok ? 'ok' : 'bad'); x.textContent = msg; document.body.appendChild(x); setTimeout(() => x.remove(), 3200) } async function req(o) { const f = new FormData(); Object.entries(o).forEach(([k, v]) => f.append(k, v)); f.append('csrf_token', csrf); const r = await fetch(api, { method: 'POST', body: f, credentials: 'same-origin' }), j = await r.json().catch(() => ({ success: false, message: 'Invalid server response.' })); if (!r.ok || !j.success) throw new Error(j.message || 'Request failed'); return j }
            function pawn() { return data.pawns.find(x => String(x.id) === String($('pawnSelect').value)) } function showPawn() { const p = pawn(); $('pawnId').value = p?.id || ''; $('infoCustomer').textContent = p ? p.customer_name + ' · ' + (p.mobile || '') : '—'; $('infoPrincipal').textContent = money(p?.principal_amount || 0); $('infoBalance').textContent = money(p?.balance_principal || 0); $('infoStatus').textContent = p?.status || '—'; clearAmounts(); $('settlementNote').textContent = p?.interest_collection_cycle === 'At Closure' ? 'This pawn collects interest at closure. Calculate dues before full settlement.' : 'Part principal payments and full settlement are available.' }
            function clearAmounts() { ['principalAmount', 'interestAmount', 'penaltyAmount', 'otherCharges'].forEach(id => $(id).value = '0'); $('isClosure').checked = false; $('closureFields').classList.add('d-none'); updateSummary() }
            function updateSummary() { const p = Number($('principalAmount').value || 0), i = Number($('interestAmount').value || 0), pen = Number($('penaltyAmount').value || 0), o = Number($('otherCharges').value || 0), total = p + i + pen + o, balance = Math.max(0, Number(pawn()?.balance_principal || 0) - p); $('sumPrincipal').textContent = money(p); $('sumInterest').textContent = money(i); $('sumPenalty').textContent = money(pen); $('sumOther').textContent = money(o); $('totalPayment').textContent = money(total); $('balanceAfter').textContent = money(balance) }
            async function calculate() { const id = $('pawnSelect').value; if (!id) return toast(false, 'Select a pawn entry.'); try { quote = await req({ action: 'payment_quote', pawn_id: id, as_of_date: $('asOfDate').value }); $('interestAmount').value = Number(quote.interest_amount).toFixed(2); $('penaltyAmount').value = Number(quote.penalty_amount).toFixed(2); if ($('isClosure').checked) $('principalAmount').value = Number(quote.pawn.balance_principal).toFixed(2); updateSummary() } catch (e) { toast(false, e.message) } }
            async function init() { try { data = await req({ action: 'pawn_options', status: 'open' }); $('pawnSelect').innerHTML = '<option value="">Select active pawn</option>' + data.pawns.map(p => `<option value="${p.id}">${esc(p.pawn_no)} · ${esc(p.customer_name)} · ₹${money(p.balance_principal)}</option>`).join(''); $('paymentMethod').innerHTML = '<option value="">Select Method</option>' + data.payment_methods.map(m => `<option value="${m.id}">${esc(m.method_name)}</option>`).join('') } catch (e) { toast(false, e.message) } }
            $('pawnSelect').onchange = showPawn; $('calculateBtn').onclick = calculate; $('asOfDate').onchange = calculate;['principalAmount', 'interestAmount', 'penaltyAmount', 'otherCharges'].forEach(id => $(id).oninput = updateSummary); $('isClosure').onchange = e => { const on = e.target.checked; $('closureFields').classList.toggle('d-none', !on); $('releasedTo').required = on; if (on) { $('principalAmount').value = Number(pawn()?.balance_principal || 0).toFixed(2); calculate() } updateSummary() }; $('paymentForm').onsubmit = async e => { e.preventDefault(); if (!$('pawnId').value) return toast(false, 'Select a pawn entry.'); const f = new FormData(e.target); f.append('action', 'payment_collect'); f.append('csrf_token', csrf); const b = $('saveBtn'); b.disabled = true; try { const r = await fetch(api, { method: 'POST', body: f, credentials: 'same-origin' }), j = await r.json(); if (!r.ok || !j.success) throw new Error(j.message || 'Unable to save payment'); toast(true, j.message + ' Receipt: ' + j.receipt_no + (j.release_no ? ' · Release: ' + j.release_no : '')); e.target.reset(); $('paymentDate').value = '<?= date('Y-m-d') ?>'; clearAmounts(); await init() } catch (err) { toast(false, err.message) } finally { b.disabled = false } }; init();
        })();
    </script>
</body>

</html>