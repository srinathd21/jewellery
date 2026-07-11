<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string) ($_SESSION['timezone'] ?? 'Asia/Kolkata'));

$configCandidates = [
    __DIR__ . '/config/config.php',
    __DIR__ . '/config.php',
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
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

function invoicePermission(mysqli $conn, string $action): bool
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

    foreach (['perm.settings.invoice', 'perm.settings'] as $key) {
        if (isset($_SESSION['permissions'][$key][$field])) {
            return (int) $_SESSION['permissions'][$key][$field] === 1;
        }
    }

    $businessId = (int) ($_SESSION['business_id'] ?? 0);
    $roleId = (int) ($_SESSION['role_id'] ?? 0);
    if ($businessId <= 0 || $roleId <= 0) {
        return false;
    }

    $sql = "SELECT rp.`{$field}`
            FROM role_permissions rp
            INNER JOIN permissions p ON p.id = rp.permission_id
            WHERE rp.business_id = ?
              AND rp.role_id = ?
              AND p.is_active = 1
              AND p.permission_code IN ('perm.settings.invoice','perm.settings')
            ORDER BY FIELD(p.permission_code,'perm.settings.invoice','perm.settings')
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ii', $businessId, $roleId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row[$field] ?? 0) === 1;
}

if (!invoicePermission($conn, 'open')) {
    http_response_code(403);
    die('Access denied. You do not have permission to open invoice settings.');
}

$canView = invoicePermission($conn, 'view') || invoicePermission($conn, 'open');
$canCreate = invoicePermission($conn, 'create');
$canUpdate = invoicePermission($conn, 'update');
$canDelete = invoicePermission($conn, 'delete');
$businessId = (int) ($_SESSION['business_id'] ?? 0);
$branchId = (int) ($_SESSION['branch_id'] ?? 0);

if ($businessId <= 0) {
    http_response_code(403);
    die('A valid business must be selected.');
}

if (empty($_SESSION['invoice_settings_csrf'])) {
    $_SESSION['invoice_settings_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['invoice_settings_csrf'];

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
$stmt = $conn->prepare('SELECT * FROM business_theme_settings WHERE business_id = ? LIMIT 1');
if ($stmt) {
    $stmt->bind_param('i', $businessId);
    $stmt->execute();
    if ($row = $stmt->get_result()->fetch_assoc()) {
        foreach ($theme as $key => $default) {
            if (isset($row[$key]) && $row[$key] !== '') {
                $theme[$key] = $row[$key];
            }
        }
    }
    $stmt->close();
}

$branches = [];
$stmt = $conn->prepare('SELECT id, branch_code, branch_name, branch_type, is_default FROM branches WHERE business_id = ? AND is_active = 1 ORDER BY is_default DESC, branch_name ASC');
$stmt->bind_param('i', $businessId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $branches[] = $row;
}
$stmt->close();

$settings = [];
$sql = "SELECT i.*, b.branch_name, b.branch_code
        FROM invoice_settings i
        LEFT JOIN branches b ON b.id = i.branch_id AND b.business_id = i.business_id
        WHERE i.business_id = ?
        ORDER BY i.is_default DESC, i.is_active DESC, i.document_type ASC, i.setting_name ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $businessId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $settings[] = $row;
}
$stmt->close();

$stats = ['total' => count($settings), 'active' => 0, 'default' => 0, 'thermal' => 0];
foreach ($settings as $setting) {
    if ((int) $setting['is_active'] === 1) $stats['active']++;
    if ((int) $setting['is_default'] === 1) $stats['default']++;
    if (in_array($setting['paper_size'], ['80mm', '58mm'], true)) $stats['thermal']++;
}

$pageTitle = 'Invoice Settings';
$businessName = (string) ($_SESSION['business_name'] ?? 'Jewellery ERP');
$documentTypes = ['Invoice','Estimate','Sales Return','Purchase','Purchase Return','Receipt','Pawn Receipt','Chit Receipt'];
$paperSizes = ['A4','A5','80mm','58mm','Custom'];
$orientations = ['Portrait','Landscape'];
$resetFrequencies = ['Never','Financial Year','Calendar Year','Monthly','Daily'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo e($businessName); ?> - Invoice Settings</title>
    <?php include('includes/links.php'); ?>
    <style>
        :root {
            --primary: <?php echo e($theme['primary_color']); ?>;
            --primary-dark: <?php echo e($theme['primary_dark_color']); ?>;
            --primary-soft: <?php echo e($theme['primary_soft_color']); ?>;
            --sidebar-gradient-1: <?php echo e($theme['sidebar_gradient_1']); ?>;
            --sidebar-gradient-2: <?php echo e($theme['sidebar_gradient_2']); ?>;
            --sidebar-gradient-3: <?php echo e($theme['sidebar_gradient_3']); ?>;
            --page-bg: <?php echo e($theme['page_background']); ?>;
            --card-bg: <?php echo e($theme['card_background']); ?>;
            --text-color: <?php echo e($theme['text_color']); ?>;
            --muted-color: <?php echo e($theme['muted_text_color']); ?>;
            --border-color: <?php echo e($theme['border_color']); ?>;
            --sidebar-width: <?php echo (int) $theme['sidebar_width_px']; ?>px;
            --radius: <?php echo (int) $theme['border_radius_px']; ?>px;
        }
        body { background: var(--page-bg); color: var(--text-color); font-family: <?php echo json_encode((string) $theme['font_family']); ?>, sans-serif; }
        .content-wrap .card, .content-wrap .card-panel, .content-wrap .stat-card, .content-wrap .toolbar-card, .content-wrap .settings-card, .content-wrap .table-responsive, .content-wrap .preview-box, .modal-content { border-radius:var(--radius) !important; }
        .content-wrap .form-control, .content-wrap .form-select, .content-wrap .btn, .content-wrap .action-btn, .content-wrap .stat-icon { border-radius:calc(var(--radius) * .7) !important; }
        .settings-table { border-collapse:separate; border-spacing:0; overflow:hidden; border-radius:var(--radius) !important; }
        .sidebar { background: linear-gradient(180deg,var(--sidebar-gradient-1),var(--sidebar-gradient-2),var(--sidebar-gradient-3)) !important; }
        .card-panel,.stat-card,.toolbar-card,.settings-card { background:var(--card-bg); border:1px solid var(--border-color); border-radius:var(--radius) !important; overflow:hidden; }
        .stat-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:10px; margin-bottom:12px; }
        .stat-card { padding:13px 14px; display:flex; align-items:center; gap:11px; }
        .stat-icon { width:40px; height:40px; display:flex; align-items:center; justify-content:center; border-radius:calc(var(--radius) * .7) !important; color:var(--primary-dark); background:var(--primary-soft); font-size:16px; flex:0 0 40px; }
        .stat-label { font-size:9px; color:var(--muted-color); }
        .stat-value { font-size:20px; font-weight:800; margin-top:2px; }
        .toolbar-card { padding:11px 12px; margin-bottom:12px; display:flex; align-items:center; justify-content:space-between; gap:12px; }
        #addSettingButton { flex:0 0 auto; white-space:nowrap; min-height:36px; }
        .toolbar-left { flex:1 1 auto; min-width:0; display:grid; grid-template-columns:minmax(260px,1.35fr) minmax(190px,.8fr) minmax(150px,.65fr); gap:8px; align-items:center; }
        .toolbar-left > * { width:100%; min-width:0; }
        .search-wrap { position:relative; min-width:0; width:100%; }
        .search-wrap i { position:absolute; left:11px; top:50%; transform:translateY(-50%); color:var(--muted-color); font-size:11px; }
        .search-wrap input { padding-left:32px; }
        .form-control,.form-select { min-height:36px; font-size:11px; border-radius:calc(var(--radius) * .7) !important; border-color:var(--border-color); }
        .btn-theme { background:linear-gradient(135deg,var(--primary),var(--primary-dark)); color:#fff; border:0; border-radius:calc(var(--radius) * .7) !important; font-size:11px; font-weight:700; padding:9px 13px; }
        .btn-theme:hover { color:#fff; filter:brightness(.97); }
        .settings-table { margin:0; font-size:11px; }
        .settings-table th { padding:10px 12px; font-size:9px; text-transform:uppercase; letter-spacing:.04em; color:var(--muted-color); background:color-mix(in srgb,var(--muted-color) 6%,transparent); white-space:nowrap; }
        .settings-table td { padding:10px 12px; vertical-align:middle; border-color:var(--border-color); }
        .setting-name { font-weight:800; color:var(--text-color); }
        .subtext { font-size:9px; color:var(--muted-color); margin-top:2px; }
        .badge-soft,.badge-blue,.badge-green,.badge-red { display:inline-flex; align-items:center; padding:4px 8px; border-radius:999px; font-size:9px; font-weight:700; }
        .badge-soft { background:var(--primary-soft); color:var(--primary-dark); }
        .badge-blue { background:#eff6ff; color:#1d4ed8; }
        .badge-green { background:#eaf8f0; color:#168449; }
        .badge-red { background:#fdecec; color:#bd2d2d; }
        .action-btn { width:30px; height:30px; border:1px solid var(--border-color); border-radius:calc(var(--radius) * .6) !important; background:var(--card-bg); color:var(--text-color); display:inline-flex; align-items:center; justify-content:center; font-size:10px; }
        .action-btn:hover { background:var(--primary-soft); color:var(--primary-dark); }
        .action-btn.danger:hover { background:#fdecec; color:#bd2d2d; }
        .modal-content { border:0; border-radius:var(--radius) !important; background:var(--card-bg); color:var(--text-color); overflow:hidden; }
        .modal-header,.modal-footer { border-color:var(--border-color); }
        .modal-title { font-size:14px; font-weight:800; }
        .section-title { font-size:11px; font-weight:800; margin:4px 0 10px; padding-bottom:7px; border-bottom:1px solid var(--border-color); }
        .field-label { display:block; font-size:10px; font-weight:700; margin-bottom:5px; }
        .preview-box { border:1px dashed var(--border-color); border-radius:var(--radius) !important; padding:14px; background:color-mix(in srgb,var(--card-bg) 95%,var(--page-bg)); }
        .preview-paper { max-width:420px; margin:auto; background:#fff; color:#111; border:1px solid #ddd; border-radius:8px; padding:16px; min-height:250px; box-shadow:0 8px 28px rgba(0,0,0,.08); }
        .preview-title { text-align:center; font-size:16px; font-weight:800; }
        .preview-line { height:1px; background:#ddd; margin:10px 0; }
        .theme-toast { position:fixed; right:18px; top:78px; z-index:20000; display:flex; align-items:center; gap:9px; min-width:260px; max-width:420px; padding:11px 14px; border-radius:10px; color:#fff; font-size:11px; font-weight:600; box-shadow:0 14px 35px rgba(0,0,0,.22); opacity:0; transform:translateY(-10px); transition:.22s; }
        .theme-toast.show { opacity:1; transform:translateY(0); }
        .theme-toast-success { background:#168449; }
        .theme-toast-error { background:#c0392b; }
        .empty-state { padding:45px 20px; text-align:center; color:var(--muted-color); font-size:11px; }
        body.dark-mode,body[data-theme="dark"],html.dark-mode body,html[data-theme="dark"] body { --page-bg:#0f151b; --card-bg:#182129; --text-color:#f3f6f8; --muted-color:#9aa7b3; --border-color:#2c3944; }
        body.dark-mode .form-control,body.dark-mode .form-select,body[data-theme="dark"] .form-control,body[data-theme="dark"] .form-select { background:#121a21; color:var(--text-color); border-color:var(--border-color); }
        body.dark-mode .settings-table,body.dark-mode .settings-table th,body.dark-mode .settings-table td,body[data-theme="dark"] .settings-table,body[data-theme="dark"] .settings-table th,body[data-theme="dark"] .settings-table td { color:var(--text-color); background:transparent; border-color:var(--border-color); }
        body.dark-mode .action-btn,body[data-theme="dark"] .action-btn { background:#121a21; color:#fff; }

        @media(min-width:992px) and (max-width:1199.98px) {
            .toolbar-left { grid-template-columns:minmax(220px,1fr) 180px 150px; }
        }
        @media(max-width:991.98px) {
            .stat-grid { grid-template-columns:repeat(2,minmax(0,1fr)); }
            .toolbar-card { flex-direction:column; align-items:stretch; }
            .toolbar-left { grid-template-columns:minmax(0,1fr) minmax(150px,.55fr) minmax(140px,.5fr); }
            .search-wrap { min-width:0; width:100%; }
            #addSettingButton { width:100%; }
            .settings-card { background:transparent; border:0; }
            .table-responsive { overflow:visible; }
            .settings-table { display:block; }
            .settings-table thead { display:none; }
            .settings-table tbody { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; }
            .settings-table tbody tr { display:grid; grid-template-columns:1fr 1fr; background:var(--card-bg); border:1px solid var(--border-color); border-radius:var(--radius) !important; padding:14px; }
            .settings-table tbody td { display:flex; justify-content:space-between; gap:10px; padding:8px 0; border:0; border-bottom:1px dashed var(--border-color); text-align:right !important; }
            .settings-table tbody td::before { content:attr(data-label); font-size:9px; font-weight:700; text-transform:uppercase; color:var(--muted-color); text-align:left; }
            .settings-table tbody td.main-column { grid-column:1/-1; display:block; text-align:left !important; border-bottom:1px solid var(--border-color); padding:0 0 11px; }
            .settings-table tbody td.main-column::before { display:none; }
            .settings-table tbody td.actions-column { grid-column:1/-1; border-bottom:0; padding-top:11px; align-items:center; }
        }
        @media(max-width:767.98px) {
            .content-wrap { padding-left:10px; padding-right:10px; }
            .toolbar-left { grid-template-columns:1fr; }
            .settings-table tbody { grid-template-columns:1fr; }
            .settings-table tbody tr { grid-template-columns:1fr; padding:13px; }
            .settings-table tbody td { grid-column:1/-1; }
            .theme-toast { left:12px; right:12px; min-width:0; max-width:none; top:70px; }
            .modal-dialog { margin:8px; }
        }
    </style>
</head>
<body>
<?php include('includes/sidebar.php'); ?>
<main class="app-main">
    <?php include('includes/nav.php'); ?>
    <div class="content-wrap">
        <?php if (!$canView): ?>
            <div class="settings-card"><div class="empty-state"><i class="fa-solid fa-lock mb-2"></i><div>You do not have permission to view invoice settings.</div></div></div>
        <?php else: ?>
            <div class="stat-grid">
                <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-file-invoice"></i></div><div><div class="stat-label">Total Settings</div><div class="stat-value" id="statTotal"><?php echo $stats['total']; ?></div></div></div>
                <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-circle-check"></i></div><div><div class="stat-label">Active Settings</div><div class="stat-value" id="statActive"><?php echo $stats['active']; ?></div></div></div>
                <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-star"></i></div><div><div class="stat-label">Default Settings</div><div class="stat-value" id="statDefault"><?php echo $stats['default']; ?></div></div></div>
                <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-receipt"></i></div><div><div class="stat-label">Thermal Formats</div><div class="stat-value" id="statThermal"><?php echo $stats['thermal']; ?></div></div></div>
            </div>

            <div class="toolbar-card">
                <div class="toolbar-left">
                    <div class="search-wrap"><i class="fa-solid fa-magnifying-glass"></i><input type="search" class="form-control" id="settingSearch" placeholder="Search setting, document type, branch..."></div>
                    <select class="form-select" id="documentFilter"><option value="">All document types</option><?php foreach ($documentTypes as $type): ?><option value="<?php echo e(strtolower($type)); ?>"><?php echo e($type); ?></option><?php endforeach; ?></select>
                    <select class="form-select" id="statusFilter"><option value="">All status</option><option value="active">Active</option><option value="inactive">Inactive</option></select>
                </div>
                <?php if ($canCreate): ?><button type="button" class="btn btn-theme" id="addSettingButton"><i class="fa-solid fa-plus me-2"></i>Add Setting</button><?php endif; ?>
            </div>

            <div class="settings-card">
                <div class="table-responsive">
                    <table class="table settings-table align-middle" id="settingsTable">
                        <thead><tr><th>Setting</th><th>Document</th><th>Branch</th><th>Paper</th><th>Number Format</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                        <tbody>
                        <?php foreach ($settings as $setting): ?>
                            <tr data-setting-id="<?php echo (int) $setting['id']; ?>" data-search="<?php echo e(strtolower(implode(' ', [$setting['setting_name'],$setting['document_type'],$setting['branch_name'],$setting['paper_size'],$setting['sample_output']] ))); ?>" data-document="<?php echo e(strtolower($setting['document_type'])); ?>" data-status="<?php echo (int) $setting['is_active'] === 1 ? 'active' : 'inactive'; ?>">
                                <td class="main-column" data-label="Setting"><div class="d-flex align-items-center gap-2"><div class="stat-icon" style="width:34px;height:34px;flex-basis:34px;font-size:13px"><i class="fa-solid fa-file-invoice-dollar"></i></div><div><div class="setting-name"><?php echo e($setting['setting_name']); ?></div><div class="subtext"><?php echo (int) $setting['is_default'] === 1 ? 'Default configuration' : 'Custom configuration'; ?></div></div></div></td>
                                <td data-label="Document"><span class="badge-soft"><?php echo e($setting['document_type']); ?></span></td>
                                <td data-label="Branch"><span class="badge-blue"><?php echo e($setting['branch_name'] ?: 'All Branches'); ?></span></td>
                                <td data-label="Paper"><div><?php echo e($setting['paper_size']); ?> · <?php echo e($setting['orientation']); ?></div><?php if ($setting['paper_size'] === 'Custom'): ?><div class="subtext"><?php echo e($setting['custom_width_mm']); ?> × <?php echo e($setting['custom_height_mm']); ?> mm</div><?php endif; ?></td>
                                <td data-label="Number Format"><div class="fw-semibold"><?php echo e($setting['sample_output']); ?></div><div class="subtext"><?php echo e($setting['reset_frequency']); ?></div></td>
                                <td data-label="Status"><span class="<?php echo (int) $setting['is_active'] === 1 ? 'badge-green' : 'badge-red'; ?>"><?php echo (int) $setting['is_active'] === 1 ? 'Active' : 'Inactive'; ?></span><?php if ((int) $setting['is_default'] === 1): ?><div class="subtext">Default</div><?php endif; ?></td>
                                <td class="text-end actions-column" data-label="Actions"><div class="d-inline-flex gap-1"><?php if ($canUpdate): ?><button class="action-btn edit-setting" type="button" title="Edit" data-id="<?php echo (int) $setting['id']; ?>"><i class="fa-solid fa-pen"></i></button><button class="action-btn clone-setting" type="button" title="Clone" data-id="<?php echo (int) $setting['id']; ?>"><i class="fa-regular fa-copy"></i></button><?php endif; ?><?php if ($canDelete): ?><button class="action-btn danger delete-setting" type="button" title="Delete" data-id="<?php echo (int) $setting['id']; ?>" data-name="<?php echo e($setting['setting_name']); ?>"><i class="fa-solid fa-trash"></i></button><?php endif; ?></div></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (!$settings): ?><div class="empty-state"><i class="fa-regular fa-file-lines fs-3 mb-2"></i><div>No invoice settings found.</div></div><?php endif; ?>
            </div>
        <?php endif; ?>
        <?php include('includes/footer.php'); ?>
    </div>
</main>

<div class="modal fade" id="settingModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <form class="modal-content" id="settingForm" enctype="multipart/form-data">
            <div class="modal-header"><h5 class="modal-title" id="settingModalTitle">Add Invoice Setting</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="setting_id" id="setting_id" value="0">
                <div class="section-title">Basic Configuration</div>
                <div class="row g-3">
                    <div class="col-md-4"><label class="field-label">Setting name <span class="text-danger">*</span></label><input class="form-control" type="text" name="setting_name" id="setting_name" maxlength="100" required></div>
                    <div class="col-md-4"><label class="field-label">Document type <span class="text-danger">*</span></label><select class="form-select" name="document_type" id="document_type" required><?php foreach ($documentTypes as $type): ?><option value="<?php echo e($type); ?>"><?php echo e($type); ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-4"><label class="field-label">Branch</label><select class="form-select" name="branch_id" id="branch_id"><option value="">All Branches</option><?php foreach ($branches as $branch): ?><option value="<?php echo (int) $branch['id']; ?>"><?php echo e($branch['branch_name'] . ' (' . $branch['branch_code'] . ')'); ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-3"><label class="field-label">Paper size</label><select class="form-select" name="paper_size" id="paper_size"><?php foreach ($paperSizes as $size): ?><option value="<?php echo e($size); ?>"><?php echo e($size); ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-3"><label class="field-label">Orientation</label><select class="form-select" name="orientation" id="orientation"><?php foreach ($orientations as $orientation): ?><option value="<?php echo e($orientation); ?>"><?php echo e($orientation); ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-3 custom-size-field d-none"><label class="field-label">Custom width (mm)</label><input class="form-control" type="number" step="0.01" min="1" name="custom_width_mm" id="custom_width_mm"></div>
                    <div class="col-md-3 custom-size-field d-none"><label class="field-label">Custom height (mm)</label><input class="form-control" type="number" step="0.01" min="1" name="custom_height_mm" id="custom_height_mm"></div>
                </div>

                <div class="section-title mt-4">Branding & Display</div>
                <div class="row g-3">
                    <div class="col-md-4"><label class="field-label">Invoice logo</label><input class="form-control" type="file" name="invoice_logo" id="invoice_logo" accept=".png,.jpg,.jpeg,.webp"></div>
                    <div class="col-md-4"><label class="field-label">Signature</label><input class="form-control" type="file" name="signature" id="signature" accept=".png,.jpg,.jpeg,.webp"></div>
                    <div class="col-md-4"><label class="field-label">Stamp</label><input class="form-control" type="file" name="stamp" id="stamp" accept=".png,.jpg,.jpeg,.webp"></div>
                    <div class="col-12"><div class="row g-2">
                        <?php $toggles = ['show_business_logo'=>'Show business logo','show_gstin'=>'Show GSTIN','show_hsn'=>'Show HSN','show_tax_breakup'=>'Show tax breakup','show_customer_balance'=>'Show customer balance','show_qr_code'=>'Show QR code']; foreach ($toggles as $name=>$label): ?>
                        <div class="col-md-4 col-sm-6"><div class="form-check"><input class="form-check-input" type="checkbox" name="<?php echo e($name); ?>" value="1" id="<?php echo e($name); ?>"><label class="form-check-label" for="<?php echo e($name); ?>"><?php echo e($label); ?></label></div></div>
                        <?php endforeach; ?>
                    </div></div>
                    <div class="col-md-6"><label class="field-label">UPI ID</label><input class="form-control" type="text" name="upi_id" id="upi_id" maxlength="120"></div>
                </div>

                <div class="section-title mt-4">Invoice Content</div>
                <div class="row g-3">
                    <div class="col-md-6"><label class="field-label">Header text</label><textarea class="form-control" name="header_text" id="header_text" rows="3"></textarea></div>
                    <div class="col-md-6"><label class="field-label">Footer text</label><textarea class="form-control" name="footer_text" id="footer_text" rows="3"></textarea></div>
                    <div class="col-12"><label class="field-label">Terms & conditions</label><textarea class="form-control" name="terms_conditions" id="terms_conditions" rows="4"></textarea></div>
                </div>

                <div class="section-title mt-4">Numbering Format</div>
                <div class="row g-3">
                    <div class="col-md-2"><label class="field-label">Prefix</label><input class="form-control" type="text" name="prefix" id="prefix" maxlength="30" value="INV"></div>
                    <div class="col-md-3"><label class="field-label">Middle format</label><input class="form-control" type="text" name="middle_format" id="middle_format" maxlength="80" value="{FY_SHORT}"></div>
                    <div class="col-md-2"><label class="field-label">Suffix</label><input class="form-control" type="text" name="suffix" id="suffix" maxlength="30"></div>
                    <div class="col-md-2"><label class="field-label">Splitter</label><input class="form-control" type="text" name="splitter_symbol" id="splitter_symbol" maxlength="5" value="/"></div>
                    <div class="col-md-3"><label class="field-label">Reset frequency</label><select class="form-select" name="reset_frequency" id="reset_frequency"><?php foreach ($resetFrequencies as $frequency): ?><option value="<?php echo e($frequency); ?>"><?php echo e($frequency); ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-3"><label class="field-label">Sequence digits</label><input class="form-control" type="number" min="1" max="10" name="sequence_digits" id="sequence_digits" value="3"></div>
                    <div class="col-md-3"><label class="field-label">Sequence start</label><input class="form-control" type="number" min="1" name="sequence_start" id="sequence_start" value="1"></div>
                    <div class="col-md-6"><label class="field-label">Format template</label><input class="form-control" type="text" name="format_template" id="format_template" maxlength="150" value="{PREFIX}{SPLITTER}{FY_SHORT}{SPLITTER}{SEQ}"><div class="subtext">Tokens: {PREFIX}, {YYYY}, {YY}, {FY_SHORT}, {FY_2DIGIT}, {MM}, {DD}, {SEQ}, {SPLITTER}, {SUFFIX}</div></div>
                    <div class="col-md-6"><label class="field-label">Sample output</label><input class="form-control" type="text" name="sample_output" id="sample_output" maxlength="150" readonly></div>
                    <div class="col-md-3"><div class="form-check mt-4"><input class="form-check-input" type="checkbox" name="is_default" value="1" id="is_default"><label class="form-check-label" for="is_default">Default setting</label></div></div>
                    <div class="col-md-3"><div class="form-check mt-4"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active" checked><label class="form-check-label" for="is_active">Active</label></div></div>
                </div>

                <div class="section-title mt-4">Live Preview</div>
                <div class="preview-box"><div class="preview-paper"><div class="preview-title" id="previewHeader">TAX INVOICE</div><div class="preview-line"></div><div class="d-flex justify-content-between"><div><strong>Invoice No:</strong> <span id="previewNumber">INV/26-27/001</span></div><div><strong>Date:</strong> <?php echo date('d-m-Y'); ?></div></div><div class="preview-line"></div><div class="small">Customer: Sample Customer</div><div class="preview-line"></div><div class="small">Item details, quantity, weight, rate and tax will appear here.</div><div class="preview-line"></div><div class="text-end fw-bold">Grand Total: ₹ 0.00</div><div class="preview-line"></div><div class="text-center small" id="previewFooter">Thank you for your business.</div></div></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-theme" id="saveSettingButton"><i class="fa-solid fa-floppy-disk me-2"></i>Save Setting</button></div>
        </form>
    </div>
</div>

<div class="offcanvas-backdrop fade" id="mobileBackdrop" style="display:none"></div>
<?php include('includes/script.php'); ?>
<script src="assets/js/script.js"></script>
<script>
(function(){
    'use strict';
    const csrfToken = <?php echo json_encode($csrfToken); ?>;
    const modalEl = document.getElementById('settingModal');
    const modal = modalEl ? new bootstrap.Modal(modalEl) : null;
    const form = document.getElementById('settingForm');

    function toast(type,message){const t=document.createElement('div');t.className='theme-toast theme-toast-'+type;t.innerHTML='<i class="fa-solid '+(type==='success'?'fa-circle-check':'fa-circle-exclamation')+'"></i><span></span>';t.querySelector('span').textContent=message;document.body.appendChild(t);requestAnimationFrame(()=>t.classList.add('show'));setTimeout(()=>{t.classList.remove('show');setTimeout(()=>t.remove(),250)},3200)}
    async function api(fd){const r=await fetch('api/invoice-settings-save.php',{method:'POST',body:fd,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}});const j=await r.json().catch(()=>({success:false,message:'Invalid response received from the server.'}));if(!r.ok||!j.success)throw new Error(j.message||'Request failed.');return j}
    function pad(value,len){return String(value).padStart(len,'0')}
    function buildSample(){const template=document.getElementById('format_template').value||'{PREFIX}{SPLITTER}{FY_SHORT}{SPLITTER}{SEQ}';const splitter=document.getElementById('splitter_symbol').value||'';const prefix=document.getElementById('prefix').value||'';const suffix=document.getElementById('suffix').value||'';const digits=Math.max(1,parseInt(document.getElementById('sequence_digits').value||'3',10));const start=Math.max(1,parseInt(document.getElementById('sequence_start').value||'1',10));const now=new Date();const year=now.getFullYear();const month=String(now.getMonth()+1).padStart(2,'0');const day=String(now.getDate()).padStart(2,'0');const fyStart=(now.getMonth()+1)>=4?year:year-1;const fyShort=String(fyStart).slice(-2)+'-'+String(fyStart+1).slice(-2);let out=template;const map={'{PREFIX}':prefix,'{YYYY}':String(year),'{YY}':String(year).slice(-2),'{FY_SHORT}':fyShort,'{FY_2DIGIT}':String(fyStart).slice(-2)+String(fyStart+1).slice(-2),'{MM}':month,'{DD}':day,'{SEQ}':pad(start,digits),'{SPLITTER}':splitter,'{SUFFIX}':suffix};Object.keys(map).forEach(k=>out=out.split(k).join(map[k]));document.getElementById('sample_output').value=out;document.getElementById('previewNumber').textContent=out;document.getElementById('previewHeader').textContent=document.getElementById('header_text').value||'TAX INVOICE';document.getElementById('previewFooter').textContent=document.getElementById('footer_text').value||'Thank you for your business.'}
    function toggleCustom(){document.querySelectorAll('.custom-size-field').forEach(el=>el.classList.toggle('d-none',document.getElementById('paper_size').value!=='Custom'))}
    function resetForm(){form.reset();document.getElementById('setting_id').value='0';document.getElementById('settingModalTitle').textContent='Add Invoice Setting';document.getElementById('prefix').value='INV';document.getElementById('middle_format').value='{FY_SHORT}';document.getElementById('splitter_symbol').value='/';document.getElementById('sequence_digits').value='3';document.getElementById('sequence_start').value='1';document.getElementById('format_template').value='{PREFIX}{SPLITTER}{FY_SHORT}{SPLITTER}{SEQ}';document.getElementById('show_business_logo').checked=true;document.getElementById('show_gstin').checked=true;document.getElementById('show_hsn').checked=true;document.getElementById('show_tax_breakup').checked=true;document.getElementById('is_active').checked=true;toggleCustom();buildSample()}

    ['prefix','middle_format','suffix','splitter_symbol','sequence_digits','sequence_start','format_template','header_text','footer_text'].forEach(id=>document.getElementById(id).addEventListener('input',buildSample));
    document.getElementById('paper_size').addEventListener('change',toggleCustom);
    const addBtn=document.getElementById('addSettingButton'); if(addBtn)addBtn.addEventListener('click',()=>{resetForm();modal.show()});

    const search=document.getElementById('settingSearch'),docFilter=document.getElementById('documentFilter'),statusFilter=document.getElementById('statusFilter');
    function filterRows(){const q=(search?.value||'').trim().toLowerCase(),d=docFilter?.value||'',s=statusFilter?.value||'';document.querySelectorAll('#settingsTable tbody tr').forEach(row=>{row.style.display=((!q||row.dataset.search.includes(q))&&(!d||row.dataset.document===d)&&(!s||row.dataset.status===s))?'':'none'})}
    [search,docFilter,statusFilter].forEach(el=>el&&el.addEventListener('input',filterRows));

    document.addEventListener('click',async function(event){
        const edit=event.target.closest('.edit-setting');
        if(edit){try{const fd=new FormData();fd.append('action','get');fd.append('csrf_token',csrfToken);fd.append('setting_id',edit.dataset.id);const r=await api(fd);resetForm();const s=r.setting;document.getElementById('settingModalTitle').textContent='Edit Invoice Setting';document.getElementById('setting_id').value=String(s.id||edit.dataset.id);Object.keys(s).forEach(k=>{if(k==='id')return;const el=document.getElementById(k);if(!el)return;if(el.type==='checkbox')el.checked=Number(s[k])===1;else el.value=s[k]??''});toggleCustom();buildSample();modal.show()}catch(err){toast('error',err.message)}}
        const clone=event.target.closest('.clone-setting');
        if(clone){try{const fd=new FormData();fd.append('action','get');fd.append('csrf_token',csrfToken);fd.append('setting_id',clone.dataset.id);const r=await api(fd);resetForm();const s=r.setting;Object.keys(s).forEach(k=>{const el=document.getElementById(k);if(!el||k==='id')return;if(el.type==='checkbox')el.checked=Number(s[k])===1;else el.value=s[k]??''});document.getElementById('setting_id').value='0';document.getElementById('setting_name').value=(s.setting_name||'Setting')+' Copy';document.getElementById('is_default').checked=false;document.getElementById('settingModalTitle').textContent='Clone Invoice Setting';toggleCustom();buildSample();modal.show()}catch(err){toast('error',err.message)}}
        const del=event.target.closest('.delete-setting');
        if(del){if(!confirm('Delete '+del.dataset.name+'?'))return;const fd=new FormData();fd.append('action','delete');fd.append('csrf_token',csrfToken);fd.append('setting_id',del.dataset.id);try{const r=await api(fd);toast('success',r.message);del.closest('tr').remove()}catch(err){toast('error',err.message)}}
    });

    form.addEventListener('submit',async function(event){event.preventDefault();const btn=document.getElementById('saveSettingButton'),old=btn.innerHTML;btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin me-2"></i>Saving...';try{const r=await api(new FormData(form));toast('success',r.message);modal.hide();setTimeout(()=>location.reload(),450)}catch(err){toast('error',err.message)}finally{btn.disabled=false;btn.innerHTML=old}});
    buildSample();
})();
</script>
</body>
</html>
