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
            margin-top: 3px;
            font-size: 22px
        }

        .pawn-table {
            font-size: 10px;
            margin: 0
        }

        .pawn-table th {
            font-size: 9px;
            text-transform: uppercase;
            color: var(--muted);
            white-space: nowrap;
            padding: 10px
        }

        .pawn-table td {
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
            width: 32px;
            height: 32px;
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
                        <div class="page-title">Pawn Management</div>
                        <div class="small text-muted">View, edit and safely remove pawn entries.</div>
                    </div>
                    <div class="d-flex gap-2"><a href="pawn-entry.php" class="btn-theme"><i
                                class="fa-solid fa-plus"></i> New Pawn</a><a href="pawn-collections.php"
                            class="btn-soft">Collections</a></div>
                </div>
            </div>
            <div class="stat-grid">
                <div class="stat-box"><span>Total Pawns</span><strong id="stTotal">0</strong></div>
                <div class="stat-box"><span>Active</span><strong id="stActive">0</strong></div>
                <div class="stat-box"><span>Partially Paid</span><strong id="stPartial">0</strong></div>
                <div class="stat-box"><span>Closed</span><strong id="stClosed">0</strong></div>
                <div class="stat-box"><span>Outstanding</span><strong>₹<span id="stOutstanding">0.00</span></strong>
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
                                <th>Interest Rate</th>
                                <th>Interest Value</th>
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
    <script>(() => { 'use strict'; const api = 'api/pawn-manage.php', csrf = <?= json_encode($csrfToken) ?>, $ = id => document.getElementById(id); let rows = [], timer = null; function esc(v) { return String(v ?? '').replace(/[&<>'"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[c])) } function money(v) {
    return Number(v || 0).toLocaleString('en-IN', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function interestBase(r) {
    return String(r.interest_method || '').toLowerCase() === 'flat'
        ? Number(r.principal_amount || 0)
        : Number(r.balance_principal || 0);
}

function interestMultiplier(r) {
    const period = String(r.interest_period || 'Monthly');
    const cycle = String(r.interest_collection_cycle || 'Monthly');
    const custom = Math.max(1, Number(r.interest_cycle_months || 1));

    if (cycle === 'At Closure') {
        return 1;
    }

    const months = {
        'Monthly': 1,
        'Quarterly': 3,
        'Half-Yearly': 6,
        'Yearly': 12,
        'Custom': custom
    }[cycle] || 1;

    if (period === 'Daily') {
        return months * 30;
    }

    if (period === 'Yearly') {
        return months / 12;
    }

    return months;
}

function calculatedInterest(r) {
    const base = interestBase(r);
    const rate = Math.max(0, Number(r.interest_percent || 0));
    return base * (rate / 100) * interestMultiplier(r);
}

function interestLabel(r) {
    const cycle = String(r.interest_collection_cycle || '');

    if (cycle === 'At Closure') {
        return String(r.interest_period || 'Period') + ' estimate';
    }

    return cycle || String(r.interest_period || 'Period');
} function note(t, m) { const x = document.createElement('div'); x.className = 'theme-toast theme-toast-' + (t === 'ok' ? 'success' : 'error'); x.textContent = m; document.body.appendChild(x); setTimeout(() => x.remove(), 3200) } async function req(data) { const f = new FormData(); Object.entries(data).forEach(([k, v]) => f.append(k, v)); f.append('csrf_token', csrf); const r = await fetch(api, { method: 'POST', body: f, credentials: 'same-origin', headers: { Accept: 'application/json' } }), raw = await r.text(); let j; try { j = JSON.parse(raw) } catch (e) { throw new Error(raw.replace(/<[^>]*>/g, ' ').slice(0, 260)) } if (!r.ok || !j.success) throw new Error(j.message || 'Request failed'); return j } function badge(s) { const cls = { 'Active': 'st-active', 'Partially Paid': 'st-partial', 'Closed': 'st-closed', 'Auctioned': 'st-auctioned', 'Draft': 'st-draft', 'Cancelled': 'st-cancelled' }[s] || 'st-draft'; return `<span class="status-pill ${cls}">${esc(s)}</span>` } function whatsappNumber(value) {
    let number = String(value || '').replace(/\D+/g, '');
    if (number.length === 10) number = '91' + number;
    else if (number.length === 11 && number.startsWith('0')) number = '91' + number.slice(1);
    return number;
}

function receiptUrl(r) {
    const base = window.location.href.substring(0, window.location.href.lastIndexOf('/') + 1);
    const file = String(r.status || '') === 'Closed'
        ? 'pawn-closed-receipt.php'
        : 'pawn-receipt.php';

    return base + file
        + '?id=' + encodeURIComponent(r.id)
        + '&ref=' + encodeURIComponent(r.pawn_no || '');
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
        (closed ? 'View closed pawn receipt:\n' : 'View receipt:\n') + url + '\n\n' +
        'Thank you.';

    return 'https://wa.me/' + mobile + '?text=' + encodeURIComponent(message);
}

function render() {
    $('tableBody').innerHTML = rows.map(r => {
        const receipt = receiptUrl(r);
        const wa = whatsappUrl(r);

        return `<tr>
            <td>
                <strong>${esc(r.pawn_no)}</strong>
                <div class="text-muted">${esc(r.category_name || '')}</div>
            </td>
            <td>
                <strong>${esc(r.customer_name)}</strong>
                <div class="text-muted">${esc(r.customer_code || '')} · ${esc(r.mobile || '')}</div>
            </td>
            <td>${esc(r.category_name || '—')}</td>
            <td>
                ${esc(r.pawn_date)}
                <div class="text-muted">${r.due_date ? 'Due ' + esc(r.due_date) : 'At Closure'}</div>
            </td>
            <td>₹${money(r.principal_amount)}</td>
            <td>₹${money(r.total_principal_paid)}</td>
            <td><strong>₹${money(r.balance_principal)}</strong></td>
            <td>
                <strong>${Number(r.interest_percent || 0).toFixed(3)}%</strong>
                <div class="text-muted">${esc(r.interest_period || '')} · ${esc(r.interest_method || '')}</div>
            </td>
            <td>
                <strong>₹${money(calculatedInterest(r))}</strong>
                <div class="text-muted">${esc(interestLabel(r))}</div>
            </td>
            <td>${badge(r.status)}</td>
            <td>
                <div class="action-btns">
                    <a class="mini-btn" href="pawn-view.php?id=${r.id}" title="View">
                        <i class="fa-solid fa-eye"></i>
                    </a>
                    <a class="mini-btn" href="${receipt}" target="_blank" rel="noopener"
                       title="${String(r.status || '') === 'Closed' ? 'Closed Pawn Receipt' : 'Receipt'}">
                        <i class="fa-solid fa-receipt"></i>
                    </a>
                    ${wa ? `<a class="mini-btn whatsapp" href="${wa}" target="_blank" rel="noopener" title="Share receipt on WhatsApp"><i class="fa-brands fa-whatsapp"></i></a>` : ''}
                    <a class="mini-btn" href="pawn-edit.php?id=${r.id}" title="Edit">
                        <i class="fa-solid fa-pen"></i>
                    </a>
                    <button class="mini-btn danger delete-btn" data-id="${r.id}" data-no="${esc(r.pawn_no)}" title="Delete">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </div>
            </td>
        </tr>`;
    }).join('');

    $('emptyState').classList.toggle('d-none', rows.length > 0);
} async function load() { try { const j = await req({ action: 'list', search: $('search').value.trim(), status: $('statusFilter').value, from_date: $('fromDate').value, to_date: $('toDate').value }); rows = j.rows || []; render(); const s = j.stats || {}; $('stTotal').textContent = Number(s.total || 0); $('stActive').textContent = Number(s.active || 0); $('stPartial').textContent = Number(s.partial || 0); $('stClosed').textContent = Number(s.closed || 0); $('stOutstanding').textContent = money(s.outstanding || 0) } catch (e) { note('bad', e.message) } } document.addEventListener('click', async e => { const d = e.target.closest('.delete-btn'); if (!d) return; if (!confirm('Delete pawn ' + d.dataset.no + '? This is allowed only when no collections, payments, release or auction records exist.')) return; try { const j = await req({ action: 'delete', id: d.dataset.id }); note('ok', j.message); load() } catch (x) { note('bad', x.message) } }); $('search').addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(load, 300) });['statusFilter', 'fromDate', 'toDate'].forEach(id => $(id).addEventListener('change', load)); $('resetBtn').onclick = () => { $('search').value = ''; $('statusFilter').value = ''; $('fromDate').value = ''; $('toDate').value = ''; load() }; load() })();</script>
</body>

</html>