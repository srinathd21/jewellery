<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));

foreach ([
    dirname(__DIR__) . '/config/config.php',
    dirname(__DIR__) . '/config.php',
    dirname(__DIR__) . '/includes/config.php',
    dirname(__DIR__) . '/super-admin/includes/config.php',
] as $configFile) {
    if (is_file($configFile)) {
        require_once $configFile;
        break;
    }
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    fail('Database configuration is not available.', 500);
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn->set_charset('utf8mb4');

if (empty($_SESSION['user_id'])) {
    fail('Authentication required.', 401);
}

$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? $_SESSION['default_branch_id'] ?? 0);

if ($businessId <= 0) {
    fail('A valid business must be selected.', 403);
}

$entryTable = firstExistingTable(
    $conn,
    ['old_metal_entries', 'old_silver_entries']
);

$itemTable = firstExistingTable(
    $conn,
    ['old_metal_items', 'old_silver_items']
);

if ($entryTable === null) {
    fail('Old silver entry data table was not found.', 500);
}

$entryColumns = tableColumns($conn, $entryTable);
$itemColumns = $itemTable !== null
    ? tableColumns($conn, $itemTable)
    : [];

$action = strtolower(trim((string)($_GET['action'] ?? 'list')));
$dateRange = strtolower(trim((string)($_GET['date_range'] ?? 'today')));
$fromDate = trim((string)($_GET['from_date'] ?? ''));
$toDate = trim((string)($_GET['to_date'] ?? ''));
$customerId = max(0, (int)($_GET['customer_id'] ?? 0));
$adjustmentType = trim((string)($_GET['adjustment_type'] ?? 'all'));
$search = trim((string)($_GET['search'] ?? ''));

if (!in_array($dateRange, ['today', 'yesterday', 'week', 'month', 'custom'], true)) {
    $dateRange = 'today';
}

if (!in_array($adjustmentType, ['all', 'Cash', 'Exchange', 'Pending'], true)) {
    $adjustmentType = 'all';
}

[$startDate, $endDate] = resolveDates(
    $dateRange,
    $fromDate,
    $toDate
);

$data = fetchReport(
    $conn,
    $entryTable,
    $entryColumns,
    $itemTable,
    $itemColumns,
    $businessId,
    $branchId,
    $startDate,
    $endDate,
    $customerId,
    $adjustmentType,
    $search
);

if ($action === 'export_excel') {
    exportExcel($data, $startDate, $endDate);
}

if ($action === 'export_pdf') {
    exportPdf($data, $startDate, $endDate);
}

if ($action !== 'list') {
    fail('Invalid action.', 400);
}

respond([
    'success' => true,
    'rows' => $data['rows'],
    'summary' => $data['summary'],
    'item_summary' => $data['item_summary'],
    'customers' => fetchCustomers($conn, $businessId),
    'period' => [
        'start' => $startDate,
        'end' => $endDate,
        'start_display' => date('d-m-Y', strtotime($startDate)),
        'end_display' => date('d-m-Y', strtotime($endDate)),
    ],
]);

function fetchReport(
    mysqli $conn,
    string $entryTable,
    array $entryColumns,
    ?string $itemTable,
    array $itemColumns,
    int $businessId,
    int $branchId,
    string $startDate,
    string $endDate,
    int $customerId,
    string $adjustmentType,
    string $search
): array {
    $where = [];
    $types = '';
    $params = [];

    if (isset($entryColumns['business_id'])) {
        $where[] = 'e.business_id = ?';
        $types .= 'i';
        $params[] = $businessId;
    }

    if ($branchId > 0 && isset($entryColumns['branch_id'])) {
        $where[] = 'e.branch_id = ?';
        $types .= 'i';
        $params[] = $branchId;
    }

    $dateColumn = firstColumn(
        $entryColumns,
        ['entry_date', 'purchase_date', 'created_at', 'date']
    );

    if ($dateColumn !== null) {
        $where[] = "DATE(e.`{$dateColumn}`) BETWEEN ? AND ?";
        $types .= 'ss';
        $params[] = $startDate;
        $params[] = $endDate;
    }

    if ($customerId > 0 && isset($entryColumns['customer_id'])) {
        $where[] = 'e.customer_id = ?';
        $types .= 'i';
        $params[] = $customerId;
    }

    $adjustmentColumn = firstColumn(
        $entryColumns,
        ['adjustment_type', 'settlement_type', 'payment_type']
    );

    if ($adjustmentType !== 'all' && $adjustmentColumn !== null) {
        $where[] = "e.`{$adjustmentColumn}` = ?";
        $types .= 's';
        $params[] = $adjustmentType;
    }

    if ($search !== '') {
        $searchParts = [];
        $term = '%' . $search . '%';

        foreach (['entry_no', 'customer_name', 'customer_mobile', 'remarks'] as $column) {
            if (isset($entryColumns[$column])) {
                $searchParts[] = "e.`{$column}` LIKE ?";
                $types .= 's';
                $params[] = $term;
            }
        }

        if ($searchParts) {
            $where[] = '(' . implode(' OR ', $searchParts) . ')';
        }
    }

    $customerJoin = '';
    $customerCodeExpr = "''";
    $customerMobileExpr = "''";

    if (
        isset($entryColumns['customer_id'])
        && tableExists($conn, 'customers')
    ) {
        $customerColumns = tableColumns($conn, 'customers');

        if (isset($customerColumns['id'])) {
            $customerJoin = 'LEFT JOIN customers c ON c.id = e.customer_id';

            if (isset($customerColumns['customer_code'])) {
                $customerCodeExpr = 'c.customer_code';
            } elseif (isset($customerColumns['code'])) {
                $customerCodeExpr = 'c.code';
            }

            if (isset($customerColumns['mobile'])) {
                $customerMobileExpr = 'c.mobile';
            } elseif (isset($customerColumns['phone'])) {
                $customerMobileExpr = 'c.phone';
            }
        }
    }

    $whereSql = $where
        ? 'WHERE ' . implode(' AND ', $where)
        : '';

    $orderColumn = $dateColumn !== null
        ? "e.`{$dateColumn}`"
        : 'e.id';

    $sql = "
        SELECT
            e.*,
            {$customerCodeExpr} AS customer_code,
            {$customerMobileExpr} AS customer_mobile_main
        FROM `{$entryTable}` e
        {$customerJoin}
        {$whereSql}
        ORDER BY {$orderColumn} DESC, e.id DESC
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        error_log(
            'Old silver report prepare error: '
            . $conn->error
            . ' | SQL: '
            . $sql
        );

        fail('Unable to prepare old silver report query.', 500);
    }

    if ($types !== '') {
        bindDynamic($stmt, $types, $params);
    }

    if (!$stmt->execute()) {
        error_log('Old silver report execute error: ' . $stmt->error);
        $stmt->close();
        fail('Unable to execute old silver report query.', 500);
    }

    $result = $stmt->get_result();
    $rows = [];

    while ($result && ($raw = $result->fetch_assoc())) {
        $items = fetchEntryItems(
            $conn,
            $itemTable,
            $itemColumns,
            $businessId,
            (int)$raw['id']
        );

        $row = normalizeEntry(
            $raw,
            $entryColumns,
            $items,
            $dateColumn
        );

        if (
            $adjustmentType !== 'all'
            && $adjustmentColumn === null
            && $row['adjustment_type'] !== $adjustmentType
        ) {
            continue;
        }

        $rows[] = $row;
    }

    $stmt->close();

    $summary = [
        'total_entries' => count($rows),
        'total_gross_weight' => sumColumn($rows, 'total_gross_weight'),
        'total_less_weight' => sumColumn($rows, 'total_less_weight'),
        'total_net_weight' => sumColumn($rows, 'total_net_weight'),
        'total_deduction' => sumColumn($rows, 'deduction_amount'),
        'total_final_amount' => sumColumn($rows, 'final_amount'),
        'cash_total' => 0.0,
        'exchange_total' => 0.0,
        'pending_total' => 0.0,
        'average_rate' => 0.0,
    ];

    foreach ($rows as $row) {
        $amount = (float)$row['final_amount'];

        if ($row['adjustment_type'] === 'Cash') {
            $summary['cash_total'] += $amount;
        } elseif ($row['adjustment_type'] === 'Exchange') {
            $summary['exchange_total'] += $amount;
        } else {
            $summary['pending_total'] += $amount;
        }
    }

    if ($summary['total_net_weight'] > 0) {
        $summary['average_rate'] =
            $summary['total_final_amount']
            / $summary['total_net_weight'];
    }

    return [
        'rows' => $rows,
        'summary' => $summary,
        'item_summary' => buildItemSummary($rows),
    ];
}

function normalizeEntry(
    array $raw,
    array $entryColumns,
    array $items,
    ?string $dateColumn
): array {
    $entryNo = firstValue(
        $raw,
        ['entry_no', 'reference_no', 'purchase_no'],
        'OS-' . (int)($raw['id'] ?? 0)
    );

    $entryDate = $dateColumn !== null
        ? (string)($raw[$dateColumn] ?? '')
        : '';

    $customerName = firstValue(
        $raw,
        ['customer_name', 'party_name', 'supplier_name'],
        'Walk-in Customer'
    );

    $customerMobile = firstValue(
        $raw,
        ['customer_mobile', 'mobile', 'phone'],
        (string)($raw['customer_mobile_main'] ?? '')
    );

    $grossWeight = firstNumericValue(
        $raw,
        ['total_gross_weight', 'gross_weight'],
        sumColumn($items, 'gross_weight')
    );

    $netWeight = firstNumericValue(
        $raw,
        ['total_net_weight', 'net_weight'],
        sumColumn($items, 'net_weight')
    );

    $lessWeight = firstNumericValue(
        $raw,
        ['total_less_weight', 'less_weight', 'deduction_weight'],
        max(0, $grossWeight - $netWeight)
    );

    if ($lessWeight <= 0 && $items) {
        $lessWeight =
            sumColumn($items, 'less_weight')
            + sumColumn($items, 'stone_weight')
            + sumColumn($items, 'deduction_weight');
    }

    $finalAmount = firstNumericValue(
        $raw,
        ['final_amount', 'total_value', 'value_amount', 'amount'],
        sumColumn($items, 'value_amount')
    );

    $ratePerGram = firstNumericValue(
        $raw,
        ['rate_per_gram', 'rate'],
        $netWeight > 0 ? $finalAmount / $netWeight : 0
    );

    if ($ratePerGram <= 0 && $items) {
        $weightedRateValue = 0.0;
        $weightedRateWeight = 0.0;

        foreach ($items as $item) {
            $itemWeight = (float)($item['net_weight'] ?? 0);
            $itemRate = (float)($item['rate_per_gram'] ?? 0);

            $weightedRateValue += $itemRate * $itemWeight;
            $weightedRateWeight += $itemWeight;
        }

        if ($weightedRateWeight > 0) {
            $ratePerGram = $weightedRateValue / $weightedRateWeight;
        }
    }

    $deductionAmount = firstNumericValue(
        $raw,
        ['deduction_amount'],
        max(0, ($grossWeight * $ratePerGram) - $finalAmount)
    );

    $deductionPercent = firstNumericValue(
        $raw,
        ['deduction_percent'],
        ($grossWeight * $ratePerGram) > 0
            ? ($deductionAmount / ($grossWeight * $ratePerGram)) * 100
            : 0
    );

    $adjustmentType = firstValue(
        $raw,
        ['adjustment_type', 'settlement_type', 'payment_type'],
        ''
    );

    if (!in_array($adjustmentType, ['Cash', 'Exchange', 'Pending'], true)) {
        $workflow = strtolower(
            firstValue(
                $raw,
                ['workflow_status', 'status'],
                ''
            )
        );

        if (str_contains($workflow, 'cash')) {
            $adjustmentType = 'Cash';
        } elseif (
            str_contains($workflow, 'exchange')
            || !empty($raw['linked_sale_id'])
        ) {
            $adjustmentType = 'Exchange';
        } elseif (
            str_contains($workflow, 'pending')
            || str_contains($workflow, 'draft')
        ) {
            $adjustmentType = 'Pending';
        } else {
            $adjustmentType = 'Pending';
        }
    }

    $itemNames = [];

    foreach ($items as $item) {
        $name = trim(
            (string)(
                $item['item_name']
                ?? $item['description']
                ?? $item['metal_name']
                ?? ''
            )
        );

        if ($name !== '') {
            $itemNames[] = $name;
        }
    }

    $itemNames = array_values(array_unique($itemNames));
    $itemNameText = implode(', ', array_slice($itemNames, 0, 2));

    if (count($itemNames) > 2 && $itemNameText !== '') {
        $itemNameText .= '...';
    }

    return [
        'id' => (int)($raw['id'] ?? 0),
        'entry_no' => $entryNo,
        'entry_date_display' => $entryDate !== ''
            ? date('d-m-Y', strtotime($entryDate))
            : '-',
        'customer_name' => $customerName,
        'customer_code' => (string)($raw['customer_code'] ?? ''),
        'customer_mobile_display' => $customerMobile,
        'item_count' => count($items),
        'item_names' => $itemNameText,
        'total_gross_weight' => $grossWeight,
        'total_less_weight' => $lessWeight,
        'total_net_weight' => $netWeight,
        'rate_per_gram' => $ratePerGram,
        'deduction_percent' => $deductionPercent,
        'deduction_amount' => $deductionAmount,
        'final_amount' => $finalAmount,
        'adjustment_type' => $adjustmentType,
        'linked_sale_id' => (int)($raw['linked_sale_id'] ?? 0),
        'items' => $items,
    ];
}

function fetchEntryItems(
    mysqli $conn,
    ?string $itemTable,
    array $itemColumns,
    int $businessId,
    int $entryId
): array {
    if ($itemTable === null) {
        return [];
    }

    $entryColumn = firstColumn(
        $itemColumns,
        ['old_metal_entry_id', 'old_silver_entry_id', 'entry_id']
    );

    if ($entryColumn === null) {
        return [];
    }

    $where = ["`{$entryColumn}` = ?"];
    $types = 'i';
    $params = [$entryId];

    if (isset($itemColumns['business_id'])) {
        $where[] = 'business_id = ?';
        $types .= 'i';
        $params[] = $businessId;
    }

    $sql = "
        SELECT *
        FROM `{$itemTable}`
        WHERE " . implode(' AND ', $where) . "
        ORDER BY id ASC
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return [];
    }

    bindDynamic($stmt, $types, $params);
    $stmt->execute();

    $result = $stmt->get_result();
    $rows = [];

    while ($result && ($row = $result->fetch_assoc())) {
        $row['item_name'] = firstValue(
            $row,
            ['item_name', 'description', 'metal_name'],
            'Old Silver Item'
        );

        $row['gross_weight'] = firstNumericValue(
            $row,
            ['gross_weight'],
            0
        );

        $row['stone_weight'] = firstNumericValue(
            $row,
            ['stone_weight'],
            0
        );

        $row['deduction_weight'] = firstNumericValue(
            $row,
            ['deduction_weight'],
            0
        );

        $row['less_weight'] = firstNumericValue(
            $row,
            ['less_weight'],
            $row['stone_weight'] + $row['deduction_weight']
        );

        $row['net_weight'] = firstNumericValue(
            $row,
            ['net_weight'],
            max(
                0,
                $row['gross_weight']
                - $row['stone_weight']
                - $row['deduction_weight']
            )
        );

        $row['rate_per_gram'] = firstNumericValue(
            $row,
            ['rate_per_gram', 'rate'],
            0
        );

        $row['value_amount'] = firstNumericValue(
            $row,
            ['value_amount', 'final_amount', 'amount'],
            $row['net_weight'] * $row['rate_per_gram']
        );

        $rows[] = $row;
    }

    $stmt->close();

    return $rows;
}

function buildItemSummary(array $entries): array
{
    $summary = [];

    foreach ($entries as $entry) {
        foreach (($entry['items'] ?? []) as $item) {
            $name = trim((string)($item['item_name'] ?? ''));
            $name = $name !== '' ? $name : 'Old Silver Item';

            $purity = trim((string)($item['purity'] ?? ''));
            $purity = $purity !== '' ? $purity : 'N/A';

            $key = $name . '|' . $purity;

            if (!isset($summary[$key])) {
                $summary[$key] = [
                    'item_name' => $name,
                    'purity' => $purity,
                    'gross_weight' => 0.0,
                    'less_weight' => 0.0,
                    'net_weight' => 0.0,
                    'appearances' => 0,
                ];
            }

            $summary[$key]['gross_weight'] +=
                (float)($item['gross_weight'] ?? 0);

            $summary[$key]['less_weight'] +=
                (float)($item['less_weight'] ?? 0);

            $summary[$key]['net_weight'] +=
                (float)($item['net_weight'] ?? 0);

            $summary[$key]['appearances']++;
        }
    }

    $rows = array_values($summary);

    usort(
        $rows,
        static fn(array $a, array $b): int =>
            ((float)$b['net_weight'])
            <=>
            ((float)$a['net_weight'])
    );

    return array_slice($rows, 0, 10);
}

function fetchCustomers(mysqli $conn, int $businessId): array
{
    if (!tableExists($conn, 'customers')) {
        return [];
    }

    $columns = tableColumns($conn, 'customers');
    $nameColumn = firstColumn(
        $columns,
        ['customer_name', 'name']
    );

    if ($nameColumn === null) {
        return [];
    }

    $codeColumn = firstColumn(
        $columns,
        ['customer_code', 'code']
    );

    $where = [];
    $types = '';
    $params = [];

    if (isset($columns['business_id'])) {
        $where[] = 'business_id = ?';
        $types .= 'i';
        $params[] = $businessId;
    }

    if (isset($columns['is_active'])) {
        $where[] = 'is_active = 1';
    }

    $whereSql = $where
        ? 'WHERE ' . implode(' AND ', $where)
        : '';

    $codeExpr = $codeColumn !== null
        ? "`{$codeColumn}`"
        : "''";

    $sql = "
        SELECT
            id,
            `{$nameColumn}` AS customer_name,
            {$codeExpr} AS customer_code
        FROM customers
        {$whereSql}
        ORDER BY `{$nameColumn}` ASC
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return [];
    }

    if ($types !== '') {
        bindDynamic($stmt, $types, $params);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];

    while ($result && ($row = $result->fetch_assoc())) {
        $rows[] = $row;
    }

    $stmt->close();

    return $rows;
}

function resolveDates(
    string $range,
    string $fromDate,
    string $toDate
): array {
    $today = date('Y-m-d');

    if ($range === 'yesterday') {
        $date = date('Y-m-d', strtotime('-1 day'));
        return [$date, $date];
    }

    if ($range === 'week') {
        return [
            date('Y-m-d', strtotime('monday this week')),
            $today,
        ];
    }

    if ($range === 'month') {
        return [
            date('Y-m-d', strtotime('first day of this month')),
            $today,
        ];
    }

    if ($range === 'custom') {
        $start = validDate($fromDate)
            ? $fromDate
            : date('Y-m-d', strtotime('-30 days'));

        $end = validDate($toDate)
            ? $toDate
            : $today;

        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }

        return [$start, $end];
    }

    return [$today, $today];
}

function validDate(string $date): bool
{
    $parsed = DateTime::createFromFormat('Y-m-d', $date);

    return $parsed
        && $parsed->format('Y-m-d') === $date;
}

function firstExistingTable(
    mysqli $conn,
    array $candidates
): ?string {
    foreach ($candidates as $table) {
        if (tableExists($conn, $table)) {
            return $table;
        }
    }

    return null;
}

function tableExists(mysqli $conn, string $table): bool
{
    $safe = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safe}'");

    return $result && $result->num_rows > 0;
}

function tableColumns(mysqli $conn, string $table): array
{
    $columns = [];
    $result = $conn->query("SHOW COLUMNS FROM `{$table}`");

    while ($result && ($row = $result->fetch_assoc())) {
        $columns[(string)$row['Field']] = true;
    }

    return $columns;
}

function firstColumn(
    array $columns,
    array $candidates
): ?string {
    foreach ($candidates as $candidate) {
        if (isset($columns[$candidate])) {
            return $candidate;
        }
    }

    return null;
}

function firstValue(
    array $row,
    array $candidates,
    string $fallback = ''
): string {
    foreach ($candidates as $candidate) {
        if (
            array_key_exists($candidate, $row)
            && trim((string)$row[$candidate]) !== ''
        ) {
            return (string)$row[$candidate];
        }
    }

    return $fallback;
}

function firstNumericValue(
    array $row,
    array $candidates,
    float $fallback = 0
): float {
    foreach ($candidates as $candidate) {
        if (
            array_key_exists($candidate, $row)
            && $row[$candidate] !== null
            && $row[$candidate] !== ''
        ) {
            return (float)$row[$candidate];
        }
    }

    return $fallback;
}

function sumColumn(array $rows, string $column): float
{
    $total = 0.0;

    foreach ($rows as $row) {
        $total += (float)($row[$column] ?? 0);
    }

    return $total;
}

function bindDynamic(
    mysqli_stmt $stmt,
    string $types,
    array &$params
): void {
    $bind = [$types];

    foreach ($params as $index => $value) {
        $bind[] = &$params[$index];
    }

    call_user_func_array([$stmt, 'bind_param'], $bind);
}

function exportExcel(
    array $data,
    string $startDate,
    string $endDate
): never {
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header(
        'Content-Disposition: attachment; filename="old_silver_report_'
        . date('Y-m-d')
        . '.xls"'
    );

    echo "\xEF\xBB\xBF";
    echo "Old Silver Purchase Report\n";
    echo 'Period: '
        . date('d-m-Y', strtotime($startDate))
        . ' to '
        . date('d-m-Y', strtotime($endDate))
        . "\n\n";

    echo "Entry No\tDate\tCustomer Name\tMobile\tItems\tGross Weight\tLess Weight\tNet Weight\tRate/g\tDeduction %\tDeduction Amount\tFinal Amount\tAdjustment Type\tLinked Sale\n";

    foreach ($data['rows'] as $row) {
        echo implode("\t", [
            cleanCell($row['entry_no'] ?? ''),
            cleanCell($row['entry_date_display'] ?? ''),
            cleanCell($row['customer_name'] ?? ''),
            cleanCell($row['customer_mobile_display'] ?? ''),
            (int)($row['item_count'] ?? 0),
            number_format((float)($row['total_gross_weight'] ?? 0), 3, '.', ''),
            number_format((float)($row['total_less_weight'] ?? 0), 3, '.', ''),
            number_format((float)($row['total_net_weight'] ?? 0), 3, '.', ''),
            number_format((float)($row['rate_per_gram'] ?? 0), 2, '.', ''),
            number_format((float)($row['deduction_percent'] ?? 0), 2, '.', ''),
            number_format((float)($row['deduction_amount'] ?? 0), 2, '.', ''),
            number_format((float)($row['final_amount'] ?? 0), 2, '.', ''),
            cleanCell($row['adjustment_type'] ?? ''),
            !empty($row['linked_sale_id']) ? 'Yes' : 'No',
        ]) . "\n";
    }

    exit;
}

function exportPdf(
    array $data,
    string $startDate,
    string $endDate
): never {
    $loaded = false;

    foreach ([
        dirname(__DIR__) . '/libs/fpdf.php',
        dirname(__DIR__) . '/vendor/fpdf/fpdf.php',
    ] as $file) {
        if (is_file($file)) {
            require_once $file;
            $loaded = true;
            break;
        }
    }

    if (!$loaded || !class_exists('FPDF')) {
        fail('FPDF library is not available.', 500);
    }

    $pdf = new FPDF('L', 'mm', 'A4');
    $pdf->AddPage();
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 9, 'Old Silver Purchase Report', 0, 1, 'C');

    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(
        0,
        6,
        'Period: '
        . date('d-m-Y', strtotime($startDate))
        . ' to '
        . date('d-m-Y', strtotime($endDate)),
        0,
        1,
        'C'
    );

    $pdf->Ln(4);

    $headers = [
        'Entry No',
        'Date',
        'Customer',
        'Net Wt',
        'Amount',
        'Type',
        'Linked',
    ];

    $widths = [30, 24, 55, 30, 36, 28, 24];

    $pdf->SetFillColor(70, 70, 90);
    $pdf->SetTextColor(255);
    $pdf->SetFont('Arial', 'B', 8);

    foreach ($headers as $index => $header) {
        $pdf->Cell(
            $widths[$index],
            7,
            $header,
            1,
            0,
            'C',
            true
        );
    }

    $pdf->Ln();
    $pdf->SetTextColor(0);
    $pdf->SetFont('Arial', '', 7);

    foreach ($data['rows'] as $row) {
        $cells = [
            substr((string)($row['entry_no'] ?? ''), 0, 15),
            (string)($row['entry_date_display'] ?? ''),
            substr((string)($row['customer_name'] ?? ''), 0, 28),
            number_format((float)($row['total_net_weight'] ?? 0), 3),
            number_format((float)($row['final_amount'] ?? 0), 2),
            (string)($row['adjustment_type'] ?? ''),
            !empty($row['linked_sale_id']) ? 'Yes' : 'No',
        ];

        foreach ($cells as $index => $cell) {
            $align = in_array($index, [3, 4], true)
                ? 'R'
                : 'L';

            $pdf->Cell(
                $widths[$index],
                6,
                $cell,
                1,
                0,
                $align
            );
        }

        $pdf->Ln();
    }

    $pdf->Output(
        'D',
        'old_silver_report_' . date('Y-m-d') . '.pdf'
    );

    exit;
}

function cleanCell($value): string
{
    return trim(
        str_replace(
            ["\t", "\r", "\n"],
            ' ',
            (string)$value
        )
    );
}

function respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    );

    exit;
}

function fail(string $message, int $status = 400): never
{
    respond([
        'success' => false,
        'message' => $message,
    ], $status);
}
