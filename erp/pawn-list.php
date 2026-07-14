<?php require __DIR__.'/_common.php'; ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($businessName)?> - Pawn List</title><?php include('includes/links.php');require __DIR__.'/_style.php';?></head><body>
<?php include('includes/sidebar.php');?><main class="app-main"><?php include('includes/nav.php');?><div class="content-wrap">
<div class="page-card mb-3"><div class="page-head"><div><div class="page-title">Pawn List</div><div class="small text-muted">Manage and track all pawn loans.</div></div><a href="pawn-entry.php" class="btn-theme">New Pawn Entry</a></div></div>
<div class="stat-grid mb-3"><div class="stat-card"><div class="stat-label">Total Pawns</div><div class="stat-value" id="sTotal">0</div></div><div class="stat-card"><div class="stat-label">Active</div><div class="stat-value" id="sActive">0</div></div><div class="stat-card"><div class="stat-label">Total Principal</div><div class="stat-value" id="sPrincipal">₹0.00</div></div><div class="stat-card"><div class="stat-label">Outstanding</div><div class="stat-value" id="sOutstanding">₹0.00</div></div></div>
<div class="page-card mb-3"><div class="card-body-x"><form id="filterForm" class="row g-2"><div class="col-md-2"><select id="status" class="form-select"><option value="">All status</option><option>Active</option><option>Partially Paid</option><option>Closed</option><option>Auctioned</option><option>Cancelled</option></select></div><div class="col-md-2"><input type="date" id="fromDate" class="form-control"></div><div class="col-md-2"><input type="date" id="toDate" class="form-control"></div><div class="col-md-4"><input type="search" id="search" class="form-control" placeholder="Pawn no, customer or mobile"></div><div class="col-md-2 d-flex gap-2"><button class="btn-theme flex-grow-1">Search</button><button type="button" id="reset" class="btn-soft">Reset</button></div></form></div></div>
<div class="page-card"><div class="loading" id="loading">Loading pawn entries...</div><div class="table-responsive" id="tableWrap"><table class="table mb-0"><thead><tr><th>Pawn No</th><th>Date</th><th>Customer</th><th>Category</th><th class="text-end">Principal</th><th class="text-end">Outstanding</th><th>Interest</th><th>Due Date</th><th>Status</th><th>Action</th></tr></thead><tbody id="tbody"></tbody></table></div><div class="empty" id="empty">No pawn entries found.</div><div class="p-3 d-flex justify-content-between"><span class="small text-muted" id="summary"></span><div id="pages"></div></div></div>
<?php include('includes/footer.php');?></div></main><?php include('includes/script.php');?><script src="assets/js/script.js"></script>
<script>
(() => {
    'use strict';

    const apiUrl = 'api/pawn.php';
    const csrfToken = <?=json_encode($csrfToken)?>;

    const loadingEl = document.getElementById('loading');
    const tableWrapEl = document.getElementById('tableWrap');
    const emptyEl = document.getElementById('empty');
    const tbodyEl = document.getElementById('tbody');
    const statusEl = document.getElementById('status');
    const fromDateEl = document.getElementById('fromDate');
    const toDateEl = document.getElementById('toDate');
    const searchEl = document.getElementById('search');
    const filterFormEl = document.getElementById('filterForm');
    const resetEl = document.getElementById('reset');
    const totalEl = document.getElementById('sTotal');
    const activeEl = document.getElementById('sActive');
    const principalEl = document.getElementById('sPrincipal');
    const outstandingEl = document.getElementById('sOutstanding');
    const summaryEl = document.getElementById('summary');
    const pagesEl = document.getElementById('pages');

    function esc(value) {
        return String(value ?? '').replace(/[&<>'"]/g, char => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            "'": '&#039;',
            '"': '&quot;'
        }[char]));
    }

    function money(value) {
        return Number(value || 0).toLocaleString('en-IN', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    async function request(data) {
        const formData = new FormData();

        Object.entries(data).forEach(([key, value]) => {
            formData.append(key, value);
        });

        formData.append('csrf_token', csrfToken);

        const response = await fetch(apiUrl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        });

        const raw = await response.text();
        let result;

        try {
            result = JSON.parse(raw);
        } catch (error) {
            const clean = raw.replace(/<[^>]*>/g, ' ')
                .replace(/\s+/g, ' ')
                .trim();

            throw new Error(
                'Pawn API did not return JSON. HTTP ' +
                response.status +
                (clean ? ': ' + clean.substring(0, 300) : '')
            );
        }

        if (!response.ok || !result.success) {
            throw new Error(result.message || 'Request failed.');
        }

        return result;
    }

    function statusBadge(status) {
        let className = 'st-cancelled';

        if (status === 'Active') className = 'st-active';
        if (status === 'Closed') className = 'st-closed';
        if (status === 'Auctioned') className = 'st-auctioned';
        if (status === 'Partially Paid') className = 'st-partial';

        return `<span class="badge-soft ${className}">${esc(status)}</span>`;
    }

    async function loadPawnList(page = 1) {
        loadingEl.classList.add('show');
        tableWrapEl.style.display = 'none';
        emptyEl.classList.remove('show');

        try {
            const data = await request({
                action: 'list',
                page: page,
                per_page: 20,
                status: statusEl.value,
                from_date: fromDateEl.value,
                to_date: toDateEl.value,
                search: searchEl.value.trim()
            });

            tbodyEl.innerHTML = data.pawns.map(pawn => `
                <tr>
                    <td><strong>${esc(pawn.pawn_no)}</strong></td>
                    <td>${esc(pawn.pawn_date_display)}</td>
                    <td>
                        ${esc(pawn.customer_name || 'Unknown Customer')}
                        <div class="small text-muted">${esc(pawn.mobile || '')}</div>
                    </td>
                    <td>${esc(pawn.category_name || 'Unassigned')}</td>
                    <td class="text-end">₹${money(pawn.principal_amount)}</td>
                    <td class="text-end">₹${money(pawn.balance_principal)}</td>
                    <td>${Number(pawn.interest_percent || 0).toFixed(3)}% ${esc(pawn.interest_period)}</td>
                    <td>${esc(pawn.due_date_display || '-')}</td>
                    <td>${statusBadge(pawn.status)}</td>
                    <td>
                        <a class="btn-soft py-1 px-2" href="pawn-view.php?id=${pawn.id}">
                            View
                        </a>
                    </td>
                </tr>
            `).join('');

            const hasRows = data.pawns.length > 0;

            tableWrapEl.style.display = hasRows ? '' : 'none';
            emptyEl.classList.toggle('show', !hasRows);

            totalEl.textContent = data.stats.total_pawns ?? 0;
            activeEl.textContent = data.stats.active_pawns ?? 0;
            principalEl.textContent = '₹' + money(data.stats.total_principal);
            outstandingEl.textContent = '₹' + money(data.stats.total_outstanding);

            summaryEl.textContent =
                `Showing ${data.meta.from}-${data.meta.to} of ${data.meta.total}`;

            pagesEl.innerHTML = Array.from(
                { length: Number(data.meta.total_pages || 1) },
                (_, index) => {
                    const pageNo = index + 1;
                    return `
                        <button type="button"
                                class="btn-soft py-1 px-2 ms-1 pawn-page"
                                data-page="${pageNo}">
                            ${pageNo}
                        </button>
                    `;
                }
            ).join('');
        } catch (error) {
            tbodyEl.innerHTML = '';
            tableWrapEl.style.display = 'none';
            emptyEl.classList.add('show');
            emptyEl.innerHTML =
                '<div class="text-danger fw-bold">Unable to load pawn entries</div>' +
                '<div class="small mt-2">' + esc(error.message) + '</div>';
            alert(error.message);
        } finally {
            loadingEl.classList.remove('show');
        }
    }

    filterFormEl.addEventListener('submit', event => {
        event.preventDefault();
        loadPawnList(1);
    });

    resetEl.addEventListener('click', () => {
        statusEl.value = '';
        fromDateEl.value = '';
        toDateEl.value = '';
        searchEl.value = '';
        loadPawnList(1);
    });

    document.addEventListener('click', event => {
        const pageButton = event.target.closest('.pawn-page');

        if (pageButton) {
            loadPawnList(Number(pageButton.dataset.page));
        }
    });

    loadPawnList(1);
})();
</script></body></html>
