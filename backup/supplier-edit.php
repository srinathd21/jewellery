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

if (!function_exists('money')) {
    function money($amount): string
    {
        return number_format((float)$amount, 2);
    }
}

if (!function_exists('getClientIp')) {
    function getClientIp(): string
    {
        return $_SERVER['HTTP_CLIENT_IP']
            ?? $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['REMOTE_ADDR']
            ?? '';
    }
}

if (!function_exists('addAuditLog')) {
    function addAuditLog(
        mysqli $conn,
        int $businessId,
        int $userId,
        string $moduleName,
        string $actionType,
        int $referenceId,
        string $description
    ): void {
        if (!tableExists($conn, 'audit_logs')) {
            return;
        }

        $ipAddress = getClientIp();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $stmt = $conn->prepare("
            INSERT INTO audit_logs
            (business_id, user_id, module_name, action_type, reference_id, description, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        if ($stmt) {
            $stmt->bind_param(
                'iississs',
                $businessId,
                $userId,
                $moduleName,
                $actionType,
                $referenceId,
                $description,
                $ipAddress,
                $userAgent
            );
            $stmt->execute();
            $stmt->close();
        }
    }
}

$pageTitle = 'Edit Supplier';
$page_title = 'Edit Supplier';
$currentPage = 'suppliers';

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

if (!$stmt) {
    die('Role check failed.');
}

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
   REQUIRED TABLE
------------------------------------------------------- */
if (!tableExists($conn, 'suppliers')) {
    die('Suppliers table not found. Please import the SQL first.');
}

/* -------------------------------------------------------
   COLUMN CHECKS
------------------------------------------------------- */
$supHasBusinessId      = hasColumn($conn, 'suppliers', 'business_id');
$supHasSupplierCode    = hasColumn($conn, 'suppliers', 'supplier_code');
$supHasSupplierName    = hasColumn($conn, 'suppliers', 'supplier_name');
$supHasContactPerson   = hasColumn($conn, 'suppliers', 'contact_person');
$supHasMobile          = hasColumn($conn, 'suppliers', 'mobile');
$supHasAlternateMobile = hasColumn($conn, 'suppliers', 'alternate_mobile');
$supHasEmail           = hasColumn($conn, 'suppliers', 'email');
$supHasGstin           = hasColumn($conn, 'suppliers', 'gstin');
$supHasAddress1        = hasColumn($conn, 'suppliers', 'address_line1');
$supHasAddress2        = hasColumn($conn, 'suppliers', 'address_line2');
$supHasCity            = hasColumn($conn, 'suppliers', 'city');
$supHasState           = hasColumn($conn, 'suppliers', 'state');
$supHasPincode         = hasColumn($conn, 'suppliers', 'pincode');
$supHasOpeningBalance  = hasColumn($conn, 'suppliers', 'opening_balance');
$supHasBalanceType     = hasColumn($conn, 'suppliers', 'balance_type');
$supHasNotes           = hasColumn($conn, 'suppliers', 'notes');
$supHasIsActive        = hasColumn($conn, 'suppliers', 'is_active');
$supHasCreatedAt       = hasColumn($conn, 'suppliers', 'created_at');
$supHasUpdatedAt       = hasColumn($conn, 'suppliers', 'updated_at');

/* -------------------------------------------------------
   SUPPLIER ID
------------------------------------------------------- */
$supplierId = (int)($_GET['id'] ?? $_POST['supplier_id'] ?? 0);

if ($supplierId <= 0) {
    die('Invalid supplier ID.');
}

/* -------------------------------------------------------
   FETCH SUPPLIER
------------------------------------------------------- */
$sql = "SELECT * FROM suppliers WHERE id = ?";

if ($supHasBusinessId) {
    $sql .= " AND business_id = ?";
}

$sql .= " LIMIT 1";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die('Failed to prepare supplier query.');
}

if ($supHasBusinessId) {
    $stmt->bind_param('ii', $supplierId, $businessId);
} else {
    $stmt->bind_param('i', $supplierId);
}

$stmt->execute();
$res = $stmt->get_result();
$supplier = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$supplier) {
    die('Supplier not found.');
}

/* -------------------------------------------------------
   FORM PROCESS
------------------------------------------------------- */
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_supplier') {
    $supplierCode    = trim((string)($_POST['supplier_code'] ?? ''));
    $supplierName    = trim((string)($_POST['supplier_name'] ?? ''));
    $contactPerson   = trim((string)($_POST['contact_person'] ?? ''));
    $mobile          = trim((string)($_POST['mobile'] ?? ''));
    $alternateMobile = trim((string)($_POST['alternate_mobile'] ?? ''));
    $email           = trim((string)($_POST['email'] ?? ''));
    $gstin           = trim((string)($_POST['gstin'] ?? ''));
    $addressLine1    = trim((string)($_POST['address_line1'] ?? ''));
    $addressLine2    = trim((string)($_POST['address_line2'] ?? ''));
    $city            = trim((string)($_POST['city'] ?? ''));
    $state           = trim((string)($_POST['state'] ?? ''));
    $pincode         = trim((string)($_POST['pincode'] ?? ''));
    $openingBalance  = (float)($_POST['opening_balance'] ?? 0);
    $balanceType     = trim((string)($_POST['balance_type'] ?? 'Cr'));
    $notes           = trim((string)($_POST['notes'] ?? ''));
    $isActive        = isset($_POST['is_active']) ? 1 : 0;

    $errors = [];

    if ($supHasSupplierName && $supplierName === '') {
        $errors[] = 'Supplier name is required.';
    }

    if ($supHasMobile && $mobile === '') {
        $errors[] = 'Mobile number is required.';
    }

    if ($supHasMobile && $mobile !== '' && !preg_match('/^[0-9+\-\s]{6,20}$/', $mobile)) {
        $errors[] = 'Enter a valid mobile number.';
    }

    if ($supHasEmail && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    }

    if ($supHasBalanceType && !in_array($balanceType, ['Dr', 'Cr'], true)) {
        $balanceType = 'Cr';
    }

    /* Duplicate mobile check */
    if (empty($errors) && $supHasMobile && $mobile !== '') {
        $checkSql = "SELECT id FROM suppliers WHERE mobile = ? AND id != ?";

        if ($supHasBusinessId) {
            $checkSql .= " AND business_id = ?";
        }

        if ($supHasIsActive) {
            $checkSql .= " AND is_active = 1";
        }

        $checkSql .= " LIMIT 1";

        $checkStmt = $conn->prepare($checkSql);

        if ($checkStmt) {
            if ($supHasBusinessId) {
                $checkStmt->bind_param('sii', $mobile, $supplierId, $businessId);
            } else {
                $checkStmt->bind_param('si', $mobile, $supplierId);
            }

            $checkStmt->execute();
            $checkRes = $checkStmt->get_result();

            if ($checkRes && $checkRes->num_rows > 0) {
                $errors[] = 'Another supplier with this mobile number already exists.';
            }

            $checkStmt->close();
        }
    }

    if (empty($errors)) {
        $setParts = [];
        $params = [];
        $types = '';

        if ($supHasSupplierCode) {
            $setParts[] = "supplier_code = ?";
            $params[] = $supplierCode;
            $types .= 's';
        }

        if ($supHasSupplierName) {
            $setParts[] = "supplier_name = ?";
            $params[] = $supplierName;
            $types .= 's';
        }

        if ($supHasContactPerson) {
            $setParts[] = "contact_person = ?";
            $params[] = $contactPerson;
            $types .= 's';
        }

        if ($supHasMobile) {
            $setParts[] = "mobile = ?";
            $params[] = $mobile;
            $types .= 's';
        }

        if ($supHasAlternateMobile) {
            $setParts[] = "alternate_mobile = ?";
            $params[] = $alternateMobile;
            $types .= 's';
        }

        if ($supHasEmail) {
            $setParts[] = "email = ?";
            $params[] = $email;
            $types .= 's';
        }

        if ($supHasGstin) {
            $setParts[] = "gstin = ?";
            $params[] = $gstin;
            $types .= 's';
        }

        if ($supHasAddress1) {
            $setParts[] = "address_line1 = ?";
            $params[] = $addressLine1;
            $types .= 's';
        }

        if ($supHasAddress2) {
            $setParts[] = "address_line2 = ?";
            $params[] = $addressLine2;
            $types .= 's';
        }

        if ($supHasCity) {
            $setParts[] = "city = ?";
            $params[] = $city;
            $types .= 's';
        }

        if ($supHasState) {
            $setParts[] = "state = ?";
            $params[] = $state;
            $types .= 's';
        }

        if ($supHasPincode) {
            $setParts[] = "pincode = ?";
            $params[] = $pincode;
            $types .= 's';
        }

        if ($supHasOpeningBalance) {
            $setParts[] = "opening_balance = ?";
            $params[] = $openingBalance;
            $types .= 'd';
        }

        if ($supHasBalanceType) {
            $setParts[] = "balance_type = ?";
            $params[] = $balanceType;
            $types .= 's';
        }

        if ($supHasNotes) {
            $setParts[] = "notes = ?";
            $params[] = $notes;
            $types .= 's';
        }

        if ($supHasIsActive) {
            $setParts[] = "is_active = ?";
            $params[] = $isActive;
            $types .= 'i';
        }

        if ($supHasUpdatedAt) {
            $setParts[] = "updated_at = NOW()";
        }

        if (empty($setParts)) {
            $error = 'No editable supplier columns found.';
        } else {
            $updateSql = "UPDATE suppliers SET " . implode(', ', $setParts) . " WHERE id = ?";
            $params[] = $supplierId;
            $types .= 'i';

            if ($supHasBusinessId) {
                $updateSql .= " AND business_id = ?";
                $params[] = $businessId;
                $types .= 'i';
            }

            $updateSql .= " LIMIT 1";

            $stmt = $conn->prepare($updateSql);

            if (!$stmt) {
                $error = 'Failed to prepare update query: ' . $conn->error;
            } else {
                $bindValues = [];
                $bindValues[] = $types;

                for ($i = 0; $i < count($params); $i++) {
                    $bindValues[] = &$params[$i];
                }

                call_user_func_array([$stmt, 'bind_param'], $bindValues);

                if ($stmt->execute()) {
                    addAuditLog(
                        $conn,
                        $businessId,
                        $userId,
                        'Suppliers',
                        'Update',
                        $supplierId,
                        'Updated supplier: ' . ($supplierName !== '' ? $supplierName : 'Supplier ID ' . $supplierId)
                    );

                    header('Location: suppliers.php?msg=updated');
                    exit;
                } else {
                    $error = 'Failed to update supplier: ' . $stmt->error;
                }

                $stmt->close();
            }
        }
    } else {
        $error = implode('<br>', array_map('h', $errors));
    }

    $supplier['supplier_code']     = $supplierCode;
    $supplier['supplier_name']     = $supplierName;
    $supplier['contact_person']    = $contactPerson;
    $supplier['mobile']            = $mobile;
    $supplier['alternate_mobile']  = $alternateMobile;
    $supplier['email']             = $email;
    $supplier['gstin']             = $gstin;
    $supplier['address_line1']     = $addressLine1;
    $supplier['address_line2']     = $addressLine2;
    $supplier['city']              = $city;
    $supplier['state']             = $state;
    $supplier['pincode']           = $pincode;
    $supplier['opening_balance']   = $openingBalance;
    $supplier['balance_type']      = $balanceType;
    $supplier['notes']             = $notes;
    $supplier['is_active']         = $isActive;
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
                        <?php echo $error; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                            <div>
                                <h4 class="mb-1">Edit Supplier</h4>
                                <p class="text-muted mb-0">Update supplier details</p>
                            </div>
                            <div>
                                <a href="suppliers.php" class="btn btn-secondary">
                                    <i class="fas fa-arrow-left"></i> Back
                                </a>
                            </div>
                        </div>

                        <form method="post" action="">
                            <input type="hidden" name="action" value="update_supplier">
                            <input type="hidden" name="supplier_id" value="<?php echo (int)$supplierId; ?>">

                            <div class="row">
                                <?php if ($supHasSupplierCode): ?>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Supplier Code</label>
                                        <input type="text" name="supplier_code" class="form-control"
                                               value="<?php echo h($supplier['supplier_code'] ?? ''); ?>">
                                    </div>
                                <?php endif; ?>

                                <?php if ($supHasSupplierName): ?>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Supplier Name <span class="text-danger">*</span></label>
                                        <input type="text" name="supplier_name" class="form-control"
                                               value="<?php echo h($supplier['supplier_name'] ?? ''); ?>" required>
                                    </div>
                                <?php endif; ?>

                                <?php if ($supHasContactPerson): ?>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Contact Person</label>
                                        <input type="text" name="contact_person" class="form-control"
                                               value="<?php echo h($supplier['contact_person'] ?? ''); ?>">
                                    </div>
                                <?php endif; ?>

                                <?php if ($supHasMobile): ?>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Mobile <span class="text-danger">*</span></label>
                                        <input type="text" name="mobile" class="form-control"
                                               value="<?php echo h($supplier['mobile'] ?? ''); ?>" required>
                                    </div>
                                <?php endif; ?>

                                <?php if ($supHasAlternateMobile): ?>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Alternate Mobile</label>
                                        <input type="text" name="alternate_mobile" class="form-control"
                                               value="<?php echo h($supplier['alternate_mobile'] ?? ''); ?>">
                                    </div>
                                <?php endif; ?>

                                <?php if ($supHasEmail): ?>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Email</label>
                                        <input type="email" name="email" class="form-control"
                                               value="<?php echo h($supplier['email'] ?? ''); ?>">
                                    </div>
                                <?php endif; ?>

                                <?php if ($supHasGstin): ?>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">GSTIN</label>
                                        <input type="text" name="gstin" class="form-control"
                                               value="<?php echo h($supplier['gstin'] ?? ''); ?>">
                                    </div>
                                <?php endif; ?>

                                <?php if ($supHasAddress1): ?>
                                    <div class="col-md-8 mb-3">
                                        <label class="form-label">Address Line 1</label>
                                        <input type="text" name="address_line1" class="form-control"
                                               value="<?php echo h($supplier['address_line1'] ?? ''); ?>">
                                    </div>
                                <?php endif; ?>

                                <?php if ($supHasAddress2): ?>
                                    <div class="col-md-12 mb-3">
                                        <label class="form-label">Address Line 2</label>
                                        <input type="text" name="address_line2" class="form-control"
                                               value="<?php echo h($supplier['address_line2'] ?? ''); ?>">
                                    </div>
                                <?php endif; ?>

                                <?php if ($supHasCity): ?>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">City</label>
                                        <input type="text" name="city" class="form-control"
                                               value="<?php echo h($supplier['city'] ?? ''); ?>">
                                    </div>
                                <?php endif; ?>

                                <?php if ($supHasState): ?>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">State</label>
                                        <input type="text" name="state" class="form-control"
                                               value="<?php echo h($supplier['state'] ?? ''); ?>">
                                    </div>
                                <?php endif; ?>

                                <?php if ($supHasPincode): ?>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Pincode</label>
                                        <input type="text" name="pincode" class="form-control"
                                               value="<?php echo h($supplier['pincode'] ?? ''); ?>">
                                    </div>
                                <?php endif; ?>

                                <?php if ($supHasOpeningBalance): ?>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Opening Balance</label>
                                        <input type="number" step="0.01" name="opening_balance" class="form-control"
                                               value="<?php echo h($supplier['opening_balance'] ?? '0.00'); ?>">
                                    </div>
                                <?php endif; ?>

                                <?php if ($supHasBalanceType): ?>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Balance Type</label>
                                        <select name="balance_type" class="form-select">
                                            <option value="Cr" <?php echo (($supplier['balance_type'] ?? '') === 'Cr') ? 'selected' : ''; ?>>Cr</option>
                                            <option value="Dr" <?php echo (($supplier['balance_type'] ?? '') === 'Dr') ? 'selected' : ''; ?>>Dr</option>
                                        </select>
                                    </div>
                                <?php endif; ?>

                                <?php if ($supHasIsActive): ?>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label d-block">Status</label>
                                        <div class="form-check form-switch mt-2">
                                            <input class="form-check-input" type="checkbox" name="is_active" id="is_active"
                                                   <?php echo (int)($supplier['is_active'] ?? 1) === 1 ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="is_active">Active</label>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if ($supHasNotes): ?>
                                    <div class="col-md-12 mb-3">
                                        <label class="form-label">Notes</label>
                                        <textarea name="notes" class="form-control" rows="4"><?php echo h($supplier['notes'] ?? ''); ?></textarea>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="text-end">
                                <a href="suppliers.php" class="btn btn-secondary">Cancel</a>
                                <button type="submit" class="btn btn-primary">Update Supplier</button>
                            </div>
                        </form>
                    </div>
                </div>

            </div>
        </div>

        <?php include('includes/footer.php'); ?>
    </div>
</div>

<?php include('includes/rightbar.php'); ?>
<?php include('includes/scripts.php'); ?>

</body>
</html>