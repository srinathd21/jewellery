<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_OFF);

function respond($success, $message, $extra = array(), $status = 200)
{
    http_response_code((int) $status);
    echo json_encode(array_merge(array('success' => (bool) $success, 'message' => (string) $message), $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity))
        return false;
    throw new ErrorException($message, 0, $severity, $file, $line); });
set_exception_handler(function ($e) {
    error_log('Metal rates API: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    respond(false, 'Server error while processing metal rates.', array(), 500); });

foreach (array(dirname(__DIR__) . '/config/config.php', dirname(__DIR__) . '/config.php', dirname(__DIR__) . '/includes/config.php', dirname(__DIR__) . '/super-admin/includes/config.php') as $f) {
    if (is_file($f)) {
        require_once $f;
        break;
    }
}
if (!isset($conn) || !($conn instanceof mysqli))
    respond(false, 'Database configuration is not available.', array(), 500);
$conn->set_charset('utf8mb4');
if (empty($_SESSION['user_id']))
    respond(false, 'Your session has expired. Please log in again.', array(), 401);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST')
    respond(false, 'Invalid request method.', array(), 405);
if (!hash_equals((string) ($_SESSION['metal_rates_csrf'] ?? ''), (string) ($_POST['csrf_token'] ?? '')))
    respond(false, 'Invalid or expired request token. Refresh the page.', array(), 419);

function bindDynamicParams($stmt, $types, &$params)
{
    if ($types === '' || empty($params))
        return true;
    $bind = array($types);
    foreach ($params as $k => $v)
        $bind[] =& $params[$k];
    return call_user_func_array(array($stmt, 'bind_param'), $bind);
}
function metalRatePermission($conn, $action)
{
    if (($_SESSION['user_type'] ?? '') === 'Platform Admin')
        return true;
    $map = array('open' => 'can_open', 'view' => 'can_view', 'value' => 'can_view_value', 'create' => 'can_create', 'update' => 'can_update', 'delete' => 'can_delete');
    $field = $map[$action] ?? '';
    if ($field === '')
        return false;
    $codes = array('perm.inventory.metal_rates', 'perm.rates.metal', 'perm.metal_rates', 'perm.inventory');
    $permissions = $_SESSION['permissions'] ?? array();
    foreach ($codes as $code)
        if (isset($permissions[$code]) && array_key_exists($field, $permissions[$code]))
            return (int) $permissions[$code][$field] === 1;
    $bid = (int) ($_SESSION['business_id'] ?? 0);
    $rid = (int) ($_SESSION['role_id'] ?? 0);
    if ($bid <= 0 || $rid <= 0)
        return false;
    $marks = implode(',', array_fill(0, count($codes), '?'));
    $stmt = $conn->prepare("SELECT rp.`{$field}` FROM role_permissions rp INNER JOIN permissions p ON p.id=rp.permission_id WHERE rp.business_id=? AND rp.role_id=? AND p.is_active=1 AND p.permission_code IN ($marks) ORDER BY FIELD(p.permission_code,'perm.inventory.metal_rates','perm.rates.metal','perm.metal_rates','perm.inventory') LIMIT 1");
    if (!$stmt)
        return false;
    $types = 'ii' . str_repeat('s', count($codes));
    $params = array_merge(array($bid, $rid), $codes);
    bindDynamicParams($stmt, $types, $params);
    if (!$stmt->execute()) {
        $stmt->close();
        return false;
    }
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row[$field] ?? 0) === 1;
}
function writeAudit($conn, $bid, $branch, $uid, $action, $id, $description, $old, $new)
{
    $stmt = $conn->prepare("INSERT INTO audit_logs (business_id,branch_id,user_id,module_code,action_type,reference_table,reference_id,description,old_values_json,new_values_json,ip_address,user_agent) VALUES (?,NULLIF(?,0),?,'inventory.metal_rates',?,'metal_rates',?,?,?,?,?,?,?)");
    if (!$stmt)
        return;
    $o = $old === null ? null : json_encode($old, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $n = $new === null ? null : json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $stmt->bind_param('iiisisssss', $bid, $branch, $uid, $action, $id, $description, $o, $n, $ip, $ua);
    $stmt->execute();
    $stmt->close();
}
function validDateTimeLocal($v)
{
    return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $v) === 1 || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $v) === 1;
}

function archiveMetalRateSnapshot($conn, $row)
{
    if (!$row)
        return;
    $stmt = $conn->prepare('INSERT INTO metal_rate_history (business_id,branch_id,metal_id,purity,rate_per_gram,effective_from,is_current,updated_by) VALUES (?,NULLIF(?,0),?,?,?,?,0,?)');
    if (!$stmt)
        throw new RuntimeException('Unable to prepare metal rate history insert: ' . $conn->error);
    $businessId = (int) $row['business_id'];
    $branchId = (int) ($row['branch_id'] ?? 0);
    $metalId = (int) $row['metal_id'];
    $purity = (float) $row['purity'];
    $rate = (float) $row['rate_per_gram'];
    $effective = (string) $row['effective_from'];
    $updatedBy = (int) ($row['updated_by'] ?? 0);
    $stmt->bind_param('iiiddsi', $businessId, $branchId, $metalId, $purity, $rate, $effective, $updatedBy);
    if (!$stmt->execute())
        throw new RuntimeException('Unable to store previous metal rate in history: ' . $stmt->error);
    $stmt->close();
}

function archiveCurrentMetalRates($conn, $bid, $branch, $metalId, $purity, $excludeId)
{
    $stmt = $conn->prepare('SELECT business_id,branch_id,metal_id,purity,rate_per_gram,effective_from,updated_by FROM metal_rates WHERE business_id=? AND branch_id=? AND metal_id=? AND purity=? AND is_current=1 AND id<>? FOR UPDATE');
    if (!$stmt)
        throw new RuntimeException('Unable to prepare current metal rate history query: ' . $conn->error);
    $stmt->bind_param('iiidi', $bid, $branch, $metalId, $purity, $excludeId);
    if (!$stmt->execute())
        throw new RuntimeException('Unable to read previous current metal rates: ' . $stmt->error);
    $result = $stmt->get_result();
    $rows = array();
    while ($result && $row = $result->fetch_assoc())
        $rows[] = $row;
    $stmt->close();
    foreach ($rows as $row)
        archiveMetalRateSnapshot($conn, $row);
}

$bid = (int) ($_SESSION['business_id'] ?? 0);
$branch = (int) ($_SESSION['branch_id'] ?? 0);
$uid = (int) ($_SESSION['user_id'] ?? 0);
$action = trim((string) ($_POST['action'] ?? ''));
if ($bid <= 0)
    respond(false, 'A valid business must be selected.', array(), 403);
if ($branch <= 0)
    respond(false, 'A valid branch must be selected.', array(), 403);

$stmt = $conn->prepare('SELECT id FROM branches WHERE id=? AND business_id=? AND is_active=1 LIMIT 1');
if (!$stmt)
    respond(false, 'Unable to validate branch.', array(), 500);
$stmt->bind_param('ii', $branch, $bid);
$stmt->execute();
$ok = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$ok)
    respond(false, 'The selected branch is invalid or inactive.', array(), 403);

if ($action === 'list') {
    if (!metalRatePermission($conn, 'view') && !metalRatePermission($conn, 'open'))
        respond(false, 'You do not have permission to view metal rates.', array(), 403);
    $search = trim((string) ($_POST['search'] ?? ''));
    $metalId = max(0, (int) ($_POST['metal_id'] ?? 0));
    $date = trim((string) ($_POST['effective_date'] ?? ''));
    $pp = (int) ($_POST['per_page'] ?? 10);
    if (!in_array($pp, array(10, 25, 50, 100), true))
        $pp = 10;
    $page = max(1, (int) ($_POST['page'] ?? 1));
    $where = ' WHERE mr.business_id=? AND mr.branch_id=?';
    $types = 'ii';
    $params = array($bid, $branch);
    if ($search !== '') {
        $where .= " AND (m.metal_name LIKE ? OR CAST(mr.purity AS CHAR) LIKE ? OR DATE_FORMAT(mr.effective_from,'%d-%m-%Y') LIKE ?)";
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like);
        $types .= 'sss';
    }
    if ($metalId > 0) {
        $where .= ' AND mr.metal_id=?';
        $params[] = $metalId;
        $types .= 'i';
    }
    if ($date !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date))
            respond(false, 'Invalid effective date filter.', array(), 422);
        $where .= ' AND DATE(mr.effective_from)=?';
        $params[] = $date;
        $types .= 's';
    }

    $sql = 'SELECT COUNT(*) total FROM metal_rates mr INNER JOIN metals m ON m.id=mr.metal_id AND m.business_id=mr.business_id' . $where;
    $stmt = $conn->prepare($sql);
    if (!$stmt)
        respond(false, 'Unable to prepare rate count query: ' . $conn->error, array(), 500);
    bindDynamicParams($stmt, $types, $params);
    if (!$stmt->execute())
        respond(false, 'Unable to count metal rates: ' . $stmt->error, array(), 500);
    $total = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    $pages = max(1, (int) ceil($total / $pp));
    if ($page > $pages)
        $page = $pages;
    $offset = ($page - 1) * $pp;

    $sql = "SELECT mr.id,mr.metal_id,m.metal_name AS metal_type,mr.purity,mr.rate_per_gram,mr.effective_from,mr.is_current,mr.updated_by,DATE_FORMAT(mr.effective_from,'%d %b %Y, %h:%i %p') effective_date_display,DATE_FORMAT(mr.effective_from,'%Y-%m-%dT%H:%i') effective_from_input FROM metal_rates mr INNER JOIN metals m ON m.id=mr.metal_id AND m.business_id=mr.business_id {$where} ORDER BY mr.effective_from DESC,mr.id DESC LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt)
        respond(false, 'Unable to prepare rate list query: ' . $conn->error, array(), 500);
    $lp = $params;
    $lp[] = $pp;
    $lp[] = $offset;
    bindDynamicParams($stmt, $types . 'ii', $lp);
    if (!$stmt->execute())
        respond(false, 'Unable to load metal rates: ' . $stmt->error, array(), 500);
    $rates = array();
    $r = $stmt->get_result();
    while ($r && $row = $r->fetch_assoc())
        $rates[] = $row;
    $stmt->close();

    $stmt = $conn->prepare('SELECT COUNT(*) total,COUNT(DISTINCT metal_id) metals,COUNT(DISTINCT purity) purities,COALESCE(MAX(CASE WHEN is_current=1 THEN rate_per_gram END),0) highest FROM metal_rates WHERE business_id=? AND branch_id=?');
    if (!$stmt)
        respond(false, 'Unable to prepare rate statistics query: ' . $conn->error, array(), 500);
    $stmt->bind_param('ii', $bid, $branch);
    $stmt->execute();
    $s = $stmt->get_result()->fetch_assoc() ?: array();
    $stmt->close();

    respond(true, 'Rates loaded.', array('rates' => $rates, 'stats' => array('total' => (int) ($s['total'] ?? 0), 'metals' => (int) ($s['metals'] ?? 0), 'purities' => (int) ($s['purities'] ?? 0), 'highest' => (float) ($s['highest'] ?? 0)), 'meta' => array('page' => $page, 'per_page' => $pp, 'total' => $total, 'total_pages' => $pages, 'from' => $total ? ($offset + 1) : 0, 'to' => min($offset + $pp, $total))));
}

if ($action === 'get') {
    if (!metalRatePermission($conn, 'view') && !metalRatePermission($conn, 'open'))
        respond(false, 'Permission denied.', array(), 403);
    $id = (int) ($_POST['rate_id'] ?? 0);
    $stmt = $conn->prepare("SELECT mr.id,mr.metal_id,m.metal_name metal_type,mr.purity,mr.rate_per_gram,mr.is_current,DATE_FORMAT(mr.effective_from,'%Y-%m-%dT%H:%i') effective_from_input FROM metal_rates mr INNER JOIN metals m ON m.id=mr.metal_id AND m.business_id=mr.business_id WHERE mr.id=? AND mr.business_id=? AND mr.branch_id=? LIMIT 1");
    if (!$stmt)
        respond(false, 'Unable to prepare rate query: ' . $conn->error, array(), 500);
    $stmt->bind_param('iii', $id, $bid, $branch);
    $stmt->execute();
    $rate = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$rate)
        respond(false, 'Rate not found.', array(), 404);
    respond(true, 'Rate loaded.', array('rate' => $rate));
}

if ($action === 'save') {
    $id = (int) ($_POST['rate_id'] ?? 0);
    $isNew = $id <= 0;
    if ($isNew && !metalRatePermission($conn, 'create'))
        respond(false, 'You do not have permission to create metal rates.', array(), 403);
    if (!$isNew && !metalRatePermission($conn, 'update'))
        respond(false, 'You do not have permission to update metal rates.', array(), 403);

    $metalId = (int) ($_POST['metal_id'] ?? 0);
    $purity = (float) ($_POST['purity'] ?? 0);
    $rate = (float) ($_POST['rate_per_gram'] ?? 0);
    $input = trim((string) ($_POST['effective_from'] ?? ''));
    if ($metalId <= 0)
        respond(false, 'Select a valid metal.', array(), 422);
    if ($purity <= 0 || $purity > 100)
        respond(false, 'Purity must be greater than 0 and not more than 100.', array(), 422);
    if ($rate <= 0)
        respond(false, 'Rate per gram must be greater than zero.', array(), 422);
    if (!validDateTimeLocal($input))
        respond(false, 'Enter a valid effective date and time.', array(), 422);
    $effective = str_replace('T', ' ', $input);
    if (strlen($effective) === 16)
        $effective .= ':00';

    $stmt = $conn->prepare('SELECT id,metal_name FROM metals WHERE id=? AND business_id=? AND is_active=1 LIMIT 1');
    $stmt->bind_param('ii', $metalId, $bid);
    $stmt->execute();
    $metal = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$metal)
        respond(false, 'The selected metal is invalid or inactive.', array(), 422);

    $old = null;
    if (!$isNew) {
        $stmt = $conn->prepare('SELECT * FROM metal_rates WHERE id=? AND business_id=? AND branch_id=? LIMIT 1');
        $stmt->bind_param('iii', $id, $bid, $branch);
        $stmt->execute();
        $old = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$old)
            respond(false, 'Rate not found.', array(), 404);
    }

    $stmt = $conn->prepare('SELECT id FROM metal_rates WHERE business_id=? AND branch_id=? AND metal_id=? AND purity=? AND effective_from=? AND id<>? LIMIT 1');
    $stmt->bind_param('iiidsi', $bid, $branch, $metalId, $purity, $effective, $id);
    $stmt->execute();
    $dup = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($dup)
        respond(false, 'A rate already exists for this metal, purity and effective time.', array(), 409);

    $conn->begin_transaction();
    try {
        if (!$isNew) {
            // Preserve the exact old row before changing any field.
            archiveMetalRateSnapshot($conn, $old);
        }

        // Preserve any other current rate that is being replaced by this save.
        archiveCurrentMetalRates($conn, $bid, $branch, $metalId, $purity, $id);

        $stmt = $conn->prepare('UPDATE metal_rates SET is_current=0 WHERE business_id=? AND branch_id=? AND metal_id=? AND purity=? AND id<>?');
        if (!$stmt)
            throw new RuntimeException('Unable to prepare current-rate update: ' . $conn->error);
        $stmt->bind_param('iiidi', $bid, $branch, $metalId, $purity, $id);
        if (!$stmt->execute())
            throw new RuntimeException($stmt->error);
        $stmt->close();

        if ($isNew) {
            $stmt = $conn->prepare('INSERT INTO metal_rates (business_id,branch_id,metal_id,purity,rate_per_gram,effective_from,is_current,updated_by) VALUES (?,?,?,?,?,?,1,?)');
            if (!$stmt)
                throw new RuntimeException('Unable to prepare insert query: ' . $conn->error);
            $stmt->bind_param('iiiddsi', $bid, $branch, $metalId, $purity, $rate, $effective, $uid);
            if (!$stmt->execute())
                throw new RuntimeException('Unable to create metal rate: ' . $stmt->error);
            $id = (int) $stmt->insert_id;
            $stmt->close();
        } else {
            $stmt = $conn->prepare('UPDATE metal_rates SET metal_id=?,purity=?,rate_per_gram=?,effective_from=?,is_current=1,updated_by=? WHERE id=? AND business_id=? AND branch_id=?');
            if (!$stmt)
                throw new RuntimeException('Unable to prepare update query: ' . $conn->error);
            $stmt->bind_param('iddsiiii', $metalId, $purity, $rate, $effective, $uid, $id, $bid, $branch);
            if (!$stmt->execute())
                throw new RuntimeException('Unable to update metal rate: ' . $stmt->error);
            $stmt->close();
        }
        $new = array('business_id' => $bid, 'branch_id' => $branch, 'metal_id' => $metalId, 'metal_name' => $metal['metal_name'], 'purity' => $purity, 'rate_per_gram' => $rate, 'effective_from' => $effective, 'is_current' => 1, 'updated_by' => $uid);
        writeAudit($conn, $bid, $branch, $uid, $isNew ? 'Create' : 'Update', $id, ($isNew ? 'Created' : 'Updated') . ' metal rate ' . $metal['metal_name'] . ' ' . number_format($purity, 4, '.', ''), $old, $new);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        respond(false, $e->getMessage(), array(), 500);
    }
    respond(true, $isNew ? 'Metal rate created successfully.' : 'Metal rate updated successfully.', array('rate_id' => $id));
}

if ($action === 'delete') {
    if (!metalRatePermission($conn, 'delete'))
        respond(false, 'You do not have permission to delete metal rates.', array(), 403);
    $id = (int) ($_POST['rate_id'] ?? 0);
    $stmt = $conn->prepare('SELECT mr.*,m.metal_name FROM metal_rates mr INNER JOIN metals m ON m.id=mr.metal_id AND m.business_id=mr.business_id WHERE mr.id=? AND mr.business_id=? AND mr.branch_id=? LIMIT 1');
    if (!$stmt)
        respond(false, 'Unable to prepare rate query: ' . $conn->error, array(), 500);
    $stmt->bind_param('iii', $id, $bid, $branch);
    $stmt->execute();
    $old = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$old)
        respond(false, 'Rate not found.', array(), 404);
    $stmt = $conn->prepare('DELETE FROM metal_rates WHERE id=? AND business_id=? AND branch_id=?');
    $stmt->bind_param('iii', $id, $bid, $branch);
    if (!$stmt->execute())
        respond(false, 'Unable to delete metal rate: ' . $stmt->error, array(), 500);
    $stmt->close();
    writeAudit($conn, $bid, $branch, $uid, 'Delete', $id, 'Deleted metal rate ' . $old['metal_name'] . ' ' . $old['purity'], $old, null);
    respond(true, 'Metal rate deleted successfully.');
}
respond(false, 'Invalid action.', array(), 400);
