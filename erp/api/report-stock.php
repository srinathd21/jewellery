<?php
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

if (!isset($conn) || !($conn instanceof mysqli)) {
    respondError('Database configuration is not available.', 500);
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn->set_charset('utf8mb4');

$userId = (int)($_SESSION['user_id'] ?? 0);
$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? $_SESSION['default_branch_id'] ?? 0);

if ($userId <= 0) {
    respondError('Authentication required.', 401);
}

if ($businessId <= 0) {
    respondError('A valid business must be selected.', 403);
}

if (!tableExists($conn, 'products')) {
    respondError('Required products table was not found.', 500);
}

$schema = buildSchema($conn);
$action = strtolower(trim((string)($_GET['action'] ?? 'list')));

if ($action === 'bootstrap') {
    respond([
        'success' => true,
        'categories' => fetchCategories($conn, $businessId, $schema),
        'purities' => fetchPurities($conn, $businessId, $schema),
    ]);
}

$categoryId = max(0, (int)($_GET['category_id'] ?? 0));
$stockType = strtolower(trim((string)($_GET['stock_type'] ?? 'all')));
$purity = trim((string)($_GET['purity'] ?? 'all'));
$search = trim((string)($_GET['search'] ?? ''));
$sortBy = trim((string)($_GET['sort_by'] ?? 'product_name'));
$sortOrder = strtoupper(trim((string)($_GET['sort_order'] ?? 'ASC')));

if (!in_array($stockType, ['all', 'in_stock', 'low_stock', 'out_of_stock'], true)) {
    $stockType = 'all';
}

if (!in_array($sortBy, ['product_name', 'product_code', 'current_stock_qty', 'sale_rate'], true)) {
    $sortBy = 'product_name';
}

if (!in_array($sortOrder, ['ASC', 'DESC'], true)) {
    $sortOrder = 'ASC';
}

$data = fetchReport(
    $conn,
    $schema,
    $businessId,
    $branchId,
    $categoryId,
    $stockType,
    $purity,
    $search,
    $sortBy,
    $sortOrder
);

if ($action === 'export_excel') {
    exportExcel($data);
}

if ($action === 'export_pdf') {
    exportPdf($data);
}

if ($action !== 'list') {
    respondError('Invalid action.', 400);
}

respond([
    'success' => true,
    'rows' => $data['rows'],
    'summary' => $data['summary'],
    'category_summary' => $data['category_summary'],
    'purity_summary' => $data['purity_summary'],
]);

function buildSchema(mysqli $conn): array
{
    $productColumns = tableColumns($conn, 'products');

    $categoryTable = '';
    $categoryColumns = [];

    foreach (['product_categories', 'categories'] as $candidate) {
        if (tableExists($conn, $candidate)) {
            $categoryTable = $candidate;
            $categoryColumns = tableColumns($conn, $candidate);
            break;
        }
    }

    return [
        'product_columns' => $productColumns,
        'category_table' => $categoryTable,
        'category_columns' => $categoryColumns,
        'has_product_stock' => tableExists($conn, 'product_stock'),
        'product_stock_columns' => tableExists($conn, 'product_stock')
            ? tableColumns($conn, 'product_stock')
            : [],
    ];
}

function fetchReport(
    mysqli $conn,
    array $schema,
    int $businessId,
    int $branchId,
    int $categoryId,
    string $stockType,
    string $purity,
    string $search,
    string $sortBy,
    string $sortOrder
): array {
    $p = $schema['product_columns'];

    $productNameExpr = columnExpr($p, 'p', ['product_name', 'name'], "CONCAT('Product #', p.id)");
    $productCodeExpr = columnExpr($p, 'p', ['product_code', 'code', 'sku'], "CAST(p.id AS CHAR)");
    $designExpr = columnExpr($p, 'p', ['design_name', 'design'], "''");
    $barcodeExpr = columnExpr($p, 'p', ['barcode', 'bar_code'], "''");
    $purityExpr = columnExpr($p, 'p', ['purity'], "''");
    $unitExpr = columnExpr($p, 'p', ['unit', 'unit_name'], "'pcs'");
    $minQtyExpr = columnExpr($p, 'p', ['min_stock_qty', 'minimum_stock', 'reorder_level'], '0');
    $saleRateExpr = columnExpr($p, 'p', ['sale_rate', 'selling_price', 'sales_rate'], '0');
    $productNetWeightExpr = columnExpr($p, 'p', ['net_weight', 'weight'], '0');
    $categoryIdExpr = columnExpr($p, 'p', ['category_id'], '0');

    $stockJoin = '';
    $qtyExpr = columnExpr($p, 'p', ['current_stock_qty', 'stock_qty', 'quantity'], '0');
    $totalWeightExpr = '(' . $productNetWeightExpr . ' * ' . $qtyExpr . ')';
    $stockValueExpr = '(' . $saleRateExpr . ' * ' . $qtyExpr . ')';

    if ($schema['has_product_stock']) {
        $ps = $schema['product_stock_columns'];

        if (isset($ps['product_id'])) {
            $quantityColumn = firstColumn($ps, ['quantity', 'stock_qty', 'closing_qty']);
            $grossWeightColumn = firstColumn($ps, ['gross_weight']);
            $netWeightColumn = firstColumn($ps, ['net_weight', 'weight']);
            $stockValueColumn = firstColumn($ps, ['stock_value', 'value_amount']);

            $stockWhere = [];

            if (isset($ps['business_id'])) {
                $stockWhere[] = 'business_id = ' . $businessId;
            }

            if ($branchId > 0 && isset($ps['branch_id'])) {
                $stockWhere[] = 'branch_id = ' . $branchId;
            }

            $stockWhereSql = $stockWhere
                ? 'WHERE ' . implode(' AND ', $stockWhere)
                : '';

            $quantitySelect = $quantityColumn
                ? "SUM(COALESCE(`{$quantityColumn}`,0))"
                : '0';

            $grossWeightSelect = $grossWeightColumn
                ? "SUM(COALESCE(`{$grossWeightColumn}`,0))"
                : '0';

            $netWeightSelect = $netWeightColumn
                ? "SUM(COALESCE(`{$netWeightColumn}`,0))"
                : '0';

            $stockValueSelect = $stockValueColumn
                ? "SUM(COALESCE(`{$stockValueColumn}`,0))"
                : '0';

            $stockJoin = "
                LEFT JOIN (
                    SELECT
                        product_id,
                        {$quantitySelect} AS stock_quantity,
                        {$grossWeightSelect} AS stock_gross_weight,
                        {$netWeightSelect} AS stock_net_weight,
                        {$stockValueSelect} AS stock_value
                    FROM product_stock
                    {$stockWhereSql}
                    GROUP BY product_id
                ) ps ON ps.product_id = p.id
            ";

            $qtyExpr = 'COALESCE(ps.stock_quantity,0)';
            $totalWeightExpr = "
                CASE
                    WHEN COALESCE(ps.stock_net_weight,0) <> 0
                        THEN COALESCE(ps.stock_net_weight,0)
                    WHEN COALESCE(ps.stock_gross_weight,0) <> 0
                        THEN COALESCE(ps.stock_gross_weight,0)
                    ELSE ({$productNetWeightExpr} * {$qtyExpr})
                END
            ";
            $stockValueExpr = "
                CASE
                    WHEN COALESCE(ps.stock_value,0) <> 0
                        THEN COALESCE(ps.stock_value,0)
                    ELSE ({$saleRateExpr} * {$qtyExpr})
                END
            ";
        }
    }

    $categoryJoin = '';
    $categoryNameExpr = "'Uncategorized'";
    $categoryGstExpr = '0';

    if ($schema['category_table'] !== '' && $categoryIdExpr !== '0') {
        $ct = $schema['category_table'];
        $cc = $schema['category_columns'];

        if (isset($cc['id'])) {
            $categoryNameColumn = firstColumn($cc, ['category_name', 'name']);
            $gstColumn = firstColumn($cc, ['gst_percent', 'gst_percentage']);

            $categoryJoin = "LEFT JOIN `{$ct}` pc ON pc.id = {$categoryIdExpr}";
            $categoryNameExpr = $categoryNameColumn
                ? "COALESCE(pc.`{$categoryNameColumn}`,'Uncategorized')"
                : "'Uncategorized'";
            $categoryGstExpr = $gstColumn
                ? "COALESCE(pc.`{$gstColumn}`,0)"
                : '0';
        }
    }

    $where = [];

    if (isset($p['business_id'])) {
        $where[] = 'p.business_id = ?';
    }

    if (isset($p['is_active'])) {
        $where[] = 'p.is_active = 1';
    }

    $types = isset($p['business_id']) ? 'i' : '';
    $params = isset($p['business_id']) ? [$businessId] : [];

    if ($categoryId > 0 && $categoryIdExpr !== '0') {
        $where[] = "{$categoryIdExpr} = ?";
        $types .= 'i';
        $params[] = $categoryId;
    }

    if ($purity !== 'all' && $purity !== '' && $purityExpr !== "''") {
        $where[] = "{$purityExpr} = ?";
        $types .= 's';
        $params[] = $purity;
    }

    if ($search !== '') {
        $searchExpressions = array_filter([
            $productNameExpr,
            $productCodeExpr,
            $designExpr !== "''" ? $designExpr : null,
            $barcodeExpr !== "''" ? $barcodeExpr : null,
        ]);

        if ($searchExpressions) {
            $conditions = [];
            $term = '%' . $search . '%';

            foreach ($searchExpressions as $expression) {
                $conditions[] = "{$expression} LIKE ?";
                $types .= 's';
                $params[] = $term;
            }

            $where[] = '(' . implode(' OR ', $conditions) . ')';
        }
    }

    if ($stockType === 'low_stock') {
        $where[] = "({$qtyExpr}) <= ({$minQtyExpr})";
        $where[] = "({$qtyExpr}) > 0";
    } elseif ($stockType === 'out_of_stock') {
        $where[] = "({$qtyExpr}) <= 0";
    } elseif ($stockType === 'in_stock') {
        $where[] = "({$qtyExpr}) > 0";
    }

    $sortMap = [
        'product_name' => 'product_name',
        'product_code' => 'product_code',
        'current_stock_qty' => 'current_stock_qty',
        'sale_rate' => 'sale_rate',
    ];

    $orderAlias = $sortMap[$sortBy] ?? 'product_name';
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $sql = "
        SELECT
            p.id,
            {$productCodeExpr} AS product_code,
            {$productNameExpr} AS product_name,
            {$designExpr} AS design_name,
            {$barcodeExpr} AS barcode,
            {$purityExpr} AS purity,
            {$unitExpr} AS unit,
            {$categoryIdExpr} AS category_id,
            {$categoryNameExpr} AS category_name,
            {$categoryGstExpr} AS gst_percent,
            ({$qtyExpr}) AS current_stock_qty,
            ({$minQtyExpr}) AS min_stock_qty,
            ({$productNetWeightExpr}) AS net_weight,
            ({$totalWeightExpr}) AS total_weight,
            ({$saleRateExpr}) AS sale_rate,
            ({$stockValueExpr}) AS stock_value
        FROM products p
        {$stockJoin}
        {$categoryJoin}
        {$whereSql}
        ORDER BY `{$orderAlias}` {$sortOrder}, p.id ASC
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        error_log('Stock report SQL prepare error: ' . $conn->error . ' | SQL: ' . $sql);
        respondError('Unable to prepare stock report query.', 500);
    }

    if ($types !== '') {
        bindDynamic($stmt, $types, $params);
    }

    if (!$stmt->execute()) {
        error_log('Stock report SQL execute error: ' . $stmt->error);
        $stmt->close();
        respondError('Unable to execute stock report query.', 500);
    }

    $result = $stmt->get_result();
    $rows = [];

    while ($result && ($row = $result->fetch_assoc())) {
        $qty = (float)($row['current_stock_qty'] ?? 0);
        $minQty = (float)($row['min_stock_qty'] ?? 0);

        if ($qty <= 0) {
            $row['stock_status'] = 'Out of Stock';
        } elseif ($qty <= $minQty) {
            $row['stock_status'] = 'Low Stock';
        } else {
            $row['stock_status'] = 'In Stock';
        }

        $rows[] = $row;
    }

    $stmt->close();

    $summary = [
        'total_products' => count($rows),
        'total_quantity' => sumColumn($rows, 'current_stock_qty'),
        'total_weight' => sumColumn($rows, 'total_weight'),
        'total_value' => sumColumn($rows, 'stock_value'),
        'in_stock_count' => 0,
        'low_stock_count' => 0,
        'out_of_stock_count' => 0,
    ];

    $categoryMap = [];
    $purityMap = [];

    foreach ($rows as $row) {
        $status = (string)$row['stock_status'];

        if ($status === 'In Stock') {
            $summary['in_stock_count']++;
        } elseif ($status === 'Low Stock') {
            $summary['low_stock_count']++;
        } else {
            $summary['out_of_stock_count']++;
        }

        $category = trim((string)($row['category_name'] ?? ''));
        $category = $category !== '' ? $category : 'Uncategorized';

        if (!isset($categoryMap[$category])) {
            $categoryMap[$category] = [
                'label' => $category,
                'count' => 0,
                'weight' => 0.0,
                'value' => 0.0,
            ];
        }

        $categoryMap[$category]['count']++;
        $categoryMap[$category]['weight'] += (float)$row['total_weight'];
        $categoryMap[$category]['value'] += (float)$row['stock_value'];

        $purityLabel = trim((string)($row['purity'] ?? ''));
        $purityLabel = $purityLabel !== '' ? $purityLabel : 'N/A';

        if (!isset($purityMap[$purityLabel])) {
            $purityMap[$purityLabel] = [
                'label' => $purityLabel,
                'count' => 0,
                'weight' => 0.0,
                'value' => 0.0,
            ];
        }

        $purityMap[$purityLabel]['count']++;
        $purityMap[$purityLabel]['weight'] += (float)$row['total_weight'];
        $purityMap[$purityLabel]['value'] += (float)$row['stock_value'];
    }

    return [
        'rows' => $rows,
        'summary' => $summary,
        'category_summary' => addPercentages(
            array_values($categoryMap),
            (float)$summary['total_value']
        ),
        'purity_summary' => addPercentages(
            array_values($purityMap),
            (float)$summary['total_value']
        ),
    ];
}

function fetchCategories(mysqli $conn, int $businessId, array $schema): array
{
    $table = $schema['category_table'];
    $columns = $schema['category_columns'];

    if ($table === '' || !isset($columns['id'])) {
        return [];
    }

    $nameColumn = firstColumn($columns, ['category_name', 'name']);

    if (!$nameColumn) {
        return [];
    }

    $where = [];

    if (isset($columns['business_id'])) {
        $where[] = 'business_id = ?';
    }

    if (isset($columns['is_active'])) {
        $where[] = 'is_active = 1';
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $sql = "
        SELECT id, `{$nameColumn}` AS category_name
        FROM `{$table}`
        {$whereSql}
        ORDER BY `{$nameColumn}` ASC
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return [];
    }

    if (isset($columns['business_id'])) {
        $stmt->bind_param('i', $businessId);
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

function fetchPurities(mysqli $conn, int $businessId, array $schema): array
{
    $columns = $schema['product_columns'];

    if (!isset($columns['purity'])) {
        return [];
    }

    $where = [
        "purity IS NOT NULL",
        "purity <> ''",
    ];

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

    $sql = "
        SELECT DISTINCT purity
        FROM products
        WHERE " . implode(' AND ', $where) . "
        ORDER BY purity ASC
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
        $rows[] = (string)$row['purity'];
    }

    $stmt->close();

    return $rows;
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

function firstColumn(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (isset($columns[$candidate])) {
            return $candidate;
        }
    }

    return null;
}

function columnExpr(
    array $columns,
    string $alias,
    array $candidates,
    string $fallback
): string {
    $column = firstColumn($columns, $candidates);

    return $column
        ? "{$alias}.`{$column}`"
        : $fallback;
}

function addPercentages(array $rows, float $totalValue): array
{
    foreach ($rows as &$row) {
        $row['percentage'] = $totalValue > 0
            ? ((float)$row['value'] / $totalValue) * 100
            : 0;
    }

    unset($row);

    return $rows;
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

function exportExcel(array $data): never
{
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header(
        'Content-Disposition: attachment; filename="stock_report_'
        . date('Y-m-d')
        . '.xls"'
    );

    echo "\xEF\xBB\xBF";
    echo "Stock Report\n";
    echo 'Generated On: ' . date('d-m-Y H:i:s') . "\n\n";

    echo "Product Code\tProduct Name\tCategory\tPurity\tUnit\tCurrent Stock\tMin Stock\tNet Weight(g)\tTotal Weight(g)\tSale Rate\tStock Value\tStatus\n";

    foreach ($data['rows'] as $row) {
        echo implode("\t", [
            cleanCell($row['product_code'] ?? ''),
            cleanCell($row['product_name'] ?? ''),
            cleanCell($row['category_name'] ?? 'Uncategorized'),
            cleanCell($row['purity'] ?? ''),
            cleanCell($row['unit'] ?? ''),
            number_format((float)($row['current_stock_qty'] ?? 0), 3, '.', ''),
            number_format((float)($row['min_stock_qty'] ?? 0), 3, '.', ''),
            number_format((float)($row['net_weight'] ?? 0), 3, '.', ''),
            number_format((float)($row['total_weight'] ?? 0), 3, '.', ''),
            number_format((float)($row['sale_rate'] ?? 0), 2, '.', ''),
            number_format((float)($row['stock_value'] ?? 0), 2, '.', ''),
            cleanCell($row['stock_status'] ?? ''),
        ]) . "\n";
    }

    echo "\nSUMMARY\n";
    echo "Total Products\t" . (int)$data['summary']['total_products'] . "\n";
    echo "Total Quantity\t" . number_format((float)$data['summary']['total_quantity'], 3, '.', '') . "\n";
    echo "Total Weight\t" . number_format((float)$data['summary']['total_weight'], 3, '.', '') . "\n";
    echo "Total Stock Value\t" . number_format((float)$data['summary']['total_value'], 2, '.', '') . "\n";
    echo "In Stock\t" . (int)$data['summary']['in_stock_count'] . "\n";
    echo "Low Stock\t" . (int)$data['summary']['low_stock_count'] . "\n";
    echo "Out of Stock\t" . (int)$data['summary']['out_of_stock_count'] . "\n";

    exit;
}

function exportPdf(array $data): never
{
    $loaded = false;

    foreach ([
        dirname(__DIR__) . '/libs/fpdf.php',
        dirname(__DIR__) . '/vendor/fpdf/fpdf.php',
    ] as $fpdfFile) {
        if (is_file($fpdfFile)) {
            require_once $fpdfFile;
            $loaded = true;
            break;
        }
    }

    if (!$loaded || !class_exists('FPDF')) {
        respondError('FPDF library is not available.', 500);
    }

    $summary = $data['summary'];

    $pdf = new FPDF('L', 'mm', 'A4');
    $pdf->AddPage();
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 9, 'Stock Report', 0, 1, 'C');
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(0, 6, 'Generated on: ' . date('d-m-Y H:i:s'), 0, 1, 'C');
    $pdf->Ln(4);

    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(0, 6, 'Total Products: ' . (int)$summary['total_products'], 0, 1);
    $pdf->Cell(0, 6, 'Total Quantity: ' . number_format((float)$summary['total_quantity'], 3), 0, 1);
    $pdf->Cell(0, 6, 'Total Weight: ' . number_format((float)$summary['total_weight'], 3) . ' g', 0, 1);
    $pdf->Cell(0, 6, 'Total Stock Value: Rs. ' . number_format((float)$summary['total_value'], 2), 0, 1);
    $pdf->Ln(4);

    $headers = ['Code', 'Product', 'Category', 'Qty', 'Weight', 'Rate', 'Value', 'Status'];
    $widths = [28, 50, 38, 24, 28, 30, 34, 28];

    $pdf->SetFillColor(70, 70, 90);
    $pdf->SetTextColor(255);
    $pdf->SetFont('Arial', 'B', 8);

    foreach ($headers as $index => $header) {
        $pdf->Cell($widths[$index], 7, $header, 1, 0, 'C', true);
    }

    $pdf->Ln();
    $pdf->SetTextColor(0);
    $pdf->SetFont('Arial', '', 7);

    foreach ($data['rows'] as $row) {
        $cells = [
            substr((string)($row['product_code'] ?? ''), 0, 14),
            substr((string)($row['product_name'] ?? ''), 0, 25),
            substr((string)($row['category_name'] ?? 'Uncategorized'), 0, 20),
            number_format((float)($row['current_stock_qty'] ?? 0), 3),
            number_format((float)($row['total_weight'] ?? 0), 3),
            number_format((float)($row['sale_rate'] ?? 0), 2),
            number_format((float)($row['stock_value'] ?? 0), 2),
            (string)($row['stock_status'] ?? ''),
        ];

        foreach ($cells as $index => $cell) {
            $align = $index >= 3 && $index <= 6 ? 'R' : 'L';
            $pdf->Cell($widths[$index], 6, $cell, 1, 0, $align);
        }

        $pdf->Ln();
    }

    $pdf->Output('D', 'stock_report_' . date('Y-m-d') . '.pdf');
    exit;
}

function cleanCell($value): string
{
    return trim(str_replace(["\t", "\r", "\n"], ' ', (string)$value));
}

function respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function respondError(string $message, int $status = 400): never
{
    respond([
        'success' => false,
        'message' => $message,
    ], $status);
}
