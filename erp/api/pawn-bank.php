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
function validDate($d)
{
    $x = DateTime::createFromFormat('Y-m-d', $d);
    return $x && $x->format('Y-m-d') === $d;
}
function cycleMonths($cycle, $custom)
{
    $m = array('Monthly' => 1, 'Quarterly' => 3, 'Half-Yearly' => 6, 'Yearly' => 12);
    return isset($m[$cycle]) ? $m[$cycle] : max(1, (int) $custom);
}
function calcBankInterest($principal, $rate, $period, $from, $to)
{
    $d = max(1, (int) (new DateTime($from))->diff(new DateTime($to))->format('%a'));
    if ($period === 'Daily')
        $v = $principal * ($rate / 100) * $d;
    elseif ($period === 'Monthly')
        $v = $principal * ($rate / 100) * ($d / 30);
    else
        $v = $principal * ($rate / 100) * ($d / 365);
    return array($d, round($v, 2));
}
function updatePawnPledgeStatus($c, $b, $pid)
{
    $s = $c->prepare('SELECT COUNT(*) n FROM pawn_items WHERE business_id=? AND pawn_entry_id=?');
    $s->bind_param('ii', $b, $pid);
    $s->execute();
    $z = $s->get_result()->fetch_assoc();
    $total = (int) $z['n'];
    $s->close();
    $s = $c->prepare("SELECT COUNT(DISTINCT pawn_item_id) n FROM pawn_bank_loan_items WHERE business_id=? AND pawn_entry_id=? AND status='Pledged'");
    $s->bind_param('ii', $b, $pid);
    $s->execute();
    $z = $s->get_result()->fetch_assoc();
    $pledged = (int) $z['n'];
    $s->close();
    $status = $pledged <= 0 ? 'Not Pledged' : ($total > 0 && $pledged >= $total ? 'Pledged' : 'Partially Pledged');
    $s = $c->prepare('UPDATE pawn_entries SET bank_pledge_status=? WHERE id=? AND business_id=?');
    $s->bind_param('sii', $status, $pid, $b);
    $s->execute();
    $s->close();
}
function pawnHistory($c, $b, $br, $pid, $loanId, $desc, $u)
{
    if (!tableExists($c, 'pawn_action_history'))
        return;
    $s = $c->prepare("INSERT INTO pawn_action_history (business_id,branch_id,pawn_entry_id,action_type,reference_table,reference_id,description,action_by) VALUES (?,?,?,'Bank Pledged','pawn_bank_loans',?,?,?)");
    if (!$s)
        return;
    $s->bind_param('iiiisi', $b, $br, $pid, $loanId, $desc, $u);
    $s->execute();
    $s->close();
}
function audit($c, $b, $br, $u, $loanId, $type, $desc, $old, $new)
{
    if (!tableExists($c, 'audit_logs'))
        return;
    $s = $c->prepare("INSERT INTO audit_logs (business_id,branch_id,user_id,module_code,action_type,reference_table,reference_id,description,old_values_json,new_values_json,ip_address,user_agent) VALUES (?,?,?,'pawn.bank',?,'pawn_bank_loans',?,?,?,?,?,?)");
    if (!$s)
        return;
    $oj = $old === null ? null : json_encode($old, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $nj = $new === null ? null : json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $s->bind_param('iiisssssss', $b, $br, $u, $type, $loanId, $desc, $oj, $nj, $ip, $ua);
    $s->execute();
    $s->close();
}
function getBanks($c, $b)
{
    $a = array();
    $s = $c->prepare('SELECT id,bank_code,bank_name,branch_name FROM pawn_banks WHERE business_id=? AND is_active=1 ORDER BY bank_name,branch_name');
    $s->bind_param('i', $b);
    $s->execute();
    $r = $s->get_result();
    while ($x = $r->fetch_assoc())
        $a[] = $x;
    $s->close();
    return $a;
}
function loanFinancialLocked($c, $b, $br, $loanId)
{
    if (tableExists($c, 'pawn_bank_payments')) {
        $s = $c->prepare('SELECT COUNT(*) n FROM pawn_bank_payments WHERE business_id=? AND branch_id=? AND bank_loan_id=? AND is_reversed=0');
        $s->bind_param('iii', $b, $br, $loanId);
        $s->execute();
        $z = $s->get_result()->fetch_assoc();
        $s->close();
        if ((int) $z['n'] > 0)
            return true;
    }
    if (tableExists($c, 'pawn_bank_interest_accruals')) {
        $s = $c->prepare('SELECT COUNT(*) n FROM pawn_bank_interest_accruals WHERE business_id=? AND branch_id=? AND bank_loan_id=? AND paid_amount>0');
        $s->bind_param('iii', $b, $br, $loanId);
        $s->execute();
        $z = $s->get_result()->fetch_assoc();
        $s->close();
        if ((int) $z['n'] > 0)
            return true;
    }
    return false;
}
function getLoanRows($c, $b, $br)
{
    $today = date('Y-m-d');
    $loans = array();
    $sql = "SELECT bl.*,pb.bank_name,pb.branch_name,COALESCE(SUM(CASE WHEN bli.status='Pledged' THEN bli.pledged_net_weight ELSE 0 END),0) pledged_net_weight,COUNT(DISTINCT CASE WHEN bli.status='Pledged' THEN bli.pawn_entry_id END) pawn_count FROM pawn_bank_loans bl JOIN pawn_banks pb ON pb.id=bl.bank_id AND pb.business_id=bl.business_id LEFT JOIN pawn_bank_loan_items bli ON bli.bank_loan_id=bl.id AND bli.business_id=bl.business_id WHERE bl.business_id=? AND bl.branch_id=? GROUP BY bl.id ORDER BY bl.pledge_date DESC,bl.id DESC";
    $s = $c->prepare($sql);
    if (!$s)
        throw new RuntimeException('Unable to load bank pawns: ' . $c->error);
    $s->bind_param('ii', $b, $br);
    $s->execute();
    $r = $s->get_result();
    while ($x = $r->fetch_assoc()) {
        $interestValue = 0.0;
        $payable = 0.0;
        $outstanding = 0.0;
        $accruedPaid = 0.0;
        $lid = (int) $x['id'];
        if (tableExists($c, 'pawn_bank_interest_accruals')) {
            $q = $c->prepare("SELECT COALESCE(SUM(interest_amount),0) interest_value,COALESCE(SUM(balance_amount),0) outstanding,COALESCE(SUM(CASE WHEN due_date IS NOT NULL AND due_date<=? AND status IN ('Pending','Partially Paid') THEN balance_amount ELSE 0 END),0) payable,COALESCE(SUM(paid_amount),0) accrual_paid FROM pawn_bank_interest_accruals WHERE business_id=? AND branch_id=? AND bank_loan_id=? AND status<>'Cancelled'");
            $q->bind_param('siii', $today, $b, $br, $lid);
            $q->execute();
            $z = $q->get_result()->fetch_assoc();
            $q->close();
            $interestValue = (float) $z['interest_value'];
            $outstanding = (float) $z['outstanding'];
            $payable = (float) $z['payable'];
            $accruedPaid = (float) $z['accrual_paid'];
        }
        if ($x['interest_payment_cycle'] === 'At Closure') {
            $to = $today;
            if (!empty($x['closure_date']) && $x['closure_date'] < $to)
                $to = $x['closure_date'];
            if ($to >= $x['pledge_date']) {
                list($d, $iv) = calcBankInterest((float) $x['balance_principal'], (float) $x['bank_interest_percent'], (string) $x['bank_interest_period'], (string) $x['pledge_date'], $to);
                $interestValue = max($interestValue, $iv);
            }
            $payable = in_array($x['status'], array('Closed', 'Released'), true) ? max(0, $interestValue - (float) $x['total_interest_paid']) : 0.0;
        }
        $customers = array();
        $q = $c->prepare("SELECT pe.id pawn_entry_id,pe.pawn_no,cu.customer_name,cu.mobile,SUM(CASE WHEN bli.status='Pledged' THEN bli.pledged_net_weight ELSE 0 END) pledged_net_weight,SUM(CASE WHEN bli.status='Pledged' THEN bli.allocated_bank_principal ELSE 0 END) allocated_bank_principal,COUNT(CASE WHEN bli.status='Pledged' THEN 1 END) item_count FROM pawn_bank_loan_items bli JOIN pawn_entries pe ON pe.id=bli.pawn_entry_id AND pe.business_id=bli.business_id JOIN customers cu ON cu.id=pe.customer_id AND cu.business_id=pe.business_id WHERE bli.business_id=? AND bli.branch_id=? AND bli.bank_loan_id=? GROUP BY pe.id,pe.pawn_no,cu.customer_name,cu.mobile ORDER BY pe.pawn_no");
        $q->bind_param('iii', $b, $br, $lid);
        $q->execute();
        $rr = $q->get_result();
        while ($cc = $rr->fetch_assoc())
            $customers[] = $cc;
        $q->close();
        $x['interest_value'] = round($interestValue, 2);
        $x['interest_outstanding'] = round($outstanding, 2);
        $x['payable_interest'] = round($payable, 2);
        $x['accrual_paid'] = round($accruedPaid, 2);
        $x['customers'] = $customers;
        $loans[] = $x;
    }
    $s->close();
    return $loans;
}
foreach (array('pawn_banks', 'pawn_bank_loans', 'pawn_bank_loan_items') as $t)
    if (!tableExists($conn, $t))
        out(false, 'Pawn bank schema is incomplete. Missing ' . $t . '.', array(), 500);
if ($action === 'list') {
    $loans = getLoanRows($conn, $businessId, $branchId);
    $stats = array('active_loans' => 0, 'principal_outstanding' => 0, 'pledged_net_weight' => 0, 'interest_due' => 0);
    foreach ($loans as $l) {
        if (in_array($l['status'], array('Active', 'Partially Paid'), true)) {
            $stats['active_loans']++;
            $stats['principal_outstanding'] += (float) $l['balance_principal'];
        }
        $stats['pledged_net_weight'] += (float) $l['pledged_net_weight'];
        $stats['interest_due'] += (float) $l['payable_interest'];
    }
    out(true, '', array('loans' => $loans, 'stats' => $stats));
}
if ($action === 'entry_init') {
    $loanId = max(0, (int) ($_POST['loan_id'] ?? 0));
    $loan = null;
    $locked = false;
    $selected = array();
    if ($loanId > 0) {
        $s = $conn->prepare('SELECT * FROM pawn_bank_loans WHERE id=? AND business_id=? AND branch_id=? LIMIT 1');
        $s->bind_param('iii', $loanId, $businessId, $branchId);
        $s->execute();
        $loan = $s->get_result()->fetch_assoc();
        $s->close();
        if (!$loan)
            out(false, 'Bank pawn not found.', array(), 404);
        $locked = loanFinancialLocked($conn, $businessId, $branchId, $loanId);
        $s = $conn->prepare("SELECT pawn_item_id,allocated_bank_principal FROM pawn_bank_loan_items WHERE business_id=? AND branch_id=? AND bank_loan_id=? AND status='Pledged'");
        $s->bind_param('iii', $businessId, $branchId, $loanId);
        $s->execute();
        $r = $s->get_result();
        while ($x = $r->fetch_assoc())
            $selected[(int) $x['pawn_item_id']] = (float) $x['allocated_bank_principal'];
        $s->close();
    }
    $items = array();
    $sql = "SELECT pi.id,pi.pawn_entry_id,pi.item_description,pi.quantity,pi.gross_weight,pi.stone_weight,pi.net_weight,pi.purity,pi.estimated_value,p.pawn_no,p.pawn_date,c.customer_name,c.mobile,COALESCE(m.metal_name,'') metal_name,active.bank_loan_id active_bank_loan_id FROM pawn_items pi JOIN pawn_entries p ON p.id=pi.pawn_entry_id AND p.business_id=pi.business_id JOIN customers c ON c.id=p.customer_id AND c.business_id=p.business_id LEFT JOIN metals m ON m.id=pi.metal_id AND m.business_id=pi.business_id LEFT JOIN pawn_bank_loan_items active ON active.pawn_item_id=pi.id AND active.business_id=pi.business_id AND active.status='Pledged' WHERE pi.business_id=? AND p.branch_id=? AND p.status IN ('Active','Partially Paid') AND (active.id IS NULL OR active.bank_loan_id=?) ORDER BY p.id DESC,pi.id";
    $s = $conn->prepare($sql);
    $s->bind_param('iii', $businessId, $branchId, $loanId);
    $s->execute();
    $r = $s->get_result();
    while ($x = $r->fetch_assoc()) {
        $id = (int) $x['id'];
        $x['selected'] = isset($selected[$id]);
        $x['allocated_bank_principal'] = $x['selected'] ? $selected[$id] : 0;
        $items[] = $x;
    }
    $s->close();
    out(true, '', array('banks' => getBanks($conn, $businessId), 'items' => $items, 'loan' => $loan, 'financial_locked' => $locked));
}
if ($action === 'save') {
    $loanId = max(0, (int) ($_POST['loan_id'] ?? 0));
    $bankId = (int) ($_POST['bank_id'] ?? 0);
    $loanNo = trim((string) ($_POST['bank_loan_no'] ?? ''));
    $date = trim((string) ($_POST['pledge_date'] ?? ''));
    $principal = round((float) ($_POST['principal_amount'] ?? 0), 2);
    $rate = (float) ($_POST['bank_interest_percent'] ?? 0);
    $period = trim((string) ($_POST['bank_interest_period'] ?? 'Yearly'));
    $method = trim((string) ($_POST['bank_interest_method'] ?? 'Simple'));
    $cycle = trim((string) ($_POST['interest_payment_cycle'] ?? 'Yearly'));
    $custom = max(1, (int) ($_POST['interest_cycle_months'] ?? 12));
    $maturity = trim((string) ($_POST['maturity_date'] ?? ''));
    $doc = trim((string) ($_POST['bank_document_no'] ?? ''));
    $remarks = trim((string) ($_POST['remarks'] ?? ''));
    $ids = json_decode((string) ($_POST['item_ids'] ?? '[]'), true);
    $alloc = json_decode((string) ($_POST['allocations'] ?? '{}'), true);
    if ($maturity !== '' && !validDate($maturity))
        out(false, 'Invalid maturity date.', array(), 422);
    $existing = null;
    $locked = false;
    if ($loanId > 0) {
        $s = $conn->prepare('SELECT * FROM pawn_bank_loans WHERE id=? AND business_id=? AND branch_id=? LIMIT 1');
        $s->bind_param('iii', $loanId, $businessId, $branchId);
        $s->execute();
        $existing = $s->get_result()->fetch_assoc();
        $s->close();
        if (!$existing)
            out(false, 'Bank pawn not found.', array(), 404);
        $locked = loanFinancialLocked($conn, $businessId, $branchId, $loanId);
    }
    if ($locked) {
        $bankId = (int) $existing['bank_id'];
        $loanNo = (string) $existing['bank_loan_no'];
        $date = (string) $existing['pledge_date'];
        $principal = (float) $existing['principal_amount'];
        $rate = (float) $existing['bank_interest_percent'];
        $period = (string) $existing['bank_interest_period'];
        $method = (string) $existing['bank_interest_method'];
        $cycle = (string) $existing['interest_payment_cycle'];
        $custom = (int) $existing['interest_cycle_months'];
    }
    if ($bankId <= 0 || $loanNo === '' || !validDate($date) || $principal <= 0)
        out(false, 'Enter bank, loan number, pledge date and principal.', array(), 422);
    if ($rate < 0 || $rate > 100)
        out(false, 'Enter a valid bank interest rate.', array(), 422);
    if (!in_array($period, array('Daily', 'Monthly', 'Yearly'), true) || !in_array($method, array('Simple', 'Reducing Balance', 'Flat'), true) || !in_array($cycle, array('Monthly', 'Quarterly', 'Half-Yearly', 'Yearly', 'At Closure', 'Custom'), true))
        out(false, 'Invalid bank interest configuration.', array(), 422);
    if (!$locked && (!is_array($ids) || !count($ids)))
        out(false, 'Select at least one pawn item.', array(), 422);
    $clean = array();
    if (is_array($ids))
        foreach ($ids as $v) {
            $v = (int) $v;
            if ($v > 0 && !in_array($v, $clean, true))
                $clean[] = $v;
        }
    $conn->begin_transaction();
    try {
        $s = $conn->prepare('SELECT id FROM pawn_banks WHERE id=? AND business_id=? AND is_active=1 LIMIT 1 FOR UPDATE');
        $s->bind_param('ii', $bankId, $businessId);
        $s->execute();
        $bank = $s->get_result()->fetch_assoc();
        $s->close();
        if (!$bank)
            throw new RuntimeException('Selected bank is not active.');
        $s = $conn->prepare('SELECT id FROM pawn_bank_loans WHERE business_id=? AND bank_id=? AND bank_loan_no=? AND id<>? LIMIT 1');
        $s->bind_param('iisi', $businessId, $bankId, $loanNo, $loanId);
        $s->execute();
        $dupe = $s->get_result()->fetch_assoc();
        $s->close();
        if ($dupe)
            throw new RuntimeException('This bank loan / pledge number already exists.');
        $months = cycleMonths($cycle, $custom);
        $firstDue = null;
        if ($cycle !== 'At Closure') {
            $d = new DateTime($date);
            $d->modify('+' . $months . ' months');
            $firstDue = $d->format('Y-m-d');
        }
        $nextDue = $firstDue;
        $maturityDb = $maturity === '' ? null : $maturity;
        if ($loanId <= 0) {
            $s = $conn->prepare("INSERT INTO pawn_bank_loans (business_id,branch_id,bank_id,bank_loan_no,pledge_date,principal_amount,balance_principal,bank_interest_percent,bank_interest_period,bank_interest_method,interest_payment_cycle,interest_cycle_months,first_interest_due_date,next_interest_due_date,maturity_date,status,bank_document_no,remarks,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'Active',?,?,?)");
            $s->bind_param('iiissdddsssisssssi', $businessId, $branchId, $bankId, $loanNo, $date, $principal, $principal, $rate, $period, $method, $cycle, $months, $firstDue, $nextDue, $maturityDb, $doc, $remarks, $userId);
            if (!$s->execute())
                throw new RuntimeException('Unable to save bank pawn: ' . $s->error);
            $loanId = (int) $s->insert_id;
            $s->close();
        } else {
            if ($locked) {
                $s = $conn->prepare('UPDATE pawn_bank_loans SET maturity_date=?,bank_document_no=?,remarks=? WHERE id=? AND business_id=? AND branch_id=?');
                $s->bind_param('sssiii', $maturityDb, $doc, $remarks, $loanId, $businessId, $branchId);
                if (!$s->execute())
                    throw new RuntimeException('Unable to update bank pawn: ' . $s->error);
                $s->close();
            } else {
                $balanceRatio = ((float) $existing['principal_amount'] > 0) ? ((float) $existing['balance_principal'] / (float) $existing['principal_amount']) : 1;
                $newBalance = round($principal * $balanceRatio, 2);
                $s = $conn->prepare('UPDATE pawn_bank_loans SET bank_id=?,bank_loan_no=?,pledge_date=?,principal_amount=?,balance_principal=?,bank_interest_percent=?,bank_interest_period=?,bank_interest_method=?,interest_payment_cycle=?,interest_cycle_months=?,first_interest_due_date=?,next_interest_due_date=?,maturity_date=?,bank_document_no=?,remarks=? WHERE id=? AND business_id=? AND branch_id=?');
                $s->bind_param('issdddsssisssssiii', $bankId, $loanNo, $date, $principal, $newBalance, $rate, $period, $method, $cycle, $months, $firstDue, $nextDue, $maturityDb, $doc, $remarks, $loanId, $businessId, $branchId);
                if (!$s->execute())
                    throw new RuntimeException('Unable to update bank pawn: ' . $s->error);
                $s->close();
            }
        }
        $affected = array();
        if (!$locked) {
            $oldPawns = array();
            if ($existing) {
                $s = $conn->prepare("SELECT DISTINCT pawn_entry_id FROM pawn_bank_loan_items WHERE business_id=? AND branch_id=? AND bank_loan_id=? AND status='Pledged'");
                $s->bind_param('iii', $businessId, $branchId, $loanId);
                $s->execute();
                $r = $s->get_result();
                while ($z = $r->fetch_assoc())
                    $oldPawns[(int) $z['pawn_entry_id']] = 1;
                $s->close();
                $s = $conn->prepare('DELETE FROM pawn_bank_loan_items WHERE business_id=? AND branch_id=? AND bank_loan_id=?');
                $s->bind_param('iii', $businessId, $branchId, $loanId);
                $s->execute();
                $s->close();
                if (tableExists($conn, 'pawn_bank_interest_accruals')) {
                    $s = $conn->prepare("DELETE FROM pawn_bank_interest_accruals WHERE business_id=? AND branch_id=? AND bank_loan_id=? AND paid_amount=0 AND status IN ('Pending','Partially Paid')");
                    $s->bind_param('iii', $businessId, $branchId, $loanId);
                    $s->execute();
                    $s->close();
                }
            }
            $allocatedTotal = 0.0;
            foreach ($clean as $itemId) {
                $q = $conn->prepare("SELECT pi.*,p.id pawn_entry_id,p.pawn_no FROM pawn_items pi JOIN pawn_entries p ON p.id=pi.pawn_entry_id AND p.business_id=pi.business_id LEFT JOIN pawn_bank_loan_items x ON x.pawn_item_id=pi.id AND x.business_id=pi.business_id AND x.status='Pledged' WHERE pi.id=? AND pi.business_id=? AND p.branch_id=? AND p.status IN ('Active','Partially Paid') AND x.id IS NULL LIMIT 1 FOR UPDATE");
                $q->bind_param('iii', $itemId, $businessId, $branchId);
                $q->execute();
                $it = $q->get_result()->fetch_assoc();
                $q->close();
                if (!$it)
                    throw new RuntimeException('One selected pawn item is already pledged or unavailable.');
                $a = is_array($alloc) && isset($alloc[(string) $itemId]) ? max(0, (float) $alloc[(string) $itemId]) : 0;
                $allocatedTotal += $a;
                $pid = (int) $it['pawn_entry_id'];
                $qty = (float) $it['quantity'];
                $gw = (float) $it['gross_weight'];
                $sw = (float) $it['stone_weight'];
                $nw = (float) $it['net_weight'];
                $q = $conn->prepare("INSERT INTO pawn_bank_loan_items (business_id,branch_id,bank_loan_id,pawn_entry_id,pawn_item_id,pledged_quantity,pledged_gross_weight,pledged_stone_weight,pledged_net_weight,allocated_bank_principal,status,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,'Pledged',?)");
                $q->bind_param('iiiiidddddi', $businessId, $branchId, $loanId, $pid, $itemId, $qty, $gw, $sw, $nw, $a, $userId);
                if (!$q->execute())
                    throw new RuntimeException('Unable to map pawn gold: ' . $q->error);
                $q->close();
                $affected[$pid] = (string) $it['pawn_no'];
            }
            if ($allocatedTotal > $principal + 0.009)
                throw new RuntimeException('Allocated principal exceeds bank principal received.');
            if ($cycle !== 'At Closure' && tableExists($conn, 'pawn_bank_interest_accruals') && $firstDue) {
                list($days, $interest) = calcBankInterest($principal, $rate, $period, $date, $firstDue);
                $total = $interest;
                $bal = $total;
                $q = $conn->prepare("INSERT INTO pawn_bank_interest_accruals (business_id,branch_id,bank_loan_id,period_no,from_date,to_date,due_date,principal_balance,interest_percent,calculation_days,interest_amount,other_charges,total_due,paid_amount,balance_amount,status,generated_by) VALUES (?,?,?,1,?,?,?,?,?,?,?,0,?,0,?,'Pending',?)");
                $q->bind_param('iiisssddidddi', $businessId, $branchId, $loanId, $date, $firstDue, $firstDue, $principal, $rate, $days, $interest, $total, $bal, $userId);
                if (!$q->execute())
                    throw new RuntimeException('Unable to create bank interest due: ' . $q->error);
                $q->close();
            }
            foreach ($oldPawns as $pid => $v)
                updatePawnPledgeStatus($conn, $businessId, (int) $pid);
            foreach ($affected as $pid => $pno) {
                updatePawnPledgeStatus($conn, $businessId, (int) $pid);
                pawnHistory($conn, $businessId, $branchId, (int) $pid, $loanId, ($existing ? 'Updated' : 'Created') . ' bank pawn ' . $loanNo . ' for ' . $pno, $userId);
            }
        }
        $newVals = array('bank_id' => $bankId, 'bank_loan_no' => $loanNo, 'pledge_date' => $date, 'principal_amount' => $principal, 'bank_interest_percent' => $rate, 'bank_interest_period' => $period, 'interest_payment_cycle' => $cycle, 'interest_cycle_months' => $months, 'maturity_date' => $maturityDb, 'bank_document_no' => $doc, 'remarks' => $remarks);
        audit($conn, $businessId, $branchId, $userId, $loanId, $existing ? 'Update' : 'Create', ($existing ? 'Updated' : 'Created') . ' bank pawn ' . $loanNo, $existing, $newVals);
        $conn->commit();
        out(true, $existing ? 'Bank pawn updated successfully.' : 'Bank pawn created successfully.', array('bank_loan_id' => $loanId));
    } catch (Throwable $e) {
        $conn->rollback();
        out(false, $e->getMessage(), array(), 422);
    }
}
out(false, 'Unknown action.', array(), 400);
