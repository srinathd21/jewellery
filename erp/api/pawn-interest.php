<?php
if (session_status() === PHP_SESSION_NONE)
    session_start();
date_default_timezone_set((string) ($_SESSION['timezone'] ?? 'Asia/Kolkata'));
header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_OFF);
ini_set('display_errors', '0');

function out($ok, $message = '', $extra = array(), $code = 200)
{
    http_response_code($code);
    echo json_encode(array_merge(array('success' => (bool) $ok, 'message' => (string) $message), $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true))
        out(false, 'Fatal API error: ' . $e['message'], array(), 500); });
foreach (array(dirname(__DIR__) . '/config/config.php', dirname(__DIR__) . '/config.php', dirname(__DIR__) . '/includes/config.php', dirname(__DIR__) . '/super-admin/includes/config.php') as $f) {
    if (is_file($f)) {
        require_once $f;
        break;
    }
}
if (!isset($conn) || !($conn instanceof mysqli))
    out(false, 'Database configuration is not available.', array(), 500);
$conn->set_charset('utf8mb4');
if (empty($_SESSION['user_id']))
    out(false, 'Session expired.', array(), 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST')
    out(false, 'Invalid request method.', array(), 405);
if (!hash_equals((string) ($_SESSION['pawn_csrf'] ?? ''), (string) ($_POST['csrf_token'] ?? '')))
    out(false, 'Invalid request token. Refresh the page.', array(), 419);
$businessId = (int) ($_SESSION['business_id'] ?? 0);
$branchId = (int) ($_SESSION['branch_id'] ?? ($_SESSION['default_branch_id'] ?? 0));
$userId = (int) ($_SESSION['user_id'] ?? 0);
$action = trim((string) ($_POST['action'] ?? ''));
if ($businessId <= 0 || $branchId <= 0)
    out(false, 'Select a valid business and branch.', array(), 403);

function tableExists($c, $t)
{
    static $x = array();
    $k = strtolower($t);
    if (isset($x[$k]))
        return $x[$k];
    $s = $c->real_escape_string($t);
    $r = $c->query("SHOW TABLES LIKE '{$s}'");
    return $x[$k] = (bool) ($r && $r->num_rows > 0);
}
function colExists($c, $t, $n)
{
    static $x = array();
    $k = strtolower($t . '.' . $n);
    if (isset($x[$k]))
        return $x[$k];
    $a = $c->real_escape_string($t);
    $b = $c->real_escape_string($n);
    $r = $c->query("SHOW COLUMNS FROM `{$a}` LIKE '{$b}'");
    return $x[$k] = (bool) ($r && $r->num_rows > 0);
}
function validDate($d)
{
    $x = DateTime::createFromFormat('Y-m-d', $d);
    return $x && $x->format('Y-m-d') === $d;
}
function bindD($s, $types, &$vals)
{
    if (strlen($types) !== count($vals))
        throw new RuntimeException('Prepared statement bind mismatch.');
    $a = array($types);
    foreach ($vals as $k => $v)
        $a[] =& $vals[$k];
    call_user_func_array(array($s, 'bind_param'), $a);
}
function fyParts($d)
{
    $t = strtotime($d);
    $y = (int) date('Y', $t);
    $m = (int) date('n', $t);
    $a = $m >= 4 ? $y : $y - 1;
    return array($a, $a + 1);
}
function docNo($c, $b, $br, $key, $date)
{
    if (!tableExists($c, 'document_number_settings') || !tableExists($c, 'number_sequences'))
        throw new RuntimeException('Document numbering tables are not available.');
    $s = $c->prepare("SELECT * FROM document_number_settings WHERE business_id=? AND document_key=? AND is_active=1 AND (branch_id=? OR branch_id IS NULL) ORDER BY (branch_id=?) DESC,id DESC LIMIT 1");
    if (!$s)
        throw new RuntimeException('Unable to read document number settings.');
    $s->bind_param('isii', $b, $key, $br, $br);
    $s->execute();
    $set = $s->get_result()->fetch_assoc();
    $s->close();
    if (!$set)
        throw new RuntimeException('Configure ' . $key . ' in Document Number Settings.');
    $t = strtotime($date);
    list($f1, $f2) = fyParts($date);
    $reset = (string) $set['reset_frequency'];
    if ($reset === 'Monthly')
        $period = date('Ym', $t);
    elseif ($reset === 'Daily')
        $period = date('Ymd', $t);
    elseif ($reset === 'Calendar Year')
        $period = date('Y', $t);
    elseif ($reset === 'Never')
        $period = 'ALL';
    else
        $period = $f1 . '-' . $f2;
    $cur = 0;
    $q = $c->prepare('SELECT current_number FROM number_sequences WHERE business_id=? AND branch_id=? AND document_type=? AND period_key=? LIMIT 1 FOR UPDATE');
    if ($q) {
        $q->bind_param('iiss', $b, $br, $key, $period);
        $q->execute();
        $r = $q->get_result()->fetch_assoc();
        $q->close();
        if ($r)
            $cur = (int) $r['current_number'];
    }
    $next = max((int) $set['sequence_start'], $cur + 1);
    $q = $c->prepare('INSERT INTO number_sequences (business_id,branch_id,document_type,period_key,current_number) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE current_number=VALUES(current_number)');
    if (!$q)
        throw new RuntimeException('Unable to update document sequence.');
    $q->bind_param('iissi', $b, $br, $key, $period, $next);
    $q->execute();
    $q->close();
    $seq = str_pad((string) $next, max(1, (int) $set['sequence_digits']), '0', STR_PAD_LEFT);
    $center = strtr((string) $set['center_format'], array('{YYYY}' => date('Y', $t), '{YY}' => date('y', $t), '{MM}' => date('m', $t), '{DD}' => date('d', $t), '{FY_SHORT}' => substr((string) $f1, 2) . '-' . substr((string) $f2, 2), '{FY}' => $f1 . '-' . $f2));
    return strtr((string) $set['format_template'], array('{PREFIX}' => (string) $set['prefix'], '{DIVIDER}' => (string) $set['divider'], '{CENTER}' => $center, '{FY_SHORT}' => substr((string) $f1, 2) . '-' . substr((string) $f2, 2), '{SEQ}' => $seq, '{SUFFIX}' => (string) $set['suffix']));
}
function actionLog($c, $b, $br, $pid, $type, $table, $rid, $desc, $u)
{
    if (!tableExists($c, 'pawn_action_history'))
        return;
    $s = $c->prepare('INSERT INTO pawn_action_history (business_id,branch_id,pawn_entry_id,action_type,reference_table,reference_id,description,action_by) VALUES (?,?,?,?,?,?,?,?)');
    if (!$s)
        return;
    $s->bind_param('iiissisi', $b, $br, $pid, $type, $table, $rid, $desc, $u);
    $s->execute();
    $s->close();
}
function auditLog($c, $b, $br, $u, $type, $table, $rid, $desc, $newVals)
{
    if (!tableExists($c, 'audit_logs'))
        return;
    $s = $c->prepare("INSERT INTO audit_logs (business_id,branch_id,user_id,module_code,action_type,reference_table,reference_id,description,new_values_json,ip_address,user_agent) VALUES (?,?,?,'pawn.interest',?,?,?,?,?,?,?)");
    if (!$s)
        return;
    $j = json_encode($newVals, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $s->bind_param('iiississss', $b, $br, $u, $type, $table, $rid, $desc, $j, $ip, $ua);
    $s->execute();
    $s->close();
}
function getPawn($c, $b, $br, $id, $lock = false)
{
    $sql = "SELECT p.*,COALESCE(c.customer_name,'') customer_name,COALESCE(c.customer_code,'') customer_code,COALESCE(c.mobile,'') mobile,COALESCE(pc.category_name,'') category_name,COALESCE(s.scheme_code,'') interest_scheme_code,COALESCE(s.scheme_name,'') interest_scheme_name,COALESCE(rs.level_no,1) current_rate_level,rs.next_level_no,nrs.rate_percent next_interest_percent FROM pawn_entries p LEFT JOIN customers c ON c.id=p.customer_id AND c.business_id=p.business_id LEFT JOIN pawn_categories pc ON pc.id=p.pawn_category_id AND pc.business_id=p.business_id LEFT JOIN pawn_interest_schemes s ON s.id=p.interest_scheme_id AND s.business_id=p.business_id LEFT JOIN pawn_interest_rate_steps rs ON rs.id=p.current_rate_step_id AND rs.business_id=p.business_id LEFT JOIN pawn_interest_rate_steps nrs ON nrs.scheme_id=rs.scheme_id AND nrs.level_no=rs.next_level_no AND nrs.business_id=p.business_id WHERE p.id=? AND p.business_id=? AND p.branch_id=? LIMIT 1" . ($lock ? ' FOR UPDATE' : '');
    $s = $c->prepare($sql);
    if (!$s)
        throw new RuntimeException('Unable to load pawn: ' . $c->error);
    $s->bind_param('iii', $id, $b, $br);
    $s->execute();
    $r = $s->get_result()->fetch_assoc();
    $s->close();
    if (!$r)
        throw new RuntimeException('Pawn entry not found.');
    return $r;
}
function addCycleDate($from, $type, $value)
{
    $d = new DateTime($from);
    $v = max(1, (int) $value);
    if ($type === 'Days')
        $d->modify('+' . $v . ' days');
    elseif ($type === 'Months')
        $d->modify('+' . $v . ' months');
    else
        $d->modify('+' . $v . ' months');
    return $d->format('Y-m-d');
}
function roundInterest($v, $m)
{
    if ($m === 'Nearest Rupee')
        return round($v);
    if ($m === 'Ceil Rupee')
        return ceil($v);
    if ($m === 'Floor Rupee')
        return floor($v);
    return round($v, 2);
}
function calcCycleInterest($pawn, $from, $to, $rate)
{
    $base = strtolower((string) ($pawn['interest_method'] ?? 'Simple')) === 'flat' ? (float) $pawn['principal_amount'] : (float) $pawn['balance_principal'];
    $days = max(0, (int) (new DateTime($from))->diff(new DateTime($to))->format('%a'));
    $period = (string) ($pawn['interest_period'] ?? 'Monthly');
    if ($period === 'Daily')
        $amt = $base * ($rate / 100) * $days;
    elseif ($period === 'Yearly')
        $amt = $base * ($rate / 100) * ($days / 365);
    else
        $amt = $base * ($rate / 100) * ($days / 30);
    return array($days, $days / 30, roundInterest($amt, (string) ($pawn['interest_rounding_method'] ?? 'Nearest Rupee')));
}
function graceDate($due, $days)
{
    $d = new DateTime($due);
    if ((int) $days > 0)
        $d->modify('+' . (int) $days . ' days');
    return $d->format('Y-m-d');
}
function generateNextAccrual($c, $pawn, $userId, $fromDate)
{
    if (!tableExists($c, 'pawn_interest_accruals'))
        return 0;
    $pid = (int) $pawn['id'];
    $b = (int) $pawn['business_id'];
    $br = (int) $pawn['branch_id'];
    $q = $c->prepare('SELECT id FROM pawn_interest_accruals WHERE pawn_entry_id=? AND business_id=? AND from_date=? AND status<>\'Cancelled\' LIMIT 1');
    $q->bind_param('iis', $pid, $b, $fromDate);
    $q->execute();
    $exists = $q->get_result()->fetch_assoc();
    $q->close();
    if ($exists)
        return (int) $exists['id'];
    $type = (string) ($pawn['interest_due_cycle_type'] ?? 'Calendar Month');
    $value = max(1, (int) ($pawn['interest_due_cycle_value'] ?? 1));
    $to = addCycleDate($fromDate, $type, $value);
    $grace = graceDate($to, (int) ($pawn['interest_grace_days'] ?? 0));
    $rate = (float) ($pawn['current_interest_percent'] ?? $pawn['interest_percent'] ?? 0);
    list($days, $months, $interest) = calcCycleInterest($pawn, $fromDate, $to, $rate);
    $q = $c->prepare('SELECT COALESCE(MAX(schedule_no),0)+1 n FROM pawn_interest_accruals WHERE pawn_entry_id=? AND business_id=?');
    $q->bind_param('ii', $pid, $b);
    $q->execute();
    $schedule = (int) $q->get_result()->fetch_assoc()['n'];
    $q->close();
    $total = $interest;
    $balance = $total;
    $step = (int) ($pawn['current_rate_step_id'] ?? 0);
    $level = (int) ($pawn['current_rate_level'] ?? 1);
    $principal = (float) $pawn['balance_principal'];
    $period = (string) ($pawn['interest_period'] ?? 'Monthly');
    $method = (string) ($pawn['interest_method'] ?? 'Simple');
    $s = $c->prepare("INSERT INTO pawn_interest_accruals (business_id,branch_id,pawn_entry_id,schedule_no,rate_step_id,rate_level_no,from_date,to_date,due_date,grace_until,principal_balance,calculation_days,calculation_months,interest_percent,interest_period,interest_method,interest_amount,penalty_amount,other_charges,total_due,paid_amount,balance_amount,status,generated_by,generated_at,remarks) VALUES (?,?,?,?,NULLIF(?,0),?,?,?,?,?,?,?,?,?,?,?,?,0,0,?,0,?,'Pending',?,NOW(),'Scheduled customer interest due')");
    if (!$s)
        throw new RuntimeException('Unable to prepare next interest schedule: ' . $c->error);
    $s->bind_param('iiiiiissssdiddssdddi', $b, $br, $pid, $schedule, $step, $level, $fromDate, $to, $to, $grace, $principal, $days, $months, $rate, $period, $method, $interest, $total, $balance, $userId);
    if (!$s->execute())
        throw new RuntimeException('Unable to create next interest schedule: ' . $s->error);
    $id = (int) $s->insert_id;
    $s->close();
    $u = $c->prepare('UPDATE pawn_entries SET next_interest_due_date=? WHERE id=? AND business_id=?');
    $u->bind_param('sii', $to, $pid, $b);
    $u->execute();
    $u->close();
    return $id;
}
function processEscalation($c, $pawn, $asOf, $userId)
{
    if (!tableExists($c, 'pawn_interest_accruals') || !tableExists($c, 'pawn_interest_rate_steps'))
        return $pawn;
    $pid = (int) $pawn['id'];
    $b = (int) $pawn['business_id'];
    $br = (int) $pawn['branch_id'];
    $s = $c->prepare("SELECT * FROM pawn_interest_accruals WHERE pawn_entry_id=? AND business_id=? AND status IN ('Pending','Partially Paid') AND COALESCE(grace_until,due_date)<? ORDER BY schedule_no ASC LIMIT 1 FOR UPDATE");
    $s->bind_param('iis', $pid, $b, $asOf);
    $s->execute();
    $a = $s->get_result()->fetch_assoc();
    $s->close();
    if (!$a)
        return $pawn;
    $aid = (int) $a['id'];
    if ((int) $a['missed_due'] === 0) {
        $u = $c->prepare('UPDATE pawn_interest_accruals SET missed_due=1,missed_marked_at=NOW() WHERE id=?');
        $u->bind_param('i', $aid);
        $u->execute();
        $u->close();
        $u = $c->prepare('UPDATE pawn_entries SET missed_interest_cycles=missed_interest_cycles+1 WHERE id=? AND business_id=?');
        $u->bind_param('ii', $pid, $b);
        $u->execute();
        $u->close();
    }
    /*
     * Business rule: when an EMI/due schedule is already late, that SAME unpaid
     * schedule must use the pawn's current locked rate.  This also repairs old
     * rows where the pawn was already escalated to Level 2 but schedule #1 was
     * still stored at the original Level 1 rate.
     */
    $currentRate = (float) ($pawn['current_interest_percent'] ?? $pawn['interest_percent'] ?? 0);
    $currentStep = (int) ($pawn['current_rate_step_id'] ?? 0);
    $currentLevel = (int) ($pawn['current_rate_level'] ?? 1);
    if ($currentRate > 0 && abs((float) $a['interest_percent'] - $currentRate) > 0.0005 && (int) ($pawn['rate_escalation_count'] ?? 0) > 0) {
        $repairPawn = $pawn;
        $repairPawn['balance_principal'] = (float) $a['principal_balance'];
        if (strtolower((string) ($repairPawn['interest_method'] ?? 'Simple')) === 'flat')
            $repairPawn['principal_amount'] = (float) $a['principal_balance'];
        list($rDays, $rMonths, $rInterest) = calcCycleInterest($repairPawn, (string) $a['from_date'], (string) $a['to_date'], $currentRate);
        $rTotal = $rInterest + max(0, (float) $a['penalty_amount']) + max(0, (float) $a['other_charges']);
        $rBalance = max(0, $rTotal - (float) $a['paid_amount']);
        $rStatus = $rBalance <= 0.009 ? 'Paid' : ((float) $a['paid_amount'] > 0 ? 'Partially Paid' : 'Pending');
        $u = $c->prepare('UPDATE pawn_interest_accruals SET rate_step_id=NULLIF(?,0),rate_level_no=?,interest_percent=?,calculation_days=?,calculation_months=?,interest_amount=?,total_due=?,balance_amount=?,status=?,rate_escalation_triggered=1,escalated_to_rate_step_id=NULLIF(?,0),escalated_at=COALESCE(escalated_at,NOW()) WHERE id=? AND business_id=?');
        $u->bind_param('iididdddsiii', $currentStep, $currentLevel, $currentRate, $rDays, $rMonths, $rInterest, $rTotal, $rBalance, $rStatus, $currentStep, $aid, $b);
        if (!$u->execute())
            throw new RuntimeException('Unable to apply current rate to overdue EMI: ' . $u->error);
        $u->close();
        return getPawn($c, $b, $br, $pid, true);
    }
    if ((int) $a['rate_escalation_triggered'] === 1) {
        return getPawn($c, $b, $br, $pid, true);
    }
    $stepId = (int) ($pawn['current_rate_step_id'] ?? 0);
    if ($stepId <= 0)
        return getPawn($c, $b, $br, $pid, true);
    $q = $c->prepare('SELECT cur.level_no,cur.next_level_no,nxt.id next_id,nxt.rate_percent,nxt.interest_cycle_type,nxt.interest_cycle_value,nxt.grace_days FROM pawn_interest_rate_steps cur LEFT JOIN pawn_interest_rate_steps nxt ON nxt.scheme_id=cur.scheme_id AND nxt.level_no=cur.next_level_no AND nxt.is_active=1 WHERE cur.id=? AND cur.business_id=? LIMIT 1');
    $q->bind_param('ii', $stepId, $b);
    $q->execute();
    $next = $q->get_result()->fetch_assoc();
    $q->close();
    if (!$next || empty($next['next_id']))
        return getPawn($c, $b, $br, $pid, true);
    $nextId = (int) $next['next_id'];
    $nextRate = (float) $next['rate_percent'];
    $cycleType = (string) $next['interest_cycle_type'];
    $cycleValue = (int) $next['interest_cycle_value'];
    $grace = (int) $next['grace_days'];
    /* Business rule: once the due is missed, Level 2 applies to the unpaid due itself as well as all following cycles. */
    $calcPawn = $pawn;
    $calcPawn['current_interest_percent'] = $nextRate;
    $calcPawn['interest_percent'] = $nextRate;
    $calcPawn['balance_principal'] = (float) $a['principal_balance'];
    if (strtolower((string) ($calcPawn['interest_method'] ?? 'Simple')) === 'flat')
        $calcPawn['principal_amount'] = (float) $a['principal_balance'];
    list($recalcDays, $recalcMonths, $recalcInterest) = calcCycleInterest($calcPawn, (string) $a['from_date'], (string) $a['to_date'], $nextRate);
    $alreadyPaid = max(0, (float) $a['paid_amount']);
    $penalty = max(0, (float) $a['penalty_amount']);
    $other = max(0, (float) $a['other_charges']);
    $newTotal = max(0, $recalcInterest + $penalty + $other);
    $newBalance = max(0, $newTotal - $alreadyPaid);
    $newStatus = $newBalance <= 0.009 ? 'Paid' : ($alreadyPaid > 0 ? 'Partially Paid' : 'Pending');
    $u = $c->prepare('UPDATE pawn_interest_accruals SET rate_step_id=?,rate_level_no=?,interest_percent=?,calculation_days=?,calculation_months=?,interest_amount=?,total_due=?,balance_amount=?,status=?,rate_escalation_triggered=1,escalated_to_rate_step_id=?,escalated_at=NOW() WHERE id=?');
    $newLevel = (int) ($next['next_level_no'] ?? ((int) $next['level_no'] + 1));
    $u->bind_param('iididdddsii', $nextId, $newLevel, $nextRate, $recalcDays, $recalcMonths, $recalcInterest, $newTotal, $newBalance, $newStatus, $nextId, $aid);
    if (!$u->execute())
        throw new RuntimeException('Unable to update overdue interest schedule: ' . $u->error);
    $u->close();
    $u = $c->prepare('UPDATE pawn_entries SET current_rate_step_id=?,current_interest_percent=?,interest_percent=?,interest_due_cycle_type=?,interest_due_cycle_value=?,interest_grace_days=?,grace_days=?,rate_escalation_count=rate_escalation_count+1,rate_escalated_at=NOW() WHERE id=? AND business_id=?');
    $u->bind_param('iddsiiiii', $nextId, $nextRate, $nextRate, $cycleType, $cycleValue, $grace, $grace, $pid, $b);
    if (!$u->execute())
        throw new RuntimeException('Unable to escalate pawn interest rate: ' . $u->error);
    $u->close();
    if (tableExists($c, 'pawn_interest_rate_history')) {
        $h = $c->prepare("UPDATE pawn_interest_rate_history SET effective_to=? WHERE pawn_entry_id=? AND business_id=? AND effective_to IS NULL");
        $eff = (string) $a['to_date'];
        $h->bind_param('sii', $eff, $pid, $b);
        $h->execute();
        $h->close();
        $newPawn = getPawn($c, $b, $br, $pid, true);
        $h = $c->prepare("INSERT INTO pawn_interest_rate_history (business_id,branch_id,pawn_entry_id,scheme_id,rate_step_id,level_no,rate_percent,interest_cycle_type,interest_cycle_value,grace_days,effective_from,change_reason,trigger_accrual_id,previous_rate_percent,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,'Missed Interest Due',?,?,?)");
        if ($h) {
            $scheme = (int) ($newPawn['interest_scheme_id'] ?? 0);
            $level = (int) ($next['next_level_no'] ?? ((int) $next['level_no'] + 1));
            $prev = (float) ($pawn['current_interest_percent'] ?? $pawn['interest_percent'] ?? 0);
            $h->bind_param('iiiiiidsiisidi', $b, $br, $pid, $scheme, $nextId, $level, $nextRate, $cycleType, $cycleValue, $grace, $eff, $aid, $prev, $userId);
            $h->execute();
            $h->close();
        }
    }
    actionLog($c, $b, $br, $pid, 'Rate Escalated', 'pawn_interest_accruals', $aid, 'Interest rate escalated to ' . number_format($nextRate, 3) . '% after missed due', $userId);
    $newPawn = getPawn($c, $b, $br, $pid, true);
    generateNextAccrual($c, $newPawn, $userId, (string) $a['to_date']);
    return getPawn($c, $b, $br, $pid, true);
}
function openAccrual($c, $b, $pid)
{
    if (!tableExists($c, 'pawn_interest_accruals'))
        return null;
    $s = $c->prepare("SELECT * FROM pawn_interest_accruals WHERE pawn_entry_id=? AND business_id=? AND status IN ('Pending','Partially Paid') ORDER BY schedule_no ASC,id ASC LIMIT 1");
    $s->bind_param('ii', $pid, $b);
    $s->execute();
    $r = $s->get_result()->fetch_assoc();
    $s->close();
    return $r ?: null;
}
function bankBlocked($c, $b, $pid)
{
    if (!tableExists($c, 'pawn_bank_loan_items'))
        return false;
    $s = $c->prepare("SELECT COUNT(*) n FROM pawn_bank_loan_items WHERE pawn_entry_id=? AND business_id=? AND status='Pledged'");
    $s->bind_param('ii', $pid, $b);
    $s->execute();
    $n = (int) $s->get_result()->fetch_assoc()['n'];
    $s->close();
    return $n > 0;
}
function quoteInterest($c, $b, $br, $pid, $asOf, $userId, $overrideRate = null)
{
    $c->begin_transaction();
    try {
        $pawn = getPawn($c, $b, $br, $pid, true);
        if (!in_array($pawn['status'], array('Active', 'Partially Paid'), true))
            throw new RuntimeException('This pawn is not open for collection.');
        $pawn = processEscalation($c, $pawn, $asOf, $userId);
        $a = openAccrual($c, $b, $pid);
        if (!$a) {
            $from = (string) ($pawn['last_interest_paid_upto'] ?: $pawn['pawn_date']);
            generateNextAccrual($c, $pawn, $userId, $from);
            $a = openAccrual($c, $b, $pid);
        }
        $c->commit();
    } catch (Throwable $e) {
        $c->rollback();
        throw $e;
    }
    if (!$a)
        throw new RuntimeException('No interest schedule is available for this pawn.');
    $displayRate = (float) $a['interest_percent'];
    $displayInterest = (float) $a['interest_amount'];
    if ($overrideRate !== null) {
        $overrideRate = (float) $overrideRate;
        if ($overrideRate <= 0 || $overrideRate > 100)
            throw new RuntimeException('Enter a valid due schedule interest rate.');
        $qpawn = $pawn;
        $qpawn['balance_principal'] = (float) $a['principal_balance'];
        if (strtolower((string) ($qpawn['interest_method'] ?? 'Simple')) === 'flat')
            $qpawn['principal_amount'] = (float) $a['principal_balance'];
        list($qd, $qm, $displayInterest) = calcCycleInterest($qpawn, (string) $a['from_date'], (string) $a['to_date'], $overrideRate);
        $displayRate = $overrideRate;
    }
    $isDueNow = !empty($a['due_date']) && $a['due_date'] <= $asOf;
    $paidAgainstInterest = min((float) $a['paid_amount'], $displayInterest);
    $interest = $isDueNow ? max(0, $displayInterest - $paidAgainstInterest) : 0.0;
    $penalty = $isDueNow ? max(0, (float) $a['penalty_amount']) : 0.0;
    $otherStored = $isDueNow ? max(0, (float) $a['other_charges']) : 0.0;
    $remaining = $isDueNow ? max(0, $displayInterest + $penalty + $otherStored - (float) $a['paid_amount']) : 0.0;
    $nextSchedule = null;
    if (tableExists($c, 'pawn_interest_accruals')) {
        $ns = $c->prepare("SELECT id,schedule_no,from_date,to_date,due_date,grace_until,rate_level_no,interest_percent,interest_amount,paid_amount,balance_amount,status FROM pawn_interest_accruals WHERE pawn_entry_id=? AND business_id=? AND status IN ('Pending','Partially Paid') AND schedule_no>? ORDER BY schedule_no ASC,id ASC LIMIT 1");
        if ($ns) {
            $curSchedule = (int) $a['schedule_no'];
            $ns->bind_param('iii', $pid, $b, $curSchedule);
            $ns->execute();
            $nextSchedule = $ns->get_result()->fetch_assoc();
            $ns->close();
        }
    }
    $note = !$isDueNow ? 'No interest is due for collection on the selected date. Next scheduled due is ' . (string) $a['due_date'] . '.' : ((int) $a['missed_due'] === 1 ? 'This payment was delayed. The escalated/current rate is applied to this unpaid due schedule and remains locked for following cycles.' : 'Scheduled interest from the registered rule.');
    return array('pawn' => $pawn, 'accrual_id' => (int) $a['id'], 'schedule_no' => (int) $a['schedule_no'], 'from_date' => $a['from_date'], 'to_date' => $a['to_date'], 'due_date' => $a['due_date'], 'grace_until' => $a['grace_until'], 'interest_percent' => $displayRate, 'interest_due' => $interest, 'penalty_due' => $penalty, 'total_due' => $remaining, 'source' => 'Scheduled', 'is_due_now' => $isDueNow, 'next_schedule' => $nextSchedule, 'note' => $note);
}

if ($action === 'init') {
    /*
     * Synchronize every open pawn before calculating dashboard statistics.
     * This is required because an overdue EMI can change from the original
     * Level-1 rate to the pawn's locked escalated rate.  Stats must therefore
     * be calculated only AFTER processEscalation() has rewritten those accruals.
     */
    $syncDate = date('Y-m-d');
    $syncIds = array();
    $syncStmt = $conn->prepare("SELECT id FROM pawn_entries WHERE business_id=? AND branch_id=? AND status IN ('Active','Partially Paid') ORDER BY id ASC");
    if ($syncStmt) {
        $syncStmt->bind_param('ii', $businessId, $branchId);
        $syncStmt->execute();
        $syncRes = $syncStmt->get_result();
        while ($syncRow = $syncRes->fetch_assoc())
            $syncIds[] = (int) $syncRow['id'];
        $syncStmt->close();
    }
    foreach ($syncIds as $syncPawnId) {
        try {
            /* quoteInterest performs the transactional overdue/rate synchronization. */
            quoteInterest($conn, $businessId, $branchId, $syncPawnId, $syncDate, $userId);
        } catch (Throwable $syncError) {
            /* Do not break the entire page for one legacy pawn; its row can still be opened manually. */
        }
    }

    $pawns = array();
    $sql = "SELECT p.id,p.pawn_no,p.pawn_date,p.principal_amount,p.balance_principal,p.interest_percent,p.current_interest_percent,p.current_rate_step_id,p.rate_escalation_count,p.next_interest_due_date,p.interest_grace_days,p.status,COALESCE(c.customer_name,'') customer_name,COALESCE(c.mobile,'') mobile,COALESCE(s.scheme_code,'') interest_scheme_code,COALESCE(s.scheme_name,'') interest_scheme_name,COALESCE(rs.level_no,1) current_rate_level,nrs.rate_percent next_interest_percent FROM pawn_entries p LEFT JOIN customers c ON c.id=p.customer_id AND c.business_id=p.business_id LEFT JOIN pawn_interest_schemes s ON s.id=p.interest_scheme_id AND s.business_id=p.business_id LEFT JOIN pawn_interest_rate_steps rs ON rs.id=p.current_rate_step_id AND rs.business_id=p.business_id LEFT JOIN pawn_interest_rate_steps nrs ON nrs.scheme_id=rs.scheme_id AND nrs.level_no=rs.next_level_no AND nrs.business_id=p.business_id WHERE p.business_id=? AND p.branch_id=? AND p.status IN ('Active','Partially Paid') ORDER BY p.id DESC";
    $s = $conn->prepare($sql);
    $s->bind_param('ii', $businessId, $branchId);
    $s->execute();
    $r = $s->get_result();
    while ($x = $r->fetch_assoc()) {
        $x['grace_until'] = $x['next_interest_due_date'] ? graceDate($x['next_interest_due_date'], (int) $x['interest_grace_days']) : null;
        $pawns[] = $x;
    }
    $s->close();
    $methods = array();
    $s = $conn->prepare('SELECT id,method_name,method_type FROM payment_methods WHERE business_id=? AND is_active=1 ORDER BY method_name');
    $s->bind_param('i', $businessId);
    $s->execute();
    $r = $s->get_result();
    while ($x = $r->fetch_assoc())
        $methods[] = $x;
    $s->close();
    $stats = array('interest_due_count' => 0, 'overdue_count' => 0, 'escalated_count' => 0, 'interest_outstanding' => 0);
    if (tableExists($conn, 'pawn_interest_accruals')) {
        $today = date('Y-m-d');
        $s = $conn->prepare("SELECT COALESCE(SUM(CASE WHEN due_date<=? THEN 1 ELSE 0 END),0) due_count,COALESCE(SUM(CASE WHEN COALESCE(grace_until,due_date)<? THEN 1 ELSE 0 END),0) overdue_count,COALESCE(SUM(CASE WHEN due_date<=? THEN balance_amount ELSE 0 END),0) outstanding FROM pawn_interest_accruals WHERE business_id=? AND branch_id=? AND status IN ('Pending','Partially Paid')");
        $s->bind_param('sssii', $today, $today, $today, $businessId, $branchId);
        $s->execute();
        $z = $s->get_result()->fetch_assoc();
        $s->close();
        $stats['interest_due_count'] = (int) $z['due_count'];
        $stats['overdue_count'] = (int) $z['overdue_count'];
        $stats['interest_outstanding'] = (float) $z['outstanding'];
    }
    $s = $conn->prepare("SELECT COUNT(*) n FROM pawn_entries WHERE business_id=? AND branch_id=? AND status IN ('Active','Partially Paid') AND rate_escalation_count>0");
    $s->bind_param('ii', $businessId, $branchId);
    $s->execute();
    $stats['escalated_count'] = (int) $s->get_result()->fetch_assoc()['n'];
    $s->close();
    out(true, '', array('pawns' => $pawns, 'payment_methods' => $methods, 'stats' => $stats));
}
if ($action === 'interest_quote') {
    $pid = (int) ($_POST['pawn_id'] ?? 0);
    $as = trim((string) ($_POST['as_of_date'] ?? date('Y-m-d')));
    $overrideRaw = trim((string) ($_POST['override_rate'] ?? ''));
    $override = $overrideRaw === '' ? null : (float) $overrideRaw;
    if ($pid <= 0 || !validDate($as))
        out(false, 'Select a pawn and valid calculation date.', array(), 422);
    try {
        $q = quoteInterest($conn, $businessId, $branchId, $pid, $as, $userId, $override);
        out(true, '', $q);
    } catch (Throwable $e) {
        out(false, $e->getMessage(), array(), 422);
    }
}
if ($action === 'interest_collect') {
    $pid = (int) ($_POST['pawn_id'] ?? 0);
    $manualRate = (float) ($_POST['interest_rate'] ?? 0);
    $aid = (int) ($_POST['accrual_id'] ?? 0);
    $date = trim((string) ($_POST['collection_date'] ?? date('Y-m-d')));
    $paid = max(0, (float) ($_POST['paid_amount'] ?? 0));
    $other = max(0, (float) ($_POST['other_charges'] ?? 0));
    $method = (int) ($_POST['payment_method_id'] ?? 0);
    $ref = trim((string) ($_POST['reference_no'] ?? ''));
    $remarks = trim((string) ($_POST['remarks'] ?? ''));
    if ($pid <= 0 || $aid <= 0 || !validDate($date) || $paid <= 0 || $method <= 0)
        out(false, 'Pawn, schedule, paid amount, date and payment method are required.', array(), 422);
    $conn->begin_transaction();
    try {
        $pawn = getPawn($conn, $businessId, $branchId, $pid, true);
        $a = openAccrual($conn, $businessId, $pid);
        if (!$a || (int) $a['id'] !== $aid)
            throw new RuntimeException('Interest schedule changed. Recalculate before collecting.');
        if ($manualRate <= 0 || $manualRate > 100)
            throw new RuntimeException('Enter a valid due schedule rate.');
        $rpawn = $pawn;
        $rpawn['balance_principal'] = (float) $a['principal_balance'];
        if (strtolower((string) ($rpawn['interest_method'] ?? 'Simple')) === 'flat')
            $rpawn['principal_amount'] = (float) $a['principal_balance'];
        list($rdays, $rmonths, $recalcInterest) = calcCycleInterest($rpawn, (string) $a['from_date'], (string) $a['to_date'], $manualRate);
        $storedPenalty = max(0, (float) $a['penalty_amount']);
        $storedOther = max(0, (float) $a['other_charges']);
        $newTotal = $recalcInterest + $storedPenalty + $storedOther;
        $newBalance = max(0, $newTotal - (float) $a['paid_amount']);
        $newStatus = $newBalance <= 0.009 ? 'Paid' : ((float) $a['paid_amount'] > 0 ? 'Partially Paid' : 'Pending');
        $us = $conn->prepare('UPDATE pawn_interest_accruals SET interest_percent=?,calculation_days=?,calculation_months=?,interest_amount=?,total_due=?,balance_amount=?,status=? WHERE id=? AND business_id=?');
        $us->bind_param('diddddsii', $manualRate, $rdays, $rmonths, $recalcInterest, $newTotal, $newBalance, $newStatus, $aid, $businessId);
        if (!$us->execute())
            throw new RuntimeException('Unable to update due schedule rate: ' . $us->error);
        $us->close();
        $a = openAccrual($conn, $businessId, $pid);
        $available = max(0, (float) $a['balance_amount']) + $other;
        if ($paid > $available + 0.01)
            throw new RuntimeException('Paid amount cannot exceed current interest due.');
        $receipt = docNo($conn, $businessId, $branchId, 'pawn_interest_receipt', $date);
        $interestRemain = max(0, (float) $a['interest_amount'] - min((float) $a['paid_amount'], (float) $a['interest_amount']));
        $interestPaid = min($paid, $interestRemain);
        $left = $paid - $interestPaid;
        $penaltyRemain = max(0, (float) $a['penalty_amount']);
        $penaltyPaid = min($left, $penaltyRemain);
        $left -= $penaltyPaid;
        $otherPaid = min($left, $other);
        $total = $interestPaid + $penaltyPaid + $otherPaid;
        $ctype = 'Custom';
        $cycle = (string) ($pawn['interest_collection_cycle'] ?? 'Monthly');
        if (in_array($cycle, array('Monthly', 'Quarterly', 'Half-Yearly', 'Yearly', 'Custom'), true))
            $ctype = $cycle;
        $rateStep = (int) ($a['rate_step_id'] ?? 0);
        $level = (int) ($a['rate_level_no'] ?? 1);
        $wasOverdue = (int) ($a['missed_due'] ?? 0);
        $triggered = (int) ($a['rate_escalation_triggered'] ?? 0);
        $principal = (float) $a['principal_balance'];
        $days = (int) $a['calculation_days'];
        $months = (float) $a['calculation_months'];
        $rate = (float) $a['interest_percent'];
        $s = $conn->prepare('INSERT INTO pawn_interest_collections (business_id,branch_id,pawn_entry_id,accrual_id,rate_step_id,rate_level_no,receipt_no,collection_date,from_date,to_date,due_date,grace_until,was_overdue,triggered_rate_escalation,principal_balance,calculation_days,calculation_months,interest_percent,interest_amount,penalty_amount,other_charges,total_amount,collection_type,payment_method_id,created_by,created_at) VALUES (?,?,?,?,NULLIF(?,0),?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
        if (!$s)
            throw new RuntimeException('Unable to prepare interest receipt: ' . $conn->error);
        $s->bind_param('iiiiiissssssiididdddddsii', $businessId, $branchId, $pid, $aid, $rateStep, $level, $receipt, $date, $a['from_date'], $a['to_date'], $a['due_date'], $a['grace_until'], $wasOverdue, $triggered, $principal, $days, $months, $rate, $interestPaid, $penaltyPaid, $otherPaid, $total, $ctype, $method, $userId);
        if (!$s->execute())
            throw new RuntimeException('Unable to save interest receipt: ' . $s->error);
        $cid = (int) $s->insert_id;
        $s->close();
        if (tableExists($conn, 'pawn_interest_payment_splits')) {
            $s = $conn->prepare('INSERT INTO pawn_interest_payment_splits (interest_collection_id,payment_method_id,amount,reference_no,remarks) VALUES (?,?,?,?,?)');
            $s->bind_param('iidss', $cid, $method, $total, $ref, $remarks);
            $s->execute();
            $s->close();
        }
        $newPaid = (float) $a['paid_amount'] + $total;
        $newBal = max(0, (float) $a['balance_amount'] - $total);
        $status = $newBal <= 0.009 ? 'Paid' : 'Partially Paid';
        $paidAt = $status === 'Paid' ? date('Y-m-d H:i:s') : null;
        $s = $conn->prepare('UPDATE pawn_interest_accruals SET paid_amount=?,balance_amount=?,status=?,paid_at=? WHERE id=? AND business_id=?');
        $s->bind_param('ddssii', $newPaid, $newBal, $status, $paidAt, $aid, $businessId);
        $s->execute();
        $s->close();
        $s = $conn->prepare('UPDATE pawn_entries SET total_interest_collected=total_interest_collected+?,total_penalty_collected=total_penalty_collected+?,total_other_charges_collected=total_other_charges_collected+? WHERE id=? AND business_id=?');
        $s->bind_param('dddii', $interestPaid, $penaltyPaid, $otherPaid, $pid, $businessId);
        $s->execute();
        $s->close();
        if ($status === 'Paid') {
            $to = (string) $a['to_date'];
            $s = $conn->prepare('UPDATE pawn_entries SET last_interest_paid_upto=? WHERE id=? AND business_id=?');
            $s->bind_param('sii', $to, $pid, $businessId);
            $s->execute();
            $s->close();
            $pawn = getPawn($conn, $businessId, $branchId, $pid, true);
            generateNextAccrual($conn, $pawn, $userId, $to);
        }
        actionLog($conn, $businessId, $branchId, $pid, 'Interest Collected', 'pawn_interest_collections', $cid, 'Collected interest receipt ' . $receipt, $userId);
        auditLog($conn, $businessId, $branchId, $userId, 'Create', 'pawn_interest_collections', $cid, 'Collected pawn interest ' . $receipt, array('pawn_id' => $pid, 'receipt_no' => $receipt, 'interest' => $interestPaid, 'penalty' => $penaltyPaid, 'other' => $otherPaid, 'total' => $total));
        $conn->commit();
        out(true, 'Interest collected successfully.', array('receipt_no' => $receipt, 'collection_id' => $cid));
    } catch (Throwable $e) {
        $conn->rollback();
        out(false, $e->getMessage(), array(), 422);
    }
}
function settlementInterestQuote($c, $b, $br, $pid, $asOf, $userId)
{
    $pawn = getPawn($c, $b, $br, $pid, true);
    if (!in_array($pawn['status'], array('Active', 'Partially Paid'), true))
        throw new RuntimeException('This pawn is not open for payment.');

    /* Apply any genuine overdue escalation first, then calculate only up to the selected settlement date. */
    $pawn = processEscalation($c, $pawn, $asOf, $userId);

    $interest = 0.0;
    $penalty = 0.0;
    $other = 0.0;
    $fromDate = (string) (($pawn['last_interest_paid_upto'] ?? '') ?: $pawn['pawn_date']);
    $usedToDate = $fromDate;

    if (!tableExists($c, 'pawn_interest_accruals')) {
        if ($asOf > $fromDate) {
            $rate = (float) ($pawn['current_interest_percent'] ?? $pawn['interest_percent'] ?? 0);
            list($days, $months, $part) = calcCycleInterest($pawn, $fromDate, $asOf, $rate);
            $interest = max(0, (float) $part);
            $usedToDate = $asOf;
        }
        return array('pawn' => $pawn, 'from_date' => $fromDate, 'to_date' => $usedToDate, 'interest_amount' => $interest, 'penalty_amount' => 0.0, 'other_charges' => 0.0, 'interest_outstanding' => $interest);
    }

    $s = $c->prepare("SELECT * FROM pawn_interest_accruals WHERE pawn_entry_id=? AND business_id=? AND status IN ('Pending','Partially Paid') ORDER BY schedule_no ASC,id ASC");
    $s->bind_param('ii', $pid, $b);
    $s->execute();
    $r = $s->get_result();
    while ($a = $r->fetch_assoc()) {
        $aFrom = (string) $a['from_date'];
        $aTo = (string) $a['to_date'];
        if ($asOf <= $aFrom)
            continue;

        $paid = max(0, (float) $a['paid_amount']);
        if ($aTo <= $asOf) {
            /* Entire schedule has accrued by the selected date. Allocate prior payments interest -> penalty -> other. */
            $iTotal = max(0, (float) $a['interest_amount']);
            $pTotal = max(0, (float) $a['penalty_amount']);
            $oTotal = max(0, (float) $a['other_charges']);
            $used = min($paid, $iTotal);
            $interest += max(0, $iTotal - $used);
            $paid -= $used;
            $used = min($paid, $pTotal);
            $penalty += max(0, $pTotal - $used);
            $paid -= $used;
            $used = min($paid, $oTotal);
            $other += max(0, $oTotal - $used);
            $usedToDate = $aTo;
            continue;
        }

        /* Current not-yet-due cycle: charge only the elapsed portion up to the selected closure/payment date. */
        $rate = (float) ($a['interest_percent'] ?? ($pawn['current_interest_percent'] ?? $pawn['interest_percent'] ?? 0));
        $calcPawn = $pawn;
        $calcPawn['balance_principal'] = (float) ($a['principal_balance'] ?? $pawn['balance_principal']);
        if (strtolower((string) ($calcPawn['interest_method'] ?? 'Simple')) === 'flat')
            $calcPawn['principal_amount'] = (float) ($a['principal_balance'] ?? $pawn['principal_amount']);
        list($days, $months, $partInterest) = calcCycleInterest($calcPawn, $aFrom, $asOf, $rate);
        $partInterest = max(0, (float) $partInterest - $paid);
        $interest += $partInterest;
        $usedToDate = $asOf;
        break;
    }
    $s->close();

    /* If no schedule covered the period, calculate from the last paid-through date to selected date. */
    if ($usedToDate === $fromDate && $asOf > $fromDate) {
        $rate = (float) ($pawn['current_interest_percent'] ?? $pawn['interest_percent'] ?? 0);
        list($days, $months, $part) = calcCycleInterest($pawn, $fromDate, $asOf, $rate);
        $interest += max(0, (float) $part);
        $usedToDate = $asOf;
    }

    $total = max(0, $interest + $penalty + $other);
    return array('pawn' => $pawn, 'from_date' => $fromDate, 'to_date' => $usedToDate, 'interest_amount' => $interest, 'penalty_amount' => $penalty, 'other_charges' => $other, 'interest_outstanding' => $total);
}

if ($action === 'payment_quote') {
    $pid = (int) ($_POST['pawn_id'] ?? 0);
    $as = trim((string) ($_POST['as_of_date'] ?? date('Y-m-d')));
    if ($pid <= 0 || !validDate($as))
        out(false, 'Select a pawn and valid payment date.', array(), 422);
    $conn->begin_transaction();
    try {
        $q = settlementInterestQuote($conn, $businessId, $branchId, $pid, $as, $userId);
        $pawn = $q['pawn'];
        $conn->commit();
        out(true, '', array(
            'principal_balance' => (float) $pawn['balance_principal'],
            'interest_outstanding' => (float) $q['interest_outstanding'],
            'interest_amount' => (float) $q['interest_amount'],
            'penalty_amount' => (float) $q['penalty_amount'],
            'other_charges' => (float) $q['other_charges'],
            'interest_from_date' => $q['from_date'],
            'interest_to_date' => $q['to_date'],
            'closure_total' => (float) $pawn['balance_principal'] + (float) $q['interest_outstanding'],
            'bank_release_blocked' => bankBlocked($conn, $businessId, $pid),
            'pawn' => $pawn
        ));
    } catch (Throwable $e) {
        $conn->rollback();
        out(false, $e->getMessage(), array(), 422);
    }
}

if ($action === 'payment_collect') {
    $pid = (int) ($_POST['pawn_id'] ?? 0);
    $date = trim((string) ($_POST['payment_date'] ?? date('Y-m-d')));
    $principal = max(0, (float) ($_POST['principal_amount'] ?? 0));
    $method = (int) ($_POST['payment_method_id'] ?? 0);
    $ref = trim((string) ($_POST['reference_no'] ?? ''));
    $remarks = trim((string) ($_POST['remarks'] ?? ''));
    $closure = !empty($_POST['is_closure']);
    $releasedTo = trim((string) ($_POST['released_to'] ?? ''));
    $relation = trim((string) ($_POST['released_to_relation'] ?? ''));
    $idNo = trim((string) ($_POST['identity_document_no'] ?? ''));
    if ($pid <= 0 || !validDate($date) || $method <= 0 || $principal <= 0)
        out(false, 'Pawn, payment date, principal amount and payment method are required.', array(), 422);
    $conn->begin_transaction();
    try {
        $settlement = settlementInterestQuote($conn, $businessId, $branchId, $pid, $date, $userId);
        $pawn = $settlement['pawn'];
        if ($principal > (float) $pawn['balance_principal'] + 0.01)
            throw new RuntimeException('Principal payment exceeds outstanding principal.');

        $interest = 0.0;
        $penalty = 0.0;
        $otherCharge = 0.0;
        if ($closure) {
            if (abs($principal - (float) $pawn['balance_principal']) > 0.01)
                throw new RuntimeException('Full closure must pay the complete principal balance.');
            if (bankBlocked($conn, $businessId, $pid))
                throw new RuntimeException('Cannot close/release: this pawn gold is still pledged with a bank.');
            if ($releasedTo === '')
                throw new RuntimeException('Enter the person receiving the pawn gold.');
            /* Closure collects the interest accrued only up to the selected payment date. */
            $interest = (float) $settlement['interest_amount'];
            $penalty = (float) $settlement['penalty_amount'];
            $otherCharge = (float) $settlement['other_charges'];
        }

        $receipt = docNo($conn, $businessId, $branchId, 'pawn_payment_receipt', $date);
        $type = $closure ? 'Full Settlement' : 'Principal Only';
        $total = $principal + $interest + $penalty + $otherCharge;
        $isClosure = $closure ? 1 : 0;
        $s = $conn->prepare('INSERT INTO pawn_payments (business_id,branch_id,pawn_entry_id,receipt_no,payment_date,principal_amount,interest_amount,penalty_amount,other_charges,total_amount,payment_type,payment_method_id,reference_no,remarks,is_closure,created_by,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
        $s->bind_param('iiissdddddsissii', $businessId, $branchId, $pid, $receipt, $date, $principal, $interest, $penalty, $otherCharge, $total, $type, $method, $ref, $remarks, $isClosure, $userId);
        if (!$s->execute())
            throw new RuntimeException('Unable to save pawn payment: ' . $s->error);
        $payId = (int) $s->insert_id;
        $s->close();

        if (tableExists($conn, 'pawn_payment_splits')) {
            $s = $conn->prepare('INSERT INTO pawn_payment_splits (pawn_payment_id,payment_method_id,amount,reference_no,remarks) VALUES (?,?,?,?,?)');
            $s->bind_param('iidss', $payId, $method, $total, $ref, $remarks);
            $s->execute();
            $s->close();
        }

        $newBal = max(0, (float) $pawn['balance_principal'] - $principal);
        $status = $closure ? 'Closed' : ($newBal < (float) $pawn['principal_amount'] ? 'Partially Paid' : 'Active');
        $closureDate = $closure ? $date : null;
        $s = $conn->prepare('UPDATE pawn_entries SET total_principal_paid=total_principal_paid+?,total_interest_collected=total_interest_collected+?,total_penalty_collected=total_penalty_collected+?,total_other_charges_collected=total_other_charges_collected+?,balance_principal=?,status=?,closure_date=?,last_interest_paid_upto=CASE WHEN ?=1 THEN ? ELSE last_interest_paid_upto END WHERE id=? AND business_id=?');
        $s->bind_param('dddddssisii', $principal, $interest, $penalty, $otherCharge, $newBal, $status, $closureDate, $isClosure, $date, $pid, $businessId);
        if (!$s->execute())
            throw new RuntimeException('Unable to update pawn balance: ' . $s->error);
        $s->close();

        if ($closure && tableExists($conn, 'pawn_interest_accruals')) {
            /* No future cycle remains after closure; closure payment itself records the exact accrued interest. */
            $s = $conn->prepare("UPDATE pawn_interest_accruals SET status='Cancelled',remarks=CONCAT(COALESCE(remarks,''),CASE WHEN COALESCE(remarks,'')='' THEN '' ELSE ' | ' END,'Closed on ',?) WHERE pawn_entry_id=? AND business_id=? AND status IN ('Pending','Partially Paid')");
            if ($s) {
                $s->bind_param('sii', $date, $pid, $businessId);
                $s->execute();
                $s->close();
            }
        }

        $releaseNo = '';
        if ($closure && tableExists($conn, 'pawn_releases')) {
            $releaseNo = docNo($conn, $businessId, $branchId, 'pawn_release', $date);
            $s = $conn->prepare("INSERT INTO pawn_releases (business_id,branch_id,pawn_entry_id,pawn_payment_id,release_no,release_date,principal_paid,interest_paid,penalty_paid,other_charges,total_paid,released_to,released_to_relation,identity_document_no,identity_verified,item_handover_status,remarks,released_by,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,'Pending',?,?,NOW())");
            $s->bind_param('iiiissdddddssssi', $businessId, $branchId, $pid, $payId, $releaseNo, $date, $principal, $interest, $penalty, $otherCharge, $total, $releasedTo, $relation, $idNo, $remarks, $userId);
            if (!$s->execute())
                throw new RuntimeException('Unable to create pawn release: ' . $s->error);
            $rid = (int) $s->insert_id;
            $s->close();
            actionLog($conn, $businessId, $branchId, $pid, 'Released', 'pawn_releases', $rid, 'Created pending item handover ' . $releaseNo, $userId);
        }

        actionLog($conn, $businessId, $branchId, $pid, $closure ? 'Closed' : 'Principal Collected', 'pawn_payments', $payId, 'Saved pawn payment ' . $receipt, $userId);
        auditLog($conn, $businessId, $branchId, $userId, 'Create', 'pawn_payments', $payId, $closure ? 'Closed pawn ' . $pawn['pawn_no'] : 'Collected pawn principal ' . $receipt, array('pawn_id' => $pid, 'receipt_no' => $receipt, 'principal' => $principal, 'interest' => $interest, 'penalty' => $penalty, 'other' => $otherCharge, 'total' => $total, 'closure' => $closure));
        $conn->commit();
        out(true, $closure ? 'Pawn closed successfully. Principal and interest up to the selected date were collected.' : 'Principal payment saved successfully.', array('receipt_no' => $receipt, 'payment_id' => $payId, 'release_no' => $releaseNo, 'interest_collected' => $interest, 'total_paid' => $total));
    } catch (Throwable $e) {
        $conn->rollback();
        out(false, $e->getMessage(), array(), 422);
    }
}

out(false, 'Invalid action.', array(), 400);
