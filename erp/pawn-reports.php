<?php require __DIR__ . '/_common.php'; ?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($businessName) ?> - Pawn Reports</title>
    <?php include('includes/links.php');
    require __DIR__ . '/_style.php'; ?>
    <style>
        .report-filter {
            display: grid;
            grid-template-columns: minmax(220px, 1.4fr) repeat(5, minmax(140px, .7fr)) auto;
            gap: 8px
        }

        .stat-grid {
            display: grid;
            grid-template-columns: repeat(6, minmax(0, 1fr));
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

        .stat-box.highlight {
            background: var(--primary-soft)
        }

        .report-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 12px
        }

        .report-table {
            font-size: 10px;
            margin: 0
        }

        .report-table th {
            font-size: 9px;
            text-transform: uppercase;
            color: var(--muted);
            white-space: nowrap;
            padding: 9px
        }

        .report-table td {
            padding: 9px;
            vertical-align: middle
        }

        .status-pill {
            display: inline-flex;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 9px;
            font-weight: 800
        }

        .st-active {
            background: #eaf8f0;
            color: #168449
        }

        .st-partial {
            background: #fff5df;
            color: #a56700
        }

        .st-closed {
            background: #eef2f6;
            color: #52606d
        }

        .st-auctioned {
            background: #fdecec;
            color: #b42318
        }

        .st-draft {
            background: #eef3ff;
            color: #3659a2
        }

        .st-cancelled {
            background: #f3f4f6;
            color: #6b7280
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

        .theme-toast-success {
            background: #168449
        }

        .theme-toast-error {
            background: #c0392b
        }

        @media(max-width:1250px) {
            .report-filter {
                grid-template-columns: 1fr 1fr 1fr
            }

            .report-filter .wide {
                grid-column: 1/-1
            }

            .stat-grid {
                grid-template-columns: repeat(3, 1fr)
            }
        }

        @media(max-width:850px) {
            .report-grid {
                grid-template-columns: 1fr
            }

            .report-filter {
                grid-template-columns: 1fr 1fr
            }
        }

        @media(max-width:575px) {

            .report-filter,
            .stat-grid {
                grid-template-columns: 1fr
            }
        }

        @media print {
            body {
                background: #fff
            }

            .sidebar,
            .app-sidebar,
            nav,
            .navbar,
            .no-print,
            footer {
                display: none !important
            }

            .app-main {
                margin: 0 !important
            }

            .content-wrap {
                padding: 0 !important
            }

            .page-card {
                box-shadow: none !important
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
                        <div class="page-title">Pawn Reports</div>
                        <div class="small text-muted">Complete pawn portfolio, collection, closure and customer-level
                            reporting.</div>
                    </div>
                    <div class="d-flex gap-2 flex-wrap no-print">
                        <button type="button" id="printBtn" class="btn-soft"><i class="fa-solid fa-print"></i>
                            Print</button>
                        <button type="button" id="exportBtn" class="btn-soft"><i class="fa-solid fa-file-csv"></i>
                            Export CSV</button>
                    </div>
                </div>
            </div>

            <div class="page-card mb-3 no-print">
                <div class="card-body-x">
                    <div class="report-filter">
                        <input type="search" id="search" class="form-control wide"
                            placeholder="Pawn no, customer, code or mobile">
                        <select id="status" class="form-select">
                            <option value="">All Statuses</option>
                            <option>Draft</option>
                            <option>Active</option>
                            <option>Partially Paid</option>
                            <option>Closed</option>
                            <option>Auctioned</option>
                            <option>Cancelled</option>
                        </select>
                        <select id="categoryId" class="form-select">
                            <option value="">All Categories</option>
                        </select>
                        <select id="customerId" class="form-select">
                            <option value="">All Customers</option>
                        </select>
                        <input type="date" id="fromDate" class="form-control">
                        <input type="date" id="toDate" class="form-control">
                        <button type="button" id="applyBtn" class="btn-theme"><i class="fa-solid fa-filter"></i>
                            Apply</button>
                    </div>
                    <div class="quick-row">
                        <button type="button" class="quick-btn" data-range="today">Today</button>
                        <button type="button" class="quick-btn" data-range="week">This Week</button>
                        <button type="button" class="quick-btn" data-range="month">This Month</button>
                        <button type="button" class="quick-btn" data-range="fy">Financial Year</button>
                        <button type="button" class="quick-btn" data-range="all">All Time</button>
                    </div>
                </div>
            </div>

            <div class="stat-grid">
                <div class="stat-box"><span>Total Pawns</span><strong id="stTotal">0</strong></div>
                <div class="stat-box"><span>Original Principal</span><strong>₹<span
                            id="stPrincipal">0.00</span></strong></div>
                <div class="stat-box"><span>Principal Paid</span><strong>₹<span id="stPaid">0.00</span></strong></div>
                <div class="stat-box highlight"><span>Outstanding</span><strong>₹<span
                            id="stOutstanding">0.00</span></strong></div>
                <div class="stat-box"><span>Interest Collected</span><strong>₹<span id="stInterest">0.00</span></strong>
                </div>
                <div class="stat-box highlight"><span>Total Collected</span><strong>₹<span
                            id="stCollected">0.00</span></strong></div>
            </div>

            <div class="report-grid">
                <div class="page-card">
                    <div class="page-head">
                        <div class="section-title">Status Summary</div>
                    </div>
                    <div class="table-responsive">
                        <table class="table report-table align-middle">
                            <thead>
                                <tr>
                                    <th>Status</th>
                                    <th>Count</th>
                                    <th>Principal</th>
                                    <th>Outstanding</th>
                                </tr>
                            </thead>
                            <tbody id="statusBody"></tbody>
                        </table>
                    </div>
                </div>
                <div class="page-card">
                    <div class="page-head">
                        <div class="section-title">Category Summary</div>
                    </div>
                    <div class="table-responsive">
                        <table class="table report-table align-middle">
                            <thead>
                                <tr>
                                    <th>Category</th>
                                    <th>Count</th>
                                    <th>Principal</th>
                                    <th>Outstanding</th>
                                </tr>
                            </thead>
                            <tbody id="categoryBody"></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="report-grid">
                <div class="page-card">
                    <div class="page-head">
                        <div class="section-title">Collection Summary</div>
                    </div>
                    <div class="table-responsive">
                        <table class="table report-table align-middle">
                            <tbody>
                                <tr>
                                    <td>Principal Collected</td>
                                    <td class="text-end"><strong>₹<span id="sumPrincipalCollected">0.00</span></strong>
                                    </td>
                                </tr>
                                <tr>
                                    <td>Interest Collected</td>
                                    <td class="text-end"><strong>₹<span id="sumInterestCollected">0.00</span></strong>
                                    </td>
                                </tr>
                                <tr>
                                    <td>Penalty Collected</td>
                                    <td class="text-end"><strong>₹<span id="sumPenaltyCollected">0.00</span></strong>
                                    </td>
                                </tr>
                                <tr>
                                    <td>Other Charges Collected</td>
                                    <td class="text-end"><strong>₹<span id="sumOtherCollected">0.00</span></strong></td>
                                </tr>
                                <tr>
                                    <td>Total Collections</td>
                                    <td class="text-end"><strong>₹<span id="sumTotalCollected">0.00</span></strong></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="page-card">
                    <div class="page-head">
                        <div class="section-title">Closure Summary</div>
                    </div>
                    <div class="table-responsive">
                        <table class="table report-table align-middle">
                            <tbody>
                                <tr>
                                    <td>Closed Pawns</td>
                                    <td class="text-end"><strong id="closedCount">0</strong></td>
                                </tr>
                                <tr>
                                    <td>Closed Principal</td>
                                    <td class="text-end"><strong>₹<span id="closedPrincipal">0.00</span></strong></td>
                                </tr>
                                <tr>
                                    <td>Active + Partial Pawns</td>
                                    <td class="text-end"><strong id="openCount">0</strong></td>
                                </tr>
                                <tr>
                                    <td>Open Outstanding</td>
                                    <td class="text-end"><strong>₹<span id="openOutstanding">0.00</span></strong></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="page-card">
                <div class="page-head">
                    <div>
                        <div class="section-title">Detailed Pawn Report</div>
                        <div class="small text-muted" id="periodText">All records</div>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table report-table align-middle">
                        <thead>
                            <tr>
                                <th>Pawn</th>
                                <th>Customer</th>
                                <th>Category</th>
                                <th>Pawn Date</th>
                                <th>Due / Closure</th>
                                <th>Principal</th>
                                <th>Principal Paid</th>
                                <th>Balance</th>
                                <th>Interest</th>
                                <th>Penalty</th>
                                <th>Other</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="detailBody"></tbody>
                    </table>
                </div>
                <div id="emptyState" class="text-center text-muted p-5 d-none">No pawn records found.</div>
            </div>

            <?php include('includes/footer.php'); ?>
        </div>
    </main>
    <?php include('includes/script.php'); ?>
    <script src="assets/js/script.js"></script>
    <script>
        (() => {
            'use strict';
            const api = 'api/pawn-reports.php';
            const csrf = <?= json_encode($csrfToken) ?>;
            const $ = id => document.getElementById(id);
            let rows = [], timer = null;

            function esc(v) { return String(v ?? '').replace(/[&<>'"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[c])) }
            function money(v) { return Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }
            function dateView(v) { if (!v) return '—'; const p = String(v).split('-'); return p.length === 3 ? `${p[2]}-${p[1]}-${p[0]}` : v }
            function note(t, m) { const x = document.createElement('div'); x.className = 'theme-toast theme-toast-' + (t === 'ok' ? 'success' : 'error'); x.textContent = m; document.body.appendChild(x); setTimeout(() => x.remove(), 3500) }
            function badge(s) { const cls = { 'Active': 'st-active', 'Partially Paid': 'st-partial', 'Closed': 'st-closed', 'Auctioned': 'st-auctioned', 'Draft': 'st-draft', 'Cancelled': 'st-cancelled' }[s] || 'st-draft'; return `<span class="status-pill ${cls}">${esc(s)}</span>` }

            async function req(payload) {
                const f = new FormData();
                Object.entries(payload).forEach(([k, v]) => f.append(k, v ?? ''));
                f.append('csrf_token', csrf);
                const r = await fetch(api, { method: 'POST', body: f, credentials: 'same-origin', headers: { Accept: 'application/json' } });
                const raw = await r.text(); let j;
                try { j = JSON.parse(raw) } catch (e) { throw new Error(raw.replace(/<[^>]*>/g, ' ').slice(0, 320)) }
                if (!r.ok || !j.success) throw new Error(j.message || 'Request failed');
                return j;
            }

            function addOptions(id, items, valueKey, textFn) {
                const s = $(id), first = s.options[0].outerHTML;
                s.innerHTML = first + items.map(x => `<option value="${esc(x[valueKey])}">${esc(textFn(x))}</option>`).join('');
            }

            async function loadOptions() {
                try {
                    const j = await req({ action: 'options' });
                    addOptions('categoryId', j.categories || [], 'id', x => x.category_name);
                    addOptions('customerId', j.customers || [], 'id', x => `${x.customer_name} - ${x.customer_code || ''} - ${x.mobile || ''}`);
                } catch (e) { note('bad', e.message) }
            }

            function filters() {
                return {
                    action: 'report',
                    search: $('search').value.trim(),
                    status: $('status').value,
                    category_id: $('categoryId').value,
                    customer_id: $('customerId').value,
                    from_date: $('fromDate').value,
                    to_date: $('toDate').value
                };
            }

            function renderSummary(j) {
                const s = j.summary || {};
                $('stTotal').textContent = Number(s.total_pawns || 0);
                $('stPrincipal').textContent = money(s.original_principal || 0);
                $('stPaid').textContent = money(s.principal_paid || 0);
                $('stOutstanding').textContent = money(s.outstanding || 0);
                $('stInterest').textContent = money(s.interest_collected || 0);
                $('stCollected').textContent = money(s.total_collected || 0);

                $('sumPrincipalCollected').textContent = money(s.principal_paid || 0);
                $('sumInterestCollected').textContent = money(s.interest_collected || 0);
                $('sumPenaltyCollected').textContent = money(s.penalty_collected || 0);
                $('sumOtherCollected').textContent = money(s.other_collected || 0);
                $('sumTotalCollected').textContent = money(s.total_collected || 0);

                $('closedCount').textContent = Number(s.closed_count || 0);
                $('closedPrincipal').textContent = money(s.closed_principal || 0);
                $('openCount').textContent = Number(s.open_count || 0);
                $('openOutstanding').textContent = money(s.open_outstanding || 0);

                $('statusBody').innerHTML = (j.status_summary || []).map(x => `<tr>
  <td>${badge(x.status)}</td><td>${Number(x.count || 0)}</td>
  <td>₹${money(x.principal)}</td><td>₹${money(x.outstanding)}</td>
 </tr>`).join('');

                $('categoryBody').innerHTML = (j.category_summary || []).map(x => `<tr>
  <td>${esc(x.category_name || 'Uncategorized')}</td><td>${Number(x.count || 0)}</td>
  <td>₹${money(x.principal)}</td><td>₹${money(x.outstanding)}</td>
 </tr>`).join('');
            }

            function renderRows() {
                $('detailBody').innerHTML = rows.map(r => `<tr>
  <td><strong>${esc(r.pawn_no)}</strong><div class="text-muted">${esc(r.branch_name || '')}</div></td>
  <td><strong>${esc(r.customer_name || '—')}</strong><div class="text-muted">${esc(r.customer_code || '')} · ${esc(r.mobile || '')}</div></td>
  <td>${esc(r.category_name || '—')}</td>
  <td>${dateView(r.pawn_date)}</td>
  <td>${r.status === 'Closed' ? 'Closed ' + dateView(r.closure_date) : r.due_date ? dateView(r.due_date) : 'At Closure'}</td>
  <td>₹${money(r.principal_amount)}</td>
  <td>₹${money(r.total_principal_paid)}</td>
  <td><strong>₹${money(r.balance_principal)}</strong></td>
  <td>₹${money(r.total_interest_collected)}</td>
  <td>₹${money(r.total_penalty_collected)}</td>
  <td>₹${money(r.total_other_charges_collected)}</td>
  <td>${badge(r.status)}</td>
 </tr>`).join('');
                $('emptyState').classList.toggle('d-none', rows.length > 0);
            }

            async function load() {
                try {
                    const j = await req(filters());
                    rows = j.rows || [];
                    renderSummary(j);
                    renderRows();
                    const f = $('fromDate').value, t = $('toDate').value;
                    $('periodText').textContent = (f || t) ? ((f ? dateView(f) : 'Beginning') + ' to ' + (t ? dateView(t) : 'Today')) : 'All records';
                } catch (e) { note('bad', e.message) }
            }

            function iso(d) { return d.toISOString().slice(0, 10) }
            function setRange(type) {
                const now = new Date(), from = new Date(now), to = new Date(now);
                if (type === 'today') { }
                else if (type === 'week') { const day = (now.getDay() + 6) % 7; from.setDate(now.getDate() - day) }
                else if (type === 'month') { from.setDate(1) }
                else if (type === 'fy') { const y = now.getMonth() >= 3 ? now.getFullYear() : now.getFullYear() - 1; from.setFullYear(y, 3, 1) }
                else if (type === 'all') { $('fromDate').value = ''; $('toDate').value = ''; load(); return }
                $('fromDate').value = iso(from); $('toDate').value = iso(to); load();
            }

            function exportCsv() {
                if (!rows.length) return note('bad', 'No report rows available to export.');
                const headers = ['Pawn No', 'Customer', 'Customer Code', 'Mobile', 'Category', 'Pawn Date', 'Due Date', 'Closure Date', 'Principal', 'Principal Paid', 'Balance', 'Interest Collected', 'Penalty Collected', 'Other Charges Collected', 'Status'];
                const vals = rows.map(r => [
                    r.pawn_no, r.customer_name, r.customer_code, r.mobile, r.category_name, r.pawn_date, r.due_date, r.closure_date,
                    r.principal_amount, r.total_principal_paid, r.balance_principal, r.total_interest_collected,
                    r.total_penalty_collected, r.total_other_charges_collected, r.status
                ]);
                const csv = [headers, ...vals].map(row => row.map(v => `"${String(v ?? '').replace(/"/g, '""')}"`).join(',')).join('\r\n');
                const blob = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8;' });
                const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = 'pawn-reports.csv'; a.click(); URL.revokeObjectURL(a.href);
            }

            $('applyBtn').onclick = load;
            $('printBtn').onclick = () => window.print();
            $('exportBtn').onclick = exportCsv;
            $('search').addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(load, 350) });
            ['status', 'categoryId', 'customerId', 'fromDate', 'toDate'].forEach(id => $(id).addEventListener('change', load));
            document.querySelectorAll('.quick-btn').forEach(b => b.addEventListener('click', () => setRange(b.dataset.range)));

            (async () => { await loadOptions(); await load() })();
        })();
    </script>
</body>

</html>