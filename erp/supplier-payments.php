<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));

$configCandidates = [
    __DIR__ . '/config/config.php',
    __DIR__ . '/config.php',
    __DIR__ . '/includes/config.php',
    __DIR__ . '/super-admin/includes/config.php',
];

foreach ($configCandidates as $configFile) {
    if (is_file($configFile)) {
        require_once $configFile;
        break;
    }
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database configuration is not available.');
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

if (!function_exists('generateSupplierPaymentNo')) {
    function generateSupplierPaymentNo(mysqli $conn, int $businessId, bool $hasBusinessId): string
    {
        $prefix = 'SP' . date('Ymd');
        $like = $prefix . '%';
        $lastNo = '';

        if ($hasBusinessId) {
            $stmt = $conn->prepare("SELECT payment_no FROM supplier_payments WHERE business_id = ? AND payment_no LIKE ? ORDER BY id DESC LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('is', $businessId, $like);
            }
        } else {
            $stmt = $conn->prepare("SELECT payment_no FROM supplier_payments WHERE payment_no LIKE ? ORDER BY id DESC LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('s', $like);
            }
        }

        if (isset($stmt) && $stmt) {
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $lastNo = (string)($row['payment_no'] ?? '');
        }

        $running = 1;
        if ($lastNo !== '' && preg_match('/(\d{4})$/', $lastNo, $match)) {
            $running = (int)$match[1] + 1;
        }

        return $prefix . str_pad((string)$running, 4, '0', STR_PAD_LEFT);
    }
}

$pageTitle = 'Supplier Payments';
$page_title = 'Supplier Payments';
$currentPage = 'supplier-payments';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$businessId = (int)($_SESSION['business_id'] ?? 0);
if ($businessId <= 0) {
    http_response_code(403);
    die('A valid business must be selected before managing supplier payments.');
}

function supplierPaymentPermission(mysqli $conn, string $action): bool
{
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin') {
        return true;
    }

    $map = [
        'open' => 'can_open',
        'view' => 'can_view',
        'value' => 'can_view_value',
        'create' => 'can_create',
        'update' => 'can_update',
        'approve' => 'can_approve',
        'delete' => 'can_delete',
    ];

    $field = $map[$action] ?? '';
    if ($field === '') {
        return false;
    }

    $permissions = $_SESSION['permissions'] ?? [];
    foreach (['perm.purchases.supplier_payments', 'perm.purchases', 'perm.suppliers'] as $key) {
        if (isset($permissions[$key][$field])) {
            return (int)$permissions[$key][$field] === 1;
        }
    }

    $businessId = (int)($_SESSION['business_id'] ?? 0);
    $roleId = (int)($_SESSION['role_id'] ?? 0);

    if ($businessId <= 0 || $roleId <= 0) {
        $roleName = strtolower(trim((string)($_SESSION['role_name'] ?? '')));
        $roleCode = strtolower(trim((string)($_SESSION['role_code'] ?? '')));
        return in_array($roleName, ['admin', 'manager', 'stock'], true)
            || in_array($roleCode, ['admin', 'manager', 'stock'], true);
    }

    $sql = "SELECT rp.`{$field}`
            FROM role_permissions rp
            INNER JOIN permissions p ON p.id = rp.permission_id
            WHERE rp.business_id = ?
              AND rp.role_id = ?
              AND p.is_active = 1
              AND p.permission_code IN ('perm.purchases.supplier_payments','perm.purchases','perm.suppliers')
            ORDER BY FIELD(p.permission_code,'perm.purchases.supplier_payments','perm.purchases','perm.suppliers')
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ii', $businessId, $roleId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row[$field] ?? 0) === 1;
}

if (!supplierPaymentPermission($conn, 'open')) {
    http_response_code(403);
    die('Access denied. You do not have permission to open supplier payments.');
}

$canView = supplierPaymentPermission($conn, 'view') || supplierPaymentPermission($conn, 'open');
$canCreate = supplierPaymentPermission($conn, 'create');
$canViewValue = supplierPaymentPermission($conn, 'value') || $canView;

foreach (['suppliers', 'purchases', 'supplier_payments'] as $requiredTable) {
    if (!tableExists($conn, $requiredTable)) {
        die("Required table `{$requiredTable}` was not found.");
    }
}

if (empty($_SESSION['supplier_payment_csrf'])) {
    $_SESSION['supplier_payment_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['supplier_payment_csrf'];

$supHasBusinessId = hasColumn($conn, 'suppliers', 'business_id');
$supHasSupplierCode = hasColumn($conn, 'suppliers', 'supplier_code');
$supHasMobile = hasColumn($conn, 'suppliers', 'mobile');
$supHasOpeningBalance = hasColumn($conn, 'suppliers', 'opening_balance');
$supHasBalanceType = hasColumn($conn, 'suppliers', 'balance_type');
$supHasIsActive = hasColumn($conn, 'suppliers', 'is_active');
$supHasStatus = hasColumn($conn, 'suppliers', 'status');

$purHasBusinessId = hasColumn($conn, 'purchases', 'business_id');
$purHasPurchaseNo = hasColumn($conn, 'purchases', 'purchase_no');
$purHasPurchaseDate = hasColumn($conn, 'purchases', 'purchase_date');
$purHasGrandTotal = hasColumn($conn, 'purchases', 'grand_total');
$purHasPaidAmount = hasColumn($conn, 'purchases', 'paid_amount');
$purHasBalanceAmount = hasColumn($conn, 'purchases', 'balance_amount');
$purHasPaymentStatus = hasColumn($conn, 'purchases', 'payment_status');

$payHasBusinessId = hasColumn($conn, 'supplier_payments', 'business_id');
$payHasPurchaseId = hasColumn($conn, 'supplier_payments', 'purchase_id');
$paymentMethodExists = tableExists($conn, 'payment_methods');

$paymentMethodIdColumn = $paymentMethodExists
    ? (hasColumn($conn, 'payment_methods', 'payment_method_id')
        ? 'payment_method_id'
        : (hasColumn($conn, 'payment_methods', 'id') ? 'id' : ''))
    : '';

$paymentMethodNameColumn = $paymentMethodExists
    ? (hasColumn($conn, 'payment_methods', 'payment_method_name')
        ? 'payment_method_name'
        : (hasColumn($conn, 'payment_methods', 'method_name') ? 'method_name' : ''))
    : '';

$paymentMethodStatusColumn = $paymentMethodExists
    ? (hasColumn($conn, 'payment_methods', 'status')
        ? 'status'
        : (hasColumn($conn, 'payment_methods', 'is_active') ? 'is_active' : ''))
    : '';

$supplierPaymentIdColumn = hasColumn($conn, 'supplier_payments', 'supplier_payment_id')
    ? 'supplier_payment_id'
    : (hasColumn($conn, 'supplier_payments', 'id') ? 'id' : '');

$suppliers = [];
$sql = "SELECT id, supplier_name,
        " . ($supHasSupplierCode ? "supplier_code" : "'' AS supplier_code") . ",
        " . ($supHasMobile ? "mobile" : "'' AS mobile") . ",
        " . ($supHasOpeningBalance ? "opening_balance" : "0 AS opening_balance") . ",
        " . ($supHasBalanceType ? "balance_type" : "'Cr' AS balance_type") . "
        FROM suppliers WHERE 1=1";

if ($supHasBusinessId) {
    $sql .= " AND business_id = ?";
}
if ($supHasIsActive) {
    $sql .= " AND is_active = 1";
} elseif ($supHasStatus) {
    $sql .= " AND (status = 1 OR status = 'Active')";
}
$sql .= " ORDER BY supplier_name ASC";

$stmt = $conn->prepare($sql);
if ($stmt) {
    if ($supHasBusinessId) {
        $stmt->bind_param('i', $businessId);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($result && $row = $result->fetch_assoc()) {
        $suppliers[] = $row;
    }
    $stmt->close();
}

$paymentMethods = [];

if (
    $paymentMethodExists &&
    $paymentMethodIdColumn !== '' &&
    $paymentMethodNameColumn !== ''
) {
    $sql = "SELECT
                `{$paymentMethodIdColumn}` AS id,
                `{$paymentMethodNameColumn}` AS method_name
            FROM payment_methods
            WHERE 1=1";

    $pmParams = [];
    $pmTypes = '';

    if (hasColumn($conn, 'payment_methods', 'business_id')) {
        $sql .= " AND (business_id = ? OR business_id IS NULL)";
        $pmParams[] = $businessId;
        $pmTypes .= 'i';
    }

    if ($paymentMethodStatusColumn !== '') {
        $sql .= " AND COALESCE(`{$paymentMethodStatusColumn}`,1) = 1";
    }

    $sql .= " ORDER BY `{$paymentMethodNameColumn}` ASC";

    $stmt = $conn->prepare($sql);

    if ($stmt) {
        if ($pmParams) {
            $stmt->bind_param($pmTypes, ...$pmParams);
        }

        $stmt->execute();
        $result = $stmt->get_result();

        while ($result && $row = $result->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $paymentMethods[] = $row;
        }

        $stmt->close();
    }

    /*
     * Compatibility fallback for old shared payment methods that are stored
     * under a different business_id.
     */
    if (!$paymentMethods) {
        $sql = "SELECT
                    `{$paymentMethodIdColumn}` AS id,
                    `{$paymentMethodNameColumn}` AS method_name
                FROM payment_methods
                WHERE 1=1";

        if ($paymentMethodStatusColumn !== '') {
            $sql .= " AND COALESCE(`{$paymentMethodStatusColumn}`,1) = 1";
        }

        $sql .= " ORDER BY `{$paymentMethodNameColumn}` ASC";

        $result = $conn->query($sql);

        while ($result && $row = $result->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $paymentMethods[] = $row;
        }
    }
}

$selectedSupplierId = (int)($_GET['supplier_id'] ?? 0);
$paymentNo = generateSupplierPaymentNo($conn, $businessId, $payHasBusinessId);
$paymentDate = date('Y-m-d');

$selectedSupplier = null;
if ($selectedSupplierId > 0) {
    $sql = "SELECT * FROM suppliers WHERE id = ?";
    if ($supHasBusinessId) {
        $sql .= " AND business_id = ?";
    }
    $sql .= " LIMIT 1";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if ($supHasBusinessId) {
            $stmt->bind_param('ii', $selectedSupplierId, $businessId);
        } else {
            $stmt->bind_param('i', $selectedSupplierId);
        }
        $stmt->execute();
        $selectedSupplier = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

$pendingPurchases = [];

if ($selectedSupplierId > 0) {
    $balanceExpression = $purHasBalanceAmount
        ? "GREATEST(COALESCE(balance_amount,0), COALESCE(grand_total,0) - COALESCE(paid_amount,0))"
        : "GREATEST(COALESCE(grand_total,0) - COALESCE(paid_amount,0),0)";

    $sql = "SELECT
                id,
                " . ($purHasPurchaseNo ? "purchase_no" : "'' AS purchase_no") . ",
                " . ($purHasPurchaseDate ? "purchase_date" : "NULL AS purchase_date") . ",
                " . ($purHasGrandTotal ? "grand_total" : "0 AS grand_total") . ",
                " . ($purHasPaidAmount ? "paid_amount" : "0 AS paid_amount") . ",
                {$balanceExpression} AS balance_amount,
                " . ($purHasPaymentStatus ? "payment_status" : "'Unpaid' AS payment_status") . "
            FROM purchases
            WHERE supplier_id = ?";

    if ($purHasBusinessId) {
        $sql .= " AND business_id = ?";
    }

    $sql .= " HAVING balance_amount > 0";
    $sql .= " ORDER BY " .
        ($purHasPurchaseDate ? "purchase_date" : "id") .
        " ASC, id ASC";

    $stmt = $conn->prepare($sql);

    if ($stmt) {
        if ($purHasBusinessId) {
            $stmt->bind_param('ii', $selectedSupplierId, $businessId);
        } else {
            $stmt->bind_param('i', $selectedSupplierId);
        }

        $stmt->execute();
        $result = $stmt->get_result();

        while ($result && $row = $result->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $pendingPurchases[] = $row;
        }

        $stmt->close();
    }
}

$totalPayable = 0.0;
if ($selectedSupplierId > 0) {
    $opening = (float)($selectedSupplier['opening_balance'] ?? 0);
    if (($selectedSupplier['balance_type'] ?? 'Cr') === 'Dr') {
        $opening = -$opening;
    }

    $totalBalanceExpression = $purHasBalanceAmount
        ? "GREATEST(COALESCE(balance_amount,0), COALESCE(grand_total,0) - COALESCE(paid_amount,0))"
        : "GREATEST(COALESCE(grand_total,0) - COALESCE(paid_amount,0),0)";

    $sql = "SELECT COALESCE(SUM({$totalBalanceExpression}),0) AS total_balance
            FROM purchases WHERE supplier_id = ?";
    if ($purHasBusinessId) {
        $sql .= " AND business_id = ?";
    }

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if ($purHasBusinessId) {
            $stmt->bind_param('ii', $selectedSupplierId, $businessId);
        } else {
            $stmt->bind_param('i', $selectedSupplierId);
        }
        $stmt->execute();
        $purchaseBalance = (float)($stmt->get_result()->fetch_assoc()['total_balance'] ?? 0);
        $stmt->close();
    } else {
        $purchaseBalance = 0.0;
    }

    $unlinkedPayments = 0.0;
    if ($payHasPurchaseId) {
        $sql = "SELECT COALESCE(SUM(amount),0) AS unlinked_paid
                FROM supplier_payments
                WHERE supplier_id = ? AND purchase_id IS NULL";
        if ($payHasBusinessId) {
            $sql .= " AND business_id = ?";
        }

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            if ($payHasBusinessId) {
                $stmt->bind_param('ii', $selectedSupplierId, $businessId);
            } else {
                $stmt->bind_param('i', $selectedSupplierId);
            }
            $stmt->execute();
            $unlinkedPayments = (float)($stmt->get_result()->fetch_assoc()['unlinked_paid'] ?? 0);
            $stmt->close();
        }
    }

    $totalPayable = max(0, $opening + $purchaseBalance - $unlinkedPayments);
}

function supplierPaidTotal(mysqli $conn, bool $hasBusinessId, int $businessId, string $period): float
{
    $where = $period === 'today'
        ? "payment_date = CURDATE()"
        : "DATE_FORMAT(payment_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')";

    $sql = "SELECT COALESCE(SUM(amount),0) AS total_paid FROM supplier_payments WHERE {$where}";
    if ($hasBusinessId) {
        $sql .= " AND business_id = ?";
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return 0.0;
    }

    if ($hasBusinessId) {
        $stmt->bind_param('i', $businessId);
    }

    $stmt->execute();
    $value = (float)($stmt->get_result()->fetch_assoc()['total_paid'] ?? 0);
    $stmt->close();
    return $value;
}

$todayPaid = supplierPaidTotal($conn, $payHasBusinessId, $businessId, 'today');
$monthPaid = supplierPaidTotal($conn, $payHasBusinessId, $businessId, 'month');

$recentPayments = [];

$paymentMethodSelect = (
    $paymentMethodExists &&
    $paymentMethodNameColumn !== '' &&
    $paymentMethodIdColumn !== ''
)
    ? "pm.`{$paymentMethodNameColumn}` AS method_name"
    : "'' AS method_name";

$paymentMethodJoin = (
    $paymentMethodExists &&
    $paymentMethodNameColumn !== '' &&
    $paymentMethodIdColumn !== ''
)
    ? "LEFT JOIN payment_methods pm
           ON pm.`{$paymentMethodIdColumn}` = sp.payment_method_id"
    : "";

$orderColumn = $supplierPaymentIdColumn !== ''
    ? "sp.`{$supplierPaymentIdColumn}`"
    : "sp.payment_date";

$sql = "SELECT
            sp.*,
            s.supplier_name,
            " . ($supHasSupplierCode ? "s.supplier_code" : "'' AS supplier_code") . ",
            " . ($purHasPurchaseNo ? "p.purchase_no" : "'' AS purchase_no") . ",
            {$paymentMethodSelect}
        FROM supplier_payments sp
        LEFT JOIN suppliers s
            ON s.id = sp.supplier_id
        LEFT JOIN purchases p
            ON p.id = sp.purchase_id
        {$paymentMethodJoin}
        WHERE 1=1";

$params = [];
$types = '';

if ($payHasBusinessId) {
    $sql .= " AND sp.business_id = ?";
    $params[] = $businessId;
    $types .= 'i';
}

if ($selectedSupplierId > 0) {
    $sql .= " AND sp.supplier_id = ?";
    $params[] = $selectedSupplierId;
    $types .= 'i';
}

$sql .= " ORDER BY {$orderColumn} DESC LIMIT 15";

$stmt = $conn->prepare($sql);

if ($stmt) {
    if ($params) {
        $bind = [$types];

        foreach ($params as $key => $value) {
            $bind[] = &$params[$key];
        }

        call_user_func_array([$stmt, 'bind_param'], $bind);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    while ($result && $row = $result->fetch_assoc()) {
        $recentPayments[] = $row;
    }

    $stmt->close();
}

$theme = [
    'primary_color' => '#d89416',
    'primary_dark_color' => '#b86a0b',
    'primary_soft_color' => '#fff6e5',
    'sidebar_gradient_1' => '#171c21',
    'sidebar_gradient_2' => '#20272d',
    'sidebar_gradient_3' => '#101419',
    'page_background' => '#f4f3f0',
    'card_background' => '#ffffff',
    'text_color' => '#171717',
    'muted_text_color' => '#7d8794',
    'border_color' => '#e8e8e8',
    'font_family' => 'Inter',
    'heading_font_family' => 'Playfair Display',
    'border_radius_px' => 12,
    'sidebar_width_px' => 230,
];

if (tableExists($conn, 'business_theme_settings')) {
    $stmt = $conn->prepare('SELECT * FROM business_theme_settings WHERE business_id = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('i', $businessId);
        $stmt->execute();
        $themeRow = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();

        foreach ($theme as $key => $value) {
            if (isset($themeRow[$key]) && $themeRow[$key] !== '') {
                $theme[$key] = $themeRow[$key];
            }
        }
    }
}

$businessName = (string)($_SESSION['business_name'] ?? 'Jewellery ERP');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo h($businessName); ?> - Supplier Payments</title>
<?php include('includes/links.php'); ?>
<style>
:root{
    --primary:<?php echo h($theme['primary_color']); ?>;
    --primary-dark:<?php echo h($theme['primary_dark_color']); ?>;
    --primary-soft:<?php echo h($theme['primary_soft_color']); ?>;
    --page-bg:<?php echo h($theme['page_background']); ?>;
    --card-bg:<?php echo h($theme['card_background']); ?>;
    --text-color:<?php echo h($theme['text_color']); ?>;
    --muted-color:<?php echo h($theme['muted_text_color']); ?>;
    --border-color:<?php echo h($theme['border_color']); ?>;
    --sidebar-width:<?php echo (int)$theme['sidebar_width_px']; ?>px;
    --radius:<?php echo (int)$theme['border_radius_px']; ?>px;
}
body{background:var(--page-bg);color:var(--text-color);font-family:<?php echo json_encode((string)$theme['font_family']); ?>,sans-serif}
.sidebar{background:linear-gradient(180deg,<?php echo h($theme['sidebar_gradient_1']); ?>,<?php echo h($theme['sidebar_gradient_2']); ?>,<?php echo h($theme['sidebar_gradient_3']); ?>)!important}
.page-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:10px}
.page-title{font-family:<?php echo json_encode((string)$theme['heading_font_family']); ?>,serif;font-size:18px;font-weight:800;margin:0}
.page-subtitle{font-size:10px;color:var(--muted-color);margin-top:2px}
.stat-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-bottom:10px}
.stat-card{background:var(--card-bg);border:1px solid var(--border-color);border-radius:var(--radius);padding:12px 14px;min-height:82px;display:flex;align-items:center;gap:12px}
.stat-icon{width:42px;height:42px;flex:0 0 42px;display:flex;align-items:center;justify-content:center;border-radius:calc(var(--radius)*.75);background:var(--primary-soft);color:var(--primary-dark);font-size:16px}
.stat-label{font-size:10px;color:var(--muted-color)}
.stat-value{font-size:21px;line-height:1.1;font-weight:800;margin-top:4px}
.panel{background:var(--card-bg);border:1px solid var(--border-color);border-radius:var(--radius);margin-bottom:10px;overflow:hidden}
.panel-head{padding:11px 13px;border-bottom:1px solid var(--border-color)}
.panel-title{font-size:12px;font-weight:800}.panel-subtitle{font-size:9px;color:var(--muted-color);margin-top:2px}.panel-body{padding:12px}
.payment-layout{display:grid;grid-template-columns:minmax(0,2fr) minmax(280px,1fr);gap:10px}
.form-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px}
.span-2{grid-column:span 2}.span-3{grid-column:span 3}.span-6{grid-column:span 6}
.field-label{display:block;font-size:9px;font-weight:700;margin-bottom:4px;color:var(--muted-color);text-transform:uppercase}
.form-control,.form-select{font-size:10px;min-height:36px;border-radius:9px;border-color:var(--border-color);background:var(--card-bg);color:var(--text-color)}
.btn-theme{background:linear-gradient(135deg,var(--primary),var(--primary-dark));border:0;color:#fff;border-radius:9px;font-size:10px;font-weight:700;min-height:36px;padding:8px 13px}
.btn-light-custom{border:1px solid var(--border-color);background:var(--card-bg);color:var(--text-color);border-radius:9px;font-size:10px;font-weight:700;min-height:36px;padding:8px 12px}
.btn-soft{background:var(--primary-soft);color:var(--primary-dark);border:0;border-radius:8px;font-size:9px;font-weight:800;padding:7px 10px}
.summary-list{display:grid;gap:8px}.summary-item{display:flex;justify-content:space-between;gap:12px;padding:9px 10px;border:1px solid var(--border-color);border-radius:9px;font-size:10px}.summary-item span:first-child{color:var(--muted-color)}.summary-item strong{font-weight:800}
.compact-table{margin:0;font-size:10px}.compact-table th{font-size:9px;text-transform:uppercase;color:var(--muted-color);background:color-mix(in srgb,var(--muted-color) 6%,transparent);padding:10px 11px;white-space:nowrap;border-color:var(--border-color)}.compact-table td{padding:10px 11px;vertical-align:middle;background:var(--card-bg)!important;color:var(--text-color);border-color:var(--border-color)}
.payment-no{font-weight:800}.subtext{font-size:8px;color:var(--muted-color);margin-top:2px}
.status-badge{display:inline-flex;border-radius:999px;padding:4px 8px;font-size:8px;font-weight:800}.status-paid{background:#eaf8f0;color:#168449}.status-partial{background:#fff6df;color:#a66800}.status-unpaid{background:#fdecec;color:#bd2d2d}
.theme-toast{position:fixed;right:18px;top:78px;z-index:20000;display:flex;align-items:center;gap:9px;min-width:260px;max-width:420px;padding:11px 14px;border-radius:10px;color:#fff;font-size:11px;font-weight:600;box-shadow:0 14px 35px rgba(0,0,0,.22);opacity:0;transform:translateY(-10px);transition:.22s}.theme-toast.show{opacity:1;transform:translateY(0)}.theme-toast-success{background:#168449}.theme-toast-error{background:#c0392b}
.empty-state{padding:38px 20px;text-align:center;color:var(--muted-color)}
body.dark-mode,body[data-theme="dark"],html.dark-mode body,html[data-theme="dark"] body{--page-bg:#0f151b;--card-bg:#182129;--text-color:#f3f6f8;--muted-color:#9aa7b3;--border-color:#2c3944}
@media(max-width:1199.98px){.payment-layout{grid-template-columns:1fr}.form-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.span-2,.span-3,.span-6{grid-column:span 1}.span-full{grid-column:1/-1}}
@media(max-width:991.98px){.stat-grid{grid-template-columns:1fr}.responsive-table thead{display:none}.responsive-table tbody{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;padding:10px}.responsive-table tbody tr{display:grid;grid-template-columns:1fr 1fr;border:1px solid var(--border-color);border-radius:var(--radius);padding:12px;background:var(--card-bg)}.responsive-table tbody td{display:flex;justify-content:space-between;gap:10px;padding:8px 0;border:0;border-bottom:1px dashed var(--border-color);text-align:right}.responsive-table tbody td::before{content:attr(data-label);font-size:8px;font-weight:700;text-transform:uppercase;color:var(--muted-color)}.responsive-table tbody td.main-column{grid-column:1/-1;display:block;text-align:left}.responsive-table tbody td.main-column::before{display:none}}
@media(max-width:767.98px){.content-wrap{padding-left:10px;padding-right:10px}.form-grid{grid-template-columns:1fr}.responsive-table tbody{grid-template-columns:1fr}.responsive-table tbody tr{grid-template-columns:1fr}.responsive-table tbody td{grid-column:1/-1}}
</style>
</head>
<body>
<?php include('includes/sidebar.php'); ?>
<main class="app-main">
<?php include('includes/nav.php'); ?>
<div class="content-wrap">
    <div class="page-heading">
        <div>
            <h1 class="page-title">Supplier Payments</h1>
            <div class="page-subtitle"><?php echo h($businessName); ?> · Record payments and update purchase balances</div>
        </div>
        <div class="d-flex gap-2">
            <a href="purchases.php" class="btn btn-light-custom">Purchases</a>
            <a href="suppliers.php" class="btn btn-light-custom">Suppliers</a>
        </div>
    </div>

    <?php if (!$canView): ?>
        <div class="panel"><div class="empty-state"><i class="fa-solid fa-lock mb-2"></i><div>You do not have permission to view supplier payments.</div></div></div>
    <?php else: ?>
        <div class="stat-grid">
            <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-wallet"></i></div><div><div class="stat-label">Selected Supplier Payable</div><div class="stat-value"><?php echo $canViewValue ? '₹' . money($totalPayable) : '••••'; ?></div></div></div>
            <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-calendar-day"></i></div><div><div class="stat-label">Today Paid</div><div class="stat-value"><?php echo $canViewValue ? '₹' . money($todayPaid) : '••••'; ?></div></div></div>
            <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-calendar"></i></div><div><div class="stat-label">This Month Paid</div><div class="stat-value"><?php echo $canViewValue ? '₹' . money($monthPaid) : '••••'; ?></div></div></div>
        </div>

        <div class="payment-layout">
            <form id="supplierPaymentForm" class="panel">
                <div class="panel-head"><div class="panel-title">Payment Entry</div><div class="panel-subtitle">Select a supplier and optionally link the payment to a pending purchase.</div></div>
                <div class="panel-body">
                    <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
                    <div class="form-grid">
                        <div><label class="field-label">Payment No</label><input type="text" name="payment_no" class="form-control" value="<?php echo h($paymentNo); ?>" required></div>
                        <div><label class="field-label">Payment Date</label><input type="date" name="payment_date" class="form-control" value="<?php echo h($paymentDate); ?>" required></div>
                        <div class="span-2"><label class="field-label">Supplier</label><select name="supplier_id" id="supplier_id" class="form-select" required><option value="">Select Supplier</option><?php foreach ($suppliers as $supplier): ?><option value="<?php echo (int)$supplier['id']; ?>" <?php echo $selectedSupplierId === (int)$supplier['id'] ? 'selected' : ''; ?>><?php echo h($supplier['supplier_name'] . (!empty($supplier['supplier_code']) ? ' (' . $supplier['supplier_code'] . ')' : '') . (!empty($supplier['mobile']) ? ' - ' . $supplier['mobile'] : '')); ?></option><?php endforeach; ?></select></div>
                        <div class="span-2"><label class="field-label">Purchase Bill</label><select name="purchase_id" id="purchase_id" class="form-select"><option value="">General Payment / Opening Balance</option><?php foreach ($pendingPurchases as $purchase): ?><option value="<?php echo (int)$purchase['id']; ?>" data-balance="<?php echo h($purchase['balance_amount'] ?? 0); ?>"><?php echo h(($purchase['purchase_no'] ?? '') . ' | Balance ₹' . money($purchase['balance_amount'] ?? 0)); ?></option><?php endforeach; ?></select></div>
                        <div><label class="field-label">Amount</label><input type="number" step="0.01" min="0.01" name="amount" id="amount" class="form-control" required></div>
                        <div>
                            <label class="field-label">Payment Method</label>
                            <select
                                name="payment_method_id"
                                id="payment_method_id"
                                class="form-select"
                                required
                                <?php echo empty($paymentMethods) ? 'disabled' : ''; ?>
                            >
                                <option value="">
                                    <?php echo empty($paymentMethods)
                                        ? 'No active payment methods found'
                                        : 'Select Payment Method'; ?>
                                </option>
                                <?php foreach ($paymentMethods as $method): ?>
                                    <option value="<?php echo (int)$method['id']; ?>">
                                        <?php echo h($method['method_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="span-2"><label class="field-label">Reference No</label><input type="text" name="reference_no" class="form-control" maxlength="150" placeholder="Cheque / UPI / Bank reference"></div>
                        <div class="span-2"><label class="field-label">Notes</label><input type="text" name="notes" class="form-control" maxlength="1000" placeholder="Payment notes"></div>
                    </div>
                    <div class="d-flex flex-wrap gap-2 mt-3">
                        <?php if ($canCreate): ?>
                            <button
                                type="submit"
                                class="btn btn-theme"
                                id="savePaymentButton"
                                <?php echo empty($paymentMethods) ? 'disabled' : ''; ?>
                            >
                                <i class="fa-solid fa-floppy-disk me-1"></i>
                                Save Payment
                            </button>
                        <?php endif; ?>
                        <a href="supplier-payments.php" class="btn btn-light-custom">Reset</a>
                    </div>
                </div>
            </form>

            <div class="panel">
                <div class="panel-head"><div class="panel-title">Supplier Summary</div><div class="panel-subtitle">Current supplier and outstanding information.</div></div>
                <div class="panel-body">
                    <?php if ($selectedSupplier): ?>
                        <div class="summary-list">
                            <div class="summary-item"><span>Supplier</span><strong><?php echo h($selectedSupplier['supplier_name'] ?? ''); ?></strong></div>
                            <div class="summary-item"><span>Mobile</span><strong><?php echo h($selectedSupplier['mobile'] ?? '—'); ?></strong></div>
                            <div class="summary-item"><span>Opening Balance</span><strong><?php echo $canViewValue ? '₹' . money($selectedSupplier['opening_balance'] ?? 0) . ' ' . h($selectedSupplier['balance_type'] ?? 'Cr') : '••••'; ?></strong></div>
                            <div class="summary-item"><span>Pending Bills</span><strong><?php echo count($pendingPurchases); ?></strong></div>
                            <div class="summary-item"><span>Total Payable</span><strong><?php echo $canViewValue ? '₹' . money($totalPayable) : '••••'; ?></strong></div>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">Select a supplier to see payable details.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="panel">
            <div class="panel-head"><div class="panel-title">Pending Purchases</div><div class="panel-subtitle"><?php echo $selectedSupplierId > 0 ? count($pendingPurchases) . ' pending purchase(s)' : 'Select a supplier to load pending purchases'; ?></div></div>
            <?php if ($pendingPurchases): ?>
                <div class="table-responsive">
                    <table class="table compact-table responsive-table">
                        <thead><tr><th>#</th><th>Purchase</th><th>Date</th><th>Grand Total</th><th>Paid</th><th>Balance</th><th>Status</th><th>Action</th></tr></thead>
                        <tbody>
                        <?php foreach ($pendingPurchases as $index => $purchase): ?>
                            <?php
                            $status = (string)($purchase['payment_status'] ?? 'Unpaid');
                            $statusClass = $status === 'Paid' ? 'status-paid' : ($status === 'Partial' ? 'status-partial' : 'status-unpaid');
                            ?>
                            <tr>
                                <td data-label="#"><?php echo $index + 1; ?></td>
                                <td class="main-column" data-label="Purchase"><div class="payment-no"><?php echo h($purchase['purchase_no'] ?? ''); ?></div></td>
                                <td data-label="Date"><?php echo !empty($purchase['purchase_date']) ? date('d M Y', strtotime($purchase['purchase_date'])) : '—'; ?></td>
                                <td data-label="Grand Total"><?php echo $canViewValue ? '₹' . money($purchase['grand_total'] ?? 0) : '••••'; ?></td>
                                <td data-label="Paid"><?php echo $canViewValue ? '₹' . money($purchase['paid_amount'] ?? 0) : '••••'; ?></td>
                                <td data-label="Balance"><strong><?php echo $canViewValue ? '₹' . money($purchase['balance_amount'] ?? 0) : '••••'; ?></strong></td>
                                <td data-label="Status"><span class="status-badge <?php echo $statusClass; ?>"><?php echo h($status); ?></span></td>
                                <td data-label="Action"><div class="d-flex gap-1 justify-content-end"><button type="button" class="btn btn-soft select-purchase" data-id="<?php echo (int)$purchase['id']; ?>" data-balance="<?php echo h($purchase['balance_amount'] ?? 0); ?>">Pay</button><a href="purchase-view.php?id=<?php echo (int)$purchase['id']; ?>" class="btn btn-light-custom" style="min-height:auto;padding:6px 9px">View</a></div></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state"><?php echo $selectedSupplierId > 0 ? 'No pending purchases found for this supplier.' : 'Select a supplier to view pending purchases.'; ?></div>
            <?php endif; ?>
        </div>

        <div class="panel">
            <div class="panel-head"><div class="panel-title">Recent Supplier Payments</div><div class="panel-subtitle">Latest 15 payment records.</div></div>
            <?php if ($recentPayments): ?>
                <div class="table-responsive">
                    <table class="table compact-table responsive-table">
                        <thead><tr><th>#</th><th>Payment</th><th>Date</th><th>Supplier</th><th>Purchase</th><th>Method</th><th>Reference</th><th>Amount</th><th>Notes</th></tr></thead>
                        <tbody>
                        <?php foreach ($recentPayments as $index => $row): ?>
                            <tr>
                                <td data-label="#"><?php echo $index + 1; ?></td>
                                <td class="main-column" data-label="Payment"><div class="payment-no"><?php echo h($row['payment_no'] ?? ''); ?></div></td>
                                <td data-label="Date"><?php echo !empty($row['payment_date']) ? date('d M Y', strtotime($row['payment_date'])) : '—'; ?></td>
                                <td data-label="Supplier"><?php echo h($row['supplier_name'] ?? ''); ?><div class="subtext"><?php echo h($row['supplier_code'] ?? ''); ?></div></td>
                                <td data-label="Purchase"><?php echo h($row['purchase_no'] ?? 'General'); ?></td>
                                <td data-label="Method"><?php echo h($row['method_name'] ?? '—'); ?></td>
                                <td data-label="Reference"><?php echo h($row['reference_no'] ?? '—'); ?></td>
                                <td data-label="Amount"><strong><?php echo $canViewValue ? '₹' . money($row['amount'] ?? 0) : '••••'; ?></strong></td>
                                <td data-label="Notes"><?php echo h($row['notes'] ?? '—'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">No supplier payments found.</div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php include('includes/footer.php'); ?>
</div>
</main>
<?php include('includes/script.php'); ?>
<script src="assets/js/script.js"></script>
<script>
(function(){
    'use strict';

    function toast(type,message){
        const element=document.createElement('div');
        element.className='theme-toast theme-toast-'+type;
        element.innerHTML='<i class="fa-solid '+(type==='success'?'fa-circle-check':'fa-circle-exclamation')+'"></i><span></span>';
        element.querySelector('span').textContent=message;
        document.body.appendChild(element);
        requestAnimationFrame(()=>element.classList.add('show'));
        setTimeout(()=>{element.classList.remove('show');setTimeout(()=>element.remove(),250)},3200);
    }

    const supplier=document.getElementById('supplier_id');
    if(supplier){
        supplier.addEventListener('change',function(){
            location.href=this.value?'supplier-payments.php?supplier_id='+encodeURIComponent(this.value):'supplier-payments.php';
        });
    }

    const purchase=document.getElementById('purchase_id');
    const amount=document.getElementById('amount');

    function applyPurchase(id,balance){
        if(purchase)purchase.value=String(id||'');
        if(amount){
            amount.value=parseFloat(balance||0).toFixed(2);
            amount.focus();
        }
    }

    document.addEventListener('click',function(event){
        const button=event.target.closest('.select-purchase');
        if(button)applyPurchase(button.dataset.id,button.dataset.balance);
    });

    if(purchase&&amount){
        purchase.addEventListener('change',function(){
            const option=this.options[this.selectedIndex];
            if(option&&option.value&&option.dataset.balance){
                amount.value=parseFloat(option.dataset.balance||0).toFixed(2);
            }
        });
    }

    const form=document.getElementById('supplierPaymentForm');
    if(form){
        form.addEventListener('submit',async function(event){
            event.preventDefault();

            const paymentMethod=document.getElementById('payment_method_id');

            if(!paymentMethod || paymentMethod.disabled || !paymentMethod.value){
                toast('error','Please select an active payment method.');
                paymentMethod?.focus();
                return;
            }

            const button=document.getElementById('savePaymentButton');
            if(!button)return;

            const old=button.innerHTML;
            button.disabled=true;
            button.innerHTML='<i class="fa-solid fa-spinner fa-spin me-1"></i>Saving...';

            try{
                const response=await fetch('api/supplier-payment-save.php',{
                    method:'POST',
                    body:new FormData(form),
                    credentials:'same-origin',
                    headers:{'X-Requested-With':'XMLHttpRequest'}
                });
                const result=await response.json().catch(()=>({success:false,message:'Invalid response received from the server.'}));
                if(!response.ok||!result.success)throw new Error(result.message||'Unable to save supplier payment.');
                toast('success',result.message);
                setTimeout(()=>location.href='supplier-payments.php?supplier_id='+encodeURIComponent(result.supplier_id)+'&msg=created',600);
            }catch(error){
                toast('error',error.message);
            }finally{
                button.disabled=false;
                button.innerHTML=old;
            }
        });
    }

    if(new URLSearchParams(location.search).get('msg')==='created'){
        toast('success','Supplier payment saved successfully.');
    }
})();
</script>
</body>
</html>
