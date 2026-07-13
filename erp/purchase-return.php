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

if (!function_exists('generateReturnNo')) {
    function generateReturnNo(mysqli $conn, int $businessId): string
    {
        $prefix = 'PRN' . date('Ymd');
        $running = 1;

        $stmt = $conn->prepare("SELECT return_no FROM purchase_returns WHERE business_id = ? AND return_no LIKE ? ORDER BY id DESC LIMIT 1");
        if ($stmt) {
            $like = $prefix . '%';
            $stmt->bind_param('is', $businessId, $like);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($row && preg_match('/(\d{4})$/', (string)$row['return_no'], $match)) {
                $running = (int)$match[1] + 1;
            }
        }

        return $prefix . str_pad((string)$running, 4, '0', STR_PAD_LEFT);
    }
}

$pageTitle = 'Purchase Return';
$page_title = 'Purchase Return';
$currentPage = 'purchase-return';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$businessId = (int)($_SESSION['business_id'] ?? 0);
if ($businessId <= 0) {
    http_response_code(403);
    die('A valid business must be selected before creating a purchase return.');
}

function purchaseReturnPermission(mysqli $conn, string $action): bool
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
    foreach (['perm.purchases.returns', 'perm.purchases'] as $key) {
        if (isset($permissions[$key][$field])) {
            return (int)$permissions[$key][$field] === 1;
        }
    }

    $businessId = (int)($_SESSION['business_id'] ?? 0);
    $roleId = (int)($_SESSION['role_id'] ?? 0);
    if ($businessId <= 0 || $roleId <= 0) {
        $roleName = strtolower(trim((string)($_SESSION['role_name'] ?? '')));
        return in_array($roleName, ['admin', 'manager', 'stock'], true);
    }

    $sql = "SELECT rp.`{$field}`
            FROM role_permissions rp
            INNER JOIN permissions p ON p.id = rp.permission_id
            WHERE rp.business_id = ?
              AND rp.role_id = ?
              AND p.is_active = 1
              AND p.permission_code IN ('perm.purchases.returns','perm.purchases')
            ORDER BY FIELD(p.permission_code,'perm.purchases.returns','perm.purchases')
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

if (!purchaseReturnPermission($conn, 'open')) {
    http_response_code(403);
    die('Access denied. You do not have permission to open purchase returns.');
}

$canView = purchaseReturnPermission($conn, 'view') || purchaseReturnPermission($conn, 'open');
$canCreate = purchaseReturnPermission($conn, 'create');
$canViewValue = purchaseReturnPermission($conn, 'value') || $canView;

foreach (['purchases', 'purchase_items', 'suppliers', 'purchase_returns', 'purchase_return_items'] as $requiredTable) {
    if (!tableExists($conn, $requiredTable)) {
        die("Required table `{$requiredTable}` was not found.");
    }
}

if (empty($_SESSION['purchase_return_csrf'])) {
    $_SESSION['purchase_return_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['purchase_return_csrf'];

$purchaseSearch = trim((string)($_GET['search'] ?? ''));
$purchaseId = (int)($_GET['purchase_id'] ?? 0);
$returnNo = generateReturnNo($conn, $businessId);
$returnDate = date('Y-m-d');

$purchases = [];
$sql = "SELECT p.id, p.purchase_no, p.purchase_date, p.invoice_no, s.supplier_name
        FROM purchases p
        LEFT JOIN suppliers s ON s.id = p.supplier_id
        WHERE p.business_id = ?";
$params = [$businessId];
$types = 'i';

if ($purchaseSearch !== '') {
    $sql .= " AND (p.purchase_no LIKE ? OR p.invoice_no LIKE ? OR s.supplier_name LIKE ?)";
    $like = '%' . $purchaseSearch . '%';
    array_push($params, $like, $like, $like);
    $types .= 'sss';
}

$sql .= " ORDER BY p.id DESC LIMIT 50";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $bind = [$types];
    foreach ($params as $key => $value) {
        $bind[] = &$params[$key];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($result && $row = $result->fetch_assoc()) {
        $purchases[] = $row;
    }
    $stmt->close();
}

$selectedPurchase = null;
$purchaseItems = [];

if ($purchaseId > 0) {
    $stmt = $conn->prepare("SELECT p.*, s.supplier_name FROM purchases p LEFT JOIN suppliers s ON s.id = p.supplier_id WHERE p.id = ? AND p.business_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('ii', $purchaseId, $businessId);
        $stmt->execute();
        $selectedPurchase = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    if ($selectedPurchase) {
        $stmt = $conn->prepare("SELECT pi.* FROM purchase_items pi WHERE pi.purchase_id = ? AND pi.business_id = ? ORDER BY pi.id ASC");
        if ($stmt) {
            $stmt->bind_param('ii', $purchaseId, $businessId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($result && $row = $result->fetch_assoc()) {
                $purchaseItems[] = $row;
            }
            $stmt->close();
        }
    }
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
<title><?php echo h($businessName); ?> - Purchase Return</title>
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
body{background:var(--page-bg);color:var(--text-color);font-family:<?php echo json_encode((string)$theme['font_family']); ?>,sans-serif;}
.sidebar{background:linear-gradient(180deg,<?php echo h($theme['sidebar_gradient_1']); ?>,<?php echo h($theme['sidebar_gradient_2']); ?>,<?php echo h($theme['sidebar_gradient_3']); ?>)!important;}
.page-heading{margin-bottom:10px}.page-title{font-family:<?php echo json_encode((string)$theme['heading_font_family']); ?>,serif;font-size:18px;font-weight:800;margin:0}.page-subtitle{font-size:10px;color:var(--muted-color);margin-top:2px}
.panel{background:var(--card-bg);border:1px solid var(--border-color);border-radius:var(--radius);margin-bottom:10px;overflow:hidden}
.panel-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:11px 13px;border-bottom:1px solid var(--border-color)}
.panel-title{font-size:12px;font-weight:800}.panel-subtitle{font-size:9px;color:var(--muted-color);margin-top:2px}.panel-body{padding:12px}
.search-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px}
.form-control,.form-select{font-size:10px;min-height:36px;border-radius:9px;border-color:var(--border-color);background:var(--card-bg);color:var(--text-color)}
.field-label{display:block;font-size:9px;font-weight:700;margin-bottom:4px;color:var(--muted-color);text-transform:uppercase}
.btn-theme{background:linear-gradient(135deg,var(--primary),var(--primary-dark));border:0;color:#fff;border-radius:9px;font-size:10px;font-weight:700;min-height:36px;padding:8px 13px}
.btn-soft{background:var(--primary-soft);color:var(--primary-dark);border:0;border-radius:8px;font-size:9px;font-weight:800;padding:7px 10px}
.btn-light-custom{border:1px solid var(--border-color);background:var(--card-bg);color:var(--text-color);border-radius:9px;font-size:10px;font-weight:700;min-height:36px;padding:8px 12px}
.compact-table{margin:0;font-size:10px}.compact-table th{font-size:9px;text-transform:uppercase;color:var(--muted-color);background:color-mix(in srgb,var(--muted-color) 6%,transparent);padding:10px 11px;white-space:nowrap;border-color:var(--border-color)}.compact-table td{padding:10px 11px;vertical-align:middle;background:var(--card-bg)!important;color:var(--text-color);border-color:var(--border-color)}
.purchase-no{font-weight:800}.purchase-sub{font-size:8px;color:var(--muted-color);margin-top:2px}
.entry-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:12px}
.return-table{min-width:1450px}.return-table input{min-width:105px}
.summary-wrap{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:12px;margin-top:12px}.summary-box{border:1px solid var(--border-color);border-radius:10px;overflow:hidden}.summary-row{display:grid;grid-template-columns:1fr 130px;align-items:center;border-bottom:1px solid var(--border-color)}.summary-row:last-child{border-bottom:0}.summary-row strong{padding:10px 12px;font-size:10px}.summary-row input{border:0;border-radius:0;text-align:right;font-weight:800}
.theme-toast{position:fixed;right:18px;top:78px;z-index:20000;display:flex;align-items:center;gap:9px;min-width:260px;max-width:420px;padding:11px 14px;border-radius:10px;color:#fff;font-size:11px;font-weight:600;box-shadow:0 14px 35px rgba(0,0,0,.22);opacity:0;transform:translateY(-10px);transition:.22s}.theme-toast.show{opacity:1;transform:translateY(0)}.theme-toast-success{background:#168449}.theme-toast-error{background:#c0392b}
.empty-state{padding:38px 20px;text-align:center;color:var(--muted-color)}
body.dark-mode,body[data-theme="dark"],html.dark-mode body,html[data-theme="dark"] body{--page-bg:#0f151b;--card-bg:#182129;--text-color:#f3f6f8;--muted-color:#9aa7b3;--border-color:#2c3944}
@media(max-width:991.98px){.entry-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.summary-wrap{grid-template-columns:1fr}.purchase-table thead{display:none}.purchase-table tbody{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.purchase-table tbody tr{display:grid;grid-template-columns:1fr 1fr;border:1px solid var(--border-color);border-radius:var(--radius);padding:12px;background:var(--card-bg)}.purchase-table tbody td{display:flex;justify-content:space-between;gap:10px;padding:8px 0;border:0;border-bottom:1px dashed var(--border-color);text-align:right}.purchase-table tbody td::before{content:attr(data-label);font-size:8px;font-weight:700;text-transform:uppercase;color:var(--muted-color)}.purchase-table tbody td.purchase-column{grid-column:1/-1;display:block;text-align:left}.purchase-table tbody td.purchase-column::before{display:none}}
@media(max-width:767.98px){.content-wrap{padding-left:10px;padding-right:10px}.search-row,.entry-grid{grid-template-columns:1fr}.purchase-table tbody{grid-template-columns:1fr}.purchase-table tbody tr{grid-template-columns:1fr}.purchase-table tbody td{grid-column:1/-1}}
</style>
</head>
<body>
<?php include('includes/sidebar.php'); ?>
<main class="app-main">
<?php include('includes/nav.php'); ?>
<div class="content-wrap">
    <div class="page-heading">
        <h1 class="page-title">Purchase Return</h1>
        <div class="page-subtitle"><?php echo h($businessName); ?> · Purchase return and stock reversal</div>
    </div>

    <?php if (!$canView): ?>
        <div class="panel"><div class="empty-state"><i class="fa-solid fa-lock mb-2"></i><div>You do not have permission to view purchase returns.</div></div></div>
    <?php else: ?>
        <div class="panel">
            <div class="panel-head"><div><div class="panel-title">Find Purchase</div><div class="panel-subtitle">Search the latest 50 purchases by purchase number, invoice or supplier.</div></div></div>
            <div class="panel-body">
                <form method="get" class="search-row">
                    <input type="search" name="search" class="form-control" placeholder="Search purchase no, invoice no, supplier..." value="<?php echo h($purchaseSearch); ?>">
                    <button type="submit" class="btn btn-theme"><i class="fa-solid fa-magnifying-glass me-1"></i>Search</button>
                </form>
            </div>
            <?php if ($purchases): ?>
                <div class="table-responsive">
                    <table class="table compact-table purchase-table">
                        <thead><tr><th>#</th><th>Purchase</th><th>Date</th><th>Supplier</th><th>Invoice No</th><th>Action</th></tr></thead>
                        <tbody>
                        <?php foreach ($purchases as $index => $purchase): ?>
                            <tr>
                                <td data-label="#"><?php echo $index + 1; ?></td>
                                <td class="purchase-column" data-label="Purchase"><div class="purchase-no"><?php echo h($purchase['purchase_no'] ?? ''); ?></div><div class="purchase-sub">Purchase ID: <?php echo (int)$purchase['id']; ?></div></td>
                                <td data-label="Date"><?php echo !empty($purchase['purchase_date']) ? date('d M Y', strtotime($purchase['purchase_date'])) : '—'; ?></td>
                                <td data-label="Supplier"><?php echo h($purchase['supplier_name'] ?? '—'); ?></td>
                                <td data-label="Invoice"><?php echo h($purchase['invoice_no'] ?? '—'); ?></td>
                                <td data-label="Action"><a href="purchase-return.php?purchase_id=<?php echo (int)$purchase['id']; ?>" class="btn btn-soft">Select</a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">No purchases found.</div>
            <?php endif; ?>
        </div>

        <?php if ($selectedPurchase): ?>
            <form id="purchaseReturnForm">
                <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
                <input type="hidden" name="purchase_id" value="<?php echo (int)$selectedPurchase['id']; ?>">

                <div class="panel">
                    <div class="panel-head">
                        <div><div class="panel-title">Purchase Return Entry</div><div class="panel-subtitle"><?php echo h($selectedPurchase['purchase_no'] ?? ''); ?> · <?php echo h($selectedPurchase['supplier_name'] ?? ''); ?></div></div>
                    </div>
                    <div class="panel-body">
                        <div class="entry-grid">
                            <div><label class="field-label">Return Number</label><input type="text" name="return_no" class="form-control" value="<?php echo h($returnNo); ?>" required></div>
                            <div><label class="field-label">Return Date</label><input type="date" name="return_date" class="form-control" value="<?php echo h($returnDate); ?>" required></div>
                            <div><label class="field-label">Purchase Number</label><input type="text" class="form-control" value="<?php echo h($selectedPurchase['purchase_no'] ?? ''); ?>" readonly></div>
                            <div><label class="field-label">Supplier</label><input type="text" class="form-control" value="<?php echo h($selectedPurchase['supplier_name'] ?? ''); ?>" readonly></div>
                        </div>

                        <div class="table-responsive">
                            <table class="table compact-table return-table" id="returnTable">
                                <thead><tr><th>Item</th><th>Purchased Qty</th><th>Purchased Net Wt</th><th>Rate/Gm</th><th>GST %</th><th>Return Qty</th><th>Return Wt</th><th>Taxable</th><th>GST</th><th>Total</th></tr></thead>
                                <tbody>
                                <?php foreach ($purchaseItems as $index => $item): ?>
                                    <tr>
                                        <td><strong><?php echo h($item['item_name'] ?? ''); ?></strong>
                                            <input type="hidden" name="items[<?php echo $index; ?>][purchase_item_id]" value="<?php echo (int)$item['id']; ?>">
                                            <input type="hidden" name="items[<?php echo $index; ?>][product_id]" value="<?php echo (int)($item['product_id'] ?? 0); ?>">
                                            <input type="hidden" name="items[<?php echo $index; ?>][item_name]" value="<?php echo h($item['item_name'] ?? ''); ?>">
                                        </td>
                                        <td><?php echo number_format((float)($item['qty'] ?? 0), 3); ?><input type="hidden" name="items[<?php echo $index; ?>][qty]" value="<?php echo h($item['qty'] ?? 0); ?>"></td>
                                        <td><?php echo number_format((float)($item['net_weight'] ?? 0), 3); ?><input type="hidden" name="items[<?php echo $index; ?>][net_weight]" value="<?php echo h($item['net_weight'] ?? 0); ?>"></td>
                                        <td><?php echo number_format((float)($item['rate_per_gram'] ?? 0), 2); ?><input type="hidden" name="items[<?php echo $index; ?>][rate_per_gram]" class="rate-per-gram" value="<?php echo h($item['rate_per_gram'] ?? 0); ?>"></td>
                                        <td><?php echo number_format((float)($item['gst_percent'] ?? 0), 2); ?><input type="hidden" name="items[<?php echo $index; ?>][gst_percent]" class="gst-percent" value="<?php echo h($item['gst_percent'] ?? 0); ?>"></td>
                                        <td><input type="number" step="0.001" min="0" max="<?php echo h($item['qty'] ?? 0); ?>" name="items[<?php echo $index; ?>][return_qty]" class="form-control return-qty"></td>
                                        <td><input type="number" step="0.001" min="0" max="<?php echo h($item['net_weight'] ?? 0); ?>" name="items[<?php echo $index; ?>][return_weight]" class="form-control return-weight"></td>
                                        <td><input type="number" step="0.01" class="form-control return-taxable" value="0.00" readonly></td>
                                        <td><input type="number" step="0.01" class="form-control return-gst" value="0.00" readonly></td>
                                        <td><input type="number" step="0.01" class="form-control return-total" value="0.00" readonly></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="summary-wrap">
                            <div><label class="field-label">Notes</label><textarea name="notes" class="form-control" rows="4" maxlength="1000"></textarea></div>
                            <div class="summary-box">
                                <div class="summary-row"><strong>Subtotal</strong><input id="summarySubtotal" class="form-control" value="0.00" readonly></div>
                                <div class="summary-row"><strong>GST Total</strong><input id="summaryGst" class="form-control" value="0.00" readonly></div>
                                <div class="summary-row"><strong>Grand Total</strong><input id="summaryGrand" class="form-control" value="0.00" readonly></div>
                            </div>
                        </div>

                        <div class="d-flex flex-wrap gap-2 mt-3">
                            <?php if ($canCreate): ?><button type="submit" class="btn btn-theme" id="saveReturnButton"><i class="fa-solid fa-floppy-disk me-1"></i>Save Purchase Return</button><?php endif; ?>
                            <a href="purchases.php" class="btn btn-light-custom">Back to Purchases</a>
                        </div>
                    </div>
                </div>
            </form>
        <?php endif; ?>
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
        const t=document.createElement('div');
        t.className='theme-toast theme-toast-'+type;
        t.innerHTML='<i class="fa-solid '+(type==='success'?'fa-circle-check':'fa-circle-exclamation')+'"></i><span></span>';
        t.querySelector('span').textContent=message;
        document.body.appendChild(t);
        requestAnimationFrame(()=>t.classList.add('show'));
        setTimeout(()=>{t.classList.remove('show');setTimeout(()=>t.remove(),250)},3200);
    }

    function calculateSummary(){
        let subtotal=0,gst=0,grand=0;
        document.querySelectorAll('#returnTable tbody tr').forEach(function(row){
            subtotal+=parseFloat(row.querySelector('.return-taxable').value||0);
            gst+=parseFloat(row.querySelector('.return-gst').value||0);
            grand+=parseFloat(row.querySelector('.return-total').value||0);
        });
        document.getElementById('summarySubtotal').value=subtotal.toFixed(2);
        document.getElementById('summaryGst').value=gst.toFixed(2);
        document.getElementById('summaryGrand').value=grand.toFixed(2);
    }

    function calculateRow(row){
        const purchasedQty=parseFloat(row.querySelector('input[name*="[qty]"]').value||0);
        const purchasedWeight=parseFloat(row.querySelector('input[name*="[net_weight]"]').value||0);
        const rate=parseFloat(row.querySelector('.rate-per-gram').value||0);
        const gstPercent=parseFloat(row.querySelector('.gst-percent').value||0);
        const qtyInput=row.querySelector('.return-qty');
        const weightInput=row.querySelector('.return-weight');

        let returnQty=Math.max(0,parseFloat(qtyInput.value||0));
        let returnWeight=Math.max(0,parseFloat(weightInput.value||0));

        if(returnQty>purchasedQty){returnQty=purchasedQty;qtyInput.value=returnQty.toFixed(3);}
        if(returnWeight>purchasedWeight){returnWeight=purchasedWeight;weightInput.value=returnWeight.toFixed(3);}
        if(returnWeight<=0&&purchasedQty>0&&purchasedWeight>0&&returnQty>0){
            returnWeight=(purchasedWeight/purchasedQty)*returnQty;
            weightInput.value=returnWeight.toFixed(3);
        }

        const taxable=returnWeight*rate;
        const gst=(taxable*gstPercent)/100;
        row.querySelector('.return-taxable').value=taxable.toFixed(2);
        row.querySelector('.return-gst').value=gst.toFixed(2);
        row.querySelector('.return-total').value=(taxable+gst).toFixed(2);
        calculateSummary();
    }

    document.querySelectorAll('#returnTable tbody tr').forEach(function(row){
        row.querySelectorAll('.return-qty,.return-weight').forEach(function(input){
            input.addEventListener('input',()=>calculateRow(row));
        });
    });

    const form=document.getElementById('purchaseReturnForm');
    if(form){
        form.addEventListener('submit',async function(event){
            event.preventDefault();
            const button=document.getElementById('saveReturnButton');
            if(!button)return;
            const old=button.innerHTML;
            button.disabled=true;
            button.innerHTML='<i class="fa-solid fa-spinner fa-spin me-1"></i>Saving...';

            try{
                const response=await fetch('api/purchase-return-save.php',{
                    method:'POST',
                    body:new FormData(form),
                    credentials:'same-origin',
                    headers:{'X-Requested-With':'XMLHttpRequest'}
                });
                const result=await response.json().catch(()=>({success:false,message:'Invalid response received from the server.'}));
                if(!response.ok||!result.success)throw new Error(result.message||'Unable to save purchase return.');
                toast('success',result.message);
                setTimeout(()=>location.href='purchase-return.php?purchase_id='+encodeURIComponent(result.purchase_id)+'&msg=created',600);
            }catch(error){
                toast('error',error.message);
            }finally{
                button.disabled=false;
                button.innerHTML=old;
            }
        });
    }

    calculateSummary();

    const params=new URLSearchParams(location.search);
    if(params.get('msg')==='created')toast('success','Purchase return created successfully.');
})();
</script>
</body>
</html>
