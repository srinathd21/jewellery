<?php
if (session_status() === PHP_SESSION_NONE)
    session_start();
header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_OFF);
ini_set('display_errors', '0');

function peOut($ok, $message, $extra = array(), $code = 200)
{
    http_response_code($code);
    echo json_encode(array_merge(array('success' => (bool) $ok, 'message' => (string) $message), $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true))
        peOut(false, 'Fatal API error: ' . $e['message'], array(), 500);
});
foreach (array(dirname(__DIR__) . '/config/config.php', dirname(__DIR__) . '/config.php', dirname(__DIR__) . '/includes/config.php', dirname(__DIR__) . '/super-admin/includes/config.php') as $f) {
    if (is_file($f)) {
        require_once $f;
        break;
    }
}
if (!isset($conn) || !($conn instanceof mysqli))
    peOut(false, 'Database configuration is not available.', array(), 500);
$conn->set_charset('utf8mb4');
if (empty($_SESSION['user_id']))
    peOut(false, 'Session expired.', array(), 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST')
    peOut(false, 'Invalid request method.', array(), 405);
if (!hash_equals((string) ($_SESSION['pawn_csrf'] ?? ''), (string) ($_POST['csrf_token'] ?? '')))
    peOut(false, 'Invalid request token. Refresh the page.', array(), 419);

$businessId = (int) ($_SESSION['business_id'] ?? 0);
$branchId = (int) ($_SESSION['branch_id'] ?? ($_SESSION['default_branch_id'] ?? 0));
$userId = (int) ($_SESSION['user_id'] ?? 0);
$action = trim((string) ($_POST['action'] ?? ''));
if ($businessId <= 0 || $branchId <= 0)
    peOut(false, 'Select a valid business and branch.', array(), 403);

function peTable($c, $t)
{
    $x = $c->real_escape_string($t);
    $r = $c->query("SHOW TABLES LIKE '{$x}'");
    return $r && $r->num_rows > 0;
}
function peCol($c, $t, $x)
{
    static $cache = array();
    $k = $t . '.' . $x;
    if (isset($cache[$k]))
        return $cache[$k];
    $tt = $c->real_escape_string($t);
    $xx = $c->real_escape_string($x);
    $r = $c->query("SHOW COLUMNS FROM `{$tt}` LIKE '{$xx}'");
    return $cache[$k] = (bool) ($r && $r->num_rows > 0);
}
function peBind($s, $types, &$vals)
{
    if (strlen($types) !== count($vals))
        throw new RuntimeException('Internal bind mismatch: ' . strlen($types) . ' types / ' . count($vals) . ' values.');
    $p = array($types);
    foreach ($vals as $k => $v)
        $p[] =& $vals[$k];
    call_user_func_array(array($s, 'bind_param'), $p);
}
function peInsert($c, $table, $fields)
{
    $cols = array();
    $vals = array();
    $types = '';
    $ph = array();
    foreach ($fields as $name => $spec) {
        if (!peCol($c, $table, $name))
            continue;
        $cols[] = '`' . $name . '`';
        if (isset($spec[2]) && $spec[2] === 'RAW') {
            $ph[] = $spec[1];
            continue;
        }
        $types .= $spec[0];
        $vals[] = $spec[1];
        $ph[] = '?';
    }
    if (!$cols)
        throw new RuntimeException('No compatible columns found for ' . $table . '.');
    $sql = 'INSERT INTO `' . $table . '` (' . implode(',', $cols) . ') VALUES (' . implode(',', $ph) . ')';
    $s = $c->prepare($sql);
    if (!$s)
        throw new RuntimeException('Unable to prepare ' . $table . ': ' . $c->error);
    if ($types !== '')
        peBind($s, $types, $vals);
    if (!$s->execute()) {
        $err = $s->error;
        $s->close();
        throw new RuntimeException('Unable to save ' . $table . ': ' . $err);
    }
    $id = (int) $s->insert_id;
    $s->close();
    return $id;
}
function peUpdate($c, $table, $fields, $whereSql, $whereTypes, $whereValues)
{
    $sets = array();
    $vals = array();
    $types = '';
    foreach ($fields as $name => $spec) {
        if (!peCol($c, $table, $name))
            continue;
        if (isset($spec[2]) && $spec[2] === 'RAW') {
            $sets[] = '`' . $name . '`=' . $spec[1];
            continue;
        }
        $sets[] = '`' . $name . '`=?';
        $types .= $spec[0];
        $vals[] = $spec[1];
    }
    if (!$sets)
        throw new RuntimeException('No compatible columns found for update of ' . $table . '.');
    $types .= $whereTypes;
    foreach ($whereValues as $v)
        $vals[] = $v;
    $sql = 'UPDATE `' . $table . '` SET ' . implode(',', $sets) . ' WHERE ' . $whereSql;
    $stmt = $c->prepare($sql);
    if (!$stmt)
        throw new RuntimeException('Unable to prepare update of ' . $table . ': ' . $c->error);
    peBind($stmt, $types, $vals);
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to update ' . $table . ': ' . $err);
    }
    $affected = $stmt->affected_rows;
    $stmt->close();
    return $affected;
}

function peEditLockReason($c, $businessId, $branchId, $pawnId, $pawn)
{
    if (!$pawn)
        return 'Pawn entry was not found.';
    if (in_array((string) ($pawn['status'] ?? ''), array('Closed', 'Auctioned', 'Cancelled'), true))
        return 'Closed, auctioned or cancelled pawns cannot have financial details edited.';
    if ((int) ($pawn['rate_escalation_count'] ?? 0) > 0 || (int) ($pawn['missed_interest_cycles'] ?? 0) > 0)
        return 'Financial details are locked because the customer interest rule has already escalated or recorded a missed cycle.';
    if (peTable($c, 'pawn_interest_collections')) {
        $q = $c->prepare('SELECT id FROM pawn_interest_collections WHERE business_id=? AND branch_id=? AND pawn_entry_id=? AND COALESCE(is_reversed,0)=0 LIMIT 1');
        if ($q) { $q->bind_param('iii', $businessId, $branchId, $pawnId); $q->execute(); $hit = $q->get_result()->fetch_assoc(); $q->close(); if ($hit) return 'Financial details are locked because interest has already been collected.'; }
    }
    if (peTable($c, 'pawn_payments')) {
        $q = $c->prepare('SELECT id FROM pawn_payments WHERE business_id=? AND branch_id=? AND pawn_entry_id=? AND COALESCE(is_reversed,0)=0 LIMIT 1');
        if ($q) { $q->bind_param('iii', $businessId, $branchId, $pawnId); $q->execute(); $hit = $q->get_result()->fetch_assoc(); $q->close(); if ($hit) return 'Financial details are locked because a pawn payment already exists.'; }
    }
    if (peTable($c, 'pawn_bank_loan_items')) {
        $q = $c->prepare('SELECT id FROM pawn_bank_loan_items WHERE business_id=? AND pawn_entry_id=? LIMIT 1');
        if ($q) { $q->bind_param('ii', $businessId, $pawnId); $q->execute(); $hit = $q->get_result()->fetch_assoc(); $q->close(); if ($hit) return 'Financial details are locked because pawn items have bank-pledge history.'; }
    }
    return '';
}

function peSetting($c, $businessId, $key, $default = '')
{
    if (!peTable($c, 'business_settings'))
        return $default;
    $s = $c->prepare('SELECT setting_value FROM business_settings WHERE business_id=? AND setting_key=? LIMIT 1');
    if (!$s)
        return $default;
    $s->bind_param('is', $businessId, $key);
    $s->execute();
    $r = $s->get_result()->fetch_assoc();
    $s->close();
    return $r ? ($r['setting_value'] ?? $default) : $default;
}
function peFy($date)
{
    $ts = strtotime($date ?: date('Y-m-d'));
    $y = (int) date('Y', $ts);
    $m = (int) date('n', $ts);
    $a = $m >= 4 ? $y : $y - 1;
    return array($a, $a + 1);
}
function peDocNo($c, $b, $br, $key, $date, $consume)
{
    if (!peTable($c, 'document_number_settings')) {
        if ($key === 'pawn') {
            $n = 1;
            $s = $c->prepare('SELECT pawn_no FROM pawn_entries WHERE business_id=? AND branch_id=? ORDER BY id DESC LIMIT 1');
            if ($s) {
                $s->bind_param('ii', $b, $br);
                $s->execute();
                $r = $s->get_result()->fetch_assoc();
                $s->close();
                if ($r)
                    $n = (int) preg_replace('/\D/', '', (string) $r['pawn_no']) + 1;
            }
            return 'PN' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
        }
        throw new RuntimeException('Document number settings table is missing.');
    }
    $s = $c->prepare('SELECT * FROM document_number_settings WHERE business_id=? AND document_key=? AND is_active=1 AND (branch_id=? OR branch_id IS NULL) ORDER BY (branch_id=?) DESC,id DESC LIMIT 1');
    if (!$s)
        throw new RuntimeException($c->error);
    $s->bind_param('isii', $b, $key, $br, $br);
    $s->execute();
    $set = $s->get_result()->fetch_assoc();
    $s->close();
    if (!$set) {
        if ($key === 'pawn')
            return peDocNoFallback($c, $b, $br);
        throw new RuntimeException('Configure ' . $key . ' numbering in Document Number Settings.');
    }
    $ts = strtotime($date ?: date('Y-m-d'));
    list($fy1, $fy2) = peFy($date);
    $reset = (string) $set['reset_frequency'];
    if ($reset === 'Monthly')
        $period = date('Ym', $ts);
    elseif ($reset === 'Daily')
        $period = date('Ymd', $ts);
    elseif ($reset === 'Calendar Year')
        $period = date('Y', $ts);
    elseif ($reset === 'Never')
        $period = 'ALL';
    else
        $period = $fy1 . '-' . $fy2;
    $current = 0;
    if (peTable($c, 'number_sequences')) {
        $q = $c->prepare('SELECT current_number FROM number_sequences WHERE business_id=? AND branch_id=? AND document_type=? AND period_key=? LIMIT 1' . ($consume ? ' FOR UPDATE' : ''));
        if ($q) {
            $q->bind_param('iiss', $b, $br, $key, $period);
            $q->execute();
            $r = $q->get_result()->fetch_assoc();
            $q->close();
            if ($r)
                $current = (int) $r['current_number'];
        }
    }
    $next = max((int) $set['sequence_start'], $current + 1);
    if ($consume) {
        $q = $c->prepare('INSERT INTO number_sequences (business_id,branch_id,document_type,period_key,current_number) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE current_number=VALUES(current_number)');
        if (!$q)
            throw new RuntimeException($c->error);
        $q->bind_param('iissi', $b, $br, $key, $period, $next);
        if (!$q->execute())
            throw new RuntimeException($q->error);
        $q->close();
    }
    $seq = str_pad((string) $next, max(1, (int) $set['sequence_digits']), '0', STR_PAD_LEFT);
    $center = strtr((string) $set['center_format'], array('{YYYY}' => date('Y', $ts), '{YY}' => date('y', $ts), '{MM}' => date('m', $ts), '{DD}' => date('d', $ts), '{FY_SHORT}' => substr((string) $fy1, 2) . '-' . substr((string) $fy2, 2), '{FY}' => $fy1 . '-' . $fy2));
    return strtr((string) $set['format_template'], array('{PREFIX}' => (string) $set['prefix'], '{DIVIDER}' => (string) $set['divider'], '{CENTER}' => $center, '{FY_SHORT}' => substr((string) $fy1, 2) . '-' . substr((string) $fy2, 2), '{SEQ}' => $seq, '{SUFFIX}' => (string) $set['suffix']));
}
function peDocNoFallback($c, $b, $br)
{
    $n = 1;
    $s = $c->prepare('SELECT pawn_no FROM pawn_entries WHERE business_id=? AND branch_id=? ORDER BY id DESC LIMIT 1');
    if ($s) {
        $s->bind_param('ii', $b, $br);
        $s->execute();
        $r = $s->get_result()->fetch_assoc();
        $s->close();
        if ($r)
            $n = (int) preg_replace('/\D/', '', (string) $r['pawn_no']) + 1;
    }
    return 'PN' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
}
function peDateAdd($date, $type, $value)
{
    $d = new DateTime($date);
    $value = max(1, (int) $value);
    if ($type === 'Days')
        $d->modify('+' . $value . ' days');
    else
        $d->modify('+' . $value . ' months');
    return $d->format('Y-m-d');
}
function peRound($amount, $method)
{
    if ($method === 'Nearest Rupee')
        return round($amount);
    if ($method === 'Ceil Rupee')
        return ceil($amount);
    if ($method === 'Floor Rupee')
        return floor($amount);
    return round($amount, 2);
}
function peUploadOne($field, $existing = '')
{
    if (empty($_FILES[$field]) || !is_array($_FILES[$field]))
        return $existing;
    $f = $_FILES[$field];
    $err = (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE)
        return $existing;
    if ($err !== UPLOAD_ERR_OK)
        throw new RuntimeException('Unable to upload ID proof.');
    return peSaveUploaded((string) $f['tmp_name'], (int) $f['size'], 'pawn-proofs', true);
}
function peUploadArrayItem($field, $index)
{
    if (empty($_FILES[$field]) || !isset($_FILES[$field]['error'][$index]))
        return '';
    $err = (int) $_FILES[$field]['error'][$index];
    if ($err === UPLOAD_ERR_NO_FILE)
        return '';
    if ($err !== UPLOAD_ERR_OK)
        throw new RuntimeException('Unable to upload pawn item image.');
    return peSaveUploaded((string) $_FILES[$field]['tmp_name'][$index], (int) $_FILES[$field]['size'][$index], 'pawn-items', false);
}
function peSaveUploaded($tmp, $size, $folder, $allowPdf)
{
    if ($tmp === '' || !is_uploaded_file($tmp))
        throw new RuntimeException('Invalid uploaded file.');
    if ($size <= 0 || $size > 8 * 1024 * 1024)
        throw new RuntimeException('Uploaded file must be below 8 MB.');
    $mime = '';
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) {
            $mime = (string) finfo_file($fi, $tmp);
            finfo_close($fi);
        }
    }
    $allowed = array('image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp');
    if ($allowPdf)
        $allowed['application/pdf'] = 'pdf';
    if (!isset($allowed[$mime]))
        throw new RuntimeException($allowPdf ? 'File must be JPG, PNG, WEBP or PDF.' : 'Item image must be JPG, PNG or WEBP.');
    $rel = 'uploads/' . $folder . '/' . date('Y/m');
    $dir = dirname(__DIR__) . '/' . $rel;
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir))
        throw new RuntimeException('Unable to create upload directory.');
    $name = $folder . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(5)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($tmp, $dir . '/' . $name))
        throw new RuntimeException('Unable to save uploaded file.');
    return $rel . '/' . $name;
}
function peAudit($c, $b, $br, $u, $id, $no, $payload, $actionType = 'Create')
{
    if (!peTable($c, 'audit_logs'))
        return;
    $fields = array('business_id' => array('i', $b), 'branch_id' => array('i', $br), 'user_id' => array('i', $u), 'module_code' => array('s', 'pawn.entry'), 'action_type' => array('s', $actionType), 'reference_table' => array('s', 'pawn_entries'), 'reference_id' => array('i', $id), 'description' => array('s', ($actionType === 'Update' ? 'Updated pawn entry ' : 'Created pawn entry ') . $no), 'old_values_json' => array('s', null), 'new_values_json' => array('s', json_encode($payload)), 'ip_address' => array('s', (string) ($_SERVER['REMOTE_ADDR'] ?? '')), 'user_agent' => array('s', substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500)), 'created_at' => array('s', 'NOW()', 'RAW'));
    try {
        peInsert($c, 'audit_logs', $fields);
    } catch (Throwable $e) {
    }
}
function peAction($c, $b, $br, $pawnId, $type, $table, $refId, $desc, $u)
{
    if (!peTable($c, 'pawn_action_history'))
        return;
    peInsert($c, 'pawn_action_history', array('business_id' => array('i', $b), 'branch_id' => array('i', $br), 'pawn_entry_id' => array('i', $pawnId), 'action_type' => array('s', $type), 'reference_table' => array('s', $table), 'reference_id' => array('i', $refId), 'description' => array('s', $desc), 'action_by' => array('i', $u), 'action_at' => array('s', 'NOW()', 'RAW')));
}
function peLatestMetalRate($c, $b, $br, $metalId, $purity)
{
    if (!peTable($c, 'metal_rates'))
        return 0.0;
    $sel = 'rate_per_gram';
    $where = 'business_id=?';
    $types = 'i';
    $vals = array($b);
    if (peCol($c, 'metal_rates', 'metal_id')) {
        $where .= ' AND metal_id=?';
        $types .= 'i';
        $vals[] = $metalId;
    }
    if (peCol($c, 'metal_rates', 'branch_id')) {
        $where .= ' AND (branch_id=? OR branch_id IS NULL)';
        $types .= 'i';
        $vals[] = $br;
    }
    if (peCol($c, 'metal_rates', 'effective_date'))
        $where .= ' AND effective_date<=CURDATE()';
    elseif (peCol($c, 'metal_rates', 'rate_date'))
        $where .= ' AND rate_date<=CURDATE()';
    if (peCol($c, 'metal_rates', 'purity') && trim((string) $purity) !== '') {
        $where .= ' AND (LOWER(REPLACE(purity,\' \',\'\'))=LOWER(REPLACE(?,\' \',\'\')) OR purity IS NULL OR purity=\'\')';
        $types .= 's';
        $vals[] = $purity;
    }
    $s = $c->prepare('SELECT ' . $sel . ' FROM metal_rates WHERE ' . $where . ' ORDER BY ' . (peCol($c, 'metal_rates', 'branch_id') ? 'branch_id DESC,' : '') . ' id DESC LIMIT 1');
    if (!$s)
        return 0.0;
    peBind($s, $types, $vals);
    $s->execute();
    $r = $s->get_result()->fetch_assoc();
    $s->close();
    return $r ? max(0, (float) $r['rate_per_gram']) : 0.0;
}

foreach (array('pawn_entries', 'pawn_items', 'pawn_categories', 'pawn_interest_schemes', 'pawn_interest_rate_steps', 'pawn_interest_accruals', 'pawn_interest_rate_history') as $t) {
    if (!peTable($conn, $t))
        peOut(false, 'Required Pawn Broking V2 table is missing: ' . $t . '. Run the V2 migration first.', array(), 500);
}

if ($action === 'options') {
    $customers = array();
    $customerCols = array('id', 'customer_code', 'customer_name', 'mobile', 'email', 'address_line1', 'address_line2', 'city', 'state', 'pincode');
    foreach (array('alternate_mobile', 'id_proof_type', 'id_proof_number', 'kyc_verified', 'risk_category') as $x)
        $customerCols[] = peCol($conn, 'customers', $x) ? $x : (($x === 'kyc_verified') ? '0 AS kyc_verified' : "'' AS {$x}");
    $proof = '';
    foreach (array('id_proof_image', 'id_proof_image_path', 'proof_image', 'proof_image_path') as $x)
        if (peCol($conn, 'customers', $x)) {
            $proof = $x;
            break;
        }
    $customerCols[] = $proof !== '' ? '`' . $proof . '` AS id_proof_image' : "'' AS id_proof_image";
    $s = $conn->prepare('SELECT ' . implode(',', $customerCols) . ' FROM customers WHERE business_id=? AND is_active=1 ORDER BY customer_name');
    if ($s) {
        $s->bind_param('i', $businessId);
        $s->execute();
        $r = $s->get_result();
        while ($x = $r->fetch_assoc())
            $customers[] = $x;
        $s->close();
    }
    $categories = array();
    $s = $conn->prepare('SELECT id,category_code,category_name,category_type,metal_type,purity_standard,max_loan_percent,valuation_method FROM pawn_categories WHERE business_id=? AND is_active=1 ORDER BY category_name');
    if ($s) {
        $s->bind_param('i', $businessId);
        $s->execute();
        $r = $s->get_result();
        while ($x = $r->fetch_assoc())
            $categories[] = $x;
        $s->close();
    }
    $metals = array();
    if (peTable($conn, 'metals')) {
        $s = $conn->prepare('SELECT id,metal_name FROM metals WHERE business_id=? AND is_active=1 ORDER BY metal_name');
        if ($s) {
            $s->bind_param('i', $businessId);
            $s->execute();
            $r = $s->get_result();
            while ($x = $r->fetch_assoc())
                $metals[] = $x;
            $s->close();
        }
    }
    $rates = array();
    if (peTable($conn, 'metal_rates')) {
        $sql = 'SELECT id,rate_per_gram,' . (peCol($conn, 'metal_rates', 'metal_id') ? 'metal_id' : 'NULL AS metal_id') . ',' . (peCol($conn, 'metal_rates', 'purity') ? 'purity' : "'' AS purity") . ' FROM metal_rates WHERE business_id=? ORDER BY id DESC';
        $s = $conn->prepare($sql);
        if ($s) {
            $s->bind_param('i', $businessId);
            $s->execute();
            $r = $s->get_result();
            $seen = array();
            while ($x = $r->fetch_assoc()) {
                $k = (string) $x['metal_id'] . '|' . strtolower(str_replace(' ', '', (string) $x['purity']));
                if (!isset($seen[$k])) {
                    $seen[$k] = 1;
                    $rates[] = $x;
                }
            }
            $s->close();
        }
    }
    $methods = array();
    if (peTable($conn, 'payment_methods')) {
        $s = $conn->prepare('SELECT id,method_name FROM payment_methods WHERE business_id=? AND is_active=1 ORDER BY method_name');
        if ($s) {
            $s->bind_param('i', $businessId);
            $s->execute();
            $r = $s->get_result();
            while ($x = $r->fetch_assoc())
                $methods[] = $x;
            $s->close();
        }
    }
    $schemes = array();
    $s = $conn->prepare('SELECT * FROM pawn_interest_schemes WHERE business_id=? AND is_active=1 ORDER BY scheme_name');
    if ($s) {
        $s->bind_param('i', $businessId);
        $s->execute();
        $r = $s->get_result();
        while ($scheme = $r->fetch_assoc()) {
            $scheme['steps'] = array();
            $q = $conn->prepare('SELECT * FROM pawn_interest_rate_steps WHERE business_id=? AND scheme_id=? AND is_active=1 ORDER BY level_no');
            $sid = (int) $scheme['id'];
            $q->bind_param('ii', $businessId, $sid);
            $q->execute();
            $rr = $q->get_result();
            while ($step = $rr->fetch_assoc())
                $scheme['steps'][] = $step;
            $q->close();
            if ($scheme['steps'])
                $schemes[] = $scheme;
        }
        $s->close();
    }
    $general = array('pawn_default_document_charge' => peSetting($conn, $businessId, 'pawn_default_document_charge', '0'), 'pawn_default_other_charge' => peSetting($conn, $businessId, 'pawn_default_other_charge', '0'), 'pawn_require_id_proof' => peSetting($conn, $businessId, 'pawn_require_id_proof', '0'), 'pawn_require_item_photo' => peSetting($conn, $businessId, 'pawn_require_item_photo', '0'));
    $reregister = null;
    $rid = max(0, (int) ($_POST['reregister_from'] ?? 0));
    if ($rid > 0) {
        $s = $conn->prepare("SELECT id,pawn_no,customer_id,pawn_category_id,id_proof_type,id_proof_number,status FROM pawn_entries WHERE id=? AND business_id=? AND branch_id=? LIMIT 1");
        $s->bind_param('iii', $rid, $businessId, $branchId);
        $s->execute();
        $reregister = $s->get_result()->fetch_assoc();
        $s->close();
        if (!$reregister)
            peOut(false, 'Original pawn for re-registration was not found.', array(), 404);
        if ((string) $reregister['status'] !== 'Closed')
            peOut(false, 'Only a closed pawn can be re-registered.', array(), 422);
        $reregister['items'] = array();
        $s = $conn->prepare('SELECT metal_id,item_description,quantity,gross_weight,stone_weight,net_weight,purity,estimated_value FROM pawn_items WHERE business_id=? AND pawn_entry_id=? ORDER BY id');
        $s->bind_param('ii', $businessId, $rid);
        $s->execute();
        $rr = $s->get_result();
        while ($x = $rr->fetch_assoc())
            $reregister['items'][] = $x;
        $s->close();
    }
    $edit = null;
    $editId = max(0, (int) ($_POST['edit_id'] ?? 0));
    if ($editId > 0) {
        $s = $conn->prepare('SELECT * FROM pawn_entries WHERE id=? AND business_id=? AND branch_id=? LIMIT 1');
        if (!$s)
            peOut(false, 'Unable to load pawn for edit: ' . $conn->error, array(), 500);
        $s->bind_param('iii', $editId, $businessId, $branchId);
        $s->execute();
        $edit = $s->get_result()->fetch_assoc();
        $s->close();
        if (!$edit)
            peOut(false, 'Pawn entry was not found.', array(), 404);
        $edit['items'] = array();
        $s = $conn->prepare('SELECT id,metal_id,item_description,quantity,gross_weight,stone_weight,net_weight,purity,rate_per_gram,estimated_value,image_path,remarks AS item_remarks FROM pawn_items WHERE business_id=? AND pawn_entry_id=? ORDER BY id');
        if ($s) {
            $s->bind_param('ii', $businessId, $editId);
            $s->execute();
            $rr = $s->get_result();
            while ($x = $rr->fetch_assoc())
                $edit['items'][] = $x;
            $s->close();
        }
        $reason = peEditLockReason($conn, $businessId, $branchId, $editId, $edit);
        $edit['financial_locked'] = $reason !== '' ? 1 : 0;
        $edit['lock_reason'] = $reason;

        $hasCustomer = false;
        foreach ($customers as $x) if ((int) $x['id'] === (int) $edit['customer_id']) { $hasCustomer = true; break; }
        if (!$hasCustomer) {
            $q = $conn->prepare('SELECT id,customer_code,customer_name,mobile,email,address_line1,address_line2,city,state,pincode FROM customers WHERE id=? AND business_id=? LIMIT 1');
            if ($q) { $cid = (int) $edit['customer_id']; $q->bind_param('ii', $cid, $businessId); $q->execute(); $x = $q->get_result()->fetch_assoc(); $q->close(); if ($x) { $x['alternate_mobile']=''; $x['id_proof_type']=''; $x['id_proof_number']=''; $x['kyc_verified']=0; $x['risk_category']=''; $x['id_proof_image']=''; $customers[]=$x; } }
        }
        $hasCategory = false;
        foreach ($categories as $x) if ((int) $x['id'] === (int) $edit['pawn_category_id']) { $hasCategory = true; break; }
        if (!$hasCategory) {
            $q = $conn->prepare('SELECT id,category_code,category_name,category_type,metal_type,purity_standard,max_loan_percent,valuation_method FROM pawn_categories WHERE id=? AND business_id=? LIMIT 1');
            if ($q) { $cid = (int) $edit['pawn_category_id']; $q->bind_param('ii', $cid, $businessId); $q->execute(); $x = $q->get_result()->fetch_assoc(); $q->close(); if ($x) $categories[]=$x; }
        }
        $hasScheme = false;
        foreach ($schemes as $x) if ((int) $x['id'] === (int) $edit['interest_scheme_id']) { $hasScheme = true; break; }
        if (!$hasScheme) {
            $q = $conn->prepare('SELECT * FROM pawn_interest_schemes WHERE id=? AND business_id=? LIMIT 1');
            if ($q) { $sid=(int)$edit['interest_scheme_id']; $q->bind_param('ii',$sid,$businessId); $q->execute(); $x=$q->get_result()->fetch_assoc(); $q->close(); if ($x) { $x['steps']=array(); $qq=$conn->prepare('SELECT * FROM pawn_interest_rate_steps WHERE business_id=? AND scheme_id=? ORDER BY level_no'); if($qq){$qq->bind_param('ii',$businessId,$sid);$qq->execute();$rr=$qq->get_result();while($st=$rr->fetch_assoc())$x['steps'][]=$st;$qq->close();} if($x['steps'])$schemes[]=$x; } }
        }
    }
    peOut(true, 'Options loaded.', array('next_pawn_no' => peDocNo($conn, $businessId, $branchId, 'pawn', date('Y-m-d'), false), 'customers' => $customers, 'categories' => $categories, 'metals' => $metals, 'metal_rates' => $rates, 'payment_methods' => $methods, 'schemes' => $schemes, 'general' => $general, 'reregister' => $reregister, 'edit' => $edit));
}

if ($action === 'update') {
    $pawnId = max(0, (int) ($_POST['edit_id'] ?? 0));
    if ($pawnId <= 0)
        peOut(false, 'Invalid pawn entry for edit.', array(), 422);

    $s = $conn->prepare('SELECT * FROM pawn_entries WHERE id=? AND business_id=? AND branch_id=? LIMIT 1');
    if (!$s)
        peOut(false, 'Unable to load pawn entry: ' . $conn->error, array(), 500);
    $s->bind_param('iii', $pawnId, $businessId, $branchId);
    $s->execute();
    $existingPawn = $s->get_result()->fetch_assoc();
    $s->close();
    if (!$existingPawn)
        peOut(false, 'Pawn entry was not found.', array(), 404);

    $idType = trim((string) ($_POST['id_proof_type'] ?? ''));
    $idNo = trim((string) ($_POST['id_proof_number'] ?? ''));
    $remarks = trim((string) ($_POST['remarks'] ?? ''));
    $proof = peUploadOne('id_proof_image', trim((string) ($_POST['existing_id_proof_image'] ?? ($existingPawn['id_proof_image'] ?? ''))));
    $lockReason = peEditLockReason($conn, $businessId, $branchId, $pawnId, $existingPawn);

    if ($lockReason !== '') {
        try {
            $conn->begin_transaction();
            peUpdate($conn, 'pawn_entries', array(
                'id_proof_type' => array('s', $idType),
                'id_proof_number' => array('s', $idNo),
                'id_proof_image' => array('s', $proof),
                'id_proof_image_path' => array('s', $proof),
                'remarks' => array('s', $remarks),
                'updated_at' => array('s', 'NOW()', 'RAW')
            ), 'id=? AND business_id=? AND branch_id=?', 'iii', array($pawnId, $businessId, $branchId));
            peAudit($conn, $businessId, $branchId, $userId, $pawnId, (string) $existingPawn['pawn_no'], array('edit_mode' => 'locked', 'id_proof_type' => $idType, 'id_proof_number' => $idNo, 'remarks' => $remarks), 'Update');
            $conn->commit();
            peOut(true, 'Pawn notes / KYC updated. Financial fields remain locked because transactions already exist.', array('pawn_id' => $pawnId, 'pawn_no' => $existingPawn['pawn_no'], 'financial_locked' => 1));
        } catch (Throwable $e) {
            $conn->rollback();
            peOut(false, $e->getMessage(), array(), 500);
        }
    }

    $date = trim((string) ($_POST['pawn_date'] ?? ''));
    $customerId = max(0, (int) ($_POST['customer_id'] ?? 0));
    $categoryId = max(0, (int) ($_POST['pawn_category_id'] ?? 0));
    $schemeId = max(0, (int) ($_POST['interest_scheme_id'] ?? 0));
    $principal = max(0, (float) ($_POST['principal_amount'] ?? 0));
    $loanType = trim((string) ($_POST['loan_type'] ?? 'General'));
    $primaryMetal = max(0, (int) ($_POST['primary_metal_id'] ?? 0));
    $docCharge = max(0, (float) ($_POST['document_charge'] ?? 0));
    $otherCharge = max(0, (float) ($_POST['other_charge'] ?? 0));
    $paymentMethod = max(0, (int) ($_POST['disbursement_payment_method_id'] ?? 0));
    $paymentRef = trim((string) ($_POST['payment_reference'] ?? ''));

    if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date))
        peOut(false, 'Valid pawn date is required.', array(), 422);
    if ($customerId <= 0 || $categoryId <= 0 || $schemeId <= 0)
        peOut(false, 'Customer, category and interest scheme are required.', array(), 422);
    if ($principal <= 0)
        peOut(false, 'Principal amount must be greater than zero.', array(), 422);
    if ($paymentMethod <= 0)
        peOut(false, 'Disbursement payment method is required.', array(), 422);

    $s = $conn->prepare('SELECT id FROM customers WHERE id=? AND business_id=? LIMIT 1');
    $s->bind_param('ii', $customerId, $businessId);
    $s->execute();
    $customer = $s->get_result()->fetch_assoc();
    $s->close();
    if (!$customer)
        peOut(false, 'Invalid customer.', array(), 422);

    $s = $conn->prepare('SELECT * FROM pawn_categories WHERE id=? AND business_id=? LIMIT 1');
    $s->bind_param('ii', $categoryId, $businessId);
    $s->execute();
    $category = $s->get_result()->fetch_assoc();
    $s->close();
    if (!$category)
        peOut(false, 'Invalid pawn category.', array(), 422);

    $s = $conn->prepare('SELECT * FROM pawn_interest_schemes WHERE id=? AND business_id=? LIMIT 1');
    $s->bind_param('ii', $schemeId, $businessId);
    $s->execute();
    $scheme = $s->get_result()->fetch_assoc();
    $s->close();
    if (!$scheme)
        peOut(false, 'Invalid interest scheme.', array(), 422);

    $s = $conn->prepare('SELECT * FROM pawn_interest_rate_steps WHERE business_id=? AND scheme_id=? AND level_no=1 LIMIT 1');
    $s->bind_param('ii', $businessId, $schemeId);
    $s->execute();
    $step = $s->get_result()->fetch_assoc();
    $s->close();
    if (!$step)
        peOut(false, 'The selected scheme does not have Level 1.', array(), 422);

    $metalIds = $_POST['metal_id'] ?? array();
    $descs = $_POST['item_description'] ?? array();
    $qtys = $_POST['quantity'] ?? array();
    $grosses = $_POST['gross_weight'] ?? array();
    $stones = $_POST['stone_weight'] ?? array();
    $purities = $_POST['purity'] ?? array();
    $postedRates = $_POST['rate_per_gram'] ?? array();
    $itemRemarks = $_POST['item_remarks'] ?? array();
    $existingImages = $_POST['existing_item_image'] ?? array();
    if (!is_array($metalIds) || count($metalIds) < 1)
        peOut(false, 'At least one pawn item is required.', array(), 422);

    $items = array();
    $tg = 0.0;
    $ts = 0.0;
    $tn = 0.0;
    $te = 0.0;
    foreach ($metalIds as $i => $midRaw) {
        $mid = max(0, (int) $midRaw);
        $desc = trim((string) ($descs[$i] ?? ''));
        $qty = max(0, (float) ($qtys[$i] ?? 0));
        $gross = max(0, (float) ($grosses[$i] ?? 0));
        $stone = max(0, (float) ($stones[$i] ?? 0));
        $purity = trim((string) ($purities[$i] ?? ''));
        if ($mid <= 0 || $desc === '' || $qty <= 0 || $gross <= 0)
            peOut(false, 'Each pawn item requires metal, description, quantity and gross weight.', array(), 422);
        if ($stone > $gross)
            peOut(false, 'Stone weight cannot exceed gross weight for item ' . ($i + 1) . '.', array(), 422);
        $net = $gross - $stone;
        $serverRate = peLatestMetalRate($conn, $businessId, $branchId, $mid, $purity);
        $rateValue = $serverRate > 0 ? $serverRate : max(0, (float) ($postedRates[$i] ?? 0));
        if ($rateValue <= 0)
            peOut(false, 'No valid metal rate is available for item ' . ($i + 1) . '.', array(), 422);
        $estimated = $net * $rateValue;
        $items[] = array(
            'metal_id' => $mid,
            'description' => $desc,
            'quantity' => $qty,
            'gross' => $gross,
            'stone' => $stone,
            'net' => $net,
            'purity' => $purity,
            'rate' => $rateValue,
            'estimated' => $estimated,
            'remarks' => trim((string) ($itemRemarks[$i] ?? '')),
            'existing_image' => trim((string) ($existingImages[$i] ?? '')),
            'index' => $i
        );
        $tg += $gross;
        $ts += $stone;
        $tn += $net;
        $te += $estimated;
    }
    if ($tn <= 0 || $te <= 0)
        peOut(false, 'Pawn net weight and estimated value must be greater than zero.', array(), 422);

    $maxLoanPercent = max(0, min(100, (float) ($category['max_loan_percent'] ?? 100)));
    $maxEligible = $te * ($maxLoanPercent / 100);
    if ($principal > $maxEligible + 0.01)
        peOut(false, 'Principal exceeds category maximum eligible loan of Rs. ' . number_format($maxEligible, 2), array(), 422);
    $disbursement = $principal - $docCharge - $otherCharge;
    if ($disbursement <= 0)
        peOut(false, 'Amount given to customer must be greater than zero after charges.', array(), 422);
    if (peSetting($conn, $businessId, 'pawn_require_id_proof', '0') === '1' && $idNo === '')
        peOut(false, 'ID proof is required by Pawn Settings.', array(), 422);

    $requireItemPhoto = peSetting($conn, $businessId, 'pawn_require_item_photo', '0') === '1';
    $rate = (float) $step['rate_percent'];
    $cycleType = (string) $step['interest_cycle_type'];
    $cycleValue = max(1, (int) $step['interest_cycle_value']);
    $grace = max(0, (int) $step['grace_days']);
    $firstDue = peDateAdd($date, $cycleType, $cycleValue);
    $graceUntil = $grace > 0 ? peDateAdd($firstDue, 'Days', $grace) : $firstDue;
    $tenureType = (string) $scheme['tenure_type'];
    $tenureMonths = $tenureType === 'Fixed Months' ? max(1, (int) $scheme['tenure_months']) : 0;
    $contractDue = $tenureType === 'Fixed Months' ? peDateAdd($date, 'Months', $tenureMonths) : null;
    $rounding = (string) $scheme['interest_rounding_method'];
    $cycleInterest = peRound($principal * ($rate / 100), $rounding);
    $days = (int) (new DateTime($date))->diff(new DateTime($firstDue))->format('%a');
    $legacyCycle = $cycleType === 'Calendar Month' ? 'Monthly' : 'Custom';
    $legacyCycleMonths = $cycleType === 'Months' ? $cycleValue : ($cycleType === 'Days' ? max(1, (int) round($cycleValue / 30)) : 1);

    $conn->begin_transaction();
    try {
        peUpdate($conn, 'pawn_entries', array(
            'pawn_date' => array('s', $date),
            'customer_id' => array('i', $customerId),
            'pawn_category_id' => array('i', $categoryId),
            'loan_type' => array('s', $loanType),
            'principal_amount' => array('d', $principal),
            'interest_scheme_id' => array('i', $schemeId),
            'initial_rate_step_id' => array('i', (int) $step['id']),
            'current_rate_step_id' => array('i', (int) $step['id']),
            'initial_interest_percent' => array('d', $rate),
            'current_interest_percent' => array('d', $rate),
            'interest_due_cycle_type' => array('s', $cycleType),
            'interest_due_cycle_value' => array('i', $cycleValue),
            'interest_grace_days' => array('i', $grace),
            'interest_percent' => array('d', $rate),
            'interest_method' => array('s', (string) $scheme['interest_method']),
            'interest_collection_cycle' => array('s', $legacyCycle),
            'interest_cycle_months' => array('i', $legacyCycleMonths),
            'interest_rounding_method' => array('s', $rounding),
            'tenure_months' => array('i', $tenureMonths),
            'due_date' => array('s', $contractDue),
            'next_interest_due_date' => array('s', $firstDue),
            'grace_days' => array('i', $grace),
            'total_gross_weight' => array('d', $tg),
            'total_stone_weight' => array('d', $ts),
            'total_net_weight' => array('d', $tn),
            'total_estimated_value' => array('d', $te),
            'balance_principal' => array('d', $principal),
            'id_proof_type' => array('s', $idType),
            'id_proof_number' => array('s', $idNo),
            'id_proof_image' => array('s', $proof),
            'id_proof_image_path' => array('s', $proof),
            'document_charge' => array('d', $docCharge),
            'other_charge' => array('d', $otherCharge),
            'disbursement_amount' => array('d', $disbursement),
            'disbursement_payment_method_id' => array('i', $paymentMethod),
            'payment_method_id' => array('i', $paymentMethod),
            'payment_reference' => array('s', $paymentRef),
            'primary_metal_id' => array('i', $primaryMetal > 0 ? $primaryMetal : null),
            'remarks' => array('s', $remarks),
            'updated_at' => array('s', 'NOW()', 'RAW')
        ), 'id=? AND business_id=? AND branch_id=?', 'iii', array($pawnId, $businessId, $branchId));

        $d = $conn->prepare('DELETE FROM pawn_items WHERE business_id=? AND pawn_entry_id=?');
        if (!$d) throw new RuntimeException('Unable to prepare pawn item refresh: ' . $conn->error);
        $d->bind_param('ii', $businessId, $pawnId);
        if (!$d->execute()) { $err=$d->error; $d->close(); throw new RuntimeException('Unable to refresh pawn items: ' . $err); }
        $d->close();
        foreach ($items as $it) {
            $image = peUploadArrayItem('item_image', $it['index']);
            if ($image === '') $image = $it['existing_image'];
            if ($requireItemPhoto && $image === '')
                throw new RuntimeException('Item photo is required for every pawn item by Pawn Settings.');
            peInsert($conn, 'pawn_items', array(
                'business_id' => array('i', $businessId),
                'pawn_entry_id' => array('i', $pawnId),
                'metal_id' => array('i', $it['metal_id']),
                'item_description' => array('s', $it['description']),
                'quantity' => array('d', $it['quantity']),
                'gross_weight' => array('d', $it['gross']),
                'stone_weight' => array('d', $it['stone']),
                'net_weight' => array('d', $it['net']),
                'purity' => array('d', is_numeric($it['purity']) ? (float) $it['purity'] : 0),
                'rate_per_gram' => array('d', $it['rate']),
                'estimated_value' => array('d', $it['estimated']),
                'image_path' => array('s', $image),
                'remarks' => array('s', $it['remarks']),
                'created_at' => array('s', 'NOW()', 'RAW')
            ));
        }

        $d = $conn->prepare('DELETE FROM pawn_interest_accruals WHERE business_id=? AND branch_id=? AND pawn_entry_id=?');
        if (!$d) throw new RuntimeException('Unable to refresh interest schedule: ' . $conn->error);
        $d->bind_param('iii', $businessId, $branchId, $pawnId);
        if (!$d->execute()) { $err=$d->error; $d->close(); throw new RuntimeException('Unable to refresh interest schedule: ' . $err); }
        $d->close();
        $d = $conn->prepare('DELETE FROM pawn_interest_rate_history WHERE business_id=? AND branch_id=? AND pawn_entry_id=?');
        if (!$d) throw new RuntimeException('Unable to refresh rate history: ' . $conn->error);
        $d->bind_param('iii', $businessId, $branchId, $pawnId);
        if (!$d->execute()) { $err=$d->error; $d->close(); throw new RuntimeException('Unable to refresh rate history: ' . $err); }
        $d->close();

        $historyId = peInsert($conn, 'pawn_interest_rate_history', array(
            'business_id' => array('i', $businessId),
            'branch_id' => array('i', $branchId),
            'pawn_entry_id' => array('i', $pawnId),
            'scheme_id' => array('i', $schemeId),
            'rate_step_id' => array('i', (int) $step['id']),
            'level_no' => array('i', (int) $step['level_no']),
            'rate_percent' => array('d', $rate),
            'interest_cycle_type' => array('s', $cycleType),
            'interest_cycle_value' => array('i', $cycleValue),
            'grace_days' => array('i', $grace),
            'effective_from' => array('s', $date),
            'effective_to' => array('s', null),
            'change_reason' => array('s', 'Pawn Entry Edit'),
            'previous_rate_percent' => array('d', $existingPawn['current_interest_percent'] ?? null),
            'created_by' => array('i', $userId),
            'created_at' => array('s', 'NOW()', 'RAW')
        ));
        $accrualId = peInsert($conn, 'pawn_interest_accruals', array(
            'business_id' => array('i', $businessId),
            'branch_id' => array('i', $branchId),
            'pawn_entry_id' => array('i', $pawnId),
            'schedule_no' => array('i', 1),
            'rate_step_id' => array('i', (int) $step['id']),
            'rate_level_no' => array('i', (int) $step['level_no']),
            'from_date' => array('s', $date),
            'to_date' => array('s', $firstDue),
            'due_date' => array('s', $firstDue),
            'grace_until' => array('s', $graceUntil),
            'principal_balance' => array('d', $principal),
            'calculation_days' => array('i', $days),
            'calculation_months' => array('d', $days / 30.0),
            'interest_percent' => array('d', $rate),
            'interest_period' => array('s', 'Monthly'),
            'interest_method' => array('s', (string) $scheme['interest_method']),
            'interest_amount' => array('d', $cycleInterest),
            'penalty_amount' => array('d', 0),
            'other_charges' => array('d', 0),
            'total_due' => array('d', $cycleInterest),
            'paid_amount' => array('d', 0),
            'balance_amount' => array('d', $cycleInterest),
            'status' => array('s', 'Pending'),
            'missed_due' => array('i', 0),
            'rate_escalation_triggered' => array('i', 0),
            'generated_by' => array('i', $userId),
            'generated_at' => array('s', 'NOW()', 'RAW'),
            'remarks' => array('s', 'First scheduled customer interest due refreshed after pawn edit')
        ));

        peAudit($conn, $businessId, $branchId, $userId, $pawnId, (string) $existingPawn['pawn_no'], array(
            'customer_id' => $customerId,
            'pawn_category_id' => $categoryId,
            'category_max_loan_percent' => $maxLoanPercent,
            'principal_amount' => $principal,
            'interest_scheme_id' => $schemeId,
            'interest_percent' => $rate,
            'first_interest_due_date' => $firstDue,
            'total_net_weight' => $tn,
            'total_estimated_value' => $te
        ), 'Update');
        $conn->commit();
        peOut(true, 'Pawn entry updated successfully.', array('pawn_id' => $pawnId, 'pawn_no' => $existingPawn['pawn_no'], 'principal_amount' => $principal, 'amount_given' => $disbursement, 'interest_rate' => $rate, 'first_interest_due_date' => $firstDue, 'first_interest_amount' => $cycleInterest, 'accrual_id' => $accrualId, 'rate_history_id' => $historyId));
    } catch (Throwable $e) {
        $conn->rollback();
        peOut(false, $e->getMessage(), array(), 500);
    }
}

if ($action === 'create') {
    $date = trim((string) ($_POST['pawn_date'] ?? ''));
    $customerId = max(0, (int) ($_POST['customer_id'] ?? 0));
    $categoryId = max(0, (int) ($_POST['pawn_category_id'] ?? 0));
    $schemeId = max(0, (int) ($_POST['interest_scheme_id'] ?? 0));
    $principal = max(0, (float) ($_POST['principal_amount'] ?? 0));
    $loanType = trim((string) ($_POST['loan_type'] ?? 'General'));
    $primaryMetal = max(0, (int) ($_POST['primary_metal_id'] ?? 0));
    $docCharge = max(0, (float) ($_POST['document_charge'] ?? 0));
    $otherCharge = max(0, (float) ($_POST['other_charge'] ?? 0));
    $paymentMethod = max(0, (int) ($_POST['disbursement_payment_method_id'] ?? 0));
    $paymentRef = trim((string) ($_POST['payment_reference'] ?? ''));
    $idType = trim((string) ($_POST['id_proof_type'] ?? ''));
    $idNo = trim((string) ($_POST['id_proof_number'] ?? ''));
    $remarks = trim((string) ($_POST['remarks'] ?? ''));
    $reregisterFrom = max(0, (int) ($_POST['reregister_from'] ?? 0));
    if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date))
        peOut(false, 'Valid pawn date is required.', array(), 422);
    if ($customerId <= 0 || $categoryId <= 0 || $schemeId <= 0)
        peOut(false, 'Customer, category and interest scheme are required.', array(), 422);
    if ($principal <= 0)
        peOut(false, 'Principal amount must be greater than zero.', array(), 422);
    if ($paymentMethod <= 0)
        peOut(false, 'Disbursement payment method is required.', array(), 422);
    $s = $conn->prepare('SELECT id FROM customers WHERE id=? AND business_id=? AND is_active=1 LIMIT 1');
    $s->bind_param('ii', $customerId, $businessId);
    $s->execute();
    $customer = $s->get_result()->fetch_assoc();
    $s->close();
    if (!$customer)
        peOut(false, 'Invalid customer.', array(), 422);
    $s = $conn->prepare('SELECT * FROM pawn_categories WHERE id=? AND business_id=? AND is_active=1 LIMIT 1');
    $s->bind_param('ii', $categoryId, $businessId);
    $s->execute();
    $category = $s->get_result()->fetch_assoc();
    $s->close();
    if (!$category)
        peOut(false, 'Invalid pawn category.', array(), 422);
    $s = $conn->prepare('SELECT * FROM pawn_interest_schemes WHERE id=? AND business_id=? AND is_active=1 LIMIT 1');
    $s->bind_param('ii', $schemeId, $businessId);
    $s->execute();
    $scheme = $s->get_result()->fetch_assoc();
    $s->close();
    if (!$scheme)
        peOut(false, 'Invalid interest scheme.', array(), 422);
    $s = $conn->prepare('SELECT * FROM pawn_interest_rate_steps WHERE business_id=? AND scheme_id=? AND level_no=1 AND is_active=1 LIMIT 1');
    $s->bind_param('ii', $businessId, $schemeId);
    $s->execute();
    $step = $s->get_result()->fetch_assoc();
    $s->close();
    if (!$step)
        peOut(false, 'The selected scheme does not have active Level 1.', array(), 422);
    if ($reregisterFrom > 0) {
        $s = $conn->prepare("SELECT id,re_registered_to_pawn_id FROM pawn_entries WHERE id=? AND business_id=? AND branch_id=? AND status='Closed' LIMIT 1");
        $s->bind_param('iii', $reregisterFrom, $businessId, $branchId);
        $s->execute();
        $oldPawn = $s->get_result()->fetch_assoc();
        $s->close();
        if (!$oldPawn)
            peOut(false, 'Re-registration requires a closed original pawn.', array(), 422);
        if (!empty($oldPawn['re_registered_to_pawn_id']))
            peOut(false, 'This closed pawn is already linked to a re-registration.', array(), 422);
    }
    $metalIds = $_POST['metal_id'] ?? array();
    $descs = $_POST['item_description'] ?? array();
    $qtys = $_POST['quantity'] ?? array();
    $grosses = $_POST['gross_weight'] ?? array();
    $stones = $_POST['stone_weight'] ?? array();
    $purities = $_POST['purity'] ?? array();
    $postedRates = $_POST['rate_per_gram'] ?? array();
    $itemRemarks = $_POST['item_remarks'] ?? array();
    if (!is_array($metalIds) || count($metalIds) < 1)
        peOut(false, 'At least one pawn item is required.', array(), 422);
    $items = array();
    $tg = 0.0;
    $ts = 0.0;
    $tn = 0.0;
    $te = 0.0;
    foreach ($metalIds as $i => $midRaw) {
        $mid = max(0, (int) $midRaw);
        $desc = trim((string) ($descs[$i] ?? ''));
        $qty = max(0, (float) ($qtys[$i] ?? 0));
        $gross = max(0, (float) ($grosses[$i] ?? 0));
        $stone = max(0, (float) ($stones[$i] ?? 0));
        $purity = trim((string) ($purities[$i] ?? ''));
        if ($mid <= 0 || $desc === '' || $qty <= 0 || $gross <= 0)
            peOut(false, 'Each pawn item requires metal, description, quantity and gross weight.', array(), 422);
        if ($stone > $gross)
            peOut(false, 'Stone weight cannot exceed gross weight for item ' . ($i + 1) . '.', array(), 422);
        $net = $gross - $stone;
        $serverRate = peLatestMetalRate($conn, $businessId, $branchId, $mid, $purity);
        $rate = $serverRate > 0 ? $serverRate : max(0, (float) ($postedRates[$i] ?? 0));
        if ($rate <= 0)
            peOut(false, 'No valid metal rate is available for item ' . ($i + 1) . '.', array(), 422);
        $est = $net * $rate;
        $items[] = array('metal_id' => $mid, 'description' => $desc, 'quantity' => $qty, 'gross' => $gross, 'stone' => $stone, 'net' => $net, 'purity' => $purity, 'rate' => $rate, 'estimated' => $est, 'remarks' => trim((string) ($itemRemarks[$i] ?? '')), 'index' => $i);
        $tg += $gross;
        $ts += $stone;
        $tn += $net;
        $te += $est;
    }
    if ($tn <= 0 || $te <= 0)
        peOut(false, 'Pawn net weight and estimated value must be greater than zero.', array(), 422);
    $maxLoanPercent = max(0, min(100, (float) ($category['max_loan_percent'] ?? 100)));
    $maxEligible = $te * ($maxLoanPercent / 100);
    if ($principal > $maxEligible + 0.01)
        peOut(false, 'Principal exceeds category maximum eligible loan of Rs. ' . number_format($maxEligible, 2), array(), 422);
    $disbursement = $principal - $docCharge - $otherCharge;
    if ($disbursement <= 0)
        peOut(false, 'Amount given to customer must be greater than zero after charges.', array(), 422);
    if (peSetting($conn, $businessId, 'pawn_require_id_proof', '0') === '1' && $idNo === '')
        peOut(false, 'ID proof is required by Pawn Settings.', array(), 422);
    $proof = peUploadOne('id_proof_image', trim((string) ($_POST['existing_id_proof_image'] ?? '')));
    $requireItemPhoto = peSetting($conn, $businessId, 'pawn_require_item_photo', '0') === '1';
    $rate = (float) $step['rate_percent'];
    $cycleType = (string) $step['interest_cycle_type'];
    $cycleValue = max(1, (int) $step['interest_cycle_value']);
    $grace = max(0, (int) $step['grace_days']);
    $firstDue = peDateAdd($date, $cycleType, $cycleValue);
    $graceUntil = $grace > 0 ? peDateAdd($firstDue, 'Days', $grace) : $firstDue;
    $tenureType = (string) $scheme['tenure_type'];
    $tenureMonths = $tenureType === 'Fixed Months' ? max(1, (int) $scheme['tenure_months']) : 0;
    $contractDue = $tenureType === 'Fixed Months' ? peDateAdd($date, 'Months', $tenureMonths) : null;
    $rounding = (string) $scheme['interest_rounding_method'];
    $cycleInterest = peRound($principal * ($rate / 100), $rounding);
    $days = (int) (new DateTime($date))->diff(new DateTime($firstDue))->format('%a');
    $legacyCycle = $cycleType === 'Calendar Month' ? 'Monthly' : 'Custom';
    $legacyCycleMonths = $cycleType === 'Months' ? $cycleValue : ($cycleType === 'Days' ? max(1, (int) round($cycleValue / 30)) : 1);
    $conn->begin_transaction();
    try {
        $pawnNo = peDocNo($conn, $businessId, $branchId, 'pawn', $date, true);
        $entryFields = array(
            'business_id' => array('i', $businessId),
            'branch_id' => array('i', $branchId),
            'pawn_no' => array('s', $pawnNo),
            'pawn_date' => array('s', $date),
            'customer_id' => array('i', $customerId),
            'pawn_category_id' => array('i', $categoryId),
            'loan_type' => array('s', $loanType),
            'principal_amount' => array('d', $principal),
            'interest_scheme_id' => array('i', $schemeId),
            'initial_rate_step_id' => array('i', (int) $step['id']),
            'current_rate_step_id' => array('i', (int) $step['id']),
            'initial_interest_percent' => array('d', $rate),
            'current_interest_percent' => array('d', $rate),
            'interest_due_cycle_type' => array('s', $cycleType),
            'interest_due_cycle_value' => array('i', $cycleValue),
            'interest_grace_days' => array('i', $grace),
            'missed_interest_cycles' => array('i', 0),
            'rate_escalation_count' => array('i', 0),
            'interest_rule_locked' => array('i', 1),
            're_registered_from_pawn_id' => array('i', $reregisterFrom > 0 ? $reregisterFrom : null),
            'bank_pledge_status' => array('s', 'Not Pledged'),
            'interest_percent' => array('d', $rate),
            'interest_period' => array('s', 'Monthly'),
            'interest_method' => array('s', (string) $scheme['interest_method']),
            'interest_collection_cycle' => array('s', $legacyCycle),
            'interest_cycle_months' => array('i', $legacyCycleMonths),
            'minimum_interest_days' => array('i', 0),
            'interest_rounding_method' => array('s', $rounding),
            'tenure_months' => array('i', $tenureMonths),
            'due_date' => array('s', $contractDue),
            'last_interest_paid_upto' => array('s', null),
            'next_interest_due_date' => array('s', $firstDue),
            'grace_days' => array('i', $grace),
            'overdue_charge_type' => array('s', 'None'),
            'overdue_charge_value' => array('d', 0),
            'total_gross_weight' => array('d', $tg),
            'total_stone_weight' => array('d', $ts),
            'total_net_weight' => array('d', $tn),
            'total_estimated_value' => array('d', $te),
            'total_interest_collected' => array('d', 0),
            'total_penalty_collected' => array('d', 0),
            'total_other_charges_collected' => array('d', 0),
            'total_principal_paid' => array('d', 0),
            'balance_principal' => array('d', $principal),
            'status' => array('s', 'Active'),
            'id_proof_type' => array('s', $idType),
            'id_proof_number' => array('s', $idNo),
            'id_proof_image' => array('s', $proof),
            'id_proof_image_path' => array('s', $proof),
            'document_charge' => array('d', $docCharge),
            'other_charge' => array('d', $otherCharge),
            'disbursement_amount' => array('d', $disbursement),
            'disbursement_payment_method_id' => array('i', $paymentMethod),
            'payment_method_id' => array('i', $paymentMethod),
            'payment_reference' => array('s', $paymentRef),
            'primary_metal_id' => array('i', $primaryMetal > 0 ? $primaryMetal : null),
            'remarks' => array('s', $remarks),
            'created_by' => array('i', $userId),
            'created_at' => array('s', 'NOW()', 'RAW'),
            'updated_at' => array('s', 'NOW()', 'RAW')
        );
        $pawnId = peInsert($conn, 'pawn_entries', $entryFields);
        foreach ($items as $it) {
            $image = peUploadArrayItem('item_image', $it['index']);
            if ($requireItemPhoto && $image === '')
                throw new RuntimeException('Item photo is required for every pawn item by Pawn Settings.');
            $itemId = peInsert($conn, 'pawn_items', array('business_id' => array('i', $businessId), 'pawn_entry_id' => array('i', $pawnId), 'metal_id' => array('i', $it['metal_id']), 'item_description' => array('s', $it['description']), 'quantity' => array('d', $it['quantity']), 'gross_weight' => array('d', $it['gross']), 'stone_weight' => array('d', $it['stone']), 'net_weight' => array('d', $it['net']), 'purity' => array('d', is_numeric($it['purity']) ? (float) $it['purity'] : 0), 'rate_per_gram' => array('d', $it['rate']), 'estimated_value' => array('d', $it['estimated']), 'image_path' => array('s', $image), 'remarks' => array('s', $it['remarks']), 'created_at' => array('s', 'NOW()', 'RAW')));
        }
        $historyId = peInsert($conn, 'pawn_interest_rate_history', array('business_id' => array('i', $businessId), 'branch_id' => array('i', $branchId), 'pawn_entry_id' => array('i', $pawnId), 'scheme_id' => array('i', $schemeId), 'rate_step_id' => array('i', (int) $step['id']), 'level_no' => array('i', (int) $step['level_no']), 'rate_percent' => array('d', $rate), 'interest_cycle_type' => array('s', $cycleType), 'interest_cycle_value' => array('i', $cycleValue), 'grace_days' => array('i', $grace), 'effective_from' => array('s', $date), 'effective_to' => array('s', null), 'change_reason' => array('s', $reregisterFrom > 0 ? 'Re-Registration' : 'Pawn Registration'), 'previous_rate_percent' => array('d', null), 'created_by' => array('i', $userId), 'created_at' => array('s', 'NOW()', 'RAW')));
        $accrualId = peInsert($conn, 'pawn_interest_accruals', array('business_id' => array('i', $businessId), 'branch_id' => array('i', $branchId), 'pawn_entry_id' => array('i', $pawnId), 'schedule_no' => array('i', 1), 'rate_step_id' => array('i', (int) $step['id']), 'rate_level_no' => array('i', (int) $step['level_no']), 'from_date' => array('s', $date), 'to_date' => array('s', $firstDue), 'due_date' => array('s', $firstDue), 'grace_until' => array('s', $graceUntil), 'principal_balance' => array('d', $principal), 'calculation_days' => array('i', $days), 'calculation_months' => array('d', $days / 30.0), 'interest_percent' => array('d', $rate), 'interest_period' => array('s', 'Monthly'), 'interest_method' => array('s', (string) $scheme['interest_method']), 'interest_amount' => array('d', $cycleInterest), 'penalty_amount' => array('d', 0), 'other_charges' => array('d', 0), 'total_due' => array('d', $cycleInterest), 'paid_amount' => array('d', 0), 'balance_amount' => array('d', $cycleInterest), 'status' => array('s', 'Pending'), 'missed_due' => array('i', 0), 'rate_escalation_triggered' => array('i', 0), 'generated_by' => array('i', $userId), 'generated_at' => array('s', 'NOW()', 'RAW'), 'remarks' => array('s', 'First scheduled customer interest due')));
        peAction($conn, $businessId, $branchId, $pawnId, 'Created', 'pawn_entries', $pawnId, 'Created pawn ' . $pawnNo . ' with interest scheme ' . $scheme['scheme_code'] . ' at ' . $rate . '%', $userId);
        if ($reregisterFrom > 0) {
            $s = $conn->prepare('UPDATE pawn_entries SET re_registered_to_pawn_id=? WHERE id=? AND business_id=? AND branch_id=?');
            $s->bind_param('iiii', $pawnId, $reregisterFrom, $businessId, $branchId);
            if (!$s->execute())
                throw new RuntimeException($s->error);
            $s->close();
            peAction($conn, $businessId, $branchId, $pawnId, 'Re-Registered', 'pawn_entries', $reregisterFrom, 'Re-registered from closed pawn ID ' . $reregisterFrom, $userId);
        }
        peAudit($conn, $businessId, $branchId, $userId, $pawnId, $pawnNo, array('pawn_no' => $pawnNo, 'customer_id' => $customerId, 'principal_amount' => $principal, 'interest_scheme_id' => $schemeId, 'rate_step_id' => (int) $step['id'], 'interest_percent' => $rate, 'first_interest_due_date' => $firstDue, 'total_net_weight' => $tn, 'total_estimated_value' => $te, 'reregister_from' => $reregisterFrom ?: null));
        $conn->commit();
        peOut(true, 'Pawn entry created successfully. Pawn No: ' . $pawnNo, array('pawn_id' => $pawnId, 'pawn_no' => $pawnNo, 'principal_amount' => $principal, 'amount_given' => $disbursement, 'interest_rate' => $rate, 'first_interest_due_date' => $firstDue, 'first_interest_amount' => $cycleInterest, 'accrual_id' => $accrualId, 'rate_history_id' => $historyId));
    } catch (Throwable $e) {
        $conn->rollback();
        peOut(false, $e->getMessage(), array(), 500);
    }
}
peOut(false, 'Invalid action.', array(), 400);
