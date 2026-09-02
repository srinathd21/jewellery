
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
    <title><?= e($businessName) ?> - Pawn Manage</title>
    <?php include('includes/links.php');
    require __DIR__ . '/_style.php'; ?>
    <style>
        .manage-toolbar {
            display: grid;
            grid-template-columns: minmax(220px, 1.5fr) repeat(3, minmax(150px, .6fr)) auto;
            gap: 8px
        }

        .stat-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 8px;
            margin-bottom: 10px
        }

        .stat-box {
            min-height: 82px;
            padding: 11px 13px;
            border: 1px solid var(--line);
            border-radius: 14px;
            background: var(--card-bg);
            display: flex;
            align-items: center;
            gap: 11px;
            min-width: 0;
            overflow: hidden;
            box-shadow: 0 1px 2px rgba(15, 23, 42, .02)
        }

        .stat-icon {
            width: 44px;
            height: 44px;
            flex: 0 0 44px;
            border-radius: 12px;
            display: grid;
            place-items: center;
            background: #fff4df;
            color: #c97800;
            font-size: 17px
        }

        .stat-content {
            min-width: 0
        }

        .stat-box .stat-label {
            display: block;
            font-size: 10px;
            font-weight: 500;
            color: #718096;
            text-transform: none;
            letter-spacing: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis
        }

        .stat-box strong {
            display: block;
            margin-top: 2px;
            font-size: 22px;
            line-height: 1.05;
            font-weight: 800;
            color: var(--text);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis
        }

        .stat-box .stat-sub {
            display: block;
            margin-top: 2px;
            font-size: 9px;
            color: var(--muted);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis
        }

        .pawn-table {
            font-size: 10px;
            margin: 0
        }

        .pawn-table th {
            font-size: 8px;
            text-transform: uppercase;
            color: var(--muted);
            white-space: nowrap;
            padding: 7px 8px
        }

        .pawn-table td {
            padding: 7px 8px;
            vertical-align: middle;
            line-height: 1.25
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

        .action-btns {
            display: flex;
            justify-content: flex-end;
            gap: 5px
        }

        .mini-btn {
            width: 28px;
            height: 28px;
            border: 1px solid var(--line);
            border-radius: 7px;
            background: var(--card-bg);
            font-size: 10px;
            display: grid;
            place-items: center;
            text-decoration: none;
            color: inherit
        }

        .mini-btn:hover {
            background: var(--primary-soft);
            color: var(--primary-dark)
        }

        .mini-btn.danger:hover {
            background: #fdecec;
            color: #b42318
        }

        .mini-btn.whatsapp {
            color: #168449;
        }

        .mini-btn.whatsapp:hover {
            background: #eaf8f0;
            color: #168449;
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

        .content-wrap { padding-top: 10px; }
        .page-card.mb-3 { margin-bottom: 10px !important; }
        .page-head { padding: 12px 16px; }
        .page-title { font-size: 20px; line-height: 1.1; }
        .page-head .small { font-size: 10px; }
        .page-head .btn-theme,
        .page-head .btn-soft { min-height: 34px; padding: 7px 12px; font-size: 10px; }
        .card-body-x { padding: 10px 12px; }
        .manage-toolbar { gap: 6px; }
        .manage-toolbar .form-control,
        .manage-toolbar .form-select,
        .manage-toolbar .btn-soft { min-height: 34px; height: 34px; padding-top: 5px; padding-bottom: 5px; font-size: 10px; }
        .status-pill { padding: 3px 6px; font-size: 8px; }
        .action-btns { gap: 4px; flex-wrap: nowrap; }

        @media(max-width:1100px) {
            .manage-toolbar {
                grid-template-columns: 1fr 1fr
            }

            .manage-toolbar .search-wide {
                grid-column: 1/-1
            }

            .stat-grid {
                grid-template-columns: repeat(2, 1fr)
            }
        }

        @media(max-width:767px) {
            .manage-toolbar {
                grid-template-columns: 1fr
            }

            .manage-toolbar .search-wide {
                grid-column: auto
            }

            .stat-grid {
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
                        <div class="page-title">Pawn Management</div>
                        <div class="small text-muted">Manage pawn status, customer interest cycle, bank pledge state and
                            re-registration.</div>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" id="calculateLevelsBtn" class="btn-soft" title="Check unpaid overdue Level 1 interest and move eligible pawns to Level 2">
                            <i class="fa-solid fa-calculator"></i> Calculate L1 → L2
                        </button>
                        <a href="pawn-entry.php" class="btn-theme"><i class="fa-solid fa-plus"></i> New Pawn</a>
                    </div>
                </div>
            </div>
            <div class="stat-grid">
                <div class="stat-box">
                    <div class="stat-icon"><i class="fa-solid fa-scale-balanced"></i></div>
                    <div class="stat-content"><span class="stat-label">Total Pawns</span><strong id="stTotal">0</strong><span class="stat-sub">All registrations</span></div>
                </div>
                <div class="stat-box">
                    <div class="stat-icon"><i class="fa-solid fa-circle-check"></i></div>
                    <div class="stat-content"><span class="stat-label">Active</span><strong id="stActive">0</strong><span class="stat-sub">Currently running</span></div>
                </div>
                <div class="stat-box">
                    <div class="stat-icon"><i class="fa-solid fa-wallet"></i></div>
                    <div class="stat-content"><span class="stat-label">Partially Paid</span><strong id="stPartial">0</strong><span class="stat-sub">Principal reduced</span></div>
                </div>
                <div class="stat-box">
                    <div class="stat-icon"><i class="fa-solid fa-lock"></i></div>
                    <div class="stat-content"><span class="stat-label">Closed</span><strong id="stClosed">0</strong><span class="stat-sub">Completed pawns</span></div>
                </div>
                <div class="stat-box">
                    <div class="stat-icon"><i class="fa-solid fa-indian-rupee-sign"></i></div>
                    <div class="stat-content"><span class="stat-label">Outstanding</span><strong>₹<span id="stOutstanding">0.00</span></strong><span class="stat-sub">Principal balance</span></div>
                </div>
            </div>
            <div class="page-card mb-3">
                <div class="card-body-x">
                    <div class="manage-toolbar"><input type="search" id="search" class="form-control search-wide"
                            placeholder="Search pawn no, customer, code or mobile"><select id="statusFilter"
                            class="form-select">
                            <option value="">All Statuses</option>
                            <option>Draft</option>
                            <option>Active</option>
                            <option>Partially Paid</option>
                            <option>Closed</option>
                            <option>Auctioned</option>
                            <option>Cancelled</option>
                        </select><input type="date" id="fromDate" class="form-control"><input type="date" id="toDate"
                            class="form-control"><button type="button" id="resetBtn" class="btn-soft"><i
                                class="fa-solid fa-rotate-left"></i> Reset</button></div>
                </div>
            </div>
            <div class="page-card">
                <div class="table-responsive">
                    <table class="table pawn-table align-middle">
                        <thead>
                            <tr>
                                <th>Pawn</th>
                                <th>Customer</th>
                                <th>Category</th>
                                <th>Date / Due</th>
                                <th>Principal</th>
                                <th>Paid</th>
                                <th>Balance</th>
                                <th>Interest</th>
                                <th>Next Due / Grace</th>
                                <th>Bank</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="tableBody"></tbody>
                    </table>
                </div>
                <div id="emptyState" class="text-center text-muted p-5 d-none">No pawn entries found.</div>
            </div>
            <?php include('includes/footer.php'); ?>
        </div>
    </main><?php include('includes/script.php'); ?>
    <script src="assets/js/script.js"></script>
    <script>
        (() => {
            'use strict';

            const api = 'api/pawn-manage.php';
            const csrf = <?= json_encode($csrfToken) ?>;
            const $ = id => document.getElementById(id);
            let rows = [];
            let timer = null;

            function esc(v) {
                return String(v ?? '').replace(/[&<>'"]/g, c => ({
                    '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;'
                }[c]));
            }

            function money(v) {
                return Number(v || 0).toLocaleString('en-IN', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
            }

            function note(type, message) {
                const x = document.createElement('div');
                x.className = 'theme-toast theme-toast-' + (type === 'ok' ? 'success' : 'error');
                x.textContent = message || (type === 'ok' ? 'Done' : 'Something went wrong');
                document.body.appendChild(x);
                setTimeout(() => x.remove(), 3200);
            }

            async function req(data) {
                const f = new FormData();
                Object.entries(data).forEach(([k, v]) => f.append(k, v == null ? '' : v));
                f.append('csrf_token', csrf);
                const response = await fetch(api, {
                    method: 'POST',
                    body: f,
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' }
                });
                const raw = await response.text();
                let json;
                try {
                    json = JSON.parse(raw);
                } catch (e) {
                    const clean = raw.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
                    throw new Error(clean.slice(0, 300) || 'Invalid response from Pawn Manage API.');
                }
                if (!response.ok || !json.success) throw new Error(json.message || 'Request failed.');
                return json;
            }

            async function interestReq(data) {
                const f = new FormData();
                Object.entries(data).forEach(([k, v]) => f.append(k, v == null ? '' : v));
                f.append('csrf_token', csrf);
                const response = await fetch('api/pawn-interest.php', {
                    method: 'POST',
                    body: f,
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' }
                });
                const raw = await response.text();
                let json;
                try {
                    json = JSON.parse(raw);
                } catch (e) {
                    const clean = raw.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
                    throw new Error(clean.slice(0, 300) || 'Invalid response from Pawn Interest API.');
                }
                if (!response.ok || !json.success) throw new Error(json.message || 'Interest calculation failed.');
                return json;
            }

            function todayYmd() {
                const d = new Date();
                const y = d.getFullYear();
                const m = String(d.getMonth() + 1).padStart(2, '0');
                const day = String(d.getDate()).padStart(2, '0');
                return y + '-' + m + '-' + day;
            }

            function canCalculateL1ToL2(r, asOf) {
                const status = String(r.status || '');
                const currentLevel = Number(r.current_rate_level || 1);
                const nextLevel = Number(r.next_rate_level || 0);
                const nextRate = Number(r.next_interest_percent || 0);
                const graceUntil = String(r.grace_until || r.next_interest_due_date || '');
                return (status === 'Active' || status === 'Partially Paid')
                    && currentLevel === 1
                    && nextLevel === 2
                    && nextRate > 0
                    && graceUntil !== ''
                    && graceUntil < asOf;
            }

            async function calculateL1ToL2() {
                const btn = $('calculateLevelsBtn');
                if (!btn) return;
                const asOf = todayYmd();
                const eligible = rows.filter(r => canCalculateL1ToL2(r, asOf));

                if (!eligible.length) {
                    note('ok', 'No unpaid overdue Level 1 pawn is eligible for Level 2 today.');
                    return;
                }

                if (!confirm('Calculate ' + eligible.length + ' unpaid overdue Level 1 pawn(s) and apply Level 2 where the missed-interest rule is satisfied?')) return;

                const oldHtml = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Calculating...';

                let changed = 0;
                let checked = 0;
                const errors = [];

                for (const pawn of eligible) {
                    try {
                        const result = await interestReq({
                            action: 'interest_quote',
                            pawn_id: Number(pawn.id || 0),
                            as_of_date: asOf
                        });
                        checked++;
                        const updatedPawn = result.pawn || {};
                        if (Number(updatedPawn.current_rate_level || 1) >= 2 || Number(updatedPawn.rate_escalation_count || 0) > Number(pawn.rate_escalation_count || 0)) {
                            changed++;
                        }
                    } catch (e) {
                        errors.push((pawn.pawn_no || ('#' + pawn.id)) + ': ' + e.message);
                    }
                }

                await load();
                btn.disabled = false;
                btn.innerHTML = oldHtml;

                if (errors.length) {
                    note('bad', changed + ' pawn(s) changed to Level 2. ' + errors.length + ' pawn(s) could not be calculated.');
                    console.warn('Pawn level calculation errors:', errors);
                } else {
                    note('ok', changed + ' of ' + checked + ' eligible pawn(s) changed from Level 1 to Level 2.');
                }
            }

            function badge(status) {
                const s = String(status || 'Draft');
                const cls = {
                    'Active': 'st-active',
                    'Partially Paid': 'st-partial',
                    'Closed': 'st-closed',
                    'Auctioned': 'st-auctioned',
                    'Draft': 'st-draft',
                    'Cancelled': 'st-cancelled'
                }[s] || 'st-draft';
                return `<span class="status-pill ${cls}">${esc(s)}</span>`;
            }

            function whatsappNumber(value) {
                let number = String(value || '').replace(/\D+/g, '');
                if (number.length === 10) number = '91' + number;
                else if (number.length === 11 && number.startsWith('0')) number = '91' + number.slice(1);
                return number;
            }

            function currentRate(r) {
                return Number(r.current_interest_percent || r.interest_percent || 0);
            }

            function dueCycleLabel(r) {
                const type = String(r.interest_due_cycle_type || '').trim();
                const value = Math.max(1, Number(r.interest_due_cycle_value || 1));
                if (type === 'Days') return value + ' day' + (value === 1 ? '' : 's');
                if (type === 'Months') return value + ' month' + (value === 1 ? '' : 's');
                if (type === 'Calendar Month') return value === 1 ? 'Calendar month' : value + ' calendar months';
                return String(r.interest_collection_cycle || r.interest_period || '—');
            }

            function nextDueText(r) {
                const due = String(r.next_interest_due_date || '');
                if (!due) return 'At Closure';
                const graceUntil = String(r.grace_until || '');
                if (graceUntil && graceUntil !== due) return 'Due ' + due + ' · Grace ' + graceUntil;
                return 'Due ' + due;
            }

            function bankBadge(r) {
                const s = String(r.bank_pledge_status || 'Not Pledged');
                const cls = s === 'Pledged' ? 'st-auctioned'
                    : (s === 'Partially Pledged' ? 'st-partial'
                    : (s === 'Released' ? 'st-closed' : 'st-draft'));
                return `<span class="status-pill ${cls}">${esc(s)}</span>`;
            }

            function receiptUrl(r) {
                const base = window.location.href.substring(0, window.location.href.lastIndexOf('/') + 1);
                const file = String(r.status || '') === 'Closed' ? 'pawn-closed-receipt.php' : 'pawn-receipt.php';
                return base + file + '?id=' + encodeURIComponent(r.id) + '&ref=' + encodeURIComponent(r.pawn_no || '');
            }

            function whatsappUrl(r) {
                const mobile = whatsappNumber(r.mobile);
                if (!mobile) return '';
                const url = receiptUrl(r);
                const closed = String(r.status || '') === 'Closed';
                const message =
                    'Dear ' + (r.customer_name || 'Customer') + ',\n\n' +
                    (closed ? 'Your pawn is closed successfully.\n' : 'Your pawn receipt is ready.\n') +
                    'Pawn No: ' + (r.pawn_no || '') + '\n' +
                    'Principal: ₹' + money(r.principal_amount) + '\n' +
                    'Paid: ₹' + money(r.total_principal_paid) + '\n' +
                    'Balance: ₹' + money(r.balance_principal) + '\n' +
                    (closed ? 'Status: Closed\n' : '') + '\n' +
                    (closed ? 'View closed pawn receipt:\n' : 'View receipt:\n') + url + '\n\nThank you.';
                return 'https://wa.me/' + mobile + '?text=' + encodeURIComponent(message);
            }

            function render() {
                $('tableBody').innerHTML = rows.map(r => {
                    const receipt = receiptUrl(r);
                    const wa = whatsappUrl(r);
                    return `<tr>
                        <td><strong>${esc(r.pawn_no)}</strong><div class="text-muted">#${Number(r.id || 0)}</div></td>
                        <td><strong>${esc(r.customer_name)}</strong><div class="text-muted">${esc(r.customer_code || '')} · ${esc(r.mobile || '')}</div></td>
                        <td>${esc(r.category_name || '—')}</td>
                        <td>${esc(r.pawn_date || '—')}<div class="text-muted">${r.due_date ? 'Closure ' + esc(r.due_date) : 'No fixed closure'}</div></td>
                        <td>₹${money(r.principal_amount)}</td>
                        <td>₹${money(r.total_principal_paid)}</td>
                        <td><strong>₹${money(r.balance_principal)}</strong></td>
                        <td>
                            <strong>${currentRate(r).toFixed(3)}%</strong>
                            <div class="text-muted">${esc(dueCycleLabel(r))}</div>
                            ${Number(r.rate_escalation_count || 0) > 0 ? `<div class="text-danger">Escalated ${Number(r.rate_escalation_count)} time(s)</div>` : ''}
                        </td>
                        <td><strong>${esc(nextDueText(r))}</strong><div class="text-muted">Missed: ${Number(r.missed_interest_cycles || 0)}</div></td>
                        <td>${bankBadge(r)}</td>
                        <td>${badge(r.status)}</td>
                        <td>
    <div class="action-btns">

        <a class="mini-btn"
           href="pawn-view.php?id=${Number(r.id)}"
           title="View">
            <i class="fa-solid fa-eye"></i>
        </a>

        ${String(r.status || '') !== 'Closed'
            && String(r.status || '') !== 'Cancelled'
            && String(r.status || '') !== 'Auctioned'
            ? `
                <a class="mini-btn"
                   href="pawn-entry.php?id=${Number(r.id)}"
                   title="Edit">
                    <i class="fa-solid fa-pen-to-square"></i>
                </a>

                <a class="mini-btn"
                   href="pawn-interest.php?pawn_id=${Number(r.id)}"
                   title="Pawn Interest">
                    <i class="fa-solid fa-indian-rupee-sign"></i>
                </a>
              `
            : ''
        }

        <a class="mini-btn"
           href="${receipt}"
           target="_blank"
           rel="noopener"
           title="Receipt">
            <i class="fa-solid fa-receipt"></i>
        </a>

        ${wa
            ? `
                <a class="mini-btn whatsapp"
                   href="${wa}"
                   target="_blank"
                   rel="noopener"
                   title="WhatsApp">
                    <i class="fa-brands fa-whatsapp"></i>
                </a>
              `
            : ''
        }

        ${String(r.status || '') === 'Closed'
            ? `
                <a class="mini-btn"
                   href="pawn-entry.php?reregister_from=${Number(r.id)}"
                   title="Re-Register">
                    <i class="fa-solid fa-rotate"></i>
                </a>
              `
            : ''
        }

    </div>
</td>
                    </tr>`;
                }).join('');
                $('emptyState').classList.toggle('d-none', rows.length > 0);
            }

            async function load() {
                try {
                    const j = await req({
                        action: 'list',
                        search: $('search').value.trim(),
                        status: $('statusFilter').value,
                        from_date: $('fromDate').value,
                        to_date: $('toDate').value
                    });
                    rows = Array.isArray(j.rows) ? j.rows : [];
                    render();
                    const s = j.stats || {};
                    $('stTotal').textContent = Number(s.total || 0);
                    $('stActive').textContent = Number(s.active || 0);
                    $('stPartial').textContent = Number(s.partial || 0);
                    $('stClosed').textContent = Number(s.closed || 0);
                    $('stOutstanding').textContent = money(s.outstanding || 0);
                } catch (e) {
                    rows = [];
                    render();
                    note('bad', e.message);
                }
            }

            $('search').addEventListener('input', () => {
                clearTimeout(timer);
                timer = setTimeout(load, 300);
            });
            ['statusFilter', 'fromDate', 'toDate'].forEach(id => $(id).addEventListener('change', load));
            $('resetBtn').addEventListener('click', () => {
                $('search').value = '';
                $('statusFilter').value = '';
                $('fromDate').value = '';
                $('toDate').value = '';
                load();
            });
            $('calculateLevelsBtn').addEventListener('click', calculateL1ToL2);

            load();
        })();
    </script>
</body>

</html>
