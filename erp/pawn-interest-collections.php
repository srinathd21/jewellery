<?php
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
require __DIR__ . '/_common.php';
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($businessName) ?> - Interest Collections</title>
    <?php include('includes/links.php');
    require __DIR__ . '/_style.php'; ?>
    <style>
        .filter-grid {
            display: grid;
            grid-template-columns: 1.5fr repeat(4, minmax(145px, .7fr));
            gap: 8px
        }

        .filter-grid-2 {
            display: grid;
            grid-template-columns: repeat(6, minmax(130px, 1fr));
            gap: 8px;
            margin-top: 8px
        }

        .stat-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 10px;
            margin-bottom: 12px
        }

        .stat-box {
            padding: 13px;
            border: 1px solid var(--line);
            border-radius: var(--radius);
            background: var(--card-bg)
        }

        .stat-box span {
            display: block;
            font-size: 9px;
            color: var(--muted);
            text-transform: uppercase
        }

        .stat-box strong {
            display: block;
            margin-top: 4px;
            font-size: 19px
        }

        .collection-table {
            font-size: 10px;
            margin: 0
        }

        .collection-table th {
            font-size: 9px;
            text-transform: uppercase;
            color: var(--muted);
            white-space: nowrap;
            padding: 10px
        }

        .collection-table td {
            padding: 10px;
            vertical-align: middle
        }

        .status-pill {
            display: inline-flex;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 9px;
            font-weight: 800
        }

        .status-ok {
            background: #eaf8f0;
            color: #168449
        }

        .status-reversed {
            background: #fdecec;
            color: #b42318
        }

        .mini-btn {
            width: 31px;
            height: 31px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: var(--card-bg);
            display: grid;
            place-items: center;
            text-decoration: none;
            color: inherit
        }

        .mini-btn:hover {
            background: var(--primary-soft);
            color: var(--primary-dark)
        }

        .mini-btn.whatsapp {
            color: #168449
        }

        .mini-btn.whatsapp:hover {
            background: #eaf8f0;
            color: #168449
        }

        .quick-row {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            margin-top: 8px
        }

        .quick-btn {
            border: 1px solid var(--line);
            background: var(--card-bg);
            border-radius: 8px;
            padding: 7px 10px;
            font-size: 10px
        }

        .quick-btn.active,
        .quick-btn:hover {
            background: var(--primary-soft);
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
            font-weight: 700
        }

        .theme-toast-error {
            background: #c0392b
        }

        .theme-toast-success {
            background: #168449
        }

        @media(max-width:1200px) {
            .filter-grid {
                grid-template-columns: 1fr 1fr
            }

            .filter-grid .wide {
                grid-column: 1/-1
            }

            .filter-grid-2 {
                grid-template-columns: repeat(3, 1fr)
            }

            .stat-grid {
                grid-template-columns: repeat(3, 1fr)
            }
        }

        @media(max-width:767px) {

            .filter-grid,
            .filter-grid-2 {
                grid-template-columns: 1fr
            }

            .filter-grid .wide {
                grid-column: auto
            }

            .stat-grid {
                grid-template-columns: 1fr 1fr
            }
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
                        <div class="page-title">Interest Collections</div>
                        <div class="small text-muted">Already collected pawn interest records with customer and
                            pawn-wise filters.</div>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <a href="pawn-payment.php" class="btn-theme"><i class="fa-solid fa-indian-rupee-sign"></i>
                            Collect Interest</a>
                        <button type="button" id="exportBtn" class="btn-soft"><i class="fa-solid fa-file-csv"></i>
                            Export CSV</button>
                    </div>
                </div>
            </div>

            <div class="stat-grid">
                <div class="stat-box"><span>Total Collections</span><strong id="stCount">0</strong></div>
                <div class="stat-box"><span>Interest Amount</span><strong>₹<span id="stInterest">0.00</span></strong>
                </div>
                <div class="stat-box"><span>Penalty Amount</span><strong>₹<span id="stPenalty">0.00</span></strong>
                </div>
                <div class="stat-box"><span>Other Charges</span><strong>₹<span id="stOther">0.00</span></strong></div>
                <div class="stat-box"><span>Total Collected</span><strong>₹<span id="stTotal">0.00</span></strong></div>
            </div>

            <div class="page-card mb-3">
                <div class="card-body-x">
                    <div class="filter-grid">
                        <input type="search" id="search" class="form-control wide"
                            placeholder="Receipt no, pawn no, customer name, code, mobile or reference">
                        <select id="customerId" class="form-select">
                            <option value="">All Customers</option>
                        </select>
                        <select id="pawnId" class="form-select">
                            <option value="">All Pawns</option>
                        </select>
                        <input type="date" id="fromDate" class="form-control">
                        <input type="date" id="toDate" class="form-control">
                    </div>
                    <div class="filter-grid-2">
                        <select id="branchId" class="form-select">
                            <option value="">All Branches</option>
                        </select>
                        <select id="paymentMethodId" class="form-select">
                            <option value="">All Payment Methods</option>
                        </select>
                        <select id="collectorId" class="form-select">
                            <option value="">All Collectors</option>
                        </select>
                        <select id="collectionType" class="form-select">
                            <option value="">All Collection Types</option>
                        </select>
                        <input type="number" id="minAmount" class="form-control" min="0" step="0.01"
                            placeholder="Minimum total">
                        <input type="number" id="maxAmount" class="form-control" min="0" step="0.01"
                            placeholder="Maximum total">
                    </div>
                    <div class="filter-grid-2">
                        <select id="recordStatus" class="form-select">
                            <option value="">All Records</option>
                            <option value="active">Active Only</option>
                            <option value="reversed">Reversed Only</option>
                        </select>
                        <select id="pawnStatus" class="form-select">
                            <option value="">All Pawn Statuses</option>
                            <option>Draft</option>
                            <option>Active</option>
                            <option>Partially Paid</option>
                            <option>Closed</option>
                            <option>Auctioned</option>
                            <option>Cancelled</option>
                        </select>
                        <select id="sortBy" class="form-select">
                            <option value="latest">Latest First</option>
                            <option value="oldest">Oldest First</option>
                            <option value="amount_desc">Highest Amount</option>
                            <option value="amount_asc">Lowest Amount</option>
                        </select>
                        <button type="button" id="applyBtn" class="btn-theme"><i class="fa-solid fa-filter"></i>
                            Apply</button>
                        <button type="button" id="resetBtn" class="btn-soft"><i class="fa-solid fa-rotate-left"></i>
                            Reset</button>
                        <div></div>
                    </div>
                    <div class="quick-row">
                        <button type="button" class="quick-btn" data-range="today">Today</button>
                        <button type="button" class="quick-btn" data-range="yesterday">Yesterday</button>
                        <button type="button" class="quick-btn" data-range="week">This Week</button>
                        <button type="button" class="quick-btn" data-range="month">This Month</button>
                        <button type="button" class="quick-btn" data-range="fy">Financial Year</button>
                        <button type="button" class="quick-btn" data-range="all">All Time</button>
                    </div>
                </div>
            </div>

            <div class="page-card">
                <div class="table-responsive">
                    <table class="table collection-table align-middle">
                        <thead>
                            <tr>
                                <th>Receipt / Date</th>
                                <th>Customer</th>
                                <th>Pawn</th>
                                <th>Interest Period</th>
                                <th>Interest</th>
                                <th>Penalty</th>
                                <th>Other</th>
                                <th>Total</th>
                                <th>Payment</th>
                                <th>Collector</th>
                                <th>Status</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody id="tableBody"></tbody>
                    </table>
                </div>
                <div id="emptyState" class="text-center text-muted p-5 d-none">No interest collections found for the
                    selected filters.</div>
            </div>

            <?php include('includes/footer.php'); ?>
        </div>
    </main>
    <?php include('includes/script.php'); ?>
    <script src="assets/js/script.js"></script>
    <script>
        (() => {
            'use strict';
            const api = 'api/pawn-interest-collections.php';
            const csrf = <?= json_encode($csrfToken) ?>;
            const $ = id => document.getElementById(id);
            let rows = [], timer = null;

            function esc(v) { return String(v ?? '').replace(/[&<>'"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[c])) }
            function money(v) { return Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }
            function dateView(v) { if (!v) return '—'; const p = String(v).split('-'); return p.length === 3 ? `${p[2]}-${p[1]}-${p[0]}` : v }
            function note(t, m) { const x = document.createElement('div'); x.className = 'theme-toast theme-toast-' + (t === 'ok' ? 'success' : 'error'); x.textContent = m; document.body.appendChild(x); setTimeout(() => x.remove(), 3500) }
            async function req(data) {
                const f = new FormData();
                Object.entries(data).forEach(([k, v]) => f.append(k, v ?? ''));
                f.append('csrf_token', csrf);
                const r = await fetch(api, { method: 'POST', body: f, credentials: 'same-origin', headers: { Accept: 'application/json' } });
                const raw = await r.text(); let j;
                try { j = JSON.parse(raw) } catch (e) { throw new Error(raw.replace(/<[^>]*>/g, ' ').slice(0, 300)) }
                if (!r.ok || !j.success) throw new Error(j.message || 'Request failed');
                return j;
            }
            function addOptions(id, rows, valueKey, textFn) {
                const s = $(id), first = s.options[0].outerHTML;
                s.innerHTML = first + rows.map(r => `<option value="${esc(r[valueKey])}">${esc(textFn(r))}</option>`).join('');
            }
            async function loadOptions() {
                try {
                    const j = await req({ action: 'options' });
                    addOptions('customerId', j.customers || [], 'id', r => `${r.customer_name} - ${r.customer_code || ''} - ${r.mobile || ''}`);
                    addOptions('pawnId', j.pawns || [], 'id', r => `${r.pawn_no} - ${r.customer_name || ''}`);
                    addOptions('branchId', j.branches || [], 'id', r => r.branch_name);
                    addOptions('paymentMethodId', j.payment_methods || [], 'id', r => r.method_name);
                    addOptions('collectorId', j.collectors || [], 'id', r => r.full_name || r.username);
                    if (j.collection_types?.length) {
                        $('collectionType').innerHTML = '<option value="">All Collection Types</option>' + j.collection_types.map(v => `<option value="${esc(v)}">${esc(v)}</option>`).join('');
                        $('collectionType').disabled = false;
                    } else { $('collectionType').disabled = true }
                    if (!j.features?.reversal) { $('recordStatus').disabled = true }
                } catch (e) { note('bad', e.message) }
            }
            function filters() {
                return {
                    action: 'list',
                    search: $('search').value.trim(),
                    customer_id: $('customerId').value,
                    pawn_id: $('pawnId').value,
                    from_date: $('fromDate').value,
                    to_date: $('toDate').value,
                    branch_id: $('branchId').value,
                    payment_method_id: $('paymentMethodId').value,
                    collector_id: $('collectorId').value,
                    collection_type: $('collectionType').value,
                    min_amount: $('minAmount').value,
                    max_amount: $('maxAmount').value,
                    record_status: $('recordStatus').value,
                    pawn_status: $('pawnStatus').value,
                    sort_by: $('sortBy').value
                };
            }

            function whatsappNumber(value) {
                let number = String(value || '').replace(/\D+/g, '');
                if (number.length === 10) number = '91' + number;
                else if (number.length === 11 && number.startsWith('0')) number = '91' + number.slice(1);
                return number;
            }

            function interestReceiptRelativeUrl(r) {
                return 'pawn-interest-receipt.php?collection_id='
                    + encodeURIComponent(r.id)
                    + '&receipt=' + encodeURIComponent(r.receipt_no || '');
            }

            function absoluteUrl(path) {
                const current = new URL(window.location.href);
                const dir = current.pathname.substring(0, current.pathname.lastIndexOf('/') + 1);
                return current.origin + dir + path.replace(/^\/+/, '');
            }

            function interestWhatsappUrl(r) {
                const mobile = whatsappNumber(r.mobile);
                if (!mobile) return '';

                const relative = interestReceiptRelativeUrl(r);
                const url = absoluteUrl(relative);

                const message =
                    'Dear ' + (r.customer_name || 'Customer') + ',\n\n'
                    + 'Your pawn interest receipt is ready.\n'
                    + 'Pawn No: ' + (r.pawn_no || '') + '\n'
                    + 'Receipt: ' + (r.receipt_no || '') + '\n'
                    + 'Interest: ₹' + money(r.interest_amount || 0) + '\n'
                    + 'Penalty: ₹' + money(r.penalty_amount || 0) + '\n'
                    + 'Other Charges: ₹' + money(r.other_charges || 0) + '\n'
                    + 'Total Paid: ₹' + money(r.total_amount || 0) + '\n\n'
                    + 'View receipt:\n' + url + '\n\n'
                    + 'Thank you.';

                return 'https://wa.me/' + mobile + '?text=' + encodeURIComponent(message);
            }

            function render() {
                $('tableBody').innerHTML = rows.map(r => `<tr>
  <td><strong>${esc(r.receipt_no)}</strong><div class="text-muted">${dateView(r.collection_date)}</div></td>
  <td><strong>${esc(r.customer_name || '—')}</strong><div class="text-muted">${esc(r.customer_code || '')} · ${esc(r.mobile || '')}</div></td>
  <td><strong>${esc(r.pawn_no || '—')}</strong><div class="text-muted">${esc(r.branch_name || '')} · ${esc(r.pawn_status || '')}</div></td>
  <td>${dateView(r.from_date)} to ${dateView(r.to_date)}<div class="text-muted">${esc(r.collection_type || 'Interest')}</div></td>
  <td>₹${money(r.interest_amount)}</td>
  <td>₹${money(r.penalty_amount)}</td>
  <td>₹${money(r.other_charges)}</td>
  <td><strong>₹${money(r.total_amount)}</strong></td>
  <td>${esc(r.method_name || '—')}<div class="text-muted">${esc(r.reference_no || '')}</div></td>
  <td>${esc(r.collector_name || '—')}</td>
  <td>${Number(r.is_reversed || 0) === 1 ? '<span class="status-pill status-reversed">Reversed</span>' : '<span class="status-pill status-ok">Collected</span>'}</td>
  <td><div class="d-flex justify-content-end gap-1">
   <a class="mini-btn" href="pawn-view.php?id=${encodeURIComponent(r.pawn_entry_id)}" title="View Pawn">
    <i class="fa-solid fa-eye"></i>
   </a>
   <a class="mini-btn" href="${interestReceiptRelativeUrl(r)}" target="_blank" rel="noopener" title="Interest Receipt">
    <i class="fa-solid fa-receipt"></i>
   </a>
   ${interestWhatsappUrl(r) ? `<a class="mini-btn whatsapp" href="${interestWhatsappUrl(r)}" target="_blank" rel="noopener" title="Share Receipt on WhatsApp"><i class="fa-brands fa-whatsapp"></i></a>` : ''}
  </div></td>
 </tr>`).join('');
                $('emptyState').classList.toggle('d-none', rows.length > 0);
            }
            async function load() {
                try {
                    const j = await req(filters());
                    rows = j.rows || []; render();
                    const s = j.summary || {};
                    $('stCount').textContent = Number(s.collection_count || 0);
                    $('stInterest').textContent = money(s.interest_amount || 0);
                    $('stPenalty').textContent = money(s.penalty_amount || 0);
                    $('stOther').textContent = money(s.other_charges || 0);
                    $('stTotal').textContent = money(s.total_amount || 0);
                } catch (e) { note('bad', e.message) }
            }
            function iso(d) { return d.toISOString().slice(0, 10) }
            function setRange(type) {
                const now = new Date(), from = new Date(now), to = new Date(now);
                if (type === 'today') { }
                else if (type === 'yesterday') { from.setDate(now.getDate() - 1); to.setDate(now.getDate() - 1) }
                else if (type === 'week') { const day = (now.getDay() + 6) % 7; from.setDate(now.getDate() - day) }
                else if (type === 'month') { from.setDate(1) }
                else if (type === 'fy') { const y = now.getMonth() >= 3 ? now.getFullYear() : now.getFullYear() - 1; from.setFullYear(y, 3, 1) }
                else if (type === 'all') { $('fromDate').value = ''; $('toDate').value = ''; load(); return }
                $('fromDate').value = iso(from); $('toDate').value = iso(to); load();
            }
            function exportCsv() {
                if (!rows.length) { note('bad', 'No collection data available to export.'); return }
                const headers = ['Receipt No', 'Collection Date', 'Customer', 'Customer Code', 'Mobile', 'Pawn No', 'Branch', 'From Date', 'To Date', 'Interest', 'Penalty', 'Other Charges', 'Total', 'Payment Method', 'Reference', 'Collector', 'Status'];
                const values = rows.map(r => [r.receipt_no, r.collection_date, r.customer_name, r.customer_code, r.mobile, r.pawn_no, r.branch_name, r.from_date, r.to_date, r.interest_amount, r.penalty_amount, r.other_charges, r.total_amount, r.method_name, r.reference_no, r.collector_name, Number(r.is_reversed || 0) === 1 ? 'Reversed' : 'Collected']);
                const csv = [headers, ...values].map(row => row.map(v => `"${String(v ?? '').replace(/"/g, '""')}"`).join(',')).join('\r\n');
                const blob = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8;' });
                const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = 'pawn-interest-collections.csv'; a.click(); URL.revokeObjectURL(a.href);
            }
            $('applyBtn').onclick = load;
            $('resetBtn').onclick = () => { ['search', 'fromDate', 'toDate', 'minAmount', 'maxAmount'].forEach(id => $(id).value = '');['customerId', 'pawnId', 'branchId', 'paymentMethodId', 'collectorId', 'collectionType', 'recordStatus', 'pawnStatus'].forEach(id => $(id).value = ''); $('sortBy').value = 'latest'; load() };
            $('exportBtn').onclick = exportCsv;
            $('search').addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(load, 350) });
            document.querySelectorAll('.quick-btn').forEach(b => b.addEventListener('click', () => setRange(b.dataset.range)));
            $('customerId').addEventListener('change', () => { const cid = $('customerId').value;[...$('pawnId').options].forEach(o => { if (!o.value) return; o.hidden = cid !== '' && String((rows.find(x => String(x.pawn_entry_id) === o.value) || {}).customer_id || '') !== cid }); load() });
            (async () => { await loadOptions(); await load() })();
        })();
    </script>
</body>

</html>