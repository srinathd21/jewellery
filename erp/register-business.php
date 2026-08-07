<?php
session_start();
date_default_timezone_set('Asia/Kolkata');

$configCandidates = [
    __DIR__ . '/config/config.php',
    __DIR__ . '/config.php',
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

function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function cleanCode(string $value): string
{
    $value = strtoupper(trim($value));
    return preg_replace('/[^A-Z0-9_-]/', '', $value) ?? '';
}

function uploadBusinessLogo(array $file, int $businessId): ?string
{
    if (empty($file['name']) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ((int)$file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Logo upload failed.');
    }

    if ((int)$file['size'] > 2 * 1024 * 1024) {
        throw new RuntimeException('Logo must be smaller than 2 MB.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowed = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'image/svg+xml' => 'svg',
    ];

    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Logo must be PNG, JPG, WEBP, or SVG.');
    }

    $relativeDir = 'uploads/business/' . $businessId;
    $absoluteDir = __DIR__ . '/' . $relativeDir;
    if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) {
        throw new RuntimeException('Unable to create the business upload folder.');
    }

    $relativePath = $relativeDir . '/logo.' . $allowed[$mime];
    $absolutePath = __DIR__ . '/' . $relativePath;

    if (!move_uploaded_file($file['tmp_name'], $absolutePath)) {
        throw new RuntimeException('Unable to save the uploaded logo.');
    }

    return $relativePath;
}

if (empty($_SESSION['public_register_csrf'])) {
    $_SESSION['public_register_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['public_register_csrf'];

$error = '';
$success = '';

$form = [
    'business_code' => '',
    'business_name' => '',
    'legal_name' => '',
    'business_type' => 'Jewellery',
    'owner_name' => '',
    'mobile' => '',
    'whatsapp' => '',
    'email' => '',
    'website' => '',
    'gstin' => '',
    'pan_no' => '',
    'subscription_plan_id' => '',
    'branch_code' => 'HO',
    'branch_name' => '',
    'branch_type' => 'Head Office',
    'branch_mobile' => '',
    'branch_email' => '',
    'address_line1' => '',
    'address_line2' => '',
    'city' => '',
    'district' => '',
    'state' => 'Tamil Nadu',
    'pincode' => '',
    'country' => 'India',
    'branch_gstin' => '',
    'full_name' => '',
    'employee_code' => '',
    'username' => '',
    'user_email' => '',
    'user_mobile' => '',
    'theme_preset_id' => '',
    'invoice_prefix' => 'INV',
    'invoice_splitter' => '/',
    'invoice_format' => '{PREFIX}{SPLITTER}{FY_SHORT}{SPLITTER}{SEQ}',
];

$plans = [];
$planResult = $conn->query("SELECT id, plan_name, max_branches, max_users FROM subscription_plans WHERE is_active = 1 ORDER BY id ASC");
if ($planResult) {
    while ($row = $planResult->fetch_assoc()) {
        $plans[] = $row;
    }
}

$themes = [];
$themeResult = $conn->query("SELECT id, preset_name, primary_color, sidebar_gradient_1, sidebar_gradient_2, sidebar_gradient_3 FROM theme_presets ORDER BY id ASC");
if ($themeResult) {
    while ($row = $themeResult->fetch_assoc()) {
        $themes[] = $row;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($form as $key => $default) {
        if (array_key_exists($key, $_POST)) {
            $form[$key] = trim((string)$_POST[$key]);
        }
    }

    $form['business_code'] = cleanCode($form['business_code']);
    $form['branch_code'] = cleanCode($form['branch_code']);
    $form['employee_code'] = cleanCode($form['employee_code']);

    $password = (string)($_POST['password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');
    $postedToken = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals($csrfToken, $postedToken)) {
        $error = 'The form session expired. Refresh the page and try again.';
    } elseif ($form['business_code'] === '' || $form['business_name'] === '') {
        $error = 'Business code and business name are required.';
    } elseif ($form['branch_code'] === '' || $form['branch_name'] === '') {
        $error = 'Branch code and branch name are required.';
    } elseif ($form['full_name'] === '' || $form['username'] === '') {
        $error = 'Administrator name and username are required.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must contain at least 8 characters.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Password and confirmation do not match.';
    } else {
        $conn->begin_transaction();

        try {
            $check = $conn->prepare('SELECT id FROM businesses WHERE business_code = ? LIMIT 1');
            if (!$check) {
                throw new RuntimeException('Unable to validate business code.');
            }
            $check->bind_param('s', $form['business_code']);
            $check->execute();
            if ($check->get_result()->fetch_assoc()) {
                throw new RuntimeException('This business code already exists.');
            }
            $check->close();

            $planId = $form['subscription_plan_id'] !== '' ? (int)$form['subscription_plan_id'] : null;
            $businessSql = "INSERT INTO businesses
                (subscription_plan_id, business_code, business_name, legal_name, business_type, owner_name,
                 mobile, whatsapp, email, website, gstin, pan_no, currency_code, currency_symbol,
                 timezone, financial_year_start_month, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'INR', '₹', 'Asia/Kolkata', 4, 'Active')";
            $stmt = $conn->prepare($businessSql);
            if (!$stmt) {
                throw new RuntimeException('Unable to create the business.');
            }
            $stmt->bind_param(
                'isssssssssss',
                $planId,
                $form['business_code'],
                $form['business_name'],
                $form['legal_name'],
                $form['business_type'],
                $form['owner_name'],
                $form['mobile'],
                $form['whatsapp'],
                $form['email'],
                $form['website'],
                $form['gstin'],
                $form['pan_no']
            );
            if (!$stmt->execute()) {
                throw new RuntimeException('Unable to create the business: ' . $stmt->error);
            }
            $businessId = (int)$conn->insert_id;
            $stmt->close();

            $branchSql = "INSERT INTO branches
                (business_id, branch_code, branch_name, branch_type, contact_person, mobile, email,
                 address_line1, address_line2, city, district, state, pincode, country, gstin,
                 is_default, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1)";
            $stmt = $conn->prepare($branchSql);
            if (!$stmt) {
                throw new RuntimeException('Unable to create the branch.');
            }
            $contactPerson = $form['owner_name'] !== '' ? $form['owner_name'] : $form['full_name'];
            $stmt->bind_param(
                'issssssssssssss',
                $businessId,
                $form['branch_code'],
                $form['branch_name'],
                $form['branch_type'],
                $contactPerson,
                $form['branch_mobile'],
                $form['branch_email'],
                $form['address_line1'],
                $form['address_line2'],
                $form['city'],
                $form['district'],
                $form['state'],
                $form['pincode'],
                $form['country'],
                $form['branch_gstin']
            );
            if (!$stmt->execute()) {
                throw new RuntimeException('Unable to create the branch: ' . $stmt->error);
            }
            $branchId = (int)$conn->insert_id;
            $stmt->close();

            $roleCode = 'BUSINESS_ADMIN';
            $roleName = 'Business Admin';
            $roleDescription = 'Full access to this business';
            $stmt = $conn->prepare("INSERT INTO roles (business_id, role_code, role_name, description, is_system, is_active) VALUES (?, ?, ?, ?, 1, 1)");
            if (!$stmt) {
                throw new RuntimeException('Unable to create the administrator role.');
            }
            $stmt->bind_param('isss', $businessId, $roleCode, $roleName, $roleDescription);
            if (!$stmt->execute()) {
                throw new RuntimeException('Unable to create the administrator role: ' . $stmt->error);
            }
            $roleId = (int)$conn->insert_id;
            $stmt->close();

            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $userSql = "INSERT INTO users
                (business_id, default_branch_id, employee_code, full_name, username, email, mobile,
                 password_hash, user_type, must_change_password, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Business User', 0, 1)";
            $stmt = $conn->prepare($userSql);
            if (!$stmt) {
                throw new RuntimeException('Unable to create the administrator user.');
            }
            $stmt->bind_param(
                'iissssss',
                $businessId,
                $branchId,
                $form['employee_code'],
                $form['full_name'],
                $form['username'],
                $form['user_email'],
                $form['user_mobile'],
                $passwordHash
            );
            if (!$stmt->execute()) {
                throw new RuntimeException('Unable to create the administrator user: ' . $stmt->error);
            }
            $userId = (int)$conn->insert_id;
            $stmt->close();

            $stmt = $conn->prepare('INSERT INTO user_roles (business_id, user_id, role_id, is_primary) VALUES (?, ?, ?, 1)');
            if (!$stmt) {
                throw new RuntimeException('Unable to assign the role.');
            }
            $stmt->bind_param('iii', $businessId, $userId, $roleId);
            if (!$stmt->execute()) {
                throw new RuntimeException('Unable to assign the role: ' . $stmt->error);
            }
            $stmt->close();

            $stmt = $conn->prepare('INSERT INTO user_branch_access (business_id, user_id, branch_id, is_default, can_switch_branch) VALUES (?, ?, ?, 1, 1)');
            if (!$stmt) {
                throw new RuntimeException('Unable to assign branch access.');
            }
            $stmt->bind_param('iii', $businessId, $userId, $branchId);
            if (!$stmt->execute()) {
                throw new RuntimeException('Unable to assign branch access: ' . $stmt->error);
            }
            $stmt->close();

            $permissionsResult = $conn->query('SELECT id FROM permissions WHERE is_active = 1 ORDER BY id ASC');
            if ($permissionsResult) {
                $permissionStmt = $conn->prepare("INSERT INTO role_permissions
                    (business_id, role_id, permission_id, can_open, can_view_value, can_view, can_create, can_update, can_approve, can_delete)
                    VALUES (?, ?, ?, 1, 1, 1, 1, 1, 1, 1)");
                if (!$permissionStmt) {
                    throw new RuntimeException('Unable to prepare role permissions.');
                }
                while ($permissionRow = $permissionsResult->fetch_assoc()) {
                    $permissionId = (int)$permissionRow['id'];
                    $permissionStmt->bind_param('iii', $businessId, $roleId, $permissionId);
                    if (!$permissionStmt->execute()) {
                        throw new RuntimeException('Unable to assign role permissions: ' . $permissionStmt->error);
                    }
                }
                $permissionStmt->close();
            }

            $themePresetId = $form['theme_preset_id'] !== '' ? (int)$form['theme_preset_id'] : null;
            $themeData = [
                'theme_name' => 'Default Theme',
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
            ];

            if ($themePresetId) {
                $stmt = $conn->prepare('SELECT preset_name, primary_color, primary_dark_color, primary_soft_color, sidebar_gradient_1, sidebar_gradient_2, sidebar_gradient_3, page_background, card_background, text_color, muted_text_color, border_color FROM theme_presets WHERE id = ? LIMIT 1');
                if ($stmt) {
                    $stmt->bind_param('i', $themePresetId);
                    $stmt->execute();
                    $themeRow = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if ($themeRow) {
                        $themeData['theme_name'] = $themeRow['preset_name'];
                        foreach ($themeData as $key => $value) {
                            if (array_key_exists($key, $themeRow)) {
                                $themeData[$key] = $themeRow[$key];
                            }
                        }
                    }
                }
            }

            $logoPath = uploadBusinessLogo($_FILES['business_logo'] ?? [], $businessId);

            $themeSql = "INSERT INTO business_theme_settings
                (business_id, theme_preset_id, theme_name, primary_color, primary_dark_color, primary_soft_color,
                 sidebar_gradient_1, sidebar_gradient_2, sidebar_gradient_3, page_background, card_background,
                 text_color, muted_text_color, border_color, logo_path)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($themeSql);
            if (!$stmt) {
                throw new RuntimeException('Unable to save theme settings.');
            }
            $stmt->bind_param(
                'iisssssssssssss',
                $businessId,
                $themePresetId,
                $themeData['theme_name'],
                $themeData['primary_color'],
                $themeData['primary_dark_color'],
                $themeData['primary_soft_color'],
                $themeData['sidebar_gradient_1'],
                $themeData['sidebar_gradient_2'],
                $themeData['sidebar_gradient_3'],
                $themeData['page_background'],
                $themeData['card_background'],
                $themeData['text_color'],
                $themeData['muted_text_color'],
                $themeData['border_color'],
                $logoPath
            );
            if (!$stmt->execute()) {
                throw new RuntimeException('Unable to save theme settings: ' . $stmt->error);
            }
            $stmt->close();

            $invoicePrefix = cleanCode($form['invoice_prefix']);
            if ($invoicePrefix === '') {
                $invoicePrefix = 'INV';
            }
            $invoiceSplitter = substr($form['invoice_splitter'], 0, 5);
            $sampleOutput = $invoicePrefix . $invoiceSplitter . date('y') . '-' . date('y', strtotime('+1 year')) . $invoiceSplitter . '001';
            $invoiceSql = "INSERT INTO invoice_settings
                (business_id, branch_id, document_type, setting_name, paper_size, orientation,
                 show_business_logo, show_gstin, show_hsn, show_tax_breakup, show_customer_balance,
                 show_qr_code, header_text, footer_text, terms_conditions, prefix, middle_format,
                 suffix, splitter_symbol, sequence_digits, sequence_start, reset_frequency,
                 format_template, sample_output, is_default, is_active)
                VALUES (?, ?, 'Invoice', 'Default Invoice', 'A4', 'Portrait',
                        1, 1, 1, 1, 0, 0, 'TAX INVOICE', 'Thank you for your business.',
                        'Goods once sold will not be taken back.', ?, '{FY_SHORT}', '', ?, 3, 1,
                        'Financial Year', ?, ?, 1, 1)";
            $stmt = $conn->prepare($invoiceSql);
            if (!$stmt) {
                throw new RuntimeException('Unable to save invoice settings.');
            }
            $stmt->bind_param('iissss', $businessId, $branchId, $invoicePrefix, $invoiceSplitter, $form['invoice_format'], $sampleOutput);
            if (!$stmt->execute()) {
                throw new RuntimeException('Unable to save invoice settings: ' . $stmt->error);
            }
            $stmt->close();

            $stmt = $conn->prepare("INSERT INTO audit_logs
                (business_id, branch_id, user_id, module_code, action_type, reference_table, reference_id,
                 description, ip_address, user_agent)
                VALUES (?, ?, ?, 'setup.registration', 'Create', 'businesses', ?, ?, ?, ?)");
            if ($stmt) {
                $description = 'Business and first administrator registered through public setup page';
                $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
                $agent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
                $stmt->bind_param('iiiisss', $businessId, $branchId, $userId, $businessId, $description, $ip, $agent);
                $stmt->execute();
                $stmt->close();
            }

            $conn->commit();
            $_SESSION['public_register_csrf'] = bin2hex(random_bytes(32));
            $csrfToken = $_SESSION['public_register_csrf'];
            $success = 'Business, branch, administrator, role, permissions, theme, and invoice settings were created successfully.';

            foreach ($form as $key => $default) {
                $form[$key] = $key === 'business_type' ? 'Jewellery' : ($key === 'branch_code' ? 'HO' : ($key === 'branch_type' ? 'Head Office' : ($key === 'state' ? 'Tamil Nadu' : ($key === 'country' ? 'India' : ($key === 'invoice_prefix' ? 'INV' : ($key === 'invoice_splitter' ? '/' : ($key === 'invoice_format' ? '{PREFIX}{SPLITTER}{FY_SHORT}{SPLITTER}{SEQ}' : '')))))));
            }
        } catch (Throwable $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Register Business | Jewellery ERP</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        :root{--primary:#d89416;--primary-dark:#b86a0b;--soft:#fff6e5;--sidebar-1:#171c21;--sidebar-2:#20272d;--sidebar-3:#101419;--page:#f4f3f0;--card:#fff;--text:#171717;--muted:#7d8794;--line:#e8e8e8}
        *{box-sizing:border-box} body{margin:0;background:var(--page);font-family:Inter,sans-serif;color:var(--text)}
        .page-shell{min-height:100vh;display:grid;grid-template-columns:340px 1fr}
        .side-panel{position:sticky;top:0;height:100vh;padding:38px 30px;color:#fff;background:linear-gradient(145deg,var(--sidebar-1),var(--sidebar-2) 52%,var(--sidebar-3));overflow:hidden}
        .side-panel:before,.side-panel:after{content:"";position:absolute;border:1px solid rgba(255,255,255,.08);border-radius:50%}.side-panel:before{width:330px;height:330px;right:-170px;top:-140px}.side-panel:after{width:260px;height:260px;left:-150px;bottom:-130px}
        .side-content{position:relative;z-index:2}.brand-mark{width:54px;height:54px;display:grid;place-items:center;border-radius:16px;background:linear-gradient(135deg,var(--primary),var(--primary-dark));font-size:24px;box-shadow:0 16px 40px rgba(216,148,22,.28)}
        .side-title{font-family:"Playfair Display",serif;font-size:34px;line-height:1.15;margin:40px 0 16px}.side-copy{color:rgba(255,255,255,.68);font-size:13px;line-height:1.8}
        .feature{display:flex;gap:10px;align-items:center;margin-top:16px;color:rgba(255,255,255,.82);font-size:12px}.feature i{color:#f3c66c}
        .content{padding:26px}.topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:18px}.topbar h1{font-family:"Playfair Display",serif;font-size:28px;margin:0}.topbar p{margin:4px 0 0;color:var(--muted);font-size:12px}
        .form-card{background:var(--card);border:1px solid var(--line);border-radius:18px;box-shadow:0 18px 55px rgba(24,31,40,.08);overflow:hidden}
        .section-head{display:flex;align-items:center;gap:12px;padding:17px 20px;border-bottom:1px solid var(--line);background:#fffdf9}.section-icon{width:36px;height:36px;display:grid;place-items:center;border-radius:11px;background:var(--soft);color:var(--primary-dark)}.section-title{font-size:14px;font-weight:700;margin:0}.section-sub{font-size:10px;color:var(--muted);margin-top:2px}
        .section-body{padding:20px}.form-label{font-size:10px;font-weight:700;color:#4b5563;margin-bottom:6px}.form-control,.form-select{height:42px;border:1px solid var(--line);border-radius:10px;font-size:12px;box-shadow:none}.form-control:focus,.form-select:focus{border-color:var(--primary);box-shadow:0 0 0 .2rem rgba(216,148,22,.12)} textarea.form-control{height:auto;min-height:80px}.theme-preview{height:54px;border-radius:10px;border:1px solid var(--line);background:linear-gradient(135deg,#171c21,#20272d 50%,#101419)}
        .submitbar{position:sticky;bottom:0;display:flex;align-items:center;justify-content:space-between;gap:14px;padding:15px 20px;border-top:1px solid var(--line);background:rgba(255,255,255,.94);backdrop-filter:blur(12px)}.submitbar small{color:var(--muted)}.btn-register{height:44px;border:0;border-radius:11px;padding:0 24px;background:linear-gradient(135deg,var(--primary),var(--primary-dark));color:#fff;font-size:12px;font-weight:700;box-shadow:0 12px 28px rgba(216,148,22,.24)}
        .alert{border:0;border-radius:12px;font-size:12px}
        @media(max-width:991.98px){.page-shell{grid-template-columns:1fr}.side-panel{position:relative;height:auto;min-height:250px}.content{padding:16px}.submitbar{flex-direction:column;align-items:stretch}.btn-register{width:100%}}
    </style>
</head>
<body>
<div class="page-shell">
    <aside class="side-panel">
        <div class="side-content">
            <div class="brand-mark"><i class="fa-solid fa-gem"></i></div>
            <h1 class="side-title">Register a new jewellery business.</h1>
            <p class="side-copy">This public setup page creates the business, first branch, administrator user, full-access role, permissions, theme, and invoice settings in one transaction.</p>
            <div class="feature"><i class="fa-solid fa-building"></i><span>Multi-business ready</span></div>
            <div class="feature"><i class="fa-solid fa-code-branch"></i><span>Default branch setup</span></div>
            <div class="feature"><i class="fa-solid fa-user-shield"></i><span>Automatic role permissions</span></div>
            <div class="feature"><i class="fa-solid fa-file-invoice"></i><span>Invoice format configured</span></div>
        </div>
    </aside>

    <main class="content">
        <div class="topbar">
            <div><h1>Business Registration</h1><p>No login is required to use this page.</p></div>
            <a href="login.php" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-arrow-left me-2"></i>Login</a>
        </div>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger"><i class="fa-solid fa-circle-exclamation me-2"></i><?php echo h($error); ?></div>
        <?php endif; ?>
        <?php if ($success !== ''): ?>
            <div class="alert alert-success"><i class="fa-solid fa-circle-check me-2"></i><?php echo h($success); ?></div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
            <div class="form-card">
                <div class="section-head"><div class="section-icon"><i class="fa-solid fa-building"></i></div><div><h2 class="section-title">Business Details</h2><div class="section-sub">Primary company information</div></div></div>
                <div class="section-body">
                    <div class="row g-3">
                        <div class="col-md-3"><label class="form-label">Business Code *</label><input class="form-control text-uppercase" name="business_code" value="<?php echo h($form['business_code']); ?>" required></div>
                        <div class="col-md-5"><label class="form-label">Business Name *</label><input class="form-control" name="business_name" value="<?php echo h($form['business_name']); ?>" required></div>
                        <div class="col-md-4"><label class="form-label">Legal Name</label><input class="form-control" name="legal_name" value="<?php echo h($form['legal_name']); ?>"></div>
                        <div class="col-md-3"><label class="form-label">Business Type</label><input class="form-control" name="business_type" value="<?php echo h($form['business_type']); ?>"></div>
                        <div class="col-md-3"><label class="form-label">Owner Name</label><input class="form-control" name="owner_name" value="<?php echo h($form['owner_name']); ?>"></div>
                        <div class="col-md-3"><label class="form-label">Mobile</label><input class="form-control" name="mobile" value="<?php echo h($form['mobile']); ?>"></div>
                        <div class="col-md-3"><label class="form-label">WhatsApp</label><input class="form-control" name="whatsapp" value="<?php echo h($form['whatsapp']); ?>"></div>
                        <div class="col-md-4"><label class="form-label">Email</label><input type="email" class="form-control" name="email" value="<?php echo h($form['email']); ?>"></div>
                        <div class="col-md-4"><label class="form-label">Website</label><input class="form-control" name="website" value="<?php echo h($form['website']); ?>"></div>
                        <div class="col-md-2"><label class="form-label">GSTIN</label><input class="form-control text-uppercase" name="gstin" value="<?php echo h($form['gstin']); ?>"></div>
                        <div class="col-md-2"><label class="form-label">PAN</label><input class="form-control text-uppercase" name="pan_no" value="<?php echo h($form['pan_no']); ?>"></div>
                        <div class="col-md-4"><label class="form-label">Subscription Plan</label><select class="form-select" name="subscription_plan_id"><option value="">No plan</option><?php foreach ($plans as $plan): ?><option value="<?php echo (int)$plan['id']; ?>" <?php echo (string)$form['subscription_plan_id'] === (string)$plan['id'] ? 'selected' : ''; ?>><?php echo h($plan['plan_name']); ?> — <?php echo (int)$plan['max_branches']; ?> branches / <?php echo (int)$plan['max_users']; ?> users</option><?php endforeach; ?></select></div>
                    </div>
                </div>

                <div class="section-head"><div class="section-icon"><i class="fa-solid fa-code-branch"></i></div><div><h2 class="section-title">Default Branch</h2><div class="section-sub">The first active branch for this business</div></div></div>
                <div class="section-body">
                    <div class="row g-3">
                        <div class="col-md-2"><label class="form-label">Branch Code *</label><input class="form-control text-uppercase" name="branch_code" value="<?php echo h($form['branch_code']); ?>" required></div>
                        <div class="col-md-4"><label class="form-label">Branch Name *</label><input class="form-control" name="branch_name" value="<?php echo h($form['branch_name']); ?>" required></div>
                        <div class="col-md-3"><label class="form-label">Branch Type</label><select class="form-select" name="branch_type"><?php foreach (['Head Office','Showroom','Warehouse','Office','Other'] as $type): ?><option <?php echo $form['branch_type'] === $type ? 'selected' : ''; ?>><?php echo h($type); ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-3"><label class="form-label">Branch GSTIN</label><input class="form-control text-uppercase" name="branch_gstin" value="<?php echo h($form['branch_gstin']); ?>"></div>
                        <div class="col-md-3"><label class="form-label">Mobile</label><input class="form-control" name="branch_mobile" value="<?php echo h($form['branch_mobile']); ?>"></div>
                        <div class="col-md-3"><label class="form-label">Email</label><input type="email" class="form-control" name="branch_email" value="<?php echo h($form['branch_email']); ?>"></div>
                        <div class="col-md-3"><label class="form-label">Address Line 1</label><input class="form-control" name="address_line1" value="<?php echo h($form['address_line1']); ?>"></div>
                        <div class="col-md-3"><label class="form-label">Address Line 2</label><input class="form-control" name="address_line2" value="<?php echo h($form['address_line2']); ?>"></div>
                        <div class="col-md-3"><label class="form-label">City</label><input class="form-control" name="city" value="<?php echo h($form['city']); ?>"></div>
                        <div class="col-md-3"><label class="form-label">District</label><input class="form-control" name="district" value="<?php echo h($form['district']); ?>"></div>
                        <div class="col-md-2"><label class="form-label">State</label><input class="form-control" name="state" value="<?php echo h($form['state']); ?>"></div>
                        <div class="col-md-2"><label class="form-label">Pincode</label><input class="form-control" name="pincode" value="<?php echo h($form['pincode']); ?>"></div>
                        <div class="col-md-2"><label class="form-label">Country</label><input class="form-control" name="country" value="<?php echo h($form['country']); ?>"></div>
                    </div>
                </div>

                <div class="section-head"><div class="section-icon"><i class="fa-solid fa-user-shield"></i></div><div><h2 class="section-title">First Administrator</h2><div class="section-sub">Creates a Business Admin role with full permissions</div></div></div>
                <div class="section-body">
                    <div class="row g-3">
                        <div class="col-md-4"><label class="form-label">Full Name *</label><input class="form-control" name="full_name" value="<?php echo h($form['full_name']); ?>" required></div>
                        <div class="col-md-2"><label class="form-label">Employee Code</label><input class="form-control text-uppercase" name="employee_code" value="<?php echo h($form['employee_code']); ?>"></div>
                        <div class="col-md-3"><label class="form-label">Username *</label><input class="form-control" name="username" value="<?php echo h($form['username']); ?>" required></div>
                        <div class="col-md-3"><label class="form-label">Mobile</label><input class="form-control" name="user_mobile" value="<?php echo h($form['user_mobile']); ?>"></div>
                        <div class="col-md-4"><label class="form-label">Email</label><input type="email" class="form-control" name="user_email" value="<?php echo h($form['user_email']); ?>"></div>
                        <div class="col-md-4"><label class="form-label">Password *</label><input type="password" class="form-control" name="password" required></div>
                        <div class="col-md-4"><label class="form-label">Confirm Password *</label><input type="password" class="form-control" name="confirm_password" required></div>
                    </div>
                </div>

                <div class="section-head"><div class="section-icon"><i class="fa-solid fa-palette"></i></div><div><h2 class="section-title">Theme and Invoice</h2><div class="section-sub">Default appearance and invoice numbering</div></div></div>
                <div class="section-body">
                    <div class="row g-3">
                        <div class="col-md-4"><label class="form-label">Theme Preset</label><select class="form-select" name="theme_preset_id" id="themePreset"><option value="">Default Gold</option><?php foreach ($themes as $theme): ?><option value="<?php echo (int)$theme['id']; ?>" data-g1="<?php echo h($theme['sidebar_gradient_1']); ?>" data-g2="<?php echo h($theme['sidebar_gradient_2']); ?>" data-g3="<?php echo h($theme['sidebar_gradient_3']); ?>" <?php echo (string)$form['theme_preset_id'] === (string)$theme['id'] ? 'selected' : ''; ?>><?php echo h($theme['preset_name']); ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-4"><label class="form-label">Business Logo</label><input type="file" class="form-control" name="business_logo" accept=".png,.jpg,.jpeg,.webp,.svg"></div>
                        <div class="col-md-4"><label class="form-label">Sidebar Preview</label><div class="theme-preview" id="themePreview"></div></div>
                        <div class="col-md-2"><label class="form-label">Invoice Prefix</label><input class="form-control text-uppercase" name="invoice_prefix" value="<?php echo h($form['invoice_prefix']); ?>"></div>
                        <div class="col-md-2"><label class="form-label">Splitter</label><input class="form-control" name="invoice_splitter" value="<?php echo h($form['invoice_splitter']); ?>" maxlength="5"></div>
                        <div class="col-md-8"><label class="form-label">Invoice Format Template</label><input class="form-control" name="invoice_format" value="<?php echo h($form['invoice_format']); ?>"><div class="form-text">Tokens: {PREFIX}, {SPLITTER}, {FY_SHORT}, {FY_2DIGIT}, {YYYY}, {SEQ}</div></div>
                    </div>
                </div>

                <div class="submitbar">
                    <small>This page is intentionally public and does not require login.</small>
                    <button class="btn-register" type="submit"><i class="fa-solid fa-plus me-2"></i>Create Business and Administrator</button>
                </div>
            </div>
        </form>
    </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const preset = document.getElementById('themePreset');
const preview = document.getElementById('themePreview');
function updatePreview(){
    const option = preset.options[preset.selectedIndex];
    const g1 = option.dataset.g1 || '#171c21';
    const g2 = option.dataset.g2 || '#20272d';
    const g3 = option.dataset.g3 || '#101419';
    preview.style.background = `linear-gradient(135deg, ${g1}, ${g2} 50%, ${g3})`;
}
preset.addEventListener('change', updatePreview);
updatePreview();
</script>
</body>
</html>
