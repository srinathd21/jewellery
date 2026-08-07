<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string)($_SESSION['timezone'] ?? 'Asia/Kolkata'));

foreach ([
    dirname(__DIR__) . '/config/config.php',
    dirname(__DIR__) . '/config.php',
    dirname(__DIR__) . '/includes/config.php',
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

foreach (['chit_groups', 'chit_members', 'chit_collections'] as $requiredTable) {
    if (!tableExists($conn, $requiredTable)) {
        fail("Required table {$requiredTable} was not found.", 500);
    }
}

$schema = [
    'groups' => tableColumns($conn, 'chit_groups'),
    'members' => tableColumns($conn, 'chit_members'),
    'collections' => tableColumns($conn, 'chit_collections'),
    'customers' => tableExists($conn, 'customers') ? tableColumns($conn, 'customers') : [],
    'payment_methods' => tableExists($conn, 'payment_methods') ? tableColumns($conn, 'payment_methods') : [],
];

$memberGroupColumn = firstColumn($schema['members'], ['chit_group_id', 'group_id']);
$collectionGroupColumn = firstColumn($schema['collections'], ['chit_group_id', 'group_id']);
$collectionMemberColumn = firstColumn($schema['collections'], ['chit_member_id', 'member_id']);

if ($memberGroupColumn === null || $collectionGroupColumn === null || $collectionMemberColumn === null) {
    fail('Chit relation columns are not available in the database.', 500);
}

$action = strtolower(trim((string)($_GET['action'] ?? 'list')));
$reportType = strtolower(trim((string)($_GET['report_type'] ?? 'groups')));
$dateRange = strtolower(trim((string)($_GET['date_range'] ?? 'all')));
$fromDate = trim((string)($_GET['from_date'] ?? ''));
$toDate = trim((string)($_GET['to_date'] ?? ''));
$groupId = max(0, (int)($_GET['group_id'] ?? 0));
$status = trim((string)($_GET['status'] ?? 'all'));
$chitType = trim((string)($_GET['chit_type'] ?? 'all'));

if (!in_array($reportType, ['groups', 'collections', 'members'], true)) {
    $reportType = 'groups';
}

if (!in_array($dateRange, ['all', 'today', 'yesterday', 'week', 'month', 'custom'], true)) {
    $dateRange = 'all';
}

[$startDate, $endDate] = resolveDates($dateRange, $fromDate, $toDate);

$allGroups = fetchGroups(
    $conn,
    $schema,
    $memberGroupColumn,
    $collectionGroupColumn,
    $businessId,
    $branchId,
    '',
    '',
    'all',
    'all'
);

$filteredGroups = fetchGroups(
    $conn,
    $schema,
    $memberGroupColumn,
    $collectionGroupColumn,
    $businessId,
    $branchId,
    $startDate,
    $endDate,
    $status,
    $chitType
);

if ($reportType === 'groups') {
    $data = groupsReport($filteredGroups);
} elseif ($reportType === 'collections') {
    $data = collectionsReport(
        $conn,
        $schema,
        $collectionGroupColumn,
        $collectionMemberColumn,
        $businessId,
        $branchId,
        $groupId,
        $startDate,
        $endDate
    );
} else {
    $data = membersReport(
        $conn,
        $schema,
        $memberGroupColumn,
        $collectionMemberColumn,
        $businessId,
        $groupId
    );
}

if ($action === 'export_excel') {
    exportExcel($reportType, $data);
}

if ($action === 'export_pdf') {
    exportPdf($reportType, $data);
}

if ($action !== 'list') {
    fail('Invalid action.', 400);
}

respond([
    'success' => true,
    'report_type' => $reportType,
    'groups' => array_map(
        static fn(array $group): array => [
            'id' => (int)($group['id'] ?? 0),
            'group_no' => (string)($group['group_no'] ?? ''),
            'group_name' => (string)($group['group_name'] ?? ''),
        ],
        $allGroups
    ),
    'rows' => $data['rows'],
    'summary' => $data['summary'],
]);

function fetchGroups(
    mysqli $conn,
    array $schema,
    string $memberGroupColumn,
    string $collectionGroupColumn,
    int $businessId,
    int $branchId,
    string $startDate,
    string $endDate,
    string $status,
    string $chitType
): array {
    $g = $schema['groups'];
    $m = $schema['members'];
    $c = $schema['collections'];

    $where = [];
    $types = '';
    $params = [];

    if (isset($g['business_id'])) {
        $where[] = 'cg.business_id = ?';
        $types .= 'i';
        $params[] = $businessId;
    }

    if ($branchId > 0 && isset($g['branch_id'])) {
        $where[] = 'cg.branch_id = ?';
        $types .= 'i';
        $params[] = $branchId;
    }

    if ($status !== 'all' && isset($g['status'])) {
        $where[] = 'cg.status = ?';
        $types .= 's';
        $params[] = $status;
    }

    if ($chitType !== 'all' && isset($g['chit_type'])) {
        $where[] = 'cg.chit_type = ?';
        $types .= 's';
        $params[] = $chitType;
    }

    if ($startDate !== '' && $endDate !== '' && isset($g['start_date'])) {
        $where[] = 'cg.start_date BETWEEN ? AND ?';
        $types .= 'ss';
        $params[] = $startDate;
        $params[] = $endDate;
    }

    $memberBusinessFilter = isset($m['business_id'])
        ? ' AND cm.business_id = cg.business_id'
        : '';

    $collectionBusinessFilter = isset($c['business_id'])
        ? ' AND cc.business_id = cg.business_id'
        : '';

    $activeMemberCondition = isset($m['status'])
        ? " AND cm.status = 'Active'"
        : '';

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $sql = "
        SELECT
            cg.*,
            (
                SELECT COUNT(*)
                FROM chit_members cm
                WHERE cm.`{$memberGroupColumn}` = cg.id
                {$memberBusinessFilter}
            ) AS member_count_actual,
            (
                SELECT COUNT(*)
                FROM chit_members cm
                WHERE cm.`{$memberGroupColumn}` = cg.id
                {$memberBusinessFilter}
                {$activeMemberCondition}
            ) AS active_members,
            (
                SELECT COUNT(*)
                FROM chit_collections cc
                WHERE cc.`{$collectionGroupColumn}` = cg.id
                {$collectionBusinessFilter}
            ) AS total_collections,
            (
                SELECT COALESCE(SUM(cc.net_amount), 0)
                FROM chit_collections cc
                WHERE cc.`{$collectionGroupColumn}` = cg.id
                {$collectionBusinessFilter}
            ) AS total_collected
        FROM chit_groups cg
        {$whereSql}
        ORDER BY " . (isset($g['start_date']) ? 'cg.start_date DESC,' : '') . " cg.id DESC
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        error_log('Chit groups report prepare error: ' . $conn->error . ' | SQL: ' . $sql);
        fail('Unable to prepare chit groups report query.', 500);
    }

    if ($types !== '') {
        bindDynamic($stmt, $types, $params);
    }

    if (!$stmt->execute()) {
        error_log('Chit groups report execute error: ' . $stmt->error);
        $stmt->close();
        fail('Unable to execute chit groups report query.', 500);
    }

    $result = $stmt->get_result();
    $rows = [];

    while ($result && ($row = $result->fetch_assoc())) {
        $row['group_no'] = firstValue($row, ['group_no', 'code'], 'CHIT-' . (int)$row['id']);
        $row['group_name'] = firstValue($row, ['group_name', 'name'], 'Chit Group');
        $row['chit_type'] = firstValue($row, ['chit_type'], 'Money');
        $row['status'] = firstValue($row, ['status'], 'Draft');

        $row['start_date_display'] = !empty($row['start_date'])
            ? date('d-m-Y', strtotime((string)$row['start_date']))
            : '-';

        $row['end_date_display'] = !empty($row['end_date'])
            ? date('d-m-Y', strtotime((string)$row['end_date']))
            : 'Ongoing';

        $row['total_members'] = (int)(
            $row['total_members']
            ?? $row['member_count_actual']
            ?? 0
        );

        $row['installment_amount'] = (float)($row['installment_amount'] ?? 0);
        $row['chit_value'] = (float)($row['chit_value'] ?? 0);
        $row['active_members'] = (int)($row['active_members'] ?? 0);
        $row['total_collections'] = (int)($row['total_collections'] ?? 0);
        $row['total_collected'] = (float)($row['total_collected'] ?? 0);

        $rows[] = $row;
    }

    $stmt->close();

    return $rows;
}

function groupsReport(array $groups): array
{
    $summary = [
        'total_groups' => count($groups),
        'active_groups' => 0,
        'closed_groups' => 0,
        'draft_groups' => 0,
        'total_chit_value' => 0.0,
        'total_installment_amount' => 0.0,
        'total_collected' => 0.0,
        'total_collections' => 0,
        'total_members' => 0,
    ];

    foreach ($groups as $group) {
        $status = (string)$group['status'];

        if ($status === 'Active') {
            $summary['active_groups']++;
        } elseif (in_array($status, ['Closed', 'Completed'], true)) {
            $summary['closed_groups']++;
        } else {
            $summary['draft_groups']++;
        }

        $summary['total_chit_value'] += (float)$group['chit_value'];
        $summary['total_installment_amount'] += (float)$group['installment_amount'];
        $summary['total_collected'] += (float)$group['total_collected'];
        $summary['total_collections'] += (int)$group['total_collections'];
        $summary['total_members'] += (int)$group['total_members'];
    }

    return [
        'rows' => $groups,
        'summary' => $summary,
    ];
}

function collectionsReport(
    mysqli $conn,
    array $schema,
    string $collectionGroupColumn,
    string $collectionMemberColumn,
    int $businessId,
    int $branchId,
    int $groupId,
    string $startDate,
    string $endDate
): array {
    $c = $schema['collections'];
    $m = $schema['members'];
    $customers = $schema['customers'];
    $paymentMethods = $schema['payment_methods'];

    $where = [];
    $types = '';
    $params = [];

    if (isset($c['business_id'])) {
        $where[] = 'cc.business_id = ?';
        $types .= 'i';
        $params[] = $businessId;
    }

    if ($branchId > 0 && isset($c['branch_id'])) {
        $where[] = 'cc.branch_id = ?';
        $types .= 'i';
        $params[] = $branchId;
    }

    if ($groupId > 0) {
        $where[] = "cc.`{$collectionGroupColumn}` = ?";
        $types .= 'i';
        $params[] = $groupId;
    }

    if ($startDate !== '' && $endDate !== '' && isset($c['collection_date'])) {
        $where[] = 'cc.collection_date BETWEEN ? AND ?';
        $types .= 'ss';
        $params[] = $startDate;
        $params[] = $endDate;
    }

    $memberNoColumn = firstColumn($m, ['ticket_no', 'member_no']);
    $subscriberNameColumn = firstColumn($m, ['subscriber_name']);
    $memberCustomerColumn = firstColumn($m, ['customer_id']);

    $customerJoin = '';
    $subscriberNameExpr = $subscriberNameColumn !== null
        ? "cm.`{$subscriberNameColumn}`"
        : "'-'";

    if (
        $subscriberNameColumn === null
        && $memberCustomerColumn !== null
        && isset($customers['id'])
    ) {
        $customerNameColumn = firstColumn($customers, ['customer_name', 'name']);

        if ($customerNameColumn !== null) {
            $customerJoin = "LEFT JOIN customers cust ON cust.id = cm.`{$memberCustomerColumn}`";
            $subscriberNameExpr = "COALESCE(cust.`{$customerNameColumn}`, '-')";
        }
    }

    $memberNoExpr = $memberNoColumn !== null
        ? "cm.`{$memberNoColumn}`"
        : "CAST(cm.id AS CHAR)";

    $paymentJoin = '';
    $paymentMethodExpr = "''";

    if (
        isset($c['payment_method_id'])
        && isset($paymentMethods['id'])
        && isset($paymentMethods['method_name'])
    ) {
        $paymentJoin = 'LEFT JOIN payment_methods pm ON pm.id = cc.payment_method_id';
        $paymentMethodExpr = "COALESCE(pm.method_name, '')";
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $sql = "
        SELECT
            cc.*,
            cg.group_no,
            cg.group_name,
            {$memberNoExpr} AS member_no,
            {$subscriberNameExpr} AS subscriber_name,
            {$paymentMethodExpr} AS payment_method_name
        FROM chit_collections cc
        INNER JOIN chit_groups cg
            ON cg.id = cc.`{$collectionGroupColumn}`
        INNER JOIN chit_members cm
            ON cm.id = cc.`{$collectionMemberColumn}`
        {$customerJoin}
        {$paymentJoin}
        {$whereSql}
        ORDER BY " . (isset($c['collection_date']) ? 'cc.collection_date DESC,' : '') . " cc.id DESC
        LIMIT 1000
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        error_log('Chit collections report prepare error: ' . $conn->error . ' | SQL: ' . $sql);
        fail('Unable to prepare chit collections report query.', 500);
    }

    if ($types !== '') {
        bindDynamic($stmt, $types, $params);
    }

    if (!$stmt->execute()) {
        error_log('Chit collections report execute error: ' . $stmt->error);
        $stmt->close();
        fail('Unable to execute chit collections report query.', 500);
    }

    $result = $stmt->get_result();
    $rows = [];

    while ($result && ($row = $result->fetch_assoc())) {
        $row['collection_date_display'] = !empty($row['collection_date'])
            ? date('d-m-Y', strtotime((string)$row['collection_date']))
            : '-';

        foreach (['due_amount', 'paid_amount', 'discount_amount', 'penalty_amount', 'net_amount'] as $amountColumn) {
            $row[$amountColumn] = (float)($row[$amountColumn] ?? 0);
        }

        $rows[] = $row;
    }

    $stmt->close();

    $summary = [
        'total_collections' => count($rows),
        'total_due' => 0.0,
        'total_paid' => 0.0,
        'total_discount' => 0.0,
        'total_penalty' => 0.0,
        'total_net' => 0.0,
        'group_count' => 0,
        'member_count' => 0,
        'average_collection' => 0.0,
    ];

    $groupSet = [];
    $memberSet = [];

    foreach ($rows as $row) {
        $summary['total_due'] += (float)$row['due_amount'];
        $summary['total_paid'] += (float)$row['paid_amount'];
        $summary['total_discount'] += (float)$row['discount_amount'];
        $summary['total_penalty'] += (float)$row['penalty_amount'];
        $summary['total_net'] += (float)$row['net_amount'];

        $groupSet[(int)($row[$collectionGroupColumn] ?? 0)] = true;
        $memberSet[(int)($row[$collectionMemberColumn] ?? 0)] = true;
    }

    $summary['group_count'] = count(array_filter(array_keys($groupSet)));
    $summary['member_count'] = count(array_filter(array_keys($memberSet)));
    $summary['average_collection'] = count($rows) > 0
        ? $summary['total_net'] / count($rows)
        : 0;

    return [
        'rows' => $rows,
        'summary' => $summary,
    ];
}

function membersReport(
    mysqli $conn,
    array $schema,
    string $memberGroupColumn,
    string $collectionMemberColumn,
    int $businessId,
    int $groupId
): array {
    if ($groupId <= 0) {
        return emptyMembersReport();
    }

    $m = $schema['members'];
    $customers = $schema['customers'];

    $where = ["cm.`{$memberGroupColumn}` = ?"];
    $types = 'i';
    $params = [$groupId];

    if (isset($m['business_id'])) {
        $where[] = 'cm.business_id = ?';
        $types .= 'i';
        $params[] = $businessId;
    }

    $memberNoColumn = firstColumn($m, ['ticket_no', 'member_no']);
    $subscriberNameColumn = firstColumn($m, ['subscriber_name']);
    $subscriberMobileColumn = firstColumn($m, ['subscriber_mobile', 'mobile']);
    $customerIdColumn = firstColumn($m, ['customer_id']);

    $customerJoin = '';
    $subscriberNameExpr = $subscriberNameColumn !== null
        ? "cm.`{$subscriberNameColumn}`"
        : "'-'";

    $subscriberMobileExpr = $subscriberMobileColumn !== null
        ? "cm.`{$subscriberMobileColumn}`"
        : "''";

    if ($customerIdColumn !== null && isset($customers['id'])) {
        $customerJoin = "LEFT JOIN customers cust ON cust.id = cm.`{$customerIdColumn}`";

        $customerNameColumn = firstColumn($customers, ['customer_name', 'name']);
        $customerMobileColumn = firstColumn($customers, ['mobile', 'phone']);

        if ($subscriberNameColumn === null && $customerNameColumn !== null) {
            $subscriberNameExpr = "COALESCE(cust.`{$customerNameColumn}`, '-')";
        }

        if ($subscriberMobileColumn === null && $customerMobileColumn !== null) {
            $subscriberMobileExpr = "COALESCE(cust.`{$customerMobileColumn}`, '')";
        }
    }

    $memberNoExpr = $memberNoColumn !== null
        ? "cm.`{$memberNoColumn}`"
        : "CAST(cm.id AS CHAR)";

    $securityDepositExpr = isset($m['security_deposit'])
        ? 'cm.security_deposit'
        : '0';

    $openingDueExpr = isset($m['opening_due'])
        ? 'cm.opening_due'
        : '0';

    $nomineeMobileExpr = isset($m['nominee_mobile'])
        ? 'cm.nominee_mobile'
        : "''";

    $sql = "
        SELECT
            cm.*,
            {$memberNoExpr} AS member_no,
            {$subscriberNameExpr} AS subscriber_name,
            {$subscriberMobileExpr} AS subscriber_mobile,
            {$nomineeMobileExpr} AS nominee_mobile,
            {$securityDepositExpr} AS security_deposit,
            {$openingDueExpr} AS opening_due,
            (
                SELECT COALESCE(SUM(cc.net_amount), 0)
                FROM chit_collections cc
                WHERE cc.`{$collectionMemberColumn}` = cm.id
                " . (isset($schema['collections']['business_id']) ? 'AND cc.business_id = ?' : '') . "
            ) AS total_collected
        FROM chit_members cm
        {$customerJoin}
        WHERE " . implode(' AND ', $where) . "
        ORDER BY {$memberNoExpr} ASC, cm.id ASC
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        error_log('Chit members report prepare error: ' . $conn->error . ' | SQL: ' . $sql);
        fail('Unable to prepare chit members report query.', 500);
    }

    $queryTypes = '';
    $queryParams = [];

    if (isset($schema['collections']['business_id'])) {
        $queryTypes .= 'i';
        $queryParams[] = $businessId;
    }

    $queryTypes .= $types;
    $queryParams = array_merge($queryParams, $params);

    bindDynamic($stmt, $queryTypes, $queryParams);

    if (!$stmt->execute()) {
        error_log('Chit members report execute error: ' . $stmt->error);
        $stmt->close();
        fail('Unable to execute chit members report query.', 500);
    }

    $result = $stmt->get_result();
    $rows = [];

    while ($result && ($row = $result->fetch_assoc())) {
        $row['join_date_display'] = !empty($row['join_date'])
            ? date('d-m-Y', strtotime((string)$row['join_date']))
            : '-';

        $row['nominee_name'] = (string)($row['nominee_name'] ?? '');
        $row['nominee_mobile'] = (string)($row['nominee_mobile'] ?? '');
        $row['status'] = firstValue($row, ['status'], 'Active');
        $row['security_deposit'] = (float)($row['security_deposit'] ?? 0);
        $row['opening_due'] = (float)($row['opening_due'] ?? 0);
        $row['total_collected'] = (float)($row['total_collected'] ?? 0);

        $rows[] = $row;
    }

    $stmt->close();

    $summary = emptyMembersReport()['summary'];
    $summary['total_members'] = count($rows);
    $summary['group_count'] = $rows ? 1 : 0;

    foreach ($rows as $row) {
        if ($row['status'] === 'Active') {
            $summary['active_members']++;
        } else {
            $summary['inactive_members']++;
        }

        $summary['security_deposit'] += (float)$row['security_deposit'];
        $summary['opening_due'] += (float)$row['opening_due'];
        $summary['total_collected'] += (float)$row['total_collected'];
    }

    $summary['pending_due'] = max(
        0,
        $summary['opening_due'] - $summary['total_collected']
    );

    $summary['average_deposit'] = count($rows) > 0
        ? $summary['security_deposit'] / count($rows)
        : 0;

    return [
        'rows' => $rows,
        'summary' => $summary,
    ];
}

function emptyMembersReport(): array
{
    return [
        'rows' => [],
        'summary' => [
            'total_members' => 0,
            'active_members' => 0,
            'inactive_members' => 0,
            'security_deposit' => 0.0,
            'opening_due' => 0.0,
            'group_count' => 0,
            'total_collected' => 0.0,
            'pending_due' => 0.0,
            'average_deposit' => 0.0,
        ],
    ];
}

function resolveDates(
    string $range,
    string $fromDate,
    string $toDate
): array {
    $today = date('Y-m-d');

    if ($range === 'all') {
        return ['', ''];
    }

    if ($range === 'today') {
        return [$today, $today];
    }

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

function validDate(string $date): bool
{
    $parsed = DateTime::createFromFormat('Y-m-d', $date);

    return $parsed
        && $parsed->format('Y-m-d') === $date;
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

function bindDynamic(
    mysqli_stmt $stmt,
    string $types,
    array &$params
): void {
    if ($types === '') {
        return;
    }

    $bind = [$types];

    foreach ($params as $index => $value) {
        $bind[] = &$params[$index];
    }

    call_user_func_array([$stmt, 'bind_param'], $bind);
}

function exportExcel(string $reportType, array $data): never
{
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header(
        'Content-Disposition: attachment; filename="chit_'
        . $reportType
        . '_report_'
        . date('Y-m-d')
        . '.xls"'
    );

    echo "\xEF\xBB\xBF";
    echo 'Chit ' . ucfirst($reportType) . " Report\n";
    echo 'Generated On: ' . date('d-m-Y H:i:s') . "\n\n";

    if ($reportType === 'groups') {
        echo "Group No\tGroup Name\tType\tStart Date\tEnd Date\tMembers\tActive Members\tInstallment\tChit Value\tCollections\tCollected\tStatus\n";

        foreach ($data['rows'] as $row) {
            echo implode("\t", [
                clean($row['group_no'] ?? ''),
                clean($row['group_name'] ?? ''),
                clean($row['chit_type'] ?? ''),
                clean($row['start_date_display'] ?? ''),
                clean($row['end_date_display'] ?? ''),
                (int)($row['total_members'] ?? 0),
                (int)($row['active_members'] ?? 0),
                num($row['installment_amount'] ?? 0),
                num($row['chit_value'] ?? 0),
                (int)($row['total_collections'] ?? 0),
                num($row['total_collected'] ?? 0),
                clean($row['status'] ?? ''),
            ]) . "\n";
        }
    } elseif ($reportType === 'collections') {
        echo "Receipt No\tDate\tGroup\tMember No\tMember Name\tDue\tPaid\tDiscount\tPenalty\tNet\tMethod\tReference\n";

        foreach ($data['rows'] as $row) {
            echo implode("\t", [
                clean($row['receipt_no'] ?? ''),
                clean($row['collection_date_display'] ?? ''),
                clean(($row['group_no'] ?? '') . ' - ' . ($row['group_name'] ?? '')),
                clean($row['member_no'] ?? ''),
                clean($row['subscriber_name'] ?? ''),
                num($row['due_amount'] ?? 0),
                num($row['paid_amount'] ?? 0),
                num($row['discount_amount'] ?? 0),
                num($row['penalty_amount'] ?? 0),
                num($row['net_amount'] ?? 0),
                clean($row['payment_method_name'] ?? ''),
                clean($row['reference_no'] ?? ''),
            ]) . "\n";
        }
    } else {
        echo "Member No\tSubscriber Name\tMobile\tNominee\tNominee Mobile\tJoin Date\tSecurity Deposit\tOpening Due\tStatus\n";

        foreach ($data['rows'] as $row) {
            echo implode("\t", [
                clean($row['member_no'] ?? ''),
                clean($row['subscriber_name'] ?? ''),
                clean($row['subscriber_mobile'] ?? ''),
                clean($row['nominee_name'] ?? ''),
                clean($row['nominee_mobile'] ?? ''),
                clean($row['join_date_display'] ?? ''),
                num($row['security_deposit'] ?? 0),
                num($row['opening_due'] ?? 0),
                clean($row['status'] ?? ''),
            ]) . "\n";
        }
    }

    exit;
}

function exportPdf(string $reportType, array $data): never
{
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
    $pdf->Cell(0, 9, 'Chit ' . ucfirst($reportType) . ' Report', 0, 1, 'C');
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(0, 6, 'Generated on: ' . date('d-m-Y H:i:s'), 0, 1, 'C');
    $pdf->Ln(4);
    $pdf->SetFont('Arial', '', 8);

    foreach ($data['rows'] as $row) {
        if ($reportType === 'groups') {
            $text = ($row['group_no'] ?? '')
                . ' | '
                . ($row['group_name'] ?? '')
                . ' | Rs. '
                . number_format((float)($row['chit_value'] ?? 0), 2);
        } elseif ($reportType === 'collections') {
            $text = ($row['receipt_no'] ?? '')
                . ' | '
                . ($row['subscriber_name'] ?? '')
                . ' | Rs. '
                . number_format((float)($row['net_amount'] ?? 0), 2);
        } else {
            $text = ($row['member_no'] ?? '')
                . ' | '
                . ($row['subscriber_name'] ?? '')
                . ' | '
                . ($row['status'] ?? '');
        }

        $pdf->Cell(0, 6, $text, 1, 1);
    }

    $pdf->Output(
        'D',
        'chit_' . $reportType . '_report_' . date('Y-m-d') . '.pdf'
    );

    exit;
}

function clean($value): string
{
    return trim(
        str_replace(
            ["\t", "\r", "\n"],
            ' ',
            (string)$value
        )
    );
}

function num($value): string
{
    return number_format((float)$value, 2, '.', '');
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

function fail(string $message, int $status = 400): never
{
    respond([
        'success' => false,
        'message' => $message,
    ], $status);
}
