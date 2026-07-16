<?php
if (session_status() === PHP_SESSION_NONE)
    session_start();
date_default_timezone_set((string) ($_SESSION['timezone'] ?? 'Asia/Kolkata'));
header('Content-Type: application/json; charset=utf-8');
foreach ([dirname(__DIR__) . '/config/config.php', dirname(__DIR__) . '/config.php', dirname(__DIR__) . '/includes/config.php', dirname(__DIR__) . '/super-admin/includes/config.php'] as $f) {
    if (is_file($f)) {
        require_once $f;
        break;
    }
}
function respond(bool $ok, string $message, array $extra = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode(array_merge(['success' => $ok, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if (!isset($conn) || !($conn instanceof mysqli))
    respond(false, 'Database configuration is not available.', [], 500);
$conn->set_charset('utf8mb4');
if (empty($_SESSION['user_id']))
    respond(false, 'Your session has expired. Please log in again.', [], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST')
    respond(false, 'Invalid request method.', [], 405);
if (empty($_SESSION['billing_csrf']) || !hash_equals((string) $_SESSION['billing_csrf'], (string) ($_POST['csrf_token'] ?? '')))
    respond(false, 'Invalid or expired request token. Refresh the page.', [], 419);
$businessId = (int) ($_SESSION['business_id'] ?? 0);
$branchId = (int) ($_SESSION['branch_id'] ?? ($_SESSION['default_branch_id'] ?? 0));
$userId = (int) $_SESSION['user_id'];
if ($businessId <= 0 || $branchId <= 0)
    respond(false, 'A valid business and branch must be selected.', [], 403);
function tableExists(mysqli $c, string $t): bool
{
    $t = $c->real_escape_string($t);
    $r = $c->query("SHOW TABLES LIKE '{$t}'");
    return $r && $r->num_rows > 0;
}
function permission(mysqli $c, string $action): bool
{
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin')
        return true;
    $map = ['create' => 'can_create', 'open' => 'can_open'];
    $field = $map[$action] ?? '';
    if (!$field)
        return false;
    foreach (['perm.billing.create', 'perm.billing'] as $code) {
        if (isset($_SESSION['permissions'][$code][$field]))
            return (int) $_SESSION['permissions'][$code][$field] === 1;
    }
    $bid = (int) ($_SESSION['business_id'] ?? 0);
    $rid = (int) ($_SESSION['role_id'] ?? 0);
    if ($bid <= 0 || $rid <= 0)
        return false;
    $s = $c->prepare("SELECT rp.`{$field}` FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.business_id=? AND rp.role_id=? AND p.is_active=1 AND p.permission_code IN ('perm.billing.create','perm.billing') ORDER BY FIELD(p.permission_code,'perm.billing.create','perm.billing') LIMIT 1");
    if (!$s)
        return false;
    $s->bind_param('ii', $bid, $rid);
    $s->execute();
    $x = $s->get_result()->fetch_assoc();
    $s->close();
    return (int) ($x[$field] ?? 0) === 1;
}
if (!permission($conn, 'create') && !permission($conn, 'open'))
    respond(false, 'You do not have permission to create bills.', [], 403);
function bindDynamic(mysqli_stmt $stmt, string $types, array &$params): void
{
    $a = [$types];
    foreach ($params as $k => $v)
        $a[] =& $params[$k];
    call_user_func_array([$stmt, 'bind_param'], $a);
}
function periodKey(string $reset, string $date): string
{
    $ts = strtotime($date);
    switch ($reset) {
        case 'Monthly':
            return date('Ym', $ts);
        case 'Daily':
            return date('Ymd', $ts);
        case 'Calendar Year':
            return date('Y', $ts);
        case 'Financial Year':
            $y = (int) date('Y', $ts);
            $m = (int) date('n', $ts);
            $a = $m >= 4 ? $y : $y - 1;
            return $a . '-' . ($a + 1);
        default:
            return 'ALL';
    }
}
function renderNo(array $s, int $seq, string $date): string
{
    $ts = strtotime($date);
    $y = (int) date('Y', $ts);
    $m = (int) date('n', $ts);
    $fyStart = $m >= 4 ? $y : $y - 1;
    $fyShort = substr((string) $fyStart, -2) . '-' . substr((string) ($fyStart + 1), -2);
    $map = ['{PREFIX}' => (string) ($s['prefix'] ?? 'INV'), '{SPLITTER}' => (string) ($s['splitter_symbol'] ?? '/'), '{FY_SHORT}' => $fyShort, '{FY_2DIGIT}' => str_replace('-', '', $fyShort), '{YYYY}' => date('Y', $ts), '{MM}' => date('m', $ts), '{DD}' => date('d', $ts), '{SEQ}' => str_pad((string) $seq, max(1, (int) ($s['sequence_digits'] ?? 3)), '0', STR_PAD_LEFT)];
    return strtr((string) ($s['format_template'] ?? '{PREFIX}{SPLITTER}{FY_SHORT}{SPLITTER}{SEQ}'), $map);
}
function nextInvoice(mysqli $c, int $bid, int $branch, string $date): array
{
    $s = $c->prepare("SELECT * FROM invoice_settings WHERE business_id=? AND (branch_id=? OR branch_id IS NULL) AND document_type='Invoice' AND is_active=1 ORDER BY (branch_id=?) DESC,is_default DESC,id DESC LIMIT 1 FOR UPDATE");
    if (!$s)
        throw new RuntimeException('Invoice settings are not available.');
    $s->bind_param('iii', $bid, $branch, $branch);
    $s->execute();
    $setting = $s->get_result()->fetch_assoc();
    $s->close();
    if (!$setting)
        throw new RuntimeException('Configure Invoice numbering in Master Control first.');
    $key = periodKey((string) $setting['reset_frequency'], $date);
    $doc = 'Invoice';
    $q = $c->prepare("SELECT id,current_number FROM number_sequences WHERE business_id=? AND branch_id=? AND document_type=? AND period_key=? LIMIT 1 FOR UPDATE");
    $q->bind_param('iiss', $bid, $branch, $doc, $key);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();
    $q->close();
    if ($row) {
        $seq = (int) $row['current_number'] + 1;
        $u = $c->prepare('UPDATE number_sequences SET current_number=? WHERE id=?');
        $u->bind_param('ii', $seq, $row['id']);
        $u->execute();
        $u->close();
    } else {
        $seq = max(1, (int) ($setting['sequence_start'] ?? 1));
        $i = $c->prepare('INSERT INTO number_sequences(business_id,branch_id,document_type,period_key,current_number) VALUES(?,?,?,?,?)');
        $i->bind_param('iissi', $bid, $branch, $doc, $key, $seq);
        $i->execute();
        $i->close();
    }
    return ['invoice_no' => renderNo($setting, $seq, $date), 'setting_id' => (int) $setting['id']];
}
$action = (string) ($_POST['action'] ?? 'save');
if ($action === 'create_customer') {
    $name = trim((string) ($_POST['customer_name'] ?? ''));
    $mobile = trim((string) ($_POST['mobile'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $gstin = strtoupper(trim((string) ($_POST['gstin'] ?? '')));
    $address = trim((string) ($_POST['address_line1'] ?? ''));
    $pincode = trim((string) ($_POST['pincode'] ?? ''));
    if ($name === '')
        respond(false, 'Customer name is required.', [], 422);
    if ($mobile === '')
        respond(false, 'Mobile number is required.', [], 422);
    $du = $conn->prepare('SELECT id FROM customers WHERE business_id=? AND mobile=? LIMIT 1');
    $du->bind_param('is', $businessId, $mobile);
    $du->execute();
    if ($du->get_result()->fetch_assoc()) {
        $du->close();
        respond(false, 'A customer with this mobile number already exists.', [], 409);
    }
    $du->close();
    $conn->begin_transaction();
    try {
        $q = $conn->prepare('SELECT COALESCE(MAX(id),0)+1 n FROM customers WHERE business_id=?');
        $q->bind_param('i', $businessId);
        $q->execute();
        $n = (int) ($q->get_result()->fetch_assoc()['n'] ?? 1);
        $q->close();
        $code = 'CUS' . date('ym') . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
        $s = $conn->prepare('INSERT INTO customers(business_id,home_branch_id,customer_code,customer_name,mobile,email,gstin,address_line1,pincode,notes,is_active) VALUES(?,?,?,?,?,?,?,?,?,\'Customer Category: billing\',1)');
        $s->bind_param('iisssssss', $businessId, $branchId, $code, $name, $mobile, $email, $gstin, $address, $pincode);
        if (!$s->execute())
            throw new RuntimeException($s->error);
        $id = (int) $s->insert_id;
        $s->close();
        if (tableExists($conn, 'customer_services')) {
            $cs = $conn->prepare("INSERT INTO customer_services(business_id,customer_id,service_type,is_active,created_by) VALUES(?,?,'Billing',1,?)");
            $cs->bind_param('iii', $businessId, $id, $userId);
            $cs->execute();
            $cs->close();
        }
        $conn->commit();
        respond(true, 'Customer created successfully.', ['customer' => ['id' => $id, 'customer_code' => $code, 'customer_name' => $name, 'mobile' => $mobile]]);
    } catch (Throwable $e) {
        $conn->rollback();
        respond(false, 'Unable to create customer: ' . $e->getMessage(), [], 500);
    }
}
if ($action !== 'save')
    respond(false, 'Invalid action.', [], 400);
$invoiceDate = (string) ($_POST['invoice_date'] ?? date('Y-m-d'));
$invoiceTime = (string) ($_POST['invoice_time'] ?? date('H:i'));
$billType = (string) ($_POST['bill_type'] ?? 'Retail');
if (!in_array($billType, ['Retail', 'GST', 'Estimate', 'Exchange'], true))
    $billType = 'Retail';
$customerId = (int) ($_POST['customer_id'] ?? 0);
if ($customerId <= 0)
    respond(false, 'Please select a customer.', [], 422);
$cs = $conn->prepare('SELECT customer_name,mobile FROM customers WHERE id=? AND business_id=? AND is_active=1 LIMIT 1');
$cs->bind_param('ii', $customerId, $businessId);
$cs->execute();
$customer = $cs->get_result()->fetch_assoc();
$cs->close();
if (!$customer)
    respond(false, 'Selected customer is invalid.', [], 422);
$productIds = $_POST['product_id'] ?? [];
$qtys = $_POST['quantity'] ?? [];
$rates = $_POST['metal_rate'] ?? [];
$wastages = $_POST['wastage_percent'] ?? [];
$makings = $_POST['making_charge'] ?? [];
$stones = $_POST['stone_amount'] ?? [];
$others = $_POST['other_charge'] ?? [];
$discounts = $_POST['item_discount'] ?? [];
if (!is_array($productIds) || count($productIds) < 1)
    respond(false, 'Add at least one product.', [], 422);
$overall = max(0, (float) ($_POST['overall_discount'] ?? 0));
$round = (float) ($_POST['round_off'] ?? 0);
$notes = trim((string) ($_POST['notes'] ?? ''));
$claims = json_decode((string) ($_POST['chit_claims_json'] ?? '[]'), true);
if (!is_array($claims))
    $claims = [];
$payMethods = $_POST['payment_method_id'] ?? [];
$payAmounts = $_POST['payment_amount'] ?? [];
$payRefs = $_POST['payment_reference'] ?? [];
$conn->begin_transaction();
try {
    $number = nextInvoice($conn, $businessId, $branchId, $invoiceDate);
    $items = [];
    $subtotal = 0.0;
    $itemDiscount = 0.0;
    $taxable = 0.0;
    $taxTotal = 0.0;
    $productStmt = $conn->prepare('SELECT p.*,COALESCE(ps.quantity,0) stock_qty,COALESCE(ps.gross_weight,0) stock_gross,COALESCE(ps.net_weight,0) stock_net,COALESCE(mr.rate_per_gram,p.sale_rate,0) live_metal_rate,mr.id live_metal_rate_id,mr.effective_from live_rate_effective_from FROM products p LEFT JOIN product_stock ps ON ps.product_id=p.id AND ps.business_id=p.business_id AND ps.branch_id=? LEFT JOIN metal_rates mr ON mr.id=(SELECT mr2.id FROM metal_rates mr2 WHERE mr2.business_id=p.business_id AND mr2.metal_id=p.metal_id AND mr2.is_current=1 AND (mr2.branch_id=? OR mr2.branch_id IS NULL) ORDER BY (mr2.branch_id=?) DESC,mr2.effective_from DESC,mr2.id DESC LIMIT 1) WHERE p.id=? AND p.business_id=? AND p.is_active=1 LIMIT 1 FOR UPDATE');
    foreach ($productIds as $i => $pidRaw) {
        $pid = (int) $pidRaw;
        if ($pid <= 0)
            continue;
        $qty = round((float) ($qtys[$i] ?? 0), 3);
        if ($qty <= 0)
            throw new RuntimeException('Quantity must be greater than zero.');
        $productStmt->bind_param('iiiii', $branchId, $branchId, $branchId, $pid, $businessId);
        $productStmt->execute();
        $p = $productStmt->get_result()->fetch_assoc();
        if (!$p)
            throw new RuntimeException('A selected product is invalid.');
        if ((int) $p['track_stock'] === 1 && (float) $p['stock_qty'] + 0.0001 < $qty)
            throw new RuntimeException($p['product_name'] . ' has only ' . number_format((float) $p['stock_qty'], 3) . ' stock available.');
        $rate = max(0, (float) ($p['live_metal_rate'] ?? $p['sale_rate'] ?? ($rates[$i] ?? 0)));
        $w = max(0, (float) ($wastages[$i] ?? $p['wastage_percent']));
        $making = max(0, (float) ($makings[$i] ?? $p['making_charge']));
        $stone = max(0, (float) ($stones[$i] ?? 0));
        $other = max(0, (float) ($others[$i] ?? 0));
        $disc = max(0, (float) ($discounts[$i] ?? 0));
        $gross = (float) $p['gross_weight'] * $qty;
        $stoneWeight = (float) $p['stone_weight'] * $qty;
        $net = (float) $p['net_weight'] * $qty;
        $metal = $net > 0 ? $net * $rate : $qty * $rate;
        $wastageAmount = $metal * $w / 100;
        $rowSub = $metal + $wastageAmount + $making + $stone + $other;
        $rowTaxable = max(0, $rowSub - $disc);
        $tax = $rowTaxable * ((float) $p['tax_percent']) / 100;
        $line = $rowTaxable + $tax;
        $items[] = ['p' => $p, 'qty' => $qty, 'gross' => $gross, 'stone_weight' => $stoneWeight, 'net' => $net, 'rate' => $rate, 'w' => $w, 'wamt' => $wastageAmount, 'making' => $making, 'stone' => $stone, 'other' => $other, 'disc' => $disc, 'tax' => $tax, 'line' => $line];
        $subtotal += $rowSub;
        $itemDiscount += $disc;
        $taxable += $rowTaxable;
        $taxTotal += $tax;
    }
    $productStmt->close();
    if (!$items)
        throw new RuntimeException('Add at least one valid product.');
    $taxable = max(0, $taxable - $overall);
    $discountTotal = $itemDiscount + $overall;
    $cgst = $taxTotal / 2;
    $sgst = $taxTotal / 2;
    $grand = max(0, $taxable + $cgst + $sgst + $round);
    $claimTotal = 0.0;
    $validatedClaims = [];
    if ($claims) {
        if (!tableExists($conn, 'sales_chit_claims'))
            throw new RuntimeException('Chit claim table is not available.');
        $cq = $conn->prepare("SELECT cm.chit_group_id,COALESCE((SELECT SUM(cc.paid_amount) FROM chit_collections cc WHERE cc.business_id=cm.business_id AND cc.chit_member_id=cm.id),0)-COALESCE((SELECT SUM(scc.claim_amount) FROM sales_chit_claims scc WHERE scc.business_id=cm.business_id AND scc.chit_member_id=cm.id AND scc.status='Posted'),0) available FROM chit_members cm WHERE cm.id=? AND cm.business_id=? AND cm.customer_id=? LIMIT 1 FOR UPDATE");
        foreach ($claims as $c) {
            $mid = (int) ($c['chit_member_id'] ?? 0);
            $amt = round((float) ($c['claim_amount'] ?? 0), 2);
            if ($mid <= 0 || $amt <= 0)
                continue;
            $cq->bind_param('iii', $mid, $businessId, $customerId);
            $cq->execute();
            $x = $cq->get_result()->fetch_assoc();
            if (!$x || $amt > (float) $x['available'] + 0.001)
                throw new RuntimeException('A chit claim exceeds the available amount.');
            $claimTotal += $amt;
            $validatedClaims[] = ['member' => $mid, 'group' => (int) $x['chit_group_id'], 'amount' => $amt];
        }
        $cq->close();
    }
    if ($claimTotal > $grand + 0.001)
        throw new RuntimeException('Chit claim cannot exceed the bill total.');
    $netPayable = max(0, $grand - $claimTotal);
    $payments = [];
    $paid = 0.0;
    if (is_array($payMethods)) {
        foreach ($payMethods as $i => $methodRaw) {
            $method = (int) $methodRaw;
            $amt = round((float) ($payAmounts[$i] ?? 0), 2);
            if ($method <= 0 && $amt <= 0)
                continue;
            if ($method <= 0 || $amt <= 0)
                throw new RuntimeException('Select a payment method and enter its amount.');
            $ref = trim((string) ($payRefs[$i] ?? ''));
            $payments[] = [$method, $amt, $ref];
            $paid += $amt;
        }
    }
    if ($paid > $netPayable + 0.01)
        throw new RuntimeException('Split payment total cannot exceed the net payable amount.');
    $balance = max(0, $netPayable - $paid);
    $paymentStatus = $balance <= 0.01 ? 'Paid' : ($paid > 0 ? 'Partial' : 'Unpaid');
    $sale = $conn->prepare('INSERT INTO sales(business_id,branch_id,invoice_setting_id,invoice_no,invoice_date,invoice_time,customer_id,customer_name,customer_mobile,bill_type,subtotal,discount_amount,taxable_amount,cgst_amount,sgst_amount,igst_amount,round_off,grand_total,chit_claim_amount,net_payable_amount,paid_amount,balance_amount,payment_status,workflow_status,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,\'Posted\',?,?)');
    $igst = 0.0;
    $sale->bind_param('iiisssisssddddddddddddssi', $businessId, $branchId, $number['setting_id'], $number['invoice_no'], $invoiceDate, $invoiceTime, $customerId, $customer['customer_name'], $customer['mobile'], $billType, $subtotal, $discountTotal, $taxable, $cgst, $sgst, $igst, $round, $grand, $claimTotal, $netPayable, $paid, $balance, $paymentStatus, $notes, $userId);
    if (!$sale->execute())
        throw new RuntimeException('Unable to create bill: ' . $sale->error);
    $saleId = (int) $sale->insert_id;
    $sale->close();
    $itemStmt = $conn->prepare('INSERT INTO sale_items(business_id,branch_id,sale_id,product_id,item_name,hsn_code,quantity,gross_weight,stone_weight,net_weight,metal_rate,wastage_percent,wastage_amount,making_charge,stone_amount,other_charge,discount_amount,tax_percent,tax_amount,line_total,cost_amount,sort_order) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $stockUp = $conn->prepare('UPDATE product_stock SET quantity=quantity-?,gross_weight=GREATEST(0,gross_weight-?),net_weight=GREATEST(0,net_weight-?) WHERE business_id=? AND branch_id=? AND product_id=?');
    $move = $conn->prepare("INSERT INTO stock_movements(business_id,branch_id,product_id,movement_date,movement_type,reference_table,reference_id,quantity_in,quantity_out,weight_in,weight_out,rate,value_amount,remarks,created_by) VALUES(?,?,?,?,'Sale','sales',?,0,?,0,?,?,?,?,?)");
    foreach ($items as $idx => $it) {
        $p = $it['p'];
        $cost = (float) $p['purchase_rate'] * $it['net'];
        $sort = $idx + 1;
        $itemStmt->bind_param('iiiissdddddddddddddddi', $businessId, $branchId, $saleId, $p['id'], $p['product_name'], $p['hsn_code'], $it['qty'], $it['gross'], $it['stone_weight'], $it['net'], $it['rate'], $it['w'], $it['wamt'], $it['making'], $it['stone'], $it['other'], $it['disc'], $p['tax_percent'], $it['tax'], $it['line'], $cost, $sort);
        $itemStmt->execute();
        if ((int) $p['track_stock'] === 1) {
            $stockUp->bind_param('dddiii', $it['qty'], $it['gross'], $it['net'], $businessId, $branchId, $p['id']);
            $stockUp->execute();
            if ($stockUp->affected_rows < 1)
                throw new RuntimeException('Unable to reduce stock for ' . $p['product_name']);
            $movementDate = $invoiceDate . ' ' . $invoiceTime . ':00';
            $value = $it['line'];
            $remarks = 'Sale ' . $number['invoice_no'];
            $move->bind_param('iiisiddddsi', $businessId, $branchId, $p['id'], $movementDate, $saleId, $it['qty'], $it['net'], $it['rate'], $value, $remarks, $userId);
            $move->execute();
        }
    }
    $itemStmt->close();
    $stockUp->close();
    $move->close();
    if ($payments) {
        $pm = $conn->prepare('INSERT INTO sale_payments(business_id,branch_id,sale_id,payment_method_id,amount,reference_no,payment_date,created_by) VALUES(?,?,?,?,?,?,?,?)');
        $dt = $invoiceDate . ' ' . $invoiceTime . ':00';
        foreach ($payments as [$method, $amt, $ref]) {
            $pm->bind_param('iiiidssi', $businessId, $branchId, $saleId, $method, $amt, $ref, $dt, $userId);
            $pm->execute();
        }
        $pm->close();
    }
    if ($validatedClaims) {
        $cc = $conn->prepare("INSERT INTO sales_chit_claims(business_id,branch_id,sale_id,customer_id,chit_group_id,chit_member_id,claim_amount,status,created_by) VALUES(?,?,?,?,?,?,?,'Posted',?)");
        foreach ($validatedClaims as $x) {
            $cc->bind_param('iiiiiidi', $businessId, $branchId, $saleId, $customerId, $x['group'], $x['member'], $x['amount'], $userId);
            $cc->execute();
        }
        $cc->close();
    }
    $conn->commit();
    respond(true, 'Bill ' . $number['invoice_no'] . ' created successfully.', ['sale_id' => $saleId, 'invoice_no' => $number['invoice_no']]);
} catch (Throwable $e) {
    $conn->rollback();
    respond(false, $e->getMessage(), [], 422);
}