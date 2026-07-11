<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/includes/config.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not available. Check includes/config.php');
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn->set_charset('utf8mb4');

$pageTitle = 'Add Customer';
$page_title = 'Add Customer';
$currentPage = 'customer-add';

$businessId = (int)($_SESSION['business_id'] ?? 1);
$userId     = (int)($_SESSION['user_id'] ?? 0);

if (!function_exists('h')) {
    function h($value): string {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('tableExists')) {
    function tableExists(mysqli $conn, string $tableName): bool {
        $safe = $conn->real_escape_string($tableName);
        $res = $conn->query("SHOW TABLES LIKE '{$safe}'");
        return $res && $res->num_rows > 0;
    }
}

if (!function_exists('getClientIp')) {
    function getClientIp(): string {
        return $_SERVER['HTTP_CLIENT_IP']
            ?? $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['REMOTE_ADDR']
            ?? '';
    }
}

if (!function_exists('logAudit')) {
    function logAudit(
        mysqli $conn,
        int $businessId,
        int $userId,
        string $moduleName,
        string $actionType,
        int $referenceId,
        string $description,
        ?string $customerType = null
    ): void {
        if (!tableExists($conn, 'audit_logs')) {
            return;
        }

        $ipAddress = getClientIp();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $sql = "INSERT INTO audit_logs
                (business_id, user_id, module_name, action_type, reference_id, customer_type, description, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param(
                'iississss',
                $businessId,
                $userId,
                $moduleName,
                $actionType,
                $referenceId,
                $customerType,
                $description,
                $ipAddress,
                $userAgent
            );
            $stmt->execute();
            $stmt->close();
        }
    }
}

/*
|--------------------------------------------------------------------------
| Customer Type From URL
|--------------------------------------------------------------------------
| URL type:
| billing => Billing
| pawn    => PawnBroking
| chit    => PawnChits
*/
$customerType = $_GET['type'] ?? ($_POST['page_customer_type'] ?? 'billing');
$validTypes = ['billing', 'pawn', 'chit'];

if (!in_array($customerType, $validTypes, true)) {
    $customerType = 'billing';
}

function mapCustomerTypeToDb(string $type): string {
    switch ($type) {
        case 'pawn':
            return 'PawnBroking';
        case 'chit':
            return 'PawnChits';
        case 'billing':
        default:
            return 'Billing';
    }
}

function getCustomerTypeLabel(string $type): string {
    switch ($type) {
        case 'pawn':
            return 'Pawn';
        case 'chit':
            return 'Chit';
        case 'billing':
        default:
            return 'Billing';
    }
}

function generateCustomerCode(mysqli $conn, int $businessId, string $type): string {
    switch ($type) {
        case 'pawn':
            $prefix = 'PWN';
            break;
        case 'chit':
            $prefix = 'CHC';
            break;
        case 'billing':
        default:
            $prefix = 'CUST';
            break;
    }

    $yearMonth = date('ym');
    $like = $prefix . $yearMonth . '%';

    $sql = "SELECT customer_code 
            FROM customers 
            WHERE business_id = ? 
              AND customer_code LIKE ? 
            ORDER BY id DESC 
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    $lastNumber = 0;

    if ($stmt) {
        $stmt->bind_param('is', $businessId, $like);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res && ($row = $res->fetch_assoc())) {
            $lastNumber = (int)substr((string)$row['customer_code'], -4);
        }

        $stmt->close();
    }

    return $prefix . $yearMonth . str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
}

/*
|--------------------------------------------------------------------------
| Optional Dropdown Data
|--------------------------------------------------------------------------
| These tables may not exist in your uploaded DB.
| So safely load them only if available.
*/
$zones = [];
if (tableExists($conn, 'zones')) {
    $zoneQuery = "SELECT id, zone_name FROM zones WHERE status = 'active' ORDER BY zone_name";
    $zoneRes = $conn->query($zoneQuery);
    if ($zoneRes) {
        while ($row = $zoneRes->fetch_assoc()) {
            $zones[] = $row;
        }
    }
}

$linemen = [];
if (tableExists($conn, 'linemen')) {
    $linemanQuery = "SELECT id, full_name, assigned_area FROM linemen WHERE status = 'active' ORDER BY full_name";
    $linemanRes = $conn->query($linemanQuery);
    if ($linemanRes) {
        while ($row = $linemanRes->fetch_assoc()) {
            $linemen[] = $row;
        }
    }
}

/*
|--------------------------------------------------------------------------
| Form Submit
|--------------------------------------------------------------------------
*/
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_customer') {
    $customerType = $_POST['page_customer_type'] ?? $customerType;

    if (!in_array($customerType, $validTypes, true)) {
        $customerType = 'billing';
    }

    $customerTypeDb = mapCustomerTypeToDb($customerType);

    $customerCode      = generateCustomerCode($conn, $businessId, $customerType);
    $shopName          = trim($_POST['shop_name'] ?? '');
    $customerName      = trim($_POST['customer_name'] ?? '');
    $customerContact   = trim($_POST['customer_contact'] ?? '');
    $alternateContact  = trim($_POST['alternate_contact'] ?? '');
    $shopLocation      = trim($_POST['shop_location'] ?? '');
    $gstNumber         = trim($_POST['gst_number'] ?? '');
    $email             = trim($_POST['email'] ?? '');
    $customerCategory  = trim($_POST['customer_category'] ?? '');
    $assignedLinemanId = (int)($_POST['assigned_lineman_id'] ?? 0);
    $assignedArea      = trim($_POST['assigned_area'] ?? '');
    $paymentTerms      = trim($_POST['payment_terms'] ?? 'cash');
    $creditLimit       = trim($_POST['credit_limit'] ?? '0');
    $notes             = trim($_POST['notes'] ?? '');
    $zoneId            = (int)($_POST['zone_id'] ?? 0);

    $errors = [];

    if ($customerName === '') {
        $errors[] = 'Customer name is required';
    }

    if ($customerContact === '') {
        $errors[] = 'Contact number is required';
    }

    if ($shopLocation === '') {
        $errors[] = 'Shop location/address is required';
    }

    if ($customerContact !== '' && !preg_match('/^[0-9+\-\s]{6,20}$/', $customerContact)) {
        $errors[] = 'Enter a valid contact number';
    }

    /*
    |--------------------------------------------------------------------------
    | Duplicate Mobile Check
    |--------------------------------------------------------------------------
    | Correct DB column is mobile, not customer_contact.
    */
    if (empty($errors)) {
        $checkQuery = "SELECT id 
                       FROM customers 
                       WHERE business_id = ? 
                         AND mobile = ? 
                         AND is_active = 1 
                       LIMIT 1";

        $checkStmt = $conn->prepare($checkQuery);

        if ($checkStmt) {
            $checkStmt->bind_param('is', $businessId, $customerContact);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();

            if ($checkResult && $checkResult->num_rows > 0) {
                $errors[] = 'Customer with this contact number already exists!';
            }

            $checkStmt->close();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Store Extra UI Fields Inside Notes
    |--------------------------------------------------------------------------
    | Your current DB does not have these columns:
    | shop_name, payment_terms, credit_limit, zone_id, assigned_lineman_id, assigned_area.
    */
    $extraNotes = [];

    if ($shopName !== '') {
        $extraNotes[] = 'Shop/Business Name: ' . $shopName;
    }

    if ($customerCategory !== '') {
        $extraNotes[] = 'Customer Category: ' . $customerCategory;
    }

    if ($zoneId > 0) {
        $extraNotes[] = 'Zone ID: ' . $zoneId;
    }

    if ($assignedArea !== '') {
        $extraNotes[] = 'Assigned Area: ' . $assignedArea;
    }

    if ($assignedLinemanId > 0) {
        $extraNotes[] = 'Assigned Lineman ID: ' . $assignedLinemanId;
    }

    if ($paymentTerms !== '') {
        $extraNotes[] = 'Payment Terms: ' . $paymentTerms;
    }

    if ($creditLimit !== '') {
        $extraNotes[] = 'Credit Limit: ₹' . $creditLimit;
    }

    if ($notes !== '') {
        $extraNotes[] = 'Notes: ' . $notes;
    }

    $finalNotes = implode("\n", $extraNotes);

    if (empty($errors)) {
        $insertQuery = "INSERT INTO customers (
                            business_id,
                            customer_code,
                            customer_type,
                            customer_name,
                            mobile,
                            alternate_mobile,
                            email,
                            gstin,
                            address_line1,
                            opening_balance,
                            balance_type,
                            notes,
                            is_active
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0.00, 'Dr', ?, 1)";

        $stmt = $conn->prepare($insertQuery);

        if (!$stmt) {
            $message = 'Database prepare error: ' . $conn->error;
            $messageType = 'danger';
        } else {
            $stmt->bind_param(
                'isssssssss',
                $businessId,
                $customerCode,
                $customerTypeDb,
                $customerName,
                $customerContact,
                $alternateContact,
                $email,
                $gstNumber,
                $shopLocation,
                $finalNotes
            );

            if ($stmt->execute()) {
                $customerId = (int)$stmt->insert_id;

                logAudit(
                    $conn,
                    $businessId,
                    $userId,
                    'Customers',
                    'Create',
                    $customerId,
                    'Created customer ' . $customerName . ' with code ' . $customerCode,
                    $customerTypeDb
                );

                $message = 'Customer added successfully! Customer Code: ' . $customerCode;
                $messageType = 'success';

                echo "<script>
                    setTimeout(function() {
                        window.location.href = 'customers.php?type=" . h($customerType) . "';
                    }, 1500);
                </script>";
            } else {
                $message = 'Error: ' . $stmt->error;
                $messageType = 'danger';
            }

            $stmt->close();
        }
    } else {
        $message = implode('<br>', array_map('h', $errors));
        $messageType = 'danger';
    }
}

/*
|--------------------------------------------------------------------------
| Previous Form Values
|--------------------------------------------------------------------------
*/
$postShopName          = $_POST['shop_name'] ?? '';
$postCustomerName      = $_POST['customer_name'] ?? '';
$postCustomerContact   = $_POST['customer_contact'] ?? '';
$postAlternateContact  = $_POST['alternate_contact'] ?? '';
$postShopLocation      = $_POST['shop_location'] ?? '';
$postGstNumber         = $_POST['gst_number'] ?? '';
$postEmail             = $_POST['email'] ?? '';
$postCustomerCategory  = $_POST['customer_category'] ?? 'retail';
$postAssignedLinemanId = (int)($_POST['assigned_lineman_id'] ?? 0);
$postAssignedArea      = $_POST['assigned_area'] ?? '';
$postPaymentTerms      = $_POST['payment_terms'] ?? 'cash';
$postCreditLimit       = $_POST['credit_limit'] ?? '';
$postNotes             = $_POST['notes'] ?? '';
$postZoneId            = (int)($_POST['zone_id'] ?? 0);
?>
<!doctype html>
<html lang="en">
<?php include('includes/head.php'); ?>

<style>
    html, body { min-height: 100%; }
    body[data-sidebar="dark"] { overflow-x: hidden; }
    .vertical-menu { height: 100vh !important; position: fixed; top: 0; bottom: 0; z-index: 1002; }
    .vertical-menu .h-100 { height: 100vh !important; overflow-y: auto !important; overflow-x: hidden !important; }
    #sidebar-menu { padding-bottom: 90px; }
    #sidebar-menu .metismenu { margin-bottom: 80px; }
    .main-content { min-height: 100vh; }

    .form-section {
        background: #fff;
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 25px;
        border: 1px solid #eef0f4;
    }
    .form-section h5 {
        margin-bottom: 18px;
        padding-bottom: 10px;
        border-bottom: 2px solid #eef0f4;
        font-size: 16px;
        font-weight: 600;
    }
    .type-badge {
        display: inline-block;
        padding: 8px 16px;
        border-radius: 8px;
        margin-bottom: 20px;
    }
    .type-billing { background: #e3f2fd; color: #1976d2; }
    .type-pawn { background: #fff3e0; color: #f57c00; }
    .type-chit { background: #e8f5e9; color: #388e3c; }

    @media (max-width: 767.98px) {
        .form-section {
            padding: 16px;
            margin-bottom: 18px;
        }
        .page-content {
            padding-left: 0;
            padding-right: 0;
        }
        .btn {
            width: 100%;
            margin-bottom: 8px;
        }
        .text-end .btn {
            width: 100%;
        }
    }
</style>

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

                <div class="row mb-3">
                    <div class="col-12">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div>
                                <h4 class="mb-1">Add New Customer</h4>
                                <div class="text-muted">Create a new customer account</div>
                            </div>
                            <div>
                                <a href="customers.php?type=<?php echo h($customerType); ?>" class="btn btn-outline-secondary">
                                    <i class="fas fa-arrow-left"></i> Back to Customers
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($message): ?>
                    <div class="alert alert-<?php echo h($messageType); ?> alert-dismissible fade show" role="alert">
                        <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <div class="type-badge type-<?php echo h($customerType); ?>">
                    <strong>Customer Type:</strong> <?php echo h(getCustomerTypeLabel($customerType)); ?> Customer
                </div>

                <form method="POST" action="" id="customerForm">
                    <input type="hidden" name="action" value="add_customer">
                    <input type="hidden" name="page_customer_type" value="<?php echo h($customerType); ?>">

                    <!-- Basic Information -->
                    <div class="form-section">
                        <h5><i class="fas fa-user-circle me-2"></i> Basic Information</h5>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Customer Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="customer_name" value="<?php echo h($postCustomerName); ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Shop/Business Name</label>
                                <input type="text" class="form-control" name="shop_name" value="<?php echo h($postShopName); ?>">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Contact Number <span class="text-danger">*</span></label>
                                <input type="tel" class="form-control" name="customer_contact" value="<?php echo h($postCustomerContact); ?>" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Alternate Number</label>
                                <input type="tel" class="form-control" name="alternate_contact" value="<?php echo h($postAlternateContact); ?>">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Email Address</label>
                                <input type="email" class="form-control" name="email" value="<?php echo h($postEmail); ?>">
                            </div>
                            <div class="col-12 mb-3">
                                <label class="form-label">Address/Location <span class="text-danger">*</span></label>
                                <textarea class="form-control" name="shop_location" rows="2" required><?php echo h($postShopLocation); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Business Details -->
                    <div class="form-section">
                        <h5><i class="fas fa-building me-2"></i> Business Details</h5>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">GST Number</label>
                                <input type="text" class="form-control" name="gst_number" value="<?php echo h($postGstNumber); ?>" placeholder="22AAAAA0000A1Z">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Customer Type</label>
                                <select class="form-select" name="customer_category">
                                    <option value="retail" <?php echo $postCustomerCategory === 'retail' ? 'selected' : ''; ?>>Retail</option>
                                    <option value="wholesale" <?php echo $postCustomerCategory === 'wholesale' ? 'selected' : ''; ?>>Wholesale</option>
                                    <option value="hotel" <?php echo $postCustomerCategory === 'hotel' ? 'selected' : ''; ?>>Hotel/Restaurant</option>
                                    <option value="office" <?php echo $postCustomerCategory === 'office' ? 'selected' : ''; ?>>Office/Corporate</option>
                                    <option value="residential" <?php echo $postCustomerCategory === 'residential' ? 'selected' : ''; ?>>Residential</option>
                                    <option value="other" <?php echo $postCustomerCategory === 'other' ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Zone & Assignment -->
                    <div class="form-section">
                        <h5><i class="fas fa-map-marker-alt me-2"></i> Zone & Assignment</h5>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Zone</label>
                                <select class="form-select" name="zone_id">
                                    <option value="">-- Select Zone --</option>
                                    <?php foreach ($zones as $zone): ?>
                                        <option value="<?php echo (int)$zone['id']; ?>" <?php echo $postZoneId === (int)$zone['id'] ? 'selected' : ''; ?>>
                                            <?php echo h($zone['zone_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Assigned Area</label>
                                <input type="text" class="form-control" name="assigned_area" value="<?php echo h($postAssignedArea); ?>" placeholder="e.g., Central Zone, North Area">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Assigned Lineman</label>
                                <select class="form-select" name="assigned_lineman_id">
                                    <option value="">-- None --</option>
                                    <?php foreach ($linemen as $lineman): ?>
                                        <option value="<?php echo (int)$lineman['id']; ?>" <?php echo $postAssignedLinemanId === (int)$lineman['id'] ? 'selected' : ''; ?>>
                                            <?php
                                            echo h($lineman['full_name']);
                                            if (!empty($lineman['assigned_area'])) {
                                                echo ' (' . h($lineman['assigned_area']) . ')';
                                            }
                                            ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Payment & Credit Terms -->
                    <div class="form-section">
                        <h5><i class="fas fa-credit-card me-2"></i> Payment & Credit Terms</h5>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Payment Terms</label>
                                <select class="form-select" name="payment_terms">
                                    <option value="cash" <?php echo $postPaymentTerms === 'cash' ? 'selected' : ''; ?>>Cash (No Credit)</option>
                                    <option value="credit_7" <?php echo $postPaymentTerms === 'credit_7' ? 'selected' : ''; ?>>7 Days Credit</option>
                                    <option value="credit_15" <?php echo $postPaymentTerms === 'credit_15' ? 'selected' : ''; ?>>15 Days Credit</option>
                                    <option value="credit_30" <?php echo $postPaymentTerms === 'credit_30' ? 'selected' : ''; ?>>30 Days Credit</option>
                                    <option value="prepaid" <?php echo $postPaymentTerms === 'prepaid' ? 'selected' : ''; ?>>Prepaid</option>
                                    <option value="weekly" <?php echo $postPaymentTerms === 'weekly' ? 'selected' : ''; ?>>Weekly Payment</option>
                                    <option value="monthly" <?php echo $postPaymentTerms === 'monthly' ? 'selected' : ''; ?>>Monthly Payment</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Credit Limit (₹)</label>
                                <input type="number" step="0.01" class="form-control" name="credit_limit" value="<?php echo h($postCreditLimit); ?>" placeholder="0.00">
                                <small class="text-muted">Leave 0 for no credit limit</small>
                            </div>
                        </div>
                    </div>

                    <!-- Additional Information -->
                    <div class="form-section">
                        <h5><i class="fas fa-sticky-note me-2"></i> Additional Information</h5>
                        <div class="row">
                            <div class="col-12 mb-3">
                                <label class="form-label">Notes/Comments</label>
                                <textarea class="form-control" name="notes" rows="3"><?php echo h($postNotes); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Submit Button -->
                    <div class="text-end mb-4">
                        <button type="reset" class="btn btn-outline-secondary me-2">Reset</button>
                        <button type="submit" class="btn btn-primary">Add Customer</button>
                    </div>
                </form>

            </div>
        </div>

        <?php include('includes/footer.php'); ?>
    </div>
</div>

<?php include('includes/rightbar.php'); ?>
<?php include('includes/scripts.php'); ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.body.setAttribute('data-sidebar', 'dark');

    var sidebarScroll = document.querySelector('.vertical-menu [data-simplebar]');
    if (sidebarScroll) {
        sidebarScroll.style.height = '100vh';
        sidebarScroll.style.overflowY = 'auto';
        sidebarScroll.style.overflowX = 'hidden';
    }

    const zoneSelect = document.querySelector('select[name="zone_id"]');
    const areaInput = document.querySelector('input[name="assigned_area"]');

    if (zoneSelect && areaInput) {
        zoneSelect.addEventListener('change', function() {
            if (this.value && !areaInput.value) {
                const selectedOption = this.options[this.selectedIndex];
                areaInput.value = selectedOption.text;
            }
        });
    }

    const form = document.getElementById('customerForm');

    if (form) {
        form.addEventListener('submit', function(e) {
            const customerName = document.querySelector('input[name="customer_name"]').value.trim();
            const contact = document.querySelector('input[name="customer_contact"]').value.trim();
            const address = document.querySelector('textarea[name="shop_location"]').value.trim();

            if (!customerName) {
                e.preventDefault();
                alert('Please enter customer name');
                return false;
            }

            if (!contact) {
                e.preventDefault();
                alert('Please enter contact number');
                return false;
            }

            if (!address) {
                e.preventDefault();
                alert('Please enter address/location');
                return false;
            }

            return true;
        });
    }
});
</script>

</body>
</html>