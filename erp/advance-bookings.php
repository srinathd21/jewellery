<?php
if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));
foreach ([__DIR__.'/config/config.php',__DIR__.'/config.php',__DIR__.'/includes/config.php',__DIR__.'/super-admin/includes/config.php'] as $f) { if (is_file($f)) { require_once $f; break; } }
if (!isset($conn) || !($conn instanceof mysqli)) die('Database configuration is not available.');
$conn->set_charset('utf8mb4');
if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }

function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function abTableExists(mysqli $c, string $t): bool { $t=$c->real_escape_string($t); $r=$c->query("SHOW TABLES LIKE '{$t}'"); return $r && $r->num_rows>0; }
function abEnsureTables(mysqli $c): void {
    if (!abTableExists($c,'advance_bookings')) {
        $sql="CREATE TABLE advance_bookings (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            business_id INT NOT NULL,
            branch_id INT NOT NULL,
            booking_no VARCHAR(100) NOT NULL,
            booking_date DATE NOT NULL,
            booking_time TIME NULL,
            customer_id INT NOT NULL,
            metal_id INT NULL,
            purity VARCHAR(50) NULL,
            product_id INT NULL,
            product_name VARCHAR(255) NOT NULL,
            booking_rate_per_gram DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            advance_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            booked_grams DECIMAL(14,6) NOT NULL DEFAULT 0.000000,
            expected_purchase_date DATE NULL,
            payment_method_id INT NULL,
            payment_reference VARCHAR(255) NULL,
            status ENUM('Active','Partially Used','Completed','Cancelled','Refunded') NOT NULL DEFAULT 'Active',
            used_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            used_grams DECIMAL(14,6) NOT NULL DEFAULT 0.000000,
            balance_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            balance_grams DECIMAL(14,6) NOT NULL DEFAULT 0.000000,
            notes TEXT NULL,
            created_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_by INT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            UNIQUE KEY uq_advance_booking_no (business_id,branch_id,booking_no),
            KEY idx_advance_customer (business_id,customer_id),
            KEY idx_advance_status (business_id,branch_id,status),
            KEY idx_advance_date (business_id,branch_id,booking_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        if (!$c->query($sql)) die('Unable to create advance_bookings table: '.$c->error);
    }
    if (!abTableExists($c,'advance_booking_usage')) {
        $sql="CREATE TABLE advance_booking_usage (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            business_id INT NOT NULL,
            branch_id INT NOT NULL,
            advance_booking_id BIGINT UNSIGNED NOT NULL,
            sale_id INT NULL,
            invoice_no VARCHAR(100) NULL,
            used_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            used_grams DECIMAL(14,6) NOT NULL DEFAULT 0.000000,
            used_rate_per_gram DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            usage_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            KEY idx_booking_usage (advance_booking_id),
            KEY idx_booking_sale (sale_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        if (!$c->query($sql)) die('Unable to create advance_booking_usage table: '.$c->error);
    }
}
function abPageUrl(array $replace=[]): string { $q=array_merge($_GET,$replace); foreach($q as $k=>$v){ if($v===''||$v===null) unset($q[$k]); } return 'advance-bookings.php'.($q?'?'.http_build_query($q):''); }

$businessId=(int)($_SESSION['business_id']??0);
$branchId=(int)($_GET['branch_id']??($_SESSION['branch_id']??($_SESSION['default_branch_id']??0)));
if($businessId<=0||$branchId<=0) die('A valid business and branch must be selected.');
abEnsureTables($conn);

$search=trim((string)($_GET['search']??''));
$status=trim((string)($_GET['status']??'all'));
$dateFrom=trim((string)($_GET['date_from']??''));
$dateTo=trim((string)($_GET['date_to']??''));
$perPage=(int)($_GET['per_page']??10); if(!in_array($perPage,[10,25,50,100],true)) $perPage=10;
$page=max(1,(int)($_GET['page']??1));

$where=' WHERE ab.business_id=? AND ab.branch_id=?';
$types='ii'; $params=[$businessId,$branchId];
if($search!==''){ $where.=' AND (ab.booking_no LIKE ? OR ab.product_name LIKE ? OR c.customer_name LIKE ? OR c.mobile LIKE ?)'; $like='%'.$search.'%'; array_push($params,$like,$like,$like,$like); $types.='ssss'; }
$validStatuses=['Active','Partially Used','Completed','Cancelled','Refunded'];
if(in_array($status,$validStatuses,true)){ $where.=' AND ab.status=?'; $params[]=$status; $types.='s'; }
if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$dateFrom)){ $where.=' AND ab.booking_date>=?'; $params[]=$dateFrom; $types.='s'; }
if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$dateTo)){ $where.=' AND ab.booking_date<=?'; $params[]=$dateTo; $types.='s'; }

$bind=function(mysqli_stmt $stmt,string $types,array &$params){ if($types==='')return; $a=[$types]; foreach($params as $k=>$v)$a[]=&$params[$k]; call_user_func_array([$stmt,'bind_param'],$a); };
$countSql='SELECT COUNT(*) total FROM advance_bookings ab LEFT JOIN customers c ON c.id=ab.customer_id AND c.business_id=ab.business_id'.$where;
$stmt=$conn->prepare($countSql); $bind($stmt,$types,$params); $stmt->execute(); $total=(int)($stmt->get_result()->fetch_assoc()['total']??0); $stmt->close();
$totalPages=max(1,(int)ceil($total/$perPage)); if($page>$totalPages)$page=$totalPages; $offset=($page-1)*$perPage;
$listParams=$params; $listTypes=$types.'ii'; $listParams[]=$perPage; $listParams[]=$offset;
$listSql='SELECT ab.*,c.customer_name,c.mobile,m.metal_name,pm.method_name FROM advance_bookings ab LEFT JOIN customers c ON c.id=ab.customer_id AND c.business_id=ab.business_id LEFT JOIN metals m ON m.id=ab.metal_id AND m.business_id=ab.business_id LEFT JOIN payment_methods pm ON pm.id=ab.payment_method_id AND pm.business_id=ab.business_id'.$where.' ORDER BY ab.id DESC LIMIT ? OFFSET ?';
$stmt=$conn->prepare($listSql); if(!$stmt) die('Unable to load advance bookings: '.$conn->error); $bind($stmt,$listTypes,$listParams); $stmt->execute(); $r=$stmt->get_result(); $rows=[]; while($x=$r->fetch_assoc())$rows[]=$x; $stmt->close();

$stmt=$conn->prepare("SELECT COUNT(*) total_count,
SUM(CASE WHEN status='Active' THEN 1 ELSE 0 END) active_count,
SUM(CASE WHEN status='Partially Used' THEN 1 ELSE 0 END) partial_count,
SUM(CASE WHEN status='Completed' THEN 1 ELSE 0 END) completed_count,
COALESCE(SUM(CASE WHEN status NOT IN('Cancelled','Refunded') THEN advance_amount ELSE 0 END),0) total_advance,
COALESCE(SUM(CASE WHEN status NOT IN('Cancelled','Refunded') THEN booked_grams ELSE 0 END),0) total_grams,
COALESCE(SUM(CASE WHEN status IN('Active','Partially Used') THEN balance_amount ELSE 0 END),0) balance_amount,
COALESCE(SUM(CASE WHEN status IN('Active','Partially Used') THEN balance_grams ELSE 0 END),0) balance_grams
FROM advance_bookings WHERE business_id=? AND branch_id=?");
$stmt->bind_param('ii',$businessId,$branchId); $stmt->execute(); $stats=$stmt->get_result()->fetch_assoc()?:[]; $stmt->close();

$theme=['primary_color'=>'#d89416','primary_dark_color'=>'#b86a0b','primary_soft_color'=>'#fff6e5','page_background'=>'#f4f3f0','card_background'=>'#fff','text_color'=>'#171717','muted_text_color'=>'#7d8794','border_color'=>'#e8e8e8','font_family'=>'Inter','heading_font_family'=>'Playfair Display','border_radius_px'=>12];
$stmt=$conn->prepare('SELECT * FROM business_theme_settings WHERE business_id=? LIMIT 1'); if($stmt){$stmt->bind_param('i',$businessId);$stmt->execute();$x=$stmt->get_result()->fetch_assoc()?:[];$stmt->close();foreach($theme as $k=>$v)if(isset($x[$k])&&$x[$k]!=='')$theme[$k]=$x[$k];}
$businessName=(string)($_SESSION['business_name']??'Jewellery ERP');
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=h($businessName)?> - Advance Bookings</title><?php include('includes/links.php'); ?>
<style>
:root{--primary:<?=h($theme['primary_color'])?>;--primary-dark:<?=h($theme['primary_dark_color'])?>;--primary-soft:<?=h($theme['primary_soft_color'])?>;--page-bg:<?=h($theme['page_background'])?>;--card-bg:<?=h($theme['card_background'])?>;--text:<?=h($theme['text_color'])?>;--muted:<?=h($theme['muted_text_color'])?>;--line:<?=h($theme['border_color'])?>;--radius:<?=(int)$theme['border_radius_px']?>px}
body{background:var(--page-bg);color:var(--text);font-family:<?=json_encode($theme['font_family'])?>,sans-serif}.page-title{font:700 21px <?=json_encode($theme['heading_font_family'])?>,serif}.stat-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:9px;margin-bottom:10px}.stat-card,.filter-card,.table-card{background:var(--card-bg);border:1px solid var(--line);border-radius:var(--radius)}.stat-card{padding:13px}.stat-label{font-size:10px;color:var(--muted);font-weight:700;text-transform:uppercase}.stat-value{font-size:20px;font-weight:800;margin-top:4px}.stat-sub{font-size:9px;color:var(--muted);margin-top:2px}.filter-card{padding:12px;margin-bottom:10px}.filter-grid{display:grid;grid-template-columns:2fr 1fr 1fr 1fr .7fr;gap:8px}.field-label{font-size:10px;font-weight:700;margin-bottom:4px}.form-control,.form-select{font-size:11px;min-height:36px;border-color:var(--line);border-radius:8px;background:var(--card-bg);color:var(--text)}.btn-theme{background:linear-gradient(135deg,var(--primary),var(--primary-dark));border:0;color:#fff;font-size:11px;font-weight:700;border-radius:8px;padding:8px 12px}.btn-soft{background:var(--primary-soft);color:var(--primary-dark);border:1px solid var(--line);font-size:11px;font-weight:700;border-radius:8px;padding:8px 12px}.table-card{overflow:hidden}.table{font-size:10px;margin:0}.table th{font-size:9px;text-transform:uppercase;color:var(--muted);background:color-mix(in srgb,var(--muted) 6%,transparent);white-space:nowrap}.table td,.table th{padding:9px;border-color:var(--line);vertical-align:middle}.small-muted{font-size:9px;color:var(--muted)}.status-badge{display:inline-flex;padding:4px 8px;border-radius:999px;font-size:9px;font-weight:800}.st-active{background:#eaf8f0;color:#168449}.st-partially-used{background:#fff3cd;color:#8a5a00}.st-completed{background:#e7f1ff;color:#0b5ed7}.st-cancelled,.st-refunded{background:#f8d7da;color:#b02a37}.action-btn{width:28px;height:28px;display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--line);border-radius:7px;color:var(--text);text-decoration:none;background:var(--card-bg)}.pagination-wrap{display:flex;justify-content:space-between;align-items:center;padding:10px 12px;border-top:1px solid var(--line);font-size:10px;color:var(--muted)}@media(max-width:1100px){.stat-grid{grid-template-columns:repeat(2,1fr)}.filter-grid{grid-template-columns:repeat(2,1fr)}}@media(max-width:650px){.stat-grid,.filter-grid{grid-template-columns:1fr}}
</style></head>
<body><?php include('includes/sidebar.php'); ?><main class="app-main"><?php include('includes/nav.php'); ?><div class="content-wrap">
<div class="d-flex justify-content-between align-items-center gap-2 mb-2"><div><div class="page-title">Advance Bookings</div><div class="small text-muted">Gold rate lock bookings collected before the purchase day.</div></div><a href="advance-booking-form.php" class="btn btn-theme"><i class="fa-solid fa-plus me-2"></i>New Booking</a></div>
<div class="stat-grid">
<div class="stat-card"><div class="stat-label">Total Bookings</div><div class="stat-value"><?=number_format((int)($stats['total_count']??0))?></div><div class="stat-sub">All booking records</div></div>
<div class="stat-card"><div class="stat-label">Active / Partial</div><div class="stat-value"><?=number_format((int)($stats['active_count']??0)+(int)($stats['partial_count']??0))?></div><div class="stat-sub">Open customer commitments</div></div>
<div class="stat-card"><div class="stat-label">Advance Collected</div><div class="stat-value">₹<?=number_format((float)($stats['total_advance']??0),2)?></div><div class="stat-sub">Booked <?=number_format((float)($stats['total_grams']??0),6)?> g</div></div>
<div class="stat-card"><div class="stat-label">Open Balance</div><div class="stat-value">₹<?=number_format((float)($stats['balance_amount']??0),2)?></div><div class="stat-sub"><?=number_format((float)($stats['balance_grams']??0),6)?> g remaining</div></div>
</div>
<form class="filter-card" method="get"><div class="filter-grid"><div><label class="field-label">Search</label><input class="form-control" name="search" value="<?=h($search)?>" placeholder="Booking no, customer, product or mobile"></div><div><label class="field-label">Status</label><select class="form-select" name="status"><option value="all">All Status</option><?php foreach($validStatuses as $s):?><option value="<?=h($s)?>" <?=$status===$s?'selected':''?>><?=h($s)?></option><?php endforeach?></select></div><div><label class="field-label">From Date</label><input type="date" class="form-control" name="date_from" value="<?=h($dateFrom)?>"></div><div><label class="field-label">To Date</label><input type="date" class="form-control" name="date_to" value="<?=h($dateTo)?>"></div><div><label class="field-label">Rows</label><select class="form-select" name="per_page"><?php foreach([10,25,50,100] as $n):?><option value="<?=$n?>" <?=$perPage===$n?'selected':''?>><?=$n?></option><?php endforeach?></select></div></div><div class="d-flex justify-content-end gap-2 mt-2"><a class="btn btn-soft" href="advance-bookings.php">Reset</a><button class="btn btn-theme"><i class="fa-solid fa-filter me-1"></i>Apply</button></div></form>
<div class="table-card"><div class="table-responsive"><table class="table align-middle"><thead><tr><th>#</th><th>Booking No</th><th>Date</th><th>Customer</th><th>Product</th><th>Metal / Purity</th><th>Rate / g</th><th>Advance</th><th>Booked Grams</th><th>Balance</th><th>Expected Date</th><th>Status</th><th>Action</th></tr></thead><tbody>
<?php if($rows): foreach($rows as $i=>$row): $cls='st-'.strtolower(str_replace(' ','-', (string)$row['status'])); ?>
<tr><td><?=$offset+$i+1?></td><td><strong><?=h($row['booking_no'])?></strong><div class="small-muted"><?=!empty($row['booking_time'])?date('h:i A',strtotime($row['booking_time'])):''?></div></td><td><?=date('d-m-Y',strtotime($row['booking_date']))?></td><td><div><?=h($row['customer_name']?:'Customer')?></div><div class="small-muted"><?=h($row['mobile']?:'')?></div></td><td><?=h($row['product_name'])?></td><td><?=h($row['metal_name']?:'—')?><div class="small-muted"><?=h($row['purity']?:'—')?></div></td><td>₹<?=number_format((float)$row['booking_rate_per_gram'],2)?></td><td>₹<?=number_format((float)$row['advance_amount'],2)?></td><td><?=number_format((float)$row['booked_grams'],6)?> g</td><td><div>₹<?=number_format((float)$row['balance_amount'],2)?></div><div class="small-muted"><?=number_format((float)$row['balance_grams'],6)?> g</div></td><td><?=!empty($row['expected_purchase_date'])?date('d-m-Y',strtotime($row['expected_purchase_date'])):'—'?></td><td><span class="status-badge <?=$cls?>"><?=h($row['status'])?></span></td><td><div class="d-flex gap-1"><a class="action-btn" href="advance-booking-view.php?id=<?=(int)$row['id']?>" title="View"><i class="fa-solid fa-eye"></i></a><?php if(in_array($row['status'],['Active','Partially Used'],true)):?><a class="action-btn" href="advance-booking-form.php?id=<?=(int)$row['id']?>" title="Edit"><i class="fa-solid fa-pen"></i></a><?php endif?></div></td></tr>
<?php endforeach; else:?><tr><td colspan="13" class="text-center py-5 text-muted">No advance bookings found.</td></tr><?php endif?>
</tbody></table></div><div class="pagination-wrap"><div>Showing <?=$total?($offset+1):0?> to <?=$total?min($offset+$perPage,$total):0?> of <?=$total?> bookings</div><?php if($totalPages>1):?><nav><ul class="pagination pagination-sm mb-0"><?php for($pg=max(1,$page-2);$pg<=min($totalPages,$page+2);$pg++):?><li class="page-item <?=$pg===$page?'active':''?>"><a class="page-link" href="<?=h(abPageUrl(['page'=>$pg]))?>"><?=$pg?></a></li><?php endfor?></ul></nav><?php endif?></div></div>
<?php include('includes/footer.php'); ?></div></main><?php include('includes/script.php'); ?><script src="assets/js/script.js"></script></body></html>
