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

$pageTitle = 'Suppliers';

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
   FLASH MESSAGES
------------------------------------------------------- */
$success = '';
$error = '';

$msg = trim((string)($_GET['msg'] ?? ''));
if ($msg === 'created') {
    $success = 'Supplier created successfully.';
} elseif ($msg === 'updated') {
    $success = 'Supplier updated successfully.';
} elseif ($msg === 'deleted') {
    $success = 'Supplier deleted successfully.';
} elseif ($msg === 'status_changed') {
    $success = 'Supplier status changed successfully.';
}

/* -------------------------------------------------------
   DELETE SUPPLIER
------------------------------------------------------- */
if (isset($_GET['delete']) && (int)$_GET['delete'] > 0) {
    $deleteId = (int)$_GET['delete'];

    if ($supHasBusinessId) {
        $stmt = $conn->prepare("DELETE FROM suppliers WHERE id = ? AND business_id = ? LIMIT 1");
        $stmt->bind_param('ii', $deleteId, $businessId);
    } else {
        $stmt = $conn->prepare("DELETE FROM suppliers WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $deleteId);
    }

    if ($stmt && $stmt->execute()) {
        if (function_exists('addAuditLog')) {
            addAuditLog(
                $conn,
                $businessId,
                $userId,
                'Suppliers',
                'Delete',
                $deleteId,
                'Deleted supplier'
            );
        }
    }

    if ($stmt) {
        $stmt->close();
    }

    header('Location: suppliers.php?msg=deleted');
    exit;
}

/* -------------------------------------------------------
   TOGGLE STATUS
------------------------------------------------------- */
if ($supHasIsActive && isset($_GET['toggle']) && (int)$_GET['toggle'] > 0) {
    $toggleId = (int)$_GET['toggle'];

    if ($supHasBusinessId) {
        $stmt = $conn->prepare("SELECT is_active FROM suppliers WHERE id = ? AND business_id = ? LIMIT 1");
        $stmt->bind_param('ii', $toggleId, $businessId);
    } else {
        $stmt = $conn->prepare("SELECT is_active FROM suppliers WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $toggleId);
    }

    if ($stmt) {
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if ($row) {
            $newStatus = ((int)$row['is_active'] === 1) ? 0 : 1;

            if ($supHasBusinessId) {
                if ($supHasUpdatedAt) {
                    $stmt = $conn->prepare("UPDATE suppliers SET is_active = ?, updated_at = NOW() WHERE id = ? AND business_id = ? LIMIT 1");
                } else {
                    $stmt = $conn->prepare("UPDATE suppliers SET is_active = ? WHERE id = ? AND business_id = ? LIMIT 1");
                }
                $stmt->bind_param('iii', $newStatus, $toggleId, $businessId);
            } else {
                if ($supHasUpdatedAt) {
                    $stmt = $conn->prepare("UPDATE suppliers SET is_active = ?, updated_at = NOW() WHERE id = ? LIMIT 1");
                } else {
                    $stmt = $conn->prepare("UPDATE suppliers SET is_active = ? WHERE id = ? LIMIT 1");
                }
                $stmt->bind_param('ii', $newStatus, $toggleId);
            }

            if ($stmt && $stmt->execute()) {
                if (function_exists('addAuditLog')) {
                    addAuditLog(
                        $conn,
                        $businessId,
                        $userId,
                        'Suppliers',
                        'Status Change',
                        $toggleId,
                        'Changed supplier status'
                    );
                }
            }

            if ($stmt) {
                $stmt->close();
            }
        }
    }

    header('Location: suppliers.php?msg=status_changed');
    exit;
}

/* -------------------------------------------------------
   COUNTS
------------------------------------------------------- */
$totalSuppliers = 0;
$activeSuppliers = 0;
$inactiveSuppliers = 0;

$sql = "SELECT COUNT(*) AS cnt FROM suppliers WHERE 1=1";
if ($supHasBusinessId) {
    $sql .= " AND business_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $businessId);
} else {
    $stmt = $conn->prepare($sql);
}

if ($stmt) {
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $totalSuppliers = (int)($row['cnt'] ?? 0);
    $stmt->close();
}

if ($supHasIsActive) {
    $sql = "SELECT COUNT(*) AS cnt FROM suppliers WHERE is_active = 1";
    if ($supHasBusinessId) {
        $sql .= " AND business_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $businessId);
    } else {
        $stmt = $conn->prepare($sql);
    }
    if ($stmt) {
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $activeSuppliers = (int)($row['cnt'] ?? 0);
        $stmt->close();
    }

    $sql = "SELECT COUNT(*) AS cnt FROM suppliers WHERE is_active = 0";
    if ($supHasBusinessId) {
        $sql .= " AND business_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $businessId);
    } else {
        $stmt = $conn->prepare($sql);
    }
    if ($stmt) {
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $inactiveSuppliers = (int)($row['cnt'] ?? 0);
        $stmt->close();
    }
}

/* -------------------------------------------------------
   FILTERS
------------------------------------------------------- */
$search = trim((string)($_GET['search'] ?? ''));
$status = trim((string)($_GET['status'] ?? 'all'));

$where = " WHERE 1=1 ";
$params = [];
$types = '';

if ($supHasBusinessId) {
    $where .= " AND s.business_id = ? ";
    $params[] = $businessId;
    $types .= 'i';
}

if ($search !== '') {
    $where .= " AND (";
    $searchParts = [];

    if ($supHasSupplierName) {
        $searchParts[] = "s.supplier_name LIKE ?";
        $params[] = '%' . $search . '%';
        $types .= 's';
    }
    if ($supHasSupplierCode) {
        $searchParts[] = "s.supplier_code LIKE ?";
        $params[] = '%' . $search . '%';
        $types .= 's';
    }
    if ($supHasContactPerson) {
        $searchParts[] = "s.contact_person LIKE ?";
        $params[] = '%' . $search . '%';
        $types .= 's';
    }
    if ($supHasMobile) {
        $searchParts[] = "s.mobile LIKE ?";
        $params[] = '%' . $search . '%';
        $types .= 's';
    }
    if ($supHasEmail) {
        $searchParts[] = "s.email LIKE ?";
        $params[] = '%' . $search . '%';
        $types .= 's';
    }
    if ($supHasCity) {
        $searchParts[] = "s.city LIKE ?";
        $params[] = '%' . $search . '%';
        $types .= 's';
    }

    $where .= implode(' OR ', $searchParts) . ")";
}

if ($supHasIsActive) {
    if ($status === 'active') {
        $where .= " AND s.is_active = 1 ";
    } elseif ($status === 'inactive') {
        $where .= " AND s.is_active = 0 ";
    }
}

/* -------------------------------------------------------
   SUPPLIER LIST
------------------------------------------------------- */
$sql = "SELECT s.id";

if ($supHasSupplierCode) {
    $sql .= ", s.supplier_code";
}
if ($supHasSupplierName) {
    $sql .= ", s.supplier_name";
}
if ($supHasContactPerson) {
    $sql .= ", s.contact_person";
}
if ($supHasMobile) {
    $sql .= ", s.mobile";
}
if ($supHasAlternateMobile) {
    $sql .= ", s.alternate_mobile";
}
if ($supHasEmail) {
    $sql .= ", s.email";
}
if ($supHasGstin) {
    $sql .= ", s.gstin";
}
if ($supHasAddress1) {
    $sql .= ", s.address_line1";
}
if ($supHasAddress2) {
    $sql .= ", s.address_line2";
}
if ($supHasCity) {
    $sql .= ", s.city";
}
if ($supHasState) {
    $sql .= ", s.state";
}
if ($supHasPincode) {
    $sql .= ", s.pincode";
}
if ($supHasOpeningBalance) {
    $sql .= ", s.opening_balance";
}
if ($supHasBalanceType) {
    $sql .= ", s.balance_type";
}
if ($supHasNotes) {
    $sql .= ", s.notes";
}
if ($supHasIsActive) {
    $sql .= ", s.is_active";
}
if ($supHasCreatedAt) {
    $sql .= ", s.created_at";
}

$sql .= " FROM suppliers s
          $where
          ORDER BY s.id DESC";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die('Failed to prepare suppliers query.');
}

if (!empty($params)) {
    $bindValues = [];
    $bindValues[] = $types;
    for ($i = 0; $i < count($params); $i++) {
        $bindValues[] = &$params[$i];
    }
    call_user_func_array([$stmt, 'bind_param'], $bindValues);
}

$stmt->execute();
$res = $stmt->get_result();
$suppliers = [];
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $suppliers[] = $row;
    }
}
$stmt->close();
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

                <div class="row">
                    <div class="col-md-4 col-xl-4">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-primary mt-2"><?php echo $totalSuppliers; ?></h3>
                                <p class="text-muted mb-0">Total Suppliers</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4 col-xl-4">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-success mt-2"><?php echo $activeSuppliers; ?></h3>
                                <p class="text-muted mb-0">Active Suppliers</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4 col-xl-4">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-danger mt-2"><?php echo $inactiveSuppliers; ?></h3>
                                <p class="text-muted mb-0">Inactive Suppliers</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <form method="get" class="row g-2 align-items-end">
                            <div class="col-md-5">
                                <label class="form-label">Search</label>
                                <input type="text" name="search" class="form-control" placeholder="Supplier name, code, mobile, email..." value="<?php echo h($search); ?>">
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select">
                                    <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All Status</option>
                                    <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <button type="submit" class="btn btn-primary w-100">Search</button>
                            </div>

                            <div class="col-md-12">
                                <a href="suppliers.php" class="btn btn-secondary">Reset</a>
                                <a href="supplier-add.php" class="btn btn-primary">Add Supplier</a>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Supplier</th>
                                        <th>Contact</th>
                                        <?php if ($supHasGstin): ?><th>GSTIN</th><?php endif; ?>
                                        <?php if ($supHasCity): ?><th>City</th><?php endif; ?>
                                        <?php if ($supHasOpeningBalance): ?><th>Opening Balance</th><?php endif; ?>
                                        <th>Status</th>
                                        <th>Created</th>
                                        <th style="min-width: 210px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($suppliers)): ?>
                                        <?php foreach ($suppliers as $index => $supplier): ?>
                                            <tr>
                                                <td><?php echo $index + 1; ?></td>

                                                <td>
                                                    <strong><?php echo h($supplier['supplier_name'] ?? ''); ?></strong>
                                                    <?php if ($supHasSupplierCode && !empty($supplier['supplier_code'])): ?>
                                                        <br><small class="text-muted"><?php echo h($supplier['supplier_code']); ?></small>
                                                    <?php endif; ?>
                                                    <?php if ($supHasContactPerson && !empty($supplier['contact_person'])): ?>
                                                        <br><small class="text-muted"><?php echo h($supplier['contact_person']); ?></small>
                                                    <?php endif; ?>
                                                </td>

                                                <td>
                                                    <?php if ($supHasMobile && !empty($supplier['mobile'])): ?>
                                                        <div><?php echo h($supplier['mobile']); ?></div>
                                                    <?php endif; ?>
                                                    <?php if ($supHasAlternateMobile && !empty($supplier['alternate_mobile'])): ?>
                                                        <small class="text-muted d-block"><?php echo h($supplier['alternate_mobile']); ?></small>
                                                    <?php endif; ?>
                                                    <?php if ($supHasEmail && !empty($supplier['email'])): ?>
                                                        <small class="text-muted d-block"><?php echo h($supplier['email']); ?></small>
                                                    <?php endif; ?>
                                                </td>

                                                <?php if ($supHasGstin): ?>
                                                    <td><?php echo h($supplier['gstin'] ?? ''); ?></td>
                                                <?php endif; ?>

                                                <?php if ($supHasCity): ?>
                                                    <td>
                                                        <?php
                                                        $cityText = trim((string)($supplier['city'] ?? ''));
                                                        $stateText = $supHasState ? trim((string)($supplier['state'] ?? '')) : '';
                                                        echo h(trim($cityText . ($cityText !== '' && $stateText !== '' ? ', ' : '') . $stateText));
                                                        ?>
                                                    </td>
                                                <?php endif; ?>

                                                <?php if ($supHasOpeningBalance): ?>
                                                    <td>
                                                        <?php
                                                        $bal = (float)($supplier['opening_balance'] ?? 0);
                                                        $balType = $supHasBalanceType ? (string)($supplier['balance_type'] ?? '') : '';
                                                        echo '₹ ' . money($bal);
                                                        if ($balType !== '') {
                                                            echo ' <small class="text-muted">(' . h($balType) . ')</small>';
                                                        }
                                                        ?>
                                                    </td>
                                                <?php endif; ?>

                                                <td>
                                                    <?php if ($supHasIsActive): ?>
                                                        <?php if ((int)($supplier['is_active'] ?? 0) === 1): ?>
                                                            <span class="badge bg-success">Active</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-danger">Inactive</span>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="badge bg-success">Active</span>
                                                    <?php endif; ?>
                                                </td>

                                                <td>
                                                    <?php
                                                    if ($supHasCreatedAt && !empty($supplier['created_at'])) {
                                                        echo date('d-m-Y h:i A', strtotime($supplier['created_at']));
                                                    } else {
                                                        echo '-';
                                                    }
                                                    ?>
                                                </td>

                                                <td>
                                                    <a href="supplier-edit.php?id=<?php echo (int)$supplier['id']; ?>" class="btn btn-sm btn-primary mb-1">Edit</a>

                                                    <?php if ($supHasIsActive): ?>
                                                        <a href="suppliers.php?toggle=<?php echo (int)$supplier['id']; ?>" class="btn btn-sm btn-<?php echo (int)($supplier['is_active'] ?? 0) === 1 ? 'warning' : 'success'; ?> mb-1" onclick="return confirm('Are you sure?');">
                                                            <?php echo (int)($supplier['is_active'] ?? 0) === 1 ? 'Deactivate' : 'Activate'; ?>
                                                        </a>
                                                    <?php endif; ?>

                                                    <a href="suppliers.php?delete=<?php echo (int)$supplier['id']; ?>" class="btn btn-sm btn-danger mb-1" onclick="return confirm('Are you sure you want to delete this supplier?');">Delete</a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="<?php echo 6 + ($supHasGstin ? 1 : 0) + ($supHasCity ? 1 : 0) + ($supHasOpeningBalance ? 1 : 0); ?>" class="text-center text-muted">
                                                No suppliers found.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
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