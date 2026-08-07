<?php
session_start();
date_default_timezone_set($_SESSION['timezone'] ?? 'Asia/Kolkata');
foreach ([__DIR__.'/config/config.php',__DIR__.'/config.php',__DIR__.'/includes/config.php'] as $f) {
    if (is_file($f)) { require_once $f; break; }
}
if (!isset($conn) || !($conn instanceof mysqli)) die('Database connection unavailable.');
$conn->set_charset('utf8mb4');
if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }

function h($v){ return htmlspecialchars((string)($v??''),ENT_QUOTES,'UTF-8'); }
function money($v,$c){ return $c.' '.number_format((float)$v,2); }

$businessId=(int)($_SESSION['business_id']??0);
$branchId=(int)($_SESSION['branch_id']??0);
$currency=$_SESSION['currency_symbol']??'₹';
if($businessId<=0||$branchId<=0) die('Business or branch session missing.');

if(empty($_SESSION['exchange_csrf'])) $_SESSION['exchange_csrf']=bin2hex(random_bytes(32));
$csrf=$_SESSION['exchange_csrf'];

if($_SERVER['REQUEST_METHOD']==='POST'){
    header('Content-Type: application/json; charset=utf-8');
    if(!hash_equals($csrf,(string)($_POST['csrf_token']??''))){
        http_response_code(419);
        echo json_encode(['success'=>false,'message'=>'Session expired.']);
        exit;
    }

    $id=(int)($_POST['id']??0);
    $action=$_POST['action']??'';

    if($action==='status'){
        $status=$_POST['status']??'';
        if(!in_array($status,['Available','Processed','Sold','Returned'],true)){
            echo json_encode(['success'=>false,'message'=>'Invalid status.']); exit;
        }

        $st=$conn->prepare("UPDATE exchange_items_stock
                            SET status=?,updated_at=NOW()
                            WHERE id=? AND business_id=? AND branch_id=?");
        $st->bind_param('siii',$status,$id,$businessId,$branchId);
        $st->execute();
        $ok=$st->affected_rows>0;
        $st->close();

        echo json_encode([
            'success'=>$ok,
            'message'=>$ok?'Status updated successfully.':'No change made.'
        ]);
        exit;
    }

    if($action==='delete'){
        $st=$conn->prepare("DELETE FROM exchange_items_stock
                            WHERE id=? AND business_id=? AND branch_id=?
                              AND status='Available'
                            LIMIT 1");
        $st->bind_param('iii',$id,$businessId,$branchId);
        $st->execute();
        $ok=$st->affected_rows>0;
        $st->close();

        echo json_encode([
            'success'=>$ok,
            'message'=>$ok?'Exchange item deleted.':'Only available items can be deleted.'
        ]);
        exit;
    }
}

$dateFrom=$_GET['date_from']??date('Y-m-01');
$dateTo=$_GET['date_to']??date('Y-m-d');
$status=$_GET['status']??'';
$search=trim($_GET['search']??'');

$sql="SELECT eis.*,c.customer_name,c.mobile customer_mobile,s.invoice_no
      FROM exchange_items_stock eis
      LEFT JOIN customers c
        ON c.id=eis.customer_id AND c.business_id=eis.business_id
      LEFT JOIN sales s
        ON s.id=eis.sale_id
       AND s.business_id=eis.business_id
       AND s.branch_id=eis.branch_id
      WHERE eis.business_id=? AND eis.branch_id=?
        AND DATE(eis.received_date) BETWEEN ? AND ?";

$types='iiss';
$params=[$businessId,$branchId,$dateFrom,$dateTo];

if($status!==''){
    $sql.=" AND eis.status=?";
    $types.='s';
    $params[]=$status;
}

if($search!==''){
    $sql.=" AND (
        eis.item_name LIKE ?
        OR c.customer_name LIKE ?
        OR c.mobile LIKE ?
        OR s.invoice_no LIKE ?
    )";
    $types.='ssss';
    $like='%'.$search.'%';
    array_push($params,$like,$like,$like,$like);
}

$sql.=" ORDER BY eis.received_date DESC,eis.id DESC";

$st=$conn->prepare($sql);
$bind=[$types];
foreach($params as $i=>$v) $bind[]=&$params[$i];
call_user_func_array([$st,'bind_param'],$bind);
$st->execute();
$res=$st->get_result();
$items=[];
while($r=$res->fetch_assoc()) $items[]=$r;
$st->close();

$summary=['total'=>count($items),'available'=>0,'processed'=>0,'gross'=>0,'net'=>0,'value'=>0];
foreach($items as $r){
    if($r['status']==='Available')$summary['available']++;
    if($r['status']==='Processed')$summary['processed']++;
    $summary['gross']+=(float)$r['gross_weight'];
    $summary['net']+=(float)$r['net_weight'];
    $summary['value']+=(float)$r['stock_value'];
}

if(($_GET['export']??'')==='excel'){
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename=exchange-products.xls');
    echo '<table border="1"><tr><th>ID</th><th>Date</th><th>Item</th><th>Customer</th><th>Invoice</th><th>Gross</th><th>Wastage</th><th>Net</th><th>Rate</th><th>Value</th><th>Status</th></tr>';
    foreach($items as $r){
        echo '<tr><td>'.$r['id'].'</td><td>'.h($r['received_date']).'</td><td>'.h($r['item_name']).'</td><td>'.h($r['customer_name']).'</td><td>'.h($r['invoice_no']).'</td><td>'.$r['gross_weight'].'</td><td>'.$r['wastage_percent'].'</td><td>'.$r['net_weight'].'</td><td>'.$r['rate_per_gram'].'</td><td>'.$r['stock_value'].'</td><td>'.h($r['status']).'</td></tr>';
    }
    echo '</table>';
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Exchange Products</title>
<?php include('includes/links.php'); ?>
<style>
body{background:#f4f3f0}
.cardx,.stat{background:#fff;border:1px solid #e8e8e8;border-radius:12px}
.head{padding:14px 16px;display:flex;justify-content:space-between;align-items:center;gap:12px}
.btnx,.btns{padding:9px 13px;border-radius:9px;font-size:11px;font-weight:700}
.btnx{border:0;background:#d89416;color:#fff}
.btns{border:1px solid #e8e8e8;background:#fff;color:#171717}
.filters{padding:12px;margin-bottom:12px}
.grid{display:grid;grid-template-columns:repeat(6,1fr);gap:8px}
.stats{display:grid;grid-template-columns:repeat(6,1fr);gap:10px;margin-bottom:12px}
.stat{padding:13px}
.sl{font-size:9px;color:#7d8794;text-transform:uppercase}
.sv{font-size:18px;font-weight:800}
.table{font-size:10px}
.table th{font-size:9px;text-transform:uppercase;color:#7d8794}
.num{text-align:right;white-space:nowrap}
.badge{padding:5px 8px;border-radius:999px}
.Available{background:#eaf8f0;color:#168449}
.Processed{background:#eaf0ff;color:#3155a6}
.Sold{background:#f0eafe;color:#7540b8}
.Returned{background:#fdecec;color:#bd2d2d}
.act{display:flex;justify-content:flex-end;gap:5px}
.icon{width:32px;height:32px;border:1px solid #e8e8e8;border-radius:8px;background:#fff}
.detail-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}
.detail{border:1px solid #e8e8e8;border-radius:9px;padding:10px}
.dl{font-size:9px;color:#7d8794}
.dv{font-weight:800}
@media(max-width:900px){.grid,.stats{grid-template-columns:repeat(2,1fr)}}
@media(max-width:600px){.grid,.stats,.detail-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<?php include('includes/sidebar.php'); ?>
<main class="app-main">
<?php include('includes/nav.php'); ?>
<div class="content-wrap">

<div class="cardx mb-3">
    <div class="head">
        <div>
            <h3 class="mb-1">Exchange Products</h3>
            <div class="text-muted small">Exchange item details, weights, values and stock status.</div>
        </div>

        <div class="d-flex gap-2">
            <button onclick="window.print()" class="btns">Print</button>
            <a class="btnx text-decoration-none"
               href="?<?=h(http_build_query(array_merge($_GET,['export'=>'excel'])))?>">
                Excel
            </a>
        </div>
    </div>
</div>

<form class="cardx filters">
    <div class="grid">
        <input type="date" name="date_from" class="form-control" value="<?=h($dateFrom)?>">
        <input type="date" name="date_to" class="form-control" value="<?=h($dateTo)?>">

        <select name="status" class="form-select">
            <option value="">All Status</option>
            <?php foreach(['Available','Processed','Sold','Returned'] as $s): ?>
                <option value="<?=h($s)?>" <?=$status===$s?'selected':''?>><?=h($s)?></option>
            <?php endforeach; ?>
        </select>

        <input name="search" class="form-control"
               value="<?=h($search)?>"
               placeholder="Item, customer, invoice">

        <button class="btnx">Apply</button>

        <a href="exchange-products.php"
           class="btns text-decoration-none text-center">
            Reset
        </a>
    </div>
</form> 

<div class="stats">
    <div class="stat"><div class="sl">Total Items</div><div class="sv"><?=$summary['total']?></div></div>
    <div class="stat"><div class="sl">Available</div><div class="sv"><?=$summary['available']?></div></div>
    <div class="stat"><div class="sl">Processed</div><div class="sv"><?=$summary['processed']?></div></div>
    <div class="stat"><div class="sl">Gross Weight</div><div class="sv"><?=number_format($summary['gross'],3)?> g</div></div>
    <div class="stat"><div class="sl">Net Weight</div><div class="sv"><?=number_format($summary['net'],3)?> g</div></div>
    <div class="stat"><div class="sl">Stock Value</div><div class="sv"><?=h(money($summary['value'],$currency))?></div></div>
</div>

<div class="cardx table-responsive">
<table class="table mb-0">
<thead>
<tr>
    <th>ID</th>
    <th>Date</th>
    <th>Item</th>
    <th>Customer</th>
    <th>Invoice</th>
    <th class="num">Gross</th>
    <th class="num">Wastage</th>
    <th class="num">Net</th>
    <th class="num">Rate</th>
    <th class="num">Value</th>
    <th>Status</th>
    <th class="num">Actions</th>
</tr>
</thead>
<tbody>

<?php if(!$items): ?>
<tr><td colspan="12" class="text-center py-5">No exchange products found.</td></tr>
<?php endif; ?>

<?php foreach($items as $r): ?>
<tr>
    <td>#<?=$r['id']?></td>
    <td><?=h(date('d-m-Y',strtotime($r['received_date'])))?></td>
    <td><strong><?=h($r['item_name'])?></strong></td>

    <td>
        <?=h($r['customer_name']?:'Walk-in')?>
        <div class="text-muted"><?=h($r['customer_mobile'])?></div>
    </td>

    <td>
        <?php if($r['sale_id']): ?>
            <a href="sales-view.php?id=<?=$r['sale_id']?>">
                <?=h($r['invoice_no']?:'Sale #'.$r['sale_id'])?>
            </a>
        <?php else: ?>—<?php endif; ?>
    </td>

    <td class="num"><?=number_format($r['gross_weight'],3)?> g</td>
    <td class="num"><?=number_format($r['wastage_percent'],3)?>%</td>
    <td class="num"><?=number_format($r['net_weight'],3)?> g</td>
    <td class="num"><?=h(money($r['rate_per_gram'],$currency))?></td>
    <td class="num"><?=h(money($r['stock_value'],$currency))?></td>

    <td>
        <span class="badge <?=h($r['status'])?>"><?=h($r['status'])?></span>
    </td>

    <td>
        <div class="act">
            <button class="icon view" type="button"
                    data-json="<?=h(json_encode($r,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))?>">
                <i class="fa-solid fa-eye"></i>
            </button>

            <button class="icon edit" type="button"
                    data-id="<?=$r['id']?>"
                    data-status="<?=h($r['status'])?>">
                <i class="fa-solid fa-pen"></i>
            </button>

            <?php if($r['status']==='Available'): ?>
                <button class="icon del" type="button" data-id="<?=$r['id']?>">
                    <i class="fa-solid fa-trash"></i>
                </button>
            <?php endif; ?>
        </div>
    </td>
</tr>
<?php endforeach; ?>

</tbody>
</table>
</div>

<?php include('includes/footer.php'); ?>
</div>
</main>

<div class="modal fade" id="viewModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5>Exchange Product Details</h5>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewBody"></div>
        </div>
    </div>
</div>

<div class="modal fade" id="statusModal" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5>Update Status</h5>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
                <input type="hidden" id="itemId">

                <select id="newStatus" class="form-select">
                    <?php foreach(['Available','Processed','Sold','Returned'] as $s): ?>
                        <option><?=h($s)?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="modal-footer">
                <button class="btns" data-bs-dismiss="modal">Cancel</button>
                <button class="btnx" id="saveStatus">Update</button>
            </div>
        </div>
    </div>
</div>

<?php include('includes/script.php'); ?>
<script src="assets/js/script.js"></script>

<script>
const csrf = <?=json_encode($csrf)?>;
const cur = <?=json_encode($currency)?>;

const vm = bootstrap.Modal.getOrCreateInstance(document.getElementById('viewModal'));
const sm = bootstrap.Modal.getOrCreateInstance(document.getElementById('statusModal'));

const esc = value => String(value ?? '')
    .replaceAll('&','&amp;')
    .replaceAll('<','&lt;')
    .replaceAll('>','&gt;')
    .replaceAll('"','&quot;')
    .replaceAll("'",'&#039;');

async function postData(data){
    data.append('csrf_token', csrf);

    const response = await fetch(location.pathname, {
        method:'POST',
        body:data,
        credentials:'same-origin'
    });

    const result = await response.json();

    if(!response.ok || !result.success){
        throw new Error(result.message || 'Request failed.');
    }

    return result;
}

document.querySelectorAll('.view').forEach(button => {
    button.addEventListener('click', function(){
        const item = JSON.parse(this.dataset.json || '{}');

        document.getElementById('viewBody').innerHTML = `
            <div class="detail-grid">
                <div class="detail">
                    <div class="dl">Item</div>
                    <div class="dv">${esc(item.item_name)}</div>
                </div>

                <div class="detail">
                    <div class="dl">Customer</div>
                    <div class="dv">${esc(item.customer_name || 'Walk-in')}</div>
                </div>

                <div class="detail">
                    <div class="dl">Invoice</div>
                    <div class="dv">${esc(item.invoice_no || '—')}</div>
                </div>

                <div class="detail">
                    <div class="dl">Gross Weight</div>
                    <div class="dv">${Number(item.gross_weight || 0).toFixed(3)} g</div>
                </div>

                <div class="detail">
                    <div class="dl">Wastage</div>
                    <div class="dv">${Number(item.wastage_percent || 0).toFixed(3)}%</div>
                </div>

                <div class="detail">
                    <div class="dl">Net Weight</div>
                    <div class="dv">${Number(item.net_weight || 0).toFixed(3)} g</div>
                </div>

                <div class="detail">
                    <div class="dl">Rate / Gram</div>
                    <div class="dv">${esc(cur)} ${Number(item.rate_per_gram || 0).toFixed(2)}</div>
                </div>

                <div class="detail">
                    <div class="dl">Stock Value</div>
                    <div class="dv">${esc(cur)} ${Number(item.stock_value || 0).toFixed(2)}</div>
                </div>

                <div class="detail">
                    <div class="dl">Status</div>
                    <div class="dv">${esc(item.status)}</div>
                </div>
            </div>
        `;

        vm.show();
    });
});

document.querySelectorAll('.edit').forEach(button => {
    button.addEventListener('click', function(){
        document.getElementById('itemId').value = this.dataset.id;
        document.getElementById('newStatus').value = this.dataset.status;
        sm.show();
    });
});

document.getElementById('saveStatus').addEventListener('click', async function(){
    const data = new FormData();
    data.append('action','status');
    data.append('id',document.getElementById('itemId').value);
    data.append('status',document.getElementById('newStatus').value);

    this.disabled = true;

    try{
        await postData(data);
        location.reload();
    }catch(error){
        alert(error.message);
        this.disabled = false;
    }
});

document.querySelectorAll('.del').forEach(button => {
    button.addEventListener('click', async function(){
        if(!confirm('Delete this available exchange item?')) return;

        const data = new FormData();
        data.append('action','delete');
        data.append('id',this.dataset.id);

        this.disabled = true;

        try{
            await postData(data);
            location.reload();
        }catch(error){
            alert(error.message);
            this.disabled = false;
        }
    });
});
</script>
</body>
</html>