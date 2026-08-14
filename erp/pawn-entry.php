<?php require __DIR__ . '/_common.php';
$editId = isset($_GET['id']) ? max(0, (int) $_GET['id']) : 0;
$reregisterFrom = $editId > 0 ? 0 : (isset($_GET['reregister_from']) ? max(0, (int) $_GET['reregister_from']) : 0);
?>
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
        .entry-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 350px;
            gap: 14px;
            align-items: start
        }

        .sticky {
            position: sticky;
            top: 82px
        }

        .item-box {
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
            font-size: 12px
        }

        .scheme-card {
            border: 1px solid var(--line);
            border-radius: 11px;
            padding: 12px;
            background: color-mix(in srgb, var(--primary) 5%, transparent)
        }

        .scheme-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 8px
        }

        .scheme-cell {
            padding: 9px;
            border-radius: 8px;
            background: var(--card-bg);
            border: 1px solid var(--line)
        }

        .scheme-cell span {
            display: block;
            font-size: 8px;
            text-transform: uppercase;
            color: var(--muted)
        }

        .scheme-cell strong {
            font-size: 11px
        }

        .rate-ladder {
            margin-top: 10px;
            display: flex;
            gap: 7px;
            flex-wrap: wrap
        }

        .rate-step {
            padding: 7px 9px;
            border: 1px solid var(--line);
            border-radius: 999px;
            font-size: 10px
        }

        .rate-step.current {
            background: var(--primary-soft);
            color: var(--primary-dark);
            font-weight: 800
        }

        .danger-note {
            padding: 10px;
            border-radius: 9px;
            background: #fff4e5;
            border: 1px solid #f4c97a;
            color: #7a4b00;
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

        .select2-container {
            width: 100% !important
        }

        .select2-container .select2-selection--single {
            height: 38px;
            border: 1px solid var(--line);
            border-radius: 9px;
            background: var(--card-bg)
        }

        .select2-container .select2-selection--single .select2-selection__rendered {
            line-height: 36px;
            color: var(--text);
            padding-left: 12px
        }

        .select2-container .select2-selection--single .select2-selection__arrow {
            height: 36px
        }

        .select2-dropdown,
        .select2-search__field {
            background: var(--card-bg);
            color: var(--text);
            border-color: var(--line) !important
        }

        .proof-preview {
            min-height: 92px;
            border: 1px dashed var(--line);
            border-radius: 9px;
            padding: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--muted);
            font-size: 10px
        }

        .proof-preview img {
            max-width: 220px;
            max-height: 135px;
            object-fit: contain;
            border-radius: 8px
        }

        @media(max-width:1100px) {
            .entry-grid {
                grid-template-columns: 1fr
            }

            .sticky {
                position: static
            }
        }

        @media(max-width:767px) {

            .info-strip,
            .scheme-grid {
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
                        <div class="page-title"><?= $editId > 0 ? 'Edit Pawn Entry' : ($reregisterFrom > 0 ? 'Re-Register Pawn' : 'New Pawn Entry') ?></div>
                        <div class="small text-muted">Register customer gold, disburse the loan and lock the configured
                            interest rule for this pawn.</div>
                    </div>
                    <div class="d-flex gap-2 flex-wrap"><a href="customers.php" class="btn-soft">Register Customer</a><a
                            href="pawn-manage.php" class="btn-soft">Pawn Manage</a><a href="pawn-settings.php"
                            class="btn-soft">Pawn Settings</a></div>
                </div>
            </div>

            <form id="pawnForm" enctype="multipart/form-data">
                <input type="hidden" name="action" id="formAction" value="<?= $editId > 0 ? 'update' : 'create' ?>">
                <input type="hidden" name="edit_id" id="editId" value="<?= (int) $editId ?>">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="reregister_from" id="reregisterFrom" value="<?= (int) $reregisterFrom ?>">
                <input type="hidden" name="total_gross_weight" id="totalGrossInput" value="0">
                <input type="hidden" name="total_stone_weight" id="totalStoneInput" value="0">
                <input type="hidden" name="total_net_weight" id="totalNetInput" value="0">
                <input type="hidden" name="total_estimated_value" id="totalEstimatedInput" value="0">
                <input type="hidden" name="disbursement_amount" id="disbursementInput" value="0">

                <div class="entry-grid">
                    <section>
                        <div class="page-card mb-3">
                            <div class="page-head">
                                <div class="section-title">Pawn & Customer</div>
                            </div>
                            <div class="card-body-x">
                                <div id="reregisterNotice" class="danger-note mb-3 d-none"></div>
                                <div class="row g-3">
                                    <div class="col-md-3"><label class="form-label">Pawn No</label><input id="pawnNo"
                                            class="form-control" readonly></div>
                                    <div class="col-md-3"><label class="form-label">Pawn Date *</label><input
                                            type="date" name="pawn_date" id="pawnDate" class="form-control"
                                            value="<?= date('Y-m-d') ?>" required></div>
                                    <div class="col-md-6"><label class="form-label">Customer *</label><select
                                            name="customer_id" id="customerSelect" class="form-select" required>
                                            <option value=""></option>
                                        </select></div>
                                    <div class="col-md-4"><label class="form-label">Pawn Category *</label><select
                                            name="pawn_category_id" id="categorySelect" class="form-select" required>
                                            <option value="">Select category</option>
                                        </select><div class="small text-muted mt-1">Category Max Loan: <strong id="categoryPercent">-</strong></div></div>
                                    <div class="col-md-4"><label class="form-label">Loan Type</label><input
                                            name="loan_type" class="form-control" value="General"></div>
                                    <div class="col-md-4"><label class="form-label">Primary Metal</label><select
                                            name="primary_metal_id" id="primaryMetal" class="form-select">
                                            <option value="">Select metal</option>
                                        </select></div>
                                </div>
                                <div class="info-strip mt-3">
                                    <div class="info-chip"><span>Customer Code</span><strong id="infoCode">-</strong>
                                    </div>
                                    <div class="info-chip"><span>Mobile</span><strong id="infoMobile">-</strong></div>
                                    <div class="info-chip"><span>KYC</span><strong id="infoKyc">-</strong></div>
                                    <div class="info-chip"><span>Risk</span><strong id="infoRisk">-</strong></div>
                                </div>
                            </div>
                        </div>

                        <div class="page-card mb-3">
                            <div class="page-head">
                                <div>
                                    <div class="section-title">Interest Contract</div>
                                    <div class="small text-muted">The selected scheme is snapshotted into this pawn and
                                        cannot reset after a rate escalation.</div>
                                </div>
                            </div>
                            <div class="card-body-x">
                                <div class="row g-3">
                                    <div class="col-md-8"><label class="form-label">Interest Scheme *</label><select
                                            name="interest_scheme_id" id="schemeSelect" class="form-select" required>
                                            <option value="">Select interest scheme</option>
                                        </select></div>
                                    <div class="col-md-4"><label class="form-label">First Interest Due</label><input
                                            id="firstDue" class="form-control" readonly></div>
                                </div>
                                <div id="schemeCard" class="scheme-card mt-3 d-none">
                                    <div class="scheme-grid">
                                        <div class="scheme-cell"><span>Starting Rate</span><strong
                                                id="scRate">-</strong></div>
                                        <div class="scheme-cell"><span>Interest Period</span><strong
                                                id="scCycle">-</strong></div>
                                        <div class="scheme-cell"><span>Grace</span><strong id="scGrace">-</strong></div>
                                        <div class="scheme-cell"><span>Pawn Tenure</span><strong
                                                id="scTenure">-</strong></div>
                                    </div>
                                    <div class="rate-ladder" id="rateLadder"></div>
                                    <div class="danger-note mt-2" id="escalationNote"></div>
                                </div>
                            </div>
                        </div>

                        <div class="page-card mb-3">
                            <div class="page-head">
                                <div class="section-title">Pledged Gold / Items</div><button type="button"
                                    class="btn-soft" id="addItemBtn"><i class="fa-solid fa-plus"></i> Add Item</button>
                            </div>
                            <div class="card-body-x" id="itemsWrap"></div>
                        </div>

                        <div class="page-card mb-3">
                            <div class="page-head">
                                <div class="section-title">KYC & Notes</div>
                            </div>
                            <div class="card-body-x">
                                <div class="row g-3">
                                    <div class="col-md-3"><label class="form-label">ID Proof Type</label><select
                                            name="id_proof_type" id="idProofType" class="form-select">
                                            <option value="">Select</option>
                                            <option>Aadhar</option>
                                            <option>PAN</option>
                                            <option>Voter ID</option>
                                            <option>Driving Licence</option>
                                            <option>Passport</option>
                                            <option>Other</option>
                                        </select></div>
                                    <div class="col-md-4"><label class="form-label">ID Proof Number</label><input
                                            name="id_proof_number" id="idProofNumber" class="form-control"></div>
                                    <div class="col-md-5"><label class="form-label">ID Proof File</label><input
                                            type="file" name="id_proof_image" id="idProofImage" class="form-control"
                                            accept="image/jpeg,image/png,image/webp,application/pdf"><input
                                            type="hidden" name="existing_id_proof_image" id="existingProof"></div>
                                    <div class="col-12">
                                        <div class="proof-preview" id="proofPreview">Customer existing proof / newly
                                            selected file will be used.</div>
                                    </div>
                                    <div class="col-12"><label class="form-label">Remarks</label><textarea
                                            name="remarks" class="form-control" rows="2"></textarea></div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <aside class="sticky">
                        <div class="page-card mb-3">
                            <div class="page-head">
                                <div class="section-title">Loan & Disbursement</div>
                            </div>
                            <div class="card-body-x">
                                <div class="loan-value mb-3"><span class="small text-muted">Amount Given to
                                        Customer</span><strong>Rs. <span id="amountGiven">0.00</span></strong></div>
                                <div class="mb-2"><label class="form-label">Principal Amount *</label><input
                                        type="number" step="0.01" min="0" name="principal_amount" id="principalAmount"
                                        class="form-control" required></div>
                                <div class="row g-2">
                                    <div class="col-6"><label class="form-label">Document Charge</label><input
                                            type="number" step="0.01" min="0" name="document_charge" id="documentCharge"
                                            class="form-control" value="0"></div>
                                    <div class="col-6"><label class="form-label">Other Charge</label><input
                                            type="number" step="0.01" min="0" name="other_charge" id="otherCharge"
                                            class="form-control" value="0"></div>
                                </div>
                                <div class="mt-2"><label class="form-label">Disbursement Method *</label><select
                                        name="disbursement_payment_method_id" id="paymentMethod" class="form-select"
                                        required>
                                        <option value="">Select Method</option>
                                    </select></div>
                                <div class="mt-2"><label class="form-label">Payment Reference</label><input
                                        name="payment_reference" class="form-control"></div>
                            </div>
                        </div>
                        <div class="page-card">
                            <div class="page-head">
                                <div class="section-title">Pawn Summary</div>
                            </div>
                            <div class="card-body-x">
                                <div class="summary-line"><span>Items</span><strong id="itemCount">0</strong></div>
                                <div class="summary-line"><span>Gross Weight</span><strong id="totalGross">0.000
                                        g</strong></div>
                                <div class="summary-line"><span>Stone Weight</span><strong id="totalStone">0.000
                                        g</strong></div>
                                <div class="summary-line"><span>Net Weight</span><strong id="totalNet">0.000 g</strong>
                                </div>
                                <div class="summary-line"><span>Estimated Value</span><strong>Rs. <span
                                            id="totalEstimated">0.00</span></strong></div>
                                <div class="summary-line"><span>Maximum Eligible</span><strong>Rs. <span
                                            id="maxEligible">0.00</span></strong></div>
                                <div class="summary-line"><span>Interest / Cycle</span><strong>Rs. <span
                                            id="cycleInterest">0.00</span></strong></div>
                                <div class="summary-line"><span>First Due</span><strong id="summaryDue">-</strong></div>
                                <button type="submit" class="btn-theme w-100 mt-3" id="saveBtn"><i
                                        class="fa-solid fa-floppy-disk"></i> <?= $editId > 0 ? 'Update Pawn Entry' : 'Save Pawn Entry' ?></button>
                            </div>
                        </div>
                    </aside>
                </div>
            </form>
            <?php include('includes/footer.php'); ?>
        </div>
    </main>
    <?php include('includes/script.php'); ?>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0/dist/js/select2.min.js"></script>
    <script src="assets/js/script.js"></script>
    <script>
        (function () {
            'use strict';
            var api = 'api/pawn-entry.php', csrf = <?= json_encode($csrfToken) ?>, reregisterFrom = <?= (int) $reregisterFrom ?>, editId = <?= (int) $editId ?>;
            var data = { customers: [], categories: [], metals: [], metal_rates: [], payment_methods: [], schemes: [] };
            var maxLoanPercent = 100;
            function $(id) { return document.getElementById(id) }
            function esc(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c] }) }
            function money(v) { return Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }
            function toast(ok, msg) { var x = document.createElement('div'); x.className = 'theme-toast ' + (ok ? 'theme-toast-success' : 'theme-toast-error'); x.textContent = msg; document.body.appendChild(x); setTimeout(function () { x.remove() }, 3800) }
            async function req(obj) { var f = new FormData(); Object.keys(obj).forEach(function (k) { f.append(k, obj[k]) }); f.append('csrf_token', csrf); var r = await fetch(api, { method: 'POST', body: f, credentials: 'same-origin', headers: { Accept: 'application/json' } }); var raw = await r.text(); var j; try { j = JSON.parse(raw) } catch (e) { throw new Error('Pawn Entry API returned invalid JSON. HTTP ' + r.status + ': ' + raw.replace(/<[^>]*>/g, ' ').slice(0, 300)) } if (!r.ok || !j.success) throw new Error(j.message || 'Request failed'); return j }
            function selectedCustomer() { return data.customers.find(function (x) { return String(x.id) === String($('customerSelect').value) }) || null }
            function selectedScheme() { return data.schemes.find(function (x) { return String(x.id) === String($('schemeSelect').value) }) || null }
            function selectedCategory() { return data.categories.find(function (x) { return String(x.id) === String($('categorySelect').value) }) || null }
            function selectedFirstStep() { var s = selectedScheme(); return s && s.steps && s.steps.length ? s.steps[0] : null }
            function metalOptions(sel) { return data.metals.map(function (m) { return '<option value="' + m.id + '" ' + (String(sel || '') === String(m.id) ? 'selected' : '') + '>' + esc(m.metal_name) + '</option>' }).join('') }
            function itemRow(item) {
                item = item || {}; return '<div class="item-box">' +
                    '<input type="hidden" name="item_id[]" value="' + esc(item.id || '') + '">' +
                    '<input type="hidden" name="existing_item_image[]" value="' + esc(item.image_path || '') + '">' +
                    '<div class="d-flex justify-content-between align-items-center"><div class="item-title">Pawn Item</div><button type="button" class="btn-soft remove-item">Remove</button></div>' +
                    '<div class="row g-2">' +
                    '<div class="col-md-2"><label class="form-label">Metal *</label><select name="metal_id[]" class="form-select item-metal" required><option value="">Select</option>' + metalOptions(item.metal_id) + '</select></div>' +
                    '<div class="col-md-4"><label class="form-label">Description *</label><input name="item_description[]" class="form-control" value="' + esc(item.item_description || '') + '" required></div>' +
                    '<div class="col-md-2"><label class="form-label">Qty *</label><input type="number" step="0.001" min="0.001" name="quantity[]" class="form-control qty" value="' + esc(item.quantity || 1) + '" required></div>' +
                    '<div class="col-md-2"><label class="form-label">Purity</label><input name="purity[]" class="form-control purity" value="' + esc(item.purity || '') + '" placeholder="916 / 22K"></div>' +
                    '<div class="col-md-2"><label class="form-label">Rate / g</label><input type="number" step="0.01" min="0" name="rate_per_gram[]" class="form-control rate" ' + (item.rate_per_gram ? 'data-manual="1" ' : '') + 'value="' + esc(item.rate_per_gram || '') + '"></div>' +
                    '<div class="col-md-3"><label class="form-label">Gross Weight *</label><input type="number" step="0.001" min="0" name="gross_weight[]" class="form-control gross" value="' + esc(item.gross_weight || '') + '" required></div>' +
                    '<div class="col-md-3"><label class="form-label">Stone Weight</label><input type="number" step="0.001" min="0" name="stone_weight[]" class="form-control stone" value="' + esc(item.stone_weight || 0) + '"></div>' +
                    '<div class="col-md-3"><label class="form-label">Net Weight</label><input type="number" step="0.001" name="net_weight[]" class="form-control net" readonly></div>' +
                    '<div class="col-md-3"><label class="form-label">Estimated Value</label><input type="number" step="0.01" name="estimated_value[]" class="form-control est" readonly></div>' +
                    '<div class="col-md-8"><label class="form-label">Item Remark</label><input name="item_remarks[]" class="form-control" value="' + esc(item.item_remarks || '') + '"></div>' +
                    '<div class="col-md-4"><label class="form-label">Item Photo</label><input type="file" name="item_image[]" class="form-control" accept="image/jpeg,image/png,image/webp"></div>' +
                    '</div></div>'
            }
            function normalizedPurity(v) { return String(v || '').toLowerCase().replace(/\s+/g, '') }
            function rateFor(metalId, purity) { var p = normalizedPurity(purity), exact = null, fallback = null; data.metal_rates.forEach(function (r) { if (String(r.metal_id) !== String(metalId)) return; if (fallback === null) fallback = Number(r.rate_per_gram || 0); if (normalizedPurity(r.purity) === p) exact = Number(r.rate_per_gram || 0) }); return exact !== null ? exact : (fallback !== null ? fallback : 0) }
            function updateRate(box) { var m = box.querySelector('.item-metal').value, p = box.querySelector('.purity').value, rate = rateFor(m, p); if (rate > 0 && !box.querySelector('.rate').dataset.manual) box.querySelector('.rate').value = rate.toFixed(2) }
            function totals() { var g = 0, st = 0, n = 0, v = 0, c = 0; document.querySelectorAll('.item-box').forEach(function (box, i) { box.querySelector('.item-title').textContent = 'Pawn Item ' + (i + 1); var gross = Number(box.querySelector('.gross').value || 0), stone = Number(box.querySelector('.stone').value || 0), net = Math.max(0, gross - stone), rate = Number(box.querySelector('.rate').value || 0), est = net * rate; box.querySelector('.net').value = net.toFixed(3); box.querySelector('.est').value = est.toFixed(2); g += gross; st += stone; n += net; v += est; c++ }); var eligible = v * (maxLoanPercent / 100); $('itemCount').textContent = c; $('totalGross').textContent = g.toFixed(3) + ' g'; $('totalStone').textContent = st.toFixed(3) + ' g'; $('totalNet').textContent = n.toFixed(3) + ' g'; $('totalEstimated').textContent = money(v); $('maxEligible').textContent = money(eligible); $('totalGrossInput').value = g.toFixed(3); $('totalStoneInput').value = st.toFixed(3); $('totalNetInput').value = n.toFixed(3); $('totalEstimatedInput').value = v.toFixed(2); if (!$('principalAmount').dataset.manual && eligible > 0) $('principalAmount').value = eligible.toFixed(2); updateLoan(); updateSchemeCard() }
            function updateLoan() { var principal = Number($('principalAmount').value || 0), doc = Number($('documentCharge').value || 0), other = Number($('otherCharge').value || 0), given = Math.max(0, principal - doc - other); $('disbursementInput').value = given.toFixed(2); $('amountGiven').textContent = money(given) }
            function addDate(dateStr, type, value) { if (!dateStr) return ''; var d = new Date(dateStr + 'T00:00:00'); value = Math.max(1, Number(value || 1)); if (type === 'Days') d.setDate(d.getDate() + value); else d.setMonth(d.getMonth() + value); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0') }
            function updateSchemeCard() { var s = selectedScheme(), step = selectedFirstStep(); if (!s || !step) { $('schemeCard').classList.add('d-none'); $('firstDue').value = ''; $('summaryDue').textContent = '-'; $('cycleInterest').textContent = '0.00'; return } $('schemeCard').classList.remove('d-none'); $('scRate').textContent = Number(step.rate_percent).toFixed(3) + '%'; var cycle = step.interest_cycle_type === 'Calendar Month' ? 'Every calendar month' : ('Every ' + step.interest_cycle_value + ' ' + step.interest_cycle_type.toLowerCase()); $('scCycle').textContent = cycle; $('scGrace').textContent = Number(step.grace_days || 0) + ' day(s)'; $('scTenure').textContent = s.tenure_type === 'At Closure' ? 'Until closure' : s.tenure_months + ' month(s)'; var due = addDate($('pawnDate').value, step.interest_cycle_type, step.interest_cycle_value); $('firstDue').value = due; $('summaryDue').textContent = due || '-'; var principal = Number($('principalAmount').value || 0); $('cycleInterest').textContent = money(principal * Number(step.rate_percent || 0) / 100); $('rateLadder').innerHTML = s.steps.map(function (x, i) { return '<span class="rate-step ' + (i === 0 ? 'current' : '') + '">Level ' + x.level_no + ': ' + Number(x.rate_percent).toFixed(3) + '%</span>' }).join(''); var next = s.steps.find(function (x) { return Number(x.level_no) === Number(step.next_level_no) }); $('escalationNote').textContent = next ? 'If this interest due is missed after the grace period, the rate becomes ' + Number(next.rate_percent).toFixed(3) + '% from the ' + (step.escalation_effective === 'Immediately' ? 'current' : 'next') + ' cycle and stays there until this pawn is closed.' : 'This is the final configured interest level for this scheme.' }
            function updateCustomer() { var c = selectedCustomer(); $('infoCode').textContent = c ? c.customer_code || '-' : '-'; $('infoMobile').textContent = c ? c.mobile || '-' : '-'; $('infoKyc').textContent = c ? (Number(c.kyc_verified || 0) ? 'Verified' : 'Not Verified') : '-'; $('infoRisk').textContent = c ? c.risk_category || '-' : '-'; if (c) { if (!$('idProofType').value) $('idProofType').value = c.id_proof_type || ''; if (!$('idProofNumber').value) $('idProofNumber').value = c.id_proof_number || ''; $('existingProof').value = c.id_proof_image || ''; if (c.id_proof_image) $('proofPreview').textContent = 'Existing proof: ' + c.id_proof_image } }
            function initSelect2() { if (window.jQuery && jQuery.fn && jQuery.fn.select2) { jQuery('#customerSelect').select2({ placeholder: 'Search customer by name / code / mobile', allowClear: true, width: '100%' }); jQuery('#customerSelect').on('change', updateCustomer) } }
            function applyCategory() { var c = selectedCategory(); maxLoanPercent = c && c.max_loan_percent != null && c.max_loan_percent !== '' ? Number(c.max_loan_percent) : 100; $('categoryPercent').textContent = c ? maxLoanPercent.toFixed(2) + '%' : '-'; if (c && c.metal_type) { var metal = data.metals.find(function (m) { return String(m.metal_name).toLowerCase() === String(c.metal_type).toLowerCase() }); if (metal) { $('primaryMetal').value = metal.id; document.querySelectorAll('.item-metal').forEach(function (x) { if (!x.value) x.value = metal.id }) } } if (c && c.purity_standard) document.querySelectorAll('.purity').forEach(function (x) { if (!x.value) x.value = c.purity_standard }); document.querySelectorAll('.item-box').forEach(updateRate); totals() }

            function setLocked(disabled) {
                ['pawnDate','customerSelect','categorySelect','schemeSelect','primaryMetal','principalAmount','documentCharge','otherCharge','paymentMethod'].forEach(function (id) { if ($(id)) $(id).disabled = !!disabled; });
                ['loan_type','payment_reference'].forEach(function (name) { var x = document.querySelector('[name="' + name + '"]'); if (x) x.disabled = !!disabled; });
                document.querySelectorAll('#itemsWrap input, #itemsWrap select, #itemsWrap button').forEach(function (x) { x.disabled = !!disabled; });
                $('addItemBtn').disabled = !!disabled;
                if (window.jQuery && jQuery.fn && jQuery.fn.select2) jQuery('#customerSelect').trigger('change.select2');
            }
            function applyEdit(r) {
                if (!r) return;
                $('pawnNo').value = r.pawn_no || '';
                $('pawnDate').value = r.pawn_date || '';
                $('customerSelect').value = r.customer_id || '';
                if (window.jQuery) jQuery('#customerSelect').trigger('change');
                $('categorySelect').value = r.pawn_category_id || '';
                $('schemeSelect').value = r.interest_scheme_id || '';
                $('primaryMetal').value = r.primary_metal_id || '';
                document.querySelector('[name="loan_type"]').value = r.loan_type || 'General';
                $('principalAmount').value = Number(r.principal_amount || 0).toFixed(2);
                $('principalAmount').dataset.manual = '1';
                $('documentCharge').value = Number(r.document_charge || 0).toFixed(2);
                $('otherCharge').value = Number(r.other_charge || 0).toFixed(2);
                $('paymentMethod').value = r.disbursement_payment_method_id || r.payment_method_id || '';
                document.querySelector('[name="payment_reference"]').value = r.payment_reference || '';
                $('idProofType').value = r.id_proof_type || '';
                $('idProofNumber').value = r.id_proof_number || '';
                $('existingProof').value = r.id_proof_image || r.id_proof_image_path || '';
                document.querySelector('[name="remarks"]').value = r.remarks || '';
                if ($('existingProof').value) $('proofPreview').textContent = 'Existing proof: ' + $('existingProof').value;
                $('itemsWrap').innerHTML = '';
                (r.items || []).forEach(function (it) { $('itemsWrap').insertAdjacentHTML('beforeend', itemRow(it)); });
                if (!(r.items || []).length) $('itemsWrap').innerHTML = itemRow();
                applyCategory();
                updateSchemeCard();
                totals();
                if (r.financial_locked) {
                    $('reregisterNotice').classList.remove('d-none');
                    $('reregisterNotice').textContent = r.lock_reason || 'Financial fields are locked because transactions already exist. You can update KYC and remarks only.';
                    setLocked(true);
                }
            }
            function applyReregister(r) { if (!r) return; $('reregisterNotice').classList.remove('d-none'); $('reregisterNotice').textContent = 'Re-registering closed pawn ' + r.pawn_no + '. A new pawn number and a new interest contract will be created.'; $('customerSelect').value = r.customer_id; if (window.jQuery) jQuery('#customerSelect').trigger('change'); $('categorySelect').value = r.pawn_category_id; $('idProofType').value = r.id_proof_type || ''; $('idProofNumber').value = r.id_proof_number || ''; $('existingProof').value = r.id_proof_image || ''; $('itemsWrap').innerHTML = ''; (r.items || []).forEach(function (it) { $('itemsWrap').insertAdjacentHTML('beforeend', itemRow(it)) }); if (!(r.items || []).length) $('itemsWrap').innerHTML = itemRow(); applyCategory(); totals() }
            async function init() { try { data = await req({ action: 'options', reregister_from: reregisterFrom, edit_id: editId }); $('pawnNo').value = data.edit ? data.edit.pawn_no : data.next_pawn_no; $('categorySelect').innerHTML = '<option value="">Select category</option>' + data.categories.map(function (c) { return '<option value="' + c.id + '">' + esc(c.category_name) + ' (' + esc(c.category_code || '') + ') · Max ' + Number(c.max_loan_percent || 0).toFixed(2) + '%</option>' }).join(''); $('customerSelect').innerHTML = '<option value=""></option>' + data.customers.map(function (c) { return '<option value="' + c.id + '">' + esc(c.customer_name) + ' · ' + esc(c.customer_code || '') + ' · ' + esc(c.mobile || '') + '</option>' }).join(''); $('primaryMetal').innerHTML = '<option value="">Select metal</option>' + metalOptions(); $('paymentMethod').innerHTML = '<option value="">Select Method</option>' + data.payment_methods.map(function (m) { return '<option value="' + m.id + '">' + esc(m.method_name) + '</option>' }).join(''); $('schemeSelect').innerHTML = '<option value="">Select interest scheme</option>' + data.schemes.map(function (s) { var st = s.steps && s.steps.length ? s.steps[0] : null; return '<option value="' + s.id + '">' + esc(s.scheme_name) + ' · ' + (st ? Number(st.rate_percent).toFixed(3) + '%' : 'No rate') + '</option>' }).join(''); $('documentCharge').value = Number(data.general.pawn_default_document_charge || 0).toFixed(2); $('otherCharge').value = Number(data.general.pawn_default_other_charge || 0).toFixed(2); $('itemsWrap').innerHTML = itemRow(); initSelect2(); if (data.edit) applyEdit(data.edit); else if (data.reregister) applyReregister(data.reregister); else { applyCategory(); totals(); } } catch (e) { toast(false, e.message) } }
            $('addItemBtn').onclick = function () { $('itemsWrap').insertAdjacentHTML('beforeend', itemRow()); totals() }; document.addEventListener('click', function (e) { var b = e.target.closest('.remove-item'); if (b) { if (document.querySelectorAll('.item-box').length <= 1) return toast(false, 'At least one pawn item is required.'); b.closest('.item-box').remove(); totals() } }); document.addEventListener('input', function (e) { if (e.target.classList.contains('gross') || e.target.classList.contains('stone') || e.target.classList.contains('qty')) totals(); if (e.target.classList.contains('purity')) { updateRate(e.target.closest('.item-box')); totals() } if (e.target.classList.contains('rate')) { e.target.dataset.manual = '1'; totals() } }); document.addEventListener('change', function (e) { if (e.target.classList.contains('item-metal')) { updateRate(e.target.closest('.item-box')); totals() } });
            $('categorySelect').onchange = applyCategory; $('schemeSelect').onchange = updateSchemeCard; $('pawnDate').onchange = updateSchemeCard; $('principalAmount').oninput = function (e) { e.target.dataset.manual = '1'; updateLoan(); updateSchemeCard() }; $('documentCharge').oninput = updateLoan; $('otherCharge').oninput = updateLoan; $('customerSelect').onchange = updateCustomer; $('idProofImage').onchange = function () { var f = this.files && this.files[0]; $('proofPreview').textContent = f ? 'Selected: ' + f.name : 'No new proof selected' };
            $('pawnForm').onsubmit = async function (e) { e.preventDefault(); totals(); if (!$('customerSelect').value) return toast(false, 'Select customer.'); if (!$('categorySelect').value) return toast(false, 'Select pawn category.'); if (!$('schemeSelect').value) return toast(false, 'Select interest scheme.'); if (Number($('totalNetInput').value || 0) <= 0) return toast(false, 'Total net weight must be greater than zero.'); var principal = Number($('principalAmount').value || 0), eligible = Number(String($('maxEligible').textContent).replace(/,/g, '') || 0); if (principal <= 0) return toast(false, 'Principal amount must be greater than zero.'); if (principal > eligible + 0.01) return toast(false, 'Principal cannot exceed the category maximum eligible loan.'); if (Number($('disbursementInput').value || 0) <= 0) return toast(false, 'Amount given must be greater than zero after charges.'); if (!$('paymentMethod').value) return toast(false, 'Select disbursement payment method.'); var btn = $('saveBtn'), old = btn.innerHTML; btn.disabled = true; btn.textContent = editId > 0 ? 'Updating...' : 'Saving...'; try { if (editId > 0 && data.edit && data.edit.financial_locked) setLocked(false); var fd = new FormData(this); if (editId > 0 && data.edit && data.edit.financial_locked) setLocked(true); var r = await fetch(api, { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'application/json' } }); var raw = await r.text(), j; try { j = JSON.parse(raw) } catch (x) { throw new Error('Invalid API response: ' + raw.replace(/<[^>]*>/g, ' ').slice(0, 300)) } if (!r.ok || !j.success) throw new Error(j.message || 'Unable to save pawn entry.'); toast(true, j.message); setTimeout(function () { location.href = 'pawn-view.php?id=' + j.pawn_id }, 700) } catch (err) { toast(false, err.message) } finally { btn.disabled = false; btn.innerHTML = old } };
            init();
        })();
    </script>
</body>

</html>