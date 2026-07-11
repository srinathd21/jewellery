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

$pageTitle = 'Stock Movements';

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
$userRow = $res ? $res->fetch_assoc() : null;
$stmt->close();

$roleName = strtolower(trim((string)($userRow['role_name'] ?? '')));
if (!in_array($roleName, ['admin', 'manager', 'stock'], true)) {
    die('Access denied.');
}

/* -------------------------------------------------------
   REQUIRED TABLES
------------------------------------------------------- */
if (!tableExists($conn, 'stock_movements')) {
    die('Required table `stock_movements` not found. Please import the SQL first.');
}

if (!tableExists($conn, 'products')) {
    die('Required table `products` not found. Please import the SQL first.');
}

/* -------------------------------------------------------
   COLUMN CHECKS
------------------------------------------------------- */
$smHasBusinessId   = hasColumn($conn, 'stock_movements', 'business_id');
$smHasMovementDate = hasColumn($conn, 'stock_movements', 'movement_date');
$smHasProductId    = hasColumn($conn, 'stock_movements', 'product_id');
$smHasMovementType = hasColumn($conn, 'stock_movements', 'movement_type');
$smHasRefTable     = hasColumn($conn, 'stock_movements', 'ref_table');
$smHasRefId        = hasColumn($conn, 'stock_movements', 'ref_id');
$smHasQtyIn        = hasColumn($conn, 'stock_movements', 'qty_in');
$smHasQtyOut       = hasColumn($conn, 'stock_movements', 'qty_out');
$smHasWeightIn     = hasColumn($conn, 'stock_movements', 'weight_in');
$smHasWeightOut    = hasColumn($conn, 'stock_movements', 'weight_out');
$smHasRemarks      = hasColumn($conn, 'stock_movements', 'remarks');
$smHasCreatedBy    = hasColumn($conn, 'stock_movements', 'created_by');
$smHasCreatedAt    = hasColumn($conn, 'stock_movements', 'created_at');

$prodHasBusinessId = hasColumn($conn, 'products', 'business_id');
$prodHasCode       = hasColumn($conn, 'products', 'product_code');
$prodHasBarcode    = hasColumn($conn, 'products', 'barcode');
$prodHasUnit       = hasColumn($conn, 'products', 'unit');

$userTableExists   = tableExists($conn, 'users');

/* -------------------------------------------------------
   FILTERS
------------------------------------------------------- */
$search        = trim((string)($_GET['search'] ?? ''));
$movementType  = trim((string)($_GET['movement_type'] ?? ''));
$dateFrom      = trim((string)($_GET['date_from'] ?? ''));
$dateTo        = trim((string)($_GET['date_to'] ?? ''));
$productId     = (int)($_GET['product_id'] ?? 0);

/* -------------------------------------------------------
   PRODUCT OPTIONS
------------------------------------------------------- */
$productSql = "SELECT id, product_name";
if ($prodHasCode) {
    $productSql .= ", product_code";
}
$productSql .= " FROM products WHERE 1=1";

$productParams = [];
$productTypes  = '';

if ($prodHasBusinessId) {
    $productSql .= " AND business_id = ?";
    $productParams[] = $businessId;
    $productTypes .= 'i';
}

if (hasColumn($conn, 'products', 'is_active')) {
    $productSql .= " AND is_active = 1";
}

$productSql .= " ORDER BY product_name ASC";

$productOptions = [];
$stmt = $conn->prepare($productSql);
if ($stmt) {
    if (!empty($productParams)) {
        $bindValues = [];
        $bindValues[] = $productTypes;
        for ($i = 0; $i < count($productParams); $i++) {
            $bindValues[] = &$productParams[$i];
        }
        call_user_func_array([$stmt, 'bind_param'], $bindValues);
    }

    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && $row = $res->fetch_assoc()) {
        $productOptions[] = $row;
    }
    $stmt->close();
}

/* -------------------------------------------------------
   SUMMARY COUNTS
------------------------------------------------------- */
$summaryWhere  = " WHERE 1=1 ";
$summaryParams = [];
$summaryTypes  = '';

if ($smHasBusinessId) {
    $summaryWhere .= " AND sm.business_id = ? ";
    $summaryParams[] = $businessId;
    $summaryTypes .= 'i';
}

$totalMovements = 0;
$totalQtyIn = 0;
$totalQtyOut = 0;
$totalWeightIn = 0;
$totalWeightOut = 0;

$summarySql = "
    SELECT
        COUNT(*) AS total_movements,
        SUM(" . ($smHasQtyIn ? "IFNULL(sm.qty_in, 0)" : "0") . ") AS total_qty_in,
        SUM(" . ($smHasQtyOut ? "IFNULL(sm.qty_out, 0)" : "0") . ") AS total_qty_out,
        SUM(" . ($smHasWeightIn ? "IFNULL(sm.weight_in, 0)" : "0") . ") AS total_weight_in,
        SUM(" . ($smHasWeightOut ? "IFNULL(sm.weight_out, 0)" : "0") . ") AS total_weight_out
    FROM stock_movements sm
    {$summaryWhere}
";

$stmt = $conn->prepare($summarySql);
if ($stmt) {
    if (!empty($summaryParams)) {
        $bindValues = [];
        $bindValues[] = $summaryTypes;
        for ($i = 0; $i < count($summaryParams); $i++) {
            $bindValues[] = &$summaryParams[$i];
        }
        call_user_func_array([$stmt, 'bind_param'], $bindValues);
    }

    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if ($row) {
        $totalMovements = (int)($row['total_movements'] ?? 0);
        $totalQtyIn = (float)($row['total_qty_in'] ?? 0);
        $totalQtyOut = (float)($row['total_qty_out'] ?? 0);
        $totalWeightIn = (float)($row['total_weight_in'] ?? 0);
        $totalWeightOut = (float)($row['total_weight_out'] ?? 0);
    }
}

/* -------------------------------------------------------
   MOVEMENT TYPES
------------------------------------------------------- */
$movementTypes = [
    'Opening',
    'Purchase',
    'Sale',
    'Sale Return',
    'Purchase Return',
    'Adjustment',
    'Old Silver Inward',
    'Damage',
    'Manual'
];

/* -------------------------------------------------------
   LIST QUERY
------------------------------------------------------- */
$where  = " WHERE 1=1 ";
$params = [];
$types  = '';

if ($smHasBusinessId) {
    $where .= " AND sm.business_id = ? ";
    $params[] = $businessId;
    $types .= 'i';
}

if ($search !== '') {
    $where .= " AND (p.product_name LIKE ?";
    $like = '%' . $search . '%';
    $params[] = $like;
    $types .= 's';

    if ($prodHasCode) {
        $where .= " OR p.product_code LIKE ?";
        $params[] = $like;
        $types .= 's';
    }

    if ($prodHasBarcode) {
        $where .= " OR p.barcode LIKE ?";
        $params[] = $like;
        $types .= 's';
    }

    if ($smHasRemarks) {
        $where .= " OR sm.remarks LIKE ?";
        $params[] = $like;
        $types .= 's';
    }

    if ($smHasRefTable) {
        $where .= " OR sm.ref_table LIKE ?";
        $params[] = $like;
        $types .= 's';
    }

    $where .= ")";
}

if ($movementType !== '' && $smHasMovementType) {
    $where .= " AND sm.movement_type = ? ";
    $params[] = $movementType;
    $types .= 's';
}

if ($productId > 0 && $smHasProductId) {
    $where .= " AND sm.product_id = ? ";
    $params[] = $productId;
    $types .= 'i';
}

if ($dateFrom !== '' && $smHasMovementDate) {
    $where .= " AND DATE(sm.movement_date) >= ? ";
    $params[] = $dateFrom;
    $types .= 's';
}

if ($dateTo !== '' && $smHasMovementDate) {
    $where .= " AND DATE(sm.movement_date) <= ? ";
    $params[] = $dateTo;
    $types .= 's';
}

$sql = "
    SELECT
        sm.id,
        " . ($smHasMovementDate ? "sm.movement_date," : "sm.created_at AS movement_date,") . "
        " . ($smHasMovementType ? "sm.movement_type," : "'' AS movement_type,") . "
        " . ($smHasRefTable ? "sm.ref_table," : "'' AS ref_table,") . "
        " . ($smHasRefId ? "sm.ref_id," : "NULL AS ref_id,") . "
        " . ($smHasQtyIn ? "sm.qty_in," : "0 AS qty_in,") . "
        " . ($smHasQtyOut ? "sm.qty_out," : "0 AS qty_out,") . "
        " . ($smHasWeightIn ? "sm.weight_in," : "0 AS weight_in,") . "
        " . ($smHasWeightOut ? "sm.weight_out," : "0 AS weight_out,") . "
        " . ($smHasRemarks ? "sm.remarks," : "'' AS remarks,") . "
        " . ($smHasCreatedAt ? "sm.created_at," : "NULL AS created_at,") . "
        p.product_name,
        " . ($prodHasCode ? "p.product_code," : "'' AS product_code,") . "
        " . ($prodHasBarcode ? "p.barcode," : "'' AS barcode,") . "
        " . ($prodHasUnit ? "p.unit," : "'pcs' AS unit,") . "
        " . ($userTableExists && $smHasCreatedBy ? "u.full_name AS created_by_name" : "'' AS created_by_name") . "
    FROM stock_movements sm
    LEFT JOIN products p ON p.id = sm.product_id
    " . ($userTableExists && $smHasCreatedBy ? "LEFT JOIN users u ON u.id = sm.created_by" : "") . "
    {$where}
    ORDER BY " . ($smHasMovementDate ? "sm.movement_date" : "sm.id") . " DESC, sm.id DESC
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die('Failed to prepare stock movements query.');
}

if (!empty($params)) {
    $bindValues = [];
    $bindValues[] = $types;
    for ($i = 0; $i < count($params); $i++) {
        $bindValues[] = &$params[$i];
    }
    call_user_func_array([$stmt, 'bind_param'], $bindValues);
}

$stmt->execute();
$res = $stmt->get_result();
$movements = [];
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $movements[] = $row;
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

                <div class="row mb-3">
                    <div class="col-12 d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div>
                            <h4 class="mb-1">Stock Movements</h4>
                            <p class="text-muted mb-0">View all product stock inward and outward entries</p>
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                            <button type="button" class="btn btn-info" onclick="window.print();">Print</button>
                            <a href="stock-overview.php" class="btn btn-secondary">Stock Overview</a>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-3 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-primary mt-2"><?php echo $totalMovements; ?></h3>
                                <p class="text-muted mb-0">Total Movements</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-success mt-2"><?php echo number_format($totalQtyIn, 3); ?></h3>
                                <p class="text-muted mb-0">Total Qty In</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-danger mt-2"><?php echo number_format($totalQtyOut, 3); ?></h3>
                                <p class="text-muted mb-0">Total Qty Out</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3 col-xl-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-dark mt-2"><?php echo number_format($totalWeightIn - $totalWeightOut, 3); ?></h3>
                                <p class="text-muted mb-0">Net Weight Change</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <form method="get" class="row g-2 align-items-end">
                            <div class="col-md-3">
                                <label class="form-label">Search</label>
                                <input
                                    type="text"
                                    name="search"
                                    class="form-control"
                                    placeholder="Product, code, remarks..."
                                    value="<?php echo h($search); ?>"
                                >
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Product</label>
                                <select name="product_id" class="form-select">
                                    <option value="0">All Products</option>
                                    <?php foreach ($productOptions as $product): ?>
                                        <option value="<?php echo (int)$product['id']; ?>" <?php echo $productId === (int)$product['id'] ? 'selected' : ''; ?>>
                                            <?php echo h($product['product_name'] . (!empty($product['product_code']) ? ' - ' . $product['product_code'] : '')); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Movement Type</label>
                                <select name="movement_type" class="form-select">
                                    <option value="">All Types</option>
                                    <?php foreach ($movementTypes as $type): ?>
                                        <option value="<?php echo h($type); ?>" <?php echo $movementType === $type ? 'selected' : ''; ?>>
                                            <?php echo h($type); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Date From</label>
                                <input type="date" name="date_from" class="form-control" value="<?php echo h($dateFrom); ?>">
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Date To</label>
                                <input type="date" name="date_to" class="form-control" value="<?php echo h($dateTo); ?>">
                            </div>

                            <div class="col-md-1">
                                <button type="submit" class="btn btn-primary w-100">Go</button>
                            </div>

                            <div class="col-md-12">
                                <a href="stock-movements.php" class="btn btn-secondary">Reset</a>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Date & Time</th>
                                        <th>Product</th>
                                        <th>Type</th>
                                        <th>Reference</th>
                                        <th>Qty In</th>
                                        <th>Qty Out</th>
                                        <th>Weight In</th>
                                        <th>Weight Out</th>
                                        <th>Remarks</th>
                                        <th>Created By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($movements)): ?>
                                        <?php foreach ($movements as $index => $movement): ?>
                                            <?php
                                                $type = (string)($movement['movement_type'] ?? '');
                                                $badgeClass = 'bg-secondary';

                                                if (in_array($type, ['Opening', 'Purchase', 'Sale Return', 'Old Silver Inward', 'Manual'], true)) {
                                                    $badgeClass = 'bg-success';
                                                } elseif (in_array($type, ['Sale', 'Purchase Return', 'Damage'], true)) {
                                                    $badgeClass = 'bg-danger';
                                                } elseif ($type === 'Adjustment') {
                                                    $badgeClass = 'bg-warning text-dark';
                                                }

                                                $referenceText = '-';
                                                if (!empty($movement['ref_table']) || !empty($movement['ref_id'])) {
                                                    $referenceText = trim((string)($movement['ref_table'] ?? ''));
                                                    if (!empty($movement['ref_id'])) {
                                                        $referenceText .= ' #' . (int)$movement['ref_id'];
                                                    }
                                                }
                                            ?>
                                            <tr>
                                                <td><?php echo $index + 1; ?></td>

                                                <td>
                                                    <?php
                                                    if (!empty($movement['movement_date'])) {
                                                        echo date('d-m-Y h:i A', strtotime($movement['movement_date']));
                                                    } else {
                                                        echo '-';
                                                    }
                                                    ?>
                                                </td>

                                                <td>
                                                    <strong><?php echo h($movement['product_name'] ?? 'Unknown Product'); ?></strong>
                                                    <?php if (!empty($movement['product_code'])): ?>
                                                        <br><small class="text-muted"><?php echo h($movement['product_code']); ?></small>
                                                    <?php endif; ?>
                                                    <?php if (!empty($movement['barcode'])): ?>
                                                        <br><small class="text-muted"><?php echo h($movement['barcode']); ?></small>
                                                    <?php endif; ?>
                                                </td>

                                                <td>
                                                    <span class="badge <?php echo $badgeClass; ?>">
                                                        <?php echo h($type !== '' ? $type : 'N/A'); ?>
                                                    </span>
                                                </td>

                                                <td><?php echo h($referenceText); ?></td>

                                                <td class="text-success">
                                                    <?php echo number_format((float)($movement['qty_in'] ?? 0), 3); ?>
                                                    <?php if (!empty($movement['unit'])): ?>
                                                        <small><?php echo h($movement['unit']); ?></small>
                                                    <?php endif; ?>
                                                </td>

                                                <td class="text-danger">
                                                    <?php echo number_format((float)($movement['qty_out'] ?? 0), 3); ?>
                                                    <?php if (!empty($movement['unit'])): ?>
                                                        <small><?php echo h($movement['unit']); ?></small>
                                                    <?php endif; ?>
                                                </td>

                                                <td class="text-success"><?php echo number_format((float)($movement['weight_in'] ?? 0), 3); ?></td>
                                                <td class="text-danger"><?php echo number_format((float)($movement['weight_out'] ?? 0), 3); ?></td>

                                                <td><?php echo h($movement['remarks'] ?? ''); ?></td>
                                                <td><?php echo h($movement['created_by_name'] ?? '-'); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="11" class="text-center text-muted">No stock movements found.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if (empty($movements)): ?>
                            <div class="alert alert-warning mt-3 mb-0">
                                No entries are available in `stock_movements` table yet.
                            </div>
                        <?php endif; ?>
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