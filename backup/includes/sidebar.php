<?php
$currentPage = $currentPage ?? 'dashboard';

$roleName = function_exists('currentRoleName')
    ? currentRoleName()
    : ($_SESSION['role_name'] ?? '');

$isSuperAdmin = ($roleName === 'Super Admin');
$isAdminOnly  = in_array($roleName, ['Super Admin', 'Admin'], true);
$isManager    = in_array($roleName, ['Super Admin', 'Admin', 'Manager'], true);
$isBilling    = in_array($roleName, ['Super Admin', 'Admin', 'Manager', 'Billing'], true);
$isStock      = in_array($roleName, ['Super Admin', 'Admin', 'Manager', 'Stock'], true);

function isActiveMenu(array $pages, string $currentPage): string {
    return in_array($currentPage, $pages, true) ? 'active' : '';
}
?>

<div id="sidebar-menu">
    <ul class="metismenu list-unstyled" id="side-menu">

        <li class="menu-title">Main</li>

        <li>
            <a href="index.php" class="waves-effect <?php echo isActiveMenu(['dashboard'], $currentPage); ?>">
                <i class="fas fa-tachometer-alt"></i>
                <span>Dashboard</span>
            </a>
        </li>

        <?php if ($isAdminOnly || $isManager || $isBilling || $isStock): ?>
        <li class="menu-title">Masters</li>

        <?php if ($isAdminOnly || $isManager || $isBilling): ?>
        <li>
            <a href="javascript:void(0);" class="has-arrow waves-effect">
                <i class="fas fa-users"></i>
                <span>Customers</span>
            </a>
            <ul class="sub-menu" aria-expanded="false">
                <li><a href="customers.php?type=billing" class="<?php echo isActiveMenu(['customers-billing'], $currentPage); ?>">Billing Customers</a></li>
                <li><a href="customers.php?type=pawn" class="<?php echo isActiveMenu(['customers-pawn'], $currentPage); ?>">Pawn Broking Customers</a></li>
                <li><a href="customers.php?type=chit" class="<?php echo isActiveMenu(['customers-chit'], $currentPage); ?>">Pawn Chits Customers</a></li>
                <li><a href="customer-add.php" class="<?php echo isActiveMenu(['customer-add'], $currentPage); ?>">Add New Customer</a></li>
            </ul>
        </li>
        <?php endif; ?>

        <?php if ($isAdminOnly || $isManager): ?>
        <li>
            <a href="suppliers.php" class="waves-effect <?php echo isActiveMenu(['suppliers','supplier-add','supplier-view','supplier-edit'], $currentPage); ?>">
                <i class="fas fa-truck"></i>
                <span>Suppliers</span>
            </a>
        </li>
        <?php endif; ?>

        <?php if ($isAdminOnly || $isManager || $isStock): ?>
        <li>
            <a href="javascript:void(0);" class="has-arrow waves-effect">
                <i class="fas fa-boxes"></i>
                <span>Product Master</span>
            </a>
            <ul class="sub-menu" aria-expanded="false">
                <li><a href="categories.php" class="<?php echo isActiveMenu(['categories','category-add','category-edit'], $currentPage); ?>">Categories</a></li>
                <li><a href="products.php" class="<?php echo isActiveMenu(['products','product-add','product-edit','product-view'], $currentPage); ?>">Products</a></li>
                <li><a href="silver-rates.php" class="<?php echo isActiveMenu(['silver-rates','silver-rate-history'], $currentPage); ?>">Silver Rate</a></li>
                <li><a href="metal-rates.php" class="<?php echo isActiveMenu(['metal-rates'], $currentPage); ?>">Metal Rates</a></li>
            </ul>
        </li>
        <?php endif; ?>
        <?php endif; ?>

        <?php if ($isAdminOnly || $isManager || $isStock): ?>
        <li class="menu-title">Purchase & Stock</li>

        <li>
            <a href="javascript:void(0);" class="has-arrow waves-effect">
                <i class="fas fa-warehouse"></i>
                <span>Stock</span>
            </a>
            <ul class="sub-menu" aria-expanded="false">
                <li><a href="stock-overview.php" class="<?php echo isActiveMenu(['stock-overview'], $currentPage); ?>">Stock Overview</a></li>
                <li><a href="stock-adjustment.php" class="<?php echo isActiveMenu(['stock-adjustment'], $currentPage); ?>">Stock Adjustment</a></li>
                <li><a href="stock-movements.php" class="<?php echo isActiveMenu(['stock-movements'], $currentPage); ?>">Stock Movements</a></li>
                <li><a href="old-silver-entry.php" class="<?php echo isActiveMenu(['old-silver-entry','old-silver-list','old-silver-view'], $currentPage); ?>">Old Silver Entry</a></li>
            </ul>
        </li>

        <li>
            <a href="javascript:void(0);" class="has-arrow waves-effect">
                <i class="fas fa-shopping-bag"></i>
                <span>Purchase</span>
            </a>
            <ul class="sub-menu" aria-expanded="false">
                <li><a href="purchase-add.php" class="<?php echo isActiveMenu(['purchase-add'], $currentPage); ?>">Create Purchase</a></li>
                <li><a href="purchases.php" class="<?php echo isActiveMenu(['purchases','purchase-view','purchase-edit'], $currentPage); ?>">Purchase List</a></li>
                <li><a href="purchase-return.php" class="<?php echo isActiveMenu(['purchase-return','purchase-return-list'], $currentPage); ?>">Purchase Return</a></li>
                <li><a href="supplier-payments.php" class="<?php echo isActiveMenu(['supplier-payments','supplier-payment-add'], $currentPage); ?>">Supplier Payments</a></li>
            </ul>
        </li>
        <?php endif; ?>

        <?php if ($isAdminOnly || $isManager || $isBilling): ?>
        <li class="menu-title">Billing</li>

        <li>
            <a href="javascript:void(0);" class="has-arrow waves-effect">
                <i class="fas fa-file-invoice-dollar"></i>
                <span>Billing</span>
            </a>
            <ul class="sub-menu" aria-expanded="false">
                <li><a href="billing.php" class="<?php echo isActiveMenu(['billing','create-bill'], $currentPage); ?>">Create Bill</a></li>
                <li><a href="sales-list.php" class="<?php echo isActiveMenu(['sales-list','sale-view'], $currentPage); ?>">Sales List</a></li>
                <li><a href="sales-return.php" class="<?php echo isActiveMenu(['sales-return','sales-return-list'], $currentPage); ?>">Sales Return</a></li>
                <li><a href="estimates.php" class="<?php echo isActiveMenu(['estimates','estimate-create','estimate-list'], $currentPage); ?>">Estimates</a></li>
                <li><a href="customer-payments.php" class="<?php echo isActiveMenu(['customer-payments','customer-payment-add'], $currentPage); ?>">Customer Payments</a></li>
            </ul>
        </li>
        <?php endif; ?>

        <?php if ($isAdminOnly || $isManager || $isBilling): ?>
        <li class="menu-title">Pawn Broking</li>

        <li>
            <a href="javascript:void(0);" class="has-arrow waves-effect">
                <i class="fas fa-hand-holding-usd"></i>
                <span>Pawn Management</span>
            </a>
            <ul class="sub-menu" aria-expanded="false">
                <li><a href="pawn-entry.php" class="<?php echo isActiveMenu(['pawn-entry'], $currentPage); ?>">New Pawn Entry</a></li>
                <li><a href="pawn-customers.php" class="<?php echo isActiveMenu(['pawn-customers','pawn-customer-add'], $currentPage); ?>">Pawn Customers</a></li>
                <li><a href="pawn-categories.php" class="<?php echo isActiveMenu(['pawn-categories','pawn-category-add','pawn-category-edit','pawn-category-view'], $currentPage); ?>">Pawn Categories</a></li>
                <li><a href="pawn-list.php" class="<?php echo isActiveMenu(['pawn-list','pawn-view'], $currentPage); ?>">Pawn List</a></li>
                <li><a href="pawn-interest.php" class="<?php echo isActiveMenu(['pawn-interest','pawn-interest-collection'], $currentPage); ?>">Interest Collection</a></li>
                <li><a href="pawn-payment.php" class="<?php echo isActiveMenu(['pawn-payment'], $currentPage); ?>">Pawn Payment</a></li>
                <li><a href="pawn-release.php" class="<?php echo isActiveMenu(['pawn-release'], $currentPage); ?>">Pawn Release</a></li>
                <li><a href="pawn-auction.php" class="<?php echo isActiveMenu(['pawn-auction'], $currentPage); ?>">Auction / Forfeit</a></li>
            </ul>
        </li>
        <?php endif; ?>
        
        <?php if ($isAdminOnly || $isManager || $isBilling): ?>
        <li class="menu-title">Pawn Chits</li>

        <li>
            <a href="javascript:void(0);" class="has-arrow waves-effect">
                <i class="fas fa-coins"></i>
                <span>Chit Management</span>
            </a>
            <ul class="sub-menu" aria-expanded="false">
                <li><a href="chit-create.php" class="<?php echo isActiveMenu(['chit-create'], $currentPage); ?>">Create Chit</a></li>
                <li><a href="chit-groups.php" class="<?php echo isActiveMenu(['chit-groups','chit-view'], $currentPage); ?>">Chit Groups</a></li>
                <li><a href="chit-customers.php" class="<?php echo isActiveMenu(['chit-customers','chit-customer-add'], $currentPage); ?>">Chit Customers</a></li>
                <li><a href="chit-members.php" class="<?php echo isActiveMenu(['chit-members','chit-member-add'], $currentPage); ?>">Members</a></li>
                <li><a href="chit-collection.php" class="<?php echo isActiveMenu(['chit-collection'], $currentPage); ?>">Collection</a></li>
                <li><a href="chit-due-list.php" class="<?php echo isActiveMenu(['chit-due-list'], $currentPage); ?>">Due List</a></li>
                <li><a href="chit-ledger.php" class="<?php echo isActiveMenu(['chit-ledger'], $currentPage); ?>">Chit Ledger</a></li>
                <li><a href="chit-close.php" class="<?php echo isActiveMenu(['chit-close'], $currentPage); ?>">Close Chit</a></li>
            </ul>
        </li>
        <?php endif; ?>

        <?php if ($isAdminOnly || $isManager || $isBilling || $isStock): ?>
        <li class="menu-title">Accounts & Reports</li>

        <li>
            <a href="expenses.php" class="waves-effect <?php echo isActiveMenu(['expenses','expense-add'], $currentPage); ?>">
                <i class="fas fa-money-check-alt"></i>
                <span>Expenses</span>
            </a>
        </li>

        <li>
            <a href="payment-history.php" class="waves-effect <?php echo isActiveMenu(['payment-history'], $currentPage); ?>">
                <i class="fas fa-receipt"></i>
                <span>Payment History</span>
            </a>
        </li>

        <li>
            <a href="javascript:void(0);" class="has-arrow waves-effect">
                <i class="fas fa-chart-bar"></i>
                <span>Reports</span>
            </a>
            <ul class="sub-menu" aria-expanded="false">
                <li><a href="report-sales.php" class="<?php echo isActiveMenu(['report-sales'], $currentPage); ?>">Sales Report</a></li>
                <li><a href="report-purchase.php" class="<?php echo isActiveMenu(['report-purchase'], $currentPage); ?>">Purchase Report</a></li>
                <li><a href="report-stock.php" class="<?php echo isActiveMenu(['report-stock'], $currentPage); ?>">Stock Report</a></li>
                <li><a href="report-old-silver.php" class="<?php echo isActiveMenu(['report-old-silver'], $currentPage); ?>">Old Silver Report</a></li>
                <li><a href="pawn-reports.php" class="<?php echo isActiveMenu(['report-pawn','pawn-reports'], $currentPage); ?>">Pawn Report</a></li>
                <li><a href="report-chit.php" class="<?php echo isActiveMenu(['report-chit'], $currentPage); ?>">Chit Report</a></li>
                <li><a href="daybook.php" class="<?php echo isActiveMenu(['daybook'], $currentPage); ?>">Day Book</a></li>
            </ul>
        </li>
        <?php endif; ?>

        <?php if ($isAdminOnly): ?>
        <li class="menu-title">Settings</li>

        <li>
            <a href="javascript:void(0);" class="has-arrow waves-effect">
                <i class="fas fa-cogs"></i>
                <span>System Settings</span>
            </a>
            <ul class="sub-menu" aria-expanded="false">
                <li><a href="company-settings.php" class="<?php echo isActiveMenu(['company-settings'], $currentPage); ?>">Company Settings</a></li>
                <li><a href="users.php" class="<?php echo isActiveMenu(['users','user-add','user-edit'], $currentPage); ?>">Users</a></li>
                <li><a href="roles.php" class="<?php echo isActiveMenu(['roles'], $currentPage); ?>">Roles & Permissions</a></li>
                <li><a href="backup.php" class="<?php echo isActiveMenu(['backup'], $currentPage); ?>">Database Backup</a></li>
                <li><a href="audit-logs.php" class="<?php echo isActiveMenu(['audit-logs'], $currentPage); ?>">Audit Logs</a></li>
            </ul>
        </li>
        <?php endif; ?>

        <li class="menu-title">Account</li>

        <li>
            <a href="profile.php" class="waves-effect <?php echo isActiveMenu(['profile'], $currentPage); ?>">
                <i class="fas fa-user-circle"></i>
                <span>My Profile</span>
            </a>
        </li>

        <li>
            <a href="logout.php" class="waves-effect text-danger">
                <i class="fas fa-sign-out-alt"></i>
                <span>Logout</span>
            </a>
        </li>

    </ul>
</div>