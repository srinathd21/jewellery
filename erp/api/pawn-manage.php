<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_OFF);
ini_set('display_errors', '0');

function respond($success, $message = '', $extra = array(), $status = 200)
{
    http_response_code($status);
    echo json_encode(array_merge(array(
        'success' => (bool) $success,
        'message' => (string) $message,
    ), $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
        respond(false, 'Fatal API error: ' . $error['message'], array(), 500);
    }
});

foreach (array(
    dirname(__DIR__) . '/config/config.php',
    dirname(__DIR__) . '/config.php',
    dirname(__DIR__) . '/includes/config.php',
    dirname(__DIR__) . '/super-admin/includes/config.php',
) as $configFile) {
    if (is_file($configFile)) {
        require_once $configFile;
        break;
    }
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    respond(false, 'Database configuration is not available.', array(), 500);
}
$conn->set_charset('utf8mb4');

if (empty($_SESSION['user_id'])) {
    respond(false, 'Session expired.', array(), 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Invalid request method.', array(), 405);
}
if (!hash_equals((string) ($_SESSION['pawn_csrf'] ?? ''), (string) ($_POST['csrf_token'] ?? ''))) {
    respond(false, 'Invalid request token. Refresh the page.', array(), 419);
}

$businessId = (int) ($_SESSION['business_id'] ?? 0);
$branchId = (int) ($_SESSION['branch_id'] ?? ($_SESSION['default_branch_id'] ?? 0));
$userId = (int) ($_SESSION['user_id'] ?? 0);
$action = trim((string) ($_POST['action'] ?? ''));

if ($businessId <= 0) {
    respond(false, 'Select a valid business.', array(), 403);
}

function bindDynamic(mysqli_stmt $stmt, string $types, array &$values): void
{
    if (strlen($types) !== count($values)) {
        throw new RuntimeException('Prepared statement bind mismatch.');
    }
    $args = array($types);
    foreach ($values as $key => $value) {
        $args[] =& $values[$key];
    }
    call_user_func_array(array($stmt, 'bind_param'), $args);
}

function tableExists(mysqli $conn, string $table): bool
{
    $table = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$table}'");
    return $result && $result->num_rows > 0;
}

function writeAudit(mysqli $conn, int $businessId, int $branchId, int $userId, int $pawnId, string $description, array $oldValues): void
{
    if (!tableExists($conn, 'audit_logs')) {
        return;
    }

    $stmt = $conn->prepare(
        "INSERT INTO audit_logs
        (business_id,branch_id,user_id,module_code,action_type,reference_table,reference_id,description,old_values_json,ip_address,user_agent)
        VALUES (?,?,?,'pawn.manage','Delete','pawn_entries',?,?,?,?,?)"
    );
    if (!$stmt) {
        return;
    }

    $json = json_encode($oldValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $stmt->bind_param('iiiissss', $businessId, $branchId, $userId, $pawnId, $description, $json, $ip, $userAgent);
    $stmt->execute();
    $stmt->close();
}

if ($action === 'list') {
    $search = trim((string) ($_POST['search'] ?? ''));
    $status = trim((string) ($_POST['status'] ?? ''));
    $fromDate = trim((string) ($_POST['from_date'] ?? ''));
    $toDate = trim((string) ($_POST['to_date'] ?? ''));

    $where = array('p.business_id=?');
    $types = 'i';
    $params = array($businessId);
    if ($status !== '') {
        $where[] = 'p.status=?';
        $types .= 's';
        $params[] = $status;
    }
    if ($fromDate !== '') {
        $where[] = 'p.pawn_date>=?';
        $types .= 's';
        $params[] = $fromDate;
    }
    if ($toDate !== '') {
        $where[] = 'p.pawn_date<=?';
        $types .= 's';
        $params[] = $toDate;
    }
    if ($search !== '') {
        $like = '%' . $search . '%';
        $where[] = '(p.pawn_no LIKE ? OR c.customer_name LIKE ? OR c.customer_code LIKE ? OR c.mobile LIKE ?)';
        $types .= 'ssss';
        array_push($params, $like, $like, $like, $like);
    }

    $sql = "SELECT
                p.id,
                p.pawn_no,
                p.pawn_date,
                p.due_date,
                p.principal_amount,
                p.total_principal_paid,
                p.balance_principal,
                p.total_interest_collected,
                p.interest_percent,
                COALESCE(p.current_interest_percent,p.interest_percent,0) AS current_interest_percent,
                COALESCE(p.initial_interest_percent,p.interest_percent,0) AS initial_interest_percent,
                p.interest_period,
                p.interest_method,
                p.interest_collection_cycle,
                p.interest_cycle_months,
                p.interest_scheme_id,
                p.initial_rate_step_id,
                p.current_rate_step_id,
                p.interest_due_cycle_type,
                p.interest_due_cycle_value,
                p.interest_grace_days,
                p.last_interest_paid_upto,
                p.next_interest_due_date,
                p.missed_interest_cycles,
                p.rate_escalation_count,
                p.rate_escalated_at,
                p.interest_rule_locked,
                p.re_registered_from_pawn_id,
                p.re_registered_to_pawn_id,
                p.bank_pledge_status,
                p.status,
                COALESCE(c.customer_name,'') AS customer_name,
                COALESCE(c.customer_code,'') AS customer_code,
                COALESCE(c.mobile,'') AS mobile,
                COALESCE(pc.category_name,'') AS category_name,
                COALESCE(b.branch_name,'') AS branch_name,
                COALESCE(pis.scheme_code,'') AS interest_scheme_code,
                COALESCE(pis.scheme_name,'') AS interest_scheme_name,
                COALESCE(prs.level_no,1) AS current_rate_level,
                prs.next_level_no AS next_rate_level,
                nrs.rate_percent AS next_interest_percent,
                COALESCE(prs.grace_days,p.interest_grace_days,0) AS effective_grace_days
            FROM pawn_entries p
            LEFT JOIN customers c
                ON c.id=p.customer_id
               AND c.business_id=p.business_id
            LEFT JOIN pawn_categories pc
                ON pc.id=p.pawn_category_id
               AND pc.business_id=p.business_id
            LEFT JOIN branches b
                ON b.id=p.branch_id
               AND b.business_id=p.business_id
            LEFT JOIN pawn_interest_schemes pis
                ON pis.id=p.interest_scheme_id
               AND pis.business_id=p.business_id
            LEFT JOIN pawn_interest_rate_steps prs
                ON prs.id=p.current_rate_step_id
               AND prs.business_id=p.business_id
            LEFT JOIN pawn_interest_rate_steps nrs
                ON nrs.scheme_id=prs.scheme_id
               AND nrs.level_no=prs.next_level_no
               AND nrs.business_id=p.business_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY p.id DESC
            LIMIT 1000";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        respond(false, 'Unable to load pawn entries: ' . $conn->error, array(), 500);
    }
    bindDynamic($stmt, $types, $params);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = array();
    while ($row = $result->fetch_assoc()) {
        $row['grace_until'] = null;
        $nextDue = isset($row['next_interest_due_date']) ? (string)$row['next_interest_due_date'] : '';
        $graceDays = isset($row['effective_grace_days']) ? (int)$row['effective_grace_days'] : 0;
        if ($nextDue !== '') {
            try {
                $dt = new DateTime($nextDue);
                if ($graceDays > 0) {
                    $dt->modify('+' . $graceDays . ' days');
                }
                $row['grace_until'] = $dt->format('Y-m-d');
            } catch (Throwable $ignore) {
                $row['grace_until'] = $nextDue;
            }
        }
        $row['active_bank_collateral_count'] = 0;
        if (tableExists($conn, 'pawn_bank_loan_items')) {
            $bc = $conn->prepare("SELECT COUNT(*) cnt FROM pawn_bank_loan_items WHERE pawn_entry_id=? AND business_id=? AND status='Pledged'");
            if ($bc) {
                $pid = (int)$row['id'];
                $bc->bind_param('ii', $pid, $businessId);
                $bc->execute();
                $br = $bc->get_result()->fetch_assoc();
                $row['active_bank_collateral_count'] = (int)($br['cnt'] ?? 0);
                $bc->close();
            }
        }
        $rows[] = $row;
    }
    $stmt->close();

    $statsWhere = 'business_id=?';
    $statsTypes = 'i';
    $statsParams = array($businessId);

    $statsSql = "SELECT
                    COUNT(*) AS total,
                    COALESCE(SUM(status='Active'),0) AS active,
                    COALESCE(SUM(status='Partially Paid'),0) AS partial,
                    COALESCE(SUM(status='Closed'),0) AS closed,
                    COALESCE(SUM(CASE WHEN status IN ('Active','Partially Paid') THEN balance_principal ELSE 0 END),0) AS outstanding
                 FROM pawn_entries
                 WHERE {$statsWhere}";
    $statsStmt = $conn->prepare($statsSql);
    if (!$statsStmt) {
        respond(false, 'Unable to calculate pawn summary: ' . $conn->error, array(), 500);
    }
    bindDynamic($statsStmt, $statsTypes, $statsParams);
    $statsStmt->execute();
    $stats = $statsStmt->get_result()->fetch_assoc() ?: array();
    $statsStmt->close();

    respond(true, '', array('rows' => $rows, 'stats' => $stats, 'scope' => array('business_id' => $businessId, 'session_branch_id' => $branchId)));
}

if ($action === 'delete') {
    respond(false, 'Direct pawn deletion is disabled in Pawn Broking V2. Use cancellation/closure workflow so interest, bank pledge and audit history remain intact.', array(), 409);
}

respond(false, 'Invalid action: ' . ($action === '' ? '(empty)' : $action), array(), 400);