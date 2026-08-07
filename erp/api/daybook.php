<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));

$configCandidates = [
    dirname(__DIR__) . '/config/config.php',
    dirname(__DIR__) . '/config.php',
    dirname(__DIR__) . '/includes/config.php',
    dirname(__DIR__) . '/super-admin/includes/config.php',
];

foreach ($configCandidates as $configFile) {
    if (is_file($configFile)) {
        require_once $configFile;
        break;
    }
}

function respond(bool $success, string $message, array $extra = [], int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    respond(false, 'Database configuration is not available.', [], 500);
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn->set_charset('utf8mb4');

function tableExists(mysqli $conn, string $table): bool
{
    $safe = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $result && $result->num_rows > 0;
}

function hasColumn(mysqli $conn, string $table, string $column): bool
{
    $safeTable = $conn->real_escape_string($table);
    $safeColumn = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    return $result && $result->num_rows > 0;
}

function firstColumn(mysqli $conn, string $table, array $columns): string
{
    foreach ($columns as $column) {
        if (hasColumn($conn, $table, $column)) {
            return $column;
        }
    }
    return '';
}

function bindDynamic(mysqli_stmt $stmt, string $types, array &$values): void
{
    if ($types === '' || !$values) {
        return;
    }

    $params = [$types];
    foreach ($values as $key => $value) {
        $params[] = &$values[$key];
    }
    call_user_func_array([$stmt, 'bind_param'], $params);
}

function appendRows(mysqli $conn, array &$entries, string $sql, string $types, array $values): void
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return;
    }

    bindDynamic($stmt, $types, $values);

    if (!$stmt->execute()) {
        $stmt->close();
        return;
    }

    $result = $stmt->get_result();
    while ($result && $row = $result->fetch_assoc()) {
        if ((float)($row['amount'] ?? 0) > 0) {
            $entries[] = $row;
        }
    }
    $stmt->close();
}

if (empty($_SESSION['user_id'])) {
    respond(false, 'Your session has expired. Please log in again.', [], 401);
}

$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? $_SESSION['default_branch_id'] ?? 0);

if ($businessId <= 0) {
    respond(false, 'A valid business must be selected.', [], 403);
}

$action = strtolower(trim((string)($_GET['action'] ?? 'list')));

if ($action === 'bootstrap') {
    respond(true, 'Bootstrap loaded.', [
        'defaults' => [
            'from_date' => date('Y-m-d'),
            'to_date' => date('Y-m-d'),
        ],
    ]);
}

$fromDate = trim((string)($_GET['from_date'] ?? date('Y-m-d')));
$toDate = trim((string)($_GET['to_date'] ?? date('Y-m-d')));
$entryTypeFilter = trim((string)($_GET['entry_type'] ?? ''));

$fromObject = DateTime::createFromFormat('Y-m-d', $fromDate);
$toObject = DateTime::createFromFormat('Y-m-d', $toDate);

if (!$fromObject || !$toObject || $fromObject->format('Y-m-d') !== $fromDate || $toObject->format('Y-m-d') !== $toDate) {
    respond(false, 'Enter valid report dates.', [], 422);
}

if ($fromDate > $toDate) {
    [$fromDate, $toDate] = [$toDate, $fromDate];
}

$entries = [];

/* SALES / SALE PAYMENTS = CASH IN */
if ($entryTypeFilter === '' || $entryTypeFilter === 'Sale') {
    if (tableExists($conn, 'sale_payments')) {
        $dateColumn = firstColumn($conn, 'sale_payments', ['payment_date', 'created_at']);
        $amountColumn = firstColumn($conn, 'sale_payments', ['amount', 'paid_amount']);
        $referenceColumn = firstColumn($conn, 'sale_payments', ['payment_no', 'reference_no']);
        $notesColumn = firstColumn($conn, 'sale_payments', ['notes', 'remarks']);
        $saleIdColumn = firstColumn($conn, 'sale_payments', ['sale_id']);

        if ($dateColumn !== '' && $amountColumn !== '') {
            $selectRef = $referenceColumn !== '' ? "sp.`{$referenceColumn}`" : "CONCAT('SALEPAY-',sp.id)";
            $selectNotes = $notesColumn !== '' ? "COALESCE(sp.`{$notesColumn}`,'')" : "''";
            $customerName = tableExists($conn, 'sales') && $saleIdColumn !== ''
                ? "COALESCE(c.customer_name,s.customer_name,'Walk-in Customer')"
                : "'Customer'";

            $sql = "SELECT sp.id, DATE(sp.`{$dateColumn}`) AS entry_date, {$selectRef} AS ref_no,
                           {$customerName} AS party_name, COALESCE(sp.`{$amountColumn}`,0) AS amount,
                           'Sale' AS entry_type, 'in' AS cash_flow, {$selectNotes} AS notes
                    FROM sale_payments sp";

            if (tableExists($conn, 'sales') && $saleIdColumn !== '') {
                $sql .= " LEFT JOIN sales s ON s.id=sp.`{$saleIdColumn}` LEFT JOIN customers c ON c.id=s.customer_id";
            }

            $where = ["DATE(sp.`{$dateColumn}`) BETWEEN ? AND ?"];
            $types = 'ss';
            $values = [$fromDate, $toDate];

            if (hasColumn($conn, 'sale_payments', 'business_id')) {
                $where[] = 'sp.business_id=?';
                $types .= 'i';
                $values[] = $businessId;
            }
            if ($branchId > 0 && hasColumn($conn, 'sale_payments', 'branch_id')) {
                $where[] = 'sp.branch_id=?';
                $types .= 'i';
                $values[] = $branchId;
            }

            $sql .= ' WHERE ' . implode(' AND ', $where);
            appendRows($conn, $entries, $sql, $types, $values);
        }
    } elseif (tableExists($conn, 'sales')) {
        $dateColumn = firstColumn($conn, 'sales', ['invoice_date', 'bill_date', 'sale_date']);
        $refColumn = firstColumn($conn, 'sales', ['invoice_no', 'bill_no', 'sale_no']);
        $amountColumn = firstColumn($conn, 'sales', ['paid_amount', 'grand_total']);
        $notesColumn = firstColumn($conn, 'sales', ['notes', 'remarks']);

        if ($dateColumn !== '' && $amountColumn !== '') {
            $sql = "SELECT s.id, DATE(s.`{$dateColumn}`) AS entry_date,
                           " . ($refColumn !== '' ? "s.`{$refColumn}`" : "CONCAT('SALE-',s.id)") . " AS ref_no,
                           COALESCE(c.customer_name,s.customer_name,'Walk-in Customer') AS party_name,
                           COALESCE(s.`{$amountColumn}`,0) AS amount,
                           'Sale' AS entry_type,'in' AS cash_flow,
                           " . ($notesColumn !== '' ? "COALESCE(s.`{$notesColumn}`,'')" : "''") . " AS notes
                    FROM sales s LEFT JOIN customers c ON c.id=s.customer_id";

            $where = ["DATE(s.`{$dateColumn}`) BETWEEN ? AND ?"];
            $types = 'ss';
            $values = [$fromDate, $toDate];
            if (hasColumn($conn, 'sales', 'business_id')) {$where[]='s.business_id=?';$types.='i';$values[]=$businessId;}
            if ($branchId>0 && hasColumn($conn,'sales','branch_id')) {$where[]='s.branch_id=?';$types.='i';$values[]=$branchId;}
            $sql .= ' WHERE ' . implode(' AND ', $where);
            appendRows($conn, $entries, $sql, $types, $values);
        }
    }
}

/* CUSTOMER COLLECTIONS = CASH IN */
if (($entryTypeFilter === '' || $entryTypeFilter === 'Customer Collection') && tableExists($conn, 'customer_payments')) {
    $dateColumn = firstColumn($conn, 'customer_payments', ['receipt_date', 'payment_date']);
    $refColumn = firstColumn($conn, 'customer_payments', ['receipt_no', 'payment_no', 'reference_no']);
    $amountColumn = firstColumn($conn, 'customer_payments', ['amount', 'paid_amount']);
    $notesColumn = firstColumn($conn, 'customer_payments', ['notes', 'remarks']);

    if ($dateColumn !== '' && $amountColumn !== '') {
        $sql = "SELECT cp.id, DATE(cp.`{$dateColumn}`) AS entry_date,
                       " . ($refColumn !== '' ? "cp.`{$refColumn}`" : "CONCAT('RCPT-',cp.id)") . " AS ref_no,
                       COALESCE(c.customer_name,'Customer') AS party_name,
                       COALESCE(cp.`{$amountColumn}`,0) AS amount,
                       'Customer Collection' AS entry_type,'in' AS cash_flow,
                       " . ($notesColumn !== '' ? "COALESCE(cp.`{$notesColumn}`,'')" : "''") . " AS notes
                FROM customer_payments cp LEFT JOIN customers c ON c.id=cp.customer_id";
        $where = ["DATE(cp.`{$dateColumn}`) BETWEEN ? AND ?"];
        $types = 'ss';
        $values = [$fromDate, $toDate];
        if (hasColumn($conn,'customer_payments','business_id')) {$where[]='cp.business_id=?';$types.='i';$values[]=$businessId;}
        if ($branchId>0 && hasColumn($conn,'customer_payments','branch_id')) {$where[]='cp.branch_id=?';$types.='i';$values[]=$branchId;}
        $sql .= ' WHERE ' . implode(' AND ', $where);
        appendRows($conn, $entries, $sql, $types, $values);
    }
}

/* PURCHASES = CASH OUT */
if (($entryTypeFilter === '' || $entryTypeFilter === 'Purchase') && tableExists($conn, 'purchases')) {
    $dateColumn = firstColumn($conn, 'purchases', ['purchase_date']);
    $refColumn = firstColumn($conn, 'purchases', ['purchase_no', 'purchase_number']);
    $amountColumn = firstColumn($conn, 'purchases', ['paid_amount', 'grand_total']);
    $notesColumn = firstColumn($conn, 'purchases', ['notes', 'remarks']);

    if ($dateColumn !== '' && $amountColumn !== '') {
        $sql = "SELECT p.id, DATE(p.`{$dateColumn}`) AS entry_date,
                       " . ($refColumn !== '' ? "p.`{$refColumn}`" : "CONCAT('PUR-',p.id)") . " AS ref_no,
                       COALESCE(s.supplier_name,'Supplier') AS party_name,
                       COALESCE(p.`{$amountColumn}`,0) AS amount,
                       'Purchase' AS entry_type,'out' AS cash_flow,
                       " . ($notesColumn !== '' ? "COALESCE(p.`{$notesColumn}`,'')" : "''") . " AS notes
                FROM purchases p LEFT JOIN suppliers s ON s.id=p.supplier_id";
        $where = ["DATE(p.`{$dateColumn}`) BETWEEN ? AND ?"];
        $types = 'ss';
        $values = [$fromDate, $toDate];
        if (hasColumn($conn,'purchases','business_id')) {$where[]='p.business_id=?';$types.='i';$values[]=$businessId;}
        if ($branchId>0 && hasColumn($conn,'purchases','branch_id')) {$where[]='p.branch_id=?';$types.='i';$values[]=$branchId;}
        $sql .= ' WHERE ' . implode(' AND ', $where);
        appendRows($conn, $entries, $sql, $types, $values);
    }
}

/* SUPPLIER PAYMENTS = CASH OUT */
if (($entryTypeFilter === '' || $entryTypeFilter === 'Supplier Payment') && tableExists($conn, 'supplier_payments')) {
    $dateColumn = firstColumn($conn, 'supplier_payments', ['payment_date']);
    $refColumn = firstColumn($conn, 'supplier_payments', ['payment_no', 'reference_no']);
    $amountColumn = firstColumn($conn, 'supplier_payments', ['amount', 'paid_amount']);
    $notesColumn = firstColumn($conn, 'supplier_payments', ['notes', 'remarks']);

    if ($dateColumn !== '' && $amountColumn !== '') {
        $sql = "SELECT sp.id, DATE(sp.`{$dateColumn}`) AS entry_date,
                       " . ($refColumn !== '' ? "sp.`{$refColumn}`" : "CONCAT('SP-',sp.id)") . " AS ref_no,
                       COALESCE(s.supplier_name,'Supplier') AS party_name,
                       COALESCE(sp.`{$amountColumn}`,0) AS amount,
                       'Supplier Payment' AS entry_type,'out' AS cash_flow,
                       " . ($notesColumn !== '' ? "COALESCE(sp.`{$notesColumn}`,'')" : "''") . " AS notes
                FROM supplier_payments sp LEFT JOIN suppliers s ON s.id=sp.supplier_id";
        $where = ["DATE(sp.`{$dateColumn}`) BETWEEN ? AND ?"];
        $types = 'ss';
        $values = [$fromDate, $toDate];
        if (hasColumn($conn,'supplier_payments','business_id')) {$where[]='sp.business_id=?';$types.='i';$values[]=$businessId;}
        if ($branchId>0 && hasColumn($conn,'supplier_payments','branch_id')) {$where[]='sp.branch_id=?';$types.='i';$values[]=$branchId;}
        $sql .= ' WHERE ' . implode(' AND ', $where);
        appendRows($conn, $entries, $sql, $types, $values);
    }
}

/* EXPENSES = CASH OUT */
if (($entryTypeFilter === '' || $entryTypeFilter === 'Expense') && tableExists($conn, 'expenses')) {
    $dateColumn = firstColumn($conn, 'expenses', ['expense_date']);
    $amountColumn = firstColumn($conn, 'expenses', ['amount']);
    $notesColumn = firstColumn($conn, 'expenses', ['description', 'notes', 'remarks']);
    $categoryIdColumn = firstColumn($conn, 'expenses', ['expense_category_id']);
    $categoryTextColumn = firstColumn($conn, 'expenses', ['expense_category', 'category']);

    if ($dateColumn !== '' && $amountColumn !== '') {
        $partyExpression = "'Expense'";
        $join = '';
        if ($categoryIdColumn !== '' && tableExists($conn, 'expense_categories')) {
            $categoryNameColumn = firstColumn($conn, 'expense_categories', ['category_name', 'name']);
            if ($categoryNameColumn !== '') {
                $partyExpression = "COALESCE(ec.`{$categoryNameColumn}`,'Expense')";
                $join = " LEFT JOIN expense_categories ec ON ec.id=e.`{$categoryIdColumn}`";
            }
        } elseif ($categoryTextColumn !== '') {
            $partyExpression = "COALESCE(e.`{$categoryTextColumn}`,'Expense')";
        }

        $sql = "SELECT e.id, DATE(e.`{$dateColumn}`) AS entry_date, CONCAT('EXP-',e.id) AS ref_no,
                       {$partyExpression} AS party_name, COALESCE(e.`{$amountColumn}`,0) AS amount,
                       'Expense' AS entry_type,'out' AS cash_flow,
                       " . ($notesColumn !== '' ? "COALESCE(e.`{$notesColumn}`,'')" : "''") . " AS notes
                FROM expenses e{$join}";
        $where = ["DATE(e.`{$dateColumn}`) BETWEEN ? AND ?"];
        $types = 'ss';
        $values = [$fromDate, $toDate];
        if (hasColumn($conn,'expenses','business_id')) {$where[]='e.business_id=?';$types.='i';$values[]=$businessId;}
        if ($branchId>0 && hasColumn($conn,'expenses','branch_id')) {$where[]='e.branch_id=?';$types.='i';$values[]=$branchId;}
        $sql .= ' WHERE ' . implode(' AND ', $where);
        appendRows($conn, $entries, $sql, $types, $values);
    }
}

usort($entries, static function (array $a, array $b): int {
    $dateCompare = strcmp((string)$a['entry_date'], (string)$b['entry_date']);
    if ($dateCompare !== 0) {
        return $dateCompare;
    }
    return strcmp((string)$a['entry_type'], (string)$b['entry_type']);
});

$totalCashIn = 0.0;
$totalCashOut = 0.0;
$runningBalance = 0.0;

foreach ($entries as $index => $entry) {
    $amount = (float)($entry['amount'] ?? 0);
    if (($entry['cash_flow'] ?? '') === 'in') {
        $totalCashIn += $amount;
        $runningBalance += $amount;
    } else {
        $totalCashOut += $amount;
        $runningBalance -= $amount;
    }

    $entries[$index]['amount'] = $amount;
    $entries[$index]['running_balance'] = $runningBalance;
    $entries[$index]['entry_date_display'] = !empty($entry['entry_date'])
        ? date('d-m-Y', strtotime((string)$entry['entry_date']))
        : '—';
}

$summary = [
    'total_entries' => count($entries),
    'total_cash_in' => $totalCashIn,
    'total_cash_out' => $totalCashOut,
    'net_balance' => $runningBalance,
];

$period = [
    'from' => $fromDate,
    'to' => $toDate,
    'from_display' => date('d-m-Y', strtotime($fromDate)),
    'to_display' => date('d-m-Y', strtotime($toDate)),
];

if ($action === 'export') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="daybook_' . date('Y-m-d_His') . '.xls"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "Day Book\n";
    echo "Period\t{$period['from_display']} to {$period['to_display']}\n\n";
    echo "Date\tType\tReference No\tParty / Category\tNotes\tCash In\tCash Out\tRunning Balance\n";

    foreach ($entries as $entry) {
        echo implode("\t", [
            $entry['entry_date_display'],
            $entry['entry_type'],
            $entry['ref_no'],
            $entry['party_name'],
            str_replace(["\t", "\r", "\n"], ' ', (string)$entry['notes']),
            $entry['cash_flow'] === 'in' ? number_format($entry['amount'], 2, '.', '') : '',
            $entry['cash_flow'] === 'out' ? number_format($entry['amount'], 2, '.', '') : '',
            number_format($entry['running_balance'], 2, '.', ''),
        ]) . "\n";
    }
    exit;
}

respond(true, 'Day book loaded.', [
    'rows' => $entries,
    'summary' => $summary,
    'period' => $period,
]);
