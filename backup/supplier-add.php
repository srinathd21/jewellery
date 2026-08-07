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

if (!function_exists('tableExists')) {
    function tableExists(mysqli $conn, string $tableName): bool
    {
        $safe = $conn->real_escape_string($tableName);
        $res = $conn->query("SHOW TABLES LIKE '{$safe}'");
        return $res && $res->num_rows > 0;
    }
}

if (!function_exists('hasColumn')) {
    function hasColumn(mysqli $conn, string $table, string $column): bool
    {
        $table = $conn->real_escape_string($table);
        $column = $conn->real_escape_string($column);
        $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
        return $res && $res->num_rows > 0;
    }
}

$pageTitle = 'Add Supplier';

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

/* -------------------------------------------------------
   ROLE CHECK
------------------------------------------------------- */
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
if (!tableExists($conn, 'suppliers')) {
    die('Suppliers table not found. Please import the SQL first.');
}

/* -------------------------------------------------------
   COLUMN CHECKS
------------------------------------------------------- */
$supHasBusinessId       = hasColumn($conn, 'suppliers', 'business_id');
$supHasSupplierCode     = hasColumn($conn, 'suppliers', 'supplier_code');
$supHasSupplierName     = hasColumn($conn, 'suppliers', 'supplier_name');
$supHasContactPerson    = hasColumn($conn, 'suppliers', 'contact_person');
$supHasMobile           = hasColumn($conn, 'suppliers', 'mobile');
$supHasAlternateMobile  = hasColumn($conn, 'suppliers', 'alternate_mobile');
$supHasEmail            = hasColumn($conn, 'suppliers', 'email');
$supHasGstin            = hasColumn($conn, 'suppliers', 'gstin');
$supHasAddress1         = hasColumn($conn, 'suppliers', 'address_line1');
$supHasAddress2         = hasColumn($conn, 'suppliers', 'address_line2');
$supHasCity             = hasColumn($conn, 'suppliers', 'city');
$supHasState            = hasColumn($conn, 'suppliers', 'state');
$supHasPincode          = hasColumn($conn, 'suppliers', 'pincode');
$supHasOpeningBalance   = hasColumn($conn, 'suppliers', 'opening_balance');
$supHasBalanceType      = hasColumn($conn, 'suppliers', 'balance_type');
$supHasNotes            = hasColumn($conn, 'suppliers', 'notes');
$supHasIsActive         = hasColumn($conn, 'suppliers', 'is_active');
$supHasCreatedAt        = hasColumn($conn, 'suppliers', 'created_at');
$supHasUpdatedAt        = hasColumn($conn, 'suppliers', 'updated_at');

/* -------------------------------------------------------
   HELPERS
------------------------------------------------------- */
function generateSupplierCode(mysqli $conn, int $businessId, bool $hasBusinessId, string $prefix = 'SUP'): string
{
    do {
        $code = strtoupper($prefix) . str_pad((string)random_int(1, 999999), 6, '0', STR_PAD_LEFT);

        if ($hasBusinessId) {
            $stmt = $conn->prepare("SELECT id FROM suppliers WHERE supplier_code = ? AND business_id = ? LIMIT 1");
            $stmt->bind_param('si', $code, $businessId);
        } else {
            $stmt = $conn->prepare("SELECT id FROM suppliers WHERE supplier_code = ? LIMIT 1");
            $stmt->bind_param('s', $code);
        }

        $exists = false;
        if ($stmt) {
            $stmt->execute();
            $res = $stmt->get_result();
            $exists = $res && $res->num_rows > 0;
            $stmt->close();
        }
    } while ($exists);

    return $code;
}

function supplierCodeExists(mysqli $conn, string $supplierCode, int $businessId, bool $hasBusinessId): bool
{
    if ($supplierCode === '') {
        return false;
    }

    if ($hasBusinessId) {
        $stmt = $conn->prepare("SELECT id FROM suppliers WHERE supplier_code = ? AND business_id = ? LIMIT 1");
        $stmt->bind_param('si', $supplierCode, $businessId);
    } else {
        $stmt = $conn->prepare("SELECT id FROM suppliers WHERE supplier_code = ? LIMIT 1");
        $stmt->bind_param('s', $supplierCode);
    }

    if (!$stmt) {
        return true;
    }

    $stmt->execute();
    $res = $stmt->get_result();
    $exists = $res && $res->num_rows > 0;
    $stmt->close();

    return $exists;
}

/* -------------------------------------------------------
   DEFAULT VALUES
------------------------------------------------------- */
$success = '';
$error = '';

$supplierCode = '';
$supplierName = '';
$contactPerson = '';
$mobile = '';
$alternateMobile = '';
$email = '';
$gstin = '';
$address1 = '';
$address2 = '';
$city = '';
$state = '';
$pincode = '';
$openingBalance = '0.00';
$balanceType = 'Cr';
$notes = '';
$isActive = 1;

/* -------------------------------------------------------
   SAVE
------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $supplierCode = strtoupper(trim((string)($_POST['supplier_code'] ?? '')));
    $supplierName = trim((string)($_POST['supplier_name'] ?? ''));
    $contactPerson = trim((string)($_POST['contact_person'] ?? ''));
    $mobile = trim((string)($_POST['mobile'] ?? ''));
    $alternateMobile = trim((string)($_POST['alternate_mobile'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $gstin = strtoupper(trim((string)($_POST['gstin'] ?? '')));
    $address1 = trim((string)($_POST['address_line1'] ?? ''));
    $address2 = trim((string)($_POST['address_line2'] ?? ''));
    $city = trim((string)($_POST['city'] ?? ''));
    $state = trim((string)($_POST['state'] ?? ''));
    $pincode = trim((string)($_POST['pincode'] ?? ''));
    $openingBalance = trim((string)($_POST['opening_balance'] ?? '0'));
    $balanceType = trim((string)($_POST['balance_type'] ?? 'Cr'));
    $notes = trim((string)($_POST['notes'] ?? ''));
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if ($supplierCode === '') {
        $supplierCode = generateSupplierCode($conn, $businessId, $supHasBusinessId, 'SUP');
    }

    if ($supplierName === '') {
        $error = 'Supplier name is required.';
    } elseif (!in_array($balanceType, ['Dr', 'Cr'], true)) {
        $error = 'Invalid balance type.';
    } elseif (!is_numeric($openingBalance)) {
        $error = 'Opening balance must be numeric.';
    } elseif ($supHasSupplierCode && supplierCodeExists($conn, $supplierCode, $businessId, $supHasBusinessId)) {
        $error = 'Supplier code already exists.';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address.';
    } else {
        $fields = [];
        $placeholders = [];
        $types = '';
        $values = [];

        if ($supHasBusinessId) {
            $fields[] = 'business_id';
            $placeholders[] = '?';
            $types .= 'i';
            $values[] = $businessId;
        }

        if ($supHasSupplierCode) {
            $fields[] = 'supplier_code';
            $placeholders[] = '?';
            $types .= 's';
            $values[] = $supplierCode;
        }

        if ($supHasSupplierName) {
            $fields[] = 'supplier_name';
            $placeholders[] = '?';
            $types .= 's';
            $values[] = $supplierName;
        }

        if ($supHasContactPerson) {
            $fields[] = 'contact_person';
            $placeholders[] = '?';
            $types .= 's';
            $values[] = $contactPerson;
        }

        if ($supHasMobile) {
            $fields[] = 'mobile';
            $placeholders[] = '?';
            $types .= 's';
            $values[] = $mobile;
        }

        if ($supHasAlternateMobile) {
            $fields[] = 'alternate_mobile';
            $placeholders[] = '?';
            $types .= 's';
            $values[] = $alternateMobile;
        }

        if ($supHasEmail) {
            $fields[] = 'email';
            $placeholders[] = '?';
            $types .= 's';
            $values[] = $email;
        }

        if ($supHasGstin) {
            $fields[] = 'gstin';
            $placeholders[] = '?';
            $types .= 's';
            $values[] = $gstin;
        }

        if ($supHasAddress1) {
            $fields[] = 'address_line1';
            $placeholders[] = '?';
            $types .= 's';
            $values[] = $address1;
        }

        if ($supHasAddress2) {
            $fields[] = 'address_line2';
            $placeholders[] = '?';
            $types .= 's';
            $values[] = $address2;
        }

        if ($supHasCity) {
            $fields[] = 'city';
            $placeholders[] = '?';
            $types .= 's';
            $values[] = $city;
        }

        if ($supHasState) {
            $fields[] = 'state';
            $placeholders[] = '?';
            $types .= 's';
            $values[] = $state;
        }

        if ($supHasPincode) {
            $fields[] = 'pincode';
            $placeholders[] = '?';
            $types .= 's';
            $values[] = $pincode;
        }

        if ($supHasOpeningBalance) {
            $fields[] = 'opening_balance';
            $placeholders[] = '?';
            $types .= 'd';
            $values[] = (float)$openingBalance;
        }

        if ($supHasBalanceType) {
            $fields[] = 'balance_type';
            $placeholders[] = '?';
            $types .= 's';
            $values[] = $balanceType;
        }

        if ($supHasNotes) {
            $fields[] = 'notes';
            $placeholders[] = '?';
            $types .= 's';
            $values[] = $notes;
        }

        if ($supHasIsActive) {
            $fields[] = 'is_active';
            $placeholders[] = '?';
            $types .= 'i';
            $values[] = $isActive;
        }

        if ($supHasCreatedAt) {
            $fields[] = 'created_at';
            $placeholders[] = 'NOW()';
        }

        if ($supHasUpdatedAt) {
            $fields[] = 'updated_at';
            $placeholders[] = 'NOW()';
        }

        $sql = "INSERT INTO suppliers (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            $error = 'Failed to prepare insert query.';
        } else {
            if (!empty($values)) {
                $bindValues = [];
                $bindValues[] = $types;
                for ($i = 0; $i < count($values); $i++) {
                    $bindValues[] = &$values[$i];
                }
                call_user_func_array([$stmt, 'bind_param'], $bindValues);
            }

            if ($stmt->execute()) {
                $newId = (int)$stmt->insert_id;

                if (function_exists('addAuditLog')) {
                    addAuditLog(
                        $conn,
                        $businessId,
                        $userId,
                        'Suppliers',
                        'Create',
                        $newId,
                        'Created supplier ' . $supplierName
                    );
                }

                $stmt->close();
                header('Location: suppliers.php?msg=created');
                exit;
            } else {
                $error = 'Failed to create supplier.';
            }

            $stmt->close();
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
                                    <h4 class="card-title mb-4">Supplier Details</h4>

                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Supplier Code</label>
                                            <input type="text" name="supplier_code" class="form-control" value="<?php echo h($supplierCode); ?>" placeholder="Leave empty for auto-generate">
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Supplier Name <span class="text-danger">*</span></label>
                                            <input type="text" name="supplier_name" class="form-control" value="<?php echo h($supplierName); ?>" required>
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Contact Person</label>
                                            <input type="text" name="contact_person" class="form-control" value="<?php echo h($contactPerson); ?>">
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Mobile</label>
                                            <input type="text" name="mobile" class="form-control" value="<?php echo h($mobile); ?>">
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Alternate Mobile</label>
                                            <input type="text" name="alternate_mobile" class="form-control" value="<?php echo h($alternateMobile); ?>">
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
                                            <label class="form-label">Pincode</label>
                                            <input type="text" name="pincode" class="form-control" value="<?php echo h($pincode); ?>">
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">City</label>
                                            <input type="text" name="city" class="form-control" value="<?php echo h($city); ?>">
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">State</label>
                                            <input type="text" name="state" class="form-control" value="<?php echo h($state); ?>">
                                        </div>

                                        <div class="col-md-12 mb-3">
                                            <label class="form-label">Address Line 1</label>
                                            <input type="text" name="address_line1" class="form-control" value="<?php echo h($address1); ?>">
                                        </div>

                                        <div class="col-md-12 mb-3">
                                            <label class="form-label">Address Line 2</label>
                                            <input type="text" name="address_line2" class="form-control" value="<?php echo h($address2); ?>">
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Opening Balance</label>
                                            <input type="text" name="opening_balance" class="form-control" value="<?php echo h($openingBalance); ?>">
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Balance Type</label>
                                            <select name="balance_type" class="form-select">
                                                <option value="Cr" <?php echo $balanceType === 'Cr' ? 'selected' : ''; ?>>Cr</option>
                                                <option value="Dr" <?php echo $balanceType === 'Dr' ? 'selected' : ''; ?>>Dr</option>
                                            </select>
                                        </div>

                                        <div class="col-md-12 mb-3">
                                            <label class="form-label">Notes</label>
                                            <textarea name="notes" class="form-control" rows="4"><?php echo h($notes); ?></textarea>
                                        </div>

                                        <div class="col-md-12 mb-3">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="is_active" id="is_active" value="1" <?php echo (int)$isActive === 1 ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="is_active">
                                                    Active Supplier
                                                </label>
                                            </div>
                                        </div>
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
                                            Save Supplier
                                        </button>
                                        <a href="suppliers.php" class="btn btn-secondary waves-effect">
                                            Back to Suppliers
                                        </a>
                                    </div>

                                    <hr>

                                    <h5 class="mb-3">Notes</h5>
                                    <ul class="mb-0 ps-3">
                                        <li>Supplier code can be entered manually or auto-generated.</li>
                                        <li>Opening balance is optional.</li>
                                        <li>Balance type default is Credit.</li>
                                        <li>Email must be valid if entered.</li>
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