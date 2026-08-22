<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));

foreach ([
    __DIR__ . '/config/config.php',
    __DIR__ . '/config.php',
    __DIR__ . '/includes/config.php',
    __DIR__ . '/super-admin/includes/config.php',
] as $configFile) {
    if (is_file($configFile)) {
        require_once $configFile;
        break;
    }
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database configuration is not available.');
}
$conn->set_charset('utf8mb4');

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

function e($value)
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function stockReportPermission(mysqli $conn)
{
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin') {
        return true;
    }

    $permissionCodes = ['perm.reports.stock', 'perm.inventory.stock', 'perm.inventory'];
    $sessionPermissions = $_SESSION['permissions'] ?? [];
    foreach ($permissionCodes as $code) {
        if (isset($sessionPermissions[$code])) {
            $row = $sessionPermissions[$code];
            if ((int)($row['can_open'] ?? 0) === 1 || (int)($row['can_view'] ?? 0) === 1) {
                return true;
            }
        }
    }

    $businessId = (int)($_SESSION['business_id'] ?? 0);
    $roleId = (int)($_SESSION['role_id'] ?? 0);
    if ($businessId <= 0 || $roleId <= 0) {
        return false;
    }

    $sql = "SELECT MAX(GREATEST(COALESCE(rp.can_open,0),COALESCE(rp.can_view,0))) AS allowed
            FROM role_permissions rp
            INNER JOIN permissions p ON p.id=rp.permission_id
            WHERE rp.business_id=? AND rp.role_id=? AND p.is_active=1
              AND p.permission_code IN ('perm.reports.stock','perm.inventory.stock','perm.inventory')";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ii', $businessId, $roleId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['allowed'] ?? 0) === 1;
}

if (!stockReportPermission($conn)) {
    http_response_code(403);
    die('Access denied. You do not have permission to view the stock report.');
}

$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? ($_SESSION['default_branch_id'] ?? 0));
if ($businessId <= 0 || $branchId <= 0) {
    http_response_code(403);
    die('A valid business and branch must be selected.');
}

$today = date('Y-m-d');
$fromDate = trim((string)($_GET['from_date'] ?? $today));
$toDate = trim((string)($_GET['to_date'] ?? $today));
$search = trim((string)($_GET['search'] ?? ''));
$categoryId = max(0, (int)($_GET['category_id'] ?? 0));
$metalId = max(0, (int)($_GET['metal_id'] ?? 0));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) {
    $fromDate = $today;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
    $toDate = $today;
}
if ($fromDate > $toDate) {
    $tmp = $fromDate;
    $fromDate = $toDate;
    $toDate = $tmp;
}

$categories = [];
$stmt = $conn->prepare('SELECT id,category_name FROM product_categories WHERE business_id=? AND is_active=1 ORDER BY category_name');
if ($stmt) {
    $stmt->bind_param('i', $businessId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $categories[] = $row;
    }
    $stmt->close();
}

$metals = [];
$stmt = $conn->prepare('SELECT id,metal_name FROM metals WHERE business_id=? AND is_active=1 ORDER BY metal_name');
if ($stmt) {
    $stmt->bind_param('i', $businessId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $metals[] = $row;
    }
    $stmt->close();
}

$where = ['p.business_id=?'];
$params = [$businessId];
$types = 'i';

if ($categoryId > 0) {
    $where[] = 'p.category_id=?';
    $params[] = $categoryId;
    $types .= 'i';
}
if ($metalId > 0) {
    $where[] = 'p.metal_id=?';
    $params[] = $metalId;
    $types .= 'i';
}
if ($search !== '') {
    $where[] = '(p.product_name LIKE ? OR p.product_code LIKE ? OR COALESCE(p.barcode,\'\') LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'sss';
}

$fromStart = $fromDate . ' 00:00:00';
$toExclusive = date('Y-m-d H:i:s', strtotime($toDate . ' +1 day'));

/*
 * Historical stock is calculated from stock_movements:
 * Opening = all movements before From Date.
 * Period In/Out = movements inside selected date range.
 * Closing = Opening + Period In - Period Out.
 */
$sql = "SELECT
            p.id,p.product_code,p.barcode,p.product_name,p.product_type,p.purity,p.gross_weight,p.net_weight,p.is_active,
            COALESCE(c.category_name,'Uncategorised') AS category_name,
            COALESCE(m.metal_name,'-') AS metal_name,
            COALESCE(u.unit_name,'') AS unit_name,
            COALESCE(SUM(CASE WHEN sm.movement_date < ? THEN sm.quantity_in-sm.quantity_out ELSE 0 END),0) AS opening_qty,
            COALESCE(SUM(CASE WHEN sm.movement_date < ? THEN sm.weight_in-sm.weight_out ELSE 0 END),0) AS opening_weight,
            COALESCE(SUM(CASE WHEN sm.movement_date >= ? AND sm.movement_date < ? THEN sm.quantity_in ELSE 0 END),0) AS period_qty_in,
            COALESCE(SUM(CASE WHEN sm.movement_date >= ? AND sm.movement_date < ? THEN sm.quantity_out ELSE 0 END),0) AS period_qty_out,
            COALESCE(SUM(CASE WHEN sm.movement_date >= ? AND sm.movement_date < ? THEN sm.weight_in ELSE 0 END),0) AS period_weight_in,
            COALESCE(SUM(CASE WHEN sm.movement_date >= ? AND sm.movement_date < ? THEN sm.weight_out ELSE 0 END),0) AS period_weight_out,
            COALESCE(SUM(CASE WHEN sm.movement_date < ? THEN sm.quantity_in-sm.quantity_out ELSE 0 END),0) AS closing_qty,
            COALESCE(SUM(CASE WHEN sm.movement_date < ? THEN sm.weight_in-sm.weight_out ELSE 0 END),0) AS closing_weight
        FROM products p
        LEFT JOIN product_categories c ON c.id=p.category_id AND c.business_id=p.business_id
        LEFT JOIN metals m ON m.id=p.metal_id AND m.business_id=p.business_id
        LEFT JOIN units u ON u.id=p.unit_id AND u.business_id=p.business_id
        LEFT JOIN stock_movements sm ON sm.product_id=p.id AND sm.business_id=p.business_id AND sm.branch_id=?
        WHERE " . implode(' AND ', $where) . "
        GROUP BY p.id,p.product_code,p.barcode,p.product_name,p.product_type,p.purity,p.gross_weight,p.net_weight,p.is_active,c.category_name,m.metal_name,u.unit_name
        HAVING ABS(opening_qty)>0.0001 OR ABS(period_qty_in)>0.0001 OR ABS(period_qty_out)>0.0001 OR ABS(closing_qty)>0.0001
        ORDER BY c.category_name,p.product_name,p.product_code";

$queryParams = [
    $fromStart,$fromStart,
    $fromStart,$toExclusive,
    $fromStart,$toExclusive,
    $fromStart,$toExclusive,
    $fromStart,$toExclusive,
    $toExclusive,$toExclusive,
    $branchId
];
$queryTypes = 'ssssssssssssi' . $types;
foreach ($params as $value) {
    $queryParams[] = $value;
}

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die('Unable to prepare stock report: ' . e($conn->error));
}

$bind = [$queryTypes];
foreach ($queryParams as $k => $value) {
    $bind[] = &$queryParams[$k];
}
call_user_func_array([$stmt, 'bind_param'], $bind);
$stmt->execute();
$result = $stmt->get_result();
$rows = [];
while ($row = $result->fetch_assoc()) {
    $rows[] = $row;
}
$stmt->close();

$summary = [
    'products' => count($rows),
    'opening_qty' => 0.0,
    'opening_weight' => 0.0,
    'qty_in' => 0.0,
    'weight_in' => 0.0,
    'qty_out' => 0.0,
    'weight_out' => 0.0,
    'closing_qty' => 0.0,
    'closing_weight' => 0.0,
];
foreach ($rows as $row) {
    $summary['opening_qty'] += (float)$row['opening_qty'];
    $summary['opening_weight'] += (float)$row['opening_weight'];
    $summary['qty_in'] += (float)$row['period_qty_in'];
    $summary['weight_in'] += (float)$row['period_weight_in'];
    $summary['qty_out'] += (float)$row['period_qty_out'];
    $summary['weight_out'] += (float)$row['period_weight_out'];
    $summary['closing_qty'] += (float)$row['closing_qty'];
    $summary['closing_weight'] += (float)$row['closing_weight'];
}

$businessName = (string)($_SESSION['business_name'] ?? 'Jewellery ERP');
$branchName = (string)($_SESSION['branch_name'] ?? 'Branch');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo e($businessName); ?> - Opening & Closing Stock</title>
    <?php include('includes/links.php'); ?>
    <style>
        :root{--stock-primary:#d89416;--stock-dark:#b86a0b;--stock-soft:#fff6e5;--stock-line:#e7e7e7;--stock-muted:#7d8794;--stock-card:#fff;--stock-bg:#f4f3f0;--stock-text:#171717}
        body{background:var(--stock-bg);color:var(--stock-text)}
        .stock-report-page{font-size:12px}
        .stock-card{background:var(--stock-card);border:1px solid var(--stock-line);border-radius:14px}
        .report-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:15px 16px;margin-bottom:12px}
        .report-title{font-size:18px;font-weight:800;margin:0}.report-sub{color:var(--stock-muted);font-size:10px;margin-top:3px}
        .filter-card{padding:12px;margin-bottom:12px}.filter-grid{display:grid;grid-template-columns:1.4fr 135px 135px 180px 180px auto auto;gap:8px;align-items:end}
        .filter-field label{display:block;font-size:9px;font-weight:700;color:var(--stock-muted);text-transform:uppercase;margin-bottom:5px}.filter-field .form-control,.filter-field .form-select{height:38px;font-size:11px;border-radius:9px;border-color:var(--stock-line)}
        .btn-stock{height:38px;border:0;border-radius:9px;padding:0 15px;background:linear-gradient(135deg,var(--stock-primary),var(--stock-dark));color:#fff;font-size:11px;font-weight:700}.btn-reset{height:38px;border:1px solid var(--stock-line);border-radius:9px;padding:0 14px;background:#fff;font-size:11px;font-weight:700;color:var(--stock-text)}
        .summary-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin-bottom:12px}.summary-card{padding:13px 14px;min-height:82px}.summary-label{font-size:9px;color:var(--stock-muted);text-transform:uppercase;font-weight:700}.summary-main{font-size:20px;font-weight:800;margin-top:5px}.summary-sub{font-size:10px;color:var(--stock-muted);margin-top:3px}
        .table-card{overflow:hidden}.table-wrap{overflow:auto}.stock-table{width:100%;margin:0;min-width:1350px;font-size:10px}.stock-table th{background:#f8f8f8;color:var(--stock-muted);font-size:9px;text-transform:uppercase;letter-spacing:.03em;white-space:nowrap;padding:10px 8px;border-color:var(--stock-line);text-align:center}.stock-table td{padding:9px 8px;border-color:var(--stock-line);vertical-align:middle}.stock-table .product-cell{min-width:190px}.product-name{font-size:11px;font-weight:800}.product-code{font-size:9px;color:var(--stock-muted);margin-top:2px}.num{text-align:right;font-variant-numeric:tabular-nums}.opening-col{background:#fffaf0!important}.in-col{background:#f2fbf5!important}.out-col{background:#fff5f5!important}.closing-col{background:#f3f7ff!important;font-weight:700}.total-row td{font-weight:800;background:#fff4d8!important}.empty{padding:55px 20px;text-align:center;color:var(--stock-muted)}
        .status-pill{display:inline-flex;border-radius:999px;padding:4px 8px;font-size:9px;font-weight:700}.active{background:#eaf8f0;color:#168449}.inactive{background:#fdecec;color:#bd2d2d}
        @media(max-width:1200px){.filter-grid{grid-template-columns:1fr 1fr 1fr 1fr}.summary-grid{grid-template-columns:repeat(3,1fr)}}
        @media(max-width:767px){.filter-grid{grid-template-columns:1fr}.summary-grid{grid-template-columns:1fr 1fr}.report-head{align-items:flex-start;flex-direction:column}}
        @media print{.sidebar,.app-main>nav,.filter-card,.no-print,footer{display:none!important}.app-main{margin:0!important}.content-wrap{padding:0!important}.stock-card{border-radius:0}.stock-report-page{font-size:9px}.table-wrap{overflow:visible}.stock-table{min-width:0;font-size:7px}.stock-table th{font-size:7px;padding:5px 3px}.stock-table td{padding:4px 3px}.summary-grid{grid-template-columns:repeat(5,1fr)}}
    </style>
</head>
<body>
<?php include('includes/sidebar.php'); ?>
<main class="app-main">
    <?php include('includes/nav.php'); ?>
    <div class="content-wrap stock-report-page">
        <section class="stock-card report-head">
            <div>
                <h1 class="report-title">Opening & Closing Stock</h1>
                <div class="report-sub"><?php echo e($businessName); ?> · <?php echo e($branchName); ?> · <?php echo e(date('d-m-Y', strtotime($fromDate))); ?> to <?php echo e(date('d-m-Y', strtotime($toDate))); ?></div>
            </div>
            <button type="button" class="btn-reset no-print" onclick="window.print()"><i class="fa-solid fa-print me-2"></i>Print</button>
        </section>

        <form method="get" class="stock-card filter-card no-print">
            <div class="filter-grid">
                <div class="filter-field"><label>Search Product</label><input type="search" class="form-control" name="search" value="<?php echo e($search); ?>" placeholder="Name, code or barcode"></div>
                <div class="filter-field"><label>From Date</label><input type="date" class="form-control" name="from_date" value="<?php echo e($fromDate); ?>"></div>
                <div class="filter-field"><label>To Date</label><input type="date" class="form-control" name="to_date" value="<?php echo e($toDate); ?>"></div>
                <div class="filter-field"><label>Category</label><select class="form-select" name="category_id"><option value="0">All Categories</option><?php foreach($categories as $category): ?><option value="<?php echo (int)$category['id']; ?>" <?php echo $categoryId===(int)$category['id']?'selected':''; ?>><?php echo e($category['category_name']); ?></option><?php endforeach; ?></select></div>
                <div class="filter-field"><label>Metal</label><select class="form-select" name="metal_id"><option value="0">All Metals</option><?php foreach($metals as $metal): ?><option value="<?php echo (int)$metal['id']; ?>" <?php echo $metalId===(int)$metal['id']?'selected':''; ?>><?php echo e($metal['metal_name']); ?></option><?php endforeach; ?></select></div>
                <button class="btn-stock" type="submit"><i class="fa-solid fa-filter me-2"></i>Apply</button>
                <a class="btn-reset d-inline-flex align-items-center justify-content-center text-decoration-none" href="opening-closing-stock.php"><i class="fa-solid fa-rotate-left me-2"></i>Reset</a>
            </div>
        </form>

        <div class="summary-grid">
            <div class="stock-card summary-card"><div class="summary-label">Products</div><div class="summary-main"><?php echo number_format($summary['products']); ?></div><div class="summary-sub">Products with stock activity</div></div>
            <div class="stock-card summary-card"><div class="summary-label">Opening Stock</div><div class="summary-main"><?php echo number_format($summary['opening_qty'],3); ?></div><div class="summary-sub"><?php echo number_format($summary['opening_weight'],3); ?> g</div></div>
            <div class="stock-card summary-card"><div class="summary-label">Stock In</div><div class="summary-main"><?php echo number_format($summary['qty_in'],3); ?></div><div class="summary-sub"><?php echo number_format($summary['weight_in'],3); ?> g</div></div>
            <div class="stock-card summary-card"><div class="summary-label">Stock Out</div><div class="summary-main"><?php echo number_format($summary['qty_out'],3); ?></div><div class="summary-sub"><?php echo number_format($summary['weight_out'],3); ?> g</div></div>
            <div class="stock-card summary-card"><div class="summary-label">Closing Stock</div><div class="summary-main"><?php echo number_format($summary['closing_qty'],3); ?></div><div class="summary-sub"><?php echo number_format($summary['closing_weight'],3); ?> g</div></div>
        </div>

        <section class="stock-card table-card">
            <?php if (!$rows): ?>
                <div class="empty"><i class="fa-regular fa-folder-open fa-2x mb-2"></i><div>No stock movement found for the selected period.</div></div>
            <?php else: ?>
            <div class="table-wrap">
                <table class="table stock-table align-middle">
                    <thead>
                    <tr>
                        <th rowspan="2">S.No</th><th rowspan="2">Product</th><th rowspan="2">Category</th><th rowspan="2">Metal</th><th rowspan="2">Status</th>
                        <th colspan="2" class="opening-col">Opening Stock</th><th colspan="2" class="in-col">Stock In</th><th colspan="2" class="out-col">Stock Out</th><th colspan="2" class="closing-col">Closing Stock</th>
                    </tr>
                    <tr>
                        <th class="opening-col">Qty</th><th class="opening-col">Weight</th><th class="in-col">Qty</th><th class="in-col">Weight</th><th class="out-col">Qty</th><th class="out-col">Weight</th><th class="closing-col">Qty</th><th class="closing-col">Weight</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach($rows as $i=>$row): ?>
                    <tr>
                        <td class="text-center"><?php echo $i+1; ?></td>
                        <td class="product-cell"><div class="product-name"><?php echo e($row['product_name']); ?></div><div class="product-code"><?php echo e($row['product_code']); ?><?php echo $row['barcode'] ? ' · '.e($row['barcode']) : ''; ?></div></td>
                        <td><?php echo e($row['category_name']); ?></td><td><?php echo e($row['metal_name']); ?></td>
                        <td class="text-center"><span class="status-pill <?php echo (int)$row['is_active']===1?'active':'inactive'; ?>"><?php echo (int)$row['is_active']===1?'Active':'Inactive'; ?></span></td>
                        <td class="num opening-col"><?php echo number_format((float)$row['opening_qty'],3); ?></td><td class="num opening-col"><?php echo number_format((float)$row['opening_weight'],3); ?> g</td>
                        <td class="num in-col"><?php echo number_format((float)$row['period_qty_in'],3); ?></td><td class="num in-col"><?php echo number_format((float)$row['period_weight_in'],3); ?> g</td>
                        <td class="num out-col"><?php echo number_format((float)$row['period_qty_out'],3); ?></td><td class="num out-col"><?php echo number_format((float)$row['period_weight_out'],3); ?> g</td>
                        <td class="num closing-col"><?php echo number_format((float)$row['closing_qty'],3); ?></td><td class="num closing-col"><?php echo number_format((float)$row['closing_weight'],3); ?> g</td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td colspan="5">TOTAL</td>
                        <td class="num"><?php echo number_format($summary['opening_qty'],3); ?></td><td class="num"><?php echo number_format($summary['opening_weight'],3); ?> g</td>
                        <td class="num"><?php echo number_format($summary['qty_in'],3); ?></td><td class="num"><?php echo number_format($summary['weight_in'],3); ?> g</td>
                        <td class="num"><?php echo number_format($summary['qty_out'],3); ?></td><td class="num"><?php echo number_format($summary['weight_out'],3); ?> g</td>
                        <td class="num"><?php echo number_format($summary['closing_qty'],3); ?></td><td class="num"><?php echo number_format($summary['closing_weight'],3); ?> g</td>
                    </tr>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </section>
        <?php include('includes/footer.php'); ?>
    </div>
</main>
<?php include('includes/script.php'); ?>
<script src="assets/js/script.js"></script>
</body>
</html>
