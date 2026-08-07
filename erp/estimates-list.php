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
function e($v): string
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
}
function jsonOut(bool $ok, string $message, array $extra = [], int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['success' => $ok, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function tableExists(mysqli $c, string $t): bool
{
    $t = $c->real_escape_string($t);
    $r = $c->query("SHOW TABLES LIKE '{$t}'");
    return $r && $r->num_rows > 0;
}
function bindParams(mysqli_stmt $stmt, string $types, array &$params): void
{
    $args = [$types];
    foreach ($params as $k => $v)
        $args[] =& $params[$k];
    call_user_func_array([$stmt, 'bind_param'], $args);
}
function estimatePermission(mysqli $c, string $action): bool
{
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin')
        return true;
    $map = ['open' => 'can_open', 'view' => 'can_view', 'create' => 'can_create', 'update' => 'can_update', 'delete' => 'can_delete', 'value' => 'can_view_value'];
    $field = $map[$action] ?? '';
    if ($field === '')
        return false;
    foreach (['perm.estimates', 'perm.billing', 'perm.sales'] as $code) {
        if (isset($_SESSION['permissions'][$code][$field]))
            return (int) $_SESSION['permissions'][$code][$field] === 1;
    }
    $bid = (int) ($_SESSION['business_id'] ?? 0);
    $rid = (int) ($_SESSION['role_id'] ?? 0);
    if ($bid <= 0 || $rid <= 0)
        return false;
    $sql = "SELECT rp.`{$field}` FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.business_id=? AND rp.role_id=? AND p.is_active=1 AND p.permission_code IN ('perm.estimates','perm.billing','perm.sales') ORDER BY FIELD(p.permission_code,'perm.estimates','perm.billing','perm.sales') LIMIT 1";
    $s = $c->prepare($sql);
    if (!$s)
        return false;
    $s->bind_param('ii', $bid, $rid);
    $s->execute();
    $x = $s->get_result()->fetch_assoc();
    $s->close();
    return (int) ($x[$field] ?? 0) === 1;
}
if (!estimatePermission($conn, 'open') && !estimatePermission($conn, 'view')) {
    http_response_code(403);
    die('You do not have permission to open estimates.');
}
$canCreate = estimatePermission($conn, 'create');
$canUpdate = estimatePermission($conn, 'update');
$canCancel = estimatePermission($conn, 'delete') || $canUpdate;
$canValue = estimatePermission($conn, 'value') || estimatePermission($conn, 'view');
$businessId = (int) ($_SESSION['business_id'] ?? 0);
$branchId = (int) ($_SESSION['branch_id'] ?? ($_SESSION['default_branch_id'] ?? 0));
if ($businessId <= 0 || $branchId <= 0)
    die('A valid business and branch must be selected.');
if (!tableExists($conn, 'estimates'))
    die('Estimate tables are not available. Run the estimate migration SQL first.');
if (empty($_SESSION['estimates_csrf']))
    $_SESSION['estimates_csrf'] = bin2hex(random_bytes(32));
$csrfToken = (string) $_SESSION['estimates_csrf'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrfToken, (string) ($_POST['csrf_token'] ?? '')))
        jsonOut(false, 'Invalid or expired request token.', [], 419);
    if (($_POST['action'] ?? '') === 'cancel_estimate') {
        if (!$canCancel)
            jsonOut(false, 'You do not have permission to cancel estimates.', [], 403);
        $id = (int) ($_POST['estimate_id'] ?? 0);
        if ($id <= 0)
            jsonOut(false, 'Invalid estimate.', [], 422);
        $s = $conn->prepare("UPDATE estimates SET status='Cancelled',updated_at=CURRENT_TIMESTAMP WHERE id=? AND business_id=? AND branch_id=? AND status='Open'");
        if (!$s)
            jsonOut(false, $conn->error, [], 500);
        $s->bind_param('iii', $id, $businessId, $branchId);
        if (!$s->execute()) {
            $m = $s->error;
            $s->close();
            jsonOut(false, $m, [], 500);
        }
        $n = $s->affected_rows;
        $s->close();
        if ($n < 1)
            jsonOut(false, 'Only open estimates can be cancelled.', [], 409);
        jsonOut(true, 'Estimate cancelled successfully.');
    }
    jsonOut(false, 'Invalid action.', [], 400);
}
$theme = ['primary_color' => '#d89416', 'primary_dark_color' => '#b86a0b', 'primary_soft_color' => '#fff6e5', 'sidebar_gradient_1' => '#171c21', 'sidebar_gradient_2' => '#20272d', 'sidebar_gradient_3' => '#101419', 'page_background' => '#f4f3f0', 'card_background' => '#fff', 'text_color' => '#171717', 'muted_text_color' => '#7d8794', 'border_color' => '#e8e8e8', 'font_family' => 'Inter', 'heading_font_family' => 'Playfair Display', 'border_radius_px' => 12];
$s = $conn->prepare('SELECT * FROM business_theme_settings WHERE business_id=? LIMIT 1');
if ($s) {
    $s->bind_param('i', $businessId);
    $s->execute();
    $tr = $s->get_result()->fetch_assoc() ?: [];
    $s->close();
    foreach ($theme as $k => $v)
        if (isset($tr[$k]) && $tr[$k] !== '')
            $theme[$k] = $tr[$k];
}
$search = trim((string) ($_GET['search'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));
$dateFrom = trim((string) ($_GET['date_from'] ?? date('Y-m-01')));
$dateTo = trim((string) ($_GET['date_to'] ?? date('Y-m-d')));
$perPage = max(10, min(100, (int) ($_GET['per_page'] ?? 20)));
$page = max(1, (int) ($_GET['page'] ?? 1));
$where = ['e.business_id=?', 'e.branch_id=?'];
$types = 'ii';
$params = [$businessId, $branchId];
if ($dateFrom !== '') {
    $where[] = 'e.estimate_date>=?';
    $types .= 's';
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $where[] = 'e.estimate_date<=?';
    $types .= 's';
    $params[] = $dateTo;
}
if (in_array($status, ['Open', 'Converted', 'Expired', 'Cancelled'], true)) {
    $where[] = 'e.status=?';
    $types .= 's';
    $params[] = $status;
}
if ($search !== '') {
    $where[] = '(e.estimate_no LIKE ? OR e.customer_name LIKE ? OR e.customer_mobile LIKE ?)';
    $like = '%' . $search . '%';
    $types .= 'sss';
    array_push($params, $like, $like, $like);
}
$whereSql = implode(' AND ', $where);
$c = $conn->prepare("SELECT COUNT(*) total FROM estimates e WHERE {$whereSql}");
$totalRows = 0;
if ($c) {
    bindParams($c, $types, $params);
    $c->execute();
    $totalRows = (int) ($c->get_result()->fetch_assoc()['total'] ?? 0);
    $c->close();
}
$totalPages = max(1, (int) ceil($totalRows / $perPage));
if ($page > $totalPages)
    $page = $totalPages;
$offset = ($page - 1) * $perPage;
$listParams = $params;
$listTypes = $types . 'ii';
$listParams[] = $perPage;
$listParams[] = $offset;
$sql = "SELECT e.*,c.customer_code,COALESCE((SELECT COUNT(*) FROM estimate_items ei WHERE ei.estimate_id=e.id),0) item_count FROM estimates e LEFT JOIN customers c ON c.id=e.customer_id AND c.business_id=e.business_id WHERE {$whereSql} ORDER BY e.estimate_date DESC,e.id DESC LIMIT ? OFFSET ?";
$l = $conn->prepare($sql);
$estimates = [];
if ($l) {
    bindParams($l, $listTypes, $listParams);
    $l->execute();
    $r = $l->get_result();
    while ($x = $r->fetch_assoc())
        $estimates[] = $x;
    $l->close();
}
$stats = ['total' => 0, 'open' => 0, 'converted' => 0, 'value' => 0];
$s = $conn->prepare("SELECT COUNT(*) total,SUM(status='Open') open_count,SUM(status='Converted') converted_count,COALESCE(SUM(CASE WHEN status<>'Cancelled' THEN net_estimate_amount ELSE 0 END),0) total_value FROM estimates WHERE business_id=? AND branch_id=?");
if ($s) {
    $s->bind_param('ii', $businessId, $branchId);
    $s->execute();
    $x = $s->get_result()->fetch_assoc() ?: [];
    $s->close();
    $stats = ['total' => (int) ($x['total'] ?? 0), 'open' => (int) ($x['open_count'] ?? 0), 'converted' => (int) ($x['converted_count'] ?? 0), 'value' => (float) ($x['total_value'] ?? 0)];
}
function queryUrl(array $changes = []): string
{
    $q = array_merge($_GET, $changes);
    foreach ($q as $k => $v)
        if ($v === '' || $v === null)
            unset($q[$k]);
    return 'estimates-list.php' . ($q ? '?' . http_build_query($q) : '');
}
$pageTitle = 'Estimates';
$businessName = (string) ($_SESSION['business_name'] ?? 'Jewellery ERP');
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($businessName) ?> - Estimates</title><?php include('includes/links.php'); ?>
    <style>
        :root {
            --primary: <?= e($theme['primary_color']) ?>;
            --primary-dark: <?= e($theme['primary_dark_color']) ?>;
            --primary-soft: <?= e($theme['primary_soft_color']) ?>;
            --page-bg: <?= e($theme['page_background']) ?>;
            --card-bg: <?= e($theme['card_background']) ?>;
            --text: <?= e($theme['text_color']) ?>;
            --muted: <?= e($theme['muted_text_color']) ?>;
            --line: <?= e($theme['border_color']) ?>;
            --radius: <?= (int) $theme['border_radius_px'] ?>px
        }

        body {
            background: var(--page-bg);
            color: var(--text);
            font-family: <?= json_encode($theme['font_family']) ?>, sans-serif
        }

        .app-main {
            min-height: 100vh;
            background: var(--page-bg)
        }

        .content-wrap {
            padding: 18px
        }

        .page-card,
        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--line);
            border-radius: var(--radius)
        }

        .page-head {
            padding: 14px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px
        }

        .page-title {
            font: 700 20px
                <?= json_encode($theme['heading_font_family']) ?>
                , serif
        }

        .page-subtitle,
        .sub {
            font-size: 9px;
            color: var(--muted)
        }

        .btn-theme,
        .btn-soft {
            border-radius: 9px;
            padding: 9px 13px;
            font-size: 11px;
            font-weight: 800;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px
        }

        .btn-theme {
            border: 0;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: #fff
        }

        .btn-soft {
            border: 1px solid var(--line);
            background: var(--card-bg);
            color: var(--text)
        }

        .stat-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 10px;
            margin: 12px 0
        }

        .stat-card {
            padding: 14px;
            display: flex;
            align-items: center;
            gap: 11px
        }

        .stat-icon {
            width: 42px;
            height: 42px;
            border-radius: 11px;
            background: var(--primary-soft);
            color: var(--primary-dark);
            display: grid;
            place-items: center
        }

        .stat-label {
            font-size: 9px;
            text-transform: uppercase;
            color: var(--muted);
            font-weight: 700
        }

        .stat-value {
            font-size: 20px;
            font-weight: 900
        }

        .toolbar {
            padding: 12px;
            margin-bottom: 12px
        }

        .filter-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr 1.5fr .65fr auto auto;
            gap: 8px;
            align-items: end
        }

        .field-label {
            display: block;
            font-size: 9px;
            font-weight: 800;
            margin-bottom: 4px;
            color: var(--muted);
            text-transform: uppercase
        }

        .form-control,
        .form-select {
            min-height: 38px;
            border: 1px solid var(--line);
            border-radius: 9px;
            background: var(--card-bg);
            color: var(--text);
            font-size: 11px
        }

        .table {
            margin: 0;
            font-size: 10px;
            --bs-table-bg: var(--card-bg);
            --bs-table-color: var(--text);
            --bs-table-border-color: var(--line)
        }

        .table th {
            font-size: 9px;
            text-transform: uppercase;
            color: var(--muted);
            background: color-mix(in srgb, var(--muted) 7%, var(--card-bg));
            white-space: nowrap;
            padding: 10px
        }

        .table td {
            padding: 10px;
            vertical-align: middle
        }

        .estimate-no {
            font-weight: 900;
            color: var(--primary-dark)
        }

        .badge-soft {
            display: inline-flex;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 9px;
            font-weight: 800
        }

        .status-open {
            background: #fff4d8;
            color: #9a6700
        }

        .status-converted {
            background: #eaf8f0;
            color: #168449
        }

        .status-expired {
            background: #edf0f2;
            color: #5f6b74
        }

        .status-cancelled {
            background: #fdecec;
            color: #bd2d2d
        }

        .action-group {
            display: inline-flex;
            gap: 4px
        }

        .action-btn {
            width: 30px;
            height: 30px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: var(--card-bg);
            color: var(--text);
            display: inline-grid;
            place-items: center;
            text-decoration: none
        }

        .action-btn:hover {
            background: var(--primary-soft);
            color: var(--primary-dark)
        }

        .pagination-wrap {
            padding: 11px 12px;
            border-top: 1px solid var(--line);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px
        }

        .pagination {
            margin: 0;
            gap: 4px
        }

        .page-link {
            font-size: 10px;
            border-radius: 8px !important;
            color: var(--text);
            background: var(--card-bg);
            border-color: var(--line)
        }

        .empty-state {
            padding: 35px;
            text-align: center;
            color: var(--muted)
        }

        .theme-toast {
            position: fixed;
            right: 18px;
            top: 78px;
            z-index: 20000;
            padding: 11px 14px;
            border-radius: 10px;
            color: #fff;
            font-size: 11px;
            font-weight: 700;
            opacity: 0;
            transform: translateY(-10px);
            transition: .2s
        }

        .theme-toast.show {
            opacity: 1;
            transform: none
        }

        .theme-toast-success {
            background: #168449
        }

        .theme-toast-error {
            background: #c0392b
        }

        @media(max-width:1100px) {
            .filter-grid {
                grid-template-columns: repeat(2, 1fr)
            }

            .stat-grid {
                grid-template-columns: repeat(2, 1fr)
            }
        }

        @media(max-width:767px) {

            .stat-grid,
            .filter-grid {
                grid-template-columns: 1fr
            }

            .page-head,
            .pagination-wrap {
                align-items: flex-start;
                flex-direction: column
            }

            .content-wrap {
                padding: 74px 10px 10px
            }
        }
    </style>
</head>

<body><?php include('includes/sidebar.php'); ?>
    <main class="app-main"><?php include('includes/nav.php'); ?>
        <div class="content-wrap">
            <div class="page-card">
                <div class="page-head">
                    <div>
                        <div class="page-title">Estimates</div>
                        <div class="page-subtitle">Separate estimates with proposed payments, exchange and gold claims.
                        </div>
                    </div><?php if ($canCreate): ?><a href="billing.php?type=Estimate" class="btn-theme"><i
                                class="fa-solid fa-plus"></i>New Estimate</a><?php endif; ?>
                </div>
            </div>
            <div class="stat-grid">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fa-solid fa-file-lines"></i></div>
                    <div>
                        <div class="stat-label">Total Estimates</div>
                        <div class="stat-value"><?= number_format($stats['total']) ?></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fa-regular fa-clock"></i></div>
                    <div>
                        <div class="stat-label">Open</div>
                        <div class="stat-value"><?= number_format($stats['open']) ?></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fa-solid fa-circle-check"></i></div>
                    <div>
                        <div class="stat-label">Converted</div>
                        <div class="stat-value"><?= number_format($stats['converted']) ?></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fa-solid fa-indian-rupee-sign"></i></div>
                    <div>
                        <div class="stat-label">Estimate Value</div>
                        <div class="stat-value"><?= $canValue ? '₹' . number_format($stats['value'], 2) : '••••' ?></div>
                    </div>
                </div>
            </div>
            <form class="page-card toolbar" method="get" action="estimates-list.php">
                <div class="filter-grid">
                    <div><label class="field-label">From Date</label><input type="date" name="date_from"
                            class="form-control" value="<?= e($dateFrom) ?>"></div>
                    <div><label class="field-label">To Date</label><input type="date" name="date_to"
                            class="form-control" value="<?= e($dateTo) ?>"></div>
                    <div><label class="field-label">Status</label><select name="status" class="form-select">
                            <option value="">All Statuses</option>
                            <?php foreach (['Open', 'Converted', 'Expired', 'Cancelled'] as $o): ?>
                                <option value="<?= e($o) ?>" <?= $status === $o ? 'selected' : '' ?>><?= e($o) ?></option>
                            <?php endforeach; ?>
                        </select></div>
                    <div><label class="field-label">Search</label><input name="search" class="form-control"
                            value="<?= e($search) ?>" placeholder="Estimate no, customer, mobile..."></div>
                    <div><label class="field-label">Rows</label><select name="per_page"
                            class="form-select"><?php foreach ([10, 20, 50, 100] as $n): ?>
                                <option value="<?= $n ?>" <?= $perPage === $n ? 'selected' : '' ?>><?= $n ?></option><?php endforeach; ?>
                        </select></div><button class="btn-theme"><i
                            class="fa-solid fa-magnifying-glass"></i>Search</button><a href="estimates-list.php"
                        class="btn-soft"><i class="fa-solid fa-rotate-left"></i>Reset</a>
                </div>
            </form>
            <div class="page-card">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Estimate</th>
                                <th>Date</th>
                                <th>Customer</th>
                                <th>Items</th>
                                <th class="text-end">Amount</th>
                                <th class="text-end">Proposed Paid</th>
                                <th class="text-end">Balance</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody><?php if (!$estimates): ?>
                                <tr>
                                    <td colspan="9">
                                        <div class="empty-state">No estimates found.</div>
                                    </td>
                                </tr>
                            <?php else:
                            foreach ($estimates as $row):
                                $sc = 'status-' . strtolower((string) $row['status']); ?>
                                    <tr>
                                        <td>
                                            <div class="estimate-no"><?= e($row['estimate_no']) ?></div>
                                            <div class="sub">#<?= (int) $row['id'] ?></div>
                                        </td>
                                        <td><?= e(date('d-m-Y', strtotime($row['estimate_date']))) ?>
                                            <div class="sub"><?= e(date('h:i A', strtotime($row['estimate_time']))) ?></div>
                                        </td>
                                        <td><strong><?= e($row['customer_name'] ?: 'Walk-in Customer') ?></strong>
                                            <div class="sub">
                                                <?= e(trim(($row['customer_code'] ?? '') . ' ' . ($row['customer_mobile'] ?? ''))) ?>
                                            </div>
                                        </td>
                                        <td><?= number_format((int) $row['item_count']) ?></td>
                                        <td class="text-end">
                                            <strong><?= $canValue ? '₹' . number_format((float) $row['net_estimate_amount'], 2) : '••••' ?></strong>
                                        </td>
                                        <td class="text-end">
                                            <?= $canValue ? '₹' . number_format((float) $row['proposed_paid_amount'], 2) : '••••' ?></td>
                                        <td class="text-end">
                                            <?= $canValue ? '₹' . number_format((float) $row['proposed_balance_amount'], 2) : '••••' ?>
                                        </td>
                                        <td><span class="badge-soft <?= e($sc) ?>"><?= e($row['status']) ?></span></td>
                                        <td class="text-end">
                                            <div class="action-group"><a class="action-btn"
                                                    href="estimate-view.php?id=<?= (int) $row['id'] ?>" title="View"><i
                                                        class="fa-regular fa-eye"></i></a><a class="action-btn"
                                                    href="estimate-print.php?id=<?= (int) $row['id'] ?>&inline=1" target="_blank"
                                                    title="Print"><i
                                                        class="fa-solid fa-print"></i></a><?php if ($canCancel && $row['status'] === 'Open'): ?><button
                                                        type="button" class="action-btn cancel-estimate"
                                                        data-id="<?= (int) $row['id'] ?>" data-no="<?= e($row['estimate_no']) ?>"
                                                        title="Cancel"><i class="fa-solid fa-ban"></i></button><?php endif; ?></div>
                                        </td>
                                    </tr><?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="pagination-wrap">
                    <div class="small text-muted">Showing
                        <?= $totalRows ? number_format($offset + 1) : 0 ?>-<?= number_format(min($offset + $perPage, $totalRows)) ?>
                        of <?= number_format($totalRows) ?></div><?php if ($totalPages > 1): ?>
                        <nav>
                            <ul class="pagination">
                                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link"
                                        href="<?= e(queryUrl(['page' => max(1, $page - 1)])) ?>">‹</a></li>
                                <?php $from = max(1, $page - 2);
                                $to = min($totalPages, $page + 2);
                                for ($i = $from; $i <= $to; $i++): ?>
                                    <li class="page-item <?= $i === $page ? 'active' : '' ?>"><a class="page-link"
                                            href="<?= e(queryUrl(['page' => $i])) ?>"><?= $i ?></a></li><?php endfor; ?>
                                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>"><a class="page-link"
                                        href="<?= e(queryUrl(['page' => min($totalPages, $page + 1)])) ?>">›</a></li>
                            </ul>
                        </nav><?php endif; ?>
                </div>
            </div><?php include('includes/footer.php'); ?>
        </div>
    </main>
    <div class="modal fade" id="cancelEstimateModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Cancel Estimate</h5><button type="button" class="btn-close"
                        data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">Cancel <strong id="cancelEstimateNo"></strong>?</div>
                <div class="modal-footer"><button type="button" class="btn btn-light btn-sm"
                        data-bs-dismiss="modal">Keep</button><button type="button" class="btn btn-danger btn-sm"
                        id="confirmCancelEstimate">Cancel Estimate</button></div>
            </div>
        </div>
    </div>
    <div class="theme-toast" id="toast"></div><?php include('includes/script.php'); ?>
    <script src="assets/js/script.js"></script>
    <script>(function () { 'use strict'; const csrf = <?= json_encode($csrfToken) ?>, toast = document.getElementById('toast'), modal = new bootstrap.Modal(document.getElementById('cancelEstimateModal')); let id = 0; function notify(ok, m) { toast.className = 'theme-toast ' + (ok ? 'theme-toast-success' : 'theme-toast-error'); toast.textContent = m; toast.classList.add('show'); setTimeout(() => toast.classList.remove('show'), 3000) } document.addEventListener('click', e => { const b = e.target.closest('.cancel-estimate'); if (!b) return; id = Number(b.dataset.id); document.getElementById('cancelEstimateNo').textContent = b.dataset.no; modal.show() }); document.getElementById('confirmCancelEstimate').addEventListener('click', async function () { if (!id) return; this.disabled = true; try { const fd = new FormData(); fd.append('action', 'cancel_estimate'); fd.append('csrf_token', csrf); fd.append('estimate_id', String(id)); const r = await fetch('estimates-list.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' }); const d = await r.json(); if (!r.ok || !d.success) throw new Error(d.message || 'Unable to cancel estimate.'); notify(true, d.message); modal.hide(); setTimeout(() => location.reload(), 500) } catch (x) { notify(false, x.message) } finally { this.disabled = false } }); const p = new URLSearchParams(location.search); if (p.get('msg') === 'created') notify(true, 'Estimate created successfully.'); })();</script>
</body>

</html>