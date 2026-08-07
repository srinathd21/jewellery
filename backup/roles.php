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
    function tableExists(mysqli $conn, string $table): bool
    {
        $table = $conn->real_escape_string($table);
        $res = $conn->query("SHOW TABLES LIKE '{$table}'");
        return $res && $res->num_rows > 0;
    }
}

function hasColumn(mysqli $conn, string $table, string $column): bool
{
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    return $res && $res->num_rows > 0;
}

function addAuditLogSafe(mysqli $conn, ?int $businessId, ?int $userId, string $module, string $action, int $refId, string $desc): void
{
    if (function_exists('addAuditLog')) {
        addAuditLog($conn, $businessId, $userId, $module, $action, $refId, $desc);
        return;
    }

    if (!tableExists($conn, 'audit_logs')) {
        return;
    }

    $sql = "INSERT INTO audit_logs (
                business_id, user_id, module_name, action_type, reference_id, description, ip_address, user_agent, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return;
    }

    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

    $stmt->bind_param(
        'iississs',
        $businessId,
        $userId,
        $module,
        $action,
        $refId,
        $desc,
        $ip,
        $ua
    );
    $stmt->execute();
    $stmt->close();
}

$pageTitle = 'Roles';

/* -------------------------------------------------------
   AUTH CHECK
------------------------------------------------------- */
if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    header('Location: ../login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$businessId = isset($_SESSION['business_id']) ? (int)$_SESSION['business_id'] : null;

/* -------------------------------------------------------
   REQUIRED TABLES
------------------------------------------------------- */
if (!tableExists($conn, 'roles')) {
    die('Required table `roles` not found. Please import the SQL first.');
}

$usersTableExists = tableExists($conn, 'users');

/* -------------------------------------------------------
   COLUMN CHECKS
------------------------------------------------------- */
$roleHasName        = hasColumn($conn, 'roles', 'role_name');
$roleHasDescription = hasColumn($conn, 'roles', 'description');

$userHasRoleId      = $usersTableExists && hasColumn($conn, 'users', 'role_id');

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
$currentUser = $res ? $res->fetch_assoc() : null;
$stmt->close();

$currentRoleName = strtolower(trim((string)($currentUser['role_name'] ?? '')));
if (!in_array($currentRoleName, ['super admin', 'admin'], true)) {
    die('Access denied.');
}

/* -------------------------------------------------------
   FLASH
------------------------------------------------------- */
$success = '';
$error = '';

$msg = trim((string)($_GET['msg'] ?? ''));
if ($msg === 'created') {
    $success = 'Role created successfully.';
} elseif ($msg === 'updated') {
    $success = 'Role updated successfully.';
} elseif ($msg === 'deleted') {
    $success = 'Role deleted successfully.';
}

/* -------------------------------------------------------
   DELETE ROLE
------------------------------------------------------- */
if (isset($_GET['delete']) && (int)$_GET['delete'] > 0) {
    $deleteId = (int)$_GET['delete'];

    $checkProtected = $conn->prepare("SELECT id, role_name FROM roles WHERE id = ? LIMIT 1");
    if ($checkProtected) {
        $checkProtected->bind_param('i', $deleteId);
        $checkProtected->execute();
        $res = $checkProtected->get_result();
        $roleRow = $res ? $res->fetch_assoc() : null;
        $checkProtected->close();

        if ($roleRow) {
            $roleName = strtolower(trim((string)($roleRow['role_name'] ?? '')));

            if (in_array($roleName, ['super admin', 'admin'], true)) {
                $error = 'Protected roles cannot be deleted.';
            } else {
                $userCount = 0;
                if ($usersTableExists && $userHasRoleId) {
                    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM users WHERE role_id = ?");
                    if ($stmt) {
                        $stmt->bind_param('i', $deleteId);
                        $stmt->execute();
                        $res = $stmt->get_result();
                        $row = $res ? $res->fetch_assoc() : null;
                        $userCount = (int)($row['cnt'] ?? 0);
                        $stmt->close();
                    }
                }

                if ($userCount > 0) {
                    $error = 'This role is assigned to users. Reassign users before deleting.';
                } else {
                    $stmt = $conn->prepare("DELETE FROM roles WHERE id = ? LIMIT 1");
                    if ($stmt) {
                        $stmt->bind_param('i', $deleteId);
                        if ($stmt->execute()) {
                            addAuditLogSafe(
                                $conn,
                                $businessId,
                                $userId,
                                'Roles',
                                'Delete',
                                $deleteId,
                                'Deleted role: ' . (string)($roleRow['role_name'] ?? '')
                            );
                            $stmt->close();
                            header('Location: roles.php?msg=deleted');
                            exit;
                        }
                        $stmt->close();
                        $error = 'Failed to delete role.';
                    } else {
                        $error = 'Failed to prepare delete query.';
                    }
                }
            }
        } else {
            $error = 'Role not found.';
        }
    }
}

/* -------------------------------------------------------
   CREATE ROLE
------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_role'])) {
    $roleName = trim((string)($_POST['role_name'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));

    if ($roleName === '') {
        $error = 'Role name is required.';
    } else {
        $stmt = $conn->prepare("SELECT id FROM roles WHERE role_name = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $roleName);
            $stmt->execute();
            $res = $stmt->get_result();
            $exists = $res ? $res->fetch_assoc() : null;
            $stmt->close();

            if ($exists) {
                $error = 'Role name already exists.';
            } else {
                $stmt = $conn->prepare("INSERT INTO roles (role_name, description) VALUES (?, ?)");
                if ($stmt) {
                    $stmt->bind_param('ss', $roleName, $description);
                    if ($stmt->execute()) {
                        $newId = (int)$stmt->insert_id;
                        addAuditLogSafe(
                            $conn,
                            $businessId,
                            $userId,
                            'Roles',
                            'Create',
                            $newId,
                            'Created role: ' . $roleName
                        );
                        $stmt->close();
                        header('Location: roles.php?msg=created');
                        exit;
                    }
                    $stmt->close();
                    $error = 'Failed to create role.';
                } else {
                    $error = 'Failed to prepare insert query.';
                }
            }
        } else {
            $error = 'Failed to check duplicate role.';
        }
    }
}

/* -------------------------------------------------------
   UPDATE ROLE
------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_role'])) {
    $editId = (int)($_POST['edit_id'] ?? 0);
    $roleName = trim((string)($_POST['role_name'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));

    if ($editId <= 0) {
        $error = 'Invalid role selected.';
    } elseif ($roleName === '') {
        $error = 'Role name is required.';
    } else {
        $existingRoleName = '';
        $stmt = $conn->prepare("SELECT role_name FROM roles WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $editId);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $existingRoleName = (string)($row['role_name'] ?? '');
            $stmt->close();
        }

        if ($existingRoleName === '') {
            $error = 'Role not found.';
        } else {
            $protected = in_array(strtolower(trim($existingRoleName)), ['super admin', 'admin'], true);

            $stmt = $conn->prepare("SELECT id FROM roles WHERE role_name = ? AND id != ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('si', $roleName, $editId);
                $stmt->execute();
                $res = $stmt->get_result();
                $exists = $res ? $res->fetch_assoc() : null;
                $stmt->close();

                if ($exists) {
                    $error = 'Another role with this name already exists.';
                } else {
                    if ($protected) {
                        $roleName = $existingRoleName;
                    }

                    $stmt = $conn->prepare("UPDATE roles SET role_name = ?, description = ? WHERE id = ? LIMIT 1");
                    if ($stmt) {
                        $stmt->bind_param('ssi', $roleName, $description, $editId);
                        if ($stmt->execute()) {
                            addAuditLogSafe(
                                $conn,
                                $businessId,
                                $userId,
                                'Roles',
                                'Update',
                                $editId,
                                'Updated role: ' . $roleName
                            );
                            $stmt->close();
                            header('Location: roles.php?msg=updated');
                            exit;
                        }
                        $stmt->close();
                        $error = 'Failed to update role.';
                    } else {
                        $error = 'Failed to prepare update query.';
                    }
                }
            } else {
                $error = 'Failed to validate role name.';
            }
        }
    }
}

/* -------------------------------------------------------
   EDIT LOAD
------------------------------------------------------- */
$editRole = null;
if (isset($_GET['edit']) && (int)$_GET['edit'] > 0) {
    $editId = (int)$_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM roles WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $editId);
        $stmt->execute();
        $res = $stmt->get_result();
        $editRole = $res ? $res->fetch_assoc() : null;
        $stmt->close();
    }
}

/* -------------------------------------------------------
   COUNTS
------------------------------------------------------- */
$totalRoles = 0;
$totalUsersAssigned = 0;

$res = $conn->query("SELECT COUNT(*) AS cnt FROM roles");
if ($res && $row = $res->fetch_assoc()) {
    $totalRoles = (int)($row['cnt'] ?? 0);
}

if ($usersTableExists && $userHasRoleId) {
    $res = $conn->query("SELECT COUNT(*) AS cnt FROM users");
    if ($res && $row = $res->fetch_assoc()) {
        $totalUsersAssigned = (int)($row['cnt'] ?? 0);
    }
}

/* -------------------------------------------------------
   SEARCH
------------------------------------------------------- */
$search = trim((string)($_GET['search'] ?? ''));

/* -------------------------------------------------------
   LIST ROLES
------------------------------------------------------- */
$sql = "
    SELECT
        r.id,
        r.role_name,
        r.description
";

if ($usersTableExists && $userHasRoleId) {
    $sql .= ",
        COUNT(u.id) AS users_count
    ";
} else {
    $sql .= ",
        0 AS users_count
    ";
}

$sql .= " FROM roles r ";

if ($usersTableExists && $userHasRoleId) {
    $sql .= " LEFT JOIN users u ON u.role_id = r.id ";
}

$sql .= " WHERE 1=1 ";

$params = [];
$types = '';

if ($search !== '') {
    $sql .= " AND (r.role_name LIKE ? OR r.description LIKE ?) ";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $types .= 'ss';
}

$sql .= " GROUP BY r.id, r.role_name, r.description
          ORDER BY r.id ASC ";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die('Failed to prepare roles query.');
}

if (!empty($params)) {
    $bind = [];
    $bind[] = $types;
    for ($i = 0; $i < count($params); $i++) {
        $bind[] = &$params[$i];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind);
}

$stmt->execute();
$res = $stmt->get_result();
$roles = [];
while ($res && $row = $res->fetch_assoc()) {
    $roles[] = $row;
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
                    <div class="col-md-6 col-xl-6">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-primary mt-2"><?php echo $totalRoles; ?></h3>
                                <p class="text-muted mb-0">Total Roles</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-6">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-success mt-2"><?php echo $totalUsersAssigned; ?></h3>
                                <p class="text-muted mb-0">Total Users Assigned</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-lg-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">
                                    <?php echo $editRole ? 'Edit Role' : 'Add Role'; ?>
                                </h5>
                            </div>
                            <div class="card-body">
                                <form method="post">
                                    <?php if ($editRole): ?>
                                        <input type="hidden" name="edit_id" value="<?php echo (int)$editRole['id']; ?>">
                                    <?php endif; ?>

                                    <div class="mb-3">
                                        <label class="form-label">Role Name</label>
                                        <input
                                            type="text"
                                            name="role_name"
                                            class="form-control"
                                            value="<?php echo h($editRole['role_name'] ?? $_POST['role_name'] ?? ''); ?>"
                                            required
                                            <?php
                                            $protectedEdit = $editRole && in_array(strtolower(trim((string)$editRole['role_name'])), ['super admin', 'admin'], true);
                                            echo $protectedEdit ? 'readonly' : '';
                                            ?>
                                        >
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Description</label>
                                        <textarea
                                            name="description"
                                            class="form-control"
                                            rows="4"
                                            placeholder="Enter role description"
                                        ><?php echo h($editRole['description'] ?? $_POST['description'] ?? ''); ?></textarea>
                                    </div>

                                    <div class="d-flex gap-2 flex-wrap">
                                        <?php if ($editRole): ?>
                                            <button type="submit" name="update_role" value="1" class="btn btn-primary">Update Role</button>
                                            <a href="roles.php" class="btn btn-secondary">Cancel</a>
                                        <?php else: ?>
                                            <button type="submit" name="save_role" value="1" class="btn btn-primary">Save Role</button>
                                            <a href="roles.php" class="btn btn-secondary">Reset</a>
                                        <?php endif; ?>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-8">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Search Roles</h5>
                            </div>
                            <div class="card-body">
                                <form method="get" class="row g-2 align-items-end">
                                    <div class="col-md-10">
                                        <label class="form-label">Search</label>
                                        <input
                                            type="text"
                                            name="search"
                                            class="form-control"
                                            placeholder="Search role name or description..."
                                            value="<?php echo h($search); ?>"
                                        >
                                    </div>

                                    <div class="col-md-2">
                                        <button type="submit" class="btn btn-primary w-100">Search</button>
                                    </div>

                                    <div class="col-md-12">
                                        <a href="roles.php" class="btn btn-secondary">Reset</a>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Roles List</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-bordered table-striped align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Role Name</th>
                                                <th>Description</th>
                                                <th>Users Assigned</th>
                                                <th style="min-width: 180px;">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($roles)): ?>
                                                <?php foreach ($roles as $index => $role): ?>
                                                    <?php
                                                    $roleName = strtolower(trim((string)($role['role_name'] ?? '')));
                                                    $isProtected = in_array($roleName, ['super admin', 'admin'], true);
                                                    ?>
                                                    <tr>
                                                        <td><?php echo $index + 1; ?></td>
                                                        <td>
                                                            <strong><?php echo h($role['role_name'] ?? ''); ?></strong>
                                                            <?php if ($isProtected): ?>
                                                                <br><span class="badge bg-info">Protected</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><?php echo h($role['description'] ?? ''); ?></td>
                                                        <td>
                                                            <span class="badge bg-secondary">
                                                                <?php echo (int)($role['users_count'] ?? 0); ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <a href="roles.php?edit=<?php echo (int)$role['id']; ?>" class="btn btn-sm btn-primary mb-1">
                                                                Edit
                                                            </a>

                                                            <?php if (!$isProtected): ?>
                                                                <a
                                                                    href="roles.php?delete=<?php echo (int)$role['id']; ?>"
                                                                    class="btn btn-sm btn-danger mb-1"
                                                                    onclick="return confirm('Are you sure you want to delete this role?');"
                                                                >
                                                                    Delete
                                                                </a>
                                                            <?php else: ?>
                                                                <button type="button" class="btn btn-sm btn-secondary mb-1" disabled>
                                                                    Delete
                                                                </button>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="5" class="text-center text-muted">
                                                        No roles found.
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <div class="alert alert-warning mt-3 mb-0">
                                    <strong>Note:</strong> Protected roles like <strong>Super Admin</strong> and <strong>Admin</strong> cannot be deleted.
                                </div>
                            </div>
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