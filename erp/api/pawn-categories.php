<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_OFF);
ini_set('display_errors', '0');

function respond(
    bool $success,
    string $message,
    array $extra = [],
    int $status = 200
): void {
    http_response_code($status);

    echo json_encode(
        array_merge(
            ['success' => $success, 'message' => $message],
            $extra
        ),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

register_shutdown_function(static function (): void {
    $error = error_get_last();

    if (
        !$error ||
        !in_array(
            $error['type'],
            [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR],
            true
        )
    ) {
        return;
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }

    echo json_encode(
        [
            'success' => false,
            'message' => 'Fatal API error: ' . $error['message'],
        ],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
});

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
    respond(false, 'Database configuration is not available.', [], 500);
}

$conn->set_charset('utf8mb4');

if (empty($_SESSION['user_id'])) {
    respond(false, 'Your session has expired. Please log in again.', [], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Invalid request method.', [], 405);
}

if (
    !hash_equals(
        (string)($_SESSION['pawn_category_csrf'] ?? ''),
        (string)($_POST['csrf_token'] ?? '')
    )
) {
    respond(false, 'Invalid or expired request token. Refresh the page.', [], 419);
}

function tableExists(mysqli $conn, string $table): bool
{
    $safe = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safe}'");

    return $result && $result->num_rows > 0;
}

function bindDynamic(
    mysqli_stmt $stmt,
    string $types,
    array &$values
): void {
    if ($types === '') {
        return;
    }

    if (strlen($types) !== count($values)) {
        throw new RuntimeException(
            'Bind parameter mismatch. Types: ' .
            strlen($types) .
            ', Values: ' .
            count($values)
        );
    }

    $bind = [$types];

    foreach ($values as $key => $value) {
        $bind[] = &$values[$key];
    }

    call_user_func_array([$stmt, 'bind_param'], $bind);
}

function generateCategoryCode(mysqli $conn, int $businessId): string
{
    $prefix = 'PCAT';
    $nextNumber = 1;

    $stmt = $conn->prepare(
        "SELECT category_code
         FROM pawn_categories
         WHERE business_id = ?
           AND category_code LIKE 'PCAT%'
         ORDER BY id DESC
         LIMIT 1"
    );

    if ($stmt) {
        $stmt->bind_param('i', $businessId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            $lastNumber = (int)preg_replace(
                '/\D/',
                '',
                (string)$row['category_code']
            );

            $nextNumber = $lastNumber + 1;
        }
    }

    return $prefix . str_pad(
        (string)$nextNumber,
        4,
        '0',
        STR_PAD_LEFT
    );
}

function addAudit(
    mysqli $conn,
    int $businessId,
    ?int $branchId,
    int $userId,
    string $action,
    int $referenceId,
    string $description
): void {
    if (!tableExists($conn, 'audit_logs')) {
        return;
    }

    $stmt = $conn->prepare(
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
            ip_address,
            user_agent
        )
        VALUES
        (
            ?,
            ?,
            ?,
            'pawn.categories',
            ?,
            'pawn_categories',
            ?,
            ?,
            ?,
            ?
        )"
    );

    if (!$stmt) {
        return;
    }

    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $userAgent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

    $stmt->bind_param(
        'iiisisss',
        $businessId,
        $branchId,
        $userId,
        $action,
        $referenceId,
        $description,
        $ip,
        $userAgent
    );

    $stmt->execute();
    $stmt->close();
}

$businessId = (int)($_SESSION['business_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? ($_SESSION['default_branch_id'] ?? 0));
$userId = (int)($_SESSION['user_id'] ?? 0);
$action = (string)($_POST['action'] ?? '');

if ($businessId <= 0) {
    respond(false, 'A valid business must be selected.', [], 403);
}

if (!tableExists($conn, 'pawn_categories')) {
    respond(false, 'Required table pawn_categories was not found.', [], 500);
}

if ($action === 'next_code') {
    respond(
        true,
        'Category code generated.',
        [
            'category_code' => generateCategoryCode($conn, $businessId),
        ]
    );
}

if ($action === 'list') {
    $search = trim((string)($_POST['search'] ?? ''));
    $categoryType = trim((string)($_POST['category_type'] ?? ''));
    $metalType = trim((string)($_POST['metal_type'] ?? ''));
    $isActive = trim((string)($_POST['is_active'] ?? ''));

    $where = ' WHERE business_id = ?';
    $types = 'i';
    $params = [$businessId];

    if ($search !== '') {
        $like = '%' . $search . '%';

        $where .=
            ' AND (' .
            'category_name LIKE ? OR ' .
            'category_code LIKE ? OR ' .
            'category_type LIKE ? OR ' .
            'metal_type LIKE ?' .
            ')';

        $types .= 'ssss';
        array_push($params, $like, $like, $like, $like);
    }

    if ($categoryType !== '') {
        $where .= ' AND category_type = ?';
        $types .= 's';
        $params[] = $categoryType;
    }

    if ($metalType !== '') {
        $where .= ' AND metal_type = ?';
        $types .= 's';
        $params[] = $metalType;
    }

    if ($isActive !== '') {
        $where .= ' AND is_active = ?';
        $types .= 'i';
        $params[] = (int)$isActive;
    }

    $sql =
        "SELECT *
         FROM pawn_categories
         {$where}
         ORDER BY category_name ASC, id DESC";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        respond(
            false,
            'Unable to prepare category list: ' . $conn->error,
            [],
            500
        );
    }

    bindDynamic($stmt, $types, $params);
    $stmt->execute();
    $result = $stmt->get_result();

    $categories = [];

    while ($row = $result->fetch_assoc()) {
        $categories[] = $row;
    }

    $stmt->close();

    $stmt = $conn->prepare(
        "SELECT
            COUNT(*) AS total_categories,
            SUM(is_active = 1) AS active_categories,
            SUM(requires_certificate = 1) AS certificate_categories,
            SUM(requires_valuation = 1) AS valuation_categories
         FROM pawn_categories
         WHERE business_id = ?"
    );

    if (!$stmt) {
        respond(
            false,
            'Unable to prepare category statistics: ' . $conn->error,
            [],
            500
        );
    }

    $stmt->bind_param('i', $businessId);
    $stmt->execute();
    $stats = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    respond(
        true,
        'Categories loaded.',
        [
            'categories' => $categories,
            'stats' => $stats,
        ]
    );
}

if ($action === 'save') {
    $categoryId = (int)($_POST['category_id'] ?? 0);
    $categoryName = trim((string)($_POST['category_name'] ?? ''));
    $categoryCode = trim((string)($_POST['category_code'] ?? ''));
    $categoryType = trim((string)($_POST['category_type'] ?? 'Ornament'));
    $metalType = trim((string)($_POST['metal_type'] ?? 'Gold'));
    $purityStandard = trim((string)($_POST['purity_standard'] ?? ''));
    $minPurity = (float)($_POST['min_purity_percent'] ?? 0);
    $maxPurity = (float)($_POST['max_purity_percent'] ?? 100);
    $defaultInterest = (float)($_POST['default_interest_percent'] ?? 0);
    $maxLoanPercent = (float)($_POST['max_loan_percent'] ?? 0);
    $storageFeePercent = (float)($_POST['storage_fee_percent'] ?? 0);
    $valuationMethod = trim((string)($_POST['valuation_method'] ?? 'Weight'));
    $requiresCertificate = isset($_POST['requires_certificate']) ? 1 : 0;
    $requiresValuation = isset($_POST['requires_valuation']) ? 1 : 0;
    $description = trim((string)($_POST['description'] ?? ''));
    $isActive = (int)($_POST['is_active'] ?? 1);

    $allowedCategoryTypes = ['Ornament', 'Coin', 'Bar', 'Stone', 'Other'];
    $allowedMetalTypes = [
        'Gold',
        'Silver',
        'Platinum',
        'Diamond',
        'Mixed',
        'Other',
    ];
    $allowedValuationMethods = [
        'Weight',
        'Fixed',
        'Market Rate',
        'Manual',
    ];

    if ($categoryName === '') {
        respond(false, 'Category name is required.');
    }

    if (!in_array($categoryType, $allowedCategoryTypes, true)) {
        respond(false, 'Invalid category type selected.');
    }

    if (!in_array($metalType, $allowedMetalTypes, true)) {
        respond(false, 'Invalid metal type selected.');
    }

    if (!in_array($valuationMethod, $allowedValuationMethods, true)) {
        respond(false, 'Invalid valuation method selected.');
    }

    if ($minPurity < 0 || $minPurity > 100) {
        respond(false, 'Minimum purity must be between 0 and 100.');
    }

    if ($maxPurity < 0 || $maxPurity > 100) {
        respond(false, 'Maximum purity must be between 0 and 100.');
    }

    if ($minPurity > $maxPurity) {
        respond(false, 'Minimum purity cannot exceed maximum purity.');
    }

    if ($maxLoanPercent < 0 || $maxLoanPercent > 100) {
        respond(false, 'Maximum loan percentage must be between 0 and 100.');
    }

    if ($defaultInterest < 0 || $storageFeePercent < 0) {
        respond(false, 'Interest and storage fee cannot be negative.');
    }

    $isActive = $isActive === 1 ? 1 : 0;

    if ($categoryId <= 0) {
        if ($categoryCode === '') {
            $categoryCode = generateCategoryCode($conn, $businessId);
        }

        $stmt = $conn->prepare(
            "SELECT id
             FROM pawn_categories
             WHERE business_id = ?
               AND (
                    category_code = ?
                    OR category_name = ?
               )
             LIMIT 1"
        );

        if (!$stmt) {
            respond(false, 'Unable to validate category.', [], 500);
        }

        $stmt->bind_param(
            'iss',
            $businessId,
            $categoryCode,
            $categoryName
        );

        $stmt->execute();
        $duplicate = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($duplicate) {
            respond(
                false,
                'A pawn category with the same name or code already exists.'
            );
        }

        $stmt = $conn->prepare(
            "INSERT INTO pawn_categories
            (
                business_id,
                category_code,
                category_name,
                category_type,
                metal_type,
                purity_standard,
                min_purity_percent,
                max_purity_percent,
                default_interest_percent,
                max_loan_percent,
                storage_fee_percent,
                valuation_method,
                requires_certificate,
                requires_valuation,
                description,
                is_active,
                created_by
            )
            VALUES
            (
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?
            )"
        );

        if (!$stmt) {
            respond(
                false,
                'Unable to prepare category insert: ' . $conn->error,
                [],
                500
            );
        }

        $stmt->bind_param(
            'isssssdddddsiisii',
            $businessId,
            $categoryCode,
            $categoryName,
            $categoryType,
            $metalType,
            $purityStandard,
            $minPurity,
            $maxPurity,
            $defaultInterest,
            $maxLoanPercent,
            $storageFeePercent,
            $valuationMethod,
            $requiresCertificate,
            $requiresValuation,
            $description,
            $isActive,
            $userId
        );

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();

            respond(
                false,
                'Unable to create pawn category: ' . $error,
                [],
                500
            );
        }

        $savedId = (int)$stmt->insert_id;
        $stmt->close();

        addAudit(
            $conn,
            $businessId,
            $branchId > 0 ? $branchId : null,
            $userId,
            'Create',
            $savedId,
            'Created pawn category ' . $categoryCode
        );

        respond(
            true,
            'Pawn category created successfully.',
            [
                'category_id' => $savedId,
                'category_code' => $categoryCode,
            ]
        );
    }

    $stmt = $conn->prepare(
        "SELECT id
         FROM pawn_categories
         WHERE business_id = ?
           AND id <> ?
           AND (
                category_code = ?
                OR category_name = ?
           )
         LIMIT 1"
    );

    if (!$stmt) {
        respond(false, 'Unable to validate category.', [], 500);
    }

    $stmt->bind_param(
        'iiss',
        $businessId,
        $categoryId,
        $categoryCode,
        $categoryName
    );

    $stmt->execute();
    $duplicate = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($duplicate) {
        respond(
            false,
            'Another pawn category already uses the same name or code.'
        );
    }

    $stmt = $conn->prepare(
        "UPDATE pawn_categories
         SET
            category_name = ?,
            category_type = ?,
            metal_type = ?,
            purity_standard = ?,
            min_purity_percent = ?,
            max_purity_percent = ?,
            default_interest_percent = ?,
            max_loan_percent = ?,
            storage_fee_percent = ?,
            valuation_method = ?,
            requires_certificate = ?,
            requires_valuation = ?,
            description = ?,
            is_active = ?,
            updated_at = NOW()
         WHERE id = ?
           AND business_id = ?
         LIMIT 1"
    );

    if (!$stmt) {
        respond(
            false,
            'Unable to prepare category update: ' . $conn->error,
            [],
            500
        );
    }

    $stmt->bind_param(
        'ssssdddddsiisiii',
        $categoryName,
        $categoryType,
        $metalType,
        $purityStandard,
        $minPurity,
        $maxPurity,
        $defaultInterest,
        $maxLoanPercent,
        $storageFeePercent,
        $valuationMethod,
        $requiresCertificate,
        $requiresValuation,
        $description,
        $isActive,
        $categoryId,
        $businessId
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();

        respond(
            false,
            'Unable to update pawn category: ' . $error,
            [],
            500
        );
    }

    if ($stmt->affected_rows === 0) {
        $stmt->close();
        respond(false, 'Pawn category was not found or no values changed.');
    }

    $stmt->close();

    addAudit(
        $conn,
        $businessId,
        $branchId > 0 ? $branchId : null,
        $userId,
        'Update',
        $categoryId,
        'Updated pawn category ' . $categoryCode
    );

    respond(
        true,
        'Pawn category updated successfully.',
        [
            'category_id' => $categoryId,
        ]
    );
}

if ($action === 'toggle') {
    $categoryId = (int)($_POST['category_id'] ?? 0);
    $isActive = (int)($_POST['is_active'] ?? 0);
    $isActive = $isActive === 1 ? 1 : 0;

    if ($categoryId <= 0) {
        respond(false, 'Invalid pawn category selected.');
    }

    $stmt = $conn->prepare(
        "UPDATE pawn_categories
         SET is_active = ?, updated_at = NOW()
         WHERE id = ?
           AND business_id = ?
         LIMIT 1"
    );

    if (!$stmt) {
        respond(false, 'Unable to prepare status update.', [], 500);
    }

    $stmt->bind_param('iii', $isActive, $categoryId, $businessId);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();

        respond(
            false,
            'Unable to update category status: ' . $error,
            [],
            500
        );
    }

    $stmt->close();

    addAudit(
        $conn,
        $businessId,
        $branchId > 0 ? $branchId : null,
        $userId,
        $isActive === 1 ? 'Activate' : 'Deactivate',
        $categoryId,
        ($isActive === 1 ? 'Activated' : 'Deactivated') .
        ' pawn category'
    );

    respond(
        true,
        $isActive === 1
            ? 'Pawn category activated.'
            : 'Pawn category deactivated.'
    );
}

if ($action === 'delete') {
    $categoryId = (int)($_POST['category_id'] ?? 0);

    if ($categoryId <= 0) {
        respond(false, 'Invalid pawn category selected.');
    }

    if (tableExists($conn, 'pawn_entries')) {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS used_count
             FROM pawn_entries
             WHERE business_id = ?
               AND pawn_category_id = ?"
        );

        if (!$stmt) {
            respond(false, 'Unable to check category usage.', [], 500);
        }

        $stmt->bind_param('ii', $businessId, $categoryId);
        $stmt->execute();
        $usedCount = (int)(
            $stmt->get_result()->fetch_assoc()['used_count'] ?? 0
        );
        $stmt->close();

        if ($usedCount > 0) {
            respond(
                false,
                'This category is already used in pawn entries. Deactivate it instead of deleting.'
            );
        }
    }

    $stmt = $conn->prepare(
        "DELETE FROM pawn_categories
         WHERE id = ?
           AND business_id = ?
         LIMIT 1"
    );

    if (!$stmt) {
        respond(false, 'Unable to prepare category deletion.', [], 500);
    }

    $stmt->bind_param('ii', $categoryId, $businessId);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();

        respond(
            false,
            'Unable to delete pawn category: ' . $error,
            [],
            500
        );
    }

    if ($stmt->affected_rows === 0) {
        $stmt->close();
        respond(false, 'Pawn category was not found.');
    }

    $stmt->close();

    addAudit(
        $conn,
        $businessId,
        $branchId > 0 ? $branchId : null,
        $userId,
        'Delete',
        $categoryId,
        'Deleted unused pawn category'
    );

    respond(true, 'Pawn category deleted successfully.');
}

respond(false, 'Invalid action.', [], 400);
