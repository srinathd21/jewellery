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

$pageTitle = 'Invoice Settings';

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
if (!tableExists($conn, 'company_settings') || !tableExists($conn, 'businesses')) {
    die('Required tables not found. Please import the SQL first.');
}

/* -------------------------------------------------------
   FETCH CURRENT SETTINGS
------------------------------------------------------- */
$stmt = $conn->prepare("
    SELECT 
        b.business_name,
        cs.id AS settings_id,
        cs.company_name,
        cs.invoice_prefix,
        cs.estimate_prefix,
        cs.return_prefix,
        cs.currency_symbol,
        cs.timezone,
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
    die('Invoice settings not found.');
}

$success = '';
$error = '';

$settingsId = (int)($data['settings_id'] ?? 0);
$companyName = (string)($data['company_name'] ?? $data['business_name'] ?? '');
$businessName = (string)($data['business_name'] ?? '');
$invoicePrefix = (string)($data['invoice_prefix'] ?? 'INV');
$estimatePrefix = (string)($data['estimate_prefix'] ?? 'EST');
$returnPrefix = (string)($data['return_prefix'] ?? 'RET');
$currencySymbol = (string)($data['currency_symbol'] ?? '₹');
$timezone = (string)($data['timezone'] ?? 'Asia/Kolkata');
$billFooter = (string)($data['bill_footer'] ?? '');
$termsConditions = (string)($data['terms_conditions'] ?? '');

/* -------------------------------------------------------
   SAVE SETTINGS
------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $companyName = trim((string)($_POST['company_name'] ?? ''));
    $invoicePrefix = strtoupper(trim((string)($_POST['invoice_prefix'] ?? 'INV')));
    $estimatePrefix = strtoupper(trim((string)($_POST['estimate_prefix'] ?? 'EST')));
    $returnPrefix = strtoupper(trim((string)($_POST['return_prefix'] ?? 'RET')));
    $currencySymbol = trim((string)($_POST['currency_symbol'] ?? '₹'));
    $timezone = trim((string)($_POST['timezone'] ?? 'Asia/Kolkata'));
    $billFooter = trim((string)($_POST['bill_footer'] ?? ''));
    $termsConditions = trim((string)($_POST['terms_conditions'] ?? ''));

    if ($companyName === '') {
        $error = 'Company name is required.';
    } elseif ($invoicePrefix === '') {
        $error = 'Invoice prefix is required.';
    } elseif ($estimatePrefix === '') {
        $error = 'Estimate prefix is required.';
    } elseif ($returnPrefix === '') {
        $error = 'Return prefix is required.';
    } else {
        $conn->begin_transaction();

        try {
            if ($settingsId > 0) {
                $stmt = $conn->prepare("
                    UPDATE company_settings
                    SET
                        company_name = ?,
                        invoice_prefix = ?,
                        estimate_prefix = ?,
                        return_prefix = ?,
                        currency_symbol = ?,
                        timezone = ?,
                        bill_footer = ?,
                        terms_conditions = ?,
                        updated_at = NOW()
                    WHERE id = ?
                    LIMIT 1
                ");
                if (!$stmt) {
                    throw new Exception('Failed to prepare invoice settings update.');
                }

                $stmt->bind_param(
                    'ssssssssi',
                    $companyName,
                    $invoicePrefix,
                    $estimatePrefix,
                    $returnPrefix,
                    $currencySymbol,
                    $timezone,
                    $billFooter,
                    $termsConditions,
                    $settingsId
                );

                if (!$stmt->execute()) {
                    throw new Exception('Failed to update invoice settings.');
                }
                $stmt->close();
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO company_settings (
                        business_id,
                        company_name,
                        business_type,
                        invoice_prefix,
                        estimate_prefix,
                        return_prefix,
                        currency_symbol,
                        timezone,
                        bill_footer,
                        terms_conditions,
                        created_at,
                        updated_at
                    ) VALUES (
                        ?, ?, 'General Business', ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()
                    )
                ");
                if (!$stmt) {
                    throw new Exception('Failed to prepare invoice settings insert.');
                }

                $stmt->bind_param(
                    'isssssss',
                    $businessId,
                    $companyName,
                    $invoicePrefix,
                    $estimatePrefix,
                    $returnPrefix,
                    $currencySymbol,
                    $timezone,
                    $billFooter,
                    $termsConditions
                );

                if (!$stmt->execute()) {
                    throw new Exception('Failed to insert invoice settings.');
                }
                $stmt->close();
            }

            if (function_exists('addAuditLog')) {
                addAuditLog(
                    $conn,
                    $businessId,
                    $userId,
                    'Invoice Settings',
                    'Update',
                    $businessId,
                    'Invoice settings updated'
                );
            }

            $conn->commit();
            $success = 'Invoice settings updated successfully.';
        } catch (Exception $e) {
            $conn->rollback();
            $error = $e->getMessage();
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

                <form method="post">
                    <div class="row">
                        <div class="col-xl-8">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title mb-4">Invoice Configuration</h4>

                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Business Name</label>
                                            <input type="text" class="form-control" value="<?php echo h($businessName); ?>" readonly>
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Company Name <span class="text-danger">*</span></label>
                                            <input type="text" name="company_name" class="form-control" value="<?php echo h($companyName); ?>" required>
                                        </div>

                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Invoice Prefix <span class="text-danger">*</span></label>
                                            <input type="text" name="invoice_prefix" class="form-control" value="<?php echo h($invoicePrefix); ?>" required>
                                        </div>

                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Estimate Prefix <span class="text-danger">*</span></label>
                                            <input type="text" name="estimate_prefix" class="form-control" value="<?php echo h($estimatePrefix); ?>" required>
                                        </div>

                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Return Prefix <span class="text-danger">*</span></label>
                                            <input type="text" name="return_prefix" class="form-control" value="<?php echo h($returnPrefix); ?>" required>
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Currency Symbol</label>
                                            <input type="text" name="currency_symbol" class="form-control" value="<?php echo h($currencySymbol); ?>">
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
                                        <textarea name="bill_footer" class="form-control" rows="4"><?php echo h($billFooter); ?></textarea>
                                    </div>

                                    <div class="mb-0">
                                        <label class="form-label">Terms & Conditions</label>
                                        <textarea name="terms_conditions" class="form-control" rows="6"><?php echo h($termsConditions); ?></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-4">
                            <div class="card">
                                <div class="card-body">
                                    <h4 class="card-title mb-4">Actions</h4>

                                    <div class="d-grid gap-2">
                                        <button type="submit" class="btn btn-primary waves-effect waves-light">
                                            Save Invoice Settings
                                        </button>

                                        <a href="company-settings.php" class="btn btn-secondary waves-effect">
                                            Back to Company Settings
                                        </a>
                                    </div>

                                    <hr>

                                    <h5 class="mb-3">Notes</h5>
                                    <ul class="mb-0 ps-3">
                                        <li>Prefixes are used while generating bill numbers.</li>
                                        <li>Footer appears at bottom of invoice print.</li>
                                        <li>Terms & conditions can be printed in invoice.</li>
                                        <li>Currency symbol is shown in billing screens and print.</li>
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