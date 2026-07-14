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
$conn->set_charset('utf8mb4');

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

function purchasePermission(mysqli $conn, string $action): bool
{
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin') {
        return true;
    }
    $map = [
        'open' => 'can_open', 'view' => 'can_view', 'value' => 'can_view_value',
        'create' => 'can_create', 'update' => 'can_update', 'approve' => 'can_approve', 'delete' => 'can_delete',
    ];
    $field = $map[$action] ?? '';
    if ($field === '') return false;

    $permissions = $_SESSION['permissions'] ?? [];
    foreach (['perm.purchases.list', 'perm.purchases'] as $key) {
        if (isset($permissions[$key][$field])) {
            return (int)$permissions[$key][$field] === 1;
        }
    }

    $businessId = (int)($_SESSION['business_id'] ?? 0);
    $roleId = (int)($_SESSION['role_id'] ?? 0);
    if ($businessId <= 0 || $roleId <= 0) return false;

    $sql = "SELECT rp.`{$field}`
            FROM role_permissions rp
            INNER JOIN permissions p ON p.id = rp.permission_id
            WHERE rp.business_id = ? AND rp.role_id = ? AND p.is_active = 1
              AND p.permission_code IN ('perm.purchases.list','perm.purchases')
            ORDER BY FIELD(p.permission_code,'perm.purchases.list','perm.purchases')
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;
    $stmt->bind_param('ii', $businessId, $roleId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row[$field] ?? 0) === 1;
}

if (!purchasePermission($conn, 'open')) {
    http_response_code(403);
    die('Access denied. You do not have permission to open purchases.');
}

$canView = purchasePermission($conn, 'view') || purchasePermission($conn, 'open');
$canViewValue = purchasePermission($conn, 'value') || $canView;
$canCreate = purchasePermission($conn, 'create');
$canUpdate = purchasePermission($conn, 'update');
$canDelete = purchasePermission($conn, 'delete');
$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? 0);
if ($businessId <= 0 || $branchId <= 0) {
    http_response_code(403);
    die('A valid business and branch must be selected.');
}

if (empty($_SESSION['purchases_csrf'])) {
    $_SESSION['purchases_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['purchases_csrf'];

$stats = ['total' => 0, 'paid' => 0, 'partial' => 0, 'unpaid' => 0, 'amount' => 0.0];
$stmt = $conn->prepare("SELECT COUNT(*) total_count,
    SUM(payment_status='Paid') paid_count,
    SUM(payment_status='Partial') partial_count,
    SUM(payment_status='Unpaid') unpaid_count,
    COALESCE(SUM(grand_total),0) total_amount
    FROM purchases WHERE business_id = ? AND branch_id = ?");
if (!$stmt) die('Unable to prepare purchase statistics: ' . e($conn->error));
$stmt->bind_param('ii', $businessId, $branchId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();
$stats = [
    'total' => (int)($row['total_count'] ?? 0),
    'paid' => (int)($row['paid_count'] ?? 0),
    'partial' => (int)($row['partial_count'] ?? 0),
    'unpaid' => (int)($row['unpaid_count'] ?? 0),
    'amount' => (float)($row['total_amount'] ?? 0),
];

$suppliers = [];
$stmt = $conn->prepare("SELECT id, supplier_code, supplier_name FROM suppliers WHERE business_id = ? AND is_active = 1 ORDER BY supplier_name");
if (!$stmt) die('Unable to prepare suppliers: ' . e($conn->error));
$stmt->bind_param('i', $businessId);
$stmt->execute();
$result = $stmt->get_result();
while ($r = $result->fetch_assoc()) $suppliers[] = $r;
$stmt->close();

$purchases = [];
$stmt = $conn->prepare("SELECT p.id, p.supplier_id, p.purchase_no, p.supplier_invoice_no, p.purchase_date,
        p.grand_total, p.paid_amount, p.balance_amount, p.payment_status, p.workflow_status,
        p.created_at, s.supplier_name, s.supplier_code, s.mobile AS supplier_mobile,
        COUNT(pi.id) AS item_count, COALESCE(SUM(pi.quantity),0) AS total_quantity,
        COALESCE(SUM(pi.net_weight),0) AS total_weight
    FROM purchases p
    INNER JOIN suppliers s ON s.id = p.supplier_id AND s.business_id = p.business_id
    LEFT JOIN purchase_items pi ON pi.purchase_id = p.id AND pi.business_id = p.business_id AND pi.branch_id = p.branch_id
    WHERE p.business_id = ? AND p.branch_id = ?
    GROUP BY p.id, p.supplier_id, p.purchase_no, p.supplier_invoice_no, p.purchase_date, p.grand_total,
             p.paid_amount, p.balance_amount, p.payment_status, p.workflow_status, p.created_at,
             s.supplier_name, s.supplier_code, s.mobile
    ORDER BY p.purchase_date DESC, p.id DESC");
if (!$stmt) die('Unable to prepare purchases: ' . e($conn->error));
$stmt->bind_param('ii', $businessId, $branchId);
$stmt->execute();
$result = $stmt->get_result();
while ($r = $result->fetch_assoc()) $purchases[] = $r;
$stmt->close();

$theme = [
    'primary_color'=>'#d89416','primary_dark_color'=>'#b86a0b','primary_soft_color'=>'#fff6e5',
    'sidebar_gradient_1'=>'#171c21','sidebar_gradient_2'=>'#20272d','sidebar_gradient_3'=>'#101419',
    'page_background'=>'#f4f3f0','card_background'=>'#ffffff','text_color'=>'#171717',
    'muted_text_color'=>'#7d8794','border_color'=>'#e8e8e8','font_family'=>'Inter',
    'heading_font_family'=>'Playfair Display','border_radius_px'=>12,'sidebar_width_px'=>230,
];
$stmt = $conn->prepare('SELECT * FROM business_theme_settings WHERE business_id = ? LIMIT 1');
if ($stmt) {
    $stmt->bind_param('i', $businessId); $stmt->execute();
    $themeRow = $stmt->get_result()->fetch_assoc() ?: []; $stmt->close();
    foreach ($theme as $key => $default) if (isset($themeRow[$key]) && $themeRow[$key] !== '') $theme[$key] = $themeRow[$key];
}
$pageTitle = 'Purchases';
$businessName = (string)($_SESSION['business_name'] ?? 'Jewellery ERP');
$currency = (string)($_SESSION['currency_symbol'] ?? '₹');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo e($businessName); ?> - Purchases</title>
    <?php include('includes/links.php'); ?>
    <style>
        :root{--primary:<?php echo e($theme['primary_color']); ?>;--primary-dark:<?php echo e($theme['primary_dark_color']); ?>;--primary-soft:<?php echo e($theme['primary_soft_color']); ?>;--sidebar-gradient-1:<?php echo e($theme['sidebar_gradient_1']); ?>;--sidebar-gradient-2:<?php echo e($theme['sidebar_gradient_2']); ?>;--sidebar-gradient-3:<?php echo e($theme['sidebar_gradient_3']); ?>;--page-bg:<?php echo e($theme['page_background']); ?>;--card-bg:<?php echo e($theme['card_background']); ?>;--text-color:<?php echo e($theme['text_color']); ?>;--muted-color:<?php echo e($theme['muted_text_color']); ?>;--border-color:<?php echo e($theme['border_color']); ?>;--sidebar-width:<?php echo (int)$theme['sidebar_width_px']; ?>px;--radius:<?php echo (int)$theme['border_radius_px']; ?>px}
        body{background:var(--page-bg);color:var(--text-color);font-family:<?php echo json_encode((string)$theme['font_family']); ?>,sans-serif}.sidebar{background:linear-gradient(180deg,var(--sidebar-gradient-1),var(--sidebar-gradient-2),var(--sidebar-gradient-3))!important}
        .stat-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px;margin-bottom:10px}.stat-card{background:var(--card-bg);border:1px solid var(--border-color);border-radius:var(--radius);padding:12px 14px;min-height:82px;display:flex;align-items:center;gap:12px}.stat-icon{width:42px;height:42px;flex:0 0 42px;display:flex;align-items:center;justify-content:center;border-radius:calc(var(--radius)*.75);background:var(--primary-soft);color:var(--primary-dark);font-size:16px}.stat-label{font-size:10px;color:var(--muted-color)}.stat-value{font-size:21px;line-height:1.1;font-weight:800;margin-top:4px}
        .toolbar{display:flex;align-items:center;justify-content:space-between;gap:10px;background:var(--card-bg);border:1px solid var(--border-color);border-radius:var(--radius);padding:10px 12px;margin-bottom:10px}
        .toolbar-left{display:flex;align-items:center;gap:8px;flex-wrap:wrap;min-width:0}
        .search-box{position:relative;min-width:250px;flex:1 1 290px}
        .search-box .search-leading-icon{position:absolute;z-index:3;left:6px;top:50%;width:28px;height:28px;display:grid;place-items:center;transform:translateY(-50%);border-radius:8px;background:var(--primary-soft);color:var(--primary-dark);font-size:10px;pointer-events:none}
        .search-box input{width:100%;padding-left:42px!important;padding-right:14px!important;border-radius:12px}
        
        
        
        .search-box:focus-within .search-leading-icon{background:var(--primary);color:#fff}
        .form-control,.form-select{font-size:11px;min-height:36px;border-radius:9px;border-color:var(--border-color);background:var(--card-bg);color:var(--text-color)}
        .btn-theme{background:linear-gradient(135deg,var(--primary),var(--primary-dark));border:0;color:#fff;border-radius:calc(var(--radius)*.65);font-size:11px;font-weight:700;padding:9px 14px}
        .table-card{background:var(--card-bg);border:1px solid var(--border-color);border-radius:var(--radius);overflow:hidden}.purchase-table{margin:0;font-size:11px}.purchase-table th{font-size:9px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted-color);background:color-mix(in srgb,var(--muted-color) 6%,transparent);white-space:nowrap;padding:10px 11px;border-color:var(--border-color)}.purchase-table td{padding:10px 11px;vertical-align:middle;color:var(--text-color);background:var(--card-bg)!important;border-color:var(--border-color)}.main-text{font-size:11px;font-weight:800}.sub-text{font-size:9px;color:var(--muted-color);margin-top:2px}.badge-soft{display:inline-flex;align-items:center;border-radius:999px;padding:4px 8px;font-size:9px;font-weight:700}.paid{background:#eaf8f0;color:#168449}.partial{background:#fff4d9;color:#a86c00}.unpaid{background:#fdecec;color:#bd2d2d}.draft{background:#eef2f7;color:#55606d}.posted{background:#eaf8f0;color:#168449}.cancelled{background:#fdecec;color:#bd2d2d}.approval{background:#eff6ff;color:#1d4ed8}.action-btn{width:30px;height:30px;border:1px solid var(--border-color);border-radius:8px;background:var(--card-bg);display:inline-flex;align-items:center;justify-content:center;font-size:10px;color:var(--text-color)}.action-btn:hover{background:var(--primary-soft);color:var(--primary-dark)}.action-btn.danger:hover{background:#fdecec;color:#bd2d2d}.theme-toast{position:fixed;right:18px;top:78px;z-index:20000;display:flex;align-items:center;gap:9px;min-width:260px;max-width:420px;padding:11px 14px;border-radius:10px;color:#fff;font-size:11px;font-weight:600;box-shadow:0 14px 35px rgba(0,0,0,.22);opacity:0;transform:translateY(-10px);transition:.22s}.theme-toast.show{opacity:1;transform:translateY(0)}.theme-toast-success{background:#168449}.theme-toast-error{background:#c0392b}.empty-state{padding:50px 20px;text-align:center;color:var(--muted-color)}
        body.dark-mode,body[data-theme="dark"],html.dark-mode body,html[data-theme="dark"] body{--page-bg:#0f151b;--card-bg:#182129;--text-color:#f3f6f8;--muted-color:#9aa7b3;--border-color:#2c3944}
        @media(max-width:1199px){.stat-grid{grid-template-columns:repeat(3,1fr)}}
        @media(max-width:991.98px){.stat-grid{grid-template-columns:repeat(2,1fr)}.toolbar{align-items:stretch;flex-direction:column}.toolbar-left{display:grid;grid-template-columns:minmax(220px,1fr) 160px 160px 145px 145px;width:100%}.search-box{min-width:0;width:100%}.table-card{background:transparent;border:0;overflow:visible}.table-responsive{overflow:visible}.purchase-table{display:block}.purchase-table thead{display:none}.purchase-table tbody{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.purchase-table tbody tr{display:grid;grid-template-columns:1fr 1fr;background:var(--card-bg);border:1px solid var(--border-color);border-radius:var(--radius);padding:14px}.purchase-table tbody td{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:9px 0;border:0;border-bottom:1px dashed var(--border-color);text-align:right!important}.purchase-table tbody td::before{content:attr(data-label);font-size:9px;font-weight:700;text-transform:uppercase;color:var(--muted-color);text-align:left}.purchase-table tbody td.purchase-column{grid-column:1/-1;display:block;text-align:left!important}.purchase-table tbody td.purchase-column::before{display:none}.purchase-table tbody td.actions-column{grid-column:1/-1;border-bottom:0;padding-top:12px}}
        @media(max-width:1100px) and (min-width:768px){.toolbar-left{grid-template-columns:minmax(220px,1fr) 160px 160px}.toolbar-left #fromDate,.toolbar-left #toDate{width:100%!important}}
        @media(max-width:767.98px){.stat-grid{grid-template-columns:1fr 1fr}.toolbar-left{grid-template-columns:1fr}.toolbar-left .form-select,.toolbar-left .form-control{width:100%!important}.search-box{width:100%;min-width:0}.purchase-table tbody{grid-template-columns:1fr}.purchase-table tbody tr{grid-template-columns:1fr}.purchase-table tbody td{grid-column:1/-1}.theme-toast{left:12px;right:12px;top:70px;min-width:0;max-width:none}}
    </style>
</head>
<body>
<?php include('includes/sidebar.php'); ?>
<main class="app-main">
<?php include('includes/nav.php'); ?>
<div class="content-wrap">
<?php if (!$canView): ?>
<div class="table-card"><div class="empty-state"><i class="fa-solid fa-lock fa-2x mb-2"></i><div>You do not have permission to view purchases.</div></div></div>
<?php else: ?>
<div class="stat-grid">
<div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-cart-flatbed"></i></div><div><div class="stat-label">Total Purchases</div><div class="stat-value"><?php echo $stats['total']; ?></div></div></div>
<div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-circle-check"></i></div><div><div class="stat-label">Paid</div><div class="stat-value"><?php echo $stats['paid']; ?></div></div></div>
<div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-circle-half-stroke"></i></div><div><div class="stat-label">Partial</div><div class="stat-value"><?php echo $stats['partial']; ?></div></div></div>
<div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-clock"></i></div><div><div class="stat-label">Unpaid</div><div class="stat-value"><?php echo $stats['unpaid']; ?></div></div></div>
<div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-indian-rupee-sign"></i></div><div><div class="stat-label">Purchase Value</div><div class="stat-value"><?php echo $canViewValue ? e($currency) . ' ' . number_format($stats['amount'],2) : '••••'; ?></div></div></div>
</div>
<div class="toolbar">
<div class="toolbar-left">
<div class="search-box">
<i class="fa-solid fa-magnifying-glass search-leading-icon"></i>
<input type="search" class="form-control" id="purchaseSearch" placeholder="Search purchase, invoice, supplier..." autocomplete="off">

</div>
<select class="form-select" id="supplierFilter" style="width:180px"><option value="">All suppliers</option><?php foreach($suppliers as $s): ?><option value="<?php echo (int)$s['id']; ?>"><?php echo e($s['supplier_name']); ?></option><?php endforeach; ?></select>
<select class="form-select" id="statusFilter" style="width:150px"><option value="">All payments</option><option>Paid</option><option>Partial</option><option>Unpaid</option></select>
<input type="date" class="form-control" id="fromDate" style="width:145px"><input type="date" class="form-control" id="toDate" style="width:145px">
</div>
<?php if ($canCreate): ?><a href="purchase-add.php" class="btn btn-theme btn-sm"><i class="fa-solid fa-plus me-2"></i>Add Purchase</a><?php endif; ?>
</div>
<div class="table-card"><div class="table-responsive"><table class="table purchase-table align-middle" id="purchasesTable"><thead><tr><th>Purchase</th><th>Date</th><th>Supplier</th><th>Items</th><th>Invoice</th><th>Grand Total</th><th>Paid</th><th>Balance</th><th>Payment</th><th>Workflow</th><th class="text-end">Actions</th></tr></thead><tbody>
<?php foreach($purchases as $p):
$paymentClass = strtolower((string)$p['payment_status']);
$workflow = (string)$p['workflow_status'];
$workflowClass = $workflow === 'Posted' ? 'posted' : ($workflow === 'Cancelled' ? 'cancelled' : ($workflow === 'Draft' ? 'draft' : 'approval'));
$searchText = strtolower(implode(' ', [$p['purchase_no'],$p['supplier_invoice_no'],$p['supplier_name'],$p['supplier_code'],$p['supplier_mobile']]));
?>
<tr data-search="<?php echo e($searchText); ?>" data-supplier="<?php echo (int)($p['supplier_id'] ?? 0); ?>" data-payment="<?php echo e($p['payment_status']); ?>" data-date="<?php echo e($p['purchase_date']); ?>">
<td class="purchase-column" data-label="Purchase"><div class="main-text"><?php echo e($p['purchase_no']); ?></div><div class="sub-text"><?php echo !empty($p['created_at']) ? e(date('d-m-Y h:i A',strtotime($p['created_at']))) : ''; ?></div></td>
<td data-label="Date"><?php echo e(date('d-m-Y',strtotime($p['purchase_date']))); ?></td>
<td data-label="Supplier"><div class="main-text"><?php echo e($p['supplier_name']); ?></div><div class="sub-text"><?php echo e(trim(($p['supplier_code'] ?? '') . ' ' . ($p['supplier_mobile'] ?? ''))); ?></div></td>
<td data-label="Items"><div><?php echo (int)$p['item_count']; ?> items</div><div class="sub-text"><?php echo number_format((float)$p['total_quantity'],3); ?> qty · <?php echo number_format((float)$p['total_weight'],3); ?> g</div></td>
<td data-label="Invoice"><?php echo e($p['supplier_invoice_no'] ?: '—'); ?></td>
<td data-label="Grand Total"><?php echo $canViewValue ? e($currency).' '.number_format((float)$p['grand_total'],2) : '••••'; ?></td>
<td data-label="Paid"><?php echo $canViewValue ? e($currency).' '.number_format((float)$p['paid_amount'],2) : '••••'; ?></td>
<td data-label="Balance"><?php echo $canViewValue ? e($currency).' '.number_format((float)$p['balance_amount'],2) : '••••'; ?></td>
<td data-label="Payment"><span class="badge-soft <?php echo e($paymentClass); ?>"><?php echo e($p['payment_status']); ?></span></td>
<td data-label="Workflow"><span class="badge-soft <?php echo e($workflowClass); ?>"><?php echo e($workflow); ?></span></td>
<td class="text-end actions-column" data-label="Actions"><div class="d-inline-flex gap-1">
<a class="action-btn" href="purchase-view.php?id=<?php echo (int)$p['id']; ?>" title="View"><i class="fa-solid fa-eye"></i></a>
<?php if($canUpdate): ?><a class="action-btn" href="purchase-edit.php?id=<?php echo (int)$p['id']; ?>" title="Edit"><i class="fa-solid fa-pen"></i></a><?php endif; ?>
<?php if($canDelete): ?><button class="action-btn danger delete-purchase" type="button" data-id="<?php echo (int)$p['id']; ?>" data-no="<?php echo e($p['purchase_no']); ?>" title="Delete"><i class="fa-solid fa-trash"></i></button><?php endif; ?>
</div></td></tr>
<?php endforeach; ?>
</tbody></table></div><?php if(!$purchases): ?><div class="empty-state"><i class="fa-regular fa-folder-open fa-2x mb-2"></i><div>No purchases found.</div></div><?php endif; ?></div>
<?php endif; ?>
<?php include('includes/footer.php'); ?>
</div></main>
<div class="modal fade" id="confirmDeleteModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Delete Purchase</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><p class="mb-1">Delete purchase <strong id="deletePurchaseNo"></strong>?</p><small class="text-muted">Posted purchase stock will be reversed. This action cannot be undone.</small></div><div class="modal-footer"><button class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button><button class="btn btn-danger btn-sm" id="confirmDeleteButton"><i class="fa-solid fa-trash me-2"></i>Delete</button></div></div></div></div>
<div class="theme-toast" id="themeToast"><i class="fa-solid fa-circle-info"></i><span></span></div>
<?php include('includes/script.php'); ?>
<script src="assets/js/script.js"></script>
<script>
(() => {
const rows=[...document.querySelectorAll('#purchasesTable tbody tr')];
const search=document.getElementById('purchaseSearch');
const supplier=document.getElementById('supplierFilter');
const status=document.getElementById('statusFilter');
const from=document.getElementById('fromDate');
const to=document.getElementById('toDate');

function filter(){
    const q=(search?.value||'').toLowerCase().trim();
    const sid=String(supplier?.value||'');
    const st=String(status?.value||'');
    const fd=String(from?.value||'');
    const td=String(to?.value||'');

    rows.forEach(row=>{
        const rowSearch=String(row.dataset.search||'').toLowerCase();
        const rowSupplier=String(row.dataset.supplier||'');
        const rowPayment=String(row.dataset.payment||'');
        const rowDate=String(row.dataset.date||'');

        const matchesSearch=!q||rowSearch.includes(q);
        const matchesSupplier=!sid||rowSupplier===sid;
        const matchesStatus=!st||rowPayment===st;
        const matchesFrom=!fd||rowDate>=fd;
        const matchesTo=!td||rowDate<=td;

        row.style.display=
            matchesSearch&&matchesSupplier&&matchesStatus&&matchesFrom&&matchesTo
                ? ''
                : 'none';
    });

}

search?.addEventListener('input',filter);
supplier?.addEventListener('change',filter);
status?.addEventListener('change',filter);
from?.addEventListener('change',filter);
to?.addEventListener('change',filter);


const toast=document.getElementById('themeToast'); function showToast(msg,ok){toast.className='theme-toast '+(ok?'theme-toast-success':'theme-toast-error');toast.querySelector('span').textContent=msg;toast.classList.add('show');setTimeout(()=>toast.classList.remove('show'),3200);}
let deleteId=0; const modalEl=document.getElementById('confirmDeleteModal'); const modal=modalEl?new bootstrap.Modal(modalEl):null;
document.querySelectorAll('.delete-purchase').forEach(btn=>btn.addEventListener('click',()=>{deleteId=Number(btn.dataset.id||0);document.getElementById('deletePurchaseNo').textContent=btn.dataset.no||'';modal?.show();}));
document.getElementById('confirmDeleteButton')?.addEventListener('click',async function(){if(!deleteId)return;this.disabled=true;const fd=new FormData();fd.append('action','delete');fd.append('purchase_id',String(deleteId));fd.append('csrf_token',<?php echo json_encode($csrfToken); ?>);try{const res=await fetch('api/purchases-save.php',{method:'POST',body:fd,credentials:'same-origin'});const data=await res.json();if(!res.ok||!data.success)throw new Error(data.message||'Unable to delete purchase.');modal?.hide();document.querySelector(`.delete-purchase[data-id="${deleteId}"]`)?.closest('tr')?.remove();showToast(data.message,true);}catch(err){showToast(err.message||'Unable to delete purchase.',false);}finally{this.disabled=false;deleteId=0;}});
})();
</script>
</body></html>
