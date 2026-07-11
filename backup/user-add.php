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

$pageTitle = 'Add User';

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
function usernameExists(mysqli $conn, string $username): bool
{
    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
    if (!$stmt) {
        return true;
    }

    $stmt->bind_param('s', $username);
    $stmt->execute();
    $res = $stmt->get_result();
    $exists = $res && $res->num_rows > 0;
    $stmt->close();

    return $exists;
}

function getAllowedRoles(mysqli $conn): array
{
    $roles = [];
    $res = $conn->query("
        SELECT id, role_name
        FROM roles
        WHERE LOWER(role_name) NOT IN ('super admin', 'super_admin', 'superadmin')
        ORDER BY id ASC
    ");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $roles[] = $row;
        }
    }
    return $roles;
}

function roleAllowed(mysqli $conn, int $roleId): bool
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
   DEFAULTS
------------------------------------------------------- */
$success = '';
$error = '';

$fullName = '';
$username = '';
$email = '';
$mobile = '';
$roleId = 0;
$isActive = 1;

$roles = getAllowedRoles($conn);

/* -------------------------------------------------------
   SAVE USER
------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
    } elseif ($password === '') {
        $error = 'Password is required.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Password and confirm password do not match.';
    } elseif ($roleId <= 0) {
        $error = 'Please select a role.';
    } elseif (!roleAllowed($conn, $roleId)) {
        $error = 'Invalid role selected.';
    } elseif (usernameExists($conn, $username)) {
        $error = 'Username already exists.';
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

        if (!$stmt) {
            $error = 'Failed to prepare insert query.';
        } else {
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
                                    <h4 class="card-title mb-4">User Details</h4>

                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Business</label>
                                            <input type="text" class="form-control" value="<?php echo h(($business['business_name'] ?? '') . ' (' . ($business['business_code'] ?? '') . ')'); ?>" readonly>
                                        </div>

                                        <div class="col-md-6 mb-3">
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

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Full Name <span class="text-danger">*</span></label>
                                            <input type="text" name="full_name" class="form-control" value="<?php echo h($fullName); ?>" required>
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Username <span class="text-danger">*</span></label>
                                            <input type="text" name="username" class="form-control" value="<?php echo h($username); ?>" required>
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Email</label>
                                            <input type="email" name="email" class="form-control" value="<?php echo h($email); ?>">
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Mobile</label>
                                            <input type="text" name="mobile" class="form-control" value="<?php echo h($mobile); ?>">
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Password <span class="text-danger">*</span></label>
                                            <input type="password" name="password" class="form-control" required>
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Confirm Password <span class="text-danger">*</span></label>
                                            <input type="password" name="confirm_password" class="form-control" required>
                                        </div>

                                        <div class="col-md-12 mb-3">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="is_active" id="is_active" value="1" <?php echo (int)$isActive === 1 ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="is_active">
                                                    Active User
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
                                            Create User
                                        </button>
                                        <a href="users.php" class="btn btn-secondary waves-effect">
                                            Back to Users
                                        </a>
                                    </div>

                                    <hr>

                                    <h5 class="mb-3">Notes</h5>
                                    <ul class="mb-0 ps-3">
                                        <li>Username must be unique.</li>
                                        <li>Password must be at least 6 characters.</li>
                                        <li>Super Admin role cannot be assigned here.</li>
                                        <li>New user will be created for this business only.</li>
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