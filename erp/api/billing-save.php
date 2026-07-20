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

function prepareOrFail(mysqli $conn, string $sql, string $label): mysqli_stmt
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($label . ': ' . $conn->error);
    }
    return $stmt;
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
function renderNo(array $setting, int $sequence, string $date): string
{
    $ts = strtotime($date);
    $year = (int) date('Y', $ts);
    $month = (int) date('n', $ts);
    $fyStart = $month >= 4 ? $year : $year - 1;
    $fyShort = substr((string) $fyStart, -2) . '-' . substr((string) ($fyStart + 1), -2);

    $center = (string) ($setting['center_format'] ?? '{FY_SHORT}');
    $center = strtr($center, [
        '{FY_SHORT}' => $fyShort,
        '{FY_2DIGIT}' => str_replace('-', '', $fyShort),
        '{YYYY}' => date('Y', $ts),
        '{YY}' => date('y', $ts),
        '{MM}' => date('m', $ts),
        '{DD}' => date('d', $ts)
    ]);

    return strtr(
        (string) ($setting['format_template'] ?? '{PREFIX}{DIVIDER}{CENTER}{DIVIDER}{SEQ}{SUFFIX}'),
        [
            '{PREFIX}' => (string) ($setting['prefix'] ?? ''),
            '{DIVIDER}' => (string) ($setting['divider'] ?? '/'),
            '{CENTER}' => $center,
            '{SEQ}' => str_pad(
                (string) $sequence,
                max(1, (int) ($setting['sequence_digits'] ?? 3)),
                '0',
                STR_PAD_LEFT
            ),
            '{SUFFIX}' => (string) ($setting['suffix'] ?? '')
        ]
    );
}

function nextDocumentNumber(mysqli $c, int $bid, int $branch, string $date, string $documentType): array
{
    $documentKey = strtolower($documentType);
    $s = $c->prepare(
        "SELECT * FROM document_number_settings
         WHERE business_id=?
           AND (branch_id=? OR branch_id IS NULL)
           AND document_key=?
           AND is_active=1
         ORDER BY (branch_id=?) DESC,id DESC
         LIMIT 1 FOR UPDATE"
    );
    if (!$s)
        throw new RuntimeException($documentType . ' settings are not available: ' . $c->error);
    $s->bind_param('iisi', $bid, $branch, $documentKey, $branch);
    $s->execute();
    $setting = $s->get_result()->fetch_assoc();
    $s->close();
    if (!$setting)
        throw new RuntimeException('Configure ' . $documentType . ' numbering in Master Control first.');
    $key = periodKey((string) $setting['reset_frequency'], $date);
    $doc = $documentType;
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
    return ['document_no' => renderNo($setting, $seq, $date), 'setting_id' => (int)$setting['id'], 'document_type' => $documentType];
}
$action = (string) ($_POST['action'] ?? 'save');
if ($action === 'preview_number') {
    $documentType = (string)($_POST['document_type'] ?? 'Invoice');
    if (!in_array($documentType, ['Invoice', 'Estimate'], true)) {
        respond(false, 'Invalid document type.', [], 422);
    }
    $documentDate = (string)($_POST['document_date'] ?? date('Y-m-d'));
    $dateObject = DateTime::createFromFormat('Y-m-d', $documentDate);
    if (!$dateObject || $dateObject->format('Y-m-d') !== $documentDate) {
        respond(false, 'Valid document date is required.', [], 422);
    }
    try {
        $documentKey = strtolower($documentType);
        $s = prepareOrFail(
            $conn,
            "SELECT * FROM document_number_settings
             WHERE business_id=?
               AND (branch_id=? OR branch_id IS NULL)
               AND document_key=?
               AND is_active=1
             ORDER BY (branch_id=?) DESC,id DESC
             LIMIT 1",
            'Unable to load numbering setting'
        );
        $s->bind_param('iisi', $businessId, $branchId, $documentKey, $branchId);
        $s->execute();
        $setting = $s->get_result()->fetch_assoc();
        $s->close();
        if (!$setting) throw new RuntimeException('Configure ' . $documentType . ' numbering in Master Control first.');
        $key = periodKey((string)$setting['reset_frequency'], $documentDate);
        $q = prepareOrFail($conn, 'SELECT current_number FROM number_sequences WHERE business_id=? AND branch_id=? AND document_type=? AND period_key=? LIMIT 1', 'Unable to load number sequence');
        $q->bind_param('iiss', $businessId, $branchId, $documentType, $key);
        $q->execute();
        $row = $q->get_result()->fetch_assoc();
        $q->close();
        $seq = $row ? ((int)$row['current_number'] + 1) : max(1, (int)($setting['sequence_start'] ?? 1));
        respond(true, 'Next number loaded.', ['document_no' => renderNo($setting, $seq, $documentDate), 'document_type' => $documentType]);
    } catch (Throwable $e) {
        respond(false, $e->getMessage(), [], 422);
    }
}
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
$documentType = $billType === 'Estimate' ? 'Estimate' : 'Invoice';
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
$taxPercents = $_POST['tax_percent'] ?? [];
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
$exchangeItemsInput = json_decode((string) ($_POST['exchange_items_json'] ?? '[]'), true);
if (!is_array($exchangeItemsInput))
    $exchangeItemsInput = [];
if (!is_array($claims))
    $claims = [];
$payMethods = $_POST['payment_method_id'] ?? [];
$payAmounts = $_POST['payment_amount'] ?? [];
$payRefs = $_POST['payment_reference'] ?? [];
$conn->begin_transaction();
try {
    $number = nextDocumentNumber($conn, $businessId, $branchId, $invoiceDate, $documentType);
    $items = [];
    $subtotal = 0.0;
    $itemDiscount = 0.0;
    $taxable = 0.0;
    $taxTotal = 0.0;
    $productStmt = prepareOrFail($conn, 'SELECT p.*,COALESCE(ps.quantity,0) stock_qty,COALESCE(ps.gross_weight,0) stock_gross,COALESCE(ps.net_weight,0) stock_net,COALESCE(mr.rate_per_gram,p.sale_rate,0) live_metal_rate,mr.id live_metal_rate_id,mr.effective_from live_rate_effective_from FROM products p LEFT JOIN product_stock ps ON ps.product_id=p.id AND ps.business_id=p.business_id AND ps.branch_id=? LEFT JOIN metal_rates mr ON mr.id=(SELECT mr2.id FROM metal_rates mr2 WHERE mr2.business_id=p.business_id AND mr2.metal_id=p.metal_id AND mr2.is_current=1 AND (mr2.branch_id=? OR mr2.branch_id IS NULL) ORDER BY (mr2.branch_id=?) DESC,mr2.effective_from DESC,mr2.id DESC LIMIT 1) WHERE p.id=? AND p.business_id=? AND p.is_active=1 LIMIT 1 FOR UPDATE', 'Unable to prepare product lookup');
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
        $taxPercent = max(0, min(100, (float) ($taxPercents[$i] ?? $p['tax_percent'] ?? 0)));
        $gross = (float) $p['gross_weight'] * $qty;
        $stoneWeight = (float) $p['stone_weight'] * $qty;
        $net = (float) $p['net_weight'] * $qty;
        $metal = $net > 0 ? $net * $rate : $qty * $rate;
        $wastageAmount = $metal * $w / 100;
        $rowSub = $metal + $wastageAmount + $making + $stone + $other;
        $rowTaxable = max(0, $rowSub - $disc);
        $tax = $rowTaxable * $taxPercent / 100;
        $line = $rowTaxable + $tax;
        $items[] = ['p' => $p, 'qty' => $qty, 'gross' => $gross, 'stone_weight' => $stoneWeight, 'net' => $net, 'rate' => $rate, 'w' => $w, 'wamt' => $wastageAmount, 'making' => $making, 'stone' => $stone, 'other' => $other, 'disc' => $disc, 'tax_percent' => $taxPercent, 'tax' => $tax, 'line' => $line];
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
    $exchangeTotal = 0.0;
    $validatedExchange = [];
    foreach ($exchangeItemsInput as $ex) {
        $name = trim((string) ($ex['item_name'] ?? ''));
        $gross = round((float) ($ex['gross_weight'] ?? 0), 3);
        $waste = max(0, min(100, (float) ($ex['wastage_percent'] ?? 0)));
        $rate = max(0, (float) ($ex['rate_per_gram'] ?? 0));
        if ($name === '' || $gross <= 0 || $rate <= 0)
            continue;
        $eligible = round($gross * (1 - $waste / 100), 3);
        $value = round($eligible * $rate, 2);
        if ($eligible <= 0 || $value <= 0)
            throw new RuntimeException('Invalid exchange item weight or value.');
        $exchangeTotal += $value;
        $validatedExchange[] = ['name' => $name, 'gross' => $gross, 'waste' => $waste, 'eligible' => $eligible, 'rate' => $rate, 'value' => $value];
    }
    if ($exchangeTotal > $grand + 0.001)
        throw new RuntimeException('Exchange value cannot exceed bill total.');
    $grand = max(0, $grand - $exchangeTotal);
    $claimTotal = 0.0;
    $validatedClaims = [];
    if ($claims) {
        if (!tableExists($conn, 'sales_chit_claims'))
            throw new RuntimeException('Chit claim table is not available.');
        $cq = prepareOrFail($conn, "SELECT cm.chit_group_id,GREATEST(
    0,
    COALESCE((
        SELECT SUM(cc.gold_weight_grams)
        FROM chit_collections cc
        WHERE cc.business_id=cm.business_id
          AND cc.chit_member_id=cm.id
    ),0)
    -
    COALESCE((
        SELECT SUM(scc.claim_grams)
        FROM sales_chit_claims scc
        WHERE scc.business_id=cm.business_id
          AND scc.chit_member_id=cm.id
          AND scc.status='Posted'
    ),0)
) available_grams FROM chit_members cm WHERE cm.id=? AND cm.business_id=? AND cm.customer_id=? LIMIT 1 FOR UPDATE", 'Unable to prepare customer gram balance query. Confirm gold_weight_grams and claim_grams columns exist');
        foreach ($claims as $c) {
            $mid = (int) ($c['chit_member_id'] ?? 0);
            $grams = round((float) ($c['claim_grams'] ?? 0), 6);
            $productId = (int) ($c['product_id'] ?? 0);
            if ($mid <= 0 || $grams <= 0 || $productId <= 0)
                continue;
            $cq->bind_param('iii', $mid, $businessId, $customerId);
            $cq->execute();
            $x = $cq->get_result()->fetch_assoc();
            if (!$x) {
                throw new RuntimeException('Selected chit membership is invalid.');
            }

            $availableGrams = round(max(0, (float)$x['available_grams']), 6);

            if ($grams > $availableGrams + 0.000001) {
                throw new RuntimeException(
                    'Gold gram claim exceeds available grams. Available: ' .
                    number_format($availableGrams, 6) . ' g.'
                );
            }
            $pq = prepareOrFail($conn, "SELECT p.id,COALESCE(mr.rate_per_gram,p.sale_rate,0) rate_per_gram FROM products p LEFT JOIN metal_rates mr ON mr.id=(SELECT mr2.id FROM metal_rates mr2 WHERE mr2.business_id=p.business_id AND mr2.metal_id=p.metal_id AND mr2.is_current=1 AND (mr2.branch_id=? OR mr2.branch_id IS NULL) ORDER BY (mr2.branch_id=?) DESC,mr2.effective_from DESC,mr2.id DESC LIMIT 1) WHERE p.id=? AND p.business_id=? LIMIT 1", 'Unable to prepare claim product-rate query');
            $pq->bind_param('iiii', $branchId, $branchId, $productId, $businessId);
            $pq->execute();
            $pr = $pq->get_result()->fetch_assoc();
            $pq->close();
            if (!$pr || (float) $pr['rate_per_gram'] <= 0)
                throw new RuntimeException('Selected claim product has no valid rate.');
            $rate = (float) $pr['rate_per_gram'];
            $amt = round($grams * $rate, 2);
            $claimTotal += $amt;
            $validatedClaims[] = ['member' => $mid, 'group' => (int) $x['chit_group_id'], 'product' => $productId, 'grams' => $grams, 'rate' => $rate, 'amount' => $amt];
        }
        $cq->close();
    }
    if ($claimTotal > $grand + 0.001)
        throw new RuntimeException('Chit claim cannot exceed the bill total.');
    $netPayable = max(0, $grand - $claimTotal);
    $payments = [];
    $receivedAmount = 0.0;
    $creditAmount = 0.0;
    $splitTotal = 0.0;

    $paymentMethodStmt = prepareOrFail(
        $conn,
        'SELECT id,method_name FROM payment_methods WHERE id=? AND business_id=? AND is_active=1 LIMIT 1',
        'Unable to prepare payment-method validation'
    );

    if (is_array($payMethods)) {
        foreach ($payMethods as $i => $methodRaw) {
            $method = (int) $methodRaw;
            $amt = round((float) ($payAmounts[$i] ?? 0), 2);

            if ($method <= 0 && $amt <= 0) {
                continue;
            }

            if ($method <= 0 || $amt <= 0) {
                throw new RuntimeException('Select a payment method and enter its amount.');
            }

            $paymentMethodStmt->bind_param('ii', $method, $businessId);

            if (!$paymentMethodStmt->execute()) {
                throw new RuntimeException(
                    'Unable to validate payment method: ' . $paymentMethodStmt->error
                );
            }

            $methodRow = $paymentMethodStmt->get_result()->fetch_assoc();

            if (!$methodRow) {
                throw new RuntimeException('A selected payment method is invalid or inactive.');
            }

            $methodName = strtolower(trim((string)$methodRow['method_name']));
            $isCreditMethod =
                strpos($methodName, 'credit') !== false ||
                strpos($methodName, 'due') !== false ||
                strpos($methodName, 'pay later') !== false ||
                strpos($methodName, 'paylater') !== false;

            $ref = trim((string) ($payRefs[$i] ?? ''));

            $payments[] = [
                'method_id' => $method,
                'amount' => $amt,
                'reference' => $ref,
                'is_credit' => $isCreditMethod,
                'method_name' => (string)$methodRow['method_name']
            ];

            $splitTotal += $amt;

            if ($isCreditMethod) {
                $creditAmount += $amt;
            } else {
                $receivedAmount += $amt;
            }
        }
    }

    $paymentMethodStmt->close();

    if ($splitTotal > $netPayable + 0.01) {
        throw new RuntimeException('Split payment total cannot exceed the net payable amount.');
    }

    /*
     * Only actually received payment methods reduce the balance.
     * Credit / Due / Pay Later remains outstanding.
     */
    $paid = round($receivedAmount, 2);
    $balance = round(max(0, $netPayable - $paid), 2);
    $paymentStatus = $balance <= 0.01
        ? 'Paid'
        : ($paid > 0 ? 'Partial' : 'Unpaid');


    if ($documentType === 'Estimate') {
        foreach (['estimates','estimate_items','estimate_payments','estimate_exchange_items','estimate_chit_claims'] as $requiredTable) {
            if (!tableExists($conn, $requiredTable)) {
                throw new RuntimeException('Estimate tables are missing. Run the supplied estimate migration SQL first.');
            }
        }

        $estimateSettingId = null;
        $estimateNo = (string)$number['document_no'];
        $customerName = (string)$customer['customer_name'];
        $customerMobile = (string)$customer['mobile'];
        $igst = 0.0;
        $storedGrandTotal = $grand + $exchangeTotal + $claimTotal;

        $estimateStmt = prepareOrFail($conn, "INSERT INTO estimates
            (business_id,branch_id,invoice_setting_id,estimate_no,estimate_date,estimate_time,
             customer_id,customer_name,customer_mobile,bill_type,subtotal,discount_amount,
             taxable_amount,cgst_amount,sgst_amount,igst_amount,round_off,grand_total,
             exchange_amount,chit_claim_amount,net_estimate_amount,proposed_paid_amount,
             proposed_balance_amount,status,notes,created_by)
             VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'Open',?,?)", 'Unable to prepare estimate insert');
        $estimateStmt->bind_param('iiisssisssdddddddddddddsi',
            $businessId,$branchId,$estimateSettingId,$estimateNo,$invoiceDate,$invoiceTime,
            $customerId,$customerName,$customerMobile,$billType,$subtotal,$discountTotal,
            $taxable,$cgst,$sgst,$igst,$round,$storedGrandTotal,$exchangeTotal,$claimTotal,
            $netPayable,$paid,$balance,$notes,$userId);
        if (!$estimateStmt->execute()) throw new RuntimeException('Unable to save estimate: ' . $estimateStmt->error);
        $estimateId = (int)$estimateStmt->insert_id;
        $estimateStmt->close();

        $estimateItem = prepareOrFail($conn, "INSERT INTO estimate_items
            (business_id,branch_id,estimate_id,product_id,item_name,hsn_code,quantity,
             gross_weight,stone_weight,net_weight,metal_rate,wastage_percent,wastage_amount,
             making_charge,stone_amount,other_charge,discount_amount,tax_percent,tax_amount,
             line_total,cost_amount,sort_order) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)", 'Unable to prepare estimate item insert');
        foreach ($items as $idx => $it) {
            $p = $it['p'];
            $cost = (float)$p['purchase_rate'] * $it['net'];
            $sort = $idx + 1;
            $estimateItem->bind_param('iiiissdddddddddddddddi', $businessId,$branchId,$estimateId,$p['id'],$p['product_name'],$p['hsn_code'],$it['qty'],$it['gross'],$it['stone_weight'],$it['net'],$it['rate'],$it['w'],$it['wamt'],$it['making'],$it['stone'],$it['other'],$it['disc'],$it['tax_percent'],$it['tax'],$it['line'],$cost,$sort);
            if (!$estimateItem->execute()) throw new RuntimeException('Unable to save estimate item: ' . $estimateItem->error);
        }
        $estimateItem->close();

        if ($validatedExchange) {
            $ex = prepareOrFail($conn, 'INSERT INTO estimate_exchange_items(business_id,branch_id,estimate_id,customer_id,item_name,gross_weight,wastage_percent,eligible_weight,rate_per_gram,exchange_value,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)', 'Unable to prepare estimate exchange insert');
            foreach ($validatedExchange as $row) {
                $ex->bind_param('iiiisdddddi', $businessId,$branchId,$estimateId,$customerId,$row['name'],$row['gross'],$row['waste'],$row['eligible'],$row['rate'],$row['value'],$userId);
                if (!$ex->execute()) throw new RuntimeException('Unable to save estimate exchange item: ' . $ex->error);
            }
            $ex->close();
        }

        if ($payments) {
            $pay = prepareOrFail($conn, 'INSERT INTO estimate_payments(business_id,branch_id,estimate_id,payment_method_id,amount,reference_no,created_by) VALUES(?,?,?,?,?,?,?)', 'Unable to prepare estimate payment insert');
            foreach ($payments as $row) {
                $method = (int)$row['method_id']; $amount=(float)$row['amount']; $reference=(string)$row['reference'];
                $pay->bind_param('iiiidsi', $businessId,$branchId,$estimateId,$method,$amount,$reference,$userId);
                if (!$pay->execute()) throw new RuntimeException('Unable to save estimate payment: ' . $pay->error);
            }
            $pay->close();
        }

        if ($validatedClaims) {
            $claimStmt = prepareOrFail($conn, "INSERT INTO estimate_chit_claims
                (business_id,branch_id,estimate_id,customer_id,chit_group_id,chit_member_id,
                 product_id,claim_grams,rate_per_gram,claim_amount,status,created_by)
                 VALUES(?,?,?,?,?,?,?,?,?,?,'Proposed',?)", 'Unable to prepare estimate claim insert');
            foreach ($validatedClaims as $claim) {
                $groupId=(int)$claim['group']; $memberId=(int)$claim['member']; $productId=(int)$claim['product'];
                $grams=(float)$claim['grams']; $rate=(float)$claim['rate']; $amount=(float)$claim['amount'];
                $claimStmt->bind_param('iiiiiiidddi', $businessId,$branchId,$estimateId,$customerId,$groupId,$memberId,$productId,$grams,$rate,$amount,$userId);
                if (!$claimStmt->execute()) throw new RuntimeException('Unable to save proposed estimate claim: ' . $claimStmt->error);
            }
            $claimStmt->close();
        }

        $conn->commit();
        respond(true, 'Estimate ' . $estimateNo . ' created successfully.', [
            'document_type' => 'Estimate',
            'estimate_id' => $estimateId,
            'estimate_no' => $estimateNo,
            'net_estimate_amount' => $netPayable,
            'proposed_paid_amount' => $paid,
            'proposed_balance_amount' => $balance
        ]);
    }
    $sale = prepareOrFail($conn, 'INSERT INTO sales(business_id,branch_id,invoice_setting_id,invoice_no,invoice_date,invoice_time,customer_id,customer_name,customer_mobile,bill_type,subtotal,discount_amount,taxable_amount,cgst_amount,sgst_amount,igst_amount,round_off,grand_total,exchange_amount,chit_claim_amount,net_payable_amount,paid_amount,balance_amount,payment_status,workflow_status,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,\'Posted\',?,?)', 'Unable to prepare sale insert. Confirm sales.exchange_amount exists');
    $igst = 0.0;
    $storedGrandTotal = $grand + $exchangeTotal + $claimTotal;
    $invoiceSettingId = null;
    $invoiceNo = (string)$number['document_no'];
    $customerName = (string) $customer['customer_name'];
    $customerMobile = (string) $customer['mobile'];

    $sale->bind_param('iiisssisssdddddddddddddssi', $businessId, $branchId, $invoiceSettingId, $invoiceNo, $invoiceDate, $invoiceTime, $customerId, $customerName, $customerMobile, $billType, $subtotal, $discountTotal, $taxable, $cgst, $sgst, $igst, $round, $storedGrandTotal, $exchangeTotal, $claimTotal, $netPayable, $paid, $balance, $paymentStatus, $notes, $userId);
    if (!$sale->execute())
        throw new RuntimeException('Unable to create bill: ' . $sale->error);
    $saleId = (int) $sale->insert_id;
    $sale->close();
    $itemStmt = prepareOrFail($conn, 'INSERT INTO sale_items(business_id,branch_id,sale_id,product_id,item_name,hsn_code,quantity,gross_weight,stone_weight,net_weight,metal_rate,wastage_percent,wastage_amount,making_charge,stone_amount,other_charge,discount_amount,tax_percent,tax_amount,line_total,cost_amount,sort_order) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', 'Unable to prepare sale item insert');
    $stockUp = prepareOrFail($conn, 'UPDATE product_stock SET quantity=quantity-?,gross_weight=GREATEST(0,gross_weight-?),net_weight=GREATEST(0,net_weight-?) WHERE business_id=? AND branch_id=? AND product_id=?', 'Unable to prepare product stock update');
    $move = prepareOrFail($conn, "INSERT INTO stock_movements(business_id,branch_id,product_id,movement_date,movement_type,reference_table,reference_id,quantity_in,quantity_out,weight_in,weight_out,rate,value_amount,remarks,created_by) VALUES(?,?,?,?,'Sale','sales',?,0,?,0,?,?,?,?,?)", 'Unable to prepare stock movement insert');
    foreach ($items as $idx => $it) {
        $p = $it['p'];
        $cost = (float) $p['purchase_rate'] * $it['net'];
        $sort = $idx + 1;
        $itemStmt->bind_param('iiiissdddddddddddddddi', $businessId, $branchId, $saleId, $p['id'], $p['product_name'], $p['hsn_code'], $it['qty'], $it['gross'], $it['stone_weight'], $it['net'], $it['rate'], $it['w'], $it['wamt'], $it['making'], $it['stone'], $it['other'], $it['disc'], $it['tax_percent'], $it['tax'], $it['line'], $cost, $sort);
        $itemStmt->execute();
        if ((int) $p['track_stock'] === 1) {
            $stockUp->bind_param('dddiii', $it['qty'], $it['gross'], $it['net'], $businessId, $branchId, $p['id']);
            $stockUp->execute();
            if ($stockUp->affected_rows < 1)
                throw new RuntimeException('Unable to reduce stock for ' . $p['product_name']);
            $movementDate = $invoiceDate . ' ' . $invoiceTime . ':00';
            $value = $it['line'];
            $remarks = 'Sale ' . $number['document_no'];
            $move->bind_param('iiisiddddsi', $businessId, $branchId, $p['id'], $movementDate, $saleId, $it['qty'], $it['net'], $it['rate'], $value, $remarks, $userId);
            $move->execute();
        }
    }
    $itemStmt->close();
    $stockUp->close();
    $move->close();
    if ($validatedExchange) {
        if (!tableExists($conn, 'exchange_items_stock') || !tableExists($conn, 'sale_exchange_items'))
            throw new RuntimeException('Exchange stock tables are not available. Run migration SQL.');
        $exSale = prepareOrFail($conn, "INSERT INTO sale_exchange_items(business_id,branch_id,sale_id,customer_id,item_name,gross_weight,wastage_percent,eligible_weight,rate_per_gram,exchange_value,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)", 'Unable to prepare sale exchange insert');
        $exStock = prepareOrFail($conn, "INSERT INTO exchange_items_stock(business_id,branch_id,sale_id,customer_id,item_name,gross_weight,wastage_percent,net_weight,rate_per_gram,stock_value,status,received_date,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,'Available',?,?)", 'Unable to prepare exchange stock insert');
        $dt = $invoiceDate . ' ' . $invoiceTime . ':00';
        foreach ($validatedExchange as $ex) {
            $exSale->bind_param('iiiisdddddi', $businessId, $branchId, $saleId, $customerId, $ex['name'], $ex['gross'], $ex['waste'], $ex['eligible'], $ex['rate'], $ex['value'], $userId);
            if (!$exSale->execute())
                throw new RuntimeException('Unable to save exchange item: ' . $exSale->error);
            $exStock->bind_param('iiiisdddddsi', $businessId, $branchId, $saleId, $customerId, $ex['name'], $ex['gross'], $ex['waste'], $ex['eligible'], $ex['rate'], $ex['value'], $dt, $userId);
            if (!$exStock->execute())
                throw new RuntimeException('Unable to add exchange stock: ' . $exStock->error);
        }
        $exSale->close();
        $exStock->close();
    }
    if ($payments) {
        $pm = prepareOrFail(
            $conn,
            'INSERT INTO sale_payments
                (business_id,branch_id,sale_id,payment_method_id,amount,reference_no,payment_date,created_by)
             VALUES(?,?,?,?,?,?,?,?)',
            'Unable to prepare sale payment insert'
        );

        $dt = $invoiceDate . ' ' . $invoiceTime . ':00';

        foreach ($payments as $payment) {
            $method = (int)$payment['method_id'];
            $amount = (float)$payment['amount'];
            $reference = (string)$payment['reference'];

            /*
             * Keep the selected Credit payment in the split-payment history.
             * It does not increase sales.paid_amount; it remains in balance_amount.
             */
            if (!empty($payment['is_credit']) && $reference === '') {
                $reference = 'Outstanding credit';
            }

            $pm->bind_param(
                'iiiidssi',
                $businessId,
                $branchId,
                $saleId,
                $method,
                $amount,
                $reference,
                $dt,
                $userId
            );

            if (!$pm->execute()) {
                throw new RuntimeException('Unable to save payment: ' . $pm->error);
            }
        }

        $pm->close();
    }
    if ($validatedClaims) {
        $cc = prepareOrFail(
            $conn,
            "INSERT INTO sales_chit_claims
                (business_id,branch_id,sale_id,customer_id,chit_group_id,
                 chit_member_id,product_id,claim_grams,rate_per_gram,
                 claim_amount,status,created_by)
             VALUES(?,?,?,?,?,?,?,?,?,?,'Posted',?)",
            'Unable to prepare chit claim insert'
        );

        foreach ($validatedClaims as $claim) {
            $claimGroupId = (int)$claim['group'];
            $claimMemberId = (int)$claim['member'];
            $claimProductId = (int)$claim['product'];
            $claimGrams = round((float)$claim['grams'], 6);
            $claimRate = round((float)$claim['rate'], 2);
            $claimAmount = round((float)$claim['amount'], 2);

            if ($claimGrams <= 0) {
                throw new RuntimeException('Claim grams must be greater than zero.');
            }

            $cc->bind_param(
                'iiiiiiidddi',
                $businessId,
                $branchId,
                $saleId,
                $customerId,
                $claimGroupId,
                $claimMemberId,
                $claimProductId,
                $claimGrams,
                $claimRate,
                $claimAmount,
                $userId
            );

            if (!$cc->execute()) {
                throw new RuntimeException(
                    'Unable to save gold gram claim: ' . $cc->error
                );
            }

            if ($cc->affected_rows !== 1) {
                throw new RuntimeException('Gold gram claim was not inserted.');
            }
        }

        $cc->close();
    }
    $conn->commit();
    respond(true, 'Bill ' . $number['document_no'] . ' created successfully.', [
        'document_type' => 'Invoice',
        'sale_id' => $saleId,
        'invoice_no' => $number['document_no'],
        'net_payable_amount' => $netPayable,
        'received_amount' => $paid,
        'credit_amount' => round($creditAmount, 2),
        'balance_amount' => $balance,
        'payment_status' => $paymentStatus
    ]);
} catch (Throwable $e) {
    $conn->rollback();
    respond(false, $e->getMessage(), [], 422);
}