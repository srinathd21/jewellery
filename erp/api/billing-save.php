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
$nonGstModeActive = (int) ($_SESSION['non_gst_mode'] ?? 0) === 1;
if ($businessId <= 0 || $branchId <= 0)
    respond(false, 'A valid business and branch must be selected.', [], 403);
function tableExists(mysqli $c, string $t): bool
{
    $t = $c->real_escape_string($t);
    $r = $c->query("SHOW TABLES LIKE '{$t}'");
    return $r && $r->num_rows > 0;
}
function columnExists(mysqli $c, string $table, string $column): bool
{
    $table = $c->real_escape_string($table);
    $column = $c->real_escape_string($column);
    $r = $c->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    return $r && $r->num_rows > 0;
}



function billingAuditLog(
    mysqli $c,
    int $businessId,
    int $branchId,
    int $userId,
    string $moduleCode,
    string $actionType,
    string $referenceTable,
    int $referenceId,
    string $description,
    ?array $oldValues = null,
    ?array $newValues = null
): void {
    if (!tableExists($c, 'audit_logs')) {
        throw new RuntimeException('audit_logs table is not available.');
    }

    $oldJson = $oldValues === null
        ? null
        : json_encode($oldValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $newJson = $newValues === null
        ? null
        : json_encode($newValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($oldJson === false) {
        $oldJson = null;
    }

    if ($newJson === false) {
        $newJson = null;
    }

    $ipAddress = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    $userAgent = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));

    $stmt = $c->prepare(
        "INSERT INTO audit_logs
            (
                business_id,
                branch_id,
                user_id,
                module_code,
                action_type,
                reference_table,
                reference_id,
                description,
                old_values_json,
                new_values_json,
                ip_address,
                user_agent
            )
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
    );

    if (!$stmt) {
        throw new RuntimeException(
            'Unable to prepare audit_logs insert: ' . $c->error
        );
    }

    $stmt->bind_param(
        'iiisssisssss',
        $businessId,
        $branchId,
        $userId,
        $moduleCode,
        $actionType,
        $referenceTable,
        $referenceId,
        $description,
        $oldJson,
        $newJson,
        $ipAddress,
        $userAgent
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException(
            'Unable to save audit log: ' . $error
        );
    }

    $stmt->close();
}


function ensureCustomerPaymentLedgerTables(mysqli $c): void
{
    if (!tableExists($c, 'customer_payments')) {
        $sql = "CREATE TABLE `customer_payments` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `business_id` INT NOT NULL,
            `branch_id` INT NOT NULL,
            `customer_id` INT NOT NULL,
            `sale_id` INT NULL,
            `payment_method_id` INT NULL,
            `payment_no` VARCHAR(100) NOT NULL,
            `receipt_no` VARCHAR(100) NULL,
            `receipt_date` DATE NULL,
            `payment_date` DATETIME NOT NULL,
            `amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            `reference_no` VARCHAR(255) NULL,
            `remarks` TEXT NULL,
            `created_by` INT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_customer_payment_no` (`business_id`,`payment_no`),
            KEY `idx_customer_payment_customer` (`business_id`,`customer_id`,`payment_date`),
            KEY `idx_customer_payment_sale` (`business_id`,`sale_id`),
            KEY `idx_customer_payment_branch` (`business_id`,`branch_id`,`payment_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if (!$c->query($sql)) {
            throw new RuntimeException('Unable to create customer_payments table: ' . $c->error);
        }
    }

    /*
     * Existing installations may already have customer_payments but with
     * an older schema. Add every column required by the current billing API.
     */
    $customerPaymentColumns = [
        'business_id' => "INT NOT NULL DEFAULT 0",
        'branch_id' => "INT NOT NULL DEFAULT 0",
        'customer_id' => "INT NOT NULL DEFAULT 0",
        'sale_id' => "INT NULL",
        'payment_method_id' => "INT NULL",
        'payment_no' => "VARCHAR(100) NULL",
        'receipt_no' => "VARCHAR(100) NULL",
        'receipt_date' => "DATE NULL",
        'payment_date' => "DATETIME NULL",
        'amount' => "DECIMAL(18,2) NOT NULL DEFAULT 0.00",
        'reference_no' => "VARCHAR(255) NULL",
        'remarks' => "TEXT NULL",
        'created_by' => "INT NULL",
        'created_at' => "TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP"
    ];

    foreach ($customerPaymentColumns as $column => $definition) {
        if (!columnExists($c, 'customer_payments', $column)) {
            if (!$c->query("ALTER TABLE `customer_payments` ADD COLUMN `{$column}` {$definition}")) {
                throw new RuntimeException(
                    'Unable to upgrade customer_payments.' . $column . ': ' . $c->error
                );
            }
        }
    }

    /*
     * Give older rows a usable payment number before making future values
     * unique/non-empty. This does not overwrite existing payment numbers.
     */
    if (columnExists($c, 'customer_payments', 'payment_no')) {
        $c->query(
            "UPDATE `customer_payments`
             SET `payment_no` = CONCAT('CPY-OLD-', `id`)
             WHERE `payment_no` IS NULL OR TRIM(`payment_no`) = ''"
        );
    }

    if (!tableExists($c, 'customer_payment_splits')) {
        $sql = "CREATE TABLE `customer_payment_splits` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `payment_id` BIGINT UNSIGNED NOT NULL,
            `payment_method_id` INT NOT NULL,
            `amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            `reference_no` VARCHAR(255) NULL,
            `created_by` INT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_customer_split_payment` (`payment_id`),
            KEY `idx_customer_split_method` (`payment_method_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if (!$c->query($sql)) {
            throw new RuntimeException('Unable to create customer_payment_splits table: ' . $c->error);
        }
    }

    $splitColumns = [
        'payment_id' => "BIGINT UNSIGNED NOT NULL DEFAULT 0",
        'payment_method_id' => "INT NOT NULL DEFAULT 0",
        'amount' => "DECIMAL(18,2) NOT NULL DEFAULT 0.00",
        'reference_no' => "VARCHAR(255) NULL",
        'created_by' => "INT NULL",
        'created_at' => "TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP"
    ];

    foreach ($splitColumns as $column => $definition) {
        if (!columnExists($c, 'customer_payment_splits', $column)) {
            if (!$c->query("ALTER TABLE `customer_payment_splits` ADD COLUMN `{$column}` {$definition}")) {
                throw new RuntimeException(
                    'Unable to upgrade customer_payment_splits.' . $column . ': ' . $c->error
                );
            }
        }
    }
}

function ensureSaleExchangePayoutTable(mysqli $c): void
{
    if (tableExists($c, 'sale_exchange_payouts')) {
        return;
    }
    $sql = "CREATE TABLE `sale_exchange_payouts` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `business_id` INT NOT NULL,
        `branch_id` INT NOT NULL,
        `sale_id` INT NOT NULL,
        `customer_id` INT NOT NULL,
        `payment_method_id` INT NOT NULL,
        `amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
        `reference_no` VARCHAR(255) NULL,
        `payout_date` DATETIME NOT NULL,
        `created_by` INT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_exchange_payout_sale` (`business_id`,`sale_id`),
        KEY `idx_exchange_payout_customer` (`business_id`,`customer_id`,`payout_date`),
        KEY `idx_exchange_payout_method` (`payment_method_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$c->query($sql)) {
        throw new RuntimeException('Unable to create sale_exchange_payouts table: ' . $c->error);
    }
}

function billingValidateExchangePayoutMethod(mysqli $c, int $businessId, int $methodId): array
{
    if ($methodId <= 0) {
        throw new RuntimeException('Select Cash / UPI payment method for the balance payable to customer.');
    }
    $hasMethodType = columnExists($c, 'payment_methods', 'method_type');
    $sql = $hasMethodType
        ? 'SELECT id,method_name,method_type FROM payment_methods WHERE id=? AND business_id=? AND is_active=1 LIMIT 1'
        : "SELECT id,method_name,'' AS method_type FROM payment_methods WHERE id=? AND business_id=? AND is_active=1 LIMIT 1";
    $stmt = prepareOrFail($c, $sql, 'Unable to validate exchange payout method');
    $stmt->bind_param('ii', $methodId, $businessId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        throw new RuntimeException('Selected exchange payout method is invalid or inactive.');
    }
    $name = strtolower(trim((string)($row['method_name'] ?? '')));
    $type = strtolower(trim((string)($row['method_type'] ?? '')));
    $isCashOrUpi = in_array($type, ['cash', 'upi'], true) || $name === 'cash' || strpos($name, 'upi') !== false;
    if (!$isCashOrUpi) {
        throw new RuntimeException('Exchange balance payout must use Cash or UPI.');
    }
    return $row;
}

function billingSaveExchangePayout(mysqli $c, int $businessId, int $branchId, int $saleId, int $customerId, int $methodId, float $amount, string $reference, string $invoiceDate, string $invoiceTime, int $userId): void
{
    if ($amount <= 0.005) {
        return;
    }
    ensureSaleExchangePayoutTable($c);
    billingValidateExchangePayoutMethod($c, $businessId, $methodId);
    $dt = $invoiceDate . ' ' . substr($invoiceTime, 0, 5) . ':00';
    $stmt = prepareOrFail($c, 'INSERT INTO sale_exchange_payouts(business_id,branch_id,sale_id,customer_id,payment_method_id,amount,reference_no,payout_date,created_by) VALUES(?,?,?,?,?,?,?,?,?)', 'Unable to save exchange balance payout');
    $stmt->bind_param('iiiiidssi', $businessId, $branchId, $saleId, $customerId, $methodId, $amount, $reference, $dt, $userId);
    if (!$stmt->execute()) {
        throw new RuntimeException('Unable to save exchange balance payout: ' . $stmt->error);
    }
    $stmt->close();
}

function permission(mysqli $c, string $action): bool
{
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin')
        return true;
    $map = ['create' => 'can_create', 'open' => 'can_open', 'update' => 'can_update'];
    $field = $map[$action] ?? '';
    if (!$field)
        return false;
    foreach (['perm.billing.create', 'perm.billing', 'perm.sales', 'perm.sales.list'] as $code) {
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
if (!permission($conn, 'create') && !permission($conn, 'open') && !permission($conn, 'update'))
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

/**
 * Automatically deactivate a stock-tracked product once its TOTAL stock
 * across the business reaches zero. products.is_active is business-wide,
 * so checking all branches prevents one branch with zero stock from hiding
 * a product that still has stock in another branch.
 */
function billingDeactivateProductIfOutOfStock(mysqli $c, int $businessId, int $productId): void
{
    if ($businessId <= 0 || $productId <= 0 || !tableExists($c, 'product_stock')) {
        return;
    }

    $stmt = prepareOrFail(
        $c,
        'SELECT COALESCE(SUM(quantity),0) AS total_qty FROM product_stock WHERE business_id=? AND product_id=?',
        'Unable to check remaining product stock'
    );
    $stmt->bind_param('ii', $businessId, $productId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $totalQty = round((float) ($row['total_qty'] ?? 0), 6);
    if ($totalQty <= 0.000001) {
        $update = prepareOrFail(
            $c,
            'UPDATE products SET is_active=0 WHERE id=? AND business_id=? AND track_stock=1 AND is_active=1',
            'Unable to deactivate out-of-stock product'
        );
        $update->bind_param('ii', $productId, $businessId);
        if (!$update->execute()) {
            $error = $update->error;
            $update->close();
            throw new RuntimeException('Unable to deactivate out-of-stock product: ' . $error);
        }
        $update->close();
    }
}

/**
 * During bill editing the original sold stock is restored first. If that
 * original sale had automatically deactivated the product at zero stock,
 * make it active again while the restored stock is available.
 */
function billingReactivateRestoredProduct(mysqli $c, int $businessId, int $productId): void
{
    if ($businessId <= 0 || $productId <= 0 || !tableExists($c, 'product_stock')) {
        return;
    }

    $stmt = prepareOrFail(
        $c,
        'SELECT COALESCE(SUM(quantity),0) AS total_qty FROM product_stock WHERE business_id=? AND product_id=?',
        'Unable to check restored product stock'
    );
    $stmt->bind_param('ii', $businessId, $productId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ((float) ($row['total_qty'] ?? 0) > 0.000001) {
        $update = prepareOrFail(
            $c,
            'UPDATE products SET is_active=1 WHERE id=? AND business_id=? AND track_stock=1',
            'Unable to reactivate restored product'
        );
        $update->bind_param('ii', $productId, $businessId);
        if (!$update->execute()) {
            $error = $update->error;
            $update->close();
            throw new RuntimeException('Unable to reactivate restored product: ' . $error);
        }
        $update->close();
    }
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
    $documentKey = $documentType === 'Non GST Invoice'
        ? 'non_gst_invoice'
        : strtolower($documentType);
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
    return ['document_no' => renderNo($setting, $seq, $date), 'setting_id' => (int) $setting['id'], 'document_type' => $documentType];
}


function resolveInvoiceSettingId(mysqli $c, int $businessId, int $branchId, string $documentType): ?int
{
    if (!tableExists($c, 'invoice_settings')) {
        return null;
    }

    // sales.invoice_setting_id / estimates.invoice_setting_id reference invoice_settings.id.
    // Do NOT use document_number_settings.id here.
    $invoiceDocumentType = $documentType === 'Non GST Invoice' ? 'Invoice' : $documentType;

    if (!in_array($invoiceDocumentType, ['Invoice', 'Estimate'], true)) {
        return null;
    }

    $stmt = prepareOrFail(
        $c,
        "SELECT id
         FROM invoice_settings
         WHERE business_id=?
           AND document_type=?
           AND is_active=1
           AND (branch_id=? OR branch_id IS NULL)
         ORDER BY (branch_id=?) DESC, is_default DESC, id DESC
         LIMIT 1",
        'Unable to load invoice print setting'
    );

    $stmt->bind_param('isii', $businessId, $invoiceDocumentType, $branchId, $branchId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ? (int) $row['id'] : null;
}

function billingValidatePaymentRows(mysqli $c, int $businessId, array $payMethods, array $payAmounts, array $payRefs, float $netPayable): array
{
    $payments = [];
    $receivedAmount = 0.0;
    $creditAmount = 0.0;
    $splitTotal = 0.0;
    $hasMethodType = columnExists($c, 'payment_methods', 'method_type');
    $sql = $hasMethodType
        ? 'SELECT id,method_name,method_type FROM payment_methods WHERE id=? AND business_id=? AND is_active=1 LIMIT 1'
        : "SELECT id,method_name,'' AS method_type FROM payment_methods WHERE id=? AND business_id=? AND is_active=1 LIMIT 1";
    $stmt = prepareOrFail($c, $sql, 'Unable to prepare payment-method validation');
    foreach ($payMethods as $i => $methodRaw) {
        $method = (int) $methodRaw;
        $amount = round((float) ($payAmounts[$i] ?? 0), 2);
        if ($method <= 0 && $amount <= 0)
            continue;
        if ($method <= 0 || $amount <= 0) {
            $stmt->close();
            throw new RuntimeException('Select a payment method and enter its amount.');
        }
        $stmt->bind_param('ii', $method, $businessId);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Unable to validate payment method: ' . $error);
        }
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) {
            $stmt->close();
            throw new RuntimeException('A selected payment method is invalid or inactive.');
        }
        $methodName = strtolower(trim((string) $row['method_name']));
        $methodType = strtolower(trim((string) ($row['method_type'] ?? '')));
        $isCredit = $methodType === 'credit'
            || strpos($methodName, 'credit') !== false
            || strpos($methodName, 'due') !== false
            || strpos($methodName, 'pay later') !== false
            || strpos($methodName, 'paylater') !== false;
        $reference = trim((string) ($payRefs[$i] ?? ''));
        $payments[] = ['method_id' => $method, 'method_name' => (string) $row['method_name'], 'amount' => $amount, 'reference' => $reference, 'is_credit' => $isCredit];
        $splitTotal += $amount;
        if ($isCredit)
            $creditAmount += $amount;
        else
            $receivedAmount += $amount;
    }
    $stmt->close();
    if ($splitTotal > $netPayable + 0.01)
        throw new RuntimeException('Split payment total cannot exceed the net payable amount.');
    $paid = round($receivedAmount, 2);
    $balance = round(max(0, $netPayable - $paid), 2);
    $paymentStatus = $balance <= 0.01 ? 'Paid' : ($paid > 0 ? 'Partial' : 'Unpaid');
    return ['payments' => $payments, 'split_total' => round($splitTotal, 2), 'received_amount' => $paid, 'credit_amount' => round($creditAmount, 2), 'balance_amount' => $balance, 'payment_status' => $paymentStatus];
}

function billingDeleteCustomerReceiptsForSale(mysqli $c, int $businessId, int $saleId): void
{
    if (!tableExists($c, 'customer_payments'))
        return;
    $ids = [];
    $stmt = prepareOrFail($c, 'SELECT id FROM customer_payments WHERE business_id=? AND sale_id=? FOR UPDATE', 'Unable to load customer receipts for reversal');
    $stmt->bind_param('ii', $businessId, $saleId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc())
        $ids[] = (int) $row['id'];
    $stmt->close();
    if ($ids && tableExists($c, 'customer_payment_splits')) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));
        $values = $ids;
        $delSplit = prepareOrFail($c, "DELETE FROM customer_payment_splits WHERE payment_id IN ($placeholders)", 'Unable to reverse customer payment splits');
        bindDynamic($delSplit, $types, $values);
        if (!$delSplit->execute())
            throw new RuntimeException('Unable to reverse customer payment splits: ' . $delSplit->error);
        $delSplit->close();
    }
    $del = prepareOrFail($c, 'DELETE FROM customer_payments WHERE business_id=? AND sale_id=?', 'Unable to reverse customer payment receipts');
    $del->bind_param('ii', $businessId, $saleId);
    if (!$del->execute())
        throw new RuntimeException('Unable to reverse customer payment receipts: ' . $del->error);
    $del->close();
}

function billingSaveSalePayments(mysqli $c, int $businessId, int $branchId, int $saleId, string $invoiceDate, string $invoiceTime, int $userId, array $payments): void
{
    if (!$payments)
        return;
    $stmt = prepareOrFail($c, 'INSERT INTO sale_payments (business_id,branch_id,sale_id,payment_method_id,amount,reference_no,payment_date,created_by) VALUES(?,?,?,?,?,?,?,?)', 'Unable to prepare sale payment insert');
    $dt = $invoiceDate . ' ' . substr($invoiceTime, 0, 5) . ':00';
    foreach ($payments as $payment) {
        $method = (int) $payment['method_id'];
        $amount = (float) $payment['amount'];
        $reference = (string) $payment['reference'];
        if (!empty($payment['is_credit']) && $reference === '')
            $reference = 'Outstanding credit';
        $stmt->bind_param('iiiidssi', $businessId, $branchId, $saleId, $method, $amount, $reference, $dt, $userId);
        if (!$stmt->execute())
            throw new RuntimeException('Unable to save payment: ' . $stmt->error);
    }
    $stmt->close();
}

function billingSaveCustomerReceipt(mysqli $c, int $businessId, int $branchId, int $customerId, int $saleId, string $invoiceNo, string $invoiceDate, string $invoiceTime, float $receivedAmount, array $payments, string $notes, int $userId): void
{
    if ($receivedAmount <= 0.001)
        return;
    ensureCustomerPaymentLedgerTables($c);
    $primaryMethodId = 0;
    foreach ($payments as $payment) {
        if (!empty($payment['is_credit']))
            continue;
        if ((int) $payment['method_id'] > 0 && (float) $payment['amount'] > 0) {
            $primaryMethodId = (int) $payment['method_id'];
            break;
        }
    }
    if ($primaryMethodId <= 0)
        throw new RuntimeException('Unable to determine the received payment method for the customer ledger.');
    $tmp = 'TMP-' . $businessId . '-' . bin2hex(random_bytes(8));
    $paymentDateTime = $invoiceDate . ' ' . substr($invoiceTime, 0, 5) . ':00';
    $ledgerReference = 'Sale ' . $invoiceNo;
    $columns = ['business_id', 'branch_id', 'customer_id', 'sale_id'];
    $types = 'iiii';
    $values = [$businessId, $branchId, $customerId, $saleId];
    if (columnExists($c, 'customer_payments', 'payment_method_id')) {
        $columns[] = 'payment_method_id';
        $types .= 'i';
        $values[] = $primaryMethodId;
    }
    if (columnExists($c, 'customer_payments', 'payment_no')) {
        $columns[] = 'payment_no';
        $types .= 's';
        $values[] = $tmp;
    }
    if (columnExists($c, 'customer_payments', 'receipt_no')) {
        $columns[] = 'receipt_no';
        $types .= 's';
        $values[] = $tmp;
    }
    if (columnExists($c, 'customer_payments', 'receipt_date')) {
        $columns[] = 'receipt_date';
        $types .= 's';
        $values[] = $invoiceDate;
    }
    if (columnExists($c, 'customer_payments', 'payment_date')) {
        $columns[] = 'payment_date';
        $types .= 's';
        $values[] = $paymentDateTime;
    }
    $columns[] = 'amount';
    $types .= 'd';
    $values[] = $receivedAmount;
    $columns[] = 'reference_no';
    $types .= 's';
    $values[] = $ledgerReference;
    $columns[] = 'remarks';
    $types .= 's';
    $values[] = $notes;
    $columns[] = 'created_by';
    $types .= 'i';
    $values[] = $userId;
    $placeholders = implode(',', array_fill(0, count($columns), '?'));
    $stmt = prepareOrFail($c, 'INSERT INTO customer_payments (' . implode(',', $columns) . ') VALUES (' . $placeholders . ')', 'Unable to prepare customer payment ledger insert');
    bindDynamic($stmt, $types, $values);
    if (!$stmt->execute())
        throw new RuntimeException('Unable to save customer payment ledger: ' . $stmt->error);
    $paymentId = (int) $stmt->insert_id;
    $stmt->close();
    $paymentNo = 'CPY/' . date('Ym', strtotime($invoiceDate)) . '/' . str_pad((string) $paymentId, 6, '0', STR_PAD_LEFT);
    if (columnExists($c, 'customer_payments', 'payment_no') && columnExists($c, 'customer_payments', 'receipt_no')) {
        $u = prepareOrFail($c, 'UPDATE customer_payments SET payment_no=?,receipt_no=? WHERE id=?', 'Unable to update customer payment number');
        $u->bind_param('ssi', $paymentNo, $paymentNo, $paymentId);
    } elseif (columnExists($c, 'customer_payments', 'payment_no')) {
        $u = prepareOrFail($c, 'UPDATE customer_payments SET payment_no=? WHERE id=?', 'Unable to update customer payment number');
        $u->bind_param('si', $paymentNo, $paymentId);
    } else {
        $u = null;
    }
    if ($u) {
        if (!$u->execute())
            throw new RuntimeException('Unable to update customer payment number: ' . $u->error);
        $u->close();
    }
    if (tableExists($c, 'customer_payment_splits')) {
        $split = prepareOrFail($c, 'INSERT INTO customer_payment_splits(payment_id,payment_method_id,amount,reference_no,created_by) VALUES(?,?,?,?,?)', 'Unable to prepare customer payment split insert');
        foreach ($payments as $payment) {
            if (!empty($payment['is_credit']))
                continue;
            $methodId = (int) $payment['method_id'];
            $amount = (float) $payment['amount'];
            $reference = (string) $payment['reference'];
            $split->bind_param('iidsi', $paymentId, $methodId, $amount, $reference, $userId);
            if (!$split->execute())
                throw new RuntimeException('Unable to save customer payment split: ' . $split->error);
        }
        $split->close();
    }
}

$action = (string) ($_POST['action'] ?? 'save');
if ($action === 'preview_number') {
    $documentType = (string) ($_POST['document_type'] ?? 'Invoice');
    if (!in_array($documentType, ['Invoice', 'Estimate', 'Non GST Invoice'], true)) {
        respond(false, 'Invalid document type.', [], 422);
    }
    $documentDate = (string) ($_POST['document_date'] ?? date('Y-m-d'));
    $dateObject = DateTime::createFromFormat('Y-m-d', $documentDate);
    if (!$dateObject || $dateObject->format('Y-m-d') !== $documentDate) {
        respond(false, 'Valid document date is required.', [], 422);
    }
    try {
        $documentKey = $documentType === 'Non GST Invoice'
            ? 'non_gst_invoice'
            : strtolower($documentType);
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
        if (!$setting)
            throw new RuntimeException('Configure ' . $documentType . ' numbering in Master Control first.');
        $key = periodKey((string) $setting['reset_frequency'], $documentDate);
        $q = prepareOrFail($conn, 'SELECT current_number FROM number_sequences WHERE business_id=? AND branch_id=? AND document_type=? AND period_key=? LIMIT 1', 'Unable to load number sequence');
        $q->bind_param('iiss', $businessId, $branchId, $documentType, $key);
        $q->execute();
        $row = $q->get_result()->fetch_assoc();
        $q->close();
        $seq = $row ? ((int) $row['current_number'] + 1) : max(1, (int) ($setting['sequence_start'] ?? 1));
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
        billingAuditLog(
            $conn,
            $businessId,
            $branchId,
            $userId,
            'customers',
            'Create',
            'customers',
            $id,
            'Created billing customer ' . $name,
            null,
            [
                'customer_code' => $code,
                'customer_name' => $name,
                'mobile' => $mobile,
                'email' => $email,
                'gstin' => $gstin,
                'service_type' => 'Billing'
            ]
        );

        $conn->commit();
        respond(true, 'Customer created successfully.', ['customer' => ['id' => $id, 'customer_code' => $code, 'customer_name' => $name, 'mobile' => $mobile]]);
    } catch (Throwable $e) {
        $conn->rollback();
        respond(false, 'Unable to create customer: ' . $e->getMessage(), [], 500);
    }
}
if ($action === 'update') {
    if (!permission($conn, 'update') && !permission($conn, 'create'))
        respond(false, 'You do not have permission to edit bills.', [], 403);
    $saleId = max(0, (int) ($_POST['edit_sale_id'] ?? 0));
    if ($saleId <= 0)
        respond(false, 'Invalid invoice selected.', [], 422);
    $invoiceDate = trim((string) ($_POST['invoice_date'] ?? ''));
    $invoiceTime = trim((string) ($_POST['invoice_time'] ?? ''));
    $customerId = max(0, (int) ($_POST['customer_id'] ?? 0));
    $submittedBillType = trim((string) ($_POST['bill_type'] ?? 'Retail'));
    $overall = max(0, (float) ($_POST['overall_discount'] ?? 0));
    $round = (float) ($_POST['round_off'] ?? 0);
    $notes = trim((string) ($_POST['notes'] ?? ''));
    $productIds = $_POST['product_id'] ?? [];
    $qtys = $_POST['quantity'] ?? [];
    $rates = $_POST['metal_rate'] ?? [];
    $wastages = $_POST['wastage_percent'] ?? [];
    $taxPercents = $_POST['tax_percent'] ?? [];
    $makings = $_POST['making_charge'] ?? [];
    $stones = $_POST['stone_amount'] ?? [];
    $others = $_POST['other_charge'] ?? [];
    $discounts = $_POST['item_discount'] ?? [];
    $dynamicGrossWeights = $_POST['dynamic_gross_weight'] ?? [];
    $exchangePayoutMethodId = (int)($_POST['exchange_payout_payment_method_id'] ?? 0);
    $exchangePayoutReference = trim((string)($_POST['exchange_payout_reference'] ?? ''));
    $claims = json_decode((string) ($_POST['chit_claims_json'] ?? '[]'), true);
    if (!is_array($claims))
        $claims = [];
    $exchangeItemsInput = json_decode((string) ($_POST['exchange_items_json'] ?? '[]'), true);
    if (!is_array($exchangeItemsInput))
        $exchangeItemsInput = [];
    $payMethods = $_POST['payment_method_id'] ?? [];
    $payAmounts = $_POST['payment_amount'] ?? [];
    $payRefs = $_POST['payment_reference'] ?? [];
    if (!is_array($payMethods))
        $payMethods = [];
    if (!is_array($payAmounts))
        $payAmounts = [];
    if (!is_array($payRefs))
        $payRefs = [];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $invoiceDate))
        respond(false, 'Valid invoice date is required.', [], 422);
    if (!preg_match('/^\d{2}:\d{2}/', $invoiceTime))
        respond(false, 'Valid invoice time is required.', [], 422);
    if ($customerId <= 0)
        respond(false, 'Please select a customer.', [], 422);
    if (!is_array($productIds) || count($productIds) < 1)
        respond(false, 'Add at least one product.', [], 422);
    $conn->begin_transaction();
    try {
        $s = prepareOrFail($conn, 'SELECT * FROM sales WHERE id=? AND business_id=? AND branch_id=? LIMIT 1 FOR UPDATE', 'Unable to load invoice for edit');
        $s->bind_param('iii', $saleId, $businessId, $branchId);
        $s->execute();
        $oldSale = $s->get_result()->fetch_assoc();
        $s->close();
        if (!$oldSale)
            throw new RuntimeException('Invoice was not found.');
        if ((string) $oldSale['workflow_status'] === 'Cancelled')
            throw new RuntimeException('Cancelled invoices cannot be edited.');
        $editNonGst = strtolower(trim((string) ($oldSale['tax_type'] ?? 'GST'))) === 'non-gst';
        $taxType = $editNonGst ? 'Non-GST' : 'GST';
        if (strtolower($submittedBillType) === 'estimate')
            throw new RuntimeException('A posted sale cannot be converted to an Estimate from Edit Bill.');
        $billType = $editNonGst ? (strtolower($submittedBillType) === 'exchange' ? 'Exchange' : 'Retail') : (in_array($submittedBillType, ['Retail', 'GST', 'Exchange'], true) ? $submittedBillType : 'Retail');
        $cs = prepareOrFail($conn, 'SELECT id,customer_name,mobile FROM customers WHERE id=? AND business_id=? AND is_active=1 LIMIT 1', 'Unable to validate customer');
        $cs->bind_param('ii', $customerId, $businessId);
        $cs->execute();
        $customer = $cs->get_result()->fetch_assoc();
        $cs->close();
        if (!$customer)
            throw new RuntimeException('Selected customer is invalid.');

        $oldItems = [];
        $os = prepareOrFail($conn, 'SELECT si.*,p.track_stock,' . (columnExists($conn, 'products', 'dynamic_stock') ? 'COALESCE(p.dynamic_stock,0)' : '0') . ' AS dynamic_stock FROM sale_items si LEFT JOIN products p ON p.id=si.product_id AND p.business_id=si.business_id WHERE si.sale_id=? AND si.business_id=? ORDER BY si.id FOR UPDATE', 'Unable to load existing invoice items');
        $os->bind_param('ii', $saleId, $businessId);
        $os->execute();
        $or = $os->get_result();
        while ($x = $or->fetch_assoc())
            $oldItems[] = $x;
        $os->close();
        $oldExchangeById = [];
        if (tableExists($conn, 'sale_exchange_items')) {
            $oe = prepareOrFail($conn, 'SELECT * FROM sale_exchange_items WHERE sale_id=? AND business_id=? ORDER BY id FOR UPDATE', 'Unable to load existing exchange items');
            $oe->bind_param('ii', $saleId, $businessId);
            $oe->execute();
            $rr = $oe->get_result();
            while ($x = $rr->fetch_assoc())
                $oldExchangeById[(int) $x['id']] = $x;
            $oe->close();
        }
        if (tableExists($conn, 'exchange_items_stock')) {
            $ss = prepareOrFail($conn, 'SELECT status FROM exchange_items_stock WHERE sale_id=? AND business_id=? FOR UPDATE', 'Unable to validate exchange stock status');
            $ss->bind_param('ii', $saleId, $businessId);
            $ss->execute();
            $rr = $ss->get_result();
            while ($x = $rr->fetch_assoc()) {
                if (strtolower((string) $x['status']) !== 'available') {
                    $ss->close();
                    throw new RuntimeException('Old-gold exchange from this invoice has already been processed. Reverse that exchange workflow before editing this bill.');
                }
            }
            $ss->close();
        }

        $restore = prepareOrFail($conn, "INSERT INTO product_stock (business_id,branch_id,product_id,quantity,gross_weight,net_weight,average_cost,stock_value) VALUES (?,?,?,?,?,?,0,0) ON DUPLICATE KEY UPDATE quantity=quantity+VALUES(quantity),gross_weight=gross_weight+VALUES(gross_weight),net_weight=net_weight+VALUES(net_weight)", 'Unable to prepare edit stock restoration');
        $moveIn = prepareOrFail($conn, "INSERT INTO stock_movements(business_id,branch_id,product_id,movement_date,movement_type,reference_table,reference_id,quantity_in,quantity_out,weight_in,weight_out,rate,value_amount,remarks,created_by) VALUES(?,?,?,?,'Adjustment In','sales',?,?,0,?,0,?,?,?,?)", 'Unable to prepare edit restoration movement');
        $reverseDate = date('Y-m-d H:i:s');
        foreach ($oldItems as $old) {
            if ((int) ($old['track_stock'] ?? 0) !== 1 || (int) $old['product_id'] <= 0)
                continue;
            $pid = (int) $old['product_id'];
            $q = (float) $old['quantity'];
            $g = (float) $old['gross_weight'];
            $n = (float) $old['net_weight'];
            $isDynamicOld = (int)($old['dynamic_stock'] ?? 0) === 1;
            $restoreNet = $isDynamicOld ? 0.0 : $n;
            $restore->bind_param('iiiddd', $businessId, $branchId, $pid, $q, $g, $restoreNet);
            if (!$restore->execute())
                throw new RuntimeException('Unable to restore old stock: ' . $restore->error);
            billingReactivateRestoredProduct($conn, $businessId, $pid);
            $rate = (float) $old['metal_rate'];
            $value = (float) $old['line_total'];
            $remarks = 'Invoice edit reverse ' . $oldSale['invoice_no'];
            $restoreMovementWeight = $isDynamicOld ? $g : $n;
            $moveIn->bind_param('iiisiddddsi', $businessId, $branchId, $pid, $reverseDate, $saleId, $q, $restoreMovementWeight, $rate, $value, $remarks, $userId);
            if (!$moveIn->execute())
                throw new RuntimeException('Unable to log stock reversal: ' . $moveIn->error);
        }
        $restore->close();
        $moveIn->close();
        billingDeleteCustomerReceiptsForSale($conn, $businessId, $saleId);
        $dp = prepareOrFail($conn, 'DELETE FROM sale_payments WHERE sale_id=? AND business_id=?', 'Unable to reverse old sale payments');
        $dp->bind_param('ii', $saleId, $businessId);
        if (!$dp->execute())
            throw new RuntimeException($dp->error);
        $dp->close();
        if (tableExists($conn, 'sales_chit_claims')) {
            $cc = prepareOrFail($conn, "UPDATE sales_chit_claims SET status='Cancelled',cancelled_by=?,cancelled_at=NOW() WHERE sale_id=? AND business_id=? AND status='Posted'", 'Unable to reverse old chit claims');
            $cc->bind_param('iii', $userId, $saleId, $businessId);
            if (!$cc->execute())
                throw new RuntimeException($cc->error);
            $cc->close();
        }
        if (tableExists($conn, 'sale_exchange_payouts')) {
            $de = prepareOrFail($conn, 'DELETE FROM sale_exchange_payouts WHERE sale_id=? AND business_id=?', 'Unable to reverse exchange balance payout');
            $de->bind_param('ii', $saleId, $businessId);
            if (!$de->execute())
                throw new RuntimeException($de->error);
            $de->close();
        }
        if (tableExists($conn, 'sale_exchange_items')) {
            $de = prepareOrFail($conn, 'DELETE FROM sale_exchange_items WHERE sale_id=? AND business_id=?', 'Unable to reverse sale exchange');
            $de->bind_param('ii', $saleId, $businessId);
            if (!$de->execute())
                throw new RuntimeException($de->error);
            $de->close();
        }
        if (tableExists($conn, 'exchange_items_stock')) {
            $de = prepareOrFail($conn, 'DELETE FROM exchange_items_stock WHERE sale_id=? AND business_id=?', 'Unable to reverse exchange stock');
            $de->bind_param('ii', $saleId, $businessId);
            if (!$de->execute())
                throw new RuntimeException($de->error);
            $de->close();
        }
        $di = prepareOrFail($conn, 'DELETE FROM sale_items WHERE sale_id=? AND business_id=?', 'Unable to replace old invoice items');
        $di->bind_param('ii', $saleId, $businessId);
        if (!$di->execute())
            throw new RuntimeException($di->error);
        $di->close();

        $items = [];
        $subtotal = 0.0;
        $itemDiscount = 0.0;
        $taxable = 0.0;
        $taxTotal = 0.0;
        $requested = [];
        $available = [];
        $tracked = [];
        $requestedDynamicGross = [];
        $availableDynamicGross = [];
        $dynamicTracked = [];
        $ps = prepareOrFail($conn, 'SELECT p.*,COALESCE(ps.quantity,0) stock_qty,COALESCE(ps.gross_weight,0) stock_gross,COALESCE(ps.net_weight,0) stock_net,COALESCE(mr.rate_per_gram,p.sale_rate,0) live_metal_rate FROM products p LEFT JOIN product_stock ps ON ps.product_id=p.id AND ps.business_id=p.business_id AND ps.branch_id=? LEFT JOIN metal_rates mr ON mr.id=(SELECT mr2.id FROM metal_rates mr2 WHERE mr2.business_id=p.business_id AND mr2.metal_id=p.metal_id AND mr2.is_current=1 AND (mr2.branch_id=? OR mr2.branch_id IS NULL) ORDER BY (mr2.branch_id=?) DESC,mr2.effective_from DESC,mr2.id DESC LIMIT 1) WHERE p.id=? AND p.business_id=? AND p.is_active=1 LIMIT 1 FOR UPDATE', 'Unable to prepare product lookup');
        foreach ($productIds as $i => $raw) {
            $pid = (int) $raw;
            if ($pid <= 0)
                continue;
            $q = round((float) ($qtys[$i] ?? 0), 3);
            if ($q <= 0)
                throw new RuntimeException('Quantity must be greater than zero.');
            $ps->bind_param('iiiii', $branchId, $branchId, $branchId, $pid, $businessId);
            $ps->execute();
            $p = $ps->get_result()->fetch_assoc();
            if (!$p)
                throw new RuntimeException('A selected product is invalid.');
            $pt = columnExists($conn, 'products', 'tax_type') ? strtolower(trim((string) ($p['tax_type'] ?? 'GST'))) : (((float) ($p['tax_percent'] ?? 0) <= 0) ? 'non-gst' : 'gst');
            $pNon = in_array($pt, ['non-gst', 'non gst'], true);
            if ($editNonGst && !$pNon)
                throw new RuntimeException($p['product_name'] . ' is not a Non-GST product.');
            if (!$editNonGst && $pNon)
                throw new RuntimeException($p['product_name'] . ' is available only in Non-GST mode.');
            $requested[$pid] = ($requested[$pid] ?? 0) + $q;
            $available[$pid] = (float) $p['stock_qty'];
            $tracked[$pid] = (int) $p['track_stock'] === 1;
            $submitted = max(0, (float) ($rates[$i] ?? 0));
            $rate = $submitted > 0 ? $submitted : max(0, (float) ($p['live_metal_rate'] ?? $p['sale_rate'] ?? 0));
            $w = max(0, (float) ($wastages[$i] ?? $p['wastage_percent']));
            $mk = max(0, (float) ($makings[$i] ?? $p['making_charge']));
            $stone = max(0, (float) ($stones[$i] ?? 0));
            $other = max(0, (float) ($others[$i] ?? 0));
            $disc = max(0, (float) ($discounts[$i] ?? 0));
            $taxPct = $editNonGst ? 0.0 : max(0, min(100, (float) ($taxPercents[$i] ?? $p['tax_percent'] ?? 0)));
            $isDynamicStock = (int)($p['dynamic_stock'] ?? 0) === 1;
            $postedDynamicGross = round(max(0, (float)($dynamicGrossWeights[$i] ?? 0)), 3);
            if ($isDynamicStock && $postedDynamicGross <= 0) {
                throw new RuntimeException('Enter the actual gross weight for dynamic stock product ' . $p['product_name'] . '.');
            }
            $gross = $isDynamicStock ? $postedDynamicGross : (float) $p['gross_weight'] * $q;
            $stoneWeight = (float) $p['stone_weight'] * $q;
            $net = (float) $p['net_weight'] * $q;
            if ($isDynamicStock) {
                $requestedDynamicGross[$pid] = ($requestedDynamicGross[$pid] ?? 0) + $gross;
                $availableDynamicGross[$pid] = (float)$p['stock_gross'];
                $dynamicTracked[$pid] = (int)$p['track_stock'] === 1;
            }
            $metal = $gross > 0 ? $gross * $rate : $q * $rate;
            $making = $gross > 0 ? $gross * $mk : $q * $mk;
            $base = $metal + $making;
            $wamt = $base * $w / 100;
            $rowSub = $base + $wamt + $stone + $other;
            $rowTaxable = max(0, $rowSub - $disc);
            $tax = $rowTaxable * $taxPct / 100;
            $line = $rowTaxable + $tax;
            $items[] = ['p' => $p, 'qty' => $q, 'gross' => $gross, 'stone_weight' => $stoneWeight, 'net' => $net, 'stock_gross_out' => $gross, 'stock_net_out' => $isDynamicStock ? 0.0 : $net, 'movement_weight' => $isDynamicStock ? $gross : $net, 'is_dynamic_stock' => $isDynamicStock, 'rate' => $rate, 'w' => $w, 'wamt' => $wamt, 'making' => $making, 'stone' => $stone, 'other' => $other, 'disc' => $disc, 'tax_percent' => $taxPct, 'tax' => $tax, 'line' => $line];
            $subtotal += $rowSub;
            $itemDiscount += $disc;
            $taxable += $rowTaxable;
            $taxTotal += $tax;
        }
        $ps->close();
        if (!$items)
            throw new RuntimeException('Add at least one valid product.');
        foreach ($requested as $pid => $q) {
            if (!empty($tracked[$pid]) && ($available[$pid] ?? 0) + 0.0001 < $q) {
                $name = 'Product';
                foreach ($items as $it)
                    if ((int) $it['p']['id'] === (int) $pid) {
                        $name = (string) $it['p']['product_name'];
                        break;
                    }
                throw new RuntimeException($name . ' has only ' . number_format((float) ($available[$pid] ?? 0), 3) . ' stock available.');
            }
        }
        foreach ($requestedDynamicGross as $pid => $grossRequested) {
            if (!empty($dynamicTracked[$pid]) && ($availableDynamicGross[$pid] ?? 0) + 0.0001 < $grossRequested) {
                $name = 'Product';
                foreach ($items as $it) {
                    if ((int)$it['p']['id'] === (int)$pid) { $name = (string)$it['p']['product_name']; break; }
                }
                throw new RuntimeException($name . ' has only ' . number_format((float)($availableDynamicGross[$pid] ?? 0), 3) . ' g gross stock available.');
            }
        }
        $taxable = max(0, $taxable - $overall);
        $discountTotal = $itemDiscount + $overall;
        $cgst = $editNonGst ? 0.0 : $taxTotal / 2;
        $sgst = $editNonGst ? 0.0 : $taxTotal / 2;
        $igst = 0.0;
        $grossGrand = max(0, $taxable + $cgst + $sgst + $round);

        $exchangeTotal = 0.0;
        $validatedExchange = [];
        foreach ($exchangeItemsInput as $ex) {
            $name = trim((string) ($ex['item_name'] ?? ''));
            $metalId = (int) ($ex['metal_id'] ?? 0);
            $gross = round((float) ($ex['gross_weight'] ?? 0), 3);
            $waste = max(0, min(100, (float) ($ex['wastage_percent'] ?? 0)));
            if ($name === '' || $metalId <= 0 || $gross <= 0)
                continue;
            $ms = prepareOrFail($conn, "SELECT m.metal_name,COALESCE((SELECT mr.rate_per_gram FROM metal_rates mr WHERE mr.business_id=? AND mr.metal_id=m.id AND mr.is_current=1 AND (mr.branch_id=? OR mr.branch_id IS NULL) ORDER BY (mr.branch_id=?) DESC,mr.effective_from DESC,mr.id DESC LIMIT 1),0) current_rate FROM metals m WHERE m.id=? AND m.business_id=? LIMIT 1", 'Unable to validate exchange metal');
            $ms->bind_param('iiiii', $businessId, $branchId, $branchId, $metalId, $businessId);
            $ms->execute();
            $mr = $ms->get_result()->fetch_assoc();
            $ms->close();
            if (!$mr || (float) $mr['current_rate'] <= 0)
                throw new RuntimeException('Selected exchange metal does not have a valid current rate.');
            $existingId = (int) ($ex['id'] ?? 0);
            $submittedRate = max(0, (float) ($ex['rate_per_gram'] ?? 0));
            $rate = ($existingId > 0 && isset($oldExchangeById[$existingId]) && $submittedRate > 0) ? $submittedRate : (float) $mr['current_rate'];
            $eligible = round($gross * (1 - $waste / 100), 3);
            $calc = round($eligible * $rate, 2);
            $entered = round(max(0, (float) ($ex['exchange_value'] ?? 0)), 2);
            $value = $entered > 0 ? $entered : $calc;
            if ($eligible <= 0 || $value <= 0)
                throw new RuntimeException('Invalid exchange item weight or value.');
            $exchangeTotal += $value;
            $validatedExchange[] = ['name' => $name, 'gross' => $gross, 'waste' => $waste, 'eligible' => $eligible, 'rate' => $rate, 'value' => $value];
        }
        $exchangePayoutAmount = round(max(0, $exchangeTotal - $grossGrand), 2);
        $afterExchange = max(0, $grossGrand - $exchangeTotal);
        if ($exchangePayoutAmount > 0.005) {
            billingValidateExchangePayoutMethod($conn, $businessId, $exchangePayoutMethodId);
        }
        $claimTotal = 0.0;
        $validatedClaims = [];
        if ($claims) {
            if (!tableExists($conn, 'sales_chit_claims'))
                throw new RuntimeException('Chit claim table is not available.');
            $cq = prepareOrFail($conn, "SELECT cm.chit_group_id,GREATEST(0,COALESCE((SELECT SUM(cc.gold_weight_grams) FROM chit_collections cc WHERE cc.business_id=cm.business_id AND cc.chit_member_id=cm.id),0)-COALESCE((SELECT SUM(scc.claim_grams) FROM sales_chit_claims scc WHERE scc.business_id=cm.business_id AND scc.chit_member_id=cm.id AND scc.status='Posted'),0)) available_grams FROM chit_members cm WHERE cm.id=? AND cm.business_id=? AND cm.customer_id=? LIMIT 1 FOR UPDATE", 'Unable to prepare claim balance');
            foreach ($claims as $c) {
                $mid = (int) ($c['chit_member_id'] ?? 0);
                $grams = round((float) ($c['claim_grams'] ?? 0), 6);
                $productId = (int) ($c['product_id'] ?? 0);
                if ($mid <= 0 || $grams <= 0 || $productId <= 0)
                    continue;
                $cq->bind_param('iii', $mid, $businessId, $customerId);
                $cq->execute();
                $x = $cq->get_result()->fetch_assoc();
                if (!$x)
                    throw new RuntimeException('Selected chit membership is invalid for this customer.');
                $avail = round(max(0, (float) $x['available_grams']), 6);
                if ($grams > $avail + 0.000001)
                    throw new RuntimeException('Gold gram claim exceeds available grams. Available: ' . number_format($avail, 6) . ' g.');
                $rq = prepareOrFail($conn, "SELECT p.id,COALESCE(mr.rate_per_gram,p.sale_rate,0) rate_per_gram FROM products p LEFT JOIN metal_rates mr ON mr.id=(SELECT mr2.id FROM metal_rates mr2 WHERE mr2.business_id=p.business_id AND mr2.metal_id=p.metal_id AND mr2.is_current=1 AND (mr2.branch_id=? OR mr2.branch_id IS NULL) ORDER BY (mr2.branch_id=?) DESC,mr2.effective_from DESC,mr2.id DESC LIMIT 1) WHERE p.id=? AND p.business_id=? LIMIT 1", 'Unable to prepare claim rate');
                $rq->bind_param('iiii', $branchId, $branchId, $productId, $businessId);
                $rq->execute();
                $pr = $rq->get_result()->fetch_assoc();
                $rq->close();
                if (!$pr || (float) $pr['rate_per_gram'] <= 0)
                    throw new RuntimeException('Selected claim product has no valid rate.');
                $rate = (float) $pr['rate_per_gram'];
                $amt = round($grams * $rate, 2);
                $claimTotal += $amt;
                $validatedClaims[] = ['member' => $mid, 'group' => (int) $x['chit_group_id'], 'product' => $productId, 'grams' => $grams, 'rate' => $rate, 'amount' => $amt];
            }
            $cq->close();
        }
        if ($claimTotal > $afterExchange + 0.001)
            throw new RuntimeException('Chit claim cannot exceed the bill total after exchange.');
        $netPayable = max(0, $afterExchange - $claimTotal);
        $payResult = billingValidatePaymentRows($conn, $businessId, $payMethods, $payAmounts, $payRefs, $netPayable);
        $payments = $payResult['payments'];
        $paid = (float) $payResult['received_amount'];
        $creditAmount = (float) $payResult['credit_amount'];
        $balance = (float) $payResult['balance_amount'];
        $paymentStatus = (string) $payResult['payment_status'];

        $is = prepareOrFail($conn, 'INSERT INTO sale_items(business_id,branch_id,sale_id,product_id,item_name,hsn_code,quantity,gross_weight,stone_weight,net_weight,metal_rate,wastage_percent,wastage_amount,making_charge,stone_amount,other_charge,discount_amount,tax_percent,tax_amount,line_total,cost_amount,sort_order) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', 'Unable to save edited items');
        $sd = prepareOrFail($conn, 'UPDATE product_stock SET quantity=quantity-?,gross_weight=GREATEST(0,gross_weight-?),net_weight=GREATEST(0,net_weight-?) WHERE business_id=? AND branch_id=? AND product_id=? AND quantity+0.0001>=?', 'Unable to deduct stock');
        $mo = prepareOrFail($conn, "INSERT INTO stock_movements(business_id,branch_id,product_id,movement_date,movement_type,reference_table,reference_id,quantity_in,quantity_out,weight_in,weight_out,rate,value_amount,remarks,created_by) VALUES(?,?,?,?,'Sale','sales',?,0,?,0,?,?,?,?,?)", 'Unable to log stock');
        $movementDate = $invoiceDate . ' ' . substr($invoiceTime, 0, 5) . ':00';
        foreach ($items as $idx => $it) {
            $p = $it['p'];
            $cost = (float) $p['purchase_rate'] * $it['net'];
            $sort = $idx + 1;
            $is->bind_param('iiiissdddddddddddddddi', $businessId, $branchId, $saleId, $p['id'], $p['product_name'], $p['hsn_code'], $it['qty'], $it['gross'], $it['stone_weight'], $it['net'], $it['rate'], $it['w'], $it['wamt'], $it['making'], $it['stone'], $it['other'], $it['disc'], $it['tax_percent'], $it['tax'], $it['line'], $cost, $sort);
            if (!$is->execute())
                throw new RuntimeException($is->error);
            if ((int) $p['track_stock'] === 1) {
                $sd->bind_param('dddiiid', $it['qty'], $it['stock_gross_out'], $it['stock_net_out'], $businessId, $branchId, $p['id'], $it['qty']);
                if (!$sd->execute() || $sd->affected_rows < 1)
                    throw new RuntimeException('Unable to deduct stock for ' . $p['product_name']);
                $value = $it['line'];
                $remarks = 'Edited invoice ' . $oldSale['invoice_no'];
                $mo->bind_param('iiisiddddsi', $businessId, $branchId, $p['id'], $movementDate, $saleId, $it['qty'], $it['movement_weight'], $it['rate'], $value, $remarks, $userId);
                if (!$mo->execute())
                    throw new RuntimeException($mo->error);
                billingDeactivateProductIfOutOfStock($conn, $businessId, (int) $p['id']);
            }
        }
        $is->close();
        $sd->close();
        $mo->close();
        if ($validatedExchange) {
            $es = prepareOrFail($conn, 'INSERT INTO sale_exchange_items(business_id,branch_id,sale_id,customer_id,item_name,gross_weight,wastage_percent,eligible_weight,rate_per_gram,exchange_value,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)', 'Unable to save exchange');
            $xs = prepareOrFail($conn, "INSERT INTO exchange_items_stock(business_id,branch_id,sale_id,customer_id,item_name,gross_weight,wastage_percent,net_weight,rate_per_gram,stock_value,status,received_date,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,'Available',?,?)", 'Unable to save exchange stock');
            foreach ($validatedExchange as $ex) {
                $es->bind_param('iiiisdddddi', $businessId, $branchId, $saleId, $customerId, $ex['name'], $ex['gross'], $ex['waste'], $ex['eligible'], $ex['rate'], $ex['value'], $userId);
                if (!$es->execute())
                    throw new RuntimeException($es->error);
                $xs->bind_param('iiiisdddddsi', $businessId, $branchId, $saleId, $customerId, $ex['name'], $ex['gross'], $ex['waste'], $ex['eligible'], $ex['rate'], $ex['value'], $movementDate, $userId);
                if (!$xs->execute())
                    throw new RuntimeException($xs->error);
            }
            $es->close();
            $xs->close();
        }
        billingSaveExchangePayout($conn, $businessId, $branchId, $saleId, $customerId, $exchangePayoutMethodId, $exchangePayoutAmount, $exchangePayoutReference, $invoiceDate, $invoiceTime, $userId);
        billingSaveSalePayments($conn, $businessId, $branchId, $saleId, $invoiceDate, $invoiceTime, $userId, $payments);
        billingSaveCustomerReceipt($conn, $businessId, $branchId, $customerId, $saleId, (string) $oldSale['invoice_no'], $invoiceDate, $invoiceTime, $paid, $payments, $notes, $userId);
        if ($validatedClaims) {
            $ci = prepareOrFail($conn, "INSERT INTO sales_chit_claims(business_id,branch_id,sale_id,customer_id,chit_group_id,chit_member_id,product_id,claim_grams,rate_per_gram,claim_amount,status,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,'Posted',?)", 'Unable to save claims');
            foreach ($validatedClaims as $c) {
                $g = (int) $c['group'];
                $m = (int) $c['member'];
                $p = (int) $c['product'];
                $gr = (float) $c['grams'];
                $r = (float) $c['rate'];
                $a = (float) $c['amount'];
                $ci->bind_param('iiiiiiidddi', $businessId, $branchId, $saleId, $customerId, $g, $m, $p, $gr, $r, $a, $userId);
                if (!$ci->execute())
                    throw new RuntimeException($ci->error);
            }
            $ci->close();
        }
        $u = prepareOrFail($conn, 'UPDATE sales SET invoice_date=?,invoice_time=?,customer_id=?,customer_name=?,customer_mobile=?,bill_type=?,tax_type=?,subtotal=?,discount_amount=?,taxable_amount=?,cgst_amount=?,sgst_amount=?,igst_amount=?,round_off=?,grand_total=?,exchange_amount=?,chit_claim_amount=?,net_payable_amount=?,paid_amount=?,balance_amount=?,payment_status=?,notes=? WHERE id=? AND business_id=? AND branch_id=?', 'Unable to update invoice');
        $params = [$invoiceDate, $invoiceTime, $customerId, (string) $customer['customer_name'], (string) $customer['mobile'], $billType, $taxType, $subtotal, $discountTotal, $taxable, $cgst, $sgst, $igst, $round, $grossGrand, $exchangeTotal, $claimTotal, $netPayable, $paid, $balance, $paymentStatus, $notes, $saleId, $businessId, $branchId];
        $types = 'ssissss' . str_repeat('d', 13) . 'ssiii';
        bindDynamic($u, $types, $params);
        if (!$u->execute())
            throw new RuntimeException($u->error);
        $u->close();
        billingAuditLog($conn, $businessId, $branchId, $userId, 'billing.sales', 'Update', 'sales', $saleId, 'Fully reversed and reposted bill ' . $oldSale['invoice_no'], ['invoice_no' => $oldSale['invoice_no'], 'customer_id' => (int) $oldSale['customer_id'], 'bill_type' => $oldSale['bill_type'], 'tax_type' => $oldSale['tax_type'], 'grand_total' => (float) $oldSale['grand_total'], 'paid_amount' => (float) $oldSale['paid_amount'], 'balance_amount' => (float) $oldSale['balance_amount']], ['invoice_no' => $oldSale['invoice_no'], 'customer_id' => $customerId, 'bill_type' => $billType, 'tax_type' => $taxType, 'grand_total' => round($grossGrand, 2), 'exchange_amount' => round($exchangeTotal, 2), 'exchange_payout_amount' => round($exchangePayoutAmount, 2), 'chit_claim_amount' => round($claimTotal, 2), 'net_payable_amount' => round($netPayable, 2), 'received_amount' => round($paid, 2), 'credit_amount' => round($creditAmount, 2), 'balance_amount' => round($balance, 2), 'payment_status' => $paymentStatus]);
        $conn->commit();
        respond(true, 'Bill ' . $oldSale['invoice_no'] . ' updated successfully with full reverse calculation.', ['document_type' => $editNonGst ? 'Non GST Invoice' : 'Invoice', 'sale_id' => $saleId, 'invoice_no' => $oldSale['invoice_no'], 'net_payable_amount' => $netPayable, 'exchange_payout_amount' => $exchangePayoutAmount, 'received_amount' => $paid, 'credit_amount' => round($creditAmount, 2), 'balance_amount' => $balance, 'payment_status' => $paymentStatus]);
    } catch (Throwable $e) {
        $conn->rollback();
        respond(false, $e->getMessage(), [], 422);
    }
}

if ($action !== 'save')
    respond(false, 'Invalid action.', [], 400);
$invoiceDate = (string) ($_POST['invoice_date'] ?? date('Y-m-d'));
$invoiceTime = (string) ($_POST['invoice_time'] ?? date('H:i'));
$billType = (string) ($_POST['bill_type'] ?? 'Retail');
if ($nonGstModeActive) {
    $billType = 'Retail';
    $taxType = 'Non-GST';
    $documentType = 'Non GST Invoice';
} else {
    if (!in_array($billType, ['Retail', 'GST', 'Estimate', 'Exchange'], true)) {
        $billType = 'Retail';
    }
    $taxType = 'GST';
    $documentType = $billType === 'Estimate' ? 'Estimate' : 'Invoice';
}
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
$dynamicGrossWeights = $_POST['dynamic_gross_weight'] ?? [];
$exchangePayoutMethodId = (int)($_POST['exchange_payout_payment_method_id'] ?? 0);
$exchangePayoutReference = trim((string)($_POST['exchange_payout_reference'] ?? ''));
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
    $requestedQtyByProduct = [];
    $availableQtyByProduct = [];
    $trackedProduct = [];
    $requestedDynamicGrossByProduct = [];
    $availableDynamicGrossByProduct = [];
    $dynamicTrackedProduct = [];
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

        $productTaxType = columnExists($conn, 'products', 'tax_type')
            ? strtolower(trim((string) ($p['tax_type'] ?? 'GST')))
            : (((float) ($p['tax_percent'] ?? 0) <= 0) ? 'non-gst' : 'gst');

        if ($nonGstModeActive && !in_array($productTaxType, ['non-gst', 'non gst'], true)) {
            throw new RuntimeException($p['product_name'] . ' is not a Non-GST product.');
        }
        if (!$nonGstModeActive && in_array($productTaxType, ['non-gst', 'non gst'], true)) {
            throw new RuntimeException($p['product_name'] . ' is available only in Non-GST mode.');
        }

        $requestedQtyByProduct[$pid] = ($requestedQtyByProduct[$pid] ?? 0) + $qty;
        $availableQtyByProduct[$pid] = (float) $p['stock_qty'];
        $trackedProduct[$pid] = (int) $p['track_stock'] === 1;
        $submittedRate = max(0, (float) ($rates[$i] ?? 0));
        $rate = $submittedRate > 0 ? $submittedRate : max(0, (float) ($p['live_metal_rate'] ?? $p['sale_rate'] ?? 0));
        $w = max(0, (float) ($wastages[$i] ?? $p['wastage_percent']));
        $making = max(0, (float) ($makings[$i] ?? $p['making_charge']));
        $stone = max(0, (float) ($stones[$i] ?? 0));
        $other = max(0, (float) ($others[$i] ?? 0));
        $disc = max(0, (float) ($discounts[$i] ?? 0));
        $taxPercent = $nonGstModeActive
            ? 0.0
            : max(0, min(100, (float) ($taxPercents[$i] ?? $p['tax_percent'] ?? 0)));
        $isDynamicStock = (int)($p['dynamic_stock'] ?? 0) === 1;
        $postedDynamicGross = round(max(0, (float)($dynamicGrossWeights[$i] ?? 0)), 3);
        if ($isDynamicStock && $postedDynamicGross <= 0) {
            throw new RuntimeException('Enter the actual gross weight for dynamic stock product ' . $p['product_name'] . '.');
        }
        $gross = $isDynamicStock ? $postedDynamicGross : (float) $p['gross_weight'] * $qty;
        $stoneWeight = (float) $p['stone_weight'] * $qty;
        $net = (float) $p['net_weight'] * $qty;
        if ($isDynamicStock) {
            $requestedDynamicGrossByProduct[$pid] = ($requestedDynamicGrossByProduct[$pid] ?? 0) + $gross;
            $availableDynamicGrossByProduct[$pid] = (float)$p['stock_gross'];
            $dynamicTrackedProduct[$pid] = (int)$p['track_stock'] === 1;
        }

        /*
         * Jewellery billing rule:
         *   Metal Amount  = Rate × Gross Weight
         *   Making Amount = Making Rate × Gross Weight
         *   Base Value    = (Rate + Making Rate) × Gross Weight
         *
         * Wastage remains a separate percentage charge on the metal amount.
         */
        $metal = $gross > 0 ? $gross * $rate : $qty * $rate;
        $makingAmount = $gross > 0 ? $gross * $making : $qty * $making;
        $baseAmount = $metal + $makingAmount;
        $wastageAmount = $baseAmount * $w / 100;

        $rowSub = $baseAmount + $wastageAmount + $stone + $other;
        $rowTaxable = max(0, $rowSub - $disc);
        $tax = $rowTaxable * $taxPercent / 100;
        $line = $rowTaxable + $tax;
        $items[] = ['p' => $p, 'qty' => $qty, 'gross' => $gross, 'stone_weight' => $stoneWeight, 'net' => $net, 'stock_gross_out' => $gross, 'stock_net_out' => $isDynamicStock ? 0.0 : $net, 'movement_weight' => $isDynamicStock ? $gross : $net, 'is_dynamic_stock' => $isDynamicStock, 'rate' => $rate, 'w' => $w, 'wamt' => $wastageAmount, 'making' => $makingAmount, 'stone' => $stone, 'other' => $other, 'disc' => $disc, 'tax_percent' => $taxPercent, 'tax' => $tax, 'line' => $line];
        $subtotal += $rowSub;
        $itemDiscount += $disc;
        $taxable += $rowTaxable;
        $taxTotal += $tax;
    }
    $productStmt->close();
    if (!$items)
        throw new RuntimeException('Add at least one valid product.');
    /*
     * Estimates are provisional documents only. They must not be blocked by
     * current stock because saving an estimate does not deduct product_stock
     * or create Sale stock movements. Stock is validated again when a real
     * invoice is created.
     */
    if ($documentType !== 'Estimate') {
        foreach ($requestedQtyByProduct as $pid => $q) {
            if (!empty($trackedProduct[$pid]) && ($availableQtyByProduct[$pid] ?? 0) + 0.0001 < $q) {
                $name = 'Product';
                foreach ($items as $it) {
                    if ((int) $it['p']['id'] === (int) $pid) {
                        $name = (string) $it['p']['product_name'];
                        break;
                    }
                }
                throw new RuntimeException(
                    $name . ' has only ' . number_format((float) ($availableQtyByProduct[$pid] ?? 0), 3) . ' stock available.'
                );
            }
        }
        foreach ($requestedDynamicGrossByProduct as $pid => $grossRequested) {
            if (!empty($dynamicTrackedProduct[$pid]) && ($availableDynamicGrossByProduct[$pid] ?? 0) + 0.0001 < $grossRequested) {
                $name = 'Product';
                foreach ($items as $it) {
                    if ((int)$it['p']['id'] === (int)$pid) { $name = (string)$it['p']['product_name']; break; }
                }
                throw new RuntimeException($name . ' has only ' . number_format((float)($availableDynamicGrossByProduct[$pid] ?? 0), 3) . ' g gross stock available.');
            }
        }
    }
    $taxable = max(0, $taxable - $overall);
    $discountTotal = $itemDiscount + $overall;
    $cgst = $nonGstModeActive ? 0.0 : $taxTotal / 2;
    $sgst = $nonGstModeActive ? 0.0 : $taxTotal / 2;
    $grand = max(0, $taxable + $cgst + $sgst + $round);
    $grossGrandTotal = $grand;
    $exchangeTotal = 0.0;
    $validatedExchange = [];
    foreach ($exchangeItemsInput as $ex) {
        $name = trim((string) ($ex['item_name'] ?? ''));
        $metalId = (int) ($ex['metal_id'] ?? 0);
        $gross = round((float) ($ex['gross_weight'] ?? 0), 3);
        $waste = max(0, min(100, (float) ($ex['wastage_percent'] ?? 0)));
        if ($name === '' || $metalId <= 0 || $gross <= 0)
            continue;

        $metalRateStmt = prepareOrFail(
            $conn,
            "SELECT m.metal_name,COALESCE((
                SELECT mr.rate_per_gram
                FROM metal_rates mr
                WHERE mr.business_id=? AND mr.metal_id=m.id AND mr.is_current=1
                  AND (mr.branch_id=? OR mr.branch_id IS NULL)
                ORDER BY (mr.branch_id=?) DESC,mr.effective_from DESC,mr.id DESC
                LIMIT 1
            ),0) current_rate
             FROM metals m
             WHERE m.id=? AND m.business_id=? LIMIT 1",
            'Unable to validate exchange metal rate'
        );
        $metalRateStmt->bind_param('iiiii', $businessId, $branchId, $branchId, $metalId, $businessId);
        $metalRateStmt->execute();
        $metalRow = $metalRateStmt->get_result()->fetch_assoc();
        $metalRateStmt->close();
        if (!$metalRow || (float) $metalRow['current_rate'] <= 0) {
            throw new RuntimeException('Selected exchange metal does not have a valid current rate.');
        }
        $rate = (float) $metalRow['current_rate'];
        $eligible = round($gross * (1 - $waste / 100), 3);
        $calculatedValue = round($eligible * $rate, 2);
        $enteredValue = round(max(0, (float) ($ex['exchange_value'] ?? 0)), 2);
        $value = $enteredValue > 0 ? $enteredValue : $calculatedValue;
        if ($eligible <= 0 || $value <= 0)
            throw new RuntimeException('Invalid exchange item weight or value.');
        $exchangeTotal += $value;
        $validatedExchange[] = ['name' => $name, 'metal_id' => $metalId, 'metal_name' => (string) $metalRow['metal_name'], 'gross' => $gross, 'waste' => $waste, 'eligible' => $eligible, 'rate' => $rate, 'value' => $value];
    }
    $exchangePayoutAmount = round(max(0, $exchangeTotal - $grand), 2);
    if ($documentType === 'Estimate' && $exchangePayoutAmount > 0.005) {
        throw new RuntimeException('Exchange value cannot exceed bill total for an Estimate.');
    }
    if ($documentType !== 'Estimate' && $exchangePayoutAmount > 0.005) {
        billingValidateExchangePayoutMethod($conn, $businessId, $exchangePayoutMethodId);
    }
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

            $availableGrams = round(max(0, (float) $x['available_grams']), 6);

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
        'SELECT id,method_name,method_type FROM payment_methods WHERE id=? AND business_id=? AND is_active=1 LIMIT 1',
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

            $methodName = strtolower(trim((string) $methodRow['method_name']));
            $methodType = strtolower(trim((string) ($methodRow['method_type'] ?? '')));
            $isCreditMethod = $methodType === 'credit' ||
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
                'method_name' => (string) $methodRow['method_name']
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
        foreach (['estimates', 'estimate_items', 'estimate_payments', 'estimate_exchange_items', 'estimate_chit_claims'] as $requiredTable) {
            if (!tableExists($conn, $requiredTable)) {
                throw new RuntimeException('Estimate tables are missing. Run the supplied estimate migration SQL first.');
            }
        }

        $estimateSettingId = resolveInvoiceSettingId($conn, $businessId, $branchId, 'Estimate');
        $estimateNo = (string) $number['document_no'];
        $customerName = (string) $customer['customer_name'];
        $customerMobile = (string) $customer['mobile'];
        $igst = 0.0;
        $storedGrandTotal = $grossGrandTotal;

        $estimateStmt = prepareOrFail($conn, "INSERT INTO estimates
            (business_id,branch_id,invoice_setting_id,estimate_no,estimate_date,estimate_time,
             customer_id,customer_name,customer_mobile,bill_type,subtotal,discount_amount,
             taxable_amount,cgst_amount,sgst_amount,igst_amount,round_off,grand_total,
             exchange_amount,chit_claim_amount,net_estimate_amount,proposed_paid_amount,
             proposed_balance_amount,status,notes,created_by)
             VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'Open',?,?)", 'Unable to prepare estimate insert');
        $estimateStmt->bind_param(
            'iiisssisssdddddddddddddsi',
            $businessId,
            $branchId,
            $estimateSettingId,
            $estimateNo,
            $invoiceDate,
            $invoiceTime,
            $customerId,
            $customerName,
            $customerMobile,
            $billType,
            $subtotal,
            $discountTotal,
            $taxable,
            $cgst,
            $sgst,
            $igst,
            $round,
            $storedGrandTotal,
            $exchangeTotal,
            $claimTotal,
            $netPayable,
            $paid,
            $balance,
            $notes,
            $userId
        );
        if (!$estimateStmt->execute())
            throw new RuntimeException('Unable to save estimate: ' . $estimateStmt->error);
        $estimateId = (int) $estimateStmt->insert_id;
        $estimateStmt->close();

        $estimateItem = prepareOrFail($conn, "INSERT INTO estimate_items
            (business_id,branch_id,estimate_id,product_id,item_name,hsn_code,quantity,
             gross_weight,stone_weight,net_weight,metal_rate,wastage_percent,wastage_amount,
             making_charge,stone_amount,other_charge,discount_amount,tax_percent,tax_amount,
             line_total,cost_amount,sort_order) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)", 'Unable to prepare estimate item insert');
        foreach ($items as $idx => $it) {
            $p = $it['p'];
            $cost = (float) $p['purchase_rate'] * $it['net'];
            $sort = $idx + 1;
            $estimateItem->bind_param('iiiissdddddddddddddddi', $businessId, $branchId, $estimateId, $p['id'], $p['product_name'], $p['hsn_code'], $it['qty'], $it['gross'], $it['stone_weight'], $it['net'], $it['rate'], $it['w'], $it['wamt'], $it['making'], $it['stone'], $it['other'], $it['disc'], $it['tax_percent'], $it['tax'], $it['line'], $cost, $sort);
            if (!$estimateItem->execute())
                throw new RuntimeException('Unable to save estimate item: ' . $estimateItem->error);
        }
        $estimateItem->close();

        if ($validatedExchange) {
            $ex = prepareOrFail($conn, 'INSERT INTO estimate_exchange_items(business_id,branch_id,estimate_id,customer_id,item_name,gross_weight,wastage_percent,eligible_weight,rate_per_gram,exchange_value,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)', 'Unable to prepare estimate exchange insert');
            foreach ($validatedExchange as $row) {
                $ex->bind_param('iiiisdddddi', $businessId, $branchId, $estimateId, $customerId, $row['name'], $row['gross'], $row['waste'], $row['eligible'], $row['rate'], $row['value'], $userId);
                if (!$ex->execute())
                    throw new RuntimeException('Unable to save estimate exchange item: ' . $ex->error);
            }
            $ex->close();
        }

        if ($payments) {
            $pay = prepareOrFail($conn, 'INSERT INTO estimate_payments(business_id,branch_id,estimate_id,payment_method_id,amount,reference_no,created_by) VALUES(?,?,?,?,?,?,?)', 'Unable to prepare estimate payment insert');
            foreach ($payments as $row) {
                $method = (int) $row['method_id'];
                $amount = (float) $row['amount'];
                $reference = (string) $row['reference'];
                $pay->bind_param('iiiidsi', $businessId, $branchId, $estimateId, $method, $amount, $reference, $userId);
                if (!$pay->execute())
                    throw new RuntimeException('Unable to save estimate payment: ' . $pay->error);
            }
            $pay->close();
        }

        if ($validatedClaims) {
            $claimStmt = prepareOrFail($conn, "INSERT INTO estimate_chit_claims
                (business_id,branch_id,estimate_id,customer_id,chit_group_id,chit_member_id,
                 product_id,claim_grams,rate_per_gram,claim_amount,status,created_by)
                 VALUES(?,?,?,?,?,?,?,?,?,?,'Proposed',?)", 'Unable to prepare estimate claim insert');
            foreach ($validatedClaims as $claim) {
                $groupId = (int) $claim['group'];
                $memberId = (int) $claim['member'];
                $productId = (int) $claim['product'];
                $grams = (float) $claim['grams'];
                $rate = (float) $claim['rate'];
                $amount = (float) $claim['amount'];
                $claimStmt->bind_param('iiiiiiidddi', $businessId, $branchId, $estimateId, $customerId, $groupId, $memberId, $productId, $grams, $rate, $amount, $userId);
                if (!$claimStmt->execute())
                    throw new RuntimeException('Unable to save proposed estimate claim: ' . $claimStmt->error);
            }
            $claimStmt->close();
        }

        billingAuditLog(
            $conn,
            $businessId,
            $branchId,
            $userId,
            'billing.estimates',
            'Create',
            'estimates',
            $estimateId,
            'Created estimate ' . $estimateNo,
            null,
            [
                'estimate_no' => $estimateNo,
                'customer_id' => $customerId,
                'customer_name' => (string) $customer['customer_name'],
                'bill_type' => $billType,
                'subtotal' => round($subtotal, 2),
                'discount_total' => round($discountTotal, 2),
                'taxable_amount' => round($taxable, 2),
                'cgst_amount' => round($cgst, 2),
                'sgst_amount' => round($sgst, 2),
                'exchange_amount' => round($exchangeTotal, 2),
                'chit_claim_amount' => round($claimTotal, 2),
                'net_estimate_amount' => round($netPayable, 2),
                'proposed_paid_amount' => round($paid, 2),
                'proposed_balance_amount' => round($balance, 2),
                'item_count' => count($items),
                'payment_count' => count($payments)
            ]
        );

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
    $sale = prepareOrFail($conn, 'INSERT INTO sales(business_id,branch_id,invoice_setting_id,invoice_no,invoice_date,invoice_time,customer_id,customer_name,customer_mobile,bill_type,tax_type,subtotal,discount_amount,taxable_amount,cgst_amount,sgst_amount,igst_amount,round_off,grand_total,exchange_amount,chit_claim_amount,net_payable_amount,paid_amount,balance_amount,payment_status,workflow_status,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,\'Posted\',?,?)', 'Unable to prepare sale insert. Confirm sales.exchange_amount exists');
    $igst = 0.0;
    $storedGrandTotal = $grossGrandTotal;
    $invoiceSettingId = resolveInvoiceSettingId($conn, $businessId, $branchId, $documentType);
    $invoiceNo = (string) $number['document_no'];
    $customerName = (string) $customer['customer_name'];
    $customerMobile = (string) $customer['mobile'];
    $saleParams = [$businessId, $branchId, $invoiceSettingId, $invoiceNo, $invoiceDate, $invoiceTime, $customerId, $customerName, $customerMobile, $billType, $taxType, $subtotal, $discountTotal, $taxable, $cgst, $sgst, $igst, $round, $storedGrandTotal, $exchangeTotal, $claimTotal, $netPayable, $paid, $balance, $paymentStatus, $notes, $userId];
    $saleTypes = 'iiisssissss' . str_repeat('d', 13) . 'ssi';
    bindDynamic($sale, $saleTypes, $saleParams);
    if (!$sale->execute())
        throw new RuntimeException('Unable to create bill: ' . $sale->error);
    $saleId = (int) $sale->insert_id;
    $sale->close();
    $itemStmt = prepareOrFail($conn, 'INSERT INTO sale_items(business_id,branch_id,sale_id,product_id,item_name,hsn_code,quantity,gross_weight,stone_weight,net_weight,metal_rate,wastage_percent,wastage_amount,making_charge,stone_amount,other_charge,discount_amount,tax_percent,tax_amount,line_total,cost_amount,sort_order) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', 'Unable to prepare sale item insert');
    $stockUp = prepareOrFail($conn, 'UPDATE product_stock SET quantity=quantity-?,gross_weight=GREATEST(0,gross_weight-?),net_weight=GREATEST(0,net_weight-?) WHERE business_id=? AND branch_id=? AND product_id=? AND quantity+0.0001>=?', 'Unable to prepare product stock update');
    $move = prepareOrFail($conn, "INSERT INTO stock_movements(business_id,branch_id,product_id,movement_date,movement_type,reference_table,reference_id,quantity_in,quantity_out,weight_in,weight_out,rate,value_amount,remarks,created_by) VALUES(?,?,?,?,'Sale','sales',?,0,?,0,?,?,?,?,?)", 'Unable to prepare stock movement insert');
    foreach ($items as $idx => $it) {
        $p = $it['p'];
        $cost = (float) $p['purchase_rate'] * $it['net'];
        $sort = $idx + 1;
        $itemStmt->bind_param('iiiissdddddddddddddddi', $businessId, $branchId, $saleId, $p['id'], $p['product_name'], $p['hsn_code'], $it['qty'], $it['gross'], $it['stone_weight'], $it['net'], $it['rate'], $it['w'], $it['wamt'], $it['making'], $it['stone'], $it['other'], $it['disc'], $it['tax_percent'], $it['tax'], $it['line'], $cost, $sort);
        $itemStmt->execute();
        if ((int) $p['track_stock'] === 1) {
            $stockUp->bind_param('dddiiid', $it['qty'], $it['stock_gross_out'], $it['stock_net_out'], $businessId, $branchId, $p['id'], $it['qty']);
            $stockUp->execute();
            if ($stockUp->affected_rows < 1)
                throw new RuntimeException('Unable to reduce stock for ' . $p['product_name']);
            $movementDate = $invoiceDate . ' ' . $invoiceTime . ':00';
            $value = $it['line'];
            $remarks = 'Sale ' . $number['document_no'];
            $move->bind_param('iiisiddddsi', $businessId, $branchId, $p['id'], $movementDate, $saleId, $it['qty'], $it['movement_weight'], $it['rate'], $value, $remarks, $userId);
            if (!$move->execute()) {
                throw new RuntimeException('Unable to log stock movement for ' . $p['product_name'] . ': ' . $move->error);
            }
            billingDeactivateProductIfOutOfStock($conn, $businessId, (int) $p['id']);
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
    billingSaveExchangePayout($conn, $businessId, $branchId, $saleId, $customerId, $exchangePayoutMethodId, $exchangePayoutAmount, $exchangePayoutReference, $invoiceDate, $invoiceTime, $userId);
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
            $method = (int) $payment['method_id'];
            $amount = (float) $payment['amount'];
            $reference = (string) $payment['reference'];

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

    /* Customer payment ledger: records only money actually received. */
    billingSaveCustomerReceipt($conn, $businessId, $branchId, $customerId, $saleId, $invoiceNo, $invoiceDate, $invoiceTime, $receivedAmount, $payments, $notes, $userId);

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
            $claimGroupId = (int) $claim['group'];
            $claimMemberId = (int) $claim['member'];
            $claimProductId = (int) $claim['product'];
            $claimGrams = round((float) $claim['grams'], 6);
            $claimRate = round((float) $claim['rate'], 2);
            $claimAmount = round((float) $claim['amount'], 2);

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
    billingAuditLog(
        $conn,
        $businessId,
        $branchId,
        $userId,
        'billing.sales',
        'Create',
        'sales',
        $saleId,
        'Created bill ' . $invoiceNo,
        null,
        [
            'invoice_no' => $invoiceNo,
            'invoice_date' => $invoiceDate,
            'invoice_time' => $invoiceTime,
            'customer_id' => $customerId,
            'customer_name' => $customerName,
            'customer_mobile' => $customerMobile,
            'bill_type' => $billType,
            'subtotal' => round($subtotal, 2),
            'discount_amount' => round($discountTotal, 2),
            'taxable_amount' => round($taxable, 2),
            'cgst_amount' => round($cgst, 2),
            'sgst_amount' => round($sgst, 2),
            'round_off' => round($round, 2),
            'grand_total' => round($storedGrandTotal, 2),
            'exchange_amount' => round($exchangeTotal, 2),
            'exchange_payout_amount' => round($exchangePayoutAmount, 2),
            'chit_claim_amount' => round($claimTotal, 2),
            'net_payable_amount' => round($netPayable, 2),
            'received_amount' => round($paid, 2),
            'credit_amount' => round($creditAmount, 2),
            'balance_amount' => round($balance, 2),
            'payment_status' => $paymentStatus,
            'item_count' => count($items),
            'payment_count' => count($payments),
            'exchange_item_count' => count($validatedExchange),
            'chit_claim_count' => count($validatedClaims)
        ]
    );

    $conn->commit();
    respond(true, 'Bill ' . $number['document_no'] . ' created successfully.', [
        'document_type' => $documentType,
        'sale_id' => $saleId,
        'invoice_no' => $number['document_no'],
        'net_payable_amount' => $netPayable,
        'exchange_payout_amount' => $exchangePayoutAmount,
        'received_amount' => $paid,
        'credit_amount' => round($creditAmount, 2),
        'balance_amount' => $balance,
        'payment_status' => $paymentStatus
    ]);
} catch (Throwable $e) {
    $conn->rollback();
    respond(false, $e->getMessage(), [], 422);
}