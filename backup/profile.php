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

$pageTitle = 'Profile';

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
   REQUIRED TABLES
------------------------------------------------------- */
if (!tableExists($conn, 'users') || !tableExists($conn, 'roles') || !tableExists($conn, 'businesses')) {
    die('Required tables not found. Please import the SQL first.');
}

/* -------------------------------------------------------
   FETCH PROFILE
------------------------------------------------------- */
$stmt = $conn->prepare("
    SELECT
        u.id,
        u.business_id,
        u.role_id,
        u.full_name,
        u.username,
        u.email,
        u.mobile,
        u.is_active,
        u.last_login_at,
        u.created_at,
        r.role_name,
        b.business_name,
        b.business_code
    FROM users u
    INNER JOIN roles r ON r.id = u.role_id
    LEFT JOIN businesses b ON b.id = u.business_id
    WHERE u.id = ?
    LIMIT 1
");
$stmt->bind_param('i', $userId);
$stmt->execute();
$res = $stmt->get_result();
$user = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$user) {
    die('User profile not found.');
}

$success = '';
$error = '';

$fullName = (string)($user['full_name'] ?? '');
$username = (string)($user['username'] ?? '');
$email = (string)($user['email'] ?? '');
$mobile = (string)($user['mobile'] ?? '');

/* -------------------------------------------------------
   SAVE PROFILE
------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile') {
    $fullName = trim((string)($_POST['full_name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $mobile = trim((string)($_POST['mobile'] ?? ''));

    if ($fullName === '') {
        $error = 'Full name is required.';
    } else {
        $stmt = $conn->prepare("
            UPDATE users
            SET
                full_name = ?,
                email = ?,
                mobile = ?,
                updated_at = NOW()
            WHERE id = ?
            LIMIT 1
        ");

        if (!$stmt) {
            $error = 'Failed to prepare profile update query.';
        } else {
            $stmt->bind_param('sssi', $fullName, $email, $mobile, $userId);

            if ($stmt->execute()) {
                if (function_exists('addAuditLog')) {
                    addAuditLog(
                        $conn,
                        $businessId,
                        $userId,
                        'Profile',
                        'Update',
                        $userId,
                        'Updated own profile'
                    );
                }

                $_SESSION['full_name'] = $fullName;
                $_SESSION['email'] = $email;
                $_SESSION['mobile'] = $mobile;

                $success = 'Profile updated successfully.';
            } else {
                $error = 'Failed to update profile.';
            }

            $stmt->close();
        }
    }
}

/* -------------------------------------------------------
   CHANGE PASSWORD
------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    $currentPassword = (string)($_POST['current_password'] ?? '');
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if ($currentPassword === '') {
        $error = 'Current password is required.';
    } elseif ($newPassword === '') {
        $error = 'New password is required.';
    } elseif (strlen($newPassword) < 6) {
        $error = 'New password must be at least 6 characters.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'New password and confirm password do not match.';
    } else {
        $stmt = $conn->prepare("SELECT password_hash FROM users WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $passRow = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        $dbHash = (string)($passRow['password_hash'] ?? '');
        $passwordOk = false;

        if ($dbHash !== '' && password_verify($currentPassword, $dbHash)) {
            $passwordOk = true;
        }

        if (!$passwordOk && $dbHash !== '' && hash_equals($dbHash, $currentPassword)) {
            $passwordOk = true;
        }

        if (!$passwordOk) {
            $error = 'Current password is incorrect.';
        } else {
            $newHash = password_hash($newPassword, PASSWORD_BCRYPT);

            $stmt = $conn->prepare("
                UPDATE users
                SET password_hash = ?, updated_at = NOW()
                WHERE id = ?
                LIMIT 1
            ");
            if (!$stmt) {
                $error = 'Failed to prepare password update query.';
            } else {
                $stmt->bind_param('si', $newHash, $userId);

                if ($stmt->execute()) {
                    if (function_exists('addAuditLog')) {
                        addAuditLog(
                            $conn,
                            $businessId,
                            $userId,
                            'Profile',
                            'Change Password',
                            $userId,
                            'Changed own password'
                        );
                    }

                    $success = 'Password changed successfully.';
                } else {
                    $error = 'Failed to change password.';
                }

                $stmt->close();
            }
        }
    }
}

/* -------------------------------------------------------
   REFRESH PROFILE VIEW
------------------------------------------------------- */
$stmt = $conn->prepare("
    SELECT
        u.id,
        u.business_id,
        u.role_id,
        u.full_name,
        u.username,
        u.email,
        u.mobile,
        u.is_active,
        u.last_login_at,
        u.created_at,
        r.role_name,
        b.business_name,
        b.business_code
    FROM users u
    INNER JOIN roles r ON r.id = u.role_id
    LEFT JOIN businesses b ON b.id = u.business_id
    WHERE u.id = ?
    LIMIT 1
");
$stmt->bind_param('i', $userId);
$stmt->execute();
$res = $stmt->get_result();
$user = $res ? $res->fetch_assoc() : $user;
$stmt->close();

$fullName = (string)($user['full_name'] ?? '');
$username = (string)($user['username'] ?? '');
$email = (string)($user['email'] ?? '');
$mobile = (string)($user['mobile'] ?? '');
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
                    <div class="col-xl-4">
                        <div class="card">
                            <div class="card-body text-center">
                                <div class="mb-3">
                                    <img src="assets/images/users/avatar-1.jpg" alt="Profile Avatar" class="rounded-circle img-thumbnail" style="width:120px;height:120px;object-fit:cover;">
                                </div>

                                <h4 class="mb-1"><?php echo h($user['full_name'] ?? ''); ?></h4>
                                <p class="text-muted mb-2"><?php echo h($user['role_name'] ?? ''); ?></p>

                                <div class="mt-4 text-start">
                                    <p class="mb-2"><strong>Username:</strong> <?php echo h($user['username'] ?? ''); ?></p>
                                    <p class="mb-2"><strong>Email:</strong> <?php echo h($user['email'] ?? ''); ?></p>
                                    <p class="mb-2"><strong>Mobile:</strong> <?php echo h($user['mobile'] ?? ''); ?></p>
                                    <p class="mb-2"><strong>Business:</strong> <?php echo h($user['business_name'] ?? ''); ?></p>
                                    <p class="mb-2"><strong>Business Code:</strong> <?php echo h($user['business_code'] ?? ''); ?></p>
                                    <p class="mb-2"><strong>Status:</strong>
                                        <?php if ((int)($user['is_active'] ?? 0) === 1): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">Inactive</span>
                                        <?php endif; ?>
                                    </p>
                                    <p class="mb-2">
                                        <strong>Last Login:</strong>
                                        <?php echo !empty($user['last_login_at']) ? date('d-m-Y h:i A', strtotime($user['last_login_at'])) : 'Never'; ?>
                                    </p>
                                    <p class="mb-0">
                                        <strong>Created At:</strong>
                                        <?php echo !empty($user['created_at']) ? date('d-m-Y h:i A', strtotime($user['created_at'])) : '-'; ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-8">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Update Profile</h4>

                                <form method="post">
                                    <input type="hidden" name="action" value="update_profile">

                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Full Name <span class="text-danger">*</span></label>
                                            <input type="text" name="full_name" class="form-control" value="<?php echo h($fullName); ?>" required>
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Username</label>
                                            <input type="text" class="form-control" value="<?php echo h($username); ?>" readonly>
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Email</label>
                                            <input type="email" name="email" class="form-control" value="<?php echo h($email); ?>">
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Mobile</label>
                                            <input type="text" name="mobile" class="form-control" value="<?php echo h($mobile); ?>">
                                        </div>
                                    </div>

                                    <div class="d-grid d-md-inline-block">
                                        <button type="submit" class="btn btn-primary waves-effect waves-light">
                                            Save Profile
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4">Change Password</h4>

                                <form method="post">
                                    <input type="hidden" name="action" value="change_password">

                                    <div class="row">
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Current Password <span class="text-danger">*</span></label>
                                            <input type="password" name="current_password" class="form-control" required>
                                        </div>

                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">New Password <span class="text-danger">*</span></label>
                                            <input type="password" name="new_password" class="form-control" required>
                                        </div>

                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Confirm Password <span class="text-danger">*</span></label>
                                            <input type="password" name="confirm_password" class="form-control" required>
                                        </div>
                                    </div>

                                    <div class="d-grid d-md-inline-block">
                                        <button type="submit" class="btn btn-warning waves-effect waves-light">
                                            Change Password
                                        </button>
                                    </div>
                                </form>
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