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

if (!function_exists('addAuditLog')) {
    function addAuditLog(mysqli $conn, ?int $businessId, ?int $userId, string $module, string $action, ?int $referenceId, string $description): void
    {
        if (!tableExists($conn, 'audit_logs')) {
            return;
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $stmt = $conn->prepare("
            INSERT INTO audit_logs
            (business_id, user_id, module_name, action_type, reference_id, description, ip_address, user_agent, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        if ($stmt) {
            $stmt->bind_param('iississs', $businessId, $userId, $module, $action, $referenceId, $description, $ip, $ua);
            $stmt->execute();
            $stmt->close();
        }
    }
}

$pageTitle = 'Payment Methods';

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
    $businessId = null;
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
   REQUIRED TABLE
------------------------------------------------------- */
if (!tableExists($conn, 'payment_methods')) {
    die('payment_methods table not found. Please import the SQL first.');
}

$hasIsActive = hasColumn($conn, 'payment_methods', 'is_active');

/* -------------------------------------------------------
   FLASH
------------------------------------------------------- */
$success = '';
$error = '';

$msg = trim((string)($_GET['msg'] ?? ''));
if ($msg === 'created') {
    $success = 'Payment method created successfully.';
} elseif ($msg === 'updated') {
    $success = 'Payment method updated successfully.';
} elseif ($msg === 'deleted') {
    $success = 'Payment method deleted successfully.';
} elseif ($msg === 'status_changed') {
    $success = 'Payment method status changed successfully.';
}

/* -------------------------------------------------------
   DELETE
------------------------------------------------------- */
if (isset($_GET['delete']) && (int)$_GET['delete'] > 0) {
    $deleteId = (int)$_GET['delete'];

    $stmt = $conn->prepare("DELETE FROM payment_methods WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $deleteId);
        if ($stmt->execute()) {
            addAuditLog(
                $conn,
                $businessId,
                $userId,
                'Payment Methods',
                'Delete',
                $deleteId,
                'Deleted payment method'
            );
        }
        $stmt->close();
    }

    header('Location: payment-methods.php?msg=deleted');
    exit;
}

/* -------------------------------------------------------
   TOGGLE STATUS
------------------------------------------------------- */
if ($hasIsActive && isset($_GET['toggle']) && (int)$_GET['toggle'] > 0) {
    $toggleId = (int)$_GET['toggle'];

    $stmt = $conn->prepare("SELECT is_active FROM payment_methods WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $toggleId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if ($row) {
            $newStatus = ((int)$row['is_active'] === 1) ? 0 : 1;

            $stmt = $conn->prepare("UPDATE payment_methods SET is_active = ? WHERE id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('ii', $newStatus, $toggleId);
                if ($stmt->execute()) {
                    addAuditLog(
                        $conn,
                        $businessId,
                        $userId,
                        'Payment Methods',
                        'Status Change',
                        $toggleId,
                        'Changed payment method status'
                    );
                }
                $stmt->close();
            }
        }
    }

    header('Location: payment-methods.php?msg=status_changed');
    exit;
}

/* -------------------------------------------------------
   ADD / UPDATE
------------------------------------------------------- */
$editId = (int)($_GET['edit'] ?? 0);
$methodName = '';
$isActive = 1;

if ($editId > 0) {
    $stmt = $conn->prepare("SELECT * FROM payment_methods WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $editId);
        $stmt->execute();
        $res = $stmt->get_result();
        $editRow = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if ($editRow) {
            $methodName = (string)($editRow['method_name'] ?? '');
            $isActive = (int)($editRow['is_active'] ?? 1);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_method'])) {
    $editId = (int)($_POST['edit_id'] ?? 0);
    $methodName = trim((string)($_POST['method_name'] ?? ''));
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if ($methodName === '') {
        $error = 'Payment method name is required.';
    } else {
        if ($editId > 0) {
            $stmt = $conn->prepare("SELECT id FROM payment_methods WHERE method_name = ? AND id != ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('si', $methodName, $editId);
                $stmt->execute();
                $res = $stmt->get_result();
                $exists = $res && $res->num_rows > 0;
                $stmt->close();

                if ($exists) {
                    $error = 'Payment method already exists.';
                } else {
                    if ($hasIsActive) {
                        $stmt = $conn->prepare("UPDATE payment_methods SET method_name = ?, is_active = ? WHERE id = ? LIMIT 1");
                        $stmt->bind_param('sii', $methodName, $isActive, $editId);
                    } else {
                        $stmt = $conn->prepare("UPDATE payment_methods SET method_name = ? WHERE id = ? LIMIT 1");
                        $stmt->bind_param('si', $methodName, $editId);
                    }

                    if ($stmt && $stmt->execute()) {
                        addAuditLog(
                            $conn,
                            $businessId,
                            $userId,
                            'Payment Methods',
                            'Update',
                            $editId,
                            'Updated payment method ' . $methodName
                        );
                        $stmt->close();
                        header('Location: payment-methods.php?msg=updated');
                        exit;
                    } else {
                        $error = 'Failed to update payment method.';
                    }

                    if ($stmt) {
                        $stmt->close();
                    }
                }
            }
        } else {
            $stmt = $conn->prepare("SELECT id FROM payment_methods WHERE method_name = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('s', $methodName);
                $stmt->execute();
                $res = $stmt->get_result();
                $exists = $res && $res->num_rows > 0;
                $stmt->close();

                if ($exists) {
                    $error = 'Payment method already exists.';
                } else {
                    if ($hasIsActive) {
                        $stmt = $conn->prepare("INSERT INTO payment_methods (method_name, is_active) VALUES (?, ?)");
                        $stmt->bind_param('si', $methodName, $isActive);
                    } else {
                        $stmt = $conn->prepare("INSERT INTO payment_methods (method_name) VALUES (?)");
                        $stmt->bind_param('s', $methodName);
                    }

                    if ($stmt && $stmt->execute()) {
                        $newId = (int)$stmt->insert_id;
                        addAuditLog(
                            $conn,
                            $businessId,
                            $userId,
                            'Payment Methods',
                            'Create',
                            $newId,
                            'Created payment method ' . $methodName
                        );
                        $stmt->close();
                        header('Location: payment-methods.php?msg=created');
                        exit;
                    } else {
                        $error = 'Failed to create payment method.';
                    }

                    if ($stmt) {
                        $stmt->close();
                    }
                }
            }
        }
    }
}

/* -------------------------------------------------------
   SUMMARY
------------------------------------------------------- */
$totalMethods = 0;
$activeMethods = 0;
$inactiveMethods = 0;

$res = $conn->query("SELECT COUNT(*) AS cnt FROM payment_methods");
if ($res) {
    $row = $res->fetch_assoc();
    $totalMethods = (int)($row['cnt'] ?? 0);
}

if ($hasIsActive) {
    $res = $conn->query("SELECT COUNT(*) AS cnt FROM payment_methods WHERE is_active = 1");
    if ($res) {
        $row = $res->fetch_assoc();
        $activeMethods = (int)($row['cnt'] ?? 0);
    }

    $res = $conn->query("SELECT COUNT(*) AS cnt FROM payment_methods WHERE is_active = 0");
    if ($res) {
        $row = $res->fetch_assoc();
        $inactiveMethods = (int)($row['cnt'] ?? 0);
    }
} else {
    $activeMethods = $totalMethods;
}

/* -------------------------------------------------------
   SEARCH + LIST
------------------------------------------------------- */
$search = trim((string)($_GET['search'] ?? ''));
$status = trim((string)($_GET['status'] ?? 'all'));

$sql = "SELECT * FROM payment_methods WHERE 1=1";
$params = [];
$types = '';

if ($search !== '') {
    $sql .= " AND method_name LIKE ?";
    $params[] = '%' . $search . '%';
    $types .= 's';
}

if ($hasIsActive) {
    if ($status === 'active') {
        $sql .= " AND is_active = 1";
    } elseif ($status === 'inactive') {
        $sql .= " AND is_active = 0";
    }
}

$sql .= " ORDER BY id ASC";

$methods = [];
$stmt = $conn->prepare($sql);
if ($stmt) {
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
    while ($res && $row = $res->fetch_assoc()) {
        $methods[] = $row;
    }
    $stmt->close();
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

                <div class="row">
                    <div class="col-md-4">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-primary mt-2"><?php echo $totalMethods; ?></h3>
                                <p class="text-muted mb-0">Total Methods</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-success mt-2"><?php echo $activeMethods; ?></h3>
                                <p class="text-muted mb-0">Active Methods</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-danger mt-2"><?php echo $inactiveMethods; ?></h3>
                                <p class="text-muted mb-0">Inactive Methods</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4"><?php echo $editId > 0 ? 'Edit Payment Method' : 'Add Payment Method'; ?></h4>

                        <form method="post" class="row g-3">
                            <input type="hidden" name="save_method" value="1">
                            <input type="hidden" name="edit_id" value="<?php echo (int)$editId; ?>">

                            <div class="col-md-6">
                                <label class="form-label">Method Name</label>
                                <input type="text" name="method_name" class="form-control" value="<?php echo h($methodName); ?>" placeholder="Cash, UPI, Card..." required>
                            </div>

                            <?php if ($hasIsActive): ?>
                                <div class="col-md-3 d-flex align-items-end">
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" name="is_active" id="is_active" value="1" <?php echo (int)$isActive === 1 ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="is_active">
                                            Active Method
                                        </label>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="col-md-3 d-flex align-items-end gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <?php echo $editId > 0 ? 'Update' : 'Save'; ?>
                                </button>
                                <?php if ($editId > 0): ?>
                                    <a href="payment-methods.php" class="btn btn-secondary">Cancel</a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <form method="get" class="row g-2 align-items-end">
                            <div class="col-md-5">
                                <label class="form-label">Search</label>
                                <input type="text" name="search" class="form-control" placeholder="Search method name..." value="<?php echo h($search); ?>">
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select">
                                    <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All</option>
                                    <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <button type="submit" class="btn btn-primary w-100">Search</button>
                            </div>

                            <div class="col-md-2">
                                <a href="payment-methods.php" class="btn btn-secondary w-100">Reset</a>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">Payment Method List</h4>

                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Method Name</th>
                                        <th>Status</th>
                                        <th style="min-width: 220px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($methods)): ?>
                                        <?php foreach ($methods as $index => $method): ?>
                                            <tr>
                                                <td><?php echo $index + 1; ?></td>
                                                <td><strong><?php echo h($method['method_name'] ?? ''); ?></strong></td>
                                                <td>
                                                    <?php if ($hasIsActive): ?>
                                                        <?php if ((int)($method['is_active'] ?? 0) === 1): ?>
                                                            <span class="badge bg-success">Active</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-danger">Inactive</span>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="badge bg-success">Active</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <a href="payment-methods.php?edit=<?php echo (int)$method['id']; ?>" class="btn btn-sm btn-primary">Edit</a>

                                                    <?php if ($hasIsActive): ?>
                                                        <a href="payment-methods.php?toggle=<?php echo (int)$method['id']; ?>" class="btn btn-sm btn-<?php echo (int)($method['is_active'] ?? 0) === 1 ? 'warning' : 'success'; ?>" onclick="return confirm('Are you sure?');">
                                                            <?php echo (int)($method['is_active'] ?? 0) === 1 ? 'Deactivate' : 'Activate'; ?>
                                                        </a>
                                                    <?php endif; ?>

                                                    <a href="payment-methods.php?delete=<?php echo (int)$method['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this method?');">
                                                        Delete
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4" class="text-center text-muted">No payment methods found.</td>
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