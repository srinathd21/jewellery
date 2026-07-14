<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));

$configCandidates = [
    __DIR__ . '/config/config.php',
    __DIR__ . '/config.php',
    __DIR__ . '/includes/config.php',
    __DIR__ . '/super-admin/includes/config.php',
];

$configLoaded = false;

foreach ($configCandidates as $configFile) {
    if (is_file($configFile)) {
        require_once $configFile;
        $configLoaded = true;
        break;
    }
}

if (!$configLoaded || !isset($conn) || !($conn instanceof mysqli)) {
    die('Database configuration is not available.');
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn->set_charset('utf8mb4');

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$businessId = (int)($_SESSION['business_id'] ?? 0);

if ($userId <= 0) {
    header('Location: login.php');
    exit;
}

if ($businessId <= 0) {
    http_response_code(403);
    die('A valid business must be selected.');
}

$pageTitle = 'Stock Report';
$page_title = 'Stock Report';
$currentPage = 'report-stock';
$businessName = (string)($_SESSION['business_name'] ?? 'ERP');

$theme = [
    'primary_color' => '#d89416',
    'primary_dark_color' => '#b86a0b',
    'primary_soft_color' => '#fff6e5',
    'sidebar_gradient_1' => '#171c21',
    'sidebar_gradient_2' => '#20272d',
    'sidebar_gradient_3' => '#101419',
    'page_background' => '#f4f3f0',
    'card_background' => '#ffffff',
    'text_color' => '#171717',
    'muted_text_color' => '#7d8794',
    'border_color' => '#e8e8e8',
    'font_family' => 'Inter',
    'heading_font_family' => 'Playfair Display',
    'border_radius_px' => 12,
];

$themeStmt = $conn->prepare(
    'SELECT * FROM business_theme_settings WHERE business_id = ? LIMIT 1'
);

if ($themeStmt) {
    $themeStmt->bind_param('i', $businessId);
    $themeStmt->execute();
    $themeRow = $themeStmt->get_result()->fetch_assoc() ?: [];
    $themeStmt->close();

    foreach ($theme as $key => $defaultValue) {
        if (isset($themeRow[$key]) && $themeRow[$key] !== '') {
            $theme[$key] = $themeRow[$key];
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo h($businessName); ?> - Stock Report</title>
<?php include 'includes/links.php'; ?>

<style>
:root{
    --primary:<?php echo h($theme['primary_color']); ?>;
    --primary-dark:<?php echo h($theme['primary_dark_color']); ?>;
    --primary-soft:<?php echo h($theme['primary_soft_color']); ?>;
    --page-bg:<?php echo h($theme['page_background']); ?>;
    --card-bg:<?php echo h($theme['card_background']); ?>;
    --text-color:<?php echo h($theme['text_color']); ?>;
    --muted-color:<?php echo h($theme['muted_text_color']); ?>;
    --border-color:<?php echo h($theme['border_color']); ?>;
    --radius:<?php echo (int)$theme['border_radius_px']; ?>px;
}

body{
    background:var(--page-bg);
    color:var(--text-color);
    font-family:<?php echo json_encode((string)$theme['font_family']); ?>,sans-serif;
}

.sidebar{
    background:linear-gradient(
        180deg,
        <?php echo h($theme['sidebar_gradient_1']); ?>,
        <?php echo h($theme['sidebar_gradient_2']); ?>,
        <?php echo h($theme['sidebar_gradient_3']); ?>
    )!important;
}

.stat-grid{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:8px;
    margin-bottom:10px;
}

.stat-card{
    background:var(--card-bg);
    border:1px solid var(--border-color);
    border-radius:var(--radius);
    padding:12px 14px;
    display:flex;
    align-items:center;
    gap:12px;
    min-height:82px;
}

.stat-icon{
    width:42px;
    height:42px;
    display:grid;
    place-items:center;
    border-radius:10px;
    background:var(--primary-soft);
    color:var(--primary-dark);
    font-size:16px;
}

.stat-label{font-size:10px;color:var(--muted-color)}
.stat-value{font-size:20px;font-weight:800;margin-top:4px}

.mini-stat-grid{
    display:grid;
    grid-template-columns:repeat(5,minmax(0,1fr));
    gap:8px;
    margin-bottom:10px;
}

.mini-stat{
    background:var(--card-bg);
    border:1px solid var(--border-color);
    border-radius:var(--radius);
    padding:10px 12px;
}

.mini-stat-label{font-size:9px;color:var(--muted-color)}
.mini-stat-value{font-size:13px;font-weight:800;margin-top:4px}

.panel{
    background:var(--card-bg);
    border:1px solid var(--border-color);
    border-radius:var(--radius);
    overflow:hidden;
    margin-bottom:10px;
}

.panel-head{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
    padding:11px 13px;
    border-bottom:1px solid var(--border-color);
}

.panel-title{font-size:12px;font-weight:800}
.panel-subtitle{font-size:9px;color:var(--muted-color);margin-top:2px}
.panel-body{padding:13px}

.filter-grid{
    display:grid;
    grid-template-columns:repeat(12,minmax(0,1fr));
    gap:10px;
}

.span-2{grid-column:span 2}
.span-3{grid-column:span 3}
.span-4{grid-column:span 4}
.span-6{grid-column:span 6}
.span-12{grid-column:span 12}

.field-label{
    display:block;
    font-size:9px;
    font-weight:700;
    text-transform:uppercase;
    color:var(--muted-color);
    margin-bottom:4px;
}

.form-control,
.form-select{
    min-height:36px;
    font-size:10px;
    border-radius:9px;
    border-color:var(--border-color);
    background:var(--card-bg);
    color:var(--text-color);
}

.btn-theme{
    background:linear-gradient(135deg,var(--primary),var(--primary-dark));
    border:0;
    color:#fff;
    border-radius:9px;
    font-size:10px;
    font-weight:700;
    min-height:36px;
    padding:8px 13px;
}

.btn-light-custom{
    border:1px solid var(--border-color);
    background:var(--card-bg);
    color:var(--text-color);
    border-radius:9px;
    font-size:10px;
    font-weight:700;
    min-height:36px;
    padding:8px 12px;
}

.compact-table{margin:0;font-size:10px}
.compact-table th{
    font-size:9px;
    text-transform:uppercase;
    color:var(--muted-color);
    background:color-mix(in srgb,var(--muted-color) 6%,transparent);
    padding:10px 11px;
    white-space:nowrap;
    border-color:var(--border-color);
}
.compact-table td{
    padding:10px 11px;
    vertical-align:middle;
    background:var(--card-bg)!important;
    color:var(--text-color);
    border-color:var(--border-color);
    white-space:nowrap;
}

.status-badge{
    display:inline-flex;
    border-radius:999px;
    padding:4px 8px;
    font-size:8px;
    font-weight:800;
}

.status-in{background:#eaf8f0;color:#168449}
.status-low{background:#fff5d9;color:#a96b00}
.status-out{background:#fdecec;color:#c0392b}

.amount-value{font-weight:800;color:#1557d6}
.empty-state{padding:42px 20px;text-align:center;color:var(--muted-color)}

.summary-table{
    margin:0;
    font-size:10px;
}
.summary-table th,
.summary-table td{
    padding:9px 10px;
    border-color:var(--border-color);
    background:var(--card-bg)!important;
}

.theme-toast{
    position:fixed;
    right:18px;
    top:78px;
    z-index:20000;
    min-width:260px;
    max-width:420px;
    padding:11px 14px;
    border-radius:10px;
    color:#fff;
    background:#c0392b;
    font-size:11px;
    font-weight:600;
    box-shadow:0 14px 35px rgba(0,0,0,.22);
    opacity:0;
    transform:translateY(-10px);
    transition:.22s;
}
.theme-toast.show{opacity:1;transform:translateY(0)}

body.dark-mode,
body[data-theme="dark"],
html.dark-mode body,
html[data-theme="dark"] body{
    --page-bg:#0f151b;
    --card-bg:#182129;
    --text-color:#f3f6f8;
    --muted-color:#9aa7b3;
    --border-color:#2c3944;
}

@media(max-width:1199px){
    .stat-grid{grid-template-columns:repeat(2,1fr)}
    .mini-stat-grid{grid-template-columns:repeat(3,1fr)}
    .filter-grid{grid-template-columns:repeat(2,1fr)}
    .span-2,.span-3,.span-4,.span-6,.span-12{grid-column:span 1}
}

@media(max-width:767px){
    .stat-grid,.mini-stat-grid,.filter-grid{grid-template-columns:1fr}
    .panel-head{align-items:flex-start;flex-direction:column}
}

@media print{
    .sidebar,.topbar,.panel.filters-panel,footer{display:none!important}
    .app-main{margin-left:0!important}
    .content-wrap{padding:0!important}
    .panel,.stat-card,.mini-stat{box-shadow:none!important}
}
</style>
</head>

<body>
<?php include 'includes/sidebar.php'; ?>

<main class="app-main">
<?php include 'includes/nav.php'; ?>

<div class="content-wrap">

    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-icon"><i class="fa-solid fa-boxes-stacked"></i></div>
            <div>
                <div class="stat-label">Total Products</div>
                <div class="stat-value" id="totalProducts">0</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon"><i class="fa-solid fa-cubes"></i></div>
            <div>
                <div class="stat-label">Total Quantity</div>
                <div class="stat-value" id="totalQuantity">0.000</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon"><i class="fa-solid fa-weight-scale"></i></div>
            <div>
                <div class="stat-label">Total Weight</div>
                <div class="stat-value" id="totalWeight">0.000 g</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon"><i class="fa-solid fa-indian-rupee-sign"></i></div>
            <div>
                <div class="stat-label">Stock Value</div>
                <div class="stat-value" id="totalValue">₹0.00</div>
            </div>
        </div>
    </div>

    <div class="mini-stat-grid">
        <div class="mini-stat">
            <div class="mini-stat-label">In Stock</div>
            <div class="mini-stat-value text-success" id="inStockCount">0</div>
        </div>

        <div class="mini-stat">
            <div class="mini-stat-label">Low Stock</div>
            <div class="mini-stat-value text-warning" id="lowStockCount">0</div>
        </div>

        <div class="mini-stat">
            <div class="mini-stat-label">Out of Stock</div>
            <div class="mini-stat-value text-danger" id="outStockCount">0</div>
        </div>

        <div class="mini-stat">
            <div class="mini-stat-label">Categories</div>
            <div class="mini-stat-value" id="categoryCount">0</div>
        </div>

        <div class="mini-stat">
            <div class="mini-stat-label">Purities</div>
            <div class="mini-stat-value" id="purityCount">0</div>
        </div>
    </div>

    <div class="panel filters-panel">
        <div class="panel-head">
            <div>
                <div class="panel-title">Stock Report Filters</div>
                <div class="panel-subtitle">Filter stock by category, purity, status, search text, and sorting.</div>
            </div>

            <button type="button" class="btn btn-light-custom" id="refreshReport">
                <i class="fa-solid fa-rotate"></i>
            </button>
        </div>

        <div class="panel-body">
            <form id="filterForm" class="filter-grid">
                <div class="span-2">
                    <label class="field-label">Category</label>
                    <select name="category_id" id="categoryFilter" class="form-select">
                        <option value="0">All Categories</option>
                    </select>
                </div>

                <div class="span-2">
                    <label class="field-label">Purity</label>
                    <select name="purity" id="purityFilter" class="form-select">
                        <option value="all">All Purities</option>
                    </select>
                </div>

                <div class="span-2">
                    <label class="field-label">Stock Status</label>
                    <select name="stock_type" class="form-select">
                        <option value="all">All Stock</option>
                        <option value="in_stock">In Stock</option>
                        <option value="low_stock">Low Stock</option>
                        <option value="out_of_stock">Out of Stock</option>
                    </select>
                </div>

                <div class="span-2">
                    <label class="field-label">Sort By</label>
                    <select name="sort_by" class="form-select">
                        <option value="product_name">Product Name</option>
                        <option value="product_code">Product Code</option>
                        <option value="current_stock_qty">Current Stock</option>
                        <option value="sale_rate">Sale Rate</option>
                    </select>
                </div>

                <div class="span-2">
                    <label class="field-label">Order</label>
                    <select name="sort_order" class="form-select">
                        <option value="ASC">Ascending</option>
                        <option value="DESC">Descending</option>
                    </select>
                </div>

                <div class="span-2">
                    <label class="field-label">Search</label>
                    <input
                        type="search"
                        name="search"
                        class="form-control"
                        placeholder="Product, code, barcode..."
                    >
                </div>

                <div class="span-12 d-flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-theme">
                        <i class="fa-solid fa-magnifying-glass me-1"></i>Show Report
                    </button>

                    <button type="button" class="btn btn-light-custom" id="resetFilters">
                        Reset
                    </button>

                    <button type="button" class="btn btn-light-custom ms-auto" data-export="excel">
                        <i class="fa-solid fa-file-excel me-1"></i>Excel
                    </button>

                    <button type="button" class="btn btn-light-custom" data-export="pdf">
                        <i class="fa-solid fa-file-pdf me-1"></i>PDF
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head">
            <div>
                <div class="panel-title">Category-wise Summary</div>
                <div class="panel-subtitle">Product count, total weight, stock value, and percentage by category.</div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table summary-table">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th>Products</th>
                        <th>Total Weight</th>
                        <th>Stock Value</th>
                        <th>% of Total</th>
                    </tr>
                </thead>
                <tbody id="categorySummaryBody"></tbody>
            </table>
        </div>
    </div>

    <div class="panel" id="purityPanel">
        <div class="panel-head">
            <div>
                <div class="panel-title">Purity-wise Summary</div>
                <div class="panel-subtitle">Product count, total weight, stock value, and percentage by purity.</div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table summary-table">
                <thead>
                    <tr>
                        <th>Purity</th>
                        <th>Products</th>
                        <th>Total Weight</th>
                        <th>Stock Value</th>
                        <th>% of Total</th>
                    </tr>
                </thead>
                <tbody id="puritySummaryBody"></tbody>
            </table>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head">
            <div>
                <div class="panel-title">Stock Report</div>
                <div class="panel-subtitle">All matching products and current stock details.</div>
            </div>
        </div>

        <div id="loadingState" class="empty-state">
            <i class="fa-solid fa-spinner fa-spin mb-2"></i>
            <div>Loading stock report...</div>
        </div>

        <div id="tableWrap" class="table-responsive d-none">
            <table class="table compact-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Product Code</th>
                        <th>Product Name</th>
                        <th>Category</th>
                        <th>Purity</th>
                        <th>Unit</th>
                        <th>Current Stock</th>
                        <th>Min Stock</th>
                        <th>Net Wt/Pc</th>
                        <th>Total Weight</th>
                        <th>Sale Rate</th>
                        <th>Stock Value</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody id="stockTableBody"></tbody>
            </table>
        </div>

        <div id="emptyState" class="empty-state d-none">
            No products found for the selected filters.
        </div>
    </div>

    <?php include 'includes/footer.php'; ?>
</div>
</main>

<?php include 'includes/script.php'; ?>
<script src="assets/js/script.js"></script>

<script>
(function(){
    'use strict';

    const filterForm=document.getElementById('filterForm');
    const categoryFilter=document.getElementById('categoryFilter');
    const purityFilter=document.getElementById('purityFilter');
    const stockTableBody=document.getElementById('stockTableBody');
    const categorySummaryBody=document.getElementById('categorySummaryBody');
    const puritySummaryBody=document.getElementById('puritySummaryBody');
    const purityPanel=document.getElementById('purityPanel');
    const tableWrap=document.getElementById('tableWrap');
    const loading=document.getElementById('loadingState');
    const empty=document.getElementById('emptyState');

    function toast(message){
        const element=document.createElement('div');
        element.className='theme-toast';
        element.textContent=message;
        document.body.appendChild(element);
        requestAnimationFrame(()=>element.classList.add('show'));
        setTimeout(()=>{
            element.classList.remove('show');
            setTimeout(()=>element.remove(),250);
        },3300);
    }

    function escapeHtml(value){
        const div=document.createElement('div');
        div.textContent=value??'';
        return div.innerHTML;
    }

    function money(value){
        return Number(value||0).toLocaleString('en-IN',{
            minimumFractionDigits:2,
            maximumFractionDigits:2
        });
    }

    function weight(value){
        return Number(value||0).toLocaleString('en-IN',{
            minimumFractionDigits:3,
            maximumFractionDigits:3
        });
    }

    function statusClass(status){
        if(status==='In Stock') return 'status-in';
        if(status==='Low Stock') return 'status-low';
        return 'status-out';
    }

    async function requestJson(url){
        const response=await fetch(url,{
            credentials:'same-origin',
            headers:{
                Accept:'application/json',
                'X-Requested-With':'XMLHttpRequest'
            }
        });

        const raw=await response.text();
        let result;

        try{
            result=JSON.parse(raw);
        }catch(error){
            throw new Error(
                raw
                    ? 'Server returned invalid output: '+raw.substring(0,180)
                    : 'Server returned an empty response.'
            );
        }

        if(!response.ok||!result.success){
            throw new Error(result.message||'Request failed.');
        }

        return result;
    }

    function renderBootstrap(result){
        categoryFilter.innerHTML=
            '<option value="0">All Categories</option>'+
            (result.categories||[]).map(row=>
                `<option value="${Number(row.id)}">${escapeHtml(row.category_name)}</option>`
            ).join('');

        purityFilter.innerHTML=
            '<option value="all">All Purities</option>'+
            (result.purities||[]).map(value=>
                `<option value="${escapeHtml(value)}">${escapeHtml(value)}</option>`
            ).join('');
    }

    function renderSummary(result){
        const s=result.summary||{};

        document.getElementById('totalProducts').textContent=
            Number(s.total_products||0);

        document.getElementById('totalQuantity').textContent=
            weight(s.total_quantity);

        document.getElementById('totalWeight').textContent=
            weight(s.total_weight)+' g';

        document.getElementById('totalValue').textContent=
            '₹'+money(s.total_value);

        document.getElementById('inStockCount').textContent=
            Number(s.in_stock_count||0);

        document.getElementById('lowStockCount').textContent=
            Number(s.low_stock_count||0);

        document.getElementById('outStockCount').textContent=
            Number(s.out_of_stock_count||0);

        document.getElementById('categoryCount').textContent=
            Number((result.category_summary||[]).length);

        document.getElementById('purityCount').textContent=
            Number((result.purity_summary||[]).length);
    }

    function renderCategorySummary(rows,totalValue){
        if(!rows.length){
            categorySummaryBody.innerHTML=
                '<tr><td colspan="5" class="empty-state">No category summary available.</td></tr>';
            return;
        }

        categorySummaryBody.innerHTML=rows.map(row=>`
            <tr>
                <td><strong>${escapeHtml(row.label)}</strong></td>
                <td>${Number(row.count||0)}</td>
                <td>${weight(row.weight)} g</td>
                <td class="amount-value">₹${money(row.value)}</td>
                <td>${Number(row.percentage||0).toFixed(1)}%</td>
            </tr>
        `).join('');
    }

    function renderPuritySummary(rows){
        purityPanel.classList.toggle('d-none',rows.length<=1);

        if(!rows.length){
            puritySummaryBody.innerHTML='';
            return;
        }

        puritySummaryBody.innerHTML=rows.map(row=>`
            <tr>
                <td><strong>${escapeHtml(row.label)}</strong></td>
                <td>${Number(row.count||0)}</td>
                <td>${weight(row.weight)} g</td>
                <td class="amount-value">₹${money(row.value)}</td>
                <td>${Number(row.percentage||0).toFixed(1)}%</td>
            </tr>
        `).join('');
    }

    function renderProducts(rows){
        loading.classList.add('d-none');

        if(!rows.length){
            tableWrap.classList.add('d-none');
            empty.classList.remove('d-none');
            stockTableBody.innerHTML='';
            return;
        }

        empty.classList.add('d-none');
        tableWrap.classList.remove('d-none');

        stockTableBody.innerHTML=rows.map((row,index)=>`
            <tr>
                <td>${index+1}</td>
                <td><strong>${escapeHtml(row.product_code||'')}</strong></td>
                <td>
                    ${escapeHtml(row.product_name||'')}
                    ${row.design_name?'<div class="panel-subtitle">'+escapeHtml(row.design_name)+'</div>':''}
                </td>
                <td>${escapeHtml(row.category_name||'Uncategorized')}</td>
                <td>${escapeHtml(row.purity||'—')}</td>
                <td>${escapeHtml(row.unit||'—')}</td>
                <td><strong>${weight(row.current_stock_qty)}</strong></td>
                <td>${weight(row.min_stock_qty)}</td>
                <td>${weight(row.net_weight)} g</td>
                <td>${weight(row.total_weight)} g</td>
                <td>₹${money(row.sale_rate)}</td>
                <td class="amount-value">₹${money(row.stock_value)}</td>
                <td>
                    <span class="status-badge ${statusClass(row.stock_status)}">
                        ${escapeHtml(row.stock_status)}
                    </span>
                </td>
            </tr>
        `).join('');
    }

    async function loadBootstrap(){
        try{
            const result=await requestJson(
                'api/report-stock.php?action=bootstrap'
            );
            renderBootstrap(result);
        }catch(error){
            toast(error.message);
        }
    }

    async function loadReport(){
        loading.classList.remove('d-none');
        tableWrap.classList.add('d-none');
        empty.classList.add('d-none');

        try{
            const params=new URLSearchParams(new FormData(filterForm));
            params.set('action','list');

            const result=await requestJson(
                'api/report-stock.php?'+params.toString()
            );

            renderSummary(result);
            renderCategorySummary(
                result.category_summary||[],
                Number(result.summary?.total_value||0)
            );
            renderPuritySummary(result.purity_summary||[]);
            renderProducts(result.rows||[]);
        }catch(error){
            loading.classList.add('d-none');
            empty.classList.remove('d-none');
            empty.textContent=error.message;
            toast(error.message);
        }
    }

    filterForm.addEventListener('submit',event=>{
        event.preventDefault();
        loadReport();
    });

    document.getElementById('refreshReport')
        .addEventListener('click',loadReport);

    document.getElementById('resetFilters')
        .addEventListener('click',async()=>{
            filterForm.reset();
            await loadBootstrap();
            await loadReport();
        });

    document.querySelectorAll('[data-export]').forEach(button=>{
        button.addEventListener('click',()=>{
            const params=new URLSearchParams(new FormData(filterForm));
            params.set(
                'action',
                button.dataset.export==='excel'
                    ? 'export_excel'
                    : 'export_pdf'
            );

            window.location.href=
                'api/report-stock.php?'+params.toString();
        });
    });

    (async()=>{
        await loadBootstrap();
        await loadReport();
    })();
})();
</script>
</body>
</html>
