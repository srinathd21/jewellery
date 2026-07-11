<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if (!function_exists('nav_e')) {
    function nav_e($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('nav_initials')) {
    function nav_initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $initials = '';
        foreach (array_slice($parts, 0, 2) as $part) {
            $initials .= strtoupper(substr($part, 0, 1));
        }
        return $initials !== '' ? $initials : 'U';
    }
}

$currentUserId   = (int)($_SESSION['user_id'] ?? 0);
$currentBusiness = (string)($_SESSION['business_name'] ?? 'Jewellery ERP');
$currentBranch   = (string)($_SESSION['branch_name'] ?? '');
$currentName     = (string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User');
$currentRole     = (string)($_SESSION['role_name'] ?? $_SESSION['user_type'] ?? 'User');
$currentEmail    = (string)($_SESSION['email'] ?? '');
$currentPhoto    = trim((string)($_SESSION['profile_photo_path'] ?? ''));
$allowedBranches = is_array($_SESSION['allowed_branches'] ?? null) ? $_SESSION['allowed_branches'] : [];
$canSwitchBranch = !empty($_SESSION['can_switch_branch']) && count($allowedBranches) > 1;

$currentPageFile = basename($_SERVER['PHP_SELF'] ?? 'index.php', '.php');

$pageTitleMap = [
    'index'                  => 'Dashboard',
    'dashboard'              => 'Dashboard',

    'billing'                => 'Billing',
    'sales'                  => 'Sales',
    'sale-add'               => 'Create Bill',
    'sale-view'              => 'View Invoice',
    'sale-edit'              => 'Edit Invoice',

    'customers'              => 'Customers',
    'customer-add'           => 'Add Customer',
    'customer-view'          => 'Customer Details',
    'customer-edit'          => 'Edit Customer',

    'products'               => 'Products',
    'product-add'            => 'Add Product',
    'product-view'           => 'Product Details',
    'product-edit'           => 'Edit Product',

    'stock'                  => 'Stock Summary',
    'stock-summary'          => 'Stock Summary',
    'stock-movements'        => 'Stock Movements',
    'branch-transfers'       => 'Branch Transfers',

    'purchases'              => 'Purchases',
    'purchase-add'           => 'Create Purchase',
    'purchase-view'          => 'Purchase Details',

    'karigar-orders'         => 'Karigar Orders',
    'karigars'               => 'Karigars',

    'payments'               => 'Payments',
    'expenses'               => 'Expenses',

    'pawn'                   => 'Pawn Broking',
    'pawn-broking'           => 'Pawn Broking',

    'chit-management'        => 'Chit Management',
    'chits'                  => 'Chit Management',

    'reports'                => 'Reports',

    'users'                  => 'Users',
    'staff'                  => 'Staff',
    'roles'                  => 'Roles & Permissions',
    'permissions'            => 'Permissions',

    'profile'                => 'My Profile',
    'settings'               => 'Settings',
    'notifications'          => 'Notifications',
    'support'                => 'Help & Support',
];

if (!isset($pageTitle) || trim((string)$pageTitle) === '') {
    $pageTitle = $pageTitleMap[$currentPageFile]
        ?? ucwords(str_replace(['-', '_'], ' ', $currentPageFile));
}
$profileUrl = $profileUrl ?? 'profile.php';
$settingsUrl = $settingsUrl ?? 'settings.php';
$supportUrl = $supportUrl ?? 'support.php';
$logoutUrl = $logoutUrl ?? 'logout.php';

$notificationCount = 0;
$notifications = [];

// Load notifications only when a compatible notifications table exists.
if (isset($conn) && $conn instanceof mysqli) {
    $tableCheck = $conn->query("SHOW TABLES LIKE 'notifications'");
    if ($tableCheck && $tableCheck->num_rows > 0) {
        $businessId = (int)($_SESSION['business_id'] ?? 0);
        $branchId = (int)($_SESSION['branch_id'] ?? 0);

        $sql = "SELECT id, title, message, notification_type, is_read, created_at
                FROM notifications
                WHERE user_id = ?
                  AND (business_id IS NULL OR business_id = ?)
                  AND (branch_id IS NULL OR branch_id = ?)
                ORDER BY created_at DESC, id DESC
                LIMIT 8";

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('iii', $currentUserId, $businessId, $branchId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $notifications[] = $row;
                if ((int)($row['is_read'] ?? 0) === 0) {
                    $notificationCount++;
                }
            }
            $stmt->close();
        }
    }
}

function navNotificationIcon(string $type): string
{
    return match (strtolower($type)) {
        'stock', 'low_stock', 'warning' => 'fa-solid fa-triangle-exclamation',
        'payment', 'receipt' => 'fa-solid fa-indian-rupee-sign',
        'invoice', 'sale' => 'fa-solid fa-file-invoice',
        'order' => 'fa-solid fa-bag-shopping',
        'approval' => 'fa-solid fa-circle-check',
        default => 'fa-regular fa-bell',
    };
}
?>

<header class="topbar">
    <button class="sidebar-toggle" id="sidebarToggle" type="button" aria-label="Toggle sidebar">
        <i class="fa-solid fa-bars"></i>
    </button>

    <div class="topbar-title-wrap">
        <h1 class="page-title"><?php echo nav_e($pageTitle); ?></h1>
        <div class="topbar-context">
            <?php echo nav_e($currentBusiness); ?>
            <?php if ($currentBranch !== ''): ?>
                <span class="mx-1">•</span><?php echo nav_e($currentBranch); ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="topbar-actions">
        <?php if ($canSwitchBranch): ?>
            <div class="dropdown">
                <button class="topbar-branch-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fa-solid fa-code-branch"></i>
                    <span class="branch-label"><?php echo nav_e($currentBranch ?: 'Select Branch'); ?></span>
                    <i class="fa-solid fa-chevron-down small"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><h6 class="dropdown-header">Switch Branch</h6></li>
                    <?php foreach ($allowedBranches as $branch): ?>
                        <?php
                        $branchId = (int)($branch['branch_id'] ?? 0);
                        $branchName = (string)($branch['branch_name'] ?? 'Branch');
                        $activeBranch = $branchId === (int)($_SESSION['branch_id'] ?? 0);
                        ?>
                        <li>
                            <a class="dropdown-item <?php echo $activeBranch ? 'active' : ''; ?>"
                               href="switch-branch.php?branch_id=<?php echo $branchId; ?>">
                                <i class="fa-solid fa-store me-2"></i><?php echo nav_e($branchName); ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <button class="theme-toggle" id="themeToggle" type="button" aria-label="Toggle theme">
            <i class="fa-regular fa-moon"></i>
        </button>

        <div class="dropdown">
            <button class="icon-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Notifications">
                <i class="fa-regular fa-bell"></i>
                <?php if ($notificationCount > 0): ?>
                    <span class="notification-badge"><?php echo $notificationCount > 99 ? '99+' : $notificationCount; ?></span>
                <?php endif; ?>
            </button>

            <div class="dropdown-menu dropdown-menu-end notification-menu p-2">
                <div class="d-flex justify-content-between align-items-center px-2 py-1">
                    <strong>Notifications</strong>
                    <span class="small text-muted"><?php echo $notificationCount; ?> new</span>
                </div>
                <hr class="my-1">

                <?php if (!$notifications): ?>
                    <div class="notification-empty">
                        <i class="fa-regular fa-bell-slash"></i>
                        <span>No new notifications</span>
                    </div>
                <?php else: ?>
                    <?php foreach ($notifications as $notification): ?>
                        <a class="notification-item text-decoration-none" href="notifications.php?id=<?php echo (int)$notification['id']; ?>">
                            <div class="metric-icon">
                                <i class="<?php echo nav_e(navNotificationIcon((string)($notification['notification_type'] ?? ''))); ?>"></i>
                            </div>
                            <div class="min-w-0 flex-grow-1">
                                <div class="fw-semibold text-truncate"><?php echo nav_e($notification['title'] ?? 'Notification'); ?></div>
                                <div class="small text-muted text-wrap"><?php echo nav_e($notification['message'] ?? ''); ?></div>
                            </div>
                            <?php if ((int)($notification['is_read'] ?? 0) === 0): ?>
                                <span class="notification-dot"></span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>

                <a class="btn btn-sm btn-light w-100 mt-1" href="notifications.php">View all notifications</a>
            </div>
        </div>

        <div class="dropdown">
            <button class="profile-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <?php if ($currentPhoto !== ''): ?>
                    <img class="avatar" src="<?php echo nav_e($currentPhoto); ?>" alt="<?php echo nav_e($currentName); ?>">
                <?php else: ?>
                    <span class="avatar avatar-initials"><?php echo nav_e(nav_initials($currentName)); ?></span>
                <?php endif; ?>

                <span class="profile-text text-start">
                    <strong class="d-block"><?php echo nav_e($currentName); ?></strong>
                    <span class="small text-muted"><?php echo nav_e($currentRole); ?></span>
                </span>
                <i class="fa-solid fa-chevron-down small"></i>
            </button>

            <ul class="dropdown-menu dropdown-menu-end profile-menu">
                <li class="profile-summary px-3 py-2">
                    <div class="fw-semibold"><?php echo nav_e($currentName); ?></div>
                    <?php if ($currentEmail !== ''): ?>
                        <div class="small text-muted text-truncate"><?php echo nav_e($currentEmail); ?></div>
                    <?php endif; ?>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="<?php echo nav_e($profileUrl); ?>"><i class="fa-regular fa-user me-2"></i>My Profile</a></li>
                <li><a class="dropdown-item" href="<?php echo nav_e($settingsUrl); ?>"><i class="fa-solid fa-gear me-2"></i>Account Settings</a></li>
                <li><a class="dropdown-item" href="<?php echo nav_e($supportUrl); ?>"><i class="fa-regular fa-circle-question me-2"></i>Help & Support</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-danger" href="<?php echo nav_e($logoutUrl); ?>"><i class="fa-solid fa-arrow-right-from-bracket me-2"></i>Logout</a></li>
            </ul>
        </div>
    </div>
</header>

<style>
.topbar-title-wrap {
    min-width: 0;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.topbar-context {
    margin-top: 2px;
    color: var(--muted, #7d8794);
    font-size: 10px;
    line-height: 1.2;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.topbar-branch-btn {
    height: 36px;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 0 10px;
    border: 1px solid var(--line, #e8e8e8);
    border-radius: 10px;
    background: var(--card, #fff);
    color: var(--text, #171717);
    font-size: 11px;
    font-weight: 600;
}

.notification-menu {
    width: 340px;
    max-height: 420px;
    overflow-y: auto;
}

.notification-item {
    position: relative;
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 9px;
    color: var(--text, #171717);
    border-radius: 9px;
}

.notification-dot {
    width: 7px;
    height: 7px;
    flex: 0 0 7px;
    margin-top: 5px;
    border-radius: 50%;
    background: var(--gold, #d89416);
}

.notification-empty {
    min-height: 110px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 8px;
    color: var(--muted, #7d8794);
    font-size: 11px;
}

.notification-empty i {
    font-size: 24px;
}

.avatar-initials {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    background: linear-gradient(135deg, var(--gold, #d89416), var(--gold-dark, #b86a0b));
    font-size: 12px;
    font-weight: 800;
}

.profile-menu {
    min-width: 230px;
}

.profile-summary {
    max-width: 230px;
}

.min-w-0 {
    min-width: 0;
}

body.dark-mode .topbar-branch-btn {
    background: #171e26;
    color: #eef2f7;
    border-color: #303740;
}

body.dark-mode .notification-item {
    color: #eef2f7;
}

@media (max-width: 991.98px) {
    .topbar-context,
    .branch-label {
        display: none;
    }

    .topbar-branch-btn {
        width: 36px;
        padding: 0;
        justify-content: center;
    }

    .notification-menu {
        width: min(340px, calc(100vw - 24px));
    }
}
</style>
