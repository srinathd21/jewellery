<?php require __DIR__ . '/_common.php'; ?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($businessName) ?> - Pawn Categories</title>
    <?php include('includes/links.php');
    require __DIR__ . '/_style.php'; ?>
    <style>
        .grid {
            display: grid;
            grid-template-columns: 360px minmax(0, 1fr);
            gap: 14px;
            align-items: start
        }

        .sticky-panel {
            position: sticky;
            top: 82px
        }

        .table-card {
            overflow: hidden
        }

        .compact-table {
            font-size: 10px;
            margin: 0
        }

        .compact-table th {
            font-size: 9px;
            text-transform: uppercase;
            color: var(--muted);
            background: color-mix(in srgb, var(--muted) 5%, transparent);
            white-space: nowrap
        }

        .compact-table td,
        .compact-table th {
            padding: 9px 10px;
            vertical-align: middle;
            border-color: var(--line)
        }

        .badge-soft {
            display: inline-flex;
            padding: 4px 7px;
            border-radius: 999px;
            background: var(--primary-soft);
            color: var(--primary-dark);
            font-size: 9px;
            font-weight: 800
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
            .grid {
                grid-template-columns: 1fr
            }

            .sticky-panel {
                position: static
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
                        <div class="page-title">Pawn Categories</div>
                        <div class="small text-muted">Configure valuation, purity, default interest and eligible loan
                            percentage.</div>
                    </div><a href="pawn-entry.php" class="btn-soft">New Pawn Entry</a>
                </div>
            </div>
            <div class="grid">
                <section class="page-card sticky-panel">
                    <div class="page-head">
                        <div class="section-title" id="formTitle">Add Category</div>
                    </div>
                    <div class="card-body-x">
                        <form id="categoryForm">
                            <input type="hidden" name="id" id="categoryId">
                            <div class="row g-2">
                                <div class="col-5"><label class="small fw-bold">Category Code</label>
                                    <div class="input-group"><input name="category_code" id="categoryCode"
                                            class="form-control" maxlength="50" readonly required><button type="button"
                                            class="btn-soft" id="generateCodeBtn" title="Generate new code"><i
                                                class="fa-solid fa-rotate"></i></button></div>
                                    <div class="small text-muted mt-1">Generated automatically</div>
                                </div>
                                <div class="col-7"><label class="small fw-bold">Category Name</label><input
                                        name="category_name" id="categoryName" class="form-control" maxlength="120"
                                        required></div>
                                <div class="col-6"><label class="small fw-bold">Category Type</label><select
                                        name="category_type" id="categoryType" class="form-select">
                                        <option>Ornament</option>
                                        <option>Metal</option>
                                        <option>Document</option>
                                        <option>Other</option>
                                    </select></div>
                                <div class="col-6"><label class="small fw-bold">Metal Type</label><select
                                        name="metal_type" id="metalType" class="form-select">
                                        <option value="">Not Applicable</option>
                                        <option>Gold</option>
                                        <option>Silver</option>
                                        <option>Platinum</option>
                                        <option>Other</option>
                                    </select></div>
                                <div class="col-12"><label class="small fw-bold">Purity Standard</label><input
                                        name="purity_standard" id="purityStandard" class="form-control"
                                        placeholder="Example: 916 / 22K"></div>
                                <div class="col-6"><label class="small fw-bold">Minimum Purity %</label><input
                                        type="number" step="0.01" min="0" max="100" name="min_purity_percent"
                                        id="minPurity" class="form-control"></div>
                                <div class="col-6"><label class="small fw-bold">Maximum Purity %</label><input
                                        type="number" step="0.01" min="0" max="100" name="max_purity_percent"
                                        id="maxPurity" class="form-control"></div>
                                <div class="col-6"><label class="small fw-bold">Default Interest %</label><input
                                        type="number" step="0.001" min="0" name="default_interest_percent"
                                        id="defaultInterest" class="form-control" value="0"></div>
                                <div class="col-6"><label class="small fw-bold">Maximum Loan %</label><input
                                        type="number" step="0.01" min="0" max="100" name="max_loan_percent" id="maxLoan"
                                        class="form-control" value="70"></div>
                                <div class="col-6"><label class="small fw-bold">Storage Fee %</label><input
                                        type="number" step="0.01" min="0" name="storage_fee_percent" id="storageFee"
                                        class="form-control" value="0"></div>
                                <div class="col-6"><label class="small fw-bold">Valuation Method</label><select
                                        name="valuation_method" id="valuationMethod" class="form-select">
                                        <option>Weight</option>
                                        <option>Piece</option>
                                        <option>Stone</option>
                                        <option>Combined</option>
                                    </select></div>
                                <div class="col-12"><label class="small fw-bold">Description</label><textarea
                                        name="description" id="description" class="form-control" rows="3"></textarea>
                                </div>
                                <div class="col-12 d-flex gap-3 flex-wrap"><label class="small"><input type="checkbox"
                                            name="requires_certificate" id="requiresCertificate" value="1"> Certificate
                                        required</label><label class="small"><input type="checkbox"
                                            name="requires_valuation" id="requiresValuation" value="1" checked>
                                        Valuation required</label><label class="small"><input type="checkbox"
                                            name="is_active" id="isActive" value="1" checked> Active</label></div>
                            </div>
                            <div class="d-grid grid-template-columns-2 gap-2 mt-3"><button class="btn-theme"
                                    type="submit" id="saveBtn">Save Category</button><button class="btn-soft"
                                    type="button" id="resetBtn">Reset</button></div>
                        </form>
                    </div>
                </section>
                <section class="page-card table-card">
                    <div class="page-head">
                        <div class="section-title">Category List</div><input id="search" class="form-control"
                            style="max-width:260px" placeholder="Search category...">
                    </div>
                    <div class="table-responsive">
                        <table class="table compact-table">
                            <thead>
                                <tr>
                                    <th>Code</th>
                                    <th>Category</th>
                                    <th>Metal / Purity</th>
                                    <th>Interest</th>
                                    <th>Loan %</th>
                                    <th>Valuation</th>
                                    <th>Status</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody id="rows"></tbody>
                        </table>
                    </div>
                    <div id="empty" class="text-center text-muted p-4 d-none">No pawn categories found.</div>
                </section>
            </div>
            <?php include('includes/footer.php'); ?>
        </div>
    </main><?php include('includes/script.php'); ?>
    <script src="assets/js/script.js"></script>
    <script>
        (() => {
            'use strict'; const api = 'api/pawn-categories.php', csrf = <?= json_encode($csrfToken) ?>, $ = id => document.getElementById(id); let categories = [], timer; function esc(v) { return String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c])) } function toast(ok, msg) { const x = document.createElement('div'); x.className = 'toast-x ' + (ok ? 'ok' : 'bad'); x.textContent = msg; document.body.appendChild(x); setTimeout(() => x.remove(), 3000) } async function req(o) { const f = new FormData(); Object.entries(o).forEach(([k, v]) => f.append(k, v)); f.append('csrf_token', csrf); const r = await fetch(api, { method: 'POST', body: f, credentials: 'same-origin' }), j = await r.json().catch(() => ({ success: false, message: 'Invalid server response.' })); if (!r.ok || !j.success) throw new Error(j.message || 'Request failed'); return j }
            function render() { const q = $('search').value.trim().toLowerCase(), list = categories.filter(x => !q || [x.category_code, x.category_name, x.metal_type, x.purity_standard].join(' ').toLowerCase().includes(q)); $('rows').innerHTML = list.map(x => `<tr><td><strong>${esc(x.category_code)}</strong></td><td><strong>${esc(x.category_name)}</strong><div class="small text-muted">${esc(x.category_type)}</div></td><td>${esc(x.metal_type || '—')}<div class="small text-muted">${esc(x.purity_standard || '—')}</div></td><td>${Number(x.default_interest_percent || 0).toFixed(3)}%</td><td>${Number(x.max_loan_percent || 0).toFixed(2)}%</td><td>${esc(x.valuation_method)}</td><td><span class="badge-soft">${Number(x.is_active) === 1 ? 'Active' : 'Inactive'}</span></td><td class="text-end"><button class="btn-soft edit" data-id="${x.id}">Edit</button> <button class="btn-soft toggle" data-id="${x.id}">${Number(x.is_active) === 1 ? 'Disable' : 'Enable'}</button></td></tr>`).join(''); $('empty').classList.toggle('d-none', list.length > 0) } async function load() { const j = await req({ action: 'category_list', search: '' }); categories = j.categories || []; render() } async function loadNextCode() { try { const j = await req({ action: 'next_code' }); $('categoryCode').value = j.next_code || '' } catch (e) { toast(false, e.message) } } function reset() { $('categoryForm').reset(); $('categoryId').value = ''; $('requiresValuation').checked = true; $('isActive').checked = true; $('maxLoan').value = '70'; $('defaultInterest').value = '0'; $('storageFee').value = '0'; $('formTitle').textContent = 'Add Category'; loadNextCode() } function edit(id) { const x = categories.find(v => String(v.id) === String(id)); if (!x) return; $('categoryId').value = x.id; $('categoryCode').value = x.category_code; $('categoryName').value = x.category_name; $('categoryType').value = x.category_type; $('metalType').value = x.metal_type || ''; $('purityStandard').value = x.purity_standard || ''; $('minPurity').value = x.min_purity_percent ?? ''; $('maxPurity').value = x.max_purity_percent ?? ''; $('defaultInterest').value = x.default_interest_percent; $('maxLoan').value = x.max_loan_percent; $('storageFee').value = x.storage_fee_percent; $('valuationMethod').value = x.valuation_method; $('description').value = x.description || ''; $('requiresCertificate').checked = Number(x.requires_certificate) === 1; $('requiresValuation').checked = Number(x.requires_valuation) === 1; $('isActive').checked = Number(x.is_active) === 1; $('formTitle').textContent = 'Edit Category'; window.scrollTo({ top: 0, behavior: 'smooth' }) }
            $('categoryForm').onsubmit = async e => { e.preventDefault(); const f = new FormData(e.target); f.append('action', 'category_save'); f.append('csrf_token', csrf); const b = $('saveBtn'); b.disabled = true; try { const r = await fetch(api, { method: 'POST', body: f, credentials: 'same-origin' }), j = await r.json(); if (!r.ok || !j.success) throw new Error(j.message || 'Unable to save'); toast(true, j.message); reset(); await load() } catch (err) { toast(false, err.message) } finally { b.disabled = false } }; $('rows').onclick = async e => { const b = e.target.closest('button'); if (!b) return; const id = b.dataset.id; if (b.classList.contains('edit')) return edit(id); if (b.classList.contains('toggle')) { try { const j = await req({ action: 'category_toggle', id }); toast(true, j.message); await load() } catch (err) { toast(false, err.message) } } }; $('resetBtn').onclick = reset; $('generateCodeBtn').onclick = loadNextCode; $('search').oninput = () => { clearTimeout(timer); timer = setTimeout(render, 200) }; Promise.all([load(), loadNextCode()]).catch(e => toast(false, e.message));
        })();
    </script>
</body>

</html>