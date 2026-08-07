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

$pageTitle = 'Users';

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
if (!tableExists($conn, 'users') || !tableExists($conn, 'roles') || !tableExists($conn, 'businesses')) {
    die('Required tables not found. Please import the SQL first.');
}

/* -------------------------------------------------------
   HELPERS
------------------------------------------------------- */
function roleAllowedForBusiness(mysqli $conn, int $roleId): bool
{
    $stmt = $conn->prepare("
        SELECT id
        FROM roles
        WHERE id = ?
          AND LOWER(role_name) NOT IN ('super admin', 'super_admin', 'superadmin')
        LIMIT 1
    ");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('i', $roleId);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok = $res && $res->num_rows > 0;
    $stmt->close();

    return $ok;
}

function usernameExistsExcept(mysqli $conn, string $username, int $excludeId = 0): bool
{
    if ($excludeId > 0) {
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ? LIMIT 1");
        $stmt->bind_param('si', $username, $excludeId);
    } else {
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $stmt->bind_param('s', $username);
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

function getBusinessRoles(mysqli $conn): array
{
    $rows = [];
    $res = $conn->query("
        SELECT id, role_name
        FROM roles
        WHERE LOWER(role_name) NOT IN ('super admin', 'super_admin', 'superadmin')
        ORDER BY id ASC
    ");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    return $rows;
}

/* -------------------------------------------------------
   FLASH MESSAGE
------------------------------------------------------- */
$success = '';
$error = '';

$msg = trim((string)($_GET['msg'] ?? ''));
if ($msg === 'created') {
    $success = 'User created successfully.';
} elseif ($msg === 'updated') {
    $success = 'User updated successfully.';
} elseif ($msg === 'deleted') {
    $success = 'User deleted successfully.';
} elseif ($msg === 'status_changed') {
    $success = 'User status changed successfully.';
} elseif ($msg === 'password_reset') {
    $success = 'Password reset successfully.';
}

/* -------------------------------------------------------
   FETCH BUSINESS
------------------------------------------------------- */
$stmt = $conn->prepare("SELECT business_name, business_code FROM businesses WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $businessId);
$stmt->execute();
$res = $stmt->get_result();
$business = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$business) {
    die('Business not found.');
}

/* -------------------------------------------------------
   DELETE USER
------------------------------------------------------- */
if (isset($_GET['delete']) && (int)$_GET['delete'] > 0) {
    $deleteId = (int)$_GET['delete'];

    if ($deleteId === $userId) {
        header('Location: users.php');
        exit;
    }

    $stmt = $conn->prepare("
        SELECT u.id, u.full_name, u.role_id, r.role_name
        FROM users u
        INNER JOIN roles r ON r.id = u.role_id
        WHERE u.id = ? AND u.business_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('ii', $deleteId, $businessId);
    $stmt->execute();
    $res = $stmt->get_result();
    $deleteUser = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if ($deleteUser) {
        $deleteRole = strtolower(trim((string)($deleteUser['role_name'] ?? '')));
        if (!in_array($deleteRole, ['super admin', 'super_admin', 'superadmin'], true)) {
            $stmt = $conn->prepare("DELETE FROM users WHERE id = ? AND business_id = ? LIMIT 1");
            $stmt->bind_param('ii', $deleteId, $businessId);
            if ($stmt->execute()) {
                if (function_exists('addAuditLog')) {
                    addAuditLog(
                        $conn,
                        $businessId,
                        $userId,
                        'Users',
                        'Delete',
                        $deleteId,
                        'Deleted user ' . ($deleteUser['full_name'] ?? '')
                    );
                }
            }
            $stmt->close();
        }
    }

    header('Location: users.php?msg=deleted');
    exit;
}

/* -------------------------------------------------------
   TOGGLE STATUS
------------------------------------------------------- */
if (isset($_GET['toggle']) && (int)$_GET['toggle'] > 0) {
    $toggleId = (int)$_GET['toggle'];

    if ($toggleId === $userId) {
        header('Location: users.php');
        exit;
    }

    $stmt = $conn->prepare("
        SELECT u.id, u.full_name, u.is_active, r.role_name
        FROM users u
        INNER JOIN roles r ON r.id = u.role_id
        WHERE u.id = ? AND u.business_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('ii', $toggleId, $businessId);
    $stmt->execute();
    $res = $stmt->get_result();
    $toggleUser = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if ($toggleUser) {
        $toggleRole = strtolower(trim((string)($toggleUser['role_name'] ?? '')));
        if (!in_array($toggleRole, ['super admin', 'super_admin', 'superadmin'], true)) {
            $newStatus = ((int)$toggleUser['is_active'] === 1) ? 0 : 1;

            $stmt = $conn->prepare("UPDATE users SET is_active = ?, updated_at = NOW() WHERE id = ? AND business_id = ? LIMIT 1");
            $stmt->bind_param('iii', $newStatus, $toggleId, $businessId);
            if ($stmt->execute()) {
                if (function_exists('addAuditLog')) {
                    addAuditLog(
                        $conn,
                        $businessId,
                        $userId,
                        'Users',
                        'Status Change',
                        $toggleId,
                        'Changed status for user ' . ($toggleUser['full_name'] ?? '')
                    );
                }
            }
            $stmt->close();
        }
    }

    header('Location: users.php?msg=status_changed');
    exit;
}

/* -------------------------------------------------------
   RESET PASSWORD
------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_password') {
    $resetUserId = (int)($_POST['reset_user_id'] ?? 0);
    $newPassword = (string)($_POST['new_password'] ?? '');

    if ($resetUserId <= 0) {
        $error = 'Invalid user selected.';
    } elseif ($newPassword === '' || strlen($newPassword) < 6) {
        $error = 'New password must be at least 6 characters.';
    } else {
        $stmt = $conn->prepare("
            SELECT u.id, u.full_name, r.role_name
            FROM users u
            INNER JOIN roles r ON r.id = u.role_id
            WHERE u.id = ? AND u.business_id = ?
            LIMIT 1
        ");
        $stmt->bind_param('ii', $resetUserId, $businessId);
        $stmt->execute();
        $res = $stmt->get_result();
        $resetUser = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!$resetUser) {
            $error = 'User not found.';
        } else {
            $resetRole = strtolower(trim((string)($resetUser['role_name'] ?? '')));
            if (in_array($resetRole, ['super admin', 'super_admin', 'superadmin'], true)) {
                $error = 'Invalid role selected.';
            } else {
                $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT);

                $stmt = $conn->prepare("
                    UPDATE users
                    SET password_hash = ?, updated_at = NOW()
                    WHERE id = ? AND business_id = ?
                    LIMIT 1
                ");
                $stmt->bind_param('sii', $passwordHash, $resetUserId, $businessId);

                if ($stmt->execute()) {
                    if (function_exists('addAuditLog')) {
                        addAuditLog(
                            $conn,
                            $businessId,
                            $userId,
                            'Users',
                            'Reset Password',
                            $resetUserId,
                            'Reset password for user ' . ($resetUser['full_name'] ?? '')
                        );
                    }
                    header('Location: users.php?msg=password_reset');
                    exit;
                } else {
                    $error = 'Failed to reset password.';
                }
                $stmt->close();
            }
        }
    }
}

/* -------------------------------------------------------
   EDIT LOAD
------------------------------------------------------- */
$editMode = false;
$editId = (int)($_GET['edit'] ?? 0);

$fullName = '';
$username = '';
$email = '';
$mobile = '';
$roleId = 0;
$isActive = 1;

if ($editId > 0) {
    $stmt = $conn->prepare("
        SELECT u.id, u.full_name, u.username, u.email, u.mobile, u.role_id, u.is_active, r.role_name
        FROM users u
        INNER JOIN roles r ON r.id = u.role_id
        WHERE u.id = ? AND u.business_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('ii', $editId, $businessId);
    $stmt->execute();
    $res = $stmt->get_result();
    $editUser = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if ($editUser) {
        $editMode = true;
        $fullName = (string)($editUser['full_name'] ?? '');
        $username = (string)($editUser['username'] ?? '');
        $email = (string)($editUser['email'] ?? '');
        $mobile = (string)($editUser['mobile'] ?? '');
        $roleId = (int)($editUser['role_id'] ?? 0);
        $isActive = (int)($editUser['is_active'] ?? 1);
    }
}

/* -------------------------------------------------------
   SAVE USER
------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_user') {
    $editId = (int)($_POST['edit_id'] ?? 0);
    $editMode = $editId > 0;

    $fullName = trim((string)($_POST['full_name'] ?? ''));
    $username = trim((string)($_POST['username'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $mobile = trim((string)($_POST['mobile'] ?? ''));
    $roleId = (int)($_POST['role_id'] ?? 0);
    $password = (string)($_POST['password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if ($fullName === '') {
        $error = 'Full name is required.';
    } elseif ($username === '') {
        $error = 'Username is required.';
    } elseif ($roleId <= 0) {
        $error = 'Please select a role.';
    } elseif (!roleAllowedForBusiness($conn, $roleId)) {
        $error = 'Invalid role selected.';
    } elseif (usernameExistsExcept($conn, $username, $editMode ? $editId : 0)) {
        $error = 'Username already exists.';
    } else {
        if (!$editMode) {
            if ($password === '') {
                $error = 'Password is required.';
            } elseif (strlen($password) < 6) {
                $error = 'Password must be at least 6 characters.';
            } elseif ($password !== $confirmPassword) {
                $error = 'Password and confirm password do not match.';
            } else {
                $passwordHash = password_hash($password, PASSWORD_BCRYPT);

                $stmt = $conn->prepare("
                    INSERT INTO users (
                        business_id,
                        role_id,
                        full_name,
                        username,
                        password_hash,
                        mobile,
                        email,
                        is_active,
                        created_at,
                        updated_at
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()
                    )
                ");

                if ($stmt) {
                    $stmt->bind_param(
                        'iisssssi',
                        $businessId,
                        $roleId,
                        $fullName,
                        $username,
                        $passwordHash,
                        $mobile,
                        $email,
                        $isActive
                    );

                    if ($stmt->execute()) {
                        $newUserId = (int)$stmt->insert_id;

                        if (function_exists('addAuditLog')) {
                            addAuditLog(
                                $conn,
                                $businessId,
                                $userId,
                                'Users',
                                'Create',
                                $newUserId,
                                'Created user ' . $fullName
                            );
                        }

                        $stmt->close();
                        header('Location: users.php?msg=created');
                        exit;
                    } else {
                        $error = 'Failed to create user.';
                    }
                    $stmt->close();
                } else {
                    $error = 'Failed to prepare insert query.';
                }
            }
        } else {
            if ($password !== '' && strlen($password) < 6) {
                $error = 'Password must be at least 6 characters.';
            } elseif ($password !== '' && $password !== $confirmPassword) {
                $error = 'Password and confirm password do not match.';
            } else {
                $stmt = $conn->prepare("
                    UPDATE users
                    SET
                        role_id = ?,
                        full_name = ?,
                        username = ?,
                        mobile = ?,
                        email = ?,
                        is_active = ?,
                        updated_at = NOW()
                    WHERE id = ? AND business_id = ?
                    LIMIT 1
                ");

                if ($stmt) {
                    $stmt->bind_param(
                        'issssiii',
                        $roleId,
                        $fullName,
                        $username,
                        $mobile,
                        $email,
                        $isActive,
                        $editId,
                        $businessId
                    );

                    if ($stmt->execute()) {
                        $stmt->close();

                        if ($password !== '') {
                            $passwordHash = password_hash($password, PASSWORD_BCRYPT);
                            $stmt2 = $conn->prepare("
                                UPDATE users
                                SET password_hash = ?, updated_at = NOW()
                                WHERE id = ? AND business_id = ?
                                LIMIT 1
                            ");
                            if ($stmt2) {
                                $stmt2->bind_param('sii', $passwordHash, $editId, $businessId);
                                $stmt2->execute();
                                $stmt2->close();
                            }
                        }

                        if (function_exists('addAuditLog')) {
                            addAuditLog(
                                $conn,
                                $businessId,
                                $userId,
                                'Users',
                                'Update',
                                $editId,
                                'Updated user ' . $fullName
                            );
                        }

                        if ($editId === $userId) {
                            $_SESSION['full_name'] = $fullName;
                            $_SESSION['username'] = $username;
                            $_SESSION['email'] = $email;
                            $_SESSION['mobile'] = $mobile;
                        }

                        header('Location: users.php?msg=updated');
                        exit;
                    } else {
                        $error = 'Failed to update user.';
                    }
                    $stmt->close();
                } else {
                    $error = 'Failed to prepare update query.';
                }
            }
        }
    }
}

/* -------------------------------------------------------
   FILTERS
------------------------------------------------------- */
$search = trim((string)($_GET['search'] ?? ''));
$status = trim((string)($_GET['status'] ?? 'all'));
$roleFilter = trim((string)($_GET['role_filter'] ?? 'all'));

$where = " WHERE u.business_id = ? ";
$params = [$businessId];
$types = 'i';

if ($search !== '') {
    $where .= " AND (
        u.full_name LIKE ?
        OR u.username LIKE ?
        OR u.email LIKE ?
        OR u.mobile LIKE ?
    ) ";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'ssss';
}

if ($status === 'active') {
    $where .= " AND u.is_active = 1 ";
} elseif ($status === 'inactive') {
    $where .= " AND u.is_active = 0 ";
}

if ($roleFilter !== '' && $roleFilter !== 'all') {
    $where .= " AND LOWER(r.role_name) = ? ";
    $params[] = strtolower($roleFilter);
    $types .= 's';
}

/* -------------------------------------------------------
   COUNTS
------------------------------------------------------- */
$totalUsers = 0;
$activeUsers = 0;
$inactiveUsers = 0;

$stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM users WHERE business_id = ?");
$stmt->bind_param('i', $businessId);
$stmt->execute();
$res = $stmt->get_result();
$row = $res ? $res->fetch_assoc() : null;
$totalUsers = (int)($row['cnt'] ?? 0);
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM users WHERE business_id = ? AND is_active = 1");
$stmt->bind_param('i', $businessId);
$stmt->execute();
$res = $stmt->get_result();
$row = $res ? $res->fetch_assoc() : null;
$activeUsers = (int)($row['cnt'] ?? 0);
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM users WHERE business_id = ? AND is_active = 0");
$stmt->bind_param('i', $businessId);
$stmt->execute();
$res = $stmt->get_result();
$row = $res ? $res->fetch_assoc() : null;
$inactiveUsers = (int)($row['cnt'] ?? 0);
$stmt->close();

/* -------------------------------------------------------
   ROLE OPTIONS
------------------------------------------------------- */
$roles = getBusinessRoles($conn);

/* -------------------------------------------------------
   USER LIST
------------------------------------------------------- */
$sql = "
    SELECT
        u.id,
        u.full_name,
        u.username,
        u.email,
        u.mobile,
        u.is_active,
        u.last_login_at,
        u.created_at,
        u.role_id,
        r.role_name
    FROM users u
    INNER JOIN roles r ON r.id = u.role_id
    $where
    ORDER BY u.id DESC
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die('Failed to prepare user list query.');
}

$bindValues = [];
$bindValues[] = $types;
for ($i = 0; $i < count($params); $i++) {
    $bindValues[] = &$params[$i];
}
call_user_func_array([$stmt, 'bind_param'], $bindValues);

$stmt->execute();
$res = $stmt->get_result();
$users = [];
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $users[] = $row;
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
                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-primary mt-2"><?php echo $totalUsers; ?></h3>
                                <p class="text-muted mb-0">Total Users</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-success mt-2"><?php echo $activeUsers; ?></h3>
                                <p class="text-muted mb-0">Active Users</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-danger mt-2"><?php echo $inactiveUsers; ?></h3>
                                <p class="text-muted mb-0">Inactive Users</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h5 class="text-dark mt-2 mb-1"><?php echo h($business['business_name'] ?? ''); ?></h5>
                                <p class="text-muted mb-0"><?php echo h($business['business_code'] ?? ''); ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-xl-4">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4"><?php echo $editMode ? 'Edit User' : 'Add User'; ?></h4>

                                <form method="post">
                                    <input type="hidden" name="action" value="save_user">
                                    <input type="hidden" name="edit_id" value="<?php echo (int)$editId; ?>">

                                    <div class="mb-3">
                                        <label class="form-label">Full Name <span class="text-danger">*</span></label>
                                        <input type="text" name="full_name" class="form-control" value="<?php echo h($fullName); ?>" required>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Username <span class="text-danger">*</span></label>
                                        <input type="text" name="username" class="form-control" value="<?php echo h($username); ?>" required>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Email</label>
                                        <input type="email" name="email" class="form-control" value="<?php echo h($email); ?>">
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Mobile</label>
                                        <input type="text" name="mobile" class="form-control" value="<?php echo h($mobile); ?>">
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Role <span class="text-danger">*</span></label>
                                        <select name="role_id" class="form-select" required>
                                            <option value="">Select Role</option>
                                            <?php foreach ($roles as $role): ?>
                                                <option value="<?php echo (int)$role['id']; ?>" <?php echo $roleId === (int)$role['id'] ? 'selected' : ''; ?>>
                                                    <?php echo h($role['role_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label"><?php echo $editMode ? 'New Password (optional)' : 'Password *'; ?></label>
                                        <input type="password" name="password" class="form-control" <?php echo $editMode ? '' : 'required'; ?>>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label"><?php echo $editMode ? 'Confirm New Password' : 'Confirm Password *'; ?></label>
                                        <input type="password" name="confirm_password" class="form-control" <?php echo $editMode ? '' : 'required'; ?>>
                                    </div>

                                    <div class="form-check mb-3">
                                        <input class="form-check-input" type="checkbox" name="is_active" id="is_active" value="1" <?php echo (int)$isActive === 1 ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="is_active">
                                            Active User
                                        </label>
                                    </div>

                                    <div class="d-grid gap-2">
                                        <button type="submit" class="btn btn-primary waves-effect waves-light">
                                            <?php echo $editMode ? 'Update User' : 'Create User'; ?>
                                        </button>

                                        <?php if ($editMode): ?>
                                            <a href="users.php" class="btn btn-secondary waves-effect">Cancel Edit</a>
                                        <?php else: ?>
                                            <a href="user-add.php" class="btn btn-secondary waves-effect">Open Add Page</a>
                                        <?php endif; ?>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Reset Password</h4>

                                <form method="post">
                                    <input type="hidden" name="action" value="reset_password">

                                    <div class="mb-3">
                                        <label class="form-label">User</label>
                                        <select name="reset_user_id" class="form-select" required>
                                            <option value="">Select User</option>
                                            <?php foreach ($users as $u): ?>
                                                <option value="<?php echo (int)$u['id']; ?>">
                                                    <?php echo h($u['full_name']); ?> (<?php echo h($u['username']); ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">New Password</label>
                                        <input type="password" name="new_password" class="form-control" required>
                                    </div>

                                    <div class="d-grid">
                                        <button type="submit" class="btn btn-warning waves-effect waves-light">
                                            Reset Password
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-8">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
                                    <h4 class="card-title mb-0">Users List</h4>
                                    <a href="user-add.php" class="btn btn-primary waves-effect waves-light">Add User</a>
                                </div>

                                <form method="get" class="row g-2 mb-4">
                                    <div class="col-md-4">
                                        <input type="text" name="search" class="form-control" placeholder="Search user..." value="<?php echo h($search); ?>">
                                    </div>

                                    <div class="col-md-3">
                                        <select name="role_filter" class="form-select">
                                            <option value="all">All Roles</option>
                                            <?php foreach ($roles as $role): ?>
                                                <option value="<?php echo h(strtolower($role['role_name'])); ?>" <?php echo strtolower($roleFilter) === strtolower($role['role_name']) ? 'selected' : ''; ?>>
                                                    <?php echo h($role['role_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="col-md-3">
                                        <select name="status" class="form-select">
                                            <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All Status</option>
                                            <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                                            <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                        </select>
                                    </div>

                                    <div class="col-md-2">
                                        <button type="submit" class="btn btn-primary w-100">Search</button>
                                    </div>
                                </form>

                                <div class="table-responsive">
                                    <table class="table table-bordered table-striped align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>User</th>
                                                <th>Role</th>
                                                <th>Contact</th>
                                                <th>Status</th>
                                                <th>Last Login</th>
                                                <th>Created</th>
                                                <th style="min-width: 230px;">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($users)): ?>
                                                <?php foreach ($users as $index => $u): ?>
                                                    <tr>
                                                        <td><?php echo $index + 1; ?></td>
                                                        <td>
                                                            <strong><?php echo h($u['full_name']); ?></strong><br>
                                                            <small class="text-muted"><?php echo h($u['username']); ?></small>
                                                        </td>
                                                        <td><?php echo h($u['role_name']); ?></td>
                                                        <td>
                                                            <?php echo h($u['mobile']); ?><br>
                                                            <small class="text-muted"><?php echo h($u['email']); ?></small>
                                                        </td>
                                                        <td>
                                                            <?php if ((int)$u['is_active'] === 1): ?>
                                                                <span class="badge bg-success">Active</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-danger">Inactive</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php
                                                            echo !empty($u['last_login_at'])
                                                                ? date('d-m-Y h:i A', strtotime($u['last_login_at']))
                                                                : '<span class="text-muted">Never</span>';
                                                            ?>
                                                        </td>
                                                        <td>
                                                            <?php
                                                            echo !empty($u['created_at'])
                                                                ? date('d-m-Y h:i A', strtotime($u['created_at']))
                                                                : '-';
                                                            ?>
                                                        </td>
                                                        <td>
                                                            <a href="users.php?edit=<?php echo (int)$u['id']; ?>" class="btn btn-sm btn-primary mb-1">Edit</a>

                                                            <?php if ((int)$u['id'] !== $userId): ?>
                                                                <a href="users.php?toggle=<?php echo (int)$u['id']; ?>" class="btn btn-sm btn-<?php echo (int)$u['is_active'] === 1 ? 'warning' : 'success'; ?> mb-1" onclick="return confirm('Are you sure?');">
                                                                    <?php echo (int)$u['is_active'] === 1 ? 'Deactivate' : 'Activate'; ?>
                                                                </a>

                                                                <a href="users.php?delete=<?php echo (int)$u['id']; ?>" class="btn btn-sm btn-danger mb-1" onclick="return confirm('Are you sure you want to delete this user?');">
                                                                    Delete
                                                                </a>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="8" class="text-center text-muted">No users found.</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
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