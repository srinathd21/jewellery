<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));

foreach ([
    __DIR__ . '/config/config.php',
    __DIR__ . '/config.php',
    __DIR__ . '/includes/config.php',
    __DIR__ . '/super-admin/includes/config.php',
] as $configFile) {
    if (is_file($configFile)) {
        require_once $configFile;
        break;
    }
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database configuration is not available.');
}

$conn->set_charset('utf8mb4');

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? ($_SESSION['default_branch_id'] ?? 0));

if ($businessId <= 0) {
    die('A valid business must be selected.');
}

if (empty($_SESSION['pawn_category_csrf'])) {
    $_SESSION['pawn_category_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = (string)$_SESSION['pawn_category_csrf'];

$theme = [
    'primary_color' => '#d89416',
    'primary_dark_color' => '#b86a0b',
    'primary_soft_color' => '#fff6e5',
    'page_background' => '#f4f3f0',
    'card_background' => '#ffffff',
    'text_color' => '#171717',
    'muted_text_color' => '#7d8794',
    'border_color' => '#e8e8e8',
    'font_family' => 'Inter',
    'heading_font_family' => 'Playfair Display',
    'border_radius_px' => 12,
    'sidebar_width_px' => 230,
    'sidebar_gradient_1' => '#171c21',
    'sidebar_gradient_2' => '#20272d',
    'sidebar_gradient_3' => '#101419',
];

$stmt = $conn->prepare(
    'SELECT * FROM business_theme_settings WHERE business_id = ? LIMIT 1'
);

if ($stmt) {
    $stmt->bind_param('i', $businessId);
    $stmt->execute();
    $themeRow = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    foreach ($theme as $key => $defaultValue) {
        if (isset($themeRow[$key]) && $themeRow[$key] !== '') {
            $theme[$key] = $themeRow[$key];
        }
    }
}

$pageTitle = 'Pawn Categories';
$businessName = (string)($_SESSION['business_name'] ?? 'Jewellery ERP');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">

    <title><?= e($businessName) ?> - Pawn Categories</title>

    <?php include('includes/links.php'); ?>

    <style>
        :root {
            --primary: <?= e($theme['primary_color']) ?>;
            --primary-dark: <?= e($theme['primary_dark_color']) ?>;
            --primary-soft: <?= e($theme['primary_soft_color']) ?>;
            --page-bg: <?= e($theme['page_background']) ?>;
            --card-bg: <?= e($theme['card_background']) ?>;
            --text: <?= e($theme['text_color']) ?>;
            --muted: <?= e($theme['muted_text_color']) ?>;
            --line: <?= e($theme['border_color']) ?>;
            --radius: <?= (int)$theme['border_radius_px'] ?>px;
            --sidebar-width: <?= (int)$theme['sidebar_width_px'] ?>px;
            --sidebar-gradient-1: <?= e($theme['sidebar_gradient_1']) ?>;
            --sidebar-gradient-2: <?= e($theme['sidebar_gradient_2']) ?>;
            --sidebar-gradient-3: <?= e($theme['sidebar_gradient_3']) ?>;
        }

        body {
            background: var(--page-bg);
            color: var(--text);
            font-family: <?= json_encode($theme['font_family']) ?>, sans-serif;
        }

        .sidebar {
            background: linear-gradient(
                180deg,
                var(--sidebar-gradient-1),
                var(--sidebar-gradient-2),
                var(--sidebar-gradient-3)
            ) !important;
        }

        .page-card,
        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--line);
            border-radius: var(--radius);
        }

        .page-head {
            padding: 15px 17px;
            border-bottom: 1px solid var(--line);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }

        .page-title {
            font: 700 20px <?= json_encode($theme['heading_font_family']) ?>, serif;
        }

        .card-body-x {
            padding: 15px;
        }

        .stat-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 10px;
        }

        .stat-card {
            padding: 14px;
        }

        .stat-label {
            font-size: 10px;
            color: var(--muted);
        }

        .stat-value {
            font-size: 20px;
            font-weight: 800;
        }

        .form-control,
        .form-select {
            min-height: 39px;
            border: 1px solid var(--line);
            border-radius: 9px;
            background: var(--card-bg);
            color: var(--text);
            font-size: 11px;
        }

        .btn-theme {
            border: 0;
            border-radius: 9px;
            padding: 9px 15px;
            color: #fff;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            font-size: 11px;
            font-weight: 700;
            text-decoration: none;
        }

        .btn-soft {
            border: 1px solid var(--line);
            border-radius: 9px;
            padding: 9px 15px;
            background: var(--card-bg);
            color: var(--text);
            font-size: 11px;
            text-decoration: none;
        }

        .category-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
        }

        .category-card {
            background: var(--card-bg);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            overflow: hidden;
        }

        .category-card-head {
            padding: 13px 14px;
            border-bottom: 1px solid var(--line);
            display: flex;
            justify-content: space-between;
            gap: 10px;
        }

        .category-code {
            font-size: 9px;
            color: var(--muted);
            text-transform: uppercase;
        }

        .category-name {
            font-size: 14px;
            font-weight: 800;
        }

        .category-body {
            padding: 14px;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 9px;
        }

        .detail-box {
            padding: 9px;
            background: color-mix(in srgb, var(--muted) 5%, transparent);
            border-radius: 9px;
        }

        .detail-label {
            font-size: 9px;
            color: var(--muted);
            text-transform: uppercase;
        }

        .detail-value {
            margin-top: 2px;
            font-size: 11px;
            font-weight: 700;
        }

        .badge-soft {
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 9px;
            font-weight: 700;
        }

        .status-active {
            background: #eaf8f0;
            color: #168449;
        }

        .status-inactive {
            background: #edf0f2;
            color: #5f6b74;
        }

        .yes-badge {
            background: #eaf2ff;
            color: #2457a7;
        }

        .no-badge {
            background: #edf0f2;
            color: #5f6b74;
        }

        .loading,
        .empty {
            display: none;
            padding: 50px;
            text-align: center;
            color: var(--muted);
        }

        .loading.show,
        .empty.show {
            display: block;
        }

        .theme-toast {
            position: fixed;
            top: 78px;
            right: 18px;
            z-index: 20000;
            padding: 11px 14px;
            border-radius: 10px;
            color: #fff;
            font-size: 11px;
            font-weight: 600;
            opacity: 0;
            transform: translateY(-10px);
            transition: .22s;
        }

        .theme-toast.show {
            opacity: 1;
            transform: none;
        }

        .theme-toast-success {
            background: #168449;
        }

        .theme-toast-error {
            background: #c0392b;
        }

        body.dark-mode,
        body[data-theme=dark] {
            --page-bg: #0f151b;
            --card-bg: #182129;
            --text: #f3f6f8;
            --muted: #9aa7b3;
            --line: #2c3944;
        }

        @media (max-width: 1199px) {
            .category-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 767px) {
            .stat-grid,
            .category-grid {
                grid-template-columns: 1fr;
            }
        }

        .modal {
            font-family: <?= json_encode($theme['font_family']) ?>, sans-serif;
        }

        .modal-dialog {
            max-width: 820px;
        }

        .modal-content {
            background: var(--card-bg);
            color: var(--text);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            box-shadow: 0 18px 55px rgba(15, 23, 42, 0.18);
            overflow: hidden;
        }

        .modal-header {
            padding: 15px 18px;
            background: var(--card-bg);
            border-bottom: 1px solid var(--line);
        }

        .modal-title {
            margin: 0;
            color: var(--text);
            font-family: <?= json_encode($theme['heading_font_family']) ?>, serif;
            font-size: 18px;
            font-weight: 700;
        }

        .modal-body {
            padding: 18px;
            background: var(--card-bg);
        }

        .modal-footer {
            padding: 13px 18px;
            background: var(--card-bg);
            border-top: 1px solid var(--line);
        }

        .modal .form-label {
            margin-bottom: 5px;
            color: var(--text);
            font-family: <?= json_encode($theme['font_family']) ?>, sans-serif;
            font-size: 11px;
            font-weight: 700;
        }

        .modal .form-control,
        .modal .form-select {
            width: 100%;
            min-height: 40px;
            padding: 7px 10px;
            color: var(--text);
            background: var(--card-bg);
            border: 1px solid var(--line);
            border-radius: 9px;
            font-family: <?= json_encode($theme['font_family']) ?>, sans-serif;
            font-size: 11px;
            line-height: 1.4;
        }

        .modal textarea.form-control {
            min-height: 88px;
            resize: vertical;
        }

        .modal input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: var(--primary);
        }

        .modal .btn-close {
            opacity: .65;
        }

        .modal-backdrop {
            background-color: #0f172a;
        }

        .modal-backdrop.show {
            opacity: .5;
        }

        .category-checkbox-card {
            height: 100%;
            padding: 10px 12px;
            border: 1px solid var(--line);
            border-radius: 9px;
            background: color-mix(in srgb, var(--muted) 4%, transparent);
        }

        .category-checkbox-card label {
            color: var(--text);
            font-size: 11px;
            font-weight: 600;
        }


        /* Keep action buttons visible inside the screen */
        #categoryModal .modal-dialog {
            width: min(920px, calc(100vw - 24px));
            max-width: 920px;
            margin: 12px auto;
        }

        #categoryModal .modal-content {
            max-height: calc(100vh - 24px);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        #categoryModal .modal-header {
            flex: 0 0 auto;
        }

        #categoryModal .modal-body {
            flex: 1 1 auto;
            min-height: 0;
            overflow-y: auto;
            padding: 16px 18px;
        }

        #categoryModal .modal-footer {
            position: sticky;
            bottom: 0;
            z-index: 5;
            flex: 0 0 auto;
            background: var(--card-bg);
            box-shadow: 0 -8px 20px rgba(15, 23, 42, 0.06);
        }

        #categoryModal .modal-footer .btn-soft,
        #categoryModal .modal-footer .btn-theme {
            min-width: 120px;
            min-height: 40px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        #categoryModal .form-control,
        #categoryModal .form-select {
            min-height: 38px;
        }

        #categoryModal textarea.form-control {
            min-height: 76px;
        }

        @media (max-width: 767px) {
            #categoryModal .modal-dialog {
                width: calc(100vw - 12px);
                margin: 6px auto;
            }

            #categoryModal .modal-content {
                max-height: calc(100vh - 12px);
            }

            #categoryModal .modal-footer {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 8px;
            }

            #categoryModal .modal-footer > * {
                width: 100%;
                margin: 0;
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
                    <div class="page-title">Pawn Categories</div>
                    <div class="small text-muted">
                        Manage pawn types, purity, valuation and loan settings.
                    </div>
                </div>

                <button type="button" class="btn-theme" id="newCategoryBtn">
                    <i class="fa-solid fa-plus me-1"></i> Add Category
                </button>
            </div>
        </div>

        <div class="stat-grid mb-3">
            <div class="stat-card">
                <div class="stat-label">Total Categories</div>
                <div class="stat-value" id="totalCategories">0</div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Active Categories</div>
                <div class="stat-value" id="activeCategories">0</div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Certificate Required</div>
                <div class="stat-value" id="certificateCategories">0</div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Valuation Required</div>
                <div class="stat-value" id="valuationCategories">0</div>
            </div>
        </div>

        <div class="page-card mb-3">
            <div class="card-body-x">
                <form id="filterForm" class="row g-2">
                    <div class="col-md-5">
                        <input type="search"
                               id="searchInput"
                               class="form-control"
                               placeholder="Search category name, code, type or metal">
                    </div>

                    <div class="col-md-2">
                        <select id="typeFilter" class="form-select">
                            <option value="">All Types</option>
                            <option value="Ornament">Ornament</option>
                            <option value="Coin">Coin</option>
                            <option value="Bar">Bar</option>
                            <option value="Stone">Stone</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>

                    <div class="col-md-2">
                        <select id="metalFilter" class="form-select">
                            <option value="">All Metals</option>
                            <option value="Gold">Gold</option>
                            <option value="Silver">Silver</option>
                            <option value="Platinum">Platinum</option>
                            <option value="Diamond">Diamond</option>
                            <option value="Mixed">Mixed</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>

                    <div class="col-md-1">
                        <select id="statusFilter" class="form-select">
                            <option value="">All</option>
                            <option value="1">Active</option>
                            <option value="0">Inactive</option>
                        </select>
                    </div>

                    <div class="col-md-2 d-flex gap-2">
                        <button type="submit" class="btn-theme flex-grow-1">Search</button>
                        <button type="button" id="resetFilterBtn" class="btn-soft">Reset</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="page-card">
            <div id="loadingBox" class="loading show">
                <i class="fa-solid fa-spinner fa-spin me-2"></i>
                Loading categories...
            </div>

            <div id="categoryGrid" class="category-grid p-3" style="display:none"></div>

            <div id="emptyBox" class="empty">
                <i class="fa-regular fa-folder-open fa-2x mb-2"></i>
                <div>No pawn categories found.</div>
            </div>
        </div>

        <?php include('includes/footer.php'); ?>
    </div>
</main>

<div class="modal fade" id="categoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <form id="categoryForm">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="category_id" id="categoryId" value="0">

                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Add Pawn Category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-2">
                        <div class="col-md-4">
                            <label class="form-label">Category Code</label>
                            <input type="text"
                                   name="category_code"
                                   id="categoryCode"
                                   class="form-control"
                                   readonly>
                        </div>

                        <div class="col-md-8">
                            <label class="form-label">Category Name</label>
                            <input type="text"
                                   name="category_name"
                                   id="categoryName"
                                   class="form-control"
                                   required>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Category Type</label>
                            <select name="category_type" id="categoryType" class="form-select" required>
                                <option value="Ornament">Ornament</option>
                                <option value="Coin">Coin</option>
                                <option value="Bar">Bar</option>
                                <option value="Stone">Stone</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Metal Type</label>
                            <select name="metal_type" id="metalType" class="form-select" required>
                                <option value="Gold">Gold</option>
                                <option value="Silver">Silver</option>
                                <option value="Platinum">Platinum</option>
                                <option value="Diamond">Diamond</option>
                                <option value="Mixed">Mixed</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Purity Standard</label>
                            <input type="text"
                                   name="purity_standard"
                                   id="purityStandard"
                                   class="form-control"
                                   placeholder="22K (916)">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Minimum Purity %</label>
                            <input type="number"
                                   step="0.01"
                                   min="0"
                                   max="100"
                                   name="min_purity_percent"
                                   id="minPurity"
                                   class="form-control"
                                   value="0">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Maximum Purity %</label>
                            <input type="number"
                                   step="0.01"
                                   min="0"
                                   max="100"
                                   name="max_purity_percent"
                                   id="maxPurity"
                                   class="form-control"
                                   value="100">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Default Interest %</label>
                            <input type="number"
                                   step="0.001"
                                   min="0"
                                   name="default_interest_percent"
                                   id="interestPercent"
                                   class="form-control"
                                   value="0">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Maximum Loan %</label>
                            <input type="number"
                                   step="0.01"
                                   min="0"
                                   max="100"
                                   name="max_loan_percent"
                                   id="maxLoanPercent"
                                   class="form-control"
                                   value="0">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Storage Fee %</label>
                            <input type="number"
                                   step="0.01"
                                   min="0"
                                   name="storage_fee_percent"
                                   id="storageFeePercent"
                                   class="form-control"
                                   value="0">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Valuation Method</label>
                            <select name="valuation_method"
                                    id="valuationMethod"
                                    class="form-select">
                                <option value="Weight">Weight</option>
                                <option value="Fixed">Fixed</option>
                                <option value="Market Rate">Market Rate</option>
                                <option value="Manual">Manual</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Status</label>
                            <select name="is_active" id="isActive" class="form-select">
                                <option value="1">Active</option>
                                <option value="0">Inactive</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <div class="category-checkbox-card d-flex flex-column justify-content-center">
                                <label class="d-flex align-items-center gap-2 mb-2">
                                    <input type="checkbox"
                                           name="requires_certificate"
                                           id="requiresCertificate"
                                           value="1">
                                    Requires Certificate
                                </label>

                                <label class="d-flex align-items-center gap-2">
                                    <input type="checkbox"
                                           name="requires_valuation"
                                           id="requiresValuation"
                                           value="1">
                                    Requires Valuation
                                </label>
                            </div>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Description</label>
                            <textarea name="description"
                                      id="description"
                                      class="form-control"
                                      rows="3"></textarea>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-soft" data-bs-dismiss="modal"><i class="fa-solid fa-xmark me-1"></i> Cancel</button>
                    <button type="submit" id="saveBtn" class="btn-theme"><i class="fa-solid fa-floppy-disk me-1"></i> Save Category</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include('includes/script.php'); ?>
<script src="assets/js/script.js"></script>

<script>
(() => {
    'use strict';

    const apiUrl = 'api/pawn-categories.php';
    const csrfToken = <?= json_encode($csrfToken) ?>;

    const loadingBox = document.getElementById('loadingBox');
    const categoryGrid = document.getElementById('categoryGrid');
    const emptyBox = document.getElementById('emptyBox');

    const categoryModalElement = document.getElementById('categoryModal');

    function getCategoryModal() {
        if (window.bootstrap && bootstrap.Modal) {
            return bootstrap.Modal.getOrCreateInstance(categoryModalElement);
        }

        return null;
    }

    function showCategoryModal() {
        const modal = getCategoryModal();

        if (modal) {
            modal.show();
            return;
        }

        categoryModalElement.style.display = 'block';
        categoryModalElement.classList.add('show');
        categoryModalElement.removeAttribute('aria-hidden');
        categoryModalElement.setAttribute('aria-modal', 'true');
        document.body.classList.add('modal-open');

        let backdrop = document.getElementById('categoryModalFallbackBackdrop');

        if (!backdrop) {
            backdrop = document.createElement('div');
            backdrop.id = 'categoryModalFallbackBackdrop';
            backdrop.className = 'modal-backdrop fade show';
            backdrop.addEventListener('click', hideCategoryModal);
            document.body.appendChild(backdrop);
        }
    }

    function hideCategoryModal() {
        const modal = getCategoryModal();

        if (modal) {
            modal.hide();
            return;
        }

        categoryModalElement.style.display = 'none';
        categoryModalElement.classList.remove('show');
        categoryModalElement.setAttribute('aria-hidden', 'true');
        categoryModalElement.removeAttribute('aria-modal');
        document.body.classList.remove('modal-open');

        document.getElementById('categoryModalFallbackBackdrop')?.remove();
    }

    function esc(value) {
        return String(value ?? '').replace(/[&<>'"]/g, character => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            "'": '&#039;',
            '"': '&quot;'
        }[character]));
    }

    function number(value, digits = 2) {
        return Number(value || 0).toLocaleString('en-IN', {
            minimumFractionDigits: digits,
            maximumFractionDigits: digits
        });
    }

    function toast(type, message) {
        const element = document.createElement('div');
        element.className = 'theme-toast theme-toast-' + type;
        element.textContent = message;
        document.body.appendChild(element);

        requestAnimationFrame(() => element.classList.add('show'));

        setTimeout(() => {
            element.classList.remove('show');
            setTimeout(() => element.remove(), 250);
        }, 3400);
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
            const clean = raw
                .replace(/<[^>]*>/g, ' ')
                .replace(/\s+/g, ' ')
                .trim();

            throw new Error(
                'Pawn Categories API did not return JSON. HTTP ' +
                response.status +
                (clean ? ': ' + clean.substring(0, 300) : '')
            );
        }

        if (!response.ok || !result.success) {
            throw new Error(result.message || 'Request failed.');
        }

        return result;
    }

    function activeBadge(value) {
        return Number(value) === 1
            ? '<span class="badge-soft status-active">Active</span>'
            : '<span class="badge-soft status-inactive">Inactive</span>';
    }

    function yesNoBadge(value) {
        return Number(value) === 1
            ? '<span class="badge-soft yes-badge">Yes</span>'
            : '<span class="badge-soft no-badge">No</span>';
    }

    function renderCategories(categories) {
        categoryGrid.innerHTML = categories.map(category => `
            <article class="category-card">
                <div class="category-card-head">
                    <div>
                        <div class="category-code">${esc(category.category_code)}</div>
                        <div class="category-name">${esc(category.category_name)}</div>
                    </div>

                    <div>${activeBadge(category.is_active)}</div>
                </div>

                <div class="category-body">
                    <div class="detail-grid">
                        <div class="detail-box">
                            <div class="detail-label">Category Type</div>
                            <div class="detail-value">${esc(category.category_type)}</div>
                        </div>

                        <div class="detail-box">
                            <div class="detail-label">Metal Type</div>
                            <div class="detail-value">${esc(category.metal_type)}</div>
                        </div>

                        <div class="detail-box">
                            <div class="detail-label">Purity Standard</div>
                            <div class="detail-value">${esc(category.purity_standard || '-')}</div>
                        </div>

                        <div class="detail-box">
                            <div class="detail-label">Purity Range</div>
                            <div class="detail-value">
                                ${number(category.min_purity_percent)}% -
                                ${number(category.max_purity_percent)}%
                            </div>
                        </div>

                        <div class="detail-box">
                            <div class="detail-label">Interest</div>
                            <div class="detail-value">
                                ${number(category.default_interest_percent, 3)}%
                            </div>
                        </div>

                        <div class="detail-box">
                            <div class="detail-label">Maximum Loan</div>
                            <div class="detail-value">
                                ${number(category.max_loan_percent)}%
                            </div>
                        </div>

                        <div class="detail-box">
                            <div class="detail-label">Storage Fee</div>
                            <div class="detail-value">
                                ${number(category.storage_fee_percent)}%
                            </div>
                        </div>

                        <div class="detail-box">
                            <div class="detail-label">Valuation</div>
                            <div class="detail-value">${esc(category.valuation_method)}</div>
                        </div>

                        <div class="detail-box">
                            <div class="detail-label">Certificate</div>
                            <div class="detail-value">${yesNoBadge(category.requires_certificate)}</div>
                        </div>

                        <div class="detail-box">
                            <div class="detail-label">Manual Valuation</div>
                            <div class="detail-value">${yesNoBadge(category.requires_valuation)}</div>
                        </div>
                    </div>

                    ${category.description
                        ? `<div class="small text-muted mt-3">${esc(category.description)}</div>`
                        : ''
                    }

                    <div class="d-flex gap-2 mt-3">
                        <button type="button"
                                class="btn-soft flex-grow-1 edit-category"
                                data-category='${esc(JSON.stringify(category))}'>
                            Edit
                        </button>

                        <button type="button"
                                class="btn-soft toggle-category"
                                data-id="${category.id}"
                                data-active="${category.is_active}">
                            ${Number(category.is_active) === 1 ? 'Deactivate' : 'Activate'}
                        </button>

                        <button type="button"
                                class="btn-soft delete-category"
                                data-id="${category.id}"
                                data-name="${esc(category.category_name)}">
                            Delete
                        </button>
                    </div>
                </div>
            </article>
        `).join('');
    }

    async function loadCategories() {
        loadingBox.classList.add('show');
        categoryGrid.style.display = 'none';
        emptyBox.classList.remove('show');

        try {
            const data = await request({
                action: 'list',
                search: document.getElementById('searchInput').value.trim(),
                category_type: document.getElementById('typeFilter').value,
                metal_type: document.getElementById('metalFilter').value,
                is_active: document.getElementById('statusFilter').value
            });

            renderCategories(data.categories);

            document.getElementById('totalCategories').textContent =
                data.stats.total_categories;

            document.getElementById('activeCategories').textContent =
                data.stats.active_categories;

            document.getElementById('certificateCategories').textContent =
                data.stats.certificate_categories;

            document.getElementById('valuationCategories').textContent =
                data.stats.valuation_categories;

            const hasRows = data.categories.length > 0;

            categoryGrid.style.display = hasRows ? 'grid' : 'none';
            emptyBox.classList.toggle('show', !hasRows);
        } catch (error) {
            emptyBox.classList.add('show');
            emptyBox.innerHTML =
                '<div class="text-danger fw-bold">Unable to load categories</div>' +
                '<div class="small mt-2">' + esc(error.message) + '</div>';

            toast('error', error.message);
        } finally {
            loadingBox.classList.remove('show');
        }
    }

    function resetForm() {
        document.getElementById('categoryForm').reset();
        document.getElementById('categoryId').value = '0';
        document.getElementById('categoryCode').value = '';
        document.getElementById('modalTitle').textContent = 'Add Pawn Category';
        document.getElementById('categoryType').value = 'Ornament';
        document.getElementById('metalType').value = 'Gold';
        document.getElementById('minPurity').value = '0';
        document.getElementById('maxPurity').value = '100';
        document.getElementById('interestPercent').value = '0';
        document.getElementById('maxLoanPercent').value = '0';
        document.getElementById('storageFeePercent').value = '0';
        document.getElementById('valuationMethod').value = 'Weight';
        document.getElementById('isActive').value = '1';
    }

    async function openNewCategory() {
        resetForm();

        try {
            const data = await request({ action: 'next_code' });
            document.getElementById('categoryCode').value = data.category_code;

            showCategoryModal();
        } catch (error) {
            toast('error', error.message);
        }
    }

    function openEditCategory(category) {
        document.getElementById('modalTitle').textContent = 'Edit Pawn Category';
        document.getElementById('categoryId').value = category.id;
        document.getElementById('categoryCode').value = category.category_code || '';
        document.getElementById('categoryName').value = category.category_name || '';
        document.getElementById('categoryType').value = category.category_type || 'Ornament';
        document.getElementById('metalType').value = category.metal_type || 'Gold';
        document.getElementById('purityStandard').value = category.purity_standard || '';
        document.getElementById('minPurity').value = category.min_purity_percent || 0;
        document.getElementById('maxPurity').value = category.max_purity_percent || 100;
        document.getElementById('interestPercent').value = category.default_interest_percent || 0;
        document.getElementById('maxLoanPercent').value = category.max_loan_percent || 0;
        document.getElementById('storageFeePercent').value = category.storage_fee_percent || 0;
        document.getElementById('valuationMethod').value = category.valuation_method || 'Weight';
        document.getElementById('requiresCertificate').checked =
            Number(category.requires_certificate) === 1;
        document.getElementById('requiresValuation').checked =
            Number(category.requires_valuation) === 1;
        document.getElementById('isActive').value =
            Number(category.is_active) === 1 ? '1' : '0';
        document.getElementById('description').value = category.description || '';

        showCategoryModal();
    }

    document.getElementById('newCategoryBtn').addEventListener(
        'click',
        openNewCategory
    );


    categoryModalElement.addEventListener('click', event => {
        const closeButton = event.target.closest('[data-bs-dismiss="modal"]');

        if (closeButton && !(window.bootstrap && bootstrap.Modal)) {
            event.preventDefault();
            hideCategoryModal();
        }
    });


    document.getElementById('filterForm').addEventListener('submit', event => {
        event.preventDefault();
        loadCategories();
    });

    document.getElementById('resetFilterBtn').addEventListener('click', () => {
        document.getElementById('searchInput').value = '';
        document.getElementById('typeFilter').value = '';
        document.getElementById('metalFilter').value = '';
        document.getElementById('statusFilter').value = '';
        loadCategories();
    });

    document.getElementById('categoryForm').addEventListener(
        'submit',
        async event => {
            event.preventDefault();

            const categoryNameValue =
                document.getElementById('categoryName').value.trim();

            if (!categoryNameValue) {
                toast('error', 'Category name is required.');
                document.getElementById('categoryName').focus();
                return;
            }

            const minPurityValue = Number(
                document.getElementById('minPurity').value || 0
            );
            const maxPurityValue = Number(
                document.getElementById('maxPurity').value || 0
            );

            if (minPurityValue > maxPurityValue) {
                toast(
                    'error',
                    'Minimum purity cannot be greater than maximum purity.'
                );
                return;
            }

            const saveButton = document.getElementById('saveBtn');
            const originalText = saveButton.innerHTML;

            saveButton.disabled = true;
            saveButton.innerHTML =
                '<i class="fa-solid fa-spinner fa-spin me-2"></i>Saving...';

            try {
                const response = await fetch(apiUrl, {
                    method: 'POST',
                    body: new FormData(event.target),
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
                    throw new Error(
                        'Pawn Categories API did not return JSON. HTTP ' +
                        response.status +
                        ': ' +
                        raw.replace(/<[^>]*>/g, ' ')
                           .replace(/\s+/g, ' ')
                           .trim()
                           .substring(0, 300)
                    );
                }

                if (!response.ok || !result.success) {
                    throw new Error(result.message || 'Unable to save category.');
                }

                toast('success', result.message);

                hideCategoryModal();

                loadCategories();
            } catch (error) {
                toast('error', error.message);
            } finally {
                saveButton.disabled = false;
                saveButton.innerHTML = originalText;
            }
        }
    );

    document.addEventListener('click', async event => {
        const editButton = event.target.closest('.edit-category');

        if (editButton) {
            try {
                openEditCategory(JSON.parse(editButton.dataset.category));
            } catch (error) {
                toast('error', 'Unable to open category for editing.');
            }

            return;
        }

        const toggleButton = event.target.closest('.toggle-category');

        if (toggleButton) {
            try {
                const data = await request({
                    action: 'toggle',
                    category_id: toggleButton.dataset.id,
                    is_active: Number(toggleButton.dataset.active) === 1 ? 0 : 1
                });

                toast('success', data.message);
                loadCategories();
            } catch (error) {
                toast('error', error.message);
            }

            return;
        }

        const deleteButton = event.target.closest('.delete-category');

        if (deleteButton) {
            const confirmed = confirm(
                'Delete category "' + deleteButton.dataset.name + '"?'
            );

            if (!confirmed) {
                return;
            }

            try {
                const data = await request({
                    action: 'delete',
                    category_id: deleteButton.dataset.id
                });

                toast('success', data.message);
                loadCategories();
            } catch (error) {
                toast('error', error.message);
            }
        }
    });

    loadCategories();
})();
</script>
</body>
</html>
