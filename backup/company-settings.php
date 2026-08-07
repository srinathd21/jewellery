<?php
session_start();
date_default_timezone_set('Asia/Kolkata');

require_once 'includes/config.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not available. Check includes/config.php');
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn->set_charset('utf8mb4');

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

$pageTitle = 'Company Settings';

/* -------------------------------------------------------
   AUTH CHECK
------------------------------------------------------- */
if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    header('Location: ../login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$businessId = (int)($_SESSION['business_id'] ?? 0);

if ($businessId <= 0) {
    die('Business session not found. Please login again.');
}

$stmt = $conn->prepare("
    SELECT r.role_name
    FROM users u
    INNER JOIN roles r ON r.id = u.role_id
    WHERE u.id = ?
    LIMIT 1
");
$stmt->bind_param('i', $userId);
$stmt->execute();
$res = $stmt->get_result();
$userRow = $res ? $res->fetch_assoc() : null;
$stmt->close();

$roleName = strtolower(trim((string)($userRow['role_name'] ?? '')));
if (!in_array($roleName, ['admin', 'manager'], true)) {
    die('Access denied.');
}

/* -------------------------------------------------------
   REQUIRED TABLES
------------------------------------------------------- */
if (!tableExists($conn, 'businesses') || !tableExists($conn, 'company_settings')) {
    die('Required tables not found. Please import the SQL first.');
}

/* -------------------------------------------------------
   HELPERS
------------------------------------------------------- */
function uploadCompanyLogo(string $fieldName, string $uploadDir = 'uploads/company/'): array
{
    if (!isset($_FILES[$fieldName]) || !is_array($_FILES[$fieldName])) {
        return ['ok' => false, 'path' => '', 'error' => 'No file uploaded.'];
    }

    $file = $_FILES[$fieldName];

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'path' => '', 'error' => 'No file uploaded.'];
    }

    if (($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'path' => '', 'error' => 'File upload failed.'];
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif'
    ];

    $tmp = $file['tmp_name'] ?? '';
    $mime = function_exists('mime_content_type') ? mime_content_type($tmp) : '';
    if (!isset($allowed[$mime])) {
        return ['ok' => false, 'path' => '', 'error' => 'Only JPG, PNG, WEBP, GIF allowed.'];
    }

    if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
        return ['ok' => false, 'path' => '', 'error' => 'Logo size must be below 2MB.'];
    }

    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0777, true);
    }

    $ext = $allowed[$mime];
    $name = 'logo_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $target = rtrim($uploadDir, '/') . '/' . $name;

    if (!move_uploaded_file($tmp, $target)) {
        return ['ok' => false, 'path' => '', 'error' => 'Unable to save uploaded logo.'];
    }

    return ['ok' => true, 'path' => $target, 'error' => ''];
}

/* -------------------------------------------------------
   FETCH CURRENT SETTINGS
------------------------------------------------------- */
$stmt = $conn->prepare("
    SELECT 
        b.id AS business_id,
        b.business_code,
        b.business_name,
        b.business_type,
        b.owner_name,
        b.mobile,
        b.whatsapp,
        b.email,
        b.address_line1,
        b.address_line2,
        b.city,
        b.state,
        b.pincode,
        b.country,
        b.gstin,
        b.pan_no,
        b.is_active,
        cs.id AS settings_id,
        cs.company_name,
        cs.invoice_prefix,
        cs.estimate_prefix,
        cs.return_prefix,
        cs.currency_symbol,
        cs.timezone,
        cs.logo_path,
        cs.bill_footer,
        cs.terms_conditions
    FROM businesses b
    LEFT JOIN company_settings cs ON cs.business_id = b.id
    WHERE b.id = ?
    LIMIT 1
");
$stmt->bind_param('i', $businessId);
$stmt->execute();
$res = $stmt->get_result();
$data = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$data) {
    die('Business settings not found.');
}

/* -------------------------------------------------------
   DEFAULT VALUES
------------------------------------------------------- */
$success = '';
$error = '';

$company_name     = (string)($data['company_name'] ?? $data['business_name'] ?? '');
$business_name    = (string)($data['business_name'] ?? '');
$business_code    = (string)($data['business_code'] ?? '');
$business_type    = (string)($data['business_type'] ?? '');
$owner_name       = (string)($data['owner_name'] ?? '');
$mobile           = (string)($data['mobile'] ?? '');
$whatsapp         = (string)($data['whatsapp'] ?? '');
$email            = (string)($data['email'] ?? '');
$address_line1    = (string)($data['address_line1'] ?? '');
$address_line2    = (string)($data['address_line2'] ?? '');
$city             = (string)($data['city'] ?? '');
$state            = (string)($data['state'] ?? '');
$pincode          = (string)($data['pincode'] ?? '');
$country          = (string)($data['country'] ?? 'India');
$gstin            = (string)($data['gstin'] ?? '');
$pan_no           = (string)($data['pan_no'] ?? '');
$invoice_prefix   = (string)($data['invoice_prefix'] ?? 'INV');
$estimate_prefix  = (string)($data['estimate_prefix'] ?? 'EST');
$return_prefix    = (string)($data['return_prefix'] ?? 'RET');
$currency_symbol  = (string)($data['currency_symbol'] ?? '₹');
$timezone         = (string)($data['timezone'] ?? 'Asia/Kolkata');
$logo_path        = (string)($data['logo_path'] ?? '');
$bill_footer      = (string)($data['bill_footer'] ?? '');
$terms_conditions = (string)($data['terms_conditions'] ?? '');

/* -------------------------------------------------------
   SAVE
------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $company_name     = trim((string)($_POST['company_name'] ?? ''));
    $business_name    = trim((string)($_POST['business_name'] ?? ''));
    $business_type    = trim((string)($_POST['business_type'] ?? ''));
    $owner_name       = trim((string)($_POST['owner_name'] ?? ''));
    $mobile           = trim((string)($_POST['mobile'] ?? ''));
    $whatsapp         = trim((string)($_POST['whatsapp'] ?? ''));
    $email            = trim((string)($_POST['email'] ?? ''));
    $address_line1    = trim((string)($_POST['address_line1'] ?? ''));
    $address_line2    = trim((string)($_POST['address_line2'] ?? ''));
    $city             = trim((string)($_POST['city'] ?? ''));
    $state            = trim((string)($_POST['state'] ?? ''));
    $pincode          = trim((string)($_POST['pincode'] ?? ''));
    $country          = trim((string)($_POST['country'] ?? 'India'));
    $gstin            = strtoupper(trim((string)($_POST['gstin'] ?? '')));
    $pan_no           = strtoupper(trim((string)($_POST['pan_no'] ?? '')));
    $invoice_prefix   = strtoupper(trim((string)($_POST['invoice_prefix'] ?? 'INV')));
    $estimate_prefix  = strtoupper(trim((string)($_POST['estimate_prefix'] ?? 'EST')));
    $return_prefix    = strtoupper(trim((string)($_POST['return_prefix'] ?? 'RET')));
    $currency_symbol  = trim((string)($_POST['currency_symbol'] ?? '₹'));
    $timezone         = trim((string)($_POST['timezone'] ?? 'Asia/Kolkata'));
    $bill_footer      = trim((string)($_POST['bill_footer'] ?? ''));
    $terms_conditions = trim((string)($_POST['terms_conditions'] ?? ''));

    if ($company_name === '') {
        $error = 'Company name is required.';
    } elseif ($business_name === '') {
        $error = 'Business name is required.';
    } else {
        $newLogoPath = $logo_path;

        if (isset($_FILES['logo']) && (int)($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $upload = uploadCompanyLogo('logo');
            if (!$upload['ok']) {
                $error = $upload['error'];
            } else {
                $newLogoPath = $upload['path'];
            }
        }

        if ($error === '') {
            $conn->begin_transaction();

            try {
                $stmt = $conn->prepare("
                    UPDATE businesses
                    SET
                        business_name = ?,
                        business_type = ?,
                        owner_name = ?,
                        mobile = ?,
                        whatsapp = ?,
                        email = ?,
                        address_line1 = ?,
                        address_line2 = ?,
                        city = ?,
                        state = ?,
                        pincode = ?,
                        country = ?,
                        gstin = ?,
                        pan_no = ?,
                        updated_at = NOW()
                    WHERE id = ?
                    LIMIT 1
                ");
                if (!$stmt) {
                    throw new Exception('Failed to prepare business update.');
                }

                $stmt->bind_param(
                    'ssssssssssssssi',
                    $business_name,
                    $business_type,
                    $owner_name,
                    $mobile,
                    $whatsapp,
                    $email,
                    $address_line1,
                    $address_line2,
                    $city,
                    $state,
                    $pincode,
                    $country,
                    $gstin,
                    $pan_no,
                    $businessId
                );

                if (!$stmt->execute()) {
                    throw new Exception('Failed to update business.');
                }
                $stmt->close();

                $stmt = $conn->prepare("
                    SELECT id
                    FROM company_settings
                    WHERE business_id = ?
                    LIMIT 1
                ");
                $stmt->bind_param('i', $businessId);
                $stmt->execute();
                $res = $stmt->get_result();
                $settingsRow = $res ? $res->fetch_assoc() : null;
                $stmt->close();

                if ($settingsRow) {
                    $settingsId = (int)$settingsRow['id'];

                    $stmt = $conn->prepare("
                        UPDATE company_settings
                        SET
                            company_name = ?,
                            business_type = ?,
                            owner_name = ?,
                            mobile = ?,
                            whatsapp = ?,
                            email = ?,
                            address_line1 = ?,
                            address_line2 = ?,
                            city = ?,
                            state = ?,
                            pincode = ?,
                            country = ?,
                            gstin = ?,
                            pan_no = ?,
                            invoice_prefix = ?,
                            estimate_prefix = ?,
                            return_prefix = ?,
                            currency_symbol = ?,
                            timezone = ?,
                            logo_path = ?,
                            bill_footer = ?,
                            terms_conditions = ?,
                            updated_at = NOW()
                        WHERE id = ?
                        LIMIT 1
                    ");
                    if (!$stmt) {
                        throw new Exception('Failed to prepare company settings update.');
                    }

                    $stmt->bind_param(
                        'ssssssssssssssssssssssi',
                        $company_name,
                        $business_type,
                        $owner_name,
                        $mobile,
                        $whatsapp,
                        $email,
                        $address_line1,
                        $address_line2,
                        $city,
                        $state,
                        $pincode,
                        $country,
                        $gstin,
                        $pan_no,
                        $invoice_prefix,
                        $estimate_prefix,
                        $return_prefix,
                        $currency_symbol,
                        $timezone,
                        $newLogoPath,
                        $bill_footer,
                        $terms_conditions,
                        $settingsId
                    );

                    if (!$stmt->execute()) {
                        throw new Exception('Failed to update company settings.');
                    }
                    $stmt->close();
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO company_settings (
                            business_id,
                            company_name,
                            business_type,
                            owner_name,
                            mobile,
                            whatsapp,
                            email,
                            address_line1,
                            address_line2,
                            city,
                            state,
                            pincode,
                            country,
                            gstin,
                            pan_no,
                            invoice_prefix,
                            estimate_prefix,
                            return_prefix,
                            currency_symbol,
                            timezone,
                            logo_path,
                            bill_footer,
                            terms_conditions,
                            created_at,
                            updated_at
                        ) VALUES (
                            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()
                        )
                    ");
                    if (!$stmt) {
                        throw new Exception('Failed to prepare company settings insert.');
                    }

                    $stmt->bind_param(
                        'issssssssssssssssssssss',
                        $businessId,
                        $company_name,
                        $business_type,
                        $owner_name,
                        $mobile,
                        $whatsapp,
                        $email,
                        $address_line1,
                        $address_line2,
                        $city,
                        $state,
                        $pincode,
                        $country,
                        $gstin,
                        $pan_no,
                        $invoice_prefix,
                        $estimate_prefix,
                        $return_prefix,
                        $currency_symbol,
                        $timezone,
                        $newLogoPath,
                        $bill_footer,
                        $terms_conditions
                    );

                    if (!$stmt->execute()) {
                        throw new Exception('Failed to insert company settings.');
                    }
                    $stmt->close();
                }

                if (function_exists('addAuditLog')) {
                    addAuditLog(
                        $conn,
                        $businessId,
                        $userId,
                        'Company Settings',
                        'Update',
                        $businessId,
                        'Company settings updated'
                    );
                }

                $conn->commit();

                $_SESSION['business_name'] = $business_name;
                $logo_path = $newLogoPath;
                $success = 'Company settings updated successfully.';
            } catch (Exception $e) {
                $conn->rollback();
                $error = $e->getMessage();
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">

<?php include('includes/head.php'); ?>

<body data-sidebar="dark">

<?php include('includes/pre-loader.php'); ?>

<div id="layout-wrapper">

    <?php include('includes/topbar.php'); ?>

    <div class="vertical-menu">
        <div data-simplebar class="h-100">
            <?php include('includes/sidebar.php'); ?>
        </div>
    </div>

    <div class="main-content">
        <div class="page-content">
            <div class="container-fluid">

                <?php if ($success !== ''): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo h($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo h($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <form method="post" enctype="multipart/form-data">
                    <div class="row">
                        <div class="col-xl-8">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title mb-4">Business Information</h4>

                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Company Name <span class="text-danger">*</span></label>
                                            <input type="text" name="company_name" class="form-control" value="<?php echo h($company_name); ?>" required>
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Business Name <span class="text-danger">*</span></label>
                                            <input type="text" name="business_name" class="form-control" value="<?php echo h($business_name); ?>" required>
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Business Code</label>
                                            <input type="text" class="form-control" value="<?php echo h($business_code); ?>" readonly>
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Business Type</label>
                                            <input type="text" name="business_type" class="form-control" value="<?php echo h($business_type); ?>">
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Owner Name</label>
                                            <input type="text" name="owner_name" class="form-control" value="<?php echo h($owner_name); ?>">
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Mobile</label>
                                            <input type="text" name="mobile" class="form-control" value="<?php echo h($mobile); ?>">
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">WhatsApp</label>
                                            <input type="text" name="whatsapp" class="form-control" value="<?php echo h($whatsapp); ?>">
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Email</label>
                                            <input type="email" name="email" class="form-control" value="<?php echo h($email); ?>">
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">GSTIN</label>
                                            <input type="text" name="gstin" class="form-control" value="<?php echo h($gstin); ?>">
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">PAN No</label>
                                            <input type="text" name="pan_no" class="form-control" value="<?php echo h($pan_no); ?>">
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Country</label>
                                            <input type="text" name="country" class="form-control" value="<?php echo h($country); ?>">
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">State</label>
                                            <input type="text" name="state" class="form-control" value="<?php echo h($state); ?>">
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">City</label>
                                            <input type="text" name="city" class="form-control" value="<?php echo h($city); ?>">
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Pincode</label>
                                            <input type="text" name="pincode" class="form-control" value="<?php echo h($pincode); ?>">
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Address Line 1</label>
                                            <input type="text" name="address_line1" class="form-control" value="<?php echo h($address_line1); ?>">
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Address Line 2</label>
                                            <input type="text" name="address_line2" class="form-control" value="<?php echo h($address_line2); ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title mb-4">Invoice Settings</h4>

                                    <div class="row">
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Invoice Prefix</label>
                                            <input type="text" name="invoice_prefix" class="form-control" value="<?php echo h($invoice_prefix); ?>">
                                        </div>

                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Estimate Prefix</label>
                                            <input type="text" name="estimate_prefix" class="form-control" value="<?php echo h($estimate_prefix); ?>">
                                        </div>

                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Return Prefix</label>
                                            <input type="text" name="return_prefix" class="form-control" value="<?php echo h($return_prefix); ?>">
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Currency Symbol</label>
                                            <input type="text" name="currency_symbol" class="form-control" value="<?php echo h($currency_symbol); ?>">
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Timezone</label>
                                            <input type="text" name="timezone" class="form-control" value="<?php echo h($timezone); ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title mb-4">Footer & Terms</h4>

                                    <div class="mb-3">
                                        <label class="form-label">Bill Footer</label>
                                        <textarea name="bill_footer" class="form-control" rows="4"><?php echo h($bill_footer); ?></textarea>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Terms & Conditions</label>
                                        <textarea name="terms_conditions" class="form-control" rows="6"><?php echo h($terms_conditions); ?></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-4">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title mb-4">Company Logo</h4>

                                    <?php if ($logo_path !== '' && file_exists($logo_path)): ?>
                                        <div class="mb-3 text-center">
                                            <img src="<?php echo h($logo_path); ?>" alt="Logo" class="img-fluid rounded border" style="max-height: 150px;">
                                        </div>
                                    <?php elseif ($logo_path !== ''): ?>
                                        <div class="mb-3 text-center">
                                            <img src="<?php echo h($logo_path); ?>" alt="Logo" class="img-fluid rounded border" style="max-height: 150px;">
                                        </div>
                                    <?php else: ?>
                                        <div class="alert alert-light text-center mb-3">
                                            No logo uploaded
                                        </div>
                                    <?php endif; ?>

                                    <div class="mb-3">
                                        <label class="form-label">Upload Logo</label>
                                        <input type="file" name="logo" class="form-control" accept=".jpg,.jpeg,.png,.webp,.gif">
                                        <small class="text-muted">Allowed: JPG, PNG, WEBP, GIF. Max 2MB.</small>
                                    </div>

                                    <div class="d-grid">
                                        <button type="submit" class="btn btn-primary waves-effect waves-light">
                                            Save Settings
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div class="card">
                                <div class="card-body">
                                    <h5 class="mb-3">Information</h5>
                                    <ul class="mb-0 ps-3">
                                        <li>Business code is read-only.</li>
                                        <li>Business name updates topbar automatically after save.</li>
                                        <li>Invoice prefixes are used in bill number generation.</li>
                                        <li>Logo is optional.</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>

            </div>
        </div>

        <?php include('includes/footer.php'); ?>
    </div>
</div>

<?php include('includes/rightbar.php'); ?>
<?php include('includes/scripts.php'); ?>

</body>
</html>