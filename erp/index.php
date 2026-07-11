<?php
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

date_default_timezone_set((string) ($_SESSION['timezone'] ?? 'Asia/Kolkata'));

$configCandidates = [
  __DIR__ . '/config/config.php',
  __DIR__ . '/config.php',
  __DIR__ . '/super-admin/includes/config.php',
];

$configLoaded = false;
foreach ($configCandidates as $configFile) {
  if (is_file($configFile)) {
    require_once $configFile;
    $configLoaded = true;
    break;
  }
}

if (!$configLoaded || !isset($conn) || !($conn instanceof mysqli)) {
  die('Database configuration is not available.');
}

$conn->set_charset('utf8mb4');

if (empty($_SESSION['user_id'])) {
  header('Location: login.php');
  exit;
}

if (!function_exists('e')) {
  function e($value): string
  {
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
  }
}

/**
 * Load the logged-in user's effective role permissions from the database.
 * This keeps the page secure even when the session permission cache is missing
 * or was created before a role/permission change.
 */
function loadUserPermissions(mysqli $conn): array
{
  if (($_SESSION['user_type'] ?? '') === 'Platform Admin') {
    return ['__platform_admin__' => true];
  }

  $businessId = (int) ($_SESSION['business_id'] ?? 0);
  $roleId = (int) ($_SESSION['role_id'] ?? 0);

  if ($businessId <= 0 || $roleId <= 0) {
    return [];
  }

  $sql = "SELECT
            p.permission_code,
            mi.menu_code,
            mi.route_url,
            rp.can_open,
            rp.can_view_value,
            rp.can_view,
            rp.can_create,
            rp.can_update,
            rp.can_approve,
            rp.can_delete
          FROM role_permissions rp
          INNER JOIN permissions p
             ON p.id = rp.permission_id
            AND p.is_active = 1
          INNER JOIN menu_items mi
             ON mi.id = p.menu_item_id
            AND mi.is_active = 1
            AND mi.is_visible = 1
          WHERE rp.business_id = ?
            AND rp.role_id = ?";

  $stmt = $conn->prepare($sql);
  if (!$stmt) {
    return [];
  }

  $stmt->bind_param('ii', $businessId, $roleId);
  $stmt->execute();
  $result = $stmt->get_result();
  $permissions = [];

  while ($row = $result->fetch_assoc()) {
    $permissions[(string) $row['permission_code']] = $row;
  }

  $stmt->close();
  return $permissions;
}

/**
 * Verify that the current user is active and still has access to the selected
 * business/branch. A removed or inactive account is logged out immediately.
 */
function validateLoggedInUser(mysqli $conn): void
{
  $userId = (int) ($_SESSION['user_id'] ?? 0);
  if ($userId <= 0) {
    header('Location: login.php');
    exit;
  }

  $stmt = $conn->prepare('SELECT id, business_id, user_type, is_active FROM users WHERE id = ? LIMIT 1');
  if (!$stmt) {
    http_response_code(500);
    die('Unable to validate the current login session.');
  }

  $stmt->bind_param('i', $userId);
  $stmt->execute();
  $user = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$user || (int) $user['is_active'] !== 1) {
    session_unset();
    session_destroy();
    header('Location: login.php?reason=inactive');
    exit;
  }

  if (($user['user_type'] ?? '') === 'Platform Admin') {
    return;
  }

  $businessId = (int) ($_SESSION['business_id'] ?? 0);
  $branchId = (int) ($_SESSION['branch_id'] ?? 0);

  if ($businessId <= 0 || (int) $user['business_id'] !== $businessId || $branchId <= 0) {
    session_unset();
    session_destroy();
    header('Location: login.php?reason=scope');
    exit;
  }

  $branchStmt = $conn->prepare(
    'SELECT 1
       FROM user_branch_access uba
       INNER JOIN branches b
          ON b.id = uba.branch_id
         AND b.business_id = uba.business_id
         AND b.is_active = 1
      WHERE uba.user_id = ?
        AND uba.business_id = ?
        AND uba.branch_id = ?
      LIMIT 1'
  );

  if (!$branchStmt) {
    http_response_code(500);
    die('Unable to validate branch access.');
  }

  $branchStmt->bind_param('iii', $userId, $businessId, $branchId);
  $branchStmt->execute();
  $hasBranch = (bool) $branchStmt->get_result()->fetch_row();
  $branchStmt->close();

  if (!$hasBranch) {
    http_response_code(403);
    die('Access denied. This branch is not assigned to your user account.');
  }
}

validateLoggedInUser($conn);

// Refresh permission cache on every page load so role changes take effect immediately.
$_SESSION['permissions'] = loadUserPermissions($conn);

function dashboardPermission(string $action): bool
{
  if (($_SESSION['user_type'] ?? '') === 'Platform Admin') {
    return true;
  }

  $row = $_SESSION['permissions']['perm.dashboard'] ?? null;
  if (!is_array($row)) {
    return false;
  }

  $field = [
    'open' => 'can_open',
    'value' => 'can_view_value',
    'view' => 'can_view',
    'create' => 'can_create',
    'update' => 'can_update',
    'approve' => 'can_approve',
    'delete' => 'can_delete',
  ][$action] ?? '';

  return $field !== '' && (int) ($row[$field] ?? 0) === 1;
}

if (!dashboardPermission('open')) {
  http_response_code(403);
  die('Access denied. You do not have permission to open the dashboard.');
}

$canView = dashboardPermission('view');
$canViewValue = dashboardPermission('value');
$canCreate = dashboardPermission('create');
$canUpdate = dashboardPermission('update');
$canApprove = dashboardPermission('approve');
$canDelete = dashboardPermission('delete');

$businessId = isset($_SESSION['business_id']) ? (int) $_SESSION['business_id'] : 0;
$branchId = isset($_SESSION['branch_id']) ? (int) $_SESSION['branch_id'] : 0;
$isPlatformAdmin = (($_SESSION['user_type'] ?? '') === 'Platform Admin');
$currency = (string) ($_SESSION['currency_symbol'] ?? '₹');

$scopeBusiness = $businessId > 0 ? " = {$businessId}" : ' IS NOT NULL';
$scopeBranch = $branchId > 0 ? " = {$branchId}" : ' IS NOT NULL';

function dbOne(mysqli $conn, string $sql): array
{
  $result = $conn->query($sql);
  if (!$result) {
    return [];
  }
  return $result->fetch_assoc() ?: [];
}

function dbAll(mysqli $conn, string $sql): array
{
  $rows = [];
  $result = $conn->query($sql);
  if (!$result) {
    return $rows;
  }
  while ($row = $result->fetch_assoc()) {
    $rows[] = $row;
  }
  return $rows;
}

function moneyInr(float $amount, string $currency = '₹'): string
{
  $negative = $amount < 0;
  $amount = abs($amount);
  $formatted = number_format($amount, 2, '.', ',');
  [$whole, $decimal] = array_pad(explode('.', $formatted, 2), 2, '00');
  $whole = str_replace(',', '', $whole);
  if (strlen($whole) > 3) {
    $lastThree = substr($whole, -3);
    $rest = substr($whole, 0, -3);
    $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
    $whole = $rest . ',' . $lastThree;
  }
  return ($negative ? '-' : '') . $currency . ' ' . $whole . ($decimal !== '00' ? '.' . $decimal : '');
}

function maskedValue(bool $allowed, string $formatted): string
{
  return $allowed ? $formatted : '••••••';
}

function initials(string $name): string
{
  $parts = preg_split('/\s+/', trim($name)) ?: [];
  $value = '';
  foreach (array_slice($parts, 0, 2) as $part) {
    $value .= strtoupper(substr($part, 0, 1));
  }
  return $value !== '' ? $value : 'CU';
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

if ($businessId > 0) {
  $themeRow = dbOne($conn, "SELECT * FROM business_theme_settings WHERE business_id = {$businessId} LIMIT 1");
  foreach ($theme as $key => $default) {
    if (isset($themeRow[$key]) && $themeRow[$key] !== '') {
      $theme[$key] = $themeRow[$key];
    }
  }
}

$currentMonthStart = date('Y-m-01');
$nextMonthStart = date('Y-m-01', strtotime('+1 month'));
$previousMonthStart = date('Y-m-01', strtotime('-1 month'));
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));

$saleScope = "business_id{$scopeBusiness}" . ($branchId > 0 ? " AND branch_id = {$branchId}" : '');
$stockScope = "ps.business_id{$scopeBusiness}" . ($branchId > 0 ? " AND ps.branch_id = {$branchId}" : '');
$customerScope = "c.business_id{$scopeBusiness}";

$salesSummary = dbOne($conn, "
    SELECT
        COALESCE(SUM(CASE WHEN invoice_date >= '{$currentMonthStart}' AND invoice_date < '{$nextMonthStart}' AND workflow_status <> 'Cancelled' THEN grand_total ELSE 0 END),0) AS current_sales,
        COALESCE(SUM(CASE WHEN invoice_date >= '{$previousMonthStart}' AND invoice_date < '{$currentMonthStart}' AND workflow_status <> 'Cancelled' THEN grand_total ELSE 0 END),0) AS previous_sales,
        COALESCE(SUM(CASE WHEN invoice_date = '{$today}' AND workflow_status <> 'Cancelled' THEN grand_total ELSE 0 END),0) AS today_sales,
        COALESCE(SUM(CASE WHEN invoice_date = '{$yesterday}' AND workflow_status <> 'Cancelled' THEN grand_total ELSE 0 END),0) AS yesterday_sales,
        COALESCE(SUM(CASE WHEN workflow_status <> 'Cancelled' THEN balance_amount ELSE 0 END),0) AS outstanding,
        COUNT(DISTINCT CASE WHEN workflow_status <> 'Cancelled' AND balance_amount > 0 THEN customer_id END) AS outstanding_customers
    FROM sales
    WHERE {$saleScope}
");

$currentSales = (float) ($salesSummary['current_sales'] ?? 0);
$previousSales = (float) ($salesSummary['previous_sales'] ?? 0);
$todaySales = (float) ($salesSummary['today_sales'] ?? 0);
$yesterdaySales = (float) ($salesSummary['yesterday_sales'] ?? 0);
$salesGrowth = $previousSales > 0 ? (($currentSales - $previousSales) / $previousSales) * 100 : ($currentSales > 0 ? 100 : 0);
$todayGrowth = $yesterdaySales > 0 ? (($todaySales - $yesterdaySales) / $yesterdaySales) * 100 : ($todaySales > 0 ? 100 : 0);

$metalStock = dbAll($conn, "
    SELECT m.metal_name, m.metal_code,
           COALESCE(SUM(ps.net_weight),0) AS net_weight,
           COALESCE(SUM(ps.stock_value),0) AS stock_value
    FROM product_stock ps
    INNER JOIN products p ON p.id = ps.product_id AND p.business_id = ps.business_id
    LEFT JOIN metals m ON m.id = p.metal_id AND m.business_id = p.business_id
    WHERE {$stockScope}
    GROUP BY m.id, m.metal_name, m.metal_code
    ORDER BY stock_value DESC
");

$goldWeight = 0.0;
$goldValue = 0.0;
$silverWeight = 0.0;
$silverValue = 0.0;
$totalInventoryValue = 0.0;
foreach ($metalStock as $metal) {
  $name = strtolower((string) ($metal['metal_name'] ?? ''));
  $weight = (float) $metal['net_weight'];
  $value = (float) $metal['stock_value'];
  $totalInventoryValue += $value;
  if (str_contains($name, 'gold') && (str_contains($name, '22') || str_contains(strtolower((string) $metal['metal_code']), '22'))) {
    $goldWeight += $weight;
    $goldValue += $value;
  }
  if (str_contains($name, 'silver')) {
    $silverWeight += $weight;
    $silverValue += $value;
  }
}

$pendingOrders = dbOne($conn, "
    SELECT COUNT(*) AS pending_count, COALESCE(SUM(making_charge),0) AS pending_value
    FROM karigar_orders
    WHERE business_id{$scopeBusiness}
      " . ($branchId > 0 ? "AND branch_id = {$branchId}" : '') . "
      AND status IN ('Draft','Issued','In Progress','Partially Received')
");

$recentInvoices = $canView ? dbAll($conn, "
    SELECT id, invoice_no, invoice_date, customer_name, grand_total, payment_status
    FROM sales
    WHERE {$saleScope} AND workflow_status <> 'Cancelled'
    ORDER BY invoice_date DESC, invoice_time DESC, id DESC
    LIMIT 5
") : [];

$recentCustomers = $canView ? dbAll($conn, "
    SELECT c.id, c.customer_name, c.email,
           COALESCE(SUM(CASE WHEN s.workflow_status <> 'Cancelled' THEN s.grand_total ELSE 0 END),0) AS total_purchase
    FROM customers c
    LEFT JOIN sales s ON s.customer_id = c.id AND s.business_id = c.business_id
      " . ($branchId > 0 ? "AND s.branch_id = {$branchId}" : '') . "
    WHERE {$customerScope} AND c.is_active = 1
    GROUP BY c.id, c.customer_name, c.email
    ORDER BY c.created_at DESC, c.id DESC
    LIMIT 5
") : [];

$topCategories = $canView ? dbAll($conn, "
    SELECT pc.category_name, COALESCE(SUM(si.line_total),0) AS sales_amount
    FROM sale_items si
    INNER JOIN sales s ON s.id = si.sale_id AND s.business_id = si.business_id
    INNER JOIN products p ON p.id = si.product_id AND p.business_id = si.business_id
    LEFT JOIN product_categories pc ON pc.id = p.category_id AND pc.business_id = p.business_id
    WHERE si.business_id{$scopeBusiness}
      " . ($branchId > 0 ? "AND si.branch_id = {$branchId}" : '') . "
      AND s.invoice_date >= '{$currentMonthStart}' AND s.invoice_date < '{$nextMonthStart}'
      AND s.workflow_status <> 'Cancelled'
    GROUP BY pc.id, pc.category_name
    ORDER BY sales_amount DESC
    LIMIT 5
") : [];

$categoryTotal = array_sum(array_map(static fn($row) => (float) $row['sales_amount'], $topCategories));

$lowStock = $canView ? dbAll($conn, "
    SELECT p.product_name, p.product_code, p.minimum_stock_qty, COALESCE(ps.quantity,0) AS quantity
    FROM products p
    LEFT JOIN product_stock ps ON ps.product_id = p.id AND ps.business_id = p.business_id
      " . ($branchId > 0 ? "AND ps.branch_id = {$branchId}" : '') . "
    WHERE p.business_id{$scopeBusiness} AND p.is_active = 1 AND p.track_stock = 1
    GROUP BY p.id, p.product_name, p.product_code, p.minimum_stock_qty
    HAVING quantity <= p.minimum_stock_qty
    ORDER BY quantity ASC, p.product_name ASC
    LIMIT 3
") : [];

$taxSummary = dbOne($conn, "
    SELECT
        COALESCE(SUM(taxable_amount),0) AS taxable_amount,
        COALESCE(SUM(cgst_amount),0) AS cgst_amount,
        COALESCE(SUM(sgst_amount),0) AS sgst_amount,
        COALESCE(SUM(igst_amount),0) AS igst_amount,
        COALESCE(SUM(grand_total),0) AS total_collected
    FROM sales
    WHERE {$saleScope}
      AND invoice_date >= '{$currentMonthStart}' AND invoice_date < '{$nextMonthStart}'
      AND workflow_status <> 'Cancelled'
");
$totalGst = (float) ($taxSummary['cgst_amount'] ?? 0) + (float) ($taxSummary['sgst_amount'] ?? 0) + (float) ($taxSummary['igst_amount'] ?? 0);

$chartRows = dbAll($conn, "
    SELECT invoice_date, COALESCE(SUM(grand_total),0) AS amount
    FROM sales
    WHERE {$saleScope}
      AND invoice_date >= '{$currentMonthStart}' AND invoice_date < '{$nextMonthStart}'
      AND workflow_status <> 'Cancelled'
    GROUP BY invoice_date
    ORDER BY invoice_date ASC
");
$chartMap = [];
foreach ($chartRows as $row) {
  $chartMap[$row['invoice_date']] = (float) $row['amount'];
}
$daysInMonth = (int) date('t');
$chartValues = [];
for ($day = 1; $day <= $daysInMonth; $day++) {
  $dateKey = date('Y-m-') . str_pad((string) $day, 2, '0', STR_PAD_LEFT);
  $chartValues[] = $chartMap[$dateKey] ?? 0.0;
}
$chartMax = max(1, ...$chartValues);
$chartPoints = [];
foreach ($chartValues as $index => $value) {
  $x = 45 + (($daysInMonth > 1 ? $index / ($daysInMonth - 1) : 0) * 615);
  $y = 220 - (($value / $chartMax) * 180);
  $chartPoints[] = round($x, 2) . ',' . round($y, 2);
}
$chartPointString = implode(' ', $chartPoints);

$businessName = (string) ($_SESSION['business_name'] ?? 'Jewellery ERP');
$branchName = (string) ($_SESSION['branch_name'] ?? '');
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo e($businessName); ?> - Dashboard</title>
  <?php include('includes/links.php'); ?>
  <style>
    :root {
      --primary:
        <?php echo e($theme['primary_color']); ?>
      ;
      --primary-dark:
        <?php echo e($theme['primary_dark_color']); ?>
      ;
      --primary-soft:
        <?php echo e($theme['primary_soft_color']); ?>
      ;
      --sidebar-gradient-1:
        <?php echo e($theme['sidebar_gradient_1']); ?>
      ;
      --sidebar-gradient-2:
        <?php echo e($theme['sidebar_gradient_2']); ?>
      ;
      --sidebar-gradient-3:
        <?php echo e($theme['sidebar_gradient_3']); ?>
      ;
      --page-bg:
        <?php echo e($theme['page_background']); ?>
      ;
      --card-bg:
        <?php echo e($theme['card_background']); ?>
      ;
      --text-color:
        <?php echo e($theme['text_color']); ?>
      ;
      --muted-color:
        <?php echo e($theme['muted_text_color']); ?>
      ;
      --border-color:
        <?php echo e($theme['border_color']); ?>
      ;
      --sidebar-width:
        <?php echo (int) $theme['sidebar_width_px']; ?>px;
      --radius:
        <?php echo (int) $theme['border_radius_px']; ?>px;
    }

    body {
      background: var(--page-bg);
      color: var(--text-color);
      font-family:
        <?php echo json_encode((string) $theme['font_family']); ?>
        , sans-serif;
    }

    .sidebar {
      background: linear-gradient(180deg, var(--sidebar-gradient-1), var(--sidebar-gradient-2), var(--sidebar-gradient-3)) !important;
    }

    .card-panel {
      background: var(--card-bg);
      border-color: var(--border-color);
      border-radius: var(--radius) !important;
      overflow: hidden;
    }

    /* Apply the business theme radius consistently to every dashboard card */
    .metric-card,
    .tax-box,
    .tax-total,
    .customer-item,
    .category-row,
    .chart-wrap,
    .table-responsive,
    .invoice-table,
    .empty-state {
      border-radius: var(--radius) !important;
    }

    .metric-card,
    .tax-box,
    .tax-total,
    .chart-wrap,
    .table-responsive,
    .empty-state {
      overflow: hidden;
    }

    .section-head {
      border-radius: var(--radius) var(--radius) 0 0;
    }

    .mini-btn,
    .metric-icon,
    .category-icon,
    .status,
    .progress,
    .progress-bar,
    .customer-avatar {
      border-radius: calc(var(--radius) * .65) !important;
    }

    .invoice-table {
      border-collapse: separate;
      border-spacing: 0;
      margin-bottom: 0;
    }

    .invoice-table thead tr:first-child th:first-child {
      border-top-left-radius: var(--radius);
    }

    .invoice-table thead tr:first-child th:last-child {
      border-top-right-radius: var(--radius);
    }

    .invoice-table tbody tr:last-child td:first-child {
      border-bottom-left-radius: var(--radius);
    }

    .invoice-table tbody tr:last-child td:last-child {
      border-bottom-right-radius: var(--radius);
    }

    .metric-icon,
    .category-icon {
      color: var(--primary-dark);
      background: var(--primary-soft);
    }

    .progress-bar,
    .chart-line {
      stroke: var(--primary);
      background-color: var(--primary);
    }

    .chart-area {
      fill: color-mix(in srgb, var(--primary) 20%, transparent);
    }

    .chart-dot circle {
      fill: var(--primary);
    }

    .tax-total {
      background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    }

    .masked-value {
      letter-spacing: 2px;
      color: var(--muted-color);
    }

    .empty-state {
      padding: 24px 12px;
      text-align: center;
      color: var(--muted-color);
      font-size: 12px;
    }

    .dashboard-context {
      font-size: 11px;
      color: var(--muted-color);
    }


    /* Force rounded corners on every dashboard card/container */
    .content-wrap .card,
    .content-wrap .card-panel,
    .content-wrap .metric-card,
    .content-wrap .tax-box,
    .content-wrap .tax-total,
    .content-wrap .chart-wrap,
    .content-wrap .table-responsive,
    .content-wrap .customer-item,
    .content-wrap .category-row,
    .content-wrap .empty-state,
    .content-wrap .section-body,
    .content-wrap .invoice-table {
      border-radius: var(--radius) !important;
    }

    .content-wrap .card,
    .content-wrap .card-panel,
    .content-wrap .metric-card,
    .content-wrap .tax-box,
    .content-wrap .tax-total,
    .content-wrap .chart-wrap,
    .content-wrap .table-responsive {
      overflow: hidden !important;
    }

    .content-wrap .card-panel {
      border: 1px solid var(--border-color) !important;
      background: var(--card-bg) !important;
    }

    .content-wrap .row > [class*="col-"] > .card-panel,
    .content-wrap .row > [class*="col-"] > .card,
    .content-wrap section > [class*="col-"] > .card-panel,
    .content-wrap section > [class*="col-"] > .card {
      border-radius: var(--radius) !important;
    }

    .content-wrap .section-head {
      border-radius: var(--radius) var(--radius) 0 0 !important;
    }

    .content-wrap .section-body:last-child,
    .content-wrap .table-responsive:last-child {
      border-radius: 0 0 var(--radius) var(--radius) !important;
    }



    /* Dark mode compatibility: keep cards, text, tables and charts readable */
    body.dark-mode,
    body[data-theme="dark"],
    html.dark-mode body,
    html[data-theme="dark"] body {
      --page-bg: #0f151b;
      --card-bg: #182129;
      --text-color: #f3f6f8;
      --muted-color: #9aa7b3;
      --border-color: #2c3944;
    }

    body.dark-mode .content-wrap,
    body[data-theme="dark"] .content-wrap,
    html.dark-mode body .content-wrap,
    html[data-theme="dark"] body .content-wrap {
      background: var(--page-bg) !important;
      color: var(--text-color) !important;
    }

    body.dark-mode .card-panel,
    body.dark-mode .metric-card,
    body.dark-mode .tax-box,
    body.dark-mode .chart-wrap,
    body.dark-mode .table-responsive,
    body[data-theme="dark"] .card-panel,
    body[data-theme="dark"] .metric-card,
    body[data-theme="dark"] .tax-box,
    body[data-theme="dark"] .chart-wrap,
    body[data-theme="dark"] .table-responsive,
    html.dark-mode body .card-panel,
    html.dark-mode body .metric-card,
    html.dark-mode body .tax-box,
    html.dark-mode body .chart-wrap,
    html.dark-mode body .table-responsive,
    html[data-theme="dark"] body .card-panel,
    html[data-theme="dark"] body .metric-card,
    html[data-theme="dark"] body .tax-box,
    html[data-theme="dark"] body .chart-wrap,
    html[data-theme="dark"] body .table-responsive {
      background: var(--card-bg) !important;
      color: var(--text-color) !important;
      border-color: var(--border-color) !important;
    }

    body.dark-mode .section-head,
    body.dark-mode .section-body,
    body[data-theme="dark"] .section-head,
    body[data-theme="dark"] .section-body,
    html.dark-mode body .section-head,
    html.dark-mode body .section-body,
    html[data-theme="dark"] body .section-head,
    html[data-theme="dark"] body .section-body {
      background: transparent !important;
      color: var(--text-color) !important;
      border-color: var(--border-color) !important;
    }

    body.dark-mode .section-title,
    body.dark-mode .metric-value,
    body.dark-mode .metric-label,
    body.dark-mode .fw-bold,
    body.dark-mode .fw-semibold,
    body.dark-mode strong,
    body[data-theme="dark"] .section-title,
    body[data-theme="dark"] .metric-value,
    body[data-theme="dark"] .metric-label,
    body[data-theme="dark"] .fw-bold,
    body[data-theme="dark"] .fw-semibold,
    body[data-theme="dark"] strong,
    html.dark-mode body .section-title,
    html.dark-mode body .metric-value,
    html.dark-mode body .metric-label,
    html.dark-mode body .fw-bold,
    html.dark-mode body .fw-semibold,
    html.dark-mode body strong,
    html[data-theme="dark"] body .section-title,
    html[data-theme="dark"] body .metric-value,
    html[data-theme="dark"] body .metric-label,
    html[data-theme="dark"] body .fw-bold,
    html[data-theme="dark"] body .fw-semibold,
    html[data-theme="dark"] body strong {
      color: var(--text-color) !important;
    }

    body.dark-mode .text-muted,
    body.dark-mode .small-muted,
    body.dark-mode .metric-meta,
    body.dark-mode .dashboard-context,
    body.dark-mode .empty-state,
    body[data-theme="dark"] .text-muted,
    body[data-theme="dark"] .small-muted,
    body[data-theme="dark"] .metric-meta,
    body[data-theme="dark"] .dashboard-context,
    body[data-theme="dark"] .empty-state,
    html.dark-mode body .text-muted,
    html.dark-mode body .small-muted,
    html.dark-mode body .metric-meta,
    html.dark-mode body .dashboard-context,
    html.dark-mode body .empty-state,
    html[data-theme="dark"] body .text-muted,
    html[data-theme="dark"] body .small-muted,
    html[data-theme="dark"] body .metric-meta,
    html[data-theme="dark"] body .dashboard-context,
    html[data-theme="dark"] body .empty-state {
      color: var(--muted-color) !important;
    }

    body.dark-mode .invoice-table,
    body.dark-mode .invoice-table th,
    body.dark-mode .invoice-table td,
    body[data-theme="dark"] .invoice-table,
    body[data-theme="dark"] .invoice-table th,
    body[data-theme="dark"] .invoice-table td,
    html.dark-mode body .invoice-table,
    html.dark-mode body .invoice-table th,
    html.dark-mode body .invoice-table td,
    html[data-theme="dark"] body .invoice-table,
    html[data-theme="dark"] body .invoice-table th,
    html[data-theme="dark"] body .invoice-table td {
      background: transparent !important;
      color: var(--text-color) !important;
      border-color: var(--border-color) !important;
    }

    body.dark-mode .invoice-table thead,
    body[data-theme="dark"] .invoice-table thead,
    html.dark-mode body .invoice-table thead,
    html[data-theme="dark"] body .invoice-table thead {
      background: #121a21 !important;
    }

    body.dark-mode .mini-btn,
    body[data-theme="dark"] .mini-btn,
    html.dark-mode body .mini-btn,
    html[data-theme="dark"] body .mini-btn {
      background: #121a21 !important;
      color: #f8fafc !important;
      border-color: #34424e !important;
    }

    body.dark-mode .metric-icon,
    body.dark-mode .category-icon,
    body[data-theme="dark"] .metric-icon,
    body[data-theme="dark"] .category-icon,
    html.dark-mode body .metric-icon,
    html.dark-mode body .category-icon,
    html[data-theme="dark"] body .metric-icon,
    html[data-theme="dark"] body .category-icon {
      background: #2b2414 !important;
      color: var(--primary) !important;
    }

    body.dark-mode .chart-grid,
    body[data-theme="dark"] .chart-grid,
    html.dark-mode body .chart-grid,
    html[data-theme="dark"] body .chart-grid {
      stroke: #394753 !important;
    }

    body.dark-mode hr,
    body[data-theme="dark"] hr,
    html.dark-mode body hr,
    html[data-theme="dark"] body hr {
      border-color: var(--border-color) !important;
      opacity: 1;
    }

  </style>
</head>

<body>
  <?php include('includes/sidebar.php'); ?>

  <main class="app-main">
    <?php include('includes/nav.php'); ?>

    <div class="content-wrap">
     

      <section class="row g-2">
        <div class="col-12 col-sm-6 col-xl-2">
          <div class="card-panel metric-card d-flex gap-2">
            <div class="metric-icon"><i class="fa-solid fa-chart-line"></i></div>
            <div>
              <p class="metric-label">Total Sales</p>
              <div class="metric-value total-sales-value <?php echo !$canViewValue ? 'masked-value' : ''; ?>">
                <?php echo e(maskedValue($canViewValue, moneyInr($currentSales, $currency))); ?></div>
              <div class="metric-meta <?php echo $salesGrowth >= 0 ? 'text-success' : 'text-danger'; ?>"><i
                  class="fa-solid fa-arrow-trend-<?php echo $salesGrowth >= 0 ? 'up' : 'down'; ?>"></i>
                <?php echo number_format(abs($salesGrowth), 1); ?>% <span class="text-muted">vs last month</span></div>
            </div>
          </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-2">
          <div class="card-panel metric-card d-flex gap-2">
            <div class="metric-icon"><i class="fa-regular fa-receipt"></i></div>
            <div>
              <p class="metric-label">Today's Billing</p>
              <div class="metric-value <?php echo !$canViewValue ? 'masked-value' : ''; ?>">
                <?php echo e(maskedValue($canViewValue, moneyInr($todaySales, $currency))); ?></div>
              <div class="metric-meta <?php echo $todayGrowth >= 0 ? 'text-success' : 'text-danger'; ?>"><i
                  class="fa-solid fa-arrow-trend-<?php echo $todayGrowth >= 0 ? 'up' : 'down'; ?>"></i>
                <?php echo number_format(abs($todayGrowth), 1); ?>% <span class="text-muted">vs yesterday</span></div>
            </div>
          </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-2">
          <div class="card-panel metric-card d-flex gap-2">
            <div class="metric-icon"><i class="fa-solid fa-cubes-stacked"></i></div>
            <div>
              <p class="metric-label">Gold Stock (22K)</p>
              <div class="metric-value"><?php echo number_format($goldWeight / 1000, 3); ?> <small>kg</small></div>
              <div class="metric-meta <?php echo !$canViewValue ? 'masked-value' : ''; ?>">
                <?php echo e(maskedValue($canViewValue, moneyInr($goldValue, $currency) . ' Value')); ?></div>
            </div>
          </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-2">
          <div class="card-panel metric-card d-flex gap-2">
            <div class="metric-icon"><i class="fa-solid fa-cubes"></i></div>
            <div>
              <p class="metric-label">Silver Stock</p>
              <div class="metric-value"><?php echo number_format($silverWeight / 1000, 3); ?> <small>kg</small></div>
              <div class="metric-meta <?php echo !$canViewValue ? 'masked-value' : ''; ?>">
                <?php echo e(maskedValue($canViewValue, moneyInr($silverValue, $currency) . ' Value')); ?></div>
            </div>
          </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-2">
          <div class="card-panel metric-card d-flex gap-2">
            <div class="metric-icon"><i class="fa-regular fa-clipboard"></i></div>
            <div>
              <p class="metric-label">Pending Orders</p>
              <div class="metric-value"><?php echo (int) ($pendingOrders['pending_count'] ?? 0); ?></div>
              <div class="metric-meta <?php echo !$canViewValue ? 'masked-value' : ''; ?>">
                <?php echo e(maskedValue($canViewValue, moneyInr((float) ($pendingOrders['pending_value'] ?? 0), $currency) . ' Value')); ?>
              </div>
            </div>
          </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-2">
          <div class="card-panel metric-card d-flex gap-2">
            <div class="metric-icon"><i class="fa-regular fa-wallet"></i></div>
            <div>
              <p class="metric-label">Outstanding</p>
              <div class="metric-value <?php echo !$canViewValue ? 'masked-value' : ''; ?>">
                <?php echo e(maskedValue($canViewValue, moneyInr((float) ($salesSummary['outstanding'] ?? 0), $currency))); ?>
              </div>
              <div class="metric-meta">From <span
                  class="text-danger fw-semibold"><?php echo (int) ($salesSummary['outstanding_customers'] ?? 0); ?>
                  Customers</span></div>
            </div>
          </div>
        </div>
      </section>

      <section class="row g-2 mt-0">
        <div class="col-12 col-xl-5">
          <div class="card-panel h-100">
            <div class="section-head">
              <div>
                <h2 class="section-title">Sales Overview</h2>
                <div class="fs-5 fw-bold mt-2 <?php echo !$canViewValue ? 'masked-value' : ''; ?>">
                  <?php echo e(maskedValue($canViewValue, moneyInr($currentSales, $currency))); ?> <span
                    class="small <?php echo $salesGrowth >= 0 ? 'text-success' : 'text-danger'; ?> fw-medium"><?php echo $salesGrowth >= 0 ? '↑' : '↓'; ?>
                    <?php echo number_format(abs($salesGrowth), 1); ?>% <span class="text-muted">vs last
                      month</span></span></div>
              </div><button class="mini-btn" type="button">This Month <i
                  class="fa-solid fa-chevron-down ms-2"></i></button>
            </div>
            <div class="section-body">
              <?php if ($canView): ?>
                <div class="chart-wrap">
                  <svg viewBox="0 0 680 250" preserveAspectRatio="none" aria-label="Current month sales chart">
                    <line x1="45" y1="40" x2="660" y2="40" class="chart-grid" />
                    <line x1="45" y1="100" x2="660" y2="100" class="chart-grid" />
                    <line x1="45" y1="160" x2="660" y2="160" class="chart-grid" />
                    <line x1="45" y1="220" x2="660" y2="220" class="chart-grid" />
                    <polygon points="45,220 <?php echo e($chartPointString); ?> 660,220" class="chart-area"></polygon>
                    <polyline points="<?php echo e($chartPointString); ?>" class="chart-line" fill="none"
                      stroke-width="3"></polyline>
                    <g fill="#8a939f" font-size="11">
                      <text x="45" y="244">01</text>
                      <text x="190" y="244">08</text>
                      <text x="340" y="244">15</text>
                      <text x="490" y="244">22</text>
                      <text x="630" y="244"><?php echo $daysInMonth; ?></text>
                    </g>
                  </svg>
                </div>
              <?php else: ?>
                <div class="empty-state">You do not have permission to view dashboard details.</div><?php endif; ?>
            </div>
          </div>
        </div>
        <div class="col-12 col-xl-4">
          <div class="card-panel h-100">
            <div class="section-head">
              <h2 class="section-title">Recent Invoices</h2><?php if ($canView): ?><a
                  class="mini-btn text-decoration-none" href="sales.php">View All</a><?php endif; ?>
            </div>
            <div class="table-responsive">
              <table class="table invoice-table">
                <thead>
                  <tr>
                    <th style="width:24%">Invoice No.</th>
                    <th style="width:28%">Customer</th>
                    <th style="width:19%">Date</th>
                    <th style="width:18%">Total</th>
                    <th style="width:11%">Status</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!$canView): ?>
                    <tr>
                      <td colspan="5" class="empty-state">Permission required.</td>
                    </tr>
                  <?php elseif (!$recentInvoices): ?>
                    <tr>
                      <td colspan="5" class="empty-state">No invoices found.</td>
                    </tr>
                  <?php else:
                    foreach ($recentInvoices as $invoice): ?>
                      <tr>
                        <td><?php echo e($invoice['invoice_no']); ?></td>
                        <td><?php echo e($invoice['customer_name'] ?: 'Walk-in Customer'); ?></td>
                        <td><?php echo e(date('d M Y', strtotime($invoice['invoice_date']))); ?></td>
                        <td class="<?php echo !$canViewValue ? 'masked-value' : ''; ?>">
                          <?php echo e(maskedValue($canViewValue, moneyInr((float) $invoice['grand_total'], $currency))); ?>
                        </td>
                        <td><span
                            class="status <?php echo $invoice['payment_status'] === 'Paid' ? 'status-paid' : 'status-unpaid'; ?>"><?php echo e($invoice['payment_status']); ?></span>
                        </td>
                      </tr>
                    <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
        <div class="col-12 col-xl-3">
          <div class="card-panel h-100">
            <div class="section-head">
              <h2 class="section-title">Recent Customers</h2><?php if ($canView): ?><a
                  class="mini-btn text-decoration-none" href="customers.php">View All</a><?php endif; ?>
            </div>
            <div>
              <?php if (!$canView): ?>
                <div class="empty-state">Permission required.</div>
              <?php elseif (!$recentCustomers): ?>
                <div class="empty-state">No customers found.</div>
              <?php else:
                foreach ($recentCustomers as $customer): ?>
                  <div class="customer-item">
                    <div class="customer-avatar"><?php echo e(initials($customer['customer_name'])); ?></div>
                    <div class="flex-grow-1 overflow-hidden">
                      <div class="fw-semibold text-truncate"><?php echo e($customer['customer_name']); ?></div>
                      <div class="small-muted text-truncate"><?php echo e($customer['email'] ?: 'No email'); ?></div>
                    </div>
                    <div class="text-end">
                      <div class="fw-semibold <?php echo !$canViewValue ? 'masked-value' : ''; ?>">
                        <?php echo e(maskedValue($canViewValue, moneyInr((float) $customer['total_purchase'], $currency))); ?>
                      </div>
                      <div class="small-muted">Total Purchase</div>
                    </div>
                  </div>
                <?php endforeach; endif; ?>
            </div>
          </div>
        </div>
      </section>

      <section class="row g-2 mt-0 bottom-compact-row">
        <div class="col-12 col-xl-4">
          <div class="card-panel h-100">
            <div class="section-head">
              <h2 class="section-title">Top Selling Categories</h2><button class="mini-btn" type="button">This Month <i
                  class="fa-solid fa-chevron-down ms-2"></i></button>
            </div>
            <div class="section-body">
              <?php if (!$canView): ?>
                <div class="empty-state">Permission required.</div>
              <?php elseif (!$topCategories): ?>
                <div class="empty-state">No category sales found.</div>
              <?php else:
                foreach ($topCategories as $category):
                  $percent = $categoryTotal > 0 ? ((float) $category['sales_amount'] / $categoryTotal) * 100 : 0; ?>
                  <div class="category-row">
                    <div class="category-icon"><i class="fa-solid fa-gem"></i></div>
                    <div class="flex-grow-1">
                      <div class="d-flex justify-content-between gap-2"><strong
                          class="text-truncate"><?php echo e($category['category_name'] ?: 'Uncategorized'); ?></strong><span
                          class="<?php echo !$canViewValue ? 'masked-value' : ''; ?>"><?php echo e(maskedValue($canViewValue, moneyInr((float) $category['sales_amount'], $currency))); ?></span><span
                          class="text-muted"><?php echo number_format($percent, 0); ?>%</span></div>
                      <div class="progress mt-2">
                        <div class="progress-bar" style="width:<?php echo min(100, max(0, $percent)); ?>%"></div>
                      </div>
                    </div>
                  </div>
                <?php endforeach; endif; ?>
            </div>
          </div>
        </div>
        <div class="col-12 col-xl-4">
          <div class="card-panel h-100">
            <div class="section-head">
              <h2 class="section-title">Inventory Summary</h2><?php if ($canView): ?><a
                  class="mini-btn text-decoration-none" href="stock.php">View All</a><?php endif; ?>
            </div>
            <div class="section-body">
              <div class="row g-2">
                <div class="col-md-6">
                  <div class="tax-box"><strong>By Metal</strong>
                    <div class="d-flex align-items-center gap-3 mt-3">
                      <div class="donut"></div>
                      <div class="small text-muted">
                        <?php if (!$metalStock): ?>
                          <div>No stock found.</div>
                        <?php else:
                          foreach (array_slice($metalStock, 0, 4) as $metal): ?>
                            <div class="mb-2">● <?php echo e($metal['metal_name'] ?: 'Other'); ?> &nbsp;
                              <?php echo number_format((float) $metal['net_weight'] / 1000, 3); ?> kg</div>
                          <?php endforeach; endif; ?>
                      </div>
                    </div>
                    <hr>
                    <div class="small text-muted">Total Inventory Value</div>
                    <div class="fs-5 fw-bold <?php echo !$canViewValue ? 'masked-value' : ''; ?>">
                      <?php echo e(maskedValue($canViewValue, moneyInr($totalInventoryValue, $currency))); ?></div>
                  </div>
                </div>
                <div class="col-md-6">
                  <div class="tax-box"><strong>Low Stock Alerts</strong>
                    <?php if (!$canView): ?>
                      <div class="empty-state">Permission required.</div>
                    <?php elseif (!$lowStock): ?>
                      <div class="empty-state">No low-stock items.</div>
                    <?php else:
                      foreach ($lowStock as $item): ?>
                        <div class="d-flex gap-3 mt-3">
                          <div class="fs-4 text-warning"><i class="fa-solid fa-ring"></i></div>
                          <div>
                            <div class="fw-semibold"><?php echo e($item['product_name']); ?></div>
                            <div class="small-muted">SKU: <?php echo e($item['product_code']); ?></div>
                            <div class="small text-danger">Stock: <?php echo number_format((float) $item['quantity'], 3); ?>
                            </div>
                          </div>
                        </div>
                      <?php endforeach; endif; ?>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="col-12 col-xl-4">
          <div class="card-panel h-100">
            <div class="section-head">
              <h2 class="section-title">GST / Tax Summary</h2><button class="mini-btn" type="button">This Month <i
                  class="fa-solid fa-chevron-down ms-2"></i></button>
            </div>
            <div class="section-body">
              <div class="row g-2">
                <div class="col-4">
                  <div class="tax-box">
                    <div class="small text-muted">Taxable Amount</div>
                    <div class="fw-bold mt-2 <?php echo !$canViewValue ? 'masked-value' : ''; ?>">
                      <?php echo e(maskedValue($canViewValue, moneyInr((float) ($taxSummary['taxable_amount'] ?? 0), $currency))); ?>
                    </div>
                  </div>
                </div>
                <div class="col-4">
                  <div class="tax-box">
                    <div class="small text-muted">CGST</div>
                    <div class="fw-bold mt-2 <?php echo !$canViewValue ? 'masked-value' : ''; ?>">
                      <?php echo e(maskedValue($canViewValue, moneyInr((float) ($taxSummary['cgst_amount'] ?? 0), $currency))); ?>
                    </div>
                  </div>
                </div>
                <div class="col-4">
                  <div class="tax-box">
                    <div class="small text-muted">SGST</div>
                    <div class="fw-bold mt-2 <?php echo !$canViewValue ? 'masked-value' : ''; ?>">
                      <?php echo e(maskedValue($canViewValue, moneyInr((float) ($taxSummary['sgst_amount'] ?? 0), $currency))); ?>
                    </div>
                  </div>
                </div>
                <div class="col-4">
                  <div class="tax-box">
                    <div class="small text-muted">IGST</div>
                    <div class="fw-bold mt-2 <?php echo !$canViewValue ? 'masked-value' : ''; ?>">
                      <?php echo e(maskedValue($canViewValue, moneyInr((float) ($taxSummary['igst_amount'] ?? 0), $currency))); ?>
                    </div>
                  </div>
                </div>
                <div class="col-8">
                  <div class="tax-total">
                    <div class="small text-white-50">Total GST</div>
                    <div class="fs-5 fw-bold mt-2 <?php echo !$canViewValue ? 'masked-value' : ''; ?>">
                      <?php echo e(maskedValue($canViewValue, moneyInr($totalGst, $currency))); ?></div>
                  </div>
                </div>
              </div>
              <div class="tax-box mt-2 d-flex align-items-center gap-3">
                <div class="metric-icon"><i class="fa-regular fa-file-invoice-dollar"></i></div>
                <div>
                  <div class="small text-muted">Total Collected (Incl. Tax)</div>
                  <div class="fs-5 fw-bold <?php echo !$canViewValue ? 'masked-value' : ''; ?>">
                    <?php echo e(maskedValue($canViewValue, moneyInr((float) ($taxSummary['total_collected'] ?? 0), $currency))); ?>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      <?php include('includes/footer.php'); ?>
    </div>
  </main>

  <div class="offcanvas-backdrop fade" id="mobileBackdrop" style="display:none"></div>
  <?php include('includes/script.php'); ?>
  <script src="assets/js/script.js"></script>
</body>

</html>