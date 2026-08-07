<?php
if (session_status() === PHP_SESSION_NONE)
    session_start();
header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_OFF);
ini_set('display_errors', '0');
function out(bool $ok, string $msg, array $extra = [], int $code = 200): void
{
    http_response_code($code);
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
register_shutdown_function(static function () {
    if ($e = error_get_last()) {
        if (in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true))
            out(false, 'Fatal API error: ' . $e['message'], [], 500);
    }
});
foreach ([dirname(__DIR__) . '/config/config.php', dirname(__DIR__) . '/config.php', dirname(__DIR__) . '/includes/config.php', dirname(__DIR__) . '/super-admin/includes/config.php'] as $f) {
    if (is_file($f)) {
        require_once $f;
        break;
    }
}
if (!isset($conn) || !($conn instanceof mysqli))
    out(false, 'Database configuration is not available.', [], 500);
$conn->set_charset('utf8mb4');
if (empty($_SESSION['user_id']))
    out(false, 'Session expired.', [], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST')
    out(false, 'Invalid request method.', [], 405);
if (!hash_equals((string) ($_SESSION['pawn_csrf'] ?? ''), (string) ($_POST['csrf_token'] ?? '')))
    out(false, 'Invalid request token. Refresh the page.', [], 419);
function tableExists(mysqli $c, string $t): bool
{
    $s = $c->real_escape_string($t);
    $r = $c->query("SHOW TABLES LIKE '{$s}'");
    return $r && $r->num_rows > 0;
}
function hasCol(mysqli $c, string $t, string $x): bool
{
    $t = $c->real_escape_string($t);
    $x = $c->real_escape_string($x);
    $r = $c->query("SHOW COLUMNS FROM `{$t}` LIKE '{$x}'");
    return $r && $r->num_rows > 0;
}
function bindD(mysqli_stmt $s, string $t, array &$p): void
{
    if (strlen($t) !== count($p))
        throw new RuntimeException('Bind mismatch: ' . strlen($t) . ' types, ' . count($p) . ' values');
    $b = [$t];
    foreach ($p as $k => $v)
        $b[] =& $p[$k];
    call_user_func_array([$s, 'bind_param'], $b);
}

function savePawnProofUpload(string $field, string $existing = ''): string
{
    if (empty($_FILES[$field]) || !is_array($_FILES[$field])) {
        return $existing;
    }

    $file = $_FILES[$field];
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($error === UPLOAD_ERR_NO_FILE) {
        return $existing;
    }

    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Unable to upload ID proof file.');
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    $size = (int)($file['size'] ?? 0);

    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Invalid ID proof upload.');
    }

    if ($size <= 0 || $size > 8 * 1024 * 1024) {
        throw new RuntimeException('ID proof file must be smaller than 8 MB.');
    }

    $mime = '';
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) {
            $mime = (string)finfo_file($fi, $tmp);
            finfo_close($fi);
        }
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    if (!isset($allowed[$mime])) {
        throw new RuntimeException('ID proof must be JPG, PNG, WEBP or PDF.');
    }

    $root = dirname(__DIR__);
    $relativeDir = 'uploads/pawn-proofs/' . date('Y/m');
    $targetDir = $root . '/' . $relativeDir;

    if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
        throw new RuntimeException('Unable to create ID proof upload folder.');
    }

    $name = 'proof_' . date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $allowed[$mime];
    $target = $targetDir . '/' . $name;

    if (!move_uploaded_file($tmp, $target)) {
        throw new RuntimeException('Unable to save ID proof file.');
    }

    return $relativeDir . '/' . $name;
}
function financialYearParts(string $date): array
{
    $ts = strtotime($date ?: date('Y-m-d'));
    $year = (int) date('Y', $ts);
    $month = (int) date('n', $ts);
    $start = $month >= 4 ? $year : $year - 1;
    return [$start, $start + 1];
}
function docNumber(mysqli $c, int $b, int $br, string $key, string $date, bool $consume): string
{
    if (!tableExists($c, 'document_number_settings')) {
        throw new RuntimeException('Document number settings table is missing.');
    }
    $s = $c->prepare("SELECT * FROM document_number_settings WHERE business_id=? AND document_key=? AND is_active=1 AND (branch_id=? OR branch_id IS NULL) ORDER BY (branch_id=? ) DESC,id DESC LIMIT 1");
    if (!$s)
        throw new RuntimeException('Unable to read document number setting: ' . $c->error);
    $s->bind_param('isii', $b, $key, $br, $br);
    $s->execute();
    $set = $s->get_result()->fetch_assoc();
    $s->close();
    if (!$set) {
        if ($key === 'pawn') {
            $n = 1;
            $q = $c->prepare('SELECT pawn_no FROM pawn_entries WHERE business_id=? AND branch_id=? ORDER BY id DESC LIMIT 1');
            if ($q) {
                $q->bind_param('ii', $b, $br);
                $q->execute();
                $r = $q->get_result()->fetch_assoc();
                $q->close();
                if ($r)
                    $n = (int) preg_replace('/\D/', '', $r['pawn_no']) + 1;
            }
            return 'PN' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
        }
        throw new RuntimeException('Configure ' . $key . ' numbering in Document Number Settings.');
    }
    $d = $date ?: date('Y-m-d');
    $ts = strtotime($d);
    [$fy1, $fy2] = financialYearParts($d);
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
    $docType = $key;
    $current = 0;
    $q = $c->prepare('SELECT current_number FROM number_sequences WHERE business_id=? AND branch_id=? AND document_type=? AND period_key=? LIMIT 1' . ($consume ? ' FOR UPDATE' : ''));
    if ($q) {
        $q->bind_param('iiss', $b, $br, $docType, $period);
        $q->execute();
        $r = $q->get_result()->fetch_assoc();
        $q->close();
        if ($r)
            $current = (int) $r['current_number'];
    }
    $next = max((int) $set['sequence_start'], $current + 1);
    if ($consume) {
        $q = $c->prepare('INSERT INTO number_sequences (business_id,branch_id,document_type,period_key,current_number) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE current_number=VALUES(current_number)');
        if (!$q)
            throw new RuntimeException('Unable to update document sequence: ' . $c->error);
        $q->bind_param('iissi', $b, $br, $docType, $period, $next);
        if (!$q->execute())
            throw new RuntimeException('Unable to update document sequence: ' . $q->error);
        $q->close();
    }
    $digits = max(1, (int) $set['sequence_digits']);
    $seq = str_pad((string) $next, $digits, '0', STR_PAD_LEFT);
    $center = strtr((string) $set['center_format'], ['{YYYY}' => date('Y', $ts), '{YY}' => date('y', $ts), '{MM}' => date('m', $ts), '{DD}' => date('d', $ts), '{FY_SHORT}' => substr((string) $fy1, 2) . '-' . substr((string) $fy2, 2), '{FY}' => $fy1 . '-' . $fy2]);
    $template = (string) $set['format_template'];
    return strtr($template, ['{PREFIX}' => (string) $set['prefix'], '{DIVIDER}' => (string) $set['divider'], '{CENTER}' => $center, '{FY_SHORT}' => substr((string) $fy1, 2) . '-' . substr((string) $fy2, 2), '{SEQ}' => $seq, '{SUFFIX}' => (string) $set['suffix']]);
}
function audit(mysqli $c, int $b, int $br, int $u, int $id, string $no): void
{
    if (!tableExists($c, 'audit_logs'))
        return;
    $s = $c->prepare("INSERT INTO audit_logs (business_id,branch_id,user_id,module_code,action_type,reference_table,reference_id,description,new_values_json,ip_address,user_agent) VALUES (?,?,?,'pawn.entry','Create','pawn_entries',?,?,?,?,?)");
    if (!$s)
        return;
    $d = 'Created pawn entry ' . $no;
    $j = json_encode(['pawn_no' => $no]);
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $s->bind_param('iiiissss', $b, $br, $u, $id, $d, $j, $ip, $ua);
    $s->execute();
    $s->close();
}
$b = (int) ($_SESSION['business_id'] ?? 0);
$br = (int) ($_SESSION['branch_id'] ?? ($_SESSION['default_branch_id'] ?? 0));
$u = (int) ($_SESSION['user_id'] ?? 0);
$a = (string) ($_POST['action'] ?? '');
if ($b <= 0 || $br <= 0)
    out(false, 'Select a valid business and branch.', [], 403);
if (!tableExists($conn, 'pawn_entries') || !tableExists($conn, 'pawn_items'))
    out(false, 'Required pawn tables are missing.', [], 500);
if ($a === 'options') {
    $customers = [];
    $sel = ['id', 'customer_code', 'customer_name', 'mobile', 'email', 'address_line1', 'address_line2', 'city', 'state', 'pincode'];
    $sel[] = hasCol($conn, 'customers', 'alternate_mobile') ? 'alternate_mobile' : "'' AS alternate_mobile";
    $sel[] = hasCol($conn, 'customers', 'id_proof_type') ? 'id_proof_type' : "'' AS id_proof_type";
    $sel[] = hasCol($conn, 'customers', 'id_proof_number') ? 'id_proof_number' : "'' AS id_proof_number";

    $proofImageCol = '';
    foreach (['id_proof_image', 'id_proof_image_path', 'proof_image', 'proof_image_path'] as $candidate) {
        if (hasCol($conn, 'customers', $candidate)) {
            $proofImageCol = $candidate;
            break;
        }
    }
    $sel[] = $proofImageCol !== '' ? "`{$proofImageCol}` AS id_proof_image" : "'' AS id_proof_image";

    $sel[] = hasCol($conn, 'customers', 'kyc_verified') ? 'kyc_verified' : '0 AS kyc_verified';
    $sel[] = hasCol($conn, 'customers', 'risk_category') ? 'risk_category' : "'' AS risk_category";
    $sel[] = hasCol($conn, 'customers', 'pawn_credit_limit') ? 'pawn_credit_limit' : '0 AS pawn_credit_limit';
    $sel[] = hasCol($conn, 'customers', 'pawn_service_active') ? 'pawn_service_active' : '0 AS pawn_service_active';
    $s = $conn->prepare('SELECT ' . implode(',', $sel) . ' FROM customers WHERE business_id=? AND is_active=1 ORDER BY customer_name');
    if ($s) {
        $s->bind_param('i', $b);
        $s->execute();
        $r = $s->get_result();
        while ($x = $r->fetch_assoc())
            $customers[] = $x;
        $s->close();
    }
    $categories = [];
    $s = $conn->prepare("SELECT id,category_code,category_name,category_type,metal_type,purity_standard,default_interest_percent,max_loan_percent,valuation_method FROM pawn_categories WHERE business_id=? AND is_active=1 ORDER BY category_name");
    if ($s) {
        $s->bind_param('i', $b);
        $s->execute();
        $r = $s->get_result();
        while ($x = $r->fetch_assoc())
            $categories[] = $x;
        $s->close();
    }
    $metals = [];
    if (tableExists($conn, 'metals')) {
        $s = $conn->prepare('SELECT id,metal_name FROM metals WHERE business_id=? AND is_active=1 ORDER BY metal_name');
        if ($s) {
            $s->bind_param('i', $b);
            $s->execute();
            $r = $s->get_result();
            while ($x = $r->fetch_assoc())
                $metals[] = $x;
            $s->close();
        }
    }
    $rates = [];
    if (tableExists($conn, 'metal_rates')) {
        $cols = ['rate_per_gram', 'purity'];
        $cols[] = hasCol($conn, 'metal_rates', 'metal_id') ? 'metal_id' : 'NULL AS metal_id';
        if (hasCol($conn, 'metal_rates', 'effective_date')) {
            $cols[] = 'effective_date';
        } elseif (hasCol($conn, 'metal_rates', 'rate_date')) {
            $cols[] = 'rate_date';
        }
        $where = ' WHERE business_id=?';
        if (hasCol($conn, 'metal_rates', 'effective_date'))
            $where .= ' AND effective_date<=CURDATE()';
        $s = $conn->prepare('SELECT ' . implode(',', $cols) . ' FROM metal_rates' . $where . ' ORDER BY id DESC');
        if ($s) {
            $s->bind_param('i', $b);
            $s->execute();
            $r = $s->get_result();
            $seen = [];
            while ($x = $r->fetch_assoc()) {
                $k = ($x['metal_id'] ?? '') . '|' . strtolower((string) ($x['purity'] ?? ''));
                if (!isset($seen[$k])) {
                    $seen[$k] = 1;
                    $rates[] = $x;
                }
            }
            $s->close();
        }
    }
    $methods = [];
    if (tableExists($conn, 'payment_methods')) {
        $s = $conn->prepare('SELECT id,method_name FROM payment_methods WHERE business_id=? AND is_active=1 ORDER BY method_name');
        if ($s) {
            $s->bind_param('i', $b);
            $s->execute();
            $r = $s->get_result();
            while ($x = $r->fetch_assoc())
                $methods[] = $x;
            $s->close();
        }
    }
    out(true, 'Options loaded.', ['next_pawn_no' => docNumber($conn, $b, $br, 'pawn', date('Y-m-d'), false), 'customers' => $customers, 'categories' => $categories, 'metals' => $metals, 'metal_rates' => $rates, 'payment_methods' => $methods]);
}
if ($a === 'create') {
    $date = trim((string) ($_POST['pawn_date'] ?? ''));
    $cid = (int) ($_POST['customer_id'] ?? 0);
    $cat = (int) ($_POST['pawn_category_id'] ?? 0);
    $principal = max(0, (float) ($_POST['principal_amount'] ?? 0));
    $interest = max(0, (float) ($_POST['interest_percent'] ?? 0));
    $period = trim((string) ($_POST['interest_period'] ?? 'Monthly'));
    $due = trim((string) ($_POST['due_date'] ?? ''));
    $remarks = trim((string) ($_POST['remarks'] ?? ''));
    $loanType = trim((string) ($_POST['loan_type'] ?? 'General'));
    $method = trim((string) ($_POST['interest_method'] ?? 'Simple'));
    $tenure = max(0, (int) ($_POST['tenure_months'] ?? 0));
    $idType = trim((string) ($_POST['id_proof_type'] ?? ''));
    $idNo = trim((string) ($_POST['id_proof_number'] ?? ''));
    $documentCharge = max(0, (float) ($_POST['document_charge'] ?? ($_POST['ticket_charge'] ?? 0)));
    $primaryMetalId = max(0, (int) ($_POST['primary_metal_id'] ?? 0));
    $postedDisbursement = max(0, (float) ($_POST['disbursement_amount'] ?? 0));
    $existingProofImage = trim((string) ($_POST['existing_id_proof_image'] ?? $_POST['customer_id_proof_existing'] ?? ''));
    $proofImage = savePawnProofUpload('id_proof_image', $existingProofImage);
    $cycle = trim((string) ($_POST['interest_collection_cycle'] ?? 'Monthly'));
    $cycleMonths = max(1, (int) ($_POST['interest_cycle_months'] ?? 1));
    $atClosure = $cycle === 'At Closure';
    $minDays = $atClosure ? 0 : max(0, (int) ($_POST['minimum_interest_days'] ?? 0));
    $rounding = trim((string) ($_POST['interest_rounding_method'] ?? 'Nearest Rupee'));
    $grace = $atClosure ? 0 : max(0, (int) ($_POST['grace_days'] ?? 0));
    $overdueType = $atClosure ? 'None' : trim((string) ($_POST['overdue_charge_type'] ?? 'None'));
    $overdueValue = $atClosure ? 0.0 : max(0, (float) ($_POST['overdue_charge_value'] ?? 0));
    $maxOverdueRaw = trim((string) ($_POST['maximum_overdue_charge'] ?? ''));
    $maxOverdue = ($atClosure || $maxOverdueRaw === '') ? null : max(0, (float) $maxOverdueRaw);
    $nextDue = $atClosure ? '' : trim((string) ($_POST['next_interest_due_date'] ?? ''));
    $auctionDate = $atClosure ? '' : trim((string) ($_POST['auction_eligible_date'] ?? ''));
    $collectFirst = !$atClosure && !empty($_POST['collect_first_interest']);
    $firstAmount = $collectFirst ? max(0, (float) ($_POST['first_interest_amount'] ?? 0)) : 0.0;
    $firstMethod = $collectFirst ? (int) ($_POST['first_interest_payment_method_id'] ?? 0) : 0;
    $firstRef = trim((string) ($_POST['first_interest_reference'] ?? ''));
    $firstPaidUpto = $collectFirst ? trim((string) ($_POST['first_interest_paid_upto'] ?? '')) : '';
    $other = max(0, (float) ($_POST['other_charge'] ?? 0));
    $pm = (int) ($_POST['payment_method_id'] ?? 0);
    $pref = trim((string) ($_POST['payment_reference'] ?? ''));
    if ($date === '')
        out(false, 'Pawn date is required.');
    if ($cid <= 0)
        out(false, 'Select a customer.');
    if ($cat <= 0)
        out(false, 'Select a pawn category.');
    if ($principal <= 0)
        out(false, 'Principal amount must be greater than zero.');

    $disbursement = round(max(0, $principal - $documentCharge - $other), 2);
    if ($disbursement <= 0)
        out(false, 'Amount given to customer must be greater than zero after deducting document and other charges.');

    if ($postedDisbursement > 0 && abs($postedDisbursement - $disbursement) > 0.01) {
        // Always trust the server-side calculation.
        $postedDisbursement = $disbursement;
    }
    if (!in_array($period, ['Monthly', 'Daily', 'Yearly'], true))
        out(false, 'Invalid interest period.');
    if (!$atClosure && $tenure <= 0)
        out(false, 'Tenure months must be greater than zero.');
    if (!in_array($cycle, ['Monthly', 'Quarterly', 'Half-Yearly', 'Yearly', 'At Closure', 'Custom'], true))
        out(false, 'Invalid interest collection cycle.');
    if ($pm <= 0)
        out(false, 'Select the disbursement payment method.');

    if ($collectFirst && ($firstAmount <= 0 || $firstMethod <= 0 || $firstPaidUpto === ''))
        out(false, 'Complete the first interest collection details.');
    if ($atClosure) {
        $tenure = 0;
        $due = '';
        $cycleMonths = 1;
    }
    $s = $conn->prepare('SELECT id FROM customers WHERE id=? AND business_id=? AND is_active=1 LIMIT 1');
    $s->bind_param('ii', $cid, $b);
    $s->execute();
    $customer = $s->get_result()->fetch_assoc();
    $s->close();
    if (!$customer)
        out(false, 'Selected customer was not found.');

    $customerUpdates = [];
    $customerTypes = '';
    $customerVals = [];

    if ($idType !== '' && hasCol($conn, 'customers', 'id_proof_type')) {
        $customerUpdates[] = 'id_proof_type=?';
        $customerTypes .= 's';
        $customerVals[] = $idType;
    }
    if ($idNo !== '' && hasCol($conn, 'customers', 'id_proof_number')) {
        $customerUpdates[] = 'id_proof_number=?';
        $customerTypes .= 's';
        $customerVals[] = $idNo;
    }

    $customerProofCol = '';
    foreach (['id_proof_image', 'id_proof_image_path', 'proof_image', 'proof_image_path'] as $candidate) {
        if (hasCol($conn, 'customers', $candidate)) {
            $customerProofCol = $candidate;
            break;
        }
    }
    if ($proofImage !== '' && $customerProofCol !== '') {
        $customerUpdates[] = "`{$customerProofCol}`=?";
        $customerTypes .= 's';
        $customerVals[] = $proofImage;
    }

    if ($customerUpdates) {
        $customerTypes .= 'ii';
        $customerVals[] = $cid;
        $customerVals[] = $b;
        $s = $conn->prepare('UPDATE customers SET ' . implode(',', $customerUpdates) . ' WHERE id=? AND business_id=?');
        if (!$s)
            out(false, 'Unable to prepare customer KYC update: ' . $conn->error, [], 500);
        bindD($s, $customerTypes, $customerVals);
        if (!$s->execute()) {
            $err = $s->error;
            $s->close();
            out(false, 'Unable to update customer KYC: ' . $err, [], 500);
        }
        $s->close();
    }

    $s = $conn->prepare('SELECT id FROM pawn_categories WHERE id=? AND business_id=? AND is_active=1 LIMIT 1');
    $s->bind_param('ii', $cat, $b);
    $s->execute();
    $category = $s->get_result()->fetch_assoc();
    $s->close();
    if (!$category)
        out(false, 'Selected category was not found.');
    $mids = $_POST['metal_id'] ?? [];
    $desc = $_POST['item_description'] ?? [];
    $qtys = $_POST['quantity'] ?? [];
    $pur = $_POST['purity'] ?? [];
    $gross = $_POST['gross_weight'] ?? [];
    $stone = $_POST['stone_weight'] ?? [];
    $rates = $_POST['rate_per_gram'] ?? [];
    $iremarks = $_POST['item_remarks'] ?? [];
    $items = [];
    $tg = $ts = $tn = $te = 0.0;
    foreach ($desc as $i => $d) {
        $d = trim((string) $d);
        if ($d === '')
            continue;
        $mid = (int) ($mids[$i] ?? 0);
        $q = max(1, (int) ($qtys[$i] ?? 1));
        $p = trim((string) ($pur[$i] ?? ''));
        $g = max(0, (float) ($gross[$i] ?? 0));
        $st = max(0, (float) ($stone[$i] ?? 0));
        $n = max(0, $g - $st);
        $rate = max(0, (float) ($rates[$i] ?? 0));
        $est = round($n * $rate, 2);
        $ir = trim((string) ($iremarks[$i] ?? ''));
        if ($mid <= 0)
            out(false, 'Select metal for every item.');
        if ($g <= 0)
            out(false, 'Gross weight must be greater than zero.');
        if ($st > $g)
            out(false, 'Stone or less weight cannot exceed gross weight.');
        if ($n <= 0)
            out(false, 'Net weight must be greater than zero.');
        if ($rate <= 0)
            out(false, 'Rate per gram must be greater than zero for every pawn item.');
        $items[] = ['mid' => $mid, 'd' => $d, 'q' => $q, 'p' => $p, 'g' => $g, 's' => $st, 'n' => $n, 'r' => $rate, 'e' => $est, 'rm' => $ir];
        $tg += $g;
        $ts += $st;
        $tn += $n;
        $te += $est;
    }
    if (!$items)
        out(false, 'Add at least one valid pawn item.');
    $extras = ['Loan Type: ' . $loanType, 'Interest Method: ' . $method, 'Tenure: ' . $tenure . ' month(s)'];
    if ($idType !== '' || $idNo !== '')
        $extras[] = 'ID Proof: ' . trim($idType . ' ' . $idNo);
    if ($documentCharge > 0)
        $extras[] = 'Document Charge: ₹' . number_format($documentCharge, 2);
    if ($other > 0)
        $extras[] = 'Other Charge: ₹' . number_format($other, 2);
    $extras[] = 'Amount Given: ₹' . number_format($disbursement, 2);
    if ($primaryMetalId > 0)
        $extras[] = 'Primary Metal ID: ' . $primaryMetalId;
    if ($proofImage !== '')
        $extras[] = 'ID Proof File: ' . $proofImage;
    if ($pm > 0)
        $extras[] = 'Disbursement Method ID: ' . $pm;
    if ($pref !== '')
        $extras[] = 'Disbursement Reference: ' . $pref;
    foreach ($items as $i => $it)
        if ($it['rm'] !== '')
            $extras[] = 'Item ' . ($i + 1) . ' Remark: ' . $it['rm'];
    $final = trim(implode("\n", array_filter([$remarks, implode(' | ', $extras)])));
    $conn->begin_transaction();
    try {
        $no = docNumber($conn, $b, $br, 'pawn', $date, true);
        $cols = ['business_id', 'branch_id', 'pawn_no', 'pawn_date', 'customer_id', 'pawn_category_id', 'principal_amount', 'interest_percent', 'interest_period', 'total_gross_weight', 'total_net_weight', 'total_interest_collected', 'total_principal_paid', 'balance_principal', 'status', 'remarks', 'created_by'];
        $ph = array_fill(0, count($cols), '?');
        $types = 'iissiidddsddddssi';
        $vals = [$b, $br, $no, $date, $cid, $cat, $principal, $interest, $period, $tg, $tn, $firstAmount, 0.0, $principal, 'Active', $final, $u];
        if ($due !== '') {
            $cols[] = 'due_date';
            $ph[] = '?';
            $types .= 's';
            $vals[] = $due;
        }
        $opt = [
            'total_stone_weight' => ['d', $ts],
            'total_estimated_value' => ['d', $te],
            'loan_type' => ['s', $loanType],
            'primary_metal_id' => ['i', $primaryMetalId > 0 ? $primaryMetalId : null],
            'interest_method' => ['s', $method],
            'interest_collection_cycle' => ['s', $cycle],
            'interest_cycle_months' => ['i', $cycleMonths],
            'minimum_interest_days' => ['i', $minDays],
            'interest_rounding_method' => ['s', $rounding],
            'next_interest_due_date' => ['s', $nextDue ?: null],
            'grace_days' => ['i', $grace],
            'overdue_charge_type' => ['s', $overdueType],
            'overdue_charge_value' => ['d', $overdueValue],
            'maximum_overdue_charge' => ['d', $maxOverdue],
            'auction_eligible_date' => ['s', $auctionDate ?: null],
            'tenure_months' => ['i', $tenure],
            'id_proof_type' => ['s', $idType],
            'id_proof_number' => ['s', $idNo],
            'id_proof_image' => ['s', $proofImage],
            'id_proof_image_path' => ['s', $proofImage],
            'document_charge' => ['d', $documentCharge],
            'ticket_charge' => ['d', $documentCharge],
            'other_charge' => ['d', $other],
            'disbursement_amount' => ['d', $disbursement],
            'payment_method_id' => ['i', $pm],
            'disbursement_payment_method_id' => ['i', $pm],
            'payment_reference' => ['s', $pref]
        ];
        foreach ($opt as $c => [$t, $v])
            if (hasCol($conn, 'pawn_entries', $c)) {
                $cols[] = $c;
                $ph[] = '?';
                $types .= $t;
                $vals[] = $v;
            }
        if (hasCol($conn, 'pawn_entries', 'created_at')) {
            $cols[] = 'created_at';
            $ph[] = 'NOW()';
        }
        $s = $conn->prepare('INSERT INTO pawn_entries (' . implode(',', $cols) . ') VALUES (' . implode(',', $ph) . ')');
        if (!$s)
            throw new RuntimeException('Unable to prepare pawn entry: ' . $conn->error);
        bindD($s, $types, $vals);
        if (!$s->execute())
            throw new RuntimeException('Unable to save pawn entry: ' . $s->error);
        $pid = (int) $s->insert_id;
        $s->close();
        foreach ($items as $it) {
            $c = ['business_id', 'pawn_entry_id', 'metal_id', 'item_description', 'quantity', 'gross_weight', 'stone_weight', 'net_weight', 'purity', 'estimated_value'];
            $p = array_fill(0, count($c), '?');
            $t = 'iiisidddsd';
            $v = [$b, $pid, $it['mid'], $it['d'], $it['q'], $it['g'], $it['s'], $it['n'], $it['p'], $it['e']];
            if (hasCol($conn, 'pawn_items', 'rate_per_gram')) {
                $c[] = 'rate_per_gram';
                $p[] = '?';
                $t .= 'd';
                $v[] = $it['r'];
            }
            if (hasCol($conn, 'pawn_items', 'remarks')) {
                $c[] = 'remarks';
                $p[] = '?';
                $t .= 's';
                $v[] = $it['rm'];
            }
            if (hasCol($conn, 'pawn_items', 'created_at')) {
                $c[] = 'created_at';
                $p[] = 'NOW()';
            }
            $s = $conn->prepare('INSERT INTO pawn_items (' . implode(',', $c) . ') VALUES (' . implode(',', $p) . ')');
            if (!$s)
                throw new RuntimeException('Unable to prepare pawn item: ' . $conn->error);
            bindD($s, $t, $v);
            if (!$s->execute())
                throw new RuntimeException('Unable to save pawn item: ' . $s->error);
            $s->close();
        }
        if ($collectFirst) {
            if (!tableExists($conn, 'pawn_interest_collections'))
                throw new RuntimeException('Pawn interest collections table is missing.');
            $receipt = docNumber($conn, $b, $br, 'pawn_interest_receipt', $date, true);
            $cols = ['business_id', 'branch_id', 'pawn_entry_id', 'receipt_no', 'collection_date', 'from_date', 'to_date', 'interest_amount', 'penalty_amount', 'total_amount', 'payment_method_id', 'created_by'];
            $vals = [$b, $br, $pid, $receipt, $date, $date, $firstPaidUpto, $firstAmount, 0.0, $firstAmount, $firstMethod, $u];
            $types = 'iiissssdddii';
            if (hasCol($conn, 'pawn_interest_collections', 'principal_balance')) {
                $cols[] = 'principal_balance';
                $vals[] = $principal;
                $types .= 'd';
            }
            if (hasCol($conn, 'pawn_interest_collections', 'interest_percent')) {
                $cols[] = 'interest_percent';
                $vals[] = $interest;
                $types .= 'd';
            }
            if (hasCol($conn, 'pawn_interest_collections', 'collection_type')) {
                $cols[] = 'collection_type';
                $vals[] = $cycle;
                $types .= 's';
            }
            if (hasCol($conn, 'pawn_interest_collections', 'reference_no')) {
                $cols[] = 'reference_no';
                $vals[] = $firstRef;
                $types .= 's';
            }
            if (hasCol($conn, 'pawn_interest_collections', 'remarks')) {
                $cols[] = 'remarks';
                $vals[] = 'First interest collected on pawn disbursement date';
                $types .= 's';
            }
            $ph = array_fill(0, count($cols), '?');
            $s = $conn->prepare('INSERT INTO pawn_interest_collections (' . implode(',', $cols) . ') VALUES (' . implode(',', $ph) . ')');
            if (!$s)
                throw new RuntimeException('Unable to prepare first interest collection: ' . $conn->error);
            bindD($s, $types, $vals);
            if (!$s->execute())
                throw new RuntimeException('Unable to save first interest collection: ' . $s->error);
            $interestCollectionId = (int) $s->insert_id;
            $s->close();
            if (tableExists($conn, 'pawn_interest_payment_splits')) {
                $s = $conn->prepare('INSERT INTO pawn_interest_payment_splits (interest_collection_id,payment_method_id,amount,reference_no) VALUES (?,?,?,?)');
                if ($s) {
                    $s->bind_param('iids', $interestCollectionId, $firstMethod, $firstAmount, $firstRef);
                    $s->execute();
                    $s->close();
                }
            }
            if (hasCol($conn, 'pawn_entries', 'last_interest_paid_upto')) {
                $s = $conn->prepare('UPDATE pawn_entries SET last_interest_paid_upto=?,next_interest_due_date=? WHERE id=? AND business_id=?');
                if ($s) {
                    $s->bind_param('ssii', $firstPaidUpto, $nextDue, $pid, $b);
                    $s->execute();
                    $s->close();
                }
            }
            if (tableExists($conn, 'pawn_action_history')) {
                $s = $conn->prepare("INSERT INTO pawn_action_history (business_id,branch_id,pawn_entry_id,action_type,reference_table,reference_id,description,action_by) VALUES (?,?,?,'Interest Collected','pawn_interest_collections',?,?,?)");
                if ($s) {
                    $descText = 'First interest collected on pawn date: ' . $receipt;
                    $s->bind_param('iiiisi', $b, $br, $pid, $interestCollectionId, $descText, $u);
                    $s->execute();
                    $s->close();
                }
            }
        }
        audit($conn, $b, $br, $u, $pid, $no);
        $conn->commit();
        out(true, 'Pawn entry created successfully. Pawn No: ' . $no, [
            'pawn_id' => $pid,
            'pawn_no' => $no,
            'principal_amount' => $principal,
            'document_charge' => $documentCharge,
            'other_charge' => $other,
            'disbursement_amount' => $disbursement,
            'total_net_weight' => $tn,
            'total_estimated_value' => $te,
            'id_proof_image' => $proofImage,
        ]);
    } catch (Throwable $e) {
        $conn->rollback();
        out(false, $e->getMessage(), [], 500);
    }
}
out(false, 'Invalid action.', [], 400);