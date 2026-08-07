<?php
if (session_status() === PHP_SESSION_NONE)
    session_start();
date_default_timezone_set((string) ($_SESSION['timezone'] ?? 'Asia/Kolkata'));

foreach ([__DIR__ . '/config/config.php', __DIR__ . '/config.php', __DIR__ . '/super-admin/includes/config.php'] as $file) {
    if (is_file($file)) {
        require_once $file;
        break;
    }
}
if (!isset($conn) || !($conn instanceof mysqli))
    die('Database configuration is not available.');
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

function businessSettingsPermission(mysqli $conn, string $action): bool
{
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin')
        return true;
    $fieldMap = ['open' => 'can_open', 'view' => 'can_view', 'value' => 'can_view_value', 'create' => 'can_create', 'update' => 'can_update', 'approve' => 'can_approve', 'delete' => 'can_delete'];
    $field = $fieldMap[$action] ?? '';
    if ($field === '')
        return false;

    foreach (['perm.settings.business', 'perm.settings'] as $key) {
        if (isset($_SESSION['permissions'][$key][$field]))
            return (int) $_SESSION['permissions'][$key][$field] === 1;
    }

    $businessId = (int) ($_SESSION['business_id'] ?? 0);
    $roleId = (int) ($_SESSION['role_id'] ?? 0);
    if ($businessId <= 0 || $roleId <= 0)
        return false;
    $sql = "SELECT rp.`{$field}` FROM role_permissions rp INNER JOIN permissions p ON p.id=rp.permission_id
          WHERE rp.business_id=? AND rp.role_id=? AND p.is_active=1
          AND p.permission_code IN ('perm.settings.business','perm.settings')
          ORDER BY FIELD(p.permission_code,'perm.settings.business','perm.settings') LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt)
        return false;
    $stmt->bind_param('ii', $businessId, $roleId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row[$field] ?? 0) === 1;
}

if (!businessSettingsPermission($conn, 'open')) {
    http_response_code(403);
    die('Access denied.');
}
$canView = businessSettingsPermission($conn, 'view') || businessSettingsPermission($conn, 'open');
$canUpdate = businessSettingsPermission($conn, 'update');
$businessId = (int) ($_SESSION['business_id'] ?? 0);
if ($businessId <= 0) {
    http_response_code(403);
    die('A valid business must be selected.');
}
if (empty($_SESSION['business_settings_csrf']))
    $_SESSION['business_settings_csrf'] = bin2hex(random_bytes(32));
$csrfToken = $_SESSION['business_settings_csrf'];

$stmt = $conn->prepare('SELECT * FROM businesses WHERE id=? LIMIT 1');
$stmt->bind_param('i', $businessId);
$stmt->execute();
$business = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$business)
    die('Business record not found.');

$customSettings = [];
$check = $conn->query("SHOW TABLES LIKE 'business_settings'");
if ($check && $check->num_rows > 0) {
    $stmt = $conn->prepare('SELECT id,setting_key,setting_value,value_type,is_public FROM business_settings WHERE business_id=? ORDER BY setting_key');
    $stmt->bind_param('i', $businessId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc())
        $customSettings[] = $row;
    $stmt->close();
}

$pageTitle = 'Business Settings';
$businessName = (string) ($business['business_name'] ?? 'Jewellery ERP');
$months = [1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'];
$timezones = ['Asia/Kolkata', 'Asia/Dubai', 'Asia/Singapore', 'Europe/London', 'America/New_York', 'America/Los_Angeles', 'UTC'];
$currencies = ['INR' => '₹', 'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'AED' => 'د.إ', 'SGD' => 'S$'];
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?php echo e($businessName); ?> - Business Settings</title>
    <?php include('includes/links.php'); ?>
    <style>
        .bs-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 330px;
            gap: 14px;
            align-items: start
        }

        .bs-card {
            background: var(--card, #fff);
            border: 1px solid var(--line, #e8e8e8);
            border-radius: 14px;
            box-shadow: var(--shadow, 0 5px 18px rgba(24, 31, 40, .08));
            overflow: hidden
        }

        .bs-head {
            padding: 13px 15px;
            border-bottom: 1px solid var(--line, #e8e8e8);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px
        }

        .bs-title {
            font-size: 14px;
            font-weight: 700;
            margin: 0
        }

        .bs-body {
            padding: 15px
        }

        .bs-section {
            padding-bottom: 17px;
            margin-bottom: 17px;
            border-bottom: 1px solid var(--line, #e8e8e8)
        }

        .bs-section:last-child {
            border: 0;
            margin: 0;
            padding: 0
        }

        .bs-section h3 {
            font-size: 12px;
            font-weight: 700;
            margin: 0 0 11px
        }

        .field-label {
            font-size: 10px;
            font-weight: 600;
            margin-bottom: 5px;
            display: block
        }

        .form-control,
        .form-select {
            font-size: 11px;
            min-height: 36px;
            border-radius: 9px
        }

        .readonly {
            background: #f5f6f7 !important
        }

        .save-bar {
            position: sticky;
            bottom: 0;
            padding: 11px 15px;
            background: color-mix(in srgb, var(--card, #fff) 94%, transparent);
            border-top: 1px solid var(--line, #e8e8e8);
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            backdrop-filter: blur(8px)
        }

        .btn-save {
            background: linear-gradient(135deg, var(--gold, #d89416), var(--gold-dark, #b86a0b));
            border: 0;
            color: #fff;
            font-size: 11px;
            font-weight: 700;
            border-radius: 9px;
            padding: 9px 16px
        }

        .summary-row {
            display: flex;
            gap: 9px;
            padding: 10px;
            border: 1px solid var(--line, #e8e8e8);
            border-radius: 10px;
            margin-bottom: 8px
        }

        .summary-icon {
            width: 30px;
            height: 30px;
            display: grid;
            place-items: center;
            border-radius: 8px;
            background: var(--gold-soft, #fff6e5);
            color: var(--gold-dark, #b86a0b)
        }

        .summary-label {
            font-size: 9px;
            color: var(--muted, #7d8794)
        }

        .summary-value {
            font-size: 11px;
            font-weight: 600;
            word-break: break-word
        }

        .custom-row {
            display: grid;
            grid-template-columns: 1.2fr 1.5fr .8fr auto auto;
            gap: 8px;
            align-items: center;
            margin-bottom: 8px
        }

        .toast-msg {
            position: fixed;
            right: 18px;
            top: 78px;
            z-index: 20000;
            display: flex;
            gap: 8px;
            align-items: center;
            min-width: 260px;
            max-width: 430px;
            padding: 11px 14px;
            border-radius: 10px;
            color: #fff;
            font-size: 11px;
            font-weight: 600;
            box-shadow: 0 14px 35px rgba(0, 0, 0, .22);
            opacity: 0;
            transform: translateY(-10px);
            transition: .22s
        }

        .toast-msg.show {
            opacity: 1;
            transform: none
        }

        .toast-success {
            background: #168449
        }

        .toast-error {
            background: #c0392b
        }

        body.dark-mode .bs-card {
            background: var(--card);
            border-color: var(--line)
        }

        body.dark-mode .form-control,
        body.dark-mode .form-select {
            background: #171e26;
            color: #eef2f7;
            border-color: #303740
        }

        body.dark-mode .readonly {
            background: #202832 !important
        }

        @media(max-width:1050px) {
            .bs-grid {
                grid-template-columns: 1fr
            }
        }

        @media(max-width:760px) {
            .custom-row {
                grid-template-columns: 1fr 1fr
            }

            .custom-row>*:nth-child(3),
            .custom-row>*:nth-child(4),
            .custom-row>*:nth-child(5) {
                grid-column: auto
            }
        }
    </style>
</head>

<body>
    <?php include('includes/sidebar.php'); ?>
    <main class="app-main"><?php include('includes/nav.php'); ?>
        <div class="content-wrap">
            <?php if (!$canView): ?>
                <div class="bs-card">
                    <div class="bs-body">You do not have permission to view business settings.</div>
                </div>
            <?php else: ?>
                <form id="businessSettingsForm">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <div class="bs-grid">
                        <section class="bs-card">
                            <div class="bs-head">
                                <div>
                                    <h2 class="bs-title">Business Profile</h2>
                                    <div class="small text-muted">All editable fields available in the businesses table.
                                    </div>
                                </div>
                            </div>
                            <div class="bs-body">
                                <div class="bs-section">
                                    <h3>Identity and ownership</h3>
                                    <div class="row g-3">
                                        <div class="col-md-4"><label class="field-label">Business ID</label><input
                                                class="form-control readonly" value="<?php echo (int) $business['id']; ?>"
                                                readonly></div>
                                        <div class="col-md-4"><label class="field-label">Subscription Plan ID</label><input
                                                class="form-control readonly"
                                                value="<?php echo e($business['subscription_plan_id']); ?>" readonly></div>
                                        <div class="col-md-4"><label class="field-label">Business Code</label><input
                                                class="form-control" name="business_code" id="business_code" maxlength="50"
                                                value="<?php echo e($business['business_code']); ?>" required></div>
                                        <div class="col-md-6"><label class="field-label">Business Name</label><input
                                                class="form-control" name="business_name" id="business_name" maxlength="150"
                                                value="<?php echo e($business['business_name']); ?>" required></div>
                                        <div class="col-md-6"><label class="field-label">Legal Name</label><input
                                                class="form-control" name="legal_name" id="legal_name" maxlength="180"
                                                value="<?php echo e($business['legal_name']); ?>"></div>
                                        <div class="col-md-6"><label class="field-label">Business Type</label><input
                                                class="form-control" name="business_type" id="business_type" maxlength="100"
                                                value="<?php echo e($business['business_type']); ?>" required></div>
                                        <div class="col-md-6"><label class="field-label">Owner Name</label><input
                                                class="form-control" name="owner_name" id="owner_name" maxlength="150"
                                                value="<?php echo e($business['owner_name']); ?>"></div>
                                    </div>
                                </div>

                                <div class="bs-section">
                                    <h3>Contact details</h3>
                                    <div class="row g-3">
                                        <div class="col-md-4"><label class="field-label">Mobile</label><input
                                                class="form-control" name="mobile" id="mobile" maxlength="20"
                                                value="<?php echo e($business['mobile']); ?>"></div>
                                        <div class="col-md-4"><label class="field-label">WhatsApp</label><input
                                                class="form-control" name="whatsapp" id="whatsapp" maxlength="20"
                                                value="<?php echo e($business['whatsapp']); ?>"></div>
                                        <div class="col-md-4"><label class="field-label">Email</label><input
                                                class="form-control" type="email" name="email" id="email" maxlength="150"
                                                value="<?php echo e($business['email']); ?>"></div>
                                        <div class="col-12"><label class="field-label">Website</label><input
                                                class="form-control" type="url" name="website" id="website" maxlength="180"
                                                value="<?php echo e($business['website']); ?>"
                                                placeholder="https://example.com"></div>
                                    </div>
                                </div>

                                <div class="bs-section">
                                    <h3>Tax and registration</h3>
                                    <div class="row g-3">
                                        <div class="col-md-4"><label class="field-label">GSTIN</label><input
                                                class="form-control text-uppercase" name="gstin" id="gstin" maxlength="30"
                                                value="<?php echo e($business['gstin']); ?>"></div>
                                        <div class="col-md-4"><label class="field-label">PAN Number</label><input
                                                class="form-control text-uppercase" name="pan_no" id="pan_no" maxlength="20"
                                                value="<?php echo e($business['pan_no']); ?>"></div>
                                        <div class="col-md-4"><label class="field-label">CIN Number</label><input
                                                class="form-control text-uppercase" name="cin_no" id="cin_no" maxlength="30"
                                                value="<?php echo e($business['cin_no']); ?>"></div>
                                    </div>
                                </div>

                                <div class="bs-section">
                                    <h3>Currency and regional settings</h3>
                                    <div class="row g-3">
                                        <div class="col-md-3"><label class="field-label">Currency Code</label><select
                                                class="form-select" name="currency_code"
                                                id="currency_code"><?php foreach ($currencies as $code => $symbol): ?>
                                                    <option value="<?php echo e($code); ?>" <?php echo $business['currency_code'] === $code ? 'selected' : ''; ?>>
                                                        <?php echo e($code); ?></option><?php endforeach; ?>
                                            </select></div>
                                        <div class="col-md-3"><label class="field-label">Currency Symbol</label><input
                                                class="form-control" name="currency_symbol" id="currency_symbol"
                                                maxlength="10" value="<?php echo e($business['currency_symbol']); ?>"></div>
                                        <div class="col-md-3"><label class="field-label">Timezone</label><select
                                                class="form-select" name="timezone"
                                                id="timezone"><?php foreach ($timezones as $tz): ?>
                                                    <option value="<?php echo e($tz); ?>" <?php echo $business['timezone'] === $tz ? 'selected' : ''; ?>><?php echo e($tz); ?>
                                                    </option><?php endforeach; ?>
                                            </select></div>
                                        <div class="col-md-3"><label class="field-label">Financial Year
                                                Starts</label><select class="form-select" name="financial_year_start_month"
                                                id="financial_year_start_month"><?php foreach ($months as $num => $label): ?>
                                                    <option value="<?php echo $num; ?>" <?php echo (int) $business['financial_year_start_month'] === $num ? 'selected' : ''; ?>>
                                                        <?php echo e($label); ?></option><?php endforeach; ?>
                                            </select></div>
                                    </div>
                                </div>

                                <div class="bs-section">
                                    <h3>Account status</h3>
                                    <div class="row g-3">
                                        <div class="col-md-4"><label class="field-label">Status</label><select
                                                class="form-select" name="status" id="status" <?php echo (($_SESSION['user_type'] ?? '') === 'Platform Admin') ? '' : 'disabled'; ?>><?php foreach (['Trial', 'Active', 'Suspended', 'Closed'] as $s): ?>
                                                    <option value="<?php echo e($s); ?>" <?php echo $business['status'] === $s ? 'selected' : ''; ?>><?php echo e($s); ?></option>
                                                <?php endforeach; ?>
                                            </select><?php if (($_SESSION['user_type'] ?? '') !== 'Platform Admin'): ?><input
                                                    type="hidden" name="status"
                                                    value="<?php echo e($business['status']); ?>"><?php endif; ?></div>
                                        <div class="col-md-4"><label class="field-label">Trial Ends At</label><input
                                                class="form-control" type="date" name="trial_ends_at" id="trial_ends_at"
                                                value="<?php echo e($business['trial_ends_at']); ?>" <?php echo (($_SESSION['user_type'] ?? '') === 'Platform Admin') ? '' : 'readonly'; ?>></div>
                                        <div class="col-md-4"><label class="field-label">Last Updated</label><input
                                                class="form-control readonly"
                                                value="<?php echo e($business['updated_at']); ?>" readonly></div>
                                    </div>
                                </div>

                                <div class="bs-section">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <h3 class="mb-0">Custom Business Settings</h3><button type="button"
                                            class="btn btn-sm btn-light" id="addCustomSetting"><i
                                                class="fa-solid fa-plus me-1"></i>Add Setting</button>
                                    </div>
                                    <div class="small text-muted mb-2">Stored in the business_settings key-value table.
                                    </div>
                                    <div id="customSettingsRows">
                                        <?php foreach ($customSettings as $setting): ?>
                                            <div class="custom-row">
                                                <input class="form-control setting-key"
                                                    value="<?php echo e($setting['setting_key']); ?>" placeholder="setting_key">
                                                <input class="form-control setting-value"
                                                    value="<?php echo e($setting['setting_value']); ?>" placeholder="Value">
                                                <select
                                                    class="form-select setting-type"><?php foreach (['string', 'number', 'boolean', 'json', 'date', 'datetime'] as $t): ?>
                                                        <option value="<?php echo $t; ?>" <?php echo $setting['value_type'] === $t ? 'selected' : ''; ?>><?php echo ucfirst($t); ?>
                                                        </option><?php endforeach; ?>
                                                </select>
                                                <label class="form-check small mb-0"><input
                                                        class="form-check-input setting-public" type="checkbox" <?php echo (int) $setting['is_public'] === 1 ? 'checked' : ''; ?>> Public</label>
                                                <button type="button" class="btn btn-sm btn-outline-danger remove-setting"><i
                                                        class="fa-solid fa-trash"></i></button>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="save-bar"><button type="button" class="btn btn-light btn-sm"
                                    id="resetBusinessForm">Reset Unsaved</button><button type="submit" class="btn-save"
                                    <?php echo !$canUpdate ? 'disabled title="Update permission required"' : ''; ?>><i
                                        class="fa-solid fa-floppy-disk me-2"></i>Save Settings</button></div>
                        </section>

                        <aside class="bs-card">
                            <div class="bs-head">
                                <h2 class="bs-title">Business Summary</h2>
                            </div>
                            <div class="bs-body">
                                <div class="summary-row">
                                    <div class="summary-icon"><i class="fa-solid fa-building"></i></div>
                                    <div>
                                        <div class="summary-label">Business</div>
                                        <div class="summary-value" data-summary="business_name">
                                            <?php echo e($business['business_name']); ?></div>
                                    </div>
                                </div>
                                <div class="summary-row">
                                    <div class="summary-icon"><i class="fa-solid fa-user-tie"></i></div>
                                    <div>
                                        <div class="summary-label">Owner</div>
                                        <div class="summary-value" data-summary="owner_name">
                                            <?php echo e($business['owner_name'] ?: '—'); ?></div>
                                    </div>
                                </div>
                                <div class="summary-row">
                                    <div class="summary-icon"><i class="fa-solid fa-phone"></i></div>
                                    <div>
                                        <div class="summary-label">Contact</div>
                                        <div class="summary-value" data-summary="mobile">
                                            <?php echo e($business['mobile'] ?: '—'); ?></div>
                                    </div>
                                </div>
                                <div class="summary-row">
                                    <div class="summary-icon"><i class="fa-solid fa-receipt"></i></div>
                                    <div>
                                        <div class="summary-label">GSTIN</div>
                                        <div class="summary-value" data-summary="gstin">
                                            <?php echo e($business['gstin'] ?: '—'); ?></div>
                                    </div>
                                </div>
                                <div class="summary-row">
                                    <div class="summary-icon"><i class="fa-solid fa-coins"></i></div>
                                    <div>
                                        <div class="summary-label">Currency</div>
                                        <div class="summary-value" data-summary="currency_code">
                                            <?php echo e($business['currency_code'] . ' ' . $business['currency_symbol']); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="summary-row">
                                    <div class="summary-icon"><i class="fa-solid fa-circle-check"></i></div>
                                    <div>
                                        <div class="summary-label">Status</div>
                                        <div class="summary-value" data-summary="status">
                                            <?php echo e($business['status']); ?></div>
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
            const form = document.getElementById('businessSettingsForm'); if (!form) return;
            const saveBtn = form.querySelector('button[type=submit]'); const initialHtml = document.getElementById('customSettingsRows').innerHTML;
            function toast(type, msg) { const el = document.createElement('div'); el.className = 'toast-msg toast-' + type; el.innerHTML = '<i class="fa-solid ' + (type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation') + '"></i><span></span>'; el.querySelector('span').textContent = msg; document.body.appendChild(el); requestAnimationFrame(() => el.classList.add('show')); setTimeout(() => { el.classList.remove('show'); setTimeout(() => el.remove(), 250) }, 3200) }
            function bindRemove() { document.querySelectorAll('.remove-setting').forEach(b => b.onclick = () => b.closest('.custom-row').remove()) }
            function addRow() { const row = document.createElement('div'); row.className = 'custom-row'; row.innerHTML = '<input class="form-control setting-key" placeholder="setting_key"><input class="form-control setting-value" placeholder="Value"><select class="form-select setting-type"><option value="string">String</option><option value="number">Number</option><option value="boolean">Boolean</option><option value="json">JSON</option><option value="date">Date</option><option value="datetime">Datetime</option></select><label class="form-check small mb-0"><input class="form-check-input setting-public" type="checkbox"> Public</label><button type="button" class="btn btn-sm btn-outline-danger remove-setting"><i class="fa-solid fa-trash"></i></button>'; document.getElementById('customSettingsRows').appendChild(row); bindRemove() }
            document.getElementById('addCustomSetting').addEventListener('click', addRow); bindRemove();
            ['business_name', 'owner_name', 'mobile', 'gstin', 'status'].forEach(id => { const el = document.getElementById(id); if (el) el.addEventListener('input', () => { const out = document.querySelector('[data-summary="' + id + '"]'); if (out) out.textContent = el.value || '—' }) });
            ['currency_code', 'currency_symbol'].forEach(id => { const el = document.getElementById(id); if (el) el.addEventListener('input', () => { const out = document.querySelector('[data-summary="currency_code"]'); if (out) out.textContent = (document.getElementById('currency_code').value + ' ' + document.getElementById('currency_symbol').value).trim() }) });
            document.getElementById('resetBusinessForm').addEventListener('click', () => { form.reset(); document.getElementById('customSettingsRows').innerHTML = initialHtml; bindRemove(); toast('success', 'Unsaved changes were reset.') });
            form.addEventListener('submit', async e => {
                e.preventDefault(); if (!saveBtn || saveBtn.disabled) { toast('error', 'You do not have permission to update business settings.'); return }
                const settings = []; document.querySelectorAll('.custom-row').forEach(row => { const key = row.querySelector('.setting-key').value.trim(); if (key) settings.push({ setting_key: key, setting_value: row.querySelector('.setting-value').value, value_type: row.querySelector('.setting-type').value, is_public: row.querySelector('.setting-public').checked ? 1 : 0 }) });
                const fd = new FormData(form); fd.set('custom_settings_json', JSON.stringify(settings)); const old = saveBtn.innerHTML; saveBtn.disabled = true; saveBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Saving...';
                try { const r = await fetch('api/business-settings-save.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } }); const j = await r.json().catch(() => ({ success: false, message: 'Invalid server response.' })); if (!r.ok || !j.success) throw new Error(j.message || 'Unable to save settings.'); toast('success', j.message || 'Business settings saved successfully.'); document.querySelector('.page-title') && (document.querySelector('.page-title').textContent = 'Business Settings'); }
                catch (err) { toast('error', err.message || 'Unable to save settings.') } finally { saveBtn.disabled = false; saveBtn.innerHTML = old }
            });
        })();
    </script>
</body>

</html>