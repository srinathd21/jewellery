<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));

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
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('themeTableExists')) {
    function themeTableExists(mysqli $conn, string $table): bool
    {
        $safe = $conn->real_escape_string($table);
        $result = $conn->query("SHOW TABLES LIKE '{$safe}'");
        return $result && $result->num_rows > 0;
    }
}

if (!function_exists('themeColumns')) {
    function themeColumns(mysqli $conn, string $table): array
    {
        $columns = [];
        $result = $conn->query("SHOW COLUMNS FROM `{$table}`");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $columns[(string)$row['Field']] = true;
            }
        }
        return $columns;
    }
}

if (!function_exists('hasThemePermission')) {
    function hasThemePermission(mysqli $conn, string $action): bool
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
            'delete' => 'can_delete',
            'approve' => 'can_approve',
        ];
        $field = $fieldMap[$action] ?? '';
        if ($field === '') {
            return false;
        }

        $sessionPermissions = $_SESSION['permissions'] ?? [];
        foreach (['perm.theme_settings', 'perm.settings', 'perm.business_settings'] as $key) {
            if (isset($sessionPermissions[$key][$field])) {
                return (int)$sessionPermissions[$key][$field] === 1;
            }
        }

        $businessId = (int)($_SESSION['business_id'] ?? 0);
        $roleId = (int)($_SESSION['role_id'] ?? 0);
        if ($businessId <= 0 || $roleId <= 0) {
            return false;
        }

        $sql = "SELECT rp.`{$field}`
                FROM role_permissions rp
                INNER JOIN permissions p ON p.id = rp.permission_id
                WHERE rp.business_id = ?
                  AND rp.role_id = ?
                  AND p.is_active = 1
                  AND p.permission_key IN ('perm.theme_settings','perm.settings','perm.business_settings')
                ORDER BY FIELD(p.permission_key,'perm.theme_settings','perm.business_settings','perm.settings')
                LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ii', $businessId, $roleId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int)($row[$field] ?? 0) === 1;
    }
}

if (!hasThemePermission($conn, 'open')) {
    http_response_code(403);
    die('Access denied. You do not have permission to open theme settings.');
}

$canView = hasThemePermission($conn, 'view') || hasThemePermission($conn, 'open');
$canUpdate = hasThemePermission($conn, 'update');
$businessId = (int)($_SESSION['business_id'] ?? 0);
$isPlatformAdmin = (($_SESSION['user_type'] ?? '') === 'Platform Admin');

if (!$isPlatformAdmin && $businessId <= 0) {
    http_response_code(403);
    die('A valid business must be selected before managing theme settings.');
}

if (empty($_SESSION['theme_settings_csrf'])) {
    $_SESSION['theme_settings_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['theme_settings_csrf'];

$defaults = [
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
    'logo_path' => '',
    'border_radius_px' => 12,
    'sidebar_width_px' => 205,
];

if (!themeTableExists($conn, 'business_theme_settings')) {
    die('Required table business_theme_settings does not exist.');
}

$availableColumns = themeColumns($conn, 'business_theme_settings');

$theme = $defaults;
$stmt = $conn->prepare('SELECT * FROM business_theme_settings WHERE business_id = ? LIMIT 1');
$stmt->bind_param('i', $businessId);
$stmt->execute();
$current = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();
foreach ($theme as $key => $default) {
    if (array_key_exists($key, $current) && $current[$key] !== null && $current[$key] !== '') {
        $theme[$key] = $current[$key];
    }
}

$businessName = (string)($_SESSION['business_name'] ?? 'Jewellery ERP');
$pageTitle = 'Theme Settings';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo e($businessName); ?> - Theme Settings</title>
    <?php include('includes/links.php'); ?>
    <style>
        .theme-settings-grid{display:grid;grid-template-columns:minmax(0,1fr) 350px;gap:14px;align-items:start}
        .settings-panel{background:var(--card,#fff);border:1px solid var(--line,#e8e8e8);border-radius:14px;box-shadow:var(--shadow,0 5px 18px rgba(24,31,40,.08));overflow:hidden}
        .settings-panel-head{padding:14px 16px;border-bottom:1px solid var(--line,#e8e8e8);display:flex;align-items:center;justify-content:space-between;gap:12px}
        .settings-panel-title{font-size:15px;font-weight:700;margin:0}
        .settings-panel-body{padding:16px}
        .settings-section{padding-bottom:18px;margin-bottom:18px;border-bottom:1px solid var(--line,#e8e8e8)}
        .settings-section:last-child{border-bottom:0;margin-bottom:0;padding-bottom:0}
        .settings-section h3{font-size:13px;font-weight:700;margin:0 0 12px}
        .field-label{font-size:11px;font-weight:600;margin-bottom:6px;display:block}
        .color-field{display:flex;gap:8px;align-items:center}
        .color-field input[type=color]{width:42px;height:36px;padding:3px;border:1px solid var(--line,#e8e8e8);border-radius:9px;background:var(--card,#fff);cursor:pointer}
        .color-field input[type=text]{height:36px;font-size:11px}
        .form-control,.form-select{font-size:11px;min-height:36px;border-radius:9px}
        .preview-shell{position:sticky;top:78px;background:<?php echo e($theme['page_background']); ?>;border-radius:14px;overflow:hidden;border:1px solid var(--line,#e8e8e8)}
        .preview-top{height:52px;background:<?php echo e($theme['card_background']); ?>;display:flex;align-items:center;padding:0 12px;border-bottom:1px solid <?php echo e($theme['border_color']); ?>;color:<?php echo e($theme['text_color']); ?>}
        .preview-layout{display:grid;grid-template-columns:92px 1fr;min-height:420px}
        .preview-sidebar{background:linear-gradient(180deg,<?php echo e($theme['sidebar_gradient_1']); ?>,<?php echo e($theme['sidebar_gradient_2']); ?>,<?php echo e($theme['sidebar_gradient_3']); ?>);padding:12px 8px}
        .preview-brand{height:38px;display:flex;align-items:center;justify-content:center;color:#f5c75d;font-size:9px;font-weight:800;letter-spacing:.14em;text-align:center}
        .preview-menu{height:30px;border-radius:8px;margin-top:8px;background:rgba(255,255,255,.06)}
        .preview-menu.active{background:linear-gradient(135deg,<?php echo e($theme['primary_color']); ?>,<?php echo e($theme['primary_dark_color']); ?>)}
        .preview-main{padding:12px;background:<?php echo e($theme['page_background']); ?>}
        .preview-card{height:78px;background:<?php echo e($theme['card_background']); ?>;border:1px solid <?php echo e($theme['border_color']); ?>;border-radius:<?php echo (int)$theme['border_radius_px']; ?>px;margin-bottom:10px;padding:10px;color:<?php echo e($theme['text_color']); ?>}
        .preview-card-title{font-size:9px;color:<?php echo e($theme['muted_text_color']); ?>}
        .preview-card-value{font-size:16px;font-weight:700;margin-top:6px}
        .preview-accent{height:7px;width:65%;margin-top:12px;border-radius:999px;background:<?php echo e($theme['primary_color']); ?>}
        .logo-preview{width:100%;height:90px;border:1px dashed var(--line,#d9d9d9);border-radius:10px;display:flex;align-items:center;justify-content:center;background:rgba(127,127,127,.03);overflow:hidden}
        .logo-preview img{max-width:90%;max-height:72px;object-fit:contain}
        .logo-placeholder{font-size:11px;color:var(--muted,#7d8794)}
        .save-bar{position:sticky;bottom:0;padding:12px 16px;background:color-mix(in srgb,var(--card,#fff) 94%,transparent);border-top:1px solid var(--line,#e8e8e8);display:flex;justify-content:flex-end;gap:8px;backdrop-filter:blur(8px)}
        .btn-theme{background:linear-gradient(135deg,var(--gold,#d89416),var(--gold-dark,#b86a0b));border:0;color:#fff;font-size:11px;font-weight:700;border-radius:9px;padding:9px 16px}
        .btn-theme:disabled{opacity:.55}
        .small-help{font-size:9px;color:var(--muted,#7d8794);margin-top:4px}
        body.dark-mode .settings-panel{background:var(--card);border-color:var(--line)}
        body.dark-mode .form-control,body.dark-mode .form-select{background:#171e26;color:#eef2f7;border-color:#303740}
        body.dark-mode .save-bar{background:rgba(21,27,34,.94)}
        .theme-toast{position:fixed;right:18px;top:78px;z-index:20000;display:flex;align-items:center;gap:9px;min-width:260px;max-width:420px;padding:11px 14px;border-radius:10px;color:#fff;font-size:11px;font-weight:600;box-shadow:0 14px 35px rgba(0,0,0,.22);opacity:0;transform:translateY(-10px);transition:opacity .22s ease,transform .22s ease}.theme-toast.show{opacity:1;transform:translateY(0)}.theme-toast-success{background:#168449}.theme-toast-error{background:#c0392b}
        @media(max-width:1100px){.theme-settings-grid{grid-template-columns:1fr}.preview-shell{position:relative;top:0}}
    </style>
</head>
<body>
<?php include('includes/sidebar.php'); ?>
<main class="app-main">
    <?php include('includes/nav.php'); ?>
    <div class="content-wrap">
        <?php if (!$canView): ?>
            <div class="settings-panel"><div class="settings-panel-body">You do not have permission to view theme settings.</div></div>
        <?php else: ?>
        <form method="post" enctype="multipart/form-data" id="themeSettingsForm">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
            <input type="hidden" name="existing_logo_path" value="<?php echo e($theme['logo_path']); ?>">
            <div class="theme-settings-grid">
                <section class="settings-panel">
                    <div class="settings-panel-head">
                        <div>
                            <h2 class="settings-panel-title">Business Theme</h2>
                            <div class="small text-muted">Customize the appearance for <?php echo e($businessName); ?>.</div>
                        </div>
                    </div>
                    <div class="settings-panel-body">
                        <div class="settings-section">
                            <h3>Brand identity</h3>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="field-label" for="logo_file">Business logo</label>
                                    <div class="logo-preview mb-2" id="logoPreview">
                                        <?php if ($theme['logo_path'] !== ''): ?>
                                            <img src="<?php echo e($theme['logo_path']); ?>" alt="Business logo">
                                        <?php else: ?>
                                            <span class="logo-placeholder">No logo uploaded</span>
                                        <?php endif; ?>
                                    </div>
                                    <input class="form-control" type="file" name="logo_file" id="logo_file" accept=".png,.jpg,.jpeg,.webp,.svg">
                                    <div class="small-help">PNG, JPG, WEBP or SVG. Maximum 2 MB.</div>
                                    <?php if ($theme['logo_path'] !== ''): ?>
                                        <div class="form-check mt-2">
                                            <input class="form-check-input" type="checkbox" name="remove_logo" value="1" id="remove_logo">
                                            <label class="form-check-label small" for="remove_logo">Remove current logo</label>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-6">
                                    <label class="field-label">Primary color</label>
                                    <div class="color-field"><input type="color" data-color-picker="primary_color" value="<?php echo e($theme['primary_color']); ?>"><input class="form-control" type="text" name="primary_color" id="primary_color" value="<?php echo e($theme['primary_color']); ?>" maxlength="7"></div>
                                    <label class="field-label mt-3">Primary dark color</label>
                                    <div class="color-field"><input type="color" data-color-picker="primary_dark_color" value="<?php echo e($theme['primary_dark_color']); ?>"><input class="form-control" type="text" name="primary_dark_color" id="primary_dark_color" value="<?php echo e($theme['primary_dark_color']); ?>" maxlength="7"></div>
                                    <label class="field-label mt-3">Primary soft color</label>
                                    <div class="color-field"><input type="color" data-color-picker="primary_soft_color" value="<?php echo e($theme['primary_soft_color']); ?>"><input class="form-control" type="text" name="primary_soft_color" id="primary_soft_color" value="<?php echo e($theme['primary_soft_color']); ?>" maxlength="7"></div>
                                </div>
                            </div>
                        </div>

                        <div class="settings-section">
                            <h3>Sidebar colors</h3>
                            <div class="row g-3">
                                <?php foreach (['sidebar_gradient_1'=>'Gradient start','sidebar_gradient_2'=>'Gradient middle','sidebar_gradient_3'=>'Gradient end'] as $field=>$label): ?>
                                <div class="col-md-4">
                                    <label class="field-label"><?php echo e($label); ?></label>
                                    <div class="color-field"><input type="color" data-color-picker="<?php echo e($field); ?>" value="<?php echo e($theme[$field]); ?>"><input class="form-control" type="text" name="<?php echo e($field); ?>" id="<?php echo e($field); ?>" value="<?php echo e($theme[$field]); ?>" maxlength="7"></div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="settings-section">
                            <h3>Page and card colors</h3>
                            <div class="row g-3">
                                <?php foreach (['page_background'=>'Page background','card_background'=>'Card background','text_color'=>'Text color','muted_text_color'=>'Muted text','border_color'=>'Border color'] as $field=>$label): ?>
                                <div class="col-md-4">
                                    <label class="field-label"><?php echo e($label); ?></label>
                                    <div class="color-field"><input type="color" data-color-picker="<?php echo e($field); ?>" value="<?php echo e($theme[$field]); ?>"><input class="form-control" type="text" name="<?php echo e($field); ?>" id="<?php echo e($field); ?>" value="<?php echo e($theme[$field]); ?>" maxlength="7"></div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="settings-section">
                            <h3>Typography and dimensions</h3>
                            <div class="row g-3">
                                <div class="col-md-6"><label class="field-label">Body font family</label><input class="form-control" type="text" name="font_family" id="font_family" value="<?php echo e($theme['font_family']); ?>"></div>
                                <div class="col-md-6"><label class="field-label">Heading font family</label><input class="form-control" type="text" name="heading_font_family" id="heading_font_family" value="<?php echo e($theme['heading_font_family']); ?>"></div>
                                <div class="col-md-6"><label class="field-label">Border radius (px)</label><input class="form-control" type="number" name="border_radius_px" id="border_radius_px" value="<?php echo (int)$theme['border_radius_px']; ?>" min="0" max="40"></div>
                                <div class="col-md-6"><label class="field-label">Sidebar width (px)</label><input class="form-control" type="number" name="sidebar_width_px" id="sidebar_width_px" value="<?php echo (int)$theme['sidebar_width_px']; ?>" min="180" max="340"></div>
                            </div>
                        </div>
                    </div>
                    <div class="save-bar">
                        <button type="button" class="btn btn-light btn-sm" id="resetPreview">Reset to defaults</button>
                        <button type="submit" class="btn-theme" <?php echo !$canUpdate ? 'disabled title="Update permission required"' : ''; ?>><i class="fa-solid fa-floppy-disk me-2"></i>Save Theme</button>
                    </div>
                </section>

                <aside class="settings-panel">
                    <div class="settings-panel-head"><h2 class="settings-panel-title">Live Preview</h2></div>
                    <div class="settings-panel-body">
                        <div class="preview-shell" id="themePreview">
                            <div class="preview-top"><strong>Dashboard</strong></div>
                            <div class="preview-layout">
                                <div class="preview-sidebar"><div class="preview-brand">SRI JEWELS</div><div class="preview-menu active"></div><div class="preview-menu"></div><div class="preview-menu"></div><div class="preview-menu"></div></div>
                                <div class="preview-main"><div class="preview-card"><div class="preview-card-title">Total Sales</div><div class="preview-card-value">₹ 1,25,000</div><div class="preview-accent"></div></div><div class="preview-card"><div class="preview-card-title">Inventory Value</div><div class="preview-card-value">₹ 8,40,000</div><div class="preview-accent" style="width:45%"></div></div><div class="preview-card"><div class="preview-card-title">Recent Invoices</div></div></div>
                            </div>
                        </div>
                    </div>
                </aside>
            </div>
        </form>
        <?php endif; ?>

        <?php include('includes/footer.php'); ?>
    </div>
</main>
<div class="offcanvas-backdrop fade" id="mobileBackdrop" style="display:none"></div>
<?php include('includes/script.php'); ?>
<script src="assets/js/script.js"></script>
<script>
(function () {
    'use strict';

    const form = document.getElementById('themeSettingsForm');
    const preview = document.getElementById('themePreview');
    if (!form || !preview) return;

    const saveButton = form.querySelector('button[type="submit"]');
    const resetButton = document.getElementById('resetPreview');
    const logoInput = document.getElementById('logo_file');
    const logoPreview = document.getElementById('logoPreview');
    const removeLogo = document.getElementById('remove_logo');

    const fieldIds = [
        'primary_color', 'primary_dark_color', 'primary_soft_color',
        'sidebar_gradient_1', 'sidebar_gradient_2', 'sidebar_gradient_3',
        'page_background', 'card_background', 'text_color',
        'muted_text_color', 'border_color', 'font_family',
        'heading_font_family', 'border_radius_px', 'sidebar_width_px'
    ];

    const initial = {};
    fieldIds.forEach(function (id) {
        const element = document.getElementById(id);
        if (element) initial[id] = element.value;
    });

    // Application default theme values. The reset button uses these values,
    // not the currently saved database values.
    const defaultTheme = <?php echo json_encode([
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
        'sidebar_width_px' => 205,
    ], JSON_UNESCAPED_SLASHES); ?>;

    const initialLogoHtml = logoPreview ? logoPreview.innerHTML : '';

    function showToast(type, message) {
        const toast = document.createElement('div');
        toast.className = 'theme-toast theme-toast-' + type;
        toast.setAttribute('role', 'alert');
        toast.innerHTML = '<i class="fa-solid ' + (type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation') + '"></i><span></span>';
        toast.querySelector('span').textContent = message;
        document.body.appendChild(toast);
        requestAnimationFrame(function () { toast.classList.add('show'); });
        setTimeout(function () {
            toast.classList.remove('show');
            setTimeout(function () { toast.remove(); }, 250);
        }, 3200);
    }

    function value(id) {
        const element = document.getElementById(id);
        return element && element.value !== '' ? element.value : initial[id];
    }

    function applyThemeToPage() {
        const root = document.documentElement;
        const body = document.body;

        root.style.setProperty('--gold', value('primary_color'));
        root.style.setProperty('--gold-dark', value('primary_dark_color'));
        root.style.setProperty('--gold-soft', value('primary_soft_color'));
        root.style.setProperty('--bg', value('page_background'));
        root.style.setProperty('--card', value('card_background'));
        root.style.setProperty('--text', value('text_color'));
        root.style.setProperty('--muted', value('muted_text_color'));
        root.style.setProperty('--line', value('border_color'));
        root.style.setProperty('--sidebar', value('sidebar_gradient_1'));
        root.style.setProperty('--sidebar2', value('sidebar_gradient_2'));
        root.style.setProperty('--sidebar-w', (parseInt(value('sidebar_width_px'), 10) || 205) + 'px');
        root.style.setProperty('--radius', (parseInt(value('border_radius_px'), 10) || 0) + 'px');

        body.style.background = value('page_background');
        body.style.color = value('text_color');
        body.style.fontFamily = value('font_family') || 'Inter';

        const sidebarElement = document.querySelector('.app-sidebar');
        if (sidebarElement) {
            sidebarElement.style.background = 'linear-gradient(180deg,' +
                value('sidebar_gradient_1') + ',' +
                value('sidebar_gradient_2') + ',' +
                value('sidebar_gradient_3') + ')';
            sidebarElement.style.width = (parseInt(value('sidebar_width_px'), 10) || 205) + 'px';
        }

        const appMain = document.querySelector('.app-main');
        if (appMain && !body.classList.contains('sidebar-collapsed') && window.innerWidth >= 992) {
            appMain.style.marginLeft = (parseInt(value('sidebar_width_px'), 10) || 205) + 'px';
        }

        document.querySelectorAll('.card-panel, .settings-panel, .metric-card, .tax-box, .tax-total, .form-control, .form-select, .mini-btn, .btn-theme').forEach(function (element) {
            element.style.borderRadius = (parseInt(value('border_radius_px'), 10) || 0) + 'px';
        });

        document.querySelectorAll('.page-title, .section-title, .settings-panel-title, h1, h2, h3').forEach(function (element) {
            element.style.fontFamily = value('heading_font_family') || 'Playfair Display';
        });
    }

    function updatePreview() {
        preview.style.background = value('page_background');
        preview.style.fontFamily = value('font_family') || 'Inter';

        const top = preview.querySelector('.preview-top');
        if (top) {
            top.style.background = value('card_background');
            top.style.color = value('text_color');
            top.style.borderColor = value('border_color');
            top.style.fontFamily = value('heading_font_family') || 'Playfair Display';
        }

        const sidebar = preview.querySelector('.preview-sidebar');
        if (sidebar) {
            sidebar.style.background = 'linear-gradient(180deg,' + value('sidebar_gradient_1') + ',' + value('sidebar_gradient_2') + ',' + value('sidebar_gradient_3') + ')';
        }

        preview.querySelectorAll('.preview-menu.active').forEach(function (element) {
            element.style.background = 'linear-gradient(135deg,' + value('primary_color') + ',' + value('primary_dark_color') + ')';
        });

        preview.querySelectorAll('.preview-main').forEach(function (element) {
            element.style.background = value('page_background');
        });

        preview.querySelectorAll('.preview-card').forEach(function (element) {
            element.style.background = value('card_background');
            element.style.color = value('text_color');
            element.style.borderColor = value('border_color');
            element.style.borderRadius = (parseInt(value('border_radius_px'), 10) || 0) + 'px';
            element.style.fontFamily = value('font_family') || 'Inter';
        });

        preview.querySelectorAll('.preview-card-title').forEach(function (element) {
            element.style.color = value('muted_text_color');
        });

        preview.querySelectorAll('.preview-accent').forEach(function (element) {
            element.style.background = value('primary_color');
        });

        applyThemeToPage();
    }

    document.querySelectorAll('[data-color-picker]').forEach(function (picker) {
        const id = picker.dataset.colorPicker;
        const text = document.getElementById(id);
        if (!text) return;

        picker.addEventListener('input', function () {
            text.value = picker.value;
            updatePreview();
        });

        text.addEventListener('input', function () {
            if (/^#[0-9a-fA-F]{6}$/.test(text.value)) picker.value = text.value;
            updatePreview();
        });
    });

    fieldIds.forEach(function (id) {
        const element = document.getElementById(id);
        if (element) element.addEventListener('input', updatePreview);
    });

    if (logoInput && logoPreview) {
        logoInput.addEventListener('change', function () {
            const file = logoInput.files && logoInput.files[0];
            if (!file) return;
            if (!['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml'].includes(file.type)) {
                logoInput.value = '';
                showToast('error', 'Logo must be PNG, JPG, WEBP or SVG.');
                return;
            }
            if (file.size > 2 * 1024 * 1024) {
                logoInput.value = '';
                showToast('error', 'Logo file size must not exceed 2 MB.');
                return;
            }
            const reader = new FileReader();
            reader.onload = function (event) {
                logoPreview.innerHTML = '<img alt="Logo preview">';
                logoPreview.querySelector('img').src = event.target.result;
                if (removeLogo) removeLogo.checked = false;
            };
            reader.readAsDataURL(file);
        });
    }

    if (removeLogo && logoPreview) {
        removeLogo.addEventListener('change', function () {
            if (removeLogo.checked) {
                logoPreview.innerHTML = '<span class="logo-placeholder">Logo will be removed after saving</span>';
                if (logoInput) logoInput.value = '';
            } else {
                logoPreview.innerHTML = initialLogoHtml;
            }
        });
    }

    if (resetButton) {
        resetButton.addEventListener('click', function () {
            fieldIds.forEach(function (id) {
                const element = document.getElementById(id);
                if (!element || !(id in defaultTheme)) return;

                const defaultValue = String(defaultTheme[id]);
                element.value = defaultValue;

                const picker = document.querySelector('[data-color-picker="' + id + '"]');
                if (picker && /^#[0-9a-fA-F]{6}$/.test(defaultValue)) {
                    picker.value = defaultValue;
                }
            });

            // Reset logo controls to the default state: no uploaded logo.
            if (logoInput) logoInput.value = '';
            if (removeLogo) removeLogo.checked = true;
            if (logoPreview) {
                logoPreview.innerHTML = '<span class="logo-placeholder">Default theme uses no custom logo</span>';
            }

            updatePreview();
            showToast('success', 'All fields were reset to the default theme values. Click Save Theme to apply them.');
        });
    }

    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (!saveButton || saveButton.disabled) {
            showToast('error', 'You do not have permission to update theme settings.');
            return;
        }

        const originalHtml = saveButton.innerHTML;
        saveButton.disabled = true;
        saveButton.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Saving...';

        try {
            const response = await fetch('api/theme-settings-save.php', {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });

            const result = await response.json().catch(function () {
                return { success: false, message: 'Invalid response received from the server.' };
            });

            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Unable to save theme settings.');
            }

            showToast('success', result.message || 'Theme settings saved successfully.');
            applyThemeToPage();

            // Keep the current page open and update the visible logo immediately when possible.
            const returnedLogo = result.logo_path || '';
            if (returnedLogo) {
                document.querySelectorAll('.brand-logo-img').forEach(function (img) {
                    img.src = returnedLogo + (returnedLogo.includes('?') ? '&' : '?') + 'v=' + Date.now();
                });
            } else if (removeLogo && removeLogo.checked) {
                document.querySelectorAll('.brand-logo-img').forEach(function (img) {
                    img.style.display = 'none';
                });
            }
        } catch (error) {
            showToast('error', error.message || 'Unable to save theme settings.');
        } finally {
            saveButton.disabled = false;
            saveButton.innerHTML = originalHtml;
        }
    });

    updatePreview();
})();
</script>
</body>
</html>
