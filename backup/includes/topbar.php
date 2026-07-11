<?php
if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

$loggedBusinessName = trim((string)($_SESSION['business_name'] ?? ''));
$loggedUserName     = trim((string)($_SESSION['full_name'] ?? ''));
$sessionUserId      = (int)($_SESSION['user_id'] ?? 0);
$sessionBusinessId  = (int)($_SESSION['business_id'] ?? 0);

/* -------------------------------------------------------
   LOAD FROM DB IF SESSION VALUES ARE EMPTY
------------------------------------------------------- */
if ((($loggedBusinessName === '') || ($loggedUserName === '')) && isset($conn) && $conn instanceof mysqli && $sessionUserId > 0) {
    $stmt = $conn->prepare("
        SELECT 
            u.full_name,
            u.business_id,
            b.business_name
        FROM users u
        LEFT JOIN businesses b ON b.id = u.business_id
        WHERE u.id = ?
        LIMIT 1
    ");

    if ($stmt) {
        $stmt->bind_param('i', $sessionUserId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if ($row) {
            if ($loggedUserName === '') {
                $loggedUserName = trim((string)($row['full_name'] ?? 'User'));
            }

            if ($loggedBusinessName === '') {
                $loggedBusinessName = trim((string)($row['business_name'] ?? 'Business Panel'));
            }

            if ($sessionBusinessId <= 0 && !empty($row['business_id'])) {
                $_SESSION['business_id'] = (int)$row['business_id'];
            }

            if (!isset($_SESSION['full_name']) || trim((string)$_SESSION['full_name']) === '') {
                $_SESSION['full_name'] = $loggedUserName;
            }

            if (!isset($_SESSION['business_name']) || trim((string)$_SESSION['business_name']) === '') {
                $_SESSION['business_name'] = $loggedBusinessName;
            }
        }
    }
}

if ($loggedBusinessName === '') {
    $loggedBusinessName = 'Business Panel';
}

if ($loggedUserName === '') {
    $loggedUserName = 'User';
}
?>
<header id="page-topbar">
    <div class="navbar-header">
        <div class="d-flex">
            <div class="navbar-brand-box">
                <a href="index.php" class="logo logo-dark">
                    <span class="logo-sm">
                        <img src="assets/images/logo-sm.png" alt="Logo" height="22">
                    </span>
                    <span class="logo-lg">
                        <img src="assets/images/logo-dark.png" alt="Logo" height="20">
                    </span>
                </a>

                <a href="index.php" class="logo logo-light">
                    <span class="logo-sm">
                        <img src="assets/images/logo-sm.png" alt="Logo" height="22">
                    </span>
                    <span class="logo-lg">
                        <img src="assets/images/logo-light.png" alt="Logo" height="20">
                    </span>
                </a>
            </div>

            <button type="button" class="btn btn-sm px-3 font-size-24 header-item waves-effect" id="vertical-menu-btn">
                <i class="mdi mdi-menu"></i>
            </button>

            <div class="d-none d-sm-block ms-2">
                <h4 class="page-title font-size-18 mb-0">Dashboard</h4>
            </div>
        </div>

        <div class="d-flex align-items-center">

            <div class="d-none d-md-block me-3 text-end">
                <div class="fw-bold text-dark"><?php echo h($loggedBusinessName); ?></div>
                <div class="small text-muted"><?php echo h($loggedUserName); ?></div>
            </div>

            <div class="dropdown d-none d-lg-inline-block">
                <button type="button" class="btn header-item noti-icon waves-effect" data-bs-toggle="fullscreen">
                    <i class="mdi mdi-fullscreen"></i>
                </button>
            </div>

            <div class="dropdown d-inline-block ms-2">
                <button type="button" class="btn header-item waves-effect" id="page-header-user-dropdown"
                    data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                    <img class="rounded-circle header-profile-user" src="assets/images/users/avatar-1.jpg" alt="Header Avatar">
                </button>

                <div class="dropdown-menu dropdown-menu-end">
                    <div class="dropdown-item-text">
                        <div class="fw-bold"><?php echo h($loggedUserName); ?></div>
                        <div class="small text-muted"><?php echo h($loggedBusinessName); ?></div>
                    </div>

                    <div class="dropdown-divider"></div>

                    <a class="dropdown-item" href="profile.php">
                        <i class="dripicons-user font-size-16 align-middle me-2"></i>
                        Profile
                    </a>

                    <a class="dropdown-item" href="company-settings.php">
                        <i class="dripicons-gear font-size-16 align-middle me-2"></i>
                        Settings
                    </a>

                    <div class="dropdown-divider"></div>

                    <a class="dropdown-item" href="logout.php">
                        <i class="dripicons-exit font-size-16 align-middle me-2"></i>
                        Logout
                    </a>
                </div>
            </div>

            <div class="dropdown d-inline-block">
                <button type="button" class="btn header-item noti-icon right-bar-toggle waves-effect">
                    <i class="mdi mdi-spin mdi-cog"></i>
                </button>
            </div>

        </div>
    </div>
</header>