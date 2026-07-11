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

$pageTitle = 'Customers';

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
if (!in_array($roleName, ['admin', 'manager', 'billing'], true)) {
    die('Access denied.');
}

/* -------------------------------------------------------
   REQUIRED TABLES
------------------------------------------------------- */
if (!tableExists($conn, 'customers')) {
    die('Customers table not found. Please import the SQL first.');
}

/* -------------------------------------------------------
   COLUMN CHECKS
------------------------------------------------------- */
$custHasBusinessId      = hasColumn($conn, 'customers', 'business_id');
$custHasCustomerCode    = hasColumn($conn, 'customers', 'customer_code');
$custHasCustomerName    = hasColumn($conn, 'customers', 'customer_name');
$custHasMobile          = hasColumn($conn, 'customers', 'mobile');
$custHasAlternateMobile = hasColumn($conn, 'customers', 'alternate_mobile');
$custHasEmail           = hasColumn($conn, 'customers', 'email');
$custHasGstin           = hasColumn($conn, 'customers', 'gstin');
$custHasAddress1        = hasColumn($conn, 'customers', 'address_line1');
$custHasAddress2        = hasColumn($conn, 'customers', 'address_line2');
$custHasCity            = hasColumn($conn, 'customers', 'city');
$custHasState           = hasColumn($conn, 'customers', 'state');
$custHasPincode         = hasColumn($conn, 'customers', 'pincode');
$custHasDob             = hasColumn($conn, 'customers', 'date_of_birth');
$custHasAnniversary     = hasColumn($conn, 'customers', 'anniversary_date');
$custHasOpeningBalance  = hasColumn($conn, 'customers', 'opening_balance');
$custHasBalanceType     = hasColumn($conn, 'customers', 'balance_type');
$custHasNotes           = hasColumn($conn, 'customers', 'notes');
$custHasIsActive        = hasColumn($conn, 'customers', 'is_active');
$custHasCreatedAt       = hasColumn($conn, 'customers', 'created_at');
$custHasUpdatedAt       = hasColumn($conn, 'customers', 'updated_at');

/* -------------------------------------------------------
   FLASH MESSAGES
------------------------------------------------------- */
$success = '';
$error = '';

$msg = trim((string)($_GET['msg'] ?? ''));
if ($msg === 'created') {
    $success = 'Customer created successfully.';
} elseif ($msg === 'updated') {
    $success = 'Customer updated successfully.';
} elseif ($msg === 'deleted') {
    $success = 'Customer deleted successfully.';
} elseif ($msg === 'status_changed') {
    $success = 'Customer status changed successfully.';
}

/* -------------------------------------------------------
   DELETE CUSTOMER
------------------------------------------------------- */
if (isset($_GET['delete']) && (int)$_GET['delete'] > 0) {
    $deleteId = (int)$_GET['delete'];

    if ($custHasBusinessId) {
        $stmt = $conn->prepare("DELETE FROM customers WHERE id = ? AND business_id = ? LIMIT 1");
        $stmt->bind_param('ii', $deleteId, $businessId);
    } else {
        $stmt = $conn->prepare("DELETE FROM customers WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $deleteId);
    }

    if ($stmt && $stmt->execute()) {
        if (function_exists('addAuditLog')) {
            addAuditLog(
                $conn,
                $businessId,
                $userId,
                'Customers',
                'Delete',
                $deleteId,
                'Deleted customer'
            );
        }
    }

    if ($stmt) {
        $stmt->close();
    }

    header('Location: customers.php?msg=deleted');
    exit;
}

/* -------------------------------------------------------
   TOGGLE STATUS
------------------------------------------------------- */
if ($custHasIsActive && isset($_GET['toggle']) && (int)$_GET['toggle'] > 0) {
    $toggleId = (int)$_GET['toggle'];

    if ($custHasBusinessId) {
        $stmt = $conn->prepare("SELECT is_active FROM customers WHERE id = ? AND business_id = ? LIMIT 1");
        $stmt->bind_param('ii', $toggleId, $businessId);
    } else {
        $stmt = $conn->prepare("SELECT is_active FROM customers WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $toggleId);
    }

    if ($stmt) {
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if ($row) {
            $newStatus = ((int)$row['is_active'] === 1) ? 0 : 1;

            if ($custHasBusinessId) {
                if ($custHasUpdatedAt) {
                    $stmt = $conn->prepare("UPDATE customers SET is_active = ?, updated_at = NOW() WHERE id = ? AND business_id = ? LIMIT 1");
                } else {
                    $stmt = $conn->prepare("UPDATE customers SET is_active = ? WHERE id = ? AND business_id = ? LIMIT 1");
                }
                $stmt->bind_param('iii', $newStatus, $toggleId, $businessId);
            } else {
                if ($custHasUpdatedAt) {
                    $stmt = $conn->prepare("UPDATE customers SET is_active = ?, updated_at = NOW() WHERE id = ? LIMIT 1");
                } else {
                    $stmt = $conn->prepare("UPDATE customers SET is_active = ? WHERE id = ? LIMIT 1");
                }
                $stmt->bind_param('ii', $newStatus, $toggleId);
            }

            if ($stmt && $stmt->execute()) {
                if (function_exists('addAuditLog')) {
                    addAuditLog(
                        $conn,
                        $businessId,
                        $userId,
                        'Customers',
                        'Status Change',
                        $toggleId,
                        'Changed customer status'
                    );
                }
            }

            if ($stmt) {
                $stmt->close();
            }
        }
    }

    header('Location: customers.php?msg=status_changed');
    exit;
}

/* -------------------------------------------------------
   COUNTS
------------------------------------------------------- */
$totalCustomers = 0;
$activeCustomers = 0;
$inactiveCustomers = 0;

$sql = "SELECT COUNT(*) AS cnt FROM customers WHERE 1=1";
if ($custHasBusinessId) {
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
    $totalCustomers = (int)($row['cnt'] ?? 0);
    $stmt->close();
}

if ($custHasIsActive) {
    $sql = "SELECT COUNT(*) AS cnt FROM customers WHERE is_active = 1";
    if ($custHasBusinessId) {
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
        $activeCustomers = (int)($row['cnt'] ?? 0);
        $stmt->close();
    }

    $sql = "SELECT COUNT(*) AS cnt FROM customers WHERE is_active = 0";
    if ($custHasBusinessId) {
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
        $inactiveCustomers = (int)($row['cnt'] ?? 0);
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

if ($custHasBusinessId) {
    $where .= " AND c.business_id = ? ";
    $params[] = $businessId;
    $types .= 'i';
}

if ($search !== '') {
    $parts = [];

    if ($custHasCustomerName) {
        $parts[] = "c.customer_name LIKE ?";
        $params[] = '%' . $search . '%';
        $types .= 's';
    }
    if ($custHasCustomerCode) {
        $parts[] = "c.customer_code LIKE ?";
        $params[] = '%' . $search . '%';
        $types .= 's';
    }
    if ($custHasMobile) {
        $parts[] = "c.mobile LIKE ?";
        $params[] = '%' . $search . '%';
        $types .= 's';
    }
    if ($custHasAlternateMobile) {
        $parts[] = "c.alternate_mobile LIKE ?";
        $params[] = '%' . $search . '%';
        $types .= 's';
    }
    if ($custHasEmail) {
        $parts[] = "c.email LIKE ?";
        $params[] = '%' . $search . '%';
        $types .= 's';
    }
    if ($custHasCity) {
        $parts[] = "c.city LIKE ?";
        $params[] = '%' . $search . '%';
        $types .= 's';
    }
    if ($custHasGstin) {
        $parts[] = "c.gstin LIKE ?";
        $params[] = '%' . $search . '%';
        $types .= 's';
    }

    if (!empty($parts)) {
        $where .= " AND (" . implode(' OR ', $parts) . ")";
    }
}

if ($custHasIsActive) {
    if ($status === 'active') {
        $where .= " AND c.is_active = 1 ";
    } elseif ($status === 'inactive') {
        $where .= " AND c.is_active = 0 ";
    }
}

/* -------------------------------------------------------
   CUSTOMER LIST
------------------------------------------------------- */
$sql = "SELECT c.id";

if ($custHasCustomerCode) {
    $sql .= ", c.customer_code";
}
if ($custHasCustomerName) {
    $sql .= ", c.customer_name";
}
if ($custHasMobile) {
    $sql .= ", c.mobile";
}
if ($custHasAlternateMobile) {
    $sql .= ", c.alternate_mobile";
}
if ($custHasEmail) {
    $sql .= ", c.email";
}
if ($custHasGstin) {
    $sql .= ", c.gstin";
}
if ($custHasAddress1) {
    $sql .= ", c.address_line1";
}
if ($custHasAddress2) {
    $sql .= ", c.address_line2";
}
if ($custHasCity) {
    $sql .= ", c.city";
}
if ($custHasState) {
    $sql .= ", c.state";
}
if ($custHasPincode) {
    $sql .= ", c.pincode";
}
if ($custHasDob) {
    $sql .= ", c.date_of_birth";
}
if ($custHasAnniversary) {
    $sql .= ", c.anniversary_date";
}
if ($custHasOpeningBalance) {
    $sql .= ", c.opening_balance";
}
if ($custHasBalanceType) {
    $sql .= ", c.balance_type";
}
if ($custHasNotes) {
    $sql .= ", c.notes";
}
if ($custHasIsActive) {
    $sql .= ", c.is_active";
}
if ($custHasCreatedAt) {
    $sql .= ", c.created_at";
}

$sql .= " FROM customers c
          $where
          ORDER BY c.id DESC";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die('Failed to prepare customers query.');
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
$customers = [];
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $customers[] = $row;
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
                                <h3 class="text-primary mt-2"><?php echo $totalCustomers; ?></h3>
                                <p class="text-muted mb-0">Total Customers</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4 col-xl-4">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-success mt-2"><?php echo $activeCustomers; ?></h3>
                                <p class="text-muted mb-0">Active Customers</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4 col-xl-4">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-danger mt-2"><?php echo $inactiveCustomers; ?></h3>
                                <p class="text-muted mb-0">Inactive Customers</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <form method="get" class="row g-2 align-items-end">
                            <div class="col-md-5">
                                <label class="form-label">Search</label>
                                <input type="text" name="search" class="form-control" placeholder="Customer name, code, mobile, email..." value="<?php echo h($search); ?>">
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
                                <a href="customers.php" class="btn btn-secondary">Reset</a>
                                <a href="customer-add.php" class="btn btn-primary">Add Customer</a>
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
                                        <th>Customer</th>
                                        <th>Contact</th>
                                        <?php if ($custHasGstin): ?><th>GSTIN</th><?php endif; ?>
                                        <?php if ($custHasCity): ?><th>City</th><?php endif; ?>
                                        <?php if ($custHasOpeningBalance): ?><th>Opening Balance</th><?php endif; ?>
                                        <th>Status</th>
                                        <th>Created</th>
                                        <th style="min-width: 210px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($customers)): ?>
                                        <?php foreach ($customers as $index => $customer): ?>
                                            <tr>
                                                <td><?php echo $index + 1; ?></td>

                                                <td>
                                                    <strong><?php echo h($customer['customer_name'] ?? ''); ?></strong>
                                                    <?php if ($custHasCustomerCode && !empty($customer['customer_code'])): ?>
                                                        <br><small class="text-muted"><?php echo h($customer['customer_code']); ?></small>
                                                    <?php endif; ?>
                                                </td>

                                                <td>
                                                    <?php if ($custHasMobile && !empty($customer['mobile'])): ?>
                                                        <div><?php echo h($customer['mobile']); ?></div>
                                                    <?php endif; ?>
                                                    <?php if ($custHasAlternateMobile && !empty($customer['alternate_mobile'])): ?>
                                                        <small class="text-muted d-block"><?php echo h($customer['alternate_mobile']); ?></small>
                                                    <?php endif; ?>
                                                    <?php if ($custHasEmail && !empty($customer['email'])): ?>
                                                        <small class="text-muted d-block"><?php echo h($customer['email']); ?></small>
                                                    <?php endif; ?>
                                                </td>

                                                <?php if ($custHasGstin): ?>
                                                    <td><?php echo h($customer['gstin'] ?? ''); ?></td>
                                                <?php endif; ?>

                                                <?php if ($custHasCity): ?>
                                                    <td>
                                                        <?php
                                                        $cityText = trim((string)($customer['city'] ?? ''));
                                                        $stateText = $custHasState ? trim((string)($customer['state'] ?? '')) : '';
                                                        echo h(trim($cityText . ($cityText !== '' && $stateText !== '' ? ', ' : '') . $stateText));
                                                        ?>
                                                    </td>
                                                <?php endif; ?>

                                                <?php if ($custHasOpeningBalance): ?>
                                                    <td>
                                                        <?php
                                                        $bal = (float)($customer['opening_balance'] ?? 0);
                                                        $balType = $custHasBalanceType ? (string)($customer['balance_type'] ?? '') : '';
                                                        echo '₹ ' . money($bal);
                                                        if ($balType !== '') {
                                                            echo ' <small class="text-muted">(' . h($balType) . ')</small>';
                                                        }
                                                        ?>
                                                    </td>
                                                <?php endif; ?>

                                                <td>
                                                    <?php if ($custHasIsActive): ?>
                                                        <?php if ((int)($customer['is_active'] ?? 0) === 1): ?>
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
                                                    if ($custHasCreatedAt && !empty($customer['created_at'])) {
                                                        echo date('d-m-Y h:i A', strtotime($customer['created_at']));
                                                    } else {
                                                        echo '-';
                                                    }
                                                    ?>
                                                </td>

                                                <td>
                                                    <a href="customer-edit.php?id=<?php echo (int)$customer['id']; ?>" class="btn btn-sm btn-primary mb-1">Edit</a>

                                                    <?php if ($custHasIsActive): ?>
                                                        <a href="customers.php?toggle=<?php echo (int)$customer['id']; ?>" class="btn btn-sm btn-<?php echo (int)($customer['is_active'] ?? 0) === 1 ? 'warning' : 'success'; ?> mb-1" onclick="return confirm('Are you sure?');">
                                                            <?php echo (int)($customer['is_active'] ?? 0) === 1 ? 'Deactivate' : 'Activate'; ?>
                                                        </a>
                                                    <?php endif; ?>

                                                    <a href="customers.php?delete=<?php echo (int)$customer['id']; ?>" class="btn btn-sm btn-danger mb-1" onclick="return confirm('Are you sure you want to delete this customer?');">Delete</a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="<?php echo 6 + ($custHasGstin ? 1 : 0) + ($custHasCity ? 1 : 0) + ($custHasOpeningBalance ? 1 : 0); ?>" class="text-center text-muted">
                                                No customers found.
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