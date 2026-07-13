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
foreach ($configCandidates as $configFile) {
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

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('supplierPermission')) {
    function supplierPermission(mysqli $conn, string $action): bool
    {
        if (($_SESSION['user_type'] ?? '') === 'Platform Admin') {
            return true;
        }

        $fieldMap = [
            'open' => 'can_open',
            'view' => 'can_view',
            'value' => 'can_view_value',
            'create' => 'can_create',
            'update' => 'can_update',
            'approve' => 'can_approve',
            'delete' => 'can_delete',
        ];
        $field = $fieldMap[$action] ?? '';
        if ($field === '') {
            return false;
        }

        $permissionKeys = [
            'perm.suppliers',
            'perm.purchases.suppliers',
            'perm.purchases',
        ];
        $sessionPermissions = $_SESSION['permissions'] ?? [];
        foreach ($permissionKeys as $key) {
            if (isset($sessionPermissions[$key][$field])) {
                return (int)$sessionPermissions[$key][$field] === 1;
            }
        }

        $businessId = (int)($_SESSION['business_id'] ?? 0);
        $roleId = (int)($_SESSION['role_id'] ?? 0);
        if ($businessId <= 0 || $roleId <= 0) {
            return false;
        }

        $placeholders = implode(',', array_fill(0, count($permissionKeys), '?'));
        $sql = "SELECT rp.`{$field}`
                FROM role_permissions rp
                INNER JOIN permissions p ON p.id = rp.permission_id
                WHERE rp.business_id = ?
                  AND rp.role_id = ?
                  AND p.is_active = 1
                  AND p.permission_code IN ({$placeholders})
                ORDER BY FIELD(p.permission_code, 'perm.suppliers', 'perm.purchases.suppliers', 'perm.purchases')
                LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return false;
        }
        $types = 'ii' . str_repeat('s', count($permissionKeys));
        $params = array_merge([$businessId, $roleId], $permissionKeys);
        $refs = [$types];
        foreach ($params as $index => $value) {
            $refs[] = &$params[$index];
        }
        call_user_func_array([$stmt, 'bind_param'], $refs);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($row[$field] ?? 0) === 1;
    }
}

if (!supplierPermission($conn, 'open')) {
    http_response_code(403);
    die('Access denied. You do not have permission to open suppliers.');
}

$canView = supplierPermission($conn, 'view') || supplierPermission($conn, 'open');
$canViewValue = supplierPermission($conn, 'value');
$canCreate = supplierPermission($conn, 'create');
$canUpdate = supplierPermission($conn, 'update');
$canDelete = supplierPermission($conn, 'delete');
$businessId = (int)($_SESSION['business_id'] ?? 0);
if ($businessId <= 0) {
    http_response_code(403);
    die('A valid business must be selected before managing suppliers.');
}

if (empty($_SESSION['suppliers_csrf'])) {
    $_SESSION['suppliers_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['suppliers_csrf'];

$suppliers = [];
$sql = "SELECT id, supplier_code, supplier_name, contact_person, mobile,
               email, gstin, address, opening_balance, current_balance, is_active
        FROM suppliers
        WHERE business_id = ?
        ORDER BY supplier_name ASC, id DESC";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    die('Unable to load suppliers: ' . h($conn->error));
}
$stmt->bind_param('i', $businessId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $suppliers[] = $row;
}
$stmt->close();

$stats = ['total' => count($suppliers), 'active' => 0, 'inactive' => 0, 'opening_balance' => 0.0];
foreach ($suppliers as $supplier) {
    if ((int)$supplier['is_active'] === 1) {
        $stats['active']++;
    } else {
        $stats['inactive']++;
    }
    $stats['opening_balance'] += (float)$supplier['opening_balance'];
}

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
    'sidebar_width_px' => 230,
];
$themeStmt = $conn->prepare('SELECT * FROM business_theme_settings WHERE business_id = ? LIMIT 1');
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

$pageTitle = 'Suppliers';
$businessName = (string)($_SESSION['business_name'] ?? 'Jewellery ERP');
$currencySymbol = (string)($_SESSION['currency_symbol'] ?? '₹');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo e($businessName); ?> - Suppliers</title>
    <?php include('includes/links.php'); ?>
    <style>
        :root{
            --primary:<?php echo e($theme['primary_color']); ?>;
            --primary-dark:<?php echo e($theme['primary_dark_color']); ?>;
            --primary-soft:<?php echo e($theme['primary_soft_color']); ?>;
            --sidebar-gradient-1:<?php echo e($theme['sidebar_gradient_1']); ?>;
            --sidebar-gradient-2:<?php echo e($theme['sidebar_gradient_2']); ?>;
            --sidebar-gradient-3:<?php echo e($theme['sidebar_gradient_3']); ?>;
            --page-bg:<?php echo e($theme['page_background']); ?>;
            --card-bg:<?php echo e($theme['card_background']); ?>;
            --text-color:<?php echo e($theme['text_color']); ?>;
            --muted-color:<?php echo e($theme['muted_text_color']); ?>;
            --border-color:<?php echo e($theme['border_color']); ?>;
            --sidebar-width:<?php echo (int)$theme['sidebar_width_px']; ?>px;
            --radius:<?php echo (int)$theme['border_radius_px']; ?>px;
        }
        body{background:var(--page-bg);color:var(--text-color);font-family:<?php echo json_encode((string)$theme['font_family']); ?>,sans-serif;}
        .sidebar{background:linear-gradient(180deg,var(--sidebar-gradient-1),var(--sidebar-gradient-2),var(--sidebar-gradient-3))!important;}
        .stat-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin-bottom:10px;}
        .stat-card{background:var(--card-bg);border:1px solid var(--border-color);border-radius:var(--radius);padding:12px 14px;min-height:82px;display:flex;align-items:center;gap:12px;}
        .stat-icon{width:42px;height:42px;flex:0 0 42px;display:flex;align-items:center;justify-content:center;border-radius:calc(var(--radius)*.75);background:var(--primary-soft);color:var(--primary-dark);font-size:16px;}
        .stat-label{font-size:10px;color:var(--muted-color);}.stat-value{font-size:22px;line-height:1.1;font-weight:800;margin-top:4px;}
        .supplier-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;background:var(--card-bg);border:1px solid var(--border-color);border-radius:var(--radius);padding:10px 12px;margin-bottom:10px;}
        .supplier-toolbar-left{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}.supplier-search{position:relative;min-width:300px;}.supplier-search i{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--muted-color);font-size:11px;}.supplier-search input{padding-left:32px;}
        .form-control,.form-select{font-size:11px;min-height:36px;border-radius:9px;border-color:var(--border-color);background:var(--card-bg);color:var(--text-color);}
        .btn-theme{background:linear-gradient(135deg,var(--primary),var(--primary-dark));border:0;color:#fff;border-radius:calc(var(--radius)*.65);font-size:11px;font-weight:700;padding:9px 14px;}.btn-theme:hover{color:#fff;filter:brightness(1.02);}
        .supplier-card{background:var(--card-bg);border:1px solid var(--border-color);border-radius:var(--radius);overflow:hidden;}.supplier-table{margin:0;font-size:11px;}.supplier-table th{font-size:10px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted-color);background:color-mix(in srgb,var(--muted-color) 6%,transparent);white-space:nowrap;padding:10px 12px;border-color:var(--border-color);}.supplier-table td{padding:10px 12px;vertical-align:middle;color:var(--text-color);background:var(--card-bg)!important;border-color:var(--border-color);}
        .supplier-name{font-size:11px;font-weight:800;}.supplier-sub{font-size:9px;color:var(--muted-color);margin-top:2px;}.code-badge,.status-badge,.balance-badge{display:inline-flex;align-items:center;border-radius:999px;padding:4px 8px;font-size:9px;font-weight:700;}.code-badge{background:var(--primary-soft);color:var(--primary-dark);}.status-active{background:#eaf8f0;color:#168449;}.status-inactive{background:#fdecec;color:#bd2d2d;}.balance-badge{background:#eff6ff;color:#1d4ed8;}
        .action-btn{width:30px;height:30px;border:1px solid var(--border-color);border-radius:8px;background:var(--card-bg);display:inline-flex;align-items:center;justify-content:center;font-size:10px;color:var(--text-color);}.action-btn:hover{background:var(--primary-soft);color:var(--primary-dark);}.action-btn.danger:hover{background:#fdecec;color:#bd2d2d;}.action-btn:disabled{opacity:.45;cursor:not-allowed;}
        .modal-content{background:var(--card-bg);color:var(--text-color);border:0;border-radius:var(--radius);overflow:hidden;}.modal-header,.modal-footer{border-color:var(--border-color);}.modal-title{font-family:<?php echo json_encode((string)$theme['heading_font_family']); ?>,serif;font-size:15px;font-weight:800;}.field-label{display:block;font-size:10px;font-weight:700;margin-bottom:5px;}.section-title{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--primary-dark);padding-bottom:7px;margin-bottom:12px;border-bottom:1px solid var(--border-color);}
        .theme-toast{position:fixed;right:18px;top:78px;z-index:20000;display:flex;align-items:center;gap:9px;min-width:260px;max-width:420px;padding:11px 14px;border-radius:10px;color:#fff;font-size:11px;font-weight:600;box-shadow:0 14px 35px rgba(0,0,0,.22);opacity:0;transform:translateY(-10px);transition:.22s;}.theme-toast.show{opacity:1;transform:translateY(0);}.theme-toast-success{background:#168449;}.theme-toast-error{background:#c0392b;}.empty-state{padding:50px 20px;text-align:center;color:var(--muted-color);}.empty-state i{font-size:34px;margin-bottom:10px;}
        body.dark-mode,body[data-theme="dark"],html.dark-mode body,html[data-theme="dark"] body{--page-bg:#0f151b;--card-bg:#182129;--text-color:#f3f6f8;--muted-color:#9aa7b3;--border-color:#2c3944;}
        @media(max-width:991.98px){.stat-grid{grid-template-columns:repeat(2,minmax(0,1fr));}.supplier-toolbar{align-items:stretch;flex-direction:column;}.supplier-toolbar-left{display:grid;grid-template-columns:minmax(0,1fr) 150px;}.supplier-search{min-width:0;width:100%;}.supplier-card{background:transparent;border:0;overflow:visible;}.table-responsive{overflow:visible;}.supplier-table{display:block;background:transparent;}.supplier-table thead{display:none;}.supplier-table tbody{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;}.supplier-table tbody tr{display:grid;grid-template-columns:1fr 1fr;background:var(--card-bg);border:1px solid var(--border-color);border-radius:var(--radius);padding:14px;}.supplier-table tbody td{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;min-width:0;padding:9px 0;border:0;border-bottom:1px dashed var(--border-color);text-align:right!important;}.supplier-table tbody td::before{content:attr(data-label);font-size:9px;font-weight:700;text-transform:uppercase;color:var(--muted-color);text-align:left;}.supplier-table tbody td.supplier-column{grid-column:1/-1;display:block;padding:0 0 12px;text-align:left!important;}.supplier-table tbody td.supplier-column::before{display:none;}.supplier-table tbody td.actions-column{grid-column:1/-1;border-bottom:0;padding:12px 0 0;align-items:center;}}
        @media(max-width:767.98px){.content-wrap{padding-left:10px;padding-right:10px;}.stat-grid{grid-template-columns:1fr 1fr;}.supplier-toolbar-left{grid-template-columns:1fr;}.supplier-table tbody{grid-template-columns:1fr;}.supplier-table tbody tr{grid-template-columns:1fr;}.supplier-table tbody td{grid-column:1/-1;}.theme-toast{left:12px;right:12px;top:70px;min-width:0;max-width:none;}.modal-dialog{margin:8px;}}
    </style>
</head>
<body>
<?php include('includes/sidebar.php'); ?>
<main class="app-main">
    <?php include('includes/nav.php'); ?>
    <div class="content-wrap">
        <?php if (!$canView): ?>
            <div class="supplier-card"><div class="empty-state"><i class="fa-solid fa-lock"></i><div>You do not have permission to view suppliers.</div></div></div>
        <?php else: ?>
            <div class="stat-grid">
                <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-truck-field"></i></div><div><div class="stat-label">Total Suppliers</div><div class="stat-value"><?php echo $stats['total']; ?></div></div></div>
                <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-circle-check"></i></div><div><div class="stat-label">Active Suppliers</div><div class="stat-value"><?php echo $stats['active']; ?></div></div></div>
                <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-circle-xmark"></i></div><div><div class="stat-label">Inactive Suppliers</div><div class="stat-value"><?php echo $stats['inactive']; ?></div></div></div>
                <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-wallet"></i></div><div><div class="stat-label">Opening Balance</div><div class="stat-value"><?php echo $canViewValue ? e($currencySymbol) . number_format($stats['opening_balance'], 2) : '••••'; ?></div></div></div>
            </div>

            <div class="supplier-toolbar">
                <div class="supplier-toolbar-left">
                    <div class="supplier-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" class="form-control" id="supplierSearch" placeholder="Search supplier, code, mobile, GSTIN, city..."></div>
                    <select class="form-select" id="statusFilter" style="width:150px"><option value="">All status</option><option value="active">Active</option><option value="inactive">Inactive</option></select>
                </div>
                <?php if ($canCreate): ?><button type="button" class="btn btn-theme btn-sm" id="addSupplierButton"><i class="fa-solid fa-plus me-2"></i>Add Supplier</button><?php endif; ?>
            </div>

            <div class="supplier-card">
                <div class="table-responsive">
                    <table class="table supplier-table align-middle" id="suppliersTable">
                        <thead><tr><th>Supplier</th><th>Contact</th><th>GSTIN</th><th>Address</th><th>Opening Balance</th><th>Current Balance</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                        <tbody>
                        <?php foreach ($suppliers as $supplier):
                            $searchText = strtolower(implode(' ', [
                                $supplier['supplier_name'], $supplier['supplier_code'], $supplier['contact_person'],
                                $supplier['mobile'], $supplier['email'], $supplier['gstin'], $supplier['address']
                            ]));
                        ?>
                            <tr data-search="<?php echo e($searchText); ?>" data-status="<?php echo (int)$supplier['is_active'] === 1 ? 'active' : 'inactive'; ?>">
                                <td class="supplier-column" data-label="Supplier"><div class="supplier-name"><?php echo e($supplier['supplier_name']); ?></div><div class="supplier-sub"><?php echo e($supplier['supplier_code'] ?: 'No supplier code'); ?><?php echo !empty($supplier['contact_person']) ? ' · ' . e($supplier['contact_person']) : ''; ?></div></td>
                                <td data-label="Contact"><div><?php echo e($supplier['mobile'] ?: '—'); ?></div><?php if (!empty($supplier['email'])): ?><div class="supplier-sub"><?php echo e($supplier['email']); ?></div><?php endif; ?></td>
                                <td data-label="GSTIN"><span class="code-badge"><?php echo e($supplier['gstin'] ?: '—'); ?></span></td>
                                <td data-label="Address"><?php echo e($supplier['address'] ?: '—'); ?></td>
                                <td data-label="Opening Balance"><?php if ($canViewValue): ?><span class="balance-badge"><?php echo e($currencySymbol) . number_format((float)$supplier['opening_balance'], 2); ?></span><?php else: ?><span class="supplier-sub">Restricted</span><?php endif; ?></td>
                                <td data-label="Current Balance"><?php if ($canViewValue): ?><span class="balance-badge"><?php echo e($currencySymbol) . number_format((float)$supplier['current_balance'], 2); ?></span><?php else: ?><span class="supplier-sub">Restricted</span><?php endif; ?></td>
                                <td data-label="Status"><span class="status-badge <?php echo (int)$supplier['is_active'] === 1 ? 'status-active' : 'status-inactive'; ?>"><?php echo (int)$supplier['is_active'] === 1 ? 'Active' : 'Inactive'; ?></span></td>
                                <td class="text-end actions-column" data-label="Actions"><div class="d-inline-flex gap-1">
                                    <?php if ($canUpdate): ?>
                                        <button class="action-btn edit-supplier" type="button" title="Edit" data-id="<?php echo (int)$supplier['id']; ?>"><i class="fa-solid fa-pen"></i></button>
                                        <button class="action-btn toggle-supplier" type="button" title="<?php echo (int)$supplier['is_active'] === 1 ? 'Deactivate' : 'Activate'; ?>" data-id="<?php echo (int)$supplier['id']; ?>" data-active="<?php echo (int)$supplier['is_active']; ?>"><i class="fa-solid <?php echo (int)$supplier['is_active'] === 1 ? 'fa-ban' : 'fa-circle-check'; ?>"></i></button>
                                    <?php endif; ?>
                                    <?php if ($canDelete): ?><button class="action-btn danger delete-supplier" type="button" title="Delete" data-id="<?php echo (int)$supplier['id']; ?>" data-name="<?php echo e($supplier['supplier_name']); ?>"><i class="fa-solid fa-trash"></i></button><?php endif; ?>
                                </div></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (!$suppliers): ?><div class="empty-state"><i class="fa-regular fa-folder-open"></i><div>No suppliers found.</div></div><?php endif; ?>
            </div>
        <?php endif; ?>
        <?php include('includes/footer.php'); ?>
    </div>
</main>

<div class="modal fade" id="supplierModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <form class="modal-content" id="supplierForm">
            <div class="modal-header"><h5 class="modal-title" id="supplierModalTitle">Add Supplier</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="supplier_id" id="supplier_id" value="0">
                <div class="section-title">Basic Information</div>
                <div class="row g-3 mb-4">
                    <div class="col-md-4"><label class="field-label">Supplier name <span class="text-danger">*</span></label><input class="form-control" type="text" name="supplier_name" id="supplier_name" maxlength="150" required></div>
                    <div class="col-md-4"><label class="field-label">Supplier code</label><input class="form-control" type="text" name="supplier_code" id="supplier_code" maxlength="50" placeholder="Example: SUP001"></div>
                    <div class="col-md-4"><label class="field-label">Contact person</label><input class="form-control" type="text" name="contact_person" id="contact_person" maxlength="150"></div>
                    <div class="col-md-4"><label class="field-label">Mobile</label><input class="form-control" type="text" name="mobile" id="mobile" maxlength="20"></div>
                    <div class="col-md-4"><label class="field-label">Email</label><input class="form-control" type="email" name="email" id="email" maxlength="190"></div>
                    <div class="col-md-4"><label class="field-label">GSTIN</label><input class="form-control text-uppercase" type="text" name="gstin" id="gstin" maxlength="15"></div>
                    <div class="col-md-4"><label class="field-label">Opening balance</label><input class="form-control" type="number" step="0.01" min="0" name="opening_balance" id="opening_balance" value="0.00"></div>
                </div>
                <div class="section-title">Address and Status</div>
                <div class="row g-3">
                    <div class="col-md-9"><label class="field-label">Address</label><textarea class="form-control" name="address" id="address" rows="3"></textarea></div>
                    <div class="col-md-3"><label class="field-label">Status</label><select class="form-select" name="is_active" id="is_active"><option value="1">Active</option><option value="0">Inactive</option></select></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-theme btn-sm" id="saveSupplierButton"><i class="fa-solid fa-floppy-disk me-2"></i>Save Supplier</button></div>
        </form>
    </div>
</div>

<div class="modal fade" id="confirmActionModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title" id="confirmActionTitle">Confirm action</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body" id="confirmActionMessage"></div><div class="modal-footer"><button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-danger btn-sm" id="confirmActionButton">Confirm</button></div></div></div></div>
<div class="theme-toast" id="themeToast"><i class="fa-solid fa-circle-info"></i><span id="themeToastMessage"></span></div>

<?php include('includes/script.php'); ?>
<script src="assets/js/script.js"></script>
<script>
(() => {
    const apiUrl = 'api/suppliers-save.php';
    const csrfToken = <?php echo json_encode($csrfToken); ?>;
    const supplierModalElement = document.getElementById('supplierModal');
    const supplierModal = supplierModalElement ? new bootstrap.Modal(supplierModalElement) : null;
    const confirmModalElement = document.getElementById('confirmActionModal');
    const confirmModal = confirmModalElement ? new bootstrap.Modal(confirmModalElement) : null;
    const form = document.getElementById('supplierForm');
    const saveButton = document.getElementById('saveSupplierButton');
    let pendingAction = null;

    function showToast(message, success = true) {
        const toast = document.getElementById('themeToast');
        document.getElementById('themeToastMessage').textContent = message;
        toast.className = 'theme-toast ' + (success ? 'theme-toast-success' : 'theme-toast-error');
        requestAnimationFrame(() => toast.classList.add('show'));
        setTimeout(() => toast.classList.remove('show'), 3200);
    }

    async function postForm(data) {
        const response = await fetch(apiUrl, {method: 'POST', body: data, credentials: 'same-origin'});
        let payload;
        try { payload = await response.json(); } catch (error) { throw new Error('Invalid server response.'); }
        if (!response.ok || !payload.success) throw new Error(payload.message || 'Request failed.');
        return payload;
    }

    function resetForm() {
        form.reset();
        document.getElementById('supplier_id').value = '0';
        document.getElementById('opening_balance').value = '0.00';
        document.getElementById('is_active').value = '1';
        document.getElementById('supplierModalTitle').textContent = 'Add Supplier';
    }

    document.getElementById('addSupplierButton')?.addEventListener('click', () => { resetForm(); supplierModal.show(); });

    document.querySelectorAll('.edit-supplier').forEach(button => button.addEventListener('click', async () => {
        const data = new FormData(); data.append('action', 'get'); data.append('supplier_id', button.dataset.id); data.append('csrf_token', csrfToken);
        try {
            const payload = await postForm(data); resetForm();
            Object.entries(payload.supplier || {}).forEach(([key, value]) => { const input = document.getElementById(key); if (input) input.value = value ?? ''; });
            document.getElementById('supplier_id').value = payload.supplier.id;
            document.getElementById('supplierModalTitle').textContent = 'Edit Supplier';
            supplierModal.show();
        } catch (error) { showToast(error.message, false); }
    }));

    form?.addEventListener('submit', async event => {
        event.preventDefault(); saveButton.disabled = true;
        try { const payload = await postForm(new FormData(form)); showToast(payload.message); setTimeout(() => location.reload(), 650); }
        catch (error) { showToast(error.message, false); }
        finally { saveButton.disabled = false; }
    });

    function openConfirm(title, message, action) {
        document.getElementById('confirmActionTitle').textContent = title;
        document.getElementById('confirmActionMessage').textContent = message;
        pendingAction = action; confirmModal.show();
    }

    document.querySelectorAll('.toggle-supplier').forEach(button => button.addEventListener('click', () => {
        const nextStatus = button.dataset.active === '1' ? 0 : 1;
        openConfirm(nextStatus ? 'Activate supplier' : 'Deactivate supplier', `Are you sure you want to ${nextStatus ? 'activate' : 'deactivate'} this supplier?`, {action: 'toggle', supplier_id: button.dataset.id, is_active: nextStatus});
    }));
    document.querySelectorAll('.delete-supplier').forEach(button => button.addEventListener('click', () => openConfirm('Delete supplier', `Delete “${button.dataset.name}”? This action cannot be undone.`, {action: 'delete', supplier_id: button.dataset.id})));

    document.getElementById('confirmActionButton')?.addEventListener('click', async function () {
        if (!pendingAction) return;
        const data = new FormData(); Object.entries(pendingAction).forEach(([key, value]) => data.append(key, value)); data.append('csrf_token', csrfToken); this.disabled = true;
        try { const payload = await postForm(data); confirmModal.hide(); showToast(payload.message); setTimeout(() => location.reload(), 650); }
        catch (error) { showToast(error.message, false); }
        finally { this.disabled = false; pendingAction = null; }
    });

    function filterRows() {
        const query = (document.getElementById('supplierSearch')?.value || '').trim().toLowerCase();
        const status = document.getElementById('statusFilter')?.value || '';
        document.querySelectorAll('#suppliersTable tbody tr').forEach(row => {
            const matchesSearch = !query || (row.dataset.search || '').includes(query);
            const matchesStatus = !status || row.dataset.status === status;
            row.style.display = matchesSearch && matchesStatus ? '' : 'none';
        });
    }
    document.getElementById('supplierSearch')?.addEventListener('input', filterRows);
    document.getElementById('statusFilter')?.addEventListener('change', filterRows);
})();
</script>
</body>
</html>
