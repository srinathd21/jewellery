<?php
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
require __DIR__ . '/_common.php';
if (session_status() === PHP_SESSION_NONE)
    session_start();
if (empty($_SESSION['pawn_csrf']))
    $_SESSION['pawn_csrf'] = bin2hex(random_bytes(24));
$csrfToken = (string) $_SESSION['pawn_csrf'];
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($businessName) ?> - Bank Pawn</title>
    <?php include('includes/links.php');
    require __DIR__ . '/_style.php'; ?>
    <style>
        .stat-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 10px;
            margin-bottom: 12px
        }

        .stat-card {
            min-height: 88px;
            padding: 13px 15px;
            border: 1px solid var(--line);
            border-radius: 16px;
            background: var(--card-bg);
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            flex: 0 0 50px;
            border-radius: 13px;
            background: #fff3dc;
            display: grid;
            place-items: center;
            color: #cf7d00;
            font-size: 19px
        }

        .stat-label {
            font-size: 10px;
            color: #71809a;
            margin-bottom: 2px
        }

        .stat-value {
            font-size: 21px;
            font-weight: 800;
            line-height: 1.05;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis
        }

        .stat-note {
            font-size: 9px;
            color: var(--muted);
            margin-top: 4px
        }

        .compact-head {
            padding: 11px 13px !important
        }

        .compact-body {
            padding: 12px 13px !important
        }

        .loan-table th {
            font-size: 9px;
            text-transform: uppercase;
            color: var(--muted);
            white-space: nowrap
        }

        .loan-table td {
            font-size: 10px;
            vertical-align: top
        }

        .mini-note {
            font-size: 9px;
            color: var(--muted)
        }

        .badge-soft {
            display: inline-flex;
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

        .customer-line {
            padding: 4px 0;
            border-bottom: 1px dashed var(--line)
        }

        .customer-line:last-child {
            border-bottom: 0
        }

        .customer-line strong {
            font-size: 10px
        }

        .customer-line .meta {
            font-size: 9px;
            color: var(--muted);
            margin-top: 1px
        }

        .action-btn {
            width: 31px;
            height: 31px;
            border: 1px solid var(--line);
            border-radius: 9px;
            background: #fff;
            display: inline-grid;
            place-items: center;
            color: var(--text);
            text-decoration: none
        }

        .action-btn:hover {
            background: #fff8e8;
            color: var(--primary-dark)
        }

        .toolbar {
            display: grid;
            grid-template-columns: minmax(260px, 1fr) 220px 120px;
            gap: 8px
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
            font-weight: 700;
            background: #c0392b
        }

        @media(max-width:1100px) {
            .stat-grid {
                grid-template-columns: repeat(2, 1fr)
            }
        }

        @media(max-width:700px) {
            .stat-grid {
                grid-template-columns: 1fr
            }

            .toolbar {
                grid-template-columns: 1fr
            }

            .loan-table {
                min-width: 1250px
            }
        }
    </style>
</head>

<body>
    <?php include('includes/sidebar.php'); ?>
    <main class="app-main"><?php include('includes/nav.php'); ?>
        <div class="content-wrap">
            <div class="page-card mb-3">
                <div class="page-head compact-head d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <div class="page-title">Bank Pawn</div>
                        <div class="small text-muted">Track every bank re-pledge, linked pawn customers, principal and
                            bank interest.</div>
                    </div>
                    <div class="d-flex gap-2"><a href="pawn-manage.php" class="btn-soft"><i
                                class="fa-solid fa-arrow-left"></i> Manage</a><a href="pawn-bank-entry.php"
                            class="btn-theme"><i class="fa-solid fa-plus me-1"></i>Add Bank Pawn</a></div>
                </div>
            </div>
            <div class="stat-grid">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fa-solid fa-building-columns"></i></div>
                    <div>
                        <div class="stat-label">Active Bank Loans</div>
                        <div class="stat-value" id="stLoans">0</div>
                        <div class="stat-note">Open bank pawns</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fa-solid fa-indian-rupee-sign"></i></div>
                    <div>
                        <div class="stat-label">Principal Outstanding</div>
                        <div class="stat-value">₹<span id="stPrincipal">0.00</span></div>
                        <div class="stat-note">Balance payable to banks</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fa-solid fa-scale-balanced"></i></div>
                    <div>
                        <div class="stat-label">Gold Pledged</div>
                        <div class="stat-value"><span id="stWeight">0.000</span> g</div>
                        <div class="stat-note">Active pledged net weight</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fa-solid fa-calendar-day"></i></div>
                    <div>
                        <div class="stat-label">Payable Bank Interest</div>
                        <div class="stat-value">₹<span id="stInterest">0.00</span></div>
                        <div class="stat-note">Interest payable as of today</div>
                    </div>
                </div>
            </div>
            <div class="page-card mb-3">
                <div class="card-body-x compact-body">
                    <div class="toolbar"><input id="searchBox" class="form-control"
                            placeholder="Search bank, loan no, pawn no, customer or mobile"><select id="statusFilter"
                            class="form-select">
                            <option value="">All statuses</option>
                            <option>Active</option>
                            <option>Partially Paid</option>
                            <option>Closed</option>
                            <option>Released</option>
                            <option>Cancelled</option>
                        </select><button type="button" class="btn-soft" id="resetBtn"><i
                                class="fa-solid fa-rotate-left"></i> Reset</button></div>
                </div>
            </div>
            <div class="page-card mb-3">
                <div class="table-responsive">
                    <table class="table loan-table mb-0">
                        <thead>
                            <tr>
                                <th>Bank / Loan</th>
                                <th>Pledge Date</th>
                                <th>Pawn Customers</th>
                                <th>Principal</th>
                                <th>Balance</th>
                                <th>Bank Rate</th>
                                <th>Interest Value</th>
                                <th>Payable Interest</th>
                                <th>Interest Paid</th>
                                <th>Next Due</th>
                                <th>Gold</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="loanBody">
                            <tr>
                                <td colspan="13" class="text-center text-muted p-4">Loading bank pawns...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php include('includes/footer.php'); ?>
        </div>
    </main>
    <?php include('includes/script.php'); ?>
    <script src="assets/js/script.js"></script>
    <script>
        (function () {
            'use strict'; const api = 'api/pawn-bank.php', csrf = <?= json_encode($csrfToken) ?>, $ = id => document.getElementById(id); let loans = [];
            function money(v) { return Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) } function esc(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c])) } function toast(msg) { const x = document.createElement('div'); x.className = 'toast-x'; x.textContent = msg; document.body.appendChild(x); setTimeout(() => x.remove(), 3500) } async function req(o) { const f = new FormData(); Object.keys(o).forEach(k => f.append(k, o[k])); f.append('csrf_token', csrf); const r = await fetch(api, { method: 'POST', body: f, credentials: 'same-origin', headers: { Accept: 'application/json' } }), raw = await r.text(); let j; try { j = JSON.parse(raw) } catch (e) { throw new Error(raw.replace(/<[^>]*>/g, ' ').trim().slice(0, 300) || 'Invalid server response') } if (!r.ok || !j.success) throw new Error(j.message || 'Request failed'); return j }
            function customerHtml(l) { const a = l.customers || []; if (!a.length) return '<span class="text-muted">No linked pawn</span>'; return a.map(x => `<div class="customer-line"><strong>${esc(x.pawn_no)} · ${esc(x.customer_name)}</strong><div class="meta">${esc(x.mobile || '')} · ${Number(x.pledged_net_weight || 0).toFixed(3)} g · Alloc ₹${money(x.allocated_bank_principal || 0)}</div></div>`).join('') }
            function render() { const q = $('searchBox').value.trim().toLowerCase(), st = $('statusFilter').value; const rows = loans.filter(l => { if (st && l.status !== st) return false; const cust = (l.customers || []).map(x => [x.pawn_no, x.customer_name, x.mobile].join(' ')).join(' '); return !q || [l.bank_name, l.branch_name, l.bank_loan_no, cust].join(' ').toLowerCase().includes(q) }); $('loanBody').innerHTML = rows.length ? rows.map(l => `<tr><td><strong>${esc(l.bank_name)}</strong><div class="mini-note">${esc(l.branch_name || '')} ${l.branch_name ? '· ' : ''}${esc(l.bank_loan_no)}</div></td><td>${esc(l.pledge_date)}</td><td style="min-width:245px">${customerHtml(l)}</td><td>₹${money(l.principal_amount)}</td><td><strong>₹${money(l.balance_principal)}</strong></td><td>${Number(l.bank_interest_percent || 0).toFixed(3)}%<div class="mini-note">${esc(l.bank_interest_period)} · ${esc(l.interest_payment_cycle)}</div></td><td><strong>₹${money(l.interest_value)}</strong></td><td><strong>₹${money(l.payable_interest)}</strong></td><td>₹${money(l.total_interest_paid)}</td><td>${esc(l.next_interest_due_date || 'At Closure')}</td><td>${Number(l.pledged_net_weight || 0).toFixed(3)} g<div class="mini-note">${Number(l.pawn_count || 0)} pawn(s)</div></td><td><span class="badge-soft ${['Active', 'Partially Paid'].includes(l.status) ? 'badge-good' : (l.status === 'Cancelled' ? 'badge-bad' : 'badge-warn')}">${esc(l.status)}</span></td><td class="text-end"><a class="action-btn" title="Edit" href="pawn-bank-entry.php?id=${l.id}"><i class="fa-solid fa-pen"></i></a></td></tr>`).join('') : '<tr><td colspan="13" class="text-center text-muted p-4">No bank pawns found.</td></tr>' }
            async function init() { try { const j = await req({ action: 'list' }); loans = j.loans || []; const s = j.stats || {}; $('stLoans').textContent = Number(s.active_loans || 0); $('stPrincipal').textContent = money(s.principal_outstanding); $('stWeight').textContent = Number(s.pledged_net_weight || 0).toFixed(3); $('stInterest').textContent = money(s.interest_due); render() } catch (e) { toast(e.message) } }
            $('searchBox').oninput = render; $('statusFilter').onchange = render; $('resetBtn').onclick = () => { $('searchBox').value = ''; $('statusFilter').value = ''; render() }; init()
        })();
    </script>
</body>

</html>