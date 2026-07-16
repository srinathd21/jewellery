<?php
if (session_status() === PHP_SESSION_NONE)
    session_start();
date_default_timezone_set((string) ($_SESSION['timezone'] ?? 'Asia/Kolkata'));
foreach ([__DIR__ . '/config/config.php', __DIR__ . '/config.php', __DIR__ . '/includes/config.php', __DIR__ . '/super-admin/includes/config.php'] as $f) {
    if (is_file($f)) {
        require_once $f;
        break;
    }
}
if (!isset($conn) || !($conn instanceof mysqli))
    die('Database configuration is not available.');
$conn->set_charset('utf8mb4');
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

function h($v): string
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
}
function bindDynamic(mysqli_stmt $stmt, string $types, array &$params): void
{
    if ($types === '')
        return;
    $bind = [$types];
    foreach ($params as $k => $v)
        $bind[] =& $params[$k];
    call_user_func_array([$stmt, 'bind_param'], $bind);
}
function pageUrl(array $replace = []): string
{
    $q = array_merge($_GET, $replace);
    foreach ($q as $k => $v)
        if ($v === '' || $v === null)
            unset($q[$k]);
    return 'stock-overview1.php' . ($q ? '?' . http_build_query($q) : '');
}
function stockPermission(string $action): bool
{
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin')
        return true;
    $map = ['open' => 'can_open', 'view' => 'can_view'];
    $field = $map[$action] ?? '';
    foreach (['perm.inventory.stock', 'perm.inventory'] as $code) {
        if (isset($_SESSION['permissions'][$code][$field]))
            return (int) $_SESSION['permissions'][$code][$field] === 1;
    }
    return true;
}
if (!stockPermission('open')) {
    http_response_code(403);
    die('Access denied.');
}

$businessId = (int) ($_SESSION['business_id'] ?? 0);
$branchId = (int) ($_GET['branch_id'] ?? ($_SESSION['branch_id'] ?? $_SESSION['default_branch_id'] ?? 0));
if ($businessId <= 0 || $branchId <= 0)
    die('Business or branch session not found.');

$search = trim((string) ($_GET['search'] ?? ''));
$categoryId = max(0, (int) ($_GET['category_id'] ?? 0));
$stockStatus = (string) ($_GET['stock_status'] ?? 'all');
$productStatus = (string) ($_GET['status'] ?? 'all');
$perPage = (int) ($_GET['per_page'] ?? 10);
if (!in_array($perPage, [10, 25, 50, 100], true))
    $perPage = 10;
$page = max(1, (int) ($_GET['page'] ?? 1));

$categories = [];
$stmt = $conn->prepare('SELECT id,category_name FROM product_categories WHERE business_id=? AND is_active=1 ORDER BY category_name');
if ($stmt) {
    $stmt->bind_param('i', $businessId);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($x = $r->fetch_assoc())
        $categories[] = $x;
    $stmt->close();
}
$branches = [];
$stmt = $conn->prepare('SELECT id,branch_name FROM branches WHERE business_id=? AND is_active=1 ORDER BY is_default DESC,branch_name');
if ($stmt) {
    $stmt->bind_param('i', $businessId);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($x = $r->fetch_assoc())
        $branches[] = $x;
    $stmt->close();
}

$where = ' WHERE p.business_id=?';
$types = 'i';
$params = [$businessId];
if ($search !== '') {
    $where .= ' AND (p.product_name LIKE ? OR p.product_code LIKE ? OR COALESCE(p.barcode,\'\') LIKE ? OR COALESCE(c.category_name,\'\') LIKE ? OR COALESCE(m.metal_name,\'\') LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like, $like);
    $types .= 'sssss';
}
if ($categoryId > 0) {
    $where .= ' AND p.category_id=?';
    $params[] = $categoryId;
    $types .= 'i';
}
if ($productStatus === 'active')
    $where .= ' AND p.is_active=1';
elseif ($productStatus === 'inactive')
    $where .= ' AND p.is_active=0';
if ($stockStatus === 'in_stock')
    $where .= ' AND COALESCE(ps.quantity,0)>0';
elseif ($stockStatus === 'out_of_stock')
    $where .= ' AND COALESCE(ps.quantity,0)<=0';
elseif ($stockStatus === 'low_stock')
    $where .= ' AND COALESCE(ps.quantity,0)>0 AND p.minimum_stock_qty>0 AND COALESCE(ps.quantity,0)<=p.minimum_stock_qty';

$join = ' FROM products p LEFT JOIN product_categories c ON c.id=p.category_id AND c.business_id=p.business_id LEFT JOIN metals m ON m.id=p.metal_id AND m.business_id=p.business_id LEFT JOIN units u ON u.id=p.unit_id AND u.business_id=p.business_id LEFT JOIN product_stock ps ON ps.product_id=p.id AND ps.business_id=p.business_id AND ps.branch_id=' . (int) $branchId;

$countSql = 'SELECT COUNT(DISTINCT p.id) total' . $join . $where;
$stmt = $conn->prepare($countSql);
if (!$stmt)
    die('Unable to prepare stock count query.');
bindDynamic($stmt, $types, $params);
$stmt->execute();
$total = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
$stmt->close();
$totalPages = max(1, (int) ceil($total / $perPage));
if ($page > $totalPages)
    $page = $totalPages;
$offset = ($page - 1) * $perPage;

$summarySql = 'SELECT COUNT(DISTINCT p.id) total_products,COALESCE(SUM(CASE WHEN COALESCE(ps.quantity,0)>0 THEN 1 ELSE 0 END),0) in_stock,COALESCE(SUM(CASE WHEN COALESCE(ps.quantity,0)>0 AND p.minimum_stock_qty>0 AND COALESCE(ps.quantity,0)<=p.minimum_stock_qty THEN 1 ELSE 0 END),0) low_stock,COALESCE(SUM(CASE WHEN COALESCE(ps.quantity,0)<=0 THEN 1 ELSE 0 END),0) out_stock,COALESCE(SUM(ps.quantity),0) total_qty,COALESCE(SUM(ps.net_weight),0) total_weight' . $join . ' WHERE p.business_id=?';
$stmt = $conn->prepare($summarySql);
$stmt->bind_param('i', $businessId);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();

$listSql = 'SELECT p.id,p.product_code,p.product_name,p.barcode,p.purity,p.minimum_stock_qty,p.sale_rate,p.image_path,p.is_active,c.category_name,m.metal_name,COALESCE(u.unit_name,\'Piece\') unit_name,COALESCE(ps.quantity,0) closing_qty,COALESCE(ps.gross_weight,0) gross_weight,COALESCE(ps.net_weight,0) net_weight,COALESCE(ps.average_cost,0) average_cost,COALESCE(ps.stock_value,0) stock_value,ps.updated_at stock_updated_at,COALESCE(mv.opening_qty,0) opening_qty,COALESCE(mv.qty_in,0) qty_in,COALESCE(mv.qty_out,0) qty_out,mv.last_movement_date' . $join . ' LEFT JOIN (SELECT product_id,SUM(CASE WHEN movement_type=\'Opening\' THEN quantity_in-quantity_out ELSE 0 END) opening_qty,SUM(quantity_in) qty_in,SUM(quantity_out) qty_out,MAX(movement_date) last_movement_date FROM stock_movements WHERE business_id=? AND branch_id=? GROUP BY product_id) mv ON mv.product_id=p.id' . $where . ' ORDER BY p.id DESC LIMIT ? OFFSET ?';
$listParams = [$businessId, $branchId];
$listTypes = 'ii';
foreach ($params as $v)
    $listParams[] = $v;
$listTypes .= $types;
$listParams[] = $perPage;
$listParams[] = $offset;
$listTypes .= 'ii';
$stmt = $conn->prepare($listSql);
if (!$stmt)
    die('Unable to prepare stock list query: ' . $conn->error);
bindDynamic($stmt, $listTypes, $listParams);
$stmt->execute();
$r = $stmt->get_result();
$products = [];
while ($x = $r->fetch_assoc())
    $products[] = $x;
$stmt->close();

$theme = ['primary_color' => '#d89416', 'primary_dark_color' => '#b86a0b', 'primary_soft_color' => '#fff6e5', 'page_background' => '#f4f3f0', 'card_background' => '#fff', 'text_color' => '#171717', 'muted_text_color' => '#7d8794', 'border_color' => '#e8e8e8', 'font_family' => 'Inter', 'heading_font_family' => 'Playfair Display', 'border_radius_px' => 12];
$stmt = $conn->prepare('SELECT * FROM business_theme_settings WHERE business_id=? LIMIT 1');
if ($stmt) {
    $stmt->bind_param('i', $businessId);
    $stmt->execute();
    $x = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    foreach ($theme as $k => $v)
        if (isset($x[$k]) && $x[$k] !== '')
            $theme[$k] = $x[$k];
}
$businessName = (string) ($_SESSION['business_name'] ?? 'Jewellery ERP');
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= h($businessName) ?> - Stock Overview</title><?php include('includes/links.php'); ?>
    <style>
        :root {
            --primary: <?= h($theme['primary_color']) ?>;
            --primary-dark: <?= h($theme['primary_dark_color']) ?>;
            --primary-soft: <?= h($theme['primary_soft_color']) ?>;
            --page-bg: <?= h($theme['page_background']) ?>;
            --card-bg: <?= h($theme['card_background']) ?>;
            --text: <?= h($theme['text_color']) ?>;
            --muted: <?= h($theme['muted_text_color']) ?>;
            --line: <?= h($theme['border_color']) ?>;
            --radius: <?= (int) $theme['border_radius_px'] ?>px
        }

        body {
            background: var(--page-bg);
            color: var(--text);
            font-family: <?= json_encode($theme['font_family']) ?>, sans-serif
        }

        .page-title {
            font: 700 21px
                <?= json_encode($theme['heading_font_family']) ?>
                , serif
        }

        .stat-grid {
            display: grid;
            grid-template-columns: repeat(6, minmax(0, 1fr));
            gap: 9px;
            margin-bottom: 10px
        }

        .stat-card,
        .filter-card,
        .table-card {
            background: var(--card-bg);
            border: 1px solid var(--line);
            border-radius: var(--radius)
        }

        .stat-card {
            padding: 12px;
            display: flex;
            align-items: center;
            gap: 10px;
            min-height: 78px
        }

        .stat-icon {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            background: var(--primary-soft);
            color: var(--primary-dark);
            display: grid;
            place-items: center
        }

        .stat-label {
            font-size: 9px;
            color: var(--muted)
        }

        .stat-value {
            font-size: 18px;
            font-weight: 800
        }

        .filter-card {
            padding: 12px;
            margin-bottom: 10px
        }

        .filter-grid {
            display: grid;
            grid-template-columns: minmax(240px, 1.8fr) repeat(5, minmax(120px, 1fr));
            gap: 8px;
            align-items: end
        }

        .field-label {
            font-size: 9px;
            font-weight: 700;
            color: var(--muted);
            margin-bottom: 5px
        }

        .form-control,
        .form-select {
            font-size: 10px;
            min-height: 36px;
            border-radius: 9px;
            border-color: var(--line)
        }

        .btn-theme {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: #fff;
            border: 0;
            border-radius: 9px;
            font-size: 10px;
            font-weight: 700;
            padding: 9px 13px
        }

        .btn-soft {
            border: 1px solid var(--line);
            background: var(--card-bg);
            color: var(--text);
            border-radius: 9px;
            font-size: 10px;
            font-weight: 700;
            padding: 8px 12px
        }

        .table-card {
            overflow: hidden
        }

        .stock-table {
            font-size: 10px;
            margin: 0
        }

        .stock-table th {
            font-size: 9px;
            text-transform: uppercase;
            color: var(--muted);
            white-space: nowrap;
            background: color-mix(in srgb, var(--muted) 6%, transparent)
        }

        .stock-table td {
            vertical-align: middle;
            white-space: nowrap
        }

        .product-cell {
            min-width: 210px;
            white-space: normal !important
        }

        .product-wrap {
            display: flex;
            align-items: center;
            gap: 9px
        }

        .product-img,
        .product-icon {
            width: 38px;
            height: 38px;
            border-radius: 9px
        }

        .product-img {
            object-fit: cover;
            border: 1px solid var(--line)
        }

        .product-icon {
            display: grid;
            place-items: center;
            background: var(--primary-soft);
            color: var(--primary-dark)
        }

        .product-name {
            font-weight: 800
        }

        .small-muted {
            font-size: 8px;
            color: var(--muted)
        }

        .badge-stock {
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 8px;
            font-weight: 800
        }

        .in-stock {
            background: #eaf8f0;
            color: #168449
        }

        .low-stock {
            background: #fff4db;
            color: #a76500
        }

        .out-stock {
            background: #fdecec;
            color: #bd2d2d
        }

        .number-in {
            color: #168449;
            font-weight: 800
        }

        .number-out {
            color: #bd2d2d;
            font-weight: 800
        }

        .number-main {
            font-weight: 800
        }

        .action-btn {
            width: 28px;
            height: 28px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: var(--card-bg);
            display: inline-grid;
            place-items: center;
            color: var(--text);
            text-decoration: none
        }

        .pagination-wrap {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 12px;
            border-top: 1px solid var(--line);
            font-size: 9px;
            color: var(--muted)
        }

        .pagination {
            margin: 0
        }

        .page-link {
            font-size: 9px;
            color: var(--text);
            border-color: var(--line);
            background: var(--card-bg)
        }

        .page-item.active .page-link {
            background: var(--primary);
            border-color: var(--primary)
        }

        body.dark-mode,
        body[data-theme=dark] {
            --page-bg: #0f151b;
            --card-bg: #182129;
            --text: #f3f6f8;
            --muted: #9aa7b3;
            --line: #2c3944
        }

        @media(max-width:1200px) {
            .stat-grid {
                grid-template-columns: repeat(3, 1fr)
            }

            .filter-grid {
                grid-template-columns: repeat(3, 1fr)
            }
        }

        @media(max-width:767px) {
            .stat-grid {
                grid-template-columns: repeat(2, 1fr)
            }

            .filter-grid {
                grid-template-columns: 1fr
            }

            .content-wrap {
                padding-left: 10px;
                padding-right: 10px
            }

            .pagination-wrap {
                align-items: flex-start;
                gap: 8px;
                flex-direction: column
            }
        }
    </style>
</head>

<body><?php include('includes/sidebar.php'); ?>
    <main class="app-main"><?php include('includes/nav.php'); ?>
        <div class="content-wrap">
            <div class="stat-grid">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fa-solid fa-boxes-stacked"></i></div>
                    <div>
                        <div class="stat-label">Total Products</div>
                        <div class="stat-value"><?= (int) ($stats['total_products'] ?? 0) ?></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fa-solid fa-circle-check"></i></div>
                    <div>
                        <div class="stat-label">In Stock</div>
                        <div class="stat-value"><?= (int) ($stats['in_stock'] ?? 0) ?></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
                    <div>
                        <div class="stat-label">Low Stock</div>
                        <div class="stat-value"><?= (int) ($stats['low_stock'] ?? 0) ?></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fa-solid fa-circle-xmark"></i></div>
                    <div>
                        <div class="stat-label">Out of Stock</div>
                        <div class="stat-value"><?= (int) ($stats['out_stock'] ?? 0) ?></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fa-solid fa-cubes"></i></div>
                    <div>
                        <div class="stat-label">Total Stock Qty</div>
                        <div class="stat-value"><?= number_format((float) ($stats['total_qty'] ?? 0), 3) ?></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fa-solid fa-weight-hanging"></i></div>
                    <div>
                        <div class="stat-label">Total Net Weight</div>
                        <div class="stat-value"><?= number_format((float) ($stats['total_weight'] ?? 0), 3) ?></div>
                    </div>
                </div>
            </div>
            <form method="get" class="filter-card">
                <div class="filter-grid">
                    <div><label class="field-label">Search</label><input class="form-control" name="search"
                            value="<?= h($search) ?>" placeholder="Product, code, barcode, category or metal"></div>
                    <div><label class="field-label">Branch</label><select class="form-select"
                            name="branch_id"><?php foreach ($branches as $b): ?>
                                <option value="<?= (int) $b['id'] ?>" <?= $branchId === (int) $b['id'] ? 'selected' : '' ?>>
                                    <?= h($b['branch_name']) ?></option><?php endforeach ?>
                        </select></div>
                    <div><label class="field-label">Category</label><select class="form-select" name="category_id">
                            <option value="0">All Categories</option><?php foreach ($categories as $c): ?>
                                <option value="<?= (int) $c['id'] ?>" <?= $categoryId === (int) $c['id'] ? 'selected' : '' ?>>
                                    <?= h($c['category_name']) ?></option><?php endforeach ?>
                        </select></div>
                    <div><label class="field-label">Stock Status</label><select class="form-select"
                            name="stock_status"><?php foreach (['all' => 'All Stock', 'in_stock' => 'In Stock', 'low_stock' => 'Low Stock', 'out_of_stock' => 'Out of Stock'] as $k => $v): ?>
                                <option value="<?= h($k) ?>" <?= $stockStatus === $k ? 'selected' : '' ?>><?= h($v) ?></option>
                            <?php endforeach ?>
                        </select></div>
                    <div><label class="field-label">Product Status</label><select class="form-select"
                            name="status"><?php foreach (['all' => 'All Status', 'active' => 'Active', 'inactive' => 'Inactive'] as $k => $v): ?>
                                <option value="<?= h($k) ?>" <?= $productStatus === $k ? 'selected' : '' ?>><?= h($v) ?></option>
                            <?php endforeach ?>
                        </select></div>
                    <div><label class="field-label">Rows</label><select class="form-select"
                            name="per_page"><?php foreach ([10, 25, 50, 100] as $n): ?>
                                <option <?= $perPage === $n ? 'selected' : '' ?>><?= $n ?></option><?php endforeach ?>
                        </select></div>
                </div>
                <div class="d-flex gap-2 justify-content-end mt-2"><button class="btn btn-theme"><i
                            class="fa-solid fa-filter me-2"></i>Apply</button><a class="btn btn-soft"
                        href="stock-overview1.php">Reset</a></div>
            </form>
            <div class="table-card">
                <div class="table-responsive">
                    <table class="table stock-table align-middle">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Product</th>
                                <th>Category / Metal</th>
                                <th>Barcode</th>
                                <th>Purity</th>
                                <th>Opening</th>
                                <th>In</th>
                                <th>Out</th>
                                <th>Closing</th>
                                <th>Gross Wt.</th>
                                <th>Net Wt.</th>
                                <th>Minimum</th>
                                <th>Stock Value</th>
                                <th>Status</th>
                                <th>Last Movement</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($products):
                                foreach ($products as $i => $p):
                                    $qty = (float) $p['closing_qty'];
                                    $min = (float) $p['minimum_stock_qty'];
                                    $status = 'In Stock';
                                    $cls = 'in-stock';
                                    if ($qty <= 0) {
                                        $status = 'Out of Stock';
                                        $cls = 'out-stock';
                                    } elseif ($min > 0 && $qty <= $min) {
                                        $status = 'Low Stock';
                                        $cls = 'low-stock';
                                    } ?>
                                    <tr>
                                        <td><?= ($offset + $i + 1) ?></td>
                                        <td class="product-cell">
                                            <div class="product-wrap"><?php if ($p['image_path']): ?><img class="product-img"
                                                        src="<?= h($p['image_path']) ?>" alt=""><?php else: ?>
                                                    <div class="product-icon"><i class="fa-solid fa-gem"></i></div><?php endif ?>
                                                <div>
                                                    <div class="product-name"><?= h($p['product_name']) ?></div>
                                                    <div class="small-muted"><?= h($p['product_code']) ?> ·
                                                        <?= h($p['unit_name']) ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?= h($p['category_name'] ?: '—') ?>
                                            <div class="small-muted"><?= h($p['metal_name'] ?: '—') ?></div>
                                        </td>
                                        <td><?= h($p['barcode'] ?: '—') ?></td>
                                        <td><?= number_format((float) $p['purity'], 4) ?></td>
                                        <td><?= number_format((float) $p['opening_qty'], 3) ?></td>
                                        <td class="number-in"><?= number_format((float) $p['qty_in'], 3) ?></td>
                                        <td class="number-out"><?= number_format((float) $p['qty_out'], 3) ?></td>
                                        <td class="number-main"><?= number_format($qty, 3) ?>         <?= h($p['unit_name']) ?></td>
                                        <td><?= number_format((float) $p['gross_weight'], 3) ?></td>
                                        <td><?= number_format((float) $p['net_weight'], 3) ?></td>
                                        <td><?= number_format($min, 3) ?></td>
                                        <td>₹<?= number_format((float) $p['stock_value'], 2) ?></td>
                                        <td><span class="badge-stock <?= $cls ?>"><?= $status ?></span></td>
                                        <td><?= !empty($p['last_movement_date']) ? date('d-m-Y h:i A', strtotime($p['last_movement_date'])) : '—' ?>
                                        </td>
                                        <td>
                                            <div class="d-flex gap-1"><a class="action-btn" title="Adjust Stock"
                                                    href="stock-adjustment.php?product_id=<?= (int) $p['id'] ?>"><i
                                                        class="fa-solid fa-sliders"></i></a><a class="action-btn"
                                                    title="Movements"
                                                    href="stock-movements.php?product_id=<?= (int) $p['id'] ?>&branch_id=<?= $branchId ?>"><i
                                                        class="fa-solid fa-right-left"></i></a><a class="action-btn"
                                                    title="View Product" href="product-view.php?id=<?= (int) $p['id'] ?>"><i
                                                        class="fa-regular fa-eye"></i></a></div>
                                        </td>
                                    </tr>
                                <?php endforeach; else: ?>
                                <tr>
                                    <td colspan="16" class="text-center py-5 text-muted">No stock records found.</td>
                                </tr><?php endif ?>
                        </tbody>
                    </table>
                </div>
                <div class="pagination-wrap">
                    <div>Showing <?= $total ? ($offset + 1) : 0 ?> to <?= $total ? min($offset + $perPage, $total) : 0 ?> of <?= $total ?>
                        products</div><?php if ($totalPages > 1): ?>
                        <nav>
                            <ul class="pagination pagination-sm">
                                <?php for ($pg = max(1, $page - 2); $pg <= min($totalPages, $page + 2); $pg++): ?>
                                    <li class="page-item <?= $pg === $page ? 'active' : '' ?>"><a class="page-link"
                                            href="<?= h(pageUrl(['page' => $pg])) ?>"><?= $pg ?></a></li><?php endfor ?>
                            </ul>
                        </nav><?php endif ?>
                </div>
            </div>
            <?php include('includes/footer.php'); ?>
        </div>
    </main><?php include('includes/script.php'); ?>
    <script src="assets/js/script.js"></script>
</body>

</html>