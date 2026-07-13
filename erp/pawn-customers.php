<?php
require_once __DIR__ . '/includes/pawn_master_bootstrap.php';

$canView = pawn_permission($conn, 'view') || pawn_permission($conn, 'open');
$canCreate = pawn_permission($conn, 'create');
$canUpdate = pawn_permission($conn, 'update');
if (!$canView) { http_response_code(403); die('You do not have permission to view pawn customers.'); }

$search = trim((string)($_GET['search'] ?? ''));
$risk = in_array((string)($_GET['risk'] ?? ''), ['Low','Medium','High'], true) ? (string)$_GET['risk'] : '';
$kyc = in_array((string)($_GET['kyc'] ?? ''), ['verified','pending'], true) ? (string)$_GET['kyc'] : '';
$occupation = trim((string)($_GET['occupation'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;

$where = ['pc.business_id = ?'];
$types = 'i';
$params = [$businessId];
if ($search !== '') {
    $where[] = '(c.customer_name LIKE ? OR c.customer_code LIKE ? OR c.mobile LIKE ? OR c.email LIKE ?)';
    $like = "%{$search}%";
    $types .= 'ssss';
    array_push($params, $like, $like, $like, $like);
}
if ($risk !== '') { $where[] = 'pc.risk_category = ?'; $types .= 's'; $params[] = $risk; }
if ($kyc !== '') { $where[] = 'pc.kyc_verified = ?'; $types .= 'i'; $params[] = $kyc === 'verified' ? 1 : 0; }
if ($occupation !== '') { $where[] = 'pc.occupation = ?'; $types .= 's'; $params[] = $occupation; }
$whereSql = implode(' AND ', $where);

$countStmt = $conn->prepare("SELECT COUNT(*) total FROM pawn_customers pc INNER JOIN customers c ON c.id=pc.customer_id WHERE {$whereSql}");
$countStmt->bind_param($types, ...$params);
$countStmt->execute();
$totalRecords = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
$countStmt->close();
$totalPages = max(1, (int)ceil($totalRecords / $perPage));
if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

$sql = "SELECT pc.*, c.customer_code, c.customer_name, c.mobile, c.email, c.city,
        (SELECT COUNT(*) FROM pawn_entries pe WHERE pe.business_id=pc.business_id AND pe.customer_id=pc.customer_id AND pe.status IN ('Active','Partially Paid')) active_loans,
        (SELECT COALESCE(SUM(pe.balance_principal),0) FROM pawn_entries pe WHERE pe.business_id=pc.business_id AND pe.customer_id=pc.customer_id AND pe.status IN ('Active','Partially Paid')) outstanding
        FROM pawn_customers pc
        INNER JOIN customers c ON c.id=pc.customer_id
        WHERE {$whereSql}
        ORDER BY c.customer_name ASC
        LIMIT ? OFFSET ?";
$listTypes = $types . 'ii';
$listParams = [...$params, $perPage, $offset];
$stmt = $conn->prepare($sql);
$stmt->bind_param($listTypes, ...$listParams);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$summaryStmt = $conn->prepare("SELECT COUNT(*) total_customers,
 SUM(pc.kyc_verified=1) verified,
 SUM(pc.risk_category='High') high_risk,
 COALESCE(SUM(pc.credit_limit),0) credit_limit
 FROM pawn_customers pc WHERE pc.business_id=?");
$summaryStmt->bind_param('i', $businessId);
$summaryStmt->execute();
$summary = $summaryStmt->get_result()->fetch_assoc() ?: [];
$summaryStmt->close();

$occupations = [];
$occStmt = $conn->prepare("SELECT DISTINCT occupation FROM pawn_customers WHERE business_id=? AND occupation IS NOT NULL AND occupation<>'' ORDER BY occupation");
$occStmt->bind_param('i', $businessId);
$occStmt->execute();
$occResult = $occStmt->get_result();
while ($r = $occResult->fetch_assoc()) $occupations[] = $r['occupation'];
$occStmt->close();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=pawn_e($businessName)?> - Pawn Customers</title>
<?php include('includes/links.php'); ?>
<?php include('includes/pawn_master_styles.php'); ?>
</head>
<body>
<?php include('includes/sidebar.php'); ?>
<main class="app-main">
<?php include('includes/nav.php'); ?>
<div class="content-wrap">

<div class="page-head">
  <div><h1 class="page-title">Pawn Customers</h1><p class="page-subtitle">Customer KYC, risk and pawn exposure in one place.</p></div>
  <?php if($canCreate): ?><a href="pawn-customer-add.php" class="btn btn-primary-theme"><i class="fa-solid fa-user-plus me-2"></i>Add Customer</a><?php endif; ?>
</div>

<div class="stat-grid">
  <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-users"></i></div><div><div class="stat-label">Total Customers</div><div class="stat-value"><?=(int)($summary['total_customers']??0)?></div></div></div>
  <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-id-card"></i></div><div><div class="stat-label">KYC Verified</div><div class="stat-value"><?=(int)($summary['verified']??0)?></div></div></div>
  <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-triangle-exclamation"></i></div><div><div class="stat-label">High Risk</div><div class="stat-value"><?=(int)($summary['high_risk']??0)?></div></div></div>
  <div class="stat-card"><div class="stat-icon"><i class="fa-solid fa-indian-rupee-sign"></i></div><div><div class="stat-label">Total Credit Limit</div><div class="stat-value"><?=pawn_e($currencySymbol)?><?=pawn_money($summary['credit_limit']??0)?></div></div></div>
</div>

<section class="ui-card">
 <div class="ui-card-body">
  <form method="get" class="filter-row">
   <div class="filter-item"><label class="form-label">Search</label><input class="form-control" name="search" value="<?=pawn_e($search)?>" placeholder="Name, code, mobile or email"></div>
   <div class="filter-item"><label class="form-label">Risk</label><select class="form-select" name="risk"><option value="">All risks</option><?php foreach(['Low','Medium','High'] as $v): ?><option value="<?=$v?>" <?=$risk===$v?'selected':''?>><?=$v?></option><?php endforeach;?></select></div>
   <div class="filter-item"><label class="form-label">KYC</label><select class="form-select" name="kyc"><option value="">All KYC</option><option value="verified" <?=$kyc==='verified'?'selected':''?>>Verified</option><option value="pending" <?=$kyc==='pending'?'selected':''?>>Pending</option></select></div>
   <div class="filter-item"><label class="form-label">Occupation</label><select class="form-select" name="occupation"><option value="">All occupations</option><?php foreach($occupations as $v): ?><option value="<?=pawn_e($v)?>" <?=$occupation===$v?'selected':''?>><?=pawn_e($v)?></option><?php endforeach;?></select></div>
   <div class="filter-actions"><button class="btn btn-primary-theme"><i class="fa-solid fa-filter me-1"></i>Apply</button> <a class="btn btn-light" href="pawn-customers.php">Clear</a></div>
  </form>
 </div>
</section>

<section class="ui-card table-card">
 <div class="ui-card-head"><div class="ui-card-title">Customer List</div><div class="text-muted small"><?=$totalRecords?> record(s)</div></div>
 <div class="table-responsive">
  <table class="ui-table mobile-cards">
   <thead><tr><th>Customer</th><th>Contact</th><th>Occupation</th><th>Credit Limit</th><th>Active Loans</th><th>Outstanding</th><th>Risk</th><th>KYC</th><th>Actions</th></tr></thead>
   <tbody>
   <?php if(!$rows): ?><tr><td colspan="9"><div class="empty-state"><i class="fa-regular fa-folder-open fa-2x mb-2"></i><div>No pawn customers found.</div></div></td></tr>
   <?php else: foreach($rows as $row): ?>
    <tr>
      <td class="main-cell" data-label="Customer"><div class="d-flex align-items-center gap-2"><div class="avatar"><?=pawn_e(strtoupper(substr((string)$row['customer_name'],0,2)))?></div><div><strong><?=pawn_e($row['customer_name'])?></strong><div class="small text-muted"><?=pawn_e($row['customer_code'])?></div></div></div></td>
      <td data-label="Contact"><?=pawn_e($row['mobile'] ?: '-')?><div class="small text-muted"><?=pawn_e($row['email'] ?: '')?></div></td>
      <td data-label="Occupation"><?=pawn_e($row['occupation'] ?: '-')?></td>
      <td data-label="Credit Limit"><?=pawn_e($currencySymbol)?><?=pawn_money($row['credit_limit'])?></td>
      <td data-label="Active Loans"><span class="badge-soft badge-warning"><?=(int)$row['active_loans']?></span></td>
      <td data-label="Outstanding"><strong><?=pawn_e($currencySymbol)?><?=pawn_money($row['outstanding'])?></strong></td>
      <td data-label="Risk"><span class="badge-soft <?=$row['risk_category']==='High'?'badge-danger':($row['risk_category']==='Medium'?'badge-warning':'badge-success')?>"><?=pawn_e($row['risk_category'])?></span></td>
      <td data-label="KYC"><span class="badge-soft <?=$row['kyc_verified']?'badge-success':'badge-muted'?>"><?=$row['kyc_verified']?'Verified':'Pending'?></span></td>
      <td class="actions-cell" data-label="Actions"><div class="actions"><a class="btn btn-sm btn-outline-info" href="pawn-customer-view.php?id=<?=(int)$row['id']?>" title="View"><i class="fa-solid fa-eye"></i></a><?php if($canUpdate): ?><a class="btn btn-sm btn-outline-primary" href="pawn-customer-add.php?id=<?=(int)$row['id']?>" title="Edit"><i class="fa-solid fa-pen"></i></a><?php endif; ?><a class="btn btn-sm btn-outline-secondary" href="pawn-list.php?customer_id=<?=(int)$row['customer_id']?>" title="Loans"><i class="fa-solid fa-hand-holding-dollar"></i></a></div></td>
    </tr>
   <?php endforeach; endif; ?>
   </tbody>
  </table>
 </div>
 <?php if($totalPages>1): ?><div class="pagination-wrap"><div class="small text-muted">Page <?=$page?> of <?=$totalPages?></div><nav><ul class="pagination pagination-sm mb-0"><?php for($i=max(1,$page-2);$i<=min($totalPages,$page+2);$i++): $q=$_GET;$q['page']=$i; ?><li class="page-item <?=$i===$page?'active':''?>"><a class="page-link" href="?<?=pawn_e(http_build_query($q))?>"><?=$i?></a></li><?php endfor;?></ul></nav></div><?php endif; ?>
</section>
<?php include('includes/footer.php'); ?>
</div>
</main>
<?php include('includes/script.php'); ?>
<script src="assets/js/script.js"></script>
</body>
</html>
